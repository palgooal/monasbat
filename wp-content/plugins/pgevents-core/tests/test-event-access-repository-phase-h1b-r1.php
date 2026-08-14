<?php
/**
 * Phase H1B-R1 — Event Access Repository Read Foundation.
 *
 * Run: php tests/test-event-access-repository-phase-h1b-r1.php
 * Uses the production repository with a contract-focused fake wpdb. No real
 * database, schema routine, WordPress authorization, or mutation is invoked.
 */

define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');

final class WP_Error
{
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }

$GLOBALS['h1br1_ready'] = true;
$GLOBALS['h1br1_ready_calls'] = 0;
$GLOBALS['h1br1_upgrade_calls'] = 0;
$GLOBALS['h1br1_post_types'] = [10 => 'pge_event'];
$GLOBALS['h1br1_post_type_calls'] = 0;
$GLOBALS['h1br1_phone_calls'] = [];

final class PGE_Event_Access_Schema
{
    public static function is_ready() { $GLOBALS['h1br1_ready_calls']++; return $GLOBALS['h1br1_ready']; }
    public static function maybe_upgrade() { $GLOBALS['h1br1_upgrade_calls']++; return true; }
}

function get_post_type($event_id) {
    $GLOBALS['h1br1_post_type_calls']++;
    return $GLOBALS['h1br1_post_types'][$event_id] ?? false;
}
function pge_event_guests_norm_phone($value) {
    $GLOBALS['h1br1_phone_calls'][] = $value;
    return preg_replace('/\D+/', '', trim((string) $value));
}

final class PGE_H1BR1_Fake_WPDB
{
    public $prefix = 'tenant7_';
    public $last_error = '';
    public $expectations = [];
    public $prepared = [];
    public $queries = [];
    public $fail_prepare = false;
    private $sequence = 0;

    public function expect($kind, array $fragments, array $args, $result, $error = '')
    {
        $this->expectations[] = compact('kind', 'fragments', 'args', 'result', 'error');
    }

    public function prepare($sql, ...$args)
    {
        if ($this->fail_prepare) {
            $this->last_error = 'synthetic prepare failure';
            return false;
        }
        $this->sequence++;
        $token = '__H1BR1_QUERY_' . $this->sequence . '__';
        $this->prepared[$token] = ['sql' => $sql, 'args' => $args];
        return $token;
    }

    public function get_results($token, $format = null) { return $this->consume($token, 'results'); }
    public function get_var($token) { return $this->consume($token, 'var'); }
    public function query($query) { $this->queries[] = $query; return 0; }

    private function consume($token, $kind)
    {
        $this->queries[] = $token;
        if (!isset($this->prepared[$token]) || !$this->expectations) {
            $this->last_error = 'unexpected query';
            return null;
        }
        $actual = $this->prepared[$token];
        $expected = array_shift($this->expectations);
        $ok = $expected['kind'] === $kind && $expected['args'] === $actual['args'];
        foreach ($expected['fragments'] as $fragment) {
            $ok = $ok && strpos($actual['sql'], $fragment) !== false;
        }
        if (!$ok) {
            $this->last_error = 'expectation mismatch';
            $GLOBALS['h1br1_expectation_failures'][] = ['expected' => $expected, 'actual' => $actual, 'kind' => $kind];
            return null;
        }
        $this->last_error = $expected['error'];
        return $expected['result'];
    }
}

require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';

$passed = 0;
$failed = 0;
$GLOBALS['h1br1_expectation_failures'] = [];

function h1br1_check($label, $condition)
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failed++;
        echo "FAIL: $label\n";
    }
}

function h1br1_code($value) { return $value instanceof WP_Error ? $value->get_error_code() : null; }

function h1br1_executed_sql($wpdb)
{
    $sql = [];
    foreach ($wpdb->queries as $query) {
        $sql[] = isset($wpdb->prepared[$query]) ? $wpdb->prepared[$query]['sql'] : $query;
    }
    return $sql;
}

function h1br1_has_forbidden_read_sql(array $sql)
{
    foreach ($sql as $statement) {
        if (!is_string($statement)
            || preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|FOR\s+UPDATE|LOCK\s+TABLES|GET_LOCK|RELEASE_LOCK)\b/i', $statement) === 1) {
            return true;
        }
    }
    return false;
}

function h1br1_fresh()
{
    global $wpdb;
    $wpdb = new PGE_H1BR1_Fake_WPDB();
    $GLOBALS['h1br1_ready'] = true;
    $GLOBALS['h1br1_ready_calls'] = 0;
    $GLOBALS['h1br1_upgrade_calls'] = 0;
    $GLOBALS['h1br1_post_types'] = [10 => 'pge_event'];
    $GLOBALS['h1br1_post_type_calls'] = 0;
    $GLOBALS['h1br1_phone_calls'] = [];
    $GLOBALS['h1br1_expectation_failures'] = [];
    return $wpdb;
}

function h1br1_group($overrides = [])
{
    return array_merge([
        'id' => '1', 'event_id' => '10', 'name' => 'Main', 'name_key' => 'main',
        'status' => 'active', 'default_slot' => '1', 'created_by_user_id' => '7',
        'created_at' => '2026-08-14 01:00:00', 'updated_at' => '2026-08-14 01:00:00',
        'archived_at' => null,
    ], $overrides);
}

function h1br1_membership($overrides = [])
{
    return array_merge([
        'id' => '2', 'event_id' => '10', 'user_id' => '20', 'role' => 'manager',
        'status' => 'active', 'created_by_user_id' => '7',
        'activated_at' => '2026-08-14 01:00:00', 'revoked_at' => null,
        'created_at' => '2026-08-14 01:00:00', 'updated_at' => '2026-08-14 01:00:00',
    ], $overrides);
}

function h1br1_assignment($overrides = [])
{
    return array_merge([
        'id' => '3', 'event_id' => '10', 'guest_phone' => '0591234567', 'group_id' => '1',
        'assigned_by_user_id' => '7', 'created_at' => '2026-08-14 01:00:00',
        'updated_at' => '2026-08-14 01:00:00', 'related_event_id' => '10',
    ], $overrides);
}

function h1br1_audit($overrides = [])
{
    return array_merge([
        'id' => '4', 'event_id' => '10', 'actor_user_id' => '7',
        'action' => 'group_created', 'entity_type' => 'group', 'entity_id' => '1',
        'metadata' => '{"source":"test"}', 'created_at' => '2026-08-14 01:00:00',
    ], $overrides);
}

function h1br1_expect_group_lookup($wpdb, $id = 1, $rows = null)
{
    $wpdb->expect('results', ['tenant7_pge_event_invitation_groups', 'event_id = %d', 'id = %d', 'LIMIT 2'], [10, $id], $rows === null ? [h1br1_group(['id' => (string) $id])] : $rows);
}

function h1br1_expect_membership_lookup($wpdb, $id = 2, $rows = null)
{
    $wpdb->expect('results', ['tenant7_pge_event_host_memberships', 'event_id = %d', 'id = %d', 'LIMIT 2'], [10, $id], $rows === null ? [h1br1_membership(['id' => (string) $id])] : $rows);
}

// Loading and readiness.
$wpdb = h1br1_fresh();
h1br1_check('repository class loads without SQL', class_exists('PGE_Event_Access_Repository') && count($wpdb->queries) === 0);
$GLOBALS['h1br1_ready'] = false;
$error = PGE_Event_Access_Repository::get_group(10, 1);
h1br1_check('schema-not-ready returns the stable error', h1br1_code($error) === 'schema_not_ready' && $error->get_error_message() === 'Event access storage is not ready.');
h1br1_check('schema-not-ready executes zero repository SQL, event lookup, and no upgrade', count($wpdb->prepared) === 0 && $GLOBALS['h1br1_post_type_calls'] === 0 && $GLOBALS['h1br1_upgrade_calls'] === 0);

$readiness_families = [
    'groups' => ['count_groups', [10]],
    'memberships' => ['list_memberships', [10]],
    'group access' => ['list_group_ids_for_membership', [10, 2]],
    'assignments' => ['map_guest_groups', [10, ['0591234567']]],
    'audit' => ['list_audit', [10]],
];
foreach ($readiness_families as $family => [$method, $args]) {
    $wpdb = h1br1_fresh();
    $GLOBALS['h1br1_ready'] = false;
    $value = call_user_func_array(['PGE_Event_Access_Repository', $method], $args);
    h1br1_check("$family reads fail closed before event lookup or SQL when schema is not ready", h1br1_code($value) === 'schema_not_ready' && count($wpdb->prepared) === 0 && $GLOBALS['h1br1_post_type_calls'] === 0);
}

// Strict IDs and event validation.
foreach (['10', 10.0, 0, -1, true, false, null, [], new stdClass()] as $invalid) {
    $wpdb = h1br1_fresh();
    h1br1_check('strict event ID rejects ' . gettype($invalid), h1br1_code(PGE_Event_Access_Repository::get_group($invalid, 1)) === 'invalid_input' && count($wpdb->prepared) === 0);
}
foreach (['1', 1.0, 0, -1, true, null, [], new stdClass()] as $invalid) {
    $wpdb = h1br1_fresh();
    h1br1_check('strict group ID rejects ' . gettype($invalid), h1br1_code(PGE_Event_Access_Repository::get_group(10, $invalid)) === 'invalid_input' && count($wpdb->prepared) === 0);
}
$wpdb = h1br1_fresh();
$GLOBALS['h1br1_post_types'][10] = 'post';
$wrong_type = PGE_Event_Access_Repository::get_group(10, 1);
$wpdb2 = h1br1_fresh();
unset($GLOBALS['h1br1_post_types'][10]);
$missing_event = PGE_Event_Access_Repository::get_group(10, 1);
h1br1_check('missing and wrong-type events share generic not-found', h1br1_code($wrong_type) === 'not_found' && h1br1_code($missing_event) === 'not_found' && $wrong_type->get_error_message() === $missing_event->get_error_message());

// Group reads, normalization, scope, and database failures.
$wpdb = h1br1_fresh();
h1br1_expect_group_lookup($wpdb);
$group = PGE_Event_Access_Repository::get_group(10, 1);
h1br1_check('get_group returns fixed typed shape', is_array($group) && $group['id'] === 1 && $group['event_id'] === 10 && $group['is_default'] === true && array_keys($group) === ['id','event_id','name','name_key','status','is_default','created_by_user_id','created_at','updated_at','archived_at']);
h1br1_check('custom prefix and event scope are enforced by fake expectation', !$GLOBALS['h1br1_expectation_failures']);
$wpdb = h1br1_fresh();
$wpdb->last_error = 'preexisting diagnostic';
h1br1_expect_group_lookup($wpdb);
$restored_state_group = PGE_Event_Access_Repository::get_group(10, 1);
h1br1_check('successful query restores preexisting wpdb error state', is_array($restored_state_group) && $wpdb->last_error === 'preexisting diagnostic');

$wpdb = h1br1_fresh();
h1br1_expect_group_lookup($wpdb, 99, []);
h1br1_check('required group missing returns not_found', h1br1_code(PGE_Event_Access_Repository::get_group(10, 99)) === 'not_found');
$wpdb = h1br1_fresh();
h1br1_expect_group_lookup($wpdb, 1, [h1br1_group(), h1br1_group(['id' => '2'])]);
h1br1_check('duplicate required group rows fail as database error', h1br1_code(PGE_Event_Access_Repository::get_group(10, 1)) === 'database_error');
$wpdb = h1br1_fresh();
h1br1_expect_group_lookup($wpdb, 1, [h1br1_group(['event_id' => '11'])]);
h1br1_check('row event mismatch returns cross_event', h1br1_code(PGE_Event_Access_Repository::get_group(10, 1)) === 'cross_event');
$wpdb = h1br1_fresh();
h1br1_expect_group_lookup($wpdb, 1, [h1br1_group(['status' => 'deleted'])]);
h1br1_check('corrupt group enum fails closed', h1br1_code(PGE_Event_Access_Repository::get_group(10, 1)) === 'database_error');
$wpdb = h1br1_fresh();
$bad_group = h1br1_group(); unset($bad_group['archived_at']);
h1br1_expect_group_lookup($wpdb, 1, [$bad_group]);
h1br1_check('missing nullable group field is malformed', h1br1_code(PGE_Event_Access_Repository::get_group(10, 1)) === 'database_error');

$group_lifecycle_cases = [
    'active group without default is valid' => [
        ['default_slot' => null, 'archived_at' => null], true,
    ],
    'active default group is valid' => [
        ['default_slot' => '1', 'archived_at' => null], true,
    ],
    'active group with archived timestamp is malformed' => [
        ['archived_at' => '2026-08-14 12:00:00'], false,
    ],
    'archived group with timestamp is valid' => [
        ['status' => 'archived', 'name_key' => null, 'default_slot' => null, 'archived_at' => '2026-08-14 12:00:00'], true,
    ],
    'archived group without timestamp is malformed' => [
        ['status' => 'archived', 'name_key' => null, 'default_slot' => null, 'archived_at' => null], false,
    ],
    'archived group with empty timestamp is malformed' => [
        ['status' => 'archived', 'name_key' => null, 'default_slot' => null, 'archived_at' => ''], false,
    ],
    'archived group with name key is malformed' => [
        ['status' => 'archived', 'name_key' => 'archived', 'default_slot' => null, 'archived_at' => '2026-08-14 12:00:00'], false,
    ],
    'archived group with default slot is malformed' => [
        ['status' => 'archived', 'name_key' => null, 'default_slot' => '1', 'archived_at' => '2026-08-14 12:00:00'], false,
    ],
];
foreach ($group_lifecycle_cases as $label => [$overrides, $valid]) {
    $wpdb = h1br1_fresh();
    h1br1_expect_group_lookup($wpdb, 1, [h1br1_group($overrides)]);
    $value = PGE_Event_Access_Repository::get_group(10, 1);
    h1br1_check($label, $valid ? is_array($value) : h1br1_code($value) === 'database_error');
}
$wpdb = h1br1_fresh();
$wpdb->fail_prepare = true;
h1br1_check('prepare failure returns database_error', h1br1_code(PGE_Event_Access_Repository::get_group(10, 1)) === 'database_error');
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['tenant7_pge_event_invitation_groups', 'event_id = %d'], [10, 1], [], 'secret database detail');
$db_error = PGE_Event_Access_Repository::get_group(10, 1);
h1br1_check('query failure is distinct from not-found and hides raw DB error', h1br1_code($db_error) === 'database_error' && strpos($db_error->get_error_message(), 'secret') === false && $wpdb->last_error === '');
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['tenant7_pge_event_invitation_groups'], [10, 1], new stdClass());
h1br1_check('malformed result collection fails closed', h1br1_code(PGE_Event_Access_Repository::get_group(10, 1)) === 'database_error');

// Group optional reads, filters, count, and pagination.
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['default_slot = %d', 'status = %s', 'LIMIT 2'], [10, 1, 'active'], [], '');
h1br1_check('missing default group is null', PGE_Event_Access_Repository::get_default_group(10) === null);
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['default_slot = %d', 'status = %s', 'LIMIT 2'], [10, 1, 'active'], [h1br1_group(), h1br1_group(['id' => '2'])]);
h1br1_check('duplicate default groups fail closed', h1br1_code(PGE_Event_Access_Repository::get_default_group(10)) === 'database_error');
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['name_key = %s', 'status = %s', 'LIMIT 2'], [10, 'main', 'active'], [h1br1_group()]);
h1br1_check('active name-key lookup trims input and returns a group', is_array(PGE_Event_Access_Repository::find_active_group_by_name_key(10, ' main ')));
$wpdb = h1br1_fresh();
h1br1_check('empty and overlong name keys are invalid', h1br1_code(PGE_Event_Access_Repository::find_active_group_by_name_key(10, ' ')) === 'invalid_input' && h1br1_code(PGE_Event_Access_Repository::find_active_group_by_name_key(10, str_repeat('x', 161))) === 'invalid_input');

$wpdb = h1br1_fresh();
$wpdb->expect('var', ['SELECT COUNT(*)', 'event_id = %d', 'status = %s'], [10, 'active'], '2');
$wpdb->expect('results', ['event_id = %d', 'status = %s', 'ORDER BY id ASC', 'LIMIT %d OFFSET %d'], [10, 'active', 2, 2], [h1br1_group(['id' => '2', 'default_slot' => null])]);
$groups = PGE_Event_Access_Repository::list_groups(10, ['status' => 'active'], 2, 2);
h1br1_check('group pagination has stable shape and bounded offset', is_array($groups) && $groups['page'] === 2 && $groups['per_page'] === 2 && $groups['total'] === 2 && $groups['total_pages'] === 1 && count($groups['items']) === 1);
h1br1_check('group pagination SQL is prepared, scoped, filtered, and ordered', !$GLOBALS['h1br1_expectation_failures']);
$wpdb = h1br1_fresh();
$wpdb->expect('var', ['tenant7_pge_event_invitation_groups'], [10], '0');
$wpdb->expect('results', ['ORDER BY id ASC', 'LIMIT %d OFFSET %d'], [10, 20, 0], []);
$empty_groups = PGE_Event_Access_Repository::list_groups(10);
h1br1_check('empty pagination is a valid list with zero total pages', $empty_groups === ['items' => [], 'page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0]);
$wpdb = h1br1_fresh();
$wpdb->expect('var', ['tenant7_pge_event_invitation_groups'], [10], '1');
$wpdb->expect('results', ['ORDER BY id ASC'], [10, 20, 0], null, 'items failed');
h1br1_check('items-query failure after a valid count returns database_error', h1br1_code(PGE_Event_Access_Repository::list_groups(10)) === 'database_error');
$wpdb = h1br1_fresh();
$wpdb->expect('var', ['tenant7_pge_event_invitation_groups'], [10], '2');
$wpdb->expect('results', ['ORDER BY id ASC'], [10, 20, 0], [h1br1_group(), h1br1_group(['id' => '2', 'event_id' => '11'])]);
h1br1_check('one cross-event row fails the complete paginated list', h1br1_code(PGE_Event_Access_Repository::list_groups(10)) === 'cross_event');
$wpdb = h1br1_fresh();
$wpdb->expect('var', ['tenant7_pge_event_invitation_groups'], [10], '2');
$wpdb->expect('results', ['ORDER BY id ASC'], [10, 20, 0], [
    h1br1_group(),
    h1br1_group(['id' => '2', 'status' => 'archived', 'name_key' => null, 'default_slot' => null, 'archived_at' => null]),
]);
h1br1_check('one archived row without timestamp fails the complete paginated list', h1br1_code(PGE_Event_Access_Repository::list_groups(10)) === 'database_error');

foreach ([[['unknown' => 'x'], 1, 20], [['status' => 'deleted'], 1, 20], [[], '1', 20], [[], 1, 101], [[], PHP_INT_MAX, 2]] as $case) {
    [$filters, $page, $per_page] = $case;
    $wpdb = h1br1_fresh();
    $value = PGE_Event_Access_Repository::list_groups(10, $filters, $page, $per_page);
    $expected = $filters && (isset($filters['unknown']) || isset($filters['status'])) ? 'invalid_filter' : 'invalid_input';
    h1br1_check('invalid group filter or pagination fails before SQL', h1br1_code($value) === $expected && count($wpdb->prepared) === 0);
}
$wpdb = h1br1_fresh();
$wpdb->expect('var', ['SELECT COUNT(*)', 'event_id = %d'], [10], null, 'count failed');
$wpdb->expect('results', ['ORDER BY id ASC'], [10, 20, 0], []);
h1br1_check('count failure stops paginated item query', h1br1_code(PGE_Event_Access_Repository::list_groups(10)) === 'database_error' && count($wpdb->expectations) === 1);
$wpdb = h1br1_fresh();
$wpdb->expect('var', ['tenant7_pge_event_invitation_groups', 'status = %s'], [10, 'archived'], '7');
h1br1_check('count_groups returns a nonnegative integer', PGE_Event_Access_Repository::count_groups(10, 'archived') === 7);
$wpdb = h1br1_fresh();
$wpdb->expect('var', ['tenant7_pge_event_invitation_groups'], [10], '-1');
h1br1_check('malformed negative count fails closed', h1br1_code(PGE_Event_Access_Repository::count_groups(10)) === 'database_error');
foreach (['01', (string) PHP_INT_MAX . '0'] as $malformed_count) {
    $wpdb = h1br1_fresh();
    $wpdb->expect('var', ['tenant7_pge_event_invitation_groups'], [10], $malformed_count);
    h1br1_check('noncanonical or overflowing count fails closed', h1br1_code(PGE_Event_Access_Repository::count_groups(10)) === 'database_error');
}

// Membership reads and pagination.
$wpdb = h1br1_fresh();
h1br1_expect_membership_lookup($wpdb);
$membership = PGE_Event_Access_Repository::get_membership(10, 2);
h1br1_check('membership row is normalized to fixed types', is_array($membership) && $membership['id'] === 2 && $membership['user_id'] === 20 && $membership['role'] === 'manager');
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['event_id = %d', 'user_id = %d', 'LIMIT 2'], [10, 20], [], '');
h1br1_check('optional membership-for-user missing is null', PGE_Event_Access_Repository::get_membership_for_user(10, 20) === null);
$wpdb = h1br1_fresh();
h1br1_check('membership user ID remains a strict PHP integer', h1br1_code(PGE_Event_Access_Repository::get_membership_for_user(10, '20')) === 'invalid_input' && count($wpdb->prepared) === 0);
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['user_id = %d', 'LIMIT 2'], [10, 20], [h1br1_membership(), h1br1_membership(['id' => '3'])]);
h1br1_check('duplicate membership-for-user rows fail closed', h1br1_code(PGE_Event_Access_Repository::get_membership_for_user(10, 20)) === 'database_error');
$wpdb = h1br1_fresh();
$wpdb->expect('var', ['tenant7_pge_event_host_memberships', 'status = %s', 'role = %s'], [10, 'active', 'viewer'], '1');
$wpdb->expect('results', ['status = %s', 'role = %s', 'ORDER BY id ASC'], [10, 'active', 'viewer', 20, 0], [h1br1_membership(['role' => 'viewer'])]);
$memberships = PGE_Event_Access_Repository::list_memberships(10, ['status' => 'active', 'role' => 'viewer']);
h1br1_check('membership filters and ordering are prepared', is_array($memberships) && count($memberships['items']) === 1 && !$GLOBALS['h1br1_expectation_failures']);
$wpdb = h1br1_fresh();
h1br1_check('unknown membership filters are rejected', h1br1_code(PGE_Event_Access_Repository::list_memberships(10, ['user_id' => 20])) === 'invalid_filter');

// Group-access relation reads.
$wpdb = h1br1_fresh();
h1br1_expect_membership_lookup($wpdb);
$wpdb->expect('results', ['tenant7_pge_event_host_group_access a', 'a.event_id = %d', 'a.membership_id = %d', 'ORDER BY a.group_id ASC'], [10, 2], [
    ['event_id' => '10', 'group_id' => '1', 'related_event_id' => '10'],
    ['event_id' => '10', 'group_id' => '3', 'related_event_id' => '10'],
]);
$group_ids = PGE_Event_Access_Repository::list_group_ids_for_membership(10, 2);
h1br1_check('membership group IDs are ordered typed integers', $group_ids === [1, 3] && !$GLOBALS['h1br1_expectation_failures']);
$wpdb = h1br1_fresh();
h1br1_expect_membership_lookup($wpdb);
$wpdb->expect('results', ['a.membership_id = %d'], [10, 2], [
    ['event_id' => '10', 'group_id' => '1', 'related_event_id' => '10'],
    ['event_id' => '10', 'group_id' => '1', 'related_event_id' => '10'],
]);
h1br1_check('duplicate relation IDs fail closed', h1br1_code(PGE_Event_Access_Repository::list_group_ids_for_membership(10, 2)) === 'database_error');
$wpdb = h1br1_fresh();
h1br1_expect_group_lookup($wpdb);
$wpdb->expect('results', ['a.group_id = %d', 'ORDER BY a.membership_id ASC'], [10, 1], [['event_id' => '10', 'membership_id' => '2', 'related_event_id' => '11']]);
h1br1_check('related entity event mismatch is cross_event', h1br1_code(PGE_Event_Access_Repository::list_membership_ids_for_group(10, 1)) === 'cross_event');

$wpdb = h1br1_fresh();
h1br1_expect_membership_lookup($wpdb);
h1br1_expect_group_lookup($wpdb);
$wpdb->expect('results', ['event_id = %d', 'membership_id = %d', 'group_id = %d', 'LIMIT 2'], [10, 2, 1], [], '');
h1br1_check('missing access relation returns false, not null', PGE_Event_Access_Repository::membership_has_group_access(10, 2, 1) === false);
$wpdb = h1br1_fresh();
h1br1_expect_membership_lookup($wpdb);
h1br1_expect_group_lookup($wpdb);
$wpdb->expect('results', ['event_id = %d', 'membership_id = %d', 'group_id = %d'], [10, 2, 1], [['event_id' => '10', 'membership_id' => '2', 'group_id' => '1']]);
h1br1_check('existing access relation returns true', PGE_Event_Access_Repository::membership_has_group_access(10, 2, 1) === true);

// Guest assignments and bounded phone map.
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['tenant7_pge_invitation_group_assignments a', 'a.event_id = %d', 'a.guest_phone = %s', 'LIMIT 2'], [10, '0591234567'], [h1br1_assignment()]);
$assignment = PGE_Event_Access_Repository::get_guest_assignment(10, '059-123-4567');
h1br1_check('guest assignment uses the canonical phone normalizer and fixed shape', is_array($assignment) && $assignment['guest_phone'] === '0591234567' && $GLOBALS['h1br1_phone_calls'][0] === '059-123-4567');
$wpdb = h1br1_fresh();
$bad_phone = PGE_Event_Access_Repository::get_guest_assignment(10, []);
h1br1_check('invalid phone error contains no raw phone data', h1br1_code($bad_phone) === 'invalid_input' && $bad_phone->get_error_message() === 'Invalid repository input.');
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['a.guest_phone = %s'], [10, '0591234567'], [h1br1_assignment(['related_event_id' => '11'])]);
h1br1_check('assignment group scope mismatch is cross_event', h1br1_code(PGE_Event_Access_Repository::get_guest_assignment(10, '0591234567')) === 'cross_event');

$wpdb = h1br1_fresh();
h1br1_expect_group_lookup($wpdb);
$wpdb->expect('var', ['tenant7_pge_invitation_group_assignments a', 'a.event_id = %d', 'a.group_id = %d'], [10, 1], '1');
$wpdb->expect('results', ['a.event_id = %d', 'a.group_id = %d', 'ORDER BY a.id ASC', 'LIMIT %d OFFSET %d'], [10, 1, 20, 0], [h1br1_assignment()]);
$assignments = PGE_Event_Access_Repository::list_group_assignments(10, 1);
h1br1_check('assignment pagination proves group scope and returns stable shape', is_array($assignments) && $assignments['total'] === 1 && count($assignments['items']) === 1 && !$GLOBALS['h1br1_expectation_failures']);
$wpdb = h1br1_fresh();
h1br1_expect_group_lookup($wpdb);
$wpdb->expect('var', ['tenant7_pge_invitation_group_assignments', 'event_id = %d', 'group_id = %d'], [10, 1], '3');
h1br1_check('count_group_assignments returns integer count', PGE_Event_Access_Repository::count_group_assignments(10, 1) === 3);

$wpdb = h1br1_fresh();
$wpdb->expect('results', ['guest_phone IN (%s, %s)', 'a.event_id = %d', 'ORDER BY a.id ASC'], [10, '059123', '056999'], [
    h1br1_assignment(['guest_phone' => '056999', 'group_id' => '2']),
    h1br1_assignment(['id' => '4', 'guest_phone' => '059123', 'group_id' => '1']),
]);
$map = PGE_Event_Access_Repository::map_guest_groups(10, ['059-123', '059123', '056-999']);
h1br1_check('bounded map returns a typed list, deduplicates, preserves input order despite DB order, and uses one query', $map === [
    ['guest_phone' => '059123', 'group_id' => 1],
    ['guest_phone' => '056999', 'group_id' => 2],
] && array_keys($map) === [0, 1] && is_string($map[0]['guest_phone']) && is_string($map[1]['guest_phone']) && is_int($map[0]['group_id']) && is_int($map[1]['group_id']) && count($wpdb->queries) === 1 && !$GLOBALS['h1br1_expectation_failures']);
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['guest_phone IN (%s)', 'a.event_id = %d'], [10, '972599123456'], [
    h1br1_assignment(['guest_phone' => '972599123456']),
]);
$country_map = PGE_Event_Access_Repository::map_guest_groups(10, ['972599123456']);
h1br1_check('all-digit phone without leading zero remains a string in the public list', $country_map === [[
    'guest_phone' => '972599123456',
    'group_id' => 1,
]] && array_is_list($country_map) && is_string($country_map[0]['guest_phone']));
$wpdb = h1br1_fresh();
$large_phone = '99999999999999999999999999999999';
$wpdb->expect('results', ['guest_phone IN (%s)', 'a.event_id = %d'], [10, $large_phone], [
    h1br1_assignment(['guest_phone' => $large_phone]),
]);
$large_phone_map = PGE_Event_Access_Repository::map_guest_groups(10, [$large_phone]);
h1br1_check('all-digit phone above PHP integer range remains an exact string', is_array($large_phone_map) && array_is_list($large_phone_map) && $large_phone_map[0]['guest_phone'] === $large_phone && is_string($large_phone_map[0]['guest_phone']));
$wpdb = h1br1_fresh();
h1br1_check('empty phone list returns empty map without repository SQL', PGE_Event_Access_Repository::map_guest_groups(10, []) === [] && count($wpdb->queries) === 0);
$wpdb = h1br1_fresh();
h1br1_check('associative and oversized phone batches are rejected', h1br1_code(PGE_Event_Access_Repository::map_guest_groups(10, ['phone' => '059'])) === 'invalid_input' && h1br1_code(PGE_Event_Access_Repository::map_guest_groups(10, array_fill(0, 201, '059'))) === 'invalid_input');
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['guest_phone IN (%s)'], [10, '059123'], [
    h1br1_assignment(['guest_phone' => '059123']), h1br1_assignment(['id' => '4', 'guest_phone' => '059123']),
]);
h1br1_check('duplicate assignment rows in map fail closed', h1br1_code(PGE_Event_Access_Repository::map_guest_groups(10, ['059123'])) === 'database_error');
$wpdb = h1br1_fresh();
$wpdb->expect('results', ['guest_phone IN (%s)'], [10, '059123'], [h1br1_assignment(['guest_phone' => '056999'])]);
h1br1_check('map rejects a DB row outside the requested phone set', h1br1_code(PGE_Event_Access_Repository::map_guest_groups(10, ['059123'])) === 'database_error');
foreach ([
    h1br1_assignment(['guest_phone' => 'bad-phone']),
    h1br1_assignment(['group_id' => '0']),
] as $malformed_assignment) {
    $wpdb = h1br1_fresh();
    $wpdb->expect('results', ['guest_phone IN (%s)'], [10, '059123'], [$malformed_assignment]);
    h1br1_check('map rejects malformed stored phone or group ID without exposing it', h1br1_code(PGE_Event_Access_Repository::map_guest_groups(10, ['059123'])) === 'database_error');
}

// Audit filters, ordering, and JSON normalization.
$wpdb = h1br1_fresh();
$filters = ['action' => 'group_created', 'entity_type' => 'group', 'actor_user_id' => 7, 'entity_id' => 1];
$wpdb->expect('var', ['tenant7_pge_event_access_audit_log', 'action = %s', 'entity_type = %s', 'actor_user_id = %d', 'entity_id = %d'], [10, 'group_created', 'group', 7, 1], '1');
$wpdb->expect('results', ['ORDER BY created_at DESC, id DESC', 'LIMIT %d OFFSET %d'], [10, 'group_created', 'group', 7, 1, 20, 0], [h1br1_audit()]);
$audit = PGE_Event_Access_Repository::list_audit(10, $filters);
h1br1_check('audit read filters and ordering are deterministic', is_array($audit) && $audit['items'][0]['metadata'] === ['source' => 'test'] && !$GLOBALS['h1br1_expectation_failures']);
foreach (['{"broken"', '"scalar"', 'null'] as $metadata) {
    $wpdb = h1br1_fresh();
    $wpdb->expect('var', ['tenant7_pge_event_access_audit_log'], [10], '1');
    $wpdb->expect('results', ['ORDER BY created_at DESC, id DESC'], [10, 20, 0], [h1br1_audit(['metadata' => $metadata])]);
    h1br1_check('invalid or scalar audit JSON fails closed', h1br1_code(PGE_Event_Access_Repository::list_audit(10)) === 'database_error');
}
$wpdb = h1br1_fresh();
$wpdb->expect('var', ['tenant7_pge_event_access_audit_log'], [10], '1');
$wpdb->expect('results', ['ORDER BY created_at DESC, id DESC'], [10, 20, 0], [h1br1_audit([
    'metadata' => str_repeat('[', 513) . '0' . str_repeat(']', 513),
])]);
h1br1_check('audit JSON beyond the decoder depth fails closed without a fatal error', h1br1_code(PGE_Event_Access_Repository::list_audit(10)) === 'database_error');
$wpdb = h1br1_fresh();
h1br1_check('unknown or unapproved audit filters are rejected', h1br1_code(PGE_Event_Access_Repository::list_audit(10, ['unknown' => 'x'])) === 'invalid_filter' && h1br1_code(PGE_Event_Access_Repository::list_audit(10, ['action' => 'custom_action'])) === 'invalid_filter');
$wpdb = h1br1_fresh();
h1br1_check('audit actor and entity IDs remain strict PHP integers', h1br1_code(PGE_Event_Access_Repository::list_audit(10, ['actor_user_id' => '7'])) === 'invalid_filter' && h1br1_code(PGE_Event_Access_Repository::list_audit(10, ['entity_id' => 1.0])) === 'invalid_filter' && count($wpdb->prepared) === 0);

// Read/write separation: invoke every production read method independently,
// reset the SQL recorder for every case, and inspect the SQL that actually ran.
$read_cases = [
    'get_group' => function () { $db = h1br1_fresh(); h1br1_expect_group_lookup($db); return [$db, PGE_Event_Access_Repository::get_group(10, 1)]; },
    'list_groups' => function () { $db = h1br1_fresh(); $db->expect('var', ['SELECT COUNT(*)'], [10], '1'); $db->expect('results', ['ORDER BY id ASC'], [10, 20, 0], [h1br1_group()]); return [$db, PGE_Event_Access_Repository::list_groups(10)]; },
    'get_default_group' => function () { $db = h1br1_fresh(); $db->expect('results', ['default_slot = %d'], [10, 1, 'active'], []); return [$db, PGE_Event_Access_Repository::get_default_group(10)]; },
    'find_active_group_by_name_key' => function () { $db = h1br1_fresh(); $db->expect('results', ['name_key = %s'], [10, 'main', 'active'], []); return [$db, PGE_Event_Access_Repository::find_active_group_by_name_key(10, 'main')]; },
    'count_groups' => function () { $db = h1br1_fresh(); $db->expect('var', ['SELECT COUNT(*)'], [10], '0'); return [$db, PGE_Event_Access_Repository::count_groups(10)]; },
    'get_membership' => function () { $db = h1br1_fresh(); h1br1_expect_membership_lookup($db); return [$db, PGE_Event_Access_Repository::get_membership(10, 2)]; },
    'get_membership_for_user' => function () { $db = h1br1_fresh(); $db->expect('results', ['user_id = %d'], [10, 20], []); return [$db, PGE_Event_Access_Repository::get_membership_for_user(10, 20)]; },
    'list_memberships' => function () { $db = h1br1_fresh(); $db->expect('var', ['SELECT COUNT(*)'], [10], '0'); $db->expect('results', ['ORDER BY id ASC'], [10, 20, 0], []); return [$db, PGE_Event_Access_Repository::list_memberships(10)]; },
    'list_group_ids_for_membership' => function () { $db = h1br1_fresh(); h1br1_expect_membership_lookup($db); $db->expect('results', ['pge_event_host_group_access'], [10, 2], []); return [$db, PGE_Event_Access_Repository::list_group_ids_for_membership(10, 2)]; },
    'list_membership_ids_for_group' => function () { $db = h1br1_fresh(); h1br1_expect_group_lookup($db); $db->expect('results', ['pge_event_host_group_access'], [10, 1], []); return [$db, PGE_Event_Access_Repository::list_membership_ids_for_group(10, 1)]; },
    'membership_has_group_access' => function () { $db = h1br1_fresh(); h1br1_expect_membership_lookup($db); h1br1_expect_group_lookup($db); $db->expect('results', ['membership_id = %d', 'group_id = %d'], [10, 2, 1], []); return [$db, PGE_Event_Access_Repository::membership_has_group_access(10, 2, 1)]; },
    'get_guest_assignment' => function () { $db = h1br1_fresh(); $db->expect('results', ['guest_phone = %s'], [10, '0591234567'], []); return [$db, PGE_Event_Access_Repository::get_guest_assignment(10, '0591234567')]; },
    'list_group_assignments' => function () { $db = h1br1_fresh(); h1br1_expect_group_lookup($db); $db->expect('var', ['SELECT COUNT(*)'], [10, 1], '0'); $db->expect('results', ['ORDER BY a.id ASC'], [10, 1, 20, 0], []); return [$db, PGE_Event_Access_Repository::list_group_assignments(10, 1)]; },
    'count_group_assignments' => function () { $db = h1br1_fresh(); h1br1_expect_group_lookup($db); $db->expect('var', ['SELECT COUNT(*)'], [10, 1], '0'); return [$db, PGE_Event_Access_Repository::count_group_assignments(10, 1)]; },
    'map_guest_groups' => function () { $db = h1br1_fresh(); $db->expect('results', ['guest_phone IN (%s)'], [10, '059123'], []); return [$db, PGE_Event_Access_Repository::map_guest_groups(10, ['059123'])]; },
    'list_audit' => function () { $db = h1br1_fresh(); $db->expect('var', ['SELECT COUNT(*)'], [10], '0'); $db->expect('results', ['ORDER BY created_at DESC'], [10, 20, 0], []); return [$db, PGE_Event_Access_Repository::list_audit(10)]; },
];
$read_only_ok = true;
foreach ($read_cases as $case) {
    [$case_db, $case_result] = $case();
    $read_only_ok = $read_only_ok && !($case_result instanceof WP_Error)
        && $case_db->expectations === []
        && !h1br1_has_forbidden_read_sql(h1br1_executed_sql($case_db));
}
$readiness_db = h1br1_fresh();
$GLOBALS['h1br1_ready'] = false;
PGE_Event_Access_Repository::get_group(10, 1);
$read_only_ok = $read_only_ok && h1br1_executed_sql($readiness_db) === [];

// Mutation proof for the SQL classifier: representative direct, indirect,
// mid-pagination, audit, transaction, and locking statements are all caught.
$mutation_guard_ok = true;
foreach ([
    'UPDATE x SET y = 1',
    'START TRANSACTION',
    'SELECT * FROM x FOR UPDATE',
    'INSERT INTO audit_log (id) VALUES (1)',
    'DELETE FROM x',
    'SELECT GET_LOCK("x", 0)',
] as $forbidden_probe) {
    $probe = h1br1_fresh();
    $probe->query($forbidden_probe);
    $mutation_guard_ok = $mutation_guard_ok && h1br1_has_forbidden_read_sql(h1br1_executed_sql($probe));
}

// Static boundary guards.
$source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-repository.php');
$loader = file_get_contents(PGE_PATH . 'pgevents-core.php');
h1br1_check('loader requires the repository exactly once after the schema', substr_count($loader, "require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';") === 1 && strpos($loader, 'class-pge-event-access-schema.php') < strpos($loader, 'class-pge-event-access-repository.php'));
h1br1_check('repository source contains no authorization, post-meta, advisory-lock, hook, or schema-upgrade calls', preg_match('/current_user_can|get_current_user_id|check_ajax_referer|wp_verify_nonce|pge_is_host_or_admin|pge_event_guests_user_can_manage|get_post_meta|update_post_meta|delete_post_meta|GET_LOCK|RELEASE_LOCK|LOCK\s+TABLES|add_action\s*\(|dbDelta\s*\(|maybe_upgrade\s*\(/i', $source) === 0);
h1br1_check('all sixteen public reads execute read-only SQL and the mutation classifier detects forbidden probes', $read_only_ok && $mutation_guard_ok);
h1br1_check('all SQL table names are derived from the runtime prefix', strpos($source, 'wp_pge_') === false && strpos($source, '$wpdb->prefix') !== false);
h1br1_check('repository never invoked schema upgrade during the suite', $GLOBALS['h1br1_upgrade_calls'] === 0);
h1br1_check('fake SQL expectations all matched query scope and parameters', !$GLOBALS['h1br1_expectation_failures']);

echo "\nH1B-R1: {$passed}/" . ($passed + $failed) . " passed\n";
exit($failed === 0 ? 0 : 1);
