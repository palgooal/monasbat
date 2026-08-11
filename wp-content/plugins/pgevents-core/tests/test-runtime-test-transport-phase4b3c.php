<?php
/**
 * Phase 4B-3C — Safe Runtime Test Transport Seam.
 *
 * Standalone PHP harness. Child processes isolate immutable constants so the
 * complete server-side activation matrix is exercised without WordPress, a
 * database, HTTP, AJAX, Cron, or real delivery.
 */

if (($argv[1] ?? '') === '--child') {
    define('ABSPATH', __DIR__ . '/');
    $scenario = (string) ($argv[2] ?? '');
    $GLOBALS['rt_env'] = str_contains($scenario, 'production')
        ? 'production'
        : (str_contains($scenario, 'development') ? 'development' : (str_contains($scenario, 'test_env') ? 'test' : 'local'));
    $GLOBALS['rt_http_calls'] = 0;

    function get_option($name, $default = '') { return $default; }
    function pge_norm_phone($value) { return preg_replace('/\D+/', '', (string) $value); }
    function wp_get_environment_type() { return $GLOBALS['rt_env']; }
    function wp_remote_post($url, $args = []) { $GLOBALS['rt_http_calls']++; return []; }

    if (!str_starts_with($scenario, 'no_constant')) {
        define('PGE_ENABLE_TEST_TRANSPORT', !str_starts_with($scenario, 'false_'));
    }
    if (str_contains($scenario, 'rejected')) {
        define('PGE_TEST_TRANSPORT_OUTCOME', 'rejected');
    } elseif (str_contains($scenario, 'ambiguous')) {
        define('PGE_TEST_TRANSPORT_OUTCOME', 'ambiguous');
    } elseif (str_contains($scenario, 'invalid')) {
        define('PGE_TEST_TRANSPORT_OUTCOME', 'client-value-is-invalid');
    }

    $_GET = ['test_mode' => '1', 'provider' => 'fake'];
    $_POST = ['test_mode' => '1', 'provider' => 'fake'];
    $_REQUEST = array_merge($_GET, $_POST);
    $_COOKIE = ['pge_test_transport' => '1'];

    require_once dirname(__DIR__) . '/includes/class-pge-cartat-transport.php';
    require_once dirname(__DIR__) . '/includes/class-pge-thank-you-transport-factory.php';

    $transport = PGE_Thank_You_Transport_Factory::resolve();
    $is_fake = $transport instanceof PGE_Thank_You_Test_Transport;
    $raw = 'not_called';
    $interpreted = 'not_called';
    if ($is_fake) {
        $raw = $transport->send_text('0500000000', 'fixture only');
        $interpreted = $transport->interpret_result($raw);
    }

    echo json_encode([
        'class' => get_class($transport),
        'is_fake' => $is_fake,
        'has_credentials' => $transport->has_credentials(),
        'raw' => $raw,
        'interpreted' => $interpreted,
        'http_calls' => $GLOBALS['rt_http_calls'],
        'send_count' => $is_fake ? PGE_Thank_You_Test_Transport::send_count() : 0,
    ]);
    exit;
}

$passed = 0;
$failed = 0;
function rt_check(string $label, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n  expected=" . var_export($expected, true)
        . "\n  actual=" . var_export($actual, true) . "\n";
}

function rt_scenario(string $scenario): array
{
    $command = [PHP_BINARY, __FILE__, '--child', $scenario];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        return ['process_error' => 'start_failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode($stdout, true);
    return is_array($decoded)
        ? $decoded + ['process_exit' => $exit, 'process_stderr' => $stderr]
        : ['process_error' => 'invalid_json', 'process_exit' => $exit];
}

$none = rt_scenario('no_constant_forged_client');
$false = rt_scenario('false_local');
$local = rt_scenario('true_local_accepted');
$test = rt_scenario('true_test_env_accepted');
$production = rt_scenario('true_production_accepted');
$development = rt_scenario('true_development_accepted');
$rejected = rt_scenario('true_local_rejected');
$ambiguous = rt_scenario('true_local_ambiguous');
$invalid = rt_scenario('true_local_invalid');

rt_check('no constant selects real Cartat', $none['class'] ?? '', 'PGE_Cartat_Transport');
rt_check('forged client fields cannot select fake', $none['is_fake'] ?? null, false);
rt_check('constant false selects real Cartat', $false['class'] ?? '', 'PGE_Cartat_Transport');
rt_check('constant true plus local selects fake', $local['class'] ?? '', 'PGE_Thank_You_Test_Transport');
rt_check('constant true plus test selects fake', $test['is_fake'] ?? null, true);
rt_check('constant true plus production denies fake', $production['is_fake'] ?? null, false);
rt_check('constant true plus development denies fake', $development['is_fake'] ?? null, false);
rt_check('fake satisfies Cartat type contract', $local['is_fake'] ?? null, true);
rt_check('fake never depends on real credentials', $local['has_credentials'] ?? null, true);
rt_check('accepted raw contract', $local['raw']['status'] ?? '', 'sent');
rt_check('accepted interpretation', $local['interpreted'] ?? '', 'accepted');
rt_check('rejected raw contract', $rejected['raw']['status'] ?? '', 'error');
rt_check('rejected interpretation', $rejected['interpreted'] ?? '', 'rejected');
rt_check(
    'ambiguous raw contract is null',
    array_key_exists('raw', $ambiguous) ? $ambiguous['raw'] : 'missing',
    null
);
rt_check('ambiguous interpretation', $ambiguous['interpreted'] ?? '', 'transport_error');
rt_check('invalid server outcome fails to accepted', $invalid['interpreted'] ?? '', 'accepted');
rt_check('accepted fake performs zero HTTP', $local['http_calls'] ?? null, 0);
rt_check('rejected fake performs zero HTTP', $rejected['http_calls'] ?? null, 0);
rt_check('ambiguous fake performs zero HTTP', $ambiguous['http_calls'] ?? null, 0);
rt_check('fake counter stores count only', $local['send_count'] ?? null, 1);

$root = dirname(__DIR__);
$factory_source = file_get_contents($root . '/includes/class-pge-thank-you-transport-factory.php');
$worker_source = file_get_contents($root . '/includes/class-pge-thank-you-batch-worker.php');
$service_source = file_get_contents($root . '/includes/class-pge-thank-you-message-service.php');
$reminder_source = file_get_contents($root . '/includes/class-pge-reminder-message-service.php');
$plugin_source = file_get_contents($root . '/pgevents-core.php');

rt_check('factory reads no GET', strpos($factory_source, '$_GET') === false, true);
rt_check('factory reads no POST', strpos($factory_source, '$_POST') === false, true);
rt_check('factory reads no REQUEST', strpos($factory_source, '$_REQUEST') === false, true);
rt_check('factory reads no COOKIE', strpos($factory_source, '$_COOKIE') === false, true);
rt_check('factory reads no database option', strpos($factory_source, 'get_option(') === false, true);
rt_check('factory registers no AJAX or UI hook', strpos($factory_source, 'add_action(') === false, true);
rt_check('fake source has no HTTP call', strpos($factory_source, 'wp_remote_') === false, true);
rt_check('fake source has no Cartat endpoint', strpos($factory_source, 'api.cartat.net') === false, true);
rt_check('fake source logs no secrets', strpos($factory_source, 'error_log(') === false, true);
rt_check('fake has no phone property', preg_match('/private\s+[^;]*\$phone\b/', $factory_source), 0);
rt_check('fake has no message property', preg_match('/private\s+[^;]*\$message\b/', $factory_source), 0);
rt_check('fake has no credential property', preg_match('/private\s+[^;]*\$(?:token|credential|secret)\b/', $factory_source), 0);
rt_check('worker resolves transport at both construction points', substr_count($worker_source, 'PGE_Thank_You_Transport_Factory::resolve()'), 2);
rt_check('worker no longer constructs Cartat directly', strpos($worker_source, 'new PGE_Cartat_Transport') === false, true);
rt_check('service compatibility path resolves transport', substr_count($service_source, 'PGE_Thank_You_Transport_Factory::resolve()'), 1);
rt_check('service no longer constructs Cartat directly', strpos($service_source, 'new PGE_Cartat_Transport') === false, true);
rt_check('Reminder is outside the seam', strpos($reminder_source, 'PGE_Thank_You_Transport_Factory') === false, true);
rt_check('plugin loads factory', strpos($plugin_source, "includes/class-pge-thank-you-transport-factory.php") !== false, true);
rt_check('factory contains no credit authority', stripos($factory_source, 'credit') === false, true);
rt_check('all child scenarios exited cleanly', array_sum(array_map(
    static fn($result) => (int) (($result['process_exit'] ?? 1) !== 0),
    [$none, $false, $local, $test, $production, $development, $rejected, $ambiguous, $invalid]
)), 0);

$total = $passed + $failed;
echo "Runtime Test Transport Phase 4B-3C: {$passed}/{$total} passed\n";
exit($failed === 0 ? 0 : 1);
