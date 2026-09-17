<?php
/**
 * RED-phase executable contract test for PGE_Salla_Token_Manager.
 *
 * No real HTTP request is possible: wp_remote_post() is fully stubbed.
 * The production class is intentionally absent in this phase, so this test
 * must exit non-zero until includes/class-pge-salla-token-manager.php exists.
 *
 * Run: php tests/test-salla-token-manager.php
 */

define('ABSPATH', __DIR__ . '/');
define('SALLA_TOKEN_TEST_NOW', 2000000000);
define('SALLA_TOKEN_TEST_NOW_MYSQL', '2033-05-18 03:33:20');
define('SALLA_TOKEN_TEST_REFRESH_WINDOW', 300);

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $code;
        private $message;
        private $data;

        public function __construct($code = '', $message = '', $data = null)
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}

function is_wp_error($value) { return $value instanceof WP_Error; }

$GLOBALS['salla_token_test_options'] = [];
$GLOBALS['salla_token_test_option_reads'] = [];
$GLOBALS['salla_token_test_option_updates'] = [];
$GLOBALS['salla_token_test_update_should_fail'] = false;
$GLOBALS['salla_token_test_http_calls'] = [];
$GLOBALS['salla_token_test_all_http_calls'] = [];
$GLOBALS['salla_token_test_http_response'] = null;

function get_option($name, $default = false)
{
    $GLOBALS['salla_token_test_option_reads'][$name] =
        ($GLOBALS['salla_token_test_option_reads'][$name] ?? 0) + 1;

    return array_key_exists($name, $GLOBALS['salla_token_test_options'])
        ? $GLOBALS['salla_token_test_options'][$name]
        : $default;
}

function update_option($name, $value, $autoload = null)
{
    $GLOBALS['salla_token_test_option_updates'][] = [
        'name'     => $name,
        'value'    => $value,
        'autoload' => $autoload,
    ];

    if ($GLOBALS['salla_token_test_update_should_fail']) {
        return false;
    }

    $GLOBALS['salla_token_test_options'][$name] = $value;
    return true;
}

function wp_remote_post($url, $args = [])
{
    $call = [
        'url'  => $url,
        'args' => $args,
    ];
    $GLOBALS['salla_token_test_http_calls'][] = $call;
    $GLOBALS['salla_token_test_all_http_calls'][] = $call;

    return $GLOBALS['salla_token_test_http_response'];
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) ? ($response['body'] ?? '') : '';
}

function current_time($type = 'mysql', $gmt = 0)
{
    if ($type === 'timestamp' || $type === 'U') {
        return SALLA_TOKEN_TEST_NOW;
    }

    return SALLA_TOKEN_TEST_NOW_MYSQL;
}

class Fake_Wpdb_Salla_Token_Manager
{
    public $lock_attempts = [];
    public $lock_acquired = [];
    public $lock_released = [];
    public $held_locks = [];
    public $force_lock_unavailable = false;
    public $on_lock_acquired = null;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $index = 0;
        return preg_replace_callback('/%[ds]/', function ($match) use (&$index, $args) {
            $value = $args[$index] ?? '';
            $index++;

            if ($match[0] === '%d') {
                return (string) (int) $value;
            }

            return "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    public function get_var($sql)
    {
        if (preg_match("/SELECT\\s+GET_LOCK\\('([^']*)',\\s*(-?\\d+)\\)/i", $sql, $matches)) {
            $lock_name = stripslashes($matches[1]);
            $this->lock_attempts[] = $lock_name;

            if ($this->force_lock_unavailable || isset($this->held_locks[$lock_name])) {
                return '0';
            }

            $this->held_locks[$lock_name] = true;
            $this->lock_acquired[] = $lock_name;

            if (is_callable($this->on_lock_acquired)) {
                call_user_func($this->on_lock_acquired, $lock_name);
            }

            return '1';
        }

        return null;
    }

    public function query($sql)
    {
        if (preg_match("/SELECT\\s+RELEASE_LOCK\\('([^']*)'\\)/i", $sql, $matches)) {
            $lock_name = stripslashes($matches[1]);
            $this->lock_released[] = $lock_name;
            unset($this->held_locks[$lock_name]);
            return 1;
        }

        return false;
    }
}

$wpdb = new Fake_Wpdb_Salla_Token_Manager();

function salla_token_fixture(array $overrides = [])
{
    return array_merge([
        'access_token'  => 'existing-access-token',
        'refresh_token' => 'old-rotating-refresh-token',
        'expires'       => SALLA_TOKEN_TEST_NOW + 3600,
        'scope'         => 'customers.read_write offline_access',
        'token_type'    => 'bearer',
        'updated_at'    => '2033-05-17 00:00:00',
    ], $overrides);
}

function salla_refresh_success_payload(array $overrides = [])
{
    return array_merge([
        'access_token'  => 'new-access-token',
        'refresh_token' => 'new-rotated-refresh-token',
        'expires'       => SALLA_TOKEN_TEST_NOW + 1209600,
        'scope'         => 'customers.read_write offline_access',
        'token_type'    => 'bearer',
    ], $overrides);
}

function reset_salla_token_test_state()
{
    global $wpdb;

    $GLOBALS['salla_token_test_options'] = [
        'pge_salla_tokens_123'      => salla_token_fixture(),
        'pge_salla_client_id'       => 'test-client-id',
        'pge_salla_client_secret'   => 'test-client-secret',
    ];
    $GLOBALS['salla_token_test_option_reads'] = [];
    $GLOBALS['salla_token_test_option_updates'] = [];
    $GLOBALS['salla_token_test_update_should_fail'] = false;
    $GLOBALS['salla_token_test_http_calls'] = [];
    $GLOBALS['salla_token_test_http_response'] = [
        'response' => ['code' => 200],
        'body'     => json_encode(salla_refresh_success_payload()),
    ];

    $wpdb = new Fake_Wpdb_Salla_Token_Manager();
}

$total = 0;
$passed = 0;
$failures = [];

function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;

    if ($actual === $expected) {
        $passed++;
        echo "PASS  $label\n";
        return;
    }

    $failures[] = $label;
    echo "FAIL  $label (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

function check_wp_error($label, $result)
{
    check_true($label . ' returns WP_Error', is_wp_error($result));
    check_true(
        $label . ' has a non-empty error code',
        is_wp_error($result) && is_string($result->get_error_code()) && $result->get_error_code() !== ''
    );
}

function check_safe_wp_error($label, $result)
{
    check_wp_error($label, $result);

    $error_output = is_wp_error($result)
        ? json_encode([
            'message' => $result->get_error_message(),
            'data'    => $result->get_error_data(),
        ])
        : '';

    foreach (['existing-access-token', 'old-rotating-refresh-token', 'test-client-secret'] as $secret) {
        check_true($label . ' does not expose secret values', strpos((string) $error_output, $secret) === false);
    }
}

function check_no_http($label)
{
    check($label . ' makes no HTTP request', count($GLOBALS['salla_token_test_http_calls']), 0);
}

function check_no_lock($label)
{
    global $wpdb;
    check($label . ' makes no lock attempt', count($wpdb->lock_attempts), 0);
}

function check_releases_every_acquired_lock($label)
{
    global $wpdb;
    $acquired = $wpdb->lock_acquired;
    $released = $wpdb->lock_released;
    sort($acquired);
    sort($released);
    check($label . ' releases every acquired lock', $released, $acquired);
    check($label . ' leaves no lock held', count($wpdb->held_locks), 0);
}

function refresh_request_body($call)
{
    $body = $call['args']['body'] ?? [];
    if (is_array($body)) {
        return $body;
    }

    if (is_string($body)) {
        parse_str($body, $parsed);
        return $parsed;
    }

    return [];
}

function call_token_manager($merchant_id)
{
    return PGE_Salla_Token_Manager::get_valid_access_token($merchant_id);
}

$manager_file = dirname(__DIR__) . '/includes/class-pge-salla-token-manager.php';
if (!is_file($manager_file)) {
    echo "FAIL  RED phase: includes/class-pge-salla-token-manager.php does not exist yet.\n";
    echo "Expected failure: implement PGE_Salla_Token_Manager in a later phase.\n";
    exit(1);
}

require_once $manager_file;

if (!class_exists('PGE_Salla_Token_Manager')) {
    echo "FAIL  RED phase: PGE_Salla_Token_Manager is not defined.\n";
    exit(1);
}

// 1. Invalid merchant ID.
reset_salla_token_test_state();
$result = call_token_manager(0);
check_safe_wp_error('invalid merchant ID', $result);
check_no_http('invalid merchant ID');
check_no_lock('invalid merchant ID');

// 2. Missing token option.
reset_salla_token_test_state();
unset($GLOBALS['salla_token_test_options']['pge_salla_tokens_123']);
$result = call_token_manager(123);
check_safe_wp_error('missing token option', $result);
check_no_http('missing token option');
check_no_lock('missing token option');

// 3. Invalid stored token structure.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123'] = 'not-an-array';
$result = call_token_manager(123);
check_safe_wp_error('non-array token data', $result);
check_no_http('non-array token data');
check_no_lock('non-array token data');

reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123'] = ['access_token' => ''];
$result = call_token_manager(123);
check_safe_wp_error('incomplete token data', $result);
check_no_http('incomplete token data');
check_no_lock('incomplete token data');

// 4. Comfortably valid access token.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123'] = salla_token_fixture([
    'expires' => SALLA_TOKEN_TEST_NOW + SALLA_TOKEN_TEST_REFRESH_WINDOW + 3600,
]);
$result = call_token_manager(123);
check('valid token returns existing access token', $result, 'existing-access-token');
check_no_http('valid token');
check_no_lock('valid token');

// 5. Token inside the refresh safety window.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123'] = salla_token_fixture([
    'expires' => SALLA_TOKEN_TEST_NOW + SALLA_TOKEN_TEST_REFRESH_WINDOW - 1,
]);
$result = call_token_manager(123);
check('token inside safety window returns refreshed access token', $result, 'new-access-token');
check('token inside safety window performs one OAuth request', count($GLOBALS['salla_token_test_http_calls']), 1);
check('token inside safety window acquires one lock', count($wpdb->lock_acquired), 1);
check_releases_every_acquired_lock('token inside safety window');

// 6. Expired access token.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123'] = salla_token_fixture([
    'expires' => SALLA_TOKEN_TEST_NOW - 1,
]);
$result = call_token_manager(123);
check('expired token returns refreshed access token', $result, 'new-access-token');
check('expired token performs one OAuth request', count($GLOBALS['salla_token_test_http_calls']), 1);
check_releases_every_acquired_lock('expired token');

// Merchant-scoped lock names must differ.
$first_lock_name = $wpdb->lock_acquired[0] ?? '';
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_124'] = salla_token_fixture([
    'expires' => SALLA_TOKEN_TEST_NOW - 1,
]);
call_token_manager(124);
$second_lock_name = $wpdb->lock_acquired[0] ?? '';
check_true('lock name is non-empty', $first_lock_name !== '' && $second_lock_name !== '');
check_true('lock is scoped by merchant ID', $first_lock_name !== $second_lock_name);
check_releases_every_acquired_lock('merchant-scoped lock');

// 7. Lock cannot be acquired.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
$wpdb->force_lock_unavailable = true;
$result = call_token_manager(123);
check_safe_wp_error('lock unavailable', $result);
check('lock unavailable records one attempt', count($wpdb->lock_attempts), 1);
check('lock unavailable acquires no lock', count($wpdb->lock_acquired), 0);
check_no_http('lock unavailable');
check_releases_every_acquired_lock('lock unavailable');

// 8. Another process refreshes after the initial read but before the locked re-read.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
$newer_token = salla_token_fixture([
    'access_token'  => 'concurrently-refreshed-access-token',
    'refresh_token' => 'concurrently-rotated-refresh-token',
    'expires'       => SALLA_TOKEN_TEST_NOW + 7200,
    'updated_at'    => SALLA_TOKEN_TEST_NOW_MYSQL,
]);
$wpdb->on_lock_acquired = function () use ($newer_token) {
    $GLOBALS['salla_token_test_options']['pge_salla_tokens_123'] = $newer_token;
};
$result = call_token_manager(123);
check('locked re-read returns concurrently refreshed token', $result, 'concurrently-refreshed-access-token');
check_no_http('locked re-read with fresh token');
check_true(
    'token option is read at least twice around the lock',
    ($GLOBALS['salla_token_test_option_reads']['pge_salla_tokens_123'] ?? 0) >= 2
);
check_releases_every_acquired_lock('locked re-read with fresh token');

// 9. Missing refresh token when refresh is required.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123'] = salla_token_fixture([
    'expires'       => SALLA_TOKEN_TEST_NOW - 1,
    'refresh_token' => '',
]);
$result = call_token_manager(123);
check_safe_wp_error('missing refresh token', $result);
check_no_http('missing refresh token');
check_releases_every_acquired_lock('missing refresh token');

// 10. Missing client ID.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
$GLOBALS['salla_token_test_options']['pge_salla_client_id'] = '';
$result = call_token_manager(123);
check_safe_wp_error('missing client ID', $result);
check_no_http('missing client ID');
check_releases_every_acquired_lock('missing client ID');

// 11. Missing client secret.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
$GLOBALS['salla_token_test_options']['pge_salla_client_secret'] = '';
$result = call_token_manager(123);
check_safe_wp_error('missing client secret', $result);
check_no_http('missing client secret');
check_releases_every_acquired_lock('missing client secret');

// 12. OAuth transport WP_Error must be wrapped safely.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
$GLOBALS['salla_token_test_http_response'] = new WP_Error(
    'http_request_failed',
    'Simulated failure containing existing-access-token, old-rotating-refresh-token, and test-client-secret.'
);
$result = call_token_manager(123);
check_safe_wp_error('OAuth transport failure', $result);
check('OAuth transport failure makes one request', count($GLOBALS['salla_token_test_http_calls']), 1);
check_releases_every_acquired_lock('OAuth transport failure');

// 13. OAuth non-2xx must expose HTTP status only.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
$GLOBALS['salla_token_test_http_response'] = [
    'response' => ['code' => 401],
    'body'     => json_encode([
        'error'             => 'invalid_grant',
        'error_description' => 'existing-access-token old-rotating-refresh-token test-client-secret',
    ]),
];
$result = call_token_manager(123);
check_safe_wp_error('OAuth non-2xx', $result);
check('OAuth non-2xx error data contains HTTP status only', $result->get_error_data(), ['http_status' => 401]);
check_releases_every_acquired_lock('OAuth non-2xx');

// 14. Empty OAuth response.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
$GLOBALS['salla_token_test_http_response'] = [
    'response' => ['code' => 200],
    'body'     => '',
];
$result = call_token_manager(123);
check_safe_wp_error('empty OAuth response', $result);
check_releases_every_acquired_lock('empty OAuth response');

// 15. Invalid JSON.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
$GLOBALS['salla_token_test_http_response'] = [
    'response' => ['code' => 200],
    'body'     => '{"access_token":"existing-access-token", invalid',
];
$result = call_token_manager(123);
check_safe_wp_error('invalid OAuth JSON', $result);
check_releases_every_acquired_lock('invalid OAuth JSON');

// 16-20. Successful refresh rotates and persists the complete token set.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
$result = call_token_manager(123);
check('successful refresh returns new access token', $result, 'new-access-token');
check('successful refresh performs one request', count($GLOBALS['salla_token_test_http_calls']), 1);
check('successful refresh persists once', count($GLOBALS['salla_token_test_option_updates']), 1);
$persisted = $GLOBALS['salla_token_test_options']['pge_salla_tokens_123'] ?? [];
check('persisted access token is rotated value', $persisted['access_token'] ?? null, 'new-access-token');
check('persisted refresh token is rotated value', $persisted['refresh_token'] ?? null, 'new-rotated-refresh-token');
check('persisted expiry is new absolute timestamp', $persisted['expires'] ?? null, SALLA_TOKEN_TEST_NOW + 1209600);
check('persisted scope is refreshed scope', $persisted['scope'] ?? null, 'customers.read_write offline_access');
check('persisted token type is refreshed type', $persisted['token_type'] ?? null, 'bearer');
check('persisted updated_at is deterministic current time', $persisted['updated_at'] ?? null, SALLA_TOKEN_TEST_NOW_MYSQL);
check_true(
    'persisted option does not retain old rotated refresh token',
    strpos(json_encode($persisted), 'old-rotating-refresh-token') === false
);
check_releases_every_acquired_lock('successful refresh');

// 17-18. Exact endpoint and OAuth refresh request contract.
$call = $GLOBALS['salla_token_test_http_calls'][0] ?? [];
check('refresh uses only the official token endpoint', $call['url'] ?? null, 'https://accounts.salla.sa/oauth2/token');
$request_body = refresh_request_body($call);
check('refresh grant_type', $request_body['grant_type'] ?? null, 'refresh_token');
check('refresh request client_id', $request_body['client_id'] ?? null, 'test-client-id');
check('refresh request client_secret', $request_body['client_secret'] ?? null, 'test-client-secret');
check('refresh request uses current refresh token', $request_body['refresh_token'] ?? null, 'old-rotating-refresh-token');

// 22. Malformed successful payloads must never be persisted.
$invalid_payloads = [
    'missing access token'  => salla_refresh_success_payload(['access_token' => '']),
    'missing rotated token' => salla_refresh_success_payload(['refresh_token' => '']),
    'invalid expiry'        => salla_refresh_success_payload(['expires' => 'not-a-timestamp']),
    'expired new token'     => salla_refresh_success_payload(['expires' => SALLA_TOKEN_TEST_NOW - 1]),
    'missing scope'         => salla_refresh_success_payload(['scope' => '']),
    'missing token type'    => salla_refresh_success_payload(['token_type' => '']),
];

foreach ($invalid_payloads as $label => $payload) {
    reset_salla_token_test_state();
    $GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
    $before = $GLOBALS['salla_token_test_options']['pge_salla_tokens_123'];
    $GLOBALS['salla_token_test_http_response'] = [
        'response' => ['code' => 200],
        'body'     => json_encode($payload),
    ];

    $result = call_token_manager(123);
    check_safe_wp_error('malformed OAuth payload: ' . $label, $result);
    check(
        'malformed OAuth payload is not persisted: ' . $label,
        $GLOBALS['salla_token_test_options']['pge_salla_tokens_123'],
        $before
    );
    check('malformed OAuth payload performs no update: ' . $label, count($GLOBALS['salla_token_test_option_updates']), 0);
    check_releases_every_acquired_lock('malformed OAuth payload: ' . $label);
}

// 23. Persistence failure must not report the refreshed token as usable.
reset_salla_token_test_state();
$GLOBALS['salla_token_test_options']['pge_salla_tokens_123']['expires'] = SALLA_TOKEN_TEST_NOW - 1;
$before = $GLOBALS['salla_token_test_options']['pge_salla_tokens_123'];
$GLOBALS['salla_token_test_update_should_fail'] = true;
$result = call_token_manager(123);
check_safe_wp_error('token persistence failure', $result);
check_true('persistence failure does not return refreshed access token', $result !== 'new-access-token');
check('persistence failure leaves authoritative option unchanged', $GLOBALS['salla_token_test_options']['pge_salla_tokens_123'], $before);
check_releases_every_acquired_lock('token persistence failure');

// Every attempted OAuth call in this process must target the one approved endpoint.
foreach ($GLOBALS['salla_token_test_all_http_calls'] as $index => $http_call) {
    check(
        'HTTP call #' . ($index + 1) . ' targets only the official endpoint',
        $http_call['url'] ?? null,
        'https://accounts.salla.sa/oauth2/token'
    );
}

echo "\n============================================\n";
echo "Total: $total | Passed: $passed | Failed: " . ($total - $passed) . "\n";

if ($failures) {
    exit(1);
}

echo "All Salla Token Manager contract tests passed. No real HTTP request was possible.\n";
exit(0);
