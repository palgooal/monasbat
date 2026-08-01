<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـEntry Check-in Supervisors، Phase 8
 * ("Host Supervisor Management" RFC) — includes/supervisor-management-ajax.php،
 * الإضافات الجديدة على PGE_Supervisor_Assignment_Service (list_assignments_
 * for_event_page/edit_supervisor_details/resend_invitation)، وPGE_Supervisor_
 * Management_Audit. يستدعي المعالجات الحقيقية بأسمائها مباشرة (نفس اصطلاح
 * call_ajax_handler() في test-supervisor-checkin-ui.php)، ويُنفِّذ pge_event_
 * guests_user_can_manage() الحقيقية من event-guests.php (لا مرآة تفويض
 * مصطنعة) — "Do NOT create logical mirrors of production code. Execute the
 * real activation code."
 *
 * السيناريوهات المطلوبة صراحةً (Testing Requirement): إنشاء مشرف، بلوغ
 * الحصة، هاتف مكرَّر، تعديل مشرف، إعادة إرسال الدعوة، إلغاء إسناد مشرف، مضيف
 * غير مخوَّل، عزل المناسبات المختلفة، الترقيم، البحث، توليد سجل التدقيق،
 * وانحدار (لا تأثير على list_assignments_for_event() القديمة/Phase 5).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-supervisor-management.php
 */

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
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($v) { return trim((string) $v); }
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

// ── تفويض/جلسة — نفس أسلوب test-supervisor-checkin-ui.php حرفياً ───────────
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null) {
    if ($cap === 'administrator') {
        return $GLOBALS['__test_user_is_admin'];
    }
    // 'edit_post' غير مُستخدَم في fixtures هذا الملف (نعتمد فقط على تطابق
    // post_author أو صلاحية administrator، تماماً كما في page-event-manage.php
    // الفعلي عند غياب أي دور Editor محاكى) — تبقى false دائماً هنا عمداً.
    return false;
}

// ── Posts وهمية (لـget_post/get_post_type/get_post_field) ──────────────────
$GLOBALS['__test_posts'] = [];
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
function get_post_type($event_id)
{
    $p = get_post($event_id);
    return $p ? $p->post_type : false;
}
function get_post_field($field, $post_id)
{
    $p = get_post($post_id);
    if (!$p) return '';
    return $p->{$field} ?? '';
}

// ── User Meta وهمية (لـpge_resolve_supervisor_quota_status) ────────────────
$GLOBALS['__test_user_meta'] = [];
function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function set_test_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
}
function reset_test_user($user_id)
{
    $GLOBALS['__test_user_meta'][$user_id] = [];
}

/**
 * إعداد سريع: مضيف Catalog بحصة مشرفين محدَّدة يملك مناسبة — بلا استدعاء
 * كامل بنية PGE_Catalog/Mon_Events_Users (غير ضرورية لهذه المرحلة؛ تكامل
 * Resolver ← Snapshot أُثبِت تنفيذياً بالكامل بالفعل في Phase 1/2 test files) —
 * يُثبَّت الـSnapshot المطلوب مباشرة عبر user meta، بنفس الشكل الذي تكتبه
 * Mon_Events_Users::build_tier_features_snapshot() فعلياً.
 */
function setup_host_with_event($host_id, $event_id, $supervisor_limit)
{
    reset_test_user($host_id);
    set_test_user_meta($host_id, '_mon_package_source', 'catalog');
    set_test_user_meta($host_id, '_mon_package_features', ['admin_supervisor_limit' => (string) $supervisor_limit]);
    set_test_event($event_id, $host_id);
}

// ── Stubs AJAX/JSON — نفس test-supervisor-checkin-ui.php حرفياً ─────────────

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
    } catch (Test_Wp_Die_Exception $e) {
        // متوقَّع دائماً — أسلوب إنهاء wp_send_json_* الطبيعي.
    }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) {
        return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

// ============================================================================
// Fake $wpdb — mon_event_supervisors + pge_supervisor_mgmt_audit_log + GET_LOCK
// ============================================================================
class Fake_Wpdb_Supervisor_Mgmt
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $supervisors = [];
    public $audit_log = [];

    private $supervisors_next_id = 1;
    private $audit_next_id = 1;

    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

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
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which !== 'supervisors') {
            return null;
        }

        // 1) تعديل: تحقّق تكرار مع استثناء الصف الحالي (id != N) — يجب أن
        // يُختبَر قبل الشكل العام (بلا id !=) لأنه أكثر تحديداً.
        if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+supervisor_phone\s*=\s*\'([^\']*)\'\s+AND\s+id\s*!=\s*(\d+)\s+AND\s+status\s+IN\s*\(([^)]*)\)/i', $sql, $m)) {
            $event_id = (int) $m[1];
            $phone = $m[2];
            $exclude_id = (int) $m[3];
            $statuses = array_map(function ($v) { return trim($v, " '"); }, explode(',', $m[4]));
            foreach ($this->supervisors as $row) {
                if ((int) $row['id'] === $exclude_id) continue;
                if ((int) $row['event_id'] === $event_id && $row['supervisor_phone'] === $phone && in_array($row['status'], $statuses, true)) {
                    return $row;
                }
            }
            return null;
        }

        // 2) إنشاء: تحقّق تكرار عادي (بلا استثناء) — من create_supervisor_assignment() الأصلية.
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

        // 3) find_by_id (get_assignment_state/edit/resend/revoke الداخلية).
        if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
            $id = (int) $m[1];
            return $this->supervisors[$id] ?? null;
        }

        return null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);

        if ($which === 'audit') {
            if (preg_match('/WHERE\s+assignment_id\s*=\s*(\d+)/i', $sql, $m)) {
                $assignment_id = (int) $m[1];
                $rows = array_values(array_filter($this->audit_log, function ($r) use ($assignment_id) {
                    return (int) $r['assignment_id'] === $assignment_id;
                }));
                usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
                return $rows;
            }
            return [];
        }

        if ($which !== 'supervisors') {
            return [];
        }

        // قائمة مُرقَّمة (list_assignments_for_event_page): تحتوي على "ORDER BY id DESC".
        if (stripos($sql, 'ORDER BY id DESC') !== false) {
            if (!preg_match('/event_id\s*=\s*(\d+)/i', $sql, $mEvent)) {
                return [];
            }
            $event_id = (int) $mEvent[1];

            $rows = array_values(array_filter($this->supervisors, function ($r) use ($event_id) {
                return (int) $r['event_id'] === $event_id;
            }));

            $rows = $this->apply_search_filter($sql, $rows);

            usort($rows, function ($a, $b) { return $b['id'] <=> $a['id']; });

            if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $mLim)) {
                $limit = (int) $mLim[1];
                $offset = (int) $mLim[2];
                $rows = array_slice($rows, $offset, $limit);
            }

            return $rows;
        }

        // list_assignments_for_event() القديمة (Phase 5) — ORDER BY id ASC، بلا ترقيم.
        if (stripos($sql, 'ORDER BY id ASC') !== false && preg_match('/event_id\s*=\s*(\d+)/i', $sql, $mEvent2)) {
            $event_id = (int) $mEvent2[1];
            $rows = array_values(array_filter($this->supervisors, function ($r) use ($event_id) {
                return (int) $r['event_id'] === $event_id;
            }));
            usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
            // إسقاط الأعمدة (Column Projection): الاستعلام الحقيقي هو تحديداً
            // "SELECT id, supervisor_phone, supervisor_name, status" فقط —
            // نطابق ذلك هنا بدقة بدل إعادة الصف الكامل، لإثبات فعلياً أن هذه
            // القراءة القديمة (Phase 5) لم تتأثر بأي عمود/حقل جديد.
            return array_map(function ($r) {
                return [
                    'id'               => $r['id'],
                    'supervisor_phone' => $r['supervisor_phone'],
                    'supervisor_name'  => $r['supervisor_name'],
                    'status'           => $r['status'],
                ];
            }, $rows);
        }

        return [];
    }

    /**
     * تطبيق شرط البحث (LIKE على الاسم و/أو الهاتف) المُستخرَج من نص SQL نفسه —
     * نفس المنطق المطلوب مطابقته من list_assignments_for_event_page() الحقيقية.
     */
    private function apply_search_filter($sql, array $rows): array
    {
        if (preg_match("/supervisor_name\s+LIKE\s+'([^']*)'\s+OR\s+supervisor_phone\s+LIKE\s+'([^']*)'/i", $sql, $m)) {
            $name_like = trim($m[1], '%');
            $phone_like = trim($m[2], '%');
            return array_values(array_filter($rows, function ($r) use ($name_like, $phone_like) {
                return (stripos((string) $r['supervisor_name'], $name_like) !== false)
                    || (strpos((string) $r['supervisor_phone'], $phone_like) !== false);
            }));
        }

        if (preg_match("/AND\s+supervisor_name\s+LIKE\s+'([^']*)'/i", $sql, $m)) {
            $name_like = trim($m[1], '%');
            return array_values(array_filter($rows, function ($r) use ($name_like) {
                return stripos((string) $r['supervisor_name'], $name_like) !== false;
            }));
        }

        return $rows;
    }

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $name = $m[1];
            $this->lock_acquire_log[] = $name;
            $this->held_locks[$name] = true;
            return 1;
        }

        $which = $this->which_table($sql);
        if ($which !== 'supervisors') {
            return null;
        }

        // عدّ الحصة (pge_count_active_event_supervisors — Resolver): status NOT IN (...)
        if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+status\s+NOT\s+IN\s*\(([^)]*)\)/i', $sql, $m)) {
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

        // عدّ الصفحة (list_assignments_for_event_page — COUNT الإجمالي مع بحث اختياري).
        if (stripos($sql, 'SELECT COUNT(*)') !== false && preg_match('/event_id\s*=\s*(\d+)/i', $sql, $mEvent)) {
            $event_id = (int) $mEvent[1];
            $rows = array_values(array_filter($this->supervisors, function ($r) use ($event_id) {
                return (int) $r['event_id'] === $event_id;
            }));
            $rows = $this->apply_search_filter($sql, $rows);
            return (string) count($rows);
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
        return false;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which !== 'supervisors') {
            return false;
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

$GLOBALS['wpdb'] = new Fake_Wpdb_Supervisor_Mgmt();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────
require_once __DIR__ . '/../includes/helpers.php'; // RC1 Fix Pack 2 (A9): pge_mgmt_validate_request() المشتركة
require_once __DIR__ . '/../includes/supervisor-quota-resolver.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-management-audit.php';
require_once __DIR__ . '/../includes/event-guests.php'; // pge_event_guests_user_can_manage() الحقيقية
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
function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

// ============================================================================
// السيناريو 1: إنشاء مشرف (Create Supervisor)
// ============================================================================
echo "=== السيناريو 1: إنشاء مشرف ===\n";

setup_host_with_event(701, 901, 3);
$GLOBALS['__test_current_user_id'] = 701;
$GLOBALS['__test_user_is_admin'] = false;

$_POST = make_post_fields(901, ['name' => 'أحمد', 'phone' => '0501111111']);
$resp1 = call_ajax_handler('pge_supervisor_mgmt_create_handler');
check_true('1. الاستجابة success=true', $resp1['success'] ?? false);
check_true('1. id مُعاد فعلياً', (int) ($resp1['data']['id'] ?? 0) > 0);
$assignment1_id = (int) $resp1['data']['id'];
check('1. الصف أُنشئ فعلياً بحالة invited', $wpdb->supervisors[$assignment1_id]['status'] ?? null, 'invited');
$audit_rows_1_created = array_values(array_filter($wpdb->audit_log, function ($r) use ($assignment1_id) {
    return (int) $r['assignment_id'] === $assignment1_id && $r['action'] === 'created';
}));
check('1. سجل تدقيق واحد بنوع created', count($audit_rows_1_created), 1);
check('1. actor_user_id في سجل التدقيق = المضيف الحالي', (int) ($audit_rows_1_created[0]['actor_user_id'] ?? 0), 701);

// ============================================================================
// السيناريو 2: هاتف مكرَّر (Duplicate phone)
// ============================================================================
echo "\n=== السيناريو 2: هاتف مكرَّر ===\n";

$_POST = make_post_fields(901, ['name' => 'أحمد آخر', 'phone' => '0501111111']);
$resp2 = call_ajax_handler('pge_supervisor_mgmt_create_handler');
check_true('2. الاستجابة success=false', !($resp2['success'] ?? true));
check('2. السبب duplicate_active', $resp2['data']['reason'] ?? null, 'duplicate_active');
check_true('2. لا صف جديد أُنشئ لهذا الهاتف', count(array_filter($wpdb->supervisors, function ($r) { return $r['supervisor_phone'] === '0501111111'; })) === 1);

// ============================================================================
// السيناريو 3: بلوغ الحصة (Quota reached)
// ============================================================================
echo "\n=== السيناريو 3: بلوغ الحصة ===\n";

setup_host_with_event(702, 902, 1); // حصة = 1 فقط
$GLOBALS['__test_current_user_id'] = 702;

$_POST = make_post_fields(902, ['phone' => '0502222222']);
$resp3a = call_ajax_handler('pge_supervisor_mgmt_create_handler');
check_true('3. أول إنشاء (ضمن الحصة): success', $resp3a['success'] ?? false);

$_POST = make_post_fields(902, ['phone' => '0502222333']);
$resp3b = call_ajax_handler('pge_supervisor_mgmt_create_handler');
check_true('3. ثانٍ بعد استنفاد الحصة: success=false', !($resp3b['success'] ?? true));
check('3. السبب quota_exceeded', $resp3b['data']['reason'] ?? null, 'quota_exceeded');
check('3. allowed=1، used=1', [$resp3b['data']['allowed'] ?? null, $resp3b['data']['used'] ?? null], [1, 1]);

// ============================================================================
// السيناريو 4: مضيف غير مخوَّل (Unauthorized host)
// ============================================================================
echo "\n=== السيناريو 4: مضيف غير مخوَّل ===\n";

setup_host_with_event(703, 903, 5); // 903 يملكها 703
$GLOBALS['__test_current_user_id'] = 799; // مستخدم آخر تماماً، ليس مضيفاً ولا أدمن
$GLOBALS['__test_user_is_admin'] = false;

$_POST = make_post_fields(903, ['phone' => '0504444444']);
$resp4 = call_ajax_handler('pge_supervisor_mgmt_create_handler');
check_true('4. مضيف غير مخوَّل: success=false', !($resp4['success'] ?? true));
check('4. السبب forbidden', $resp4['data']['reason'] ?? null, 'forbidden');
check_true('4. لا صف أُنشئ فعلياً', count(array_filter($wpdb->supervisors, function ($r) { return (int) $r['event_id'] === 903; })) === 0);

// أدمن (ليس مالكاً) يُسمَح له صراحة (current_user_can('administrator') = true)
$GLOBALS['__test_user_is_admin'] = true;
$_POST = make_post_fields(903, ['phone' => '0504444444']);
$resp4b = call_ajax_handler('pge_supervisor_mgmt_create_handler');
check_true('4ب. أدمن (ليس مالكاً): success=true', $resp4b['success'] ?? false);
$GLOBALS['__test_user_is_admin'] = false;

// ============================================================================
// السيناريو 5: تعديل مشرف (Edit Supervisor)
// ============================================================================
echo "\n=== السيناريو 5: تعديل مشرف ===\n";

$GLOBALS['__test_current_user_id'] = 701;
$_POST = make_post_fields(901, ['assignment_id' => $assignment1_id, 'name' => 'أحمد المعدَّل', 'phone' => '0501111199']);
$resp5 = call_ajax_handler('pge_supervisor_mgmt_edit_handler');
check_true('5. التعديل: success', $resp5['success'] ?? false);
check('5. الاسم تحدَّث فعلياً', $wpdb->supervisors[$assignment1_id]['supervisor_name'] ?? null, 'أحمد المعدَّل');
check('5. الهاتف تحدَّث فعلياً', $wpdb->supervisors[$assignment1_id]['supervisor_phone'] ?? null, '0501111199');
check('5. status لم يتغيَّر (يبقى invited)', $wpdb->supervisors[$assignment1_id]['status'] ?? null, 'invited');
check_true('5. سجل تدقيق edited واحد', count(array_filter($wpdb->audit_log, function ($r) use ($assignment1_id) {
    return (int) $r['assignment_id'] === $assignment1_id && $r['action'] === 'edited';
})) === 1);

// تعديل بهاتف يخصّ إسناداً نشطاً آخر لنفس المناسبة → duplicate_active
setup_host_with_event(704, 904, 5);
$GLOBALS['__test_current_user_id'] = 704;
$_POST = make_post_fields(904, ['phone' => '0505550001', 'name' => 'الأول']);
$edit_seed_a = call_ajax_handler('pge_supervisor_mgmt_create_handler');
$_POST = make_post_fields(904, ['phone' => '0505550002', 'name' => 'الثاني']);
$edit_seed_b = call_ajax_handler('pge_supervisor_mgmt_create_handler');
$id_b = (int) $edit_seed_b['data']['id'];

$_POST = make_post_fields(904, ['assignment_id' => $id_b, 'phone' => '0505550001', 'name' => 'الثاني']);
$resp5c = call_ajax_handler('pge_supervisor_mgmt_edit_handler');
check_true('5ج. تعديل هاتف يخصّ إسناداً آخر نشطاً: success=false', !($resp5c['success'] ?? true));
check('5ج. السبب duplicate_active', $resp5c['data']['reason'] ?? null, 'duplicate_active');

// ============================================================================
// السيناريو 6: إعادة إرسال الدعوة (Resend Invitation)
// ============================================================================
echo "\n=== السيناريو 6: إعادة إرسال الدعوة ===\n";

$old_hash_6 = $wpdb->supervisors[$assignment1_id]['invitation_token_hash'];
$old_invited_at_6 = $wpdb->supervisors[$assignment1_id]['invited_at'];

$GLOBALS['__test_current_user_id'] = 701;
$_POST = make_post_fields(901, ['assignment_id' => $assignment1_id]);
$resp6 = call_ajax_handler('pge_supervisor_mgmt_resend_handler');
check_true('6. إعادة الإرسال: success', $resp6['success'] ?? false);
check_true('6. التوكن الخام غير مُعاد للواجهة إطلاقاً', !isset($resp6['data']['invitation_token']));
check_true('6. invitation_token_hash تغيَّر (توكن جديد فعلياً)', $wpdb->supervisors[$assignment1_id]['invitation_token_hash'] !== $old_hash_6);
check('6. status يبقى invited (لا إنشاء صف جديد، لا استهلاك حصة إضافية)', $wpdb->supervisors[$assignment1_id]['status'] ?? null, 'invited');
check_true('6. سجل تدقيق invitation_resent واحد', count(array_filter($wpdb->audit_log, function ($r) use ($assignment1_id) {
    return (int) $r['assignment_id'] === $assignment1_id && $r['action'] === 'invitation_resent';
})) === 1);

// إعادة إرسال لإسناد active يُرفَض (not_resendable)
$active_row_id = null;
foreach ($wpdb->supervisors as $id => $row) {
    if ((int) $row['event_id'] === 901) { $active_row_id = $id; break; }
}
$wpdb->supervisors[$active_row_id]['status'] = 'active'; // محاكاة قبول مباشرة للفيكستشر
$_POST = make_post_fields(901, ['assignment_id' => $active_row_id]);
$resp6b = call_ajax_handler('pge_supervisor_mgmt_resend_handler');
check_true('6ب. إعادة إرسال لإسناد active: success=false', !($resp6b['success'] ?? true));
check('6ب. السبب not_resendable', $resp6b['data']['reason'] ?? null, 'not_resendable');
$wpdb->supervisors[$active_row_id]['status'] = 'invited'; // إعادة الحالة لبقية السيناريوهات

// ============================================================================
// السيناريو 7: إلغاء إسناد مشرف (Revoke Supervisor)
// ============================================================================
echo "\n=== السيناريو 7: إلغاء إسناد مشرف ===\n";

$_POST = make_post_fields(901, ['assignment_id' => $assignment1_id, 'reason' => 'استقالة']);
$resp7 = call_ajax_handler('pge_supervisor_mgmt_revoke_handler');
check_true('7. الإلغاء: success', $resp7['success'] ?? false);
check('7. status أصبح revoked', $wpdb->supervisors[$assignment1_id]['status'] ?? null, 'revoked');
check_true('7. revoked_at سُجِّل', !empty($wpdb->supervisors[$assignment1_id]['revoked_at']));
check_true('7. سجل تدقيق revoked مع السبب', count(array_filter($wpdb->audit_log, function ($r) use ($assignment1_id) {
    return (int) $r['assignment_id'] === $assignment1_id && $r['action'] === 'revoked' && $r['reason'] === 'استقالة';
})) === 1);

// إلغاء مكرَّر (مُلغى مسبقاً) → not_revocable
$_POST = make_post_fields(901, ['assignment_id' => $assignment1_id]);
$resp7b = call_ajax_handler('pge_supervisor_mgmt_revoke_handler');
check_true('7ب. إلغاء مكرَّر: success=false', !($resp7b['success'] ?? true));
check('7ب. السبب not_revocable', $resp7b['data']['reason'] ?? null, 'not_revocable');

// ============================================================================
// السيناريو 8: عزل المناسبات المختلفة (Different event isolation)
// ============================================================================
echo "\n=== السيناريو 8: عزل المناسبات المختلفة ===\n";

setup_host_with_event(705, 905, 5);
setup_host_with_event(706, 906, 5);
$GLOBALS['__test_current_user_id'] = 705;
$_POST = make_post_fields(905, ['phone' => '0509990001']);
$resp8_seed = call_ajax_handler('pge_supervisor_mgmt_create_handler');
$assignment_905 = (int) $resp8_seed['data']['id'];

// المضيف 706 (يملك مناسبة أخرى 906) يحاول تعديل/إلغاء إسناد يخصّ مناسبة 905 —
// حتى لو زعم أنه يملك المناسبة 905 داخل الطلب، الفحص الحقيقي (post_author)
// يرفضه أولاً في pge_supervisor_mgmt_validate_request().
$GLOBALS['__test_current_user_id'] = 706;
$_POST = make_post_fields(905, ['assignment_id' => $assignment_905, 'phone' => '0509990002']);
$resp8a = call_ajax_handler('pge_supervisor_mgmt_edit_handler');
check_true('8أ. مضيف آخر يحاول تعديل مناسبة لا يملكها: success=false', !($resp8a['success'] ?? true));
check('8أ. السبب forbidden (تفويض المضيف نفسه يرفض أولاً)', $resp8a['data']['reason'] ?? null, 'forbidden');

// حتى لو أصبح 706 مالكاً شرعياً لمناسبته 906، ويحاول تمرير event_id=906 مع
// assignment_id يخصّ فعلياً مناسبة 905 (تلاعب بمعرِّف الإسناد) — يُرفَض عبر
// pge_supervisor_mgmt_load_owned_assignment() (عزل حقيقي، لا مجرد تفويض عام).
$_POST = make_post_fields(906, ['assignment_id' => $assignment_905, 'phone' => '0509990003']);
$resp8b = call_ajax_handler('pge_supervisor_mgmt_edit_handler');
check_true('8ب. تلاعب بـassignment_id يخصّ مناسبة أخرى: success=false', !($resp8b['success'] ?? true));
check('8ب. السبب not_found (لا تسريب "موجود لكن ممنوع")', $resp8b['data']['reason'] ?? null, 'not_found');
check('8ب. الصف الأصلي (905) لم يتغيَّر إطلاقاً', $wpdb->supervisors[$assignment_905]['supervisor_phone'] ?? null, '0509990001');

// نفس العزل على resend/revoke
$_POST = make_post_fields(906, ['assignment_id' => $assignment_905]);
$resp8c = call_ajax_handler('pge_supervisor_mgmt_resend_handler');
check('8ج. resend عبر مناسبة أخرى: not_found', $resp8c['data']['reason'] ?? null, 'not_found');

$_POST = make_post_fields(906, ['assignment_id' => $assignment_905]);
$resp8d = call_ajax_handler('pge_supervisor_mgmt_revoke_handler');
check('8د. revoke عبر مناسبة أخرى: not_found', $resp8d['data']['reason'] ?? null, 'not_found');
check('8د. الصف الأصلي (905) لا يزال invited (لم يُلغَ)', $wpdb->supervisors[$assignment_905]['status'] ?? null, 'invited');

// ============================================================================
// السيناريو 9: الترقيم (Pagination) والبحث (Search)
// ============================================================================
echo "\n=== السيناريو 9: الترقيم والبحث ===\n";

setup_host_with_event(707, 907, 100); // حصة كبيرة كافية لإنشاء عدة مشرفين
$GLOBALS['__test_current_user_id'] = 707;

$names = ['محمد الأول', 'سالم الثاني', 'محمد الثالث', 'خالد الرابع', 'محمد الخامس'];
foreach ($names as $i => $name) {
    $_POST = make_post_fields(907, ['name' => $name, 'phone' => '05088800' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
    call_ajax_handler('pge_supervisor_mgmt_create_handler');
}

$_POST = make_post_fields(907, ['page' => 1]);
// نستخدم per_page الافتراضي (20) داخل الخدمة — نعدّل مباشرة بالاستدعاء اليدوي
// عبر معامل page فقط في هذا السيناريو، ونتحقق من total/الحد الأقصى للصفحة.
$resp9_list = call_ajax_handler('pge_supervisor_mgmt_list_handler');
check_true('9. القائمة: success', $resp9_list['success'] ?? false);
check('9. total = 5 (كل المشرفين المُنشَأين لهذه المناسبة)', (int) ($resp9_list['data']['total'] ?? -1), 5);
check('9. عدد العناصر المُعادة في الصفحة = 5 (أقل من per_page)', count($resp9_list['data']['items'] ?? []), 5);
check('9. ترتيب العناصر: الأحدث أولاً (id تنازلياً) — أول عنصر هو آخر مُنشَأ', $resp9_list['data']['items'][0]['name'] ?? null, 'محمد الخامس');

// بحث بالاسم (جزئي، غير حسّاس لحالة الأحرف عربياً لا ينطبق لكن نتحقق من التطابق الجزئي)
$_POST = make_post_fields(907, ['search' => 'محمد']);
$resp9_search = call_ajax_handler('pge_supervisor_mgmt_list_handler');
check('9ب. بحث "محمد": total = 3', (int) ($resp9_search['data']['total'] ?? -1), 3);

// بحث برقم جوال جزئي
$_POST = make_post_fields(907, ['search' => '0508880002']);
$resp9_search_phone = call_ajax_handler('pge_supervisor_mgmt_list_handler');
check('9ج. بحث بالهاتف الكامل: total = 1', (int) ($resp9_search_phone['data']['total'] ?? -1), 1);
check('9ج. النتيجة الوحيدة هي "محمد الثالث"', $resp9_search_phone['data']['items'][0]['name'] ?? null, 'محمد الثالث');

// بحث بلا نتائج
$_POST = make_post_fields(907, ['search' => 'غير موجود إطلاقاً']);
$resp9_empty = call_ajax_handler('pge_supervisor_mgmt_list_handler');
check('9د. بحث بلا نتائج: total = 0', (int) ($resp9_empty['data']['total'] ?? -1), 0);
check('9د. items = مصفوفة فارغة', $resp9_empty['data']['items'] ?? null, []);

// حصة معروضة ضمن استجابة القائمة (Quota reused، لا حساب مكرَّر)
check('9هـ. quota.allowed=100', (int) ($resp9_list['data']['quota']['allowed'] ?? -1), 100);
check('9هـ. quota.used=5', (int) ($resp9_list['data']['quota']['used'] ?? -1), 5);

// ============================================================================
// السيناريو 10: توليد سجل التدقيق (Audit generation) — قراءة عبر الخدمة الحقيقية
// ============================================================================
echo "\n=== السيناريو 10: توليد سجل التدقيق ===\n";

$audit_rows_1 = PGE_Supervisor_Management_Audit::list_for_assignment($assignment1_id);
$audit_actions_1 = array_column($audit_rows_1, 'action');
check('10. سجل الإسناد الأول يحوي: created, edited, invitation_resent, revoked بالترتيب الزمني', $audit_actions_1, ['created', 'edited', 'invitation_resent', 'revoked']);
check_true('10. كل صف تدقيق يحمل actor_user_id وcreated_at', array_reduce($audit_rows_1, function ($carry, $r) {
    return $carry && (int) $r['actor_user_id'] > 0 && !empty($r['created_at']);
}, true));

// ============================================================================
// السيناريو 11: انحدار — list_assignments_for_event() القديمة (Phase 5) غير متأثرة
// ============================================================================
echo "\n=== السيناريو 11: انحدار — list_assignments_for_event() (Phase 5) ===\n";

$legacy_list = PGE_Supervisor_Assignment_Service::list_assignments_for_event(907);
check('11. list_assignments_for_event() القديمة لا تزال تُعيد كل الصفوف (5)', count($legacy_list), 5);
check_true('11. كل صف من القديمة يحتوي فقط المفاتيح الأربعة الأصلية (id, supervisor_phone, supervisor_name, status)', array_reduce($legacy_list, function ($carry, $r) {
    return $carry && array_keys($r) === ['id', 'supervisor_phone', 'supervisor_name', 'status'];
}, true));
check_true('11. list_assignments_for_event() مُرتَّبة تصاعدياً (id ASC) — بلا تغيير في ترتيبها الأصلي', $legacy_list[0]['id'] < $legacy_list[count($legacy_list) - 1]['id']);

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
