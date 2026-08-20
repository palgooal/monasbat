<?php
/**
 * Phase H1C-W8 — Additional Inviter Quota & Scoped Guest Creation.
 *
 * Executes the REAL production classes (PGE_Event_Access_Repository,
 * PGE_Event_Access_Authorization[_Context], PGE_Additional_Inviter,
 * PGE_Invitation_Service, PGE_Invitation_Repository, event-guests.php,
 * rsvp-canonical-lookup.php) against a single fake $wpdb + in-memory
 * Post Meta store — no logical mirror of any of this phase's own new
 * code. Targeted, high-value coverage per the H1C-W8 brief's own
 * categories A-I, not an exhaustive suite.
 *
 * Run: php tests/test-h1c-w8-additional-inviter.php
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

final class WP_Error
{
    private $c; private $m;
    function __construct($c = '', $m = '') { $this->c = $c; $this->m = $m; }
    function get_error_code() { return $this->c; }
    function get_error_message() { return $this->m; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function code($v) { return $v instanceof WP_Error ? $v->get_error_code() : null; }

// ── WordPress primitive mocks ──────────────────────────────────────────
$GLOBALS['w8_posts'] = [10 => ['type' => 'pge_event', 'author' => 501]];
$GLOBALS['w8_admins'] = [];       // user_id => true
$GLOBALS['w8_users'] = [501 => true, 502 => true, 503 => true, 504 => true];
$GLOBALS['w8_ready'] = true;
$GLOBALS['w8_postmeta'] = [];     // "event_id|meta_key" => value
$GLOBALS['w8_user_meta'] = [];    // "user_id|meta_key" => value
$GLOBALS['w8_error_log'] = [];

function get_post_type($id) { return $GLOBALS['w8_posts'][$id]['type'] ?? false; }
function get_post_field($field, $id) { return $field === 'post_author' ? ($GLOBALS['w8_posts'][$id]['author'] ?? 0) : null; }
function user_can($user_id, $cap) { return $cap === 'administrator' && !empty($GLOBALS['w8_admins'][$user_id]); }
function get_userdata($id) { return !empty($GLOBALS['w8_users'][$id]) ? (object) ['ID' => $id] : false; }
function current_time($type, $gmt = false) { return '2026-08-20 10:00:00'; }
function wp_json_encode($v) { return json_encode($v); }
function sanitize_text_field($v) { return trim((string) $v); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function wp_unslash($v) { return $v; }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
if (!function_exists('mb_strpos')) { function mb_strpos($h, $n, $o = 0, $e = null) { return strpos((string) $h, (string) $n, $o); } }
function get_post_meta($post_id, $key, $single = false) {
    $v = $GLOBALS['w8_postmeta']["$post_id|$key"] ?? '';
    return $single ? $v : ($v === '' ? [] : [$v]);
}
function update_post_meta($post_id, $key, $value) { $GLOBALS['w8_postmeta']["$post_id|$key"] = $value; return true; }
function delete_post_meta($post_id, $key) { unset($GLOBALS['w8_postmeta']["$post_id|$key"]); return true; }
function get_user_meta($user_id, $key, $single = false) {
    $v = $GLOBALS['w8_user_meta']["$user_id|$key"] ?? '';
    return $single ? $v : ($v === '' ? [] : [$v]);
}
if (!function_exists('error_log')) { function error_log($m) { $GLOBALS['w8_error_log'][] = $m; } }
if (!function_exists('add_action')) { function add_action(...$a) { return true; } }
if (!function_exists('register_activation_hook')) { function register_activation_hook(...$a) { return true; } }
if (!function_exists('register_deactivation_hook')) { function register_deactivation_hook(...$a) { return true; } }
if (!function_exists('add_filter')) { function add_filter(...$a) { return true; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }
if (!function_exists('do_action')) { function do_action(...$a) { return null; } }
if (!function_exists('wp_send_json_error')) { function wp_send_json_error($d = null) { throw new Exception('wp_send_json_error called unexpectedly in W8 test: ' . print_r($d, true)); } }
if (!function_exists('wp_send_json_success')) { function wp_send_json_success($d = null) { throw new Exception('wp_send_json_success called unexpectedly in W8 test'); } }
// pge_resolve_guest_quota_status() (helpers.php, real/unmodified — the
// PACKAGE-level guest quota this suite's E-category tests keep distinct
// from the NEW inviter quota) calls this to read the event owner's package
// guest_limit. Its real implementation lives in event-factory.php, which
// this focused W8 suite deliberately does not load (out of scope — W8 does
// not touch package/plan resolution). Stubbed here so package-quota
// interaction (Section 17) can still be exercised: tests set
// $GLOBALS['w8_user_meta']["<owner_id>|_mon_guest_limit"] directly.
if (!function_exists('pge_get_user_plan_limits_for_events')) {
    function pge_get_user_plan_limits_for_events($user_id) {
        return ['guest_limit' => (int) ($GLOBALS['w8_user_meta']["$user_id|_mon_guest_limit"] ?? 0)];
    }
}

final class PGE_Event_Access_Schema { static function is_ready() { return $GLOBALS['w8_ready']; } static function maybe_upgrade() { return false; } }

// ── Fake $wpdb: routes by table substring, same convention as the
//    existing H1B-W2/H1B-W3 repository test harnesses. ──────────────────
final class PGE_W8_WPDB
{
    public $prefix = 'w8_';
    public $last_error = '';
    public $insert_id = 0;
    public $groups = [];
    public $memberships = [];
    public $access = [];
    public $assignments = [];
    public $audits = [];
    public $rsvps = [];
    public $sql = [];
    public $fail = [];
    private $prepared = [];
    private $seq = 0;
    private $snapshot = null;

    function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $t = '__W8_' . (++$this->seq) . '__';
        $this->prepared[$t] = [$sql, $args];
        return $t;
    }
    private function resolve($q) { return $this->prepared[$q] ?? [$q, []]; }

    function query($q) {
        [$s, $a] = $this->resolve($q);
        $this->sql[] = $s;
        if ($s === 'START TRANSACTION') {
            $this->snapshot = [$this->groups, $this->memberships, $this->access, $this->assignments, $this->audits, $this->insert_id];
            return 0;
        }
        if ($s === 'COMMIT') { $this->snapshot = null; return 0; }
        if ($s === 'ROLLBACK') {
            if ($this->snapshot !== null) {
                [$this->groups, $this->memberships, $this->access, $this->assignments, $this->audits, $this->insert_id] = $this->snapshot;
            }
            $this->snapshot = null;
            return 0;
        }
        $this->last_error = '';

        if (strpos($s, 'INSERT INTO w8_pge_event_host_memberships') === 0) {
            if (!empty($this->fail['insert_membership'])) { $this->last_error = 'x'; return false; }
            $id = $this->memberships ? max(array_keys($this->memberships)) + 1 : 1;
            $this->insert_id = $id;
            $this->memberships[$id] = [
                'id' => (string) $id, 'event_id' => (string) $a[0], 'user_id' => (string) $a[1],
                'role' => $a[2], 'status' => $a[3], 'allocated_quota' => (string) $a[4],
                'created_by_user_id' => (string) $a[5], 'activated_at' => $a[6], 'revoked_at' => null,
                'created_at' => $a[7], 'updated_at' => $a[8],
            ];
            return 1;
        }
        if (strpos($s, 'INSERT INTO w8_pge_event_host_group_access') === 0) {
            if (!empty($this->fail['insert_access'])) { $this->last_error = 'x'; return false; }
            $id = $this->access ? max(array_keys($this->access)) + 1 : 1;
            $this->insert_id = $id;
            $this->access[$id] = ['id' => (string) $id, 'event_id' => (string) $a[0], 'membership_id' => (string) $a[1], 'group_id' => (string) $a[2], 'granted_by_user_id' => (string) $a[3], 'created_at' => $a[4]];
            return 1;
        }
        if (strpos($s, 'INSERT INTO w8_pge_invitation_group_assignments') === 0) {
            if (!empty($this->fail['insert_assignment'])) { $this->last_error = 'x'; return false; }
            $id = $this->assignments ? max(array_keys($this->assignments)) + 1 : 1;
            $this->insert_id = $id;
            $this->assignments[$id] = ['id' => (string) $id, 'event_id' => (string) $a[0], 'guest_phone' => $a[1], 'group_id' => (string) $a[2], 'assigned_by_user_id' => (string) $a[3], 'created_at' => $a[4], 'updated_at' => $a[4]];
            return 1;
        }
        if (strpos($s, 'INSERT INTO w8_pge_event_access_audit_log') === 0) {
            if (!empty($this->fail['insert_audit'])) { $this->last_error = 'x'; return false; }
            $meta = strpos($s, 'NULL, %s)') === false ? json_decode($a[5], true) : null;
            $this->audits[] = ['event_id' => $a[0], 'actor' => $a[1], 'action' => $a[2], 'entity_type' => $a[3], 'entity_id' => $a[4], 'metadata' => $meta];
            return 1;
        }
        if (strpos($s, 'UPDATE w8_pge_event_host_memberships') === 0 && strpos($s, 'allocated_quota') !== false) {
            // set_membership_quota(): args are [?new_quota, updated_at, event_id,
            // id, status, ?expected_quota] — the leading new_quota slot and the
            // trailing expected_quota slot are each present only when their
            // respective SQL fragment is non-NULL, so the id's position must be
            // derived from the KNOWN leading offset (set_count), not counted
            // back from the end of $a (which shifts when expected_quota is NULL
            // and $where_args is empty — this bit the very first NULL-expected
            // raise-to-quota-enabled case: fixed this Fix Pass round).
            $expects_null = strpos($s, 'AND allocated_quota IS NULL') !== false;
            $sets_null = strpos($s, 'SET allocated_quota = NULL') !== false;
            $set_count = $sets_null ? 0 : 1;
            $id_arg = $a[$set_count + 2] ?? null;
            $id = null;
            foreach ($this->memberships as $mid => $row) { if ((int) $row['id'] === (int) $id_arg) { $id = $mid; break; } }
            if ($id === null) return 0;
            $row = &$this->memberships[$id];
            $last = end($a);
            if ($expects_null) { if ($row['allocated_quota'] !== null && $row['allocated_quota'] !== '') return 0; }
            else { if ((string) $row['allocated_quota'] !== (string) $last) return 0; }
            $row['allocated_quota'] = $sets_null ? null : (string) ($a[0] ?? null);
            $row['updated_at'] = $a[$set_count] ?? null;
            return 1;
        }
        if (strpos($s, 'UPDATE w8_pge_event_host_memberships') === 0 && strpos($s, 'revoked_at = NULL') !== false) {
            // reactivate_membership(): SET status=%s, role=%s, activated_at=%s,
            // revoked_at=NULL, updated_at=%s WHERE event_id=%d AND id=%d AND status=%s
            [$new_status, $new_role, $activated_at, $updated_at, $ev, $mid_arg, $expected_status] = $a;
            $id = null;
            foreach ($this->memberships as $mid => $row) {
                if ((int) $row['id'] === (int) $mid_arg && (int) $row['event_id'] === (int) $ev) { $id = $mid; break; }
            }
            if ($id === null) return 0;
            $row = &$this->memberships[$id];
            if ($row['status'] !== $expected_status) return 0;
            $row['status'] = $new_status;
            $row['role'] = $new_role;
            $row['activated_at'] = $activated_at;
            $row['revoked_at'] = null;
            $row['updated_at'] = $updated_at;
            return 1;
        }
        if (strpos($s, 'UPDATE w8_pge_event_host_memberships') === 0 && strpos($s, 'revoked_at = %s') !== false) {
            // revoke_membership(): SET status=%s, revoked_at=%s, updated_at=%s
            // WHERE event_id=%d AND id=%d AND status=%s
            [$new_status, $revoked_at, $updated_at, $ev, $mid_arg, $expected_status] = $a;
            $id = null;
            foreach ($this->memberships as $mid => $row) {
                if ((int) $row['id'] === (int) $mid_arg && (int) $row['event_id'] === (int) $ev) { $id = $mid; break; }
            }
            if ($id === null) return 0;
            $row = &$this->memberships[$id];
            if ($row['status'] !== $expected_status) return 0;
            $row['status'] = $new_status;
            $row['revoked_at'] = $revoked_at;
            $row['updated_at'] = $updated_at;
            return 1;
        }
        if (strpos($s, 'UPDATE w8_pge_event_host_memberships') === 0 && strpos($s, 'SET role = %s, updated_at = %s') !== false) {
            // change_membership_role(): SET role=%s, updated_at=%s WHERE
            // event_id=%d AND id=%d AND status=%s AND role=%s
            [$new_role, $updated_at, $ev, $mid_arg, $expected_status, $expected_role] = $a;
            $id = null;
            foreach ($this->memberships as $mid => $row) {
                if ((int) $row['id'] === (int) $mid_arg && (int) $row['event_id'] === (int) $ev) { $id = $mid; break; }
            }
            if ($id === null) return 0;
            $row = &$this->memberships[$id];
            if ($row['status'] !== $expected_status || $row['role'] !== $expected_role) return 0;
            $row['role'] = $new_role;
            $row['updated_at'] = $updated_at;
            return 1;
        }
        $this->last_error = 'unexpected write: ' . $s;
        return false;
    }

    function get_results($q, $fmt = null) {
        [$s, $a] = $this->resolve($q);
        $this->sql[] = $s;
        $this->last_error = '';
        // JOIN queries reference two table names at once — must be routed BEFORE
        // the generic single-table branches below (whose substring checks would
        // otherwise also match these), and distinguished from EACH OTHER by
        // their FROM table, since several share the same "...JOIN w8_pge_event_
        // invitation_groups" suffix.
        if (strpos($s, 'FROM w8_pge_event_host_group_access a LEFT JOIN w8_pge_event_invitation_groups') !== false) {
            // list_group_ids_for_membership: access a JOIN groups g
            $event_id = $a[0] ?? null; $membership_id = $a[1] ?? null;
            $rows = array_values(array_filter($this->access, fn($r) => (int) $r['event_id'] === (int) $event_id && (int) $r['membership_id'] === (int) $membership_id));
            usort($rows, fn($x, $y) => (int) $x['group_id'] <=> (int) $y['group_id']);
            $out = [];
            foreach ($rows as $r) {
                $group = $this->groups[(int) $r['group_id']] ?? null;
                $out[] = ['event_id' => $r['event_id'], 'group_id' => $r['group_id'], 'related_event_id' => $group['event_id'] ?? null];
            }
            return $out;
        }
        if (strpos($s, 'FROM w8_pge_event_host_group_access a LEFT JOIN w8_pge_event_host_memberships') !== false) {
            // list_membership_ids_for_group: access a JOIN memberships m
            $event_id = $a[0] ?? null; $group_id = $a[1] ?? null;
            $rows = array_values(array_filter($this->access, fn($r) => (int) $r['event_id'] === (int) $event_id && (int) $r['group_id'] === (int) $group_id));
            usort($rows, fn($x, $y) => (int) $x['membership_id'] <=> (int) $y['membership_id']);
            $out = [];
            foreach ($rows as $r) {
                $member = $this->memberships[(int) $r['membership_id']] ?? null;
                $out[] = ['event_id' => $r['event_id'], 'membership_id' => $r['membership_id'], 'related_event_id' => $member['event_id'] ?? null];
            }
            return $out;
        }
        if (strpos($s, 'FROM w8_pge_invitation_group_assignments a LEFT JOIN w8_pge_event_invitation_groups') !== false) {
            // list_group_assignments / list_group_assignments_for_groups: assignments a JOIN groups g
            $event_id = $a[0] ?? null;
            $rows = array_values(array_filter($this->assignments, fn($r) => (int) $r['event_id'] === (int) $event_id));
            if (strpos($s, 'group_id IN') !== false) {
                // list_group_assignments_for_groups: remaining args (besides event_id
                // and, if LIMIT present, the trailing per_page/offset) are group ids.
                $group_args = $a;
                array_shift($group_args); // drop event_id
                if (strpos($s, 'LIMIT') !== false && count($group_args) >= 2) {
                    $group_args = array_slice($group_args, 0, count($group_args) - 2);
                }
                $group_ids = array_map('intval', $group_args);
                $rows = array_values(array_filter($rows, fn($r) => in_array((int) $r['group_id'], $group_ids, true)));
            } elseif (isset($a[1]) && strpos($s, 'group_id = %d') !== false) {
                $rows = array_values(array_filter($rows, fn($r) => (int) $r['group_id'] === (int) $a[1]));
            }
            usort($rows, fn($x, $y) => (int) $x['id'] <=> (int) $y['id']);
            if (strpos($s, 'LIMIT') !== false && count($a) >= 2) {
                $per_page = (int) $a[count($a) - 2];
                $offset = (int) $a[count($a) - 1];
                $rows = array_slice($rows, $offset, $per_page);
            }
            $out = [];
            foreach ($rows as $r) {
                $group = $this->groups[(int) $r['group_id']] ?? null;
                $out[] = $r + ['related_event_id' => $group['event_id'] ?? null];
            }
            return $out;
        }
        if (strpos($s, 'w8_pge_event_invitation_groups') !== false) {
            $rows = array_values(array_filter($this->groups, fn($r) => (int) $r['event_id'] === $a[0]));
            if (isset($a[1]) && strpos($s, 'id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $a[1]));
            return $rows;
        }
        if (strpos($s, 'w8_pge_event_host_memberships') !== false) {
            $rows = array_values(array_filter($this->memberships, fn($r) => (int) $r['event_id'] === $a[0]));
            if (isset($a[1]) && strpos($s, 'user_id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['user_id'] === $a[1]));
            elseif (isset($a[1]) && strpos($s, 'id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $a[1]));
            usort($rows, fn($x, $y) => (int) $x['id'] <=> (int) $y['id']);
            return $rows;
        }
        if (strpos($s, 'w8_pge_event_host_group_access') !== false) {
            $rows = array_values(array_filter($this->access, fn($r) => (int) $r['event_id'] === $a[0]));
            $has_member = strpos($s, 'membership_id = %d') !== false;
            if ($has_member && isset($a[1])) $rows = array_values(array_filter($rows, fn($r) => (int) $r['membership_id'] === $a[1]));
            $group_arg = $has_member ? 2 : 1;
            if (isset($a[$group_arg]) && strpos($s, 'group_id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['group_id'] === $a[$group_arg]));
            usort($rows, fn($x, $y) => (int) $x['id'] <=> (int) $y['id']);
            return $rows;
        }
        if (strpos($s, 'w8_pge_invitation_group_assignments') !== false) {
            $rows = array_values(array_filter($this->assignments, fn($r) => (int) $r['event_id'] === $a[0]));
            $arg_i = 1;
            if (strpos($s, 'group_id = %d') !== false && isset($a[$arg_i])) {
                $rows = array_values(array_filter($rows, fn($r) => (int) $r['group_id'] === $a[$arg_i]));
                $arg_i++;
            }
            if (strpos($s, 'guest_phone = %s') !== false && isset($a[$arg_i])) {
                $phone = $a[$arg_i];
                $rows = array_values(array_filter($rows, fn($r) => (string) $r['guest_phone'] === (string) $phone));
                $arg_i++;
            }
            usort($rows, fn($x, $y) => (int) $x['id'] <=> (int) $y['id']);
            // Only "LIMIT %d OFFSET %d" (paginate()) consumes trailing bind
            // args for the limit — a literal "LIMIT 2"/"LIMIT N" in the SQL
            // text (lock/read-single-row helpers) has no bound placeholder,
            // so every WHERE arg has already been consumed above and none
            // remain for pagination.
            if (strpos($s, 'OFFSET %d') !== false && count($a) >= 2) {
                $per_page = (int) $a[count($a) - 2];
                $offset = (int) $a[count($a) - 1];
                $rows = array_slice($rows, $offset, $per_page);
            } elseif (preg_match('/LIMIT\s+(\d+)\s*$/', $s, $lm)) {
                $rows = array_slice($rows, 0, (int) $lm[1]);
            }
            return $rows;
        }
        if (strpos($s, 'w8_pge_event_rsvps') !== false) {
            $event_id = $a[0] ?? null;
            if (strpos($s, 'guest_phone = %s') !== false) {
                // pge_rsvp_find_canonical_by_phone(): per-phone lookup, wants objects.
                $phone = $a[1] ?? null;
                $rows = array_values(array_filter($this->rsvps, fn($r) => (int) $r['event_id'] === (int) $event_id && (string) $r['guest_phone'] === (string) $phone));
                if ($fmt === ARRAY_A) return $rows;
                return array_map(fn($r) => (object) $r, $rows);
            }
            // pge_event_guests_load_rsvp_from_db(): whole-event load, ARRAY_A.
            $rows = array_values(array_filter($this->rsvps, fn($r) => (int) $r['event_id'] === (int) $event_id));
            if ($fmt === ARRAY_A) return $rows;
            return array_map(fn($r) => (object) $r, $rows);
        }
        $this->last_error = 'unexpected read: ' . $s;
        return null;
    }

    function get_var($q) {
        [$s, $a] = $this->resolve($q);
        $this->sql[] = $s;
        if (strpos($s, 'GET_LOCK') === 0 || strpos($s, 'GET_LOCK') !== false) return 1;
        if (strpos($s, 'RELEASE_LOCK') !== false) return 1;
        if (strpos($s, 'COUNT(*)') !== false && strpos($s, 'w8_pge_invitation_group_assignments') !== false) {
            $rows = array_values(array_filter($this->assignments, fn($r) => (int) $r['event_id'] === $a[0] && (int) $r['group_id'] === $a[1]));
            return count($rows);
        }
        return null;
    }

    function update($table, $data, $where) {
        // Only reached by RSVP-lifecycle-reset on an EXISTING row — this
        // suite deliberately uses fresh, never-before-seen phone numbers
        // for every create_scoped_guest test, so this path is not expected
        // to be exercised; return failure loudly if it ever is.
        $this->last_error = 'unexpected update';
        return false;
    }

    function delete($table, $where, $formats) {
        // revoke_membership()'s access-row cleanup: $wpdb->delete($access_table,
        // ['event_id'=>..., 'membership_id'=>...], ['%d','%d']) — real WordPress
        // $wpdb->delete() shape (associative WHERE array), not raw SQL.
        if (strpos((string) $table, 'w8_pge_event_host_group_access') !== false) {
            $removed = 0;
            foreach ($this->access as $id => $row) {
                $match = true;
                foreach ($where as $col => $val) {
                    if ((string) ($row[$col] ?? null) !== (string) $val) { $match = false; break; }
                }
                if ($match) { unset($this->access[$id]); $removed++; }
            }
            return $removed;
        }
        $this->last_error = 'unexpected delete: ' . $table;
        return false;
    }

    // PGE_Invitation_Management_Audit::record() (existing, unmodified,
    // pre-W8 production audit trail for guest-lifecycle actions — separate
    // from the H1C event-access audit log this suite exercises directly via
    // $this->audits) writes here. W8 does not assert on its content, so a
    // successful no-op insert is sufficient.
    function insert($table, $data, $formats = null) { $this->insert_id = ++$this->seq; return 1; }
}

require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';
require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';
require_once PGE_PATH . 'includes/helpers.php';
require_once PGE_PATH . 'includes/event-guests.php';
require_once PGE_PATH . 'includes/rsvp-canonical-lookup.php';
require_once PGE_PATH . 'includes/class-pge-invitation-management-schema.php';
require_once PGE_PATH . 'includes/class-pge-invitation-management-audit.php';
require_once PGE_PATH . 'includes/class-pge-invitation-repository.php';
require_once PGE_PATH . 'includes/class-pge-invitation-service.php';
require_once PGE_PATH . 'includes/class-pge-additional-inviter.php';

$pass = 0; $fail = 0; $failures = [];
function ok($l, $v) { global $pass, $fail, $failures; if ($v) { $pass++; echo "PASS: $l\n"; } else { $fail++; $failures[] = $l; echo "FAIL: $l\n"; } }

function w8_group($id, $status = 'active', $event = 10) {
    return ['id' => (string) $id, 'event_id' => (string) $event, 'name' => 'Group ' . $id, 'name_key' => $status === 'active' ? 'group ' . $id : null, 'status' => $status, 'default_slot' => null, 'created_by_user_id' => '501', 'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00', 'archived_at' => null];
}
function w8_member($id, $user, $role = 'manager', $status = 'active', $quota = null, $revoked = null, $event = 10) {
    return ['id' => (string) $id, 'event_id' => (string) $event, 'user_id' => (string) $user, 'role' => $role, 'status' => $status, 'allocated_quota' => $quota === null ? null : (string) $quota, 'created_by_user_id' => '501', 'activated_at' => '2026-08-01 00:00:00', 'revoked_at' => $revoked, 'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00'];
}
function w8_access($id, $member, $group, $event = 10) {
    return ['id' => (string) $id, 'event_id' => (string) $event, 'membership_id' => (string) $member, 'group_id' => (string) $group, 'granted_by_user_id' => '501', 'created_at' => '2026-08-01 00:00:00'];
}
function w8_assignment($id, $group, $phone, $event = 10) {
    return ['id' => (string) $id, 'event_id' => (string) $event, 'guest_phone' => $phone, 'group_id' => (string) $group, 'assigned_by_user_id' => '501', 'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00'];
}
function w8_rsvp($event, $phone, $reply, $checked_in = 0) {
    return ['event_id' => $event, 'guest_phone' => $phone, 'reply' => $reply, 'checked_in' => $checked_in];
}
function w8_seed_guest($event_id, $phone, $name, $invitation_status = 'active') {
    $map_key = "$event_id|_pge_invited_guests";
    $map = $GLOBALS['w8_postmeta'][$map_key] ?? [];
    $map[$phone] = ['phone' => $phone, 'name' => $name, 'note' => '', 'code' => ''];
    $GLOBALS['w8_postmeta'][$map_key] = $map;
    $status_key = "$event_id|_pge_invitation_status";
    $status = $GLOBALS['w8_postmeta'][$status_key] ?? [];
    $status[$phone] = ['status' => $invitation_status, 'invited_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00'];
    $GLOBALS['w8_postmeta'][$status_key] = $status;
}

// pge_event_guests_load_rsvp_from_db() (production code, unmodified) caches
// its per-event RSVP map in a function-local static across the ENTIRE test
// process — there is no reset hook, by design (it's a real request-scoped
// cache in production, not a test seam). So every fixture that will be read
// through the RSVP/quota path MUST use a brand-new event_id the very first
// time it's touched, or it will silently see a PREVIOUS fixture's stale
// cached RSVP map instead of its own. w8_new_event() hands out a fresh id
// per fixture for exactly this reason.
$GLOBALS['w8_event_seq'] = 10;
function w8_new_event() { return ++$GLOBALS['w8_event_seq']; }

function fresh($memberships = [], $groups = [], $access = [], $assignments = [], $rsvps = [], $event_id = 10) {
    global $wpdb;
    $wpdb = new PGE_W8_WPDB();
    foreach ($memberships as $r) $wpdb->memberships[(int) $r['id']] = $r;
    foreach ($groups as $r) $wpdb->groups[(int) $r['id']] = $r;
    foreach ($access as $r) $wpdb->access[(int) $r['id']] = $r;
    foreach ($assignments as $r) $wpdb->assignments[(int) $r['id']] = $r;
    foreach ($rsvps as $r) $wpdb->rsvps[] = $r;
    $GLOBALS['w8_ready'] = true;
    $GLOBALS['w8_posts'] = [(int) $event_id => ['type' => 'pge_event', 'author' => 501]];
    $GLOBALS['w8_admins'] = [];
    $GLOBALS['w8_users'] = [501 => true, 502 => true, 503 => true, 504 => true];
    $GLOBALS['w8_postmeta'] = [];
    $GLOBALS['w8_user_meta'] = [];
    $GLOBALS['w8_error_log'] = [];
    return $wpdb;
}

// ============================================================================
// A — Schema
// ============================================================================
$schema_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-schema.php');
ok('A1. allocated_quota column declared in table_specs()', strpos($schema_source, "'allocated_quota'") !== false);
ok('A2. allocated_quota is NULL DEFAULT NULL (not a required column)', strpos($schema_source, 'allocated_quota INT(10) UNSIGNED NULL DEFAULT NULL') !== false);
ok('A3. SCHEMA_VERSION bumped from 1.1.0', strpos($schema_source, "SCHEMA_VERSION = '1.2.0'") !== false);
$db = fresh([w8_member(1, 502)]);
$m = PGE_Event_Access_Repository::get_membership(10, 1);
ok('A4. pre-W8 membership (no allocated_quota set) reads back as NULL', is_array($m) && $m['allocated_quota'] === null);
ok('A5. pre-W8 membership retains its W1-W7 shape (role/status readable unchanged)', is_array($m) && $m['role'] === 'manager' && $m['status'] === 'active');

// ============================================================================
// B — Additional Inviter creation (atomicity + authorization)
// ============================================================================
$db = fresh([], [w8_group(1)]);
$GLOBALS['w8_admins'] = []; // actor 501 is Owner (post_author)
$r = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 501, 502, 1, 100);
ok('B1. Owner creates Additional Inviter successfully', is_array($r) && $r['ok'] === true && $r['role'] === 'manager' && $r['allocated_quota'] === 100 && $r['group_id'] === 1);
ok('B1b. exactly one group access row created', count($db->access) === 1 && (int) $db->access[1]['group_id'] === 1);
ok('B1c. membership_created audit has no PII, includes quota', $db->audits[0]['action'] === 'membership_created' && $db->audits[0]['metadata'] === ['role' => 'manager', 'allocated_quota' => 100]);
ok('B1d. group_access_granted audit recorded', $db->audits[1]['action'] === 'group_access_granted');

$db = fresh([], [w8_group(1)]);
$GLOBALS['w8_admins'] = [999 => true];
$r = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 999, 502, 1, 100);
ok('B2. Administrator (non-owner) creates Additional Inviter successfully', is_array($r) && $r['ok'] === true);

$db = fresh([w8_member(5, 601, 'manager')], [w8_group(1)]);
$GLOBALS['w8_users'][601] = true;
$r = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 601, 502, 1, 100);
ok('B3. Manager cannot create an Additional Inviter', code($r) === 'not_authorized' && $db->memberships === [5 => w8_member(5, 601, 'manager')] && $db->access === []);

$db = fresh([w8_member(5, 601, 'viewer')], [w8_group(1)]);
$r = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 601, 502, 1, 100);
ok('B4. Viewer cannot create an Additional Inviter', code($r) === 'not_authorized');

$db = fresh([], [w8_group(1)]);
$r = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 999999, 502, 1, 100);
ok('B5. A stranger (no membership at all) cannot create an Additional Inviter', code($r) === 'not_authorized');

$db = fresh([], [w8_group(1)]);
$r = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 501, 502, 1, 100);
ok('B6. role is forced to manager (product contract, not caller-supplied)', is_array($r) && $r['role'] === 'manager');

$db = fresh([], [w8_group(1)]);
$db->fail['insert_access'] = true;
$r = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 501, 502, 1, 100);
ok('B7. Atomicity: access-insert failure rolls the whole operation back (no orphaned membership)', $r instanceof WP_Error && $db->memberships === [] && $db->access === [] && $db->audits === []);

$db = fresh([], [w8_group(1), w8_group(2)]);
$first = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 501, 502, 1, 100);
$second = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 501, 502, 1, 60);
ok('B8. duplicate target user in same event is rejected (no double membership)', is_array($first) && code($second) === 'duplicate' && count($db->memberships) === 1);
$dup = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 501, 502, 2, 999);
ok('B8b. duplicate is rejected even against a different group (per-event, not per-group)', code($dup) === 'duplicate' && count($db->memberships) === 1);

// ============================================================================
// C — Quota resolver classification + D — boundary
// ============================================================================
function w8_quota_fixture($occupied_reply_pairs, $quota = 5) {
    $event_id = w8_new_event();
    $mships = [w8_member(1, 502, 'manager', 'active', $quota, null, $event_id)];
    $groups = [w8_group(1, 'active', $event_id)];
    $access = [w8_access(1, 1, 1, $event_id)];
    $assignments = []; $rsvps = [];
    $i = 1;
    foreach ($occupied_reply_pairs as [$phone, $reply, $invstatus]) {
        $assignments[] = w8_assignment($i, 1, $phone, $event_id);
        if ($reply !== null) $rsvps[] = w8_rsvp($event_id, $phone, $reply);
        $i++;
    }
    $db = fresh($mships, $groups, $access, $assignments, $rsvps, $event_id);
    foreach ($occupied_reply_pairs as [$phone, $reply, $invstatus]) {
        w8_seed_guest($event_id, $phone, 'Guest ' . $phone, $invstatus);
    }
    $GLOBALS['w8_fixture_event'] = $event_id;
    return $db;
}

$db = w8_quota_fixture([
    ['9700001', 'yes', 'active'],
    ['9700002', null, 'active'],   // no RSVP row at all -> pending
    ['9700003', 'no', 'active'],   // declined
    ['9700004', 'yes', 'cancelled'], // cancelled invitation, even though reply=yes
]);
$eid = $GLOBALS['w8_fixture_event'];
$status = PGE_Additional_Inviter::get_quota_status_for_owner($eid, 501, 1);
ok('C1. yes counts as attending', is_array($status) && $status['attending'] === 1);
ok('C2. missing RSVP row counts as pending', $status['pending'] === 1);
ok('C3. reply=no counts as declined, not occupied', $status['declined'] === 1);
ok('C4. cancelled invitation counts as cancelled, not occupied (even though reply was yes)', $status['cancelled'] === 1);
ok('C5. occupied = attending + pending only', $status['occupied'] === 2);
ok('D1. available = allocated - occupied, group name resolved', $status['available'] === 3 && $status['group_id'] === 1 && $status['group_name'] === 'Group 1');

$db = w8_quota_fixture([['9700010', 'yes', 'active'], ['9700011', 'yes', 'active']], 2);
$eid = $GLOBALS['w8_fixture_event'];
$status = PGE_Additional_Inviter::get_quota_status_for_owner($eid, 501, 1);
ok('D2. occupied == allocated -> available is exactly 0 (not negative)', $status['available'] === 0 && $status['occupied'] === 2);

$db = w8_quota_fixture([['9700020', 'no', 'active']], 1);
$eid = $GLOBALS['w8_fixture_event'];
$status = PGE_Additional_Inviter::get_quota_status_for_owner($eid, 501, 1);
ok('D3. a declined guest frees the slot (available returns to allocated)', $status['available'] === 1 && $status['occupied'] === 0);

$db = w8_quota_fixture([['9700030', 'yes', 'cancelled']], 1);
$eid = $GLOBALS['w8_fixture_event'];
$status = PGE_Additional_Inviter::get_quota_status_for_owner($eid, 501, 1);
ok('D4. a cancelled invitation frees the slot', $status['available'] === 1 && $status['occupied'] === 0);

// ============================================================================
// F — Scoped guest creation (Manager self-service)
// ============================================================================
// NOTE: every fixture below that reaches the quota resolver (directly or via
// create_scoped_guest_for_actor) uses its OWN fresh event_id from
// w8_new_event(). pge_event_guests_load_rsvp_from_db() (real, unmodified
// production code) caches its per-event RSVP map in a function-static for
// the lifetime of this PHP process with no reset hook — reusing event_id=10
// across fixtures would silently serve a PREVIOUS fixture's stale cached
// map instead of the current one.
$eid = w8_new_event();
$db = fresh([w8_member(1, 502, 'manager', 'active', 5, null, $eid)], [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid);
$r = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700100', 'Sara', '');
ok('F1. Manager creates a scoped guest into their own resolved group', is_array($r) && $r['ok'] === true && $r['group_id'] === 1 && $r['phone'] !== '');
ok('F2. the created guest is actually assigned to the group in H1C storage', count($db->assignments) === 1 && (int) reset($db->assignments)['group_id'] === 1);
ok('F3. W1 scoped listing (list_scoped_group_assignments) now sees the guest', (function () use ($db, $eid) {
    $ctx = PGE_Event_Access_Authorization::resolve_context($eid, 502);
    if ($ctx instanceof WP_Error) return false;
    $rows = PGE_Event_Access_Authorization::list_scoped_group_assignments($ctx);
    return is_array($rows) && ($rows['total'] ?? 0) === 1;
})());

$ajax_source = file_get_contents(PGE_PATH . 'includes/additional-inviter-ajax.php');
ok('F4. scoped guest creation handler never reads $_POST[group_id] (server resolves the group)', preg_match('/function pge_additional_inviter_create_guest_handler.*?(?=^\}|\Z)/ms', $ajax_source, $m2) === 1 && strpos($m2[0], "_POST['group_id']") === false);
ok('F5. no handler in this file reads an actor identity field from the client', preg_match("/_POST\\[.actor_user_id.\\]|_POST\\[.owner_id.\\]|_POST\\[.manager_id.\\]|_POST\\[.user_id.\\]/", $ajax_source) === 0);
ok('F6. no wp_ajax_nopriv_ variant is ever registered in this file', preg_match("/add_action\\(\\s*['\"]wp_ajax_nopriv_/", $ajax_source) === 0);

$eid = w8_new_event();
// Actor has exactly ONE granted group (group 1), per the Section 11.1 product
// contract. The pre-existing guest at phone 9700200 is ASSIGNED to a
// DIFFERENT, unrelated group (group 2, no access granted to this actor) —
// proving the duplicate error can't leak that other group's existence.
$db = fresh(
    [w8_member(1, 502, 'manager', 'active', 5, null, $eid)],
    [w8_group(1, 'active', $eid), w8_group(2, 'active', $eid)],
    [w8_access(1, 1, 1, $eid)],
    [w8_assignment(1, 2, '9700200', $eid)],
    [],
    $eid
);
w8_seed_guest($eid, '9700200', 'Existing Guest');
$r = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700200', 'Attempted Dup', '');
ok('F7. duplicate phone returns a safe generic error', code($r) === 'duplicate_guest');
ok('F7b. duplicate error message reveals no group/other-inviter detail', strpos($r->get_error_message(), 'group') === false && strpos($r->get_error_message(), 'Group') === false);

// ============================================================================
// E — Package quota interaction (kept distinct from inviter quota)
// ============================================================================
$eid = w8_new_event();
$db = fresh([w8_member(1, 502, 'manager', 'active', 10, null, $eid)], [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid);
$GLOBALS['w8_user_meta']['501|_mon_guest_limit'] = '1';
w8_seed_guest($eid, '9700300', 'Pre-existing package guest'); // occupies the 1-guest package limit already
$r = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700301', 'New Guest', '');
ok('E1. inviter has quota available but package limit is exhausted -> creation fails', code($r) === 'package_quota_exceeded');

$db = w8_quota_fixture([['9700310', 'yes', 'active']], 1); // inviter quota fully occupied (allocated=1)
$eid = $GLOBALS['w8_fixture_event'];
$GLOBALS['w8_user_meta']['501|_mon_guest_limit'] = '0'; // package unlimited
$r = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700311', 'New Guest', '');
ok('E2. inviter quota is exhausted even though package has room -> creation fails', code($r) === 'quota_exceeded');

$eid = w8_new_event();
$db = fresh([w8_member(1, 502, 'manager', 'active', 5, null, $eid)], [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid);
$GLOBALS['w8_user_meta']['501|_mon_guest_limit'] = '0';
$r = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700320', 'New Guest', '');
ok('E3. both inviter quota and package quota available -> creation succeeds', is_array($r) && $r['ok'] === true);

// ============================================================================
// G — Misconfiguration (Section 11.1 fail-closed)
// ============================================================================
$eid = w8_new_event();
$db = fresh([w8_member(1, 502, 'manager', 'active', 5, null, $eid)], [], [], [], [], $eid); // quota configured, zero groups
$r = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700400', 'X', '');
ok('G1. quota-configured Manager with ZERO granted groups fails closed (misconfigured)', code($r) === 'misconfigured');

$eid = w8_new_event();
$db = fresh([w8_member(1, 502, 'manager', 'active', 5, null, $eid)], [w8_group(1, 'active', $eid), w8_group(2, 'active', $eid)], [w8_access(1, 1, 1, $eid), w8_access(2, 1, 2, $eid)], [], [], $eid);
$r = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700401', 'X', '');
ok('G2. quota-configured Manager with MORE THAN ONE granted group fails closed (misconfigured)', code($r) === 'misconfigured');

$eid = w8_new_event();
$db = fresh([w8_member(1, 502, 'manager', 'active', null, null, $eid)], [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid);
$r = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700402', 'X', '');
ok('G3. ordinary Manager with allocated_quota=NULL is simply not_authorized for this feature', code($r) === 'not_authorized');

$eid = w8_new_event();
$db = fresh([w8_member(1, 502, 'manager', 'revoked', 5, '2026-08-15 00:00:00', $eid)], [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid);
$r = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700403', 'X', '');
ok('G4. revoked membership is not_authorized', code($r) === 'not_authorized');

// ============================================================================
// H — Concurrency (sequential-call proof of the CAS/lock outcome — see the
//     final report's honest concurrency-verification note; this proves the
//     serialization LOGIC, real cross-connection locking is verified
//     separately against a real database where noted).
// ============================================================================
$db = w8_quota_fixture([['9700500', 'yes', 'active']], 2); // allocated=2, occupied=1, one slot left
$eid = $GLOBALS['w8_fixture_event'];
$first = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700501', 'First', '');
$second = PGE_Additional_Inviter::create_scoped_guest_for_actor($eid, 502, '9700502', 'Second', '');
ok('H1. with exactly one slot left, the first of two sequential creates succeeds', is_array($first) && $first['ok'] === true);
ok('H2. and the second is rejected with quota_exceeded (exactly one succeeds)', code($second) === 'quota_exceeded');

// ============================================================================
// I — Privacy / enumeration
// ============================================================================
$db = fresh([], [w8_group(1)]);
$missing_event = PGE_Additional_Inviter::create_additional_inviter_for_actor(11, 501, 502, 1, 100); // event 11 does not exist
$denied_actor = PGE_Additional_Inviter::create_additional_inviter_for_actor(10, 999999, 502, 1, 100); // event exists, actor has no authority
ok('I1. nonexistent event and a denied real actor are publicly indistinguishable (both not_authorized)', code($missing_event) === 'not_authorized' && code($missing_event) === code($denied_actor));

$repo_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-repository.php');
ok('I2. membership_created audit metadata for Additional Inviter creation carries no phone/name key', strpos($repo_source, "'role' => 'manager', 'allocated_quota' => \$allocated_quota") !== false);
$inviter_source = file_get_contents(PGE_PATH . 'includes/class-pge-additional-inviter.php');
ok('I3. no guest phone/name is ever passed into an H1C audit metadata array in this new file', preg_match('/insert_audit\([^)]*phone|insert_audit\([^)]*\bname\b/i', $inviter_source) === 0);

// ============================================================================
// X — Fix Pass: Additional Inviter group exclusivity invariant
// (ONE GROUP -> AT MOST ONE ACTIVE quota-enabled Additional Inviter),
// revoke/replacement policy, the mandatory reactivation edge case, quota-
// setting validity, and the quota resolver's corrupt-data defense.
// ============================================================================

// X1 — the core invariant at the create path.
$eid = w8_new_event();
$db = fresh([], [w8_group(1, 'active', $eid)], [], [], [], $eid);
$first = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 502, 1, 100, 501);
$second = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 503, 1, 50, 501);
ok('X1. two Additional Inviters cannot both be bound to the SAME group (create path)', is_array($first) && $first['changed'] === true && code($second) === 'quota_group_conflict');
ok('X1b. the rejected second attempt leaves no membership behind for the second user', count(array_filter($db->memberships, fn($m) => (int) $m['user_id'] === 503)) === 0);

// X2 — the invariant is per-group, not per-event/global.
$eid = w8_new_event();
$db = fresh([], [w8_group(1, 'active', $eid), w8_group(2, 'active', $eid)], [], [], [], $eid);
$first = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 502, 1, 100, 501);
$second = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 503, 2, 50, 501);
ok('X2. Additional Inviters on DIFFERENT groups both succeed (invariant is per-group, not per-event)', is_array($first) && $first['changed'] === true && is_array($second) && $second['changed'] === true);

// X3 — an ordinary (non-quota) H1C membership is NOT restricted by the invariant.
$eid = w8_new_event();
$db = fresh(
    [w8_member(1, 502, 'manager', 'active', 100, null, $eid), w8_member(2, 503, 'viewer', 'active', null, null, $eid)],
    [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid
);
$r = PGE_Event_Access_Repository::grant_group_access($eid, 2, 1, 501);
ok('X3. an ordinary (non-quota) H1C membership CAN still be granted access to a group that already has an active quota-enabled Additional Inviter', is_array($r) && $r['changed'] === true);

// X4 — baseline W1-W7 sharing between two ordinary memberships is unaffected.
$eid = w8_new_event();
$db = fresh(
    [w8_member(1, 503, 'manager', 'active', null, null, $eid), w8_member(2, 504, 'viewer', 'active', null, null, $eid)],
    [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid
);
$r = PGE_Event_Access_Repository::grant_group_access($eid, 2, 1, 501);
ok('X4. two ordinary (non-quota) H1C memberships can still share the same group (W1-W7 baseline unaffected)', is_array($r) && $r['changed'] === true);

// X5 — Revoke/Replacement policy: revoking the active inviter frees the group.
$eid = w8_new_event();
$db = fresh([], [w8_group(1, 'active', $eid)], [], [], [], $eid);
$first = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 502, 1, 100, 501);
$revoke = PGE_Event_Access_Repository::revoke_membership($eid, $first['membership']['id'], 501);
$replacement = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 503, 1, 60, 501);
ok('X5. revoking the active quota-enabled inviter on a group allows Owner/Admin to create a NEW Additional Inviter on that SAME group', is_array($revoke) && $revoke['changed'] === true && is_array($replacement) && $replacement['changed'] === true);

// X6 — Reactivation edge case, defense-in-depth: a revoked+quota-enabled
// membership that (per real production behavior) should have zero access
// rows, but is fabricated here WITH leftover access on a group that also
// has another active quota-enabled inviter (simulating legacy/corrupt
// data) — reactivate_membership() must fail closed and must never end up
// activating a second quota-enabled inviter onto that group. Note: this
// fires the PRE-EXISTING "$access_rows !== [] -> database_error()"
// integrity guard, not the newly added exclusivity guard specifically —
// see the Final Report's honest account of why the new guard is currently
// unreachable under normal production call sequences.
$eid = w8_new_event();
$db = fresh(
    [w8_member(1, 502, 'manager', 'revoked', 100, '2026-08-15 00:00:00', $eid), w8_member(2, 503, 'manager', 'active', 50, null, $eid)],
    [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid), w8_access(2, 2, 1, $eid)], [], [], $eid
);
$r = PGE_Event_Access_Repository::reactivate_membership($eid, 1, 'manager', 501);
ok('X6. reactivate() on a membership with corrupt leftover group access on a group that already has another active quota-enabled inviter fails closed (never activates a second one)', $r instanceof WP_Error);
ok('X6b. membership 1 remains revoked after the failed reactivate attempt (no partial state change)', $db->memberships[1]['status'] === 'revoked');

// X7 — Reactivation edge case, mandatory scenario (Section 6): the real,
// reachable production sequence revoke(A) -> create(B) -> reactivate(A)
// on the SAME group must never leave A and B both active+quota-enabled on
// it. reactivate_membership() never re-adds access on its own (see the
// Repository's own W1-W7 contract), so A comes back with ZERO granted
// groups — the sequence is safe through reactivate() alone. The residual
// gap (a SEPARATE grant_group_access() call after this point) is a real,
// separately-tracked limitation — see the Final Report.
$eid = w8_new_event();
$db = fresh([], [w8_group(1, 'active', $eid)], [], [], [], $eid);
$a = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 502, 1, 100, 501);
$a_id = $a['membership']['id'];
PGE_Event_Access_Repository::revoke_membership($eid, $a_id, 501);
$b = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 503, 1, 60, 501);
$reactivate = PGE_Event_Access_Repository::reactivate_membership($eid, $a_id, 'manager', 501);
ok('X7. mandatory sequence revoke(A) -> create(B) -> reactivate(A) on the same group never leaves two active quota-enabled inviters on it', is_array($b) && $b['changed'] === true && is_array($reactivate) && $reactivate['changed'] === true);
$a_groups = PGE_Event_Access_Repository::list_group_ids_for_membership($eid, $a_id);
ok('X7b. reactivated A holds ZERO granted groups afterwards (reactivate() never re-adds access on its own)', is_array($a_groups) && count($a_groups) === 0);

// X8 — Revoke/Replacement policy: the revoked inviter's guests/assignments/
// group all persist; only the membership's own access is removed.
$eid = w8_new_event();
$db = fresh([], [w8_group(1, 'active', $eid)], [], [], [], $eid);
$a = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 502, 1, 100, 501);
$a_id = $a['membership']['id'];
$db->assignments[1] = w8_assignment(1, 1, '9700900', $eid);
w8_seed_guest($eid, '9700900', 'Persisted Guest');
PGE_Event_Access_Repository::revoke_membership($eid, $a_id, 501);
ok('X8. revoking the Additional Inviter leaves the group itself intact', isset($db->groups[1]) && $db->groups[1]['status'] === 'active');
ok('X8b. ...leaves the guest group assignment intact', isset($db->assignments[1]) && $db->assignments[1]['guest_phone'] === '9700900');
ok('X8c. ...but does remove the revoked membership\'s own group access', count(PGE_Event_Access_Repository::list_group_ids_for_membership($eid, $a_id)) === 0);

// X9 — Quota-setting validity: raising an ordinary membership to
// quota-enabled must go through the same exclusivity check.
$eid = w8_new_event();
$db = fresh(
    [w8_member(1, 502, 'manager', 'active', 100, null, $eid), w8_member(2, 503, 'manager', 'active', null, null, $eid)],
    [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid), w8_access(2, 2, 1, $eid)], [], [], $eid
);
$r = PGE_Event_Access_Repository::set_membership_quota($eid, 2, null, 50, 501);
ok('X9. set_membership_quota() raising to quota-enabled fails with quota_group_conflict when the membership\'s group already has another active quota-enabled inviter', code($r) === 'quota_group_conflict');
ok('X9b. the membership\'s quota is left unchanged after the rejected attempt (fail closed, no partial write)', $db->memberships[2]['allocated_quota'] === null);

// X10 — the same call succeeds when there is no conflict.
$eid = w8_new_event();
$db = fresh([w8_member(1, 503, 'manager', 'active', null, null, $eid)], [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid);
$r = PGE_Event_Access_Repository::set_membership_quota($eid, 1, null, 50, 501);
ok('X10. set_membership_quota() raising to quota-enabled succeeds when its granted group has no conflicting active inviter', is_array($r) && $r['changed'] === true && $r['membership']['allocated_quota'] === 50);

// X11 — setting quota back to NULL never silently changes role or group access.
$eid = w8_new_event();
$db = fresh([w8_member(1, 502, 'manager', 'active', 100, null, $eid)], [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid);
$r = PGE_Event_Access_Repository::set_membership_quota($eid, 1, 100, null, 501);
ok('X11. setting quota to NULL succeeds and never auto-changes the membership\'s role', is_array($r) && $r['changed'] === true && $r['membership']['role'] === 'manager' && $r['membership']['allocated_quota'] === null);
ok('X11b. ...and never auto-revokes/removes the membership\'s existing group access as a side effect', count(PGE_Event_Access_Repository::list_group_ids_for_membership($eid, 1)) === 1);

// X12 — the exactly-one-group requirement is enforced independently of any conflict.
$eid = w8_new_event();
$db = fresh([w8_member(1, 503, 'manager', 'active', null, null, $eid)], [], [], [], [], $eid);
$r = PGE_Event_Access_Repository::set_membership_quota($eid, 1, null, 50, 501);
ok('X12. set_membership_quota() raising to quota-enabled fails closed when the membership has ZERO granted groups', code($r) === 'invalid_state');

$eid = w8_new_event();
$db = fresh(
    [w8_member(1, 503, 'manager', 'active', null, null, $eid)],
    [w8_group(1, 'active', $eid), w8_group(2, 'active', $eid)], [w8_access(1, 1, 1, $eid), w8_access(2, 1, 2, $eid)], [], [], $eid
);
$r = PGE_Event_Access_Repository::set_membership_quota($eid, 1, null, 50, 501);
ok('X12b. set_membership_quota() raising to quota-enabled fails closed when the membership has MORE THAN ONE granted group', code($r) === 'invalid_state');

// X13 — Quota Resolver defense: legacy/corrupt data with >1 active
// quota-enabled inviter on the same group must fail closed, never pick one.
$eid = w8_new_event();
$db = fresh(
    [w8_member(1, 502, 'manager', 'active', 100, null, $eid), w8_member(2, 503, 'manager', 'active', 60, null, $eid)],
    [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid), w8_access(2, 2, 1, $eid)], [], [], $eid
);
$r = PGE_Additional_Inviter::get_quota_status_for_owner($eid, 501, 1);
ok('X13. the quota resolver detects a legacy/corrupt second active quota-enabled inviter on the same group and fails closed (misconfigured), never silently picking one', code($r) === 'misconfigured');

// ============================================================================
// Y — Fix Pass 2: Additional-Inviter Mutation Closure. Closes the two
// remaining mutation paths that can construct the Additional-Inviter
// predicate (active + manager + allocated_quota IS NOT NULL) without
// going through create_additional_inviter_membership()/set_membership_
// quota(): grant_group_access() and change_membership_role().
// ============================================================================

// Y1 — one-group MVP rule: a quota-enabled inviter already holding ONE
// group cannot be granted a SECOND one.
$eid = w8_new_event();
$db = fresh([], [w8_group(1, 'active', $eid), w8_group(2, 'active', $eid)], [], [], [], $eid);
$a = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 502, 1, 100, 501);
$a_id = $a['membership']['id'];
$r = PGE_Event_Access_Repository::grant_group_access($eid, $a_id, 2, 501);
ok('Y1. a quota-enabled Additional Inviter already holding ONE group cannot be granted a SECOND group (MVP one-group rule)', code($r) === 'invalid_state');
ok('Y1b. the rejected grant leaves the membership with exactly its original one group (no partial access row)', count(PGE_Event_Access_Repository::list_group_ids_for_membership($eid, $a_id)) === 1);

// Y2 — closes the Fix Pass 1 documented residual gap: the mandatory
// revoke(A) -> create(B) -> reactivate(A) sequence, when FOLLOWED by a
// separate grant_group_access(A, same group), must now be rejected.
$eid = w8_new_event();
$db = fresh([], [w8_group(1, 'active', $eid)], [], [], [], $eid);
$a = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 502, 1, 100, 501);
$a_id = $a['membership']['id'];
PGE_Event_Access_Repository::revoke_membership($eid, $a_id, 501);
$b = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 503, 1, 60, 501);
$reactivate = PGE_Event_Access_Repository::reactivate_membership($eid, $a_id, 'manager', 501);
$r = PGE_Event_Access_Repository::grant_group_access($eid, $a_id, 1, 501);
ok('Y2. revoke(A) -> create(B) -> reactivate(A) -> grant(A, same group as B) is now correctly rejected (closes the Fix Pass 1 residual gap)', is_array($reactivate) && $reactivate['changed'] === true && code($r) === 'quota_group_conflict');

// Y3 — a generic (quota=NULL) Manager is never subject to the one-group rule.
$eid = w8_new_event();
$db = fresh([w8_member(1, 503, 'manager', 'active', null, null, $eid)], [w8_group(1, 'active', $eid), w8_group(2, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid);
$r = PGE_Event_Access_Repository::grant_group_access($eid, 1, 2, 501);
ok('Y3. a generic (quota=NULL) Manager can still be granted a SECOND group — the one-group MVP rule never applies to non-quota memberships', is_array($r) && $r['changed'] === true);

// Y4 — a Viewer with quota=NULL sharing a group with an active inviter is unaffected.
$eid = w8_new_event();
$db = fresh(
    [w8_member(1, 502, 'manager', 'active', 100, null, $eid), w8_member(2, 503, 'viewer', 'active', null, null, $eid)],
    [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid
);
$r = PGE_Event_Access_Repository::grant_group_access($eid, 2, 1, 501);
ok('Y4. a Viewer with quota=NULL can still be granted access to a group that already has an active quota-enabled inviter — unaffected', is_array($r) && $r['changed'] === true);

// Y5 — a Viewer with a quota already set, transitioning to Manager, is a
// candidate and must be rejected when the target group already has
// another active quota-enabled inviter.
$eid = w8_new_event();
$db = fresh(
    [w8_member(1, 502, 'viewer', 'active', 100, null, $eid), w8_member(2, 503, 'manager', 'active', 60, null, $eid)],
    [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid), w8_access(2, 2, 1, $eid)], [], [], $eid
);
$r = PGE_Event_Access_Repository::change_membership_role($eid, 1, 'viewer', 'manager', 501);
ok('Y5. a Viewer with a quota already set, transitioning to Manager, is rejected when its group already has another active quota-enabled inviter', code($r) === 'quota_group_conflict');
ok('Y5b. the rejected role change leaves the membership as viewer (no partial role change)', $db->memberships[1]['role'] === 'viewer');

// Y6 — the same transition succeeds when there is no conflict.
$eid = w8_new_event();
$db = fresh([w8_member(1, 502, 'viewer', 'active', 100, null, $eid)], [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid);
$r = PGE_Event_Access_Repository::change_membership_role($eid, 1, 'viewer', 'manager', 501);
ok('Y6. a Viewer with a quota already set, transitioning to Manager, succeeds when its one granted group has no conflicting inviter', is_array($r) && $r['changed'] === true && $r['membership']['role'] === 'manager');

// Y7 — a quota-enabled membership with ZERO granted groups fails closed on this transition.
$eid = w8_new_event();
$db = fresh([w8_member(1, 502, 'viewer', 'active', 100, null, $eid)], [], [], [], [], $eid);
$r = PGE_Event_Access_Repository::change_membership_role($eid, 1, 'viewer', 'manager', 501);
ok('Y7. a quota-enabled membership with ZERO granted groups is rejected when transitioning to Manager (never half-configured)', code($r) === 'invalid_state');

// Y8 — a quota-enabled membership with MORE THAN ONE granted group fails closed too.
$eid = w8_new_event();
$db = fresh(
    [w8_member(1, 502, 'viewer', 'active', 100, null, $eid)],
    [w8_group(1, 'active', $eid), w8_group(2, 'active', $eid)], [w8_access(1, 1, 1, $eid), w8_access(2, 1, 2, $eid)], [], [], $eid
);
$r = PGE_Event_Access_Repository::change_membership_role($eid, 1, 'viewer', 'manager', 501);
ok('Y8. a quota-enabled membership with MORE THAN ONE granted group is rejected when transitioning to Manager', code($r) === 'invalid_state');

// Y9 — an ordinary (quota=NULL) role change is completely unaffected.
$eid = w8_new_event();
$db = fresh([w8_member(1, 503, 'viewer', 'active', null, null, $eid)], [w8_group(1, 'active', $eid)], [w8_access(1, 1, 1, $eid)], [], [], $eid);
$r = PGE_Event_Access_Repository::change_membership_role($eid, 1, 'viewer', 'manager', 501);
ok('Y9. an ordinary (quota=NULL) role change is completely unaffected by the new guard', is_array($r) && $r['changed'] === true && $r['membership']['role'] === 'manager');

// Y10 — revoke_group_access() from an Additional Inviter is still allowed
// under the generic contract (unchanged); the resolver fails closed afterwards.
$eid = w8_new_event();
$db = fresh([], [w8_group(1, 'active', $eid)], [], [], [], $eid);
$a = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 502, 1, 100, 501);
$a_id = $a['membership']['id'];
$r = PGE_Event_Access_Repository::revoke_group_access($eid, $a_id, 1, 501);
ok('Y10. revoking a quota-enabled Additional Inviter\'s only group is still allowed under the generic H1C contract (revoke_group_access is unchanged)', is_array($r) && $r['changed'] === true);
$status = PGE_Additional_Inviter::get_quota_status_for_owner($eid, 501, $a_id);
ok('Y10b. the resolver correctly fails closed (misconfigured) afterwards, since the inviter now has zero groups (no auto-repair)', code($status) === 'misconfigured');

// Y11 — revoke_membership() still allows a replacement on the same group (unchanged from Fix Pass 1).
$eid = w8_new_event();
$db = fresh([], [w8_group(1, 'active', $eid)], [], [], [], $eid);
$a = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 502, 1, 100, 501);
PGE_Event_Access_Repository::revoke_membership($eid, $a['membership']['id'], 501);
$b = PGE_Event_Access_Repository::create_additional_inviter_membership($eid, 503, 1, 60, 501);
ok('Y11. after revoking a membership, a replacement Additional Inviter on the SAME group remains possible', is_array($b) && $b['changed'] === true);

// Y12 — every failed mutation path this Fix Pass touches leaves no partial
// access row, no partial role change, and no partial audit entry.
$eid = w8_new_event();
$db = fresh(
    [w8_member(1, 502, 'manager', 'active', 100, null, $eid), w8_member(2, 503, 'viewer', 'active', 60, null, $eid)],
    [w8_group(1, 'active', $eid), w8_group(2, 'active', $eid)],
    [w8_access(1, 1, 1, $eid), w8_access(2, 2, 1, $eid)], [], [], $eid
);
$audit_count_before = count($db->audits);
$fail_grant = PGE_Event_Access_Repository::grant_group_access($eid, 1, 2, 501); // one-group MVP violation
$fail_role = PGE_Event_Access_Repository::change_membership_role($eid, 2, 'viewer', 'manager', 501); // group conflict with membership 1
ok(
    'Y12. every failed mutation this Fix Pass touches leaves no partial access row, no partial role change, and no partial audit entry',
    $fail_grant instanceof WP_Error && $fail_role instanceof WP_Error
    && count(PGE_Event_Access_Repository::list_group_ids_for_membership($eid, 1)) === 1
    && $db->memberships[2]['role'] === 'viewer'
    && count($db->audits) === $audit_count_before
);

echo "\n----------------------------------------\n";
echo "H1C-W8: $pass/" . ($pass + $fail) . " passed\n";
if ($fail) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
exit($fail ? 1 : 0);
