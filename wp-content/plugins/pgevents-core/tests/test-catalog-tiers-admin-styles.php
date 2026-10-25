<?php
/** Run: php tests/test-catalog-tiers-admin-styles.php */
$source = file_get_contents(__DIR__ . '/../pgevents-core.php');
$css = file_get_contents(__DIR__ . '/../assets/css/catalog-tiers-admin.css');
$checks = 0;
function verify_tiers_styles($condition, $label) {
    $GLOBALS['checks']++;
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

verify_tiers_styles(strpos($source, "function pge_catalog_tiers_admin_styles") !== false, 'tiers loader exists');
verify_tiers_styles(strpos($source, "\$GLOBALS['pge_catalog_tiers_page_hook'] = add_submenu_page(") !== false, 'submenu return hook is captured');
verify_tiers_styles(strpos($source, "'assets/css/catalog-tiers-admin.css'") !== false, 'tiers stylesheet path');
verify_tiers_styles(strpos($source, "'assets/js/catalog-tiers-admin.js'") !== false, 'tiers tab script path');
verify_tiers_styles(strpos($source, 'filemtime($stylesheet_path)') !== false, 'stylesheet version uses file mtime');
verify_tiers_styles(strpos($source, 'pge-catalog-plans-admin') < strpos($source, 'function pge_catalog_tiers_admin_styles'), 'plans loader remains separate');
verify_tiers_styles(strpos($css, '.pge-catalog-tiers') !== false, 'all tiers styles start scoped');
verify_tiers_styles(!preg_match('/^\s*(?:body|html|\.wrap)\b/m', $css), 'no global admin selectors');
verify_tiers_styles(strpos($css, '@media screen and (max-width: 782px)') !== false, 'mobile layout');
verify_tiers_styles(strpos($css, 'data-label') !== false, 'mobile table labels');
verify_tiers_styles(strpos($css, 'min-height: 44px') !== false, 'touch targets');
verify_tiers_styles(strpos($css, '[dir="ltr"]') !== false, 'technical LTR isolation');

$tokens = token_get_all($source);
$callback = '';
for ($i = 0; $i < count($tokens); $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $j = $i + 1;
    while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
    if (!is_array($tokens[$j]) || $tokens[$j][1] !== 'pge_catalog_tiers_admin_styles') continue;
    $depth = 0; $opened = false;
    for (; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        $callback .= is_array($token) ? $token[1] : $token;
        if ($token === '{') { $depth++; $opened = true; }
        if ($token === '}' && --$depth === 0 && $opened) break;
    }
    break;
}
verify_tiers_styles($callback !== '', 'live stylesheet callback extracted');
define('PGE_URL', 'https://example.test/wp-content/plugins/pgevents-core/');
define('PGE_PATH', dirname(__DIR__) . '/');
$GLOBALS['enqueued_tiers_styles'] = [];
$GLOBALS['enqueued_tiers_scripts'] = [];
function wp_enqueue_style($handle, $src, $deps = [], $version = false) {
    $GLOBALS['enqueued_tiers_styles'][] = [$handle, $src, $deps, $version];
}
function wp_enqueue_script($handle, $src, $deps = [], $version = false, $in_footer = false) {
    $GLOBALS['enqueued_tiers_scripts'][] = [$handle, $src, $deps, $version, $in_footer];
}
eval($callback);
$GLOBALS['pge_catalog_tiers_page_hook'] = 'changed-parent_page_pge-catalog-tiers';
pge_catalog_tiers_admin_styles('changed-parent_page_pge-catalog-tiers');
verify_tiers_styles(count($GLOBALS['enqueued_tiers_styles']) === 1, 'actual callback enqueues target hook');
verify_tiers_styles($GLOBALS['enqueued_tiers_styles'][0][1] === PGE_URL . 'assets/css/catalog-tiers-admin.css', 'actual callback uses PGE_URL');
verify_tiers_styles($GLOBALS['enqueued_tiers_styles'][0][3] === (string) filemtime(PGE_PATH . 'assets/css/catalog-tiers-admin.css'), 'actual callback cache bust version');
verify_tiers_styles(count($GLOBALS['enqueued_tiers_scripts']) === 1, 'actual callback enqueues tab script on target hook');
verify_tiers_styles($GLOBALS['enqueued_tiers_scripts'][0][1] === PGE_URL . 'assets/js/catalog-tiers-admin.js', 'actual callback script uses PGE_URL');
verify_tiers_styles($GLOBALS['enqueued_tiers_scripts'][0][3] === (string) filemtime(PGE_PATH . 'assets/js/catalog-tiers-admin.js'), 'actual callback script cache bust version');
verify_tiers_styles($GLOBALS['enqueued_tiers_scripts'][0][4] === true, 'actual callback loads tab script in footer');
foreach (['toplevel_page_pge-catalog-plans', 'dashboard_page_home', 'pgevents_page_pge-catalog-tiers'] as $other_hook) {
    pge_catalog_tiers_admin_styles($other_hook);
}
verify_tiers_styles(count($GLOBALS['enqueued_tiers_styles']) === 1, 'actual callback excludes plans and other screens');
verify_tiers_styles(count($GLOBALS['enqueued_tiers_scripts']) === 1, 'actual callback excludes tab script from plans and other screens');

echo "PASS: $checks/$checks stylesheet scope and responsive assertions\n";
