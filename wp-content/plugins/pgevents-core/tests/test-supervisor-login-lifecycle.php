<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) — Supervisor Login Architecture
 * (Post-Activation Login) RFC. يغطي الطبقات الجديدة كلها:
 *   - PGE_Supervisor_Assignment_Service::commit_new_login_token_hash()/
 *     consume_login_token()/find_active_assignments_by_phone() (الإضافات
 *     الجديدة، بلا أي تغيير على accept_invitation()/generate_delivery_token()
 *     الأصليتين).
 *   - PGE_Supervisor_Login_Service::generate() (توليد+التزام+تدقيق، Option A).
 *   - PGE_Supervisor_Login_Delivery::deliver() (Cartat اختياري فوق التوليد).
 *   - PGE_Supervisor_Login_Authenticator::authenticate() (استهلاك توكن دخول
 *     ← جلسة جديدة، مستقل تماماً عن PGE_Supervisor_Authenticator الأصلي).
 *   - pge_supervisor_login_classify_auth_error() (routing.php، دالة نقية).
 *   - معالِجات AJAX: pge_supervisor_mgmt_login_link_handler()/
 *     pge_supervisor_mgmt_send_login_handler() (المضيف)،
 *     pge_supervisor_login_request_handler() (ذاتي/nopriv).
 *   - تدقيق logout الجديد في PGE_Supervisor_Session::logout().
 *
 * "Use fake database/transport where required. Do not call the real Cartat
 * API." — wp_remote_post() هنا دالة وهمية بالكامل، قابلة للتحكم لكل سيناريو.
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-supervisor-login-lifecycle.php
 */

define('ABSPATH', __DIR__ . '/');

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) {
    function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $v))); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($v) { return trim((string) $v); }
}
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) { return preg_replace('/\D+/', '', (string) $v); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('rawurlencode') === false) { /* دالة PHP أصلية دائماً */ }

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $code;
        public $message;
        public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}

$GLOBALS['__test_now'] = '2026-01-01 00:00:00';
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now']; }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://monasbat.test' . $path; }
}

// ── wp_options وهمية — pge_wa_provider/Cartat، قابلة للتحكم لكل سيناريو ──
$GLOBALS['__test_wa_options'] = [
    'pge_wa_provider'         => 'cartat',
    'pge_cartat_api_token'    => 'test-token-abc',
    'pge_cartat_country_code' => '966',
];
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['__test_wa_options']) ? $GLOBALS['__test_wa_options'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) { $GLOBALS['__test_wa_options'][$name] = $value; return true; }
}

// ── wp_remote_post() وهمية بالكامل، قابلة للتحكم + تسجيل كل استدعاء ──────
$GLOBALS['__test_remote_post_calls'] = [];
$GLOBALS['__test_remote_post_response'] = ['body' => json_encode(['status' => 'sent', 'id' => 'test-msg-id'])];
if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = [])
    {
        $GLOBALS['__test_remote_post_calls'][] = ['url' => $url, 'args' => $args];
        return $GLOBALS['__test_remote_post_response'];
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return is_array($response) ? ($response['body'] ?? '') : ''; }
}
function reset_remote_post_spy()
{
    $GLOBALS['__test_remote_post_calls'] = [];
    $GLOBALS['__test_remote_post_response'] = ['body' => json_encode(['status' => 'sent', 'id' => 'test-msg-id'])];
}

// ── Posts وهمية (اسم المناسبة + get_post_type لـpge_mgmt_validate_request) ──
$GLOBALS['__test_posts'] = [];
function set_test_event($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) [
        'ID' => $event_id,
        'post_type' => $post_type,
        'post_author' => $author_id,
        'post_title' => 'مناسبة الاختبار',
    ];
}
function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
function get_post_field($field, $post_id) { $p = get_post($post_id); if (!$p) return ''; return $p->{$field} ?? ''; }

// ── تفويض/جلسة مضيف — نفس أسلوب test-supervisor-management.php حرفياً ──────
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null) {
    if ($cap === 'administrator') { return $GLOBALS['__test_user_is_admin']; }
    return false;
}
if (!function_exists('pge_event_guests_user_can_manage')) {
    function pge_event_guests_user_can_manage($event_id)
    {
        $post = get_post($event_id);
        if (!$post) return false;
        if (current_user_can('administrator')) return true;
        return (int) $post->post_author === get_current_user_id();
    }
}

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('COOKIEPATH')) { define('COOKIEPATH', '/'); }
if (!defined('COOKIE_DOMAIN')) { define('COOKIE_DOMAIN', ''); }
if (!function_exists('is_ssl')) { function is_ssl() { return false; } }
if (!function_exists('headers_sent')) { /* PHP أصلية */ }
if (!function_exists('status_header')) { function status_header($code) { /* no-op */ } }
if (!function_exists('nocache_headers')) { function nocache_headers() { /* no-op */ } }
if (!function_exists('wp_safe_redirect')) { function wp_safe_redirect($url) { $GLOBALS['__test_last_redirect'] = $url; } }
if (!function_exists('rawurldecode') === false) { /* أصلية */ }

// ============================================================================
// Fake $wpdb — mon_event_supervisors + pge_supervisor_mgmt_audit_log +
// mon_supervisor_sessions + GET_LOCK/RELEASE_LOCK
// ============================================================================
class Fake_Wpdb_Login_Lifecycle
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $supervisors = [];
    public $audit_log = [];
    public $sessions = [];

    private $supervisors_next_id = 1;
    private $audit_next_id = 1;
    private $sessions_next_id = 1;

    public $lock_acquire_log = [];
    public $lock_release_log = [];
    public $force_lock_busy_once = false;
    public $force_commit_conflict_once = false;

    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? '';
            $i++;
            return $m[0] === '%d' ? (string) (int) $val : "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    private function which_table($sql_or_table)
    {
        if (strpos($sql_or_table, $this->prefix . 'mon_event_supervisors') !== false) return 'supervisors';
        if (strpos($sql_or_table, $this->prefix . 'pge_supervisor_mgmt_audit_log') !== false) return 'audit';
        if (strpos($sql_or_table, $this->prefix . 'mon_supervisor_sessions') !== false) return 'sessions';
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $which = $this->which_table($sql);

        if ($which === 'sessions') {
            if (preg_match("/WHERE\s+session_token_hash\s*=\s*'([a-f0-9]{64})'/i", $sql, $m)) {
                $hash = $m[1];
                foreach ($this->sessions as $row) {
                    if (($row['session_token_hash'] ?? null) === $hash) return $row;
                }
                return null;
            }
            return null;
        }

        if ($which !== 'supervisors') return null;

        if (preg_match("/WHERE\s+invitation_token_hash\s*=\s*'([a-f0-9]{64})'/i", $sql, $m)) {
            $hash = $m[1];
            foreach ($this->supervisors as $row) {
                if (($row['invitation_token_hash'] ?? null) === $hash) return $row;
            }
            return null;
        }

        if (preg_match("/WHERE\s+login_token_hash\s*=\s*'([a-f0-9]{64})'/i", $sql, $m)) {
            $hash = $m[1];
            foreach ($this->supervisors as $row) {
                if (($row['login_token_hash'] ?? null) === $hash) return $row;
            }
            return null;
        }

        if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
            $id = (int) $m[1];
            return $this->supervisors[$id] ?? null;
        }

        return null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which !== 'supervisors') return [];

        // find_active_assignments_by_phone(): WHERE supervisor_phone = %s AND status = 'active'
        if (preg_match("/WHERE\s+supervisor_phone\s*=\s*'([^']*)'\s+AND\s+status\s*=\s*'active'/i", $sql, $m)) {
            $phone = $m[1];
            return array_values(array_filter($this->supervisors, function ($r) use ($phone) {
                return $r['supervisor_phone'] === $phone && $r['status'] === 'active';
            }));
        }

        return [];
    }

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $name = $m[1];
            if ($this->force_lock_busy_once) {
                $this->force_lock_busy_once = false;
                return 0;
            }
            $this->lock_acquire_log[] = $name;
            return 1;
        }
        return null;
    }

    public function query($sql)
    {
        if (preg_match("/SELECT\s+RELEASE_LOCK\('([^']*)'\)/i", $sql, $m)) {
            $this->lock_release_log[] = $m[1];
            return 1;
        }
        return false;
    }

    public function insert($table, $data, $format = null)
    {
        $which = $this->which_table($table);
        if ($which === 'supervisors') {
            $id = $this->supervisors_next_id++;
            $this->supervisors[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        if ($which === 'audit') {
            $id = $this->audit_next_id++;
            $this->audit_log[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        if ($which === 'sessions') {
            $id = $this->sessions_next_id++;
            $this->sessions[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        return false;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);

        if ($which === 'sessions') {
            $id = $where['id'] ?? null;
            if ($id === null || !isset($this->sessions[$id])) return 0;
            foreach ($data as $k => $v) { $this->sessions[$id][$k] = $v; }
            return 1;
        }

        if ($which !== 'supervisors') return false;

        if ($this->force_commit_conflict_once && (array_key_exists('invitation_token_hash', $data) || array_key_exists('login_token_hash', $data)) && array_key_exists('status', $where)) {
            $this->force_commit_conflict_once = false;
            return 0;
        }

        $id = $where['id'] ?? null;
        if ($id === null || !isset($this->supervisors[$id])) return 0;
        foreach ($where as $where_key => $where_value) {
            $current_value = $this->supervisors[$id][$where_key] ?? null;
            if ($where_value === null) {
                if ($current_value !== null) return 0;
                continue;
            }
            if ((string) $current_value !== (string) $where_value) return 0;
        }
        foreach ($data as $k => $v) { $this->supervisors[$id][$k] = $v; }
        return 1;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Login_Lifecycle();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-cartat-transport.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-invitation-delivery.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-manual-link-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-session.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-authenticator.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-login-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-login-delivery.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-login-authenticator.php';
require_once __DIR__ . '/../includes/routing.php'; // pge_supervisor_accept_token_shape_valid()/pge_supervisor_login_classify_auth_error() فقط

// ── Stubs AJAX/JSON — نفس test-supervisor-management.php حرفياً ────────────
if (!class_exists('Test_Wp_Die_Exception')) {
    class Test_Wp_Die_Exception extends \Exception {}
}
$GLOBALS['__test_nonce_valid_actions'] = [];
function wp_create_nonce($action) {
    $GLOBALS['__test_nonce_valid_actions'][$action] = true;
    return 'test-nonce-' . sanitize_key($action);
}
function wp_verify_nonce($nonce, $action) {
    $expected = 'test-nonce-' . sanitize_key($action);
    return hash_equals($expected, (string) $nonce) ? 1 : false;
}
$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) { /* متوقَّع */ }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}
function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

require_once __DIR__ . '/../includes/supervisor-management-ajax.php';
require_once __DIR__ . '/../includes/supervisor-login-ajax.php';

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
function check_true($label, $condition) { check($label, (bool) $condition, true); }

function seed_assignment($wpdb, $id, $event_id, $status, $phone, $raw_invitation_token = null, $raw_login_token = null, $name = 'مشرف الاختبار')
{
    $inv_hash = $raw_invitation_token !== null ? hash('sha256', $raw_invitation_token) : null;
    $login_hash = $raw_login_token !== null ? hash('sha256', $raw_login_token) : null;
    $wpdb->supervisors[$id] = [
        'id' => $id,
        'event_id' => $event_id,
        'user_id' => null,
        'supervisor_phone' => $phone,
        'supervisor_name' => $name,
        'status' => $status,
        'invitation_token_hash' => $inv_hash,
        'login_token_hash' => $login_hash,
        'invited_by_user_id' => 501,
        'invited_at' => $GLOBALS['__test_now'],
        'accepted_at' => ($status === 'active') ? $GLOBALS['__test_now'] : null,
        'revoked_at' => null,
        'created_at' => $GLOBALS['__test_now'],
        'updated_at' => $GLOBALS['__test_now'],
    ];
    return $id;
}

function audit_actions_for($wpdb, $assignment_id)
{
    return array_values(array_map(function ($r) { return $r['action']; }, array_filter($wpdb->audit_log, function ($r) use ($assignment_id) {
        return (int) $r['assignment_id'] === $assignment_id;
    })));
}

function extract_token_from_login_url(string $url): string
{
    if (preg_match('#/supervisor/login/([a-f0-9]{64})/#', $url, $m)) return $m[1];
    return '';
}

echo "=== القسم أ: PGE_Supervisor_Login_Service::generate() — الأهلية والقفل والالتزام ===\n";

// أ1-أ2: الأهلية — active فقط، بقية الحالات الأربع كلها تُرفَض (سيناريوهات
// "Pending supervisor cannot request login" و"Active supervisor can request login")
seed_assignment($wpdb, 1, 100, 'invited', '966500000001');
$r1 = PGE_Supervisor_Login_Service::generate(1, 501);
check('أ1. invited: مشرف بانتظار القبول لا يمكنه طلب دخول (not_eligible)', $r1['reason'] ?? null, 'not_eligible');

seed_assignment($wpdb, 2, 100, 'pending', '966500000002');
$r2 = PGE_Supervisor_Login_Service::generate(2, 501);
check('أ2. pending: نفس الرفض تماماً', $r2['reason'] ?? null, 'not_eligible');

seed_assignment($wpdb, 3, 100, 'revoked', '966500000003');
$r3 = PGE_Supervisor_Login_Service::generate(3, 501);
check('أ3. revoked: مرفوض', $r3['reason'] ?? null, 'not_eligible');

seed_assignment($wpdb, 4, 100, 'expired', '966500000004');
$r4 = PGE_Supervisor_Login_Service::generate(4, 501);
check('أ4. expired: مرفوض', $r4['reason'] ?? null, 'not_eligible');

seed_assignment($wpdb, 5, 100, 'active', '966500000005');
$r5 = PGE_Supervisor_Login_Service::generate(5, 501);
check('أ5. active: مشرف نشط يمكنه طلب دخول بنجاح (generated)', $r5['result'] ?? null, 'generated');
check_true('أ5ب. الرابط يحمل مسار /supervisor/login/ (لا /supervisor/accept/)', strpos($r5['login_url'] ?? '', '/supervisor/login/') !== false);

// أ6: القفل مستقل تماماً عن قفل الرابط اليدوي وقفل تسليم الدعوة
check_true('أ6. اسم القفل يبدأ بـ pge_supervisor_login_ (بادئة مستقلة)', strpos(end($wpdb->lock_acquire_log), 'pge_supervisor_login_') === 0);

// أ7: قفل منشغل — رفض فوري بلا تغيير
seed_assignment($wpdb, 6, 100, 'active', '966500000006');
$hash_before_6 = $wpdb->supervisors[6]['login_token_hash'];
$wpdb->force_lock_busy_once = true;
$r7 = PGE_Supervisor_Login_Service::generate(6, 501);
check('أ7. قفل منشغل: lock_busy', $r7['reason'] ?? null, 'lock_busy');
check('أ7ب. لا تغيير على هاش توكن الدخول', $wpdb->supervisors[6]['login_token_hash'], $hash_before_6);

// أ8: فشل التزام — لا رابط، لا تدقيق نجاح
seed_assignment($wpdb, 7, 100, 'active', '966500000007', null, 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc');
$old_login_hash_7 = $wpdb->supervisors[7]['login_token_hash'];
$wpdb->force_commit_conflict_once = true;
$r8 = PGE_Supervisor_Login_Service::generate(7, 501);
check('أ8. فشل التزام: token_commit_failed', $r8['reason'] ?? null, 'token_commit_failed');
check_true('أ8ب. لا login_url في الاستجابة', !array_key_exists('login_url', $r8));
check('أ8ج. هاش توكن الدخول القديم لم يتغيّر', $wpdb->supervisors[7]['login_token_hash'], $old_login_hash_7);
check('أ8د. لا سجل تدقيق نجاح', in_array('login_link_generated', audit_actions_for($wpdb, 7), true), false);

echo "\n=== القسم ب: استقلال توكن الدخول عن توكن الدعوة (سيناريوهات صريحة) ===\n";

// ب1: توليد رابط دخول لا يمسّ invitation_token_hash إطلاقاً (قد يكون NULL
// أصلاً بعد القبول، أو قيمة أخرى — يجب أن يبقى كما هو بالضبط)
seed_assignment($wpdb, 8, 100, 'active', '966500000008');
$wpdb->supervisors[8]['invitation_token_hash'] = 'preexisting-marker-should-not-change';
PGE_Supervisor_Login_Service::generate(8, 501);
check('ب1. توليد توكن الدخول لا يغيِّر invitation_token_hash إطلاقاً ("Invitation token remains independent")', $wpdb->supervisors[8]['invitation_token_hash'], 'preexisting-marker-should-not-change');

// ب2: توليد توكن الدخول لا يغيّر status إطلاقاً ("Never changes assignment status")
check('ب2. status يبقى active بلا أي تغيير بعد توليد توكن دخول', $wpdb->supervisors[8]['status'], 'active');

// ب3-ب4: "Invitation token cannot log in" / "Login token cannot activate invitation"
seed_assignment($wpdb, 9, 100, 'active', '966500000009', 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd');
$invitation_raw_9 = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
$consume_with_invitation_token = PGE_Supervisor_Assignment_Service::consume_login_token($invitation_raw_9);
check('ب3. توكن دعوة (مخزَّن في invitation_token_hash فقط) يُرفَض عند محاولة استهلاكه كتوكن دخول', $consume_with_invitation_token['reason'] ?? null, 'invalid_token');

$r_login_9 = PGE_Supervisor_Login_Service::generate(9, 501);
$login_raw_9 = extract_token_from_login_url($r_login_9['login_url']);
$accept_with_login_token = PGE_Supervisor_Assignment_Service::accept_invitation($login_raw_9);
check('ب4. توكن دخول لا يمكنه تفعيل دعوة (accept_invitation ترفضه)', $accept_with_login_token['reason'] ?? null, 'invalid_token');

echo "\n=== القسم ج: تدويرة توكن الدخول ورفض الرابط القديم ===\n";

// ج1-ج3: تدويرة جديدة تُبطِل الرابط السابق تلقائياً ("Old login token rejected after rotation")
seed_assignment($wpdb, 10, 100, 'active', '966500000010');
$r_first = PGE_Supervisor_Login_Service::generate(10, 501);
$token_first = extract_token_from_login_url($r_first['login_url']);
$r_second = PGE_Supervisor_Login_Service::generate(10, 501);
$token_second = extract_token_from_login_url($r_second['login_url']);
check_true('ج1. تدويرتان متتاليتان تُنتجان توكنين مختلفين', $token_first !== '' && $token_second !== '' && $token_first !== $token_second);

$auth_old = PGE_Supervisor_Login_Authenticator::authenticate($token_first);
check_true('ج2. الرابط الأول يُرفَض بعد التدويرة الثانية', ($auth_old['result'] ?? '') !== 'authenticated');

$auth_new = PGE_Supervisor_Login_Authenticator::authenticate($token_second);
check('ج3. الرابط الثاني (الأحدث) يُقبَل فعلياً', $auth_new['result'] ?? null, 'authenticated');

// ج4: بعد المصادقة الناجحة، الإسناد لا يزال active (لا انتقال حالة — "Never changes assignment status")
check('ج4. الإسناد يبقى active بعد تسجيل الدخول', $wpdb->supervisors[10]['status'], 'active');

// ج5: "Active supervisor can log in repeatedly" — تدويرة ثالثة بعد نجاح الثانية
$r_third = PGE_Supervisor_Login_Service::generate(10, 501);
$token_third = extract_token_from_login_url($r_third['login_url']);
$auth_third = PGE_Supervisor_Login_Authenticator::authenticate($token_third);
check('ج5. تسجيل دخول ثالث متتالٍ لنفس المشرف النشط ينجح أيضاً', $auth_third['result'] ?? null, 'authenticated');
check_true('ج5ب. كل تسجيل دخول يُنتج session_id مختلفاً (جلسات منفصلة، لا إعادة استخدام)', ($auth_third['session_id'] ?? 0) !== ($auth_new['session_id'] ?? 0));

echo "\n=== القسم د: تكامل مع pge_supervisor_login_classify_auth_error() (routing.php) ===\n";

seed_assignment($wpdb, 11, 100, 'revoked', '966500000011', null, 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
$auth_revoked = PGE_Supervisor_Login_Authenticator::authenticate('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
check('د1. مصادقة إسناد ملغى: stage=login/reason=assignment_not_active', $auth_revoked['reason'] ?? null, 'assignment_not_active');
$error_page_revoked = pge_supervisor_login_classify_auth_error($auth_revoked);
check('د1ب. تصنيف الخطأ: HTTP 403', $error_page_revoked['http_status'], 403);

$auth_invalid = PGE_Supervisor_Login_Authenticator::authenticate('0000000000000000000000000000000000000000000000000000000000000000');
$error_page_invalid = pge_supervisor_login_classify_auth_error($auth_invalid);
check('د2. توكن غير صالح إطلاقاً: HTTP 410', $error_page_invalid['http_status'], 410);

check_true('د3. pge_supervisor_accept_token_shape_valid() مُعاد استخدامها (نفس نمط 64 حرف hex)', pge_supervisor_accept_token_shape_valid('a1b2c3d4e5f6' . str_repeat('0', 52)));

// د4: تدقيق login_failed كُتِب فعلياً للإسناد المعروف (assignment_not_active)
check_true('د4. سجل تدقيق login_failed كُتِب فعلياً لإسناد 11', in_array('login_failed', audit_actions_for($wpdb, 11), true));

echo "\n=== القسم هـ: PGE_Supervisor_Login_Delivery::deliver() — Cartat اختياري ===\n";

// هـ1: نجاح Cartat
reset_remote_post_spy();
seed_assignment($wpdb, 12, 100, 'active', '966500000012');
$deliver_ok = PGE_Supervisor_Login_Delivery::deliver(12, 501);
check('هـ1. تسليم ناجح عبر Cartat: result = sent', $deliver_ok['result'] ?? null, 'sent');
check_true('هـ1ب. لا login_url في استجابة النجاح عبر Cartat (لا حاجة لعرضه للمضيف)', !array_key_exists('login_url', $deliver_ok));
$login_message_body_15 = json_encode($GLOBALS['__test_remote_post_calls'][0]['args']['body'] ?? $GLOBALS['__test_remote_post_calls'][0]);
check_true('هـ1ج. رسالة الإرسال لا تحتوي كلمة "دعوتك" (نص مستقل عن رسالة الدعوة)', strpos($login_message_body_15, 'دعوتك') === false);

// هـ2: فشل Cartat (رفض المزوّد) — التوكن ملتزَم فعلاً (Option A)، الرابط يُعاد للمستدعي الداخلي فقط
reset_remote_post_spy();
$GLOBALS['__test_remote_post_response'] = ['body' => json_encode(['status' => 'error', 'message' => 'rejected'])];
seed_assignment($wpdb, 13, 100, 'active', '966500000013');
$deliver_failed = PGE_Supervisor_Login_Delivery::deliver(13, 501);
check('هـ2. رفض المزوّد: result = generated_delivery_failed', $deliver_failed['result'] ?? null, 'generated_delivery_failed');
check_true('هـ2ب. login_url مُعاد فعلياً (الرابط صالح رغم فشل الإرسال — Option A)', ($deliver_failed['login_url'] ?? '') !== '');
check_true('هـ2ج. التوكن المُعاد فعلاً مُلتزَم على الخادم (يعمل عبر Authenticator الحقيقي)', (function () use ($deliver_failed) {
    $token = extract_token_from_login_url($deliver_failed['login_url']);
    $auth = PGE_Supervisor_Login_Authenticator::authenticate($token);
    return ($auth['result'] ?? '') === 'authenticated';
})());
reset_remote_post_spy();

// هـ3: مزوّد غير Cartat نشط
$GLOBALS['__test_wa_options']['pge_wa_provider'] = 'ultramsg';
seed_assignment($wpdb, 14, 100, 'active', '966500000014');
$deliver_not_cartat = PGE_Supervisor_Login_Delivery::deliver(14, 501);
check('هـ3. مزوّد نشط غير Cartat: generated_delivery_failed / provider_not_active', $deliver_not_cartat['reason'] ?? null, 'provider_not_active');
$GLOBALS['__test_wa_options']['pge_wa_provider'] = 'cartat';

// هـ4: توليد فاشل من الأساس (غير مؤهَّل) — لا محاولة إرسال إطلاقاً
reset_remote_post_spy();
seed_assignment($wpdb, 15, 100, 'pending', '966500000015');
$deliver_not_eligible = PGE_Supervisor_Login_Delivery::deliver(15, 501);
check('هـ4. غير مؤهَّل من الأساس: result = error (لا generated_delivery_failed)', $deliver_not_eligible['result'] ?? null, 'error');
check('هـ4ب. لا أي استدعاء لـwp_remote_post إطلاقاً', count($GLOBALS['__test_remote_post_calls']), 0);

echo "\n=== القسم و: معالِجات AJAX الجانب المضيف (login_link/send_login) — تفويض وعزل مناسبات ===\n";

$GLOBALS['__test_current_user_id'] = 501;
$GLOBALS['__test_user_is_admin'] = false;
set_test_event(100, 501);
set_test_event(200, 601);

// و1: مضيف مخوَّل — نسخ رابط الدخول
seed_assignment($wpdb, 16, 100, 'active', '966500000016');
$_POST = make_post_fields(100, ['assignment_id' => 16]);
$resp_login_link = call_ajax_handler('pge_supervisor_mgmt_login_link_handler');
check_true('و1. pge_supervisor_mgmt_login_link: مضيف مخوَّل ينجح', $resp_login_link['success'] === true);
check_true('و1ب. عقد الاستجابة: login_url فقط (لا حقول حسّاسة إضافية)', array_keys($resp_login_link['data']) === ['login_url']);

// و2: إرسال رابط الدخول (Cartat)
reset_remote_post_spy();
seed_assignment($wpdb, 17, 100, 'active', '966500000017');
$_POST = make_post_fields(100, ['assignment_id' => 17]);
$resp_send_login = call_ajax_handler('pge_supervisor_mgmt_send_login_handler');
check_true('و2. pge_supervisor_mgmt_send_login: مضيف مخوَّل، إرسال ناجح', $resp_send_login['success'] === true);
check_true('و2ب. لا login_url في استجابة send_login إطلاقاً (لا نجاح ولا فشل)', !array_key_exists('login_url', $resp_send_login['data'] ?? []));

// و3: مستخدم غير مخوَّل يُرفَض دون توليد
$GLOBALS['__test_current_user_id'] = 777;
seed_assignment($wpdb, 18, 100, 'active', '966500000018');
$hash_before_18 = $wpdb->supervisors[18]['login_token_hash'];
$_POST = make_post_fields(100, ['assignment_id' => 18]);
$resp_unauth = call_ajax_handler('pge_supervisor_mgmt_login_link_handler');
check_true('و3. مستخدم غير مخوَّل: success = false', $resp_unauth['success'] === false);
check('و3ب. reason = forbidden', $resp_unauth['data']['reason'] ?? null, 'forbidden');
check('و3ج. لا تغيير على هاش توكن الدخول', $wpdb->supervisors[18]['login_token_hash'], $hash_before_18);

// و4: عزل مناسبات مختلفة — إسناد 16 يخصّ المناسبة 100، محاولة الوصول عبر 200 تُرفَض
$GLOBALS['__test_current_user_id'] = 601;
$_POST = make_post_fields(200, ['assignment_id' => 16]);
$resp_cross_event = call_ajax_handler('pge_supervisor_mgmt_login_link_handler');
check_true('و4. عزل مناسبات مختلفة: success = false', $resp_cross_event['success'] === false);
check('و4ب. reason = not_found (بلا تسريب تمييز)', $resp_cross_event['data']['reason'] ?? null, 'not_found');

// و5: Administrator (ليس مالكاً) — ينجح أيضاً
$GLOBALS['__test_current_user_id'] = 999;
$GLOBALS['__test_user_is_admin'] = true;
seed_assignment($wpdb, 19, 100, 'active', '966500000019');
$_POST = make_post_fields(100, ['assignment_id' => 19]);
$resp_admin = call_ajax_handler('pge_supervisor_mgmt_login_link_handler');
check_true('و5. Administrator (ليس مالكاً): ينجح', $resp_admin['success'] === true);
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_current_user_id'] = 501;

echo "\n=== القسم ز: الطلب الذاتي (pge_supervisor_login_request_handler — nopriv) ===\n";

// ز1: طلب برقم مطابق لإسناد نشط واحد — يُولِّد رابطاً فعلياً ويُرسِله
reset_remote_post_spy();
$GLOBALS['__test_logged_in'] = false; // محاكاة زائر مجهول الهوية (لا حساب WP)
seed_assignment($wpdb, 20, 100, 'active', '966500000020');
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_login_request'), 'phone' => '966500000020'];
$resp_self1 = call_ajax_handler('pge_supervisor_login_request_handler');
check_true('ز1. طلب ذاتي برقم مطابق: success = true', $resp_self1['success'] === true);
check_true('ز1ب. حدث login_requested كُتِب فعلياً', in_array('login_requested', audit_actions_for($wpdb, 20), true));
check_true('ز1ج. حدث login_link_generated كُتِب فعلياً (تسليم فعلي حدث)', in_array('login_link_generated', audit_actions_for($wpdb, 20), true));
$msg_found = $resp_self1['data']['message'] ?? '';

// ز2: طلب برقم غير مطابق إطلاقاً — **نفس الرسالة تماماً** (منع تعداد الأرقام)
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_login_request'), 'phone' => '0599999999'];
$resp_self2 = call_ajax_handler('pge_supervisor_login_request_handler');
check_true('ز2. طلب ذاتي برقم غير موجود: success = true أيضاً (لا فرق ظاهري)', $resp_self2['success'] === true);
check('ز2ب. نفس نص الرسالة حرفياً بين "موجود" و"غير موجود" (منع Phone Enumeration)', $resp_self2['data']['message'] ?? '', $msg_found);

// ز3: nonce غير صالح — يُرفَض
$_POST = ['nonce' => 'bad-nonce', 'phone' => '966500000020'];
$resp_self3 = call_ajax_handler('pge_supervisor_login_request_handler');
check_true('ز3. nonce غير صالح: success = false', $resp_self3['success'] === false);

$GLOBALS['__test_logged_in'] = true;
$GLOBALS['__test_current_user_id'] = 501;

echo "\n=== القسم ح: تسجيل الخروج — يدمّر الجلسة فقط ===\n";

// ح1-ح3: "Logout destroys session only" — الإسناد لا يتغيّر إطلاقاً بعد logout
seed_assignment($wpdb, 21, 100, 'active', '966500000021');
$session_result = PGE_Supervisor_Session::create_session(21, 100);
check('ح1. إنشاء جلسة نجح', $session_result['result'] ?? null, 'created');

$assignment_before_logout = $wpdb->supervisors[21];
// يستدعي pge_supervisor_process_logout_token() الحقيقية المُستخرَجة من
// closure الـtemplate_redirect في routing.php — نفس الدالة التي يستدعيها
// معالج /supervisor/logout/ فعلياً في الإنتاج، لا مرآة منطقية.
$logout_nonce = wp_create_nonce('pge_supervisor_logout');
$logout_result = pge_supervisor_process_logout_token($session_result['session_token'], $logout_nonce);
check('ح2. تسجيل الخروج نجح', $logout_result['result'] ?? null, 'logged_out');
check('ح2ب. عقد الإرجاع الجديد يتضمّن assignment_id/event_id (إضافة غير كاسرة)', [$logout_result['assignment_id'] ?? null, $logout_result['event_id'] ?? null], [21, 100]);

check('ح3. صف الإسناد بأكمله لم يتغيّر إطلاقاً بعد logout (لا status، لا أي عمود)', $wpdb->supervisors[21], $assignment_before_logout);

// ح4: تدقيق logout كُتِب فعلياً، مرة واحدة فقط
check_true('ح4. حدث تدقيق logout كُتِب فعلياً', in_array('logout', audit_actions_for($wpdb, 21), true));
check('ح4ب. حدث logout واحد بالضبط لهذا الاستدعاء', count(array_filter(audit_actions_for($wpdb, 21), function ($a) { return $a === 'logout'; })), 1);

// ح5: تسجيل خروج مكرَّر (idempotent) لا يكتب حدث تدقيق ثانٍ
$logout_again = pge_supervisor_process_logout_token($session_result['session_token'], $logout_nonce);
check('ح5. تسجيل خروج مكرَّر: already_revoked', $logout_again['result'] ?? null, 'already_revoked');
check('ح5ب. لا حدث logout ثانٍ (لا ضجيج تدقيق)', count(array_filter(audit_actions_for($wpdb, 21), function ($a) { return $a === 'logout'; })), 1);

// الجلسة نفسها أصبحت غير صالحة بعد logout (لكن الإسناد يبقى active — يمكن تسجيل دخول جديد لاحقاً)
$validate_after_logout = PGE_Supervisor_Session::validate_session($session_result['session_token']);
check('ح6. الجلسة المُبطَلة لم تعد صالحة (session_revoked)', $validate_after_logout['reason'] ?? null, 'session_revoked');
check('ح6ب. الإسناد نفسه لا يزال active (logout لا يُلغي الإسناد)', $wpdb->supervisors[21]['status'], 'active');

echo "\n=== القسم ط: انحدار — لا أثر على تسليم الدعوة/الرابط اليدوي/القبول الأصليَين ===\n";

// ط1: تسليم دعوة Cartat الأصلي لا يزال يعمل بلا أي تغيير سلوكي
reset_remote_post_spy();
seed_assignment($wpdb, 22, 100, 'invited', '966500000022');
$invitation_delivery = PGE_Supervisor_Invitation_Delivery::deliver(22, 501);
check('ط1. PGE_Supervisor_Invitation_Delivery::deliver() لا يزال يعمل كما هو', $invitation_delivery['result'] ?? null, 'provider_accepted');

// ط2: الرابط اليدوي الأصلي لا يزال يعمل
seed_assignment($wpdb, 23, 100, 'invited', '966500000023');
$manual_link = PGE_Supervisor_Manual_Link_Service::generate(23, 501);
check('ط2. PGE_Supervisor_Manual_Link_Service::generate() لا يزال يعمل كما هو', $manual_link['result'] ?? null, 'generated');

// ط3: قبول دعوة أصلي (Authenticator الأصلي) لا يزال يعمل، ولا يعرف بوجود توكن الدخول إطلاقاً
seed_assignment($wpdb, 24, 100, 'invited', '966500000024', 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff');
$original_auth = PGE_Supervisor_Authenticator::authenticate('ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff');
check('ط3. PGE_Supervisor_Authenticator::authenticate() (الأصلي) لا يزال يعمل كما هو', $original_auth['result'] ?? null, 'authenticated');
check('ط3ب. القبول الأصلي لا يمسّ login_token_hash إطلاقاً (يبقى NULL)', $wpdb->supervisors[24]['login_token_hash'], null);

echo "\n=== النتيجة: $passed / $total ===\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
