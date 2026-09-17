<?php
/**
 * Executable unit test for PGE_Salla_Customer_Groups_Service.
 *
 * wp_remote_post() is fully stubbed below, so this test cannot contact Salla.
 * Run: php tests/test-salla-customer-groups-service.php
 */

define('ABSPATH', __DIR__ . '/');

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
function wp_json_encode($value) { return json_encode($value); }

$GLOBALS['salla_test_manager_calls'] = [];
$GLOBALS['salla_test_http_calls'] = [];
$GLOBALS['salla_test_http_response'] = null;
$GLOBALS['salla_test_get_calls'] = [];
$GLOBALS['salla_test_get_response'] = null;

function get_option($name, $default = false)
{
    throw new RuntimeException('Customer Groups must not read token options directly.');
}

function wp_remote_post($url, $args = [])
{
    $GLOBALS['salla_test_http_calls'][] = [
        'url'  => $url,
        'args' => $args,
    ];
    return $GLOBALS['salla_test_http_response'];
}

function wp_remote_get($url, $args = [])
{
    $GLOBALS['salla_test_get_calls'][] = ['url' => $url, 'args' => $args];
    return $GLOBALS['salla_test_get_response'];
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) ? ($response['body'] ?? '') : '';
}

function reset_salla_test_state()
{
    $GLOBALS['salla_test_manager_token'] = 'manager-access-token';
    $GLOBALS['salla_test_manager_calls'] = [];
    $GLOBALS['salla_test_http_calls'] = [];
    $GLOBALS['salla_test_get_calls'] = [];
    $GLOBALS['salla_test_http_response'] = [
        'response' => ['code' => 200],
        'body'     => json_encode([
            'status'  => 200,
            'success' => true,
            'data'    => ['The customers has been added to group successfully'],
        ]),
    ];
    $GLOBALS['salla_test_get_response'] = [
        'response' => ['code' => 200],
        'body' => json_encode(['status' => 200, 'success' => true, 'data' => ['groups' => [789]]]),
    ];
}

$failures = 0;
$checks = 0;

function check($label, $actual, $expected)
{
    global $failures, $checks;
    $checks++;
    if ($actual === $expected) {
        echo "PASS  $label\n";
        return;
    }

    $failures++;
    echo "FAIL  $label (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

function check_error($label, $result, $expected_code)
{
    check_true($label . ' returns WP_Error', is_wp_error($result));
    check($label . ' error code', is_wp_error($result) ? $result->get_error_code() : null, $expected_code);
}

require_once dirname(__DIR__) . '/includes/class-pge-salla-customer-groups-service.php';

$service = new PGE_Salla_Customer_Groups_Service();

reset_salla_test_state();
$result = $service->add_customer_to_group('invalid', 456, 789);
check_error('invalid merchant_id', $result, 'invalid_salla_merchant_id');
check('invalid merchant_id makes no HTTP request', count($GLOBALS['salla_test_http_calls']), 0);

reset_salla_test_state();
$result = $service->add_customer_to_group(123, 0, 789);
check_error('invalid customer_id', $result, 'invalid_salla_customer_id');
check('invalid customer_id makes no HTTP request', count($GLOBALS['salla_test_http_calls']), 0);

reset_salla_test_state();
$result = $service->add_customer_to_group(123, 456, -1);
check_error('invalid group_id', $result, 'invalid_salla_group_id');
check('invalid group_id makes no HTTP request', count($GLOBALS['salla_test_http_calls']), 0);

reset_salla_test_state();
check('manager is absent before conditional stub declaration', class_exists('PGE_Salla_Token_Manager'), false);
$result = $service->add_customer_to_group(123, 456, 789);
check_error('unavailable manager', $result, 'salla_token_manager_unavailable');
check('unavailable manager makes no HTTP request', count($GLOBALS['salla_test_http_calls']), 0);

// Conditional declaration runs only after exercising the missing-class path.
if (!class_exists('PGE_Salla_Token_Manager')) {
    class PGE_Salla_Token_Manager
    {
        public static function get_valid_access_token($merchant_id)
        {
            $GLOBALS['salla_test_manager_calls'][] = $merchant_id;
            return $GLOBALS['salla_test_manager_token'];
        }
    }
}

$manager_codes = [
    'invalid_salla_merchant_id', 'salla_tokens_missing', 'salla_token_data_invalid',
    'salla_token_lock_unavailable', 'salla_token_refresh_lock_failed',
    'salla_refresh_token_missing', 'salla_client_id_missing', 'salla_client_secret_missing',
    'salla_token_refresh_transport_error', 'salla_token_refresh_http_error',
    'salla_token_refresh_empty_response', 'salla_token_refresh_invalid_json',
    'salla_token_refresh_invalid_response', 'salla_token_persistence_failed',
];
foreach ($manager_codes as $code) {
    reset_salla_test_state();
    $GLOBALS['salla_test_manager_token'] = new WP_Error($code,
        'access_token=fake-access refresh_token=fake-refresh client_secret=fake-secret',
        ['access_token' => 'fake-access', 'refresh_token' => 'fake-refresh', 'client_secret' => 'fake-secret']);
    $result = $service->add_customer_to_group(123, 456, 789);
    check_error('manager ' . $code, $result, $code);
    check('manager failure makes no HTTP request', count($GLOBALS['salla_test_http_calls']), 0);
    check('manager error uses safe message', $result->get_error_message(), 'Unable to obtain a valid Salla access token.');
    check('manager error discards sensitive data', $result->get_error_data(), null);
}
reset_salla_test_state();
$GLOBALS['salla_test_manager_token'] = new WP_Error('fake-secret-in-error-code', 'fake-secret');
$result = $service->add_customer_to_group(123, 456, 789);
check_error('unknown manager error code is not exposed', $result, 'salla_token_acquisition_failed');
check('unknown manager error makes no HTTP request', count($GLOBALS['salla_test_http_calls']), 0);

foreach (['', '   ', null, false, true, 123, [], new stdClass(), "bad\r\nheader", 'bad token'] as $token) {
    reset_salla_test_state();
    $GLOBALS['salla_test_manager_token'] = $token;
    $result = $service->add_customer_to_group(123, 456, 789);
    check_error('empty/invalid manager token', $result,
        is_string($token) && trim($token) === '' ? 'salla_access_token_missing' : 'salla_token_data_invalid');
    check('empty/invalid manager token makes no HTTP request', count($GLOBALS['salla_test_http_calls']), 0);
}

foreach ([[0, 456, 789], [123, false, 789], [123, 456, []]] as $ids) {
    reset_salla_test_state();
    $result = $service->add_customer_to_group(...$ids);
    check_true('validation still rejects invalid IDs with manager loaded', is_wp_error($result));
    check('invalid IDs do not call manager', $GLOBALS['salla_test_manager_calls'], []);
    check('invalid IDs do not call HTTP', $GLOBALS['salla_test_http_calls'], []);
}

reset_salla_test_state();
$GLOBALS['salla_test_http_response'] = [
    'response' => ['code' => 401],
    'body' => json_encode(['success' => false, 'message' => 'Missing customers.read_write scope']),
];
$result = $service->add_customer_to_group(123, 456, 789);
check_error('401 scope rejection', $result, 'salla_customer_group_http_error');
check('401 preserves HTTP status', $result->get_error_data(), ['http_status' => 401]);
check('401 does not retry group request', count($GLOBALS['salla_test_http_calls']), 1);
check('401 does not reacquire or refresh token', $GLOBALS['salla_test_manager_calls'], [123]);

reset_salla_test_state();
$GLOBALS['salla_test_http_response'] = new WP_Error('http_request_failed', 'Simulated transport failure.');
$result = $service->add_customer_to_group(123, 456, 789);
check_error('WordPress HTTP failure', $result, 'salla_customer_group_transport_error');
check('WordPress HTTP failure uses one stub request', count($GLOBALS['salla_test_http_calls']), 1);

reset_salla_test_state();
$GLOBALS['salla_test_http_response'] = [
    'response' => ['code' => 422],
    'body'     => json_encode(['status' => 422, 'success' => false]),
];
$result = $service->add_customer_to_group(123, 456, 789);
check_error('non-2xx response', $result, 'salla_customer_group_http_error');

reset_salla_test_state();
$GLOBALS['salla_test_http_response']['body'] = '';
$result = $service->add_customer_to_group(123, 456, 789);
check_error('empty 2xx response', $result, 'salla_customer_group_empty_response');

reset_salla_test_state();
$GLOBALS['salla_test_http_response']['body'] = '{invalid json';
$result = $service->add_customer_to_group(123, 456, 789);
check_error('invalid JSON response', $result, 'salla_customer_group_invalid_json');

reset_salla_test_state();
$GLOBALS['salla_test_http_response']['body'] = json_encode([
    'status'  => 200,
    'success' => false,
    'data'    => ['message' => 'Rejected by API'],
]);
$result = $service->add_customer_to_group(123, 456, 789);
check_error('2xx API rejection', $result, 'salla_customer_group_api_error');

reset_salla_test_state();
$result = $service->add_customer_to_group('123', 456, 789);
check('manager receives normalized merchant ID once', $GLOBALS['salla_test_manager_calls'], [123]);
check_true('valid 2xx JSON response succeeds', is_array($result) && ($result['success'] ?? false) === true);
check('success HTTP status', $result['http_status'] ?? null, 200);
check('success API status', $result['api_status'] ?? null, 200);
check('success makes one stub request', count($GLOBALS['salla_test_http_calls']), 1);

$call = $GLOBALS['salla_test_http_calls'][0] ?? [];
check('request endpoint', $call['url'] ?? null, 'https://api.salla.dev/admin/v2/customers/groups/add_customers');
check('Authorization header', $call['args']['headers']['Authorization'] ?? null, 'Bearer manager-access-token');
check('Content-Type header', $call['args']['headers']['Content-Type'] ?? null, 'application/json');
check('Accept header', $call['args']['headers']['Accept'] ?? null, 'application/json');
check('request body', json_decode($call['args']['body'] ?? '', true), [
    'group_id'  => 789,
    'customers' => [456],
]);

reset_salla_test_state();
$membership = $service->customer_is_in_group(123, 456, 789);
check_true('Customer Details confirms group membership', is_array($membership) && $membership['is_member'] === true);
check('Customer Details uses documented endpoint', $GLOBALS['salla_test_get_calls'][0]['url'] ?? null, 'https://api.salla.dev/admin/v2/customers/456');
check('Customer Details uses manager token', $GLOBALS['salla_test_get_calls'][0]['args']['headers']['Authorization'] ?? null, 'Bearer manager-access-token');

reset_salla_test_state();
$GLOBALS['salla_test_get_response']['body'] = json_encode(['status' => 200, 'success' => true, 'data' => ['groups' => [111]]]);
$membership = $service->customer_is_in_group(123, 456, 789);
check('Customer Details reports absent membership', $membership['is_member'] ?? null, false);

reset_salla_test_state();
$GLOBALS['salla_test_get_response'] = new WP_Error('http_request_failed', 'simulated');
$membership = $service->customer_is_in_group(123, 456, 789);
check_error('Customer Details transport failure', $membership, 'salla_customer_details_transport_error');
check('Customer Details transport performs one stub request', count($GLOBALS['salla_test_get_calls']), 1);

$service_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-salla-customer-groups-service.php');
$plugin_source = file_get_contents(dirname(__DIR__) . '/pgevents-core.php');
check_true('group ID is not hardcoded in service', strpos($service_source, '225189340') === false);
check_true('generic service contains no Plus plan logic', strpos($service_source, 'halwa_plus') === false);
check_true(
    'plugin bootstrap loads the service',
    strpos($plugin_source, "require_once PGE_PATH . 'includes/class-pge-salla-customer-groups-service.php';") !== false
);

$manager_require = strpos($plugin_source, "require_once PGE_PATH . 'includes/class-pge-salla-token-manager.php';");
$service_require = strpos($plugin_source, "require_once PGE_PATH . 'includes/class-pge-salla-customer-groups-service.php';");
$handler_require = strpos($plugin_source, "require_once PGE_PATH . 'includes/class-salla-handler.php';");
check_true('bootstrap order is manager then service then handler',
    $manager_require !== false && $service_require !== false && $handler_require !== false
    && $manager_require < $service_require && $service_require < $handler_require);
check_true('service does not read token options', strpos($service_source, 'get_option(') === false);
check_true('service calls manager', strpos($service_source, 'PGE_Salla_Token_Manager::get_valid_access_token($merchant_id)') !== false);

if ($failures > 0) {
    echo "\n$failures test(s) failed.\n";
    exit(1);
}

echo "\n$checks/$checks PASS. All Salla Customer Groups service tests passed. No real HTTP request was possible.\n";
exit(0);
