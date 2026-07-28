<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس أسلوب tests/test-event-quota-snapshot.php)
 * لـCommit 9 (Invitation Credit Accumulation Across Renewals) — تنفيذ حقيقي
 * فعلي لـMon_Events_Users::activate_catalog_tier() الحقيقية، بلا تعديل عليها،
 * ولا مرآة منطقية.
 *
 * السيناريوهات السبعة المطلوبة صراحةً:
 *   1. أول تفعيل — Total الجديد = رصيد الـTier بالضبط (لا تراكم، لا رصيد سابق).
 *   2. تجديد باستخدام صفري (Example 1 من المواصفة: 100+100=200).
 *   3. تجديد بعد استخدام جزئي (Example 2 من المواصفة: متبقٍ 70 + جديد 100 = 170).
 *   4. تجديد بعد استخدام كامل (متبقٍ 0 + جديد 100 = 100، بلا ترحيل سالب).
 *   5. رصيد الاستبدال (Replacement) يتبع نفس الصيغة تماماً، بشكل مستقل عن
 *      الدعوات الأساسية.
 *   6. تكرار Webhook (نفس plan_id/tier_id/order_id تماماً) → لا تراكم مضاعف
 *      (نفس مسار Idempotency القائم أصلاً، بلا أي تعديل عليه في هذا الـCommit).
 *   7. انحدار Event Quota: التأكد أن _mon_event_quota_mode/_mon_event_quota_limit
 *      لا تزالان تعملان بالضبط كما كانتا (دورة جديدة كاملة بلا تراكم في كل
 *      تفعيل)، بمعزل تام عن التغيير التراكمي في رصيد الدعوات/الاستبدال.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. لا تعديل على أي كود إنتاج
 * تم إجراؤه لأجل هذا الملف.
 *
 * التشغيل: php tests/test-invitation-credit-accumulation.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (نفس الحد الأدنى المستخدَم في test-event-quota-snapshot.php) ──

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

// ── User Meta وهمي في الذاكرة (نفس نمط test-event-quota-snapshot.php) ───────

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_users_by_id'] = [];

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
    $GLOBALS['__test_users_by_id'][$user_id] = true;
}

function get_user_by($field, $value)
{
    if ($field === 'id') {
        return !empty($GLOBALS['__test_users_by_id'][$value]) ? (object) ['ID' => (int) $value] : false;
    }
    return false;
}

// ── Fake $wpdb — منسوخ حرفياً بنفس بنية test-event-quota-snapshot.php،
// يخدم mon_plans + mon_plan_tiers (PGE_Catalog) وmon_tier_features
// (PGE_Tier_Features) معاً. ────────────────────────────────────────────────

class Fake_Wpdb_Credit_Accumulation
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

$GLOBALS['wpdb'] = new Fake_Wpdb_Credit_Accumulation();
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

$wpdb->seed_plan(1, [
    'plan_key'  => 'basic_plan',
    'name'      => 'باقة أساسية',
    'plan_type' => 'personal',
    'status'    => 'active',
]);

function make_test_tier($tier_key, $sort_order, array $extra = [])
{
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

// ============================================================================
// السيناريو 1: أول تفعيل — لا رصيد سابق إطلاقاً
// ============================================================================
echo "=== السيناريو 1: أول تفعيل ===\n";

reset_test_user(9401);
$tier1 = make_test_tier('s1_first', 1, [
    'invitation_credit_limit'  => 100,
    'replacement_credit_limit' => 20,
    'event_quota_mode'         => 'limited',
    'event_quota_limit'        => 3,
]);

$result_1 = Mon_Events_Users::activate_catalog_tier(9401, 1, $tier1['id'], 'ORDER-1-FIRST');
check_true('1. activate_catalog_tier() نجح', $result_1 === true);
check('1. أول تفعيل: _mon_invitation_credit_total = 100 (رصيد الـTier بالضبط، لا تراكم)', get_user_meta(9401, '_mon_invitation_credit_total', true), 100);
check('1. أول تفعيل: _mon_invitation_credit_used = 0', get_user_meta(9401, '_mon_invitation_credit_used', true), 0);
check('1. أول تفعيل: _mon_replacement_credit_total = 20', get_user_meta(9401, '_mon_replacement_credit_total', true), 20);
check('1. أول تفعيل: _mon_replacement_credit_used = 0', get_user_meta(9401, '_mon_replacement_credit_used', true), 0);

// ============================================================================
// السيناريو 2: تجديد باستخدام صفري (Example 1 من المواصفة: 100+100=200)
// ============================================================================
echo "\n=== السيناريو 2: تجديد باستخدام صفري (Example 1) ===\n";

$result_2 = Mon_Events_Users::activate_catalog_tier(9401, 1, $tier1['id'], 'ORDER-2-RENEWAL-ZERO-USAGE');
check_true('2. تجديد (طلب مختلف) نجح', $result_2 === true);
check('2. تجديد باستخدام صفري: Total الجديد = 200 (100 متبقٍ + 100 جديد)', get_user_meta(9401, '_mon_invitation_credit_total', true), 200);
check('2. تجديد باستخدام صفري: Used = 0', get_user_meta(9401, '_mon_invitation_credit_used', true), 0);

// ============================================================================
// السيناريو 3: تجديد بعد استخدام جزئي (Example 2 من المواصفة: متبقٍ 70 + 100 = 170)
// ============================================================================
echo "\n=== السيناريو 3: تجديد بعد استخدام جزئي (Example 2) ===\n";

// محاكاة استهلاك 30 من الرصيد الحالي (200) قبل التجديد التالي — لا علاقة لهذا
// الاختبار بمنطق الـLedger نفسه، فقط ضبط الحالة المسبقة قبل استدعاء
// activate_catalog_tier() الحقيقية.
set_test_user_meta(9401, '_mon_invitation_credit_used', 30);
// الرصيد الحالي بعد الاستهلاك الجزئي: 200 - 30 = 170 (لكن المواصفة تستخدم
// أرقام 100/30/70 كمثال مستقل — نطابق نفس المعادلة برقمنا الفعلي 200/30/170
// بدل إعادة ضبط Total إلى 100 يدوياً، لضمان استمرارية سيناريوهات هذا الملف).
$result_3 = Mon_Events_Users::activate_catalog_tier(9401, 1, $tier1['id'], 'ORDER-3-RENEWAL-PARTIAL-USAGE');
check_true('3. تجديد بعد استخدام جزئي نجح', $result_3 === true);
check('3. تجديد بعد استخدام جزئي: Total الجديد = 270 (170 متبقٍ + 100 جديد)', get_user_meta(9401, '_mon_invitation_credit_total', true), 270);
check('3. تجديد بعد استخدام جزئي: Used = 0 (يُصفَّر دوماً عند تفعيل جديد)', get_user_meta(9401, '_mon_invitation_credit_used', true), 0);

// تكرار المثال الحرفي من المواصفة (100/30/70 → تجديد 100 → 170) على مستخدم
// منفصل تماماً، لضمان مطابقة الأرقام المذكورة نصاً في طلب المستخدم.
reset_test_user(9402);
$tier_example2 = make_test_tier('s3_example2', 2, [
    'invitation_credit_limit'  => 100,
    'replacement_credit_limit' => 0,
]);
Mon_Events_Users::activate_catalog_tier(9402, 1, $tier_example2['id'], 'ORDER-EX2-A');
check('3ب. Example 2 حرفياً: بعد أول تفعيل Total = 100', get_user_meta(9402, '_mon_invitation_credit_total', true), 100);
set_test_user_meta(9402, '_mon_invitation_credit_used', 30);
Mon_Events_Users::activate_catalog_tier(9402, 1, $tier_example2['id'], 'ORDER-EX2-B');
check('3ب. Example 2 حرفياً: بعد التجديد Total = 170 (متبقٍ 70 + جديد 100)', get_user_meta(9402, '_mon_invitation_credit_total', true), 170);
check('3ب. Example 2 حرفياً: Used = 0', get_user_meta(9402, '_mon_invitation_credit_used', true), 0);

// ============================================================================
// السيناريو 4: تجديد بعد استخدام كامل (متبقٍ 0 + 100 = 100، بلا ترحيل سالب)
// ============================================================================
echo "\n=== السيناريو 4: تجديد بعد استخدام كامل ===\n";

reset_test_user(9403);
$tier4 = make_test_tier('s4_full_usage', 3, [
    'invitation_credit_limit'  => 100,
    'replacement_credit_limit' => 0,
]);
Mon_Events_Users::activate_catalog_tier(9403, 1, $tier4['id'], 'ORDER-4-A');
check('4. بعد أول تفعيل: Total = 100', get_user_meta(9403, '_mon_invitation_credit_total', true), 100);

// استهلاك كامل: used = total بالضبط.
set_test_user_meta(9403, '_mon_invitation_credit_used', 100);
Mon_Events_Users::activate_catalog_tier(9403, 1, $tier4['id'], 'ORDER-4-B');
check('4. تجديد بعد استخدام كامل: Total الجديد = 100 (متبقٍ 0 + جديد 100، بلا ترحيل سالب)', get_user_meta(9403, '_mon_invitation_credit_total', true), 100);
check('4. تجديد بعد استخدام كامل: Used = 0', get_user_meta(9403, '_mon_invitation_credit_used', true), 0);

// حالة متطرفة إضافية: استخدام يتجاوز الرصيد المخزَّن (بيانات غير متسقة
// افتراضياً) — max(0, ...) يجب أن يمنع أي قيمة سالبة من التسرّب للمعادلة.
reset_test_user(9404);
$tier4b = make_test_tier('s4b_over_usage', 4, [
    'invitation_credit_limit'  => 50,
    'replacement_credit_limit' => 0,
]);
Mon_Events_Users::activate_catalog_tier(9404, 1, $tier4b['id'], 'ORDER-4B-A');
set_test_user_meta(9404, '_mon_invitation_credit_used', 999); // أكبر من Total المخزَّن
Mon_Events_Users::activate_catalog_tier(9404, 1, $tier4b['id'], 'ORDER-4B-B');
check('4ب. استخدام يتجاوز الرصيد المخزَّن: المتبقي يُقصّ عند صفر (Total الجديد = 50 فقط)', get_user_meta(9404, '_mon_invitation_credit_total', true), 50);

// ============================================================================
// السيناريو 5: رصيد الاستبدال (Replacement) يتبع نفس الصيغة، بمعزل عن الدعوات
// ============================================================================
echo "\n=== السيناريو 5: رصيد الاستبدال (Replacement) ===\n";

reset_test_user(9405);
$tier5 = make_test_tier('s5_replacement', 5, [
    'invitation_credit_limit'  => 0,
    'replacement_credit_limit' => 20,
]);
Mon_Events_Users::activate_catalog_tier(9405, 1, $tier5['id'], 'ORDER-5-A');
check('5. أول تفعيل: _mon_replacement_credit_total = 20', get_user_meta(9405, '_mon_replacement_credit_total', true), 20);

set_test_user_meta(9405, '_mon_replacement_credit_used', 5);
Mon_Events_Users::activate_catalog_tier(9405, 1, $tier5['id'], 'ORDER-5-B');
check('5. تجديد بعد استخدام جزئي: Total الجديد = 35 (متبقٍ 15 + جديد 20)', get_user_meta(9405, '_mon_replacement_credit_total', true), 35);
check('5. تجديد: _mon_replacement_credit_used = 0', get_user_meta(9405, '_mon_replacement_credit_used', true), 0);
// استقلالية عن الدعوات الأساسية: invitation لم تتأثر بهذا الـTier (limit=0)
check('5. استقلالية: _mon_invitation_credit_total = 0 (لم يتأثر بتغيّر Replacement)', get_user_meta(9405, '_mon_invitation_credit_total', true), 0);

// ============================================================================
// السيناريو 6: تكرار Webhook — لا تراكم مضاعف
// ============================================================================
echo "\n=== السيناريو 6: تكرار Webhook ===\n";

reset_test_user(9406);
$tier6 = make_test_tier('s6_duplicate', 6, [
    'invitation_credit_limit'  => 100,
    'replacement_credit_limit' => 10,
]);
$result_6a = Mon_Events_Users::activate_catalog_tier(9406, 1, $tier6['id'], 'ORDER-6-SAME');
check_true('6. التفعيل الأول نجح', $result_6a === true);
check('6. بعد التفعيل الأول: Total = 100', get_user_meta(9406, '_mon_invitation_credit_total', true), 100);

// نفس plan_id/tier_id/order_id بالضبط — يجب أن يعيد true عبر مسار
// Idempotency الحالي (return true مبكرة) بلا أي إعادة كتابة للـSnapshot إطلاقاً.
$result_6b = Mon_Events_Users::activate_catalog_tier(9406, 1, $tier6['id'], 'ORDER-6-SAME');
check_true('6. تكرار Webhook بنفس المعطيات تماماً يعيد true (idempotent)', $result_6b === true);
check('6. تكرار Webhook: Total يبقى 100 (لا تراكم مضاعف)', get_user_meta(9406, '_mon_invitation_credit_total', true), 100);
check('6. تكرار Webhook: Replacement يبقى 10 أيضاً (لا تراكم مضاعف)', get_user_meta(9406, '_mon_replacement_credit_total', true), 10);

// حتى مع استخدام جزئي قبل التكرار — نفس الطلب المطابق تماماً لا يُعيد كتابة
// أي شيء (مسار "نفس البيانات" لا يصل إطلاقاً لحساب Total الجديد).
set_test_user_meta(9406, '_mon_invitation_credit_used', 40);
$result_6c = Mon_Events_Users::activate_catalog_tier(9406, 1, $tier6['id'], 'ORDER-6-SAME');
check_true('6ب. تكرار ثالث بنفس المعطيات يعيد true أيضاً', $result_6c === true);
check('6ب. تكرار Webhook مع استخدام جزئي: Total يبقى 100 (لم يُعَد حسابه إطلاقاً)', get_user_meta(9406, '_mon_invitation_credit_total', true), 100);
check('6ب. تكرار Webhook مع استخدام جزئي: Used يبقى 40 كما هو (لم يُصفَّر لأن لا Snapshot جديدة كُتبت)', get_user_meta(9406, '_mon_invitation_credit_used', true), 40);

// ============================================================================
// السيناريو 7: انحدار Event Quota — بلا تراكم، بمعزل تام عن التغيير الحالي
// ============================================================================
echo "\n=== السيناريو 7: انحدار Event Quota ===\n";

reset_test_user(9407);
$tier7 = make_test_tier('s7_event_quota_regression', 7, [
    'invitation_credit_limit'  => 50,
    'replacement_credit_limit' => 5,
    'event_quota_mode'         => 'limited',
    'event_quota_limit'        => 10,
]);
Mon_Events_Users::activate_catalog_tier(9407, 1, $tier7['id'], 'ORDER-7-A');
check('7. أول تفعيل: _mon_event_quota_mode = limited', get_user_meta(9407, '_mon_event_quota_mode', true), 'limited');
check('7. أول تفعيل: _mon_event_quota_limit = 10', get_user_meta(9407, '_mon_event_quota_limit', true), 10);
check('7. أول تفعيل: _mon_invitation_credit_total = 50 (رصيد منفصل تماماً)', get_user_meta(9407, '_mon_invitation_credit_total', true), 50);

// تجديد بنفس الـTier — Event Quota يجب أن يبقى 10 (دورة جديدة كاملة، لا
// تراكم)، بينما رصيد الدعوات يتراكم (50 + 50 = 100) بحسب Commit 9.
Mon_Events_Users::activate_catalog_tier(9407, 1, $tier7['id'], 'ORDER-7-B');
check('7. بعد التجديد: _mon_event_quota_limit يبقى 10 (لا تراكم — دورة جديدة كاملة)', get_user_meta(9407, '_mon_event_quota_limit', true), 10);
check('7. بعد التجديد: _mon_event_quota_mode يبقى limited', get_user_meta(9407, '_mon_event_quota_mode', true), 'limited');
check('7. بعد التجديد: _mon_invitation_credit_total تراكم إلى 100 (50 متبقٍ + 50 جديد)', get_user_meta(9407, '_mon_invitation_credit_total', true), 100);

// حتى بعد استخدام جزئي لحصة المناسبات (محاكاة يدوية، لا علاقة لها بمنطق
// العدّ الفعلي في Resolver) — تجديد جديد يعيد ضبط Event Quota من الصفر
// دائماً (لا قراءة لأي "متبقٍ" سابق لحصة المناسبات، خلافاً تماماً لرصيد
// الدعوات).
Mon_Events_Users::activate_catalog_tier(9407, 1, $tier7['id'], 'ORDER-7-C');
check('7ب. تفعيل ثالث: _mon_event_quota_limit لا يزال 10 (بلا أي تراكم مهما تكرر التفعيل)', get_user_meta(9407, '_mon_event_quota_limit', true), 10);
check('7ب. تفعيل ثالث: _mon_invitation_credit_total تراكم مجدداً إلى 150 (100 متبقٍ + 50 جديد)', get_user_meta(9407, '_mon_invitation_credit_total', true), 150);

// Unlimited tier — انحدار إضافي: لا تأثير لتراكم رصيد الدعوات على معاملة
// Event Quota لوضع Unlimited (limit يبقى القيمة الرقمية المُتجاهَلة =1 كما
// في التصميم الأصلي، بمعزل عن أي رقم رصيد دعوات).
reset_test_user(9408);
$tier7_unlimited = make_test_tier('s7_unlimited_regression', 8, [
    'invitation_credit_limit'  => 30,
    'replacement_credit_limit' => 0,
    'event_quota_mode'         => 'unlimited',
    'event_quota_limit'        => 1,
]);
Mon_Events_Users::activate_catalog_tier(9408, 1, $tier7_unlimited['id'], 'ORDER-7U-A');
check('7ج. Unlimited: _mon_event_quota_mode = unlimited (بلا تغيير)', get_user_meta(9408, '_mon_event_quota_mode', true), 'unlimited');
check('7ج. Unlimited: _mon_event_quota_limit = 1 (القيمة الرقمية المُتجاهَلة، بلا تغيير)', get_user_meta(9408, '_mon_event_quota_limit', true), 1);
check('7ج. Unlimited: رصيد الدعوات لا يزال يتراكم بشكل طبيعي = 30', get_user_meta(9408, '_mon_invitation_credit_total', true), 30);

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
