<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit وبلا أي بنية اختبارات جديدة) لمهمة
 * "Invitation Credits Engine — المرحلة الثانية: تأسيس دورة الرصيد وسجل
 * الاستهلاك الذري فقط".
 *
 * يحمّل الملفات الحقيقية التالية دون أي تعديل عليها:
 *   - includes/class-pge-catalog.php
 *   - includes/class-mon-catalog-schema.php
 *   - includes/class-mon-events-users.php
 *   - includes/class-pge-invitation-credit-ledger.php
 *
 * يحتاج هذا الملف Fake $wpdb واحد "موحَّد" يغطي ثلاثة جداول معاً (mon_plans،
 * mon_plan_tiers، mon_invitation_credit_ledger) — بخلاف الملفين الآخرين في
 * هذا المجلد (كل منهما مخصَّص لنطاق أضيق)، لأن اختبار دورة الرصيد الكاملة
 * يحتاج فعلياً: إنشاء Tier حقيقي (CRUD)، تفعيله عبر activate_catalog_tier()
 * الحقيقية (تحتاج plans/tiers)، ثم اختبار Repository السجل الذري (يحتاج
 * جدول السجل نفسه) — الثلاثة معاً في نفس تسلسل الاختبار المنطقي. محاكاة
 * القيد UNIQUE (credit_cycle_id, event_id, guest_phone, credit_type) تتم عبر
 * فهرس داخلي بسيط في الذاكرة (لا محرّك SQL عام)، كافية فقط لإثبات أن
 * create_reservation() تتعامل مع تعارض المفتاح بشكل صحيح (already_exists لا
 * فشل قاتل)، وليست بديلاً لاختبار قيد UNIQUE حقيقي على خادم MySQL فعلي.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل:
 *   php tests/test-invitation-credit-ledger.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب لتحميل الملفات الحقيقية) ─────

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

if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}

// قابلة للتحكم بها من الاختبارات (المرحلة الثالثة A — إصلاح Blocker الـLease):
// $GLOBALS['__test_now_override'] يسمح بمحاكاة تقدّم الوقت دون انتظار فعلي —
// ضروري لاختبار is_lease_expired() التي تقارن attempt_started_at المخزَّن
// (كُتب عبر current_time() وقت claim الأول) بـ"الآن" وقت claim لاحق.
$GLOBALS['__test_now_override'] = null;
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0)
    {
        return $GLOBALS['__test_now_override'] ?? '2026-01-01 00:00:00';
    }
}

// ملاحظة: pge_norm_phone() (helpers.php) عمداً غير مُحمَّلة هنا (لا حاجة
// لجرّ كل تبعياتها). PGE_Invitation_Credit_Ledger::normalize_guest_phone()
// تتحقق بـfunction_exists() وتستخدم احتياطها الخاص (إزالة كل ما ليس رقماً)
// تلقائياً — نفس المنطق فعلياً بلا أي فرق سلوكي على أرقام الاختبار هنا.

$GLOBALS['__test_user_meta']      = [];
$GLOBALS['__test_users_by_id']    = [];
$GLOBALS['__test_users_by_email'] = [];
$GLOBALS['__test_options']        = [];

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

function set_test_user_email($email, $user_id)
{
    $GLOBALS['__test_users_by_email'][$email] = $user_id;
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

function get_option($name, $default = false)
{
    return $GLOBALS['__test_options'][$name] ?? $default;
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

// ── Fake $wpdb موحَّد: mon_plans + mon_plan_tiers + mon_invitation_credit_ledger ─

class Fake_Wpdb_Ledger
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    /** @var array<int, string>|null تجاوز اختياري لأعمدة SHOW COLUMNS (محاكاة بنية ناقصة) */
    public $show_columns_override = null;
    /** @var array<int, array>|null تجاوز اختياري لصفوف SHOW INDEX (محاكاة غياب القيد) */
    public $show_index_override = null;
    /** @var bool إجبار فشل INSERT التالي على جدول Ledger (لاختبار تحرير القفل عند الخطأ) */
    public $force_ledger_insert_failure = false;
    /**
     * محاكاة أقفال GET_LOCK/RELEASE_LOCK المسمّاة — خريطة بسيطة "اسم القفل
     * محجوز أم لا" ضمن اتصال/عملية PHP واحدة. هذا **ليس** محاكاة حقيقية
     * لتزامن متعدد الاتصالات (مستحيل داخل عملية PHP متزامنة واحدة)، لكنه
     * كافٍ لاختبار: (أ) أن GET_LOCK/RELEASE_LOCK يُستدعيان بشكل متوازن دائماً
     * (حتى عند الخطأ، عبر finally)، و(ب) سلوك "القفل محجوز مسبقاً" عبر حجزه
     * يدوياً في الاختبار قبل استدعاء الدالة محل الاختبار — راجع الاختبار 20.
     */
    public $held_locks = [];

    private $plans = [];
    private $tiers = [];
    private $ledger_rows = [];
    private $ledger_unique_index = []; // "cycle|event|phone|type" => id

    private $plans_next_id = 1;
    private $tiers_next_id = 1;
    private $ledger_next_id = 1;

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
        if (strpos($sql_or_table, $this->prefix . 'mon_invitation_credit_ledger') !== false) {
            return 'ledger';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plan_tiers') !== false) {
            return 'tiers';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plans') !== false) {
            return 'plans';
        }
        return null;
    }

    private function &store_for($which)
    {
        if ($which === 'ledger') {
            return $this->ledger_rows;
        }
        if ($which === 'tiers') {
            return $this->tiers;
        }
        return $this->plans;
    }

    public function get_row($sql, $output = null)
    {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_var($sql)
    {
        // GET_LOCK(name, timeout) — راجع توثيق $held_locks أعلاه لحدود هذه المحاكاة.
        if (preg_match("/SELECT\\s+GET_LOCK\\('([^']*)',\\s*(-?\\d+)\\)/i", $sql, $m)) {
            $name = $m[1];
            if (isset($this->held_locks[$name])) {
                return '0';
            }
            $this->held_locks[$name] = true;
            return '1';
        }

        if (stripos(ltrim($sql), 'SELECT COUNT(*)') === 0) {
            $which = $this->which_table($sql);
            if ($which === null) {
                return null;
            }
            $rows = array_values($this->store_for($which));
            $filtered = $this->apply_where($rows, $sql);
            return (string) count($filtered);
        }

        return null;
    }

    /** RELEASE_LOCK(name) فقط — أي استعلام آخر غير مدعوم في هذا الـFake. */
    public function query($sql)
    {
        if (preg_match("/SELECT\\s+RELEASE_LOCK\\('([^']*)'\\)/i", $sql, $m)) {
            unset($this->held_locks[$m[1]]);
            return 1;
        }

        return false;
    }

    public function get_results($sql, $output = null)
    {
        if (stripos($sql, 'SHOW COLUMNS FROM') === 0) {
            $columns = $this->show_columns_override ?? [
                'id', 'user_id', 'credit_cycle_id', 'event_id', 'guest_phone',
                'credit_type', 'status', 'created_at', 'consumed_at', 'refunded_at',
            ];
            $out = [];
            foreach ($columns as $field) {
                $out[] = ['Field' => $field];
            }
            return $out;
        }

        if (stripos($sql, 'SHOW INDEX FROM') === 0) {
            if ($this->show_index_override !== null) {
                return $this->show_index_override;
            }
            $out = [];
            $seq = 1;
            foreach (['credit_cycle_id', 'event_id', 'guest_phone', 'credit_type'] as $col) {
                $out[] = ['Key_name' => 'unique_credit_consumption', 'Column_name' => $col, 'Seq_in_index' => $seq++];
            }
            return $out;
        }

        $which = $this->which_table($sql);
        if ($which === null) {
            return [];
        }

        $rows = array_values($this->store_for($which));
        return $this->apply_where($rows, $sql);
    }

    private function apply_where(array $rows, $sql)
    {
        if (preg_match('/WHERE\s+(.+?)(LIMIT|$)/is', $sql, $m)) {
            $where = trim($m[1]);
            $conditions = preg_split('/\bAND\b/i', $where);
            foreach ($conditions as $cond) {
                $cond = trim($cond);
                if ($cond === '') {
                    continue;
                }

                // دعم status IN ('a','b') — مطلوب لاستعلام حساب السقف
                // (reserved+consumed معاً) داخل claim_for_delivery().
                if (preg_match("/^(\\w+)\\s+IN\\s*\\(([^)]*)\\)$/i", $cond, $cm)) {
                    $field = $cm[1];
                    $values = array_map(function ($v) {
                        return trim(trim($v), "'\"");
                    }, explode(',', $cm[2]));
                    $rows = array_values(array_filter($rows, function ($r) use ($field, $values) {
                        return array_key_exists($field, $r) && in_array((string) $r[$field], $values, true);
                    }));
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

    private function ledger_unique_key(array $row)
    {
        return ($row['credit_cycle_id'] ?? '') . '|' . ($row['event_id'] ?? '') . '|' . ($row['guest_phone'] ?? '') . '|' . ($row['credit_type'] ?? '');
    }

    public function insert($table, $data, $format = null)
    {
        $this->last_error = '';
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }

        if ($which === 'ledger') {
            if ($this->force_ledger_insert_failure) {
                $this->force_ledger_insert_failure = false; // مرة واحدة فقط لكل تفعيل
                $this->last_error = 'Forced insert failure (test)';
                return false;
            }
            $key = $this->ledger_unique_key($data);
            if (isset($this->ledger_unique_index[$key])) {
                $this->last_error = "Duplicate entry for key 'unique_credit_consumption'";
                return false;
            }
            $id = $this->ledger_next_id++;
            $row = array_merge(['id' => $id, 'consumed_at' => null, 'refunded_at' => null, 'attempt_token' => null, 'attempt_started_at' => null, 'last_attempt_at' => null], $data);
            $this->ledger_rows[$id] = $row;
            $this->ledger_unique_index[$key] = $id;
            $this->insert_id = $id;
            return 1;
        }

        if ($which === 'tiers') {
            $id = $this->tiers_next_id++;
            $this->tiers[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }

        $id = $this->plans_next_id++;
        $this->plans[$id] = array_merge(['id' => $id], $data);
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

        if ($which === 'ledger') {
            if (!isset($this->ledger_rows[$id])) {
                return 0;
            }
            // مطابقة عامة لكل مفاتيح WHERE الإضافية (status، attempt_token،
            // ...) معاً — نفس سلوك $wpdb->update() الحقيقي (AND بين كل شرط).
            foreach ($where as $where_key => $where_value) {
                if ($where_key === 'id') {
                    continue;
                }
                $current_value = $this->ledger_rows[$id][$where_key] ?? null;
                if ((string) $current_value !== (string) $where_value) {
                    return 0;
                }
            }
            foreach ($data as $k => $v) {
                $this->ledger_rows[$id][$k] = $v;
            }
            return 1;
        }

        if ($which === 'tiers') {
            if (!isset($this->tiers[$id])) {
                return 0;
            }
            foreach ($data as $k => $v) {
                $this->tiers[$id][$k] = $v;
            }
            return 1;
        }

        if (!isset($this->plans[$id])) {
            return 0;
        }
        foreach ($data as $k => $v) {
            $this->plans[$id][$k] = $v;
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

$GLOBALS['wpdb'] = new Fake_Wpdb_Ledger();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── تحميل الملفات الحقيقية من المشروع (بلا أي تعديل عليها) ─────────────────

require_once __DIR__ . '/../includes/class-pge-catalog.php';
require_once __DIR__ . '/../includes/class-mon-catalog-schema.php';
require_once __DIR__ . '/../includes/class-pge-feature-registry.php';
require_once __DIR__ . '/../includes/class-pge-tier-features.php';
require_once __DIR__ . '/../includes/class-mon-events-users.php';
require_once __DIR__ . '/../includes/class-pge-invitation-credit-ledger.php';

// ── أدوات الاختبار ──────────────────────────────────────────────────────────

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

$wpdb->seed_plan(1, [
    'plan_key'  => 'basic_plan',
    'name'      => 'باقة أساسية',
    'plan_type' => 'personal',
    'status'    => 'active',
]);

echo "=== قسم Schema: جدول mon_invitation_credit_ledger ===\n";

// ── Schema (1-6) ────────────────────────────────────────────────────────────

$schema_sql_ref = new ReflectionMethod('Mon_Catalog_Schema', 'get_schema_sql');
$schema_sql_ref->setAccessible(true);
$schema_sql_parts = $schema_sql_ref->invoke(null);
$ledger_sql = $schema_sql_parts[3] ?? '';

check_true('1. الجدول mon_invitation_credit_ledger موجود في نص Schema', strpos($ledger_sql, 'mon_invitation_credit_ledger') !== false);

$required_ledger_columns = ['id', 'user_id', 'credit_cycle_id', 'event_id', 'guest_phone', 'credit_type', 'status', 'created_at', 'consumed_at', 'refunded_at'];
$all_columns_present = true;
foreach ($required_ledger_columns as $col) {
    if (strpos($ledger_sql, $col) === false) {
        $all_columns_present = false;
        break;
    }
}
check_true('2. جميع الأعمدة المطلوبة موجودة في نص Schema', $all_columns_present);

check_true('3. UNIQUE KEY unique_credit_consumption يحتوي الأعمدة الأربعة بالترتيب الصحيح', strpos($ledger_sql, 'UNIQUE KEY unique_credit_consumption (credit_cycle_id, event_id, guest_phone, credit_type)') !== false);

$upgrade_150_ref = new ReflectionMethod('Mon_Catalog_Schema', 'upgrade_to_1_5_0');
$upgrade_150_ref->setAccessible(true);

$wpdb->show_columns_override = null;
$wpdb->show_index_override = null;
check_true('4. upgrade_to_1_5_0() ينجح عند اكتمال البنية (أعمدة + قيد UNIQUE صحيحان)', $upgrade_150_ref->invoke(null) === true);

$wpdb->show_index_override = []; // القيد UNIQUE غائب تماماً
check_true('5. upgrade_to_1_5_0() يفشل بأمان عند غياب القيد UNIQUE', $upgrade_150_ref->invoke(null) === false);

$wpdb->show_index_override = null; // إعادة الحالة الطبيعية
check_true('6. upgrade_to_1_5_0() Idempotent — يعود true بعد اكتمال البنية مجدداً، وتكراره لا أثر إضافي له', $upgrade_150_ref->invoke(null) === true && $upgrade_150_ref->invoke(null) === true);

echo "\n=== قسم Credit Cycle: _mon_credit_cycle_id عند activate_catalog_tier() ===\n";

// ── Credit cycle (7-10) ──────────────────────────────────────────────────────

$cycle_tier_a = PGE_Catalog::create_tier([
    'plan_id' => 1, 'tier_key' => 'cycle_tier_a', 'name' => 'مستوى دورة أ',
    'price' => 100, 'currency' => 'SAR', 'salla_product_id' => null,
    'status' => 'active', 'sort_order' => 1,
    'invitation_credit_limit' => 50, 'replacement_credit_limit' => 10,
]);

set_test_user_id(9101);
reset_test_user(9101);

$activation_1 = Mon_Events_Users::activate_catalog_tier(9101, 1, $cycle_tier_a['id']);
check_true('7. activate_catalog_tier() الأول نجح', $activation_1 === true);
$cycle_id_1 = get_user_meta(9101, '_mon_credit_cycle_id', true);
check_true('7. _mon_credit_cycle_id غير فارغ بعد التفعيل', is_string($cycle_id_1) && $cycle_id_1 !== '');
check_true('7. _mon_credit_cycle_id بطول UUID القياسي (36 حرفاً)', strlen($cycle_id_1) === 36);

$cycle_tier_b = PGE_Catalog::create_tier([
    'plan_id' => 1, 'tier_key' => 'cycle_tier_b', 'name' => 'مستوى دورة ب',
    'price' => 150, 'currency' => 'SAR', 'salla_product_id' => null,
    'status' => 'active', 'sort_order' => 2,
    'invitation_credit_limit' => 80, 'replacement_credit_limit' => 15,
]);

$activation_2 = Mon_Events_Users::activate_catalog_tier(9101, 1, $cycle_tier_b['id']);
check_true('8. إعادة تفعيل Tier مختلف نجحت', $activation_2 === true);
$cycle_id_2 = get_user_meta(9101, '_mon_credit_cycle_id', true);
check_true('8. إعادة التفعيل تولد credit_cycle_id مختلفاً تماماً عن السابق', $cycle_id_2 !== '' && $cycle_id_2 !== $cycle_id_1);

$GLOBALS['__test_options']['mon_packages_settings'] = [
    'plan_1' => ['name' => 'باقة Legacy', 'guest_limit' => 20, 'host_photos' => 5, 'events_count' => 2, 'wa_messages' => 0],
];
reset_test_user(9102);
set_test_user_email('cycle-legacy-test@example.test', 9102);
Mon_Events_Users::activate_user_package('cycle-legacy-test@example.test', ['plan_key' => 'plan_1', 'order_id' => 'ORDER-CYCLE-TEST']);
check('9. تفعيل Legacy لا يكتب _mon_credit_cycle_id إطلاقاً', get_user_meta(9102, '_mon_credit_cycle_id', true), '');

check('10. بقية Snapshot صحيحة بعد إعادة التفعيل: invitation_credit_total = 80 (Tier الثاني)', (int) get_user_meta(9101, '_mon_invitation_credit_total', true), 80);
check('10. بقية Snapshot صحيحة: invitation_credit_used = 0', (int) get_user_meta(9101, '_mon_invitation_credit_used', true), 0);
check('10. بقية Snapshot صحيحة: replacement_credit_total = 15 (Tier الثاني)', (int) get_user_meta(9101, '_mon_replacement_credit_total', true), 15);
check('10. بقية Snapشot صحيحة: replacement_credit_used = 0', (int) get_user_meta(9101, '_mon_replacement_credit_used', true), 0);

echo "\n=== قسم Repository: PGE_Invitation_Credit_Ledger ===\n";

// ── Repository (11-26) ───────────────────────────────────────────────────────

$res_11 = PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-A', 501, '0501234567', 'primary');
check('11. create_reservation() نتيجة created', $res_11['result'] ?? null, 'created');
check_true('11. create_reservation() يعيد id صالحاً موجباً', isset($res_11['id']) && $res_11['id'] > 0);
$entry_11 = PGE_Invitation_Credit_Ledger::find_entry('CYCLE-A', 501, '0501234567', 'primary');
check_true('11. الصف المُنشأ موجود فعلاً بحالة reserved', $entry_11 !== null && $entry_11['status'] === 'reserved');
check('11. credit_type المخزَّن = primary', $entry_11['credit_type'] ?? null, 'primary');

$res_12 = PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-A', 501, '0501234567', 'primary');
check('12. تكرار نفس المفتاح الرباعي بالضبط → already_exists (لا صف ثانٍ)', $res_12['result'] ?? null, 'already_exists');
check('12. already_exists يعيد نفس id الصف الأصلي', $res_12['id'] ?? null, $res_11['id'] ?? null);

$res_13 = PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-A', 502, '0501234567', 'primary');
check('13. نفس الجوال بمناسبة أخرى (event_id مختلف) → created (صف مستقل)', $res_13['result'] ?? null, 'created');
check_true('13. id مختلف عن صف المناسبة الأولى', ($res_13['id'] ?? null) !== ($res_11['id'] ?? null));

$res_14 = PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-B', 501, '0501234567', 'primary');
check('14. نفس الجوال/المناسبة بدورة رصيد أخرى (credit_cycle_id مختلف) → created (صف مستقل)', $res_14['result'] ?? null, 'created');

$res_15 = PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-A', 501, '0501234567', 'replacement');
check('15. نفس (cycle,event,phone) لكن credit_type=replacement → created (منفصل عن primary)', $res_15['result'] ?? null, 'created');
check_true('15. id مختلف عن صف primary لنفس (cycle,event,phone)', ($res_15['id'] ?? null) !== ($res_11['id'] ?? null));

$res_16 = PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-A', 503, '+966 050-123-4567', 'primary');
check('16. create_reservation() نجح مع جوال بصيغة غير مطبَّعة', $res_16['result'] ?? null, 'created');
$entry_16 = PGE_Invitation_Credit_Ledger::find_entry('CYCLE-A', 503, '9660501234567', 'primary');
check_true('16. الجوال طُبِّع قبل الحفظ (أرقام فقط، لا مسافات/رموز)', $entry_16 !== null);

check('17. create_reservation() بـuser_id=0 → error', (PGE_Invitation_Credit_Ledger::create_reservation(0, 'CYCLE-A', 501, '0501234567', 'primary'))['result'] ?? null, 'error');
check('17. create_reservation() بـevent_id غير صالح (0) → error', (PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-A', 0, '0501234567', 'primary'))['result'] ?? null, 'error');
check('17. create_reservation() بـcredit_cycle_id فارغ → error', (PGE_Invitation_Credit_Ledger::create_reservation(9101, '', 501, '0501234567', 'primary'))['result'] ?? null, 'error');
check('17. create_reservation() بجوال يصبح فارغاً بعد التطبيع (abc) → error', (PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-A', 501, 'abc', 'primary'))['result'] ?? null, 'error');
check('17. create_reservation() بـcredit_type غير معتمد (bonus) → error', (PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-A', 501, '0501234567', 'bonus'))['result'] ?? null, 'error');

$mc_18 = PGE_Invitation_Credit_Ledger::mark_consumed($res_11['id']);
check_true('18. mark_consumed() على reserved يعيد true', $mc_18 === true);
$entry_18 = PGE_Invitation_Credit_Ledger::find_entry('CYCLE-A', 501, '0501234567', 'primary');
check('18. status أصبحت consumed', $entry_18['status'] ?? null, 'consumed');
check_true('18. consumed_at مضبوط (غير فارغ)', !empty($entry_18['consumed_at']));

$mc_19 = PGE_Invitation_Credit_Ledger::mark_consumed($res_11['id']);
check_true('19. mark_consumed() مرة ثانية على consumed بالفعل → true (Idempotent)', $mc_19 === true);
check('19. status تبقى consumed دون تغيير', PGE_Invitation_Credit_Ledger::find_entry('CYCLE-A', 501, '0501234567', 'primary')['status'] ?? null, 'consumed');

PGE_Invitation_Credit_Ledger::mark_consumed($res_13['id']);
$refund_prep_13 = PGE_Invitation_Credit_Ledger::mark_refunded($res_13['id']);
check_true('تمهيد لـ20: mark_refunded على consumed ينجح فعلاً (تأكيد قبل اختبار الحظر)', $refund_prep_13 === true);
$mc_20 = PGE_Invitation_Credit_Ledger::mark_consumed($res_13['id']);
check_true('20. mark_consumed() على صف refunded → false (refunded لا يعود consumed)', $mc_20 === false);
check('20. status تبقى refunded دون أي تغيير', PGE_Invitation_Credit_Ledger::find_entry('CYCLE-A', 502, '0501234567', 'primary')['status'] ?? null, 'refunded');

PGE_Invitation_Credit_Ledger::mark_consumed($res_15['id']);
$mr_21 = PGE_Invitation_Credit_Ledger::mark_refunded($res_15['id']);
check_true('21. mark_refunded() على consumed يعيد true', $mr_21 === true);
check('21. status أصبحت refunded', PGE_Invitation_Credit_Ledger::find_entry('CYCLE-A', 501, '0501234567', 'replacement')['status'] ?? null, 'refunded');

$mr_22 = PGE_Invitation_Credit_Ledger::mark_refunded($res_15['id']);
check_true('22. mark_refunded() مرة ثانية على refunded بالفعل → true (Idempotent)', $mr_22 === true);

$mr_23 = PGE_Invitation_Credit_Ledger::mark_refunded($res_14['id']);
check_true('23. mark_refunded() على صف لا يزال reserved → false (reserved لا يتحول refunded مباشرة)', $mr_23 === false);
check('23. status تبقى reserved دون أي تغيير', PGE_Invitation_Credit_Ledger::find_entry('CYCLE-B', 501, '0501234567', 'primary')['status'] ?? null, 'reserved');

// بيانات معزولة مخصصة لاختبارَي العدّ (24، 25) لتفادي الاعتماد على التسلسل
// الدقيق لتحولات lifecycle السابقة (18-23).
PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-COUNT', 601, '0511111111', 'primary'); // يبقى reserved
$count_res_2 = PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-COUNT', 602, '0522222222', 'primary');
PGE_Invitation_Credit_Ledger::mark_consumed($count_res_2['id']);
$count_res_3 = PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-COUNT', 603, '0533333333', 'primary');
PGE_Invitation_Credit_Ledger::mark_consumed($count_res_3['id']);
PGE_Invitation_Credit_Ledger::create_reservation(9101, 'CYCLE-COUNT', 604, '0544444444', 'replacement'); // يبقى reserved

check('24. count_consumed(9101, CYCLE-COUNT, primary) = 2', PGE_Invitation_Credit_Ledger::count_consumed(9101, 'CYCLE-COUNT', 'primary'), 2);
check('24. count_consumed(9101, CYCLE-COUNT, replacement) = 0 (الصف الوحيد ما زال reserved)', PGE_Invitation_Credit_Ledger::count_consumed(9101, 'CYCLE-COUNT', 'replacement'), 0);
check('24. count_consumed لمستخدم آخر (9999) في نفس الدورة = 0 (لا تسرّب بين المستخدمين)', PGE_Invitation_Credit_Ledger::count_consumed(9999, 'CYCLE-COUNT', 'primary'), 0);

check('25. count_reserved(9101, CYCLE-COUNT, primary) = 1 (الصف الأول فقط لم يُستهلَك)', PGE_Invitation_Credit_Ledger::count_reserved(9101, 'CYCLE-COUNT', 'primary'), 1);
check('25. count_reserved(9101, CYCLE-COUNT, replacement) = 1', PGE_Invitation_Credit_Ledger::count_reserved(9101, 'CYCLE-COUNT', 'replacement'), 1);

check('26. _mon_invitation_credit_used لم يتأثر بأي عملية Ledger (يبقى 0)', (int) get_user_meta(9101, '_mon_invitation_credit_used', true), 0);
check('26. _mon_replacement_credit_used لم يتأثر بأي عملية Ledger (يبقى 0)', (int) get_user_meta(9101, '_mon_replacement_credit_used', true), 0);

// فحوصات إضافية دفاعية (خارج الترقيم المطلوب) — id غير صالح/غير موجود.
check_true('إضافي: mark_consumed(0) → false', PGE_Invitation_Credit_Ledger::mark_consumed(0) === false);
check_true('إضافي: mark_consumed(999999 غير موجود) → false', PGE_Invitation_Credit_Ledger::mark_consumed(999999) === false);
check_true('إضافي: mark_refunded(0) → false', PGE_Invitation_Credit_Ledger::mark_refunded(0) === false);

echo "\n=== المرحلة الثالثة A: Schema 1.6.0 (محاولة تسليم مملوكة) ===\n";

// ── Schema 1.6.0 (1-5) ──────────────────────────────────────────────────────

// DB_VERSION الحالي أصبح 1.7.0 (رُفع لاحقاً في المرحلة 4A عند إضافة جدول
// mon_replacement_entitlements — راجع class-mon-catalog-schema.php) — أعمدة
// attempt_token/attempt_started_at/last_attempt_at أدناه أُضيفت أصلاً ضمن
// 1.6.0، لكن الثابت نفسه لم يعد يحمل تلك القيمة، فالتحقق يجب أن يطابق القيمة
// الفعلية الحالية للثابت لا القيمة التاريخية وقت كتابة هذا القسم.
check('المرحلة 3A - 1. DB_VERSION = 1.7.0 (القيمة الحالية للثابت)', Mon_Catalog_Schema::DB_VERSION, '1.7.0');

$has_new_columns_in_sql = strpos($ledger_sql, 'attempt_token') !== false
    && strpos($ledger_sql, 'attempt_started_at') !== false
    && strpos($ledger_sql, 'last_attempt_at') !== false;
check_true('المرحلة 3A - 2. الأعمدة الثلاثة الجديدة موجودة في نص Schema', $has_new_columns_in_sql);

$upgrade_160_ref = new ReflectionMethod('Mon_Catalog_Schema', 'upgrade_to_1_6_0');
$upgrade_160_ref->setAccessible(true);

$full_160_columns = [
    'id', 'user_id', 'credit_cycle_id', 'event_id', 'guest_phone',
    'credit_type', 'status', 'attempt_token', 'attempt_started_at', 'last_attempt_at',
    'created_at', 'consumed_at', 'refunded_at',
];

$wpdb->show_columns_override = $full_160_columns;
check_true('المرحلة 3A - 3. upgrade_to_1_6_0() ينجح عند اكتمال الأعمدة الثلاثة', $upgrade_160_ref->invoke(null) === true);

$missing_one_column = array_values(array_diff($full_160_columns, ['last_attempt_at']));
$wpdb->show_columns_override = $missing_one_column;
check_true('المرحلة 3A - 4. upgrade_to_1_6_0() يفشل بأمان عند غياب عمود واحد فقط (last_attempt_at)', $upgrade_160_ref->invoke(null) === false);

$wpdb->show_columns_override = $full_160_columns;
check_true('المرحلة 3A - 5. upgrade_to_1_6_0() Idempotent — نجاح متكرر بلا أثر إضافي', $upgrade_160_ref->invoke(null) === true && $upgrade_160_ref->invoke(null) === true);

echo "\n=== المرحلة الثالثة A: Atomic Claim (claim_for_delivery وما يتبعها) ===\n";

// ── Atomic claim (6-20) ──────────────────────────────────────────────────────
// ملاحظة منهجية: GET_LOCK/RELEASE_LOCK هنا Fake بسيط (خريطة "محجوز/غير
// محجوز" ضمن عملية PHP واحدة متزامنة) — لا يحاكي تزامناً حقيقياً بين
// اتصالين منفصلين (مستحيل داخل سكربت PHP واحد متتابع). الاختبارات أدناه
// تتحقق من: (أ) منطق الحالة الصحيح عبر استدعاءات متتابعة تحاكي ما يفعله
// منطق الأعمال عند التزامن الفعلي، و(ب) توازن GET_LOCK/RELEASE_LOCK نفسه
// (اختبار 20) عبر حجز/تحرير يدوي مباشر — لا اختباراً لسلوك MySQL الحقيقي
// عبر اتصالات متعددة فعلياً.

$claim_6 = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 701, '0561111111', 'primary', 10);
check('المرحلة 3A - 6. أول claim يعيد claimed', $claim_6['result'] ?? null, 'claimed');
check_true('المرحلة 3A - 6. claim يعيد id صالحاً موجباً', isset($claim_6['id']) && $claim_6['id'] > 0);
check_true('المرحلة 3A - 6. claim يعيد attempt_token غير فارغ', !empty($claim_6['attempt_token']));

$claim_7 = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 701, '0561111111', 'primary', 10);
check('المرحلة 3A - 7. claim ثانٍ على reserved نشط (بلا إنهاء) يعيد in_progress', $claim_7['result'] ?? null, 'in_progress');
check('المرحلة 3A - 7. in_progress يعيد نفس id الصف', $claim_7['id'] ?? null, $claim_6['id'] ?? null);

$mc_8 = PGE_Invitation_Credit_Ledger::mark_consumed_with_token($claim_6['id'], $claim_6['attempt_token']);
check_true('المرحلة 3A - 8. تمهيد: mark_consumed_with_token بتوكن صحيح ينجح', $mc_8 === true);
$claim_8b = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 701, '0561111111', 'primary', 10);
check('المرحلة 3A - 8. claim على صف consumed يعيد already_consumed', $claim_8b['result'] ?? null, 'already_consumed');
check('المرحلة 3A - 8. already_consumed يعيد نفس id', $claim_8b['id'] ?? null, $claim_6['id'] ?? null);

$claim_9a = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 702, '0562222222', 'primary', 10);
check('المرحلة 3A - 9. تمهيد: claim أول لصف جديد', $claim_9a['result'] ?? null, 'claimed');
$mf_9 = PGE_Invitation_Credit_Ledger::mark_failed_with_token($claim_9a['id'], $claim_9a['attempt_token']);
check_true('المرحلة 3A - 9. تمهيد: mark_failed_with_token بتوكن صحيح ينجح', $mf_9 === true);
$claim_9b = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 702, '0562222222', 'primary', 10);
check('المرحلة 3A - 9. claim على صف failed يعيد claimed مجدداً', $claim_9b['result'] ?? null, 'claimed');
check('المرحلة 3A - 9. failed يُعاد استخدام نفس id الصف', $claim_9b['id'] ?? null, $claim_9a['id'] ?? null);
check_true('المرحلة 3A - 9. توكن جديد مختلف تماماً عن السابق', !empty($claim_9b['attempt_token']) && $claim_9b['attempt_token'] !== $claim_9a['attempt_token']);

PGE_Invitation_Credit_Ledger::mark_consumed_with_token($claim_9b['id'], $claim_9b['attempt_token']);
$refunded_10 = PGE_Invitation_Credit_Ledger::mark_refunded($claim_9b['id']); // الدالة القديمة من المرحلة الثانية — عقدها بلا أي تغيير
check_true('المرحلة 3A - 10. تمهيد: mark_refunded (القديمة) تنجح على consumed', $refunded_10 === true);
$claim_10 = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 702, '0562222222', 'primary', 10);
check('المرحلة 3A - 10. claim على صف refunded → error (لا يُعاد استخدامه كدعوة جديدة)', $claim_10['result'] ?? null, 'error');
check('المرحلة 3A - 10. سبب الرفض refunded_reuse_not_allowed', $claim_10['reason'] ?? null, 'refunded_reuse_not_allowed');

$claim_11 = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 703, '0563333333', 'primary', 10);
$mc_11 = PGE_Invitation_Credit_Ledger::mark_consumed_with_token($claim_11['id'], $claim_11['attempt_token']);
check_true('المرحلة 3A - 11. تطابق token مطلوب للاستهلاك: توكن صحيح ينجح', $mc_11 === true);
check('المرحلة 3A - 11. status أصبحت consumed', PGE_Invitation_Credit_Ledger::find_entry('CLAIM-CYCLE-A', 703, '0563333333', 'primary')['status'] ?? null, 'consumed');

$claim_12 = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 704, '0564444444', 'primary', 10);
$mc_12 = PGE_Invitation_Credit_Ledger::mark_consumed_with_token($claim_12['id'], 'WRONG-TOKEN-VALUE');
check_true('المرحلة 3A - 12. token خاطئ لا يستهلك (يعيد false)', $mc_12 === false);
check('المرحلة 3A - 12. status تبقى reserved دون تغيير', PGE_Invitation_Credit_Ledger::find_entry('CLAIM-CYCLE-A', 704, '0564444444', 'primary')['status'] ?? null, 'reserved');

$claim_13a    = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 705, '0565555555', 'primary', 10);
$token_13_old = $claim_13a['attempt_token'];
PGE_Invitation_Credit_Ledger::mark_failed_with_token($claim_13a['id'], $token_13_old);
$claim_13b    = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 705, '0565555555', 'primary', 10);
$token_13_new = $claim_13b['attempt_token'];
$mc_13_stale  = PGE_Invitation_Credit_Ledger::mark_consumed_with_token($claim_13a['id'], $token_13_old);
check_true('المرحلة 3A - 13. توكن قديم (من محاولة سابقة) لا ينهي محاولة أحدث', $mc_13_stale === false);
check('المرحلة 3A - 13. status تبقى reserved (المحاولة الأحدث لم تتأثر بالتوكن القديم)', PGE_Invitation_Credit_Ledger::find_entry('CLAIM-CYCLE-A', 705, '0565555555', 'primary')['status'] ?? null, 'reserved');
$mc_13_correct = PGE_Invitation_Credit_Ledger::mark_consumed_with_token($claim_13b['id'], $token_13_new);
check_true('المرحلة 3A - 13. التوكن الأحدث الصحيح ينهي المحاولة بنجاح', $mc_13_correct === true);

$claim_14      = PGE_Invitation_Credit_Ledger::claim_for_delivery(9201, 'CLAIM-CYCLE-A', 706, '0566666666', 'primary', 10);
$mf_14_wrong   = PGE_Invitation_Credit_Ledger::mark_failed_with_token($claim_14['id'], 'ANOTHER-WRONG-TOKEN');
check_true('المرحلة 3A - 14. mark_failed_with_token بتوكن خاطئ يعيد false', $mf_14_wrong === false);
check('المرحلة 3A - 14. status تبقى reserved', PGE_Invitation_Credit_Ledger::find_entry('CLAIM-CYCLE-A', 706, '0566666666', 'primary')['status'] ?? null, 'reserved');
$mf_14_correct = PGE_Invitation_Credit_Ledger::mark_failed_with_token($claim_14['id'], $claim_14['attempt_token']);
check_true('المرحلة 3A - 14. mark_failed_with_token بتوكن صحيح يتطلب تطابقاً وينجح فعلاً', $mf_14_correct === true);
check('المرحلة 3A - 14. status أصبحت failed', PGE_Invitation_Credit_Ledger::find_entry('CLAIM-CYCLE-A', 706, '0566666666', 'primary')['status'] ?? null, 'failed');

$claim_15 = PGE_Invitation_Credit_Ledger::claim_for_delivery(9202, 'CLAIM-CYCLE-CAP-ZERO', 710, '0570000000', 'primary', 0);
check('المرحلة 3A - 15. limit=0 يعيد limit_exceeded فوراً لأي صف جديد', $claim_15['result'] ?? null, 'limit_exceeded');

$user_1617  = 9203;
$cycle_1617 = 'CLAIM-CYCLE-1617';
$limit_1617 = 2;

$claim_16a = PGE_Invitation_Credit_Ledger::claim_for_delivery($user_1617, $cycle_1617, 720, '0571111111', 'primary', $limit_1617);
check('المرحلة 3A - 16. تمهيد: أول حجز (1/2) ينجح', $claim_16a['result'] ?? null, 'claimed');
$claim_16b = PGE_Invitation_Credit_Ledger::claim_for_delivery($user_1617, $cycle_1617, 721, '0572222222', 'primary', $limit_1617);
check('المرحلة 3A - 16. تمهيد: ثانٍ حجز (2/2) ينجح ويبلغ السقف', $claim_16b['result'] ?? null, 'claimed');
PGE_Invitation_Credit_Ledger::mark_consumed_with_token($claim_16b['id'], $claim_16b['attempt_token']);
$claim_16c = PGE_Invitation_Credit_Ledger::claim_for_delivery($user_1617, $cycle_1617, 722, '0573333333', 'primary', $limit_1617);
check('المرحلة 3A - 16. consumed(1)+reserved(1) عند الحد (2) يمنع حجزاً جديداً', $claim_16c['result'] ?? null, 'limit_exceeded');

$claim_17 = PGE_Invitation_Credit_Ledger::claim_for_delivery($user_1617, $cycle_1617, 999999, '0579999999', 'primary', $limit_1617);
check('المرحلة 3A - 17. اختلاف event_id لا يتجاوز الحد الإجمالي (limit_exceeded أيضاً لنفس المستخدم/الدورة/النوع)', $claim_17['result'] ?? null, 'limit_exceeded');

$claim_18 = PGE_Invitation_Credit_Ledger::claim_for_delivery($user_1617, 'CLAIM-CYCLE-1617-OTHER', 720, '0571111111', 'primary', $limit_1617);
check('المرحلة 3A - 18. اختلاف credit_cycle_id يفصل الرصيد (ينجح رغم امتلاء الدورة الأخرى)', $claim_18['result'] ?? null, 'claimed');

$claim_19 = PGE_Invitation_Credit_Ledger::claim_for_delivery($user_1617, $cycle_1617, 720, '0571111111', 'replacement', $limit_1617);
check('المرحلة 3A - 19. اختلاف credit_type يفصل الرصيد (primary ممتلئ لكن replacement ينجح)', $claim_19['result'] ?? null, 'claimed');

$wpdb->force_ledger_insert_failure = true;
$claim_20 = PGE_Invitation_Credit_Ledger::claim_for_delivery(9204, 'CLAIM-CYCLE-LOCK', 730, '0580000000', 'primary', 10);
check('المرحلة 3A - 20. تمهيد: فشل INSERT مفتعل داخل القفل ينتج error', $claim_20['result'] ?? null, 'error');
$lock_name_20 = 'pge_credit_' . md5('9204|CLAIM-CYCLE-LOCK|primary');
$relock_20    = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name_20, 1));
check('المرحلة 3A - 20. القفل يُحرر حتى عند الخطأ (يمكن الحصول عليه فوراً بعد الفشل)', (string) $relock_20, '1');
$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name_20)); // تنظيف

echo "\n=== إصلاح Blocker: Attempt Lease (انتهاء صلاحية محاولة reserved) ===\n";

// ── Attempt Lease (1-8، 11، 12 من طلب الإصلاح) ─────────────────────────────
// $GLOBALS['__test_now_override'] يسمح بمحاكاة تقدّم الوقت دون انتظار فعلي —
// راجع current_time() أعلى الملف. ATTEMPT_LEASE_SECONDS = 120 ثانية.

check('Lease - الثابت ATTEMPT_LEASE_SECONDS = 120', PGE_Invitation_Credit_Ledger::ATTEMPT_LEASE_SECONDS, 120);

$GLOBALS['__test_now_override'] = '2026-01-01 00:00:00';
$lease_claim_1 = PGE_Invitation_Credit_Ledger::claim_for_delivery(9205, 'LEASE-CYCLE-A', 740, '0581111111', 'primary', 10);
check('Lease - تمهيد: أول claim ينجح', $lease_claim_1['result'] ?? null, 'claimed');

// لا يزال ضمن المهلة (فارق 0 ثانية < 120)
$lease_claim_1b = PGE_Invitation_Credit_Ledger::claim_for_delivery(9205, 'LEASE-CYCLE-A', 740, '0581111111', 'primary', 10);
check('1. reserved بتوكن حديث (ضمن المهلة) → in_progress', $lease_claim_1b['result'] ?? null, 'in_progress');

// نُقدّم "الآن" 130 ثانية (أكبر من 120) لمحاكاة انتهاء المهلة دون انتظار فعلي.
$GLOBALS['__test_now_override'] = '2026-01-01 00:02:10';
$old_token_lease   = $lease_claim_1['attempt_token'];
$old_started_lease = PGE_Invitation_Credit_Ledger::find_entry('LEASE-CYCLE-A', 740, '0581111111', 'primary')['attempt_started_at'] ?? null;

$lease_claim_2 = PGE_Invitation_Credit_Ledger::claim_for_delivery(9205, 'LEASE-CYCLE-A', 740, '0581111111', 'primary', 10);
check('2. reserved بتوكن منتهي المهلة → claimed بتوكن جديد (نفس الصف)', $lease_claim_2['result'] ?? null, 'claimed');
check('2. نفس id الصف — لا صف جديد', $lease_claim_2['id'] ?? null, $lease_claim_1['id'] ?? null);
check_true('3. التوكن الجديد مختلف تماماً عن القديم', !empty($lease_claim_2['attempt_token']) && $lease_claim_2['attempt_token'] !== $old_token_lease);

$entry_after_lease_2 = PGE_Invitation_Credit_Ledger::find_entry('LEASE-CYCLE-A', 740, '0581111111', 'primary');
check_true('4. attempt_started_at تحدّثت عند إعادة Claim', ($entry_after_lease_2['attempt_started_at'] ?? null) !== $old_started_lease);
check('4. attempt_started_at الجديدة = وقت إعادة Claim بالضبط', $entry_after_lease_2['attempt_started_at'] ?? null, '2026-01-01 00:02:10');

$mc_old_lease = PGE_Invitation_Credit_Ledger::mark_consumed_with_token($lease_claim_1['id'], $old_token_lease);
check_true('5. العامل القديم لا يستطيع mark_consumed بتوكنه بعد انتهاء Lease وإعادة Claim', $mc_old_lease === false);

$mf_old_lease = PGE_Invitation_Credit_Ledger::mark_failed_with_token($lease_claim_1['id'], $old_token_lease);
check_true('6. العامل القديم لا يستطيع mark_failed بتوكنه بعد انتهاء Lease وإعادة Claim', $mf_old_lease === false);

check('5/6. status تبقى reserved طوال محاولات العامل القديم', PGE_Invitation_Credit_Ledger::find_entry('LEASE-CYCLE-A', 740, '0581111111', 'primary')['status'] ?? null, 'reserved');

$mc_new_lease = PGE_Invitation_Credit_Ledger::mark_consumed_with_token($lease_claim_2['id'], $lease_claim_2['attempt_token']);
check_true('7. العامل الجديد (بالتوكن الصحيح الحالي) يستطيع mark_consumed بنجاح', $mc_new_lease === true);
check('7. status أصبحت consumed', PGE_Invitation_Credit_Ledger::find_entry('LEASE-CYCLE-A', 740, '0581111111', 'primary')['status'] ?? null, 'consumed');

// 8) reserved بلا attempt_token إطلاقاً (صف من create_reservation() القديمة) يُعامَل كغير مملوك.
$GLOBALS['__test_now_override'] = null; // إعادة الوقت الافتراضي
$old_res_lease_8 = PGE_Invitation_Credit_Ledger::create_reservation(9205, 'LEASE-CYCLE-A', 741, '0582222222', 'primary');
check('8. تمهيد: create_reservation القديمة أنشأت صفاً reserved', $old_res_lease_8['result'] ?? null, 'created');
$entry_lease_8_before = PGE_Invitation_Credit_Ledger::find_entry('LEASE-CYCLE-A', 741, '0582222222', 'primary');
check_true('8. تمهيد: الصف بلا attempt_token فعلاً', empty($entry_lease_8_before['attempt_token']));
$lease_claim_8 = PGE_Invitation_Credit_Ledger::claim_for_delivery(9205, 'LEASE-CYCLE-A', 741, '0582222222', 'primary', 10);
check('8. reserved بلا attempt_token → claimed مباشرة (بلا انتظار أي مهلة)', $lease_claim_8['result'] ?? null, 'claimed');
check('8. نفس id الصف القديم — لا صف جديد', $lease_claim_8['id'] ?? null, $old_res_lease_8['id'] ?? null);

// 11/12) سيناريو مستقل ونظيف للتحقق الصريح من عدم إنشاء صف ثانٍ عند انتهاء المهلة.
$GLOBALS['__test_now_override'] = '2026-01-01 00:00:00';
$lease_claim_11a = PGE_Invitation_Credit_Ledger::claim_for_delivery(9206, 'LEASE-CYCLE-B', 742, '0583333333', 'primary', 10);
check('11/12. تمهيد: أول claim لمفتاح جديد', $lease_claim_11a['result'] ?? null, 'claimed');
check('11/12. تمهيد: reserved واحد فقط قبل انتهاء المهلة', PGE_Invitation_Credit_Ledger::count_reserved(9206, 'LEASE-CYCLE-B', 'primary'), 1);

$GLOBALS['__test_now_override'] = '2026-01-01 00:02:10'; // +130 ثانية، تنتهي المهلة
$lease_claim_11b = PGE_Invitation_Credit_Ledger::claim_for_delivery(9206, 'LEASE-CYCLE-B', 742, '0583333333', 'primary', 10);
check('11. لا إنشاء صف Ledger ثانٍ عند انتهاء Lease (نفس id)', $lease_claim_11b['id'] ?? null, $lease_claim_11a['id'] ?? null);
check(
    '12. العدد الإجمالي (reserved+consumed) لنفس المفتاح الرباعي يبقى 1 بعد إعادة Claim',
    PGE_Invitation_Credit_Ledger::count_reserved(9206, 'LEASE-CYCLE-B', 'primary') + PGE_Invitation_Credit_Ledger::count_consumed(9206, 'LEASE-CYCLE-B', 'primary'),
    1
);

$GLOBALS['__test_now_override'] = null; // إعادة الوقت الافتراضي احتياطاً لأي كود لاحق

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
