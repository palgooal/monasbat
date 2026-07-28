<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit وبلا أي بنية اختبارات جديدة) لمهمة
 * "تصحيح محدود لنظام Catalog بحيث تصبح كل عملية شراء = مناسبة واحدة".
 *
 * يختلف هذا الملف عن tests/test-catalog-plan-limits.php في نقطة جوهرية:
 * ذلك الملف يستبدل PGE_Catalog بالكامل بكلاس وهمي صغير (لأن الدوال التي
 * يختبرها — pge_get_user_plan_limits_for_events() وما حولها — تستخدم فقط
 * PGE_Catalog::get_tier()، قراءة بلا أي منطق). هذه المهمة على العكس تختبر
 * منطق CRUD الحقيقي نفسه (create_tier/update_tier) ومنطق الترحيل الحقيقي
 * (Mon_Catalog_Schema::upgrade_to_1_3_0())، وكلاهما يستخدم $wpdb فعلياً —
 * لذا لا يمكن استخدام نفس الكلاس الوهمي البديل (سيُلغي الغرض من الاختبار
 * تماماً)، ولا يمكن تحميل هذا الملف مع ذاك في نفس العملية (تعارض إعادة
 * تعريف class PGE_Catalog). الحل: ملف مستقل يحمّل includes/class-pge-catalog.php
 * وincludes/class-mon-catalog-schema.php الحقيقيين دون أي تعديل عليهما، مع
 * كلاس Fake_Wpdb صغير في الذاكرة يحاكي فقط أشكال الاستعلامات الفعلية التي
 * تصدرها هاتان الدالتان (SELECT/INSERT/UPDATE بشروط WHERE بسيطة من نوع
 * المساواة، بلا JOIN وبلا LIKE) — ليس محرّك SQL عاماً، فقط بديل كافٍ لتشغيل
 * المسارات الحقيقية دون خادم MySQL فعلي (غير متاح في هذه البيئة).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل:
 *   php tests/test-catalog-tier-events-count.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب لتحميل الملفين الحقيقيين) ────

define('ABSPATH', __DIR__ . '/');

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op: لا تفعيل حقيقي هنا */ }

if (!function_exists('sanitize_key')) {
    function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($v) { return trim(strip_tags((string) $v)); }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url, $protocols = null) { return $url; }
}

// ── Stubs إضافية لتحميل includes/class-mon-events-users.php الحقيقي (قسم
// Snapshot) — نفس الأسلوب المستخدم في tests/test-catalog-plan-limits.php
// لكن بمخزن ذاكرة منفصل خاص بهذا الملف، لأن هذا ملف عملية PHP مستقل تماماً.

if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }
}

$GLOBALS['__test_user_meta']       = [];
$GLOBALS['__test_users_by_id']     = [];
$GLOBALS['__test_users_by_email']  = [];
$GLOBALS['__test_options']         = [];

function get_option($name, $default = false)
{
    return $GLOBALS['__test_options'][$name] ?? $default;
}

function set_test_user_email($email, $user_id)
{
    $GLOBALS['__test_users_by_email'][$email] = $user_id;
    $GLOBALS['__test_users_by_id'][$user_id] = true;
}

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

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}

function update_user_meta($user_id, $key, $value)
{
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
}

function set_test_user_id($user_id)
{
    $GLOBALS['__test_users_by_id'][$user_id] = true;
}

function get_user_by($field, $value)
{
    if ($field === 'id') {
        return !empty($GLOBALS['__test_users_by_id'][$value]) ? (object) ['ID' => (int) $value] : false;
    }
    if ($field === 'email') {
        $id = $GLOBALS['__test_users_by_email'][$value] ?? null;
        return $id === null ? false : (object) ['ID' => (int) $id];
    }
    return false;
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
if (!function_exists('is_wp_error')) {
    // Stub ناقص سابقاً في هذا الملف — أُضيف الآن فقط لأن activate_catalog_tier()
    // الحقيقية (منذ Phase 4 Commit 2: Feature Snapshot) تستدعيه فعلياً عبر
    // is_wp_error($feature_snapshot) قبل أي كتابة Meta. إضافة بحتة بلا أي أثر
    // على أي حالة/تأكيد موجود في هذا الملف — لا تُغيّر أي سلوك مُختبَر، فقط
    // تُتيح التنفيذ الفعلي لهذا الملف من الأساس (كان يفشل بـ Fatal Error قبلها).
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}

// ── Fake $wpdb — بديل كافٍ فقط لأشكال الاستعلامات الفعلية في هذا الملف ────
// (راجع توثيق أعلى الملف لسبب عدم استخدام محرّك SQL عام).

class Fake_Wpdb
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    /** @var array<int, array> */
    public $plans = [];
    /** @var array<int, array> */
    public $tiers = [];

    /** @var array<int, string>|null تجاوز اختياري لأعمدة SHOW COLUMNS (لمحاكاة بنية ناقصة في الاختبار) */
    public $show_columns_override = null;

    private $plans_next_id = 1;
    private $tiers_next_id = 1;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? '';
            $i++;
            if ($m[0] === '%d') {
                return (string) (int) $val;
            }
            return "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    public function get_charset_collate()
    {
        return '';
    }

    private function which_table($sql_or_table)
    {
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
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which === null) {
            return [];
        }
        $rows = array_values($which === 'tiers' ? $this->tiers : $this->plans);

        // الحالة الخاصة الوحيدة التي تصدر عن upgrade_to_1_3_0(): شرط OR + IS NULL،
        // لا تدعمه مطابقة AND العامة أدناه، فتُعامَل بشكل صريح ومنفصل.
        if (strpos($sql, 'events_count IS NULL OR events_count = 0') !== false) {
            return array_values(array_filter($rows, function ($r) {
                return !array_key_exists('events_count', $r)
                    || $r['events_count'] === null
                    || (int) $r['events_count'] === 0;
            }));
        }

        // SHOW COLUMNS FROM <table> — تصدر عن upgrade_to_1_4_0() للتحقق من
        // إضافة العمودين الجديدين فعلياً. Fake_Wpdb لا يملك بنية أعمدة حقيقية
        // (تخزين مصفوفات مفتوحة، لا Schema صارم)، لذا نُعيد قائمة أعمدة ثابتة
        // تمثّل بنية mon_plan_tiers الفعلية بعد هذا الإصلاح — يمكن للاختبار
        // حذف عمود منها عمداً (عبر show_columns_override) لمحاكاة بنية ناقصة.
        if (stripos($sql, 'SHOW COLUMNS FROM') === 0) {
            $columns = $this->show_columns_override ?? [
                'id', 'plan_id', 'tier_key', 'name', 'guest_limit', 'events_count',
                'host_photos_limit', 'wa_messages_limit',
                'invitation_credit_limit', 'replacement_credit_limit',
                'price', 'currency', 'salla_product_id', 'salla_sku', 'salla_url',
                'sort_order', 'status', 'created_at', 'updated_at',
            ];
            $out = [];
            foreach ($columns as $field) {
                $out[] = ['Field' => $field];
            }
            return $out;
        }

        // مطابقة شروط WHERE من نوع "field = value" أو "field = 'value'" مفصولة بـ AND فقط.
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
        } else {
            $id = $this->plans_next_id++;
            $this->plans[$id] = array_merge(['id' => $id], $data);
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
        // ملاحظة إصلاح: لا يجوز أخذ مرجع (&) لنتيجة تعبير ثلاثي (ternary)
        // مباشرة في PHP الحقيقي — "$x = &($cond ? $a : $b);" خطأ صياغة
        // (Parse error)، رغم أن بعض محلّلات AST المتساهلة قد تقبله. الحل:
        // فرعان منفصلان صريحان بدل محاولة توحيدهما عبر مرجع لتعبير شرطي.
        if ($which === 'tiers') {
            if (!isset($this->tiers[$id])) {
                return 0;
            }
            foreach ($data as $k => $v) {
                $this->tiers[$id][$k] = $v;
            }
        } else {
            if (!isset($this->plans[$id])) {
                return 0;
            }
            foreach ($data as $k => $v) {
                $this->plans[$id][$k] = $v;
            }
        }
        return 1;
    }

    // مساعد اختبار فقط: بذر صف مباشرةً في الذاكرة (يتجاوز create_tier()
    // عمداً في حالات الترحيل، لمحاكاة صفوف قديمة أُنشئت قبل هذا الإصلاح).
    public function seed_tier($id, array $row)
    {
        $this->tiers[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->tiers_next_id) {
            $this->tiers_next_id = $id + 1;
        }
    }

    public function seed_plan($id, array $row)
    {
        $this->plans[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->plans_next_id) {
            $this->plans_next_id = $id + 1;
        }
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── تحميل الملفين الحقيقيين من المشروع (بلا أي تعديل عليهما) ───────────────

// class-mon-catalog-schema.php يستخدم PGE_PATH على مستوى الملف مباشرة (في
// register_activation_hook() بالسطر الأخير) — هذا الثابت يُعرَّف عادة في
// pgevents-core.php الرئيسي، وهو غير مُحمَّل هنا عمداً (تحميل كامل الإضافة
// غير مطلوب لهذا الاختبار). عرّفه هنا فقط إن لم يكن معرَّفاً مسبقاً، بمسار
// محسوب من __DIR__ (جذر مجلد tests) لا مسار ثابت خاص بجهاز: dirname(__DIR__)
// هو جذر مجلد الإضافة pgevents-core نفسه (أب مباشر لمجلد tests)، بنفس صيغة
// PGE_PATH الحقيقية المنتهية بفاصل مسار (يطابق شكل الاستخدام
// PGE_PATH . 'pgevents-core.php' في الملف الحقيقي).
if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

// ARRAY_A هو وضع الإرجاع الوحيد المستخدَم فعلياً في class-pge-catalog.php
// وclass-mon-catalog-schema.php (كل استدعاءات $wpdb->get_row()/get_results()
// في الملفين تمرّره صراحةً؛ لا وجود إطلاقاً لـOBJECT ولا ARRAY_N في أي منهما
// — تأكَّدتُ بالبحث الكامل في الملفين قبل الإضافة). ووردبريس تُعرِّفه عادة
// كـ wpdb::ARRAY_A = 'ARRAY_A' (قيمة نصية وليست رقماً)، وFake_Wpdb في هذا
// الملف أصلاً لا يفرّق بين أوضاع الإرجاع (يعيد Array ترابطي دائماً)، فقيمة
// الثابت غير مهمة عملياً هنا — التعريف مطلوب فقط لمنع Fatal عند الاستدعاء.
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../includes/class-pge-catalog.php';
require_once __DIR__ . '/../includes/class-mon-catalog-schema.php';
require_once __DIR__ . '/../includes/class-pge-feature-registry.php';
require_once __DIR__ . '/../includes/class-pge-tier-features.php';
require_once __DIR__ . '/../includes/class-mon-events-users.php';

// ── أدوات الاختبار (نفس نمط check()/check_true() في الملف الآخر) ──────────

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
        $actual_str = var_export($actual, true);
        $expected_str = var_export($expected, true);
        echo "FAIL  $label (expected $expected_str, got $actual_str)\n";
    }
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

// ── تجهيز باقة أساسية (mon_plans) تُستخدم كأب لكل المستويات في هذا الاختبار ─

$wpdb->seed_plan(1, [
    'plan_key' => 'basic_plan',
    'name'     => 'باقة أساسية',
    'plan_type' => 'personal',
    'status'   => 'active',
]);

echo "=== قسم 11: تصحيح events_count — كل عملية شراء = مناسبة واحدة ===\n";

// ── سيناريو 1: Tier جديدة بدون events_count → تصبح 1 ──────────────────────

$tier1 = PGE_Catalog::create_tier([
    'plan_id'          => 1,
    'tier_key'         => 'no_events_count',
    'name'             => 'مستوى بلا events_count',
    'price'            => 100,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 0,
    // events_count غائب عمداً — هذا هو صلب السيناريو 1
]);
check_true('1. create_tier() نجح دون events_count', $tier1 !== null);
check('1. Tier جديدة بدون events_count → events_count = 1', $tier1['events_count'] ?? null, 1);
check_true('1. events_count المُعادة من النوع int', is_int($tier1['events_count'] ?? null));

// ── سيناريو 4أ: Tier جديدة بقيمة events_count=5 صريحة → تبقى 5 (لا قفل على القيمة الافتراضية) ─

$tier5 = PGE_Catalog::create_tier([
    'plan_id'          => 1,
    'tier_key'         => 'explicit_five',
    'name'             => 'مستوى بخمس مناسبات',
    'price'            => 500,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 1,
    'events_count'     => 5,
]);
check_true('4أ. create_tier() نجح مع events_count=5', $tier5 !== null);
check('4أ. Tier جديدة مع events_count=5 صريحة → تبقى 5', $tier5['events_count'] ?? null, 5);

// ── تحديث (update_tier) بدون events_count → لا تغيير على القيمة الحالية ───

$updated_no_touch = PGE_Catalog::update_tier($tier5['id'], [
    'plan_id'          => 1,
    'tier_key'         => 'explicit_five',
    'price'            => 550,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 1,
    // events_count غائب عمداً — يجب ألا يُلمَس
]);
check_true('update_tier() نجح بدون events_count', $updated_no_touch !== null);
check('update_tier() بدون events_count لا يغيّر القيمة الحالية (تبقى 5)', $updated_no_touch['events_count'] ?? null, 5);

// ── تحديث events_count صراحةً لقيمة أخرى → المسؤول غير ممنوع من تغييرها ───

$updated_change = PGE_Catalog::update_tier($tier5['id'], [
    'plan_id'          => 1,
    'tier_key'         => 'explicit_five',
    'price'            => 550,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 1,
    'events_count'     => 3,
]);
check('update_tier() مع events_count=3 صراحة يغيّرها فعلاً إلى 3', $updated_change['events_count'] ?? null, 3);

// ── تحديث events_count=0 صراحةً → تصبح 1 (يمنع عودة الخلل حتى عبر إدخال يدوي خاطئ) ─

$updated_zero = PGE_Catalog::update_tier($tier5['id'], [
    'plan_id'          => 1,
    'tier_key'         => 'explicit_five',
    'price'            => 550,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 1,
    'events_count'     => 0,
]);
check('update_tier() مع events_count=0 صراحة يعيدها إلى 1', $updated_zero['events_count'] ?? null, 1);

// ── سيناريو 7: guest_limit لا يتغيّر بسبب أي من تعديلات events_count أعلاه ─
// (لم يُمرَّر guest_limit في أي نداء أعلاه؛ الحقل غير موجود أصلاً في create_tier/
// update_tier — نتحقق أنه لم يُضَف أو يُلمَس بأي شكل ضمن الصف الناتج).

check_true('7. guest_limit غير موجود ضمن مخرجات create_tier/update_tier (لم يُلمَس إطلاقاً)', !array_key_exists('guest_limit', $updated_zero) || $updated_zero['guest_limit'] === null);

// ── سيناريو 5: القراءة عبر PGE_Catalog::get_tier() — وهي نفس القراءة التي
// يعتمد عليها pge_get_catalog_user_plan_limits_for_events() (ومن ثم
// activate_catalog_tier() بشكل غير مباشر عبر _mon_catalog_tier_id، دون أي
// تعديل على أي منهما) — تعيد events_count=1 لمستوى أُنشئ حديثاً دون قيمة
// صريحة. هذا يثبت أن المسار الكامل من الشراء إلى القراءة يستفيد تلقائياً
// من الإصلاح دون أي حاجة لتعديل activate_catalog_tier() نفسها.

$fresh_for_activation = PGE_Catalog::create_tier([
    'plan_id'          => 1,
    'tier_key'         => 'purchase_flow_tier',
    'name'             => 'مستوى مسار الشراء',
    'price'            => 200,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 2,
]);
$read_back = PGE_Catalog::get_tier($fresh_for_activation['id']);
check('5. get_tier() بعد "الشراء" (نفس قراءة activate_catalog_tier()/الدالة المركزية) يعيد events_count=1', $read_back['events_count'] ?? null, 1);

// ── سيناريوهات 2 و3: الترحيل (Migration) لصفوف قديمة NULL/0، مع عدم لمس القيم > 0 ─
// بذر صفوف تحاكي مستويات أُنشئت قبل هذا الإصلاح (حين لم يكن أي مسار CRUD
// يكتب events_count إطلاقاً)، عبر seed_tier() مباشرة — يتجاوز create_tier()
// عمداً لأن create_tier() الحقيقي بعد هذا الإصلاح لم يعد يسمح بإنتاج NULL/0
// أصلاً؛ الهدف هنا محاكاة بيانات قديمة موجودة فعلياً في قاعدة الإنتاج.

$wpdb->seed_tier(201, ['plan_id' => 1, 'tier_key' => 'legacy_zero', 'name' => 'قديم صفر', 'events_count' => 0, 'guest_limit' => 100, 'status' => 'active', 'sort_order' => 0]);
$wpdb->seed_tier(202, ['plan_id' => 1, 'tier_key' => 'legacy_null', 'name' => 'قديم فارغ', 'events_count' => null, 'guest_limit' => 200, 'status' => 'active', 'sort_order' => 1]);
$wpdb->seed_tier(203, ['plan_id' => 1, 'tier_key' => 'legacy_five', 'name' => 'قديم خمسة', 'events_count' => 5, 'guest_limit' => 300, 'status' => 'active', 'sort_order' => 2]);

$migration_ref = new ReflectionMethod('Mon_Catalog_Schema', 'upgrade_to_1_3_0');
$migration_ref->setAccessible(true);
$migration_result_1 = $migration_ref->invoke(null);

check_true('الترحيل: upgrade_to_1_3_0() يعيد true عند النجاح', $migration_result_1 === true);
check('2. events_count=0 قبل الترحيل → 1 بعده', $wpdb->tiers[201]['events_count'], 1);
check('3. events_count=NULL قبل الترحيل → 1 بعده', $wpdb->tiers[202]['events_count'], 1);
check('4ج. events_count=5 قبل الترحيل → تبقى 5 بعده (لم تُلمَس)', $wpdb->tiers[203]['events_count'], 5);

// guest_limit يجب ألا يتغيّر بسبب الترحيل مطلقاً (سيناريو 7 من جانب الترحيل) ─

check('7. الترحيل لا يغيّر guest_limit لصف 201', $wpdb->tiers[201]['guest_limit'], 100);
check('7. الترحيل لا يغيّر guest_limit لصف 202', $wpdb->tiers[202]['guest_limit'], 200);
check('7. الترحيل لا يغيّر guest_limit لصف 203', $wpdb->tiers[203]['guest_limit'], 300);

// تكرار الترحيل — يجب أن يكون Idempotent تماماً (لا أثر إضافي) ────────────

$migration_result_2 = $migration_ref->invoke(null);
check_true('الترحيل (تكرار ثانٍ): يعيد true أيضاً', $migration_result_2 === true);
check('الترحيل (تكرار): events_count لصف 201 يبقى 1', $wpdb->tiers[201]['events_count'], 1);
check('الترحيل (تكرار): events_count لصف 202 يبقى 1', $wpdb->tiers[202]['events_count'], 1);
check('الترحيل (تكرار): events_count لصف 203 يبقى 5 (لم يُلمَس ثانيةً)', $wpdb->tiers[203]['events_count'], 5);

echo "\n=== قسم 12: Invitation Credits Engine — تأسيس البيانات فقط ===\n";

// ── Schema / Migration (1-3) ───────────────────────────────────────────────

$schema_sql_ref = new ReflectionMethod('Mon_Catalog_Schema', 'get_schema_sql');
$schema_sql_ref->setAccessible(true);
$schema_sql_parts = $schema_sql_ref->invoke(null);
$tiers_sql = $schema_sql_parts[1] ?? '';

check_true('1. عمود invitation_credit_limit موجود في نص Schema', strpos($tiers_sql, 'invitation_credit_limit') !== false);
check_true('1. عمود replacement_credit_limit موجود في نص Schema', strpos($tiers_sql, 'replacement_credit_limit') !== false);
check_true('2. invitation_credit_limit: DEFAULT 0 في نص Schema', strpos($tiers_sql, 'invitation_credit_limit INT UNSIGNED NOT NULL DEFAULT 0') !== false);
check_true('2. replacement_credit_limit: DEFAULT 0 في نص Schema', strpos($tiers_sql, 'replacement_credit_limit INT UNSIGNED NOT NULL DEFAULT 0') !== false);

$upgrade_140_ref = new ReflectionMethod('Mon_Catalog_Schema', 'upgrade_to_1_4_0');
$upgrade_140_ref->setAccessible(true);

$wpdb->show_columns_override = null; // القائمة الافتراضية الكاملة (تتضمن العمودين الجديدين)
check_true('3. upgrade_to_1_4_0() يعيد true عندما يكون العمودان موجودين فعلاً', $upgrade_140_ref->invoke(null) === true);

$wpdb->show_columns_override = ['id', 'plan_id', 'tier_key', 'name', 'events_count']; // بنية ناقصة عمداً
check_true('3. upgrade_to_1_4_0() يعيد false عند بنية ناقصة (تحقق حقيقي لا افتراض أعمى)', $upgrade_140_ref->invoke(null) === false);

$wpdb->show_columns_override = null; // إعادة الحالة الطبيعية لبقية الاختبارات
check_true('3. upgrade_to_1_4_0() Idempotent — تكرار الاستدعاء بعد اكتمال البنية يبقى true', $upgrade_140_ref->invoke(null) === true);

// ── CRUD (4-9) ──────────────────────────────────────────────────────────────

$credit_default_tier = PGE_Catalog::create_tier([
    'plan_id' => 1, 'tier_key' => 'credit_default_tier', 'name' => 'مستوى بلا رصيد',
    'price' => 10, 'currency' => 'SAR', 'salla_product_id' => null,
    'status' => 'active', 'sort_order' => 12,
    // invitation_credit_limit وreplacement_credit_limit غائبان عمداً — صلب السيناريو 4
]);
check_true('4. create_tier() نجح بلا حقول الرصيد', $credit_default_tier !== null);
check('4. invitation_credit_limit الافتراضي = 0', $credit_default_tier['invitation_credit_limit'] ?? null, 0);
check('4. replacement_credit_limit الافتراضي = 0', $credit_default_tier['replacement_credit_limit'] ?? null, 0);

$credit_values_tier = PGE_Catalog::create_tier([
    'plan_id' => 1, 'tier_key' => 'credit_values_tier', 'name' => 'مستوى بقيم رصيد',
    'price' => 300, 'currency' => 'SAR', 'salla_product_id' => null,
    'status' => 'active', 'sort_order' => 13,
    'invitation_credit_limit' => 100,
    'replacement_credit_limit' => 30,
]);
check('5. invitation_credit_limit = 100 (int)', $credit_values_tier['invitation_credit_limit'] ?? null, 100);
check_true('5. invitation_credit_limit من النوع int', is_int($credit_values_tier['invitation_credit_limit'] ?? null));
check('5. replacement_credit_limit = 30 (int)', $credit_values_tier['replacement_credit_limit'] ?? null, 30);
check_true('5. replacement_credit_limit من النوع int', is_int($credit_values_tier['replacement_credit_limit'] ?? null));

$credit_invalid_tier = PGE_Catalog::create_tier([
    'plan_id' => 1, 'tier_key' => 'credit_invalid_tier', 'name' => 'مستوى قيم غير صالحة',
    'price' => 50, 'currency' => 'SAR', 'salla_product_id' => null,
    'status' => 'active', 'sort_order' => 14,
    'invitation_credit_limit'  => -5,    // سالب
    'replacement_credit_limit' => 'abc', // نص غير رقمي
]);
check('6. invitation_credit_limit سالب (-5) → 0', $credit_invalid_tier['invitation_credit_limit'] ?? null, 0);
check('6. replacement_credit_limit نص غير رقمي (abc) → 0', $credit_invalid_tier['replacement_credit_limit'] ?? null, 0);

$updated_credit_no_touch = PGE_Catalog::update_tier($credit_values_tier['id'], [
    'plan_id' => 1, 'tier_key' => 'credit_values_tier', 'price' => 350, 'currency' => 'SAR',
    'salla_product_id' => null, 'status' => 'active', 'sort_order' => 13,
    // حقول الرصيد غائبة عمداً — صلب السيناريو 7
]);
check('7. update_tier() بدون حقول الرصيد → invitation تبقى 100', $updated_credit_no_touch['invitation_credit_limit'] ?? null, 100);
check('7. update_tier() بدون حقول الرصيد → replacement تبقى 30', $updated_credit_no_touch['replacement_credit_limit'] ?? null, 30);

$updated_credit_change = PGE_Catalog::update_tier($credit_values_tier['id'], [
    'plan_id' => 1, 'tier_key' => 'credit_values_tier', 'price' => 350, 'currency' => 'SAR',
    'salla_product_id' => null, 'status' => 'active', 'sort_order' => 13,
    'invitation_credit_limit'  => 150,
    'replacement_credit_limit' => 45,
]);
check('8. update_tier() invitation_credit_limit → 150', $updated_credit_change['invitation_credit_limit'] ?? null, 150);
check('8. update_tier() replacement_credit_limit → 45', $updated_credit_change['replacement_credit_limit'] ?? null, 45);

// إعادة القيم إلى 100/30 حرفياً (نص المهمة: "تفعيل Tier بقيم 100 و30")
// لاستخدامها في اختبار Snapshot أدناه (10)، والتحقق بنفس الاستدعاء من عدم
// تأثّر events_count/guest_limit (9).
$restored_credit_tier = PGE_Catalog::update_tier($credit_values_tier['id'], [
    'plan_id' => 1, 'tier_key' => 'credit_values_tier', 'price' => 300, 'currency' => 'SAR',
    'salla_product_id' => null, 'status' => 'active', 'sort_order' => 13,
    'invitation_credit_limit'  => 100,
    'replacement_credit_limit' => 30,
]);
check('9. events_count لم يتأثر بأي من تعديلات الرصيد أعلاه (يبقى 1، الافتراضي عند الإنشاء)', $restored_credit_tier['events_count'] ?? null, 1);
check_true('9. guest_limit غير موجود ضمن المخرجات (لم يُلمَس إطلاقاً — لا create_tier ولا update_tier يتعاملان معه)', !array_key_exists('guest_limit', $restored_credit_tier) || $restored_credit_tier['guest_limit'] === null);

// ── Snapshot (10-12) ─────────────────────────────────────────────────────────

set_test_user_id(9001);
reset_test_user(9001);

$activation_result = Mon_Events_Users::activate_catalog_tier(9001, 1, $restored_credit_tier['id']);
check_true('10. activate_catalog_tier() نجح (أعاد true وليس WP_Error)', $activation_result === true);
check('10. _mon_invitation_credit_total = 100', (int) get_user_meta(9001, '_mon_invitation_credit_total', true), 100);
check('10. _mon_invitation_credit_used = 0', (int) get_user_meta(9001, '_mon_invitation_credit_used', true), 0);
check('10. _mon_replacement_credit_total = 30', (int) get_user_meta(9001, '_mon_replacement_credit_total', true), 30);
check('10. _mon_replacement_credit_used = 0', (int) get_user_meta(9001, '_mon_replacement_credit_used', true), 0);

// محاكاة استهلاك سابق (لا يوجد منطق خصم فعلي بعد؛ هذا فقط لإثبات أن إعادة
// التفعيل بـTier مختلف يُصفّر used من جديد، لا يُبقي القيمة القديمة).
set_test_user_meta(9001, '_mon_invitation_credit_used', 40);
set_test_user_meta(9001, '_mon_replacement_credit_used', 10);

$second_credit_tier = PGE_Catalog::create_tier([
    'plan_id' => 1, 'tier_key' => 'credit_second_tier', 'name' => 'مستوى ثانٍ لإعادة التفعيل',
    'price' => 400, 'currency' => 'SAR', 'salla_product_id' => null,
    'status' => 'active', 'sort_order' => 15,
    'invitation_credit_limit'  => 200,
    'replacement_credit_limit' => 50,
]);

$reactivation_result = Mon_Events_Users::activate_catalog_tier(9001, 1, $second_credit_tier['id']);
check_true('11. إعادة التفعيل بـTier جديد نجحت', $reactivation_result === true);
// ملاحظة (Commit 9 — Invitation Credit Accumulation Across Renewals، تغيير
// سياسة تجارية معتمَد): الأرقام أدناه (200/50) كانت تعكس السلوك القديم
// "تصفير كامل عند كل تفعيل جديد إلى رصيد الـTier الخام مباشرة". اعتباراً من
// Commit 9، كل تفعيل حقيقي يُضيف رصيد الـTier الجديد إلى المتبقي الفعلي
// (Total - Used، بحد أدنى صفر) من الدورة السابقة بدل تصفيره بالكامل: هنا
// المتبقي قبل إعادة التفعيل = invitation(100-40=60)/replacement(30-10=20)،
// فالتفعيل بالـTier الثاني (200/50 جديدتين) يُنتج 60+200=260 و20+50=70. هذا
// تحديث ضروري ليعكس التعريف الصحيح الحالي للسياسة التجارية المعتمدة، لا
// إصلاحاً لعيب في هذا الاختبار.
check('11. إعادة التفعيل: total الأساسي يتحدّث إلى 260 (60 متبقٍ + 200 من Tier الثاني — تراكم Commit 9)', (int) get_user_meta(9001, '_mon_invitation_credit_total', true), 260);
check('11. إعادة التفعيل: used الأساسي يُصفَّر إلى 0 (لا يبقى 40 من قبل)', (int) get_user_meta(9001, '_mon_invitation_credit_used', true), 0);
check('11. إعادة التفعيل: total البدائل يتحدّث إلى 70 (20 متبقٍ + 50 من Tier الثاني — تراكم Commit 9)', (int) get_user_meta(9001, '_mon_replacement_credit_total', true), 70);
check('11. إعادة التفعيل: used البدائل يُصفَّر إلى 0 (لا يبقى 10 من قبل)', (int) get_user_meta(9001, '_mon_replacement_credit_used', true), 0);

// 12) مسار تفعيل Legacy (activate_user_package) لا يكتب أياً من مفاتيح
// الرصيد الأربعة إطلاقاً — الميزة مقصورة على مسار Catalog حصراً.
$GLOBALS['__test_options']['mon_packages_settings'] = [
    'plan_1' => ['name' => 'باقة Legacy تجريبية', 'guest_limit' => 20, 'host_photos' => 5, 'events_count' => 2, 'wa_messages' => 0],
];
reset_test_user(9002);
set_test_user_email('legacy-credit-test@example.test', 9002);

Mon_Events_Users::activate_user_package('legacy-credit-test@example.test', ['plan_key' => 'plan_1', 'order_id' => 'ORDER-CREDIT-TEST']);

check('12. تفعيل Legacy لا يكتب _mon_invitation_credit_total', get_user_meta(9002, '_mon_invitation_credit_total', true), '');
check('12. تفعيل Legacy لا يكتب _mon_invitation_credit_used', get_user_meta(9002, '_mon_invitation_credit_used', true), '');
check('12. تفعيل Legacy لا يكتب _mon_replacement_credit_total', get_user_meta(9002, '_mon_replacement_credit_total', true), '');
check('12. تفعيل Legacy لا يكتب _mon_replacement_credit_used', get_user_meta(9002, '_mon_replacement_credit_used', true), '');

// ── ملخص ────────────────────────────────────────────────────────────────

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
