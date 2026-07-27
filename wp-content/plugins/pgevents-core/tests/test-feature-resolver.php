<?php
/**
 * اختبار مستقل بذاته (بلا PHPUnit وبلا أي بنية اختبارات جديدة، بنفس نمط
 * tests/test-tier-features.php وtests/test-catalog-plan-limits.php) لـ
 * Phase 3 — Commit 2 (Feature Resolver — Testing)، وفق
 * docs/FEATURES-PHASE-3-SPEC.md §13 وdocs/DECISION-LOG.md (DEC-001, DEC-002,
 * DEC-003).
 *
 * يختبر **حصراً Public API الثلاث** المُعرَّفة في includes/feature-resolver.php:
 *   - pge_user_has_feature($user_id, $feature_key): bool
 *   - pge_get_user_feature_value($user_id, $feature_key, $default = null)
 *   - pge_get_user_package_features($user_id): array
 * لا استدعاء مباشر لأي دالة مساعدة داخلية (pge_feature_resolver_*) — كل
 * تحقق يمر عبر الثلاث أعلاه فقط، بما يشمل اختبار Type Parsing (عبر Seed
 * مباشر لقيمة Snapshot خام ثم قراءتها عبر Public API).
 *
 * الاعتماديات الحقيقية المحمَّلة بلا أي تعديل:
 *   - includes/class-pge-feature-registry.php (Phase 1، بلا DB)
 *   - includes/class-pge-tier-features.php (Phase 2، عبر Fake $wpdb أدناه)
 *   - includes/feature-resolver.php (الملف تحت الاختبار)
 *
 * PGE_Catalog وPGE_Packages: كلاهما كلاس اختباري خفيف بديل (Test Double)،
 * بنفس فلسفة tests/test-catalog-plan-limits.php ("لا حاجة لتحميل الكلاس
 * الحقيقي بكل تعقيده" لـPGE_Catalog تحديداً) — لا تعديل على أي ملف إنتاج.
 * ملاحظة صادقة حول PGE_Packages: لا تطابق حرفي اليوم بين مفاتيح Legacy الـ14
 * (PGE_Packages::get_feature_keys() الحقيقية) ومفاتيح Feature Registry الـ19
 * (موثَّق في PACKAGE-FEATURE-MATRIX.md §10) — الكلاس البديل هنا يُستخدَم
 * لعزل واختبار **آلية** قراءة Legacy في الـResolver نفسها (array_key_exists +
 * تفسير النوع) بمعزل عن حالة عدم التطابق الحالية، لا لادعاء أن هذا التطابق
 * موجود فعلياً في الإنتاج اليوم.
 *
 * التشغيل:
 *   php tests/test-feature-resolver.php
 *
 * ملاحظة بيئية: لا يوجد PHP CLI في بيئة إعداد هذا الاختبار (نفس القيد
 * البيئي الموثَّق مسبقاً في هذا المشروع). الاختبار مكتوب بالكامل وجاهز
 * للتشغيل الفعلي؛ التحقق المتاح هنا فحص AST فقط — **ليس بديلاً** لتشغيله.
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب فقط) ──────────────────────────

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

// ── Fake $wpdb — لتحميل includes/class-pge-tier-features.php الحقيقي ────────
// (نسخة مبسّطة من Fake_Wpdb_TierFeatures في tests/test-tier-features.php —
// فقط get_row/get_results/prepare + seed_row، لأن الـResolver لا يكتب أبداً)

class Fake_Wpdb_Resolver
{
    public $prefix = 'wp_';
    public $last_error = '';

    public $force_get_row_active = false;
    public $force_get_row_value = null;
    public $force_get_row_last_error = null;

    public $force_get_results_active = false;
    public $force_get_results_value = null;

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

        if ($this->force_get_row_active) {
            $this->force_get_row_active = false;
            if ($this->force_get_row_last_error !== null) {
                $this->last_error = $this->force_get_row_last_error;
            }
            return $this->force_get_row_value;
        }

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

        if ($this->force_get_results_active) {
            $this->force_get_results_active = false;
            return $this->force_get_results_value;
        }

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

// ── PGE_Catalog — Test Double خفيف (نفس فلسفة tests/test-catalog-plan-limits.php) ──

class PGE_Catalog
{
    public static $tiers = [];

    public static function get_tier($tier_id)
    {
        return self::$tiers[$tier_id] ?? null;
    }
}

// ── PGE_Packages — Test Double خفيف، لعزل واختبار آلية قراءة Legacy فقط ─────

class PGE_Packages
{
    public static $plan_limits_by_user = [];
    public static $call_count = 0;

    public static function get_user_plan_limits($user_id)
    {
        self::$call_count++;
        return self::$plan_limits_by_user[$user_id] ?? [];
    }
}

function set_test_legacy_limits($user_id, array $limits)
{
    PGE_Packages::$plan_limits_by_user[$user_id] = $limits;
}

// ── تحميل الملفات الحقيقية من المشروع (بلا أي تعديل عليها) ──────────────────

require_once __DIR__ . '/../includes/class-pge-feature-registry.php';
require_once __DIR__ . '/../includes/class-pge-tier-features.php';
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

// حالة نظيفة لكل تعريف Tier وهمي مستخدَم عبر الاختبار
function seed_catalog_user($user_id, $tier_id, $plan_id, $status = 'active')
{
    set_test_user_meta($user_id, '_mon_package_source', 'catalog');
    set_test_user_meta($user_id, '_mon_package_status', $status);
    set_test_user_meta($user_id, '_mon_catalog_tier_id', $tier_id);
    set_test_user_meta($user_id, '_mon_catalog_plan_id', $plan_id);
    PGE_Catalog::$tiers[$tier_id] = ['id' => $tier_id, 'plan_id' => $plan_id];
}

echo "=== قسم 1: Public API — الوجود والتواقيع ===\n";

check_true('1. pge_user_has_feature موجودة', function_exists('pge_user_has_feature'));
check_true('1. pge_get_user_feature_value موجودة', function_exists('pge_get_user_feature_value'));
check_true('1. pge_get_user_package_features موجودة', function_exists('pge_get_user_package_features'));

$rf_has = new ReflectionFunction('pge_user_has_feature');
check('2. pge_user_has_feature: عدد البارامترات = 2', count($rf_has->getParameters()), 2);
check('2. pge_user_has_feature: اسم البارامتر الأول', $rf_has->getParameters()[0]->getName(), 'user_id');
check('2. pge_user_has_feature: اسم البارامتر الثاني', $rf_has->getParameters()[1]->getName(), 'feature_key');
check_true('2. pge_user_has_feature: يحمل Return Type', $rf_has->hasReturnType());
check('2. pge_user_has_feature: نوع الإرجاع bool', (string) $rf_has->getReturnType(), 'bool');

$rf_val = new ReflectionFunction('pge_get_user_feature_value');
check('2. pge_get_user_feature_value: عدد البارامترات = 3', count($rf_val->getParameters()), 3);
check('2. pge_get_user_feature_value: اسم البارامتر الثالث', $rf_val->getParameters()[2]->getName(), 'default');
check_true(
    '2. pge_get_user_feature_value: القيمة الافتراضية للبارامتر الثالث = null',
    $rf_val->getParameters()[2]->isDefaultValueAvailable() && $rf_val->getParameters()[2]->getDefaultValue() === null
);
check_true('2. pge_get_user_feature_value: بلا Return Type Declaration (مطابق للتوقيع المُجمَّد §7)', $rf_val->hasReturnType() === false);

$rf_pkg = new ReflectionFunction('pge_get_user_package_features');
check('2. pge_get_user_package_features: عدد البارامترات = 1', count($rf_pkg->getParameters()), 1);
check_true('2. pge_get_user_package_features: يحمل Return Type', $rf_pkg->hasReturnType());
check('2. pge_get_user_package_features: نوع الإرجاع array', (string) $rf_pkg->getReturnType(), 'array');

echo "\n=== قسم 2: Unknown Feature ===\n";

check('3. pge_user_has_feature لمفتاح غير معروف → false', pge_user_has_feature(101, 'totally_unknown_xyz'), false);
check('4. pge_get_user_feature_value لمفتاح غير معروف مع default مخصَّص → يُعاد $default حرفياً', pge_get_user_feature_value(101, 'totally_unknown_xyz', 'MY_DEFAULT'), 'MY_DEFAULT');
check('4. pge_get_user_feature_value لمفتاح غير معروف مع default = مصفوفة → تُعاد كما هي', pge_get_user_feature_value(101, 'totally_unknown_xyz', ['a' => 1]), ['a' => 1]);
check('5. pge_get_user_feature_value لمفتاح غير معروف بلا default → null', pge_get_user_feature_value(101, 'totally_unknown_xyz'), null);
// ملاحظة: لمفتاح غير معروف، Registry Lookup يعيد مبكراً قبل أي استدعاء لـTier/Legacy —
// موثَّق سلوكياً عبر الأسطر أعلاه (لا Fatal، لا استثناء عند التنفيذ الفعلي). لا يوجد عدّاد
// استدعاءات حقيقي في Test Doubles هذا الملف لإثبات "عدم الاستدعاء" كـAssertion مستقل،
// لذا أُزيل الـAssertion الوهمي السابق هنا تفادياً لـFalse Positive (راجع مراجعة Commit 2).

echo "\n=== قسم 3: Snapshot — الأولوية والقيم الخاصة ===\n";

// gift_feature: boolean, Registry default = false
set_test_user_meta(201, '_mon_package_features', ['gift_feature' => false]);
check('6. Snapshot value = false (boolean) → has_feature = false', pge_user_has_feature(201, 'gift_feature'), false);

set_test_user_meta(202, '_mon_package_features', ['gift_feature' => '0']);
check('6. Snapshot value = "0" (boolean) → has_feature = false', pge_user_has_feature(202, 'gift_feature'), false);

set_test_user_meta(203, '_mon_package_features', ['gift_feature' => '']);
check('6. Snapshot value = "" (boolean) → has_feature = false', pge_user_has_feature(203, 'gift_feature'), false);

set_test_user_meta(204, '_mon_package_features', ['gift_feature' => true]);
check('6. Snapshot value = true (boolean) → has_feature = true', pge_user_has_feature(204, 'gift_feature'), true);

set_test_user_meta(205, '_mon_package_features', ['gift_feature' => 'true']);
check('6. Snapshot value = "true" (نص) → has_feature = true', pge_user_has_feature(205, 'gift_feature'), true);

// أولوية Snapshot فوق Tier/Legacy: نفس المستخدم بقيمة Tier مختلفة تماماً
seed_catalog_user(206, 3001, 5001);
$wpdb->seed_row(3001, 'gift_feature', '1'); // Tier تقول true
set_test_user_meta(206, '_mon_package_features', ['gift_feature' => false]); // Snapshot تقول false
check('6. Snapshot تسبق Tier عند تعارض القيمتين', pge_user_has_feature(206, 'gift_feature'), false);

echo "\n=== قسم 4: Tier — الأولوية، فشل DB، مصفوفة فارغة، قيمة موجودة ===\n";

// Tier قيمة موجودة فعلياً (بلا Snapshot)
seed_catalog_user(301, 3002, 5002);
$wpdb->seed_row(3002, 'gift_feature', '1');
check('9. Tier قيمة موجودة "1" (بلا Snapshot) → has_feature = true', pge_user_has_feature(301, 'gift_feature'), true);
check('9. نفس الحالة عبر get_user_feature_value → true', pge_get_user_feature_value(301, 'gift_feature'), true);

// Tier أولوية فوق Legacy: لا ينطبق فعلياً لمستخدم Catalog (Legacy لا تُقرأ أصلاً له) — يُختبَر ضمن قسم 5

// Tier DB failure — عبر get_tier_feature_value (المسار المفرد)
seed_catalog_user(302, 3003, 5003);
$wpdb->force_get_row_active = true;
$wpdb->force_get_row_value = null;
$wpdb->force_get_row_last_error = 'Simulated DB failure';
check('7. Tier DB failure (get_tier_feature_value) → يُتابَع لـRegistry Default (gift_feature: false)', pge_get_user_feature_value(302, 'gift_feature'), false);

// Tier DB failure — عبر get_all_tier_features (مسار package_features)
seed_catalog_user(303, 3004, 5004);
$wpdb->force_get_results_active = true;
$wpdb->force_get_results_value = null;
$pkg_303 = pge_get_user_package_features(303);
check('7. Tier DB failure (get_all_tier_features) → gift_feature في package_features = false (Default)', $pkg_303['gift_feature'], false);

// Tier مصفوفة فارغة (لا صفوف لهذا الـTier إطلاقاً)
seed_catalog_user(304, 3005, 5005);
check('8. Tier بلا أي صف (مصفوفة فارغة) → get_user_feature_value يعيد Default', pge_get_user_feature_value(304, 'gift_feature'), false);
$pkg_304 = pge_get_user_package_features(304);
check('8. نفس الحالة عبر package_features', $pkg_304['gift_feature'], false);

echo "\n=== قسم 5: Legacy — لمستخدم Non-Catalog، واستبعاده لمستخدم Catalog ===\n";

// مستخدم Non-Catalog (لا _mon_package_source='catalog') — Legacy تُقرأ فعلاً
set_test_legacy_limits(401, ['gift_feature' => '1']); // Test Double: تطابق مباشر مقصود لعزل الآلية (راجع تعليق أعلى الملف)
check('10. Legacy value موجودة لمستخدم Non-Catalog → has_feature = true', pge_user_has_feature(401, 'gift_feature'), true);
check_true('10. PGE_Packages::get_user_plan_limits() استُدعيت فعلاً لهذا المستخدم', PGE_Packages::$call_count > 0);

// مستخدم Catalog: نفس المفتاح موجود في Legacy لكن يجب ألا يُقرأ إطلاقاً
seed_catalog_user(402, 3006, 5006); // لا صف Tier لـgift_feature، لا Snapshot
set_test_legacy_limits(402, ['gift_feature' => '1']); // لو قُرئت Legacy خطأً لأعادت true
check('11. مستخدم Catalog لا يقرأ Legacy إطلاقاً → يؤول لـRegistry Default (false) لا لقيمة Legacy (true)', pge_user_has_feature(402, 'gift_feature'), false);

echo "\n=== قسم 6: Registry Default — boolean / integer / percentage / TBD ===\n";

check('12. Registry Default (boolean=true) لمستخدم بلا أي مصدر — event_website', pge_user_has_feature(501, 'event_website'), true);
check('12. Registry Default (boolean=false) لمستخدم بلا أي مصدر — gift_feature', pge_user_has_feature(501, 'gift_feature'), false);
// ملاحظة: لا توجد ميزة Integer عادية (بلا TBD) في Registry اليوم يمكن اختبار قيمتها
// الافتراضية بمعزل عن TBD؛ سيناريو Registry Default لنوع Integer مغطّى فعلياً أدناه
// عبر حالات TBD الثلاث (host_limit / admin_supervisor_limit / invitation_design_limit).
check('12. Registry Default (percentage=0) لمستخدم بلا أي مصدر — support_services_discount_percentage', pge_get_user_feature_value(501, 'support_services_discount_percentage'), 0);
check_true('12. النتيجة من النوع int لا string', is_int(pge_get_user_feature_value(501, 'support_services_discount_percentage')));
check('12. Registry Default = "TBD" (host_limit) → (int)"TBD" = 0 صامتة (DEC-003)', pge_get_user_feature_value(501, 'host_limit'), 0);
check_true('12. نتيجة TBD من النوع int بالضبط', is_int(pge_get_user_feature_value(501, 'host_limit')));
check('12. Registry Default = "TBD" (admin_supervisor_limit) → 0', pge_get_user_feature_value(501, 'admin_supervisor_limit'), 0);
check('12. Registry Default = "TBD" (invitation_design_limit) → 0', pge_get_user_feature_value(501, 'invitation_design_limit'), 0);

echo "\n=== قسم 7: Resolution Precedence كاملة (Snapshot → Tier → Legacy → Default) ===\n";

// مستخدم Catalog بكل المصادر معاً — Snapshot يجب أن يفوز
seed_catalog_user(601, 3007, 5007);
$wpdb->seed_row(3007, 'gift_feature', '1'); // Tier: true
set_test_legacy_limits(601, ['gift_feature' => '1']); // (لن تُقرأ أصلاً لمستخدم Catalog)
set_test_user_meta(601, '_mon_package_features', ['gift_feature' => false]); // Snapshot: false
check('13أ. Snapshot يفوز على Tier وLegacy وDefault معاً', pge_user_has_feature(601, 'gift_feature'), false);

// إزالة Snapshot — يجب أن يفوز Tier
set_test_user_meta(602, '_mon_package_features', []);
seed_catalog_user(602, 3008, 5008);
$wpdb->seed_row(3008, 'gift_feature', '1');
check('13ب. بلا Snapshot: Tier يفوز على Default (Catalog لا يقرأ Legacy أصلاً)', pge_user_has_feature(602, 'gift_feature'), true);

// مستخدم Non-Catalog بلا Snapshot: يجب أن يفوز Legacy
set_test_legacy_limits(603, ['gift_feature' => '1']);
check('13ج. Non-Catalog بلا Snapshot: Legacy يفوز على Default', pge_user_has_feature(603, 'gift_feature'), true);

// لا شيء إطلاقاً: Default
check('13د. بلا أي مصدر: Default (gift_feature=false)', pge_user_has_feature(604, 'gift_feature'), false);

echo "\n=== قسم 8: Boolean Parsing (عبر Public API فقط، بواسطة Snapshot) ===\n";

// قائمة مرتَّبة (لا Associative Array) عمداً — استخدام القيم الخام كمفاتيح
// Array يُسبِّب تحويل PHP القسري لأنواع المفاتيح (null→''، true→1، "1"→1،
// إلخ)، مما يُدمج عدة حالات مختلفة في مفتاح واحد بصمت. القائمة المرتَّبة
// تحافظ على النوع الحقيقي لكل قيمة خام دون أي دمج أو فقدان.
$boolean_cases = [
    ['1', true],
    ['0', false],
    [1, true],
    [0, false],
    [true, true],
    [false, false],
    ['true', true],
    ['false', false],
    ['', false],
    [null, false],
    ['yes', true],
    ['on', true],
    ['abc', false],
];

$uid = 700;
foreach ($boolean_cases as [$raw, $expected]) {
    $uid++;
    set_test_user_meta($uid, '_mon_package_features', ['gift_feature' => $raw]);
    $label_raw = var_export($raw, true);
    check("14. Boolean parsing لقيمة خام $label_raw → " . var_export($expected, true), pge_user_has_feature($uid, 'gift_feature'), $expected);
}

echo "\n=== قسم 9: Integer Parsing (عبر Public API فقط، بواسطة Snapshot) ===\n";

// قائمة مرتَّبة (لا Associative Array) لنفس سبب Boolean أعلاه — "" وnull
// يتحوَّلان لنفس مفتاح Array ('') لو استُخدما كمفاتيح، فيُفقَد تمييزهما.
$integer_cases = [
    ['001', 1],
    ['-1', -1],
    ['12.5', 12],
    ['abc', 0],
    ['', 0],
    [null, 0],
];

$uid = 800;
foreach ($integer_cases as [$raw, $expected]) {
    $uid++;
    set_test_user_meta($uid, '_mon_package_features', ['host_limit' => $raw]);
    $label_raw = var_export($raw, true);
    check("15. Integer parsing لقيمة خام $label_raw → $expected", pge_get_user_feature_value($uid, 'host_limit'), $expected);
    check_true("15. النتيجة من النوع int لقيمة خام $label_raw", is_int(pge_get_user_feature_value($uid, 'host_limit')));
}

echo "\n=== قسم 10: Percentage Parsing (نفس آلية Integer تماماً) ===\n";

$percentage_cases = [
    ['12.50', 12],
    ['-1', -1],
    ['101', 101],
    ['', 0],
    ['abc', 0],
];

$uid = 900;
foreach ($percentage_cases as [$raw, $expected]) {
    $uid++;
    set_test_user_meta($uid, '_mon_package_features', ['support_services_discount_percentage' => $raw]);
    check("16. Percentage parsing لقيمة خام '$raw' → $expected (بلا Clamp، بلا رفض)", pge_get_user_feature_value($uid, 'support_services_discount_percentage'), $expected);
}

echo "\n=== قسم 11: تمييز resolved=false عن not found ===\n";

// google_maps: boolean, Registry default = true — إن اختلط "found=true,value=false"
// بـ"لم يُعثَر"، ستكون النتيجة الخاطئة true (Default) بدل false (القيمة الفعلية).
set_test_user_meta(1001, '_mon_package_features', ['google_maps' => false]);
check('17. Snapshot value=false صراحة لميزة Default=true → النتيجة false لا true (لا خلط بين resolved=false وnot-found)', pge_user_has_feature(1001, 'google_maps'), false);
check('17. نفس الحالة عبر get_user_feature_value', pge_get_user_feature_value(1001, 'google_maps'), false);

// تأكيد إضافي: مستخدم بلا أي قيمة إطلاقاً لنفس الميزة → Default الحقيقي (true)
check('17. مقارنة: مستخدم بلا أي مصدر لنفس الميزة → Default الحقيقي true (لا false)', pge_user_has_feature(1002, 'google_maps'), true);

echo "\n=== قسم 12: pge_get_user_package_features() — الشكل والمحتوى ===\n";

$registry_keys = array_keys(PGE_Feature_Registry::all());
$pkg_1101 = pge_get_user_package_features(1101);

check_true('18. النتيجة array', is_array($pkg_1101));
check('18. عدد العناصر = عدد ميزات Registry الفعلي', count($pkg_1101), count($registry_keys));
// مقارنة مستقلة عن ترتيب العناصر (لا يوجد عقد موثَّق يُلزم بحفظ ترتيب المفاتيح):
// نطابق المفتاحين كمجموعتين (Sets) عبر الفرز قبل المقارنة، بدل الاعتماد على ترتيب Array.
$pkg_keys_sorted = array_keys($pkg_1101);
$registry_keys_sorted = $registry_keys;
sort($pkg_keys_sorted);
sort($registry_keys_sorted);
check('18. المفاتيح مطابقة تماماً لمفاتيح Registry كمجموعة (بلا زيادة أو نقصان، بمعزل عن الترتيب)', $pkg_keys_sorted, $registry_keys_sorted);

$is_flat = true;
foreach ($pkg_1101 as $v) {
    if (is_array($v)) { $is_flat = false; break; }
}
check_true('18. المصفوفة مسطّحة (Flat) — لا قيمة داخلية هي Array', $is_flat);

echo "\n=== قسم 13: التكافؤ بين pge_get_user_feature_value() وpge_get_user_package_features()[\$key] ===\n";

// مستخدم Catalog غني بالبيانات (Snapshot جزئي + Tier لبقية المفاتيح)
seed_catalog_user(1201, 3009, 5009);
set_test_user_meta(1201, '_mon_package_features', ['gift_feature' => true]);
$wpdb->seed_row(3009, 'host_limit', '7');
$wpdb->seed_row(3009, 'support_services_discount_percentage', '25');

$sample_keys = ['gift_feature', 'host_limit', 'support_services_discount_percentage', 'google_maps', 'event_website'];
$pkg_1201 = pge_get_user_package_features(1201);
foreach ($sample_keys as $k) {
    check("19. تكافؤ Catalog لمفتاح '$k'", pge_get_user_feature_value(1201, $k), $pkg_1201[$k]);
}

// مستخدم Non-Catalog
set_test_legacy_limits(1202, ['gift_feature' => '1']);
$pkg_1202 = pge_get_user_package_features(1202);
foreach ($sample_keys as $k) {
    check("19. تكافؤ Non-Catalog لمفتاح '$k'", pge_get_user_feature_value(1202, $k), $pkg_1202[$k]);
}

echo "\n=== قسم 14: مسارات Catalog وNon-Catalog صريحة ===\n";

seed_catalog_user(1301, 3010, 5010);
$wpdb->seed_row(3010, 'event_website', '0');
check('20. مسار Catalog: قيمة Tier صريحة (event_website=0) تُقرأ وتُفسَّر → false', pge_user_has_feature(1301, 'event_website'), false);

set_test_legacy_limits(1302, ['gift_feature' => '0']);
check('21. مسار Non-Catalog: قيمة Legacy صريحة (gift_feature=0) → false', pge_user_has_feature(1302, 'gift_feature'), false);

echo "\n=== قسم 15: plan_id mismatch ===\n";

set_test_user_meta(1401, '_mon_package_source', 'catalog');
set_test_user_meta(1401, '_mon_package_status', 'active');
set_test_user_meta(1401, '_mon_catalog_tier_id', 3011);
set_test_user_meta(1401, '_mon_catalog_plan_id', 9999); // المخزَّن لدى المستخدم
PGE_Catalog::$tiers[3011] = ['id' => 3011, 'plan_id' => 8888]; // مختلف فعلياً عن الـtier
$wpdb->seed_row(3011, 'gift_feature', '1');
check('22. plan_id mismatch → تُهمَل بيانات Tier بالكامل → Default (false) لا قيمة Tier (true)', pge_user_has_feature(1401, 'gift_feature'), false);

echo "\n=== قسم 16: Tier غير موجود (tier_id لا يقابله صف في PGE_Catalog) ===\n";

set_test_user_meta(1501, '_mon_package_source', 'catalog');
set_test_user_meta(1501, '_mon_package_status', 'active');
set_test_user_meta(1501, '_mon_catalog_tier_id', 999999); // لا وجود له في PGE_Catalog::$tiers
$wpdb->seed_row(999999, 'gift_feature', '1'); // حتى لو وُجد صف Tier Features، Tier نفسه غير موجود بـCatalog
check('23. Tier غير موجود في PGE_Catalog::get_tier() → Default (false) بلا Fatal', pge_user_has_feature(1501, 'gift_feature'), false);

echo "\n=== قسم 17: Snapshot غير صالحة بنيوياً (Malformed) ===\n";

set_test_user_meta(1601, '_mon_package_features', 'not-an-array-string');
check('24. Snapshot سلسلة نصية (غير Array) → لا تُعامَل كموجودة، ينتقل البحث بأمان', pge_user_has_feature(1601, 'gift_feature'), false);

set_test_user_meta(1602, '_mon_package_features', '');
check('24. Snapshot سلسلة فارغة (القيمة الافتراضية لمفتاح غير موجود) → بلا Warning، ينتقل البحث', pge_user_has_feature(1602, 'event_website'), true);

echo "\n=== قسم 18: DB Failure شامل (المسارين معاً) ===\n";

// فشل get_tier_feature_value (مسار مفرد) لمستخدم بلا Snapshot ولا Legacy fallback ممكن
seed_catalog_user(1701, 3012, 5012);
$wpdb->force_get_row_active = true;
$wpdb->force_get_row_value = null;
$wpdb->force_get_row_last_error = 'DB down';
check('25. DB failure (get_tier_feature_value) عبر has_feature → Default بلا استثناء', pge_user_has_feature(1701, 'gift_feature'), false);

// فشل get_all_tier_features (مسار جماعي)
seed_catalog_user(1702, 3013, 5013);
$wpdb->force_get_results_active = true;
$wpdb->force_get_results_value = null;
$pkg_1702 = pge_get_user_package_features(1702);
check_true('25. DB failure (get_all_tier_features) عبر package_features → مصفوفة كاملة (19 عنصراً) بلا استثناء', count($pkg_1702) === count($registry_keys));
check('25. DB failure: كل قيمة تؤول لـDefault المفسَّر (gift_feature=false)', $pkg_1702['gift_feature'], false);

// ── ملخص ──────────────────────────────────────────────────────────────────

echo "\n";
echo "النتيجة: $passed / $total نجحت.\n";

if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}

exit(0);
