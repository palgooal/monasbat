<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit وبلا أي بنية اختبارات جديدة) لتغطية
 * الحد الأدنى المطلوب في مهمة "دمج Catalog مع نظام الصلاحيات الفعلي —
 * المرحلة الأولى". يعتمد على الملفات الحقيقية للمشروع:
 *   - includes/event-factory.php  (الدالة المركزية + مسار Catalog الجديد)
 *   - includes/class-pge-packages.php (مسار Legacy الحالي، بلا أي تعديل)
 *
 * لا يلمس أي قاعدة بيانات حقيقية: كل دوال ووردبريس المستخدمة (get_user_meta،
 * get_option، absint) مُستبدَلة بدوال وهمية (Stubs) بسيطة تعمل فوق مصفوفات في
 * الذاكرة فقط. كلاس PGE_Catalog مُستبدَل بالكامل بكلاس وهمي صغير (بدل
 * الاعتماد على $wpdb/قاعدة بيانات فعلية)، لأن الدالتين الجديدتين تستخدمان
 * فقط PGE_Catalog::get_tier() — لا حاجة لتحميل الكلاس الحقيقي بكل تعقيده.
 *
 * التشغيل:
 *   php tests/test-catalog-plan-limits.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة (لتسهيل ربطها
 * بأي CI مستقبلاً دون أي اعتماد إضافي).
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب فقط) ─────────────────────────

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_options']  = [];

function add_action(...$args) { /* no-op: لا حاجة للـ Hooks في هذا الاختبار */ }

if (!function_exists('add_shortcode')) {
    // مطلوبة لأن class-pge-packages.php يسجّل shortcode عند التحميل مباشرة
    // (add_shortcode('pge_packages', ...) في آخر سطر من الملف) — استدعاء على
    // مستوى الملف وليس داخل دالة، فيُنفَّذ فوراً عند require_once.
    function add_shortcode($tag, $callback) {
        return true;
    }
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

// ── Stubs إضافية للمرحلة الثانية (حماية Catalog من مسارات Legacy) ─────────
// مطلوبة لتحميل class-mon-events-users.php وclass-salla-handler.php الحقيقيين
// واستدعاء deactivate_user_package()/activate_user_package() فعلياً (عبر
// Reflection للدالة الخاصة الأولى) بدل الاكتفاء بتتبّع منطقي فقط.

$GLOBALS['__test_users_by_email'] = [];

function get_user_by($field, $value)
{
    if ($field === 'email') {
        $id = $GLOBALS['__test_users_by_email'][$value] ?? null;
        return $id === null ? false : (object) ['ID' => $id];
    }
    return false;
}

function set_test_user_email($email, $user_id)
{
    $GLOBALS['__test_users_by_email'][$email] = $user_id;
}

function delete_user_meta($user_id, $key)
{
    unset($GLOBALS['__test_user_meta'][$user_id][$key]);
    return true;
}

function update_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
    return true;
}

function metadata_exists($type, $object_id, $meta_key)
{
    $value = $GLOBALS['__test_user_meta'][$object_id][$meta_key] ?? '';
    return $value !== '';
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v) { return json_encode($v); }
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }
}

if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}

function add_filter(...$args) { /* no-op: لا حاجة للـ Hooks في هذا الاختبار */ }

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;
        public function __construct($data = [], $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

// ── كلاس Catalog وهمي — بديل كامل عن class-pge-catalog.php لهذا الاختبار ──
// (الدالتان الجديدتان تستدعيان PGE_Catalog::get_tier() فقط)

class PGE_Catalog
{
    public static $tiers = [];

    public static function get_tier($tier_id)
    {
        return self::$tiers[$tier_id] ?? null;
    }
}

// ── تحميل الملفات الحقيقية من المشروع (بلا أي تعديل عليها) ─────────────────

require_once __DIR__ . '/../includes/class-pge-packages.php';
require_once __DIR__ . '/../includes/event-factory.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/class-mon-events-users.php';
require_once __DIR__ . '/../includes/class-salla-handler.php';

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

// ============================================================================
// 1) Legacy user remains unchanged
// ============================================================================
echo "1) Legacy user remains unchanged\n";
$GLOBALS['__test_options']['mon_packages_settings'] = [
    'plan_2' => ['name' => 'الباقة الفضية', 'price' => '149', 'guest_limit' => 50, 'host_photos' => 25, 'events_count' => 3],
];
reset_test_user(201);
set_test_user_meta(201, '_mon_package_key', 'plan_2');
set_test_user_meta(201, '_mon_guest_limit', 60);
set_test_user_meta(201, '_mon_active_features', ['header_img', 'google_map']);
// عمداً بلا _mon_package_source إطلاقاً — تماماً كمستخدم Legacy قديم حقيقي.

$legacy_via_central = pge_get_user_plan_limits_for_events(201);
$legacy_direct       = PGE_Packages::get_user_plan_limits(201);
check('نفس نتيجة PGE_Packages::get_user_plan_limits() تماماً (لا تغيير في السلوك)', $legacy_via_central, $legacy_direct);
check('events_count = 3 (من إعدادات الباقة، لا Catalog)', (int) $legacy_via_central['events_count'], 3);
check('guest_limit = 60 (override من user meta كما كان قبل التعديل)', (int) $legacy_via_central['guest_limit'], 60);
check_true('google_map مفعّلة عبر _mon_active_features', pge_plan_feature_enabled_for_events($legacy_via_central, 'google_map'));

// ============================================================================
// 2) Active Catalog user receives Catalog limits
// ============================================================================
echo "\n2) Active Catalog user receives Catalog limits\n";
PGE_Catalog::$tiers[10] = [
    'id' => 10, 'plan_id' => 5, 'tier_key' => 'classic-100',
    'events_count' => 3, 'host_photos_limit' => 15, 'wa_messages_limit' => 0,
];
reset_test_user(202);
set_test_user_meta(202, '_mon_package_source', 'catalog');
set_test_user_meta(202, '_mon_package_status', 'active');
set_test_user_meta(202, '_mon_catalog_plan_id', 5);
set_test_user_meta(202, '_mon_catalog_tier_id', 10);
set_test_user_meta(202, '_mon_guest_limit', 80);
set_test_user_meta(202, '_mon_catalog_features', ['google_map', 'header_img']);

$catalog_limits = pge_get_user_plan_limits_for_events(202);
check('events_count = 3 (من tier.events_count)', (int) $catalog_limits['events_count'], 3);
check('guest_limit = 80 (من _mon_guest_limit Snapshot)', (int) $catalog_limits['guest_limit'], 80);
check('host_photos = 15 (من tier.host_photos_limit)', (int) $catalog_limits['host_photos'], 15);
check('wa_messages = 0 (من tier.wa_messages_limit)', (int) $catalog_limits['wa_messages'], 0);
check_true('google_map مفعّلة عبر _mon_catalog_features', pge_plan_feature_enabled_for_events($catalog_limits, 'google_map'));
check_true('header_img مفعّلة عبر _mon_catalog_features', pge_plan_feature_enabled_for_events($catalog_limits, 'header_img'));
check_true('stc_pay غير مفعّلة (لم تُذكر في _mon_catalog_features)', !pge_plan_feature_enabled_for_events($catalog_limits, 'stc_pay'));

// ============================================================================
// 3) Catalog user ignores stale Legacy limits (نفس المستخدم + بيانات Legacy قديمة)
// ============================================================================
echo "\n3) Catalog user ignores stale Legacy limits\n";
set_test_user_meta(202, '_mon_package_key', 'plan_4');       // بيانات Legacy قديمة متبقية
set_test_user_meta(202, '_mon_active_features', ['stc_pay']); // بيانات Legacy قديمة متبقية
set_test_user_meta(202, '_mon_events_limit', 999);            // بيانات Legacy قديمة متبقية
set_test_user_meta(202, '_mon_host_photos_limit', 999);       // بيانات Legacy قديمة متبقية

$catalog_limits_with_stale_legacy = pge_get_user_plan_limits_for_events(202);
check('النتيجة مطابقة تماماً لنتيجة الحالة 2 رغم وجود Legacy قديم', $catalog_limits_with_stale_legacy, $catalog_limits);
check('events_count تبقى 3 وليست 999', (int) $catalog_limits_with_stale_legacy['events_count'], 3);
check_true('stc_pay تبقى غير مفعّلة رغم وجودها في _mon_active_features Legacy', !pge_plan_feature_enabled_for_events($catalog_limits_with_stale_legacy, 'stc_pay'));

// ============================================================================
// 4) Expired Catalog user does not fall back to Legacy
// ============================================================================
echo "\n4) Expired Catalog user does not fall back to Legacy\n";
set_test_user_meta(202, '_mon_package_status', 'expired');

$expired_limits = pge_get_user_plan_limits_for_events(202);
check('events_count = 0 (لا رجوع لـ999 من Legacy)', (int) $expired_limits['events_count'], 0);
check('guest_limit = 0 (لا رجوع لـ80 من Catalog القديم ولا لأي Legacy)', (int) $expired_limits['guest_limit'], 0);
check('host_photos = 0', (int) $expired_limits['host_photos'], 0);
check_true('google_map غير مفعّلة بعد انتهاء الاشتراك', !pge_plan_feature_enabled_for_events($expired_limits, 'google_map'));
check_true('stc_pay غير مفعّلة (لم تُستخدم بيانات Legacy إطلاقاً)', !pge_plan_feature_enabled_for_events($expired_limits, 'stc_pay'));

// ============================================================================
// 5) Catalog features are returned from _mon_catalog_features (كل الأشكال الممكنة)
// ============================================================================
echo "\n5) Catalog features normalization (array / JSON / serialized / فارغ / تالف)\n";
check('array عادية', pge_normalize_catalog_features_meta(['google_map', 'header_img', 'google_map']), ['google_map', 'header_img']);
check('JSON نصي', pge_normalize_catalog_features_meta('["google_map","stc_pay"]'), ['google_map', 'stc_pay']);
check('serialized نصي (PHP)', pge_normalize_catalog_features_meta(serialize(['countdown'])), ['countdown']);
check('نص فارغ', pge_normalize_catalog_features_meta(''), []);
check('null', pge_normalize_catalog_features_meta(null), []);
check('نص تالف/غير صالح', pge_normalize_catalog_features_meta('{not-valid-json'), []);
check('عدد صحيح (نوع غير متوقع)', pge_normalize_catalog_features_meta(42), []);
check('array تحتوي عناصر غير نصية تُتجاهَل', pge_normalize_catalog_features_meta(['google_map', 123, null, ['x']]), ['google_map', '123']);

// ============================================================================
// 6) Invalid or missing Catalog data fails safely without Fatal Error
// ============================================================================
echo "\n6) Invalid/missing Catalog data — بلا Fatal Error\n";
reset_test_user(203);
set_test_user_meta(203, '_mon_package_source', 'catalog');
set_test_user_meta(203, '_mon_package_status', 'active');
// عمداً: بلا _mon_catalog_tier_id، بلا _mon_guest_limit، بلا _mon_catalog_features إطلاقاً.
$missing_data_limits = pge_get_user_plan_limits_for_events(203);
check('events_count = 0 دون أي Fatal Error', (int) $missing_data_limits['events_count'], 0);
check('guest_limit = 0 دون أي Fatal Error', (int) $missing_data_limits['guest_limit'], 0);
check_true('لا توجد أي ميزة مفعّلة', !pge_plan_feature_enabled_for_events($missing_data_limits, 'google_map'));

reset_test_user(204);
set_test_user_meta(204, '_mon_package_source', 'catalog');
set_test_user_meta(204, '_mon_package_status', 'active');
set_test_user_meta(204, '_mon_catalog_tier_id', 999999); // tier غير موجود إطلاقاً
$nonexistent_tier_limits = pge_get_user_plan_limits_for_events(204);
check('tier غير موجود → events_count = 0 دون Fatal Error', (int) $nonexistent_tier_limits['events_count'], 0);

reset_test_user(205);
set_test_user_meta(205, '_mon_package_source', 'catalog');
set_test_user_meta(205, '_mon_package_status', 'active');
set_test_user_meta(205, '_mon_catalog_plan_id', 999); // plan_id لا يطابق tier.plan_id
set_test_user_meta(205, '_mon_catalog_tier_id', 10);   // نفس tier الحالة 2 (plan_id=5)
$mismatched_tier_limits = pge_get_user_plan_limits_for_events(205);
check('تعارض plan_id/tier.plan_id → تُهمَل بيانات tier وتُعاد حدود صفرية آمنة', (int) $mismatched_tier_limits['events_count'], 0);

// ============================================================================
// 7) نقاط الإنفاذ/العرض بعد التوحيد (المرحلة 1.5)
//    هذه الحالات تُحاكي بدقة التعبيرات الفعلية المستخدمة الآن في:
//    event-factory.php::pge_handle_event_creation() و
//    page-create-event.php و dashboard-main.php و page-event-manage.php
//    (بعد إزالة شرط _mon_package_key/_mon_events_limit منها)، دون استدعاء
//    الملفات نفسها مباشرة لتفادي تبعيات WP الثقيلة (WP_Query, wp_send_json،
//    رفع الملفات...) في اختبار مركّز.
// ============================================================================
echo "\n7) نقاط الإنفاذ/العرض الموحّدة (event-factory / page-create-event / dashboard / page-event-manage)\n";

// 7.1) Catalog active يمكنه إنشاء مناسبة عندما الاستخدام أقل من الحد
reset_test_user(301);
set_test_user_meta(301, '_mon_package_source', 'catalog');
set_test_user_meta(301, '_mon_package_status', 'active');
set_test_user_meta(301, '_mon_catalog_plan_id', 5);
set_test_user_meta(301, '_mon_catalog_tier_id', 10); // نفس tier: events_count=3
$plan_limits_301 = pge_get_user_plan_limits_for_events(301);
$allowed_301 = (int) ($plan_limits_301['events_count'] ?? 0); // نفس تعبير pge_handle_event_creation()/page-create-event.php الآن
check('Catalog active: allowed_limit = 3 (من tier، بلا _mon_package_key إطلاقاً)', $allowed_301, 3);
check_true('current_count=1 < allowed_limit=3 → يُسمح بالإنشاء', !(1 >= $allowed_301));

// 7.2) Catalog active يُمنع عندما يصل إلى الحد
check_true('current_count=3 >= allowed_limit=3 → يُمنع الإنشاء', (3 >= $allowed_301));

// 7.3) Catalog active لا يحتاج _mon_package_key إطلاقاً (تأكيد صريح)
check('لا يوجد _mon_package_key لدى مستخدم Catalog هذا', get_user_meta(301, '_mon_package_key', true), '');
check('ورغم ذلك allowed_limit صحيح = 3', $allowed_301, 3);

// 7.4) Catalog expired يُمنع حتى لو كانت لديه Legacy meta قديمة
reset_test_user(302);
set_test_user_meta(302, '_mon_package_source', 'catalog');
set_test_user_meta(302, '_mon_package_status', 'expired');
set_test_user_meta(302, '_mon_catalog_plan_id', 5);
set_test_user_meta(302, '_mon_catalog_tier_id', 10);
set_test_user_meta(302, '_mon_package_key', 'plan_4');   // Legacy قديم متبقٍّ
set_test_user_meta(302, '_mon_events_limit', 999);       // Legacy قديم متبقٍّ
$plan_limits_302 = pge_get_user_plan_limits_for_events(302);
$allowed_302 = (int) ($plan_limits_302['events_count'] ?? 0);
check('Catalog expired: allowed_limit = 0 رغم _mon_events_limit=999 القديم', $allowed_302, 0);
check_true('current_count=0 >= allowed_limit=0 → يُمنع الإنشاء دائماً', (0 >= $allowed_302));

// 7.5) Legacy user ما زال يعمل تماماً كما قبل (إعادة تأكيد بمستخدم مستقل جديد)
$GLOBALS['__test_options']['mon_packages_settings']['plan_3'] = ['name' => 'الباقة الذهبية', 'events_count' => 10, 'guest_limit' => 200, 'host_photos' => 50];
reset_test_user(303);
set_test_user_meta(303, '_mon_package_key', 'plan_3');
$plan_limits_303 = pge_get_user_plan_limits_for_events(303);
$allowed_303 = (int) ($plan_limits_303['events_count'] ?? 0);
check('Legacy user: allowed_limit = 10 (من إعدادات plan_3) دون أي مساس', $allowed_303, 10);

// 7.6) Dashboard يعرض events_count الصحيح لمستخدم Catalog، ويحسب remaining بشكل صحيح
// (نفس تعبير dashboard-main.php: $events_left = max(0, $events_limit - $events_used))
$dashboard_events_limit_catalog = (int) ($plan_limits_301['events_count'] ?? 0);
check('Dashboard: events_limit = 3 لمستخدم Catalog', $dashboard_events_limit_catalog, 3);
check('Dashboard: remaining = 1 عند استخدام 2 من 3', max(0, $dashboard_events_limit_catalog - 2), 1);
check('Dashboard: remaining = 0 عند استخدام 3 من 3 (لا قيمة سالبة)', max(0, $dashboard_events_limit_catalog - 3), 0);
check('Dashboard: remaining = 0 حتى لو تجاوز الاستخدام الحد (لا قيمة سالبة)', max(0, $dashboard_events_limit_catalog - 5), 0);

// 7.7) أي Feature check معدّل (tabs.php/rsvp.php الآن تستخدم pge_plan_feature_enabled_for_events)
// يقرأ ميزات Catalog بنجاح — نفس النمط المستخدم الآن في template-parts/event/tabs.php
reset_test_user(304);
set_test_user_meta(304, '_mon_package_source', 'catalog');
set_test_user_meta(304, '_mon_package_status', 'active');
set_test_user_meta(304, '_mon_catalog_tier_id', 10);
set_test_user_meta(304, '_mon_catalog_features', ['guest_photos', 'guest_video', 'public_chat']);
$host_limits_304 = pge_get_user_plan_limits_for_events(304); // نفس ما يفعله tabs.php/rsvp.php عبر author_id
check_true('tabs.php: guest_photos مفعّلة لمضيف Catalog', pge_plan_feature_enabled_for_events($host_limits_304, 'guest_photos'));
check_true('tabs.php: guest_video مفعّلة لمضيف Catalog', pge_plan_feature_enabled_for_events($host_limits_304, 'guest_video'));
check_true('tabs.php: public_chat مفعّلة لمضيف Catalog', pge_plan_feature_enabled_for_events($host_limits_304, 'public_chat'));
check_true('tabs.php: private_chat غير مفعّلة (لم تُذكر)', !pge_plan_feature_enabled_for_events($host_limits_304, 'private_chat'));
// guest_limit لنفس المضيف (نفس ما تفعله rsvp-handler.php/rsvp.php الآن عبر author_id)
set_test_user_meta(304, '_mon_guest_limit', 150);
$host_limits_304_with_guest = pge_get_user_plan_limits_for_events(304);
check('rsvp.php/rsvp-handler.php: guest_limit = 150 لمضيف Catalog عبر author_id', (int) $host_limits_304_with_guest['guest_limit'], 150);

// ============================================================================
// 8) حماية Catalog من مسارات Legacy (المرحلة الثانية)
//    هذا القسم يستدعي الدوال الحقيقية فعلياً (بما فيها الدالة الخاصة
//    deactivate_user_package() عبر Reflection) بدل الاكتفاء بتتبّع منطقي —
//    اختبار حقيقي على نفس أسلوب class-salla-handler.php/class-mon-events-users.php.
// ============================================================================
echo "\n8) حماية Catalog من مسارات Legacy (deactivate_user_package / activate_user_package / admin-mods)\n";

function call_private_method($object, $method, array $args = [])
{
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($object, $args);
}

// كل المفاتيح المشمولة بالحماية (بند 8 من المهمة) — يجب أن تبقى مطابقة
// تماماً قبل وبعد أي محاولة إلغاء/إسناد Legacy على مستخدم Catalog.
function snapshot_catalog_keys($user_id)
{
    $keys = [
        '_mon_package_source', '_mon_package_status', '_mon_package_key', '_mon_package_name',
        '_mon_package_price', '_mon_package_currency', '_mon_guest_limit', '_mon_events_limit',
        '_mon_host_photos_limit', '_mon_wa_limit', '_mon_active_features',
        '_mon_catalog_plan_id', '_mon_catalog_tier_id', '_mon_catalog_plan_key',
        '_mon_catalog_plan_name', '_mon_catalog_tier_key', '_mon_catalog_tier_name',
        '_mon_catalog_features', '_mon_last_order_id',
    ];
    $snap = [];
    foreach ($keys as $k) {
        $snap[$k] = get_user_meta($user_id, $k, true);
    }
    return $snap;
}

$salla_handler = new Mon_Salla_Handler();

// إعداد مشترك: مستخدم Catalog نشط لديه أيضاً بقايا Legacy قديمة (سيناريو
// "كان لديه Legacy، ثم فعّل Catalog لاحقاً، وبقيت بعض Meta القديمة").
reset_test_user(401);
set_test_user_meta(401, '_mon_package_source', 'catalog');
set_test_user_meta(401, '_mon_package_status', 'active');
set_test_user_meta(401, '_mon_package_key', 'plan_2');           // بقايا Legacy قديمة
set_test_user_meta(401, '_mon_package_name', 'الباقة الفضية');    // بقايا Legacy قديمة
set_test_user_meta(401, '_mon_package_price', '99.00');
set_test_user_meta(401, '_mon_package_currency', 'SAR');
set_test_user_meta(401, '_mon_guest_limit', 120);
set_test_user_meta(401, '_mon_events_limit', '');
set_test_user_meta(401, '_mon_host_photos_limit', '');
set_test_user_meta(401, '_mon_wa_limit', '');
set_test_user_meta(401, '_mon_active_features', '');
set_test_user_meta(401, '_mon_catalog_plan_id', 5);
set_test_user_meta(401, '_mon_catalog_tier_id', 10);
set_test_user_meta(401, '_mon_catalog_plan_key', 'halwa');
set_test_user_meta(401, '_mon_catalog_plan_name', 'باقة حلوة');
set_test_user_meta(401, '_mon_catalog_tier_key', 'classic-100');
set_test_user_meta(401, '_mon_catalog_tier_name', 'كلاسيك 100');
set_test_user_meta(401, '_mon_catalog_features', ['google_map']);
set_test_user_meta(401, '_mon_last_order_id', 'CATALOG-ORDER-NEW');
set_test_user_email('catalog-active@example.com', 401);

// 8.1) deactivate_user_package() لا تعدّل Catalog active
// 8.3) إلغاء طلب Legacy قديم لا يؤثر على Catalog أحدث (بقايا plan_2 موجودة)
// 8.4) و8.7) جميع Meta تبقى مطابقة تماماً، والمنع يحدث قبل أي كتابة (لا فرق جزئي)
$before_401 = snapshot_catalog_keys(401);
call_private_method($salla_handler, 'deactivate_user_package', ['catalog-active@example.com', 'OLD-LEGACY-ORDER-1']);
$after_401 = snapshot_catalog_keys(401);
check('Catalog active: deactivate_user_package() لا تغيّر أي مفتاح مشترك/Catalog (بما فيها بقايا Legacy)', $after_401, $before_401);
check('Catalog active: _mon_package_status تبقى active (لا fallback ولا تغيير)', get_user_meta(401, '_mon_package_status', true), 'active');

// 8.2) و8.9) نفس الحماية بالضبط لمستخدم Catalog منتهي (expired) — الحماية
// تعتمد على المصدر لا الحالة.
reset_test_user(402);
set_test_user_meta(402, '_mon_package_source', 'catalog');
set_test_user_meta(402, '_mon_package_status', 'expired');
set_test_user_meta(402, '_mon_guest_limit', 200);
set_test_user_meta(402, '_mon_catalog_plan_id', 5);
set_test_user_meta(402, '_mon_catalog_tier_id', 10);
set_test_user_meta(402, '_mon_catalog_features', ['stc_pay']);
set_test_user_email('catalog-expired@example.com', 402);

$before_402 = snapshot_catalog_keys(402);
call_private_method($salla_handler, 'deactivate_user_package', ['catalog-expired@example.com', 'OLD-LEGACY-ORDER-2']);
$after_402 = snapshot_catalog_keys(402);
check('Catalog expired: deactivate_user_package() لا تغيّر أي مفتاح (نفس حماية active)', $after_402, $before_402);
check('Catalog expired: _mon_package_status تبقى expired (لا يُعاد كتابتها ولا يتغيّر شيء)', get_user_meta(402, '_mon_package_status', true), 'expired');

// 8.5) مستخدم Legacy يُلغى كما كان تماماً (بلا _mon_package_source إطلاقاً)
reset_test_user(403);
set_test_user_meta(403, '_mon_package_status', 'active');
set_test_user_meta(403, '_mon_package_key', 'plan_3');
set_test_user_meta(403, '_mon_guest_limit', 200);
set_test_user_meta(403, '_mon_host_photos_limit', 50);
set_test_user_meta(403, '_mon_events_limit', 10);
set_test_user_meta(403, '_mon_active_features', ['google_map']);
set_test_user_email('legacy-user@example.com', 403);

call_private_method($salla_handler, 'deactivate_user_package', ['legacy-user@example.com', 'LEGACY-ORDER-3']);
check('Legacy user: status تصبح expired كالسابق تماماً', get_user_meta(403, '_mon_package_status', true), 'expired');
check('Legacy user: guest_limit يُصفَّر كالسابق', get_user_meta(403, '_mon_guest_limit', true), 0);
check('Legacy user: host_photos_limit يُصفَّر كالسابق', get_user_meta(403, '_mon_host_photos_limit', true), 0);
check('Legacy user: events_limit يُصفَّر كالسابق', get_user_meta(403, '_mon_events_limit', true), 0);
check('Legacy user: active_features تُمسح كالسابق', get_user_meta(403, '_mon_active_features', true), []);

// 8.6) و8.8) دالة الفحص الصغيرة المستخدمة داخل الإسناد اليدوي في admin-mods.php
// (استبدال اختبار admin POST المعقّد بدالة الفحص المستقلة كما يسمح به بند 11.6 من المهمة)
reset_test_user(404);
set_test_user_meta(404, '_mon_package_source', 'catalog');
set_test_user_meta(404, '_mon_package_status', 'active');
check_true('pge_is_legacy_write_allowed_for_user(): false لمستخدم Catalog active → الإسناد اليدوي يُمنع', !pge_is_legacy_write_allowed_for_user(404));

set_test_user_meta(404, '_mon_package_status', 'expired');
check_true('pge_is_legacy_write_allowed_for_user(): false أيضاً لمستخدم Catalog expired → نفس المنع', !pge_is_legacy_write_allowed_for_user(404));

reset_test_user(409);
set_test_user_meta(409, '_mon_package_status', 'active');
set_test_user_meta(409, '_mon_package_key', 'plan_1');
check_true('pge_is_legacy_write_allowed_for_user(): true لمستخدم Legacy → الإسناد اليدوي يعمل كالسابق', pge_is_legacy_write_allowed_for_user(409));
check_true('pge_is_legacy_write_allowed_for_user(): true لمعرّف مستخدم غير صالح (0)', pge_is_legacy_write_allowed_for_user(0));

// 8.10) تسجيل Log المنع فعلياً (بدون بناء إطار كبير — إعادة توجيه error_log لملف مؤقت)
$log_file = tempnam(sys_get_temp_dir(), 'pge_test_log_');
ini_set('log_errors', '1');
ini_set('error_log', $log_file);

reset_test_user(405);
set_test_user_meta(405, '_mon_package_source', 'catalog');
set_test_user_meta(405, '_mon_package_status', 'active');
set_test_user_email('catalog-log-test@example.com', 405);
call_private_method($salla_handler, 'deactivate_user_package', ['catalog-log-test@example.com', 'ORDER-LOG-1']);

$log_contents = @file_get_contents($log_file) ?: '';
check_true('Log: يحتوي legacy_deactivation_blocked_for_catalog', strpos($log_contents, 'legacy_deactivation_blocked_for_catalog') !== false);
check_true('Log: يحتوي user_id الصحيح (405)', strpos($log_contents, '"user_id":"405"') !== false || strpos($log_contents, '"user_id":405') !== false);
@unlink($log_file);

// إضافي: نفس الحماية داخل activate_user_package() (مسار تفعيل Legacy، وليس
// فقط إلغاءه — أُضيف لأنه مسار Legacy فعلي آخر يكتب نفس المفاتيح المشتركة
// ولا يمر عبر أي دالة محمية مسبقاً، حسب بند 9 من المهمة)
$GLOBALS['__test_options']['mon_packages_settings']['plan_1'] = [
    'name' => 'الباقة الأساسية', 'events_count' => 1, 'guest_limit' => 15, 'host_photos' => 10, 'wa_messages' => 0,
];

reset_test_user(406);
set_test_user_meta(406, '_mon_package_source', 'catalog');
set_test_user_meta(406, '_mon_package_status', 'active');
set_test_user_meta(406, '_mon_guest_limit', 80);
set_test_user_email('catalog-activate-test@example.com', 406);

$before_406 = snapshot_catalog_keys(406);
$activate_blocked_result = Mon_Events_Users::activate_user_package('catalog-activate-test@example.com', ['plan_key' => 'plan_1', 'order_id' => 'ORD-2']);
$after_406 = snapshot_catalog_keys(406);
check('activate_user_package(): Catalog لا يتغيّر عند محاولة تفعيل Legacy فوقه', $after_406, $before_406);
check_true('activate_user_package(): تعيد status=blocked ضمن نفس عقد WP_REST_Response', is_array($activate_blocked_result->data) && ($activate_blocked_result->data['status'] ?? '') === 'blocked');

// تأكيد أن تفعيل Legacy العادي (بلا Catalog) ما زال يعمل كما كان تماماً
reset_test_user(407);
set_test_user_email('legacy-activate-test@example.com', 407);
$activate_legacy_result = Mon_Events_Users::activate_user_package('legacy-activate-test@example.com', ['plan_key' => 'plan_1', 'order_id' => 'ORD-3']);
check('activate_user_package(): تفعيل Legacy عادي يعيد status=success كالسابق', $activate_legacy_result->data['status'] ?? '', 'success');
check('activate_user_package(): تفعيل Legacy عادي يكتب _mon_package_status=active كالسابق', get_user_meta(407, '_mon_package_status', true), 'active');

// ============================================================================
// 9) أعمدة /wp-admin/users.php (المرحلة الثالثة) — pge_resolve_admin_user_package_name()
//    وpge_resolve_admin_user_package_source() مباشرة (بدل تحميل wp-admin كاملة).
// ============================================================================
echo "\n9) عمود الباقة ومصدر الاشتراك في users.php\n";

// 9.1) Catalog active يعرض اسم الخطة والمستوى بصيغة "الخطة — المستوى"
reset_test_user(501);
set_test_user_meta(501, '_mon_package_source', 'catalog');
set_test_user_meta(501, '_mon_package_status', 'active');
set_test_user_meta(501, '_mon_catalog_plan_name', 'حلوة');
set_test_user_meta(501, '_mon_catalog_tier_name', 'كلاسيك 100');
check('9.1 Catalog active: الاسم = "حلوة — كلاسيك 100"', pge_resolve_admin_user_package_name(501), 'حلوة — كلاسيك 100');
check('9.1 Catalog active: المصدر = Catalog', pge_resolve_admin_user_package_source(501), 'Catalog');

// 9.2) Catalog expired يعرض نفس اسم Catalog (لا يوجد عمود/نص حالة معروض في
// هذه المرحلة أصلاً — راجع التقرير: لم يُضف عمود حالة لعدم وجود حاجة واضحة
// له في بنية الأعمدة الحالية، فالتوقع هنا هو تطابق الاسم فقط بصرف النظر عن الحالة)
reset_test_user(502);
set_test_user_meta(502, '_mon_package_source', 'catalog');
set_test_user_meta(502, '_mon_package_status', 'expired');
set_test_user_meta(502, '_mon_catalog_plan_name', 'حلوة');
set_test_user_meta(502, '_mon_catalog_tier_name', 'كلاسيك 100');
check('9.2 Catalog expired: نفس اسم Catalog active بالضبط', pge_resolve_admin_user_package_name(502), pge_resolve_admin_user_package_name(501));
check('9.2 Catalog expired: المصدر يبقى Catalog (الحماية بالمصدر لا بالحالة)', pge_resolve_admin_user_package_source(502), 'Catalog');

// 9.3) Catalog مع Legacy meta قديمة لا يعرض Legacy إطلاقاً
reset_test_user(503);
set_test_user_meta(503, '_mon_package_source', 'catalog');
set_test_user_meta(503, '_mon_package_status', 'active');
set_test_user_meta(503, '_mon_catalog_plan_name', 'حلوة');
set_test_user_meta(503, '_mon_catalog_tier_name', 'كلاسيك 100');
set_test_user_meta(503, '_mon_package_name', 'باقة Legacy قديمة');
set_test_user_meta(503, '_mon_package_key', 'plan_4');
check('9.3 Catalog مع Legacy قديم: الاسم Catalog فقط، بلا أي أثر لـ"باقة Legacy قديمة"', pge_resolve_admin_user_package_name(503), 'حلوة — كلاسيك 100');
check('9.3 Catalog مع Legacy قديم: المصدر Catalog فقط', pge_resolve_admin_user_package_source(503), 'Catalog');

// 9.4) Catalog ناقص plan_name يستخدم plan_key
reset_test_user(504);
set_test_user_meta(504, '_mon_package_source', 'catalog');
set_test_user_meta(504, '_mon_catalog_plan_key', 'halwa');
set_test_user_meta(504, '_mon_catalog_tier_name', 'كلاسيك 100');
check('9.4 plan_name فارغ → استخدام plan_key', pge_resolve_admin_user_package_name(504), 'halwa — كلاسيك 100');

// 9.5) Catalog ناقص tier_name يستخدم tier_key
reset_test_user(505);
set_test_user_meta(505, '_mon_package_source', 'catalog');
set_test_user_meta(505, '_mon_catalog_plan_name', 'حلوة');
set_test_user_meta(505, '_mon_catalog_tier_key', 'classic-100');
check('9.5 tier_name فارغ → استخدام tier_key', pge_resolve_admin_user_package_name(505), 'حلوة — classic-100');

// 9.5b) جزء واحد فقط متوفر → يُعرض وحده (بند 5 من قواعد fallback)
reset_test_user(506);
set_test_user_meta(506, '_mon_package_source', 'catalog');
set_test_user_meta(506, '_mon_catalog_plan_name', 'حلوة فقط');
check('9.5b جزء الخطة فقط متوفر → يُعرض وحده بلا "—" زائدة', pge_resolve_admin_user_package_name(506), 'حلوة فقط');

// 9.6) Catalog بلا أي أسماء أو keys يعرض "بيانات Catalog غير مكتملة"
reset_test_user(507);
set_test_user_meta(507, '_mon_package_source', 'catalog');
check('9.6 Catalog بلا أي بيانات → "بيانات Catalog غير مكتملة"', pge_resolve_admin_user_package_name(507), 'بيانات Catalog غير مكتملة');

// 9.7) Legacy بلا _mon_package_source يعرض _mon_package_name كما كان
reset_test_user(508);
set_test_user_meta(508, '_mon_package_name', 'الباقة الذهبية');
check('9.7 Legacy: يعرض _mon_package_name كما كان', pge_resolve_admin_user_package_name(508), 'الباقة الذهبية');

// 9.8) Legacy يظهر المصدر Legacy (عبر مؤشر _mon_package_key فقط، بلا name)
reset_test_user(509);
set_test_user_meta(509, '_mon_package_key', 'plan_2');
check('9.8 Legacy: المصدر = Legacy', pge_resolve_admin_user_package_source(509), 'Legacy');
check('9.8 Legacy بلا اسم → "—"', pge_resolve_admin_user_package_name(509), '—');

// 9.9) _created_via_salla وحده لا يجعل المصدر Legacy ولا Catalog
reset_test_user(510);
set_test_user_meta(510, '_created_via_salla', 'yes');
check('9.9 _created_via_salla وحدها → "بدون اشتراك" (ليست مؤشر اشتراك)', pge_resolve_admin_user_package_source(510), 'بدون اشتراك');
check('9.9 نفس الحالة: الاسم "—"', pge_resolve_admin_user_package_name(510), '—');

// 9.10) مستخدم بلا أي Catalog أو Legacy meta إطلاقاً
reset_test_user(511);
check('9.10 بلا اشتراك إطلاقاً: الاسم "—"', pge_resolve_admin_user_package_name(511), '—');
check('9.10 بلا اشتراك إطلاقاً: المصدر "بدون اشتراك"', pge_resolve_admin_user_package_source(511), 'بدون اشتراك');

// 9.11) نصوص ضارة/HTML داخل meta تُهرَّب عند الإخراج (esc_html يحدث في
// callback العمود الفعلي في class-salla-handler.php؛ هنا نتحقق من: (أ) أن
// resolver لا يُغيّر/يُهرِّب البيانات الخام بنفسه (مسؤولية العرض منفصلة)،
// و(ب) أن esc_html() على مخرجاته فعلاً يُحيِّد أي HTML/سكربت خطير — وهو
// بالضبط ما يستدعيه callback العمود الحقيقي).
reset_test_user(512);
$malicious = '<script>alert(1)</script>';
set_test_user_meta(512, '_mon_package_name', $malicious);
$raw_name_512 = pge_resolve_admin_user_package_name(512);
check('9.11 resolver يعيد القيمة الخام دون تعديل (الهروب مسؤولية العرض)', $raw_name_512, $malicious);
check_true('9.11 esc_html() على المخرجات يُحيِّد <script> فعلياً', strpos(esc_html($raw_name_512), '<script>') === false);
check_true('9.11 esc_html() يحوّل < إلى &lt;', strpos(esc_html($raw_name_512), '&lt;script&gt;') !== false);

// 9.12) لا Fatal ولا Notice/Warning عند قيم فارغة أو أنواع غير متوقعة
reset_test_user(513);
set_test_user_meta(513, '_mon_package_source', 'catalog');
set_test_user_meta(513, '_mon_catalog_plan_name', ['unexpected' => 'array']); // نوع غير متوقع تماماً
set_test_user_meta(513, '_mon_catalog_tier_name', null);
$name_513 = pge_resolve_admin_user_package_name(513); // يجب ألا يُصدر أي PHP Warning/Notice
check('9.12 قيمة array غير متوقعة لـplan_name تُعامَل كفارغة بلا Warning', $name_513, 'بيانات Catalog غير مكتملة');

check('9.12 user_id = 0 لا يُسبب Fatal، ويُعامَل كبلا اشتراك', pge_resolve_admin_user_package_name(0), '—');
check('9.12 user_id = 0 للمصدر أيضاً بلا Fatal', pge_resolve_admin_user_package_source(0), 'بدون اشتراك');

reset_test_user(514);
set_test_user_meta(514, '_mon_package_source', 123); // نوع غير نصي تماماً بدل 'catalog'
check('9.12 _mon_package_source برقم بدل نص → يُعامَل كغير Catalog (Legacy/بدون اشتراك) بلا Fatal', pge_resolve_admin_user_package_source(514), 'بدون اشتراك');

// ── الخلاصة ─────────────────────────────────────────────────────────────
echo "\n----------------------------------------\n";
echo "Total checks: {$GLOBALS['__total']}, Failures: {$GLOBALS['__failures']}\n";
exit($GLOBALS['__failures'] > 0 ? 1 : 0);
