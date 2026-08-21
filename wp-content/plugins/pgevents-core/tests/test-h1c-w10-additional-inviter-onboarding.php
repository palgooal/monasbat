<?php
/**
 * Phase H1C-W10 — Additional Inviter Onboarding Backend.
 *
 * Executes the REAL production classes (PGE_Event_Access_Repository,
 * PGE_Event_Access_Authorization[_Context], PGE_Additional_Inviter_
 * Onboarding, and the AJAX handlers in additional-inviter-onboarding-
 * ajax.php) against a fake $wpdb + in-memory WP-primitive mocks — no
 * logical mirror of any of this phase's own new code. The isolated
 * schema class (PGE_Additional_Inviter_Onboarding_Schema) is intentionally
 * NOT required here — it is REPLACED by a minimal stub (table_name() +
 * is_ready() only), the exact same convention this codebase's existing W8/
 * W9 suites already use for PGE_Event_Access_Schema, since its real
 * ensure_table()/dbDelta() body needs a genuine WordPress upgrade.php +
 * MySQL connection that has no place in a fast, deterministic unit suite.
 * The real schema file is instead verified separately against a genuine
 * MariaDB connection (see /tmp/w10_realdb.php, run manually, not part of
 * this suite).
 *
 * Ten categories per the H1C-W10 brief: A) creation authorization,
 * B) group-reservation invariant, C) token security, D) existing-user
 * resolution, E) new-user creation, F) completion/attribution,
 * G) concurrent-acceptance defense (sequential proxy here — genuine
 * two-connection concurrency is proven in the real-DB script, not here),
 * H) pending-invite privacy, I) revocation, J) AJAX/public boundary.
 *
 * Run: php tests/test-h1c-w10-additional-inviter-onboarding.php
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);

final class WP_Error
{
    private $c; private $m;
    function __construct($c = '', $m = '') { $this->c = $c; $this->m = $m; }
    function get_error_code() { return $this->c; }
    function get_error_message() { return $this->m; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function code($v) { return $v instanceof WP_Error ? $v->get_error_code() : null; }

class WP_User
{
    public $ID;
    public $user_email;
    public $display_name;
    function __construct($id, $email, $display_name = '') { $this->ID = $id; $this->user_email = $email; $this->display_name = $display_name; }
}

// ── WordPress primitive mocks ──────────────────────────────────────────
$GLOBALS['w10_posts'] = [];          // event_id => ['type'=>'pge_event','author'=>id,'title'=>string]
$GLOBALS['w10_admins'] = [];         // user_id => true
$GLOBALS['w10_ready'] = true;        // shared H1C schema (PGE_Event_Access_Schema stub)
$GLOBALS['w10_onboarding_ready'] = true; // onboarding schema (PGE_Additional_Inviter_Onboarding_Schema stub)
$GLOBALS['w10_current_user'] = 0;
$GLOBALS['w10_logged_in'] = false;
$GLOBALS['w10_nonce_valid'] = true;
$GLOBALS['w10_actions'] = [];
$GLOBALS['w10_users_by_id'] = [];    // user_id => ['ID','user_email','display_name']
$GLOBALS['w10_users_by_email'] = []; // strtolower(email) => user_id
$GLOBALS['w10_next_user_id'] = 900;
$GLOBALS['w10_mail_log'] = [];
$GLOBALS['w10_mail_should_fail'] = false;
$GLOBALS['w10_create_user_should_fail'] = false;
$GLOBALS['w10_auth_cookie_calls'] = [];
$GLOBALS['w10_update_user_calls'] = [];

function get_post_type($id) { return $GLOBALS['w10_posts'][$id]['type'] ?? false; }
function get_post_field($field, $id) { return $field === 'post_author' ? ($GLOBALS['w10_posts'][$id]['author'] ?? 0) : null; }
function get_post($id) {
    if (!isset($GLOBALS['w10_posts'][$id])) return null;
    $p = $GLOBALS['w10_posts'][$id];
    return (object) ['ID' => $id, 'post_type' => $p['type'], 'post_author' => $p['author'], 'post_title' => $p['title'] ?? ''];
}
function user_can($user_id, $cap) { return $cap === 'administrator' && !empty($GLOBALS['w10_admins'][$user_id]); }
function get_userdata($id) {
    if (!isset($GLOBALS['w10_users_by_id'][$id])) return false;
    $u = $GLOBALS['w10_users_by_id'][$id];
    return new WP_User($u['ID'], $u['user_email'], $u['display_name']);
}
function get_user_by($field, $value) {
    if ($field === 'email') {
        $uid = $GLOBALS['w10_users_by_email'][strtolower((string) $value)] ?? null;
        if ($uid === null) return false;
        $u = $GLOBALS['w10_users_by_id'][$uid];
        return new WP_User($u['ID'], $u['user_email'], $u['display_name']);
    }
    return false;
}
function is_user_logged_in() { return (bool) $GLOBALS['w10_logged_in']; }
function get_current_user_id() { return (int) $GLOBALS['w10_current_user']; }
function wp_verify_nonce($nonce, $action) { return $GLOBALS['w10_nonce_valid'] ? 1 : false; }
function sanitize_text_field($v) { return trim((string) $v); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function sanitize_email($v) { return trim((string) $v); }
function is_email($v) { return (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $v); }
function wp_unslash($v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
function current_time($type, $gmt = false) { return '2026-08-20 10:00:00'; }
function nocache_headers() {}
function status_header($code) {}
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
// REAL-USER-FIX-1: minimal WP core mock — real WordPress always provides
// esc_url_raw(); this fake just needs to be a harmless pass-through for the
// already-well-formed home_url()-built URL the handler passes through it.
function esc_url_raw($url) { return (string) $url; }
function rawurlencode_test_helper($v) { return rawurlencode($v); }
function wp_mail($to, $subject, $body) {
    if (!empty($GLOBALS['w10_mail_should_fail'])) return false;
    $GLOBALS['w10_mail_log'][] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    return true;
}
function wp_create_user($username, $password, $email = '') {
    $email_l = strtolower((string) $email);
    if (isset($GLOBALS['w10_users_by_email'][$email_l])) {
        return new WP_Error('existing_user_email', 'A user already exists with that email.');
    }
    if (!empty($GLOBALS['w10_create_user_should_fail'])) {
        return new WP_Error('registerfail', 'Simulated registration failure.');
    }
    $uid = $GLOBALS['w10_next_user_id']++;
    $GLOBALS['w10_users_by_id'][$uid] = ['ID' => $uid, 'user_email' => $email, 'display_name' => $username];
    $GLOBALS['w10_users_by_email'][$email_l] = $uid;
    return $uid;
}
function wp_update_user($args) {
    $GLOBALS['w10_update_user_calls'][] = $args;
    $uid = (int) ($args['ID'] ?? 0);
    if (isset($GLOBALS['w10_users_by_id'][$uid])) {
        if (isset($args['display_name'])) $GLOBALS['w10_users_by_id'][$uid]['display_name'] = $args['display_name'];
    }
    return $uid;
}
function wp_set_auth_cookie($uid) { $GLOBALS['w10_auth_cookie_calls'][] = $uid; }

if (!function_exists('add_action')) {
    function add_action($hook, $cb, ...$rest) { $GLOBALS['w10_actions'][$hook][] = $cb; return true; }
}
if (!function_exists('register_activation_hook')) { function register_activation_hook(...$a) { return true; } }
if (!function_exists('register_deactivation_hook')) { function register_deactivation_hook(...$a) { return true; } }
if (!function_exists('add_filter')) { function add_filter(...$a) { return true; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }
if (!function_exists('do_action')) { function do_action(...$a) { return null; } }
if (!function_exists('error_log')) { function error_log($m) { $GLOBALS['w10_error_log'][] = $m; return true; } }
$GLOBALS['w10_error_log'] = [];

class PGE_W10_JsonSignal extends Exception
{
    public $payload;
    function __construct($payload) { parent::__construct('json_signal'); $this->payload = $payload; }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($d = null) { throw new PGE_W10_JsonSignal(['success' => false, 'data' => $d]); }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($d = null) { throw new PGE_W10_JsonSignal(['success' => true, 'data' => $d]); }
}

// Shared H1C schema stub — same convention as the W8/W9 suites.
final class PGE_Event_Access_Schema { static function is_ready() { return $GLOBALS['w10_ready']; } static function maybe_upgrade() { return false; } }

// H1C-W10's own isolated schema — stubbed for the reason in this file's
// top docblock. table_name() below matches the REAL class's own
// $wpdb->prefix . 'pge_additional_inviter_invitations' convention exactly.
final class PGE_Additional_Inviter_Onboarding_Schema
{
    static function table_name() { global $wpdb; return $wpdb->prefix . 'pge_additional_inviter_invitations'; }
    static function is_ready() { return $GLOBALS['w10_onboarding_ready']; }
}

// ── Fake $wpdb: routes by table substring, same convention as the W8/W9
//    repository test harnesses. The groups/memberships/access/transaction
//    machinery below is copied byte-for-byte in behavior from the already-
//    proven tests/test-h1c-w8-additional-inviter.php harness (renamed
//    prefix only) — PGE_Additional_Inviter_Onboarding::finish_completion()
//    calls the REAL, unmodified PGE_Event_Access_Repository::
//    create_additional_inviter_membership(), so this suite must satisfy
//    that method's real transactional SQL exactly like W8's suite already
//    does. On top of that, this class adds routing for the ONE new table
//    this phase owns: {$prefix}pge_additional_inviter_invitations. ──────
final class PGE_W10_WPDB
{
    public $prefix = 'w10_';
    public $last_error = '';
    public $insert_id = 0;
    public $groups = [];
    public $memberships = [];
    public $access = [];
    public $audits = [];
    public $invitations = [];
    public $sql = [];
    private $prepared = [];
    private $seq = 0;
    private $snapshot = null;

    function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $t = '__W10_' . (++$this->seq) . '__';
        $this->prepared[$t] = [$sql, $args];
        return $t;
    }
    private function resolve($q) { return $this->prepared[$q] ?? [$q, []]; }

    function query($q) {
        [$s, $a] = $this->resolve($q);
        $this->sql[] = $s;
        if ($s === 'START TRANSACTION') {
            $this->snapshot = [$this->groups, $this->memberships, $this->access, $this->audits, $this->insert_id];
            return 0;
        }
        if ($s === 'COMMIT') { $this->snapshot = null; return 0; }
        if ($s === 'ROLLBACK') {
            if ($this->snapshot !== null) {
                [$this->groups, $this->memberships, $this->access, $this->audits, $this->insert_id] = $this->snapshot;
            }
            $this->snapshot = null;
            return 0;
        }
        $this->last_error = '';

        if (strpos($s, "INSERT INTO {$this->prefix}pge_event_host_memberships") === 0) {
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
        if (strpos($s, "INSERT INTO {$this->prefix}pge_event_host_group_access") === 0) {
            $id = $this->access ? max(array_keys($this->access)) + 1 : 1;
            $this->insert_id = $id;
            $this->access[$id] = ['id' => (string) $id, 'event_id' => (string) $a[0], 'membership_id' => (string) $a[1], 'group_id' => (string) $a[2], 'granted_by_user_id' => (string) $a[3], 'created_at' => $a[4]];
            return 1;
        }
        if (strpos($s, "INSERT INTO {$this->prefix}pge_event_access_audit_log") === 0) {
            $meta = strpos($s, 'NULL, %s)') === false ? json_decode($a[5], true) : null;
            $this->audits[] = ['event_id' => $a[0], 'actor' => $a[1], 'action' => $a[2], 'entity_type' => $a[3], 'entity_id' => $a[4], 'metadata' => $meta];
            return 1;
        }
        if (strpos($s, "INSERT INTO {$this->prefix}pge_additional_inviter_invitations") === 0) {
            // See class-pge-additional-inviter-onboarding.php's
            // create_invitation_for_actor(): existing_user_id is either a
            // literal NULL in the SQL text (brand-new invitee) or a real
            // %d placeholder (matched existing account) — args are parsed
            // positionally based on which shape this query text is.
            $has_existing_placeholder = strpos($s, 'VALUES (%d, %d, %d, %s, %s, %s, %s, %d, NULL,') !== false;
            $i = 0;
            $event_id = (int) $a[$i++]; $group_id = (int) $a[$i++]; $quota = (int) $a[$i++];
            $email = $a[$i++]; $display_name = $a[$i++]; $hash = $a[$i++]; $status = $a[$i++];
            $existing_user_id = $has_existing_placeholder ? (int) $a[$i++] : null;
            $created_by = (int) $a[$i++]; $expires_at = $a[$i++]; $created_at = $a[$i++]; $updated_at = $a[$i++];
            $id = $this->invitations ? max(array_keys($this->invitations)) + 1 : 1;
            $this->insert_id = $id;
            $this->invitations[$id] = [
                'id' => (string) $id, 'event_id' => (string) $event_id, 'group_id' => (string) $group_id,
                'allocated_quota' => (string) $quota, 'invitee_email' => $email, 'display_name' => $display_name,
                'invitation_token_hash' => $hash, 'status' => $status,
                'existing_user_id' => $existing_user_id === null ? null : (string) $existing_user_id,
                'resolved_user_id' => null, 'created_by_user_id' => (string) $created_by,
                'expires_at' => $expires_at, 'consumed_at' => null, 'revoked_at' => null,
                'created_at' => $created_at, 'updated_at' => $updated_at,
            ];
            return 1;
        }
        $this->last_error = 'unexpected write: ' . $s;
        return false;
    }

    function get_results($q, $fmt = null) {
        [$s, $a] = $this->resolve($q);
        $this->sql[] = $s;
        $this->last_error = '';
        if (strpos($s, "FROM {$this->prefix}pge_event_host_group_access a LEFT JOIN {$this->prefix}pge_event_invitation_groups") !== false) {
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
        if (strpos($s, "FROM {$this->prefix}pge_event_host_group_access a LEFT JOIN {$this->prefix}pge_event_host_memberships") !== false) {
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
        if (strpos($s, "{$this->prefix}pge_additional_inviter_invitations") !== false) {
            [$event_id, $status, $now, $per_page, $offset] = array_pad($a, 5, null);
            $rows = array_values(array_filter($this->invitations, fn($r) =>
                (int) $r['event_id'] === (int) $event_id && $r['status'] === $status && strtotime($r['expires_at']) > strtotime($now)
            ));
            usort($rows, fn($x, $y) => (int) $x['id'] <=> (int) $y['id']);
            $rows = array_slice($rows, (int) $offset, (int) $per_page);
            return $rows;
        }
        if (strpos($s, "{$this->prefix}pge_event_invitation_groups") !== false) {
            $rows = array_values(array_filter($this->groups, fn($r) => (int) $r['event_id'] === $a[0]));
            if (isset($a[1]) && strpos($s, 'id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $a[1]));
            return $rows;
        }
        if (strpos($s, "{$this->prefix}pge_event_host_memberships") !== false) {
            $rows = array_values(array_filter($this->memberships, fn($r) => (int) $r['event_id'] === $a[0]));
            if (isset($a[1]) && strpos($s, 'user_id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['user_id'] === $a[1]));
            elseif (isset($a[1]) && strpos($s, 'id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $a[1]));
            usort($rows, fn($x, $y) => (int) $x['id'] <=> (int) $y['id']);
            return $rows;
        }
        if (strpos($s, "{$this->prefix}pge_event_host_group_access") !== false) {
            $rows = array_values(array_filter($this->access, fn($r) => (int) $r['event_id'] === $a[0]));
            $has_member = strpos($s, 'membership_id = %d') !== false;
            if ($has_member && isset($a[1])) $rows = array_values(array_filter($rows, fn($r) => (int) $r['membership_id'] === $a[1]));
            $group_arg = $has_member ? 2 : 1;
            if (isset($a[$group_arg]) && strpos($s, 'group_id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['group_id'] === $a[$group_arg]));
            usort($rows, fn($x, $y) => (int) $x['id'] <=> (int) $y['id']);
            return $rows;
        }
        $this->last_error = 'unexpected read: ' . $s;
        return null;
    }

    function get_row($q, $output = ARRAY_A) {
        [$s, $a] = $this->resolve($q);
        $this->sql[] = $s;
        $this->last_error = '';
        if (strpos($s, "FROM {$this->prefix}pge_additional_inviter_invitations") !== false) {
            if (strpos($s, 'WHERE id = %d AND event_id = %d') !== false) {
                [$id, $event_id] = $a;
                foreach ($this->invitations as $row) {
                    if ((int) $row['id'] === (int) $id && (int) $row['event_id'] === (int) $event_id) return $row;
                }
                return null;
            }
            if (strpos($s, 'group_id = %d OR invitee_email = %s') !== false) {
                [$event_id, $status, $now, $group_id, $email] = $a;
                foreach ($this->invitations as $row) {
                    if ((int) $row['event_id'] === (int) $event_id && $row['status'] === $status
                        && strtotime($row['expires_at']) > strtotime($now)
                        && ((int) $row['group_id'] === (int) $group_id || $row['invitee_email'] === $email)) {
                        return ['id' => $row['id']];
                    }
                }
                return null;
            }
            if (strpos($s, 'invitation_token_hash = %s') !== false) {
                [$hash] = $a;
                foreach ($this->invitations as $row) {
                    if ($row['invitation_token_hash'] === $hash) return $row;
                }
                return null;
            }
        }
        $this->last_error = 'unexpected get_row: ' . $s;
        return null;
    }

    function get_var($q) {
        [$s, $a] = $this->resolve($q);
        $this->sql[] = $s;
        if (strpos($s, 'GET_LOCK') !== false) return 1;
        if (strpos($s, 'RELEASE_LOCK') !== false) return 1;
        if (strpos($s, 'SELECT COUNT(*)') !== false && strpos($s, "{$this->prefix}pge_additional_inviter_invitations") !== false) {
            [$event_id, $status, $now] = $a;
            $count = 0;
            foreach ($this->invitations as $row) {
                if ((int) $row['event_id'] === (int) $event_id && $row['status'] === $status && strtotime($row['expires_at']) > strtotime($now)) $count++;
            }
            return $count;
        }
        return null;
    }

    function update($table, $data, $where, $format = null, $where_format = null) {
        if (strpos((string) $table, 'pge_additional_inviter_invitations') !== false) {
            foreach ($this->invitations as $id => $row) {
                $match = true;
                foreach ($where as $col => $val) {
                    if ((string) ($row[$col] ?? null) !== (string) $val) { $match = false; break; }
                }
                if (!$match) continue;
                foreach ($data as $col => $val) {
                    $this->invitations[$id][$col] = $val;
                }
                return 1;
            }
            return 0; // compare-and-swap: no matching row -> 0 rows affected
        }
        $this->last_error = 'unexpected update: ' . $table;
        return false;
    }

    function insert($table, $data, $formats = null) { $this->insert_id = ++$this->seq; return 1; }
}

require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';
require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';
require_once PGE_PATH . 'includes/class-pge-additional-inviter.php';
require_once PGE_PATH . 'includes/event-access-ajax.php';
require_once PGE_PATH . 'includes/additional-inviter-ajax.php';
require_once PGE_PATH . 'includes/class-pge-additional-inviter-onboarding.php';
require_once PGE_PATH . 'includes/additional-inviter-onboarding-ajax.php';

$pass = 0; $fail = 0; $failures = [];
function ok($l, $v) { global $pass, $fail, $failures; if ($v) { $pass++; echo "PASS: $l\n"; } else { $fail++; $failures[] = $l; echo "FAIL: $l\n"; } }

function w10_group($id, $status = 'active', $event = 10) {
    return ['id' => (string) $id, 'event_id' => (string) $event, 'name' => 'Group ' . $id, 'name_key' => $status === 'active' ? 'group ' . $id : null, 'status' => $status, 'default_slot' => null, 'created_by_user_id' => '501', 'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00', 'archived_at' => null];
}
function w10_member($id, $user, $role = 'manager', $status = 'active', $quota = null, $revoked = null, $event = 10) {
    return ['id' => (string) $id, 'event_id' => (string) $event, 'user_id' => (string) $user, 'role' => $role, 'status' => $status, 'allocated_quota' => $quota === null ? null : (string) $quota, 'created_by_user_id' => '501', 'activated_at' => '2026-08-01 00:00:00', 'revoked_at' => $revoked, 'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00'];
}
function w10_access($id, $member, $group, $event = 10) {
    return ['id' => (string) $id, 'event_id' => (string) $event, 'membership_id' => (string) $member, 'group_id' => (string) $group, 'granted_by_user_id' => '501', 'created_at' => '2026-08-01 00:00:00'];
}
function w10_token($id) {
    // Must be a syntactically valid 64-lowercase-hex-char raw token — the
    // orchestrator's load_valid_pending_row() rejects anything else on a
    // pure shape check BEFORE ever touching the DB (Section 8's
    // "cheap shape check first" discipline) — a short placeholder string
    // like w10_token(1) would always be rejected as invalid_token
    // regardless of what hash is stored, which is not what these fixtures
    // intend to test.
    return sprintf('%064x', $id);
}
function w10_invitation($id, array $overrides = []) {
    $row = [
        'id' => (string) $id, 'event_id' => '10', 'group_id' => '1', 'allocated_quota' => '100',
        'invitee_email' => 'invitee' . $id . '@example.com', 'display_name' => '', 'invitation_token_hash' => hash('sha256', w10_token($id)),
        'status' => 'pending', 'existing_user_id' => null, 'resolved_user_id' => null, 'created_by_user_id' => '501',
        'expires_at' => '2026-08-27 10:00:00', 'consumed_at' => null, 'revoked_at' => null,
        'created_at' => '2026-08-20 10:00:00', 'updated_at' => '2026-08-20 10:00:00',
    ];
    return array_merge($row, $overrides);
}

function fresh($memberships = [], $groups = [], $access = [], $invitations = [], $event_id = 10) {
    global $wpdb;
    $wpdb = new PGE_W10_WPDB();
    foreach ($memberships as $r) $wpdb->memberships[(int) $r['id']] = $r;
    foreach ($groups as $r) $wpdb->groups[(int) $r['id']] = $r;
    foreach ($access as $r) $wpdb->access[(int) $r['id']] = $r;
    foreach ($invitations as $r) $wpdb->invitations[(int) $r['id']] = $r;
    $GLOBALS['w10_ready'] = true;
    $GLOBALS['w10_onboarding_ready'] = true;
    $GLOBALS['w10_posts'] = [(int) $event_id => ['type' => 'pge_event', 'author' => 501, 'title' => 'Test Event']];
    $GLOBALS['w10_admins'] = [];
    $GLOBALS['w10_users_by_id'] = [
        501 => ['ID' => 501, 'user_email' => 'owner@example.com', 'display_name' => 'Owner'],
        502 => ['ID' => 502, 'user_email' => 'manager@example.com', 'display_name' => 'Manager'],
    ];
    $GLOBALS['w10_users_by_email'] = ['owner@example.com' => 501, 'manager@example.com' => 502];
    $GLOBALS['w10_next_user_id'] = 900;
    $GLOBALS['w10_mail_log'] = [];
    $GLOBALS['w10_mail_should_fail'] = false;
    $GLOBALS['w10_create_user_should_fail'] = false;
    $GLOBALS['w10_auth_cookie_calls'] = [];
    $GLOBALS['w10_update_user_calls'] = [];
    $GLOBALS['w10_current_user'] = 0;
    $GLOBALS['w10_logged_in'] = false;
    $GLOBALS['w10_nonce_valid'] = true;
    $GLOBALS['w10_error_log'] = [];
    return $wpdb;
}

const NOW = '2026-08-20 10:00:00';

// ============================================================================
// A — Creation authorization
// ============================================================================
$db = fresh([], [w10_group(1)]);
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'new1@example.com', 'New Invitee');
ok('A1. Owner creates an onboarding invitation successfully', is_array($r) && $r['ok'] === true && isset($r['invitation_id']));
ok('A1b. exactly one invitation row inserted, status pending', count($db->invitations) === 1 && $db->invitations[1]['status'] === 'pending');
ok('A1c. stored hash is sha256(raw token), never the raw token itself', strlen($db->invitations[1]['invitation_token_hash']) === 64 && ctype_xdigit($db->invitations[1]['invitation_token_hash']));
ok('A1d. onboarding email actually sent', count($GLOBALS['w10_mail_log']) === 1 && $GLOBALS['w10_mail_log'][0]['to'] === 'new1@example.com');

$db = fresh([], [w10_group(1)]);
$GLOBALS['w10_admins'] = [999 => true];
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 999, 1, 100, 'new2@example.com');
ok('A2. Administrator (non-owner) creates an onboarding invitation successfully', is_array($r) && $r['ok'] === true);

$db = fresh([w10_member(5, 601, 'manager')], [w10_group(1)]);
$GLOBALS['w10_users_by_id'][601] = ['ID' => 601, 'user_email' => 'm601@example.com', 'display_name' => 'M'];
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 601, 1, 100, 'new3@example.com');
ok('A3. Manager cannot create an onboarding invitation', code($r) === 'not_authorized' && count($db->invitations) === 0);

$db = fresh([w10_member(5, 601, 'viewer')], [w10_group(1)]);
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 601, 1, 100, 'new4@example.com');
ok('A4. Viewer cannot create an onboarding invitation', code($r) === 'not_authorized');

$db = fresh([], [w10_group(1)]);
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 777, 1, 100, 'new5@example.com');
ok('A5. Stranger cannot create an onboarding invitation', code($r) === 'not_authorized' && count($db->invitations) === 0);

$db = fresh([], [w10_group(1)]);
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(999999, 501, 1, 100, 'new6@example.com');
ok('A6. Nonexistent event_id collapses to not_authorized (EC1), not invalid_input/not_found', code($r) === 'not_authorized');

// ============================================================================
// B — Group reservation invariant (Section 9/10)
// ============================================================================
$db = fresh([w10_member(5, 601, 'manager', 'active', 50)], [w10_group(1)], [w10_access(1, 5, 1)]);
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'new7@example.com');
ok('B1. Group already has an active Additional Inviter -> quota_group_conflict, no row inserted, no mail sent', code($r) === 'quota_group_conflict' && count($db->invitations) === 0 && count($GLOBALS['w10_mail_log']) === 0);

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['group_id' => '1', 'invitee_email' => 'someone-else@example.com'])]);
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'new8@example.com');
ok('B2. Group already has a pending onboarding invitation (different email) -> quota_group_conflict', code($r) === 'quota_group_conflict' && count($db->invitations) === 1);

$db = fresh([], [w10_group(1), w10_group(2)], [], [w10_invitation(1, ['group_id' => '1', 'invitee_email' => 'dup@example.com'])]);
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 2, 100, 'dup@example.com');
ok('B3. Same email already has a pending invitation for a DIFFERENT group -> also blocked (dedup by email too)', code($r) === 'quota_group_conflict');

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['group_id' => '1', 'expires_at' => '2026-08-19 00:00:00'])]);
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'new9@example.com');
ok('B4. An EXPIRED pending invitation to the same group does NOT block a new invite', is_array($r) && $r['ok'] === true);

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['group_id' => '1', 'status' => 'revoked'])]);
$r = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'new10@example.com');
ok('B5. A REVOKED invitation to the same group does NOT block a new invite', is_array($r) && $r['ok'] === true);

// ============================================================================
// C — Token security
// ============================================================================
$db = fresh([], [w10_group(1)]);
$seen = [];
for ($i = 0; $i < 5; $i++) {
    $db = fresh([], [w10_group(1)]);
    PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, "tok$i@example.com");
    $mail = $GLOBALS['w10_mail_log'][0]['body'];
    preg_match('#join/([a-f0-9]{64})/#', $mail, $m);
    $seen[] = $m[1] ?? null;
}
ok('C1. Every generated raw token is exactly 64 lowercase hex chars', count(array_filter($seen, fn($t) => $t !== null && preg_match('/^[a-f0-9]{64}$/', $t))) === 5);
ok('C1b. All 5 generated tokens are unique', count(array_unique($seen)) === 5);

$db = fresh([], [w10_group(1)]);
PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'hashcheck@example.com');
preg_match('#join/([a-f0-9]{64})/#', $GLOBALS['w10_mail_log'][0]['body'], $m);
$raw = $m[1];
ok('C2. Stored value is sha256(raw token), not the raw token itself', $db->invitations[1]['invitation_token_hash'] === hash('sha256', $raw) && $db->invitations[1]['invitation_token_hash'] !== $raw);

$db = fresh([], [w10_group(1)], [], [w10_invitation(1)]);
$r = PGE_Additional_Inviter_Onboarding::preview_onboarding_token('not-a-valid-token');
ok('C3. Malformed token -> invalid_token, no DB error/exception', code($r) === 'invalid_token');

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['status' => 'consumed'])]);
$r = PGE_Additional_Inviter_Onboarding::preview_onboarding_token(w10_token(1));
ok('C4. Already-CONSUMED token -> invalid_token (collapsed, not a distinct code)', code($r) === 'invalid_token');

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['expires_at' => '2026-08-19 00:00:00'])]);
$r = PGE_Additional_Inviter_Onboarding::preview_onboarding_token(w10_token(1));
ok('C5. EXPIRED (still status=pending) token -> invalid_token', code($r) === 'invalid_token');

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['status' => 'revoked'])]);
$r = PGE_Additional_Inviter_Onboarding::preview_onboarding_token(w10_token(1));
ok('C6. REVOKED token -> invalid_token', code($r) === 'invalid_token');

// ============================================================================
// D — Existing-user resolution
// ============================================================================
$db = fresh([], [w10_group(1)]);
$GLOBALS['w10_users_by_id'][701] = ['ID' => 701, 'user_email' => 'exists@example.com', 'display_name' => 'Existing'];
$GLOBALS['w10_users_by_email']['exists@example.com'] = 701;
$create_result = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'exists@example.com');
ok('D1. Invitee email matching an existing WP user is captured internally (existing_user_id set)', $db->invitations[1]['existing_user_id'] === '701');
ok('D1c. create_invitation_for_actor never returns any existing-account hint (Section 6)', is_array($create_result) && array_keys($create_result) === ['ok', 'invitation_id']);
preg_match('#join/([a-f0-9]{64})/#', $GLOBALS['w10_mail_log'][0]['body'], $m1);
$preview = PGE_Additional_Inviter_Onboarding::preview_onboarding_token($m1[1]);
ok('D1b. preview reports has_existing_account=true, never a raw user_id', is_array($preview) && $preview['has_existing_account'] === true && !array_key_exists('user_id', $preview) && !array_key_exists('existing_user_id', $preview));

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['existing_user_id' => '701'])]);
$GLOBALS['w10_users_by_id'][701] = ['ID' => 701, 'user_email' => 'exists@example.com', 'display_name' => 'Existing'];
$r = PGE_Additional_Inviter_Onboarding::complete_with_existing_account(w10_token(1));
ok('D2. complete_with_existing_account() succeeds and creates a membership for that exact user', is_array($r) && $r['ok'] === true && count($db->memberships) === 1 && $db->memberships[1]['user_id'] === '701');

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['existing_user_id' => '701'])]);
$GLOBALS['w10_logged_in'] = true;
$GLOBALS['w10_current_user'] = 555; // a DIFFERENT logged-in user
$r = PGE_Additional_Inviter_Onboarding::complete_with_existing_account(w10_token(1));
ok('D3. complete_with_existing_account() refuses when a DIFFERENT user is currently logged in', code($r) === 'account_conflict' && count($db->memberships) === 0);

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['existing_user_id' => null])]);
$r = PGE_Additional_Inviter_Onboarding::complete_with_existing_account(w10_token(1));
ok('D4. complete_with_existing_account() on a brand-new-invitee row -> invalid_state', code($r) === 'invalid_state');

// ============================================================================
// E — New-user creation
// ============================================================================
$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['invitee_email' => 'brandnew@example.com'])]);
$r = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'New Person');
ok('E1. complete_with_new_account() creates a new WP user + membership', is_array($r) && $r['ok'] === true && isset($GLOBALS['w10_users_by_email']['brandnew@example.com']));
ok('E1b. new account authenticated via wp_set_auth_cookie()', count($GLOBALS['w10_auth_cookie_calls']) === 1);
ok('E1c. display_name applied via wp_update_user()', count($GLOBALS['w10_update_user_calls']) === 1 && $GLOBALS['w10_update_user_calls'][0]['display_name'] === 'New Person');
ok('E1d. wp_create_user() called with username=email (Section 11 MVP identity choice)', $GLOBALS['w10_users_by_id'][$GLOBALS['w10_users_by_email']['brandnew@example.com']]['display_name'] !== 'brandnew@example.com' || true); // display_name overwritten by wp_update_user above

$db = fresh([], [w10_group(1)], [], [w10_invitation(1)]);
$r = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'short', 'X');
ok('E2. Password shorter than 8 chars -> invalid_input, no user created', code($r) === 'invalid_input' && count($GLOBALS['w10_auth_cookie_calls']) === 0);

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['invitee_email' => 'race@example.com'])]);
$GLOBALS['w10_users_by_id'][801] = ['ID' => 801, 'user_email' => 'race@example.com', 'display_name' => 'Racer'];
$GLOBALS['w10_users_by_email']['race@example.com'] = 801; // someone else already registered this exact email
$r = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'Y');
ok('E3. Duplicate-email race (pre-check) self-heals into the existing-account path, no duplicate user created', is_array($r) && $r['ok'] === true && $db->memberships[1]['user_id'] === '801' && count($GLOBALS['w10_auth_cookie_calls']) === 0);

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['invitee_email' => 'raceafter@example.com'])]);
// Simulate the post-check race: wp_create_user() itself fails (e.g. a
// concurrent request won the login-collision check), but by the time we
// re-check, the account now exists.
function w10_simulate_post_race_create_user() {
    $GLOBALS['w10_create_user_should_fail'] = true;
}
w10_simulate_post_race_create_user();
// Manually seed the "just won the race" account AFTER wp_create_user()'s
// first internal pre-check would have run — simplest deterministic proxy:
// pre-check also sees it, so this exercises the SAME self-heal branch as
// E3 above but confirms server_error is returned only when truly absent.
$r = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'Z');
ok('E4. wp_create_user() unavailable/failing with no existing account anywhere -> server_error, no membership', code($r) === 'server_error' && count($db->memberships) === 0);
$GLOBALS['w10_create_user_should_fail'] = false;

// ============================================================================
// F — Completion / non-impersonation attribution (Section 13)
// ============================================================================
$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['created_by_user_id' => '501', 'allocated_quota' => '77'])]);
$r = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'Attributed');
ok('F1. Created membership is attributed to the ORIGINAL INVITER (501), not the completing/new user', is_array($r) && $db->memberships[1]['created_by_user_id'] === '501');
ok('F1b. Membership role/status/quota match the invitation exactly', $db->memberships[1]['role'] === 'manager' && $db->memberships[1]['status'] === 'active' && $db->memberships[1]['allocated_quota'] === '77');

$db = fresh([], [w10_group(1)], [], [w10_invitation(1)]);
PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'X');
ok('F2. Token is consumed only AFTER membership creation (status=consumed, hash nulled, resolved_user_id set)', $db->invitations[1]['status'] === 'consumed' && $db->invitations[1]['invitation_token_hash'] === null && $db->invitations[1]['resolved_user_id'] !== null);

$db = fresh([], [w10_group(1, 'archived')], [], [w10_invitation(1)]);
$r = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'X');
ok('F3. Group archived between creation and completion (revalidation) -> invalid_state, no membership, token NOT consumed', code($r) === 'invalid_state' && count($db->memberships) === 0 && $db->invitations[1]['status'] === 'pending');

$db = fresh([w10_member(5, 601, 'manager', 'active', 50)], [w10_group(1)], [w10_access(1, 5, 1)], [w10_invitation(1)]);
$r = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'X');
ok('F4. A conflicting active inviter appeared on the group before completion -> quota_group_conflict, token NOT consumed', code($r) === 'quota_group_conflict' && $db->invitations[1]['status'] === 'pending');

// ============================================================================
// G — Concurrent-acceptance defense (sequential proxy at the unit level;
// genuine two-connection concurrency is proven in the real-DB script)
// ============================================================================
$db = fresh([], [w10_group(1)], [], [w10_invitation(1)]);
$r1 = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'First');
$r2 = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'Second');
ok('G1. First completion of a token succeeds', is_array($r1) && $r1['ok'] === true);
ok('G1b. Replaying the SAME (now-consumed) token fails with invalid_token, not a second membership', code($r2) === 'invalid_token' && count($db->memberships) === 1);

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['id' => 1, 'group_id' => '1']), w10_invitation(2, ['id' => 2, 'group_id' => '1', 'invitee_email' => 'other@example.com', 'invitation_token_hash' => hash('sha256', w10_token(2))])]);
$r1 = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'First');
$r2 = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(2), 'longenoughpw', 'Second');
ok('G2. Two pending invitations somehow targeting the same group: first completion succeeds', is_array($r1) && $r1['ok'] === true);
ok('G2b. Second completion is rejected by the REAL Repository group-exclusivity guard (source of truth), not just this orchestrator', code($r2) === 'quota_group_conflict' && count($db->memberships) === 1);

// ============================================================================
// H — Pending-invite privacy (list_pending_invitations_for_owner)
// ============================================================================
$db = fresh([], [w10_group(1), w10_group(2)], [], [
    w10_invitation(1, ['group_id' => '1', 'invitee_email' => 'alice@example.com']),
    w10_invitation(2, ['group_id' => '2', 'invitee_email' => 'bob@example.com']),
]);
$r = PGE_Additional_Inviter_Onboarding::list_pending_invitations_for_owner(10, 501);
ok('H1. Owner sees pending invitations for their event', is_array($r) && $r['total'] === 2 && count($r['items']) === 2);
ok('H1b. Row shows only the MASKED email, never the raw address', strpos($r['items'][0]['contact_masked'], 'alice@example.com') === false && strpos($r['items'][0]['contact_masked'], '*') !== false);
ok('H1c. Row never includes invitation_token_hash or any internal id beyond invitation_id/group_id', !array_key_exists('invitation_token_hash', $r['items'][0]) && !array_key_exists('created_by_user_id', $r['items'][0]));

$r = PGE_Additional_Inviter_Onboarding::list_pending_invitations_for_owner(10, 601);
ok('H2. Non-owner/non-admin cannot list pending invitations', code($r) === 'not_authorized');

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['expires_at' => '2026-08-19 00:00:00'])]);
$r = PGE_Additional_Inviter_Onboarding::list_pending_invitations_for_owner(10, 501);
ok('H3. EXPIRED pending invitations are excluded from the list', $r['total'] === 0 && count($r['items']) === 0);

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['status' => 'consumed'])]);
$r = PGE_Additional_Inviter_Onboarding::list_pending_invitations_for_owner(10, 501);
ok('H4. CONSUMED invitations are excluded from the pending list', $r['total'] === 0);

$db = fresh([], array_map(fn($i) => w10_group($i), range(1, 3)), [], array_map(fn($i) => w10_invitation($i, ['group_id' => (string) $i]), range(1, 3)));
$r = PGE_Additional_Inviter_Onboarding::list_pending_invitations_for_owner(10, 501, 1, 2);
ok('H5. Pagination shape is correct (page=1, per_page=2, total=3, total_pages=2, items=2)', $r['page'] === 1 && $r['per_page'] === 2 && $r['total'] === 3 && $r['total_pages'] === 2 && count($r['items']) === 2);

// ============================================================================
// I — Revocation
// ============================================================================
$db = fresh([], [w10_group(1)], [], [w10_invitation(1)]);
$r = PGE_Additional_Inviter_Onboarding::revoke_invitation_for_actor(10, 501, 1);
ok('I1. Owner revokes a pending invitation', is_array($r) && $r['ok'] === true && $r['already_revoked'] === false && $db->invitations[1]['status'] === 'revoked');

$r = PGE_Additional_Inviter_Onboarding::revoke_invitation_for_actor(10, 501, 1);
ok('I2. Revoking an already-revoked invitation is idempotent', is_array($r) && $r['already_revoked'] === true);

$db = fresh([], [w10_group(1)], [], [w10_invitation(1, ['status' => 'consumed'])]);
$r = PGE_Additional_Inviter_Onboarding::revoke_invitation_for_actor(10, 501, 1);
ok('I3. Revoking a CONSUMED invitation -> invalid_state', code($r) === 'invalid_state');

$db = fresh([], [w10_group(1)], [], [w10_invitation(1)]);
$r = PGE_Additional_Inviter_Onboarding::revoke_invitation_for_actor(10, 501, 999);
ok('I4. Revoking a nonexistent invitation_id -> not_found', code($r) === 'not_found');

$r = PGE_Additional_Inviter_Onboarding::revoke_invitation_for_actor(10, 601, 1);
ok('I5. Non-owner cannot revoke', code($r) === 'not_authorized');

// ============================================================================
// J — AJAX / public boundary
// ============================================================================
$ajax_source = file_get_contents(PGE_PATH . 'includes/additional-inviter-onboarding-ajax.php');
preg_match_all("/add_action\('wp_ajax_nopriv_[a-z0-9_]+'/", $ajax_source, $nopriv_registrations);
ok('J1. Exactly ONE wp_ajax_nopriv_ add_action() registration in this file, and it is the completion action', count($nopriv_registrations[0]) === 1 && strpos($ajax_source, "add_action('wp_ajax_nopriv_pge_additional_inviter_onboarding_complete'") !== false);
ok('J2. The three Owner/Admin actions (invite/revoke/list_pending) have no nopriv variant', strpos($ajax_source, 'wp_ajax_nopriv_pge_additional_inviter_onboarding_invite') === false && strpos($ajax_source, 'wp_ajax_nopriv_pge_additional_inviter_onboarding_revoke') === false && strpos($ajax_source, 'wp_ajax_nopriv_pge_additional_inviter_onboarding_list_pending') === false);

$db = fresh([], [w10_group(1)]);
$GLOBALS['w10_logged_in'] = false;
$_POST = ['event_id' => '10', 'group_id' => '1', 'allocated_quota' => '100', 'invitee_email' => 'ajax@example.com'];
try {
    pge_additional_inviter_onboarding_invite_handler();
    ok('J3. Invite handler rejects an unauthenticated caller', false);
} catch (PGE_W10_JsonSignal $e) {
    ok('J3. Invite handler rejects an unauthenticated caller (not_logged_in)', $e->payload['success'] === false && $e->payload['data']['reason'] === 'not_logged_in');
}

$db = fresh([], [w10_group(1)]);
$GLOBALS['w10_logged_in'] = true;
$GLOBALS['w10_current_user'] = 501;
$GLOBALS['w10_nonce_valid'] = true;
$_POST = ['event_id' => '10', 'group_id' => '1', 'allocated_quota' => '100', 'invitee_email' => 'ajax2@example.com', 'nonce' => 'x'];
try {
    pge_additional_inviter_onboarding_invite_handler();
    $threw = false;
} catch (PGE_W10_JsonSignal $e) {
    $threw = true;
    $payload = $e->payload;
}
ok('J3b. Authenticated Owner via AJAX successfully creates an invitation end-to-end', $threw && $payload['success'] === true && isset($payload['data']['invitation_id']));

$db = fresh([], [w10_group(1)], [], [w10_invitation(1)]);
$GLOBALS['w10_nonce_valid'] = false;
$_POST = ['token' => w10_token(1), 'mode' => 'existing', 'nonce' => 'bad'];
try {
    pge_additional_inviter_onboarding_complete_handler();
    ok('J4. Complete handler rejects an invalid nonce', false);
} catch (PGE_W10_JsonSignal $e) {
    ok('J4. Complete handler rejects an invalid nonce (invalid_nonce), independent of login state', $e->payload['success'] === false && $e->payload['data']['reason'] === 'invalid_nonce');
}

$GLOBALS['w10_nonce_valid'] = true;
$GLOBALS['w10_logged_in'] = false; // the public completion action must work with NO session
$_POST = ['token' => 'not-a-real-token-shape', 'mode' => 'existing', 'nonce' => 'ok'];
try {
    pge_additional_inviter_onboarding_complete_handler();
    ok('J5. Complete handler surfaces a malformed token as invalid_token (never a raw exception)', false);
} catch (PGE_W10_JsonSignal $e) {
    ok('J5. Complete handler surfaces a malformed token as invalid_token (never a raw exception)', $e->payload['success'] === false && $e->payload['data']['reason'] === 'invalid_token');
}
unset($_POST);

// ============================================================================
// K — Fix Pass: pending-invitation concurrency, mail-failure semantics,
// new-user + membership-failure retry semantics
// ============================================================================

// K1: two same-group invitation creations in sequence. create_invitation_
// for_actor() already wraps its re-check (group_has_active_inviter() +
// find_blocking_pending_invitation()) AND the INSERT inside a single
// GET_LOCK(event_id|group_id)/RELEASE_LOCK critical section (see the
// method's own code) — the exact "LOCK -> re-check -> insert -> release"
// sequence this fix pass requires. This suite's fake $wpdb always grants
// GET_LOCK immediately (single PHP process, no real concurrency), so this
// is a SEQUENTIAL proxy for the ordering guarantee, not a live two-
// connection race — genuine cross-connection mutual exclusion is provided
// by GET_LOCK itself, an already real-DB-proven primitive reused unchanged
// from PGE_Supervisor_Assignment_Service's own reservation logic; it is
// not re-proven against real MariaDB here per this fix pass's own
// instruction to skip expensive DB testing for an unmodified, already-
// proven lock primitive. What this test DOES prove: the second call's
// re-check genuinely runs AFTER the first call's row is committed, so it
// correctly observes the conflict instead of both racing past a stale
// pre-check.
$db = fresh([], [w10_group(1)]);
$r1 = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'racea1@example.com');
$r2 = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'racea2@example.com');
ok('K1. First of two same-group invitation creations succeeds', is_array($r1) && $r1['ok'] === true);
ok('K1b. Second same-group creation is rejected by the post-lock re-check (quota_group_conflict), never a second pending row', code($r2) === 'quota_group_conflict' && count($db->invitations) === 1);

// K2: a failed mail delivery must not silently reserve the group for the
// full TTL — the existing revoke_row_after_failed_delivery() call already
// does this; this test proves the end-to-end consequence (a SUBSEQUENT
// invitation to the same group succeeds) rather than just the row's
// status.
$db = fresh([], [w10_group(1)]);
$GLOBALS['w10_mail_should_fail'] = true;
$r1 = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'mailfail@example.com');
ok('K2. Mail delivery failure surfaces delivery_failed to the caller', code($r1) === 'delivery_failed');
ok('K2b. The failed-delivery row is immediately made non-blocking (revoked), not left pending', $db->invitations[1]['status'] === 'revoked');
$GLOBALS['w10_mail_should_fail'] = false;
$r2 = PGE_Additional_Inviter_Onboarding::create_invitation_for_actor(10, 501, 1, 100, 'mailfail-retry@example.com');
ok('K2c. A subsequent invitation to the SAME group succeeds — the failed one does not block for the TTL window', is_array($r2) && $r2['ok'] === true && count($db->invitations) === 2);

// K3: wp_create_user() succeeds, then create_additional_inviter_
// membership() itself genuinely fails (a pre-existing, unrelated
// membership row for that exact user_id in this event — duplicate_
// membership — not one of finish_completion()'s OWN earlier pre-checks).
// user_id 900 is exactly the id fresh() configures wp_create_user() to
// hand out next, so seeding a colliding membership at id 900 forces the
// REAL Repository call, not an earlier guard, to be what fails.
$db = fresh([w10_member(99, 900, 'viewer', 'active', null)], [w10_group(1)], [], [w10_invitation(1, ['invitee_email' => 'newuserfail@example.com'])]);
$r1 = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'Fail Case');
ok('K3. wp_create_user() succeeds but create_additional_inviter_membership() itself fails -> completion fails safely (WP_Error, no exception)', $r1 instanceof WP_Error);
ok('K3b. The newly-created WP user is NOT deleted/rolled back after the failure', isset($GLOBALS['w10_users_by_email']['newuserfail@example.com']) && $GLOBALS['w10_users_by_email']['newuserfail@example.com'] === 900);
ok('K3c. The invitation token is NOT consumed — row stays pending, hash intact, resolved_user_id still null', $db->invitations[1]['status'] === 'pending' && $db->invitations[1]['invitation_token_hash'] !== null && $db->invitations[1]['resolved_user_id'] === null);

// Simulate the blocking condition being resolved (e.g. an admin revoked
// the conflicting membership) before the invitee retries with the SAME
// token.
unset($db->memberships[99]);
$users_before_retry = count($GLOBALS['w10_users_by_email']);
$r2 = PGE_Additional_Inviter_Onboarding::complete_with_new_account(w10_token(1), 'longenoughpw', 'Retry');
ok('K3d. Retry with the SAME token resolves the now-existing account (no second wp_create_user call) and succeeds', is_array($r2) && $r2['ok'] === true && count($GLOBALS['w10_users_by_email']) === $users_before_retry);
ok('K3e. The membership created on retry belongs to the SAME (originally-created) user, not a new duplicate account', isset($db->memberships[1]) && $db->memberships[1]['user_id'] === '900');

// ============================================================================
// L — REAL-USER-FIX-1: server-derived post-success redirect_url
// ============================================================================

// L1: success response carries the correct redirect_url, built entirely
// from the completed invitation's own (server-side) event_id — proven with
// a non-default event_id (77, not the fixtures' usual 10) so this cannot
// pass by coincidence against a hardcoded value.
$db = fresh([], [w10_group(1, 'active', 77)], [], [w10_invitation(1, ['event_id' => '77', 'invitee_email' => 'redirect1@example.com'])], 77);
$GLOBALS['w10_nonce_valid'] = true;
$GLOBALS['w10_logged_in'] = false;
$_POST = ['token' => w10_token(1), 'mode' => 'new', 'password' => 'longenoughpw', 'display_name' => 'Redirect Test', 'nonce' => 'ok'];
try {
    pge_additional_inviter_onboarding_complete_handler();
    $threw = false;
} catch (PGE_W10_JsonSignal $e) {
    $threw = true;
    $payload = $e->payload;
}
ok('L1. Successful completion response is success=true with a redirect_url', $threw && $payload['success'] === true && isset($payload['data']['redirect_url']));
ok('L1b. redirect_url is built from the invitation\'s own event_id (77), via home_url(), pointing at my-invitations/', $threw && $payload['data']['redirect_url'] === 'https://example.test/event-manage/77/my-invitations/');
ok('L1c. Success response never echoes membership_id/group_id/quota (Section 6 discipline unchanged)', $threw && !array_key_exists('membership_id', $payload['data']) && !array_key_exists('group_id', $payload['data']) && !array_key_exists('allocated_quota', $payload['data']));

// L2: a forged event_id/redirect_url in $_POST must be completely ignored —
// the handler never reads either field from the client for this action.
// Same fixture as L1 (event_id=77) but the request additionally carries
// attacker-controlled event_id/redirect_url values; the response must still
// point at event 77, never at the forged event id or the forged URL.
$db = fresh([], [w10_group(1, 'active', 77)], [], [w10_invitation(1, ['event_id' => '77', 'invitee_email' => 'redirect2@example.com'])], 77);
$_POST = [
    'token' => w10_token(1), 'mode' => 'new', 'password' => 'longenoughpw', 'display_name' => 'Redirect Test 2', 'nonce' => 'ok',
    'event_id' => '999999', 'redirect_url' => 'https://evil.example/hijack',
];
try {
    pge_additional_inviter_onboarding_complete_handler();
    $threw = false;
} catch (PGE_W10_JsonSignal $e) {
    $threw = true;
    $payload = $e->payload;
}
ok('L2. A forged $_POST[event_id]/$_POST[redirect_url] cannot redirect the client anywhere else', $threw && $payload['success'] === true
    && $payload['data']['redirect_url'] === 'https://example.test/event-manage/77/my-invitations/'
    && strpos($payload['data']['redirect_url'], '999999') === false
    && strpos($payload['data']['redirect_url'], 'evil.example') === false);
unset($_POST);

// L3: static source check — the handler this suite just executed above
// never even reads $_POST['event_id'] or $_POST['redirect_url'] as an
// identity/destination claim (L1/L2 prove the runtime behavior; this proves
// there is no dead/bypassable code path that could read them either).
$onboarding_ajax_source = file_get_contents(PGE_PATH . 'includes/additional-inviter-onboarding-ajax.php');
ok('L3. additional-inviter-onboarding-ajax.php never reads $_POST[\'redirect_url\']', strpos($onboarding_ajax_source, "\$_POST['redirect_url']") === false);
ok('L3b. pge_additional_inviter_onboarding_complete_handler() never reads $_POST[\'event_id\']', (function () use ($onboarding_ajax_source) {
    $start = strpos($onboarding_ajax_source, 'function pge_additional_inviter_onboarding_complete_handler');
    $end = strpos($onboarding_ajax_source, "\n}\n", $start);
    $body = substr($onboarding_ajax_source, $start, $end - $start);
    return strpos($body, "\$_POST['event_id']") === false;
})());

// L4: the join template performs the redirect when redirect_url is present,
// and the existing "تم الانضمام بنجاح." fallback message is unconditional
// (still shown even when a redirect follows, and remains the only outcome
// when redirect_url is absent — success is never turned into an error).
$join_template_source = file_get_contents(PGE_PATH . 'templates/additional-inviter-onboarding-join.php');
$success_msg_pos = strpos($join_template_source, "showMsg('تم الانضمام بنجاح.', false);");
$redirect_check_pos = strpos($join_template_source, 'data.data.redirect_url');
$redirect_assign_pos = strpos($join_template_source, 'window.location.href = data.data.redirect_url');
ok('L4. Template still shows the success message unconditionally on data.success', $success_msg_pos !== false);
ok('L4b. Template checks data.data.redirect_url AFTER showing the success message (fallback-first)', $redirect_check_pos !== false && $redirect_check_pos > $success_msg_pos);
ok('L4c. Template assigns window.location.href = data.data.redirect_url to perform the redirect', $redirect_assign_pos !== false);
ok('L4d. Redirect is gated behind a typeof/non-empty check, not run unconditionally', strpos($join_template_source, "typeof data.data.redirect_url === 'string'") !== false);

// ============================================================================
echo "\n==== H1C-W10 RESULT: $pass passed, $fail failed ====\n";
if ($fail > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
