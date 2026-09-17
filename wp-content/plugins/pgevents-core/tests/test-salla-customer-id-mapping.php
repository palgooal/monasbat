<?php
/**
 * Executable test for Salla customer ID mapping in the Catalog flow.
 * Run: php tests/test-salla-customer-id-mapping.php
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

$GLOBALS['salla_mapping_user'] = new WP_User(77);
$GLOBALS['salla_mapping_meta'] = [];
$GLOBALS['salla_mapping_updates'] = [];
$GLOBALS['salla_mapping_update_should_fail'] = false;
$GLOBALS['salla_mapping_activations'] = 0;

function get_user_by($field, $value)
{
    if ($field === 'email' && $value === 'customer@example.test') {
        return $GLOBALS['salla_mapping_user'];
    }
    return false;
}

function metadata_exists($meta_type, $object_id, $meta_key)
{
    return $meta_type === 'user'
        && array_key_exists($meta_key, $GLOBALS['salla_mapping_meta'][(int) $object_id] ?? []);
}

function get_user_meta($user_id, $meta_key, $single = false)
{
    return $GLOBALS['salla_mapping_meta'][(int) $user_id][$meta_key] ?? '';
}

function update_user_meta($user_id, $meta_key, $meta_value)
{
    $GLOBALS['salla_mapping_updates'][] = [
        'user_id' => (int) $user_id,
        'key'     => $meta_key,
        'value'   => $meta_value,
    ];

    if ($GLOBALS['salla_mapping_update_should_fail']) {
        return false;
    }

    $GLOBALS['salla_mapping_meta'][(int) $user_id][$meta_key] = $meta_value;
    return true;
}

class Mon_Events_Users
{
    public static function activate_catalog_tier($user_id, $plan_id, $tier_id, $order_id)
    {
        $GLOBALS['salla_mapping_activations']++;
        return true;
    }

    public static function deactivate_catalog_tier($user_id, $order_id)
    {
        return true;
    }
}

class PGE_Salla_Customer_Groups_Service
{
    public static $calls = 0;

    public function add_customer_to_group($merchant_id, $customer_id, $group_id)
    {
        self::$calls++;
    }
}

function reset_salla_mapping_state()
{
    $GLOBALS['salla_mapping_meta'] = [];
    $GLOBALS['salla_mapping_updates'] = [];
    $GLOBALS['salla_mapping_update_should_fail'] = false;
    $GLOBALS['salla_mapping_activations'] = 0;
    PGE_Salla_Customer_Groups_Service::$calls = 0;
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
$persist = $reflection->getMethod('persist_salla_customer_id_mapping');

reset_salla_mapping_state();
$result = $persist->invoke($handler, $GLOBALS['salla_mapping_user'], 0, 'ORDER-1');
check('customer_id=0 is skipped', $result, 'skipped');
check('customer_id=0 creates no meta', $GLOBALS['salla_mapping_meta'], []);
check('customer_id=0 performs no write', count($GLOBALS['salla_mapping_updates']), 0);

reset_salla_mapping_state();
$result = $persist->invoke($handler, $GLOBALS['salla_mapping_user'], 456, 'ORDER-2');
check('first mapping reports stored', $result, 'stored');
check('first mapping stores exact customer ID', get_user_meta(77, '_pge_salla_customer_id', true), 456);
check('first mapping writes once', count($GLOBALS['salla_mapping_updates']), 1);

reset_salla_mapping_state();
$GLOBALS['salla_mapping_meta'][77]['_pge_salla_customer_id'] = 456;
$result = $persist->invoke($handler, $GLOBALS['salla_mapping_user'], 456, 'ORDER-3');
check('same-ID replay reports unchanged', $result, 'unchanged');
check('same-ID replay keeps mapping', get_user_meta(77, '_pge_salla_customer_id', true), 456);
check('same-ID replay performs no write', count($GLOBALS['salla_mapping_updates']), 0);

reset_salla_mapping_state();
$GLOBALS['salla_mapping_meta'][77]['_pge_salla_customer_id'] = 111;
$result = $persist->invoke($handler, $GLOBALS['salla_mapping_user'], 456, 'ORDER-4');
check('conflicting ID reports conflict', $result, 'conflict');
check('conflicting ID does not overwrite old mapping', get_user_meta(77, '_pge_salla_customer_id', true), 111);
check('conflicting ID performs no write', count($GLOBALS['salla_mapping_updates']), 0);

reset_salla_mapping_state();
$GLOBALS['salla_mapping_update_should_fail'] = true;
$process = $reflection->getMethod('process_catalog_match');
$process->invoke(
    $handler,
    [
        'customer' => [
            'id'    => 456,
            'email' => 'customer@example.test',
        ],
    ],
    [
        'tier'             => ['id' => 20, 'plan_id' => 10],
        'plan'             => ['id' => 10],
        'product_id'       => 30,
        'sku'              => 'TEST-SKU',
        'validation_error' => '',
    ],
    'ORDER-5',
    'activate',
    123
);
check('failed meta write does not store mapping', get_user_meta(77, '_pge_salla_customer_id', true), '');
check('failed meta write does not block activation', $GLOBALS['salla_mapping_activations'], 1);
$mapping_updates = array_values(array_filter(
    $GLOBALS['salla_mapping_updates'],
    static fn($update) => ($update['key'] ?? '') === '_pge_salla_customer_id'
));
check('failed mapping write was attempted once', count($mapping_updates), 1);
check('Customer Groups service is never called', PGE_Salla_Customer_Groups_Service::$calls, 0);

$handler_source = file_get_contents(dirname(__DIR__) . '/includes/class-salla-handler.php');
check_true(
    'conflict logging uses safe diagnostic fields',
    strpos($handler_source, "'salla_customer_id_mapping_conflict'") !== false
        && strpos($handler_source, "'existing_salla_customer_id'") !== false
        && strpos($handler_source, "'incoming_salla_customer_id'") !== false
);
check_true(
    'failure logging is present',
    strpos($handler_source, "'salla_customer_id_mapping_failed'") !== false
        && strpos($handler_source, "'update_user_meta_failed'") !== false
);
check_true(
    'mapping helper does not call Customer Groups service',
    strpos(
        substr(
            $handler_source,
            strpos($handler_source, 'private function persist_salla_customer_id_mapping'),
            strpos($handler_source, 'private function add_plus_customer_to_salla_group')
                - strpos($handler_source, 'private function persist_salla_customer_id_mapping')
        ),
        'PGE_Salla_Customer_Groups_Service'
    ) === false
);

echo "\n============================================\n";
echo "Total: $total | Passed: $passed | Failed: " . ($total - $passed) . "\n";

exit($total === $passed ? 0 : 1);
