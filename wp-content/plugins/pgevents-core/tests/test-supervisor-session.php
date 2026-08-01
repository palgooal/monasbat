<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس أسلوب
 * tests/test-supervisor-assignment-service.php حرفياً) لـEntry Check-in
 * Supervisors، Phase 3 ("Supervisor Authentication" RFC) — PGE_Supervisor_
 * Session، pge_supervisor_authenticate()، وpge_is_active_supervisor_for_event()
 * (التنفيذ الحقيقي الآن — لا علاقة له بـ
 * pge_has_active_supervisor_assignment() من Phase 2).
 *
 * السيناريوهات المطلوبة صراحةً (Requirement 10):
 *   - مصادقة ناجحة، توكن غير صالح، جلسة منتهية/غير صالحة، إسناد مُلغى،
 *     إعادة توليد الجلسة، تسجيل الخروج، نجاح التفويض، فشل التفويض، عدة
 *     مشرفين، عزل بين المناسبات، وانحدار على Phase 1 وPhase 2.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. لا تعديل على أي كود
 * إنتاج (Phase 1/2) تم إجراؤه لأجل هذا الملف.
 *
 * التشغيل: php tests/test-supervisor-session.php
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
// current_time() قابل للتحكم عبر $GLOBALS['__test_now_override'] — بنفس
// نمط tests/test-invitation-credit-ledger.php حرفياً، لازم هنا لاختبار
// انتهاء صلاحية الجلسة (Scenario 3) بلا الاعتماد على sleep() حقيقي.
$GLOBALS['__test_now_override'] = null;
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) {
        return $GLOBALS['__test_now_override'] ?? '2026-01-01 00:00:00';
    }
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

// ── User Meta + Posts وهميان في الذاكرة (بنفس نمط test-supervisor-
// assignment-service.php حرفياً) ─────────────────────────────────────────

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

// ── Fake $wpdb — امتداد لـFake_Wpdb_Supervisor_Service (Phase 2) بإضافة
// جدول mon_supervisor_sessions (insert/update/get_row لبحث session_token_hash)
// — بقية الجداول (plans/tiers/tier_features/supervisors) وGET_LOCK/RELEASE_LOCK
// كما هي بالضبط، لازمة لتشغيل create_supervisor_assignment()/
// accept_invitation()/revoke_supervisor_assignment() الحقيقية التي يعتمد
// عليها pge_supervisor_authenticate(). ──────────────────────────────────────

class Fake_Wpdb_Supervisor_Session
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $plans = [];
    public $tiers = [];
    public $tier_features = [];
    public $supervisors = [];
    public $sessions = [];

    private $plans_next_id = 1;
    private $tiers_next_id = 1;
    private $tier_features_next_id = 1;
    private $supervisors_next_id = 1;
    private $sessions_next_id = 1;

    // محاكاة القفل (لازمة لـcreate_supervisor_assignment() فقط)
    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];
    public $force_lock_unavailable = false;
    public $force_session_insert_failure = false;

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
        if (strpos($sql_or_table, $this->prefix . 'mon_supervisor_sessions') !== false) {
            return 'sessions';
        }
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

        if ($which === 'sessions') {
            // شكلان فقط يصدران فعلياً من PGE_Supervisor_Session: بحث بـid
            // (find لا يوجد فعلياً هنا، فقط بـsession_token_hash).
            if (preg_match("/WHERE\s+session_token_hash\s*=\s*'([^']*)'/i", $sql, $m)) {
                $hash = $m[1];
                foreach ($this->sessions as $row) {
                    if (($row['session_token_hash'] ?? null) === $hash) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        if ($which === 'supervisors') {
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
            // شكل رابع: pge_has_active_supervisor_assignment() (Phase 2 Lookup)
            // — بحث بمساواة حالة واحدة صريحة (status = 'active'), لا IN(...).
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
        if ($which === null || $which === 'supervisors' || $which === 'sessions') {
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
        } elseif ($which === 'sessions') {
            // محاكاة فشل بنيوي مؤقت (اتصال قاعدة بيانات، امتلاء قرص، ...) —
            // لاختبار قاعدة "معالجة الفشل الجزئي" في PGE_Supervisor_
            // Authenticator (Blocking Issue) دون أي علاقة بمنطق التوكن نفسه.
            if ($this->force_session_insert_failure) {
                return false;
            }
            // محاكاة UNIQUE KEY session_token_hash — رفض إدراج توكن مكرَّر.
            $hash = $data['session_token_hash'] ?? null;
            foreach ($this->sessions as $row) {
                if (($row['session_token_hash'] ?? null) === $hash) {
                    return false;
                }
            }
            $id = $this->sessions_next_id++;
            $this->sessions[$id] = array_merge(['id' => $id], $data);
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

        if ($which === 'sessions') {
            $id = $where['id'] ?? null;
            if ($id === null || !isset($this->sessions[$id])) {
                return 0;
            }
            foreach ($where as $where_key => $where_value) {
                $current_value = $this->sessions[$id][$where_key] ?? null;
                if ((string) $current_value !== (string) $where_value) {
                    return 0;
                }
            }
            foreach ($data as $k => $v) {
                $this->sessions[$id][$k] = $v;
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

$GLOBALS['wpdb'] = new Fake_Wpdb_Supervisor_Session();
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
require_once __DIR__ . '/../includes/class-pge-supervisor-session.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-authenticator.php';

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
 * إعداد سريع: مستخدم Catalog نشط بحصة مشرفين محدَّدة + مناسبة يملكها —
 * بنفس نمط setup_catalog_owner_with_event() في test-supervisor-assignment-
 * service.php حرفياً.
 */
function setup_catalog_owner_with_event($user_id, $event_id, $supervisor_limit, $tier_key)
{
    static $sort = 100;
    reset_test_user($user_id);
    $tier = make_test_tier($tier_key, $sort++);
    PGE_Tier_Features::set_tier_feature_value($tier['id'], 'admin_supervisor_limit', (string) $supervisor_limit);
    Mon_Events_Users::activate_catalog_tier($user_id, 1, $tier['id'], 'SESSION-ORDER-' . $tier_key);
    set_test_event($event_id, $user_id);
    return $tier;
}

/**
 * اختصار: يُنشئ إسناداً جديداً عبر الخدمة الحقيقية (Phase 2)، ويعيد فقط
 * التوكن الخام — أداة مساعدة لتقليل التكرار في سيناريوهات هذا الملف فقط.
 */
function create_and_get_token($event_id, $inviter_id, $phone)
{
    $r = PGE_Supervisor_Assignment_Service::create_supervisor_assignment($event_id, $inviter_id, $phone);
    return $r;
}

// ============================================================================
// السيناريو 1: مصادقة ناجحة (Successful authentication)
// ============================================================================
echo "=== السيناريو 1: مصادقة ناجحة ===\n";

setup_catalog_owner_with_event(9701, 901, 5, 'sess1');
$invite1 = create_and_get_token(901, 9701, '0511111111');
check('1. الدعوة أُنشئت بنجاح قبل المصادقة', $invite1['result'] ?? null, 'created');

$auth1 = pge_supervisor_authenticate($invite1['invitation_token']);
check('2. المصادقة نجحت بالكامل', $auth1['result'] ?? null, 'authenticated');
check('2. assignment_id يطابق الإسناد الأصلي', $auth1['assignment_id'] ?? null, $invite1['id']);
check('2. event_id صحيح (901)', $auth1['event_id'] ?? null, 901);
check_true('2. session_token مُعاد (64 حرفاً hex)', is_string($auth1['session_token'] ?? null) && strlen($auth1['session_token']) === 64 && ctype_xdigit($auth1['session_token']));
check_true('2. الإسناد أصبح active فعلياً بعد المصادقة (نفس تدفق accept_invitation)', $wpdb->supervisors[$invite1['id']]['status'] === 'active');
check_true('2. صف الجلسة أُنشئ فعلياً في mon_supervisor_sessions', isset($wpdb->sessions[$auth1['session_id']]));
check('2. session_token_hash المخزَّن = hash(session_token) وليس التوكن نفسه', $wpdb->sessions[$auth1['session_id']]['session_token_hash'], hash('sha256', $auth1['session_token']));
check_true('2. التوكن الخام نفسه لا يظهر في أي عمود مخزَّن (لا تسريب)', strpos(json_encode($wpdb->sessions[$auth1['session_id']]), $auth1['session_token']) === false);

$validate1 = PGE_Supervisor_Session::validate_session($auth1['session_token']);
check('3. التحقق من الجلسة الجديدة: valid', $validate1['result'] ?? null, 'valid');
check('3. assignment_id مطابق', $validate1['assignment_id'] ?? null, $invite1['id']);
check('3. event_id مطابق', $validate1['event_id'] ?? null, 901);

// ============================================================================
// السيناريو 2: توكن غير صالح (Invalid token)
// ============================================================================
echo "\n=== السيناريو 2: توكن غير صالح ===\n";

$auth_invalid1 = pge_supervisor_authenticate('');
check('4. توكن دعوة فارغ: error في مرحلة invitation', [$auth_invalid1['result'] ?? null, $auth_invalid1['stage'] ?? null], ['error', 'invitation']);

$auth_invalid2 = pge_supervisor_authenticate(str_repeat('a', 64)); // توكن دعوة عشوائي غير موجود
check('5. توكن دعوة غير موجود إطلاقاً: error في مرحلة invitation', [$auth_invalid2['result'] ?? null, $auth_invalid2['stage'] ?? null], ['error', 'invitation']);
check('5. السبب المنقول حرفياً من accept_invitation(): invalid_token', $auth_invalid2['reason'] ?? null, 'invalid_token');

$validate_garbage = PGE_Supervisor_Session::validate_session(str_repeat('b', 64));
check('6. validate_session() لتوكن جلسة غير موجود: invalid/session_not_found', [$validate_garbage['result'] ?? null, $validate_garbage['reason'] ?? null], ['invalid', 'session_not_found']);

$validate_empty = PGE_Supervisor_Session::validate_session('');
check('7. validate_session() لتوكن فارغ: invalid/invalid_token', [$validate_empty['result'] ?? null, $validate_empty['reason'] ?? null], ['invalid', 'invalid_token']);

// ============================================================================
// السيناريو 3: جلسة منتهية/غير صالحة (Expired/invalid session)
// ============================================================================
echo "\n=== السيناريو 3: جلسة منتهية ===\n";

$GLOBALS['__test_now_override'] = '2026-01-01 00:00:00';
setup_catalog_owner_with_event(9702, 902, 5, 'sess3');
$invite3 = create_and_get_token(902, 9702, '0512222222');
$auth3 = pge_supervisor_authenticate($invite3['invitation_token']);
check('8. مصادقة ناجحة قبل محاكاة مرور الوقت', $auth3['result'] ?? null, 'authenticated');

$validate3_before = PGE_Supervisor_Session::validate_session($auth3['session_token']);
check('9. الجلسة صالحة قبل انتهاء المهلة', $validate3_before['result'] ?? null, 'valid');

// تقديم الوقت لما بعد SESSION_TTL_SECONDS (12 ساعة) بثانية واحدة فقط
$expired_time = date('Y-m-d H:i:s', strtotime('2026-01-01 00:00:00') + PGE_Supervisor_Session::SESSION_TTL_SECONDS + 1);
$GLOBALS['__test_now_override'] = $expired_time;

$validate3_after = PGE_Supervisor_Session::validate_session($auth3['session_token']);
check('10. الجلسة أصبحت غير صالحة بعد انتهاء المهلة: invalid/session_expired', [$validate3_after['result'] ?? null, $validate3_after['reason'] ?? null], ['invalid', 'session_expired']);

$GLOBALS['__test_now_override'] = '2026-01-01 00:00:00'; // إعادة الضبط لبقية السيناريوهات

// ============================================================================
// السيناريو 4: إسناد مُلغى (Revoked assignment)
// ============================================================================
echo "\n=== السيناريو 4: إسناد مُلغى ===\n";

setup_catalog_owner_with_event(9703, 903, 5, 'sess4');
$invite4 = create_and_get_token(903, 9703, '0513333333');
$auth4 = pge_supervisor_authenticate($invite4['invitation_token']);
check('11. مصادقة ناجحة قبل الإلغاء', $auth4['result'] ?? null, 'authenticated');

$validate4_before = PGE_Supervisor_Session::validate_session($auth4['session_token']);
check('12. الجلسة صالحة قبل إلغاء الإسناد', $validate4_before['result'] ?? null, 'valid');

$revoke4 = PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($invite4['id']);
check('13. إلغاء الإسناد نجح (Phase 2، بلا أي تعديل هنا)', $revoke4['result'] ?? null, 'revoked');

// Requirement 7: الجلسة تصبح غير صالحة فوراً دون أي كتابة على صف الجلسة نفسه
check_true('14. صف الجلسة نفسه لم يُعدَّل إطلاقاً (revoked_at لا يزال NULL)', empty($wpdb->sessions[$auth4['session_id']]['revoked_at']));
$validate4_after = PGE_Supervisor_Session::validate_session($auth4['session_token']);
check('15. الجلسة رُفضت فوراً بعد إلغاء الإسناد: invalid/assignment_not_active', [$validate4_after['result'] ?? null, $validate4_after['reason'] ?? null], ['invalid', 'assignment_not_active']);
check('15. status المنقولة = revoked', $validate4_after['status'] ?? null, 'revoked');

// ============================================================================
// السيناريو 5: إعادة توليد الجلسة (Session regeneration)
// ============================================================================
echo "\n=== السيناريو 5: إعادة توليد الجلسة ===\n";

setup_catalog_owner_with_event(9704, 904, 5, 'sess5');
$invite5 = create_and_get_token(904, 9704, '0514444444');
$auth5a = pge_supervisor_authenticate($invite5['invitation_token']);
check('16. المصادقة الأولى نجحت', $auth5a['result'] ?? null, 'authenticated');

// "إعادة توليد الجلسة" لا تعني إعادة استخدام نفس رابط الدعوة — الأخير توكن
// لمرة واحدة، يُبطَل فعلياً (invitation_token_hash → NULL) داخل
// accept_invitation() فور أول استخدام (Phase 2، Requirement 4: "Invalidate
// token")، فإعادة استدعاء pge_supervisor_authenticate() بنفس الرابط تفشل
// دائماً بتصميم صحيح ومقصود (invalid_token) — هذا ليس خللاً بل تأكيداً إضافياً
// على أحادية استخدام رابط الدعوة نفسه. "إعادة التوليد" المطلوب اختبارها هنا
// (Requirement 8) هي قدرة create_session() على إصدار جلسة إضافية جديدة
// تماماً لنفس الإسناد النشط بالفعل (مثال: تسجيل دخول لاحق من جهاز آخر) —
// بدون إعادة عبور تدفّق قبول الدعوة، وبدون أي إعادة استخدام لأي مُعرِّف قديم.
$reauth_denied = pge_supervisor_authenticate($invite5['invitation_token']);
check('17أ. إعادة استخدام رابط الدعوة نفسه بعد استهلاكه: يُرفَض دائماً (توكن لمرة واحدة، بتصميم Phase 2)', [$reauth_denied['result'] ?? null, $reauth_denied['reason'] ?? null], ['error', 'invalid_token']);

$auth5b = PGE_Supervisor_Session::create_session($auth5a['assignment_id'], $auth5a['event_id']);
check('17ب. إصدار جلسة إضافية جديدة لنفس الإسناد النشط ينجح (تسجيل دخول لاحق، لا حاجة لرابط دعوة جديد)', $auth5b['result'] ?? null, 'created');

check_true('18. جلستان مختلفتان تماماً (معرّف صف مختلف)', $auth5a['session_id'] !== $auth5b['id']);
check_true('18. توكنا الجلسة مختلفان تماماً (لا إعادة استخدام — Regenerate identifiers)', $auth5a['session_token'] !== $auth5b['session_token']);

$validate5a = PGE_Supervisor_Session::validate_session($auth5a['session_token']);
$validate5b = PGE_Supervisor_Session::validate_session($auth5b['session_token']);
check('19. الجلسة الأولى لا تزال صالحة بشكل مستقل', $validate5a['result'] ?? null, 'valid');
check('19. الجلسة الثانية صالحة أيضاً بشكل مستقل', $validate5b['result'] ?? null, 'valid');
check('19. كلتاهما تشيران لنفس الإسناد (نفس المشرف، جلستان)', $validate5a['assignment_id'] ?? null, $validate5b['assignment_id'] ?? null);

// ============================================================================
// السيناريو 6: تسجيل الخروج (Logout)
// ============================================================================
echo "\n=== السيناريو 6: تسجيل الخروج ===\n";

$logout6 = PGE_Supervisor_Session::logout($auth5a['session_token']);
check('20. تسجيل الخروج نجح', $logout6['result'] ?? null, 'logged_out');
check_true('20. revoked_at سُجِّل فعلياً', !empty($wpdb->sessions[$auth5a['session_id']]['revoked_at']));

$validate6_after = PGE_Supervisor_Session::validate_session($auth5a['session_token']);
check('21. الجلسة المُسجَّل خروجها أصبحت غير صالحة: invalid/session_revoked', [$validate6_after['result'] ?? null, $validate6_after['reason'] ?? null], ['invalid', 'session_revoked']);

$logout6_again = PGE_Supervisor_Session::logout($auth5a['session_token']);
check('22. تسجيل خروج مكرَّر لنفس الجلسة: already_revoked (Idempotent، لا خطأ)', $logout6_again['result'] ?? null, 'already_revoked');

check_true('23. الجلسة الثانية (auth5b) لم تتأثر بتسجيل خروج الجلسة الأولى', PGE_Supervisor_Session::validate_session($auth5b['session_token'])['result'] === 'valid');

$logout_invalid = PGE_Supervisor_Session::logout('');
check('24. تسجيل خروج بتوكن فارغ: error/invalid_token', [$logout_invalid['result'] ?? null, $logout_invalid['reason'] ?? null], ['error', 'invalid_token']);

$logout_garbage = PGE_Supervisor_Session::logout(str_repeat('c', 64));
check('25. تسجيل خروج بتوكن غير موجود: error/invalid_token', [$logout_garbage['result'] ?? null, $logout_garbage['reason'] ?? null], ['error', 'invalid_token']);

// ============================================================================
// السيناريو 7: نجاح التفويض (Authorization success) — pge_is_active_
// supervisor_for_event($event_id) عبر كوكي الجلسة فقط، بلا أي معامل آخر
// ============================================================================
echo "\n=== السيناريو 7: نجاح التفويض ===\n";

setup_catalog_owner_with_event(9705, 905, 5, 'sess7');
$invite7 = create_and_get_token(905, 9705, '0515555555');
$auth7 = pge_supervisor_authenticate($invite7['invitation_token']);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth7['session_token'];
check_true('26. pge_is_active_supervisor_for_event(905) = true عبر الكوكي فقط (Requirement 4)', pge_is_active_supervisor_for_event(905));
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 8: فشل التفويض (Authorization failure)
// ============================================================================
echo "\n=== السيناريو 8: فشل التفويض ===\n";

check_true('27. بلا أي كوكي إطلاقاً: false', pge_is_active_supervisor_for_event(905) === false);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = 'garbage-not-a-real-token';
check_true('28. كوكي غير صالح (garbage): false', pge_is_active_supervisor_for_event(905) === false);
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth7['session_token'];
check_true('29. event_id خاطئ (906 بدل 905): false', pge_is_active_supervisor_for_event(906) === false);

PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($invite7['id']);
check_true('30. بعد إلغاء الإسناد: false (رغم أن الكوكي لا يزال يحمل نفس التوكن)', pge_is_active_supervisor_for_event(905) === false);
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

check_true('31. event_id غير صالح (0): false دون أي قراءة كوكي', pge_is_active_supervisor_for_event(0) === false);

// ── تحقّق إضافي (Requirement 4): لا اعتماد على $_REQUEST/$_POST/$_GET إطلاقاً
$_POST['event_id'] = 905;
$_GET['assignment_id'] = 99999;
$_POST['phone'] = '0515555555';
check_true('32. وجود event_id/assignment_id/phone في $_POST/$_GET لا يمنح أي تفويض (لا كوكي صالح): false', pge_is_active_supervisor_for_event(905) === false);
unset($_POST['event_id'], $_GET['assignment_id'], $_POST['phone']);

// ============================================================================
// السيناريو 9: عدة مشرفين (Multiple supervisors) لنفس المناسبة
// ============================================================================
echo "\n=== السيناريو 9: عدة مشرفين لنفس المناسبة ===\n";

setup_catalog_owner_with_event(9706, 906, 5, 'sess9');
$invite9a = create_and_get_token(906, 9706, '0516666666');
$invite9b = create_and_get_token(906, 9706, '0517777777');
check('33. مشرف أول أُنشئ', $invite9a['result'] ?? null, 'created');
check('33. مشرف ثانٍ مختلف الهاتف أُنشئ أيضاً (لا رفض تكرار — هاتف مختلف)', $invite9b['result'] ?? null, 'created');

$auth9a = pge_supervisor_authenticate($invite9a['invitation_token']);
$auth9b = pge_supervisor_authenticate($invite9b['invitation_token']);
check('34. مصادقة المشرف الأول نجحت', $auth9a['result'] ?? null, 'authenticated');
check('34. مصادقة المشرف الثاني نجحت', $auth9b['result'] ?? null, 'authenticated');
check_true('34. إسنادان مختلفان تماماً (assignment_id مختلف)', $auth9a['assignment_id'] !== $auth9b['assignment_id']);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth9a['session_token'];
check_true('35. المشرف الأول مخوَّل لنفس المناسبة (906)', pge_is_active_supervisor_for_event(906));

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth9b['session_token'];
check_true('36. المشرف الثاني مخوَّل أيضاً لنفس المناسبة (906) — عدة مشرفين نشطون معاً', pge_is_active_supervisor_for_event(906));
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// إلغاء المشرف الأول لا يؤثر على تفويض الثاني إطلاقاً
PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($invite9a['id']);
$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth9b['session_token'];
check_true('37. بعد إلغاء المشرف الأول، الثاني لا يزال مخوَّلاً (لا تأثير متبادل)', pge_is_active_supervisor_for_event(906));
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth9a['session_token'];
check_true('38. المشرف الأول (المُلغى) لم يعد مخوَّلاً', pge_is_active_supervisor_for_event(906) === false);
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 10: عزل بين المناسبات (Cross-event isolation)
// ============================================================================
echo "\n=== السيناريو 10: عزل بين المناسبات ===\n";

setup_catalog_owner_with_event(9707, 907, 5, 'sess10a');
setup_catalog_owner_with_event(9708, 908, 5, 'sess10b');
$invite10a = create_and_get_token(907, 9707, '0518888888');
$invite10b = create_and_get_token(908, 9708, '0519999999');
$auth10a = pge_supervisor_authenticate($invite10a['invitation_token']);
$auth10b = pge_supervisor_authenticate($invite10b['invitation_token']);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth10a['session_token'];
check_true('39. مشرف المناسبة 907 مخوَّل لمناسبته', pge_is_active_supervisor_for_event(907));
check_true('40. مشرف المناسبة 907 غير مخوَّل لمناسبة أخرى (908)', pge_is_active_supervisor_for_event(908) === false);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth10b['session_token'];
check_true('41. مشرف المناسبة 908 مخوَّل لمناسبته', pge_is_active_supervisor_for_event(908));
check_true('42. مشرف المناسبة 908 غير مخوَّل للمناسبة الأولى (907)', pge_is_active_supervisor_for_event(907) === false);
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 11: انحدار على Phase 1 وPhase 2
// ============================================================================
echo "\n=== السيناريو 11: انحدار Phase 1/Phase 2 ===\n";

// Phase 1 Resolver: لا يزال يعمل بلا أي تأثير من Phase 3
setup_catalog_owner_with_event(9709, 909, 3, 'sess11');
$quota11 = pge_resolve_supervisor_quota_status(909);
check_true('43. pge_resolve_supervisor_quota_status() لا يزال يعمل (Phase 1، بلا أي تأثير من Phase 3)', !is_wp_error($quota11));
check('43. allowed=3 كما أُعِدَّ', $quota11['allowed'] ?? null, 3);

// Phase 2: pge_has_active_supervisor_assignment() (Lookup، لا علاقة له بالجلسات) لا يزال يعمل
$invite11 = create_and_get_token(909, 9709, '0510101010');
PGE_Supervisor_Assignment_Service::accept_invitation($invite11['invitation_token']);
check_true('44. pge_has_active_supervisor_assignment() (Phase 2 Lookup) لا يزال يعمل دون أي تعديل', pge_has_active_supervisor_assignment(909, '0510101010'));
check_true('44. نفس الدالة تُعيد false لهاتف آخر (سلوكها لم يتغيّر إطلاقاً)', pge_has_active_supervisor_assignment(909, '0519999999') === false);

// Phase 2: رفض التكرار وGET_LOCK لا يزالان يعملان كما هما دون أي تأثير
$duplicate11 = create_and_get_token(909, 9709, '0510101010');
check('45. رفض دعوة نشطة مكرَّرة لا يزال يعمل (Phase 2، دون أي تعديل)', $duplicate11['result'] ?? null, 'duplicate_active');

// Phase 3 لا تكتب أبداً على mon_event_supervisors (فقط تقرأ) — تحقّق أن عدد
// الاستدعاءات على supervisors لم يتأثر ببنية جديدة غير متوقَّعة
check_true('46. صفوف mon_event_supervisors تحتوي فقط الحقول المعروفة من Phase 1/2 (لا عمود دخيل من Phase 3)', !array_key_exists('session_token_hash', $wpdb->supervisors[$invite11['id']]));

// ============================================================================
// السيناريو 12: معالجة الفشل الجزئي + التعافي (Blocking Issue — Failure
// Handling & Recovery)
// ============================================================================
// يثبت تنفيذياً القاعدة المطلوبة صراحةً: قبول الدعوة ينجح ← إنشاء الجلسة
// يفشل (محاكاة فشل بنيوي، لا علاقة له بمنطق التوكن) ← الإسناد يبقى ACTIVE
// (لا تراجع إلى invited) ← التوكن يبقى مُستهلَكاً (invitation_token_hash لا
// يزال NULL، لا استعادة) ← لا تاريخ يُستهلَك (لا صف جديد، لا تعديل الصف
// القديم) ← مسار مصادقة لاحق (هنا: استدعاء مباشر لـcreate_session()، يمثّل
// "نقطة الدخول المعتمَدة مستقبلاً" المذكورة في التوثيق) ينجح لنفس الإسناد
// النشط دون الحاجة لإعادة قبول الدعوة.
echo "\n=== السيناريو 12: معالجة الفشل الجزئي والتعافي ===\n";

setup_catalog_owner_with_event(9710, 910, 5, 'sess12');
$invite12 = create_and_get_token(910, 9710, '0510202020');
check('47. الدعوة أُنشئت بنجاح', $invite12['result'] ?? null, 'created');

$sessions_count_before_failure = count($wpdb->sessions);

$wpdb->force_session_insert_failure = true;
$auth12_fail = PGE_Supervisor_Authenticator::authenticate($invite12['invitation_token']);
check('48. فشل إنشاء الجلسة بعد قبول ناجح: error/stage=session', [$auth12_fail['result'] ?? null, $auth12_fail['stage'] ?? null], ['error', 'session']);
$wpdb->force_session_insert_failure = false;

// القاعدة الأساسية: الإسناد يبقى ACTIVE، لا تراجع إلى invited مطلقاً
check('49. الإسناد بقي active رغم فشل إنشاء الجلسة (لا تراجع)', $wpdb->supervisors[$invite12['id']]['status'] ?? null, 'active');

// التوكن يبقى مُستهلَكاً (accept_invitation() صفَّره فعلياً قبل أي محاولة
// لإنشاء جلسة — فشل الجلسة لا يعيده ولا يُنشئ توكناً بديلاً). القيمة
// المقارَنة تُقرأ مباشرة من المصفوفة (لا ?? — المفتاح مضمون الوجود هنا،
// وقيمته الحقيقية null فعلاً؛ استخدام ?? كان سيُخفي القيمة الحقيقية لأن
// عامل ?? يُطلِق البديل أيضاً عندما تكون القيمة المخزَّنة null صراحة، لا
// فقط عند غياب المفتاح — نفس الخطأ المُصحَّح سابقاً في Phase 2).
check('50. invitation_token_hash لا يزال NULL (التوكن مُستهلَك بشكل دائم، لم يُستعَد)', $wpdb->supervisors[$invite12['id']]['invitation_token_hash'], null);

// لا تاريخ استُهلِك: لا يزال هناك صف واحد بالضبط لهذا الإسناد (id لم يتغيّر)،
// ولا صف جديد أُنشئ محاولةً "لإعادة الدعوة" تلقائياً
$rows_for_event_910 = array_filter($wpdb->supervisors, function ($r) { return (int) $r['event_id'] === 910; });
check_true('51. لا يزال هناك صف واحد بالضبط لهذا الإسناد (لا إعادة إنشاء دعوة تلقائية)', count($rows_for_event_910) === 1);

// لا صف جلسة جديد أُنشئ فعلياً أثناء محاولة الفشل (مقارنة بالعدد قبل
// المحاولة مباشرة — لا بفراغ $wpdb->sessions الكلي، الذي يحوي فعلياً جلسات
// حقيقية من سيناريوهات سابقة في نفس تشغيل هذا الملف)
check_true('52. لا صف جلسة جديد أُضيف أثناء فشل create_session() المحاكى', count($wpdb->sessions) === $sessions_count_before_failure);

// التعافي: نقطة مصادقة مستقبلية (هنا: استدعاء create_session() مباشرة، بلا
// إعادة قبول الدعوة إطلاقاً) تُصدر جلسة صالحة لنفس الإسناد النشط بالفعل
$recovery12 = PGE_Supervisor_Session::create_session($invite12['id'], 910);
check('53. التعافي: create_session() مباشرة لنفس الإسناد الـACTIVE ينجح (بلا accept_invitation() ثانية)', $recovery12['result'] ?? null, 'created');

$validate_recovery12 = PGE_Supervisor_Session::validate_session($recovery12['session_token']);
check('54. الجلسة الناتجة عن التعافي صالحة فعلياً', $validate_recovery12['result'] ?? null, 'valid');
check('54. تشير لنفس الإسناد الصحيح', $validate_recovery12['assignment_id'] ?? null, $invite12['id']);

// تأكيد إضافي: إعادة استخدام نفس توكن الدعوة الأصلي (المُستهلَك فعلياً) بعد
// التعافي لا تزال مرفوضة — لا "إحياء" للتوكن القديم بأي شكل بعد التعافي
$reattempt12 = PGE_Supervisor_Authenticator::authenticate($invite12['invitation_token']);
check('55. إعادة استخدام توكن الدعوة الأصلي بعد التعافي: لا يزال مرفوضاً (invalid_token)', [$reattempt12['result'] ?? null, $reattempt12['reason'] ?? null], ['error', 'invalid_token']);

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
