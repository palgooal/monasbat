<?php
if (!defined('ABSPATH')) exit;

/**
 * H1C-W1 — Collaborator Read-Only Application Wiring (original scope of
 * this file). H1C-W2 — Collaborator Scoped Guest-Assignment Write Wiring
 * (Section below "Collaborator scoped guest-assignment writes"): the first
 * writes this class performs, added in a later phase. H1C-W3 — Owner/Admin
 * Group-Access Lifecycle Write Wiring (Section below "Owner/Admin
 * group-access lifecycle writes"): the second write surface, added in a
 * still later phase.
 *
 * The first production Application-layer consumer of the H1B Authorization
 * Core (PGE_Event_Access_Authorization / PGE_Event_Access_Authorization_Context)
 * and Repository (PGE_Event_Access_Repository). The original H1C-W1 read
 * paths above remain read-only exactly as before — they still call no
 * write/mutation method on either dependency. As of H1C-W2, this class ALSO
 * calls exactly three Repository mutation methods: assign_guest_to_group /
 * move_guest_to_group / unassign_guest_from_group (see the dedicated
 * section below). As of H1C-W3, this class additionally calls exactly two
 * more: grant_group_access / revoke_group_access (see that section's own
 * dedicated block below) — five Repository mutation methods total. Every
 * other write/mutation method (create_group/rename_group/archive_group/
 * set_default_group/create_membership/change_membership_role/
 * revoke_membership/reactivate_membership) is still never referenced here
 * and remains out of scope for this class.
 *
 * Architecture (Section 6 of the H1C-W1 brief):
 *
 *   Transport (future AJAX handler)
 *     -> Authentication/nonce (transport's job, not this class's)
 *     -> PGE_Event_Access_Application_Service (this file)
 *     -> PGE_Event_Access_Authorization (decision)
 *     -> PGE_Event_Access_Repository / guest store (data)
 *
 * This class is deliberately NOT HTTP-aware, NOT nonce-aware, and NOT
 * session-aware: every method takes $actor_user_id as an explicit
 * parameter, exactly like PGE_Event_Access_Authorization itself. It never
 * calls current_user_can()/get_current_user_id()/wp_verify_nonce()/any
 * wp_ajax_ action/register_rest_route(). A transport layer is responsible for turning a real
 * WordPress session into a trusted actor_user_id and handing it in — this
 * class never trusts a client-supplied actor id itself (it has no concept of
 * "client" at all).
 *
 * Owner/Admin vs collaborator: PGE_Event_Access_Authorization::
 * list_scoped_group_assignments() intentionally refuses Owner/Admin
 * (invalid_scope, by design — Section 13 of the H1C-A2 brief). This service
 * therefore never routes Owner/Admin through that method; it reads the
 * existing guest store the same way the current production Owner-facing
 * listing does (pge_event_guests_get_map()/pge_event_guests_get_row_payload(),
 * exactly as PGE_Invitation_Repository::list_invitations() already does for
 * the existing Owner UI) and does not change existing guest semantics.
 *
 * Guest storage boundary — Owner/Admin (unchanged since H1C-W1, unchanged by
 * GR1): guest identity data (name/note/code) is authoritative in a single
 * WordPress Post Meta value per event (`_pge_invited_guests`, DEC-004 —
 * docs/DECISION-LOG.md), and get_post_meta() always deserializes the entire
 * stored value for that meta key. Owner/Admin genuinely need every guest in
 * the event, assigned or not, so this path still reads it directly via
 * pge_event_guests_get_map() — that is a real full-event read, not an
 * over-fetch, and GR1 deliberately leaves it unchanged (H1C-GR1 brief,
 * Section 23: "لا تغيّر Owner/Admin في GR1 إلا لسبب مثبت").
 *
 * Guest storage boundary — Manager/Viewer (collaborator), changed by Phase
 * H1C-GR1: this path no longer calls pge_event_guests_get_map() in its
 * steady-state (H1C-W1D found this to be Storage/PII scoping = NO — the
 * full per-event blob was loaded into PHP memory before extracting only the
 * authorized phones). It now resolves guest identity via
 * PGE_Event_Guest_Read_Projection::get_guests_by_phones() — a real scoped
 * SQL query (event_id + guest_phone IN (...only the phones already proven
 * authorized by the H1B scoped assignment read...)) against a derived,
 * rebuildable, non-authoritative relational projection kept in sync from
 * the single existing pge_event_guests_save_map() write choke point. See
 * that class's own docblock for the full sync/freshness/rebuild contract,
 * and DEC-004 for why Post Meta remains Source of Truth throughout. This
 * class still:
 *   (a) always resolves H1B scope FIRST (resolve_context() + a real,
 *       DB-paginated, zero-Post-Meta-touch scoped assignment read for
 *       collaborators) before any guest-identity lookup of any kind;
 *   (b) for a collaborator, only ever requests the handful of phones
 *       (bounded by $per_page, at most PGE_Event_Access_Repository::
 *       MAX_PER_PAGE = 100) that were already proven authorized by the
 *       scoped assignment read — no other phone is ever passed to the
 *       projection lookup, and the underlying SQL query only ever selects
 *       rows matching those exact phones;
 *   (c) never touches the guest store at all when a collaborator has zero
 *       granted groups or zero assignments on the requested page (Section
 *       22 — list_scoped_group_assignments() itself already short-circuits
 *       to an empty result without issuing even a group-assignment query in
 *       the zero-grant case).
 * The one exception is recovery: if the projection is not yet built or is
 * found incomplete for the requested phones, exactly one rebuild-from-Post-
 * Meta is triggered and the scoped fetch retried once before failing closed
 * — see scoped_guest_identity_rows() below. That rebuild is architecturally
 * the same category as the Owner/Admin full-event read above (event-wide,
 * not actor-scoped), not a per-collaborator-request PII over-fetch.
 */
final class PGE_Event_Access_Application_Service
{
    const REASON_NOT_AUTHORIZED = 'not_authorized';
    const REASON_INVALID_INPUT = 'invalid_input';
    const REASON_GUEST_DATA_ERROR = 'guest_data_error';

    /**
     * Use case: list every guest the actor is authorized to see for this
     * event, with the correct role projection applied, paginated.
     *
     * Owner/Admin: every guest in the event (assigned or not), Full
     * projection, group_id is the guest's current group or null if
     * unassigned.
     *
     * Manager/Viewer (active membership required): only guests currently
     * assigned to one of the actor's granted groups, Manager/Viewer
     * projection respectively. Unassigned guests are never returned to a
     * collaborator (Section 18 of the H1C-A2 brief, unchanged).
     *
     * Stranger / revoked membership / any other non-collaborator,
     * non-owner, non-admin trusted context: denied (WP_Error,
     * REASON_NOT_AUTHORIZED) — never an empty successful list, so an
     * authorization bug can never present itself to a caller as "there
     * happen to be no guests" (Section 21).
     *
     * @return array{items:array<int,array{guest:array,group_id:?int}>,page:int,per_page:int,total:int,total_pages:int}|WP_Error
     */
    public static function list_accessible_guests_for_actor($event_id, $actor_user_id, $page = 1, $per_page = null)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id)) {
            return self::invalid_input();
        }

        $context = PGE_Event_Access_Authorization::resolve_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) return self::not_authorized();

        $pagination = self::validate_pagination($page, $per_page);
        if ($pagination instanceof WP_Error) return $pagination;

        if ($context->is_admin() || $context->is_owner()) {
            return self::list_for_owner_or_admin($context, $pagination['page'], $pagination['per_page']);
        }

        if ($context->is_active_collaborator()) {
            return self::list_for_collaborator($context, $pagination['page'], $pagination['per_page']);
        }

        // Stranger, or an actor whose membership row exists but is revoked:
        // a real, trusted context with no guest-read authority at all. Deny
        // — never fall through to an empty success (Section 21).
        return self::not_authorized();
    }

    // ──────────────────────────────────────────────────────────────
    // Owner / Admin read path
    // ──────────────────────────────────────────────────────────────

    /**
     * Owner/Admin have full event authority and need no scoping at all —
     * list_scoped_group_assignments() intentionally refuses them
     * (invalid_scope by design), so this path never calls it. It reads the
     * guest store the same way the existing production Owner-facing listing
     * does (pge_event_guests_get_map()/pge_event_guests_get_row_payload()),
     * unchanged in semantics, and additionally annotates each guest with
     * its current group_id (null if unassigned) via the Repository's
     * existing read-only map_guest_groups() — no new Repository method, no
     * schema change.
     */
    private static function list_for_owner_or_admin($context, $page, $per_page)
    {
        if (!function_exists('pge_event_guests_get_map')
            || !function_exists('pge_event_guests_get_row_payload')) {
            return self::guest_data_error();
        }

        $event_id = $context->event_id();
        $map = pge_event_guests_get_map($event_id);
        if (!is_array($map)) return self::guest_data_error();

        // Deterministic, storage-order-independent pagination: sort by the
        // normalized phone. Read from each entry's own 'phone' value, not
        // via array_keys($map) — PHP silently coerces an all-digit string
        // array key to a real int, which would hand map_guest_groups() (and
        // any is_string() check downstream) an int instead of the phone
        // string it actually is (the same pitfall PGE_Event_Access_
        // Repository::map_guest_groups() already documents in its own
        // docblock for exactly this reason).
        $phones = [];
        foreach ($map as $guest_record) {
            if (is_array($guest_record) && isset($guest_record['phone'])) {
                $phones[] = (string) $guest_record['phone'];
            }
        }
        sort($phones, SORT_STRING);

        $total = count($phones);
        $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 0;
        $offset = ($page - 1) * $per_page;
        $page_phones = array_slice($phones, $offset, $per_page);

        if ($page_phones === []) {
            return self::page_result([], $page, $per_page, $total, $total_pages);
        }

        $group_by_phone = self::resolve_group_by_phone($event_id, $page_phones);
        if ($group_by_phone instanceof WP_Error) return $group_by_phone;

        $items = [];
        foreach ($page_phones as $phone) {
            $guest_record = $map[$phone] ?? null;
            if (!is_array($guest_record) || !array_key_exists('phone', $guest_record)) {
                // pge_event_guests_get_map() already normalizes every entry
                // into this shape; this is an unreachable-in-practice
                // defensive guard, not a silent drop — fail the whole read
                // closed rather than ever return a partial/guessed guest.
                return self::guest_data_error();
            }
            $row_payload = pge_event_guests_get_row_payload($event_id, $guest_record);
            $items[] = [
                'guest' => PGE_Event_Access_Authorization::project_guest_fields($context, $row_payload),
                'group_id' => $group_by_phone[$phone] ?? null,
            ];
        }

        return self::page_result($items, $page, $per_page, $total, $total_pages);
    }

    /**
     * @return array<string,int> phone -> group_id, only for phones actually
     *   currently assigned; a phone absent from the returned array is
     *   unassigned.
     */
    private static function resolve_group_by_phone($event_id, array $phones)
    {
        if (!class_exists('PGE_Event_Access_Repository')
            || !method_exists('PGE_Event_Access_Repository', 'map_guest_groups')) {
            return self::guest_data_error();
        }
        $rows = PGE_Event_Access_Repository::map_guest_groups($event_id, $phones);
        if ($rows instanceof WP_Error) return self::guest_data_error();
        if (!is_array($rows)) return self::guest_data_error();

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['guest_phone'], $row['group_id'])) {
                return self::guest_data_error();
            }
            $out[$row['guest_phone']] = (int) $row['group_id'];
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    // Manager / Viewer (collaborator) read path
    // ──────────────────────────────────────────────────────────────

    /**
     * Scope is resolved entirely at the database layer first
     * (list_scoped_group_assignments() — real SQL pagination over the H1B
     * assignment table, zero Post Meta access, and a zero-query short
     * circuit when the actor has no granted groups at all).
     *
     * Phase H1C-GR1: guest identity for the authorized phones is now
     * resolved via PGE_Event_Guest_Read_Projection::get_guests_by_phones() —
     * a real scoped SQL query (event_id + guest_phone IN (...only the
     * authorized phones...)) against a derived relational read projection,
     * NOT pge_event_guests_get_map(). This method never calls
     * pge_event_guests_get_map() (verified by a dedicated source-level test,
     * Section 34 of the GR1 brief) in its steady-state path. The one
     * exception, by design, is recovery: if the projection is not ready or
     * turns out incomplete for the requested phones, PGE_Event_Guest_Read_
     * Projection::rebuild_event() is called (which itself reads the full
     * Post Meta map once, the same event-wide category of read as the
     * existing Owner/Admin path) and the scoped SQL fetch is retried once;
     * if it is still incomplete after that, the read fails closed
     * (REASON_GUEST_DATA_ERROR) — it never silently falls back to loading
     * pge_event_guests_get_map() and filtering in PHP (Section 16 of the
     * GR1 brief: that would quietly reintroduce Storage/PII scoping = NO).
     */
    private static function list_for_collaborator($context, $page, $per_page)
    {
        $scoped = PGE_Event_Access_Authorization::list_scoped_group_assignments($context, $page, $per_page);
        if ($scoped instanceof WP_Error) return self::not_authorized();
        if (!is_array($scoped) || !isset($scoped['items']) || !is_array($scoped['items'])) {
            return self::guest_data_error();
        }

        if ($scoped['items'] === []) {
            // Zero-grant (or zero-assignment-on-this-page) collaborator:
            // successful empty result, and — critically — no guest store
            // access happens below this line at all (Section 22).
            return self::page_result([], (int) $scoped['page'], (int) $scoped['per_page'], (int) $scoped['total'], (int) $scoped['total_pages']);
        }

        if (!class_exists('PGE_Event_Guest_Read_Projection')
            || !function_exists('pge_event_guests_get_row_payload')) {
            return self::guest_data_error();
        }

        $event_id = $context->event_id();
        $phones = [];
        $group_by_phone = [];
        foreach ($scoped['items'] as $assignment) {
            if (!is_array($assignment) || !isset($assignment['guest_phone'], $assignment['group_id'])) {
                return self::guest_data_error();
            }
            $phone = (string) $assignment['guest_phone'];
            $phones[] = $phone;
            $group_by_phone['phone:' . $phone] = (int) $assignment['group_id'];
        }

        $guest_rows = self::scoped_guest_identity_rows($event_id, $phones);
        if ($guest_rows instanceof WP_Error) return self::guest_data_error();

        $items = [];
        foreach ($phones as $phone) {
            $guest_record = $guest_rows['phone:' . $phone] ?? null;

            // Still missing after the rebuild-and-retry above: the H1B
            // assignment is authorized, but no matching guest identity
            // exists (deleted from Post Meta without an assignment cleanup,
            // or a genuinely corrupted projection). Fail the entire scoped
            // read closed (Section 13/29 of the H1C-W1 brief, unchanged by
            // GR1) rather than silently drop this one row.
            if (!is_array($guest_record) || !array_key_exists('phone', $guest_record)) {
                return self::guest_data_error();
            }

            $row_payload = pge_event_guests_get_row_payload($event_id, $guest_record);
            $items[] = [
                'guest' => PGE_Event_Access_Authorization::project_guest_fields($context, $row_payload),
                'group_id' => $group_by_phone['phone:' . $phone] ?? null,
            ];
        }

        return self::page_result($items, (int) $scoped['page'], (int) $scoped['per_page'], (int) $scoped['total'], (int) $scoped['total_pages']);
    }

    /**
     * Scoped guest-identity resolution for a bounded, already-authorized
     * phone list. Ensures the projection is ready, performs the scoped SQL
     * fetch, and — if the result is incomplete for the requested phones —
     * forces a fresh rebuild-from-Post-Meta and retries before failing
     * closed (Section 16/17/32 of the original GR1 brief: rebuild, retry,
     * then fail closed — never a silent drop, never a fallback to loading
     * the full guest map and filtering in PHP).
     *
     * Freshness Publication Protocol Redesign: this method no longer calls
     * PGE_Event_Guest_Read_Projection::is_ready() as a separate pre-check
     * before every scoped fetch. get_guests_by_phones() already resolves
     * readiness internally (it never queries a build-in-progress
     * generation, and returns an empty list — not an error — when nothing
     * is currently active), so a redundant is_ready() call would only add
     * a third SQL query to the steady-state collaborator read path for no
     * additional correctness — the original GR1 performance target is a
     * bounded, small query count per scoped read. "Not ready" and "ready
     * but incomplete for the requested phones" are therefore handled
     * identically here: both surface as a result missing one or more
     * requested phones, which triggers exactly one rebuild-and-retry, up to
     * $max_attempts, before failing closed.
     *
     * A rebuild_event() failure is not treated as an immediate hard error
     * either: under the fingerprint-verify protocol, rebuild_event() can
     * legitimately return false for a reason that is NOT a database
     * failure — its own publish attempt can lose to a fresher concurrent
     * write whose content no longer matches what this rebuild fresh-read at
     * the start (attempt_publish() rejects a stale-on-arrival candidate by
     * design). That is a benign, self-healing loss, not a fault: the next
     * attempt's get_guests_by_phones() call simply re-resolves whatever
     * generation is active by then, which may already be exactly what is
     * needed. Only the FINAL attempt's failure is treated as unrecoverable.
     * This bounded retry never weakens the fail-closed guarantee: rows are
     * still only ever returned once a scoped fetch proves complete for
     * every requested phone.
     *
     * @return array<string,array{phone:string,name:string,note:string,code:string}> phone-prefixed
     *   lookup ("phone:<phone>" => row), or WP_Error on unrecoverable failure.
     */
    private static function scoped_guest_identity_rows($event_id, array $phones)
    {
        $max_attempts = 3;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones($event_id, $phones);
            if ($rows instanceof WP_Error) return self::guest_data_error();
            if (!is_array($rows)) return self::guest_data_error();

            $lookup = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['phone'])) {
                    return self::guest_data_error();
                }
                $lookup['phone:' . $row['phone']] = $row;
            }

            $complete = true;
            foreach ($phones as $phone) {
                if (!isset($lookup['phone:' . $phone])) {
                    $complete = false;
                    break;
                }
            }

            if ($complete) {
                return $lookup;
            }

            // Not ready yet, or ready but incomplete for the requested
            // phones: force a fresh rebuild from cache-bypassing Post Meta
            // and retry, unless this was already the final attempt.
            if (!PGE_Event_Guest_Read_Projection::rebuild_event($event_id) && $attempt === $max_attempts) {
                return self::guest_data_error();
            }
        }

        // Exhausted every attempt: fail closed.
        return self::guest_data_error();
    }

    // ──────────────────────────────────────────────────────────────
    // Collaborator scoped guest-assignment writes (Phase H1C-W2)
    // ──────────────────────────────────────────────────────────────

    /**
     * H1C-W2 — Collaborator Scoped Guest-Assignment Write Wiring.
     *
     * Architecture (unchanged from H1C-W1): Transport (AJAX) resolves the
     * actor from the real WordPress session and hands in $actor_user_id
     * explicitly; this class is still not HTTP/session/nonce-aware. Every
     * write use case below follows the same fixed order:
     *
     *   1. strict scalar input validation (REASON_INVALID_INPUT, before any
     *      Authorization/Repository call);
     *   2. PGE_Event_Access_Authorization::resolve_context() — a fresh,
     *      point-in-time trusted context, resolved fresh for this call (no
     *      caller-supplied context is ever accepted, exactly like H1C-W1);
     *   3. the matching H1C-A2 can_*_guest() decision, using the actual
     *      group ids involved — NEVER "actor has *a* granted group", always
     *      the specific group(s) this operation touches;
     *   4. only on an explicit allow, the matching H1B
     *      PGE_Event_Access_Repository mutation method — Repository is
     *      never invoked when the decision is deny (Section 10/36 of the
     *      H1C-W2 brief: a Repository mutation must never even be attempted
     *      for a denied actor, not merely have its result discarded).
     *
     * resolve_context() is freshly resolved for each use case immediately
     * before the operation-specific authorization/precondition sequence — no
     * caller-supplied or cached context is ever accepted, and it is never
     * resolved once and reused across a later, separate call (the pattern
     * H1C-A2's TOCTOU contract is actually guarding against). For
     * assign_guest_to_group_for_actor() this resolution is immediately
     * followed by the can_assign_guest() decision and, on allow, the
     * Repository call, with no intervening read. For
     * move_guest_to_group_for_actor()/unassign_guest_from_group_for_actor()
     * a Manager actor additionally goes through a scoped current-assignment
     * pre-check between the can_*_guest() decision and the Repository call
     * (see the next paragraph) — that pre-check and the Repository mutation
     * are separate operations and are NOT atomic with each other; the
     * Repository's own transactional expected_group_id compare-and-swap
     * remains the sole concurrency/correctness authority regardless.
     *
     * Scoped Manager Enumeration/Privacy Fix Pass — TOCTOU honesty note:
     * move_guest_to_group_for_actor()/unassign_guest_from_group_for_actor()
     * additionally run manager_scoped_current_assignment_check() (a
     * separate, non-locking relational read) between the can_*_guest()
     * decision and the Repository call, for Manager actors. This is
     * freshly re-derived on every call, immediately before the Repository
     * call — but it is still a distinct read from the Repository's own
     * locked transaction, not one atomic unit with it. Authorization,
     * this scoped precondition check, and the mutation are NOT atomic as a
     * whole; a concurrent write in the small window between the check and
     * the Repository's transaction remains possible. This does not weaken
     * correctness: the Repository's own transactional expected_group_id
     * compare-and-swap is unchanged and still the sole authority for
     * whether a write actually applies (a stale read here can at worst
     * route a request into a Repository call that then correctly reports
     * concurrent_update, never an incorrect mutation). It exists purely to
     * close a privacy/enumeration channel, not to provide concurrency
     * correctness — that responsibility remains entirely
     * PGE_Event_Access_Repository's, unchanged.
     *
     * H1B state-machine semantics (no-op/concurrent_update/repair rules) are
     * never re-implemented here — every one of these methods calls straight
     * through to the real, already-tested H1B Repository method and only
     * translates its result/error into the small public contract below.
     *
     * Success shape (never contains PII — phone/name/note/code are never
     * read or returned by any method in this section):
     *   ['ok' => true, 'changed' => bool, 'assignment_id' => ?int,
     *    'group_id' => ?int, 'has_assignment' => bool]
     * — an exact passthrough of PGE_Event_Access_Repository's own
     * assignment_write_result() shape, plus the 'ok' marker.
     *
     * Failure is always a WP_Error with one of a small, fixed set of reason
     * codes (Section 15 of the brief, reviewed against the ACTUAL
     * PGE_Event_Access_Repository error codes rather than invented ahead of
     * time — see map_repository_write_error() below for the exact mapping
     * and the one deliberate collapse it documents):
     *   not_authorized, invalid_input, not_found, ambiguous_guest,
     *   invalid_group, concurrent_update, write_unavailable, database_error.
     *
     * GR1 interaction: none. Guest identity (name/note/code) is never read
     * or written by any method here, and PGE_Event_Access_Repository's
     * assign/move/unassign methods never call pge_event_guests_save_map()
     * or reference PGE_Event_Guest_Read_Projection at all — an assignment
     * mutation is not a guest-identity mutation (Section 25). This class
     * still never calls pge_event_guests_save_map() itself, for the same
     * reason.
     */

    /**
     * Use case: Manager/Owner/Admin performs the INITIAL assignment of an
     * already-invited guest into a group. Per the actual, verified H1C-A2
     * contract, this is Owner/Admin ONLY — PGE_Event_Access_Authorization::
     * can_assign_guest() takes no group parameter and is owner_or_admin_only
     * (initial assignment requires unassigned-pool visibility, which
     * collaborators never have — Section 21 of the A2 brief, re-verified
     * directly against the live can_assign_guest() source and its own A2
     * test suite (A2 tests G3/K5) before this method was written, per
     * Section 2/9 of the H1C-W2 brief's explicit instruction not to assume a
     * Manager permission that does not actually exist in code). A Manager
     * calling this method is always REASON_NOT_AUTHORIZED, regardless of
     * granted scope on the target group.
     *
     * @return array{ok:true,changed:bool,assignment_id:?int,group_id:?int,has_assignment:bool}|WP_Error
     */
    public static function assign_guest_to_group_for_actor($event_id, $actor_user_id, $guest_phone, $group_id)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id)
            || !self::valid_scalar_id($group_id) || !self::valid_guest_phone($guest_phone)) {
            return self::invalid_input();
        }

        $context = PGE_Event_Access_Authorization::resolve_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) return self::not_authorized();

        $decision = PGE_Event_Access_Authorization::can_assign_guest($context);
        if (empty($decision['allowed'])) return self::not_authorized();

        if (!class_exists('PGE_Event_Access_Repository')) return self::guest_data_error();
        $result = PGE_Event_Access_Repository::assign_guest_to_group($event_id, $guest_phone, $group_id, $actor_user_id);
        return self::map_repository_write_result($result);
    }

    /**
     * Use case: move an already-assigned guest from one group to another.
     * $expected_group_id is the caller's asserted CURRENT group (optimistic
     * concurrency — Section 22, never dropped or auto-detected) and is used
     * BOTH as the H1B Repository's concurrency precondition AND as the
     * authorization "source group" per the real, verified can_move_guest()
     * contract, which requires the actor to hold granted access to BOTH the
     * source and the target group (A2 tests G7-G11: a granted G1 does not
     * imply access to any other group, including a same-group no-op move —
     * re-verified directly against the live source before this method was
     * written).
     *
     * @return array{ok:true,changed:bool,assignment_id:?int,group_id:?int,has_assignment:bool}|WP_Error
     */
    public static function move_guest_to_group_for_actor($event_id, $actor_user_id, $guest_phone, $expected_group_id, $new_group_id)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id)
            || !self::valid_scalar_id($expected_group_id) || !self::valid_scalar_id($new_group_id)
            || !self::valid_guest_phone($guest_phone)) {
            return self::invalid_input();
        }

        $context = PGE_Event_Access_Authorization::resolve_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) return self::not_authorized();

        $decision = PGE_Event_Access_Authorization::can_move_guest($context, $expected_group_id, $new_group_id);
        if (empty($decision['allowed'])) return self::not_authorized();

        // Scoped Manager Enumeration/Privacy Fix Pass (Section 7): group-id
        // authorization on the CLAIMED $expected_group_id alone is not
        // sufficient for a Manager — it does not yet prove the guest's
        // ACTUAL current assignment is one this Manager may see. See
        // manager_scoped_current_assignment_check() below for the exact
        // invariant and why Owner/Admin are excluded.
        $scope_check = self::manager_scoped_current_assignment_check($event_id, $context, $guest_phone, $expected_group_id);
        if ($scope_check instanceof WP_Error) return $scope_check;

        if (!class_exists('PGE_Event_Access_Repository')) return self::guest_data_error();
        $result = PGE_Event_Access_Repository::move_guest_to_group($event_id, $guest_phone, $expected_group_id, $new_group_id, $actor_user_id);
        return self::map_repository_write_result($result);
    }

    /**
     * Use case: remove an assigned guest from its current group.
     * $expected_group_id is the caller's asserted CURRENT group (Section
     * 22, required — never optional, never "whichever assignment currently
     * exists") and is used both as the Repository's concurrency
     * precondition and as the authorization "current group" per the real
     * can_unassign_guest() contract.
     *
     * Deleted-guest cleanup (Section 32/33): PGE_Event_Access_Repository::
     * unassign_guest_from_group() deliberately never calls
     * pge_event_guests_resolve_current_by_phone() — a guest already removed
     * from Post Meta can still have its stray H1B assignment row cleaned
     * up. This method preserves that on purpose: authorization here depends
     * only on event/group/membership context (can_unassign_guest() takes a
     * group id, never a guest identity), so cleanup remains possible even
     * when guest identity can no longer be resolved at all (Section 33 —
     * covered by a dedicated W2 test).
     *
     * @return array{ok:true,changed:bool,assignment_id:?int,group_id:?int,has_assignment:bool}|WP_Error
     */
    public static function unassign_guest_from_group_for_actor($event_id, $actor_user_id, $guest_phone, $expected_group_id)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id)
            || !self::valid_scalar_id($expected_group_id) || !self::valid_guest_phone($guest_phone)) {
            return self::invalid_input();
        }

        $context = PGE_Event_Access_Authorization::resolve_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) return self::not_authorized();

        $decision = PGE_Event_Access_Authorization::can_unassign_guest($context, $expected_group_id);
        if (empty($decision['allowed'])) return self::not_authorized();

        // Scoped Manager Enumeration/Privacy Fix Pass (Section 8) — see
        // manager_scoped_current_assignment_check() below. Owner/Admin
        // cleanup semantics (deleted-guest, no-op) are entirely unaffected
        // (the helper is a no-op for them).
        $scope_check = self::manager_scoped_current_assignment_check($event_id, $context, $guest_phone, $expected_group_id);
        if ($scope_check instanceof WP_Error) return $scope_check;

        if (!class_exists('PGE_Event_Access_Repository')) return self::guest_data_error();
        $result = PGE_Event_Access_Repository::unassign_guest_from_group($event_id, $guest_phone, $expected_group_id, $actor_user_id);
        return self::map_repository_write_result($result);
    }

    /**
     * Scoped Manager Enumeration/Privacy Fix Pass.
     *
     * ROOT CAUSE closed by this method: group-id authorization on the
     * CLIENT-SUPPLIED $expected_group_id/$new_group_id alone (can_move_guest()/
     * can_unassign_guest()) only proves the Manager is allowed to operate on
     * those GROUP IDS in the abstract — it says nothing yet about whether
     * the guest phone's ACTUAL current assignment (a fact the Manager does
     * not control) is itself something this Manager may observe. Before
     * this fix, a Manager granted G1+G2 (not G3) could send
     * move(phone=X, expected_group_id=G1, new_group_id=G2) or
     * unassign(phone=X, expected_group_id=G1) for ANY phone X — including
     * one that was never invited, one that is unassigned, or one actually
     * assigned to out-of-scope G3 — and the subsequent H1B Repository call
     * would surface a DIFFERENT public reason for each case (not_found for
     * a non-existent guest via require_current_guest(), ambiguous_guest for
     * a malformed Post Meta identity, concurrent_update for "assignment
     * exists but expected_group_id didn't match" — which fires identically
     * whether the guest is simply unassigned OR is actually sitting in G3).
     * A Manager could therefore use move/unassign as an oracle to learn
     * which phone numbers are invited guests of the event, and whether a
     * specific phone is currently assigned anywhere, entirely outside their
     * granted scope.
     *
     * INVARIANT enforced here: for a Manager (never Owner/Admin — see
     * below), existence-sensitive guest-mutation behavior may only be
     * reached once it is independently proven, from RELATIONAL assignment
     * data alone (PGE_Event_Access_Repository::map_guest_groups() — the
     * same already-tested, zero-Post-Meta-touch primitive the existing
     * Owner/Admin read path already uses; no new Repository method was
     * added, per Section 5's explicit "reuse existing primitives first"),
     * that:
     *   (a) the phone currently has a real assignment in this event, AND
     *   (b) that assignment's actual group_id is granted to this Manager.
     * Both failure sub-cases — "no assignment at all" and "assignment
     * exists but in an ungranted group" — collapse to the SAME
     * REASON_NOT_AUTHORIZED WP_Error (an existing, already-used reason;
     * Section 7/8 explicitly asks for no new taxonomy) and the Repository
     * is never called at all, so neither guest existence nor out-of-scope
     * assignment existence is observable from a Manager's request.
     *
     * If the actual current group IS granted to this Manager but does not
     * equal $expected_group_id, this method still returns true (proceeds
     * to the Repository): that mismatch is a legitimate optimistic-
     * concurrency conflict the Manager already has authorized visibility
     * into (they can see this exact group's assignments through their own
     * granted scope), so letting the Repository's own expected_group_id
     * check surface concurrent_update naturally here is not a new leak.
     *
     * Owner/Admin are event-wide actors (Section 4/17) and are explicitly
     * excluded — this method returns true immediately for them, and their
     * existing H1B behavior (including deleted-guest cleanup, repair
     * semantics, not_found/ambiguous_guest passthrough) is completely
     * unchanged.
     *
     * Not touched, on purpose (Section 6): pge_event_guests_get_map(),
     * pge_event_guests_get_row_payload(), pge_event_guests_save_map(),
     * PGE_Event_Guest_Read_Projection — none of these are read or called by
     * this method. Scope is proven from relational assignment/group data
     * only, never guest Post Meta identity.
     *
     * Untrusted/malformed map_guest_groups() results (Scoped Manager
     * Malformed/Duplicate Assignment Privacy Fix Pass): a prior version of
     * this method treated any non-array/WP_Error result from
     * map_guest_groups() as purely systemic and surfaced it as a distinct
     * guest_data_error — that assumption is too strong and was itself a
     * privacy leak. map_guest_groups() can fail for this exact phone's
     * relational data specifically, not only for genuine connection/query
     * failures. Precisely: normalize_assignment() may return database_error
     * for malformed or missing-group rows (bad ids/timestamps/phone shape,
     * or a group_id referencing no row at all via the LEFT JOIN), while a
     * related group belonging to another event returns the DIFFERENT,
     * distinguishable cross_event code instead — the two are not the same
     * error code. Separately, map_guest_groups() itself also returns
     * database_error when it detects more than one assignment row for this
     * exact phone (a duplicate/corrupt relational state). Both
     * database_error and cross_event are phone-specific relational failure
     * states that must be collapsed for a scoped Manager before in-scope
     * assignment knowledge is established — but for two different reasons:
     * genuine infrastructure/query failures may ALSO surface as
     * database_error, so the Application layer cannot safely distinguish
     * all phone-specific corruption from systemic failure merely by
     * inspecting that particular WP_Error code (and this method must never
     * invent a heuristic based on $wpdb->last_error, message text, or SQL to
     * try); cross_event, by contrast, is always phone-specific and never
     * systemic, but revealing it as a distinct reason would itself be a new
     * observable signal, so it is collapsed for privacy rather than for
     * ambiguity. For a scoped Manager, ANY untrusted or malformed relational
     * result from map_guest_groups() before in-scope assignment is proven
     * (a WP_Error of any code, a non-array return, or a malformed per-row
     * shape) is collapsed into the SAME not_authorized used for "no
     * assignment" and "out-of-scope assignment" above. This intentionally
     * trades a precise infrastructure-failure reason for privacy: a Manager
     * must not be able to learn that a specific phone has a duplicate,
     * malformed, missing-group, or cross-event-corrupted assignment row
     * versus simply having none at all or one outside their scope.
     * Owner/Admin are unaffected (see above — this whole method is a no-op
     * for them, so their existing not_found/ambiguous_guest/database_error/
     * cross_event/repair-semantics passthrough via the real Repository call
     * is untouched).
     *
     * TOCTOU (Section 10): this check and the subsequent Repository call
     * are two separate reads/statements, NOT one atomic transaction — a
     * concurrent write could still change the assignment in the (small)
     * window between this check and the Repository's own locked
     * transaction. This is intentionally NOT a correctness guarantee (the
     * Repository's own expected_group_id compare-and-swap inside its
     * transaction remains the sole correctness/concurrency authority,
     * unchanged and unweakened by this fix); it is a privacy guard that
     * closes an information-disclosure channel. A stale-but-since-changed
     * read here can, at worst, let a request reach the Repository that the
     * Repository then correctly rejects with concurrent_update (no
     * incorrect mutation is possible) — it can never cause an unauthorized
     * mutation to succeed, because the Repository's own transactional
     * expected_group_id check still governs every actual write.
     *
     * @return true|WP_Error
     */
    private static function manager_scoped_current_assignment_check($event_id, $context, $guest_phone, $expected_group_id)
    {
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) return self::not_authorized();

        // Owner/Admin: event-wide actors, no additional scoping needed or
        // applied — existing H1B Repository semantics govern entirely,
        // unchanged (Section 4/17).
        if ($context->is_admin() || $context->is_owner()) return true;

        if (!class_exists('PGE_Event_Access_Repository') || !method_exists('PGE_Event_Access_Repository', 'map_guest_groups')) {
            return self::guest_data_error();
        }

        $rows = PGE_Event_Access_Repository::map_guest_groups($event_id, [$guest_phone]);
        // WP_Error from map_guest_groups() is NOT reliably systemic — it can
        // also mean THIS phone's relational assignment data is corrupt
        // (duplicate rows or a malformed/missing-group row, both reported as
        // database_error; a cross-event group reference reported as the
        // DIFFERENT, distinguishable cross_event code — see the method
        // docblock above for the precise mapping). database_error alone
        // cannot distinguish a genuine infrastructure failure from
        // this-phone-specific corruption, and this method must not inspect
        // $wpdb->last_error/message text/SQL to try; cross_event is always
        // phone-specific but must still be collapsed for privacy, not
        // ambiguity. For a scoped Manager, collapse ANY such untrusted
        // result (any WP_Error code) into the same not_authorized used for
        // "no assignment"/"out-of-scope assignment" — privacy takes
        // priority over a precise infrastructure reason here.
        if ($rows instanceof WP_Error) return self::not_authorized();

        // Any other non-array/malformed return (should not happen given the
        // contract above, but fail closed the same way rather than risk a
        // distinguishable guest_data_error for this exact phone).
        if (!is_array($rows) || $rows === []) return self::not_authorized();

        $actual_group_id = null;
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('group_id', $row) || !is_int($row['group_id'])) {
                // Same reasoning as above: a malformed per-row shape here is
                // this-phone-specific, not systemic — collapse to
                // not_authorized rather than a distinguishable reason.
                return self::not_authorized();
            }
            $actual_group_id = $row['group_id'];
        }
        if ($actual_group_id === null || !$context->has_group_access($actual_group_id)) {
            // Assignment exists, but in a group this Manager was never
            // granted — collapsed into the identical not_authorized used
            // for "no assignment at all" above.
            return self::not_authorized();
        }

        // Actual current group is provably within this Manager's granted
        // scope. Whether or not it equals $expected_group_id is now safe
        // to let the Repository's own concurrency check decide.
        return true;
    }

    /**
     * Translates a real PGE_Event_Access_Repository write result into the
     * small, fixed public write contract. Reviewed against the ACTUAL
     * Repository error codes (class-pge-event-access-repository.php) rather
     * than an invented list:
     *
     *   invalid_input      -> invalid_input
     *   schema_not_ready    -> write_unavailable (storage layer not ready;
     *                          honest, generic, no detail)
     *   not_found           -> not_found. DELIBERATE COLLAPSE: the
     *                          Repository returns the exact same WP_Error
     *                          code/message ('not_found', 'Requested event
     *                          access record was not found.') both when the
     *                          guest phone cannot be resolved
     *                          (require_current_guest()) AND when the
     *                          target/current group is missing or
     *                          cross-event (find_locked_group_by_id() /
     *                          validate_assignment_group_reference()) — the
     *                          two cases are NOT distinguishable from the
     *                          Repository's return value alone. Rather than
     *                          inventing a false distinction (separate
     *                          'guest_not_found' vs 'invalid_group' codes
     *                          the storage layer does not actually
     *                          provide), this mapping stays honest and
     *                          unifies both under one reason, per the
     *                          brief's own instruction (Section 15: "وحّد
     *                          mapping بدل تمرير تفاصيل داخلية").
     *   ambiguous_guest     -> ambiguous_guest
     *   invalid_state       -> invalid_group (this one IS unambiguous: it
     *                          only ever fires for a resolved-but-archived
     *                          target group)
     *   concurrent_update   -> concurrent_update
     *   database_error      -> database_error
     *   anything else       -> database_error (never a raw/unrecognized
     *                          code reaches a caller)
     *
     * @return array{ok:true,changed:bool,assignment_id:?int,group_id:?int,has_assignment:bool}|WP_Error
     */
    private static function map_repository_write_result($result)
    {
        if (is_array($result)) {
            if (!array_key_exists('changed', $result) || !array_key_exists('assignment_id', $result)
                || !array_key_exists('group_id', $result) || !array_key_exists('has_assignment', $result)) {
                return self::guest_data_error();
            }
            return [
                'ok' => true,
                'changed' => (bool) $result['changed'],
                'assignment_id' => $result['assignment_id'],
                'group_id' => $result['group_id'],
                'has_assignment' => (bool) $result['has_assignment'],
            ];
        }

        if (!($result instanceof WP_Error)) return self::guest_data_error();

        $code = (string) $result->get_error_code();
        $map = [
            'invalid_input' => 'invalid_input',
            'schema_not_ready' => 'write_unavailable',
            'not_found' => 'not_found',
            'ambiguous_guest' => 'ambiguous_guest',
            'invalid_state' => 'invalid_group',
            'concurrent_update' => 'concurrent_update',
            'database_error' => 'database_error',
        ];
        $public_code = $map[$code] ?? 'database_error';
        return new WP_Error($public_code, 'Unable to complete the requested guest-assignment change.');
    }

    private static function valid_guest_phone($value): bool
    {
        return is_string($value) && $value !== '';
    }

    // ──────────────────────────────────────────────────────────────
    // Owner/Admin group-access lifecycle writes (Phase H1C-W3)
    // ──────────────────────────────────────────────────────────────

    /**
     * H1C-W3 — Owner/Admin Group-Access Lifecycle Write Wiring.
     *
     * Discovery (see the H1C-W3 report for the full inventory): of the ten
     * remaining H1B Repository write APIs never wired to any Application
     * layer before this phase — create_group/rename_group/archive_group/
     * set_default_group (group lifecycle), create_membership/
     * change_membership_role/revoke_membership/reactivate_membership
     * (membership lifecycle), grant_group_access/revoke_group_access
     * (group-access lifecycle) — grant_group_access/revoke_group_access is
     * the smallest complete vertical slice: two methods, no state machine,
     * no cascading side effects on other domains, and an Authorization
     * contract (PGE_Event_Access_Authorization::can_manage_group_access())
     * that is already fully proven both in code and in the existing A2 test
     * suite (Section D: owner allowed, admin allowed, manager denied,
     * viewer denied — verified directly against source before this file was
     * written, not assumed from memory).
     *
     * Unlike H1C-W2's guest-assignment writes, can_manage_group_access()
     * takes NO group-id parameter at all and is owner_or_admin_only() with
     * zero exception for Manager/Viewer — there is no "partially authorized
     * scoped actor" concept in this domain the way a Manager granted some-
     * but-not-all groups is in guest assignment. A denied actor here (
     * Manager/Viewer/Revoked/Stranger) is rejected by role before any
     * existence-sensitive Repository lookup of membership_id or group_id —
     * i.e. before any Repository call at all. Only syntax/positive-integer
     * validation of membership_id/group_id occurs beforehand (step 1 below),
     * which reveals nothing about whether either id actually exists — so,
     * unlike manager_scoped_current_assignment_check() in the
     * guest-assignment section above, no analogous scoped pre-check helper
     * is needed or added here: the existing can_manage_group_access() gate
     * is already a complete, existence-independent denial for every actor
     * type this method must deny. This is a direct, provable consequence of
     * the Authorization contract, not an assumption.
     *
     * Same fixed order as every other write use case in this class:
     *   1. strict scalar input validation (REASON_INVALID_INPUT);
     *   2. PGE_Event_Access_Authorization::resolve_context() — fresh,
     *      point-in-time, never caller-supplied;
     *   3. PGE_Event_Access_Authorization::can_manage_group_access($context)
     *      — Owner/Admin only, no group-scoping parameter;
     *   4. only on an explicit allow, the matching H1B Repository mutation
     *      method — Repository is never invoked when the decision is deny.
     *
     * H1B state-machine semantics (idempotent grant no-op, revoke-absent
     * no-op, concurrent_update on a raced delete, invalid_state on an
     * archived group or revoked membership) are never re-implemented here —
     * both methods below call straight through to the real, already-tested
     * H1B Repository method and only translate its result/error into the
     * small public contract (see map_group_access_write_result() below).
     *
     * actor_user_id is an explicit parameter here too, exactly like H1C-W2
     * — the AJAX transport (event-access-ajax.php) is the only caller that
     * reads get_current_user_id(); this class never reads $_POST, a nonce,
     * or any session state directly.
     *
     * GR1 interaction: none. Group-access grants/revokes never touch guest
     * identity (name/note/code) or PGE_Event_Guest_Read_Projection at all.
     *
     * Success shape (never contains PII):
     *   ['ok' => true, 'changed' => bool, 'membership_id' => int,
     *    'group_id' => int, 'has_access' => bool]
     * — an exact passthrough of PGE_Event_Access_Repository's own
     * grant_group_access()/revoke_group_access() result shape, plus the
     * 'ok' marker.
     */

    /**
     * @return array{ok:true,changed:bool,membership_id:int,group_id:int,has_access:bool}|WP_Error
     */
    public static function grant_group_access_for_actor($event_id, $actor_user_id, $membership_id, $group_id)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id)
            || !self::valid_scalar_id($membership_id) || !self::valid_scalar_id($group_id)) {
            return self::invalid_input();
        }

        $context = PGE_Event_Access_Authorization::resolve_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) return self::not_authorized();

        $decision = PGE_Event_Access_Authorization::can_manage_group_access($context);
        if (empty($decision['allowed'])) return self::not_authorized();

        if (!class_exists('PGE_Event_Access_Repository')) return self::guest_data_error();
        $result = PGE_Event_Access_Repository::grant_group_access($event_id, $membership_id, $group_id, $actor_user_id);
        return self::map_group_access_write_result($result);
    }

    /**
     * @return array{ok:true,changed:bool,membership_id:int,group_id:int,has_access:bool}|WP_Error
     */
    public static function revoke_group_access_for_actor($event_id, $actor_user_id, $membership_id, $group_id)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id)
            || !self::valid_scalar_id($membership_id) || !self::valid_scalar_id($group_id)) {
            return self::invalid_input();
        }

        $context = PGE_Event_Access_Authorization::resolve_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) return self::not_authorized();

        $decision = PGE_Event_Access_Authorization::can_manage_group_access($context);
        if (empty($decision['allowed'])) return self::not_authorized();

        if (!class_exists('PGE_Event_Access_Repository')) return self::guest_data_error();
        $result = PGE_Event_Access_Repository::revoke_group_access($event_id, $membership_id, $group_id, $actor_user_id);
        return self::map_group_access_write_result($result);
    }

    /**
     * Translates a real PGE_Event_Access_Repository group-access write
     * result into a small, fixed public contract — the group-access
     * counterpart of map_repository_write_result() above (kept separate on
     * purpose: the result/error shapes differ — membership_id/has_access
     * here vs assignment_id/has_assignment there — and grant_group_access()/
     * revoke_group_access() never return ambiguous_guest, so merging the two
     * mappings would either invent a code that can't occur here or silently
     * drop one that can).
     *
     * Reviewed against the ACTUAL grant_group_access()/revoke_group_access()
     * source (class-pge-event-access-repository.php) rather than an assumed
     * list:
     *
     *   invalid_input     -> invalid_input
     *   schema_not_ready   -> write_unavailable (storage layer not ready)
     *   not_found          -> not_found. Fires for: the group missing/
     *                         archived-table-absent, the membership missing,
     *                         a cross-event group/membership id (lock_groups()/
     *                         lock_memberships() scope strictly by event_id,
     *                         so a foreign id is simply absent from the
     *                         locked set — never a distinguishable code), or
     *                         (grant only) the membership's underlying WP
     *                         user no longer existing. Not split into
     *                         separate codes: the Repository does not
     *                         distinguish them either.
     *   invalid_state      -> invalid_state (grant only: the group is
     *                         archived OR the membership is revoked/
     *                         inactive — collapsed into one reason exactly
     *                         as the Repository itself does not distinguish
     *                         which side was invalid)
     *   concurrent_update  -> concurrent_update (revoke only: the access
     *                         relation vanished between the locking read and
     *                         the delete)
     *   database_error     -> database_error
     *   anything else      -> database_error (never a raw/unrecognized code
     *                         reaches a caller)
     *
     * @return array{ok:true,changed:bool,membership_id:int,group_id:int,has_access:bool}|WP_Error
     */
    private static function map_group_access_write_result($result)
    {
        if (is_array($result)) {
            if (!array_key_exists('changed', $result) || !array_key_exists('membership_id', $result)
                || !array_key_exists('group_id', $result) || !array_key_exists('has_access', $result)) {
                return self::guest_data_error();
            }
            return [
                'ok' => true,
                'changed' => (bool) $result['changed'],
                'membership_id' => $result['membership_id'],
                'group_id' => $result['group_id'],
                'has_access' => (bool) $result['has_access'],
            ];
        }

        if (!($result instanceof WP_Error)) return self::guest_data_error();

        $code = (string) $result->get_error_code();
        $map = [
            'invalid_input' => 'invalid_input',
            'schema_not_ready' => 'write_unavailable',
            'not_found' => 'not_found',
            'invalid_state' => 'invalid_state',
            'concurrent_update' => 'concurrent_update',
            'database_error' => 'database_error',
        ];
        $public_code = $map[$code] ?? 'database_error';
        return new WP_Error($public_code, 'Unable to complete the requested group-access change.');
    }

    // ──────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────

    private static function page_result(array $items, int $page, int $per_page, int $total, int $total_pages): array
    {
        return [
            'items' => $items,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
        ];
    }

    private static function validate_pagination($page, $per_page)
    {
        if ($per_page === null) {
            $per_page = class_exists('PGE_Event_Access_Repository')
                ? PGE_Event_Access_Repository::DEFAULT_PER_PAGE
                : 20;
        }
        $max_per_page = class_exists('PGE_Event_Access_Repository') ? PGE_Event_Access_Repository::MAX_PER_PAGE : 100;
        if (!self::valid_scalar_id($page) || !self::valid_scalar_id($per_page) || $per_page > $max_per_page) {
            return self::invalid_input();
        }
        return ['page' => $page, 'per_page' => $per_page];
    }

    private static function valid_scalar_id($value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function not_authorized()
    {
        return new WP_Error(self::REASON_NOT_AUTHORIZED, 'Actor is not authorized to read this event\'s guests.');
    }

    private static function invalid_input()
    {
        return new WP_Error(self::REASON_INVALID_INPUT, 'Invalid application service input.');
    }

    private static function guest_data_error()
    {
        return new WP_Error(self::REASON_GUEST_DATA_ERROR, 'Unable to safely resolve guest data for this authorized read.');
    }
}
