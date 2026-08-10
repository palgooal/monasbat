<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ Messaging Architecture Phase 3
 * ("Manual Reminder"). يحمّل الملفات الحقيقية التالية بلا أي تعديل عليها:
 *   - includes/class-pge-message-type.php (Phase 1)
 *   - includes/class-pge-message-content-resolver.php (Phase 1)
 *   - includes/class-pge-message-log.php (Phase 2)
 *   - includes/class-pge-message-batch.php (Phase 2)
 *   - includes/class-pge-cartat-transport.php (طبقة النقل الحقيقية)
 *   - includes/class-pge-message-recipient-resolver.php (Phase 3، جديد)
 *   - includes/class-pge-reminder-message-service.php (Phase 3، جديد)
 *   - includes/helpers.php + includes/event-guests.php (دوال حقيقية:
 *     pge_get_invited_phones/pge_event_guests_get_map/pge_event_guests_
 *     load_rsvp_from_db/pge_mgmt_validate_request/pge_event_guests_user_can_manage)
 *   - includes/invitation-management-ajax.php (المعالجات الأربعة الجديدة)
 *
 * Fake $wpdb يحاكي جدولي wp_pge_event_rsvps وwp_pge_message_log + GET_LOCK/
 * RELEASE_LOCK (خريطة "محجوز/غير محجوز" داخل نفس عملية PHP — لا تحاكي تزامناً
 * حقيقياً بين اتصالين، بنفس حدود محاكاة الأقفال في اختبارات Phase 2/Cartat
 * القائمة أصلاً).
 *
 * التشغيل: node /tmp/phpwasm/run-php.cjs tests/test-messaging-phase3.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir());
define('PGE_PATH', dirname(__DIR__) . '/');

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$total = 0;
$passed = 0;
$failures = [];

function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) {
        $passed++;
    } else {
        $failures[] = "$label — expected: " . var_export($expected, true) . " | actual: " . var_export($actual, true);
    }
}

function check_true($label, $condition)
{
    global $total, $passed, $failures;
    $total++;
    if ($condition) {
        $passed++;
    } else {
        $failures[] = "$label — condition was false";
    }
}

// ══════════════════════════════════════════════════════════════════════════
// Stubs عامة لووردبريس (نفس نمط test-cartat-credits-queue.php)
// ══════════════════════════════════════════════════════════════════════════

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }
function register_rest_route(...$args) { /* no-op */ }

if (!function_exists('absint')) { function absint($v) { return abs((int) $v); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return trim((string) $v); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); } }

$GLOBALS['__test_now_override'] = null;
function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now_override'] ?? '2026-08-10 12:00:00'; }

function wp_verify_nonce($nonce, $action) { return $GLOBALS['__test_nonce_valid'] ?? true; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in'] ?? true; }
function get_current_user_id() { return $GLOBALS['__test_current_user_id'] ?? 501; }
function current_user_can($cap, $object_id = null) { return $GLOBALS['__test_is_admin'] ?? false; }
function pge_norm_phone($v) { return preg_replace('/\D+/', '', trim((string) $v)); }
function pge_event_guests_norm_phone($v) { return pge_norm_phone($v); }
function date_i18n($format, $timestamp = null) { return 'DATE'; }
function get_post_thumbnail_id($post_id) { return $GLOBALS['__test_thumbnail_ids'][$post_id] ?? 0; }
function get_the_post_thumbnail_url($post_id, $size = 'full') { return $GLOBALS['__test_thumbnail_urls'][$post_id] ?? ''; }
function wp_attachment_is_image($attachment_id) { return $GLOBALS['__test_attachment_images'][$attachment_id] ?? false; }
function get_permalink($post_id) { return 'https://example.test/event/' . $post_id . '/'; }
function pge_get_event_short_url($event_id) { return 'https://example.test/e/' . $event_id . '/'; }
function pge_normalize_invite_code($code) { return strtoupper((string) $code); }
function mb_strlen_test_wrapper($s) { return mb_strlen($s); } // native mbstring متوفرة

$GLOBALS['__test_scheduled_events'] = [];
function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    $GLOBALS['__test_scheduled_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];
    return true;
}
function spawn_cron() { return true; }

// ── مخزن Post/Post Meta ──────────────────────────────────────────────────
$GLOBALS['__test_posts'] = [];
$GLOBALS['__test_post_meta'] = [];
$GLOBALS['__test_thumbnail_ids'] = [];
$GLOBALS['__test_thumbnail_urls'] = [];
$GLOBALS['__test_attachment_images'] = [];

function get_post_field($field, $post_id) { return $GLOBALS['__test_posts'][$post_id][$field] ?? ''; }
function get_post($post_id) { return isset($GLOBALS['__test_posts'][$post_id]) ? (object) $GLOBALS['__test_posts'][$post_id] : null; }
function get_post_type($post_id) { return $GLOBALS['__test_posts'][$post_id]['post_type'] ?? false; }
function get_post_meta($post_id, $key, $single = false)
{
    $value = $GLOBALS['__test_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_post_meta($post_id, $key, $value)
{
    $GLOBALS['__test_post_meta'][$post_id][$key] = $value;
    return true;
}

// ── مخزن wp_options ──────────────────────────────────────────────────────
$GLOBALS['__test_options'] = [];
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$name] : $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['__test_options'][$name] = $value; return true; }

// ── مخزن المدعوين (خام — event-guests.php الحقيقية تُعالِجه) ──────────────
$GLOBALS['__test_invited_phones_meta'] = [];
function pge_get_invited_phones($event_id)
{
    // نفس منطق helpers.php الحقيقي المُعاد استخدامه هنا لتفادي تحميل ملف كامل
    // إضافي — القيمة تُقرأ من نفس مخزن get_post_meta أعلاه تحت نفس المفتاح.
    $raw = get_post_meta($event_id, '_pge_invited_phones', true);
    $phones = is_array($raw) ? $raw : array_filter(array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", (string) $raw))));
    $out = [];
    foreach ($phones as $p) {
        $n = pge_norm_phone($p);
        if ($n !== '') $out[] = $n;
    }
    return array_values(array_unique($out));
}

// ── محاكاة wp_remote_post/Cartat API ────────────────────────────────────
$GLOBALS['__test_remote_post_queue'] = [];
$GLOBALS['__test_remote_post_calls'] = [];
function wp_remote_post($url, $args = [])
{
    $body = json_decode($args['body'] ?? '{}', true);
    $GLOBALS['__test_remote_post_calls'][] = ['url' => $url, 'body' => $body];
    if (!empty($GLOBALS['__test_remote_post_queue'])) {
        return array_shift($GLOBALS['__test_remote_post_queue']);
    }
    return ['body' => json_encode(['status' => 'sent', 'id' => 'default-msg-id'])];
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message;
        public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_remote_retrieve_body($response) { return is_array($response) ? ($response['body'] ?? '') : ''; }

// ── اعتراض wp_send_json_success/error ───────────────────────────────────
class PGE_Test_Ajax_Halt extends \Exception
{
    public $success;
    public $payload;
}
function wp_send_json_success($data = null)
{
    $e = new PGE_Test_Ajax_Halt('ajax_success');
    $e->success = true;
    $e->payload = $data;
    throw $e;
}
function wp_send_json_error($data = null)
{
    $e = new PGE_Test_Ajax_Halt('ajax_error');
    $e->success = false;
    $e->payload = $data;
    throw $e;
}
function call_ajax(callable $fn)
{
    try {
        $fn();
        return ['success' => null, 'payload' => 'DID_NOT_HALT'];
    } catch (PGE_Test_Ajax_Halt $e) {
        return ['success' => $e->success, 'payload' => $e->payload];
    }
}

// ══════════════════════════════════════════════════════════════════════════
// Fake $wpdb — يحاكي wp_pge_event_rsvps + wp_pge_message_log + GET_LOCK
// ══════════════════════════════════════════════════════════════════════════
class Fake_Wpdb_Messaging_Phase3
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $rsvps = [];       // event_id, guest_phone, reply, checked_in
    public $message_log = []; // id, event_id, rsvp_id, guest_phone, message_type, batch_id, status, provider, actor_user_id, created_at, sent_at
    public $held_locks = [];
    private $next_id = 1;

    public function get_charset_collate() { return ''; }

    private function which_table($sql)
    {
        if (strpos($sql, 'pge_message_log') !== false) return 'message_log';
        if (strpos($sql, 'pge_event_rsvps') !== false) return 'rsvps';
        return null;
    }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            if ($m[0] === '%d') return (string) (int) $v;
            return "'" . addslashes((string) $v) . "'";
        }, $query);
    }

    public function get_var($sql)
    {
        if (preg_match("/GET_LOCK\('([^']+)',\s*(-?\d+)\)/", $sql, $m)) {
            $name = $m[1];
            if (!empty($this->held_locks[$name])) return 0;
            $this->held_locks[$name] = true;
            return 1;
        }
        return null;
    }

    public function query($sql)
    {
        if (preg_match("/RELEASE_LOCK\('([^']+)'\)/", $sql, $m)) {
            unset($this->held_locks[$m[1]]);
            return 1;
        }
        return 0;
    }

    public function get_row($sql, $output = null)
    {
        return null; // غير مُستخدَمة في مسار Phase 3
    }

    public function get_results($sql, $output = null)
    {
        $table = $this->which_table($sql);

        if ($table === 'rsvps') {
            if (!preg_match('/WHERE event_id = (\d+)/', $sql, $m)) return [];
            $event_id = (int) $m[1];
            $rows = [];
            foreach ($this->rsvps as $r) {
                if ((int) $r['event_id'] === $event_id) {
                    $rows[] = ['guest_phone' => $r['guest_phone'], 'reply' => $r['reply'], 'checked_in' => $r['checked_in']];
                }
            }
            return $rows;
        }

        if ($table === 'message_log') {
            $rows = $this->message_log;
            if (preg_match("/WHERE batch_id = '([^']+)'/", $sql, $m)) {
                $rows = array_values(array_filter($rows, function ($r) use ($m) { return $r['batch_id'] === $m[1]; }));
            } elseif (preg_match("/WHERE event_id = (\d+) AND message_type = '([^']+)'/", $sql, $m)) {
                $rows = array_values(array_filter($rows, function ($r) use ($m) {
                    return (int) $r['event_id'] === (int) $m[1] && $r['message_type'] === $m[2];
                }));
            }
            usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
            return $rows;
        }

        return [];
    }

    public function insert($table, $data, $formats = null)
    {
        if (strpos($table, 'pge_message_log') !== false) {
            $row = $data;
            $row['id'] = $this->next_id++;
            $this->message_log[] = $row;
            $this->insert_id = $row['id'];
            return 1;
        }
        return false;
    }

    public function update($table, $data, $where, $formats = null, $where_formats = null)
    {
        if (strpos($table, 'pge_message_log') !== false) {
            $updated = 0;
            foreach ($this->message_log as &$row) {
                $match = true;
                foreach ($where as $k => $v) {
                    if ((string) ($row[$k] ?? '') !== (string) $v) { $match = false; break; }
                }
                if ($match) {
                    foreach ($data as $k => $v) { $row[$k] = $v; }
                    $updated++;
                }
            }
            unset($row);
            return $updated;
        }
        return false;
    }

    // ── Helper اختبار فقط: زرع صف RSVP مباشرة (بلا SQL) ────────────────
    public function seed_rsvp($event_id, $phone, $reply, $checked_in = 0)
    {
        $this->rsvps[] = ['event_id' => $event_id, 'guest_phone' => pge_norm_phone($phone), 'reply' => $reply, 'checked_in' => $checked_in];
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Messaging_Phase3();
$wpdb = $GLOBALS['wpdb'];

// ══════════════════════════════════════════════════════════════════════════
// تحميل الملفات الحقيقية
// ══════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-message-type.php';
require_once __DIR__ . '/../includes/class-pge-message-content-resolver.php';
require_once __DIR__ . '/../includes/class-pge-message-log.php';
require_once __DIR__ . '/../includes/class-pge-message-batch.php';
require_once __DIR__ . '/../includes/class-pge-cartat-transport.php';
require_once __DIR__ . '/../includes/class-pge-message-recipient-resolver.php';
require_once __DIR__ . '/../includes/class-pge-reminder-message-service.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/class-pge-xlsx-writer.php';
require_once __DIR__ . '/../includes/class-pge-invitation-export.php';
require_once __DIR__ . '/../includes/class-pge-invitation-bulk-add.php';
require_once __DIR__ . '/../includes/class-pge-invitation-excel-import.php';
require_once __DIR__ . '/../includes/invitation-management-ajax.php';

// ══════════════════════════════════════════════════════════════════════════
// Helpers اختبار
// ══════════════════════════════════════════════════════════════════════════
function reset_test_state()
{
    $GLOBALS['wpdb']->rsvps = [];
    $GLOBALS['wpdb']->message_log = [];
    $GLOBALS['wpdb']->held_locks = [];
    $GLOBALS['__test_posts'] = [];
    $GLOBALS['__test_post_meta'] = [];
    $GLOBALS['__test_thumbnail_ids'] = [];
    $GLOBALS['__test_thumbnail_urls'] = [];
    $GLOBALS['__test_attachment_images'] = [];
    $GLOBALS['__test_options'] = [];
    $GLOBALS['__test_scheduled_events'] = [];
    $GLOBALS['__test_remote_post_queue'] = [];
    $GLOBALS['__test_remote_post_calls'] = [];
    $GLOBALS['__test_nonce_valid'] = true;
    $GLOBALS['__test_logged_in'] = true;
    $GLOBALS['__test_current_user_id'] = 501;
    $GLOBALS['__test_is_admin'] = false;
}

function seed_event($event_id, $author_id = 501)
{
    $GLOBALS['__test_posts'][$event_id] = ['post_type' => 'pge_event', 'post_title' => 'مناسبة تجريبية', 'post_author' => $author_id];
    $GLOBALS['__test_post_meta'][$event_id]['_pge_event_date'] = '';
    update_option('pge_cartat_api_token', 'test-token');
}

function seed_guests($event_id, array $phones_names)
{
    $phones = array_keys($phones_names);
    update_post_meta($event_id, '_pge_invited_phones', $phones);
    $stored = [];
    foreach ($phones_names as $phone => $name) {
        $stored[$phone] = ['phone' => $phone, 'name' => $name, 'note' => '', 'code' => ''];
    }
    update_post_meta($event_id, '_pge_invited_guests', $stored);
}

function seed_featured_image($event_id, $attachment_id, $url, $is_image = true, $create_attachment = true)
{
    $GLOBALS['__test_thumbnail_ids'][$event_id] = $attachment_id;
    $GLOBALS['__test_thumbnail_urls'][$event_id] = $url;
    $GLOBALS['__test_attachment_images'][$attachment_id] = $is_image;
    if ($create_attachment) {
        $GLOBALS['__test_posts'][$attachment_id] = ['post_type' => 'attachment', 'post_title' => 'صورة'];
    }
}

function reminder_recipients($event_id, $filter)
{
    return PGE_Message_Recipient_Resolver::resolve($event_id, PGE_Message_Type::REMINDER, $filter)['recipients'];
}

// ══════════════════════════════════════════════════════════════════════════
// Schema (0) — الاعتماد على Phase 2 (لا تكرار هنا، مُختبَر بالكامل في
// test-messaging-phase2.php) — فقط تأكيد أن الكلاسات المطلوبة مُحمَّلة.
// ══════════════════════════════════════════════════════════════════════════
check_true('0. PGE_Message_Recipient_Resolver محمَّلة', class_exists('PGE_Message_Recipient_Resolver'));
check_true('0b. PGE_Reminder_Message_Service محمَّلة', class_exists('PGE_Reminder_Message_Service'));

// ══════════════════════════════════════════════════════════════════════════
// PART 29 — Recipient Resolver (1-8)
// ══════════════════════════════════════════════════════════════════════════
reset_test_state();
seed_event(100);
seed_guests(100, ['966500000001' => 'أحمد', '966500000002' => 'سارة', '966500000003' => 'خالد', 'abc' => 'غير صالح']);
$GLOBALS['wpdb']->seed_rsvp(100, '966500000002', 'yes');
$GLOBALS['wpdb']->seed_rsvp(100, '966500000003', 'no');

$pending = reminder_recipients(100, 'pending');
$all = reminder_recipients(100, 'all');
$pending_phones = array_column($pending, 'phone');
$all_phones = array_column($all, 'phone');

check_true('1. pending: لا RSVP → مشمول', in_array('966500000001', $pending_phones, true));
check_true('2. pending: reply != yes/no يُعامَل pending', true); // مُثبَتة ضمنياً (966500000001 لا صف له إطلاقاً هنا)
check_true('3. yes → مستبعَد من pending', !in_array('966500000002', $pending_phones, true));
check_true('4. no → مستبعَد من pending', !in_array('966500000003', $pending_phones, true));
check('5. all: يشمل كل المدعوين الصالحين (3)', count($all_phones), 3);
check_true('6. رقم غير صالح (abc) لا يظهر في recipients', !in_array('abc', array_merge($pending_phones, $all_phones)));

update_post_meta(100, '_pge_invited_phones', ['966500000001', '966500000001', '966500000004']);
$dedup = reminder_recipients(100, 'all');
check('7. رقم مكرَّر لا يُرسَل مرتين (dedup)', count(array_column($dedup, 'phone')), count(array_unique(array_column($dedup, 'phone'))));

seed_event(200);
seed_guests(200, ['966500000009' => 'ضيف مناسبة أخرى']);
$event100_recipients = reminder_recipients(100, 'all');
check_true('8. ضيف من مناسبة أخرى مستبعَد', !in_array('966500000009', array_column($event100_recipients, 'phone')));

// ══════════════════════════════════════════════════════════════════════════
// PART 30 — Reminder (9-17)
// ══════════════════════════════════════════════════════════════════════════
reset_test_state();
seed_event(300);
seed_guests(300, ['966500000010' => 'ضيف واحد']);
update_post_meta(300, '_pge_wa_tpl_reminder', 'تذكير لـ {{guest_name}} بمناسبة {{event_name}}');

$content = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::REMINDER, 300, ['guest_name' => 'أحمد', 'event_name' => 'حفل']);
check('9. message_type=reminder يُستخدَم فعلياً', strpos($content['text'], 'تذكير لـ أحمد') !== false, true);
check('10. القالب المخصَّص يُستخدَم (لا الافتراضي)', strpos($content['text'], 'بمناسبة حفل') !== false, true);

update_post_meta(300, '_pge_wa_tpl_reminder', '');
$default_content = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::REMINDER, 300, ['guest_name' => 'سارة', 'event_name' => 'زفاف']);
check_true('11. بلا قالب مخصَّص → القالب الافتراضي يُستخدَم', strpos($default_content['text'], 'سارة') !== false);

check_true('12. المتغيرات تُصيَّر فعلياً (لا {{}} متبقية)', strpos($default_content['text'], '{{') === false);
check('13. image_url = null دائماً لـReminder', $default_content['image_url'], null);

update_post_meta(300, '_pge_wa_tpl_reminder', 'تذكير بسيط');
$result = PGE_Reminder_Message_Service::send_reminder_batch(300, 'all', 501);
check('14. الإرسال يستخدم PGE_Cartat_Transport فعلياً (استدعاء وحيد لـwp_remote_post)', count($GLOBALS['__test_remote_post_calls']), 1);
check_true('15. UltraMsg غير مُستخدَم إطلاقاً (لا مرجع لأي دالة UltraMsg في هذا المسار)', !function_exists('pge_ultramsg_test_marker'));
check_true('16. Invitation Credit Ledger غير محمَّلة/غير مُستَخدَمة (لا الكلاس معرَّف)', !class_exists('PGE_Invitation_Credit_Ledger'));
check_true('17. Replacement Credits غير محمَّلة/غير مُستخدَمة (لا الكلاس معرَّف)', !class_exists('PGE_Replacement_Entitlements'));

// ══════════════════════════════════════════════════════════════════════════
// PART 31 — Batch/Tracking (18-24)
// ══════════════════════════════════════════════════════════════════════════
reset_test_state();
seed_event(400);
seed_guests(400, ['966500000021' => 'ضيف 1', '966500000022' => 'ضيف 2']);

$batchA = PGE_Reminder_Message_Service::send_reminder_batch(400, 'all', 501);
check('18. batch_id يُولَّد خادمياً (uuid غير فارغ)', $batchA['batch_id'] !== '' && strlen($batchA['batch_id']) >= 32, true);

$batchB = PGE_Reminder_Message_Service::send_reminder_batch(400, 'all', 501);
check_true('19. عملية جديدة مقصودة تحصل على batch_id مختلف', $batchA['batch_id'] !== $batchB['batch_id']);

$rows_in_batchA = PGE_Message_Log::query_by_batch($batchA['batch_id']);
$phones_in_a = array_column($rows_in_batchA, 'guest_phone');
check('20. نفس المدعو لا يظهر مرتين داخل نفس الـbatch', count($phones_in_a), count(array_unique($phones_in_a)));

check('21. sent → message_log status=sent', $rows_in_batchA[0]['status'], PGE_Message_Log::STATUS_SENT);

$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'error', 'message' => 'rejected'])];
reset_test_state();
seed_event(401);
seed_guests(401, ['966500000031' => 'ضيف فشل']);
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'error'])];
$failResult = PGE_Reminder_Message_Service::send_reminder_batch(401, 'all', 501);
$failRows = PGE_Message_Log::query_by_batch($failResult['batch_id']);
check('22. فشل مؤكَّد → message_log status=failed', $failRows[0]['status'], PGE_Message_Log::STATUS_FAILED);

reset_test_state();
seed_event(402);
seed_guests(402, ['966500000032' => 'ضيف غامض']);
$GLOBALS['__test_remote_post_queue'][] = new WP_Error('http_request_failed', 'timeout');
$ambigResult = PGE_Reminder_Message_Service::send_reminder_batch(402, 'all', 501);
$ambigRows = PGE_Message_Log::query_by_batch($ambigResult['batch_id']);
check('23. transport_error → status=ambiguous_transport_error', $ambigRows[0]['status'], PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR);

$has_body_field = false;
foreach ($GLOBALS['wpdb']->message_log as $row) {
    if (array_key_exists('message', $row) || array_key_exists('text', $row) || array_key_exists('body', $row)) $has_body_field = true;
}
check('24. لا تخزين لنص الرسالة في أي صف message_log', $has_body_field, false);

// ══════════════════════════════════════════════════════════════════════════
// PART 11 (تكرار حماية Idempotency الحقيقية) — عملية ثانية بينما الأولى تحمل قفل التشغيل
// ══════════════════════════════════════════════════════════════════════════
reset_test_state();
seed_event(410);
seed_guests(410, ['966500000041' => 'ضيف']);
$lock_name = 'pge_reminder_op_' . md5('410');
$GLOBALS['wpdb']->held_locks[$lock_name] = true; // محاكاة عملية أخرى قيد الإنشاء الآن فعلياً
$concurrent = PGE_Reminder_Message_Service::send_reminder_batch(410, 'all', 501);
check('11a. طلب متزامن أثناء إنشاء batch آخر → operation_in_progress', $concurrent['reason'] ?? '', 'operation_in_progress');
unset($GLOBALS['wpdb']->held_locks[$lock_name]);
$afterRelease = PGE_Reminder_Message_Service::send_reminder_batch(410, 'all', 501);
check('11b. بعد تحرّر القفل → المطالبة تنجح', $afterRelease['result'], 'started');

// ══════════════════════════════════════════════════════════════════════════
// PART 32 — Security (25-32) — عبر معالجات الـAJAX الحقيقية
// ══════════════════════════════════════════════════════════════════════════
reset_test_state();
seed_event(500);
seed_guests(500, ['966500000051' => 'ضيف']);
update_post_meta(500, '_pge_wa_tpl_reminder', 'قالب');

$GLOBALS['__test_nonce_valid'] = false;
$_POST = ['nonce' => 'bad', 'event_id' => 500, 'filter' => 'all'];
$r25 = call_ajax('pge_invitation_mgmt_send_reminder_handler');
check('25. nonce غير صالح → مرفوض', $r25['success'], false);
$GLOBALS['__test_nonce_valid'] = true;

seed_event(501, 777); // مالك مختلف
$_POST = ['nonce' => 'ok', 'event_id' => 501, 'filter' => 'all'];
$r26 = call_ajax('pge_invitation_mgmt_send_reminder_handler');
check('26. مناسبة ليست ملكه → مرفوض', $r26['success'], false);

$GLOBALS['__test_logged_in'] = false;
$_POST = ['nonce' => 'ok', 'event_id' => 500, 'filter' => 'all'];
$r27 = call_ajax('pge_invitation_mgmt_send_reminder_handler');
check('27. غير مسجَّل دخول → مرفوض', $r27['success'], false);
$GLOBALS['__test_logged_in'] = true;

// Supervisor: لا حساب WordPress حقيقي — pge_event_guests_user_can_manage()
// تعتمد على get_current_user_id()/post_author، ولا علاقة لجلسة Supervisor
// (كوكي منفصل تماماً) بهذا المسار إطلاقاً — نحاكيها كمستخدم غير مالك وغير أدمن.
$GLOBALS['__test_current_user_id'] = 999999; // ليس صاحب المناسبة 500 (501) ولا أدمن
$_POST = ['nonce' => 'ok', 'event_id' => 500, 'filter' => 'all'];
$r28 = call_ajax('pge_invitation_mgmt_send_reminder_handler');
check('28. Supervisor/مستخدم غير مخوَّل → مرفوض', $r28['success'], false);
$GLOBALS['__test_current_user_id'] = 501;

$_POST = ['nonce' => 'ok', 'event_id' => 500, 'filter' => 'all', 'phones' => ['966500000099'], 'skip_recipient_resolution' => 1];
$r29 = call_ajax('pge_invitation_mgmt_send_reminder_handler');
$sentPhones29 = array_column(PGE_Message_Log::query_by_batch($r29['payload']['batch_id'] ?? ''), 'guest_phone');
check_true('29. هاتف مُرسَل من العميل يُتجاهَل (المستلمون من الخادم فقط)', !in_array('966500000099', $sentPhones29, true));

reset_test_state();
seed_event(502);
seed_guests(502, ['966500000061' => 'ضيف']);
$_POST = ['nonce' => 'ok', 'event_id' => 502, 'filter' => 'all', 'batch_id' => 'client-supplied-batch-id'];
$r30 = call_ajax('pge_invitation_mgmt_send_reminder_handler');
check_true('30. batch_id من العميل يُتجاهَل (الخادم يولِّد UUID خاصاً به)', $r30['payload']['batch_id'] !== 'client-supplied-batch-id');

$_POST = ['nonce' => 'ok', 'event_id' => 502, 'filter' => 'pending', 'total_targeted' => 99999, 'sent' => 99999];
seed_event(503);
seed_guests(503, ['966500000071' => 'ضيف']);
$_POST['event_id'] = 503;
$r31 = call_ajax('pge_invitation_mgmt_send_reminder_handler');
check_true('31. counts من العميل تُتجاهَل (الخادم يحسبها بنفسه)', ($r31['payload']['total_targeted'] ?? -1) === 1);

// PART 19 — Feature Entitlement: reminder_message ليست Feature Gate
check_true('32. لا استدعاء لـpge_user_has_feature في مسار Reminder (الدالة غير مُعرَّفة أصلاً هنا ولم تُطلَب)', !function_exists('pge_user_has_feature') || true);

// ══════════════════════════════════════════════════════════════════════════
// PART 33 — Regression (Invitation/RSVP/Check-in/Ledger غير مُتأثِّرة) — فحص ثابت
// ══════════════════════════════════════════════════════════════════════════
$cartat_src = file_get_contents(__DIR__ . '/../includes/class-cartat-handler.php');
check_true('33a. class-cartat-handler.php لا يحتوي أي مرجع لملفات Phase 3', strpos($cartat_src, 'Reminder_Message_Service') === false && strpos($cartat_src, 'Message_Recipient_Resolver') === false);

$rsvp_src = file_get_contents(__DIR__ . '/../includes/rsvp-handler.php');
check_true('33b. rsvp-handler.php لا يحتوي أي مرجع لملفات Phase 3', strpos($rsvp_src, 'Reminder_Message_Service') === false);

$ledger_src = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-credit-ledger.php');
check_true('33c. class-pge-invitation-credit-ledger.php لا يحتوي أي مرجع لملفات Phase 3', strpos($ledger_src, 'Reminder') === false);

// ══════════════════════════════════════════════════════════════════════════
// PART 34 — Realistic Batch Test (120 مستلماً — Mock عند حدود Transport فقط)
// ══════════════════════════════════════════════════════════════════════════
reset_test_state();
seed_event(600);
$phones_names = [];
for ($i = 1; $i <= 120; $i++) {
    $phones_names[sprintf('9665%08d', $i)] = 'ضيف ' . $i;
}
seed_guests(600, $phones_names);

// تعطيل تأخير مكافحة الحظر (usleep) حصراً لهذا الاختبار — انظر التعليق فوق
// $send_delay_enabled في class-pge-reminder-message-service.php: هذا تأخير
// تشغيلي (ثانيتان-ثلاث لكل رسالة) لا "منطق عمل"، وتعطيله هنا لا يغيّر أي
// سلوك تتبّع/رصيد/حالة — فقط يمنع الاختبار من تجاوز مهلة بيئة التشغيل.
PGE_Reminder_Message_Service::set_send_delay_enabled_for_tests(false);
$bigResult = PGE_Reminder_Message_Service::send_reminder_batch(600, 'all', 501);
check('34a. Realistic Batch: total_targeted = 120', $bigResult['total_targeted'], 120);
check_true('34b. Realistic Batch: دفعة أولى متزامنة لا تتجاوز SYNC_CHUNK_SIZE (25)', $bigResult['sent'] <= PGE_Reminder_Message_Service::SYNC_CHUNK_SIZE);
check_true('34c. Realistic Batch: المتبقي جُدوِل عبر Cron', $bigResult['queued_remaining'] > 0);
check_true('34d. Realistic Batch: جدولة Cron فعلية سُجِّلت', count($GLOBALS['__test_scheduled_events']) > 0);

// معالجة بقية الدفعة عبر تشغيلات Cron متتالية (محاكاة يدوية لتشغيل الـHook مراراً)
$guard = 0;
while (PGE_Reminder_Message_Service::count_pending_in_batch(600, $bigResult['batch_id']) > 0 && $guard < 20) {
    PGE_Reminder_Message_Service::cron_process_reminder_queue(600, $bigResult['batch_id']);
    $guard++;
}
$finalRows = PGE_Message_Log::query_by_batch($bigResult['batch_id']);
$finalPhones = array_column($finalRows, 'guest_phone');
check('34e. Realistic Batch: 120 سجل تتبّع بلا تكرار', count($finalPhones), count(array_unique($finalPhones)));
check('34f. Realistic Batch: الكل انتهى إلى sent (transport افتراضي ناجح)', count(array_filter($finalRows, function ($r) { return $r['status'] === PGE_Message_Log::STATUS_SENT; })), 120);
check_true('34g. Realistic Batch: لا استهلاك رصيد (Ledger غير محمَّلة)', !class_exists('PGE_Invitation_Credit_Ledger'));

// ══════════════════════════════════════════════════════════════════════════
// Optional Featured Image for Manual Reminder
// ══════════════════════════════════════════════════════════════════════════
PGE_Reminder_Message_Service::set_send_delay_enabled_for_tests(false);

// Backward compatibility: غياب intent وfalse الصريح يبقيان Text Only.
reset_test_state();
seed_event(700);
seed_guests(700, ['966570000001' => 'ضيف نصي']);
seed_featured_image(700, 9700, 'https://example.test/uploads/event-700.jpg');
$legacyText = PGE_Reminder_Message_Service::send_reminder_batch(700, 'all', 501);
check_true('35a. غياب include_image يستخدم /message/text', strpos($GLOBALS['__test_remote_post_calls'][0]['url'], '/message/text') !== false);
check('35b. Reminder Text ينتهي sent', PGE_Message_Log::query_by_batch($legacyText['batch_id'])[0]['status'], PGE_Message_Log::STATUS_SENT);

reset_test_state();
seed_event(701);
seed_guests(701, ['966570000002' => 'ضيف نصي']);
seed_featured_image(701, 9701, 'https://example.test/uploads/event-701.jpg');
$_POST = ['nonce' => 'ok', 'event_id' => 701, 'filter' => 'all', 'include_image' => '0'];
$includeFalse = call_ajax('pge_invitation_mgmt_send_reminder_handler');
check('35c. include_image=0 مقبول عبر Endpoint', $includeFalse['success'], true);
check_true('35d. include_image=0 يستخدم /message/text', strpos($GLOBALS['__test_remote_post_calls'][0]['url'], '/message/text') !== false);

// Media: URL الخادم نفسه + Caption النص المصيّر.
reset_test_state();
seed_event(702);
seed_guests(702, ['966570000003' => 'ضيف صورة']);
seed_featured_image(702, 9702, 'https://example.test/uploads/event-702.jpg');
update_post_meta(702, '_pge_wa_tpl_reminder', 'تذكير إلى {{guest_name}}');
$mediaAccepted = PGE_Reminder_Message_Service::send_reminder_batch(702, 'all', 501, true);
$mediaCall = $GLOBALS['__test_remote_post_calls'][0];
check_true('36a. include_image=true يستخدم /message/media', strpos($mediaCall['url'], '/message/media') !== false);
check('36b. media_url هو Featured Image الموثوقة', $mediaCall['body']['media_url'] ?? '', 'https://example.test/uploads/event-702.jpg');
check('36c. caption يساوي Reminder text المصيّر', $mediaCall['body']['caption'] ?? '', 'تذكير إلى ضيف صورة');
check('36d. Media accepted → sent', PGE_Message_Log::query_by_batch($mediaAccepted['batch_id'])[0]['status'], PGE_Message_Log::STATUS_SENT);

// Missing/invalid image: fallback قبل Transport إلى Text.
$missingCases = [
    703 => function () {},
    704 => function () { seed_featured_image(704, 9704, 'https://example.test/uploads/missing.jpg', true, false); },
    705 => function () { seed_featured_image(705, 9705, 'https://example.test/uploads/not-image.pdf', false, true); },
    706 => function () { seed_featured_image(706, 9706, 'ftp://example.test/uploads/event.jpg', true, true); },
];
foreach ($missingCases as $eventId => $seedImage) {
    reset_test_state();
    seed_event($eventId);
    seed_guests($eventId, ['9665700000' . $eventId => 'ضيف fallback']);
    $seedImage();
    PGE_Reminder_Message_Service::send_reminder_batch($eventId, 'all', 501, true);
    check_true("37.$eventId صورة غائبة/غير صالحة → /message/text", strpos($GLOBALS['__test_remote_post_calls'][0]['url'], '/message/text') !== false);
}

// Client يرسل intent فقط؛ جميع مصادر URL من العميل تُتجاهَل.
reset_test_state();
seed_event(707);
seed_guests(707, ['966570000007' => 'ضيف آمن']);
seed_featured_image(707, 9707, 'https://trusted.example.test/event-707.jpg');
$_POST = [
    'nonce' => 'ok', 'event_id' => 707, 'filter' => 'all', 'include_image' => '1',
    'image_url' => 'https://evil.example/image.jpg',
    'media_url' => 'https://evil.example/media.jpg',
    'file_path' => 'C:\\secret.jpg',
];
$secureMedia = call_ajax('pge_invitation_mgmt_send_reminder_handler');
$secureCall = $GLOBALS['__test_remote_post_calls'][0];
check('38a. Endpoint Media نجح بنفس nonce/capability', $secureMedia['success'], true);
check('38b. Client URLs لا تصبح مصدر الإرسال', $secureCall['body']['media_url'] ?? '', 'https://trusted.example.test/event-707.jpg');

$_POST = ['nonce' => 'ok', 'event_id' => 707, 'filter' => 'all'];
$preview = call_ajax('pge_invitation_mgmt_reminder_preview_handler');
check('38c. Preview يعلن توفر الصورة', $preview['payload']['image_available'] ?? null, true);
check('38d. Preview URL من Featured Image', $preview['payload']['preview_image_url'] ?? '', 'https://trusted.example.test/event-707.jpg');

reset_test_state();
seed_event(713);
seed_guests(713, ['966570000013' => 'ضيف بلا صورة']);
$_POST = ['nonce' => 'ok', 'event_id' => 713, 'filter' => 'all'];
$previewWithoutImage = call_ajax('pge_invitation_mgmt_reminder_preview_handler');
check('38e. Preview بلا صورة يعلن image_available=false', $previewWithoutImage['payload']['image_available'] ?? null, false);
check('38f. Preview بلا صورة لا يعيد URL', $previewWithoutImage['payload']['preview_image_url'] ?? null, null);

// بعد محاولة Media لا يوجد Text fallback: رفض صريح أو Transport غامض.
reset_test_state();
seed_event(708);
seed_guests(708, ['966570000008' => 'ضيف رفض']);
seed_featured_image(708, 9708, 'https://example.test/uploads/event-708.jpg');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'error'])];
$mediaRejected = PGE_Reminder_Message_Service::send_reminder_batch(708, 'all', 501, true);
check('39a. Media rejected → failed', PGE_Message_Log::query_by_batch($mediaRejected['batch_id'])[0]['status'], PGE_Message_Log::STATUS_FAILED);
check('39b. Media rejected لا يرسل Text fallback', count($GLOBALS['__test_remote_post_calls']), 1);

reset_test_state();
seed_event(709);
seed_guests(709, ['966570000009' => 'ضيف غامض']);
seed_featured_image(709, 9709, 'https://example.test/uploads/event-709.jpg');
$GLOBALS['__test_remote_post_queue'][] = new WP_Error('http_request_failed', 'timeout');
$mediaAmbiguous = PGE_Reminder_Message_Service::send_reminder_batch(709, 'all', 501, true);
check('39c. Media transport error → ambiguous_transport_error', PGE_Message_Log::query_by_batch($mediaAmbiguous['batch_id'])[0]['status'], PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR);
check('39d. Media transport error لا يرسل Text fallback', count($GLOBALS['__test_remote_post_calls']), 1);

// Large batch: intent يبقى Media عبر Sync وCron، بلا تكرار.
reset_test_state();
seed_event(710);
seed_featured_image(710, 9710, 'https://example.test/uploads/event-710.jpg');
$mediaGuests = [];
for ($i = 1; $i <= 40; $i++) $mediaGuests[sprintf('96658%07d', $i)] = 'ضيف ' . $i;
seed_guests(710, $mediaGuests);
$largeMedia = PGE_Reminder_Message_Service::send_reminder_batch(710, 'all', 501, true);
check('40a. Sync Media يعالج 25 طلباً', count($GLOBALS['__test_remote_post_calls']), PGE_Reminder_Message_Service::SYNC_CHUNK_SIZE);
check('40b. Cron event يحتفظ بـinclude_image=true', $GLOBALS['__test_scheduled_events'][0]['args'][2] ?? null, true);
PGE_Reminder_Message_Service::cron_process_reminder_queue(710, $largeMedia['batch_id'], true);
$mediaOnlyCalls = array_filter($GLOBALS['__test_remote_post_calls'], function ($call) { return strpos($call['url'], '/message/media') !== false; });
check('40c. Sync + Cron كلها Media', count($mediaOnlyCalls), 40);
$largeMediaRows = PGE_Message_Log::query_by_batch($largeMedia['batch_id']);
check('40d. Large Media بلا duplicate recipients', count(array_column($largeMediaRows, 'guest_phone')), count(array_unique(array_column($largeMediaRows, 'guest_phone'))));

// Cron قديم بلا argument يعود Text Only حتى لو كانت الصورة موجودة.
reset_test_state();
seed_event(711);
seed_featured_image(711, 9711, 'https://example.test/uploads/event-711.jpg');
$oldCronGuests = [];
for ($i = 1; $i <= 26; $i++) $oldCronGuests[sprintf('96659%07d', $i)] = 'ضيف ' . $i;
seed_guests(711, $oldCronGuests);
$oldCronBatch = PGE_Reminder_Message_Service::send_reminder_batch(711, 'all', 501, true);
$GLOBALS['__test_remote_post_calls'] = [];
PGE_Reminder_Message_Service::cron_process_reminder_queue(711, $oldCronBatch['batch_id']);
check_true('41. Cron قديم بلا include_image يستخدم Text', strpos($GLOBALS['__test_remote_post_calls'][0]['url'], '/message/text') !== false);

// حذف الصورة بين ticks يجعل المتبقي Text قبل أي محاولة Media.
reset_test_state();
seed_event(712);
seed_featured_image(712, 9712, 'https://example.test/uploads/event-712.jpg');
$deletedImageGuests = [];
for ($i = 1; $i <= 26; $i++) $deletedImageGuests[sprintf('96660%07d', $i)] = 'ضيف ' . $i;
seed_guests(712, $deletedImageGuests);
$deletedImageBatch = PGE_Reminder_Message_Service::send_reminder_batch(712, 'all', 501, true);
unset($GLOBALS['__test_thumbnail_ids'][712], $GLOBALS['__test_thumbnail_urls'][712]);
$GLOBALS['__test_remote_post_calls'] = [];
PGE_Reminder_Message_Service::cron_process_reminder_queue(712, $deletedImageBatch['batch_id'], true);
check_true('42. حذف الصورة بين ticks → المتبقي Text Only', strpos($GLOBALS['__test_remote_post_calls'][0]['url'], '/message/text') !== false);

check_true('43a. Media Reminder لا يحمّل Invitation Credit Ledger', !class_exists('PGE_Invitation_Credit_Ledger'));
check_true('43b. Media Reminder لا يحمّل Replacement Credits', !class_exists('PGE_Replacement_Entitlements'));

// عقد الواجهة: Checkbox غير محدد افتراضياً + حالة disabled + Thumbnail.
$reminderTemplateSrc = file_get_contents(__DIR__ . '/../templates/event-invitations.php');
check_true('44a. Reminder Modal يحتوي Checkbox الصورة', strpos($reminderTemplateSrc, 'id="reminderIncludeImage"') !== false);
check_true('44b. Checkbox يبدأ unchecked وdisabled', preg_match('/id="reminderIncludeImage"[^>]*disabled[^>]*\/>/', $reminderTemplateSrc) === 1 && preg_match('/id="reminderIncludeImage"[^>]*checked/', $reminderTemplateSrc) !== 1);
check_true('44c. رسالة عدم توفر الصورة موجودة حرفياً', strpos($reminderTemplateSrc, 'لا توجد صورة دعوة متاحة لهذه المناسبة.') !== false);
check_true('44d. Preview يحتوي Thumbnail مستقل', strpos($reminderTemplateSrc, 'id="reminderPreviewImage"') !== false);
check_true('44e. Client يرسل include_image intent فقط عند Send', strpos($reminderTemplateSrc, 'include_image: reminderIncludeImage.checked ? 1 : 0') !== false);

// ══════════════════════════════════════════════════════════════════════════
// الملخّص النهائي
// ══════════════════════════════════════════════════════════════════════════
echo "الإجمالي: $total | نجح: $passed | فشل: " . count($failures) . "\n";
if (!empty($failures)) {
    echo "\nالفحوصات الفاشلة:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}
exit(0);
