<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) — Phase 4 من "استيراد المدعوين من
 * Excel" (docs/EXCEL-GUEST-IMPORT-SPEC.md): تأكيد الاستيراد الفعلي (Confirm)
 * عبر pge_invitation_mgmt_excel_confirm_handler() الحقيقي — يُنفَّذ الاسم
 * الحقيقي مباشرة عبر AJAX حقيقي، بلا أي محاكاة منطقية موازية.
 *
 * يحمِّل نفس السلسلة الكاملة المُستخدَمة في test-excel-import-upload-
 * preview.php (Phase 3) بالضبط، ويُعيد استخدام pge_invitation_mgmt_excel_
 * preview_handler() الحقيقي لتوليد upload_token/transient/ملف حقيقيَين قبل
 * كل اختبار Confirm — تماماً كما يحدث في الإنتاج (Preview أولاً، ثم Confirm).
 *
 * ============================================================================
 * ملاحظة منهجية — قيد بيئة الاختبار (php-wasm، موروث من Phase 2/3)
 * ============================================================================
 * بيئة php-wasm هنا لا تملك ext-simplexml. رفع XLSX حقيقي سينتهي عادةً بـ
 * error='xlsx_parse_error' من parse_file() (الذي يُستدعى مرتين: مرة في
 * Preview، ومرة أخرى — بتصميم متعمَّد — داخل Confirm نفسه لإعادة التحليل من
 * الصفر). سيناريو 2 (Confirm لملف XLSX) لذلك يقبل صراحةً إما نجاحاً حقيقياً
 * كاملاً أو رفضاً نظيفاً بهذا السبب تحديداً مع تنظيف مؤكَّد — نفس منهجية
 * Phase 3 المُفصَح عنها هناك حرفياً.
 *
 * التشغيل: node run-generic2.mjs test-excel-import-confirm.php (أو
 * build-phase3.mjs/run-phase3.mjs المكافئ إن احتاج فحص نصي لـ__DIR__).
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__test_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['__test_registered_hooks'][$hook] = true; }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) { function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_file_name')) { function sanitize_file_name($v) { return preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string) $v); } }
if (!function_exists('absint')) { function absint($value) { return abs((int) $value); } }
if (!function_exists('current_time')) { function current_time($type = 'mysql', $gmt = 0) { return '2026-08-07 00:00:00'; } }
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
if (!function_exists('wp_hash')) { function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed'); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($string, $encoding = null) { return strtolower((string) $string); } }
if (!function_exists('mb_strpos')) { function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) { return strpos((string) $haystack, (string) $needle, $offset); } }
if (!function_exists('pge_generate_invite_code')) {
    function pge_generate_invite_code()
    {
        static $n = 0;
        $n++;
        return 'CODE-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}

if (!defined('PGE_PATH')) define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

// ── تفويض/جلسة ───────────────────────────────────────────────────────────
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
function get_post_field($field, $post_id) { $p = get_post($post_id); if (!$p) return ''; return $p->{$field} ?? ''; }
function get_the_title($post_id) { $p = get_post($post_id); return $p ? ('مناسبة #' . $p->ID) : ''; }

// ── Post Meta وهمية — مع حَقن فشل اختياري لسيناريو "فشل صف واحد لا يوقف الباقي" ─
$GLOBALS['__test_post_meta'] = [];
$GLOBALS['__test_throw_on_next_guest_map_save'] = false;
function get_post_meta($post_id, $key = '', $single = false)
{
    $value = $GLOBALS['__test_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_post_meta($post_id, $key, $value)
{
    if ($key === '_pge_invited_guests' && $GLOBALS['__test_throw_on_next_guest_map_save']) {
        $GLOBALS['__test_throw_on_next_guest_map_save'] = false;
        throw new \RuntimeException('simulated_guest_map_save_failure');
    }
    $GLOBALS['__test_post_meta'][$post_id][$key] = $value;
    return true;
}

// ── Transients وهمية (in-memory) — نفس عقد set/get/delete_transient الحقيقي ─
$GLOBALS['__test_transients'] = [];
function set_transient($key, $value, $expiration = 0) { $GLOBALS['__test_transients'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['__test_transients'][$key] ?? false; }
function delete_transient($key) { unset($GLOBALS['__test_transients'][$key]); return true; }

// ── Uploads dir وهمية — مسار حقيقي قابل للكتابة داخل VFS الاختبار ──────────
function wp_upload_dir()
{
    $base = sys_get_temp_dir() . '/pge-test-uploads-p4';
    if (!is_dir($base)) { @mkdir($base, 0777, true); }
    return ['basedir' => $base, 'baseurl' => 'http://example.test/wp-content/uploads', 'path' => $base, 'url' => 'http://example.test/wp-content/uploads'];
}
function wp_mkdir_p($dir) { if (!is_dir($dir)) { return (bool) @mkdir($dir, 0777, true); } return true; }

function pge_invitation_mgmt_is_uploaded_file(string $path): bool
{
    return $path !== '' && is_file($path);
}
function pge_invitation_mgmt_move_uploaded_file(string $src, string $dst): bool
{
    if (@rename($src, $dst)) return true;
    return (bool) @copy($src, $dst);
}

// ── Stubs AJAX/JSON ──────────────────────────────────────────────────────
if (!class_exists('Test_Wp_Die_Exception_P4')) { class Test_Wp_Die_Exception_P4 extends \Exception {} }

function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception_P4('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception_P4('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception_P4('wp_die'); }

function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try { $handler(); } catch (Test_Wp_Die_Exception_P4 $e) { /* متوقَّع */ }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

function make_upload_tmp_file(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'pge_upload_src_p4_');
    file_put_contents($path, $content);
    return $path;
}
function make_files_field(string $content, string $original_name, int $error = UPLOAD_ERR_OK): array
{
    $tmp_path = make_upload_tmp_file($content);
    return [
        'name'     => $original_name,
        'type'     => 'application/octet-stream',
        'tmp_name' => $tmp_path,
        'error'    => $error,
        'size'     => strlen($content),
    ];
}

// ── Fake $wpdb — نفس نمط test-rc1-fixpack3a.php / test-excel-import-upload-preview.php ─
class Fake_Wpdb_Excel_P4
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $audit_log = [];
    private $audit_next_id = 1;

    private function which_table($sql_or_table)
    {
        if (strpos($sql_or_table, $this->prefix . 'pge_invitation_mgmt_audit_log') !== false) return 'audit';
        return null;
    }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? ''; $i++;
            return $m[0] === '%d' ? (string) (int) $val : "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    public function get_results($sql, $output = null) { return []; }

    public function insert($table, $data, $format = null)
    {
        if ($this->which_table($table) !== 'audit') return false;
        $id = $this->audit_next_id++;
        $this->audit_log[$id] = array_merge(['id' => $id], $data);
        $this->insert_id = $id;
        return 1;
    }
}
$GLOBALS['wpdb'] = new Fake_Wpdb_Excel_P4();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ============================================================================
// تحميل حقيقي للسلسلة الكاملة (نفس ترتيب pgevents-core.php بالضبط)
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/class-pge-xlsx-writer.php';
require_once __DIR__ . '/../includes/class-pge-invitation-export.php';
require_once __DIR__ . '/../includes/class-pge-invitation-bulk-add.php';
require_once __DIR__ . '/../includes/class-pge-invitation-excel-import.php';
require_once __DIR__ . '/../includes/invitation-management-ajax.php';

$total = 0; $passed = 0; $failures = [];
function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS  $label\n"; }
    else { $failures[] = $label; echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}
function check_true($label, $cond) { check($label, (bool) $cond, true); }

function extract_function_body(string $source, string $function_name): string
{
    $needle = 'function ' . $function_name . '(';
    $start = strpos($source, $needle);
    if ($start === false) return '';
    $brace_open = strpos($source, '{', $start);
    if ($brace_open === false) return '';
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace_open; $i < $len; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $brace_open, $i - $brace_open + 1);
            }
        }
    }
    return '';
}

// ── مساعدات Preview/Confirm عالية المستوى — استدعاء المعالجات الحقيقية فقط ──
function do_preview($event_id, string $content, string $filename = 'guests.csv'): array
{
    $_FILES = ['file' => make_files_field($content, $filename)];
    $_POST = make_post_fields($event_id);
    return call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
}
function do_confirm($event_id, $token, array $extra = []): array
{
    $_FILES = [];
    $_POST = make_post_fields($event_id, array_merge(['upload_token' => $token], $extra));
    return call_ajax_handler('pge_invitation_mgmt_excel_confirm_handler');
}

echo "=== Phase 4 — Excel Import: Confirm (استيراد فعلي) ===\n";

// ============================================================================
// إعداد: مضيف يملك 4001/4002/4003، أدمن منفصل، مناسبات معزولة للاختبارات
// ============================================================================
$HOST_ID = 701;
$ADMIN_ID = 999;
set_test_event(4001, $HOST_ID);
set_test_event(4002, $HOST_ID);
set_test_event(4003, $HOST_ID);
$GLOBALS['__test_current_user_id'] = $HOST_ID;
$GLOBALS['__test_logged_in'] = true;
$GLOBALS['__test_user_is_admin'] = false;

// ============================================================================
// 1) Confirm صحيح لملف CSV — استيراد فعلي كامل
// ============================================================================
$resp1_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nضيف واحد,0511100001,ملاحظة1\n");
$token1 = $resp1_preview['data']['upload_token'] ?? null;
check_true('1. Preview أولي نجح وأعاد upload_token', is_string($token1) && $token1 !== '');
$transient1_path = get_transient('pge_excel_import_' . $token1)['file_path'] ?? '';
$resp1 = do_confirm(4001, $token1);
check_true('1. Confirm صحيح لملف CSV: ok=true', $resp1['success'] ?? false);
check('1ب. summary.imported = 1', $resp1['data']['summary']['imported'] ?? null, 1);
check('1ج. summary.total_rows = 1', $resp1['data']['summary']['total_rows'] ?? null, 1);
check_true('1د. الضيف أُنشئ فعلياً في خريطة المناسبة 4001', isset(pge_event_guests_get_map(4001)['0511100001']));

// ============================================================================
// 2) Confirm صحيح لملف XLSX — يقبل نجاحاً حقيقياً أو رفضاً نظيفاً بسبب قيد بيئة الاختبار
// ============================================================================
$xlsx_binary_2 = PGE_Xlsx_Writer::build(
    [['الاسم', 'رقم الجوال', 'ملاحظة'], ['ضيف اكسل', '0511100002', '']],
    [1]
);
$resp2_preview = do_preview(4001, $xlsx_binary_2, 'guests.xlsx');
$token2 = $resp2_preview['data']['upload_token'] ?? null;
check_true('2. Preview XLSX أعاد upload_token رغم أي فشل تحليل محتمل', is_string($token2) && $token2 !== '');
$resp2 = do_confirm(4001, $token2);
$resp2_ok = $resp2['success'] ?? false;
$resp2_reason = $resp2['data']['reason'] ?? null;
check_true('2. Confirm صحيح لملف XLSX: نجاح حقيقي أو رفض نظيف بسبب xlsx_parse_error فقط', $resp2_ok === true || $resp2_reason === 'xlsx_parse_error');
if (!$resp2_ok) {
    check_true('2ب. عند فشل التحليل: تنظيف مؤكَّد (الملف محذوف)', !file_exists(get_transient('pge_excel_import_' . $token2)['file_path'] ?? '/__nonexistent__') );
    check('2ج. عند فشل التحليل: الـ transient محذوف', get_transient('pge_excel_import_' . $token2), false);
}

// ============================================================================
// 3) Token غير موجود إطلاقاً
// ============================================================================
$bogus_token3 = bin2hex(random_bytes(16));
$resp3 = do_confirm(4001, $bogus_token3);
check('3. Token غير موجود: ok=false', $resp3['success'] ?? true, false);
check('3ب. reason=token_not_found', $resp3['data']['reason'] ?? null, 'token_not_found');
check('3ج. رسالة انتهاء الصلاحية الحرفية', $resp3['data']['message'] ?? '', 'انتهت صلاحية جلسة الاستيراد أو تم تنفيذها مسبقاً.');

// ============================================================================
// 4) Token منتهي الصلاحية (محاكاة انتهاء TTL عبر حذف الـtransient يدوياً)
// ============================================================================
$resp4_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nمنتهي الصلاحية,0511100004,\n");
$token4 = $resp4_preview['data']['upload_token'] ?? null;
delete_transient('pge_excel_import_' . $token4); // محاكاة انتهاء TTL الحقيقي
$resp4 = do_confirm(4001, $token4);
check('4. Token منتهي الصلاحية: ok=false', $resp4['success'] ?? true, false);
check('4ب. reason=token_not_found', $resp4['data']['reason'] ?? null, 'token_not_found');

// ============================================================================
// 5) Token يخص مستخدماً آخر — أدمن أنشأ الرفع، مضيف المناسبة يحاول تأكيده
// ============================================================================
$GLOBALS['__test_current_user_id'] = $ADMIN_ID;
$GLOBALS['__test_user_is_admin'] = true;
$resp5_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nرفع الأدمن,0511100005,\n");
$token5 = $resp5_preview['data']['upload_token'] ?? null;
$GLOBALS['__test_current_user_id'] = $HOST_ID;
$GLOBALS['__test_user_is_admin'] = false;
$resp5 = do_confirm(4001, $token5);
check('5. Token يخص مستخدماً آخر: ok=false', $resp5['success'] ?? true, false);
check('5ب. reason=token_not_found (رسالة موحَّدة لمنع تعداد Token)', $resp5['data']['reason'] ?? null, 'token_not_found');

// ============================================================================
// 6) Token يخص مناسبة أخرى
// ============================================================================
$resp6_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nمناسبة أخرى,0511100006,\n");
$token6 = $resp6_preview['data']['upload_token'] ?? null;
$resp6 = do_confirm(4002, $token6); // نفس المضيف يملك 4002 أيضاً، لكن الـtoken يخص 4001
check('6. Token يخص مناسبة أخرى: ok=false', $resp6['success'] ?? true, false);
check('6ب. reason=token_not_found', $resp6['data']['reason'] ?? null, 'token_not_found');

// ============================================================================
// 7) file_path مفقود داخل الـtransient
// ============================================================================
$resp7_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nمسار مفقود,0511100007,\n");
$token7 = $resp7_preview['data']['upload_token'] ?? null;
$t7 = get_transient('pge_excel_import_' . $token7);
$t7['file_path'] = '';
set_transient('pge_excel_import_' . $token7, $t7);
$resp7 = do_confirm(4001, $token7);
check('7. file_path مفقود: ok=false', $resp7['success'] ?? true, false);
check('7ب. reason=file_missing', $resp7['data']['reason'] ?? null, 'file_missing');

// ============================================================================
// 8) الملف حُذف فعلياً من القرص قبل Confirm
// ============================================================================
$resp8_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nملف محذوف,0511100008,\n");
$token8 = $resp8_preview['data']['upload_token'] ?? null;
$t8_path = get_transient('pge_excel_import_' . $token8)['file_path'] ?? '';
@unlink($t8_path);
$resp8 = do_confirm(4001, $token8);
check('8. الملف محذوف مسبقاً: ok=false', $resp8['success'] ?? true, false);
check('8ب. reason=file_missing', $resp8['data']['reason'] ?? null, 'file_missing');
check('8ج. تنظيف مؤكَّد رغم ذلك: الـtransient محذوف', get_transient('pge_excel_import_' . $token8), false);

// ============================================================================
// 9) file_type غير مدعوم داخل الـtransient
// ============================================================================
$resp9_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nنوع غير مدعوم,0511100009,\n");
$token9 = $resp9_preview['data']['upload_token'] ?? null;
$t9 = get_transient('pge_excel_import_' . $token9);
$t9_path = $t9['file_path'];
$t9['file_type'] = 'exe';
set_transient('pge_excel_import_' . $token9, $t9);
$resp9 = do_confirm(4001, $token9);
check('9. file_type غير مدعوم: ok=false', $resp9['success'] ?? true, false);
check('9ب. reason=invalid_file_type', $resp9['data']['reason'] ?? null, 'invalid_file_type');
check_true('9ج. تنظيف مؤكَّد: الملف الفعلي محذوف', !file_exists($t9_path));

// ============================================================================
// 10) إعادة التحليل تحدث فعلياً — تعديل الملف على القرص بعد Preview وقبل Confirm
// ============================================================================
$resp10_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nصف أصلي واحد,0511100010,\n");
$token10 = $resp10_preview['data']['upload_token'] ?? null;
$t10_path = get_transient('pge_excel_import_' . $token10)['file_path'] ?? '';
file_put_contents($t10_path, "الاسم,رقم الجوال,ملاحظة\nصف جديد أول,0511100011,\nصف جديد ثاني,0511100012,\n");
$resp10 = do_confirm(4001, $token10);
check_true('10. Confirm نجح بعد استبدال الملف', $resp10['success'] ?? false);
check('10ب. total_rows=2 (يثبت إعادة التحليل من الملف الفعلي الجديد، لا القديم)', $resp10['data']['summary']['total_rows'] ?? null, 2);
check('10ج. imported=2', $resp10['data']['summary']['imported'] ?? null, 2);
check_true('10د. الصف القديم (0511100010) لم يُستورَد إطلاقاً', !isset(pge_event_guests_get_map(4001)['0511100010']));

// ============================================================================
// 11) لا اعتماد على صفوف Preview — إرسال rows مُلفَّقة من المتصفح تُتجاهَل كلياً
// ============================================================================
$resp11_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nصف حقيقي,0511100013,\n");
$token11 = $resp11_preview['data']['upload_token'] ?? null;
$fake_rows_json = json_encode([
    ['name' => 'ملفَّق 1', 'phone' => '0599999991', 'status' => 'valid'],
    ['name' => 'ملفَّق 2', 'phone' => '0599999992', 'status' => 'valid'],
]);
$resp11 = do_confirm(4001, $token11, ['rows' => $fake_rows_json, 'count' => 999]);
check_true('11. Confirm نجح رغم إرسال rows مُلفَّقة', $resp11['success'] ?? false);
check('11ب. total_rows=1 (تجاهل كامل للـrows المُلفَّقة من $_POST)', $resp11['data']['summary']['total_rows'] ?? null, 1);
check_true('11ج. الضيف الحقيقي فقط أُضيف', isset(pge_event_guests_get_map(4001)['0511100013']));
check_true('11د. أي من الأرقام المُلفَّقة لم تُضَف', !isset(pge_event_guests_get_map(4001)['0599999991']) && !isset(pge_event_guests_get_map(4001)['0599999992']));
$ajax_source_11 = isset($__pge_ajax_source_override) ? $__pge_ajax_source_override : file_get_contents(__DIR__ . '/../includes/invitation-management-ajax.php');
$confirm_body_11 = extract_function_body($ajax_source_11, 'pge_invitation_mgmt_excel_confirm_handler');
check_true("11هـ. فحص نصّي: جسم Confirm لا يقرأ \$_POST['rows'] إطلاقاً", strpos($confirm_body_11, "\$_POST['rows']") === false);

// ============================================================================
// 12) Race Condition: صف كان valid في Preview يصبح duplicate قبل Confirm
// ============================================================================
$RACE_PHONE = '0511100014';
$resp12_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nسباق,$RACE_PHONE,\n");
$token12 = $resp12_preview['data']['upload_token'] ?? null;
check('12. Preview: الصف valid قبل أي إضافة يدوية', $resp12_preview['data']['rows'][0]['status'] ?? null, 'valid');
// إضافة يدوية "متزامنة" لنفس الرقم بين Preview وConfirm (محاكاة سباق حقيقي)
$manual_add_12 = PGE_Invitation_Service::create(4001, $RACE_PHONE, 'أُضيف يدوياً بالتزامن', '', $HOST_ID);
check('12ب. الإضافة اليدوية التمهيدية نجحت فعلاً', $manual_add_12['result'] ?? null, 'created');
$resp12 = do_confirm(4001, $token12);
check_true('12ج. Confirm نجح بنيوياً رغم اكتشاف التكرار', $resp12['success'] ?? false);
check('12د. الصف اكتُشف كمكرر عند Confirm (لا اعتماد على status=valid من Preview)', $resp12['data']['rows'][0]['result'] ?? null, 'duplicate');
check('12هـ. duplicates=1، imported=0 لهذا الصف', [$resp12['data']['summary']['duplicates'] ?? null, $resp12['data']['summary']['imported'] ?? null], [1, 0]);
check('12و. لا إضافة مضاعفة: الرقم موجود مرة واحدة فقط في خريطة الضيوف', count(array_filter(pge_event_guests_get_map(4001), fn($g) => $g['phone'] === $RACE_PHONE)), 1);

// ============================================================================
// 13) مكرَّر لا يُضاف (حالة عادية، بلا تزامن — تكرار من نفس ملف الاستيراد)
// ============================================================================
$DUP_PHONE_13 = '0511100015';
PGE_Invitation_Service::create(4001, $DUP_PHONE_13, 'موجود مسبقاً', '', $HOST_ID);
$resp13_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nمكرر,$DUP_PHONE_13,\n");
$token13 = $resp13_preview['data']['upload_token'] ?? null;
$resp13 = do_confirm(4001, $token13);
check('13. المكرر لا يُضاف: duplicates=1', $resp13['data']['summary']['duplicates'] ?? null, 1);
check('13ب. imported=0', $resp13['data']['summary']['imported'] ?? null, 0);
check('13ج. لا يزال موجوداً مرة واحدة فقط', count(array_filter(pge_event_guests_get_map(4001), fn($g) => $g['phone'] === $DUP_PHONE_13)), 1);

// ============================================================================
// 14) invalid_phone لا يُضاف
// ============================================================================
$resp14_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nرقم فاسد,***,\n");
$token14 = $resp14_preview['data']['upload_token'] ?? null;
$resp14 = do_confirm(4001, $token14);
check('14. invalid_phone لا يُضاف: invalid=1', $resp14['data']['summary']['invalid'] ?? null, 1);
check('14ب. imported=0', $resp14['data']['summary']['imported'] ?? null, 0);

// ============================================================================
// 15) missing_name لا يُضاف
// ============================================================================
$resp15_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\n,0511100016,\n");
$token15 = $resp15_preview['data']['upload_token'] ?? null;
$resp15 = do_confirm(4001, $token15);
check('15. missing_name لا يُضاف: invalid=1', $resp15['data']['summary']['invalid'] ?? null, 1);
check_true('15ب. الرقم لم يُضَف', !isset(pge_event_guests_get_map(4001)['0511100016']));

// ============================================================================
// 16) missing_phone لا يُضاف
// ============================================================================
$resp16_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nبدون جوال,,\n");
$token16 = $resp16_preview['data']['upload_token'] ?? null;
$resp16 = do_confirm(4001, $token16);
check('16. missing_phone لا يُضاف: invalid=1', $resp16['data']['summary']['invalid'] ?? null, 1);

// ============================================================================
// 17) الصف الصالح يمر فعلياً عبر PGE_Invitation_Service::create() — إثبات عبر أثر التدقيق الفردي
// ============================================================================
$resp17_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nإثبات المسار,0511100017,\n");
$token17 = $resp17_preview['data']['upload_token'] ?? null;
$resp17 = do_confirm(4001, $token17);
$created_audit_17 = array_filter($wpdb->audit_log, fn($row) => $row['action'] === 'created' && $row['guest_phone'] === '0511100017');
check_true('17. أثر تدقيق created فردي حقيقي — يثبت مرور الصف عبر PGE_Invitation_Service::create() الحقيقية', count($created_audit_17) === 1);

// ============================================================================
// 18) لا INSERT مباشر داخل معالج Confirm
// ============================================================================
$ajax_source_18 = isset($__pge_ajax_source_override) ? $__pge_ajax_source_override : file_get_contents(__DIR__ . '/../includes/invitation-management-ajax.php');
$confirm_body_18 = extract_function_body($ajax_source_18, 'pge_invitation_mgmt_excel_confirm_handler');
check_true('18. لا استدعاء $wpdb->insert( مباشر داخل معالج Confirm', strpos($confirm_body_18, '$wpdb->insert(') === false);

// ============================================================================
// 19) فشل صف واحد لا يوقف بقية الصفوف (حَقن فشل حقيقي في طبقة الحفظ لصف أول فقط)
// ============================================================================
$resp19_preview = do_preview(4001, implode("\n", [
    'الاسم,رقم الجوال,ملاحظة',
    'سيفشل حفظه,0511100018,',
    'سينجح حفظه,0511100019,',
]) . "\n");
$token19 = $resp19_preview['data']['upload_token'] ?? null;
$GLOBALS['__test_throw_on_next_guest_map_save'] = true; // يفشل أول استدعاء create() فقط، ثم يُطفَأ تلقائياً
$resp19 = do_confirm(4001, $token19);
check_true('19. Confirm نجح بنيوياً رغم فشل صف واحد داخلياً', $resp19['success'] ?? false);
check('19ب. total_rows=2', $resp19['data']['summary']['total_rows'] ?? null, 2);
check('19ج. failed=1 وimported=1 (فشل صف واحد فقط، والآخر أُكمل)', [$resp19['data']['summary']['failed'] ?? null, $resp19['data']['summary']['imported'] ?? null], [1, 1]);
check_true('19د. الصف الثاني (الناجح) أُضيف فعلياً رغم فشل الأول', isset(pge_event_guests_get_map(4001)['0511100019']));

// ============================================================================
// 20) عدد Audit = المستورَد فعلياً فقط
// ============================================================================
$last_bulk_audit_20 = null;
foreach ($wpdb->audit_log as $row) {
    if ($row['action'] === 'bulk_create_completed') $last_bulk_audit_20 = $row;
}
$audit_payload_20 = json_decode($last_bulk_audit_20['reason'] ?? '{}', true);
check('20. عدد Audit لآخر عملية = imported الفعلي (سيناريو 19: 1)', $audit_payload_20['count'] ?? null, 1);

// ============================================================================
// 21) Audit لا يحوي اسم ملف/هاتف/token/مسار
// ============================================================================
$audit_reason_21 = $last_bulk_audit_20['reason'] ?? '';
check_true('21. لا يحوي الرقم 0511100018 (هاتف الصف الفاشل)', strpos($audit_reason_21, '0511100018') === false);
check_true('21ب. لا يحوي الرقم 0511100019 (هاتف الصف الناجح)', strpos($audit_reason_21, '0511100019') === false);
check_true('21ج. لا يحوي upload_token', strpos($audit_reason_21, (string) $token19) === false);
check_true('21د. لا يحوي فاصل مسار ملف (/ أو \\)', strpos($audit_reason_21, '/') === false && strpos($audit_reason_21, '\\') === false);
check('21هـ. Payload مطابق تماماً للعقد {"source":"excel","count":N}', json_decode($audit_reason_21, true), ['source' => 'excel', 'count' => 1]);

// ============================================================================
// 22-23) تنظيف مؤكَّد بعد النجاح — الملف والـtransient معاً (إعادة استخدام سيناريو 1)
// ============================================================================
check_true('22. الملف المؤقت مُحذوف فعلياً بعد النجاح (سيناريو 1)', !file_exists($transient1_path));
check('23. الـtransient محذوف فعلياً بعد النجاح (سيناريو 1)', get_transient('pge_excel_import_' . $token1), false);

// ============================================================================
// 24) تنظيف مؤكَّد بعد فشل نهائي (إعادة استخدام سيناريو 9 — invalid_file_type)
// ============================================================================
check('24. تنظيف مؤكَّد بعد فشل نهائي: الـtransient محذوف (سيناريو 9)', get_transient('pge_excel_import_' . $token9), false);

// ============================================================================
// 25) تكرار الضغط على Confirm لا يُعيد الاستيراد
// ============================================================================
$guests_count_before_repeat_25 = count(pge_event_guests_get_map(4001));
$resp25 = do_confirm(4001, $token1); // نفس token1 من سيناريو 1، مُستهلَك بالفعل
check('25. تكرار Confirm بنفس Token: ok=false', $resp25['success'] ?? true, false);
check('25ب. reason=token_not_found', $resp25['data']['reason'] ?? null, 'token_not_found');
check('25ج. عدد الضيوف لم يتغيَّر إطلاقاً (لا إعادة استيراد)', count(pge_event_guests_get_map(4001)), $guests_count_before_repeat_25);

// ============================================================================
// 26) نفس رقم الهاتف في مناسبة أخرى يُسمَح به (عزل على مستوى المناسبة)
// ============================================================================
$SHARED_PHONE_26 = '0511100020';
$resp26a_preview = do_preview(4001, "الاسم,رقم الجوال,ملاحظة\nمشترك في 4001,$SHARED_PHONE_26,\n");
$resp26a = do_confirm(4001, $resp26a_preview['data']['upload_token'] ?? null);
check('26. الرقم المشترك أُضيف بنجاح في 4001', $resp26a['data']['summary']['imported'] ?? null, 1);
$resp26b_preview = do_preview(4002, "الاسم,رقم الجوال,ملاحظة\nمشترك في 4002,$SHARED_PHONE_26,\n");
$resp26b = do_confirm(4002, $resp26b_preview['data']['upload_token'] ?? null);
check('26ب. نفس الرقم في مناسبة أخرى (4002): يُعتبَر valid وليس duplicate', $resp26b['data']['summary']['imported'] ?? null, 1);
check_true('26ج. الرقم موجود فعلياً في كلتا المناسبتين معاً', isset(pge_event_guests_get_map(4001)[$SHARED_PHONE_26]) && isset(pge_event_guests_get_map(4002)[$SHARED_PHONE_26]));

// ============================================================================
// 27) رمز الدعوة/دورة الحياة يأتيان تلقائياً من المسار الحالي (بلا أي منطق خاص هنا)
// ============================================================================
$status_map_27 = get_post_meta(4001, '_pge_invitation_status', true);
$entry_27 = $status_map_27['0511100017'] ?? null; // من سيناريو 17
check_true('27. حالة الدعوة أُنشئت تلقائياً (status=active) عبر المسار القياسي، بلا أي كود خاص هنا', is_array($entry_27) && ($entry_27['status'] ?? null) === PGE_Invitation_Repository::STATUS_ACTIVE);
check_true('27ب. invited_at مُسجَّل تلقائياً', !empty($entry_27['invited_at'] ?? null));

// ============================================================================
// 28-29) لا كتابة مباشرة على Repository ولا على خريطة الضيوف من داخل Confirm
// ============================================================================
check_true('28. لا استدعاء PGE_Invitation_Repository:: مباشر داخل معالج Confirm', strpos($confirm_body_18, 'PGE_Invitation_Repository::') === false);
check_true('29. لا استدعاء pge_event_guests_save_map( مباشر داخل معالج Confirm', strpos($confirm_body_18, 'pge_event_guests_save_map(') === false);

// ============================================================================
// 30) صحة الأعداد النهائية — دفعة مختلطة كاملة تشمل كل الحالات معاً
// ============================================================================
$DUP_PHONE_30 = '0511100021';
PGE_Invitation_Service::create(4003, $DUP_PHONE_30, 'موجود مسبقاً 30', '', $HOST_ID);
$csv_mixed_30 = implode("\n", [
    'الاسم,رقم الجوال,ملاحظة',
    'صالح واحد 30,0511100022,',
    'صالح اثنان 30,0511100023,',
    "مكرر 30,$DUP_PHONE_30,",
    ',0511100024,',       // missing_name
    'بدون جوال 30,,',      // missing_phone
    'رقم فاسد 30,###,',   // invalid_phone
    ',,',                   // empty_row
]) . "\n";
$resp30_preview = do_preview(4003, $csv_mixed_30);
$resp30 = do_confirm(4003, $resp30_preview['data']['upload_token'] ?? null);
$summary_30 = $resp30['data']['summary'] ?? [];
check('30. الملخص النهائي مطابق تماماً للمتوقع', $summary_30, [
    'total_rows'          => 7,
    'valid_before_import' => 2, // 3 صفوف "بحسب استيراد Excel" لكنها valid فقط بعد فحص التكرار الطازج (الثالث duplicate)
    'imported'            => 2,
    'duplicates'          => 1,
    'invalid'             => 3,
    'empty'               => 1,
    'failed'              => 0,
]);
check(
    '30ب. الهوية الجامعة: total_rows = imported+duplicates+invalid+empty+failed',
    $summary_30['total_rows'],
    $summary_30['imported'] + $summary_30['duplicates'] + $summary_30['invalid'] + $summary_30['empty'] + $summary_30['failed']
);

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
} else {
    echo "كل اختبارات Phase 4 (Confirm — الاستيراد الفعلي) نجحت.\n";
}
