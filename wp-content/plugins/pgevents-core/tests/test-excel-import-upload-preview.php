<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) — Phase 3 من "استيراد المدعوين من
 * Excel" (docs/EXCEL-GUEST-IMPORT-SPEC.md): ربط طبقة قراءة Excel (Phase 2)
 * بمنطق التحقق + فحص التكرار + Upload Endpoint + Preview فقط. **لا Confirm،
 * لا Import فعلي** — أي دعوة تُنشأ فعلياً في هذا الاختبار هي فقط لتهيئة حالة
 * "تكرار موجود مسبقاً" (سيناريو 4)، عبر PGE_Invitation_Service::create()
 * المُستخدَمة مباشرة كأداة تهيئة بيانات اختبار — وليست جزءاً من مسار الرفع
 * قيد الاختبار نفسه.
 *
 * يحمِّل السلسلة الحقيقية الكاملة (نفس ترتيب pgevents-core.php، ونفس نمط
 * test-rc1-fixpack3a.php) وينفِّذ المعالج الحقيقي pge_invitation_mgmt_excel_
 * preview_handler() بأسمائها مباشرة عبر AJAX حقيقي — "Do NOT create logical
 * mirrors of production code. Execute the real activation code."
 *
 * ============================================================================
 * ملاحظة منهجية — قيد بيئة الاختبار (php-wasm، موروث من Phase 2)
 * ============================================================================
 * بيئة php-wasm المستخدَمة هنا لا تملك ext-simplexml (موثَّق بالتفصيل في
 * تقرير Phase 2). بعد إضافة try/catch دفاعي حول \Shuchkin\SimpleXLSX::parse()
 * في class-pge-invitation-excel-import.php (Phase 3 hardening)، أي رفع xlsx
 * حقيقي هنا سينتهي بـ error='xlsx_parse_error' بدلاً من محتوى مُحلَّل فعلياً
 * — هذا سلوك بيئة الاختبار فقط، وليس عيباً إنتاجياً (استضافة WordPress
 * حقيقية تملك ext-simplexml افتراضياً). سيناريو 1 (رفع XLSX صحيح) لذلك
 * مقسَّم إلى: (1أ) اختبار end-to-end حقيقي كامل للـUpload Endpoint يثبت أن
 * الملف يُخزَّن بشكل صحيح والـ transient يُنشأ بشكل صحيح ولا يحدث Fatal، رغم
 * فشل التحليل العميق بسبب قيد البيئة، و(1ب) اختبار تنفيذي حقيقي لمنطق Phase 3
 * الفعلي (apply_duplicate_detection/summarize_preview) بمدخلات بشكل rowsEx()
 * الرسمي (نفس منهجية Phase 2 المُتَّبعة والمُفصَح عنها هناك).
 *
 * التشغيل: node run-generic3.mjs (أو مكافئه) — راجع تعليق الهارنس المخصَّص.
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

// ── Post Meta وهمية ──────────────────────────────────────────────────────
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

// ── Transients وهمية (in-memory) — نفس عقد set/get/delete_transient الحقيقي ─
$GLOBALS['__test_transients'] = [];
function set_transient($key, $value, $expiration = 0) { $GLOBALS['__test_transients'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['__test_transients'][$key] ?? false; }
function delete_transient($key) { unset($GLOBALS['__test_transients'][$key]); return true; }

// ── Uploads dir وهمية — مسار حقيقي قابل للكتابة داخل VFS الاختبار ──────────
function wp_upload_dir()
{
    $base = sys_get_temp_dir() . '/pge-test-uploads';
    if (!is_dir($base)) { @mkdir($base, 0777, true); }
    return ['basedir' => $base, 'baseurl' => 'http://example.test/wp-content/uploads', 'path' => $base, 'url' => 'http://example.test/wp-content/uploads'];
}
function wp_mkdir_p($dir) { if (!is_dir($dir)) { return (bool) @mkdir($dir, 0777, true); } return true; }

/**
 * القسم Upload Endpoint: `is_uploaded_file()`/`move_uploaded_file()` الحقيقيتان
 * تتطلبان سياق رفع HTTP فعلياً (PHP الأساسية) — غير مُحاكاة ممكنة في CLI. هذا
 * الاختبار يُعرِّف الأغلفة القابلة للاستبدال المُعرَّفة في invitation-
 * management-ajax.php نفسها (بنفس اصطلاح function_exists() guard) *قبل*
 * تحميل ذلك الملف، فتُستخدَم نسخة الاختبار (rename/copy حقيقيين على القرص)
 * بدل النسخة الإنتاجية — لا تغيير في سلوك الإنتاج الفعلي.
 */
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
if (!class_exists('Test_Wp_Die_Exception_P3')) { class Test_Wp_Die_Exception_P3 extends \Exception {} }

function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception_P3('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception_P3('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception_P3('wp_die'); }

function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try { $handler(); } catch (Test_Wp_Die_Exception_P3 $e) { /* متوقَّع */ }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

/**
 * يبني ملف مصدر مؤقت حقيقي على القرص بمحتوى مُعطى، ويُعيد مصفوفة $_FILES
 * صالحة الشكل تماماً كما يُنتِجها PHP فعلياً عند رفع حقيقي.
 */
function make_upload_tmp_file(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'pge_upload_src_');
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

// ── Fake $wpdb — نفس نمط test-rc1-fixpack3a.php حرفياً ─────────────────────
class Fake_Wpdb_Excel_P3
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
$GLOBALS['wpdb'] = new Fake_Wpdb_Excel_P3();
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

/**
 * استخراج جسم دالة مُسمَّاة بدقة عبر عدّ الأقواس المتوازن (لا regex تخمينية
 * قد تُخطئ في حالات متداخلة) — تُستخدَم في سيناريو 13 (لا استدعاء create())
 * لتضييق نطاق الفحص النصّي إلى معالج الرفع الجديد حصراً، دون أن يُصادف
 * استدعاءات create() المشروعة الموجودة في معالجات أخرى بنفس الملف.
 */
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

echo "=== Phase 3 — Excel Import: Upload + Validate + Duplicate Detection + Preview ===\n";

// ============================================================================
// إعداد: مضيف يملك المناسبة 3001، مناسبة أخرى 3002 لعزل الأحداث
// ============================================================================
$HOST_ID = 601;
set_test_event(3001, $HOST_ID);
set_test_event(3002, $HOST_ID);
$GLOBALS['__test_current_user_id'] = $HOST_ID;
$GLOBALS['__test_logged_in'] = true;

// دعوة مسبقة في المناسبة 3001 (لاختبار duplicate) — أداة تهيئة بيانات فقط،
// ليست جزءاً من مسار الرفع قيد الاختبار.
PGE_Invitation_Service::create(3001, '0599111111', 'ضيف سابق', '', $HOST_ID);
$guests_before_3001 = count(pge_event_guests_get_map(3001));

// ============================================================================
// 1أ) رفع XLSX صحيح — end-to-end حقيقي عبر AJAX الحقيقي (تخزين + transient)
// ============================================================================
$xlsx_binary = PGE_Xlsx_Writer::build(
    [['الاسم', 'رقم الجوال', 'ملاحظة'], ['محمد أحمد', '0599222222', '']],
    [1]
);
$_FILES = ['file' => make_files_field($xlsx_binary, 'guests.xlsx')];
$_POST = make_post_fields(3001);
$resp_xlsx = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check_true('1أ. رفع XLSX: لا Fatal/استثناء غير مُعالَج (استجابة JSON مُلتقَطة فعلياً)', $GLOBALS['__test_json_response'] !== null);
$xlsx_token = $resp_xlsx['data']['upload_token'] ?? null;
check_true('1أ-2. رفع XLSX: upload_token أُعيد للمتصفح', is_string($xlsx_token) && strlen($xlsx_token) >= 16);
$xlsx_transient = get_transient('pge_excel_import_' . $xlsx_token);
check_true('1أ-3. رفع XLSX: transient أُنشئ فعلياً', $xlsx_transient !== false);
check('1أ-4. رفع XLSX: file_type المخزَّن = xlsx', $xlsx_transient['file_type'] ?? null, 'xlsx');
check_true('1أ-5. رفع XLSX: الملف مُخزَّن فعلياً على القرص بامتداد xlsx صحيح', is_file($xlsx_transient['file_path'] ?? '') && substr($xlsx_transient['file_path'], -5) === '.xlsx');
check_true('1أ-6. رفع XLSX: مسار التخزين يحوي event_id ضمن البنية (عزل لكل مناسبة)', strpos($xlsx_transient['file_path'], DIRECTORY_SEPARATOR . '3001' . DIRECTORY_SEPARATOR) !== false || strpos($xlsx_transient['file_path'], '/3001/') !== false);
// ملاحظة بيئة الاختبار (راجع التعليق العلوي): parse قد يفشل بـxlsx_parse_error
// بسبب غياب ext-simplexml في هذا الـsandbox تحديداً — نتحقق أن الاستجابة
// إما نجاح حقيقي أو رفض نظيف بهذا السبب تحديداً، وليس أي شيء آخر غير متوقَّع.
$xlsx_ok = $resp_xlsx['success'] ?? false;
$xlsx_reason = $resp_xlsx['data']['reason'] ?? null;
check_true('1أ-7. رفع XLSX: النتيجة إما نجاح حقيقي أو فشل مُعالَج بلطف تحديداً بسبب قيد بيئة الاختبار (xlsx_parse_error) — لا أي فشل آخر', $xlsx_ok === true || $xlsx_reason === 'xlsx_parse_error');

// ============================================================================
// 1ب) رفع XLSX صحيح — تنفيذ حقيقي لمنطق Phase 3 (dedup+summary) على بيانات
// بشكل rowsEx() الرسمي (نفس منهجية Phase 2 المُفصَح عنها لتفادي قيد ext-simplexml)
// ============================================================================
function xcell($type, $value) { return ['type' => $type, 'name' => '', 'value' => $value, 'href' => '', 'f' => '', 'format' => '', 's' => 0, 'css' => '', 'r' => '', 'hidden' => false, 'width' => 0, 'height' => 0, 'comment' => '']; }
$rows_ex = [
    [xcell('s', 'الاسم'), xcell('s', 'رقم الجوال'), xcell('s', 'ملاحظة')],
    [xcell('s', 'خالد سالم'), xcell('s', '0599333333'), xcell('s', '')],
    [xcell('s', 'ضيف سابق مكرر'), xcell('s', '0599111111'), xcell('s', '')], // مكرر مع 3001
];
$parsed_xlsx_shape = PGE_Invitation_Excel_Import_Service::build_from_rows_ex($rows_ex);
check_true('1ب. build_from_rows_ex (شكل XLSX رسمي): نجح بنيوياً', $parsed_xlsx_shape['ok']);
$rows_1b = $parsed_xlsx_shape['rows'];
PGE_Invitation_Excel_Import_Service::apply_duplicate_detection(3001, $rows_1b);
check('1ب-2. الصف الأول (رقم جديد) status=valid', $rows_1b[0]['status'], 'valid');
check('1ب-3. الصف الثاني (رقم موجود مسبقاً في 3001) status=duplicate', $rows_1b[1]['status'], 'duplicate');

// ============================================================================
// 2) رفع CSV صحيح — end-to-end حقيقي كامل
// ============================================================================
$csv_valid = "الاسم,رقم الجوال,ملاحظة\nسارة خالد,0599444444,ملاحظة تجريبية\n";
$_FILES = ['file' => make_files_field($csv_valid, 'guests.csv')];
$_POST = make_post_fields(3001);
$resp_csv = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check_true('2. رفع CSV صحيح: ok=true', $resp_csv['success'] ?? false);
check('2ب. صف واحد في المعاينة', count($resp_csv['data']['rows'] ?? []), 1);
check('2ج. status=valid', $resp_csv['data']['rows'][0]['status'] ?? null, 'valid');
check('2د. status_label يحمل ✅', $resp_csv['data']['rows'][0]['status_label'] ?? '', '✅ صالح');
check_true('2هـ. upload_token أُعيد', is_string($resp_csv['data']['upload_token'] ?? null));
check('2و. عبارة "سيتم تنفيذ الاستيراد في المرحلة التالية" ظاهرة (لا Confirm بعد)', $resp_csv['data']['note'] ?? '', 'سيتم تنفيذ الاستيراد في المرحلة التالية.');

// ============================================================================
// 3) ملف غير مدعوم — رفض واضح، بلا محاولة قراءة
// ============================================================================
$_FILES = ['file' => make_files_field('PDF-1.4 fake content', 'guests.pdf')];
$_POST = make_post_fields(3001);
$resp_unsupported = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check('3. ملف .pdf: ok=false', $resp_unsupported['success'] ?? true, false);
check('3ب. reason=unsupported_extension', $resp_unsupported['data']['reason'] ?? null, 'unsupported_extension');

// .xls (الصيغة القديمة) — غير مدعومة صراحة حسب القسم 4 من الوثيقة.
$_FILES = ['file' => make_files_field('binary xls fake', 'guests.xls')];
$_POST = make_post_fields(3001);
$resp_xls = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check('3ج. ملف .xls: ok=false (غير مدعوم في هذا الإصدار)', $resp_xls['success'] ?? true, false);
check('3د. reason=unsupported_extension', $resp_xls['data']['reason'] ?? null, 'unsupported_extension');

// ============================================================================
// 4) Duplicate داخل المناسبة — end-to-end حقيقي كامل
// ============================================================================
$csv_dup = "الاسم,رقم الجوال,ملاحظة\nمكرر فعلي,0599111111,\nجديد كلياً,0599555555,\n";
$_FILES = ['file' => make_files_field($csv_dup, 'guests.csv')];
$_POST = make_post_fields(3001);
$resp_dup = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check_true('4. Duplicate: ok=true (لا يُسقِط الملف)', $resp_dup['success'] ?? false);
check('4ب. الصف الأول (رقم موجود مسبقاً) status=duplicate', $resp_dup['data']['rows'][0]['status'] ?? null, 'duplicate');
check('4ج. الصف الثاني (رقم جديد) status=valid', $resp_dup['data']['rows'][1]['status'] ?? null, 'valid');
check('4د. status_label للمكرر يحمل ⚠', $resp_dup['data']['rows'][0]['status_label'] ?? '', '⚠ مكرر');

// نفس الرقم 0599111111 في مناسبة أخرى (3002) لا يُعتبَر تكراراً — عزل المناسبات.
$_FILES = ['file' => make_files_field("الاسم,رقم الجوال,ملاحظة\nضيف مناسبة أخرى,0599111111,\n", 'guests.csv')];
$_POST = make_post_fields(3002);
$resp_dup_other_event = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check('4هـ. نفس الرقم في مناسبة أخرى (3002): status=valid (عزل المناسبات)', $resp_dup_other_event['data']['rows'][0]['status'] ?? null, 'valid');

// ============================================================================
// 5) رقم غير صالح — CSV: نص لا يحوي أي رقم بعد pge_norm_phone()
// ============================================================================
$csv_invalid_phone = "الاسم,رقم الجوال,ملاحظة\nفهد ناصر,غير-رقم-إطلاقاً,\n";
$_FILES = ['file' => make_files_field($csv_invalid_phone, 'guests.csv')];
$_POST = make_post_fields(3001);
$resp_invalid_phone = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check_true('5. رقم غير صالح: ok=true (لا يُسقِط الملف)', $resp_invalid_phone['success'] ?? false);
check('5ب. status=invalid_phone', $resp_invalid_phone['data']['rows'][0]['status'] ?? null, 'invalid_phone');
check('5ج. status_label "رقم غير صالح"', $resp_invalid_phone['data']['rows'][0]['status_label'] ?? '', '⚠ رقم غير صالح');

// ============================================================================
// 6) missing_name
// ============================================================================
$csv_missing_name = "الاسم,رقم الجوال,ملاحظة\n,0599666666,\n";
$_FILES = ['file' => make_files_field($csv_missing_name, 'guests.csv')];
$_POST = make_post_fields(3001);
$resp_missing_name = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check('6. missing_name: status صحيح', $resp_missing_name['data']['rows'][0]['status'] ?? null, 'missing_name');

// ============================================================================
// 7) missing_phone
// ============================================================================
$csv_missing_phone = "الاسم,رقم الجوال,ملاحظة\nبدون جوال,,\n";
$_FILES = ['file' => make_files_field($csv_missing_phone, 'guests.csv')];
$_POST = make_post_fields(3001);
$resp_missing_phone = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check('7. missing_phone: status صحيح', $resp_missing_phone['data']['rows'][0]['status'] ?? null, 'missing_phone');

// ============================================================================
// 8) Preview counts — دفعة مختلطة كاملة (كل الحالات الست معاً)
// ============================================================================
$csv_mixed = implode("\n", [
    'الاسم,رقم الجوال,ملاحظة',
    'صالح واحد,0599777777,',       // valid
    'صالح اثنان,0599888888,',      // valid
    'مكرر فعلي,0599111111,',       // duplicate
    ',0599999999,',                 // missing_name
    'بدون جوال,,',                  // missing_phone
    'رقم فاسد,***,',                // invalid_phone
    ',,',                            // empty_row
]) . "\n";
$_FILES = ['file' => make_files_field($csv_mixed, 'guests.csv')];
$_POST = make_post_fields(3001);
$resp_mixed = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check_true('8. دفعة مختلطة: ok=true', $resp_mixed['success'] ?? false);
$summary_mixed = $resp_mixed['data']['summary'] ?? [];
check('8ب. الملخص يطابق التوقع بالكامل', $summary_mixed, [
    'total'         => 7,
    'valid'         => 2,
    'duplicate'     => 1,
    'invalid_phone' => 1,
    'missing_name'  => 1,
    'missing_phone' => 1,
    'empty_row'     => 1,
]);
check('8ج. total = مجموع كل التصنيفات (لا صف مفقود من الملخص)', $summary_mixed['total'], $summary_mixed['valid'] + $summary_mixed['duplicate'] + $summary_mixed['invalid_phone'] + $summary_mixed['missing_name'] + $summary_mixed['missing_phone'] + $summary_mixed['empty_row']);

// ============================================================================
// 9-10) upload_token مرتبط بالمستخدم/المناسبة الصحيحين
// ============================================================================
$csv_binding = "الاسم,رقم الجوال,ملاحظة\nربط التوكن,0599123123,\n";
$_FILES = ['file' => make_files_field($csv_binding, 'guests.csv')];
$_POST = make_post_fields(3001);
$resp_binding = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
$binding_token = $resp_binding['data']['upload_token'] ?? null;
$binding_transient = get_transient('pge_excel_import_' . $binding_token);
check('9. upload_token مرتبط بالمستخدم الصحيح (user_id)', $binding_transient['user_id'] ?? null, $HOST_ID);
check('10. upload_token مرتبط بالمناسبة الصحيحة (event_id)', $binding_transient['event_id'] ?? null, 3001);

// ============================================================================
// 11) رفض Token غير صالح — لا ربط لأي token عشوائي غير موجود فعلياً
// ============================================================================
$bogus_token = bin2hex(random_bytes(16));
check('11. Token عشوائي غير موجود: get_transient يُعيد false (لا ربط وهمي)', get_transient('pge_excel_import_' . $bogus_token), false);

// ============================================================================
// 12) عدم كتابة أي بيانات — عدد ضيوف المناسبتين لم يتغيَّر عبر كل الرفعات أعلاه
// ============================================================================
check('12. لا كتابة بيانات: عدد ضيوف المناسبة 3001 لم يتغيَّر منذ التهيئة', count(pge_event_guests_get_map(3001)), $guests_before_3001);
check('12ب. عدد ضيوف المناسبة 3002 = صفر (لم يُنشأ فيها أي شيء إطلاقاً)', count(pge_event_guests_get_map(3002)), 0);

// ============================================================================
// 13) عدم استدعاء create() — فحص نصّي مضيَّق على جسم المعالج الجديد فقط
// ============================================================================
// $__pge_ajax_source_override يسمح لهارنس الاختبار (بيئات لا تُحلّ فيها
// __DIR__ الحقيقي، مثل php-wasm) بحقن بايتات الملف الحقيقية مباشرة؛ في أي
// بيئة PHP حقيقية (إنتاج/CI) يُقرأ الملف من القرص فعلياً كالمعتاد.
$ajax_source = isset($__pge_ajax_source_override)
    ? $__pge_ajax_source_override
    : file_get_contents(__DIR__ . '/../includes/invitation-management-ajax.php');
check_true('13. تم تحميل مصدر invitation-management-ajax.php للفحص', strlen($ajax_source) > 0);
$handler_body = extract_function_body($ajax_source, 'pge_invitation_mgmt_excel_preview_handler');
check_true('13ب. تم استخراج جسم pge_invitation_mgmt_excel_preview_handler() بنجاح', $handler_body !== '');
check_true('13ج. لا استدعاء PGE_Invitation_Service::create( داخل معالج الرفع نفسه', strpos($handler_body, 'PGE_Invitation_Service::create(') === false);
check_true('13د. لا استدعاء PGE_Invitation_Bulk_Add_Service داخل معالج الرفع نفسه', strpos($handler_body, 'PGE_Invitation_Bulk_Add_Service') === false);
check_true('13هـ. لا استدعاء PGE_Invitation_Management_Audit داخل معالج الرفع نفسه', strpos($handler_body, 'PGE_Invitation_Management_Audit') === false);
check_true('13و. لا unlink()/حذف ملف داخل معالج الرفع نفسه (ممنوع صراحة في Phase 3)', strpos($handler_body, 'unlink(') === false);
check_true('13ز. تأكيد أن الفحص كان سيلتقط استدعاء create() فعلياً لو وُجد (سلامة المنهجية) — موجود في معالج آخر بنفس الملف', strpos($ajax_source, 'PGE_Invitation_Service::create(') !== false);

// ============================================================================
// تفويض — مستخدم غير مصرَّح له يُرفَض عبر AJAX الحقيقي، بلا وصول لأي رفع
// ============================================================================
$GLOBALS['__test_current_user_id'] = 999; // ليس مالك المناسبة 3001 ولا أدمن.
$_FILES = ['file' => make_files_field($csv_valid, 'guests.csv')];
$_POST = make_post_fields(3001);
$resp_unauth = call_ajax_handler('pge_invitation_mgmt_excel_preview_handler');
check('14. مستخدم غير مصرَّح: يُرفَض (forbidden)', [$resp_unauth['success'], $resp_unauth['data']['reason'] ?? null], [false, 'forbidden']);
$GLOBALS['__test_current_user_id'] = $HOST_ID;

// ============================================================================
// انحدار سريع مضمَّن: التنزيل (Phase 1) لا يزال يعمل بعد إضافة Phase 3
// ============================================================================
$_POST = make_post_fields(3001);
ob_start();
try { pge_invitation_mgmt_excel_template_handler(); } catch (Test_Wp_Die_Exception_P3 $e) { /* متوقَّع */ }
$template_binary = ob_get_clean();
check_true('15. انحدار مضمَّن: تنزيل نموذج Excel (Phase 1) لا يزال يعمل وينتج محتوى', strlen($template_binary) > 100);

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
} else {
    echo "كل اختبارات Phase 3 (Upload + Validate + Duplicate Detection + Preview) نجحت.\n";
}
