<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـEntry Check-in Supervisors، Phase 7
 * ("Supervisor Check-in User Interface" RFC) — templates/supervisor-checkin.php
 * وincludes/checkin-ui-ajax.php، واستهلاك includes/checkin-ajax.php الحقيقية
 * غير المُعدَّلة (Phase 4). يستدعي الأنبوب الحقيقي الكامل (Catalog → Assignment
 * Service → Session → Authenticator → Portal Middleware → PGE_Checkin_QR_
 * Service/PGE_Guest_Resolution_Service/PGE_Checkin_Recorder → جدول التدقيق →
 * PGE_Attendance_Dashboard_Provider) بلا أي تعديل على أي من هذه الطبقات — هذه
 * المرحلة عرض فقط، ونقطة AJAX جديدة واحدة (بحث آمن فقط، بلا كتابة).
 *
 * نفس فلسفة "قصّ وتنفيذ" القالب الحقيقي (run_template_slice() في
 * test-event-quota-ui-integration.php، وrender_supervisor_dashboard_slice()
 * في test-supervisor-dashboard-ui.php) — استبدال `exit;` الحرفية بـ
 * `return get_defined_vars();` لتشغيل القالب الحقيقي داخل حصاد اختبار واحد
 * متعدد السيناريوهات، بلا أي تعديل يدوي على محتواه.
 *
 * السيناريوهات المطلوبة صراحةً (Testing Requirement): مسار QR، مسار البحث
 * اليدوي، اختيار من مرشَّحين متعددين، مسار المرجع المُوقَّع، مسار التأكيد،
 * تسجيل حضور مكرَّر، أخطاء التحقق، فشل التفويض، تحديث لوحة الحضور تلقائياً بعد
 * النجاح، وانحدار على المراحل 1-6.
 *
 * ملاحظة صريحة حول حدود هذا الحصاد (نفس القيد المذكور في test-supervisor-
 * dashboard-ui.php): تفاعلات الكاميرا الفعلية (getUserMedia/BarcodeDetector)
 * غير قابلة للتنفيذ داخل حصاد PHP بحت بلا متصفح حقيقي. تُختبَر هنا بطريقتين
 * صادقتين بدلاً من محاكاتها: (أ) نقطة نهاية "المسح" الخادمية التي يعتمد عليها
 * مسار QR (pge_supervisor_checkin_scan) تُختبَر تنفيذياً بالكامل (نجاح/رفض
 * حقيقيان) بحمولة QR حقيقية مبنية عبر PGE_Checkin_QR_Service::build_payload()
 * نفسها، و(ب) وجود بنية JS الصحيحة (BarcodeDetector، إيقاف/استئناف، تبديل
 * كاميرا، حارس التكرار، التراجع اليدوي) يُتحقَّق منه كتحقّق بنيوي على نص الـJS
 * المُصدَّر فعلياً ضمن HTML الناتج — لا يُدَّعى تنفيذ JS فعلياً؛ هذا القيد مذكور
 * صراحة في التقرير النهائي.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-supervisor-checkin-ui.php
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
$GLOBALS['__test_now_override'] = null;
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) {
        return $GLOBALS['__test_now_override'] ?? '2026-01-01 00:00:00';
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_hash')) {
    function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed-for-tests'); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false) {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags($text);
        if ($remove_breaks) {
            $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
        }
        return trim($text);
    }
}
if (!function_exists('wp_generate_uuid4')) {
    $GLOBALS['__test_uuid_counter'] = 0;
    function wp_generate_uuid4() {
        $GLOBALS['__test_uuid_counter']++;
        return 'test-uuid-' . $GLOBALS['__test_uuid_counter'];
    }
}
if (!function_exists('wp_rand')) {
    function wp_rand($min = 0, $max = 100) { return mt_rand($min, $max); }
}

// ── تفويض (current_user_can/get_current_user_id/get_post_field) — نفس أسلوب
// test-supervisor-dashboard-ui.php حرفياً. ──────────────────────────────────
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function current_user_can($cap) {
    if ($cap === 'administrator') {
        return $GLOBALS['__test_user_is_admin'];
    }
    return false;
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

// ── Stubs عرض/HTML/AJAX (نفس test-supervisor-dashboard-ui.php حرفياً) ───────

if (!class_exists('Test_Wp_Die_Exception')) {
    /** إشارة اختبارية خفيفة تحلّ محلّ exit/die الحقيقي لـwp_die() فقط. */
    class Test_Wp_Die_Exception extends \Exception {}
}

function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); }
function language_attributes() { echo 'lang="ar"'; }
function bloginfo($key) { echo $key === 'charset' ? 'UTF-8' : ''; }
function wp_head() { /* no-op */ }
function wp_footer() { /* no-op */ }
$GLOBALS['__test_last_status_header'] = null;
function status_header($code) { $GLOBALS['__test_last_status_header'] = (int) $code; }
function nocache_headers() { /* no-op */ }
function wp_die($message = '', $title = '', $args = []) {
    throw new Test_Wp_Die_Exception((string) $message);
}
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_nonce_url($url, $action) { return $url . (strpos($url, '?') === false ? '?' : '&') . '_wpnonce=test-nonce-' . sanitize_key($action); }
function wp_json_encode($data) { return json_encode($data); }

$GLOBALS['__test_nonce_valid_actions'] = [];
function wp_create_nonce($action) {
    $GLOBALS['__test_nonce_valid_actions'][$action] = true;
    return 'test-nonce-' . sanitize_key($action);
}
function wp_verify_nonce($nonce, $action) {
    $expected = 'test-nonce-' . sanitize_key($action);
    return hash_equals($expected, (string) $nonce) ? 1 : false;
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    if ($status_code !== null) {
        status_header($status_code);
    }
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}

/**
 * استدعاء أي معالج AJAX حقيقي (POST من $_POST الحالي) بأمان داخل حصاد اختبار
 * واحد متعدد السيناريوهات — يلتقط استجابة wp_send_json_* كنص JSON خام مُفكَّك.
 */
function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // متوقَّع دائماً — هذا هو أسلوب إنهاء wp_send_json_* الطبيعي.
    }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) {
        return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

/**
 * قصّ وتنفيذ شريحة PHP حقيقية من templates/supervisor-checkin.php الحقيقي
 * (بلا أي تعديل يدوي على محتواه) — نفس فلسفة render_supervisor_dashboard_
 * slice() في test-supervisor-dashboard-ui.php حرفياً.
 *
 * @return array{html:string,vars:array}
 */
$GLOBALS['__test_slice_call_counter'] = 0;
function render_supervisor_checkin_slice($template_file): array
{
    $content = file_get_contents($template_file);
    if ($content === false) {
        throw new RuntimeException("تعذّرت قراءة الملف: $template_file");
    }
    $body = preg_replace('/^<\?php/', '', $content, 1);
    $body = str_replace('exit;', 'return get_defined_vars();', $body);

    $GLOBALS['__test_slice_call_counter']++;
    $fn_name = '__test_render_supervisor_checkin_' . $GLOBALS['__test_slice_call_counter'];

    $wrapped = 'function ' . $fn_name . '() { ' . $body . " \nreturn get_defined_vars(); }";
    eval($wrapped);

    ob_start();
    $vars = [];
    try {
        $vars = call_user_func($fn_name);
    } catch (Test_Wp_Die_Exception $e) {
        // نفس ملاحظة test-supervisor-dashboard-ui.php — لا يحدث في اختباراتنا.
    }
    $html = ob_get_clean();

    return ['html' => $html, 'vars' => is_array($vars) ? $vars : []];
}

// ── User Meta + Posts + Post Meta + Userdata وهمية في الذاكرة ───────────────

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_users_by_id'] = [];
$GLOBALS['__test_posts'] = [];
$GLOBALS['__test_post_meta'] = [];
$GLOBALS['__test_userdata'] = [];

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
    return true;
}
function delete_user_meta($user_id, $key)
{
    unset($GLOBALS['__test_user_meta'][$user_id][$key]);
    return true;
}
function metadata_exists($type, $object_id, $meta_key)
{
    $value = $GLOBALS['__test_user_meta'][$object_id][$meta_key] ?? '';
    return $value !== '';
}
function set_test_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
}
function reset_test_user($user_id)
{
    $GLOBALS['__test_user_meta'][$user_id] = [];
    $GLOBALS['__test_users_by_id'][$user_id] = true;
}
function get_user_by($field, $value)
{
    if ($field === 'id') {
        return !empty($GLOBALS['__test_users_by_id'][$value]) ? (object) ['ID' => (int) $value] : false;
    }
    return false;
}
function set_test_event_full($event_id, $author_id, $title = '', $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) [
        'ID' => $event_id,
        'post_type' => $post_type,
        'post_author' => $author_id,
        'post_title' => $title,
    ];
    if (!isset($GLOBALS['__test_post_meta'][$event_id])) {
        $GLOBALS['__test_post_meta'][$event_id] = [];
    }
}
function get_post($event_id)
{
    return $GLOBALS['__test_posts'][$event_id] ?? null;
}
function get_post_type($event_id)
{
    $post = $GLOBALS['__test_posts'][$event_id] ?? null;
    return $post ? (string) $post->post_type : false;
}
function get_post_field($field, $post_id)
{
    $post = $GLOBALS['__test_posts'][$post_id] ?? null;
    if ($post === null) {
        return '';
    }
    return $post->$field ?? '';
}
function get_the_title($post_id)
{
    $post = $GLOBALS['__test_posts'][$post_id] ?? null;
    return $post ? (string) $post->post_title : '';
}
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
function set_test_userdata($user_id, $display_name)
{
    $GLOBALS['__test_userdata'][$user_id] = (object) ['display_name' => $display_name];
}
function get_userdata($user_id)
{
    return $GLOBALS['__test_userdata'][$user_id] ?? false;
}
function seed_invited_guest($event_id, $phone, $name, $code)
{
    $map = $GLOBALS['__test_post_meta'][$event_id]['_pge_invited_guests'] ?? [];
    $map[$phone] = ['phone' => $phone, 'name' => $name, 'note' => '', 'code' => $code];
    $GLOBALS['__test_post_meta'][$event_id]['_pge_invited_guests'] = $map;
}

// ── Fake $wpdb — دمج بين عائلة test-supervisor-dashboard-ui.php (استعلامات
// Attendance Statistics المُجمَّعة اللازمة لملخّص الجلسة SSR في القالب الجديد)
// وعائلة test-checkin-engine.php (استعلامات محرك تسجيل الحضور الحقيقية:
// بحث بالهاتف/القائمة، QR، والتحديث الذري) — كلا الأنبوبين مُستهلَكان معاً في
// هذا القالب الجديد (Provider للملخّص + Checkin Engine للتسجيل الفعلي). ─────

class Fake_Wpdb_Checkin_Ui
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public function get_charset_collate() { return ''; }

    public $plans = [];
    public $tiers = [];
    public $tier_features = [];
    public $supervisors = [];
    public $sessions = [];
    public $rsvps = [];
    public $audit_log = [];

    private $plans_next_id = 1;
    private $tiers_next_id = 1;
    private $tier_features_next_id = 1;
    private $supervisors_next_id = 1;
    private $sessions_next_id = 1;
    private $rsvps_next_id = 1;
    private $audit_log_next_id = 1;

    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];
    public $force_lock_unavailable = false;

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
        if (strpos($sql_or_table, $this->prefix . 'pge_checkin_audit_log') !== false) {
            return 'audit_log';
        }
        if (strpos($sql_or_table, $this->prefix . 'pge_event_rsvps') !== false) {
            return 'rsvps';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_supervisor_sessions') !== false) {
            return 'sessions';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_tier_features') !== false) {
            return 'tier_features';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plan_tiers') !== false) {
            return 'tiers';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plans') !== false) {
            return 'plans';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_event_supervisors') !== false) {
            return 'supervisors';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $which = $this->which_table($sql);

        // Phase 5: استعلام Attendance Summary المُجمَّع الواحد (للملخّص SSR).
        if ($which === 'rsvps' && stripos($sql, 'total_invitations') !== false) {
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $m);
            $event_id = isset($m[1]) ? (int) $m[1] : 0;
            $total = 0;
            $checked_in = 0;
            $expected = 0;
            $actual = 0;
            foreach ($this->rsvps as $row) {
                if ((int) $row['event_id'] !== $event_id) {
                    continue;
                }
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

        if ($which === 'rsvps') {
            // بحث بهاتف كامل (Blocker Fix #2 — find_rsvp_row_by_phone()،
            // مصدر واحد لكل نتيجة).
            if (preg_match("/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+guest_phone\s*=\s*'([^']*)'/i", $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                foreach ($this->rsvps as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['guest_phone'] === $phone) {
                        return $row;
                    }
                }
                return null;
            }
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)\s+AND\s+event_id\s*=\s*(\d+)/i', $sql, $m)) {
                $id = (int) $m[1];
                $event_id = (int) $m[2];
                $row = $this->rsvps[$id] ?? null;
                if ($row !== null && (int) $row['event_id'] === $event_id) {
                    return $row;
                }
                return null;
            }
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
                $id = (int) $m[1];
                return $this->rsvps[$id] ?? null;
            }
            return null;
        }

        if ($which === 'sessions') {
            if (preg_match("/WHERE\s+session_token_hash\s*=\s*'([^']*)'/i", $sql, $m)) {
                $hash = $m[1];
                foreach ($this->sessions as $row) {
                    if (($row['session_token_hash'] ?? null) === $hash) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        if ($which === 'supervisors') {
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $m)) {
                $id = (int) $m[1];
                return $this->supervisors[$id] ?? null;
            }
            if (preg_match("/WHERE\s+invitation_token_hash\s*=\s*'([^']*)'/i", $sql, $m)) {
                $hash = $m[1];
                foreach ($this->supervisors as $row) {
                    if (($row['invitation_token_hash'] ?? null) === $hash) {
                        return $row;
                    }
                }
                return null;
            }
            if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+supervisor_phone\s*=\s*\'([^\']*)\'\s+AND\s+status\s+IN\s*\(([^)]*)\)/i', $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                $statuses = array_map(function ($v) { return trim($v, " '"); }, explode(',', $m[3]));
                foreach ($this->supervisors as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['supervisor_phone'] === $phone && in_array($row['status'], $statuses, true)) {
                        return $row;
                    }
                }
                return null;
            }
            if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+supervisor_phone\s*=\s*\'([^\']*)\'\s+AND\s+status\s*=\s*\'([^\']*)\'/i', $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                $status = $m[3];
                foreach ($this->supervisors as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['supervisor_phone'] === $phone && $row['status'] === $status) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        $rows = $this->get_results($sql, $output);
        if ($rows === null) {
            return null;
        }
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);

        // Phase 5: آخر عمليات تسجيل حضور (audit_log، محدود، مُرتَّب) — للملخّص.
        if ($which === 'audit_log' && stripos($sql, 'ORDER BY created_at DESC') !== false) {
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $me);
            preg_match('/LIMIT\s+(\d+)/i', $sql, $ml);
            $event_id = isset($me[1]) ? (int) $me[1] : 0;
            $limit = isset($ml[1]) ? (int) $ml[1] : 10;

            $matched = array_values(array_filter($this->audit_log, function ($r) use ($event_id) {
                return (int) $r['event_id'] === $event_id;
            }));
            usort($matched, function ($a, $b) {
                $cmp = strcmp((string) $b['created_at'], (string) $a['created_at']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return $b['id'] <=> $a['id'];
            });
            return array_slice($matched, 0, $limit);
        }

        // Phase 5: تجميع Supervisor Summary (GROUP BY assignment_id).
        if ($which === 'audit_log' && stripos($sql, 'GROUP BY assignment_id') !== false) {
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $m);
            $event_id = isset($m[1]) ? (int) $m[1] : 0;
            $grouped = [];
            foreach ($this->audit_log as $row) {
                if ((int) $row['event_id'] !== $event_id) {
                    continue;
                }
                $aid = (int) $row['assignment_id'];
                if (!isset($grouped[$aid])) {
                    $grouped[$aid] = ['assignment_id' => $aid, 'checkins_recorded' => 0, 'guests_recorded' => 0];
                }
                $grouped[$aid]['checkins_recorded']++;
                $grouped[$aid]['guests_recorded'] += (int) $row['actual_count'];
            }
            return array_values($grouped);
        }

        // Phase 5: دفعة RSVP بمعرّفات (WHERE id IN (...)).
        if ($which === 'rsvps' && preg_match('/\bIN\s*\(([^)]*)\)/i', $sql, $m)) {
            $ids = array_map('intval', array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
            $matched = [];
            foreach ($ids as $id) {
                if (isset($this->rsvps[$id])) {
                    $matched[] = $this->rsvps[$id];
                }
            }
            return $matched;
        }

        // Blocker Fix #2: get_results() على rsvps بلا LIMIT — لازم لـ
        // find_rsvp_rows_by_phone() (0/1/أكثر من نتيجة، أساس "ambiguous").
        if ($which === 'rsvps') {
            if (preg_match("/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+guest_phone\s*=\s*'([^']*)'/i", $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                $matched = [];
                foreach ($this->rsvps as $row) {
                    if ((int) $row['event_id'] === $event_id && $row['guest_phone'] === $phone) {
                        $matched[] = $row;
                    }
                }
                usort($matched, function ($a, $b) { return $a['id'] <=> $b['id']; });
                return $matched;
            }
            return [];
        }

        // Phase 5: PGE_Supervisor_Assignment_Service::list_assignments_for_event().
        if ($which === 'supervisors' && stripos($sql, 'ORDER BY id ASC') !== false) {
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $m);
            $event_id = isset($m[1]) ? (int) $m[1] : 0;
            $matched = array_values(array_filter($this->supervisors, function ($r) use ($event_id) {
                return (int) $r['event_id'] === $event_id;
            }));
            usort($matched, function ($a, $b) { return $a['id'] <=> $b['id']; });
            return $matched;
        }

        if ($which === null || in_array($which, ['supervisors', 'sessions', 'audit_log'], true)) {
            return [];
        }

        $rows = array_values(
            $which === 'tiers' ? $this->tiers : ($which === 'plans' ? $this->plans : $this->tier_features)
        );

        if (preg_match('/WHERE\s+(.+)$/is', $sql, $m)) {
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

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $name = $m[1];
            $this->lock_acquire_log[] = $name;
            if ($this->force_lock_unavailable) {
                return 0;
            }
            $this->held_locks[$name] = true;
            return 1;
        }

        $table = $this->prefix . 'mon_event_supervisors';
        $pattern = '/FROM\s+' . preg_quote($table, '/') . '\s+WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+status\s+NOT\s+IN\s*\(([^)]*)\)/i';
        if (preg_match($pattern, $sql, $m)) {
            $event_id = (int) $m[1];
            $excluded = array_map(function ($v) { return trim($v, " '"); }, explode(',', $m[2]));
            $count = 0;
            foreach ($this->supervisors as $row) {
                if ((int) $row['event_id'] === $event_id && !in_array((string) $row['status'], $excluded, true)) {
                    $count++;
                }
            }
            return (string) $count;
        }

        return null;
    }

    public function query($sql)
    {
        if (preg_match("/SELECT\s+RELEASE_LOCK\('([^']*)'\)/i", $sql, $m)) {
            $name = $m[1];
            $this->lock_release_log[] = $name;
            unset($this->held_locks[$name]);
            return 1;
        }
        return false;
    }

    public function insert($table, $data, $format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }
        if ($which === 'tiers') {
            $id = $this->tiers_next_id++;
            $this->tiers[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'plans') {
            $id = $this->plans_next_id++;
            $this->plans[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'supervisors') {
            $hash = $data['invitation_token_hash'] ?? null;
            if ($hash !== null) {
                foreach ($this->supervisors as $row) {
                    if (($row['invitation_token_hash'] ?? null) === $hash) {
                        return false;
                    }
                }
            }
            $id = $this->supervisors_next_id++;
            $this->supervisors[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'sessions') {
            $hash = $data['session_token_hash'] ?? null;
            foreach ($this->sessions as $row) {
                if (($row['session_token_hash'] ?? null) === $hash) {
                    return false;
                }
            }
            $id = $this->sessions_next_id++;
            $this->sessions[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'rsvps') {
            $id = $this->rsvps_next_id++;
            $defaults = ['checked_in' => 0, 'checked_in_at' => null, 'checked_in_by_assignment_id' => null, 'checkin_method' => null, 'actual_entered_count' => null, 'companions' => 0, 'reply' => 'pending'];
            $this->rsvps[$id] = array_merge(['id' => $id], $defaults, $data);
        } elseif ($which === 'audit_log') {
            $id = $this->audit_log_next_id++;
            $this->audit_log[$id] = array_merge(['id' => $id], $data);
        } else {
            $id = $this->tier_features_next_id++;
            $this->tier_features[$id] = array_merge(['id' => $id], $data);
        }
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }

        if ($which === 'supervisors' || $which === 'sessions' || $which === 'rsvps') {
            $store = $which;
            $id = $where['id'] ?? null;
            if ($id === null || !isset($this->{$store}[$id])) {
                return 0;
            }
            foreach ($where as $where_key => $where_value) {
                $current_value = $this->{$store}[$id][$where_key] ?? null;
                if ((string) $current_value !== (string) $where_value) {
                    return 0;
                }
            }
            foreach ($data as $k => $v) {
                $this->{$store}[$id][$k] = $v;
            }
            return 1;
        }

        $id = $where['id'] ?? null;
        if ($id === null) {
            return false;
        }
        $store = $which === 'tiers' ? 'tiers' : ($which === 'plans' ? 'plans' : 'tier_features');
        if (!isset($this->{$store}[$id])) {
            return 0;
        }
        foreach ($data as $k => $v) {
            $this->{$store}[$id][$k] = $v;
        }
        return 1;
    }

    public function seed_plan($id, array $row)
    {
        $this->plans[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->plans_next_id) {
            $this->plans_next_id = $id + 1;
        }
    }

    /** إدراج مباشر لصف RSVP اختباري (تمهيد قراءة فقط — لا يختبر مسار الكتابة). */
    public function seed_rsvp($event_id, $phone, array $extra = [])
    {
        $id = $this->rsvps_next_id++;
        $defaults = [
            'event_id' => $event_id,
            'guest_phone' => $phone,
            'guest_name' => null,
            'companions' => 0,
            'note' => null,
            'reply' => 'pending',
            'checked_in' => 0,
            'checked_in_at' => null,
            'checked_in_by_assignment_id' => null,
            'checkin_method' => null,
            'actual_entered_count' => null,
        ];
        $this->rsvps[$id] = array_merge(['id' => $id], $defaults, $extra);
        return $id;
    }

    /** إدراج مباشر لسطر تدقيق اختباري (تمهيد قراءة فقط). */
    public function seed_audit_row($event_id, $rsvp_id, $assignment_id, $method, $expected_count, $actual_count, $created_at)
    {
        $id = $this->audit_log_next_id++;
        $this->audit_log[$id] = [
            'id' => $id,
            'event_id' => $event_id,
            'rsvp_id' => $rsvp_id,
            'assignment_id' => $assignment_id,
            'method' => $method,
            'expected_count' => $expected_count,
            'actual_count' => $actual_count,
            'entry_type' => 'confirmation',
            'created_at' => $created_at,
        ];
        return $id;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Checkin_Ui();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-catalog.php';
require_once __DIR__ . '/../includes/class-pge-feature-registry.php';
require_once __DIR__ . '/../includes/class-pge-tier-features.php';
require_once __DIR__ . '/../includes/feature-resolver.php';
require_once __DIR__ . '/../includes/class-mon-events-users.php';
require_once __DIR__ . '/../includes/supervisor-quota-resolver.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-assignment-service.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-session.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-authenticator.php';
require_once __DIR__ . '/../includes/class-pge-supervisor-portal-middleware.php';
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php';
require_once __DIR__ . '/../includes/class-pge-guest-resolution-service.php';
require_once __DIR__ . '/../includes/class-pge-checkin-recorder.php';
require_once __DIR__ . '/../includes/checkin-ajax.php';
require_once __DIR__ . '/../includes/checkin-ui-ajax.php';
require_once __DIR__ . '/../includes/class-pge-attendance-statistics-service.php';
require_once __DIR__ . '/../includes/class-pge-attendance-dashboard-provider.php';
require_once __DIR__ . '/../includes/dashboard-ajax.php';

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
$wpdb->seed_plan(1, [
    'plan_key' => 'basic_plan',
    'name' => 'باقة أساسية',
    'plan_type' => 'personal',
    'status' => 'active',
]);

function make_test_tier($tier_key, $sort_order, array $extra = [])
{
    return PGE_Catalog::create_tier(array_merge([
        'plan_id' => 1,
        'tier_key' => $tier_key,
        'name' => 'مستوى اختبار ' . $tier_key,
        'price' => 100,
        'currency' => 'SAR',
        'salla_product_id' => null,
        'status' => 'active',
        'sort_order' => $sort_order,
    ], $extra));
}

function setup_catalog_owner_with_event($user_id, $event_id, $supervisor_limit, $tier_key)
{
    static $sort = 100;
    reset_test_user($user_id);
    $tier = make_test_tier($tier_key, $sort++);
    PGE_Tier_Features::set_tier_feature_value($tier['id'], 'admin_supervisor_limit', (string) $supervisor_limit);
    Mon_Events_Users::activate_catalog_tier($user_id, 1, $tier['id'], 'CHECKIN-UI-ORDER-' . $tier_key);
    return $tier;
}

function create_and_get_token($event_id, $inviter_id, $phone, $name = '')
{
    return PGE_Supervisor_Assignment_Service::create_supervisor_assignment($event_id, $inviter_id, $phone, $name);
}

function authenticate_supervisor_for_event($event_id, $host_id, $phone, $name = '')
{
    $invite = create_and_get_token($event_id, $host_id, $phone, $name);
    $auth = pge_supervisor_authenticate($invite['invitation_token']);
    $_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $auth['session_token'];
    return ['assignment_id' => $auth['assignment_id'], 'event_id' => $auth['event_id'], 'session_token' => $auth['session_token']];
}

/** تمهيد صف RSVP غير مسجَّل الحضور (قراءة فقط — لا يختبر مسار الكتابة). */
function seed_pending_rsvp($event_id, $phone, $companions = 0, $reply = 'yes')
{
    global $wpdb;
    return $wpdb->seed_rsvp($event_id, $phone, ['companions' => $companions, 'reply' => $reply]);
}

// ── مسار ملف القالب الحقيقي (نفس فلسفة PGE_TEST_UI_ROOT) ───────────────────
if (defined('PGE_TEST_UI_ROOT')) {
    $TEMPLATE_FILE = PGE_TEST_UI_ROOT . '/templates/supervisor-checkin.php';
} else {
    $TEMPLATE_FILE = __DIR__ . '/../templates/supervisor-checkin.php';
}
if (!file_exists($TEMPLATE_FILE)) {
    throw new RuntimeException('تعذّرت قراءة ملف القالب: ' . $TEMPLATE_FILE);
}

// ============================================================================
// السيناريو أ: فشل التفويض (Authorization Failure) — على مستوى الصفحة وكل
// نقاط AJAX الثلاث (scan/search/ui_search/confirm) بلا استثناء
// ============================================================================
echo "=== السيناريو أ: فشل التفويض ===\n";

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$GLOBALS['__test_current_user_id'] = 0;

$slice_a = render_supervisor_checkin_slice($TEMPLATE_FILE);
check('أ. لا جلسة مشرف: نتيجة التفويض denied', $slice_a['vars']['authorization']['result'] ?? null, 'denied');
check('أ. كود HTTP = 401 (لا كوكي جلسة إطلاقاً)', $slice_a['vars']['http_status'] ?? null, 401);
check_true('أ. HTML يحتوي رسالة "الدخول مطلوب"', strpos($slice_a['html'], 'الدخول مطلوب') !== false);
check_true('أ. لا نموذج تسجيل حضور مُسرَّب في صفحة الرفض (بلا data-role="qr-panel")', strpos($slice_a['html'], 'qr-panel') === false);

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'qr_payload' => 'x|1|y'];
$resp_scan_denied = call_ajax_handler('pge_supervisor_checkin_scan_handler');
check('أ. pge_supervisor_checkin_scan بلا جلسة: success=false', $resp_scan_denied['success'] ?? null, false);
check('أ. pge_supervisor_checkin_scan بلا جلسة: reason=no_session_cookie', $resp_scan_denied['data']['reason'] ?? null, 'no_session_cookie');

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'query' => '0501234567'];
$resp_search_denied = call_ajax_handler('pge_supervisor_checkin_ui_search_handler');
check('أ. pge_supervisor_checkin_ui_search بلا جلسة: success=false', $resp_search_denied['success'] ?? null, false);
check('أ. pge_supervisor_checkin_ui_search بلا جلسة: reason=no_session_cookie', $resp_search_denied['data']['reason'] ?? null, 'no_session_cookie');

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => 'x|1|y', 'actual_count' => 1];
$resp_confirm_denied = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('أ. pge_supervisor_checkin_confirm بلا جلسة: success=false', $resp_confirm_denied['success'] ?? null, false);
check('أ. pge_supervisor_checkin_confirm بلا جلسة: reason=no_session_cookie', $resp_confirm_denied['data']['reason'] ?? null, 'no_session_cookie');

$_POST = ['qr_payload' => 'x|1|y'];
$resp_scan_bad_nonce = call_ajax_handler('pge_supervisor_checkin_scan_handler');
check('أ. nonce غير صالح: reason=invalid_nonce', $resp_scan_bad_nonce['data']['reason'] ?? null, 'invalid_nonce');
$_POST = [];

// ============================================================================
// السيناريو ب: مسار QR (QR Workflow) — مسح صالح ثم تأكيد ناجح
// ============================================================================
echo "\n=== السيناريو ب: مسار QR ===\n";

setup_catalog_owner_with_event(9701, 9901, 5, 'ui-checkin-b');
set_test_event_full(9901, 9701, 'مناسبة تسجيل الحضور ب');
$supB = authenticate_supervisor_for_event(9901, 9701, '0561000001', 'مشرف تسجيل ب');
seed_invited_guest(9901, '0571000001', 'ضيف مسح QR', 'QRQR-0001');
$rsvp_b1 = seed_pending_rsvp(9901, '0571000001', 1);

$slice_b = render_supervisor_checkin_slice($TEMPLATE_FILE);
check('ب. التفويض نجح: authorized', $slice_b['vars']['authorization']['result'] ?? null, 'authorized');
check_true('ب. HTML يحتوي لوحة مسح QR', strpos($slice_b['html'], 'data-role="qr-panel"') !== false);
check_true('ب. HTML يحتوي لوحة البحث اليدوي (مخفية افتراضياً)', preg_match('/id="manual-panel"[^>]*\bhidden\b/', $slice_b['html']) === 1);

// Phase 9B QR Architecture Final Fix: qr_version=1 صريح (Repository غير
// مُحمَّلة هنا، فالإصدار المتوقَّع يفترض 1 افتراضياً).
$qr_payload_b = PGE_Checkin_QR_Service::build_payload(9901, $rsvp_b1, 1);

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'qr_payload' => $qr_payload_b];
$resp_scan_b = call_ajax_handler('pge_supervisor_checkin_scan_handler');
check('ب. مسح QR صالح: success=true', $resp_scan_b['success'] ?? null, true);
check('ب. مسح QR: اسم الضيف صحيح', $resp_scan_b['data']['guest']['name'] ?? null, 'ضيف مسح QR');
check('ب. مسح QR: expected_guest_count = 2 (1 + مرافق واحد)', $resp_scan_b['data']['guest']['expected_guest_count'] ?? null, 2);

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $qr_payload_b, 'attendance_origin' => 'qr', 'actual_count' => 2];
$resp_confirm_b = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('ب. تأكيد QR: success=true', $resp_confirm_b['success'] ?? null, true);
check('ب. تأكيد QR: actual_count = 2', $resp_confirm_b['data']['actual_count'] ?? null, 2);
check_true('ب. تأكيد QR: checked_in_at مملوء فعلياً', ($resp_confirm_b['data']['guest']['checked_in_at'] ?? '') !== '');
check_true('ب. تأكيد QR: rsvp_id فعلياً موجود في صف RSVP الحقيقي (تحقّق كتابة حقيقية على $wpdb)', ((int) ($wpdb->rsvps[$rsvp_b1]['checked_in'] ?? 0)) === 1);
check('ب. [Final Fix] تدقيق سلامة الطريقة: checkin_method المُسجَّل = qr (أصل حقيقي: مسح كاميرا)', $wpdb->rsvps[$rsvp_b1]['checkin_method'] ?? null, 'qr');
$audit_rows_b = array_values($wpdb->audit_log);
check('ب. [Final Fix] سطر التدقيق (audit_log) نفسه يحمل method=qr أيضاً', $audit_rows_b[count($audit_rows_b) - 1]['method'] ?? null, 'qr');

// ============================================================================
// السيناريو ج: البحث اليدوي (Manual Search Workflow) — بلا نتائج/نتيجة واحدة
// عبر pge_supervisor_checkin_ui_search (طبقة العرض الآمنة الجديدة)
// ============================================================================
echo "\n=== السيناريو ج: البحث اليدوي ===\n";

setup_catalog_owner_with_event(9702, 9902, 5, 'ui-checkin-c');
set_test_event_full(9902, 9702, 'مناسبة البحث اليدوي ج');
$supC = authenticate_supervisor_for_event(9902, 9702, '0561000002', 'مشرف تسجيل ج');
seed_invited_guest(9902, '0571000002', 'ضيف البحث اليدوي', 'MANU-0002');
$rsvp_c1 = seed_pending_rsvp(9902, '0571000002', 0);

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'query' => 'ضيف لا وجود له إطلاقاً'];
$resp_no_results = call_ajax_handler('pge_supervisor_checkin_ui_search_handler');
check('ج. بحث بلا نتائج: success=true', $resp_no_results['success'] ?? null, true);
check('ج. بحث بلا نتائج: result=no_results', $resp_no_results['data']['result'] ?? null, 'no_results');
check('ج. بحث بلا نتائج: candidates=[]', $resp_no_results['data']['candidates'] ?? null, []);

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'query' => 'البحث اليدوي'];
$resp_single = call_ajax_handler('pge_supervisor_checkin_ui_search_handler');
check('ج. بحث باسم جزئي: success=true', $resp_single['success'] ?? null, true);
check('ج. بحث باسم جزئي: result=single', $resp_single['data']['result'] ?? null, 'single');
check('ج. النتيجة الوحيدة: name صحيح', $resp_single['data']['candidates'][0]['name'] ?? null, 'ضيف البحث اليدوي');
check_true('ج. النتيجة الوحيدة: reference موقَّع غير فارغ', ($resp_single['data']['candidates'][0]['reference'] ?? '') !== '');
check_true('ج. Security: لا مفتاح rsvp_id إطلاقاً في استجابة البحث', !array_key_exists('rsvp_id', $resp_single['data']['candidates'][0]));
check_true('ج. Security: لا هاتف خام كامل — فقط masked_phone مقنَّع', strpos((string) ($resp_single['data']['candidates'][0]['masked_phone'] ?? ''), '•') !== false);
check_true('ج. Security: الاستجابة الخام (JSON) لا تحوي رقم الهاتف الكامل 0571000002 إطلاقاً', strpos(json_encode($resp_single), '0571000002') === false);

// تأكيد عبر reference البحث اليدوي (Signed Reference Workflow) — Phase 7
// Final Fix ("Audit Method Integrity"): identifier_type يبقى 'qr' (نفس آلية
// الحلّ المُوقَّعة المُعاد استخدامها)، لكن attendance_origin يصل الآن صراحةً
// كـ'manual' (الأصل الحقيقي للعملية) — لا يُشتَق أحدهما من الآخر بعد الآن.
$reference_c = $resp_single['data']['candidates'][0]['reference'];
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $reference_c, 'attendance_origin' => 'manual', 'actual_count' => 1];
$resp_confirm_c = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('د. تأكيد عبر reference البحث اليدوي: success=true', $resp_confirm_c['success'] ?? null, true);
check('د. [Final Fix] checkin_method المُسجَّل فعلياً = manual (الأصل الحقيقي: بحث يدوي، رغم identifier_type=qr للحلّ)', $wpdb->rsvps[$rsvp_c1]['checkin_method'] ?? null, 'manual');
$audit_rows_d = array_values($wpdb->audit_log);
check('د. [Final Fix] سطر التدقيق (audit_log) نفسه يحمل method=manual أيضاً', $audit_rows_d[count($audit_rows_d) - 1]['method'] ?? null, 'manual');

// ============================================================================
// السيناريو هـ: اختيار من مرشَّحين متعددين (Multiple Candidate Selection) —
// نفس هاتف مطابق لأكثر من صف RSVP واحد (عقد "ambiguous" الحالي غير المُعدَّل)
// ============================================================================
echo "\n=== السيناريو هـ: اختيار من مرشَّحين متعددين ===\n";

setup_catalog_owner_with_event(9703, 9903, 5, 'ui-checkin-e');
set_test_event_full(9903, 9703, 'مناسبة المرشَّحين المتعددين');
$supE = authenticate_supervisor_for_event(9903, 9703, '0561000003', 'مشرف تسجيل هـ');
seed_invited_guest(9903, '0571000003', 'ضيف مشترك الهاتف', 'DUP0-0003');
$rsvp_e1 = seed_pending_rsvp(9903, '0571000003', 0);
$rsvp_e2 = seed_pending_rsvp(9903, '0571000003', 2);

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'query' => '0571000003'];
$resp_multi = call_ajax_handler('pge_supervisor_checkin_ui_search_handler');
check('هـ. بحث بهاتف مشترك بين صفَّين: result=multiple', $resp_multi['data']['result'] ?? null, 'multiple');
check('هـ. عدد المرشَّحين = 2', count($resp_multi['data']['candidates'] ?? []), 2);
check_true('هـ. Security: لا مفتاح rsvp_id في أي مرشَّح', !array_key_exists('rsvp_id', $resp_multi['data']['candidates'][0]) && !array_key_exists('rsvp_id', $resp_multi['data']['candidates'][1]));
check_true('هـ. كل مرشَّح يحمل reference موقَّعاً مختلفاً عن الآخر', ($resp_multi['data']['candidates'][0]['reference'] ?? 'a') !== ($resp_multi['data']['candidates'][1]['reference'] ?? 'b'));

// اختيار المرشَّح الثاني تحديداً (expected_guest_count=3) وتأكيده.
$chosen = null;
foreach ($resp_multi['data']['candidates'] as $c) {
    if ((int) ($c['expected_guest_count'] ?? 0) === 3) {
        $chosen = $c;
    }
}
check_true('هـ. تم تحديد المرشَّح الصحيح (expected_guest_count=3) من بين الاثنين', $chosen !== null);

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $chosen['reference'], 'attendance_origin' => 'manual', 'actual_count' => 3];
$resp_confirm_e = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('هـ. تأكيد المرشَّح المختار: success=true', $resp_confirm_e['success'] ?? null, true);
check('هـ. الصف الصحيح (rsvp_e2) هو من سُجِّل، لا الآخر', $wpdb->rsvps[$rsvp_e2]['checked_in'] ?? null, 1);
check('هـ. الصف الآخر (rsvp_e1) بقي بلا تسجيل حضور', $wpdb->rsvps[$rsvp_e1]['checked_in'] ?? null, 0);
check('هـ. [Final Fix] checkin_method المُسجَّل = manual (اختيار من قائمة مرشَّحين هو أصلاً بحث يدوي)', $wpdb->rsvps[$rsvp_e2]['checkin_method'] ?? null, 'manual');
// السيناريوهات الثلاثة معاً تُثبِت: "Both flows resolve the same RSVP
// correctly" — كل من مسار QR (ب) والبحث اليدوي المباشر (د) واختيار مرشَّح من
// قائمة (هـ) يُسجِّل بالضبط صف RSVP الصحيح الذي طلبه المستخدم، لا غيره.
check_true('هـ. [Final Fix] Recorder سلوكه مطابق تماماً لمسار QR (نفس الحقول: expected_count/actual_count/audit_log_id)', isset($resp_confirm_e['data']['expected_count']) && isset($resp_confirm_e['data']['actual_count']));

// ============================================================================
// السيناريو و: تسجيل حضور مكرَّر (Duplicate Attendance)
// ============================================================================
echo "\n=== السيناريو و: تسجيل حضور مكرَّر ===\n";

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $chosen['reference'], 'attendance_origin' => 'manual', 'actual_count' => 2];
$resp_dup = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('و. تسجيل مكرَّر لنفس الضيف: success=true (ليس خطأً)', $resp_dup['success'] ?? null, true);
check('و. تسجيل مكرَّر: already=true', $resp_dup['data']['already'] ?? null, true);
check('و. تسجيل مكرَّر: العدد الفعلي يبقى القيمة الأصلية (3) لا يتغيَّر إلى (2)', $wpdb->rsvps[$rsvp_e2]['actual_entered_count'] ?? null, 3);
check('و. [Final Fix] حماية التكرار غير متأثِّرة بالإصلاح: checkin_method المُخزَّن يبقى manual كما سُجِّل أول مرة', $wpdb->rsvps[$rsvp_e2]['checkin_method'] ?? null, 'manual');

// ============================================================================
// السيناريو ز: أخطاء التحقق (Validation Errors) — عدد فعلي غير صالح
// ============================================================================
echo "\n=== السيناريو ز: أخطاء التحقق ===\n";

setup_catalog_owner_with_event(9704, 9904, 5, 'ui-checkin-g');
set_test_event_full(9904, 9704, 'مناسبة أخطاء التحقق');
$supG = authenticate_supervisor_for_event(9904, 9704, '0561000004', 'مشرف تسجيل ز');
seed_invited_guest(9904, '0571000004', 'ضيف التحقق', 'VALD-0004');
$rsvp_g1 = seed_pending_rsvp(9904, '0571000004', 1); // expected_guest_count = 2
$qr_payload_g = PGE_Checkin_QR_Service::build_payload(9904, $rsvp_g1, 1);

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $qr_payload_g, 'attendance_origin' => 'qr', 'actual_count' => 0];
$resp_invalid_low = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('ز. عدد فعلي = 0: success=false', $resp_invalid_low['success'] ?? null, false);
check('ز. عدد فعلي = 0: reason=invalid_actual_count', $resp_invalid_low['data']['reason'] ?? null, 'invalid_actual_count');
check('ز. عدد فعلي = 0: expected_count = 2 مُرفَق للعرض', $resp_invalid_low['data']['expected_count'] ?? null, 2);

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $qr_payload_g, 'attendance_origin' => 'qr', 'actual_count' => 99];
$resp_invalid_high = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('ز. عدد فعلي = 99 (> المتوقَّع): success=false', $resp_invalid_high['success'] ?? null, false);
check('ز. عدد فعلي = 99: reason=invalid_actual_count', $resp_invalid_high['data']['reason'] ?? null, 'invalid_actual_count');
check('ز. عدد فعلي = 99: لم يُسجَّل أي حضور فعلياً', $wpdb->rsvps[$rsvp_g1]['checked_in'] ?? null, 0);

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'qr_payload' => 'malformed-not-three-parts'];
$resp_malformed = call_ajax_handler('pge_supervisor_checkin_scan_handler');
check('ز. حمولة QR مشوَّهة: success=false', $resp_malformed['success'] ?? null, false);
check('ز. حمولة QR مشوَّهة: reason=malformed_payload', $resp_malformed['data']['reason'] ?? null, 'malformed_payload');

$qr_payload_wrong_event = PGE_Checkin_QR_Service::build_payload(9901, $rsvp_b1, 1); // يخصّ مناسبة أخرى
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'qr_payload' => $qr_payload_wrong_event];
$resp_wrong_event = call_ajax_handler('pge_supervisor_checkin_scan_handler');
check('ز. رمز QR يخص مناسبة أخرى: success=false', $resp_wrong_event['success'] ?? null, false);
check('ز. رمز QR يخص مناسبة أخرى: reason=event_mismatch', $resp_wrong_event['data']['reason'] ?? null, 'event_mismatch');

$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'query' => ''];
$resp_empty_query = call_ajax_handler('pge_supervisor_checkin_ui_search_handler');
check('ز. بحث بنص فارغ: success=false', $resp_empty_query['success'] ?? null, false);
check('ز. بحث بنص فارغ: reason=empty_query', $resp_empty_query['data']['reason'] ?? null, 'empty_query');

// ============================================================================
// السيناريو ك: سلامة طريقة التدقيق (Phase 7 Final Fix — "Audit Method
// Integrity") — فصل "حلّ الدعوة" عن "أصل تسجيل الحضور"، رفض القيم المجهولة/
// المُتلاعَب بها، وتأكيد بقاء كل ما عداه (القفل/التفويض/الحماية من التكرار)
// بلا تغيير إطلاقاً.
// ============================================================================
echo "\n=== السيناريو ك: سلامة طريقة التدقيق ===\n";

setup_catalog_owner_with_event(9705, 9905, 5, 'ui-checkin-k');
set_test_event_full(9905, 9705, 'مناسبة سلامة التدقيق');
$supK = authenticate_supervisor_for_event(9905, 9705, '0561000005', 'مشرف تسجيل ك');
seed_invited_guest(9905, '0571000005', 'ضيف سلامة التدقيق', 'AUDT-0005');
$rsvp_k1 = seed_pending_rsvp(9905, '0571000005', 0);
$qr_payload_k = PGE_Checkin_QR_Service::build_payload(9905, $rsvp_k1, 1);

// (8) قيمة attendance_origin مجهولة تماماً → رفض صريح، بلا افتراض صامت، وبلا
// أي تسجيل حضور.
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $qr_payload_k, 'attendance_origin' => 'whatsapp', 'actual_count' => 1];
$resp_unknown_origin = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('ك.8 attendance_origin مجهول ("whatsapp"): success=false', $resp_unknown_origin['success'] ?? null, false);
check('ك.8 attendance_origin مجهول: reason=invalid_attendance_origin', $resp_unknown_origin['data']['reason'] ?? null, 'invalid_attendance_origin');
check('ك.8 attendance_origin مجهول: لم يُسجَّل أي حضور فعلياً', $wpdb->rsvps[$rsvp_k1]['checked_in'] ?? null, 0);

// (8ب) attendance_origin مفقود تماماً (لا حقل إطلاقاً) → نفس الرفض، بلا قيمة
// افتراضية صامتة (Requirement: "Never silently default").
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $qr_payload_k, 'actual_count' => 1];
$resp_missing_origin = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('ك.8ب attendance_origin مفقود تماماً: success=false', $resp_missing_origin['success'] ?? null, false);
check('ك.8ب attendance_origin مفقود: reason=invalid_attendance_origin (لا افتراض صامت)', $resp_missing_origin['data']['reason'] ?? null, 'invalid_attendance_origin');

// (9) قيمة "مُتلاعَب بها" (محاولة حقن/تلاعب) → نفس الرفض الصارم عبر القائمة
// البيضاء المُحكَمة (PGE_Checkin_Recorder::VALID_METHODS)، بلا أي استثناء أو
// معالجة خاصة لأشكال التلاعب.
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $qr_payload_k, 'attendance_origin' => "qr' OR '1'='1", 'actual_count' => 1];
$resp_tampered_origin = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('ك.9 attendance_origin مُتلاعَب به (نمط حقن): success=false', $resp_tampered_origin['success'] ?? null, false);
check('ك.9 attendance_origin مُتلاعَب به: reason=invalid_attendance_origin', $resp_tampered_origin['data']['reason'] ?? null, 'invalid_attendance_origin');
check('ك.9 attendance_origin مُتلاعَب به: لم يُسجَّل أي حضور فعلياً', $wpdb->rsvps[$rsvp_k1]['checked_in'] ?? null, 0);

// (1) و(2) و(3): تأكيد QR حقيقي على نفس المناسبة يُنتج method=qr، ثم إثبات
// أن الحلّ الصحيح لنفس RSVP يبقى سليماً (rsvp_id الصحيح هو من سُجِّل).
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $qr_payload_k, 'attendance_origin' => 'qr', 'actual_count' => 1];
$resp_confirm_k_qr = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('ك.1 مسار QR الحقيقي بعد الإصلاح: success=true', $resp_confirm_k_qr['success'] ?? null, true);
check('ك.1 مسار QR: checkin_method = qr', $wpdb->rsvps[$rsvp_k1]['checkin_method'] ?? null, 'qr');
$audit_rows_k = array_values($wpdb->audit_log);
check('ك.1 مسار QR: سطر التدقيق method=qr أيضاً', $audit_rows_k[count($audit_rows_k) - 1]['method'] ?? null, 'qr');
check('ك.3 الحلّ يشير لنفس RSVP الصحيح (rsvp_k1)', $audit_rows_k[count($audit_rows_k) - 1]['rsvp_id'] ?? null, $rsvp_k1);

// (5) حماية التكرار غير متأثِّرة: محاولة ثانية بأصل مختلف (manual) على نفس
// RSVP المُسجَّل بالفعل تُعامَل بلا أي كتابة إضافية، وتبقى already_checked_in
// بصرف النظر عن attendance_origin الجديد المُرسَل (Requirement: "Duplicate
// protection unchanged").
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $qr_payload_k, 'attendance_origin' => 'manual', 'actual_count' => 1];
$resp_confirm_k_dup = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('ك.5 محاولة ثانية بأصل مختلف على RSVP مُسجَّل مسبقاً: already=true (حماية التكرار سليمة)', $resp_confirm_k_dup['data']['already'] ?? null, true);
check('ك.5 checkin_method يبقى qr (القيمة الأصلية، لم يُعِد الكتابة بـmanual)', $wpdb->rsvps[$rsvp_k1]['checkin_method'] ?? null, 'qr');

// (4) سلوك Recorder مطابق تماماً للسابق: نفس الحقول المُعادة (expected_count/
// actual_count/audit_log_id عبر مسار النجاح).
check_true('ك.4 سلوك Recorder الأساسي (expected_count/actual_count) لم يتغيَّر', isset($resp_confirm_k_qr['data']['expected_count']) && isset($resp_confirm_k_qr['data']['actual_count']));

// (6) القفل (Locking) غير متأثِّر: كل عملية GET_LOCK قابلها RELEASE_LOCK
// بنفس الاسم — بلا أي قفل مُعلَّق (نفس بنية اسم القفل event_id|rsvp_id).
check('ك.6 القفل: عدد محاولات الحصول على القفل = عدد التحريرات (لا قفل مُعلَّق أبداً)', count($wpdb->lock_acquire_log), count($wpdb->lock_release_log));
check_true('ك.6 القفل: لا قفل واحد يبقى محتجَزاً بعد كل هذه العمليات', empty($wpdb->held_locks));

// (7) التفويض غير متأثِّر: بلا جلسة، نفس نقطة نهاية التأكيد ترفض بنفس السبب
// السابق تماماً (no_session_cookie) — الإصلاح لم يمسّ طبقة التفويض إطلاقاً.
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_checkin_nonce'), 'identifier_type' => 'qr', 'identifier_value' => $qr_payload_k, 'attendance_origin' => 'qr', 'actual_count' => 1];
$resp_confirm_k_noauth = call_ajax_handler('pge_supervisor_checkin_confirm_handler');
check('ك.7 تأكيد بلا جلسة: success=false', $resp_confirm_k_noauth['success'] ?? null, false);
check('ك.7 تأكيد بلا جلسة: reason=no_session_cookie (نفس سلوك التفويض السابق تماماً)', $resp_confirm_k_noauth['data']['reason'] ?? null, 'no_session_cookie');
$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $supK['session_token'];

// ============================================================================
// السيناريو ح: تحديث لوحة الحضور تلقائياً بعد النجاح (Dashboard Refresh
// Integration) — تحقّق حقيقي على Provider + تحقّق بنيوي على JS المُصدَّر
// ============================================================================
echo "\n=== السيناريو ح: تحديث لوحة الحضور تلقائياً ===\n";

// تحقّق حقيقي: بعد تسجيلي الحضور في السيناريو ب (event 9901)، يعكس Provider
// (غير المُعدَّل) هذا التغيير فوراً — نفس نقطة AJAX المُستخدَمة فعلياً من JS.
$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $supB['session_token'];
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_dashboard_nonce')];
$resp_dashboard_after = call_ajax_handler('pge_supervisor_dashboard_data_handler');
check('ح. لوحة الحضور تعكس فوراً تسجيل الحضور من السيناريو ب: checked_in_invitations=1', $resp_dashboard_after['data']['attendance_summary']['checked_in_invitations'] ?? null, 1);

// تحقّق بنيوي: القالب فعلياً يستدعي refreshSessionSummary() داخل مسار
// النجاح تحديداً، وبنفس نقطة AJAX الحقيقية (pge_supervisor_dashboard_data)
// — لا Endpoint جديد مخترَع لهذا الغرض، ولا تعديل على Dashboard Provider.
check_true('ح. renderSuccessScreen() يستدعي refreshSessionSummary() فعلياً', preg_match('/function renderSuccessScreen\([^)]*\)\s*\{(?:(?!function\s).)*?refreshSessionSummary\(\)/s', $slice_b['html']) === 1);
check_true('ح. refreshSessionSummary() يستخدم نفس dashboardAction الحقيقي (pge_supervisor_dashboard_data)', strpos($slice_b['html'], 'dashboardAction: "pge_supervisor_dashboard_data"') !== false);
check_true('ح. لا إعادة تحميل للصفحة (لا استدعاء location.reload في كامل الـJS)', strpos($slice_b['html'], 'location.reload') === false);

// ============================================================================
// السيناريو ط: بنية JS لمسح QR ولإمكانية الوصول (تحقّق بنيوي — راجع الملاحظة
// أعلى الملف حول حدود تنفيذ JS داخل حصاد PHP بحت)
// ============================================================================
echo "\n=== السيناريو ط: بنية JS لمسح QR وإمكانية الوصول ===\n";

check_true('ط. استخدام BarcodeDetector الأصلية (بلا مكتبة خارجية/CDN)', strpos($slice_b['html'], 'window.BarcodeDetector') !== false);
check_true('ط. لا وجود لأي <script src="http يشير لمكتبة خارجية', preg_match('/<script[^>]+src=["\']https?:/', $slice_b['html']) === 0);
check_true('ط. دعم تبديل الكاميرا (enumerateDevices + قائمة videoDevices)', strpos($slice_b['html'], 'enumerateDevices') !== false && strpos($slice_b['html'], 'qr-switch-camera') !== false);
check_true('ط. إيقاف المسح أثناء المعالجة (pauseScanning عند فك الترميز)', strpos($slice_b['html'], 'pauseScanning();') !== false);
check_true('ط. استئناف تلقائي بعد الانتهاء (resumeScanning عند الإلغاء/النجاح/التكرار)', substr_count($slice_b['html'], 'resumeScanning()') >= 2);
check_true('ط. منع المسح المكرَّر لنفس الرمز (duplicateScanCooldownMs)', strpos($slice_b['html'], 'duplicateScanCooldownMs') !== false);
check_true('ط. إطار توجيه المسح (Scan Overlay) موجود في HTML', strpos($slice_b['html'], 'data-role="qr-overlay"') !== false);
check_true('ط. تراجع يدوي متاح دائماً (زر "استخدام البحث اليدوي")', strpos($slice_b['html'], 'qr-use-manual') !== false);
check_true('ط. إرشاد إذن الكاميرا موجود', strpos($slice_b['html'], 'qr-permission-guidance') !== false);
check_true('ط. رسالة عدم الدعم + تحويل لدعم عدم توفر BarcodeDetector', strpos($slice_b['html'], 'qr-unsupported') !== false);
check_true('ط. Debounce بحث يدوي (300ms) موجود', strpos($slice_b['html'], 'searchDebounceMs: 300') !== false);
check_true('ط. إلغاء الطلبات القديمة (searchRequestToken) موجود', strpos($slice_b['html'], 'searchRequestToken') !== false);
check_true('ط. منع الإرسال المزدوج للتأكيد (confirmInFlight + تعطيل الزر)', strpos($slice_b['html'], 'confirmInFlight') !== false && strpos($slice_b['html'], 'btn.disabled = true') !== false);
check_true('ط. role="tablist"/"tab"/"tabpanel" لمبدِّل الوضع (Accessibility)', strpos($slice_b['html'], 'role="tablist"') !== false && strpos($slice_b['html'], 'role="tab"') !== false && strpos($slice_b['html'], 'role="tabpanel"') !== false);
check_true('ط. aria-live موجود للإعلانات الصوتية (sr-announcer)', strpos($slice_b['html'], 'data-role="sr-announcer"') !== false);
check_true('ط. focus-visible:ring على الأزرار التفاعلية', strpos($slice_b['html'], 'focus-visible:ring') !== false);
check_true('ط. تحويل التركيز (focus()) عند تغيّر الشاشة (شاشتا التأكيد والنجاح)', substr_count($slice_b['html'], '.focus();') >= 2);

// ============================================================================
// السيناريو ي: انحدار على المراحل 1-6 (عبر نفس الأنبوب الحقيقي المُستخدَم أعلاه)
// ============================================================================
echo "\n=== السيناريو ي: انحدار على المراحل 1-6 ===\n";

$quota_regression = pge_resolve_supervisor_quota_status(9901);
check_true('ي. Phase 1 (Supervisor Quota Resolver) لا يزال يعمل', !is_wp_error($quota_regression));

check_true('ي. Phase 2 (Assignment Service) لا يزال يعمل', pge_has_active_supervisor_assignment(9901, '0561000001'));

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$authz_regression = PGE_Supervisor_Portal_Middleware::authorize();
check('ي. Phase 3/3.5 (Middleware) لا يزال يعمل — لا جلسة حالياً', $authz_regression['result'] ?? null, 'denied');

$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $supB['session_token'];
$authz_regression2 = PGE_Supervisor_Portal_Middleware::authorize();
check('ي. Phase 3/3.5: جلسة صالحة توثِّق نفس المناسبة بعد كل السيناريوهات السابقة', $authz_regression2['event_id'] ?? null, 9901);

check_true('ي. Phase 5 (Statistics Service) لا يزال محمي الإنشاء المباشر (Final Fix)', (function () {
    try {
        new PGE_Attendance_Statistics_Service();
        return false;
    } catch (\Error $e) {
        return true;
    }
})());

$dashboard_regression = PGE_Attendance_Dashboard_Provider::get_dashboard(9901);
check('ي. Phase 5/6 (Dashboard Provider) لا يزال يعمل فوق كامل الأنبوب بعد كل هذه السيناريوهات', $dashboard_regression['result'] ?? null, 'authorized');

$slice_dashboard_regression = null;
if (defined('PGE_TEST_UI_ROOT') && file_exists(PGE_TEST_UI_ROOT . '/templates/supervisor-dashboard.php')) {
    $content = file_get_contents(PGE_TEST_UI_ROOT . '/templates/supervisor-dashboard.php');
    $body = preg_replace('/^<\?php/', '', $content, 1);
    $body = str_replace('exit;', 'return get_defined_vars();', $body);
    $fn = '__test_regr_dashboard_after_checkin';
    eval('function ' . $fn . '() { ' . $body . " \nreturn get_defined_vars(); }");
    ob_start();
    try {
        $slice_dashboard_regression = call_user_func($fn);
    } catch (Test_Wp_Die_Exception $e) {
    }
    ob_get_clean();
}
if ($slice_dashboard_regression !== null) {
    check('ي. Phase 6 (لوحة الحضور نفسها) لا تزال تعمل وتعرض المناسبة الصحيحة بعد Phase 7', $slice_dashboard_regression['authorization']['result'] ?? null, 'authorized');
}

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

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
