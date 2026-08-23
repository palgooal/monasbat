<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ"D2-W4 — Authorized Claim / Send
 * Request Mutation Contract": PGE_Invitation_Send_Application::
 * request_send_for_actor() — حدود الطفرة الوحيدة (Mutation Boundary)، فوق
 * D2-W1/D2-W2/D2-W3 القائمة فعلياً — بلا تعديل على أي منها.
 *
 * يحمّل الملفات الحقيقية التالية دون أي تعديل عليها (فيما عدا الإضافة
 * الموثَّقة لـclass-pge-invitation-send-application.php نفسها، الملف قيد
 * الاختبار):
 *   - includes/class-pge-message-type.php
 *   - includes/class-pge-message-log.php
 *   - includes/class-pge-message-batch.php
 *   - includes/class-pge-invitation-send-ledger.php   (D2-W1، غير مُعدَّل هنا)
 *   - includes/class-pge-invitation-send-state.php    (D2-W2، غير مُعدَّل هنا)
 *   - includes/class-pge-event-access-authorization.php (D2-W3، غير مُعدَّل هنا)
 *   - includes/class-pge-invitation-send-application.php (D2-W3 + D2-W4)
 *
 * PGE_Invitation_Repository وPGE_Event_Access_Repository (+ WP primitives)
 * تُحاكى محلياً هنا بالكامل — نفس فلسفة tests/test-d2-w3-invitation-send-
 * authorization.php تماماً (تركيز الاختبار على D2-W4 فقط).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. الخروج برمز 0 عند نجاح كل
 * الحالات، أو 1 عند فشل أي حالة.
 *
 * التشغيل: php tests/test-d2-w4-invitation-send-request.php
 */

define('ABSPATH', __DIR__ . '/');

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('current_time')) {
    $GLOBALS['__test_now'] = '2026-08-22 10:00:00';
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now']; }
}

if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) { return preg_replace('/\D+/', '', trim((string) $v)); }
}

final class WP_Error
{
    private $code;
    private $message;
    function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
}

// ══════════════════════════════════════════════════════════════════════
// WP primitives — Context::resolve() تحتاجها فقط.
// ══════════════════════════════════════════════════════════════════════

$GLOBALS['w4_posts'] = [];
$GLOBALS['w4_admins'] = [];

function get_post_type($id) { return $GLOBALS['w4_posts'][$id]['type'] ?? false; }
function get_post_field($field, $id) { return $field === 'post_author' ? ($GLOBALS['w4_posts'][$id]['author'] ?? 0) : null; }
function user_can($user_id, $cap) { return $cap === 'administrator' && !empty($GLOBALS['w4_admins'][$user_id]); }

function seed_event($event_id, $owner_user_id) { $GLOBALS['w4_posts'][$event_id] = ['type' => 'pge_event', 'author' => $owner_user_id]; }
function seed_admin($user_id) { $GLOBALS['w4_admins'][$user_id] = true; }

// ══════════════════════════════════════════════════════════════════════
// Fake PGE_Event_Access_Repository (نفس نمط D2-W3 حرفياً).
// ══════════════════════════════════════════════════════════════════════

class PGE_Event_Access_Repository
{
    public static $memberships = [];
    public static $membership_groups = [];
    public static $assignments = [];

    public static function get_membership_for_user($event_id, $actor_user_id)
    {
        return self::$memberships[$event_id][$actor_user_id] ?? null;
    }

    public static function list_group_ids_for_membership($event_id, $membership_id)
    {
        return self::$membership_groups[$event_id][$membership_id] ?? [];
    }

    public static function get_guest_assignment($event_id, $guest_phone)
    {
        $key = ((int) $event_id) . '|' . (string) $guest_phone;
        if (!array_key_exists($key, self::$assignments)) {
            return null;
        }
        return ['group_id' => self::$assignments[$key]];
    }
}

function seed_membership($event_id, $user_id, $membership_id, $status, $role, $allocated_quota, array $group_ids)
{
    PGE_Event_Access_Repository::$memberships[$event_id][$user_id] = [
        'id' => $membership_id,
        'status' => $status,
        'role' => $role,
        'allocated_quota' => $allocated_quota,
    ];
    PGE_Event_Access_Repository::$membership_groups[$event_id][$membership_id] = $group_ids;
}

function seed_assignment($event_id, $phone, $group_id)
{
    PGE_Event_Access_Repository::$assignments[((int) $event_id) . '|' . (string) $phone] = $group_id;
}

// ══════════════════════════════════════════════════════════════════════
// Fake PGE_Invitation_Repository (نفس نمط D2-W1/D2-W2/D2-W3 حرفياً).
// ══════════════════════════════════════════════════════════════════════

class PGE_Invitation_Repository
{
    public static $invitations = [];

    public static function get_invitation($event_id, $phone)
    {
        $key = ((int) $event_id) . '|' . (string) $phone;
        return self::$invitations[$key] ?? null;
    }
}

function seed_invitation($event_id, $phone, $invited_at)
{
    $key = ((int) $event_id) . '|' . (string) $phone;
    PGE_Invitation_Repository::$invitations[$key] = ['invited_at' => $invited_at];
}

// ══════════════════════════════════════════════════════════════════════
// Fake $wpdb: pge_message_log فقط (نفس نمط D2-W1/D2-W2/D2-W3 حرفياً).
// ══════════════════════════════════════════════════════════════════════

class Fake_Wpdb_D2W4
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $message_log = [];
    private $next_id = 1;

    public $held_locks = [];

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

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $name = $m[1];
            if (isset($this->held_locks[$name])) {
                return '0';
            }
            $this->held_locks[$name] = true;
            return '1';
        }

        return null;
    }

    public function query($sql)
    {
        if (preg_match("/SELECT\s+RELEASE_LOCK\('([^']*)'\)/i", $sql, $m)) {
            unset($this->held_locks[$m[1]]);
            return 1;
        }

        return false;
    }

    public function get_row($sql, $output = null)
    {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null)
    {
        $rows = array_values($this->message_log);
        $filtered = $this->apply_where($rows, $sql);

        if (preg_match('/ORDER BY\s+id\s+ASC/i', $sql)) {
            usort($filtered, function ($a, $b) { return $a['id'] <=> $b['id']; });
        }

        return $filtered;
    }

    private function apply_where(array $rows, $sql)
    {
        if (preg_match('/WHERE\s+(.+?)(ORDER BY|LIMIT|$)/is', $sql, $m)) {
            $where = trim($m[1]);
            $conditions = preg_split('/\bAND\b/i', $where);
            foreach ($conditions as $cond) {
                $cond = trim($cond);
                if ($cond === '') {
                    continue;
                }
                if (preg_match("/^(\\w+)\\s*=\\s*'([^']*)'$/", $cond, $cm)) {
                    $field = $cm[1];
                    $value = $cm[2];
                } elseif (preg_match('/^(\\w+)\\s*=\\s*(-?\\d+)$/', $cond, $cm)) {
                    $field = $cm[1];
                    $value = $cm[2];
                } else {
                    continue;
                }
                $rows = array_values(array_filter($rows, function ($r) use ($field, $value) {
                    return array_key_exists($field, $r) && (string) $r[$field] === (string) $value;
                }));
            }
        }
        return $rows;
    }

    public function insert($table, $data, $format = null)
    {
        $this->last_error = '';
        $id = $this->next_id++;
        $this->message_log[$id] = array_merge(['id' => $id], $data);
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $id = $where['id'] ?? null;
        if ($id === null || !isset($this->message_log[$id])) {
            return 0;
        }

        foreach ($where as $where_key => $where_value) {
            if ($where_key === 'id') {
                continue;
            }
            $current_value = $this->message_log[$id][$where_key] ?? null;
            if ($where_value === null) {
                if ($current_value !== null) {
                    return 0;
                }
                continue;
            }
            if ((string) $current_value !== (string) $where_value) {
                return 0;
            }
        }

        foreach ($data as $k => $v) {
            $this->message_log[$id][$k] = $v;
        }

        return 1;
    }
}

global $wpdb;
$wpdb = new Fake_Wpdb_D2W4();

require_once __DIR__ . '/../includes/class-pge-message-type.php';
require_once __DIR__ . '/../includes/class-pge-message-log.php';
require_once __DIR__ . '/../includes/class-pge-message-batch.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-ledger.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-state.php';
require_once __DIR__ . '/../includes/class-pge-event-access-authorization.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-application.php';

// ── أدوات الفحص (نفس نمط D2-W1/D2-W2/D2-W3) ──────────────────────────

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
        $failures[] = "$label (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")";
        echo "FAIL  $label (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")\n";
    }
}

function check_true($label, $condition)
{
    global $total, $passed, $failures;
    $total++;
    if ($condition) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failures[] = "$label (condition was false)";
        echo "FAIL  $label (condition was false)\n";
    }
}

function pge_strip_php_comments($source)
{
    $stripped = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $stripped .= $token[1];
        } else {
            $stripped .= $token;
        }
    }
    return $stripped;
}

function count_rows_for_phone($event_id, $phone)
{
    global $wpdb;
    $n = 0;
    foreach ($wpdb->message_log as $row) {
        if ((int) ($row['event_id'] ?? 0) === (int) $event_id && (string) ($row['guest_phone'] ?? '') === (string) $phone) {
            $n++;
        }
    }
    return $n;
}

// ══════════════════════════════════════════════════════════════════════
// التثبيتات المشتركة
// ══════════════════════════════════════════════════════════════════════

const EVT = 8001;
const GROUP_1 = 4001;
const GROUP_2 = 4002;
const GROUP_3 = 4003;

seed_event(EVT, 9001); // Owner
seed_admin(9002);      // Admin

seed_membership(EVT, 9003, 1, 'active', 'manager', 10, [GROUP_1]);   // Additional Inviter مؤهَّل — GROUP_1
seed_membership(EVT, 9004, 2, 'active', 'manager', null, [GROUP_1]); // Plain Manager
seed_membership(EVT, 9005, 3, 'active', 'viewer', null, [GROUP_1]);  // Viewer
seed_membership(EVT, 9006, 4, 'revoked', 'manager', 10, [GROUP_2]);  // داعٍ إضافي مُلغى
seed_membership(EVT, 9010, 8, 'active', 'manager', 1, [GROUP_3]);    // داعٍ إضافي مؤهَّل — حصة صغيرة جداً (للاختبار R)

// ══════════════════════════════════════════════════════════════════════
// A. Owner: normal + not_sent → claimed
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000001', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000001', GROUP_1);

$a = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000001', 9001, 'normal');
check('A1. Owner normal not_sent: result = claimed', $a['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);
check_true('A2. Owner: log_id موجب في النتيجة', ($a['log_id'] ?? 0) > 0);
check('A3. Owner: state = send_requested', $a['state'] ?? null, 'send_requested');
check('A4. Owner: actor_user_id في النتيجة = 9001', $a['actor_user_id'] ?? null, 9001);

// ══════════════════════════════════════════════════════════════════════
// B. Additional Inviter في نطاقه: normal → claimed
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000002', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000002', GROUP_1);

$b = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000002', 9003, 'normal');
check('B1. Additional Inviter في نطاقه: claimed', $b['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);

// ══════════════════════════════════════════════════════════════════════
// C-D. مدير عادي/مُشاهِد: لا مطالبة إطلاقاً.
// ══════════════════════════════════════════════════════════════════════

$c = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000001', 9004, 'normal');
check('C1. Plain Manager: مرفوض، لا claim', $c['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);
check_true('C2. Plain Manager: لا يحمل log_id إطلاقاً في النتيجة', !array_key_exists('log_id', $c));

$d = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000001', 9005, 'normal');
check('D1. Viewer: مرفوض، لا claim', $d['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// E. داعٍ إضافي مُلغى: لا مطالبة.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000003', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000003', GROUP_2);

$e = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000003', 9006, 'normal');
check('E1. داعٍ إضافي مُلغى: مرفوض، لا claim', $e['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// F-G. TOCTOU وقت الطلب — انتقال الضيف قبل الطلب مباشرة.
// ══════════════════════════════════════════════════════════════════════

// F: الضيف "انتقل خارج" نطاق الداعي 9003 (GROUP_1) — الآن في GROUP_2 — قبل
// استدعاء request_send_for_actor() مباشرة (تفويض طازج يقرأ هذا فوراً).
seed_invitation(EVT, '966500000004', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000004', GROUP_2);
$f = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000004', 9003, 'normal');
check('F1. ضيف انتقل خارج نطاق الداعي قبل الطلب: مرفوض، لا claim', $f['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);
check('F2. لا صف أُنشئ لهذا الضيف', count_rows_for_phone(EVT, '966500000004'), 0);

// G: الضيف "انتقل إلى" نطاق الداعي 9003 — الآن في GROUP_1 — مسموح فوراً.
seed_invitation(EVT, '966500000005', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000005', GROUP_1);
$g = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000005', 9003, 'normal');
check('G1. ضيف انتقل إلى نطاق الداعي قبل الطلب: مسموح، claimed', $g['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);

// ══════════════════════════════════════════════════════════════════════
// H-I. ضيف غير مُخصَّص — Owner مسموح، Additional Inviter مرفوض.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000006', '2026-08-22 09:00:00'); // بلا seed_assignment
$h = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000006', 9001, 'normal');
check('H1. Owner لضيف غير مُخصَّص: claimed', $h['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);

seed_invitation(EVT, '966500000007', '2026-08-22 09:00:00'); // بلا seed_assignment
$i = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000007', 9003, 'normal');
check('I1. Additional Inviter لضيف غير مُخصَّص: مرفوض', $i['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// J-K. حالة provider_accepted (sent) موجودة مسبقاً.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000008', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000008', GROUP_1);
$jk_claim = PGE_Invitation_Send_Ledger::claim(EVT, '966500000008', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check_true('J0. تهيئة: claim() الأولي ينجح', ($jk_claim['result'] ?? null) === 'claimed');
check_true('J0b. تهيئة: finalize_success() ينجح', PGE_Invitation_Send_Ledger::finalize_success($jk_claim['log_id']));

$j = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000008', 9001, 'normal');
check('J1. normal بعد sent: already_sent، لا claim جديد', $j['result'] ?? null, PGE_Invitation_Send_Application::RESULT_ALREADY_SENT);
check('J2. لا صف جديد أُضيف (يبقى صف واحد فقط)', count_rows_for_phone(EVT, '966500000008'), 1);

$k = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000008', 9001, 'resend');
check('K1. resend بعد sent: محاولة جديدة claimed فعلياً', $k['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);
check('K2. صف ثانٍ أُضيف فعلياً (الآن صفّان لنفس الضيف)', count_rows_for_phone(EVT, '966500000008'), 2);
check_true('K3. log_id الجديد يختلف عن log_id الأصلي', ($k['log_id'] ?? null) !== ($jk_claim['log_id'] ?? null));

// ══════════════════════════════════════════════════════════════════════
// L-M. حالة ambiguous_transport_error موجودة مسبقاً.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000009', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000009', GROUP_1);
$lm_claim = PGE_Invitation_Send_Ledger::claim(EVT, '966500000009', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check_true('L0. تهيئة: claim() الأولي ينجح', ($lm_claim['result'] ?? null) === 'claimed');
check_true('L0b. تهيئة: finalize_failure(ambiguous) ينجح', PGE_Invitation_Send_Ledger::finalize_failure($lm_claim['log_id'], PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR));

$l = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000009', 9001, 'normal');
check('L1. ambiguous + normal: resend_required، لا claim جديد', $l['result'] ?? null, PGE_Invitation_Send_Application::RESULT_RESEND_REQUIRED);
check('L2. لا صف جديد أُضيف (يبقى صف واحد فقط)', count_rows_for_phone(EVT, '966500000009'), 1);

// M0. تصحيح افتراض الاختبار: claim() في D2-W1 يحجب أي محاولة جديدة على حالة
// ambiguous_transport_error بصرف النظر عن النيّة طالما الـLease (120 ثانية)
// لا يزال نشطاً (راجع class-pge-invitation-send-ledger.php، الفرع
// STATUS_AMBIGUOUS_TRANSPORT_ERROR — 'ambiguous_transport_lease_active') —
// resend يتجاوز فقط سجل sent النهائي، وليس نافذة الـLease نفسها. محاولة
// resend فورية دون تقادم الـLease تُعيد already_in_progress فعلياً، وليس
// claimed. لمحاكاة نافذة Lease منتهية فعلياً (current_time() هنا ثابتة عبر
// $GLOBALS['__test_now']، فلا وقت حقيقي يمرّ)، نُرجِع created_at الصف يدوياً
// إلى ما قبل CLAIM_LEASE_SECONDS من __test_now — عندها فقط يُطابق M1/M2
// السيناريو الذي تختبره فعلاً هذه الحالات: "resend بعد أن انتهى Lease محاولة
// ambiguous سابقة".
$wpdb->message_log[$lm_claim['log_id']]['created_at'] = '2026-08-22 09:57:00';

$m = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000009', 9001, 'resend');
check('M1. ambiguous + resend بعد انتهاء الـLease: محاولة جديدة claimed فعلياً', $m['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);
check('M2. صف ثانٍ أُضيف فعلياً', count_rows_for_phone(EVT, '966500000009'), 2);

// ══════════════════════════════════════════════════════════════════════
// N. محاولة pending حالية بالفعل — أي نيّة محجوبة.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000010', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000010', GROUP_1);
$n_claim = PGE_Invitation_Send_Ledger::claim(EVT, '966500000010', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check_true('N0. تهيئة: claim() الأولي ينجح (يبقى pending)', ($n_claim['result'] ?? null) === 'claimed');

$n = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000010', 9001, 'normal');
check('N1. pending موجودة: already_in_progress', $n['result'] ?? null, PGE_Invitation_Send_Application::RESULT_ALREADY_IN_PROGRESS);
check('N2. لا صف جديد أُضيف', count_rows_for_phone(EVT, '966500000010'), 1);

// ══════════════════════════════════════════════════════════════════════
// O. فاعلان لنفس الضيف — صف واحد فقط.
// ══════════════════════════════════════════════════════════════════════

// O1: التسلسل الطبيعي عبر request_send_for_actor() نفسها مرتين — الطلب
// الثاني يُحجَب فعلياً على مستوى D2-W3 (قراءة حالة طازجة) قبل حتى الوصول
// لـclaim() — هذا هو المسار الشائع عملياً.
seed_invitation(EVT, '966500000011', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000011', GROUP_1);
$o1_first = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000011', 9001, 'normal');
check('O1a. الفاعل الأول: claimed', $o1_first['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);
$o1_second = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000011', 9002, 'normal');
check('O1b. الفاعل الثاني (نفس الضيف): already_in_progress — محجوب قبل الوصول لـclaim() أصلاً', $o1_second['result'] ?? null, PGE_Invitation_Send_Application::RESULT_ALREADY_IN_PROGRESS);
check('O1c. صف واحد فقط لهذا الضيف', count_rows_for_phone(EVT, '966500000011'), 1);

// O2: محاكاة السباق الحقيقي (T1 تفويض ناجح → T2 فاعل آخر يُطالِب فعلياً →
// T3 هذا الطلب يصل claim()) عبر استدعاء PGE_Invitation_Send_Ledger::claim()
// مباشرة مرتين متتاليتين لنفس الضيف — يُثبِت أن حصر D2-W1 الذري نفسه (لا
// D2-W3 فقط) يمنع الصف المكرَّر حتى لو وصل الطلبان فعلياً إلى claim().
seed_invitation(EVT, '966500000012', '2026-08-22 09:00:00');
$o2_first = PGE_Invitation_Send_Ledger::claim(EVT, '966500000012', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check_true('O2a. المطالبة الأولى المباشرة تنجح', ($o2_first['result'] ?? null) === 'claimed');
$o2_second = PGE_Invitation_Send_Ledger::claim(EVT, '966500000012', 9002, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check('O2b. المطالبة الثانية المباشرة (نفس الضيف، فاعل آخر): already_in_progress من حصر D2-W1 نفسها', $o2_second['result'] ?? null, 'already_in_progress');
check('O2c. صف واحد فقط لهذا الضيف رغم وصول محاولتين فعلياً لـclaim()', count_rows_for_phone(EVT, '966500000012'), 1);

// ══════════════════════════════════════════════════════════════════════
// P. إسناد الفاعل الفعلي على الصف المُطالَب به.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000013', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000013', GROUP_1);
$p = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000013', 9003, 'normal');
check('P1. الطلب نفسه: claimed', $p['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);
check_true('P2. actor_user_id المُخزَّن فعلياً في صف message_log = 9003 (الفاعل الحقيقي)', ($wpdb->message_log[$p['log_id']]['actor_user_id'] ?? null) === 9003);

// ══════════════════════════════════════════════════════════════════════
// Q. هوية المُنشئ التاريخي لا تُستشار إطلاقاً.
// ══════════════════════════════════════════════════════════════════════

$q_reflection = new ReflectionMethod('PGE_Invitation_Send_Application', 'request_send_for_actor');
$q_param_names = array_map(function ($p) { return $p->getName(); }, $q_reflection->getParameters());
check_true('Q1. لا معامل لهوية "منشئ" في توقيع request_send_for_actor() إطلاقاً', !array_intersect($q_param_names, ['creator_user_id', 'created_by', 'original_actor_user_id', 'original_inviter_id']));

// نفس سيناريو D2-W3 I/J وظيفياً: ضيف "أنشأه" فاعل مختلف تاريخياً (لا حقل
// لذلك في هذه التثبيتات أصلاً)، الآن في نطاق الداعي 9003 — المطالبة تنجح
// بناءً على النطاق الحالي فقط.
seed_invitation(EVT, '966500000014', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000014', GROUP_1);
$q2 = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000014', 9003, 'normal');
check('Q2. النطاق الحالي فقط يحكم المطالبة، بلا أي أثر لهوية تاريخية: claimed', $q2['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);

// ══════════════════════════════════════════════════════════════════════
// R. قيمة الحصة الرقمية نفسها لا تُستشار في المطالبة — فقط وجود المسند.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000015', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000015', GROUP_3);
$r = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000015', 9010, 'normal'); // allocated_quota=1
check('R1. حصة=1 (صغيرة جداً، لا علاقة برصيد فعلي هنا): claimed كذلك', $r['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);

// ══════════════════════════════════════════════════════════════════════
// S. لا أثر جانبي خارج المطالبة نفسها — لا Queue/Worker/Cartat/UltraMsg.
// ══════════════════════════════════════════════════════════════════════

$app_src = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-send-application.php');
$app_code_only = pge_strip_php_comments($app_src);
$forbidden_terms_s = [
    'wp_ajax_', 'add_action', 'add_filter',
    'Cartat', 'UltraMsg',
    'wp_send_json',
    '::finalize_success(', '::finalize_failure(',
    'wp_schedule', 'set_transient', 'wp_queue',
];
$forbidden_found_s = [];
foreach ($forbidden_terms_s as $term) {
    if (strpos($app_code_only, $term) !== false) {
        $forbidden_found_s[] = $term;
    }
}
check_true('S1. لا Queue/Worker/Cartat/UltraMsg/AJAX/finalize_*() داخل الكود التنفيذي لكامل الملف (D2-W3+D2-W4 معاً)', empty($forbidden_found_s));

// ══════════════════════════════════════════════════════════════════════
// T. فشل تقني في المطالبة نفسها لا يُبلَّغ كنجاح إطلاقاً.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000016', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000016', GROUP_1);
// نُحاكي تنازع قفل حقيقي: نأخذ نفس اسم القفل الذي ستستخدمه claim() يدوياً
// قبل استدعاء request_send_for_actor() — نفس بناء الاسم في D2-W1 الفعلية.
$t_lock_name = 'pge_invitation_send_' . md5(EVT . '|' . '966500000016' . '|' . PGE_Message_Type::INVITATION);
$wpdb->held_locks[$t_lock_name] = true;

$t = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000016', 9001, 'normal');
check('T1. تنازع قفل حقيقي: النتيجة error، ليست claimed إطلاقاً', $t['result'] ?? null, PGE_Invitation_Send_Application::RESULT_ERROR);
check('T2. لا صف أُنشئ رغم محاولة المطالبة', count_rows_for_phone(EVT, '966500000016'), 0);

unset($wpdb->held_locks[$t_lock_name]); // تنظيف — لا يُفترَض أن يبقى القفل محجوزاً فعلياً بعد فشل claim() (تُحرِّره في finally دوماً)، هذا فقط ضمان نظافة بيئة الاختبار التالية.

// ══════════════════════════════════════════════════════════════════════
// U. ضيف غير موجود مقابل غير مخوَّل — يبقيان قابلين للطيّ الآمن لاحقاً.
// ══════════════════════════════════════════════════════════════════════

$u_nonexistent_event = PGE_Invitation_Send_Application::request_send_for_actor(999999, '966500000001', 9001, 'normal');
check('U1. مناسبة غير موجودة: not_authorized', $u_nonexistent_event['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

$u_stranger = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000001', 9099, 'normal');
check('U2. فاعل غريب تماماً لمناسبة حقيقية: not_authorized (نفس رمز U1)', $u_stranger['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

$u_ghost_guest = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000999', 9003, 'normal');
check('U3. ضيف غير موجود إطلاقاً لداعٍ خارج نطاقه أصلاً: not_found — تمييز داخلي مقصود (راجع توثيق الملف)، قابل للطيّ خارجياً مستقبلاً', $u_ghost_guest['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_FOUND);
check_true('U4. not_found وnot_authorized قيمتان نصّيتان مختلفتان فقط — لا حقل إضافي يُعقِّد الطيّ الخارجي المستقبلي', is_string($u_ghost_guest['result']) && is_string($u_stranger['result']));

// ══════════════════════════════════════════════════════════════════════
// V. نتيجة الطلب تكشف فقط الحقول المقصودة — لا محتوى رسالة، لا بيانات مزوّد.
// ══════════════════════════════════════════════════════════════════════

$v_claimed_keys = array_keys($a); // من الاختبار A أعلاه (claimed فعلياً)
sort($v_claimed_keys);
$v_expected_keys = ['actor_user_id', 'batch_id', 'event_id', 'intent', 'log_id', 'reason', 'result', 'state'];
sort($v_expected_keys);
check('V1. نتيجة claimed تحمل بالضبط الحقول المتوقَّعة، لا أكثر ولا أقل', $v_claimed_keys, $v_expected_keys);
check_true('V2. لا مفتاح "phone"/"guest_phone" في نتيجة claimed', !array_key_exists('phone', $a) && !array_key_exists('guest_phone', $a));
check_true('V3. لا مفتاح "message"/"content"/"provider" في نتيجة claimed', !array_key_exists('message', $a) && !array_key_exists('content', $a) && !array_key_exists('provider', $a));

$v_denied_keys = array_keys($c); // من الاختبار C أعلاه (not_authorized)
sort($v_denied_keys);
$v_expected_denied_keys = ['actor_user_id', 'event_id', 'intent', 'reason', 'result'];
sort($v_expected_denied_keys);
check('V4. نتيجة not_authorized تحمل بالضبط الحقول المتوقَّعة (لا log_id/batch_id/state)', $v_denied_keys, $v_expected_denied_keys);

// ══════════════════════════════════════════════════════════════════════
// W. طلب مكرَّر لا يُمكن أن يُكرِّر إرسالاً ناجحاً صامتاً.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000017', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000017', GROUP_1);
$w_first = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000017', 9001, 'normal');
check_true('W0. الطلب الأول: claimed', ($w_first['result'] ?? null) === PGE_Invitation_Send_Application::RESULT_CLAIMED);
check_true('W0b. تهيئة: finalize_success() ينجح لمحاكاة إرسال ناجح فعلياً', PGE_Invitation_Send_Ledger::finalize_success($w_first['log_id']));

$w_repeat = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000017', 9001, 'normal');
check('W1. طلب normal مكرَّر بعد نجاح فعلي: already_sent، لا مطالبة جديدة صامتة', $w_repeat['result'] ?? null, PGE_Invitation_Send_Application::RESULT_ALREADY_SENT);
check('W2. صف واحد فقط لهذا الضيف رغم الطلب المكرَّر', count_rows_for_phone(EVT, '966500000017'), 1);

// ══════════════════════════════════════════════════════════════════════
// X. D2-W6A Fix Pass 1 — cancelled (حالة صريحة أولى الدرجة). يتحقّق أن
//    request_send_for_actor() (التي تستدعي claim() فعلياً، لا مجرَّد قراءة
//    حالة) تُنتج مطالبة جديدة فعلية بعد cancelled + normal، وترفض resend
//    صراحةً بلا أي مطالبة جديدة — بلا أي تعديل على هذا الملف نفسه.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000018', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000018', GROUP_1);
$x_claim = PGE_Invitation_Send_Ledger::claim(EVT, '966500000018', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check_true('X0. تهيئة: claim() الأولي ينجح', ($x_claim['result'] ?? null) === 'claimed');
check_true('X0b. تهيئة: finalize_cancelled() ينجح (Cartat لم يُستدعَ إطلاقاً هنا)', PGE_Invitation_Send_Ledger::finalize_cancelled($x_claim['log_id']));

$x_resend = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000018', 9001, 'resend');
check('X1. cancelled + resend: invalid_state، لا مطالبة جديدة', $x_resend['result'] ?? null, PGE_Invitation_Send_Application::RESULT_INVALID_STATE);
check('X2. لا صف جديد أُضيف بعد resend المرفوض (يبقى صف واحد فقط)', count_rows_for_phone(EVT, '966500000018'), 1);
check('X3. الصف الأصلي المُلغى يبقى دون تغيير (status لا يزال cancelled)', $wpdb->message_log[$x_claim['log_id']]['status'], PGE_Message_Log::STATUS_CANCELLED);

$x_normal = PGE_Invitation_Send_Application::request_send_for_actor(EVT, '966500000018', 9001, 'normal');
check('X4. cancelled + normal: محاولة جديدة claimed فعلياً', $x_normal['result'] ?? null, PGE_Invitation_Send_Application::RESULT_CLAIMED);
check('X5. صف ثانٍ مستقل أُضيف فعلياً (الآن صفّان لنفس الضيف)', count_rows_for_phone(EVT, '966500000018'), 2);
check_true('X6. log_id الجديد يختلف عن log_id الأصلي المُلغى', ($x_normal['log_id'] ?? null) !== ($x_claim['log_id'] ?? null));
check('X7. الصف الأصلي المُلغى يبقى دون تغيير بعد إنشاء الصف الجديد (immutable)', $wpdb->message_log[$x_claim['log_id']]['status'], PGE_Message_Log::STATUS_CANCELLED);

// ── ملخص ────────────────────────────────────────────────────────────────

echo "\n";
echo "النتيجة: $passed / $total نجحت.\n";

if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}

exit(0);
