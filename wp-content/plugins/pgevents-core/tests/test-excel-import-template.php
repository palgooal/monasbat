<?php
/**
 * اختبار تنفيذي حقيقي — Phase 1 فقط من "استيراد المدعوين من Excel"
 * (docs/EXCEL-GUEST-IMPORT-SPEC.md): تنزيل النموذج الرسمي حصراً.
 *
 * يغطي:
 *  1) PGE_Xlsx_Writer::build() — التوافق الخلفي (بلا $text_columns، السلوك
 *     يبقى مطابقاً تماماً لكل الاستدعاءات السابقة، بلا أي خاصية `s` على أي خلية).
 *  2) PGE_Xlsx_Writer::build($rows, [1]) — عمود واحد فقط (فهرس 1) يحمل
 *     s="1" (نمط Text، numFmtId="49")، بقية الأعمدة بلا أي `s`.
 *  3) styles.xml يحوي cellXfs count="2" ونمط numFmtId="49" جديد، بلا مساس
 *     بالنمط الافتراضي index 0.
 *  4) pge_invitation_mgmt_excel_template_handler() — تسجيل الـhook، حارس
 *     pge_mgmt_validate_request() (تسجيل دخول/nonce/event صالح/ملكية)،
 *     المخرَج xlsx حقيقي (توقيع ZIP)، 3 أعمدة بالضبط (الاسم|رقم الجوال|ملاحظة)
 *     بلا عمود رابع، عمود رقم الجوال (B) فقط منسَّق Text.
 *  5) انحدار: باقي hooks التصدير (CSV/Excel الحاليان) لا تزال مُسجَّلة.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 * التشغيل: php tests/test-excel-import-template.php (أو عبر harness php-wasm)
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__test_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['__test_registered_hooks'][$hook] = true; }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) {
    function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($v) { return trim((string) $v); }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($v) { return preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string) $v); }
}
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now'] ?? '2026-01-01 00:00:00'; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('wp_hash')) {
    function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed'); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) { return strtolower((string) $string); }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) { return strpos((string) $haystack, (string) $needle, $offset); }
}

// ── تفويض/جلسة — نفس أسلوب test-invitation-export.php حرفياً ───────────────
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null) {
    if ($cap === 'administrator') return $GLOBALS['__test_user_is_admin'];
    return false;
}

// ── Posts وهمية ──────────────────────────────────────────────────────────
$GLOBALS['__test_posts'] = [];
function set_test_event($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) ['ID' => $event_id, 'post_type' => $post_type, 'post_author' => $author_id];
}
function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
function get_post_field($field, $post_id) { $p = get_post($post_id); return $p ? ($p->{$field} ?? '') : ''; }
function get_the_title($post_id) { $p = get_post($post_id); return $p ? ('مناسبة #' . $p->ID) : ''; }

// ── Post Meta وهمية (event-guests.php قد يستدعيها لدوال أخرى غير مُستخدَمة هنا) ──
$GLOBALS['__test_post_meta'] = [];
function get_post_meta($post_id, $key = '', $single = false)
{
    $value = $GLOBALS['__test_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_post_meta($post_id, $key, $value)
{
    $GLOBALS['__test_post_meta'][$post_id][$key] = $value;
    return true;
}

if (!class_exists('Test_Wp_Die_Exception')) {
    class Test_Wp_Die_Exception extends \Exception {}
}

$GLOBALS['__test_nonce_valid_actions'] = [];
function wp_create_nonce($action) { $GLOBALS['__test_nonce_valid_actions'][$action] = true; return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception('wp_die'); }
function esc_url($v) { return $v; }
function esc_attr($v) { return $v; }
function esc_html($v) { return $v; }
function admin_url($path = '') { return 'http://example.test/wp-admin/' . ltrim($path, '/'); }
function home_url($path = '') { return 'http://example.test' . $path; }

/**
 * التقاط المخرَجات الخام لمعالج تنزيل ملف (echo + wp_die())، أو استجابة JSON
 * إن فشل التحقق مبكراً (pge_mgmt_validate_request()) — نفس نمط
 * test-invitation-export.php::call_export_handler حرفياً.
 */
function call_export_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    ob_start();
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // متوقَّع (wp_die() في نهاية المسار الناجح، أو wp_send_json_error عند الفشل المبكر).
    }
    $raw_output = ob_get_clean();
    return ['output' => $raw_output, 'json' => $GLOBALS['__test_json_response']];
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failures[] = $label;
        echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}
function check_true($label, $condition) { check($label, (bool) $condition, true); }

$total = 0; $passed = 0; $failures = [];

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php';
require_once __DIR__ . '/../includes/invitation-management-ajax.php'; // يُحمِّل class-pge-invitation-export.php وclass-pge-xlsx-writer.php وclass-pge-invitation-bulk-add.php داخلياً

set_test_event(2001, 901);
set_test_event(2002, 902);
$GLOBALS['__test_current_user_id'] = 901;

echo "=== 1) PGE_Xlsx_Writer::build() — التوافق الخلفي (بلا text_columns) ===\n";
$legacy_bin = PGE_Xlsx_Writer::build([['اسم', 'قيمة'], ['أحمد', '123']]);
check_true('1.1 المخرَج توقيع ZIP حقيقي (PK\\x03\\x04)', substr($legacy_bin, 0, 4) === "PK\x03\x04");
check_true('1.2 لا أي خاصية s= على أي خلية (السلوك القديم كما هو تماماً)', strpos($legacy_bin, ' s="') === false);
check_true('1.3 القيمة "أحمد" محفوظة بايتياً (UTF-8 سليم)', strpos($legacy_bin, 'أحمد') !== false);
check_true('1.4 styles.xml يحوي cellXfs count="2" الآن (إضافة نمط Text غير مُستخدَم هنا، لكنه موجود دوماً بلا كسر شيء)', strpos($legacy_bin, 'cellXfs count="2"') !== false);
check_true('1.5 النمط index 0 الافتراضي (numFmtId="0") لا يزال أول xf في cellXfs', strpos($legacy_bin, '<cellXfs count="2"><xf numFmtId="0"') !== false);

echo "\n=== 2) PGE_Xlsx_Writer::build(\$rows, [1]) — عمود واحد Text ===\n";
$styled_bin = PGE_Xlsx_Writer::build([['الاسم', 'رقم الجوال', 'ملاحظة'], ['محمد', '0599123456', '']], [1]);
check_true('2.1 توقيع ZIP حقيقي', substr($styled_bin, 0, 4) === "PK\x03\x04");
check_true('2.2 الخلية A1 (الاسم) بلا s=', strpos($styled_bin, '<c r="A1" t="inlineStr">') !== false);
check_true('2.3 الخلية B1 (رقم الجوال) تحمل s="1"', strpos($styled_bin, '<c r="B1" s="1" t="inlineStr">') !== false);
check_true('2.4 الخلية C1 (ملاحظة) بلا s=', strpos($styled_bin, '<c r="C1" t="inlineStr">') !== false);
check_true('2.5 الصف الثاني: B2 أيضاً s="1" (كل صفوف نفس العمود تُنسَّق)', strpos($styled_bin, '<c r="B2" s="1" t="inlineStr">') !== false);
check_true('2.6 الصف الثاني: A2/C2 بلا s=', strpos($styled_bin, '<c r="A2" t="inlineStr">') !== false && strpos($styled_bin, '<c r="C2" t="inlineStr">') !== false);
check_true('2.7 numFmtId="49" (Text) موجود في styles.xml', strpos($styled_bin, 'numFmtId="49"') !== false);
check_true('2.8 القيمة "0599123456" محفوظة بايتياً كما هي (بلا فقدان صفر)', strpos($styled_bin, '0599123456') !== false);

echo "\n=== 3) تسجيل الـhook ===\n";
check_true('3.1 wp_ajax_pge_invitation_mgmt_excel_template مُسجَّل', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_excel_template']));
check_true('3.2 wp_ajax_pge_invitation_mgmt_export_csv لا يزال مُسجَّلاً (انحدار)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_export_csv']));
check_true('3.3 wp_ajax_pge_invitation_mgmt_export_excel لا يزال مُسجَّلاً (انحدار)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_export_excel']));
check_true('3.4 wp_ajax_pge_invitation_mgmt_bulk_preview لا يزال مُسجَّلاً (انحدار)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_bulk_preview']));

echo "\n=== 4) حارس pge_mgmt_validate_request() — الفشل ===\n";
$GLOBALS['__test_logged_in'] = false;
$_POST = make_post_fields(2001);
$r = call_export_handler('pge_invitation_mgmt_excel_template_handler');
check('4.1 غير مسجَّل دخول: reason=not_logged_in', json_decode($r['json'], true)['data']['reason'] ?? null, 'not_logged_in');
check('4.1ب لا مخرَج ملف عند الرفض', $r['output'], '');
$GLOBALS['__test_logged_in'] = true;

$_POST = make_post_fields(2001, ['nonce' => 'invalid-nonce-value']);
$r = call_export_handler('pge_invitation_mgmt_excel_template_handler');
check('4.2 nonce غير صالح: reason=invalid_nonce', json_decode($r['json'], true)['data']['reason'] ?? null, 'invalid_nonce');

$_POST = make_post_fields(999999);
$r = call_export_handler('pge_invitation_mgmt_excel_template_handler');
check('4.3 مناسبة غير موجودة: reason=invalid_event', json_decode($r['json'], true)['data']['reason'] ?? null, 'invalid_event');

$GLOBALS['__test_current_user_id'] = 555; // مستخدم دخيل، لا يملك 2001 ولا 2002
$_POST = make_post_fields(2001);
$r = call_export_handler('pge_invitation_mgmt_excel_template_handler');
check('4.4 مستخدم دخيل (ليس مالكاً/أدمن): reason=forbidden', json_decode($r['json'], true)['data']['reason'] ?? null, 'forbidden');
$GLOBALS['__test_current_user_id'] = 901; // إعادة المالك الصحيح

echo "\n=== 5) المسار الناجح — تنزيل فعلي ===\n";
$_POST = make_post_fields(2001);
$r = call_export_handler('pge_invitation_mgmt_excel_template_handler');
$bin = $r['output'];
check_true('5.1 لا استجابة JSON فشل (المسار نجح)', $r['json'] === null);
check_true('5.2 المخرَج غير فارغ', $bin !== '');
check_true('5.3 توقيع ZIP حقيقي (PK\\x03\\x04) — xlsx حقيقي لا HTML', substr($bin, 0, 4) === "PK\x03\x04");
check_true('5.4 لا أي نص "Warning" أو "Notice" أو "<html" مسرَّب قبل الملف الثنائي', strpos($bin, 'Warning') !== 0 && strpos($bin, '<html') !== 0);

echo "\n=== 6) محتوى النموذج — Template Contract (3 أعمدة بالضبط) ===\n";
check_true('6.1 خلية A1 = "الاسم"', strpos($bin, '<c r="A1" t="inlineStr"><is><t xml:space="preserve">الاسم</t></is></c>') !== false);
check_true('6.2 خلية B1 = "رقم الجوال" (منسَّقة Text عبر s="1")', strpos($bin, '<c r="B1" s="1" t="inlineStr"><is><t xml:space="preserve">رقم الجوال</t></is></c>') !== false);
check_true('6.3 خلية C1 = "ملاحظة"', strpos($bin, '<c r="C1" t="inlineStr"><is><t xml:space="preserve">ملاحظة</t></is></c>') !== false);
check_true('6.4 لا وجود لخلية رابعة D1 (3 أعمدة بالضبط، لا أكثر)', strpos($bin, 'r="D1"') === false);
check_true('6.5 صف واحد فقط (لا صفوف بيانات إضافية غير الهيدر) — لا r="2" في sheetData', strpos($bin, '<row r="2">') === false);
check_true('6.6 عمود الاسم (A) بلا أي s= (ليس Text — فقط الجوال)', strpos($bin, '<c r="A1" s=') === false);
check_true('6.7 عمود الملاحظة (C) بلا أي s= (ليس Text — فقط الجوال)', strpos($bin, '<c r="C1" s=') === false);
check_true('6.8 numFmtId="49" (Text) موجود في styles.xml المضمَّن', strpos($bin, 'numFmtId="49"') !== false);

echo "\n=== 7) انحدار — لا مساس بمنطق تصدير Phase 9C الأصلي ===\n";
// نفس الاستدعاء أحادي الوسيط (بلا text_columns) الذي يستخدمه class-pge-invitation-export.php فعلياً — يجب أن يبقى بلا أي s= على الإطلاق حتى بعد إضافة الميزة.
$export_style_bin = PGE_Xlsx_Writer::build([['عمود1', 'عمود2', 'عمود3'], ['قيمة1', 'قيمة2', 'قيمة3']]);
check_true('7.1 استدعاء التصدير القديم (وسيط واحد) لا يزال بلا أي s= على أي خلية', strpos($export_style_bin, ' s="') === false);
check_true('7.2 استدعاء التصدير القديم لا يزال يُنتج ZIP حقيقي', substr($export_style_bin, 0, 4) === "PK\x03\x04");

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
} else {
    echo "كل اختبارات Phase 1 (استيراد المدعوين من Excel — تنزيل النموذج) نجحت.\n";
}
