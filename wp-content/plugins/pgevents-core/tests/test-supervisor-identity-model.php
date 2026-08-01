<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـEntry Check-in Supervisors، Phase 8
 * Final Fix ("Supervisor Identity Model" RFC) — يثبت تنفيذياً (لا وصفاً فقط)
 * أن النموذج المعماري المُطبَّق فعلياً هو **Model B (Assignment Identity)**:
 * لا هوية مشرف مستقلة عن صف الإسناد، وكل إسناد مُقيَّد بحدث واحد (event_id)
 * فقط، بلا أي دمج/تجميع عبر المناسبات باستخدام رقم الهاتف أو أي مفتاح آخر.
 *
 * لا تعديل على أي كود إنتاج هنا — هذا الملف اختبار توثيقي/معماري بحت، يثبت
 * الحالة الحالية فقط (RFC: "No functional behaviour should change").
 *
 * السيناريوهات:
 *   أ. تدقيق ساكن (Static): فحص نص SQL الفعلي لجدول mon_event_supervisors —
 *      لا عمود/FK باسم supervisor_id، والفهرس event_phone غير فريد (Non-Unique).
 *   ب. لا دمج هوية عبر المناسبات: نفس الهاتف في مناسبتين مختلفتين ينتج صفَّي
 *      إسناد مستقلَّين تماماً (created، لا duplicate_active)، بـuser_id=NULL
 *      في كليهما.
 *   ج. الحصة والقائمة مُقيَّدتان بحدث واحد: لا يتأثر عدّ/سرد مناسبة بوجود
 *      إسناد آخر بنفس الهاتف في مناسبة أخرى.
 *   د. التعديل لا يُسرِّب أثراً عبر المناسبات: تعديل إسناد مناسبة A لا يغيِّر
 *      إسناد مناسبة B رغم تطابق الهاتف الأصلي.
 *   هـ. الجلسة/التفويض مُقيَّدان بإسناد واحد + مناسبة واحدة فقط.
 *   و. لا سطح API لنموذج A: لا توجد أي دالة "كل إسنادات هذا الهاتف عبر
 *      المناسبات" في الخدمة (غياب فعلي، لا افتراض).
 *   ز. انحدار خفيف على تدفّق Phase 8 الكامل (إنشاء → تعديل → إلغاء).
 *
 * التشغيل: php tests/test-supervisor-identity-model.php
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

$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null) {
    return $cap === 'administrator' ? $GLOBALS['__test_user_is_admin'] : false;
}

$GLOBALS['__test_posts'] = [];
function set_test_event($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) ['ID' => $event_id, 'post_type' => $post_type, 'post_author' => $author_id];
}
function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
function get_post_field($field, $post_id) { $p = get_post($post_id); return $p ? ($p->{$field} ?? '') : ''; }

$GLOBALS['__test_user_meta'] = [];
function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function set_test_user_meta($user_id, $key, $value) { $GLOBALS['__test_user_meta'][$user_id][$key] = $value; }
function reset_test_user($user_id) { $GLOBALS['__test_user_meta'][$user_id] = []; }

function setup_host_with_event($host_id, $event_id, $supervisor_limit)
{
    reset_test_user($host_id);
    set_test_user_meta($host_id, '_mon_package_source', 'catalog');
    set_test_user_meta($host_id, '_mon_package_features', ['admin_supervisor_limit' => (string) $supervisor_limit]);
    set_test_event($event_id, $host_id);
}

// ============================================================================
// Fake $wpdb — نفس بنية tests/test-supervisor-management.php (Phase 8) حرفياً،
// بلا أي تبسيط يُخفي سلوكاً حقيقياً — مطلوب لتشغيل الخدمة الحقيقية فقط.
// ============================================================================
class Fake_Wpdb_Identity
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $supervisors = [];
    public $audit_log = [];
    public $sessions = [];
    private $supervisors_next_id = 1;
    private $audit_next_id = 1;
    private $sessions_next_id = 1;
    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];

    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
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
            if (preg_match("/WHERE\s+session_token_hash\s*=\s*'([^']*)'/i", $sql, $m)) {
                $hash = $m[1];
                foreach ($this->sessions as $row) {
                    if (($row['session_token_hash'] ?? null) === $hash) return $row;
                }
            }
            return null;
        }

        if ($which !== 'supervisors') return null;

        if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+supervisor_phone\s*=\s*\'([^\']*)\'\s+AND\s+id\s*!=\s*(\d+)\s+AND\s+status\s+IN\s*\(([^)]*)\)/i', $sql, $m)) {
            $event_id = (int) $m[1]; $phone = $m[2]; $exclude_id = (int) $m[3];
            $statuses = array_map(function ($v) { return trim($v, " '"); }, explode(',', $m[4]));
            foreach ($this->supervisors as $row) {
                if ((int) $row['id'] === $exclude_id) continue;
                if ((int) $row['event_id'] === $event_id && $row['supervisor_phone'] === $phone && in_array($row['status'], $statuses, true)) return $row;
            }
            return null;
        }

        if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+supervisor_phone\s*=\s*\'([^\']*)\'\s+AND\s+status\s+IN\s*\(([^)]*)\)/i', $sql, $m)) {
            $event_id = (int) $m[1]; $phone = $m[2];
            $statuses = array_map(function ($v) { return trim($v, " '"); }, explode(',', $m[3]));
            foreach ($this->supervisors as $row) {
                if ((int) $row['event_id'] === $event_id && $row['supervisor_phone'] === $phone && in_array($row['status'], $statuses, true)) return $row;
            }
            return null;
        }

        if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
            return $this->supervisors[(int) $m[1]] ?? null;
        }

        return null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which !== 'supervisors') return [];

        if (stripos($sql, 'ORDER BY id DESC') !== false && preg_match('/event_id\s*=\s*(\d+)/i', $sql, $mEvent)) {
            $event_id = (int) $mEvent[1];
            $rows = array_values(array_filter($this->supervisors, function ($r) use ($event_id) { return (int) $r['event_id'] === $event_id; }));
            usort($rows, function ($a, $b) { return $b['id'] <=> $a['id']; });
            if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $mLim)) {
                $rows = array_slice($rows, (int) $mLim[2], (int) $mLim[1]);
            }
            return $rows;
        }

        return [];
    }

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $this->lock_acquire_log[] = $m[1];
            $this->held_locks[$m[1]] = true;
            return 1;
        }

        if ($this->which_table($sql) !== 'supervisors') return null;

        if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+status\s+NOT\s+IN\s*\(([^)]*)\)/i', $sql, $m)) {
            $event_id = (int) $m[1];
            $excluded = array_map(function ($v) { return trim($v, " '"); }, explode(',', $m[2]));
            $count = 0;
            foreach ($this->supervisors as $row) {
                if ((int) $row['event_id'] === $event_id && !in_array((string) $row['status'], $excluded, true)) $count++;
            }
            return (string) $count;
        }

        if (stripos($sql, 'SELECT COUNT(*)') !== false && preg_match('/event_id\s*=\s*(\d+)/i', $sql, $mEvent)) {
            $event_id = (int) $mEvent[1];
            $count = 0;
            foreach ($this->supervisors as $row) {
                if ((int) $row['event_id'] === $event_id) $count++;
            }
            return (string) $count;
        }

        return null;
    }

    public function query($sql)
    {
        if (preg_match("/SELECT\s+RELEASE_LOCK\('([^']*)'\)/i", $sql, $m)) {
            $this->lock_release_log[] = $m[1];
            unset($this->held_locks[$m[1]]);
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
            foreach ($data as $k => $v) $this->sessions[$id][$k] = $v;
            return 1;
        }
        if ($which !== 'supervisors') return false;
        $id = $where['id'] ?? null;
        if ($id === null || !isset($this->supervisors[$id])) return 0;
        foreach ($where as $k => $v) {
            if ((string) ($this->supervisors[$id][$k] ?? null) !== (string) $v) return 0;
        }
        foreach ($data as $k => $v) $this->supervisors[$id][$k] = $v;
        return 1;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Identity();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

require_once __DIR__ . '/../includes/supervisor-quota-resolver.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-session.php';

$total = 0;
$passed = 0;
$failures = [];

function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS  $label\n"; }
    else { $failures[] = $label; echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}
function check_true($label, $condition) { check($label, (bool) $condition, true); }

// ============================================================================
// أ. تدقيق ساكن (Static Audit) — نص SQL الفعلي للجدول
// ============================================================================
echo "=== أ. تدقيق ساكن لبنية mon_event_supervisors ===\n";

$schema_source = file_get_contents(__DIR__ . '/../includes/class-mon-catalog-schema.php');
check_true('أ1. ملف الـSchema قابل للقراءة', $schema_source !== false);

if (preg_match('/\$sql_supervisors\s*=\s*"CREATE TABLE.*?;"/s', $schema_source, $m)) {
    $table_block = $m[0];
    check_true('أ2. لا عمود/مفتاح باسم supervisor_id في تعريف الجدول', strpos($table_block, 'supervisor_id') === false);
    check_true('أ3. لا يوجد "UNIQUE KEY event_phone" (الفهرس غير فريد)', strpos($table_block, 'UNIQUE KEY event_phone') === false);
    check_true('أ4. الفهرس غير الفريد على (event_id, supervisor_phone) موجود فعلاً', strpos($table_block, 'KEY event_phone (event_id, supervisor_phone)') !== false);
    check_true('أ5. عمود user_id موجود لكن NULLable (لا NOT NULL) — بقايا مُخطَّطة لنموذج مستقبلي غير مُفعَّل', (bool) preg_match('/user_id\s+BIGINT\(20\)\s+UNSIGNED\s+NULL/', $table_block));
} else {
    check_true('أ2-أ5. تعذّر استخراج تعريف الجدول (فشل بنيوي في الاستخراج نفسه)', false);
}

// ============================================================================
// ب. لا دمج هوية عبر المناسبات (نفس الهاتف، مناسبتان مختلفتان)
// ============================================================================
echo "\n=== ب. لا دمج هوية عبر المناسبات ===\n";

setup_host_with_event(801, 1001, 5);
setup_host_with_event(802, 1002, 5);

$same_phone = '0501234567';
$rb1 = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(1001, 801, $same_phone, 'فلان في مناسبة 1');
$rb2 = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(1002, 802, $same_phone, 'فلان في مناسبة 2');

check('ب1. نفس الهاتف في مناسبة أخرى: created أيضاً (لا duplicate_active عبر المناسبات)', $rb2['result'] ?? null, 'created');
check_true('ب2. صفّان مستقلّان تماماً (id مختلف)', ($rb1['id'] ?? 0) !== ($rb2['id'] ?? 0));
check_true('ب3. عمود user_id موجود فعلياً في الصف الأول (مفتاح مُدخَل، لا غائب)', array_key_exists('user_id', $wpdb->supervisors[$rb1['id']]));
check('ب3ب. قيمة user_id للصف الأول = NULL فعلياً', $wpdb->supervisors[$rb1['id']]['user_id'], null);
check_true('ب4. عمود user_id موجود فعلياً في الصف الثاني (مفتاح مُدخَل، لا غائب)', array_key_exists('user_id', $wpdb->supervisors[$rb2['id']]));
check('ب4ب. قيمة user_id للصف الثاني = NULL فعلياً', $wpdb->supervisors[$rb2['id']]['user_id'], null);
check_true('ب5. لا حقل في أي من الصفَّين يشير للآخر (لا مفتاح مشترك عدا نص الهاتف نفسه)', !isset($wpdb->supervisors[$rb1['id']]['supervisor_id']) && !isset($wpdb->supervisors[$rb2['id']]['supervisor_id']));

// ============================================================================
// ج. الحصة والقائمة مُقيَّدتان بحدث واحد فقط
// ============================================================================
echo "\n=== ج. الحصة والقائمة مُقيَّدتان بحدث واحد ===\n";

$quota_1001 = pge_resolve_supervisor_quota_status(1001);
$quota_1002 = pge_resolve_supervisor_quota_status(1002);
check('ج1. used لمناسبة 1001 = 1 (لا يُحتسَب صف مناسبة 1002 رغم تطابق الهاتف)', $quota_1001['used'] ?? null, 1);
check('ج2. used لمناسبة 1002 = 1 (بالمثل، مستقل تماماً)', $quota_1002['used'] ?? null, 1);

$list_1001 = PGE_Supervisor_Assignment_Service::list_assignments_for_event_page(1001, '', 1, 20);
check('ج3. قائمة مناسبة 1001 تحتوي عنصراً واحداً فقط', (int) $list_1001['total'], 1);
check('ج4. العنصر الوحيد يخصّ event_id=1001 حصراً (لا تسريب من 1002)', $list_1001['items'][0]['id'] ?? null, $rb1['id']);

// ============================================================================
// د. التعديل لا يُسرِّب أثراً عبر المناسبات
// ============================================================================
echo "\n=== د. التعديل لا يُسرِّب أثراً عبر المناسبات ===\n";

$edit_result = PGE_Supervisor_Assignment_Service::edit_supervisor_details($rb1['id'], '0509999999', 'اسم مُعدَّل');
check('د1. تعديل صف مناسبة 1001: نجح', $edit_result['result'] ?? null, 'updated');
check('د2. هاتف الصف الثاني (مناسبة 1002) لم يتغيَّر إطلاقاً', $wpdb->supervisors[$rb2['id']]['supervisor_phone'] ?? null, $same_phone);
check('د3. اسم الصف الثاني (مناسبة 1002) لم يتغيَّر إطلاقاً', $wpdb->supervisors[$rb2['id']]['supervisor_name'] ?? null, 'فلان في مناسبة 2');

// ============================================================================
// هـ. الجلسة/التفويض مُقيَّدان بإسناد واحد + مناسبة واحدة
// ============================================================================
echo "\n=== هـ. الجلسة مُقيَّدة بإسناد واحد + مناسبة واحدة ===\n";

// validate_session() يتحقّق من أن حالة الإسناد الحيّة "active" بالضبط —
// نضبطها مباشرة هنا (بدلاً من عبور تدفّق accept_invitation() الكامل، غير
// المعنيّ به هذا السيناريو) لعزل اختبار الجلسة/الهوية فقط.
$wpdb->supervisors[$rb1['id']]['status'] = 'active';
$wpdb->supervisors[$rb2['id']]['status'] = 'active';

$session_a = PGE_Supervisor_Session::create_session($rb1['id'], 1001);
$session_b = PGE_Supervisor_Session::create_session($rb2['id'], 1002);
check_true('هـ1. جلستان مستقلَّتان أُنشئتا بنجاح', ($session_a['result'] ?? null) === 'created' && ($session_b['result'] ?? null) === 'created');
check_true('هـ2. توكنا الجلستين مختلفان تماماً', $session_a['session_token'] !== $session_b['session_token']);

$validate_a = PGE_Supervisor_Session::validate_session($session_a['session_token']);
check('هـ3. جلسة A تُحلّ إلى assignment_id الصحيح (rb1)، لا rb2', $validate_a['assignment_id'] ?? null, $rb1['id']);
check('هـ4. جلسة A تُحلّ إلى event_id=1001، لا 1002 (رغم تطابق الهاتف الأصلي بين الإسنادين)', $validate_a['event_id'] ?? null, 1001);

$validate_b = PGE_Supervisor_Session::validate_session($session_b['session_token']);
check('هـ5. جلسة B تُحلّ إلى assignment_id الصحيح (rb2)، لا rb1', $validate_b['assignment_id'] ?? null, $rb2['id']);

// ============================================================================
// و. لا سطح API لنموذج A (غياب فعلي لأي دالة "كل إسنادات هذا الهاتف")
// ============================================================================
echo "\n=== و. لا سطح API لنموذج A ===\n";

$forbidden_model_a_methods = [
    'list_assignments_for_phone',
    'get_supervisor_by_phone',
    'find_supervisor_across_events',
    'merge_assignments',
    'get_all_assignments_for_supervisor',
];
foreach ($forbidden_model_a_methods as $method_name) {
    check_true("و. لا وجود لدالة نموذج A: PGE_Supervisor_Assignment_Service::$method_name", !method_exists('PGE_Supervisor_Assignment_Service', $method_name));
}
check_true('و. لا وجود لعمود supervisor_id في أي صف مُنشَأ فعلياً', !array_key_exists('supervisor_id', $wpdb->supervisors[$rb1['id']]));

// ============================================================================
// ز. انحدار خفيف — تدفّق Phase 8 الكامل (إنشاء → تعديل → إلغاء)
// ============================================================================
echo "\n=== ز. انحدار خفيف على Phase 8 ===\n";

setup_host_with_event(803, 1003, 3);
$rz1 = PGE_Supervisor_Assignment_Service::create_supervisor_assignment(1003, 803, '0501112233', 'انحدار');
check('ز1. إنشاء: created', $rz1['result'] ?? null, 'created');

$edit_z = PGE_Supervisor_Assignment_Service::edit_supervisor_details($rz1['id'], '0501112299', 'انحدار مُعدَّل');
check('ز2. تعديل: updated', $edit_z['result'] ?? null, 'updated');

$resend_z = PGE_Supervisor_Assignment_Service::resend_invitation($rz1['id']);
check('ز3. إعادة إرسال: resent (لا صف جديد)', $resend_z['result'] ?? null, 'resent');
check('ز4. لا يزال صفاً واحداً فقط لهذه المناسبة', (int) PGE_Supervisor_Assignment_Service::list_assignments_for_event_page(1003, '', 1, 20)['total'], 1);

$revoke_z = PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($rz1['id']);
check('ز5. إلغاء: revoked', $revoke_z['result'] ?? null, 'revoked');
check('ز6. الحصة بعد الإلغاء: used=0 (لا يزال مُقيَّداً بحدث واحد)', pge_resolve_supervisor_quota_status(1003)['used'] ?? null, 0);

// ── ملخص ────────────────────────────────────────────────────────────────

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
exit(0);
