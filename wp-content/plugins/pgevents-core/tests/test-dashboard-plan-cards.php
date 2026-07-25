<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس نمط بقية ملفات tests/ في هذا
 * المشروع) لبطاقات "حالة الباقة والحصص" الأربع في templates/dashboard-main.php
 * (الباقة الحالية، المناسبات المتبقية، المدعوين لكل مناسبة، رسائل واتساب
 * المتاحة) — إصلاح 2026-07-25.
 *
 * لماذا لا يُحمَّل templates/dashboard-main.php مباشرة؟
 * الملف قالب HTML كامل يُنفَّذ فور تحميله (auth_redirect, get_header,
 * wp_get_current_user, WP_Query متعددة، $_GET، إلخ) — تحميله في بيئة معزولة
 * يتطلب عشرات الـStubs غير المتعلقة إطلاقاً بمنطق البطاقات الأربع محل
 * الإصلاح، ويُدخل هشاشة كبيرة (أي تغيير مستقبلي في أي جزء آخر من القالب
 * يكسر الاختبار رغم عدم صلته). البديل المعتمد هنا (بنفس روح بقية اختبارات
 * هذا المشروع التي تفصل منطق القرار عن HTML): استدعاء المُحلِّل المركزي
 * الحقيقي pge_get_user_plan_limits_for_events() من includes/event-factory.php
 * بلا أي تعديل عليه، ثم اختبار دالة صغيرة هنا (pge_test_resolve_dashboard_cards)
 * تُطابق حرفياً منطق اختيار القيم المعروضة المُضاف في dashboard-main.php
 * (الأسطر 118-187 و291-312 تقريباً وقت كتابة هذا الاختبار) — وهي لا تُعيد
 * حساب أي حد أو رصيد بنفسها (ذلك عمل pge_get_user_plan_limits_for_events()
 * وحدها)، بل تُطبّق فقط قاعدة "أي حقل يُعرض تحت أي عنوان" بالضبط كما في
 * القالب. عند أي تعديل مستقبلي على هذا الجزء من القالب، حدِّث هذه الدالة
 * بالتوازي.
 *
 * ملاحظة مهمة على السيناريو #2 (المناسبات المتبقية): طلب المهمة الأصلي كان
 * عرض "غير محدود" دائماً، لكن التتبع أظهر أن events_count مُنفَّذ فعلياً في
 * includes/event-factory.php (pge_handle_event_creation, generate limit
 * check) — أي أن "1 من 1" ليس عطلاً في العرض بل انعكاساً صحيحاً لحد مُنفَّذ
 * فعلياً. المستخدم اختار صراحة (بعد عرض هذا التعارض عليه): "اعرض الرقم
 * الحقيقي (X من Y)" — فبقيت هذه البطاقة كما كانت دون أي تعديل في القالب أو
 * في هذا الاختبار، ولا يُختبر هنا أي "غير محدود".
 *
 * التشغيل:
 *   php tests/test-dashboard-plan-cards.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب فقط، بنفس نمط بقية الاختبارات) ──

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_options']   = [];

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }

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

function absint($value) { return abs((int) $value); }

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
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
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}

// ── كلاس Catalog وهمي — بديل كامل عن class-pge-catalog.php لهذا الاختبار ──
class PGE_Catalog
{
    public static $tiers = [];

    public static function get_tier($tier_id)
    {
        return self::$tiers[$tier_id] ?? null;
    }
}

// ── تحميل الملف الحقيقي من المشروع (بلا أي تعديل عليه) ─────────────────────
require_once __DIR__ . '/../includes/event-factory.php';

// ── مطابقة منطق اختيار القيم المعروضة في dashboard-main.php (انظر التعليق
//    أعلى الملف) — تُستدعى بعد pge_get_user_plan_limits_for_events() فقط،
//    ولا تُعيد حساب أي حد بنفسها.
function pge_test_resolve_dashboard_cards($user_id, array $plan_limits)
{
    $plan_key    = (string) get_user_meta($user_id, '_mon_package_key', true);
    $plan_name   = (string) get_user_meta($user_id, '_mon_package_name', true);
    $plan_status = (string) get_user_meta($user_id, '_mon_package_status', true);

    $package_source    = (string) get_user_meta($user_id, '_mon_package_source', true);
    $display_plan_name = '';

    if ($package_source === 'catalog') {
        $catalog_tier_id = absint(get_user_meta($user_id, '_mon_catalog_tier_id', true));
        $catalog_tier    = ($catalog_tier_id > 0 && class_exists('PGE_Catalog')) ? PGE_Catalog::get_tier($catalog_tier_id) : null;

        if (is_array($catalog_tier) && !empty($catalog_tier['name'])) {
            $display_plan_name = (string) $catalog_tier['name'];
        } else {
            $snapshot_tier_name = (string) get_user_meta($user_id, '_mon_catalog_tier_name', true);
            $snapshot_plan_name = (string) get_user_meta($user_id, '_mon_catalog_plan_name', true);
            $display_plan_name  = $snapshot_tier_name !== '' ? $snapshot_tier_name : $snapshot_plan_name;
        }
    }

    if ($display_plan_name === '') {
        $display_plan_name = $plan_name ?: $plan_key;
    }

    $guest_limit_per_event = isset($plan_limits['guest_limit']) ? (int) $plan_limits['guest_limit'] : 0;

    $invitation_credit_total     = isset($plan_limits['invitation_credit_total']) ? (int) $plan_limits['invitation_credit_total'] : 0;
    $invitation_credit_used      = isset($plan_limits['invitation_credit_used']) ? (int) $plan_limits['invitation_credit_used'] : 0;
    $invitation_credit_remaining = isset($plan_limits['invitation_credit_remaining'])
        ? (int) $plan_limits['invitation_credit_remaining']
        : max(0, $invitation_credit_total - $invitation_credit_used);

    if ($guest_limit_per_event > 0) {
        $guest_card_title = 'المدعوين لكل مناسبة';
        $guest_card_value = $guest_limit_per_event;
    } elseif ($invitation_credit_total > 0) {
        $guest_card_title = 'إجمالي رصيد الدعوات';
        $guest_card_value = $invitation_credit_total;
    } else {
        $guest_card_title = 'المدعوين لكل مناسبة';
        $guest_card_value = 0;
    }

    return [
        'display_plan_name'           => $display_plan_name,
        'plan_status'                 => $plan_status,
        'guest_card_title'            => $guest_card_title,
        'guest_card_value'            => $guest_card_value,
        'invitation_credit_total'     => $invitation_credit_total,
        'invitation_credit_used'      => $invitation_credit_used,
        'invitation_credit_remaining' => $invitation_credit_remaining,
    ];
}

// ── أداة تأكيد صغيرة (بنفس نمط بقية الاختبارات) ─────────────────────────────

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
// 1) مستخدم Catalog باقة نشطة — يظهر اسم Tier الصحيح
// ============================================================================
echo "1) مستخدم Catalog باقة نشطة — اسم Tier الصحيح\n";
PGE_Catalog::$tiers[10] = [
    'id' => 10, 'plan_id' => 5, 'tier_key' => 'classic-100', 'name' => 'حلوة كلاسيك',
    'events_count' => 1, 'host_photos_limit' => 15, 'wa_messages_limit' => null,
];
reset_test_user(301);
set_test_user_meta(301, '_mon_package_source', 'catalog');
set_test_user_meta(301, '_mon_package_status', 'active');
set_test_user_meta(301, '_mon_catalog_plan_id', 5);
set_test_user_meta(301, '_mon_catalog_tier_id', 10);
set_test_user_meta(301, '_mon_invitation_credit_total', 100);
set_test_user_meta(301, '_mon_invitation_credit_used', 0);
set_test_user_meta(301, '_mon_replacement_credit_total', 10);
set_test_user_meta(301, '_mon_replacement_credit_used', 0);

$limits_301 = pge_get_user_plan_limits_for_events(301);
$cards_301  = pge_test_resolve_dashboard_cards(301, $limits_301);

check('اسم الباقة المعروض = "حلوة كلاسيك" (من Tier الحي)', $cards_301['display_plan_name'], 'حلوة كلاسيك');
check('حالة الباقة = active', $cards_301['plan_status'], 'active');
check_true('لا تظهر "لا توجد باقة نشطة" (الاسم غير فارغ)', $cards_301['display_plan_name'] !== '');

// ============================================================================
// 2) المناسبات — القرار المعتمد من المستخدم: عرض الرقم الحقيقي X من Y (لا
//    "غير محدود")، لأن events_count مُنفَّذ فعلياً في event-factory.php.
//    نتحقق فقط أن events_count القادم من المُحلِّل المركزي يطابق قيمة الـTier
//    الحقيقية (1 هنا)، بلا أي منطق "unlimited" مضاف.
// ============================================================================
echo "\n2) المناسبات المتبقية — الرقم الحقيقي من events_count (بقرار من المستخدم)\n";
check('events_count = 1 (القيمة الافتراضية الفعلية لهذا الـTier، تُعرض كما هي)', (int) $limits_301['events_count'], 1);

// ============================================================================
// 3) رصيد الدعوات: total = 100, used = 0, remaining = 100
// ============================================================================
echo "\n3) رصيد الدعوات — total=100, used=0, remaining=100\n";
check('invitation_credit_total = 100', $cards_301['invitation_credit_total'], 100);
check('invitation_credit_used = 0', $cards_301['invitation_credit_used'], 0);
check('invitation_credit_remaining = 100', $cards_301['invitation_credit_remaining'], 100);
check('بطاقة "المدعوين لكل مناسبة": guest_limit=0 لهذا الـTier فتتحول تلقائياً لعنوان "إجمالي رصيد الدعوات"', $cards_301['guest_card_title'], 'إجمالي رصيد الدعوات');
check('قيمة بطاقة الرصيد = 100', $cards_301['guest_card_value'], 100);

// ============================================================================
// 4) بعد استهلاك 12 دعوة: remaining = 88
// ============================================================================
echo "\n4) بعد استهلاك 12 دعوة — remaining=88\n";
set_test_user_meta(301, '_mon_invitation_credit_used', 12);
$limits_301_after  = pge_get_user_plan_limits_for_events(301);
$cards_301_after   = pge_test_resolve_dashboard_cards(301, $limits_301_after);
check('invitation_credit_used = 12', $cards_301_after['invitation_credit_used'], 12);
check('invitation_credit_remaining = 88', $cards_301_after['invitation_credit_remaining'], 88);

// ============================================================================
// 5) مستخدم دون اشتراك: تظهر «لا توجد باقة نشطة»
// ============================================================================
echo "\n5) مستخدم دون اشتراك — لا توجد باقة نشطة\n";
reset_test_user(302);
$limits_302 = pge_get_user_plan_limits_for_events(302);
$cards_302  = pge_test_resolve_dashboard_cards(302, $limits_302);
check('اسم الباقة المعروض فارغ (القالب يعرض "لا توجد باقة نشطة" في هذه الحالة)', $cards_302['display_plan_name'], '');
check('invitation_credit_total = 0', $cards_302['invitation_credit_total'], 0);
check('guest_card_value = 0 (تظهر "غير محدد")', $cards_302['guest_card_value'], 0);

// ============================================================================
// 6) مستخدم Legacy — العرض يستمر دون Regression (يستخدم guest_limit الحقيقي،
//    لا رصيد دعوات إطلاقاً — Legacy لا يملك هذا المفهوم أصلاً)
// ============================================================================
echo "\n6) مستخدم Legacy — استمرار العرض دون Regression\n";
reset_test_user(303);
set_test_user_meta(303, '_mon_package_key', 'plan_2');
set_test_user_meta(303, '_mon_package_name', 'الباقة الفضية');
set_test_user_meta(303, '_mon_package_status', 'active');
set_test_user_meta(303, '_mon_guest_limit', 50);
// عمداً بلا _mon_package_source — تماماً كمستخدم Legacy قديم حقيقي؛ ولا
// PGE_Packages محمَّلة هنا (غير مطلوبة لهذا المسار لأن pge_get_user_plan_limits_for_events
// يرجع للقراءة المباشرة من user meta عند غياب class_exists('PGE_Packages')).

$limits_303 = pge_get_user_plan_limits_for_events(303);
$cards_303  = pge_test_resolve_dashboard_cards(303, $limits_303);
check('اسم الباقة المعروض = "الباقة الفضية" (مسار Legacy القديم بلا أي تغيير)', $cards_303['display_plan_name'], 'الباقة الفضية');
check('بطاقة "المدعوين لكل مناسبة" تبقى بعنوانها الأصلي لمستخدم Legacy (guest_limit حقيقي > 0)', $cards_303['guest_card_title'], 'المدعوين لكل مناسبة');
check('قيمتها = 50 (من _mon_guest_limit مباشرة، Legacy)', $cards_303['guest_card_value'], 50);
check('invitation_credit_total = 0 (Legacy لا يملك مفهوم رصيد الدعوات)', $cards_303['invitation_credit_total'], 0);

// ============================================================================
// 7) مستخدم Catalog نشط بـSnapshot مكتمل: لا تظهر "لا توجد باقة نشطة" ولا
//    "غير محدد" لبطاقة الرصيد ولا "غير مفعّلة" لواتساب.
//    (بند "لا يظهر 1 من 1" من الطلب الأصلي غير مُختبَر هنا عمداً — أصبح
//    "1 من 1" عرضاً صحيحاً وليس عطلاً، بقرار صريح من المستخدم في هذه المهمة
//    بعد الكشف عن أن events_count مُنفَّذ فعلياً؛ راجع الملاحظة أعلى الملف.)
// ============================================================================
echo "\n7) مستخدم Catalog نشط بـSnapshot مكتمل — لا قيم افتراضية خاطئة\n";
check_true('لا تظهر "لا توجد باقة نشطة" (اسم الباقة غير فارغ)', $cards_301['display_plan_name'] !== '');
check_true('لا تظهر "غير محدد" لبطاقة الرصيد (guest_card_value > 0)', $cards_301['guest_card_value'] > 0);
check_true('لا تظهر "غير مفعّلة" لرسائل واتساب (invitation_credit_total > 0)', $cards_301['invitation_credit_total'] > 0);

// ── الخلاصة ─────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 70) . "\n";
echo "الإجمالي: {$GLOBALS['__total']} | الناجحة: " . ($GLOBALS['__total'] - $GLOBALS['__failures']) . " | الفاشلة: {$GLOBALS['__failures']}\n";

exit($GLOBALS['__failures'] > 0 ? 1 : 0);
