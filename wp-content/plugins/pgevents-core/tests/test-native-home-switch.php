<?php
/** Isolated policy test. Run: php tests/test-native-home-switch.php
 * No WordPress bootstrap, database, HTTP or mail.
 */
$theme = dirname(__DIR__, 3) . '/themes/pgevents-pro/';
$source = file_get_contents($theme . 'functions.php');
$start = strpos($source, "if (!function_exists('pge_is_native_home_preview'))");
$end = strpos($source, 'function pge_render_native_home_preview_header()', $start);
function is_admin() { return $GLOBALS['admin']; }
function is_front_page() { return $GLOBALS['front']; }
function is_feed() { return false; }
function is_404() { return false; }
function wp_get_environment_type() { return $GLOBALS['environment']; }
function wp_doing_ajax() { return $GLOBALS['ajax']; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can($cap) { return $cap === 'manage_options' && $GLOBALS['authorized']; }
function sanitize_text_field($s) { return trim($s); }
function wp_unslash($s) { return $s; }
function add_action(...$args) {}
function add_filter(...$args) { $GLOBALS['filters'][] = $args; }
function nocache_headers() { $GLOBALS['nocache'] = true; }
define('ABSPATH', __DIR__);
function get_option($key, $default = false) { return $key === 'pge_native_home_production_preview' ? ($GLOBALS['preview_option'] ?? $default) : $default; }
$preview_option = isset($argv[4]) ? json_decode($argv[4]) : '0';
require $theme . 'inc/native-home-preview-admin.php';
function wp_die($message, $title = '', $args = []) { throw new RuntimeException('denied:' . $args['response']); }
function check_admin_referer($action) { if (!$GLOBALS['valid_nonce']) { throw new RuntimeException('nonce'); } }
function update_option($key, $value, $autoload) { $GLOBALS['writes'][] = [$key, $value, $autoload]; }
function admin_url($path) { return '/wp-admin/' . $path; }
function wp_safe_redirect($url) { throw new RuntimeException('redirect'); }
if (isset($argv[1]) && $argv[1] !== 'absent') {
    define('PGE_NATIVE_HOME_PUBLIC', json_decode($argv[1]));
}
if (isset($argv[2]) && $argv[2] !== 'absent') {
    define('PGE_NATIVE_HOME_PRODUCTION_PREVIEW', json_decode($argv[2]));
}
if (($argv[3] ?? '') === 'rest') { define('REST_REQUEST', true); }
$expected_enabled = isset($argv[2]) && $argv[2] !== 'absent'
    ? $argv[2] === 'true'
    : in_array($preview_option, [1, '1'], true);
if (pge_native_home_production_preview_enabled() !== $expected_enabled) { throw new RuntimeException('precedence'); }
foreach ([
    ['GET', true, true, '1', 'denied:405', null],
    ['POST', false, true, '1', 'denied:403', null],
    ['POST', true, false, '1', 'nonce', null],
    ['POST', true, true, 'yes', 'denied:400', null],
    ['POST', true, true, ['1'], 'denied:400', null],
    ['POST', true, true, '1', 'redirect', '1'],
    ['POST', true, true, null, 'redirect', '0'],
] as [$method, $authorized, $valid_nonce, $value, $outcome, $saved]) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $value === null ? [] : ['enabled' => $value];
    $writes = [];
    if (defined('PGE_NATIVE_HOME_PRODUCTION_PREVIEW') && $method === 'POST' && $authorized && $valid_nonce) {
        $outcome = 'denied:403'; $saved = null;
    }
    try { pge_save_native_preview(); throw new RuntimeException('missing redirect'); }
    catch (RuntimeException $e) { if ($e->getMessage() !== $outcome) { throw $e; } }
    $expected_writes = $saved === null ? [] : [['pge_native_home_production_preview', $saved, false]];
    if ($writes !== $expected_writes) { throw new RuntimeException('save/autoload/unauthorized write'); }
}
$ajax = false; $logged_in = true;
// Capture header calls in CLI; no HTTP server or application bootstrap.
function capture_header($value, $replace) { $GLOBALS['headers'][] = [$value, $replace]; }
eval(str_replace('header(', 'capture_header(', substr($source, $start, $end - $start)));
$public = defined('PGE_NATIVE_HOME_PUBLIC') && PGE_NATIVE_HOME_PUBLIC === true;
$production = pge_native_home_production_preview_enabled()
    && !(defined('REST_REQUEST') && REST_REQUEST);
$cases = [
    ['default/public/rollback', 'production', false, '', false, true, $public],
    ['authorized preview', 'local', true, '1', false, true, true],
    ['unauthorized preview', 'local', false, '1', false, true, $public],
    ['production preview gated', 'production', true, '1', false, true, $public || $production],
    ['production insufficient capability', 'production', false, '1', false, true, $public],
    ['production wrong query', 'production', true, '0', false, true, $public],
    ['production padded query', 'production', true, ' 1 ', false, true, $public],
    ['production array query', 'production', true, ['1'], false, true, $public],
    ['development preview', 'development', true, '1', false, true, true],
    ['internal page', 'local', true, '1', false, false, false],
    ['admin', 'local', true, '1', true, true, false],
];
foreach ($cases as [$label, $environment, $authorized, $query, $admin, $front, $expected]) {
    $_GET = ['pge_native_home_preview' => $query];
    if (pge_should_render_native_home() !== $expected) { throw new RuntimeException($label); }
    $filters = []; $headers = []; $nocache = false;
    pge_protect_native_home_preview();
    if ($nocache !== pge_is_native_home_preview() || (bool)$filters !== $nocache) {
        throw new RuntimeException('preview protections: ' . $label);
    }
    $expected_headers = $nocache ? [
        ['Cache-Control: private, no-store, no-cache, must-revalidate', true],
        ['X-Robots-Tag: noindex, nofollow', true],
    ] : [];
    if ($headers !== $expected_headers) { throw new RuntimeException('headers: ' . $label); }
}
$environment = 'production'; $admin = false; $front = true; $authorized = true;
$_GET = ['pge_native_home_preview' => '1'];
$logged_in = false;
if (pge_is_native_home_preview()) { throw new RuntimeException('anonymous preview'); }
$logged_in = true; $ajax = true;
if (pge_is_native_home_preview()) { throw new RuntimeException('AJAX preview'); }
$robots = pge_native_home_preview_robots(['index' => true, 'follow' => true]);
if ($robots !== ['noindex' => true, 'nofollow' => true]) { throw new RuntimeException('robots'); }
foreach (['front-page.php', 'header.php'] as $file) {
    if (!str_contains(file_get_contents($theme . $file), 'pge_should_render_native_home()')) {
        throw new RuntimeException('render gate: ' . $file);
    }
}
if (!str_contains($source, 'if (pge_should_render_native_home()) {') ||
    !str_contains($source, 'if (!pge_should_render_native_home()) {')) {
    throw new RuntimeException('asset/HFE gate');
}
echo 'PASS switch=' . ($argv[1] ?? 'absent') . ': policy matrix, preview protections, shared call sites' . PHP_EOL;
