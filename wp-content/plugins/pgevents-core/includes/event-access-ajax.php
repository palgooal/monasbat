<?php
if (!defined('ABSPATH')) exit;

/**
 * H1C-W1 — Collaborator Read-Only Application Wiring: the single read-only
 * authenticated AJAX action for this phase.
 *
 * Registered wp_ajax_ ONLY — never wp_ajax_nopriv_ (Section 17 of the
 * H1C-W1 brief; an unauthenticated request must never reach this handler at
 * all, WordPress itself refuses it before this file's code runs).
 *
 * This handler does exactly four things, in this order, and nothing else
 * (Section 18):
 *   1. validates transport (login state, nonce, basic event_id shape);
 *   2. obtains actor_user_id from the real WordPress session
 *      (get_current_user_id()) — a client-supplied actor_user_id is never
 *      read from $_POST/$_GET/anywhere in the request, so a caller cannot
 *      claim to be a different actor (Section 17, a security-relevant
 *      point per the brief);
 *   3. calls PGE_Event_Access_Application_Service — every authorization
 *      decision (owner/admin/manager/viewer/stranger/revoked, scope,
 *      projection) happens there and in the H1B Authorization Core it
 *      delegates to, never in this file;
 *   4. maps the result to a small, fixed, enumeration-resistant public JSON
 *      shape (Section 19) — no membership/revoked/scope/SQL/table-name/
 *      guest-phone/internal-id detail is ever exposed to the client.
 *
 * Nonce action name ('pge_event_manage_nonce') reuses the existing, single
 * event-management nonce convention already shared by event-guests.php/
 * invitation-management-ajax.php (helpers.php::pge_mgmt_validate_request())
 * — no new nonce action invented. This handler intentionally does NOT call
 * pge_mgmt_validate_request()/pge_event_guests_user_can_manage() for the
 * authorization step, because that helper only recognizes Owner/Admin
 * (post_author/administrator/edit_post) — it would incorrectly reject a
 * legitimate H1B Manager/Viewer collaborator. Only the transport-level
 * pieces (login + nonce) are shared; the authorization decision itself
 * comes exclusively from the Application Service / Authorization Core
 * below.
 *
 * Named function (not a closure) — same convention as checkin-ajax.php/
 * supervisor-management-ajax.php, deliberately, for direct-by-name testing.
 */

if (!function_exists('pge_event_access_public_error')) {
    /**
     * Collapses every possible Application Service / Authorization Core /
     * Repository WP_Error into a small, fixed, enumeration-resistant public
     * shape. Every denial reason (stranger, revoked membership, forged/
     * invalid context, out-of-scope) collapses to the same public
     * 'not_authorized' bucket; every internal storage/corruption failure
     * (database_error, schema_not_ready, guest_data_error) collapses to
     * 'server_error'. Neither bucket's message ever contains SQL, a table
     * name, a membership id, or a guest phone/name — both are static,
     * translatable strings (Section 19).
     */
    function pge_event_access_public_error($error)
    {
        $code = ($error instanceof WP_Error) ? (string) $error->get_error_code() : '';

        if (in_array($code, ['invalid_input', 'not_found'], true)) {
            return ['message' => 'مناسبة غير صالحة', 'reason' => 'invalid_event'];
        }

        if ($code === PGE_Event_Access_Application_Service::REASON_NOT_AUTHORIZED) {
            return ['message' => 'ليس لديك صلاحية لعرض بيانات هذه المناسبة', 'reason' => 'not_authorized'];
        }

        // database_error / schema_not_ready / guest_data_error / anything
        // else unrecognized — a single generic bucket, no internal detail.
        return ['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error'];
    }
}

if (!function_exists('pge_event_access_list_guests_handler')) {
    function pge_event_access_list_guests_handler()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'غير مصرح', 'reason' => 'not_logged_in']);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pge_event_manage_nonce')) {
            wp_send_json_error(['message' => 'رمز الأمان غير صالح', 'reason' => 'invalid_nonce']);
        }

        $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
        if ($event_id <= 0) {
            wp_send_json_error(['message' => 'مناسبة غير صالحة', 'reason' => 'invalid_event']);
        }

        $page = isset($_POST['page']) ? (int) $_POST['page'] : 1;
        if ($page < 1) $page = 1;

        $per_page = isset($_POST['per_page']) && $_POST['per_page'] !== '' ? (int) $_POST['per_page'] : null;

        // Actor identity comes exclusively from the authenticated WP
        // session. There is no $_POST['actor_user_id'] read anywhere in
        // this file, on purpose (Section 17).
        $actor_user_id = (int) get_current_user_id();

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::list_accessible_guests_for_actor(
            $event_id,
            $actor_user_id,
            $page,
            $per_page
        );

        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_event_access_public_error($result));
        }
        if (!is_array($result) || !isset($result['items'])) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
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
add_action('wp_ajax_pge_event_access_list_guests', 'pge_event_access_list_guests_handler');

/**
 * H1C-W2 — Collaborator Scoped Guest-Assignment Write Wiring: three
 * authenticated AJAX actions (assign/move/unassign), same conventions as
 * the H1C-W1 read handler above.
 *
 * Registered wp_ajax_ ONLY — never wp_ajax_nopriv_, same as above (Section
 * 17 of the H1C-W2 brief; a dedicated source-level W2 test asserts no
 * wp_ajax_nopriv_pge_event_access_{assign,move,unassign}_guest hook exists
 * anywhere in this file).
 *
 * Actor identity (Section 18): actor_user_id is read exclusively from
 * get_current_user_id() — exactly like the read handler above. There is no
 * $_POST['actor_user_id']/['user_id']/['manager_id']/['owner_id'] read
 * anywhere in this file; if a client sends any such field it is silently
 * ignored (never merged into the request), which a dedicated "actor spoof"
 * test in the W2 suite verifies end-to-end (spoofed field present in
 * $_POST, but the mutation runs — or is denied — strictly under the real
 * session's permissions, and PGE_Event_Access_Repository is never invoked
 * at all when the real session's permissions deny it).
 *
 * expected_group_id (Section 22): required on both move and unassign,
 * never optional, never inferred — this file only ever forwards the exact
 * value the client sent to the Application Service, which forwards it
 * unchanged to the Repository as the optimistic-concurrency precondition.
 */

if (!function_exists('pge_event_access_strict_positive_int')) {
    /**
     * Strict positive-integer parsing for AJAX POST fields that identify a
     * row (event_id/group_id) in a WRITE request. Deliberately stricter
     * than the casual (int) cast used by the pre-existing H1C-W1 read
     * handler above: a mutation endpoint must reject a float, a scientific
     * notation string, a negative number, zero, or an array outright rather
     * than silently truncating/coercing it (Section 21 of the H1C-W2
     * brief). Real WordPress AJAX values are always string|array
     * ($_POST/$_GET never contain a native int or float), but this
     * function also rejects a raw non-string scalar defensively (a test
     * harness or a future caller could otherwise inject one directly).
     *
     * @return int|null null on anything that is not a strict positive
     *   integer.
     */
    function pge_event_access_strict_positive_int($value)
    {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) return null;
        if (strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }
        return (int) $value;
    }
}

if (!function_exists('pge_event_access_public_write_error')) {
    /**
     * Collapses an Application Service write-use-case WP_Error into a
     * small, fixed, enumeration-resistant public shape — the write-path
     * counterpart of pge_event_access_public_error() above. Kept as a
     * separate function rather than extended into the existing one: the
     * read handler's 'invalid_event' message for invalid_input/not_found
     * would be misleading here (a write's not_found can also mean an
     * invalid/archived/cross-event group, or an unresolvable guest — see
     * PGE_Event_Access_Application_Service::map_repository_write_result()'s
     * own docblock for the exact, reviewed mapping), and the existing
     * function's behavior must not change under this phase's own explicit
     * "no unrelated refactor" instruction — pge_event_access_public_error()
     * backs the already-tested H1C-W1 read handler and is left untouched.
     *
     * Every denial reason (stranger, revoked membership, forged context,
     * out-of-scope, wrong-operation-for-role such as a Manager attempting
     * assign) collapses to the same public 'not_authorized' bucket. No
     * message here ever contains SQL, a table name, a membership id, or a
     * guest phone/name.
     */
    function pge_event_access_public_write_error($error)
    {
        $code = ($error instanceof WP_Error) ? (string) $error->get_error_code() : '';

        if ($code === PGE_Event_Access_Application_Service::REASON_NOT_AUTHORIZED) {
            return ['message' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء', 'reason' => 'not_authorized'];
        }
        if ($code === PGE_Event_Access_Application_Service::REASON_INVALID_INPUT || $code === 'invalid_input') {
            return ['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input'];
        }
        if ($code === 'not_found') {
            return ['message' => 'لم يتم العثور على العنصر المطلوب', 'reason' => 'not_found'];
        }
        if ($code === 'ambiguous_guest') {
            return ['message' => 'تعذّر تحديد هوية الضيف بدقة', 'reason' => 'ambiguous_guest'];
        }
        if ($code === 'invalid_group') {
            return ['message' => 'المجموعة المحددة غير صالحة', 'reason' => 'invalid_group'];
        }
        if ($code === 'concurrent_update') {
            return ['message' => 'تم تغيير حالة الضيف أثناء تنفيذ العملية، يرجى إعادة المحاولة', 'reason' => 'concurrent_update'];
        }
        if ($code === 'write_unavailable') {
            return ['message' => 'الخدمة غير متاحة حالياً، يرجى المحاولة لاحقاً', 'reason' => 'write_unavailable'];
        }

        // database_error / guest_data_error / anything else unrecognized —
        // a single generic bucket, no internal detail.
        return ['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error'];
    }
}

if (!function_exists('pge_event_access_write_request_context')) {
    /**
     * Shared transport validation for all three write handlers below:
     * login state, nonce, and the actor identity — exactly the first two
     * steps of pge_event_access_list_guests_handler() above, factored out
     * once rather than duplicated three times. Sends the JSON error and
     * (via wp_send_json_error()) halts execution itself on failure, exactly
     * like the read handler; on success returns the real actor_user_id.
     */
    function pge_event_access_write_request_context()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'غير مصرح', 'reason' => 'not_logged_in']);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pge_event_manage_nonce')) {
            wp_send_json_error(['message' => 'رمز الأمان غير صالح', 'reason' => 'invalid_nonce']);
        }

        // Actor identity comes exclusively from the authenticated WP
        // session. There is no $_POST['actor_user_id']/['user_id']/
        // ['manager_id']/['owner_id'] read anywhere in this file, on
        // purpose (Section 18) — any such field the client sends is simply
        // never looked at.
        return (int) get_current_user_id();
    }
}

if (!function_exists('pge_event_access_read_guest_phone')) {
    function pge_event_access_read_guest_phone()
    {
        $raw = isset($_POST['guest_phone']) ? wp_unslash($_POST['guest_phone']) : '';
        return is_string($raw) ? sanitize_text_field($raw) : '';
    }
}

if (!function_exists('pge_event_access_send_write_result')) {
    function pge_event_access_send_write_result($result)
    {
        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_event_access_public_write_error($result));
        }
        if (!is_array($result) || !array_key_exists('changed', $result) || !array_key_exists('assignment_id', $result)
            || !array_key_exists('group_id', $result) || !array_key_exists('has_assignment', $result)) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        wp_send_json_success([
            'changed' => $result['changed'],
            'assignment_id' => $result['assignment_id'],
            'group_id' => $result['group_id'],
            'has_assignment' => $result['has_assignment'],
        ]);
    }
}

if (!function_exists('pge_event_access_assign_guest_handler')) {
    function pge_event_access_assign_guest_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $group_id = pge_event_access_strict_positive_int($_POST['group_id'] ?? null);
        $guest_phone = pge_event_access_read_guest_phone();

        if ($event_id === null || $group_id === null || $guest_phone === '') {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::assign_guest_to_group_for_actor(
            $event_id,
            $actor_user_id,
            $guest_phone,
            $group_id
        );

        pge_event_access_send_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_assign_guest', 'pge_event_access_assign_guest_handler');

if (!function_exists('pge_event_access_move_guest_handler')) {
    function pge_event_access_move_guest_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $expected_group_id = pge_event_access_strict_positive_int($_POST['expected_group_id'] ?? null);
        $new_group_id = pge_event_access_strict_positive_int($_POST['new_group_id'] ?? null);
        $guest_phone = pge_event_access_read_guest_phone();

        if ($event_id === null || $expected_group_id === null || $new_group_id === null || $guest_phone === '') {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::move_guest_to_group_for_actor(
            $event_id,
            $actor_user_id,
            $guest_phone,
            $expected_group_id,
            $new_group_id
        );

        pge_event_access_send_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_move_guest', 'pge_event_access_move_guest_handler');

if (!function_exists('pge_event_access_unassign_guest_handler')) {
    function pge_event_access_unassign_guest_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $expected_group_id = pge_event_access_strict_positive_int($_POST['expected_group_id'] ?? null);
        $guest_phone = pge_event_access_read_guest_phone();

        if ($event_id === null || $expected_group_id === null || $guest_phone === '') {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::unassign_guest_from_group_for_actor(
            $event_id,
            $actor_user_id,
            $guest_phone,
            $expected_group_id
        );

        pge_event_access_send_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_unassign_guest', 'pge_event_access_unassign_guest_handler');

/**
 * H1C-W3 — Owner/Admin Group-Access Lifecycle Write Wiring: two
 * authenticated AJAX actions (grant/revoke), same conventions as the H1C-W2
 * write handlers above.
 *
 * Registered wp_ajax_ ONLY — never wp_ajax_nopriv_, same as every handler
 * in this file (a dedicated source-level W3 test asserts no
 * wp_ajax_nopriv_pge_event_access_{grant,revoke}_group_access hook exists
 * anywhere in this file).
 *
 * Actor identity: actor_user_id is read exclusively via
 * pge_event_access_write_request_context() — the same shared helper the
 * H1C-W2 write handlers use, reading get_current_user_id() only. There is
 * no $_POST['actor_user_id']/['user_id']/['owner_id'] read anywhere in this
 * file; a client-sent field of that name is silently ignored, which a
 * dedicated "actor spoof" test in the W3 suite verifies end-to-end.
 *
 * This is an Owner/Admin-only operation (the Authorization Core's
 * can_manage_group_access() capability check takes no group-scoping
 * parameter at all — see the Application Service docblock for the full
 * contract), so unlike the H1C-W2 handlers there is no group-scoped actor to
 * reason about here: a Manager/Viewer/Revoked/Stranger caller is rejected by
 * role before any existence-sensitive Repository lookup of membership_id or
 * group_id. membership_id/group_id are still parsed as strict positive
 * integers here in this handler before the Application Service call (same
 * as event_id, below) — that parsing reveals nothing about whether either id
 * actually exists, so it carries no enumeration risk. This AJAX layer never
 * references the Authorization class directly by name — it calls only
 * PGE_Event_Access_Application_Service, exactly like every other handler in
 * this file (Section 9's fixed architecture: AJAX -> Application Service ->
 * Authorization -> Repository).
 */

if (!function_exists('pge_event_access_public_group_access_write_error')) {
    /**
     * Collapses an Application Service group-access write-use-case
     * WP_Error into a small, fixed, enumeration-resistant public shape —
     * the group-access counterpart of pge_event_access_public_write_error()
     * above. Kept separate on purpose: this domain's reachable codes differ
     * (invalid_state instead of ambiguous_guest/invalid_group — see
     * PGE_Event_Access_Application_Service::map_group_access_write_result()'s
     * own docblock for the exact, reviewed mapping), and the existing
     * function backs the already-tested H1C-W2 handlers and must not change
     * under this phase's own "no unrelated refactor" instruction.
     *
     * Every denial reason (stranger, revoked membership, forged context,
     * or simply not Owner/Admin) collapses to the same public
     * 'not_authorized' bucket. No message here ever contains SQL, a table
     * name, a membership id, or a guest phone/name.
     */
    function pge_event_access_public_group_access_write_error($error)
    {
        $code = ($error instanceof WP_Error) ? (string) $error->get_error_code() : '';

        if ($code === PGE_Event_Access_Application_Service::REASON_NOT_AUTHORIZED) {
            return ['message' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء', 'reason' => 'not_authorized'];
        }
        if ($code === PGE_Event_Access_Application_Service::REASON_INVALID_INPUT || $code === 'invalid_input') {
            return ['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input'];
        }
        if ($code === 'not_found') {
            return ['message' => 'لم يتم العثور على العنصر المطلوب', 'reason' => 'not_found'];
        }
        if ($code === 'invalid_state') {
            return ['message' => 'حالة العنصر الحالية لا تسمح بهذا الإجراء', 'reason' => 'invalid_state'];
        }
        if ($code === 'concurrent_update') {
            return ['message' => 'تم تغيير الحالة أثناء تنفيذ العملية، يرجى إعادة المحاولة', 'reason' => 'concurrent_update'];
        }
        if ($code === 'write_unavailable') {
            return ['message' => 'الخدمة غير متاحة حالياً، يرجى المحاولة لاحقاً', 'reason' => 'write_unavailable'];
        }

        // database_error / guest_data_error / anything else unrecognized —
        // a single generic bucket, no internal detail.
        return ['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error'];
    }
}

if (!function_exists('pge_event_access_send_group_access_write_result')) {
    function pge_event_access_send_group_access_write_result($result)
    {
        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_event_access_public_group_access_write_error($result));
        }
        if (!is_array($result) || !array_key_exists('changed', $result) || !array_key_exists('membership_id', $result)
            || !array_key_exists('group_id', $result) || !array_key_exists('has_access', $result)) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        wp_send_json_success([
            'changed' => $result['changed'],
            'membership_id' => $result['membership_id'],
            'group_id' => $result['group_id'],
            'has_access' => $result['has_access'],
        ]);
    }
}

if (!function_exists('pge_event_access_grant_group_access_handler')) {
    function pge_event_access_grant_group_access_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $membership_id = pge_event_access_strict_positive_int($_POST['membership_id'] ?? null);
        $group_id = pge_event_access_strict_positive_int($_POST['group_id'] ?? null);

        if ($event_id === null || $membership_id === null || $group_id === null) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::grant_group_access_for_actor(
            $event_id,
            $actor_user_id,
            $membership_id,
            $group_id
        );

        pge_event_access_send_group_access_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_grant_group_access', 'pge_event_access_grant_group_access_handler');

if (!function_exists('pge_event_access_revoke_group_access_handler')) {
    function pge_event_access_revoke_group_access_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $membership_id = pge_event_access_strict_positive_int($_POST['membership_id'] ?? null);
        $group_id = pge_event_access_strict_positive_int($_POST['group_id'] ?? null);

        if ($event_id === null || $membership_id === null || $group_id === null) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::revoke_group_access_for_actor(
            $event_id,
            $actor_user_id,
            $membership_id,
            $group_id
        );

        pge_event_access_send_group_access_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_revoke_group_access', 'pge_event_access_revoke_group_access_handler');
