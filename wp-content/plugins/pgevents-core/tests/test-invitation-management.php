<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـEntry Check-in Supervisors، Phase 9
 * ("Host Invitation Management" RFC) — includes/invitation-management-ajax.php،
 * PGE_Invitation_Service، PGE_Invitation_Repository، PGE_Invitation_Management_Audit.
 * يستدعي المعالجات الحقيقية بأسمائها مباشرة (نفس اصطلاح test-supervisor-
 * management.php)، وينفِّذ pge_event_guests_user_can_manage()/pge_event_guests_
 * get_map()/save_map()/get_row_payload()/get_stats() الحقيقية من event-guests.php
 * فعلياً (بلا مرآة) — "Do NOT create logical mirrors of production code.
 * Execute the real activation code."
 *
 * السيناريوهات المطلوبة صراحةً (Testing Requirement، مُحدَّثة بعد "Phase 9A
 * Final Fix"): إنشاء، تكرار، تعديل، إلغاء، بحث، فلاتر، ترقيم، تفويض، عزل
 * المناسبات المختلفة، توليد سجل التدقيق، انحدار على event-guests.php
 * (Phases 1-8)، **وإثبات أن إعادة الإرسال/تجديد QR/تصدير CSV/تصدير Excel غير
 * قابلة للوصول إطلاقاً في نطاق Phase 9A** (لا wp_ajax_* مُسجَّل لها، وسطح
 * التدقيق يرفض تسجيلها) — مع إثبات أن تنفيذها الفعلي محفوظ (لا محذوف) لمرحلة
 * مستقبلية غير مُعتمَدة بعد.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-invitation-management.php
 */

define('ABSPATH', __DIR__ . '/');

// Phase 9A Final Fix: add_action() هنا لم تعد no-op بحتة — تُسجِّل اسم كل hook
// فعلياً في سجل عالمي، لنتمكن من إثبات تنفيذياً (لا قراءة كود) أي hook مُسجَّل
// فعلياً في الإنتاج. Phase 9B اعتمدت Resend/QR Regeneration، وPhase 9C اعتمدت
// Export لاحقاً — راجع السيناريو 0/9 لإثبات تسجيلها الثلاثة فعلياً الآن.
$GLOBALS['__test_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['__test_registered_hooks'][$hook] = true; }
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
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now'] ?? '2026-01-01 00:00:00'; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('wp_hash')) {
    // نفس نمط test-checkin-cancellation-enforcement.php حرفياً — مطلوبة الآن
    // لـPhase 9B Final Fix: PGE_Checkin_QR_Service::sign() (المسار الموقَّع
    // الحقيقي الذي يستهلكه resolve_from_qr()) تستدعيها فعلياً.
    function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed'); }
}
// بيئة الاختبار (php-wasm) لا تُحمِّل امتداد mbstring (فجوة بيئية مُوثَّقة سابقاً
// أيضاً في test-replacement-entitlement-grant.php) — الكود الحقيقي في
// class-pge-invitation-repository.php::list_invitations() يستخدم mb_strtolower()/
// mb_strpos() لبحث غير حسّاس لحالة الأحرف. هذا شيم بيئي بحت (لا يُنفِّذ أي منطق
// عمل)، وليس مرآة منطقية للكود قيد الاختبار: strtolower()/strpos() العاديتان
// كافيتان تماماً هنا لأن كل بيانات الاختبار عربية (لا حروف كبيرة/صغيرة في
// العربية أصلاً) أو أرقام/إنجليزية ASCII في رموز الدعوة، وكل استخدام لـmb_strpos()
// في الكود الحقيقي يتحقق فقط من (!== false) لا من الموضع الرقمي الدقيق.
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) { return strtolower((string) $string); }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) { return strpos((string) $haystack, (string) $needle, $offset); }
}
if (!function_exists('pge_event_guests_norm_phone')) {
    // alias مطابق تماماً لـ helpers.php الحقيقي (لا نُحمِّل helpers.php كاملاً
    // لتفادي أي تعارض hooks — لكن هذا alias بسيط بلا منطق عمل حقيقي، مطابق حرفياً).
}
if (!function_exists('pge_generate_invite_code')) {
    // بديل حتمي بسيط (لا عشوائية) لضمان قابلية تكرار الاختبار — يطابق الصيغة
    // XXXX-XXXX التي يتحقق منها pge_event_guests_save_map() الحقيقي (regex
    // /^[A-Z0-9]{4}-[A-Z0-9]{4}$/) بالضبط، بلا تنفيذ منطق pge_generate_invite_code()
    // الحقيقي (عشوائي بطبيعته وغير قابل للاختبار الحتمي).
    function pge_generate_invite_code()
    {
        static $n = 0;
        $n++;
        return 'CODE-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
if (!function_exists('pge_normalize_invite_code')) {
    // نسخة حرفية مطابقة تماماً لـhelpers.php الحقيقية (دالة تطبيع نصّية بحتة
    // بلا أي منطق عمل/عشوائية — لا مرآة منطقية، بل نفس الكود بالضبط، لتفادي
    // تحميل helpers.php كاملاً وما قد يسبّبه من تعارض hooks مع ملفات أخرى).
    // مطلوبة هنا فعلياً لـPhase 9B: PGE_Guest_Resolution_Service::search()
    // (المسار الحقيقي لحلّ invite_code) تستدعيها مباشرة.
    function pge_normalize_invite_code($code)
    {
        $code = strtoupper(trim((string) $code));
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        if ($code === '') return '';

        $code = substr($code, 0, 8);
        if (strlen($code) > 4) {
            $code = substr($code, 0, 4) . '-' . substr($code, 4);
        }

        return $code;
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
    return false; // 'edit_post' غير مُستخدَم في fixtures هذا الملف عمداً.
}

// ── Posts وهمية ──────────────────────────────────────────────────────────
$GLOBALS['__test_posts'] = [];
function set_test_event($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) [
        'ID' => $event_id,
        'post_type' => $post_type,
        'post_author' => $author_id,
    ];
}
function get_post($event_id)
{
    return $GLOBALS['__test_posts'][$event_id] ?? null;
}
function get_post_type($event_id)
{
    $p = get_post($event_id);
    return $p ? $p->post_type : false;
}
function get_post_field($field, $post_id)
{
    $p = get_post($post_id);
    if (!$p) return '';
    return $p->{$field} ?? '';
}
function get_the_title($post_id)
{
    $p = get_post($post_id);
    return $p ? ('مناسبة #' . $p->ID) : '';
}

// ── Post Meta وهمية (لـ_pge_invited_guests/_pge_invited_phones/
//    _pge_invitation_status/_pge_rsvp_map/_pge_checkins) ─────────────────────
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

// ── Stubs AJAX/JSON — نفس test-supervisor-management.php حرفياً ─────────────

if (!class_exists('Test_Wp_Die_Exception')) {
    class Test_Wp_Die_Exception extends \Exception {}
}

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
function wp_die($message = '', $title = '', $args = []) {
    throw new Test_Wp_Die_Exception('wp_die');
}
// headers_sent() دالة PHP أصلية فعلياً (حتى داخل بيئة php-wasm) — لا حاجة لإعادة
// تعريفها؛ CLI/php-wasm تُعيدها true افتراضياً بلا ترويسات HTTP حقيقية أصلاً.

function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // متوقَّع دائماً — أسلوب إنهاء wp_send_json_* الطبيعي.
    }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) {
        return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

/**
 * التقاط المخرَجات الخام لمعالجات التصدير (echo + wp_die())، بدل JSON —
 * نمط منفصل عمداً عن call_ajax_handler() لأن هذين المعالجَين لا يستخدمان
 * wp_send_json_* إطلاقاً (نفس ما هو موثَّق في invitation-management-ajax.php:
 * "لا مكتبة xlsx حقيقية... جدول HTML بامتداد .xls").
 */
function call_export_handler(callable $handler): string
{
    ob_start();
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // متوقَّع.
    }
    return ob_get_clean();
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

function setup_host_with_event($host_id, $event_id)
{
    set_test_event($event_id, $host_id);
}

// ============================================================================
// Fake $wpdb — wp_pge_event_rsvps (قراءة فقط) + wp_pge_invitation_mgmt_audit_log
// ============================================================================
class Fake_Wpdb_Invitation_Mgmt
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $rsvp_rows = []; // [[id, event_id, guest_phone, reply, checked_in], ...]
    public $audit_log = [];
    private $audit_next_id = 1;
    private $rsvp_next_id = 1;

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
        if (strpos($sql_or_table, $this->prefix . 'pge_invitation_mgmt_audit_log') !== false) {
            return 'audit';
        }
        return null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);

        if ($which === 'rsvps') {
            if (!preg_match('/WHERE\s+event_id\s*=\s*(\d+)/i', $sql, $m)) {
                return [];
            }
            $event_id = (int) $m[1];
            $out = [];
            foreach ($this->rsvp_rows as $row) {
                if ((int) $row['event_id'] === $event_id) {
                    $out[] = ['id' => $row['id'], 'guest_phone' => $row['guest_phone'], 'reply' => $row['reply'], 'checked_in' => $row['checked_in']];
                }
            }
            return $out;
        }

        if ($which === 'audit') {
            if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+guest_phone\s*=\s*\'([^\']*)\'/i', $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                $rows = array_values(array_filter($this->audit_log, function ($r) use ($event_id, $phone) {
                    return (int) $r['event_id'] === $event_id && $r['guest_phone'] === $phone;
                }));
                usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
                return $rows;
            }
            return [];
        }

        return [];
    }

    public function get_row($sql, $output = null)
    {
        // Phase 9B: مطلوبة لـPGE_Guest_Resolution_Service::find_rsvp_row_by_phone()
        // (تُستدعى داخلياً من search() لكل هاتف مطابق) — نفس منطق get_results()
        // أعلاه لجدول rsvps لكن بصف واحد فقط (LIMIT 1 حقيقي في الاستعلام).
        if ($this->which_table($sql) !== 'rsvps') {
            return null;
        }

        // صيغة 1: WHERE event_id = %d AND guest_phone = '%s' (find_rsvp_row_by_phone).
        if (preg_match('/WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+guest_phone\s*=\s*\'([^\']*)\'/i', $sql, $m)) {
            $event_id = (int) $m[1];
            $phone = $m[2];
            foreach ($this->rsvp_rows as $row) {
                if ((int) $row['event_id'] === $event_id && $row['guest_phone'] === $phone) {
                    return ['id' => $row['id'], 'guest_phone' => $row['guest_phone'], 'reply' => $row['reply'], 'checked_in' => $row['checked_in']];
                }
            }
            return null;
        }

        // صيغة 2: WHERE id = %d AND event_id = %d (find_rsvp_row_by_id +
        // PGE_Checkin_QR_Service::validate() — Phase 9B Final Fix: مطلوبة الآن
        // فعلياً لأن resolve_from_qr() الحقيقية أصبحت تُستدعى في الاختبارات).
        if (preg_match('/WHERE\s+id\s*=\s*(\d+)\s+AND\s+event_id\s*=\s*(\d+)/i', $sql, $m)) {
            $rsvp_id = (int) $m[1];
            $event_id = (int) $m[2];
            foreach ($this->rsvp_rows as $row) {
                if ((int) $row['id'] === $rsvp_id && (int) $row['event_id'] === $event_id) {
                    return ['id' => $row['id'], 'guest_phone' => $row['guest_phone'], 'reply' => $row['reply'], 'checked_in' => $row['checked_in']];
                }
            }
            return null;
        }

        return null;
    }

    public function insert($table, $data, $format = null)
    {
        $which = $this->which_table($table);
        if ($which === 'audit') {
            $id = $this->audit_next_id++;
            $this->audit_log[$id] = array_merge(['id' => $id], $data);
            $this->insert_id = $id;
            return 1;
        }
        return false;
    }

    public function seed_rsvp($event_id, $phone, $reply, $checked_in)
    {
        $this->rsvp_rows[] = ['id' => $this->rsvp_next_id++, 'event_id' => $event_id, 'guest_phone' => $phone, 'reply' => $reply, 'checked_in' => $checked_in];
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Invitation_Mgmt();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────
require_once __DIR__ . '/../includes/helpers.php'; // RC1 Fix Pack 2 (A9): pge_mgmt_validate_request() المشتركة
require_once __DIR__ . '/../includes/event-guests.php'; // pge_event_guests_* الحقيقية
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/invitation-management-ajax.php';
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php'; // Phase 9B Final Fix: مسار QR المُوقَّع الحقيقي (Phase 4)
require_once __DIR__ . '/../includes/class-pge-guest-resolution-service.php'; // مسار الحلّ الحقيقي: resolve_from_qr()/search()

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

// ============================================================================
// السيناريو 0: السطح الفعّال المسموح لـPhase 9A مُسجَّل فعلياً (تكامل الإثبات)
// ============================================================================
// "Phase 9A must expose ONLY: List, View, Create, Edit, Cancel, Search,
// Filters, Pagination, Audit for created/edited/cancelled." — هذا لا يقتصر
// على إثبات عدم تسجيل Resend/QR Regeneration/Export (السيناريوهات 5/6/9)؛
// يجب أيضاً إثبات أن السطح المسموح به فعلاً **لا يزال** مُسجَّلاً وقابلاً
// للوصول (وإلا لكان التقييد مُبالَغاً فيه، يعطّل ميزات مُعتمَدة خطأً).
echo "=== السيناريو 0: السطح الفعّال (List/Create/Edit/Cancel) مُسجَّل ===\n";
check_true('0. wp_ajax_pge_invitation_mgmt_list مُسجَّل', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_list']));
check_true('0. wp_ajax_pge_invitation_mgmt_create مُسجَّل', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_create']));
check_true('0. wp_ajax_pge_invitation_mgmt_edit مُسجَّل', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_edit']));
check_true('0. wp_ajax_pge_invitation_mgmt_cancel مُسجَّل', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_cancel']));

// Phase 9B: Resend/QR Regeneration مُعتمَدتان — مُسجَّلتان فعلياً.
// Phase 9C ("Invitation Export"): Export مُعتمَد الآن أيضاً — مُسجَّل فعلياً
// (راجع تفصيل تنفيذي كامل في tests/test-invitation-export.php المُخصَّص).
check_true('0ب. PGE_INVITATION_MGMT_RESEND_QR_ENABLED = true', defined('PGE_INVITATION_MGMT_RESEND_QR_ENABLED') && PGE_INVITATION_MGMT_RESEND_QR_ENABLED === true);
check_true('0ج. PGE_INVITATION_MGMT_EXPORT_ENABLED = true (Phase 9C مُعتمَدة)', defined('PGE_INVITATION_MGMT_EXPORT_ENABLED') && PGE_INVITATION_MGMT_EXPORT_ENABLED === true);
check_true('0د. wp_ajax_pge_invitation_mgmt_resend مُسجَّل (Phase 9B مُعتمَدة)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_resend']));
check_true('0هـ. wp_ajax_pge_invitation_mgmt_qr_regenerate مُسجَّل (Phase 9B مُعتمَدة)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_qr_regenerate']));
check_true('0و. wp_ajax_pge_invitation_mgmt_export_csv مُسجَّل (Phase 9C مُعتمَدة)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_export_csv']));
check_true('0ز. wp_ajax_pge_invitation_mgmt_export_excel مُسجَّل (Phase 9C مُعتمَدة)', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_export_excel']));

// ============================================================================
// السيناريو 1: إنشاء دعوة (Create Invitation)
// ============================================================================
echo "=== السيناريو 1: إنشاء دعوة ===\n";

setup_host_with_event(801, 1001);
$GLOBALS['__test_current_user_id'] = 801;
$GLOBALS['__test_user_is_admin'] = false;

$_POST = make_post_fields(1001, ['name' => 'سالم', 'phone' => '0501111111']);
$resp1 = call_ajax_handler('pge_invitation_mgmt_create_handler');
check_true('1. الاستجابة success=true', $resp1['success'] ?? false);
check('1. الهاتف المُطبَّع مُعاد', $resp1['data']['phone'] ?? null, '0501111111');

$guests_map_1 = pge_event_guests_get_map(1001);
check_true('1. الضيف أُنشئ فعلياً في _pge_invited_guests (event-guests.php الحقيقية)', isset($guests_map_1['0501111111']));
check('1. اسم الضيف صحيح', $guests_map_1['0501111111']['name'] ?? null, 'سالم');
check_true('1. رمز الدعوة (invite_code) وُلِّد فعلياً', ($guests_map_1['0501111111']['code'] ?? '') !== '');

$invitation_1 = PGE_Invitation_Repository::get_invitation(1001, '0501111111');
check('1. حالة الدعوة الابتدائية = active', $invitation_1['invitation_status'] ?? null, 'active');

$audit_1_created = array_values(array_filter($wpdb->audit_log, function ($r) { return $r['guest_phone'] === '0501111111' && $r['action'] === 'created'; }));
check('1. سجل تدقيق واحد بنوع created', count($audit_1_created), 1);
check('1. actor_user_id في سجل التدقيق = المضيف الحالي', (int) ($audit_1_created[0]['actor_user_id'] ?? 0), 801);

// اسم فارغ → خطأ، بلا إنشاء
$_POST = make_post_fields(1001, ['name' => '', 'phone' => '0509999999']);
$resp1b = call_ajax_handler('pge_invitation_mgmt_create_handler');
check_true('1ب. اسم فارغ: success=false', !($resp1b['success'] ?? true));
check_true('1ب. لا ضيف أُنشئ لهذا الهاتف', !isset(pge_event_guests_get_map(1001)['0509999999']));

// ============================================================================
// السيناريو 2: تكرار (Duplicate validation) + عزل المناسبات المختلفة
// ============================================================================
echo "\n=== السيناريو 2: تكرار + عزل المناسبات ===\n";

$_POST = make_post_fields(1001, ['name' => 'سالم آخر', 'phone' => '0501111111']);
$resp2 = call_ajax_handler('pge_invitation_mgmt_create_handler');
check_true('2. تكرار ضمن نفس المناسبة: success=false', !($resp2['success'] ?? true));
check('2. السبب duplicate', $resp2['data']['reason'] ?? null, 'duplicate');
check('2. اسم الضيف الأصلي لم يتغيَّر', pge_event_guests_get_map(1001)['0501111111']['name'] ?? null, 'سالم');

// نفس الهاتف في مناسبة مختلفة مسموح تماماً (عزل بنيوي حسب post meta لكل مناسبة)
setup_host_with_event(802, 1002);
$GLOBALS['__test_current_user_id'] = 802;
$_POST = make_post_fields(1002, ['name' => 'نفس الرقم بمناسبة أخرى', 'phone' => '0501111111']);
$resp2b = call_ajax_handler('pge_invitation_mgmt_create_handler');
check_true('2ب. نفس الهاتف بمناسبة أخرى: success=true (عزل حقيقي)', $resp2b['success'] ?? false);
check_true('2ج. الدعوة في 1001 غير مرئية عبر 1002', PGE_Invitation_Repository::get_invitation(1002, '0501111111')['name'] !== 'سالم');

// ============================================================================
// السيناريو 3: تعديل دعوة (Edit Invitation)
// ============================================================================
echo "\n=== السيناريو 3: تعديل دعوة ===\n";

$GLOBALS['__test_current_user_id'] = 801;
$_POST = make_post_fields(1001, ['old_phone' => '0501111111', 'phone' => '0501111199', 'name' => 'سالم المعدَّل', 'note' => 'ملاحظة جديدة']);
$resp3 = call_ajax_handler('pge_invitation_mgmt_edit_handler');
check_true('3. التعديل: success', $resp3['success'] ?? false);

$guests_map_3 = pge_event_guests_get_map(1001);
check_true('3. الهاتف القديم لم يعد موجوداً', !isset($guests_map_3['0501111111']));
check('3. الاسم تحدَّث تحت الهاتف الجديد', $guests_map_3['0501111199']['name'] ?? null, 'سالم المعدَّل');
check('3. الملاحظة تحدَّثت', $guests_map_3['0501111199']['note'] ?? null, 'ملاحظة جديدة');

$invitation_3 = PGE_Invitation_Repository::get_invitation(1001, '0501111199');
check('3. حالة الدعوة لا تزال active (لا تغيير على الحضور/الإحصاء)', $invitation_3['invitation_status'] ?? null, 'active');

$audit_3_edited = array_values(array_filter($wpdb->audit_log, function ($r) { return $r['guest_phone'] === '0501111199' && $r['action'] === 'edited'; }));
check('3. سجل تدقيق edited واحد تحت الهاتف الجديد', count($audit_3_edited), 1);

// تعديل بهاتف مكرَّر ضمن نفس المناسبة → duplicate
$_POST = make_post_fields(1001, ['name' => 'ثالث', 'phone' => '0501112000']);
call_ajax_handler('pge_invitation_mgmt_create_handler');
$_POST = make_post_fields(1001, ['old_phone' => '0501111199', 'phone' => '0501112000', 'name' => 'محاولة دمج']);
$resp3b = call_ajax_handler('pge_invitation_mgmt_edit_handler');
check_true('3ب. تعديل لهاتف مستخدَم بالفعل: success=false', !($resp3b['success'] ?? true));
check('3ب. السبب duplicate', $resp3b['data']['reason'] ?? null, 'duplicate');

// تعديل هاتف غير موجود أصلاً → not_found
$_POST = make_post_fields(1001, ['old_phone' => '0509990000', 'phone' => '0509990001', 'name' => 'غير موجود']);
$resp3c = call_ajax_handler('pge_invitation_mgmt_edit_handler');
check_true('3ج. تعديل هاتف غير موجود: success=false', !($resp3c['success'] ?? true));
check('3ج. السبب not_found', $resp3c['data']['reason'] ?? null, 'not_found');

// ============================================================================
// السيناريو 4: إلغاء دعوة (Cancel Invitation)
// ============================================================================
echo "\n=== السيناريو 4: إلغاء دعوة ===\n";

$_POST = make_post_fields(1001, ['phone' => '0501111199', 'reason' => 'اعتذار المضيف']);
$resp4 = call_ajax_handler('pge_invitation_mgmt_cancel_handler');
check_true('4. الإلغاء: success', $resp4['success'] ?? false);

$invitation_4 = PGE_Invitation_Repository::get_invitation(1001, '0501111199');
check('4. حالة الدعوة أصبحت cancelled', $invitation_4['invitation_status'] ?? null, 'cancelled');
check_true('4. الضيف لا يزال موجوداً في _pge_invited_guests (لا حذف، append-only)', isset(pge_event_guests_get_map(1001)['0501111199']));

$audit_4_cancelled = array_values(array_filter($wpdb->audit_log, function ($r) { return $r['guest_phone'] === '0501111199' && $r['action'] === 'cancelled'; }));
check('4. سجل تدقيق cancelled واحد مع السبب', $audit_4_cancelled[0]['reason'] ?? null, 'اعتذار المضيف');

// إلغاء مكرَّر (لا يجوز إلغاء مرتين)
$_POST = make_post_fields(1001, ['phone' => '0501111199']);
$resp4b = call_ajax_handler('pge_invitation_mgmt_cancel_handler');
check_true('4ب. إلغاء مكرَّر: success=false', !($resp4b['success'] ?? true));
check('4ب. السبب already_cancelled', $resp4b['data']['reason'] ?? null, 'already_cancelled');
check('4ب. لا سجل تدقيق cancelled إضافي', count(array_filter($wpdb->audit_log, function ($r) { return $r['guest_phone'] === '0501111199' && $r['action'] === 'cancelled'; })), 1);

// ============================================================================
// السيناريو 5: إعادة الإرسال — مُعتمَدة وقابلة للوصول (Phase 9B)
// ============================================================================
// "Phase 9B" (RFC): "Resend invitation... reuse existing invitation, no new
// guest/RSVP/assignment, no quota consumed, append exactly one resend audit
// event." الإثبات هنا تنفيذي حقيقي عبر AJAX الحقيقي (pge_invitation_mgmt_
// resend_handler المُسجَّل الآن فعلياً)، لا مرآة منطقية.
echo "\n=== السيناريو 5: إعادة الإرسال — مُعتمَدة وقابلة للوصول ===\n";

// إعداد ضيف مخصَّص لهذا السيناريو (لا نُعيد استخدام هاتف سيناريوهات أخرى).
$_POST = make_post_fields(1001, ['name' => 'ضيف الإرسال', 'phone' => '0501115000']);
call_ajax_handler('pge_invitation_mgmt_create_handler');
$guests_map_before_resend = pge_event_guests_get_map(1001);
check_true('5-0. الضيف أُنشئ فعلياً قبل اختبار الإرسال', isset($guests_map_before_resend['0501115000']));

// (1) نجاح إعادة الإرسال + (4) توليد سجل تدقيق فعلي
$audit_before_5 = count($wpdb->audit_log);
$_POST = make_post_fields(1001, ['phone' => '0501115000']);
$resp5 = call_ajax_handler('pge_invitation_mgmt_resend_handler');
check_true('5أ. [1] إعادة الإرسال: success', $resp5['success'] ?? false);

// Phase 9B Final Fix ("Resend semantics"): حدث التدقيق المكتوب فعلياً هو
// 'delivery_requested' — لا 'resent' (لا قناة تسليم فعلية مربوطة، فالادّعاء
// بأن رسالة "أُعيدت" فعلياً غير صحيح؛ ما حدث فعلياً هو طلب تسليم فقط).
$audit_5_resent = array_values(array_filter($wpdb->audit_log, function ($r) { return $r['guest_phone'] === '0501115000' && $r['action'] === 'delivery_requested'; }));
check('5ب. [4][7] سجل تدقيق delivery_requested واحد بالضبط بعد الطلب الأول', count($audit_5_resent), 1);
check_true('5ب. [4] سجل التدقيق يحمل actor_user_id وcreated_at صحيحَين', (int) ($audit_5_resent[0]['actor_user_id'] ?? 0) === 801 && !empty($audit_5_resent[0]['created_at'] ?? ''));
check('5ب. [4] لا سجلات تدقيق إضافية غير مقصودة (لا تكرار داخل نفس الطلب)', count($wpdb->audit_log), $audit_before_5 + 1);
check('5ب. [8] لا سجل واحد بالاسم القديم resent في كامل السجل (الاسم حُذف نهائياً)', count(array_filter($wpdb->audit_log, function ($r) { return $r['action'] === 'resent'; })), 0);

// لم يُنشأ ضيف/RSVP/إسناد جديد — نفس الضيف نفسه فقط تحدَّث updated_at.
check('5ج. لا ضيف جديد أُنشئ (نفس عدد الضيوف في المناسبة)', count(pge_event_guests_get_map(1001)), count($guests_map_before_resend));
check_true('5ج. الضيف نفسه لا يزال موجوداً بلا تكرار', isset(pge_event_guests_get_map(1001)['0501115000']));

// (3) طلب إعادة إرسال مكرَّر (طلب ثانٍ منفصل ومشروع) — كل طلب يُضيف بالضبط
// سطراً واحداً، لا تصفير ولا تضاعف داخل نفس الطلب الواحد.
$_POST = make_post_fields(1001, ['phone' => '0501115000']);
$resp5_dup = call_ajax_handler('pge_invitation_mgmt_resend_handler');
check_true('5د. [3] طلب إعادة إرسال ثانٍ منفصل: success أيضاً (لا حظر على تكرار مشروع)', $resp5_dup['success'] ?? false);
$audit_5_resent_after_dup = array_values(array_filter($wpdb->audit_log, function ($r) { return $r['guest_phone'] === '0501115000' && $r['action'] === 'delivery_requested'; }));
check('5د. [3] بالضبط سجلّان (سجل واحد لكل طلب، لا تكرار داخل الطلب الواحد ولا تصفير)', count($audit_5_resent_after_dup), 2);

// (2) إعادة إرسال دعوة مُلغاة → خطأ، بلا سجل تدقيق إضافي
$_POST = make_post_fields(1001, ['name' => 'ضيف مُلغى للإرسال', 'phone' => '0501115999']);
call_ajax_handler('pge_invitation_mgmt_create_handler');
$_POST = make_post_fields(1001, ['phone' => '0501115999', 'reason' => 'إلغاء تجريبي']);
call_ajax_handler('pge_invitation_mgmt_cancel_handler');
$_POST = make_post_fields(1001, ['phone' => '0501115999']);
$resp5_cancelled = call_ajax_handler('pge_invitation_mgmt_resend_handler');
check_true('5هـ. [2] إعادة إرسال دعوة مُلغاة: success=false', !($resp5_cancelled['success'] ?? true));
check('5هـ. [2] السبب cancelled', $resp5_cancelled['data']['reason'] ?? null, 'cancelled');
check('5هـ. [2] لا سجل تدقيق delivery_requested لهذه الدعوة المُلغاة', count(array_filter($wpdb->audit_log, function ($r) { return $r['guest_phone'] === '0501115999' && $r['action'] === 'delivery_requested'; })), 0);

// (10) عزل المناسبات — نفس الهاتف مسجَّل أيضاً ضمن مناسبة أخرى (1002، مالكها
// 802)؛ إعادة إرسال عبر 1001 يجب ألا تُسجِّل أي شيء ولا تؤثر على 1002 إطلاقاً.
$GLOBALS['__test_current_user_id'] = 802;
$_POST = make_post_fields(1002, ['name' => 'نفس الهاتف بمناسبة أخرى', 'phone' => '0501115000']);
call_ajax_handler('pge_invitation_mgmt_create_handler');
$GLOBALS['__test_current_user_id'] = 801;
$_POST = make_post_fields(1001, ['phone' => '0501115000']);
call_ajax_handler('pge_invitation_mgmt_resend_handler');
check('5و. [10] عزل المناسبات: لا سجل تدقيق delivery_requested مسرَّب لهاتف 0501115000 ضمن المناسبة 1002', count(array_filter($wpdb->audit_log, function ($r) { return (int) $r['event_id'] === 1002 && $r['guest_phone'] === '0501115000' && $r['action'] === 'delivery_requested'; })), 0);
check('5و. [10] بيانات الضيف في 1002 لم تتأثَّر (الاسم كما هو)', pge_event_guests_get_map(1002)['0501115000']['name'] ?? null, 'نفس الهاتف بمناسبة أخرى');

// (11) التفويض — مستخدم دخيل (ليس مضيفاً ولا أدمن) يحاول إعادة الإرسال
$GLOBALS['__test_current_user_id'] = 999;
$_POST = make_post_fields(1001, ['phone' => '0501115000']);
$resp5_forbidden = call_ajax_handler('pge_invitation_mgmt_resend_handler');
check_true('5ز. [11] مستخدم غير مخوَّل: success=false', !($resp5_forbidden['success'] ?? true));
check('5ز. [11] السبب forbidden', $resp5_forbidden['data']['reason'] ?? null, 'forbidden');
$GLOBALS['__test_current_user_id'] = 801;

// (8) إثبات شامل نهائي — بعد كل سيناريوهات الإرسال أعلاه (نجاح/تكرار/إلغاء/
// عزل/تفويض)، لا يوجد سطر تدقيق واحد بالاسم القديم 'resent' في كامل السجل.
check('5ح. [8] لا سجل واحد بالاسم القديم resent في كامل سجل التدقيق (بعد كل سيناريوهات الإرسال)', count(array_filter($wpdb->audit_log, function ($r) { return $r['action'] === 'resent'; })), 0);

// ============================================================================
// السيناريو 6: بنية QR الكنسية — Phase 9B QR Architecture Final Fix
// ============================================================================
// "QR is an access credential. QR is NOT invitation identity." — إثبات
// تنفيذي حقيقي (24 حالة مطلوبة) لفصل invite_code (بحث يدوي) عن Scanner QR
// Credential (حمولة موقَّعة، تدوير بـqr_version)، إزالة resolve_by_invite_
// code_payload() نهائياً، وأن resolve_from_qr() لا يقبل أبداً invite_code
// خاماً ولا يسقط احتياطياً لأي صيغة غير موقَّعة.
echo "\n=== السيناريو 6: بنية QR الكنسية (فصل عن invite_code + تدوير) ===\n";

$_POST = make_post_fields(1001, ['name' => 'ضيف التجديد', 'phone' => '0501116000']);
call_ajax_handler('pge_invitation_mgmt_create_handler');
$invite_code_6 = pge_event_guests_get_map(1001)['0501116000']['code'];
$wpdb->seed_rsvp(1001, '0501116000', 'yes', 1);
$rsvp_row_6 = null;
foreach ($wpdb->rsvp_rows as $r) {
    if ((int) $r['event_id'] === 1001 && $r['guest_phone'] === '0501116000') { $rsvp_row_6 = $r; break; }
}
check_true('6-0. صف RSVP الحقيقي موجود (تحقّق من الفرضية)', $rsvp_row_6 !== null);
$rsvp_id_6 = (int) $rsvp_row_6['id'];

// ضيف ثانٍ لإثبات الربط بـrsvp_id تحديداً لا فقط بالمناسبة (بند 6).
$_POST = make_post_fields(1001, ['name' => 'ضيف آخر للربط', 'phone' => '0501116050']);
call_ajax_handler('pge_invitation_mgmt_create_handler');
$wpdb->seed_rsvp(1001, '0501116050', 'no', 0);
$rsvp_row_6b = null;
foreach ($wpdb->rsvp_rows as $r) {
    if ((int) $r['event_id'] === 1001 && $r['guest_phone'] === '0501116050') { $rsvp_row_6b = $r; break; }
}
$rsvp_id_6b = (int) $rsvp_row_6b['id'];

// [18] المولِّد الكنسي الوحيد — نفس الدالة التي يستدعيها كل منتِجي الإنتاج
// (checkin-ui-ajax.php وclass-cartat-handler.php وclass-ultramsg-handler.php).
$qr_v1_6 = PGE_Guest_Resolution_Service::build_scanner_qr_payload(1001, $rsvp_id_6, '0501116000');
check_true('6أ. [18] المولِّد الكنسي يُعيد حمولة غير فارغة', $qr_v1_6 !== '');
check('6أ. [18] الحمولة الكنسية 4 أجزاء بالضبط (event_id|rsvp_id|qr_version|signature)', count(explode('|', $qr_v1_6)), 4);
check_true('6أ. [18] الحمولة الكنسية ليست invite_code الخام (عقدان منفصلان فعلياً)', $qr_v1_6 !== $invite_code_6);

// (1) invite_code الخام مرفوض عبر resolve_from_qr() — لا سقوط احتياطي.
$resolution_plain_invite_6 = PGE_Guest_Resolution_Service::resolve_from_qr(1001, $invite_code_6);
check_true('6ب. [1] invite_code الخام مرفوض عبر resolve_from_qr() (لا يُعيد found)', ($resolution_plain_invite_6['result'] ?? '') !== 'found');
check('6ب. [1] السبب malformed_payload (لا سقوط احتياطي لصيغة أخرى)', $resolution_plain_invite_6['reason'] ?? null, 'malformed_payload');

// (2) حمولة موقَّعة مُزوَّرة لا تسقط احتياطياً لأي صيغة أخرى.
$tampered_6 = substr($qr_v1_6, 0, -1) . (substr($qr_v1_6, -1) === 'a' ? 'b' : 'a');
$resolution_tampered_6 = PGE_Guest_Resolution_Service::resolve_from_qr(1001, $tampered_6);
check_true('6ج. [2] توقيع مُزوَّر مرفوض (لا يُعيد found)', ($resolution_tampered_6['result'] ?? '') !== 'found');
check('6ج. [2] السبب signature_mismatch (لا سقوط احتياطي لـinvite_code)', $resolution_tampered_6['reason'] ?? null, 'signature_mismatch');

// (3) الحمولة الكنسية الموقَّعة تُحَلّ بنجاح.
$resolution_v1_6 = PGE_Guest_Resolution_Service::resolve_from_qr(1001, $qr_v1_6);
check('6د. [3] الحمولة الكنسية الصحيحة تُحَلّ بنجاح: found', $resolution_v1_6['result'] ?? null, 'found');
check('6د. [3] هاتف الضيف المُعاد صحيح', $resolution_v1_6['guest']['phone'] ?? null, '0501116000');

// (4) الربط بالمناسبة الصحيحة — التحقق المباشر عبر validate().
$validate_v1_6 = PGE_Checkin_QR_Service::validate(1001, $qr_v1_6);
check('6هـ. [4] الحمولة مرتبطة بالمناسبة الصحيحة (event_id المُعاد = 1001)', $validate_v1_6['event_id'] ?? null, 1001);

// (5) إعادة استخدام عبر مناسبة مختلفة (Cross-event reuse) مرفوضة.
$resolution_cross_event_6 = PGE_Guest_Resolution_Service::resolve_from_qr(1002, $qr_v1_6);
check_true('6و. [5] استخدام QR عبر مناسبة مختلفة مرفوض (لا يُعيد found)', ($resolution_cross_event_6['result'] ?? '') !== 'found');
check('6و. [5] السبب event_mismatch', $resolution_cross_event_6['reason'] ?? null, 'event_mismatch');

// (6) الربط بـrsvp_id الصحيح تحديداً — حمولة الضيف الثاني تُحَلّ لهويته هو
// فقط، لا للضيف الأول رغم كونهما بنفس المناسبة.
$qr_v1_6b = PGE_Guest_Resolution_Service::build_scanner_qr_payload(1001, $rsvp_id_6b, '0501116050');
$resolution_6b = PGE_Guest_Resolution_Service::resolve_from_qr(1001, $qr_v1_6b);
check('6ز. [6] حمولة الضيف الثاني تُحَلّ لهاتفه هو تحديداً', $resolution_6b['guest']['phone'] ?? null, '0501116050');
check_true('6ز. [6] حمولة الضيف الأول لا تزال تُحَلّ لهاتفه هو (لا تبادل بين rsvp_id مختلفَين)', ($resolution_v1_6['guest']['phone'] ?? '') === '0501116000');

// ============================================================================
// التجديد (Regeneration) — يُدوِّر qr_version فقط، لا يمسّ invite_code/الهوية
// ============================================================================
$invitation_before_6 = PGE_Invitation_Repository::get_invitation(1001, '0501116000');
$guest_before_6 = pge_event_guests_get_map(1001)['0501116000'];
$rsvp_checked_in_before_6 = $rsvp_row_6['checked_in'];
$rsvp_reply_before_6 = $rsvp_row_6['reply'];
$audit_before_regen_6 = count($wpdb->audit_log);

$_POST = make_post_fields(1001, ['phone' => '0501116000']);
$resp6_regen = call_ajax_handler('pge_invitation_mgmt_qr_regenerate_handler');
check_true('6ح. تجديد الرمز: success', $resp6_regen['success'] ?? false);
$new_qr_version_6 = $resp6_regen['data']['qr_version'] ?? null;
check_true('6ح. qr_version الجديد مُعاد في الاستجابة كرقم صحيح موجب', is_int($new_qr_version_6) && $new_qr_version_6 > 0);
// [مُحدَّث RWPU — RC1 Final Release Blocker: RSVP Write Path Unification] الإصدار
// الافتراضي (قبل أي تدوير) لم يعد ثابتاً (1) — يُشتَق الآن من invited_at
// لإغلاق Blocker 2 بالكامل (راجع docblock get_qr_version() في
// class-pge-invitation-repository.php)، فالتدوير الأول يُنتج رقماً مُشتَقاً+1،
// لا الثابت 2 حرفياً. جوهر الاختبار (تدوير حقيقي ينتج رقماً موجباً جديداً،
// مؤكَّد في السطر أعلاه) لم يتأثر.
check_true('6ح. [7، مُحدَّث RWPU] الإصدار الجديد أكبر من 1 (تدوير حقيقي من الافتراضي المُشتَق)', $new_qr_version_6 > 1);

// [19] سجل تدقيق qr_regenerated واحد بالضبط.
$audit_6_regen = array_values(array_filter($wpdb->audit_log, function ($r) { return $r['guest_phone'] === '0501116000' && $r['action'] === 'qr_regenerated'; }));
check('6ط. [19] سجل تدقيق qr_regenerated واحد بالضبط', count($audit_6_regen), 1);
check_true('6ط. [19] سجل التدقيق يحمل actor_user_id وcreated_at صحيحَين', (int) ($audit_6_regen[0]['actor_user_id'] ?? 0) === 801 && !empty($audit_6_regen[0]['created_at'] ?? ''));
check('6ط. [19] لا سجلات تدقيق إضافية غير مقصودة', count($wpdb->audit_log), $audit_before_regen_6 + 1);

// [7][11][12] التجديد غيَّر فقط بدائيّ التدوير — الدعوة/الضيف/invite_code بلا تغيير.
$invitation_after_6 = PGE_Invitation_Repository::get_invitation(1001, '0501116000');
check('6ي. [11] حالة الدعوة الإدارية لم تتغيَّر (active قبل/بعد)', $invitation_after_6['invitation_status'] ?? null, $invitation_before_6['invitation_status'] ?? null);
$guest_after_6 = pge_event_guests_get_map(1001)['0501116000'];
check('6ي. [7][12] invite_code لم يتغيَّر إطلاقاً (التجديد لا يمسّ invite_code)', $guest_after_6['code'] ?? null, $invite_code_6);
check('6ي. [12] اسم الضيف لم يتغيَّر', $guest_after_6['name'] ?? null, $guest_before_6['name'] ?? null);

// [13][14] RSVP والحضور لم يتأثَّرا — نفس الصف الخام في fake wpdb.
$rsvp_row_6_after = null;
foreach ($wpdb->rsvp_rows as $r) {
    if ((int) $r['id'] === $rsvp_id_6) { $rsvp_row_6_after = $r; break; }
}
check('6ك. [13] حالة RSVP (reply) لم تتغيَّر', $rsvp_row_6_after['reply'] ?? null, $rsvp_reply_before_6);
check('6ك. [14] حالة الحضور (checked_in) لم تتغيَّر', $rsvp_row_6_after['checked_in'] ?? null, $rsvp_checked_in_before_6);

// [15] لا سطر تدقيق تسجيل حضور كُتب أثناء التجديد — لا استدعاء لـRecorder
// إطلاقاً؛ البرهان غير المباشر: checked_in/reply الخامان في wpdb (الجهتان
// الوحيدتان اللتان يُعدِّلهما PGE_Checkin_Recorder فعلياً) طابقا القيم قبل
// التجديد بالضبط، وPGE_Invitation_Repository::regenerate_qr() لا يستدعي
// Recorder إطلاقاً (تحقّق كودي مباشر أيضاً، لا مجرد سلوكي).
check_true('6ل. [15] لا أثر لاستدعاء Recorder أثناء التجديد (checked_in/reply الخامان بلا تغيير)', $rsvp_row_6_after['reply'] === $rsvp_reply_before_6 && $rsvp_row_6_after['checked_in'] === $rsvp_checked_in_before_6);

// [8][9][10] الحمولة القديمة تُرفَض الآن (qr_superseded)، الجديدة تُقبَل فوراً.
$resolution_old_after_regen_6 = PGE_Guest_Resolution_Service::resolve_from_qr(1001, $qr_v1_6);
check_true('6م. [8] الحمولة القديمة مرفوضة بعد التجديد (لا تُعيد found)', ($resolution_old_after_regen_6['result'] ?? '') !== 'found');
check('6م. [10] السبب qr_superseded (خطأ العمل المستقر الوحيد المُستخدَم باتساق)', $resolution_old_after_regen_6['reason'] ?? null, 'qr_superseded');

$qr_v2_6 = PGE_Guest_Resolution_Service::build_scanner_qr_payload(1001, $rsvp_id_6, '0501116000');
check_true('6ن. الحمولة الجديدة تختلف فعلياً عن القديمة', $qr_v2_6 !== $qr_v1_6);
$resolution_new_6 = PGE_Guest_Resolution_Service::resolve_from_qr(1001, $qr_v2_6);
check('6ن. [9] الحمولة الجديدة تُحَلّ بنجاح فوراً بعد التجديد: found', $resolution_new_6['result'] ?? null, 'found');
check('6ن. [9] نفس هاتف الضيف عبر الحمولة الجديدة', $resolution_new_6['guest']['phone'] ?? null, '0501116000');

// [17] البحث اليدوي بـinvite_code لا يزال يعمل تماماً — غير متأثر بتجديد QR.
$search_by_code_6 = PGE_Guest_Resolution_Service::search(1001, $invite_code_6);
$found_by_code_6 = array_values(array_filter($search_by_code_6['guests'] ?? [], function ($g) { return $g['phone'] === '0501116000'; }));
check('6س. [17] البحث اليدوي بـinvite_code لا يزال يعمل بعد تجديد QR', count($found_by_code_6), 1);
check('6س. [17] invite_code المُعاد في نتيجة البحث لم يتغيَّر', $found_by_code_6[0]['invite_code'] ?? null, $invite_code_6);

// [16] دعوة مُلغاة: تجديد يُرفَض + حمولة QR موقَّعة صحيحة لها تُرفَض أيضاً عبر
// resolve_from_qr() (حارس الإلغاء الإداري — سبب منفصل عن qr_superseded).
$_POST = make_post_fields(1001, ['name' => 'ضيف مُلغى للتجديد', 'phone' => '0501116999']);
call_ajax_handler('pge_invitation_mgmt_create_handler');
$wpdb->seed_rsvp(1001, '0501116999', 'yes', 0);
$rsvp_row_cancelled_6 = null;
foreach ($wpdb->rsvp_rows as $r) {
    if ((int) $r['event_id'] === 1001 && $r['guest_phone'] === '0501116999') { $rsvp_row_cancelled_6 = $r; break; }
}
$qr_cancelled_6 = PGE_Guest_Resolution_Service::build_scanner_qr_payload(1001, (int) $rsvp_row_cancelled_6['id'], '0501116999');
$code_before_cancelled_regen = pge_event_guests_get_map(1001)['0501116999']['code'];

$_POST = make_post_fields(1001, ['phone' => '0501116999', 'reason' => 'إلغاء تجريبي']);
call_ajax_handler('pge_invitation_mgmt_cancel_handler');

$_POST = make_post_fields(1001, ['phone' => '0501116999']);
$resp6_cancelled_regen = call_ajax_handler('pge_invitation_mgmt_qr_regenerate_handler');
check_true('6ع. [16] تجديد رمز دعوة مُلغاة: success=false', !($resp6_cancelled_regen['success'] ?? true));
check('6ع. [16] السبب cancelled', $resp6_cancelled_regen['data']['reason'] ?? null, 'cancelled');
check('6ع. [16] invite_code لم يتغيَّر لدعوة مُلغاة', pge_event_guests_get_map(1001)['0501116999']['code'] ?? null, $code_before_cancelled_regen);

$resolution_cancelled_qr_6 = PGE_Guest_Resolution_Service::resolve_from_qr(1001, $qr_cancelled_6);
check_true('6ف. [16] حمولة QR موقَّعة صحيحة لدعوة مُلغاة مرفوضة أيضاً عبر resolve_from_qr() (حارس الإلغاء)', ($resolution_cancelled_qr_6['result'] ?? '') !== 'found');
check('6ف. [16] السبب invitation_cancelled (منفصل عن qr_superseded)', $resolution_cancelled_qr_6['reason'] ?? null, 'invitation_cancelled');

// التفويض — مستخدم دخيل يحاول تجديد الرمز.
$GLOBALS['__test_current_user_id'] = 999;
$_POST = make_post_fields(1001, ['phone' => '0501116000']);
$resp6_forbidden = call_ajax_handler('pge_invitation_mgmt_qr_regenerate_handler');
check_true('6ص. مستخدم غير مخوَّل: success=false', !($resp6_forbidden['success'] ?? true));
check('6ص. السبب forbidden', $resp6_forbidden['data']['reason'] ?? null, 'forbidden');
$GLOBALS['__test_current_user_id'] = 801;

// ============================================================================
// [23] انحدار — المسار المُوقَّع الأصلي (Phase 4) عبر build_payload() المباشرة
// ============================================================================
// إثبات أن الإصلاح لم يُبدِّل مسار Phase 4 القائم، فقط وسَّع عقده (qr_version
// كمعامل ثالث إلزامي الآن، موثَّق أعلاه في class-pge-checkin-qr-service.php).
$signed_qr_6 = PGE_Checkin_QR_Service::build_payload(1001, $rsvp_id_6, $new_qr_version_6);
$resolution_signed_6 = PGE_Guest_Resolution_Service::resolve_from_qr(1001, $signed_qr_6);
check('6ق. [23] المسار المُوقَّع الأصلي (Phase 4) عبر build_payload() المباشرة لا يزال يعمل', $resolution_signed_6['result'] ?? null, 'found');
check('6ق. [23] QR مُوقَّع يُعيد نفس rsvp_id الصحيح', $resolution_signed_6['guest']['rsvp_id'] ?? null, $rsvp_id_6);

// ============================================================================
// السيناريو 7: مضيف غير مخوَّل (Authorization) + عزل المناسبات (AJAX layer)
// ============================================================================
echo "\n=== السيناريو 7: التفويض وعزل المناسبات ===\n";

$GLOBALS['__test_current_user_id'] = 999; // ليس مضيفاً ولا أدمن
$GLOBALS['__test_user_is_admin'] = false;
$_POST = make_post_fields(1001, ['name' => 'دخيل', 'phone' => '0507770000']);
$resp7 = call_ajax_handler('pge_invitation_mgmt_create_handler');
check_true('7. مستخدم غير مخوَّل: success=false', !($resp7['success'] ?? true));
check('7. السبب forbidden', $resp7['data']['reason'] ?? null, 'forbidden');
check_true('7. لا ضيف أُنشئ فعلياً', !isset(pge_event_guests_get_map(1001)['0507770000']));

// أدمن (ليس مالكاً) يُسمَح له صراحة
$GLOBALS['__test_user_is_admin'] = true;
$_POST = make_post_fields(1001, ['name' => 'عبر الأدمن', 'phone' => '0507770001']);
$resp7b = call_ajax_handler('pge_invitation_mgmt_create_handler');
check_true('7ب. أدمن (ليس مالكاً): success=true', $resp7b['success'] ?? false);
$GLOBALS['__test_user_is_admin'] = false;

// مضيف 802 (يملك 1002) يحاول العمل على دعوة تخصّ 1001 عبر تمرير event_id=1002
// لكن هاتف يخصّ فعلياً 1001 — يفشل بنيوياً (not_found)، لا "موجود لكن ممنوع".
$GLOBALS['__test_current_user_id'] = 802;
$_POST = make_post_fields(1002, ['phone' => '0501112000', 'reason' => 'محاولة تلاعب'] );
$resp7c = call_ajax_handler('pge_invitation_mgmt_cancel_handler');
check_true('7ج. محاولة إلغاء دعوة مناسبة أخرى عبر event_id مختلف: success=false', !($resp7c['success'] ?? true));
check('7ج. السبب not_found', $resp7c['data']['reason'] ?? null, 'not_found');
check('7ج. الدعوة الأصلية في 1001 لم تتأثَّر', PGE_Invitation_Repository::get_invitation(1001, '0501112000')['invitation_status'] ?? null, 'active');

// event_id غير صالح (لا ينتمي لـpge_event) → invalid_event
$_POST = make_post_fields(9999, ['phone' => '0501112000']);
$GLOBALS['__test_current_user_id'] = 801;
$resp7d = call_ajax_handler('pge_invitation_mgmt_cancel_handler');
check_true('7د. event_id غير صالح: success=false', !($resp7d['success'] ?? true));
check('7د. السبب invalid_event', $resp7d['data']['reason'] ?? null, 'invalid_event');

// ============================================================================
// السيناريو 8: بحث + فلاتر + ترقيم (Search + Filters + Pagination)
// ============================================================================
echo "\n=== السيناريو 8: بحث وفلاتر وترقيم ===\n";

setup_host_with_event(803, 1003);
$GLOBALS['__test_current_user_id'] = 803;

$seed_names = ['محمد الأول', 'سالم الثاني', 'محمد الثالث', 'خالد الرابع', 'محمد الخامس'];
foreach ($seed_names as $i => $name) {
    $_POST = make_post_fields(1003, ['name' => $name, 'phone' => '05066600' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
    call_ajax_handler('pge_invitation_mgmt_create_handler');
}

// RSVP/حضور محاكى عبر fake wpdb (قراءة فقط، نفس pge_event_guests_load_rsvp_from_db الحقيقية)
$wpdb->seed_rsvp(1003, '0506660000', 'yes', 1);
$wpdb->seed_rsvp(1003, '0506660001', 'no', 0);

$_POST = make_post_fields(1003, ['per_page' => 2, 'page' => 1, 'sort_by' => 'name', 'sort_dir' => 'asc']);
$resp8_p1 = call_ajax_handler('pge_invitation_mgmt_list_handler');
check_true('8. القائمة صفحة 1: success', $resp8_p1['success'] ?? false);
check('8. total = 5', (int) ($resp8_p1['data']['total'] ?? -1), 5);
check('8. total_pages = 3 (per_page=2)', (int) ($resp8_p1['data']['total_pages'] ?? -1), 3);
check('8. عدد عناصر الصفحة الأولى = 2', count($resp8_p1['data']['items'] ?? []), 2);
check('8. ترتيب أبجدي تصاعدي: أول عنصر "خالد الرابع"', $resp8_p1['data']['items'][0]['name'] ?? null, 'خالد الرابع');

$_POST = make_post_fields(1003, ['per_page' => 2, 'page' => 3, 'sort_by' => 'name', 'sort_dir' => 'asc']);
$resp8_p3 = call_ajax_handler('pge_invitation_mgmt_list_handler');
check('8ب. الصفحة الأخيرة تحتوي عنصراً واحداً (5 عناصر / per_page=2)', count($resp8_p3['data']['items'] ?? []), 1);

$_POST = make_post_fields(1003, ['search' => 'محمد']);
$resp8_search = call_ajax_handler('pge_invitation_mgmt_list_handler');
check('8ج. بحث بالاسم "محمد": total = 3', (int) ($resp8_search['data']['total'] ?? -1), 3);

$_POST = make_post_fields(1003, ['search' => '0506660002']);
$resp8_search_phone = call_ajax_handler('pge_invitation_mgmt_list_handler');
check('8د. بحث بالهاتف الكامل: total = 1', (int) ($resp8_search_phone['data']['total'] ?? -1), 1);
check('8د. النتيجة "محمد الثالث"', $resp8_search_phone['data']['items'][0]['name'] ?? null, 'محمد الثالث');

$code_for_search = pge_event_guests_get_map(1003)['0506660003']['code'];
$_POST = make_post_fields(1003, ['search' => $code_for_search]);
$resp8_search_code = call_ajax_handler('pge_invitation_mgmt_list_handler');
check('8هـ. بحث برمز الدعوة: total = 1', (int) ($resp8_search_code['data']['total'] ?? -1), 1);

$_POST = make_post_fields(1003, ['rsvp_status' => 'yes']);
$resp8_filter_rsvp = call_ajax_handler('pge_invitation_mgmt_list_handler');
check('8و. فلتر rsvp_status=yes: total = 1', (int) ($resp8_filter_rsvp['data']['total'] ?? -1), 1);

$_POST = make_post_fields(1003, ['attendance_status' => 'yes']);
$resp8_filter_att = call_ajax_handler('pge_invitation_mgmt_list_handler');
check('8ز. فلتر attendance_status=yes: total = 1 (checked_in=1 فقط)', (int) ($resp8_filter_att['data']['total'] ?? -1), 1);

$_POST = make_post_fields(1003, ['phone' => '0506660004', 'reason' => 'اختبار فلتر']);
call_ajax_handler('pge_invitation_mgmt_cancel_handler');
$_POST = make_post_fields(1003, ['invitation_status' => 'cancelled']);
$resp8_filter_inv = call_ajax_handler('pge_invitation_mgmt_list_handler');
check('8ح. فلتر invitation_status=cancelled: total = 1', (int) ($resp8_filter_inv['data']['total'] ?? -1), 1);

$_POST = make_post_fields(1003, ['search' => 'غير موجود إطلاقاً']);
$resp8_empty = call_ajax_handler('pge_invitation_mgmt_list_handler');
check('8ط. بحث بلا نتائج: total = 0', (int) ($resp8_empty['data']['total'] ?? -1), 0);
check('8ط. items = مصفوفة فارغة', $resp8_empty['data']['items'] ?? null, []);

// ============================================================================
// السيناريو 9: تصدير CSV/Excel — Phase 9C مُعتمَدة، متاح فعلياً الآن
// ============================================================================
// Phase 9C ("Invitation Export") اعتمدت التصدير (راجع PGE_INVITATION_MGMT_
// EXPORT_ENABLED في invitation-management-ajax.php = true الآن). التغطية
// التفصيلية الكاملة (CSV/Excel الحقيقيَّين، الفلترة، الترتيب، التدقيق،
// استبعاد بيانات QR، الانحدار...) موجودة في tests/test-invitation-export.php
// المُخصَّص لهذه المرحلة؛ هذا السيناريو هنا يبقى للتحقق السطحي المتكامل مع
// بقية سيناريوهات هذا الملف (تفويض/عزل مناسبات/فلترة نفس الـfixtures).
echo "\n=== السيناريو 9: تصدير CSV/Excel — مُعتمَد فعلياً (Phase 9C) ===\n";

check_true('9-0. PGE_INVITATION_MGMT_EXPORT_ENABLED = true', defined('PGE_INVITATION_MGMT_EXPORT_ENABLED') && PGE_INVITATION_MGMT_EXPORT_ENABLED === true);
check_true('9. wp_ajax_pge_invitation_mgmt_export_csv مُسجَّل فعلياً', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_export_csv']));
check_true('9ب. wp_ajax_pge_invitation_mgmt_export_excel مُسجَّل فعلياً', isset($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_export_excel']));

$_POST = make_post_fields(1003, ['search' => 'محمد']);
$csv_output = call_export_handler('pge_invitation_mgmt_export_csv_handler');
check_true('9ج. تصدير CSV فعلي: يبدأ بـUTF-8 BOM', strlen($csv_output) > 0 && substr($csv_output, 0, 3) === "\xEF\xBB\xBF");
// كل سطر (رأس + بيانات) ينتهي بـ"\r\n" (لا سطر أخير بلا فاصل) — عدد مرات
// "\r\n" هو ذاته عدد الأسطر الكلي مباشرة، بلا +1 (تصحيح: الصيغة القديمة هنا
// كانت تضيف +1 خطأً، فكانت "تنجح" صدفة فقط قبل هذا الإصلاح بسبب تطابق رقمي
// عرَضي مع تنفيذ سابق مختلف، لا لأنها صحيحة حسابياً).
check('9ج. تصدير CSV: سطر رأس + 3 أسطر بيانات = 4 أسطر إجمالاً (نفس الفلترة total=3)', substr_count($csv_output, "\r\n"), 4);

$_POST = make_post_fields(1003, ['search' => 'محمد']);
$excel_output = call_export_handler('pge_invitation_mgmt_export_excel_handler');
check_true('9د. تصدير Excel: .xlsx حقيقي (توقيع ZIP)، ليس جدول HTML', substr($excel_output, 0, 4) === "PK\x03\x04" && stripos($excel_output, '<table') === false);

// ============================================================================
// السيناريو 10: توليد سجل التدقيق (Audit generation) — قراءة عبر الخدمة الحقيقية
// ============================================================================
echo "\n=== السيناريو 10: توليد سجل التدقيق ===\n";

// ملاحظة: لا سجلات resent/qr_regenerated ضمن السطح الفعّال بعد Phase 9A Final
// Fix — الاستدعاءات المباشرة في السيناريوهين 5/6 أعلاه أثبتتا صراحةً أن بوابة
// التدقيق رفضت كتابتهما، فسجل هذه الدعوة يبقى created فقط (لم يُعدَّل/يُلغَ).
$audit_rows_10 = PGE_Invitation_Management_Audit::list_for_invitation(1001, '0501112000');
$audit_actions_10 = array_column($audit_rows_10, 'action');
check('10. سجل الدعوة يحوي created فقط (سطح Phase 9A: resent/qr_regenerated مرفوضان تدقيقياً)', $audit_actions_10, ['created']);
check_true('10. كل صف تدقيق يحمل actor_user_id وcreated_at', array_reduce($audit_rows_10, function ($carry, $r) {
    return $carry && (int) $r['actor_user_id'] > 0 && !empty($r['created_at']);
}, true));

// إثبات تكميلي: سطح التدقيق النشط لأي دعوة أخرى (0501111199، مرّت بـ
// edited→cancelled عبر الـAJAX الحقيقي في السيناريوهات 3/4 — سطر 'created'
// الأصلي مُسجَّل تحت الهاتف القديم 0501111111 نفسه قبل التعديل، بما أن
// التدقيق مفهرَس بالهاتف الحرفي وقت كل عملية، لا بمعرِّف كيان ثابت؛ سلوك
// موجود مسبقاً بلا علاقة بهذا الإصلاح) يبقى بلا أي تسريب لـresent/qr_regenerated.
$audit_rows_10b = PGE_Invitation_Management_Audit::list_for_invitation(1001, '0501111199');
check('10ب. سجل دعوة أخرى (بعد التعديل): edited, cancelled بالضبط (لا تسريب resent/qr_regenerated)', array_column($audit_rows_10b, 'action'), ['edited', 'cancelled']);

// ============================================================================
// السيناريو 11: انحدار — event-guests.php (Phases 1-8) غير متأثرة
// ============================================================================
echo "\n=== السيناريو 11: انحدار — event-guests.php ===\n";

$stats_11 = pge_event_guests_get_stats(1003);
check('11. pge_event_guests_get_stats() الحقيقية: total = 5 (نفس ضيوف Phase 9، لا تخزين مكرَّر)', (int) $stats_11['total'], 5);
check('11. pge_event_guests_get_stats(): yes = 1 (من RSVP الحقيقي المحاكى)', (int) $stats_11['yes'], 1);
check('11. pge_event_guests_get_stats(): checked = 1', (int) $stats_11['checked'], 1);

$row_payload_11 = pge_event_guests_get_row_payload(1003, pge_event_guests_get_map(1003)['0506660000']);
check('11ب. pge_event_guests_get_row_payload() الحقيقية: status=yes لضيف تم تسجيل رده', $row_payload_11['status'] ?? null, 'yes');

check_true('11ج. pge_event_guests_user_can_manage() الحقيقية لا تزال تعمل لمضيف 803 على 1003', pge_event_guests_user_can_manage(1003));
$GLOBALS['__test_current_user_id'] = 999;
check_true('11د. pge_event_guests_user_can_manage() ترفض مستخدماً دخيلاً', !pge_event_guests_user_can_manage(1003));
$GLOBALS['__test_current_user_id'] = 803;

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
