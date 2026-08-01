<?php
/**
 * ============================================================================
 * اختبار تنفيذي حقيقي — "RC1 Final Release Blocker: RSVP Write Path Unification"
 * ============================================================================
 * يغطي العناصر العشرة المطلوبة صراحةً (1-10 أدناه)، بتنفيذ حقيقي (لا مرآة
 * منطقية) للسلسلة الكاملة المُعدَّلة في هذا الإصلاح:
 *   - includes/class-pge-invitation-repository.php   (current_or_null() الجديدة
 *     + إصلاح edit() لتحديث invited_at عند تغيّر الهاتف)
 *   - includes/rsvp-handler.php                       (Web RSVP)
 *   - includes/class-cartat-handler.php                (record_rsvp() عبر Reflection)
 *   - includes/class-ultramsg-handler.php              (record_rsvp() عبر Reflection)
 *   - includes/rsvp-migration.php                      (الترحيل القديم)
 *   - includes/ajax.php                                (pge_checkin_guest القديم)
 *
 * Fake $wpdb يُحاكي القيد الحقيقي UNIQUE KEY event_phone (event_id, guest_phone)
 * على wp_pge_event_rsvps (نفس القيد الفعلي في pge_create_rsvp_table()) — أي
 * محاولة INSERT تصطدم به تفشل هنا تماماً كما تفشل في MySQL الحقيقي، بما يثبت
 * أن current_or_null() تُصفِّر الصف اليتيم في مكانه بدل محاولة إدراج صف ثانٍ
 * (مستحيل فعلياً).
 *
 * "Do NOT create logical mirrors of production code. Execute the real
 * activation code." — كل استدعاء أدناه لدالة/صنف إنتاجي حقيقي بالاسم.
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-rsvp-write-path-unification.php
 */

define('ABSPATH', __DIR__ . '/');
if (!defined('PGE_PATH')) define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

$GLOBALS['__test_registered_hooks'] = [];
function add_action($hook, $callback, ...$rest) { $GLOBALS['__test_registered_hooks'][$hook] = $callback; }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }
function register_rest_route(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) { function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return trim((string) $v); } }
if (!function_exists('absint')) { function absint($value) { return abs((int) $value); } }
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
if (!function_exists('wp_hash')) { function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed'); } }
if (!function_exists('__')) { function __($text, $domain = 'default') { return $text; } }
if (!function_exists('pge_norm_phone')) { function pge_norm_phone($v) { return preg_replace('/\D+/', '', trim((string) $v)); } }
if (!function_exists('pge_generate_invite_code')) {
    function pge_generate_invite_code() { static $n = 0; $n++; return 'CODE-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT); }
}
if (!function_exists('pge_get_user_plan_limits_for_events')) {
    function pge_get_user_plan_limits_for_events($user_id) { return ['guest_limit' => 0]; }
}

// ساعة اختبار مُتقدِّمة (لا قيمة ثابتة) — ضرورية لصحة مقارنة invited_at مقابل
// created_at في is_rsvp_row_current() عبر current_or_null().
if (!function_exists('current_time')) {
    $GLOBALS['__test_clock_counter'] = 0;
    function current_time($type = 'mysql', $gmt = 0) {
        $GLOBALS['__test_clock_counter']++;
        return gmdate('Y-m-d H:i:s', strtotime('2026-08-01 00:00:00') + $GLOBALS['__test_clock_counter']);
    }
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

$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_user_is_admin'] = false;
$GLOBALS['__test_logged_in'] = true;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null) {
    if ($cap === 'administrator') return $GLOBALS['__test_user_is_admin'];
    if ($cap === 'edit_post') return true;
    return false;
}

$GLOBALS['__test_posts'] = [];
function set_test_event($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) ['ID' => $event_id, 'post_type' => $post_type, 'post_author' => $author_id];
}
function get_post($event_id) { return $GLOBALS['__test_posts'][$event_id] ?? null; }
function get_post_type($event_id) { $p = get_post($event_id); return $p ? $p->post_type : false; }
function get_post_field($field, $post_id) { $p = get_post($post_id); if (!$p) return ''; return $p->{$field} ?? ''; }

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

if (!class_exists('Test_Wp_Die_Exception_Rwpu')) { class Test_Wp_Die_Exception_Rwpu extends \Exception {} }
function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }
$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) { $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]); throw new Test_Wp_Die_Exception_Rwpu('wp_send_json_success'); }
function wp_send_json_error($data = null, $status_code = null) { $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]); throw new Test_Wp_Die_Exception_Rwpu('wp_send_json_error'); }
function wp_die($message = '', $title = '', $args = []) { throw new Test_Wp_Die_Exception_Rwpu('wp_die'); }

function call_registered_hook(string $hook): array
{
    $GLOBALS['__test_json_response'] = null;
    $cb = $GLOBALS['__test_registered_hooks'][$hook] ?? null;
    if (!$cb) return ['success' => false, 'data' => ['reason' => 'hook_not_registered']];
    try { $cb(); } catch (Test_Wp_Die_Exception_Rwpu $e) { /* متوقَّع */ }
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

/**
 * ============================================================================
 * Fake $wpdb — يفرض فعلياً UNIQUE KEY event_phone (event_id, guest_phone) على
 * wp_pge_event_rsvps، تماماً كما تفعل MySQL الحقيقية (راجع pge_create_rsvp_table()
 * في rsvp-handler.php). أي INSERT يصطدم بصف موجود بنفس (event_id, phone) يفشل
 * هنا (يعيد false) بدل إدراج صف ثانٍ صامتاً — هذا بالتحديد ما يثبت أن
 * current_or_null() تُصفِّر الصف اليتيم في مكانه ولا تحاول إدراج صف جديد أبداً
 * حين يوجد صف فعلي بالفعل.
 * ============================================================================
 */
class Fake_Wpdb_Rwpu
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $rsvps = [];
    private $rsvp_next_id = 1;

    // بيانات وهمية لمسار الترحيل القديم (Migration) فقط
    public $legacy_events = []; // event_id => true

    public function prepare($query, ...$args)
    {
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

    public function get_row($sql, $output = null)
    {
        if (!$this->is_rsvps_table($sql)) return null;
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

    public function get_var($sql)
    {
        if ($this->is_rsvps_table($sql) && strpos($sql, 'checked_in') !== false && strpos($sql, 'SELECT') === 0) {
            $row = $this->get_row($sql);
            return $row ? (int) $row->checked_in : null;
        }
        if (strpos($sql, 'COUNT(DISTINCT p.ID)') !== false) {
            return count($this->legacy_events);
        }
        return null;
    }

    public function get_col($sql)
    {
        if (strpos($sql, 'DISTINCT p.ID') !== false) {
            return array_keys($this->legacy_events);
        }
        return [];
    }

    public function get_results($sql, $output = null)
    {
        if (!$this->is_rsvps_table($sql)) return [];
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

    public function insert($table, $data, $format = null)
    {
        if (strpos($table, 'pge_event_rsvps') === false) return false;

        $event_id = (int) ($data['event_id'] ?? 0);
        $phone = (string) ($data['guest_phone'] ?? '');
        // محاكاة حقيقية للقيد الفريد الحقيقي — لا إدراج صف ثانٍ لنفس
        // (event_id, guest_phone) بينما يبقى صف قديم موجوداً.
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
            'created_at' => function_exists('current_time') ? current_time('mysql', true) : null,
            'updated_at' => function_exists('current_time') ? current_time('mysql', true) : null,
        ], $data, ['id' => $id]);
        $this->rsvps[$id] = $row;
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        if (strpos($table, 'pge_event_rsvps') === false || !isset($where['id'])) return false;
        $id = (int) $where['id'];
        if (!isset($this->rsvps[$id])) return 0;
        $this->rsvps[$id] = array_merge($this->rsvps[$id], $data);
        return 1;
    }

    // يحاكي بدقة SQL الخام في ajax.php::pge_checkin_guest — نفس القيد الفريد
    // الحقيقي: يبحث عن صف مطابق أولاً (UPDATE)، وإلا يُدرج (INSERT) واحداً جديداً.
    public function query($sql)
    {
        if (stripos($sql, 'INSERT INTO') === 0 && strpos($sql, 'pge_event_rsvps') !== false && stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            if (preg_match("/VALUES\s*\((\d+),\s*'([^']*)',\s*'pending',\s*1,\s*NOW\(\)\)/", $sql, $m)) {
                $event_id = (int) $m[1];
                $phone = $m[2];
                foreach ($this->rsvps as $id => $r) {
                    if ((int) $r['event_id'] === $event_id && (string) $r['guest_phone'] === $phone) {
                        $this->rsvps[$id]['checked_in'] = 1;
                        $this->rsvps[$id]['checked_in_at'] = 'NOW';
                        return 1;
                    }
                }
                $id = $this->rsvp_next_id++;
                $this->rsvps[$id] = [
                    'id' => $id, 'event_id' => $event_id, 'guest_phone' => $phone,
                    'guest_name' => null, 'companions' => 0, 'note' => null, 'reply' => 'pending',
                    'checked_in' => 1, 'checked_in_at' => 'NOW',
                    'checked_in_by_assignment_id' => null, 'checkin_method' => null, 'actual_entered_count' => null,
                    'created_at' => function_exists('current_time') ? current_time('mysql', true) : null,
                    'updated_at' => function_exists('current_time') ? current_time('mysql', true) : null,
                ];
                $this->insert_id = $id;
                return 1;
            }
        }
        return 0;
    }
}
$GLOBALS['wpdb'] = new Fake_Wpdb_Rwpu();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ============================================================================
// تحميل حقيقي لكل مسارات الكتابة الخمسة + الطبقة الموحَّدة
// ============================================================================
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/rsvp-handler.php';
require_once __DIR__ . '/../includes/class-pge-guest-resolution-service.php';
require_once __DIR__ . '/../includes/class-pge-checkin-qr-service.php';
require_once __DIR__ . '/../includes/class-cartat-handler.php';
require_once __DIR__ . '/../includes/class-ultramsg-handler.php';
require_once __DIR__ . '/../includes/rsvp-migration.php';
require_once __DIR__ . '/../includes/ajax.php';

$total = 0; $passed = 0; $failures = [];
function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS  $label\n"; }
    else { $failures[] = $label; echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}
function check_true($label, $cond) { check($label, (bool) $cond, true); }

$record_rsvp_cartat_ref = new ReflectionMethod('Mon_Cartat_Handler', 'record_rsvp');
$record_rsvp_cartat_ref->setAccessible(true);
$record_rsvp_um_ref = new ReflectionMethod('Mon_UltraMsg_Handler', 'record_rsvp');
$record_rsvp_um_ref->setAccessible(true);
$cartat_handler = new Mon_Cartat_Handler();
$um_handler = new Mon_UltraMsg_Handler();

$HOST_ID = 970;
set_test_event(7001, $HOST_ID);
$GLOBALS['__test_current_user_id'] = $HOST_ID;
$GLOBALS['__test_logged_in'] = true;

echo "=== RC1 Final Release Blocker: RSVP Write Path Unification — Executable Verification ===\n";

// ============================================================================
// 1: Web RSVP — يعمل كما كان، عبر المسار الموحَّد الجديد
// ============================================================================
PGE_Invitation_Service::create(7001, '0580000001', 'ويب', '', $HOST_ID);
$r1 = pge_save_rsvp_response(7001, '0580000001', 'yes', 1, '', false);
check_true('1. Web RSVP لا يزال ينجح عبر المسار الموحَّد', $r1['success']);
$row1 = PGE_Guest_Resolution_Service::resolve_by_phone(7001, '0580000001');
check('1ب. الرد المخزَّن صحيح (yes)', $row1['guest']['reply'], 'yes');

// ============================================================================
// 2: Cartat RSVP — عبر record_rsvp() الحقيقية بالـReflection
// ============================================================================
PGE_Invitation_Service::create(7001, '0580000002', 'كارتات', '', $HOST_ID);
$record_rsvp_cartat_ref->invoke($cartat_handler, 7001, '0580000002', 'yes');
$row2 = PGE_Guest_Resolution_Service::resolve_by_phone(7001, '0580000002');
check('2. Cartat RSVP يُخزَّن عبر record_rsvp() الحقيقية', $row2['result'], 'found');
check('2ب. الرد صحيح (yes)', $row2['guest']['reply'], 'yes');

// ============================================================================
// 3: UltraMsg RSVP — عبر record_rsvp() الحقيقية بالـReflection
// ============================================================================
PGE_Invitation_Service::create(7001, '0580000003', 'الترا مسج', '', $HOST_ID);
$record_rsvp_um_ref->invoke($um_handler, 7001, '0580000003', 'yes');
$row3 = PGE_Guest_Resolution_Service::resolve_by_phone(7001, '0580000003');
check('3. UltraMsg RSVP يُخزَّن عبر record_rsvp() الحقيقية', $row3['result'], 'found');
check('3ب. الرد صحيح (yes)', $row3['guest']['reply'], 'yes');

// ============================================================================
// 4: إعادة استخدام الهاتف بعد Delete — عبر قناة مختلفة (Cartat) هذه المرة،
// لإثبات أن الحارس الموحَّد يعمل عبر أي قناة، ليس فقط القناة الأصلية
// ============================================================================
PGE_Invitation_Service::create(7001, '0580000004', 'قبل الحذف', '', $HOST_ID);
$r4a = pge_save_rsvp_response(7001, '0580000004', 'yes', 2, '', false);
$old_row_id_4 = $r4a['success'] ? (int) PGE_Guest_Resolution_Service::resolve_by_phone(7001, '0580000004')['guest']['rsvp_id'] : 0;
// محاكاة أعمدة Recorder الحقيقية مباشرة (تسجيل حضور فعلي على الدعوة القديمة)
$wpdb->update($wpdb->prefix . 'pge_event_rsvps', [
    'checked_in' => 1, 'checked_in_at' => '2026-08-01 09:00:00',
    'checked_in_by_assignment_id' => 40, 'checkin_method' => 'qr', 'actual_entered_count' => 3,
], ['id' => $old_row_id_4]);
$rows_before_4 = count($wpdb->rsvps);
PGE_Invitation_Service::delete(7001, '0580000004', $HOST_ID);
PGE_Invitation_Service::create(7001, '0580000004', 'بعد الحذف', '', $HOST_ID);
// الرد الجديد يصل عبر Cartat (قناة مختلفة عن التي أنشأت الصف اليتيم أصلاً)
$new_rsvp_id_4 = $record_rsvp_cartat_ref->invoke($cartat_handler, 7001, '0580000004', 'yes');
$rows_after_4 = count($wpdb->rsvps);
check('4. إعادة استخدام الهاتف بعد Delete: لا صف فعلي جديد يُنشأ (يبقى نفس العدد — القيد الفريد يمنع الإدراج، فالتصفير في المكان هو الآلية الوحيدة الممكنة فعلياً)', $rows_after_4, $rows_before_4);
check('4ب. نفس id الفعلي القديم أُعيد استخدامه (تصفير في المكان)', $new_rsvp_id_4, $old_row_id_4);
$resolve_4 = PGE_Guest_Resolution_Service::resolve_by_phone(7001, '0580000004');
check_true('4ج. الدعوة الجديدة قابلة للحلّ فوراً (found)', $resolve_4['result'] === 'found');

// ============================================================================
// 5: إعادة استخدام الهاتف بعد QR Rotation (ثم حذف ثم إعادة إنشاء) — عبر UltraMsg
// ============================================================================
PGE_Invitation_Service::create(7001, '0580000005', 'قبل التدوير', '', $HOST_ID);
pge_save_rsvp_response(7001, '0580000005', 'yes', 0, '', false);
$rsvp_id_5_v1 = (int) PGE_Guest_Resolution_Service::resolve_by_phone(7001, '0580000005')['guest']['rsvp_id'];
$qr_v1_5 = PGE_Guest_Resolution_Service::build_scanner_qr_payload(7001, $rsvp_id_5_v1, '0580000005');
$regen_5 = PGE_Invitation_Repository::regenerate_qr(7001, '0580000005');
check('5. تمهيد: تدوير QR نجح (v1→v2)', $regen_5['result'], 'regenerated');
PGE_Invitation_Service::delete(7001, '0580000005', $HOST_ID);
PGE_Invitation_Service::create(7001, '0580000005', 'بعد التدوير ثم الحذف', '', $HOST_ID);
$record_rsvp_um_ref->invoke($um_handler, 7001, '0580000005', 'yes');
$qr_v1_5_after = PGE_Guest_Resolution_Service::resolve_from_qr(7001, $qr_v1_5);
check('5ب. الـQR القديم (v1، من قبل التدوير والحذف، ثم إعادة إنشاء الدعوة ورد جديد) يُرفَض (invalid/qr_superseded) — لا يُحيا رغم أن rsvp_id الفعلي أُعيد استخدامه فعلياً', [$qr_v1_5_after['result'], $qr_v1_5_after['reason'] ?? null], ['invalid', 'qr_superseded']);
$resolve_5 = PGE_Guest_Resolution_Service::resolve_by_phone(7001, '0580000005');
check('5ج. الدعوة الجديدة سليمة وتُحَل عبر QR جديد فقط', $resolve_5['result'], 'found');

// ============================================================================
// 6: إعادة استخدام الهاتف بعد Check-in — عبر المسار القديم ajax.php مباشرة
// (pge_checkin_guest) لإثبات أن حتى هذا المسار اليدوي القديم لا يُحيي حالة قديمة
// ============================================================================
PGE_Invitation_Service::create(7001, '0580000006', 'قبل تسجيل حضور قديم', '', $HOST_ID);
pge_save_rsvp_response(7001, '0580000006', 'yes', 0, '', false);
// تسجيل حضور فعلي عبر مسار ajax.php القديم نفسه (upsert خام)
$_POST = ['event_id' => 7001, 'phone' => '0580000006', 'nonce' => wp_create_nonce('pge_checkin_nonce')];
update_post_meta(7001, '_pge_invited_phones', ['0580000006']);
$checkin_resp_old = call_registered_hook('wp_ajax_pge_checkin_guest');
check_true('6. تمهيد: تسجيل حضور قديم عبر ajax.php نجح', $checkin_resp_old['success']);
$old_row_id_6 = (int) PGE_Guest_Resolution_Service::resolve_by_phone(7001, '0580000006')['guest']['rsvp_id'];
PGE_Invitation_Service::delete(7001, '0580000006', $HOST_ID);
PGE_Invitation_Service::create(7001, '0580000006', 'بعد الحذف — يعيد تسجيل الحضور القديم؟', '', $HOST_ID);
// نفس مسار ajax.php القديم يحاول تسجيل حضور للهوية الجديدة — يجب ألا يُبلِّغ
// "مسجل مسبقاً" اعتماداً على حالة الدعوة المحذوفة
$_POST = ['event_id' => 7001, 'phone' => '0580000006', 'nonce' => wp_create_nonce('pge_checkin_nonce')];
update_post_meta(7001, '_pge_invited_phones', array_merge((array) get_post_meta(7001, '_pge_invited_phones', true), ['0580000006']));
$checkin_resp_new = call_registered_hook('wp_ajax_pge_checkin_guest');
check_true('6ب. ajax.php القديم لا يُبلِّغ "مسجل مسبقاً" اعتماداً على صف يتيم — ينجح كتسجيل جديد', $checkin_resp_new['success'] && empty($checkin_resp_new['data']['already']));
$resolve_6 = PGE_Guest_Resolution_Service::resolve_by_phone(7001, '0580000006');
check('6ج. الدعوة الجديدة checked_in=true (سجّلها ajax.php الآن فعلاً)، لكن checkin_method/actual_entered_count تبقيان null (لم تُوَرَّثا من القديم ولم يكتبهما هذا المسار)', [$resolve_6['guest']['checked_in'], $resolve_6['guest']['checkin_method'], $resolve_6['guest']['actual_entered_count']], [true, null, null]);

// ============================================================================
// 7: إنشاء RSVP جديد لا يرث أي حالة — فحص شامل مباشر على المثال #4 أعلاه
// ============================================================================
check_true('7. RSVP جديد (المثال #4) لا يرث checked_in', $resolve_4['guest']['checked_in'] === false);
check('7ب. لا يرث checkin_method', $resolve_4['guest']['checkin_method'], null);
check('7ج. لا يرث actual_entered_count', $resolve_4['guest']['actual_entered_count'], null);
check('7د. لا يرث companions القديم (كان 2، يجب أن يعكس الرد الجديد فقط)', $resolve_4['guest']['companions'], 0);

// ============================================================================
// 8: جميع القنوات تستخدم نفس القرار — استدعاء مباشر لنفس current_or_null()
// من ثلاث قنوات مختلفة (Web/Cartat/UltraMsg) على نفس الهاتف بالتتابع، والتحقق
// من نفس السلوك بالضبط (تصفير الصف اليتيم، لا إدراج مكرر) في كل مرة
// ============================================================================
PGE_Invitation_Service::create(7001, '0580000008', 'قناة1', '', $HOST_ID);
pge_save_rsvp_response(7001, '0580000008', 'yes', 0, '', false); // Web
$id_8a = (int) PGE_Guest_Resolution_Service::resolve_by_phone(7001, '0580000008')['guest']['rsvp_id'];
PGE_Invitation_Service::delete(7001, '0580000008', $HOST_ID);
PGE_Invitation_Service::create(7001, '0580000008', 'قناة2', '', $HOST_ID);
$id_8b = $record_rsvp_cartat_ref->invoke($cartat_handler, 7001, '0580000008', 'yes'); // Cartat
PGE_Invitation_Service::delete(7001, '0580000008', $HOST_ID);
PGE_Invitation_Service::create(7001, '0580000008', 'قناة3', '', $HOST_ID);
$id_8c = $record_rsvp_um_ref->invoke($um_handler, 7001, '0580000008', 'yes'); // UltraMsg
check('8. نفس id الفعلي (نفس الصف المُصفَّر) عبر الثلاث قنوات المتتالية — دليل تنفيذي على قرار موحَّد واحد لا نسخ متفرقة منه', [$id_8a, $id_8b, $id_8c], [$id_8a, $id_8a, $id_8a]);

// ============================================================================
// 9: لا يوجد أي مسار كتابة يتجاوزه — تحقق بنيوي مباشر: كل الملفات الخمسة
// تستدعي PGE_Invitation_Repository::current_or_null() حرفياً في نص الكود
// ============================================================================
$files_must_call_guard = [
    'rsvp-handler.php', 'class-cartat-handler.php', 'class-ultramsg-handler.php',
    'rsvp-migration.php', 'ajax.php',
];
$all_call_guard = true;
foreach ($files_must_call_guard as $f) {
    $src = file_get_contents(__DIR__ . '/../includes/' . $f);
    if (strpos($src, 'PGE_Invitation_Repository::current_or_null(') === false) { $all_call_guard = false; break; }
}
check_true('9. كل ملفات الكتابة الخمسة تستدعي current_or_null() حرفياً — لا مسار موازٍ', $all_call_guard);
// تحقق سلبي إضافي: لا وجود لأي شرط created_at>=invited_at مُعاد تنفيذه يدوياً
// خارج class-pge-invitation-repository.php نفسها
$dup_condition_found = false;
foreach ($files_must_call_guard as $f) {
    $src = file_get_contents(__DIR__ . '/../includes/' . $f);
    if (preg_match('/created_at\s*>=\s*\$?invited_at/', $src)) { $dup_condition_found = true; break; }
}
check_true('9ب. لا يوجد أي نسخة موازية للشرط created_at>=invited_at خارج الطبقة الموحَّدة', !$dup_condition_found);

// ============================================================================
// 10: Regression كامل للمراحل السابقة — يُنفَّذ عبر ملفات اختبار منفصلة قائمة
// فعلاً (test-hard-delete-semantics-fixpack.php، test-hard-delete-semantics-audit.php،
// وبقية حزمة RC1)، مُوثَّق بنتائجه الكاملة في التقرير النهائي.
// ============================================================================
echo "10. (مرجعي) Regression الكامل يُشغَّل عبر ملفات اختبار منفصلة قائمة — راجع التقرير النهائي.\n";

echo "\n=== النتيجة: $passed / $total ===\n";
if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
