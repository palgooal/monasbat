<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس أسلوب
 * tests/test-supervisor-quota-resolver.php وtests/test-event-quota-enforcement.php)
 * لـEntry Check-in Supervisors، Phase 2 ("Supervisor Invitation Lifecycle" RFC)
 * — PGE_Supervisor_Assignment_Service وpge_has_active_supervisor_assignment()
 * (أُعيدت تسميتها من pge_is_active_supervisor_for_event() — راجع "Blocking
 * Issue #1: Authentication vs Lookup" في class-pge-supervisor-assignment-
 * service.php: الدالة Lookup بحت، ليست دالة تفويض/مصادقة).
 *
 * السيناريوهات المطلوبة صراحةً (Requirement 11):
 *   - دعوة ناجحة، رفض دعوة نشطة مكرَّرة، دعوة بعد إلغاء، بلوغ الحصة،
 *     تدفّق القبول، توكن غير صالح، إسناد منتهي/مُلغى، سلوك قفل GET_LOCK،
 *     تكامل الـResolver، تكامل الـSnapshot، وانحدار على Invitation Credits/
 *     Replacement Credits/Event Quota.
 *
 * السيناريو 12 (أُضيف عند تصحيح Blocking Issue #2 — قرار الانتهاء): يثبت
 * تنفيذياً أن الحالات الثلاث التي تكتبها هذه الخدمة فعلياً عبر عملياتها
 * العامة الثلاث هي بالضبط invited/active/revoked — لا 'expired' ولا أي قيمة
 * أخرى — بجمع كل قيم status الناتجة فعلياً عن استدعاءات الخدمة (لا عن أي
 * تلاعب يدوي بالـfixture) والتحقق أنها مجموعة فرعية من {invited, pending,
 * active, revoked}.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. لا تعديل على أي كود إنتاج
 * تم إجراؤه لأجل هذا الملف.
 *
 * التشغيل: php tests/test-supervisor-assignment-service.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس ─────────────────────────────────────────────────

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
if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) { return preg_replace('/\D+/', '', (string) $v); }
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

// ── User Meta + Posts وهميان في الذاكرة ──────────────────────────────────

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_users_by_id'] = [];
$GLOBALS['__test_posts'] = [];

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
function set_test_event($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) [
        'ID' => $event_id,
        'post_type' => $post_type,
        'post_author' => $author_id,
    ];
}
function get_post($event_id)
{
    return $GLOBALS['__test_posts'][$event_id] ?? null;
}

// ── Fake $wpdb — يجمع بين: (أ) جداول mon_plans/mon_plan_tiers/mon_tier_features
// (لتشغيل PGE_Catalog::create_tier()/activate_catalog_tier() الحقيقيتين)،
// (ب) جدول mon_event_supervisors (insert/update/get_row/get_var للعدّ)،
// (ج) محاكاة GET_LOCK/RELEASE_LOCK (بنفس فلسفة Fake_Wpdb_Event_Lock في
// tests/test-event-quota-enforcement.php: خريطة محجوز/غير محجوز ضمن نفس
// عملية PHP، مع سجلّ acquire/release للتحقق من توازن القفل، وعلم
// force_lock_unavailable لمحاكاة "قفل مشغول من جلسة أخرى" دون الحاجة لتزامن
// حقيقي غير متاح في PHP-WASM أحادي الخيط). ─────────────────────────────────

class Fake_Wpdb_Supervisor_Service
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $plans = [];
    public $tiers = [];
    public $tier_features = [];
    public $supervisors = [];

    private $plans_next_id = 1;
    private $tiers_next_id = 1;
    private $tier_features_next_id = 1;
    private $supervisors_next_id = 1;

    // محاكاة القفل
    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];
    public $force_lock_unavailable = false;

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
        if (strpos($sql_or_table, $this->prefix . 'mon_event_supervisors') !== false) {
            return 'supervisors';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $which = $this->which_table($sql);

        if ($which === 'supervisors') {
            // شكلان فقط يصدران فعلياً من الخدمة/الـResolver: بحث بـid، بحث
            // بـinvitation_token_hash، بحث عن إسناد نشط لـ(event_id, phone).
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
                $id = (int) $m[1];
                return $this->supervisors[$id] ?? null;
            }
            if (preg_match("/WHERE\s+invitation_token_hash\s*=\s*'([^']*)'/i", $sql, $m)) {
                $hash = $m[1];
                foreach ($this->supervisors as $row) {
                    if (($row['invitation_token_hash'] ?? null) === $hash) {
                        return $row;
                    }
                }
                return null;
            }
            if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+supervisor_phone\s*=\s*\'([^\']*)\'\s+AND\s+status\s+IN\s*\(([^)]*)\)/i', $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                $statuses = array_map(function ($v) { return trim($v, " '"); }, explode(',', $m[3]));
                foreach ($this->supervisors as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['supervisor_phone'] === $phone && in_array($row['status'], $statuses, true)) {
                        return $row;
                    }
                }
                return null;
            }
            if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+supervisor_phone\s*=\s*\'([^\']*)\'\s+AND\s+status\s*=\s*\'([^\']*)\'/i', $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                $status = $m[3];
                foreach ($this->supervisors as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['supervisor_phone'] === $phone && $row['status'] === $status) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        $rows = $this->get_results($sql, $output);
        if ($rows === null) {
            return null;
        }
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which === null || $which === 'supervisors') {
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

    /**
     * get_var() — يخدم أمرين: (1) GET_LOCK(...) — محاكاة القفل، (2)
     * pge_count_active_event_supervisors() من Phase 1 — COUNT(*) بشرط
     * status NOT IN (...).
     */
    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $name = $m[1];
            $this->lock_acquire_log[] = $name;
            if ($this->force_lock_unavailable) {
                return 0;
            }
            $this->held_locks[$name] = true;
            return 1;
        }

        $table = $this->prefix . 'mon_event_supervisors';
        $pattern = '/FROM\s+' . preg_quote($table, '/') . '\s+WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+status\s+NOT\s+IN\s*\(([^)]*)\)/i';
        if (preg_match($pattern, $sql, $m)) {
            $event_id = (int) $m[1];
            $excluded = array_map(function ($v) { return trim($v, " '"); }, explode(',', $m[2]));
            $count = 0;
            foreach ($this->supervisors as $row) {
                if ((int) $row['event_id'] === $event_id && !in_array((string) $row['status'], $excluded, true)) {
                    $count++;
                }
            }
            return (string) $count;
        }

        return null;
    }

    public function query($sql)
    {
        if (preg_match("/SELECT\s+RELEASE_LOCK\('([^']*)'\)/i", $sql, $m)) {
            $name = $m[1];
            $this->lock_release_log[] = $name;
            unset($this->held_locks[$name]);
            return 1;
        }
        return false;
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
        } elseif ($which === 'supervisors') {
            // محاكاة UNIQUE KEY invitation_token_hash — رفض إدراج توكن مكرَّر
            // (لن يحدث عملياً بحكم عشوائية 256 بت، لكن نحترم القيد بأمانة).
            $hash = $data['invitation_token_hash'] ?? null;
            if ($hash !== null) {
                foreach ($this->supervisors as $row) {
                    if (($row['invitation_token_hash'] ?? null) === $hash) {
                        return false;
                    }
                }
            }
            $id = $this->supervisors_next_id++;
            $this->supervisors[$id] = array_merge(['id' => $id], $data);
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

        if ($which === 'supervisors') {
            // شرط WHERE قد يتضمن id فقط، أو id + status، أو id +
            // invitation_token_hash — تطابق كل مفاتيح $where معاً إلزامي.
            $id = $where['id'] ?? null;
            if ($id === null || !isset($this->supervisors[$id])) {
                return 0;
            }
            foreach ($where as $where_key => $where_value) {
                $current_value = $this->supervisors[$id][$where_key] ?? null;
                if ((string) $current_value !== (string) $where_value) {
                    return 0;
                }
            }
            foreach ($data as $k => $v) {
                $this->supervisors[$id][$k] = $v;
            }
            return 1;
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

$GLOBALS['wpdb'] = new Fake_Wpdb_Supervisor_Service();
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
require_once __DIR__ . '/../includes/supervisor-quota-resolver.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';

if (!class_exists('WP_Query')) {
    class WP_Query
    {
        public $found_posts = 0;
        public function __construct($args = []) { $this->found_posts = 0; }
    }
}
if (!function_exists('pge_get_user_plan_limits_for_events')) {
    function pge_get_user_plan_limits_for_events($user_id) { return ['events_count' => 0]; }
}
require_once __DIR__ . '/../includes/event-factory.php';

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
    'plan_key' => 'basic_plan',
    'name' => 'باقة أساسية',
    'plan_type' => 'personal',
    'status' => 'active',
]);

function make_test_tier($tier_key, $sort_order, array $extra = [])
{
    return PGE_Catalog::create_tier(array_merge([
        'plan_id' => 1,
        'tier_key' => $tier_key,
        'name' => 'مستوى اختبار ' . $tier_key,
        'price' => 100,
        'currency' => 'SAR',
        'salla_product_id' => null,
        'status' => 'active',
        'sort_order' => $sort_order,
    ], $extra));
}

/**
 * إعداد سريع: مستخدم Catalog نشط بحصة مشرفين محدَّدة + مناسبة يملكها.
 */
function setup_catalog_owner_with_event($user_id, $event_id, $supervisor_limit, $tier_key)
{
    global $wpdb;
    static $sort = 100;
    reset_test_user($user_id);
    $tier = make_test_tier($tier_key, $sort++);
    PGE_Tier_Features::set_tier_feature_value($tier['id'], 'admin_supervisor_limit', (string) $supervisor_limit);
    Mon_Events_Users::activate_catalog_tier($user_id, 1, $tier['id'], 'SVC-ORDER-' . $tier_key);
    set_test_event($event_id, $user_id);
    return $tier;
}

// ============================================================================
// السيناريو 1: دعوة ناجحة (Successful invitation)
// ============================================================================
echo "=== السيناريو 1: دعوة ناجحة ===\n";

setup_catalog_owner_with_event(9601, 801, 3, 'svc1');

$r1 = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(801, 9601, '0501111111', 'أحمد');
check('1. النتيجة created', $r1['result'] ?? null, 'created');
check_true('1. id مُعاد فعلياً', ($r1['id'] ?? 0) > 0);
check_true('1. invitation_token مُعاد (64 حرفاً hex)', isset($r1['invitation_token']) && preg_match('/^[0-9a-f]{64}$/', $r1['invitation_token']));

$row1 = $wpdb->supervisors[$r1['id']] ?? null;
check_true('1. الصف أُنشئ فعلياً في الجدول', $row1 !== null);
check('1. status = invited', $row1['status'] ?? null, 'invited');
check_true('1. invitation_token_hash مخزَّن (هاش، لا القيمة الخام)', ($row1['invitation_token_hash'] ?? '') !== '' && $row1['invitation_token_hash'] !== $r1['invitation_token']);
check('1. hash(token) يطابق invitation_token_hash المخزَّن فعلياً', hash('sha256', $r1['invitation_token']), $row1['invitation_token_hash']);
check_true('1. القفل تم تحريره (لا قفل معلَّق بعد الإرجاع)', empty($wpdb->held_locks));
check_true('1. سجل الحصول على القفل والتحرير متوازن (1 اكتساب، 1 تحرير)', count($wpdb->lock_acquire_log) === 1 && count($wpdb->lock_release_log) === 1);

// ============================================================================
// السيناريو 2: رفض دعوة نشطة مكرَّرة (Duplicate active invitation rejection)
// ============================================================================
echo "\n=== السيناريو 2: رفض دعوة نشطة مكرَّرة ===\n";

$r2 = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(801, 9601, '0501111111', 'أحمد آخر');
check('2. النتيجة duplicate_active (نفس الهاتف لا يزال invited)', $r2['result'] ?? null, 'duplicate_active');
check('2. id يشير للصف الأصلي نفسه', $r2['id'] ?? null, $r1['id']);
check_true('2. لا صف جديد أُنشئ (العدد يبقى كما هو)', count($wpdb->supervisors) === 1);

// دعوة هاتف مختلف لنفس المناسبة تنجح (لا علاقة لها بتكرار الهاتف الأول)
$r2b = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(801, 9601, '0502222222', 'سارة');
check('2ب. هاتف مختلف تماماً لنفس المناسبة: created', $r2b['result'] ?? null, 'created');

// ============================================================================
// السيناريو 3: دعوة بعد إلغاء (Invitation after revocation)
// ============================================================================
echo "\n=== السيناريو 3: دعوة بعد إلغاء ===\n";

$revoke_r3 = PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($r1['id']);
check('3. الإلغاء نجح', $revoke_r3['result'] ?? null, 'revoked');
check('3. الصف الأصلي أصبح revoked فعلاً', $wpdb->supervisors[$r1['id']]['status'] ?? null, 'revoked');
check_true('3. revoked_at سُجِّل', !empty($wpdb->supervisors[$r1['id']]['revoked_at']));

$r3 = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(801, 9601, '0501111111', 'أحمد الثاني');
check('4. الدعوة الثانية لنفس الهاتف بعد الإلغاء: created (لا رفض)', $r3['result'] ?? null, 'created');
check_true('4. الصف الجديد id مختلف عن الصف الملغى', ($r3['id'] ?? 0) !== $r1['id']);
check('4. الصف التاريخي (r1) لا يزال revoked دون أي تعديل', $wpdb->supervisors[$r1['id']]['status'] ?? null, 'revoked');
check('4. الصف الجديد بحالة invited', $wpdb->supervisors[$r3['id']]['status'] ?? null, 'invited');
check_true('4. عدد صفوف (event=801, phone=0501111111) = 2 (تاريخي + جديد)', count(array_filter($wpdb->supervisors, function ($r) { return (int) $r['event_id'] === 801 && $r['supervisor_phone'] === '0501111111'; })) === 2);

// ============================================================================
// السيناريو 4: بلوغ الحصة (Quota reached)
// ============================================================================
echo "\n=== السيناريو 4: بلوغ الحصة ===\n";

setup_catalog_owner_with_event(9602, 802, 1, 'svc4'); // حصة = 1 فقط
$r4a = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(802, 9602, '0503333333');
check('5. أول دعوة (ضمن الحصة): created', $r4a['result'] ?? null, 'created');

$r4b = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(802, 9602, '0504444444'); // هاتف مختلف — الحصة استُنفدت
check('6. دعوة ثانية بعد استنفاد الحصة (allowed=1): quota_exceeded', $r4b['result'] ?? null, 'quota_exceeded');
check('6. allowed=1، used=1', [$r4b['allowed'] ?? null, $r4b['used'] ?? null], [1, 1]);
check_true('6. لا صف جديد أُنشئ للهاتف الثاني', !in_array('0504444444', array_column($wpdb->supervisors, 'supervisor_phone'), true));
check_true('6. القفل حُرِّر أيضاً رغم الرفض (لا قفل معلَّق)', empty($wpdb->held_locks));

// إلغاء الوحيد النشط يعيد فتح الحصة لدعوة جديدة (used يعود إلى 0)
PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($r4a['id']);
$r4c = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(802, 9602, '0504444444');
check('7. بعد إلغاء الإسناد الوحيد: دعوة جديدة تنجح (الحصة انفتحت مجدداً)', $r4c['result'] ?? null, 'created');

// ============================================================================
// السيناريو 5: تدفّق القبول (Acceptance flow)
// ============================================================================
echo "\n=== السيناريو 5: تدفّق القبول ===\n";

setup_catalog_owner_with_event(9603, 803, 5, 'svc5');
$r5 = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(803, 9603, '0505555555');
$token5 = $r5['invitation_token'];

$accept5 = PGE_Supervisor_Assignment_Service::accept_invitation($token5);
check('8. القبول نجح', $accept5['result'] ?? null, 'accepted');
check('8. id يطابق الإسناد الأصلي', $accept5['id'] ?? null, $r5['id']);
check('8. status أصبح active', $wpdb->supervisors[$r5['id']]['status'] ?? null, 'active');
check_true('8. accepted_at سُجِّل', !empty($wpdb->supervisors[$r5['id']]['accepted_at']));
check('8. invitation_token_hash أُبطِل (NULL) بعد القبول', $wpdb->supervisors[$r5['id']]['invitation_token_hash'], null);

// قبول مكرَّر بنفس التوكن بعد إبطاله — التوكن لم يعد يطابق أي صف
$accept5_again = PGE_Supervisor_Assignment_Service::accept_invitation($token5);
check('9. إعادة استخدام نفس التوكن بعد القبول (أُبطِل فعلياً): error invalid_token', $accept5_again['result'] ?? null, 'error');
check('9. السبب invalid_token', $accept5_again['reason'] ?? null, 'invalid_token');

// pge_has_active_supervisor_assignment() (Lookup بحت — راجع Blocking Issue #1):
// true فقط بعد القبول الفعلي. هذه ليست دالة تفويض؛ الاستدعاء هنا يمرّر رقم
// هاتف مُدخَل مباشرة من الاختبار (تماماً كما قد يُدخله أي مستدعي غير موثوق) —
// الاختبار يثبت سلوك البحث فقط، لا أي قرار وصول.
check_true('10. pge_has_active_supervisor_assignment(803, 0505555555) = true بعد القبول (Lookup فقط)', pge_has_active_supervisor_assignment(803, '0505555555'));
check_true('10. pge_has_active_supervisor_assignment لحدث آخر = false (لا خلط بين المناسبات)', pge_has_active_supervisor_assignment(804, '0505555555') === false);
check_true('10. pge_has_active_supervisor_assignment لهاتف آخر = false', pge_has_active_supervisor_assignment(803, '0509999999') === false);

// ============================================================================
// السيناريو 6: توكن غير صالح (Invalid token)
// ============================================================================
echo "\n=== السيناريو 6: توكن غير صالح ===\n";

$invalid1 = PGE_Supervisor_Assignment_Service::accept_invitation('');
check('11. توكن فارغ: error invalid_token', [$invalid1['result'] ?? null, $invalid1['reason'] ?? null], ['error', 'invalid_token']);

$invalid2 = PGE_Supervisor_Assignment_Service::accept_invitation(str_repeat('f', 64)); // توكن عشوائي غير موجود إطلاقاً
check('12. توكن غير موجود إطلاقاً: error invalid_token', [$invalid2['result'] ?? null, $invalid2['reason'] ?? null], ['error', 'invalid_token']);

// ============================================================================
// السيناريو 7: إسناد منتهي/مُلغى (Expired/revoked assignment)
// ============================================================================
echo "\n=== السيناريو 7: إسناد منتهي/مُلغى ===\n";

setup_catalog_owner_with_event(9604, 805, 5, 'svc7');
$r7a = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(805, 9604, '0506666666');
$token7a = $r7a['invitation_token'];
PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($r7a['id']);

$accept7a = PGE_Supervisor_Assignment_Service::accept_invitation($token7a);
check('13. قبول توكن لإسناد مُلغى (revoked): error assignment_not_acceptable', [$accept7a['result'] ?? null, $accept7a['reason'] ?? null, $accept7a['status'] ?? null], ['error', 'assignment_not_acceptable', 'revoked']);

// حالة expired (لا مسار في هذه المرحلة يُنتجها فعلياً — نضبطها يدوياً لمحاكاة
// "إسناد منتهي" وفق نص RFC، لإثبات أن accept_invitation() ترفضها بشكل صحيح
// أيضاً مثل revoked تماماً).
$r7b = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(805, 9604, '0507777777');
$token7b = $r7b['invitation_token'];
$wpdb->supervisors[$r7b['id']]['status'] = 'expired';
$accept7b = PGE_Supervisor_Assignment_Service::accept_invitation($token7b);
check('14. قبول توكن لإسناد منتهي (expired): error assignment_not_acceptable', [$accept7b['result'] ?? null, $accept7b['reason'] ?? null, $accept7b['status'] ?? null], ['error', 'assignment_not_acceptable', 'expired']);

// محاولة إلغاء إسناد منتهي/مُلغى مسبقاً — يُرفَض أيضاً (لا يجوز إلا لـ
// invited/pending/active وفق Requirement 5)
$revoke_again = PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($r7a['id']); // بالفعل revoked
check('15. إلغاء إسناد مُلغى مسبقاً: error not_revocable', [$revoke_again['result'] ?? null, $revoke_again['reason'] ?? null], ['error', 'not_revocable']);

$revoke_expired = PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($r7b['id']); // expired
check('16. إلغاء إسناد منتهي: error not_revocable', [$revoke_expired['result'] ?? null, $revoke_expired['reason'] ?? null], ['error', 'not_revocable']);

// ============================================================================
// السيناريو 8: سلوك قفل GET_LOCK (Concurrency)
// ============================================================================
echo "\n=== السيناريو 8: سلوك قفل GET_LOCK ===\n";

setup_catalog_owner_with_event(9605, 806, 5, 'svc8');
$wpdb->force_lock_unavailable = true;
$acquire_before = count($wpdb->lock_acquire_log);
$r8 = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(806, 9605, '0508888888');
check('17. القفل مشغول (محاكاة تنافس): error lock_not_acquired', [$r8['result'] ?? null, $r8['reason'] ?? null], ['error', 'lock_not_acquired']);
check_true('17. لا صف أُنشئ أثناء فشل الحصول على القفل', !in_array('0508888888', array_column($wpdb->supervisors, 'supervisor_phone'), true));
check_true('17. محاولة الحصول على القفل سُجِّلت فعلياً', count($wpdb->lock_acquire_log) === $acquire_before + 1);
$wpdb->force_lock_unavailable = false;

// بعد تحرير المحاكاة، نفس الدعوة تنجح الآن
$r8b = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(806, 9605, '0508888888');
check('18. بعد توفّر القفل: created بنجاح', $r8b['result'] ?? null, 'created');

// ============================================================================
// السيناريو 9: تكامل الـResolver (Requirement 7 — لا قراءة مباشرة لـ Tier)
// ============================================================================
echo "\n=== السيناريو 9: تكامل الـResolver ===\n";

// مستخدم Legacy (بلا _mon_package_source=catalog) — allowed=0 دائماً (Phase 1)
reset_test_user(9606);
set_test_event(807, 9606);
$r9_legacy = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(807, 9606, '0500000001');
check('19. مالك Legacy: quota_exceeded فوراً (allowed=0 عبر Resolver، لا اختراع قيمة)', $r9_legacy['result'] ?? null, 'quota_exceeded');
check('19. allowed=0، used=0', [$r9_legacy['allowed'] ?? null, $r9_legacy['used'] ?? null], [0, 0]);

// مستخدم Catalog بلا Snapshot ميزات إطلاقاً (تكامل بيانات ناقص، محاكاة تفعيل
// سابق على Phase 1) — الخدمة يجب أن تنقل خطأ الـResolver دون اختراع سلوك.
reset_test_user(9607);
set_test_user_meta(9607, '_mon_package_source', 'catalog');
set_test_event(808, 9607);
$r9_missing_snapshot = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(808, 9607, '0500000002');
check('20. Catalog بلا Snapshot: error quota_resolution_failed (ينقل خطأ الـResolver حرفياً)', [$r9_missing_snapshot['result'] ?? null, $r9_missing_snapshot['reason'] ?? null, $r9_missing_snapshot['quota_error_code'] ?? null], ['error', 'quota_resolution_failed', 'supervisor_snapshot_missing']);

// ============================================================================
// السيناريو 10: تكامل الـSnapshot (لا قراءة حيّة لـ Tier أثناء الإنشاء)
// ============================================================================
echo "\n=== السيناريو 10: تكامل الـSnapshot ===\n";

setup_catalog_owner_with_event(9608, 809, 2, 'svc10');
// تغيير قيمة الـTier الحيّة بعد التفعيل — يجب ألا يتغيّر أي شيء في الحصة
// (Snapshot مجمَّدة وقت التفعيل فقط، Requirement 7: "Never bypass Snapshot").
$tier10 = PGE_Catalog::get_tier((int) get_user_meta(9608, '_mon_catalog_tier_id', true));
PGE_Tier_Features::set_tier_feature_value($tier10['id'], 'admin_supervisor_limit', '999');

$r10 = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(809, 9608, '0500000003');
check('21. تعديل Tier الحيّ لاحقاً لا يغيّر الحصة المجمَّدة (allowed لا يزال 2، ليس 999)', $r10['result'] ?? null, 'created');
$quota_after = pge_resolve_supervisor_quota_status(809);
check('21. Resolver يؤكد allowed=2 (Snapshot، لا قراءة Tier حيّة)', $quota_after['allowed'] ?? null, 2);

// ============================================================================
// السيناريو 11: انحدار — Event Quota / Invitation Credits / Replacement Credits
// ============================================================================
echo "\n=== السيناريو 11: انحدار Event Quota / Invitation Credits / Replacement Credits ===\n";

reset_test_user(9609);
$tier11 = make_test_tier('svc11_regression', 200, [
    'event_quota_mode' => 'limited',
    'event_quota_limit' => 7,
    'invitation_credit_limit' => 40,
    'replacement_credit_limit' => 3,
]);
Mon_Events_Users::activate_catalog_tier(9609, 1, $tier11['id'], 'SVC-REG-A');
check('22. انحدار Event Quota: _mon_event_quota_limit = 7 (بلا أي تأثير من Phase 2)', get_user_meta(9609, '_mon_event_quota_limit', true), 7);
check('22. انحدار Invitation Credits: _mon_invitation_credit_total = 40', get_user_meta(9609, '_mon_invitation_credit_total', true), 40);
check('22. انحدار Replacement Credits: _mon_replacement_credit_total = 3', get_user_meta(9609, '_mon_replacement_credit_total', true), 3);

set_test_user_meta(9609, '_mon_invitation_credit_used', 10);
Mon_Events_Users::activate_catalog_tier(9609, 1, $tier11['id'], 'SVC-REG-B');
check('23. تجديد: Invitation Credits تراكم إلى 70 (30 متبقٍ + 40 جديد، بلا أي تأثير من Phase 2)', get_user_meta(9609, '_mon_invitation_credit_total', true), 70);
check('23. تجديد: Event Quota يبقى 7 (لا تراكم، بلا أي تأثير من Phase 2)', get_user_meta(9609, '_mon_event_quota_limit', true), 7);

$event_quota_check = pge_resolve_event_quota_status(9609);
check_true('24. pge_resolve_event_quota_status() لا يزال يعمل بلا أي تأثير من الخدمة الجديدة', !is_wp_error($event_quota_check));
check('24. mode=limited، allowed=7', [$event_quota_check['mode'] ?? null, $event_quota_check['allowed'] ?? null], ['limited', 7]);

// ============================================================================
// السيناريو 12: الحالات القابلة للوصول فعلياً (Blocking Issue #2 — Option A)
// ============================================================================
// يثبت تنفيذياً (لا وصفاً/AST) أن كل قيمة status تكتبها الخدمة فعلياً عبر
// عملياتها العامة الثلاث (create/accept/revoke) تنتمي حصراً إلى المجموعة
// {invited, pending, active, revoked} — وتحديداً أن 'expired' لا تظهر أبداً
// من أي مسار خدمة حقيقي (الظهور الوحيد لـ'expired' في هذا الملف كان عبر
// تلاعب يدوي مباشر بالـfixture في السيناريو 7، وليس عبر استدعاء أي دالة
// عامة من الخدمة — هذا الاختبار يعزل ذلك ليثبت أن المسار الحقيقي وحده لا
// ينتج 'expired' إطلاقاً).
echo "\n=== السيناريو 12: الحالات القابلة للوصول فعلياً ===\n";

$observed_statuses = [];

setup_catalog_owner_with_event(9611, 810, 5, 'svc12');

$s12_created = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(810, 9611, '0501111111');
check('25. إنشاء ناجح قبل رصد الحالة', $s12_created['result'] ?? null, 'created');
$observed_statuses[] = $wpdb->supervisors[$s12_created['id']]['status']; // متوقَّع: invited

$s12_accepted = PGE_Supervisor_Assignment_Service::accept_invitation($s12_created['invitation_token']);
check('26. قبول ناجح قبل رصد الحالة', $s12_accepted['result'] ?? null, 'accepted');
$observed_statuses[] = $wpdb->supervisors[$s12_accepted['id']]['status']; // متوقَّع: active

$s12_created2 = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(810, 9611, '0502222222');
check('27. إنشاء ثانٍ ناجح قبل رصد الحالة', $s12_created2['result'] ?? null, 'created');
$observed_statuses[] = $wpdb->supervisors[$s12_created2['id']]['status']; // متوقَّع: invited

$s12_revoked = PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($s12_created2['id']);
check('28. إلغاء ناجح قبل رصد الحالة', $s12_revoked['result'] ?? null, 'revoked');
$observed_statuses[] = $wpdb->supervisors[$s12_revoked['id']]['status']; // متوقَّع: revoked

$unique_observed = array_values(array_unique($observed_statuses));
sort($unique_observed);

check('29. الحالات المرصودة فعلياً عبر الخدمة = {active, invited, revoked} بالضبط (لا pending، لا expired)', $unique_observed, ['active', 'invited', 'revoked']);

$reachable_allowed_set = ['invited', 'pending', 'active', 'revoked'];
$only_allowed = true;
foreach ($unique_observed as $status_value) {
    if (!in_array($status_value, $reachable_allowed_set, true)) {
        $only_allowed = false;
    }
}
check_true('30. كل حالة مرصودة ضمن المجموعة المسموحة {invited, pending, active, revoked}', $only_allowed);
check_true("31. 'expired' لا تظهر إطلاقاً في الحالات المرصودة عبر مسار الخدمة الحقيقي", !in_array('expired', $unique_observed, true));

// ── ملخص ────────────────────────────────────────────────────────────────

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
