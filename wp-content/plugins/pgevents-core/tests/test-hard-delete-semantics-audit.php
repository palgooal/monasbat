<?php
/**
 * ============================================================================
 * اختبار تنفيذي حقيقي (audit-only) — "RC1 Hard Delete Semantics Audit"
 * ============================================================================
 * "RC1 Fix Pack 3B is APPROVED. A4 is CLOSED. This task is a focused audit
 * only. Do NOT modify production code. Do NOT create migrations. Do NOT
 * change delete behavior." — هذا الملف **لا يعدّل أي سلوك إنتاجي إطلاقاً**؛
 * يحمّل السلسلة الحقيقية كما هي تماماً وينفّذها بأسمائها الحقيقية فقط
 * ("Do NOT create logical mirrors of production code. Execute the real
 * activation code.") ليُظهر (يقرأ ويُثبت، لا يُغيّر) السلوك الحالي الفعلي
 * لسياسة Hard Delete المُعتمَدة (RC1 Fix Pack 3B) بعد الحذف.
 *
 * الملفات الحقيقية المُحمَّلة بلا أي تعديل وقت التحميل:
 *   - includes/helpers.php، includes/event-guests.php
 *   - includes/class-pge-invitation-management-schema.php
 *   - includes/class-pge-invitation-management-audit.php
 *   - includes/class-pge-invitation-repository.php
 *   - includes/class-pge-invitation-service.php
 *   - includes/invitation-management-ajax.php   (مسار الحذف المفرد/الجماعي الحقيقي عبر AJAX)
 *   - includes/rsvp-handler.php                 (pge_save_rsvp_response — الكاتب الكنسي الوحيد لـwp_pge_event_rsvps)
 *   - includes/class-pge-guest-resolution-service.php  (resolve_by_phone/resolve_by_rsvp_id/resolve_from_qr/search/build_scanner_qr_payload)
 *   - includes/class-pge-checkin-qr-service.php        (build_payload/validate)
 *   - includes/class-pge-attendance-statistics-service.php (get_attendance_summary — عبر ReflectionClass::newInstanceWithoutConstructor()،
 *     **نفس الآلية الإنتاجية الحقيقية** التي يستخدمها PGE_Attendance_Dashboard_Provider حصراً — لا محاكاة، استدعاء حقيقي لدالة إنتاجية حقيقية).
 *
 * ============================================================================
 * قرار نطاق صريح (Scoping Decision) — PGE_Checkin_Recorder لم يُستدعَ مباشرة
 * ============================================================================
 * تسجيل الحضور الفعلي (checked_in=1) في السيناريوهات أدناه يُبنى بكتابة مباشرة
 * على صف wp_pge_event_rsvps عبر $wpdb->update() (نفس الأعمدة بالضبط التي
 * يكتبها PGE_Checkin_Recorder::record_guest_checkin() الحقيقي: checked_in،
 * checked_in_at، checked_in_by_assignment_id، checkin_method،
 * actual_entered_count) بدلاً من استدعاء الـRecorder نفسه عبر السلسلة الكاملة
 * (PGE_Supervisor_Assignment_Service + PGE_Supervisor_Session + GET_LOCK
 * الذري). هذا قرار نطاق مقصود: قراءة الكود الفعلي لـclass-pge-checkin-
 * recorder.php (Phase 4) تُثبت أنه **لا يحتوي أي فحص لوجود الدعوة في خريطة
 * الضيوف إطلاقاً** — فقط فحوصات الإسناد/المناسبة/التكرار/العدد — فمحاكاة
 * الأعمدة النهائية التي يكتبها كافية تماماً لإثبات "ماذا يحدث لصف RSVP مُسجَّل
 * حضوره بعد Hard Delete؟" دون سحب بنية تفويض مشرف كاملة غير متعلقة بمركز
 * التدقيق. لا ادّعاء بأن هذا استدعاء لـRecorder نفسه — التقرير المرافق يستشهد
 * بكود Recorder مباشرة (سطراً برقمه) بدل إعادة تنفيذه هنا.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. Fake $wpdb يحاكي فقط:
 * wp_pge_event_rsvps وwp_pge_invitation_mgmt_audit_log (الجدولان الوحيدان
 * اللازمان لكل السيناريوهات).
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-hard-delete-semantics-audit.php
 *
 * ============================================================================
 * تحديث بعد "RC1 Hard Delete Semantics Fix Pack" — تصحيح تأكيدات أصبحت غير
 * صحيحة بتصميم مقصود (نفس نمط تصحيحات test-rc1-fixpack2.php/fixpack3a.php)
 * ============================================================================
 * هذا الملف كُتب أصلاً *قبل* الإصلاح ليُثبت وجود العلل الثلاث (Blocker
 * 1/2/3) بالتنفيذ الحقيقي. بعد تنفيذ الفيكس باك (`PGE_Invitation_Repository::
 * is_rsvp_row_current()` + نقاط استدعائها في Guest Resolution Service
 * وrsvp-handler.php)، الأسطر المُعلَّمة أدناه بـ"[مُصلَح]" كانت تؤكِّد أن
 * النتيجة تبقى `found`/تحتفظ بالحالة — وهذا لم يعد صحيحاً الآن بتصميم
 * مقصود ومُعتمَد. عُدِّلت لتعكس السلوك الصحيح الحالي (تُعامَل كغير موجودة
 * إطلاقاً) بدل حذفها، حفاظاً على هذا الملف كسجل تاريخي لما كانت عليه العلة
 * قبل الإصلاح، وكحارس انحدار يثبت بقاءها مُصلَحة بعده. راجع
 * `docs/HARD-DELETE-SEMANTICS-FIXPACK.md` للتفاصيل الكاملة، وملف
 * `tests/test-hard-delete-semantics-fixpack.php` للاختبارات المخصَّصة
 * الجديدة (19 حالة مطلوبة صراحة في RFC الفيكس باك).
 *
 * ============================================================================
 * تحديث ثانٍ بعد "RC1 Final Release Blocker: RSVP Write Path Unification"
 * ============================================================================
 * الفيكس باك أعلاه (is_rsvp_row_current()) عالج طبقة "الحلّ" فقط (Resolution)،
 * لكن مسارات الكتابة (rsvp-handler.php + كلا مزوّدَي واتساب + الترحيل القديم)
 * لم تكن كلها تستدعيه — Cartat/UltraMsg كانا يُحدِّثان created_at لأي صف
 * موجود بالهاتف بلا أي تحقق. التوحيد اللاحق أضاف `PGE_Invitation_Repository::
 * current_or_null()` كنقطة القرار الموحَّدة الوحيدة لكل مسارات الكتابة. اكتشاف
 * جوهري أثناء ذلك: `wp_pge_event_rsvps` تفرض فعلياً `UNIQUE KEY event_phone
 * (event_id, guest_phone)` — **لا يمكن فعلياً وجود صفين لنفس (event_id, phone)
 * في آنٍ واحد**، فـ"صفان فعليان" (Fake_Wpdb_Hdsa هنا لا يُنفِّذ هذا القيد،
 * فسمح بمحاكاة سيناريو مستحيل فعلياً في MySQL الحقيقية) لم يكن أبداً وصفاً
 * دقيقاً للسلوك المُصلَح الصحيح. القرار الصحيح الوحيد المُمكن فعلياً: تصفير
 * الصف اليتيم **في مكانه** (نفس id) عند اكتشاف أنه من دورة حياة سابقة، بدل
 * محاولة إدراج صف ثانٍ (مستحيل). أسطر السيناريو D وB8 أدناه المُعلَّمة الآن
 * بـ"[مُحدَّث RWPU]" عُدِّلت لتعكس هذه الحقيقة الأدق — لا تراجع عن أي إغلاق
 * سابق: عدم توريث checked_in/checkin_method/actual_entered_count يبقى مُثبَتاً
 * بالكامل (D5/D5ب لا تزالان دون تغيير)، فقط الافتراض الخاطئ عن "id مختلف/صف
 * ثانٍ منفصل" هو ما صُحِّح. راجع `tests/test-rsvp-write-path-unification.php`
 * (الذي يُحاكي القيد الفريد الحقيقي فعلياً) للاختبارات المخصَّصة الجديدة.
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
if (!function_exists('absint')) { function absint($value) { return abs((int) $value); } }
// RC1 Hard Delete Semantics Fix Pack — ساعة اختبار مُتقدِّمة (لا قيمة ثابتة):
// كل استدعاء يتقدَّم ثانية واحدة عن سابقه، يحاكي مرور الوقت الحقيقي بين
// create()/RSVP/delete/إعادة الإنشاء المتتالية — ضروري لصحة مقارنة
// invited_at مقابل created_at في PGE_Invitation_Repository::is_rsvp_row_current()
// (السيناريو D تحديداً؛ قيمة ثابتة واحدة كانت ستجعل كل الطوابع متطابقة دوماً).
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

// ── نفس نمط test-replacement-entitlement-grant.php المُثبَت — تجاوز فحص سعة
//    الباقة (غير متعلق بموضوع التدقيق هنا إطلاقاً).
if (!function_exists('pge_get_user_plan_limits_for_events')) {
    function pge_get_user_plan_limits_for_events($user_id) { return ['guest_limit' => 0]; }
}

// ── تفويض/جلسة ───────────────────────────────────────────────────────────
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null) {
    if ($cap === 'administrator') return $GLOBALS['__test_user_is_admin'];
    return false;
}

// ── Posts وهمية ──────────────────────────────────────────────────────────
$GLOBALS['__test_posts'] = [];
function set_test_event($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) ['ID' => $event_id, 'post_type' => $post_type, 'post_author' => $author_id];
}
function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
function get_post_field($field, $post_id) { $p = get_post($post_id); if (!$p) return ''; return $p->{$field} ?? ''; }

// ── Post Meta وهمية (مخزن حقيقي يعمل — لازم لـ_pge_invited_guests/_pge_invitation_status) ──
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

// ── Stubs AJAX/JSON (لمسار الحذف الحقيقي عبر invitation-management-ajax.php) ──
if (!class_exists('Test_Wp_Die_Exception_Hdsa')) { class Test_Wp_Die_Exception_Hdsa extends \Exception {} }

function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception_Hdsa('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception_Hdsa('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception_Hdsa('wp_die'); }

function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    try { $handler(); } catch (Test_Wp_Die_Exception_Hdsa $e) { /* متوقَّع */ }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function make_post_fields($event_id, array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_event_manage_nonce'), 'event_id' => $event_id], $extra);
}

/**
 * ============================================================================
 * Fake $wpdb — يحاكي جدولين حقيقيين فقط: wp_pge_event_rsvps (بنفس أعمدة
 * Schema الحقيقية) وwp_pge_invitation_mgmt_audit_log — يكفي بالضبط لتشغيل كل
 * الدوال الحقيقية المذكورة أعلى الملف دون أي محاكاة زائدة لمنطقها الداخلي.
 * ============================================================================
 */
class Fake_Wpdb_Hdsa
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $rsvps = [];       // id => row (array)
    public $mgmt_audit = [];  // id => row (array)
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
            // نفس استعلام PGE_Attendance_Statistics_Service::get_attendance_summary() الحقيقي
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
            // created_at الافتراضي (كما تفعله MySQL فعلياً عبر `DEFAULT
            // CURRENT_TIMESTAMP` في تعريف الجدول الحقيقي — راجع
            // pge_create_rsvp_table()) — ضروري لعمل is_rsvp_row_current()
            // بمعناها الفعلي في هذا الاختبار (Blocker 3).
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
$GLOBALS['wpdb'] = new Fake_Wpdb_Hdsa();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ============================================================================
// تحميل حقيقي للسلسلة الكاملة اللازمة (بلا أي تعديل)
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/invitation-management-ajax.php';
require_once __DIR__ . '/../includes/rsvp-handler.php';
require_once __DIR__ . '/../includes/class-pge-guest-resolution-service.php';
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php';
require_once __DIR__ . '/../includes/class-pge-attendance-statistics-service.php';

$total = 0; $passed = 0; $failures = [];
function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS  $label\n"; }
    else { $failures[] = $label; echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}
function check_true($label, $cond) { check($label, (bool) $cond, true); }

/** استدعاء get_attendance_summary() الإنتاجية الحقيقية — نفس آلية الوصول
 *  الوحيدة المخوَّلة (ReflectionClass::newInstanceWithoutConstructor()) التي
 *  يستخدمها PGE_Attendance_Dashboard_Provider حصراً؛ استدعاء حقيقي لدالة
 *  إنتاجية حقيقية، لا محاكاة لمنطقها. */
function real_attendance_summary(int $event_id): array
{
    $ref = new ReflectionClass('PGE_Attendance_Statistics_Service');
    $instance = $ref->newInstanceWithoutConstructor();
    return $instance->get_attendance_summary($event_id);
}

$HOST_ID = 801;
set_test_event(5001, $HOST_ID);
$GLOBALS['__test_current_user_id'] = $HOST_ID;
$GLOBALS['__test_logged_in'] = true;

echo "=== RC1 Hard Delete Semantics Audit — Audit-Only Executable Demonstration ===\n";

// ============================================================================
// السيناريو A: حذف قبل أي RSVP — لا صف RSVP على الإطلاق، فلا يتام (Clean case)
// ============================================================================
PGE_Invitation_Service::create(5001, '0540000001', 'ضيف A', '', $HOST_ID);
check_true('A1. تمهيد: get_invitation() يجد الدعوة قبل الحذف', PGE_Invitation_Repository::get_invitation(5001, '0540000001') !== null);
PGE_Invitation_Service::delete(5001, '0540000001', $HOST_ID);
check('A2. get_invitation() يُعيد null بعد الحذف (كما هو مُعتمَد في Fix Pack 3B)', PGE_Invitation_Repository::get_invitation(5001, '0540000001'), null);
check('A3. resolve_by_phone() بعد الحذف: not_found (لا صف RSVP أُنشئ قط — لا يُتم هنا)', PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000001'), ['result' => 'not_found', 'reason' => 'invitation_not_found']);
$search_a = PGE_Guest_Resolution_Service::search(5001, '0540000001');
check('A4. search() (المسار المبني على خريطة الضيوف) لا يُعيد الضيف المحذوف', $search_a['guests'], []);

// ============================================================================
// السيناريو B: حذف بعد RSVP، قبل تسجيل الحضور — RSVP Bypasses Guest-Map Check
// ============================================================================
PGE_Invitation_Service::create(5001, '0540000002', 'ضيف B', '', $HOST_ID);
$rsvp_b = pge_save_rsvp_response(5001, '0540000002', 'yes', 1, 'ملاحظة B', false);
check_true('B1. تمهيد: RSVP نجح قبل الحذف', $rsvp_b['success']);
$resolve_b_before = PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000002');
check('B2. تمهيد: resolve_by_phone() يجد الضيف قبل الحذف (found)', $resolve_b_before['result'], 'found');
$rsvp_id_b = $resolve_b_before['guest']['rsvp_id'];
$qr_b = PGE_Guest_Resolution_Service::build_scanner_qr_payload(5001, $rsvp_id_b, '0540000002');
check_true('B3. تمهيد: بُني QR موقَّع صالح قبل الحذف', $qr_b !== '');

PGE_Invitation_Service::delete(5001, '0540000002', $HOST_ID);

check('B4. get_invitation() يُعيد null بعد الحذف (الدعوة غائبة من خريطة الضيوف)', PGE_Invitation_Repository::get_invitation(5001, '0540000002'), null);
$resolve_b_after = PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000002');
check('B5. [مُصلَح — Blocker 1] resolve_by_phone() بعد الحذف يُعيد not_found الآن — صف RSVP اليتيم يُعامَل كغير موجود', $resolve_b_after['result'], 'not_found');
$resolve_by_id_b_after = PGE_Guest_Resolution_Service::resolve_by_rsvp_id(5001, $rsvp_id_b);
check('B6. [مُصلَح — Blocker 1] resolve_by_rsvp_id() بعد الحذف يُعيد not_found الآن (نفس نقطة تقارب مسار QR)', $resolve_by_id_b_after['result'], 'not_found');
$qr_validate_b_after = PGE_Checkin_QR_Service::validate(5001, $qr_b);
check('B7. (طبقة التحقق البنيوي وحدها لم تُعدَّل عمداً) PGE_Checkin_QR_Service::validate() الخام لا يزال valid بمعزل — الحارس الجديد في طبقة الحلّ (B8) هو ما يمنع الوصول الفعلي، لا هذه الطبقة', $qr_validate_b_after['result'], 'valid');
$resolve_qr_b_after = PGE_Guest_Resolution_Service::resolve_from_qr(5001, $qr_b);
// [مُحدَّث RWPU] بعد إصلاح اشتقاق qr_version الافتراضي (يُشتَق الآن من
// invited_at بدل ثابت 1 عند غياب تدوير سابق)، is_qr_version_current() يرفض
// الـQR القديم بالفعل *قبل* الوصول لمسار resolve_by_rsvp_id() (النتيجة تصبح
// invalid/qr_superseded بدل not_found) — رفض حقيقي أقوى، لا انحدار: لا وصول
// لـPGE_Checkin_Recorder بأي الحالتين.
check('B8. [مُصلَح — Blocker 1+2، مُحدَّث RWPU] resolve_from_qr() الكامل (المسار الفعلي لمسح المشرف) بعد الحذف يُرفَض الآن (invalid/qr_superseded) — لا وصول لـPGE_Checkin_Recorder', [$resolve_qr_b_after['result'], $resolve_qr_b_after['reason'] ?? null], ['invalid', 'qr_superseded']);

// ============================================================================
// السيناريو C: حذف بعد تسجيل الحضور — الحالة الأخطر (راجع ملاحظة النطاق أعلى الملف)
// ============================================================================
PGE_Invitation_Service::create(5001, '0540000003', 'ضيف C', '', $HOST_ID);
pge_save_rsvp_response(5001, '0540000003', 'yes', 0, '', false);
$resolve_c_before = PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000003');
$rsvp_id_c = $resolve_c_before['guest']['rsvp_id'];
// محاكاة أعمدة PGE_Checkin_Recorder الحقيقية مباشرة (راجع "قرار نطاق صريح" أعلى الملف)
$wpdb->update($wpdb->prefix . 'pge_event_rsvps', [
    'checked_in' => 1, 'checked_in_at' => '2026-08-01 09:00:00',
    'checked_in_by_assignment_id' => 55, 'checkin_method' => 'qr', 'actual_entered_count' => 1,
], ['id' => $rsvp_id_c]);
check_true('C1. تمهيد: صف RSVP مُسجَّل حضوره فعلياً قبل الحذف (checked_in=1)', PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000003')['guest']['checked_in']);

$summary_before_delete = real_attendance_summary(5001);

PGE_Invitation_Service::delete(5001, '0540000003', $HOST_ID);

check('C2. get_invitation() يُعيد null بعد الحذف رغم تسجيل الحضور مسبقاً', PGE_Invitation_Repository::get_invitation(5001, '0540000003'), null);
$resolve_c_after = PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000003');
check('C3. [مُصلَح — Blocker 1] resolve_by_phone() بعد الحذف يُعيد not_found الآن حتى لضيف مُسجَّل حضوره بالفعل', $resolve_c_after['result'], 'not_found');
check('C4. [مُصلَح — Blocker 1] لا Guest Object يُبنى إطلاقاً بعد الحذف (result=not_found) — لا تسريب لحالة checked_in عبر مسار الحلّ', $resolve_c_after['guest'] ?? null, null);
check('C5. الصف الفعلي في wp_pge_event_rsvps يبقى checked_in_at=... كما هو (لا Cascade، غير مطلوب حسب RFC) — لكنه غير قابل للوصول عبر أي مسار حلّ بعد الآن (C3/C4)', $wpdb->rsvps[$rsvp_id_c]['checked_in_at'], '2026-08-01 09:00:00');
$summary_after_delete = real_attendance_summary(5001);
check('C6. [حرج] PGE_Attendance_Statistics_Service::get_attendance_summary() الحقيقية: total_invitations لا يتغيَّر بالحذف (يبقى يحتسب الصف اليتيم)', $summary_after_delete['total_invitations'], $summary_before_delete['total_invitations']);
check('C7. [حرج] checked_in_invitations كذلك لا يتغيَّر — لوحة/إحصاء المشرف تستمر في عرض حضور ضيف محذوف نهائياً من إدارة الدعوات', $summary_after_delete['checked_in_invitations'], $summary_before_delete['checked_in_invitations']);
check_true('C8. للتأكيد الإيجابي: كلا الرقمين لا يزالان >= 1 فعلياً (لا يتضح الأمر بمصادفة رقمين صفريين)', $summary_after_delete['total_invitations'] >= 1 && $summary_after_delete['checked_in_invitations'] >= 1);

// ============================================================================
// السيناريو D: حذف ثم إعادة إنشاء بنفس رقم الجوال — تصادم هوية صامت
// ============================================================================
PGE_Invitation_Service::create(5001, '0540000004', 'ضيف D — الأصلي', '', $HOST_ID);
pge_save_rsvp_response(5001, '0540000004', 'yes', 2, 'رد الضيف الأصلي', false);
$resolve_d_original = PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000004');
$rsvp_id_d_original = $resolve_d_original['guest']['rsvp_id'];
$wpdb->update($wpdb->prefix . 'pge_event_rsvps', [
    'checked_in' => 1, 'checked_in_at' => '2026-08-01 08:00:00',
    'checked_in_by_assignment_id' => 55, 'checkin_method' => 'manual_search', 'actual_entered_count' => 3,
], ['id' => $rsvp_id_d_original]);

PGE_Invitation_Service::delete(5001, '0540000004', $HOST_ID);
check('D1. [مُصلَح — Blocker 1] بعد الحذف مباشرة (قبل إعادة الإنشاء)، resolve_by_phone() يُعيد not_found الآن', PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000004')['result'], 'not_found');

PGE_Invitation_Service::create(5001, '0540000004', 'ضيف D — الجديد (نفس الرقم)', '', $HOST_ID);
$rsvp_d_new = pge_save_rsvp_response(5001, '0540000004', 'yes', 0, 'رد الضيف الجديد', false);
check_true('D2. RSVP الضيف الجديد (نفس الرقم) نجح ظاهرياً', $rsvp_d_new['success']);

$rows_d_after = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pge_event_rsvps WHERE event_id = %d AND guest_phone = %s ORDER BY id ASC", 5001, '0540000004'), ARRAY_A);
check('D3. [مُحدَّث RWPU] صف فعلي واحد فقط يبقى بهذا الهاتف — wp_pge_event_rsvps تفرض فعلياً UNIQUE KEY event_phone (event_id, guest_phone)، فلا يمكن وجود صفين فعلياً (Fake_Wpdb_Hdsa هنا لا يُنفِّذ هذا القيد؛ راجع tests/test-rsvp-write-path-unification.php الذي يُحاكيه فعلياً) — التصفير في المكان هو الخيار الوحيد الممكن', count($rows_d_after), 1);
$new_row_d = null;
foreach ($rows_d_after as $r) { if ((int) $r['id'] === $rsvp_id_d_original) { $new_row_d = $r; } }
check('D4. [مُحدَّث RWPU] نفس id الفعلي القديم أُعيد استخدامه (تصفير في المكان)', $new_row_d !== null && (int) $new_row_d['id'] === $rsvp_id_d_original, true);
check('D5. [مُصلَح — Blocker 3] الصف (المُصفَّر في مكانه رغم إعادة استخدام id) يعود لحالة حضور مستقلة تماماً (checked_in=0) — لا توريث checked_in من الهوية القديمة المحذوفة', (int) $new_row_d['checked_in'], 0);
check('D5ب. checked_in_at/checkin_method/actual_entered_count كلها تُصفَّر أيضاً (لا توريث)', [$new_row_d['checked_in_at'], $new_row_d['checkin_method'], $new_row_d['actual_entered_count']], [null, null, null]);
check('D6. [مُحدَّث RWPU] الرد/الملاحظة في الصف (بعد التصفير ثم الكتابة الجديدة) تعكسان فعلاً رد الضيف الجديد', [$new_row_d['reply'], $new_row_d['note']], ['yes', 'رد الضيف الجديد']);
$resolve_d_new = PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000004');
check('D7. [مُصلَح — Blocker 1+3] resolve_by_phone() للهاتف بعد إعادة الإنشاء يُعيد found نظيفاً (لا ambiguous)', $resolve_d_new['result'], 'found');
check('D7ب. [مُحدَّث RWPU] rsvp_id المُعاد هو نفس id الفعلي (مُصفَّراً) — إعادة الاستخدام هنا سليمة لأن لا حالة تاريخية توَرَّثت (D5/D5ب/D6)', $resolve_d_new['guest']['rsvp_id'] === $rsvp_id_d_original, true);
check_true('D7ج. حالة الحضور المُعادة عبر مسار الحلّ الفعلي هي false أيضاً (متسقة مع D5)', $resolve_d_new['guest']['checked_in'] === false);

// ============================================================================
// السيناريو E: حذف يُصفّر تتبّع qr_version — يُعيد إحياء QR سابق كان "مُدوَّراً"
// ============================================================================
PGE_Invitation_Service::create(5001, '0540000005', 'ضيف E', '', $HOST_ID);
pge_save_rsvp_response(5001, '0540000005', 'yes', 0, '', false);
$rsvp_id_e = PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000005')['guest']['rsvp_id'];
$qr_v1_e = PGE_Guest_Resolution_Service::build_scanner_qr_payload(5001, $rsvp_id_e, '0540000005'); // qr_version=1 (افتراضي)

$regen = PGE_Invitation_Repository::regenerate_qr(5001, '0540000005');
// [مُحدَّث RWPU] qr_version الافتراضي (قبل أي تدوير) لم يعد ثابتاً (1) —
// يُشتَق الآن من invited_at (طابع وقت كبير) لإغلاق Blocker 2 الكامل (راجع
// docblock get_qr_version() في class-pge-invitation-repository.php)، فالقيمة
// بعد أول تدوير هنا رقم مُشتَق أكبر من 1 بالضرورة، لا الثابت 2 حرفياً.
check_true('E1. تمهيد: تدوير QR أثناء الدعوة نشطة نجح (qr_version رقمي > 1)', ($regen['result'] ?? null) === 'regenerated' && (int) ($regen['qr_version'] ?? 0) > 1);
$resolve_qr_v1_while_active = PGE_Guest_Resolution_Service::resolve_from_qr(5001, $qr_v1_e);
check('E2. تمهيد: أثناء الدعوة نشطة، الـQR القديم (v1) يُرفَض فعلاً بعد التدوير (qr_superseded) — التدوير يعمل كما هو مُصمَّم', $resolve_qr_v1_while_active, ['result' => 'invalid', 'reason' => 'qr_superseded']);

PGE_Invitation_Service::delete(5001, '0540000005', $HOST_ID);

check('E3. (سلوك delete() لم يتغيَّر عمداً) بعد الحذف، get_qr_version() لا يزال يعود للقيمة الافتراضية 1 — لم نلمس delete()/qr_version إطلاقاً، الإصلاح في طبقة الحلّ فقط (E4)', PGE_Invitation_Repository::get_qr_version(5001, '0540000005'), 1);
$resolve_qr_v1_after_delete = PGE_Guest_Resolution_Service::resolve_from_qr(5001, $qr_v1_e);
// [مُحدَّث RWPU] بما أن qr_version الافتراضي أصبح مُشتَقاً من invited_at (رقم
// كبير مميَّز)، والقيمة الافتراضية بعد الحذف تعود لـDEFAULT_QR_VERSION=1
// الثابتة (E3، بلا invited_at بعد unset الحالة) — يستحيل تطابقهما أصلاً، فيُرفَض
// الـQR القديم عند فحص is_qr_version_current() نفسه (invalid/qr_superseded)
// قبل الوصول لـresolve_by_rsvp_id() (not_found) — رفض أقوى بطبقة أبكر، لا
// انحدار.
check('E4. [مُصلَح — Blocker 2، مُحدَّث RWPU] الـQR القديم (v1) الذي كان "مُدوَّراً/مُبطَلاً" فعلياً قبل الحذف يُرفَض الآن (invalid/qr_superseded) بعد الحذف', [$resolve_qr_v1_after_delete['result'], $resolve_qr_v1_after_delete['reason'] ?? null], ['invalid', 'qr_superseded']);

// ============================================================================
// السيناريو F: حذف جماعي لخليط (معلَّق/مُجاب/مُسجَّل حضوره) عبر مسار AJAX الحقيقي
// ============================================================================
PGE_Invitation_Service::create(5001, '0540000006', 'ضيف F — معلَّق (بلا RSVP)', '', $HOST_ID);
PGE_Invitation_Service::create(5001, '0540000007', 'ضيف F — مُجاب (RSVP فقط)', '', $HOST_ID);
pge_save_rsvp_response(5001, '0540000007', 'yes', 1, '', false);
PGE_Invitation_Service::create(5001, '0540000008', 'ضيف F — مُسجَّل حضوره', '', $HOST_ID);
pge_save_rsvp_response(5001, '0540000008', 'yes', 0, '', false);
$rsvp_id_f8 = PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000008')['guest']['rsvp_id'];
$wpdb->update($wpdb->prefix . 'pge_event_rsvps', ['checked_in' => 1, 'checked_in_at' => '2026-08-01 07:00:00'], ['id' => $rsvp_id_f8]);

$_POST = make_post_fields(5001, ['phones' => '0540000006,0540000007,0540000008']);
$resp_bulk_f = call_ajax_handler('pge_invitation_mgmt_bulk_delete_handler');
check_true('F1. الحذف الجماعي الحقيقي (نفس مسار AJAX الفعلي في اللوحة) نجح', $resp_bulk_f['success']);
check('F2. الحذف الجماعي أزال الثلاثة فعلياً من خريطة الضيوف', $resp_bulk_f['data']['deleted'], 3);

check('F3. الضيف المعلَّق (لا RSVP قط): not_found بعد الحذف الجماعي — نظيف، لا يتيم (يطابق A)', PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000006')['result'], 'not_found');
check('F4. [مُصلَح — Blocker 1] الضيف المُجاب (RSVP بلا حضور): not_found الآن بعد الحذف الجماعي — نفس سلوك الحذف المفرد المُصلَح بالضبط (يطابق B)، لا معالجة خاصة للحذف الجماعي', PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000007')['result'], 'not_found');
$resolve_f8_after = PGE_Guest_Resolution_Service::resolve_by_phone(5001, '0540000008');
check('F5. [مُصلَح — Blocker 1] الضيف المُسجَّل حضوره: not_found الآن بعد الحذف الجماعي (يطابق C المُصلَح) — لا معالجة خاصة للحذف الجماعي، نفس الحارس الموحَّد', [$resolve_f8_after['result'], $resolve_f8_after['guest'] ?? null], ['not_found', null]);

// ============================================================================
// سجل التدقيق (Audit Trail) — حدث 'deleted' يُسجَّل، لكنه غير مقروء من أي مستهلك
// ============================================================================
$audit_trail_b = PGE_Invitation_Management_Audit::list_for_invitation(5001, '0540000002');
$last_audit_event_b = end($audit_trail_b);
check('G1. حدث 0540000002: سجل التدقيق يحتوي فعلياً على action=deleted بعد الحذف (Append-Only يعمل كما هو مُصمَّم)', $last_audit_event_b['action'] ?? null, 'deleted');
check_true('G2. list_for_invitation() تُعيد النتيجة فعلياً (الدالة تعمل، القراءة ممكنة تقنياً)', is_array($audit_trail_b) && count($audit_trail_b) >= 1);
// فحص بنيوي حقيقي (لا محاكاة): list_for_invitation لا تُستهلَك من أي AJAX/UI حالياً — نفس
// الاستنتاج الذي وصل إليه grep -r "list_for_invitation" عبر كامل المشروع أثناء البحث.
$ajax_source = file_get_contents(__DIR__ . '/../includes/invitation-management-ajax.php');
check('G3. تأكيد بنيوي حقيقي: invitation-management-ajax.php لا يستدعي list_for_invitation() إطلاقاً (لا واجهة تعرض هذا السجل اليوم)', strpos($ajax_source, 'list_for_invitation') === false, true);

echo "\n=== النتيجة: $passed / $total ===\n";
if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
