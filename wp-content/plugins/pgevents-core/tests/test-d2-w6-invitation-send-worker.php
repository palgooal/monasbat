<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ"D2-W6 — Worker-Time
 * Reauthorization + Cartat Transport Execution": PGE_Invitation_Send_Worker::
 * process_log_id() — أول طبقة في السلسلة تستدعي فعلياً PGE_Cartat_Transport،
 * فوق D2-W1 حتى D2-W5 القائمة فعلاً بلا أي تعديل عليها.
 *
 * يحمّل الملفات الحقيقية التالية دون أي تعديل عليها (فيما عدا الإضافة
 * الموثَّقة لـclass-pge-invitation-send-worker.php نفسه، الملف قيد الاختبار):
 *   - includes/class-pge-message-type.php
 *   - includes/class-pge-message-log.php
 *   - includes/class-pge-message-batch.php
 *   - includes/class-pge-invitation-send-ledger.php   (D2-W1، غير مُعدَّل هنا)
 *   - includes/class-pge-event-access-authorization.php (D2-W3، غير مُعدَّل هنا)
 *   - includes/class-pge-message-content-resolver.php (Messaging Phase 1، غير مُعدَّل هنا)
 *   - includes/class-pge-invitation-send-queue.php    (D2-W5، غير مُعدَّل هنا)
 *   - includes/class-pge-invitation-send-worker.php   (D2-W6 — قيد الاختبار)
 *
 * لا يُحمَّل class-pge-invitation-send-application.php (D2-W3/D2-W4) عمداً —
 * العامل لا يعتمد عليه إطلاقاً (راجع توثيق رأس ملف الإنتاج لسبب معماري
 * موثَّق)، ولا class-pge-cartat-transport.php الحقيقي — نسخة مزيَّفة مُتحكَّم
 * بها بالكامل مُعرَّفة أدناه بنفس اسم الصنف الحقيقي (نفس اصطلاح استبدال
 * PGE_Event_Access_Repository/PGE_Invitation_Repository القائم فعلاً في
 * اختبارات D2-W3/D2-W4/D2-W5 — لا نقطة حقن جديدة أُضيفت لكود الإنتاج).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. الخروج برمز 0 عند نجاح كل
 * الحالات، أو 1 عند فشل أي حالة.
 *
 * التشغيل: php tests/test-d2-w6-invitation-send-worker.php
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
// WP primitives — Context::resolve() ومسار بناء الرسالة يحتاجانها فقط.
// ══════════════════════════════════════════════════════════════════════

$GLOBALS['w6_posts'] = [];
$GLOBALS['w6_admins'] = [];
$GLOBALS['w6_post_meta'] = [];

function get_post_type($id) { return $GLOBALS['w6_posts'][$id]['type'] ?? false; }
function get_post_field($field, $id) { return $field === 'post_author' ? ($GLOBALS['w6_posts'][$id]['author'] ?? 0) : null; }
function user_can($user_id, $cap) { return $cap === 'administrator' && !empty($GLOBALS['w6_admins'][$user_id]); }

function get_post($id)
{
    if (!isset($GLOBALS['w6_posts'][$id])) {
        return null;
    }
    return (object) ['post_title' => (string) ($GLOBALS['w6_posts'][$id]['title'] ?? '')];
}

function get_post_meta($id, $key, $single = false)
{
    return $GLOBALS['w6_post_meta'][$id][$key] ?? '';
}

function get_the_post_thumbnail_url($id, $size = 'full')
{
    return $GLOBALS['w6_post_meta'][$id]['_thumbnail'] ?? '';
}

if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp) { return date($format, $timestamp); }
}

function pge_wa_get_templates($event_id)
{
    return ['invite' => '{guest_name}|{event_name}|{event_date_line}|{guest_phone}'];
}

function pge_wa_render_template($tpl, $vars)
{
    return strtr($tpl, [
        '{guest_name}'      => (string) ($vars['guest_name'] ?? ''),
        '{event_name}'      => (string) ($vars['event_name'] ?? ''),
        '{event_date_line}' => (string) ($vars['event_date_line'] ?? ''),
        '{guest_phone}'     => (string) ($vars['guest_phone'] ?? ''),
    ]);
}

function seed_event($event_id, $owner_user_id, $title = 'مناسبة الاختبار')
{
    $GLOBALS['w6_posts'][$event_id] = ['type' => 'pge_event', 'author' => $owner_user_id, 'title' => $title];
}

function seed_admin($user_id) { $GLOBALS['w6_admins'][$user_id] = true; }

function set_event_thumbnail($event_id, $url)
{
    $GLOBALS['w6_post_meta'][$event_id]['_thumbnail'] = $url;
}

function clear_event_thumbnail($event_id)
{
    unset($GLOBALS['w6_post_meta'][$event_id]['_thumbnail']);
}

// ══════════════════════════════════════════════════════════════════════
// Fake PGE_Event_Access_Repository (نفس نمط D2-W3/D2-W4 حرفياً) — مع إضافة
// اختيارية لمحاكاة فشل قراءة دفاعي (WP_Error) لهاتف/مناسبة معيَّنة (اختبار Y2).
// ══════════════════════════════════════════════════════════════════════

class PGE_Event_Access_Repository
{
    public static $memberships = [];
    public static $membership_groups = [];
    public static $assignments = [];
    public static $force_assignment_error = [];

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
        if (!empty(self::$force_assignment_error[$key])) {
            return new WP_Error('database_error', 'محاكاة فشل قراءة');
        }
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

function force_assignment_error($event_id, $phone, $enabled = true)
{
    $key = ((int) $event_id) . '|' . (string) $phone;
    if ($enabled) {
        PGE_Event_Access_Repository::$force_assignment_error[$key] = true;
    } else {
        unset(PGE_Event_Access_Repository::$force_assignment_error[$key]);
    }
}

// ══════════════════════════════════════════════════════════════════════
// Fake PGE_Invitation_Repository — دعوة حالية (invited_at + name) لكل هاتف.
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

function seed_invitation($event_id, $phone, $invited_at, $name = 'ضيف الاختبار')
{
    $key = ((int) $event_id) . '|' . (string) $phone;
    PGE_Invitation_Repository::$invitations[$key] = ['invited_at' => $invited_at, 'name' => $name];
}

function delete_invitation($event_id, $phone)
{
    $key = ((int) $event_id) . '|' . (string) $phone;
    unset(PGE_Invitation_Repository::$invitations[$key]);
}

// ══════════════════════════════════════════════════════════════════════
// add_option/get_option/delete_option — نفس نمط D2-W5 حرفياً (WP Options
// غير Autoloaded، غير Transients) — يحتاجها class-pge-invitation-send-
// queue.php الحقيقي غير المُعدَّل هنا.
// ══════════════════════════════════════════════════════════════════════

$GLOBALS['w6_options'] = [];

if (!function_exists('add_option')) {
    function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
    {
        if (array_key_exists($name, $GLOBALS['w6_options'])) {
            return false;
        }
        $GLOBALS['w6_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['w6_options']) ? $GLOBALS['w6_options'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = 'yes')
    {
        $GLOBALS['w6_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        if (!array_key_exists($name, $GLOBALS['w6_options'])) {
            return false;
        }
        unset($GLOBALS['w6_options'][$name]);
        return true;
    }
}

// ══════════════════════════════════════════════════════════════════════
// Fake $wpdb — pge_message_log + محاكاة GET_LOCK/RELEASE_LOCK حقيقية (نفس
// نمط D2-W1/D2-W4 حرفياً)، مع إضافة قابلية محاكاة فشل تحديث (UPDATE) عابر
// لمرة واحدة فقط (اختبار M — "نجح النقل لكن فشل الحفظ المحلي").
// ══════════════════════════════════════════════════════════════════════

class Fake_Wpdb_D2W6
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $message_log = [];
    private $next_id = 1;

    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];

    /** يجعل استدعاء update() التالي فقط يفشل (0 صفوف)، ثم يعود لسلوكه الطبيعي تلقائياً. */
    public $force_update_failure_once = false;

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

    /** نداءات GET_LOCK الناجحة فقط (مُنحت فعلياً) — لهذه وحدها يجب أن يقابلها تحرير لاحق. */
    public $successful_lock_acquire_log = [];

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $name = $m[1];
            // كل محاولة (ناجحة أو محجوبة) تُسجَّل هنا لأغراض التشخيص — لكن
            // محاولة محجوبة (القفل محجوز فعلاً لدى طرف آخر) لا تُنشئ التزاماً
            // بتحرير لاحق من جانبنا (لم نأخذ القفل أصلاً)، لذلك لا تُحسَب ضمن
            // successful_lock_acquire_log أدناه (راجع اختبار Z2).
            $this->lock_acquire_log[] = $name;
            if (isset($this->held_locks[$name])) {
                return '0';
            }
            $this->held_locks[$name] = true;
            $this->successful_lock_acquire_log[] = $name;
            return '1';
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

        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $lm)) {
            $filtered = array_slice($filtered, 0, (int) $lm[1]);
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
        if ($this->force_update_failure_once) {
            $this->force_update_failure_once = false;
            return 0; // فشل تحديث حقيقي مُحاكى لمرة واحدة فقط — لا شيء يُطبَّق.
        }

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
$wpdb = new Fake_Wpdb_D2W6();

// ══════════════════════════════════════════════════════════════════════
// Fake PGE_Cartat_Transport — بديل كامل التحكم بلا أي HTTP فعلي، بنفس اسم
// الصنف الحقيقي (نفس اصطلاح استبدال الاعتماديات القائم فعلياً في اختبارات
// D2-W3/D2-W4/D2-W5). يُعرَّف هنا بدلاً من تحميل includes/class-pge-cartat-
// transport.php الحقيقي — لا تعديل على الملف الحقيقي، ولا نقطة حقن جديدة في
// كود الإنتاج (PGE_Invitation_Send_Worker::resolve_transport() يستدعي
// `new PGE_Cartat_Transport()` فقط، فيحصل هنا على هذه النسخة المزيَّفة تلقائياً
// عبر تحميل هذا الملف قبل ملف العامل).
// ══════════════════════════════════════════════════════════════════════

class PGE_Cartat_Transport
{
    public static $send_count = 0;
    public static $has_credentials = true;
    public static $next_outcome = 'accepted'; // 'accepted' | 'rejected' | 'ambiguous'
    public static $last_method = null;        // 'text' | 'media'
    public static $last_number = null;
    public static $last_message = null;

    public static function reset_all()
    {
        self::$send_count = 0;
        self::$has_credentials = true;
        self::$next_outcome = 'accepted';
        self::$last_method = null;
        self::$last_number = null;
        self::$last_message = null;
    }

    public function has_credentials(): bool { return self::$has_credentials; }

    public function format_number(string $phone): string { return '00' . $phone; }

    public function send_text(string $number, string $message): ?array
    {
        self::$send_count++;
        self::$last_method = 'text';
        self::$last_number = $number;
        self::$last_message = $message;
        return self::simulated_result();
    }

    public function send_media(string $number, string $media_url, string $caption = ''): ?array
    {
        self::$send_count++;
        self::$last_method = 'media';
        self::$last_number = $number;
        self::$last_message = $caption;
        return self::simulated_result();
    }

    public function interpret_result($result): string
    {
        if ($result === null) {
            return 'transport_error';
        }
        if ((isset($result['status']) && $result['status'] === 'error')
            || (isset($result['success']) && $result['success'] === false)) {
            return 'rejected';
        }
        return 'accepted';
    }

    private static function simulated_result(): ?array
    {
        if (self::$next_outcome === 'ambiguous') {
            return null;
        }
        if (self::$next_outcome === 'rejected') {
            return ['status' => 'error'];
        }
        return ['status' => 'sent'];
    }
}

require_once __DIR__ . '/../includes/class-pge-message-type.php';
require_once __DIR__ . '/../includes/class-pge-message-log.php';
require_once __DIR__ . '/../includes/class-pge-message-batch.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-ledger.php';
require_once __DIR__ . '/../includes/class-pge-event-access-authorization.php';
require_once __DIR__ . '/../includes/class-pge-message-content-resolver.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-queue.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-worker.php';

// ── أدوات الفحص (نفس نمط D2-W1/D2-W4/D2-W5) ──────────────────────────

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

function seed_log_row($id, array $overrides = [])
{
    global $wpdb;
    $defaults = [
        'id'                   => $id,
        'event_id'             => 8001,
        'rsvp_id'              => null,
        'lifecycle_started_at' => '2026-08-22 09:00:00',
        'guest_phone'          => '966500000001',
        'message_type'         => PGE_Message_Type::INVITATION,
        'batch_id'             => 'batch-' . $id,
        'status'               => PGE_Message_Log::STATUS_PENDING,
        'provider'             => null,
        'actor_user_id'        => 9001,
        'created_at'           => '2026-08-22 09:00:00',
        'sent_at'              => null,
    ];
    $wpdb->message_log[$id] = array_merge($defaults, $overrides, ['id' => $id]);
    return $wpdb->message_log[$id];
}

function inject_queue_item($log_id)
{
    $GLOBALS['w6_options']['pge_invsend_queue_' . $log_id] = ['log_id' => $log_id, 'queued_at' => $GLOBALS['__test_now']];
}

// ══════════════════════════════════════════════════════════════════════
// التثبيتات المشتركة
// ══════════════════════════════════════════════════════════════════════

const EVT = 8001;
const GROUP_1 = 4001;
const GROUP_2 = 4002;

seed_event(EVT, 9001, 'حفل زفاف الاختبار'); // Owner = 9001
seed_admin(9002);

seed_membership(EVT, 9003, 1, 'active', 'manager', 10, [GROUP_1]);   // داعٍ إضافي مؤهَّل — GROUP_1
seed_membership(EVT, 9007, 5, 'active', 'manager', 10, [GROUP_2]);   // داعٍ إضافي مؤهَّل — GROUP_2
seed_membership(EVT, 9009, 7, 'revoked', 'manager', 10, [GROUP_1]);  // داعٍ إضافي مُلغى (اختبار C)
seed_membership(EVT, 9011, 9, 'active', 'manager', 10, [GROUP_1]);   // اختبار P (إعادة قراءة طازجة)
seed_membership(EVT, 9012, 10, 'active', 'manager', 1, [GROUP_1]);   // حصة صغيرة جداً — اختبار R

// ══════════════════════════════════════════════════════════════════════
// A. مالك (Owner) — محاولة معلَّقة مُطالَب بها → Cartat مرة واحدة → sent →
//    إزالة عنصر الطابور.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000001', '2026-08-22 09:00:00', 'أحمد');
seed_assignment(EVT, '966500000001', GROUP_1);
seed_log_row(201, ['guest_phone' => '966500000001', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(201);
PGE_Cartat_Transport::reset_all();
$rows_before = count($wpdb->message_log);

$a = PGE_Invitation_Send_Worker::process_log_id(201);
check('A1. Owner: result = sent', $a['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);
check('A2. Owner: reason = accepted', $a['reason'] ?? null, 'accepted');
check('A3. Owner: log_id في النتيجة = 201', $a['log_id'] ?? null, 201);
check('A4. Cartat استُدعيت مرة واحدة بالضبط', PGE_Cartat_Transport::$send_count, 1);
check('A5. السجل الدائم أصبح sent', $wpdb->message_log[201]['status'], PGE_Message_Log::STATUS_SENT);
check_true('A6. sent_at أصبح مضبوطاً', !empty($wpdb->message_log[201]['sent_at']));
check('A7. عنصر الطابور أُزيل بعد النجاح', PGE_Invitation_Send_Queue::is_queued(201), false);
check('A8. لا صف جديد أُنشئ في pge_message_log (U أيضاً — راجع أدناه)', count($wpdb->message_log), $rows_before);
check_true('A9. القفل حُرِّر بعد التنفيذ (لا قفل متروك)', empty($wpdb->held_locks));

// ══════════════════════════════════════════════════════════════════════
// B. داعٍ إضافي مؤهَّل ضمن مجموعته الحالية → sent.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000002', '2026-08-22 09:00:00', 'سارة');
seed_assignment(EVT, '966500000002', GROUP_1);
seed_log_row(202, ['guest_phone' => '966500000002', 'actor_user_id' => 9003]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(202);
PGE_Cartat_Transport::reset_all();

$b = PGE_Invitation_Send_Worker::process_log_id(202);
check('B1. داعٍ إضافي مؤهَّل: result = sent', $b['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);
check('B2. Cartat استُدعيت مرة واحدة', PGE_Cartat_Transport::$send_count, 1);
check('B3. عنصر الطابور أُزيل', PGE_Invitation_Send_Queue::is_queued(202), false);

// ══════════════════════════════════════════════════════════════════════
// C. الداعي أُلغي بعد المطالبة (Claim) → Cartat لا تُستدعى → not_authorized
//    → السجل يُنهى 'cancelled' (D2-W6A) → عنصر الطابور يُزال (راجع X).
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000003', '2026-08-22 09:00:00', 'خالد');
seed_assignment(EVT, '966500000003', GROUP_1);
seed_log_row(203, ['guest_phone' => '966500000003', 'actor_user_id' => 9009]); // 9009 = revoked
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(203);
PGE_Cartat_Transport::reset_all();

$c = PGE_Invitation_Send_Worker::process_log_id(203);
check('C1. داعٍ مُلغى: result = not_authorized', $c['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_NOT_AUTHORIZED);
check('C2. سبب = authorization_lapsed', $c['reason'] ?? null, 'authorization_lapsed');
check('C3. Cartat لم تُستدعَ إطلاقاً', PGE_Cartat_Transport::$send_count, 0);
check('C4. السجل أُنهي بحالة cancelled (D2-W6A — لا Cartat استُدعيت إطلاقاً)', $wpdb->message_log[203]['status'], PGE_Message_Log::STATUS_CANCELLED);
check('C5. عنصر الطابور أُزيل بعد الإنهاء الناجح (D2-W6A)', PGE_Invitation_Send_Queue::is_queued(203), false);

// ══════════════════════════════════════════════════════════════════════
// D. الضيف انتقل خارج مجموعة الداعي بعد المطالبة → Cartat لا تُستدعى →
//    not_authorized.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000004', '2026-08-22 09:00:00', 'منى');
seed_assignment(EVT, '966500000004', GROUP_2); // خارج نطاق 9003 (مؤهَّل لـGROUP_1 فقط)
seed_log_row(204, ['guest_phone' => '966500000004', 'actor_user_id' => 9003]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(204);
PGE_Cartat_Transport::reset_all();

$d = PGE_Invitation_Send_Worker::process_log_id(204);
check('D1. الضيف خارج النطاق الحالي: result = not_authorized', $d['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_NOT_AUTHORIZED);
check('D2. Cartat لم تُستدعَ إطلاقاً', PGE_Cartat_Transport::$send_count, 0);
check('D3. السجل أُنهي بحالة cancelled (D2-W6A)', $wpdb->message_log[204]['status'], PGE_Message_Log::STATUS_CANCELLED);
check('D4. عنصر الطابور أُزيل', PGE_Invitation_Send_Queue::is_queued(204), false);

// ══════════════════════════════════════════════════════════════════════
// E. الضيف ضمن نطاق الداعي الحالي (قراءة طازجة، لا قيمة قديمة) → sent.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000005', '2026-08-22 09:00:00', 'علي');
seed_assignment(EVT, '966500000005', GROUP_2); // 9007 مؤهَّل لـGROUP_2 تحديداً
seed_log_row(205, ['guest_phone' => '966500000005', 'actor_user_id' => 9007]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(205);
PGE_Cartat_Transport::reset_all();

$e = PGE_Invitation_Send_Worker::process_log_id(205);
check('E1. الضيف ضمن النطاق الحالي: result = sent', $e['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);

// ══════════════════════════════════════════════════════════════════════
// F. محاولة مُطالَب بها لدورة حياة قديمة (الضيف حُذف وأُعيد إنشاؤه) → لا نقل
//    → lifecycle_mismatch → إنهاء آمن (STATUS_CANCELLED منذ D2-W6A) → إزالة الطابور.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000006', '2026-08-22 09:50:00', 'فهد'); // دورة حياة جديدة
seed_assignment(EVT, '966500000006', GROUP_1);
seed_log_row(206, [
    'guest_phone'          => '966500000006',
    'actor_user_id'        => 9001,
    'lifecycle_started_at' => '2026-08-22 08:00:00', // دورة حياة قديمة مُخزَّنة وقت claim()
]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(206);
PGE_Cartat_Transport::reset_all();

$f = PGE_Invitation_Send_Worker::process_log_id(206);
check('F1. دورة حياة قديمة: result = lifecycle_mismatch', $f['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_LIFECYCLE_MISMATCH);
check('F2. سبب = lifecycle_changed', $f['reason'] ?? null, 'lifecycle_changed');
check('F3. Cartat لم تُستدعَ إطلاقاً', PGE_Cartat_Transport::$send_count, 0);
check('F4. السجل أُنهي بحالة cancelled (D2-W6A — لا Cartat استُدعيت، بدل STATUS_FAILED)', $wpdb->message_log[206]['status'], PGE_Message_Log::STATUS_CANCELLED);
check('F5. عنصر الطابور أُزيل', PGE_Invitation_Send_Queue::is_queued(206), false);

// ══════════════════════════════════════════════════════════════════════
// G. نوع رسالة خاطئ (message_type != invitation) → لا نقل → invalid.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(207, ['message_type' => 'reminder', 'guest_phone' => '966500000007', 'actor_user_id' => 9001]);
inject_queue_item(207); // عنصر طابور خاطئ/عدائي مُحاكى مباشرة — لا يمر عبر enqueue_claimed_attempt() الحقيقية (التي كانت سترفضه أصلاً).
PGE_Cartat_Transport::reset_all();

$g = PGE_Invitation_Send_Worker::process_log_id(207);
check('G1. نوع رسالة خاطئ: result = invalid', $g['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_INVALID);
check('G2. سبب = wrong_message_type', $g['reason'] ?? null, 'wrong_message_type');
check('G3. Cartat لم تُستدعَ إطلاقاً', PGE_Cartat_Transport::$send_count, 0);
check('G4. عنصر الطابور البالي أُزيل', PGE_Invitation_Send_Queue::is_queued(207), false);

// ══════════════════════════════════════════════════════════════════════
// H. سجل نهائي أصلاً (sent) قبل وصول العامل → لا نقل، تنظيف طابور بالٍ.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(208, ['status' => PGE_Message_Log::STATUS_SENT, 'guest_phone' => '966500000008', 'actor_user_id' => 9001, 'sent_at' => '2026-08-22 09:01:00']);
inject_queue_item(208);
PGE_Cartat_Transport::reset_all();

$h = PGE_Invitation_Send_Worker::process_log_id(208);
check('H1. سجل sent نهائي أصلاً: result = already_terminal', $h['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_ALREADY_TERMINAL);
check('H2. سبب = status_sent', $h['reason'] ?? null, 'status_sent');
check('H3. Cartat لم تُستدعَ إطلاقاً', PGE_Cartat_Transport::$send_count, 0);
check('H4. السجل لم يُمَس (sent_at كما كان)', $wpdb->message_log[208]['sent_at'], '2026-08-22 09:01:00');
check('H5. عنصر الطابور البالي أُزيل', PGE_Invitation_Send_Queue::is_queued(208), false);

// ══════════════════════════════════════════════════════════════════════
// I. سجل نهائي أصلاً (failed) قبل وصول العامل → لا نقل.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(209, ['status' => PGE_Message_Log::STATUS_FAILED, 'guest_phone' => '966500000009', 'actor_user_id' => 9001]);
inject_queue_item(209);
PGE_Cartat_Transport::reset_all();

$i = PGE_Invitation_Send_Worker::process_log_id(209);
check('I1. سجل failed نهائي أصلاً: result = already_terminal', $i['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_ALREADY_TERMINAL);
check('I2. سبب = status_failed', $i['reason'] ?? null, 'status_failed');
check('I3. Cartat لم تُستدعَ إطلاقاً', PGE_Cartat_Transport::$send_count, 0);
check('I4. عنصر الطابور البالي أُزيل', PGE_Invitation_Send_Queue::is_queued(209), false);

// ══════════════════════════════════════════════════════════════════════
// J. رفض صريح من Cartat → الدفتر failed → إزالة الطابور.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000010', '2026-08-22 09:00:00', 'ريم');
seed_assignment(EVT, '966500000010', GROUP_1);
seed_log_row(210, ['guest_phone' => '966500000010', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(210);
PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$next_outcome = 'rejected';

$j = PGE_Invitation_Send_Worker::process_log_id(210);
check('J1. رفض صريح: result = failed', $j['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_FAILED);
check('J2. سبب = rejected', $j['reason'] ?? null, 'rejected');
check('J3. Cartat استُدعيت مرة واحدة فقط', PGE_Cartat_Transport::$send_count, 1);
check('J4. السجل أصبح failed', $wpdb->message_log[210]['status'], PGE_Message_Log::STATUS_FAILED);
check('J5. عنصر الطابور أُزيل', PGE_Invitation_Send_Queue::is_queued(210), false);

// ══════════════════════════════════════════════════════════════════════
// K. نتيجة نقل غامضة (transport_error، استجابة null) → الدفتر
//    ambiguous_transport_error → إزالة الطابور — لا 'failed' أبداً هنا.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000011', '2026-08-22 09:00:00', 'ناصر');
seed_assignment(EVT, '966500000011', GROUP_1);
seed_log_row(211, ['guest_phone' => '966500000011', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(211);
PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$next_outcome = 'ambiguous';

$k = PGE_Invitation_Send_Worker::process_log_id(211);
check('K1. نتيجة غامضة: result = ambiguous_transport_error', $k['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_AMBIGUOUS);
check('K2. سبب = transport_error', $k['reason'] ?? null, 'transport_error');
check('K3. Cartat استُدعيت مرة واحدة فقط (لا إعادة محاولة تلقائية)', PGE_Cartat_Transport::$send_count, 1);
check('K4. السجل أصبح ambiguous_transport_error', $wpdb->message_log[211]['status'], PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR);
check('K5. عنصر الطابور أُزيل', PGE_Invitation_Send_Queue::is_queued(211), false);

// ══════════════════════════════════════════════════════════════════════
// L. نجاح Cartat + finalize_success ناجح، عبر مسار send_media (صورة مضبوطة)
//    → sent — يُثبت أن مسار الصورة أيضاً يعمل عبر نفس القناة الإنتاجية.
// ══════════════════════════════════════════════════════════════════════

set_event_thumbnail(EVT, 'https://example.test/cover.jpg');
seed_invitation(EVT, '966500000012', '2026-08-22 09:00:00', 'لمى');
seed_assignment(EVT, '966500000012', GROUP_1);
seed_log_row(212, ['guest_phone' => '966500000012', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(212);
PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$next_outcome = 'accepted';

$l = PGE_Invitation_Send_Worker::process_log_id(212);
check('L1. مسار الصورة: result = sent', $l['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);
check('L2. النقل استخدم send_media (صورة مضبوطة على المناسبة)', PGE_Cartat_Transport::$last_method, 'media');
check('L3. السجل أصبح sent', $wpdb->message_log[212]['status'], PGE_Message_Log::STATUS_SENT);
clear_event_thumbnail(EVT);

// ══════════════════════════════════════════════════════════════════════
// M. Cartat تقبل الرسالة فعلياً لكن finalize_success محلياً يفشل مرة واحدة
//    → لا إعادة محاولة نقل تلقائية إطلاقاً (نداء واحد فقط) → ambiguous
//    (نفس نمط PGE_Thank_You_Message_Service::process_recipient() حرفياً).
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000013', '2026-08-22 09:00:00', 'وليد');
seed_assignment(EVT, '966500000013', GROUP_1);
seed_log_row(213, ['guest_phone' => '966500000013', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(213);
PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$next_outcome = 'accepted';
$wpdb->force_update_failure_once = true; // mark_sent() الأولى تفشل مرة واحدة فقط

$m = PGE_Invitation_Send_Worker::process_log_id(213);
check('M1. فشل حفظ محلي بعد قبول المزوّد: result = ambiguous_transport_error', $m['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_AMBIGUOUS);
check('M2. سبب = finalize_error', $m['reason'] ?? null, 'finalize_error');
check('M3. Cartat استُدعيت مرة واحدة بالضبط — لا نداء نقل ثانٍ تلقائي', PGE_Cartat_Transport::$send_count, 1);
check('M4. السجل انتهى إلى ambiguous_transport_error (عبر finalize_failure الاحتياطية)', $wpdb->message_log[213]['status'], PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR);
check('M5. عنصر الطابور أُزيل (نتيجة نهائية فعلية)', PGE_Invitation_Send_Queue::is_queued(213), false);

// ══════════════════════════════════════════════════════════════════════
// N. عنصر الطابور غائب تماماً، process_log_id() يُستدعى مباشرة بالـlog_id
//    → سلوك آمن مطابق للمسار العادي بالضبط (لا اعتماد على الطابور إطلاقاً).
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000014', '2026-08-22 09:00:00', 'هند');
seed_assignment(EVT, '966500000014', GROUP_1);
seed_log_row(214, ['guest_phone' => '966500000014', 'actor_user_id' => 9001]);
// لا PGE_Invitation_Send_Queue::enqueue_claimed_attempt(214) هنا عمداً.
check('N0. لا عنصر طابور موجود أصلاً قبل الاستدعاء', PGE_Invitation_Send_Queue::is_queued(214), false);
PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$next_outcome = 'accepted';

$n = PGE_Invitation_Send_Worker::process_log_id(214);
check('N1. بلا عنصر طابور أصلاً: نفس نتيجة المسار العادي (sent)', $n['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);
check('N2. لا خطأ من محاولة إزالة عنصر غير موجود', PGE_Invitation_Send_Queue::is_queued(214), false);

// ══════════════════════════════════════════════════════════════════════
// O. actor_user_id يُقرَأ من الدفتر حصراً، لا من الطابور (الطابور أصلاً لا
//    يحتوي هذا الحقل إطلاقاً منذ D2-W5 Fix Pass 1) — فاعلان مختلفان لنفس
//    الشكل، نتيجتان مختلفتان صحيحتان.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000015', '2026-08-22 09:00:00', 'عمر');
seed_assignment(EVT, '966500000015', GROUP_1);
seed_log_row(215, ['guest_phone' => '966500000015', 'actor_user_id' => 9003]); // مؤهَّل
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(215);

seed_invitation(EVT, '966500000016', '2026-08-22 09:00:00', 'زينب');
seed_assignment(EVT, '966500000016', GROUP_1);
seed_log_row(216, ['guest_phone' => '966500000016', 'actor_user_id' => 9009]); // مُلغى
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(216);

$queue_item_215 = PGE_Invitation_Send_Queue::get(215);
check('O1. عنصر طابور 215 لا يحتوي actor_user_id إطلاقاً (نفس عقد D2-W5)', array_key_exists('actor_user_id', (array) $queue_item_215), false);
check('O2. مفاتيح عنصر الطابور = log_id + queued_at فقط', array_keys((array) $queue_item_215), ['log_id', 'queued_at']);

PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$next_outcome = 'accepted';
$o1 = PGE_Invitation_Send_Worker::process_log_id(215);
$o2 = PGE_Invitation_Send_Worker::process_log_id(216);
check('O3. actor_user_id=9003 (من الدفتر): sent', $o1['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);
check('O4. actor_user_id=9009 (من الدفتر): not_authorized', $o2['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// P. إعادة قراءة طازجة للمجموعة/العضوية عند التنفيذ — نفس الفاعل، ضيفان
//    مختلفان بتخصيصين حاليين مختلفين، نتيجتان مختلفتان صحيحتان (لا Cache).
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000017', '2026-08-22 09:00:00', 'ياسر');
seed_assignment(EVT, '966500000017', GROUP_1); // ضمن نطاق 9011
seed_log_row(217, ['guest_phone' => '966500000017', 'actor_user_id' => 9011]);

seed_invitation(EVT, '966500000018', '2026-08-22 09:00:00', 'دانة');
seed_assignment(EVT, '966500000018', GROUP_2); // خارج نطاق 9011 (مؤهَّل لـGROUP_1 فقط)
seed_log_row(218, ['guest_phone' => '966500000018', 'actor_user_id' => 9011]);

PGE_Invitation_Send_Queue::enqueue_claimed_attempt(217);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(218);
PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$next_outcome = 'accepted';

$p1 = PGE_Invitation_Send_Worker::process_log_id(217);
$p2 = PGE_Invitation_Send_Worker::process_log_id(218);
check('P1. نفس الفاعل، ضيف ضمن النطاق الحالي: sent', $p1['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);
check('P2. نفس الفاعل، ضيف آخر خارج النطاق الحالي: not_authorized', $p2['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_NOT_AUTHORIZED);

// ══════════════════════════════════════════════════════════════════════
// Q. منشئ الضيف التاريخي غير ذي صلة — الاختبار البنيوي: الفاعل المزيَّف/
//    المستودعات هنا لا تحمل مفهوم "منشئ" إطلاقاً، فيستحيل بنيوياً أن يقرأه
//    العامل. تأكيد إضافي عبر فحص المصدر (لا مصطلح creator/created_by).
// ══════════════════════════════════════════════════════════════════════

$worker_source_raw = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-send-worker.php');
$worker_source = pge_strip_php_comments($worker_source_raw);
check_true('Q1. لا إشارة لأي "creator"/"created_by" في مصدر العامل', stripos($worker_source, 'creator') === false && stripos($worker_source, 'created_by') === false);
check_true('Q2. اختبار B/E أعلاه أثبتا فعلياً أن الإرسال يعتمد فقط على النطاق الحالي، لا هوية المُنشئ التاريخية', ($b['result'] ?? null) === PGE_Invitation_Send_Worker::RESULT_SENT);

// ══════════════════════════════════════════════════════════════════════
// R. توفر/تبقّي الحصة (Quota) غير ذي صلة — فقط "أهو مُهيَّأ بحصة أصلاً؟"
//    (allocated_quota() !== null) يُستهلَك عبر can_send_guest_invitation()
//    القائمة فعلاً، بلا أي فحص "حصة متبقية" مُضاف هنا إطلاقاً.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000019', '2026-08-22 09:00:00', 'بدر');
seed_assignment(EVT, '966500000019', GROUP_1);
seed_log_row(219, ['guest_phone' => '966500000019', 'actor_user_id' => 9012]); // حصة=1 فقط، لا مفهوم "تبقٍّ" هنا إطلاقاً
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(219);
PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$next_outcome = 'accepted';

$r = PGE_Invitation_Send_Worker::process_log_id(219);
check('R1. حصة صغيرة جداً (مُهيَّأة فقط، لا فحص تبقٍّ): sent', $r['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);
check_true('R2. لا إشارة لأي "quota_remaining"/استهلاك حصة في مصدر العامل', stripos($worker_source, 'quota_remaining') === false && stripos($worker_source, 'resolve_quota_status') === false);

// ══════════════════════════════════════════════════════════════════════
// S. نفس log_id من عاملين متزامنين (مُحاكى) → Cartat تُستدعى مرة واحدة فقط
//    إجمالاً — المحاولة الثانية تُحجَب فوراً بلا أي مساس.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000020', '2026-08-22 09:00:00', 'مها');
seed_assignment(EVT, '966500000020', GROUP_1);
seed_log_row(220, ['guest_phone' => '966500000020', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(220);
PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$next_outcome = 'accepted';

$locked_name = 'pge_invsend_exec_' . md5('220');
$wpdb->held_locks[$locked_name] = true; // محاكاة عامل آخر يُنفِّذ فعلياً نفس log_id الآن.

$s_blocked = PGE_Invitation_Send_Worker::process_log_id(220);
check('S1. عامل ثانٍ متزامن: result = retryable_error', $s_blocked['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_RETRYABLE_ERROR);
check('S2. سبب = execution_lock_not_acquired', $s_blocked['reason'] ?? null, 'execution_lock_not_acquired');
check('S3. Cartat لم تُستدعَ إطلاقاً أثناء الحجب', PGE_Cartat_Transport::$send_count, 0);
check('S4. السجل يبقى pending أثناء الحجب', $wpdb->message_log[220]['status'], PGE_Message_Log::STATUS_PENDING);
check('S5. عنصر الطابور يبقى موجوداً أثناء الحجب (لا إزالة لخطأ قابل للاستعادة)', PGE_Invitation_Send_Queue::is_queued(220), true);

unset($wpdb->held_locks[$locked_name]); // العامل الآخر انتهى وحرَّر القفل.
$s_ok = PGE_Invitation_Send_Worker::process_log_id(220);
check('S6. بعد تحرّر القفل: result = sent', $s_ok['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);
check('S7. إجمالي نداءات Cartat لهذا log_id = 1 فقط (لا نداء مضاعف)', PGE_Cartat_Transport::$send_count, 1);

// ══════════════════════════════════════════════════════════════════════
// T. سجلات log_id مختلفة تُعالَج باستقلال تام (لا تعارض أقفال).
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000021', '2026-08-22 09:00:00', 'طارق');
seed_assignment(EVT, '966500000021', GROUP_1);
seed_log_row(221, ['guest_phone' => '966500000021', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(221);

seed_invitation(EVT, '966500000022', '2026-08-22 09:00:00', 'نورة');
seed_assignment(EVT, '966500000022', GROUP_1);
seed_log_row(222, ['guest_phone' => '966500000022', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(222);

PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$next_outcome = 'accepted';
$t1 = PGE_Invitation_Send_Worker::process_log_id(221);
$t2 = PGE_Invitation_Send_Worker::process_log_id(222);
check('T1. log_id مستقل أول: sent', $t1['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);
check('T2. log_id مستقل ثانٍ: sent', $t2['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_SENT);
check('T3. نداءا Cartat منفصلان (2 إجمالاً لسجلين مستقلين)', PGE_Cartat_Transport::$send_count, 2);
check_true('T4. لا قفل متروك لأي منهما', empty($wpdb->held_locks));

// ══════════════════════════════════════════════════════════════════════
// U. لا claim() يُستدعى إطلاقاً من العامل — فحص مصدر + عدد صفوف الدفتر لا
//    يزيد أبداً عبر كل الاختبارات أعلاه.
// ══════════════════════════════════════════════════════════════════════

check_true('U1. لا "::claim(" في مصدر العامل إطلاقاً', strpos($worker_source, '::claim(') === false);
check_true('U2. لا "->claim(" في مصدر العامل إطلاقاً', strpos($worker_source, '->claim(') === false);

// ══════════════════════════════════════════════════════════════════════
// V. لا AJAX/UI في العامل إطلاقاً.
// ══════════════════════════════════════════════════════════════════════

check_true('V1. لا wp_ajax في مصدر العامل', stripos($worker_source, 'wp_ajax') === false);
check_true('V2. لا add_action في مصدر العامل', stripos($worker_source, 'add_action') === false);
check_true('V3. لا $_POST/$_GET في مصدر العامل', strpos($worker_source, '$_POST') === false && strpos($worker_source, '$_GET') === false);
check_true('V4. لا echo/print في مصدر العامل', preg_match('/\b(echo|print)\b/i', $worker_source) === 0);
check_true('V5. لا wp_send_json في مصدر العامل', stripos($worker_source, 'wp_send_json') === false);

// ══════════════════════════════════════════════════════════════════════
// W. لا UltraMsg إطلاقاً — Cartat فقط.
// ══════════════════════════════════════════════════════════════════════

check_true('W1. لا UltraMsg في مصدر العامل', stripos($worker_source, 'ultramsg') === false);
check_true('W2. لا wa.me في مصدر العامل', stripos($worker_source, 'wa.me') === false);
check_true('W3. لا Mon_UltraMsg_Handler في مصدر العامل', stripos($worker_source, 'Mon_UltraMsg_Handler') === false);

// ══════════════════════════════════════════════════════════════════════
// X. سياسة إزالة الطابور — ملخَّص تجميعي عبر الحالات أعلاه (كل الفحوصات
//    الفردية أُجريت ضمن A-N/S أعلاه؛ هذا فقط تأكيد صريح إضافي مُجمَّع).
// ══════════════════════════════════════════════════════════════════════

check_true('X1. not_authorized (C) أزال عنصر الطابور (D2-W6A — إنهاء cancelled ناجح)', !PGE_Invitation_Send_Queue::is_queued(203));
check_true('X2. lifecycle_mismatch (F) أزال عنصر الطابور', !PGE_Invitation_Send_Queue::is_queued(206));
check_true('X3. already_terminal (H) أزال عنصر الطابور', !PGE_Invitation_Send_Queue::is_queued(208));
check_true('X4. sent (A) أزال عنصر الطابور', !PGE_Invitation_Send_Queue::is_queued(201));
check_true('X5. failed (J) أزال عنصر الطابور', !PGE_Invitation_Send_Queue::is_queued(210));
check_true('X6. ambiguous_transport_error (K) أزال عنصر الطابور', !PGE_Invitation_Send_Queue::is_queued(211));

// ══════════════════════════════════════════════════════════════════════
// Y. خطأ بنية تحتية سابق للنقل (قابل للاستعادة) — لا مساس بالسجل/الطابور.
// ══════════════════════════════════════════════════════════════════════

// Y1. اعتماد Cartat غير مضبوط.
seed_invitation(EVT, '966500000023', '2026-08-22 09:00:00', 'سلطان');
seed_assignment(EVT, '966500000023', GROUP_1);
seed_log_row(223, ['guest_phone' => '966500000023', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(223);
PGE_Cartat_Transport::reset_all();
PGE_Cartat_Transport::$has_credentials = false;

$y1 = PGE_Invitation_Send_Worker::process_log_id(223);
check('Y1a. Cartat غير مضبوط: result = retryable_error', $y1['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_RETRYABLE_ERROR);
check('Y1b. سبب = cartat_not_configured', $y1['reason'] ?? null, 'cartat_not_configured');
check('Y1c. Cartat لم تُستدعَ (لا اعتماد أصلاً)', PGE_Cartat_Transport::$send_count, 0);
check('Y1d. السجل يبقى pending', $wpdb->message_log[223]['status'], PGE_Message_Log::STATUS_PENDING);
check('Y1e. عنصر الطابور لم يُزَل', PGE_Invitation_Send_Queue::is_queued(223), true);
PGE_Cartat_Transport::$has_credentials = true;

// Y2. فشل قراءة تخصيص الضيف (WP_Error) — دفاعي بحت، ليس حكم تخويل.
seed_invitation(EVT, '966500000024', '2026-08-22 09:00:00', 'أمل');
force_assignment_error(EVT, '966500000024', true);
seed_log_row(224, ['guest_phone' => '966500000024', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(224);
PGE_Cartat_Transport::reset_all();

$y2 = PGE_Invitation_Send_Worker::process_log_id(224);
check('Y2a. فشل قراءة تخصيص: result = retryable_error', $y2['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_RETRYABLE_ERROR);
check('Y2b. سبب = assignment_unavailable', $y2['reason'] ?? null, 'assignment_unavailable');
check('Y2c. Cartat لم تُستدعَ', PGE_Cartat_Transport::$send_count, 0);
check('Y2d. السجل يبقى pending', $wpdb->message_log[224]['status'], PGE_Message_Log::STATUS_PENDING);
check('Y2e. عنصر الطابور لم يُزَل', PGE_Invitation_Send_Queue::is_queued(224), true);
force_assignment_error(EVT, '966500000024', false);

// ══════════════════════════════════════════════════════════════════════
// AA. D2-W6A — فشل كتابة الإلغاء نفسه (terminalization write failure) عند
//     سقوط التخويل: لا Cartat، لا إزالة طابور، السجل يبقى pending تماماً،
//     النتيجة retryable_error (قابل للاستعادة بالكامل — لا حالة نهائية
//     خاطئة تُفرَض قسراً).
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000025', '2026-08-22 09:00:00', 'ريم');
seed_assignment(EVT, '966500000025', GROUP_1);
seed_log_row(225, ['guest_phone' => '966500000025', 'actor_user_id' => 9009]); // 9009 = revoked → authorization_lapsed
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(225);
PGE_Cartat_Transport::reset_all();
$wpdb->force_update_failure_once = true; // mark_cancelled() الوحيدة تفشل مرة واحدة فقط

$aa = PGE_Invitation_Send_Worker::process_log_id(225);
check('AA1. فشل كتابة الإلغاء: result = retryable_error', $aa['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_RETRYABLE_ERROR);
check('AA2. سبب = cancellation_write_failed', $aa['reason'] ?? null, 'cancellation_write_failed');
check('AA3. Cartat لم تُستدعَ إطلاقاً', PGE_Cartat_Transport::$send_count, 0);
check('AA4. السجل يبقى pending (لا حالة نهائية خاطئة)', $wpdb->message_log[225]['status'], PGE_Message_Log::STATUS_PENDING);
check('AA5. عنصر الطابور لم يُزَل (قابل للاستعادة لاحقاً)', PGE_Invitation_Send_Queue::is_queued(225), true);

// ══════════════════════════════════════════════════════════════════════
// AB. D2-W6A — الدعوة حُذفت قبل التنفيذ (invitation_not_found) → إنهاء
//     cancelled صراحةً (بدل failed القديمة).
// ══════════════════════════════════════════════════════════════════════

seed_invitation(EVT, '966500000026', '2026-08-22 09:00:00', 'سعد');
seed_assignment(EVT, '966500000026', GROUP_1);
seed_log_row(226, ['guest_phone' => '966500000026', 'actor_user_id' => 9001]);
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(226);
delete_invitation(EVT, '966500000026'); // الدعوة حُذفت بعد claim()، قبل التنفيذ
PGE_Cartat_Transport::reset_all();

$ab = PGE_Invitation_Send_Worker::process_log_id(226);
check('AB1. دعوة محذوفة: result = lifecycle_mismatch', $ab['result'] ?? null, PGE_Invitation_Send_Worker::RESULT_LIFECYCLE_MISMATCH);
check('AB2. سبب = invitation_not_found', $ab['reason'] ?? null, 'invitation_not_found');
check('AB3. Cartat لم تُستدعَ إطلاقاً', PGE_Cartat_Transport::$send_count, 0);
check('AB4. السجل أُنهي بحالة cancelled', $wpdb->message_log[226]['status'], PGE_Message_Log::STATUS_CANCELLED);
check('AB5. عنصر الطابور أُزيل', PGE_Invitation_Send_Queue::is_queued(226), false);

// ══════════════════════════════════════════════════════════════════════
// AC. D2-W6A — سجل cancelled لا يُعاد اكتشافه بواسطة D2-W5::
//     find_recoverable_pending_attempts() (يقرأ status=pending حصراً —
//     query_pending_by_type() — راجع class-pge-message-log.php)، بخلاف صف
//     pending يتيم فعلي والذي يبقى مكتشَفاً كما هو.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(227, ['status' => PGE_Message_Log::STATUS_CANCELLED, 'guest_phone' => '966500000027', 'actor_user_id' => 9001]); // بلا عنصر طابور — يتيم لكن نهائي
seed_log_row(228, ['guest_phone' => '966500000028', 'actor_user_id' => 9001]); // pending يتيم فعلي (بلا عنصر طابور) — يجب اكتشافه

$recoverable_ids = array_map(function ($row) { return (int) $row['id']; }, PGE_Invitation_Send_Queue::find_recoverable_pending_attempts(100));
check_true('AC1. سجل 227 (cancelled) غير موجود ضمن المُستردَّة', !in_array(227, $recoverable_ids, true));
check_true('AC2. سجل 228 (pending يتيم فعلي) موجود ضمن المُستردَّة (تأكيد أن الاستبعاد ليس شاملاً بالخطأ)', in_array(228, $recoverable_ids, true));
// 227/228 كلاهما بلا عنصر طابور أصلاً (لم يُستدعَ enqueue_claimed_attempt())
// — لا حاجة لأي تنظيف طابور هنا.

// ══════════════════════════════════════════════════════════════════════
// Z. تأكيدات ختامية عامة.
// ══════════════════════════════════════════════════════════════════════

check_true('Z1. لا قفل مأخوذ يبقى محجوزاً بعد نهاية كامل الاختبار', empty($wpdb->held_locks));
check_true('Z2. عدد نداءات GET_LOCK الناجحة = عدد نداءات RELEASE_LOCK عبر كل الاختبار (كل قفل مأخوذ فعلياً حُرِّر — لا نداء GET_LOCK محجوب/فاشل يُحسَب هنا، إذ لا التزام تحرير لقفل لم يُؤخَذ أصلاً)', count($wpdb->successful_lock_acquire_log) === count($wpdb->lock_release_log));
check_true('Z3. لا مدخل "invented" أو "TODO" أو "FIXME" مُتبقٍّ في مصدر العامل', stripos($worker_source, 'TODO') === false && stripos($worker_source, 'FIXME') === false);

// ── ملخص ────────────────────────────────────────────────────────────────

echo "\n";
echo "النتيجة: $passed / $total نجحت.\n";

if (!empty($failures)) {
    echo "\nالحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}

exit(0);
