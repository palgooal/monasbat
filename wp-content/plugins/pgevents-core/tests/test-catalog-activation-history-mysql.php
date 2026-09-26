<?php
/** Local-MySQL integration coverage for Catalog Activation History hardening. */

$project_root = dirname(__DIR__, 4);
$_SERVER['REQUEST_SCHEME'] = 'https';
$_SERVER['HTTP_HOST'] = 'monasbat.test';
require_once $project_root . '/wp-load.php';

$site_url = (string) get_option('siteurl', '');
$local_guard = defined('DB_NAME') && DB_NAME === 'monasbat'
    && preg_match('#^https?://[^/]+\.test(?:/|$)#', $site_url)
    && stripos(str_replace('\\', '/', $project_root), '/laragon/www/monasbat') !== false
    && function_exists('wp_get_environment_type')
    && wp_get_environment_type() !== 'production';

if (!$local_guard) {
    fwrite(STDERR, "ABORT: local WordPress guard failed; no database writes performed.\n");
    exit(2);
}

global $wpdb;
$passed = 0;
$total = 0;
$failures = [];
$activation_ids = [];
$activation_db_ids = [];
$event_ids = [];

function mysql_check($label, $actual, $expected)
{
    global $passed, $total, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS: $label\n"; return; }
    $failures[] = $label;
    echo "FAIL: $label expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . "\n";
}

$run = 'phase1-it-' . bin2hex(random_bytes(8));
$activation_a = $run . '-a';
$activation_b = $run . '-b';
$activation_c = $run . '-c';
$idem_a = 'manual:' . $run . '-a';
$idem_b = 'manual:' . $run . '-b';
$idem_c = 'manual:' . $run . '-c';
$event_one = random_int(700000000000, 799999999999);
$event_two = $event_one + 1;
$merchant_id = random_int(700000000, 799999999);
$order_one = $run . '-order-1';
$order_two = $run . '-order-2';

$new_activation = static function ($activation_id, $idempotency_key) {
    return PGE_Catalog_Activation_Repository::create_preparing([
        'activation_id' => $activation_id,
        'user_id' => 900000001,
        'plan_id' => 900000002,
        'tier_id' => 900000003,
        'plan_key' => 'halwa_plus',
        'tier_key' => 'phase1_integration',
        'activation_source' => 'manual',
        'idempotency_key' => $idempotency_key,
        'projection_snapshot' => '{"phase":"integration","exact":"  retained  "}',
    ]);
};

try {
    foreach ([$activation_a, $activation_b, $activation_c] as $value) $activation_ids[] = $value;
    $activation_ids[] = $run . '-different';
    foreach ([$event_one, $event_two] as $value) $event_ids[] = $value;

    $created_a = $new_activation($activation_a, $idem_a);
    mysql_check('create preparing', $created_a['result'] ?? null, 'created');
    $row_a = PGE_Catalog_Activation_Repository::find_by_activation_id($activation_a);
    mysql_check('lookup preparing', $row_a['lifecycle_state'] ?? null, 'preparing');
    mysql_check('exact snapshot', $row_a['projection_snapshot'] ?? null, '{"phase":"integration","exact":"  retained  "}');
    $activation_db_ids[] = (int) ($row_a['id'] ?? 0);

    $duplicate_activation = $new_activation($activation_a, 'manual:' . $run . '-different');
    mysql_check('duplicate activation result', $duplicate_activation['result'] ?? null, 'conflict');
    mysql_check('duplicate activation reason', $duplicate_activation['reason'] ?? null, 'activation_id');

    $duplicate_idempotency = $new_activation($run . '-different', $idem_a);
    mysql_check('duplicate idempotency result', $duplicate_idempotency['result'] ?? null, 'conflict');
    mysql_check('duplicate idempotency reason', $duplicate_idempotency['reason'] ?? null, 'idempotency_key');

    $cas = PGE_Catalog_Activation_Repository::compare_and_swap_state($row_a['id'], 'preparing', 1, 'active_unbound');
    mysql_check('CAS updated', $cas['result'] ?? null, 'updated');
    $stale = PGE_Catalog_Activation_Repository::compare_and_swap_state($row_a['id'], 'preparing', 1, 'active_bound');
    mysql_check('CAS stale revision', $stale['result'] ?? null, 'stale');
    $missing = PGE_Catalog_Activation_Repository::compare_and_swap_state(9223372036854775000, 'preparing', 1, 'active_bound');
    mysql_check('CAS missing', $missing['result'] ?? null, 'missing');

    $projection_failure = PGE_Catalog_Activation_Repository::record_projection_failure($row_a['id'], 2, 'integration_projection_failure', '2026-09-24 12:00:00');
    mysql_check('projection failure recorded', $projection_failure['result'] ?? null, 'recorded');
    $row_a = PGE_Catalog_Activation_Repository::find_by_id($row_a['id']);
    mysql_check('projection failure state unchanged', $row_a['lifecycle_state'] ?? null, 'active_unbound');
    mysql_check('projection failure attempts incremented', (int) ($row_a['projection_attempts'] ?? -1), 1);
    mysql_check('projection failure revision incremented', (int) ($row_a['revision'] ?? -1), 3);
    $projection_stale = PGE_Catalog_Activation_Repository::record_projection_failure($row_a['id'], 2, 'stale', null);
    mysql_check('projection failure stale', $projection_stale['result'] ?? null, 'stale');

    $created_b = $new_activation($activation_b, $idem_b);
    $row_b = PGE_Catalog_Activation_Repository::find_by_activation_id($activation_b);
    $activation_db_ids[] = (int) ($row_b['id'] ?? 0);
    mysql_check('second activation created', $created_b['result'] ?? null, 'created');
    PGE_Catalog_Activation_Repository::compare_and_swap_state($row_b['id'], 'preparing', 1, 'revoked');
    $terminal = PGE_Catalog_Activation_Repository::compare_and_swap_state($row_b['id'], 'revoked', 2, 'active_unbound');
    mysql_check('terminal rejection', $terminal['result'] ?? null, 'terminal');
    $projection_revoked = PGE_Catalog_Activation_Repository::record_projection_failure($row_b['id'], 2, 'revoked', null);
    mysql_check('projection failure revoked terminal', $projection_revoked['result'] ?? null, 'terminal');

    $binding_one = PGE_Catalog_Event_Binding_Repository::create($row_a['id'], $event_one);
    $binding_two = PGE_Catalog_Event_Binding_Repository::create($row_a['id'], $event_two);
    mysql_check('first event binding', $binding_one['result'] ?? null, 'created');
    mysql_check('second event same activation allowed', $binding_two['result'] ?? null, 'created');
    $duplicate_event = PGE_Catalog_Event_Binding_Repository::create($row_b['id'], $event_one);
    mysql_check('duplicate event conflict', $duplicate_event['result'] ?? null, 'conflict');
    mysql_check('duplicate event reason', $duplicate_event['reason'] ?? null, 'event_id');

    $origin = PGE_Catalog_Provider_Origin_Repository::create($row_a['id'], 'salla', $merchant_id, $run . '-customer', $order_one);
    mysql_check('provider origin created', $origin['result'] ?? null, 'created');
    $duplicate_order = PGE_Catalog_Provider_Origin_Repository::create($row_b['id'], 'salla', $merchant_id, $run . '-customer', $order_one);
    mysql_check('duplicate provider order conflict', $duplicate_order['result'] ?? null, 'conflict');
    mysql_check('duplicate provider order reason', $duplicate_order['reason'] ?? null, 'provider_order');
    $duplicate_activation_provider = PGE_Catalog_Provider_Origin_Repository::create($row_a['id'], 'salla', $merchant_id, $run . '-customer', $order_two);
    mysql_check('duplicate activation/provider conflict', $duplicate_activation_provider['result'] ?? null, 'conflict');
    mysql_check('duplicate activation/provider reason', $duplicate_activation_provider['reason'] ?? null, 'activation_provider');

    $created_c = $new_activation($activation_c, $idem_c);
    $row_c = PGE_Catalog_Activation_Repository::find_by_activation_id($activation_c);
    if (is_array($row_c)) $activation_db_ids[] = (int) $row_c['id'];
    mysql_check('third activation created', $created_c['result'] ?? null, 'created');
    PGE_Catalog_Activation_Repository::compare_and_swap_state($row_c['id'], 'preparing', 1, 'ended');
    $projection_ended = PGE_Catalog_Activation_Repository::record_projection_failure($row_c['id'], 2, 'ended', null);
    mysql_check('projection failure ended terminal', $projection_ended['result'] ?? null, 'terminal');
} finally {
    foreach ($event_ids as $event_id) {
        $wpdb->delete(PGE_Catalog_Activation_Schema::events_table(), ['event_id' => $event_id], ['%d']);
    }
    foreach (array_unique(array_filter($activation_db_ids)) as $activation_db_id) {
        $wpdb->delete(PGE_Catalog_Activation_Schema::origins_table(), ['catalog_activation_id' => $activation_db_id], ['%d']);
    }
    foreach (array_unique($activation_ids) as $activation_id) {
        $wpdb->delete(PGE_Catalog_Activation_Schema::activations_table(), ['activation_id' => $activation_id], ['%s']);
    }
}

echo "RESULT: $passed/$total PASS\n";
exit($failures ? 1 : 0);
