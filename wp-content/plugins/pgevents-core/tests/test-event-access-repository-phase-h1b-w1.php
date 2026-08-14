<?php
/**
 * Phase H1B-W1 — Transactional Invitation Group Writes.
 *
 * Run: php tests/test-event-access-repository-phase-h1b-w1.php
 * Uses the production repository with a stateful fake wpdb. No real database,
 * schema routine, authorization, hook, or WordPress mutation is invoked.
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

$GLOBALS['h1bw1_ready'] = true;
$GLOBALS['h1bw1_post_types'] = [10 => 'pge_event'];
$GLOBALS['h1bw1_ready_calls'] = 0;
$GLOBALS['h1bw1_post_type_calls'] = 0;
$GLOBALS['h1bw1_current_user_calls'] = 0;

final class PGE_Event_Access_Schema
{
    public static function is_ready() { $GLOBALS['h1bw1_ready_calls']++; return $GLOBALS['h1bw1_ready']; }
    public static function maybe_upgrade() { throw new RuntimeException('schema upgrade is forbidden'); }
}
function get_post_type($event_id) { $GLOBALS['h1bw1_post_type_calls']++; return $GLOBALS['h1bw1_post_types'][$event_id] ?? false; }
function get_current_user_id() { $GLOBALS['h1bw1_current_user_calls']++; return 999; }
function current_time($type, $gmt = false) { return '2026-08-14 12:34:56'; }
function wp_strip_all_tags($value)
{
    $value = preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $value);
    return trim(strip_tags($value));
}
function wp_json_encode($value) { return json_encode($value); }

final class PGE_H1BW1_Fake_WPDB
{
    public $prefix = 'tenant7_';
    public $last_error = '';
    public $insert_id = 0;
    public $groups = [];
    public $audits = [];
    public $sql = [];
    public $fail = [];
    public $next_result = [];
    public $next_read_result = [];
    public $throw_on = null;
    public $forced_insert_id = null;
    public $force_insert_id = false;
    public $preserve_insert_id_on_group_insert = false;
    public $inserted_group_overrides = [];
    public $race_group = null;
    private $prepared = [];
    private $sequence = 0;
    private $snapshot = null;

    public function prepare($sql, ...$args)
    {
        $this->sequence++;
        $token = '__H1BW1_' . $this->sequence . '__';
        $this->prepared[$token] = ['sql' => $sql, 'args' => $args];
        return $token;
    }

    public function query($query)
    {
        [$sql, $args] = $this->resolve($query);
        $this->sql[] = $sql;
        $op = $this->operation($sql);
        if ($this->throw_on === $op) throw new RuntimeException('synthetic throwable');
        if (array_key_exists($op, $this->next_result)) {
            $result = $this->next_result[$op];
            unset($this->next_result[$op]);
            if ($result === false) $this->last_error = 'synthetic database detail';
            return $result;
        }
        if (!empty($this->fail[$op])) {
            $this->last_error = 'synthetic database detail';
            return false;
        }
        $this->last_error = '';

        if ($op === 'begin') {
            $this->snapshot = [$this->groups, $this->audits, $this->insert_id];
            return 0;
        }
        if ($op === 'rollback') {
            if ($this->snapshot !== null) [$this->groups, $this->audits, $this->insert_id] = $this->snapshot;
            $this->snapshot = null;
            return 0;
        }
        if ($op === 'commit') { $this->snapshot = null; return 0; }

        if ($op === 'insert_group') {
            $event_id = $args[0]; $name = $args[1]; $key = $args[2]; $status = $args[3];
            $has_slot = strpos($sql, 'NULL, %d, %s, %s, NULL)') === false;
            $offset = $has_slot ? 1 : 0;
            $slot = $has_slot ? $args[4] : null;
            if (is_array($this->race_group)) {
                $race_id = (int) $this->race_group['id'];
                $this->groups[$race_id] = $this->race_group;
                $this->race_group = null;
            }
            foreach ($this->groups as $row) {
                if ((int) $row['event_id'] === $event_id && $row['name_key'] === $key) {
                    $this->last_error = 'duplicate'; return false;
                }
                if ($slot === 1 && (int) $row['event_id'] === $event_id && $row['default_slot'] !== null) {
                    $this->last_error = 'duplicate'; return false;
                }
            }
            $id = $this->groups ? max(array_keys($this->groups)) + 1 : 1;
            if (!$this->preserve_insert_id_on_group_insert) {
                $this->insert_id = $this->force_insert_id ? $this->forced_insert_id : $id;
            }
            $this->groups[$id] = [
                'id' => (string) $id, 'event_id' => (string) $event_id, 'name' => $name, 'name_key' => $key,
                'status' => $status, 'default_slot' => $slot === null ? null : '1',
                'created_by_user_id' => (string) $args[4 + $offset],
                'created_at' => $args[5 + $offset], 'updated_at' => $args[6 + $offset], 'archived_at' => null,
            ];
            if ($this->inserted_group_overrides !== []) {
                $this->groups[$id] = array_merge($this->groups[$id], $this->inserted_group_overrides);
            }
            return 1;
        }
        if ($op === 'insert_audit') {
            $has_metadata = strpos($sql, 'metadata, created_at) VALUES (%d, %d, %s, %s, %d, NULL, %s)') === false;
            $this->audits[] = [
                'event_id' => $args[0], 'actor_user_id' => $args[1], 'action' => $args[2],
                'entity_type' => $args[3], 'entity_id' => $args[4],
                'metadata' => $has_metadata ? $args[5] : null,
            ];
            return 1;
        }
        if ($op === 'clear_default') {
            $id = $args[2];
            if (!isset($this->groups[$id]) || $this->groups[$id]['default_slot'] === null) return 0;
            $this->groups[$id]['default_slot'] = null; $this->groups[$id]['updated_at'] = $args[0]; return 1;
        }
        if ($op === 'set_default') {
            $id = $args[3];
            if (!isset($this->groups[$id]) || $this->groups[$id]['default_slot'] !== null || $this->groups[$id]['status'] !== 'active') return 0;
            $this->groups[$id]['default_slot'] = '1'; $this->groups[$id]['updated_at'] = $args[1]; return 1;
        }
        if ($op === 'rename') {
            $id = $args[4];
            if (!isset($this->groups[$id]) || $this->groups[$id]['status'] !== 'active') return 0;
            foreach ($this->groups as $other_id => $row) {
                if ($other_id !== $id && (int) $row['event_id'] === $args[3] && $row['name_key'] === $args[1]) {
                    $this->last_error = 'duplicate'; return false;
                }
            }
            $this->groups[$id]['name'] = $args[0]; $this->groups[$id]['name_key'] = $args[1]; $this->groups[$id]['updated_at'] = $args[2]; return 1;
        }
        if ($op === 'archive') {
            $id = $args[4];
            if (!isset($this->groups[$id]) || $this->groups[$id]['status'] !== 'active') return 0;
            $this->groups[$id]['status'] = 'archived'; $this->groups[$id]['name_key'] = null;
            $this->groups[$id]['default_slot'] = null; $this->groups[$id]['archived_at'] = $args[1];
            $this->groups[$id]['updated_at'] = $args[2]; return 1;
        }
        $this->last_error = 'unexpected mutation';
        return false;
    }

    public function get_results($query, $format = null)
    {
        [$sql, $args] = $this->resolve($query);
        $this->sql[] = $sql;
        $op = $this->operation($sql);
        if ($this->throw_on === $op) throw new RuntimeException('synthetic throwable');
        if (array_key_exists($op, $this->next_read_result)) {
            $result = $this->next_read_result[$op];
            unset($this->next_read_result[$op]);
            return $result;
        }
        if (!empty($this->fail[$op])) { $this->last_error = 'synthetic database detail'; return null; }
        $this->last_error = '';
        if ($op === 'lock') {
            $rows = array_values(array_filter($this->groups, fn($row) => (int) $row['event_id'] === $args[0]));
            usort($rows, fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
            return $rows;
        }
        if ($op === 'read_group') {
            $row = $this->groups[$args[1]] ?? null;
            return $row !== null && (int) $row['event_id'] === $args[0] ? [$row] : [];
        }
        if ($op === 'read_duplicate') {
            foreach ($this->groups as $row) {
                if ((int) $row['event_id'] === $args[0] && $row['name_key'] === $args[1] && $row['status'] === $args[2]) return [$row];
            }
            return [];
        }
        $this->last_error = 'unexpected read';
        return null;
    }

    private function resolve($query)
    {
        return isset($this->prepared[$query]) ? [$this->prepared[$query]['sql'], $this->prepared[$query]['args']] : [$query, []];
    }

    private function operation($sql)
    {
        if ($sql === 'START TRANSACTION') return 'begin';
        if ($sql === 'COMMIT') return 'commit';
        if ($sql === 'ROLLBACK') return 'rollback';
        if (strpos($sql, 'ORDER BY id FOR UPDATE') !== false) return 'lock';
        if (strpos($sql, 'INSERT INTO tenant7_pge_event_invitation_groups') === 0) return 'insert_group';
        if (strpos($sql, 'INSERT INTO tenant7_pge_event_access_audit_log') === 0) return 'insert_audit';
        if (strpos($sql, 'SET default_slot = NULL') !== false) return 'clear_default';
        if (strpos($sql, 'SET default_slot = %d') !== false) return 'set_default';
        if (strpos($sql, 'SET name = %s') !== false) return 'rename';
        if (strpos($sql, 'SET status = %s') !== false) return 'archive';
        if (strpos($sql, 'name_key = %s') !== false) return 'read_duplicate';
        if (strpos($sql, 'id = %d') !== false) return 'read_group';
        return 'unknown';
    }
}

require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';

$passed = 0; $failed = 0;
function h1bw1_check($label, $condition) { global $passed, $failed; if ($condition) { $passed++; echo "PASS: $label\n"; } else { $failed++; echo "FAIL: $label\n"; } }
function h1bw1_code($value) { return $value instanceof WP_Error ? $value->get_error_code() : null; }
function h1bw1_type_label($value)
{
    if (is_object($value)) return 'object';
    if (is_array($value)) return 'array';
    return gettype($value) . ':' . var_export($value, true);
}
function h1bw1_group($id, $name, $default = false, $status = 'active', $event_id = 10)
{
    return [
        'id' => (string) $id, 'event_id' => (string) $event_id, 'name' => $name,
        'name_key' => $status === 'active' ? strtolower($name) : null, 'status' => $status,
        'default_slot' => $default ? '1' : null, 'created_by_user_id' => '7',
        'created_at' => '2026-08-14 01:00:00', 'updated_at' => '2026-08-14 01:00:00',
        'archived_at' => $status === 'archived' ? '2026-08-14 02:00:00' : null,
    ];
}
function h1bw1_fresh($groups = [])
{
    global $wpdb;
    $wpdb = new PGE_H1BW1_Fake_WPDB();
    foreach ($groups as $row) $wpdb->groups[(int) $row['id']] = $row;
    $GLOBALS['h1bw1_ready'] = true; $GLOBALS['h1bw1_post_types'] = [10 => 'pge_event'];
    $GLOBALS['h1bw1_ready_calls'] = 0; $GLOBALS['h1bw1_post_type_calls'] = 0; $GLOBALS['h1bw1_current_user_calls'] = 0;
    return $wpdb;
}
function h1bw1_has_order(array $sql, array $needles)
{
    $position = -1;
    foreach ($needles as $needle) {
        $found = null;
        foreach ($sql as $index => $statement) if ($index > $position && strpos($statement, $needle) !== false) { $found = $index; break; }
        if ($found === null) return false;
        $position = $found;
    }
    return true;
}

// Guards and strict validation.
$writes = [
    ['create_group', [10, 'Main', 7, false]],
    ['rename_group', [10, 1, 'Main', 'New', 7]],
    ['set_default_group', [10, 1, 7]],
    ['archive_group', [10, 1, 7]],
];
foreach ($writes as [$method, $args]) {
    $wpdb = h1bw1_fresh(); $GLOBALS['h1bw1_ready'] = false;
    $result = call_user_func_array(['PGE_Event_Access_Repository', $method], $args);
    h1bw1_check("$method fails closed on readiness with zero SQL", h1bw1_code($result) === 'schema_not_ready' && $wpdb->sql === [] && $GLOBALS['h1bw1_post_type_calls'] === 0);
}
$wpdb = h1bw1_fresh();
h1bw1_check('event, group, actor IDs and make_default remain strict',
    h1bw1_code(PGE_Event_Access_Repository::create_group('10', 'Main', 7)) === 'invalid_input'
    && h1bw1_code(PGE_Event_Access_Repository::create_group(10, 'Main', '7')) === 'invalid_input'
    && h1bw1_code(PGE_Event_Access_Repository::create_group(10, 'Main', 7, 1)) === 'invalid_input'
    && h1bw1_code(PGE_Event_Access_Repository::rename_group(10, 1.0, 'Main', 'New', 7)) === 'invalid_input');
$wpdb = h1bw1_fresh(); $GLOBALS['h1bw1_post_types'][10] = 'post';
h1bw1_check('missing or wrong post type is generic not_found before transaction', h1bw1_code(PGE_Event_Access_Repository::create_group(10, 'Main', 7)) === 'not_found' && $wpdb->sql === []);

// Name normalization.
h1bw1_check('wp_strip_all_tags fake mirrors WordPress trimming relevant to W1', wp_strip_all_tags('  Main  ') === 'Main');
$wpdb = h1bw1_fresh();
$arabic = PGE_Event_Access_Repository::create_group(10, "  أهل　 العريس  ", 7);
h1bw1_check('Arabic and Unicode whitespace normalize without identity loss', is_array($arabic) && $arabic['group']['name'] === 'أهل العريس' && $arabic['group']['name_key'] === 'أهل العريس');
$wpdb = h1bw1_fresh();
$ascii = PGE_Event_Access_Repository::create_group(10, '  BRIDE Friends  ', 7);
h1bw1_check('ASCII edge spaces trim and ASCII case folds only in name_key', is_array($ascii) && $ascii['group']['name'] === 'BRIDE Friends' && $ascii['group']['name_key'] === 'bride friends');
$wpdb = h1bw1_fresh();
$ascii_whitespace = PGE_Event_Access_Repository::create_group(10, "\tMain\t \r\nGroup\n", 7);
h1bw1_check('tabs CR and LF trim and collapse to one internal space', is_array($ascii_whitespace) && $ascii_whitespace['group']['name'] === 'Main Group' && $ascii_whitespace['group']['name_key'] === 'main group');
$wpdb = h1bw1_fresh();
$unicode_whitespace = PGE_Event_Access_Repository::create_group(10, "\u{00A0}\u{3000}Main\u{00A0}Group\u{2003}", 7);
h1bw1_check('NBSP and Unicode separators trim and collapse', is_array($unicode_whitespace) && $unicode_whitespace['group']['name'] === 'Main Group');
$wpdb = h1bw1_fresh();
$entity = PGE_Event_Access_Repository::create_group(10, '&lt;b&gt;', 7);
h1bw1_check('HTML entities remain text rather than actual tags', is_array($entity) && $entity['group']['name'] === '&lt;b&gt;');
$wpdb = h1bw1_fresh();
$emoji_combining = PGE_Event_Access_Repository::create_group(10, "Party 😀 e\u{0301}", 7);
h1bw1_check('emoji and combining marks preserve identity', is_array($emoji_combining) && $emoji_combining['group']['name'] === "Party 😀 e\u{0301}" && $emoji_combining['group']['name_key'] === "party 😀 e\u{0301}");
foreach (['', '   ', '<b>Main</b>', '  <b>Main</b>  ', 'Main <b>x</b>', '< Main', 'Main >', "Bad\0Name", "Bad\u{0001}Name", "Bad\u{200D}Name", "Bad\u{E000}Name", "Bad\u{F0000}Name", "\xC3\x28"] as $bad_name) {
    $wpdb = h1bw1_fresh();
    $error = PGE_Event_Access_Repository::create_group(10, $bad_name, 7);
    $not_leaked = $error instanceof WP_Error && ($bad_name === '' || strpos($error->get_error_message(), $bad_name) === false);
    h1bw1_check('invalid/HTML/control/format/private/UTF-8 names fail before SQL without leaking input', h1bw1_code($error) === 'invalid_name' && $wpdb->sql === [] && $not_leaked);
}
$wpdb = h1bw1_fresh();
h1bw1_check('160 code points are accepted', is_array(PGE_Event_Access_Repository::create_group(10, str_repeat('a', 160), 7)));
$wpdb = h1bw1_fresh();
h1bw1_check('161 key code points and 192 name code points are rejected before SQL', h1bw1_code(PGE_Event_Access_Repository::create_group(10, str_repeat('a', 161), 7)) === 'invalid_name' && h1bw1_code(PGE_Event_Access_Repository::create_group(10, str_repeat('ب', 192), 7)) === 'invalid_name' && $wpdb->sql === []);

// Create and create-default.
$wpdb = h1bw1_fresh();
$created = PGE_Event_Access_Repository::create_group(10, 'Main', 7);
h1bw1_check('create writes active non-default group and one audit atomically', $created['changed'] === true && !$created['group']['is_default'] && $wpdb->insert_id === $created['group']['id'] && count($wpdb->audits) === 1 && $wpdb->audits[0]['action'] === 'group_created' && h1bw1_has_order($wpdb->sql, ['START TRANSACTION', 'FOR UPDATE', 'INSERT INTO tenant7_pge_event_invitation_groups', 'INSERT INTO tenant7_pge_event_access_audit_log', 'COMMIT']));
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Old', true)]);
$created = PGE_Event_Access_Repository::create_group(10, 'New', 7, true);
h1bw1_check('create-default clears previous first and emits two audits with technical metadata', $created['group']['is_default'] && $wpdb->groups[1]['default_slot'] === null && count($wpdb->audits) === 2 && json_decode($wpdb->audits[1]['metadata'], true) === ['previous_group_id' => 1] && h1bw1_has_order($wpdb->sql, ['SET default_slot = NULL', 'INSERT INTO tenant7_pge_event_invitation_groups']));
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Main')]);
h1bw1_check('duplicate create is not idempotent success', h1bw1_code(PGE_Event_Access_Repository::create_group(10, 'Main', 7)) === 'duplicate_group' && count($wpdb->groups) === 1 && $wpdb->audits === []);
foreach ([0, -1, '1', 1.0, null] as $invalid_insert_id) {
    $wpdb = h1bw1_fresh(); $wpdb->force_insert_id = true; $wpdb->forced_insert_id = $invalid_insert_id;
    h1bw1_check('insert_id must be a positive PHP integer', h1bw1_code(PGE_Event_Access_Repository::create_group(10, 'Main', 7)) === 'database_error' && $wpdb->groups === [] && $wpdb->audits === []);
}
$wpdb = h1bw1_fresh(); $wpdb->insert_id = 77; $wpdb->preserve_insert_id_on_group_insert = true;
$stale_insert_id = PGE_Event_Access_Repository::create_group(10, 'Main', 7);
h1bw1_check('stale positive insert_id is cleared and never consumed', h1bw1_code($stale_insert_id) === 'database_error' && $wpdb->insert_id === 77 && $wpdb->groups === [] && $wpdb->audits === [] && in_array('ROLLBACK', $wpdb->sql, true));
$wpdb = h1bw1_fresh(); $wpdb->fail['read_group'] = true;
h1bw1_check('post-insert row reread failure rolls back mutation and audit', h1bw1_code(PGE_Event_Access_Repository::create_group(10, 'Main', 7)) === 'database_error' && $wpdb->groups === [] && $wpdb->audits === []);
foreach ([
    ['name' => 'Wrong'],
    ['name_key' => 'wrong'],
    ['status' => 'archived'],
    ['default_slot' => '1'],
    ['created_by_user_id' => '8'],
    ['created_at' => '2026-08-14 12:34:55'],
    ['updated_at' => '2026-08-14 12:34:55'],
    ['archived_at' => '2026-08-14 12:34:56'],
] as $row_mismatch) {
    $wpdb = h1bw1_fresh(); $wpdb->inserted_group_overrides = $row_mismatch;
    $result = PGE_Event_Access_Repository::create_group(10, 'Main', 7);
    h1bw1_check('created-row reread must match expected identity and create fields', h1bw1_code($result) === 'database_error' && $wpdb->groups === [] && $wpdb->audits === [] && in_array('ROLLBACK', $wpdb->sql, true));
}
$wpdb = h1bw1_fresh(); $wpdb->race_group = h1bw1_group(2, 'Main');
h1bw1_check('duplicate appearing after precheck is translated without parsing DB text', h1bw1_code(PGE_Event_Access_Repository::create_group(10, 'Main', 7)) === 'duplicate_group');

// Rename behavior and stale protection.
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Main')]);
$renamed = PGE_Event_Access_Repository::rename_group(10, 1, 'Main', 'New Name', 7);
h1bw1_check('rename updates name/key and audits once', $renamed['changed'] && $renamed['group']['name_key'] === 'new name' && count($wpdb->audits) === 1 && $wpdb->audits[0]['metadata'] === null);
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Main')]);
$noop = PGE_Event_Access_Repository::rename_group(10, 1, 'Main', 'Main', 7);
h1bw1_check('rename no-op commits without mutation or audit', $noop['changed'] === false && $wpdb->audits === [] && count(array_filter($wpdb->sql, fn($sql) => strpos($sql, 'UPDATE ') === 0)) === 0);
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Changed')]);
h1bw1_check('stale expected name returns concurrent_update and rolls back', h1bw1_code(PGE_Event_Access_Repository::rename_group(10, 1, 'Old', 'New', 7)) === 'concurrent_update' && in_array('ROLLBACK', $wpdb->sql, true));
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Main'), h1bw1_group(2, 'Other')]);
h1bw1_check('rename collision fails as duplicate_group', h1bw1_code(PGE_Event_Access_Repository::rename_group(10, 1, 'Main', 'Other', 7)) === 'duplicate_group');
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Main', false, 'archived')]);
h1bw1_check('archived rename target is invalid_state', h1bw1_code(PGE_Event_Access_Repository::rename_group(10, 1, 'Main', 'New', 7)) === 'invalid_state');

// Default switching.
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Main')]);
$defaulted = PGE_Event_Access_Repository::set_default_group(10, 1, 7);
h1bw1_check('default can be assigned when none exists', $defaulted['group']['is_default'] && json_decode($wpdb->audits[0]['metadata'], true) === ['previous_group_id' => null]);
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Old', true), h1bw1_group(2, 'New')]);
$defaulted = PGE_Event_Access_Repository::set_default_group(10, 2, 7);
h1bw1_check('default switch clears old before setting target', $defaulted['group']['id'] === 2 && $wpdb->groups[1]['default_slot'] === null && h1bw1_has_order($wpdb->sql, ['SET default_slot = NULL', 'SET default_slot = %d']));
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Main', true)]);
$noop = PGE_Event_Access_Repository::set_default_group(10, 1, 7);
h1bw1_check('setting current default is a no-op without audit', !$noop['changed'] && $wpdb->audits === []);
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Main', false, 'archived')]);
h1bw1_check('archived default target is invalid_state', h1bw1_code(PGE_Event_Access_Repository::set_default_group(10, 1, 7)) === 'invalid_state');
$corrupt = h1bw1_group(2, 'Other', true); $wpdb = h1bw1_fresh([h1bw1_group(1, 'Main', true), $corrupt]);
h1bw1_check('multiple stored defaults fail closed', h1bw1_code(PGE_Event_Access_Repository::set_default_group(10, 2, 7)) === 'database_error');

// Archive behavior.
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Only', true)]);
$archived = PGE_Event_Access_Repository::archive_group(10, 1, 7);
h1bw1_check('archive permits default and last active while enforcing final invariants', $archived['changed'] && $archived['group']['status'] === 'archived' && $archived['group']['name_key'] === null && !$archived['group']['is_default'] && $archived['group']['archived_at'] === '2026-08-14 12:34:56' && json_decode($wpdb->audits[0]['metadata'], true) === ['was_default' => true]);
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Old', false, 'archived')]);
$noop = PGE_Event_Access_Repository::archive_group(10, 1, 7);
h1bw1_check('already archived is an idempotent no-op', !$noop['changed'] && $wpdb->audits === []);
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Other', false, 'active', 11)]);
h1bw1_check('cross-event group IDs are generic not_found', h1bw1_code(PGE_Event_Access_Repository::archive_group(10, 1, 7)) === 'not_found');

// Serialized interleaving simulations over the same fake state.
$wpdb = h1bw1_fresh();
$first_create = PGE_Event_Access_Repository::create_group(10, 'Same', 7);
$second_create = PGE_Event_Access_Repository::create_group(10, 'Same', 8);
h1bw1_check('Create/Create same key leaves one group and one successful audit', is_array($first_create) && h1bw1_code($second_create) === 'duplicate_group' && count($wpdb->groups) === 1 && count($wpdb->audits) === 1);
$wpdb = h1bw1_fresh([h1bw1_group(1, 'One'), h1bw1_group(2, 'Two'), h1bw1_group(3, 'Three')]);
$first_default = PGE_Event_Access_Repository::set_default_group(10, 2, 7);
$second_default = PGE_Event_Access_Repository::set_default_group(10, 3, 8);
$defaults = array_filter($wpdb->groups, fn($row) => $row['default_slot'] !== null);
h1bw1_check('Set-default/Set-default serializes to exactly one final default', is_array($first_default) && is_array($second_default) && count($defaults) === 1 && array_key_exists(3, $defaults));
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Main')]);
PGE_Event_Access_Repository::set_default_group(10, 1, 7);
$after_set_archive = PGE_Event_Access_Repository::archive_group(10, 1, 8);
h1bw1_check('Set-default/Archive permits archive and leaves zero default', is_array($after_set_archive) && $after_set_archive['group']['status'] === 'archived' && $wpdb->groups[1]['default_slot'] === null);
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Main')]);
PGE_Event_Access_Repository::archive_group(10, 1, 7);
$after_archive_set = PGE_Event_Access_Repository::set_default_group(10, 1, 8);
h1bw1_check('Archive/Set-default rejects the now-archived target', h1bw1_code($after_archive_set) === 'invalid_state');
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Source')]);
PGE_Event_Access_Repository::rename_group(10, 1, 'Source', 'Shared', 7);
$rename_create = PGE_Event_Access_Repository::create_group(10, 'Shared', 8);
h1bw1_check('Rename/Create same key preserves unique identity', h1bw1_code($rename_create) === 'duplicate_group' && count($wpdb->groups) === 1);

// Transaction failure and rollback matrix.
foreach (['begin', 'lock', 'insert_group', 'insert_audit', 'commit'] as $failure) {
    $wpdb = h1bw1_fresh(); $wpdb->fail[$failure] = true;
    $result = PGE_Event_Access_Repository::create_group(10, 'Main', 7);
    $rolled_back = $failure === 'begin' ? !in_array('ROLLBACK', $wpdb->sql, true) : in_array('ROLLBACK', $wpdb->sql, true);
    h1bw1_check("$failure failure returns database_error with correct rollback boundary", h1bw1_code($result) === 'database_error' && $rolled_back && $wpdb->groups === [] && $wpdb->audits === []);
}
$malformed_control_results = [true, false, 1, -1, '0', '1', null, 1.0, [], new stdClass()];
foreach ($malformed_control_results as $control_result) {
    $label = h1bw1_type_label($control_result);

    $wpdb = h1bw1_fresh(); $wpdb->next_result['begin'] = $control_result;
    $create_result = PGE_Event_Access_Repository::create_group(10, 'Main', 7);
    $create_start_closed = h1bw1_code($create_result) === 'database_error'
        && $wpdb->groups === [] && $wpdb->audits === []
        && !in_array('COMMIT', $wpdb->sql, true) && !in_array('ROLLBACK', $wpdb->sql, true);
    $wpdb = h1bw1_fresh([h1bw1_group(1, 'Main')]); $wpdb->next_result['begin'] = $control_result;
    $archive_result = PGE_Event_Access_Repository::archive_group(10, 1, 7);
    h1bw1_check("START rejects $label for create and archive without mutation or cleanup", $create_start_closed
        && h1bw1_code($archive_result) === 'database_error' && $wpdb->groups[1]['status'] === 'active'
        && $wpdb->audits === [] && !in_array('COMMIT', $wpdb->sql, true) && !in_array('ROLLBACK', $wpdb->sql, true));

    $wpdb = h1bw1_fresh(); $wpdb->next_result['commit'] = $control_result;
    $create_result = PGE_Event_Access_Repository::create_group(10, 'Main', 7);
    $create_commit_closed = h1bw1_code($create_result) === 'database_error'
        && $wpdb->groups === [] && $wpdb->audits === []
        && count(array_keys($wpdb->sql, 'COMMIT', true)) === 1
        && count(array_keys($wpdb->sql, 'ROLLBACK', true)) === 1;
    $wpdb = h1bw1_fresh([h1bw1_group(1, 'Main')]); $wpdb->next_result['commit'] = $control_result;
    $archive_result = PGE_Event_Access_Repository::archive_group(10, 1, 7);
    h1bw1_check("COMMIT rejects $label for create and archive without blind retry", $create_commit_closed
        && h1bw1_code($archive_result) === 'database_error' && $wpdb->groups[1]['status'] === 'active'
        && $wpdb->audits === [] && count(array_keys($wpdb->sql, 'COMMIT', true)) === 1
        && count(array_keys($wpdb->sql, 'ROLLBACK', true)) === 1);

    $wpdb = h1bw1_fresh(); $wpdb->next_result['insert_audit'] = 2; $wpdb->next_result['rollback'] = $control_result;
    $create_result = PGE_Event_Access_Repository::create_group(10, 'Main', 7);
    $wpdb = h1bw1_fresh([h1bw1_group(1, 'Changed')]); $wpdb->next_result['rollback'] = $control_result;
    $rename_result = PGE_Event_Access_Repository::rename_group(10, 1, 'Old', 'New', 7);
    h1bw1_check("ROLLBACK rejects $label without hiding the original operation error", h1bw1_code($create_result) === 'database_error'
        && h1bw1_code($rename_result) === 'concurrent_update' && !in_array('COMMIT', $wpdb->sql, true)
        && count(array_keys($wpdb->sql, 'ROLLBACK', true)) === 1);
}
$wpdb = h1bw1_fresh([h1bw1_group(1, 'Changed')]);
$rollback_zero = PGE_Event_Access_Repository::rename_group(10, 1, 'Old', 'New', 7);
h1bw1_check('integer zero proves successful START and ROLLBACK control commands', h1bw1_code($rollback_zero) === 'concurrent_update' && in_array('START TRANSACTION', $wpdb->sql, true) && in_array('ROLLBACK', $wpdb->sql, true));
$wpdb = h1bw1_fresh(); $wpdb->next_read_result['lock'] = new stdClass();
h1bw1_check('malformed lock result fails closed and attempts rollback', h1bw1_code(PGE_Event_Access_Repository::create_group(10, 'Main', 7)) === 'database_error' && in_array('ROLLBACK', $wpdb->sql, true));
$wpdb = h1bw1_fresh(); $wpdb->next_result['insert_audit'] = 2;
h1bw1_check('audit affected rows above one rolls back the mutation', h1bw1_code(PGE_Event_Access_Repository::create_group(10, 'Main', 7)) === 'database_error' && $wpdb->groups === [] && $wpdb->audits === []);
$wpdb = h1bw1_fresh(); $wpdb->throw_on = 'insert_audit';
$result = PGE_Event_Access_Repository::create_group(10, 'Main', 7);
h1bw1_check('Throwable after begin rolls back mutation and hides details', h1bw1_code($result) === 'database_error' && $wpdb->groups === [] && $wpdb->audits === [] && strpos($result->get_error_message(), 'synthetic') === false);
foreach ([0, 2, false, new stdClass()] as $bad_result) {
    $wpdb = h1bw1_fresh([h1bw1_group(1, 'Main')]); $wpdb->next_result['rename'] = $bad_result;
    $result = PGE_Event_Access_Repository::rename_group(10, 1, 'Main', 'New', 7);
    $expected = $bad_result === 0 ? 'concurrent_update' : 'database_error';
    h1bw1_check('mutation affected-row result fails closed and rolls back', h1bw1_code($result) === $expected && $wpdb->groups[1]['name'] === 'Main' && $wpdb->audits === []);
}
$wpdb = h1bw1_fresh(); $wpdb->last_error = 'preexisting diagnostic';
PGE_Event_Access_Repository::create_group(10, 'Main', 7);
h1bw1_check('transaction restores preexisting wpdb last_error', $wpdb->last_error === 'preexisting diagnostic');
$wpdb = h1bw1_fresh(); $wpdb->last_error = 'preexisting diagnostic'; $wpdb->next_result['commit'] = true;
$malformed_commit = PGE_Event_Access_Repository::create_group(10, 'Main', 7);
h1bw1_check('malformed control result still restores preexisting wpdb last_error', h1bw1_code($malformed_commit) === 'database_error' && $wpdb->last_error === 'preexisting diagnostic');

// Static scope and separation guards.
$source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-repository.php');
h1bw1_check('write API exists without authorization, advisory locks, hooks, or schema upgrade',
    preg_match('/public static function (?:create_group|rename_group|set_default_group|archive_group)\b/', $source) === 1
    && preg_match('/current_user_can|get_current_user_id|GET_LOCK|RELEASE_LOCK|LOCK TABLES|add_action\s*\(|maybe_upgrade\s*\(|dbDelta\s*\(/i', $source) === 0);
h1bw1_check('W1 contains no membership/access/assignment mutation, restore, delete, or cascade',
    preg_match('/public static function restore_group|DELETE\s+FROM|UPDATE\s+[^\n]*(?:host_memberships|host_group_access|group_assignments)|INSERT\s+INTO\s+[^\n]*(?:host_memberships|host_group_access|group_assignments)/i', $source) === 0);
h1bw1_check('all write SQL values are prepared and lock order is explicit', strpos($source, 'ORDER BY id FOR UPDATE') !== false && strpos($source, 'mutation_query(') !== false && strpos($source, '$wpdb->prefix') !== false);
h1bw1_check('name normalizer rejects actual angle brackets without raw wp_strip_all_tags comparison', strpos($source, "strpos(\$value, '<')") !== false
    && strpos($source, "strpos(\$value, '>')") !== false
    && preg_match('/wp_strip_all_tags\s*\(\s*\$value\s*\)\s*!==\s*\$value/', $source) === 0);
h1bw1_check('transaction controls accept only integer zero', strpos($source, '!is_int($result) || $result !== 0') !== false);
h1bw1_check('create clears and strictly validates insert_id before matching the reread row', strpos($source, '$wpdb->insert_id = 0;') !== false
    && strpos($source, '!is_int($group_id) || $group_id <= 0') !== false
    && strpos($source, '$group[\'name_key\'] !== $name[\'name_key\']') !== false);
h1bw1_check('repository never derives actor from current user', $GLOBALS['h1bw1_current_user_calls'] === 0);

echo "\nH1B-W1: {$passed}/" . ($passed + $failed) . " passed\n";
exit($failed === 0 ? 0 : 1);
