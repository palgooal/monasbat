<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية، بنفس أسلوب tests/test-supervisor-
 * session.php حرفياً) لـEntry Check-in Supervisors، Phase 3.5 ("Supervisor
 * Portal Foundation" RFC) — PGE_Supervisor_Portal_Middleware وPGE_Supervisor_
 * Portal_Bootstrap. يستدعي كامل الأنبوب الحقيقي (Catalog → Tier Features →
 * Mon_Events_Users → Assignment Service → Session → Authenticator) لإنتاج
 * جلسات مشرف حقيقية عبر pge_supervisor_authenticate()، ثم يختبر طبقتَي
 * البورتال الجديدتين ضد هذه الجلسات الحقيقية عبر $_COOKIE — بلا أي إعادة
 * تنفيذ منطقي لأي من الطبقتين.
 *
 * السيناريوهات المطلوبة صراحةً (Requirement 9):
 *   - وصول للبورتال بجلسة صالحة، وصول بلا مصادقة، إسناد مُلغى، جلسة منتهية،
 *     مناسبة خاطئة، تسجيل خروج، تفويض الـMiddleware (نجاح/فشل)، عزل بين
 *     المناسبات، وانحدار على Phase 1–3.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. لا تعديل على أي كود
 * إنتاج (Phase 1/2/3) تم إجراؤه لأجل هذا الملف.
 *
 * التشغيل: php tests/test-supervisor-portal.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (نفس أسلوب test-supervisor-session.php حرفياً) ─────

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
if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp = null) {
        return date($format, $timestamp ?? time());
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

if (!class_exists('WP_User')) {
    class WP_User
    {
        public $ID;
        public $display_name;
        public function __construct($id, $display_name = '')
        {
            $this->ID = (int) $id;
            $this->display_name = $display_name;
        }
    }
}

// ── User Meta + Posts + Post Meta وهميان في الذاكرة (بنفس نمط test-
// supervisor-session.php، مع إضافة post_title/post meta/get_userdata اللازمة
// لـPGE_Supervisor_Portal_Bootstrap فقط) ────────────────────────────────────

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_users_by_id'] = [];
$GLOBALS['__test_users_data'] = []; // user_id => WP_User (لـget_userdata فقط)
$GLOBALS['__test_posts'] = [];
$GLOBALS['__test_post_meta'] = [];

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

/**
 * إعداد مضيف (اسم عرض) لـget_userdata() — Bootstrap يستهلكها فقط لعرض اسم
 * المضيف، لا علاقة لها بأي منطق تفويض.
 */
function set_test_host_user($user_id, $display_name)
{
    $GLOBALS['__test_users_data'][$user_id] = new WP_User($user_id, $display_name);
}
function get_userdata($user_id)
{
    return $GLOBALS['__test_users_data'][$user_id] ?? false;
}

/**
 * إعداد مناسبة كاملة (post_title/post_author/post_type + _pge_event_date
 * الاختياري) — امتداد لـset_test_event() الأصلية في test-supervisor-
 * session.php (تلك تكتفي بـID/post_type/post_author لأنها لا تحتاج عنوان
 * المناسبة إطلاقاً؛ هنا نحتاجه فعلياً لأن Bootstrap يعرضه).
 */
function set_test_event_full($event_id, $author_id, $title, $event_date_raw = '', $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) [
        'ID' => $event_id,
        'post_type' => $post_type,
        'post_author' => $author_id,
        'post_title' => $title,
    ];
    $GLOBALS['__test_post_meta'][$event_id] = $event_date_raw !== ''
        ? ['_pge_event_date' => $event_date_raw]
        : [];
}
function get_post($event_id)
{
    return $GLOBALS['__test_posts'][$event_id] ?? null;
}
function get_the_title($post_or_id = 0)
{
    if (is_object($post_or_id)) {
        return (string) ($post_or_id->post_title ?? '');
    }
    $p = $GLOBALS['__test_posts'][$post_or_id] ?? null;
    return $p ? (string) ($p->post_title ?? '') : '';
}
function get_post_meta($post_id, $key = '', $single = false)
{
    $value = $GLOBALS['__test_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}

// ── Fake $wpdb — نسخة طبق الأصل من Fake_Wpdb_Supervisor_Session في
// test-supervisor-session.php (Phase 3)، بلا أي تعديل منطقي — يبقى هذا
// الملف يختبر الطبقة الجديدة (Portal) فوق نفس أنبوب الإنتاج الحقيقي تماماً،
// لا مرآة منطقية جديدة. ──────────────────────────────────────────────────

class Fake_Wpdb_Supervisor_Portal
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
            if ($this->force_session_insert_failure) {
                return false;
            }
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

$GLOBALS['wpdb'] = new Fake_Wpdb_Supervisor_Portal();
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
require_once __DIR__ . '/../includes/class-pge-supervisor-portal-middleware.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-portal-bootstrap.php';

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

function setup_catalog_owner_with_event($user_id, $event_id, $supervisor_limit, $tier_key)
{
    static $sort = 100;
    reset_test_user($user_id);
    $tier = make_test_tier($tier_key, $sort++);
    PGE_Tier_Features::set_tier_feature_value($tier['id'], 'admin_supervisor_limit', (string) $supervisor_limit);
    Mon_Events_Users::activate_catalog_tier($user_id, 1, $tier['id'], 'PORTAL-ORDER-' . $tier_key);
    return $tier;
}

function create_and_get_token($event_id, $inviter_id, $phone, $name = '')
{
    return PGE_Supervisor_Assignment_Service::create_supervisor_assignment($event_id, $inviter_id, $phone, $name);
}

// ============================================================================
// السيناريو 1: وصول للبورتال بجلسة صالحة (Portal access with valid session)
// ============================================================================
echo "=== السيناريو 1: وصول للبورتال بجلسة صالحة ===\n";

setup_catalog_owner_with_event(9801, 9701, 5, 'portal1');
set_test_host_user(9801, 'أحمد المضيف');
set_test_event_full(9701, 9801, 'زفاف عائلة الأحمد', '2026-08-15');

$invite1 = create_and_get_token(9701, 9801, '0511111111', 'مشرف الاستقبال');
check('1. الدعوة أُنشئت بنجاح', $invite1['result'] ?? null, 'created');

$auth1 = pge_supervisor_authenticate($invite1['invitation_token']);
check('1. المصادقة نجحت (تمهيد فقط، ليست موضوع الاختبار هنا)', $auth1['result'] ?? null, 'authenticated');

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth1['session_token'];
$authz1 = PGE_Supervisor_Portal_Middleware::authorize();
check('1. Middleware: authorized', $authz1['result'] ?? null, 'authorized');
check('1. assignment_id صحيح', $authz1['assignment_id'] ?? null, $invite1['id']);
check('1. event_id صحيح (9701)', $authz1['event_id'] ?? null, 9701);

$bootstrap1 = PGE_Supervisor_Portal_Bootstrap::load($authz1['assignment_id'], $authz1['event_id']);
check_true('1. Bootstrap: ok=true', $bootstrap1['ok'] ?? false);
check('1. اسم المشرف صحيح', $bootstrap1['supervisor_name'] ?? null, 'مشرف الاستقبال');
check('1. اسم المناسبة صحيح', $bootstrap1['event_name'] ?? null, 'زفاف عائلة الأحمد');
check('1. اسم المضيف صحيح', $bootstrap1['host_name'] ?? null, 'أحمد المضيف');
check_true('1. رقم الهاتف مُخفى جزئياً (يظهر آخر 4 أرقام فقط)', ($bootstrap1['supervisor_phone_masked'] ?? '') === '••••••1111');
check_true('1. لا تسريب لرقم الهاتف كاملاً في القيمة المُخفاة', strpos((string) ($bootstrap1['supervisor_phone_masked'] ?? ''), '0511111111') === false);

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 2: وصول بلا مصادقة (Unauthenticated access)
// ============================================================================
echo "\n=== السيناريو 2: وصول بلا مصادقة ===\n";

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$authz2 = PGE_Supervisor_Portal_Middleware::authorize();
check('2. لا كوكي إطلاقاً: denied', $authz2['result'] ?? null, 'denied');
check('2. http_status = 401', $authz2['http_status'] ?? null, 401);
check('2. reason = no_session_cookie', $authz2['reason'] ?? null, 'no_session_cookie');
check_true('2. لا assignment_id/event_id مُسرَّب في استجابة الرفض (Requirement 6)', !array_key_exists('assignment_id', $authz2) && !array_key_exists('event_id', $authz2));

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = 'غير_موجود_إطلاقاً_' . bin2hex(random_bytes(16));
$authz2b = PGE_Supervisor_Portal_Middleware::authorize();
check('2ب. كوكي عشوائي غير موجود: denied', $authz2b['result'] ?? null, 'denied');
check('2ب. http_status = 401', $authz2b['http_status'] ?? null, 401);
check('2ب. reason = session_not_found', $authz2b['reason'] ?? null, 'session_not_found');
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 3: إسناد مُلغى (Revoked assignment)
// ============================================================================
echo "\n=== السيناريو 3: إسناد مُلغى ===\n";

setup_catalog_owner_with_event(9802, 9702, 5, 'portal3');
set_test_event_full(9702, 9802, 'حفل تخرج', '2026-09-01');
$invite3 = create_and_get_token(9702, 9802, '0512222222');
$auth3 = pge_supervisor_authenticate($invite3['invitation_token']);
check('3. المصادقة نجحت (تمهيد)', $auth3['result'] ?? null, 'authenticated');

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth3['session_token'];
$authz3_before = PGE_Supervisor_Portal_Middleware::authorize();
check('3. قبل الإلغاء: authorized', $authz3_before['result'] ?? null, 'authorized');

$revoke3 = PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($invite3['id']);
check('3. الإلغاء نجح (Phase 2، بلا أي تعديل)', $revoke3['result'] ?? null, 'revoked');

// نفس الكوكي/الجلسة القديمة — لم تُحذَف، لكن الإسناد لم يعد active (Requirement 7)
$authz3_after = PGE_Supervisor_Portal_Middleware::authorize();
check('3. بعد الإلغاء مباشرة (بنفس الجلسة القديمة): denied فوراً', $authz3_after['result'] ?? null, 'denied');
check('3. http_status = 403 (معروف، لكن ممنوع الآن)', $authz3_after['http_status'] ?? null, 403);
check('3. reason = assignment_not_active', $authz3_after['reason'] ?? null, 'assignment_not_active');
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 4: جلسة منتهية (Expired session)
// ============================================================================
echo "\n=== السيناريو 4: جلسة منتهية ===\n";

$GLOBALS['__test_now_override'] = '2026-02-01 00:00:00';
setup_catalog_owner_with_event(9803, 9703, 5, 'portal4');
set_test_event_full(9703, 9803, 'مناسبة اختبار الانتهاء', '2026-02-10');
$invite4 = create_and_get_token(9703, 9803, '0513333333');
$auth4 = pge_supervisor_authenticate($invite4['invitation_token']);
check('4. المصادقة نجحت (تمهيد)', $auth4['result'] ?? null, 'authenticated');

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth4['session_token'];
$authz4_fresh = PGE_Supervisor_Portal_Middleware::authorize();
check('4. جلسة جديدة، لم تنتهِ بعد: authorized', $authz4_fresh['result'] ?? null, 'authorized');

// تقديم الوقت بعد مهلة الجلسة (12 ساعة) — بنفس أسلوب اختبار Phase 3 الأصلي
$GLOBALS['__test_now_override'] = '2026-02-01 13:00:01';
$authz4_expired = PGE_Supervisor_Portal_Middleware::authorize();
check('4. بعد انتهاء الصلاحية: denied', $authz4_expired['result'] ?? null, 'denied');
check('4. http_status = 401', $authz4_expired['http_status'] ?? null, 401);
check('4. reason = session_expired', $authz4_expired['reason'] ?? null, 'session_expired');
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$GLOBALS['__test_now_override'] = '2026-01-01 00:00:00';

// ============================================================================
// السيناريو 5: مناسبة خاطئة / تجاهل مُدخَلات الطلب (Wrong event، لا يُقرأ
// event_id من $_GET إطلاقاً — Requirement 2)
// ============================================================================
echo "\n=== السيناريو 5: تجاهل مُدخَلات الطلب (مناسبة خاطئة) ===\n";

setup_catalog_owner_with_event(9804, 9704, 5, 'portal5');
set_test_event_full(9704, 9804, 'مناسبة 5 الحقيقية', '2026-03-01');
$invite5 = create_and_get_token(9704, 9804, '0514444444');
$auth5 = pge_supervisor_authenticate($invite5['invitation_token']);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth5['session_token'];
// محاولة تزوير event_id عبر $_GET/$_POST — يجب ألا يكون له أي أثر إطلاقاً
$_GET['event_id'] = 999999;
$_POST['event_id'] = 999999;
$authz5 = PGE_Supervisor_Portal_Middleware::authorize();
check('5. authorized رغم تزوير $_GET/$_POST', $authz5['result'] ?? null, 'authorized');
check('5. event_id الفعلي = 9704 (من الجلسة فقط، لا من $_GET المزوَّر)', $authz5['event_id'] ?? null, 9704);
unset($_GET['event_id'], $_POST['event_id'], $_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 6: تسجيل الخروج (Logout)
// ============================================================================
echo "\n=== السيناريو 6: تسجيل الخروج ===\n";

setup_catalog_owner_with_event(9805, 9705, 5, 'portal6');
set_test_event_full(9705, 9805, 'مناسبة 6', '2026-04-01');
$invite6 = create_and_get_token(9705, 9805, '0515555555');
$auth6 = pge_supervisor_authenticate($invite6['invitation_token']);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth6['session_token'];
$authz6_before = PGE_Supervisor_Portal_Middleware::authorize();
check('6. قبل الخروج: authorized', $authz6_before['result'] ?? null, 'authorized');

$logout6 = PGE_Supervisor_Session::logout($auth6['session_token']);
check('6. تسجيل الخروج نجح (Phase 3، بلا أي تعديل)', $logout6['result'] ?? null, 'logged_out');

$authz6_after = PGE_Supervisor_Portal_Middleware::authorize();
check('6. بعد الخروج (بنفس الكوكي القديم): denied', $authz6_after['result'] ?? null, 'denied');
check('6. http_status = 401', $authz6_after['http_status'] ?? null, 401);
check('6. reason = session_revoked', $authz6_after['reason'] ?? null, 'session_revoked');

// تسجيل خروج ثانٍ (idempotent) لا يكسر شيئاً
$logout6b = PGE_Supervisor_Session::logout($auth6['session_token']);
check('6. تكرار تسجيل الخروج: already_revoked (Idempotent)', $logout6b['result'] ?? null, 'already_revoked');
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 7: عزل بين المناسبات (Cross-event isolation)
// ============================================================================
echo "\n=== السيناريو 7: عزل بين المناسبات ===\n";

setup_catalog_owner_with_event(9806, 9706, 5, 'portal7a');
set_test_event_full(9706, 9806, 'مناسبة 7أ', '2026-05-01');
$invite7a = create_and_get_token(9706, 9806, '0516666666');
$auth7a = pge_supervisor_authenticate($invite7a['invitation_token']);

setup_catalog_owner_with_event(9807, 9707, 5, 'portal7b');
set_test_event_full(9707, 9807, 'مناسبة 7ب', '2026-06-01');
$invite7b = create_and_get_token(9707, 9807, '0517777777');
$auth7b = pge_supervisor_authenticate($invite7b['invitation_token']);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth7a['session_token'];
$authz7a = PGE_Supervisor_Portal_Middleware::authorize();
check('7. جلسة المشرف أ: event_id = 9706 فقط', $authz7a['event_id'] ?? null, 9706);
check_true('7. جلسة المشرف أ غير مخوَّلة لمناسبة ب عبر pge_is_active_supervisor_for_event', pge_is_active_supervisor_for_event(9707) === false);

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth7b['session_token'];
$authz7b = PGE_Supervisor_Portal_Middleware::authorize();
check('7. جلسة المشرف ب: event_id = 9707 فقط', $authz7b['event_id'] ?? null, 9707);
check_true('7. جلسة المشرف ب غير مخوَّلة لمناسبة أ عبر pge_is_active_supervisor_for_event', pge_is_active_supervisor_for_event(9706) === false);

$bootstrap7b = PGE_Supervisor_Portal_Bootstrap::load($authz7b['assignment_id'], $authz7b['event_id']);
check('7. بيانات البورتال لجلسة ب تعرض مناسبة ب فقط', $bootstrap7b['event_name'] ?? null, 'مناسبة 7ب');
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو 8: Bootstrap — حالات بيانات استثنائية (تحقّق دفاعي)
// ============================================================================
echo "\n=== السيناريو 8: Bootstrap — حالات استثنائية ===\n";

$bootstrap8_missing = PGE_Supervisor_Portal_Bootstrap::load(999999, 9706);
check('8. إسناد غير موجود: ok=false', $bootstrap8_missing['ok'] ?? true, false);
check('8. السبب: assignment_not_found', $bootstrap8_missing['reason'] ?? null, 'assignment_not_found');

$bootstrap8_mismatch = PGE_Supervisor_Portal_Bootstrap::load($invite7a['id'], 9707); // event_id خاطئ عمداً
check('8. تعارض event_id بين المُعطى والإسناد الفعلي: ok=false', $bootstrap8_mismatch['ok'] ?? true, false);
check('8. السبب: event_mismatch', $bootstrap8_mismatch['reason'] ?? null, 'event_mismatch');

// مضيف غير معروف (post_author=0 أو غير موجود في get_userdata) — لا يجب أن
// يكسر شيئاً، فقط host_name فارغ.
setup_catalog_owner_with_event(9808, 9708, 5, 'portal8');
set_test_event_full(9708, 9808, 'مناسبة بلا بيانات مضيف', ''); // لا _pge_event_date، ولا set_test_host_user()
$invite8 = create_and_get_token(9708, 9808, '0518888888');
$bootstrap8_no_host = PGE_Supervisor_Portal_Bootstrap::load($invite8['id'], 9708);
check_true('8. لا بيانات مضيف: لا يزال ok=true (لا يكسر التحميل)', $bootstrap8_no_host['ok'] ?? false);
check('8. host_name فارغ بأمان بدل خطأ PHP', $bootstrap8_no_host['host_name'] ?? null, '');
check('8. event_date_display فارغ بأمان (لا _pge_event_date)', $bootstrap8_no_host['event_date_display'] ?? null, '');

// ============================================================================
// السيناريو 9: انحدار على Phase 1–3 (Regression)
// ============================================================================
echo "\n=== السيناريو 9: انحدار Phase 1–3 ===\n";

// Phase 1 Resolver
setup_catalog_owner_with_event(9809, 9709, 3, 'portal9');
set_test_event_full(9709, 9809, 'مناسبة انحدار 9');
$quota9 = pge_resolve_supervisor_quota_status(9709);
check_true('9. pge_resolve_supervisor_quota_status() لا يزال يعمل (Phase 1)', !is_wp_error($quota9));
check('9. allowed=3 كما أُعِدَّ', is_array($quota9) ? ($quota9['allowed'] ?? null) : null, 3);

// Phase 2 Lookup
$invite9 = create_and_get_token(9709, 9809, '0519999999');
PGE_Supervisor_Assignment_Service::accept_invitation($invite9['invitation_token']);
check_true('9. pge_has_active_supervisor_assignment() (Phase 2) لا يزال يعمل', pge_has_active_supervisor_assignment(9709, '0519999999'));

// Phase 2/3 blocker fix: get_assignment_state() لا يزال يعمل
$state9 = PGE_Supervisor_Assignment_Service::get_assignment_state($invite9['id']);
check_true('9. get_assignment_state() لا يزال يعيد الصف الكامل', is_array($state9) && ($state9['status'] ?? null) === 'active');

// Phase 3: create_session/validate_session/logout لا تزال تعمل مباشرة (بلا Middleware)
$direct9 = PGE_Supervisor_Session::create_session($invite9['id'], 9709);
check('9. create_session() المباشرة لا تزال تعمل', $direct9['result'] ?? null, 'created');
$validate9 = PGE_Supervisor_Session::validate_session($direct9['session_token']);
check('9. validate_session() المباشرة لا تزال تعمل', $validate9['result'] ?? null, 'valid');

// Phase 3 Authenticator: التعافي بعد فشل جزئي (Blocker fix #3) لا يزال يعمل
$wpdb->force_session_insert_failure = true;
$invite9b = create_and_get_token(9709, 9809, '0510000001');
$auth9b_fail = PGE_Supervisor_Authenticator::authenticate($invite9b['invitation_token']);
check('9. فشل إنشاء الجلسة بعد قبول ناجح لا يزال يُعامَل كـerror/session (Blocker fix #3)', [$auth9b_fail['result'] ?? null, $auth9b_fail['stage'] ?? null], ['error', 'session']);
$wpdb->force_session_insert_failure = false;
check_true('9. الإسناد بقي active رغم فشل الجلسة (لا تراجع — Blocker fix #3)', $wpdb->supervisors[$invite9b['id']]['status'] === 'active');

// لا عمود دخيل على mon_event_supervisors من طبقة Portal الجديدة
check_true('9. صفوف mon_event_supervisors لا تحتوي أي عمود دخيل من Portal', !array_key_exists('session_token_hash', $wpdb->supervisors[$invite9['id']]));

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
