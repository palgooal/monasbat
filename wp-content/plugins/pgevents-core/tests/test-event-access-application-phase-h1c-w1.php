<?php
/**
 * Phase H1C-W1 — Collaborator Read-Only Application Wiring.
 *
 * Exercises the real PGE_Event_Access_Application_Service,
 * PGE_Event_Access_Authorization[_Context], PGE_Event_Access_Repository,
 * the real pge_event_guests_get_map()/get_row_payload() guest store
 * functions from event-guests.php, and the real
 * pge_event_access_list_guests_handler() AJAX handler — against a small
 * in-memory fake $wpdb (H1B relational tables + the RSVP table) and a fake
 * Post Meta store (`_pge_invited_guests`). No real database, no real
 * network, no UI.
 *
 * Run: php tests/test-event-access-application-phase-h1c-w1.php
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
// Hook registration — records real hook names so W1 can prove no
// wp_ajax_nopriv_ registration exists, without executing WordPress itself.
// ──────────────────────────────────────────────────────────────

$GLOBALS['w1_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['w1_registered_hooks'][$hook] = true; }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

// ──────────────────────────────────────────────────────────────
// WordPress function shims
// ──────────────────────────────────────────────────────────────

function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function wp_unslash($value) { return $value; }
function wp_json_encode($data) { return json_encode($data); }
function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }

$GLOBALS['w1_current_user_id'] = 0;
$GLOBALS['w1_logged_in'] = true;
$GLOBALS['w1_admins'] = [];
function get_current_user_id() { return $GLOBALS['w1_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['w1_logged_in']; }
// Authorization Context calls user_can($actor_user_id, 'administrator') —
// deliberately NOT current_user_can() (no session dependency inside the
// Authorization Core, unchanged contract from H1C-A2).
function user_can($user_id, $capability)
{
    if ($capability !== 'administrator') return false;
    return in_array((int) $user_id, $GLOBALS['w1_admins'], true);
}

$GLOBALS['w1_posts'] = [];
function get_post_type($post_id)
{
    return $GLOBALS['w1_posts'][(int) $post_id]['post_type'] ?? false;
}
function get_post_field($field, $post_id)
{
    if (!isset($GLOBALS['w1_posts'][(int) $post_id][$field])) return '';
    return (string) $GLOBALS['w1_posts'][(int) $post_id][$field];
}

// ──────────────────────────────────────────────────────────────
// Fake Post Meta store (`_pge_invited_guests` and friends) — instrumented
// so tests can prove WHEN (and whether) guest PII storage was touched.
// ──────────────────────────────────────────────────────────────

$GLOBALS['w1_post_meta'] = [];
$GLOBALS['w1_access_log'] = [];
$GLOBALS['w1_guest_postmeta_call_count'] = 0;

function get_post_meta($post_id, $key = '', $single = false)
{
    if ($key === '_pge_invited_guests') {
        $GLOBALS['w1_guest_postmeta_call_count']++;
        $GLOBALS['w1_access_log'][] = 'postmeta:_pge_invited_guests:' . $post_id;
    }
    $value = $GLOBALS['w1_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_post_meta($post_id, $key, $value)
{
    $GLOBALS['w1_post_meta'][$post_id][$key] = $value;
    return true;
}
function delete_post_meta($post_id, $key)
{
    unset($GLOBALS['w1_post_meta'][$post_id][$key]);
    return true;
}

// ──────────────────────────────────────────────────────────────
// Fake schema — always "ready" unless a test flips it.
// ──────────────────────────────────────────────────────────────

class PGE_Event_Access_Schema
{
    public static function is_ready()
    {
        return $GLOBALS['w1_schema_ready'];
    }

    public static function maybe_upgrade()
    {
        throw new RuntimeException('maybe_upgrade() must never be called by a W1 read path.');
    }
}

// ──────────────────────────────────────────────────────────────
// In-memory fixture store + SQL-shape dispatcher (H1B relational tables +
// the RSVP table event-guests.php reads status from).
// ──────────────────────────────────────────────────────────────

function w1_tables()
{
    return [
        'groups' => 'wp_pge_event_invitation_groups',
        'memberships' => 'wp_pge_event_host_memberships',
        'access' => 'wp_pge_event_host_group_access',
        'assignments' => 'wp_pge_invitation_group_assignments',
        'rsvps' => 'wp_pge_event_rsvps',
    ];
}

function w1_reset_db()
{
    $GLOBALS['w1_db'] = [
        'groups' => [],
        'memberships' => [],
        'access' => [],
        'assignments' => [],
        'rsvps' => [],
        'next_id' => 1,
        'query_count' => 0,
    ];
    $GLOBALS['w1_posts'] = [];
    $GLOBALS['w1_admins'] = [];
    $GLOBALS['w1_schema_ready'] = true;
    $GLOBALS['w1_post_meta'] = [];
    $GLOBALS['w1_access_log'] = [];
    $GLOBALS['w1_guest_postmeta_call_count'] = 0;
    $GLOBALS['w1_current_user_id'] = 0;
    $GLOBALS['w1_logged_in'] = true;
}

function w1_next_id() { return $GLOBALS['w1_db']['next_id']++; }

function w1_event($event_id, $author_id)
{
    $GLOBALS['w1_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => $author_id];
}

function w1_group($event_id, $status = 'active', $name = 'Group')
{
    $id = w1_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w1_db']['groups'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'name' => $name,
        'name_key' => $status === 'active' ? strtolower($name) . '-' . $id : null,
        'status' => $status, 'default_slot' => null, 'created_by_user_id' => 1,
        'created_at' => $now, 'updated_at' => $now,
        'archived_at' => $status === 'archived' ? $now : null,
    ];
    return $id;
}

function w1_membership($event_id, $user_id, $role, $status = 'active')
{
    $id = w1_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w1_db']['memberships'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'user_id' => $user_id, 'role' => $role, 'status' => $status,
        'created_by_user_id' => 1, 'activated_at' => $now, 'revoked_at' => $status === 'revoked' ? $now : null,
        'created_at' => $now, 'updated_at' => $now,
    ];
    return $id;
}

function w1_access($event_id, $membership_id, $group_id)
{
    $id = w1_next_id();
    $GLOBALS['w1_db']['access'][] = [
        'id' => $id, 'event_id' => $event_id, 'membership_id' => $membership_id, 'group_id' => $group_id,
        'granted_by_user_id' => 1, 'created_at' => '2026-01-01 00:00:00',
    ];
    return $id;
}

function w1_assignment($event_id, $phone, $group_id)
{
    $id = w1_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w1_db']['assignments'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'guest_phone' => $phone, 'group_id' => $group_id,
        'assigned_by_user_id' => 1, 'created_at' => $now, 'updated_at' => $now,
    ];
    return $id;
}

function w1_rsvp($event_id, $phone, $reply = 'pending', $checked_in = 0)
{
    $GLOBALS['w1_db']['rsvps'][] = ['event_id' => $event_id, 'guest_phone' => $phone, 'reply' => $reply, 'checked_in' => $checked_in];
}

/**
 * Directly writes `_pge_invited_guests` — deliberately bypasses
 * pge_event_guests_save_map() so a test can also construct a malformed
 * entry on purpose (Section 29's "malformed guest record" fixture).
 */
function w1_set_guest($event_id, $phone, $name, $note = '', $code = 'ABCD-1234')
{
    $existing = $GLOBALS['w1_post_meta'][$event_id]['_pge_invited_guests'] ?? [];
    if (!is_array($existing)) $existing = [];
    $existing[$phone] = ['phone' => $phone, 'name' => $name, 'note' => $note, 'code' => $code];
    $GLOBALS['w1_post_meta'][$event_id]['_pge_invited_guests'] = $existing;
}

function w1_set_malformed_guest($event_id, $phone)
{
    $existing = $GLOBALS['w1_post_meta'][$event_id]['_pge_invited_guests'] ?? [];
    if (!is_array($existing)) $existing = [];
    // A shape pge_event_guests_get_map() cannot normalize into a usable
    // record: not an array at all (a bare integer), unlike the
    // "not-array-but-string" legacy compatibility shape it does handle.
    $existing[$phone] = 12345;
    $GLOBALS['w1_post_meta'][$event_id]['_pge_invited_guests'] = $existing;
}

function w1_remove_guest($event_id, $phone)
{
    $existing = $GLOBALS['w1_post_meta'][$event_id]['_pge_invited_guests'] ?? [];
    if (!is_array($existing)) return;
    unset($existing[$phone]);
    $GLOBALS['w1_post_meta'][$event_id]['_pge_invited_guests'] = $existing;
}

function w1_dispatch_select($sql)
{
    $t = w1_tables();
    $sql = trim($sql);
    $GLOBALS['w1_access_log'][] = 'db:query';

    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND user_id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $user_id = (int) $m[2];
        return array_values(array_filter($GLOBALS['w1_db']['memberships'], function ($r) use ($event_id, $user_id) {
            return $r['event_id'] === $event_id && $r['user_id'] === $user_id;
        }));
    }

    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $id = (int) $m[2];
        return array_values(array_filter($GLOBALS['w1_db']['memberships'], function ($r) use ($event_id, $id) {
            return $r['event_id'] === $event_id && $r['id'] === $id;
        }));
    }

    $join = '/^SELECT a\.event_id, a\.group_id, g\.event_id AS related_event_id FROM '
        . preg_quote($t['access'], '/') . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.membership_id = (\d+) ORDER BY a\.group_id ASC$/';
    if (preg_match($join, $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2];
        $out = [];
        foreach ($GLOBALS['w1_db']['access'] as $row) {
            if ($row['event_id'] !== $event_id || $row['membership_id'] !== $membership_id) continue;
            $group = $GLOBALS['w1_db']['groups'][$row['group_id']] ?? null;
            $out[] = ['event_id' => $row['event_id'], 'group_id' => $row['group_id'], 'related_event_id' => $group['event_id'] ?? null];
        }
        return $out;
    }

    $scoped = '/^SELECT a\.\*, g\.event_id AS related_event_id FROM ' . preg_quote($t['assignments'], '/')
        . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.group_id IN \(([\d, ]+)\) ORDER BY a\.id ASC LIMIT (\d+) OFFSET (\d+)$/';
    if (preg_match($scoped, $sql, $m)) {
        $event_id = (int) $m[1];
        $ids = array_map('intval', array_map('trim', explode(',', $m[2])));
        $limit = (int) $m[3]; $offset = (int) $m[4];
        $rows = [];
        foreach ($GLOBALS['w1_db']['assignments'] as $row) {
            if ($row['event_id'] !== $event_id || !in_array($row['group_id'], $ids, true)) continue;
            $group = $GLOBALS['w1_db']['groups'][$row['group_id']] ?? null;
            $rows[] = array_merge($row, ['related_event_id' => $group['event_id'] ?? null]);
        }
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return array_slice($rows, $offset, $limit);
    }

    $byPhone = '/^SELECT a\.\*, g\.event_id AS related_event_id FROM ' . preg_quote($t['assignments'], '/')
        . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.guest_phone IN \(([^)]*)\) ORDER BY a\.id ASC$/';
    if (preg_match($byPhone, $sql, $m)) {
        $event_id = (int) $m[1];
        $phones = array_map(function ($p) { return trim(trim($p), "'"); }, explode(',', $m[2]));
        $rows = [];
        foreach ($GLOBALS['w1_db']['assignments'] as $row) {
            if ($row['event_id'] !== $event_id || !in_array($row['guest_phone'], $phones, true)) continue;
            $group = $GLOBALS['w1_db']['groups'][$row['group_id']] ?? null;
            $rows[] = array_merge($row, ['related_event_id' => $group['event_id'] ?? null]);
        }
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    if (preg_match('/^SELECT guest_phone, reply, checked_in FROM ' . preg_quote($t['rsvps'], '/') . ' WHERE event_id = (\d+)$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $out = [];
        foreach ($GLOBALS['w1_db']['rsvps'] as $row) {
            if ($row['event_id'] !== $event_id) continue;
            $out[] = ['guest_phone' => $row['guest_phone'], 'reply' => $row['reply'], 'checked_in' => $row['checked_in']];
        }
        return $out;
    }

    return null;
}

function w1_dispatch_scalar($sql)
{
    $t = w1_tables();
    $sql = trim($sql);
    $GLOBALS['w1_access_log'][] = 'db:query';
    $pattern = '/^SELECT COUNT\(\*\) FROM ' . preg_quote($t['assignments'], '/') . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.group_id IN \(([\d, ]+)\)$/';
    if (preg_match($pattern, $sql, $m)) {
        $event_id = (int) $m[1];
        $ids = array_map('intval', array_map('trim', explode(',', $m[2])));
        $count = 0;
        foreach ($GLOBALS['w1_db']['assignments'] as $row) {
            if ($row['event_id'] === $event_id && in_array($row['group_id'], $ids, true)) $count++;
        }
        return $count;
    }
    return false;
}

class PGE_W1_Fake_WPDB
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $write_calls = 0;

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
        $GLOBALS['w1_db']['query_count']++;
        $rows = w1_dispatch_select($sql);
        if ($rows === null) {
            $this->last_error = 'w1-fake-wpdb: unrecognized SELECT: ' . $sql;
            return null;
        }
        return $rows;
    }

    public function get_var($sql)
    {
        $GLOBALS['w1_db']['query_count']++;
        $value = w1_dispatch_scalar($sql);
        if ($value === false) {
            $this->last_error = 'w1-fake-wpdb: unrecognized scalar query: ' . $sql;
            return null;
        }
        return $value;
    }

    public function query($sql)
    {
        $this->write_calls++;
        $this->last_error = 'w1-fake-wpdb: unexpected mutating query() call: ' . $sql;
        return false;
    }

    public function delete($table, $where, $formats)
    {
        $this->write_calls++;
        $this->last_error = 'w1-fake-wpdb: unexpected delete() call';
        return false;
    }

    public function insert($table, $data, $formats = null)
    {
        $this->write_calls++;
        $this->last_error = 'w1-fake-wpdb: unexpected insert() call';
        return false;
    }
}

w1_reset_db();
global $wpdb;
$wpdb = new PGE_W1_Fake_WPDB();

// ──────────────────────────────────────────────────────────────
// AJAX/JSON stubs
// ──────────────────────────────────────────────────────────────

class Test_Wp_Die_Exception extends \Exception {}

$GLOBALS['w1_json_response'] = null;
function wp_send_json_success($data = null)
{
    $GLOBALS['w1_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null)
{
    $GLOBALS['w1_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = [])
{
    throw new Test_Wp_Die_Exception('wp_die');
}

function w1_call_ajax_handler(callable $handler): array
{
    $GLOBALS['w1_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // expected
    }
    $raw = $GLOBALS['w1_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function w1_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_create_nonce($action) { return w1_nonce($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals(w1_nonce($action), (string) $nonce) ? 1 : false; }

require_once PGE_PATH . 'includes/helpers.php';
require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';
require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';
require_once PGE_PATH . 'includes/class-pge-event-access-application-service.php';
require_once PGE_PATH . 'includes/event-guests.php';
require_once PGE_PATH . 'includes/event-access-ajax.php';

// ──────────────────────────────────────────────────────────────
// Assertion helpers
// ──────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$failures = [];

function w1_ok($label, $condition, $extra = '')
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

function w1_code($result) { return $result instanceof WP_Error ? $result->get_error_code() : null; }

$S = 'PGE_Event_Access_Application_Service';

// ══════════════════════════════════════════════════════════════
// Section A — Owner / Admin read path
// ══════════════════════════════════════════════════════════════

w1_reset_db();
w1_event(100, 50); // owner = user 50
$gA1 = w1_group(100, 'active', 'Family');
$gA2 = w1_group(100, 'active', 'Friends');

w1_set_guest(100, '970000000001', 'Owner Guest Assigned', 'note-1', 'AAAA-1111');
w1_set_guest(100, '970000000002', 'Owner Guest Unassigned', 'note-2', 'BBBB-2222');
w1_assignment(100, '970000000001', $gA1);
w1_rsvp(100, '970000000001', 'yes', 1);
w1_rsvp(100, '970000000002', 'pending', 0);

$owner_result = $S::list_accessible_guests_for_actor(100, 50);
w1_ok('A1 owner call succeeds (not a WP_Error)', is_array($owner_result));
w1_ok('A2 owner sees both guests (assigned and unassigned)', is_array($owner_result) && count($owner_result['items']) === 2);

$owner_phones = is_array($owner_result) ? array_map(function ($i) { return $i['guest']['phone']; }, $owner_result['items']) : [];
sort($owner_phones);
w1_ok('A3 owner sees the unassigned guest too', in_array('970000000002', $owner_phones, true));
w1_ok('A4 owner sees the assigned guest too', in_array('970000000001', $owner_phones, true));

$owner_assigned_item = null;
$owner_unassigned_item = null;
foreach ($owner_result['items'] as $item) {
    if ($item['guest']['phone'] === '970000000001') $owner_assigned_item = $item;
    if ($item['guest']['phone'] === '970000000002') $owner_unassigned_item = $item;
}
w1_ok('A5 owner assigned-guest item carries the correct group_id', $owner_assigned_item !== null && $owner_assigned_item['group_id'] === $gA1);
w1_ok('A6 owner unassigned-guest item has group_id null', $owner_unassigned_item !== null && $owner_unassigned_item['group_id'] === null);
w1_ok(
    'A7 owner projection is Full (phone/name/note/code/status/status_label/checked)',
    $owner_assigned_item !== null
    && $owner_assigned_item['guest'] === [
        'phone' => '970000000001', 'name' => 'Owner Guest Assigned', 'note' => 'note-1', 'code' => 'AAAA-1111',
        'status' => 'yes', 'status_label' => 'سيحضر', 'checked' => 'yes',
    ]
);

$GLOBALS['w1_admins'] = [77];
$admin_result = $S::list_accessible_guests_for_actor(100, 77);
w1_ok('A8 admin call succeeds', is_array($admin_result));
w1_ok('A9 admin sees the same full guest set as owner, without any membership row', is_array($admin_result) && count($admin_result['items']) === 2);
$GLOBALS['w1_admins'] = [];

// ══════════════════════════════════════════════════════════════
// Section B — Manager scoped read path
// ══════════════════════════════════════════════════════════════

w1_reset_db();
w1_event(200, 60);
$gB1 = w1_group(200, 'active', 'Family');
$gB2 = w1_group(200, 'active', 'Friends');
$mgrMembership = w1_membership(200, 61, 'manager', 'active');
w1_access(200, $mgrMembership, $gB1);

w1_set_guest(200, '970000011111', 'Guest G1', 'g1-note', 'CCCC-1111');
w1_set_guest(200, '970000022222', 'Guest G2', 'g2-note', 'DDDD-2222');
w1_set_guest(200, '970000033333', 'Guest Unassigned', 'un-note', 'EEEE-3333');
w1_assignment(200, '970000011111', $gB1); // granted to manager
w1_assignment(200, '970000022222', $gB2); // NOT granted to manager
// 970000033333 stays unassigned
w1_rsvp(200, '970000011111', 'yes', 1);
w1_rsvp(200, '970000022222', 'no', 0);

$mgr_result = $S::list_accessible_guests_for_actor(200, 61);
w1_ok('B1 manager call succeeds', is_array($mgr_result));
w1_ok('B2 manager sees exactly one guest (only the granted-group one)', is_array($mgr_result) && count($mgr_result['items']) === 1);
$mgr_phones = is_array($mgr_result) ? array_map(function ($i) { return $i['guest']['phone']; }, $mgr_result['items']) : [];
w1_ok('B3 (Section 11) manager sees Guest G1 (granted group)', in_array('970000011111', $mgr_phones, true));
w1_ok('B4 (Section 11) manager does NOT see Guest G2 (ungranted group)', !in_array('970000022222', $mgr_phones, true));
w1_ok('B5 (Section 11) manager does NOT see the unassigned guest', !in_array('970000033333', $mgr_phones, true));
w1_ok(
    'B6 (Section 20) manager projection includes note, excludes code',
    $mgr_result['items'][0]['guest'] === [
        'phone' => '970000011111', 'name' => 'Guest G1', 'note' => 'g1-note',
        'status' => 'yes', 'status_label' => 'سيحضر', 'checked' => 'yes',
    ]
);
w1_ok('B7 manager item carries the correct granted group_id', $mgr_result['items'][0]['group_id'] === $gB1);

// No placeholder for the out-of-scope/unassigned guests — simply absent,
// never a listed item with a denial reason attached (Section 12).
w1_ok('B8 (Section 12) result item count matches visible-guest count exactly, no placeholders for omitted guests', count($mgr_result['items']) === 1);

// ══════════════════════════════════════════════════════════════
// Section C — Viewer scoped read path
// ══════════════════════════════════════════════════════════════

$viewerMembership = w1_membership(200, 62, 'viewer', 'active');
w1_access(200, $viewerMembership, $gB2);
w1_rsvp(200, '970000033333', 'pending', 0);

$viewer_result = $S::list_accessible_guests_for_actor(200, 62);
w1_ok('C1 viewer call succeeds', is_array($viewer_result));
w1_ok('C2 viewer sees exactly one guest (only their granted group)', is_array($viewer_result) && count($viewer_result['items']) === 1);
w1_ok('C3 viewer sees Guest G2 (granted group)', $viewer_result['items'][0]['guest']['phone'] === '970000022222');
w1_ok(
    'C4 (Section 20) viewer projection excludes both note and code',
    $viewer_result['items'][0]['guest'] === [
        'phone' => '970000022222', 'name' => 'Guest G2', 'status' => 'no', 'status_label' => 'اعتذر', 'checked' => 'no',
    ]
);
w1_ok('C5 viewer does not see the unassigned guest', count(array_filter($viewer_result['items'], function ($i) { return $i['guest']['phone'] === '970000033333'; })) === 0);
w1_ok('C6 viewer does not see the manager-granted guest (G1)', count(array_filter($viewer_result['items'], function ($i) { return $i['guest']['phone'] === '970000011111'; })) === 0);

// ══════════════════════════════════════════════════════════════
// Section D — Zero grants: successful empty result, NO guest-store access
// ══════════════════════════════════════════════════════════════

$zeroMembership = w1_membership(200, 63, 'manager', 'active'); // active, but never granted any group
$GLOBALS['w1_guest_postmeta_call_count'] = 0;
$zero_result = $S::list_accessible_guests_for_actor(200, 63);
w1_ok('D1 (Section 22) active zero-grant manager gets a successful result', is_array($zero_result));
w1_ok('D2 zero-grant result has an empty items list', is_array($zero_result) && $zero_result['items'] === []);
w1_ok('D3 (Section 22) zero-grant read never touches the guest Post Meta store', $GLOBALS['w1_guest_postmeta_call_count'] === 0);

// ══════════════════════════════════════════════════════════════
// Section E — Revoked / stranger: denial, never an empty success
// ══════════════════════════════════════════════════════════════

$revokedMembership = w1_membership(200, 64, 'manager', 'revoked');
$GLOBALS['w1_guest_postmeta_call_count'] = 0;
$revoked_result = $S::list_accessible_guests_for_actor(200, 64);
w1_ok('E1 (Section 21) revoked manager is denied, not an empty success', w1_code($revoked_result) === PGE_Event_Access_Application_Service::REASON_NOT_AUTHORIZED);
w1_ok('E2 revoked-membership denial never touches guest Post Meta either', $GLOBALS['w1_guest_postmeta_call_count'] === 0);

$GLOBALS['w1_guest_postmeta_call_count'] = 0;
$stranger_result = $S::list_accessible_guests_for_actor(200, 65); // never a member at all
w1_ok('E3 (Section 21) stranger is denied, not an empty success', w1_code($stranger_result) === PGE_Event_Access_Application_Service::REASON_NOT_AUTHORIZED);
w1_ok('E4 stranger denial never touches guest Post Meta', $GLOBALS['w1_guest_postmeta_call_count'] === 0);

// ══════════════════════════════════════════════════════════════
// Section F — Malformed membership / cross-event corruption: fail closed
// ══════════════════════════════════════════════════════════════

w1_membership(200, 66, 'manager', 'active');
w1_membership(200, 66, 'viewer', 'active'); // duplicate/ambiguous -> Repository already fails this closed
w1_ok('F1 malformed/duplicate membership fails closed (propagated from Repository)', w1_code($S::list_accessible_guests_for_actor(200, 66)) === 'database_error');

// A membership row for a DIFFERENT event than the one being queried must
// never leak into this event's authorization decision.
w1_event(201, 70);
w1_membership(201, 67, 'manager', 'active'); // membership belongs to event 201
w1_ok('F2 cross-event membership is not usable to gain access to event 200', w1_code($S::list_accessible_guests_for_actor(200, 67)) === PGE_Event_Access_Application_Service::REASON_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════
// Section G — Missing / malformed guest record for an authorized
// assignment: fail closed, never a silent drop (Section 13/29)
// ══════════════════════════════════════════════════════════════

w1_reset_db();
w1_event(300, 80);
$gG1 = w1_group(300, 'active', 'G1');
$corruptMembership = w1_membership(300, 81, 'manager', 'active');
w1_access(300, $corruptMembership, $gG1);
w1_assignment(300, '970000099999', $gG1); // authorized assignment...
// ...but NO guest record exists at all for this phone (Post Meta never had it).
w1_ok('G1 missing guest record for an authorized assignment fails the whole read closed', w1_code($S::list_accessible_guests_for_actor(300, 81)) === PGE_Event_Access_Application_Service::REASON_GUEST_DATA_ERROR);

w1_reset_db();
w1_event(301, 82);
$gG2 = w1_group(301, 'active', 'G1');
$malformedMembership = w1_membership(301, 83, 'manager', 'active');
w1_access(301, $malformedMembership, $gG2);
w1_assignment(301, '970000088888', $gG2);
w1_set_malformed_guest(301, '970000088888'); // present, but not an array-shaped record
// Documented real behavior (not this phase's decision to change):
// pge_event_guests_get_map() itself already normalizes a non-array Post
// Meta entry into a safe, valid, EMPTY placeholder record (phone recovered
// from the array key, name/note/code = '') rather than ever producing
// something this service's own defensive is_array()/array_key_exists('phone')
// guard would reject. This is a pre-existing, separately-approved safety
// net in event-guests.php, not a gap introduced here — the guard in
// PGE_Event_Access_Application_Service is kept anyway as an extra backstop
// (see the source comment), even though this specific fixture cannot
// reach it through pge_event_guests_get_map() as it exists today.
$malformed_result = $S::list_accessible_guests_for_actor(301, 83);
w1_ok('G2 a malformed (non-array) Post Meta guest entry never crashes and never leaks stale/garbage data — it safely degrades to an empty-but-valid guest record', is_array($malformed_result) && count($malformed_result['items']) === 1);
w1_ok('G2b the degraded record still carries the correct phone (recovered from the storage key) with empty name/note', is_array($malformed_result) && $malformed_result['items'][0]['guest']['phone'] === '970000088888' && $malformed_result['items'][0]['guest']['name'] === '' && $malformed_result['items'][0]['guest']['note'] === '');

// Pagination never silently drifts: with two authorized assignments where
// one has no guest record, the read must fail entirely (never "return the
// one good item, total says 2").
w1_reset_db();
w1_event(302, 84);
$gG3 = w1_group(302, 'active', 'G1');
$driftMembership = w1_membership(302, 85, 'manager', 'active');
w1_access(302, $driftMembership, $gG3);
w1_assignment(302, '970000077777', $gG3);
w1_assignment(302, '970000066666', $gG3);
w1_set_guest(302, '970000077777', 'Has A Record', 'n', 'FFFF-1111');
// 970000066666 has no guest record at all.
w1_ok('G3 (Section 13) partial guest-record corruption never yields a smaller-than-total items list; the read fails closed entirely instead', w1_code($S::list_accessible_guests_for_actor(302, 85)) === PGE_Event_Access_Application_Service::REASON_GUEST_DATA_ERROR);

// ══════════════════════════════════════════════════════════════
// Section H — Scoped PII loading order proof (Section 23)
// ══════════════════════════════════════════════════════════════

w1_reset_db();
w1_event(400, 90);
$gH1 = w1_group(400, 'active', 'G1');
$orderMembership = w1_membership(400, 91, 'manager', 'active');
w1_access(400, $orderMembership, $gH1);
w1_assignment(400, '970000055555', $gH1);
w1_set_guest(400, '970000055555', 'Order Guest', 'note', 'GGGG-1111');
w1_rsvp(400, '970000055555', 'yes', 1);

$GLOBALS['w1_access_log'] = [];
$order_result = $S::list_accessible_guests_for_actor(400, 91);
$log = $GLOBALS['w1_access_log'];
$first_db_index = array_search('db:query', $log, true);
$first_postmeta_index = null;
foreach ($log as $i => $entry) {
    if (strpos($entry, 'postmeta:_pge_invited_guests:') === 0) { $first_postmeta_index = $i; break; }
}
w1_ok('H1 (Section 23) at least one scope-resolution DB query happened', $first_db_index !== false);
w1_ok('H2 (Section 23) the guest Post Meta store was touched', $first_postmeta_index !== null);
w1_ok(
    'H3 (Section 23) scope resolution (DB) happened strictly before guest PII (Post Meta) was ever touched',
    $first_db_index !== false && $first_postmeta_index !== null && $first_db_index < $first_postmeta_index
);
w1_ok('H4 the successful order-proof read actually returned the guest (sanity check the proof is not vacuous)', is_array($order_result) && count($order_result['items']) === 1);

// ══════════════════════════════════════════════════════════════
// Section I — Pagination semantics on the collaborator (DB-paginated) path
// ══════════════════════════════════════════════════════════════

w1_reset_db();
w1_event(500, 95);
$gI1 = w1_group(500, 'active', 'G1');
$pagMembership = w1_membership(500, 96, 'manager', 'active');
w1_access(500, $pagMembership, $gI1);
for ($n = 1; $n <= 5; $n++) {
    $phone = '97000009' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    w1_assignment(500, $phone, $gI1);
    w1_set_guest(500, $phone, "Guest $n", '', 'HHHH-000' . $n);
    w1_rsvp(500, $phone, 'pending', 0);
}
$page1 = $S::list_accessible_guests_for_actor(500, 96, 1, 2);
$page2 = $S::list_accessible_guests_for_actor(500, 96, 2, 2);
$page3 = $S::list_accessible_guests_for_actor(500, 96, 3, 2);
w1_ok('I1 page 1 (per_page=2) returns exactly 2 items', is_array($page1) && count($page1['items']) === 2);
w1_ok('I2 page 2 (per_page=2) returns exactly 2 items', is_array($page2) && count($page2['items']) === 2);
w1_ok('I3 page 3 (per_page=2) returns exactly the 1 remaining item (no drift)', is_array($page3) && count($page3['items']) === 1);
w1_ok('I4 total reflects the full authorized set (5), independent of page', is_array($page1) && $page1['total'] === 5 && $page2['total'] === 5 && $page3['total'] === 5);
w1_ok('I5 total_pages is computed correctly (ceil(5/2)=3)', is_array($page1) && $page1['total_pages'] === 3);

// ══════════════════════════════════════════════════════════════
// Section J — Write isolation proof (Section 25/Q)
// ══════════════════════════════════════════════════════════════

w1_ok('J1 no write() call was made to $wpdb during this entire test run so far', $wpdb->write_calls === 0);

function w1_strip_php_comments($source)
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $code .= is_array($token) ? $token[1] : $token;
    }
    return $code;
}

// Comment-stripped: this file's own class docblock intentionally NAMES
// every forbidden write method in prose (to document why none is called),
// which would otherwise trip its own regression guard the same way
// H1C-A2's TOCTOU docblock did for the $wpdb guard.
$application_service_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$application_service_code_only = w1_strip_php_comments($application_service_source);
$forbidden_writes = [
    'assign_guest_to_group', 'move_guest_to_group', 'unassign_guest_from_group',
    'create_group', 'archive_group', 'rename_group', 'set_default_group',
    'create_membership', 'change_membership_role', 'revoke_membership', 'reactivate_membership',
    'grant_group_access', 'revoke_group_access',
];
$found_forbidden = [];
foreach ($forbidden_writes as $needle) {
    if (strpos($application_service_code_only, $needle) !== false) $found_forbidden[] = $needle;
}
w1_ok('J2 (Section 25) the Application Service EXECUTABLE code (comments stripped) references zero write-API method names', $found_forbidden === [], implode(',', $found_forbidden));

// ══════════════════════════════════════════════════════════════
// Section K — Input validation / fail-closed edges
// ══════════════════════════════════════════════════════════════

// Reuses Section I's still-live fixture (event 500, user 96 — active
// manager, granted gI1) rather than event 200/user 61, which no longer
// exists after the intervening w1_reset_db() calls in Sections G/H/I.
w1_ok('K1 event_id <= 0 is rejected', w1_code($S::list_accessible_guests_for_actor(0, 96)) === PGE_Event_Access_Application_Service::REASON_INVALID_INPUT);
w1_ok('K2 actor_user_id <= 0 is rejected', w1_code($S::list_accessible_guests_for_actor(500, -1)) === PGE_Event_Access_Application_Service::REASON_INVALID_INPUT);
w1_ok('K3 non-existent event is rejected', w1_code($S::list_accessible_guests_for_actor(999999, 96)) === 'not_found');
w1_ok('K4 per_page beyond MAX_PER_PAGE is rejected', w1_code($S::list_accessible_guests_for_actor(500, 96, 1, 99999)) === PGE_Event_Access_Application_Service::REASON_INVALID_INPUT);
w1_ok('K5 page <= 0 is rejected', w1_code($S::list_accessible_guests_for_actor(500, 96, 0, 20)) === PGE_Event_Access_Application_Service::REASON_INVALID_INPUT);
w1_ok('K6 default per_page is applied when omitted', is_array($S::list_accessible_guests_for_actor(500, 96)) && $S::list_accessible_guests_for_actor(500, 96)['per_page'] === PGE_Event_Access_Repository::DEFAULT_PER_PAGE);

// ══════════════════════════════════════════════════════════════
// Section L — AJAX transport (Sections 16-21, 30)
// ══════════════════════════════════════════════════════════════

// Section L gets its own fresh, self-contained fixture (event 200 is
// rebuilt here) rather than depending on Section B-F's event 200 state,
// which no longer exists after the intervening w1_reset_db() calls in
// Sections G/H/I.
w1_reset_db();
w1_event(200, 60); // owner = user 60
$gL1 = w1_group(200, 'active', 'Family');
$gL2 = w1_group(200, 'active', 'Friends');
$lMgrMembership = w1_membership(200, 61, 'manager', 'active');
w1_access(200, $lMgrMembership, $gL1);
$lViewerMembership = w1_membership(200, 62, 'viewer', 'active');
w1_access(200, $lViewerMembership, $gL2);
w1_membership(200, 64, 'manager', 'revoked');
w1_membership(200, 66, 'manager', 'active');
w1_membership(200, 66, 'viewer', 'active'); // duplicate -> database_error internally
w1_set_guest(200, '970000011111', 'Guest G1', 'g1-note', 'CCCC-1111');
w1_set_guest(200, '970000022222', 'Guest G2', 'g2-note', 'DDDD-2222');
w1_assignment(200, '970000011111', $gL1);
w1_assignment(200, '970000022222', $gL2);
w1_rsvp(200, '970000011111', 'yes', 1);
w1_rsvp(200, '970000022222', 'no', 0);

w1_ok('L1 (Section 17) AJAX action is registered as wp_ajax_ only', isset($GLOBALS['w1_registered_hooks']['wp_ajax_pge_event_access_list_guests']));
w1_ok('L1b (Section 17) no wp_ajax_nopriv_ registration exists for this action', !isset($GLOBALS['w1_registered_hooks']['wp_ajax_nopriv_pge_event_access_list_guests']));

function w1_ajax_request($fields)
{
    $_POST = $fields;
    return w1_call_ajax_handler('pge_event_access_list_guests_handler');
}

// Unauthenticated
$GLOBALS['w1_logged_in'] = false;
$GLOBALS['w1_current_user_id'] = 0;
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 200]);
w1_ok('L2 unauthenticated request is denied', $resp['success'] === false);
w1_ok('L2b unauthenticated denial reason is not_logged_in', ($resp['data']['reason'] ?? null) === 'not_logged_in');
$GLOBALS['w1_logged_in'] = true;

// Invalid nonce
$GLOBALS['w1_current_user_id'] = 60; // owner of event 200
$resp = w1_ajax_request(['nonce' => 'garbage', 'event_id' => 200]);
w1_ok('L3 invalid nonce is rejected', $resp['success'] === false && ($resp['data']['reason'] ?? null) === 'invalid_nonce');

// Invalid event_id
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 0]);
w1_ok('L4 invalid event_id is rejected', $resp['success'] === false && ($resp['data']['reason'] ?? null) === 'invalid_event');

// Owner success via AJAX
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 200]);
w1_ok('L5 owner AJAX call succeeds', $resp['success'] === true);
w1_ok('L5b owner AJAX response carries the standard page envelope', isset($resp['data']['items'], $resp['data']['page'], $resp['data']['per_page'], $resp['data']['total'], $resp['data']['total_pages']));

// Manager success via AJAX, scoped
$GLOBALS['w1_current_user_id'] = 61; // manager, granted gB1 only
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 200]);
w1_ok('L6 (Section 20) manager AJAX call succeeds and is scoped to exactly 1 guest', $resp['success'] === true && count($resp['data']['items']) === 1);
w1_ok('L6b manager AJAX item includes note, excludes code', array_key_exists('note', $resp['data']['items'][0]['guest']) && !array_key_exists('code', $resp['data']['items'][0]['guest']));

// Viewer success via AJAX, scoped
$GLOBALS['w1_current_user_id'] = 62; // viewer, granted gB2 only
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 200]);
w1_ok('L7 (Section 20) viewer AJAX call succeeds and is scoped to exactly 1 guest', $resp['success'] === true && count($resp['data']['items']) === 1);
w1_ok('L7b viewer AJAX item excludes both note and code', !array_key_exists('note', $resp['data']['items'][0]['guest']) && !array_key_exists('code', $resp['data']['items'][0]['guest']));

// Stranger denied via AJAX
$GLOBALS['w1_current_user_id'] = 65; // never a member of event 200
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 200]);
w1_ok('L8 stranger AJAX call is denied', $resp['success'] === false && ($resp['data']['reason'] ?? null) === 'not_authorized');

// Revoked denied via AJAX
$GLOBALS['w1_current_user_id'] = 64; // revoked manager
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 200]);
w1_ok('L9 revoked-membership AJAX call is denied', $resp['success'] === false && ($resp['data']['reason'] ?? null) === 'not_authorized');

// Section 17: actor id must come exclusively from the WP session, never
// from the request — a client claiming to be the owner (user 60) while the
// real session is a stranger (user 65) must still be denied.
$GLOBALS['w1_current_user_id'] = 65;
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 200, 'actor_user_id' => 60]);
w1_ok('L10 (Section 17) a client-supplied actor_user_id is ignored — the real session user (stranger) is still denied', $resp['success'] === false && ($resp['data']['reason'] ?? null) === 'not_authorized');
$GLOBALS['w1_current_user_id'] = 60;
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 200, 'actor_user_id' => 65]);
w1_ok('L11 (Section 17) a client-supplied actor_user_id is ignored — the real session owner still succeeds despite the forged field', $resp['success'] === true);

// Section 19: error privacy — no internal WP_Error code, membership id,
// group id, guest phone, or SQL ever appears in a public AJAX error.
$GLOBALS['w1_current_user_id'] = 66; // duplicate/ambiguous membership -> database_error internally
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 200]);
w1_ok('L12 (Section 19) an internal database_error never reaches the client as-is', $resp['success'] === false && ($resp['data']['reason'] ?? null) === 'server_error');
$public_json = json_encode($resp);
w1_ok('L13 (Section 19) public error JSON contains no phone-shaped digit run', preg_match('/\d{6,}/', $public_json) !== 1);
w1_ok('L14 (Section 19) public error JSON never contains the word database', stripos($public_json, 'database') === false);
w1_ok('L15 (Section 19) public error JSON never contains a raw SQL keyword', stripos($public_json, 'SELECT') === false);

// Section 30: exact set of public reasons observed across this whole run —
// enumeration resistance at the transport boundary.
$GLOBALS['w1_current_user_id'] = 60;
$resp = w1_ajax_request(['nonce' => w1_nonce('pge_event_manage_nonce'), 'event_id' => 999999]);
w1_ok('L16 non-existent event via AJAX maps to invalid_event, never a distinct not_found leak', $resp['success'] === false && ($resp['data']['reason'] ?? null) === 'invalid_event');

// No write side effects across the entire AJAX section either.
w1_ok('L17 (Section 25/30) zero $wpdb write calls occurred across the entire AJAX section', $wpdb->write_calls === 0);

// ══════════════════════════════════════════════════════════════
// Final report
// ══════════════════════════════════════════════════════════════

echo "\nH1C-W1: {$passed}/" . ($passed + $failed) . " passed\n";
if ($failed > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
exit($failed === 0 ? 0 : 1);
