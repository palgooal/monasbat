<?php
/**
 * Isolated regression test for the retired legacy package-save path.
 *
 * Run: php tests/test-packages-settings-admin-security.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['pge_packages_settings_test_options'] = [
    'mon_packages_settings' => [
        'plan_1' => ['name' => 'Legacy plan'],
    ],
];
$GLOBALS['pge_packages_settings_test_updates'] = [];

function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($name, $default = false) { return $GLOBALS['pge_packages_settings_test_options'][$name] ?? $default; }
function update_option($name, $value) { $GLOBALS['pge_packages_settings_test_updates'][] = [$name, $value]; return true; }
function current_time($type) { return '2030-01-01 00:00:00'; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function sanitize_email($value) { return sanitize_text_field($value); }
function check_admin_referer($action) { return true; }
function wp_nonce_field($action) { echo '<input type="hidden" data-nonce-action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'; }
function wp_kses_post($value) { return $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return (string) $value; }
function get_user_by(...$args) { return false; }
function get_current_user_id() { return 1; }

require_once dirname(__DIR__) . '/includes/admin-mods.php';

$checks = 0;
$failures = 0;
function packages_settings_security_check($condition, $label)
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "PASS $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n";
}

$_POST = [
    'mon_save_plans' => '1',
    'plans' => ['plan_1' => ['name' => 'attempted overwrite']],
];

$controller = new PGE_Admin_Controller();
ob_start();
$controller->render_packages_admin_page();
$html = ob_get_clean();

packages_settings_security_check(
    $GLOBALS['pge_packages_settings_test_updates'] === [],
    'mon_save_plans POST does not write any option'
);
packages_settings_security_check(
    get_option('mon_packages_settings')['plan_1']['name'] === 'Legacy plan',
    'legacy option test data remains unchanged'
);
packages_settings_security_check(
    strpos($html, 'mon_save_plans') === false && strpos($html, 'name="plans[') === false,
    'legacy package form is absent from rendered callback output'
);

foreach ([
    'mon_save_salla_secret',
    'mon_salla_secret_nonce',
    'mon_save_salla_credentials',
    'mon_salla_credentials_nonce',
    'mon_assign_package',
    'mon_assign_package_nonce',
] as $required) {
    packages_settings_security_check(strpos($html, $required) !== false, "retained contract present: $required");
}

packages_settings_security_check(
    strpos($html, 'salla_secret_input') !== false
        && strpos($html, 'salla_client_id_input') !== false
        && strpos($html, 'salla_client_secret_input') !== false,
    'secret visibility controls remain rendered'
);

echo "Result: " . ($checks - $failures) . "/$checks passed\n";
exit($failures === 0 ? 0 : 1);
