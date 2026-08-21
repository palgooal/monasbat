<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * H1C-W10 — Additional Inviter Onboarding — AJAX Transport
 * ============================================================================
 * Same transport conventions as includes/additional-inviter-ajax.php (W8/
 * W9), reused directly: pge_event_access_write_request_context() (login +
 * 'pge_event_manage_nonce' + actor identity exclusively from
 * get_current_user_id()) for every OWNER/ADMIN action below, and
 * pge_event_access_strict_positive_int() for every numeric field. This
 * file is loaded after both event-access-ajax.php and
 * additional-inviter-ajax.php (see pgevents-core.php), so both of those
 * shared helpers already exist by the time this file's handlers run.
 *
 * Section 20 of the H1C-W10 brief: exactly ONE action in this entire file
 * (pge_additional_inviter_onboarding_complete) is registered with a
 * wp_ajax_nopriv_ variant. This is a deliberate, narrow, explicitly
 * documented exception to the "no nopriv" rule every other H1C surface
 * follows — NOT a general relaxation of that rule. It is safe specifically
 * because:
 *   1. Authorization for that ONE action is never based on a WP session —
 *      it is based entirely on presenting the raw, high-entropy, one-time,
 *      short-lived onboarding token (Section 15's token contract), which
 *      only the invitee ever received (by email).
 *   2. It performs no read or write that discloses anything beyond what
 *      that specific, already-possessed token authorizes (Section 6: it
 *      never reports whether an email has an account, never accepts an
 *      arbitrary user_id, never lists/searches anything).
 *   3. Every other write path in this file (invite/revoke/list-pending)
 *      still requires a full authenticated Owner/Admin session exactly
 *      like every pre-existing H1C write action.
 *
 * No handler below ever reads an actor_user_id/owner_id/manager_id field
 * from $_POST as an identity claim, mirroring additional-inviter-ajax.php.
 */

if (!function_exists('pge_additional_inviter_onboarding_actor_error')) {
    /**
     * Public error map for the three authenticated (Owner/Admin) actions
     * below. Deliberately a SEPARATE map from
     * pge_additional_inviter_public_error() (W8/W9) rather than extending
     * it — this file introduces new reason codes
     * (delivery_failed/invalid_token/account_conflict) that would be
     * meaningless on the older W8/W9 actions, and the existing function's
     * behavior must not change under this phase's own "no unrelated
     * refactor" instruction.
     */
    function pge_additional_inviter_onboarding_actor_error($error)
    {
        $code = ($error instanceof WP_Error) ? (string) $error->get_error_code() : '';

        $map = [
            'not_authorized' => ['message' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء', 'reason' => 'not_authorized'],
            'invalid_input' => ['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input'],
            'not_found' => ['message' => 'لم يتم العثور على العنصر المطلوب', 'reason' => 'not_found'],
            'invalid_state' => ['message' => 'حالة الدعوة الحالية لا تسمح بهذا الإجراء', 'reason' => 'invalid_state'],
            'quota_group_conflict' => ['message' => 'هذه المجموعة لديها بالفعل داعٍ إضافي فعّال أو دعوة معلّقة', 'reason' => 'quota_group_conflict'],
            'delivery_failed' => ['message' => 'تعذّر إرسال دعوة الانضمام، يرجى المحاولة مرة أخرى', 'reason' => 'delivery_failed'],
        ];

        if (isset($map[$code])) return $map[$code];
        return ['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error'];
    }
}

if (!function_exists('pge_additional_inviter_onboarding_public_error')) {
    /**
     * Public error map for the ONE public/nopriv completion action only.
     * Deliberately narrower than the map above: a nonexistent/expired/
     * already-consumed/already-revoked token all collapse to the exact
     * same generic invalid_token bucket (Section 8) — this map never
     * exposes 'not_found' or 'not_authorized' to an unauthenticated
     * caller, since neither of those reason codes can actually occur on
     * this path (see PGE_Additional_Inviter_Onboarding::
     * load_valid_pending_row()).
     */
    function pge_additional_inviter_onboarding_public_error($error)
    {
        $code = ($error instanceof WP_Error) ? (string) $error->get_error_code() : '';

        $map = [
            'invalid_token' => ['message' => 'رابط الدعوة غير صالح أو منتهي الصلاحية', 'reason' => 'invalid_token'],
            'invalid_input' => ['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input'],
            'invalid_state' => ['message' => 'لم يعد بالإمكان إتمام هذه الدعوة', 'reason' => 'invalid_state'],
            'account_conflict' => ['message' => 'أنت مسجّل الدخول حالياً بحساب مختلف عن هذه الدعوة', 'reason' => 'account_conflict'],
            'quota_group_conflict' => ['message' => 'لم يعد بالإمكان إتمام هذه الدعوة', 'reason' => 'invalid_state'],
        ];

        if (isset($map[$code])) return $map[$code];
        return ['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error'];
    }
}

// ──────────────────────────────────────────────────────────────
// Owner/Admin: create + send an onboarding invitation
// ──────────────────────────────────────────────────────────────

if (!function_exists('pge_additional_inviter_onboarding_invite_handler')) {
    function pge_additional_inviter_onboarding_invite_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $group_id = pge_event_access_strict_positive_int($_POST['group_id'] ?? null);
        $allocated_quota = pge_event_access_strict_positive_int($_POST['allocated_quota'] ?? null);
        // Owner/Admin-supplied contact of the INVITEE — an email address,
        // never a user_id (Section 6: no free user search, no user_id
        // entry field exists anywhere in this file).
        $invitee_email = isset($_POST['invitee_email']) ? sanitize_email(wp_unslash($_POST['invitee_email'])) : '';
        $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';

        if ($event_id === null || $group_id === null || $allocated_quota === null || $invitee_email === '') {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Additional_Inviter_Onboarding')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(
            $event_id, $actor_user_id, $group_id, $allocated_quota, $invitee_email, $display_name
        );

        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_additional_inviter_onboarding_actor_error($result));
        }
        if (!is_array($result) || !array_key_exists('invitation_id', $result)) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        // Generic success shape only (Section 6) — never echoes back
        // whether the email matched an existing account.
        wp_send_json_success(['invitation_id' => $result['invitation_id']]);
    }
}
add_action('wp_ajax_pge_additional_inviter_onboarding_invite', 'pge_additional_inviter_onboarding_invite_handler');

// ──────────────────────────────────────────────────────────────
// Owner/Admin: revoke a pending onboarding invitation
// ──────────────────────────────────────────────────────────────

if (!function_exists('pge_additional_inviter_onboarding_revoke_handler')) {
    function pge_additional_inviter_onboarding_revoke_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $invitation_id = pge_event_access_strict_positive_int($_POST['invitation_id'] ?? null);

        if ($event_id === null || $invitation_id === null) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Additional_Inviter_Onboarding')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Additional_Inviter_Onboarding::revoke_invitation_for_actor($event_id, $actor_user_id, $invitation_id);

        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_additional_inviter_onboarding_actor_error($result));
        }
        if (!is_array($result) || !array_key_exists('already_revoked', $result)) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        wp_send_json_success(['already_revoked' => $result['already_revoked']]);
    }
}
add_action('wp_ajax_pge_additional_inviter_onboarding_revoke', 'pge_additional_inviter_onboarding_revoke_handler');

// ──────────────────────────────────────────────────────────────
// Owner/Admin: list pending onboarding invitations (Section 21 —
// integrates alongside H1C-W9's existing pending-inviter-team read).
// ──────────────────────────────────────────────────────────────

if (!function_exists('pge_additional_inviter_onboarding_list_pending_handler')) {
    function pge_additional_inviter_onboarding_list_pending_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        if ($event_id === null) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        // Reuses the exact same page/per_page parsing convention already
        // defined by additional-inviter-ajax.php (W9), loaded earlier —
        // see pgevents-core.php's require order.
        $pagination = pge_additional_inviter_read_pagination();

        if (!class_exists('PGE_Additional_Inviter_Onboarding')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Additional_Inviter_Onboarding::list_pending_invitations_for_owner(
            $event_id, $actor_user_id, $pagination['page'], $pagination['per_page']
        );

        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_additional_inviter_onboarding_actor_error($result));
        }
        $required = ['items', 'page', 'per_page', 'total', 'total_pages'];
        foreach ($required as $key) {
            if (!is_array($result) || !array_key_exists($key, $result)) {
                wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
            }
        }

        wp_send_json_success([
            'items' => $result['items'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'total' => $result['total'],
            'total_pages' => $result['total_pages'],
        ]);
    }
}
add_action('wp_ajax_pge_additional_inviter_onboarding_list_pending', 'pge_additional_inviter_onboarding_list_pending_handler');

// ──────────────────────────────────────────────────────────────
// PUBLIC, token-authenticated: complete onboarding. The ONE intentional
// nopriv surface in this file (see top docblock). POST-only by
// construction — there is no wp_ajax(_nopriv)_ action a browser can reach
// via a plain GET, so this action can never be triggered by an email
// client's link-preview/security scanner simply fetching the join URL
// (the join URL itself only ever renders the GET-safe preview page —
// see includes/routing.php — never this action).
// ──────────────────────────────────────────────────────────────

if (!function_exists('pge_additional_inviter_onboarding_complete_handler')) {
    function pge_additional_inviter_onboarding_complete_handler()
    {
        // Ordinary CSRF hygiene only (same discipline as the Supervisor
        // Login RFC POST-confirm's 'pge_supervisor_login_confirm' nonce,
        // which is likewise verified for a possibly-anonymous caller) —
        // never treated as an authorization check by itself, and never
        // checked against any actor identity. The raw token IS the
        // authority; see PGE_Additional_Inviter_Onboarding::
        // load_valid_pending_row().
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pge_additional_inviter_onboarding_complete')) {
            wp_send_json_error(['message' => 'رمز الأمان غير صالح', 'reason' => 'invalid_nonce']);
        }

        $raw_token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : '';

        if ($raw_token === '' || !in_array($mode, ['existing', 'new'], true)) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Additional_Inviter_Onboarding')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        if ($mode === 'existing') {
            $result = PGE_Additional_Inviter_Onboarding::complete_with_existing_account($raw_token);
        } else {
            // Never wp_unslash()+sanitize_text_field() the password itself
            // — that would mangle legitimate characters. Only its length
            // is validated (by the orchestrator), never its content.
            $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
            $display_name = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
            $result = PGE_Additional_Inviter_Onboarding::complete_with_new_account($raw_token, $password, $display_name);
        }

        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_additional_inviter_onboarding_public_error($result));
        }
        if (!is_array($result) || !array_key_exists('membership_id', $result)) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        // Deliberately minimal success shape — no membership_id, group_id,
        // quota, or any other internal identifier is ever echoed back to
        // this anonymous-origin request (Section 6's discipline extended
        // to the completion response too, not just the invite response).
        wp_send_json_success(['ok' => true]);
    }
}
add_action('wp_ajax_pge_additional_inviter_onboarding_complete', 'pge_additional_inviter_onboarding_complete_handler');
add_action('wp_ajax_nopriv_pge_additional_inviter_onboarding_complete', 'pge_additional_inviter_onboarding_complete_handler');
