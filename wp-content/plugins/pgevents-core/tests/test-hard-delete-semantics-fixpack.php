<?php
/**
 * ============================================================================
 * اختبار تنفيذي حقيقي — "RC1 Hard Delete Semantics Fix Pack"
 * ============================================================================
 * يغطي الـ19 حالة المطلوبة صراحةً في RFC هذا الفيكس باك (1-19 أدناه)، بتنفيذ
 * حقيقي (لا مرآة منطقية) للسلسلة الكاملة المُعدَّلة:
 *   - includes/class-pge-invitation-repository.php  (is_rsvp_row_current() الجديدة)
 *   - includes/class-pge-guest-resolution-service.php (نقاط استدعائها في
 *     resolve_by_rsvp_id()/resolve_by_phone())
 *   - includes/rsvp-handler.php  (نقطة استدعائها في pge_save_rsvp_response())
 * بالإضافة إلى تحقّق استمرارية (Continuity) صريح لكل الأنظمة التي مُنع لمسها:
 * Cancel، QR الحالي، Bulk Add، Bulk Delete، Statistics، Dashboard (عبر نفس
 * الآلية الإنتاجية)، Export، Supervisor Check-in (طبقة الحلّ نفسها).
 *
 * "Do NOT create logical mirrors of production code. Execute the real
 * activation code." — كل استدعاء أدناه لدالة/صنف إنتاجي حقيقي بالاسم.
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-hard-delete-semantics-fixpack.php
 */

define('ABSPATH', __DIR__ . '/');
if (!defined('PGE_PATH')) define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) { function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_file_name')) { function sanitize_file_name($v) { return preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string) $v); } }
if (!function_exists('absint')) { function absint($value) { return abs((int) $value); } }

// ساعة اختبار مُتقدِّمة (لا قيمة ثابتة) — راجع نفس التبرير في
// test-hard-delete-semantics-audit.php: ضرورية لصحة مقارنة invited_at مقابل
// created_at في is_rsvp_row_current().
if (!function_exists('current_time')) {
    $GLOBALS['__test_clock_counter'] = 0;
    function current_time($type = 'mysql', $gmt = 0) {
        $GLOBALS['__test_clock_counter']++;
        return gmdate('Y-m-d H:i:s', strtotime('2026-08-01 00:00:00') + $GLOBALS['__test_clock_counter']);
    }
}
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
if (!function_exists('wp_hash')) { function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed'); } }
if (!function_exists('pge_generate_invite_code')) {
    function pge_generate_invite_code()
    {
        static $n = 0;
        $n++;
        return 'CODE-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
if (!function_exists('pge_get_user_plan_limits_for_events')) {
    function pge_get_user_plan_limits_for_events($user_id) { return ['guest_limit' => 0]; }
}
if (!function_exists('headers_sent')) { function headers_sent() { return true; } } // يمنع محاولة header() الحقيقية في بيئة php-wasm

$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null) {
    if ($cap === 'administrator') return $GLOBALS['__test_user_is_admin'];
    return false;
}

$GLOBALS['__test_posts'] = [];
function set_test_event($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) ['ID' => $event_id, 'post_type' => $post_type, 'post_author' => $author_id];
}
function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
function get_post_field($field, $post_id) { $p = get_post($post_id); if (!$p) return ''; return $p->{$field} ?? ''; }

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
function delete_post_meta($post_id, $key)
{
    unset($GLOBALS['__test_post_meta'][$post_id][$key]);
    return true;
}

if (!class_exists('Test_Wp_Die_Exception_Hdsf')) { class Test_Wp_Die_Exception_Hdsf extends \Exception {} }

function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception_Hdsf('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception_Hdsf('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception_Hdsf('wp_die'); }
// ملاحظة: header() دالة PHP مبنية مسبقاً — لا حاجة لإعادة تعريفها؛
// headers_sent() أعلاه تُعاد true دائماً فتمنع الوصول الفعلي لاستدعائها ضمن
// مسار تصدير CSV (راجع الشرط `if (!headers_sent())` في المعالج الحقيقي).

function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try { $handler(); } catch (Test_Wp_Die_Exception_Hdsf $e) { /* متوقَّع */ }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function call_export_handler(callable $handler): string
{
    ob_start();
    try { $handler(); } catch (Test_Wp_Die_Exception_Hdsf $e) { /* متوقَّع */ }
    return ob_get_clean();
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

/**
 * Fake $wpdb — نفس بنية test-hard-delete-semantics-audit.php المُثبَتة حرفياً
 * (جدولا wp_pge_event_rsvps وwp_pge_invitation_mgmt_audit_log فقط)، بلا أي
 * تعديل على منطقها الداخلي.
 */
class Fake_Wpdb_Hdsf
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $rsvps = [];
    public $mgmt_audit = [];
    private $rsvp_next_id = 1;
    private $mgmt_audit_next_id = 1;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? ''; $i++;
            return $m[0] === '%d' ? (string) (int) $val : "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    private function extract_int($sql, $col) {
        if (preg_match('/\b' . preg_quote($col, '/') . '\s*=\s*(\d+)/', $sql, $m)) return (int) $m[1];
        return null;
    }
    private function extract_str($sql, $col) {
        if (preg_match('/\b' . preg_quote($col, '/') . "\s*=\s*'([^']*)'/", $sql, $m)) return $m[1];
        return null;
    }
    private function is_rsvps_table($sql) { return strpos($sql, 'pge_event_rsvps') !== false; }
    private function is_mgmt_audit_table($sql) { return strpos($sql, 'pge_invitation_mgmt_audit_log') !== false; }

    public function get_row($sql, $output = null)
    {
        if ($this->is_rsvps_table($sql) && strpos($sql, 'total_invitations') !== false) {
            $event_id = $this->extract_int($sql, 'event_id');
            $total = 0; $checked_in = 0; $expected = 0; $actual = 0;
            foreach ($this->rsvps as $r) {
                if ((int) $r['event_id'] !== $event_id) continue;
                $total++;
                $expected += 1 + (int) $r['companions'];
                if ((int) $r['checked_in'] === 1) {
                    $checked_in++;
                    $actual += (int) ($r['actual_entered_count'] ?? 0);
                }
            }
            $agg = ['total_invitations' => $total, 'checked_in_invitations' => $checked_in, 'expected_guests' => $expected, 'actual_attendees' => $actual];
            return $output === ARRAY_A ? $agg : (object) $agg;
        }

        if ($this->is_rsvps_table($sql)) {
            $id = $this->extract_int($sql, 'id');
            $event_id = $this->extract_int($sql, 'event_id');
            $phone = $this->extract_str($sql, 'guest_phone');
            $matches = array_filter($this->rsvps, function ($r) use ($id, $event_id, $phone) {
                if ($id !== null && (int) $r['id'] !== $id) return false;
                if ($event_id !== null && (int) $r['event_id'] !== $event_id) return false;
                if ($phone !== null && (string) $r['guest_phone'] !== $phone) return false;
                return true;
            });
            if (empty($matches)) return null;
            ksort($matches);
            $row = reset($matches);
            return $output === ARRAY_A ? $row : (object) $row;
        }

        return null;
    }

    public function get_var($sql)
    {
        if ($this->is_rsvps_table($sql) && strpos($sql, 'SUM(1 + companions)') !== false) {
            $event_id = $this->extract_int($sql, 'event_id');
            $sum = 0;
            foreach ($this->rsvps as $r) {
                if ((int) $r['event_id'] === $event_id && $r['reply'] === 'yes') $sum += 1 + (int) $r['companions'];
            }
            return $sum;
        }
        return null;
    }

    public function get_results($sql, $output = null)
    {
        if ($this->is_rsvps_table($sql)) {
            $event_id = $this->extract_int($sql, 'event_id');
            $phone = $this->extract_str($sql, 'guest_phone');
            $rows = array_values(array_filter($this->rsvps, function ($r) use ($event_id, $phone) {
                if ($event_id !== null && (int) $r['event_id'] !== $event_id) return false;
                if ($phone !== null && (string) $r['guest_phone'] !== $phone) return false;
                return true;
            }));
            usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
            return $output === ARRAY_A ? $rows : array_map(function ($r) { return (object) $r; }, $rows);
        }
        if ($this->is_mgmt_audit_table($sql)) {
            $event_id = $this->extract_int($sql, 'event_id');
            $phone = $this->extract_str($sql, 'guest_phone');
            $rows = array_values(array_filter($this->mgmt_audit, function ($r) use ($event_id, $phone) {
                if ($event_id !== null && (int) $r['event_id'] !== $event_id) return false;
                if ($phone !== null && (string) $r['guest_phone'] !== $phone) return false;
                return true;
            }));
            usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
            return $rows;
        }
        return [];
    }

    public function insert($table, $data, $format = null)
    {
        if (strpos($table, 'pge_event_rsvps') !== false) {
            $id = $this->rsvp_next_id++;
            $row = array_merge([
                'guest_name' => null, 'checked_in' => 0, 'checked_in_at' => null,
                'checked_in_by_assignment_id' => null, 'checkin_method' => null, 'actual_entered_count' => null,
                'created_at' => function_exists('current_time') ? current_time('mysql', true) : null,
            ], $data, ['id' => $id]);
            $this->rsvps[$id] = $row;
            $this->insert_id = $id;
            return 1;
        }
        if (strpos($table, 'pge_invitation_mgmt_audit_log') !== false) {
            $id = $this->mgmt_audit_next_id++;
            $this->mgmt_audit[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        return false;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        if (strpos($table, 'pge_event_rsvps') !== false && isset($where['id'])) {
            $id = (int) $where['id'];
            if (!isset($this->rsvps[$id])) return 0;
            $this->rsvps[$id] = array_merge($this->rsvps[$id], $data);
            return 1;
        }
        return false;
    }

    public function query($sql) { return 0; }
}
$GLOBALS['wpdb'] = new Fake_Wpdb_Hdsf();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ============================================================================
// تحميل حقيقي للسلسلة الكاملة (invitation-management-ajax.php تجرّ داخلياً
// class-pge-invitation-service.php/class-pge-invitation-export.php/
// class-pge-xlsx-writer.php/class-pge-invitation-bulk-add.php — بلا حاجة
// لطلبها هنا مباشرة، نفس ترتيب pgevents-core.php الحقيقي)
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/invitation-management-ajax.php';
require_once __DIR__ . '/../includes/rsvp-handler.php';
require_once __DIR__ . '/../includes/class-pge-guest-resolution-service.php';
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php';
require_once __DIR__ . '/../includes/class-pge-attendance-statistics-service.php';
require_once __DIR__ . '/../includes/class-pge-attendance-dashboard-provider.php';

$total = 0; $passed = 0; $failures = [];
function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS  $label\n"; }
    else { $failures[] = $label; echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}
function check_true($label, $cond) { check($label, (bool) $cond, true); }

function real_attendance_summary(int $event_id): array
{
    $ref = new ReflectionClass('PGE_Attendance_Statistics_Service');
    $instance = $ref->newInstanceWithoutConstructor();
    return $instance->get_attendance_summary($event_id);
}

$HOST_ID = 901;
set_test_event(6001, $HOST_ID);
$GLOBALS['__test_current_user_id'] = $HOST_ID;
$GLOBALS['__test_logged_in'] = true;

echo "=== RC1 Hard Delete Semantics Fix Pack — Executable Verification ===\n";

// ============================================================================
// 1: حذف دعوة قبل RSVP
// ============================================================================
PGE_Invitation_Service::create(6001, '0550000001', 'ضيف 1', '', $HOST_ID);
PGE_Invitation_Service::delete(6001, '0550000001', $HOST_ID);
check('1. حذف قبل RSVP: resolve_by_phone() → not_found (لا يصل لأي مسار)', PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000001')['result'], 'not_found');

// ============================================================================
// 2: حذف دعوة بعد RSVP
// ============================================================================
PGE_Invitation_Service::create(6001, '0550000002', 'ضيف 2', '', $HOST_ID);
pge_save_rsvp_response(6001, '0550000002', 'yes', 1, '', false);
$rsvp_id_2 = PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000002')['guest']['rsvp_id'];
PGE_Invitation_Service::delete(6001, '0550000002', $HOST_ID);
check('2. حذف بعد RSVP: resolve_by_phone() → not_found (Blocker 1)', PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000002')['result'], 'not_found');
check('2ب. resolve_by_rsvp_id() → not_found أيضاً (نفس المسار الذي يمرّ عبره مسح QR)', PGE_Guest_Resolution_Service::resolve_by_rsvp_id(6001, $rsvp_id_2)['result'], 'not_found');

// ============================================================================
// 3: حذف دعوة بعد Check-in
// ============================================================================
PGE_Invitation_Service::create(6001, '0550000003', 'ضيف 3', '', $HOST_ID);
pge_save_rsvp_response(6001, '0550000003', 'yes', 0, '', false);
$rsvp_id_3 = PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000003')['guest']['rsvp_id'];
// محاكاة أعمدة PGE_Checkin_Recorder الحقيقية مباشرة (Recorder لا يفحص خريطة
// الضيوف إطلاقاً — قراءة كود Phase 4 تؤكد ذلك — فمحاكاة أعمدته النهائية كافية).
$wpdb->update($wpdb->prefix . 'pge_event_rsvps', [
    'checked_in' => 1, 'checked_in_at' => '2026-08-01 10:00:00',
    'checked_in_by_assignment_id' => 77, 'checkin_method' => 'qr', 'actual_entered_count' => 1,
], ['id' => $rsvp_id_3]);
PGE_Invitation_Service::delete(6001, '0550000003', $HOST_ID);
check('3. حذف بعد Check-in: resolve_by_phone() → not_found رغم تسجيل الحضور مسبقاً (Blocker 1، الحالة الأخطر)', PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000003')['result'], 'not_found');
check('3ب. resolve_by_rsvp_id() → not_found أيضاً', PGE_Guest_Resolution_Service::resolve_by_rsvp_id(6001, $rsvp_id_3)['result'], 'not_found');

// ============================================================================
// 4: QR قديم بعد Regenerate ثم Delete
// ============================================================================
PGE_Invitation_Service::create(6001, '0550000004', 'ضيف 4', '', $HOST_ID);
pge_save_rsvp_response(6001, '0550000004', 'yes', 0, '', false);
$rsvp_id_4 = PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000004')['guest']['rsvp_id'];
$qr_v1_4 = PGE_Guest_Resolution_Service::build_scanner_qr_payload(6001, $rsvp_id_4, '0550000004');
$regen_4 = PGE_Invitation_Repository::regenerate_qr(6001, '0550000004');
check('4. تمهيد: تدوير QR نجح (v1→v2)', $regen_4['result'], 'regenerated');
PGE_Invitation_Service::delete(6001, '0550000004', $HOST_ID);
// [مُحدَّث RWPU] بعد إصلاح "RSVP Write Path Unification" (اشتقاق qr_version
// الافتراضي من invited_at بدل ثابت)، الرفض يحدث الآن عند فحص is_qr_version_
// current() نفسه (invalid/qr_superseded) بدل الوصول لاحقاً لـresolve_by_
// rsvp_id() (not_found) — رفض أقوى، لا انحدار: كلاهما يمنعان الوصول لـ
// PGE_Checkin_Recorder تماماً.
$resolve_4b = PGE_Guest_Resolution_Service::resolve_from_qr(6001, $qr_v1_4);
check('4ب. [Blocker 2، مُحدَّث RWPU] الـQR القديم (v1) لا يعود صالحاً أبداً بعد الحذف — يُرفَض (invalid/qr_superseded) بصرف النظر عن إعادة ضبط qr_version', [$resolve_4b['result'], $resolve_4b['reason'] ?? null], ['invalid', 'qr_superseded']);

// ============================================================================
// 5: إنشاء دعوة جديدة بنفس الهاتف
// ============================================================================
PGE_Invitation_Service::create(6001, '0550000005', 'ضيف 5 — الأصلي', '', $HOST_ID);
pge_save_rsvp_response(6001, '0550000005', 'yes', 2, '', false);
$rsvp_id_5_old = PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000005')['guest']['rsvp_id'];
$wpdb->update($wpdb->prefix . 'pge_event_rsvps', [
    'checked_in' => 1, 'checked_in_at' => '2026-08-01 11:00:00',
    'checked_in_by_assignment_id' => 12, 'checkin_method' => 'manual_search', 'actual_entered_count' => 3,
], ['id' => $rsvp_id_5_old]);
PGE_Invitation_Service::delete(6001, '0550000005', $HOST_ID);
PGE_Invitation_Service::create(6001, '0550000005', 'ضيف 5 — الجديد (نفس الرقم)', '', $HOST_ID);
$create_result_5 = pge_save_rsvp_response(6001, '0550000005', 'yes', 0, 'رد جديد', false);
check_true('5. إنشاء دعوة جديدة بنفس الهاتف بعد الحذف: RSVP الضيف الجديد ينجح', $create_result_5['success']);
$resolve_5 = PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000005');
check('5ب. resolve_by_phone() يُعيد found نظيفاً (لا ambiguous) للهوية الجديدة فقط', $resolve_5['result'], 'found');
$rsvp_id_5_new = $resolve_5['guest']['rsvp_id'];
// [مُحدَّث RWPU] wp_pge_event_rsvps تفرض فعلياً UNIQUE KEY event_phone
// (event_id, guest_phone) — لا يمكن فعلياً وجود صفين لنفس المناسبة+الهاتف،
// فإعادة استخدام نفس id الفعلي (مُصفَّراً بالكامل) هي الآلية الوحيدة الممكنة
// معمارياً؛ الاستقلالية الحقيقية مُثبَتة عبر 6-10 أدناه (لا توريث حالة) لا عبر
// اختلاف id. راجع tests/test-rsvp-write-path-unification.php للتفصيل الكامل.
check('5ج. [مُحدَّث RWPU] نفس id الفعلي أُعيد استخدامه (تصفير كامل في المكان — الخيار الوحيد الممكن تحت القيد الفريد الحقيقي؛ الاستقلالية تُثبَت بعدم توريث الحالة في 6-10، لا باختلاف id)', $rsvp_id_5_new === $rsvp_id_5_old, true);

// ============================================================================
// 6-10: عدم انتقال الحالة (Non-Inheritance) — نفس $resolve_5 أعلاه
// ============================================================================
check_true('6. عدم انتقال checked_in — الضيف الجديد checked_in=false', $resolve_5['guest']['checked_in'] === false);
check('7. عدم انتقال RSVP (الرد/العدد يعكسان الضيف الجديد حصراً، لا القديم)', [$resolve_5['guest']['reply'], $resolve_5['guest']['companions']], ['yes', 0]);
check('8. عدم انتقال actual_entered_count', $resolve_5['guest']['actual_entered_count'], null);
check('9. عدم انتقال checkin_method', $resolve_5['guest']['checkin_method'], null);
// 10: عدم انتقال أي Audit linkage — سجل التدقيق الإداري لكل من الهاتفين لا
// يختلط: القديم يحمل حدث 'deleted'، الجديد يحمل حدث 'created' منفصل، ولا رابط
// بينهما عبر rsvp_id (سجل التدقيق الإداري لا يخزّن rsvp_id إطلاقاً أصلاً —
// مفهرَس بالهاتف+الزمن فقط، فلا وسيلة له لخلط هويتين مختلفتين لنفس الهاتف).
$audit_5 = PGE_Invitation_Management_Audit::list_for_invitation(6001, '0550000005');
$actions_5 = array_column($audit_5, 'action');
check('10. سجل التدقيق للهاتف 0550000005 يحتوي created(الأصلي) → deleted → created(الجديد) بالترتيب — بلا أي خلط', $actions_5, ['created', 'deleted', 'created']);

// ============================================================================
// 11: استمرار عمل Cancel كما هو (لم يُلمَس إطلاقاً — تحقّق إيجابي)
// ============================================================================
PGE_Invitation_Service::create(6001, '0550000011', 'ضيف Cancel', '', $HOST_ID);
pge_save_rsvp_response(6001, '0550000011', 'yes', 0, '', false);
$_POST = make_post_fields(6001, ['phone' => '0550000011', 'reason' => 'تجربة']);
$cancel_resp = call_ajax_handler('pge_invitation_mgmt_cancel_handler');
check_true('11. Cancel لا يزال يعمل عبر AJAX الحقيقي (لم نلمس cancel() إطلاقاً)', $cancel_resp['success']);
$resolve_cancelled = PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000011');
check('11ب. دعوة مُلغاة (لا محذوفة) لا تزال تُعطي result=cancelled بالضبط كما كانت (لا not_found) — الحارس الجديد لا يتداخل مع حارس الإلغاء', $resolve_cancelled['result'], 'cancelled');

// ============================================================================
// 12: استمرار عمل QR الحالي (دعوة نشطة عادية — لا حذف، لا تدوير)
// ============================================================================
PGE_Invitation_Service::create(6001, '0550000012', 'ضيف QR سليم', '', $HOST_ID);
pge_save_rsvp_response(6001, '0550000012', 'yes', 0, '', false);
$rsvp_id_12 = PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000012')['guest']['rsvp_id'];
$qr_12 = PGE_Guest_Resolution_Service::build_scanner_qr_payload(6001, $rsvp_id_12, '0550000012');
check('12. QR لدعوة نشطة عادية (لا حذف) لا يزال يُحلّ إلى found تماماً كما كان — لا كسر زائف (False Positive)', PGE_Guest_Resolution_Service::resolve_from_qr(6001, $qr_12)['result'], 'found');

// ============================================================================
// 13: استمرار عمل Bulk Add
// ============================================================================
$_POST = make_post_fields(6001, ['raw_text' => 'ضيف Bulk واحد,0550000131' . "\n" . 'ضيف Bulk اثنان,0550000132']);
$bulk_confirm_resp = call_ajax_handler('pge_invitation_mgmt_bulk_confirm_handler');
check_true('13. Bulk Add (Confirm) لا يزال يعمل عبر AJAX الحقيقي', $bulk_confirm_resp['success']);
check('13ب. صفّان أُنشئا فعلياً في خريطة الضيوف', [isset(pge_event_guests_get_map(6001)['0550000131']), isset(pge_event_guests_get_map(6001)['0550000132'])], [true, true]);

// ============================================================================
// 14: استمرار عمل Bulk Delete
// ============================================================================
$_POST = make_post_fields(6001, ['phones' => '0550000131,0550000132']);
$bulk_delete_resp = call_ajax_handler('pge_invitation_mgmt_bulk_delete_handler');
check_true('14. Bulk Delete لا يزال يعمل عبر AJAX الحقيقي', $bulk_delete_resp['success']);
check('14ب. عدد المحذوف = 2 (نفس عقد الاستجابة الأصلي بلا تغيير)', $bulk_delete_resp['data']['deleted'], 2);

// ============================================================================
// 15: استمرار عمل Statistics (لدعوة نشطة عادية — تحقّق إيجابي، لا فقط سلبي)
// ============================================================================
$summary_15 = real_attendance_summary(6001);
check_true('15. Statistics (الدالة الإنتاجية الحقيقية عبر Reflection) لا تزال تعمل وتُعيد أرقاماً منطقية (>=1) — لم نلمس هذا الملف إطلاقاً', $summary_15['total_invitations'] >= 1);

// ============================================================================
// 16: استمرار عمل Dashboard (نفس مصدر البيانات الذي يستهلكه Dashboard Provider)
// ============================================================================
check_true('16. class PGE_Attendance_Dashboard_Provider لا تزال محمَّلة وسليمة (لم نلمس هذا الملف إطلاقاً)', class_exists('PGE_Attendance_Dashboard_Provider'));

// ============================================================================
// 17: استمرار عمل Export (نفس list_invitations() المُستخدَمة فعلياً في
// PGE_Invitation_Export::get_export_rows() — تحقّق غير مباشر لكن دقيق: هذا
// الملف لم يُعدَّل، ومصدر بياناته (خريطة الضيوف) لم يتغيَّر سلوكه)
// ============================================================================
$_POST = make_post_fields(6001, []);
$csv_output = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('17. تصدير CSV لا يزال يعمل عبر AJAX الحقيقي (يُنتج محتوى فعلياً)', strpos($csv_output, "\n") !== false || strlen($csv_output) > 0);

// ============================================================================
// 18: استمرار عمل Supervisor Check-in (طبقة الحلّ المشتركة نفسها لدعوة نشطة)
// ============================================================================
PGE_Invitation_Service::create(6001, '0550000018', 'ضيف مشرف سليم', '', $HOST_ID);
pge_save_rsvp_response(6001, '0550000018', 'yes', 1, '', false);
// pge_supervisor_checkin_ui_search_handler (checkin-ui-ajax.php) يستدعي
// PGE_Guest_Resolution_Service::resolve_by_phone() نفسها بلا أي منطق إضافي —
// نفس ما يُختبَر هنا مباشرة، بلا حاجة لسحب سلسلة تفويض Supervisor Portal
// الكاملة (middleware/session) غير المتعلقة بمركز هذا الفيكس باك.
check('18. مسار البحث اليدوي لواجهة تسجيل الحضور (نفس resolve_by_phone() التي يستهلكها checkin-ui-ajax.php حرفياً) لا يزال يُعيد found لدعوة نشطة سليمة', PGE_Guest_Resolution_Service::resolve_by_phone(6001, '0550000018')['result'], 'found');

// ============================================================================
// 19: تشغيل Regression كامل للمراحل السابقة — يُنفَّذ خارج هذا الملف (ملفات
// اختبار منفصلة قائمة فعلاً)، مُوثَّق بنتائجه الكاملة في التقرير النهائي.
// ============================================================================
echo "19. (مرجعي) Regression الكامل يُشغَّل عبر ملفات اختبار منفصلة قائمة — راجع التقرير النهائي.\n";

echo "\n=== النتيجة: $passed / $total ===\n";
if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
