<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس أسلوب tests/test-feature-snapshot.php
 * وtests/test-catalog-tier-events-count.php) لـ Event Quota Architecture — Commit 3
 * (Activation Snapshot)، بتنفيذ حقيقي فعلي — لا مرآة منطقية لأي كود إنتاج.
 *
 * يحمّل هذا الملف الكلاسات الحقيقية التالية دون أي تعديل عليها، وينفّذ
 * Mon_Events_Users::activate_catalog_tier() الحقيقية مباشرة (نفس الدالة التي
 * عُدِّلت في Commit 3):
 *   - includes/class-pge-catalog.php          (create_tier()/update_tier()/get_tier() الحقيقية)
 *   - includes/class-pge-feature-registry.php  (تعتمد عليها build_tier_features_snapshot() داخلياً)
 *   - includes/class-pge-tier-features.php     (تعتمد عليها build_tier_features_snapshot() داخلياً)
 *   - includes/feature-resolver.php
 *   - includes/class-mon-events-users.php      (activate_catalog_tier() الحقيقية — الهدف الفعلي لهذا الاختبار)
 *
 * عبر Fake $wpdb صغير في الذاكرة (نفس فلسفة tests/test-feature-snapshot.php:
 * بديل كافٍ فقط لأشكال الاستعلامات الفعلية الصادرة عن هذه الملفات — SELECT/
 * INSERT/UPDATE بشروط WHERE بسيطة من نوع المساواة — لا محرّك SQL عام، ولا
 * خادم MySQL فعلي).
 *
 * السيناريوهات السبعة المطلوبة صراحةً (راجع طلب المستخدم):
 *   1. Tier محدود (Limited/3) → تفعيل جديد → Snapshot mode=limited, limit=3.
 *   2. تكرار Webhook (نفس المعطيات تماماً) → مسار Idempotency الحالي (return
 *      true مبكرة) → Snapshot السابقة تبقى كما هي بلا أي إعادة كتابة.
 *   3. تعديل Tier لاحقاً (Limited 3 → Limited 5) → مستخدم مُفعَّل مسبقاً على
 *      القيمة القديمة يبقى Snapshot له 3 (عزل تام عن تعديل الـTier).
 *   4. تفعيل جديد فعلي (order_id مختلف) بعد ذلك التعديل → يلتقط القيمة
 *      الجديدة 5.
 *   5. Tier غير محدود (Unlimited) → Snapshot mode=unlimited, limit=1 (القيمة
 *      الرقمية المُتَجاهَلة وقت التشغيل حسب المعمارية المعتمدة).
 *   6. انحدار: التحقق من أن الحقول الشقيقة الموجودة مسبقاً
 *      (_mon_guest_limit, _mon_invitation_credit_total,
 *      _mon_replacement_credit_total, _mon_credit_cycle_id) لم يتأثر
 *      سلوكها بإضافة Event Quota.
 *   7. لا Snapshot جزئية أبداً: mode وlimit يُكتَبان معاً دوماً في المسار
 *      الناجح، ولا يوجد أي كود يكتب أحدهما بمعزل عن الآخر إلا ضمن سياسة
 *      "لا Rollback" العامة المعتمدة أصلاً لكامل الـSnapshot (مُختبَرة
 *      ومُعتمَدة سلفاً في tests/test-feature-snapshot.php § قسم G) — يُثبَت
 *      هنا أن Event Quota لا تُدخِل أي سلوك جديد يخالف تلك السياسة القائمة،
 *      لا أكثر ولا أقل.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. لا تعديل على أي كود
 * إنتاج تم إجراؤه لأجل هذا الملف — إن ظهر عطل حقيقي، يُوثَّق في التقرير
 * النهائي بدل إصلاحه ضمنياً هنا.
 *
 * التشغيل: php tests/test-event-quota-snapshot.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
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
    // create_tier() لكل Tier نُنشئه في هذا الملف).
    function wp_strip_all_tags($text, $remove_breaks = false) {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags($text);
        if ($remove_breaks) {
            $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
        }
        return trim($text);
    }
}
if (!function_exists('wp_generate_uuid4')) {
    // نستخدم بديلاً حتمياً بسيطاً بدل الاعتماد على الاحتياط العشوائي داخل
    // Mon_Events_Users::generate_credit_cycle_id() — يكفي هنا فقط أن تكون
    // القيمة "غير فارغة ومختلفة بين استدعاءين مختلفين"؛ لا نحتاج عشوائية
    // تشفيرية حقيقية لأغراض الاختبار، ونتجنب الاعتماد على random_bytes()
    // داخل بيئة WASM قد لا توفر مصدر عشوائية نظامياً بنفس الموثوقية.
    $GLOBALS['__test_uuid_counter'] = 0;
    function wp_generate_uuid4() {
        $GLOBALS['__test_uuid_counter']++;
        return 'test-uuid-' . $GLOBALS['__test_uuid_counter'];
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

// ── User Meta وهمي في الذاكرة، مع حقن فشل كتابة قابل للتحكّم ────────────────

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_users_by_id'] = [];
$GLOBALS['__test_force_update_user_meta_failure'] = [];

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}

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
// (PGE_Tier_Features) معاً، بنفس فلسفة Fake_Wpdb_Snapshot في
// tests/test-feature-snapshot.php (مطابقة WHERE من نوع "field = value" مفصولة
// بـAND فقط، لا JOIN ولا LIKE) — بديل كافٍ فقط لأشكال الاستعلامات الفعلية
// الصادرة عن الملفات الثلاثة، لا محرّك SQL عام.

class Fake_Wpdb_Event_Quota_Snapshot
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $plans = [];
    public $tiers = [];
    public $tier_features = [];

    private $plans_next_id = 1;
    private $tiers_next_id = 1;
    private $tier_features_next_id = 1;

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

    public function seed_plan($id, array $row)
    {
        $this->plans[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->plans_next_id) {
            $this->plans_next_id = $id + 1;
        }
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Event_Quota_Snapshot();
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

// ── باقة أساسية مشتركة (mon_plans) لكل المستويات في هذا الملف ───────────────

$wpdb->seed_plan(1, [
    'plan_key' => 'basic_plan',
    'name'     => 'باقة أساسية',
    'plan_type' => 'personal',
    'status'   => 'active',
]);

/**
 * مساعد اختبار: ينشئ Tier حقيقياً عبر PGE_Catalog::create_tier() الحقيقية
 * (لا Seed مباشر) — نطابق مسار الإنتاج الفعلي بالكامل، بما في ذلك تطبيع
 * event_quota_mode/event_quota_limit عبر normalize_event_quota_mode()/
 * normalize_event_quota_limit() الخاصتين (private) داخل PGE_Catalog، واللتين
 * لا سبيل لاختبارهما هنا إلا عبر المسار العلني create_tier() نفسه (تماماً
 * كما فعل tests/test-catalog-tier-event-quota-crud.php الحالي لمعظم حالاته
 * غير الخاصة بالانعكاس المباشر عبر Reflection).
 */
function make_test_tier($tier_key, $sort_order, array $extra = [])
{
    global $wpdb;
    return PGE_Catalog::create_tier(array_merge([
        'plan_id'          => 1,
        'tier_key'         => $tier_key,
        'name'             => 'مستوى اختبار ' . $tier_key,
        'price'            => 100,
        'currency'         => 'SAR',
        'salla_product_id' => null,
        'status'           => 'active',
        'sort_order'       => $sort_order,
    ], $extra));
}

echo "=== السيناريو 1: تفعيل جديد — Tier محدود (Limited/3) ===\n";

$tier1 = make_test_tier('s1_limited_3', 1, [
    'event_quota_mode'  => 'limited',
    'event_quota_limit' => 3,
]);
check('1. Tier أُنشئ فعلياً بـevent_quota_mode=limited', $tier1['event_quota_mode'] ?? null, 'limited');
check('1. Tier أُنشئ فعلياً بـevent_quota_limit=3', $tier1['event_quota_limit'] ?? null, 3);

reset_test_user(9301);
$result_s1 = Mon_Events_Users::activate_catalog_tier(9301, 1, $tier1['id'], 'ORDER-S1');
check_true('1. activate_catalog_tier() نجح (true)', $result_s1 === true);
check('1. _mon_event_quota_mode = limited', get_user_meta(9301, '_mon_event_quota_mode', true), 'limited');
check('1. _mon_event_quota_limit = 3 (int)', get_user_meta(9301, '_mon_event_quota_limit', true), 3);
check_true('1. _mon_event_quota_limit من النوع int فعلياً (لا string)', is_int(get_user_meta(9301, '_mon_event_quota_limit', true)));

echo "\n=== السيناريو 2: تكرار Webhook (Idempotency) — لا إعادة كتابة ===\n";

$tier2 = make_test_tier('s2_idempotency', 2, [
    'event_quota_mode'  => 'limited',
    'event_quota_limit' => 4,
]);
reset_test_user(9302);
$result_s2_first = Mon_Events_Users::activate_catalog_tier(9302, 1, $tier2['id'], 'ORDER-S2');
check_true('2. أول تفعيل نجح', $result_s2_first === true);

$mode_before_s2 = get_user_meta(9302, '_mon_event_quota_mode', true);
$limit_before_s2 = get_user_meta(9302, '_mon_event_quota_limit', true);
$full_meta_before_s2 = $GLOBALS['__test_user_meta'][9302];

// تعديل صف الـTier الحيّ مباشرة بعد التفعيل الأول (محاكاة تعديل إداري بين
// الطلب الأول وإعادة إرسال نفس Webhook) — إن أعادت الدالة قراءة الـTier رغم
// مطابقة كل معطيات الطلب، ستتسرب القيمة الجديدة خطأً؛ هذا بالضبط ما يثبت
// المسار الصحيح Early Return وليس فقط "القيم لم تتغيّر صدفة".
$wpdb->tiers[$tier2['id']]['event_quota_limit'] = 999;

// نفس المعطيات تماماً (plan_id/tier_id/order_id) — يجب أن يمر عبر فرع
// "تكرار طلب متطابق" الحالي (return true مبكرة) بلا أي إعادة بناء Snapshot.
$result_s2_repeat = Mon_Events_Users::activate_catalog_tier(9302, 1, $tier2['id'], 'ORDER-S2');
check_true('2. تكرار نفس الطلب (Webhook) يعيد true', $result_s2_repeat === true);
check('2. _mon_event_quota_mode لم تتغيّر', get_user_meta(9302, '_mon_event_quota_mode', true), $mode_before_s2);
check('2. _mon_event_quota_limit لم تتغيّر (تبقى 4 رغم تعديل Tier الحيّ إلى 999)', get_user_meta(9302, '_mon_event_quota_limit', true), $limit_before_s2);
check('2. لا تغيير على أي مفتاح آخر في Meta المستخدم بالكامل', $GLOBALS['__test_user_meta'][9302], $full_meta_before_s2);

echo "\n=== السيناريو 3: تعديل Tier لاحقاً — عزل Snapshot المُفعَّل مسبقاً ===\n";

$tier3 = make_test_tier('s3_isolation', 3, [
    'event_quota_mode'  => 'limited',
    'event_quota_limit' => 3,
]);
reset_test_user(9303);
$result_s3_activate = Mon_Events_Users::activate_catalog_tier(9303, 1, $tier3['id'], 'ORDER-S3A');
check_true('3. التفعيل الأول (Limited/3) نجح', $result_s3_activate === true);
check('3. Snapshot الأول = 3 قبل أي تعديل', get_user_meta(9303, '_mon_event_quota_limit', true), 3);

// تعديل Tier فعلياً عبر PGE_Catalog::update_tier() الحقيقية (لا Seed مباشر).
// update_tier() الحقيقية تتطلب الحقول السبعة الإلزامية معاً دوماً (تحديث
// كامل للسجل لا PATCH جزئي — موثَّق صراحة في تعليقاتها أعلى الدالة)، فنمرّر
// قيم tier3 الحالية كما هي ونُغيّر event_quota_limit فقط، تماماً كما يفعل
// نموذج الإدارة الفعلي (يعيد إرسال كل الحقول دوماً).
$update_result_s3 = PGE_Catalog::update_tier($tier3['id'], [
    'plan_id'           => $tier3['plan_id'],
    'tier_key'          => $tier3['tier_key'],
    'price'             => $tier3['price'],
    'currency'          => $tier3['currency'],
    'salla_product_id'  => $tier3['salla_product_id'],
    'status'            => $tier3['status'],
    'sort_order'        => $tier3['sort_order'],
    'event_quota_limit' => 5,
]);
check_true('3. تعديل الـTier (update_tier) نجح فعلياً', is_array($update_result_s3));
$tier3_after_edit = PGE_Catalog::get_tier($tier3['id']);
check('3. صف الـTier الحيّ أصبح فعلياً event_quota_limit=5', $tier3_after_edit['event_quota_limit'] ?? null, 5);

check('3. Snapshot المستخدم المُفعَّل مسبقاً يبقى 3 (معزول تماماً عن تعديل الـTier)', get_user_meta(9303, '_mon_event_quota_limit', true), 3);
check('3. _mon_event_quota_mode يبقى limited كذلك (بلا تغيير)', get_user_meta(9303, '_mon_event_quota_mode', true), 'limited');

echo "\n=== السيناريو 4: تفعيل جديد فعلي بعد تعديل Tier — يلتقط القيمة الجديدة ===\n";

// نفس المستخدم 9303 ونفس الـTier (تم تعديله للتو إلى limit=5)، لكن بمعطى
// order_id مختلف — هذا يجعله تفعيلاً جديداً فعلياً (لا يطابق فرع Idempotency
// أعلاه)، فيجب أن يقرأ صف الـTier الحالي (5) لا القيمة المجمَّدة سابقاً (3).
$result_s4 = Mon_Events_Users::activate_catalog_tier(9303, 1, $tier3['id'], 'ORDER-S3B');
check_true('4. التفعيل الجديد الفعلي (order_id مختلف) نجح', $result_s4 === true);
check('4. Snapshot الجديدة تعكس قيمة الـTier الحالية (5)', get_user_meta(9303, '_mon_event_quota_limit', true), 5);
check('4. _mon_event_quota_mode يبقى limited', get_user_meta(9303, '_mon_event_quota_mode', true), 'limited');

echo "\n=== السيناريو 5: Tier غير محدود (Unlimited) ===\n";

$tier5 = make_test_tier('s5_unlimited', 5, [
    'event_quota_mode' => 'unlimited',
    // event_quota_limit غير مُمرَّرة عمداً — normalize_event_quota_limit()
    // في PGE_Catalog تطبّعها افتراضياً إلى 1 (راجع تعليقاتها: القيمة الرقمية
    // بلا معنى تجاري حين Unlimited، وتُتجاهَل وقت التشغيل مستقبلاً).
]);
check('5. Tier أُنشئ فعلياً بـevent_quota_mode=unlimited', $tier5['event_quota_mode'] ?? null, 'unlimited');
check('5. event_quota_limit طُبِّعت افتراضياً إلى 1 عند الإنشاء', $tier5['event_quota_limit'] ?? null, 1);

reset_test_user(9305);
$result_s5 = Mon_Events_Users::activate_catalog_tier(9305, 1, $tier5['id'], 'ORDER-S5');
check_true('5. activate_catalog_tier() نجح', $result_s5 === true);
check('5. _mon_event_quota_mode = unlimited', get_user_meta(9305, '_mon_event_quota_mode', true), 'unlimited');
check('5. _mon_event_quota_limit = 1 (القيمة الرقمية المُتجاهَلة وقت التشغيل)', get_user_meta(9305, '_mon_event_quota_limit', true), 1);

echo "\n=== السيناريو 6: انحدار — الحقول الشقيقة في نفس Snapshot ===\n";

$tier6 = make_test_tier('s6_regression', 6, [
    'event_quota_mode'         => 'limited',
    'event_quota_limit'        => 2,
    'invitation_credit_limit'  => 40,
    'replacement_credit_limit' => 10,
]);
// guest_limit عمود موجود فعلياً في mon_plan_tiers (Schema)، لكن لا
// create_tier() ولا update_tier() الحقيقيتين تكتبانه إطلاقاً (تحقّقنا من
// هذا بقراءة الكود الفعلي — لا وجود لأي إشارة لـ'guest_limit' في مسار CRUD،
// مسألة سابقة لـEvent Quota تماماً وخارج نطاق هذا الاختبار). لذا نحقنه هنا
// مباشرة على صف الـTier الوهمي، بنفس الطريقة الوحيدة التي يُمكن لهذه القيمة
// أن تصل بها فعلياً إلى activate_catalog_tier() (قراءة $tier['guest_limit']
// الخام، بصرف النظر عن كيفية وصولها للصف).
$wpdb->tiers[$tier6['id']]['guest_limit'] = 150;
reset_test_user(9306);
$result_s6_first = Mon_Events_Users::activate_catalog_tier(9306, 1, $tier6['id'], 'ORDER-S6A');
check_true('6. التفعيل الأول نجح', $result_s6_first === true);

check('6. _mon_guest_limit = 150 (سلوك سابق غير مُتأثِّر)', get_user_meta(9306, '_mon_guest_limit', true), 150);
check('6. _mon_invitation_credit_total = 40 (سلوك سابق غير مُتأثِّر)', get_user_meta(9306, '_mon_invitation_credit_total', true), 40);
check('6. _mon_invitation_credit_used = 0 (سلوك سابق غير مُتأثِّر)', get_user_meta(9306, '_mon_invitation_credit_used', true), 0);
check('6. _mon_replacement_credit_total = 10 (سلوك سابق غير مُتأثِّر)', get_user_meta(9306, '_mon_replacement_credit_total', true), 10);
check('6. _mon_replacement_credit_used = 0 (سلوك سابق غير مُتأثِّر)', get_user_meta(9306, '_mon_replacement_credit_used', true), 0);
$cycle_id_first_s6 = get_user_meta(9306, '_mon_credit_cycle_id', true);
check_true('6. _mon_credit_cycle_id مكتوب وغير فارغ', is_string($cycle_id_first_s6) && $cycle_id_first_s6 !== '');
check('6. Event Quota مكتوبة أيضاً في نفس Snapshot (limited)', get_user_meta(9306, '_mon_event_quota_mode', true), 'limited');
check('6. Event Quota مكتوبة أيضاً في نفس Snapshot (2)', get_user_meta(9306, '_mon_event_quota_limit', true), 2);

// تفعيل جديد فعلي آخر (order_id مختلف) — credit_cycle_id يجب أن يتغيّر (سلوك
// سابق موجود مسبقاً، غير مُتأثِّر بإضافة Event Quota) بينما Event Quota أيضاً
// تُعاد كتابتها بشكل صحيح في نفس الوقت من نفس صف الـTier.
$result_s6_second = Mon_Events_Users::activate_catalog_tier(9306, 1, $tier6['id'], 'ORDER-S6B');
check_true('6. التفعيل الثاني الفعلي نجح', $result_s6_second === true);
$cycle_id_second_s6 = get_user_meta(9306, '_mon_credit_cycle_id', true);
check_true('6. _mon_credit_cycle_id تغيّر بين تفعيلين فعليين مختلفين (سلوك سابق سليم)', $cycle_id_second_s6 !== $cycle_id_first_s6);
check('6. _mon_invitation_credit_used أُعيد تصفيره إلى 0 مجدداً (سلوك سابق سليم)', get_user_meta(9306, '_mon_invitation_credit_used', true), 0);
check('6. Event Quota تبقى صحيحة بعد التفعيل الثاني أيضاً (limited/2)', get_user_meta(9306, '_mon_event_quota_limit', true), 2);

echo "\n=== السيناريو 7: لا Snapshot جزئية أبداً لحقلي Event Quota ===\n";

// 7أ. المسار الناجح العادي (كل السيناريوهات 1-6 أعلاه): مode وlimit يظهران
// معاً دوماً — نتحقق صراحة هنا من حالة واحدة إضافية مستقلة لهذا الغرض بالذات.
$tier7 = make_test_tier('s7_together_success', 7, [
    'event_quota_mode'  => 'limited',
    'event_quota_limit' => 6,
]);
reset_test_user(9307);
Mon_Events_Users::activate_catalog_tier(9307, 1, $tier7['id'], 'ORDER-S7A');
check_true(
    '7أ. في المسار الناجح: كلا المفتاحين موجودان معاً (لا أحدهما بمفرده)',
    metadata_exists('user', 9307, '_mon_event_quota_mode') === metadata_exists('user', 9307, '_mon_event_quota_limit')
    && metadata_exists('user', 9307, '_mon_event_quota_mode')
);

// 7ب. حقن فشل كتابة فعلي مضبوط بدقة على _mon_event_quota_limit تحديداً (بعد
// أن ينجح _mon_event_quota_mode في نفس حلقة الكتابة التسلسلية، لأن ترتيب
// مفاتيح $snapshot في activate_catalog_tier() الحقيقية يضع mode قبل limit
// مباشرة) — يثبت هذا بدقة حدود سياسة "لا Rollback" العامة المعتمدة أصلاً
// لكامل الـSnapshot (مُختبَرة ومُعتمَدة سلفاً في tests/test-feature-snapshot.php
// § قسم G، السيناريوهات G24-G26): Event Quota لا تُدخِل أي ضمان معاملاتي
// (Transactional) جديد لم يكن موجوداً من قبل لبقية حقول الـSnapshot، ولا
// تكسر أي ضمان كان قائماً. هذا سلوك متسق موروث، وليس عيباً جديداً أحدثه
// Commit 3 تحديداً.
$tier7b = make_test_tier('s7_forced_partial_failure', 8, [
    'event_quota_mode'  => 'limited',
    'event_quota_limit' => 9,
]);
reset_test_user(9308);
set_test_force_update_user_meta_failure(9308, '_mon_event_quota_limit', true);
$result_s7b = Mon_Events_Users::activate_catalog_tier(9308, 1, $tier7b['id'], 'ORDER-S7B');
check_true('7ب. فشل كتابة _mon_event_quota_limit المحقون → WP_Error (كما هو متوقع، لا كتمان للخطأ)', is_wp_error($result_s7b));
check_true('7ب. _mon_event_quota_mode نفسها كُتبت فعلاً قبل نقطة الفشل (سلوك موروث من سياسة لا-Rollback العامة، لا عيب جديد)', metadata_exists('user', 9308, '_mon_event_quota_mode'));
check_true('7ب. _mon_event_quota_limit لم تُكتب (فشلت فعلياً كما حُقن)', !metadata_exists('user', 9308, '_mon_event_quota_limit'));
clear_test_force_update_user_meta_failure(9308, '_mon_event_quota_limit');

// 7ج. تأكيد أن الحالة الجزئية أعلاه (7ب) ليست دائمة: تفعيل فعلي جديد لاحق
// لنفس المستخدم (بلا حقن فشل) يعيد Snapshot متكاملة كاملة كالمعتاد.
$result_s7c = Mon_Events_Users::activate_catalog_tier(9308, 1, $tier7b['id'], 'ORDER-S7C');
check_true('7ج. تفعيل فعلي لاحق بلا حقن فشل ينجح', $result_s7c === true);
check('7ج. _mon_event_quota_mode = limited بعد الإصلاح', get_user_meta(9308, '_mon_event_quota_mode', true), 'limited');
check('7ج. _mon_event_quota_limit = 9 بعد الإصلاح (كلاهما موجود الآن معاً)', get_user_meta(9308, '_mon_event_quota_limit', true), 9);

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
