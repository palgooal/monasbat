<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) — Supervisor Invitation Delivery via
 * Cartat، تنفيذ. يغطي الطبقات الثلاث الجديدة تحديداً في هذا الملف:
 *   - PGE_Cartat_Transport (طبقة النقل المشتركة، Option B)
 *   - إضافتا generate_delivery_token()/commit_new_token_hash() على
 *     PGE_Supervisor_Assignment_Service (تدوير التوكن الآمن)
 *   - PGE_Supervisor_Invitation_Delivery::deliver() (التنسيق الكامل: أهلية ←
 *     قفل ← توليد توكن ← إرسال ← التزام/رفض ← تدقيق)
 *   - pge_supervisor_accept_token_shape_valid()/pge_supervisor_accept_
 *     classify_auth_error() (دوال المحوِّل النقية في includes/routing.php)
 *
 * لا يستدعي إغلاق (closure) معالج template_redirect نفسه في routing.php
 * مباشرة — ذاك ينتهي دائماً بـexit() الحقيقية (نفس القيد الموثَّق فعلياً في
 * سابقة المشروع: tests/test-supervisor-portal.php يختبر PGE_Supervisor_
 * Portal_Middleware/Bootstrap فقط، لا قالب supervisor-portal.php الذي ينتهي
 * بـexit() أيضاً). المنطق الفعلي القابل للاختبار من ذلك المعالج (تحقّق شكل
 * التوكن + تصنيف رسالة الخطأ) استُخرِج عمداً لدالتين نقيتين تحديداً لهذا
 * السبب — راجع توثيقهما في routing.php.
 *
 * "Use a fake/injected Cartat transport. Do not call the real Cartat API
 * during tests." — لا اتصال شبكي حقيقي إطلاقاً؛ wp_remote_post() هنا دالة
 * وهمية بالكامل، قابلة للتحكم بالكامل عبر $GLOBALS لكل سيناريو.
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-supervisor-cartat-delivery.php
 * (أو أي php-wasm harness مكافئ في هذا المشروع).
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
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('rawurlencode') === false) {
    // rawurlencode() دالة PHP أصلية دائماً — لا حاجة لتعريفها، فقط تأكيد وجودها.
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

// ── current_time() قابل للتحكم صراحة (بنفس أسلوب ملفات Ledger/Queue السابقة
// في هذا المشروع) — يُثبِت اختلاف invited_at قبل/بعد تدوير التوكن ────────────
$GLOBALS['__test_now'] = '2026-01-01 00:00:00';
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now']; }
}

// ── wp_options وهمية — نفس مفاتيح Cartat الثلاثة حصراً ──────────────────────
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
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['__test_wa_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://monasbat.test' . $path; }
}

// ── wp_remote_post() وهمية بالكامل، قابلة للتحكم لكل سيناريو + تسجيل كل
// استدعاء (لإثبات "لم يُستدعَ Cartat إطلاقاً" في مسارات الرفض المبكر) ────────
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

// ── get_post() وهمية (لاسم المناسبة في نص الرسالة) ─────────────────────────
$GLOBALS['__test_posts'] = [];
function set_test_event_post($event_id, $title)
{
    $GLOBALS['__test_posts'][$event_id] = (object) ['ID' => $event_id, 'post_title' => $title];
}
function get_post($event_id)
{
    return $GLOBALS['__test_posts'][$event_id] ?? null;
}

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ============================================================================
// Fake $wpdb — mon_event_supervisors + pge_supervisor_mgmt_audit_log +
// mon_supervisor_sessions (إدراج فقط) + GET_LOCK/RELEASE_LOCK
// ============================================================================
class Fake_Wpdb_Cartat_Delivery
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

    // ── تحكّم اختباري صريح لسيناريوهات لا يمكن بلوغها إلا بالتلاعب المباشر ──
    public $force_lock_busy_once = false;
    public $force_commit_conflict_once = false;

    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }

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
        if (strpos($sql_or_table, $this->prefix . 'mon_event_supervisors') !== false) {
            return 'supervisors';
        }
        if (strpos($sql_or_table, $this->prefix . 'pge_supervisor_mgmt_audit_log') !== false) {
            return 'audit';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_supervisor_sessions') !== false) {
            return 'sessions';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which !== 'supervisors') {
            return null;
        }

        // accept_invitation(): بحث بالـhash.
        if (preg_match("/WHERE\s+invitation_token_hash\s*=\s*'([a-f0-9]{64})'/i", $sql, $m)) {
            $hash = $m[1];
            foreach ($this->supervisors as $row) {
                if (($row['invitation_token_hash'] ?? null) === $hash) {
                    return $row;
                }
            }
            return null;
        }

        // find_by_id (get_assignment_state الداخلية).
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
        if ($which !== 'supervisors') {
            return false;
        }

        // تحكّم اختباري صريح: محاكاة "تغيّر حالة متزامن" تحديداً عند تنفيذ
        // commit_new_token_hash() (تُميَّز بوجود 'invitation_token_hash' ضمن
        // $data و'status' ضمن $where معاً) — يُستهلَك مرة واحدة فقط.
        if ($this->force_commit_conflict_once && array_key_exists('invitation_token_hash', $data) && array_key_exists('status', $where)) {
            $this->force_commit_conflict_once = false;
            return 0;
        }

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
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Cartat_Delivery();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-cartat-transport.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-invitation-delivery.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-session.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-authenticator.php';
require_once __DIR__ . '/../includes/routing.php'; // pge_supervisor_accept_token_shape_valid()/pge_supervisor_accept_classify_auth_error() فقط

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

/**
 * إسناد بذرة مباشر إلى الجدول الوهمي — بلا استدعاء create_supervisor_
 * assignment() الحقيقية (تلك تحتاج pge_resolve_supervisor_quota_status()
 * غير المُحمَّلة هنا عمداً؛ خارج نطاق هذا الملف — راجع tests/test-supervisor-
 * management.php لتغطيتها). الحقول مطابقة تماماً لما تكتبه create_
 * supervisor_assignment() الحقيقية فعلياً (نفس الأعمدة بالضبط).
 */
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
    if ($id >= 1000) { /* لا تأثير — فقط لتفادي تحذير عدم استخدام معامل مستقبلاً */ }
    return $id;
}

function audit_actions_for($wpdb, $assignment_id)
{
    return array_values(array_map(function ($r) { return $r['action']; }, array_filter($wpdb->audit_log, function ($r) use ($assignment_id) {
        return (int) $r['assignment_id'] === $assignment_id;
    })));
}

function audit_rows_for($wpdb, $assignment_id, $action)
{
    return array_values(array_filter($wpdb->audit_log, function ($r) use ($assignment_id, $action) {
        return (int) $r['assignment_id'] === $assignment_id && $r['action'] === $action;
    }));
}

// ============================================================================
// القسم أ: PGE_Cartat_Transport — طبقة النقل المشتركة
// ============================================================================
echo "=== القسم أ: PGE_Cartat_Transport ===\n";

$GLOBALS['__test_wa_options']['pge_cartat_api_token'] = 'test-token-abc';
$transport_a = new PGE_Cartat_Transport();
check_true('أ1. has_credentials() صحيح مع توكن مضبوط', $transport_a->has_credentials());

$GLOBALS['__test_wa_options']['pge_cartat_api_token'] = '';
$transport_a_empty = new PGE_Cartat_Transport();
check_true('أ2. has_credentials() خطأ مع توكن فارغ', !$transport_a_empty->has_credentials());
$GLOBALS['__test_wa_options']['pge_cartat_api_token'] = 'test-token-abc';

$transport_fmt = new PGE_Cartat_Transport();
check('أ3. format_number(): 0-محلي يستبدل بكود الدولة', $transport_fmt->format_number('0501234567'), '966501234567');
check('أ4. format_number(): 00-دولي يُزال الصفران', $transport_fmt->format_number('00966501234567'), '966501234567');
check('أ5. format_number(): رقم قصير بلا كود دولة يُضاف له', $transport_fmt->format_number('501234'), '966501234');
check('أ6. format_number(): رقم يحمل كود الدولة مسبقاً لا يتغيّر', $transport_fmt->format_number('972501234567'), '972501234567');

check('أ7. interpret_result(null) => transport_error', $transport_fmt->interpret_result(null), 'transport_error');
check('أ8. interpret_result(status=error) => rejected', $transport_fmt->interpret_result(['status' => 'error']), 'rejected');
check('أ9. interpret_result(success=false) => rejected', $transport_fmt->interpret_result(['success' => false]), 'rejected');
check('أ10. interpret_result(status=sent) => accepted', $transport_fmt->interpret_result(['status' => 'sent']), 'accepted');
check('أ11. interpret_result([]) => accepted (غياب الحقلين)', $transport_fmt->interpret_result([]), 'accepted');

reset_remote_post_spy();
$transport_fmt->send_text('966500000000', 'رسالة اختبار');
check('أ12. send_text(): استدعاء واحد فقط لـwp_remote_post', count($GLOBALS['__test_remote_post_calls']), 1);
check_true('أ12ب. send_text(): المسار /message/text', strpos($GLOBALS['__test_remote_post_calls'][0]['url'], '/message/text') !== false);
check_true('أ12ج. send_text(): ترويسة Authorization تحمل التوكن', strpos($GLOBALS['__test_remote_post_calls'][0]['args']['headers']['Authorization'] ?? '', 'test-token-abc') !== false);

reset_remote_post_spy();
$transport_fmt->send_media('966500000000', 'https://example.test/x.png', 'caption');
check_true('أ13. send_media(): المسار /message/media', strpos($GLOBALS['__test_remote_post_calls'][0]['url'], '/message/media') !== false);

// ============================================================================
// القسم ب: تدوير التوكن الآمن — generate_delivery_token()/commit_new_token_hash()
// ============================================================================
echo "\n=== القسم ب: تدوير التوكن الآمن ===\n";

$token_b1 = PGE_Supervisor_Assignment_Service::generate_delivery_token();
check_true('ب1. generate_delivery_token(): raw بطول 64 حرف hex', (bool) preg_match('/^[a-f0-9]{64}$/', $token_b1['raw']));
check('ب2. generate_delivery_token(): hash = sha256(raw)', $token_b1['hash'], hash('sha256', $token_b1['raw']));

$token_b2 = PGE_Supervisor_Assignment_Service::generate_delivery_token();
check_true('ب3. استدعاءان متتاليان يولّدان قيمتين مختلفتين تماماً', $token_b1['raw'] !== $token_b2['raw']);

seed_assignment($wpdb, 100, 9100, 'invited', '0501000001', 'old-raw-token-b');
$commit_b1 = PGE_Supervisor_Assignment_Service::commit_new_token_hash(100, 'invited', $token_b1['hash']);
check('ب4. commit_new_token_hash(): نجاح -> committed', $commit_b1['result'] ?? null, 'committed');
check('ب4ب. invitation_token_hash استُبدِل فعلياً في الصف', $wpdb->supervisors[100]['invitation_token_hash'] ?? null, $token_b1['hash']);

seed_assignment($wpdb, 101, 9101, 'invited', '0501000002', 'old-raw-token-b2');
$commit_b2 = PGE_Supervisor_Assignment_Service::commit_new_token_hash(101, 'active', $token_b2['hash']); // حالة متوقَّعة خاطئة عمداً
check('ب5. commit_new_token_hash(): حالة متوقَّعة غير مطابقة -> error', $commit_b2['result'] ?? null, 'error');
check('ب5ب. السبب concurrent_status_change', $commit_b2['reason'] ?? null, 'concurrent_status_change');
check_true('ب5ج. الصف لم يتغيَّر إطلاقاً', $wpdb->supervisors[101]['invitation_token_hash'] !== $token_b2['hash']);

$commit_b3 = PGE_Supervisor_Assignment_Service::commit_new_token_hash(0, 'invited', $token_b2['hash']);
check('ب6. commit_new_token_hash(): id=0 -> invalid_id', $commit_b3['reason'] ?? null, 'invalid_id');

$commit_b4 = PGE_Supervisor_Assignment_Service::commit_new_token_hash(101, 'invited', '');
check('ب7. commit_new_token_hash(): hash فارغ -> invalid_arguments', $commit_b4['reason'] ?? null, 'invalid_arguments');

$commit_b5 = PGE_Supervisor_Assignment_Service::commit_new_token_hash(99999, 'invited', $token_b2['hash']);
check('ب8. commit_new_token_hash(): id غير موجود -> error', $commit_b5['result'] ?? null, 'error');

// ============================================================================
// القسم ج: PGE_Supervisor_Invitation_Delivery::deliver() — الترتيب الآمن الكامل
// ============================================================================
echo "\n=== القسم ج: PGE_Supervisor_Invitation_Delivery::deliver() ===\n";

$GLOBALS['__test_wa_options']['pge_wa_provider'] = 'cartat';
$GLOBALS['__test_wa_options']['pge_cartat_api_token'] = 'test-token-abc';

// ج1-2: معرّف/إسناد غير صالحين
$del_c1 = PGE_Supervisor_Invitation_Delivery::deliver(0, 501);
check('ج1. assignment_id=0 -> error invalid_assignment_id', $del_c1['reason'] ?? null, 'invalid_assignment_id');

$del_c2 = PGE_Supervisor_Invitation_Delivery::deliver(88888, 501);
check('ج2. إسناد غير موجود -> error assignment_not_found', $del_c2['reason'] ?? null, 'assignment_not_found');

// ج3-4: أهلية الحالة
seed_assignment($wpdb, 200, 9200, 'active', '0502000001');
set_test_event_post(9200, 'مناسبة الاختبار');
reset_remote_post_spy();
$del_c3 = PGE_Supervisor_Invitation_Delivery::deliver(200, 501);
check('ج3. status=active -> error not_eligible', $del_c3['reason'] ?? null, 'not_eligible');
check('ج3ب. status المُعادة = active', $del_c3['status'] ?? null, 'active');
check('ج3ج. لا استدعاء لـCartat إطلاقاً (رفض مبكر)', count($GLOBALS['__test_remote_post_calls']), 0);

seed_assignment($wpdb, 201, 9200, 'revoked', '0502000002');
$del_c4 = PGE_Supervisor_Invitation_Delivery::deliver(201, 501);
check('ج4. status=revoked -> error not_eligible', $del_c4['reason'] ?? null, 'not_eligible');

// ج5: بيانات إسناد ناقصة (event_id=0)
seed_assignment($wpdb, 202, 0, 'invited', '0502000003');
$del_c5 = PGE_Supervisor_Invitation_Delivery::deliver(202, 501);
check('ج5. event_id=0 -> error assignment_incomplete', $del_c5['reason'] ?? null, 'assignment_incomplete');

// ج6: مزوّد غير Cartat
seed_assignment($wpdb, 203, 9200, 'invited', '0502000004');
$GLOBALS['__test_wa_options']['pge_wa_provider'] = 'ultramsg';
reset_remote_post_spy();
$del_c6 = PGE_Supervisor_Invitation_Delivery::deliver(203, 501);
check('ج6. pge_wa_provider != cartat -> error provider_not_active', $del_c6['reason'] ?? null, 'provider_not_active');
check('ج6ب. لا استدعاء لـCartat إطلاقاً', count($GLOBALS['__test_remote_post_calls']), 0);
$GLOBALS['__test_wa_options']['pge_wa_provider'] = 'cartat';

// ج7: اعتماد Cartat غير مضبوط
$GLOBALS['__test_wa_options']['pge_cartat_api_token'] = '';
reset_remote_post_spy();
$del_c7 = PGE_Supervisor_Invitation_Delivery::deliver(203, 501);
check('ج7. توكن Cartat فارغ -> error missing_settings', $del_c7['reason'] ?? null, 'missing_settings');
check('ج7ب. لا استدعاء لـCartat إطلاقاً', count($GLOBALS['__test_remote_post_calls']), 0);
$GLOBALS['__test_wa_options']['pge_cartat_api_token'] = 'test-token-abc';

// ج8: القفل مشغول
$wpdb->force_lock_busy_once = true;
reset_remote_post_spy();
$del_c8 = PGE_Supervisor_Invitation_Delivery::deliver(203, 501);
check('ج8. GET_LOCK مشغول -> error lock_busy', $del_c8['reason'] ?? null, 'lock_busy');
check('ج8ب. لا سجل تدقيق أُنشئ (لم يبدأ أي طلب فعلي)', count(audit_actions_for($wpdb, 203)), 0);
check('ج8ج. لا استدعاء لـCartat إطلاقاً', count($GLOBALS['__test_remote_post_calls']), 0);

// ج9: تسليم ناجح كامل — الإسناد 203 (invited) لا يزال بتوكنه الأصلي حتى الآن
$old_hash_203 = $wpdb->supervisors[203]['invitation_token_hash'];
$old_invited_at_203 = $wpdb->supervisors[203]['invited_at'];
$GLOBALS['__test_now'] = '2026-03-15 10:00:00';
reset_remote_post_spy();
$del_c9 = PGE_Supervisor_Invitation_Delivery::deliver(203, 501);
check('ج9. تسليم ناجح -> provider_accepted', $del_c9['result'] ?? null, 'provider_accepted');
check('ج9ب. id المُعاد = 203', $del_c9['id'] ?? null, 203);
check('ج9ج. استدعاء واحد فقط لـwp_remote_post (نص فقط، لا وسائط)', count($GLOBALS['__test_remote_post_calls']), 1);
check(
    'ج9د. سجل التدقيق: delivery_requested -> delivery_attempted -> provider_accepted بالضبط',
    audit_actions_for($wpdb, 203),
    ['delivery_requested', 'delivery_attempted', 'provider_accepted']
);
check_true('ج9هـ. invitation_token_hash تغيَّر فعلياً', $wpdb->supervisors[203]['invitation_token_hash'] !== $old_hash_203);
check_true('ج9و. invited_at تحدَّث فعلياً', $wpdb->supervisors[203]['invited_at'] !== $old_invited_at_203);
check('ج9ز. القفل حُرِّر فعلياً (RELEASE_LOCK استُدعيت)', count($wpdb->lock_release_log), count($wpdb->lock_acquire_log));

// ج10: نص الرسالة المُرسَلة يحوي اسم المناسبة ورابط قبول بالشكل الصحيح، والتوكن
// داخل ذلك الرابط هو فعلياً نفس التوكن الذي التزم في invitation_token_hash.
$sent_message_203 = json_decode($GLOBALS['__test_remote_post_calls'][0]['args']['body'], true)['message'] ?? '';
check_true('ج10. الرسالة تحوي اسم المناسبة', strpos($sent_message_203, 'مناسبة الاختبار') !== false);
check_true('ج10ب. الرسالة تحوي رابط /supervisor/accept/{64hex}/', (bool) preg_match('#/supervisor/accept/([a-f0-9]{64})/#', $sent_message_203, $url_match_203));
if (!empty($url_match_203[1])) {
    check('ج10ج. hash(التوكن في الرابط) = invitation_token_hash المُلتزَم فعلياً', hash('sha256', $url_match_203[1]), $wpdb->supervisors[203]['invitation_token_hash']);
} else {
    check('ج10ج. hash(التوكن في الرابط) = invitation_token_hash المُلتزَم فعلياً', 'no_match', $wpdb->supervisors[203]['invitation_token_hash']);
}

// ج11: التوكن القديم (قبل هذا التسليم) لم يعد صالحاً للقبول بعد الآن.
$accept_old_203 = PGE_Supervisor_Assignment_Service::accept_invitation('old_raw_placeholder_not_matching_any_hash');
check('ج11. توكن عشوائي غير مطابق لأي هاش -> invalid_token (تأكيد سلوك أساسي)', $accept_old_203['reason'] ?? null, 'invalid_token');

// ============================================================================
// ج12-15: مسارات الفشل — التوكن القديم يبقى سارياً بالكامل
// ============================================================================
seed_assignment($wpdb, 204, 9200, 'invited', '0502000005', 'raw-token-for-204');
$hash_204_before = $wpdb->supervisors[204]['invitation_token_hash'];

// ج12: transport_error (وصول null بدل JSON — نفس ما يفعله request() عند WP_Error)
// ملاحظة ترتيب: reset_remote_post_spy() تُعيد $GLOBALS['__test_remote_post_response']
// لقيمة النجاح الافتراضية أيضاً — يجب استدعاؤها *قبل* ضبط استجابة الفشل المخصَّصة هنا، لا بعده.
reset_remote_post_spy();
$GLOBALS['__test_remote_post_response'] = new WP_Error('http_request_failed', 'Connection timed out');
$del_c12 = PGE_Supervisor_Invitation_Delivery::deliver(204, 501);
check('ج12. فشل نقل (WP_Error) -> delivery_failed', $del_c12['result'] ?? null, 'delivery_failed');
check('ج12ب. السبب transport_error', $del_c12['reason'] ?? null, 'transport_error');
check('ج12ج. invitation_token_hash لم يتغيَّر إطلاقاً (التوكن القديم لا يزال سارياً)', $wpdb->supervisors[204]['invitation_token_hash'] ?? null, $hash_204_before);
check(
    'ج12د. سجل التدقيق: delivery_requested -> delivery_attempted -> delivery_failed (بلا provider_accepted)',
    audit_actions_for($wpdb, 204),
    ['delivery_requested', 'delivery_attempted', 'delivery_failed']
);
$failed_rows_204 = audit_rows_for($wpdb, 204, 'delivery_failed');
check('ج12هـ. reason صف delivery_failed = transport_error', $failed_rows_204[0]['reason'] ?? null, 'transport_error');

// إعادة الاستجابة الوهمية للوضع الطبيعي قبل السيناريو التالي
$GLOBALS['__test_remote_post_response'] = ['body' => json_encode(['status' => 'sent', 'id' => 'test-msg-id'])];

// ج13: provider_rejected (استجابة JSON فعلية لكن برفض صريح) — نفس ملاحظة
// الترتيب أعلاه: reset أولاً، ثم ضبط الاستجابة المخصَّصة.
reset_remote_post_spy();
$GLOBALS['__test_remote_post_response'] = ['body' => json_encode(['status' => 'error', 'message' => 'invalid number'])];
$del_c13 = PGE_Supervisor_Invitation_Delivery::deliver(204, 501);
check('ج13. رفض صريح من Cartat -> delivery_failed', $del_c13['result'] ?? null, 'delivery_failed');
check('ج13ب. السبب provider_rejected', $del_c13['reason'] ?? null, 'provider_rejected');
check('ج13ج. invitation_token_hash لا يزال دون تغيير', $wpdb->supervisors[204]['invitation_token_hash'] ?? null, $hash_204_before);
$GLOBALS['__test_remote_post_response'] = ['body' => json_encode(['status' => 'sent', 'id' => 'test-msg-id'])];

// ج14: التوكن القديم (الأصلي، قبل أي محاولة تسليم فاشلة) لا يزال يقبل فعلياً —
// إثبات تنفيذي كامل لقاعدة "لا إبطال قبل قبول المزوّد فعلياً".
$accept_204_old = PGE_Supervisor_Assignment_Service::accept_invitation('raw-token-for-204');
check('ج14. التوكن الأصلي لإسناد 204 لا يزال صالحاً بعد فشلَي التسليم -> accepted', $accept_204_old['result'] ?? null, 'accepted');
check('ج14ب. الإسناد أصبح active فعلياً عبر التوكن الأصلي القديم', $wpdb->supervisors[204]['status'] ?? null, 'active');

// ج15: تعارض تزامن أثناء الالتزام (Cartat قَبِل، لكن الحالة تغيَّرت قبل commit)
seed_assignment($wpdb, 205, 9200, 'invited', '0502000006', 'raw-token-for-205');
$hash_205_before = $wpdb->supervisors[205]['invitation_token_hash'];
$wpdb->force_commit_conflict_once = true;
reset_remote_post_spy();
$del_c15 = PGE_Supervisor_Invitation_Delivery::deliver(205, 501);
check('ج15. تعارض عند الالتزام بعد قبول المزوّد -> delivery_failed', $del_c15['result'] ?? null, 'delivery_failed');
check('ج15ب. السبب token_commit_failed', $del_c15['reason'] ?? null, 'token_commit_failed');
check('ج15ج. Cartat استُدعيَت فعلياً رغم فشل الالتزام (الرسالة أُرسِلت بالفعل)', count($GLOBALS['__test_remote_post_calls']), 1);
check('ج15د. invitation_token_hash لم يتغيَّر (الالتزام نفسه فشل)', $wpdb->supervisors[205]['invitation_token_hash'] ?? null, $hash_205_before);
check(
    'ج15هـ. سجل التدقيق: delivery_requested -> delivery_attempted -> delivery_failed(token_commit_failed)',
    audit_actions_for($wpdb, 205),
    ['delivery_requested', 'delivery_attempted', 'delivery_failed']
);

// ج16: أهلية 'pending' مقبولة أيضاً (نفس ACCEPTABLE_STATUSES)
seed_assignment($wpdb, 206, 9200, 'pending', '0502000007');
reset_remote_post_spy();
$del_c16 = PGE_Supervisor_Invitation_Delivery::deliver(206, 501);
check('ج16. status=pending مؤهَّل أيضاً -> provider_accepted', $del_c16['result'] ?? null, 'provider_accepted');

// ج17: القفل يُحرَّر حتى في مسار الفشل المبكر (not_eligible) — لا قفل تسرَّب أصلاً هناك
check_true('ج17. عدد التحريرات لا يقل أبداً عن عدد الاكتسابات الفعلية عبر كل هذا القسم', count($wpdb->lock_release_log) === count($wpdb->lock_acquire_log));

// ============================================================================
// القسم د: الدوال النقية في routing.php — pge_supervisor_accept_token_shape_
// valid()/pge_supervisor_accept_classify_auth_error()
// ============================================================================
echo "\n=== القسم د: دوال محوِّل المسار النقية (routing.php) ===\n";

check_true('د1. توكن صحيح (64 hex صغير) -> valid=true', pge_supervisor_accept_token_shape_valid(str_repeat('a1', 32)));
check_true('د2. أحرف كبيرة (bin2hex لا يُنتجها أبداً) -> valid=false', !pge_supervisor_accept_token_shape_valid(strtoupper(str_repeat('a1', 32))));
check_true('د3. أقصر من 64 -> valid=false', !pge_supervisor_accept_token_shape_valid(str_repeat('a', 63)));
check_true('د4. أطول من 64 -> valid=false', !pge_supervisor_accept_token_shape_valid(str_repeat('a', 65)));
check_true('د5. أحرف غير hex -> valid=false', !pge_supervisor_accept_token_shape_valid(str_repeat('z', 64)));
check_true('د6. سلسلة فارغة -> valid=false', !pge_supervisor_accept_token_shape_valid(''));

$d_session = pge_supervisor_accept_classify_auth_error(['result' => 'error', 'stage' => 'session', 'reason' => 'session_creation_failed']);
check('د7. stage=session -> http_status=500', $d_session['http_status'] ?? null, 500);
check_true('د7ب. رسالة stage=session تُقرّ بنجاح القبول صراحة (لا توحي بإعادة فتح نفس الرابط)', strpos($d_session['message'], 'قبولك') !== false || strpos($d_session['message'], 'قبول') !== false);

$d_not_acceptable = pge_supervisor_accept_classify_auth_error(['result' => 'error', 'stage' => 'invitation', 'reason' => 'assignment_not_acceptable', 'status' => 'revoked']);
check('د8. reason=assignment_not_acceptable -> http_status=403', $d_not_acceptable['http_status'] ?? null, 403);

$d_invalid = pge_supervisor_accept_classify_auth_error(['result' => 'error', 'stage' => 'invitation', 'reason' => 'invalid_token']);
check('د9. reason=invalid_token -> http_status=410', $d_invalid['http_status'] ?? null, 410);

$d_used = pge_supervisor_accept_classify_auth_error(['result' => 'error', 'stage' => 'invitation', 'reason' => 'token_already_used_or_invalid']);
check('د10. reason=token_already_used_or_invalid -> http_status=410 (نفس فئة د9)', $d_used['http_status'] ?? null, 410);
check('د10ب. نفس العنوان تماماً بين د9/د10 (فئة واحدة موحَّدة)', $d_used['title'], $d_invalid['title']);

$d_unexpected = pge_supervisor_accept_classify_auth_error(['result' => 'error', 'stage' => 'invitation', 'reason' => 'assignment_not_active_after_acceptance']);
check('د11. سبب غير متوقَّع (افتراضي) -> http_status=500', $d_unexpected['http_status'] ?? null, 500);
check_true('د11ب. لا كشف لاسم السبب التقني الخام في الرسالة', strpos($d_unexpected['message'], 'assignment_not_active_after_acceptance') === false);

// كل مخرجات التصنيف الست يجب ألا تحتوي كلمة "token"/"hash" لاتينية (لا تسريب مصطلح تقني للمستخدم النهائي)
foreach ([$d_session, $d_not_acceptable, $d_invalid, $d_used, $d_unexpected] as $idx => $classified) {
    check_true("د12.$idx. لا كلمة token/hash لاتينية داخل الرسالة المعروضة للمستخدم", stripos($classified['message'], 'token') === false && stripos($classified['message'], 'hash') === false);
}

// ============================================================================
// القسم هـ: تكامل كامل — deliver() ← PGE_Supervisor_Authenticator::authenticate()
// ============================================================================
echo "\n=== القسم هـ: تكامل deliver() مع authenticate() الكاملة ===\n";

/** استخراج التوكن الخام من آخر رسالة واتساب أُرسِلت فعلياً عبر الـspy. */
function extract_raw_token_from_last_message($globals_calls)
{
    $last = end($globals_calls);
    $body = json_decode($last['args']['body'] ?? '', true);
    $message = $body['message'] ?? '';
    if (preg_match('#/supervisor/accept/([a-f0-9]{64})/#', $message, $m)) {
        return $m[1];
    }
    return '';
}

seed_assignment($wpdb, 300, 9200, 'invited', '0503000001');
reset_remote_post_spy();
$del_e1 = PGE_Supervisor_Invitation_Delivery::deliver(300, 501);
check('هـ1. تسليم ناجح للإسناد 300', $del_e1['result'] ?? null, 'provider_accepted');
$raw_token_300 = extract_raw_token_from_last_message($GLOBALS['__test_remote_post_calls']);
check_true('هـ1ب. تم استخراج توكن خام صالح شكلياً من الرسالة المُرسَلة فعلياً', pge_supervisor_accept_token_shape_valid($raw_token_300));

$auth_e1 = PGE_Supervisor_Authenticator::authenticate($raw_token_300);
check('هـ2. authenticate() بالتوكن المُسلَّم فعلياً -> authenticated', $auth_e1['result'] ?? null, 'authenticated');
check('هـ2ب. الإسناد 300 أصبح active فعلياً', $wpdb->supervisors[300]['status'] ?? null, 'active');
check_true('هـ2ج. session_token خام مُعاد (غير فارغ)', !empty($auth_e1['session_token'] ?? ''));
check('هـ2د. event_id المُعاد = 9200 (نفس مناسبة الإسناد)', $auth_e1['event_id'] ?? null, 9200);
check('هـ2هـ. صف جلسة واحد أُنشئ فعلياً في mon_supervisor_sessions', count($wpdb->sessions), 1);

// هـ3: إعادة استخدام نفس التوكن الخام بعد استهلاكه (invitation_token_hash أصبح
// null بعد accept_invitation() الناجح) -> فشل بسبب توكن غير صالح، لا نجاح مكرَّر.
$auth_e3 = PGE_Supervisor_Authenticator::authenticate($raw_token_300);
check('هـ3. إعادة استخدام نفس التوكن بعد الاستهلاك -> error', $auth_e3['result'] ?? null, 'error');
check('هـ3ب. السبب invalid_token (التوكن استُهلِك، لا يوجد صف يطابق الهاش بعد الآن)', $auth_e3['reason'] ?? null, 'invalid_token');

// هـ4: عند فشل التسليم (transport_error)، التوكن *القديم* (قبل محاولة التسليم
// الفاشلة) يبقى صالحاً بالكامل عبر authenticate() الكاملة — لا مجرد
// accept_invitation() المباشرة (المُختبَرة في ج14) — إثبات تكاملي شامل.
seed_assignment($wpdb, 301, 9200, 'invited', '0503000002', 'raw-token-for-301-original');
reset_remote_post_spy();
$GLOBALS['__test_remote_post_response'] = new WP_Error('http_request_failed', 'timeout');
$del_e4 = PGE_Supervisor_Invitation_Delivery::deliver(301, 501);
check('هـ4. محاولة تسليم فاشلة للإسناد 301', $del_e4['result'] ?? null, 'delivery_failed');
$GLOBALS['__test_remote_post_response'] = ['body' => json_encode(['status' => 'sent', 'id' => 'test-msg-id'])];

$auth_e4 = PGE_Supervisor_Authenticator::authenticate('raw-token-for-301-original');
check('هـ4ب. التوكن الأصلي (قبل فشل التسليم) لا يزال يُصادِق بنجاح كامل', $auth_e4['result'] ?? null, 'authenticated');
check('هـ4ج. الإسناد 301 أصبح active عبر التوكن الأصلي رغم فشل محاولة التسليم', $wpdb->supervisors[301]['status'] ?? null, 'active');

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
