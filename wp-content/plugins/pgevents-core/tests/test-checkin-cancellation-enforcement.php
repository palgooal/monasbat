<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـEntry Check-in Supervisors، "Phase
 * 9A Final Fix" — Fix 2: "Enforce Cancellation in the Real Check-in Path".
 * يستدعي مباشرة الكود الحقيقي غير المُعدَّل منطقياً: PGE_Guest_Resolution_
 * Service::resolve_from_qr()/resolve_by_rsvp_id()/resolve_by_phone() (يحملن
 * الآن حارس الإلغاء الجديد)، PGE_Checkin_Recorder::record_guest_checkin()
 * (غير مُعدَّلة إطلاقاً)، وPGE_Invitation_Repository::get_invitation()/create()/
 * cancel() (Phase 9، غير مُعدَّلة). لا إعادة تنفيذ لأي من منطقهما هنا —
 * "Execute the real activation code."
 *
 * السيناريوهات المطلوبة صراحةً (الاختبارات 1-8 من RFC "Phase 9A Final Fix"):
 *   1. رفض دعوة مُلغاة عبر مسار QR.
 *   2. رفض دعوة مُلغاة عبر البحث اليدوي (الهاتف).
 *   3. عدم استدعاء Recorder إطلاقاً لدعوة مُلغاة.
 *   4. حالة RSVP/الحضور تبقى بلا تغيير.
 *   5. لا يُكتَب أي سطر تدقيق حضور (pge_checkin_audit_log).
 *   6. الدعوة النشطة تُسجَّل حضورها طبيعياً كالسابق تماماً.
 *   7. الدعوة القديمة (بلا _pge_invitation_status إطلاقاً) تُسجَّل حضورها طبيعياً.
 *   8. عزل المناسبات المختلفة سليم (دعوة مُلغاة في مناسبة لا تؤثر على مناسبة أخرى).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-checkin-cancellation-enforcement.php
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
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_hash')) {
    function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed'); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('pge_generate_invite_code')) {
    function pge_generate_invite_code()
    {
        static $n = 0;
        $n++;
        return 'CODE-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}

// ── Posts + Post Meta وهميان (لـ_pge_invited_guests و_pge_invitation_status) ──
$GLOBALS['__test_posts'] = [];
$GLOBALS['__test_post_meta'] = [];
function set_test_event_full($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) ['ID' => $event_id, 'post_type' => $post_type, 'post_author' => $author_id];
    if (!isset($GLOBALS['__test_post_meta'][$event_id])) {
        $GLOBALS['__test_post_meta'][$event_id] = [];
    }
}
function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
function get_post_field($field, $post_id) { $p = get_post($post_id); return $p ? ($p->{$field} ?? '') : ''; }
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

/** إدراج ضيف مباشرة ضمن _pge_invited_guests (بلا استدعاء save_map). */
function seed_invited_guest($event_id, $phone, $name, $code)
{
    $map = $GLOBALS['__test_post_meta'][$event_id]['_pge_invited_guests'] ?? [];
    $map[$phone] = ['phone' => $phone, 'name' => $name, 'note' => '', 'code' => $code];
    $GLOBALS['__test_post_meta'][$event_id]['_pge_invited_guests'] = $map;
}

// ============================================================================
// Fake $wpdb — wp_pge_event_rsvps + wp_pge_checkin_audit_log + mon_event_supervisors
// ============================================================================
class Fake_Wpdb_Checkin_Cancellation
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $rsvps = [];
    public $audit_log = [];
    public $supervisors = [];

    private $rsvps_next_id = 1;
    private $audit_log_next_id = 1;
    private $supervisors_next_id = 1;

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
        if (strpos($sql_or_table, $this->prefix . 'pge_event_rsvps') !== false) {
            return 'rsvps';
        }
        if (strpos($sql_or_table, $this->prefix . 'pge_checkin_audit_log') !== false) {
            return 'audit';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_event_supervisors') !== false) {
            return 'supervisors';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $which = $this->which_table($sql);

        if ($which === 'rsvps') {
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)\s+AND\s+event_id\s*=\s*(\d+)/i', $sql, $m)) {
                $id = (int) $m[1];
                $event_id = (int) $m[2];
                $row = $this->rsvps[$id] ?? null;
                return ($row && (int) $row['event_id'] === $event_id) ? $row : null;
            }
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
                return $this->rsvps[(int) $m[1]] ?? null;
            }
            if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+guest_phone\s*=\s*\'([^\']*)\'/i', $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                foreach ($this->rsvps as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['guest_phone'] === $phone) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        if ($which === 'supervisors') {
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
                return $this->supervisors[(int) $m[1]] ?? null;
            }
            return null;
        }

        return null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);

        if ($which === 'rsvps' && preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+guest_phone\s*=\s*\'([^\']*)\'\s+ORDER\s+BY\s+id\s+ASC/i', $sql, $m)) {
            $event_id = (int) $m[1];
            $phone = $m[2];
            $rows = array_values(array_filter($this->rsvps, function ($r) use ($event_id, $phone) {
                return (int) $r['event_id'] === $event_id && $r['guest_phone'] === $phone;
            }));
            usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
            return $rows;
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
        if ($which === 'audit') {
            $id = $this->audit_log_next_id++;
            $this->audit_log[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        if ($which === 'rsvps') {
            $id = $this->rsvps_next_id++;
            $this->rsvps[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        return false;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which !== 'rsvps') {
            return false;
        }
        $id = $where['id'] ?? null;
        if ($id === null || !isset($this->rsvps[$id])) {
            return 0;
        }
        foreach ($data as $k => $v) {
            $this->rsvps[$id][$k] = $v;
        }
        return 1;
    }

    /** إدراج مباشر لصف RSVP اختباري. */
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

    /** إدراج مباشر لصف إسناد مشرف "نشط" اختباري (يتخطّى دورة الدعوة/القبول الكاملة عمداً). */
    public function seed_active_assignment($event_id)
    {
        $id = $this->supervisors_next_id++;
        $this->supervisors[$id] = ['id' => $id, 'event_id' => $event_id, 'status' => 'active'];
        return $id;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Checkin_Cancellation();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php'; // تبعية class-pge-invitation-service.php غير مطلوبة هنا؛ Repository مستقل
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php';
require_once __DIR__ . '/../includes/class-pge-guest-resolution-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-checkin-recorder.php';

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
 * نفس تدفّق pge_supervisor_checkin_confirm_handler() الحقيقي (checkin-ajax.php)
 * حرفياً — resolve ثم (إن 'found' فقط) Recorder — بلا أي منطق موازٍ إضافي.
 * تُستخدَم بدل استدعاء AJAX handler الكامل لتفادي بناء طبقة nonce/middleware
 * الكاملة غير ذات الصلة بموضوع هذا الاختبار (حارس الإلغاء في طبقة الحلّ نفسها).
 */
function confirm_checkin_via_qr($event_id, $qr_payload, $actual_count, $assignment_id)
{
    $resolution = PGE_Guest_Resolution_Service::resolve_from_qr($event_id, $qr_payload);
    if (($resolution['result'] ?? '') !== 'found') {
        return $resolution;
    }
    return PGE_Checkin_Recorder::record_guest_checkin($assignment_id, $resolution['guest'], $actual_count, 'qr');
}

function confirm_checkin_by_phone($event_id, $phone, $actual_count, $assignment_id)
{
    $resolution = PGE_Guest_Resolution_Service::resolve_by_phone($event_id, $phone);
    if (($resolution['result'] ?? '') !== 'found') {
        return $resolution;
    }
    return PGE_Checkin_Recorder::record_guest_checkin($assignment_id, $resolution['guest'], $actual_count, 'manual');
}

// ============================================================================
// السيناريو 1: رفض دعوة مُلغاة عبر مسار QR
// ============================================================================
echo "=== السيناريو 1: رفض دعوة مُلغاة عبر QR ===\n";

set_test_event_full(9701, 8801);
$assignment1 = $wpdb->seed_active_assignment(9701);
seed_invited_guest(9701, '0511110001', 'ضيف مُلغى', 'ABCD-0001');
$rsvp1 = $wpdb->seed_rsvp(9701, '0511110001', ['companions' => 1, 'reply' => 'yes']);

// إنشاء دعوة عبر الطبقة الحقيقية (Phase 9) ثم إلغاؤها عبر نفس الطبقة الحقيقية.
PGE_Invitation_Repository::create(9701, '0511119999', 'مؤقت'); // يضمن schema سليم فقط، غير مستخدَم لاحقاً
$cancel_result1 = PGE_Invitation_Repository::cancel(9701, '0511110001', 'اعتذار الضيف');
check('1. الإلغاء عبر الطبقة الحقيقية: result=cancelled', $cancel_result1['result'] ?? null, 'cancelled');
check('1. حالة الدعوة (قراءة حقيقية) = cancelled', PGE_Invitation_Repository::get_invitation(9701, '0511110001')['invitation_status'] ?? null, 'cancelled');

// Phase 9B QR Architecture Final Fix: build_payload() يتطلّب الآن qr_version
// كمعامل ثالث صريح — 1 هنا يطابق DEFAULT_QR_VERSION (لا PGE_Invitation_
// Repository::regenerate_qr() استُدعيت لهذا الهاتف في هذا الملف، فالإصدار
// الإداري الفعلي يبقى الافتراضي).
$qr1 = PGE_Checkin_QR_Service::build_payload(9701, $rsvp1, 1);
$resolve1 = PGE_Guest_Resolution_Service::resolve_from_qr(9701, $qr1);
check('1. resolve_from_qr() لدعوة مُلغاة: result=cancelled', $resolve1['result'] ?? null, 'cancelled');
check('1. السبب invitation_cancelled', $resolve1['reason'] ?? null, 'invitation_cancelled');
check_true('1. لا "guest" في نتيجة الرفض (لم يُبنَ Guest Object إطلاقاً)', !isset($resolve1['guest']));

$confirm1 = confirm_checkin_via_qr(9701, $qr1, 2, $assignment1);
check('1. محاولة تأكيد الحضور عبر QR لدعوة مُلغاة تُرفَض بنفس السبب', $confirm1['reason'] ?? null, 'invitation_cancelled');
check_true('1. النتيجة ليست checked_in', ($confirm1['result'] ?? '') !== 'checked_in');

// ============================================================================
// السيناريو 2: رفض دعوة مُلغاة عبر البحث اليدوي (الهاتف)
// ============================================================================
echo "\n=== السيناريو 2: رفض دعوة مُلغاة عبر البحث اليدوي ===\n";

$resolve2 = PGE_Guest_Resolution_Service::resolve_by_phone(9701, '0511110001');
check('2. resolve_by_phone() لدعوة مُلغاة: result=cancelled', $resolve2['result'] ?? null, 'cancelled');
check('2. السبب invitation_cancelled', $resolve2['reason'] ?? null, 'invitation_cancelled');

$confirm2 = confirm_checkin_by_phone(9701, '0511110001', 2, $assignment1);
check('2. تأكيد يدوي بالهاتف لدعوة مُلغاة يُرفَض بنفس السبب', $confirm2['reason'] ?? null, 'invitation_cancelled');
check_true('2. لا استدعاء ناجح للـRecorder (لا نتيجة checked_in)', ($confirm2['result'] ?? '') !== 'checked_in');

// ============================================================================
// السيناريو 3: عدم استدعاء Recorder إطلاقاً لدعوة مُلغاة (إثبات مباشر)
// ============================================================================
echo "\n=== السيناريو 3: عدم استدعاء Recorder ===\n";

// نقطة الإثبات: لو استُدعي Recorder فعلياً بأي شكل لهذا الـrsvp_id، لكانت
// checked_in قد أصبحت 1 (Recorder هو الكاتب الوحيد المسموح لهذا العمود —
// راجع توثيق class-pge-checkin-recorder.php). بما أن resolve_* رفضت **قبل**
// إعادة "guest"، ولا مسار في confirm_checkin_via_qr()/confirm_checkin_by_phone()
// يستدعي Recorder إلا بعد التحقق من result==='found' — checked_in تبقى كما هي.
check('3. checked_in لصف RSVP المُلغى لا يزال 0 (Recorder لم يُستدعَ)', (int) ($wpdb->rsvps[$rsvp1]['checked_in'] ?? -1), 0);
check_true('3. لا سطر تدقيق حضور بأي rsvp_id يخص هذا الصف', count(array_filter($wpdb->audit_log, function ($r) use ($rsvp1) {
    return (int) ($r['rsvp_id'] ?? 0) === $rsvp1;
})) === 0);

// ============================================================================
// السيناريو 4: حالة RSVP/الحضور تبقى بلا تغيير
// ============================================================================
echo "\n=== السيناريو 4: حالة RSVP/الحضور بلا تغيير ===\n";

check('4. reply لم يتغيَّر (يبقى yes كما زُرِع)', $wpdb->rsvps[$rsvp1]['reply'] ?? null, 'yes');
// ملاحظة: `??` يُعامِل null الفعلية كـ"غير موجودة" أيضاً — نستخدم array_key_exists
// + قراءة مباشرة لتفادي تقييم خاطئ (نفس الخطأ المُصحَّح سابقاً في اختبارات أخرى).
check_true('4. checked_in_at لا يزال null', array_key_exists('checked_in_at', $wpdb->rsvps[$rsvp1]) && $wpdb->rsvps[$rsvp1]['checked_in_at'] === null);
check_true('4. checked_in_by_assignment_id لا يزال null', array_key_exists('checked_in_by_assignment_id', $wpdb->rsvps[$rsvp1]) && $wpdb->rsvps[$rsvp1]['checked_in_by_assignment_id'] === null);
check_true('4. checkin_method لا يزال null', array_key_exists('checkin_method', $wpdb->rsvps[$rsvp1]) && $wpdb->rsvps[$rsvp1]['checkin_method'] === null);
check_true('4. actual_entered_count لا يزال null', array_key_exists('actual_entered_count', $wpdb->rsvps[$rsvp1]) && $wpdb->rsvps[$rsvp1]['actual_entered_count'] === null);

// ============================================================================
// السيناريو 5: لا يُكتَب أي سطر تدقيق حضور (pge_checkin_audit_log)
// ============================================================================
echo "\n=== السيناريو 5: لا سطر تدقيق حضور ===\n";

check('5. جدول تدقيق الحضور فارغ تماماً (لا أي محاولة رفعت سطراً)', count($wpdb->audit_log), 0);

// ============================================================================
// السيناريو 6: الدعوة النشطة تُسجَّل حضورها طبيعياً (Active still works)
// ============================================================================
echo "\n=== السيناريو 6: الدعوة النشطة تعمل طبيعياً ===\n";

set_test_event_full(9702, 8802);
$assignment2 = $wpdb->seed_active_assignment(9702);
seed_invited_guest(9702, '0511110002', 'ضيف نشط', 'ABCD-0002');
$rsvp2 = $wpdb->seed_rsvp(9702, '0511110002', ['companions' => 0, 'reply' => 'yes']);
PGE_Invitation_Repository::create(9702, '0511119998', 'مؤقت٢'); // يضمن وجود schema فقط

check('6. حالة الدعوة النشطة (Phase 9) = active افتراضياً', PGE_Invitation_Repository::get_invitation(9702, '0511110002')['invitation_status'] ?? null, 'active');

$qr2 = PGE_Checkin_QR_Service::build_payload(9702, $rsvp2, 1);
$resolve6 = PGE_Guest_Resolution_Service::resolve_from_qr(9702, $qr2);
check('6. resolve_from_qr() لدعوة نشطة: found', $resolve6['result'] ?? null, 'found');

$confirm6 = confirm_checkin_via_qr(9702, $qr2, 1, $assignment2);
check('6. تسجيل حضور دعوة نشطة عبر QR: checked_in', $confirm6['result'] ?? null, 'checked_in');
check('6. checked_in فعلياً = 1 في الصف الحقيقي', (int) ($wpdb->rsvps[$rsvp2]['checked_in'] ?? 0), 1);
check('6. سطر تدقيق حضور واحد فعلياً لهذا rsvp_id', count(array_filter($wpdb->audit_log, function ($r) use ($rsvp2) {
    return (int) ($r['rsvp_id'] ?? 0) === $rsvp2;
})), 1);

// ============================================================================
// السيناريو 7: الدعوة القديمة (بلا _pge_invitation_status إطلاقاً) تعمل طبيعياً
// ============================================================================
echo "\n=== السيناريو 7: الدعوة القديمة (Legacy) تعمل طبيعياً ===\n";

set_test_event_full(9703, 8803);
$assignment3 = $wpdb->seed_active_assignment(9703);
// ضيف قديم: مُضاف مباشرة إلى _pge_invited_guests بلا أي استدعاء لـ
// PGE_Invitation_Repository::create() إطلاقاً — لا سجل _pge_invitation_status
// له على الإطلاق (يحاكي دعوة أُنشئت قبل Phase 9 بالكامل).
seed_invited_guest(9703, '0511110003', 'ضيف قديم', 'ABCD-0003');
$rsvp3 = $wpdb->seed_rsvp(9703, '0511110003', ['companions' => 2, 'reply' => 'yes']);

check_true('7. لا سجل _pge_invitation_status لهذا الضيف إطلاقاً (تحقّق من الفرضية)', PGE_Invitation_Repository::get_invitation(9703, '0511110003') === null || (PGE_Invitation_Repository::get_invitation(9703, '0511110003')['invitation_status'] ?? null) === 'active');

$qr3 = PGE_Checkin_QR_Service::build_payload(9703, $rsvp3, 1);
$resolve7 = PGE_Guest_Resolution_Service::resolve_from_qr(9703, $qr3);
check('7. resolve_from_qr() لدعوة قديمة بلا حالة إدارية: found (توافق قديم)', $resolve7['result'] ?? null, 'found');

$confirm7 = confirm_checkin_via_qr(9703, $qr3, 3, $assignment3);
check('7. تسجيل حضور دعوة قديمة: checked_in', $confirm7['result'] ?? null, 'checked_in');
check('7. checked_in فعلياً = 1', (int) ($wpdb->rsvps[$rsvp3]['checked_in'] ?? 0), 1);

// ============================================================================
// السيناريو 8: عزل المناسبات المختلفة
// ============================================================================
echo "\n=== السيناريو 8: عزل المناسبات المختلفة ===\n";

// نفس رقم الهاتف بالضبط في مناسبة مختلفة تماماً (9704) — نشطة هناك رغم أنها
// مُلغاة في 9701. الحالة الإدارية مُخزَّنة بمفتاح post meta *لكل مناسبة على
// حدة* (_pge_invitation_status) — لا يمكن تسريبها عبر المناسبات بنيوياً.
set_test_event_full(9704, 8804);
$assignment4 = $wpdb->seed_active_assignment(9704);
seed_invited_guest(9704, '0511110001', 'نفس الهاتف بمناسبة أخرى', 'ABCD-0004');
$rsvp4 = $wpdb->seed_rsvp(9704, '0511110001', ['companions' => 0, 'reply' => 'yes']);

check('8. حالة الدعوة لنفس الهاتف في مناسبة أخرى = active (لا تسريب إلغاء عبر المناسبات)', PGE_Invitation_Repository::get_invitation(9704, '0511110001')['invitation_status'] ?? null, 'active');

$qr4 = PGE_Checkin_QR_Service::build_payload(9704, $rsvp4, 1);
$resolve8 = PGE_Guest_Resolution_Service::resolve_from_qr(9704, $qr4);
check('8. resolve_from_qr() لنفس الهاتف في مناسبة أخرى: found', $resolve8['result'] ?? null, 'found');

$confirm8 = confirm_checkin_via_qr(9704, $qr4, 1, $assignment4);
check('8. تسجيل حضور في المناسبة الأخرى ينجح رغم إلغاء نفس الهاتف في 9701', $confirm8['result'] ?? null, 'checked_in');

// تأكيد إضافي: محاولة استخدام QR الخاص بالمناسبة 9701 (rsvp1، المُلغاة) ضد
// event_id=9704 (تلاعب بمناسبة مختلفة) — تُرفَض أصلاً عبر التوقيع/الانتماء
// (event_mismatch)، ليست عبر منطق الإلغاء، وهذا سلوك سابق غير مُعدَّل هنا.
$cross_event_attempt = PGE_Guest_Resolution_Service::resolve_from_qr(9704, $qr1);
check_true('8ب. استخدام QR مناسبة أخرى (9701) ضد 9704: مرفوض (ليس found)', ($cross_event_attempt['result'] ?? '') !== 'found');
// الصف الأصلي المُلغى (9701) يبقى كما هو تماماً بلا أي تأثير من هذه المحاولة.
check('8ب. الصف الأصلي (rsvp1) لا يزال checked_in=0', (int) ($wpdb->rsvps[$rsvp1]['checked_in'] ?? -1), 0);

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
