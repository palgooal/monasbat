<?php
/**
 * Phase H1C-W4 — Owner/Admin Group Lifecycle Write Wiring.
 *
 * Independent suite (deliberately NOT added inside H1C-W2/W3 — same
 * precedent as W3's own independent file). Exercises the real
 * PGE_Event_Access_Application_Service write methods
 * (create_group_for_actor / rename_group_for_actor / archive_group_for_actor
 * / set_default_group_for_actor), the real
 * PGE_Event_Access_Authorization[_Context] decision layer (unmodified —
 * can_manage_event_structure() is the same flat Owner/Admin-only gate shape
 * already proven for can_manage_group_access() in H1C-W3), the real
 * PGE_Event_Access_Repository transactional mutation methods
 * (create_group()/rename_group()/archive_group()/set_default_group() —
 * unmodified), and the real AJAX handlers in event-access-ajax.php
 * (pge_event_access_create_group_handler /
 * pge_event_access_rename_group_handler /
 * pge_event_access_archive_group_handler /
 * pge_event_access_set_default_group_handler) — against a small in-memory
 * fake $wpdb (H1B relational tables only) and real WordPress function shims.
 * No real database, no real network, no UI.
 *
 * Unlike H1C-W3's fake $wpdb (which only ever needed a query()-dispatch
 * INSERT and a real delete() call), this domain's Repository issues FIVE
 * distinct raw-SQL shapes through $wpdb->query() alone: an INSERT (create),
 * a name/name_key UPDATE (rename), a status/name_key/default_slot UPDATE
 * (archive), and two default_slot UPDATEs (clear/set, shared by create's
 * make_default path and set_default_group) — all modeled below. There is no
 * $wpdb->delete() call anywhere in this domain (archiving does not delete a
 * row) and no $wpdb->insert() call (the INSERT goes through mutation_query()
 * like every other write in this Repository).
 *
 * Run: php tests/test-event-access-application-phase-h1c-w4.php
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

$GLOBALS['w4_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['w4_registered_hooks'][$hook] = true; }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

// ──────────────────────────────────────────────────────────────
// WordPress function shims
// ──────────────────────────────────────────────────────────────

function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function wp_unslash($value)
{
    if (is_array($value)) return array_map('wp_unslash', $value);
    return is_string($value) ? stripslashes($value) : $value;
}
function wp_json_encode($data) { return json_encode($data); }
function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }

$GLOBALS['w4_current_user_id'] = 0;
$GLOBALS['w4_logged_in'] = true;
$GLOBALS['w4_admins'] = [];
function get_current_user_id() { return $GLOBALS['w4_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['w4_logged_in']; }
function user_can($user_id, $capability)
{
    if ($capability !== 'administrator') return false;
    return in_array((int) $user_id, $GLOBALS['w4_admins'], true);
}

$GLOBALS['w4_posts'] = [];
function get_post_type($post_id)
{
    return $GLOBALS['w4_posts'][(int) $post_id]['post_type'] ?? false;
}
function get_post_field($field, $post_id)
{
    if (!isset($GLOBALS['w4_posts'][(int) $post_id][$field])) return '';
    return (string) $GLOBALS['w4_posts'][(int) $post_id][$field];
}

// ──────────────────────────────────────────────────────────────
// Fake schema
// ──────────────────────────────────────────────────────────────

class PGE_Event_Access_Schema
{
    public static function is_ready() { return $GLOBALS['w4_schema_ready']; }
    public static function maybe_upgrade() { throw new RuntimeException('maybe_upgrade() must never be called by a W4 write path.'); }
}

// ──────────────────────────────────────────────────────────────
// In-memory fixture store + SQL dispatcher (H1B relational tables only).
// ──────────────────────────────────────────────────────────────

function w4_tables()
{
    return [
        'groups' => 'wp_pge_event_invitation_groups',
        'memberships' => 'wp_pge_event_host_memberships',
        'access' => 'wp_pge_event_host_group_access',
        'assignments' => 'wp_pge_invitation_group_assignments',
        'audit' => 'wp_pge_event_access_audit_log',
    ];
}

function w4_reset_db()
{
    $GLOBALS['w4_db'] = [
        'groups' => [],
        'memberships' => [],
        'access' => [],
        'assignments' => [],
        'audit' => [],
        'next_id' => 1,
    ];
    $GLOBALS['w4_posts'] = [];
    $GLOBALS['w4_admins'] = [];
    $GLOBALS['w4_schema_ready'] = true;
    $GLOBALS['w4_current_user_id'] = 0;
    $GLOBALS['w4_logged_in'] = true;
    $GLOBALS['w4_mutation_calls'] = 0;
    $GLOBALS['w4_query_log'] = [];
    $GLOBALS['w4_force_insert_fail'] = false;
    $GLOBALS['w4_force_update_miss'] = false;
}

function w4_next_id() { return $GLOBALS['w4_db']['next_id']++; }

function w4_event($event_id, $author_id)
{
    $GLOBALS['w4_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => $author_id];
}

// Seeds a group row directly (bypasses create_group()) for rename/archive/
// set_default fixtures. name_key here is a simple ASCII-fold matching what
// normalize_group_name_input() would compute for a plain ASCII test name —
// tests that exercise CREATE itself (and therefore the real name_key
// derivation) go through the real Application Service call instead.
function w4_group($event_id, $status = 'active', $name = 'Group', $is_default = false)
{
    $id = w4_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w4_db']['groups'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'name' => $name,
        'name_key' => $status === 'active' ? strtolower($name) : null,
        'status' => $status, 'default_slot' => ($is_default && $status === 'active') ? 1 : null,
        'created_by_user_id' => 1, 'created_at' => $now, 'updated_at' => $now,
        'archived_at' => $status === 'archived' ? $now : null,
    ];
    return $id;
}

function w4_membership($event_id, $user_id, $role, $status = 'active')
{
    $id = w4_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w4_db']['memberships'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'user_id' => $user_id, 'role' => $role, 'status' => $status,
        'created_by_user_id' => 1, 'activated_at' => $now, 'revoked_at' => $status === 'revoked' ? $now : null,
        'created_at' => $now, 'updated_at' => $now,
    ];
    return $id;
}

function w4_access($event_id, $membership_id, $group_id)
{
    $id = w4_next_id();
    $GLOBALS['w4_db']['access'][] = [
        'id' => $id, 'event_id' => $event_id, 'membership_id' => $membership_id, 'group_id' => $group_id,
        'granted_by_user_id' => 1, 'created_at' => '2026-01-01 00:00:00',
    ];
    return $id;
}

function w4_assignment($event_id, $phone, $group_id, $actor = 1)
{
    $id = w4_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w4_db']['assignments'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'guest_phone' => $phone, 'group_id' => $group_id,
        'assigned_by_user_id' => $actor, 'created_at' => $now, 'updated_at' => $now,
    ];
    return $id;
}

function w4_dispatch_select($sql)
{
    $t = w4_tables();
    $sql = trim($sql);

    // lock_groups()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['groups'], '/') . ' WHERE event_id = (\d+) ORDER BY id FOR UPDATE$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $rows = array_values(array_filter($GLOBALS['w4_db']['groups'], function ($r) use ($event_id) { return $r['event_id'] === $event_id; }));
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    // read_group_inside_transaction()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['groups'], '/') . ' WHERE event_id = (\d+) AND id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $id = (int) $m[2];
        $rows = array_values(array_filter($GLOBALS['w4_db']['groups'], function ($r) use ($event_id, $id) {
            return $r['event_id'] === $event_id && $r['id'] === $id;
        }));
        return array_slice($rows, 0, 2);
    }

    // translate_duplicate_after_failure()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['groups'], '/') . ' WHERE event_id = (\d+) AND name_key = \'([^\']*)\' AND status = \'([^\']*)\' LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $name_key = $m[2]; $status = $m[3];
        $rows = array_values(array_filter($GLOBALS['w4_db']['groups'], function ($r) use ($event_id, $name_key, $status) {
            return $r['event_id'] === $event_id && $r['name_key'] === $name_key && $r['status'] === $status;
        }));
        return array_slice($rows, 0, 2);
    }

    // get_membership_for_user() — Authorization Context::resolve() for
    // every non-Owner/non-Admin actor.
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND user_id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $user_id = (int) $m[2];
        return array_values(array_filter($GLOBALS['w4_db']['memberships'], function ($r) use ($event_id, $user_id) {
            return $r['event_id'] === $event_id && $r['user_id'] === $user_id;
        }));
    }

    // get_membership_scoped() (used internally by list_group_ids_for_membership())
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $id = (int) $m[2];
        return array_values(array_filter($GLOBALS['w4_db']['memberships'], function ($r) use ($event_id, $id) {
            return $r['event_id'] === $event_id && $r['id'] === $id;
        }));
    }

    // list_group_ids_for_membership() join
    $join = '/^SELECT a\.event_id, a\.group_id, g\.event_id AS related_event_id FROM '
        . preg_quote($t['access'], '/') . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.membership_id = (\d+) ORDER BY a\.group_id ASC$/';
    if (preg_match($join, $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2];
        $out = [];
        foreach ($GLOBALS['w4_db']['access'] as $row) {
            if ($row['event_id'] !== $event_id || $row['membership_id'] !== $membership_id) continue;
            $group = $GLOBALS['w4_db']['groups'][$row['group_id']] ?? null;
            $out[] = ['event_id' => $row['event_id'], 'group_id' => $row['group_id'], 'related_event_id' => $group['event_id'] ?? null];
        }
        return $out;
    }

    return null;
}

function w4_dispatch_mutation($sql)
{
    $t = w4_tables();
    $sql = trim($sql);

    if ($sql === 'START TRANSACTION') {
        $GLOBALS['w4_snapshot'] = [$GLOBALS['w4_db']['groups'], $GLOBALS['w4_db']['audit'], $GLOBALS['w4_db']['next_id']];
        return ['handled' => true, 'result' => 0];
    }
    if ($sql === 'COMMIT') {
        $GLOBALS['w4_snapshot'] = null;
        return ['handled' => true, 'result' => 0];
    }
    if ($sql === 'ROLLBACK') {
        if ($GLOBALS['w4_snapshot'] !== null) {
            [$GLOBALS['w4_db']['groups'], $GLOBALS['w4_db']['audit'], $GLOBALS['w4_db']['next_id']] = $GLOBALS['w4_snapshot'];
        }
        $GLOBALS['w4_snapshot'] = null;
        return ['handled' => true, 'result' => 0];
    }

    // create_group()'s INSERT — default_slot is either the literal NULL or
    // a %d placeholder depending on $make_default, so group 5 below matches
    // either "NULL" or a digit string.
    $insertGroup = '/^INSERT INTO ' . preg_quote($t['groups'], '/')
        . ' \(event_id, name, name_key, status, default_slot, created_by_user_id, created_at, updated_at, archived_at\) VALUES \((\d+), \'([^\']*)\', \'([^\']*)\', \'([^\']*)\', (NULL|\d+), (\d+), \'([^\']*)\', \'([^\']*)\', NULL\)$/';
    if (preg_match($insertGroup, $sql, $m)) {
        if ($GLOBALS['w4_force_insert_fail']) {
            return ['handled' => true, 'result' => 0];
        }
        $GLOBALS['w4_mutation_calls']++;
        $id = w4_next_id();
        $GLOBALS['w4_db']['groups'][$id] = [
            'id' => $id, 'event_id' => (int) $m[1], 'name' => $m[2], 'name_key' => $m[3], 'status' => $m[4],
            'default_slot' => $m[5] === 'NULL' ? null : (int) $m[5], 'created_by_user_id' => (int) $m[6],
            'created_at' => $m[7], 'updated_at' => $m[8], 'archived_at' => null,
        ];
        return ['handled' => true, 'result' => 1, 'insert_id' => $id];
    }

    // rename_group()'s UPDATE (name/name_key/updated_at).
    $renameUpdate = '/^UPDATE ' . preg_quote($t['groups'], '/')
        . ' SET name = \'([^\']*)\', name_key = \'([^\']*)\', updated_at = \'([^\']*)\' WHERE event_id = (\d+) AND id = (\d+) AND status = \'([^\']*)\'$/';
    if (preg_match($renameUpdate, $sql, $m)) {
        return w4_apply_group_update((int) $m[5], (int) $m[4], ['name' => $m[1], 'name_key' => $m[2], 'updated_at' => $m[3]], $m[6]);
    }

    // archive_group()'s UPDATE (status/name_key/default_slot/archived_at/updated_at).
    $archiveUpdate = '/^UPDATE ' . preg_quote($t['groups'], '/')
        . ' SET status = \'([^\']*)\', name_key = NULL, default_slot = NULL, archived_at = \'([^\']*)\', updated_at = \'([^\']*)\' WHERE event_id = (\d+) AND id = (\d+) AND status = \'([^\']*)\'$/';
    if (preg_match($archiveUpdate, $sql, $m)) {
        return w4_apply_group_update((int) $m[5], (int) $m[4], ['status' => $m[1], 'name_key' => null, 'default_slot' => null, 'archived_at' => $m[2], 'updated_at' => $m[3]], $m[6]);
    }

    // update_group_default_slot() — clear (default_slot = NULL ... AND default_slot = %d).
    $clearDefault = '/^UPDATE ' . preg_quote($t['groups'], '/')
        . ' SET default_slot = NULL, updated_at = \'([^\']*)\' WHERE event_id = (\d+) AND id = (\d+) AND status = \'([^\']*)\' AND default_slot = (\d+)$/';
    if (preg_match($clearDefault, $sql, $m)) {
        $row = $GLOBALS['w4_db']['groups'][(int) $m[3]] ?? null;
        if ($row === null || $row['event_id'] !== (int) $m[2] || $row['status'] !== $m[4] || $row['default_slot'] !== (int) $m[5]) {
            return ['handled' => true, 'result' => 0];
        }
        if ($GLOBALS['w4_force_update_miss']) { $GLOBALS['w4_force_update_miss'] = false; return ['handled' => true, 'result' => 0]; }
        $GLOBALS['w4_mutation_calls']++;
        $GLOBALS['w4_db']['groups'][(int) $m[3]]['default_slot'] = null;
        $GLOBALS['w4_db']['groups'][(int) $m[3]]['updated_at'] = $m[1];
        return ['handled' => true, 'result' => 1];
    }

    // update_group_default_slot() — set (default_slot = %d ... AND default_slot IS NULL).
    $setDefault = '/^UPDATE ' . preg_quote($t['groups'], '/')
        . ' SET default_slot = (\d+), updated_at = \'([^\']*)\' WHERE event_id = (\d+) AND id = (\d+) AND status = \'([^\']*)\' AND default_slot IS NULL$/';
    if (preg_match($setDefault, $sql, $m)) {
        $row = $GLOBALS['w4_db']['groups'][(int) $m[4]] ?? null;
        if ($row === null || $row['event_id'] !== (int) $m[3] || $row['status'] !== $m[5] || $row['default_slot'] !== null) {
            return ['handled' => true, 'result' => 0];
        }
        if ($GLOBALS['w4_force_update_miss']) { $GLOBALS['w4_force_update_miss'] = false; return ['handled' => true, 'result' => 0]; }
        $GLOBALS['w4_mutation_calls']++;
        $GLOBALS['w4_db']['groups'][(int) $m[4]]['default_slot'] = (int) $m[1];
        $GLOBALS['w4_db']['groups'][(int) $m[4]]['updated_at'] = $m[2];
        return ['handled' => true, 'result' => 1];
    }

    $insertAudit = '/^INSERT INTO ' . preg_quote($t['audit'], '/')
        . ' \(event_id, actor_user_id, action, entity_type, entity_id, metadata, created_at\) VALUES \((\d+), (\d+), \'([^\']*)\', \'([^\']*)\', (\d+), \'([^\']*)\', \'([^\']*)\'\)$/';
    if (preg_match($insertAudit, $sql, $m)) {
        $GLOBALS['w4_mutation_calls']++;
        $GLOBALS['w4_db']['audit'][] = [
            'event_id' => (int) $m[1], 'actor_user_id' => (int) $m[2], 'action' => $m[3],
            'entity_type' => $m[4], 'entity_id' => (int) $m[5],
            // See the identical note in the W3 suite: prepare()'s %s
            // handling addslashes()-escapes the JSON string's own double
            // quotes before quoting it for the SQL literal, so the captured
            // text must be stripslashes()'d back before json_decode() can
            // parse it — otherwise json_decode() silently returns null.
            'metadata' => json_decode(stripslashes($m[6]), true), 'created_at' => $m[7],
        ];
        return ['handled' => true, 'result' => 1];
    }

    $insertAuditNullMeta = '/^INSERT INTO ' . preg_quote($t['audit'], '/')
        . ' \(event_id, actor_user_id, action, entity_type, entity_id, metadata, created_at\) VALUES \((\d+), (\d+), \'([^\']*)\', \'([^\']*)\', (\d+), NULL, \'([^\']*)\'\)$/';
    if (preg_match($insertAuditNullMeta, $sql, $m)) {
        $GLOBALS['w4_mutation_calls']++;
        $GLOBALS['w4_db']['audit'][] = [
            'event_id' => (int) $m[1], 'actor_user_id' => (int) $m[2], 'action' => $m[3],
            'entity_type' => $m[4], 'entity_id' => (int) $m[5], 'metadata' => null, 'created_at' => $m[6],
        ];
        return ['handled' => true, 'result' => 1];
    }

    return ['handled' => false];
}

function w4_apply_group_update($id, $event_id, array $fields, $expected_status)
{
    $row = $GLOBALS['w4_db']['groups'][$id] ?? null;
    if ($row === null || $row['event_id'] !== $event_id || $row['status'] !== $expected_status) {
        return ['handled' => true, 'result' => 0];
    }
    if ($GLOBALS['w4_force_update_miss']) { $GLOBALS['w4_force_update_miss'] = false; return ['handled' => true, 'result' => 0]; }
    $GLOBALS['w4_mutation_calls']++;
    $GLOBALS['w4_db']['groups'][$id] = array_merge($row, $fields);
    return ['handled' => true, 'result' => 1];
}

class PGE_W4_Fake_WPDB
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
        $GLOBALS['w4_query_log'][] = $sql;
        $rows = w4_dispatch_select($sql);
        if ($rows === null) {
            $this->last_error = 'w4-fake-wpdb: unrecognized SELECT: ' . $sql;
            return null;
        }
        $this->last_error = '';
        return $rows;
    }

    public function get_var($sql)
    {
        $GLOBALS['w4_query_log'][] = $sql;
        $this->last_error = 'w4-fake-wpdb: unrecognized scalar query: ' . $sql;
        return null;
    }

    public function query($sql)
    {
        $GLOBALS['w4_query_log'][] = $sql;
        $outcome = w4_dispatch_mutation($sql);
        if (!$outcome['handled']) {
            $this->last_error = 'w4-fake-wpdb: unexpected mutating query() call: ' . $sql;
            return false;
        }
        $this->last_error = '';
        if (array_key_exists('insert_id', $outcome)) $this->insert_id = $outcome['insert_id'];
        return $outcome['result'];
    }

    public function delete($table, $where, $formats)
    {
        $this->last_error = 'w4-fake-wpdb: unexpected delete() call on ' . $table;
        return false;
    }

    public function insert($table, $data, $formats = null)
    {
        $this->last_error = 'w4-fake-wpdb: unexpected insert() call';
        return false;
    }
}

w4_reset_db();
global $wpdb;
$wpdb = new PGE_W4_Fake_WPDB();

// ──────────────────────────────────────────────────────────────
// AJAX/JSON stubs
// ──────────────────────────────────────────────────────────────

class Test_Wp_Die_Exception extends \Exception {}

$GLOBALS['w4_json_response'] = null;
function wp_send_json_success($data = null)
{
    $GLOBALS['w4_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null)
{
    $GLOBALS['w4_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = [])
{
    throw new Test_Wp_Die_Exception('wp_die');
}

function w4_call_ajax_handler(callable $handler): array
{
    $GLOBALS['w4_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // expected
    }
    $raw = $GLOBALS['w4_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function w4_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_create_nonce($action) { return w4_nonce($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals(w4_nonce($action), (string) $nonce) ? 1 : false; }

require_once PGE_PATH . 'includes/event-guests.php';
require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';
require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';
require_once PGE_PATH . 'includes/class-pge-event-access-application-service.php';
require_once PGE_PATH . 'includes/event-access-ajax.php';

$passed = 0;
$failed = 0;
function w4_ok($label, $condition) { global $passed, $failed; if ($condition) { $passed++; echo "PASS: $label\n"; } else { $failed++; echo "FAIL: $label\n"; } }
function w4_code($value) { return $value instanceof WP_Error ? $value->get_error_code() : null; }

function w4_strip_comments($php_source)
{
    $out = '';
    foreach (token_get_all('<?php ' . $php_source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
}

function w4_ajax_post($fields)
{
    $_POST = array_merge(['nonce' => w4_nonce('pge_event_manage_nonce')], $fields);
}

$AS = 'PGE_Event_Access_Application_Service';
$Auth = 'PGE_Event_Access_Authorization';
$Repo = 'PGE_Event_Access_Repository';

// ──────────────────────────────────────────────────────────────
// Section C — Authorization matrix, all four operations, across Owner /
// Admin / Manager / Viewer / Revoked Manager / Revoked Viewer / Stranger.
// can_manage_event_structure() is a flat Owner/Admin-only gate with NO
// group-scoping parameter, so a Manager's own granted-group scope has zero
// bearing on the outcome. Every denial is proven to issue ZERO Repository
// mutation queries.
// ──────────────────────────────────────────────────────────────

function w4_matrix_fixture()
{
    w4_reset_db();
    w4_event(10, 1); // owner = user 1
    $g1 = w4_group(10, 'active', 'Group One');
    $GLOBALS['w4_admins'] = [2];
    $mgr = w4_membership(10, 3, 'manager');
    w4_access(10, $mgr, $g1);
    $viewer = w4_membership(10, 5, 'viewer');
    $revoked_mgr = w4_membership(10, 6, 'manager', 'revoked');
    $revoked_viewer = w4_membership(10, 7, 'viewer', 'revoked');
    return ['g1' => $g1, 'owner' => 1, 'admin' => 2, 'manager' => 3, 'viewer' => 5,
        'revoked_manager' => 6, 'revoked_viewer' => 7, 'stranger' => 99];
}

foreach ([
    ['create', function ($who, $f) use ($AS) { return call_user_func([$AS, 'create_group_for_actor'], 10, $f[$who], 'New Group'); }],
    ['rename', function ($who, $f) use ($AS) { return call_user_func([$AS, 'rename_group_for_actor'], 10, $f[$who], $f['g1'], 'Group One', 'Renamed'); }],
    ['archive', function ($who, $f) use ($AS) { return call_user_func([$AS, 'archive_group_for_actor'], 10, $f[$who], $f['g1']); }],
    ['set_default', function ($who, $f) use ($AS) { return call_user_func([$AS, 'set_default_group_for_actor'], 10, $f[$who], $f['g1']); }],
] as [$op, $call]) {
    foreach ([
        ['owner', true], ['admin', true], ['manager', false], ['viewer', false],
        ['revoked_manager', false], ['revoked_viewer', false], ['stranger', false],
    ] as [$who, $expect_allowed]) {
        $f = w4_matrix_fixture();
        $before_mutations = $GLOBALS['w4_mutation_calls'];
        $result = $call($who, $f);
        $allowed = !($result instanceof WP_Error);
        w4_ok("C-$op $who allowed=" . ($expect_allowed ? 'true' : 'false'), $allowed === $expect_allowed
            && ($expect_allowed || w4_code($result) === 'not_authorized')
            && ($expect_allowed || $GLOBALS['w4_mutation_calls'] === $before_mutations));
    }
}

// ──────────────────────────────────────────────────────────────
// Section D — CREATE behavior
// ──────────────────────────────────────────────────────────────

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], 'Fresh Group');
w4_ok('D1 owner create success shape', is_array($result) && $result['ok'] === true && $result['changed'] === true
    && $result['group']['name'] === 'Fresh Group' && $result['group']['name_key'] === 'fresh group'
    && $result['group']['status'] === 'active' && $result['group']['is_default'] === false
    && $result['group']['created_by_user_id'] === $f['owner'] && $result['group']['archived_at'] === null);
w4_ok('D1b create emits exactly one audit row (group_created)', count($GLOBALS['w4_db']['audit']) === 1
    && $GLOBALS['w4_db']['audit'][0]['action'] === 'group_created' && $GLOBALS['w4_db']['audit'][0]['entity_type'] === 'group');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], 'Group One'); // duplicate of fixture's g1 (case-insensitive)
w4_ok('D2 duplicate active name (case-insensitive) is duplicate', w4_code($result) === 'duplicate');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], 'GROUP ONE'); // same name_key, different case
w4_ok('D2b duplicate detection is case-insensitive via name_key', w4_code($result) === 'duplicate');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], '');
w4_ok('D3 empty name is invalid_name', w4_code($result) === 'invalid_name');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], '   ');
w4_ok('D4 whitespace-only name is invalid_name', w4_code($result) === 'invalid_name');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], 'Bad<Name>');
w4_ok('D5 name containing < or > is invalid_name', w4_code($result) === 'invalid_name');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], str_repeat('A', 200));
w4_ok('D6 name exceeding the 191-codepoint cap is invalid_name', w4_code($result) === 'invalid_name');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], ['array', 'not', 'string']);
w4_ok('D7 array name is invalid_input (application-layer strict type gate)', w4_code($result) === 'invalid_input');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], 'مجموعة العائلة'); // real Arabic name — no A-Z to fold, name_key unchanged
w4_ok('D8 a real Arabic name is accepted and name_key equals the (unfolded) name', is_array($result) && $result['group']['name'] === 'مجموعة العائلة' && $result['group']['name_key'] === 'مجموعة العائلة');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], 'First Default', true);
w4_ok('D9 create with make_default=true sets is_default=true', is_array($result) && $result['group']['is_default'] === true);
w4_ok('D9b create-as-default emits BOTH group_created and default_group_changed audit rows', count($GLOBALS['w4_db']['audit']) === 2
    && $GLOBALS['w4_db']['audit'][0]['action'] === 'group_created' && $GLOBALS['w4_db']['audit'][1]['action'] === 'default_group_changed'
    && $GLOBALS['w4_db']['audit'][1]['metadata']['previous_group_id'] === null);

$f = w4_matrix_fixture();
$g_old_default = w4_group(10, 'active', 'Old Default', true);
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], 'New Default', true);
w4_ok('D10 create-as-default clears the PREVIOUS default group\'s slot (only one default at a time)', is_array($result) && $result['group']['is_default'] === true
    && $GLOBALS['w4_db']['groups'][$g_old_default]['default_slot'] === null);
w4_ok('D10b default_group_changed audit records the previous default group id', end($GLOBALS['w4_db']['audit'])['metadata']['previous_group_id'] === $g_old_default);

$f = w4_matrix_fixture();
$GLOBALS['w4_schema_ready'] = false;
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], 'X');
w4_ok('D11 schema-not-ready create maps to write_unavailable', w4_code($result) === 'write_unavailable');
$GLOBALS['w4_schema_ready'] = true;

$f = w4_matrix_fixture();
$GLOBALS['w4_force_insert_fail'] = true;
$before_audit = count($GLOBALS['w4_db']['audit']);
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['owner'], 'Will Fail');
w4_ok('D12 forced INSERT failure is database_error', w4_code($result) === 'database_error');
w4_ok('D12b failed create emits no false audit row', count($GLOBALS['w4_db']['audit']) === $before_audit);
$GLOBALS['w4_force_insert_fail'] = false;

// ──────────────────────────────────────────────────────────────
// Section E — RENAME behavior
// ──────────────────────────────────────────────────────────────

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'rename_group_for_actor'], 10, $f['owner'], $f['g1'], 'Group One', 'Renamed Group');
w4_ok('E1 owner rename success shape', is_array($result) && $result['changed'] === true && $result['group']['name'] === 'Renamed Group' && $result['group']['name_key'] === 'renamed group');
w4_ok('E1b rename emits exactly one audit row (group_renamed)', count($GLOBALS['w4_db']['audit']) === 1 && $GLOBALS['w4_db']['audit'][0]['action'] === 'group_renamed');

$f = w4_matrix_fixture();
$before_audit = count($GLOBALS['w4_db']['audit']);
$result = call_user_func([$AS, 'rename_group_for_actor'], 10, $f['owner'], $f['g1'], 'Group One', 'Group One'); // same name
w4_ok('E2 rename to the exact same name is a no-op (changed=false), no audit', $result['changed'] === false && count($GLOBALS['w4_db']['audit']) === $before_audit);

$f = w4_matrix_fixture();
$g2 = w4_group(10, 'active', 'Group Two');
$result = call_user_func([$AS, 'rename_group_for_actor'], 10, $f['owner'], $f['g1'], 'Group One', 'Group Two');
w4_ok('E3 rename to a duplicate active name (a different group) is duplicate', w4_code($result) === 'duplicate');

$f = w4_matrix_fixture();
$archived = w4_group(10, 'archived', 'Archived Group');
$result = call_user_func([$AS, 'rename_group_for_actor'], 10, $f['owner'], $archived, 'Archived Group', 'New Name');
w4_ok('E4 rename of an archived group is invalid_state', w4_code($result) === 'invalid_state');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'rename_group_for_actor'], 10, $f['owner'], 999999, 'Anything', 'New Name');
w4_ok('E5 rename of a missing group id is not_found', w4_code($result) === 'not_found');

w4_reset_db();
w4_event(10, 1); w4_event(11, 1);
$foreign = w4_group(11, 'active', 'Foreign Group');
$result = call_user_func([$AS, 'rename_group_for_actor'], 10, 1, $foreign, 'Foreign Group', 'New Name');
w4_ok('E6 rename with a cross-event group id (real row, wrong event) is not_found', w4_code($result) === 'not_found');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'rename_group_for_actor'], 10, $f['owner'], $f['g1'], 'Wrong Expected Name', 'New Name');
w4_ok('E7 rename with a mismatched expected_name is concurrent_update', w4_code($result) === 'concurrent_update');
w4_ok('E7b mismatched expected_name never mutated the group row', $GLOBALS['w4_db']['groups'][$f['g1']]['name'] === 'Group One');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'rename_group_for_actor'], 10, $f['owner'], $f['g1'], 'Group One', '');
w4_ok('E8 rename to an empty new name is invalid_name', w4_code($result) === 'invalid_name');

$f = w4_matrix_fixture();
$GLOBALS['w4_force_update_miss'] = true;
$before_audit = count($GLOBALS['w4_db']['audit']);
$result = call_user_func([$AS, 'rename_group_for_actor'], 10, $f['owner'], $f['g1'], 'Group One', 'Raced Name');
w4_ok('E9 a lost race on the rename UPDATE itself (forced) is concurrent_update', w4_code($result) === 'concurrent_update');
w4_ok('E9b raced rename emits no false audit row', count($GLOBALS['w4_db']['audit']) === $before_audit);

// ──────────────────────────────────────────────────────────────
// Section F — ARCHIVE behavior
// ──────────────────────────────────────────────────────────────

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'archive_group_for_actor'], 10, $f['owner'], $f['g1']);
w4_ok('F1 owner archive success shape', is_array($result) && $result['changed'] === true && $result['group']['status'] === 'archived' && $result['group']['name_key'] === null);
w4_ok('F1b archive emits exactly one audit row (group_archived)', count($GLOBALS['w4_db']['audit']) === 1 && $GLOBALS['w4_db']['audit'][0]['action'] === 'group_archived' && $GLOBALS['w4_db']['audit'][0]['metadata']['was_default'] === false);

$f = w4_matrix_fixture();
$already = w4_group(10, 'archived', 'Already Archived');
$before_audit = count($GLOBALS['w4_db']['audit']);
$result = call_user_func([$AS, 'archive_group_for_actor'], 10, $f['owner'], $already);
w4_ok('F2 archiving an already-archived group is a no-op success (changed=false), NOT an error', is_array($result) && $result['changed'] === false);
w4_ok('F2b no-op archive emits no audit row', count($GLOBALS['w4_db']['audit']) === $before_audit);

$f = w4_matrix_fixture();
$default_group = w4_group(10, 'active', 'The Default', true);
$result = call_user_func([$AS, 'archive_group_for_actor'], 10, $f['owner'], $default_group);
w4_ok('F3 archiving the current default group succeeds and silently clears default_slot too', is_array($result) && $result['changed'] === true && $result['group']['status'] === 'archived');
w4_ok('F3b was_default=true recorded in the archive audit metadata', end($GLOBALS['w4_db']['audit'])['metadata']['was_default'] === true);
w4_ok('F3c exactly one audit row — no separate default_group_changed row for this side effect', count($GLOBALS['w4_db']['audit']) === 1);

$f = w4_matrix_fixture();
w4_assignment(10, '0591111111', $f['g1'], 1);
$result = call_user_func([$AS, 'archive_group_for_actor'], 10, $f['owner'], $f['g1']);
w4_ok('F4 archive of a group WITH an existing guest assignment still succeeds (no check, no block, matches the real Repository contract)', is_array($result) && $result['changed'] === true);
w4_ok('F4b the guest assignment row is untouched by archive (no cascade/cleanup)', $GLOBALS['w4_db']['assignments'][array_key_first($GLOBALS['w4_db']['assignments'])]['group_id'] === $f['g1']);

$f = w4_matrix_fixture();
$mgr_with_access = w4_membership(10, 20, 'manager');
w4_access(10, $mgr_with_access, $f['g1']);
// w4_matrix_fixture() itself already grants access for 'manager' (user 3) on
// g1, so the fixture plus this test's own grant means TWO access rows exist
// for g1 before archive — count the actual baseline rather than assuming 1,
// so this assertion proves "untouched" (before === after) instead of
// asserting an arbitrary literal that silently drifts if the fixture changes.
$access_count_for_g1_before = count(array_filter($GLOBALS['w4_db']['access'], function ($r) use ($f) { return $r['group_id'] === $f['g1']; }));
$result = call_user_func([$AS, 'archive_group_for_actor'], 10, $f['owner'], $f['g1']);
w4_ok('F5 archive of a group WITH an existing group-access grant still succeeds (no check, no block)', is_array($result) && $result['changed'] === true);
w4_ok('F5b the group-access grant row(s) are untouched by archive (no cascade/cleanup — matches H1C-W3\'s already-proven revoke_group_access asymmetry)', count(array_filter($GLOBALS['w4_db']['access'], function ($r) use ($f) { return $r['group_id'] === $f['g1']; })) === $access_count_for_g1_before && $access_count_for_g1_before >= 1);

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'archive_group_for_actor'], 10, $f['owner'], 999999);
w4_ok('F6 archive of a missing group id is not_found', w4_code($result) === 'not_found');

w4_reset_db();
w4_event(10, 1); w4_event(11, 1);
$foreign2 = w4_group(11, 'active', 'Foreign');
$result = call_user_func([$AS, 'archive_group_for_actor'], 10, 1, $foreign2);
w4_ok('F7 archive with a cross-event group id (real row, wrong event) is not_found', w4_code($result) === 'not_found');

$f = w4_matrix_fixture();
$GLOBALS['w4_force_update_miss'] = true;
$before_audit = count($GLOBALS['w4_db']['audit']);
$result = call_user_func([$AS, 'archive_group_for_actor'], 10, $f['owner'], $f['g1']);
w4_ok('F8 a lost race on the archive UPDATE itself (forced) is concurrent_update', w4_code($result) === 'concurrent_update');
w4_ok('F8b raced archive emits no false audit row', count($GLOBALS['w4_db']['audit']) === $before_audit);

// ──────────────────────────────────────────────────────────────
// Section G — SET DEFAULT behavior
// ──────────────────────────────────────────────────────────────

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'set_default_group_for_actor'], 10, $f['owner'], $f['g1']);
w4_ok('G1 set G1 default from no-default state succeeds', is_array($result) && $result['changed'] === true && $result['group']['is_default'] === true);
w4_ok('G1b default_group_changed audit records previous_group_id=null', end($GLOBALS['w4_db']['audit'])['metadata']['previous_group_id'] === null);

$f = w4_matrix_fixture();
$before_audit = count($GLOBALS['w4_db']['audit']);
$g_already_default = w4_group(10, 'active', 'Already Default', true);
$result = call_user_func([$AS, 'set_default_group_for_actor'], 10, $f['owner'], $g_already_default);
w4_ok('G2 setting the already-current default again is a no-op (changed=false), no audit', $result['changed'] === false && count($GLOBALS['w4_db']['audit']) === $before_audit);

$f = w4_matrix_fixture();
$g_old = w4_group(10, 'active', 'Old Default', true);
$g_new = w4_group(10, 'active', 'New Default');
$result = call_user_func([$AS, 'set_default_group_for_actor'], 10, $f['owner'], $g_new);
w4_ok('G3 switching default from G1 to G2 succeeds', is_array($result) && $result['changed'] === true && $result['group']['is_default'] === true);
w4_ok('G3b the previous default group\'s slot was cleared', $GLOBALS['w4_db']['groups'][$g_old]['default_slot'] === null);
w4_ok('G3c default_group_changed audit records the previous default group id', end($GLOBALS['w4_db']['audit'])['metadata']['previous_group_id'] === $g_old);

$f = w4_matrix_fixture();
$archived_target = w4_group(10, 'archived', 'Archived');
$result = call_user_func([$AS, 'set_default_group_for_actor'], 10, $f['owner'], $archived_target);
w4_ok('G4 setting an archived group as default is invalid_state', w4_code($result) === 'invalid_state');

$f = w4_matrix_fixture();
$result = call_user_func([$AS, 'set_default_group_for_actor'], 10, $f['owner'], 999999);
w4_ok('G5 set_default of a missing group id is not_found', w4_code($result) === 'not_found');

w4_reset_db();
w4_event(10, 1); w4_event(11, 1);
$foreign3 = w4_group(11, 'active', 'Foreign');
$result = call_user_func([$AS, 'set_default_group_for_actor'], 10, 1, $foreign3);
w4_ok('G6 set_default with a cross-event group id (real row, wrong event) is not_found', w4_code($result) === 'not_found');

$f = w4_matrix_fixture();
$GLOBALS['w4_force_update_miss'] = true;
$before_audit = count($GLOBALS['w4_db']['audit']);
$result = call_user_func([$AS, 'set_default_group_for_actor'], 10, $f['owner'], $f['g1']);
w4_ok('G7 a lost race on the set-default UPDATE itself (forced) is concurrent_update', w4_code($result) === 'concurrent_update');
w4_ok('G7b raced set_default emits no false audit row', count($GLOBALS['w4_db']['audit']) === $before_audit);

// ──────────────────────────────────────────────────────────────
// Section H — Application-layer strict input validation (ids).
// ──────────────────────────────────────────────────────────────

$f = w4_matrix_fixture();
foreach ([
    [-1, $f['g1'], 'Group One', 'X', 'negative actor_user_id'],
    [$f['owner'], 0, 'Group One', 'X', 'zero group_id'],
] as [$actor, $gid, $exp, $new, $label]) {
    $r1 = call_user_func([$AS, 'rename_group_for_actor'], 10, $actor, $gid, $exp, $new);
    w4_ok("H1 rename strict-rejects $label as invalid_input", w4_code($r1) === 'invalid_input');
}
$r2 = call_user_func([$AS, 'create_group_for_actor'], 10, -1, 'X');
w4_ok('H2 create strict-rejects negative actor_user_id as invalid_input', w4_code($r2) === 'invalid_input');
$r3 = call_user_func([$AS, 'archive_group_for_actor'], 10, $f['owner'], -5);
w4_ok('H3 archive strict-rejects negative group_id as invalid_input', w4_code($r3) === 'invalid_input');
$r4 = call_user_func([$AS, 'set_default_group_for_actor'], 10, $f['owner'], 0);
w4_ok('H4 set_default strict-rejects zero group_id as invalid_input', w4_code($r4) === 'invalid_input');

// ──────────────────────────────────────────────────────────────
// Section I — AJAX: login/nonce/validation/actor-spoof/no-nopriv.
// ──────────────────────────────────────────────────────────────

$f = w4_matrix_fixture();
$GLOBALS['w4_logged_in'] = false;
w4_ajax_post(['event_id' => '10', 'name' => 'X']);
$resp = w4_call_ajax_handler('pge_event_access_create_group_handler');
w4_ok('I1 create AJAX not logged in is denied before any application call', $resp['success'] === false && $resp['data']['reason'] === 'not_logged_in');
$GLOBALS['w4_logged_in'] = true;

$f = w4_matrix_fixture();
$GLOBALS['w4_current_user_id'] = $f['owner'];
$_POST = ['nonce' => 'garbage', 'event_id' => '10', 'name' => 'X'];
$resp = w4_call_ajax_handler('pge_event_access_create_group_handler');
w4_ok('I2 create AJAX bad nonce is denied', $resp['success'] === false && $resp['data']['reason'] === 'invalid_nonce');

foreach ([
    ['event_id' => '', 'name' => 'X'],
    ['event_id' => '10'], // missing name entirely
    ['event_id' => '10', 'name' => ['a']],
] as $bad) {
    $f = w4_matrix_fixture();
    $GLOBALS['w4_current_user_id'] = $f['owner'];
    w4_ajax_post($bad);
    $resp = w4_call_ajax_handler('pge_event_access_create_group_handler');
    w4_ok('I3 create AJAX strict-rejects malformed field ' . json_encode($bad), $resp['success'] === false && $resp['data']['reason'] === 'invalid_input');
}

$f = w4_matrix_fixture();
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'name' => 'Created Via AJAX']);
$resp = w4_call_ajax_handler('pge_event_access_create_group_handler');
w4_ok('I4 create AJAX end-to-end success shape', $resp['success'] === true && $resp['data']['changed'] === true && $resp['data']['group']['name'] === 'Created Via AJAX');

foreach ([
    ['event_id' => '10', 'group_id' => '1'], // missing expected_name/new_name
    ['event_id' => '10', 'expected_name' => 'X', 'new_name' => 'Y'], // missing group_id
] as $bad) {
    $f = w4_matrix_fixture();
    $GLOBALS['w4_current_user_id'] = $f['owner'];
    w4_ajax_post($bad);
    $resp = w4_call_ajax_handler('pge_event_access_rename_group_handler');
    w4_ok('I5 rename AJAX strict-rejects malformed field ' . json_encode($bad), $resp['success'] === false && $resp['data']['reason'] === 'invalid_input');
}

$f = w4_matrix_fixture();
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'group_id' => (string) $f['g1'], 'expected_name' => 'Group One', 'new_name' => 'Renamed Via AJAX']);
$resp = w4_call_ajax_handler('pge_event_access_rename_group_handler');
w4_ok('I6 rename AJAX end-to-end success shape', $resp['success'] === true && $resp['data']['group']['name'] === 'Renamed Via AJAX');

$f = w4_matrix_fixture();
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'group_id' => (string) $f['g1']]);
$resp = w4_call_ajax_handler('pge_event_access_archive_group_handler');
w4_ok('I7 archive AJAX end-to-end success shape', $resp['success'] === true && $resp['data']['group']['status'] === 'archived');

$f = w4_matrix_fixture();
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'group_id' => (string) $f['g1']]);
$resp = w4_call_ajax_handler('pge_event_access_set_default_group_handler');
w4_ok('I8 set_default AJAX end-to-end success shape', $resp['success'] === true && $resp['data']['group']['is_default'] === true);

foreach ([
    'pge_event_access_archive_group_handler' => ['event_id' => '', 'group_id' => '1'],
    'pge_event_access_set_default_group_handler' => ['event_id' => '10', 'group_id' => '0'],
] as $handler => $bad) {
    $f = w4_matrix_fixture();
    $GLOBALS['w4_current_user_id'] = $f['owner'];
    w4_ajax_post($bad);
    $resp = w4_call_ajax_handler($handler);
    w4_ok("I9 $handler strict-rejects malformed field " . json_encode($bad), $resp['success'] === false && $resp['data']['reason'] === 'invalid_input');
}

// Actor spoof: a client-sent actor_user_id/user_id/manager_id/owner_id
// field must be completely ignored; the real session (Manager, denied)
// governs.
$f = w4_matrix_fixture();
$GLOBALS['w4_current_user_id'] = $f['manager'];
$before_mutations = $GLOBALS['w4_mutation_calls'];
w4_ajax_post([
    'event_id' => '10', 'group_id' => (string) $f['g1'],
    'actor_user_id' => (string) $f['admin'], 'user_id' => (string) $f['admin'], 'manager_id' => (string) $f['admin'], 'owner_id' => (string) $f['admin'],
]);
$resp = w4_call_ajax_handler('pge_event_access_archive_group_handler');
w4_ok('J1 (actor spoof) spoofed actor_user_id/user_id/manager_id/owner_id fields are ignored — real Manager session denied', $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');
w4_ok('J2 (actor spoof) Repository mutation was never invoked for the spoofed/denied request', $GLOBALS['w4_mutation_calls'] === $before_mutations);

$ajax_write_source = file_get_contents(PGE_PATH . 'includes/event-access-ajax.php');
$ajax_write_code = w4_strip_comments($ajax_write_source);
w4_ok('J3 AJAX source never reads $_POST[\'actor_user_id\']/[\'user_id\']/[\'manager_id\']/[\'owner_id\']', preg_match('/\$_POST\[\'(actor_user_id|user_id|manager_id|owner_id)\'\]/', $ajax_write_code) === 0);

// No wp_ajax_nopriv_ registration.
w4_ok('J4 no wp_ajax_nopriv_ registered for create/rename/archive/set_default group (source)', preg_match('/wp_ajax_nopriv_pge_event_access_(create|rename|archive|set_default)_group/', $ajax_write_source) === 0);
w4_ok('J5 wp_ajax_ hooks ARE registered for all four actions (real add_action() calls captured at require-time)',
    isset($GLOBALS['w4_registered_hooks']['wp_ajax_pge_event_access_create_group'])
    && isset($GLOBALS['w4_registered_hooks']['wp_ajax_pge_event_access_rename_group'])
    && isset($GLOBALS['w4_registered_hooks']['wp_ajax_pge_event_access_archive_group'])
    && isset($GLOBALS['w4_registered_hooks']['wp_ajax_pge_event_access_set_default_group']));
w4_ok('J6 no wp_ajax_nopriv_ hook was ever actually registered (runtime proof, not just source grep)',
    !isset($GLOBALS['w4_registered_hooks']['wp_ajax_nopriv_pge_event_access_create_group'])
    && !isset($GLOBALS['w4_registered_hooks']['wp_ajax_nopriv_pge_event_access_rename_group'])
    && !isset($GLOBALS['w4_registered_hooks']['wp_ajax_nopriv_pge_event_access_archive_group'])
    && !isset($GLOBALS['w4_registered_hooks']['wp_ajax_nopriv_pge_event_access_set_default_group']));

// ──────────────────────────────────────────────────────────────
// Section K — Error mapping via the real AJAX handler + real public error
// mapper (pge_event_access_public_group_write_error()).
// ──────────────────────────────────────────────────────────────

$f = w4_matrix_fixture();
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'name' => '']);
$resp = w4_call_ajax_handler('pge_event_access_create_group_handler');
w4_ok('K1 invalid_name maps to public reason invalid_name', $resp['success'] === false && $resp['data']['reason'] === 'invalid_name');

$f = w4_matrix_fixture();
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'name' => 'Group One']); // duplicate of fixture g1
$resp = w4_call_ajax_handler('pge_event_access_create_group_handler');
w4_ok('K2 duplicate maps to public reason duplicate, no internal detail', $resp['success'] === false && $resp['data']['reason'] === 'duplicate' && strpos(json_encode($resp), 'wp_pge_') === false);

$f = w4_matrix_fixture();
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'group_id' => '999999']);
$resp = w4_call_ajax_handler('pge_event_access_archive_group_handler');
w4_ok('K3 not_found maps to public reason not_found', $resp['success'] === false && $resp['data']['reason'] === 'not_found');

$f = w4_matrix_fixture();
$archived_for_k4 = w4_group(10, 'archived', 'Archived K4');
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'group_id' => (string) $archived_for_k4]);
$resp = w4_call_ajax_handler('pge_event_access_set_default_group_handler');
w4_ok('K4 invalid_state maps to public reason invalid_state', $resp['success'] === false && $resp['data']['reason'] === 'invalid_state');

$f = w4_matrix_fixture();
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'group_id' => (string) $f['g1'], 'expected_name' => 'Wrong', 'new_name' => 'X']);
$resp = w4_call_ajax_handler('pge_event_access_rename_group_handler');
w4_ok('K5 concurrent_update maps to public reason concurrent_update', $resp['success'] === false && $resp['data']['reason'] === 'concurrent_update');

$f = w4_matrix_fixture();
$GLOBALS['w4_schema_ready'] = false;
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'name' => 'X']);
$resp = w4_call_ajax_handler('pge_event_access_create_group_handler');
w4_ok('K6 write_unavailable maps to public reason write_unavailable', $resp['success'] === false && $resp['data']['reason'] === 'write_unavailable');
$GLOBALS['w4_schema_ready'] = true;

$f = w4_matrix_fixture();
$GLOBALS['w4_force_insert_fail'] = true;
$GLOBALS['w4_current_user_id'] = $f['owner'];
w4_ajax_post(['event_id' => '10', 'name' => 'Will Fail Via AJAX']);
$resp = w4_call_ajax_handler('pge_event_access_create_group_handler');
w4_ok('K7 database_error (forced insert failure) maps to generic public reason server_error, no SQL/table leak', $resp['success'] === false && $resp['data']['reason'] === 'server_error' && strpos(json_encode($resp), 'wp_pge_') === false && strpos(json_encode($resp), 'INSERT') === false);
$GLOBALS['w4_force_insert_fail'] = false;

// ──────────────────────────────────────────────────────────────
// Section L — Audit behavior summary: denial -> no mutation -> no audit.
// (changed->audit and no-op->no-audit already covered per-operation above.)
// ──────────────────────────────────────────────────────────────

$f = w4_matrix_fixture();
$before_audit = count($GLOBALS['w4_db']['audit']);
$before_mutations = $GLOBALS['w4_mutation_calls'];
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f['stranger'], 'Denied Create');
w4_ok('L1 authorization denial (create) never mutates and never audits', w4_code($result) === 'not_authorized' && count($GLOBALS['w4_db']['audit']) === $before_audit && $GLOBALS['w4_mutation_calls'] === $before_mutations);

$f = w4_matrix_fixture();
$before_audit = count($GLOBALS['w4_db']['audit']);
$before_mutations = $GLOBALS['w4_mutation_calls'];
$result = call_user_func([$AS, 'archive_group_for_actor'], 10, $f['stranger'], $f['g1']);
w4_ok('L2 authorization denial (archive) never mutates and never audits, even though a real group exists', w4_code($result) === 'not_authorized' && count($GLOBALS['w4_db']['audit']) === $before_audit && $GLOBALS['w4_mutation_calls'] === $before_mutations);

// ──────────────────────────────────────────────────────────────
// Section M — Source-level guards (Section 31 of the brief).
// ──────────────────────────────────────────────────────────────

$as_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$as_code = w4_strip_comments($as_source);
$w4_section_pos = strpos($as_code, 'function create_group_for_actor');
$w4_section_code = substr($as_code, $w4_section_pos);

w4_ok('M1 group lifecycle write methods never call pge_event_guests_save_map() (comments stripped)', strpos($w4_section_code, 'pge_event_guests_save_map') === false);
w4_ok('M2 group lifecycle write methods never reference PGE_Event_Guest_Read_Projection (comments stripped)', strpos($w4_section_code, 'PGE_Event_Guest_Read_Projection') === false);
w4_ok('M3 group lifecycle write methods never reference guest_phone or Post Meta at all (comments stripped)', strpos($w4_section_code, 'guest_phone') === false && strpos($w4_section_code, 'get_post_meta') === false);
w4_ok('M4 Application Service source never reads $_POST anywhere (comments stripped)', strpos($as_code, '$_POST') === false);
w4_ok('M5 Application Service source never calls current_user_can()/get_current_user_id() anywhere (comments stripped)', preg_match('/current_user_can\s*\(|get_current_user_id\s*\(/', $as_code) === 0);
w4_ok('M6 AJAX handlers never call Repository directly (comments stripped, scoped to the W4 handler functions)',
    (function () use ($ajax_write_code) {
        $start = strpos($ajax_write_code, 'function pge_event_access_create_group_handler');
        $section = substr($ajax_write_code, $start);
        return strpos($section, 'PGE_Event_Access_Repository::') === false;
    })());
w4_ok('M7 AJAX source never references the Authorization class by name (comments stripped)', strpos($ajax_write_code, 'PGE_Event_Access_Authorization') === false);
w4_ok('M8 AJAX handlers never call pge_event_guests_save_map() or reference the projection (comments stripped)', strpos($ajax_write_code, 'pge_event_guests_save_map') === false && strpos($ajax_write_code, 'PGE_Event_Guest_Read_Projection') === false);

$repo_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-repository.php');
$repo_code = w4_strip_comments($repo_source);
w4_ok('M9 (regression sanity) Repository itself still never touches Post Meta or the projection (comments stripped)', strpos($repo_code, 'pge_event_guests_save_map') === false && strpos($repo_code, 'PGE_Event_Guest_Read_Projection') === false);

// ──────────────────────────────────────────────────────────────
// Section N — Cross-event: Owner of event A cannot reach a group id that
// belongs to event B, even by numeric id guess (already exercised per
// operation above — one aggregate confirmation here for all four).
// ──────────────────────────────────────────────────────────────

w4_reset_db();
w4_event(10, 1); w4_event(11, 1);
$gB = w4_group(11, 'active', 'Event B Group');
foreach ([
    ['rename_group_for_actor', [10, 1, $gB, 'Event B Group', 'X']],
    ['archive_group_for_actor', [10, 1, $gB]],
    ['set_default_group_for_actor', [10, 1, $gB]],
] as [$method, $args]) {
    $result = call_user_func_array([$AS, $method], $args);
    w4_ok("N1 $method with a cross-event group id is not_found (fail closed)", w4_code($result) === 'not_found');
}

// ──────────────────────────────────────────────────────────────
// Section O — Event-Context Enumeration Privacy Fix Pass
//
// An independent post-implementation review found that
// PGE_Event_Access_Authorization::resolve_context() checks event
// existence (get_post_type($event_id) !== 'pge_event' -> not_found)
// BEFORE any Owner/Admin/Manager/Viewer decision is made, and the four
// group-lifecycle write methods were returning that raw WP_Error to the
// caller unchanged — so a denied, authenticated actor with a valid nonce
// could distinguish "event exists but I'm not authorized" (not_authorized)
// from "event does not exist" (not_found) via the public AJAX 'reason'
// field: an Event-ID existence oracle. This section proves that gap is
// closed, and that closing it did NOT turn into a blind global
// not_found -> not_authorized rule that would also swallow a proven
// Owner/Admin's legitimate not_found/invalid_state/duplicate/
// concurrent_update results for a bad group_id.
// ──────────────────────────────────────────────────────────────

$w4_nonexistent_event_id = 999999; // never registered via w4_event() in any fixture below

// O1 — Application Service layer: for every denied actor type x every
// operation, the WP_Error code is IDENTICAL whether event_id refers to a
// real event the actor is denied on, or to an event_id that does not
// exist at all. Zero Repository mutations and zero audit rows on both
// sides of every comparison.
$f = w4_matrix_fixture();
$w4_dummy_group_id = 1; // irrelevant: a denied actor never reaches a group_id lookup

$w4_ops = [
    'create' => function ($actor_id, $event_id) use ($AS) {
        return call_user_func([$AS, 'create_group_for_actor'], $event_id, $actor_id, 'O1 Probe Group');
    },
    'rename' => function ($actor_id, $event_id) use ($AS, $w4_dummy_group_id) {
        return call_user_func([$AS, 'rename_group_for_actor'], $event_id, $actor_id, $w4_dummy_group_id, 'Old', 'New');
    },
    'archive' => function ($actor_id, $event_id) use ($AS, $w4_dummy_group_id) {
        return call_user_func([$AS, 'archive_group_for_actor'], $event_id, $actor_id, $w4_dummy_group_id);
    },
    'set_default' => function ($actor_id, $event_id) use ($AS, $w4_dummy_group_id) {
        return call_user_func([$AS, 'set_default_group_for_actor'], $event_id, $actor_id, $w4_dummy_group_id);
    },
];

foreach ($w4_ops as $op => $call) {
    foreach ([
        ['manager', $f['manager']], ['viewer', $f['viewer']],
        ['revoked_manager', $f['revoked_manager']], ['revoked_viewer', $f['revoked_viewer']],
        ['stranger', $f['stranger']],
    ] as [$who, $actor_id]) {
        $before_mut = $GLOBALS['w4_mutation_calls'];
        $before_audit = count($GLOBALS['w4_db']['audit']);
        $code_existing = w4_code($call($actor_id, 10));
        $mut_ok_existing = $GLOBALS['w4_mutation_calls'] === $before_mut && count($GLOBALS['w4_db']['audit']) === $before_audit;

        $before_mut = $GLOBALS['w4_mutation_calls'];
        $before_audit = count($GLOBALS['w4_db']['audit']);
        $code_missing = w4_code($call($actor_id, $w4_nonexistent_event_id));
        $mut_ok_missing = $GLOBALS['w4_mutation_calls'] === $before_mut && count($GLOBALS['w4_db']['audit']) === $before_audit;

        w4_ok("O1 $op $who on an EXISTING event is not_authorized", $code_existing === 'not_authorized');
        w4_ok("O1 $op $who on a NONEXISTENT event is ALSO not_authorized, never a distinct not_found", $code_missing === 'not_authorized');
        w4_ok("O1 $op $who: existing-denied vs nonexistent-event are publicly indistinguishable (identical WP_Error code)", $code_existing === $code_missing);
        w4_ok("O1 $op $who: zero Repository mutations / zero audit rows on both sides", $mut_ok_existing && $mut_ok_missing);
    }
}

// O2 — AJAX layer: full public JSON response (reason AND message, not
// just a status code) is byte-for-byte identical between an existing
// event the actor is denied on and a nonexistent event, for every
// endpoint. This is the actual attacker-observable surface.
$w4_handlers = [
    'create' => ['pge_event_access_create_group_handler', ['name' => 'O2 Probe']],
    'rename' => ['pge_event_access_rename_group_handler', ['group_id' => '1', 'expected_name' => 'Old', 'new_name' => 'New']],
    'archive' => ['pge_event_access_archive_group_handler', ['group_id' => '1']],
    'set_default' => ['pge_event_access_set_default_group_handler', ['group_id' => '1']],
];

foreach ($w4_handlers as $op => $handler_spec) {
    [$handler_fn, $extra_fields] = $handler_spec;
    foreach ([['manager', $f['manager']], ['stranger', $f['stranger']]] as [$who, $actor_id]) {
        $GLOBALS['w4_current_user_id'] = $actor_id;
        w4_ajax_post(array_merge(['event_id' => '10'], $extra_fields));
        $resp_existing = w4_call_ajax_handler($handler_fn);

        $GLOBALS['w4_current_user_id'] = $actor_id;
        w4_ajax_post(array_merge(['event_id' => (string) $w4_nonexistent_event_id], $extra_fields));
        $resp_missing = w4_call_ajax_handler($handler_fn);

        w4_ok("O2 AJAX $op $who: existing-denied response is not_authorized", $resp_existing['success'] === false && $resp_existing['data']['reason'] === 'not_authorized');
        w4_ok("O2 AJAX $op $who: nonexistent-event response is ALSO not_authorized, never not_found", $resp_missing['success'] === false && $resp_missing['data']['reason'] === 'not_authorized');
        w4_ok("O2 AJAX $op $who: full public response (reason AND message) is byte-for-byte identical between existing-denied and nonexistent-event", $resp_existing['data'] === $resp_missing['data']);
    }
}

// O3 — Actor spoofing remains impossible even against a nonexistent
// event_id: the real (denied) session actor is what is evaluated, not any
// client-supplied identity field.
$GLOBALS['w4_current_user_id'] = $f['manager'];
w4_ajax_post([
    'event_id' => (string) $w4_nonexistent_event_id, 'name' => 'O3 Spoofed Create',
    'actor_user_id' => (string) $f['owner'], 'user_id' => (string) $f['owner'],
    'owner_id' => (string) $f['owner'], 'manager_id' => (string) $f['owner'],
]);
$before_mut = $GLOBALS['w4_mutation_calls'];
$resp = w4_call_ajax_handler('pge_event_access_create_group_handler');
w4_ok('O3 actor spoof remains impossible against a nonexistent event_id — the real (Manager) session is still denied as not_authorized', $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');
w4_ok('O3 the spoofed request against a nonexistent event triggered zero Repository mutations', $GLOBALS['w4_mutation_calls'] === $before_mut);

// O4 — Regression sanity: a real Owner/Admin on a real event is completely
// unaffected by the fix (the fix only touches the resolve_context()
// WP_Error branch, which a proven Owner/Admin never enters).
$f4 = w4_matrix_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 10, $f4['owner'], 'O4 Owner Still Works');
w4_ok('O4 Owner on a real event can still CREATE normally after the fix', is_array($result) && $result['changed'] === true && $result['group']['name'] === 'O4 Owner Still Works');
$result2 = call_user_func([$AS, 'archive_group_for_actor'], 10, $f4['admin'], $f4['g1']);
w4_ok('O4 Admin on a real event can still ARCHIVE normally after the fix', is_array($result2) && $result2['changed'] === true);

// O5 — Critical negative proof: the fix is NOT a blind global
// not_found -> not_authorized rule. A proven Owner/Admin on a REAL event
// with a group_id that genuinely does not exist still gets the real,
// honest, POST-authority Repository not_found.
$f5 = w4_matrix_fixture();
foreach ([
    ['rename_group_for_actor', [10, $f5['owner'], 999999, 'Old', 'New']],
    ['archive_group_for_actor', [10, $f5['owner'], 999999]],
    ['set_default_group_for_actor', [10, $f5['owner'], 999999]],
] as [$method, $args]) {
    $result = call_user_func_array([$AS, $method], $args);
    w4_ok("O5 Owner on a REAL event with a genuinely missing group_id: $method still returns real not_found (the fix did NOT become a blind global rule)", w4_code($result) === 'not_found');
}

// O6 — Further negative proof: Owner/Admin post-authority error fidelity
// (invalid_state, duplicate, concurrent_update) is completely preserved.
$f6 = w4_matrix_fixture();
$archived_for_o6 = w4_group(10, 'archived', 'O6 Already Archived');
$result = call_user_func([$AS, 'rename_group_for_actor'], 10, $f6['owner'], $archived_for_o6, 'O6 Already Archived', 'New Name');
w4_ok('O6 Owner: RENAME of an archived group still returns real invalid_state', w4_code($result) === 'invalid_state');

$second_for_o6 = w4_group(10, 'active', 'O6 Second Group');
$result2 = call_user_func([$AS, 'rename_group_for_actor'], 10, $f6['owner'], $f6['g1'], 'Group One', 'O6 Second Group');
w4_ok('O6 Owner: RENAME to a duplicate active name still returns real duplicate', w4_code($result2) === 'duplicate');

$GLOBALS['w4_force_update_miss'] = true;
$result3 = call_user_func([$AS, 'archive_group_for_actor'], 10, $f6['owner'], $f6['g1']);
w4_ok('O6 Owner: a raced ARCHIVE update-miss still returns real concurrent_update', w4_code($result3) === 'concurrent_update');

// O7 — Source-level lock-in: all four public methods route through the
// single shared resolve_group_structure_authority() gate rather than each
// re-implementing (and potentially re-breaking) the resolve_context()
// WP_Error handling independently.
$app_source_full = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$app_code_only = w4_strip_comments($app_source_full);
w4_ok('O7 the shared resolve_group_structure_authority() gate exists', strpos($app_code_only, 'function resolve_group_structure_authority') !== false);
foreach (['create_group_for_actor', 'rename_group_for_actor', 'archive_group_for_actor', 'set_default_group_for_actor'] as $method) {
    $pos = strpos($app_code_only, "function $method");
    $next_pos = strpos($app_code_only, 'function ', $pos + 10);
    $body = $next_pos !== false ? substr($app_code_only, $pos, $next_pos - $pos) : substr($app_code_only, $pos);
    w4_ok("O7 $method uses the shared resolve_group_structure_authority() gate directly (no independent resolve_context()/can_manage_event_structure() call of its own)",
        strpos($body, 'resolve_group_structure_authority') !== false
        && strpos($body, 'PGE_Event_Access_Authorization::resolve_context') === false
        && strpos($body, 'PGE_Event_Access_Authorization::can_manage_event_structure') === false);
}

// ──────────────────────────────────────────────────────────────
// Section P — PRE-AUTHORITY phase-based collapse follow-up (Phase
// H1C-EC1 follow-up security review).
//
// Section O above proved and closed the 'not_found' half of the
// resolve_context() Event-ID Enumeration Oracle. A follow-up review
// found the shared gate (resolve_event_actor_context(), introduced by
// Section O's fix and reused by W1/W2/W3/W4) was collapsing ONLY the
// 'not_found' WP_Error code to not_authorized, while every OTHER
// resolve_context() WP_Error -- most notably 'database_error', which
// PGE_Event_Access_Authorization_Context::resolve() itself returns when
// Repository's get_membership_for_user() detects more than one active
// membership row for the same (event_id, actor_user_id) and fails
// closed -- was passed through unchanged. That database_error is still
// raised strictly BEFORE any Owner/Admin/Manager/Viewer capability
// decision is reached, i.e. it is PRE-AUTHORITY exactly like not_found
// is, so per this project's security invariant (collapse is decided by
// PHASE -- pre- vs. post-authority -- not by which specific error code
// resolve_context() happened to return) it must ALSO collapse to
// not_authorized. This section proves Case A (nonexistent event) and
// Case B (a real, existing event where this same actor's own membership
// row is ambiguous/duplicated) are publicly indistinguishable.
// ──────────────────────────────────────────────────────────────

function w4_ambiguous_membership_fixture()
{
    w4_reset_db();
    w4_event(20, 1); // owner = user 1, a fresh event distinct from Section O's event 10
    $g1 = w4_group(20, 'active', 'P Group One');
    // Actor 8 is deliberately given TWO active membership rows for the
    // SAME (event_id=20, user_id=8) pair. Repository's
    // get_membership_for_user() issues "... LIMIT 2" and fails closed
    // with database_error the instant it sees more than one row -- this
    // happens inside PGE_Event_Access_Authorization_Context::resolve(),
    // strictly before any role/capability decision, so it is a
    // PRE-AUTHORITY failure exactly like the event-does-not-exist case.
    w4_membership(20, 8, 'manager');
    w4_membership(20, 8, 'viewer');
    return ['g1' => $g1, 'owner' => 1, 'ambiguous_actor' => 8];
}

$w4_nonexistent_event_id_p = 888888; // never registered in this fixture

// P1 — Application Service layer, all four operations: the WP_Error code
// is IDENTICAL whether event_id refers to the REAL event 20 (where this
// actor's own membership row is ambiguous) or to an event_id that does
// not exist at all. Zero Repository mutations / zero audit rows on both
// sides of every comparison.
$fp = w4_ambiguous_membership_fixture();
foreach ($w4_ops as $op => $call) {
    $before_mut = $GLOBALS['w4_mutation_calls'];
    $before_audit = count($GLOBALS['w4_db']['audit']);
    $code_existing = w4_code($call($fp['ambiguous_actor'], 20));
    $mut_ok_existing = $GLOBALS['w4_mutation_calls'] === $before_mut && count($GLOBALS['w4_db']['audit']) === $before_audit;

    $before_mut = $GLOBALS['w4_mutation_calls'];
    $before_audit = count($GLOBALS['w4_db']['audit']);
    $code_missing = w4_code($call($fp['ambiguous_actor'], $w4_nonexistent_event_id_p));
    $mut_ok_missing = $GLOBALS['w4_mutation_calls'] === $before_mut && count($GLOBALS['w4_db']['audit']) === $before_audit;

    w4_ok("P1 $op ambiguous-membership actor on the REAL event 20 is not_authorized, NOT a distinct database_error", $code_existing === 'not_authorized');
    w4_ok("P1 $op ambiguous-membership actor on a NONEXISTENT event is ALSO not_authorized", $code_missing === 'not_authorized');
    w4_ok("P1 $op: existing (ambiguous-membership) vs nonexistent-event are publicly indistinguishable (identical WP_Error code)", $code_existing === $code_missing);
    w4_ok("P1 $op: zero Repository mutations / zero audit rows on both sides", $mut_ok_existing && $mut_ok_missing);
}

// P2 — AJAX layer: full public JSON response (reason AND message) is
// byte-for-byte identical between the real ambiguous-membership event
// and a nonexistent event, for every endpoint.
$fp2 = w4_ambiguous_membership_fixture();
foreach ($w4_handlers as $op => $handler_spec) {
    [$handler_fn, $extra_fields] = $handler_spec;
    $GLOBALS['w4_current_user_id'] = $fp2['ambiguous_actor'];
    w4_ajax_post(array_merge(['event_id' => '20'], $extra_fields));
    $resp_existing = w4_call_ajax_handler($handler_fn);

    $GLOBALS['w4_current_user_id'] = $fp2['ambiguous_actor'];
    w4_ajax_post(array_merge(['event_id' => (string) $w4_nonexistent_event_id_p], $extra_fields));
    $resp_missing = w4_call_ajax_handler($handler_fn);

    w4_ok("P2 AJAX $op ambiguous-membership actor: real-event response is not_authorized, never database_error/server_error", $resp_existing['success'] === false && $resp_existing['data']['reason'] === 'not_authorized');
    w4_ok("P2 AJAX $op ambiguous-membership actor: nonexistent-event response is ALSO not_authorized", $resp_missing['success'] === false && $resp_missing['data']['reason'] === 'not_authorized');
    w4_ok("P2 AJAX $op: full public response (reason AND message) is byte-for-byte identical between the real ambiguous-membership event and a nonexistent event", $resp_existing['data'] === $resp_missing['data']);
}

// P3 — Actor spoofing remains impossible for this scenario too: the real
// session is the ambiguous-membership actor, spoofed fields are ignored.
$fp3 = w4_ambiguous_membership_fixture();
$GLOBALS['w4_current_user_id'] = $fp3['ambiguous_actor'];
w4_ajax_post([
    'event_id' => '20', 'name' => 'P3 Spoofed Create',
    'actor_user_id' => (string) $fp3['owner'], 'user_id' => (string) $fp3['owner'],
    'owner_id' => (string) $fp3['owner'], 'manager_id' => (string) $fp3['owner'],
]);
$before_mut = $GLOBALS['w4_mutation_calls'];
$resp = w4_call_ajax_handler('pge_event_access_create_group_handler');
w4_ok('P3 actor spoof remains impossible for the ambiguous-membership actor — still not_authorized, not the owner it claims to be', $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');
w4_ok('P3 the spoofed request triggered zero Repository mutations', $GLOBALS['w4_mutation_calls'] === $before_mut);

// P4 — Regression sanity: a different, unrelated actor (the real Owner)
// on the SAME event 20 is completely unaffected by actor 8's corrupted
// membership row — the fix is actor-scoped via resolve_context(), not
// event-scoped, so one actor's data problem cannot suppress another
// actor's legitimate authority.
$fp4 = w4_ambiguous_membership_fixture();
$result = call_user_func([$AS, 'create_group_for_actor'], 20, $fp4['owner'], 'P4 Owner Still Works');
w4_ok('P4 the real Owner of event 20 can still CREATE normally — unaffected by actor 8\'s ambiguous membership row', is_array($result) && $result['changed'] === true && $result['group']['name'] === 'P4 Owner Still Works');

// P5 — Post-authority error fidelity is still completely preserved (not
// a blind global collapse): repeats O5's negative proof on event 20 to
// confirm the widened collapse did not spill into POST-authority
// Repository errors.
$fp5 = w4_ambiguous_membership_fixture();
$result5 = call_user_func([$AS, 'rename_group_for_actor'], 20, $fp5['owner'], 999999, 'Old', 'New');
w4_ok('P5 Owner on REAL event 20 with a genuinely missing group_id still returns real not_found (widened PRE-AUTHORITY collapse did not spill into POST-authority errors)', w4_code($result5) === 'not_found');

// P6 — Source-level: resolve_event_actor_context() must not special-case
// on a specific WP_Error code (locks in the phase-based contract so a
// future edit can't silently narrow it back to a single code again).
$app_source_full_p = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$pos_p = strpos($app_source_full_p, 'function resolve_event_actor_context');
$next_pos_p = strpos($app_source_full_p, 'function ', $pos_p + 10);
$body_p = $next_pos_p !== false ? substr($app_source_full_p, $pos_p, $next_pos_p - $pos_p) : substr($app_source_full_p, $pos_p);
w4_ok(
    'P6 resolve_event_actor_context() does not special-case on get_error_code() (every WP_Error collapses, not just one code)',
    strpos($body_p, 'get_error_code') === false
);

echo "\nH1C-W4: {$passed}/" . ($passed + $failed) . " passed\n";
exit($failed === 0 ? 0 : 1);
