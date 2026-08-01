<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـEntry Check-in Supervisors، Phase 10
 * ("Event Operations" RFC) — includes/event-operations-ajax.php، الإضافة
 * التوافقية الخلفية على PGE_Attendance_Dashboard_Provider::get_dashboard()،
 * وقالب templates/event-operations.php (فحص بنيوي على الـHTML/JS المُصدَّر).
 *
 * يستدعي الأنبوب الحقيقي الكامل بلا أي مرآة منطقية: pge_event_ops_dashboard_
 * handler()/pge_event_ops_search_handler() الحقيقيتان → PGE_Attendance_
 * Dashboard_Provider::get_dashboard() الحقيقية → PGE_Attendance_Statistics_
 * Service الحقيقية (غير مُعدَّلة) + PGE_Invitation_Service/PGE_Invitation_
 * Repository الحقيقيتان (غير مُعدَّلتين) + PGE_Supervisor_Assignment_Service
 * الحقيقية (غير مُعدَّلة). "Do NOT create logical mirrors of production code.
 * Execute the real activation code."
 *
 * السيناريوهات المطلوبة صراحةً: تحميل اللوحة (Dashboard load)، التفويض
 * (Authorization)، عزل المناسبات (Event isolation)، الإحصاء الحيّ (Live
 * statistics)، النشاط الأخير (Recent activity — بما فيها التوافق الخلفي مع
 * استدعاء get_dashboard() بمعامل واحد)، تكامل البحث (Search integration)،
 * حالة المشرفين (Supervisor status)، مجموعات بيانات كبيرة (Large datasets)،
 * مناسبة فارغة (Empty event)، وعدم تسجيل أي تدقيق عند مجرَّد العرض (Audit
 * boundary)، بالإضافة لفحص بنيوي لقسمَي الاستطلاع الدوري (15 ثانية) والبحث
 * في الـJS المُصدَّر فعلياً من templates/event-operations.php.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: node /tmp/phpcheck/runany.mjs tests/test-event-operations.php
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
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) { return preg_replace('/\D+/', '', (string) $v); }
}
// بيئة الاختبار (php-wasm) لا تُحمِّل امتداد mbstring (فجوة بيئية مُوثَّقة سابقاً
// في test-invitation-management.php/test-replacement-entitlement-grant.php) —
// PGE_Invitation_Repository::list_invitations() يستخدم mb_strtolower()/mb_strpos()
// لبحث غير حسّاس لحالة الأحرف. شيم بيئي بحت (لا منطق عمل)، ليس مرآة منطقية:
// strtolower()/strpos() العاديتان كافيتان تماماً لبيانات هذا الاختبار (عربية،
// أو أرقام/ASCII)، وكل استخدام لـmb_strpos() هنا يتحقق فقط من (!== false).
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) { return strtolower((string) $string); }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) { return strpos((string) $haystack, (string) $needle, $offset); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('wp_generate_uuid4')) {
    $GLOBALS['__test_uuid_counter'] = 0;
    function wp_generate_uuid4() {
        $GLOBALS['__test_uuid_counter']++;
        return 'test-uuid-' . $GLOBALS['__test_uuid_counter'];
    }
}
$GLOBALS['__test_now_override'] = '2026-01-01 00:00:00';
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now_override']; }
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

// ── تفويض/جلسة — نفس أسلوب test-supervisor-management.php حرفياً ───────────
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null) {
    if ($cap === 'administrator') {
        return $GLOBALS['__test_user_is_admin'];
    }
    return false;
}

// ── Posts + Post Meta وهمية ─────────────────────────────────────────────────
$GLOBALS['__test_posts'] = [];
$GLOBALS['__test_post_meta'] = [];
$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_userdata'] = [];

function set_test_event($event_id, $author_id, $title = '', $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) [
        'ID' => $event_id, 'post_type' => $post_type, 'post_author' => $author_id, 'post_title' => $title,
    ];
    if (!isset($GLOBALS['__test_post_meta'][$event_id])) {
        $GLOBALS['__test_post_meta'][$event_id] = [];
    }
}
function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
function get_post_field($field, $post_id) { $p = get_post($post_id); return $p ? ($p->{$field} ?? '') : ''; }
function get_the_title($post_id) { $p = get_post($post_id); return $p ? (string) $p->post_title : ''; }
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
function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function get_userdata($user_id) { return $GLOBALS['__test_userdata'][$user_id] ?? false; }

// ── Stubs AJAX/JSON ──────────────────────────────────────────────────────────
if (!class_exists('Test_Wp_Die_Exception')) {
    class Test_Wp_Die_Exception extends \Exception {}
}
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function language_attributes() { echo 'lang="ar"'; }
function bloginfo($k) { echo $k === 'charset' ? 'UTF-8' : ''; }
function wp_head() {}
function wp_footer() {}
function get_header() {}
function get_footer() {}
function auth_redirect() { throw new Test_Wp_Die_Exception('auth_redirect'); }
function wp_safe_redirect($url) { $GLOBALS['__test_last_redirect'] = $url; throw new Test_Wp_Die_Exception('redirect'); }
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function locate_template($name) { return ''; }

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
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}
function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}
function post_with(array $fields, callable $fn)
{
    $prev = $_POST;
    $_POST = $fields;
    try {
        return $fn();
    } finally {
        $_POST = $prev;
    }
}

// ============================================================================
// Fake $wpdb — pge_event_rsvps + pge_checkin_audit_log + mon_event_supervisors
// + pge_invitation_mgmt_audit_log (لإثبات حدود التدقيق فقط) + GET_LOCK.
// ============================================================================
class Fake_Wpdb_Event_Ops
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $rsvps = [];
    public $audit_log = [];       // pge_checkin_audit_log
    public $supervisors = [];     // mon_event_supervisors
    public $mgmt_audit_log = [];  // pge_invitation_mgmt_audit_log (حدود التدقيق فقط)

    private $rsvps_next_id = 1;
    private $audit_next_id = 1;
    private $supervisors_next_id = 1;
    private $mgmt_audit_next_id = 1;

    public $held_locks = [];

    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? ''; $i++;
            return $m[0] === '%d' ? (string) (int) $val : "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    private function which_table($sql_or_table)
    {
        if (strpos($sql_or_table, $this->prefix . 'pge_checkin_audit_log') !== false) return 'audit_log';
        if (strpos($sql_or_table, $this->prefix . 'pge_event_rsvps') !== false) return 'rsvps';
        if (strpos($sql_or_table, $this->prefix . 'mon_event_supervisors') !== false) return 'supervisors';
        if (strpos($sql_or_table, $this->prefix . 'pge_invitation_mgmt_audit_log') !== false) return 'mgmt_audit';
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $which = $this->which_table($sql);

        if ($which === 'rsvps' && stripos($sql, 'total_invitations') !== false) {
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $m);
            $event_id = isset($m[1]) ? (int) $m[1] : 0;
            $total = 0; $checked_in = 0; $expected = 0; $actual = 0;
            foreach ($this->rsvps as $row) {
                if ((int) $row['event_id'] !== $event_id) continue;
                $total++;
                $companions = (int) ($row['companions'] ?? 0);
                $expected += 1 + $companions;
                if ((int) ($row['checked_in'] ?? 0) === 1) {
                    $checked_in++;
                    $actual += (int) ($row['actual_entered_count'] ?? 0);
                }
            }
            return [
                'total_invitations' => $total,
                'checked_in_invitations' => $checked_in,
                'expected_guests' => $expected,
                'actual_attendees' => $actual,
            ];
        }

        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);

        // event-guests.php: SELECT guest_phone, reply, checked_in FROM ... WHERE event_id = %d
        if ($which === 'rsvps' && stripos($sql, 'guest_phone, reply, checked_in') !== false) {
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $m);
            $event_id = isset($m[1]) ? (int) $m[1] : 0;
            $rows = [];
            foreach ($this->rsvps as $row) {
                if ((int) $row['event_id'] === $event_id) {
                    $rows[] = ['guest_phone' => $row['guest_phone'], 'reply' => $row['reply'], 'checked_in' => $row['checked_in']];
                }
            }
            return $rows;
        }

        // Attendance Statistics: دفعة RSVP بمعرّفات (IN (...)).
        if ($which === 'rsvps' && preg_match('/\bIN\s*\(([^)]*)\)/i', $sql, $m)) {
            $ids = array_map('intval', array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
            $matched = [];
            foreach ($ids as $id) {
                if (isset($this->rsvps[$id])) $matched[] = $this->rsvps[$id];
            }
            return $matched;
        }

        // آخر عمليات تسجيل حضور (audit_log، محدود، مُرتَّب).
        if ($which === 'audit_log' && stripos($sql, 'ORDER BY created_at DESC') !== false) {
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $me);
            preg_match('/LIMIT\s+(\d+)/i', $sql, $ml);
            $event_id = isset($me[1]) ? (int) $me[1] : 0;
            $limit = isset($ml[1]) ? (int) $ml[1] : 10;
            $matched = array_values(array_filter($this->audit_log, function ($r) use ($event_id) { return (int) $r['event_id'] === $event_id; }));
            usort($matched, function ($a, $b) {
                $cmp = strcmp((string) $b['created_at'], (string) $a['created_at']);
                return $cmp !== 0 ? $cmp : ($b['id'] <=> $a['id']);
            });
            return array_slice($matched, 0, $limit);
        }

        // تجميع Supervisor Summary (GROUP BY assignment_id).
        if ($which === 'audit_log' && stripos($sql, 'GROUP BY assignment_id') !== false) {
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $m);
            $event_id = isset($m[1]) ? (int) $m[1] : 0;
            $grouped = [];
            foreach ($this->audit_log as $row) {
                if ((int) $row['event_id'] !== $event_id) continue;
                $aid = (int) $row['assignment_id'];
                if (!isset($grouped[$aid])) $grouped[$aid] = ['assignment_id' => $aid, 'checkins_recorded' => 0, 'guests_recorded' => 0];
                $grouped[$aid]['checkins_recorded']++;
                $grouped[$aid]['guests_recorded'] += (int) $row['actual_count'];
            }
            return array_values($grouped);
        }

        if ($which === 'supervisors') {
            // list_assignments_for_event_page (مُرقَّمة + بحث): "ORDER BY id DESC".
            if (stripos($sql, 'ORDER BY id DESC') !== false) {
                if (!preg_match('/event_id\s*=\s*(\d+)/i', $sql, $mEvent)) return [];
                $event_id = (int) $mEvent[1];
                $rows = array_values(array_filter($this->supervisors, function ($r) use ($event_id) { return (int) $r['event_id'] === $event_id; }));
                $rows = $this->apply_search_filter($sql, $rows);
                usort($rows, function ($a, $b) { return $b['id'] <=> $a['id']; });
                if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $mLim)) {
                    $rows = array_slice($rows, (int) $mLim[2], (int) $mLim[1]);
                }
                return $rows;
            }
            // list_assignments_for_event() القديمة (Phase 5): "ORDER BY id ASC".
            if (stripos($sql, 'ORDER BY id ASC') !== false && preg_match('/event_id\s*=\s*(\d+)/i', $sql, $mEvent2)) {
                $event_id = (int) $mEvent2[1];
                $rows = array_values(array_filter($this->supervisors, function ($r) use ($event_id) { return (int) $r['event_id'] === $event_id; }));
                usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
                return array_map(function ($r) {
                    return ['id' => $r['id'], 'supervisor_phone' => $r['supervisor_phone'], 'supervisor_name' => $r['supervisor_name'], 'status' => $r['status']];
                }, $rows);
            }
            return [];
        }

        return [];
    }

    private function apply_search_filter($sql, array $rows): array
    {
        if (preg_match("/supervisor_name\s+LIKE\s+'([^']*)'\s+OR\s+supervisor_phone\s+LIKE\s+'([^']*)'/i", $sql, $m)) {
            $name_like = trim($m[1], '%'); $phone_like = trim($m[2], '%');
            return array_values(array_filter($rows, function ($r) use ($name_like, $phone_like) {
                return (stripos((string) $r['supervisor_name'], $name_like) !== false) || (strpos((string) $r['supervisor_phone'], $phone_like) !== false);
            }));
        }
        if (preg_match("/AND\s+supervisor_name\s+LIKE\s+'([^']*)'/i", $sql, $m)) {
            $name_like = trim($m[1], '%');
            return array_values(array_filter($rows, function ($r) use ($name_like) { return stripos((string) $r['supervisor_name'], $name_like) !== false; }));
        }
        return $rows;
    }

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $this->held_locks[$m[1]] = true;
            return 1;
        }
        $which = $this->which_table($sql);
        if ($which !== 'supervisors') return null;

        if (stripos($sql, 'SELECT COUNT(*)') !== false && preg_match('/event_id\s*=\s*(\d+)/i', $sql, $mEvent)) {
            $event_id = (int) $mEvent[1];
            $rows = array_values(array_filter($this->supervisors, function ($r) use ($event_id) { return (int) $r['event_id'] === $event_id; }));
            $rows = $this->apply_search_filter($sql, $rows);
            return (string) count($rows);
        }
        return null;
    }

    public function query($sql) { return false; }

    public function insert($table, $data, $format = null)
    {
        $which = $this->which_table($table);
        if ($which === 'rsvps') {
            $id = $this->rsvps_next_id++;
            $defaults = ['checked_in' => 0, 'actual_entered_count' => null, 'companions' => 0, 'reply' => 'pending'];
            $this->rsvps[$id] = array_merge(['id' => $id], $defaults, $data);
            $this->insert_id = $id;
            return 1;
        }
        if ($which === 'audit_log') {
            $id = $this->audit_next_id++;
            $this->audit_log[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        if ($which === 'supervisors') {
            $id = $this->supervisors_next_id++;
            $this->supervisors[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        if ($which === 'mgmt_audit') {
            $id = $this->mgmt_audit_next_id++;
            $this->mgmt_audit_log[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        return false;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which === 'supervisors') {
            $id = $where['id'] ?? null;
            if ($id === null || !isset($this->supervisors[$id])) return 0;
            foreach ($data as $k => $v) $this->supervisors[$id][$k] = $v;
            return 1;
        }
        return false;
    }

    /** تمهيد RSVP مباشر (قراءة فقط — لا يختبر مسار الكتابة). */
    public function seed_rsvp($event_id, $phone, array $extra = [])
    {
        $id = $this->rsvps_next_id++;
        $defaults = [
            'event_id' => $event_id, 'guest_phone' => $phone, 'guest_name' => null,
            'companions' => 0, 'note' => null, 'reply' => 'pending', 'checked_in' => 0,
            'checked_in_at' => null, 'checked_in_by_assignment_id' => null,
            'checkin_method' => null, 'actual_entered_count' => null,
        ];
        $this->rsvps[$id] = array_merge(['id' => $id], $defaults, $extra);
        return $id;
    }

    /** تمهيد سطر تدقيق حضور مباشر (قراءة فقط). */
    public function seed_audit_row($event_id, $rsvp_id, $assignment_id, $method, $expected_count, $actual_count, $created_at)
    {
        $id = $this->audit_next_id++;
        $this->audit_log[$id] = [
            'id' => $id, 'event_id' => $event_id, 'rsvp_id' => $rsvp_id, 'assignment_id' => $assignment_id,
            'method' => $method, 'expected_count' => $expected_count, 'actual_count' => $actual_count,
            'entry_type' => 'confirmation', 'created_at' => $created_at,
        ];
        return $id;
    }

    /** تمهيد إسناد مشرف مباشر (قراءة فقط). */
    public function seed_supervisor($event_id, $phone, $name, $status, $updated_at)
    {
        $id = $this->supervisors_next_id++;
        $this->supervisors[$id] = [
            'id' => $id, 'event_id' => $event_id, 'supervisor_phone' => $phone, 'supervisor_name' => $name,
            'status' => $status, 'invited_at' => $updated_at, 'accepted_at' => $updated_at, 'revoked_at' => null,
            'updated_at' => $updated_at, 'invitation_token_hash' => 'hash-' . $id,
        ];
        return $id;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Event_Ops();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-attendance-statistics-service.php';
require_once __DIR__ . '/../includes/class-pge-attendance-dashboard-provider.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/supervisor-management-ajax.php';
require_once __DIR__ . '/../includes/invitation-management-ajax.php';
require_once __DIR__ . '/../includes/event-operations-ajax.php';

$total = 0; $passed = 0; $failures = [];
function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS  $label\n"; }
    else { $failures[] = $label; echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}
function check_true($label, $cond) { check($label, (bool) $cond, true); }

function seed_invitation($event_id, $phone, $name, $note = '')
{
    global $wpdb;
    $map = $GLOBALS['__test_post_meta'][$event_id]['_pge_invited_guests'] ?? [];
    $norm = pge_norm_phone($phone);
    $map[$norm] = ['phone' => $norm, 'name' => $name, 'note' => $note, 'code' => strtoupper(substr(md5($norm), 0, 4)) . '-' . strtoupper(substr(md5($norm . 'x'), 0, 4))];
    $GLOBALS['__test_post_meta'][$event_id]['_pge_invited_guests'] = $map;
    return $norm;
}

// ============================================================================
// السيناريو 1: تحميل اللوحة (Dashboard load) — مضيف مخوَّل
// ============================================================================
echo "=== السيناريو 1: تحميل اللوحة (مضيف مخوَّل) ===\n";

$GLOBALS['__test_current_user_id'] = 501;
$GLOBALS['__test_user_is_admin'] = false;
set_test_event(9001, 501, 'حفلة اختبار 1');

seed_invitation(9001, '966500000001', 'ضيف واحد');
seed_invitation(9001, '966500000002', 'ضيف اثنان');
seed_invitation(9001, '966500000003', 'ضيف ثلاثة');

$wpdb->seed_rsvp(9001, '966500000001', ['reply' => 'yes', 'checked_in' => 1, 'checked_in_at' => '2026-01-01 10:00:00', 'actual_entered_count' => 2]);
$wpdb->seed_rsvp(9001, '966500000002', ['reply' => 'yes']);
$wpdb->seed_rsvp(9001, '966500000003', ['reply' => 'no']);

$sup1 = $wpdb->seed_supervisor(9001, '966511111111', 'مشرف أول', 'active', '2026-01-01 09:00:00');
$wpdb->seed_audit_row(9001, 1, $sup1, 'manual', 3, 2, '2026-01-01 10:00:00');

$result1 = call_ajax_handler(function () {
    post_with(make_post_fields(9001), 'pge_event_ops_dashboard_handler');
});
check_true('1.1 نجاح الاستجابة', $result1['success']);
check('1.2 إجمالي الدعوات = 3', (int) $result1['data']['attendance_summary']['total_invitations'], 3);
check('1.3 تم تسجيل حضورها = 1', (int) $result1['data']['attendance_summary']['checked_in_invitations'], 1);
check('1.4 دعوات مُلغاة = 0 (لا شيء أُلغي بعد)', (int) $result1['data']['attendance_summary']['cancelled_invitations'], 0);
check('1.5 عدد صفوف آخر الحضور = 1', count($result1['data']['recent_checkins']), 1);
check('1.6 عدد المشرفين = 1', count($result1['data']['supervisor_summary']), 1);
check('1.7 آخر نشاط المشرف مأخوذ من updated_at الفعلي', $result1['data']['supervisor_summary'][0]['last_activity'], '2026-01-01 09:00:00');
check_true('1.8 dashboard_metadata.generated_at موجود', isset($result1['data']['dashboard_metadata']['generated_at']) && $result1['data']['dashboard_metadata']['generated_at'] !== '');

// ============================================================================
// السيناريو 2: التفويض (Authorization) — مستخدم غير مخوَّل
// ============================================================================
echo "\n=== السيناريو 2: مستخدم غير مخوَّل ===\n";

$GLOBALS['__test_current_user_id'] = 999;
$result2 = call_ajax_handler(function () {
    post_with(make_post_fields(9001), 'pge_event_ops_dashboard_handler');
});
check_true('2.1 رفض للمستخدم غير المخوَّل', !$result2['success']);
check('2.2 السبب forbidden', $result2['data']['reason'], 'forbidden');

$GLOBALS['__test_logged_in'] = false;
$result2b = call_ajax_handler(function () {
    post_with(make_post_fields(9001), 'pge_event_ops_dashboard_handler');
});
check_true('2.3 رفض لغير مسجَّل الدخول', !$result2b['success']);
check('2.4 السبب not_logged_in', $result2b['data']['reason'], 'not_logged_in');
$GLOBALS['__test_logged_in'] = true;
$GLOBALS['__test_current_user_id'] = 501;

// ============================================================================
// السيناريو 3: عزل المناسبات (Event isolation)
// ============================================================================
echo "\n=== السيناريو 3: عزل المناسبات ===\n";

$GLOBALS['__test_current_user_id'] = 777;
set_test_event(9002, 777, 'حفلة اختبار 2 (مضيف آخر)');
seed_invitation(9002, '966522222222', 'ضيف مناسبة أخرى');
$wpdb->seed_rsvp(9002, '966522222222', ['reply' => 'yes', 'checked_in' => 1, 'actual_entered_count' => 1, 'checked_in_at' => '2026-01-01 11:00:00']);

$result3a = call_ajax_handler(function () { post_with(make_post_fields(9002), 'pge_event_ops_dashboard_handler'); });
check_true('3.1 نجاح لمضيف المناسبة 9002 نفسه', $result3a['success']);
check('3.2 إجمالي دعوات 9002 = 1 (لا اختلاط مع 9001)', (int) $result3a['data']['attendance_summary']['total_invitations'], 1);

$GLOBALS['__test_current_user_id'] = 501; // مضيف 9001 يحاول الوصول لمناسبة 9002
$result3b = call_ajax_handler(function () { post_with(make_post_fields(9002), 'pge_event_ops_dashboard_handler'); });
check_true('3.3 رفض مضيف 9001 لمناسبة 9002', !$result3b['success']);
check('3.4 السبب forbidden (عزل صحيح)', $result3b['data']['reason'], 'forbidden');

// ============================================================================
// السيناريو 4: الإحصاء الحيّ — عدّاد الملغاة الفعلي عبر Invitation Repository
// ============================================================================
echo "\n=== السيناريو 4: عدّاد الدعوات المُلغاة ===\n";

$GLOBALS['__test_current_user_id'] = 501;
$cancel_result = PGE_Invitation_Repository::cancel(9001, '966500000003', 'تجربة إلغاء');
check('4.1 إلغاء ناجح (تمهيد)', $cancel_result['result'], 'cancelled');

$result4 = call_ajax_handler(function () { post_with(make_post_fields(9001), 'pge_event_ops_dashboard_handler'); });
check('4.2 دعوات مُلغاة = 1 بعد الإلغاء', (int) $result4['data']['attendance_summary']['cancelled_invitations'], 1);
check('4.3 إجمالي الدعوات ما زال 3 (الإلغاء لا يحذف)', (int) $result4['data']['attendance_summary']['total_invitations'], 3);

// ============================================================================
// السيناريو 5: النشاط الأخير — عدد قابل للتهيئة (افتراضه 20) + توافق خلفي
// ============================================================================
echo "\n=== السيناريو 5: النشاط الأخير (حدّ قابل للتهيئة) ===\n";

set_test_event(9003, 501, 'حفلة اختبار كبيرة');
for ($i = 1; $i <= 25; $i++) {
    $phone = '96653' . str_pad((string) $i, 7, '0', STR_PAD_LEFT);
    seed_invitation(9003, $phone, 'ضيف رقم ' . $i);
    $rsvp_id = $wpdb->seed_rsvp(9003, $phone, ['reply' => 'yes', 'checked_in' => 1, 'actual_entered_count' => 1, 'checked_in_at' => sprintf('2026-01-01 %02d:00:00', $i % 24)]);
    $wpdb->seed_audit_row(9003, $rsvp_id, $sup1, 'qr', 1, 1, sprintf('2026-01-01 %02d:00:00', $i % 24));
}

$result5a = call_ajax_handler(function () { post_with(make_post_fields(9003, ['recent_limit' => 5]), 'pge_event_ops_dashboard_handler'); });
check('5.1 recent_limit=5 يُحترَم', count($result5a['data']['recent_checkins']), 5);

$result5b = call_ajax_handler(function () { post_with(make_post_fields(9003), 'pge_event_ops_dashboard_handler'); });
check('5.2 الافتراض 20 عند غياب recent_limit', count($result5b['data']['recent_checkins']), 20);

// توافق خلفي: استدعاء get_dashboard() المباشر بمعامل واحد فقط يبقي الافتراض 10
// (Zero Behavior Change لكل مستهلك حالي — dashboard-ajax.php/Phase 6).
$direct_dashboard = PGE_Attendance_Dashboard_Provider::get_dashboard(9003);
check('5.3 توافق خلفي: get_dashboard($id) بمعامل واحد يُعيد 10 كحد أقصى', count($direct_dashboard['data']['recent_checkins']), 10);

// ============================================================================
// السيناريو 6: تكامل البحث السريع الموحَّد (دعوات + مشرفون)
// ============================================================================
echo "\n=== السيناريو 6: البحث السريع الموحَّد ===\n";

$sup2 = $wpdb->seed_supervisor(9001, '966533333333', 'سارة المشرفة', 'active', '2026-01-01 08:00:00');

$result6a = call_ajax_handler(function () { post_with(make_post_fields(9001, ['q' => 'ضيف واحد']), 'pge_event_ops_search_handler'); });
check_true('6.1 نجاح البحث', $result6a['success']);
check('6.2 نتيجة دعوة واحدة مطابقة للاسم', count($result6a['data']['invitations']), 1);
check('6.3 لا نتائج مشرفين لبحث اسم دعوة', count($result6a['data']['supervisors']), 0);

$result6b = call_ajax_handler(function () { post_with(make_post_fields(9001, ['q' => 'سارة']), 'pge_event_ops_search_handler'); });
check('6.4 نتيجة مشرف واحد مطابقة للاسم', count($result6b['data']['supervisors']), 1);
check('6.5 اسم المشرف صحيح', $result6b['data']['supervisors'][0]['name'], 'سارة المشرفة');

// ============================================================================
// السيناريو 7: حالة المشرفين (last_activity من مصدر مُعتمَد فعلاً)
// ============================================================================
echo "\n=== السيناريو 7: حالة المشرفين ===\n";

$result7 = call_ajax_handler(function () { post_with(make_post_fields(9001), 'pge_event_ops_dashboard_handler'); });
$sup_rows = $result7['data']['supervisor_summary'];
$sup1_row = null;
foreach ($sup_rows as $row) { if ((int) $row['assignment_id'] === $sup1) $sup1_row = $row; }
check_true('7.1 صف المشرف الأول موجود', $sup1_row !== null);
check('7.2 حالة المشرف = active (خام، بلا اختراع "متصل الآن")', $sup1_row['status'], 'active');
check('7.3 last_activity = updated_at الحقيقي، لا تتبُّع جديد', $sup1_row['last_activity'], '2026-01-01 09:00:00');

// ============================================================================
// السيناريو 8: مجموعة بيانات كبيرة (لا أخطاء، لا N+1 يظهر كخطأ زمن التنفيذ)
// ============================================================================
echo "\n=== السيناريو 8: مجموعة بيانات كبيرة ===\n";

$result8 = call_ajax_handler(function () { post_with(make_post_fields(9003, ['recent_limit' => 100]), 'pge_event_ops_dashboard_handler'); });
check_true('8.1 نجاح مع 25 سجل حضور وحدّ 100', $result8['success']);
check('8.2 إجمالي دعوات 9003 = 25', (int) $result8['data']['attendance_summary']['total_invitations'], 25);
check('8.3 كل سجلات الحضور الـ25 عادت (أقل من الحدّ 100)', count($result8['data']['recent_checkins']), 25);

// ============================================================================
// السيناريو 9: مناسبة فارغة
// ============================================================================
echo "\n=== السيناريو 9: مناسبة فارغة ===\n";

set_test_event(9004, 501, 'مناسبة فارغة تماماً');
$result9 = call_ajax_handler(function () { post_with(make_post_fields(9004), 'pge_event_ops_dashboard_handler'); });
check_true('9.1 نجاح للمناسبة الفارغة (لا خطأ)', $result9['success']);
check('9.2 إجمالي الدعوات = 0', (int) $result9['data']['attendance_summary']['total_invitations'], 0);
check('9.3 دعوات مُلغاة = 0', (int) $result9['data']['attendance_summary']['cancelled_invitations'], 0);
check('9.4 آخر عمليات الحضور فارغة', $result9['data']['recent_checkins'], []);
check('9.5 ملخّص المشرفين فارغ', $result9['data']['supervisor_summary'], []);

$result9b = call_ajax_handler(function () { post_with(make_post_fields(9004, ['q' => 'لا شيء']), 'pge_event_ops_search_handler'); });
check('9.6 بحث في مناسبة فارغة يُعيد نتائج فارغة بلا خطأ', $result9b['data'], ['invitations' => [], 'supervisors' => []]);

// ============================================================================
// السيناريو 10: حدود التدقيق (Audit Boundary) — العرض لا يُسجِّل، الإجراء يُسجِّل
// ============================================================================
echo "\n=== السيناريو 10: حدود التدقيق ===\n";

$mgmt_audit_count_before = count($wpdb->mgmt_audit_log);
for ($i = 0; $i < 5; $i++) {
    call_ajax_handler(function () { post_with(make_post_fields(9001), 'pge_event_ops_dashboard_handler'); });
    call_ajax_handler(function () { post_with(make_post_fields(9001, ['q' => 'test']), 'pge_event_ops_search_handler'); });
}
check('10.1 خمس دورات عرض/بحث لم تُضِف أي سطر تدقيق', count($wpdb->mgmt_audit_log), $mgmt_audit_count_before);

// تباين: إجراء عمل حقيقي (Service::edit عبر Invitation Service — يُسجِّل تدقيقاً فعلياً).
PGE_Invitation_Service::edit(9001, '966500000001', '966500000001', 'اسم مُحدَّث', '', 501);
check_true('10.2 إجراء عمل حقيقي (تعديل دعوة) يُسجِّل تدقيقاً فعلياً (تباين مع 10.1)', count($wpdb->mgmt_audit_log) > $mgmt_audit_count_before);

// ============================================================================
// السيناريو 11: فحص بنيوي على القالب المُصدَّر (استطلاع 15 ثانية، لا WebSockets/SSE)
// ============================================================================
echo "\n=== السيناريو 11: فحص بنيوي على قالب event-operations.php ===\n";

$template_source = file_get_contents(__DIR__ . '/../templates/event-operations.php');
check_true('11.1 الملف موجود ويُقرَأ', $template_source !== false);
check_true('11.2 فاصل الاستطلاع 15000ms (data-poll-interval-ms)', strpos($template_source, "data-poll-interval-ms=\"<?php echo (int) \$poll_interval_ms; ?>\"") !== false && strpos($template_source, '$poll_interval_ms = 15000;') !== false);
// ملاحظة: لا نبحث عن السلسلة النصية "WebSocket" وحدها لأن تعليقات التوثيق في
// القالب نفسه تذكرها صراحة ضمن شرح "الممنوع" (نفس نص RFC) — الاختبار الحقيقي
// المطلوب هو غياب الاستخدام الفعلي لواجهة WebSocket البرمجية (`new WebSocket(`).
check_true('11.3 لا يوجد استخدام فعلي لواجهة WebSocket البرمجية', strpos($template_source, 'new WebSocket(') === false);
check_true('11.4 لا يوجد EventSource (SSE) في القالب', stripos($template_source, 'EventSource') === false);
check_true('11.5 استخدام setInterval فقط للاستطلاع (لا setTimeout متداخل لمحاكاة SSE)', strpos($template_source, 'setInterval(fetchDashboard') !== false);
check_true('11.6 حارس عدم التداخل موجود (isRefreshingDashboard)', strpos($template_source, 'isRefreshingDashboard') !== false);
check_true('11.7 أقسام ARIA موجودة (aria-labelledby)', substr_count($template_source, 'aria-labelledby') >= 5);
check_true('11.8 حالات تركيز مرئية عبر focus-visible', strpos($template_source, 'focus-visible:ring') !== false);
check_true('11.9 لا إعادة تحميل كاملة (location.reload) في القالب', strpos($template_source, 'location.reload') === false);

// ── ملخّص ────────────────────────────────────────────────────────────────
echo "\n============================================================\n";
echo "الإجمالي: $total | نجح: $passed | فشل: " . count($failures) . "\n";
if ($failures) {
    echo "الاختبارات الفاشلة:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
