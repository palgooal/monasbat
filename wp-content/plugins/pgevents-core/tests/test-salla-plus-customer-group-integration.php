<?php
/**
 * Executable integration test for post-activation Plus customer-group sync.
 * No real HTTP transport is loaded or available in this test.
 * Run: php tests/test-salla-plus-customer-group-integration.php
 */

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

class WP_User
{
    public $ID;

    public function __construct($id)
    {
        $this->ID = $id;
    }
}

class WP_Error
{
    private $code;

    public function __construct($code = '')
    {
        $this->code = $code;
    }

    public function get_error_code() { return $this->code; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function get_option($name, $default = false) { return $default; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
function is_email($value) { return filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false; }
function wp_json_encode($value) { return json_encode($value); }
function is_serialized($value) { return false; }

$GLOBALS['plus_test_user'] = new WP_User(77);
$GLOBALS['plus_test_meta'] = [];
$GLOBALS['plus_test_sequence'] = [];
$GLOBALS['plus_test_activation_result'] = true;
$GLOBALS['plus_test_deactivations'] = 0;
$GLOBALS['plus_test_sync_result'] = null;
$GLOBALS['plus_test_sync_calls'] = [];
$GLOBALS['plus_test_sync_rows'] = [];
$GLOBALS['plus_test_worker_schedules'] = 0;
$GLOBALS['plus_test_email_calls'] = [];

function get_user_by($field, $value)
{
    if ($field === 'email' && $value === 'customer@example.test') {
        return $GLOBALS['plus_test_user'];
    }
    return false;
}

function metadata_exists($meta_type, $object_id, $meta_key)
{
    return $meta_type === 'user'
        && array_key_exists($meta_key, $GLOBALS['plus_test_meta'][(int) $object_id] ?? []);
}

function get_user_meta($user_id, $meta_key, $single = false)
{
    return $GLOBALS['plus_test_meta'][(int) $user_id][$meta_key] ?? '';
}

function update_user_meta($user_id, $meta_key, $meta_value)
{
    $GLOBALS['plus_test_meta'][(int) $user_id][$meta_key] = $meta_value;
    return true;
}

class Mon_Events_Users
{
    public static function activate_catalog_tier($user_id, $plan_id, $tier_id, $order_id)
    {
        $GLOBALS['plus_test_sequence'][] = 'activate';
        return $GLOBALS['plus_test_activation_result'];
    }

    public static function deactivate_catalog_tier($user_id, $order_id)
    {
        $GLOBALS['plus_test_deactivations']++;
        $GLOBALS['plus_test_sequence'][] = 'deactivate';
        return true;
    }
}

class PGE_Salla_Membership_Sync_Store
{
    public static function request_member($merchant_id, $customer_id, $group_id)
    {
        $GLOBALS['plus_test_sequence'][] = 'sync';
        $GLOBALS['plus_test_sync_calls'][] = [$merchant_id, $customer_id, $group_id];
        if (is_array($GLOBALS['plus_test_sync_result'])) return $GLOBALS['plus_test_sync_result'];
        $key = $merchant_id . ':' . $customer_id . ':' . $group_id;
        if (isset($GLOBALS['plus_test_sync_rows'][$key])) {
            return ['result' => 'pending', 'id' => $GLOBALS['plus_test_sync_rows'][$key]['id']];
        }
        $GLOBALS['plus_test_sync_rows'][$key] = ['id' => count($GLOBALS['plus_test_sync_rows']) + 1, 'merchant_id' => $merchant_id, 'customer_id' => $customer_id, 'group_id' => $group_id];
        return ['result' => 'created', 'id' => $GLOBALS['plus_test_sync_rows'][$key]['id']];
    }
}

class PGE_Salla_Membership_Sync_Worker
{
    public static function schedule_worker($delay = 1) { $GLOBALS['plus_test_worker_schedules']++; return true; }
    public static function ensure_recovery_scheduled() { return true; }
}

class PGE_Package_Activation_Email
{
    public static function send($user_id, $plan_id, $tier_id, $order_id)
    {
        $GLOBALS['plus_test_sequence'][] = 'email';
        $GLOBALS['plus_test_email_calls'][] = [$user_id, $plan_id, $tier_id, $order_id];
        return true;
    }
}

function reset_plus_test_state()
{
    $GLOBALS['plus_test_meta'] = [];
    $GLOBALS['plus_test_sequence'] = [];
    $GLOBALS['plus_test_activation_result'] = true;
    $GLOBALS['plus_test_deactivations'] = 0;
    $GLOBALS['plus_test_sync_result'] = null;
    $GLOBALS['plus_test_sync_calls'] = [];
    $GLOBALS['plus_test_sync_rows'] = [];
    $GLOBALS['plus_test_worker_schedules'] = 0;
    $GLOBALS['plus_test_email_calls'] = [];
}

$total = 0;
$passed = 0;

function check($label, $actual, $expected)
{
    global $total, $passed;
    $total++;
    if ($actual === $expected) {
        $passed++;
        echo "PASS  $label\n";
        return;
    }

    echo "FAIL  $label (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

require_once dirname(__DIR__) . '/includes/class-salla-handler.php';

$reflection = new ReflectionClass('Mon_Salla_Handler');
$handler = $reflection->newInstanceWithoutConstructor();
$process = $reflection->getMethod('process_catalog_match');

function run_catalog_flow($handler, $process, $plan_key, $action = 'activate', $merchant_id = 123, $customer_id = 456, $order_id = 'ORDER-1')
{
    return $process->invoke(
        $handler,
        [
            'customer' => [
                'id'    => $customer_id,
                'email' => 'customer@example.test',
            ],
        ],
        [
            'tier'             => ['id' => 20, 'plan_id' => 10],
            'plan'             => ['id' => 10, 'plan_key' => $plan_key],
            'product_id'       => 30,
            'sku'              => 'TEST-SKU',
            'validation_error' => '',
        ],
        $order_id,
        $action,
        $merchant_id
    );
}

reset_plus_test_state();
run_catalog_flow($handler, $process, 'halwa_classic');
check('Classic activation creates no Plus sync work', count($GLOBALS['plus_test_sync_calls']), 0);
check('Classic activation still succeeds internally', $GLOBALS['plus_test_sequence'], ['activate', 'email']);

reset_plus_test_state();
run_catalog_flow($handler, $process, 'halwa_plus');
check('Plus activation persists desired membership once', count($GLOBALS['plus_test_sync_calls']), 1);
check('Plus sync receives merchant, customer, and group IDs', $GLOBALS['plus_test_sync_calls'][0], [123, 456, 225189340]);
check('Plus sync runs after internal activation', $GLOBALS['plus_test_sequence'], ['activate', 'sync', 'email']);
check('Plus activation creates one durable identity row', count($GLOBALS['plus_test_sync_rows']), 1);

reset_plus_test_state();
$GLOBALS['plus_test_activation_result'] = new WP_Error('activation_failed');
run_catalog_flow($handler, $process, 'halwa_plus');
check('failed internal Plus activation creates no sync work', count($GLOBALS['plus_test_sync_calls']), 0);
check('failed internal Plus activation does not send email', count($GLOBALS['plus_test_email_calls']), 0);

reset_plus_test_state();
run_catalog_flow($handler, $process, 'halwa_plus', 'activate', 123, 0);
check('Plus without customer ID creates no sync work', count($GLOBALS['plus_test_sync_calls']), 0);
check('Plus without customer ID keeps entitlement successful', $GLOBALS['plus_test_sequence'], ['activate', 'email']);

reset_plus_test_state();
run_catalog_flow($handler, $process, 'halwa_plus', 'activate', 0, 456);
check('Plus without merchant ID creates no sync work', count($GLOBALS['plus_test_sync_calls']), 0);
check('Plus without merchant ID keeps entitlement successful', $GLOBALS['plus_test_sequence'], ['activate', 'email']);

reset_plus_test_state();
$GLOBALS['plus_test_sync_result'] = ['result' => 'error', 'reason' => 'simulated_persistence_failure'];
run_catalog_flow($handler, $process, 'halwa_plus');
check('sync persistence failure still follows activation then email', $GLOBALS['plus_test_sequence'], ['activate', 'sync', 'email']);
check('sync persistence failure does not prevent activation email', count($GLOBALS['plus_test_email_calls']), 1);

reset_plus_test_state();
run_catalog_flow($handler, $process, 'halwa_plus', 'deactivate');
check('Plus deactivation creates no sync work', count($GLOBALS['plus_test_sync_calls']), 0);
check('Plus deactivation uses only internal deactivation', $GLOBALS['plus_test_sequence'], ['deactivate']);

reset_plus_test_state();
run_catalog_flow($handler, $process, 'halwa_plus', 'activate', 123, 456, 'ORDER-REPLAY');
run_catalog_flow($handler, $process, 'halwa_plus', 'activate', 123, 456, 'ORDER-REPLAY');
check('replay remains delegated to existing activation semantics', $GLOBALS['plus_test_sequence'], ['activate', 'sync', 'email', 'activate', 'sync', 'email']);
check('replayed Plus activation keeps one durable identity row', count($GLOBALS['plus_test_sync_rows']), 1);

$handler_source = file_get_contents(dirname(__DIR__) . '/includes/class-salla-handler.php');
$sync_start = strpos($handler_source, 'private function add_plus_customer_to_salla_group');
$sync_end = strpos($handler_source, 'private function extract_customer_mobile', $sync_start);
$sync_source = substr($handler_source, $sync_start, $sync_end - $sync_start);
check_true(
    'invalid-ID and service failures use safe logging events',
    strpos($sync_source, "'salla_plus_group_sync_skipped'") !== false
        && strpos($sync_source, "'salla_plus_group_sync_failed'") !== false
        && strpos($sync_source, "'merchant_id'") !== false
        && strpos($sync_source, "'salla_customer_id'") !== false
        && strpos($sync_source, "'group_id'") !== false
        && strpos($sync_source, "'error_code'") !== false
);
check_true('sync logging contains no email', strpos($sync_source, 'email') === false);
check_true('sync logging contains no mobile', strpos($sync_source, 'mobile') === false);
check_true('sync logging contains no access token', strpos($sync_source, 'access_token') === false);
check_true('sync logging contains no raw response body', strpos($sync_source, 'response_body') === false);
check_true('Plus sync delegates durable idempotency to the sync store', strpos($sync_source, 'PGE_Salla_Membership_Sync_Store::request_member') !== false);
check_true('Plus webhook path performs no direct Salla HTTP mutation', strpos($sync_source, 'PGE_Salla_Customer_Groups_Service') === false && strpos($sync_source, 'wp_remote_') === false);

echo "\n============================================\n";
echo "Total: $total | Passed: $passed | Failed: " . ($total - $passed) . "\n";

exit($total === $passed ? 0 : 1);
