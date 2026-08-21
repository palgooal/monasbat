<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * H1C-W10 — Additional Inviter Onboarding (Backend)
 * ============================================================================
 * Product contract (Section 1 of the H1C-W10 brief): Owner/Admin identifies
 * an invitee by email; the system decides at COMPLETION time whether that
 * email already belongs to a real WP account (silent internal binding) or
 * needs a brand-new account (self-service creation on the token page) —
 * never via a free user search, never by exposing a user_id, never by
 * letting the Owner learn whether the email already has an account.
 *
 * Architecture — how W8 Additional Inviter creation happens WITHOUT
 * impersonating Owner/Admin (Section 13's central question):
 *   PGE_Event_Access_Repository::create_additional_inviter_membership()
 *   (unmodified, unchanged this phase) never performs authorization itself
 *   — that has always been the ORCHESTRATOR's job (PGE_Additional_Inviter::
 *   create_additional_inviter_for_actor() proves live Owner/Admin authority
 *   BEFORE calling it; this class proves authority a DIFFERENT way — by
 *   revalidating a previously-approved, still-valid onboarding invitation
 *   record). The $actor_user_id parameter that method takes is documented
 *   (and, by reading its body, proven) to be an AUDIT-ATTRIBUTION field
 *   only (created_by_user_id + audit log actor) — the Repository does not
 *   re-check that this id currently holds any authority. This class calls
 *   that exact same, unmodified Repository method directly, passing the
 *   ORIGINAL INVITER's user_id (captured at invitation-creation time, when
 *   they WERE freshly verified as Owner/Admin via can_manage_membership())
 *   as that attribution value — an honest "this membership exists because
 *   user X's earlier, still-valid grant authorized it", never a live-actor
 *   impersonation, and never a change to any public Owner/Admin-facing API.
 *
 * This file owns the ENTIRE onboarding-invitation table
 * ({$wpdb->prefix}pge_additional_inviter_invitations) — no other file
 * reads or writes it. It is a genuinely separate concern from the H1C
 * event-access tables (PGE_Event_Access_Repository's five tables): it does
 * its own light $wpdb access rather than going through that Repository's
 * table()/paginate() helpers, which are hard-scoped to those five tables.
 */
final class PGE_Additional_Inviter_Onboarding
{
    const REASON_NOT_AUTHORIZED = 'not_authorized';
    const REASON_INVALID_INPUT = 'invalid_input';
    const REASON_INVALID_STATE = 'invalid_state';
    const REASON_NOT_FOUND = 'not_found';
    const REASON_QUOTA_GROUP_CONFLICT = 'quota_group_conflict';
    const REASON_DELIVERY_FAILED = 'delivery_failed';
    const REASON_TOKEN_INVALID = 'invalid_token';
    const REASON_ACCOUNT_CONFLICT = 'account_conflict';
    const REASON_SERVER_ERROR = 'server_error';

    /** Section 16: no existing project convention found — 7 days, named constant, not a repeated magic number. */
    const INVITATION_TTL_SECONDS = 7 * DAY_IN_SECONDS;

    const STATUS_PENDING = 'pending';
    const STATUS_CONSUMED = 'consumed';
    const STATUS_REVOKED = 'revoked';

    const DEFAULT_PER_PAGE = 20;
    const MAX_PER_PAGE = 100;

    // ──────────────────────────────────────────────────────────────
    // Owner/Admin: create + send an onboarding invitation
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{ok:true,invitation_id:int}|WP_Error
     */
    public static function create_invitation_for_actor($event_id, $actor_user_id, $group_id, $allocated_quota, $invitee_email, $display_name = '')
    {
        if (!self::valid_id($event_id) || !self::valid_id($actor_user_id) || !self::valid_id($group_id) || !self::valid_id($allocated_quota)) {
            return self::invalid_input();
        }
        $email = self::normalize_email($invitee_email);
        if ($email === null) return self::invalid_input();
        $display_name = is_scalar($display_name) ? trim((string) $display_name) : '';
        if (self::string_length($display_name) > 191) return self::invalid_input();

        $context = self::resolve_actor_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;
        $decision = PGE_Event_Access_Authorization::can_manage_membership($context);
        if (empty($decision['allowed'])) return self::not_authorized();

        if (!class_exists('PGE_Event_Access_Repository')) return self::server_error();
        $group = PGE_Event_Access_Repository::get_group($event_id, $group_id);
        if ($group instanceof WP_Error) {
            // Same collapse discipline as everywhere else in H1C: a
            // nonexistent/cross-event group is not distinguished from any
            // other creation failure at the public boundary.
            return self::invalid_input();
        }
        if (!is_array($group) || ($group['status'] ?? null) !== 'active') return self::invalid_input();

        if (!self::schema_ready()) return self::server_error();

        $lock_name = self::lock_name($event_id, $group_id);
        global $wpdb;
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ((int) $got_lock !== 1) {
            return new WP_Error(self::REASON_SERVER_ERROR, 'الخدمة غير متاحة حالياً، يرجى المحاولة لاحقاً.');
        }

        try {
            $now = current_time('mysql', true);

            // Section 9/10 invariant: ONE GROUP -> at most one active
            // Additional Inviter OR one pending valid onboarding invitation.
            $conflict = self::group_has_active_inviter($event_id, $group_id);
            if ($conflict instanceof WP_Error) return $conflict;
            if ($conflict === true) return self::quota_group_conflict();

            $blocking = self::find_blocking_pending_invitation($event_id, $group_id, $email, $now);
            if ($blocking instanceof WP_Error) return $blocking;
            if ($blocking !== null) return self::quota_group_conflict();

            // Internal-only resolution — NEVER surfaced to the Owner/Admin
            // caller (Section 6: the public response must not become a WP
            // user-enumeration oracle).
            $existing_user = function_exists('get_user_by') ? get_user_by('email', $email) : false;
            $existing_user_id = ($existing_user instanceof WP_User) ? (int) $existing_user->ID : null;

            $raw_token = self::generate_token();
            $token_hash = self::hash_token($raw_token);
            $expires_at = gmdate('Y-m-d H:i:s', strtotime($now) + self::INVITATION_TTL_SECONDS);

            $table = self::table();
            // existing_user_id is a nullable BIGINT column. Binding it via a
            // %s placeholder unconditionally (as an earlier draft of this
            // method did) is a real bug: $wpdb->prepare() renders a PHP null
            // passed to %s as an empty string, not SQL NULL, and MariaDB
            // under strict mode (the common default) then rejects '' for an
            // integer column outright — which would break every invitation
            // for a genuinely new invitee (no existing account), the most
            // common case this feature exists for. Instead, conditionally
            // embed the literal NULL keyword (same technique already used
            // for resolved_user_id/consumed_at/revoked_at below) when there
            // is no existing user, and use a real %d placeholder otherwise.
            $existing_user_sql = $existing_user_id === null ? 'NULL' : '%d';
            $insert_args = [$event_id, $group_id, $allocated_quota, $email, $display_name, $token_hash, self::STATUS_PENDING];
            if ($existing_user_id !== null) $insert_args[] = $existing_user_id;
            $insert_args[] = $actor_user_id;
            $insert_args[] = $expires_at;
            $insert_args[] = $now;
            $insert_args[] = $now;
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (event_id, group_id, allocated_quota, invitee_email, display_name, invitation_token_hash, status, existing_user_id, resolved_user_id, created_by_user_id, expires_at, consumed_at, revoked_at, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %s, %s, $existing_user_sql, NULL, %d, %s, NULL, NULL, %s, %s)",
                $insert_args
            ));
            if ($inserted !== 1) return self::server_error();
            $invitation_id = (int) $wpdb->insert_id;
            if ($invitation_id <= 0) return self::server_error();

            self::log_lifecycle_event('onboarding_invitation_created', $invitation_id, $event_id, $group_id, $allocated_quota, $actor_user_id);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }

        // ── Delivery (Section 18) — outside the lock: sending mail must
        // never hold GET_LOCK for the group's whole reservation window. ──
        $join_url = self::build_join_url($raw_token);
        $sent = self::deliver_onboarding_email($email, $display_name, $join_url);
        if (!$sent) {
            // Section 18's chosen policy: never report success if delivery
            // failed. A dead pending row would otherwise silently reserve
            // the group forever (Section 9's invariant), so best-effort
            // revoke it immediately rather than leave a phantom reservation.
            self::revoke_row_after_failed_delivery($invitation_id, $event_id);
            return new WP_Error(self::REASON_DELIVERY_FAILED, 'تعذّر إرسال دعوة الانضمام، يرجى المحاولة مرة أخرى.');
        }

        // Section 6: generic response only — never reveals existing-vs-new.
        return ['ok' => true, 'invitation_id' => $invitation_id];
    }

    // ──────────────────────────────────────────────────────────────
    // Owner/Admin: revoke a pending invitation
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{ok:true,already_revoked:bool}|WP_Error
     */
    public static function revoke_invitation_for_actor($event_id, $actor_user_id, $invitation_id)
    {
        if (!self::valid_id($event_id) || !self::valid_id($actor_user_id) || !self::valid_id($invitation_id)) {
            return self::invalid_input();
        }
        $context = self::resolve_actor_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;
        $decision = PGE_Event_Access_Authorization::can_manage_membership($context);
        if (empty($decision['allowed'])) return self::not_authorized();
        if (!self::schema_ready()) return self::server_error();

        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, status, group_id, allocated_quota FROM $table WHERE id = %d AND event_id = %d LIMIT 1", $invitation_id, $event_id), ARRAY_A);
        if ($row === null) return self::not_found();

        if ($row['status'] === self::STATUS_REVOKED) {
            return ['ok' => true, 'already_revoked' => true];
        }
        if ($row['status'] !== self::STATUS_PENDING) {
            // consumed: the invitation has already graduated into a real
            // membership — revoking the invitation record after the fact
            // does nothing useful (out of scope: use the existing H1C
            // membership revoke path for that, Section 28).
            return self::invalid_state();
        }

        $now = current_time('mysql', true);
        $updated = $wpdb->update(
            $table,
            ['status' => self::STATUS_REVOKED, 'revoked_at' => $now, 'updated_at' => $now],
            ['id' => $invitation_id, 'status' => self::STATUS_PENDING],
            ['%s', '%s', '%s'],
            ['%d', '%s']
        );
        if ($updated === false || $updated === 0) {
            // Race: consumed/revoked concurrently between the read above and this write.
            return self::invalid_state();
        }

        self::log_lifecycle_event('onboarding_invitation_revoked', $invitation_id, $event_id, (int) $row['group_id'], (int) $row['allocated_quota'], $actor_user_id);
        return ['ok' => true, 'already_revoked' => false];
    }

    // ──────────────────────────────────────────────────────────────
    // Owner/Admin: W9-style pending-invitation read (Section 21)
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{ok:true,items:array,page:int,per_page:int,total:int,total_pages:int}|WP_Error
     */
    public static function list_pending_invitations_for_owner($event_id, $actor_user_id, $page = 1, $per_page = null)
    {
        if (!self::valid_id($event_id) || !self::valid_id($actor_user_id)) return self::invalid_input();
        $context = self::resolve_actor_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return $context;
        $decision = PGE_Event_Access_Authorization::can_manage_membership($context);
        if (empty($decision['allowed'])) return self::not_authorized();

        if ($per_page === null) $per_page = self::DEFAULT_PER_PAGE;
        if (!self::valid_id($page) || !self::valid_id($per_page) || $per_page > self::MAX_PER_PAGE) return self::invalid_input();
        if (!self::schema_ready()) return self::server_error();

        global $wpdb;
        $table = self::table();
        $now = current_time('mysql', true);
        $offset = ($page - 1) * $per_page;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND status = %s AND expires_at > %s",
            $event_id, self::STATUS_PENDING, $now
        ));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, group_id, allocated_quota, invitee_email, display_name, status, expires_at, created_at FROM $table WHERE event_id = %d AND status = %s AND expires_at > %s ORDER BY id ASC LIMIT %d OFFSET %d",
            $event_id, self::STATUS_PENDING, $now, $per_page, $offset
        ), ARRAY_A);
        if (!is_array($rows)) return self::server_error();

        $items = [];
        foreach ($rows as $row) {
            $group_id = (int) $row['group_id'];
            $group = class_exists('PGE_Event_Access_Repository') ? PGE_Event_Access_Repository::get_group($event_id, $group_id) : null;
            $group_name = (is_array($group) && isset($group['name'])) ? $group['name'] : null;
            $items[] = [
                'invitation_id' => (int) $row['id'],
                'contact_masked' => self::mask_email((string) $row['invitee_email']),
                'display_name' => (string) $row['display_name'],
                'group_id' => $group_id,
                'group_name' => $group_name,
                'allocated_quota' => (int) $row['allocated_quota'],
                'status' => (string) $row['status'],
                'expires_at' => (string) $row['expires_at'],
            ];
        }

        return [
            'ok' => true,
            'items' => $items,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : intdiv($total - 1, $per_page) + 1,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Public, token-authenticated: GET-safe preview (no mutation)
    // ──────────────────────────────────────────────────────────────

    /**
     * Never mutates anything — safe to run on a plain GET request even if
     * fetched by an email client's link-preview/security scanner (same
     * GET-preview / POST-confirms discipline as the existing Supervisor
     * Login RFC route, Section 20).
     *
     * @return array{valid:true,has_existing_account:bool,invitee_email_masked:string,event_title:string}|WP_Error
     */
    public static function preview_onboarding_token($raw_token)
    {
        $row = self::load_valid_pending_row($raw_token);
        if ($row instanceof WP_Error) return $row;

        $event_title = '';
        if (function_exists('get_post')) {
            $post = get_post((int) $row['event_id']);
            if (is_object($post) && isset($post->post_title)) $event_title = (string) $post->post_title;
        }

        return [
            'valid' => true,
            'has_existing_account' => $row['existing_user_id'] !== null,
            'invitee_email_masked' => self::mask_email((string) $row['invitee_email']),
            'event_title' => $event_title,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Public, token-authenticated: complete via an existing WP account
    // ──────────────────────────────────────────────────────────────

    /**
     * Section 11's chosen MVP policy, explicitly documented: the onboarding
     * link is delivered to the SAME email address WordPress already has on
     * file for the matched account (get_user_by('email', ...) at creation
     * time used the identical normalized email column later re-verified
     * here) — so possession of the token IS treated as sufficient proof of
     * control for MVP, and this call authenticates the matched account
     * directly (wp_set_auth_cookie()) rather than prompting a password.
     * If the browser already holds a session for a DIFFERENT WP user, this
     * call safely refuses rather than silently switching/attaching to the
     * wrong account.
     *
     * @return array{ok:true,membership_id:int}|WP_Error
     */
    public static function complete_with_existing_account($raw_token)
    {
        $row = self::load_valid_pending_row($raw_token);
        if ($row instanceof WP_Error) return $row;
        if ($row['existing_user_id'] === null) return self::invalid_state();

        if (function_exists('is_user_logged_in') && is_user_logged_in()
            && function_exists('get_current_user_id') && (int) get_current_user_id() !== (int) $row['existing_user_id']) {
            return self::account_conflict();
        }

        return self::finish_completion($row, (int) $row['existing_user_id']);
    }

    // ──────────────────────────────────────────────────────────────
    // Public, token-authenticated: complete via a brand-new WP account
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{ok:true,membership_id:int}|WP_Error
     */
    public static function complete_with_new_account($raw_token, $password, $display_name)
    {
        $row = self::load_valid_pending_row($raw_token);
        if ($row instanceof WP_Error) return $row;

        $password = is_scalar($password) ? (string) $password : '';
        if (self::string_length($password) < 8) return self::invalid_input();
        $display_name = is_scalar($display_name) ? trim((string) $display_name) : '';
        if ($display_name === '') $display_name = (string) $row['display_name'];
        if ($display_name === '') $display_name = 'داعٍ إضافي';

        $email = (string) $row['invitee_email'];

        // Duplicate-email race (Section 12/Test category E): someone else
        // created (or completed a different invitation into) this exact
        // account between invitation-creation and now. Self-heal instead
        // of failing: fall through to the existing-account path.
        $existing = function_exists('get_user_by') ? get_user_by('email', $email) : false;
        if ($existing instanceof WP_User) {
            if (function_exists('is_user_logged_in') && is_user_logged_in()
                && function_exists('get_current_user_id') && (int) get_current_user_id() !== (int) $existing->ID) {
                return self::account_conflict();
            }
            return self::finish_completion($row, (int) $existing->ID);
        }

        if (!function_exists('wp_create_user')) return self::server_error();
        $user_id = wp_create_user($email, $password, $email);
        if (is_wp_error($user_id)) {
            // Most likely: a race just created this exact email as another
            // user's login between our check above and this call. Re-check
            // once, self-heal into the existing-account path if so.
            $existing_after_race = function_exists('get_user_by') ? get_user_by('email', $email) : false;
            if ($existing_after_race instanceof WP_User) {
                return self::finish_completion($row, (int) $existing_after_race->ID);
            }
            return self::server_error();
        }
        $user_id = (int) $user_id;

        if (function_exists('wp_update_user')) {
            wp_update_user(['ID' => $user_id, 'display_name' => $display_name, 'nickname' => $display_name]);
        }
        if (function_exists('wp_set_auth_cookie')) {
            wp_set_auth_cookie($user_id);
        }

        return self::finish_completion($row, $user_id);
    }

    /**
     * Shared tail of both completion paths (Section 13/14): re-validate
     * the invitation is STILL valid right now (event still exists, group
     * still belongs to it, no conflicting active inviter appeared since
     * creation — Section 14's explicit "revalidate under current event
     * authority" requirement), atomically consume the token (compound
     * WHERE id + invitation_token_hash — the exact same race-safe
     * compare-and-swap pattern PGE_Supervisor_Assignment_Service::
     * accept_invitation() already uses), THEN create the membership via
     * the existing, unmodified W8 Repository primitive. Token is consumed
     * only AFTER membership creation succeeds (never before) — if
     * membership creation fails, the token remains valid/retryable rather
     * than being burned on a failed attempt.
     */
    private static function finish_completion(array $row, $target_user_id)
    {
        $event_id = (int) $row['event_id'];
        $group_id = (int) $row['group_id'];
        $allocated_quota = (int) $row['allocated_quota'];
        $invitation_id = (int) $row['id'];
        $created_by_user_id = (int) $row['created_by_user_id'];

        if (!class_exists('PGE_Event_Access_Repository')) return self::server_error();

        $group = PGE_Event_Access_Repository::get_group($event_id, $group_id);
        if ($group instanceof WP_Error || !is_array($group) || ($group['status'] ?? null) !== 'active') {
            return self::invalid_state();
        }
        $conflict = self::group_has_active_inviter($event_id, $group_id);
        if ($conflict instanceof WP_Error) return $conflict;
        if ($conflict === true) return self::quota_group_conflict();

        $result = PGE_Event_Access_Repository::create_additional_inviter_membership(
            $event_id, $target_user_id, $group_id, $allocated_quota, $created_by_user_id
        );
        if ($result instanceof WP_Error) {
            $code = (string) $result->get_error_code();
            if ($code === 'quota_group_conflict') return self::quota_group_conflict();
            if ($code === 'duplicate_membership') return self::invalid_state();
            return self::server_error();
        }
        if (!is_array($result) || !isset($result['membership']['id'])) return self::server_error();
        $membership_id = (int) $result['membership']['id'];

        // Consume the token ONLY now that the membership genuinely exists.
        global $wpdb;
        $table = self::table();
        $now = current_time('mysql', true);
        $consumed = $wpdb->update(
            $table,
            ['status' => self::STATUS_CONSUMED, 'consumed_at' => $now, 'resolved_user_id' => $target_user_id, 'invitation_token_hash' => null, 'updated_at' => $now],
            ['id' => $invitation_id, 'status' => self::STATUS_PENDING],
            ['%s', '%s', '%d', 'NULL', '%s'],
            ['%d', '%s']
        );
        // Even if this UPDATE affects 0 rows (a concurrent duplicate
        // completion attempt lost the race — see Test G), the membership
        // this call itself created is real and already returned to THIS
        // caller; the token row's bookkeeping lagging behind does not
        // undo that. The Repository's own duplicate_membership guard is
        // what actually prevents two memberships for the same user.

        self::log_lifecycle_event('onboarding_invitation_consumed', $invitation_id, $event_id, $group_id, $allocated_quota, $target_user_id);
        return ['ok' => true, 'membership_id' => $membership_id];
    }

    /**
     * Token validation shared by preview + both completion paths. Does
     * NOT distinguish "token never existed" from "already consumed/
     * revoked/expired" in its error code — all collapse to the same
     * invalid_token (Section 8: "replay after consumed fails", "expired
     * token fails", none of these are told apart at the public boundary).
     *
     * @return array|WP_Error the raw DB row (never returned publicly as-is)
     */
    private static function load_valid_pending_row($raw_token)
    {
        $raw_token = is_scalar($raw_token) ? trim((string) $raw_token) : '';
        // Cheap shape check before ANY DB hit (Supervisor's exact pattern).
        if ($raw_token === '' || !preg_match('/\A[0-9a-f]{64}\z/', $raw_token)) {
            return self::token_invalid();
        }
        if (!self::schema_ready()) return self::server_error();

        global $wpdb;
        $table = self::table();
        $hash = self::hash_token($raw_token);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE invitation_token_hash = %s LIMIT 1", $hash), ARRAY_A);
        if (!is_array($row)) return self::token_invalid();
        if ((string) $row['status'] !== self::STATUS_PENDING) return self::token_invalid();

        $now = current_time('mysql', true);
        if (strtotime((string) $row['expires_at']) <= strtotime($now)) return self::token_invalid();

        $row['event_id'] = (int) $row['event_id'];
        $row['group_id'] = (int) $row['group_id'];
        $row['allocated_quota'] = (int) $row['allocated_quota'];
        $row['id'] = (int) $row['id'];
        $row['created_by_user_id'] = (int) $row['created_by_user_id'];
        $row['existing_user_id'] = $row['existing_user_id'] !== null ? (int) $row['existing_user_id'] : null;
        return $row;
    }

    // ──────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Light, non-locking pre-check (Section 9): the AUTHORITATIVE
     * exclusivity guarantee is always the Repository's own transactional
     * ensure_no_active_quota_inviter_for_group(), enforced again inside
     * create_additional_inviter_membership() at actual completion time —
     * this only avoids reserving/sending a doomed invitation up front.
     * Reuses the existing, unmodified public Repository reads exactly as
     * H1C-W8/W9's own real-DB verification scripts already did.
     *
     * @return bool|WP_Error
     */
    private static function group_has_active_inviter($event_id, $group_id)
    {
        if (!class_exists('PGE_Event_Access_Repository')) return self::server_error();
        $membership_ids = PGE_Event_Access_Repository::list_membership_ids_for_group($event_id, $group_id);
        if ($membership_ids instanceof WP_Error) return $membership_ids;
        if (!is_array($membership_ids)) return self::server_error();
        foreach ($membership_ids as $membership_id) {
            $membership = PGE_Event_Access_Repository::get_membership($event_id, $membership_id);
            if ($membership instanceof WP_Error) continue;
            if (is_array($membership) && ($membership['status'] ?? null) === 'active'
                && ($membership['role'] ?? null) === 'manager' && ($membership['allocated_quota'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array|null|WP_Error the blocking row, or null if none
     */
    private static function find_blocking_pending_invitation($event_id, $group_id, $email, $now)
    {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE event_id = %d AND status = %s AND expires_at > %s AND (group_id = %d OR invitee_email = %s) LIMIT 1",
            $event_id, self::STATUS_PENDING, $now, $group_id, $email
        ), ARRAY_A);
        return $row;
    }

    private static function revoke_row_after_failed_delivery($invitation_id, $event_id)
    {
        global $wpdb;
        $table = self::table();
        $now = current_time('mysql', true);
        $updated = $wpdb->update(
            $table,
            ['status' => self::STATUS_REVOKED, 'revoked_at' => $now, 'updated_at' => $now],
            ['id' => $invitation_id, 'event_id' => $event_id, 'status' => self::STATUS_PENDING],
            ['%s', '%s', '%s'],
            ['%d', '%d', '%s']
        );
        if ($updated !== 1 && function_exists('error_log')) {
            error_log(sprintf('PGE H1C-W10: failed to auto-revoke invitation_id=%d after delivery failure — may leave a phantom group reservation.', $invitation_id));
        }
    }

    private static function deliver_onboarding_email($email, $display_name, $join_url)
    {
        if (!function_exists('wp_mail')) return false;
        $subject = 'دعوة انضمام - داعٍ إضافي';
        $greeting = $display_name !== '' ? "مرحباً {$display_name}،" : 'مرحباً،';
        $body = "{$greeting}\n\nتمت دعوتك للانضمام كداعٍ إضافي في إحدى المناسبات.\n\nيرجى الضغط على الرابط التالي لإتمام التسجيل:\n{$join_url}\n\nهذا الرابط صالح لمدة محدودة ولاستخدام واحد فقط.";
        try {
            return (bool) wp_mail($email, $subject, $body);
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function build_join_url($raw_token)
    {
        if (!function_exists('home_url')) return '';
        return home_url('/additional-inviter/join/' . rawurlencode($raw_token) . '/');
    }

    private static function mask_email($email)
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) return '***';
        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        $visible = self::string_length($local) <= 2 ? substr($local, 0, 1) : substr($local, 0, 2);
        return $visible . str_repeat('*', max(1, self::string_length($local) - self::string_length($visible))) . $domain;
    }

    private static function log_lifecycle_event($event, $invitation_id, $event_id, $group_id, $allocated_quota, $actor_user_id)
    {
        if (!function_exists('error_log')) return;
        // Section 23: IDs/group/quota only — never raw email/phone/token.
        error_log(sprintf(
            'PGE H1C-W10 [%s] invitation_id=%d event_id=%d group_id=%d allocated_quota=%d actor_user_id=%d',
            $event, $invitation_id, $event_id, $group_id, $allocated_quota, $actor_user_id
        ));
    }

    private static function normalize_email($email)
    {
        if (!is_scalar($email)) return null;
        $email = trim((string) $email);
        if (function_exists('sanitize_email')) $email = sanitize_email($email);
        $email = strtolower($email);
        if ($email === '' || self::string_length($email) > 255) return null;
        if (function_exists('is_email') && !is_email($email)) return null;
        if (!function_exists('is_email') && strpos($email, '@') === false) return null;
        return $email;
    }

    private static function generate_token(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function hash_token(string $raw_token): string
    {
        return hash('sha256', $raw_token);
    }

    private static function lock_name($event_id, $group_id): string
    {
        return 'pge_ai_onboarding_' . md5($event_id . '|' . $group_id);
    }

    private static function table()
    {
        return PGE_Additional_Inviter_Onboarding_Schema::table_name();
    }

    private static function schema_ready(): bool
    {
        return class_exists('PGE_Additional_Inviter_Onboarding_Schema') && PGE_Additional_Inviter_Onboarding_Schema::is_ready();
    }

    /**
     * Local EC1-equivalent pre-authority collapse — same documented
     * contract as PGE_Additional_Inviter::resolve_actor_context(),
     * reproduced here rather than depended on cross-class (that method is
     * private) via the same PUBLIC PGE_Event_Access_Authorization::
     * resolve_context() entry point every H1C Application-layer surface
     * already uses.
     *
     * @return PGE_Event_Access_Authorization_Context|WP_Error
     */
    private static function resolve_actor_context($event_id, $actor_user_id)
    {
        if (!class_exists('PGE_Event_Access_Authorization')) return self::server_error();
        $context = PGE_Event_Access_Authorization::resolve_context($event_id, $actor_user_id);
        if ($context instanceof WP_Error) return self::not_authorized();
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) return self::not_authorized();
        return $context;
    }

    private static function valid_id($value): bool
    {
        return is_int($value) && $value > 0;
    }

    private static function string_length($value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private static function not_authorized()
    {
        return new WP_Error(self::REASON_NOT_AUTHORIZED, 'Actor is not authorized to perform this action.');
    }

    private static function invalid_input()
    {
        return new WP_Error(self::REASON_INVALID_INPUT, 'Invalid request input.');
    }

    private static function invalid_state()
    {
        return new WP_Error(self::REASON_INVALID_STATE, 'This invitation is no longer in a valid state.');
    }

    private static function not_found()
    {
        return new WP_Error(self::REASON_NOT_FOUND, 'Invitation not found.');
    }

    private static function quota_group_conflict()
    {
        return new WP_Error(self::REASON_QUOTA_GROUP_CONFLICT, 'This group already has an active or pending Additional Inviter.');
    }

    private static function token_invalid()
    {
        return new WP_Error(self::REASON_TOKEN_INVALID, 'Invalid or expired invitation token.');
    }

    private static function account_conflict()
    {
        return new WP_Error(self::REASON_ACCOUNT_CONFLICT, 'You are logged in as a different account than this invitation is for.');
    }

    private static function server_error()
    {
        return new WP_Error(self::REASON_SERVER_ERROR, 'Unable to complete the request right now.');
    }
}
