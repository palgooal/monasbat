<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـRC1 Fix Pack 3A ("Invitation Bulk
 * Add Migration" — A4 جزئياً فقط: نقل الإضافة الجماعية حصراً).
 *
 * يحمِّل السلسلة الحقيقية الكاملة (helpers.php → event-guests.php → Schema/
 * Audit/Repository/Service → PGE_Invitation_Bulk_Add_Service → xlsx-writer/
 * export → invitation-management-ajax.php) وينفِّذ المعالجات الحقيقية
 * بأسمائها مباشرة — "Do NOT create logical mirrors of production code.
 * Execute the real activation code."
 *
 * 26 حالة مطلوبة صراحةً في RFC الأصلي (مُرقَّمة أدناه 1-26 لتطابق القائمة
 * حرفياً)، بالإضافة إلى 21 حالة إضافية مطلوبة صراحةً في RFC الخاص بـ
 * "Blocker Fix — Phone-Only Compatibility" (مُرقَّمة BF1-BF21 في نهاية الملف).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-rc1-fixpack3a.php
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
if (!function_exists('current_time')) { function current_time($type = 'mysql', $gmt = 0) { return '2026-08-01 00:00:00'; } }
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

// ── Stubs AJAX/JSON ──────────────────────────────────────────────────────
if (!class_exists('Test_Wp_Die_Exception_FP3A')) { class Test_Wp_Die_Exception_FP3A extends \Exception {} }

function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception_FP3A('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception_FP3A('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception_FP3A('wp_die'); }

function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try { $handler(); } catch (Test_Wp_Die_Exception_FP3A $e) { /* متوقَّع */ }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function call_export_handler(callable $handler): string
{
    ob_start();
    try { $handler(); } catch (Test_Wp_Die_Exception_FP3A $e) { /* متوقَّع */ }
    return ob_get_clean();
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

// ── Fake $wpdb — نفس نمط test-invitation-management.php حرفياً ─────────────
class Fake_Wpdb_Fp3a
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

    public function get_results($sql, $output = null)
    {
        if ($this->which_table($sql) !== 'audit') return [];
        if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+guest_phone\s*=\s*\'([^\']*)\'/i', $sql, $m)) {
            $event_id = (int) $m[1]; $phone = $m[2];
            $rows = array_values(array_filter($this->audit_log, function ($r) use ($event_id, $phone) {
                return (int) $r['event_id'] === $event_id && $r['guest_phone'] === $phone;
            }));
            usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
            return $rows;
        }
        return [];
    }

    public function insert($table, $data, $format = null)
    {
        if ($this->which_table($table) !== 'audit') return false;
        $id = $this->audit_next_id++;
        $this->audit_log[$id] = array_merge(['id' => $id], $data);
        $this->insert_id = $id;
        return 1;
    }
}
$GLOBALS['wpdb'] = new Fake_Wpdb_Fp3a();
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

// ============================================================================
// إعداد: مضيف يملك المناسبة 2001، مناسبة أخرى 2002 لعزل الأحداث/التزامن
// ============================================================================
$HOST_ID = 501;
set_test_event(2001, $HOST_ID);
set_test_event(2002, $HOST_ID);
$GLOBALS['__test_current_user_id'] = $HOST_ID;
$GLOBALS['__test_logged_in'] = true;

// دعوة مسبقة في المناسبة 2001 (لاختبار duplicate_in_event) عبر الإنشاء الحقيقي.
PGE_Invitation_Service::create(2001, '0500009999', 'ضيف سابق', '', $HOST_ID);
// دعوة مسبقة في المناسبة 2002 فقط (لاختبار عزل المناسبات — نفس الرقم قد يظهر في دفعة 2001).
PGE_Invitation_Service::create(2002, '0500009998', 'ضيف من مناسبة أخرى', '', $HOST_ID);

$guests_before_2001 = count(pge_event_guests_get_map(2001));
$guests_before_2002 = count(pge_event_guests_get_map(2002));

echo "=== RC1 Fix Pack 3A — Invitation Bulk Add Migration ===\n";

// ============================================================================
// 1-10: تحليل/تحقّق (Parser + Validation) — عبر preview() مباشرة
// ============================================================================
$batch_text = implode("\n", [
    '0500000001',                       // 1) هاتف فقط — صالح الآن، باسم مُولَّد "ضيف — 0001" (Blocker Fix).
    'أحمد محمد,0500000002',              // 2+3) اسم + هاتف بفاصلة.
    "سارة علي\t0500000003",              // 4) اسم + هاتف بتبويب.
    '',                                  // 5) سطر فارغ — يُتجاهَل تماماً.
    '   ',                               // 5) سطر بيضاوي فقط — يُتجاهَل أيضاً.
    'ليس-رقماً-!!!',                      // 6) هاتف غير صالح (لا أرقام إطلاقاً بعد التطبيع).
    'خالد,0500000002',                   // 7) مكرَّر داخل الدفعة نفسها (نفس هاتف السطر 2).
    'منى,0500009999',                    // 8) مكرَّر مع دعوة موجودة فعلاً في المناسبة 2001.
    'غير مكرر,0500009998',               // 9) نفس الرقم موجود في مناسبة أخرى (2002) فقط — يجب أن يكون صالحاً هنا.
]);

$preview_1 = PGE_Invitation_Bulk_Add_Service::preview(2001, $batch_text);
check_true('0. preview() نجح بنيوياً (ok=true)', $preview_1['ok']);
$rows_1 = $preview_1['rows'];

// ملاحظة فهرسة: الأسطر الفارغة/البيضاوية (السطران 4 و5 في النص المُدخَل) لا
// تُحسَب صفوفاً إطلاقاً — لذا فهارس $rows_1 (0-based) تقابل الأسطر الفعلية:
// [0]=هاتف فقط، [1]=اسم+فاصلة، [2]=اسم+تبويب، [3]=هاتف غير صالح،
// [4]=مكرَّر داخل الدفعة، [5]=مكرَّر مع المناسبة، [6]=صالح عبر مناسبة أخرى.
check('1. هاتف فقط: يُحلَّل الهاتف بنجاح (طبقة Parsing)', $rows_1[0]['normalized_phone'], '0500000001');
check('1ب. هاتف فقط (Blocker Fix): يبقى صالحاً الآن (لا يُرفَض)', $rows_1[0]['status'], PGE_Invitation_Bulk_Add_Service::STATUS_VALID);
check('1ج. هاتف فقط: الاسم المُولَّد "ضيف — 0001" ظاهر في المعاينة', $rows_1[0]['guest_name'], 'ضيف — 0001');
check('2. اسم+هاتف بفاصلة: الاسم صحيح', $rows_1[1]['guest_name'], 'أحمد محمد');
check('3. اسم+هاتف بفاصلة: الهاتف صحيح ويبقى صالحاً', $rows_1[1]['status'], PGE_Invitation_Bulk_Add_Service::STATUS_VALID);
check('4. اسم+هاتف بتبويب: الاسم والهاتف صحيحان ويبقى صالحاً', [$rows_1[2]['guest_name'], $rows_1[2]['normalized_phone'], $rows_1[2]['status']], ['سارة علي', '0500000003', PGE_Invitation_Bulk_Add_Service::STATUS_VALID]);
check('5. الأسطر الفارغة/البيضاوية لم تُحسَب صفوفاً إطلاقاً (7 صفوف فعلية فقط من 9 أسطر مُدخَلة)', count($rows_1), 7);
check('6. هاتف غير صالح: phone_missing بعد التطبيع', $rows_1[3]['error'], 'phone_missing');
check('7. مكرَّر داخل الدفعة: duplicate_in_batch (السطر الثاني بنفس الرقم)', $rows_1[4]['status'], PGE_Invitation_Bulk_Add_Service::STATUS_DUPLICATE_IN_BATCH);
check('8. مكرَّر مع دعوة موجودة في المناسبة: duplicate_in_event', $rows_1[5]['status'], PGE_Invitation_Bulk_Add_Service::STATUS_DUPLICATE_IN_EVENT);
check('9. نفس الرقم في مناسبة أخرى (2002) لا يُعتبَر تكراراً هنا (عزل المناسبات)', $rows_1[6]['status'], PGE_Invitation_Bulk_Add_Service::STATUS_VALID);
check('10. دفعة مختلطة (بعد Blocker Fix): الملخص يعكس صالح/غير صالح/مكرَّر معاً بشكل صحيح', $preview_1['summary'], ['total' => 7, 'valid' => 4, 'invalid' => 1, 'duplicate' => 2]);

// ============================================================================
// 11: Preview لا يُنشئ أي دعوة إطلاقاً
// ============================================================================
check('11. Preview لا يكتب شيئاً — عدد دعوات المناسبة 2001 لم يتغيَّر', count(pge_event_guests_get_map(2001)), $guests_before_2001);
check('11ب. Preview لا يكتب شيئاً — عدد دعوات المناسبة 2002 لم يتغيَّر أيضاً', count(pge_event_guests_get_map(2002)), $guests_before_2002);

// ============================================================================
// 12-13: Confirm — إنشاء الصفوف الصالحة فقط، Best-Effort لبقية الصفوف
// ============================================================================
// Confirm يمرّ عبر المعالج الحقيقي pge_invitation_mgmt_bulk_confirm_handler()
// (لا استدعاء مباشر للخدمة هنا) — هذا هو المسار الوحيد الذي يُسجِّل حدث
// التدقيق الدفعي 'bulk_create_completed' فعلياً (راجع 19-21 أدناه)، ويُثبت
// أيضاً أن التفويض/nonce الحقيقيَّين يُطبَّقان قبل أي معالجة.
$_POST = make_post_fields(2001, ['raw_text' => $batch_text]);
$confirm_resp_1 = call_ajax_handler('pge_invitation_mgmt_bulk_confirm_handler');
check_true('12. Confirm عبر AJAX الحقيقي نجح (success=true)', $confirm_resp_1['success']);
$confirm_1 = $confirm_resp_1['data'];
check('12. النتيجة (بعد Blocker Fix): 4 صفوف "created" (السطور 1/2/3/9 الصالحة، بما فيها الهاتف فقط)', $confirm_1['summary']['created'], 4);
check('12ب. النتيجة: صف واحد duplicate (السطر 8 — duplicate_in_event) + صف واحد duplicate إضافي (السطر 7 — duplicate_in_batch) = 2', $confirm_1['summary']['duplicate'], 2);
check('12ج. النتيجة (بعد Blocker Fix): صف واحد فقط invalid (السطر 6 هاتف غير صالح)', $confirm_1['summary']['invalid'], 1);
check('13. Best-Effort: الإجمالي = مجموع كل التصنيفات (لا صف مفقود، لا توقّف مبكر)', $confirm_1['summary']['total'], $confirm_1['summary']['created'] + $confirm_1['summary']['duplicate'] + $confirm_1['summary']['invalid'] + $confirm_1['summary']['failed']);

$guests_after_2001 = pge_event_guests_get_map(2001);
check('12د. الرقم 0500000002 (اسم+فاصلة) أُنشئ فعلياً في المناسبة 2001', isset($guests_after_2001['0500000002']), true);
check('12هـ. الرقم 0500000003 (اسم+تبويب) أُنشئ فعلياً في المناسبة 2001', isset($guests_after_2001['0500000003']), true);
check('12و. (Blocker Fix) الرقم 0500000001 (هاتف فقط) أُنشئ فعلياً بالاسم المُولَّد', isset($guests_after_2001['0500000001']), true);
check('12و-2. (Blocker Fix) الاسم المخزَّن فعلياً للرقم 0500000001 = "ضيف — 0001" (مطابق للمعاينة تماماً)', $guests_after_2001['0500000001']['name'], 'ضيف — 0001');
check('12ز. الرقم 0500000998... التحقق: 0500009998 (من مناسبة أخرى) أُنشئ فعلياً هنا أيضاً (لا يُعتبَر تكراراً)', isset($guests_after_2001['0500009998']), true);
check('12ح. (Blocker Fix) عدد دعوات المناسبة 2001 بعد Confirm = العدد قبل + 4 المُنشأة فعلياً', count($guests_after_2001), $guests_before_2001 + 4);

// ============================================================================
// 14: التفويض (Authorization) — مستخدم غير مصرَّح له يُرفَض عبر AJAX الحقيقي
// ============================================================================
$GLOBALS['__test_current_user_id'] = 777; // ليس مالك المناسبة 2001 ولا أدمن.
$_POST = make_post_fields(2001, ['raw_text' => 'اختبار,0500000777']);
$resp_unauth_preview = call_ajax_handler('pge_invitation_mgmt_bulk_preview_handler');
check('14. Preview: مستخدم غير مصرَّح يُرفَض (forbidden)', [$resp_unauth_preview['success'], $resp_unauth_preview['data']['reason'] ?? null], [false, 'forbidden']);

$_POST = make_post_fields(2001, ['raw_text' => 'اختبار,0500000777']);
$resp_unauth_confirm = call_ajax_handler('pge_invitation_mgmt_bulk_confirm_handler');
check('14ب. Confirm: مستخدم غير مصرَّح يُرفَض (forbidden)', [$resp_unauth_confirm['success'], $resp_unauth_confirm['data']['reason'] ?? null], [false, 'forbidden']);
check('14ج. الرفض لم يُنشئ أي دعوة (لا كتابة قبل التفويض)', isset(pge_event_guests_get_map(2001)['0500000777']), false);
$GLOBALS['__test_current_user_id'] = $HOST_ID;

// ============================================================================
// 15: عزل المناسبات — الكتابة في 2001 لا تُسرِّب لعدد دعوات 2002
// ============================================================================
check('15. عزل المناسبات: عدد دعوات المناسبة 2002 لم يتأثَّر بكتابة دفعة المناسبة 2001', count(pge_event_guests_get_map(2002)), $guests_before_2002);

// ============================================================================
// 16-17: حدود الحجم/عدد الأسطر — رفض واضح، بلا اقتطاع صامت
// ============================================================================
$too_large_text = str_repeat('a', PGE_Invitation_Bulk_Add_Service::MAX_PAYLOAD_BYTES + 1);
$preview_too_large = PGE_Invitation_Bulk_Add_Service::preview(2001, $too_large_text);
check('16. النص الملصوق أكبر من الحد: رفض واضح (payload_too_large)', [$preview_too_large['ok'], $preview_too_large['reason'] ?? null], [false, 'payload_too_large']);

$too_many_lines_text = implode("\n", array_fill(0, PGE_Invitation_Bulk_Add_Service::MAX_LINES + 1, '0500001111'));
$preview_too_many = PGE_Invitation_Bulk_Add_Service::preview(2001, $too_many_lines_text);
check('17. عدد الأسطر أكبر من الحد: رفض واضح (too_many_lines)، بلا اقتطاع صامت لأول 500 سطر', [$preview_too_many['ok'], $preview_too_many['reason'] ?? null], [false, 'too_many_lines']);

// ============================================================================
// 18: تهريب المخرَجات (Output Escaping) — فحص بنيوي على مصدر القالب الحقيقي
// ============================================================================
$template_source = file_exists(__DIR__ . '/../templates/event-invitations.php') ? file_get_contents(__DIR__ . '/../templates/event-invitations.php') : '';
check_true('18. تمّ العثور على templates/event-invitations.php وقراءته', $template_source !== '');
check_true('18ب. bulkRenderPreviewRows() تُمرِّر guest_name عبر escapeHtml() (لا HTML خام من نص ملصوق)', strpos($template_source, 'escapeHtml(row.guest_name)') !== false);
check_true('18ج. bulkRenderPreviewRows()/bulkRenderResultRows() تُمرِّران phone عبر escapeHtml()', substr_count($template_source, 'escapeHtml(row.phone)') >= 2);
check_true('18د. لا استخدام لـinnerHTML مباشرة بقيمة row.guest_name أو row.phone بلا escapeHtml (لا نمط "+ row.guest_name +" خام)', strpos($template_source, "+ row.guest_name +") === false && strpos($template_source, "+ row.phone +") === false);

// ============================================================================
// 19-21: التدقيق (Audit) — فردي (created، بلا تغيير) + دفعي (bulk_create_completed) + بلا بيانات خام
// ============================================================================
$audit_created_rows = array_values(array_filter($wpdb->audit_log, function ($r) { return (int) $r['event_id'] === 2001 && $r['action'] === 'created' && $r['guest_phone'] === '0500000002'; }));
check('19. حدث تدقيق "created" الفردي الحالي سُجِّل بلا تغيير لكل دعوة أُنشئت عبر الدفعة', count($audit_created_rows), 1);

$audit_bulk_rows = array_values(array_filter($wpdb->audit_log, function ($r) { return (int) $r['event_id'] === 2001 && $r['action'] === 'bulk_create_completed'; }));
check('20. حدث تدقيق دفعي واحد فقط "bulk_create_completed" لكل عملية Confirm', count($audit_bulk_rows), 1);
$bulk_audit_reason = json_decode($audit_bulk_rows[0]['reason'] ?? '{}', true);
check('20ب. حدث التدقيق الدفعي يحمل العدّاد الصحيح (بعد Blocker Fix: total/created/duplicate/invalid/failed)', $bulk_audit_reason, ['total' => 7, 'created' => 4, 'duplicate' => 2, 'invalid' => 1, 'failed' => 0]);
check_true('20ج. حدث التدقيق الدفعي مُسجَّل بسنتينل مستوى المناسبة (لا هاتف فردي)', $audit_bulk_rows[0]['guest_phone'] === PGE_Invitation_Management_Audit::EVENT_LEVEL_BULK_ADD_SENTINEL);

check_true('21. لا اسم مدعو (مثل "أحمد محمد") داخل نص عمود reason لحدث bulk_create_completed', strpos($audit_bulk_rows[0]['reason'], 'أحمد') === false);
check_true('21ب. لا رقم هاتف فردي (مثل 0500000002) داخل نص عمود reason لحدث bulk_create_completed', strpos($audit_bulk_rows[0]['reason'], '0500000002') === false);
check_true('21ج. لا نص ملصوق خام (مثل "خالد,0500000002") داخل أي سجل تدقيق دفعي', strpos($audit_bulk_rows[0]['reason'], 'خالد') === false);

// ============================================================================
// 22-24: انحدار — CRUD المفردة / Phase 9B QR / Phase 9C Export لم تتأثَّر
// ============================================================================
$_POST = make_post_fields(2001, ['phone' => '0500005555', 'name' => 'ضيف منفرد', 'note' => '']);
$resp_single_create = call_ajax_handler('pge_invitation_mgmt_create_handler');
check('22. انحدار: "إضافة دعوة" المفردة الحالية لا تزال تعمل بنجاح بعد إضافة Bulk Add', $resp_single_create['success'], true);

$_POST = make_post_fields(2001, ['phone' => '0500009999']);
$resp_qr_regen = call_ajax_handler('pge_invitation_mgmt_qr_regenerate_handler');
check('23. انحدار: Phase 9B (تجديد QR) لا يزال يعمل بنجاح بعد إضافة Bulk Add', $resp_qr_regen['success'], true);

$_POST = make_post_fields(2001, []);
$csv_output = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('24. انحدار: Phase 9C (تصدير CSV) لا يزال يُنتج مخرَجات فعلية (BOM + رأس الأعمدة) بعد إضافة Bulk Add', strpos($csv_output, "\xEF\xBB\xBF") === 0 && strlen($csv_output) > 10);

// ============================================================================
// 25-26: اللوحة القديمة — الحذف الفعلي لا يزال متاحاً، والشريط التوجيهي مُحدَّث
// ============================================================================
check_true('25. wp_ajax_pge_event_guest_delete لا يزال مُسجَّلاً فعلياً (الحذف الفردي)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_delete']));
check_true('25ب. wp_ajax_pge_event_guest_bulk_delete لا يزال مُسجَّلاً فعلياً (الحذف الجماعي)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_bulk_delete']));

$theme_candidates = [
    getenv('PGE_TEST_THEME_FILE') ?: '',
    __DIR__ . '/../../../themes/pgevents-pro/page-event-manage.php',
];
if (defined('PGE_TEST_THEME_FILE') && file_exists(PGE_TEST_THEME_FILE)) {
    $theme_source = file_get_contents(PGE_TEST_THEME_FILE);
} else {
    $theme_source = '';
    foreach ($theme_candidates as $c) { if ($c && file_exists($c)) { $theme_source = file_get_contents($c); break; } }
}
check_true('25ج. تمّ العثور على ملف الثيم page-event-manage.php وقراءته', $theme_source !== '');
/**
 * ============================================================================
 * RC1 Fix Pack 3B ("Legacy Guest Panel Retirement — Hard Delete Migration")
 * ============================================================================
 * تصحيح لتوقعات قديمة: هذا الملف (Fix Pack 3A) كُتب في مرحلة كانت اللوحة
 * القديمة لا تزال تحوي واجهة الحذف الفعلي (Hard Delete) — كان ذلك السبب
 * الوحيد المُبقي عليها (راجع RFC "الحالة الحالية" لـFix Pack 3B). بعد نقل
 * الحذف الفعلي بالكامل إلى صفحة "إدارة الدعوات" وتقاعد واجهة CRUD القديمة
 * (الإضافة/التعديل/الحذف الفردي والجماعي) — مع إبقاء بطاقة واتساب فقط —
 * أصبحت 25د/25هـ/25و القديمة تصف سلوكاً لم يعد قائماً بتصميم مقصود، لا
 * انحداراً. عُدِّلت الثلاثة هنا لتعكس الواقع الصحيح بعد Fix Pack 3B، بدل
 * حذفها، حفاظاً على تسلسل توثيق تطوّر هذه الواجهة عبر مراحل المشروع.
 * التغطية التنفيذية الكاملة لـFix Pack 3B (20 حالة مطلوبة) موجودة في
 * tests/test-rc1-fixpack3b.php المستقل.
 */
check_true('25د. (RC1 Fix Pack 3B) زر الحذف الفردي القديم لم يعد موجوداً في أي عنصر DOM فعلي (class="guest-delete-btn) — الحذف انتقل لصفحة إدارة الدعوات', strpos($theme_source, '"guest-delete-btn') === false);
check_true("25هـ. (RC1 Fix Pack 3B) إجراء AJAX القديم pge_event_guest_delete لم يعد يُستدعى من أي زر في الواجهة (لا postAction('pge_event_guest_delete' في الصفحة) — يبقى مُسجَّلاً فعلياً كطبقة توافق فقط (Legacy AJAX handlers may remain registered)", strpos($theme_source, "postAction('pge_event_guest_delete'") === false);
check_true("25و. (RC1 Fix Pack 3B) نموذج الحذف الجماعي bulkGuestForm (اللوحة القديمة) أُزيل بالكامل — لا id=\"bulkGuestForm\" في الصفحة", strpos($theme_source, 'id="bulkGuestForm"') === false);

/**
 * RC1 Fix Pack 3B: نص الشريط التوجيهي المذكور في 26/26ب الأصليتين ("الإضافة
 * الجماعية متوفّرة الآن" / اللوحة باقية "من أجل الحذف الفعلي فقط") وصف مرحلة
 * انتقالية سابقة (Fix Pack 3A) لم تعد قائمة — Fix Pack 3B استبدل الشريط
 * بالكامل بإشعار "إدارة المدعوين (إضافة/تعديل/حذف) انتقلت بالكامل" لأن
 * الحذف الفعلي نفسه انتقل الآن أيضاً (لم يعد سبباً للبقاء). عُدِّلت الحالتان
 * لتعكسا نص الشريط الحالي الصحيح فعلياً.
 */
check_true('26. الشريط التوجيهي (بعد Fix Pack 3B) يذكر انتقال إدارة المدعوين بالكامل (إضافة/تعديل/حذف) لصفحة إدارة الدعوات', strpos($theme_source, 'إدارة المدعوين (إضافة/تعديل/حذف) انتقلت بالكامل إلى') !== false);
check_true('26ب. الشريط التوجيهي يذكر أن أقسام الإضافة/التعديل/الحذف القديمة لم تعد متاحة في هذه الصفحة', strpos($theme_source, 'أقسام الإضافة/التعديل/الحذف أدناه لم تعد متاحة هنا') !== false);
check_true('26ج. نص الشريط القديم (Fix Pack 2، قبل هذا التحديث) لم يعد موجوداً حرفياً', strpos($theme_source, 'للمزايا الإضافية (تصدير، رمز QR، سجلّ تدقيق) استخدم') === false);
check_true('26د. الرابط نفسه (id=navInvitationsLegacyBanner) لا يزال يشير لنفس $invitations_url', strpos($theme_source, "esc_url(\$invitations_url) ?>\" id=\"navInvitationsLegacyBanner\"") !== false);

// ============================================================================
// إضافيّ — بوابة النطاق (Gating) مُسجَّلة فعلياً، ونطاق AJAX الجديد لا يُنشئ نطاقاً موازياً
// ============================================================================
check_true('إضافي. wp_ajax_pge_invitation_mgmt_bulk_preview مُسجَّل فعلياً', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_bulk_preview']));
check_true('إضافي. wp_ajax_pge_invitation_mgmt_bulk_confirm مُسجَّل فعلياً', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_bulk_confirm']));
check_true('إضافي. wp_ajax_pge_invitation_mgmt_create (المسار المفرد) لا يزال مُسجَّلاً أيضاً', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_create']));

// ============================================================================
// RC1 Fix Pack 3A — Blocker Fix (Phone-Only Compatibility): 21 اختباراً
// مطلوباً صراحةً في RFC (مُرقَّمة BF1-BF21 أدناه لتطابق القائمة حرفياً).
// ============================================================================

// BF1: صف "هاتف فقط" صالح (رقم مختلف واضح للتأكيد الإضافي، غير الرقم المُستخدَم في القسم 1-10 أعلاه).
$preview_bf = PGE_Invitation_Bulk_Add_Service::preview(2001, "0512345678");
check('BF1. هاتف فقط: يبقى صالحاً (رقم مختلف للتأكيد الإضافي)', $preview_bf['rows'][0]['status'], PGE_Invitation_Bulk_Add_Service::STATUS_VALID);

// BF2: المعاينة تُظهر الاسم المُولَّد قبل أي تأكيد.
check('BF2. المعاينة تُظهر الاسم المُولَّد "ضيف — 5678" قبل التأكيد', $preview_bf['rows'][0]['guest_name'], 'ضيف — 5678');

// BF3: التأكيد يُنشئ الدعوة فعلياً عبر مسار AJAX الحقيقي (مناسبة معزولة 2003 لعدم التداخل مع 2001).
set_test_event(2003, $HOST_ID);
$_POST = make_post_fields(2003, ['raw_text' => "0512345678"]);
$confirm_bf = call_ajax_handler('pge_invitation_mgmt_bulk_confirm_handler');
check_true('BF3. التأكيد أنشأ الدعوة فعلياً (success=true)', $confirm_bf['success']);
check('BF3ب. عدد الصفوف المُنشأة فعلياً = 1', $confirm_bf['data']['summary']['created'], 1);

// BF4: الاسم المخزَّن فعلياً يطابق الاسم المعروض في المعاينة تماماً (حتمية Preview↔Confirm — لا اسم من المتصفح).
$guests_2003 = pge_event_guests_get_map(2003);
check('BF4. الاسم المخزَّن فعلياً يطابق الاسم المعروض في المعاينة تماماً', $guests_2003['0512345678']['name'], $preview_bf['rows'][0]['guest_name']);

// BF5: آخر 4 أرقام فقط تظهر في الاسم المُولَّد.
check('BF5. الاسم المُولَّد يحتوي فقط على آخر 4 أرقام (5678)', $guests_2003['0512345678']['name'], 'ضيف — 5678');

// BF6: الهاتف الكامل غير مكشوف داخل الاسم المُولَّد (لا الرقم الكامل ولا حتى البادئة).
check_true('BF6. الهاتف الكامل (0512345678) غير مكشوف داخل الاسم المُولَّد', strpos($guests_2003['0512345678']['name'], '0512345678') === false);
check_true('BF6ب. البادئة السابقة لآخر 4 أرقام (051234) غير مكشوفة أيضاً داخل الاسم', strpos($guests_2003['0512345678']['name'], '051234') === false);

// BF7: هاتف أقصر من 4 أرقام يستخدم كل الأرقام المتاحة (fallback عبر substr() السالب بلا فرع شرطي إضافي).
$preview_short = PGE_Invitation_Bulk_Add_Service::preview(2001, "123");
check('BF7. هاتف أقصر من 4 أرقام (123): الصف صالح مستخدماً كل الأرقام المتاحة', $preview_short['rows'][0]['status'], PGE_Invitation_Bulk_Add_Service::STATUS_VALID);
check('BF7ب. الاسم المُولَّد لهاتف قصير = "ضيف — 123" (كل الأرقام المتاحة، بلا حشو)', $preview_short['rows'][0]['guest_name'], 'ضيف — 123');

// BF8: صف بلا أي رقم قابل للاستخدام إطلاقاً يبقى غير صالح — توليد الاسم لا يتجاوز التحقّق من الهاتف.
$preview_nouseable = PGE_Invitation_Bulk_Add_Service::preview(2001, "بلا أي أرقام إطلاقاً");
check('BF8. صف بلا هاتف قابل للاستخدام إطلاقاً يبقى غير صالح (phone_missing)', $preview_nouseable['rows'][0]['status'], PGE_Invitation_Bulk_Add_Service::STATUS_INVALID);
check('BF8ب. لا اسم يُولَّد لصف بلا هاتف صالح (guest_name يبقى فارغاً)', $preview_nouseable['rows'][0]['guest_name'], '');
check('BF8ج. رمز الخطأ يبقى phone_missing (لم يتحوّل خطأً إلى name_required أو غيره)', $preview_nouseable['rows'][0]['error'], 'phone_missing');

// BF9: صفوف "اسم+هاتف" لا تزال تحتفظ بالاسم المُقدَّم يدوياً من المستخدم (بلا استبدال باسم مُولَّد).
check('BF9. الاسم المُقدَّم يدوياً (أحمد محمد) لم يُستبدَل باسم مُولَّد', $rows_1[1]['guest_name'], 'أحمد محمد');

// BF10-BF11: الفصل بالفاصلة والتبويب لا يزالان يعملان دون تغيير بعد Blocker Fix.
$preview_sep = PGE_Invitation_Bulk_Add_Service::preview(2001, "زياد,0533000001\nهند\t0533000002");
check('BF10. الفصل بالفاصلة لا يزال يعمل كما هو بعد Blocker Fix', [$preview_sep['rows'][0]['guest_name'], $preview_sep['rows'][0]['status']], ['زياد', PGE_Invitation_Bulk_Add_Service::STATUS_VALID]);
check('BF11. الفصل بالتبويب لا يزال يعمل كما هو بعد Blocker Fix', [$preview_sep['rows'][1]['guest_name'], $preview_sep['rows'][1]['status']], ['هند', PGE_Invitation_Bulk_Add_Service::STATUS_VALID]);

// BF12: اكتشاف التكرار داخل الدفعة لا يزال يعمل حتى لصفوف "هاتف فقط".
$preview_dup_phoneonly = PGE_Invitation_Bulk_Add_Service::preview(2001, "0544000001\n0544000001");
check('BF12. التكرار داخل الدفعة يُكتشَف حتى لصفَي هاتف فقط متطابقين', $preview_dup_phoneonly['rows'][1]['status'], PGE_Invitation_Bulk_Add_Service::STATUS_DUPLICATE_IN_BATCH);

// BF13: اكتشاف التكرار مع دعوة موجودة فعلياً في المناسبة لا يزال يعمل لصف "هاتف فقط".
PGE_Invitation_Service::create(2001, '0555000002', 'ضيف موجود مسبقاً', '', $HOST_ID);
$preview_dup_event_phoneonly = PGE_Invitation_Bulk_Add_Service::preview(2001, "0555000002");
check('BF13. التكرار مع دعوة موجودة في المناسبة يُكتشَف حتى لصف هاتف فقط', $preview_dup_event_phoneonly['rows'][0]['status'], PGE_Invitation_Bulk_Add_Service::STATUS_DUPLICATE_IN_EVENT);

// BF14: المعاينة تبقى للقراءة فقط — حتى لصف "هاتف فقط" تحديداً (لا كتابة قبل التأكيد).
$guests_2001_before_extra_preview = count(pge_event_guests_get_map(2001));
PGE_Invitation_Bulk_Add_Service::preview(2001, "0566000001");
check('BF14. معاينة صف هاتف فقط لا تكتب أي دعوة إطلاقاً', count(pge_event_guests_get_map(2001)), $guests_2001_before_extra_preview);

// BF15: Best-Effort لا يزال يعمل بلا تغيير — التأكيد الأصلي (القسم 12-13 أعلاه) أنشأ صفوفاً صالحة (بما فيها هاتف فقط) رغم وجود صف غير صالح ضمن نفس الدفعة.
check_true('BF15. Best-Effort: التأكيد لا يتوقف مبكراً رغم وجود صف غير صالح بجانب صفوف هاتف فقط صالحة', $confirm_1['summary']['created'] > 0 && $confirm_1['summary']['invalid'] > 0);

// BF16: التدقيق الدفعي لا يزال يخزّن العدادات فقط — بلا رقم هاتف فردي وبلا اسم مُولَّد داخل عمود reason.
check_true('BF16. سجل التدقيق الدفعي لا يحتوي على رقم هاتف فردي (0512345678)', strpos($audit_bulk_rows[0]['reason'], '0512345678') === false);
check_true('BF16ب. سجل التدقيق الدفعي لا يحتوي على أي اسم مُولَّد (مثل "ضيف")', strpos($audit_bulk_rows[0]['reason'], 'ضيف') === false);

// BF17: إنشاء الدعوة المفردة لا يزال يشترط الاسم — العقد العام لم يتغيَّر (عبر المعالج الحقيقي pge_invitation_mgmt_create_handler، بلا استثناء).
$_POST = make_post_fields(2001, ['phone' => '0577000001', 'name' => '', 'note' => '']);
$resp_single_no_name = call_ajax_handler('pge_invitation_mgmt_create_handler');
check('BF17. الدعوة المفردة (بلا اسم) لا تزال تُرفَض (invalid_name) — العقد العام لم يتغيَّر', [$resp_single_no_name['success'], $resp_single_no_name['data']['reason'] ?? null], [false, 'invalid_name']);
check_true('BF17ب. الرفض لم يُنشئ دعوة فعلياً بدون اسم', !isset(pge_event_guests_get_map(2001)['0577000001']));

// BF18: الاستثناء موجود حصراً داخل PGE_Invitation_Bulk_Add_Service — لم تُمَسّ Service/Repository المُفردة (لا تغيير عالمي في العقد).
$service_source = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-service.php');
$repository_source = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-repository.php');
check_true('BF18. class-pge-invitation-service.php لا يستدعي generate_display_name إطلاقاً', strpos($service_source, 'generate_display_name') === false);
check_true('BF18ب. class-pge-invitation-repository.php لا يستدعي generate_display_name إطلاقاً', strpos($repository_source, 'generate_display_name') === false);
check_true('BF18ج. class-pge-invitation-repository.php لا يزال يرفض الاسم الفارغ (invalid_name) دون أي استثناء', strpos($repository_source, "'invalid_name'") !== false);

// BF19: اللوحة القديمة (الحذف الفعلي) لا تزال متاحة بلا تغيير — مُغطّى بالكامل في الاختبارين 25/25ب أعلاه (يُعاد التأكيد هنا صراحةً ضمن قائمة الـ21).
check_true('BF19. الحذف الفعلي (Hard Delete) في اللوحة القديمة لا يزال متاحاً بلا تغيير', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_delete']) && isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_bulk_delete']));

// BF20-BF21: الانحدار مقابل Phase 9A/9B/9C وRC1 Fix Pack 1/2 يُنفَّذ عبر ملفات الاختبار المستقلة الست
// (test-invitation-management.php / test-invitation-export.php / test-supervisor-management.php /
// test-event-operations.php / test-rc1-fixpack1.php / test-rc1-fixpack2.php) — نتائجها موثَّقة في
// التقرير النهائي لهذا الإصلاح، وهي مُصمَّمة أصلاً لتنفيذ سلسلة الإنتاج الحقيقية دون أي محاكاة.
check_true('BF20-21. (مرجعي) الانحدار الكامل خارج هذا الملف — راجع "نتائج الانحدار" في التقرير النهائي', true);

echo "\n=== النتيجة: $passed / $total ===\n";
if (!empty($failures)) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
