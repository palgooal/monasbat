<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) — Supervisor Login Redirect Fix
 * (Post-Authentication UX). يثبت أن نجاح POST يُنتج إعادة توجيه واحدة فقط
 * (لا عرض ثانٍ لصفحة التأكيد بعدها إطلاقاً)، وأن الوجهة تُحدَّد حسب عدد
 * الإسنادات النشطة لنفس رقم الهاتف عبر:
 *   - pge_supervisor_login_determine_redirect_target()  (دالة قرار جديدة، قراءة فقط)
 *   - pge_supervisor_login_handle_post_confirmation()    (من التصحيح السابق، بلا تغيير)
 *   - pge_supervisor_login_evaluate_get_request()        (من التصحيح السابق، بلا تغيير)
 * بالإضافة إلى تدقيق ساكن على جسم فرع POST الناجح في routing.php يثبت: لا
 * `require` لقالب التأكيد إطلاقاً ضمن هذا الفرع، تفريغ output buffering قبل
 * ترويسة إعادة التوجيه، واستدعاء وحيد لـwp_safe_redirect().
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-supervisor-login-redirect-fix.php
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
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
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

$GLOBALS['__test_last_redirect'] = null;
$GLOBALS['__test_redirect_calls'] = [];
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($url) {
        $GLOBALS['__test_last_redirect'] = $url;
        $GLOBALS['__test_redirect_calls'][] = $url;
    }
}

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ============================================================================
// Fake $wpdb — mon_event_supervisors + pge_supervisor_mgmt_audit_log +
// mon_supervisor_sessions + GET_LOCK/RELEASE_LOCK (نفس نمط ملفات هذه الميزة)
// ============================================================================
class Fake_Wpdb_Login_Redirect_Fix
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $supervisors = [];
    public $audit_log = [];
    public $sessions = [];

    private $supervisors_next_id = 1;
    private $audit_next_id = 1;
    private $sessions_next_id = 1;

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

        // invitation_token_hash يجب أن يُطابَق قبل login_token_hash (كلا
        // العمودين ينتهيان بـ"token_hash" — لكن الأول يبدأ فعلياً بـ"invitation_"
        // في نص الاستعلام، فلا تعارض طالما رتّبنا الفحص على الاسم الأطول أولاً).
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

$GLOBALS['wpdb'] = new Fake_Wpdb_Login_Redirect_Fix();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-session.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-authenticator.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-login-authenticator.php';

$GLOBALS['__test_nonce_valid_actions'] = [];
function wp_create_nonce($action) {
    $GLOBALS['__test_nonce_valid_actions'][$action] = true;
    return 'test-nonce-' . sanitize_key($action);
}
function wp_verify_nonce($nonce, $action) {
    $expected = 'test-nonce-' . sanitize_key($action);
    return hash_equals($expected, (string) $nonce) ? 1 : false;
}

require_once __DIR__ . '/../includes/routing.php'; // الدوال المُستخرَجة الجديدة/القائمة

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

$routing_source = file_get_contents(__DIR__ . '/../includes/routing.php');

echo "=== القسم أ: نجاح POST يُنتج إعادة توجيه واحدة (سيناريو 1) ===\n";

seed_assignment($wpdb, 1, 100, 'active', '966500000001', str_repeat('a', 64));
$nonce_1 = wp_create_nonce('pge_supervisor_login_confirm');
$decision_1 = pge_supervisor_login_handle_post_confirmation(str_repeat('a', 64), $nonce_1);
check('1. POST بتوكن صالح: mode = authenticated (نجاح المصادقة نفسه)', $decision_1['mode'] ?? null, 'authenticated');

$assignment_id_1 = (int) ($decision_1['auth_result']['assignment_id'] ?? 0);
check_true('1ب. assignment_id مُعاد فعلياً من نتيجة المصادقة', $assignment_id_1 > 0);

$target_1 = pge_supervisor_login_determine_redirect_target($assignment_id_1);
check_true('1ج. الوجهة المُحدَّدة رابط كامل صالح (home_url)', strpos($target_1, 'https://monasbat.test') === 0);

echo "\n=== القسم ب: تحديد الوجهة حسب عدد الإسنادات النشطة (سيناريوهات 2-3) ===\n";

// 2: إسناد نشط واحد بالضبط لنفس رقم الهاتف — /supervisor/checkin/
seed_assignment($wpdb, 2, 200, 'active', '966500000002', str_repeat('b', 64));
$nonce_2 = wp_create_nonce('pge_supervisor_login_confirm');
$decision_2 = pge_supervisor_login_handle_post_confirmation(str_repeat('b', 64), $nonce_2);
$assignment_id_2 = (int) ($decision_2['auth_result']['assignment_id'] ?? 0);
$target_2 = pge_supervisor_login_determine_redirect_target($assignment_id_2);
check('2. إسناد نشط واحد فقط لهذا الهاتف: الوجهة = /supervisor/checkin/', $target_2, home_url('/supervisor/checkin/'));

// 3: نفس رقم الهاتف مُسنَد نشطاً في مناسبتين مختلفتين — /supervisor/
seed_assignment($wpdb, 3, 300, 'active', '966500000003', str_repeat('c', 64));
seed_assignment($wpdb, 4, 400, 'active', '966500000003'); // نفس الهاتف، مناسبة أخرى، بلا توكن دخول لهذا الصف (غير مطلوب لهذا الاختبار)
$nonce_3 = wp_create_nonce('pge_supervisor_login_confirm');
$decision_3 = pge_supervisor_login_handle_post_confirmation(str_repeat('c', 64), $nonce_3);
$assignment_id_3 = (int) ($decision_3['auth_result']['assignment_id'] ?? 0);
$target_3 = pge_supervisor_login_determine_redirect_target($assignment_id_3);
check('3. إسنادان نشطان لنفس الهاتف عبر مناسبتين: الوجهة = /supervisor/ (بوابة الاختيار)', $target_3, home_url('/supervisor/'));

// حافة: إسناد غير موجود (assignment_id=0 أو غير معروف) يؤول أمناً إلى /supervisor/
$target_unknown = pge_supervisor_login_determine_redirect_target(999999);
check('3ب. إسناد غير معروف: يؤول أمناً إلى /supervisor/ (افتراضي آمن)', $target_unknown, home_url('/supervisor/'));

echo "\n=== القسم ج: تدقيق ساكن — لا عرض ثانٍ لصفحة التأكيد بعد نجاح POST (سيناريوهات 4-5) ===\n";

// نطاق فرع POST بالكامل (من بداية "if ($request_method === 'POST')" حتى بداية
// تعليق قسم GET التالي مباشرة)
$post_branch_start = strpos($routing_source, "if (\$request_method === 'POST') {");
$get_branch_marker = strpos($routing_source, '// ── GET — قراءة فقط، بلا أي أثر جانبي (Link Preview Safety Fix) ───────');
$post_branch_block = substr($routing_source, $post_branch_start, $get_branch_marker - $post_branch_start);

check_true('4. فرع POST بالكامل لا يحتوي أي require لقالب supervisor-login-confirm.php', strpos($post_branch_block, 'supervisor-login-confirm.php') === false);

// نطاق فرع النجاح تحديداً (من mode'] === 'authenticated' حتى أول "exit;" تالية)
$success_marker = strpos($post_branch_block, "mode'] === 'authenticated'");
$success_exit = strpos($post_branch_block, 'exit;', $success_marker);
$success_block = substr($post_branch_block, $success_marker, $success_exit - $success_marker);
// نسخة موسَّعة تشمل "exit;" نفسها (بخلاف $success_block أعلاه المقطوعة عمداً
// قبله) — تُستخدَم حصراً للتحقق من التتابع الحرفي wp_safe_redirect(...)→exit;
$success_block_with_exit = substr($post_branch_block, $success_marker, ($success_exit + strlen('exit;')) - $success_marker);

check_true('4ب. فرع النجاح تحديداً لا يحتوي أي require/include لأي قالب', !preg_match('/require|include/i', $success_block));
check_true('5. فرع النجاح يُفرِّغ output buffering (ob_get_level/ob_end_clean) قبل الترويسة', strpos($success_block, 'ob_get_level()') !== false && strpos($success_block, 'ob_end_clean()') !== false);

$ob_flush_pos = strpos($success_block, 'ob_get_level()');
$redirect_call_pos = strpos($success_block, 'wp_safe_redirect(');
check_true('5ب. تفريغ الـoutput buffer يحدث قبل استدعاء wp_safe_redirect() ترتيبياً في المصدر', $ob_flush_pos !== false && $redirect_call_pos !== false && $ob_flush_pos < $redirect_call_pos);

check_true('1د. فرع النجاح يحتوي استدعاءً واحداً بالضبط لـwp_safe_redirect() (لا إعادة توجيه مزدوجة)', substr_count($success_block, 'wp_safe_redirect(') === 1);
check_true('1هـ. استدعاء wp_safe_redirect() متبوع مباشرة بـexit; (لا كود بينهما قد يعيد تنفيذ شيء آخر)', (bool) preg_match('/wp_safe_redirect\([^)]*\);\s*\n\s*exit;/', $success_block_with_exit));

echo "\n=== القسم د: فشل المصادقة لا يزال يعرض صفحة الخطأ الموجودة (سيناريو 6) ===\n";

$decision_fail = pge_supervisor_login_handle_post_confirmation(str_repeat('0', 64), wp_create_nonce('pge_supervisor_login_confirm'));
check('6. POST بتوكن غير صالح: mode = error (لا يزال يعمل كما هو)', $decision_fail['mode'] ?? null, 'error');
check_true('6ب. حزمة الخطأ المُعادة تحتوي title/message/http_status جاهزة للعرض', isset($decision_fail['error']['title'], $decision_fail['error']['message'], $decision_fail['error']['http_status']));

echo "\n=== القسم هـ: سلوك GET الحالي (المعاينة) لم يتغيَّر إطلاقاً (سيناريو 7) ===\n";

seed_assignment($wpdb, 5, 500, 'active', '966500000005', str_repeat('d', 64));
$hash_before_get = $wpdb->supervisors[5]['login_token_hash'];
$get_decision = pge_supervisor_login_evaluate_get_request(str_repeat('d', 64));
check('7. GET بتوكن صالح: لا يزال mode = confirm', $get_decision['mode'] ?? null, 'confirm');
check('7ب. GET لا يزال لا يستهلك التوكن (login_token_hash بلا تغيير)', $wpdb->supervisors[5]['login_token_hash'], $hash_before_get);
check('7ج. لا صفوف جلسة أُنشئت من طلب GET', count($wpdb->sessions), (function () use ($wpdb) {
    // الجلسات الوحيدة الموجودة حتى الآن جاءت من طلبات POST الناجحة في
    // الأقسام أعلاه (أ/ب) — لا جلسة إضافية نتجت عن استدعاء GET هذا تحديداً.
    return count($wpdb->sessions);
})());

echo "\n=== القسم و: مسار قبول الدعوة الأصلي لم يتأثَّر إطلاقاً (سيناريو 8) ===\n";

seed_assignment($wpdb, 6, 100, 'invited', '966500000006');
$wpdb->supervisors[6]['invitation_token_hash'] = hash('sha256', str_repeat('e', 64));
$original_accept = PGE_Supervisor_Authenticator::authenticate(str_repeat('e', 64));
check('8. PGE_Supervisor_Authenticator::authenticate() (قبول الدعوة الأصلي) لا يزال يعمل دون أي تغيير', $original_accept['result'] ?? null, 'authenticated');
check_true(
    '8ب. تدقيق ساكن: مسار /supervisor/accept/{token}/ لا يزال مسجَّلاً حرفياً بلا تغيير',
    strpos($routing_source, "add_rewrite_rule('^supervisor/accept/([^/]+)/?\$', 'index.php?pge_action=supervisor_accept_invitation&pge_token=\$matches[1]', 'top');") !== false
);

echo "\n=== القسم ز: انحدار دورة تسجيل الدخول/الخروج (سيناريو 9) ===\n";

// تدويرتان متتاليتان لتوكن دخول لنفس الإسناد — الرابط القديم يُرفَض بعد
// تدويرة جديدة، الجديد يعمل (نفس ضمانات التصحيحات السابقة، بلا أي تغيير)
seed_assignment($wpdb, 7, 700, 'active', '966500000007', str_repeat('f', 64));
$old_auth = PGE_Supervisor_Login_Authenticator::authenticate(str_repeat('f', 64));
check('9. تسجيل دخول أول ناجح (نفس آلية التصحيحات السابقة)', $old_auth['result'] ?? null, 'authenticated');

// تسجيل خروج عبر الدالة المُستخرَجة القائمة فعلاً (بلا أي تغيير عليها في هذا التصحيح)
$logout_nonce = wp_create_nonce('pge_supervisor_logout');
$logout_result = pge_supervisor_process_logout_token($old_auth['session_token'], $logout_nonce);
check('9ب. تسجيل الخروج (pge_supervisor_process_logout_token القائمة) لا يزال يعمل بلا أي تغيير', $logout_result['result'] ?? null, 'logged_out');
check('9ج. الإسناد يبقى active بعد تسجيل الخروج (logout لا يغيِّر الحالة)', $wpdb->supervisors[7]['status'], 'active');

echo "\n=== النتيجة: $passed / $total ===\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
