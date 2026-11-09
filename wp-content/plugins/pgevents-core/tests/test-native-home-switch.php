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
function wp_get_environment_type() { return $GLOBALS['environment']; }
function current_user_can($cap) { return $cap === 'manage_options' && $GLOBALS['authorized']; }
function sanitize_text_field($s) { return trim($s); }
function wp_unslash($s) { return $s; }
function add_action(...$args) {}
function add_filter(...$args) { $GLOBALS['filters'][] = $args; }
function nocache_headers() { $GLOBALS['nocache'] = true; }
if (isset($argv[1]) && $argv[1] !== 'absent') {
    define('PGE_NATIVE_HOME_PUBLIC', json_decode($argv[1]));
}
eval(substr($source, $start, $end - $start));
$public = defined('PGE_NATIVE_HOME_PUBLIC') && PGE_NATIVE_HOME_PUBLIC === true;
$cases = [
    ['default/public/rollback', 'production', false, '', false, true, $public],
    ['authorized preview', 'local', true, '1', false, true, true],
    ['unauthorized preview', 'local', false, '1', false, true, $public],
    ['production preview denied', 'production', true, '1', false, true, $public],
    ['development preview', 'development', true, '1', false, true, true],
    ['internal page', 'local', true, '1', false, false, false],
    ['admin', 'local', true, '1', true, true, false],
];
foreach ($cases as [$label, $environment, $authorized, $query, $admin, $front, $expected]) {
    $_GET = ['pge_native_home_preview' => $query];
    if (pge_should_render_native_home() !== $expected) { throw new RuntimeException($label); }
    $filters = []; $nocache = false;
    pge_protect_native_home_preview();
    if ($nocache !== pge_is_native_home_preview() || (bool)$filters !== $nocache) {
        throw new RuntimeException('preview protections: ' . $label);
    }
}
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
