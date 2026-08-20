<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * H1C-W8 — Additional Inviter Quota & Scoped Guest Creation — AJAX Transport
 * ============================================================================
 * Same transport conventions as includes/event-access-ajax.php, reused
 * directly rather than reimplemented: is_user_logged_in() gate,
 * wp_verify_nonce() against the SAME existing 'pge_event_manage_nonce'
 * action (no new nonce action), actor identity read exclusively from
 * get_current_user_id() (pge_event_access_write_request_context(), reused
 * unmodified from event-access-ajax.php — this file is loaded after it),
 * and pge_event_access_strict_positive_int() for every numeric field. No
 * wp_ajax_nopriv_ variant exists for any action in this file. No handler
 * below ever reads an actor_user_id/owner_id/manager_id/user_id field from
 * $_POST as an identity claim — 'target_user_id' exists only as the TARGET
 * of an Owner/Admin-initiated creation, never as the acting identity.
 *
 * Every handler below is a thin translation layer only: parse/validate
 * transport input -> call exactly one PGE_Additional_Inviter method ->
 * translate its result to a small public JSON shape. No authorization
 * decision, no quota arithmetic, and no guest-creation logic exists in
 * this file — all of it lives in the PGE_Additional_Inviter orchestrator
 * (class-pge-additional-inviter.php), which is itself the only caller of
 * the H1C authorization/repository core and of the invitation service and
 * repository — this AJAX layer never references those lower layers
 * directly.
 */

if (!function_exists('pge_additional_inviter_read_nullable_quota')) {
    /**
     * Reads an optional quota field. Returns null for "explicitly no
     * quota" (field absent or empty string — both valid, meaningful
     * inputs for set_membership_quota()'s expected_quota/new_quota), a
     * strict positive int for a real quota value, or false for malformed
     * input the caller must reject as invalid_input. false is never
     * confused with null: PHP's === is used everywhere this return value
     * is consumed.
     *
     * @return int|null|false
     */
    function pge_additional_inviter_read_nullable_quota($field)
    {
        if (!array_key_exists($field, $_POST)) return null;
        $raw = wp_unslash($_POST[$field]);
        if (is_string($raw) && trim($raw) === '') return null;
        $parsed = pge_event_access_strict_positive_int($raw);
        return $parsed === null ? false : $parsed;
    }
}

if (!function_exists('pge_additional_inviter_public_error')) {
    function pge_additional_inviter_public_error($error)
    {
        $code = ($error instanceof WP_Error) ? (string) $error->get_error_code() : '';

        $map = [
            'not_authorized' => ['message' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء', 'reason' => 'not_authorized'],
            'invalid_input' => ['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input'],
            'not_found' => ['message' => 'لم يتم العثور على العنصر المطلوب', 'reason' => 'not_found'],
            'invalid_state' => ['message' => 'حالة العنصر الحالية لا تسمح بهذا الإجراء', 'reason' => 'invalid_state'],
            'duplicate' => ['message' => 'يوجد بالفعل عضوية لهذا المستخدم في هذه المناسبة', 'reason' => 'duplicate'],
            'quota_group_conflict' => ['message' => 'هذه المجموعة لديها بالفعل داعٍ إضافي فعّال', 'reason' => 'quota_group_conflict'],
            'concurrent_update' => ['message' => 'تم تغيير الحالة أثناء تنفيذ العملية، يرجى إعادة المحاولة', 'reason' => 'concurrent_update'],
            'write_unavailable' => ['message' => 'الخدمة غير متاحة حالياً، يرجى المحاولة لاحقاً', 'reason' => 'write_unavailable'],
            'misconfigured' => ['message' => 'إعدادات الداعي الإضافي غير صالحة حالياً', 'reason' => 'misconfigured'],
            'duplicate_guest' => ['message' => 'هذا الرقم مضاف مسبقاً لهذه المناسبة', 'reason' => 'duplicate_guest'],
            'quota_exceeded' => ['message' => 'تم بلوغ الحد الأقصى لعدد الضيوف المتاح لك', 'reason' => 'quota_exceeded'],
            'package_quota_exceeded' => ['message' => 'تم بلوغ الحد الأقصى لعدد ضيوف هذه المناسبة', 'reason' => 'package_quota_exceeded'],
        ];

        if (isset($map[$code])) return $map[$code];
        return ['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error'];
    }
}

if (!function_exists('pge_additional_inviter_send_membership_result')) {
    function pge_additional_inviter_send_membership_result($result)
    {
        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_additional_inviter_public_error($result));
        }
        $required = ['changed', 'membership_id', 'user_id', 'role', 'status', 'allocated_quota'];
        foreach ($required as $key) {
            if (!is_array($result) || !array_key_exists($key, $result)) {
                wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
            }
        }
        $payload = [
            'changed' => $result['changed'],
            'membership_id' => $result['membership_id'],
            'user_id' => $result['user_id'],
            'role' => $result['role'],
            'status' => $result['status'],
            'allocated_quota' => $result['allocated_quota'],
        ];
        if (array_key_exists('group_id', $result)) {
            $payload['group_id'] = $result['group_id'];
        }
        wp_send_json_success($payload);
    }
}

if (!function_exists('pge_additional_inviter_send_quota_status')) {
    function pge_additional_inviter_send_quota_status($result)
    {
        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_additional_inviter_public_error($result));
        }
        $required = ['allocated', 'occupied', 'available', 'attending', 'pending', 'declined', 'cancelled', 'group_id', 'group_name', 'membership_id'];
        foreach ($required as $key) {
            if (!is_array($result) || !array_key_exists($key, $result)) {
                wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
            }
        }
        wp_send_json_success([
            'allocated' => $result['allocated'],
            'occupied' => $result['occupied'],
            'available' => $result['available'],
            'attending' => $result['attending'],
            'pending' => $result['pending'],
            'declined' => $result['declined'],
            'cancelled' => $result['cancelled'],
            'group_id' => $result['group_id'],
            'group_name' => $result['group_name'],
        ]);
    }
}

// ──────────────────────────────────────────────────────────────
// Owner/Admin: create an Additional Inviter
// ──────────────────────────────────────────────────────────────

if (!function_exists('pge_additional_inviter_create_handler')) {
    function pge_additional_inviter_create_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        // Owner/Admin-supplied TARGET of the new membership — never actor
        // identity. See this file's own top docblock.
        $target_user_id = pge_event_access_strict_positive_int($_POST['target_user_id'] ?? null);
        $group_id = pge_event_access_strict_positive_int($_POST['group_id'] ?? null);
        $allocated_quota = pge_event_access_strict_positive_int($_POST['allocated_quota'] ?? null);

        if ($event_id === null || $target_user_id === null || $group_id === null || $allocated_quota === null) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Additional_Inviter')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Additional_Inviter::create_additional_inviter_for_actor(
            $event_id, $actor_user_id, $target_user_id, $group_id, $allocated_quota
        );

        pge_additional_inviter_send_membership_result($result);
    }
}
add_action('wp_ajax_pge_additional_inviter_create', 'pge_additional_inviter_create_handler');

// ──────────────────────────────────────────────────────────────
// Owner/Admin: change an existing Additional Inviter's quota
// ──────────────────────────────────────────────────────────────

if (!function_exists('pge_additional_inviter_set_quota_handler')) {
    function pge_additional_inviter_set_quota_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $membership_id = pge_event_access_strict_positive_int($_POST['membership_id'] ?? null);
        $expected_quota = pge_additional_inviter_read_nullable_quota('expected_quota');
        $new_quota = pge_additional_inviter_read_nullable_quota('new_quota');

        if ($event_id === null || $membership_id === null || $expected_quota === false || $new_quota === false) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Additional_Inviter')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Additional_Inviter::set_additional_inviter_quota_for_actor(
            $event_id, $actor_user_id, $membership_id, $expected_quota, $new_quota
        );

        pge_additional_inviter_send_membership_result($result);
    }
}
add_action('wp_ajax_pge_additional_inviter_set_quota', 'pge_additional_inviter_set_quota_handler');

// ──────────────────────────────────────────────────────────────
// Owner/Admin: read a specific Additional Inviter's quota status
// ──────────────────────────────────────────────────────────────

if (!function_exists('pge_additional_inviter_get_quota_handler')) {
    function pge_additional_inviter_get_quota_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $membership_id = pge_event_access_strict_positive_int($_POST['membership_id'] ?? null);

        if ($event_id === null || $membership_id === null) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Additional_Inviter')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Additional_Inviter::get_quota_status_for_owner($event_id, $actor_user_id, $membership_id);

        pge_additional_inviter_send_quota_status($result);
    }
}
add_action('wp_ajax_pge_additional_inviter_get_quota', 'pge_additional_inviter_get_quota_handler');

// ──────────────────────────────────────────────────────────────
// Manager self-read: my own Additional Inviter quota status. No
// membership_id parameter exists on this action at all — arbitrary
// membership_id enumeration is structurally impossible here, not just
// authorization-denied (Section 18).
// ──────────────────────────────────────────────────────────────

if (!function_exists('pge_additional_inviter_get_my_quota_handler')) {
    function pge_additional_inviter_get_my_quota_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);

        if ($event_id === null) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Additional_Inviter')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Additional_Inviter::get_quota_status_for_self($event_id, $actor_user_id);

        pge_additional_inviter_send_quota_status($result);
    }
}
add_action('wp_ajax_pge_additional_inviter_get_my_quota', 'pge_additional_inviter_get_my_quota_handler');

// ──────────────────────────────────────────────────────────────
// Manager self-service: create a guest scoped to my own single granted
// group. No group_id parameter exists on this action at all — the client
// cannot choose another group even by sending one, since it is never read
// (Section 12: "The client MUST NOT send group_id... Server resolves").
// ──────────────────────────────────────────────────────────────

if (!function_exists('pge_additional_inviter_create_guest_handler')) {
    function pge_additional_inviter_create_guest_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        if ($event_id === null || $phone === '' || $name === '') {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Additional_Inviter')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Additional_Inviter::create_scoped_guest_for_actor($event_id, $actor_user_id, $phone, $name, $note);

        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_additional_inviter_public_error($result));
        }
        if (!is_array($result) || !array_key_exists('phone', $result) || !array_key_exists('group_id', $result)) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        wp_send_json_success([
            'phone' => $result['phone'],
            'group_id' => $result['group_id'],
        ]);
    }
}
add_action('wp_ajax_pge_additional_inviter_create_guest', 'pge_additional_inviter_create_guest_handler');
