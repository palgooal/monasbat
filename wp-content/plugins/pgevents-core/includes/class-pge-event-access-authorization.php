<?php
if (!defined('ABSPATH')) exit;

/**
 * H1B Authorization Core — Phase H1C-A2 (Fix Pass).
 *
 * This file defines two classes on purpose, kept together to minimize
 * surface area (no new file, no new loader line):
 *
 *   - PGE_Event_Access_Authorization_Context — a trusted, unforgeable
 *     value object. Its constructor is private; the ONLY way to obtain an
 *     instance is PGE_Event_Access_Authorization_Context::resolve($event_id,
 *     $actor_user_id), which takes real scalar identifiers only. There is
 *     no public entry point anywhere that accepts a caller-supplied claim
 *     of authority (no $is_admin/$is_owner/$membership_role/
 *     $granted_group_ids parameter exists on resolve()) — every field on a
 *     resolved context is derived here, from get_post_type()/
 *     get_post_field()/user_can() and the real
 *     PGE_Event_Access_Repository, never trusted from a caller. The class
 *     is final (no subclassing) and exposes only getters — no setter can
 *     turn a resolved Viewer/Manager/stranger context into Owner/Admin, and
 *     no scope can be added to granted_group_ids after construction.
 *
 *   - PGE_Event_Access_Authorization — the decision layer. Every can_*()
 *     method requires an actual PGE_Event_Access_Authorization_Context
 *     instance (`instanceof` check); a plain array, null, a WP_Error, or
 *     any foreign object is denied with REASON_INVALID_CONTEXT rather than
 *     ever being read as if it were a trusted context. This is what makes
 *     the previous array-shape contract ({is_admin, is_owner, ...}) no
 *     longer forgeable: there is no array shape a caller can construct
 *     that satisfies `instanceof`.
 *
 * Central actor-permission and scope decision layer for the H1B Event
 * Access system. This class owns actor authority and scope decisions only:
 * it never mutates storage, never re-implements PGE_Event_Access_Repository
 * invariants, and never performs transport/session work (no
 * current_user_can(), no get_current_user_id(), no nonce/AJAX/REST/cookie
 * logic). An actor is always passed in explicitly as $actor_user_id; this
 * class is deliberately agnostic to how that id was authenticated.
 *
 * Actors recognized: Administrator, Event Owner (post_author of the
 * pge_event post, not current_user_can('edit_post', ...)), Event
 * Collaborator — Manager, Event Collaborator — Viewer. Entry Supervisor is
 * not an actor here at all; this file must never reference
 * PGE_Supervisor_Session, PGE_Supervisor_Portal_Middleware,
 * pge_supervisor_session, or pge_is_active_supervisor_for_event.
 *
 * Owner/Admin membership independence: Administrator and Event Owner
 * authority is proved entirely from get_post_type()/user_can()/
 * get_post_field('post_author', ...) — PGE_Event_Access_Repository's
 * membership table is never read for an actor already proven to be Admin
 * or Owner. A corrupt, duplicate, or malformed H1B membership row for that
 * same user/event can therefore never suppress or interfere with Owner/
 * Admin authority. A non-owner, non-admin collaborator still fails closed
 * on any such corruption, exactly as before.
 *
 * On any fail-closed condition (invalid actor/event id, missing event,
 * wrong post type, corrupted/ambiguous membership or group-access data,
 * database error) resolve()/resolve_context() return a WP_Error instead.
 * There is no fallback role: a WP_Error can never be treated as Viewer or
 * any other role by a decision method below.
 *
 * TOCTOU contract (Sections 35-37 of the H1C-A2 brief): resolve_context()
 * plus the can_*() decision methods below answer "was the actor permitted
 * to attempt this?" as of the moment they run. A caller that later performs
 * a mutation MUST call resolve_context() (and the relevant can_*() check)
 * again immediately before invoking the Repository mutation, to catch a
 * membership revoked or a group grant revoked in between. Revalidation
 * reduces the race window. It does not make authorization and mutation
 * atomic. Concurrency/postcondition protection for the mutation itself
 * (expected_group_id, row locks, postcondition reread) remains entirely
 * PGE_Event_Access_Repository's responsibility — this class never
 * re-implements it and never decides "is the transition still valid",
 * only "was the actor permitted to attempt it". A resolved context is a
 * point-in-time snapshot, never a live object — it holds no $wpdb
 * reference and re-reads nothing after construction; "revalidate" always
 * means "call resolve() again", never "refresh this instance in place".
 *
 * This class does not wire itself to any AJAX/REST/UI endpoint. No
 * existing production handler is changed by this file's existence.
 */
final class PGE_Event_Access_Authorization_Context
{
    const MEMBERSHIP_ROLES = ['manager', 'viewer'];

    /** @var int */
    private $actor_user_id;
    /** @var int */
    private $event_id;
    /** @var bool */
    private $is_admin;
    /** @var bool */
    private $is_owner;
    /** @var int|null */
    private $membership_id;
    /** @var string|null */
    private $membership_role;
    /** @var string|null */
    private $membership_status;
    /** @var int[] */
    private $granted_group_ids;
    /**
     * @var int|null H1C-W8 (Additional Inviter Quota). NULL for every
     * ordinary membership and for Owner/Admin (who never have a membership
     * row consulted at all — see the Owner/Admin membership independence
     * note above). A strict positive int identifies an Additional Inviter
     * membership and its configured quota. This field grants no authority
     * by itself — it is exposed only as data for the quota resolver; every
     * can_*() decision below is completely unaffected by its value.
     */
    private $allocated_quota;

    private function __construct(
        int $actor_user_id,
        int $event_id,
        bool $is_admin,
        bool $is_owner,
        ?int $membership_id,
        ?string $membership_role,
        ?string $membership_status,
        array $granted_group_ids,
        ?int $allocated_quota
    ) {
        $this->actor_user_id = $actor_user_id;
        $this->event_id = $event_id;
        $this->is_admin = $is_admin;
        $this->is_owner = $is_owner;
        $this->membership_id = $membership_id;
        $this->membership_role = $membership_role;
        $this->membership_status = $membership_status;
        $this->granted_group_ids = $granted_group_ids;
        $this->allocated_quota = $allocated_quota;
    }

    /**
     * The ONLY way to obtain a context instance. $event_id/$actor_user_id
     * are real scalar identifiers — there is deliberately no parameter here
     * through which a caller could assert is_admin/is_owner/role/scope
     * directly; every one of those fields is derived inside this method
     * from real WordPress/Repository reads.
     *
     * @return self|WP_Error
     */
    public static function resolve($event_id, $actor_user_id)
    {
        if (!self::valid_scalar_id($actor_user_id)) return self::invalid_input();
        if (!self::valid_scalar_id($event_id)) return self::invalid_input();
        if (!function_exists('get_post_type')) return self::database_error();
        if (get_post_type($event_id) !== 'pge_event') return self::not_found();

        $is_admin = self::actor_is_administrator($actor_user_id);
        if (!is_bool($is_admin)) return self::database_error();

        $author_id = self::event_author_id($event_id);
        if ($author_id === null) return self::database_error();
        $is_owner = $author_id === $actor_user_id;

        // Owner/Admin membership independence: authority is already fully
        // proven above from post_type/post_author/user_can() alone. The
        // membership table is never consulted for them, so a corrupt,
        // duplicate, or malformed H1B membership row belonging to this same
        // (event, user) can never suppress or otherwise affect Owner/Admin
        // authority.
        if ($is_admin || $is_owner) {
            return new self($actor_user_id, $event_id, $is_admin, $is_owner, null, null, null, [], null);
        }

        if (!class_exists('PGE_Event_Access_Repository')
            || !method_exists('PGE_Event_Access_Repository', 'get_membership_for_user')
            || !method_exists('PGE_Event_Access_Repository', 'list_group_ids_for_membership')) {
            return self::database_error();
        }

        $membership = PGE_Event_Access_Repository::get_membership_for_user($event_id, $actor_user_id);
        if ($membership instanceof WP_Error) return $membership;

        $membership_id = null;
        $membership_role = null;
        $membership_status = null;
        $granted_group_ids = [];
        $allocated_quota = null;

        if (is_array($membership)) {
            if (!self::valid_scalar_id($membership['id'] ?? null)
                || !in_array($membership['status'] ?? null, ['active', 'revoked'], true)
                || ($membership['status'] === 'active' && !in_array($membership['role'] ?? null, self::MEMBERSHIP_ROLES, true))) {
                return self::database_error();
            }
            $membership_id = $membership['id'];
            $membership_status = $membership['status'];
            // H1C-W8: read straight through from the same already-fetched
            // Repository row — no extra query. NULL stays NULL (ordinary
            // membership); anything else must already be a valid positive
            // int, since PGE_Event_Access_Repository::normalize_membership()
            // itself fails closed with database_error() on any other stored
            // shape before this code ever runs.
            $quota_value = array_key_exists('allocated_quota', $membership) ? $membership['allocated_quota'] : null;
            if ($quota_value !== null) {
                if (!self::valid_scalar_id($quota_value)) return self::database_error();
                $allocated_quota = $quota_value;
            }

            if ($membership_status === 'active') {
                $membership_role = $membership['role'];
                $group_ids = PGE_Event_Access_Repository::list_group_ids_for_membership($event_id, $membership_id);
                if ($group_ids instanceof WP_Error) return $group_ids;
                if (!is_array($group_ids)) return self::database_error();
                $granted_group_ids = $group_ids;
            }
        }

        return new self($actor_user_id, $event_id, $is_admin, $is_owner, $membership_id, $membership_role, $membership_status, $granted_group_ids, $allocated_quota);
    }

    public function actor_user_id(): int
    {
        return $this->actor_user_id;
    }

    public function event_id(): int
    {
        return $this->event_id;
    }

    public function is_admin(): bool
    {
        return $this->is_admin;
    }

    public function is_owner(): bool
    {
        return $this->is_owner;
    }

    /** @return int|null */
    public function membership_id()
    {
        return $this->membership_id;
    }

    /** @return string|null */
    public function membership_role()
    {
        return $this->membership_role;
    }

    /** @return string|null */
    public function membership_status()
    {
        return $this->membership_status;
    }

    /** @return int[] a copy — PHP arrays are value types, so mutating the returned array never affects this context. */
    public function granted_group_ids(): array
    {
        return $this->granted_group_ids;
    }

    /**
     * H1C-W8 (Additional Inviter Quota). NULL for every ordinary
     * membership and for Owner/Admin. Grants no authority by itself — see
     * the field's own docblock above.
     *
     * @return int|null
     */
    public function allocated_quota()
    {
        return $this->allocated_quota;
    }

    public function is_active_collaborator(?string $role = null): bool
    {
        if ($this->membership_status !== 'active' || $this->membership_role === null) return false;
        return $role === null || $this->membership_role === $role;
    }

    public function has_group_access($group_id): bool
    {
        return self::valid_scalar_id($group_id) && in_array($group_id, $this->granted_group_ids, true);
    }

    private static function actor_is_administrator($actor_user_id)
    {
        if (!function_exists('user_can')) return null;
        try {
            return (bool) user_can($actor_user_id, 'administrator');
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function event_author_id($event_id)
    {
        if (!function_exists('get_post_field')) return null;
        try {
            $raw = get_post_field('post_author', $event_id);
        } catch (Throwable $e) {
            return null;
        }
        return self::positive_int_from_mixed($raw);
    }

    private static function positive_int_from_mixed($value)
    {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) return null;
        if (strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }
        return (int) $value;
    }

    private static function valid_scalar_id($value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function invalid_input()
    {
        return new WP_Error('invalid_input', 'Invalid authorization input.');
    }

    private static function not_found()
    {
        return new WP_Error('not_found', 'Requested event was not found.');
    }

    private static function database_error()
    {
        return new WP_Error('database_error', 'Unable to resolve authorization context.');
    }
}

final class PGE_Event_Access_Authorization
{
    const REASON_ADMIN = 'admin_override';
    const REASON_OWNER = 'event_owner';
    const REASON_GRANTED_SCOPE = 'granted_scope';
    const REASON_NOT_AUTHORIZED = 'not_authorized';
    const REASON_INVALID_CONTEXT = 'invalid_context';

    /**
     * Full operational guest projection (Owner/Admin). Real field names
     * only, taken directly from pge_event_guests_get_row_payload() in
     * event-guests.php — none invented.
     */
    const GUEST_PROJECTION_FULL = ['phone', 'name', 'note', 'code', 'status', 'status_label', 'checked'];

    /**
     * Manager's operational projection: includes 'note' (Manager needs the
     * organizer's seating/handling note to actually manage a guest within
     * their granted scope) but excludes 'code' (the internal invite/QR
     * code is not needed for move/unassign and stays out per data
     * minimization — a field is never exposed just because it exists in
     * storage).
     */
    const GUEST_PROJECTION_MANAGER = ['phone', 'name', 'note', 'status', 'status_label', 'checked'];

    /**
     * Viewer's read-only projection: excludes both 'note' (internal note)
     * and 'code' (internal storage/administrative field) — Section 16's
     * explicit allow list (name, phone, RSVP/status, attendance status).
     */
    const GUEST_PROJECTION_VIEWER = ['phone', 'name', 'status', 'status_label', 'checked'];

    // ──────────────────────────────────────────────────────────────
    // Context resolution
    // ──────────────────────────────────────────────────────────────

    /**
     * The documented, stable entry point for building a trusted context.
     * Thin delegation to PGE_Event_Access_Authorization_Context::resolve()
     * — kept as a named method here so existing/future callers have one
     * stable place to call, without exposing any way to fabricate a
     * context's fields.
     *
     * @return PGE_Event_Access_Authorization_Context|WP_Error
     */
    public static function resolve_context($event_id, $actor_user_id)
    {
        return PGE_Event_Access_Authorization_Context::resolve($event_id, $actor_user_id);
    }

    // ──────────────────────────────────────────────────────────────
    // Structural / administrative capability contract (Owner/Admin only)
    // ──────────────────────────────────────────────────────────────

    /** create_group / rename_group / archive_group / set_default_group. */
    public static function can_manage_event_structure($context)
    {
        return self::owner_or_admin_only($context);
    }

    /** create_membership / change_membership_role / revoke_membership / reactivate_membership. */
    public static function can_manage_membership($context)
    {
        return self::owner_or_admin_only($context);
    }

    /** grant_group_access / revoke_group_access. */
    public static function can_manage_group_access($context)
    {
        return self::owner_or_admin_only($context);
    }

    /** list_audit / read audit trail. */
    public static function can_view_audit($context)
    {
        return self::owner_or_admin_only($context);
    }

    /** Owner/Admin-only: unassigned guests are never visible to a collaborator (Section 18). */
    public static function can_view_unassigned_guests($context)
    {
        return self::owner_or_admin_only($context);
    }

    // ──────────────────────────────────────────────────────────────
    // Group / guest scoped capability contract
    // ──────────────────────────────────────────────────────────────

    public static function can_view_group($context, $group_id)
    {
        if (!self::is_trusted_context($context)) return self::deny(self::REASON_INVALID_CONTEXT);
        if ($context->is_admin()) return self::allow(self::REASON_ADMIN);
        if ($context->is_owner()) return self::allow(self::REASON_OWNER);
        if ($context->is_active_collaborator() && $context->has_group_access($group_id)) {
            return self::allow(self::REASON_GRANTED_SCOPE);
        }
        return self::deny();
    }

    /**
     * $assigned_group_id must be the guest's CURRENT group id (int) if the
     * guest is assigned, or null if the guest is unassigned or the
     * assignment could not be resolved. Owner/Admin see the guest either
     * way (Section 18/19). A collaborator sees it only if the guest is
     * currently assigned to one of their granted groups — this collapses
     * "guest missing", "guest unassigned" and "guest assigned to an
     * ungranted group" into the same deny() shape (Section 32/43).
     */
    public static function can_view_guest($context, $assigned_group_id)
    {
        if (!self::is_trusted_context($context)) return self::deny(self::REASON_INVALID_CONTEXT);
        if ($context->is_admin()) return self::allow(self::REASON_ADMIN);
        if ($context->is_owner()) return self::allow(self::REASON_OWNER);
        if ($assigned_group_id !== null
            && $context->is_active_collaborator()
            && $context->has_group_access($assigned_group_id)) {
            return self::allow(self::REASON_GRANTED_SCOPE);
        }
        return self::deny();
    }

    /** Initial assignment always requires seeing the unassigned pool first — Owner/Admin only (Section 21). */
    public static function can_assign_guest($context)
    {
        return self::owner_or_admin_only($context);
    }

    /**
     * H1C-W8 — Additional Inviter Quota. Deliberately NOT an extension of
     * can_assign_guest() above, which stays Owner/Admin-only and unchanged
     * (Section 21's "never browses the unassigned pool" rule is preserved
     * exactly as-is). This is a narrow, different capability: it answers
     * only "may this actor create-and-assign a brand-new guest of their
     * own, via the controlled Additional-Inviter workflow, into the single
     * group already granted to their own active Manager membership" — it
     * never grants unassigned-pool visibility, never grants a choice of
     * group, and is meaningless (and denied) for Owner/Admin/Viewer/a
     * revoked Manager/a Manager with no membership at all. The caller is
     * still responsible for verifying the membership is actually
     * quota-configured (allocated_quota() !== null) and has exactly one
     * granted group before treating this as an Additional Inviter — this
     * method alone does not encode either of those product-contract rules,
     * since they are configuration/state checks, not authority checks.
     */
    public static function can_create_scoped_guest($context)
    {
        if (!self::is_trusted_context($context)) return self::deny(self::REASON_INVALID_CONTEXT);
        if ($context->is_active_collaborator('manager')) return self::allow(self::REASON_GRANTED_SCOPE);
        return self::deny();
    }

    /**
     * Owner/Admin: authorization-level allow; Repository governs domain
     * validity (Section 22). Manager: both $source_group_id and
     * $target_group_id must be in granted_group_ids — a granted G1 does not
     * imply access to any other group, including a same-group no-op move,
     * which must never become a scope-bypass path. Viewer: always denied.
     */
    public static function can_move_guest($context, $source_group_id, $target_group_id)
    {
        if (!self::is_trusted_context($context)) return self::deny(self::REASON_INVALID_CONTEXT);
        if ($context->is_admin()) return self::allow(self::REASON_ADMIN);
        if ($context->is_owner()) return self::allow(self::REASON_OWNER);
        if ($context->is_active_collaborator('manager')
            && $context->has_group_access($source_group_id)
            && $context->has_group_access($target_group_id)) {
            return self::allow(self::REASON_GRANTED_SCOPE);
        }
        return self::deny();
    }

    /**
     * $current_group_id must be the guest's CURRENT group id if it can be
     * safely resolved, or null if it is missing/cross-event/malformed/
     * ambiguous (Section 23). Owner/Admin: always allowed — this
     * authorization layer must never block their existing cleanup
     * capability; Repository alone decides whether the mutation succeeds.
     * Manager: allowed only when $current_group_id is resolvable AND
     * granted. Viewer: always denied.
     */
    public static function can_unassign_guest($context, $current_group_id)
    {
        if (!self::is_trusted_context($context)) return self::deny(self::REASON_INVALID_CONTEXT);
        if ($context->is_admin()) return self::allow(self::REASON_ADMIN);
        if ($context->is_owner()) return self::allow(self::REASON_OWNER);
        if ($current_group_id !== null
            && $context->is_active_collaborator('manager')
            && $context->has_group_access($current_group_id)) {
            return self::allow(self::REASON_GRANTED_SCOPE);
        }
        return self::deny();
    }

    // ──────────────────────────────────────────────────────────────
    // Guest projection (Sections 16-17, 28, 30-31)
    // ──────────────────────────────────────────────────────────────

    /**
     * Applies data minimization to an already-authorized guest payload.
     * Callers must call can_view_guest() (or equivalent) first — this
     * method does not authorize, it only trims fields. $full_payload is
     * expected to come from pge_event_guests_get_row_payload() or an
     * equivalent shape; unknown/missing keys are silently dropped rather
     * than invented, per the explicit instruction not to fabricate field
     * names that do not exist in the real guest record.
     */
    public static function project_guest_fields($context, array $full_payload)
    {
        if (!self::is_trusted_context($context)) return [];
        if ($context->is_admin() || $context->is_owner()) {
            $allow_list = self::GUEST_PROJECTION_FULL;
        } elseif ($context->is_active_collaborator('manager')) {
            $allow_list = self::GUEST_PROJECTION_MANAGER;
        } elseif ($context->is_active_collaborator('viewer')) {
            $allow_list = self::GUEST_PROJECTION_VIEWER;
        } else {
            // A trusted context that is nonetheless not Owner/Admin and not
            // an active Manager/Viewer collaborator — a stranger with no
            // membership, or a revoked Manager/Viewer — has no guest-read
            // authority at all. Fail closed with no projection rather than
            // defaulting to Viewer fields: this method must never hand out
            // guest PII to a trusted-but-unauthorized context, even if a
            // future caller invokes it without calling can_view_guest()
            // first (Defense in Depth; the permission matrix itself is
            // unchanged — can_view_guest() etc. still gate the read).
            return [];
        }
        return array_intersect_key($full_payload, array_flip($allow_list));
    }

    // ──────────────────────────────────────────────────────────────
    // Scoped guest read (Sections 28-30)
    // ──────────────────────────────────────────────────────────────

    /**
     * Collaborator-only scoped guest-assignment read. Scope
     * (granted_group_ids) was already resolved inside resolve_context();
     * this method never re-derives it and never loads guest PII from Post
     * Meta — it only returns the H1B assignment rows (id/phone/group_id/
     * timestamps) for groups the actor was already proven to have access
     * to. Resolving guest identity/name/note details for the phones this
     * returns remains the caller's job via the existing event-guests.php
     * functions, followed by project_guest_fields() for minimization —
     * Authorization never touches the _pge_invited_guests Post Meta
     * boundary itself (Section 30).
     *
     * Owner/Admin are intentionally rejected here (invalid_scope): they
     * need no scoping and should read via PGE_Event_Access_Repository
     * directly (Section 13 — this class only authorizes, it does not
     * re-implement or wrap every Repository read).
     *
     * With zero granted groups, this returns an empty paginated shape
     * without ever calling the Repository — no guest data can leak from a
     * query that is never issued.
     */
    public static function list_scoped_group_assignments($context, $page = 1, $per_page = null)
    {
        if (!self::is_trusted_context($context)) return self::invalid_scope();
        if ($context->is_admin() || $context->is_owner()) return self::invalid_scope();
        if (!$context->is_active_collaborator()) return self::invalid_scope();
        if (!class_exists('PGE_Event_Access_Repository')) return self::database_error();

        $per_page = $per_page === null ? PGE_Event_Access_Repository::DEFAULT_PER_PAGE : $per_page;

        $group_ids = $context->granted_group_ids();
        if (!$group_ids) {
            return [
                'items' => [],
                'page' => $page,
                'per_page' => $per_page,
                'total' => 0,
                'total_pages' => 0,
            ];
        }

        if (!method_exists('PGE_Event_Access_Repository', 'list_group_assignments_for_groups')) {
            return self::database_error();
        }
        return PGE_Event_Access_Repository::list_group_assignments_for_groups($context->event_id(), $group_ids, $page, $per_page);
    }

    // ──────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────

    private static function owner_or_admin_only($context)
    {
        if (!self::is_trusted_context($context)) return self::deny(self::REASON_INVALID_CONTEXT);
        if ($context->is_admin()) return self::allow(self::REASON_ADMIN);
        if ($context->is_owner()) return self::allow(self::REASON_OWNER);
        return self::deny();
    }

    /**
     * The only check that matters for forgery-resistance: a real, trusted
     * context can only ever be a PGE_Event_Access_Authorization_Context
     * instance obtained from its own resolve() factory. There is no array
     * shape, foreign object, or null that satisfies instanceof — this is
     * what replaces the old array-shape is_context() check.
     */
    private static function is_trusted_context($context): bool
    {
        return $context instanceof PGE_Event_Access_Authorization_Context;
    }

    private static function allow($reason)
    {
        return ['allowed' => true, 'reason' => $reason];
    }

    private static function deny($reason = self::REASON_NOT_AUTHORIZED)
    {
        return ['allowed' => false, 'reason' => $reason];
    }

    private static function invalid_scope()
    {
        return new WP_Error('invalid_scope', 'Scoped read is not applicable for this authorization context.');
    }

    private static function database_error()
    {
        return new WP_Error('database_error', 'Unable to resolve authorization context.');
    }
}
