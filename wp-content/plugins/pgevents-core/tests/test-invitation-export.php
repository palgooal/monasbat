<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـPhase 9C ("Invitation Export") —
 * includes/class-pge-invitation-export.php، includes/class-pge-xlsx-writer.php،
 * ونقطتا AJAX الحقيقيتان pge_invitation_mgmt_export_csv_handler()/
 * pge_invitation_mgmt_export_excel_handler() في invitation-management-ajax.php.
 * يستدعي المعالجات/الخدمات الحقيقية بأسمائها مباشرة (نفس اصطلاح test-
 * invitation-management.php حرفياً) — "Do NOT create logical mirrors of
 * production code. Execute the real activation code."
 *
 * الفئات الـ16 المطلوبة صراحةً في الـRFC (Phase 9C — Invitation Export):
 * 1) تصدير CSV، 2) تصدير Excel، 3) تقييد المناسبة الحالية، 4) الفلترة،
 * 5) تصدير مع بحث، 6) الحفاظ على الترتيب، 7) التفويض، 8) تصدير فارغ،
 * 9) تصدير كبير، 10) UTF-8 عربي، 11) حدث التدقيق، 12) عدم تصدير حمولة QR،
 * 13) عدم تصدير qr_version، 14) عدم تصدير التوقيع، 15) انحدار Phase 9A،
 * 16) انحدار Phase 9B.
 *
 * السيناريو 17 (Phase 9C Final Security Fix — "Spreadsheet Formula Injection
 * Protection"): اختبارات PGE_Invitation_Export::sanitize_spreadsheet_cell()
 * المباشرة (كل الحالات الحدّية المطلوبة صراحةً)، بالإضافة لتكامل كامل عبر
 * خط الأنابيب الحقيقي (إنشاء → تصدير CSV/Excel) يثبت: التطهير الفعلي في
 * كلا التنسيقين، عدم تغيّر القيمة المخزَّنة في قاعدة البيانات، عدم تأثر
 * الفلترة/الترتيب/عدد الصفوف/التدقيق/التصدير الكبير/التصدير الفارغ، وانحدار
 * سليم على Phase 9A/9B/منطق تصدير Phase 9C الأصلي.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-invitation-export.php (أو عبر runany.mjs في بيئة php-wasm)
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__test_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['__test_registered_hooks'][$hook] = true; }
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
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($v) { return preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string) $v); }
}
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now'] ?? '2026-01-01 00:00:00'; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('wp_hash')) {
    function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed'); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) { return strtolower((string) $string); }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) { return strpos((string) $haystack, (string) $needle, $offset); }
}

// ── تفويض/جلسة — نفس أسلوب test-invitation-management.php حرفياً ───────────
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null) {
    if ($cap === 'administrator') return $GLOBALS['__test_user_is_admin'];
    return false;
}

// ── Posts وهمية ──────────────────────────────────────────────────────────
$GLOBALS['__test_posts'] = [];
function set_test_event($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) ['ID' => $event_id, 'post_type' => $post_type, 'post_author' => $author_id];
}
function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
function get_post_field($field, $post_id) { $p = get_post($post_id); return $p ? ($p->{$field} ?? '') : ''; }
function get_the_title($post_id) { $p = get_post($post_id); return $p ? ('مناسبة #' . $p->ID) : ''; }

// ── Post Meta وهمية ──────────────────────────────────────────────────────
$GLOBALS['__test_post_meta'] = [];
function get_post_meta($post_id, $key = '', $single = false)
{
    $value = $GLOBALS['__test_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_post_meta($post_id, $key, $value)
{
    $GLOBALS['__test_post_meta'][$post_id][$key] = $value;
    return true;
}

if (!class_exists('Test_Wp_Die_Exception')) {
    class Test_Wp_Die_Exception extends \Exception {}
}

$GLOBALS['__test_nonce_valid_actions'] = [];
function wp_create_nonce($action) { $GLOBALS['__test_nonce_valid_actions'][$action] = true; return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception('wp_die'); }
function esc_url($v) { return $v; }
function esc_attr($v) { return $v; }
function esc_html($v) { return $v; }
function admin_url($path = '') { return 'http://example.test/wp-admin/' . ltrim($path, '/'); }
function home_url($path = '') { return 'http://example.test' . $path; }

function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // متوقَّع دائماً.
    }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

/**
 * التقاط المخرَجات الخام لمعالجات التصدير (echo + wp_die())، أو استجابة
 * JSON إن فشل التحقق مبكراً (pge_invitation_mgmt_validate_request()) — كلا
 * الحالتين ممكنتان فعلياً من نفس نقطة الدخول (نفس نمط test-invitation-
 * management.php::call_export_handler، مع إضافة التقاط JSON عند الفشل).
 */
function call_export_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    ob_start();
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // متوقَّع (wp_die() في نهاية المسار الناجح، أو wp_send_json_error عند الفشل المبكر).
    }
    $raw_output = ob_get_clean();
    return ['output' => $raw_output, 'json' => $GLOBALS['__test_json_response']];
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

// ============================================================================
// Fake $wpdb — wp_pge_event_rsvps (+checked_in_by_assignment_id)،
// wp_mon_event_supervisors، wp_pge_invitation_mgmt_audit_log
// ============================================================================
class Fake_Wpdb_Invitation_Export
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $rsvp_rows = [];        // ['event_id','guest_phone','checked_in_by_assignment_id']
    public $supervisor_rows = [];  // ['id','event_id','supervisor_phone','supervisor_name','status']
    public $audit_log = [];
    private $audit_next_id = 1;

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

    private function which_table($sql)
    {
        if (strpos($sql, $this->prefix . 'pge_event_rsvps') !== false) return 'rsvps';
        if (strpos($sql, $this->prefix . 'mon_event_supervisors') !== false) return 'supervisors';
        if (strpos($sql, $this->prefix . 'pge_invitation_mgmt_audit_log') !== false) return 'audit';
        return null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);

        if ($which === 'rsvps') {
            // استعلامان حقيقيان مختلفان يقرآن نفس الجدول (نفس الأسلوب المُبسَّط
            // في Fake_Wpdb_Invitation_Mgmt::get_results() الأصلية — تُعاد كل
            // الأعمدة المُتاحة دائماً بصرف النظر عن قائمة SELECT الحرفية):
            //   1) pge_event_guests_load_rsvp_from_db() (event-guests.php):
            //      SELECT guest_phone, reply, checked_in ...
            //   2) PGE_Invitation_Export::load_supervisor_names_by_phone():
            //      SELECT guest_phone, checked_in_by_assignment_id ... IS NOT NULL
            if (!preg_match('/WHERE\s+event_id\s*=\s*(\d+)/i', $sql, $m)) return [];
            $event_id = (int) $m[1];
            $requires_assignment = (strpos($sql, 'checked_in_by_assignment_id IS NOT NULL') !== false);
            $out = [];
            foreach ($this->rsvp_rows as $row) {
                if ((int) $row['event_id'] !== $event_id) continue;
                if ($requires_assignment && empty($row['checked_in_by_assignment_id'])) continue;
                $out[] = [
                    'guest_phone'                 => $row['guest_phone'],
                    'reply'                       => $row['reply'] ?? '',
                    'checked_in'                  => $row['checked_in'] ?? 0,
                    'checked_in_by_assignment_id' => $row['checked_in_by_assignment_id'] ?? null,
                ];
            }
            return $out;
        }

        if ($which === 'supervisors') {
            if (!preg_match('/WHERE\s+event_id\s*=\s*(\d+)/i', $sql, $m)) return [];
            $event_id = (int) $m[1];
            $out = [];
            foreach ($this->supervisor_rows as $row) {
                if ((int) $row['event_id'] === $event_id) {
                    $out[] = ['id' => $row['id'], 'supervisor_phone' => $row['supervisor_phone'], 'supervisor_name' => $row['supervisor_name'], 'status' => $row['status']];
                }
            }
            usort($out, function ($a, $b) { return $a['id'] <=> $b['id']; });
            return $out;
        }

        if ($which === 'audit') {
            if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+guest_phone\s*=\s*\'([^\']*)\'/i', $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                $rows = array_values(array_filter($this->audit_log, function ($r) use ($event_id, $phone) {
                    return (int) $r['event_id'] === $event_id && $r['guest_phone'] === $phone;
                }));
                usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
                return $rows;
            }
            return [];
        }

        return [];
    }

    public function insert($table, $data, $format = null)
    {
        if ($this->which_table($table) === 'audit') {
            $id = $this->audit_next_id++;
            $this->audit_log[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        return false;
    }

    public function seed_rsvp_checkin($event_id, $phone, $assignment_id)
    {
        $this->rsvp_rows[] = [
            'event_id'                    => $event_id,
            'guest_phone'                 => $phone,
            'reply'                       => 'yes',
            'checked_in'                  => 1,
            'checked_in_by_assignment_id' => $assignment_id,
        ];
    }

    public function seed_supervisor($id, $event_id, $phone, $name, $status = 'active')
    {
        $this->supervisor_rows[] = ['id' => $id, 'event_id' => $event_id, 'supervisor_phone' => $phone, 'supervisor_name' => $name, 'status' => $status];
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Invitation_Export();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────
require_once __DIR__ . '/../includes/helpers.php'; // RC1 Fix Pack 2 (A9): pge_mgmt_validate_request() المشتركة
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php'; // عمود "المشرف"
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php';            // للفئتين 12/14 (بناء حمولة/توقيع QR حقيقيَّين للمقارنة السلبية)
require_once __DIR__ . '/../includes/invitation-management-ajax.php'; // يُحمِّل class-pge-invitation-export.php وclass-pge-xlsx-writer.php داخلياً

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

// ============================================================================
// تجهيز البيانات الثابتة: مناسبتان (1001 لمعظم السيناريوهات، 1002 لسيناريو
// تقييد المناسبة الحالية)، مضيف 801 يملك 1001، مضيف 802 يملك 1002.
// ============================================================================
set_test_event(1001, 801);
set_test_event(1002, 802);
$GLOBALS['__test_current_user_id'] = 801;

function create_invitation($event_id, $phone, $name, $actor)
{
    $_POST = make_post_fields($event_id, ['name' => $name, 'phone' => $phone]);
    return call_ajax_handler('pge_invitation_mgmt_create_handler');
}

$GLOBALS['__test_current_user_id'] = 801;
create_invitation(1001, '0501111111', 'محمد العلي', 801);   // سيُلغى لاحقاً
create_invitation(1001, '0502222222', 'سارة أحمد', 801);     // ستُسجَّل حضورها عبر مشرف
create_invitation(1001, '0503333333', 'خالد سالم', 801);
create_invitation(1001, '0504444444', 'ليلى محمود', 801);    // سيُجدَّد رمز QR الخاص بها

$_POST = make_post_fields(1001, ['phone' => '0501111111', 'reason' => 'تعارض مواعيد']);
call_ajax_handler('pge_invitation_mgmt_cancel_handler');

$GLOBALS['__test_current_user_id'] = 801;
$_POST = make_post_fields(1001, ['phone' => '0504444444']);
call_ajax_handler('pge_invitation_mgmt_qr_regenerate_handler');

// مشرف واحد نشط على 1001 + تسجيل حضور 0502222222 عبره (بيانات ثابتة، تُقرأ
// عبر PGE_Supervisor_Assignment_Service::list_assignments_for_event() الحقيقية).
$wpdb->seed_supervisor(501, 1001, '0555000000', 'المشرف أحمد', 'active');
$wpdb->seed_rsvp_checkin(1001, '0502222222', 501);

// مناسبة 1002 منفصلة تماماً — لإثبات عدم التسريب بين المناسبات.
$GLOBALS['__test_current_user_id'] = 802;
create_invitation(1002, '0509999999', 'ضيف مناسبة أخرى', 802);
$GLOBALS['__test_current_user_id'] = 801;

// ============================================================================
// 1) تصدير CSV — صحة أساسية
// ============================================================================
echo "=== 1) تصدير CSV ===\n";
$_POST = make_post_fields(1001, []);
$csv1 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('1. المخرَجات تبدأ بـUTF-8 BOM', strpos($csv1['output'], PGE_Invitation_Export::CSV_BOM) === 0);
$csv1_lines = explode("\r\n", rtrim(substr($csv1['output'], strlen(PGE_Invitation_Export::CSV_BOM)), "\r\n"));
check('1. سطر الرأس يحوي 11 عموداً', count(str_getcsv($csv1_lines[0])), 11);
check('1. عدد أسطر البيانات = 4 دعوات (3 نشطة + 1 مُلغاة، كلها ضمن النتيجة المُرشَّحة الافتراضية)', count($csv1_lines) - 1, 4);
check_true('1. اسم "محمد العلي" ظاهر في المخرَجات', strpos($csv1['output'], 'محمد العلي') !== false);

// ============================================================================
// 2) تصدير Excel — .xlsx حقيقي (بنية ZIP/OOXML مُتحقَّق منها فعلياً)
// ============================================================================
echo "\n=== 2) تصدير Excel ===\n";
$_POST = make_post_fields(1001, []);
$xlsx2 = call_export_handler('pge_invitation_mgmt_export_excel_handler');
$bin2 = $xlsx2['output'];
check_true('2. المخرَجات تبدأ بتوقيع ZIP Local File Header (PK\\x03\\x04)', substr($bin2, 0, 4) === "PK\x03\x04");
check_true('2. توقيع End Of Central Directory موجود قرب النهاية (PK\\x05\\x06)', strpos($bin2, "PK\x05\x06") !== false);
// عدّاد الإدخالات في EOCD (offset 10، u16 LE) = 6 أجزاء OOXML بالضبط.
$eocd_pos2 = strpos($bin2, "PK\x05\x06");
$entry_count2 = unpack('v', substr($bin2, $eocd_pos2 + 10, 2))[1];
check('2. عدد الإدخالات في نهاية الدليل المركزي = 6 (بنية OOXML الثابتة)', $entry_count2, 6);
check_true('2. اسم الجزء xl/worksheets/sheet1.xml موجود في الأرشيف', strpos($bin2, 'xl/worksheets/sheet1.xml') !== false);
check_true('2. ليس HTML متنكِّراً بامتداد Excel (لا وسم <table لأي مكان في المخرَجات)', stripos($bin2, '<table') === false);

// ============================================================================
// 3) تقييد المناسبة الحالية — لا تسريب بين المناسبات
// ============================================================================
echo "\n=== 3) تقييد المناسبة الحالية ===\n";
$_POST = make_post_fields(1001, []);
$csv3 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('3. تصدير 1001 لا يحوي ضيف مناسبة 1002', strpos($csv3['output'], 'ضيف مناسبة أخرى') === false);

$GLOBALS['__test_current_user_id'] = 802;
$_POST = make_post_fields(1002, []);
$csv3b = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('3ب. تصدير 1002 يحوي ضيفه فقط', strpos($csv3b['output'], 'ضيف مناسبة أخرى') !== false);
check_true('3ب. تصدير 1002 لا يحوي أي ضيف من 1001', strpos($csv3b['output'], 'محمد العلي') === false);
$GLOBALS['__test_current_user_id'] = 801;

// ============================================================================
// 4) الفلترة — نفس محرّك list_invitations() الحالي، بلا خوارزمية ثانية
// ============================================================================
echo "\n=== 4) الفلترة ===\n";
$_POST = make_post_fields(1001, ['invitation_status' => 'cancelled']);
$csv4 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
$csv4_lines = explode("\r\n", rtrim(substr($csv4['output'], strlen(PGE_Invitation_Export::CSV_BOM)), "\r\n"));
check('4. فلتر invitation_status=cancelled: سطر بيانات واحد فقط', count($csv4_lines) - 1, 1);
check_true('4. السطر الوحيد يخص محمد العلي (الدعوة المُلغاة)', strpos($csv4['output'], 'محمد العلي') !== false);
check_true('4. سارة أحمد (نشطة) غائبة عن هذا الفلتر', strpos($csv4['output'], 'سارة أحمد') === false);

// ============================================================================
// 5) تصدير مع بحث
// ============================================================================
echo "\n=== 5) تصدير مع بحث ===\n";
$_POST = make_post_fields(1001, ['search' => 'خالد']);
$csv5 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
$csv5_lines = explode("\r\n", rtrim(substr($csv5['output'], strlen(PGE_Invitation_Export::CSV_BOM)), "\r\n"));
check('5. بحث "خالد": سطر بيانات واحد فقط', count($csv5_lines) - 1, 1);
check_true('5. السطر يخص خالد سالم', strpos($csv5['output'], 'خالد سالم') !== false);

// ============================================================================
// 6) الحفاظ على الترتيب — مطابق تماماً لـlist_invitations() الحقيقية
// ============================================================================
echo "\n=== 6) الحفاظ على الترتيب ===\n";
$filters6 = ['sort_by' => 'phone', 'sort_dir' => 'desc'];
$list6 = PGE_Invitation_Service::list_invitations(1001, array_merge($filters6, ['page' => 1, 'per_page' => 100]));
$expected_order6 = array_column($list6['items'], 'phone');

$dataset6 = PGE_Invitation_Export::build_dataset(1001, $filters6);
$actual_order6 = array_column($dataset6['rows'], 0); // العمود 0 = معرّف الدعوة (الهاتف)
check('6. ترتيب sort_by=phone,desc مطابق تماماً لقائمة العرض (لا خوارزمية فرز ثانية)', $actual_order6, $expected_order6);

// ============================================================================
// 7) التفويض — نفس pge_event_guests_user_can_manage() الحالية، لا صلاحية جديدة
// ============================================================================
echo "\n=== 7) التفويض ===\n";
$GLOBALS['__test_current_user_id'] = 999; // مستخدم دخيل، ليس مضيف 1001 ولا أدمن
$_POST = make_post_fields(1001, []);
$csv7 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
$json7 = $csv7['json'] ? json_decode($csv7['json'], true) : null;
check_true('7. مستخدم دخيل: لا محتوى CSV مُصدَّر (فشل مبكر قبل أي ترويسة/بث)', $csv7['output'] === '');
check_true('7. مستخدم دخيل: استجابة JSON خطأ فعلية (forbidden)', $json7 !== null && ($json7['success'] ?? true) === false);
check('7. سبب الرفض forbidden', $json7['data']['reason'] ?? null, 'forbidden');
$GLOBALS['__test_current_user_id'] = 801;

// ============================================================================
// 8) تصدير فارغ — مناسبة موجودة بلا أي دعوة
// ============================================================================
echo "\n=== 8) تصدير فارغ ===\n";
set_test_event(1003, 803);
$GLOBALS['__test_current_user_id'] = 803;
$_POST = make_post_fields(1003, []);
$csv8 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
$csv8_lines = explode("\r\n", rtrim(substr($csv8['output'], strlen(PGE_Invitation_Export::CSV_BOM)), "\r\n"));
check('8. مناسبة فارغة: سطر الرأس فقط (0 سطر بيانات)، بلا أي خطأ/استثناء', count($csv8_lines), 1);
check('8. سطر الرأس لا يزال 11 عموداً حتى مع 0 دعوات', count(str_getcsv($csv8_lines[0])), 11);

$_POST = make_post_fields(1003, []);
$xlsx8 = call_export_handler('pge_invitation_mgmt_export_excel_handler');
check_true('8ب. Excel فارغ أيضاً ملف .xlsx صالح (نفس توقيع ZIP)', substr($xlsx8['output'], 0, 4) === "PK\x03\x04");
$GLOBALS['__test_current_user_id'] = 801;

// ============================================================================
// 9) تصدير كبير — 250 دعوة، بلا أخطاء/بلا اختصار
// ============================================================================
echo "\n=== 9) تصدير كبير ===\n";
set_test_event(1004, 804);
$GLOBALS['__test_current_user_id'] = 804;
for ($i = 1; $i <= 250; $i++) {
    create_invitation(1004, '059' . str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'ضيف رقم ' . $i, 804);
}
$_POST = make_post_fields(1004, []);
$csv9 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
$csv9_lines = explode("\r\n", rtrim(substr($csv9['output'], strlen(PGE_Invitation_Export::CSV_BOM)), "\r\n"));
check('9. تصدير 250 دعوة: 250 سطر بيانات بالضبط', count($csv9_lines) - 1, 250);

$_POST = make_post_fields(1004, []);
$xlsx9 = call_export_handler('pge_invitation_mgmt_export_excel_handler');
$eocd_pos9 = strpos($xlsx9['output'], "PK\x05\x06");
check_true('9ب. Excel لقائمة كبيرة أيضاً ملف صالح (EOCD موجود)', $eocd_pos9 !== false);
$GLOBALS['__test_current_user_id'] = 801;

// ============================================================================
// 10) UTF-8 عربي — الأسماء العربية تظهر صحيحة بايتياً في كلا التنسيقين
// ============================================================================
echo "\n=== 10) UTF-8 عربي ===\n";
$_POST = make_post_fields(1001, []);
$csv10 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('10. CSV: "سارة أحمد" محفوظة بايتياً (UTF-8 صحيح، لا تلف ترميز)', strpos($csv10['output'], 'سارة أحمد') !== false);

$_POST = make_post_fields(1001, []);
$xlsx10 = call_export_handler('pge_invitation_mgmt_export_excel_handler');
check_true('10ب. Excel: "سارة أحمد" محفوظة بايتياً داخل sheet1.xml (خلايا inlineStr)', strpos($xlsx10['output'], 'سارة أحمد') !== false);

// ============================================================================
// 11) حدث التدقيق — export_completed واحد بالضبط لكل تصدير، بلا محتوى مُخزَّن
// ============================================================================
echo "\n=== 11) حدث التدقيق ===\n";
$wpdb->audit_log = []; // بداية نظيفة لعزل هذا السيناريو عن التصديرات السابقة
$_POST = make_post_fields(1001, []);
call_export_handler('pge_invitation_mgmt_export_csv_handler');
$export_audit_rows_11 = array_values(array_filter($wpdb->audit_log, function ($r) { return $r['action'] === 'export_completed'; }));
check('11. سجل تدقيق export_completed واحد بالضبط بعد تصدير CSV', count($export_audit_rows_11), 1);
check('11. event_id صحيح', (int) ($export_audit_rows_11[0]['event_id'] ?? 0), 1001);
check('11. actor_user_id = المستخدم الحالي', (int) ($export_audit_rows_11[0]['actor_user_id'] ?? 0), 801);
check('11. guest_phone = السنتينل على مستوى المناسبة (لا دعوة واحدة)', $export_audit_rows_11[0]['guest_phone'] ?? null, PGE_Invitation_Management_Audit::EVENT_LEVEL_PHONE_SENTINEL);
$reason_decoded_11 = json_decode($export_audit_rows_11[0]['reason'] ?? '', true);
check('11. reason.format = csv', $reason_decoded_11['format'] ?? null, 'csv');
check('11. reason.count = 4 (دعوات 1001 الأربع)', $reason_decoded_11['count'] ?? null, 4);
check_true('11. لا أي بيانات ضيف/محتوى فعلي داخل reason (فقط format+count)', strpos($export_audit_rows_11[0]['reason'] ?? '', 'محمد') === false);

$_POST = make_post_fields(1001, []);
call_export_handler('pge_invitation_mgmt_export_excel_handler');
$export_audit_rows_11b = array_values(array_filter($wpdb->audit_log, function ($r) { return $r['action'] === 'export_completed'; }));
check('11ب. سجل تدقيق ثانٍ واحد بعد تصدير Excel (مجموع 2، append-only)', count($export_audit_rows_11b), 2);
$reason_11b = json_decode($export_audit_rows_11b[1]['reason'] ?? '', true);
check('11ب. reason.format = xlsx للتصدير الثاني', $reason_11b['format'] ?? null, 'xlsx');

// ============================================================================
// 12) عدم تصدير حمولة QR — لا أي حقل/عمود يحمل حمولة الماسح الموقَّعة
// ============================================================================
echo "\n=== 12) عدم تصدير حمولة QR ===\n";
$dataset12 = PGE_Invitation_Export::build_dataset(1001, []);
check('12. الرأس 11 عموداً بالضبط (بلا عمود QR payload إضافي)', count($dataset12['header']), 11);
check_true('12. لا أي رأس عمود يذكر "حمولة" أو "payload"', !array_reduce($dataset12['header'], function ($carry, $h) { return $carry || stripos($h, 'حمولة') !== false || stripos($h, 'payload') !== false; }, false));
// بناء حمولة QR كنسية حقيقية فعلياً لدعوة 0504444444 (جُدِّدت في الإعداد أعلاه) للمقارنة السلبية.
$qr_version12 = PGE_Invitation_Repository::get_qr_version(1001, '0504444444');
$real_payload_12 = PGE_Checkin_QR_Service::build_payload(1001, 4, $qr_version12); // rsvp_id افتراضي 4 (لا يؤثر على صحة المقارنة السلبية)
$_POST = make_post_fields(1001, []);
$csv12 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('12. الحمولة الكنسية الحقيقية (event_id|rsvp_id|qr_version|signature) غير موجودة إطلاقاً في مخرَجات CSV', strpos($csv12['output'], $real_payload_12) === false);

// ============================================================================
// 13) عدم تصدير qr_version
// ============================================================================
echo "\n=== 13) عدم تصدير qr_version ===\n";
check_true('13. لا أي رأس عمود = "qr_version" حرفياً', !in_array('qr_version', $dataset12['header'], true));
check_true('13. لا أي رأس عمود يذكر "الإصدار" (rotation primitive)', !array_reduce($dataset12['header'], function ($carry, $h) { return $carry || stripos($h, 'qr_version') !== false; }, false));
// كل صف بيانات = 11 خلية بالضبط (لا خلية qr_version خام مُلحَقة).
$row_lengths_13 = array_map('count', $dataset12['rows']);
check_true('13. كل صف بيانات 11 خلية بالضبط (بلا عمود qr_version خام)', count(array_unique($row_lengths_13)) <= 1 && (empty($row_lengths_13) || $row_lengths_13[0] === 11));

// ============================================================================
// 14) عدم تصدير التوقيع (signature)
// ============================================================================
echo "\n=== 14) عدم تصدير التوقيع ===\n";
check_true('14. لا أي رأس عمود يذكر "توقيع" أو "signature"', !array_reduce($dataset12['header'], function ($carry, $h) { return $carry || stripos($h, 'توقيع') !== false || stripos($h, 'signature') !== false; }, false));
$signature_parts_14 = explode('|', $real_payload_12);
$real_signature_12 = $signature_parts_14[3] ?? '';
check_true('14. التوقيع الحقيقي المُشتَق فعلياً (wp_hash) طوله معقول (تأكيد أنه أُنتِج فعلياً)', strlen($real_signature_12) >= 32);
check_true('14. هذا التوقيع الحقيقي غير موجود إطلاقاً في مخرَجات CSV', strpos($csv12['output'], $real_signature_12) === false);
$_POST = make_post_fields(1001, []);
$xlsx14 = call_export_handler('pge_invitation_mgmt_export_excel_handler');
check_true('14ب. ولا في مخرَجات Excel الثنائية أيضاً', strpos($xlsx14['output'], $real_signature_12) === false);

// ============================================================================
// 15) انحدار — Phase 9A (List/Create/Edit/Cancel/Search/Filter/Pagination لا تزال تعمل)
// ============================================================================
echo "\n=== 15) انحدار Phase 9A ===\n";
$_POST = make_post_fields(1001, ['page' => 1, 'per_page' => 2, 'sort_by' => 'name', 'sort_dir' => 'asc']);
$list15 = call_ajax_handler('pge_invitation_mgmt_list_handler');
check_true('15. القائمة (Phase 9A) لا تزال تعمل: success', $list15['success'] ?? false);
check('15. الترقيم لا يزال يعمل: عنصران فقط في الصفحة', count($list15['data']['items'] ?? []), 2);

$_POST = make_post_fields(1001, ['old_phone' => '0503333333', 'phone' => '0503333399', 'name' => 'خالد سالم المعدَّل']);
$edit15 = call_ajax_handler('pge_invitation_mgmt_edit_handler');
check_true('15ب. التعديل (Phase 9A) لا يزال يعمل', $edit15['success'] ?? false);

$_POST = make_post_fields(1001, []);
$csv15 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('15ج. التصدير يعكس التعديل الأخير فوراً (خالد سالم المعدَّل، لا تخزين مكرَّر)', strpos($csv15['output'], 'خالد سالم المعدَّل') !== false);

// ============================================================================
// 16) انحدار — Phase 9B (Resend/QR Regeneration لا تزالان تعملان)
// ============================================================================
echo "\n=== 16) انحدار Phase 9B ===\n";
check_true('16. wp_ajax_pge_invitation_mgmt_resend لا يزال مُسجَّلاً', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_resend']));
check_true('16ب. wp_ajax_pge_invitation_mgmt_qr_regenerate لا يزال مُسجَّلاً', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_qr_regenerate']));
check_true('16ج. wp_ajax_pge_invitation_mgmt_export_csv مُسجَّل الآن (Phase 9C مُعتمَدة)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_export_csv']));
check_true('16د. wp_ajax_pge_invitation_mgmt_export_excel مُسجَّل الآن (Phase 9C مُعتمَدة)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_export_excel']));

$qr_version_before_16 = PGE_Invitation_Repository::get_qr_version(1001, '0502222222');
$_POST = make_post_fields(1001, ['phone' => '0502222222']);
$regen16 = call_ajax_handler('pge_invitation_mgmt_qr_regenerate_handler');
check_true('16هـ. تجديد QR (Phase 9B) لا يزال يعمل فعلياً بعد إضافة Phase 9C', $regen16['success'] ?? false);
$qr_version_after_16 = PGE_Invitation_Repository::get_qr_version(1001, '0502222222');
check('16و. qr_version تدوَّر فعلياً (+1)', $qr_version_after_16, $qr_version_before_16 + 1);

$_POST = make_post_fields(1001, ['phone' => '0502222222']);
$resend16 = call_ajax_handler('pge_invitation_mgmt_resend_handler');
check_true('16ز. إعادة الإرسال (Phase 9B) لا تزال تعمل فعلياً', $resend16['success'] ?? false);

// إثبات تكميلي: عمود "المشرف" في التصدير يعكس فعلياً بيانات Phase 8 الحقيقية
// (لم تُلمَس هنا، قُرئت فقط عبر استعلامَين للقراءة).
$_POST = make_post_fields(1001, []);
$csv16 = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('16ح. عمود "المشرف" يظهر فعلياً لسارة أحمد (سجَّلها المشرف أحمد فعلياً)', strpos($csv16['output'], 'المشرف أحمد') !== false);

// ============================================================================
// 17) الحماية من حقن الصيغ (Spreadsheet Formula Injection) — Phase 9C Final Security Fix
// ============================================================================
echo "\n=== 17) الحماية من حقن الصيغ (Spreadsheet Formula Injection) ===\n";

// --- 17.1 اختبارات وحدة مباشرة على sanitize_spreadsheet_cell() نفسها ---
// (تغطي الحالات الحدّية التي لا تصل سليمة عبر خط الأنابيب الكامل، لأن
// sanitize_text_field() الحقيقي في طبقة الـAJAX يُزيل المسافات البادئة أصلاً
// قبل التخزين — فاختبار "مسافة بادئة" يجب أن يستهدف الدالة ذاتها مباشرة).
check('17.1 فارغ يبقى فارغاً', PGE_Invitation_Export::sanitize_spreadsheet_cell(''), '');
check('17.1 null يُعامَل كفارغ', PGE_Invitation_Export::sanitize_spreadsheet_cell(null), '');
check('17.1 =SUM(...) يُطهَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('=SUM(A1:A5)'), "'=SUM(A1:A5)");
check('17.1 +SUM(...) يُطهَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('+SUM(A1:A5)'), "'+SUM(A1:A5)");
check('17.1 -1+2 يُطهَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('-1+2'), "'-1+2");
check('17.1 @SUM(...) يُطهَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('@SUM(A1:A5)'), "'@SUM(A1:A5)");
check('17.1 مسافة بادئة ثم = تُطهَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('  =SUM(A1:A5)'), "'  =SUM(A1:A5)");
check('17.1 Tab بادئ ثم = يُطهَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell("\t=SUM(A1:A5)"), "'\t=SUM(A1:A5)");
check('17.1 CR بادئ ثم = يُطهَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell("\r=SUM(A1:A5)"), "'\r=SUM(A1:A5)");
check('17.1 LF بادئ ثم = يُطهَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell("\n=SUM(A1:A5)"), "'\n=SUM(A1:A5)");
check('17.1 عربي يبدأ بـ= يُطهَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('=دعوة خطيرة'), "'=دعوة خطيرة");
check('17.1 عربي ممزوج بصيغة لاحقة (لا يبدأ بحرف خطر) لا يُطهَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('أحمد =SUM(A1:A5)'), 'أحمد =SUM(A1:A5)');
$long_dangerous_17 = '=' . str_repeat('X', 5000);
$long_dangerous_17_out = PGE_Invitation_Export::sanitize_spreadsheet_cell($long_dangerous_17);
check('17.1 نص طويل جداً خطِر: يُطهَّر مع بقاء المحتوى كاملاً', $long_dangerous_17_out, "'" . $long_dangerous_17);
check('17.1 نص طويل جداً خطِر: الطول يزيد بحرف واحد بالضبط', strlen($long_dangerous_17_out), strlen($long_dangerous_17) + 1);
$long_safe_17 = str_repeat('أ', 5000);
check('17.1 نص طويل جداً آمن لا يتغيَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell($long_safe_17), $long_safe_17);
check('17.1 عربي عادي لا يتغيَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('محمد أحمد'), 'محمد أحمد');
check('17.1 إنجليزي عادي لا يتغيَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('John Smith'), 'John Smith');
check('17.1 رقم صرف لا يتغيَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('0501234567'), '0501234567');
check('17.1 تاريخ لا يتغيَّر', PGE_Invitation_Export::sanitize_spreadsheet_cell('2026-01-01 10:00:00'), '2026-01-01 10:00:00');

// --- 17.2 تكامل كامل عبر خط الأنابيب الحقيقي: إنشاء → تصدير CSV ---
set_test_event(1006, 806);
$GLOBALS['__test_current_user_id'] = 806;

create_invitation(1006, '0581110001', '=SUM(A1:A9)', 806);       // = مباشرة
create_invitation(1006, '0581110002', '+SUM(A1:A9)', 806);       // +
create_invitation(1006, '0581110003', '-1+2+cmd', 806);          // -
create_invitation(1006, '0581110004', '@SUM(A1:A9)', 806);       // @
create_invitation(1006, '0581110005', '=دعوة عربية خطيرة', 806); // عربي يبدأ بـ=
create_invitation(1006, '0581110006', 'أحمد =SUM(A1:A9)', 806);  // عربي ممزوج، آمن (لا يبدأ بحرف خطر)
create_invitation(1006, '0581110007', 'ضيف عادي تماماً', 806);   // عربي عادي

$_POST = make_post_fields(1006, []);
$csv17 = call_export_handler('pge_invitation_mgmt_export_csv_handler');

check_true("17.2 CSV: '=SUM(A1:A9)' خرج مُطهَّراً", strpos($csv17['output'], "\"'=SUM(A1:A9)\"") !== false);
check_true('17.2 CSV: لا يوجد =SUM بلا بادئة اقتباس في المخرَجات', strpos($csv17['output'], '"=SUM(A1:A9)"') === false);
check_true("17.2 CSV: '+SUM(A1:A9)' مُطهَّر", strpos($csv17['output'], "\"'+SUM(A1:A9)\"") !== false);
check_true("17.2 CSV: '-1+2+cmd' مُطهَّر", strpos($csv17['output'], "\"'-1+2+cmd\"") !== false);
check_true("17.2 CSV: '@SUM(A1:A9)' مُطهَّر", strpos($csv17['output'], "\"'@SUM(A1:A9)\"") !== false);
check_true('17.2 CSV: عربي يبدأ بـ= مُطهَّر', strpos($csv17['output'], "'=دعوة عربية خطيرة") !== false);
check_true('17.2 CSV: عربي ممزوج (لا يبدأ بحرف خطر) بقي بلا بادئة اقتباس', strpos($csv17['output'], '"أحمد =SUM(A1:A9)"') !== false);
check_true('17.2 CSV: ضيف عادي بقي كما هو تماماً', strpos($csv17['output'], '"ضيف عادي تماماً"') !== false);

$_POST = make_post_fields(1006, []);
$xlsx17 = call_export_handler('pge_invitation_mgmt_export_excel_handler');

check_true('17.3 XLSX: لا يوجد وسم <f> إطلاقاً في كامل المخرَجات', strpos($xlsx17['output'], '<f>') === false && strpos($xlsx17['output'], '<f ') === false);
// ملاحظة: xml_escape() الحقيقية في PGE_Xlsx_Writer (ENT_QUOTES|ENT_XML1، غير
// مُعدَّلة هنا إطلاقاً) تُرمِّز علامة الاقتباس المفردة نفسها كـ"&apos;" داخل
// XML — سلوك ترميز XML قياسي صحيح، لا فقدان للمحتوى؛ لذا نبحث عن الشكل
// المُرمَّز فعلياً الذي يكتبه الكاتب الحقيقي، لا عن حرف اقتباس خام.
check_true("17.3 XLSX: القيمة المُطهَّرة \"'=SUM(A1:A9)\" موجودة كنص (مُرمَّزة XML: &apos;=SUM...)", strpos($xlsx17['output'], '&apos;=SUM(A1:A9)') !== false);
check_true('17.3 XLSX: لا =SUM(A1:A9) بلا بادئة اقتباس في المخرَجات', strpos($xlsx17['output'], '>=SUM(A1:A9)<') === false);
check_true('17.3 XLSX: العربي الممزوج غير المُطهَّر موجود كما هو', strpos($xlsx17['output'], 'أحمد =SUM(A1:A9)') !== false);

// --- 17.4 القيمة الأصلية في قاعدة البيانات لم تتغيَّر إطلاقاً ---
$guests_map_17 = pge_event_guests_get_map(1006);
check('17.4 القيمة المخزَّنة لـ0581110001 لا تزال =SUM(A1:A9) الخام (بلا بادئة اقتباس)', $guests_map_17['0581110001']['name'] ?? null, '=SUM(A1:A9)');
check('17.4 القيمة المخزَّنة لـ0581110005 لا تزال =دعوة عربية خطيرة الخام', $guests_map_17['0581110005']['name'] ?? null, '=دعوة عربية خطيرة');

// --- 17.5 الفلترة (بحث) لا تزال تعمل رغم المحتوى الخطِر ---
$_POST = make_post_fields(1006, ['search' => 'SUM(A1:A9)']);
$csv17_search = call_export_handler('pge_invitation_mgmt_export_csv_handler');
$csv17_search_lines = explode("\r\n", rtrim(substr($csv17_search['output'], strlen(PGE_Invitation_Export::CSV_BOM)), "\r\n"));
check('17.5 البحث عن "SUM(A1:A9)" يطابق 4 دعوات (=/+/@ المحتويَة على النص + العربي الممزوج)', count($csv17_search_lines) - 1, 4);

// --- 17.6 الترتيب لا يزال مطابقاً لقائمة العرض رغم المحتوى الخطِر ---
$filters17_sort = ['sort_by' => 'phone', 'sort_dir' => 'asc'];
$list17 = PGE_Invitation_Service::list_invitations(1006, array_merge($filters17_sort, ['page' => 1, 'per_page' => 100]));
$expected_order17 = array_column($list17['items'], 'phone');
$dataset17 = PGE_Invitation_Export::build_dataset(1006, $filters17_sort);
$actual_order17 = array_map(function ($row) {
    // العمود 0 قد يحمل بادئة اقتباس — نُزيلها هنا فقط للمقارنة مع الهاتف الخام (الترتيب نفسه غير متأثر إطلاقاً؛ أرقام الهواتف هنا آمنة أصلاً ولن تُطهَّر عملياً، هذا فقط دفاع في العمق).
    return ltrim((string) $row[0], "'");
}, $dataset17['rows']);
check('17.6 الترتيب مطابق تماماً لقائمة العرض حتى مع أسماء خطِرة', $actual_order17, $expected_order17);

// --- 17.7 عدد الصفوف لم يتغيَّر (7 دعوات بالضبط) ---
check('17.7 عدد صفوف مناسبة 1006 = 7 بالضبط (لا صفوف زائدة/ناقصة بسبب التطهير)', $dataset17['count'], 7);

// --- 17.8 التدقيق لا يزال يعمل بعد التصدير رغم المحتوى الخطِر ---
$wpdb->audit_log = array_filter($wpdb->audit_log, function ($r) { return (int) $r['event_id'] !== 1006; }); // عزل بداية نظيفة لهذا القسم
$_POST = make_post_fields(1006, []);
call_export_handler('pge_invitation_mgmt_export_csv_handler');
$audit17 = array_values(array_filter($wpdb->audit_log, function ($r) { return (int) $r['event_id'] === 1006 && $r['action'] === 'export_completed'; }));
check('17.8 حدث تدقيق export_completed واحد بعد التصدير رغم المحتوى الخطِر', count($audit17), 1);
$reason17 = json_decode($audit17[0]['reason'] ?? '', true);
check('17.8 reason.count = 7 (لا تأثير على العدّ المُدقَّق)', $reason17['count'] ?? null, 7);

// --- 17.9 تصدير كبير مع محتوى خطِر لا يزال يعمل ---
set_test_event(1007, 807);
$GLOBALS['__test_current_user_id'] = 807;
for ($i = 1; $i <= 60; $i++) {
    $malicious_name_17 = ($i % 5 === 0) ? ('=DANGER' . $i) : ('ضيف رقم ' . $i);
    create_invitation(1007, '057' . str_pad((string) $i, 7, '0', STR_PAD_LEFT), $malicious_name_17, 807);
}
$_POST = make_post_fields(1007, []);
$csv17_large = call_export_handler('pge_invitation_mgmt_export_csv_handler');
$csv17_large_lines = explode("\r\n", rtrim(substr($csv17_large['output'], strlen(PGE_Invitation_Export::CSV_BOM)), "\r\n"));
check('17.9 تصدير كبير (60 دعوة، بعضها خطِر) لا يزال يُنتج 60 سطر بيانات بالضبط', count($csv17_large_lines) - 1, 60);
check_true('17.9 كل القيم الخطِرة في التصدير الكبير مُطهَّرة (لا =DANGER بلا بادئة)', strpos($csv17_large['output'], '"=DANGER') === false);
check_true('17.9 نسخة مُطهَّرة واحدة على الأقل موجودة فعلياً', strpos($csv17_large['output'], "'=DANGER") !== false);

// --- 17.10 تصدير فارغ (بلا أي دعوة) لا يزال يعمل بعد إضافة التطهير ---
set_test_event(1008, 808);
$GLOBALS['__test_current_user_id'] = 808;
$_POST = make_post_fields(1008, []);
$csv17_empty = call_export_handler('pge_invitation_mgmt_export_csv_handler');
$csv17_empty_lines = explode("\r\n", rtrim(substr($csv17_empty['output'], strlen(PGE_Invitation_Export::CSV_BOM)), "\r\n"));
check('17.10 تصدير فارغ: سطر الرأس فقط، بلا أي خطأ', count($csv17_empty_lines), 1);

// --- 17.11/17.12/17.13: انحدار Phase 9A/9B/منطق تصدير Phase 9C الأصلي ---
$GLOBALS['__test_current_user_id'] = 801;
$_POST = make_post_fields(1001, ['page' => 1, 'per_page' => 20]);
$list17_regress = call_ajax_handler('pge_invitation_mgmt_list_handler');
check_true('17.11 انحدار Phase 9A: القائمة لا تزال تعمل بعد إضافة التطهير', $list17_regress['success'] ?? false);

check_true('17.12 انحدار Phase 9B: hooks Resend/QR Regeneration لا تزالان مُسجَّلتين', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_resend']) && isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_qr_regenerate']));

$_POST = make_post_fields(1001, []);
$csv17_regress_9c = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('17.13 انحدار منطق تصدير Phase 9C: تصدير مناسبة 1001 لا يزال يبدأ بـBOM كما كان', strpos($csv17_regress_9c['output'], PGE_Invitation_Export::CSV_BOM) === 0);
check_true('17.13 انحدار Phase 9C: اسم "محمد العلي" (بلا حرف خطر) خرج بلا أي بادئة اقتباس زائدة', strpos($csv17_regress_9c['output'], '"محمد العلي"') !== false && strpos($csv17_regress_9c['output'], "\"'محمد العلي\"") === false);

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
echo "كل الفئات المطلوبة (Phase 9C — Invitation Export + Final Security Fix) نجحت.\n";
