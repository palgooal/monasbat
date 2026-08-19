<?php
/**
 * Phase H1C-W7 — Owner/Admin Group & Membership Read Wiring.
 *
 * Exercises the real PGE_Event_Access_Application_Service::
 * get_group_for_actor()/list_groups_for_actor()/get_membership_for_actor()/
 * list_memberships_for_actor(), PGE_Event_Access_Authorization[_Context]::
 * can_manage_event_structure()/can_manage_membership(), the real
 * PGE_Event_Access_Repository::get_group()/list_groups()/get_membership()/
 * list_memberships(), and the four real pge_event_access_{get_group,
 * list_groups,get_membership,list_memberships}_handler() AJAX handlers —
 * against a small in-memory fake $wpdb (H1B relational tables only). No
 * real database, no real network, no UI.
 *
 * Deliberately COMPACT (same house style as H1C-W6): behavioral CONTRACTS,
 * not every implementation line, and NOT a W2-W5-sized suite despite
 * covering four use cases instead of one.
 *
 * Run: php tests/test-event-access-application-phase-h1c-w7.php
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

// ──────────────────────────────────────────────────────────────
// Minimal WordPress error primitive
// ──────────────────────────────────────────────────────────────

class WP_Error
{
    private $code;
    private $message;

    public function __construct($code = '', $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

// ──────────────────────────────────────────────────────────────
// Hook registration
// ──────────────────────────────────────────────────────────────

$GLOBALS['w7_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['w7_registered_hooks'][$hook] = true; }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

// ──────────────────────────────────────────────────────────────
// WordPress function shims
// ──────────────────────────────────────────────────────────────

function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function wp_unslash($value) { return $value; }
function wp_json_encode($data) { return json_encode($data); }
function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }

$GLOBALS['w7_current_user_id'] = 0;
$GLOBALS['w7_logged_in'] = true;
$GLOBALS['w7_admins'] = [];
function get_current_user_id() { return $GLOBALS['w7_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['w7_logged_in']; }
function user_can($user_id, $capability)
{
    if ($capability !== 'administrator') return false;
    return in_array((int) $user_id, $GLOBALS['w7_admins'], true);
}

$GLOBALS['w7_posts'] = [];
function get_post_type($post_id)
{
    return $GLOBALS['w7_posts'][(int) $post_id]['post_type'] ?? false;
}
function get_post_field($field, $post_id)
{
    if (!isset($GLOBALS['w7_posts'][(int) $post_id][$field])) return '';
    return (string) $GLOBALS['w7_posts'][(int) $post_id][$field];
}

class PGE_Event_Access_Schema
{
    public static function is_ready() { return $GLOBALS['w7_schema_ready']; }
    public static function maybe_upgrade() { throw new RuntimeException('maybe_upgrade() must never be called by a W7 read path.'); }
}

// ──────────────────────────────────────────────────────────────
// In-memory fixture store + SQL dispatcher. Only the query shapes actually
// reachable from the four W7 use cases + resolve_context() are recognized.
// ──────────────────────────────────────────────────────────────

function w7_tables()
{
    return [
        'groups' => 'wp_pge_event_invitation_groups',
        'memberships' => 'wp_pge_event_host_memberships',
        'access' => 'wp_pge_event_host_group_access',
    ];
}

function w7_reset_db()
{
    $GLOBALS['w7_db'] = ['groups' => [], 'memberships' => [], 'access' => [], 'next_id' => 1];
    $GLOBALS['w7_posts'] = [];
    $GLOBALS['w7_admins'] = [];
    $GLOBALS['w7_schema_ready'] = true;
    $GLOBALS['w7_current_user_id'] = 0;
    $GLOBALS['w7_logged_in'] = true;
    $GLOBALS['w7_groups_read_calls'] = 0;
    $GLOBALS['w7_memberships_read_calls'] = 0;
}

function w7_next_id() { return $GLOBALS['w7_db']['next_id']++; }

function w7_event($event_id, $author_id)
{
    $GLOBALS['w7_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => $author_id];
}

function w7_admin($user_id) { $GLOBALS['w7_admins'][] = (int) $user_id; }

function w7_group($event_id, $status = 'active', $name = 'Group', $is_default = false)
{
    $id = w7_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w7_db']['groups'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'name' => $name,
        'name_key' => $status === 'active' ? strtolower($name) : null,
        'status' => $status, 'default_slot' => ($is_default && $status === 'active') ? 1 : null,
        'created_by_user_id' => 1, 'created_at' => $now, 'updated_at' => $now,
        'archived_at' => $status === 'archived' ? $now : null,
    ];
    return $id;
}

function w7_membership($event_id, $user_id, $role, $status = 'active')
{
    $id = w7_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w7_db']['memberships'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'user_id' => $user_id, 'role' => $role, 'status' => $status,
        'created_by_user_id' => 1, 'activated_at' => $now, 'revoked_at' => $status === 'revoked' ? $now : null,
        'created_at' => $now, 'updated_at' => $now,
    ];
    return $id;
}

function w7_access($event_id, $membership_id, $group_id)
{
    $GLOBALS['w7_db']['access'][] = ['event_id' => $event_id, 'membership_id' => $membership_id, 'group_id' => $group_id];
}

function w7_parse_simple_where($where)
{
    $conditions = [];
    foreach (explode(' AND ', $where) as $frag) {
        $frag = trim($frag);
        if (preg_match('/^event_id = (\d+)$/', $frag, $m)) { $conditions['event_id'] = (int) $m[1]; continue; }
        if (preg_match("/^status = '([^']*)'\$/", $frag, $m)) { $conditions['status'] = $m[1]; continue; }
        if (preg_match("/^role = '([^']*)'\$/", $frag, $m)) { $conditions['role'] = $m[1]; continue; }
        return null;
    }
    return $conditions;
}

function w7_matching_rows($table_key, $conditions)
{
    $rows = [];
    foreach (array_values($GLOBALS['w7_db'][$table_key]) as $row) {
        $ok = true;
        foreach ($conditions as $k => $v) {
            if ($row[$k] !== $v) { $ok = false; break; }
        }
        if ($ok) $rows[] = $row;
    }
    return $rows;
}

function w7_dispatch_scalar($sql)
{
    $t = w7_tables();
    $sql = trim($sql);
    if (preg_match('/^SELECT COUNT\(\*\) FROM ' . preg_quote($t['groups'], '/') . ' WHERE (.+)$/', $sql, $m)) {
        $conditions = w7_parse_simple_where($m[1]);
        if ($conditions === null) return false;
        $GLOBALS['w7_groups_read_calls']++;
        return count(w7_matching_rows('groups', $conditions));
    }
    if (preg_match('/^SELECT COUNT\(\*\) FROM ' . preg_quote($t['memberships'], '/') . ' WHERE (.+)$/', $sql, $m)) {
        $conditions = w7_parse_simple_where($m[1]);
        if ($conditions === null) return false;
        $GLOBALS['w7_memberships_read_calls']++;
        return count(w7_matching_rows('memberships', $conditions));
    }
    return false;
}

function w7_dispatch_select($sql)
{
    $t = w7_tables();
    $sql = trim($sql);

    // get_group()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['groups'], '/') . ' WHERE event_id = (\d+) AND id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $id = (int) $m[2];
        $GLOBALS['w7_groups_read_calls']++;
        return w7_matching_rows('groups', ['event_id' => $event_id, 'id' => $id]);
    }

    // list_groups()'s own paginated SELECT.
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['groups'], '/') . ' WHERE (.+) ORDER BY id ASC LIMIT (\d+) OFFSET (\d+)$/', $sql, $m)) {
        $conditions = w7_parse_simple_where($m[1]);
        if ($conditions === null) return null;
        $GLOBALS['w7_groups_read_calls']++;
        $rows = w7_matching_rows('groups', $conditions);
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return array_slice($rows, (int) $m[3], (int) $m[2]);
    }

    // get_membership() / get_membership_scoped()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $id = (int) $m[2];
        $GLOBALS['w7_memberships_read_calls']++;
        return w7_matching_rows('memberships', ['event_id' => $event_id, 'id' => $id]);
    }

    // get_membership_for_user() — consulted by resolve_context() for any
    // non-Admin, non-Owner actor. Deliberately NOT counted in
    // w7_memberships_read_calls (that counter proves the TARGET read for
    // get_membership/list_memberships is unreached, not context
    // resolution, which every non-owner/admin actor always goes through).
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND user_id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $user_id = (int) $m[2];
        return w7_matching_rows('memberships', ['event_id' => $event_id, 'user_id' => $user_id]);
    }

    // list_group_ids_for_membership() join — consulted for an active
    // collaborator's granted scope during resolve_context().
    $join = '/^SELECT a\.event_id, a\.group_id, g\.event_id AS related_event_id FROM '
        . preg_quote($t['access'], '/') . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.membership_id = (\d+) ORDER BY a\.group_id ASC$/';
    if (preg_match($join, $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2];
        $out = [];
        foreach ($GLOBALS['w7_db']['access'] as $row) {
            if ($row['event_id'] !== $event_id || $row['membership_id'] !== $membership_id) continue;
            $group = $GLOBALS['w7_db']['groups'][$row['group_id']] ?? null;
            $out[] = ['event_id' => $row['event_id'], 'group_id' => $row['group_id'], 'related_event_id' => $group['event_id'] ?? null];
        }
        return $out;
    }

    // list_memberships()'s own paginated SELECT.
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE (.+) ORDER BY id ASC LIMIT (\d+) OFFSET (\d+)$/', $sql, $m)) {
        $conditions = w7_parse_simple_where($m[1]);
        if ($conditions === null) return null;
        $GLOBALS['w7_memberships_read_calls']++;
        $rows = w7_matching_rows('memberships', $conditions);
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return array_slice($rows, (int) $m[3], (int) $m[2]);
    }

    return null;
}

class PGE_W7_Fake_WPDB
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;

    public function prepare($sql, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : ("'" . addslashes((string) $v) . "'");
        }, $sql);
    }

    public function get_results($sql, $output = null)
    {
        $rows = w7_dispatch_select($sql);
        if ($rows === null) {
            $this->last_error = 'w7-fake-wpdb: unrecognized SELECT: ' . $sql;
            return null;
        }
        return $rows;
    }

    public function get_var($sql)
    {
        $value = w7_dispatch_scalar($sql);
        if ($value === false) {
            $this->last_error = 'w7-fake-wpdb: unrecognized scalar query: ' . $sql;
            return null;
        }
        $this->last_error = '';
        return $value;
    }

    public function get_row($sql, $output = null)
    {
        $this->last_error = 'w7-fake-wpdb: unrecognized get_row() query: ' . $sql;
        return null;
    }

    // W7 is a pure read path — no write ever should reach $wpdb.
    public function query($sql)
    {
        $this->last_error = 'w7-fake-wpdb: unexpected mutating query() call: ' . $sql;
        return false;
    }

    public function insert($table, $data, $formats = null)
    {
        $this->last_error = 'w7-fake-wpdb: unexpected insert() call';
        return false;
    }

    public function update($table, $data, $where, $formats = null, $where_formats = null)
    {
        $this->last_error = 'w7-fake-wpdb: unexpected update() call';
        return false;
    }

    public function delete($table, $where, $formats = null)
    {
        $this->last_error = 'w7-fake-wpdb: unexpected delete() call';
        return false;
    }
}

w7_reset_db();
global $wpdb;
$wpdb = new PGE_W7_Fake_WPDB();

// ──────────────────────────────────────────────────────────────
// AJAX/JSON stubs
// ──────────────────────────────────────────────────────────────

class Test_Wp_Die_Exception extends \Exception {}

$GLOBALS['w7_json_response'] = null;
function wp_send_json_success($data = null)
{
    $GLOBALS['w7_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null)
{
    $GLOBALS['w7_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = [])
{
    throw new Test_Wp_Die_Exception('wp_die');
}

function w7_call_ajax_handler(callable $handler): array
{
    $GLOBALS['w7_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // expected
    }
    $raw = $GLOBALS['w7_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function w7_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_create_nonce($action) { return w7_nonce($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals(w7_nonce($action), (string) $nonce) ? 1 : false; }

require_once PGE_PATH . 'includes/helpers.php';
require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';
require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';
require_once PGE_PATH . 'includes/class-pge-event-access-application-service.php';
require_once PGE_PATH . 'includes/event-access-ajax.php';

// ──────────────────────────────────────────────────────────────
// Assertion helpers
// ──────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$failures = [];

function w7_ok($label, $condition, $extra = '')
{
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failed++;
        $failures[] = $label . ($extra !== '' ? " ({$extra})" : '');
        echo "FAIL: $label" . ($extra !== '' ? " ({$extra})" : '') . "\n";
    }
}

function w7_code($result) { return $result instanceof WP_Error ? $result->get_error_code() : null; }

$S = 'PGE_Event_Access_Application_Service';

function w7_ajax(callable $handler, array $extra_post): array
{
    $_POST = array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => 200], $extra_post);
    return w7_call_ajax_handler($handler);
}

// ══════════════════════════════════════════════════════════════
// Common fixture: event 200, owner=10, admin=20, four actor types.
// ══════════════════════════════════════════════════════════════

w7_reset_db();
w7_event(200, 10);
w7_admin(20);
$manager_id = 30; $viewer_id = 31; $revoked_id = 32; $stranger_id = 33;
// The manager's own membership row also doubles as the "target" id
// get_membership/list_memberships fixtures below fetch — deliberately NOT
// a second, duplicate row: a second active row for the same (event, user)
// would make get_membership_for_user()'s own LIMIT-2 ambiguity guard fire
// inside resolve_context() for the manager actor, masking the actual
// can_manage_membership() denial this suite means to exercise for A3/C2.
$target_membership_id = w7_membership(200, $manager_id, 'manager', 'active');
w7_membership(200, $viewer_id, 'viewer', 'active');
w7_membership(200, $revoked_id, 'manager', 'revoked');

$gid = w7_group(200, 'active', 'Family', true);
$gid2 = w7_group(200, 'active', 'Friends');
$missing_gid = 999999;
$missing_mid = 999999;

// ══════════════════════════════════════════════════════════════
// Section A — get_group
// ══════════════════════════════════════════════════════════════

$a1 = $S::get_group_for_actor(200, 10, $gid);
w7_ok('A1 owner get_group success', is_array($a1) && $a1['id'] === $gid && $a1['is_default'] === true, w7_code($a1));

$a2 = $S::get_group_for_actor(200, 20, $gid);
w7_ok('A2 admin get_group success', is_array($a2) && $a2['id'] === $gid);

foreach (['manager' => $manager_id, 'viewer' => $viewer_id, 'revoked' => $revoked_id, 'stranger' => $stranger_id] as $label => $uid) {
    $r = $S::get_group_for_actor(200, $uid, $gid);
    w7_ok("A3 $label denied for get_group", w7_code($r) === $S::REASON_NOT_AUTHORIZED, w7_code($r));
}

$a4 = $S::get_group_for_actor(200, 10, $missing_gid);
w7_ok('A4 missing group for owner -> not_found', w7_code($a4) === 'not_found', w7_code($a4));

$a5_existing = $S::get_group_for_actor(200, $stranger_id, $gid);
$a5_missing = $S::get_group_for_actor(200, $stranger_id, $missing_gid);
w7_ok('A5 existing vs missing group indistinguishable for a denied actor', w7_code($a5_existing) === w7_code($a5_missing) && w7_code($a5_existing) === $S::REASON_NOT_AUTHORIZED);

$GLOBALS['w7_current_user_id'] = $stranger_id;
$a6 = w7_ajax('pge_event_access_get_group_handler', ['event_id' => 200, 'group_id' => $gid, 'actor_user_id' => 10, 'user_id' => 10, 'owner_id' => 10, 'manager_id' => 10]);
w7_ok('A6 actor spoof: get_group AJAX ignores spoofed identity fields, real (denied) session still not_authorized',
    $a6['success'] === false && ($a6['data']['reason'] ?? null) === 'not_authorized', json_encode($a6));
$GLOBALS['w7_current_user_id'] = 0;

// ══════════════════════════════════════════════════════════════
// Section B — list_groups
// ══════════════════════════════════════════════════════════════

$b1 = $S::list_groups_for_actor(200, 10);
w7_ok('B1 owner/admin list_groups success', is_array($b1) && count($b1['items']) === 2 && $b1['total'] === 2, w7_code($b1));

$b2 = $S::list_groups_for_actor(200, 10, [], 1, 1);
w7_ok('B2 pagination honored (per_page=1 -> 1 item, total=2, total_pages=2)', is_array($b2) && count($b2['items']) === 1 && $b2['total_pages'] === 2);

$b3 = $S::list_groups_for_actor(200, 10, ['bogus_key' => 1]);
w7_ok('B3 unsupported filter key -> invalid_filter (only Repository\'s actual "status" filter is valid)', w7_code($b3) === 'invalid_filter', w7_code($b3));

$b3b = $S::list_groups_for_actor(200, 10, ['status' => 'active']);
w7_ok('B3b actual Repository filter (status) is honored end-to-end', is_array($b3b) && count($b3b['items']) === 2);

$b4a = $S::list_groups_for_actor(200, 10, [], 0);
w7_ok('B4a invalid page (0) -> invalid_input', w7_code($b4a) === 'invalid_input');
$b4b = $S::list_groups_for_actor(200, 10, ['status' => 'not_a_real_status']);
w7_ok('B4b invalid filter value -> invalid_filter', w7_code($b4b) === 'invalid_filter');

$GLOBALS['w7_groups_read_calls'] = 0;
$b5 = $S::list_groups_for_actor(200, $stranger_id);
w7_ok('B5 denied actor is rejected (not_authorized)', w7_code($b5) === $S::REASON_NOT_AUTHORIZED);
w7_ok('B5b denied actor: the groups table itself was never queried', $GLOBALS['w7_groups_read_calls'] === 0);

// ══════════════════════════════════════════════════════════════
// Section C — get_membership
// ══════════════════════════════════════════════════════════════

$c1 = $S::get_membership_for_actor(200, 10, $target_membership_id);
w7_ok('C1 owner/admin get_membership success', is_array($c1) && $c1['id'] === $target_membership_id && $c1['user_id'] === $manager_id, w7_code($c1));

foreach (['manager' => $manager_id, 'viewer' => $viewer_id, 'revoked' => $revoked_id, 'stranger' => $stranger_id] as $label => $uid) {
    $r = $S::get_membership_for_actor(200, $uid, $target_membership_id);
    w7_ok("C2 $label denied for get_membership", w7_code($r) === $S::REASON_NOT_AUTHORIZED, w7_code($r));
}

$c3 = $S::get_membership_for_actor(200, 10, $missing_mid);
w7_ok('C3 missing membership for owner -> not_found', w7_code($c3) === 'not_found', w7_code($c3));

$c4_existing = $S::get_membership_for_actor(200, $stranger_id, $target_membership_id);
$c4_missing = $S::get_membership_for_actor(200, $stranger_id, $missing_mid);
w7_ok('C4 existing vs missing membership indistinguishable for a denied actor', w7_code($c4_existing) === w7_code($c4_missing) && w7_code($c4_existing) === $S::REASON_NOT_AUTHORIZED);

$GLOBALS['w7_current_user_id'] = $stranger_id;
$c5 = w7_ajax('pge_event_access_get_membership_handler', ['event_id' => 200, 'membership_id' => $target_membership_id, 'actor_user_id' => 10, 'user_id' => 10, 'owner_id' => 10, 'manager_id' => 10]);
w7_ok('C5 actor spoof: get_membership AJAX ignores spoofed identity fields, real (denied) session still not_authorized',
    $c5['success'] === false && ($c5['data']['reason'] ?? null) === 'not_authorized', json_encode($c5));
$GLOBALS['w7_current_user_id'] = 0;

// ══════════════════════════════════════════════════════════════
// Section D — list_memberships
// ══════════════════════════════════════════════════════════════

$d1 = $S::list_memberships_for_actor(200, 10);
w7_ok('D1 owner/admin list_memberships success', is_array($d1) && $d1['total'] === 3, w7_code($d1)); // manager + viewer + revoked

$d2 = $S::list_memberships_for_actor(200, 10, [], 1, 2);
w7_ok('D2 pagination honored (per_page=2 -> 2 items, total=3, total_pages=2)', is_array($d2) && count($d2['items']) === 2 && $d2['total_pages'] === 2);

$d3 = $S::list_memberships_for_actor(200, 10, ['role' => 'manager']);
w7_ok('D3 actual Repository filter (role) is honored end-to-end', is_array($d3) && count($d3['items']) === 2); // both manager rows (active + revoked)

$d4a = $S::list_memberships_for_actor(200, 10, [], 1, 0);
w7_ok('D4a invalid per_page (0) -> invalid_input', w7_code($d4a) === 'invalid_input');
$d4b = $S::list_memberships_for_actor(200, 10, ['role' => 'not_a_real_role']);
w7_ok('D4b invalid filter value -> invalid_filter', w7_code($d4b) === 'invalid_filter');
$d4c = $S::list_memberships_for_actor(200, 10, ['user_id' => $manager_id]);
w7_ok('D4c a user_id filter key (not part of Repository\'s actual contract) -> invalid_filter, not silently accepted', w7_code($d4c) === 'invalid_filter');

$GLOBALS['w7_memberships_read_calls'] = 0;
$d5 = $S::list_memberships_for_actor(200, $stranger_id);
w7_ok('D5 denied actor is rejected (not_authorized)', w7_code($d5) === $S::REASON_NOT_AUTHORIZED);
w7_ok('D5b denied actor: the memberships table target read was never reached', $GLOBALS['w7_memberships_read_calls'] === 0);

// ══════════════════════════════════════════════════════════════
// Section E — EC1: existing-denied event vs nonexistent event, for all
// four use cases, must remain identically not_authorized.
// ══════════════════════════════════════════════════════════════

$e1a = $S::get_group_for_actor(200, $stranger_id, $gid);
$e1b = $S::get_group_for_actor(999999, $stranger_id, $gid);
w7_ok('E1 get_group EC1: existing-denied vs nonexistent event identical', w7_code($e1a) === w7_code($e1b) && w7_code($e1a) === $S::REASON_NOT_AUTHORIZED);

$e2a = $S::list_groups_for_actor(200, $stranger_id);
$e2b = $S::list_groups_for_actor(999999, $stranger_id);
w7_ok('E2 list_groups EC1: existing-denied vs nonexistent event identical', w7_code($e2a) === w7_code($e2b) && w7_code($e2a) === $S::REASON_NOT_AUTHORIZED);

$e3a = $S::get_membership_for_actor(200, $stranger_id, $target_membership_id);
$e3b = $S::get_membership_for_actor(999999, $stranger_id, $target_membership_id);
w7_ok('E3 get_membership EC1: existing-denied vs nonexistent event identical', w7_code($e3a) === w7_code($e3b) && w7_code($e3a) === $S::REASON_NOT_AUTHORIZED);

$e4a = $S::list_memberships_for_actor(200, $stranger_id);
$e4b = $S::list_memberships_for_actor(999999, $stranger_id);
w7_ok('E4 list_memberships EC1: existing-denied vs nonexistent event identical', w7_code($e4a) === w7_code($e4b) && w7_code($e4a) === $S::REASON_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════
// Section F — Public AJAX: transport structure.
// ══════════════════════════════════════════════════════════════

$hooks = [
    'pge_event_access_get_group' => 'pge_event_access_get_group_handler',
    'pge_event_access_list_groups' => 'pge_event_access_list_groups_handler',
    'pge_event_access_get_membership' => 'pge_event_access_get_membership_handler',
    'pge_event_access_list_memberships' => 'pge_event_access_list_memberships_handler',
];
foreach ($hooks as $action => $fn) {
    w7_ok("F1 wp_ajax_$action is registered", isset($GLOBALS['w7_registered_hooks']["wp_ajax_$action"]));
    w7_ok("F2 no wp_ajax_nopriv_$action registration exists", !isset($GLOBALS['w7_registered_hooks']["wp_ajax_nopriv_$action"]));
}

$ajax_source = file_get_contents(PGE_PATH . 'includes/event-access-ajax.php');
foreach ($hooks as $action => $fn) {
    $start = strpos($ajax_source, "function $fn()");
    $end = strpos($ajax_source, "add_action('wp_ajax_$action'", $start);
    w7_ok("F3 $fn source located", $start !== false && $end !== false && $end > $start);
    $body = ($start !== false && $end !== false) ? substr($ajax_source, $start, $end - $start) : '';
    w7_ok("F4 $fn calls PGE_Event_Access_Application_Service only", strpos($body, 'PGE_Event_Access_Application_Service::') !== false);
    w7_ok("F5 $fn never references PGE_Event_Access_Repository directly", strpos($body, 'PGE_Event_Access_Repository') === false);
    w7_ok("F6 $fn never references PGE_Event_Access_Authorization directly", strpos($body, 'PGE_Event_Access_Authorization') === false);
}

// Comment lines are stripped first — this class's own long-standing
// docblocks (predating W7) legitimately NAME $_POST/get_current_user_id()/
// current_user_can() in prose to document that it never calls them; only
// actual executable code is scanned here.
function w7_strip_comment_lines($source)
{
    $kept = [];
    foreach (explode("\n", $source) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] === '*' || strpos($trimmed, '//') === 0 || strpos($trimmed, '/*') === 0) continue;
        $kept[] = $line;
    }
    return implode("\n", $kept);
}
$app_service_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$app_service_code_only = w7_strip_comment_lines($app_service_source);
w7_ok('F7 Application Service has no executable $_POST access', strpos($app_service_code_only, '$_POST') === false);
w7_ok('F8 Application Service has no executable get_current_user_id() call', strpos($app_service_code_only, 'get_current_user_id(') === false);
w7_ok('F9 Application Service has no executable current_user_can() call', strpos($app_service_code_only, 'current_user_can(') === false);

// ══════════════════════════════════════════════════════════════
// Section G — Error fidelity.
// ══════════════════════════════════════════════════════════════

// invalid_filter preserved end-to-end through the AJAX boundary.
$GLOBALS['w7_current_user_id'] = 10;
$g1 = w7_ajax('pge_event_access_list_groups_handler', ['event_id' => 200, 'filter_status' => 'not_a_real_status']);
w7_ok('G1 AJAX invalid_filter fidelity: authorized owner, Repository-invalid filter -> invalid_filter, not server_error/not_authorized',
    $g1['success'] === false && ($g1['data']['reason'] ?? null) === 'invalid_filter', json_encode($g1));

// read_unavailable preserved end-to-end.
$GLOBALS['w7_schema_ready'] = false;
$g2 = w7_ajax('pge_event_access_get_group_handler', ['event_id' => 200, 'group_id' => $gid]);
$GLOBALS['w7_schema_ready'] = true;
w7_ok('G2 AJAX read_unavailable fidelity: authorized owner, schema not ready -> read_unavailable, not server_error/not_authorized',
    $g2['success'] === false && ($g2['data']['reason'] ?? null) === 'read_unavailable', json_encode($g2));

// database_error -> server_error, no internal detail leaked. A group row
// with a status value outside GROUP_STATUSES makes normalize_group()
// return database_error() when list_groups() tries to normalize it.
$GLOBALS['w7_db']['groups'][w7_next_id()] = [
    'id' => $GLOBALS['w7_db']['next_id'] - 1, 'event_id' => 200, 'name' => 'Corrupt', 'name_key' => 'corrupt',
    'status' => 'not_a_real_status', 'default_slot' => null, 'created_by_user_id' => 1,
    'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', 'archived_at' => null,
];
$g3 = w7_ajax('pge_event_access_list_groups_handler', ['event_id' => 200, 'per_page' => 100]);
w7_ok('G3 AJAX database_error collapses to the generic server_error bucket, leaking no internal detail',
    $g3['success'] === false && ($g3['data']['reason'] ?? null) === 'server_error', json_encode($g3));
$g3_serialized = strtolower(json_encode($g3));
w7_ok('G3b no database/sql/table substring leaked', strpos($g3_serialized, 'database') === false && strpos($g3_serialized, 'sql') === false && strpos($g3_serialized, 'table') === false);
$GLOBALS['w7_current_user_id'] = 0;

// ══════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════

echo "\n" . str_repeat('=', 60) . "\n";
echo "H1C-W7: {$passed} passed, {$failed} failed (" . ($passed + $failed) . " total)\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo " - $f\n";
}
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
