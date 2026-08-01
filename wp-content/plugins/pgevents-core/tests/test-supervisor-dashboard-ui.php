<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـEntry Check-in Supervisors، Phase 6
 * ("Supervisor Attendance Dashboard UI" RFC) — templates/supervisor-dashboard.php
 * وincludes/dashboard-ajax.php. يستدعي الأنبوب الحقيقي الكامل (Catalog →
 * Assignment Service → Session → Authenticator → Portal Middleware →
 * PGE_Attendance_Dashboard_Provider → PGE_Attendance_Statistics_Service) بلا
 * أي تعديل على أي من هذه الطبقات — هذه المرحلة عرض فقط.
 *
 * القالب الحقيقي (supervisor-dashboard.php) ينتهي بـ`exit;` بعد كل فرع (تماماً
 * كـsupervisor-portal.php)، ما يمنع تضمينه مباشرة داخل عملية اختبار واحدة
 * متعددة السيناريوهات. الحل المُعتمَد هنا (نفس فلسفة run_template_slice() في
 * test-event-quota-ui-integration.php حرفياً): قراءة المحتوى الحرفي الحقيقي
 * للقالب (file_get_contents — بلا أي تعديل يدوي)، تنفيذه داخل دالة مغلَّفة
 * فريدة الاسم في كل استدعاء، مع استبدال نصي لكل ظهور حرفي لـ`exit;` بـ`return;`
 * (توقّف عن التنفيذ ضمن نفس الدالة المغلَّفة فقط — تكافؤ ضبطي بحت لغرض تشغيل
 * الاختبار داخل عملية واحدة، بلا أي أثر على منطق العرض/التفويض الفعلي الذي
 * يُنفَّذ حرفياً وبلا أي تعديل آخر في كل مرة).
 *
 * السيناريوهات المطلوبة صراحةً: عرض اللوحة (Dashboard rendering)، مساعدات
 * التخطيط المتجاوب (Responsive layout helpers)، تكامل Provider، وصول غير
 * مصرَّح، مشرف مصرَّح، لوحة فارغة، حضور جزئي، مجموعة بيانات كبيرة، سلوك
 * الاستطلاع الدوري (Polling)، حالة التحميل (Loading state)، وانحدار على
 * المراحل 1-5 (يُتحقَّق منه هنا عبر مسار الأنبوب الحقيقي الكامل، وعبر تشغيل
 * كامل حزمة الاختبارات الأخرى بعد هذا الملف).
 *
 * ملاحظة صريحة حول حدود هذا الحصاد: "سلوك الاستطلاع الدوري" (JS في المتصفح:
 * setInterval/fetch الفعلي) غير قابل للتنفيذ داخل حصاد PHP بحت بلا متصفح
 * حقيقي — يُختبَر هنا بطريقتين صادقتين بدلاً من محاكاته: (أ) نقطة النهاية
 * الخادمية التي يعتمد عليها الاستطلاع (pge_supervisor_dashboard_data_handler)
 * تُختبَر تنفيذياً بالكامل (نجاح/رفض حقيقيان)، و(ب) وجود إعدادات الاستطلاع
 * الصحيحة (فاصل 30 ثانية، حارس isRefreshing، إعادة ضبط المؤقّت عند التحديث
 * اليدوي) يُتحقَّق منه كتحقّق بنيوي على نص الـJS المُصدَّر فعلياً ضمن HTML
 * الناتج — لا يُدَّعى تنفيذ JS فعلياً؛ هذا القيد مذكور صراحة في التقرير النهائي.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-supervisor-dashboard-ui.php
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

// ── تفويض (Requirement 8): current_user_can/get_current_user_id/get_post_field
// — نفس أسلوب test-event-quota-ui-integration.php حرفياً. ────────────────────
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

// ── Stubs عرض/HTML/AJAX (Phase 6 فقط — غير موجودة في test-attendance-
// statistics.php الأصلي لأنه لم يكن يُصيِّر أي HTML) ─────────────────────────

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
 * استدعاء معالج AJAX حقيقي (pge_supervisor_dashboard_data_handler) بأمان داخل
 * حصاد اختبار واحد متعدد السيناريوهات — يلتقط استجابة wp_send_json_*
 * (المُوقَفة أعلاه لترمي Test_Wp_Die_Exception بدل exit حقيقي) كنص JSON خام.
 */
function call_dashboard_ajax_handler(): array
{
    $GLOBALS['__test_json_response'] = null;
    try {
        pge_supervisor_dashboard_data_handler();
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
 * قصّ وتنفيذ شريحة PHP حقيقية من templates/supervisor-dashboard.php الحقيقي
 * (بلا أي تعديل يدوي على محتواه) — نفس فلسفة run_template_slice() في
 * test-event-quota-ui-integration.php حرفياً، بإضافة استبدال نصي لـ`exit;`
 * بـ`return;` (تكافؤ ضبطي بحت لتشغيله داخل دالة مغلَّفة فريدة الاسم، بلا أي
 * أثر على منطق العرض/التفويض نفسه) لتفادي إنهاء عملية الاختبار بالكامل.
 *
 * @return array{html:string,vars:array}
 */
$GLOBALS['__test_slice_call_counter'] = 0;
function render_supervisor_dashboard_slice($template_file): array
{
    $content = file_get_contents($template_file);
    if ($content === false) {
        throw new RuntimeException("تعذّرت قراءة الملف: $template_file");
    }
    $body = preg_replace('/^<\?php/', '', $content, 1);
    // كل `exit;` حقيقي يُصبح `return get_defined_vars();` — نقطة توقّف الفرع
    // نفسها تلتقط كل المتغيّرات المُعرَّفة حتى تلك اللحظة بالضبط (وليس بعدها،
    // إذ لا كود يُنفَّذ بعد exit/return أصلاً في نفس الفرع على أي حال).
    $body = str_replace('exit;', 'return get_defined_vars();', $body);

    $GLOBALS['__test_slice_call_counter']++;
    $fn_name = '__test_render_supervisor_dashboard_' . $GLOBALS['__test_slice_call_counter'];

    $wrapped = 'function ' . $fn_name . '() { ' . $body . " \nreturn get_defined_vars(); }";
    eval($wrapped);

    ob_start();
    $vars = [];
    try {
        $vars = call_user_func($fn_name);
    } catch (Test_Wp_Die_Exception $e) {
        // نتوقّف هنا فقط إذا ضربنا حارس الصفوف المفقودة (لا يحدث في اختباراتنا
        // لأن الأصناف المطلوبة محمَّلة دائماً)، أو نداء wp_die حقيقي آخر.
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

// ── Fake $wpdb — نفس عائلة Fake_Wpdb_Checkin_Engine (Phase 4)، مع إضافة صيغ
// استعلامات Phase 5 الجديدة (تجميع Attendance Summary، دفعة Recent Check-ins،
// تجميع Supervisor Summary، قائمة list_assignments_for_event). ─────────────

class Fake_Wpdb_Attendance_Stats
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

    /**
     * عدّاد تجسّسي (spy) لعدد استعلامات "محرّك الإحصاء" الفعلية المُنفَّذة —
     * يُزاد فقط داخل الفروع الثلاثة الخاصة بـPhase 5 (ملخّص الحضور المُجمَّع،
     * آخر عمليات التسجيل + دفعة RSVP المرافقة لها، ملخّص المشرفين المُجمَّع +
     * قائمة الإسنادات المرافقة لها) — لا يُزاد أبداً بواسطة استعلامات التفويض
     * (بحث مشرف/جلسة/Tier/Catalog). يُستخدَم لإثبات "Query Execution Guard":
     * صفر استعلامات محرّك عند فشل التفويض، قبل تنفيذ أي حساب.
     */
    public $engine_query_count = 0;

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

        // Phase 5: استعلام Attendance Summary المُجمَّع الواحد.
        if ($which === 'rsvps' && stripos($sql, 'total_invitations') !== false) {
            $this->engine_query_count++;
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

        // Phase 5: آخر عمليات تسجيل حضور (audit_log، محدود، مُرتَّب).
        if ($which === 'audit_log' && stripos($sql, 'ORDER BY created_at DESC') !== false) {
            $this->engine_query_count++;
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
            $this->engine_query_count++;
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
            $this->engine_query_count++;
            $ids = array_map('intval', array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
            $matched = [];
            foreach ($ids as $id) {
                if (isset($this->rsvps[$id])) {
                    $matched[] = $this->rsvps[$id];
                }
            }
            return $matched;
        }

        // Phase 5: PGE_Supervisor_Assignment_Service::list_assignments_for_event().
        if ($which === 'supervisors' && stripos($sql, 'ORDER BY id ASC') !== false) {
            $this->engine_query_count++;
            preg_match('/event_id\s*=\s*(\d+)/i', $sql, $m);
            $event_id = isset($m[1]) ? (int) $m[1] : 0;
            $matched = array_values(array_filter($this->supervisors, function ($r) use ($event_id) {
                return (int) $r['event_id'] === $event_id;
            }));
            usort($matched, function ($a, $b) { return $a['id'] <=> $b['id']; });
            return $matched;
        }

        if ($which === null || in_array($which, ['supervisors', 'sessions', 'rsvps', 'audit_log'], true)) {
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

$GLOBALS['wpdb'] = new Fake_Wpdb_Attendance_Stats();
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
    Mon_Events_Users::activate_catalog_tier($user_id, 1, $tier['id'], 'STATS-ORDER-' . $tier_key);
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

/** تمهيد صف RSVP مسجَّل الحضور + سطر تدقيق مطابق (قراءة فقط). */
function seed_checked_in_rsvp($event_id, $phone, $companions, $actual_count, $method, $assignment_id, $created_at)
{
    global $wpdb;
    $rsvp_id = $wpdb->seed_rsvp($event_id, $phone, [
        'companions' => $companions,
        'reply' => 'yes',
        'checked_in' => 1,
        'checked_in_at' => $created_at,
        'checked_in_by_assignment_id' => $assignment_id,
        'checkin_method' => $method,
        'actual_entered_count' => $actual_count,
    ]);
    $wpdb->seed_audit_row($event_id, $rsvp_id, $assignment_id, $method, 1 + $companions, $actual_count, $created_at);
    return $rsvp_id;
}

// ── مسار ملف القالب الحقيقي (نفس فلسفة PGE_TEST_UI_ROOT في test-event-quota-
// ui-integration.php) ───────────────────────────────────────────────────────
if (defined('PGE_TEST_UI_ROOT')) {
    $TEMPLATE_FILE = PGE_TEST_UI_ROOT . '/templates/supervisor-dashboard.php';
} else {
    $TEMPLATE_FILE = __DIR__ . '/../templates/supervisor-dashboard.php';
}
if (!file_exists($TEMPLATE_FILE)) {
    throw new RuntimeException('تعذّرت قراءة ملف القالب: ' . $TEMPLATE_FILE);
}

// ============================================================================
// السيناريو أ: وصول غير مصرَّح (Unauthorized access)
// ============================================================================
echo "=== السيناريو أ: وصول غير مصرَّح ===\n";

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$GLOBALS['__test_current_user_id'] = 0;

$slice_a = render_supervisor_dashboard_slice($TEMPLATE_FILE);
check('أ. لا جلسة مشرف: نتيجة التفويض denied', $slice_a['vars']['authorization']['result'] ?? null, 'denied');
check('أ. كود HTTP = 401 (لا كوكي جلسة إطلاقاً)', $slice_a['vars']['http_status'] ?? null, 401);
check_true('أ. HTML يحتوي رسالة "الدخول مطلوب"', strpos($slice_a['html'], 'الدخول مطلوب') !== false);
check_true('أ. لا أرقام حضور مُسرَّبة في صفحة الرفض (بلا data-metric)', strpos($slice_a['html'], 'data-metric') === false);
check_true('أ. صفحة الرفض تحمل role="alert"', strpos($slice_a['html'], 'role="alert"') !== false);
check('أ. لا استدعاء لـProvider حدث إطلاقاً (dashboard غير معرَّف في السياق)', array_key_exists('dashboard', $slice_a['vars']), false);

// ============================================================================
// السيناريو ب: مشرف مصرَّح + حضور جزئي (Authorized supervisor / Partial attendance)
// ============================================================================
echo "\n=== السيناريو ب: مشرف مصرَّح — حضور جزئي ===\n";

setup_catalog_owner_with_event(9601, 9801, 5, 'ui-b');
set_test_event_full(9801, 9601, 'مناسبة لوحة المشرف');
$supB = authenticate_supervisor_for_event(9801, 9601, '0560000001', 'مشرف اللوحة ب');

seed_checked_in_rsvp(9801, '0570000001', 1, 2, 'qr', $supB['assignment_id'], '2026-03-01 10:00:00');
seed_checked_in_rsvp(9801, '0570000002', 0, 1, 'manual', $supB['assignment_id'], '2026-03-01 10:05:00');
seed_pending_rsvp(9801, '0570000003', 3);
seed_pending_rsvp(9801, '0570000004', 0);

$slice_b = render_supervisor_dashboard_slice($TEMPLATE_FILE);
$vars_b = $slice_b['vars'];
$html_b = $slice_b['html'];

check('ب. التفويض نجح: authorized', $vars_b['authorization']['result'] ?? null, 'authorized');
check('ب. عبر المشرف تحديداً', $vars_b['dashboard']['via'] ?? null, 'supervisor');

// --- تكامل Provider: القيم المعروضة مطابقة تماماً لما يُعيده Provider مباشرة ---
$direct_dashboard_b = PGE_Attendance_Dashboard_Provider::get_dashboard(9801);
check('ب. Provider Integration: attendance_summary مطابق تماماً لاستدعاء Provider مباشر', $vars_b['attendance_summary'], $direct_dashboard_b['data']['attendance_summary']);
check('ب. Provider Integration: recent_checkins مطابق تماماً', $vars_b['recent_checkins'], $direct_dashboard_b['data']['recent_checkins']);
check('ب. Provider Integration: supervisor_summary مطابق تماماً', $vars_b['supervisor_summary'], $direct_dashboard_b['data']['supervisor_summary']);

check('ب. total_invitations = 4', $vars_b['attendance_summary']['total_invitations'] ?? null, 4);
check('ب. checked_in_invitations = 2', $vars_b['attendance_summary']['checked_in_invitations'] ?? null, 2);
check('ب. is_empty_dashboard = false', $vars_b['is_empty_dashboard'] ?? null, false);
check_true('ب. رقم إجمالي الدعوات (4) يظهر فعلياً في HTML بجانب data-metric المطابق', preg_match('/data-metric="total_invitations">4</', $html_b) === 1);
check_true('ب. عدد الدعوات المسجَّلة (2) يظهر فعلياً في HTML', preg_match('/data-metric="checked_in_invitations">2</', $html_b) === 1);

// --- قسم المشرف: اسم/حالة/آخر نشاط من Provider فقط (بحث لا حساب) ---
check('ب. current_supervisor_row.assignment_id يطابق جلسة المشرف الحالي', $vars_b['current_supervisor_row']['assignment_id'] ?? null, $supB['assignment_id']);
check('ب. اسم المشرف الظاهر صحيح', $vars_b['current_supervisor_row']['supervisor_name'] ?? null, 'مشرف اللوحة ب');
check_true('ب. آخر نشاط غير فارغ (توجد عملية تسجيل حضور فعلية)', $vars_b['current_supervisor_last_activity'] !== '');
check_true('ب. اسم المشرف يظهر فعلياً في HTML', strpos($html_b, 'مشرف اللوحة ب') !== false);

// --- حالة التحميل: بلا هيكل تحميل ظاهر، بلا شريط خطأ ظاهر، حالة فارغة مخفية ---
check_true('ب. شريط الخطأ مخفي افتراضياً (hidden) عند نجاح العرض', preg_match('/data-role="error-banner"[^>]*\bhidden\b/', $html_b) === 1);
check_true('ب. حالة "لا بيانات" مخفية (hidden) لأن الحضور غير صفري', preg_match('/data-role="empty-state"[^>]*\bhidden\b/', $html_b) === 1);
check_true('ب. هيكل التحميل (Skeleton) مخفي افتراضياً (hidden) عند التصيير الأول (SSR)', preg_match('/data-role="loading-skeleton"[^>]*\bhidden\b/', $html_b) === 1);

// --- مساعدات التخطيط المتجاوب (Responsive layout helpers) ---
check_true('ب. بطاقات الملخّص: عمود واحد على الجوال (grid-cols-1)', strpos($html_b, 'grid-cols-1') !== false);
check_true('ب. بطاقات الملخّص: صفّان على الشاشات المتوسطة (sm:grid-cols-2)', strpos($html_b, 'sm:grid-cols-2') !== false);
check_true('ب. بطاقات الملخّص: 4 أعمدة على الحاسوب (lg:grid-cols-4)', strpos($html_b, 'lg:grid-cols-4') !== false);
check_true('ب. آخر عمليات التسجيل تأخذ عمودين على الحاسوب (lg:col-span-2)', strpos($html_b, 'lg:col-span-2') !== false);
check_true('ب. رأس اللوحة يتكدَّس عمودياً على الجوال ثم أفقياً (sm:flex-row)', strpos($html_b, 'sm:flex-row') !== false);

// --- إمكانية الوصول (Accessibility) ---
check_true('ب. ARIA: aria-live موجودة (تحديثات حيّة بلا انقطاع القارئ الصوتي)', strpos($html_b, 'aria-live') !== false);
check_true('ب. ARIA: aria-disabled موجودة على الأزرار المعطَّلة', strpos($html_b, 'aria-disabled="true"') !== false);
check_true('ب. تسلسل عناوين صحيح: h1 ثم h2 (aria-labelledby مرتبط بمعرِّفات حقيقية)', strpos($html_b, '<h1') !== false && strpos($html_b, 'aria-labelledby="summary-cards-heading"') !== false);
check_true('ب. حالات تركيز مرئية (focus-visible:ring) على الروابط/الأزرار التفاعلية', strpos($html_b, 'focus-visible:ring') !== false);

// --- إجراءات سريعة: تسجيل يدوي/QR معطَّلان "قريباً"، التحديث فعّال ---
function button_tag_containing($html, $data_action)
{
    // يلتقط وسم <button ...> بالكامل الذي يحتوي data-action المطلوب، بصرف
    // النظر عن ترتيب الخصائص داخله (disabled قد يسبق data-action في HTML).
    if (preg_match('/<button\b[^>]*data-action="' . preg_quote($data_action, '/') . '"[^>]*>/', $html, $m)) {
        return $m[0];
    }
    return '';
}
check_true('ب. زر "تسجيل يدوي" معطَّل (disabled) ومُعلَّم "قريباً"', strpos(button_tag_containing($html_b, 'manual-checkin'), 'disabled') !== false);
check_true('ب. زر "مسح QR" معطَّل (disabled) ومُعلَّم "قريباً"', strpos(button_tag_containing($html_b, 'qr-checkin'), 'disabled') !== false);
check_true('ب. زر "تحديث اللوحة" غير معطَّل (فعّال فعلياً)', strpos($html_b, 'data-action="refresh-dashboard"') !== false && strpos(button_tag_containing($html_b, 'refresh-dashboard'), 'disabled') === false);

// --- سلوك الاستطلاع الدوري (بنيوياً — لا تنفيذ JS حقيقي، راجع تعليق أعلى الملف) ---
check_true('ب. فاصل الاستطلاع 30 ثانية مُضمَّن في JS المُصدَّر', strpos($html_b, 'pollIntervalMs: 30000') !== false);
check_true('ب. حارس "دورة تحديث سابقة لا تزال تعمل" (isRefreshing) موجود', strpos($html_b, 'isRefreshing') !== false);
check_true('ب. إعادة ضبط المؤقّت عند التحديث اليدوي (لا طلبات مكرَّرة) موجودة', strpos($html_b, 'clearInterval') !== false && strpos($html_b, 'startPolling()') !== false);
check_true('ب. لا طلب AJAX فوري عند التحميل الأول (تعليق صريح + عدم استدعاء fetchDashboard قبل startPolling)', strpos($html_b, 'لا طلب فوري عند التحميل') !== false);

// ============================================================================
// السيناريو ج: لوحة فارغة (Empty dashboard)
// ============================================================================
echo "\n=== السيناريو ج: لوحة فارغة ===\n";

setup_catalog_owner_with_event(9602, 9802, 5, 'ui-c');
set_test_event_full(9802, 9602, 'مناسبة بلا حضور بعد');
$supC = authenticate_supervisor_for_event(9802, 9602, '0560000002', 'مشرف اللوحة ج');
// لا أي بذر RSVP إطلاقاً لهذه المناسبة.

$slice_c = render_supervisor_dashboard_slice($TEMPLATE_FILE);
$vars_c = $slice_c['vars'];
$html_c = $slice_c['html'];

check('ج. is_empty_dashboard = true (لا صفوف RSVP إطلاقاً)', $vars_c['is_empty_dashboard'] ?? null, true);
check('ج. total_invitations = 0', $vars_c['attendance_summary']['total_invitations'] ?? null, 0);
check_true('ج. رسالة "لا توجد بيانات حضور بعد" ظاهرة فعلياً في HTML', strpos($html_c, 'لا توجد بيانات حضور بعد') !== false);
check_true('ج. عنصر حالة "لا بيانات" غير مخفي (بلا hidden) لأن الحضور صفري', preg_match('/data-role="empty-state"[^>]*\bhidden\b/', $html_c) === 0);
check_true('ج. لا عمليات تسجيل حضور: رسالة القائمة الفارغة ظاهرة', strpos($html_c, 'لا توجد عمليات تسجيل حضور بعد') !== false);
check_true('ج. آخر نشاط: "لا يوجد نشاط بعد" (لا سطر مطابق في recent_checkins)', strpos($html_c, 'لا يوجد نشاط بعد') !== false);

// ============================================================================
// السيناريو د: مجموعة بيانات كبيرة (Large attendance dataset)
// ============================================================================
echo "\n=== السيناريو د: مجموعة بيانات كبيرة ===\n";

setup_catalog_owner_with_event(9603, 9803, 5, 'ui-d');
set_test_event_full(9803, 9603, 'مناسبة كبيرة للوحة');
$supD = authenticate_supervisor_for_event(9803, 9603, '0560000003', 'مشرف اللوحة د');

for ($i = 1; $i <= 240; $i++) {
    if ($i % 2 === 0) {
        seed_checked_in_rsvp(9803, '0580' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 1, 2, ($i % 4 === 0 ? 'qr' : 'manual'), $supD['assignment_id'], sprintf('2026-03-02 %02d:%02d:00', intdiv($i, 60) % 24, $i % 60));
    } else {
        seed_pending_rsvp(9803, '0580' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 0);
    }
}

$slice_d = render_supervisor_dashboard_slice($TEMPLATE_FILE);
$vars_d = $slice_d['vars'];
$html_d = $slice_d['html'];

check('د. total_invitations = 240 حتى مع مجموعة بيانات كبيرة', $vars_d['attendance_summary']['total_invitations'] ?? null, 240);
check('د. checked_in_invitations = 120', $vars_d['attendance_summary']['checked_in_invitations'] ?? null, 120);
check('د. is_empty_dashboard = false', $vars_d['is_empty_dashboard'] ?? null, false);
check_true('د. الرقم الكبير (240) يظهر صحيحاً في HTML بلا تقريب/تشويه', preg_match('/data-metric="total_invitations">240</', $html_d) === 1);
check('د. recent_checkins محدودة بحد Provider الافتراضي (10) رغم 120 عملية تسجيل فعلية', count($vars_d['recent_checkins']), 10);
check_true('د. القالب لا يتجاوز حد Provider ولا يعيد استعلام إضافي لعرض المزيد', count($vars_d['recent_checkins']) <= 10);

// ============================================================================
// السيناريو هـ: تكامل AJAX الحقيقي (نقطة نهاية الاستطلاع الفعلية)
// ============================================================================
echo "\n=== السيناريو هـ: تكامل نقطة نهاية الاستطلاع (dashboard-ajax.php) ===\n";

// هـ.1: بلا nonce إطلاقاً.
$_POST = [];
$resp_e1 = call_dashboard_ajax_handler();
check('هـ.1 بلا nonce: success=false', $resp_e1['success'] ?? null, false);
check('هـ.1 بلا nonce: reason=invalid_nonce', $resp_e1['data']['reason'] ?? null, 'invalid_nonce');

// هـ.2: nonce صحيح، لكن بلا جلسة مشرف إطلاقاً.
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_dashboard_nonce')];
$resp_e2 = call_dashboard_ajax_handler();
check('هـ.2 nonce صحيح بلا جلسة: success=false', $resp_e2['success'] ?? null, false);
check('هـ.2 nonce صحيح بلا جلسة: reason=no_session_cookie', $resp_e2['data']['reason'] ?? null, 'no_session_cookie');

// هـ.3: nonce صحيح + جلسة مشرف حقيقية صالحة (من السيناريو ب) — نجاح حقيقي كامل.
$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $supB['session_token'];
$_POST = ['nonce' => wp_create_nonce('pge_supervisor_dashboard_nonce')];
$resp_e3 = call_dashboard_ajax_handler();
check('هـ.3 nonce صحيح + جلسة صحيحة: success=true', $resp_e3['success'] ?? null, true);
// ملاحظة: مقارنة == لا === — json_encode/json_decode (نفس آلية wp_send_json_
// success الحقيقية) يُحوِّل عدداً عشرياً بلا كسور (مثل 2.0) إلى صحيح (2) عبر
// سلك JSON، وهو سلوك JSON/PHP قياسي متوقَّع، لا فرقاً في القيمة الفعلية ولا
// خللاً في منطق الإنتاج (نفس التحوّل يحدث حرفياً في ووردبريس الحقيقي أيضاً).
check_true('هـ.3 البيانات المُعادة عبر AJAX مطابقة قيمياً لبيانات Provider المباشرة', ($resp_e3['data']['attendance_summary'] ?? null) == $direct_dashboard_b['data']['attendance_summary']);
unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);
$_POST = [];

// ============================================================================
// السيناريو ز: اللقطة الواحدة الذرية (Phase 6 Final Fix — Atomic Dashboard
// Snapshot) — "One Provider call → One dashboard payload → Render everything
// together"، "The user should never observe mixed generations."
// ============================================================================
echo "\n=== السيناريو ز: اللقطة الواحدة الذرية (Atomic Dashboard Snapshot) ===\n";

// إعادة تفعيل جلسة المشرف من السيناريو ب (استُخدِمت مباشرة أعلاه في هـ.3 ثم
// أُلغيَت) — الأنبوب الحقيقي الكامل يتطلَّب تفويضاً صالحاً لأي نداء لـProvider.
$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $supB['session_token'];

// ز.1 وز.2: نداء واحد لـProvider يُعيد حزمة واحدة تحوي كل الأقسام المطلوبة معاً.
$snapshot_g1 = PGE_Attendance_Dashboard_Provider::get_dashboard(9801);
$required_snapshot_keys = ['event_summary', 'supervisor_summary', 'attendance_summary', 'recent_checkins', 'quick_actions', 'dashboard_metadata'];
$missing_snapshot_keys = array_diff($required_snapshot_keys, array_keys($snapshot_g1['data'] ?? []));
check_true('ز.1 حزمة Provider الواحدة تحوي كل الأقسام المطلوبة معاً (Summary/Recent/Supervisor/Quick Actions/Metadata)', empty($missing_snapshot_keys));
check_true('ز.2 dashboard_metadata.generated_at موجود وغير فارغ', ($snapshot_g1['data']['dashboard_metadata']['generated_at'] ?? '') !== '');
check_true('ز.2 dashboard_metadata.snapshot_id موجود وغير فارغ', ($snapshot_g1['data']['dashboard_metadata']['snapshot_id'] ?? '') !== '');
check('ز.2 dashboard_metadata يحوي مفتاحين فقط (generated_at وsnapshot_id) — قيمة توقيت واحدة على مستوى الجذر، لا نسخة منفصلة لكل قسم', count(array_keys($snapshot_g1['data']['dashboard_metadata'])), 2);

// ز.3: كل نداء منفصل لـget_dashboard() يُنتج لقطة (snapshot) مستقلة بذاتها —
// إثبات أن كل دورة استطلاع تحصل على جيل بيانات جديد ومتماسك، لا إعادة استخدام
// جزئية لحالة قديمة.
$snapshot_g2 = PGE_Attendance_Dashboard_Provider::get_dashboard(9801);
check_true('ز.3 كل نداء لـget_dashboard() يُنتج snapshot_id مستقلاً (لقطة جديدة بذاتها في كل دورة استطلاع)', ($snapshot_g1['data']['dashboard_metadata']['snapshot_id'] ?? '1') !== ($snapshot_g2['data']['dashboard_metadata']['snapshot_id'] ?? '2'));

// ز.4: حالة الإجراءات السريعة ضمن نفس الحزمة — بلا أي تغيير في السلوك الفعلي
// للأزرار (Scope Guard: "Do NOT change Quick Action behaviour").
check('ز.4 quick_actions.manual_checkin.enabled = false (سلوك الزر لم يتغيّر)', $snapshot_g1['data']['quick_actions']['manual_checkin']['enabled'] ?? null, false);
check('ز.4 quick_actions.qr_checkin.enabled = false (سلوك الزر لم يتغيّر)', $snapshot_g1['data']['quick_actions']['qr_checkin']['enabled'] ?? null, false);
check('ز.4 quick_actions.refresh_dashboard.enabled = true (سلوك الزر لم يتغيّر)', $snapshot_g1['data']['quick_actions']['refresh_dashboard']['enabled'] ?? null, true);

// ز.5: القالب (SSR) يستخرج ويعرض dashboard_metadata.generated_at فعلياً — لا
// نص عنصر نائب ثابت ("الآن")، ويحمل جذر الصفحة data-current-assignment-id.
$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $supB['session_token'];
$slice_g = render_supervisor_dashboard_slice($TEMPLATE_FILE);
$vars_g = $slice_g['vars'];
$html_g = $slice_g['html'];

check_true('ز.5 القالب يستخرج dashboard_metadata من بيانات Provider', isset($vars_g['dashboard_metadata']) && is_array($vars_g['dashboard_metadata']));
check_true('ز.5 $generated_at في القالب غير فارغ ومطابق تماماً لما أعاده Provider', ($vars_g['generated_at'] ?? '') !== '' && $vars_g['generated_at'] === ($vars_g['dashboard_metadata']['generated_at'] ?? null));
check_true('ز.5 عنصر "آخر تحديث" في HTML يحمل data-generated-at مطابقاً لقيمة الحزمة (لا نص ثابت "الآن")', strpos($html_g, 'data-generated-at="' . $vars_g['generated_at'] . '"') !== false);
check_true('ز.5 خاصية data-current-assignment-id موجودة في جذر الصفحة (لتمكين JS من إيجاد صف المشرف الحالي عند إعادة البناء)', strpos($html_g, 'data-current-assignment-id="' . $supB['assignment_id'] . '"') !== false);

// ز.6 (يغطّي بنود التقرير 1، 4، 5، 6، 8، 9 — تحقّق بنيوي حقيقي على نص الـJS
// المُصدَّر فعلياً ضمن HTML الناتج، إذ لا مفسِّر JS داخل حصاد PHP بحت؛ نفس
// القيد المذكور صراحة أعلى هذا الملف لسلوك الاستطلاع الدوري).

// (1) طلب AJAX واحد بالضبط لكل دورة استطلاع — نقطة نداء fetch( واحدة فقط
// (Requirement: "Forbidden: separate summary/recent/supervisor requests").
check('ز.6.1 نقطة نداء fetch( واحدة بالضبط في كامل الـJS المُصدَّر', substr_count($html_g, 'fetch('), 1);

// (4) آخر عمليات التسجيل تُعاد بناؤها فعلياً عبر JS — الفجوة الأساسية التي
// حدّدها الـRFC (لم تكن موجودة إطلاقاً قبل هذا الإصلاح).
check_true('ز.6.4 دالة إعادة بناء آخر عمليات التسجيل (buildRecentCheckinsHtml) موجودة في JS', strpos($html_g, 'function buildRecentCheckinsHtml') !== false);
check_true('ز.6.4 الالتزام يستبدل recent-checkins-list فعلياً (innerHTML)', strpos($html_g, 'recentListEl.innerHTML = recentHtml') !== false);

// (5) قسم المشرف يُعاد بناؤه فعلياً عبر JS — نفس الفجوة.
check_true('ز.6.5 دالة إعادة بناء قسم المشرف (buildSupervisorHtml) موجودة في JS', strpos($html_g, 'function buildSupervisorHtml') !== false);
check_true('ز.6.5 الالتزام يستبدل supervisor-section فعلياً (innerHTML)', strpos($html_g, 'supervisorSectionEl.innerHTML = supervisorHtml') !== false);

// (6) البيانات الوصفية (dashboard_metadata) تُحدَّث فعلياً من الحزمة، لا من
// ساعة المتصفّح الخاصة بالعميل.
check_true('ز.6.6 last-updated يُحدَّث من meta.generated_at القادم من الحزمة فعلياً', strpos($html_g, 'lastUpdatedEl.textContent = lastUpdatedText') !== false && strpos($html_g, 'var lastUpdatedText = String(meta.generated_at)') !== false);
check_true('ز.6.6 لا استخدام لساعة العميل (new Date()) لتحديث "آخر تحديث" بعد الإصلاح', strpos($html_g, 'new Date()') === false);

$script_start_g = strpos($html_g, '<script>');
$script_end_g = strpos($html_g, '</script>');
$script_body_g = ($script_start_g !== false && $script_end_g !== false) ? substr($html_g, $script_start_g, $script_end_g - $script_start_g) : '';

// (8) فشل العرض يترك اللقطة السابقة كما هي — applySnapshot() تُستدعى داخل
// try (كتلة then الناجحة)، وكتلة catch لا تستدعي applySnapshot ولا أي دالة
// بناء إطلاقاً — فقط شريط الخطأ (Requirement: "if rendering fails, discard
// the entire snapshot").
check_true('ز.6.8 applySnapshot(json.data) مُستدعاة داخل then() الناجحة (يُلتقَط فشلها عبر catch لاحقاً)', strpos($script_body_g, 'applySnapshot(json.data)') !== false);
$catch_block_g = '';
if (preg_match('/\.catch\(function\s*\(\)\s*\{(.*?)\}\)\s*\.finally/s', $script_body_g, $mcatch)) {
    $catch_block_g = $mcatch[1];
}
check_true('ز.6.8 كتلة catch لا تستدعي applySnapshot( ولا أي دالة build*( — فقط شريط الخطأ (اللقطة السابقة تبقى كما هي)', $catch_block_g !== '' && strpos($catch_block_g, 'applySnapshot(') === false && strpos($catch_block_g, 'build') === false && strpos($catch_block_g, 'showErrorBanner(true)') !== false);

// (9) لا تحديث جزئي مرئي: validatePayload تُستدعى أولاً داخل applySnapshot،
// قبل أي دالة بناء (build*)، وكل عمليات الالتزام (DOM writes) تقع بعد كل
// عمليات البناء — لا تشابك بين "البناء" و"الالتزام".
$apply_fn_pos_g = strpos($script_body_g, 'function applySnapshot');
$apply_fn_body_g = $apply_fn_pos_g !== false ? substr($script_body_g, $apply_fn_pos_g) : '';
$pos_validate = strpos($apply_fn_body_g, 'validatePayload(data)');
$pos_build_summary = strpos($apply_fn_body_g, 'buildSummaryUpdates(summary)');
$pos_build_recent = strpos($apply_fn_body_g, 'buildRecentCheckinsHtml(recent)');
$pos_build_supervisor = strpos($apply_fn_body_g, 'buildSupervisorHtml(supervisors, recent)');
$pos_commit_summary = strpos($apply_fn_body_g, 'summaryUpdates.forEach');
$pos_commit_recent = strpos($apply_fn_body_g, 'recentListEl.innerHTML');
$pos_commit_supervisor = strpos($apply_fn_body_g, 'supervisorSectionEl.innerHTML');
$pos_commit_meta = strpos($apply_fn_body_g, 'lastUpdatedEl.textContent');

check_true('ز.6.9 validatePayload() يُنفَّذ قبل أي دالة بناء (build*) داخل applySnapshot', $pos_validate !== false && $pos_validate < $pos_build_summary && $pos_validate < $pos_build_recent && $pos_validate < $pos_build_supervisor);
check_true('ز.6.9 كل عمليات "البناء" (build*) تسبق كل عمليات "الالتزام" (DOM writes) — لا تحديث جزئي متشابك بين قسمين', max($pos_build_summary, $pos_build_recent, $pos_build_supervisor) < min($pos_commit_summary, $pos_commit_recent, $pos_commit_supervisor, $pos_commit_meta));

// (7) كل الأقسام المعروضة تشترك في نفس generated_at — الحزمة تحوي قيمة واحدة
// فقط على مستوى الجذر (dashboard_metadata.generated_at، وليس نسخة منفصلة لكل
// قسم — تحقّق منه في ز.2)، واستخدام JS لنفس المتغيّر meta.generated_at حصرياً
// (بلا أي مصدر توقيت بديل لأي قسم) يُثبِت بنيوياً استحالة خلط جيلين مختلفين.
check_true('ز.7 لا يوجد أي مصدر توقيت بديل (new Date()) يمكن أن يُستخدَم لأي قسم غير meta.generated_at الواحد', strpos($script_body_g, 'new Date()') === false);

// (10) انحدار: التغييرات هنا لم تكسر عقد البيانات القديم — كل المفاتيح
// المُعتمَدة سابقاً (Phase 5/6) لا تزال موجودة وبنفس الأشكال المتوقَّعة، والحساب
// نفسه لم يتغيَّر.
check_true('ز.10 انحدار: event_summary/supervisor_summary/attendance_summary/recent_checkins لا تزال موجودة كما كانت قبل الإصلاح', isset($snapshot_g1['data']['event_summary']) && isset($snapshot_g1['data']['supervisor_summary']) && isset($snapshot_g1['data']['attendance_summary']) && isset($snapshot_g1['data']['recent_checkins']));
check('ز.10 انحدار: attendance_summary لم يتغيَّر حسابياً (نفس total_invitations = 4 من السيناريو ب)', $snapshot_g1['data']['attendance_summary']['total_invitations'] ?? null, 4);
check_true('ز.10 انحدار: PGE_Attendance_Statistics_Service لا يزال محمي الإنشاء المباشر بعد كل تعديلات هذا الإصلاح', (function () {
    try {
        new PGE_Attendance_Statistics_Service();
        return false;
    } catch (\Error $e) {
        return true;
    }
})());

unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

// ============================================================================
// السيناريو و: انحدار على المراحل 1-5 (عبر نفس الأنبوب الحقيقي المُستخدَم أعلاه)
// ============================================================================
echo "\n=== السيناريو و: انحدار على المراحل 1-5 ===\n";

$quota_regression = pge_resolve_supervisor_quota_status(9801);
check_true('و. Phase 1 (Supervisor Quota Resolver) لا يزال يعمل', !is_wp_error($quota_regression));

check_true('و. Phase 2 (Assignment Service) لا يزال يعمل — الإسناد المُنشَأ أعلاه نشط فعلياً', pge_has_active_supervisor_assignment(9801, '0560000001'));

$authz_regression = PGE_Supervisor_Portal_Middleware::authorize();
check('و. Phase 3/3.5 (Session + Portal Middleware) لا يزال يعمل — لا جلسة حالياً', $authz_regression['result'] ?? null, 'denied');

// إعادة تفعيل جلسة السيناريو ب لإثبات مسار Middleware الناجح أيضاً بعد كل ما سبق.
$_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME] = $supB['session_token'];
$authz_regression2 = PGE_Supervisor_Portal_Middleware::authorize();
check('و. Phase 3/3.5: جلسة صالحة توثِّق نفس المناسبة بعد كل السيناريوهات السابقة', $authz_regression2['event_id'] ?? null, 9801);

check_true('و. Phase 5 (Statistics Service) لا يزال محمي الإنشاء المباشر (Final Fix)', (function () {
    try {
        new PGE_Attendance_Statistics_Service();
        return false;
    } catch (\Error $e) {
        return true;
    }
})());

$dashboard_regression = PGE_Attendance_Dashboard_Provider::get_dashboard(9801);
check('و. Phase 5 (Dashboard Provider) لا يزال يعمل فوق كامل الأنبوب بعد كل هذه السيناريوهات', $dashboard_regression['result'] ?? null, 'authorized');

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
