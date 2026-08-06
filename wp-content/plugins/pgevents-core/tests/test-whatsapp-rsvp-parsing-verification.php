<?php
/**
 * ============================================================================
 * اختبار تنفيذي حقيقي — "RC1 Final Gap: WhatsApp RSVP Reply Parsing Verification"
 * ============================================================================
 * "This task is TESTING and AUDIT only. Do NOT redesign. Do NOT add features.
 * Do NOT change business rules unless a real production bug is discovered."
 *
 * يُنفِّذ هذا الملف الكود الإنتاجي الحقيقي فعلياً (لا مرآة منطقية) عبر:
 *   - includes/class-cartat-handler.php     (parse_rsvp_reply/record_rsvp/handle_incoming_message عبر Reflection + استدعاء حقيقي)
 *   - includes/class-ultramsg-handler.php   (نفس الثلاثة)
 *   - includes/class-pge-invitation-repository.php (current_or_null/get_qr_version/regenerate_qr)
 *   - includes/class-pge-invitation-service.php    (create/delete/cancel + Audit)
 *   - includes/class-pge-invitation-management-audit.php (list_for_invitation)
 *   - includes/class-pge-guest-resolution-service.php    (resolve_by_phone/resolve_from_qr)
 *   - includes/class-pge-checkin-qr-service.php
 *   - includes/helpers.php (pge_norm_phone وقوالب واتساب الحقيقية)
 *
 * pge_maybe_grant_replacement_entitlement() تُستبدَل هنا بجاسوس (spy) مسجِّل
 * استدعاءات — منطقها الداخلي مُختبَر بالكامل في test-replacement-entitlement-
 * grant.php وtest-replacement-credit-consumption.php (خارج نطاق هذه المهمة
 * صراحةً)؛ هنا يهمّنا فقط: هل استُدعيت؟ بأي old_reply/new_reply؟ — لإثبات/نفي
 * تكافؤ Cartat وUltraMsg في هذه النقطة تحديداً.
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-whatsapp-rsvp-parsing-verification.php
 */

define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir());
if (!defined('PGE_PATH')) define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

// ── Stubs عامة ──────────────────────────────────────────────────────────────

$GLOBALS['__test_registered_hooks'] = [];
function add_action($hook, $callback, ...$rest) { $GLOBALS['__test_registered_hooks'][$hook] = $callback; }
function add_filter(...$args) { /* no-op */ }
function register_rest_route(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }
function register_deactivation_hook(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) { function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return trim((string) $v); } }
if (!function_exists('absint')) { function absint($value) { return abs((int) $value); } }
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); } }
if (!function_exists('wp_hash')) { function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed'); } }
if (!function_exists('__')) { function __($text, $domain = 'default') { return $text; } }
if (!function_exists('pge_norm_phone')) { function pge_norm_phone($v) { return preg_replace('/\D+/', '', trim((string) $v)); } }
if (!function_exists('home_url')) { function home_url($path = '') { return 'https://monasbat.test' . $path; } }
if (!function_exists('date_i18n')) { function date_i18n($format, $timestamp = null) { return 'DATE'; } }

$GLOBALS['__test_clock_counter'] = 0;
function current_time($type = 'mysql', $gmt = 0) {
    $GLOBALS['__test_clock_counter']++;
    return gmdate('Y-m-d H:i:s', strtotime('2026-08-01 00:00:00') + $GLOBALS['__test_clock_counter']);
}

$GLOBALS['__test_options'] = [
    'pge_cartat_api_token'      => 'TEST-TOKEN',
    'pge_cartat_country_code'   => '966',
    'pge_ultramsg_instance_id'  => 'TEST-INSTANCE',
    'pge_ultramsg_token'        => 'TEST-TOKEN-UM',
];
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$name] : $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['__test_options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['__test_options'][$name]); return true; }

$GLOBALS['__test_posts'] = [];
function set_test_event($event_id, $author_id, $post_type = 'pge_event') {
    $GLOBALS['__test_posts'][$event_id] = (object) ['ID' => $event_id, 'post_type' => $post_type, 'post_author' => $author_id, 'post_title' => 'مناسبة ' . $event_id];
}
function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
function get_post_field($field, $post_id) { $p = get_post($post_id); if (!$p) return ''; return $p->{$field} ?? ''; }
function get_the_title($post_id) { $p = get_post($post_id); return $p ? $p->post_title : ''; }
function get_permalink($post_id) { return 'https://monasbat.test/event/' . $post_id . '/'; }
function get_the_post_thumbnail_url($post_id, $size = 'full') { return ''; }

$GLOBALS['__test_post_meta'] = [];
function get_post_meta($post_id, $key = '', $single = false) {
    $value = $GLOBALS['__test_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_post_meta($post_id, $key, $value) { $GLOBALS['__test_post_meta'][$post_id][$key] = $value; return true; }
function delete_post_meta($post_id, $key) { unset($GLOBALS['__test_post_meta'][$post_id][$key]); return true; }

function get_current_user_id() { return $GLOBALS['__test_current_user_id'] ?? 0; }
function is_user_logged_in() { return true; }

$GLOBALS['__test_scheduled_events'] = [];
function wp_schedule_single_event($timestamp, $hook, $args = []) { $GLOBALS['__test_scheduled_events'][] = compact('timestamp', 'hook', 'args'); return true; }
function spawn_cron() { return true; }

// ── نقل HTTP: قابل للتحكم بالكامل، لا يخرج فعلياً عبر الشبكة ────────────────

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message;
        public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

$GLOBALS['__test_remote_post_calls'] = [];
function wp_remote_post($url, $args = []) {
    $GLOBALS['__test_remote_post_calls'][] = ['url' => $url, 'args' => $args];
    return ['body' => json_encode(['status' => 'sent', 'id' => 'msg-' . count($GLOBALS['__test_remote_post_calls'])])];
}
function wp_remote_head($url, $args = []) { return new WP_Error('no_network', 'شبكة معطّلة في الاختبار'); }
function wp_remote_retrieve_body($response) { return is_array($response) ? ($response['body'] ?? '') : ''; }
function wp_remote_retrieve_response_code($response) { return 0; }
function wp_remote_retrieve_header($response, $header) { return ''; }

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data; public $status;
        public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
        public function get_data() { return $this->data; }
    }
}

class Fake_Wa_Rest_Request {
    private $body;
    public function __construct(string $body) { $this->body = $body; }
    public function get_body() { return $this->body; }
}

// ── جاسوس Replacement Entitlement (منطقه الداخلي خارج النطاق) ──────────────

$GLOBALS['__test_replacement_calls'] = [];
function pge_maybe_grant_replacement_entitlement($event_id, $guest_phone, $old_reply, $new_reply) {
    $GLOBALS['__test_replacement_calls'][] = [
        'event_id' => $event_id, 'phone' => $guest_phone,
        'old_reply' => $old_reply, 'new_reply' => $new_reply,
    ];
    return ['result' => 'granted'];
}

// ============================================================================
// Fake $wpdb — يخدم جدولي wp_pge_event_rsvps وwp_pge_invitation_mgmt_audit_log
// ============================================================================
class Fake_Wpdb_Wa {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $rsvps = [];
    private $rsvp_next_id = 1;
    public $audit = [];
    private $audit_next_id = 1;

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? ''; $i++;
            return $m[0] === '%d' ? (string) (int) $val : "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    private function extract_int($sql, $col) { if (preg_match('/\b' . preg_quote($col, '/') . '\s*=\s*(\d+)/', $sql, $m)) return (int) $m[1]; return null; }
    private function extract_str($sql, $col) { if (preg_match('/\b' . preg_quote($col, '/') . "\s*=\s*'([^']*)'/", $sql, $m)) return $m[1]; return null; }
    private function is_rsvps_table($sql) { return strpos($sql, 'pge_event_rsvps') !== false; }
    private function is_audit_table($sql) { return strpos($sql, 'pge_invitation_mgmt_audit_log') !== false; }

    public function get_row($sql, $output = null) {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null) {
        if ($this->is_audit_table($sql)) {
            $event_id = $this->extract_int($sql, 'event_id');
            $phone = $this->extract_str($sql, 'guest_phone');
            $rows = array_values(array_filter($this->audit, function ($r) use ($event_id, $phone) {
                if ($event_id !== null && (int) $r['event_id'] !== $event_id) return false;
                if ($phone !== null && (string) $r['guest_phone'] !== $phone) return false;
                return true;
            }));
            usort($rows, fn($a, $b) => $a['id'] <=> $b['id']);
            return $output === ARRAY_A ? $rows : array_map(fn($r) => (object) $r, $rows);
        }
        if (!$this->is_rsvps_table($sql)) return [];
        $id = $this->extract_int($sql, 'id');
        $event_id = $this->extract_int($sql, 'event_id');
        $phone = $this->extract_str($sql, 'guest_phone');
        $rows = array_values(array_filter($this->rsvps, function ($r) use ($id, $event_id, $phone) {
            if ($id !== null && (int) $r['id'] !== $id) return false;
            if ($event_id !== null && (int) $r['event_id'] !== $event_id) return false;
            if ($phone !== null && (string) $r['guest_phone'] !== $phone) return false;
            return true;
        }));
        usort($rows, fn($a, $b) => $a['id'] <=> $b['id']);
        return $output === ARRAY_A ? $rows : array_map(fn($r) => (object) $r, $rows);
    }

    public function get_var($sql) {
        if (strpos($sql, 'COALESCE(SUM(1 + companions), 0)') !== false) {
            $event_id = $this->extract_int($sql, 'event_id');
            $sum = 0;
            foreach ($this->rsvps as $r) {
                if ($event_id !== null && (int) $r['event_id'] !== $event_id) continue;
                if (($r['reply'] ?? '') !== 'yes') continue;
                $sum += 1 + (int) ($r['companions'] ?? 0);
            }
            return (string) $sum;
        }
        return null;
    }

    public function insert($table, $data, $format = null) {
        if (strpos($table, 'pge_invitation_mgmt_audit_log') !== false) {
            $id = $this->audit_next_id++;
            $this->audit[$id] = array_merge($data, ['id' => $id]);
            $this->insert_id = $id;
            return 1;
        }
        if (strpos($table, 'pge_event_rsvps') === false) return false;
        $event_id = (int) ($data['event_id'] ?? 0);
        $phone = (string) ($data['guest_phone'] ?? '');
        foreach ($this->rsvps as $r) {
            if ((int) $r['event_id'] === $event_id && (string) $r['guest_phone'] === $phone) {
                $this->last_error = "Duplicate entry '{$event_id}-{$phone}' for key 'event_phone'";
                return false;
            }
        }
        $id = $this->rsvp_next_id++;
        $row = array_merge([
            'guest_name' => null, 'companions' => 0, 'note' => null, 'reply' => 'pending',
            'checked_in' => 0, 'checked_in_at' => null,
            'checked_in_by_assignment_id' => null, 'checkin_method' => null, 'actual_entered_count' => null,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], $data, ['id' => $id]);
        $this->rsvps[$id] = $row;
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        if (strpos($table, 'pge_event_rsvps') === false || !isset($where['id'])) return false;
        $id = (int) $where['id'];
        if (!isset($this->rsvps[$id])) return 0;
        $this->rsvps[$id] = array_merge($this->rsvps[$id], $data);
        return 1;
    }
}
$GLOBALS['wpdb'] = new Fake_Wpdb_Wa();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ============================================================================
// تحميل حقيقي لكل الطبقات المعنية — بلا مرآة منطقية
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/class-pge-guest-resolution-service.php';
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php';
require_once __DIR__ . '/../includes/class-pge-cartat-transport.php';
require_once __DIR__ . '/../includes/class-cartat-handler.php';
require_once __DIR__ . '/../includes/class-ultramsg-handler.php';
require_once __DIR__ . '/../includes/rsvp-handler.php';
// عمداً: لا نحمّل includes/replacement-entitlement-grant.php الحقيقي — الجاسوس
// أعلاه يحل محله (function_exists() يجده أولاً لأنه عُرِّف قبل هذا التحميل).

// ============================================================================
// أدوات الاختبار
// ============================================================================
$total = 0; $passed = 0; $failures = [];
function check($label, $actual, $expected) {
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) { $passed++; /* echo "PASS  $label\n"; */ }
    else { $failures[] = $label; echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}
function check_true($label, $cond) { check($label, (bool) $cond, true); }

$parse_cartat = new ReflectionMethod('Mon_Cartat_Handler', 'parse_rsvp_reply');
$parse_cartat->setAccessible(true);
$parse_um = new ReflectionMethod('Mon_UltraMsg_Handler', 'parse_rsvp_reply');
$parse_um->setAccessible(true);
$record_cartat = new ReflectionMethod('Mon_Cartat_Handler', 'record_rsvp');
$record_cartat->setAccessible(true);
$record_um = new ReflectionMethod('Mon_UltraMsg_Handler', 'record_rsvp');
$record_um->setAccessible(true);

$cartat = new Mon_Cartat_Handler();
$um = new Mon_UltraMsg_Handler();

function seed_pending($key_suffix, array $data) {
    update_option('pge_wa_pending_' . $key_suffix, $data, false);
}
function seed_pending_lid($lid, array $data) { update_option('pge_wa_pending_lid_' . $lid, $data, false); }
function cartat_payload($from, $body, $from_me = false) {
    return json_encode(['event' => 'message_received', 'from' => $from, 'body' => $body, 'fromMe' => $from_me]);
}
function um_payload($from, $body, $type = 'chat', $self = '0') {
    return json_encode(['event_type' => 'message_received', 'data' => ['from' => $from, 'body' => $body, 'type' => $type, 'self' => $self]]);
}
function seed_pending_msgid($msg_id, array $data) { update_option('pge_wa_pending_msgid_' . $msg_id, $data, false); }
function cartat_ack_payload($ack, $id, $to) {
    return json_encode(['event' => 'ack', 'ack' => $ack, 'id' => $id, 'to' => $to]);
}

echo "=== RC1 Final Gap: WhatsApp RSVP Reply Parsing Verification — تنفيذي حقيقي ===\n";

// ============================================================================
// 1. Files reviewed / Entry points — تحقق بنيوي (Static Audit مبدئي)
// ============================================================================
$src_cartat = file_get_contents(__DIR__ . '/../includes/class-cartat-handler.php');
$src_um     = file_get_contents(__DIR__ . '/../includes/class-ultramsg-handler.php');
check_true('ST1. Cartat: توجد نسخة واحدة فقط من parse_rsvp_reply()', substr_count($src_cartat, 'function parse_rsvp_reply') === 1);
check_true('ST2. UltraMsg: توجد نسخة واحدة فقط من parse_rsvp_reply()', substr_count($src_um, 'function parse_rsvp_reply') === 1);
check_true('ST3. Cartat: توجد نسخة واحدة فقط من record_rsvp()', substr_count($src_cartat, 'function record_rsvp') === 1);
check_true('ST4. UltraMsg: توجد نسخة واحدة فقط من record_rsvp()', substr_count($src_um, 'function record_rsvp') === 1);
check_true('ST5. لا يوجد ملف .disabled متعلق بواتساب (فقط checkin-handler/salla-api معطّلان، غير متعلقين)',
    !file_exists(__DIR__ . '/../includes/class-cartat-handler.php.disabled') && !file_exists(__DIR__ . '/../includes/class-ultramsg-handler.php.disabled'));
check_true('ST6. كلا المزوّدين يستدعيان current_or_null() الموحَّدة (لا نسخة موازية من شرط التصفير)',
    strpos($src_cartat, 'PGE_Invitation_Repository::current_or_null(') !== false
    && strpos($src_um, 'PGE_Invitation_Repository::current_or_null(') !== false);
check_true('ST7. لا نسخة أخرى من parse_rsvp_reply/record_rsvp في أي ملف آخر بالمشروع (بحث شامل)', (function () {
    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
    $hits = 0;
    foreach ($dir as $f) {
        if ($f->getExtension() !== 'php') continue;
        $p = $f->getPathname();
        if (strpos($p, 'class-cartat-handler.php') !== false || strpos($p, 'class-ultramsg-handler.php') !== false) continue;
        if (strpos($p, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) continue;
        $c = file_get_contents($p);
        if (strpos($c, 'function parse_rsvp_reply') !== false || strpos($c, 'function record_rsvp') !== false) $hits++;
    }
    return $hits === 0;
})());

// ============================================================================
// 2. Positive scenarios — parse_rsvp_reply() عبر Reflection، كلا المزوّدين
// نُسمّي كل حالة كما وردت حرفياً في المتطلبات، ونُسجِّل السلوك الحقيقي —
// PASS يعني "يطابق سلوك الإنتاج الفعلي الحقيقي"، سواء كان دعماً أو عدم دعم.
// ============================================================================
// أ. كلمات مفتاحية مدعومة فعلياً في كلا المزوّدين (yes)
$supported_yes = [
    '1 (رقم)'                    => '1',
    'Yes (إنجليزي صغير)'         => 'yes',
    'YES (إنجليزي كبير)'         => 'YES',
    'YeS (مختلط الحالة)'         => 'YeS',
    'نعم'                         => 'نعم',
    'حاضر'                        => 'حاضر',
    'سأحضر'                       => 'سأحضر',
    'حضور'                        => 'حضور',
    'موافق'                       => 'موافق',
    'اوكي'                        => 'اوكي',
    'ok'                          => 'ok',
    '✅ (إيموجي كرمز كامل)'       => '✅',
    'مسافات بادئة'                => '   yes',
    'مسافات لاحقة'                => 'yes   ',
    'مسافات متعددة بالأطراف'      => '   yes   ',
    'سطر جديد قبل'                 => "\nyes",
    'سطر جديد بعد'                 => "yes\n",
    'عربي بمسافات بادئة ولاحقة'   => '  حاضر  ',
];
foreach ($supported_yes as $label => $input) {
    check("P-CARTAT: $label → yes", $parse_cartat->invoke($cartat, $input), 'yes');
    check("P-UM: $label → yes", $parse_um->invoke($um, $input), 'yes');
}
// الأرقام العربية-هندية — موثَّقة كدعم Cartat فقط حالياً (CLAUDE.md صراحةً)
check('P-CARTAT: ١ (رقم عربي) → yes (مدعوم في Cartat فقط)', $parse_cartat->invoke($cartat, '١'), 'yes');
check('P-UM: ١ (رقم عربي) → "" (غير مدعوم بعد في UltraMsg — فجوة موثَّقة مسبقاً، ليست خللاً جديداً)', $parse_um->invoke($um, '١'), '');
check('P-CARTAT: ٢ (رقم عربي اعتذار) → no', $parse_cartat->invoke($cartat, '٢'), 'no');
check('P-UM: ٢ (رقم عربي اعتذار) → "" (نفس الفجوة الموثَّقة)', $parse_um->invoke($um, '٢'), '');

// ب. سيناريوهات "Positive" المطلوبة حرفياً في التكليف لكنها غير مدعومة فعلياً
// بالكود الإنتاجي الحالي (مطابقة exact-match على قائمة كلمات ثابتة) — نُثبت
// السلوك الحقيقي (فشل التعرّف، وليس تخميناً) دون تعديل الكود، لأن توسيع
// قائمة الكلمات المقبولة هو تغيير قاعدة عمل (مفردات مقبولة) يتطلب قراراً
// منتجياً منفصلاً، وهو خارج تفويض "TESTING and AUDIT only" لهذه المهمة.
$unsupported_but_required = [
    'Accept (إنجليزي — غير موجود في قائمة yes الفعلية)' => 'accept',
    'تمام (غير موجودة في قائمة yes الفعلية)'            => 'تمام',
    'تم (غير موجودة في قائمة yes الفعلية)'               => 'تم',
    'Mixed Arabic/English (رسالة مركّبة — لا تطابق تاماً أي عنصر واحد)' => 'Yes حاضر',
    'إيموجي قبل الكلمة (😊حاضر — لا يُقتطع، trim() لا يُزيل الإيموجي)'    => '😊حاضر',
    'إيموجي بعد الكلمة (yes😊 — لا يُقتطع)'                              => 'yes😊',
];
foreach ($unsupported_but_required as $label => $input) {
    check("P-GAP-CARTAT: $label → \"\" (غير معروف، سلوك حقيقي موثَّق)", $parse_cartat->invoke($cartat, $input), '');
    check("P-GAP-UM: $label → \"\" (نفس السلوك، متطابق بين المزوّدين)", $parse_um->invoke($um, $input), '');
}

// ج. كلمات no المدعومة فعلياً (تكافؤ سلبي للتأكد من عدم كسر مسار الاعتذار)
$supported_no = ['2', 'لا', 'no', 'اعتذر', 'معذرة', '❌'];
foreach ($supported_no as $input) {
    check("P-NO-CARTAT: '$input' → no", $parse_cartat->invoke($cartat, $input), 'no');
    check("P-NO-UM: '$input' → no", $parse_um->invoke($um, $input), 'no');
}

// ============================================================================
// 3. Negative scenarios — نص/كلمة غير معروفة (على مستوى parse فقط)
// ============================================================================
$negatives = ['نص عشوائي طويل لا علاقة له بالحضور أو الاعتذار', '3', '99', 'maybe', 'ربما', '', '   ', "\n\n"];
foreach ($negatives as $n) {
    check("N-CARTAT: '" . addslashes($n) . "' → \"\"", $parse_cartat->invoke($cartat, $n), '');
    check("N-UM: '" . addslashes($n) . "' → \"\"", $parse_um->invoke($um, $n), '');
}

// ============================================================================
// من هنا فصاعداً: اختبارات تنفّذ handle_incoming_message() الحقيقية بالكامل
// (البوابة/LID/الهاتف/التسجيل/مسح pending/رسائل التأكيد) — إعداد بيانات حقيقية
// ============================================================================
set_test_event(8001, 401);
$GLOBALS['__test_current_user_id'] = 401;

// ============================================================================
// 4. Entry point Cartat — دورة كاملة ناجحة عبر REST callback الحقيقي
// ============================================================================
PGE_Invitation_Service::create(8001, '0591112222', 'ضيف كارتات', '', 401);
seed_pending('591112222', ['event_id' => 8001, 'wa_number' => '591112222', 'norm_phone' => '591112222', 'original_phone' => '0591112222', 'invite_code' => 'ABCD-1234']);
$resp1 = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('591112222@c.us', '1')));
check('E1. Cartat handle_incoming_message: status=success', $resp1->get_data()['status'], 'success');
check('E1ب. الرد المُخزَّن فعلياً = yes', PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0591112222')['guest']['reply'], 'yes');
check_true('E1ج. pending الأصلي حُذف بعد المعالجة (لا يُعاد استخدامه)', get_option('pge_wa_pending_591112222', null) === null);
check_true('E1د. رسالة تأكيد أُرسلت فعلياً عبر transport (wp_remote_post استُدعيت)', count($GLOBALS['__test_remote_post_calls']) >= 1);

// ============================================================================
// 5. Entry point UltraMsg — دورة كاملة ناجحة، مقارنة مباشرة بسلوك Cartat
// ============================================================================
PGE_Invitation_Service::create(8001, '0591113333', 'ضيف ألترامسج', '', 401);
seed_pending('591113333', ['event_id' => 8001, 'wa_number' => '591113333', 'original_phone' => '0591113333']);
$resp2 = $um->handle_incoming_message(new Fake_Wa_Rest_Request(um_payload('591113333@c.us', '1')));
check('E2. UltraMsg handle_incoming_message: status=success', $resp2->get_data()['status'], 'success');
check('E2ب. الرد المُخزَّن فعلياً = yes', PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0591113333')['guest']['reply'], 'yes');

// ============================================================================
// 6. Negative عند مستوى الـwebhook الكامل: رد غير معروف يُرسل رسالة تذكير فقط
// ============================================================================
PGE_Invitation_Service::create(8001, '0591114444', 'رد غامض', '', 401);
seed_pending('591114444', ['event_id' => 8001, 'wa_number' => '591114444', 'original_phone' => '0591114444']);
$before_calls = count($GLOBALS['__test_remote_post_calls']);
$resp3 = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('591114444@c.us', 'مرحبا كيف الحال')));
check('N-E1. رد غير مفهوم → status=invalid_reply', $resp3->get_data()['status'], 'invalid_reply');
check_true('N-E1ب. لم يُسجَّل أي RSVP فعلي (تبقى pending الأصلية غير محذوفة)', get_option('pge_wa_pending_591114444', null) !== null);
check_true('N-E1ج. رسالة تذكير أُرسلت (استدعاء واحد إضافي فقط)', count($GLOBALS['__test_remote_post_calls']) === $before_calls + 1);

// Sticker/Image/Voice payload — جسم فارغ (لا نص) → 'empty' يُتجاهَل قبل أي تحليل
check('N-E2. Cartat: حمولة Sticker (body فارغ) → ignored/empty', $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('591119999@c.us', '')))->get_data(), ['status' => 'ignored', 'reason' => 'empty']);
check('N-E3. UM: حمولة صورة (type=image, body فارغ) → ignored/empty', $um->handle_incoming_message(new Fake_Wa_Rest_Request(um_payload('591119999@c.us', '', 'image')))->get_data(), ['status' => 'ignored', 'reason' => 'empty']);
// Empty payload بالكامل (JSON فارغ/غير صالح)
check('N-E4. Cartat: JSON فارغ تماماً {} → ignored (event غير معروف)', $cartat->handle_incoming_message(new Fake_Wa_Rest_Request('{}'))->get_data(), ['status' => 'ignored', 'reason' => 'unknown']);
check('N-E5. Cartat: حمولة تالفة (ليست JSON) → لا استثناء فادح، ignored', $cartat->handle_incoming_message(new Fake_Wa_Rest_Request('not-json-at-all'))->get_data(), ['status' => 'ignored', 'reason' => 'unknown']);
// Missing phone — from فارغ
check('N-E6. Cartat: from فارغ → ignored/empty', $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('', '1')))->get_data(), ['status' => 'ignored', 'reason' => 'empty']);
// Unknown phone — لا pending إطلاقاً
check('N-E7. Cartat: رقم غير معروف بلا pending → no_pending', $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('590000000@c.us', '1')))->get_data(), ['status' => 'no_pending']);
check('N-E8. UM: رقم غير معروف بلا pending → no_pending', $um->handle_incoming_message(new Fake_Wa_Rest_Request(um_payload('590000000@c.us', '1')))->get_data(), ['status' => 'no_pending']);
// رسالة صادرة (fromMe/self) تُتجاهَل قبل أي معالجة
check('N-E9. Cartat: fromMe=true → ignored/outgoing', $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('591112222@c.us', '1', true)))->get_data(), ['status' => 'ignored', 'reason' => 'outgoing']);
check('N-E10. UM: self=1 → ignored/outgoing', $um->handle_incoming_message(new Fake_Wa_Rest_Request(um_payload('591112222@c.us', '1', 'chat', '1')))->get_data(), ['status' => 'ignored', 'reason' => 'outgoing']);

// Unknown event — pending يشير إلى event_id لا يقابله أي منشور حقيقي
seed_pending('599990000', ['event_id' => 999999, 'wa_number' => '599990000', 'original_phone' => '0599990000']);
$resp_unknown_event = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('599990000@c.us', '1')));
check('N-E11. حدث لمناسبة غير موجودة: التسجيل ينجح رغم ذلك (لا تحقق من وجود المنشور في مسار الكتابة) — سلوك حقيقي موثَّق كفجوة، ليس خللاً أمنياً', $resp_unknown_event->get_data()['status'], 'success');

// ============================================================================
// 7. LID Mapping — الصيغة/الأولوية/سلسلة fallback/تعدد الحلول
// ============================================================================
// أ. الصيغة المباشرة (رقم هاتف)
PGE_Invitation_Service::create(8001, '0592220001', 'phone-tier', '', 401);
seed_pending('592220001', ['event_id' => 8001, 'wa_number' => '592220001', 'original_phone' => '0592220001']);
check('L1. تحليل عبر الصيغة 2 (هاتف مباشر) — phone tier فقط بلا LID', $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('592220001@c.us', '1')))->get_data()['status'], 'success');

// ب. صيغة 00 (fallback الثالثة)
PGE_Invitation_Service::create(8001, '0592220002', '00-tier', '', 401);
seed_pending('00592220002', ['event_id' => 8001, 'wa_number' => '592220002', 'original_phone' => '0592220002']);
check('L2. تحليل عبر الصيغة 3 (00+هاتف) — لا وجود لصيغة 2 إطلاقاً', $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('592220002@c.us', '1')))->get_data()['status'], 'success');

// ج. LID (الصيغة 1 — الأولوية القصوى عند from يحوي @lid)
PGE_Invitation_Service::create(8001, '0592220003', 'lid-tier', '', 401);
seed_pending_lid('7001112223333', ['event_id' => 8001, 'wa_number' => '592220003', 'original_phone' => '0592220003']);
check('L3. تحليل عبر LID (صيغة 1) — from ينتهي بـ@lid', $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('7001112223333@lid', '1')))->get_data()['status'], 'success');

// د. سلسلة fallback كاملة: لا LID، لا هاتف مباشر، فقط 00 — يجب أن يصل للمستوى الثالث بنجاح
PGE_Invitation_Service::create(8001, '0592220004', 'fallback-chain', '', 401);
seed_pending('00592220004', ['event_id' => 8001, 'wa_number' => '592220004', 'original_phone' => '0592220004']);
$fallback_result = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('592220004@c.us', '1')));
check('L4. سلسلة fallback: لا LID مطابق ولا هاتف مباشر → يصل لصيغة 00 بنجاح', $fallback_result->get_data()['status'], 'success');

// هـ. تعدد الحلول (Duplicate resolution) — LID وهاتف مباشر موجودان معاً بنفس
// اللحظة لهاتفين مختلفين تماماً؛ التحقق من عدم تسرّب/اختلاط أحدهما بالآخر
PGE_Invitation_Service::create(8001, '0592220005', 'دعوة-أ (عبر LID)', '', 401);
PGE_Invitation_Service::create(8001, '0592220006', 'دعوة-ب (عبر هاتف مباشر)', '', 401);
seed_pending_lid('8887776665555', ['event_id' => 8001, 'wa_number' => '592220005', 'original_phone' => '0592220005']);
seed_pending('592220006', ['event_id' => 8001, 'wa_number' => '592220006', 'original_phone' => '0592220006']);
// رد عبر LID → يجب أن يُحل فقط لصاحب LID (0592220005)، لا يمس دعوة 0592220006 إطلاقاً
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('8887776665555@lid', '1')));
check('L5. تعدد الحلول: رد عبر LID يُحل فقط للـLID صاحبه', PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0592220005')['guest']['reply'], 'yes');
check('L5ب. تعدد الحلول: الدعوة الأخرى (هاتف مباشر) غير متأثرة إطلاقاً — لا صف RSVP أُنشئ لها بعد (not_found، لأن صف RSVP يُنشأ فقط عند أول رد فعلي)', PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0592220006')['result'], 'not_found');
// رد عبر الهاتف المباشر لاحقاً → يُحل فقط لصاحبه هو (0592220006)، رغم بقاء
// مفتاح LID الآخر مخزَّناً في وقت واحد تقريباً — لا اختلاط بين القناتين
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('592220006@c.us', '2')));
check('L6. تعدد الحلول: رد عبر الهاتف المباشر يُحل فقط لصاحبه، ولا يُعيد فتح دعوة LID المُغلَقة مسبقاً', PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0592220006')['guest']['reply'], 'no');
check('L6ب. الدعوة عبر LID (أُغلقت في L5) لم تتأثر برد لاحق على قناة أخرى', PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0592220005')['guest']['reply'], 'yes');

// ============================================================================
// 8. Phone normalization — pge_norm_phone عبر صيغ مدعومة متعددة
// ============================================================================
$phone_formats = [
    '0591234567'      => '0591234567',
    '00970591234567'  => '00970591234567',
    '+970591234567'   => '970591234567',
    '970-591-234-567' => '970591234567',
    '(059) 123-4567'  => '0591234567',
    ' 0591234567 '    => '0591234567',
];
foreach ($phone_formats as $input => $expected) {
    check("PH1. pge_norm_phone('$input') → '$expected'", pge_norm_phone($input), $expected);
}

// ============================================================================
// 9. Idempotency
// ============================================================================
// أ. نفس الرد مرتين على التوالي — نفس rsvp_id، لا صف مكرر
PGE_Invitation_Service::create(8001, '0593330001', 'idempotent-same', '', 401);
seed_pending('593330001', ['event_id' => 8001, 'wa_number' => '593330001', 'original_phone' => '0593330001']);
$rid_a1 = $record_cartat->invoke($cartat, 8001, '0593330001', 'yes');
$rid_a2 = $record_cartat->invoke($cartat, 8001, '0593330001', 'yes');
check('ID1. نفس الرد مرتين: نفس rsvp_id (لا إدراج مكرر)', $rid_a2, $rid_a1);
check('ID1ب. عدد الصفوف الفعلي لم يزد', count(array_filter($wpdb->rsvps, fn($r) => (int) $r['event_id'] === 8001 && $r['guest_phone'] === '0593330001')), 1);

// ب. رد مختلف لاحقاً (yes→no) — يُحدِّث نفس الصف، ويستدعي Replacement Entitlement (Cartat)
$GLOBALS['__test_replacement_calls'] = [];
$record_cartat->invoke($cartat, 8001, '0593330001', 'no');
check('ID2. Cartat: انتقال yes→no يستدعي pge_maybe_grant_replacement_entitlement() مرة واحدة', count($GLOBALS['__test_replacement_calls']), 1);
check('ID2ب. المعطيات الممرَّرة صحيحة (old=yes, new=no)', [$GLOBALS['__test_replacement_calls'][0]['old_reply'], $GLOBALS['__test_replacement_calls'][0]['new_reply']], ['yes', 'no']);

// نفس السيناريو عبر UltraMsg — يثبت تكافؤ المزوّدين بعد إصلاح البند BUG1
// (كان UltraMsg::record_rsvp() يفتقر لاستدعاء pge_maybe_grant_replacement_
// entitlement() بالكامل، بخلاف Cartat؛ الإصلاح مُطبَّق الآن في
// includes/class-ultramsg-handler.php — راجع docs/WHATSAPP-RSVP-PARSING-VERIFICATION.md)
PGE_Invitation_Service::create(8001, '0593330002', 'idempotent-um', '', 401);
$record_um->invoke($um, 8001, '0593330002', 'yes');
$GLOBALS['__test_replacement_calls'] = [];
$record_um->invoke($um, 8001, '0593330002', 'no');
check('BUG1-FIXED. UltraMsg: انتقال yes→no يستدعي pge_maybe_grant_replacement_entitlement() الآن — تكافؤ تام مع Cartat بعد الإصلاح', count($GLOBALS['__test_replacement_calls']), 1);
check('BUG1-FIXEDب. المعطيات الممرَّرة صحيحة (old=yes, new=no) — نفس عقد Cartat تماماً', [$GLOBALS['__test_replacement_calls'][0]['old_reply'], $GLOBALS['__test_replacement_calls'][0]['new_reply']], ['yes', 'no']);

// ج. no→no — نقطة الاستدعاء في record_rsvp() تستدعي الدالة المركزية دوماً
// عندما reply==='no' (بصرف النظر عن old_reply)؛ فلترة الأهلية (pending/yes/
// null→no مؤهَّل، no→no غير مؤهَّل) منطقها الداخلي حصراً — مُختبَر بالكامل في
// test-replacement-entitlement-grant.php (خارج نطاق هذه المهمة). هنا نتحقق
// فقط أن نقطة الاستدعاء تُمرِّر old_reply='no' الصحيح في هذه الحالة تحديداً
// (أمانة الاستدعاء، لا إعادة تنفيذ منطق الأهلية هنا).
PGE_Invitation_Service::create(8001, '0593330003', 'no-to-no', '', 401);
$record_cartat->invoke($cartat, 8001, '0593330003', 'no');
$GLOBALS['__test_replacement_calls'] = [];
$record_cartat->invoke($cartat, 8001, '0593330003', 'no');
check('ID3. Cartat: نقطة الاستدعاء عند no→no تستدعي الدالة مرة واحدة (الفلترة داخلية، خارج النطاق)', count($GLOBALS['__test_replacement_calls']), 1);
check('ID3ب. المعطيات الممرَّرة صحيحة (old=no, new=no) — أمانة نقل القيم لنقطة القرار الداخلية', [$GLOBALS['__test_replacement_calls'][0]['old_reply'], $GLOBALS['__test_replacement_calls'][0]['new_reply']], ['no', 'no']);

// د. رد بعد إلغاء الدعوة إدارياً (cancelled) — الكتابة عبر واتساب تنجح صامتة،
// لكن الحل عبر resolve_by_phone (نقطة الدخول الفعلية لأي استخدام لاحق) يُحظَر
PGE_Invitation_Service::create(8001, '0593330004', 'قبل الإلغاء', '', 401);
PGE_Invitation_Service::cancel(8001, '0593330004', 'طلب المضيف', 401);
seed_pending('593330004', ['event_id' => 8001, 'wa_number' => '593330004', 'original_phone' => '0593330004']);
$resp_cancel = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('593330004@c.us', '1')));
check('ID4. رد واتساب بعد الإلغاء الإداري: الكتابة الخام تنجح (status=success) — لا حارس إلغاء عند مستوى كتابة RSVP نفسه', $resp_cancel->get_data()['status'], 'success');
$resolve_cancel = PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0593330004');
check('ID4ب. لكن resolve_by_phone (نقطة الدخول الفعلية) لا تزال تُبلِّغ cancelled — الحدود الأمنية سليمة رغم "إحياء" صف RSVP', $resolve_cancel['result'], 'cancelled');

// هـ. رد بعد Hard Delete ثم إعادة الإنشاء (Write Path Unification يحمي من إحياء بيانات قديمة)
PGE_Invitation_Service::create(8001, '0593330005', 'قبل الحذف', '', 401);
$record_cartat->invoke($cartat, 8001, '0593330005', 'yes');
$old_rsvp_id_5 = (int) PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0593330005')['guest']['rsvp_id'];
$wpdb->update($wpdb->prefix . 'pge_event_rsvps', ['checked_in' => 1, 'checked_in_at' => '2026-08-01 10:00:00', 'checkin_method' => 'qr', 'actual_entered_count' => 2], ['id' => $old_rsvp_id_5]);
PGE_Invitation_Service::delete(8001, '0593330005', 401);
PGE_Invitation_Service::create(8001, '0593330005', 'بعد الحذف', '', 401);
seed_pending('593330005', ['event_id' => 8001, 'wa_number' => '593330005', 'original_phone' => '0593330005']);
$resp_after_delete = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('593330005@c.us', '1')));
$resolve_after_delete = PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0593330005');
check('ID5. رد واتساب بعد Hard Delete ثم إعادة الإنشاء: ينجح كدعوة جديدة نظيفة', $resp_after_delete->get_data()['status'], 'success');
check('ID5ب. لا يرث checked_in من الصف اليتيم القديم', $resolve_after_delete['guest']['checked_in'], false);
check('ID5ج. لا يرث checkin_method من الصف اليتيم القديم', $resolve_after_delete['guest']['checkin_method'], null);

// و. RSVP Write Path Unification عبر القنوات: Web ثم Cartat ثم UltraMsg على
// نفس الهاتف بعد حذف/إعادة إنشاء متكررة — يجب أن يتقارب الجميع لنفس القرار
PGE_Invitation_Service::create(8001, '0593330006', 'قناة1-ويب', '', 401);
pge_save_rsvp_response(8001, '0593330006', 'yes', 0, '', false);
$id_6a = (int) PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0593330006')['guest']['rsvp_id'];
PGE_Invitation_Service::delete(8001, '0593330006', 401);
PGE_Invitation_Service::create(8001, '0593330006', 'قناة2-كارتات', '', 401);
$id_6b = $record_cartat->invoke($cartat, 8001, '0593330006', 'yes');
PGE_Invitation_Service::delete(8001, '0593330006', 401);
PGE_Invitation_Service::create(8001, '0593330006', 'قناة3-ألترامسج', '', 401);
$id_6c = $record_um->invoke($um, 8001, '0593330006', 'yes');
check('ID6. نفس id الفعلي (تصفير في المكان) عبر الثلاث قنوات المتتالية — قرار موحَّد واحد', [$id_6a, $id_6b, $id_6c], [$id_6a, $id_6a, $id_6a]);

// ============================================================================
// 10. QR interaction — تأكيد أن رد واتساب لا يكسر أبداً QR/الحضور/الحل
// ============================================================================
PGE_Invitation_Service::create(8001, '0594440001', 'qr-flow', '', 401);
seed_pending('594440001', ['event_id' => 8001, 'wa_number' => '594440001', 'original_phone' => '0594440001', 'invite_code' => 'QRQR-0001']);
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('594440001@c.us', '1')));
$rsvp_id_qr = (int) PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0594440001')['guest']['rsvp_id'];
$qr_payload_v1 = PGE_Guest_Resolution_Service::build_scanner_qr_payload(8001, $rsvp_id_qr, '0594440001');
check_true('QR1. حمولة QR كنسية صالحة تُبنى فعلياً بعد رد واتساب (yes)', $qr_payload_v1 !== '' && substr_count($qr_payload_v1, '|') === 3);
$qr_resolve_1 = PGE_Guest_Resolution_Service::resolve_from_qr(8001, $qr_payload_v1);
check('QR2. الـQR الناتج بعد رد واتساب يُحل فوراً (found) — Guest Resolution لا ينكسر', $qr_resolve_1['result'], 'found');

// تدوير QR ثم حذف ثم إعادة إنشاء ثم رد واتساب جديد — الـQR القديم يُرفَض،
// والجديد فقط يُحل؛ الحضور (Check-in) يبقى محكوماً بآخر نسخة سارية فقط
PGE_Invitation_Repository::regenerate_qr(8001, '0594440001');
PGE_Invitation_Service::delete(8001, '0594440001', 401);
PGE_Invitation_Service::create(8001, '0594440001', 'qr-flow-بعد-التدوير-والحذف', '', 401);
seed_pending('594440001', ['event_id' => 8001, 'wa_number' => '594440001', 'original_phone' => '0594440001']);
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('594440001@c.us', '1')));
$qr_resolve_old = PGE_Guest_Resolution_Service::resolve_from_qr(8001, $qr_payload_v1);
check('QR3. الـQR القديم (من قبل التدوير+الحذف) يُرفَض بعد رد واتساب جديد — لا إحياء لبطاقة دخول قديمة', $qr_resolve_old['result'], 'invalid');
$rsvp_id_qr_new = (int) PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0594440001')['guest']['rsvp_id'];
$qr_payload_v2 = PGE_Guest_Resolution_Service::build_scanner_qr_payload(8001, $rsvp_id_qr_new, '0594440001');
check('QR4. الـQR الجديد (بعد رد واتساب اللاحق) يُحل بنجاح — Attendance/Check-in سليمان', PGE_Guest_Resolution_Service::resolve_from_qr(8001, $qr_payload_v2)['result'], 'found');

// ============================================================================
// 11. Audit — لا أحداث تدقيق زائفة/مكررة/مفقودة نتيجة رد واتساب
// ============================================================================
PGE_Invitation_Service::create(8001, '0595550001', 'audit-check', '', 401);
$audit_before = PGE_Invitation_Management_Audit::list_for_invitation(8001, '0595550001');
check('AU1. بعد create(): يوجد بالضبط سجل تدقيق واحد (created)', count($audit_before), 1);
check('AU1ب. الحدث حقيقي وصحيح (created)', $audit_before[0]['action'], 'created');
seed_pending('595550001', ['event_id' => 8001, 'wa_number' => '595550001', 'original_phone' => '0595550001']);
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('595550001@c.us', '1')));
$um_dup_seed = ['event_id' => 8001, 'wa_number' => '595550001', 'original_phone' => '0595550001'];
seed_pending('595550001', $um_dup_seed);
$um->handle_incoming_message(new Fake_Wa_Rest_Request(um_payload('595550001@c.us', '2')));
$audit_after = PGE_Invitation_Management_Audit::list_for_invitation(8001, '0595550001');
check('AU2. بعد ردَّين واتساب (yes ثم no عبر مزوّد آخر): لا سجل تدقيق إضافي أُنشئ — نفس السجل الواحد فقط (created)', count($audit_after), 1);
check_true('AU3. لا نوع حدث تدقيق باسم rsvp/reply موجود في enum الإجراءات المفعَّلة أصلاً (بحث بنيوي)', strpos(file_get_contents(__DIR__ . '/../includes/class-pge-invitation-management-audit.php'), "'rsvp") === false);

// ============================================================================
// 12. Security
// ============================================================================
// لا تسريب لتوكن الاعتماد (Authorization) في أي استدعاء log/error_log — تحقق
// بنيوي: الدالة الوحيدة التي تبني ترويسة Authorization لا تُمرِّرها لأي log()
$transport_src = file_get_contents(__DIR__ . '/../includes/class-pge-cartat-transport.php');
check_true('SEC1. لا استدعاء لأي log()/error_log() يُمرِّر $this->api_token أو الترويسة كاملة', !preg_match('/error_log\([^)]*api_token/', $transport_src) && !preg_match('/error_log\([^)]*headers/', $transport_src));
// لا تعداد أرقام (Phone Enumeration): رقم غير مسجَّل يُعيد استجابة عامة
// مطابقة تماماً بلا أي تفاصيل إضافية تكشف عن وجود/عدم وجود الرقم بالنظام
$enum_probe_1 = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('500000001@c.us', '1')))->get_data();
$enum_probe_2 = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('500000002@c.us', '1')))->get_data();
check('SEC2. رقمان مختلفان غير مسجَّلين → نفس الاستجابة العامة تماماً (no_pending)، لا تمييز', $enum_probe_1, $enum_probe_2);
check_true('SEC2ب. الاستجابة العامة لا تحوي أي حقل يكشف تفاصيل داخلية (فقط status)', array_keys($enum_probe_1) === ['status']);
// لا قبول لحمولة مشوَّهة (Malformed payload) بأي شكل يُسقط استثناءً فادحاً
$malformed_payloads = ['', '{', '[1,2,3]', 'null', '{"event":123}', str_repeat('x', 5000)];
$malformed_ok = true;
foreach ($malformed_payloads as $mp) {
    try {
        $r = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request($mp));
        if (!($r instanceof WP_REST_Response)) { $malformed_ok = false; break; }
    } catch (\Throwable $e) { $malformed_ok = false; break; }
}
check_true('SEC3. حمولات مشوَّهة متعددة (فارغة/JSON غير صالح/أنواع خاطئة/طويلة جداً) لا تُسقط أي استثناء فادح — دائماً WP_REST_Response سليمة', $malformed_ok);
// عدم وجود مصادقة على الـwebhook (توثيق حقيقي للفجوة — بحث بنيوي، ليس إصلاحاً)
check_true('SEC4. [موثَّق كخطر أمني قائم مسبقاً — خارج نطاق الإصلاح هنا] كلا الـwebhook مسجَّلان بـpermission_callback=__return_true (بلا تحقق توقيع/سر مشترك)',
    substr_count($src_cartat, "'permission_callback' => '__return_true'") >= 1
    && substr_count($src_um, "'permission_callback' => '__return_true'") >= 1);

// ============================================================================
// 13. RC1 Cartat ACK Compatibility Fix — تحقّق تنفيذي حقيقي عبر handle_incoming_message
// ============================================================================
// دليل إنتاجي: كارتات يُصدر ack=2/3 لا =1 فقط لمسار ربط LID. الشرط الجديد:
// ack>=1 (لا =1 فقط) + الوجهة LID فعلاً (raw_to يحوي @lid) + msg_id/pending
// صالحان — كلها via الاستدعاء الحقيقي لـhandle_incoming_message()، بلا Reflection.

// ACK1: ack=2 يبني الخريطة
seed_pending_msgid('MSGID-ACK2', ['event_id' => 8001, 'wa_number' => '596000001', 'norm_phone' => '596000001', 'original_phone' => '0596000001']);
$ack1 = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_ack_payload(2, 'MSGID-ACK2', '920000000001@lid')))->get_data();
check('ACK1. الاستجابة ack_ok', $ack1, ['status' => 'ack_ok']);
check_true('ACK1ب. ack=2 يبني خريطة pge_wa_pending_lid_920000000001', get_option('pge_wa_pending_lid_920000000001', null) !== null);
check('ACK1ج. الخريطة المبنية تشير للمناسبة الصحيحة', get_option('pge_wa_pending_lid_920000000001')['event_id'] ?? null, 8001);

// ACK2: ack=3 يبني/يُحدِّث الخريطة (مستوى تسليم أعلى، نفس المسار)
seed_pending_msgid('MSGID-ACK3', ['event_id' => 8001, 'wa_number' => '596000002', 'norm_phone' => '596000002', 'original_phone' => '0596000002']);
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_ack_payload(3, 'MSGID-ACK3', '920000000002@lid')));
check('ACK2. ack=3 أيضاً يبني الخريطة (لا يقتصر الشرط على ack=1 أو ack=2)', get_option('pge_wa_pending_lid_920000000002')['event_id'] ?? null, 8001);

// ACK3: تكرار ACK (2 ثم 3) لنفس id/to — استقرار (Idempotent)، لا خطأ ولا تكرار
seed_pending_msgid('MSGID-DUP', ['event_id' => 8001, 'wa_number' => '596000003', 'norm_phone' => '596000003', 'original_phone' => '0596000003']);
$dup1 = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_ack_payload(2, 'MSGID-DUP', '920000000003@lid')))->get_data();
$dup2 = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_ack_payload(3, 'MSGID-DUP', '920000000003@lid')))->get_data();
check('ACK3. كلا الاستدعاءين المتكرَّرين (ack=2 ثم ack=3) يُعيدان ack_ok بلا استثناء', [$dup1, $dup2], [['status' => 'ack_ok'], ['status' => 'ack_ok']]);
check('ACK3ب. الخريطة بعد التكرار لا تزال تشير لنفس المناسبة (استبدال آمن، لا تراكم)', get_option('pge_wa_pending_lid_920000000003')['event_id'] ?? null, 8001);

// ACK4: لا pending محفوظ لهذا msg_id → لا شيء يُبنى
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_ack_payload(2, 'MSGID-NEVER-SENT', '920000000004@lid')));
check_true('ACK4. ack بلا pending مطابق → لا خريطة LID تُبنى', get_option('pge_wa_pending_lid_920000000004', null) === null);

// ACK5: pending موجود لكن الوجهة ليست LID (رقم هاتف عادي) → لا شيء يُبنى
seed_pending_msgid('MSGID-NOLID', ['event_id' => 8001, 'wa_number' => '596000005', 'norm_phone' => '596000005', 'original_phone' => '0596000005']);
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_ack_payload(2, 'MSGID-NOLID', '966500000009')));
check_true('ACK5. الوجهة رقم هاتف عادي (بلا @lid) → لا خريطة pge_wa_pending_lid_ تُبنى', get_option('pge_wa_pending_lid_966500000009', null) === null);

// ACK6: مستوى ACK غير مؤهَّل (0 = لم يُرسَل بعد) → لا شيء يُبنى، رغم صحة الباقي
seed_pending_msgid('MSGID-ACK0', ['event_id' => 8001, 'wa_number' => '596000006', 'norm_phone' => '596000006', 'original_phone' => '0596000006']);
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_ack_payload(0, 'MSGID-ACK0', '920000000006@lid')));
check_true('ACK6. ack=0 (لم يُرسَل بعد) → لا خريطة LID تُبنى', get_option('pge_wa_pending_lid_920000000006', null) === null);
seed_pending_msgid('MSGID-ACKNEG', ['event_id' => 8001, 'wa_number' => '596000007', 'norm_phone' => '596000007', 'original_phone' => '0596000007']);
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_ack_payload(-1, 'MSGID-ACKNEG', '920000000007@lid')));
check_true('ACK6ب. ack سالب (فشل صريح) → لا خريطة LID تُبنى', get_option('pge_wa_pending_lid_920000000007', null) === null);

// ACK7: رد الضيف بعد ack=2 يصل فعلياً لـRSVP (المسار الكامل من الإرسال حتى التسجيل)
PGE_Invitation_Service::create(8001, '0596000008', 'ضيف-ack2', '', 401);
seed_pending_msgid('MSGID-E2E-ACK2', ['event_id' => 8001, 'wa_number' => '596000008', 'norm_phone' => '596000008', 'original_phone' => '0596000008']);
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_ack_payload(2, 'MSGID-E2E-ACK2', '920000000008@lid')));
$reply_after_ack2 = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('920000000008@lid', '1')))->get_data();
check('ACK7. رد الضيف عبر LID بعد ack=2 → success (يصل لـRSVP فعلياً، لا no_pending)', $reply_after_ack2, ['status' => 'success', 'reply' => 'yes']);
check('ACK7ب. الرد فعلياً مُسجَّل yes في RSVP الحقيقي', PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0596000008')['guest']['reply'], 'yes');

// ACK8: رد الضيف بعد ack=3 يصل فعلياً لـRSVP (نفس المسار بمستوى تسليم مختلف)
PGE_Invitation_Service::create(8001, '0596000009', 'ضيف-ack3', '', 401);
seed_pending_msgid('MSGID-E2E-ACK3', ['event_id' => 8001, 'wa_number' => '596000009', 'norm_phone' => '596000009', 'original_phone' => '0596000009']);
$cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_ack_payload(3, 'MSGID-E2E-ACK3', '920000000009@lid')));
$reply_after_ack3 = $cartat->handle_incoming_message(new Fake_Wa_Rest_Request(cartat_payload('920000000009@lid', '2')))->get_data();
check('ACK8. رد الضيف عبر LID بعد ack=3 → success (يصل لـRSVP فعلياً، لا no_pending)', $reply_after_ack3, ['status' => 'success', 'reply' => 'no']);
check('ACK8ب. الرد فعلياً مُسجَّل no في RSVP الحقيقي', PGE_Guest_Resolution_Service::resolve_by_phone(8001, '0596000009')['guest']['reply'], 'no');

echo "\n=== النتيجة النهائية (بعد إصلاح BUG1 + RC1 Cartat ACK Compatibility Fix): $passed / $total ===\n";
if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) echo " - $f\n";
}
exit(empty($failures) ? 0 : 1);
