<?php
/**
 * اختبار مستقل بذاته (بلا PHPUnit، نفس نمط tests/test-feature-resolver.php)
 * لـ Phase 6 — Commit 1.1b: اختبارات الارتداد (Regression) لإصلاح التوافق مع
 * Legacy المُطبَّق في Commit 1.1a داخل includes/feature-resolver.php (فرع
 * Legacy لـpge_feature_resolver_resolve_raw_value()).
 *
 * الفرق الجوهري عن tests/test-feature-resolver.php: هذا الملف **لا يعرّف
 * Test Double لـPGE_Packages إطلاقاً** — يُحمَّل الكلاس الحقيقي
 * includes/class-pge-packages.php كما هو، بلا أي تعديل، لأن سبب وجود هذا
 * الملف تحديداً هو إثبات أن الإصلاح يعمل بالتفاعل الفعلي بين:
 *   - includes/feature-resolver.php (تحت الاختبار، فرع Legacy + الـAlias)
 *   - includes/class-pge-feature-registry.php (الحقيقي)
 *   - includes/class-pge-packages.php (الحقيقي — 14 مفتاح Legacy الفعلية،
 *     بما فيها 'google_map' المفرد الذي كان سبب الانحدار الأصلي)
 *
 * اختبارات tests/test-feature-resolver.php لم تكن لتكتشف انحدار Commit 1.1
 * لأنها تستخدم Test Double لـPGE_Packages (بمفاتيحه الخاصة به فقط) — هذا
 * الملف يسد تلك الفجوة تحديداً عبر تشغيل الكلاس الحقيقي.
 *
 * لا نسخ لمنطق الـResolver هنا بأي شكل — كل تحقق يمر حصراً عبر استدعاء
 * pge_user_has_feature($user_id, $feature_key) الحقيقية من feature-resolver.php
 * بعد require_once مباشر للملفات الحقيقية الأربعة أعلاه.
 *
 * البنية التحتية المُعاد استخدامها من tests/test-feature-resolver.php (محاكاة
 * ووردبريس فقط — ليست منطق إنتاج): get_user_meta()/set_test_user_meta()،
 * Fake_Wpdb_Resolver (لتحميل class-pge-tier-features.php الحقيقي)، PGE_Catalog
 * Test Double الخفيف (نفس فلسفة الملف الأصلي — عزل Catalog Tier lookup فقط،
 * لا علاقة له بمنطق الـAlias تحت الاختبار). الإضافة الوحيدة الجديدة هنا:
 * get_option()/set_test_option()، مطلوبة لأن class-pge-packages.php الحقيقي
 * (خلافاً للـTest Double السابق) يستدعي get_option('mon_packages_settings', ...)
 * فعلياً داخل get_package_settings().
 *
 * التشغيل:
 *   php tests/test-feature-resolver-legacy-alias.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (نفس تعريفات tests/test-feature-resolver.php حرفياً) ──

define('ABSPATH', __DIR__ . '/');

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) { return true; }
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }
}

$GLOBALS['__test_user_meta'] = [];

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}

function set_test_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
}

function absint($value)
{
    return abs((int) $value);
}

// ── Stub جديد: get_option()/set_test_option() ───────────────────────────────
// غير موجود في tests/test-feature-resolver.php لأن ذلك الملف لم يكن يحمّل
// class-pge-packages.php الحقيقي إطلاقاً. مطلوب هنا فقط لأن
// PGE_Packages::get_package_settings() الحقيقية تستدعي get_option() فعلياً.
// السلوك الافتراضي (بلا أي set_test_option) يُعيد $default تماماً كما هو —
// أي get_package_settings() ستقع تلقائياً على self::get_default_plans()
// الحقيقية، تماماً كما تفعل في الإنتاج عند عدم وجود الخيار في قاعدة البيانات.

$GLOBALS['__test_options'] = [];

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$name] : $default;
}

function set_test_option($name, $value)
{
    $GLOBALS['__test_options'][$name] = $value;
}

// ── Fake $wpdb — لتحميل includes/class-pge-tier-features.php الحقيقي ────────
// (نسخة مطابقة حرفياً لـFake_Wpdb_Resolver في tests/test-feature-resolver.php)

class Fake_Wpdb_Resolver
{
    public $prefix = 'wp_';
    public $last_error = '';

    private $rows = [];
    private $next_id = 1;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? '';
            $i++;
            if ($m[0] === '%d') return (string) (int) $val;
            return "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    public function get_row($sql, $output = null)
    {
        $this->last_error = '';

        if (preg_match('/tier_id\s*=\s*(-?\d+)/', $sql, $m1) && preg_match("/feature_key\s*=\s*'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m2)) {
            $tier_id = (int) $m1[1];
            $feature_key = stripslashes($m2[1]);
            foreach ($this->rows as $row) {
                if ((int) $row['tier_id'] === $tier_id && $row['feature_key'] === $feature_key) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function get_results($sql, $output = null)
    {
        $this->last_error = '';

        if (preg_match('/tier_id\s*=\s*(-?\d+)/', $sql, $m)) {
            $tier_id = (int) $m[1];
            $out = [];
            foreach ($this->rows as $row) {
                if ((int) $row['tier_id'] === $tier_id) {
                    $out[] = $row;
                }
            }
            return $out;
        }

        return [];
    }

    public function seed_row($tier_id, $feature_key, $feature_value)
    {
        $id = $this->next_id++;
        $this->rows[$id] = [
            'id'            => $id,
            'tier_id'       => (int) $tier_id,
            'feature_key'   => (string) $feature_key,
            'feature_value' => (string) $feature_value,
            'created_at'    => '2026-01-01 00:00:00',
            'updated_at'    => '2026-01-01 00:00:00',
        ];
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Resolver();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── PGE_Catalog — Test Double خفيف (نفس فلسفة tests/test-feature-resolver.php) ──
// عزل استعلام Catalog Tier فقط — لا علاقة له بالـAlias تحت الاختبار (الـAlias
// حصراً داخل فرع Legacy، لا يمسّ مسار Catalog/Tier إطلاقاً).

class PGE_Catalog
{
    public static $tiers = [];

    public static function get_tier($tier_id)
    {
        return self::$tiers[$tier_id] ?? null;
    }
}

// ── تحميل الملفات الحقيقية من المشروع (بلا أي تعديل عليها) ──────────────────
// ملاحظة: PGE_Packages الحقيقي (بخلاف tests/test-feature-resolver.php) —
// هذا هو صميم هدف Commit 1.1b.

require_once __DIR__ . '/../includes/class-pge-feature-registry.php';
require_once __DIR__ . '/../includes/class-pge-tier-features.php';
require_once __DIR__ . '/../includes/class-pge-packages.php';
require_once __DIR__ . '/../includes/feature-resolver.php';

// ── أدوات الاختبار (نفس نمط check()/check_true() في بقية ملفات tests/) ──────

$total = 0;
$passed = 0;
$failures = [];

function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failures[] = $label;
        echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

function seed_catalog_user($user_id, $tier_id, $plan_id, $status = 'active')
{
    set_test_user_meta($user_id, '_mon_package_source', 'catalog');
    set_test_user_meta($user_id, '_mon_package_status', $status);
    set_test_user_meta($user_id, '_mon_catalog_tier_id', $tier_id);
    set_test_user_meta($user_id, '_mon_catalog_plan_id', $plan_id);
    PGE_Catalog::$tiers[$tier_id] = ['id' => $tier_id, 'plan_id' => $plan_id];
}

// إعداد خيار mon_packages_settings ثابت وحيد، يُستخدَم لكل السيناريوهات بصرف
// النظر عن ترتيب تنفيذها: 'plan_1' مطابق حرفياً لـ
// PGE_Packages::get_default_plans()['plan_1'] الحقيقية (بلا مفتاح google_maps/
// google_map إطلاقاً) — يضمن أن أي مستخدم لا يحدد pge_current_plan صراحة يقع
// على نفس السلوك الافتراضي الحقيقي. 'plan_both_keys' يُستخدَم حصراً في حالة
// رقم 3 (كلا المفتاحين موجودان معاً) عبر تحديد pge_current_plan صراحة لذلك
// المستخدم فقط.
set_test_option('mon_packages_settings', [
    'plan_1' => ['name' => 'الباقة الأساسية', 'price' => '49', 'guest_limit' => 15, 'host_photos' => 10, 'events_count' => 1, 'salla_url' => '#', 'color' => 'blue'],
    'plan_both_keys' => ['name' => 'Test Both Keys', 'price' => '0', 'guest_limit' => 0, 'host_photos' => 0, 'events_count' => 0, 'salla_url' => '#', 'color' => 'blue', 'google_maps' => 1, 'google_map' => 0],
]);

echo "=== Phase 6 — Commit 1.1b: اختبارات ارتداد Legacy Alias (google_maps ↔ google_map) ===\n";
echo "=== تنفيذ حقيقي لـ pge_user_has_feature() عبر feature-resolver.php + PGE_Feature_Registry + PGE_Packages الحقيقية ===\n\n";

// ── الحالة 1: Legacy google_map = 0 → pge_user_has_feature(..., 'google_maps') = false ──
// مستخدم Legacy (غير Catalog)، بلا Snapshot، _mon_active_features مصفوفة لا
// تحوي 'google_map' → PGE_Packages الحقيقية تضبط limits['google_map'] = 0
// صراحة (حلقة get_user_plan_limits()، السطر 53-59). فرع Legacy في الـResolver
// لا يجد 'google_maps' (Registry) فيلجأ للـAlias 'google_map' الحقيقي.
$u101 = 101;
set_test_user_meta($u101, '_mon_active_features', []);
check('1. Legacy google_map=0 → google_maps=false', pge_user_has_feature($u101, 'google_maps'), false);

// ── الحالة 2: Legacy google_map = 1 → pge_user_has_feature(..., 'google_maps') = true ──
$u102 = 102;
set_test_user_meta($u102, '_mon_active_features', ['google_map']);
check('2. Legacy google_map=1 → google_maps=true', pge_user_has_feature($u102, 'google_maps'), true);

// ── الحالة 3: كلا المفتاحين (google_maps وgoogle_map) موجودان معاً في Legacy ──
// → المفتاح القانوني (google_maps) يفوز دائماً، الـAlias يُتجاهَل صراحة.
// يُبنى عبر plan_both_keys الحقيقي (google_maps=1 من إعدادات الباقة المخزَّنة،
// google_map يُعاد ضبطه صراحة إلى 0 عبر حلقة _mon_active_features الحقيقية
// في PGE_Packages — بلا أي Test Double، كل هذا عبر تخزين وقراءة حقيقيين).
$u103 = 103;
set_test_user_meta($u103, 'pge_current_plan', 'plan_both_keys');
set_test_user_meta($u103, '_mon_active_features', []); // يضمن google_map=0 صراحة، google_maps يبقى 1 (ليس من مفاتيح get_feature_keys())
check('3. كلا المفتاحين موجودان (google_maps=1, google_map=0) → المفتاح القانوني يفوز → true', pge_user_has_feature($u103, 'google_maps'), true);

// ── الحالة 4: Snapshot لا يزال يتفوق على Legacy ──────────────────────────────
// Snapshot (_mon_package_features) = google_maps:false، بينما Legacy (لو
// استُشير) كان سيقول true (عبر google_map=1) — يجب أن يفوز Snapshot لأنه
// يُفحَص أولاً في الـResolver الحقيقي بصرف النظر عن أي بيانات Legacy.
$u104 = 104;
set_test_user_meta($u104, '_mon_package_features', ['google_maps' => false]);
set_test_user_meta($u104, '_mon_active_features', ['google_map']);
check('4. Snapshot=false يتفوق على Legacy google_map=1 → false', pge_user_has_feature($u104, 'google_maps'), false);

// ── الحالة 5: Tier لا يزال يتفوق على Legacy (Legacy مُتخطّى بنيوياً لمستخدم Catalog) ──
// مستخدم Catalog فعلي (source=catalog, status=active) — الـResolver الحقيقي
// لا يصل لفرع Legacy إطلاقاً لمثل هذا المستخدم (§13، مُطبَّق في السطر 181-185
// من feature-resolver.php: "return ['found' => false...]" فور انتهاء محاولة
// Tier، دون أي fallback لـLegacy). قيمة Tier الحقيقية (عبر PGE_Tier_Features
// الحقيقي + Fake $wpdb) هي 0 (false)، بينما Default من Registry هو true —
// إثبات أن Tier يُستخدَم فعلياً قبل الوصول لأي حالة اضطرارية أخرى.
$u105 = 105;
seed_catalog_user($u105, 9001, 0);
$wpdb->seed_row(9001, 'google_maps', '0');
check('5. Tier=0 (مستخدم Catalog) يتفوق → false (Legacy متخطّى بنيوياً)', pge_user_has_feature($u105, 'google_maps'), false);

// ── الحالة 5ب (تكميلية): الـAlias لا يُطبَّق إطلاقاً على طبقة Tier ───────────
// صف Tier مخزَّن تحت الاسم القديم 'google_map' (لا 'google_maps') بقيمة 0 —
// إن كان أي Mutation يطبّق الـAlias خطأً على Tier، لكانت النتيجة false. بما
// أن الـAlias محصور حصراً بفرع Legacy (كما في الكود الحقيقي)، النتيجة
// الصحيحة هي الرجوع لـDefault من Registry (true) لأن 'google_maps' غير
// موجود إطلاقاً في صفوف Tier لهذا الـtier.
$u106 = 106;
seed_catalog_user($u106, 9002, 0);
$wpdb->seed_row(9002, 'google_map', '0');
check('5ب. Alias غير مُطبَّق على Tier (صف باسم Legacy فقط) → Default=true', pge_user_has_feature($u106, 'google_maps'), true);

// ── الحالة 6: Default من Registry يُستخدَم فقط عند غياب Snapshot وTier وLegacy معاً ──
// مستخدم Legacy (غير Catalog)، بلا Snapshot، وبلا _mon_active_features مطلقاً
// (المفتاح غائب تماماً من User Meta) → PGE_Packages الحقيقية لا تُدخِل حلقة
// ضبط المفاتيح الأربعة عشر إطلاقاً (get_user_meta() تُعيد '' وليست Array) —
// فلا 'google_maps' ولا 'google_map' موجودان في limits على الإطلاق.
$u107 = 107;
// عمداً: لا استدعاء set_test_user_meta لـ'_mon_active_features' لهذا المستخدم.
check('6. Legacy تفتقر للمفتاحين معاً (لا _mon_active_features) → Default Registry=true', pge_user_has_feature($u107, 'google_maps'), true);

// ── الحالة 7: ميزة غير مرتبطة (gift_feature) غير متأثرة إطلاقاً بالـAlias ────
// نفس المستخدم لديه Legacy google_map=1 (لو استُشير لـgoogle_maps لكانت true)،
// لكن هذا الفحص على مفتاح مختلف تماماً (gift_feature) لا يقابله أي مفتاح
// Legacy إطلاقاً (لا 'gift_feature' ولا حتى 'stc_pay' يُعتبر Alias له —
// الـAlias في الكود الحقيقي محصور حرفياً بـ$feature_key === 'google_maps' فقط)
// → يجب أن يقع على Default الحقيقي من Registry لـgift_feature، وهو false.
$u108 = 108;
set_test_user_meta($u108, '_mon_active_features', ['google_map']);
check('7. gift_feature غير متأثرة بوجود google_map=1 في نفس Legacy limits → Default=false', pge_user_has_feature($u108, 'gift_feature'), false);
// تأكيد إضافي: نفس المستخدم، google_maps نفسها لا تزال تعمل بشكل صحيح (الـAlias يعمل تحديداً لها فقط)
check('7ب. لنفس المستخدم، google_maps (المفتاح المقصود) لا يزال يعمل عبر الـAlias → true', pge_user_has_feature($u108, 'google_maps'), true);

echo "\n=== النتيجة: $passed / $total ناجحة ===\n";

if ($passed !== $total) {
    echo "فشلت الحالات التالية:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

exit(0);
