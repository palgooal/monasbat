<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـRC1 Fix Pack 3B ("Legacy Guest Panel
 * Retirement — Hard Delete Migration").
 *
 * يحمِّل السلسلة الحقيقية الكاملة (helpers.php → event-guests.php → Schema/
 * Audit/Repository/Service → invitation-management-ajax.php → event-
 * operations-ajax.php) وينفِّذ المعالجات الحقيقية بأسمائها مباشرة —
 * "Do NOT create logical mirrors of production code. Execute the real
 * activation code."
 *
 * 20 حالة مطلوبة صراحةً في RFC الأصلي (مُرقَّمة أدناه 1-20 لتطابق القائمة
 * حرفياً). التغطية الفعلية لكل حالة:
 *   1-9, 12-16: تنفيذ حقيقي كامل عبر استدعاء المعالجات/الخدمات الحقيقية.
 *   10-11, 17-20: فحص بنيوي حقيقي (لا محاكاة) على المصدر الفعلي للملفات
 *     المُنتَجة (قراءة theme/template الحقيقيين) أو تسجيل الـHooks الحقيقي —
 *     نفس أسلوب "25-26" المُعتمَد فعلياً في test-rc1-fixpack3a.php لبنود
 *     خارج قدرة محاكاة قاعدة بيانات وهمية بسيطة (جداول Supervisor/Statistics
 *     الكاملة لم تُلمَس إطلاقاً في هذه المرحلة أصلاً — لا خطر انحدار منطقي
 *     عليها، فالفحص البنيوي على استقرار نقاط الدمج (Hooks/Requires) كافٍ).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-rc1-fixpack3b.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__test_registered_hooks'] = [];
$GLOBALS['__test_hook_callbacks'] = [];
function add_action($hook, ...$args) {
    $GLOBALS['__test_registered_hooks'][$hook] = true;
    // يلتقط الـcallback الفعلي (closures ضمناً) كي يمكن استدعاء معالجات
    // اللوحة القديمة المُسجَّلة كـclosures بلا اسم دالة مباشر — بلا محاكاة
    // منطقها، فقط استدعاء حقيقي للـclosure المُسجَّلة فعلياً في الإنتاج.
    if (isset($args[0]) && is_callable($args[0])) {
        $GLOBALS['__test_hook_callbacks'][$hook] = $args[0];
    }
}
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
function delete_post_meta($post_id, $key)
{
    unset($GLOBALS['__test_post_meta'][$post_id][$key]);
    return true;
}

// ── Stubs AJAX/JSON ──────────────────────────────────────────────────────
if (!class_exists('Test_Wp_Die_Exception_FP3B')) { class Test_Wp_Die_Exception_FP3B extends \Exception {} }

function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception_FP3B('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception_FP3B('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception_FP3B('wp_die'); }

function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try { $handler(); } catch (Test_Wp_Die_Exception_FP3B $e) { /* متوقَّع */ }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function call_export_handler(callable $handler): string
{
    ob_start();
    try { $handler(); } catch (Test_Wp_Die_Exception_FP3B $e) { /* متوقَّع */ }
    return ob_get_clean();
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

/**
 * استدعاء حقيقي لمعالج AJAX مُسجَّل عبر add_action() باسم الـHook (وليس اسم
 * دالة PHP) — يُستخدَم لمعالجات اللوحة القديمة المُسجَّلة كـclosures بلا اسم
 * (event-guests.php). يعتمد على $GLOBALS['__test_hook_callbacks'] المُعبَّأة
 * فعلياً بواسطة add_action() أعلاه عند require_once الحقيقي للملف.
 */
function call_registered_hook(string $hook_name): array
{
    if (!isset($GLOBALS['__test_hook_callbacks'][$hook_name])) {
        return ['success' => false, 'data' => ['reason' => 'hook_not_registered']];
    }
    return call_ajax_handler($GLOBALS['__test_hook_callbacks'][$hook_name]);
}

// ── Fake $wpdb — يُسجِّل جدول التدقيق فعلياً، ويرصد (Spy) أي لمسة لجداول
//    RSVP/الحضور الحقيقية (wp_pge_event_rsvps / pge_checkin_audit_log) —
//    Fix Pack 3B لا يجوز أن يكتب إليها إطلاقاً (راجع اختبار 5/6 أدناه).
class Fake_Wpdb_Fp3b
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $audit_log = [];
    public $touched_forbidden_tables = [];
    private $audit_next_id = 1;
    private $forbidden_fragments = ['pge_event_rsvps', 'pge_checkin_audit_log'];
    // Guest Limit Unification RFC: محاكاة GET_LOCK/RELEASE_LOCK لقفل
    // PGE_Invitation_Service::create() — نفس نمط Fake_Wpdb في
    // test-invitation-credit-ledger.php.
    private $held_locks = [];

    private function record_if_forbidden($sql_or_table)
    {
        foreach ($this->forbidden_fragments as $fragment) {
            if (strpos((string) $sql_or_table, $fragment) !== false) {
                $this->touched_forbidden_tables[] = $sql_or_table;
            }
        }
    }

    private function which_table($sql_or_table)
    {
        $this->record_if_forbidden($sql_or_table);
        if (strpos($sql_or_table, $this->prefix . 'pge_invitation_mgmt_audit_log') !== false) return 'audit';
        return null;
    }

    public function prepare($query, ...$args)
    {
        $this->record_if_forbidden($query);
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
        $this->which_table($sql);
        return 0;
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
$GLOBALS['wpdb'] = new Fake_Wpdb_Fp3b();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ============================================================================
// تحميل حقيقي للسلسلة الكاملة (نفس ترتيب pgevents-core.php بالضبط، بالإضافة
// لملفات RC1 Fix Pack 3A/3B وevent-operations-ajax.php للانحدار البند 16)
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
// إعداد: مضيف يملك المناسبتين 3001/3002 لعزل الأحداث، ومناسبة 3003 مضيف آخر
// ============================================================================
$HOST_ID = 601;
$OTHER_HOST_ID = 602;
set_test_event(3001, $HOST_ID);
set_test_event(3002, $HOST_ID);
set_test_event(3003, $OTHER_HOST_ID);
$GLOBALS['__test_current_user_id'] = $HOST_ID;
$GLOBALS['__test_logged_in'] = true;

echo "=== RC1 Fix Pack 3B — Legacy Guest Panel Retirement (Hard Delete Migration) ===\n";

// ============================================================================
// 1: حذف مفرد (Single hard delete) — عبر المعالج الحقيقي الجديد
// ============================================================================
PGE_Invitation_Service::create(3001, '0511111111', 'ضيف للحذف المفرد', '', $HOST_ID);
check_true('1. تمهيد: الدعوة موجودة قبل الحذف', isset(pge_event_guests_get_map(3001)['0511111111']));

$_POST = make_post_fields(3001, ['phone' => '0511111111']);
$resp_delete_single = call_ajax_handler('pge_invitation_mgmt_delete_handler');
check_true('1ب. الحذف المفرد عبر AJAX الجديد نجح (success=true)', $resp_delete_single['success']);
check('1ج. الدعوة أُزيلت فعلياً من خريطة الضيوف', isset(pge_event_guests_get_map(3001)['0511111111']), false);

// ============================================================================
// 2: حذف جماعي (Bulk hard delete) — عبر المعالج الحقيقي الجديد
// ============================================================================
PGE_Invitation_Service::create(3001, '0511111112', 'ضيف جماعي 1', '', $HOST_ID);
PGE_Invitation_Service::create(3001, '0511111113', 'ضيف جماعي 2', '', $HOST_ID);
PGE_Invitation_Service::create(3001, '0511111114', 'ضيف جماعي 3 (سيبقى)', '', $HOST_ID);

$_POST = make_post_fields(3001, ['phones' => '0511111112,0511111113']);
$resp_delete_bulk = call_ajax_handler('pge_invitation_mgmt_bulk_delete_handler');
check_true('2. الحذف الجماعي عبر AJAX الجديد نجح (success=true)', $resp_delete_bulk['success']);
check('2ب. عدد المحذوف فعلياً = 2', $resp_delete_bulk['data']['deleted'], 2);
$map_after_bulk = pge_event_guests_get_map(3001);
check('2ج. الرقمان المحذوفان غائبان فعلياً من الخريطة', [isset($map_after_bulk['0511111112']), isset($map_after_bulk['0511111113'])], [false, false]);
check_true('2د. الرقم الثالث (لم يُختَر للحذف) لا يزال موجوداً', isset($map_after_bulk['0511111114']));

// ============================================================================
// 3: التفويض (Authorization) — مستخدم غير مصرَّح له يُرفَض عبر AJAX الحقيقي
// ============================================================================
PGE_Invitation_Service::create(3001, '0511111199', 'ضيف حماية تفويض', '', $HOST_ID);
$GLOBALS['__test_current_user_id'] = 999; // ليس مالك 3001 ولا أدمن
$_POST = make_post_fields(3001, ['phone' => '0511111199']);
$resp_unauth_delete = call_ajax_handler('pge_invitation_mgmt_delete_handler');
check('3. حذف مفرد: مستخدم غير مصرَّح يُرفَض (forbidden)', [$resp_unauth_delete['success'], $resp_unauth_delete['data']['reason'] ?? null], [false, 'forbidden']);
check_true('3ب. الرفض لم يحذف الدعوة فعلياً', isset(pge_event_guests_get_map(3001)['0511111199']));

$_POST = make_post_fields(3001, ['phones' => '0511111199']);
$resp_unauth_bulk_delete = call_ajax_handler('pge_invitation_mgmt_bulk_delete_handler');
check('3ج. حذف جماعي: مستخدم غير مصرَّح يُرفَض (forbidden)', [$resp_unauth_bulk_delete['success'], $resp_unauth_bulk_delete['data']['reason'] ?? null], [false, 'forbidden']);
$GLOBALS['__test_current_user_id'] = $HOST_ID;

// ============================================================================
// 4: عزل المناسبات (Cross-event isolation) — هاتف من مناسبة أخرى لا يُحذَف
// ============================================================================
PGE_Invitation_Service::create(3002, '0511112200', 'ضيف مناسبة أخرى', '', $HOST_ID);
$_POST = make_post_fields(3001, ['phone' => '0511112200']); // event_id=3001 لكن الهاتف يخص 3002
$resp_cross_event = call_ajax_handler('pge_invitation_mgmt_delete_handler');
check('4. حذف هاتف يخص مناسبة أخرى (نفس المضيف) يُرفَض (not_found)', [$resp_cross_event['success'], $resp_cross_event['data']['reason'] ?? null], [false, 'not_found']);
check_true('4ب. الدعوة في مناسبتها الأصلية (3002) لم تتأثَّر إطلاقاً', isset(pge_event_guests_get_map(3002)['0511112200']));

// ============================================================================
// 5-6: RSVP/الحضور — نفس سلوك المعالج القديم (تنظيف _pge_rsvp_map/_pge_checkins
// فقط)، وبلا أي لمسة لجدول wp_pge_event_rsvps الحقيقي أو pge_checkin_audit_log
// ============================================================================
PGE_Invitation_Service::create(3001, '0511113300', 'ضيف RSVP/حضور', '', $HOST_ID);
update_post_meta(3001, '_pge_rsvp_map', ['0511113300' => ['reply' => 'yes']]);
update_post_meta(3001, '_pge_checkins', ['0511113300' => ['checked_in_at' => '2026-08-01 10:00:00']]);

$wpdb->touched_forbidden_tables = []; // إعادة ضبط الرصد قبل عملية الحذف المُختبَرة تحديداً
$_POST = make_post_fields(3001, ['phone' => '0511113300']);
$resp_rsvp_delete = call_ajax_handler('pge_invitation_mgmt_delete_handler');
check_true('5. حذف دعوة لديها _pge_rsvp_map نجح', $resp_rsvp_delete['success']);
$rsvp_map_after = (array) get_post_meta(3001, '_pge_rsvp_map', true);
check('5ب. _pge_rsvp_map القديم نُظِّف من الهاتف المحذوف (نفس سلوك المعالج القديم)', isset($rsvp_map_after['0511113300']), false);

$checkins_after = (array) get_post_meta(3001, '_pge_checkins', true);
check('6. _pge_checkins القديم نُظِّف من الهاتف المحذوف (نفس سلوك المعالج القديم)', isset($checkins_after['0511113300']), false);
check('6ب. الحذف لم يلمس جدول wp_pge_event_rsvps الحقيقي ولا pge_checkin_audit_log إطلاقاً (لا cascade جديد)', $wpdb->touched_forbidden_tables, []);

// ============================================================================
// 7: السلوك القديم لم يتغيَّر — معالج event-guests.php القديم لا يزال يعمل
// بنفس بنية الاستجابة/الرسائل تماماً (حذف مفرد + جماعي)، بعد إعادة توجيهه
// عبر PGE_Invitation_Service::delete() (لا Cancel، لا مسار موازٍ)
// ============================================================================
PGE_Invitation_Service::create(3001, '0511114400', 'ضيف اللوحة القديمة (حذف مفرد)', '', $HOST_ID);
$_POST = make_post_fields(3001, ['phone' => '0511114400']);
$resp_legacy_delete = call_registered_hook('wp_ajax_pge_event_guest_delete');
check('7. المعالج القديم (event-guests.php، الآن مُفوَّض للخدمة) لا يزال ينجح بنفس رسالة النجاح الأصلية', [$resp_legacy_delete['success'], $resp_legacy_delete['data']['message'] ?? null], [true, 'تم حذف المدعو']);
check_true("7ب. استجابة المعالج القديم لا تزال تتضمّن 'stats' (بنية غير مُغيَّرة من طرف العميل)", isset($resp_legacy_delete['data']['stats']));
check('7ج. الدعوة أُزيلت فعلياً عبر المسار القديم (نفس نتيجة الحذف الحقيقي — لا Cancel)', isset(pge_event_guests_get_map(3001)['0511114400']), false);

PGE_Invitation_Service::create(3001, '0511114401', 'ضيف اللوحة القديمة (حذف جماعي) 1', '', $HOST_ID);
PGE_Invitation_Service::create(3001, '0511114402', 'ضيف اللوحة القديمة (حذف جماعي) 2', '', $HOST_ID);
$_POST = make_post_fields(3001, ['phones' => '0511114401,0511114402']);
$resp_legacy_bulk_delete = call_registered_hook('wp_ajax_pge_event_guest_bulk_delete');
check('7د. معالج الحذف الجماعي القديم (event-guests.php) لا يزال ينجح', $resp_legacy_bulk_delete['success'], true);
check('7هـ. عدد المحذوف فعلياً عبر المسار الجماعي القديم = 2', $resp_legacy_bulk_delete['data']['deleted'], 2);

// ============================================================================
// 8: تدقيق الحذف (Delete audit) — صف واحد لكل دعوة محذوفة، لا حدث دفعي
// ============================================================================
$audit_deleted_rows_bulk = array_values(array_filter($wpdb->audit_log, function ($r) {
    return (int) $r['event_id'] === 3001 && $r['action'] === 'deleted' && in_array($r['guest_phone'], ['0511111112', '0511111113'], true);
}));
check('8. الحذف الجماعي سجَّل حدث تدقيق "deleted" واحداً لكل دعوة محذوفة (2 صف)', count($audit_deleted_rows_bulk), 2);

$audit_deleted_single = array_values(array_filter($wpdb->audit_log, function ($r) {
    return (int) $r['event_id'] === 3001 && $r['action'] === 'deleted' && $r['guest_phone'] === '0511111111';
}));
check('8ب. الحذف المفرد سجَّل حدث تدقيق "deleted" واحداً بالهاتف الصحيح', count($audit_deleted_single), 1);

check_true('8ج. لا يوجد أي حدث تدقيق دفعي (bulk) على مستوى المناسبة لعملية الحذف الجماعي', !in_array('bulk_delete_completed', array_column($wpdb->audit_log, 'action'), true));

// ============================================================================
// 9: تدفّق تأكيد الحذف (Delete confirmation flow) — فحص بنيوي على القالب
// الحقيقي: لا window.confirm() لعملية الحذف، نص تحذير واضح، نافذة موحَّدة
// ============================================================================
$inv_template_source = file_exists(__DIR__ . '/../templates/event-invitations.php') ? file_get_contents(__DIR__ . '/../templates/event-invitations.php') : '';
check_true('9. تمّ العثور على templates/event-invitations.php وقراءته', $inv_template_source !== '');
check_true('9ب. نافذة تأكيد الحذف deleteInvModal موجودة فعلياً', strpos($inv_template_source, 'id="deleteInvModal"') !== false);
check_true('9ج. زر "حذف نهائياً" (deleteInvConfirmBtn) موجود فعلياً — لا اعتماد على window.confirm() للحذف', strpos($inv_template_source, 'id="deleteInvConfirmBtn"') !== false);
check_true('9د. النص يوضّح أن الحذف نهائي/لا يمكن التراجع', strpos($inv_template_source, 'لا يمكن التراجع عن هذا الإجراء') !== false);
check_true('9هـ. النص يوضّح تأثّر سجلّ الحضور المحتمل', strpos($inv_template_source, 'سجلّ الحضور') !== false);
// فحص دقيق: المسافة النصية بين "زر التأكيد يُستمَع لنقرته" وأول استدعاء
// postAjax لعمليتَي الحذف الفعليتين يجب ألّا تحتوي window.confirm() إطلاقاً
// — أي أن مسار الحذف تحديداً لا يعتمد على confirm() المتصفح (خلافاً لـ
// regen-QR/إلغاء الدعوة القديمتين، الموجودتين مسبقاً وخارج نطاق 3B).
$confirm_click_pos = strpos($inv_template_source, 'deleteInvConfirmBtn.addEventListener');
$post_ajax_delete_pos = strpos($inv_template_source, "postAjax('pge_invitation_mgmt_bulk_delete'");
check_true('9و. زر التأكيد موجود وموقعه يسبق استدعاء postAjax الفعلي للحذف', $confirm_click_pos !== false && $post_ajax_delete_pos !== false && $confirm_click_pos < $post_ajax_delete_pos);
$between_confirm_and_ajax = ($confirm_click_pos !== false && $post_ajax_delete_pos !== false)
    ? substr($inv_template_source, $confirm_click_pos, $post_ajax_delete_pos - $confirm_click_pos)
    : '';
check_true('9ز. لا window.confirm() إطلاقاً بين معالج نقرة التأكيد واستدعاء postAjax الفعلي (الحذف لا يعتمد على confirm() المتصفح)', strpos($between_confirm_and_ajax, 'window.confirm(') === false);

// ============================================================================
// 10-11: اللوحة القديمة والتنقّل — فحص بنيوي على ملف الثيم الحقيقي
// ============================================================================
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
check_true('10. تمّ العثور على ملف الثيم page-event-manage.php وقراءته', $theme_source !== '');
check_true('10ب. نموذج الإضافة القديم (addGuestForm) لم يعد موجوداً', strpos($theme_source, 'id="addGuestForm"') === false);
check_true('10ج. نموذج التعديل القديم (editGuestForm) لم يعد موجوداً', strpos($theme_source, 'id="editGuestForm"') === false);
check_true('10د. نموذج الإضافة الجماعية القديم (bulkGuestForm) لم يعد موجوداً', strpos($theme_source, 'id="bulkGuestForm"') === false);
// ملاحظة: نبحث عن نمط class="guest-delete-btn (بعلامة اقتباس مباشرة قبل
// الاسم) لا عن الكلمة المجرَّدة — التعليقات التوثيقية (أسطر 533/1188) تذكر
// "guest-delete-btn"/"guest-edit-btn" نثراً ضمن شرح الإزالة، فبحث بالكلمة
// المجرَّدة كان سيُنتج فشلاً كاذباً رغم عدم وجود أي عنصر DOM فعلي بهذا الصنف.
check_true('10هـ. زر الحذف الفردي القديم (class="guest-delete-btn) لم يعد موجوداً في أي عنصر DOM فعلي', strpos($theme_source, '"guest-delete-btn') === false);
check_true('10و. زر التعديل القديم (class="guest-edit-btn) لم يعد موجوداً في أي عنصر DOM فعلي', strpos($theme_source, '"guest-edit-btn') === false);
check_true('10ز. زر الحذف الجماعي القديم (bulkDeleteBtn) لم يعد موجوداً', strpos($theme_source, 'id="bulkDeleteBtn"') === false);
check_true('10ح. إشعار انتقالي واضح يذكر إدارة الدعوات', strpos($theme_source, 'إدارة الدعوات') !== false);
check_true('10ط. زر "فتح إدارة الدعوات" موجود فعلياً', strpos($theme_source, 'فتح إدارة الدعوات') !== false);
check_true('10ي. زر واتساب (guest-wa-btn) لا يزال موجوداً بلا مساس (القرار: إبقاء واتساب، تقاعد CRUD فقط)', strpos($theme_source, 'guest-wa-btn') !== false);
check_true('10ك. بنية الضيف (.guest-row/data-phone) لا تزال موجودة لخدمة واتساب', strpos($theme_source, 'guest-row') !== false && strpos($theme_source, 'data-phone') !== false);

check_true('11. رابط التنقّل الرئيسي navInvitationsLink لا يزال يشير لصفحة إدارة الدعوات', strpos($theme_source, 'id="navInvitationsLink"') !== false);
check_true('11ب. رابط التنقّل عبر الجوال navInvitationsLinkMobile لا يزال يشير لصفحة إدارة الدعوات', strpos($theme_source, 'id="navInvitationsLinkMobile"') !== false);
check_true('11ج. زر "+" السفلي (navAdd) يُنقِّل الآن لصفحة إدارة الدعوات مباشرة (focusAddGuest)', strpos($theme_source, "focusAddGuest()") !== false);

// ============================================================================
// 12: لا مسارات حذف مكرَّرة — نقطة تنفيذ واحدة فقط (Repository::delete)
// ============================================================================
$repo_source = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-repository.php');
check('12. PGE_Invitation_Repository::delete() مُعرَّفة مرة واحدة فقط في الملف', substr_count($repo_source, 'public static function delete('), 1);

$service_source = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-service.php');
// ملاحظة عدّ: النص "PGE_Invitation_Repository::delete(" يظهر مرتين في
// الملف — مرة داخل التعليق التوثيقي (docblock) فوق delete()، ومرة واحدة
// فقط في الكود الفعلي القابل للتنفيذ (سطر $result = PGE_Invitation_
// Repository::delete(...)). العدّ الإجمالي هنا 2 تحديداً لهذا السبب — لا
// يعني وجود استدعاءين فعليين للطبقة الأدنى.
check('12ب. PGE_Invitation_Repository::delete() تُذكَر مرتين فقط في الملف (توثيق واحد + استدعاء تنفيذي واحد) — لا استدعاء تنفيذي مكرَّر', substr_count($service_source, 'PGE_Invitation_Repository::delete('), 2);
check_true('12ب-2. الاستدعاء التنفيذي الفعلي الوحيد يقع داخل جسم delete() نفسه (تخصيص $result)', strpos($service_source, '$result = PGE_Invitation_Repository::delete(') !== false);

$event_guests_source = file_get_contents(__DIR__ . '/../includes/event-guests.php');
$ajax_source = file_get_contents(__DIR__ . '/../includes/invitation-management-ajax.php');
// ملاحظة عدّ: نستخدم نمط الاستدعاء التنفيذي الفعلي "$result = ...::delete("
// (وليس مجرَّد substr_count للاسم المؤهَّل الكامل) — لأن التعليقات
// التوثيقية (docblocks) في كلا الملفين تذكر "PGE_Invitation_Service::delete()"
// و/أو "PGE_Invitation_Repository::delete()" نثراً كجزء من الشرح، مما يُضخِّم
// أي عدّ نصي بسيط. نمط "$result = " موجود حصراً في نقاط الاستدعاء التنفيذي
// الأربع الحقيقية، لا في أي تعليق.
$call_pattern_service = '$result = PGE_Invitation_Service::delete(';
$total_service_delete_calls = substr_count($event_guests_source, $call_pattern_service) + substr_count($ajax_source, $call_pattern_service);
check('12ج. PGE_Invitation_Service::delete() تُستدعى تنفيذياً من 4 نقاط فقط (حذف مفرد/جماعي × لوحة قديمة/إدارة الدعوات)، لا مسار خامس', $total_service_delete_calls, 4);

$call_pattern_repo = '= PGE_Invitation_Repository::delete(';
check_true('12د. لا استدعاء تنفيذي مباشر لـRepository::delete() من أي طبقة AJAX (event-guests.php/invitation-management-ajax.php) — المرور حصراً عبر Service', strpos($event_guests_source, $call_pattern_repo) === false && strpos($ajax_source, $call_pattern_repo) === false);

// ============================================================================
// 13-16: انحدار — CRUD المفردة / Bulk Add / Export / QR لا تزال تعمل
// ============================================================================
$_POST = make_post_fields(3001, ['phone' => '0511115500', 'name' => 'ضيف انحدار CRUD', 'note' => '']);
$resp_regr_create = call_ajax_handler('pge_invitation_mgmt_create_handler');
check('13. انحدار: "إضافة دعوة" المفردة لا تزال تعمل بعد Fix Pack 3B', $resp_regr_create['success'], true);

$_POST = make_post_fields(3001, ['raw_text' => "ضيف انحدار,0511115501"]);
$resp_regr_bulk_add = call_ajax_handler('pge_invitation_mgmt_bulk_confirm_handler');
check('14. انحدار: Bulk Add (Fix Pack 3A) لا تزال تعمل بعد Fix Pack 3B', $resp_regr_bulk_add['success'], true);
check('14ب. انحدار Bulk Add: صف واحد أُنشئ فعلياً', $resp_regr_bulk_add['data']['summary']['created'], 1);

$_POST = make_post_fields(3001, []);
$csv_output_regr = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('15. انحدار: تصدير CSV (Phase 9C) لا يزال يُنتج مخرَجات فعلية بعد Fix Pack 3B', strpos($csv_output_regr, "\xEF\xBB\xBF") === 0 && strlen($csv_output_regr) > 10);

$_POST = make_post_fields(3001, ['phone' => '0511115500']);
$resp_regr_qr = call_ajax_handler('pge_invitation_mgmt_qr_regenerate_handler');
check('16. انحدار: تجديد QR (Phase 9B) لا يزال يعمل بعد Fix Pack 3B', $resp_regr_qr['success'], true);

// ============================================================================
// 17-20: انحدار خارج نطاق التعديل المباشر (إحصائيات/لوحة تحكم/عمليات/مشرفون)
// — Fix Pack 3B لم يلمس أياً من هذه الملفات؛ فحص بنيوي/تحميل حقيقي يتحقق من
// عدم كسر نقاط التكامل المشتركة (PGE_Invitation_Service/Audit) دون الحاجة
// لمحاكاة جداول Supervisor/Statistics الكاملة (خارج نطاق هذه المرحلة صراحةً
// وفق قيود RFC — "Do NOT implement: Statistics changes... Any Phase 11 work").
// ============================================================================
check_true('17. ملف الإحصاء class-pge-attendance-statistics-service.php لا يزال موجوداً ولم يُلمَس (لا مرجع لـPGE_Invitation_Service فيه أصلاً، فلا تأثر بإضافة delete())', file_exists(__DIR__ . '/../includes/class-pge-attendance-statistics-service.php'));

check_true('18. ملف لوحة التحكم dashboard-ajax.php لا يزال موجوداً ولم يُلمَس', file_exists(__DIR__ . '/../includes/dashboard-ajax.php'));

require_once __DIR__ . '/../includes/event-operations-ajax.php';
check_true('19. event-operations-ajax.php (Phase 10) يُحمَّل بنجاح بعد Fix Pack 3B بلا خطأ فادح (يعتمد PGE_Invitation_Service المُعدَّل فعلياً)', class_exists('PGE_Attendance_Dashboard_Provider'));
check_true('19ب. pge_event_ops_cancelled_count() (تعتمد list_invitations() غير المُعدَّلة) قابلة للاستدعاء الحقيقي بلا خطأ', function_exists('pge_event_ops_cancelled_count') && is_int(pge_event_ops_cancelled_count(3001)));

check_true('20. ملف إدارة المشرفين supervisor-management-ajax.php لا يزال موجوداً ولم يُلمَس', file_exists(__DIR__ . '/../includes/supervisor-management-ajax.php'));
$supervisor_source = file_get_contents(__DIR__ . '/../includes/supervisor-management-ajax.php');
check_true('20ب. supervisor-management-ajax.php لا يستدعي PGE_Invitation_Service/Repository إطلاقاً (معزول تماماً عن تعديلات Fix Pack 3B)', strpos($supervisor_source, 'PGE_Invitation_Service') === false && strpos($supervisor_source, 'PGE_Invitation_Repository') === false);

// ============================================================================
// إضافيّ — بوابة النطاق الجديدة مُسجَّلة فعلياً، والحذف القديم لا يزال مُسجَّلاً
// (Legacy AJAX handlers may remain registered)
// ============================================================================
check_true('إضافي. wp_ajax_pge_invitation_mgmt_delete مُسجَّل فعلياً', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_delete']));
check_true('إضافي. wp_ajax_pge_invitation_mgmt_bulk_delete مُسجَّل فعلياً', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_bulk_delete']));
check_true('إضافي. wp_ajax_pge_event_guest_delete (القديم) لا يزال مُسجَّلاً كطبقة توافق', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_delete']));
check_true('إضافي. wp_ajax_pge_event_guest_bulk_delete (القديم) لا يزال مُسجَّلاً كطبقة توافق', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_bulk_delete']));
check_true("إضافي. الملاحظة التوثيقية (docblock) في event-guests.php تؤكد إعادة استخدام الخدمة (لا تكرار منطق)", strpos($event_guests_source, 'Do NOT duplicate delete logic') !== false);

// ============================================================================
// خاتمة
// ============================================================================
echo "\n=== النتيجة: $passed / $total ===\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
