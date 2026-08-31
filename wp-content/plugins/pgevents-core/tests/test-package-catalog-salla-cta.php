<?php
/**
 * E2E-02 FIX PASS 2 — Package Catalog CTA → Real Salla Product.
 *
 * تصنيف الأدلة (كما في مراجعات E2E السابقة — لا خلط بين الأنواع):
 *
 * القسم 1 = اختبار وحدة حقيقي (Real Unit Test) على الصنف الحقيقي نفسه —
 * PGE_Packages_Widget من class-pge-packages-widget.php — يُحمَّل ويُنفَّذ
 * فعلياً هنا (لا نسخة موازية/محاكاة منطقية له)، ضمن Stubs دنيا لبيئة
 * ووردبريس/إليمنتور (PGE_Catalog، Elementor\Widget_Base، دوال ووردبريس
 * الأساسية). الدوال المُختبَرة (build_catalog_packages/get_https_purchase_url/
 * render) خاصة (private/protected) فتُستدعى عبر ReflectionMethod، لكن
 * الكود المُنفَّذ هو نفسه كود الإنتاج الحرفي دون أي تعديل. هذا اختبار وحدة
 * حقيقي على الكلاس، وليس فحصاً نصياً فقط — لكنه ليس اختبار تكامل HTTP
 * لووردبريس، ولا يُشغِّل أي طلب شبكة حقيقي لـSalla.
 *
 * القسم 2 = فحص نصي/حدودي (Source/Boundary Scan) — لا يُشغِّل أي كود. يثبت
 * عدم استدعاء أي دالة HTTP خارجية من ملف الـWidget (لا استدعاء API لسلة من
 * صفحة التصفح)، وعدم وجود أي إشارة لمنطق الـWebhook/التفعيل داخل هذا
 * الملف، وعدم تسرّب أي بيانات حساسة (nonce/user_id/توكن) داخل بناء رابط
 * الشراء.
 *
 * لا يوجد اختبار تكامل HTTP كامل لووردبريس أو اتصال فعلي بـSalla في هذا
 * الملف أو في المشروع حالياً يغطي هذا التدفق من متصفح حقيقي حتى Webhook
 * حقيقي — هذا الملف هو الدليل الآلي المتاح فقط.
 *
 * التشغيل:
 *   php tests/test-package-catalog-salla-cta.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

namespace {

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

function check_contains($label, $haystack, $needle)
{
    check_true($label, is_string($haystack) && strpos($haystack, $needle) !== false);
}

function check_not_contains($label, $haystack, $needle)
{
    check_true($label, is_string($haystack) && strpos($haystack, $needle) === false);
}

// ═══════════════════════════════════════════════════════════════════════
// Stubs دنيا لبيئة ووردبريس/إليمنتور — دوال حقيقية بسيطة، لا محاكاة منطقية
// لكود الإنتاج نفسه (كود الإنتاج يُحمَّل وينفَّذ فعلياً أدناه).
// ═══════════════════════════════════════════════════════════════════════

define('ABSPATH', '/tmp/');

function __($text, $domain = 'default') { return $text; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_html__($text, $domain = 'default') { return $text; }
function esc_url($url, $protocols = null) { return (string) $url; }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url_raw($url, $protocols = null) { return (string) $url; }
function sanitize_key($key) {
    $key = strtolower((string) $key);
    return preg_replace('/[^a-z0-9_\-]/', '', $key);
}
function wp_parse_url($url, $component = -1) { return parse_url($url); }
function home_url($path = '') { return 'https://hilwah.net' . $path; }
function add_query_arg($key, $value = null, $url = null) {
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $sep . $key . '=' . $value;
}
function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, $decimals); }
function get_option($name, $default = false) { return $default; }

class WP_User { public $ID = 0; }

}

namespace Elementor {
    class Controls_Manager {
        const TEXT = 'text';
        const TEXTAREA = 'textarea';
        const SWITCHER = 'switcher';
        const SELECT = 'select';
        const COLOR = 'color';
        const NUMBER = 'number';
        const URL = 'url';
    }
    class Group_Control_Typography {
        public static function get_type() { return 'typography'; }
    }
    class Widget_Base {
        public $test_settings = [];
        public function get_settings_for_display() { return $this->test_settings; }
        protected function start_controls_section($id, $args) {}
        protected function add_control($id, $args) {}
        protected function add_group_control($type, $args) {}
        protected function end_controls_section() {}
        public function get_id() { return 'test-instance'; }
    }
}

namespace {

// PGE_Catalog stub — بيانات ثابتة يتحكم بها الاختبار، تمثل بنية الجدولين
// الحقيقيين mon_catalog_plans/mon_plan_tiers (plan لا يحمل أي حقل Salla،
// الحقول الثلاثة salla_product_id/salla_sku/salla_url على الـTier فقط —
// طبقاً للمخطط الحقيقي في class-mon-catalog-schema.php).
class PGE_Catalog
{
    public static $plans = [];
    public static $tiers_by_plan = [];

    public static function get_plans() { return self::$plans; }
    public static function get_plan_tiers($plan_id) { return self::$tiers_by_plan[$plan_id] ?? []; }
}

$widget_file = __DIR__ . '/../../../themes/pgevents-pro/inc/elementor/widgets/class-pge-packages-widget.php';
$widget_src = file_exists($widget_file) ? (string) file_get_contents($widget_file) : '';
check_true('ملف class-pge-packages-widget.php موجود وقابل للقراءة', $widget_src !== '');

require $widget_file;
check_true('الصنف الحقيقي PGE_Packages_Widget مُحمَّل فعلياً', class_exists('PGE_Packages_Widget'));

$widget = new PGE_Packages_Widget();
$ref = new ReflectionClass($widget);

function call_private($obj, $ref, $method, $args = [])
{
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($obj, $args);
}

// ═══════════════════════════════════════════════════════════════════════
// القسم 1 — اختبار وحدة حقيقي على get_https_purchase_url()
// ═══════════════════════════════════════════════════════════════════════

check('get_https_purchase_url: رابط https صالح يُقبَل كما هو',
    call_private($widget, $ref, 'get_https_purchase_url', ['https://hilwah.net/salla/p/100']),
    'https://hilwah.net/salla/p/100');

check('get_https_purchase_url: رابط http (غير https) يُرفَض → سلسلة فارغة',
    call_private($widget, $ref, 'get_https_purchase_url', ['http://hilwah.net/salla/p/100']),
    '');

check('get_https_purchase_url: javascript: scheme يُرفَض → سلسلة فارغة',
    call_private($widget, $ref, 'get_https_purchase_url', ['javascript:alert(1)']),
    '');

check('get_https_purchase_url: سلسلة فارغة تُعيد سلسلة فارغة',
    call_private($widget, $ref, 'get_https_purchase_url', ['']),
    '');

check('get_https_purchase_url: قيمة غير نصية (null) تُعيد سلسلة فارغة',
    call_private($widget, $ref, 'get_https_purchase_url', [null]),
    '');

check('get_https_purchase_url: رابط بلا host يُرفَض → سلسلة فارغة',
    call_private($widget, $ref, 'get_https_purchase_url', ['https:///no-host']),
    '');

// ═══════════════════════════════════════════════════════════════════════
// القسم 1 — اختبار وحدة حقيقي على build_catalog_packages()
// ═══════════════════════════════════════════════════════════════════════

PGE_Catalog::$plans = [
    ['id' => 1, 'plan_key' => 'halwa_classic', 'name' => 'حلوة كلاسيك', 'status' => 'active', 'features' => '[]'],
    ['id' => 2, 'plan_key' => 'halwa_premium', 'name' => 'حلوة بريميوم', 'status' => 'active', 'features' => '[]'],
    ['id' => 3, 'plan_key' => 'halwa_no_mapping', 'name' => 'حلوة بلا ربط', 'status' => 'active', 'features' => '[]'],
    ['id' => 4, 'plan_key' => 'halwa_inactive_tier', 'name' => 'حلوة Tier معطّل', 'status' => 'active', 'features' => '[]'],
    ['id' => 5, 'plan_key' => 'halwa_insecure_url', 'name' => 'حلوة رابط غير آمن', 'status' => 'active', 'features' => '[]'],
    ['id' => 6, 'plan_key' => 'halwa_no_product_id', 'name' => 'حلوة بلا معرّف منتج', 'status' => 'active', 'features' => '[]'],
    // E2E-02 FIX PASS 3 — سيناريوهات إضافية خاصة بعقد السعر (القسم 8، A-E):
    ['id' => 7, 'plan_key' => 'halwa_genuine_zero', 'name' => 'حلوة صفر حقيقي', 'status' => 'active', 'features' => '[]'],
    ['id' => 8, 'plan_key' => 'halwa_no_active_tier', 'name' => 'حلوة بلا Tier نشط', 'status' => 'active', 'features' => '[]'],
    // E2E-02 FIX PASS 4 — سيناريوهات "منتج سلة واحد مشترك بين عدة Tiers"
    // (القسم 6، B/D/E من الطلب):
    ['id' => 9, 'plan_key' => 'halwa_shared_product', 'name' => 'حلوة منتج مشترك', 'status' => 'active', 'features' => '[]'],
    ['id' => 10, 'plan_key' => 'halwa_inactive_noise', 'name' => 'حلوة تشويش Tier معطّل', 'status' => 'active', 'features' => '[]'],
    ['id' => 11, 'plan_key' => 'halwa_url_id_mismatch', 'name' => 'حلوة تعارض معرّف المنتج', 'status' => 'active', 'features' => '[]'],
];

PGE_Catalog::$tiers_by_plan = [
    // A: Tier نشط واحد فقط، وله salla_product_id وsalla_url صالحان معاً
    // → CTA يفتح Salla مباشرة (is_direct_purchase = true) — R‑A/E.
    1 => [
        ['id' => 11, 'plan_id' => 1, 'status' => 'active', 'price' => 500, 'currency' => 'SAR', 'salla_product_id' => 'SP100', 'salla_url' => 'https://hilwah.net/salla/p/100'],
    ],
    // B (E2E-02 FIX PASS 4، C من القسم 6): مستويان نشطان، برابطَي منتج
    // سلة مختلفَين فعلياً ومعرّفَي منتج مختلفَين أيضاً → منتجا سلة
    // حقيقيان مختلفان (وليس مجرد "أكثر من Tier" كما كان يُفترَض خطأً قبل
    // هذا الإصلاح) → لا يوجد منتج واحد غير قابل للالتباس → تبقى على
    // الرابط الداخلي (Fail Closed، لا تخمين) — R‑C.
    2 => [
        ['id' => 21, 'plan_id' => 2, 'status' => 'active', 'price' => 800, 'currency' => 'SAR', 'salla_product_id' => 'SP200', 'salla_url' => 'https://hilwah.net/salla/p/200'],
        ['id' => 22, 'plan_id' => 2, 'status' => 'active', 'price' => 1200, 'currency' => 'SAR', 'salla_product_id' => 'SP201', 'salla_url' => 'https://hilwah.net/salla/p/201'],
    ],
    // C: Tier نشط لكن بلا أي ربط Salla إطلاقاً → غير مطابَق → الرابط
    // الداخلي (Fail Closed للربط المفقود) — R‑D.
    3 => [
        ['id' => 31, 'plan_id' => 3, 'status' => 'active', 'price' => 300, 'currency' => 'SAR', 'salla_product_id' => '', 'salla_url' => ''],
    ],
    // D: Tier معطّل (status=inactive) رغم امتلاكه بيانات Salla صالحة ظاهرياً
    // → يُستبعَد بالكامل من عدّ Tiers القابلة للشراء → الرابط الداخلي — R‑E.
    4 => [
        ['id' => 41, 'plan_id' => 4, 'status' => 'inactive', 'price' => 400, 'currency' => 'SAR', 'salla_product_id' => 'SP400', 'salla_url' => 'https://hilwah.net/salla/p/400'],
    ],
    // E: salla_url غير آمن (http وليس https) رغم وجود salla_product_id →
    // get_https_purchase_url() يرفضه → غير قابل للشراء → الرابط الداخلي — R‑F.
    5 => [
        ['id' => 51, 'plan_id' => 5, 'status' => 'active', 'price' => 600, 'currency' => 'SAR', 'salla_product_id' => 'SP500', 'salla_url' => 'http://hilwah.net/salla/p/500'],
    ],
    // F: salla_url صالح لكن salla_product_id فارغ → كلاهما مطلوب معاً →
    // غير قابل للشراء → الرابط الداخلي.
    6 => [
        ['id' => 61, 'plan_id' => 6, 'status' => 'active', 'price' => 700, 'currency' => 'SAR', 'salla_product_id' => '', 'salla_url' => 'https://hilwah.net/salla/p/600'],
    ],
    // G (E2E-02 FIX PASS 3، D): Tier نشط واحد بسعر صفر حقيقي مُخزَّن فعلياً —
    // يجب أن يُعرَض "0" + العملة كما هو (لا إخفاء، لا استبدال بنص آخر)، لأن
    // normalize_price() في class-pge-catalog.php تسمح بالصفر عمداً كقيمة
    // صالحة (راجع التقرير) — هذا يثبت أن "0" يظهر فقط عندما يكون هو فعلاً
    // القيمة المُخزَّنة، لا نتيجة أي عطل في القراءة.
    7 => [
        ['id' => 71, 'plan_id' => 7, 'status' => 'active', 'price' => 0, 'currency' => 'SAR', 'salla_product_id' => '', 'salla_url' => ''],
    ],
    // H (E2E-02 FIX PASS 3، C): لا يوجد أي Tier نشط إطلاقاً (الوحيد معطّل) →
    // لا يوجد سعر لعرضه → "تواصل معنا" بلا عملة، وليس "0".
    8 => [
        ['id' => 81, 'plan_id' => 8, 'status' => 'inactive', 'price' => 999, 'currency' => 'SAR', 'salla_product_id' => '', 'salla_url' => ''],
    ],
    // I (E2E-02 FIX PASS 4، B/D من القسم 6): ثلاثة Tiers نشطة، بنفس رابط
    // منتج سلة تماماً ونفس معرّف المنتج تماماً، لكن بـSKU مختلف لكل مستوى
    // (تمثيل حقيقي لـ"مستويات/متغيّرات داخل منتج سلة واحد") وبأسعار مختلفة
    // (السعر يبقى معياراً منفصلاً تماماً عن قرار الشراء المباشر) → يجب أن
    // تُعامَل كمنتج سلة واحد لا لبس فيه → شراء مباشر لذلك الرابط المشترك.
    // هذا يثبت أيضاً إزالة التكرار (Dedup) قبل الحكم بالالتباس: 3 قيم
    // مكرَّرة للرابط ولمعرّف المنتج يجب أن تُختزَل كل منها إلى قيمة واحدة
    // فريدة، لا أن تُحسَب كـ"عدة قيم" فتُرفَض خطأً.
    9 => [
        ['id' => 91, 'plan_id' => 9, 'status' => 'active', 'price' => 400, 'currency' => 'SAR', 'salla_product_id' => 'SP900', 'salla_sku' => 'SKU900A', 'salla_url' => 'https://hilwah.net/salla/p/900'],
        ['id' => 92, 'plan_id' => 9, 'status' => 'active', 'price' => 600, 'currency' => 'SAR', 'salla_product_id' => 'SP900', 'salla_sku' => 'SKU900B', 'salla_url' => 'https://hilwah.net/salla/p/900'],
        ['id' => 93, 'plan_id' => 9, 'status' => 'active', 'price' => 900, 'currency' => 'SAR', 'salla_product_id' => 'SP900', 'salla_sku' => 'SKU900C', 'salla_url' => 'https://hilwah.net/salla/p/900'],
    ],
    // J (E2E-02 FIX PASS 4، E من القسم 6): Tier نشط واحد قابل للشراء فعلياً
    // + Tier آخر معطّل (inactive) له رابط/معرّف منتج *مختلفَين تماماً* عن
    // الـTier النشط. يجب ألا يُدخِل الـTier المعطّل أي التباس زائف — النتيجة
    // يجب أن تبقى "شراء مباشر" لرابط الـTier النشط الوحيد، تماماً كأن
    // الـTier المعطّل غير موجود إطلاقاً (يُستبعَد قبل حتى دخول قوائم
    // الروابط/المعرّفات القابلة للشراء).
    10 => [
        ['id' => 101, 'plan_id' => 10, 'status' => 'active', 'price' => 350, 'currency' => 'SAR', 'salla_product_id' => 'SP1000', 'salla_url' => 'https://hilwah.net/salla/p/1000'],
        ['id' => 102, 'plan_id' => 10, 'status' => 'inactive', 'price' => 999, 'currency' => 'SAR', 'salla_product_id' => 'SP2000', 'salla_url' => 'https://hilwah.net/salla/p/2000'],
    ],
    // K (تغطية إضافية للقسم 3 — تعارض بيانات حقيقي): تياران نشطان بنفس
    // رابط منتج سلة تماماً، لكن بمعرّفَي منتج مختلفَين (تناقض بيانات يخالف
    // نموذج "منتج واحد بمستويات" — لا يجوز افتراض أيهما صحيح). يجب أن يبقى
    // الفشل آمناً (الرابط الداخلي)، لا شراءً مباشراً تخمينياً.
    11 => [
        ['id' => 111, 'plan_id' => 11, 'status' => 'active', 'price' => 450, 'currency' => 'SAR', 'salla_product_id' => 'SP1100A', 'salla_url' => 'https://hilwah.net/salla/p/1100'],
        ['id' => 112, 'plan_id' => 11, 'status' => 'active', 'price' => 550, 'currency' => 'SAR', 'salla_product_id' => 'SP1100B', 'salla_url' => 'https://hilwah.net/salla/p/1100'],
    ],
];

$settings = [
    'featured_plan' => 'plan_none',
    'max_features'  => 7,
    'fallback_url'  => ['url' => ''],
];

$packages = call_private($widget, $ref, 'build_catalog_packages', [$settings]);
check('build_catalog_packages: يُعيد 11 باقة (بعدد PGE_Catalog::$plans)', count($packages), 11);

$by_key = [];
foreach ($packages as $p) { $by_key[$p['plan_key']] = $p; }

// A — halwa_classic: Tier واحد قابل للشراء → رابط Salla مباشر
check_true('A: halwa_classic → is_direct_purchase = true', $by_key['halwa_classic']['is_direct_purchase'] === true);
check('A: halwa_classic → رابط CTA = رابط Salla الحقيقي للـTier نفسه', $by_key['halwa_classic']['url'], 'https://hilwah.net/salla/p/100');
check_not_contains('B: رابط halwa_classic لا يشير إلى /packages/?plan= (لم يعد المسار الرئيسي للشراء)', $by_key['halwa_classic']['url'], '/packages/?plan=');

// C — halwa_premium: مستويان بمنتجَين مختلفَين فعلياً → لا التباس مقبول
// → الرابط الداخلي كما كان
check_true('C: halwa_premium (منتجان مختلفان فعلياً) → is_direct_purchase = false (لا تخمين)', $by_key['halwa_premium']['is_direct_purchase'] === false);
check_contains('C: halwa_premium → يبقى على الرابط الداخلي /packages/?plan=halwa_premium', $by_key['halwa_premium']['url'], '/packages/?plan=halwa_premium');

// ═══════════════════════════════════════════════════════════════════════
// القسم 1 — E2E-02 FIX PASS 4: منتج سلة واحد مشترك بين عدة Tiers (القسم 6)
// ═══════════════════════════════════════════════════════════════════════

// B: ثلاثة Tiers نشطة بنفس رابط المنتج ونفس معرّف المنتج، SKU مختلف لكل
// مستوى → منتج واحد لا لبس فيه → شراء مباشر لذلك الرابط المشترك (يثبت
// أيضاً إزالة التكرار (Dedup) قبل الحكم بالالتباس — D).
check_true('B/D: halwa_shared_product (3 Tiers، نفس الرابط ونفس معرّف المنتج) → is_direct_purchase = true', $by_key['halwa_shared_product']['is_direct_purchase'] === true);
check('B/D: halwa_shared_product → رابط CTA = رابط المنتج المشترك نفسه', $by_key['halwa_shared_product']['url'], 'https://hilwah.net/salla/p/900');
check_not_contains('B/D: halwa_shared_product → لا يشير إلى /packages/?plan= (شراء مباشر فعلاً)', $by_key['halwa_shared_product']['url'], '/packages/?plan=');

// E: Tier نشط واحد قابل للشراء + Tier معطّل برابط/معرّف مختلفَين تماماً
// → لا يجوز أن يُدخِل الـTier المعطّل أي التباس زائف
check_true('E: halwa_inactive_noise (Tier نشط واحد + Tier معطّل بمنتج مختلف) → is_direct_purchase = true (المعطّل مُستبعَد قبل الحكم)', $by_key['halwa_inactive_noise']['is_direct_purchase'] === true);
check('E: halwa_inactive_noise → رابط CTA = رابط الـTier النشط الوحيد فقط', $by_key['halwa_inactive_noise']['url'], 'https://hilwah.net/salla/p/1000');

// K (تغطية إضافية، القسم 3): نفس الرابط تماماً، لكن معرّفَي منتج مختلفَين
// (تعارض بيانات حقيقي) → فشل آمن، لا شراء مباشر تخميني
check_true('K: halwa_url_id_mismatch (نفس الرابط، معرّفا منتج مختلفان) → is_direct_purchase = false (فشل آمن عند تعارض البيانات)', $by_key['halwa_url_id_mismatch']['is_direct_purchase'] === false);
check_contains('K: halwa_url_id_mismatch → يبقى على الرابط الداخلي', $by_key['halwa_url_id_mismatch']['url'], '/packages/?plan=halwa_url_id_mismatch');

// ═══════════════════════════════════════════════════════════════════════
// القسم 1 — E2E-02 FIX PASS 3: عقد السعر (القسم 8، A-E من الطلب)
// ═══════════════════════════════════════════════════════════════════════

// السعر (A): Tier نشط واحد → يُعرَض سعره الفعلي بلا بادئة "ابتداءً من"
check('السعر A: halwa_classic (Tier واحد) → price_text = السعر الفعلي "500"', $by_key['halwa_classic']['price_text'], '500');
check_true('السعر A: halwa_classic (Tier واحد) → has_starting_price = false (لا بادئة "ابتداءً من" لسعر غير ملتبس)', $by_key['halwa_classic']['has_starting_price'] === false);
check('السعر E: halwa_classic → العملة المعروضة من بيانات الـTier نفسه (SAR)', $by_key['halwa_classic']['currency'], 'SAR');

// السعر (B): عدة Tiers نشطة → أقل سعر منها + بادئة "ابتداءً من"
check('السعر B: halwa_premium (Tier‑ان: 800/1200) → price_text = أقل سعر "800"', $by_key['halwa_premium']['price_text'], '800');
check_true('السعر B: halwa_premium (Tier‑ان) → has_starting_price = true (بادئة "ابتداءً من" لسعر ملتبس فعلاً)', $by_key['halwa_premium']['has_starting_price'] === true);

// السعر (C): لا يوجد أي Tier نشط له سعر (الوحيد معطّل) → لا "0"، بل نص تواصل
check('السعر C: halwa_no_active_tier (Tier الوحيد معطّل) → price_text = "تواصل معنا" (ليس 0)', $by_key['halwa_no_active_tier']['price_text'], 'تواصل معنا');
check('السعر C: halwa_no_active_tier → لا عملة تُعرَض بلا سعر حقيقي', $by_key['halwa_no_active_tier']['currency'], '');
check_true('السعر C: halwa_no_active_tier → has_starting_price = false', $by_key['halwa_no_active_tier']['has_starting_price'] === false);

// السعر (D): صفر يظهر فقط عندما يكون هو فعلاً سعر الـTier النشط المُخزَّن
check('السعر D: halwa_genuine_zero (Tier نشط واحد بسعر 0 حقيقي) → price_text = "0" (القيمة الحقيقية، لا عطل قراءة)', $by_key['halwa_genuine_zero']['price_text'], '0');
check('السعر D: halwa_genuine_zero → العملة تُعرَض رغم أن السعر صفر (SAR)', $by_key['halwa_genuine_zero']['currency'], 'SAR');
check_true('السعر D: halwa_genuine_zero (Tier واحد) → has_starting_price = false (سعر واحد غير ملتبس، ولو كان صفراً)', $by_key['halwa_genuine_zero']['has_starting_price'] === false);

// D — halwa_no_mapping: بلا ربط Salla → فشل آمن (Fail Closed)
check_true('D: halwa_no_mapping (بلا ربط) → is_direct_purchase = false', $by_key['halwa_no_mapping']['is_direct_purchase'] === false);
check_contains('D: halwa_no_mapping → يبقى على الرابط الداخلي', $by_key['halwa_no_mapping']['url'], '/packages/?plan=halwa_no_mapping');

// E — halwa_inactive_tier: Tier معطّل رغم بيانات Salla ظاهرياً صالحة
check_true('E: halwa_inactive_tier (Tier معطّل) → is_direct_purchase = false (باقة غير متاحة للشراء لا تنتج CTA شراء)', $by_key['halwa_inactive_tier']['is_direct_purchase'] === false);
check_contains('E: halwa_inactive_tier → يبقى على الرابط الداخلي', $by_key['halwa_inactive_tier']['url'], '/packages/?plan=halwa_inactive_tier');

// F — halwa_insecure_url: رابط http غير آمن → يُرفَض أمنياً
check_true('F: halwa_insecure_url (رابط http) → is_direct_purchase = false (رُفض أمنياً وليس http)', $by_key['halwa_insecure_url']['is_direct_purchase'] === false);
check_contains('F: halwa_insecure_url → يبقى على الرابط الداخلي، لا رابط http مطلقاً', $by_key['halwa_insecure_url']['url'], '/packages/?plan=halwa_insecure_url');

// halwa_no_product_id: رابط صالح لكن لا معرّف منتج
check_true('halwa_no_product_id (بلا salla_product_id) → is_direct_purchase = false', $by_key['halwa_no_product_id']['is_direct_purchase'] === false);

// ═══════════════════════════════════════════════════════════════════════
// القسم 1 — اختبار وحدة حقيقي على render() الكامل (HTML الناتج فعلياً)
// ═══════════════════════════════════════════════════════════════════════

$widget->test_settings = [
    'data_source'    => 'catalog',
    'show_heading'   => 'no',
    'featured_plan'  => 'plan_none',
    'max_features'   => 7,
    'fallback_url'   => ['url' => ''],
    'button_text'    => 'اختر هذه الباقة',
    'featured_badge' => 'الأكثر طلبا',
];

ob_start();
call_private($widget, $ref, 'render');
$html = ob_get_clean();

// E2E-02 FIX PASS 3: يعزل بطاقة باقة واحدة (من عنوانها H3 حتى </article>
// التالية) لإثبات ظهور/غياب بادئة "ابتداءً من" ضمن تلك البطاقة تحديداً، لا
// ضمن HTML الصفحة كاملة (حيث قد تحوي بطاقة أخرى البادئة فعلاً وبشكل صحيح).
function extract_card_html($html, $title)
{
    $start = strpos($html, '>' . $title . '</h3>');
    if ($start === false) {
        return '';
    }
    $end = strpos($html, '</article>', $start);
    if ($end === false) {
        return '';
    }
    return substr($html, $start, $end - $start);
}

$card_classic = extract_card_html($html, 'حلوة كلاسيك');
$card_premium = extract_card_html($html, 'حلوة بريميوم');
check_true('السعر A (render): بطاقة halwa_classic (Tier واحد) موجودة فعلاً في الناتج', $card_classic !== '');
check_not_contains('السعر A (render): بطاقة halwa_classic لا تحوي بادئة "ابتداءً من" (سعر واحد غير ملتبس)', $card_classic, 'ابتداءً من');
check_contains('السعر A (render): بطاقة halwa_classic تعرض السعر الفعلي "500"', $card_classic, '500');
check_true('السعر B (render): بطاقة halwa_premium (Tier‑ان) موجودة فعلاً في الناتج', $card_premium !== '');
check_contains('السعر B (render): بطاقة halwa_premium تحوي بادئة "ابتداءً من" (سعر ملتبس فعلاً)', $card_premium, 'ابتداءً من');
check_contains('السعر B (render): بطاقة halwa_premium تعرض أقل سعر "800"', $card_premium, '800');

// E2E-02 FIX PASS 4 (render): بطاقة halwa_shared_product (منتج مشترك بين
// 3 Tiers) يجب أن تحوي href لرابط المنتج المشترك ونص الزر "اشترِ الآن"
$card_shared = extract_card_html($html, 'حلوة منتج مشترك');
check_true('B/D (render): بطاقة halwa_shared_product موجودة فعلاً في الناتج', $card_shared !== '');
check_contains('B/D (render): بطاقة halwa_shared_product تحوي href لرابط المنتج المشترك', $card_shared, 'href="https://hilwah.net/salla/p/900"');
check_contains('B/D (render): بطاقة halwa_shared_product تستخدم نص الزر "اشترِ الآن"', $card_shared, 'اشترِ الآن');
check_not_contains('B/D (render): بطاقة halwa_shared_product لا تشير إلى /packages/?plan= إطلاقاً', $card_shared, '/packages/?plan=');

check_contains('render(): بطاقة halwa_classic (شراء مباشر) تحوي href لرابط Salla الحقيقي', $html, 'href="https://hilwah.net/salla/p/100"');
check_contains('render(): بطاقة halwa_classic تستخدم نص الزر "اشترِ الآن"', $html, 'اشترِ الآن');
check_contains('render(): بطاقة halwa_premium (تفاصيل داخلية) تحوي نص الزر "عرض التفاصيل"', $html, 'عرض التفاصيل');
check_contains('render(): بطاقة halwa_premium تحوي رابط /packages/?plan=halwa_premium الداخلي', $html, '/packages/?plan=halwa_premium');
check_not_contains('render(): لا رابط http:// (غير آمن) في الناتج كاملاً', $html, 'href="http://');

// G — التأكد أن آلية الرابط الداخلي القديمة نفسها ما زالت تُنتَج فعلياً
// (لم تُحذَف add_query_arg نهائياً من الكود) لأي باقة تحتاجها.
check_contains('G: الرابط الداخلي /packages/?plan= ما زال يُنتَج فعلياً لباقات لا تملك شراءً مباشراً', $html, '/packages/');

// F — لا بيانات حساسة داخل أي رابط CTA في الناتج بالكامل
$sensitive_markers = ['nonce', 'user_id', 'password', 'auth_cookie', 'wp-login', '_wpnonce'];
foreach ($sensitive_markers as $marker) {
    check_not_contains("F: لا إشارة لـ\"$marker\" في ناتج render() بالكامل (لا بيانات حساسة في أي رابط)", $html, $marker);
}

// ═══════════════════════════════════════════════════════════════════════
// القسم 1 — الوضع اليدوي (manual/legacy) يبقى بلا أي تغيير — J
// ═══════════════════════════════════════════════════════════════════════

$manual_settings = [
    'featured_plan' => 'plan_2',
    'max_features'  => 7,
];
$manual_packages = call_private($widget, $ref, 'build_manual_packages', [$manual_settings]);
check_true('J: build_manual_packages() ما زالت تُعيد 4 باقات ثابتة كما كان تماماً', count($manual_packages) === 4);
foreach ($manual_packages as $mp) {
    check_true('J: باقات الوضع اليدوي لا تحمل مفتاح is_direct_purchase إطلاقاً (سلوك القسم الجديد خاص بالكتالوج فقط)', !array_key_exists('is_direct_purchase', $mp));
}

// ═══════════════════════════════════════════════════════════════════════
// القسم 2 — فحص نصي/حدودي (Source/Boundary Scan)
// ═══════════════════════════════════════════════════════════════════════

$http_functions = ['wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'curl_exec', 'curl_init', 'file_get_contents(\'https://api.salla'];
foreach ($http_functions as $fn) {
    check('H: لا استدعاء لـ"' . $fn . '" داخل ملف الـWidget (صفحة التصفح لا تتصل بـSalla API إطلاقاً)', substr_count($widget_src, $fn), 0);
}

$webhook_markers = ['handle_salla_notification', 'process_order_packages', 'activate_user_package', 'verify_signature', 'process_catalog_match', 'classify_order_items'];
foreach ($webhook_markers as $marker) {
    check('I: لا إشارة لمنطق الـWebhook ("' . $marker . '") داخل ملف الـWidget — لم يُعدَّل أي شيء متعلق بمعالجة الـWebhook', substr_count($widget_src, $marker), 0);
}

check_contains('القسم الجديد get_https_purchase_url() موجود فعلاً في الملف الحقيقي', $widget_src, 'private function get_https_purchase_url($url)');
check_contains('القسم الجديد is_direct_purchase موجود فعلاً في build_catalog_packages()', $widget_src, "'is_direct_purchase'");

echo "\n============================================\n";
echo "الإجمالي: $total | ناجح: $passed | فاشل: " . count($failures) . "\n";
if (count($failures) > 0) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "كل الحالات ناجحة.\n";
exit(0);

}
