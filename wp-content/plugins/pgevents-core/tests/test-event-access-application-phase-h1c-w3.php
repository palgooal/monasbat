<?php
/**
 * Phase H1C-W3 — Owner/Admin Group-Access Lifecycle Write Wiring.
 *
 * Independent suite (deliberately NOT added inside the H1C-W2 test file —
 * Section 19 of the H1C-W3 brief). Exercises the real
 * PGE_Event_Access_Application_Service write methods
 * (grant_group_access_for_actor / revoke_group_access_for_actor), the real
 * PGE_Event_Access_Authorization[_Context] decision layer (unmodified —
 * can_manage_group_access() is a flat Owner/Admin-only gate with no
 * group-scoping parameter), the real PGE_Event_Access_Repository
 * transactional mutation methods (grant_group_access() / revoke_group_access()
 * — unmodified), and the real AJAX handlers in event-access-ajax.php
 * (pge_event_access_grant_group_access_handler /
 * pge_event_access_revoke_group_access_handler) — against a small in-memory
 * fake $wpdb (H1B relational tables only) and real WordPress function shims.
 * No real database, no real network, no UI.
 *
 * Because this domain's Repository INSERT goes through a raw prepared SQL
 * string via $wpdb->query() (mutation_query()) while its DELETE goes through
 * a genuine $wpdb->delete($table, $where, $formats) METHOD call
 * (delete_rows()), the fake $wpdb below implements BOTH mechanisms — unlike
 * H1C-W2's fake $wpdb, which only ever needed the query()-dispatch path for
 * its inserts/updates and delete() for its one delete path on a different
 * table.
 *
 * Run: php tests/test-event-access-application-phase-h1c-w3.php
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
// Hook registration — records real hook names so W3 can prove no
// wp_ajax_nopriv_ registration exists, without executing WordPress itself.
// ──────────────────────────────────────────────────────────────

$GLOBALS['w3_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['w3_registered_hooks'][$hook] = true; }
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

$GLOBALS['w3_current_user_id'] = 0;
$GLOBALS['w3_logged_in'] = true;
$GLOBALS['w3_admins'] = [];
function get_current_user_id() { return $GLOBALS['w3_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['w3_logged_in']; }
// Authorization Context calls user_can($actor_user_id, 'administrator') —
// deliberately NOT current_user_can() (unchanged contract from H1C-A2/W1/W2).
function user_can($user_id, $capability)
{
    if ($capability !== 'administrator') return false;
    return in_array((int) $user_id, $GLOBALS['w3_admins'], true);
}

$GLOBALS['w3_posts'] = [];
function get_post_type($post_id)
{
    return $GLOBALS['w3_posts'][(int) $post_id]['post_type'] ?? false;
}
function get_post_field($field, $post_id)
{
    if (!isset($GLOBALS['w3_posts'][(int) $post_id][$field])) return '';
    return (string) $GLOBALS['w3_posts'][(int) $post_id][$field];
}

// require_user($user_id) inside grant_group_access() calls get_userdata().
// Every membership's user_id is a "known" WP user by default; a test can
// mark a specific user id as missing via $GLOBALS['w3_missing_users'] to
// exercise the "membership's underlying WP user no longer exists" ->
// not_found() path that is unique to grant (revoke never calls require_user()
// at all).
$GLOBALS['w3_missing_users'] = [];
function get_userdata($user_id)
{
    if (in_array((int) $user_id, $GLOBALS['w3_missing_users'], true)) return false;
    $obj = new stdClass();
    $obj->ID = (int) $user_id;
    return $obj;
}

// ──────────────────────────────────────────────────────────────
// Fake schema — always "ready" unless a test flips it.
// ──────────────────────────────────────────────────────────────

class PGE_Event_Access_Schema
{
    public static function is_ready() { return $GLOBALS['w3_schema_ready']; }
    public static function maybe_upgrade() { throw new RuntimeException('maybe_upgrade() must never be called by a W3 write path.'); }
}

// ──────────────────────────────────────────────────────────────
// In-memory fixture store + SQL dispatcher (H1B relational tables only).
// ──────────────────────────────────────────────────────────────

function w3_tables()
{
    return [
        'groups' => 'wp_pge_event_invitation_groups',
        'memberships' => 'wp_pge_event_host_memberships',
        'access' => 'wp_pge_event_host_group_access',
        'assignments' => 'wp_pge_invitation_group_assignments',
        'audit' => 'wp_pge_event_access_audit_log',
    ];
}

function w3_reset_db()
{
    $GLOBALS['w3_db'] = [
        'groups' => [],
        'memberships' => [],
        'access' => [],
        'audit' => [],
        'next_id' => 1,
    ];
    $GLOBALS['w3_posts'] = [];
    $GLOBALS['w3_admins'] = [];
    $GLOBALS['w3_schema_ready'] = true;
    $GLOBALS['w3_missing_users'] = [];
    $GLOBALS['w3_current_user_id'] = 0;
    $GLOBALS['w3_logged_in'] = true;
    $GLOBALS['w3_mutation_calls'] = 0;
    $GLOBALS['w3_query_log'] = [];
    $GLOBALS['w3_force_insert_fail'] = false;
    $GLOBALS['w3_force_delete_miss'] = false;
}

function w3_next_id() { return $GLOBALS['w3_db']['next_id']++; }

function w3_event($event_id, $author_id)
{
    $GLOBALS['w3_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => $author_id];
}

function w3_group($event_id, $status = 'active', $name = 'Group')
{
    $id = w3_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w3_db']['groups'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'name' => $name,
        'name_key' => $status === 'active' ? strtolower($name) . '-' . $id : null,
        'status' => $status, 'default_slot' => null, 'created_by_user_id' => 1,
        'created_at' => $now, 'updated_at' => $now,
        'archived_at' => $status === 'archived' ? $now : null,
    ];
    return $id;
}

function w3_membership($event_id, $user_id, $role, $status = 'active')
{
    $id = w3_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w3_db']['memberships'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'user_id' => $user_id, 'role' => $role, 'status' => $status,
        'created_by_user_id' => 1, 'activated_at' => $now, 'revoked_at' => $status === 'revoked' ? $now : null,
        'created_at' => $now, 'updated_at' => $now,
    ];
    return $id;
}

function w3_access($event_id, $membership_id, $group_id)
{
    $id = w3_next_id();
    $GLOBALS['w3_db']['access'][] = [
        'id' => $id, 'event_id' => $event_id, 'membership_id' => $membership_id, 'group_id' => $group_id,
        'granted_by_user_id' => 1, 'created_at' => '2026-01-01 00:00:00',
    ];
    return $id;
}

function w3_dispatch_select($sql)
{
    $t = w3_tables();
    $sql = trim($sql);

    // lock_groups()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['groups'], '/') . ' WHERE event_id = (\d+) ORDER BY id FOR UPDATE$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $rows = array_values(array_filter($GLOBALS['w3_db']['groups'], function ($r) use ($event_id) { return $r['event_id'] === $event_id; }));
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    // lock_memberships()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) ORDER BY id FOR UPDATE$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $rows = array_values(array_filter($GLOBALS['w3_db']['memberships'], function ($r) use ($event_id) { return $r['event_id'] === $event_id; }));
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    // get_membership_for_user() — used by Authorization Context::resolve()
    // for every non-Owner/non-Admin actor.
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND user_id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $user_id = (int) $m[2];
        return array_values(array_filter($GLOBALS['w3_db']['memberships'], function ($r) use ($event_id, $user_id) {
            return $r['event_id'] === $event_id && $r['user_id'] === $user_id;
        }));
    }

    // get_membership_scoped() (used internally by list_group_ids_for_membership())
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $id = (int) $m[2];
        return array_values(array_filter($GLOBALS['w3_db']['memberships'], function ($r) use ($event_id, $id) {
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
        foreach ($GLOBALS['w3_db']['access'] as $row) {
            if ($row['event_id'] !== $event_id || $row['membership_id'] !== $membership_id) continue;
            $group = $GLOBALS['w3_db']['groups'][$row['group_id']] ?? null;
            $out[] = ['event_id' => $row['event_id'], 'group_id' => $row['group_id'], 'related_event_id' => $group['event_id'] ?? null];
        }
        return $out;
    }

    // lock_access_relation()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['access'], '/') . ' WHERE event_id = (\d+) AND membership_id = (\d+) AND group_id = (\d+) ORDER BY id FOR UPDATE$/', $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2]; $group_id = (int) $m[3];
        $rows = array_values(array_filter($GLOBALS['w3_db']['access'], function ($r) use ($event_id, $membership_id, $group_id) {
            return $r['event_id'] === $event_id && $r['membership_id'] === $membership_id && $r['group_id'] === $group_id;
        }));
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    // read_access_relation_inside_transaction()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['access'], '/') . ' WHERE event_id = (\d+) AND membership_id = (\d+) AND group_id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2]; $group_id = (int) $m[3];
        $rows = array_values(array_filter($GLOBALS['w3_db']['access'], function ($r) use ($event_id, $membership_id, $group_id) {
            return $r['event_id'] === $event_id && $r['membership_id'] === $membership_id && $r['group_id'] === $group_id;
        }));
        return array_slice($rows, 0, 2);
    }

    return null;
}

function w3_dispatch_mutation($sql)
{
    $t = w3_tables();
    $sql = trim($sql);

    if ($sql === 'START TRANSACTION') {
        $GLOBALS['w3_snapshot'] = [$GLOBALS['w3_db']['access'], $GLOBALS['w3_db']['audit'], $GLOBALS['w3_db']['next_id']];
        return ['handled' => true, 'result' => 0];
    }
    if ($sql === 'COMMIT') {
        $GLOBALS['w3_snapshot'] = null;
        return ['handled' => true, 'result' => 0];
    }
    if ($sql === 'ROLLBACK') {
        if ($GLOBALS['w3_snapshot'] !== null) {
            [$GLOBALS['w3_db']['access'], $GLOBALS['w3_db']['audit'], $GLOBALS['w3_db']['next_id']] = $GLOBALS['w3_snapshot'];
        }
        $GLOBALS['w3_snapshot'] = null;
        return ['handled' => true, 'result' => 0];
    }

    // grant_group_access()'s raw-SQL INSERT (via mutation_query() / $wpdb->query()
    // — NOT $wpdb->insert()). Optionally forced to "fail" (0 rows) via
    // $GLOBALS['w3_force_insert_fail'] to exercise the "failed mutation -> no
    // audit" path deterministically.
    $insertAccess = '/^INSERT INTO ' . preg_quote($t['access'], '/')
        . ' \(event_id, membership_id, group_id, granted_by_user_id, created_at\) VALUES \((\d+), (\d+), (\d+), (\d+), \'([^\']*)\'\)$/';
    if (preg_match($insertAccess, $sql, $m)) {
        if ($GLOBALS['w3_force_insert_fail']) {
            return ['handled' => true, 'result' => 0];
        }
        $GLOBALS['w3_mutation_calls']++;
        $id = w3_next_id();
        $GLOBALS['w3_db']['access'][] = [
            'id' => $id, 'event_id' => (int) $m[1], 'membership_id' => (int) $m[2], 'group_id' => (int) $m[3],
            'granted_by_user_id' => (int) $m[4], 'created_at' => $m[5],
        ];
        return ['handled' => true, 'result' => 1, 'insert_id' => $id];
    }

    $insertAudit = '/^INSERT INTO ' . preg_quote($t['audit'], '/')
        . ' \(event_id, actor_user_id, action, entity_type, entity_id, metadata, created_at\) VALUES \((\d+), (\d+), \'([^\']*)\', \'([^\']*)\', (\d+), \'([^\']*)\', \'([^\']*)\'\)$/';
    if (preg_match($insertAudit, $sql, $m)) {
        $GLOBALS['w3_mutation_calls']++;
        $GLOBALS['w3_db']['audit'][] = [
            'event_id' => (int) $m[1], 'actor_user_id' => (int) $m[2], 'action' => $m[3],
            // $m[6] came through PGE_W3_Fake_WPDB::prepare()'s %s handling,
            // which applies addslashes() (escaping the JSON string's own
            // double quotes as \") before wrapping it in single quotes for
            // the SQL literal — so the captured text must be stripslashes()'d
            // back before json_decode() can parse it. (A fake-wpdb-authoring
            // bug this exact fix pass had to catch: the escaped text alone
            // is not valid JSON and json_decode() of it silently returns
            // null, not a parse error.)
            'entity_type' => $m[4], 'entity_id' => (int) $m[5], 'metadata' => json_decode(stripslashes($m[6]), true), 'created_at' => $m[7],
        ];
        return ['handled' => true, 'result' => 1];
    }

    return ['handled' => false];
}

class PGE_W3_Fake_WPDB
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
        $GLOBALS['w3_query_log'][] = $sql;
        $rows = w3_dispatch_select($sql);
        if ($rows === null) {
            $this->last_error = 'w3-fake-wpdb: unrecognized SELECT: ' . $sql;
            return null;
        }
        $this->last_error = '';
        return $rows;
    }

    public function get_var($sql)
    {
        $GLOBALS['w3_query_log'][] = $sql;
        $this->last_error = 'w3-fake-wpdb: unrecognized scalar query: ' . $sql;
        return null;
    }

    public function query($sql)
    {
        $GLOBALS['w3_query_log'][] = $sql;
        $outcome = w3_dispatch_mutation($sql);
        if (!$outcome['handled']) {
            $this->last_error = 'w3-fake-wpdb: unexpected mutating query() call: ' . $sql;
            return false;
        }
        $this->last_error = '';
        if (array_key_exists('insert_id', $outcome)) $this->insert_id = $outcome['insert_id'];
        return $outcome['result'];
    }

    // revoke_group_access() deletes via a genuine $wpdb->delete($table, $where,
    // $formats) METHOD call (delete_rows()) — not a raw SQL string. Optionally
    // forced to "miss" via $GLOBALS['w3_force_delete_miss'] to exercise the
    // "relation vanished between lock and delete -> concurrent_update, no
    // audit" path deterministically (models a real race without needing real
    // concurrency).
    public function delete($table, $where, $formats)
    {
        $t = w3_tables();
        $GLOBALS['w3_query_log'][] = 'DELETE ' . $table;
        if ($table !== $t['access']) {
            $this->last_error = 'w3-fake-wpdb: unexpected delete() call on ' . $table;
            return false;
        }
        $this->last_error = '';
        if ($GLOBALS['w3_force_delete_miss']) {
            $GLOBALS['w3_force_delete_miss'] = false;
            return 0;
        }
        foreach ($GLOBALS['w3_db']['access'] as $id => $row) {
            if ($row['event_id'] === $where['event_id'] && $row['membership_id'] === $where['membership_id']
                && $row['group_id'] === $where['group_id']) {
                $GLOBALS['w3_mutation_calls']++;
                unset($GLOBALS['w3_db']['access'][$id]);
                return 1;
            }
        }
        return 0;
    }

    public function insert($table, $data, $formats = null)
    {
        $this->last_error = 'w3-fake-wpdb: unexpected insert() call';
        return false;
    }
}

w3_reset_db();
global $wpdb;
$wpdb = new PGE_W3_Fake_WPDB();

// ──────────────────────────────────────────────────────────────
// AJAX/JSON stubs
// ──────────────────────────────────────────────────────────────

class Test_Wp_Die_Exception extends \Exception {}

$GLOBALS['w3_json_response'] = null;
function wp_send_json_success($data = null)
{
    $GLOBALS['w3_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null)
{
    $GLOBALS['w3_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = [])
{
    throw new Test_Wp_Die_Exception('wp_die');
}

function w3_call_ajax_handler(callable $handler): array
{
    $GLOBALS['w3_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // expected
    }
    $raw = $GLOBALS['w3_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function w3_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_create_nonce($action) { return w3_nonce($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals(w3_nonce($action), (string) $nonce) ? 1 : false; }

require_once PGE_PATH . 'includes/event-guests.php';
require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';
require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';
require_once PGE_PATH . 'includes/class-pge-event-access-application-service.php';
require_once PGE_PATH . 'includes/event-access-ajax.php';

$passed = 0;
$failed = 0;
function w3_ok($label, $condition) { global $passed, $failed; if ($condition) { $passed++; echo "PASS: $label\n"; } else { $failed++; echo "FAIL: $label\n"; } }
function w3_code($value) { return $value instanceof WP_Error ? $value->get_error_code() : null; }

function w3_strip_comments($php_source)
{
    $out = '';
    foreach (token_get_all('<?php ' . $php_source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
}

function w3_ajax_post($fields)
{
    $_POST = array_merge(['nonce' => w3_nonce('pge_event_manage_nonce')], $fields);
}

$AS = 'PGE_Event_Access_Application_Service';
$Auth = 'PGE_Event_Access_Authorization';
$Repo = 'PGE_Event_Access_Repository';

// ──────────────────────────────────────────────────────────────
// Section C — Authorization matrix (Application Service layer), for both
// grant and revoke, across Owner / Admin / Manager (with access to the
// target group) / Manager (no access) / Viewer / revoked Manager / revoked
// Viewer / stranger. can_manage_group_access() is a flat Owner/Admin-only
// gate with NO group-scoping parameter, so a Manager's own granted-group
// scope must have zero bearing on the outcome — every non-Owner/Admin actor
// is denied identically. Every denial is also proven to issue ZERO
// Repository mutation queries.
// ──────────────────────────────────────────────────────────────

function w3_matrix_fixture()
{
    w3_reset_db();
    w3_event(10, 1); // author/owner = user 1
    $g1 = w3_group(10);
    $g2 = w3_group(10);
    $GLOBALS['w3_admins'] = [2];
    $mgr = w3_membership(10, 3, 'manager');
    w3_access(10, $mgr, $g1); // manager IS granted g1 — must still be denied (not scope-dependent)
    $mgr_unauth = w3_membership(10, 4, 'manager'); // manager, no access to g1/g2
    $viewer = w3_membership(10, 5, 'viewer');
    $revoked_mgr = w3_membership(10, 6, 'manager', 'revoked');
    $revoked_viewer = w3_membership(10, 7, 'viewer', 'revoked');
    // A separate, always-inactive target membership+group pair for the
    // matrix loop's grant/revoke calls to operate on, so the authorization
    // outcome under test is never entangled with the target's own state.
    $target_mgr = w3_membership(10, 50, 'manager');
    return [
        'g1' => $g1, 'g2' => $g2, 'owner' => 1, 'admin' => 2, 'manager' => 3, 'manager_unauth' => 4,
        'viewer' => 5, 'revoked_manager' => 6, 'revoked_viewer' => 7, 'stranger' => 99, 'target_mgr' => $target_mgr,
    ];
}

foreach (['grant', 'revoke'] as $op) {
    foreach ([
        ['owner', true], ['admin', true], ['manager', false], ['manager_unauth', false],
        ['viewer', false], ['revoked_manager', false], ['revoked_viewer', false], ['stranger', false],
    ] as [$who, $expect_allowed]) {
        $f = w3_matrix_fixture();
        if ($op === 'revoke') w3_access(10, $f['target_mgr'], $f['g1']); // seed a real grant so revoke has something to act on
        $before_mutations = $GLOBALS['w3_mutation_calls'];
        $method = $op === 'grant' ? 'grant_group_access_for_actor' : 'revoke_group_access_for_actor';
        $result = call_user_func([$AS, $method], 10, $f[$who], $f['target_mgr'], $f['g1']);
        $allowed = !($result instanceof WP_Error);
        w3_ok("C-$op $who allowed=" . ($expect_allowed ? 'true' : 'false'), $allowed === $expect_allowed
            && ($expect_allowed || w3_code($result) === 'not_authorized')
            && ($expect_allowed || $GLOBALS['w3_mutation_calls'] === $before_mutations));
    }
}

// A Manager granted the EXACT target group is still denied (owner/admin-only,
// not scope-dependent) — the specific case the docblock calls out.
$f = w3_matrix_fixture();
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $f['manager'], $f['target_mgr'], $f['g1']);
w3_ok('C-manager-granted-target-group still denied grant (owner/admin-only, not scope-dependent)', w3_code($result) === 'not_authorized');

// ──────────────────────────────────────────────────────────────
// Section D — Repository contract exercised via the real Application Service
// (owner acting throughout, so every branch below is purely about the
// Repository's OWN grant_group_access()/revoke_group_access() semantics,
// never about authorization).
// ──────────────────────────────────────────────────────────────

// D1/D2 — grant to a missing membership / missing group is not_found.
$f = w3_matrix_fixture();
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $f['owner'], 999999, $f['g1']);
w3_ok('D1 grant to a missing membership id is not_found', w3_code($result) === 'not_found');

$f = w3_matrix_fixture();
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $f['owner'], $f['target_mgr'], 999999);
w3_ok('D2 grant to a missing group id is not_found', w3_code($result) === 'not_found');

// D3/D4 — cross-event membership id / cross-event group id (real rows, but
// belonging to a DIFFERENT event) are simply absent from the locked set —
// not_found, not a distinguishable cross_event code (matches H1C-W2's
// documented lesson for this Repository's lock-then-find pattern).
w3_reset_db();
w3_event(10, 1); w3_event(11, 1);
$gA = w3_group(10);
$mgrB = w3_membership(11, 3, 'manager'); // belongs to event 11
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, 1, $mgrB, $gA);
w3_ok('D3 grant with a cross-event membership id is not_found', w3_code($result) === 'not_found');

w3_reset_db();
w3_event(10, 1); w3_event(11, 1);
$mgrA = w3_membership(10, 3, 'manager');
$gB = w3_group(11); // belongs to event 11
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, 1, $mgrA, $gB);
w3_ok('D4 grant with a cross-event group id is not_found', w3_code($result) === 'not_found');

// D5 — grant to an archived group is invalid_state (unique to grant).
$f = w3_matrix_fixture();
$archived = w3_group(10, 'archived');
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $f['owner'], $f['target_mgr'], $archived);
w3_ok('D5 grant to an archived group is invalid_state', w3_code($result) === 'invalid_state');

// D6 — grant against a revoked membership is invalid_state (unique to grant).
$f = w3_matrix_fixture();
$revoked_target = w3_membership(10, 51, 'manager', 'revoked');
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $f['owner'], $revoked_target, $f['g1']);
w3_ok('D6 grant against a revoked membership is invalid_state', w3_code($result) === 'invalid_state');

// D7 — grant when the membership's underlying WP user no longer exists is
// not_found (unique to grant — revoke never calls require_user() at all).
$f = w3_matrix_fixture();
$GLOBALS['w3_missing_users'] = [3];
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $f['owner'], $f['manager'], $f['g2']);
w3_ok('D7 grant when the membership\'s WP user no longer exists is not_found', w3_code($result) === 'not_found');
$GLOBALS['w3_missing_users'] = [];

// D8 — grant success: shape + audit correctness.
$f = w3_matrix_fixture();
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $f['owner'], $f['target_mgr'], $f['g1']);
w3_ok('D8 grant success shape', $result === ['ok' => true, 'changed' => true, 'membership_id' => $f['target_mgr'], 'group_id' => $f['g1'], 'has_access' => true]);
w3_ok('D8b grant emits exactly one audit row with correct action/entity_type/metadata', count($GLOBALS['w3_db']['audit']) === 1
    && $GLOBALS['w3_db']['audit'][0]['action'] === 'group_access_granted'
    && $GLOBALS['w3_db']['audit'][0]['entity_type'] === 'group_access'
    && $GLOBALS['w3_db']['audit'][0]['actor_user_id'] === $f['owner']
    && $GLOBALS['w3_db']['audit'][0]['metadata']['membership_id'] === $f['target_mgr']
    && $GLOBALS['w3_db']['audit'][0]['metadata']['group_id'] === $f['g1']);

// D9 — grant idempotency: duplicate grant is a no-op, no new audit.
$f = w3_matrix_fixture();
w3_access(10, $f['target_mgr'], $f['g1']);
$before_audit = count($GLOBALS['w3_db']['audit']);
$before_mutations = $GLOBALS['w3_mutation_calls'];
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $f['owner'], $f['target_mgr'], $f['g1']);
w3_ok('D9 duplicate grant is a no-op (changed=false, has_access=true)', $result === ['ok' => true, 'changed' => false, 'membership_id' => $f['target_mgr'], 'group_id' => $f['g1'], 'has_access' => true]);
w3_ok('D9b duplicate grant emits no new audit row', count($GLOBALS['w3_db']['audit']) === $before_audit);
w3_ok('D9c duplicate grant issues no INSERT mutation', $GLOBALS['w3_mutation_calls'] === $before_mutations);

// D10/D11 — revoke to a missing membership / missing group is not_found.
$f = w3_matrix_fixture();
$result = call_user_func([$AS, 'revoke_group_access_for_actor'], 10, $f['owner'], 999999, $f['g1']);
w3_ok('D10 revoke against a missing membership id is not_found', w3_code($result) === 'not_found');

$f = w3_matrix_fixture();
$result = call_user_func([$AS, 'revoke_group_access_for_actor'], 10, $f['owner'], $f['target_mgr'], 999999);
w3_ok('D11 revoke against a missing group id is not_found', w3_code($result) === 'not_found');

// D12 — revoke of an ARCHIVED group's active access relation still succeeds
// (revoke_group_access() deliberately has NO status !== 'active' check,
// unlike grant — this is the key asymmetry documented in the discovery).
$f = w3_matrix_fixture();
$archived2 = w3_group(10, 'archived');
w3_access(10, $f['target_mgr'], $archived2);
$result = call_user_func([$AS, 'revoke_group_access_for_actor'], 10, $f['owner'], $f['target_mgr'], $archived2);
w3_ok('D12 revoke of an archived group\'s existing access relation still succeeds (no status check on revoke)', is_array($result) && $result['changed'] === true && $result['has_access'] === false);

// D13 — revoke against a REVOKED membership's active access relation still
// succeeds (same asymmetry, membership side).
$f = w3_matrix_fixture();
$revoked_target2 = w3_membership(10, 52, 'manager', 'revoked');
w3_access(10, $revoked_target2, $f['g1']);
$result = call_user_func([$AS, 'revoke_group_access_for_actor'], 10, $f['owner'], $revoked_target2, $f['g1']);
w3_ok('D13 revoke against a revoked membership\'s existing access relation still succeeds (no status check on revoke)', is_array($result) && $result['changed'] === true);

// D14 — revoke with no existing access relation is a no-op, no audit.
$f = w3_matrix_fixture();
$before_audit = count($GLOBALS['w3_db']['audit']);
$before_mutations = $GLOBALS['w3_mutation_calls'];
$result = call_user_func([$AS, 'revoke_group_access_for_actor'], 10, $f['owner'], $f['target_mgr'], $f['g1']);
w3_ok('D14 revoke with no existing access relation is a no-op (changed=false, has_access=false)', $result === ['ok' => true, 'changed' => false, 'membership_id' => $f['target_mgr'], 'group_id' => $f['g1'], 'has_access' => false]);
w3_ok('D14b revoke no-op emits no audit row', count($GLOBALS['w3_db']['audit']) === $before_audit);
w3_ok('D14c revoke no-op issues no DELETE mutation', $GLOBALS['w3_mutation_calls'] === $before_mutations);

// D15 — revoke success: shape + audit correctness.
$f = w3_matrix_fixture();
w3_access(10, $f['target_mgr'], $f['g1']);
$result = call_user_func([$AS, 'revoke_group_access_for_actor'], 10, $f['owner'], $f['target_mgr'], $f['g1']);
w3_ok('D15 revoke success shape', $result === ['ok' => true, 'changed' => true, 'membership_id' => $f['target_mgr'], 'group_id' => $f['g1'], 'has_access' => false]);
w3_ok('D15b revoke emits exactly one audit row with correct action/entity_type/metadata', count($GLOBALS['w3_db']['audit']) === 1
    && $GLOBALS['w3_db']['audit'][0]['action'] === 'group_access_revoked'
    && $GLOBALS['w3_db']['audit'][0]['entity_type'] === 'group_access'
    && $GLOBALS['w3_db']['audit'][0]['actor_user_id'] === $f['owner']
    && $GLOBALS['w3_db']['audit'][0]['metadata']['membership_id'] === $f['target_mgr']
    && $GLOBALS['w3_db']['audit'][0]['metadata']['group_id'] === $f['g1']);

// ──────────────────────────────────────────────────────────────
// Section E — Application-layer strict scalar input validation.
// ──────────────────────────────────────────────────────────────

$f = w3_matrix_fixture();
foreach ([
    [-1, $f['target_mgr'], $f['g1'], 'negative actor_user_id'],
    [$f['owner'], 0, $f['g1'], 'zero membership_id'],
    [$f['owner'], $f['target_mgr'], -5, 'negative group_id'],
] as [$actor, $mem, $grp, $label]) {
    $result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $actor, $mem, $grp);
    w3_ok("E1 grant strict-rejects $label as invalid_input", w3_code($result) === 'invalid_input');
    $result2 = call_user_func([$AS, 'revoke_group_access_for_actor'], 10, $actor, $mem, $grp);
    w3_ok("E2 revoke strict-rejects $label as invalid_input", w3_code($result2) === 'invalid_input');
}

// ──────────────────────────────────────────────────────────────
// Section F — schema-not-ready maps to write_unavailable (not a raw
// schema_not_ready leak).
// ──────────────────────────────────────────────────────────────

$f = w3_matrix_fixture();
$GLOBALS['w3_schema_ready'] = false;
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $f['owner'], $f['target_mgr'], $f['g1']);
w3_ok('F1 schema-not-ready grant maps to write_unavailable', w3_code($result) === 'write_unavailable');
$result = call_user_func([$AS, 'revoke_group_access_for_actor'], 10, $f['owner'], $f['target_mgr'], $f['g1']);
w3_ok('F2 schema-not-ready revoke maps to write_unavailable', w3_code($result) === 'write_unavailable');
$GLOBALS['w3_schema_ready'] = true;

// ──────────────────────────────────────────────────────────────
// Section G — AJAX: login/nonce/validation/actor-spoof/no-nopriv.
// ──────────────────────────────────────────────────────────────

$f = w3_matrix_fixture();
$GLOBALS['w3_logged_in'] = false;
w3_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['target_mgr'], 'group_id' => (string) $f['g1']]);
$resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
w3_ok('G1 grant AJAX not logged in is denied before any application call', $resp['success'] === false && $resp['data']['reason'] === 'not_logged_in');
$GLOBALS['w3_logged_in'] = true;

$f = w3_matrix_fixture();
$GLOBALS['w3_current_user_id'] = $f['owner'];
$_POST = ['nonce' => 'garbage', 'event_id' => '10', 'membership_id' => (string) $f['target_mgr'], 'group_id' => (string) $f['g1']];
$resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
w3_ok('G2 grant AJAX bad nonce is denied', $resp['success'] === false && $resp['data']['reason'] === 'invalid_nonce');

foreach ([
    ['event_id' => '', 'membership_id' => '1', 'group_id' => '1'],
    ['event_id' => '10', 'membership_id' => '', 'group_id' => '1'],
    ['event_id' => '10', 'membership_id' => '1.5', 'group_id' => '1'],
    ['event_id' => '10', 'membership_id' => '-1', 'group_id' => '1'],
    ['event_id' => '10', 'membership_id' => '1', 'group_id' => '0'],
    ['event_id' => '10', 'membership_id' => ['1'], 'group_id' => '1'],
] as $bad) {
    $f = w3_matrix_fixture();
    $GLOBALS['w3_current_user_id'] = $f['owner'];
    w3_ajax_post($bad);
    $resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
    w3_ok('G3 grant AJAX strict-rejects malformed field ' . json_encode($bad), $resp['success'] === false && $resp['data']['reason'] === 'invalid_input');
    $resp2 = w3_call_ajax_handler('pge_event_access_revoke_group_access_handler');
    w3_ok('G4 revoke AJAX strict-rejects malformed field ' . json_encode($bad), $resp2['success'] === false && $resp2['data']['reason'] === 'invalid_input');
}

$f = w3_matrix_fixture();
$GLOBALS['w3_current_user_id'] = $f['owner'];
w3_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['target_mgr'], 'group_id' => (string) $f['g1']]);
$resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
w3_ok('G5 grant AJAX end-to-end success shape', $resp['success'] === true && $resp['data']['changed'] === true
    && $resp['data']['membership_id'] === $f['target_mgr'] && $resp['data']['group_id'] === $f['g1'] && $resp['data']['has_access'] === true);

$f = w3_matrix_fixture();
w3_access(10, $f['target_mgr'], $f['g1']);
$GLOBALS['w3_current_user_id'] = $f['owner'];
w3_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['target_mgr'], 'group_id' => (string) $f['g1']]);
$resp = w3_call_ajax_handler('pge_event_access_revoke_group_access_handler');
w3_ok('G6 revoke AJAX end-to-end success shape', $resp['success'] === true && $resp['data']['changed'] === true
    && $resp['data']['membership_id'] === $f['target_mgr'] && $resp['data']['group_id'] === $f['g1'] && $resp['data']['has_access'] === false);

// Actor spoof: a client-sent actor_user_id/user_id/manager_id/owner_id field
// must be completely ignored; the real session (Manager, denied) governs.
$f = w3_matrix_fixture();
$GLOBALS['w3_current_user_id'] = $f['manager']; // real session is a Manager (flat-denied for this domain)
$before_mutations = $GLOBALS['w3_mutation_calls'];
w3_ajax_post([
    'event_id' => '10', 'membership_id' => (string) $f['target_mgr'], 'group_id' => (string) $f['g1'],
    'actor_user_id' => (string) $f['admin'], 'user_id' => (string) $f['admin'], 'manager_id' => (string) $f['admin'], 'owner_id' => (string) $f['admin'],
]);
$resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
w3_ok('G7 (actor spoof) spoofed actor_user_id/user_id/manager_id/owner_id fields are ignored — real Manager session denied', $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');
w3_ok('G8 (actor spoof) Repository mutation was never invoked for the spoofed/denied request', $GLOBALS['w3_mutation_calls'] === $before_mutations);

$ajax_write_source = file_get_contents(PGE_PATH . 'includes/event-access-ajax.php');
$ajax_write_code = w3_strip_comments($ajax_write_source);
w3_ok('G9 AJAX source never reads $_POST[\'actor_user_id\']/[\'user_id\']/[\'manager_id\']/[\'owner_id\']', preg_match('/\$_POST\[\'(actor_user_id|user_id|manager_id|owner_id)\'\]/', $ajax_write_code) === 0);

// No wp_ajax_nopriv_ registration.
w3_ok('G10 no wp_ajax_nopriv_ registered for grant/revoke group access (source)', preg_match('/wp_ajax_nopriv_pge_event_access_(grant|revoke)_group_access/', $ajax_write_source) === 0);
w3_ok('G11 wp_ajax_ hooks ARE registered for both actions (real add_action() calls captured at require-time)', isset($GLOBALS['w3_registered_hooks']['wp_ajax_pge_event_access_grant_group_access']) && isset($GLOBALS['w3_registered_hooks']['wp_ajax_pge_event_access_revoke_group_access']));
w3_ok('G12 no wp_ajax_nopriv_ hook was ever actually registered (runtime proof, not just source grep)', !isset($GLOBALS['w3_registered_hooks']['wp_ajax_nopriv_pge_event_access_grant_group_access']) && !isset($GLOBALS['w3_registered_hooks']['wp_ajax_nopriv_pge_event_access_revoke_group_access']));

// ──────────────────────────────────────────────────────────────
// Section H — Error mapping via the real AJAX handler + real public error
// mapper (pge_event_access_public_group_access_write_error()).
// ──────────────────────────────────────────────────────────────

// H1 invalid_input (already covered end-to-end in G3/G4 above).

// H2 not_found (missing membership id).
$f = w3_matrix_fixture();
$GLOBALS['w3_current_user_id'] = $f['owner'];
w3_ajax_post(['event_id' => '10', 'membership_id' => '999999', 'group_id' => (string) $f['g1']]);
$resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
w3_ok('H2 not_found maps to public reason not_found, no internal detail', $resp['success'] === false && $resp['data']['reason'] === 'not_found' && strpos(json_encode($resp), 'wp_pge_') === false);

// H3 invalid_state (archived group).
$f = w3_matrix_fixture();
$archived3 = w3_group(10, 'archived');
$GLOBALS['w3_current_user_id'] = $f['owner'];
w3_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['target_mgr'], 'group_id' => (string) $archived3]);
$resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
w3_ok('H3 invalid_state maps to public reason invalid_state', $resp['success'] === false && $resp['data']['reason'] === 'invalid_state');

// H4 concurrent_update (forced delete-miss race on revoke).
$f = w3_matrix_fixture();
w3_access(10, $f['target_mgr'], $f['g1']);
$GLOBALS['w3_force_delete_miss'] = true;
$GLOBALS['w3_current_user_id'] = $f['owner'];
w3_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['target_mgr'], 'group_id' => (string) $f['g1']]);
$resp = w3_call_ajax_handler('pge_event_access_revoke_group_access_handler');
w3_ok('H4 concurrent_update (forced delete-miss race) maps to public reason concurrent_update', $resp['success'] === false && $resp['data']['reason'] === 'concurrent_update');
w3_ok('H4b no audit row was written for the failed/raced revoke', count($GLOBALS['w3_db']['audit']) === 0);

// H5 write_unavailable (schema not ready).
$f = w3_matrix_fixture();
$GLOBALS['w3_schema_ready'] = false;
$GLOBALS['w3_current_user_id'] = $f['owner'];
w3_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['target_mgr'], 'group_id' => (string) $f['g1']]);
$resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
w3_ok('H5 write_unavailable maps to public reason write_unavailable', $resp['success'] === false && $resp['data']['reason'] === 'write_unavailable');
$GLOBALS['w3_schema_ready'] = true;

// H6 database_error / unrecognized -> generic server_error (forced insert
// failure on grant — models a genuine storage-layer write failure).
$f = w3_matrix_fixture();
$GLOBALS['w3_force_insert_fail'] = true;
$GLOBALS['w3_current_user_id'] = $f['owner'];
w3_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['target_mgr'], 'group_id' => (string) $f['g1']]);
$resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
w3_ok('H6 database_error (forced insert failure) maps to generic public reason server_error, no SQL/table leak', $resp['success'] === false && $resp['data']['reason'] === 'server_error' && strpos(json_encode($resp), 'wp_pge_') === false && strpos(json_encode($resp), 'INSERT') === false);
w3_ok('H6b no audit row was written for the failed insert', count($GLOBALS['w3_db']['audit']) === 0);
$GLOBALS['w3_force_insert_fail'] = false;

// ──────────────────────────────────────────────────────────────
// Section I — Audit behavior summary (Section 17 of the brief): changed
// mutation -> correct audit (D8b/D15b above); no-op -> no audit (D9b/D14b
// above); authorization denial -> no mutation -> no audit (below); failed
// mutation -> no false audit (H4b/H6b above).
// ──────────────────────────────────────────────────────────────

$f = w3_matrix_fixture();
$before_audit = count($GLOBALS['w3_db']['audit']);
$before_mutations = $GLOBALS['w3_mutation_calls'];
$result = call_user_func([$AS, 'grant_group_access_for_actor'], 10, $f['stranger'], $f['target_mgr'], $f['g1']);
w3_ok('I1 authorization denial (grant) never mutates and never audits', w3_code($result) === 'not_authorized' && count($GLOBALS['w3_db']['audit']) === $before_audit && $GLOBALS['w3_mutation_calls'] === $before_mutations);

$f = w3_matrix_fixture();
w3_access(10, $f['target_mgr'], $f['g1']);
$before_audit = count($GLOBALS['w3_db']['audit']);
$before_mutations = $GLOBALS['w3_mutation_calls'];
$result = call_user_func([$AS, 'revoke_group_access_for_actor'], 10, $f['stranger'], $f['target_mgr'], $f['g1']);
w3_ok('I2 authorization denial (revoke) never mutates and never audits, even though a real grant exists', w3_code($result) === 'not_authorized' && count($GLOBALS['w3_db']['audit']) === $before_audit && $GLOBALS['w3_mutation_calls'] === $before_mutations);

// ──────────────────────────────────────────────────────────────
// Section J — Cross-event boundaries, full stack (Application Service +
// AJAX), fail-closed even for a numeric-id guess.
// ──────────────────────────────────────────────────────────────

w3_reset_db();
w3_event(10, 1); w3_event(11, 1);
$gA = w3_group(10);
$mgrOtherEvent = w3_membership(11, 3, 'manager');
$GLOBALS['w3_current_user_id'] = 1;
w3_ajax_post(['event_id' => '10', 'membership_id' => (string) $mgrOtherEvent, 'group_id' => (string) $gA]);
$resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
w3_ok('J1 AJAX end-to-end: cross-event membership id (real row, wrong event) is not_found', $resp['success'] === false && $resp['data']['reason'] === 'not_found');

w3_reset_db();
w3_event(10, 1); w3_event(11, 1);
$mgrA2 = w3_membership(10, 3, 'manager');
$gOtherEvent = w3_group(11);
$GLOBALS['w3_current_user_id'] = 1;
w3_ajax_post(['event_id' => '10', 'membership_id' => (string) $mgrA2, 'group_id' => (string) $gOtherEvent]);
$resp = w3_call_ajax_handler('pge_event_access_grant_group_access_handler');
w3_ok('J2 AJAX end-to-end: cross-event group id (real row, wrong event) is not_found', $resp['success'] === false && $resp['data']['reason'] === 'not_found');

// ──────────────────────────────────────────────────────────────
// Section K — GR1/guest-projection non-interaction (source-level proof,
// comments stripped — this domain never touches guest identity at all).
// ──────────────────────────────────────────────────────────────

$as_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$as_code = w3_strip_comments($as_source);
$write_method_pos = strpos($as_code, 'function grant_group_access_for_actor');
$write_section_code = substr($as_code, $write_method_pos);
w3_ok('K1 group-access write methods never call pge_event_guests_save_map() (comments stripped)', strpos($write_section_code, 'pge_event_guests_save_map') === false);
w3_ok('K2 group-access write methods never reference PGE_Event_Guest_Read_Projection (comments stripped)', strpos($write_section_code, 'PGE_Event_Guest_Read_Projection') === false);
w3_ok('K3 group-access write methods never reference guest_phone at all (comments stripped)', strpos($write_section_code, 'guest_phone') === false);
w3_ok('K4 AJAX handlers never call pge_event_guests_save_map() or reference the projection (comments stripped)', strpos($ajax_write_code, 'pge_event_guests_save_map') === false && strpos($ajax_write_code, 'PGE_Event_Guest_Read_Projection') === false);

$repo_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-repository.php');
$repo_code = w3_strip_comments($repo_source);
w3_ok('K5 (regression sanity) Repository itself still never touches Post Meta or the projection (comments stripped)', strpos($repo_code, 'pge_event_guests_save_map') === false && strpos($repo_code, 'PGE_Event_Guest_Read_Projection') === false);

// ──────────────────────────────────────────────────────────────
// Section L — Repository mutation spy: EVERY denied actor across the full
// authorization matrix issues zero mutating query()/delete() calls in total
// (re-confirms Section C's per-case spies with one aggregate pass).
// ──────────────────────────────────────────────────────────────

$total_denied_mutations = 0;
foreach (['manager', 'manager_unauth', 'viewer', 'revoked_manager', 'revoked_viewer', 'stranger'] as $who) {
    foreach (['grant_group_access_for_actor', 'revoke_group_access_for_actor'] as $method) {
        $f = w3_matrix_fixture();
        w3_access(10, $f['target_mgr'], $f['g1']); // ensure a real grant exists so a would-be mutation is even possible
        $before = $GLOBALS['w3_mutation_calls'];
        call_user_func([$AS, $method], 10, $f[$who], $f['target_mgr'], $f['g1']);
        $total_denied_mutations += ($GLOBALS['w3_mutation_calls'] - $before);
    }
}
w3_ok('L1 zero Repository mutations across the entire denied-actor x grant/revoke matrix', $total_denied_mutations === 0);

echo "\nH1C-W3: {$passed}/" . ($passed + $failed) . " passed\n";
exit($failed === 0 ? 0 : 1);
