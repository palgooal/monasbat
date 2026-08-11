<?php
/**
 * اختبار تنفيذي حقيقي — Guest Limit Unification RFC.
 *
 * يغطي المتطلبات الصريحة للمهمة (لا مرآة منطقية — كل شيء عبر الدوال/
 * الأصناف الحقيقية بأسمائها الفعلية):
 *   A. الإضافة اليدوية (PGE_Invitation_Service::create() عبر
 *      pge_invitation_mgmt_create_handler الحقيقي).
 *   B. Bulk Add (PGE_Invitation_Bulk_Add_Service::confirm() الحقيقية).
 *   D. المسارات القديمة (event-guests.php) — إثبات عدم التسجيل.
 *   E. التزامن/القفل (GET_LOCK/RELEASE_LOCK عبر Fake_Wpdb، بنفس منهجية
 *      test-invitation-credit-ledger.php: محاكاة تسلسلية داخل عملية PHP
 *      واحدة تثبت توازن القفل والقرار الصحيح تسلسلياً — لا محاكاة حقيقية
 *      لاتصالي MySQL منفصلين، وهو قيد بنيوي معروف لأي اختبار PHP CLI واحد).
 *   F. انحدار مركَّز على edit()/cancel()/delete()/regenerate_qr() عبر
 *      Repository/Service الحقيقيين، لإثبات أن القفل الجديد في create()
 *      وحده لا يمسّ بقية العمليات (كلها بلا قفل، كما كانت).
 *
 * ملاحظة منهجية (بند C — سلوك Excel بعد التوحيد): مُغطّى بالفعل تنفيذياً في
 * tests/test-excel-import-guest-limit.php (سيناريوهات D1/D2/F1 التي تثبت
 * أن Confirm يعيد الحساب حياً لحظة كل صف عبر PGE_Invitation_Service::
 * create() ولا يعتمد على سقف مجمَّد من Preview) — لم يُكرَّر هنا تفادياً
 * لازدواج بنية تحميل ملفات Excel الكاملة (SimpleXLSX + namespace wrapping)
 * التي لا علاقة مباشرة لها بمنطق هذا الملف.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__test_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['__test_registered_hooks'][$hook] = true; }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) { function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return trim((string) $v); } }
if (!function_exists('absint')) { function absint($value) { return abs((int) $value); } }
if (!function_exists('current_time')) { function current_time($type = 'mysql', $gmt = 0) { return '2026-08-08 00:00:00'; } }
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
if (!function_exists('wp_hash')) { function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed'); } }
if (!function_exists('pge_generate_invite_code')) {
    function pge_generate_invite_code()
    {
        static $n = 0;
        $n++;
        return 'CODE-GLU-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
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

// ── حدود الباقة للمستخدم — stub لـ pge_get_user_plan_limits_for_events() ──
$GLOBALS['__test_user_guest_limits'] = [];
function set_test_user_guest_limit($user_id, $limit) { $GLOBALS['__test_user_guest_limits'][$user_id] = $limit; }
if (!function_exists('pge_get_user_plan_limits_for_events')) {
    function pge_get_user_plan_limits_for_events($user_id)
    {
        return ['guest_limit' => $GLOBALS['__test_user_guest_limits'][$user_id] ?? 0];
    }
}

// ── Stubs AJAX/JSON ──────────────────────────────────────────────────────
if (!class_exists('Test_Wp_Die_Exception_GLU')) { class Test_Wp_Die_Exception_GLU extends \Exception {} }

function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception_GLU('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception_GLU('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception_GLU('wp_die'); }

function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try { $handler(); } catch (Test_Wp_Die_Exception_GLU $e) { /* متوقَّع */ }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

// ── Fake $wpdb — مع محاكاة GET_LOCK/RELEASE_LOCK حقيقية السلوك ─────────────
class Fake_Wpdb_Glu
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $audit_log = [];
    private $audit_next_id = 1;
    /** خريطة "قفل محجوز/غير محجوز" ضمن عملية PHP واحدة — راجع توضيح أعلى الملف. */
    public $held_locks = [];

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $query = str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%f'], $query);
        return vsprintf(str_replace("'%s'", "%s", $query), array_map(function ($a) {
            return is_string($a) ? "'" . addslashes($a) . "'" : $a;
        }, $args));
    }

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

    public function get_results($sql, $output = null) { return []; }

    public function insert($table, $data, $format = null)
    {
        if (strpos((string) $table, 'pge_invitation_mgmt_audit_log') === false) return false;
        $id = $this->audit_next_id++;
        $this->audit_log[$id] = array_merge(['id' => $id], $data);
        $this->insert_id = $id;
        return 1;
    }
}
$GLOBALS['wpdb'] = new Fake_Wpdb_Glu();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ============================================================================
// تحميل حقيقي للسلسلة الكاملة (نفس ترتيب pgevents-core.php)
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
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

echo "=== Guest Limit Unification RFC — اختبار تنفيذي حقيقي ===\n";

// ============================================================================
// A. الإضافة اليدوية (Manual Create)
// ============================================================================
echo "\n--- A. الإضافة اليدوية ---\n";

set_test_event(1001, 901);
set_test_user_guest_limit(901, 5);
$GLOBALS['__test_current_user_id'] = 901;
$GLOBALS['__test_logged_in'] = true;

// A1. دون الحد ينجح (current=0, limit=5)
$_POST = make_post_fields(1001, ['phone' => '0500000001', 'name' => 'ضيف أول', 'note' => '']);
$respA1 = call_ajax_handler('pge_invitation_mgmt_create_handler');
check('A1. دون الحد: الإنشاء ينجح', $respA1['success'] ?? null, true);

// نملأ الحصة يدوياً حتى current=5 (limit=5) عبر create() مباشرة.
for ($i = 2; $i <= 5; $i++) {
    PGE_Invitation_Service::create(1001, '050000000' . $i, 'ضيف ' . $i, '', 901);
}
check('A1ب. تمهيد: current=5 فعلاً بعد التعبئة', count(pge_event_guests_get_map(1001)), 5);

// A2. عند الحد بالضبط: يُرفَض
$_POST = make_post_fields(1001, ['phone' => '0500000099', 'name' => 'ضيف زائد', 'note' => '']);
$respA2 = call_ajax_handler('pge_invitation_mgmt_create_handler');
check('A2. عند الحد: الإنشاء يُرفَض', $respA2['success'] ?? null, false);
check('A2ب. السبب المُعاد guest_limit_reached', $respA2['data']['reason'] ?? null, 'guest_limit_reached');
check_true('A2ج. رسالة عربية واضحة للمستخدم', strpos((string) ($respA2['data']['message'] ?? ''), 'الحد الأقصى للمدعوين') !== false);
check_true('A2د. لا كتابة جزئية: الرقم الزائد لم يُضَف فعلياً', !isset(pge_event_guests_get_map(1001)['0500000099']));

// A3. باقة غير محدودة (guest_limit=0): تنجح دائماً
set_test_event(1002, 902);
set_test_user_guest_limit(902, 0);
$GLOBALS['__test_current_user_id'] = 902;
for ($i = 1; $i <= 8; $i++) {
    PGE_Invitation_Service::create(1002, '051100000' . $i, 'ضيف غير محدود ' . $i, '', 902);
}
$_POST = make_post_fields(1002, ['phone' => '0511000099', 'name' => 'ضيف تاسع', 'note' => '']);
$respA3 = call_ajax_handler('pge_invitation_mgmt_create_handler');
check('A3. باقة غير محدودة: الإنشاء ينجح رغم 8 مدعوين سابقين', $respA3['success'] ?? null, true);

// A4. مناسبة تجاوزت الحد فعلاً مسبقاً (current=7 > limit=5): كل إنشاء جديد يُرفَض
// التسلسل: نُنشئ 7 مدعوين والحد غير محدود بعد (نفس منطق Part M — الحد
// انخفض *بعد* أن كان للمناسبة مدعوون أكثر منه، وليس عبر تجاوز create()
// نفسها، إذ باتت تفرض الحصة دوماً الآن).
set_test_event(1003, 903);
set_test_user_guest_limit(903, 0); // بلا حد أثناء التعبئة الأولية فقط.
for ($i = 1; $i <= 7; $i++) {
    PGE_Invitation_Service::create(1003, '052200000' . $i, 'ضيف زائد سلفاً ' . $i, '', 903);
}
check('A4. تمهيد: current=7 فعلياً بعد التعبئة (بلا حد وقتها)', count(pge_event_guests_get_map(1003)), 7);
set_test_user_guest_limit(903, 5); // الآن يُخفَّض الحد إلى 5 — أقل من current=7 الفعلي.
$GLOBALS['__test_current_user_id'] = 903;
$_POST = make_post_fields(1003, ['phone' => '0522000099', 'name' => 'ضيف عاشر', 'note' => '']);
$respA4 = call_ajax_handler('pge_invitation_mgmt_create_handler');
check('A4ب. الإنشاء الجديد يُرفَض (remaining=0 عبر max(0,...))', $respA4['success'] ?? null, false);
check('A4ج. السبب guest_limit_reached', $respA4['data']['reason'] ?? null, 'guest_limit_reached');
check('A4د. السجلات السبعة الموجودة سلفاً لم تُمَسّ (لا حذف/تعديل)', count(pge_event_guests_get_map(1003)), 7);

// ============================================================================
// B. Bulk Add (best-effort عبر PGE_Invitation_Bulk_Add_Service::confirm())
// ============================================================================
echo "\n--- B. Bulk Add ---\n";

set_test_event(2001, 904);
set_test_user_guest_limit(904, 3); // الحصة الكاملة = 3، لا مدعوين بعد.

// الصيغة الحقيقية لـ parse_line(): "name,phone" (الاسم أولاً، ثم الهاتف).
$bulk_lines = [
    'ضيف ب1,0533000001',
    'ضيف ب2,0533000002',
    'ضيف ب1 مكرر,0533000001', // تكرار داخل نفس النص — لا يستهلك حصة.
    'ضيف ب3,0533000003',
    'ضيف ب4,0533000004', // من هنا فصاعداً: تجاوز الحصة (remaining سيصل صفراً).
    'ضيف ب5,0533000005',
    'ضيف ب6,0533000006',
];
$bulk_result = PGE_Invitation_Bulk_Add_Service::confirm(2001, implode("\n", $bulk_lines), 904);

check_true('B5. Bulk Add: العملية تمّت بنيوياً (ok=true)', $bulk_result['ok'] ?? false);
check('B5ب. capacity=3 من أصل 6 صفوف صالحة مختلفة → created=3 بالضبط', $bulk_result['summary']['created'] ?? null, 3);
// الصف الرابع (0533000003) يستهلك المقعد الثالث والأخير؛ الثلاثة الأخيرة
// (0533000004/5/6) تُصنَّف quota_exceeded — لا 2 كما قد يُظَن للوهلة الأولى.
check('B6. الصفوف الثلاثة المتبقية تُصنَّف quota_exceeded (لا failed)', $bulk_result['summary']['quota_exceeded'] ?? null, 3);
check('B7. التكرار الداخلي لم يستهلك حصة (duplicate=1، created ظلت 3)', $bulk_result['summary']['duplicate'] ?? null, 1);
$created_phones = array_values(array_filter(array_map(function ($r) {
    return ($r['result'] ?? null) === 'created' ? $r['normalized_phone'] : null;
}, $bulk_result['rows'])));
check('B7ب. الأرقام الثلاثة المُنشأة فعلياً هي الأولى وفق الترتيب (لا الرقم المكرر)', $created_phones, ['0533000001', '0533000002', '0533000003']);
$quota_exceeded_phones = array_values(array_filter(array_map(function ($r) {
    return ($r['result'] ?? null) === 'quota_exceeded' ? $r['normalized_phone'] : null;
}, $bulk_result['rows'])));
check('B8. الصفوف بعد نفاد الحصة استمرت في المعالجة (الثلاثة صُنِّفت quota_exceeded لا تجاهلاً صامتاً)', $quota_exceeded_phones, ['0533000004', '0533000005', '0533000006']);
check('B8ب. الهوية الجامعة: total=7', $bulk_result['summary']['total'] ?? null, 7);
check(
    'B8ج. total = created+duplicate+invalid+failed+quota_exceeded',
    ($bulk_result['summary']['created'] ?? 0) + ($bulk_result['summary']['duplicate'] ?? 0) + ($bulk_result['summary']['invalid'] ?? 0) + ($bulk_result['summary']['failed'] ?? 0) + ($bulk_result['summary']['quota_exceeded'] ?? 0),
    7
);

// ============================================================================
// D. المسارات القديمة (Legacy) — عدم التسجيل
// ============================================================================
echo "\n--- D. المسارات القديمة ---\n";
check_true('D14. wp_ajax_pge_event_guest_add لم يعد مُسجَّلاً إطلاقاً', empty($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_add']));
check_true('D15. wp_ajax_pge_event_guest_bulk_add لم يعد مُسجَّلاً إطلاقاً', empty($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_bulk_add']));
check_true('D16. wp_ajax_pge_event_guest_update لا يزال مُسجَّلاً كطبقة توافق (ويفوّض للـService منذ Phase D2)', !empty($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_update']));
check_true('D16ب. wp_ajax_pge_event_guest_bulk_delete لا يزال مُسجَّلاً (لم يُلمَس)', !empty($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_bulk_delete']));
check_true('D16ج. wp_ajax_pge_event_guest_regen_code لا يزال مُسجَّلاً (لم يُلمَس)', !empty($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_regen_code']));
check_true('D16د. الدالة المساعدة pge_event_guests_get_map ما زالت موجودة (لم تُحذَف أي دالة مساعدة)', function_exists('pge_event_guests_get_map'));
check_true('D16هـ. الدالة المساعدة pge_event_guests_save_map ما زالت موجودة (لم تُحذَف أي دالة مساعدة)', function_exists('pge_event_guests_save_map'));

// ============================================================================
// E. التزامن (Concurrency) والقفل
// ============================================================================
echo "\n--- E. التزامن والقفل ---\n";

set_test_event(3001, 905);
set_test_user_guest_limit(905, 1); // مقعد واحد فقط.

// E17. طلبان "متزامنان" (تسلسليان هنا، محاكاة وحيدة العملية) مع مقعد واحد متبقٍّ → نجاح واحد فقط.
$e17_a = PGE_Invitation_Service::create(3001, '0544000001', 'طلب A', '', 905);
$e17_b = PGE_Invitation_Service::create(3001, '0544000002', 'طلب B', '', 905);
check('E17. الطلب الأول ينجح (created)', $e17_a['result'] ?? null, 'created');
check('E17ب. الطلب الثاني يُرفَض (quota_exceeded) — لا تجاوز للحد', $e17_b['result'] ?? null, 'quota_exceeded');
check('E17ج. عدد المدعوين النهائي = 1 بالضبط (لا تجاوز)', count(pge_event_guests_get_map(3001)), 1);

// E18. نفس الهاتف من طلبين متتاليين → دعوة واحدة فقط.
set_test_event(3002, 906);
set_test_user_guest_limit(906, 0); // بلا حد — لعزل اختبار التكرار عن اختبار الحصة.
$e18_a = PGE_Invitation_Service::create(3002, '0555000001', 'أول', '', 906);
$e18_b = PGE_Invitation_Service::create(3002, '0555000001', 'محاولة ثانية لنفس الرقم', '', 906);
check('E18. المحاولة الأولى تنجح', $e18_a['result'] ?? null, 'created');
check('E18ب. المحاولة الثانية لنفس الهاتف: duplicate', $e18_b['result'] ?? null, 'duplicate');
check('E18ج. دعوة واحدة فقط فعلياً في الخريطة', count(pge_event_guests_get_map(3002)), 1);

// E19. هاتفان مختلفان، حصة تكفي كلاهما → كلاهما ينجو (لا Lost Update).
set_test_event(3003, 907);
set_test_user_guest_limit(907, 10);
$e19_a = PGE_Invitation_Service::create(3003, '0566000001', 'ضيف مختلف أول', '', 907);
$e19_b = PGE_Invitation_Service::create(3003, '0566000002', 'ضيف مختلف ثانٍ', '', 907);
check('E19. كلا الطلبين نجح', [$e19_a['result'] ?? null, $e19_b['result'] ?? null], ['created', 'created']);
check_true('E19ب. كلا الهاتفين موجود فعلياً معاً (لا فقدان لأحدهما)', isset(pge_event_guests_get_map(3003)['0566000001']) && isset(pge_event_guests_get_map(3003)['0566000002']));
check('E19ج. عدد المدعوين = 2 بالضبط', count(pge_event_guests_get_map(3003)), 2);

// E20/E21/E22/E23. القفل يُحرَّر دائماً — إعادة الحصول عليه فوراً بعد كل مسار (نجاح/تكرار/تجاوز حصة/خطأ تحقّق).
$lock_name_for = function ($event_id) { return 'pge_invitation_create_' . md5((string) (int) $event_id); };

// E20 (نجاح، مناسبة 3003 من E19 أعلاه):
$relock_20 = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name_for(3003), 1));
check('E20. القفل مُحرَّر بعد نجاح create() (إعادة الحصول فوراً تنجح)', (string) $relock_20, '1');
$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name_for(3003)));

// E21 (تكرار، مناسبة 3002 من E18 أعلاه):
$relock_21 = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name_for(3002), 1));
check('E21. القفل مُحرَّر بعد نتيجة duplicate', (string) $relock_21, '1');
$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name_for(3002)));

// E22 (تجاوز حصة، مناسبة 3001 من E17 أعلاه):
$relock_22 = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name_for(3001), 1));
check('E22. القفل مُحرَّر بعد نتيجة quota_exceeded', (string) $relock_22, '1');
$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name_for(3001)));

// E23 (خطأ تحقّق — اسم فارغ، بلا كتابة، لكن القفل يجب أن يُحرَّر أيضاً):
set_test_event(3004, 908);
set_test_user_guest_limit(908, 10);
$e23_invalid = PGE_Invitation_Service::create(3004, '0577000001', '', '', 908); // اسم فارغ → 'error'/'invalid_name'.
check('E23. تمهيد: create() برقم بلا اسم يفشل بـinvalid_name', [$e23_invalid['result'] ?? null, $e23_invalid['reason'] ?? null], ['error', 'invalid_name']);
$relock_23 = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name_for(3004), 1));
check('E23ب. القفل مُحرَّر حتى بعد فشل تحقّق داخلي (لا قفل معلَّق)', (string) $relock_23, '1');
$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name_for(3004)));

// ============================================================================
// F. انحدار مركَّز — عمليات أخرى غير الإنشاء لم تتأثر بالقفل الجديد
// ============================================================================
echo "\n--- F. انحدار مركَّز ---\n";

set_test_event(4001, 909);
set_test_user_guest_limit(909, 10);
PGE_Invitation_Service::create(4001, '0588000001', 'ضيف قبل التعديل', '', 909);

// F24. edit() لا تزال تعمل تحت قفل lifecycle الموحّد منذ Phase D2.
$edit_result = PGE_Invitation_Service::edit(4001, '0588000001', '0588000002', 'اسم مُعدَّل', 'ملاحظة', 909);
check('F24. PGE_Invitation_Service::edit() لا تزال تعمل بلا تغيير', $edit_result['result'] ?? null, 'updated');

// F25. cancel() لا تزال تعمل.
$cancel_result = PGE_Invitation_Service::cancel(4001, '0588000002', 'سبب الإلغاء', 909);
check('F25. PGE_Invitation_Service::cancel() لا تزال تعمل بلا تغيير', $cancel_result['result'] ?? null, 'cancelled');

// F26. delete() (Hard Delete) لا تزال تعمل.
PGE_Invitation_Service::create(4001, '0588000003', 'ضيف للحذف', '', 909);
$delete_result = PGE_Invitation_Service::delete(4001, '0588000003', 909);
check('F26. PGE_Invitation_Service::delete() لا تزال تعمل بلا تغيير', $delete_result['result'] ?? null, 'deleted');

// F27. regenerate_qr() لا تزال تعمل.
PGE_Invitation_Service::create(4001, '0588000004', 'ضيف QR', '', 909);
$qr_result = PGE_Invitation_Service::regenerate_qr(4001, '0588000004', 909);
check('F27. PGE_Invitation_Service::regenerate_qr() لا تزال تعمل بلا تغيير', $qr_result['result'] ?? null, 'regenerated');

// F28. Bulk Add "duplicate" الحقيقي (رقم موجود مسبقاً في المناسبة، لا داخل النص) لا يزال يعمل كما كان.
$bulk_dup_result = PGE_Invitation_Bulk_Add_Service::confirm(4001, "محاولة تكرار,0588000004", 909);
check('F28. Bulk Add: رقم موجود مسبقاً في المناسبة لا يزال duplicate', $bulk_dup_result['rows'][0]['result'] ?? null, 'duplicate');

// F29. Repository::create() تبقى الكاتب الوحيد على المفتاح المركزي — لا تعديل بنيوي هنا (تحقّق سلوكي غير مباشر: الحقول المتوقعة موجودة).
$final_map = pge_event_guests_get_map(4001);
check_true('F29. خريطة المدعوين تحوي الحقول القياسية (phone/name/note) دون أي حقل جديد غريب', isset($final_map['0588000002']['phone']) && isset($final_map['0588000002']['name']));

// F30. RSVP/rsvp-handler.php لم يُلمَس في هذه المهمة — لا استدعاء لأي دالة منه هنا (تحقّق سلبي: الملف غير محمَّل في هذا الاختبار إطلاقاً ولا حاجة له).
check_true('F30. rsvp-handler.php غير مُحمَّل في هذا الاختبار (لا اعتمادية جديدة عليه من مسار الإنشاء)', !function_exists('pge_save_rsvp_response'));

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
}
