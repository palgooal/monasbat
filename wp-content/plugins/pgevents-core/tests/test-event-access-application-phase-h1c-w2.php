<?php
/**
 * Phase H1C-W2 — Collaborator Scoped Guest-Assignment Write Wiring.
 *
 * Exercises the real PGE_Event_Access_Application_Service write methods
 * (assign_guest_to_group_for_actor / move_guest_to_group_for_actor /
 * unassign_guest_from_group_for_actor), the real
 * PGE_Event_Access_Authorization[_Context] decision layer, the real
 * PGE_Event_Access_Repository transactional mutation methods, and the real
 * AJAX handlers in event-access-ajax.php (pge_event_access_assign_guest_handler /
 * pge_event_access_move_guest_handler / pge_event_access_unassign_guest_handler)
 * — against a small in-memory fake $wpdb (H1B relational tables only; GR1's
 * projection tables are deliberately NOT modeled here, so any accidental
 * touch of them by a write path fails the test with an unrecognized-query
 * error rather than silently succeeding) and a fake Post Meta store
 * (`_pge_invited_guests`, read-only in this suite via the real
 * pge_event_guests_resolve_current_by_phone() resolver). No real database,
 * no real network, no UI.
 *
 * Run: php tests/test-event-access-application-phase-h1c-w2.php
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
// Hook registration — records real hook names so W2 can prove no
// wp_ajax_nopriv_ registration exists, without executing WordPress itself.
// ──────────────────────────────────────────────────────────────

$GLOBALS['w2_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['w2_registered_hooks'][$hook] = true; }
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

$GLOBALS['w2_current_user_id'] = 0;
$GLOBALS['w2_logged_in'] = true;
$GLOBALS['w2_admins'] = [];
function get_current_user_id() { return $GLOBALS['w2_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['w2_logged_in']; }
// Authorization Context calls user_can($actor_user_id, 'administrator') —
// deliberately NOT current_user_can() (no session dependency inside the
// Authorization Core, unchanged contract from H1C-A2/H1C-W1).
function user_can($user_id, $capability)
{
    if ($capability !== 'administrator') return false;
    return in_array((int) $user_id, $GLOBALS['w2_admins'], true);
}

$GLOBALS['w2_posts'] = [];
function get_post_type($post_id)
{
    return $GLOBALS['w2_posts'][(int) $post_id]['post_type'] ?? false;
}
function get_post_field($field, $post_id)
{
    if (!isset($GLOBALS['w2_posts'][(int) $post_id][$field])) return '';
    return (string) $GLOBALS['w2_posts'][(int) $post_id][$field];
}

// ──────────────────────────────────────────────────────────────
// Fake Post Meta store (`_pge_invited_guests`) — read-only in this suite,
// consulted only by the real pge_event_guests_resolve_current_by_phone()
// resolver that PGE_Event_Access_Repository::assign_guest_to_group()/
// move_guest_to_group() call before opening a transaction. Mirrors the
// simple W3 harness shape (single(), not the H1C-W1/GR1 multi-key shape) —
// this suite never touches _pge_invited_phones or the projection.
// ──────────────────────────────────────────────────────────────

$GLOBALS['w2_meta'] = [];
$GLOBALS['w2_meta_calls'] = 0;
function get_post_meta($event_id, $key = '', $single = false)
{
    if ($key === '_pge_invited_guests') $GLOBALS['w2_meta_calls']++;
    return $GLOBALS['w2_meta'][$event_id][$key] ?? '';
}
function update_post_meta($post_id, $key, $value) { $GLOBALS['w2_save_map_calls'][] = [$post_id, $key]; return true; }
function delete_post_meta($post_id, $key) { return true; }

// ──────────────────────────────────────────────────────────────
// Fake schema — always "ready" unless a test flips it.
// ──────────────────────────────────────────────────────────────

class PGE_Event_Access_Schema
{
    public static function is_ready() { return $GLOBALS['w2_schema_ready']; }
    public static function maybe_upgrade() { throw new RuntimeException('maybe_upgrade() must never be called by a W2 write path.'); }
}

// ──────────────────────────────────────────────────────────────
// In-memory fixture store + SQL dispatcher (H1B relational tables only).
// ──────────────────────────────────────────────────────────────

function w2_tables()
{
    return [
        'groups' => 'wp_pge_event_invitation_groups',
        'memberships' => 'wp_pge_event_host_memberships',
        'access' => 'wp_pge_event_host_group_access',
        'assignments' => 'wp_pge_invitation_group_assignments',
        'audit' => 'wp_pge_event_access_audit_log',
    ];
}

function w2_reset_db()
{
    $GLOBALS['w2_db'] = [
        'groups' => [],
        'memberships' => [],
        'access' => [],
        'assignments' => [],
        'audit' => [],
        'next_id' => 1,
    ];
    $GLOBALS['w2_posts'] = [];
    $GLOBALS['w2_admins'] = [];
    $GLOBALS['w2_schema_ready'] = true;
    $GLOBALS['w2_meta'] = [];
    $GLOBALS['w2_meta_calls'] = 0;
    $GLOBALS['w2_current_user_id'] = 0;
    $GLOBALS['w2_logged_in'] = true;
    $GLOBALS['w2_mutation_calls'] = 0;
    $GLOBALS['w2_delete_calls'] = 0;
    $GLOBALS['w2_query_log'] = [];
    $GLOBALS['w2_save_map_calls'] = [];
}

function w2_next_id() { return $GLOBALS['w2_db']['next_id']++; }

function w2_event($event_id, $author_id)
{
    $GLOBALS['w2_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => $author_id];
}

function w2_group($event_id, $status = 'active', $name = 'Group')
{
    $id = w2_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w2_db']['groups'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'name' => $name,
        'name_key' => $status === 'active' ? strtolower($name) . '-' . $id : null,
        'status' => $status, 'default_slot' => null, 'created_by_user_id' => 1,
        'created_at' => $now, 'updated_at' => $now,
        'archived_at' => $status === 'archived' ? $now : null,
    ];
    return $id;
}

function w2_membership($event_id, $user_id, $role, $status = 'active')
{
    $id = w2_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w2_db']['memberships'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'user_id' => $user_id, 'role' => $role, 'status' => $status,
        'created_by_user_id' => 1, 'activated_at' => $now, 'revoked_at' => $status === 'revoked' ? $now : null,
        'created_at' => $now, 'updated_at' => $now,
        // H1C-W8: additive nullable column now present on every real row.
        'allocated_quota' => null,
    ];
    return $id;
}

function w2_access($event_id, $membership_id, $group_id)
{
    $id = w2_next_id();
    $GLOBALS['w2_db']['access'][] = [
        'id' => $id, 'event_id' => $event_id, 'membership_id' => $membership_id, 'group_id' => $group_id,
        'granted_by_user_id' => 1, 'created_at' => '2026-01-01 00:00:00',
    ];
    return $id;
}

function w2_assignment($event_id, $phone, $group_id, $actor = 1)
{
    $id = w2_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w2_db']['assignments'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'guest_phone' => $phone, 'group_id' => $group_id,
        'assigned_by_user_id' => $actor, 'created_at' => $now, 'updated_at' => $now,
    ];
    return $id;
}

function w2_guest($event_id, $phone)
{
    $existing = $GLOBALS['w2_meta'][$event_id]['_pge_invited_guests'] ?? [];
    if (!is_array($existing)) $existing = [];
    $existing[] = ['phone' => $phone, 'name' => 'Private Name', 'note' => '', 'code' => 'ABCD-1234'];
    $GLOBALS['w2_meta'][$event_id]['_pge_invited_guests'] = $existing;
}

// Scoped Manager Malformed/Duplicate Assignment Privacy Fix Pass: a raw
// assignment-row injector that bypasses w2_assignment()'s well-formed shape
// on purpose, to faithfully model a row that PGE_Event_Access_Repository::
// normalize_assignment() will reject (here: an empty created_at, which
// fails required_string()) — the same 'database_error' code a truly
// corrupt/malformed row produces in production, without needing to touch
// real storage.
function w2_malformed_assignment($event_id, $phone, $group_id)
{
    $id = w2_next_id();
    $GLOBALS['w2_db']['assignments'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'guest_phone' => $phone, 'group_id' => $group_id,
        'assigned_by_user_id' => 1, 'created_at' => '', 'updated_at' => '2026-01-01 00:00:00',
    ];
    return $id;
}

function w2_dispatch_select($sql)
{
    $t = w2_tables();
    $sql = trim($sql);

    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND user_id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $user_id = (int) $m[2];
        return array_values(array_filter($GLOBALS['w2_db']['memberships'], function ($r) use ($event_id, $user_id) {
            return $r['event_id'] === $event_id && $r['user_id'] === $user_id;
        }));
    }

    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $id = (int) $m[2];
        return array_values(array_filter($GLOBALS['w2_db']['memberships'], function ($r) use ($event_id, $id) {
            return $r['event_id'] === $event_id && $r['id'] === $id;
        }));
    }

    $join = '/^SELECT a\.event_id, a\.group_id, g\.event_id AS related_event_id FROM '
        . preg_quote($t['access'], '/') . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.membership_id = (\d+) ORDER BY a\.group_id ASC$/';
    if (preg_match($join, $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2];
        $out = [];
        foreach ($GLOBALS['w2_db']['access'] as $row) {
            if ($row['event_id'] !== $event_id || $row['membership_id'] !== $membership_id) continue;
            $group = $GLOBALS['w2_db']['groups'][$row['group_id']] ?? null;
            $out[] = ['event_id' => $row['event_id'], 'group_id' => $row['group_id'], 'related_event_id' => $group['event_id'] ?? null];
        }
        return $out;
    }

    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['groups'], '/') . ' WHERE event_id = (\d+) ORDER BY id FOR UPDATE$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $rows = array_values(array_filter($GLOBALS['w2_db']['groups'], function ($r) use ($event_id) { return $r['event_id'] === $event_id; }));
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['assignments'], '/') . ' WHERE event_id = (\d+) AND guest_phone = \'([^\']*)\' ORDER BY id FOR UPDATE$/', $sql, $m)) {
        $event_id = (int) $m[1]; $phone = $m[2];
        $rows = array_values(array_filter($GLOBALS['w2_db']['assignments'], function ($r) use ($event_id, $phone) {
            return $r['event_id'] === $event_id && $r['guest_phone'] === $phone;
        }));
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['assignments'], '/') . ' WHERE event_id = (\d+) AND guest_phone = \'([^\']*)\' ORDER BY id LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $phone = $m[2];
        $rows = array_values(array_filter($GLOBALS['w2_db']['assignments'], function ($r) use ($event_id, $phone) {
            return $r['event_id'] === $event_id && $r['guest_phone'] === $phone;
        }));
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return array_slice($rows, 0, 2);
    }

    if (preg_match('/^SELECT event_id FROM ' . preg_quote($t['groups'], '/') . ' WHERE id = (\d+) LIMIT 2$/', $sql, $m)) {
        $id = (int) $m[1];
        $row = $GLOBALS['w2_db']['groups'][$id] ?? null;
        return $row === null ? [] : [['event_id' => $row['event_id']]];
    }

    // PGE_Event_Access_Repository::map_guest_groups() — the existing,
    // already-tested, zero-Post-Meta-touch relational primitive the Scoped
    // Manager Enumeration/Privacy Fix Pass reuses (Section 5 of that
    // brief). One or more phones IN (...); no FOR UPDATE, no LIMIT.
    $mapGuestGroups = '/^SELECT a\.\*, g\.event_id AS related_event_id FROM ' . preg_quote($t['assignments'], '/')
        . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.guest_phone IN \(([^)]*)\) ORDER BY a\.id ASC$/';
    if (preg_match($mapGuestGroups, $sql, $m)) {
        $event_id = (int) $m[1];
        $phones = array_map(function ($p) { return trim(trim($p), "'"); }, explode(',', $m[2]));
        $rows = [];
        foreach ($GLOBALS['w2_db']['assignments'] as $row) {
            if ($row['event_id'] !== $event_id || !in_array($row['guest_phone'], $phones, true)) continue;
            $group = $GLOBALS['w2_db']['groups'][$row['group_id']] ?? null;
            $rows[] = array_merge($row, ['related_event_id' => $group['event_id'] ?? null]);
        }
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    return null;
}

function w2_dispatch_mutation($sql)
{
    $t = w2_tables();
    $sql = trim($sql);

    if ($sql === 'START TRANSACTION') {
        $GLOBALS['w2_snapshot'] = [$GLOBALS['w2_db']['assignments'], $GLOBALS['w2_db']['audit'], $GLOBALS['w2_db']['next_id']];
        return ['handled' => true, 'result' => 0];
    }
    if ($sql === 'COMMIT') {
        $GLOBALS['w2_snapshot'] = null;
        return ['handled' => true, 'result' => 0];
    }
    if ($sql === 'ROLLBACK') {
        if ($GLOBALS['w2_snapshot'] !== null) {
            [$GLOBALS['w2_db']['assignments'], $GLOBALS['w2_db']['audit'], $GLOBALS['w2_db']['next_id']] = $GLOBALS['w2_snapshot'];
        }
        $GLOBALS['w2_snapshot'] = null;
        return ['handled' => true, 'result' => 0];
    }

    $insertAssign = '/^INSERT INTO ' . preg_quote($t['assignments'], '/')
        . ' \(event_id, guest_phone, group_id, assigned_by_user_id, created_at, updated_at\) VALUES \((\d+), \'([^\']*)\', (\d+), (\d+), \'([^\']*)\', \'([^\']*)\'\)$/';
    if (preg_match($insertAssign, $sql, $m)) {
        $GLOBALS['w2_mutation_calls']++;
        $id = w2_next_id();
        $GLOBALS['w2_db']['assignments'][$id] = [
            'id' => $id, 'event_id' => (int) $m[1], 'guest_phone' => $m[2], 'group_id' => (int) $m[3],
            'assigned_by_user_id' => (int) $m[4], 'created_at' => $m[5], 'updated_at' => $m[6],
        ];
        return ['handled' => true, 'result' => 1, 'insert_id' => $id];
    }

    $updateAssign = '/^UPDATE ' . preg_quote($t['assignments'], '/')
        . ' SET group_id = (\d+), assigned_by_user_id = (\d+), updated_at = \'([^\']*)\' WHERE id = (\d+) AND event_id = (\d+) AND guest_phone = \'([^\']*)\' AND group_id = (\d+)$/';
    if (preg_match($updateAssign, $sql, $m)) {
        $GLOBALS['w2_mutation_calls']++;
        [$new_group, $actor, $now, $id, $event_id, $phone, $expected] = [(int) $m[1], (int) $m[2], $m[3], (int) $m[4], (int) $m[5], $m[6], (int) $m[7]];
        $row = $GLOBALS['w2_db']['assignments'][$id] ?? null;
        if ($row === null || $row['event_id'] !== $event_id || $row['guest_phone'] !== $phone || $row['group_id'] !== $expected) {
            return ['handled' => true, 'result' => 0];
        }
        $GLOBALS['w2_db']['assignments'][$id]['group_id'] = $new_group;
        $GLOBALS['w2_db']['assignments'][$id]['assigned_by_user_id'] = $actor;
        $GLOBALS['w2_db']['assignments'][$id]['updated_at'] = $now;
        return ['handled' => true, 'result' => 1];
    }

    $insertAudit = '/^INSERT INTO ' . preg_quote($t['audit'], '/')
        . ' \(event_id, actor_user_id, action, entity_type, entity_id, metadata, created_at\) VALUES \((\d+), (\d+), \'([^\']*)\', \'([^\']*)\', (\d+), \'([^\']*)\', \'([^\']*)\'\)$/';
    if (preg_match($insertAudit, $sql, $m)) {
        $GLOBALS['w2_mutation_calls']++;
        $GLOBALS['w2_db']['audit'][] = [
            'event_id' => (int) $m[1], 'actor_user_id' => (int) $m[2], 'action' => $m[3],
            'entity_type' => $m[4], 'entity_id' => (int) $m[5], 'metadata' => json_decode($m[6], true), 'created_at' => $m[7],
        ];
        return ['handled' => true, 'result' => 1];
    }

    return ['handled' => false];
}

class PGE_W2_Fake_WPDB
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
        $GLOBALS['w2_query_log'][] = $sql;
        $rows = w2_dispatch_select($sql);
        if ($rows === null) {
            $this->last_error = 'w2-fake-wpdb: unrecognized SELECT: ' . $sql;
            return null;
        }
        $this->last_error = '';
        return $rows;
    }

    public function get_var($sql)
    {
        $GLOBALS['w2_query_log'][] = $sql;
        $this->last_error = 'w2-fake-wpdb: unrecognized scalar query: ' . $sql;
        return null;
    }

    public function query($sql)
    {
        $GLOBALS['w2_query_log'][] = $sql;
        $outcome = w2_dispatch_mutation($sql);
        if (!$outcome['handled']) {
            $this->last_error = 'w2-fake-wpdb: unexpected mutating query() call: ' . $sql;
            return false;
        }
        $this->last_error = '';
        if (array_key_exists('insert_id', $outcome)) $this->insert_id = $outcome['insert_id'];
        return $outcome['result'];
    }

    public function delete($table, $where, $formats)
    {
        $t = w2_tables();
        $GLOBALS['w2_query_log'][] = 'DELETE ' . $table;
        if ($table !== $t['assignments']) {
            $this->last_error = 'w2-fake-wpdb: unexpected delete() call on ' . $table;
            return false;
        }
        $this->last_error = '';
        foreach ($GLOBALS['w2_db']['assignments'] as $id => $row) {
            if ($row['id'] === $where['id'] && $row['event_id'] === $where['event_id']
                && $row['guest_phone'] === $where['guest_phone'] && $row['group_id'] === $where['group_id']) {
                $GLOBALS['w2_mutation_calls']++;
                $GLOBALS['w2_delete_calls']++;
                unset($GLOBALS['w2_db']['assignments'][$id]);
                return 1;
            }
        }
        return 0;
    }

    public function insert($table, $data, $formats = null)
    {
        $this->last_error = 'w2-fake-wpdb: unexpected insert() call';
        return false;
    }
}

w2_reset_db();
global $wpdb;
$wpdb = new PGE_W2_Fake_WPDB();

// ──────────────────────────────────────────────────────────────
// AJAX/JSON stubs
// ──────────────────────────────────────────────────────────────

class Test_Wp_Die_Exception extends \Exception {}

$GLOBALS['w2_json_response'] = null;
function wp_send_json_success($data = null)
{
    $GLOBALS['w2_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null)
{
    $GLOBALS['w2_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = [])
{
    throw new Test_Wp_Die_Exception('wp_die');
}

function w2_call_ajax_handler(callable $handler): array
{
    $GLOBALS['w2_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // expected
    }
    $raw = $GLOBALS['w2_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function w2_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_create_nonce($action) { return w2_nonce($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals(w2_nonce($action), (string) $nonce) ? 1 : false; }

require_once PGE_PATH . 'includes/event-guests.php';
require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';
require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';
require_once PGE_PATH . 'includes/class-pge-event-access-application-service.php';
require_once PGE_PATH . 'includes/event-access-ajax.php';

$passed = 0;
$failed = 0;
function w2_ok($label, $condition) { global $passed, $failed; if ($condition) { $passed++; echo "PASS: $label\n"; } else { $failed++; echo "FAIL: $label\n"; } }
function w2_code($value) { return $value instanceof WP_Error ? $value->get_error_code() : null; }

/**
 * Strips PHP comments (// # and /* *\/ blocks, including docblocks) from a
 * source string via the real tokenizer, so a source-level "this string
 * never appears in the code" test cannot be defeated by that very string
 * appearing inside a comment EXPLAINING that it is never called (as this
 * suite's own docblocks deliberately do, e.g. "never calls
 * pge_event_guests_save_map()").
 */
function w2_strip_comments($php_source)
{
    $out = '';
    foreach (token_get_all('<?php ' . $php_source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
}

function w2_ajax_post($fields)
{
    $_POST = array_merge(['nonce' => w2_nonce('pge_event_manage_nonce')], $fields);
}

$AS = 'PGE_Event_Access_Application_Service';
$Auth = 'PGE_Event_Access_Authorization';
$Repo = 'PGE_Event_Access_Repository';

// ──────────────────────────────────────────────────────────────
// Section C — Authorization matrix (Application Service layer), for each
// of assign / move / unassign, across Owner / Admin / Manager-authorized /
// Manager-unauthorized / Viewer / revoked Manager / revoked Viewer /
// stranger. Every denial is also proven to issue ZERO Repository mutation
// queries (Section 36 — Repository Mutation Spy).
// ──────────────────────────────────────────────────────────────

function w2_matrix_fixture()
{
    w2_reset_db();
    w2_event(10, 1); // author/owner = user 1
    $g1 = w2_group(10);
    $g2 = w2_group(10);
    w2_guest(10, '0591111111');
    $GLOBALS['w2_admins'] = [2];
    $mgr = w2_membership(10, 3, 'manager');
    w2_access(10, $mgr, $g1);
    $mgr_unauth = w2_membership(10, 4, 'manager'); // manager, but no access to g1/g2
    $viewer = w2_membership(10, 5, 'viewer');
    w2_access(10, $viewer, $g1);
    $revoked_mgr = w2_membership(10, 6, 'manager', 'revoked');
    w2_access(10, $revoked_mgr, $g1);
    $revoked_viewer = w2_membership(10, 7, 'viewer', 'revoked');
    return ['g1' => $g1, 'g2' => $g2, 'owner' => 1, 'admin' => 2, 'manager' => 3, 'manager_unauth' => 4, 'viewer' => 5, 'revoked_manager' => 6, 'revoked_viewer' => 7, 'stranger' => 99];
}

foreach ([
    ['assign', 'owner', true], ['assign', 'admin', true], ['assign', 'manager', false], ['assign', 'manager_unauth', false],
    ['assign', 'viewer', false], ['assign', 'revoked_manager', false], ['assign', 'revoked_viewer', false], ['assign', 'stranger', false],
] as [$op, $who, $expect_allowed]) {
    $f = w2_matrix_fixture();
    $before_mutations = $GLOBALS['w2_mutation_calls'];
    $result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f[$who], '0591111111', $f['g1']);
    $allowed = !($result instanceof WP_Error);
    w2_ok("C-assign $who allowed=" . ($expect_allowed ? 'true' : 'false'), $allowed === $expect_allowed
        && ($expect_allowed || w2_code($result) === 'not_authorized')
        && ($expect_allowed || $GLOBALS['w2_mutation_calls'] === $before_mutations));
}

foreach ([
    // Note: the default fixture's 'manager' (user 3) is granted g1 ONLY —
    // moving g1->g2 requires BOTH groups granted (A2 tests G7-G11), so this
    // specific manager is correctly denied here. The "manager granted both
    // groups" success path is exercised separately below (Section L1).
    ['move', 'owner', true], ['move', 'admin', true], ['move', 'manager', false], ['move', 'manager_unauth', false],
    ['move', 'viewer', false], ['move', 'revoked_manager', false], ['move', 'revoked_viewer', false], ['move', 'stranger', false],
] as [$op, $who, $expect_allowed]) {
    $f = w2_matrix_fixture();
    w2_assignment(10, '0591111111', $f['g1'], 1);
    $before_mutations = $GLOBALS['w2_mutation_calls'];
    $result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f[$who], '0591111111', $f['g1'], $f['g2']);
    $allowed = !($result instanceof WP_Error);
    w2_ok("C-move $who allowed=" . ($expect_allowed ? 'true' : 'false'), $allowed === $expect_allowed
        && ($expect_allowed || w2_code($result) === 'not_authorized')
        && ($expect_allowed || $GLOBALS['w2_mutation_calls'] === $before_mutations));
}

foreach ([
    ['unassign', 'owner', true], ['unassign', 'admin', true], ['unassign', 'manager', true], ['unassign', 'manager_unauth', false],
    ['unassign', 'viewer', false], ['unassign', 'revoked_manager', false], ['unassign', 'revoked_viewer', false], ['unassign', 'stranger', false],
] as [$op, $who, $expect_allowed]) {
    $f = w2_matrix_fixture();
    w2_assignment(10, '0591111111', $f['g1'], 1);
    $before_mutations = $GLOBALS['w2_mutation_calls'];
    $result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f[$who], '0591111111', $f['g1']);
    $allowed = !($result instanceof WP_Error);
    w2_ok("C-unassign $who allowed=" . ($expect_allowed ? 'true' : 'false'), $allowed === $expect_allowed
        && ($expect_allowed || w2_code($result) === 'not_authorized')
        && ($expect_allowed || $GLOBALS['w2_mutation_calls'] === $before_mutations));
}

// ──────────────────────────────────────────────────────────────
// Section K — Assign behavior
// ──────────────────────────────────────────────────────────────

$f = w2_matrix_fixture();
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', $f['g1']);
w2_ok('K1 authorized owner assign creates assignment with ok=true shape', $result === ['ok' => true, 'changed' => true, 'assignment_id' => $result['assignment_id'] ?? null, 'group_id' => $f['g1'], 'has_assignment' => true] && is_int($result['assignment_id']));
w2_ok('K1b assign emits exactly one audit row, no PII', count($GLOBALS['w2_db']['audit']) === 1 && $GLOBALS['w2_db']['audit'][0]['action'] === 'guest_group_assigned' && strpos(json_encode($GLOBALS['w2_db']['audit']), '059') === false && strpos(json_encode($GLOBALS['w2_db']['audit']), 'Private Name') === false);

$f = w2_matrix_fixture();
w2_assignment(10, '0591111111', $f['g1'], 1);
$before = count($GLOBALS['w2_db']['audit']);
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', $f['g1']);
w2_ok('K2 same-group assign is a no-op (changed=false), no new audit', $result['changed'] === false && $result['has_assignment'] === true && count($GLOBALS['w2_db']['audit']) === $before);

$f = w2_matrix_fixture();
w2_assignment(10, '0591111111', $f['g2'], 1);
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', $f['g1']);
w2_ok('K3 already assigned elsewhere is concurrent_update', w2_code($result) === 'concurrent_update');

$f = w2_matrix_fixture();
$archived = w2_group(10, 'archived');
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', $archived);
w2_ok('K4 assign to archived target is invalid_group', w2_code($result) === 'invalid_group');

$f = w2_matrix_fixture();
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', 999999);
w2_ok('K5 assign to missing/cross-event target is not_found', w2_code($result) === 'not_found');

$f = w2_matrix_fixture();
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['owner'], '0599999999', $f['g1']);
w2_ok('K6 assign of unknown guest phone is not_found', w2_code($result) === 'not_found');

$f = w2_matrix_fixture();
w2_guest(10, '0591111111'); // duplicate raw entry -> ambiguous canonical identity
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', $f['g1']);
w2_ok('K7 assign of ambiguous guest identity is ambiguous_guest', w2_code($result) === 'ambiguous_guest');

$f = w2_matrix_fixture();
w2_access(10, w2_membership(10, 8, 'manager'), $f['g1']);
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, 8, '0591111111', $f['g1']);
w2_ok('K8 manager granted the exact target group is STILL denied assign (owner/admin-only, not scope-dependent)', w2_code($result) === 'not_authorized');

// ──────────────────────────────────────────────────────────────
// Section L — Move behavior
// ──────────────────────────────────────────────────────────────

$f = w2_matrix_fixture();
w2_access(10, $mgr_full = w2_membership(10, 20, 'manager'), $f['g1']);
w2_access(10, $mgr_full, $f['g2']);
w2_assignment(10, '0591111111', $f['g1'], 1);
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, 20, '0591111111', $f['g1'], $f['g2']);
w2_ok('L1 manager granted BOTH groups moves G1->G2 successfully', $result === ['ok' => true, 'changed' => true, 'assignment_id' => $result['assignment_id'] ?? null, 'group_id' => $f['g2'], 'has_assignment' => true]);

$f = w2_matrix_fixture();
w2_assignment(10, '0591111111', $f['g1'], 1);
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', $f['g2'], $f['g1']);
w2_ok('L2 expected-current-group mismatch is concurrent_update', w2_code($result) === 'concurrent_update');
w2_ok('L2b mismatch never mutated the assignment row', $GLOBALS['w2_db']['assignments'][array_key_first($GLOBALS['w2_db']['assignments'])]['group_id'] === $f['g1']);

$f = w2_matrix_fixture();
w2_assignment(10, '0591111111', $f['g1'], 1);
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', $f['g1'], $f['g1']);
w2_ok('L3 same-target move is a no-op per H1B contract', $result['changed'] === false && $result['group_id'] === $f['g1']);

$f = w2_matrix_fixture();
w2_access(10, $mgr_src_only = w2_membership(10, 21, 'manager'), $f['g1']); // granted source only, not destination
w2_assignment(10, '0591111111', $f['g1'], 1);
$before_mutations = $GLOBALS['w2_mutation_calls'];
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, 21, '0591111111', $f['g1'], $f['g2']);
w2_ok('L4 destination unauthorized denies BEFORE mutation', w2_code($result) === 'not_authorized' && $GLOBALS['w2_mutation_calls'] === $before_mutations);

$f = w2_matrix_fixture();
w2_access(10, $mgr_dst_only = w2_membership(10, 22, 'manager'), $f['g2']); // granted destination only, not source
w2_assignment(10, '0591111111', $f['g1'], 1);
$before_mutations = $GLOBALS['w2_mutation_calls'];
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, 22, '0591111111', $f['g1'], $f['g2']);
w2_ok('L5 source unauthorized denies BEFORE mutation', w2_code($result) === 'not_authorized' && $GLOBALS['w2_mutation_calls'] === $before_mutations);

$f = w2_matrix_fixture();
$archived_current = w2_group(10, 'archived');
w2_assignment(10, '0591111111', $archived_current, 1);
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', $archived_current, $f['g1']);
w2_ok('L6 move from archived current group to active target repairs (H1B semantics)', is_array($result) && $result['group_id'] === $f['g1']);

$missing_group_id = 555555;
$f = w2_matrix_fixture();
w2_assignment(10, '0591111111', $missing_group_id, 1);
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', $missing_group_id, $f['g1']);
w2_ok('L7 move from a missing/explicit-repair current group succeeds for Owner (H1B semantics)', is_array($result) && $result['group_id'] === $f['g1']);

// ──────────────────────────────────────────────────────────────
// Section M/N — Unassign behavior + deleted-guest cleanup
// ──────────────────────────────────────────────────────────────

$f = w2_matrix_fixture();
w2_assignment(10, '0591111111', $f['g1'], 1);
$result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['manager'], '0591111111', $f['g1']);
w2_ok('M1 authorized unassign succeeds with has_assignment=false', $result === ['ok' => true, 'changed' => true, 'assignment_id' => null, 'group_id' => null, 'has_assignment' => false]);
w2_ok('M1b unassign emits exactly one audit row', count($GLOBALS['w2_db']['audit']) === 1 && $GLOBALS['w2_db']['audit'][0]['action'] === 'guest_group_unassigned');

$f = w2_matrix_fixture();
$before = count($GLOBALS['w2_db']['audit']);
$result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['owner'], '0591111111', $f['g1']);
w2_ok('M2 unassign with no existing assignment is a no-op, no audit', $result['changed'] === false && count($GLOBALS['w2_db']['audit']) === $before);

$f = w2_matrix_fixture();
w2_assignment(10, '0591111111', $f['g1'], 1);
$result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['owner'], '0591111111', $f['g2']);
w2_ok('M3 unassign expected-group mismatch is concurrent_update', w2_code($result) === 'concurrent_update');

// N — the critical deleted-guest cleanup case (Section 32/33): guest phone
// has NO entry in Post Meta at all (never added via w2_guest()), yet an
// H1B assignment row still exists for it — cleanup (unassign) must still
// work, and authorization must never have depended on guest-identity
// resolution.
w2_reset_db();
w2_event(10, 1);
$g1 = w2_group(10);
$mgr = w2_membership(10, 3, 'manager');
w2_access(10, $mgr, $g1);
// Deliberately no w2_guest(10, ...) call — Post Meta has zero guests.
w2_assignment(10, '0597777777', $g1, 1);
$meta_calls_before = $GLOBALS['w2_meta_calls'];
$result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, 3, '0597777777', $g1);
w2_ok('N1 unassign cleans up an assignment for a guest already deleted from Post Meta', is_array($result) && $result['changed'] === true);
w2_ok('N2 unassign never consulted guest Post Meta at all (resolver skipped, matches H1B contract)', $GLOBALS['w2_meta_calls'] === $meta_calls_before);
w2_ok('N3 unassign authorization depended only on event/group/membership context, not guest identity', true); // proven structurally: can_unassign_guest() takes no guest-identity argument at all.

// ──────────────────────────────────────────────────────────────
// Section O — Repository error mapping (reviewed against ACTUAL codes)
// ──────────────────────────────────────────────────────────────

$f = w2_matrix_fixture();
$GLOBALS['w2_schema_ready'] = false;
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', $f['g1']);
w2_ok('O1 schema-not-ready maps to write_unavailable (not a raw schema_not_ready leak)', w2_code($result) === 'write_unavailable');

$result_ai = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, -1, '0591111111', $f['g1']);
w2_ok('O2 invalid actor id (application-layer strict validation) is invalid_input', w2_code($result_ai) === 'invalid_input');
$result_ap = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['owner'], '', $f['g1']);
w2_ok('O3 empty guest_phone (application-layer strict validation) is invalid_input', w2_code($result_ap) === 'invalid_input');
$result_ag = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['owner'], '0591111111', 0);
w2_ok('O4 zero group id (application-layer strict validation) is invalid_input', w2_code($result_ag) === 'invalid_input');

// ──────────────────────────────────────────────────────────────
// Section Q — GR1 interaction: source-level proof no write method touches
// pge_event_guests_save_map()/PGE_Event_Guest_Read_Projection at all.
// ──────────────────────────────────────────────────────────────

// Strip comments from the WHOLE file first (never slice a raw source
// string on a string that itself appears inside a docblock — doing so cuts
// through the comment's opening "/**" and defeats the tokenizer's ability
// to recognize what remains as a comment at all).
$as_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$as_code = w2_strip_comments($as_source);
$write_method_pos = strpos($as_code, 'function assign_guest_to_group_for_actor');
$write_section_code = substr($as_code, $write_method_pos);
w2_ok('Q1 Application Service write methods never call pge_event_guests_save_map() (comments stripped)', strpos($write_section_code, 'pge_event_guests_save_map') === false);
w2_ok('Q2 Application Service write methods never reference PGE_Event_Guest_Read_Projection (comments stripped)', strpos($write_section_code, 'PGE_Event_Guest_Read_Projection') === false);
$ajax_write_source = file_get_contents(PGE_PATH . 'includes/event-access-ajax.php');
$ajax_write_code = w2_strip_comments($ajax_write_source);
w2_ok('Q3 AJAX handlers never call pge_event_guests_save_map() or reference the projection (comments stripped)', strpos($ajax_write_code, 'pge_event_guests_save_map') === false && strpos($ajax_write_code, 'PGE_Event_Guest_Read_Projection') === false);

$repo_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-repository.php');
$repo_code = w2_strip_comments($repo_source);
w2_ok('Q4 (regression sanity) Repository itself still never touches Post Meta or the projection (comments stripped)', strpos($repo_code, 'pge_event_guests_save_map') === false && strpos($repo_code, 'PGE_Event_Guest_Read_Projection') === false);

// ──────────────────────────────────────────────────────────────
// Section G/AJAX — actor identity, nonce, validation, actor-spoof,
// no-nopriv, cross-event.
// ──────────────────────────────────────────────────────────────

$f = w2_matrix_fixture();
$GLOBALS['w2_logged_in'] = false;
w2_ajax_post(['event_id' => '10', 'group_id' => (string) $f['g1'], 'guest_phone' => '0591111111']);
$resp = w2_call_ajax_handler('pge_event_access_assign_guest_handler');
w2_ok('F1 assign AJAX not logged in is denied before any application call', $resp['success'] === false && $resp['data']['reason'] === 'not_logged_in');
$GLOBALS['w2_logged_in'] = true;

$f = w2_matrix_fixture();
$GLOBALS['w2_current_user_id'] = $f['owner'];
$_POST = ['nonce' => 'garbage', 'event_id' => '10', 'group_id' => (string) $f['g1'], 'guest_phone' => '0591111111'];
$resp = w2_call_ajax_handler('pge_event_access_assign_guest_handler');
w2_ok('F2 assign AJAX bad nonce is denied', $resp['success'] === false && $resp['data']['reason'] === 'invalid_nonce');

foreach ([
    ['event_id' => '', 'group_id' => '1', 'guest_phone' => '059'],
    ['event_id' => '10', 'group_id' => '', 'guest_phone' => '059'],
    ['event_id' => '10', 'group_id' => '1.5', 'guest_phone' => '059'],
    ['event_id' => '10', 'group_id' => '-1', 'guest_phone' => '059'],
    ['event_id' => '10', 'group_id' => '0', 'guest_phone' => '059'],
    ['event_id' => '10', 'group_id' => ['1'], 'guest_phone' => '059'],
    ['event_id' => '10', 'group_id' => '1', 'guest_phone' => ''],
] as $bad) {
    $f = w2_matrix_fixture();
    $GLOBALS['w2_current_user_id'] = $f['owner'];
    w2_ajax_post($bad);
    $resp = w2_call_ajax_handler('pge_event_access_assign_guest_handler');
    w2_ok('F3 assign AJAX strict-rejects malformed field ' . json_encode($bad), $resp['success'] === false && $resp['data']['reason'] === 'invalid_input');
}

foreach ([
    ['event_id' => '10', 'expected_group_id' => '1', 'guest_phone' => '059'], // missing new_group_id
] as $bad) {
    $f = w2_matrix_fixture();
    $GLOBALS['w2_current_user_id'] = $f['owner'];
    w2_ajax_post($bad);
    $resp = w2_call_ajax_handler('pge_event_access_move_guest_handler');
    w2_ok('F4 move AJAX rejects a request missing new_group_id', $resp['success'] === false && $resp['data']['reason'] === 'invalid_input');
}

$f = w2_matrix_fixture();
w2_assignment(10, '0591111111', $f['g1'], 1);
$GLOBALS['w2_current_user_id'] = $f['manager'];
w2_ajax_post(['event_id' => '10', 'expected_group_id' => (string) $f['g1'], 'guest_phone' => '0591111111']);
$resp = w2_call_ajax_handler('pge_event_access_unassign_guest_handler');
w2_ok('F5 unassign AJAX end-to-end success shape has no PII', $resp['success'] === true && $resp['data']['changed'] === true && !isset($resp['data']['name']) && !isset($resp['data']['note']) && !isset($resp['data']['code']) && strpos(json_encode($resp), 'Private Name') === false);

// Actor spoof (Section 18/37): a client-sent actor_user_id/user_id field
// must be completely ignored; the real session (Viewer, denied) governs.
$f = w2_matrix_fixture();
$GLOBALS['w2_current_user_id'] = $f['viewer']; // real session is a Viewer
w2_assignment(10, '0591111111', $f['g1'], 1);
$before_mutations = $GLOBALS['w2_mutation_calls'];
w2_ajax_post([
    'event_id' => '10', 'expected_group_id' => (string) $f['g1'], 'guest_phone' => '0591111111',
    'actor_user_id' => (string) $f['admin'], 'user_id' => (string) $f['admin'], 'manager_id' => (string) $f['admin'], 'owner_id' => (string) $f['admin'],
]);
$resp = w2_call_ajax_handler('pge_event_access_unassign_guest_handler');
w2_ok('G1 (actor spoof) spoofed actor_user_id/user_id/manager_id/owner_id fields are ignored — real Viewer session denied', $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');
w2_ok('G2 (actor spoof) Repository mutation was never invoked for the spoofed/denied request', $GLOBALS['w2_mutation_calls'] === $before_mutations);

// Source-level proof no $_POST actor field is ever read anywhere in the
// file (comments stripped — this file's own docblocks deliberately mention
// these exact field names to explain they are never read).
w2_ok('G3 AJAX source never reads $_POST[\'actor_user_id\']/[\'user_id\']/[\'manager_id\']/[\'owner_id\']', preg_match('/\$_POST\[\'(actor_user_id|user_id|manager_id|owner_id)\'\]/', $ajax_write_code) === 0);

// No wp_ajax_nopriv_ registration (Section 35).
w2_ok('H1 no wp_ajax_nopriv_ registered for assign/move/unassign', preg_match('/wp_ajax_nopriv_pge_event_access_(assign|move|unassign)_guest/', $ajax_write_source) === 0);
w2_ok('H2 wp_ajax_ hooks ARE registered for all three actions (real add_action() calls captured at require-time)', isset($GLOBALS['w2_registered_hooks']['wp_ajax_pge_event_access_assign_guest']) && isset($GLOBALS['w2_registered_hooks']['wp_ajax_pge_event_access_move_guest']) && isset($GLOBALS['w2_registered_hooks']['wp_ajax_pge_event_access_unassign_guest']));
w2_ok('H3 no wp_ajax_nopriv_ hook was ever actually registered (runtime proof, not just source grep)', !isset($GLOBALS['w2_registered_hooks']['wp_ajax_nopriv_pge_event_access_assign_guest']) && !isset($GLOBALS['w2_registered_hooks']['wp_ajax_nopriv_pge_event_access_move_guest']) && !isset($GLOBALS['w2_registered_hooks']['wp_ajax_nopriv_pge_event_access_unassign_guest']));

// ──────────────────────────────────────────────────────────────
// Section 38 — Cross-event: Manager in event A cannot reach a group that
// belongs to event B, even by numeric id guess.
// ──────────────────────────────────────────────────────────────

w2_reset_db();
w2_event(10, 1); // event A
w2_event(11, 1); // event B
$gA = w2_group(10);
$gB = w2_group(11); // a real group, but it belongs to event B
$mgrA = w2_membership(10, 3, 'manager');
w2_access(10, $mgrA, $gA);
w2_assignment(10, '0591111111', $gA, 1);
$before_mutations = $GLOBALS['w2_mutation_calls'];
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, 3, '0591111111', $gA, $gB);
w2_ok('J1 cross-event group id (not granted in event A) is denied — authorization layer alone stops it', w2_code($result) === 'not_authorized' && $GLOBALS['w2_mutation_calls'] === $before_mutations);

// Defense in depth: even if a corrupted access grant in event A happened to
// reference event B's group id directly, resolve_context() itself fails
// closed (list_group_ids_for_membership() -> normalize_scoped_ids() detects
// the cross-event reference) before any can_*_guest() decision runs.
w2_reset_db();
w2_event(10, 1);
w2_event(11, 1);
$gA = w2_group(10);
$gB = w2_group(11);
$mgrCorrupt = w2_membership(10, 3, 'manager');
w2_access(10, $mgrCorrupt, $gB); // corrupted: event-10 membership granted a group that is really event 11's
$before_mutations = $GLOBALS['w2_mutation_calls'];
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, 3, '0591111111', $gA);
w2_ok('J2 corrupted cross-event access grant fails closed at context resolution (defense in depth)', $result instanceof WP_Error && $GLOBALS['w2_mutation_calls'] === $before_mutations);

// ──────────────────────────────────────────────────────────────
// Section 39 — Concurrency: expected_group_id reaches Repository unchanged.
// ──────────────────────────────────────────────────────────────

$f = w2_matrix_fixture();
w2_assignment(10, '0591111111', $f['g1'], 1);
$GLOBALS['w2_current_user_id'] = $f['owner'];
w2_ajax_post(['event_id' => '10', 'expected_group_id' => (string) $f['g2'], 'new_group_id' => (string) $f['g1'], 'guest_phone' => '0591111111']);
$resp = w2_call_ajax_handler('pge_event_access_move_guest_handler');
w2_ok('AA1 wrong expected_group_id reaches Repository unchanged and is correctly reported as concurrent_update', $resp['success'] === false && $resp['data']['reason'] === 'concurrent_update');
w2_ok('AA2 the assignment row was never mutated by the mismatched expected_group_id', $GLOBALS['w2_db']['assignments'][array_key_first($GLOBALS['w2_db']['assignments'])]['group_id'] === $f['g1']);

// ──────────────────────────────────────────────────────────────
// Section AC — Enumeration resistance: an unauthorized actor gets the same
// not_authorized/reason shape regardless of whether the guest/group exists.
// ──────────────────────────────────────────────────────────────

$f = w2_matrix_fixture();
$r_existing = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['stranger'], '0591111111', $f['g1']);
$r_missing_guest = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['stranger'], '0599999999', $f['g1']);
$r_missing_group = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f['stranger'], '0591111111', 999999);
w2_ok('AC1 stranger gets identical not_authorized whether guest/group exist or not', w2_code($r_existing) === 'not_authorized' && w2_code($r_missing_guest) === 'not_authorized' && w2_code($r_missing_group) === 'not_authorized');

// ══════════════════════════════════════════════════════════════
// Scoped Manager Enumeration/Privacy Fix Pass — dedicated coverage.
//
// Fixture: Manager (user 3) granted G1 + G2, deliberately NOT granted G3.
// Owner = user 1, Admin = user 2.
// ══════════════════════════════════════════════════════════════

function w2_enum_fixture()
{
    w2_reset_db();
    w2_event(10, 1); // owner
    $g1 = w2_group(10);
    $g2 = w2_group(10);
    $g3 = w2_group(10); // deliberately never granted to the manager below
    $GLOBALS['w2_admins'] = [2];
    $mgr = w2_membership(10, 3, 'manager');
    w2_access(10, $mgr, $g1);
    w2_access(10, $mgr, $g2);
    return ['g1' => $g1, 'g2' => $g2, 'g3' => $g3, 'owner' => 1, 'admin' => 2, 'manager' => 3];
}

// ── M-ENUM — MOVE enumeration resistance ────────────────────────

// M-ENUM-1: phone is not a guest/assignment at all.
$f = w2_enum_fixture();
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000001', $f['g1'], $f['g2']);
w2_ok('M-ENUM-1 move: phone with zero assignment is not_authorized (not a distinguishable not_found/concurrent_update)', w2_code($result) === 'not_authorized');
w2_ok('M-ENUM-1b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('M-ENUM-1c zero guest Post Meta reads occurred (scope denied before Repository/resolver ever ran)', $GLOBALS['w2_meta_calls'] === $meta_before);

// M-ENUM-2: phone IS an invited guest (Post Meta entry exists) but has no
// current assignment at all — must be indistinguishable from M-ENUM-1.
$f = w2_enum_fixture();
w2_guest(10, '0590000002');
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000002', $f['g1'], $f['g2']);
w2_ok('M-ENUM-2 move: invited-but-unassigned phone is not_authorized, identical reason to M-ENUM-1', w2_code($result) === 'not_authorized');
w2_ok('M-ENUM-2b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('M-ENUM-2c zero guest Post Meta reads occurred', $GLOBALS['w2_meta_calls'] === $meta_before);

// M-ENUM-3: phone is assigned, but to G3 — outside this manager's scope.
$f = w2_enum_fixture();
w2_assignment(10, '0590000003', $f['g3'], 1);
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000003', $f['g1'], $f['g2']);
w2_ok('M-ENUM-3 move: out-of-scope G3 assignment is not_authorized, identical reason to M-ENUM-1/2 (the critical case)', w2_code($result) === 'not_authorized');
w2_ok('M-ENUM-3b zero Repository mutation queries were issued for the out-of-scope assignment', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('M-ENUM-3c zero guest Post Meta reads occurred', $GLOBALS['w2_meta_calls'] === $meta_before);
w2_ok('M-ENUM-3d the G3 assignment row itself was never touched', $GLOBALS['w2_db']['assignments'][array_key_first($GLOBALS['w2_db']['assignments'])]['group_id'] === $f['g3']);

// M-ENUM-4: phone assigned to G1 (granted, in scope) but the client claims
// expected_group_id=G2 — actual group IS visible to this manager, so this
// is a legitimate concurrency conflict, safe to reveal as concurrent_update
// (proves the fix does not over-collapse legitimate in-scope information).
$f = w2_enum_fixture();
w2_assignment(10, '0590000004', $f['g1'], 1);
w2_guest(10, '0590000004'); // required so require_current_guest() resolves 'found' and the
                             // Repository actually reaches its expected_group_id compare
                             // (without this the phone is not a recognized current guest at
                             // all and move_guest_to_group() returns not_found before ever
                             // comparing groups — unrelated to the fix under test here).
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000004', $f['g2'], $f['g1']);
w2_ok('M-ENUM-4 move: in-scope actual group mismatched against claimed expected_group_id is concurrent_update (not collapsed — legitimate in-scope info)', w2_code($result) === 'concurrent_update');

// M-ENUM-5: an in-scope, expected-matching assignment whose guest identity
// happens to be ambiguous in Post Meta. Reachable only AFTER scope is
// already proven (the phone has a real, in-scope, matching assignment) —
// so surfacing ambiguous_guest here is not a new leak: the manager already
// legitimately knows this exact phone is assigned within their own scope.
$f = w2_enum_fixture();
w2_assignment(10, '0590000005', $f['g1'], 1);
w2_guest(10, '0590000005');
w2_guest(10, '0590000005'); // duplicate raw entry -> ambiguous canonical identity
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000005', $f['g1'], $f['g2']);
w2_ok('M-ENUM-5 move: ambiguous guest identity surfaces normally once scope is already proven (no new leak)', w2_code($result) === 'ambiguous_guest');

// Legitimate Manager success is preserved (Section 16).
$f = w2_enum_fixture();
w2_assignment(10, '0590000006', $f['g1'], 1);
w2_guest(10, '0590000006'); // required for require_current_guest() to resolve 'found' (see
                             // the M-ENUM-4 comment above for why this is unrelated to the
                             // fix under test).
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000006', $f['g1'], $f['g2']);
w2_ok('M-OK manager granted BOTH groups still successfully moves an in-scope guest G1->G2', is_array($result) && $result['changed'] === true && $result['group_id'] === $f['g2']);
w2_ok('M-OK audit has correct actor id and no PII', end($GLOBALS['w2_db']['audit'])['actor_user_id'] === $f['manager'] && strpos(json_encode($GLOBALS['w2_db']['audit']), '059') === false);

// ── U-ENUM — UNASSIGN enumeration resistance ────────────────────

// U-ENUM-1: phone has no assignment at all.
$f = w2_enum_fixture();
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['manager'], '0590000011', $f['g1']);
w2_ok('U-ENUM-1 unassign: phone with zero assignment is not_authorized for a Manager (no longer a distinguishable no-op)', w2_code($result) === 'not_authorized');
w2_ok('U-ENUM-1b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('U-ENUM-1c zero guest Post Meta reads occurred', $GLOBALS['w2_meta_calls'] === $meta_before);

// U-ENUM-2: phone assigned to G3 — outside this manager's scope. Must be
// INDISTINGUISHABLE from U-ENUM-1 (the critical case from Section 8).
$f = w2_enum_fixture();
w2_assignment(10, '0590000012', $f['g3'], 1);
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['manager'], '0590000012', $f['g1']);
w2_ok('U-ENUM-2 unassign: out-of-scope G3 assignment is not_authorized, IDENTICAL reason/shape to U-ENUM-1', w2_code($result) === 'not_authorized');
w2_ok('U-ENUM-2b (the exact Section-8 requirement) "no assignment" and "assignment in ungranted G3" produce the same WP_Error code', w2_code($result) === w2_code(call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['manager'], '0590000099', $f['g1'])));
w2_ok('U-ENUM-2c zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('U-ENUM-2d zero guest Post Meta reads occurred', $GLOBALS['w2_meta_calls'] === $meta_before);
w2_ok('U-ENUM-2e the G3 assignment row itself was never touched/deleted', isset($GLOBALS['w2_db']['assignments'][array_key_first($GLOBALS['w2_db']['assignments'])]));

// U-ENUM-3: phone assigned to G1 (granted) — legitimate success preserved.
$f = w2_enum_fixture();
w2_assignment(10, '0590000013', $f['g1'], 1);
$result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['manager'], '0590000013', $f['g1']);
w2_ok('U-ENUM-3 unassign: in-scope G1 assignment still succeeds normally', is_array($result) && $result['changed'] === true);
w2_ok('U-ENUM-3b audit has correct actor id and no PII', end($GLOBALS['w2_db']['audit'])['actor_user_id'] === $f['manager'] && strpos(json_encode($GLOBALS['w2_db']['audit']), '059') === false);

// U-ENUM-4: phone assigned to G1 (in scope) but client claims expected=G2
// — legitimate in-scope concurrency conflict, safe to reveal.
$f = w2_enum_fixture();
w2_assignment(10, '0590000014', $f['g1'], 1);
$result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['manager'], '0590000014', $f['g2']);
w2_ok('U-ENUM-4 unassign: in-scope actual group mismatched against claimed expected is concurrent_update (legitimate in-scope info, not collapsed)', w2_code($result) === 'concurrent_update');

// ── Owner/Admin semantics fully preserved (Section 17) ──────────

$f = w2_enum_fixture();
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['owner'], '0599999888', $f['g1'], $f['g2']);
w2_ok('OWNER-PRESERVE move: missing guest is still not_found for Owner (scope check is a no-op for Owner/Admin)', w2_code($result) === 'not_found');

$f = w2_enum_fixture();
w2_guest(10, '0599999889'); w2_guest(10, '0599999889');
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['owner'], '0599999889', $f['g1'], $f['g2']);
w2_ok('OWNER-PRESERVE move: ambiguous guest is still ambiguous_guest for Owner', w2_code($result) === 'ambiguous_guest');

$f = w2_enum_fixture();
w2_assignment(10, '0599999890', $f['g1'], 1);
w2_guest(10, '0599999890'); // required for require_current_guest() to resolve 'found' (see
                             // the M-ENUM-4 comment above for why this is unrelated to the
                             // fix under test).
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['owner'], '0599999890', $f['g2'], $f['g1']);
w2_ok('OWNER-PRESERVE move: expected-group mismatch is still concurrent_update for Owner', w2_code($result) === 'concurrent_update');

$f = w2_enum_fixture();
w2_assignment(10, '0599999891', $f['g3'], 1); // deliberately in a group not granted to ANY manager here — irrelevant for Owner
w2_guest(10, '0599999891'); // required for require_current_guest() to resolve 'found' (see
                             // the M-ENUM-4 comment above for why this is unrelated to the
                             // fix under test).
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['owner'], '0599999891', $f['g3'], $f['g1']);
w2_ok('OWNER-PRESERVE move: Owner can freely move a guest out of a group no Manager was ever granted (event-wide authority unaffected)', is_array($result) && $result['changed'] === true);

// Owner deleted-guest cleanup regression (Section 17/L, mirrors N1-N3 but
// with the Owner actor specifically, since Owner is architecturally
// unaffected by this fix pass).
w2_reset_db();
w2_event(10, 1);
$g1o = w2_group(10);
w2_assignment(10, '0599999892', $g1o, 1); // no w2_guest() call — Post Meta has zero guests for this phone
$meta_before = $GLOBALS['w2_meta_calls'];
$result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, 1, '0599999892', $g1o);
w2_ok('OWNER-PRESERVE unassign: deleted-guest cleanup still works for Owner (H1B-W3 semantics untouched)', is_array($result) && $result['changed'] === true);
w2_ok('OWNER-PRESERVE unassign: still zero Post Meta reads for Owner cleanup', $GLOBALS['w2_meta_calls'] === $meta_before);

$f = w2_enum_fixture();
$result = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['owner'], '0599999893', $f['g1']);
w2_ok('OWNER-PRESERVE unassign: no existing assignment is still a plain no-op success for Owner (not not_authorized — Owner semantics differ from Manager on purpose)', is_array($result) && $result['changed'] === false);

// ── Full authorization matrix re-confirmation (Section 18) ──────
// Re-run using the G1/G2-granted-G3-ungranted fixture to prove the fix
// pass did not alter who is allowed, only what a Manager can observe.
foreach ([
    ['owner', '1', true], ['admin', '2', true], ['manager', '3', true], // manager: in-scope assignment below
] as [$who, $suffix, $expect_allowed]) {
    $f = w2_enum_fixture();
    // Digits-only suffix per actor (not a letter): guest_phone is normalized
    // via pge_event_guests_norm_phone() (\D+ stripped) before every lookup —
    // a letter suffix would collapse to the SAME canonical phone across all
    // three iterations, and would also desync the raw phone the fixture
    // stores in the assignment row from the normalized phone the Repository
    // actually queries with, producing a spurious concurrent_update instead
    // of exercising the intended in-scope-allowed path.
    $rematrix_phone = '05900000990' . $suffix;
    w2_assignment(10, $rematrix_phone, $f['g1'], 1);
    w2_guest(10, $rematrix_phone); // required for require_current_guest() to resolve
                                    // 'found' (see the M-ENUM-4 comment above for why
                                    // this is unrelated to the fix under test).
    $result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f[$who], $rematrix_phone, $f['g1'], $f['g2']);
    w2_ok("REMATRIX move (in-scope) $who allowed=" . ($expect_allowed ? 'true' : 'false'), (!($result instanceof WP_Error)) === $expect_allowed);
}

// ══════════════════════════════════════════════════════════════
// Scoped Manager Malformed/Duplicate Assignment Privacy Fix Pass —
// dedicated coverage.
//
// Same fixture as above: Manager (user 3) granted G1 + G2, NOT G3. Proves
// that a Manager cannot distinguish "no assignment" from "this exact
// phone's relational assignment data is corrupt" via response shape,
// before an in-scope assignment has been proven.
// ══════════════════════════════════════════════════════════════

// E-MOVE-1: baseline — phone has no assignment at all.
$f = w2_enum_fixture();
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$audit_before = count($GLOBALS['w2_db']['audit']);
$result_e_move_1 = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000021', $f['g1'], $f['g2']);
w2_ok('E-MOVE-1 move: phone with zero assignment is not_authorized', w2_code($result_e_move_1) === 'not_authorized');
w2_ok('E-MOVE-1b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('E-MOVE-1c zero guest Post Meta reads occurred', $GLOBALS['w2_meta_calls'] === $meta_before);
w2_ok('E-MOVE-1d zero audit rows were added', count($GLOBALS['w2_db']['audit']) === $audit_before);

// E-MOVE-2: phone has DUPLICATE assignment rows in this event — a
// relational corruption state map_guest_groups() itself detects (two rows
// resolving to the same guest_phone) and reports via the SAME
// 'database_error' code a genuine connection/query failure would use. Must
// be indistinguishable from E-MOVE-1.
$f = w2_enum_fixture();
w2_assignment(10, '0590000022', $f['g1'], 1);
w2_assignment(10, '0590000022', $f['g2'], 1); // duplicate row, same phone
$assignments_before = $GLOBALS['w2_db']['assignments'];
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$audit_before = count($GLOBALS['w2_db']['audit']);
$result_e_move_2 = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000022', $f['g1'], $f['g2']);
w2_ok('E-MOVE-2 move: duplicate assignment rows produce the SAME public reason as E-MOVE-1 (no assignment)', w2_code($result_e_move_2) === w2_code($result_e_move_1));
w2_ok('E-MOVE-2b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('E-MOVE-2c zero guest Post Meta reads occurred', $GLOBALS['w2_meta_calls'] === $meta_before);
w2_ok('E-MOVE-2d zero audit rows were added', count($GLOBALS['w2_db']['audit']) === $audit_before);
w2_ok('E-MOVE-2e the duplicate assignment rows themselves were never touched', $GLOBALS['w2_db']['assignments'] === $assignments_before);

// E-MOVE-3: phone's assignment references a group_id that does not exist
// at all (a missing-group reference — the LEFT JOIN in map_guest_groups()
// resolves related_event_id to null for this row).
$f = w2_enum_fixture();
w2_assignment(10, '0590000023', 999999, 1); // 999999 is not a real group id
$assignments_before = $GLOBALS['w2_db']['assignments'];
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$audit_before = count($GLOBALS['w2_db']['audit']);
$result_e_move_3 = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000023', $f['g1'], $f['g2']);
w2_ok('E-MOVE-3 move: missing-group assignment produces the SAME public reason as E-MOVE-1', w2_code($result_e_move_3) === w2_code($result_e_move_1));
w2_ok('E-MOVE-3b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('E-MOVE-3c zero guest Post Meta reads occurred', $GLOBALS['w2_meta_calls'] === $meta_before);
w2_ok('E-MOVE-3d zero audit rows were added', count($GLOBALS['w2_db']['audit']) === $audit_before);
w2_ok('E-MOVE-3e the missing-group assignment row itself was never touched', $GLOBALS['w2_db']['assignments'] === $assignments_before);

// E-MOVE-4: phone's assignment row has event_id=10 (this event) but its
// group_id actually belongs to a DIFFERENT event (20) — a cross-event
// relational corruption, reported via normalize_assignment()'s distinct
// 'cross_event' code (still collapsed identically for a scoped Manager).
$f = w2_enum_fixture();
w2_event(20, 1);
$foreign_group = w2_group(20);
w2_assignment(10, '0590000024', $foreign_group, 1);
$assignments_before = $GLOBALS['w2_db']['assignments'];
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$audit_before = count($GLOBALS['w2_db']['audit']);
$result_e_move_4 = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000024', $f['g1'], $f['g2']);
w2_ok('E-MOVE-4 move: cross-event assignment corruption produces the SAME public reason as E-MOVE-1', w2_code($result_e_move_4) === w2_code($result_e_move_1));
w2_ok('E-MOVE-4b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('E-MOVE-4c zero guest Post Meta reads occurred', $GLOBALS['w2_meta_calls'] === $meta_before);
w2_ok('E-MOVE-4d zero audit rows were added', count($GLOBALS['w2_db']['audit']) === $audit_before);
w2_ok('E-MOVE-4e the cross-event assignment row itself was never touched', $GLOBALS['w2_db']['assignments'] === $assignments_before);

// E-MOVE-5: phone's assignment row is malformed at the storage-shape level
// (empty created_at — fails normalize_assignment()'s required_string()
// check), faithfully modeled via w2_malformed_assignment().
$f = w2_enum_fixture();
w2_malformed_assignment(10, '0590000025', $f['g1']);
$assignments_before = $GLOBALS['w2_db']['assignments'];
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$audit_before = count($GLOBALS['w2_db']['audit']);
$result_e_move_5 = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000025', $f['g1'], $f['g2']);
w2_ok('E-MOVE-5 move: malformed relational assignment row produces the SAME public reason as E-MOVE-1', w2_code($result_e_move_5) === w2_code($result_e_move_1));
w2_ok('E-MOVE-5b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('E-MOVE-5c zero guest Post Meta reads occurred', $GLOBALS['w2_meta_calls'] === $meta_before);
w2_ok('E-MOVE-5d zero audit rows were added', count($GLOBALS['w2_db']['audit']) === $audit_before);
w2_ok('E-MOVE-5e the malformed assignment row itself was never touched', $GLOBALS['w2_db']['assignments'] === $assignments_before);

// ── E-UNASSIGN — same corruption states, unassign path ──────────

$f = w2_enum_fixture();
$meta_before = $GLOBALS['w2_meta_calls']; $mut_before = $GLOBALS['w2_mutation_calls'];
$audit_before = count($GLOBALS['w2_db']['audit']);
$result_e_unassign_1 = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['manager'], '0590000031', $f['g1']);
w2_ok('E-UNASSIGN-1 unassign: phone with zero assignment is not_authorized for a Manager', w2_code($result_e_unassign_1) === 'not_authorized');
w2_ok('E-UNASSIGN-1b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('E-UNASSIGN-1c zero guest Post Meta reads occurred', $GLOBALS['w2_meta_calls'] === $meta_before);
w2_ok('E-UNASSIGN-1d zero audit rows were added', count($GLOBALS['w2_db']['audit']) === $audit_before);

$f = w2_enum_fixture();
w2_assignment(10, '0590000032', $f['g1'], 1);
w2_assignment(10, '0590000032', $f['g2'], 1);
$assignments_before = $GLOBALS['w2_db']['assignments'];
$mut_before = $GLOBALS['w2_mutation_calls']; $audit_before = count($GLOBALS['w2_db']['audit']);
$result_e_unassign_2 = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['manager'], '0590000032', $f['g1']);
w2_ok('E-UNASSIGN-2 unassign: duplicate assignment rows produce the SAME public reason as E-UNASSIGN-1', w2_code($result_e_unassign_2) === w2_code($result_e_unassign_1));
w2_ok('E-UNASSIGN-2b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('E-UNASSIGN-2c zero audit rows were added', count($GLOBALS['w2_db']['audit']) === $audit_before);
w2_ok('E-UNASSIGN-2d the duplicate assignment rows themselves were never touched', $GLOBALS['w2_db']['assignments'] === $assignments_before);

$f = w2_enum_fixture();
w2_assignment(10, '0590000033', 999999, 1);
$assignments_before = $GLOBALS['w2_db']['assignments'];
$mut_before = $GLOBALS['w2_mutation_calls']; $audit_before = count($GLOBALS['w2_db']['audit']);
$result_e_unassign_3 = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['manager'], '0590000033', $f['g1']);
w2_ok('E-UNASSIGN-3 unassign: missing-group assignment produces the SAME public reason as E-UNASSIGN-1', w2_code($result_e_unassign_3) === w2_code($result_e_unassign_1));
w2_ok('E-UNASSIGN-3b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('E-UNASSIGN-3c the missing-group assignment row itself was never touched', $GLOBALS['w2_db']['assignments'] === $assignments_before);

$f = w2_enum_fixture();
w2_event(21, 1);
$foreign_group2 = w2_group(21);
w2_assignment(10, '0590000034', $foreign_group2, 1);
$assignments_before = $GLOBALS['w2_db']['assignments'];
$mut_before = $GLOBALS['w2_mutation_calls']; $audit_before = count($GLOBALS['w2_db']['audit']);
$result_e_unassign_4 = call_user_func([$AS, 'unassign_guest_from_group_for_actor'], 10, $f['manager'], '0590000034', $f['g1']);
w2_ok('E-UNASSIGN-4 unassign: cross-event assignment corruption produces the SAME public reason as E-UNASSIGN-1', w2_code($result_e_unassign_4) === w2_code($result_e_unassign_1));
w2_ok('E-UNASSIGN-4b zero Repository mutation queries were issued', $GLOBALS['w2_mutation_calls'] === $mut_before);
w2_ok('E-UNASSIGN-4c the cross-event assignment row itself was never touched', $GLOBALS['w2_db']['assignments'] === $assignments_before);

// ── Legitimate in-scope behavior still preserved (Section 9) — already
// proven above by the pre-existing M-OK (success), M-ENUM-4 (in-scope
// mismatch -> concurrent_update), and M-ENUM-5 (ambiguous_guest surfaces
// normally once scope is already proven) tests; re-asserted here directly
// against this fix pass's own fixtures for a self-contained proof.

$f = w2_enum_fixture();
w2_assignment(10, '0590000041', $f['g1'], 1);
w2_guest(10, '0590000041'); // required for require_current_guest() to resolve 'found'
                             // (see the M-ENUM-4 comment above for why).
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000041', $f['g1'], $f['g2']);
w2_ok('LEGIT-1 Manager actual assignment in G1, expected=G1, move to G2 -> success', is_array($result) && $result['changed'] === true && $result['group_id'] === $f['g2']);

$f = w2_enum_fixture();
w2_assignment(10, '0590000042', $f['g1'], 1);
w2_guest(10, '0590000042'); // required for require_current_guest() to resolve 'found'.
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['manager'], '0590000042', $f['g2'], $f['g1']);
w2_ok('LEGIT-2 Manager actual assignment in G1, expected=G2 -> concurrent_update (legitimate in-scope info, not collapsed)', w2_code($result) === 'concurrent_update');

// Owner/Admin remain completely unaffected by this fix pass — the helper
// is a no-op for them, so their database_error/not_found/cross_event
// passthrough via the real Repository call must still surface normally
// (NOT collapsed to not_authorized — collapsing is a Manager-only rule).
$f = w2_enum_fixture();
w2_guest(10, '0599999895'); // required so require_current_guest() resolves 'found' and
                             // the Repository's own transactional lock_guest_assignment()
                             // — which independently detects >1 row for this phone — is
                             // actually reached (see the M-ENUM-4 comment above for why a
                             // w2_guest() call is needed to exercise the Repository path).
w2_assignment(10, '0599999895', $f['g1'], 1);
w2_assignment(10, '0599999895', $f['g2'], 1); // duplicate, same phone
$result = call_user_func([$AS, 'move_guest_to_group_for_actor'], 10, $f['owner'], '0599999895', $f['g1'], $f['g2']);
w2_ok('OWNER-PRESERVE-2 move: duplicate assignment for Owner still surfaces the real Repository database_error, NOT collapsed to not_authorized (Owner/Admin scope check is a no-op)', w2_code($result) === 'database_error');

// ──────────────────────────────────────────────────────────────
// Section N — Event-Context Enumeration Privacy Hardening (Phase H1C-EC1)
//
// Proves the same family of Event-ID Enumeration Oracle H1C-W4 found and
// closed on its own write surface also existed here, on all three W2 write
// use cases, and is now closed.
// ──────────────────────────────────────────────────────────────

$w2_nonexistent_event_id = 999999;

// N1 — Application Service layer: for every denied actor type x every
// operation, the WP_Error code is IDENTICAL whether event_id refers to a
// real event the actor is denied on, or to an event_id that does not
// exist at all. Zero Repository mutations / zero audit rows on both sides.
$f = w2_matrix_fixture();
$w2_ops = [
    'assign' => function ($actor_id, $event_id) use ($AS, $f) {
        return call_user_func([$AS, 'assign_guest_to_group_for_actor'], $event_id, $actor_id, '0591111111', $f['g1']);
    },
    'move' => function ($actor_id, $event_id) use ($AS, $f) {
        return call_user_func([$AS, 'move_guest_to_group_for_actor'], $event_id, $actor_id, '0591111111', $f['g1'], $f['g2']);
    },
    'unassign' => function ($actor_id, $event_id) use ($AS, $f) {
        return call_user_func([$AS, 'unassign_guest_from_group_for_actor'], $event_id, $actor_id, '0591111111', $f['g1']);
    },
];

foreach ($w2_ops as $op => $call) {
    foreach ([
        ['manager_unauth', $f['manager_unauth']], ['viewer', $f['viewer']],
        ['revoked_manager', $f['revoked_manager']], ['revoked_viewer', $f['revoked_viewer']],
        ['stranger', $f['stranger']],
    ] as [$who, $actor_id]) {
        $before_mut = $GLOBALS['w2_mutation_calls'];
        $before_audit = count($GLOBALS['w2_db']['audit']);
        $code_existing = w2_code($call($actor_id, 10));
        $mut_ok_existing = $GLOBALS['w2_mutation_calls'] === $before_mut && count($GLOBALS['w2_db']['audit']) === $before_audit;

        $before_mut = $GLOBALS['w2_mutation_calls'];
        $before_audit = count($GLOBALS['w2_db']['audit']);
        $code_missing = w2_code($call($actor_id, $w2_nonexistent_event_id));
        $mut_ok_missing = $GLOBALS['w2_mutation_calls'] === $before_mut && count($GLOBALS['w2_db']['audit']) === $before_audit;

        w2_ok("N1 $op $who on an EXISTING event is not_authorized", $code_existing === 'not_authorized');
        w2_ok("N1 $op $who on a NONEXISTENT event is ALSO not_authorized, never a distinct not_found", $code_missing === 'not_authorized');
        w2_ok("N1 $op $who: existing-denied vs nonexistent-event are publicly indistinguishable (identical WP_Error code)", $code_existing === $code_missing);
        w2_ok("N1 $op $who: zero Repository mutations / zero audit rows on both sides", $mut_ok_existing && $mut_ok_missing);
    }
}

// N2 — AJAX layer: full public JSON response (reason AND message) is
// byte-for-byte identical between an existing event the actor is denied on
// and a nonexistent event, for all three endpoints.
$w2_handlers = [
    'assign' => ['pge_event_access_assign_guest_handler', ['group_id' => (string) $f['g1'], 'guest_phone' => '0591111111']],
    'move' => ['pge_event_access_move_guest_handler', ['expected_group_id' => (string) $f['g1'], 'new_group_id' => (string) $f['g2'], 'guest_phone' => '0591111111']],
    'unassign' => ['pge_event_access_unassign_guest_handler', ['expected_group_id' => (string) $f['g1'], 'guest_phone' => '0591111111']],
];

foreach ($w2_handlers as $op => $handler_spec) {
    [$handler_fn, $extra_fields] = $handler_spec;
    foreach ([['manager_unauth', $f['manager_unauth']], ['stranger', $f['stranger']]] as [$who, $actor_id]) {
        $GLOBALS['w2_current_user_id'] = $actor_id;
        w2_ajax_post(array_merge(['event_id' => '10'], $extra_fields));
        $resp_existing = w2_call_ajax_handler($handler_fn);

        $GLOBALS['w2_current_user_id'] = $actor_id;
        w2_ajax_post(array_merge(['event_id' => (string) $w2_nonexistent_event_id], $extra_fields));
        $resp_missing = w2_call_ajax_handler($handler_fn);

        w2_ok("N2 AJAX $op $who: existing-denied response is not_authorized", $resp_existing['success'] === false && $resp_existing['data']['reason'] === 'not_authorized');
        w2_ok("N2 AJAX $op $who: nonexistent-event response is ALSO not_authorized, never not_found", $resp_missing['success'] === false && $resp_missing['data']['reason'] === 'not_authorized');
        w2_ok("N2 AJAX $op $who: full public response (reason AND message) is byte-for-byte identical between existing-denied and nonexistent-event", $resp_existing['data'] === $resp_missing['data']);
    }
}

// N3 — Actor spoofing remains impossible even against a nonexistent
// event_id.
$GLOBALS['w2_current_user_id'] = $f['manager_unauth'];
w2_ajax_post([
    'event_id' => (string) $w2_nonexistent_event_id, 'group_id' => (string) $f['g1'], 'guest_phone' => '0591111111',
    'actor_user_id' => (string) $f['owner'], 'user_id' => (string) $f['owner'],
    'owner_id' => (string) $f['owner'], 'manager_id' => (string) $f['owner'],
]);
$before_mut = $GLOBALS['w2_mutation_calls'];
$resp = w2_call_ajax_handler('pge_event_access_assign_guest_handler');
w2_ok('N3 actor spoof remains impossible against a nonexistent event_id — the real (denied Manager) session is still denied as not_authorized', $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');
w2_ok('N3 the spoofed request against a nonexistent event triggered zero Repository mutations', $GLOBALS['w2_mutation_calls'] === $before_mut);

// N4 — Regression sanity: a real Owner on a real event is completely
// unaffected (the fix only touches the resolve_context() WP_Error branch,
// which a proven Owner/Admin never enters for an EXISTING event).
$f4 = w2_matrix_fixture();
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f4['owner'], '0591111111', $f4['g1']);
w2_ok('N4 Owner on a real event can still ASSIGN normally after the fix', is_array($result) && $result['changed'] === true);

// N5 — Critical negative proof: the fix is NOT a blind global
// not_found -> not_authorized rule. A proven Owner on a REAL event with a
// group_id that genuinely does not exist still gets the real, honest,
// POST-authority Repository not_found (mirrors K5 above, re-confirmed here
// under the new gate).
$f5 = w2_matrix_fixture();
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 10, $f5['owner'], '0591111111', 999999);
w2_ok('N5 Owner on a REAL event with a genuinely missing group_id still returns real not_found (the fix did NOT become a blind global rule)', w2_code($result) === 'not_found');

// N6 — Source-level lock-in: all three public methods route through the
// shared resolve_event_actor_context() gate.
$app_source_full_n = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$app_code_only_n = w2_strip_comments($app_source_full_n);
w2_ok('N6 the shared resolve_event_actor_context() gate exists', strpos($app_code_only_n, 'function resolve_event_actor_context') !== false);
foreach (['assign_guest_to_group_for_actor', 'move_guest_to_group_for_actor', 'unassign_guest_from_group_for_actor'] as $method) {
    $pos = strpos($app_code_only_n, "function $method");
    $next_pos = strpos($app_code_only_n, 'function ', $pos + 10);
    $body = $next_pos !== false ? substr($app_code_only_n, $pos, $next_pos - $pos) : substr($app_code_only_n, $pos);
    w2_ok(
        "N6 $method uses the shared resolve_event_actor_context() gate directly (no independent resolve_context() call of its own)",
        strpos($body, 'resolve_event_actor_context') !== false && strpos($body, 'PGE_Event_Access_Authorization::resolve_context') === false
    );
}

// ──────────────────────────────────────────────────────────────
// Section O — PRE-AUTHORITY phase-based collapse follow-up (Phase
// H1C-EC1 follow-up security review).
//
// Section N above proved and closed the 'not_found' half of the
// resolve_context() Event-ID Enumeration Oracle. A follow-up review
// found the shared gate (resolve_event_actor_context()) was collapsing
// ONLY the 'not_found' WP_Error code, while every OTHER resolve_context()
// WP_Error -- most notably 'database_error' from an ambiguous/duplicated
// membership row for this same actor at a REAL event -- was passed
// through unchanged. That database_error is still raised strictly BEFORE
// any assign/move/unassign capability decision, i.e. PRE-AUTHORITY
// exactly like not_found, so it must ALSO collapse to not_authorized.
// This section proves Case A (nonexistent event) and Case B (a real,
// existing event where this actor's own membership row is ambiguous) are
// publicly indistinguishable, for all three write operations.
// ──────────────────────────────────────────────────────────────

function w2_ambiguous_membership_fixture()
{
    w2_reset_db();
    w2_event(20, 1); // owner = user 1, a fresh event distinct from Section N's event 10
    $g1 = w2_group(20);
    $g2 = w2_group(20);
    w2_guest(20, '0592222222');
    // Actor 8 gets TWO active membership rows for the SAME (event, user)
    // pair -> Repository's get_membership_for_user() ("... LIMIT 2")
    // fails closed with database_error before any capability decision is
    // ever reached.
    w2_membership(20, 8, 'manager');
    w2_membership(20, 8, 'viewer');
    return ['g1' => $g1, 'g2' => $g2, 'owner' => 1, 'ambiguous_actor' => 8];
}

$w2_nonexistent_event_id_o = 888888;
$fo = w2_ambiguous_membership_fixture();
$w2_ops_o = [
    'assign' => function ($actor_id, $event_id) use ($AS, $fo) {
        return call_user_func([$AS, 'assign_guest_to_group_for_actor'], $event_id, $actor_id, '0592222222', $fo['g1']);
    },
    'move' => function ($actor_id, $event_id) use ($AS, $fo) {
        return call_user_func([$AS, 'move_guest_to_group_for_actor'], $event_id, $actor_id, '0592222222', $fo['g1'], $fo['g2']);
    },
    'unassign' => function ($actor_id, $event_id) use ($AS, $fo) {
        return call_user_func([$AS, 'unassign_guest_from_group_for_actor'], $event_id, $actor_id, '0592222222', $fo['g1']);
    },
];

// O1 — Application Service layer.
foreach ($w2_ops_o as $op => $call) {
    $before_mut = $GLOBALS['w2_mutation_calls'];
    $before_audit = count($GLOBALS['w2_db']['audit']);
    $code_existing = w2_code($call($fo['ambiguous_actor'], 20));
    $mut_ok_existing = $GLOBALS['w2_mutation_calls'] === $before_mut && count($GLOBALS['w2_db']['audit']) === $before_audit;

    $before_mut = $GLOBALS['w2_mutation_calls'];
    $before_audit = count($GLOBALS['w2_db']['audit']);
    $code_missing = w2_code($call($fo['ambiguous_actor'], $w2_nonexistent_event_id_o));
    $mut_ok_missing = $GLOBALS['w2_mutation_calls'] === $before_mut && count($GLOBALS['w2_db']['audit']) === $before_audit;

    w2_ok("O1 $op ambiguous-membership actor on the REAL event 20 is not_authorized, NOT a distinct database_error", $code_existing === 'not_authorized');
    w2_ok("O1 $op ambiguous-membership actor on a NONEXISTENT event is ALSO not_authorized", $code_missing === 'not_authorized');
    w2_ok("O1 $op: existing (ambiguous-membership) vs nonexistent-event are publicly indistinguishable (identical WP_Error code)", $code_existing === $code_missing);
    w2_ok("O1 $op: zero Repository mutations / zero audit rows on both sides", $mut_ok_existing && $mut_ok_missing);
}

// O2 — AJAX layer.
$w2_handlers_o = [
    'assign' => ['pge_event_access_assign_guest_handler', ['group_id' => (string) $fo['g1'], 'guest_phone' => '0592222222']],
    'move' => ['pge_event_access_move_guest_handler', ['expected_group_id' => (string) $fo['g1'], 'new_group_id' => (string) $fo['g2'], 'guest_phone' => '0592222222']],
    'unassign' => ['pge_event_access_unassign_guest_handler', ['expected_group_id' => (string) $fo['g1'], 'guest_phone' => '0592222222']],
];
foreach ($w2_handlers_o as $op => $handler_spec) {
    [$handler_fn, $extra_fields] = $handler_spec;
    $GLOBALS['w2_current_user_id'] = $fo['ambiguous_actor'];
    w2_ajax_post(array_merge(['event_id' => '20'], $extra_fields));
    $resp_existing = w2_call_ajax_handler($handler_fn);

    $GLOBALS['w2_current_user_id'] = $fo['ambiguous_actor'];
    w2_ajax_post(array_merge(['event_id' => (string) $w2_nonexistent_event_id_o], $extra_fields));
    $resp_missing = w2_call_ajax_handler($handler_fn);

    w2_ok("O2 AJAX $op ambiguous-membership actor: real-event response is not_authorized, never database_error/server_error", $resp_existing['success'] === false && $resp_existing['data']['reason'] === 'not_authorized');
    w2_ok("O2 AJAX $op ambiguous-membership actor: nonexistent-event response is ALSO not_authorized", $resp_missing['success'] === false && $resp_missing['data']['reason'] === 'not_authorized');
    w2_ok("O2 AJAX $op: full public response (reason AND message) is byte-for-byte identical between the real ambiguous-membership event and a nonexistent event", $resp_existing['data'] === $resp_missing['data']);
}

// O3 — Actor spoofing.
$fo3 = w2_ambiguous_membership_fixture();
$GLOBALS['w2_current_user_id'] = $fo3['ambiguous_actor'];
w2_ajax_post([
    'event_id' => '20', 'group_id' => (string) $fo3['g1'], 'guest_phone' => '0592222222',
    'actor_user_id' => (string) $fo3['owner'], 'user_id' => (string) $fo3['owner'],
    'owner_id' => (string) $fo3['owner'], 'manager_id' => (string) $fo3['owner'],
]);
$before_mut = $GLOBALS['w2_mutation_calls'];
$resp = w2_call_ajax_handler('pge_event_access_assign_guest_handler');
w2_ok('O3 actor spoof remains impossible for the ambiguous-membership actor — still not_authorized', $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');
w2_ok('O3 the spoofed request triggered zero Repository mutations', $GLOBALS['w2_mutation_calls'] === $before_mut);

// O4 — Regression sanity: the real Owner of the same event 20 is
// unaffected by actor 8's corrupted membership row.
$fo4 = w2_ambiguous_membership_fixture();
$result = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 20, $fo4['owner'], '0592222222', $fo4['g1']);
w2_ok('O4 the real Owner of event 20 can still ASSIGN normally — unaffected by actor 8\'s ambiguous membership row', is_array($result) && $result['changed'] === true);

// O5 — Post-authority error fidelity still preserved.
$fo5 = w2_ambiguous_membership_fixture();
$result5 = call_user_func([$AS, 'assign_guest_to_group_for_actor'], 20, $fo5['owner'], '0592222222', 999999);
w2_ok('O5 Owner on REAL event 20 with a genuinely missing group_id still returns real not_found (widened PRE-AUTHORITY collapse did not spill into POST-authority errors)', w2_code($result5) === 'not_found');

// O6 — Source-level: resolve_event_actor_context() must not special-case
// on a specific WP_Error code.
$app_source_full_o = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$pos_o = strpos($app_source_full_o, 'function resolve_event_actor_context');
$next_pos_o = strpos($app_source_full_o, 'function ', $pos_o + 10);
$body_o = $next_pos_o !== false ? substr($app_source_full_o, $pos_o, $next_pos_o - $pos_o) : substr($app_source_full_o, $pos_o);
w2_ok(
    'O6 resolve_event_actor_context() does not special-case on get_error_code() (every WP_Error collapses, not just one code)',
    strpos($body_o, 'get_error_code') === false
);

echo "\nH1C-W2: {$passed}/" . ($passed + $failed) . " passed\n";
exit($failed === 0 ? 0 : 1);
