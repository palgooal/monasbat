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

        // Phase H1C-EC1 — Event-Context Enumeration Privacy Hardening: the
        // 'not_found' branch below is now DEAD CODE for this read use case
        // on purpose, kept only for defense in depth. Before this fix,
        // PGE_Event_Access_Application_Service::list_accessible_guests_for_actor()
        // could return a raw 'not_found' straight from context resolution
        // for a NONEXISTENT event_id — landing here in this same
        // 'invalid_event' bucket, distinct from the 'not_authorized' bucket
        // below that an existing-but-denied event_id gets. That made the
        // two publicly distinguishable: an Event-ID existence oracle. The
        // fix lives entirely in the Application Service's shared
        // resolve_event_actor_context() helper, which now collapses every
        // context-resolution failure to not_authorized before this
        // function ever sees a result — a nonexistent event_id therefore
        // reaches this function as 'not_authorized', not 'not_found', and
        // takes the same branch below as any other denial. This function
        // required no behavioral change.
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
 * H1C-W6 — Audit Trail Read Wiring: one authenticated, Owner/Admin-only
 * read AJAX action, same transport conventions as the H1C-W1 read handler
 * above (login check, 'pge_event_manage_nonce' nonce, event_id from
 * $_POST, actor identity exclusively from get_current_user_id() — no
 * $_POST['actor_user_id']/'user_id'/'owner_id'/'manager_id' is ever read
 * anywhere in this handler to determine WHO is asking).
 *
 * Registered wp_ajax_ ONLY — never wp_ajax_nopriv_ (same convention as
 * every other action in this file; an unauthenticated request never
 * reaches this handler at all).
 *
 * H1C-W6 Corrective Pass — strict transport parsing: event_id and every
 * numeric filter (filter_actor_user_id / filter_entity_id) are parsed with
 * the SAME pge_event_access_strict_positive_int() helper the W2-W5 write
 * handlers already use below, not a casual (int) cast — "12abc" must never
 * silently become 12. page/per_page follow the identical strict-or-fail
 * rule (see the two variables' own comments below): a MISSING value still
 * gets the existing default (page=1, per_page=null i.e. Repository's own
 * default), but an EXPLICITLY supplied value that fails strict parsing is
 * never silently coerced into a default or any other value — it is instead
 * passed through as a value guaranteed to fail the Application/Repository's
 * own authoritative validation (invalid_input for page, invalid_filter for
 * a malformed numeric filter), so the reachable public 'reason' always
 * reflects a real Application/Repository decision, never a transport-layer
 * guess.
 *
 * Optional audit filters are read from distinctly-prefixed $_POST keys
 * (filter_action / filter_entity_type / filter_actor_user_id /
 * filter_entity_id) so a filter on a logged actor's id can never be
 * confused with, or substituted for, the real requesting actor's own
 * identity above. Only keys actually present and non-empty in $_POST are
 * added to the $filters array passed through to the Application Service —
 * every accepted key name and its own validation is entirely
 * PGE_Event_Access_Repository::list_audit()'s existing contract; no filter
 * rule is invented here. filter_action/filter_entity_type are passed
 * through wp_unslash() ONLY — never sanitize_text_field()/trim()/
 * strtolower()/sanitize_key() — because any of those would repair a
 * malformed-but-valid-looking client value (e.g. " group_created " with
 * stray whitespace) into a value Repository's own strict exact-match
 * AUDIT_ACTIONS/AUDIT_ENTITY_TYPES check would then accept, silently
 * substituting a transport-layer guess for Repository's authoritative
 * decision. Repository::list_audit() alone decides whether the value is a
 * real AUDIT_ACTIONS/AUDIT_ENTITY_TYPES member.
 *
 * This handler calls PGE_Event_Access_Application_Service only — never the
 * Repository or the Authorization Core classes directly. Errors are mapped
 * with the dedicated pge_event_access_public_audit_error() below — NOT the
 * older pge_event_access_public_error() above, which predates this
 * domain's own invalid_filter/read_unavailable codes and would collapse
 * both to the same generic server_error bucket a genuine invalid_input
 * already uses.
 */
if (!function_exists('pge_event_access_public_audit_error')) {
    /**
     * Collapses a list_audit_for_actor() WP_Error into a small, fixed,
     * enumeration-resistant public shape — the audit-read counterpart of
     * pge_event_access_public_error() (H1C-W1) and
     * pge_event_access_public_write_error() (H1C-W2+). Kept as its own
     * function rather than folded into either existing one: this domain's
     * reachable codes (invalid_filter, read_unavailable) genuinely differ
     * from both, and the two existing, already-tested mappers must not
     * change behavior for their own callers.
     *
     * Reviewed against map_audit_read_result()'s own documented mapping in
     * PGE_Event_Access_Application_Service (not assumed): not_authorized,
     * invalid_input, invalid_filter, read_unavailable, not_found, and
     * database_error are the only codes that method can return.
     *
     *   not_authorized   -> not_authorized (EC1: an existing-but-denied
     *                       event, a nonexistent event, and a pre-authority
     *                       context-resolution failure all collapse to this
     *                       same code before this function ever runs — see
     *                       resolve_event_actor_context()'s own docblock —
     *                       so this bucket is already indistinguishable by
     *                       construction, not by anything done here)
     *   invalid_input    -> invalid_input
     *   invalid_filter   -> invalid_filter
     *   read_unavailable -> read_unavailable
     *   not_found        -> server_error (a post-authority Repository
     *                       not_found can only mean an internal race — the
     *                       event existed when resolve_event_actor_context()
     *                       checked it a moment earlier — never a caller-
     *                       facing "invalid event" distinct from
     *                       not_authorized; folding it into the generic
     *                       bucket keeps that pre-authority/post-authority
     *                       line intact instead of accidentally re-exposing
     *                       a not_found an unproven actor could ever see)
     *   database_error   -> server_error
     *   anything else    -> server_error (never a raw/unrecognized code
     *                       reaches a caller)
     *
     * No message here ever contains SQL, a table name, a membership id, or
     * a guest phone/name.
     */
    function pge_event_access_public_audit_error($error)
    {
        $code = ($error instanceof WP_Error) ? (string) $error->get_error_code() : '';

        if ($code === PGE_Event_Access_Application_Service::REASON_NOT_AUTHORIZED) {
            return ['message' => 'ليس لديك صلاحية لعرض سجل هذه المناسبة', 'reason' => 'not_authorized'];
        }
        if ($code === PGE_Event_Access_Application_Service::REASON_INVALID_INPUT || $code === 'invalid_input') {
            return ['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input'];
        }
        if ($code === 'invalid_filter') {
            return ['message' => 'معايير التصفية غير صالحة', 'reason' => 'invalid_filter'];
        }
        if ($code === 'read_unavailable') {
            return ['message' => 'الخدمة غير متاحة حالياً، يرجى المحاولة لاحقاً', 'reason' => 'read_unavailable'];
        }

        // not_found / database_error / guest_data_error / anything else
        // unrecognized — a single generic bucket, no internal detail.
        return ['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error'];
    }
}

if (!function_exists('pge_event_access_list_audit_handler')) {
    function pge_event_access_list_audit_handler()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'غير مصرح', 'reason' => 'not_logged_in']);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pge_event_manage_nonce')) {
            wp_send_json_error(['message' => 'رمز الأمان غير صالح', 'reason' => 'invalid_nonce']);
        }

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        if ($event_id === null) {
            wp_send_json_error(['message' => 'مناسبة غير صالحة', 'reason' => 'invalid_event']);
        }

        // page: missing -> the existing default of 1. Explicitly supplied
        // but not a strict positive integer -> passed through as null,
        // which self::validate_pagination() always rejects as invalid_input
        // (it has no "null means default" special case for $page, unlike
        // $per_page below) — the failure is reported by the Application
        // Service's own authoritative validation, not invented here.
        if (!isset($_POST['page']) || $_POST['page'] === '') {
            $page = 1;
        } else {
            $page = pge_event_access_strict_positive_int($_POST['page']);
        }

        // per_page: missing/empty -> null, preserved exactly as before —
        // PGE_Event_Access_Application_Service::validate_pagination()
        // already treats a null $per_page as "substitute Repository's own
        // DEFAULT_PER_PAGE", so null must be reserved for that meaning
        // alone. An explicitly supplied value that fails strict parsing is
        // therefore passed through as 0 instead — pge_event_access_
        // strict_positive_int() never itself returns 0, so this can only
        // ever mean "the client explicitly sent something invalid", and 0
        // always fails validate_pagination()'s own $per_page > 0 check,
        // i.e. invalid_input — never silently reinterpreted as "use the
        // default".
        if (!isset($_POST['per_page']) || $_POST['per_page'] === '') {
            $per_page = null;
        } else {
            $per_page = pge_event_access_strict_positive_int($_POST['per_page']);
            if ($per_page === null) $per_page = 0;
        }

        $filters = [];
        // wp_unslash() ONLY — no sanitize_text_field()/trim()/strtolower()/
        // sanitize_key(). Repository::list_audit()'s own strict exact-match
        // AUDIT_ACTIONS/AUDIT_ENTITY_TYPES check is the sole authority on
        // whether this value is valid; this file must not repair a
        // malformed-but-valid-looking value (stray whitespace, wrong case,
        // ...) into one Repository would accept.
        if (isset($_POST['filter_action']) && $_POST['filter_action'] !== '') {
            $filters['action'] = wp_unslash($_POST['filter_action']);
        }
        if (isset($_POST['filter_entity_type']) && $_POST['filter_entity_type'] !== '') {
            $filters['entity_type'] = wp_unslash($_POST['filter_entity_type']);
        }
        // filter_actor_user_id / filter_entity_id: strict-parsed exactly
        // like event_id above. A malformed value (e.g. "12abc") parses to
        // null — the key is still added (never silently omitted) so
        // Repository::list_audit()'s own valid_id() check authoritatively
        // rejects it as invalid_filter, instead of this file either
        // dropping the filter (silently broadening the query) or coercing
        // "12abc" into 12 (silently querying the wrong actor/entity).
        if (isset($_POST['filter_actor_user_id']) && $_POST['filter_actor_user_id'] !== '') {
            $filters['actor_user_id'] = pge_event_access_strict_positive_int($_POST['filter_actor_user_id']);
        }
        if (isset($_POST['filter_entity_id']) && $_POST['filter_entity_id'] !== '') {
            $filters['entity_id'] = pge_event_access_strict_positive_int($_POST['filter_entity_id']);
        }

        // Actor identity comes exclusively from the authenticated WP
        // session, same as the H1C-W1 read handler above. No client-
        // supplied actor/user/owner/manager id field of any kind is read
        // anywhere in this file to determine the requesting actor.
        $actor_user_id = (int) get_current_user_id();

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::list_audit_for_actor(
            $event_id,
            $actor_user_id,
            $filters,
            $page,
            $per_page
        );

        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_event_access_public_audit_error($result));
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
add_action('wp_ajax_pge_event_access_list_audit', 'pge_event_access_list_audit_handler');

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
     * assign, OR event_id not referring to an existing event) collapses to
     * the same public 'not_authorized' bucket. That last case is the
     * Phase H1C-EC1 Event-Context Enumeration Privacy Hardening fix: the
     * Application Service's shared resolve_event_actor_context() helper
     * now collapses every context-resolution failure — including a
     * nonexistent event_id — to not_authorized BEFORE any of the three
     * write use cases' own capability decision, so 'not_found' below can
     * only ever arrive from a genuine POST-authority Repository lookup (an
     * invalid/archived/cross-event group_id, or an unresolvable guest — see
     * the note above), reachable only by an actor who already passed that
     * capability decision. No message here ever contains SQL, a table
     * name, a membership id, or a guest phone/name.
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
 * actually exists, so it carries no enumeration risk (Group-ID/Membership-ID
 * enumeration resistance specifically). This AJAX layer never references
 * the Authorization class directly by name — it calls only
 * PGE_Event_Access_Application_Service, exactly like every other handler in
 * this file (Section 9's fixed architecture: AJAX -> Application Service ->
 * Authorization -> Repository).
 *
 * Event-Context Enumeration Privacy Hardening (Phase H1C-EC1): the
 * paragraph above does NOT by itself prove Event-ID enumeration
 * resistance — a real gap here was found and fixed, the same family
 * H1C-W4 closed for its own four write methods: a denied actor's event_id
 * previously could distinguish "exists but I'm not authorized" from "does
 * not exist". The fix lives entirely in the Application Service's shared
 * resolve_event_actor_context() helper — see its docblock. This AJAX
 * layer required no behavioral change for the fix itself.
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
     * simply not Owner/Admin, OR event_id not referring to an existing
     * event) collapses to the same public 'not_authorized' bucket — the
     * last case per the Phase H1C-EC1 fix noted in the handler docblock
     * above. No message here ever contains SQL, a table name, a
     * membership id, or a guest phone/name.
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

/**
 * H1C-W4 — Owner/Admin Group Lifecycle Write Wiring: four authenticated
 * AJAX actions (create/rename/archive/set_default), same conventions as the
 * H1C-W2/W3 write handlers above.
 *
 * Registered wp_ajax_ ONLY — never wp_ajax_nopriv_, same as every handler
 * in this file (a dedicated source-level W4 test asserts no
 * wp_ajax_nopriv_pge_event_access_{create,rename,archive,set_default}_group
 * hook exists anywhere in this file).
 *
 * Actor identity: actor_user_id is read exclusively via
 * pge_event_access_write_request_context() — the same shared helper every
 * other write handler in this file uses, reading get_current_user_id()
 * only. There is no $_POST['actor_user_id']/['user_id']/['owner_id'] read
 * anywhere in this file; a client-sent field of that name is silently
 * ignored, which a dedicated "actor spoof" test in the W4 suite verifies
 * end-to-end.
 *
 * This is an Owner/Admin-only operation (the Authorization Core's
 * can_manage_event_structure() capability check takes no group-scoping
 * parameter at all — see the Application Service docblock for the full
 * contract), so exactly like the H1C-W3 handlers above there is no
 * group-scoped actor to reason about here: a Manager/Viewer/Revoked/
 * Stranger caller is rejected by role before any existence-sensitive
 * Repository lookup of group_id. group_id (and, for CREATE, the name
 * fields) are still parsed/read here in each handler before the
 * Application Service call (same as event_id) — that parsing reveals
 * nothing about whether either actually exists, so it carries no
 * enumeration risk. This AJAX layer never references the Authorization
 * class directly by name — it calls only
 * PGE_Event_Access_Application_Service, exactly like every other handler
 * in this file (Section 9's fixed architecture: AJAX -> Application
 * Service -> Authorization -> Repository).
 *
 * create_group's $make_default parameter is deliberately NOT exposed here
 * — this phase's AJAX surface stays at exactly what create/rename/archive/
 * set_default need; making a newly created group the default is reachable
 * by composing CREATE then SET DEFAULT, via the already-dedicated
 * set_default endpoint, without adding a second way to reach the same
 * effect from a single endpoint.
 *
 * Event-Context Enumeration Privacy Fix Pass: the paragraph above proves
 * only Group-ID enumeration resistance (group_id parsing reveals nothing
 * about group existence) — it does NOT by itself prove Event-ID
 * enumeration resistance, and a real gap here was found and fixed: a
 * denied actor's event_id previously could distinguish "exists but I'm not
 * authorized" (not_authorized) from "does not exist" (not_found), because
 * the Authorization Core's context-resolution step checks event existence
 * before any role decision. The fix lives entirely in
 * PGE_Event_Access_Application_Service::resolve_group_structure_authority()
 * — see its docblock — which now collapses every context-resolution
 * failure to not_authorized before this handler ever sees a result. This
 * AJAX layer required no change for the fix itself.
 */

if (!function_exists('pge_event_access_read_group_name')) {
    /**
     * Reads a $_POST field intended as a group name WITHOUT
     * sanitize_text_field(). Repository's own normalize_group_name_input()
     * is the sole authority on what a valid group name looks like (trims/
     * collapses whitespace, rejects '<'/'>' and control characters,
     * enforces a 191-codepoint cap) — running sanitize_text_field() here
     * first would silently strip '<'/'>' via strip_tags() before Repository
     * ever sees them, defeating that exact check and changing observable
     * behavior with no contract change behind it. wp_unslash() alone
     * reverses WordPress's own magic-quotes escaping of $_POST and changes
     * nothing else. A missing field returns null; a non-string field (e.g.
     * a $_POST['name'][] array) passes through UNCHANGED — the caller's own
     * is_string() gate (and, redundantly, Application Service's) rejects it
     * as invalid_input before any Repository call, exactly like a malformed
     * group_id would be rejected by strict-positive-int parsing.
     */
    function pge_event_access_read_group_name($field)
    {
        if (!array_key_exists($field, $_POST)) return null;
        return wp_unslash($_POST[$field]);
    }
}

if (!function_exists('pge_event_access_public_group_write_error')) {
    /**
     * Collapses an Application Service group-lifecycle write-use-case
     * WP_Error into a small, fixed, enumeration-resistant public shape —
     * the group-lifecycle counterpart of pge_event_access_public_write_error()
     * and pge_event_access_public_group_access_write_error() above. Kept
     * separate on purpose: this domain's reachable codes differ again
     * (invalid_name, duplicate — see
     * PGE_Event_Access_Application_Service::map_group_write_result()'s own
     * docblock for the exact, reviewed mapping), and the existing functions
     * back already-tested handlers and must not change under this phase's
     * own "no unrelated refactor" instruction.
     *
     * Every denial reason (stranger, revoked membership, forged context,
     * simply not Owner/Admin, OR event_id not referring to an existing
     * event) collapses to the same public 'not_authorized' bucket. That
     * last case — a nonexistent event_id — is the Event-Context
     * Enumeration Privacy Fix Pass this phase's independent review found
     * and fixed: PGE_Event_Access_Application_Service's shared
     * resolve_group_structure_authority() gate now maps EVERY
     * resolve_context() WP_Error (not just an authorization denial) to
     * this same 'not_authorized' code before it ever reaches this
     * function, so 'not_found' can only ever arrive here from a
     * POST-authority Repository lookup (a missing/cross-event group_id,
     * reachable only by an actor who already proved Owner/Admin authority
     * — see that method's own docblock). No message here ever contains
     * SQL, a table name, a group id, or a raw exception message.
     */
    function pge_event_access_public_group_write_error($error)
    {
        $code = ($error instanceof WP_Error) ? (string) $error->get_error_code() : '';

        if ($code === PGE_Event_Access_Application_Service::REASON_NOT_AUTHORIZED) {
            return ['message' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء', 'reason' => 'not_authorized'];
        }
        if ($code === PGE_Event_Access_Application_Service::REASON_INVALID_INPUT || $code === 'invalid_input') {
            return ['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input'];
        }
        if ($code === 'invalid_name') {
            return ['message' => 'اسم المجموعة غير صالح', 'reason' => 'invalid_name'];
        }
        if ($code === 'not_found') {
            return ['message' => 'لم يتم العثور على العنصر المطلوب', 'reason' => 'not_found'];
        }
        if ($code === 'invalid_state') {
            return ['message' => 'حالة العنصر الحالية لا تسمح بهذا الإجراء', 'reason' => 'invalid_state'];
        }
        if ($code === 'duplicate') {
            return ['message' => 'يوجد بالفعل عنصر بنفس الاسم', 'reason' => 'duplicate'];
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

if (!function_exists('pge_event_access_send_group_write_result')) {
    function pge_event_access_send_group_write_result($result)
    {
        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_event_access_public_group_write_error($result));
        }
        if (!is_array($result) || !array_key_exists('changed', $result) || !array_key_exists('group', $result) || !is_array($result['group'])) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        wp_send_json_success([
            'changed' => $result['changed'],
            'group' => $result['group'],
        ]);
    }
}

if (!function_exists('pge_event_access_create_group_handler')) {
    function pge_event_access_create_group_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $name = pge_event_access_read_group_name('name');

        if ($event_id === null || !is_string($name)) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::create_group_for_actor(
            $event_id,
            $actor_user_id,
            $name
        );

        pge_event_access_send_group_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_create_group', 'pge_event_access_create_group_handler');

if (!function_exists('pge_event_access_rename_group_handler')) {
    function pge_event_access_rename_group_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $group_id = pge_event_access_strict_positive_int($_POST['group_id'] ?? null);
        $expected_name = pge_event_access_read_group_name('expected_name');
        $new_name = pge_event_access_read_group_name('new_name');

        if ($event_id === null || $group_id === null || !is_string($expected_name) || !is_string($new_name)) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::rename_group_for_actor(
            $event_id,
            $actor_user_id,
            $group_id,
            $expected_name,
            $new_name
        );

        pge_event_access_send_group_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_rename_group', 'pge_event_access_rename_group_handler');

if (!function_exists('pge_event_access_archive_group_handler')) {
    function pge_event_access_archive_group_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $group_id = pge_event_access_strict_positive_int($_POST['group_id'] ?? null);

        if ($event_id === null || $group_id === null) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::archive_group_for_actor(
            $event_id,
            $actor_user_id,
            $group_id
        );

        pge_event_access_send_group_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_archive_group', 'pge_event_access_archive_group_handler');

if (!function_exists('pge_event_access_set_default_group_handler')) {
    function pge_event_access_set_default_group_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $group_id = pge_event_access_strict_positive_int($_POST['group_id'] ?? null);

        if ($event_id === null || $group_id === null) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::set_default_group_for_actor(
            $event_id,
            $actor_user_id,
            $group_id
        );

        pge_event_access_send_group_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_set_default_group', 'pge_event_access_set_default_group_handler');

/**
 * H1C-W5 — Owner/Admin Membership Lifecycle Write Wiring: four authenticated
 * AJAX actions (create/change_role/revoke/reactivate), same conventions as
 * every write handler above.
 *
 * Registered wp_ajax_ ONLY — never wp_ajax_nopriv_ (a dedicated source-level
 * W5 test asserts no wp_ajax_nopriv_pge_event_access_{create,change_role,
 * revoke,reactivate}_membership hook exists anywhere in this file).
 *
 * Actor identity: actor_user_id is read exclusively via
 * pge_event_access_write_request_context() — get_current_user_id() only.
 * There is no $_POST['actor_user_id']/['owner_id']/['manager_id'] read
 * anywhere in this file; a client-sent field of that name is silently
 * ignored (Section 20/22's actor-spoof tests verify this end-to-end).
 * CREATE alone reads a $_POST['target_user_id'] field — deliberately named
 * apart from 'user_id' (see that handler's own inline comment: three other
 * phases' pre-existing source guards scan this whole file for the literal
 * $_POST['user_id'] as an actor-spoof red flag, and 'target_user_id' avoids
 * colliding with that check over a naming choice rather than an actual
 * risk). It identifies WHO IS BEING GRANTED membership, never who is
 * acting — spoofing it only changes which user a proven Owner/Admin's
 * CREATE would target, it can never forge the actor's own authority, since
 * actor_user_id itself is never read from $_POST at all (Section 20's
 * spoof tests verify this explicitly for target_user_id too).
 *
 * This is an Owner/Admin-only operation (can_manage_membership() takes no
 * group-scoping parameter, identical shape to can_manage_event_structure()/
 * can_manage_group_access()), so exactly like the H1C-W3/W4 handlers a
 * Manager/Viewer/Revoked/Stranger caller is rejected by role before any
 * existence-sensitive Repository lookup of membership_id, user_id, role, or
 * status — this is what makes Section 17/18's User-ID and Membership-ID
 * enumeration resistance true here, not any extra AJAX-layer logic. This
 * file never references the Authorization class directly by name — it
 * calls only PGE_Event_Access_Application_Service, exactly like every other
 * handler in this file (AJAX -> Application Service -> Authorization ->
 * Repository).
 *
 * Role fields (expected_role/new_role on CHANGE ROLE; role on CREATE/
 * REACTIVATE) are read via pge_event_access_read_membership_role() — raw
 * wp_unslash() only, deliberately NOT sanitize_key()'d or lowercased, so an
 * invalid role (wrong case, unrecognized string) fails Application
 * Service's is_string() gate and Repository's own exact-match valid_role()
 * as invalid_input rather than being silently coerced into a different,
 * unintended role.
 *
 * Event-Context Enumeration Privacy (Phase H1C-EC1, reused unmodified): the
 * shared resolve_event_actor_context() gate these four Application Service
 * methods call means a nonexistent event_id and an existing-but-denied
 * event are publicly indistinguishable here too — no AJAX-layer change was
 * needed for that guarantee to hold.
 */

if (!function_exists('pge_event_access_read_membership_role')) {
    /**
     * Reads a $_POST field intended as a membership role WITHOUT
     * sanitize_key()/sanitize_text_field() — see this section's top
     * docblock for why. wp_unslash() alone reverses WordPress's magic-quotes
     * escaping and changes nothing else. A missing field returns null; a
     * non-string field passes through UNCHANGED — the caller's own
     * is_string() gate (and, redundantly, Application Service's) rejects it
     * as invalid_input before any Repository call.
     */
    function pge_event_access_read_membership_role($field)
    {
        if (!array_key_exists($field, $_POST)) return null;
        return wp_unslash($_POST[$field]);
    }
}

if (!function_exists('pge_event_access_public_membership_write_error')) {
    /**
     * Collapses an Application Service membership-lifecycle write-use-case
     * WP_Error into a small, fixed, enumeration-resistant public shape —
     * the membership-lifecycle counterpart of pge_event_access_public_write_error()/
     * pge_event_access_public_group_access_write_error()/
     * pge_event_access_public_group_write_error() above. Kept separate on
     * purpose: this domain's reachable codes differ again (duplicate maps
     * from 'duplicate_membership', not 'duplicate_group' — see
     * PGE_Event_Access_Application_Service::map_membership_write_result()'s
     * own docblock for the exact, reviewed mapping), and the existing
     * functions back already-tested handlers and must not change under this
     * phase's own "no unrelated refactor" instruction.
     *
     * Every denial reason (stranger, revoked membership, forged context,
     * simply not Owner/Admin, OR event_id not referring to an existing
     * event) collapses to the same public 'not_authorized' bucket — the
     * last case via the shared resolve_event_actor_context() gate (Phase
     * H1C-EC1), reused unmodified by this phase. No message here ever
     * contains SQL, a table name, a membership id, or a WP user id.
     */
    function pge_event_access_public_membership_write_error($error)
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
        if ($code === 'duplicate') {
            return ['message' => 'يوجد بالفعل عضو مسجل بهذه المناسبة', 'reason' => 'duplicate'];
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

if (!function_exists('pge_event_access_send_membership_write_result')) {
    function pge_event_access_send_membership_write_result($result)
    {
        if ($result instanceof WP_Error) {
            wp_send_json_error(pge_event_access_public_membership_write_error($result));
        }
        if (!is_array($result) || !array_key_exists('changed', $result) || !array_key_exists('membership_id', $result)
            || !array_key_exists('user_id', $result) || !array_key_exists('role', $result) || !array_key_exists('status', $result)) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        wp_send_json_success([
            'changed' => $result['changed'],
            'membership_id' => $result['membership_id'],
            'user_id' => $result['user_id'],
            'role' => $result['role'],
            'status' => $result['status'],
        ]);
    }
}

if (!function_exists('pge_event_access_create_membership_handler')) {
    function pge_event_access_create_membership_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        // Read as 'target_user_id', deliberately NOT 'user_id': W2/W3/W4's
        // own pre-existing actor-spoof source guards each scan this whole
        // file for the literal $_POST['user_id'] and treat any match as a
        // spoofable-actor-identity red flag (that field never had a
        // legitimate meaning anywhere in this file before this phase). This
        // field is the CREATE target (who is being granted membership), not
        // an actor-identity claim — actor_user_id is still read exclusively
        // via pge_event_access_write_request_context() a few lines above,
        // never from $_POST. Naming the target field 'target_user_id'
        // keeps that distinction unambiguous in the wire format itself and
        // avoids re-tripping three other phases' already-passing guards
        // over a naming collision rather than an actual spoof risk.
        $user_id = pge_event_access_strict_positive_int($_POST['target_user_id'] ?? null);
        $role = pge_event_access_read_membership_role('role');

        if ($event_id === null || $user_id === null || !is_string($role)) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::create_membership_for_actor(
            $event_id,
            $actor_user_id,
            $user_id,
            $role
        );

        pge_event_access_send_membership_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_create_membership', 'pge_event_access_create_membership_handler');

if (!function_exists('pge_event_access_change_membership_role_handler')) {
    function pge_event_access_change_membership_role_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $membership_id = pge_event_access_strict_positive_int($_POST['membership_id'] ?? null);
        $expected_role = pge_event_access_read_membership_role('expected_role');
        $new_role = pge_event_access_read_membership_role('new_role');

        if ($event_id === null || $membership_id === null || !is_string($expected_role) || !is_string($new_role)) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::change_membership_role_for_actor(
            $event_id,
            $actor_user_id,
            $membership_id,
            $expected_role,
            $new_role
        );

        pge_event_access_send_membership_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_change_membership_role', 'pge_event_access_change_membership_role_handler');

if (!function_exists('pge_event_access_revoke_membership_handler')) {
    function pge_event_access_revoke_membership_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $membership_id = pge_event_access_strict_positive_int($_POST['membership_id'] ?? null);

        if ($event_id === null || $membership_id === null) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::revoke_membership_for_actor(
            $event_id,
            $actor_user_id,
            $membership_id
        );

        pge_event_access_send_membership_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_revoke_membership', 'pge_event_access_revoke_membership_handler');

if (!function_exists('pge_event_access_reactivate_membership_handler')) {
    function pge_event_access_reactivate_membership_handler()
    {
        $actor_user_id = pge_event_access_write_request_context();

        $event_id = pge_event_access_strict_positive_int($_POST['event_id'] ?? null);
        $membership_id = pge_event_access_strict_positive_int($_POST['membership_id'] ?? null);
        $role = pge_event_access_read_membership_role('role');

        if ($event_id === null || $membership_id === null || !is_string($role)) {
            wp_send_json_error(['message' => 'بيانات الطلب غير صالحة', 'reason' => 'invalid_input']);
        }

        if (!class_exists('PGE_Event_Access_Application_Service')) {
            wp_send_json_error(['message' => 'تعذّر تنفيذ العملية حالياً', 'reason' => 'server_error']);
        }

        $result = PGE_Event_Access_Application_Service::reactivate_membership_for_actor(
            $event_id,
            $actor_user_id,
            $membership_id,
            $role
        );

        pge_event_access_send_membership_write_result($result);
    }
}
add_action('wp_ajax_pge_event_access_reactivate_membership', 'pge_event_access_reactivate_membership_handler');
