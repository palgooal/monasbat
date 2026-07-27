<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس أسلوب tests/test-feature-resolver.php
 * وtests/test-catalog-tier-events-count.php) لـ Phase 4 — Commit 3: Snapshot Tests.
 *
 * النطاق: الدوال الثلاث الجديدة المضافة في Commit 1 داخل Mon_Events_Users
 * (build_tier_features_snapshot()، interpret_feature_value_for_snapshot()،
 * get_next_package_feature_version()، جميعها private static — تُختبَر عبر
 * ReflectionMethod::setAccessible(true) دون أي تعديل على Visibility في
 * الإنتاج ودون إضافة Public Wrapper)، وتكامل Commit 2 داخل
 * activate_catalog_tier()/deactivate_catalog_tier() الحقيقيتين.
 *
 * يحمّل هذا الملف الكلاسات الحقيقية التالية دون أي تعديل عليها:
 *   - includes/class-pge-catalog.php
 *   - includes/class-pge-feature-registry.php
 *   - includes/class-pge-tier-features.php
 *   - includes/feature-resolver.php
 *   - includes/class-mon-events-users.php
 * عبر Fake $wpdb صغير في الذاكرة (نفس فلسفة tests/test-catalog-tier-events-count.php:
 * بديل كافٍ فقط لأشكال الاستعلامات الفعلية الصادرة عن هذه الملفات — SELECT/
 * INSERT/UPDATE بشروط WHERE بسيطة من نوع المساواة — لا محرّك SQL عام، ولا
 * خادم MySQL فعلي (غير متاح في هذه البيئة)).
 *
 * لا PGE_Packages (Legacy) في هذا الملف عمداً — كل السيناريوهات هنا تخص
 * مستخدمي Catalog حصراً (المصدر الوحيد الذي تكتب له Phase 4 أي Snapshot،
 * راجع docs/FEATURES-PHASE-4-SPEC.md §9 "Legacy User")، فلا مسار كود يصل
 * فعلياً إلى PGE_Packages ضمن أي سيناريو مُختبَر هنا.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-feature-snapshot.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 *
 * ⚠️ قيد بيئي: لا يوجد PHP CLI فعلي في بيئة التطوير الحالية لهذا المشروع
 * (موثَّق ومتكرر في هذا المشروع). التحقق هنا اقتصر على: (أ) فحص AST بنيوي
 * عبر أداة parser خارجية — ليس بديلاً عن تشغيل حقيقي، و(ب) تتبّع يدوي كامل
 * لكل سيناريو أدناه على الكود الفعلي. لا ادّعاء بأن هذا الملف "عمل بنجاح"
 * عبر تشغيل PHP CLI فعلي.
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب لتحميل الملفات الحقيقية) ─────

define('ABSPATH', __DIR__ . '/');

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) {
    function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
}
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_strip_all_tags')) {
    // مطلوبة فعلياً بواسطة PGE_Catalog::normalize_tier_name() (تُستدعى من
    // create_tier() لكل Tier نُنشئه في هذا الملف) — بلا هذا الـstub يفشل
    // التحميل بخطأ Fatal "Call to undefined function". تطبيق مبسّط لكن مطابق
    // سلوكياً لمدخلاتنا هنا (نصوص عربية/لاتينية بلا أي وسم HTML).
    function wp_strip_all_tags($text, $remove_breaks = false) {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags($text);
        if ($remove_breaks) {
            $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
        }
        return trim($text);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $code;
        public $message;
        public function __construct($code = '', $message = '', $data = null)
        {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}

// ── User Meta وهمي في الذاكرة، مع حقن فشل كتابة قابل للتحكّم (Section G) ────

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_users_by_id'] = [];
$GLOBALS['__test_force_update_user_meta_failure'] = [];

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}

/**
 * محاكاة صادقة لفشل كتابة فعلي: عند تفعيل العلم لمفتاح مستخدم محدَّد، لا
 * تُخزَّن القيمة الجديدة إطلاقاً وتُعاد false — بحيث تفشل أيضاً محاولة
 * إعادة التحقق اللاحقة داخل update_user_meta_safely() (القراءة بعد الكتابة
 * لا تجد القيمة الجديدة)، فلا ينتج False Failure زائف بل فشل حقيقي متسق
 * تماماً كما لو فشل $wpdb فعلياً. هذا حقن ضمن Stub الاختبار وحده — لا تعديل
 * على أي كود إنتاج، بنفس آلية إعادة تعريف دوال ووردبريس المُتَّبعة أصلاً في
 * كل ملفات tests/ القائمة في هذا المشروع.
 */
function update_user_meta($user_id, $key, $value)
{
    $flag_key = $user_id . '|' . $key;
    if (!empty($GLOBALS['__test_force_update_user_meta_failure'][$flag_key])) {
        return false;
    }
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
    return true;
}

function delete_user_meta($user_id, $key)
{
    unset($GLOBALS['__test_user_meta'][$user_id][$key]);
    return true;
}

function metadata_exists($type, $object_id, $meta_key)
{
    $value = $GLOBALS['__test_user_meta'][$object_id][$meta_key] ?? '';
    return $value !== '';
}

function set_test_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
}

function reset_test_user($user_id)
{
    $GLOBALS['__test_user_meta'][$user_id] = [];
    $GLOBALS['__test_users_by_id'][$user_id] = true;
}

function set_test_force_update_user_meta_failure($user_id, $key, $force = true)
{
    $GLOBALS['__test_force_update_user_meta_failure'][$user_id . '|' . $key] = $force;
}

function clear_test_force_update_user_meta_failure($user_id, $key)
{
    unset($GLOBALS['__test_force_update_user_meta_failure'][$user_id . '|' . $key]);
}

function get_user_by($field, $value)
{
    if ($field === 'id') {
        return !empty($GLOBALS['__test_users_by_id'][$value]) ? (object) ['ID' => (int) $value] : false;
    }
    return false;
}

// ── Fake $wpdb: يخدم mon_plans + mon_plan_tiers (PGE_Catalog) وmon_tier_features
// (PGE_Tier_Features) معاً، بنفس فلسفة Fake_Wpdb في
// tests/test-catalog-tier-events-count.php (مطابقة WHERE من نوع "field = value"
// مفصولة بـAND فقط، لا JOIN ولا LIKE) — بديل كافٍ فقط لأشكال الاستعلامات
// الفعلية الصادرة عن الملفات الثلاثة، لا محرّك SQL عام.

class Fake_Wpdb_Snapshot
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $plans = [];
    public $tiers = [];
    public $tier_features = [];

    private $plans_next_id = 1;
    private $tiers_next_id = 1;
    private $tier_features_next_id = 1;

    /** @var int|null إن ضُبِط، أي استعلام SELECT على mon_tier_features بهذا tier_id يُعيد null (فشل استعلام فعلي) بدل الصفوف الحقيقية — يحاكي DEC-002 false. */
    public $force_tier_features_failure_for_tier_id = null;

    /** عدّاد استعلامات mon_tier_features — يُستخدَم للتحقق من عدم تكرار القراءة (Section F، لا Repository read جديد عند Early Return). */
    public $tier_features_query_count = 0;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? '';
            $i++;
            return $m[0] === '%d' ? (string) (int) $val : "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    private function which_table($sql_or_table)
    {
        if (strpos($sql_or_table, $this->prefix . 'mon_tier_features') !== false) {
            return 'tier_features';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plan_tiers') !== false) {
            return 'tiers';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plans') !== false) {
            return 'plans';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $rows = $this->get_results($sql, $output);
        if ($rows === null) {
            return null;
        }
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which === null) {
            return [];
        }

        if ($which === 'tier_features') {
            $this->tier_features_query_count++;

            if (
                $this->force_tier_features_failure_for_tier_id !== null
                && preg_match('/tier_id\s*=\s*(-?\d+)/', $sql, $tm)
                && (int) $tm[1] === (int) $this->force_tier_features_failure_for_tier_id
            ) {
                return null; // فشل استعلام فعلي — PGE_Tier_Features::get_all_tier_features() تُترجمه إلى false (DEC-002).
            }
        }

        $rows = array_values(
            $which === 'tiers' ? $this->tiers : ($which === 'plans' ? $this->plans : $this->tier_features)
        );

        if (preg_match('/WHERE\s+(.+)$/is', $sql, $m)) {
            $where = trim($m[1]);
            $conditions = preg_split('/\bAND\b/i', $where);
            foreach ($conditions as $cond) {
                $cond = trim($cond);
                if ($cond === '') {
                    continue;
                }
                if (preg_match("/^(\\w+)\\s*=\\s*'([^']*)'$/", $cond, $cm)) {
                    $field = $cm[1];
                    $value = $cm[2];
                } elseif (preg_match('/^(\\w+)\\s*=\\s*(-?\\d+)$/', $cond, $cm)) {
                    $field = $cm[1];
                    $value = $cm[2];
                } else {
                    continue;
                }
                $rows = array_values(array_filter($rows, function ($r) use ($field, $value) {
                    return array_key_exists($field, $r) && (string) $r[$field] === (string) $value;
                }));
            }
        }

        return $rows;
    }

    public function insert($table, $data, $format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }
        if ($which === 'tiers') {
            $id = $this->tiers_next_id++;
            $this->tiers[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'plans') {
            $id = $this->plans_next_id++;
            $this->plans[$id] = array_merge(['id' => $id], $data);
        } else {
            $id = $this->tier_features_next_id++;
            $this->tier_features[$id] = array_merge(['id' => $id], $data);
        }
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }
        $id = $where['id'] ?? null;
        if ($id === null) {
            return false;
        }
        $store = $which === 'tiers' ? 'tiers' : ($which === 'plans' ? 'plans' : 'tier_features');
        if (!isset($this->{$store}[$id])) {
            return 0;
        }
        foreach ($data as $k => $v) {
            $this->{$store}[$id][$k] = $v;
        }
        return 1;
    }

    // مساعدا اختبار: بذر صف Plan/Tier مباشرةً (يطابق seed_plan()/seed_tier()
    // في tests/test-catalog-tier-events-count.php).
    public function seed_plan($id, array $row)
    {
        $this->plans[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->plans_next_id) {
            $this->plans_next_id = $id + 1;
        }
    }

    public function seed_tier($id, array $row)
    {
        $this->tiers[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->tiers_next_id) {
            $this->tiers_next_id = $id + 1;
        }
    }

    // مساعد اختبار: بذر صف mon_tier_features مباشرةً، يحاكي ما يكتبه
    // PGE_Tier_Features::set_tier_feature_value() الحقيقي (خارج نطاق هذا
    // الاختبار — لا نستدعيه، Phase 4 لا تكتب على هذا الجدول إطلاقاً).
    public function seed_tier_feature($tier_id, $feature_key, $feature_value)
    {
        $id = $this->tier_features_next_id++;
        $this->tier_features[$id] = [
            'id' => $id,
            'tier_id' => $tier_id,
            'feature_key' => $feature_key,
            'feature_value' => $feature_value,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Snapshot();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────

require_once __DIR__ . '/../includes/class-pge-catalog.php';
require_once __DIR__ . '/../includes/class-pge-feature-registry.php';
require_once __DIR__ . '/../includes/class-pge-tier-features.php';
require_once __DIR__ . '/../includes/feature-resolver.php';
require_once __DIR__ . '/../includes/class-mon-events-users.php';

// ── أدوات الاختبار (نفس نمط check()/check_true() القائم فعلاً) ─────────────

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

// ── وصول للدوال الخاصة (private static) عبر Reflection فقط — بلا أي تغيير
// على Visibility أو إضافة Public Wrapper في includes/class-mon-events-users.php ─

$ref_build_snapshot = new ReflectionMethod('Mon_Events_Users', 'build_tier_features_snapshot');
$ref_build_snapshot->setAccessible(true);
function call_build_tier_features_snapshot($tier_id)
{
    global $ref_build_snapshot;
    return $ref_build_snapshot->invoke(null, $tier_id);
}

$ref_interpret = new ReflectionMethod('Mon_Events_Users', 'interpret_feature_value_for_snapshot');
$ref_interpret->setAccessible(true);
function call_interpret_feature_value_for_snapshot($type, $raw)
{
    global $ref_interpret;
    return $ref_interpret->invoke(null, $type, $raw);
}

$ref_next_version = new ReflectionMethod('Mon_Events_Users', 'get_next_package_feature_version');
$ref_next_version->setAccessible(true);
function call_get_next_package_feature_version($user_id)
{
    global $ref_next_version;
    return $ref_next_version->invoke(null, $user_id);
}

$registry_key_count = count(PGE_Feature_Registry::all());

// ── باقة أساسية مشتركة (mon_plans) لكل المستويات في هذا الملف ───────────────

$wpdb->seed_plan(1, [
    'plan_key' => 'basic_plan',
    'name'     => 'باقة أساسية',
    'plan_type' => 'personal',
    'status'   => 'active',
]);

/**
 * مساعد اختبار: ينشئ Tier حقيقياً عبر PGE_Catalog::create_tier() (لا Seed
 * مباشر — نطابق مسار الإنتاج الفعلي بالكامل لأن activate_catalog_tier()
 * يقرأ عبر PGE_Catalog::get_tier() الحقيقية). معرّف الصف (id) يتولّد تلقائياً
 * عبر create_tier()/$wpdb->insert_id — لا نمرّره هنا، ونعتمد حصراً على القيمة
 * المُعادة (['id' => ...]) في كل استدعاء لاحق.
 */
function make_test_tier($tier_key, $sort_order)
{
    global $wpdb;
    return PGE_Catalog::create_tier([
        'plan_id'          => 1,
        'tier_key'         => $tier_key,
        'name'             => 'مستوى اختبار ' . $tier_key,
        'price'            => 100,
        'currency'         => 'SAR',
        'salla_product_id' => null,
        'status'           => 'active',
        'sort_order'       => $sort_order,
    ]);
}

echo "=== قسم A: Snapshot Builder ===\n";

// A1. tier_id غير صالح
$result_zero = call_build_tier_features_snapshot(0);
check_true('A1. tier_id=0 → WP_Error', is_wp_error($result_zero));
check('A1. tier_id=0 → error code = invalid_tier_id', is_wp_error($result_zero) ? $result_zero->get_error_code() : null, 'invalid_tier_id');

$result_nonnumeric = call_build_tier_features_snapshot('abc');
check_true("A1. tier_id='abc' → WP_Error (absint('abc')=0)", is_wp_error($result_nonnumeric));

// A2. Repository يعيد false (فشل استعلام فعلي) — لا يُعامَل كمصفوفة فارغة
$tier_a2 = make_test_tier('a2_repo_failure', 1);
$wpdb->force_tier_features_failure_for_tier_id = $tier_a2['id'];
$result_a2 = call_build_tier_features_snapshot($tier_a2['id']);
check_true('A2. Repository false → WP_Error (لا Snapshot)', is_wp_error($result_a2));
check('A2. error code = tier_features_repository_failure', is_wp_error($result_a2) ? $result_a2->get_error_code() : null, 'tier_features_repository_failure');
$wpdb->force_tier_features_failure_for_tier_id = null; // إعادة الحالة الطبيعية لبقية الاختبارات

// A3. Repository يعيد [] — نجاح، كل القيم من Registry Defaults
$tier_a3 = make_test_tier('a3_empty_tier', 2);
$result_a3 = call_build_tier_features_snapshot($tier_a3['id']);
check_true('A3. Tier فارغ كلياً → array ناجحة (ليست WP_Error)', is_array($result_a3));
check('A3. عدد المفاتيح = عدد Registry الفعلي', count($result_a3), $registry_key_count);
check('A3. event_website (default=true) بلا صف Tier → true', $result_a3['event_website'] ?? null, true);
check('A3. gift_feature (default=false) بلا صف Tier → false', $result_a3['gift_feature'] ?? null, false);
check('A3. host_limit (default=TBD) بلا صف Tier → (int) 0', $result_a3['host_limit'] ?? null, 0);

// A4. Tier يحتوي جزءاً من المفاتيح فقط
$tier_a4 = make_test_tier('a4_partial_tier', 3);
$wpdb->seed_tier_feature($tier_a4['id'], 'gift_feature', '1');   // Tier: true (Registry default = false)
$wpdb->seed_tier_feature($tier_a4['id'], 'host_limit', '7');     // Tier: 7 (Registry default = TBD/0)
$result_a4 = call_build_tier_features_snapshot($tier_a4['id']);
check('A4. gift_feature من Tier (لا من Default) → true', $result_a4['gift_feature'] ?? null, true);
check('A4. host_limit من Tier (لا من Default) → 7', $result_a4['host_limit'] ?? null, 7);
check('A4. event_website (لا صف له) → Default true', $result_a4['event_website'] ?? null, true);
check('A4. عدد المفاتيح يبقى = عدد Registry الفعلي رغم صفين فقط', count($result_a4), $registry_key_count);

// A5. Tier يحتوي Orphan feature_key (غير موجود في Registry)
$tier_a5 = make_test_tier('a5_orphan_tier', 4);
$wpdb->seed_tier_feature($tier_a5['id'], 'totally_unknown_orphan_key', '1');
$wpdb->seed_tier_feature($tier_a5['id'], 'gift_feature', '1'); // صف شرعي أيضاً، للتأكد أن الصف اليتيم لا يُفسد البناء بأكمله
$result_a5 = call_build_tier_features_snapshot($tier_a5['id']);
check_true('A5. المفتاح اليتيم غير موجود في Snapshot', !array_key_exists('totally_unknown_orphan_key', $result_a5));
check('A5. عدد المفاتيح = عدد Registry الفعلي بالضبط (لا +1 بسبب اليتيم)', count($result_a5), $registry_key_count);
check('A5. الصف الشرعي المجاور (gift_feature) لم يتأثر بوجود اليتيم', $result_a5['gift_feature'] ?? null, true);

// A6. array_key_exists semantics — قيمة Tier صريحة "" (التمثيل الواقعي
// الوحيد لقيمة "فارغة" في عمود LONGTEXT حقيقي؛ PHP null/false غير قابلين
// للتخزين حرفياً في هذا العمود) يجب أن تُعامَل كـ"موجودة"، لا كـ"مفقودة".
// event_website: Registry default = true. لو استُخدِم isset() بدل
// array_key_exists() في أي مكان من مسار البحث، ستُعامَل "" كأنها غير
// موجودة وتؤول القيمة خطأً إلى Default (true) بدل تفسير "" الفعلي (false).
$tier_a6 = make_test_tier('a6_explicit_empty', 5);
$wpdb->seed_tier_feature($tier_a6['id'], 'event_website', '');
$result_a6 = call_build_tier_features_snapshot($tier_a6['id']);
check('A6. قيمة Tier صريحة "" لـevent_website → false (من Tier نفسه، لا Default=true)', $result_a6['event_website'] ?? null, false);

echo "\n=== قسم B: Value Interpretation (مطابقة حرفياً لقواعد Phase 3) ===\n";

// B7. Boolean true (نفس الأشكال الأربعة المعتمدة في §9/event-factory.php:333-344)
check_true("B7. Boolean('1') → true", call_interpret_feature_value_for_snapshot('boolean', '1') === true);
check_true("B7. Boolean('on') → true", call_interpret_feature_value_for_snapshot('boolean', 'on') === true);
check_true("B7. Boolean('yes') → true", call_interpret_feature_value_for_snapshot('boolean', 'yes') === true);
check_true("B7. Boolean('true') → true", call_interpret_feature_value_for_snapshot('boolean', 'true') === true);

// B8. Boolean false (عيّنة تمثيلية، لا تكرار للتغطية الاستقصائية الكاملة الموجودة أصلاً في tests/test-feature-resolver.php)
check_true("B8. Boolean('0') → false", call_interpret_feature_value_for_snapshot('boolean', '0') === false);
check_true("B8. Boolean('') → false", call_interpret_feature_value_for_snapshot('boolean', '') === false);
check_true("B8. Boolean('abc') → false", call_interpret_feature_value_for_snapshot('boolean', 'abc') === false);
check_true("B8. Boolean('false') → false (السلسلة الحرفية 'false' ليست في القائمة البيضاء)", call_interpret_feature_value_for_snapshot('boolean', 'false') === false);

// B9. Integer — (int) صريح بلا Clamp، يشمل السالب (Resolver لا يرفض أي نطاق، §9)
check('B9. Integer("5") → 5', call_interpret_feature_value_for_snapshot('integer', '5'), 5);
check('B9. Integer("-3") → -3 (لا رفض للسالب، مطابق §9)', call_interpret_feature_value_for_snapshot('integer', '-3'), -3);
check_true('B9. النتيجة من النوع int', is_int(call_interpret_feature_value_for_snapshot('integer', '5')));

// B10. Percentage — نفس آلية Integer تماماً (DEC-003)
check('B10. Percentage("75") → 75', call_interpret_feature_value_for_snapshot('percentage', '75'), 75);
check('B10. Percentage("150") → 150 (بلا Clamp لـ100، مطابق DEC-003)', call_interpret_feature_value_for_snapshot('percentage', '150'), 150);

// B11. TBD — (int) 'TBD' = 0
check("B11. Integer('TBD') → (int) 0", call_interpret_feature_value_for_snapshot('integer', 'TBD'), 0);

echo "\n=== قسم C: Complete Registry Snapshot ===\n";

$tier_c = make_test_tier('c_full_snapshot', 6);
$wpdb->seed_tier_feature($tier_c['id'], 'gift_feature', '1');
$result_c = call_build_tier_features_snapshot($tier_c['id']);

// C12. مطابقة المفاتيح كمجموعة بعد sort() — بلا اعتماد على ترتيب Array
$snapshot_keys_sorted = array_keys($result_c);
$registry_keys_sorted = array_keys(PGE_Feature_Registry::all());
sort($snapshot_keys_sorted);
sort($registry_keys_sorted);
check('C12. مفاتيح Snapshot = مفاتيح Registry كمجموعة (بمعزل عن الترتيب)', $snapshot_keys_sorted, $registry_keys_sorted);

// C13. كل قيمة scalar من النوع المتوقع — لا definition array يتسرّب
$all_scalar_and_typed = true;
foreach ($result_c as $key => $value) {
    if (!is_bool($value) && !is_int($value)) {
        $all_scalar_and_typed = false;
        break;
    }
}
check_true('C13. كل قيم Snapshot bool أو int فقط (لا Metadata/definition array)', $all_scalar_and_typed);

echo "\n=== قسم D: Version Helper ===\n";

// D14. لا Version سابقة
reset_test_user(9801);
check('D14. لا Version سابقة → 1', call_get_next_package_feature_version(9801), 1);

// D15. Version الحالية = 1
reset_test_user(9802);
set_test_user_meta(9802, '_mon_package_feature_version', 1);
check('D15. Version الحالية = 1 → 2', call_get_next_package_feature_version(9802), 2);

// D16. Version مخزَّنة كنص رقمي
reset_test_user(9803);
set_test_user_meta(9803, '_mon_package_feature_version', '7');
check("D16. Version مخزَّنة كنص '7' → absint()+1 = 8", call_get_next_package_feature_version(9803), 8);

// D17. Version مفقودة أو تالفة
reset_test_user(9804);
set_test_user_meta(9804, '_mon_package_feature_version', 'not-a-number');
check("D17. Version تالفة ('not-a-number') → absint()=0 → 1", call_get_next_package_feature_version(9804), 1);

// D18. Version Helper لا يكتب User Meta
reset_test_user(9805);
$meta_before = $GLOBALS['__test_user_meta'][9805] ?? [];
call_get_next_package_feature_version(9805);
$meta_after = $GLOBALS['__test_user_meta'][9805] ?? [];
check('D18. لا كتابة User Meta من داخل get_next_package_feature_version()', $meta_after, $meta_before);

echo "\n=== قسم E: Successful Integration ===\n";

// E19. أول activate_catalog_tier() ناجح
$tier_e1 = make_test_tier('e19_first_activation', 7);
reset_test_user(9210);
$result_e19 = Mon_Events_Users::activate_catalog_tier(9210, 1, $tier_e1['id']);
check_true('E19. activate_catalog_tier() الأول نجح (true)', $result_e19 === true);
$snapshot_e19 = get_user_meta(9210, '_mon_package_features', true);
check_true('E19. _mon_package_features مكتوبة (array)', is_array($snapshot_e19));
check('E19. عدد مفاتيحها = عدد Registry', count($snapshot_e19), $registry_key_count);
check('E19. _mon_package_feature_version = 1', absint(get_user_meta(9210, '_mon_package_feature_version', true)), 1);
check('E19. ثم Meta الحالية الخاصة بالتفعيل كُتبت أيضاً: _mon_package_source', get_user_meta(9210, '_mon_package_source', true), 'catalog');
check('E19. _mon_package_status = active', get_user_meta(9210, '_mon_package_status', true), 'active');
check('E19. _mon_catalog_tier_id = tier الجديد', absint(get_user_meta(9210, '_mon_catalog_tier_id', true)), $tier_e1['id']);

// E20. إعادة تفعيل فعلية لنفس Tier (order_id مختلف) — إعادة بناء + version+1
$result_e20 = Mon_Events_Users::activate_catalog_tier(9210, 1, $tier_e1['id'], 'ORDER-E20');
check_true('E20. إعادة التفعيل الفعلية نجحت', $result_e20 === true);
check('E20. Version يزداد بمقدار 1 بالضبط (1 → 2)', absint(get_user_meta(9210, '_mon_package_feature_version', true)), 2);

// E21. تفعيل Tier آخر لنفس المستخدم — Snapshot تعكس Tier الجديد، version+1
$tier_e2 = make_test_tier('e21_different_tier', 8);
$wpdb->seed_tier_feature($tier_e2['id'], 'gift_feature', '1'); // مختلف عن tier_e1 (بلا صف لـgift_feature، فيؤول لـDefault=false)
$result_e21 = Mon_Events_Users::activate_catalog_tier(9210, 1, $tier_e2['id'], 'ORDER-E21');
check_true('E21. تفعيل Tier آخر نجح', $result_e21 === true);
check('E21. Snapshot الجديدة تعكس Tier الجديد (gift_feature=true من tier_e2)', get_user_meta(9210, '_mon_package_features', true)['gift_feature'] ?? null, true);
check('E21. Version يزداد بمقدار 1 بالضبط (2 → 3)', absint(get_user_meta(9210, '_mon_package_feature_version', true)), 3);
check('E21. _mon_catalog_tier_id يعكس Tier الجديد', absint(get_user_meta(9210, '_mon_catalog_tier_id', true)), $tier_e2['id']);

echo "\n=== قسم F: Early Return ===\n";

$tier_f = make_test_tier('f_early_return', 9);
reset_test_user(9220);
Mon_Events_Users::activate_catalog_tier(9220, 1, $tier_f['id'], 'ORDER-F');
$snapshot_before_repeat = get_user_meta(9220, '_mon_package_features', true);
$version_before_repeat = absint(get_user_meta(9220, '_mon_package_feature_version', true));
$tier_features_query_count_before = $wpdb->tier_features_query_count;

// نفس المعطيات تماماً (source/status/plan_id/tier_id/order_id) — Early Return
$result_repeat = Mon_Events_Users::activate_catalog_tier(9220, 1, $tier_f['id'], 'ORDER-F');

check_true('F22. تفعيل مطابق تماماً يعيد true (Early Return)', $result_repeat === true);
check('F22. لا إعادة بناء لـSnapshot (نفس القيمة تماماً)', get_user_meta(9220, '_mon_package_features', true), $snapshot_before_repeat);
check('F22. لا زيادة في Version', absint(get_user_meta(9220, '_mon_package_feature_version', true)), $version_before_repeat);
check('F22. لا قراءة Repository جديدة لـmon_tier_features (العدّاد لم يتغيّر)', $wpdb->tier_features_query_count, $tier_features_query_count_before);

echo "\n=== قسم G: Failure Policy ===\n";

// G23. Builder Failure → activate_catalog_tier() يعيد WP_Error، صفر كتابة
$tier_g23 = make_test_tier('g23_builder_failure', 10);
$wpdb->force_tier_features_failure_for_tier_id = $tier_g23['id'];
reset_test_user(9230);
$result_g23 = Mon_Events_Users::activate_catalog_tier(9230, 1, $tier_g23['id']);
check_true('G23. Builder Failure → WP_Error', is_wp_error($result_g23));
check_true('G23. لا _mon_package_features مكتوبة', !metadata_exists('user', 9230, '_mon_package_features'));
check_true('G23. لا _mon_package_feature_version مكتوبة', !metadata_exists('user', 9230, '_mon_package_feature_version'));
check_true('G23. لا Meta الحلقة الحالية مكتوبة (_mon_package_source)', !metadata_exists('user', 9230, '_mon_package_source'));
$wpdb->force_tier_features_failure_for_tier_id = null;

// G24. Snapshot Write Failure → WP_Error، لا Version، لا الحلقة الحالية
$tier_g24 = make_test_tier('g24_snapshot_write_failure', 11);
reset_test_user(9231);
set_test_force_update_user_meta_failure(9231, '_mon_package_features', true);
$result_g24 = Mon_Events_Users::activate_catalog_tier(9231, 1, $tier_g24['id']);
check_true('G24. Snapshot Write Failure → WP_Error', is_wp_error($result_g24));
check_true('G24. _mon_package_features لم تُكتب فعلياً (الكتابة نفسها فشلت)', !metadata_exists('user', 9231, '_mon_package_features'));
check_true('G24. لا _mon_package_feature_version مكتوبة', !metadata_exists('user', 9231, '_mon_package_feature_version'));
check_true('G24. لا Meta الحلقة الحالية مكتوبة (_mon_package_source)', !metadata_exists('user', 9231, '_mon_package_source'));
clear_test_force_update_user_meta_failure(9231, '_mon_package_features');

// G25. Version Write Failure → WP_Error، Snapshot قد تبقى مكتوبة، Version القديمة تبقى
$tier_g25 = make_test_tier('g25_version_write_failure', 12);
reset_test_user(9232);
set_test_force_update_user_meta_failure(9232, '_mon_package_feature_version', true);
$result_g25 = Mon_Events_Users::activate_catalog_tier(9232, 1, $tier_g25['id']);
check_true('G25. Version Write Failure → WP_Error', is_wp_error($result_g25));
check_true('G25. _mon_package_features مكتوبة رغم فشل Version (لا Rollback)', metadata_exists('user', 9232, '_mon_package_features'));
check_true('G25. _mon_package_feature_version القديمة (غائبة) تبقى كما هي — لم تُكتب', !metadata_exists('user', 9232, '_mon_package_feature_version'));
check_true('G25. لا Meta الحلقة الحالية مكتوبة (_mon_package_source)', !metadata_exists('user', 9232, '_mon_package_source'));
clear_test_force_update_user_meta_failure(9232, '_mon_package_feature_version');

// G26. فشل لاحق داخل الحلقة الحالية بعد نجاح Snapshot وVersion — Partial State مقبولة، بلا Rollback
$tier_g26 = make_test_tier('g26_legacy_loop_failure', 13);
reset_test_user(9233);
set_test_force_update_user_meta_failure(9233, '_mon_salla_product_id', true); // مفتاح من الحلقة الحالية القديمة، بعد نقطة Snapshot/Version تماماً
$result_g26 = Mon_Events_Users::activate_catalog_tier(9233, 1, $tier_g26['id']);
check_true('G26. فشل لاحق في الحلقة الحالية → WP_Error', is_wp_error($result_g26));
check_true('G26. Snapshot الجديدة تبقى مكتوبة (لا Rollback)', metadata_exists('user', 9233, '_mon_package_features'));
check('G26. Version الجديدة تبقى مكتوبة = 1 (لا Rollback)', absint(get_user_meta(9233, '_mon_package_feature_version', true)), 1);
clear_test_force_update_user_meta_failure(9233, '_mon_salla_product_id');

echo "\n=== قسم H: Deactivation ===\n";

$tier_h = make_test_tier('h_deactivation', 14);
reset_test_user(9240);
Mon_Events_Users::activate_catalog_tier(9240, 1, $tier_h['id']);
$snapshot_before_deactivate = get_user_meta(9240, '_mon_package_features', true);
$version_before_deactivate = absint(get_user_meta(9240, '_mon_package_feature_version', true));

$deactivate_result = Mon_Events_Users::deactivate_catalog_tier(9240);

check_true('H27. deactivate_catalog_tier() نجح', $deactivate_result === true);
check('H27. لا حذف لـ_mon_package_features (تبقى كما هي)', get_user_meta(9240, '_mon_package_features', true), $snapshot_before_deactivate);
check('H27. لا حذف لـ_mon_package_feature_version ولا زيادة (تبقى كما هي)', absint(get_user_meta(9240, '_mon_package_feature_version', true)), $version_before_deactivate);
check('H27. _mon_package_status أصبحت expired', get_user_meta(9240, '_mon_package_status', true), 'expired');

echo "\n=== قسم I: Frozen Snapshot Behavior (يثبت منع Tier Fallback الحيّ) ===\n";

$tier_i = make_test_tier('i_frozen_snapshot', 15);
$wpdb->seed_tier_feature($tier_i['id'], 'gift_feature', '0'); // القيمة وقت التفعيل: false
reset_test_user(9250);
Mon_Events_Users::activate_catalog_tier(9250, 1, $tier_i['id']);

check_true('I28. قبل أي تعديل: pge_user_has_feature(gift_feature) = false (من Snapshot)', pge_user_has_feature(9250, 'gift_feature') === false);

// تعديل مباشر على mon_tier_features (محاكاة تعديل إداري) بلا استدعاء تفعيل جديد
foreach ($wpdb->tier_features as $row_id => $row) {
    if ((int) $row['tier_id'] === (int) $tier_i['id'] && $row['feature_key'] === 'gift_feature') {
        $wpdb->tier_features[$row_id]['feature_value'] = '1'; // الآن true على مستوى Tier الحيّ
    }
}

check_true('I28. بعد تعديل Tier الحيّ بلا إعادة تفعيل: Resolver يستمر بإرجاع القيمة القديمة المُجمَّدة (false)', pge_user_has_feature(9250, 'gift_feature') === false);
check('I28. _mon_package_feature_version لم يتغيّر (لا إعادة بناء)', absint(get_user_meta(9250, '_mon_package_feature_version', true)), 1);

// إعادة تفعيل فعلية الآن (order_id مختلف) — يجب أن تلتقط القيمة الجديدة
Mon_Events_Users::activate_catalog_tier(9250, 1, $tier_i['id'], 'ORDER-I29');

check_true('I29. بعد إعادة تفعيل فعلية: Resolver يعكس القيمة الجديدة (true)', pge_user_has_feature(9250, 'gift_feature') === true);
check('I29. Version يزداد بعد إعادة التفعيل الفعلية (1 → 2)', absint(get_user_meta(9250, '_mon_package_feature_version', true)), 2);

// ── الملخص النهائي ───────────────────────────────────────────────────────

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
exit(0);
