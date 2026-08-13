<?php
/**
 * Phase H1A — Event Host Access Schema Foundation.
 *
 * Run: php tests/test-event-access-schema-phase-h1a.php
 *
 * This runs the production schema class against a contract-focused fake wpdb.
 * It proves control flow, emitted DDL, drift handling, structural checks, data
 * diagnostics, and lock/version behavior. It does not prove real dbDelta,
 * InnoDB UNIQUE/NULL behavior, or concurrent MySQL connections.
 */

define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['h1a_options'] = [];
$GLOBALS['h1a_option_updates'] = [];
$GLOBALS['h1a_actions'] = [];
$GLOBALS['h1a_activation_hooks'] = [];
$GLOBALS['h1a_fail_option_update'] = false;
$GLOBALS['h1a_fail_option_keys'] = [];
$GLOBALS['h1a_fail_option_on_write'] = [];
$GLOBALS['h1a_fail_option_on_writes'] = [];
$GLOBALS['h1a_option_reread_mismatch_on_write'] = [];
$GLOBALS['h1a_option_read_overrides'] = [];
$GLOBALS['h1a_option_write_counts'] = [];
$GLOBALS['h1a_option_reads'] = [];
$GLOBALS['h1a_now'] = time();
$GLOBALS['h1a_generation_sequence'] = 0;
$GLOBALS['h1a_generation_values'] = [];
$GLOBALS['h1a_cache_deletes'] = [];

function get_option($key, $default = false) {
    $GLOBALS['h1a_option_reads'][] = $key;
    if (!empty($GLOBALS['h1a_option_read_overrides'][$key])) {
        return array_shift($GLOBALS['h1a_option_read_overrides'][$key]);
    }
    return $GLOBALS['h1a_options'][$key] ?? $default;
}
function update_option($key, $value) {
    $GLOBALS['h1a_option_updates'][] = [$key, $value];
    $GLOBALS['h1a_option_write_counts'][$key] = ($GLOBALS['h1a_option_write_counts'][$key] ?? 0) + 1;
    $write_number = $GLOBALS['h1a_option_write_counts'][$key];
    $fail_on = $GLOBALS['h1a_fail_option_on_write'][$key] ?? null;
    $fail_on_many = $GLOBALS['h1a_fail_option_on_writes'][$key] ?? [];
    if ($GLOBALS['h1a_fail_option_update'] || in_array($key, $GLOBALS['h1a_fail_option_keys'], true) || $fail_on === $write_number || in_array($write_number, $fail_on_many, true)) return false;
    $GLOBALS['h1a_options'][$key] = $value;
    if (array_key_exists($write_number, $GLOBALS['h1a_option_reread_mismatch_on_write'][$key] ?? [])) {
        $GLOBALS['h1a_option_read_overrides'][$key][] = $GLOBALS['h1a_option_reread_mismatch_on_write'][$key][$write_number];
    }
    return true;
}
function maybe_serialize($value) { return is_array($value) || is_object($value) ? serialize($value) : (string) $value; }
function wp_cache_delete($key, $group = '') { $GLOBALS['h1a_cache_deletes'][] = [$key, $group]; return true; }
function current_time($type, $gmt = false) { return $GLOBALS['h1a_now']; }
function wp_generate_uuid4() {
    if ($GLOBALS['h1a_generation_values']) return array_shift($GLOBALS['h1a_generation_values']);
    $GLOBALS['h1a_generation_sequence']++;
    return sprintf('00000000-0000-4000-8000-%012d', $GLOBALS['h1a_generation_sequence']);
}
function add_action($hook, $callback) { $GLOBALS['h1a_actions'][] = [$hook, $callback]; }
function register_activation_hook($file, $callback) { $GLOBALS['h1a_activation_hooks'][] = [$file, $callback]; }
function dbDelta($sql) { $GLOBALS['wpdb']->apply_create($sql); }

final class PGE_H1A_Fake_WPDB
{
    public $prefix = 'tenant_';
    public $options = 'tenant_options';
    public $charset = 'utf8mb4';
    public $collate = 'utf8mb4_unicode_ci';
    public $last_error = '';
    public $tables = [];
    public $queries = [];
    public $dbdelta_sql = [];
    public $lock_result = 1;
    public $release_result = 1;
    public $release_count = 0;
    public $fail_next_alter = false;
    public $pretend_alter_success = false;
    public $on_get_lock = null;
    public $on_release_lock = null;
    public $throw_on_show = false;
    public $fail_constraint_metadata = false;
    public $fail_table_metadata = false;
    public $database_result = 'h1a_test';
    public $constraint_rows_override = [];
    public $on_option_cas = null;
    public $fail_next_cas = false;
    public $malformed_next_cas_result = null;

    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET ' . $this->charset . ($this->collate !== '' ? ' COLLATE ' . $this->collate : '');
    }
    public function esc_like($text) { return addcslashes($text, '_%\\'); }

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
        $this->queries[] = $sql;
        if (stripos($sql, 'SELECT GET_LOCK(') === 0) {
            if (is_callable($this->on_get_lock)) {
                $callback = $this->on_get_lock;
                $this->on_get_lock = null;
                $callback();
            }
            return $this->lock_result;
        }
        if (stripos($sql, 'SELECT RELEASE_LOCK(') === 0) {
            $this->release_count++;
            if (is_callable($this->on_release_lock)) {
                $callback = $this->on_release_lock;
                $this->on_release_lock = null;
                $callback();
            }
            return $this->release_result;
        }
        if (strcasecmp(trim($sql), 'SELECT DATABASE()') === 0) return $this->database_result;
        if (preg_match("/^SHOW TABLES LIKE '([^']+)'$/i", $sql, $m)) {
            $table = strtr($m[1], ['\\_' => '_', '\\%' => '%', '\\\\' => '\\']);
            return isset($this->tables[$table]) ? $table : null;
        }

        if (preg_match('/^SELECT COUNT\(\*\) FROM ([a-zA-Z0-9_]+)$/i', $sql, $m)) {
            return isset($this->tables[$m[1]]) ? count($this->tables[$m[1]]['rows']) : null;
        }

        return $this->diagnostic_count($sql);
    }

    public function get_results($sql, $format = null)
    {
        $this->queries[] = $sql;
        if ($this->throw_on_show && stripos($sql, 'SHOW ') === 0) {
            throw new RuntimeException('synthetic show failure');
        }
        if (preg_match('/^SHOW FULL COLUMNS FROM ([a-zA-Z0-9_]+)$/i', $sql, $m)) {
            return isset($this->tables[$m[1]]) ? array_values($this->tables[$m[1]]['columns']) : null;
        }
        if (preg_match('/^SHOW INDEX FROM ([a-zA-Z0-9_]+)$/i', $sql, $m)) {
            if (!isset($this->tables[$m[1]])) return null;
            $rows = [];
            foreach ($this->tables[$m[1]]['indexes'] as $name => $index) {
                foreach ($index['columns'] as $offset => $column) {
                    $row = [
                        'Key_name' => $name,
                        'Non_unique' => $index['non_unique'],
                        'Seq_in_index' => $offset + 1,
                        'Column_name' => $column,
                    ];
                    if (($index['sub_parts'][$offset] ?? null) !== '__missing__') {
                        $row['Sub_part'] = array_key_exists($offset, $index['sub_parts']) ? $index['sub_parts'][$offset] : null;
                    }
                    $rows[] = $row;
                }
            }
            return $rows;
        }
        if (stripos($sql, 'SELECT CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS ') === 0) {
            if ($this->fail_constraint_metadata) return null;
            if (!preg_match("/TABLE_NAME = '([^']+)'$/i", $sql, $m) || !isset($this->tables[$m[1]])) return [];
            if (array_key_exists($m[1], $this->constraint_rows_override)) return $this->constraint_rows_override[$m[1]];
            $rows = [];
            foreach ($this->tables[$m[1]]['constraints'] as $constraint) {
                $rows[] = ['CONSTRAINT_TYPE' => is_array($constraint) ? $constraint['type'] : $constraint];
            }
            return $rows;
        }
        return null;
    }

    public function get_row($sql, $format = null)
    {
        $this->queries[] = $sql;
        if (stripos($sql, 'SELECT t.TABLE_COLLATION, c.CHARACTER_SET_NAME FROM information_schema.TABLES ') === 0) {
            if ($this->fail_table_metadata) return null;
            if (!preg_match("/t\.TABLE_NAME = '([^']+)'$/i", $sql, $m) || !isset($this->tables[$m[1]])) return null;
            return [
                'TABLE_COLLATION' => $this->tables[$m[1]]['table_collation'] ?? null,
                'CHARACTER_SET_NAME' => $this->tables[$m[1]]['table_charset'] ?? null,
            ];
        }
        if (preg_match('/^SHOW CREATE TABLE ([a-zA-Z0-9_]+)$/i', $sql, $m) && isset($this->tables[$m[1]])) {
            return ['Table' => $m[1], 'Create Table' => $this->tables[$m[1]]['create_sql']];
        }
        return null;
    }

    public function query($sql)
    {
        $this->queries[] = $sql;
        if (preg_match("/^UPDATE `([a-zA-Z0-9_]+)` SET option_value = '((?:''|[^'])*)' WHERE option_name = '((?:''|[^'])*)' AND BINARY option_value = '((?:''|[^'])*)'$/s", $sql, $m)) {
            if ($m[1] !== $this->options) return false;
            $desired_serialized = str_replace("''", "'", $m[2]);
            $key = str_replace("''", "'", $m[3]);
            $expected_serialized = str_replace("''", "'", $m[4]);
            $desired = unserialize($desired_serialized);
            $expected = unserialize($expected_serialized);
            if (is_callable($this->on_option_cas)) {
                $callback = $this->on_option_cas;
                $this->on_option_cas = null;
                $callback($key, $expected, $desired);
            }
            $GLOBALS['h1a_option_write_counts'][$key] = ($GLOBALS['h1a_option_write_counts'][$key] ?? 0) + 1;
            $write_number = $GLOBALS['h1a_option_write_counts'][$key];
            $GLOBALS['h1a_option_updates'][] = [$key, $desired];
            $fail_on = $GLOBALS['h1a_fail_option_on_write'][$key] ?? null;
            $fail_on_many = $GLOBALS['h1a_fail_option_on_writes'][$key] ?? [];
            if ($this->fail_next_cas || $GLOBALS['h1a_fail_option_update'] || in_array($key, $GLOBALS['h1a_fail_option_keys'], true) || $fail_on === $write_number || in_array($write_number, $fail_on_many, true)) {
                $this->fail_next_cas = false;
                return false;
            }
            if ($this->malformed_next_cas_result !== null) {
                $result = $this->malformed_next_cas_result;
                $this->malformed_next_cas_result = null;
                return $result;
            }
            if (!array_key_exists($key, $GLOBALS['h1a_options']) || maybe_serialize($GLOBALS['h1a_options'][$key]) !== $expected_serialized) return 0;
            $GLOBALS['h1a_options'][$key] = $desired;
            if (array_key_exists($write_number, $GLOBALS['h1a_option_reread_mismatch_on_write'][$key] ?? [])) {
                $GLOBALS['h1a_option_read_overrides'][$key][] = $GLOBALS['h1a_option_reread_mismatch_on_write'][$key][$write_number];
            }
            return 1;
        }
        if ($this->fail_next_alter && stripos($sql, 'ALTER TABLE ') === 0) {
            $this->fail_next_alter = false;
            return false;
        }
        if ($this->pretend_alter_success && stripos($sql, 'ALTER TABLE ') === 0) return 1;

        if (preg_match('/^ALTER TABLE ([a-zA-Z0-9_]+) ADD COLUMN ([a-zA-Z0-9_]+) (.+)$/i', $sql, $m)) {
            if (!isset($this->tables[$m[1]])) return false;
            $this->tables[$m[1]]['columns'][$m[2]] = $this->parse_column($m[2], $m[3]);
            return 1;
        }
        if (preg_match('/^ALTER TABLE ([a-zA-Z0-9_]+) ADD PRIMARY KEY \(([^)]+)\)$/i', $sql, $m)) {
            $columns = $this->column_list($m[2]);
            $this->tables[$m[1]]['indexes']['PRIMARY'] = ['non_unique' => 0, 'columns' => $columns, 'sub_parts' => array_fill(0, count($columns), null)];
            return 1;
        }
        if (preg_match('/^ALTER TABLE ([a-zA-Z0-9_]+) ADD (UNIQUE KEY|KEY) ([a-zA-Z0-9_]+) \(([^)]+)\)$/i', $sql, $m)) {
            $columns = $this->column_list($m[4]);
            $this->tables[$m[1]]['indexes'][$m[3]] = [
                'non_unique' => strtoupper($m[2]) === 'UNIQUE KEY' ? 0 : 1,
                'columns' => $columns,
                'sub_parts' => array_fill(0, count($columns), null),
            ];
            return 1;
        }
        return 0;
    }

    public function apply_create($sql)
    {
        $this->dbdelta_sql[] = $sql;
        if (!preg_match('/CREATE TABLE ([a-zA-Z0-9_]+) \((.*)\)\s+DEFAULT CHARACTER SET/si', $sql, $m)) return;
        $table = $m[1];
        $columns = [];
        $indexes = [];
        foreach (preg_split('/\R/', trim($m[2])) as $line) {
            $line = trim(rtrim(trim($line), ','));
            if ($line === '') continue;
            if (preg_match('/^PRIMARY KEY \(([^)]+)\)$/i', $line, $im)) {
                $index_columns = $this->column_list($im[1]);
                $indexes['PRIMARY'] = ['non_unique' => 0, 'columns' => $index_columns, 'sub_parts' => array_fill(0, count($index_columns), null)];
            } elseif (preg_match('/^(UNIQUE KEY|KEY) ([a-zA-Z0-9_]+) \(([^)]+)\)$/i', $line, $im)) {
                $index_columns = $this->column_list($im[3]);
                $indexes[$im[2]] = ['non_unique' => strtoupper($im[1]) === 'UNIQUE KEY' ? 0 : 1, 'columns' => $index_columns, 'sub_parts' => array_fill(0, count($index_columns), null)];
            } elseif (preg_match('/^([a-zA-Z0-9_]+)\s+(.+)$/', $line, $cm)) {
                $columns[$cm[1]] = $this->parse_column($cm[1], $cm[2]);
            }
        }
        $constraints = [];
        foreach ($indexes as $name => $index) {
            if ($name === 'PRIMARY') $constraints[] = 'PRIMARY KEY';
            elseif ($index['non_unique'] === 0) $constraints[] = 'UNIQUE';
        }
        $table_collation = $this->collate !== '' ? $this->collate : $this->charset . '_general_ci';
        $this->tables[$table] = ['columns' => $columns, 'indexes' => $indexes, 'constraints' => $constraints, 'rows' => [], 'create_sql' => $sql, 'table_collation' => $table_collation, 'table_charset' => $this->charset];
    }

    public function reset_observation()
    {
        $this->queries = [];
        $this->dbdelta_sql = [];
        $this->release_count = 0;
        $GLOBALS['h1a_option_updates'] = [];
        $GLOBALS['h1a_option_reads'] = [];
        $GLOBALS['h1a_cache_deletes'] = [];
    }

    public function table($suffix) { return $this->prefix . $suffix; }
    public function remove_column($suffix, $name) { unset($this->tables[$this->table($suffix)]['columns'][$name]); }
    public function remove_index($suffix, $name) { unset($this->tables[$this->table($suffix)]['indexes'][$name]); }
    public function add_row($suffix, array $row) { $this->tables[$this->table($suffix)]['rows'][] = $row; }
    public function set_column($suffix, $name, array $changes) {
        $table = $this->table($suffix);
        $this->tables[$table]['columns'][$name] = array_merge($this->tables[$table]['columns'][$name], $changes);
    }
    public function add_index($suffix, $name, array $columns, $unique = false, array $sub_parts = []) {
        if (!$sub_parts) $sub_parts = array_fill(0, count($columns), null);
        $this->tables[$this->table($suffix)]['indexes'][$name] = ['non_unique' => $unique ? 0 : 1, 'columns' => $columns, 'sub_parts' => $sub_parts];
    }
    public function set_index_sub_parts($suffix, $name, array $sub_parts) { $this->tables[$this->table($suffix)]['indexes'][$name]['sub_parts'] = $sub_parts; }
    public function add_constraint($suffix, $type, $name = null) { $this->tables[$this->table($suffix)]['constraints'][] = ['type' => $type, 'name' => $name]; }
    public function set_table_collation($suffix, $collation) { $this->tables[$this->table($suffix)]['table_collation'] = $collation; }
    public function set_table_charset($suffix, $charset) { $this->tables[$this->table($suffix)]['table_charset'] = $charset; }
    public function set_constraint_rows($suffix, array $rows) { $this->constraint_rows_override[$this->table($suffix)] = $rows; }
    public function append_create_sql($suffix, $sql) {
        $table = $this->table($suffix);
        $this->tables[$table]['create_sql'] = preg_replace(
            '/\)\s+DEFAULT CHARACTER SET/i',
            ",\n                $sql\n            ) DEFAULT CHARACTER SET",
            $this->tables[$table]['create_sql'],
            1
        );
    }

    private function parse_column($name, $definition)
    {
        preg_match('/^(BIGINT(?:\(20\))? UNSIGNED|TINYINT(?:\(1\))? UNSIGNED|VARCHAR\(\d+\)|DATETIME(?:\(\d+\))?|LONGTEXT)/i', trim($definition), $tm);
        $default = null;
        if (preg_match("/DEFAULT\s+'([^']*)'/i", $definition, $dm)) $default = $dm[1];
        $type = strtolower($tm[1] ?? '');
        return [
            'Field' => $name,
            'Type' => $type,
            'Null' => stripos($definition, 'NOT NULL') !== false ? 'NO' : 'YES',
            'Default' => $default,
            'Extra' => stripos($definition, 'AUTO_INCREMENT') !== false ? 'auto_increment' : '',
            'Collation' => preg_match('/^(?:varchar|longtext)/', $type) ? ($this->collate !== '' ? $this->collate : $this->charset . '_general_ci') : null,
        ];
    }

    private function column_list($raw) { return array_map('trim', explode(',', $raw)); }

    private function rows($suffix) { return $this->tables[$this->table($suffix)]['rows'] ?? []; }

    private function diagnostic_count($sql)
    {
        if (strpos($sql, 'default_slot IS NOT NULL AND default_slot <> 1') !== false) {
            return count(array_filter($this->rows('pge_event_invitation_groups'), fn($r) => array_key_exists('default_slot', $r) && $r['default_slot'] !== null && (int) $r['default_slot'] !== 1));
        }
        if (strpos($sql, "status NOT IN ('active', 'archived')") !== false) {
            return count(array_filter($this->rows('pge_event_invitation_groups'), fn($r) => !in_array($r['status'] ?? '', ['active', 'archived'], true)));
        }
        if (strpos($sql, "status = 'active'") !== false && strpos($sql, 'name_key IS NULL') !== false) {
            return count(array_filter($this->rows('pge_event_invitation_groups'), fn($r) => ($r['status'] ?? '') === 'active' && (($r['name_key'] ?? null) === null || ($r['name_key'] ?? '') === '')));
        }
        if (strpos($sql, "status = 'archived'") !== false) {
            return count(array_filter($this->rows('pge_event_invitation_groups'), fn($r) => ($r['status'] ?? '') === 'archived' && (($r['name_key'] ?? null) !== null || ($r['default_slot'] ?? null) !== null)));
        }
        if (strpos($sql, "role NOT IN ('manager', 'viewer')") !== false) {
            return count(array_filter($this->rows('pge_event_host_memberships'), fn($r) => !in_array($r['role'] ?? '', ['manager', 'viewer'], true)));
        }
        if (strpos($sql, "status NOT IN ('active', 'revoked')") !== false) {
            return count(array_filter($this->rows('pge_event_host_memberships'), fn($r) => !in_array($r['status'] ?? '', ['active', 'revoked'], true)));
        }
        if (strpos($sql, 'pge_h1a_duplicates') !== false) return $this->duplicate_count_for_sql($sql);
        if (strpos($sql, ' LEFT JOIN ') !== false) return $this->relationship_error_count($sql);
        return null;
    }

    private function duplicate_count_for_sql($sql)
    {
        $map = [
            'pge_event_invitation_groups' => [strpos($sql, 'SELECT id ') !== false ? ['id'] : (strpos($sql, 'default_slot') !== false ? ['event_id', 'default_slot'] : ['event_id', 'name_key']), strpos($sql, 'SELECT id ') !== false ? null : (strpos($sql, 'default_slot') !== false ? 'default_slot' : 'name_key')],
            'pge_event_host_memberships' => [['event_id', 'user_id'], null],
            'pge_event_host_group_access' => [['membership_id', 'group_id'], null],
            'pge_invitation_group_assignments' => [['event_id', 'guest_phone'], null],
            'pge_event_access_audit_log' => [['id'], null],
        ];
        foreach ($map as $suffix => [$columns, $nullable_key]) {
            if (strpos($sql, $this->table($suffix)) === false) continue;
            if (strpos($sql, 'SELECT id ') !== false) $columns = ['id'];
            $seen = [];
            $duplicates = 0;
            foreach ($this->rows($suffix) as $row) {
                if ($nullable_key !== null && ($row[$nullable_key] ?? null) === null) continue;
                $key = implode('|', array_map(fn($c) => (string) ($row[$c] ?? ''), $columns));
                if (isset($seen[$key])) $duplicates++; else $seen[$key] = true;
            }
            return $duplicates;
        }
        return null;
    }

    private function relationship_error_count($sql)
    {
        $groups = [];
        foreach ($this->rows('pge_event_invitation_groups') as $row) $groups[(int) ($row['id'] ?? 0)] = $row;
        if (strpos($sql, $this->table('pge_event_host_group_access')) !== false) {
            $memberships = [];
            foreach ($this->rows('pge_event_host_memberships') as $row) $memberships[(int) ($row['id'] ?? 0)] = $row;
            $bad = 0;
            foreach ($this->rows('pge_event_host_group_access') as $row) {
                $m = $memberships[(int) ($row['membership_id'] ?? 0)] ?? null;
                $g = $groups[(int) ($row['group_id'] ?? 0)] ?? null;
                if (!$m || !$g || (int) $row['event_id'] !== (int) $m['event_id'] || (int) $row['event_id'] !== (int) $g['event_id']) $bad++;
            }
            return $bad;
        }
        if (strpos($sql, $this->table('pge_invitation_group_assignments')) !== false) {
            $bad = 0;
            foreach ($this->rows('pge_invitation_group_assignments') as $row) {
                $g = $groups[(int) ($row['group_id'] ?? 0)] ?? null;
                if (!$g || (int) $row['event_id'] !== (int) $g['event_id']) $bad++;
            }
            return $bad;
        }
        return null;
    }
}

require_once PGE_PATH . 'includes/class-pge-event-access-schema.php';

$passed = 0;
$failed = 0;
function h1a_check($label, $condition)
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

function h1a_fresh($prefix = 'tenant_')
{
    global $wpdb;
    $wpdb = new PGE_H1A_Fake_WPDB();
    $wpdb->prefix = $prefix;
    $wpdb->options = $prefix . 'options';
    $GLOBALS['h1a_options'] = [];
    $GLOBALS['h1a_option_updates'] = [];
    $GLOBALS['h1a_fail_option_update'] = false;
    $GLOBALS['h1a_fail_option_keys'] = [];
    $GLOBALS['h1a_fail_option_on_write'] = [];
    $GLOBALS['h1a_fail_option_on_writes'] = [];
    $GLOBALS['h1a_option_reread_mismatch_on_write'] = [];
    $GLOBALS['h1a_option_read_overrides'] = [];
    $GLOBALS['h1a_option_write_counts'] = [];
    $GLOBALS['h1a_option_reads'] = [];
    $GLOBALS['h1a_now'] = time();
    $GLOBALS['h1a_generation_sequence'] = 0;
    $GLOBALS['h1a_generation_values'] = [];
    $GLOBALS['h1a_cache_deletes'] = [];
    h1a_reset_schema_request_state();
    return $wpdb;
}

function h1a_reset_schema_request_state()
{
    $reflection = new ReflectionClass(PGE_Event_Access_Schema::class);
    foreach ([
        'running' => false,
        'request_completed' => false,
        'request_result' => false,
        'suppress_logging' => false,
        'database_name' => null,
        'last_error_code' => '',
    ] as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}

function h1a_expire_health_marker()
{
    $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION]['checked_at'] = $GLOBALS['h1a_now'] - PGE_Event_Access_Schema::HEALTH_TTL_SECONDS - 1;
}

function h1a_install_healthy($prefix = 'tenant_', $keep_health_current = false)
{
    $fake = h1a_fresh($prefix);
    if (!PGE_Event_Access_Schema::activate()) throw new RuntimeException('fixture install failed');
    $fake->reset_observation();
    h1a_reset_schema_request_state();
    if (!$keep_health_current) h1a_expire_health_marker();
    return $fake;
}

function h1a_check_invalid_fast_timestamp($label, $marker, $field, $value)
{
    $fake = h1a_install_healthy('tenant_', true);
    $GLOBALS['h1a_options'][$marker][$field] = $value;
    $result = PGE_Event_Access_Schema::maybe_upgrade();
    $lock_count = count(array_filter($fake->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0));
    h1a_check($label, $result === true && $lock_count === 1);
}

function h1a_expected_suffixes()
{
    return [
        'pge_event_invitation_groups', 'pge_event_host_memberships',
        'pge_event_host_group_access', 'pge_invitation_group_assignments',
        'pge_event_access_audit_log',
    ];
}

function h1a_capture_error_log(callable $callback)
{
    $path = tempnam(sys_get_temp_dir(), 'pge-h1a-log-');
    $old_log_errors = ini_get('log_errors');
    $old_error_log = ini_get('error_log');
    ini_set('log_errors', '1');
    ini_set('error_log', $path);
    try {
        $result = $callback();
        $log = (string) file_get_contents($path);
    } finally {
        ini_set('error_log', (string) $old_error_log);
        ini_set('log_errors', (string) $old_log_errors);
        @unlink($path);
    }
    return [$result, $log];
}

// Hook registration and fresh install.
h1a_check('activation hook registered exactly once', count($GLOBALS['h1a_activation_hooks']) === 1 && $GLOBALS['h1a_activation_hooks'][0][0] === PGE_PATH . 'pgevents-core.php' && $GLOBALS['h1a_activation_hooks'][0][1] === [PGE_Event_Access_Schema::class, 'activate']);
h1a_check('runtime upgrade hook registered exactly once', count($GLOBALS['h1a_actions']) === 1 && $GLOBALS['h1a_actions'][0][0] === 'plugins_loaded' && $GLOBALS['h1a_actions'][0][1] === [PGE_Event_Access_Schema::class, 'maybe_upgrade']);
$wpdb = h1a_fresh('site7_');
h1a_check('fresh install succeeds', PGE_Event_Access_Schema::maybe_upgrade() === true);
h1a_check('fresh install creates exactly five tables through dbDelta', count($wpdb->dbdelta_sql) === 5 && count($wpdb->tables) === 5);
h1a_check('all table names honor a non-default prefix', array_keys($wpdb->tables) === array_map(fn($s) => 'site7_' . $s, h1a_expected_suffixes()));
h1a_check('table existence checks escape LIKE wildcards in the prefix and table name', strpos(implode("\n", $wpdb->queries), "SHOW TABLES LIKE 'site7\\_pge\\_event") !== false);
h1a_check('fresh install stores schema version only after success', get_option(PGE_Event_Access_Schema::VERSION_OPTION, '') === '1.0.0');
$fresh_health = get_option(PGE_Event_Access_Schema::HEALTH_OPTION, null);
h1a_check('successful full check stores a generation-bound healthy marker', is_array($fresh_health) && array_keys($fresh_health) === ['schema_version', 'checked_at', 'status', 'generation'] && $fresh_health['schema_version'] === PGE_Event_Access_Schema::SCHEMA_VERSION && is_int($fresh_health['checked_at']) && $fresh_health['status'] === 'healthy' && is_string($fresh_health['generation']));
h1a_check('fresh install creates no rows or backfill', array_sum(array_map(fn($t) => count($t['rows']), $wpdb->tables)) === 0);
h1a_check('owned lock is released after successful install', $wpdb->release_count === 1);

$all_sql = implode("\n", $wpdb->dbdelta_sql);
h1a_check('approved nullable default-slot contract is emitted', strpos($all_sql, 'default_slot TINYINT(1) UNSIGNED NULL DEFAULT NULL') !== false && strpos($all_sql, 'UNIQUE KEY event_default_slot (event_id, default_slot)') !== false);
h1a_check('membership identity and role/status columns are emitted', strpos($all_sql, 'UNIQUE KEY event_user (event_id, user_id)') !== false && strpos($all_sql, 'role VARCHAR(20) NOT NULL') !== false);
h1a_check('all approved composite indexes are emitted', strpos($all_sql, 'KEY event_status (event_id, status, id)') !== false && strpos($all_sql, 'KEY event_membership (event_id, membership_id, group_id)') !== false && strpos($all_sql, 'KEY actor_created (actor_user_id, created_at, id)') !== false);
h1a_check('schema DDL contains no forbidden SQL features', preg_match('/FOREIGN\s+KEY|\bENUM\s*\(|\bJSON\b|GENERATED\s+ALWAYS|CREATE\s+(?:UNIQUE\s+)?INDEX.+WHERE/is', $all_sql) === 0);

// A current health marker is the runtime fast path; request memoization is even cheaper.
$wpdb->reset_observation();
h1a_reset_schema_request_state();
h1a_check('healthy current-version rerun succeeds', PGE_Event_Access_Schema::maybe_upgrade() === true);
h1a_check('healthy rerun performs zero SQL of any kind', count($wpdb->queries) === 0 && count($wpdb->dbdelta_sql) === 0);
h1a_check('healthy rerun reads only version, health, and attempt options', $GLOBALS['h1a_option_reads'] === [PGE_Event_Access_Schema::VERSION_OPTION, PGE_Event_Access_Schema::HEALTH_OPTION, PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]);
h1a_check('healthy rerun does not rewrite current version', count($GLOBALS['h1a_option_updates']) === 0);
$option_reads = count($GLOBALS['h1a_option_reads']);
h1a_check('same-request repeat returns memoized success', PGE_Event_Access_Schema::maybe_upgrade() === true && count($wpdb->queries) === 0 && count($GLOBALS['h1a_option_reads']) === $option_reads);
h1a_check('health and retry windows use the approved constants', PGE_Event_Access_Schema::HEALTH_TTL_SECONDS === 43200 && PGE_Event_Access_Schema::RETRY_THROTTLE_SECONDS === 300);

$wpdb = h1a_install_healthy('tenant_', true);
h1a_check('activation forces a full check despite current health', PGE_Event_Access_Schema::activate() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
h1a_check('runtime fast success precedes forced activation', PGE_Event_Access_Schema::maybe_upgrade() === true && count($wpdb->queries) === 0);
h1a_check('activation still forces full check after runtime memoized success', PGE_Event_Access_Schema::activate() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);
$query_count = count($wpdb->queries);
h1a_check('runtime after successful activation reuses request result', PGE_Event_Access_Schema::maybe_upgrade() === true && count($wpdb->queries) === $query_count);

$wpdb = h1a_install_healthy('tenant_', true);
unset($GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION]);
h1a_check('current version with missing health forces full check', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] = 'corrupt';
h1a_check('corrupt attempt state prevents stale fast-path acceptance', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy();
h1a_check('expired health marker forces a full check', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION] = 'corrupt';
h1a_check('corrupt health marker fails closed into a full check', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION]['schema_version'] = '0.9.0';
h1a_check('health marker from another schema version forces a full check', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] = [
    'schema_version' => PGE_Event_Access_Schema::SCHEMA_VERSION,
    'attempted_at' => time(),
    'status' => 'failed',
];
h1a_check('schema version mismatch bypasses retry throttle', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

// Missing table and additive drift repair while version is already current.
$wpdb = h1a_install_healthy();
unset($wpdb->tables[$wpdb->table('pge_event_access_audit_log')]);
h1a_check('missing table is repaired despite current version', PGE_Event_Access_Schema::maybe_upgrade() === true && isset($wpdb->tables[$wpdb->table('pge_event_access_audit_log')]));
h1a_check('missing table repair uses dbDelta for that table only', count($wpdb->dbdelta_sql) === 1);
h1a_check('expected probe drift is cleared after verified repair', PGE_Event_Access_Schema::get_last_error_code() === '');

$wpdb = h1a_install_healthy();
$wpdb->remove_column('pge_event_access_audit_log', 'metadata');
h1a_check('missing nullable column is repaired additively', PGE_Event_Access_Schema::maybe_upgrade() === true && isset($wpdb->tables[$wpdb->table('pge_event_access_audit_log')]['columns']['metadata']));
h1a_check('existing-table column repair does not call dbDelta', count($wpdb->dbdelta_sql) === 0);

$wpdb = h1a_install_healthy();
$wpdb->remove_index('pge_event_access_audit_log', 'actor_created');
h1a_check('missing ordinary index is repaired', PGE_Event_Access_Schema::maybe_upgrade() === true && isset($wpdb->tables[$wpdb->table('pge_event_access_audit_log')]['indexes']['actor_created']));

$wpdb = h1a_install_healthy();
$wpdb->remove_index('pge_event_host_memberships', 'event_user');
h1a_check('missing unique index is repaired after clean diagnostics', PGE_Event_Access_Schema::maybe_upgrade() === true && isset($wpdb->tables[$wpdb->table('pge_event_host_memberships')]['indexes']['event_user']));

// Unsafe repairs and failed postconditions retain the old version.
$wpdb = h1a_install_healthy();
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$wpdb->remove_column('pge_event_host_memberships', 'role');
$wpdb->add_row('pge_event_host_memberships', ['id' => 1, 'event_id' => 10, 'user_id' => 2, 'status' => 'active']);
h1a_check('missing required column on populated table fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'unsafe_required_column_memberships');
h1a_check('unsafe required-column drift keeps old version', get_option(PGE_Event_Access_Schema::VERSION_OPTION, '') === '0.9.0');

$wpdb = h1a_install_healthy();
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$wpdb->remove_index('pge_event_host_memberships', 'event_user');
$wpdb->add_row('pge_event_host_memberships', ['id' => 1, 'event_id' => 10, 'user_id' => 2, 'role' => 'viewer', 'status' => 'active']);
$wpdb->add_row('pge_event_host_memberships', ['id' => 2, 'event_id' => 10, 'user_id' => 2, 'role' => 'manager', 'status' => 'active']);
h1a_check('duplicate data blocks missing unique index', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'duplicate_membership');
h1a_check('duplicate blocker neither adds unique nor advances version', !isset($wpdb->tables[$wpdb->table('pge_event_host_memberships')]['indexes']['event_user']) && get_option(PGE_Event_Access_Schema::VERSION_OPTION, '') === '0.9.0');
h1a_check('duplicate rows are not deleted', count($wpdb->tables[$wpdb->table('pge_event_host_memberships')]['rows']) === 2);

$wpdb = h1a_install_healthy();
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$wpdb->remove_index('pge_event_access_audit_log', 'PRIMARY');
$wpdb->add_row('pge_event_access_audit_log', ['id' => 7]);
$wpdb->add_row('pge_event_access_audit_log', ['id' => 7]);
h1a_check('duplicate primary identifiers are diagnosed before index repair', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'duplicate_audit_id');

$wpdb = h1a_install_healthy();
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$wpdb->remove_index('pge_event_access_audit_log', 'actor_created');
$wpdb->pretend_alter_success = true;
h1a_check('ALTER success is not trusted without index postcondition', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'index_add_not_verified_audit_actor_created');
h1a_check('unverified index repair keeps old version', get_option(PGE_Event_Access_Schema::VERSION_OPTION, '') === '0.9.0');

$wpdb = h1a_install_healthy();
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$wpdb->tables[$wpdb->table('pge_event_host_group_access')]['indexes']['event_membership']['columns'] = ['event_id', 'group_id', 'membership_id'];
h1a_check('wrong composite index order fails without replacement', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'index_columns_mismatch_access_event_membership');

$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_event_invitation_groups', 'name_key', ['Type' => 'varchar(191)']);
h1a_check('column length mismatch is detected', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'column_type_mismatch_groups_name_key');
$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_event_invitation_groups', 'event_id', ['Type' => 'bigint(20)']);
h1a_check('missing UNSIGNED is detected', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'column_type_mismatch_groups_event_id');
$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_event_invitation_groups', 'default_slot', ['Null' => 'NO']);
h1a_check('nullability mismatch is detected', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'column_nullability_mismatch_groups_default_slot');
$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_event_host_memberships', 'status', ['Default' => 'revoked']);
h1a_check('default mismatch is detected', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'column_default_mismatch_memberships_status');
$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_event_access_audit_log', 'id', ['Extra' => '']);
h1a_check('missing auto-increment is detected', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'column_auto_increment_mismatch_audit_id');
$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_event_access_audit_log', 'created_at', ['Type' => 'datetime(6)']);
h1a_check('unexpected datetime precision is detected', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'column_type_mismatch_audit_created_at');

// Index fidelity includes full-column Sub_part and sequence.
$wpdb = h1a_install_healthy();
$wpdb->set_index_sub_parts('pge_invitation_group_assignments', 'event_guest', [null, 10]);
h1a_check('required unique prefix index fails structural verification', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'index_prefix_mismatch_assignments_event_guest');
$wpdb = h1a_install_healthy();
$wpdb->set_index_sub_parts('pge_event_invitation_groups', 'event_status', [null, 8, null]);
h1a_check('required ordinary prefix index is not accepted as full-column', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'index_prefix_mismatch_groups_event_status');
$wpdb = h1a_install_healthy();
$wpdb->add_index('pge_invitation_group_assignments', 'event_guest_alias', ['event_id', 'guest_phone'], true, [null, 10]);
h1a_check('prefix unique alias of approved shape fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_unique_index_assignments');
$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['future_prefix'] = ['Field' => 'future_prefix', 'Type' => 'varchar(20)', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Collation' => $wpdb->collate];
$wpdb->add_index('pge_event_invitation_groups', 'future_prefix_unique', ['future_prefix'], true, [8]);
h1a_check('extra prefix unique fails closed despite nullable column', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_unique_index_groups');
$wpdb = h1a_install_healthy();
$wpdb->set_index_sub_parts('pge_invitation_group_assignments', 'event_guest', [null, null]);
h1a_check('explicit NULL Sub_part remains a valid full-column index', PGE_Event_Access_Schema::maybe_upgrade() === true);
$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['future_lookup_prefix'] = ['Field' => 'future_lookup_prefix', 'Type' => 'varchar(20)', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Collation' => $wpdb->collate];
$wpdb->add_index('pge_event_invitation_groups', 'future_lookup_prefix', ['future_lookup_prefix'], false, [5]);
h1a_check('extra ordinary prefix index remains harmless', PGE_Event_Access_Schema::maybe_upgrade() === true);
$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['future_unknown_part'] = ['Field' => 'future_unknown_part', 'Type' => 'varchar(20)', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Collation' => $wpdb->collate];
$wpdb->add_index('pge_event_invitation_groups', 'future_unknown_part', ['future_unknown_part'], false, ['unknown']);
h1a_check('unknown Sub_part on extra ordinary index also fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'index_metadata_invalid_groups');
$wpdb = h1a_install_healthy();
$wpdb->tables[$wpdb->table('pge_invitation_group_assignments')]['indexes']['event_guest']['sub_parts'][1] = 'unknown';
h1a_check('unknown Sub_part metadata fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'index_prefix_mismatch_assignments_event_guest');
$wpdb = h1a_install_healthy();
$wpdb->tables[$wpdb->table('pge_invitation_group_assignments')]['indexes']['event_guest']['sub_parts'][1] = '__missing__';
h1a_check('missing Sub_part key fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'index_prefix_mismatch_assignments_event_guest');

// Charset and collation are structural, read-only postconditions.
$wpdb = h1a_install_healthy();
h1a_check('matching table and textual column collations pass', PGE_Event_Access_Schema::maybe_upgrade() === true);
$wpdb = h1a_install_healthy();
$wpdb->set_table_collation('pge_event_invitation_groups', 'utf8mb4_bin');
h1a_check('different table collation fails closed without DDL', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'table_collation_mismatch_groups' && count($wpdb->dbdelta_sql) === 0 && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'ALTER TABLE ') === 0)) === 0);
$wpdb = h1a_install_healthy();
$wpdb->set_table_collation('pge_event_invitation_groups', 'latin1_swedish_ci');
$wpdb->set_table_charset('pge_event_invitation_groups', 'latin1');
h1a_check('different table charset fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'table_charset_mismatch_groups');
$wpdb = h1a_install_healthy();
$wpdb->fail_table_metadata = true;
h1a_check('missing table collation metadata fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'table_collation_read_failed_groups');
$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_event_invitation_groups', 'name_key', ['Collation' => 'utf8mb4_bin']);
h1a_check('name_key collation drift fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'column_collation_mismatch_groups_name_key');
$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_invitation_group_assignments', 'guest_phone', ['Collation' => 'utf8mb4_bin']);
h1a_check('guest_phone collation drift fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'column_collation_mismatch_assignments_guest_phone');
$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_event_invitation_groups', 'name', ['Collation' => null]);
h1a_check('missing textual column collation metadata fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'column_collation_missing_groups_name');
$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_event_invitation_groups', 'event_id', ['Collation' => null]);
h1a_check('numeric column requires no collation', PGE_Event_Access_Schema::maybe_upgrade() === true);
$wpdb = h1a_install_healthy();
$wpdb->collate = '';
h1a_check('empty wpdb collate uses verified table collation within current charset', PGE_Event_Access_Schema::maybe_upgrade() === true);
$wpdb = h1a_install_healthy();
foreach ($wpdb->tables as &$table_definition) {
    $table_definition['table_collation'] = 'latin1_swedish_ci';
    $table_definition['table_charset'] = 'latin1';
    foreach ($table_definition['columns'] as &$column) {
        if (($column['Collation'] ?? null) !== null) $column['Collation'] = 'latin1_swedish_ci';
    }
}
unset($table_definition, $column);
$wpdb->charset = 'latin1';
$wpdb->collate = 'latin1_swedish_ci';
h1a_check('latin1 metadata is accepted directly without collation parsing', PGE_Event_Access_Schema::maybe_upgrade() === true);
$wpdb = h1a_install_healthy();
foreach ($wpdb->tables as &$table_definition) {
    $table_definition['table_collation'] = 'binary';
    $table_definition['table_charset'] = 'binary';
    foreach ($table_definition['columns'] as &$column) {
        if (($column['Collation'] ?? null) !== null) $column['Collation'] = 'binary';
    }
}
unset($table_definition, $column);
$wpdb->charset = 'binary';
$wpdb->collate = 'binary';
h1a_check('binary collation metadata is accepted without underscore parsing', PGE_Event_Access_Schema::maybe_upgrade() === true);
$wpdb = h1a_install_healthy();
unset($wpdb->tables[$wpdb->table('pge_event_invitation_groups')]['table_charset']);
h1a_check('missing character-set metadata fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'table_collation_read_failed_groups');
$wpdb = h1a_install_healthy();
$wpdb->tables[$wpdb->table('pge_event_invitation_groups')]['table_charset'] = ['malformed'];
h1a_check('malformed character-set metadata fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'table_collation_read_failed_groups');
$wpdb = h1a_install_healthy();
$wpdb->tables[$wpdb->table('pge_event_invitation_groups')]['table_collation'] = null;
h1a_check('missing collation value fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'table_collation_read_failed_groups');
$wpdb = h1a_install_healthy();
$wpdb->set_table_charset('pge_event_invitation_groups', 'latin1');
h1a_check('direct character-set mismatch fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'table_charset_mismatch_groups');
$wpdb = h1a_install_healthy();
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$wpdb->set_table_collation('pge_event_invitation_groups', 'utf8mb4_bin');
h1a_check('collation drift neither advances version nor writes healthy state', PGE_Event_Access_Schema::maybe_upgrade() === false && get_option(PGE_Event_Access_Schema::VERSION_OPTION, '') === '0.9.0' && (get_option(PGE_Event_Access_Schema::HEALTH_OPTION, [])['status'] ?? '') === 'failed');

// Data diagnostics.
$wpdb = h1a_install_healthy();
$wpdb->add_row('pge_event_invitation_groups', ['id' => 1, 'event_id' => 1, 'name' => 'Private Name', 'name_key' => 'private name', 'status' => 'active', 'default_slot' => 2]);
h1a_check('default_slot outside NULL/1 fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'invalid_default_slot');
h1a_check('diagnostic code does not leak group name', strpos(PGE_Event_Access_Schema::get_last_error_code(), 'Private Name') === false);

$wpdb = h1a_install_healthy();
$wpdb->add_row('pge_event_invitation_groups', ['id' => 1, 'event_id' => 1, 'name_key' => 'x', 'status' => 'deleted', 'default_slot' => null]);
h1a_check('invalid group status fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'invalid_group_status');
$wpdb = h1a_install_healthy();
$wpdb->add_row('pge_event_invitation_groups', ['id' => 1, 'event_id' => 1, 'name_key' => null, 'status' => 'active', 'default_slot' => null]);
h1a_check('active group requires a live name key', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'invalid_active_group');
$wpdb = h1a_install_healthy();
$wpdb->add_row('pge_event_invitation_groups', ['id' => 1, 'event_id' => 1, 'name_key' => 'old', 'status' => 'archived', 'default_slot' => null]);
h1a_check('archived group cannot retain name key', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'invalid_archived_group');
$wpdb = h1a_install_healthy();
$wpdb->add_row('pge_event_invitation_groups', ['id' => 1, 'event_id' => 1, 'name_key' => null, 'status' => 'archived', 'default_slot' => 1]);
h1a_check('archived group cannot remain default', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'invalid_archived_group');

$wpdb = h1a_install_healthy();
$wpdb->add_row('pge_event_host_memberships', ['id' => 1, 'event_id' => 1, 'user_id' => 2, 'role' => 'owner', 'status' => 'active']);
h1a_check('invalid membership role fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'invalid_membership_role');
$wpdb = h1a_install_healthy();
$wpdb->add_row('pge_event_host_memberships', ['id' => 1, 'event_id' => 1, 'user_id' => 2, 'role' => 'viewer', 'status' => 'invited']);
h1a_check('invalid membership status fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'invalid_membership_status');

$wpdb = h1a_install_healthy();
$wpdb->add_row('pge_event_invitation_groups', ['id' => 11, 'event_id' => 1, 'name_key' => 'a', 'status' => 'active', 'default_slot' => null]);
$wpdb->add_row('pge_event_host_memberships', ['id' => 21, 'event_id' => 2, 'user_id' => 3, 'role' => 'viewer', 'status' => 'active']);
$wpdb->add_row('pge_event_host_group_access', ['id' => 31, 'event_id' => 1, 'membership_id' => 21, 'group_id' => 11]);
h1a_check('cross-event group access fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'cross_event_group_access');
$wpdb = h1a_install_healthy();
$wpdb->add_row('pge_event_invitation_groups', ['id' => 11, 'event_id' => 1, 'name_key' => 'a', 'status' => 'active', 'default_slot' => null]);
$wpdb->add_row('pge_invitation_group_assignments', ['id' => 41, 'event_id' => 2, 'guest_phone' => '0599999999', 'group_id' => 11]);
h1a_check('cross-event assignment fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'cross_event_assignment');
h1a_check('assignment diagnostic does not leak phone', strpos(PGE_Event_Access_Schema::get_last_error_code(), '0599999999') === false);
$wpdb = h1a_install_healthy();
$wpdb->add_row('pge_event_host_group_access', ['id' => 1, 'event_id' => 1, 'membership_id' => 999, 'group_id' => 998]);
h1a_check('orphan group access fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'cross_event_group_access');

// Request guard, memoization, lock lifecycle, and option finalization.
$wpdb = h1a_install_healthy();
$nested_result = null;
$wpdb->on_get_lock = function () use (&$nested_result) {
    $nested_result = PGE_Event_Access_Schema::maybe_upgrade();
};
h1a_check('nested invocation is conservatively rejected', PGE_Event_Access_Schema::maybe_upgrade() === true && $nested_result === false);
h1a_check('nested invocation does not acquire a second lock', count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);
$query_count = count($wpdb->queries);
h1a_check('completed full check is memoized for the request', PGE_Event_Access_Schema::maybe_upgrade() === true && count($wpdb->queries) === $query_count);

$wpdb = h1a_fresh();
$wpdb->lock_result = 0;
h1a_check('lock failure prevents upgrade', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'lock_not_acquired');
h1a_check('lock failure performs no DDL or version update', count($wpdb->dbdelta_sql) === 0 && get_option(PGE_Event_Access_Schema::VERSION_OPTION, '') === '');
h1a_check('failed full check never stores a healthy marker', (get_option(PGE_Event_Access_Schema::HEALTH_OPTION, [])['status'] ?? '') !== 'healthy');
h1a_check('unowned lock is never released', $wpdb->release_count === 0);
$query_count = count($wpdb->queries);
h1a_check('failed request result remains failed when memoized', PGE_Event_Access_Schema::maybe_upgrade() === false && count($wpdb->queries) === $query_count);

$wpdb = h1a_install_healthy();
$wpdb->lock_result = 0;
h1a_check('failed current-version attempt records failure', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'lock_not_acquired');
h1a_reset_schema_request_state();
$wpdb->reset_observation();
h1a_check('recent failed attempt is throttled without SQL', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'retry_throttled' && count($wpdb->queries) === 0);
h1a_reset_schema_request_state();
$wpdb->reset_observation();
h1a_check('activation bypasses retry throttle', PGE_Event_Access_Schema::activate() === false && PGE_Event_Access_Schema::get_last_error_code() === 'lock_not_acquired' && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy();
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$wpdb->release_result = 0;
h1a_check('release result zero prevents full-check success', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'lock_release_not_owned');
h1a_check('release result zero neither advances version nor writes healthy marker', get_option(PGE_Event_Access_Schema::VERSION_OPTION, '') === '0.9.0' && (get_option(PGE_Event_Access_Schema::HEALTH_OPTION, [])['status'] ?? '') === 'failed');
h1a_check('release result zero is attempted exactly once', $wpdb->release_count === 1);

$wpdb = h1a_install_healthy();
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$wpdb->release_result = null;
h1a_check('null release result prevents full-check success', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'lock_release_error');
h1a_check('null release result neither advances version nor writes healthy marker', get_option(PGE_Event_Access_Schema::VERSION_OPTION, '') === '0.9.0' && (get_option(PGE_Event_Access_Schema::HEALTH_OPTION, [])['status'] ?? '') === 'failed');
h1a_check('null release result is attempted exactly once', $wpdb->release_count === 1);

$wpdb = h1a_install_healthy();
$wpdb->throw_on_show = true;
h1a_check('Throwable returns a technical failure', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'unexpected_upgrade_failure');
$reflection = new ReflectionClass(PGE_Event_Access_Schema::class);
$running_property = $reflection->getProperty('running');
$running_property->setAccessible(true);
h1a_check('Throwable always clears the request running guard', $running_property->getValue() === false);
h1a_check('Throwable path releases the owned lock', $wpdb->release_count === 1);
$query_count = count($wpdb->queries);
h1a_check('Throwable failure is memoized without becoming success', PGE_Event_Access_Schema::maybe_upgrade() === false && count($wpdb->queries) === $query_count);
$wpdb->throw_on_show = false;
$wpdb->reset_observation();
h1a_check('forced activation can retry after a memoized failure', PGE_Event_Access_Schema::activate() === true && $wpdb->release_count === 1);

$wpdb = h1a_install_healthy();
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$GLOBALS['h1a_fail_option_keys'] = [PGE_Event_Access_Schema::VERSION_OPTION];
h1a_check('version write failure is reported', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'version_update_failed');
h1a_check('owned lock is released after version failure', $wpdb->release_count === 1);

$wpdb = h1a_install_healthy();
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::VERSION_OPTION] = '0.9.0';
$old_health_checked_at = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION]['checked_at'];
$GLOBALS['h1a_fail_option_keys'] = [PGE_Event_Access_Schema::HEALTH_OPTION];
h1a_check('health marker write failure is reported', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'health_invalidation_failed');
h1a_check('failed health write does not refresh healthy state', (get_option(PGE_Event_Access_Schema::HEALTH_OPTION, [])['checked_at'] ?? 0) === $old_health_checked_at);

$wpdb = h1a_install_healthy('tenant_', true);
$wpdb->set_column('pge_event_host_memberships', 'status', ['Default' => 'revoked']);
$old_health = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION];
$GLOBALS['h1a_fail_option_keys'] = [PGE_Event_Access_Schema::HEALTH_OPTION];
h1a_check('failed activation remains false when health invalidation persistence fails', PGE_Event_Access_Schema::activate() === false && PGE_Event_Access_Schema::get_last_error_code() === 'health_invalidation_failed');
h1a_check('failed activation leaves the old marker but persists a newer failed attempt', $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION] === $old_health && ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['status'] ?? '') === 'failed');
h1a_reset_schema_request_state();
$wpdb->reset_observation();
h1a_check('newer failed attempt blocks stale healthy marker on next request', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'retry_throttled' && count($wpdb->queries) === 0);

$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_fail_option_keys'] = [PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION];
h1a_check('initial attempt persistence failure prevents lock and full check', PGE_Event_Access_Schema::activate() === false && PGE_Event_Access_Schema::get_last_error_code() === 'failed_state_write_failed' && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 0);
h1a_check('attempt persistence failure still invalidates health when possible', (get_option(PGE_Event_Access_Schema::HEALTH_OPTION, [])['status'] ?? '') === 'failed');

$wpdb = h1a_install_healthy('tenant_', true);
$current_attempt_writes = $GLOBALS['h1a_option_write_counts'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] ?? 0;
$GLOBALS['h1a_fail_option_on_write'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] = $current_attempt_writes + 2;
h1a_check('success-attempt persistence failure prevents stored success', PGE_Event_Access_Schema::activate() === false && PGE_Event_Access_Schema::get_last_error_code() === 'attempt_state_write_failed');
h1a_check('success-attempt failure is converted to durable failed state', (get_option(PGE_Event_Access_Schema::HEALTH_OPTION, [])['status'] ?? '') === 'failed' && (get_option(PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION, [])['status'] ?? '') === 'failed');

// Fix3: success is a generation-bound three-marker commit, including partial writes.
$wpdb = h1a_install_healthy('tenant_', true);
$health = get_option(PGE_Event_Access_Schema::HEALTH_OPTION, []);
$attempt = get_option(PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION, []);
h1a_check('healthy and success markers share one generation', ($health['generation'] ?? '') !== '' && $health['generation'] === ($attempt['generation'] ?? null));
h1a_check('success marker time commits the matching health marker', ($attempt['status'] ?? '') === 'success' && ($attempt['completed_at'] ?? null) === ($health['checked_at'] ?? null) && ($attempt['attempted_at'] ?? PHP_INT_MAX) <= ($attempt['completed_at'] ?? 0));

$wpdb = h1a_install_healthy('tenant_', true);
$generation = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION]['generation'];
unset($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]);
h1a_check('missing success attempt cannot use healthy fast path', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
unset($GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION]['generation']);
h1a_check('health without generation cannot use fast path', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
unset($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['generation']);
h1a_check('success attempt without generation cannot use fast path', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['generation'] = 'corrupt generation value';
h1a_check('corrupt generation cannot use fast path', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['schema_version'] = '0.9.0';
h1a_check('attempt schema-version mismatch cannot use fast path', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['generation'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
h1a_check('success generation mismatch forces full verification', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
$generation = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION]['generation'];
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] = [
    'schema_version' => PGE_Event_Access_Schema::SCHEMA_VERSION,
    'attempted_at' => $GLOBALS['h1a_now'],
    'status' => 'in_progress',
    'reason_code' => 'full_check_started',
    'generation' => $generation,
];
h1a_check('recent in-progress generation is retry-throttled without SQL', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'retry_throttled' && count($wpdb->queries) === 0);

$wpdb = h1a_install_healthy('tenant_', true);
$generation = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION]['generation'];
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] = [
    'schema_version' => PGE_Event_Access_Schema::SCHEMA_VERSION,
    'attempted_at' => $GLOBALS['h1a_now'] - PGE_Event_Access_Schema::RETRY_THROTTLE_SECONDS - 1,
    'status' => 'in_progress',
    'reason_code' => 'full_check_started',
    'generation' => $generation,
];
h1a_check('abandoned in-progress generation recovers through a new full check', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);

$wpdb = h1a_install_healthy('tenant_', true);
$attempt_base = $GLOBALS['h1a_option_write_counts'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION];
$GLOBALS['h1a_fail_option_on_writes'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] = [$attempt_base + 2, $attempt_base + 3];
h1a_check('failed success and failed terminal attempt remain fail closed', PGE_Event_Access_Schema::activate() === false && ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['status'] ?? '') === 'in_progress');
h1a_reset_schema_request_state();
$wpdb->reset_observation();
h1a_check('in-progress residue after terminal-attempt failure cannot fast succeed', PGE_Event_Access_Schema::maybe_upgrade() === false && count($wpdb->queries) === 0);

$wpdb = h1a_install_healthy('tenant_', true);
$attempt_base = $GLOBALS['h1a_option_write_counts'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION];
$health_base = $GLOBALS['h1a_option_write_counts'][PGE_Event_Access_Schema::HEALTH_OPTION];
$GLOBALS['h1a_fail_option_on_writes'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] = [$attempt_base + 2];
$GLOBALS['h1a_fail_option_on_writes'][PGE_Event_Access_Schema::HEALTH_OPTION] = [$health_base + 2];
h1a_check('failed success and failed terminal health remain fail closed', PGE_Event_Access_Schema::activate() === false && ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['status'] ?? '') === 'failed');
h1a_reset_schema_request_state();
$wpdb->reset_observation();
h1a_check('failed attempt blocks surviving healthy marker', PGE_Event_Access_Schema::maybe_upgrade() === false && count($wpdb->queries) === 0);

foreach ([0, 1] as $time_delta) {
    $wpdb = h1a_install_healthy('tenant_', true);
    $attempt_base = $GLOBALS['h1a_option_write_counts'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION];
    $health_base = $GLOBALS['h1a_option_write_counts'][PGE_Event_Access_Schema::HEALTH_OPTION];
    $GLOBALS['h1a_fail_option_on_writes'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] = [$attempt_base + 2, $attempt_base + 3];
    $GLOBALS['h1a_fail_option_on_writes'][PGE_Event_Access_Schema::HEALTH_OPTION] = [$health_base + 2];
    $wpdb->on_release_lock = function () use ($time_delta) { $GLOBALS['h1a_now'] += $time_delta; };
    [$h4_result, $h4_log] = h1a_capture_error_log(fn() => PGE_Event_Access_Schema::activate());
    $h4_attempt = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION];
    $h4_health = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION];
    h1a_check('H-4 current request fails with all terminal writes failing (delta ' . $time_delta . ')', $h4_result === false && $h4_attempt['status'] === 'in_progress' && $h4_health['status'] === 'healthy' && $h4_attempt['generation'] === $h4_health['generation']);
    h1a_check('H-4 terminal failure logs once (delta ' . $time_delta . ')', substr_count($h4_log, 'PGE event access schema:') === 1);
    h1a_reset_schema_request_state();
    $wpdb->reset_observation();
    h1a_check('H-4 next request cannot fast succeed (delta ' . $time_delta . ')', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'retry_throttled' && count($wpdb->queries) === 0);
}

$wpdb = h1a_install_healthy('tenant_', true);
$health_base = $GLOBALS['h1a_option_write_counts'][PGE_Event_Access_Schema::HEALTH_OPTION];
$GLOBALS['h1a_option_reread_mismatch_on_write'][PGE_Event_Access_Schema::HEALTH_OPTION][$health_base + 1] = ['stale' => true];
h1a_check('health write reread mismatch prevents success', PGE_Event_Access_Schema::activate() === false);

$wpdb = h1a_install_healthy('tenant_', true);
$attempt_base = $GLOBALS['h1a_option_write_counts'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION];
$GLOBALS['h1a_option_reread_mismatch_on_write'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION][$attempt_base + 2] = ['stale' => true];
h1a_check('success CAS reread mismatch prevents request success without demoting committed attempt', PGE_Event_Access_Schema::activate() === false && ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['status'] ?? '') === 'success' && ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION]['status'] ?? '') === 'failed');

$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_fail_option_update'] = true;
h1a_check('all option writes failing prevents lock and success', PGE_Event_Access_Schema::activate() === false && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 0);

$wpdb = h1a_fresh();
$desired = ['already' => 'stored'];
$GLOBALS['h1a_options']['h1a_verified_existing'] = $desired;
$GLOBALS['h1a_fail_option_keys'] = ['h1a_verified_existing'];
$reflection = new ReflectionClass(PGE_Event_Access_Schema::class);
$write_verified = $reflection->getMethod('write_option_verified');
$write_verified->setAccessible(true);
h1a_check('update false with already stored exact value verifies successfully', $write_verified->invoke(null, 'h1a_verified_existing', $desired) === true);

$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_generation_values'] = ['invalid generation with spaces'];
h1a_check('generation creation failure prevents lock and fails closed', PGE_Event_Access_Schema::activate() === false && PGE_Event_Access_Schema::get_last_error_code() === 'generation_failed' && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 0);

$wpdb = h1a_install_healthy('tenant_', true);
$newer_attempt = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION];
$newer_health = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION];
$store_failed = $reflection->getMethod('store_failed_state');
$store_failed->setAccessible(true);
$older_generation = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$older_attempt = ['schema_version' => PGE_Event_Access_Schema::SCHEMA_VERSION, 'attempted_at' => $GLOBALS['h1a_now'], 'status' => 'in_progress', 'reason_code' => 'full_check_started', 'generation' => $older_generation];
$older_health = ['schema_version' => PGE_Event_Access_Schema::SCHEMA_VERSION, 'checked_at' => $GLOBALS['h1a_now'], 'status' => 'healthy', 'generation' => $older_generation];
h1a_check('older generation failure cannot invalidate newer committed success', $store_failed->invoke(null, $older_generation, $older_attempt, $older_health) === false && $GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] === $newer_attempt && $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION] === $newer_health);

// Fix4: SQL compare-and-swap owns generation commit and terminal transitions.
$g2 = '22222222-2222-4222-8222-222222222222';
$wpdb = h1a_install_healthy('tenant_', true);
$wpdb->on_option_cas = function ($key, $expected, $desired) use ($g2) {
    if ($key === PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION && ($desired['status'] ?? '') === 'success') {
        $GLOBALS['h1a_options'][$key] = ['schema_version' => PGE_Event_Access_Schema::SCHEMA_VERSION, 'attempted_at' => $GLOBALS['h1a_now'], 'status' => 'in_progress', 'reason_code' => 'full_check_started', 'generation' => $g2];
    }
};
h1a_check('G2 before success CAS makes G1 fail closed', PGE_Event_Access_Schema::activate() === false);
h1a_check('G1 success and terminal CAS cannot overwrite G2', ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['generation'] ?? '') === $g2 && ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['status'] ?? '') === 'in_progress');
$cas_sql = implode("\n", array_filter($wpdb->queries, fn($q) => stripos($q, 'UPDATE `tenant_options` SET option_value') === 0));
h1a_check('success CAS uses prefixed options table and byte-exact serialized full expected marker', $cas_sql !== '' && strpos($cas_sql, 'option_name = \'pge_event_access_schema_last_attempt\'') !== false && strpos($cas_sql, 's:11:"in_progress"') !== false && strpos($cas_sql, 'BINARY option_value =') !== false);
h1a_reset_schema_request_state();
$wpdb->reset_observation();
h1a_check('next request cannot accept G1 health with G2 attempt', PGE_Event_Access_Schema::maybe_upgrade() === false && count($wpdb->queries) === 0);

$wpdb = h1a_install_healthy('tenant_', true);
$wpdb->lock_result = 0;
$wpdb->on_option_cas = function ($key, $expected, $desired) use ($g2) {
    if ($key === PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION && ($desired['status'] ?? '') === 'failed') {
        $GLOBALS['h1a_options'][$key] = ['schema_version' => PGE_Event_Access_Schema::SCHEMA_VERSION, 'attempted_at' => $GLOBALS['h1a_now'], 'status' => 'in_progress', 'reason_code' => 'full_check_started', 'generation' => $g2];
    }
};
h1a_check('G2 before failed CAS keeps the current request failed', PGE_Event_Access_Schema::activate() === false);
h1a_check('failed CAS for G1 does not overwrite G2 attempt', ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['generation'] ?? '') === $g2 && ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['status'] ?? '') === 'in_progress');

$wpdb = h1a_install_healthy('tenant_', true);
$wpdb->lock_result = 0;
$healthy_g2 = ['schema_version' => PGE_Event_Access_Schema::SCHEMA_VERSION, 'checked_at' => $GLOBALS['h1a_now'], 'status' => 'healthy', 'generation' => $g2];
$wpdb->on_option_cas = function ($key, $expected, $desired) use ($healthy_g2) {
    if ($key === PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION && ($desired['status'] ?? '') === 'failed') {
        $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION] = $healthy_g2;
    }
};
h1a_check('G2 health interleaving keeps G1 request failed', PGE_Event_Access_Schema::activate() === false);
h1a_check('G1 health invalidation CAS cannot overwrite healthy G2', $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION] === $healthy_g2);

$wpdb = h1a_install_healthy('tenant_', true);
$success_g1 = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION];
$health_g1 = $GLOBALS['h1a_options'][PGE_Event_Access_Schema::HEALTH_OPTION];
$g1 = $success_g1['generation'];
$initial_g1 = ['schema_version' => PGE_Event_Access_Schema::SCHEMA_VERSION, 'attempted_at' => $success_g1['attempted_at'], 'status' => 'in_progress', 'reason_code' => 'full_check_started', 'generation' => $g1];
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION] = ['schema_version' => PGE_Event_Access_Schema::SCHEMA_VERSION, 'attempted_at' => $GLOBALS['h1a_now'], 'status' => 'in_progress', 'reason_code' => 'full_check_started', 'generation' => $g2];
h1a_check('late G1 cleanup after G2 starts cannot rewrite G2 attempt', $store_failed->invoke(null, $g1, $initial_g1, $health_g1) === false && ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['generation'] ?? '') === $g2);
h1a_reset_schema_request_state();
$wpdb->reset_observation();
h1a_check('G2 in-progress blocks fast path after committed G1', PGE_Event_Access_Schema::maybe_upgrade() === false && count($wpdb->queries) === 0);

$wpdb = h1a_install_healthy('tenant_', true);
$wpdb->fail_next_cas = true;
h1a_check('success CAS query failure fails closed', PGE_Event_Access_Schema::activate() === false && ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['status'] ?? '') === 'failed');
$wpdb = h1a_install_healthy('tenant_', true);
$wpdb->malformed_next_cas_result = '1';
h1a_check('non-integer affected-row result fails closed', PGE_Event_Access_Schema::activate() === false && ($GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['status'] ?? '') === 'failed');
$wpdb = h1a_install_healthy('custom_', true);
h1a_check('successful CAS clears individual, alloptions, and notoptions caches', PGE_Event_Access_Schema::activate() === true && in_array([PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION, 'options'], $GLOBALS['h1a_cache_deletes'], true) && in_array(['alloptions', 'options'], $GLOBALS['h1a_cache_deletes'], true) && in_array(['notoptions', 'options'], $GLOBALS['h1a_cache_deletes'], true));
h1a_check('custom prefix is used for atomic option transition', count(array_filter($wpdb->queries, fn($q) => stripos($q, 'UPDATE `custom_options` SET option_value') === 0)) === 1);

// Strict timestamp validation: malformed markers must fall through to a full proof.
h1a_check_invalid_fast_timestamp('attempted_at zero cannot use fast path', PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION, 'attempted_at', 0);
h1a_check_invalid_fast_timestamp('attempted_at negative cannot use fast path', PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION, 'attempted_at', -1);
h1a_check_invalid_fast_timestamp('checked_at zero cannot use fast path', PGE_Event_Access_Schema::HEALTH_OPTION, 'checked_at', 0);
h1a_check_invalid_fast_timestamp('checked_at negative cannot use fast path', PGE_Event_Access_Schema::HEALTH_OPTION, 'checked_at', -1);
h1a_check_invalid_fast_timestamp('completed_at zero cannot use fast path', PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION, 'completed_at', 0);
h1a_check_invalid_fast_timestamp('completed_at negative cannot use fast path', PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION, 'completed_at', -1);
h1a_check_invalid_fast_timestamp('numeric-string timestamp cannot use fast path', PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION, 'attempted_at', '123');
h1a_check_invalid_fast_timestamp('float timestamp cannot use fast path', PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION, 'attempted_at', 123.0);
$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['attempted_at'] = $GLOBALS['h1a_now'] + 1;
h1a_check('attempted_at after completed_at cannot use fast path', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);
$wpdb = h1a_install_healthy('tenant_', true);
$GLOBALS['h1a_options'][PGE_Event_Access_Schema::LAST_ATTEMPT_OPTION]['completed_at']--;
h1a_check('completed_at different from checked_at cannot use fast path', PGE_Event_Access_Schema::maybe_upgrade() === true && count(array_filter($wpdb->queries, fn($q) => stripos($q, 'SELECT GET_LOCK(') === 0)) === 1);
$wpdb = h1a_install_healthy('tenant_', true);
h1a_check('positive coherent timestamps retain zero-SQL fast path', PGE_Event_Access_Schema::maybe_upgrade() === true && count($wpdb->queries) === 0);

// Generation format is exactly canonical lowercase UUIDv4 or lowercase 32-hex fallback.
$generation_valid = $reflection->getMethod('generation_is_valid');
$generation_valid->setAccessible(true);
$generation_cases = [
    'canonical lowercase UUIDv4 is accepted' => ['123e4567-e89b-42d3-a456-426614174000', true],
    'uppercase UUIDv4 is rejected' => ['123E4567-E89B-42D3-A456-426614174000', false],
    'UUIDv1 is rejected' => ['123e4567-e89b-12d3-a456-426614174000', false],
    'malformed UUID version is rejected' => ['123e4567-e89b-62d3-a456-426614174000', false],
    'invalid UUID variant is rejected' => ['123e4567-e89b-42d3-7456-426614174000', false],
    'lowercase 32-hex fallback is accepted' => ['0123456789abcdef0123456789abcdef', true],
    'uppercase 32-hex fallback is rejected' => ['0123456789ABCDEF0123456789ABCDEF', false],
    '31-hex fallback is rejected' => [str_repeat('a', 31), false],
    '33-hex fallback is rejected' => [str_repeat('a', 33), false],
    'arbitrary 16-character token is rejected' => [str_repeat('a', 16), false],
    'underscore token is rejected' => ['123e4567_e89b_42d3_a456_426614174000', false],
    'whitespace generation is rejected' => [' 123e4567-e89b-42d3-a456-426614174000 ', false],
    'empty generation is rejected' => ['', false],
    'non-string generation is rejected' => [123, false],
];
foreach ($generation_cases as $label => [$value, $expected]) {
    h1a_check($label, $generation_valid->invoke(null, $value) === $expected);
}

// Probe logging is silent; terminal failures are visible without retry spam.
$wpdb = h1a_install_healthy();
$wpdb->remove_column('pge_event_access_audit_log', 'metadata');
[$repair_result, $repair_log] = h1a_capture_error_log(fn() => PGE_Event_Access_Schema::maybe_upgrade());
h1a_check('repairable probe drift emits no terminal log', $repair_result === true && substr_count($repair_log, 'PGE event access schema:') === 0);
$wpdb = h1a_install_healthy();
$wpdb->set_column('pge_event_host_memberships', 'status', ['Default' => 'revoked']);
[$terminal_result, $terminal_log] = h1a_capture_error_log(fn() => PGE_Event_Access_Schema::maybe_upgrade());
h1a_check('terminal structural failure logs exactly once', $terminal_result === false && substr_count($terminal_log, 'PGE event access schema:') === 1);
h1a_reset_schema_request_state();
$wpdb->reset_observation();
[$throttled_result, $throttled_log] = h1a_capture_error_log(fn() => PGE_Event_Access_Schema::maybe_upgrade());
h1a_check('retry-throttled request emits no repeated terminal log', $throttled_result === false && PGE_Event_Access_Schema::get_last_error_code() === 'retry_throttled' && substr_count($throttled_log, 'PGE event access schema:') === 0);

// Extra structures: harmless ordinary index/nullable column survive; conflicting constraints fail.
$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_access_audit_log');
$wpdb->tables[$table]['columns']['future_note'] = ['Field' => 'future_note', 'Type' => 'varchar(20)', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Collation' => $wpdb->collate];
$wpdb->add_index('pge_event_access_audit_log', 'future_lookup', ['future_note'], false);
h1a_check('harmless extra column and ordinary index do not fail', PGE_Event_Access_Schema::maybe_upgrade() === true);
h1a_check('harmless extras are not dropped', isset($wpdb->tables[$table]['columns']['future_note']) && isset($wpdb->tables[$table]['indexes']['future_lookup']));

$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['required_surprise'] = ['Field' => 'required_surprise', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''];
h1a_check('extra required column without a default fails as behavior-changing', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_required_column_groups');

$wpdb = h1a_install_healthy();
$wpdb->add_index('pge_event_invitation_groups', 'event_active_name_alias', ['event_id', 'name_key'], true);
h1a_check('redundant alias of an approved unique shape is harmless', PGE_Event_Access_Schema::maybe_upgrade() === true);

$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['future_key'] = ['Field' => 'future_key', 'Type' => 'varchar(20)', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Collation' => $wpdb->collate];
$wpdb->add_index('pge_event_invitation_groups', 'future_unique', ['future_key'], true);
h1a_check('nullable extra-only unique is conservatively accepted', PGE_Event_Access_Schema::maybe_upgrade() === true);

$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['future_default'] = ['Field' => 'future_default', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => 'fixed', 'Extra' => '', 'Collation' => $wpdb->collate];
h1a_check('extra required column with a usable default is accepted', PGE_Event_Access_Schema::maybe_upgrade() === true);

$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['future_sequence'] = ['Field' => 'future_sequence', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'AUTO_INCREMENT'];
h1a_check('second auto-increment column fails as an impossible owned-table shape', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_auto_increment_column_groups');

$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['future_virtual'] = ['Field' => 'future_virtual', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => null, 'Extra' => 'VIRTUAL GENERATED', 'Collation' => $wpdb->collate];
$wpdb->tables[$table]['columns']['future_stored'] = ['Field' => 'future_stored', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => null, 'Extra' => 'STORED GENERATED', 'Collation' => $wpdb->collate];
h1a_check('virtual and stored generated columns are accepted by metadata', PGE_Event_Access_Schema::maybe_upgrade() === true);

$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['future_unknown'] = ['Field' => 'future_unknown', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => null, 'Extra' => 'GENERATED SOMETIMES', 'Collation' => $wpdb->collate];
h1a_check('unknown generated-like metadata fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_required_column_groups');

$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['future_default'] = ['Field' => 'future_default', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => 'fixed', 'Extra' => '', 'Collation' => $wpdb->collate];
$wpdb->add_index('pge_event_invitation_groups', 'future_default_unique', ['future_default'], true);
h1a_check('extra unique over a fixed default fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_unique_index_groups');

$wpdb = h1a_install_healthy();
$wpdb->add_index('pge_event_invitation_groups', 'future_unknown_unique', ['missing_metadata'], true);
h1a_check('extra unique with unknown column metadata fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_unique_index_groups');

$wpdb = h1a_install_healthy();
$table = $wpdb->table('pge_event_invitation_groups');
$wpdb->tables[$table]['columns']['future_generated'] = ['Field' => 'future_generated', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => null, 'Extra' => 'VIRTUAL GENERATED', 'Collation' => $wpdb->collate];
$wpdb->add_index('pge_event_invitation_groups', 'future_generated_unique', ['future_generated'], true);
h1a_check('extra unique over a generated value fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_unique_index_groups');

$wpdb = h1a_install_healthy();
$wpdb->add_index('pge_event_invitation_groups', 'unexpected_unique_name', ['name'], true);
h1a_check('extra unique constraint fails as behavior-changing', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_unique_index_groups');
$wpdb = h1a_install_healthy();
$wpdb->add_constraint('pge_event_host_group_access', 'FOREIGN KEY');
h1a_check('unexpected foreign key fails structural verification', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_constraint_access');
$wpdb = h1a_install_healthy();
$wpdb->add_constraint('pge_event_host_memberships', 'CHECK', 'role_check');
h1a_check('named table-level check fails structural verification', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_constraint_memberships');
$wpdb = h1a_install_healthy();
$wpdb->add_constraint('pge_event_host_memberships', 'CHECK', null);
h1a_check('unnamed table-level check fails structural verification', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_constraint_memberships');
$wpdb = h1a_install_healthy();
$wpdb->add_constraint('pge_event_host_memberships', 'CHECK');
h1a_check('nested check expression is detected without parsing it', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_constraint_memberships');
$wpdb = h1a_install_healthy();
$wpdb->add_constraint('pge_event_invitation_groups', 'CHECK');
$wpdb->append_create_sql('pge_event_invitation_groups', 'status VARCHAR(20) CHECK ((status <> \'\'))');
h1a_check('column-level check is rejected from metadata regardless of SHOW CREATE formatting', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'conflicting_constraint_groups');
$wpdb = h1a_install_healthy();
$wpdb->fail_constraint_metadata = true;
h1a_check('constraint metadata query failure fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'constraint_metadata_read_failed_groups');
$wpdb = h1a_install_healthy();
$wpdb->add_constraint('pge_event_invitation_groups', 'EXCLUSION');
h1a_check('unknown constraint metadata type fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'constraint_metadata_type_unknown_groups');
$wpdb = h1a_install_healthy();
$wpdb->set_constraint_rows('pge_event_invitation_groups', ['not-a-row']);
h1a_check('non-row constraint metadata fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'constraint_metadata_shape_invalid_groups');
$wpdb = h1a_install_healthy();
$wpdb->set_constraint_rows('pge_event_invitation_groups', [[]]);
h1a_check('constraint row without type fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'constraint_metadata_shape_invalid_groups');
$wpdb = h1a_install_healthy();
$wpdb->set_constraint_rows('pge_event_invitation_groups', [['CONSTRAINT_TYPE' => null]]);
h1a_check('null constraint type fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'constraint_metadata_shape_invalid_groups');
$wpdb = h1a_install_healthy();
$wpdb->set_constraint_rows('pge_event_invitation_groups', [['CONSTRAINT_TYPE' => '']]);
h1a_check('empty constraint type fails closed', PGE_Event_Access_Schema::maybe_upgrade() === false && PGE_Event_Access_Schema::get_last_error_code() === 'constraint_metadata_type_unknown_groups');
$wpdb = h1a_install_healthy();
h1a_check('empty FK/CHECK metadata with only PRIMARY and UNIQUE passes', PGE_Event_Access_Schema::maybe_upgrade() === true);
$wpdb = h1a_install_healthy();
$wpdb->append_create_sql('pge_event_host_memberships', "future_check_note VARCHAR(20) NULL DEFAULT NULL COMMENT 'CHECK (role) FOREIGN KEY'");
$table = $wpdb->table('pge_event_host_memberships');
$wpdb->tables[$table]['columns']['check_foreign_key_note'] = ['Field' => 'check_foreign_key_note', 'Type' => 'varchar(20)', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Collation' => $wpdb->collate];
$wpdb->add_index('pge_event_host_memberships', 'check_foreign_key_label', ['event_id'], false);
h1a_check('constraint keywords in column comments or index names are not false positives', PGE_Event_Access_Schema::maybe_upgrade() === true);

// Integration/static scope guards complement the behavioral assertions above.
$schema_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-schema.php');
$plugin_source = file_get_contents(PGE_PATH . 'pgevents-core.php');
h1a_check('plugin loads the schema exactly once', substr_count($plugin_source, "require_once PGE_PATH . 'includes/class-pge-event-access-schema.php';") === 1);
h1a_check('schema source contains no repository or authorization implementation', strpos($schema_source, 'Repository') === false && strpos($schema_source, 'current_user_can') === false && strpos($schema_source, 'pge_is_host_or_admin') === false);
h1a_check('schema source performs no row inserts, backfill, or post-meta writes', preg_match('/\bINSERT\s+INTO\b|update_post_meta|add_post_meta|readiness/i', $schema_source) === 0);
h1a_check('schema is independent from Catalog, RSVP, and Messaging classes', strpos($schema_source, 'Mon_Catalog_Schema') === false && strpos($schema_source, 'pge_event_rsvps') === false && strpos($schema_source, 'PGE_Messaging_Schema') === false);

echo "\nH1A: {$passed}/" . ($passed + $failed) . " passed\n";
exit($failed === 0 ? 0 : 1);
