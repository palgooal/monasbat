<?php
/**
 * Phase H1C-W6 — Audit Trail Read Wiring.
 *
 * Exercises the real PGE_Event_Access_Application_Service::list_audit_for_actor(),
 * PGE_Event_Access_Authorization[_Context]::can_view_audit(), the real
 * PGE_Event_Access_Repository::list_audit(), and the real
 * pge_event_access_list_audit_handler() AJAX handler — against a small
 * in-memory fake $wpdb (H1B relational tables + the audit log table). No
 * real database, no real network, no UI.
 *
 * Deliberately COMPACT (Section 7 of the H1C-W6 brief): this suite tests
 * behavioral CONTRACTS, not every implementation line — it does not
 * reproduce the hundreds of assertions W2-W5 use for their own (much
 * larger) mutation surfaces. Sections A-H below map 1:1 to the brief's own
 * required-coverage list.
 *
 * Run: php tests/test-event-access-application-phase-h1c-w6.php
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
// Hook registration — records real hook names so W6 can prove no
// wp_ajax_nopriv_ registration exists, without executing WordPress itself.
// ──────────────────────────────────────────────────────────────

$GLOBALS['w6_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['w6_registered_hooks'][$hook] = true; }
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

$GLOBALS['w6_current_user_id'] = 0;
$GLOBALS['w6_logged_in'] = true;
$GLOBALS['w6_admins'] = [];
function get_current_user_id() { return $GLOBALS['w6_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['w6_logged_in']; }
// Authorization Context calls user_can($actor_user_id, 'administrator') —
// deliberately NOT current_user_can() (same convention as W1/W5).
function user_can($user_id, $capability)
{
    if ($capability !== 'administrator') return false;
    return in_array((int) $user_id, $GLOBALS['w6_admins'], true);
}

$GLOBALS['w6_posts'] = [];
function get_post_type($post_id)
{
    return $GLOBALS['w6_posts'][(int) $post_id]['post_type'] ?? false;
}
function get_post_field($field, $post_id)
{
    if (!isset($GLOBALS['w6_posts'][(int) $post_id][$field])) return '';
    return (string) $GLOBALS['w6_posts'][(int) $post_id][$field];
}

// ──────────────────────────────────────────────────────────────
// PGE_Event_Access_Schema shim (Repository's guard_event() consults this).
// ──────────────────────────────────────────────────────────────

class PGE_Event_Access_Schema
{
    public static function is_ready() { return $GLOBALS['w6_schema_ready']; }
    public static function maybe_upgrade() { throw new RuntimeException('maybe_upgrade() must never be called by a W6 read path.'); }
}

// ──────────────────────────────────────────────────────────────
// In-memory fixture store + SQL dispatcher.
//
// Only the query shapes actually reachable from list_audit_for_actor()'s
// real call graph are recognized: resolve_context()'s membership/scope
// lookups (get_membership_for_user() / list_group_ids_for_membership()),
// and list_audit()'s own COUNT(*)/paginated SELECT. Nothing else — W6
// touches no other Repository read/write path.
// ──────────────────────────────────────────────────────────────

function w6_tables()
{
    return [
        'groups' => 'wp_pge_event_invitation_groups',
        'memberships' => 'wp_pge_event_host_memberships',
        'access' => 'wp_pge_event_host_group_access',
        'audit' => 'wp_pge_event_access_audit_log',
    ];
}

function w6_reset_db()
{
    $GLOBALS['w6_db'] = [
        'groups' => [],
        'memberships' => [],
        'access' => [],
        'audit' => [],
        'next_id' => 1,
    ];
    $GLOBALS['w6_posts'] = [];
    $GLOBALS['w6_admins'] = [];
    $GLOBALS['w6_schema_ready'] = true;
    $GLOBALS['w6_current_user_id'] = 0;
    $GLOBALS['w6_logged_in'] = true;
    $GLOBALS['w6_audit_read_calls'] = 0;
}

function w6_next_id() { return $GLOBALS['w6_db']['next_id']++; }

function w6_event($event_id, $author_id)
{
    $GLOBALS['w6_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => $author_id];
}

function w6_admin($user_id) { $GLOBALS['w6_admins'][] = (int) $user_id; }

function w6_membership($event_id, $user_id, $role, $status = 'active')
{
    $id = w6_next_id();
    $GLOBALS['w6_db']['memberships'][] = [
        'id' => $id, 'event_id' => $event_id, 'user_id' => $user_id, 'role' => $role, 'status' => $status,
    ];
    return $id;
}

function w6_group($event_id, $name = 'Group')
{
    $id = w6_next_id();
    $GLOBALS['w6_db']['groups'][$id] = ['id' => $id, 'event_id' => $event_id, 'name' => $name];
    return $id;
}

function w6_access($event_id, $membership_id, $group_id)
{
    $GLOBALS['w6_db']['access'][] = ['event_id' => $event_id, 'membership_id' => $membership_id, 'group_id' => $group_id];
}

/**
 * Seeds a raw audit-log row exactly as the Repository would read it back
 * from the real table — 'metadata' stored as a JSON string (or null), NOT
 * a decoded array, so normalize_audit()'s own is_string()/json_decode()
 * path is genuinely exercised (Section 5/G of the brief: this suite tests
 * the Application/AJAX boundary, not a shortcut around it).
 */
function w6_audit($event_id, $actor_user_id, $action, $entity_type, $entity_id, $metadata, $created_at)
{
    $id = w6_next_id();
    $GLOBALS['w6_db']['audit'][] = [
        'id' => $id, 'event_id' => $event_id, 'actor_user_id' => $actor_user_id,
        'action' => $action, 'entity_type' => $entity_type, 'entity_id' => $entity_id,
        'metadata' => $metadata === null ? null : wp_json_encode($metadata),
        'created_at' => $created_at,
    ];
    return $id;
}

function w6_parse_audit_where($where)
{
    $conditions = [];
    foreach (explode(' AND ', $where) as $frag) {
        $frag = trim($frag);
        if (preg_match('/^event_id = (\d+)$/', $frag, $m)) { $conditions['event_id'] = (int) $m[1]; continue; }
        if (preg_match("/^action = '([^']*)'\$/", $frag, $m)) { $conditions['action'] = $m[1]; continue; }
        if (preg_match("/^entity_type = '([^']*)'\$/", $frag, $m)) { $conditions['entity_type'] = $m[1]; continue; }
        if (preg_match('/^actor_user_id = (\d+)$/', $frag, $m)) { $conditions['actor_user_id'] = (int) $m[1]; continue; }
        if (preg_match('/^entity_id = (\d+)$/', $frag, $m)) { $conditions['entity_id'] = (int) $m[1]; continue; }
        return null; // unrecognized fragment — fail closed, not silently ignored.
    }
    return $conditions;
}

function w6_matching_audit_rows($conditions)
{
    $rows = [];
    foreach ($GLOBALS['w6_db']['audit'] as $row) {
        $ok = true;
        foreach ($conditions as $k => $v) {
            if ($row[$k] !== $v) { $ok = false; break; }
        }
        if ($ok) $rows[] = $row;
    }
    return $rows;
}

function w6_dispatch_scalar($sql)
{
    $t = w6_tables();
    $sql = trim($sql);
    if (preg_match('/^SELECT COUNT\(\*\) FROM ' . preg_quote($t['audit'], '/') . ' WHERE (.+)$/', $sql, $m)) {
        $conditions = w6_parse_audit_where($m[1]);
        if ($conditions === null) return false;
        $GLOBALS['w6_audit_read_calls']++;
        return count(w6_matching_audit_rows($conditions));
    }
    return false;
}

function w6_dispatch_select($sql)
{
    $t = w6_tables();
    $sql = trim($sql);

    // get_membership_for_user() — consulted by resolve_context() for any
    // non-Admin, non-Owner actor.
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND user_id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $user_id = (int) $m[2];
        return array_values(array_filter($GLOBALS['w6_db']['memberships'], function ($r) use ($event_id, $user_id) {
            return $r['event_id'] === $event_id && $r['user_id'] === $user_id;
        }));
    }

    // list_group_ids_for_membership() join — consulted for an active
    // collaborator's granted scope.
    $join = '/^SELECT a\.event_id, a\.group_id, g\.event_id AS related_event_id FROM '
        . preg_quote($t['access'], '/') . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.membership_id = (\d+) ORDER BY a\.group_id ASC$/';
    if (preg_match($join, $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2];
        $out = [];
        foreach ($GLOBALS['w6_db']['access'] as $row) {
            if ($row['event_id'] !== $event_id || $row['membership_id'] !== $membership_id) continue;
            $group = $GLOBALS['w6_db']['groups'][$row['group_id']] ?? null;
            $out[] = ['event_id' => $row['event_id'], 'group_id' => $row['group_id'], 'related_event_id' => $group['event_id'] ?? null];
        }
        return $out;
    }

    // list_audit()'s own paginated SELECT.
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['audit'], '/') . ' WHERE (.+) ORDER BY created_at DESC, id DESC LIMIT (\d+) OFFSET (\d+)$/', $sql, $m)) {
        $conditions = w6_parse_audit_where($m[1]);
        if ($conditions === null) return null;
        $GLOBALS['w6_audit_read_calls']++;
        $rows = w6_matching_audit_rows($conditions);
        usort($rows, function ($a, $b) {
            $c = strcmp($b['created_at'], $a['created_at']);
            if ($c !== 0) return $c;
            return $b['id'] <=> $a['id'];
        });
        return array_slice($rows, (int) $m[3], (int) $m[2]);
    }

    return null;
}

class PGE_W6_Fake_WPDB
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
        $rows = w6_dispatch_select($sql);
        if ($rows === null) {
            $this->last_error = 'w6-fake-wpdb: unrecognized SELECT: ' . $sql;
            return null;
        }
        return $rows;
    }

    public function get_var($sql)
    {
        $value = w6_dispatch_scalar($sql);
        if ($value === false) {
            $this->last_error = 'w6-fake-wpdb: unrecognized scalar query: ' . $sql;
            return null;
        }
        $this->last_error = '';
        return $value;
    }

    public function get_row($sql, $output = null)
    {
        $this->last_error = 'w6-fake-wpdb: unrecognized get_row() query: ' . $sql;
        return null;
    }

    // W6 is a pure read path. Any mutating call at all is a structural
    // violation — this class never wires a write, so none should ever
    // reach $wpdb.
    public function query($sql)
    {
        $this->last_error = 'w6-fake-wpdb: unexpected mutating query() call: ' . $sql;
        return false;
    }

    public function insert($table, $data, $formats = null)
    {
        $this->last_error = 'w6-fake-wpdb: unexpected insert() call';
        return false;
    }

    public function update($table, $data, $where, $formats = null, $where_formats = null)
    {
        $this->last_error = 'w6-fake-wpdb: unexpected update() call';
        return false;
    }

    public function delete($table, $where, $formats = null)
    {
        $this->last_error = 'w6-fake-wpdb: unexpected delete() call';
        return false;
    }
}

w6_reset_db();
global $wpdb;
$wpdb = new PGE_W6_Fake_WPDB();

// ──────────────────────────────────────────────────────────────
// AJAX/JSON stubs (same convention as H1C-W1).
// ──────────────────────────────────────────────────────────────

class Test_Wp_Die_Exception extends \Exception {}

$GLOBALS['w6_json_response'] = null;
function wp_send_json_success($data = null)
{
    $GLOBALS['w6_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null)
{
    $GLOBALS['w6_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = [])
{
    throw new Test_Wp_Die_Exception('wp_die');
}

function w6_call_ajax_handler(callable $handler): array
{
    $GLOBALS['w6_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // expected
    }
    $raw = $GLOBALS['w6_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function w6_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_create_nonce($action) { return w6_nonce($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals(w6_nonce($action), (string) $nonce) ? 1 : false; }

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

function w6_ok($label, $condition, $extra = '')
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

function w6_code($result) { return $result instanceof WP_Error ? $result->get_error_code() : null; }

$S = 'PGE_Event_Access_Application_Service';

// ══════════════════════════════════════════════════════════════
// Section A — Authorized success (every actor type can_view_audit() allows:
// Admin, Owner)
// ══════════════════════════════════════════════════════════════

w6_reset_db();
w6_event(300, 10); // owner = user 10
w6_admin(20);       // admin = user 20

w6_audit(300, 10, 'membership_created', 'membership', 501, ['role' => 'viewer'], '2026-01-01 10:00:00');
w6_audit(300, 10, 'group_created', 'group', 601, null, '2026-01-01 11:00:00');
w6_audit(300, 10, 'membership_revoked', 'membership', 501, ['status_changed' => true], '2026-01-01 12:00:00');
// A different event's row must never leak into event 300's results.
w6_event(301, 99);
w6_audit(301, 99, 'group_created', 'group', 602, null, '2026-01-01 13:00:00');

$owner_result = $S::list_audit_for_actor(300, 10);
w6_ok('A1 owner call succeeds (not a WP_Error)', is_array($owner_result), is_wp_error($owner_result) ? $owner_result->get_error_code() : '');
w6_ok('A2 owner sees exactly the 3 rows for event 300, none from event 301', is_array($owner_result) && count($owner_result['items']) === 3 && $owner_result['total'] === 3);
w6_ok('A3 items are ordered created_at DESC, id DESC', is_array($owner_result)
    && $owner_result['items'][0]['action'] === 'membership_revoked'
    && $owner_result['items'][1]['action'] === 'group_created'
    && $owner_result['items'][2]['action'] === 'membership_created');

$admin_result = $S::list_audit_for_actor(300, 20);
w6_ok('A4 admin call succeeds and sees the same 3 rows', is_array($admin_result) && count($admin_result['items']) === 3);

$paged = $S::list_audit_for_actor(300, 10, [], 1, 2);
w6_ok('A5 pagination contract honored (per_page=2 -> 2 items, total=3, total_pages=2)', is_array($paged)
    && count($paged['items']) === 2 && $paged['total'] === 3 && $paged['total_pages'] === 2 && $paged['page'] === 1 && $paged['per_page'] === 2);

$filtered = $S::list_audit_for_actor(300, 10, ['entity_type' => 'membership']);
w6_ok('A6 accepted Repository filter (entity_type) is honored end-to-end', is_array($filtered) && count($filtered['items']) === 2);

// ══════════════════════════════════════════════════════════════
// Section B — Unauthorized actors denied; the audit table itself is never
// queried for a denied actor (can_view_audit() rejects by role alone,
// before any existence-sensitive audit-table lookup).
// ══════════════════════════════════════════════════════════════

$manager_id = 40;
$viewer_id = 41;
$revoked_id = 42;
$stranger_id = 43;
w6_membership(300, $manager_id, 'manager', 'active');
w6_membership(300, $viewer_id, 'viewer', 'active');
w6_membership(300, $revoked_id, 'manager', 'revoked');
// $stranger_id has no membership row at all.

foreach (['manager' => $manager_id, 'viewer' => $viewer_id, 'revoked' => $revoked_id, 'stranger' => $stranger_id] as $label => $uid) {
    $GLOBALS['w6_audit_read_calls'] = 0;
    $result = $S::list_audit_for_actor(300, $uid);
    w6_ok("B $label actor is denied (not_authorized)", w6_code($result) === $S::REASON_NOT_AUTHORIZED, w6_code($result));
    w6_ok("B $label actor: the audit table itself was never queried", $GLOBALS['w6_audit_read_calls'] === 0);
}

// ══════════════════════════════════════════════════════════════
// Section C — EC1 privacy: an existing-but-denied event, a nonexistent
// event, and a cheap pre-authority context-resolution failure must all be
// publicly indistinguishable.
// ══════════════════════════════════════════════════════════════

$existing_denied = $S::list_audit_for_actor(300, $stranger_id);
$nonexistent_event = $S::list_audit_for_actor(999999, $stranger_id);
w6_ok('C1 existing event + unauthorized actor -> not_authorized', w6_code($existing_denied) === $S::REASON_NOT_AUTHORIZED);
w6_ok('C2 nonexistent event + the SAME unauthorized actor -> the identical not_authorized code (no event-id oracle)', w6_code($nonexistent_event) === w6_code($existing_denied));

// Cheap pre-authority context-resolution failure: two ACTIVE membership
// rows for the same (event, user) makes get_membership_for_user()'s own
// LIMIT-2 ambiguity guard fire inside resolve_context() itself — a real
// pre-authority WP_Error, before any capability decision is ever reached.
$ambiguous_id = 44;
w6_membership(300, $ambiguous_id, 'manager', 'active');
w6_membership(300, $ambiguous_id, 'viewer', 'active');
$ambiguous_result = $S::list_audit_for_actor(300, $ambiguous_id);
w6_ok('C3 a genuine pre-authority context-resolution failure collapses to the identical not_authorized code too', w6_code($ambiguous_result) === $S::REASON_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════
// Section D — Actor spoof resistance: client-supplied actor_user_id/
// user_id/owner_id/manager_id fields must never change who the AJAX
// handler treats as the actor.
// ══════════════════════════════════════════════════════════════

$GLOBALS['w6_current_user_id'] = $stranger_id; // real session: denied stranger
$GLOBALS['w6_logged_in'] = true;
$_POST = [
    'nonce' => wp_create_nonce('pge_event_manage_nonce'),
    'event_id' => 300,
    // Spoof attempt: claim to be the real owner (user 10) via every
    // plausible field name.
    'actor_user_id' => 10,
    'user_id' => 10,
    'owner_id' => 10,
    'manager_id' => 10,
];
$spoof_response = w6_call_ajax_handler('pge_event_access_list_audit_handler');
w6_ok('D1 spoofed actor_user_id/user_id/owner_id/manager_id fields do not grant access — real session (stranger) is still denied',
    $spoof_response['success'] === false && ($spoof_response['data']['reason'] ?? null) === 'not_authorized');

// Sanity: the SAME real owner session, no spoofing needed, legitimately succeeds.
$GLOBALS['w6_current_user_id'] = 10;
$_POST = ['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => 300];
$owner_ajax = w6_call_ajax_handler('pge_event_access_list_audit_handler');
w6_ok('D2 the real owner session (no spoof needed) legitimately succeeds via the same handler', $owner_ajax['success'] === true && isset($owner_ajax['data']['items']));

// ══════════════════════════════════════════════════════════════
// Section E — Input validation: only boundaries actually present in the
// real Application/Repository contract.
// ══════════════════════════════════════════════════════════════

w6_ok('E1 invalid event_id (0) -> invalid_input', w6_code($S::list_audit_for_actor(0, 10)) === $S::REASON_INVALID_INPUT);
w6_ok('E2 invalid actor_user_id (negative) -> invalid_input', w6_code($S::list_audit_for_actor(300, -1)) === $S::REASON_INVALID_INPUT);
w6_ok('E3 invalid page (0) -> invalid_input (post-authority: owner is authorized first)', w6_code($S::list_audit_for_actor(300, 10, [], 0)) === 'invalid_input');
w6_ok('E4 invalid per_page (0) -> invalid_input', w6_code($S::list_audit_for_actor(300, 10, [], 1, 0)) === 'invalid_input');
w6_ok('E5 per_page over Repository MAX_PER_PAGE (100) -> invalid_input', w6_code($S::list_audit_for_actor(300, 10, [], 1, 101)) === 'invalid_input');
w6_ok('E6 unsupported filter key (not in list_audit()\'s own allowed list) -> invalid_filter', w6_code($S::list_audit_for_actor(300, 10, ['bogus_key' => 1])) === 'invalid_filter');
w6_ok('E7 filter action value outside AUDIT_ACTIONS -> invalid_filter', w6_code($S::list_audit_for_actor(300, 10, ['action' => 'not_a_real_action'])) === 'invalid_filter');
w6_ok('E8 filter entity_type value outside AUDIT_ENTITY_TYPES -> invalid_filter', w6_code($S::list_audit_for_actor(300, 10, ['entity_type' => 'not_a_real_type'])) === 'invalid_filter');

// ══════════════════════════════════════════════════════════════
// Section F — Post-authority error fidelity: a genuine Repository error
// for an AUTHORIZED actor must keep its own honest reason, never collapse
// to not_authorized merely because W6 exists.
// ══════════════════════════════════════════════════════════════

w6_ok('F1 invalid_filter for an authorized owner stays invalid_filter, not not_authorized', w6_code($S::list_audit_for_actor(300, 10, ['bogus_key' => 1])) === 'invalid_filter');

$GLOBALS['w6_schema_ready'] = false;
$schema_result = $S::list_audit_for_actor(300, 10);
$GLOBALS['w6_schema_ready'] = true;
w6_ok('F2 schema_not_ready for an authorized owner maps to read_unavailable, not not_authorized', w6_code($schema_result) === 'read_unavailable', w6_code($schema_result));

// A genuine row-normalization failure (an action value that somehow isn't
// in AUDIT_ACTIONS reaching normalize_audit()) must surface as a real
// database_error for an authorized actor, not a false not_authorized.
w6_audit(300, 10, 'this_action_does_not_exist', 'membership', 501, null, '2026-01-01 14:00:00');
// An unfiltered call (per_page raised to include every row) so the corrupt
// row is actually reached by normalize_audit() — a filter on that same
// bogus action value would just be rejected first as invalid_filter
// (already proven by E7), which would not exercise this path.
$corrupt_result = $S::list_audit_for_actor(300, 10, [], 1, 100);
w6_ok('F3 a genuine row-normalization database_error for an authorized owner stays database_error, not not_authorized', w6_code($corrupt_result) === 'database_error', w6_code($corrupt_result));
// Remove the corrupt row so it doesn't affect later sections.
array_pop($GLOBALS['w6_db']['audit']);

// ══════════════════════════════════════════════════════════════
// Section G — Privacy: the public result at the Application/AJAX boundary
// never reintroduces guest PII, even though the Repository already
// guarantees guest_group_* metadata can only ever contain numeric
// group/membership ids.
// ══════════════════════════════════════════════════════════════

w6_audit(300, 10, 'guest_group_assigned', 'guest_assignment', 701, ['group_id' => 55], '2026-01-01 15:00:00');
$pii_result = $S::list_audit_for_actor(300, 10, ['action' => 'guest_group_assigned']);
$pii_item = is_array($pii_result) ? ($pii_result['items'][0] ?? null) : null;
w6_ok('G1 guest_group_assigned metadata reaches the public result with EXACTLY the group_id key (no phone/name field of any kind)',
    is_array($pii_item) && is_array($pii_item['metadata']) && array_keys($pii_item['metadata']) === ['group_id']);
$serialized = wp_json_encode($pii_result);
w6_ok('G2 the serialized public result contains no phone-shaped string (a 970-prefixed 12-digit run)', !preg_match('/\b970\d{9}\b/', $serialized));

// ══════════════════════════════════════════════════════════════
// Section H — Transport structure.
// ══════════════════════════════════════════════════════════════

w6_ok('H1 wp_ajax_pge_event_access_list_audit is registered', isset($GLOBALS['w6_registered_hooks']['wp_ajax_pge_event_access_list_audit']));
w6_ok('H2 no wp_ajax_nopriv_pge_event_access_list_audit registration exists', !isset($GLOBALS['w6_registered_hooks']['wp_ajax_nopriv_pge_event_access_list_audit']));

$ajax_source = file_get_contents(PGE_PATH . 'includes/event-access-ajax.php');
$handler_start = strpos($ajax_source, 'function pge_event_access_list_audit_handler()');
$handler_end = strpos($ajax_source, "add_action('wp_ajax_pge_event_access_list_audit'", $handler_start);
w6_ok('H3 handler source located', $handler_start !== false && $handler_end !== false && $handler_end > $handler_start);
$handler_body = ($handler_start !== false && $handler_end !== false) ? substr($ajax_source, $handler_start, $handler_end - $handler_start) : '';
w6_ok('H4 the handler calls PGE_Event_Access_Application_Service', strpos($handler_body, 'PGE_Event_Access_Application_Service::list_audit_for_actor') !== false);
w6_ok('H5 the handler never references PGE_Event_Access_Repository directly', strpos($handler_body, 'PGE_Event_Access_Repository') === false);
w6_ok('H6 the handler never references PGE_Event_Access_Authorization directly', strpos($handler_body, 'PGE_Event_Access_Authorization') === false);
w6_ok('H7 the handler never reads $_POST[\'actor_user_id\']/\'user_id\'/\'owner_id\'/\'manager_id\'',
    !preg_match('/\$_POST\[.(actor_user_id|user_id|owner_id|manager_id).\]/', $handler_body));

// ══════════════════════════════════════════════════════════════
// Section J — AJAX-boundary corrective pass (strict transport parsing +
// audit-specific public error fidelity). All calls below go through the
// REAL pge_event_access_list_audit_handler(), not the Application Service
// directly, since these are transport-layer contracts.
// ══════════════════════════════════════════════════════════════

$GLOBALS['w6_current_user_id'] = 10; // owner of event 300 throughout Section J
$GLOBALS['w6_logged_in'] = true;

function w6_ajax_audit(array $extra_post): array
{
    $_POST = array_merge([
        'nonce' => wp_create_nonce('pge_event_manage_nonce'),
        'event_id' => 300,
    ], $extra_post);
    return w6_call_ajax_handler('pge_event_access_list_audit_handler');
}

// J1 (brief A) — a malformed numeric filter must never be silently
// coerced into a valid-looking id via (int).
$j1 = w6_ajax_audit(['filter_actor_user_id' => '12abc']);
w6_ok('J1 filter_actor_user_id="12abc" is rejected as invalid_filter, never silently read as actor 12',
    $j1['success'] === false && ($j1['data']['reason'] ?? null) === 'invalid_filter', json_encode($j1));

// J2 (brief B) — same strict behavior for filter_entity_id.
$j2 = w6_ajax_audit(['filter_entity_id' => '7xyz']);
w6_ok('J2 filter_entity_id="7xyz" is rejected as invalid_filter, never silently read as entity 7',
    $j2['success'] === false && ($j2['data']['reason'] ?? null) === 'invalid_filter', json_encode($j2));

// J3 (brief C) — an explicitly supplied invalid page must fail, not
// silently become page 1.
$j3a = w6_ajax_audit(['page' => 'abc']);
w6_ok('J3a page="abc" is rejected as invalid_input, never silently normalized to page 1',
    $j3a['success'] === false && ($j3a['data']['reason'] ?? null) === 'invalid_input', json_encode($j3a));
$j3b = w6_ajax_audit(['page' => '0']);
w6_ok('J3b page="0" is rejected as invalid_input, never silently normalized to page 1',
    $j3b['success'] === false && ($j3b['data']['reason'] ?? null) === 'invalid_input', json_encode($j3b));
// Sanity: a genuinely missing page still gets the existing default (1) and
// succeeds normally — the strict parsing above only rejects an EXPLICITLY
// supplied bad value, it does not break the ordinary no-page-given case.
$j3c = w6_ajax_audit([]);
w6_ok('J3c a missing page still defaults to page 1 and succeeds', $j3c['success'] === true && ($j3c['data']['page'] ?? null) === 1, json_encode($j3c));

// J4 (brief D) — an explicitly supplied invalid per_page must fail, not
// silently become the default or any other value.
$j4a = w6_ajax_audit(['per_page' => 'abc']);
w6_ok('J4a per_page="abc" is rejected as invalid_input, never silently normalized to the default',
    $j4a['success'] === false && ($j4a['data']['reason'] ?? null) === 'invalid_input', json_encode($j4a));
$j4b = w6_ajax_audit(['per_page' => '0']);
w6_ok('J4b per_page="0" is rejected as invalid_input, never silently normalized to the default',
    $j4b['success'] === false && ($j4b['data']['reason'] ?? null) === 'invalid_input', json_encode($j4b));
// Sanity: a genuinely missing per_page still gets the existing default
// (Repository's own DEFAULT_PER_PAGE) and succeeds normally.
$j4c = w6_ajax_audit([]);
w6_ok('J4c a missing per_page still defaults correctly and succeeds', $j4c['success'] === true && ($j4c['data']['per_page'] ?? null) === 20, json_encode($j4c));

// J5 (brief E) — AJAX invalid_filter fidelity: an authorized Owner sends a
// Repository-invalid audit filter VALUE (the only way a client can reach
// invalid_filter through this transport — the filter KEYS themselves are
// fixed by this handler, never client-supplied).
$j5 = w6_ajax_audit(['filter_action' => 'not_a_real_action']);
w6_ok('J5 an authorized owner\'s Repository-invalid filter value surfaces as invalid_filter, not server_error or not_authorized',
    $j5['success'] === false && ($j5['data']['reason'] ?? null) === 'invalid_filter', json_encode($j5));

// J6 (brief F) — AJAX read_unavailable fidelity: authorized Owner, schema
// not ready.
$GLOBALS['w6_schema_ready'] = false;
$j6 = w6_ajax_audit([]);
$GLOBALS['w6_schema_ready'] = true;
w6_ok('J6 an authorized owner sees read_unavailable when the schema is not ready, not server_error or not_authorized',
    $j6['success'] === false && ($j6['data']['reason'] ?? null) === 'read_unavailable', json_encode($j6));

// J7 (brief G) — AJAX database_error privacy: force a genuine post-
// authority Repository row-normalization failure and confirm the public
// response collapses to the generic server_error bucket, leaking no
// internal detail (no 'database'/'sql'/table-name substring anywhere in
// the response).
w6_audit(300, 10, 'this_action_does_not_exist_either', 'membership', 501, null, '2026-01-01 16:00:00');
$j7 = w6_ajax_audit(['per_page' => '100']);
array_pop($GLOBALS['w6_db']['audit']); // clean up the corrupt fixture row
w6_ok('J7 a genuine post-authority database_error collapses to the generic server_error bucket at the AJAX boundary',
    $j7['success'] === false && ($j7['data']['reason'] ?? null) === 'server_error', json_encode($j7));
$j7_serialized = strtolower(json_encode($j7));
w6_ok('J7b the server_error response leaks no internal detail (no database/sql/table substring)',
    strpos($j7_serialized, 'database') === false && strpos($j7_serialized, 'sql') === false && strpos($j7_serialized, 'table') === false);

// J8 (brief H) — EC1 regression at the AJAX boundary, after the parser/
// mapper changes above: an existing-but-denied event and a nonexistent
// event must still be publicly indistinguishable.
$GLOBALS['w6_current_user_id'] = $stranger_id;
$j8_existing = w6_ajax_audit(['event_id' => 300]);
$j8_nonexistent = w6_ajax_audit(['event_id' => 999999]);
$GLOBALS['w6_current_user_id'] = 10;
w6_ok('J8 EC1 still holds through the AJAX boundary: existing-denied and nonexistent event responses are byte-for-byte identical',
    $j8_existing === $j8_nonexistent
    && $j8_existing['success'] === false && ($j8_existing['data']['reason'] ?? null) === 'not_authorized',
    json_encode([$j8_existing, $j8_nonexistent]));

// J9 (brief I) — actor spoof regression, reconfirmed after this pass (D1/D2
// above already cover this; this call additionally spoofs a different
// legitimate-looking target to confirm the parser changes did not open a
// new path for a client-supplied identity field to be read).
$GLOBALS['w6_current_user_id'] = $stranger_id;
$j9 = w6_ajax_audit(['actor_user_id' => 20, 'user_id' => 20, 'owner_id' => 20, 'manager_id' => 20]);
w6_ok('J9 spoofed actor/user/owner/manager id fields still cannot alter the real (denied) requesting actor',
    $j9['success'] === false && ($j9['data']['reason'] ?? null) === 'not_authorized', json_encode($j9));
$GLOBALS['w6_current_user_id'] = 10;

// ══════════════════════════════════════════════════════════════
// Section K — filter_action/filter_entity_type must never be repaired by
// transport-layer sanitization. Repository's own strict exact-match
// AUDIT_ACTIONS/AUDIT_ENTITY_TYPES check is the sole authority.
// ══════════════════════════════════════════════════════════════

// K1 — a whitespace-wrapped, otherwise-valid-looking action must NOT be
// silently trimmed into the real "group_created" Repository would accept.
$k1 = w6_ajax_audit(['filter_action' => ' group_created ']);
w6_ok('K1 filter_action=" group_created " is rejected as invalid_filter, never silently trimmed to a valid action',
    $k1['success'] === false && ($k1['data']['reason'] ?? null) === 'invalid_filter', json_encode($k1));

// K2 — same strict behavior for entity_type.
$k2 = w6_ajax_audit(['filter_entity_type' => ' membership ']);
w6_ok('K2 filter_entity_type=" membership " is rejected as invalid_filter, never silently trimmed to a valid type',
    $k2['success'] === false && ($k2['data']['reason'] ?? null) === 'invalid_filter', json_encode($k2));

// K3 — a non-string filter_action must not be coerced into a string; the
// existing downstream contract (Repository's is_string() check) rejects it
// as invalid_filter on its own, with no new AJAX-layer handling needed.
$k3 = w6_ajax_audit(['filter_action' => ['group_created']]);
w6_ok('K3 a non-string filter_action (array) is rejected as invalid_filter, never coerced to a string',
    $k3['success'] === false && ($k3['data']['reason'] ?? null) === 'invalid_filter', json_encode($k3));

// ══════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════

echo "\n" . str_repeat('=', 60) . "\n";
echo "H1C-W6: {$passed} passed, {$failed} failed (" . ($passed + $failed) . " total)\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo " - $f\n";
}
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
