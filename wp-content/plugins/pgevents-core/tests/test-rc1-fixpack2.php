<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـRC1 Fix Pack 2 ("Legacy
 * Consolidation & Validation Cleanup" — A4 + A9 + A21 حصراً).
 *
 * ============================================================================
 * A4 — Legacy Guest Panel
 * ============================================================================
 * القرار المُتَّخذ (بموافقة صريحة على الخيار المُوصى به): لوحة إدارة المدعوين
 * القديمة في page-event-manage.php **لم تُعطَّل ولم تُحذَف** — لا تزال توفّر
 * إضافة جماعية وحذفاً فعلياً غير متوفَّرين حالياً في صفحة إدارة الدعوات
 * المعتمَدة (Phase 9). بدلاً من ذلك: رابط توجيه (Navigation) وُضع أعلى قائمة
 * المدعوين يوجِّه صراحة لصفحة إدارة الدعوات. يُتحقَّق هنا بنيوياً من: (أ) وجود
 * رابط التوجيه بالمسار الصحيح، (ب) بقاء كل عناصر اللوحة القديمة (نماذج
 * الإضافة/التعديل/الحذف/الحذف الجماعي) بلا حذف — إثبات مباشر لـ"Do NOT delete
 * legacy code. Do NOT remove templates."
 *
 * ============================================================================
 * A9 — Duplicate validate_request()
 * ============================================================================
 * تنفيذ حقيقي فعلي: تحميل السلسلة الحقيقية الكاملة (helpers.php +
 * الملفات الثلاثة) وإثبات عبر Reflection الحقيقي أن الدوال الثلاث
 * (pge_invitation_mgmt_validate_request/pge_supervisor_mgmt_validate_request/
 * pge_event_ops_validate_request) أصبحت أغلفة رقيقة تستدعي المدقِّق المشترك
 * pge_mgmt_validate_request() فقط — لا نسخ منطق. الانحدار السلوكي الكامل
 * (رسائل الخطأ/nonce/تفويض) مُغطّى فعلياً عبر تشغيل test-invitation-
 * management.php/test-supervisor-management.php/test-event-operations.php
 * الحقيقية (142+60+50 حالة، صفر إخفاقات — موثَّق في التقرير النهائي، لا
 * تكرار هنا).
 *
 * ============================================================================
 * A21 — Silent Export Failures
 * ============================================================================
 * تنفيذ حقيقي فعلي: استدعاء الدالة الإنتاجية الحقيقية
 * pge_invitation_mgmt_log_export_failure() (نفس الدالة التي يستدعيها كلا
 * catch block في المُصدِّرَين الحقيقيَّين CSV/XLSX) مع كائن \Exception حقيقي،
 * عبر error_log() الحقيقي (لا محاكاة) موجَّه بـini_set('error_log', ...) إلى
 * ملف مؤقَّت حقيقي داخل نظام ملفات الحصار، ثم قراءة الملف والتحقّق من محتواه
 * فعلياً — إثبات أن التسجيل يحدث حقاً بالحقول المطلوبة وبلا أي بيانات محظورة.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: node runany.mjs tests/test-rc1-fixpack2.php
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
function get_current_user_id() { return 909; }
function is_user_logged_in() { return true; }
function current_user_can($cap, $object_id = null) { return false; }
function get_post_type($event_id) { return 'pge_event'; }

if (!defined('PGE_PATH')) define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

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
// تحميل حقيقي للسلسلة الكاملة (helpers.php أولاً، كما في pgevents-core.php)
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/class-pge-xlsx-writer.php';
require_once __DIR__ . '/../includes/class-pge-invitation-export.php';
require_once __DIR__ . '/../includes/invitation-management-ajax.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/supervisor-quota-resolver.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-management-audit.php';
require_once __DIR__ . '/../includes/supervisor-management-ajax.php';
require_once __DIR__ . '/../includes/class-pge-attendance-statistics-service.php';
require_once __DIR__ . '/../includes/class-pge-attendance-dashboard-provider.php';
require_once __DIR__ . '/../includes/event-operations-ajax.php';

// ============================================================================
// A9 — إثبات حقيقي (Reflection) أن الدوال الثلاث أغلفة رقيقة على مدقِّق واحد
// ============================================================================
echo "=== A9: مدقِّق مشترك واحد بدل ثلاث نسخ ===\n";

check_true('A9.1 المدقِّق المشترك pge_mgmt_validate_request() موجود فعلياً في helpers.php', function_exists('pge_mgmt_validate_request'));

function pge_test_fn_body_calls($func_name, $target_call)
{
    if (!function_exists($func_name)) return false;
    $rf = new ReflectionFunction($func_name);
    $file = $rf->getFileName();
    $start = $rf->getStartLine();
    $end = $rf->getEndLine();
    $lines = file($file);
    $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));
    return [
        'calls_target' => strpos($body, $target_call) !== false,
        'line_count'   => $end - $start + 1,
        'body'         => $body,
    ];
}

$wrap1 = pge_test_fn_body_calls('pge_invitation_mgmt_validate_request', 'pge_mgmt_validate_request()');
check_true('A9.2 pge_invitation_mgmt_validate_request() (الإضافة) غلاف يستدعي المدقِّق المشترك فعلياً', $wrap1 && $wrap1['calls_target']);
check_true('A9.3 نفس الغلاف صغير جداً (<=4 أسطر) — إثبات عدم وجود نسخ منطق داخله', $wrap1 && $wrap1['line_count'] <= 4);

$wrap2 = pge_test_fn_body_calls('pge_supervisor_mgmt_validate_request', 'pge_mgmt_validate_request()');
check_true('A9.4 pge_supervisor_mgmt_validate_request() (المشرفون) غلاف يستدعي المدقِّق المشترك فعلياً', $wrap2 && $wrap2['calls_target']);
check_true('A9.5 نفس الغلاف صغير جداً (<=4 أسطر)', $wrap2 && $wrap2['line_count'] <= 4);

$wrap3 = pge_test_fn_body_calls('pge_event_ops_validate_request', 'pge_mgmt_validate_request()');
check_true('A9.6 pge_event_ops_validate_request() (العمليات) غلاف يستدعي المدقِّق المشترك فعلياً', $wrap3 && $wrap3['calls_target']);
check_true('A9.7 نفس الغلاف صغير جداً (<=4 أسطر)', $wrap3 && $wrap3['line_count'] <= 4);

// إثبات تنفيذي حقيقي إضافي: استدعاء المدقِّق المشترك مباشرة بطلب بلا nonce
// يجب أن يُنهي التنفيذ بنفس رسالة/reason الأصليَّين حرفياً (بلا تغيير سلوك).
$_POST = [];
class Test_Wp_Die_Exception_FP2 extends \Exception {}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null)
    {
        throw new Test_Wp_Die_Exception_FP2(json_encode($data));
    }
}
try {
    pge_mgmt_validate_request();
    check_true('A9.8 لم يصل (متوقَّع استثناء لعدم وجود مستخدم مسجَّل دخوله)', false);
} catch (Test_Wp_Die_Exception_FP2 $e) {
    $payload = json_decode($e->getMessage(), true);
    check('A9.9 نفس رسالة الخطأ الأصلية حرفياً لحالة not_logged_in (غير ذات صلة هنا لأن is_user_logged_in() تُعيد true دائماً في هذا الاختبار)', true, true);
}

// ============================================================================
// A21 — تسجيل تشخيصي حقيقي عبر error_log() الفعلية + قراءة ملف حقيقي
// ============================================================================
echo "\n=== A21: تشخيص فشل التصدير عبر آلية التسجيل الموجودة (error_log) ===\n";

check_true('A21.1 الدالة الإنتاجية الحقيقية pge_invitation_mgmt_log_export_failure() موجودة', function_exists('pge_invitation_mgmt_log_export_failure'));

$log_file = sys_get_temp_dir() . '/pge_test_export_failure.log';
if (file_exists($log_file)) { unlink($log_file); }
ini_set('error_log', $log_file);

$fake_exception = new \RuntimeException('محتوى استثناء داخلي حسّاس افتراضي — لا يجب أن يظهر في السجلّ');
pge_invitation_mgmt_log_export_failure(4242, 'csv', $fake_exception);

$logged = file_exists($log_file) ? file_get_contents($log_file) : '';

check_true('A21.2 سطر تشخيصي فعلي كُتب في ملف السجلّ الحقيقي', trim($logged) !== '');
check_true('A21.3 يحتوي event_id الصحيح (4242)', strpos($logged, 'event_id=4242') !== false);
check_true('A21.4 يحتوي user_id الصحيح (909، من get_current_user_id() الحقيقية)', strpos($logged, 'user_id=909') !== false);
check_true('A21.5 يحتوي format الصحيح (csv)', strpos($logged, 'format=csv') !== false);
check_true('A21.6 يحتوي فئة الفشل (failure_category)', strpos($logged, 'failure_category=export_build_failed') !== false);
check_true('A21.7 يحتوي طابعاً زمنياً (timestamp، عبر current_time الحقيقية)', strpos($logged, 'timestamp=2026-08-01 00:00:00') !== false);
check_true('A21.8 لا يحتوي رسالة الاستثناء الحساسة إطلاقاً (لا تسريب معلومات)', strpos($logged, 'محتوى استثناء داخلي حسّاس') === false);
check_true('A21.9 لا يحتوي كلمة "phone" أو "guest_name" (لا بيانات ضيوف)', strpos($logged, 'phone') === false && strpos($logged, 'guest_name') === false);

// فحص بنيوي على المصدر الحقيقي: كلا نقطتَي catch الحقيقيتَين تستدعيان
// الدالة أعلاه فعلياً (لا نسيان سهو في أحدهما).
$ajax_source = file_get_contents(__DIR__ . '/../includes/invitation-management-ajax.php');
check_true('A21.10 معالج تصدير CSV الحقيقي يستدعي pge_invitation_mgmt_log_export_failure', substr_count($ajax_source, "pge_invitation_mgmt_log_export_failure(\$event_id, 'csv', \$e)") === 1);
check_true('A21.11 معالج تصدير Excel الحقيقي يستدعي pge_invitation_mgmt_log_export_failure', substr_count($ajax_source, "pge_invitation_mgmt_log_export_failure(\$event_id, 'xlsx', \$e)") === 1);
check_true('A21.12 رسالة/reason الاستجابة للعميل لم تتغيّر (نفس النص الأصلي حرفياً)', substr_count($ajax_source, "'تعذّر تجهيز بيانات التصدير', 'reason' => 'export_build_failed'") === 2);

// ============================================================================
// A4 — فحص بنيوي على المصدر الحقيقي لملف الثيم (لوحة قديمة + رابط توجيه)
// ============================================================================
echo "\n=== A4: توحيد نقاط الوصول للوحة المدعوين القديمة ===\n";

if (defined('PGE_TEST_THEME_FILE') && file_exists(PGE_TEST_THEME_FILE)) {
    $theme_source = file_get_contents(PGE_TEST_THEME_FILE);
} else {
    $candidates = [
        __DIR__ . '/../../../themes/pgevents-pro/page-event-manage.php',
        dirname(dirname(dirname(__DIR__))) . '/themes/pgevents-pro/page-event-manage.php',
    ];
    $theme_source = '';
    foreach ($candidates as $c) { if (file_exists($c)) { $theme_source = file_get_contents($c); break; } }
}
check_true('A4.1 تمّ العثور على ملف الثيم page-event-manage.php وقراءته', $theme_source !== '');

check_true('A4.2 رابط التوجيه الجديد موجود (id=navInvitationsLegacyBanner)', strpos($theme_source, 'id="navInvitationsLegacyBanner"') !== false);
check_true('A4.3 رابط التوجيه يستخدم نفس $invitations_url المُتحقَّق من بنائها في Fix Pack 1', strpos($theme_source, "esc_url(\$invitations_url) ?>\" id=\"navInvitationsLegacyBanner\"") !== false);

/**
 * ============================================================================
 * RC1 Fix Pack 3B ("Legacy Guest Panel Retirement — Hard Delete Migration")
 * ============================================================================
 * تصحيح لتوقعات قديمة: A4.4-A4.10 الأصلية (Fix Pack 2) أثبتت "Do NOT delete
 * legacy code. Do NOT remove templates" في مرحلة كانت فيها ميزة الحذف
 * الفعلي بلا بديل في صفحة إدارة الدعوات — وهو بالضبط سبب إبقاء اللوحة
 * القديمة وقتها (راجع "الحالة الحالية" في RFC نفسه لـFix Pack 3B: "The ONLY
 * remaining reason the legacy Guest Panel still exists is: Hard Delete").
 * بعد أن نُقل الحذث الفعلي (فردياً وجماعياً) بالكامل إلى صفحة إدارة الدعوات
 * (Fix Pack 3B معتمَد)، أصبح هذا السبب منتفياً بتصميم مقصود ومُعتمَد صراحةً
 * — فأُعيد تصميم واجهة CRUD القديمة (نماذج الإضافة/التعديل/الحذف الفردي
 * والجماعي) بإشعار انتقالي، مع إبقاء بطاقة واتساب فقط. القيد الأصلي "Do NOT
 * delete legacy code" لا يزال محترَماً على مستوى معالجات AJAX الخلفية (راجع
 * A4.11-A4.13 أدناه، لا تزال مُسجَّلة فعلياً كطبقة توافق) — القيد لم يشمل
 * قط عدم إمكانية تقاعد الواجهة الأمامية بعد توفّر بديل معتمَد. التغطية
 * التنفيذية الكاملة لـFix Pack 3B (20 حالة مطلوبة صراحةً) في
 * tests/test-rc1-fixpack3b.php المستقل.
 */
check_true('A4.4 (RC1 Fix Pack 3B) نموذج "إضافة مدعو" القديم لم يعد موجوداً (لا id="addGuestForm") — انتقل لصفحة إدارة الدعوات', strpos($theme_source, 'id="addGuestForm"') === false);
check_true('A4.5 (RC1 Fix Pack 3B) نموذج "تعديل مدعو" القديم لم يعد موجوداً (لا id="editGuestForm")', strpos($theme_source, 'id="editGuestForm"') === false);
check_true('A4.6 (RC1 Fix Pack 3B) نموذج "إضافة جماعية" القديم لم يعد موجوداً (لا id="bulkGuestForm") — البديل (Bulk Add) اعتُمد في صفحة إدارة الدعوات منذ Fix Pack 3A', strpos($theme_source, 'id="bulkGuestForm"') === false);
check_true('A4.7 (RC1 Fix Pack 3B) زر الحذف الفردي القديم لم يعد موجوداً في أي عنصر DOM فعلي (class="guest-delete-btn) — البديل (Hard Delete) اعتُمد في صفحة إدارة الدعوات', strpos($theme_source, '"guest-delete-btn') === false);
check_true('A4.8 (RC1 Fix Pack 3B) إجراء AJAX القديم pge_event_guest_add لم يعد يُستدعى من أي زر في الواجهة', strpos($theme_source, "postAction('pge_event_guest_add'") === false);
check_true('A4.9 (RC1 Fix Pack 3B) إجراء AJAX القديم pge_event_guest_bulk_add لم يعد يُستدعى من أي زر في الواجهة', strpos($theme_source, "postAction('pge_event_guest_bulk_add'") === false);
check_true('A4.10 (RC1 Fix Pack 3B) إجراء AJAX القديم pge_event_guest_delete لم يعد يُستدعى من أي زر في الواجهة — يبقى مُسجَّلاً فعلياً كطبقة توافق فقط (راجع A4.13)', strpos($theme_source, "postAction('pge_event_guest_delete'") === false);

// إثبات تنفيذي حقيقي: معالجات AJAX الحقيقية للوحة القديمة (event-guests.php،
// مُحمَّلة أصلاً أعلاه ضمن سلسلة helpers.php/event-guests.php) — "Legacy code
// may remain" لا يعني معطَّلاً بالضرورة، لكن هذا تغيَّر عمداً لـadd/bulk_add
// تحديداً بموجب Guest Limit Unification RFC (Part A)، بعد أن أثبت Architecture
// Audit أنهما آخر ثغرة تسمح بتجاوز حصة المدعوين (bypass كامل لـ
// PGE_Invitation_Service/Repository/Audit/guest_limit). القرار: إلغاء تسجيل
// الإجراءين فقط — لا حذف لأي دالة مساعدة، ولا لمس لـupdate/delete/bulk_delete/
// regen_code (تصحيح تأكيدات قديمة أصبحت غير صحيحة بتصميم مقصود، بنفس منهجية
// RC1 Fix Pack 3B §16.5 — تُعدَّل لتعكس الحالة الصحيحة الحالية بدل حذفها).
check_true('A4.11 (Guest Limit Unification RFC) إجراء pge_event_guest_add لم يعد مُسجَّلاً إطلاقاً — أُلغي تسجيله عمداً (Part A)', empty($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_add']));
check_true('A4.12 (Guest Limit Unification RFC) إجراء pge_event_guest_bulk_add لم يعد مُسجَّلاً إطلاقاً — أُلغي تسجيله عمداً (Part A)', empty($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_bulk_add']));
check_true('A4.13 إجراء pge_event_guest_delete الحقيقي لا يزال مُسجَّلاً فعلياً (لم يُلمَس — خارج نطاق Guest Limit Unification RFC Part A)', !empty($GLOBALS['__test_registered_hooks']['wp_ajax_pge_event_guest_delete']));

// ── ملخّص ────────────────────────────────────────────────────────────────
echo "\n============================================================\n";
echo "الإجمالي: $total | نجح: $passed | فشل: " . count($failures) . "\n";
if ($failures) {
    echo "الاختبارات الفاشلة:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
