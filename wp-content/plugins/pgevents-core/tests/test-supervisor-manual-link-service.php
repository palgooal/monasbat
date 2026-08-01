<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) — Supervisor Manual Invitation Link:
 * Secure One-Time Generation، تنفيذ. يغطي:
 *   - PGE_Supervisor_Manual_Link_Service::generate() (الترتيب الآمن الكامل:
 *     تفويض مُفتَرَض من الطبقة المستدعية ← قفل ← إعادة قراءة ← أهلية ← توليد
 *     بلا كتابة ← بناء رابط ← التزام ذري ← إبطال ضمني للقديم ← تدقيق نجاح فقط
 *     ← تحرير قفل).
 *   - pge_supervisor_mgmt_manual_link_handler() (AJAX) — nonce/تسجيل دخول/
 *     تفويض مضيف/عزل مناسبات مختلفة، بإعادة استخدام pge_mgmt_validate_
 *     request()/pge_supervisor_mgmt_load_owned_assignment() الحقيقيتين.
 *   - تكامل كامل مع PGE_Supervisor_Authenticator/PGE_Supervisor_Session
 *     الحقيقيتين: الرابط المُولَّد يُقبَل فعلياً عبر نفس مسار /supervisor/
 *     accept/{token}/ القائم، والرابط السابق يُرفَض بعد تدويرة جديدة.
 *   - عدم وجود أي استدعاء لـwp_remote_post (لا اتصال Cartat إطلاقاً من هذه
 *     الميزة على الإطلاق — spy يُفحَص في نهاية الملف).
 *
 * "Use fake database/transport where required. Do not call the real Cartat
 * API." — wp_remote_post() هنا دالة وهمية فقط لإثبات عدم استدعائها، لا
 * لمحاكاة استجابة ناجحة (هذه الميزة لا تستدعيها إطلاقاً في مسارها الطبيعي).
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-supervisor-manual-link-service.php
 * (أو أي php-wasm harness مكافئ في هذا المشروع)، أو php tests/test-supervisor-manual-link-service.php مباشرة.
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

$GLOBALS['__test_now'] = '2026-01-01 00:00:00';
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now']; }
}

if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://monasbat.test' . $path; }
}

// ── wp_remote_post() وهمية — يجب ألّا تُستدعى إطلاقاً من هذه الميزة (لا
// Cartat هنا بأي شكل). التسجيل هنا فقط لإثبات "صفر استدعاءات" في نهاية الملف. ──
$GLOBALS['__test_remote_post_calls'] = [];
if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = [])
    {
        $GLOBALS['__test_remote_post_calls'][] = ['url' => $url, 'args' => $args];
        return ['body' => json_encode(['status' => 'sent', 'id' => 'should-not-be-called'])];
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return is_array($response) ? ($response['body'] ?? '') : ''; }
}
if (!function_exists('get_option')) {
    // لا خيارات Cartat مضبوطة إطلاقاً هنا عمداً — لو حاول أي كود في هذه
    // الميزة قراءة pge_wa_provider/pge_cartat_* لَفشل بصمت (يعيد false)،
    // فيثبت عملياً أن الخدمة الجديدة لا تعتمد عليها إطلاقاً.
    function get_option($name, $default = false) { return $default; }
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

// ── تفويض/جلسة — نفس أسلوب test-supervisor-management.php حرفياً ───────────
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null) {
    if ($cap === 'administrator') { return $GLOBALS['__test_user_is_admin']; }
    return false;
}

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ============================================================================
// Fake $wpdb — mon_event_supervisors + pge_supervisor_mgmt_audit_log +
// mon_supervisor_sessions (إدراج فقط، للتكامل مع Authenticator) + GET_LOCK
// ============================================================================
class Fake_Wpdb_Manual_Link
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
        if ($which !== 'supervisors') return null;

        if (preg_match("/WHERE\s+invitation_token_hash\s*=\s*'([a-f0-9]{64})'/i", $sql, $m)) {
            $hash = $m[1];
            foreach ($this->supervisors as $row) {
                if (($row['invitation_token_hash'] ?? null) === $hash) return $row;
            }
            return null;
        }

        if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
            $id = (int) $m[1];
            return $this->supervisors[$id] ?? null;
        }

        return null;
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
        if ($which !== 'supervisors') return false;

        if ($this->force_commit_conflict_once && array_key_exists('invitation_token_hash', $data) && array_key_exists('status', $where)) {
            $this->force_commit_conflict_once = false;
            return 0;
        }

        $id = $where['id'] ?? null;
        if ($id === null || !isset($this->supervisors[$id])) return 0;
        foreach ($where as $where_key => $where_value) {
            $current_value = $this->supervisors[$id][$where_key] ?? null;
            if ((string) $current_value !== (string) $where_value) return 0;
        }
        foreach ($data as $k => $v) { $this->supervisors[$id][$k] = $v; }
        return 1;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Manual_Link();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── تفويض المضيف الحقيقي — نفس pge_event_guests_user_can_manage() المُستخدَمة
// فعلياً في event-guests.php، مُبسَّطة هنا محلياً (لا تحميل event-guests.php
// كاملاً؛ نفس التبسيط المعتمَد في test-supervisor-cartat-delivery.php: مالك
// المناسبة أو Administrator فقط) ──
if (!function_exists('pge_event_guests_user_can_manage')) {
    function pge_event_guests_user_can_manage($event_id)
    {
        $post = get_post($event_id);
        if (!$post) return false;
        if (current_user_can('administrator')) return true;
        return (int) $post->post_author === get_current_user_id();
    }
}

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-manual-link-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-session.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-authenticator.php';

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

function seed_assignment($wpdb, $id, $event_id, $status, $phone, $raw_token = null, $name = 'مشرف الاختبار')
{
    $hash = $raw_token !== null ? hash('sha256', $raw_token) : null;
    $wpdb->supervisors[$id] = [
        'id' => $id,
        'event_id' => $event_id,
        'user_id' => null,
        'supervisor_phone' => $phone,
        'supervisor_name' => $name,
        'status' => $status,
        'invitation_token_hash' => $hash,
        'invited_by_user_id' => 501,
        'invited_at' => $GLOBALS['__test_now'],
        'accepted_at' => null,
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

function extract_token_from_url(string $url): string
{
    if (preg_match('#/supervisor/accept/([a-f0-9]{64})/#', $url, $m)) return $m[1];
    return '';
}

// ============================================================================
// القسم أ: PGE_Supervisor_Manual_Link_Service::generate() — استدعاء مباشر
// ============================================================================
echo "=== القسم أ: PGE_Supervisor_Manual_Link_Service::generate() ===\n";

// أ1-أ2: النجاح — invited وpending كلاهما مؤهَّل (اختبارا 6-7 من قائمة التكليف)
seed_assignment($wpdb, 1, 100, 'invited', '966500000001');
$r1 = PGE_Supervisor_Manual_Link_Service::generate(1, 501);
check('أ1. invited: result = generated', $r1['result'] ?? null, 'generated');
check_true('أ1ب. invited: invitation_url يحمل مسار /supervisor/accept/', strpos($r1['invitation_url'] ?? '', '/supervisor/accept/') !== false);

seed_assignment($wpdb, 2, 100, 'pending', '966500000002');
$r2 = PGE_Supervisor_Manual_Link_Service::generate(2, 501);
check('أ2. pending: result = generated', $r2['result'] ?? null, 'generated');

// أ3-أ5: الحالات غير المؤهَّلة الثلاث المتبقية (active/revoked/expired) — تُرفَض جميعاً
seed_assignment($wpdb, 3, 100, 'active', '966500000003');
$r3 = PGE_Supervisor_Manual_Link_Service::generate(3, 501);
check('أ3. active: result = error', $r3['result'] ?? null, 'error');
check('أ3ب. active: reason = not_eligible', $r3['reason'] ?? null, 'not_eligible');
check_true('أ3ج. active: لا invitation_url في الاستجابة', !array_key_exists('invitation_url', $r3));

seed_assignment($wpdb, 4, 100, 'revoked', '966500000004');
$r4 = PGE_Supervisor_Manual_Link_Service::generate(4, 501);
check('أ4. revoked: result = error / not_eligible', $r4['reason'] ?? null, 'not_eligible');

seed_assignment($wpdb, 5, 100, 'expired', '966500000005');
$r5 = PGE_Supervisor_Manual_Link_Service::generate(5, 501);
check('أ5. expired: result = error / not_eligible', $r5['reason'] ?? null, 'not_eligible');

// أ6: إسناد غير موجود إطلاقاً
$r6 = PGE_Supervisor_Manual_Link_Service::generate(9999, 501);
check('أ6. إسناد غير موجود: reason = assignment_not_found', $r6['reason'] ?? null, 'assignment_not_found');

// أ7: معرّف غير صالح
$r7 = PGE_Supervisor_Manual_Link_Service::generate(0, 501);
check('أ7. معرّف غير صالح: reason = invalid_assignment_id', $r7['reason'] ?? null, 'invalid_assignment_id');

// أ8-أ9: القفل — منشغل يُرفَض فوراً بلا أي تغيير
seed_assignment($wpdb, 6, 100, 'invited', '966500000006');
$wpdb->force_lock_busy_once = true;
$before_hash = $wpdb->supervisors[6]['invitation_token_hash'];
$r8 = PGE_Supervisor_Manual_Link_Service::generate(6, 501);
check('أ8. قفل منشغل: result = error / lock_busy', $r8['reason'] ?? null, 'lock_busy');
check('أ9. قفل منشغل: لا تغيير على هاش التوكن', $wpdb->supervisors[6]['invitation_token_hash'], $before_hash);
check('أ9ب. قفل منشغل: لا سجل تدقيق نجاح', in_array('manual_link_generated', audit_actions_for($wpdb, 6), true), false);

// أ10: اسم القفل مستقل عن قفل تسليم Cartat (بادئة مختلفة تماماً)
check_true('أ10. اسم القفل يبدأ بـ pge_supervisor_manual_link_', strpos(end($wpdb->lock_acquire_log), 'pge_supervisor_manual_link_') === 0);

// أ11: القفل يُحرَّر دائماً (عدد التحرير = عدد الاكتساب الناجح لهذه الميزة تحديداً)
$acquired_manual = count(array_filter($wpdb->lock_acquire_log, function ($n) { return strpos($n, 'pge_supervisor_manual_link_') === 0; }));
$released_manual = count(array_filter($wpdb->lock_release_log, function ($n) { return strpos($n, 'pge_supervisor_manual_link_') === 0; }));
check('أ11. كل قفل مُكتسَب لهذه الميزة حُرِّر (acquire === release)', $acquired_manual, $released_manual);

// أ12-أ14: التزام فاشل (تغيّر الحالة تحت القفل) — لا رابط، لا تدقيق نجاح، القديم يبقى سارياً
seed_assignment($wpdb, 7, 100, 'invited', '966500000007', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$old_hash_7 = $wpdb->supervisors[7]['invitation_token_hash'];
$wpdb->force_commit_conflict_once = true;
$r12 = PGE_Supervisor_Manual_Link_Service::generate(7, 501);
check('أ12. فشل التزام: result = error / token_commit_failed', $r12['reason'] ?? null, 'token_commit_failed');
check_true('أ13. فشل التزام: لا invitation_url في الاستجابة', !array_key_exists('invitation_url', $r12));
check('أ14. فشل التزام: هاش التوكن القديم لم يتغيّر', $wpdb->supervisors[7]['invitation_token_hash'], $old_hash_7);
check('أ14ب. فشل التزام: لا سجل تدقيق نجاح', in_array('manual_link_generated', audit_actions_for($wpdb, 7), true), false);

// أ15-أ16: نجاح — التزام فعلي + إبطال التوكن القديم ضمنياً (هاش مختلف)
seed_assignment($wpdb, 8, 100, 'pending', '966500000008', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
$old_hash_8 = $wpdb->supervisors[8]['invitation_token_hash'];
$r15 = PGE_Supervisor_Manual_Link_Service::generate(8, 501);
check('أ15. نجاح: result = generated', $r15['result'] ?? null, 'generated');
check_true('أ16. نجاح: هاش جديد مختلف عن القديم (إبطال ضمني)', $wpdb->supervisors[8]['invitation_token_hash'] !== $old_hash_8);

// أ17-أ18: تدقيق — حدث واحد بالضبط عند النجاح، بلا أي بيانات حسّاسة
$audit_actions_8 = audit_actions_for($wpdb, 8);
check('أ17. حدث تدقيق واحد بالضبط عند النجاح', count(array_filter($audit_actions_8, function ($a) { return $a === 'manual_link_generated'; })), 1);
$audit_row_8 = null;
foreach ($wpdb->audit_log as $row) {
    if ((int) $row['assignment_id'] === 8 && $row['action'] === 'manual_link_generated') { $audit_row_8 = $row; break; }
}
check_true('أ18. صف التدقيق: لا حقل invitation_url/token/hash/phone/message', $audit_row_8 !== null
    && !array_key_exists('invitation_url', $audit_row_8)
    && !array_key_exists('token', $audit_row_8)
    && !array_key_exists('invitation_token', $audit_row_8)
    && !array_key_exists('phone', $audit_row_8)
    && !array_key_exists('message', $audit_row_8));
check('أ18ب. صف التدقيق: reason فارغ (لا فئة فشل مُخزَّنة لحدث نجاح)', $audit_row_8['reason'] ?? '', '');

// أ19: لا تخزين للتوكن الخام في أي مكان في الجدول الوهمي (فقط الهاش)
$raw_token_8 = extract_token_from_url($r15['invitation_url']);
check_true('أ19. التوكن الخام غير مُخزَّن نصاً في صف الإسناد', $raw_token_8 !== '' && strpos((string) $wpdb->supervisors[8]['invitation_token_hash'], $raw_token_8) === false);
check('أ19ب. الهاش المُخزَّن = sha256(التوكن الخام)', $wpdb->supervisors[8]['invitation_token_hash'], hash('sha256', $raw_token_8));

echo "\n=== القسم ب: تكامل حقيقي مع مسار القبول (PGE_Supervisor_Authenticator/Session) ===\n";

// ب1: الرابط المُولَّد يُقبَل فعلياً عبر Authenticator الحقيقي
seed_assignment($wpdb, 9, 100, 'invited', '966500000009');
$rb1 = PGE_Supervisor_Manual_Link_Service::generate(9, 501);
$token_b1 = extract_token_from_url($rb1['invitation_url']);
$auth_b1 = PGE_Supervisor_Authenticator::authenticate($token_b1);
check('ب1. الرابط المُولَّد يُقبَل فعلياً عبر authenticate() الحقيقية', $auth_b1['result'] ?? null, 'authenticated');
check('ب1ب. الإسناد أصبح active فعلياً بعد القبول', $wpdb->supervisors[9]['status'], 'active');

// ب2-ب3: تدويرة ثانية تُبطِل رابطاً سابقاً لم يُستخدَم بعد
seed_assignment($wpdb, 10, 100, 'invited', '966500000010');
$rb2_first = PGE_Supervisor_Manual_Link_Service::generate(10, 501);
$token_first = extract_token_from_url($rb2_first['invitation_url']);
$rb2_second = PGE_Supervisor_Manual_Link_Service::generate(10, 501);
$token_second = extract_token_from_url($rb2_second['invitation_url']);
check_true('ب2. تدويرة ثانية تُنتج توكناً مختلفاً عن الأولى', $token_first !== $token_second && $token_first !== '' && $token_second !== '');

$auth_old = PGE_Supervisor_Authenticator::authenticate($token_first);
check_true('ب3. الرابط الأول يُرفَض بعد التدويرة الثانية (غير موثَّق كـauthenticated)', ($auth_old['result'] ?? '') !== 'authenticated');

$auth_new = PGE_Supervisor_Authenticator::authenticate($token_second);
check('ب3ب. الرابط الثاني (الأحدث) يُقبَل فعلياً', $auth_new['result'] ?? null, 'authenticated');

echo "\n=== القسم ج: معالج AJAX — pge_supervisor_mgmt_manual_link_handler() ===\n";

$GLOBALS['__test_current_user_id'] = 501; // مالك المناسبة 100
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
set_test_event(100, 501);
set_test_event(200, 601); // مناسبة أخرى، مالك مختلف — لعزل المناسبات

// ج1: مضيف مخوَّل (مالك المناسبة) — نجاح، عقد الاستجابة يحتوي invitation_url فقط
seed_assignment($wpdb, 11, 100, 'invited', '966500000011');
$_POST = make_post_fields(100, ['assignment_id' => 11]);
$resp_c1 = call_ajax_handler('pge_supervisor_mgmt_manual_link_handler');
check_true('ج1. مضيف مخوَّل: success = true', $resp_c1['success'] === true);
check_true('ج1ب. عقد الاستجابة: invitation_url موجود ونصّي غير فارغ', is_string($resp_c1['data']['invitation_url'] ?? null) && $resp_c1['data']['invitation_url'] !== '');
check_true('ج1ج. عقد الاستجابة: لا حقل token/hash/assignment_id/phone إضافي', !array_key_exists('token', $resp_c1['data'])
    && !array_key_exists('invitation_token', $resp_c1['data'])
    && !array_key_exists('hash', $resp_c1['data'])
    && !array_key_exists('assignment_id', $resp_c1['data'])
    && !array_key_exists('phone', $resp_c1['data']));
check('ج1د. عقد الاستجابة: مفتاحان فقط داخل data (invitation_url حصراً)', array_keys($resp_c1['data']), ['invitation_url']);

// ج2: مسؤول (Administrator) — نجاح أيضاً وإن لم يكن مالك المناسبة
$GLOBALS['__test_current_user_id'] = 999;
$GLOBALS['__test_user_is_admin'] = true;
seed_assignment($wpdb, 12, 100, 'invited', '966500000012');
$_POST = make_post_fields(100, ['assignment_id' => 12]);
$resp_c2 = call_ajax_handler('pge_supervisor_mgmt_manual_link_handler');
check_true('ج2. Administrator (ليس مالكاً): success = true', $resp_c2['success'] === true);
$GLOBALS['__test_user_is_admin'] = false;

// ج3: مستخدم غير مخوَّل (ليس مالكاً وليس Administrator) — رفض بلا توليد
$GLOBALS['__test_current_user_id'] = 777;
seed_assignment($wpdb, 13, 100, 'invited', '966500000013');
$hash_before_13 = $wpdb->supervisors[13]['invitation_token_hash'];
$_POST = make_post_fields(100, ['assignment_id' => 13]);
$resp_c3 = call_ajax_handler('pge_supervisor_mgmt_manual_link_handler');
check_true('ج3. مستخدم غير مخوَّل: success = false', $resp_c3['success'] === false);
check('ج3ب. مستخدم غير مخوَّل: reason = forbidden', $resp_c3['data']['reason'] ?? null, 'forbidden');
check('ج3ج. مستخدم غير مخوَّل: لا تغيير على هاش التوكن', $wpdb->supervisors[13]['invitation_token_hash'], $hash_before_13);

// ج4: nonce غير صالح
$GLOBALS['__test_current_user_id'] = 501;
$_POST = make_post_fields(100, ['assignment_id' => 11]);
$_POST['nonce'] = 'invalid-nonce-value';
$resp_c4 = call_ajax_handler('pge_supervisor_mgmt_manual_link_handler');
check('ج4. nonce غير صالح: reason = invalid_nonce', $resp_c4['data']['reason'] ?? null, 'invalid_nonce');

// ج5: عزل مناسبات مختلفة — الإسناد 11 يخصّ المناسبة 100، محاولة الوصول إليه عبر المناسبة 200 يجب أن تُرفَض
$GLOBALS['__test_current_user_id'] = 601; // مالك المناسبة 200 فعلياً (مخوَّل لمناسبته، لا لإسناد غيرها)
$_POST = make_post_fields(200, ['assignment_id' => 11]);
$resp_c5 = call_ajax_handler('pge_supervisor_mgmt_manual_link_handler');
check_true('ج5. عزل مناسبات مختلفة: success = false', $resp_c5['success'] === false);
check('ج5ب. عزل مناسبات مختلفة: reason = not_found (بلا تسريب تمييز)', $resp_c5['data']['reason'] ?? null, 'not_found');

// ج6: assignment_id غير موجود إطلاقاً
$GLOBALS['__test_current_user_id'] = 501;
$_POST = make_post_fields(100, ['assignment_id' => 987654]);
$resp_c6 = call_ajax_handler('pge_supervisor_mgmt_manual_link_handler');
check('ج6. assignment_id غير موجود: reason = not_found', $resp_c6['data']['reason'] ?? null, 'not_found');

// ج7: حالة غير مؤهَّلة (active) عبر AJAX — رسالة عربية واضحة، لا رابط
seed_assignment($wpdb, 14, 100, 'active', '966500000014');
$_POST = make_post_fields(100, ['assignment_id' => 14]);
$resp_c7 = call_ajax_handler('pge_supervisor_mgmt_manual_link_handler');
check_true('ج7. حالة active: success = false', $resp_c7['success'] === false);
check_true('ج7ب. حالة active: رسالة عربية غير فارغة، لا استثناء PHP مكشوف', is_string($resp_c7['data']['message'] ?? null) && strpos($resp_c7['data']['message'], 'Exception') === false && strpos($resp_c7['data']['message'], 'SQL') === false);

// ج8: طلبان متتاليان (كل واحد طلب AJAX واحد فقط لكل توليد — لا طلبات مكرَّرة داخل الاستدعاء نفسه)
$audit_before_c8 = count($wpdb->audit_log);
seed_assignment($wpdb, 15, 100, 'invited', '966500000015');
$_POST = make_post_fields(100, ['assignment_id' => 15]);
call_ajax_handler('pge_supervisor_mgmt_manual_link_handler');
$audit_after_c8 = count($wpdb->audit_log);
check('ج8. استدعاء واحد ينتج صف تدقيق واحد بالضبط', $audit_after_c8 - $audit_before_c8, 1);

echo "\n=== القسم د: انحدار (Regression) — لا أثر على مسارات أخرى ===\n";

// د1: إعادة إرسال Cartat غير مُحمَّلة في هذا الملف إطلاقاً (فصل تام) — التأكد
// من أن هذا الملف نفسه لا يحمّل PGE_Supervisor_Invitation_Delivery ولا
// PGE_Cartat_Transport، إثباتاً عملياً أن الميزة الجديدة مستقلة عنهما تماماً.
check('د1. PGE_Cartat_Transport غير مُحمَّلة في نطاق اختبار هذه الميزة', class_exists('PGE_Cartat_Transport', false), false);
check('د1ب. PGE_Supervisor_Invitation_Delivery غير مُحمَّلة في نطاق اختبار هذه الميزة', class_exists('PGE_Supervisor_Invitation_Delivery', false), false);

// د2: قائمة/إنشاء/تعديل/إلغاء المشرفين لا تزال تعمل (نفس المعالجات القديمة سليمة)
seed_assignment($wpdb, 16, 100, 'invited', '966500000016', null, 'قبل التعديل');
$GLOBALS['__test_current_user_id'] = 501;
$_POST = make_post_fields(100, ['assignment_id' => 16, 'name' => 'بعد التعديل', 'phone' => '966500000016']);
$resp_d2 = call_ajax_handler('pge_supervisor_mgmt_edit_handler');
check_true('د2. pge_supervisor_mgmt_edit_handler لا تزال تعمل بعد إضافة الميزة الجديدة', $resp_d2['success'] === true);

$_POST = make_post_fields(100, ['assignment_id' => 16, 'reason' => '']);
$resp_d2b = call_ajax_handler('pge_supervisor_mgmt_revoke_handler');
check_true('د2ب. pge_supervisor_mgmt_revoke_handler لا تزال تعمل بعد إضافة الميزة الجديدة', $resp_d2b['success'] === true);

echo "\n=== القسم هـ: صفر استدعاءات Cartat عبر كامل هذا الملف ===\n";
check('هـ1. wp_remote_post() لم تُستدعَ ولو مرة واحدة عبر كامل الملف', count($GLOBALS['__test_remote_post_calls']), 0);

echo "\n=== النتيجة: $passed / $total ===\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
