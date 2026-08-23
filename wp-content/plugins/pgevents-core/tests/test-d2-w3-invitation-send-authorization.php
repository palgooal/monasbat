<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ"D2-W3 — Scoped Invitation Send
 * Authorization / Application Contract": PGE_Invitation_Send_Application
 * (+ القدرة الجديدة PGE_Event_Access_Authorization::can_send_guest_invitation())
 * فوق D2-W1/D2-W2 القائمتين فعلياً — تفويض + تنسيق فقط، بلا أي كتابة.
 *
 * يحمّل الملفات الحقيقية التالية دون أي تعديل عليها (فيما عدا الإضافة
 * الوحيدة الموثَّقة لـclass-pge-event-access-authorization.php):
 *   - includes/class-pge-message-type.php
 *   - includes/class-pge-message-log.php
 *   - includes/class-pge-message-batch.php
 *   - includes/class-pge-invitation-send-ledger.php   (D2-W1، غير مُعدَّل هنا)
 *   - includes/class-pge-invitation-send-state.php    (D2-W2، غير مُعدَّل هنا)
 *   - includes/class-pge-event-access-authorization.php (D2-W3: إضافة
 *     can_send_guest_invitation() فقط — كل شيء آخر في الملف كما هو)
 *   - includes/class-pge-invitation-send-application.php (D2-W3 الجديد)
 *
 * PGE_Invitation_Repository وPGE_Event_Access_Repository (+ WP primitives
 * get_post_type/get_post_field/user_can) يُحاكَيان محلياً هنا بالكامل — لا
 * تحميل لأي ملف إنتاجي حقيقي ثقيل/متّصل بقاعدة بيانات لأي منهما، بنفس فلسفة
 * tests/test-d2-w1-invitation-send-ledger.php وtests/test-d2-w2-invitation-
 * send-state.php تماماً (تركيز الاختبار على D2-W3 فقط، لا إعادة تنفيذ H1C
 * الكاملة).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. الخروج برمز 0 عند نجاح كل
 * الحالات، أو 1 عند فشل أي حالة.
 *
 * التشغيل: php tests/test-d2-w3-invitation-send-authorization.php
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
// WP primitives — Context::resolve() تحتاجها فقط (get_post_type/
// get_post_field/user_can). لا شيء آخر من WordPress محمَّل هنا.
// ══════════════════════════════════════════════════════════════════════

$GLOBALS['w3_posts'] = [];  // event_id => ['type' => 'pge_event'|other, 'author' => user_id]
$GLOBALS['w3_admins'] = []; // user_id => true

function get_post_type($id) { return $GLOBALS['w3_posts'][$id]['type'] ?? false; }
function get_post_field($field, $id) { return $field === 'post_author' ? ($GLOBALS['w3_posts'][$id]['author'] ?? 0) : null; }
function user_can($user_id, $cap) { return $cap === 'administrator' && !empty($GLOBALS['w3_admins'][$user_id]); }

function seed_event($event_id, $owner_user_id) { $GLOBALS['w3_posts'][$event_id] = ['type' => 'pge_event', 'author' => $owner_user_id]; }
function seed_admin($user_id) { $GLOBALS['w3_admins'][$user_id] = true; }

// ══════════════════════════════════════════════════════════════════════
// Fake PGE_Event_Access_Repository — محاكاة محلية فقط لكل ما يحتاجه
// Context::resolve() (get_membership_for_user/list_group_ids_for_membership)
// وPGE_Invitation_Send_Application (get_guest_assignment) — لا تحميل للملف
// الإنتاجي الحقيقي الثقيل/المتّصل بقاعدة بيانات.
// ══════════════════════════════════════════════════════════════════════

class PGE_Event_Access_Repository
{
    /** [event_id][actor_user_id] => membership row array */
    public static $memberships = [];
    /** [event_id][membership_id] => int[] group ids */
    public static $membership_groups = [];
    /** "event_id|phone" => group_id (absent key = unassigned) */
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

function clear_assignment($event_id, $phone)
{
    unset(PGE_Event_Access_Repository::$assignments[((int) $event_id) . '|' . (string) $phone]);
}

// ══════════════════════════════════════════════════════════════════════
// Fake PGE_Invitation_Repository — محاكاة محلية فقط (نفس نمط D2-W1/D2-W2
// حرفياً).
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
// Fake $wpdb: pge_message_log فقط (نفس نمط D2-W1/D2-W2 حرفياً) — تُستخدَم
// حصراً لتهيئة (Seed) حالات إرسال مختلفة عبر PGE_Invitation_Send_Ledger
// الحقيقية (claim()/finalize_success()/finalize_failure()) قبل استدعاء
// PGE_Invitation_Send_Application قيد الاختبار؛ الأخيرة نفسها لا تستدعي أياً
// من هذه الثلاثة إطلاقاً — راجع الاختبار W أدناه.
// ══════════════════════════════════════════════════════════════════════

class Fake_Wpdb_D2W3
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
$wpdb = new Fake_Wpdb_D2W3();

require_once __DIR__ . '/../includes/class-pge-message-type.php';
require_once __DIR__ . '/../includes/class-pge-message-log.php';
require_once __DIR__ . '/../includes/class-pge-message-batch.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-ledger.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-state.php';
require_once __DIR__ . '/../includes/class-pge-event-access-authorization.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-application.php';

// ── أدوات الفحص (نفس نمط D2-W1/D2-W2) ────────────────────────────────

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

// ══════════════════════════════════════════════════════════════════════
// التثبيتات المشتركة (Fixtures)
// ══════════════════════════════════════════════════════════════════════

const EVT = 7001;
const GROUP_1 = 3001;
const GROUP_2 = 3002;

seed_event(EVT, 9001); // 9001 = Owner (post_author)
seed_admin(9002);      // 9002 = Administrator (أي مناسبة)

// داعٍ إضافي مؤهَّل — مجموعة واحدة فقط (GROUP_1)
seed_membership(EVT, 9003, 1, 'active', 'manager', 10, [GROUP_1]);
// مدير عادي (Plain Manager) — نفس المجموعة، بلا حصة
seed_membership(EVT, 9004, 2, 'active', 'manager', null, [GROUP_1]);
// مُشاهِد (Viewer)
seed_membership(EVT, 9005, 3, 'active', 'viewer', null, [GROUP_1]);
// داعٍ إضافي مُلغى (Revoked)
seed_membership(EVT, 9006, 4, 'revoked', 'manager', 10, [GROUP_2]);
// داعٍ إضافي مُشوَّه — بلا أي مجموعة ممنوحة
seed_membership(EVT, 9007, 5, 'active', 'manager', 10, []);
// داعٍ إضافي مُشوَّه — أكثر من مجموعة ممنوحة (تشمل GROUP_1)
seed_membership(EVT, 9008, 6, 'active', 'manager', 10, [GROUP_1, GROUP_2]);
// داعٍ إضافي بديل لـGROUP_2 (يحل محل 9006 المُلغى)
seed_membership(EVT, 9009, 7, 'active', 'manager', 5, [GROUP_2]);
// داعٍ إضافي مؤهَّل آخر بحصة مختلفة تماماً (للاختبار T — قيمة الحصة نفسها لا تؤثر)
seed_membership(EVT, 9010, 8, 'active', 'manager', 1000, [GROUP_1]);

// ضيوف — كل واحد بدورة حياة دعوة حالية (invited_at)، وتخصيص مجموعة حسب السيناريو.
seed_invitation(EVT, '966500000001', '2026-08-22 09:00:00'); // group 1
seed_assignment(EVT, '966500000001', GROUP_1);

seed_invitation(EVT, '966500000002', '2026-08-22 09:00:00'); // group 2
seed_assignment(EVT, '966500000002', GROUP_2);

seed_invitation(EVT, '966500000003', '2026-08-22 09:00:00'); // غير مُخصَّص
// لا seed_assignment — يبقى غير مُخصَّص عمداً.

seed_invitation(EVT, '966500000004', '2026-08-22 09:00:00'); // "أنشأه Owner، الآن في مجموعة الداعي" — group 1
seed_assignment(EVT, '966500000004', GROUP_1);

seed_invitation(EVT, '966500000005', '2026-08-22 09:00:00'); // "أنشأه داعٍ ملغى، الآن في مجموعة البديل" — group 2
seed_assignment(EVT, '966500000005', GROUP_2);

seed_invitation(EVT, '966500000006', '2026-08-22 09:00:00'); // "انتقل خارج مجموعة الداعي 9003" — الآن في group 2
seed_assignment(EVT, '966500000006', GROUP_2);

seed_invitation(EVT, '966500000007', '2026-08-22 09:00:00'); // "انتقل إلى مجموعة الداعي 9003" — الآن في group 1
seed_assignment(EVT, '966500000007', GROUP_1);

// ══════════════════════════════════════════════════════════════════════
// A-B. Owner/Admin يُخوَّلان لضيف حالي حقيقي، بغضّ النظر عن المجموعة.
// ══════════════════════════════════════════════════════════════════════

$a = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9001, 'normal');
check('A1. Owner: result = authorized', $a['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);
check_true('A2. Owner: is_owner=true في النتيجة', ($a['is_owner'] ?? null) === true);

$b = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000002', 9002, 'normal');
check('B1. Admin: result = authorized (مجموعة مختلفة، لا يهم)', $b['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);
check_true('B2. Admin: is_admin=true في النتيجة', ($b['is_admin'] ?? null) === true);

// ══════════════════════════════════════════════════════════════════════
// C-D. الداعي الإضافي: مخوَّل لمجموعته الحالية فقط، مرفوض لمجموعة أخرى.
// ══════════════════════════════════════════════════════════════════════

$c = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9003, 'normal');
check('C1. Additional Inviter: مخوَّل لضيف في مجموعته الوحيدة (GROUP_1)', $c['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);

$d = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000002', 9003, 'normal');
check('D1. Additional Inviter: مرفوض لضيف في مجموعة أخرى (GROUP_2)', $d['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// E-G. مدير عادي/مُشاهِد/داعٍ مُلغى — مرفوضون جميعاً.
// ══════════════════════════════════════════════════════════════════════

$e = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9004, 'normal');
check('E1. Plain Manager: مرفوض رغم وصول المجموعة نفسها (GROUP_1)', $e['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

$f = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9005, 'normal');
check('F1. Viewer: مرفوض', $f['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

$g = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000002', 9006, 'normal');
check('G1. Additional Inviter مُلغى: مرفوض (حتى لضيف كان في مجموعته الأصلية)', $g['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// H. داعٍ إضافي مُشوَّه — صفر مجموعات، أو أكثر من مجموعة — ينهار مغلقاً.
// ══════════════════════════════════════════════════════════════════════

$h1 = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9007, 'normal');
check('H1. صفر مجموعات ممنوحة: مرفوض', $h1['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

$h2 = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9008, 'normal');
check('H2. أكثر من مجموعة ممنوحة (تشمل GROUP_1 نفسها): مرفوض رغم ذلك', $h2['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// I-J. منشئ الضيف التاريخي لا يُستخدَم إطلاقاً — التخصيص الحالي فقط يحكم.
// ══════════════════════════════════════════════════════════════════════

$i = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000004', 9003, 'normal');
check('I1. ضيف أنشأه Owner تاريخياً، الآن في مجموعة الداعي: الداعي مخوَّل', $i['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);

$j = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000005', 9009, 'normal');
check('J1. ضيف أنشأه داعٍ ملغى تاريخياً، البديل الحالي لنفس المجموعة مخوَّل', $j['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// K-L. انتقال الضيف بين المجموعات — التخصيص الحالي فقط يحكم فوراً.
// ══════════════════════════════════════════════════════════════════════

$k = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000006', 9003, 'normal');
check('K1. ضيف انتقل خارج مجموعة الداعي 9003: مرفوض الآن', $k['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

$l = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000007', 9003, 'normal');
check('L1. ضيف انتقل إلى مجموعة الداعي 9003: مخوَّل الآن', $l['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// M-N. ضيف غير مُخصَّص — الداعي الإضافي مرفوض دوماً، Owner/Admin مخوَّلان.
// ══════════════════════════════════════════════════════════════════════

$m = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000003', 9003, 'normal');
check('M1. Additional Inviter: مرفوض لضيف غير مُخصَّص', $m['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

$n = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000003', 9001, 'normal');
check('N1. Owner: مخوَّل لضيف غير مُخصَّص طالما موجود فعلياً', $n['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// O-U. مطابقة النيّة مقابل حالة إرسال D2-W2 — نستخدم Owner (9001) كفاعل
// مخوَّل ثابت لعزل هذا الجزء عن منطق التفويض المُختبَر أعلاه بالكامل.
// ══════════════════════════════════════════════════════════════════════

// O: not_sent + normal → authorized (ضيف 966500000001 لم تُنشأ له أي محاولة إرسال بعد).
$o = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9001, 'normal');
check('O1. not_sent + normal: authorized', $o['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);

// P/Q: provider_accepted (ضيف جديد مخصَّص لـOwner، أُرسل له بنجاح فعلاً عبر D2-W1 الحقيقية).
seed_invitation(EVT, '966500000101', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000101', GROUP_1);
$pq_claim = PGE_Invitation_Send_Ledger::claim(EVT, '966500000101', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check_true('P0. تهيئة: claim() الأولي ينجح', ($pq_claim['result'] ?? null) === 'claimed');
check_true('P0b. تهيئة: finalize_success() ينجح', PGE_Invitation_Send_Ledger::finalize_success($pq_claim['log_id']));

$p = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000101', 9001, 'normal');
check('P1. provider_accepted + normal: already_sent (محجوب)', $p['result'] ?? null, PGE_Invitation_Send_Application::RESULT_ALREADY_SENT);

$q = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000101', 9001, 'resend');
check('Q1. provider_accepted + resend: authorized', $q['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);

// R: ambiguous_transport_error.
seed_invitation(EVT, '966500000102', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000102', GROUP_1);
$r_claim = PGE_Invitation_Send_Ledger::claim(EVT, '966500000102', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check_true('R0. تهيئة: claim() الأولي ينجح', ($r_claim['result'] ?? null) === 'claimed');
check_true('R0b. تهيئة: finalize_failure(ambiguous) ينجح', PGE_Invitation_Send_Ledger::finalize_failure($r_claim['log_id'], PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR));

$r_normal = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000102', 9001, 'normal');
check('R1. ambiguous + normal: محجوب (resend_required)', $r_normal['result'] ?? null, PGE_Invitation_Send_Application::RESULT_RESEND_REQUIRED);

$r_resend = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000102', 9001, 'resend');
check('R2. ambiguous + resend: authorized', $r_resend['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);

// S: pending/send_requested — كلتا النيّتين محجوبتان.
seed_invitation(EVT, '966500000103', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000103', GROUP_1);
$s_claim = PGE_Invitation_Send_Ledger::claim(EVT, '966500000103', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check_true('S0. تهيئة: claim() الأولي ينجح (يبقى pending، بلا finalize)', ($s_claim['result'] ?? null) === 'claimed');

$s_normal = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000103', 9001, 'normal');
check('S1. send_requested + normal: already_in_progress', $s_normal['result'] ?? null, PGE_Invitation_Send_Application::RESULT_ALREADY_IN_PROGRESS);

$s_resend = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000103', 9001, 'resend');
check('S2. send_requested + resend: already_in_progress أيضاً (كلتا النيّتين محجوبتان)', $s_resend['result'] ?? null, PGE_Invitation_Send_Application::RESULT_ALREADY_IN_PROGRESS);

// ══════════════════════════════════════════════════════════════════════
// T. قيمة الحصة نفسها لا تؤثر على التفويض — فقط وجود المسند (IS NOT NULL).
// ══════════════════════════════════════════════════════════════════════

$t1 = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9003, 'normal'); // allocated_quota=10
$t2 = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9010, 'normal'); // allocated_quota=1000
check('T1. حصة=10: authorized', $t1['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);
check('T2. حصة=1000 (نفس التركيب، قيمة مختلفة تماماً): authorized كذلك', $t2['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// Y. D2-W6A Fix Pass 1 — cancelled (حالة صريحة أولى الدرجة منذ الآن، لا
//    Fallthrough افتراضي). يتحقّق أن التركيب القائم فعلياً فوق أعلام D2-W2
//    (normal_send_allowed/resend_required) ينتج السلوك الصحيح تلقائياً —
//    بلا أي تعديل على class-pge-invitation-send-application.php نفسه.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000104', '2026-08-22 09:00:00');
seed_assignment(EVT, '966500000104', GROUP_1);
$y_claim = PGE_Invitation_Send_Ledger::claim(EVT, '966500000104', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check_true('Y0. تهيئة: claim() الأولي ينجح', ($y_claim['result'] ?? null) === 'claimed');
check_true('Y0b. تهيئة: finalize_cancelled() ينجح (Cartat لم يُستدعَ إطلاقاً هنا)', PGE_Invitation_Send_Ledger::finalize_cancelled($y_claim['log_id']));

$y_normal = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000104', 9001, 'normal');
check('Y1. cancelled + normal: authorized (نفس معاملة failed)', $y_normal['result'] ?? null, PGE_Invitation_Send_Application::RESULT_AUTHORIZED);

$y_resend = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000104', 9001, 'resend');
check('Y2. cancelled + resend: invalid_state (لا معنى لإعادة إرسال محاولة لم تُرسَل قط)', $y_resend['result'] ?? null, PGE_Invitation_Send_Application::RESULT_INVALID_STATE);

// ══════════════════════════════════════════════════════════════════════
// U. هوية المُنشئ التاريخي لا تُستخدَم إطلاقاً — تأكيد إضافي عبر توقيع
// الدالة نفسها (لا معامل لهوية منشئ أصلاً) + الاختباران I/J أعلاه فعلياً.
// ══════════════════════════════════════════════════════════════════════

$reflection = new ReflectionMethod('PGE_Invitation_Send_Application', 'authorize_send_for_actor');
$param_names = array_map(function ($p) { return $p->getName(); }, $reflection->getParameters());
check_true('U1. لا معامل لهوية "منشئ" في توقيع authorize_send_for_actor() إطلاقاً', !array_intersect($param_names, ['creator_user_id', 'created_by', 'original_actor_user_id', 'original_inviter_id']));

// ══════════════════════════════════════════════════════════════════════
// V. EC1: مناسبة غير موجودة مقابل فاعل غير مخوَّل لمناسبة حقيقية — نفس
// النتيجة الخارجية بالضبط.
// ══════════════════════════════════════════════════════════════════════

$v_nonexistent = PGE_Invitation_Send_Application::authorize_send_for_actor(999999, '966500000001', 9001, 'normal');
check('V1. مناسبة غير موجودة: not_authorized', $v_nonexistent['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);

// 9099 = مستخدم حقيقي بلا أي عضوية إطلاقاً في مناسبة حقيقية (غريب تماماً).
$v_stranger = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9099, 'normal');
check('V2. فاعل غريب تماماً لمناسبة حقيقية: not_authorized (نفس رمز V1)', $v_stranger['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_AUTHORIZED);
check('V3. V1 وV2 متطابقان خارجياً تماماً — EC1 محفوظ', $v_nonexistent['result'], $v_stranger['result']);

// ── ملاحظة موثَّقة (راجع توثيق رأس class-pge-invitation-send-application.php
// أيضاً): وجود المناسبة (event existence) مُطابَق تماماً لـEC1 هنا (V1=V2
// حرفياً). أما "وجود الضيف نفسه" (not_found) فتمييز داخلي مقصود في هذه
// المرحلة فقط — انظر التحقق التالي الذي يوثِّق هذا التمييز صراحة، لا كخلل:
$v_ghost_guest = PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000999', 9003, 'normal');
check('V4. (توثيقي) ضيف غير موجود إطلاقاً لداعٍ خارج نطاقه أصلاً: not_found — تمييز داخلي مقصود، يجب طيّه خارجياً في طبقة نقل مستقبلية (راجع توثيق الملف)', $v_ghost_guest['result'] ?? null, PGE_Invitation_Send_Application::RESULT_NOT_FOUND);

// ══════════════════════════════════════════════════════════════════════
// W. لا أثر جانبي إطلاقاً — لا claim، لا صفوف جديدة، لا أقفال.
// ══════════════════════════════════════════════════════════════════════

$rows_before = $wpdb->message_log;
$locks_before = $wpdb->held_locks;
for ($rep = 0; $rep < 5; $rep++) {
    PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9001, 'normal'); // authorized
    PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000001', 9004, 'normal'); // not_authorized
    PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000101', 9001, 'resend');  // authorized (provider_accepted)
    PGE_Invitation_Send_Application::authorize_send_for_actor(EVT, '966500000999', 9003, 'normal');  // not_found
}
check('W1. لا تغيير على أي صف message_log بعد استدعاءات متكررة لـauthorize_send_for_actor()', $wpdb->message_log, $rows_before);
check('W2. لا قفل مأخوذ إطلاقاً عبر authorize_send_for_actor() (لا GET_LOCK)', $wpdb->held_locks, $locks_before);

// ══════════════════════════════════════════════════════════════════════
// X. حدود المرحلة (Phase Boundary) داخل class-pge-invitation-send-
// application.php — مُصحَّحة في D2-W4 Fix Pass 1.
//
// ملاحظة تصحيح مهمة: النسخة الأصلية من X1 (D2-W3) كانت تفحص أن الملف
// **كله** لا يحتوي "::claim(" إطلاقاً — قيد كان صحيحاً حصراً طالما ظل
// الملف تفويضاً/قراءة بحتة بلا أي طفرة (نطاق D2-W3 الأصلي). في D2-W4
// أُضيفت request_send_for_actor() **عمداً وبموافقة صريحة** كحدود الطفرة
// الوحيدة المعتمدة فوق PGE_Invitation_Send_Ledger::claim() — فالقيد
// القديم على مستوى الملف كله أصبح مُتجاوَزاً (Stale) بحكم التصميم، لا
// خللاً. العقد الحقيقي الذي يجب أن يبقى صحيحاً إلى الأبد ليس "لا claim()
// في الملف إطلاقاً" بل: (أ) authorize_send_for_actor() تبقى قراءة/تفويض
// بحتاً بلا أي طفرة، (ب) request_send_for_actor() فقط هي حدود الطفرة
// المعتمدة، (ج) لا يوجد أي مسار طفرة آخر مُكرَّر أو مُسرَّب في أي تابع
// آخر بالملف. الفحوصات أدناه تُثبِت هذا العقد على مستوى **متن كل تابع
// على حدة** (مطابقة أقواس عبر token_get_all، لا بحثاً نصياً ساذجاً على
// الملف كله ولا افتراضاً بترتيب التوابع) — فتبقى صحيحة وحسّاسة لأي مستقبل
// يُدخل مسار طفرة إضافياً بالخطأ في تابع آخر، بينما تسمح بالمسار الوحيد
// المعتمد صراحة.
// ══════════════════════════════════════════════════════════════════════

/**
 * يستخرج المتن الحرفي (Body) لتابع واحد بالاسم من كود مصدر مُجرَّد من
 * التعليقات مسبقاً — عبر مطابقة الأقواس المعقوفة { } على مستوى التوكِنات
 * (token_get_all)، وليس بحثاً نصياً ساذجاً عن نهاية افتراضية (كالاعتماد
 * على "التابع التالي" الذي كان سيُخطئ لو تغيّر ترتيب التوابع لاحقاً).
 * يُعيد null إن لم يُعثر على التابع بالاسم المطلوب كتعريف توابع حقيقي —
 * بعد function مباشرة، وليس مجرد نص "method_name(" داخل استدعاء أو تعليق.
 */
function x_extract_method_body($stripped_code, $method_name)
{
    $tokens = token_get_all($stripped_code);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_STRING || $tok[1] !== $method_name) {
            continue;
        }
        $j = $i - 1;
        while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j--;
        }
        if ($j < 0 || !is_array($tokens[$j]) || $tokens[$j][0] !== T_FUNCTION) {
            continue; // اسم مطابق لكنه ليس تعريف تابع (مثلاً استدعاء عادي)
        }

        $open = -1;
        for ($k = $i + 1; $k < $n; $k++) {
            $val = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            if ($val === '{') { $open = $k; break; }
            if ($val === ';') { $open = -1; break; } // تعريف بلا متن — غير متوقَّع هنا
        }
        if ($open === -1) {
            continue;
        }

        $depth = 0;
        $close = -1;
        for ($m = $open; $m < $n; $m++) {
            $val = is_array($tokens[$m]) ? $tokens[$m][1] : $tokens[$m];
            if ($val === '{') {
                $depth++;
            } elseif ($val === '}') {
                $depth--;
                if ($depth === 0) { $close = $m; break; }
            }
        }
        if ($close === -1) {
            continue;
        }

        $body = '';
        for ($p = $open; $p <= $close; $p++) {
            $body .= is_array($tokens[$p]) ? $tokens[$p][1] : $tokens[$p];
        }
        return $body;
    }
    return null;
}

/**
 * يُعيد أسماء كل التوابع المُعرَّفة فعلياً (function ...() {...}) في كود
 * مصدر مُجرَّد من التعليقات — يُستخدم لفحص "لا تابع آخر" ديناميكياً، بلا
 * الحاجة لسرد أسماء كل التوابع الحالية والمستقبلية يدوياً.
 */
function x_list_method_names($stripped_code)
{
    $tokens = token_get_all($stripped_code);
    $n = count($tokens);
    $names = [];
    for ($i = 0; $i < $n; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $names[] = $tokens[$j][1];
            }
        }
    }
    return array_values(array_unique($names));
}

$app_src = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-send-application.php');
$app_code_only = pge_strip_php_comments($app_src);

// X1. authorize_send_for_actor() نفسها تبقى تفويضاً/قراءة للحالة بحتة —
// بلا أي طفرة إطلاقاً (مطابقة على متن التابع فقط، المُستخرَج بمطابقة
// الأقواس أعلاه — لا على الملف كله).
$authorize_body = x_extract_method_body($app_code_only, 'authorize_send_for_actor');
$readonly_forbidden = [
    '::claim(', '::finalize_success(', '::finalize_failure(',
    'GET_LOCK', 'RELEASE_LOCK', '->insert(', '->update(',
    'wp_ajax_', 'add_action', 'add_filter', 'wp_send_json',
    'Cartat', 'UltraMsg', 'Queue', 'Worker',
];
$readonly_found = [];
foreach ($readonly_forbidden as $term) {
    if ($authorize_body !== null && strpos($authorize_body, $term) !== false) {
        $readonly_found[] = $term;
    }
}
check_true('X1. authorize_send_for_actor() موجود ويبقى تفويضاً/قراءة حالة بحتة فقط — بلا claim/finalize/GET_LOCK/insert/update/queue/worker/Cartat/UltraMsg/AJAX داخل متنه الفعلي', $authorize_body !== null && empty($readonly_found));

// X2. request_send_for_actor() هي حدود الطفرة الوحيدة المعتمدة — يجب أن
// تستدعي claim() فعلياً، وإلا فهي بلا جدوى فعلياً كحدود مطالبة.
$request_body = x_extract_method_body($app_code_only, 'request_send_for_actor');
check_true('X2. request_send_for_actor() موجود ويستدعي PGE_Invitation_Send_Ledger::claim() فعلياً كحدود الطفرة المعتمدة الوحيدة', $request_body !== null && strpos($request_body, '::claim(') !== false);

// X3. لكن request_send_for_actor() نفسها لا تتجاوز حدودها المصرَّح بها:
// لا finalize_success/finalize_failure (خارج نطاق D2-W4 بالكامل)، ولا
// GET_LOCK/RELEASE_LOCK/insert/update مباشرة (تلك داخلية للـLedger نفسها
// فقط، تُستدعى عبر claim() لا مباشرة)، ولا Queue/Worker/Cartat/UltraMsg/
// AJAX أياً كان شكلها.
$request_forbidden = [
    '::finalize_success(', '::finalize_failure(',
    'GET_LOCK', 'RELEASE_LOCK', '->insert(', '->update(',
    'wp_ajax_', 'add_action', 'add_filter', 'wp_send_json',
    'Cartat', 'UltraMsg', 'Queue', 'Worker',
];
$request_found = [];
foreach ($request_forbidden as $term) {
    if ($request_body !== null && strpos($request_body, $term) !== false) {
        $request_found[] = $term;
    }
}
check_true('X3. request_send_for_actor() لا تتجاوز claim() نفسها: لا finalize/GET_LOCK/RELEASE_LOCK/insert/update مباشرة، ولا Queue/Worker/Cartat/UltraMsg/AJAX داخل متنها', empty($request_found));

// X4. مسار طفرة claim() واحد فقط حرفياً في كامل الملف — إثبات أنه غير
// مُكرَّر أو مُسرَّب لأي مكان آخر.
$claim_occurrences = substr_count($app_code_only, '::claim(');
check('X4. "::claim(" يظهر مرة واحدة فقط حرفياً في كامل الملف (لا تكرار/تسرُّب لمسار الطفرة)', $claim_occurrences, 1);

// X5. تحقُّق ديناميكي على كل توابع الكلاس الفعلية (أياً كان اسمها، حالياً
// أو أي تابع يُضاف مستقبلاً بالخطأ): لا يجوز أن يحتوي متن أي تابع
// claim()/finalize_*()/Queue/Worker/Cartat/UltraMsg، عدا استثناء واحد
// وحيد مصرَّح به صراحة: request_send_for_actor() تحديدااً لـ"::claim("
// فقط دون سواها من المصطلحات.
$all_methods = x_list_method_names($app_code_only);
$violations_x5 = [];
foreach ($all_methods as $method_name) {
    $body = x_extract_method_body($app_code_only, $method_name);
    if ($body === null) {
        continue;
    }
    foreach (['::claim(', '::finalize_success(', '::finalize_failure(', 'Queue', 'Worker', 'Cartat', 'UltraMsg'] as $term) {
        if (strpos($body, $term) === false) {
            continue;
        }
        $is_allowed = ($method_name === 'request_send_for_actor' && $term === '::claim(');
        if (!$is_allowed) {
            $violations_x5[] = $method_name . ' :: ' . $term;
        }
    }
}
check_true('X5. لا تابع آخر بالكلاس (فحص ديناميكي على كل التوابع الفعلية، بلا سرد أسماء يدوي) يحتوي claim/finalize/Queue/Worker/Cartat/UltraMsg، عدا request_send_for_actor() لـ"::claim(" فقط', empty($violations_x5));

// X6. حظر شامل بلا شرط على الملف كله لمصطلحات لا مكان لها هنا إطلاقاً —
// بصرف النظر عن التابع: GET_LOCK/RELEASE_LOCK/insert/update داخلية
// للـLedger نفسها فقط ويجب ألا تظهر في طبقة التطبيق مطلقاً مهما كان
// السياق، وfinalize_*()/AJAX/UI/Cartat/UltraMsg خارج نطاق D2-W3+D2-W4
// معاً بالكامل ولن تدخل هذا الملف مطلقاً في هذه المرحلة.
$whole_file_forbidden = [
    'wp_ajax_', 'add_action', 'add_filter',
    'GET_LOCK', 'RELEASE_LOCK',
    '->insert(', '->update(',
    '::finalize_success(', '::finalize_failure(',
    'Cartat', 'UltraMsg',
    'wp_send_json',
];
$whole_file_found = [];
foreach ($whole_file_forbidden as $term) {
    if (strpos($app_code_only, $term) !== false) {
        $whole_file_found[] = $term;
    }
}
check_true('X6. لا AJAX/UI/GET_LOCK/RELEASE_LOCK/insert/update مباشرة/finalize_*()/Cartat/UltraMsg في أي مكان من الملف كله، بصرف النظر عن التابع', empty($whole_file_found));

$auth_src = file_get_contents(__DIR__ . '/../includes/class-pge-event-access-authorization.php');
$auth_code_only = pge_strip_php_comments($auth_src);
check_true('X7. القدرة can_send_guest_invitation() (Authorization Core) لا تحتوي أي I/O (لا $wpdb، لا current_user_can) — الملف نفسه بلا I/O أصلاً، ولم يُعدَّل إطلاقاً في D2-W4 (راجع تقرير D2-W4 Fix Pass 1)', strpos($auth_code_only, '$wpdb') === false && strpos($auth_code_only, 'current_user_can') === false);

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
