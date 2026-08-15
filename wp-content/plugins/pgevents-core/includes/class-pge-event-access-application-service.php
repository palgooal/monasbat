<?php
if (!defined('ABSPATH')) exit;

/**
 * H1C-W1 — Collaborator Read-Only Application Wiring.
 *
 * The first production Application-layer consumer of the H1B Authorization
 * Core (PGE_Event_Access_Authorization / PGE_Event_Access_Authorization_Context)
 * and Repository (PGE_Event_Access_Repository). Read-only: this class calls
 * no write/mutation method on either dependency (create_group/rename_group/
 * archive_group/set_default_group/create_membership/change_membership_role/
 * revoke_membership/reactivate_membership/grant_group_access/
 * revoke_group_access/assign_guest_to_group/move_guest_to_group/
 * unassign_guest_from_group are never referenced here).
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
 * Guest storage boundary (Section 9/23 of the H1C-W1 brief — read this
 * before changing the PII-loading order below): guest identity data
 * (name/note/code) lives in a single WordPress Post Meta value per event
 * (`_pge_invited_guests`), not in a queryable table — get_post_meta() always
 * deserializes the entire stored value for that meta key; there is no
 * WordPress or plugin primitive that can fetch a subset of it without first
 * loading the whole blob into PHP memory. pge_event_guests_get_map($event_id)
 * is the one function in this codebase that performs that load (already
 * relied on by PGE_Invitation_Repository and, for a single-phone lookup,
 * PGE_Guest_Resolution_Service::load_guest_meta()) — there is no safer
 * existing alternative to reuse. Given that constraint, this class:
 *   (a) always resolves H1B scope FIRST (resolve_context() + a real,
 *       DB-paginated, zero-Post-Meta-touch scoped assignment read for
 *       collaborators) and only calls pge_event_guests_get_map() AFTER
 *       scope is known to be non-empty (Section 23 — proven in the test
 *       suite by instrumenting get_post_meta() call order/count);
 *   (b) for a collaborator, only ever extracts the handful of phones
 *       (bounded by $per_page, at most PGE_Event_Access_Repository::
 *       MAX_PER_PAGE = 100) that were already proven authorized by the
 *       scoped assignment read — every other entry in the loaded map is
 *       discarded immediately and never enters any return value;
 *   (c) never calls get_post_meta() at all when a collaborator has zero
 *       granted groups or zero assignments on the requested page (Section
 *       22 — list_scoped_group_assignments() itself already short-circuits
 *       to an empty result without issuing even a group-assignment query in
 *       the zero-grant case).
 * This is a known, documented limitation of the current guest storage
 * design, not a gap introduced by this phase — see the final report's
 * "Known Limits" section for the full statement.
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
     * H1C-W1D finding (Storage/PII scoping = NO — see that report):
     * pge_event_guests_get_map() below is the only guest-store primitive
     * available, and it necessarily deserializes the *entire* per-event
     * _pge_invited_guests blob into PHP memory in one call — there is no
     * narrower fetch. What IS true, and is what this scoped read actually
     * guarantees: that full-blob load only happens AFTER the scoped
     * assignment read above has completed (never before, and never at all
     * in the zero-grant case), and only the phones already proven
     * authorized by that scoped read are ever extracted from the loaded
     * map into a projected item. Every other entry the blob load put into
     * memory is discarded and never reaches project_guest_fields() or any
     * return value. This is "scope-before-load with scoped projection over
     * a full serialized guest blob", not a scoped storage-layer fetch —
     * documented technical debt, see Phase H1C-GR1 (Relational Guest Read
     * Projection).
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

        if (!function_exists('pge_event_guests_get_map')
            || !function_exists('pge_event_guests_get_row_payload')) {
            return self::guest_data_error();
        }

        $event_id = $context->event_id();
        $map = pge_event_guests_get_map($event_id);
        if (!is_array($map)) return self::guest_data_error();

        $items = [];
        foreach ($scoped['items'] as $assignment) {
            if (!is_array($assignment) || !isset($assignment['guest_phone'], $assignment['group_id'])) {
                return self::guest_data_error();
            }
            $phone = $assignment['guest_phone'];
            $guest_record = $map[$phone] ?? null;

            // Missing or malformed guest record for an assignment the actor
            // was already proven authorized to see: fail the entire scoped
            // read closed (Section 13/29) rather than silently drop this
            // one row, which would otherwise make total/total_pages drift
            // out of sync with the actually-returned item count without
            // any signal to the caller.
            if (!is_array($guest_record) || !array_key_exists('phone', $guest_record)) {
                return self::guest_data_error();
            }

            $row_payload = pge_event_guests_get_row_payload($event_id, $guest_record);
            $items[] = [
                'guest' => PGE_Event_Access_Authorization::project_guest_fields($context, $row_payload),
                'group_id' => (int) $assignment['group_id'],
            ];
        }

        return self::page_result($items, (int) $scoped['page'], (int) $scoped['per_page'], (int) $scoped['total'], (int) $scoped['total_pages']);
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
