<?php
/**
 * اختبار Phase A — RSVP Schema Ownership and Alignment (Option A).
 * يشغّل دوال Schema الإنتاجية من rsvp-handler.php وPGE_Checkin_Schema ضد
 * Fake $wpdb محدود بالمخطط، بلا لمس قاعدة بيانات حقيقية.
 *
 * التشغيل: php tests/test-rsvp-schema-option-a.php
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');

function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}

$GLOBALS['pge_test_options'] = [];
function get_option($key, $default = false) { return $GLOBALS['pge_test_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['pge_test_options'][$key] = $value; return true; }

class PGE_RSVP_Schema_Test_WPDB
{
    public $prefix = 'wp_';
    public $table_exists = true;
    public $indexes = [];
    public $rsvps = [];
    public $rsvp_columns = [];
    public $audit_columns = [];
    public $queries = [];
    public $dbdelta_sql = [];
    public $fail_add_unique = false;
    public $pretend_add_unique_without_effect = false;

    public function __construct()
    {
        $this->rsvp_columns = [
            'id', 'event_id', 'guest_phone', 'guest_name', 'companions', 'note',
            'reply', 'checked_in', 'checked_in_at', 'created_at', 'updated_at',
        ];
    }

    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }

    public function prepare($sql, ...$args)
    {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[sd]/', $replacement, $sql, 1);
        }
        return $sql;
    }

    public function get_var($sql)
    {
        if (stripos($sql, 'SHOW TABLES LIKE') === 0) {
            return $this->table_exists ? $this->prefix . 'pge_event_rsvps' : null;
        }
        if (strpos($sql, 'pge_rsvp_duplicates') !== false) {
            $counts = [];
            foreach ($this->rsvps as $row) {
                $key = (int) $row['event_id'] . '|' . (string) $row['guest_phone'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            return count(array_filter($counts, fn($count) => $count > 1));
        }
        return null;
    }

    public function get_results($sql, $format = null)
    {
        if (stripos($sql, 'SHOW INDEX FROM') === 0) {
            $rows = [];
            foreach ($this->indexes as $name => $meta) {
                foreach ($meta['columns'] as $offset => $column) {
                    $rows[] = [
                        'Key_name' => $name,
                        'Non_unique' => $meta['non_unique'],
                        'Seq_in_index' => $offset + 1,
                        'Column_name' => $column,
                    ];
                }
            }
            return $rows;
        }
        if (stripos($sql, 'SHOW COLUMNS FROM ' . $this->prefix . 'pge_event_rsvps') === 0) {
            return array_map(fn($field) => ['Field' => $field], $this->rsvp_columns);
        }
        if (stripos($sql, 'SHOW COLUMNS FROM ' . $this->prefix . 'pge_checkin_audit_log') === 0) {
            return array_map(fn($field) => ['Field' => $field], $this->audit_columns);
        }
        if (stripos($sql, 'SELECT event_id, guest_phone FROM') === 0) {
            return array_values($this->rsvps);
        }
        return [];
    }

    public function query($sql)
    {
        $this->queries[] = $sql;

        if (stripos($sql, 'ADD UNIQUE KEY') !== false) {
            if ($this->fail_add_unique) return false;
            if ($this->pretend_add_unique_without_effect) return 1;
            if (stripos($sql, 'DROP INDEX event_phone') !== false) unset($this->indexes['event_phone']);
            $name = stripos($sql, 'event_phone_unique') !== false ? 'event_phone_unique' : 'event_phone';
            $this->indexes[$name] = ['non_unique' => 0, 'columns' => ['event_id', 'guest_phone']];
            return 1;
        }
        if (stripos($sql, 'DROP INDEX event_guest_phone') !== false) {
            unset($this->indexes['event_guest_phone']);
            return 1;
        }
        if (preg_match('/ADD COLUMN\s+(\w+)/i', $sql, $m)) {
            if (!in_array($m[1], $this->rsvp_columns, true)) $this->rsvp_columns[] = $m[1];
            return 1;
        }
        return 1;
    }

    public function apply_dbdelta($sql)
    {
        $this->dbdelta_sql[] = $sql;
        if (strpos($sql, 'pge_event_rsvps') !== false) {
            $this->table_exists = true;
            $this->indexes = [
                'PRIMARY' => ['non_unique' => 0, 'columns' => ['id']],
                'event_phone' => ['non_unique' => 0, 'columns' => ['event_id', 'guest_phone']],
                'event_id' => ['non_unique' => 1, 'columns' => ['event_id']],
            ];
        }
        if (strpos($sql, 'pge_checkin_audit_log') !== false) {
            $this->audit_columns = ['id', 'event_id', 'rsvp_id', 'assignment_id', 'method', 'expected_count', 'actual_count', 'entry_type', 'created_at'];
        }
    }
}

function dbDelta($sql) { $GLOBALS['wpdb']->apply_dbdelta($sql); }

require_once dirname(__DIR__) . '/includes/rsvp-handler.php';
require_once dirname(__DIR__) . '/includes/class-pge-checkin-schema.php';

$passed = 0;
$failed = 0;
function check_true($label, $condition)
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: $label\n"; }
    else { $failed++; echo "FAIL: $label\n"; }
}

function fresh_fake(array $indexes = [])
{
    $GLOBALS['pge_test_options'] = [];
    $fake = new PGE_RSVP_Schema_Test_WPDB();
    $fake->indexes = $indexes;
    $GLOBALS['wpdb'] = $fake;
    return $fake;
}

$base_indexes = [
    'PRIMARY' => ['non_unique' => 0, 'columns' => ['id']],
    'event_phone' => ['non_unique' => 0, 'columns' => ['event_id', 'guest_phone']],
    'event_id' => ['non_unique' => 1, 'columns' => ['event_id']],
];

// 1) Fresh installation.
$wpdb = fresh_fake();
$wpdb->table_exists = false;
check_true('Fresh migration succeeds', pge_maybe_upgrade_rsvp_schema() === true);
check_true('Fresh schema has PRIMARY(id)', isset($wpdb->indexes['PRIMARY']) && $wpdb->indexes['PRIMARY']['columns'] === ['id']);
check_true('Fresh schema has UNIQUE(event_id, guest_phone)', pge_rsvp_schema_has_unique_identity($wpdb->indexes));
check_true('Fresh schema does not create event_guest_phone', !isset($wpdb->indexes['event_guest_phone']));
check_true('Fresh CREATE SQL does not define event_guest_phone', strpos(implode("\n", $wpdb->dbdelta_sql), 'event_guest_phone') === false);

// 2/3/9) Existing correct + idempotency + no duplicate indexes.
$wpdb = fresh_fake($base_indexes);
$GLOBALS['pge_test_options'][PGE_RSVP_SCHEMA_VERSION_OPTION] = PGE_RSVP_SCHEMA_VERSION;
check_true('Existing correct schema succeeds', pge_maybe_upgrade_rsvp_schema() === true);
check_true('Existing correct schema performs no ALTER', count($wpdb->queries) === 0);
check_true('Second migration run succeeds', pge_maybe_upgrade_rsvp_schema() === true);
check_true('Second run remains a no-op', count($wpdb->queries) === 0 && count(array_filter($wpdb->indexes, fn($i) => $i['columns'] === ['event_id', 'guest_phone'] && $i['non_unique'] === 0)) === 1);

// 4/5) Duplicate data blocks migration and version advancement.
$wpdb = fresh_fake([
    'PRIMARY' => ['non_unique' => 0, 'columns' => ['id']],
    'event_guest_phone' => ['non_unique' => 1, 'columns' => ['event_id', 'guest_phone']],
]);
$wpdb->rsvps = [
    ['event_id' => 7, 'guest_phone' => '0500000000'],
    ['event_id' => 7, 'guest_phone' => '0500000000'],
];
check_true('Exact duplicates block migration', pge_maybe_upgrade_rsvp_schema() === false);
check_true('Version is not advanced after duplicate blocker', get_option(PGE_RSVP_SCHEMA_VERSION_OPTION, '') === '');
check_true('Duplicate blocker does not alter indexes', count($wpdb->queries) === 0 && isset($wpdb->indexes['event_guest_phone']));

// Normalization collision is also fail-closed.
$wpdb = fresh_fake(['PRIMARY' => ['non_unique' => 0, 'columns' => ['id']]]);
$wpdb->rsvps = [
    ['event_id' => 8, 'guest_phone' => '050-000-0001'],
    ['event_id' => 8, 'guest_phone' => '0500000001'],
];
check_true('Normalization collision blocks migration', pge_maybe_upgrade_rsvp_schema() === false);
check_true('Normalization collision does not advance version', get_option(PGE_RSVP_SCHEMA_VERSION_OPTION, '') === '');

// 6) Redundant index is removed only when UNIQUE already verifies.
$with_extra = $base_indexes;
$with_extra['event_guest_phone'] = ['non_unique' => 1, 'columns' => ['event_id', 'guest_phone']];
$wpdb = fresh_fake($with_extra);
check_true('Existing UNIQUE plus redundant index migrates successfully', pge_maybe_upgrade_rsvp_schema() === true);
check_true('Redundant index is removed after UNIQUE verification', !isset($wpdb->indexes['event_guest_phone']) && count($wpdb->queries) === 1);

// ADD UNIQUE failure keeps fallback index and version unchanged.
$wpdb = fresh_fake([
    'PRIMARY' => ['non_unique' => 0, 'columns' => ['id']],
    'event_guest_phone' => ['non_unique' => 1, 'columns' => ['event_id', 'guest_phone']],
]);
$wpdb->fail_add_unique = true;
check_true('ADD UNIQUE failure returns false', pge_maybe_upgrade_rsvp_schema() === false);
check_true('ADD UNIQUE failure preserves event_guest_phone', isset($wpdb->indexes['event_guest_phone']));
check_true('ADD UNIQUE failure does not advance version', get_option(PGE_RSVP_SCHEMA_VERSION_OPTION, '') === '');

// ALTER success alone is not trusted; SHOW INDEX postcondition must pass.
$wpdb = fresh_fake(['PRIMARY' => ['non_unique' => 0, 'columns' => ['id']]]);
$wpdb->pretend_add_unique_without_effect = true;
check_true('Unverified ALTER success is rejected', pge_maybe_upgrade_rsvp_schema() === false);
check_true('Unverified ALTER does not advance version', get_option(PGE_RSVP_SCHEMA_VERSION_OPTION, '') === '');

// 8) Stored current version does not hide schema drift.
$wpdb = fresh_fake([
    'PRIMARY' => ['non_unique' => 0, 'columns' => ['id']],
    'event_guest_phone' => ['non_unique' => 1, 'columns' => ['event_id', 'guest_phone']],
]);
$GLOBALS['pge_test_options'][PGE_RSVP_SCHEMA_VERSION_OPTION] = PGE_RSVP_SCHEMA_VERSION;
check_true('Schema drift is repaired even with current stored version', pge_maybe_upgrade_rsvp_schema() === true && pge_rsvp_schema_has_unique_identity($wpdb->indexes));
check_true('Drift repair removes redundant fallback only after UNIQUE', !isset($wpdb->indexes['event_guest_phone']));

// فهرس event_phone غير الفريد يُستبدل atomically ثم يُحذف fallback الزائد.
$wpdb = fresh_fake([
    'PRIMARY' => ['non_unique' => 0, 'columns' => ['id']],
    'event_phone' => ['non_unique' => 1, 'columns' => ['event_id', 'guest_phone']],
    'event_guest_phone' => ['non_unique' => 1, 'columns' => ['event_id', 'guest_phone']],
]);
check_true('Non-unique event_phone is replaced by the canonical UNIQUE', pge_maybe_upgrade_rsvp_schema() === true && isset($wpdb->indexes['event_phone']) && $wpdb->indexes['event_phone']['non_unique'] === 0);
check_true('Fallback index is removed after atomic replacement verifies', !isset($wpdb->indexes['event_guest_phone']));

// 7/10) Check-in schema retains unrelated functionality and never changes identity index.
$wpdb = fresh_fake($base_indexes);
$GLOBALS['pge_test_options'][PGE_Checkin_Schema::VERSION_OPTION] = '';
check_true('Check-in schema upgrade succeeds for audit table and columns', (PGE_Checkin_Schema::maybe_upgrade() === null) && get_option(PGE_Checkin_Schema::VERSION_OPTION, '') === PGE_Checkin_Schema::SCHEMA_VERSION);
check_true('Check-in audit table remains intact', in_array('entry_type', $wpdb->audit_columns, true));
check_true('Check-in RSVP columns remain intact', in_array('checked_in_by_assignment_id', $wpdb->rsvp_columns, true) && in_array('checkin_method', $wpdb->rsvp_columns, true) && in_array('actual_entered_count', $wpdb->rsvp_columns, true));
check_true('Check-in schema leaves RSVP UNIQUE unchanged', pge_rsvp_schema_has_unique_identity($wpdb->indexes));
$checkin_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-checkin-schema.php');
check_true('Check-in schema contains no identity-index migration', strpos($checkin_source, 'ensure_phone_index_not_unique') === false && strpos($checkin_source, 'DROP INDEX') === false && strpos($checkin_source, 'event_guest_phone') === false);

echo "\nRESULT: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
