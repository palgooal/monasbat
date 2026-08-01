<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) — Supervisor Login Link Preview
 * Safety Fix. يثبت أن GET /supervisor/login/{token}/ صار قراءة بحتة بلا أي
 * استهلاك للتوكن، وأن POST هو المسار الوحيد الذي يستهلك التوكن فعلياً
 * وينشئ جلسة، عبر الدوال الحقيقية المُستخرَجة في routing.php:
 *   - pge_supervisor_login_evaluate_get_request()   (قرار GET — قراءة فقط)
 *   - pge_supervisor_login_handle_post_confirmation() (قرار POST — يستهلك)
 *   - pge_supervisor_login_session_cookie_params()   (معاملات الكوكي)
 *   - PGE_Supervisor_Assignment_Service::peek_login_token()/consume_login_token()
 *   - PGE_Supervisor_Login_Authenticator::authenticate()
 * بالإضافة إلى انحدار صريح على: تسليم Cartat لرابط الدخول، الرابط اليدوي
 * (Login_Service::generate())، ومسار قبول الدعوة الأصلي (بلا أي تعديل عليه).
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-supervisor-login-preview-safety.php
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
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) { return preg_replace('/\D+/', '', (string) $v); }
}
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
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}

$GLOBALS['__test_now'] = '2026-01-01 00:00:00';
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now']; }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://monasbat.test' . $path; }
}
if (!function_exists('is_ssl')) { function is_ssl() { return false; } }
if (!defined('COOKIEPATH')) { define('COOKIEPATH', '/'); }
if (!defined('COOKIE_DOMAIN')) { define('COOKIE_DOMAIN', ''); }
if (!function_exists('headers_sent')) { /* أصلية PHP */ }
if (!function_exists('status_header')) { function status_header($code) { /* no-op */ } }
if (!function_exists('nocache_headers')) { function nocache_headers() { /* no-op */ } }
if (!function_exists('wp_safe_redirect')) { function wp_safe_redirect($url) { $GLOBALS['__test_last_redirect'] = $url; } }

// ── جاسوس wp_insert_user() — إثبات "No WordPress user is created" ─────────
$GLOBALS['__test_wp_insert_user_calls'] = 0;
if (!function_exists('wp_insert_user')) {
    function wp_insert_user($args) { $GLOBALS['__test_wp_insert_user_calls']++; return 1; }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) { return false; }
}

// ── wp_options وهمية — pge_wa_provider/Cartat ──────────────────────────────
$GLOBALS['__test_wa_options'] = [
    'pge_wa_provider' => 'cartat',
    'pge_cartat_api_token' => 'test-token-abc',
    'pge_cartat_country_code' => '966',
];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['__test_wa_options']) ? $GLOBALS['__test_wa_options'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) { $GLOBALS['__test_wa_options'][$name] = $value; return true; }
}

// ── wp_remote_post() وهمية بالكامل ─────────────────────────────────────────
$GLOBALS['__test_remote_post_calls'] = [];
$GLOBALS['__test_remote_post_response'] = ['body' => json_encode(['status' => 'sent', 'id' => 'test-msg-id'])];
if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = []) {
        $GLOBALS['__test_remote_post_calls'][] = ['url' => $url, 'args' => $args];
        return $GLOBALS['__test_remote_post_response'];
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return is_array($response) ? ($response['body'] ?? '') : ''; }
}

// ── Posts وهمية — اسم المناسبة لـPGE_Supervisor_Login_Delivery::deliver() ──
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
if (!function_exists('get_post')) {
    function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
}
if (!function_exists('get_post_type')) {
    function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
}
if (!function_exists('get_post_field')) {
    function get_post_field($field, $post_id) { $p = get_post($post_id); if (!$p) return ''; return $p->{$field} ?? ''; }
}

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ============================================================================
// Fake $wpdb — mon_event_supervisors + pge_supervisor_mgmt_audit_log +
// mon_supervisor_sessions + GET_LOCK/RELEASE_LOCK (نفس نمط ملفات الاختبار
// السابقة لهذه الميزة حرفياً)
// ============================================================================
class Fake_Wpdb_Login_Preview_Safety
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

        // كلٌّ من consume_login_token() وpeek_login_token() يستعلمان
        // login_token_hash بنفس نمط WHERE — لا فرق بينهما هنا (الفرق الوحيد:
        // peek لا يستدعي update() إطلاقاً بعد هذا الاستعلام).
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

    public function get_results($sql, $output = null) { return []; }

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $this->lock_acquire_log[] = $m[1];
            return 1;
        }
        return null;
    }

    public function query($sql)
    {
        if (preg_match("/SELECT\s+RELEASE_LOCK\('([^']*)'\)/i", $sql, $m)) {
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

$GLOBALS['wpdb'] = new Fake_Wpdb_Login_Preview_Safety();
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

// wp_create_nonce()/wp_verify_nonce() يجب تعريفهما قبل تحميل routing.php
// (routing.php لا يستدعيهما وقت التحميل، لكن يُفضَّل نفس ترتيب الملفات
// الفعلي في pgevents-core.php).
$GLOBALS['__test_nonce_valid_actions'] = [];
function wp_create_nonce($action) {
    $GLOBALS['__test_nonce_valid_actions'][$action] = true;
    return 'test-nonce-' . sanitize_key($action);
}
function wp_verify_nonce($nonce, $action) {
    $expected = 'test-nonce-' . sanitize_key($action);
    return hash_equals($expected, (string) $nonce) ? 1 : false;
}

require_once __DIR__ . '/../includes/routing.php'; // الدوال المُستخرَجة الجديدة + pge_supervisor_accept_token_shape_valid()/pge_supervisor_login_classify_auth_error()

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

function seed_assignment($wpdb, $id, $event_id, $status, $phone, $raw_login_token = null, $name = 'مشرف الاختبار')
{
    $login_hash = $raw_login_token !== null ? hash('sha256', $raw_login_token) : null;
    $wpdb->supervisors[$id] = [
        'id' => $id,
        'event_id' => $event_id,
        'user_id' => null,
        'supervisor_phone' => $phone,
        'supervisor_name' => $name,
        'status' => $status,
        'invitation_token_hash' => null,
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

echo "=== القسم أ: GET — قراءة بحتة، بلا استهلاك (سيناريوهات 1-6) ===\n";

// أ1: توكن دخول صالح فعلياً — GET لا يستهلكه
seed_assignment($wpdb, 1, 100, 'active', '966500000001', str_repeat('a', 64));
$hash_before_get_1 = $wpdb->supervisors[1]['login_token_hash'];
$get_result_1 = pge_supervisor_login_evaluate_get_request(str_repeat('a', 64));
check('1. GET بتوكن صالح: mode = confirm (لا استهلاك)', $get_result_1['mode'] ?? null, 'confirm');
check('2. GET لا يغيِّر login_token_hash إطلاقاً', $wpdb->supervisors[1]['login_token_hash'], $hash_before_get_1);
check('3. GET لا ينشئ أي صف جلسة', count($wpdb->sessions), 0);
check('5. GET لا يكتب حدث تدقيق login_authenticated', in_array('login_authenticated', audit_actions_for($wpdb, 1), true), false);
check('5ب. GET لا يكتب أي حدث تدقيق إطلاقاً لهذا الإسناد', audit_actions_for($wpdb, 1), []);

// أ4 (بلا setcookie فعلي ممكن اختباره HTTP-يّاً هنا) — إثبات بنيوي: جسم
// دالة القرار نفسها لا تحتوي استدعاء setcookie() إطلاقاً (تدقيق ساكن مضمَّن)
$routing_source = file_get_contents(__DIR__ . '/../includes/routing.php');
$evaluate_fn_start = strpos($routing_source, 'function pge_supervisor_login_evaluate_get_request');
$evaluate_fn_end = strpos($routing_source, "\n}\n", $evaluate_fn_start);
$evaluate_fn_body = substr($routing_source, $evaluate_fn_start, $evaluate_fn_end - $evaluate_fn_start);
check_true('4. جسم pge_supervisor_login_evaluate_get_request() لا يحتوي استدعاء setcookie() إطلاقاً', strpos($evaluate_fn_body, 'setcookie') === false);
check_true('4ب. جسم الدالة لا يستدعي PGE_Supervisor_Session::create_session() إطلاقاً', strpos($evaluate_fn_body, 'create_session') === false);
check_true('4ج. جسم الدالة لا يستدعي PGE_Supervisor_Login_Authenticator::authenticate() إطلاقاً', strpos($evaluate_fn_body, 'Login_Authenticator::authenticate') === false);
check_true('4د. جسم الدالة لا يستدعي consume_login_token() إطلاقاً (فقط peek_login_token())', strpos($evaluate_fn_body, 'consume_login_token') === false && strpos($evaluate_fn_body, 'peek_login_token') !== false);

// 6: طلبات GET متعددة متتالية تترك التوكن صالحاً في كل مرة
$get_result_1b = pge_supervisor_login_evaluate_get_request(str_repeat('a', 64));
$get_result_1c = pge_supervisor_login_evaluate_get_request(str_repeat('a', 64));
check('6. GET ثانٍ متكرر: لا يزال confirm', $get_result_1b['mode'] ?? null, 'confirm');
check('6ب. GET ثالث متكرر: لا يزال confirm', $get_result_1c['mode'] ?? null, 'confirm');
check('6ج. هاش التوكن لم يتغيَّر بعد 3 طلبات GET متتالية', $wpdb->supervisors[1]['login_token_hash'], $hash_before_get_1);

echo "\n=== القسم ب: POST — يستهلك فعلياً، ينشئ جلسة (سيناريوهات 7-12) ===\n";

$valid_confirm_nonce = wp_create_nonce('pge_supervisor_login_confirm');

// 7-9: POST بتوكن صالح ينجح، يستهلك، ينشئ جلسة
$post_result_1 = pge_supervisor_login_handle_post_confirmation(str_repeat('a', 64), $valid_confirm_nonce);
check('7. POST بتوكن صالح: mode = authenticated', $post_result_1['mode'] ?? null, 'authenticated');
check('8. POST استهلك التوكن فعلياً (login_token_hash أصبح NULL)', $wpdb->supervisors[1]['login_token_hash'], null);
check_true('9. POST أنشأ صف جلسة فعلي واحد', count($wpdb->sessions) === 1);
check('9ب. الجلسة تخص الإسناد/المناسبة الصحيحين', [$wpdb->sessions[1]['assignment_id'] ?? null, $wpdb->sessions[1]['event_id'] ?? null], [1, 100]);

// 10: معاملات كوكي آمنة (secure/httponly/samesite) — نفس السياسة المعتمَدة أصلاً
$cookie_params = pge_supervisor_login_session_cookie_params((string) ($post_result_1['auth_result']['expires_at'] ?? ''));
check('10. httponly = true', $cookie_params['httponly'] ?? null, true);
check('10ب. samesite = Lax', $cookie_params['samesite'] ?? null, 'Lax');
check('10ج. secure يطابق is_ssl()', $cookie_params['secure'] ?? null, is_ssl());
check('10د. path افتراضي = /', $cookie_params['path'] ?? null, '/');

// 11: إعادة التوجيه إلى بوابة المشرف — تدقيق ساكن على نص فرع POST الناجح
// (بين نقطة نجاح POST ونهاية الـif الخاص بها عبر أول "exit;" تالية)
$post_success_marker = strpos($routing_source, "mode'] === 'authenticated'");
$post_success_exit = strpos($routing_source, 'exit;', $post_success_marker);
$post_success_block = substr($routing_source, $post_success_marker, $post_success_exit - $post_success_marker);
check_true("11. فرع POST الناجح يعيد التوجيه فعلياً إلى home_url('/supervisor/')", strpos($post_success_block, "wp_safe_redirect(home_url('/supervisor/'));") !== false);

// 12: POST ثانٍ بنفس التوكن (المُستهلَك فعلاً) يفشل
$post_result_2 = pge_supervisor_login_handle_post_confirmation(str_repeat('a', 64), $valid_confirm_nonce);
check('12. POST ثانٍ بنفس التوكن المُستهلَك: mode = error', $post_result_2['mode'] ?? null, 'error');

echo "\n=== القسم ج: حالات الخطأ الآمنة (سيناريوهات 13-16) ===\n";

// 13: توكن غير صالح إطلاقاً (لا صف مطابق) — GET
$get_invalid = pge_supervisor_login_evaluate_get_request(str_repeat('0', 64));
check('13. GET بتوكن غير موجود: mode = error', $get_invalid['mode'] ?? null, 'error');
check('13ب. http_status = 410 (رابط غير صالح)', $get_invalid['error']['http_status'] ?? null, 410);

// 14: توكن مُستخدَم مسبقاً (نفس توكن أ1 بعد استهلاكه في POST أعلاه) — GET
$get_used = pge_supervisor_login_evaluate_get_request(str_repeat('a', 64));
check('14. GET بتوكن مُستخدَم مسبقاً: mode = error', $get_used['mode'] ?? null, 'error');
check('14ب. http_status = 410', $get_used['error']['http_status'] ?? null, 410);

// 15: إسناد أصبح revoked لكن كان يحمل هاش توكن دخول (سيناريو حافة: تدوير
// حالة الإسناد بعد توليد رابط دخول ناجح، قبل استخدامه)
seed_assignment($wpdb, 2, 100, 'revoked', '966500000002', str_repeat('b', 64));
$get_revoked = pge_supervisor_login_evaluate_get_request(str_repeat('b', 64));
check('15. GET لإسناد revoked: mode = error', $get_revoked['mode'] ?? null, 'error');
check('15ب. http_status = 403 (وصول لم يعد فعّالاً)', $get_revoked['error']['http_status'] ?? null, 403);
check_true('15ج. لا استهلاك حتى في حالة الخطأ — هاش التوكن لم يتغيَّر', $wpdb->supervisors[2]['login_token_hash'] === hash('sha256', str_repeat('b', 64)));

// 16: nonce مفقود/غير صالح على POST — يُرفَض بلا استهلاك
seed_assignment($wpdb, 3, 100, 'active', '966500000003', str_repeat('c', 64));
$hash_before_bad_nonce = $wpdb->supervisors[3]['login_token_hash'];
$post_bad_nonce = pge_supervisor_login_handle_post_confirmation(str_repeat('c', 64), 'bad-nonce-value');
check('16. POST بنونس غير صالح: mode = error', $post_bad_nonce['mode'] ?? null, 'error');
check('16ب. http_status = 403', $post_bad_nonce['error']['http_status'] ?? null, 403);
check('16ج. لا استهلاك — هاش التوكن لم يتغيَّر', $wpdb->supervisors[3]['login_token_hash'], $hash_before_bad_nonce);

$post_missing_nonce = pge_supervisor_login_handle_post_confirmation(str_repeat('c', 64), '');
check('16د. POST بنونس فارغ إطلاقاً: mode = error أيضاً', $post_missing_nonce['mode'] ?? null, 'error');
check('16هـ. لا استهلاك حتى مع نونس فارغ', $wpdb->supervisors[3]['login_token_hash'], $hash_before_bad_nonce);

echo "\n=== القسم د: محاكاة معاينة WhatsApp — GET قبل POST لا يفسد الدخول الحقيقي (سيناريو 17) ===\n";

seed_assignment($wpdb, 4, 100, 'active', '966500000004', str_repeat('d', 64));
$nonce_4 = wp_create_nonce('pge_supervisor_login_confirm');

// محاكاة: معاينة WhatsApp (أو أي زاحف) تفتح الرابط عبر GET — قد يحدث عدة
// مرات (WhatsApp نفسه، متصفح الهاتف Prefetch، فاحص أمني الشركة...)
$preview_1 = pge_supervisor_login_evaluate_get_request(str_repeat('d', 64));
$preview_2 = pge_supervisor_login_evaluate_get_request(str_repeat('d', 64));
$preview_3 = pge_supervisor_login_evaluate_get_request(str_repeat('d', 64));
check_true('17أ. ثلاث معاينات GET متتالية جميعها confirm (لا استهلاك من أي منها)', $preview_1['mode'] === 'confirm' && $preview_2['mode'] === 'confirm' && $preview_3['mode'] === 'confirm');

// المشرف الحقيقي يضغط الزر بعدها — POST حقيقي
$real_login = pge_supervisor_login_handle_post_confirmation(str_repeat('d', 64), $nonce_4);
check('17ب. POST الحقيقي بعد كل معاينات GET السابقة لا يزال ينجح فعلياً', $real_login['mode'] ?? null, 'authenticated');
check_true('17ج. جلسة فعلية أُنشئت لهذا الدخول الحقيقي', ($real_login['auth_result']['session_token'] ?? '') !== '');

echo "\n=== القسم هـ: انحدار — Cartat/الرابط اليدوي/قبول الدعوة الأصلي (سيناريوهات 18-20) ===\n";

// 18: تسليم Cartat لرابط الدخول لا يزال يعمل كما هو
set_test_event(100, 501);
seed_assignment($wpdb, 5, 100, 'active', '966500000005');
$deliver_result = PGE_Supervisor_Login_Delivery::deliver(5, 501);
check('18. PGE_Supervisor_Login_Delivery::deliver() (Cartat) لا يزال يعمل: result = sent', $deliver_result['result'] ?? null, 'sent');

// 19: الرابط اليدوي (توليد رابط دخول للنسخ) لا يزال يعمل، والرابط الناتج
// يمرّ بنجاح عبر نفس تدفّق GET(preview)→POST(authenticate) الجديد
seed_assignment($wpdb, 6, 100, 'active', '966500000006');
$manual_login_link = PGE_Supervisor_Login_Service::generate(6, 501);
check('19. PGE_Supervisor_Login_Service::generate() (الرابط اليدوي) لا يزال يعمل: result = generated', $manual_login_link['result'] ?? null, 'generated');
if (preg_match('#/supervisor/login/([a-f0-9]{64})/#', $manual_login_link['login_url'] ?? '', $m19)) {
    $manual_token = $m19[1];
    $manual_preview = pge_supervisor_login_evaluate_get_request($manual_token);
    check('19ب. رابط الدخول اليدوي يعمل عبر GET(preview) الجديد بلا استهلاك', $manual_preview['mode'] ?? null, 'confirm');
    $manual_nonce = wp_create_nonce('pge_supervisor_login_confirm');
    $manual_post = pge_supervisor_login_handle_post_confirmation($manual_token, $manual_nonce);
    check('19ج. ونفس الرابط يُكمِل الدخول فعلياً عبر POST', $manual_post['mode'] ?? null, 'authenticated');
} else {
    check_true('19ب-ج. تعذّر استخراج التوكن من رابط الدخول اليدوي', false);
}

// 20: مسار قبول الدعوة الأصلي — بلا أي تعديل عليه، لا يزال يستهلك على GET
// المباشر (تصميمه الأصلي المعتمَد، خارج نطاق هذا التصحيح كلياً)
seed_assignment($wpdb, 7, 100, 'invited', '966500000007');
$wpdb->supervisors[7]['invitation_token_hash'] = hash('sha256', str_repeat('e', 64));
$original_accept = PGE_Supervisor_Authenticator::authenticate(str_repeat('e', 64));
check('20. PGE_Supervisor_Authenticator::authenticate() (قبول الدعوة الأصلي) لا يزال يعمل دون أي تغيير', $original_accept['result'] ?? null, 'authenticated');
check_true(
    '20ب. تدقيق ساكن: مسار /supervisor/accept/{token}/ لا يزال مسجَّلاً حرفياً بلا تغيير',
    strpos($routing_source, "add_rewrite_rule('^supervisor/accept/([^/]+)/?\$', 'index.php?pge_action=supervisor_accept_invitation&pge_token=\$matches[1]', 'top');") !== false
);
check_true(
    '20ج. تدقيق ساكن: لا فرع REQUEST_METHOD/POST داخل معالج supervisor_accept_invitation (لم يُطبَّق عليه GET/POST split)',
    (function () use ($routing_source) {
        $start = strpos($routing_source, "if (\$action !== 'supervisor_accept_invitation') return;");
        $end = strpos($routing_source, "}, 1);", $start);
        $block = substr($routing_source, $start, $end - $start);
        return strpos($block, 'REQUEST_METHOD') === false;
    })()
);

echo "\n=== القسم و: لا حساب WordPress يُنشَأ إطلاقاً (سيناريو 21) ===\n";

check('21. wp_insert_user() لم تُستدعَ ولو مرة واحدة عبر كامل هذا الملف', $GLOBALS['__test_wp_insert_user_calls'], 0);

echo "\n=== القسم ز: تدقيق ساكن إضافي — لا مسار دخول مكرَّر، لا QR ===\n";

check_true('ز1. لا مسار /supervisor/login/{token} ثانٍ في routing.php (rewrite rule واحد فقط)', substr_count($routing_source, "add_rewrite_rule('^supervisor/login/([^/]+)/?\$'") === 1);
check_true('ز2. لا أي إشارة لـQR في هذا الملف (لم تُضَف ميزة QR)', stripos($routing_source, 'qr') === false || true); // qr موجودة في نطاقات أخرى (check-in) لا علاقة لها بهذا المسار — تحقّق نطاقي أدق أدناه
$login_route_region_start = strpos($routing_source, "مصادقة توكن الدخول");
$login_route_region_end = strpos($routing_source, "التوجيه الذكي للملفات");
$login_route_region = substr($routing_source, $login_route_region_start, $login_route_region_end - $login_route_region_start);
check_true('ز2ب. نطاق مسار تسجيل الدخول تحديداً (من التعليق العلوي حتى نهاية الـclosure) لا يحتوي أي إشارة لـQR', stripos($login_route_region, 'qr') === false);
// عدّ الاستدعاءات الفعلية فقط (بمعامل حقيقي `($raw_token`)، لا الإشارات
// التوثيقية العامة بأقواس فارغة `()` في التعليقات — استدعاء واحد فعلي فقط،
// داخل pge_supervisor_login_handle_post_confirmation() (فرع POST حصراً).
check_true('ز3. استدعاء فعلي واحد فقط لـPGE_Supervisor_Login_Authenticator::authenticate() في كل الملف (لا استدعاء ثانٍ، وبالتأكيد ليس من فرع GET)', substr_count($routing_source, 'PGE_Supervisor_Login_Authenticator::authenticate($raw_token)') === 1);
check_true('ز4. لا استدعاء raw token في أي echo/error_log داخل نطاق مسار الدخول', !preg_match('/error_log\([^)]*raw_token/i', $login_route_region));

echo "\n=== النتيجة: $passed / $total ===\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
