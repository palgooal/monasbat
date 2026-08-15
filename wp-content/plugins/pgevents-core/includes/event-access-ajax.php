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
