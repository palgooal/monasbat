<?php
/** Phase H1B-W3 — transactional guest-assignment writes. No real DB. */
define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

final class WP_Error {
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }

$GLOBALS['w3_ready'] = true;
$GLOBALS['w3_posts'] = [10 => 'pge_event'];
$GLOBALS['w3_post_type_calls'] = 0;
$GLOBALS['w3_meta_calls'] = 0;
$GLOBALS['w3_meta'] = [];
$GLOBALS['w3_json_fail'] = false;
$GLOBALS['w3_current_user_calls'] = 0;

final class PGE_Event_Access_Schema {
    public static function is_ready() { return $GLOBALS['w3_ready']; }
    public static function maybe_upgrade() { throw new RuntimeException('upgrade forbidden'); }
}
function get_post_type($id) { $GLOBALS['w3_post_type_calls']++; return $GLOBALS['w3_posts'][$id] ?? false; }
function get_post_meta($event_id, $key, $single = false) {
    $GLOBALS['w3_meta_calls']++;
    return $GLOBALS['w3_meta'][$event_id][$key] ?? '';
}
function get_current_user_id() { $GLOBALS['w3_current_user_calls']++; return 999; }
function current_time($type, $gmt = false) { return '2026-08-16 12:00:00'; }
function wp_json_encode($value) { return $GLOBALS['w3_json_fail'] ? false : json_encode($value); }
function add_action($hook, $callback) { /* Production callbacks are not executed by this harness. */ }

final class PGE_H1BW3_WPDB
{
    public $prefix = 'tenant7_';
    public $last_error = '';
    public $insert_id = 0;
    public $groups = [];
    public $assignments = [];
    public $audits = [];
    public $sql = [];
    public $next = [];
    public $next_results = [];
    public $fail = [];
    public $throw = [];
    public $insert_id_override = null;
    public $race_on_insert = false;
    private $prepared = [];
    private $sequence = 0;
    private $snapshot = null;

    public function prepare($sql, ...$args) {
        $token = '__W3_' . (++$this->sequence) . '__';
        $this->prepared[$token] = [$sql, $args];
        return $token;
    }
    private function resolve($query) { return $this->prepared[$query] ?? [$query, []]; }
    private function mutation_op($sql) {
        if ($sql === 'START TRANSACTION') return 'begin';
        if ($sql === 'COMMIT') return 'commit';
        if ($sql === 'ROLLBACK') return 'rollback';
        if (strpos($sql, 'INSERT INTO tenant7_pge_invitation_group_assignments') === 0) return 'insert_assignment';
        if (strpos($sql, 'UPDATE tenant7_pge_invitation_group_assignments SET group_id') === 0) return 'move_assignment';
        if (strpos($sql, 'DELETE FROM tenant7_pge_invitation_group_assignments') === 0) return 'delete_assignment';
        if (strpos($sql, 'INSERT INTO tenant7_pge_event_access_audit_log') === 0) return 'insert_audit';
        return 'unknown';
    }
    private function read_op($sql) {
        if (strpos($sql, 'tenant7_pge_event_invitation_groups') !== false && strpos($sql, 'ORDER BY id FOR UPDATE') !== false) return 'lock_groups';
        if (strpos($sql, 'tenant7_pge_invitation_group_assignments') !== false && strpos($sql, 'FOR UPDATE') !== false) return 'lock_assignment';
        if (strpos($sql, 'tenant7_pge_invitation_group_assignments') !== false) return 'read_assignment';
        if (strpos($sql, 'SELECT event_id FROM tenant7_pge_event_invitation_groups') === 0) return 'lookup_group';
        return 'unknown_read';
    }
    public function query($query) {
        [$sql, $args] = $this->resolve($query);
        $this->sql[] = $sql;
        $op = $this->mutation_op($sql);
        if (!empty($this->throw[$op])) throw new RuntimeException('synthetic throwable');
        if (array_key_exists($op, $this->next) && $this->next[$op] !== []) return array_shift($this->next[$op]);
        if (!empty($this->fail[$op])) { $this->last_error = 'private database detail'; return false; }
        $this->last_error = '';
        if ($op === 'begin') { $this->snapshot = [$this->assignments, $this->audits, $this->insert_id]; return 0; }
        if ($op === 'rollback') { if ($this->snapshot !== null) [$this->assignments, $this->audits, $this->insert_id] = $this->snapshot; $this->snapshot = null; return 0; }
        if ($op === 'commit') { $this->snapshot = null; return 0; }
        if ($op === 'insert_assignment') {
            if ($this->race_on_insert) {
                $this->assignments[99] = w3_assignment(99, $args[1], $args[2], 88);
                $this->last_error = 'duplicate private detail';
                return false;
            }
            $id = $this->assignments ? max(array_keys($this->assignments)) + 1 : 1;
            $this->assignments[$id] = w3_assignment($id, $args[1], $args[2], $args[3], $args[4], $args[5]);
            $this->insert_id = $this->insert_id_override !== null ? $this->insert_id_override : $id;
            return 1;
        }
        if ($op === 'move_assignment') {
            [$new_group, $actor, $now, $id, $event, $phone, $expected] = $args;
            if (!isset($this->assignments[$id])) return 0;
            $row = $this->assignments[$id];
            if ((int) $row['event_id'] !== $event || $row['guest_phone'] !== $phone || (int) $row['group_id'] !== $expected) return 0;
            $this->assignments[$id]['group_id'] = (string) $new_group;
            $this->assignments[$id]['assigned_by_user_id'] = (string) $actor;
            $this->assignments[$id]['updated_at'] = $now;
            return 1;
        }
        if ($op === 'delete_assignment') {
            [$id, $event, $phone, $expected] = $args;
            if (!isset($this->assignments[$id])) return 0;
            $row = $this->assignments[$id];
            if ((int) $row['event_id'] !== $event || $row['guest_phone'] !== $phone || (int) $row['group_id'] !== $expected) return 0;
            unset($this->assignments[$id]);
            return 1;
        }
        if ($op === 'insert_audit') {
            $metadata = strpos($sql, 'NULL, %s)') === false ? json_decode($args[5], true) : null;
            $this->audits[] = ['event_id' => $args[0], 'actor_user_id' => $args[1], 'action' => $args[2], 'entity_type' => $args[3], 'entity_id' => $args[4], 'metadata' => $metadata];
            return 1;
        }
        $this->last_error = 'unexpected mutation';
        return false;
    }
    public function get_results($query, $format = null) {
        [$sql, $args] = $this->resolve($query);
        $this->sql[] = $sql;
        $op = $this->read_op($sql);
        if (!empty($this->throw[$op])) throw new RuntimeException('synthetic read throwable');
        if (array_key_exists($op, $this->next_results) && $this->next_results[$op] !== []) return array_shift($this->next_results[$op]);
        if (!empty($this->fail[$op])) { $this->last_error = 'private database detail'; return null; }
        $this->last_error = '';
        if ($op === 'lock_groups') {
            $rows = array_values(array_filter($this->groups, fn($row) => (int) $row['event_id'] === $args[0]));
            usort($rows, fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
            return $rows;
        }
        if ($op === 'lock_assignment' || $op === 'read_assignment') {
            $rows = array_values(array_filter($this->assignments, fn($row) => (int) $row['event_id'] === $args[0] && $row['guest_phone'] === $args[1]));
            usort($rows, fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
            return $rows;
        }
        if ($op === 'lookup_group') {
            return array_values(array_filter($this->groups, fn($row) => (int) $row['id'] === $args[0]));
        }
        $this->last_error = 'unexpected read';
        return null;
    }
    public function get_var($query) { $this->last_error = 'unexpected scalar'; return null; }
    public function delete($table, $where, $formats) {
        $this->sql[] = 'WPDB DELETE ' . $table;
        if (!empty($this->throw['delete_assignment'])) throw new RuntimeException('synthetic delete throwable');
        if (array_key_exists('delete_assignment', $this->next) && $this->next['delete_assignment'] !== []) return array_shift($this->next['delete_assignment']);
        if (!empty($this->fail['delete_assignment'])) { $this->last_error = 'private database detail'; return false; }
        $this->last_error = '';
        foreach ($this->assignments as $id => $row) {
            if ((int) $row['id'] === $where['id'] && (int) $row['event_id'] === $where['event_id']
                && $row['guest_phone'] === $where['guest_phone'] && (int) $row['group_id'] === $where['group_id']) {
                unset($this->assignments[$id]);
                return 1;
            }
        }
        return 0;
    }
}

function w3_group($id = 1, $status = 'active', $event = 10) {
    return ['id' => (string) $id, 'event_id' => (string) $event, 'name' => 'Group ' . $id,
        'name_key' => $status === 'active' ? 'group ' . $id : null, 'status' => $status,
        'default_slot' => null, 'created_by_user_id' => '7', 'created_at' => '2026-08-14 01:00:00',
        'updated_at' => '2026-08-14 01:00:00', 'archived_at' => $status === 'archived' ? '2026-08-15 01:00:00' : null];
}
function w3_assignment($id = 1, $phone = '0591234567', $group = 1, $actor = 7, $created = '2026-08-14 01:00:00', $updated = '2026-08-14 01:00:00') {
    return ['id' => (string) $id, 'event_id' => '10', 'guest_phone' => $phone, 'group_id' => (string) $group,
        'assigned_by_user_id' => (string) $actor, 'created_at' => $created, 'updated_at' => $updated];
}

require_once PGE_PATH . 'includes/event-guests.php';
require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';

$passed = 0;
$failed = 0;
function w3_ok($label, $condition) { global $passed, $failed; if ($condition) { $passed++; echo "PASS: $label\n"; } else { $failed++; echo "FAIL: $label\n"; } }
function w3_code($value) { return $value instanceof WP_Error ? $value->get_error_code() : null; }
function w3_unique_meta($phone = '0591234567') { return [$phone => ['phone' => $phone, 'name' => 'Private Name', 'note' => '', 'code' => 'ABCD-1234']]; }
function w3_fresh($groups = null, $assignments = [], $meta = null) {
    global $wpdb;
    $wpdb = new PGE_H1BW3_WPDB();
    foreach ($groups === null ? [w3_group(1), w3_group(2)] : $groups as $row) $wpdb->groups[(int) $row['id']] = $row;
    foreach ($assignments as $row) $wpdb->assignments[(int) $row['id']] = $row;
    $GLOBALS['w3_ready'] = true;
    $GLOBALS['w3_posts'] = [10 => 'pge_event'];
    $GLOBALS['w3_post_type_calls'] = 0;
    $GLOBALS['w3_meta_calls'] = 0;
    $GLOBALS['w3_meta'] = [10 => ['_pge_invited_guests' => $meta === null ? w3_unique_meta() : $meta]];
    $GLOBALS['w3_json_fail'] = false;
    return $wpdb;
}

// Production resolver: raw uniqueness, strict storage, and no PII result.
$GLOBALS['w3_meta'] = [10 => ['_pge_invited_guests' => []]];
w3_ok('resolver empty storage is not_found', pge_event_guests_resolve_current_by_phone(10, '0591234567') === ['status' => 'not_found']);
$GLOBALS['w3_meta'][10]['_pge_invited_guests'] = w3_unique_meta();
w3_ok('resolver unique canonical phone is found without PII', pge_event_guests_resolve_current_by_phone(10, '059-123-4567') === ['status' => 'found']);
$GLOBALS['w3_meta'][10]['_pge_invited_guests'] = [['phone' => '059-123'], ['phone' => '059123']];
w3_ok('resolver detects two raw phones with one canonical identity', pge_event_guests_resolve_current_by_phone(10, '059123') === ['status' => 'ambiguous']);
foreach (['scalar', [['name' => 'missing phone']], [['phone' => []]], [['phone' => '---']]] as $bad) {
    $GLOBALS['w3_meta'][10]['_pge_invited_guests'] = $bad;
    w3_ok('resolver malformed storage fails closed', pge_event_guests_resolve_current_by_phone(10, '059123') === ['status' => 'storage_error']);
}
$guest_source = file_get_contents(PGE_PATH . 'includes/event-guests.php');
$resolver_start = strpos($guest_source, "function pge_event_guests_resolve_current_by_phone");
$resolver_end = strpos($guest_source, "if (!function_exists('pge_event_guests_user_can_manage'))", $resolver_start);
$resolver_source = substr($guest_source, $resolver_start, $resolver_end - $resolver_start);
w3_ok('resolver reads raw meta and never uses collapsed map or writes meta', strpos($resolver_source, "'_pge_invited_guests'") !== false && strpos($resolver_source, 'pge_event_guests_get_map') === false && preg_match('/update_post_meta|add_post_meta|delete_post_meta/', $resolver_source) === 0);

// Readiness and strict pre-transaction validation.
$writes = [
    ['assign_guest_to_group', [10, '0591234567', 1, 7]],
    ['move_guest_to_group', [10, '0591234567', 1, 2, 7]],
    ['unassign_guest_from_group', [10, '0591234567', 1, 7]],
];
foreach ($writes as [$method, $args]) {
    $db = w3_fresh(); $GLOBALS['w3_ready'] = false;
    $result = call_user_func_array(['PGE_Event_Access_Repository', $method], $args);
    w3_ok("$method readiness fails before event resolver and SQL", w3_code($result) === 'schema_not_ready' && $GLOBALS['w3_post_type_calls'] === 0 && $GLOBALS['w3_meta_calls'] === 0 && $db->sql === []);
}
foreach ([
    ['assign_guest_to_group', ['10', '059123', 1, 7]],
    ['assign_guest_to_group', [10, '059123', 1.0, 7]],
    ['move_guest_to_group', [10, '059123', 0, 2, 7]],
    ['move_guest_to_group', [10, '059123', 1, 2, '7']],
    ['unassign_guest_from_group', [10, '059123', 1, false]],
    ['assign_guest_to_group', [10, [], 1, 7]],
    ['assign_guest_to_group', [10, '---', 1, 7]],
] as [$method, $args]) {
    $db = w3_fresh();
    w3_ok("$method rejects strict invalid input before transaction", w3_code(call_user_func_array(['PGE_Event_Access_Repository', $method], $args)) === 'invalid_input' && $db->sql === []);
}
$db = w3_fresh(); unset($GLOBALS['w3_posts'][10]);
w3_ok('missing event is generic not_found before resolver and SQL', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '059123', 1, 7)) === 'not_found' && $GLOBALS['w3_meta_calls'] === 0 && $db->sql === []);
$db = w3_fresh(null, [], []);
w3_ok('assign missing guest is not_found before transaction', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'not_found' && $db->sql === []);
$db = w3_fresh(null, [], [['phone' => '059-1234567'], ['phone' => '0591234567']]);
w3_ok('assign ambiguous guest fails before transaction', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'ambiguous_guest' && $db->sql === []);
$db = w3_fresh(null, [], 'corrupt');
w3_ok('move guest storage error is database_error before transaction', w3_code(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 7)) === 'database_error' && $db->sql === []);

// Assign transitions, strict insert identity, audit, and rollback.
$db = w3_fresh();
$result = PGE_Event_Access_Repository::assign_guest_to_group(10, '059-123-4567', 1, 7);
w3_ok('assign creates exact typed result and assignment', $result === ['changed' => true, 'assignment_id' => 1, 'group_id' => 1, 'has_assignment' => true] && count($db->assignments) === 1);
w3_ok('assign stores canonical phone and exact create fields', $db->assignments[1]['guest_phone'] === '0591234567' && $db->assignments[1]['assigned_by_user_id'] === '7' && $db->assignments[1]['created_at'] === '2026-08-16 12:00:00' && $db->assignments[1]['updated_at'] === '2026-08-16 12:00:00');
w3_ok('assign emits technical audit without phone or PII', $db->audits === [['event_id' => 10, 'actor_user_id' => 7, 'action' => 'guest_group_assigned', 'entity_type' => 'guest_assignment', 'entity_id' => 1, 'metadata' => ['group_id' => 1]]] && strpos(json_encode($db->audits), '059') === false && strpos(json_encode($db->audits), 'Private Name') === false);
$db = w3_fresh(null, [w3_assignment()]);
$before = $db->assignments[1]; $result = PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 9);
w3_ok('assign same group is no-op preserving actor and timestamps', $result === ['changed' => false, 'assignment_id' => 1, 'group_id' => 1, 'has_assignment' => true] && $db->assignments[1] === $before && $db->audits === []);
$db = w3_fresh(null, [w3_assignment(1, '0591234567', 2)]);
w3_ok('assign existing other group is concurrent_update', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'concurrent_update' && $db->audits === []);
$db = w3_fresh([w3_group(1, 'archived')]);
w3_ok('assign rejects archived target', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'invalid_state');
$db = w3_fresh([w3_group(2)]);
w3_ok('assign missing or cross-event target is generic not_found', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'not_found');
foreach ([0, '1', 1.0, false, [], new stdClass()] as $bad_insert_id) {
    $db = w3_fresh(); $db->insert_id_override = $bad_insert_id;
    w3_ok('assign rejects malformed insert_id and rolls back', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'database_error' && $db->assignments === [] && $db->audits === []);
}
$db = w3_fresh(); $db->insert_id = 77; $db->next['insert_assignment'] = [1];
w3_ok('assign clears stale positive insert_id', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'database_error' && $db->assignments === []);
foreach ([0, 2, -1, '1', false, null] as $bad_result) {
    $db = w3_fresh(); $db->next['insert_assignment'] = [$bad_result];
    w3_ok('assign rejects malformed INSERT result', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'database_error' && $db->assignments === []);
}
$db = w3_fresh(); $db->race_on_insert = true;
w3_ok('assign duplicate race is concurrent_update without parsing DB text', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'concurrent_update' && $db->assignments === []);
$db = w3_fresh(); $bad = w3_assignment(1); $bad['assigned_by_user_id'] = '99'; $db->next_results['read_assignment'] = [[$bad]];
w3_ok('assign reread mismatch rolls back', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'database_error' && $db->assignments === []);
$db = w3_fresh(); $db->fail['insert_audit'] = true;
w3_ok('assign audit failure rolls back assignment and audit', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'database_error' && $db->assignments === [] && $db->audits === []);
$db = w3_fresh(); $db->fail['commit'] = true;
w3_ok('assign commit failure rolls back without retry', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'database_error' && $db->assignments === [] && $db->audits === []);
$db = w3_fresh(); $db->throw['insert_assignment'] = true;
w3_ok('assign Throwable rolls back and hides details', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'database_error' && $db->assignments === []);

// Move transitions, repair paths, optimistic scope, and postconditions.
$db = w3_fresh(null, [w3_assignment()]); $before_created = $db->assignments[1]['created_at'];
$result = PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 9);
w3_ok('move succeeds with fixed result and preserves identity', $result === ['changed' => true, 'assignment_id' => 1, 'group_id' => 2, 'has_assignment' => true] && $db->assignments[1]['id'] === '1' && $db->assignments[1]['event_id'] === '10' && $db->assignments[1]['guest_phone'] === '0591234567' && $db->assignments[1]['created_at'] === $before_created);
w3_ok('move updates group actor and updated_at with exact audit', $db->assignments[1]['group_id'] === '2' && $db->assignments[1]['assigned_by_user_id'] === '9' && $db->assignments[1]['updated_at'] === '2026-08-16 12:00:00' && $db->audits[0]['metadata'] === ['previous_group_id' => 1, 'new_group_id' => 2]);
$db = w3_fresh(null, [w3_assignment()]);
w3_ok('move expected mismatch is concurrent_update', w3_code(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 2, 1, 7)) === 'concurrent_update');
$db = w3_fresh();
w3_ok('move missing assignment is concurrent_update', w3_code(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 7)) === 'concurrent_update');
$db = w3_fresh(null, [w3_assignment()]); $before = $db->assignments[1];
w3_ok('move same active group is no-op without audit', PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 1, 9) === ['changed' => false, 'assignment_id' => 1, 'group_id' => 1, 'has_assignment' => true] && $db->assignments[1] === $before && $db->audits === []);
$db = w3_fresh([w3_group(1, 'archived'), w3_group(2)], [w3_assignment()]);
w3_ok('move from archived current group to active target repairs explicitly', is_array(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 7)) && $db->assignments[1]['group_id'] === '2');
$db = w3_fresh([w3_group(2)], [w3_assignment()]);
w3_ok('move from missing current group to active target repairs explicitly', is_array(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 7)) && $db->assignments[1]['group_id'] === '2');
$db = w3_fresh([w3_group(2), w3_group(1, 'active', 11)], [w3_assignment()]);
w3_ok('move cross-event current group fails closed', w3_code(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 7)) === 'database_error' && $db->assignments[1]['group_id'] === '1');
$db = w3_fresh([w3_group(1), w3_group(2, 'archived')], [w3_assignment()]);
w3_ok('move rejects archived target', w3_code(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 7)) === 'invalid_state');
$db = w3_fresh([w3_group(1)], [w3_assignment()]);
w3_ok('move missing or cross-event target is generic not_found', w3_code(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 7)) === 'not_found');
foreach ([0, 2, -1, '1', false, null] as $bad_result) {
    $db = w3_fresh(null, [w3_assignment()]); $db->next['move_assignment'] = [$bad_result];
    $expected = $bad_result === 0 ? 'concurrent_update' : 'database_error';
    w3_ok('move enforces strict affected-row contract', w3_code(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 7)) === $expected && $db->assignments[1]['group_id'] === '1');
}
$db = w3_fresh(null, [w3_assignment()]); $bad = w3_assignment(1, '0591234567', 2, 7); $db->next_results['read_assignment'] = [[$bad]];
w3_ok('move reread mismatch rolls back', w3_code(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 9)) === 'database_error' && $db->assignments[1]['group_id'] === '1');
$db = w3_fresh(null, [w3_assignment()]); $db->fail['insert_audit'] = true;
w3_ok('move audit failure restores assignment', w3_code(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 9)) === 'database_error' && $db->assignments[1]['group_id'] === '1' && $db->audits === []);
$db = w3_fresh(null, [w3_assignment()]); $db->fail['commit'] = true;
w3_ok('move commit failure restores assignment and audit', w3_code(PGE_Event_Access_Repository::move_guest_to_group(10, '0591234567', 1, 2, 9)) === 'database_error' && $db->assignments[1]['group_id'] === '1' && $db->audits === []);

// Unassign cleanup deliberately does not resolve current guest storage.
$db = w3_fresh(null, [w3_assignment()], 'corrupt');
$result = PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 9);
w3_ok('unassign succeeds when guest storage cannot prove existence and skips resolver', $result === ['changed' => true, 'assignment_id' => null, 'group_id' => null, 'has_assignment' => false] && $GLOBALS['w3_meta_calls'] === 0 && $db->assignments === []);
w3_ok('unassign audit uses deleted assignment ID and exact metadata', $db->audits[0]['entity_id'] === 1 && $db->audits[0]['action'] === 'guest_group_unassigned' && $db->audits[0]['metadata'] === ['previous_group_id' => 1]);
$db = w3_fresh();
w3_ok('unassign missing assignment is no-op without audit', PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 7) === ['changed' => false, 'assignment_id' => null, 'group_id' => null, 'has_assignment' => false] && $db->audits === []);
$db = w3_fresh(null, [w3_assignment()]);
w3_ok('unassign expected mismatch is concurrent_update', w3_code(PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 2, 7)) === 'concurrent_update' && count($db->assignments) === 1);
$db = w3_fresh([w3_group(1, 'archived')], [w3_assignment()]);
w3_ok('unassign cleans archived current group', is_array(PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 7)) && $db->assignments === []);
$db = w3_fresh([w3_group(2)], [w3_assignment()]);
w3_ok('unassign cleans missing current group', is_array(PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 7)) && $db->assignments === []);
$db = w3_fresh([w3_group(2), w3_group(1, 'active', 11)], [w3_assignment()]);
w3_ok('unassign rejects cross-event current group', w3_code(PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 7)) === 'database_error' && count($db->assignments) === 1);
foreach ([0, 2, -1, '1', false, null] as $bad_result) {
    $db = w3_fresh(null, [w3_assignment()]); $db->next['delete_assignment'] = [$bad_result];
    $expected = $bad_result === 0 ? 'concurrent_update' : 'database_error';
    w3_ok('unassign enforces strict affected-row contract', w3_code(PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 7)) === $expected && count($db->assignments) === 1);
}
$db = w3_fresh(null, [w3_assignment()]); $db->next['delete_assignment'] = [1];
w3_ok('unassign post-delete surviving row fails closed', w3_code(PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 7)) === 'database_error' && count($db->assignments) === 1);
$db = w3_fresh(null, [w3_assignment()]); $db->fail['insert_audit'] = true;
w3_ok('unassign audit failure restores assignment and audit', w3_code(PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 7)) === 'database_error' && count($db->assignments) === 1 && $db->audits === []);
$db = w3_fresh(null, [w3_assignment()]); $db->fail['commit'] = true;
w3_ok('unassign commit failure restores assignment and audit', w3_code(PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 7)) === 'database_error' && count($db->assignments) === 1 && $db->audits === []);

// Corruption, controls, lock order, last_error, and static boundaries.
$db = w3_fresh(null, [w3_assignment(), w3_assignment(2)]);
w3_ok('duplicate exact assignments fail closed', w3_code(PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 7)) === 'database_error');
foreach ([['group_id' => '0'], ['guest_phone' => 'bad-phone'], ['assigned_by_user_id' => '0'], ['created_at' => '']] as $override) {
    $row = array_merge(w3_assignment(), $override); $db = w3_fresh(); $db->next_results['lock_assignment'] = [[$row]];
    w3_ok('malformed stored assignment fails closed', w3_code(PGE_Event_Access_Repository::unassign_guest_from_group(10, '0591234567', 1, 7)) === 'database_error');
}
foreach ([true, false, 1, -1, '0', '1', null, 1.0, [], new stdClass()] as $bad_control) {
    $db = w3_fresh(); $db->next['begin'] = [$bad_control];
    w3_ok('START accepts only integer zero', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'database_error' && $db->assignments === []);
}
$db = w3_fresh(); $db->next['commit'] = [true];
w3_ok('malformed COMMIT rolls back mutation and audit', w3_code(PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7)) === 'database_error' && $db->assignments === [] && $db->audits === []);
$db = w3_fresh(); $db->last_error = 'preexisting'; PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7);
w3_ok('transaction restores preexisting wpdb last_error', $db->last_error === 'preexisting');
$db = w3_fresh(); PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7); $joined = implode("\n", $db->sql);
$groups_pos = strpos($joined, 'pge_event_invitation_groups'); $assignment_pos = strpos($joined, 'pge_invitation_group_assignments');
w3_ok('lock order is groups then exact assignment with deterministic ordering', $groups_pos !== false && $assignment_pos !== false && $groups_pos < $assignment_pos && strpos($joined, 'WHERE event_id = %d ORDER BY id FOR UPDATE') !== false && strpos($joined, 'guest_phone = %s ORDER BY id FOR UPDATE') !== false);

$repository_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-repository.php');
w3_ok('W3 source excludes authorization hooks schema upgrades advisory locks and upsert', preg_match('/current_user_can|get_current_user_id|wp_verify_nonce|wp_ajax|register_rest_route|dbDelta\s*\(|maybe_upgrade\s*\(|GET_LOCK|RELEASE_LOCK|ON\s+DUPLICATE\s+KEY/i', $repository_source) === 0);
w3_ok('W3 repository has no Post Meta mutation or direct Post Meta read', preg_match('/get_post_meta|update_post_meta|add_post_meta|delete_post_meta/i', $repository_source) === 0);
w3_ok('no public arbitrary audit writer or bulk assignment API exists', strpos($repository_source, 'public static function append_audit') === false && preg_match('/public static function .*bulk.*assignment/i', $repository_source) === 0);
w3_ok('repository never derives actor from current user', $GLOBALS['w3_current_user_calls'] === 0);
w3_ok('audit metadata JSON failure rolls back safely', (function () { $db = w3_fresh(); $GLOBALS['w3_json_fail'] = true; $result = PGE_Event_Access_Repository::assign_guest_to_group(10, '0591234567', 1, 7); return w3_code($result) === 'database_error' && $db->assignments === [] && $db->audits === []; })());

echo "\nH1B-W3: {$passed}/" . ($passed + $failed) . " passed\n";
exit($failed === 0 ? 0 : 1);
