<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * H1C-W8 — Additional Inviter Quota & Scoped Guest Creation (Backend)
 * ============================================================================
 * Product Contract (binding for this phase, see the H1C-W8 brief): an
 * Additional Inviter is a real WordPress user with an active H1C Manager
 * membership, exactly ONE granted group, and one allocated_quota on that
 * membership. Owner/Admin alone create/configure Additional Inviters; a
 * Manager can only self-service create guests scoped to their own single
 * granted group, bounded by their own live-computed quota.
 *
 * This class is the Application-layer orchestrator sitting between AJAX
 * (includes/additional-inviter-ajax.php) and three existing subsystems it
 * never duplicates: PGE_Event_Access_Authorization/Repository (H1C),
 * PGE_Invitation_Service/Repository (guest creation), and the guest/RSVP
 * read helpers in event-guests.php. It performs no SQL of its own, holds
 * no Post Meta write path of its own, and re-implements no capability
 * decision — every actual mutation and every actual read is delegated to
 * an existing, unmodified (or, for the two narrow Repository/Service
 * additions this phase makes, minimally extended) primitive.
 *
 * Not HTTP-aware: every method takes $actor_user_id explicitly, exactly
 * like PGE_Event_Access_Application_Service, which this class deliberately
 * does NOT reach into (its EC1 pre-authority helper is private) — instead
 * this class re-derives the identical, documented EC1 collapse pattern
 * locally via resolve_actor_context() below, calling the same public
 * PGE_Event_Access_Authorization::resolve_context() entry point every
 * other H1C Application-layer surface already uses.
 */
final class PGE_Additional_Inviter
{
    const REASON_NOT_AUTHORIZED = 'not_authorized';
    const REASON_INVALID_INPUT = 'invalid_input';
    const REASON_MISCONFIGURED = 'misconfigured';
    const REASON_GUEST_DATA_ERROR = 'guest_data_error';

    /** Safety cap for the group-assignment scan below — see resolve_quota_status(). */
    const MAX_ASSIGNMENT_PAGES = 50;
    const ASSIGNMENT_PAGE_SIZE = 100;

    // ──────────────────────────────────────────────────────────────
    // Owner/Admin: create an Additional Inviter atomically
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{ok:true,changed:bool,membership_id:int,user_id:int,role:string,status:string,allocated_quota:int,group_id:int}|WP_Error
     */
    public static function create_additional_inviter_for_actor($event_id, $actor_user_id, $target_user_id, $group_id, $allocated_quota)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id)
            || !self::valid_scalar_id($target_user_id) || !self::valid_scalar_id($group_id)
            || !self::valid_scalar_id($allocated_quota)) {
            return self::invalid_input();
        }

        $context = self::resolve_actor_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;

        $decision = PGE_Event_Access_Authorization::can_manage_membership($context);
        if (empty($decision['allowed'])) return self::not_authorized();

        if (!class_exists('PGE_Event_Access_Repository')) return self::guest_data_error();
        $result = PGE_Event_Access_Repository::create_additional_inviter_membership(
            $event_id, $target_user_id, $group_id, $allocated_quota, $actor_user_id
        );
        return self::map_create_inviter_result($result);
    }

    private static function map_create_inviter_result($result)
    {
        if (is_array($result)) {
            if (!array_key_exists('changed', $result) || !array_key_exists('membership', $result)
                || !is_array($result['membership']) || !array_key_exists('group_id', $result)) {
                return self::guest_data_error();
            }
            $membership = $result['membership'];
            foreach (['id', 'user_id', 'role', 'status', 'allocated_quota'] as $field) {
                if (!array_key_exists($field, $membership)) return self::guest_data_error();
            }
            return [
                'ok' => true,
                'changed' => (bool) $result['changed'],
                'membership_id' => $membership['id'],
                'user_id' => $membership['user_id'],
                'role' => $membership['role'],
                'status' => $membership['status'],
                'allocated_quota' => $membership['allocated_quota'],
                'group_id' => $result['group_id'],
            ];
        }

        if (!($result instanceof WP_Error)) return self::guest_data_error();

        $code = (string) $result->get_error_code();
        $map = [
            'invalid_input' => 'invalid_input',
            'schema_not_ready' => 'write_unavailable',
            'not_found' => 'not_found',
            'invalid_state' => 'invalid_state',
            'duplicate_membership' => 'duplicate',
            'quota_group_conflict' => 'quota_group_conflict',
            'database_error' => 'database_error',
        ];
        $public_code = $map[$code] ?? 'database_error';
        $message = $code === 'quota_group_conflict'
            ? 'This group already has an active Additional Inviter.'
            : 'Unable to create the Additional Inviter.';
        return new WP_Error($public_code, $message);
    }

    // ──────────────────────────────────────────────────────────────
    // Owner/Admin: change an existing Additional Inviter's quota
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{ok:true,changed:bool,membership_id:int,user_id:int,role:string,status:string,allocated_quota:int|null}|WP_Error
     */
    public static function set_additional_inviter_quota_for_actor($event_id, $actor_user_id, $membership_id, $expected_quota, $new_quota)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id) || !self::valid_scalar_id($membership_id)) {
            return self::invalid_input();
        }
        if ($expected_quota !== null && !self::valid_scalar_id($expected_quota)) return self::invalid_input();
        if ($new_quota !== null && !self::valid_scalar_id($new_quota)) return self::invalid_input();

        $context = self::resolve_actor_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;

        $decision = PGE_Event_Access_Authorization::can_manage_membership($context);
        if (empty($decision['allowed'])) return self::not_authorized();

        if (!class_exists('PGE_Event_Access_Repository')) return self::guest_data_error();
        $result = PGE_Event_Access_Repository::set_membership_quota(
            $event_id, $membership_id, $expected_quota, $new_quota, $actor_user_id
        );
        return self::map_set_quota_result($result);
    }

    private static function map_set_quota_result($result)
    {
        if (is_array($result)) {
            if (!array_key_exists('changed', $result) || !array_key_exists('membership', $result) || !is_array($result['membership'])) {
                return self::guest_data_error();
            }
            $membership = $result['membership'];
            foreach (['id', 'user_id', 'role', 'status', 'allocated_quota'] as $field) {
                if (!array_key_exists($field, $membership)) return self::guest_data_error();
            }
            return [
                'ok' => true,
                'changed' => (bool) $result['changed'],
                'membership_id' => $membership['id'],
                'user_id' => $membership['user_id'],
                'role' => $membership['role'],
                'status' => $membership['status'],
                'allocated_quota' => $membership['allocated_quota'],
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
            'quota_group_conflict' => 'quota_group_conflict',
            'database_error' => 'database_error',
        ];
        $public_code = $map[$code] ?? 'database_error';
        $message = $code === 'quota_group_conflict'
            ? 'This group already has an active Additional Inviter.'
            : 'Unable to change the Additional Inviter quota.';
        return new WP_Error($public_code, $message);
    }

    // ──────────────────────────────────────────────────────────────
    // Quota status reads (Owner/Admin explicit target; Manager self-read)
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{allocated:int,occupied:int,available:int,attending:int,pending:int,declined:int,cancelled:int,group_id:int,group_name:?string,membership_id:int,user_id:int,membership_status:string}|WP_Error
     */
    public static function get_quota_status_for_owner($event_id, $actor_user_id, $membership_id)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id) || !self::valid_scalar_id($membership_id)) {
            return self::invalid_input();
        }

        $context = self::resolve_actor_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;

        $decision = PGE_Event_Access_Authorization::can_manage_membership($context);
        if (empty($decision['allowed'])) return self::not_authorized();

        return self::resolve_quota_status($event_id, $membership_id);
    }

    /**
     * @return array{allocated:int,occupied:int,available:int,attending:int,pending:int,declined:int,cancelled:int,group_id:int,group_name:?string,membership_id:int,user_id:int,membership_status:string}|WP_Error
     */
    public static function get_quota_status_for_self($event_id, $actor_user_id)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id)) {
            return self::invalid_input();
        }

        $context = self::resolve_actor_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;

        // A plain H1C Manager who is simply not an Additional Inviter (no
        // quota configured) gets the same not_authorized any other denied
        // actor gets here — this is not the Section 11.1 misconfiguration
        // case (which is reserved for a QUOTA-CONFIGURED membership whose
        // group count is wrong), it is "this feature does not apply to
        // you", answered identically to any other authority denial so it
        // cannot be used to distinguish the two states from outside.
        if (!$context->is_active_collaborator('manager') || $context->allocated_quota() === null) {
            return self::not_authorized();
        }

        return self::resolve_quota_status($event_id, $context->membership_id());
    }

    // ──────────────────────────────────────────────────────────────
    // The central W8 operation: Manager self-service scoped guest creation
    // ──────────────────────────────────────────────────────────────

    /**
     * Reuses PGE_Invitation_Service (guest creation choke point, unmodified
     * except for the new create_for_additional_inviter() wrapper added
     * alongside its existing create()) and PGE_Event_Access_Repository::
     * assign_guest_to_group() (existing H1C assignment primitive, entirely
     * unmodified) — no second guest-creation implementation, no new
     * Post Meta write path, no new H1C SQL write path.
     *
     * Atomicity — stated honestly, per the H1C-W8 brief's explicit
     * requirement: guest creation (Post Meta, via PGE_Invitation_Repository
     * ::create()) and group assignment (relational SQL, via
     * PGE_Event_Access_Repository::assign_guest_to_group()) are NOT one
     * shared database transaction — they cannot be, since Post Meta and
     * this plugin's own SQL tables are different storage systems with no
     * shared transaction boundary. What IS guaranteed:
     *   1. The Additional-Inviter quota re-check and the package/event
     *      guest_limit re-check both happen live, inside the SAME MySQL
     *      GET_LOCK per-event lock that already serializes every guest
     *      lifecycle write for this event (PGE_Invitation_Service::
     *      with_invitation_lifecycle_lock()) — so two concurrent scoped
     *      creations for the same or different inviters in the same event
     *      can never both observe "quota available" when only one slot
     *      remains (Sections 13/16).
     *   2. If guest creation succeeds but the subsequent H1C assignment
     *      fails, this method does NOT report success. It attempts a
     *      compensating delete via the EXISTING, already-production
     *      PGE_Invitation_Repository::delete() (the same hard-delete path
     *      used elsewhere in the plugin, not an invented one-off). If that
     *      compensation also succeeds, the guest is left in the same state
     *      as if creation never ran. If the compensation itself fails, the
     *      guest remains created-but-unassigned; this is never silently
     *      hidden — it is a real, pre-existing, recoverable state Owner/
     *      Admin can already resolve today, because Owner/Admin's existing
     *      "unassigned guests" visibility (PGE_Event_Access_Authorization::
     *      can_view_unassigned_guests()) was ALREADY Owner/Admin-only
     *      before this phase — nothing new is exposed by this fallback, and
     *      the failure is logged server-side for follow-up. The caller is
     *      always told the operation failed either way; "guest created,
     *      assignment failed" is never reported as success.
     */
    public static function create_scoped_guest_for_actor($event_id, $actor_user_id, $phone, $name, $note)
    {
        if (!self::valid_scalar_id($event_id) || !self::valid_scalar_id($actor_user_id)) {
            return self::invalid_input();
        }

        $context = self::resolve_actor_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;

        $decision = PGE_Event_Access_Authorization::can_create_scoped_guest($context);
        if (empty($decision['allowed'])) return self::not_authorized();

        if ($context->allocated_quota() === null) return self::not_authorized();

        $membership_id = $context->membership_id();
        $granted = $context->granted_group_ids();
        if (count($granted) !== 1) return self::misconfigured();
        $expected_group_id = $granted[0];

        if (!class_exists('PGE_Invitation_Service') || !class_exists('PGE_Event_Access_Repository')
            || !class_exists('PGE_Invitation_Repository')) {
            return self::guest_data_error();
        }

        // Re-derived fresh (not from the pre-lock $context above) INSIDE the
        // per-event lock, so a membership revoked, a quota changed, or a
        // group re-granted in the window between authorization and the
        // lock is caught here rather than trusted from the stale snapshot
        // — the same "revalidate immediately before the mutation" TOCTOU
        // discipline PGE_Event_Access_Application_Service already uses for
        // move_guest/unassign_guest.
        $inviter_quota_check = function () use ($event_id, $membership_id, $expected_group_id) {
            $status = self::resolve_quota_status($event_id, $membership_id);
            if ($status instanceof WP_Error) {
                return ['result' => 'error', 'reason' => 'inviter_quota_unavailable'];
            }
            if ($status['membership_status'] !== 'active') {
                return ['result' => 'error', 'reason' => 'not_authorized'];
            }
            if ($status['group_id'] !== $expected_group_id) {
                return ['result' => 'error', 'reason' => 'inviter_quota_unavailable'];
            }
            if ($status['available'] <= 0) {
                return ['result' => 'quota_exceeded', 'reason' => 'inviter_limit_reached'];
            }
            return ['result' => 'ok'];
        };

        $create_result = PGE_Invitation_Service::create_for_additional_inviter(
            $event_id, $phone, $name, $note, $actor_user_id, $inviter_quota_check
        );

        if (($create_result['result'] ?? '') !== 'created') {
            return self::map_scoped_create_error($create_result);
        }

        $created_phone = $create_result['phone'];

        $assign_result = PGE_Event_Access_Repository::assign_guest_to_group($event_id, $created_phone, $expected_group_id, $actor_user_id);
        $assigned = is_array($assign_result) && !empty($assign_result['changed']) && ($assign_result['has_assignment'] ?? false) === true;

        if (!$assigned) {
            $delete_result = PGE_Invitation_Repository::delete($event_id, $created_phone);
            $compensated = (($delete_result['result'] ?? '') === 'deleted');
            if (function_exists('error_log')) {
                error_log(sprintf(
                    'PGE H1C-W8: scoped guest creation assignment failed after guest create (event_id=%d membership_id=%d compensated=%s)',
                    $event_id,
                    $membership_id,
                    $compensated ? 'yes' : 'no'
                ));
            }
            return new WP_Error('server_error', 'Unable to complete guest creation.');
        }

        return [
            'ok' => true,
            'phone' => $created_phone,
            'group_id' => $expected_group_id,
        ];
    }

    private static function map_scoped_create_error($result)
    {
        $code = is_array($result) ? (string) ($result['result'] ?? '') : '';
        $reason = is_array($result) ? (string) ($result['reason'] ?? '') : '';

        if ($code === 'duplicate') {
            // Section 6: never reveal the existing guest's name/group/other
            // inviter — the same generic conflict message regardless of
            // which group the phone actually belongs to.
            return new WP_Error('duplicate_guest', 'هذا الرقم مضاف مسبقاً لهذه المناسبة.');
        }
        if ($code === 'quota_exceeded' && $reason === 'inviter_limit_reached') {
            return new WP_Error('quota_exceeded', 'تم بلوغ الحد الأقصى لعدد الضيوف المتاح لك.');
        }
        if ($code === 'quota_exceeded' && $reason === 'guest_limit_reached') {
            return new WP_Error('package_quota_exceeded', 'تم بلوغ الحد الأقصى لعدد ضيوف هذه المناسبة.');
        }
        if ($reason === 'not_authorized') {
            return self::not_authorized();
        }
        if ($reason === 'invalid_phone' || $reason === 'invalid_name') {
            return self::invalid_input();
        }
        if ($reason === 'lock_not_acquired') {
            return new WP_Error('write_unavailable', 'الخدمة غير متاحة حالياً، يرجى المحاولة لاحقاً.');
        }

        return new WP_Error('server_error', 'تعذّر تنفيذ العملية حالياً.');
    }

    // ──────────────────────────────────────────────────────────────
    // Live quota computation — no persisted counters (Product Contract
    // Section 2/3: "Do NOT store derived occupied counters. Always
    // calculate from authoritative current data.")
    // ──────────────────────────────────────────────────────────────

    /**
     * Section 11.1 (Multiple Group Defensive Handling) is enforced here for
     * every caller (Owner read, self read, and the scoped-create re-check
     * above all funnel through this one method): a quota-configured
     * membership that does not resolve to exactly one active granted group
     * fails closed with misconfigured() rather than guessing which group to
     * use — this never alters generic H1C Manager behavior used elsewhere,
     * it only ever applies to this Additional-Inviter-specific read.
     *
     * @return array{allocated:int,occupied:int,available:int,attending:int,pending:int,declined:int,cancelled:int,group_id:int,group_name:?string,membership_id:int,user_id:int,membership_status:string}|WP_Error
     */
    private static function resolve_quota_status($event_id, $membership_id)
    {
        if (!class_exists('PGE_Event_Access_Repository')) return self::guest_data_error();

        $membership = PGE_Event_Access_Repository::get_membership($event_id, $membership_id);
        if ($membership instanceof WP_Error) return $membership;
        if (!is_array($membership) || ($membership['role'] ?? null) !== 'manager' || ($membership['allocated_quota'] ?? null) === null) {
            return self::misconfigured();
        }

        $group_ids = PGE_Event_Access_Repository::list_group_ids_for_membership($event_id, $membership_id);
        if ($group_ids instanceof WP_Error) return $group_ids;
        if (!is_array($group_ids) || count($group_ids) !== 1) return self::misconfigured();
        $group_id = $group_ids[0];

        // Defensive corrupt-data guard (Fix Pass, Section 9): the exclusivity
        // invariant enforced at write time (PGE_Event_Access_Repository::
        // ensure_no_active_quota_inviter_for_group()) should make this
        // unreachable in normal operation, but if legacy/corrupt data ever
        // left more than one OTHER active, quota-enabled manager membership
        // bound to this same group, this read path must fail closed rather
        // than silently pick one of them or attempt to repair the data.
        $other_membership_ids = PGE_Event_Access_Repository::list_membership_ids_for_group($event_id, $group_id);
        if ($other_membership_ids instanceof WP_Error) return $other_membership_ids;
        if (is_array($other_membership_ids)) {
            foreach ($other_membership_ids as $other_id) {
                if ((int) $other_id === (int) $membership_id) continue;
                $other = PGE_Event_Access_Repository::get_membership($event_id, $other_id);
                if ($other instanceof WP_Error) continue;
                if (
                    is_array($other)
                    && ($other['status'] ?? null) === 'active'
                    && ($other['role'] ?? null) === 'manager'
                    && ($other['allocated_quota'] ?? null) !== null
                ) {
                    return self::misconfigured();
                }
            }
        }

        $group = PGE_Event_Access_Repository::get_group($event_id, $group_id);
        if ($group instanceof WP_Error) return $group;

        $phones = self::collect_group_guest_phones($event_id, $group_id);
        if ($phones instanceof WP_Error) return $phones;

        $buckets = self::classify_guest_phones($event_id, $phones);

        $allocated = $membership['allocated_quota'];
        $occupied = $buckets['attending'] + $buckets['pending'];
        $available = max(0, $allocated - $occupied);

        return [
            'allocated' => $allocated,
            'occupied' => $occupied,
            'available' => $available,
            'attending' => $buckets['attending'],
            'pending' => $buckets['pending'],
            'declined' => $buckets['declined'],
            'cancelled' => $buckets['cancelled'],
            'group_id' => $group_id,
            'group_name' => is_array($group) ? ($group['name'] ?? null) : null,
            'membership_id' => $membership['id'],
            'user_id' => $membership['user_id'],
            'membership_status' => $membership['status'],
        ];
    }

    /**
     * Loops PGE_Event_Access_Repository::list_group_assignments() (the
     * existing, unmodified, paginated H1C read) rather than any new bulk
     * query. Bounded by MAX_ASSIGNMENT_PAGES as an explicit safety cap — if
     * a single Additional Inviter's group somehow exceeds it, this fails
     * closed (misconfigured) rather than silently under-counting occupied
     * slots, which would otherwise let quota enforcement be bypassed by
     * sheer group size.
     *
     * @return string[]|WP_Error
     */
    private static function collect_group_guest_phones($event_id, $group_id)
    {
        $phones = [];
        $page = 1;
        $total_pages = 1;
        do {
            $result = PGE_Event_Access_Repository::list_group_assignments($event_id, $group_id, $page, self::ASSIGNMENT_PAGE_SIZE);
            if ($result instanceof WP_Error) return $result;
            if (!is_array($result) || !isset($result['items']) || !is_array($result['items'])) return self::guest_data_error();
            foreach ($result['items'] as $item) {
                if (is_array($item) && isset($item['guest_phone']) && is_string($item['guest_phone'])) {
                    $phones[] = $item['guest_phone'];
                }
            }
            $total_pages = (int) ($result['total_pages'] ?? 0);
            $page++;
        } while ($page <= $total_pages && $page <= self::MAX_ASSIGNMENT_PAGES);

        if ($page <= $total_pages) {
            return self::misconfigured();
        }
        return $phones;
    }

    /**
     * Classifies each assigned phone using the SAME authoritative read
     * PGE_Invitation_Repository already exposes (get_invitation() ->
     * build_row(), which itself already computes rsvp_status from
     * wp_pge_event_rsvps and invitation_status from the invitation status
     * map) — no parallel RSVP/status computation is introduced. A phone
     * that no longer resolves to a guest at all (get_invitation() returns
     * null — an orphaned H1B assignment row left behind by the existing,
     * pre-existing, unrelated PGE_Invitation_Repository::delete() "no
     * cascade" behavior documented in that method) is excluded from every
     * bucket: it occupies no slot, since the guest it would have occupied
     * a slot for no longer exists. This is a known pre-existing limitation
     * of the wider system (Post Meta deletion never cleans up H1C
     * assignment rows), not something this phase introduces or is asked to
     * fix — see the final report's "Remaining Known Limits".
     *
     * @param string[] $phones
     * @return array{attending:int,pending:int,declined:int,cancelled:int}
     */
    private static function classify_guest_phones($event_id, array $phones)
    {
        $buckets = ['attending' => 0, 'pending' => 0, 'declined' => 0, 'cancelled' => 0];
        if (!class_exists('PGE_Invitation_Repository')) return $buckets;

        foreach ($phones as $phone) {
            $invitation = PGE_Invitation_Repository::get_invitation($event_id, $phone);
            if ($invitation === null) continue;

            if (($invitation['invitation_status'] ?? '') === 'cancelled') {
                $buckets['cancelled']++;
                continue;
            }
            $rsvp = $invitation['rsvp_status'] ?? 'pending';
            if ($rsvp === 'no') {
                $buckets['declined']++;
                continue;
            }
            if ($rsvp === 'yes') {
                $buckets['attending']++;
                continue;
            }
            $buckets['pending']++;
        }

        return $buckets;
    }

    // ──────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Local EC1-equivalent pre-authority collapse. PGE_Event_Access_
     * Application_Service::resolve_event_actor_context() (the class this
     * pattern originates in) is private, so it cannot be called from here;
     * this reproduces the identical, documented contract via the same
     * PUBLIC PGE_Event_Access_Authorization::resolve_context() entry point
     * every other H1C Application-layer surface already uses — any
     * WP_Error out of resolve_context() (nonexistent event, database
     * error, schema not ready — anything reached before a capability
     * decision) collapses to the same not_authorized() an actually-denied
     * real actor gets, so this surface cannot be used as an event-id or
     * actor-existence oracle either.
     *
     * @return PGE_Event_Access_Authorization_Context|WP_Error
     */
    private static function resolve_actor_context($event_id, $actor_user_id)
    {
        if (!class_exists('PGE_Event_Access_Authorization')) return self::guest_data_error();
        $context = PGE_Event_Access_Authorization::resolve_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return self::not_authorized();
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) return self::not_authorized();
        return $context;
    }

    private static function valid_scalar_id($value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function not_authorized()
    {
        return new WP_Error(self::REASON_NOT_AUTHORIZED, 'Actor is not authorized to perform this Additional Inviter action.');
    }

    private static function invalid_input()
    {
        return new WP_Error(self::REASON_INVALID_INPUT, 'Invalid Additional Inviter request input.');
    }

    private static function misconfigured()
    {
        return new WP_Error(self::REASON_MISCONFIGURED, 'This Additional Inviter membership is not in a valid configuration.');
    }

    private static function guest_data_error()
    {
        return new WP_Error(self::REASON_GUEST_DATA_ERROR, 'Unable to safely resolve Additional Inviter data.');
    }
}
