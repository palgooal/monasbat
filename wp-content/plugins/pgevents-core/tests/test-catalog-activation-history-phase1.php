<?php
/** Focused Phase 1 contract tests for Catalog Activation History. */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

function register_activation_hook(...$args) {}
function add_action(...$args) {}
function get_option($key, $default = false) { return $default; }
function update_option(...$args) { return true; }
function current_time($type, $gmt = false)
{
    return $type === 'timestamp' ? strtotime('2026-09-23 12:00:00 UTC') : '2026-09-23 12:00:00';
}

final class PGE_Phase1_Fake_WPDB
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $rows = [];

    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }

    public function insert($table, array $data, array $formats = [])
    {
        $rows = $this->rows[$table] ?? [];
        foreach ($rows as $row) {
            if ($table === 'wp_pge_catalog_activations'
                && (($row['activation_id'] === $data['activation_id']) || ($row['idempotency_key'] === $data['idempotency_key']))) return false;
            if ($table === 'wp_pge_catalog_activation_events' && (int) $row['event_id'] === (int) $data['event_id']) return false;
            if ($table === 'wp_pge_catalog_activation_origins'
                && (((int) $row['catalog_activation_id'] === (int) $data['catalog_activation_id'] && $row['provider'] === $data['provider'])
                    || ($row['provider'] === $data['provider'] && (int) $row['merchant_id'] === (int) $data['merchant_id'] && $row['external_order_id'] === $data['external_order_id']))) return false;
        }
        $this->insert_id++;
        $data['id'] = $this->insert_id;
        $this->rows[$table][] = $data;
        return 1;
    }

    public function update($table, array $data, array $where, array $formats = [], array $where_formats = [])
    {
        $updated = 0;
        foreach ($this->rows[$table] as &$row) {
            foreach ($where as $key => $value) {
                if ((string) ($row[$key] ?? null) !== (string) $value) continue 2;
            }
            $row = array_merge($row, $data);
            $updated++;
        }
        unset($row);
        return $updated;
    }

    public function prepare($sql, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%[ds]/', static function ($match) use (&$i, $args) {
            $value = $args[$i++];
            return $match[0] === '%d' ? (string) (int) $value : "'" . str_replace("'", "''", (string) $value) . "'";
        }, $sql);
    }

    public function get_row($sql, $format = ARRAY_A)
    {
        foreach ($this->rows as $table => $rows) {
            if (strpos($sql, 'FROM ' . $table) === false) continue;
            foreach ($rows as $row) {
                if ($this->matches($sql, $row)) return $row;
            }
        }
        return null;
    }

    public function get_results($sql, $format = ARRAY_A)
    {
        if (strpos($sql, 'FROM wp_pge_catalog_activations') !== false && strpos($sql, 'next_reconcile_at') !== false) {
            $rows = array_filter($this->rows['wp_pge_catalog_activations'] ?? [], static function ($row) {
                return !in_array($row['lifecycle_state'], ['ended', 'revoked'], true)
                    && !empty($row['next_reconcile_at'])
                    && $row['next_reconcile_at'] <= '2026-09-23 12:00:00';
            });
            usort($rows, static fn($a, $b) => strcmp($a['next_reconcile_at'], $b['next_reconcile_at']) ?: ($a['id'] <=> $b['id']));
            return array_values($rows);
        }
        if (strpos($sql, 'FROM wp_pge_catalog_activation_events') !== false) {
            return array_values(array_filter($this->rows['wp_pge_catalog_activation_events'] ?? [], fn($row) => $this->matches($sql, $row)));
        }
        return [];
    }

    private function matches($sql, array $row)
    {
        preg_match_all("/([a-z_]+) = (?:'((?:''|[^'])*)'|([0-9]+))/", $sql, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $value = $match[2] !== '' ? str_replace("''", "'", $match[2]) : $match[3];
            if ((string) ($row[$match[1]] ?? null) !== (string) $value) return false;
        }
        return true;
    }
}

$wpdb = new PGE_Phase1_Fake_WPDB();
$passed = 0;
$total = 0;
$failures = [];

function check($label, $actual, $expected)
{
    global $passed, $total, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS: $label\n"; return; }
    $failures[] = $label;
    echo "FAIL: $label\n";
}

require_once PGE_PATH . 'includes/class-pge-catalog-activation-schema.php';
require_once PGE_PATH . 'includes/class-pge-catalog-activation-repository.php';
require_once PGE_PATH . 'includes/class-pge-catalog-event-binding-repository.php';
require_once PGE_PATH . 'includes/class-pge-catalog-provider-origin-repository.php';

$schema = implode("\n", PGE_Catalog_Activation_Schema::get_schema_sql());
check('four tables are declared', substr_count($schema, 'CREATE TABLE'), 4);
foreach (['id', 'activation_id', 'user_id', 'plan_id', 'tier_id', 'plan_key', 'tier_key', 'activation_source', 'idempotency_key', 'external_order_id', 'projection_snapshot', 'lifecycle_state', 'revision', 'projection_attempts', 'last_error_code', 'next_reconcile_at', 'activated_at', 'ended_at', 'revoked_at', 'last_reconciled_at', 'created_at', 'updated_at'] as $column) {
    check("activation column $column", strpos($schema, $column) !== false, true);
}
check('unique activation_id', strpos($schema, 'UNIQUE KEY activation_id (activation_id)') !== false, true);
check('unique idempotency_key', strpos($schema, 'UNIQUE KEY idempotency_key (idempotency_key)') !== false, true);
check('user lifecycle index', strpos($schema, 'KEY user_lifecycle (user_id, lifecycle_state)') !== false, true);
check('plus lifecycle index', strpos($schema, 'KEY plus_lifecycle (plan_key, lifecycle_state)') !== false, true);
check('due reconciliation index', strpos($schema, 'KEY due_reconciliation (lifecycle_state, next_reconcile_at)') !== false, true);
check('unique event ownership', strpos($schema, 'UNIQUE KEY event_id (event_id)') !== false, true);
check('activation event index is non-unique', strpos($schema, 'KEY catalog_activation_id (catalog_activation_id)') !== false, true);
check('unique provider order', strpos($schema, 'UNIQUE KEY provider_order (provider, merchant_id, external_order_id)') !== false, true);
check('unique activation provider', strpos($schema, 'UNIQUE KEY activation_provider (catalog_activation_id, provider)') !== false, true);
check('provider customer index', strpos($schema, 'KEY provider_customer (provider, merchant_id, external_customer_id)') !== false, true);
check('revocation reason column', strpos($schema, 'reason VARCHAR(64) NOT NULL') !== false, true);
check('revocation exact order uniqueness', substr_count($schema, 'UNIQUE KEY provider_order (provider, merchant_id, external_order_id)'), 2);
check('no global user/plan/tier unique', preg_match('/UNIQUE KEY[^\n]+\((?:user_id|plan_id|tier_id)/', $schema), 0);
check('no unique activation event binding', strpos($schema, 'UNIQUE KEY catalog_activation_id (catalog_activation_id)') !== false, false);
$repository_source = file_get_contents(PGE_PATH . 'includes/class-pge-catalog-activation-repository.php');
check('no projection_failed lifecycle', strpos($repository_source, "'projection_failed'") !== false, false);
check('no superseded lifecycle', strpos($repository_source, "'superseded'") !== false, false);
$schema_source = file_get_contents(PGE_PATH . 'includes/class-pge-catalog-activation-schema.php');
check('schema activation hook wired', strpos($schema_source, "register_activation_hook(PGE_PATH . 'pgevents-core.php'") !== false, true);
check('schema plugins_loaded upgrade wired', strpos($schema_source, "add_action('plugins_loaded'") !== false, true);
$bootstrap_source = file_get_contents(PGE_PATH . 'pgevents-core.php');
foreach (['class-pge-catalog-activation-schema.php', 'class-pge-catalog-activation-repository.php', 'class-pge-catalog-event-binding-repository.php', 'class-pge-catalog-provider-origin-repository.php'] as $include_file) {
    check("bootstrap loads $include_file", strpos($bootstrap_source, $include_file) !== false, true);
}

$snapshot = '{"tier":{"id":9,"limit":100},"exact":"  preserved  "}';
$created = PGE_Catalog_Activation_Repository::create_preparing([
    'activation_id' => '6daa5ee5-5bda-43a6-a392-ad75402b8c21', 'user_id' => 380,
    'plan_id' => 2, 'tier_id' => 9, 'plan_key' => 'halwa_plus', 'tier_key' => 'guests_100',
    'activation_source' => 'salla', 'idempotency_key' => 'salla:392732220:1576373696',
    'external_order_id' => '1576373696', 'projection_snapshot' => $snapshot,
]);
check('create preparing', $created['result'], 'created');
$activation = PGE_Catalog_Activation_Repository::find_by_activation_id('6daa5ee5-5bda-43a6-a392-ad75402b8c21');
check('preparing state', $activation['lifecycle_state'], 'preparing');
check('exact snapshot round trip', $activation['projection_snapshot'], $snapshot);
check('lookup idempotency key', PGE_Catalog_Activation_Repository::find_by_idempotency_key('salla:392732220:1576373696')['id'], $activation['id']);
check('duplicate activation_id rejected', PGE_Catalog_Activation_Repository::create_preparing([
    'activation_id' => $activation['activation_id'], 'user_id' => 381, 'plan_id' => 2, 'tier_id' => 9,
    'plan_key' => 'halwa_plus', 'tier_key' => 'guests_100', 'activation_source' => 'manual',
    'idempotency_key' => 'manual:different-key', 'projection_snapshot' => '{}',
])['result'], 'conflict');
check('duplicate idempotency_key rejected', PGE_Catalog_Activation_Repository::create_preparing([
    'activation_id' => 'different-activation', 'user_id' => 381, 'plan_id' => 2, 'tier_id' => 9,
    'plan_key' => 'halwa_plus', 'tier_key' => 'guests_100', 'activation_source' => 'manual',
    'idempotency_key' => 'salla:392732220:1576373696', 'projection_snapshot' => '{}',
])['result'], 'conflict');

check('CAS transition succeeds', PGE_Catalog_Activation_Repository::compare_and_swap_state($activation['id'], 'preparing', 1, 'active_unbound')['result'], 'updated');
check('CAS stale revision fails', PGE_Catalog_Activation_Repository::compare_and_swap_state($activation['id'], 'active_unbound', 1, 'active_bound')['result'], 'stale');
$after_cas = PGE_Catalog_Activation_Repository::find_by_activation_id($activation['activation_id']);
check('stale CAS did not mutate', $after_cas['lifecycle_state'], 'active_unbound');
check('record projection failure', PGE_Catalog_Activation_Repository::record_projection_failure($activation['id'], 2, 'projection_write_failed', '2026-09-24 11:59:00')['result'] ?? null, 'recorded');
$after_failure = PGE_Catalog_Activation_Repository::find_by_activation_id($activation['activation_id']);
check('failure metadata preserves lifecycle', $after_failure['lifecycle_state'], 'active_unbound');
check('failure increments attempts', (int) $after_failure['projection_attempts'], 1);

$binding_one = PGE_Catalog_Event_Binding_Repository::create($activation['id'], 501, '2026-09-23 12:00:00');
$binding_two = PGE_Catalog_Event_Binding_Repository::create($activation['id'], 502, '2026-09-23 12:00:00');
check('multiple events per activation allowed', [$binding_one['result'], $binding_two['result']], ['created', 'created']);
check('duplicate event rejected', PGE_Catalog_Event_Binding_Repository::create($activation['id'] + 1, 501, '2026-09-23 12:00:00')['result'], 'conflict');
check('lookup event binding', PGE_Catalog_Event_Binding_Repository::find_by_event_id(502)['catalog_activation_id'], $activation['id']);
check('lookup bindings by activation', count(PGE_Catalog_Event_Binding_Repository::find_by_activation_id($activation['id'])), 2);

$origin = PGE_Catalog_Provider_Origin_Repository::create($activation['id'], 'salla', 392732220, '1888007575', '1576373696');
check('provider origin created', $origin['result'], 'created');
check('lookup exact provider order', PGE_Catalog_Provider_Origin_Repository::find_by_order('salla', 392732220, '1576373696')['catalog_activation_id'], $activation['id']);
check('duplicate provider order rejected', PGE_Catalog_Provider_Origin_Repository::create($activation['id'] + 1, 'salla', 392732220, '1888007575', '1576373696')['result'], 'conflict');
check('duplicate activation/provider rejected', PGE_Catalog_Provider_Origin_Repository::create($activation['id'], 'salla', 392732220, '1888007575', 'another-order')['result'], 'conflict');

PGE_Catalog_Activation_Repository::create_preparing([
    'activation_id' => 'due-active', 'user_id' => 1, 'plan_id' => 1, 'tier_id' => 1,
    'plan_key' => 'halwa_plus', 'tier_key' => 'x', 'activation_source' => 'manual',
    'idempotency_key' => 'manual:due-active', 'projection_snapshot' => '{}',
]);
$due = PGE_Catalog_Activation_Repository::find_by_activation_id('due-active');
PGE_Catalog_Activation_Repository::record_projection_failure($due['id'], 1, 'retry', '2026-09-23 11:00:00');
PGE_Catalog_Activation_Repository::create_preparing([
    'activation_id' => 'future-active', 'user_id' => 1, 'plan_id' => 1, 'tier_id' => 1,
    'plan_key' => 'halwa_plus', 'tier_key' => 'x', 'activation_source' => 'manual',
    'idempotency_key' => 'manual:future-active', 'projection_snapshot' => '{}',
]);
$future = PGE_Catalog_Activation_Repository::find_by_activation_id('future-active');
PGE_Catalog_Activation_Repository::record_projection_failure($future['id'], 1, 'retry', '2026-09-24 11:00:00');
PGE_Catalog_Activation_Repository::create_preparing([
    'activation_id' => 'terminal', 'user_id' => 1, 'plan_id' => 1, 'tier_id' => 1,
    'plan_key' => 'halwa_plus', 'tier_key' => 'x', 'activation_source' => 'backfill',
    'idempotency_key' => 'backfill:terminal', 'projection_snapshot' => '{}',
]);
$terminal = PGE_Catalog_Activation_Repository::find_by_activation_id('terminal');
PGE_Catalog_Activation_Repository::compare_and_swap_state($terminal['id'], 'preparing', 1, 'revoked', ['next_reconcile_at' => '2026-09-23 11:00:00']);
check('revoked is terminal', PGE_Catalog_Activation_Repository::compare_and_swap_state($terminal['id'], 'revoked', 2, 'active_unbound')['result'], 'terminal');
check('terminal projection failure is rejected', PGE_Catalog_Activation_Repository::record_projection_failure($terminal['id'], 2, 'retry', '2026-09-23 11:00:00')['result'] ?? null, 'terminal');
PGE_Catalog_Activation_Repository::create_preparing([
    'activation_id' => 'ended-terminal', 'user_id' => 1, 'plan_id' => 1, 'tier_id' => 1,
    'plan_key' => 'halwa_plus', 'tier_key' => 'x', 'activation_source' => 'backfill',
    'idempotency_key' => 'backfill:ended-terminal', 'projection_snapshot' => '{}',
]);
$ended = PGE_Catalog_Activation_Repository::find_by_activation_id('ended-terminal');
PGE_Catalog_Activation_Repository::compare_and_swap_state($ended['id'], 'preparing', 1, 'ended', ['next_reconcile_at' => '2026-09-23 11:00:00']);
check('ended is terminal', PGE_Catalog_Activation_Repository::compare_and_swap_state($ended['id'], 'ended', 2, 'active_unbound')['result'], 'terminal');
$due_rows = PGE_Catalog_Activation_Repository::find_due_reconciliation(10);
check('due query excludes terminal and future rows', array_column($due_rows, 'activation_id'), ['due-active']);

echo "RESULT: $passed/$total PASS\n";
exit($failures ? 1 : 0);
