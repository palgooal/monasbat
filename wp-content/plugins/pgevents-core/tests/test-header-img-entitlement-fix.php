<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لتغطية إصلاح "صورة الهيدر ليست
 * ميزة مدفوعة" — التعديل الوحيد المطبَّق هو Short-circuit صريح داخل
 * pge_plan_feature_enabled_for_events() في includes/event-factory.php:
 *
 *   if ((string) $feature_key === 'header_img') { return true; }
 *
 * الهدف من هذا الملف إثبات ثلاثة أمور معاً، بتنفيذ فعلي للكود الحقيقي
 * (لا Mock لدالة الإصلاح نفسها):
 *   1) header_img تُعيد true دائماً، بصرف النظر عن مصدر البيانات (Legacy
 *      مباشر عبر mon_packages_settings، Legacy عبر _mon_active_features
 *      قديم بلا الميزة أصلاً، Legacy مع القيمة صراحة false/absent، Catalog
 *      Snapshot ناقص أو صريح المنع، أو حتى مصفوفة $limits حرفية بلا أي
 *      resolver إطلاقاً).
 *   2) بقية مفاتيح الميزات (13 مفتاحاً آخر) تستمر بنفس السلوك تماماً —
 *      مفعّلة تبقى مفعّلة، معطّلة تبقى معطّلة، غائبة تبقى false.
 *   3) PGE_Packages::is_feature_enabled() نفسها (class-pge-packages.php)
 *      لم تُلمَس إطلاقاً — تستمر بإرجاع القيمة الحقيقية المخزَّنة لـheader_img
 *      حين تُستدعى مباشرة، ما يثبت أن الإصلاح محصور في الغلاف
 *      pge_plan_feature_enabled_for_events() فقط ولم يتسرّب لأي طبقة أخرى.
 *
 * التشغيل:
 *   php tests/test-header-img-entitlement-fix.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (نفس نمط tests/test-catalog-plan-limits.php) ─────

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_options']  = [];

function add_action(...$args) { /* no-op */ }

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) { return true; }
}

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}

function set_test_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
}

function reset_test_user($user_id)
{
    $GLOBALS['__test_user_meta'][$user_id] = [];
}

function get_option($name, $default = false)
{
    return $GLOBALS['__test_options'][$name] ?? $default;
}

function absint($value)
{
    return abs((int) $value);
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
}

if (!function_exists('esc_html')) {
    function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('esc_url')) {
    function esc_url($v) { return (string) $v; }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $code;
        public $message;
        public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
        public function get_error_message() { return $this->message; }
    }
}

// ── كلاس Catalog وهمي (فقط PGE_Catalog::get_tier() مستخدَمة) ──────────────

class PGE_Catalog
{
    public static $tiers = [];

    public static function get_tier($tier_id)
    {
        return self::$tiers[$tier_id] ?? null;
    }
}

// ── تحميل الملفات الحقيقية من المشروع (بلا أي تعديل عليها في هذا الاختبار) ─

require_once __DIR__ . '/../includes/class-pge-packages.php';
require_once __DIR__ . '/../includes/event-factory.php';

// ── أداة تأكيد صغيرة ────────────────────────────────────────────────────

$GLOBALS['__failures'] = 0;
$GLOBALS['__total']    = 0;

function check($label, $actual, $expected)
{
    $GLOBALS['__total']++;
    if ($actual === $expected) {
        echo "  PASS: $label\n";
        return;
    }
    $GLOBALS['__failures']++;
    echo "  FAIL: $label\n";
    echo "        expected: " . var_export($expected, true) . "\n";
    echo "        actual:   " . var_export($actual, true) . "\n";
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

function check_false($label, $condition)
{
    check($label, (bool) $condition, false);
}

// ============================================================================
// 1) header_img = true دائماً عبر مصفوفة $limits حرفية (بلا أي resolver) —
//    يغطي حالتي "غائبة تماماً" و"موجودة صراحة بقيمة false/0"
// ============================================================================
echo "1) header_img دائماً true بصرف النظر عن \$limits المُمرَّرة مباشرة\n";
check_true('1.1 \$limits فارغة تماماً (المفتاح غائب) → true', pge_plan_feature_enabled_for_events([], 'header_img'));
check_true('1.2 header_img موجودة صراحة بقيمة false → true', pge_plan_feature_enabled_for_events(['header_img' => false], 'header_img'));
check_true('1.3 header_img موجودة صراحة بقيمة 0 → true', pge_plan_feature_enabled_for_events(['header_img' => 0], 'header_img'));
check_true("1.4 header_img موجودة صراحة بقيمة النص '0' → true", pge_plan_feature_enabled_for_events(['header_img' => '0'], 'header_img'));
check_true('1.5 \$limits ليست array أصلاً (null) → true', pge_plan_feature_enabled_for_events(null, 'header_img'));

// ============================================================================
// 2) ميزات أخرى غير متعلقة — يجب أن تستمر بنفس السلوك السابق تماماً (لا تأثر)
// ============================================================================
echo "\n2) ميزات أخرى غير متعلقة تبقى كما كانت (بلا أي تأثر من الإصلاح)\n";
check_false('2.1 ميزة معطّلة صراحة (google_map=0) تبقى false', pge_plan_feature_enabled_for_events(['google_map' => '0', 'header_img' => '0'], 'google_map'));
check_true('2.2 ميزة مفعّلة صراحة (guest_photos=1) تبقى true', pge_plan_feature_enabled_for_events(['guest_photos' => '1'], 'guest_photos'));
check_false('2.3 ميزة غائبة تماماً (public_chat) تبقى false (بخلاف header_img)', pge_plan_feature_enabled_for_events(['header_img' => '0'], 'public_chat'));
check_false('2.4 مفتاح غير معروف عشوائي يبقى false (fallback الأصلي لم يتغيّر)', pge_plan_feature_enabled_for_events(['header_img' => '0'], 'some_future_feature'));

// ============================================================================
// 3) مستخدم Legacy — عبر الدالة المركزية الكاملة pge_get_user_plan_limits_for_events()
// ============================================================================
echo "\n3) مستخدم Legacy (عبر mon_packages_settings + PGE_Packages الحقيقي)\n";

$GLOBALS['__test_options']['mon_packages_settings'] = [
    'plan_1' => ['name' => 'الباقة الأساسية', 'guest_limit' => 15, 'host_photos' => 10, 'events_count' => 1, 'header_img' => '0', 'google_map' => '0'],
    'plan_2' => ['name' => 'الباقة الفضية',   'guest_limit' => 50, 'host_photos' => 25, 'events_count' => 3, 'header_img' => '1', 'google_map' => '1'],
];

// 3.1: باقة أساسية بها header_img='0' صراحة في إعدادات الأدمن — بلا أي
// _mon_active_features مخزَّنة (مستخدم قديم فعّل الباقة قبل أن يُكتب له
// Snapshot الميزات، أو بلا سياق باقة إطلاقاً غير plan_key).
reset_test_user(301);
set_test_user_meta(301, '_mon_package_key', 'plan_1');
$limits_301 = pge_get_user_plan_limits_for_events(301);
check_true('3.1 Legacy plan_1 (header_img=0 في mon_packages_settings، بلا Snapshot) → header_img=true', pge_plan_feature_enabled_for_events($limits_301, 'header_img'));
check_false('3.1 نفس المستخدم: google_map=0 في نفس الباقة يبقى false (غير متأثر)', pge_plan_feature_enabled_for_events($limits_301, 'google_map'));

// 3.2: Snapshot قديم صريح (_mon_active_features) بلا 'header_img' فيه إطلاقاً
// — يمثّل "old snapshot with header_img absent" حرفياً كما طلب المستخدم.
reset_test_user(302);
set_test_user_meta(302, '_mon_package_key', 'plan_2');
set_test_user_meta(302, '_mon_active_features', ['google_map']); // header_img غير مذكورة إطلاقاً
$limits_302 = pge_get_user_plan_limits_for_events(302);
check_true('3.2 Snapshot قديم بلا header_img إطلاقاً → header_img=true رغم ذلك', pge_plan_feature_enabled_for_events($limits_302, 'header_img'));
check_true('3.2 نفس المستخدم: google_map المذكورة في Snapshot تبقى true (غير متأثرة)', pge_plan_feature_enabled_for_events($limits_302, 'google_map'));

// 3.3: Snapshot قديم صريح بقيمة header_img=false فعلياً (الميزة كانت مذكورة
// في القائمة السوداء ضمنياً — أي غير موجودة في المصفوفة المفعَّلة، بينما
// ميزة أخرى غير مفعّلة أيضاً للتأكد أنها تبقى معطّلة).
reset_test_user(303);
set_test_user_meta(303, '_mon_package_key', 'plan_2');
set_test_user_meta(303, '_mon_active_features', ['guest_photos']); // لا header_img ولا google_map
$limits_303 = pge_get_user_plan_limits_for_events(303);
check_true('3.3 Snapshot صريح بلا header_img (القيمة الفعلية المضمّنة=معطّلة) → header_img=true رغم ذلك', pge_plan_feature_enabled_for_events($limits_303, 'header_img'));
check_false('3.3 نفس المستخدم: google_map غير المذكورة في Snapshot تبقى false (غير متأثرة)', pge_plan_feature_enabled_for_events($limits_303, 'google_map'));
check_true('3.3 نفس المستخدم: guest_photos المذكورة تبقى true (غير متأثرة)', pge_plan_feature_enabled_for_events($limits_303, 'guest_photos'));

// ============================================================================
// 4) مستخدم Catalog — عبر pge_get_catalog_user_plan_limits_for_events()
// ============================================================================
echo "\n4) مستخدم Catalog (عبر _mon_catalog_features Snapshot + PGE_Catalog وهمي)\n";

PGE_Catalog::$tiers[10] = [
    'plan_id'            => 5,
    'events_count'       => 3,
    'host_photos_limit'  => 25,
    'wa_messages_limit'  => 50,
];

// 4.1: مستخدم Catalog نشط، Snapshot الميزات لا يحتوي header_img إطلاقاً
// (يمثّل "old/incomplete Catalog snapshot with header_img absent").
reset_test_user(401);
set_test_user_meta(401, '_mon_package_source', 'catalog');
set_test_user_meta(401, '_mon_package_status', 'active');
set_test_user_meta(401, '_mon_catalog_plan_id', 5);
set_test_user_meta(401, '_mon_catalog_tier_id', 10);
set_test_user_meta(401, '_mon_catalog_features', ['google_map']); // header_img غائبة عمداً
$limits_401 = pge_get_user_plan_limits_for_events(401);
check_true('4.1 Catalog Snapshot بلا header_img → header_img=true رغم ذلك', pge_plan_feature_enabled_for_events($limits_401, 'header_img'));
check_true('4.1 نفس المستخدم: google_map المذكورة تبقى true (غير متأثرة)', pge_plan_feature_enabled_for_events($limits_401, 'google_map'));
check_false('4.1 نفس المستخدم: guest_photos غير المذكورة تبقى false (غير متأثرة)', pge_plan_feature_enabled_for_events($limits_401, 'guest_photos'));

// 4.2: مستخدم Catalog غير نشط (status != active) — حدود صفرية آمنة، ويجب أن
// يبقى header_img=true رغم ذلك لأن الإصلاح Short-circuit قبل أي فحص لحالة
// التفعيل إطلاقاً.
reset_test_user(402);
set_test_user_meta(402, '_mon_package_source', 'catalog');
set_test_user_meta(402, '_mon_package_status', 'expired');
$limits_402 = pge_get_user_plan_limits_for_events(402);
check_true('4.2 Catalog غير نشط (status=expired) → header_img=true رغم ذلك', pge_plan_feature_enabled_for_events($limits_402, 'header_img'));
check_false('4.2 نفس المستخدم: google_map تبقى false (حدود صفرية آمنة، غير متأثرة)', pge_plan_feature_enabled_for_events($limits_402, 'google_map'));

// ============================================================================
// 5) إثبات أن PGE_Packages::is_feature_enabled() نفسها لم تُلمَس إطلاقاً —
//    الإصلاح محصور في الغلاف pge_plan_feature_enabled_for_events() فقط
// ============================================================================
echo "\n5) PGE_Packages::is_feature_enabled() (class-pge-packages.php) بلا أي تعديل\n";
check_false('5.1 استدعاء الكلاس مباشرة (بدون الغلاف) لا يزال يُرجع القيمة الحقيقية المخزَّنة (false)', PGE_Packages::is_feature_enabled(['header_img' => '0'], 'header_img'));
check_true('5.2 استدعاء الكلاس مباشرة بقيمة مفعّلة لا يزال يُرجع true كما هو متوقَّع', PGE_Packages::is_feature_enabled(['header_img' => '1'], 'header_img'));
check_true('5.3 والغلاف نفسه لنفس الحالة (5.1) يُعيد true (هذا هو الفرق المقصود الوحيد)', pge_plan_feature_enabled_for_events(['header_img' => '0'], 'header_img'));

// ============================================================================
// الخلاصة
// ============================================================================
echo "\n" . str_repeat('=', 70) . "\n";
echo "النتيجة: {$GLOBALS['__total']} فحصاً، ";
if ($GLOBALS['__failures'] === 0) {
    echo "كلها ناجحة (0 فشل).\n";
    exit(0);
}
echo "{$GLOBALS['__failures']} فشل.\n";
exit(1);
