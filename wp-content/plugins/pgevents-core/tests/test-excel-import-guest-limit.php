<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) — ميزة "حدود الباقة" (Guest Limit/
 * Package Quota) لاستيراد Excel: pge_resolve_guest_quota_status() الجديدة
 * في helpers.php، حقل quota الإضافي في استجابة Preview
 * (pge_invitation_mgmt_excel_preview_handler)، وسقف الاستيراد الفعلي في
 * Confirm (pge_invitation_mgmt_excel_confirm_handler) — كلها تُنفَّذ عبر
 * أسمائها الحقيقية مباشرة، بلا أي محاكاة منطقية موازية.
 *
 * نفس هيكل/أسلوب test-excel-import-confirm.php (Phase 4) بالضبط — نفس
 * الـstubs، نفس نمط do_preview()/do_confirm()، إعادة استخدام صريحة.
 *
 * الفرق الوحيد المضاف: stub لـ pge_get_user_plan_limits_for_events() (الدالة
 * الحقيقية معرَّفة في event-factory.php، الذي لا يُحمَّل هنا لتجنّب سلسلة
 * تبعياته الأخرى غير ذات الصلة — بنفس روح stub الـpge_generate_invite_code()
 * الموجود مسبقاً في هذا المشروع لاختبارات مشابهة). الدالة الحقيقية قيد
 * الاختبار هي pge_resolve_guest_quota_status() نفسها (من helpers.php، غير
 * مُعاد تعريفها هنا إطلاقاً) — هي من تستدعي stub الحد هذا عبر
 * function_exists()، تماماً كما يحدث في الإنتاج مع الدالة الحقيقية.
 *
 * التشغيل: node build-phase3.mjs test-excel-import-guest-limit.php ثم
 * node run-phase3.mjs (أو run-generic2.mjs المكافئ).
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
if (!function_exists('current_time')) { function current_time($type = 'mysql', $gmt = 0) { return '2026-08-08 00:00:00'; } }
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
        return 'CODE-GL-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
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

// ── Guest Limit للمستخدم (حدود الباقة) — stub لـ
// pge_get_user_plan_limits_for_events() الحقيقية (event-factory.php)، غير
// مُحمَّلة هنا عمداً لتفادي سلسلة تبعياتها الأخرى غير ذات الصلة بهذا
// الاختبار. الدالة قيد الاختبار الفعلي هي pge_resolve_guest_quota_status()
// نفسها من helpers.php — هي من تستدعي هذا الـstub عبر function_exists()،
// تماماً بنفس الآلية التي تستدعي بها الدالة الحقيقية في الإنتاج. القيمة
// الافتراضية 0 لأي مستخدم غير مضبوط تطابق تماماً اصطلاح "0 = بلا حد" الحقيقي.
$GLOBALS['__test_user_guest_limits'] = [];
function set_test_user_guest_limit($user_id, $limit) { $GLOBALS['__test_user_guest_limits'][$user_id] = $limit; }
if (!function_exists('pge_get_user_plan_limits_for_events')) {
    function pge_get_user_plan_limits_for_events($user_id)
    {
        return ['guest_limit' => $GLOBALS['__test_user_guest_limits'][$user_id] ?? 0];
    }
}

// ── Transients وهمية (in-memory) ────────────────────────────────────────
$GLOBALS['__test_transients'] = [];
function set_transient($key, $value, $expiration = 0) { $GLOBALS['__test_transients'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['__test_transients'][$key] ?? false; }
function delete_transient($key) { unset($GLOBALS['__test_transients'][$key]); return true; }

// ── Uploads dir وهمية ────────────────────────────────────────────────────
function wp_upload_dir()
{
    $base = sys_get_temp_dir() . '/pge-test-uploads-gl';
    if (!is_dir($base)) { @mkdir($base, 0777, true); }
    return ['basedir' => $base, 'baseurl' => 'http://example.test/wp-content/uploads', 'path' => $base, 'url' => 'http://example.test/wp-content/uploads'];
}
function wp_mkdir_p($dir) { if (!is_dir($dir)) { return (bool) @mkdir($dir, 0777, true); } return true; }

function pge_invitation_mgmt_is_uploaded_file(string $path): bool { return $path !== '' && is_file($path); }
function pge_invitation_mgmt_move_uploaded_file(string $src, string $dst): bool
{
    if (@rename($src, $dst)) return true;
    return (bool) @copy($src, $dst);
}

// ── Stubs AJAX/JSON ──────────────────────────────────────────────────────
if (!class_exists('Test_Wp_Die_Exception_GL')) { class Test_Wp_Die_Exception_GL extends \Exception {} }

function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception_GL('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception_GL('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception_GL('wp_die'); }

function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try { $handler(); } catch (Test_Wp_Die_Exception_GL $e) { /* متوقَّع */ }
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
    $path = tempnam(sys_get_temp_dir(), 'pge_upload_src_gl_');
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

// ── Fake $wpdb ───────────────────────────────────────────────────────────
class Fake_Wpdb_Excel_GL
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

    // Guest Limit Unification RFC: محاكاة GET_LOCK/RELEASE_LOCK لقفل
    // PGE_Invitation_Service::create() — نفس نمط Fake_Wpdb في
    // test-invitation-credit-ledger.php.
    private $held_locks = [];
    public function get_var($sql)
    {
        if (preg_match("/SELECT\\s+GET_LOCK\\('([^']*)',\\s*(-?\\d+)\\)/i", $sql, $m)) {
            $name = $m[1];
            if (isset($this->held_locks[$name])) return '0';
            $this->held_locks[$name] = true;
            return '1';
        }
        return null;
    }
    public function query($sql)
    {
        if (preg_match("/SELECT\\s+RELEASE_LOCK\\('([^']*)'\\)/i", $sql, $m)) {
            unset($this->held_locks[$m[1]]);
            return 1;
        }
        return 0;
    }
}
$GLOBALS['wpdb'] = new Fake_Wpdb_Excel_GL();
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
function csv_rows(array $rows): string
{
    $out = "الاسم,رقم الجوال,ملاحظة\n";
    foreach ($rows as $r) { $out .= $r[0] . ',' . $r[1] . ',' . ($r[2] ?? '') . "\n"; }
    return $out;
}

echo "=== حدود الباقة (Guest Limit/Package Quota) — استيراد Excel ===\n";

$HOST_UNLIMITED = 801; // بلا guest_limit مضبوط إطلاقاً (0 = بلا حد)
$HOST_LIMITED   = 802; // guest_limit يُضبَط لكل سيناريو بالقيمة اللازمة
set_test_event(5001, $HOST_UNLIMITED); // سيناريو A/B/C — غير محدود
set_test_event(5002, $HOST_LIMITED);   // سيناريو B — الحد يتجاوَز عدد الصفوف
set_test_event(5003, $HOST_LIMITED);   // سيناريو B — الباقة ممتلئة مسبقاً
set_test_event(5004, $HOST_LIMITED);   // سيناريو B — الحد أكبر من الصفوف (لا تجاوز)
set_test_event(5005, $HOST_LIMITED);   // سيناريو C — تحديد الاستيراد الفعلي عند Confirm
set_test_event(5006, $HOST_LIMITED);   // سيناريو D — إعادة الحساب الطازجة (Race Condition)
set_test_event(5007, $HOST_LIMITED);   // سيناريو F — مكرَّر لا يستهلك الحصة

// ============================================================================
// A) pge_resolve_guest_quota_status() مباشرة — اختبار وحدة للـresolver نفسه
// ============================================================================
set_test_user_guest_limit($HOST_UNLIMITED, 0);
$qa1 = pge_resolve_guest_quota_status(5001);
check('A1. guest_limit=0: mode=unlimited', $qa1['mode'], 'unlimited');
check('A1ب. guest_limit=0: limit=null', $qa1['limit'], null);
check('A1ج. guest_limit=0: remaining=null', $qa1['remaining'], null);
check('A1د. guest_limit=0: current=0 (لا مدعوين بعد)', $qa1['current'], 0);

set_test_user_guest_limit($HOST_LIMITED, 5);
$qa2 = pge_resolve_guest_quota_status(5002);
check('A2. guest_limit=5, current=0: mode=limited', $qa2['mode'], 'limited');
check('A2ب. remaining=5', $qa2['remaining'], 5);

PGE_Invitation_Service::create(5002, '0522200001', 'ضيف أ', '', $HOST_LIMITED);
PGE_Invitation_Service::create(5002, '0522200002', 'ضيف ب', '', $HOST_LIMITED);
$qa3 = pge_resolve_guest_quota_status(5002);
check('A3. بعد إضافة ضيفين يدوياً: current=2', $qa3['current'], 2);
check('A3ب. remaining=3 (5-2)', $qa3['remaining'], 3);

for ($i = 3; $i <= 5; $i++) { PGE_Invitation_Service::create(5002, '05222000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'ضيف ' . $i, '', $HOST_LIMITED); }
$qa4 = pge_resolve_guest_quota_status(5002);
check('A4. الباقة ممتلئة تماماً (current=limit=5): remaining=0', $qa4['remaining'], 0);

// محاكاة تجاوز فعلي (نادر لكن ممكن نظرياً، مثلاً بعد تخفيض الحد إدارياً) —
// remaining لا يصبح سالباً أبداً (max(0, ...)).
set_test_user_guest_limit($HOST_LIMITED, 3); // تخفيض الحد إلى أقل من current=5
$qa5 = pge_resolve_guest_quota_status(5002);
check('A5. current=5 > limit=3 المخفَّض: remaining=0 وليس سالباً', $qa5['remaining'], 0);
set_test_user_guest_limit($HOST_LIMITED, 5); // إعادة الحد لقيمته الطبيعية لبقية السيناريوهات

// ============================================================================
// B) حقل quota في استجابة Preview — بلا أي تغيير في summary/rows الحاليين
// ============================================================================

// B1) مضيف بلا حد (unlimited): quota.mode=unlimited، بلا سقف على will_import
$GLOBALS['__test_current_user_id'] = $HOST_UNLIMITED;
$respB1 = do_preview(5001, csv_rows([['ب1 ضيف1', '0522300001'], ['ب1 ضيف2', '0522300002']]));
check_true('B1. Preview نجح (مضيف بلا حد)', $respB1['success'] ?? false);
check('B1ب. quota.mode=unlimited', $respB1['data']['quota']['mode'] ?? null, 'unlimited');
check('B1ج. quota.limit=null', $respB1['data']['quota']['limit'], null);
check('B1د. quota.remaining=null', $respB1['data']['quota']['remaining'], null);
check('B1هـ. quota.valid_rows=2', $respB1['data']['quota']['valid_rows'] ?? null, 2);
check('B1و. quota.will_import=2 (بلا سقف)', $respB1['data']['quota']['will_import'] ?? null, 2);
check('B1ز. quota.quota_exceeded=false', $respB1['data']['quota']['quota_exceeded'] ?? null, false);
check_true('B1ح. summary.valid لم يتأثر إطلاقاً (لا يزال 2)', ($respB1['data']['summary']['valid'] ?? null) === 2);

// B2) مضيف محدود، الصفوف الصالحة (5) تتجاوز الحصة المتبقية (3، مصدره: مناسبة 5001 مختلفة عن الـuser meta، الحد هنا حصراً على 5002 المُستخدَم — لذا نضبط حداً منفصلاً لحدث 5002 نظيفاً عبر مضيف جديد)
$HOST_B2 = 803;
set_test_user_guest_limit($HOST_B2, 3);
set_test_event(5010, $HOST_B2);
$GLOBALS['__test_current_user_id'] = $HOST_B2;
$respB2 = do_preview(5010, csv_rows([
    ['ب2 ضيف1', '0522300010'], ['ب2 ضيف2', '0522300011'], ['ب2 ضيف3', '0522300012'],
    ['ب2 ضيف4', '0522300013'], ['ب2 ضيف5', '0522300014'],
]));
check('B2. quota.mode=limited', $respB2['data']['quota']['mode'] ?? null, 'limited');
check('B2ب. quota.limit=3', $respB2['data']['quota']['limit'] ?? null, 3);
check('B2ج. quota.current=0', $respB2['data']['quota']['current'] ?? null, 0);
check('B2د. quota.remaining=3', $respB2['data']['quota']['remaining'] ?? null, 3);
check('B2هـ. quota.valid_rows=5', $respB2['data']['quota']['valid_rows'] ?? null, 5);
check('B2و. quota.will_import=3 (مقتصر على المتبقي)', $respB2['data']['quota']['will_import'] ?? null, 3);
check('B2ز. quota.quota_exceeded=true', $respB2['data']['quota']['quota_exceeded'] ?? null, true);

// B3) مضيف محدود، الباقة ممتلئة مسبقاً بالكامل (remaining=0)
$HOST_B3 = 804;
set_test_user_guest_limit($HOST_B3, 2);
set_test_event(5011, $HOST_B3);
PGE_Invitation_Service::create(5011, '0522300020', 'موجود1', '', $HOST_B3);
PGE_Invitation_Service::create(5011, '0522300021', 'موجود2', '', $HOST_B3);
$GLOBALS['__test_current_user_id'] = $HOST_B3;
$respB3 = do_preview(5011, csv_rows([['ب3 ضيف جديد', '0522300022']]));
check('B3. الباقة ممتلئة: quota.remaining=0', $respB3['data']['quota']['remaining'] ?? null, 0);
check('B3ب. quota.will_import=0', $respB3['data']['quota']['will_import'] ?? null, 0);
check('B3ج. quota.quota_exceeded=true', $respB3['data']['quota']['quota_exceeded'] ?? null, true);

// B4) مضيف محدود، الحد أكبر من عدد الصفوف الصالحة — لا تجاوز إطلاقاً
$HOST_B4 = 805;
set_test_user_guest_limit($HOST_B4, 10);
set_test_event(5012, $HOST_B4);
PGE_Invitation_Service::create(5012, '0522300030', 'موجود مسبقاً', '', $HOST_B4);
$GLOBALS['__test_current_user_id'] = $HOST_B4;
$respB4 = do_preview(5012, csv_rows([['ب4 ضيف1', '0522300031'], ['ب4 ضيف2', '0522300032'], ['ب4 ضيف3', '0522300033']]));
check('B4. remaining=9 (10-1)', $respB4['data']['quota']['remaining'] ?? null, 9);
check('B4ب. valid_rows=3، will_import=3 (بلا سقف لأن 3<=9)', [$respB4['data']['quota']['valid_rows'] ?? null, $respB4['data']['quota']['will_import'] ?? null], [3, 3]);
check('B4ج. quota_exceeded=false', $respB4['data']['quota']['quota_exceeded'] ?? null, false);

// ============================================================================
// C) سقف الاستيراد الفعلي عند Confirm
// ============================================================================

// C1) مضيف محدود (limit=3, current=0)، 5 صفوف صالحة → يُستورَد 3 فقط، والباقي quota_exceeded
$HOST_C1 = 806;
set_test_user_guest_limit($HOST_C1, 3);
set_test_event(5020, $HOST_C1);
$GLOBALS['__test_current_user_id'] = $HOST_C1;
$respC1_preview = do_preview(5020, csv_rows([
    ['ج1 ضيف1', '0522400001'], ['ج1 ضيف2', '0522400002'], ['ج1 ضيف3', '0522400003'],
    ['ج1 ضيف4', '0522400004'], ['ج1 ضيف5', '0522400005'],
]));
$tokenC1 = $respC1_preview['data']['upload_token'] ?? null;
$respC1 = do_confirm(5020, $tokenC1);
check_true('C1. Confirm نجح بنيوياً', $respC1['success'] ?? false);
check('C1ب. imported=3 (مقتصر على الحد المتبقي)', $respC1['data']['summary']['imported'] ?? null, 3);
check('C1ج. quota_exceeded=2 (5 صالحة - 3 مستوردة)', $respC1['data']['summary']['quota_exceeded'] ?? null, 2);
check('C1د. الهوية الجامعة الكاملة: total_rows=5=imported+duplicates+invalid+empty+failed+quota_exceeded', $respC1['data']['summary']['total_rows'] ?? null, 5);
check('C1هـ. عدد الضيوف الفعلي في المناسبة = 3 (ليس 5)', count(pge_event_guests_get_map(5020)), 3);
check('C1و. أول 3 صفوف result=imported', [$respC1['data']['rows'][0]['result'] ?? null, $respC1['data']['rows'][1]['result'] ?? null, $respC1['data']['rows'][2]['result'] ?? null], ['imported', 'imported', 'imported']);
check('C1ز. آخر صفين result=quota_exceeded', [$respC1['data']['rows'][3]['result'] ?? null, $respC1['data']['rows'][4]['result'] ?? null], ['quota_exceeded', 'quota_exceeded']);

// C2) مضيف بلا حد (unlimited) — لا تغيير عن سلوك ما قبل هذه الميزة إطلاقاً
$GLOBALS['__test_current_user_id'] = $HOST_UNLIMITED;
$respC2_preview = do_preview(5001, csv_rows([
    ['ج2 ضيف1', '0522400010'], ['ج2 ضيف2', '0522400011'], ['ج2 ضيف3', '0522400012'],
    ['ج2 ضيف4', '0522400013'], ['ج2 ضيف5', '0522400014'],
]));
$tokenC2 = $respC2_preview['data']['upload_token'] ?? null;
$respC2 = do_confirm(5001, $tokenC2);
check('C2. مضيف بلا حد: imported=5 (كل الصفوف الصالحة، بلا سقف)', $respC2['data']['summary']['imported'] ?? null, 5);
check('C2ب. quota_exceeded=0 (الميزة الجديدة لا تؤثر على مضيف بلا حد)', $respC2['data']['summary']['quota_exceeded'] ?? null, 0);

// ============================================================================
// D) إعادة الحساب الطازجة عند Confirm — لا اعتماد على رقم Preview (Race Condition)
// ============================================================================
$HOST_D = 807;
set_test_user_guest_limit($HOST_D, 3);
set_test_event(5030, $HOST_D);
$GLOBALS['__test_current_user_id'] = $HOST_D;
$respD_preview = do_preview(5030, csv_rows([['د ضيف1', '0522500001'], ['د ضيف2', '0522500002'], ['د ضيف3', '0522500003']]));
check('D1. لحظة Preview: quota.remaining=3 (لا ضيوف بعد)', $respD_preview['data']['quota']['remaining'] ?? null, 3);
check('D1ب. لحظة Preview: quota_exceeded=false (3 صالحة <= 3 متبقٍّ)', $respD_preview['data']['quota']['quota_exceeded'] ?? null, false);
$tokenD = $respD_preview['data']['upload_token'] ?? null;

// محاكاة سباق حقيقي: تبويب آخر (أو مستخدم آخر) يضيف ضيفين يدوياً بين Preview وConfirm
PGE_Invitation_Service::create(5030, '0522500098', 'أُضيف يدوياً بالتزامن 1', '', $HOST_D);
PGE_Invitation_Service::create(5030, '0522500099', 'أُضيف يدوياً بالتزامن 2', '', $HOST_D);

$respD = do_confirm(5030, $tokenD);
check_true('D2. Confirm نجح بنيوياً رغم تغيّر الحالة أثناء الانتظار', $respD['success'] ?? false);
check('D2ب. imported=1 فقط (لا 3 كما أوحى Preview) — إثبات إعادة الحساب الطازجة', $respD['data']['summary']['imported'] ?? null, 1);
check('D2ج. quota_exceeded=2 (الصفان المتبقيان رُفضا بسبب الحد الفعلي وقت Confirm)', $respD['data']['summary']['quota_exceeded'] ?? null, 2);
check('D2د. quota الراجعة من Confirm نفسه تعكس الحالة الطازجة: remaining=0', $respD['data']['quota']['remaining'] ?? null, 0);
check('D2هـ. إجمالي الضيوف النهائي = 3 (2 يدوي + 1 مستورَد) = الحد بالضبط', count(pge_event_guests_get_map(5030)), 3);

// ============================================================================
// E) فحص نصّي: Confirm يستدعي pge_resolve_guest_quota_status() فعلياً ولا
//    يقرأ أي حقل quota/remaining/limit من $_POST إطلاقاً (لا ثقة بالمتصفح)
// ============================================================================
$ajax_source_e = isset($__pge_ajax_source_override) ? $__pge_ajax_source_override : file_get_contents(__DIR__ . '/../includes/invitation-management-ajax.php');
$confirm_body_e = extract_function_body($ajax_source_e, 'pge_invitation_mgmt_excel_confirm_handler');
check_true('E1. جسم Confirm يستدعي pge_resolve_guest_quota_status( فعلياً', strpos($confirm_body_e, 'pge_resolve_guest_quota_status(') !== false);
check_true("E2. جسم Confirm لا يقرأ \$_POST['remaining'] أو أي حقل حصة من المتصفح", strpos($confirm_body_e, "\$_POST['remaining']") === false && strpos($confirm_body_e, "\$_POST['quota']") === false);

// ============================================================================
// F) المكرَّر لا يستهلك حصة الباقة — العدّاد يقارَن بعدد الاستيرادات الناجحة
//    فعلياً لا بترتيب الصفوف الصالحة الخام
// ============================================================================
// الحد=3، وضيف واحد موجود مسبقاً (يستهلك مقعداً واحداً) → المتبقي الفعلي=2،
// بحيث يُستورَد بالضبط ضيفان جديدان رغم أن المكرر يقع بينهما في منتصف القائمة.
$HOST_F = 808;
set_test_user_guest_limit($HOST_F, 3);
set_test_event(5040, $HOST_F);
PGE_Invitation_Service::create(5040, '0522600099', 'موجود مسبقاً (سيصبح مكرراً)', '', $HOST_F);
$GLOBALS['__test_current_user_id'] = $HOST_F;
$respF_preview = do_preview(5040, csv_rows([
    ['و ضيف1', '0522600001'],
    ['و مكرر', '0522600099'], // duplicate — لا يستهلك حصة
    ['و ضيف2', '0522600002'],
    ['و ضيف3', '0522600003'],
]));
$tokenF = $respF_preview['data']['upload_token'] ?? null;
$respF = do_confirm(5040, $tokenF);
check('F1. imported=2 (الحد بالضبط، رغم أن المكرر جاء بمنتصف القائمة)', $respF['data']['summary']['imported'] ?? null, 2);
check('F1ب. duplicates=1 (لا تُحسَب ضمن الحصة المستهلَكة)', $respF['data']['summary']['duplicates'] ?? null, 1);
check('F1ج. quota_exceeded=1 (الصف الرابع فقط، بعد بلوغ حد الاستيرادات الناجحة)', $respF['data']['summary']['quota_exceeded'] ?? null, 1);
check('F1د. الهوية الجامعة: total_rows=4', $respF['data']['summary']['total_rows'] ?? null, 4);
check(
    'F1هـ. imported+duplicates+invalid+empty+failed+quota_exceeded=4',
    $respF['data']['summary']['imported'] + $respF['data']['summary']['duplicates'] + $respF['data']['summary']['invalid'] + $respF['data']['summary']['empty'] + $respF['data']['summary']['failed'] + $respF['data']['summary']['quota_exceeded'],
    4
);
check('F1و. ترتيب النتائج: [imported, duplicate, imported, quota_exceeded]', [
    $respF['data']['rows'][0]['result'] ?? null,
    $respF['data']['rows'][1]['result'] ?? null,
    $respF['data']['rows'][2]['result'] ?? null,
    $respF['data']['rows'][3]['result'] ?? null,
], ['imported', 'duplicate', 'imported', 'quota_exceeded']);

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
} else {
    echo "كل اختبارات حدود الباقة (Guest Limit) نجحت.\n";
}
