<?php
/**
 * Run: php tests/test-catalog-plans-admin-views.php
 * Executes the live plans callback extracted with PHP tokens (not a copied
 * implementation). WordPress and Catalog are test doubles; no WP bootstrap,
 * database, filesystem writes, hooks or migrations. Tests the UI contract only.
 */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message) { throw new RuntimeException($message); });
$source = file_get_contents(__DIR__ . '/../pgevents-core.php');
$tokens = token_get_all($source);
$callback = '';
for ($i = 0; $i < count($tokens); $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $j = $i + 1;
    while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
    if (!is_array($tokens[$j]) || $tokens[$j][1] !== 'pge_render_catalog_plans_page') continue;
    $depth = 0;
    $opened = false;
    for (; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        $callback .= is_array($token) ? $token[1] : $token;
        if ($token === '{') { $depth++; $opened = true; }
        if ($token === '}' && --$depth === 0 && $opened) break;
    }
    break;
}
if ($callback === '') throw new RuntimeException('Plans callback missing');
eval($callback);

class ViewDenied extends RuntimeException {}
function current_user_can($cap) { return $cap === 'manage_options' && $GLOBALS['allowed']; }
function wp_die($message) { throw new ViewDenied($message); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function absint($value) { return abs((int) $value); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function esc_textarea($value) { return esc_html($value); }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function selected($a, $b) { if ($a === $b) echo ' selected="selected"'; }
function admin_url($path) { return '/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args); }
function wp_nonce_field($action, $name) { echo '<input name="' . $name . '" value="' . $action . '">'; }
function check_admin_referer($action, $name) {
    $GLOBALS['trace'][] = 'nonce:' . $action;
    if (($_POST[$name] ?? '') !== $action) throw new ViewDenied('nonce');
}
function submit_button($label, $type, $name) { echo '<button name="' . $name . '">' . $label . '</button>'; }
class PGE_Catalog {
    public static $row = ['id' => 1, 'plan_key' => 'classic', 'name' => 'باقة اختبار', 'plan_type' => 'personal', 'status' => 'active', 'sort_order' => '0', 'features' => '["ميزة"]'];
    public static $fail = false;
    public static $empty = false;
    public static $rows = null;
    public static $writes = [];
    public static function get_plan($id) { return $id === 1 ? self::$row : null; }
    public static function get_plans() { return self::$empty ? [] : (self::$rows ?? [self::$row]); }
    public static function create_plan($data) { return self::write('create', $data); }
    public static function update_plan($id, $data) { return self::write('update', $data, $id); }
    public static function delete_plan($id) { self::write('delete', [], $id); return !self::$fail; }
    private static function write($operation, $data, $id = null) {
        $GLOBALS['trace'][] = $operation;
        self::$writes[] = [$operation, $id, $data];
        return self::$fail ? null : array_merge(self::$row, $data, ['features' => json_encode($data['features'] ?? [])]);
    }
}
$checks = 0;
function verify($condition, $label) {
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
}
function render_view($get = [], $post = [], $method = 'GET', $allowed = true) {
    $_GET = $get; $_POST = $post; $_SERVER['REQUEST_METHOD'] = $method;
    $GLOBALS['allowed'] = $allowed; $GLOBALS['trace'] = []; PGE_Catalog::$writes = [];
    ob_start();
    try { pge_render_catalog_plans_page(); return [ob_get_contents(), false]; }
    catch (ViewDenied $e) { return [ob_get_contents(), true]; }
    finally { ob_end_clean(); }
}
function expect_view($html, $view) {
    foreach (['add' => 'submit_create_plan', 'edit' => 'submit_update_plan', 'delete' => 'submit_delete_plan', 'list' => 'الباقات الحالية'] as $key => $marker) {
        verify((strpos($html, $marker) !== false) === ($view === $key), 'exclusive view ' . $view . '/' . $key);
    }
}
foreach (['list', 'add', 'edit', 'delete'] as $view) {
    [$html, $denied] = render_view($view === 'list' ? [] : ['action' => $view, 'plan_id' => '1']);
    verify(!$denied, 'GET allowed'); expect_view($html, $view);
    verify(PGE_Catalog::$writes === [], 'GET never writes');
    verify(strpos($html, 'page-title-action') !== false && strpos($html, 'action=add') !== false, 'add link');
    [$html, $denied] = render_view(['action' => $view, 'plan_id' => '1'], [], 'GET', false);
    verify($denied && $html === '' && PGE_Catalog::$writes === [], 'permission before rendering');
}
foreach (['unknown', ['edit'], '<script>'] as $action) {
    [$html] = render_view(['action' => $action]); expect_view($html, 'list');
}
foreach (['edit', 'delete'] as $action) {
    foreach ([null, '999', '0', '-1', '1x', ['1']] as $id) {
        [$html] = render_view(['action' => $action, 'plan_id' => $id]);
        expect_view($html, 'list');
        verify(strpos($html, 'notice-error') !== false && PGE_Catalog::$writes === [], 'invalid record safe');
    }
}
PGE_Catalog::$empty = true;
[$html] = render_view(); verify(strpos($html, 'لا توجد باقات') !== false, 'empty list');
PGE_Catalog::$empty = false;
$fields = ['plan_key' => 'new-key', 'name' => 'اسم محفوظ', 'plan_type' => 'business', 'status' => 'inactive', 'sort_order' => '7', 'features' => "أولى\nثانية"];
$contracts = [
    'create' => ['add', 'pge_catalog_plan_nonce', 'pge_create_catalog_plan'],
    'update' => ['edit', 'pge_catalog_update_nonce', 'pge_update_catalog_plan'],
    'delete' => ['delete', 'pge_catalog_delete_nonce', 'pge_delete_catalog_plan'],
];
foreach ($contracts as $operation => [$view, $nonce, $nonce_action]) {
    [$form] = render_view(['action' => $view, 'plan_id' => '1']);
    foreach (array_merge(['pge_catalog_action', 'submit_' . $operation . '_plan', $nonce], $operation === 'delete' ? ['plan_id'] : array_keys($fields)) as $name) {
        verify(strpos($form, 'name="' . $name . '"') !== false, 'field contract ' . $name);
    }
    verify(strpos($form, 'value="' . $operation . '_plan"') !== false, 'action contract');
    $post = array_merge($fields, ['plan_id' => '1', 'pge_catalog_action' => $operation . '_plan', 'submit_' . $operation . '_plan' => '1', $nonce => $nonce_action]);
    foreach ([false, true] as $fail) {
        PGE_Catalog::$fail = $fail;
        // Deliberately conflicting GET must not replace the POST result view.
        [$html, $denied] = render_view(['action' => 'delete', 'plan_id' => '999'], $post, 'POST');
        expect_view($html, $operation === 'delete' && !$fail ? 'list' : $view);
        verify(!$denied && count(PGE_Catalog::$writes) === 1, 'one service call');
        verify(PGE_Catalog::$writes[0][1] === ($operation === 'create' ? null : 1), 'service target unchanged');
        verify($GLOBALS['trace'] === ['nonce:' . $nonce_action, $operation], 'nonce before write');
        verify(strpos($html, $fail ? 'notice-error' : 'notice-success') !== false, 'result notice');
        if ($operation !== 'delete') {
            $payload = $fields; $payload['features'] = explode("\n", $payload['features']);
            verify(PGE_Catalog::$writes[0][2] === $payload, 'unchanged service payload');
            if ($fail) foreach (['new-key', 'اسم محفوظ', 'business', 'inactive', '7', "أولى\nثانية"] as $value) {
                verify(strpos($html, $value) !== false, 'failed input retained');
            }
        }
    }
    PGE_Catalog::$fail = false;
    if ($operation !== 'create') {
        $missing = $post; $missing['plan_id'] = '999';
        [$html] = render_view(['action' => 'edit', 'plan_id' => '1'], $missing, 'POST');
        expect_view($html, 'list');
        verify(strpos($html, 'notice-error') !== false && PGE_Catalog::$writes === [], 'missing POST target safe');
    }
    foreach ([null, 'wrong'] as $invalid_nonce) {
        $invalid = $post; $invalid[$nonce] = $invalid_nonce;
        [, $denied] = render_view([], $invalid, 'POST');
        verify($denied && PGE_Catalog::$writes === [], 'invalid nonce blocks write');
    }
    [, $denied] = render_view([], $post, 'POST', false);
    verify($denied && PGE_Catalog::$writes === [] && $GLOBALS['trace'] === [], 'POST permission blocks write');
    render_view(['action' => $view, 'plan_id' => '1'], $post, 'GET');
    verify(PGE_Catalog::$writes === [] && $GLOBALS['trace'] === [], 'POST-shaped GET never writes');
    unset($post['submit_' . $operation . '_plan']);
    render_view([], $post, 'POST');
    verify(PGE_Catalog::$writes === [], 'submit guard unchanged');
}
// UI-02: statistics use the same complete result set as the rendered rows.
$ui01_checks = $checks;
foreach ([[], [PGE_Catalog::$row], [
    PGE_Catalog::$row,
    array_merge(PGE_Catalog::$row, ['id' => 2, 'plan_key' => 'business-long-key', 'name' => 'باقة أعمال', 'status' => 'inactive']),
    array_merge(PGE_Catalog::$row, ['id' => 3, 'plan_key' => 'plus', 'name' => 'باقة بلس', 'status' => 'active']),
]] as $rows) {
    PGE_Catalog::$rows = $rows;
    [$html] = render_view();
    $expected_active = count($rows) === 3 ? 2 : count($rows);
    verify(strpos($html, 'data-stat="total">' . count($rows) . '</dd>') !== false, 'total from complete dataset');
    verify(strpos($html, 'data-stat="active">' . $expected_active . '</dd>') !== false, 'active excludes inactive');
    verify(substr_count($html, 'scope="row" class="pge-plan-identity"') === count($rows), 'every plan renders once');
    foreach ($rows as $row) {
        verify(strpos($html, '<strong>' . esc_html($row['name']) . '</strong>') !== false, 'plan name visible');
        verify(strpos($html, '<bdi dir="ltr"><code>' . $row['plan_key'] . '</code></bdi>') !== false, 'key isolated LTR');
        verify(strpos($html, 'plan_id=' . $row['id']) !== false, 'row action keeps target');
        verify(strpos($html, 'المعرّف: ' . $row['id']) !== false, 'ID retained');
    }
    verify(strpos($html, '<tfoot>') === false, 'no duplicate footer');
    verify(PGE_Catalog::$writes === [], 'statistics are read only');
}
verify(strpos($html, 'is-inactive">غير نشطة') !== false, 'inactive readable without color');
verify(strpos($html, 'is-active">نشطة') !== false, 'active readable without color');
foreach (['المزايا', 'النوع', 'الحالة', 'ترتيب العرض'] as $label) {
    verify(strpos($html, 'class="pge-mobile-label" aria-hidden="true">' . $label) !== false, 'mobile field label');
}
PGE_Catalog::$rows = null;
foreach (['add', 'edit', 'delete'] as $view) {
    [$html] = render_view(['action' => $view, 'plan_id' => '1']);
    verify(strpos($html, 'data-stat=') === false, 'statistics only on list');
    verify(strpos($html, 'pge-plan-panel') !== false, 'form panel');
    if ($view !== 'delete') {
        verify(substr_count($html, '(مطلوب)') === 2, 'required fields explained');
        verify(strpos($html, '50 ميزة، و255 حرفًا') !== false, 'existing feature limits explained');
        verify(strpos($html, 'aria-describedby="pge-' . ($view === 'add' ? 'create' : 'edit') . '-features-help"') !== false, 'feature help associated');
        verify(strpos($html, 'name="plan_key" class="regular-text" dir="ltr"') !== false, 'input direction');
    }
}
// Execute the live enqueue function separately; do not bootstrap the plugin.
$style_start = strpos($source, 'function pge_catalog_plans_admin_styles(');
$style_end = strpos($source, "add_action('admin_enqueue_scripts', 'pge_catalog_plans_admin_styles');", $style_start);
eval(substr($source, $style_start, $style_end - $style_start));
define('PGE_URL', '/wp-content/plugins/pgevents-core/');
function wp_enqueue_style(...$args) { $GLOBALS['enqueued_styles'][] = $args; }
$GLOBALS['enqueued_styles'] = [];
foreach (['index.php', 'pge-catalog-plans_page_pge-catalog-tiers', 'post.php', ''] as $hook) {
    pge_catalog_plans_admin_styles($hook);
    verify($GLOBALS['enqueued_styles'] === [], 'no CSS on other admin screens');
}
pge_catalog_plans_admin_styles('toplevel_page_pge-catalog-plans');
verify(count($GLOBALS['enqueued_styles']) === 1, 'CSS enqueued once on plans');
verify($GLOBALS['enqueued_styles'][0][1] === PGE_URL . 'assets/css/catalog-plans-admin.css', 'local CSS URL');
verify($GLOBALS['enqueued_styles'][0][2] === [], 'no CSS dependencies');
echo 'UI-01: ' . $ui01_checks . '/' . $ui01_checks . " passed\n";
echo 'UI-02: ' . ($checks - $ui01_checks) . '/' . ($checks - $ui01_checks) . " passed\n";
echo "PASS: $checks/$checks assertions\n";
