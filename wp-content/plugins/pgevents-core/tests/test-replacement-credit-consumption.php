<?php
/**
 * ============================================================================
 * اختبارات ذاتية الاكتفاء — المرحلة 4C: Replacement Credit Consumption During
 * Cartat Queue Delivery.
 * ============================================================================
 * لا PHPUnit. يحمّل الملفات الحقيقية الثلاثة التالية من المشروع دون أي تعديل
 * عليها إطلاقاً (تحقّق مباشرةً بمقارنة هذا الملف بمحتواها الفعلي إن رغبت):
 *   - includes/class-pge-invitation-credit-ledger.php
 *   - includes/class-pge-replacement-entitlements.php
 *   - includes/class-cartat-handler.php
 *
 * Fake $wpdb واحد يحاكي جدولين معاً (mon_invitation_credit_ledger و
 * mon_replacement_entitlements) + GET_LOCK/RELEASE_LOCK (عام لأي اسم قفل —
 * pge_credit_*، pge_replacement_credit_*، pge_wa_cron_* على حدٍّ سواء) + دعم
 * ORDER BY/LIMIT (مطلوب لاستعلام FIFO في claim_oldest_granted_for_ledger()).
 *
 * تحذير بيئي (كما في كل ملفات الاختبار السابقة لهذا المشروع): لا مفسّر PHP
 * CLI متاح في بيئة التطوير الحالية لهذه الجلسة (لا صلاحيات root/sudo
 * لتثبيته). التحقق من هذا الملف تم عبر فاحص AST (php-parser عبر Node.js)
 * للتأكد من خلوّه من أخطاء صياغية، بالإضافة إلى تتبّع يدوي دقيق لمنطق كل
 * اختبار مقابل الكود الفعلي — وليس تنفيذاً حقيقياً. راجع التقرير النهائي
 * المرفق لتفاصيل هذا القيد الصريح، وأوامر التشغيل الفعلية المطلوبة على
 * السيرفر الحقيقي.
 *
 * التشغيل على السيرفر:
 *   php tests/test-replacement-credit-consumption.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس ────────────────────────────────────────────────────

define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir());

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_rest_route(...$args) { /* no-op */ }

if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) { return $v; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
}
// قابلة للتحكم بها من الاختبارات — بنفس آلية ملفات الاختبار السابقة.
$GLOBALS['__test_now_override'] = null;
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0)
    {
        return $GLOBALS['__test_now_override'] ?? '2026-01-01 00:00:00';
    }
}
function wp_verify_nonce($nonce, $action) { return true; }
function is_user_logged_in() { return true; }
function pge_is_host_or_admin($event_id) { return true; }
function pge_norm_phone($v) { return preg_replace('/\D+/', '', trim((string) $v)); }

// ── مخزن User Meta ──────────────────────────────────────────────────────────

$GLOBALS['__test_user_meta'] = [];

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}

function update_user_meta($user_id, $key, $value)
{
    if (
        isset($GLOBALS['__test_block_meta_key'])
        && $GLOBALS['__test_block_meta_key'] !== null
        && $key === $GLOBALS['__test_block_meta_key']
    ) {
        return false;
    }
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
    return true;
}

// ── مخزن wp_options عام (الـQueue وpending) ─────────────────────────────────

$GLOBALS['__test_options'] = [];

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    $GLOBALS['__test_options'][$name] = $value;
    return true;
}

function delete_option($name)
{
    unset($GLOBALS['__test_options'][$name]);
    return true;
}

// ── مخزن Post/Post Meta ─────────────────────────────────────────────────────

$GLOBALS['__test_posts']     = [];
$GLOBALS['__test_post_meta'] = [];

function get_post_field($field, $post_id)
{
    return $GLOBALS['__test_posts'][$post_id][$field] ?? '';
}

function get_post($post_id)
{
    return isset($GLOBALS['__test_posts'][$post_id]) ? (object) $GLOBALS['__test_posts'][$post_id] : null;
}

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

// ── مخزن المدعوين ────────────────────────────────────────────────────────────

$GLOBALS['__test_invited_phones'] = [];
$GLOBALS['__test_guests_map']     = [];

function pge_get_invited_phones($event_id)
{
    return $GLOBALS['__test_invited_phones'][$event_id] ?? [];
}

function pge_event_guests_get_map($event_id)
{
    return $GLOBALS['__test_guests_map'][$event_id] ?? [];
}

// ── قوالب واتساب (نسخة اختبار مبسّطة) ───────────────────────────────────────

function pge_wa_get_templates($event_id)
{
    return ['invite' => 'دعوة لـ {{guest_name}} إلى {{event_name}}'];
}

function pge_wa_render_template($tpl, $vars)
{
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{{' . $k . '}}', (string) $v, $tpl);
    }
    return $tpl;
}

// ── دوال ووردبريس متفرقة غير جوهرية لمنطق الرصيد ────────────────────────────

function date_i18n($format, $timestamp = null) { return 'DATE'; }
function get_the_post_thumbnail_url($post_id, $size = 'full') { return ''; }
function get_permalink($post_id) { return 'https://example.test/event/' . $post_id . '/'; }

$GLOBALS['__test_scheduled_events'] = [];
function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    $GLOBALS['__test_scheduled_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];
    return true;
}
function spawn_cron() { return true; }

// ── محاكاة wp_remote_post/Cartat API ────────────────────────────────────────

$GLOBALS['__test_remote_post_queue'] = [];
$GLOBALS['__test_remote_post_calls'] = 0;

function wp_remote_post($url, $args = [])
{
    $GLOBALS['__test_remote_post_calls']++;
    if (!empty($GLOBALS['__test_remote_post_queue'])) {
        return array_shift($GLOBALS['__test_remote_post_queue']);
    }
    return ['body' => json_encode(['status' => 'sent', 'id' => 'default-msg-id'])];
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

function is_wp_error($thing) { return $thing instanceof WP_Error; }

function wp_remote_retrieve_body($response)
{
    return is_array($response) ? ($response['body'] ?? '') : '';
}

// ── اعتراض wp_send_json_success/error ───────────────────────────────────────

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

/** يستدعي ajax_queue_start() ويلتقط الاستثناء دائماً. */
function call_ajax_queue_start(Mon_Cartat_Handler $handler)
{
    try {
        $handler->ajax_queue_start();
        return ['success' => null, 'payload' => 'DID_NOT_HALT'];
    } catch (PGE_Test_Ajax_Halt $e) {
        return ['success' => $e->success, 'payload' => $e->payload];
    }
}

// ── محاكاة Resolver المركزي (نسخة تشمل حقول Replacement الستة) ─────────────

function pge_get_user_plan_limits_for_events($user_id)
{
    $status = (string) get_user_meta($user_id, '_mon_package_status', true);
    if ($status !== 'active') {
        return [
            'invitation_credit_total'      => 0, 'invitation_credit_used'      => 0, 'invitation_credit_remaining'      => 0,
            'replacement_credit_total'     => 0, 'replacement_credit_used'     => 0, 'replacement_credit_remaining'     => 0,
        ];
    }

    $inv_total = get_user_meta($user_id, '_mon_invitation_credit_total', true);
    $inv_used  = get_user_meta($user_id, '_mon_invitation_credit_used', true);
    $inv_total = is_numeric($inv_total) ? max(0, (int) $inv_total) : 0;
    $inv_used  = is_numeric($inv_used) ? max(0, (int) $inv_used) : 0;

    $rep_total = get_user_meta($user_id, '_mon_replacement_credit_total', true);
    $rep_used  = get_user_meta($user_id, '_mon_replacement_credit_used', true);
    $rep_total = is_numeric($rep_total) ? max(0, (int) $rep_total) : 0;
    $rep_used  = is_numeric($rep_used) ? max(0, (int) $rep_used) : 0;

    return [
        'invitation_credit_total'      => $inv_total,
        'invitation_credit_used'       => $inv_used,
        'invitation_credit_remaining'  => max(0, $inv_total - $inv_used),
        'replacement_credit_total'     => $rep_total,
        'replacement_credit_used'      => $rep_used,
        'replacement_credit_remaining' => max(0, $rep_total - $rep_used),
    ];
}

// ── Fake $wpdb: جدولا Ledger + Entitlements معاً + GET_LOCK/RELEASE_LOCK ───

class Fake_Wpdb_Replacement_Consumption
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $held_locks = [];

    private $ledger_rows = [];
    private $ledger_unique_index = [];
    private $ledger_next_id = 1;

    private $entitlement_rows = [];
    private $entitlement_unique_index = [];
    private $entitlement_next_id = 1;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? '';
            $i++;
            if ($m[0] === '%d') {
                return (string) (int) $val;
            }
            return "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    private function which_table($sql_or_table)
    {
        if (strpos($sql_or_table, $this->prefix . 'mon_replacement_entitlements') !== false) {
            return 'entitlements';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_invitation_credit_ledger') !== false) {
            return 'ledger';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_var($sql)
    {
        if (preg_match("/SELECT\\s+GET_LOCK\\('([^']*)',\\s*(-?\\d+)\\)/i", $sql, $m)) {
            $name = $m[1];
            if (isset($this->held_locks[$name])) {
                return '0';
            }
            $this->held_locks[$name] = true;
            return '1';
        }

        if (stripos(ltrim($sql), 'SELECT COUNT(*)') === 0) {
            $which = $this->which_table($sql);
            if ($which === null) {
                return null;
            }
            $rows = array_values($which === 'entitlements' ? $this->entitlement_rows : $this->ledger_rows);
            $filtered = $this->apply_where($rows, $sql);
            return (string) count($filtered);
        }

        return null;
    }

    public function query($sql)
    {
        if (preg_match("/SELECT\\s+RELEASE_LOCK\\('([^']*)'\\)/i", $sql, $m)) {
            unset($this->held_locks[$m[1]]);
            return 1;
        }
        return false;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which === null) {
            return [];
        }
        $rows = array_values($which === 'entitlements' ? $this->entitlement_rows : $this->ledger_rows);
        $rows = $this->apply_where($rows, $sql);
        $rows = $this->apply_order_by($rows, $sql);
        return $rows;
    }

    /**
     * نسخة WHERE مُصحَّحة (بنفس منهج test-replacement-entitlements.php): تُزال
     * جملتا LIMIT وORDER BY المرساتان بنهاية السلسلة أولاً (بترتيب: LIMIT ثم
     * ORDER BY، فبعد إزالة LIMIT تصبح ORDER BY هي الذيل)، قبل استخراج شرط
     * WHERE — يمنع تلوّث آخر شرط AND بنص "ORDER BY ..." أو "LIMIT ..." غير
     * متعلّق بالبيانات فعلياً.
     */
    private function apply_where(array $rows, $sql)
    {
        $clean = preg_replace('/\s+LIMIT\s+\d+\s*$/i', '', $sql);
        $clean = preg_replace('/\s+ORDER\s+BY\s+.+$/i', '', $clean);

        if (preg_match('/WHERE\s+(.+)$/is', $clean, $m)) {
            $where = trim($m[1]);
            $conditions = preg_split('/\bAND\b/i', $where);
            foreach ($conditions as $cond) {
                $cond = trim($cond);
                if ($cond === '') continue;

                if (preg_match("/^(\\w+)\\s+IN\\s*\\(([^)]*)\\)$/i", $cond, $cm)) {
                    $field = $cm[1];
                    $values = array_map(function ($v) { return trim(trim($v), "'\""); }, explode(',', $cm[2]));
                    $rows = array_values(array_filter($rows, function ($r) use ($field, $values) {
                        return array_key_exists($field, $r) && in_array((string) $r[$field], $values, true);
                    }));
                    continue;
                }

                if (preg_match("/^(\\w+)\\s*=\\s*'([^']*)'$/", $cond, $cm)) {
                    $field = $cm[1]; $value = $cm[2];
                } elseif (preg_match('/^(\\w+)\\s*=\\s*(-?\\d+)$/', $cond, $cm)) {
                    $field = $cm[1]; $value = $cm[2];
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

    /**
     * دعم "ORDER BY col1 ASC, col2 ASC" — مطلوب حصراً لاستعلام FIFO في
     * claim_oldest_granted_for_ledger() ("ORDER BY granted_at ASC, id ASC").
     * يعمل على $sql الأصلي كاملاً (غير المنظَّف) — مستقل تماماً عن apply_where().
     */
    private function apply_order_by(array $rows, $sql)
    {
        if (!preg_match('/ORDER\s+BY\s+([a-zA-Z0-9_,\s]+?)\s*(?:LIMIT|$)/i', $sql, $m)) {
            return $rows;
        }
        $parts = array_map('trim', explode(',', $m[1]));
        usort($rows, function ($a, $b) use ($parts) {
            foreach ($parts as $part) {
                if (preg_match('/^(\w+)\s*(ASC|DESC)?$/i', $part, $pm)) {
                    $field = $pm[1];
                    $dir = strtoupper($pm[2] ?? 'ASC');
                    $av = $a[$field] ?? null;
                    $bv = $b[$field] ?? null;
                    if ($av == $bv) continue;
                    $cmp = ($av < $bv) ? -1 : 1;
                    return $dir === 'DESC' ? -$cmp : $cmp;
                }
            }
            return 0;
        });
        return $rows;
    }

    public function insert($table, $data, $format = null)
    {
        $this->last_error = '';
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }

        if ($which === 'entitlements') {
            $key = ($data['credit_cycle_id'] ?? '') . '|' . ($data['event_id'] ?? '') . '|' . ($data['source_guest_phone'] ?? '');
            if (isset($this->entitlement_unique_index[$key])) {
                $this->last_error = "Duplicate entry for key 'unique_replacement_entitlement'";
                return false;
            }
            $id = $this->entitlement_next_id++;
            $row = array_merge(['id' => $id, 'consumed_by_ledger_id' => null, 'consumed_at' => null], $data);
            $this->entitlement_rows[$id] = $row;
            $this->entitlement_unique_index[$key] = $id;
            $this->insert_id = $id;
            return 1;
        }

        $key = ($data['credit_cycle_id'] ?? '') . '|' . ($data['event_id'] ?? '') . '|' . ($data['guest_phone'] ?? '') . '|' . ($data['credit_type'] ?? '');
        if (isset($this->ledger_unique_index[$key])) {
            $this->last_error = "Duplicate entry for key 'unique_credit_consumption'";
            return false;
        }
        $id = $this->ledger_next_id++;
        $row = array_merge(['id' => $id, 'consumed_at' => null, 'refunded_at' => null, 'attempt_token' => null, 'attempt_started_at' => null, 'last_attempt_at' => null], $data);
        $this->ledger_rows[$id] = $row;
        $this->ledger_unique_index[$key] = $id;
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }
        $id = $where['id'] ?? null;
        if ($id === null) {
            return false;
        }

        if ($which === 'entitlements') {
            if (!isset($this->entitlement_rows[$id])) {
                return 0;
            }
            foreach ($where as $where_key => $where_value) {
                if ($where_key === 'id') continue;
                $current_value = $this->entitlement_rows[$id][$where_key] ?? null;
                if ((string) $current_value !== (string) $where_value) {
                    return 0;
                }
            }
            foreach ($data as $k => $v) {
                $this->entitlement_rows[$id][$k] = $v;
            }
            return 1;
        }

        if (!isset($this->ledger_rows[$id])) {
            return 0;
        }
        foreach ($where as $where_key => $where_value) {
            if ($where_key === 'id') continue;
            $current_value = $this->ledger_rows[$id][$where_key] ?? null;
            if ((string) $current_value !== (string) $where_value) {
                return 0;
            }
        }
        foreach ($data as $k => $v) {
            $this->ledger_rows[$id][$k] = $v;
        }
        return 1;
    }

    /** أداة اختبار: زرع صف مباشر في جدول Ledger الوهمي بأي حالة/بيانات. */
    public function seed_ledger_row($id, array $row)
    {
        $merged = array_merge(['id' => $id, 'consumed_at' => null, 'refunded_at' => null, 'attempt_token' => null, 'attempt_started_at' => null, 'last_attempt_at' => null], $row);
        $this->ledger_rows[$id] = $merged;
        $key = ($merged['credit_cycle_id'] ?? '') . '|' . ($merged['event_id'] ?? '') . '|' . ($merged['guest_phone'] ?? '') . '|' . ($merged['credit_type'] ?? '');
        $this->ledger_unique_index[$key] = $id;
        if ($id >= $this->ledger_next_id) {
            $this->ledger_next_id = $id + 1;
        }
    }

    /** أداة اختبار: زرع صف مباشر في جدول Entitlements الوهمي بأي حالة/بيانات. */
    public function seed_entitlement_row($id, array $row)
    {
        $merged = array_merge(['id' => $id, 'consumed_by_ledger_id' => null, 'consumed_at' => null], $row);
        $this->entitlement_rows[$id] = $merged;
        $key = ($merged['credit_cycle_id'] ?? '') . '|' . ($merged['event_id'] ?? '') . '|' . ($merged['source_guest_phone'] ?? '');
        $this->entitlement_unique_index[$key] = $id;
        if ($id >= $this->entitlement_next_id) {
            $this->entitlement_next_id = $id + 1;
        }
    }

    /** أداة اختبار: قراءة مباشرة لصف Entitlement دون المرور بـapply_where. */
    public function raw_entitlement_row($id)
    {
        return $this->entitlement_rows[$id] ?? null;
    }

    /** أداة اختبار: قراءة مباشرة لصف Ledger دون المرور بـapply_where. */
    public function raw_ledger_row($id)
    {
        return $this->ledger_rows[$id] ?? null;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Replacement_Consumption();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// API Token لازم لتجاوز فحص "لم يتم ضبط Cartat API Token" في المُنشئ.
$GLOBALS['__test_options']['pge_cartat_api_token']    = 'TEST-TOKEN-1234';
$GLOBALS['__test_options']['pge_cartat_country_code'] = '966';

// ── تحميل الملفات الحقيقية من المشروع (بلا أي تعديل عليها) ─────────────────

require_once __DIR__ . '/../includes/class-pge-invitation-credit-ledger.php';
require_once __DIR__ . '/../includes/class-pge-replacement-entitlements.php';
// Supervisor Invitation Delivery via Cartat — تنفيذ (Option B): class-cartat-
// handler.php أصبح يعتمد على PGE_Cartat_Transport داخلياً — يجب تحميلها قبله.
require_once __DIR__ . '/../includes/class-pge-cartat-transport.php';
require_once __DIR__ . '/../includes/class-cartat-handler.php';

// ── أدوات الاختبار ──────────────────────────────────────────────────────────

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

function reset_test_state()
{
    $GLOBALS['__test_remote_post_queue'] = [];
    $GLOBALS['__test_remote_post_calls'] = 0;
    $GLOBALS['__test_block_meta_key']    = null;
}

function seed_active_catalog_user($user_id, $cycle_id, $invitation_total = 10, $invitation_used = 0, $replacement_total = 0, $replacement_used = 0)
{
    $GLOBALS['__test_user_meta'][$user_id] = [
        '_mon_package_source'          => 'catalog',
        '_mon_package_status'          => 'active',
        '_mon_credit_cycle_id'         => $cycle_id,
        '_mon_invitation_credit_total' => $invitation_total,
        '_mon_invitation_credit_used'  => $invitation_used,
        '_mon_replacement_credit_total' => $replacement_total,
        '_mon_replacement_credit_used'  => $replacement_used,
    ];
}

function seed_catalog_queue($event_id, $subscriber_user_id, $credit_cycle_id, $phones, $credit_limit, $credit_type = 'primary')
{
    $queue = [
        'event_id'   => $event_id,
        'status'     => 'queued',
        'phones'     => array_values($phones),
        'guests_map' => [],
        'event_name' => 'مناسبة اختبار',
        'event_date' => '',
        'image_url'  => '',
        'event_url'  => 'https://example.test/event/' . $event_id . '/',
        'invite_code' => '',
        'offset'     => 0,
        'total'      => count($phones),
        'results'    => [],
        'created_at' => time(),
        'done_at'    => null,
        'cancel_reason' => null,
        'is_catalog'                => true,
        'subscriber_user_id'        => $subscriber_user_id,
        'credit_cycle_id'           => $credit_cycle_id,
        'credit_type'               => $credit_type,
        'invitation_credit_total'   => $credit_limit,
    ];
    update_option('pge_wa_queue_' . $event_id, $queue, false);
    return $queue;
}

/** يزرع N استحقاقات granted متتالية زمنياً (granted_at تصاعدي) لنفس (user,cycle). */
function seed_granted_entitlements($wpdb, $start_id, $user_id, $cycle_id, $count, $event_id_base = 9000)
{
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $id = $start_id + $i;
        $wpdb->seed_entitlement_row($id, [
            'user_id'            => $user_id,
            'credit_cycle_id'    => $cycle_id,
            'event_id'           => $event_id_base + $i,
            'source_guest_phone' => '05' . str_pad((string) $id, 8, '0', STR_PAD_LEFT),
            'source_ledger_id'   => 100000 + $id,
            'status'             => 'granted',
            'granted_at'         => sprintf('2026-01-01 00:%02d:00', $i),
            'created_at'         => sprintf('2026-01-01 00:%02d:00', $i),
            'updated_at'         => sprintf('2026-01-01 00:%02d:00', $i),
        ]);
        $ids[] = $id;
    }
    return $ids;
}

/** يزرع صف Ledger replacement/consumed جاهز مباشرةً (بلا مرور بـclaim_for_delivery). */
function seed_consumed_replacement_ledger_row($wpdb, $id, $user_id, $cycle_id, $event_id, $phone)
{
    $wpdb->seed_ledger_row($id, [
        'user_id'         => $user_id,
        'credit_cycle_id' => $cycle_id,
        'event_id'        => $event_id,
        'guest_phone'     => $phone,
        'credit_type'     => 'replacement',
        'status'          => 'consumed',
        'consumed_at'     => '2026-01-01 00:00:00',
    ]);
}

$handler = new Mon_Cartat_Handler();

// ════════════════════════════════════════════════════════════════════════
// القسم 1: اختبارات بدء الـQueue (1-14)
// ════════════════════════════════════════════════════════════════════════

echo "=== المرحلة 4C: Queue-start (1-14) ===\n";

// 1) primary بدون credit_type → السلوك الحالي تماماً.
reset_test_state();
$event_1 = 9001; $sub_1 = 7001;
$GLOBALS['__test_posts'][$event_1] = ['post_title' => 'ح1', 'post_author' => $sub_1];
$GLOBALS['__test_invited_phones'][$event_1] = ['0501111111'];
seed_active_catalog_user($sub_1, 'CYCLE-1', 10, 0);
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_1];
$resp_1 = call_ajax_queue_start($handler);
check_true('1. primary بلا credit_type ينجح', $resp_1['success'] === true);
check('1. credit_type المخزَّن = primary', get_option('pge_wa_queue_' . $event_1)['credit_type'] ?? null, 'primary');

// 2) credit_type=primary صريح → نفس السلوك.
reset_test_state();
$event_2 = 9002; $sub_2 = 7002;
$GLOBALS['__test_posts'][$event_2] = ['post_title' => 'ح2', 'post_author' => $sub_2];
$GLOBALS['__test_invited_phones'][$event_2] = ['0502222222'];
seed_active_catalog_user($sub_2, 'CYCLE-2', 10, 0);
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_2, 'credit_type' => 'primary'];
$resp_2 = call_ajax_queue_start($handler);
check_true('2. primary صريح ينجح', $resp_2['success'] === true);

// 3) credit_type غير صالح → رفض، لا Queue.
reset_test_state();
$event_3 = 9003; $sub_3 = 7003;
$GLOBALS['__test_posts'][$event_3] = ['post_title' => 'ح3', 'post_author' => $sub_3];
$GLOBALS['__test_invited_phones'][$event_3] = ['0503333333'];
seed_active_catalog_user($sub_3, 'CYCLE-3', 10, 0);
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_3, 'credit_type' => 'foo'];
$resp_3 = call_ajax_queue_start($handler);
check_true('3. credit_type غير صالح يُرفَض', $resp_3['success'] === false);
check('3. لا Queue تُنشأ', get_option('pge_wa_queue_' . $event_3, 'NONE'), 'NONE');

// 4) replacement مع package_limit=0 → رفض، لا Queue.
reset_test_state();
$event_4 = 9004; $sub_4 = 7004;
$GLOBALS['__test_posts'][$event_4] = ['post_title' => 'ح4', 'post_author' => $sub_4];
$GLOBALS['__test_invited_phones'][$event_4] = ['0504444444'];
seed_active_catalog_user($sub_4, 'CYCLE-4', 10, 0, 0, 0); // replacement_total=0
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_4, 'credit_type' => 'replacement'];
$resp_4 = call_ajax_queue_start($handler);
check_true('4. replacement مع package_limit=0 يُرفَض', $resp_4['success'] === false);
check('4. لا Queue تُنشأ', get_option('pge_wa_queue_' . $event_4, 'NONE'), 'NONE');

// 5) replacement بلا Entitlements ممنوحة → رفض no_available_entitlement.
reset_test_state();
$event_5 = 9005; $sub_5 = 7005;
$GLOBALS['__test_posts'][$event_5] = ['post_title' => 'ح5', 'post_author' => $sub_5];
$GLOBALS['__test_invited_phones'][$event_5] = ['0505555555'];
seed_active_catalog_user($sub_5, 'CYCLE-5', 10, 0, 5, 0); // package_limit=5 لكن بلا استحقاقات ممنوحة
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_5, 'credit_type' => 'replacement'];
$resp_5 = call_ajax_queue_start($handler);
check_true('5. replacement بلا استحقاقات يُرفَض', $resp_5['success'] === false);
check('5. لا Queue تُنشأ', get_option('pge_wa_queue_' . $event_5, 'NONE'), 'NONE');

// 6) replacement مع 1 استحقاق + package_limit كبير → effective_limit=1، ينجح.
reset_test_state();
$event_6 = 9006; $sub_6 = 7006;
$GLOBALS['__test_posts'][$event_6] = ['post_title' => 'ح6', 'post_author' => $sub_6];
$GLOBALS['__test_invited_phones'][$event_6] = ['0506666666'];
seed_active_catalog_user($sub_6, 'CYCLE-6', 10, 0, 100, 0);
seed_granted_entitlements($wpdb, 6100, $sub_6, 'CYCLE-6', 1);
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_6, 'credit_type' => 'replacement'];
$resp_6 = call_ajax_queue_start($handler);
check_true('6. replacement مع استحقاق واحد ينجح', $resp_6['success'] === true);
check('6. invitation_credit_total (effective_limit) = 1', get_option('pge_wa_queue_' . $event_6)['invitation_credit_total'] ?? null, 1);

// 7) replacement مع 2 استحقاق + package_limit=1 → effective_limit=1.
reset_test_state();
$event_7 = 9007; $sub_7 = 7007;
$GLOBALS['__test_posts'][$event_7] = ['post_title' => 'ح7', 'post_author' => $sub_7];
$GLOBALS['__test_invited_phones'][$event_7] = ['0507777777'];
seed_active_catalog_user($sub_7, 'CYCLE-7', 10, 0, 1, 0);
seed_granted_entitlements($wpdb, 7100, $sub_7, 'CYCLE-7', 2);
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_7, 'credit_type' => 'replacement'];
$resp_7 = call_ajax_queue_start($handler);
check_true('7. replacement مع استحقاقين وpackage_limit=1 ينجح', $resp_7['success'] === true);
check('7. effective_limit = MIN(1,2) = 1', get_option('pge_wa_queue_' . $event_7)['invitation_credit_total'] ?? null, 1);

// 8) replacement لمستخدم Legacy (بلا Catalog) → رفض صريح.
reset_test_state();
$event_8 = 9008; $sub_8 = 7008;
$GLOBALS['__test_posts'][$event_8] = ['post_title' => 'ح8', 'post_author' => $sub_8];
$GLOBALS['__test_invited_phones'][$event_8] = ['0508888888'];
$GLOBALS['__test_user_meta'][$sub_8] = ['_mon_package_status' => 'active']; // بلا _mon_package_source=catalog
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_8, 'credit_type' => 'replacement'];
$resp_8 = call_ajax_queue_start($handler);
check_true('8. replacement لمستخدم Legacy يُرفَض', $resp_8['success'] === false);

// 9) replacement بلا مالك صالح للمناسبة (post_author=0) → رفض.
reset_test_state();
$event_9q = 9009;
$GLOBALS['__test_posts'][$event_9q] = ['post_title' => 'ح9', 'post_author' => 0];
$GLOBALS['__test_invited_phones'][$event_9q] = ['0509999999'];
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_9q, 'credit_type' => 'replacement'];
$resp_9q = call_ajax_queue_start($handler);
check_true('9. replacement بلا مالك صالح يُرفَض', $resp_9q['success'] === false);

// 10) replacement مع Catalog لكن غير نشط → رفض (نفس رسالة primary).
reset_test_state();
$event_10 = 9010; $sub_10 = 7010;
$GLOBALS['__test_posts'][$event_10] = ['post_title' => 'ح10', 'post_author' => $sub_10];
$GLOBALS['__test_invited_phones'][$event_10] = ['0510000000'];
$GLOBALS['__test_user_meta'][$sub_10] = ['_mon_package_source' => 'catalog', '_mon_package_status' => 'expired'];
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_10, 'credit_type' => 'replacement'];
$resp_10 = call_ajax_queue_start($handler);
check_true('10. replacement مع اشتراك منتهٍ يُرفَض', $resp_10['success'] === false);

// 11) replacement مع credit_cycle_id فارغ → رفض.
reset_test_state();
$event_11 = 9011; $sub_11 = 7011;
$GLOBALS['__test_posts'][$event_11] = ['post_title' => 'ح11', 'post_author' => $sub_11];
$GLOBALS['__test_invited_phones'][$event_11] = ['0511111111'];
$GLOBALS['__test_user_meta'][$sub_11] = ['_mon_package_source' => 'catalog', '_mon_package_status' => 'active', '_mon_credit_cycle_id' => ''];
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_11, 'credit_type' => 'replacement'];
$resp_11 = call_ajax_queue_start($handler);
check_true('11. replacement بلا credit_cycle_id يُرفَض', $resp_11['success'] === false);

// 12) replacement مع used_or_reserved = effective_limit فعلاً → رفض (لا شيء متاح).
reset_test_state();
$event_12 = 9012; $sub_12 = 7012;
$GLOBALS['__test_posts'][$event_12] = ['post_title' => 'ح12', 'post_author' => $sub_12];
$GLOBALS['__test_invited_phones'][$event_12] = ['0512222222'];
seed_active_catalog_user($sub_12, 'CYCLE-12', 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 12100, $sub_12, 'CYCLE-12', 1); // granted=1
seed_consumed_replacement_ledger_row($wpdb, 12200, $sub_12, 'CYCLE-12', 5555, '0512999999'); // consumed=1 بالفعل
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_12, 'credit_type' => 'replacement'];
$resp_12 = call_ajax_queue_start($handler);
check_true('12. replacement مع used_or_reserved=effective_limit يُرفَض', $resp_12['success'] === false);

// 13) لا يُخزَّن entitlement_id في الـQueue إطلاقاً (فحص بنيوي).
reset_test_state();
$event_13 = 9013; $sub_13 = 7013;
$GLOBALS['__test_posts'][$event_13] = ['post_title' => 'ح13', 'post_author' => $sub_13];
$GLOBALS['__test_invited_phones'][$event_13] = ['0513333333'];
seed_active_catalog_user($sub_13, 'CYCLE-13', 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 13100, $sub_13, 'CYCLE-13', 1);
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_13, 'credit_type' => 'replacement'];
call_ajax_queue_start($handler);
$queue_13 = get_option('pge_wa_queue_' . $event_13);
check_true('13. لا مفتاح entitlement_id في بنية الـQueue', !array_key_exists('entitlement_id', $queue_13));

// 14) الـQueue تحمل credit_type=replacement فعلياً في الحقل المخزَّن.
check('14. credit_type المخزَّن = replacement', get_option('pge_wa_queue_' . $event_13)['credit_type'] ?? null, 'replacement');

// ════════════════════════════════════════════════════════════════════════
// القسم 2: التزامن (15-18)
// ════════════════════════════════════════════════════════════════════════

echo "\n=== المرحلة 4C: التزامن (15-18) ===\n";

// 15) claim_oldest_granted_for_ledger مرتين بنفس ledger_id → Idempotent.
reset_test_state();
$user_15 = 7015; $cycle_15 = 'CYCLE-15';
seed_granted_entitlements($wpdb, 15100, $user_15, $cycle_15, 1);
seed_consumed_replacement_ledger_row($wpdb, 15200, $user_15, $cycle_15, 6001, '0515111111');
$r1_15 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_15, $cycle_15, 15200);
$r2_15 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_15, $cycle_15, 15200);
check('15. الاستدعاء الأول: consumed', $r1_15['result'] ?? null, 'consumed');
check('15. الاستدعاء الثاني (نفس ledger_id): already_linked', $r2_15['result'] ?? null, 'already_linked');
check('15. نفس id الاستحقاق في الحالتين', $r2_15['id'] ?? null, $r1_15['id'] ?? null);

// 16) قفل الحجز الذري محجوز مسبقاً أثناء دفعة Cron replacement → تخطٍّ آمن، لا claim.
reset_test_state();
$event_16 = 9016; $sub_16 = 7016; $cycle_16 = 'CYCLE-16';
seed_active_catalog_user($sub_16, $cycle_16, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 16100, $sub_16, $cycle_16, 1);
seed_catalog_queue($event_16, $sub_16, $cycle_16, ['0516111111'], 1, 'replacement');
$rep_lock_16 = PGE_Replacement_Entitlements::build_replacement_credit_lock_name($sub_16, $cycle_16);
$wpdb->held_locks[$rep_lock_16] = true; // محاكاة "مشغول من عملية أخرى"
$handler->cron_process_queue($event_16);
unset($wpdb->held_locks[$rep_lock_16]);
$queue_16_after = get_option('pge_wa_queue_' . $event_16);
check('16. نتيجة الهاتف ledger_error عند تعذّر القفل', $queue_16_after['results']['0516111111']['status'] ?? null, 'ledger_error');
check('16. لا أي استدعاء لكارتات', $GLOBALS['__test_remote_post_calls'], 0);

// 17) claim_oldest_granted_for_ledger مع القفل محجوز مسبقاً → error lock_not_acquired.
reset_test_state();
$user_17 = 7017; $cycle_17 = 'CYCLE-17';
seed_granted_entitlements($wpdb, 17100, $user_17, $cycle_17, 1);
seed_consumed_replacement_ledger_row($wpdb, 17200, $user_17, $cycle_17, 6002, '0517111111');
$rep_lock_17 = PGE_Replacement_Entitlements::build_replacement_credit_lock_name($user_17, $cycle_17);
$wpdb->held_locks[$rep_lock_17] = true;
$r_17 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_17, $cycle_17, 17200);
unset($wpdb->held_locks[$rep_lock_17]);
check('17. تعذّر القفل → error', $r_17['result'] ?? null, 'error');
check('17. السبب lock_not_acquired', $r_17['reason'] ?? null, 'lock_not_acquired');

// 18) أسماء أقفال مختلفة لدورتين مختلفتين لا تتعارضان (استقلال تام).
$name_18a = PGE_Replacement_Entitlements::build_replacement_credit_lock_name(1, 'A');
$name_18b = PGE_Replacement_Entitlements::build_replacement_credit_lock_name(1, 'B');
check_true('18. أسماء أقفال مختلفة لدورتين مختلفتين', $name_18a !== $name_18b);

// ════════════════════════════════════════════════════════════════════════
// القسم 3: Queue/Cron (19-28)
// ════════════════════════════════════════════════════════════════════════

echo "\n=== المرحلة 4C: Queue/Cron (19-28) ===\n";

// 19) replacement claimed + accepted → Ledger consumed + Entitlement consumed مربوط.
reset_test_state();
$event_19 = 9019; $sub_19 = 7019; $cycle_19 = 'CYCLE-19';
seed_active_catalog_user($sub_19, $cycle_19, 10, 0, 5, 0);
$ent_ids_19 = seed_granted_entitlements($wpdb, 19100, $sub_19, $cycle_19, 1);
seed_catalog_queue($event_19, $sub_19, $cycle_19, ['0519111111'], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-19'])];
$handler->cron_process_queue($event_19);
$queue_19_after = get_option('pge_wa_queue_' . $event_19);
check('19. نتيجة الهاتف sent', $queue_19_after['results']['0519111111']['status'] ?? null, 'sent');
$ledger_entry_19 = PGE_Invitation_Credit_Ledger::find_entry($cycle_19, $event_19, '0519111111', 'replacement');
check('19. صف Ledger أصبح consumed', $ledger_entry_19['status'] ?? null, 'consumed');
$ent_19_after = $wpdb->raw_entitlement_row($ent_ids_19[0]);
check('19. الاستحقاق أصبح consumed', $ent_19_after['status'] ?? null, 'consumed');
check('19. consumed_by_ledger_id مطابق لصف الـLedger', $ent_19_after['consumed_by_ledger_id'] ?? null, $ledger_entry_19['id'] ?? null);

// 20) replacement rejected (Cartat رفض صريح) → Ledger failed، الاستحقاق يبقى granted.
reset_test_state();
$event_20 = 9020; $sub_20 = 7020; $cycle_20 = 'CYCLE-20';
seed_active_catalog_user($sub_20, $cycle_20, 10, 0, 5, 0);
$ent_ids_20 = seed_granted_entitlements($wpdb, 20100, $sub_20, $cycle_20, 1);
seed_catalog_queue($event_20, $sub_20, $cycle_20, ['0520111111'], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'error', 'message' => 'invalid number'])];
$handler->cron_process_queue($event_20);
$ledger_entry_20 = PGE_Invitation_Credit_Ledger::find_entry($cycle_20, $event_20, '0520111111', 'replacement');
check('20. صف Ledger أصبح failed', $ledger_entry_20['status'] ?? null, 'failed');
check('20. الاستحقاق يبقى granted', $wpdb->raw_entitlement_row($ent_ids_20[0])['status'] ?? null, 'granted');

// 21) replacement transport_error → Ledger يبقى reserved، الاستحقاق يبقى granted.
reset_test_state();
$event_21c = 9021; $sub_21 = 7021; $cycle_21 = 'CYCLE-21';
seed_active_catalog_user($sub_21, $cycle_21, 10, 0, 5, 0);
$ent_ids_21 = seed_granted_entitlements($wpdb, 21100, $sub_21, $cycle_21, 1);
seed_catalog_queue($event_21c, $sub_21, $cycle_21, ['0521111111'], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = new WP_Error('http_request_failed', 'timeout');
$handler->cron_process_queue($event_21c);
$ledger_entry_21 = PGE_Invitation_Credit_Ledger::find_entry($cycle_21, $event_21c, '0521111111', 'replacement');
check('21. صف Ledger يبقى reserved', $ledger_entry_21['status'] ?? null, 'reserved');
check('21. الاستحقاق يبقى granted', $wpdb->raw_entitlement_row($ent_ids_21[0])['status'] ?? null, 'granted');

// 22) already_consumed → تخطٍّ فوري، لا استهلاك ثانٍ للاستحقاق.
reset_test_state();
$event_22c = 9022; $sub_22 = 7022; $cycle_22 = 'CYCLE-22';
seed_active_catalog_user($sub_22, $cycle_22, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 22100, $sub_22, $cycle_22, 1);
$pre_claim_22 = PGE_Invitation_Credit_Ledger::claim_for_delivery($sub_22, $cycle_22, $event_22c, '0522111111', 'replacement', 1);
PGE_Invitation_Credit_Ledger::mark_consumed_with_token($pre_claim_22['id'], $pre_claim_22['attempt_token']);
seed_catalog_queue($event_22c, $sub_22, $cycle_22, ['0522111111'], 1, 'replacement');
$handler->cron_process_queue($event_22c);
$queue_22_after = get_option('pge_wa_queue_' . $event_22c);
check('22. نتيجة الهاتف skipped_already_consumed', $queue_22_after['results']['0522111111']['status'] ?? null, 'skipped_already_consumed');
check('22. لا أي استدعاء لكارتات', $GLOBALS['__test_remote_post_calls'], 0);

// 23) in_progress → تخطٍّ فوري.
reset_test_state();
$event_23c = 9023; $sub_23 = 7023; $cycle_23 = 'CYCLE-23';
seed_active_catalog_user($sub_23, $cycle_23, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 23100, $sub_23, $cycle_23, 1);
PGE_Invitation_Credit_Ledger::claim_for_delivery($sub_23, $cycle_23, $event_23c, '0523111111', 'replacement', 1); // reserved نشط
seed_catalog_queue($event_23c, $sub_23, $cycle_23, ['0523111111'], 1, 'replacement');
$handler->cron_process_queue($event_23c);
$queue_23_after = get_option('pge_wa_queue_' . $event_23c);
check('23. نتيجة الهاتف skipped_in_progress', $queue_23_after['results']['0523111111']['status'] ?? null, 'skipped_in_progress');

// 24) لا استحقاق متاح أثناء تنفيذ Cron (استُهلك كل الرصيد بين ajax وcron) → skipped_no_entitlement.
reset_test_state();
$event_24c = 9024; $sub_24 = 7024; $cycle_24 = 'CYCLE-24';
seed_active_catalog_user($sub_24, $cycle_24, 10, 0, 1, 0);
seed_granted_entitlements($wpdb, 24100, $sub_24, $cycle_24, 1); // استحقاق واحد فقط
seed_consumed_replacement_ledger_row($wpdb, 24200, $sub_24, $cycle_24, 6003, '0524999999'); // استُهلك بالفعل
seed_catalog_queue($event_24c, $sub_24, $cycle_24, ['0524111111'], 1, 'replacement');
$handler->cron_process_queue($event_24c);
$queue_24_after = get_option('pge_wa_queue_' . $event_24c);
check('24. نتيجة الهاتف skipped_no_entitlement', $queue_24_after['results']['0524111111']['status'] ?? null, 'skipped_no_entitlement');
check('24. لا أي استدعاء لكارتات', $GLOBALS['__test_remote_post_calls'], 0);

// 25) credit_cycle_changed قبل Cron لـreplacement → cancelled، بلا استهلاك.
reset_test_state();
$event_25c = 9025; $sub_25 = 7025;
$GLOBALS['__test_user_meta'][$sub_25] = ['_mon_credit_cycle_id' => 'NEW-CYCLE-25'];
seed_catalog_queue($event_25c, $sub_25, 'OLD-CYCLE-25', ['0525111111'], 1, 'replacement');
$handler->cron_process_queue($event_25c);
$queue_25_after = get_option('pge_wa_queue_' . $event_25c);
check('25. status أصبحت cancelled', $queue_25_after['status'] ?? null, 'cancelled');
check('25. cancel_reason = credit_cycle_changed', $queue_25_after['cancel_reason'] ?? null, 'credit_cycle_changed');
check('25. لا أي استدعاء لكارتات', $GLOBALS['__test_remote_post_calls'], 0);

// 26) primary لا يتأثر إطلاقاً بمنطق replacement الجديد (Regression سريع داخل هذا الملف).
reset_test_state();
$event_26c = 9026; $sub_26 = 7026; $cycle_26 = 'CYCLE-26';
seed_active_catalog_user($sub_26, $cycle_26, 10, 0);
seed_catalog_queue($event_26c, $sub_26, $cycle_26, ['0526111111'], 10, 'primary');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-26'])];
$handler->cron_process_queue($event_26c);
$ledger_entry_26 = PGE_Invitation_Credit_Ledger::find_entry($cycle_26, $event_26c, '0526111111', 'primary');
check('26. صف Ledger primary أصبح consumed كالمعتاد', $ledger_entry_26['status'] ?? null, 'consumed');
check('26. _mon_invitation_credit_used تحدَّث', (int) get_user_meta($sub_26, '_mon_invitation_credit_used', true), 1);

// 27) sync_replacement_credit_used لا يُستدعى لصف primary ناجح.
check('27. _mon_replacement_credit_used لم يُكتب لصف primary', get_user_meta($sub_26, '_mon_replacement_credit_used', true), 0);

// 28) Legacy queue (is_catalog=false) لا يتأثر بمنطق replacement الجديد إطلاقاً.
reset_test_state();
$event_28c = 9028; $sub_28 = 7028;
$queue_28 = [
    'event_id' => $event_28c, 'status' => 'queued', 'phones' => ['0528111111'],
    'guests_map' => [], 'event_name' => 'Legacy', 'event_date' => '',
    'image_url' => '', 'event_url' => 'https://example.test/event/' . $event_28c . '/',
    'invite_code' => '', 'offset' => 0, 'total' => 1, 'results' => [],
    'created_at' => time(), 'done_at' => null, 'cancel_reason' => null,
    'is_catalog' => false, 'subscriber_user_id' => $sub_28,
    'credit_cycle_id' => '', 'credit_type' => 'replacement', 'invitation_credit_total' => 0,
];
update_option('pge_wa_queue_' . $event_28c, $queue_28, false);
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-28'])];
$handler->cron_process_queue($event_28c);
$queue_28_after = get_option('pge_wa_queue_' . $event_28c);
check('28. Legacy (is_catalog=false) يُرسِل مباشرةً بلا أي منطق Ledger/Entitlements', $queue_28_after['results']['0528111111']['status'] ?? null, 'sent');

// ════════════════════════════════════════════════════════════════════════
// القسم 4: اختيار الاستحقاق (Entitlement selection) (29-36)
// ════════════════════════════════════════════════════════════════════════

echo "\n=== المرحلة 4C: اختيار الاستحقاق (29-36) ===\n";

// 29) FIFO: أقدم استحقاق (granted_at الأصغر) يُختار أولاً.
reset_test_state();
$user_29 = 7029; $cycle_29 = 'CYCLE-29';
$wpdb->seed_entitlement_row(29101, ['user_id' => $user_29, 'credit_cycle_id' => $cycle_29, 'event_id' => 1, 'source_guest_phone' => '0529000001', 'source_ledger_id' => 1, 'status' => 'granted', 'granted_at' => '2026-01-05 00:00:00', 'created_at' => '2026-01-05', 'updated_at' => '2026-01-05']);
$wpdb->seed_entitlement_row(29102, ['user_id' => $user_29, 'credit_cycle_id' => $cycle_29, 'event_id' => 2, 'source_guest_phone' => '0529000002', 'source_ledger_id' => 2, 'status' => 'granted', 'granted_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
seed_consumed_replacement_ledger_row($wpdb, 29200, $user_29, $cycle_29, 6004, '0529111111');
$r_29 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_29, $cycle_29, 29200);
check('29. الاستحقاق الأقدم (29102) هو المُختار', $r_29['id'] ?? null, 29102);

// 30) FIFO tie-break: نفس granted_at، id الأصغر يُختار.
reset_test_state();
$user_30 = 7030; $cycle_30 = 'CYCLE-30';
$wpdb->seed_entitlement_row(30105, ['user_id' => $user_30, 'credit_cycle_id' => $cycle_30, 'event_id' => 1, 'source_guest_phone' => '0530000001', 'source_ledger_id' => 1, 'status' => 'granted', 'granted_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
$wpdb->seed_entitlement_row(30103, ['user_id' => $user_30, 'credit_cycle_id' => $cycle_30, 'event_id' => 2, 'source_guest_phone' => '0530000002', 'source_ledger_id' => 2, 'status' => 'granted', 'granted_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
seed_consumed_replacement_ledger_row($wpdb, 30200, $user_30, $cycle_30, 6005, '0530111111');
$r_30 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_30, $cycle_30, 30200);
check('30. عند تساوي granted_at، id الأصغر (30103) يُختار', $r_30['id'] ?? null, 30103);

// 31) ledger_id غير موجود إطلاقاً → mark_consumed الداخلي يرفض.
reset_test_state();
$user_31 = 7031; $cycle_31 = 'CYCLE-31';
seed_granted_entitlements($wpdb, 31100, $user_31, $cycle_31, 1);
$r_31 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_31, $cycle_31, 999999);
check('31. ledger_id غير موجود → error', $r_31['result'] ?? null, 'error');
check('31. السبب consumed_by_ledger_not_found', $r_31['reason'] ?? null, 'consumed_by_ledger_not_found');

// 32) صف Ledger موجود لكن credit_type=primary → رفض consumed_by_not_replacement.
reset_test_state();
$user_32 = 7032; $cycle_32 = 'CYCLE-32';
seed_granted_entitlements($wpdb, 32100, $user_32, $cycle_32, 1);
$wpdb->seed_ledger_row(32200, ['user_id' => $user_32, 'credit_cycle_id' => $cycle_32, 'event_id' => 1, 'guest_phone' => '0532111111', 'credit_type' => 'primary', 'status' => 'consumed']);
$r_32 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_32, $cycle_32, 32200);
check('32. صف primary → error', $r_32['result'] ?? null, 'error');
check('32. السبب consumed_by_not_replacement', $r_32['reason'] ?? null, 'consumed_by_not_replacement');

// 33) صف Ledger replacement لكن status != consumed → رفض consumed_by_not_consumed.
reset_test_state();
$user_33 = 7033; $cycle_33 = 'CYCLE-33';
seed_granted_entitlements($wpdb, 33100, $user_33, $cycle_33, 1);
$wpdb->seed_ledger_row(33200, ['user_id' => $user_33, 'credit_cycle_id' => $cycle_33, 'event_id' => 1, 'guest_phone' => '0533111111', 'credit_type' => 'replacement', 'status' => 'reserved']);
$r_33 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_33, $cycle_33, 33200);
check('33. صف reserved (ليس consumed) → error', $r_33['result'] ?? null, 'error');
check('33. السبب consumed_by_not_consumed', $r_33['reason'] ?? null, 'consumed_by_not_consumed');

// 34) لا استحقاق granted متاح إطلاقاً → no_entitlement_available.
reset_test_state();
$user_34 = 7034; $cycle_34 = 'CYCLE-34';
seed_consumed_replacement_ledger_row($wpdb, 34200, $user_34, $cycle_34, 6006, '0534111111');
$r_34 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_34, $cycle_34, 34200);
check('34. بلا أي استحقاق granted → error', $r_34['result'] ?? null, 'error');
check('34. السبب no_entitlement_available', $r_34['reason'] ?? null, 'no_entitlement_available');

// 35) الاستهلاك الناجح يُحدّث consumed_by_ledger_id على الاستحقاق الصحيح فقط.
reset_test_state();
$user_35 = 7035; $cycle_35 = 'CYCLE-35';
$ids_35 = seed_granted_entitlements($wpdb, 35100, $user_35, $cycle_35, 2);
seed_consumed_replacement_ledger_row($wpdb, 35200, $user_35, $cycle_35, 6007, '0535111111');
$r_35 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_35, $cycle_35, 35200);
$other_id_35 = ($r_35['id'] === $ids_35[0]) ? $ids_35[1] : $ids_35[0];
check('35. الاستحقاق المُستهلَك مرتبط بالـledger الصحيح', $wpdb->raw_entitlement_row($r_35['id'])['consumed_by_ledger_id'] ?? null, 35200);
check('35. الاستحقاق الآخر يبقى granted دون تأثير', $wpdb->raw_entitlement_row($other_id_35)['status'] ?? null, 'granted');

// 36) استحقاق من مستخدم/دورة مختلفة لا يُختار إطلاقاً (عزل صارم).
reset_test_state();
$user_36a = 7036; $user_36b = 7037; $cycle_36 = 'CYCLE-36';
seed_granted_entitlements($wpdb, 36100, $user_36b, $cycle_36, 1); // ينتمي لمستخدم آخر
seed_consumed_replacement_ledger_row($wpdb, 36200, $user_36a, $cycle_36, 6008, '0536111111');
$r_36 = PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_36a, $cycle_36, 36200);
check('36. لا استحقاق لمستخدم آخر يُختار خطأً → error', $r_36['result'] ?? null, 'error');
check('36. السبب no_entitlement_available', $r_36['reason'] ?? null, 'no_entitlement_available');

// ════════════════════════════════════════════════════════════════════════
// القسم 5: العدّاد (Counter) (37-42)
// ════════════════════════════════════════════════════════════════════════

echo "\n=== المرحلة 4C: العدّاد (37-42) ===\n";

// 37) sync_replacement_credit_used عبر مسار Cron الحقيقي يكتب COUNT الفعلي.
reset_test_state();
$event_37 = 9037; $sub_37 = 7038; $cycle_37 = 'CYCLE-37';
seed_active_catalog_user($sub_37, $cycle_37, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 37100, $sub_37, $cycle_37, 1);
seed_catalog_queue($event_37, $sub_37, $cycle_37, ['0537111111'], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-37'])];
$handler->cron_process_queue($event_37);
check('37. _mon_replacement_credit_used = 1 بعد استهلاك ناجح واحد', (int) get_user_meta($sub_37, '_mon_replacement_credit_used', true), 1);

// 38) استدعاء sync_replacement_credit_used مرتين (عبر already_linked لاحقاً) لا يزيد العدّاد تراكمياً.
reset_test_state();
$user_38 = 7039; $cycle_38 = 'CYCLE-38';
seed_granted_entitlements($wpdb, 38100, $user_38, $cycle_38, 1);
seed_consumed_replacement_ledger_row($wpdb, 38200, $user_38, $cycle_38, 6009, '0538111111');
$GLOBALS['__test_user_meta'][$user_38]['_mon_credit_cycle_id'] = $cycle_38;
$reflection_38 = new ReflectionMethod('Mon_Cartat_Handler', 'sync_replacement_credit_used');
$reflection_38->setAccessible(true);
PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_38, $cycle_38, 38200);
$reflection_38->invoke($handler, $user_38, $cycle_38);
$first_38 = (int) get_user_meta($user_38, '_mon_replacement_credit_used', true);
$reflection_38->invoke($handler, $user_38, $cycle_38);
$second_38 = (int) get_user_meta($user_38, '_mon_replacement_credit_used', true);
check('38. القيمة الأولى = 1', $first_38, 1);
check('38. استدعاء ثانٍ لا يغيّر القيمة (يبقى 1، لا تراكم)', $second_38, 1);

// 39) sync_replacement_credit_used لا يكتب على دورة غير سارية (تغيّرت).
reset_test_state();
$user_39 = 7040; $cycle_39 = 'CYCLE-39';
$GLOBALS['__test_user_meta'][$user_39]['_mon_credit_cycle_id'] = 'DIFFERENT-CYCLE';
$reflection_39 = new ReflectionMethod('Mon_Cartat_Handler', 'sync_replacement_credit_used');
$reflection_39->setAccessible(true);
$reflection_39->invoke($handler, $user_39, $cycle_39); // دورة مختلفة عن الحالية
check('39. لا كتابة على _mon_replacement_credit_used لدورة غير سارية', get_user_meta($user_39, '_mon_replacement_credit_used', true), '');

// 40) sync_replacement_credit_used لا يلمس _mon_replacement_credit_total إطلاقاً.
reset_test_state();
$user_40 = 7041; $cycle_40 = 'CYCLE-40';
$GLOBALS['__test_user_meta'][$user_40] = ['_mon_credit_cycle_id' => $cycle_40, '_mon_replacement_credit_total' => 99];
seed_granted_entitlements($wpdb, 40100, $user_40, $cycle_40, 1);
seed_consumed_replacement_ledger_row($wpdb, 40200, $user_40, $cycle_40, 6010, '0540111111');
PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_40, $cycle_40, 40200);
$reflection_40 = new ReflectionMethod('Mon_Cartat_Handler', 'sync_replacement_credit_used');
$reflection_40->setAccessible(true);
$reflection_40->invoke($handler, $user_40, $cycle_40);
check('40. _mon_replacement_credit_total يبقى 99 دون أي تغيير', (int) get_user_meta($user_40, '_mon_replacement_credit_total', true), 99);

// 41) فشل update_user_meta الصامت يُسجَّل ولا يُلغي حالة consumed.
reset_test_state();
$event_41 = 9041; $sub_41 = 7042; $cycle_41 = 'CYCLE-41';
seed_active_catalog_user($sub_41, $cycle_41, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 41100, $sub_41, $cycle_41, 1);
seed_catalog_queue($event_41, $sub_41, $cycle_41, ['0541111111'], 1, 'replacement');
$log_file_41 = WP_CONTENT_DIR . '/cartat-webhook.log';
file_put_contents($log_file_41, '');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-41'])];
$GLOBALS['__test_block_meta_key'] = '_mon_replacement_credit_used';
$handler->cron_process_queue($event_41);
$GLOBALS['__test_block_meta_key'] = null;
$ledger_entry_41 = PGE_Invitation_Credit_Ledger::find_entry($cycle_41, $event_41, '0541111111', 'replacement');
check('41. صف Ledger يبقى consumed رغم فشل مزامنة العدّاد', $ledger_entry_41['status'] ?? null, 'consumed');
$log_contents_41 = file_exists($log_file_41) ? file_get_contents($log_file_41) : '';
check_true('41. تسجيل replacement_credit_used_sync_error في اللوغ', strpos($log_contents_41, 'replacement_credit_used_sync_error') !== false);

// 42) نجاحان متتاليان لمرسلتين مختلفتين يعطيان عدّاداً صحيحاً = 2.
reset_test_state();
$event_42 = 9042; $sub_42 = 7043; $cycle_42 = 'CYCLE-42';
seed_active_catalog_user($sub_42, $cycle_42, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 42100, $sub_42, $cycle_42, 2);
seed_catalog_queue($event_42, $sub_42, $cycle_42, ['0542111111', '0542222222'], 2, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-42A'])];
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-42B'])];
$handler->cron_process_queue($event_42);
check('42. _mon_replacement_credit_used = 2 بعد مرسلتين ناجحتين', (int) get_user_meta($sub_42, '_mon_replacement_credit_used', true), 2);

// ════════════════════════════════════════════════════════════════════════
// القسم 6: الإصلاح (Repair) (43-48)
// ════════════════════════════════════════════════════════════════════════

echo "\n=== المرحلة 4C: الإصلاح (43-48) ===\n";

// 43) reconcile على ledger_id مرتبط بالفعل → already_linked، لا تغيير.
reset_test_state();
$user_43 = 7044; $cycle_43 = 'CYCLE-43';
$ids_43 = seed_granted_entitlements($wpdb, 43100, $user_43, $cycle_43, 1);
seed_consumed_replacement_ledger_row($wpdb, 43200, $user_43, $cycle_43, 6011, '0543111111');
PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_43, $cycle_43, 43200); // ربط مسبق
$r_43 = $handler->reconcile_consumed_replacement_ledger(43200);
check('43. reconcile على ledger مرتبط بالفعل → already_linked', $r_43['result'] ?? null, 'already_linked');
check('43. entitlement_id مطابق للمرتبط سابقاً', $r_43['entitlement_id'] ?? null, $ids_43[0]);

// 44) reconcile على ledger_id غير موجود → error ledger_not_found.
reset_test_state();
$r_44 = $handler->reconcile_consumed_replacement_ledger(999998);
check('44. ledger_id غير موجود → error', $r_44['result'] ?? null, 'error');
check('44. السبب ledger_not_found', $r_44['reason'] ?? null, 'ledger_not_found');

// 45) reconcile على ledger_id credit_type=primary → error not_replacement.
reset_test_state();
$wpdb->seed_ledger_row(45200, ['user_id' => 7045, 'credit_cycle_id' => 'CYCLE-45', 'event_id' => 1, 'guest_phone' => '0545111111', 'credit_type' => 'primary', 'status' => 'consumed']);
$r_45 = $handler->reconcile_consumed_replacement_ledger(45200);
check('45. صف primary → error', $r_45['result'] ?? null, 'error');
check('45. السبب not_replacement', $r_45['reason'] ?? null, 'not_replacement');

// 46) reconcile على ledger_id status != consumed → error not_consumed.
reset_test_state();
$wpdb->seed_ledger_row(46200, ['user_id' => 7046, 'credit_cycle_id' => 'CYCLE-46', 'event_id' => 1, 'guest_phone' => '0546111111', 'credit_type' => 'replacement', 'status' => 'reserved']);
$r_46 = $handler->reconcile_consumed_replacement_ledger(46200);
check('46. صف reserved (ليس consumed) → error', $r_46['result'] ?? null, 'error');
check('46. السبب not_consumed', $r_46['reason'] ?? null, 'not_consumed');

// 47) reconcile ناجح فعلياً — يُصلح ربطاً كان مفقوداً ويستدعي مزامنة العدّاد.
reset_test_state();
$user_47 = 7047; $cycle_47 = 'CYCLE-47';
$GLOBALS['__test_user_meta'][$user_47]['_mon_credit_cycle_id'] = $cycle_47;
$ids_47 = seed_granted_entitlements($wpdb, 47100, $user_47, $cycle_47, 1);
seed_consumed_replacement_ledger_row($wpdb, 47200, $user_47, $cycle_47, 6012, '0547111111');
$r_47 = $handler->reconcile_consumed_replacement_ledger(47200);
check('47. reconcile ناجح → reconciled', $r_47['result'] ?? null, 'reconciled');
check('47. entitlement_id هو الاستحقاق المتاح الوحيد', $r_47['entitlement_id'] ?? null, $ids_47[0]);
check('47. الاستحقاق أصبح consumed فعلاً بعد الإصلاح', $wpdb->raw_entitlement_row($ids_47[0])['status'] ?? null, 'consumed');
check('47. _mon_replacement_credit_used تحدَّث بعد الإصلاح', (int) get_user_meta($user_47, '_mon_replacement_credit_used', true), 1);

// 48) reconcile على ledger_id بلا أي استحقاق granted متاح → error no_entitlement_available.
reset_test_state();
$user_48 = 7048; $cycle_48 = 'CYCLE-48';
seed_consumed_replacement_ledger_row($wpdb, 48200, $user_48, $cycle_48, 6013, '0548111111');
$r_48 = $handler->reconcile_consumed_replacement_ledger(48200);
check('48. بلا استحقاق متاح → error', $r_48['result'] ?? null, 'error');
check('48. السبب no_entitlement_available', $r_48['reason'] ?? null, 'no_entitlement_available');

// ════════════════════════════════════════════════════════════════════════
// القسم 7: إصلاح Blocker — failed لا يجوز أن يتجاوز بوابة التوفر (A-G)
// ════════════════════════════════════════════════════════════════════════
//
// السيناريو المُبلَّغ: Entitlement واحد → A يفشل (Ledger A=failed، الاستحقاق
// يبقى granted) → B ينجح ويستهلك الاستحقاق الوحيد → إعادة محاولة A كانت
// (قبل الإصلاح) تتجاوز بوابة التوفر لمجرّد وجود صف Ledger سابق لها (حتى لو
// failed)، فتنجح مع Cartat فعلياً بلا استحقاق متبقٍّ — Overbooking حقيقي.
// الإصلاح: status='failed' (ولا وجود أي صف) هما الحالتان الوحيدتان اللتان
// تمرّان عبر بوابة التوفر الذرية؛ consumed وreserved (بغضّ النظر عن Lease)
// تتجاوزانها دائماً إلى claim_for_delivery() مباشرة.

echo "\n=== المرحلة 4C: إصلاح Blocker — failed retry وبوابة التوفر (A-G) ===\n";

// A) استحقاق واحد: A يفشل، B ينجح ويستهلك الاستحقاق، إعادة محاولة A تُرفَض
// (no_available_entitlement أو limit_exceeded)، وCartat لا يُستدعى لـA إطلاقاً.
reset_test_state();
$user_A = 7100; $cycle_A = 'CYCLE-BLOCKER-A';
$event_A_shared = 9101; $phone_A = '0590000001';
$event_B_a = 9102; $phone_B_a = '0590000002';
seed_active_catalog_user($user_A, $cycle_A, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 91100, $user_A, $cycle_A, 1);
// A يفشل أولاً.
seed_catalog_queue($event_A_shared, $user_A, $cycle_A, [$phone_A], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'error', 'message' => 'bad number'])];
$handler->cron_process_queue($event_A_shared);
check('A. تمهيد: Ledger A أصبح failed', PGE_Invitation_Credit_Ledger::find_entry($cycle_A, $event_A_shared, $phone_A, 'replacement')['status'] ?? null, 'failed');
// B ينجح ويستهلك الاستحقاق الوحيد.
seed_catalog_queue($event_B_a, $user_A, $cycle_A, [$phone_B_a], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-A-B'])];
$handler->cron_process_queue($event_B_a);
check('A. تمهيد: Ledger B أصبح consumed', PGE_Invitation_Credit_Ledger::find_entry($cycle_A, $event_B_a, $phone_B_a, 'replacement')['status'] ?? null, 'consumed');
check('A. تمهيد: الاستحقاق الوحيد استُهلك', PGE_Replacement_Entitlements::count_consumed($user_A, $cycle_A), 1);
// إعادة محاولة A — بلا أي رد Cartat مُهيَّأ عمداً؛ إن استُدعي Cartat فعلياً
// سيستهلك الرد الافتراضي (نجاح) من الـFake، فنتحقق أدناه أنه لم يُستدعَ إطلاقاً.
$calls_before_retry_A = $GLOBALS['__test_remote_post_calls'];
seed_catalog_queue($event_A_shared, $user_A, $cycle_A, [$phone_A], 1, 'replacement');
$handler->cron_process_queue($event_A_shared);
$queue_A_retry = get_option('pge_wa_queue_' . $event_A_shared);
$status_A_retry = $queue_A_retry['results'][$phone_A]['status'] ?? null;
check_true('A. إعادة محاولة A تُرفَض (no_available_entitlement أو limit_exceeded)', in_array($status_A_retry, ['skipped_no_entitlement', 'skipped_limit_exceeded'], true));
check('A. Cartat لم يُستدعَ إطلاقاً لإعادة محاولة A', $GLOBALS['__test_remote_post_calls'], $calls_before_retry_A);
check('A. Ledger A يبقى failed (لم يتحوّل consumed زوراً)', PGE_Invitation_Credit_Ledger::find_entry($cycle_A, $event_A_shared, $phone_A, 'replacement')['status'] ?? null, 'failed');

// B) استحقاق واحد: A يفشل، لا إرسال آخر، إعادة محاولة A مسموحة وتنجح وتستهلك استحقاقاً واحداً بالضبط.
reset_test_state();
$user_B = 7101; $cycle_B = 'CYCLE-BLOCKER-B';
$event_B_shared = 9111; $phone_B = '0591000001';
seed_active_catalog_user($user_B, $cycle_B, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 91200, $user_B, $cycle_B, 1);
seed_catalog_queue($event_B_shared, $user_B, $cycle_B, [$phone_B], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'error', 'message' => 'bad number'])];
$handler->cron_process_queue($event_B_shared);
check('B. تمهيد: Ledger أصبح failed', PGE_Invitation_Credit_Ledger::find_entry($cycle_B, $event_B_shared, $phone_B, 'replacement')['status'] ?? null, 'failed');
seed_catalog_queue($event_B_shared, $user_B, $cycle_B, [$phone_B], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-B-retry'])];
$handler->cron_process_queue($event_B_shared);
$queue_B_after = get_option('pge_wa_queue_' . $event_B_shared);
check('B. إعادة المحاولة مسموحة وتنجح', $queue_B_after['results'][$phone_B]['status'] ?? null, 'sent');
check('B. Ledger أصبح consumed', PGE_Invitation_Credit_Ledger::find_entry($cycle_B, $event_B_shared, $phone_B, 'replacement')['status'] ?? null, 'consumed');
check('B. استحقاق واحد بالضبط استُهلك', PGE_Replacement_Entitlements::count_consumed($user_B, $cycle_B), 1);

// C) استحقاقان: A يفشل، B يستهلك واحداً، إعادة محاولة A مسموحة وتستخدم الاستحقاق المتبقي.
reset_test_state();
$user_C = 7102; $cycle_C = 'CYCLE-BLOCKER-C';
$event_A_c = 9121; $phone_A_c = '0592000001';
$event_B_c = 9122; $phone_B_c = '0592000002';
seed_active_catalog_user($user_C, $cycle_C, 10, 0, 5, 0);
$ent_ids_C = seed_granted_entitlements($wpdb, 91300, $user_C, $cycle_C, 2);
seed_catalog_queue($event_A_c, $user_C, $cycle_C, [$phone_A_c], 2, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'error', 'message' => 'bad number'])];
$handler->cron_process_queue($event_A_c);
check('C. تمهيد: Ledger A أصبح failed', PGE_Invitation_Credit_Ledger::find_entry($cycle_C, $event_A_c, $phone_A_c, 'replacement')['status'] ?? null, 'failed');
seed_catalog_queue($event_B_c, $user_C, $cycle_C, [$phone_B_c], 2, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-C-B'])];
$handler->cron_process_queue($event_B_c);
check('C. تمهيد: استحقاق واحد استُهلك (B)', PGE_Replacement_Entitlements::count_consumed($user_C, $cycle_C), 1);
$ledger_B_c = PGE_Invitation_Credit_Ledger::find_entry($cycle_C, $event_B_c, $phone_B_c, 'replacement');
seed_catalog_queue($event_A_c, $user_C, $cycle_C, [$phone_A_c], 2, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-C-A-retry'])];
$handler->cron_process_queue($event_A_c);
$queue_C_after = get_option('pge_wa_queue_' . $event_A_c);
check('C. إعادة محاولة A مسموحة وتنجح باستخدام الاستحقاق المتبقي', $queue_C_after['results'][$phone_A_c]['status'] ?? null, 'sent');
check('C. كلا الاستحقاقين استُهلكا الآن', PGE_Replacement_Entitlements::count_consumed($user_C, $cycle_C), 2);
$ledger_A_c = PGE_Invitation_Credit_Ledger::find_entry($cycle_C, $event_A_c, $phone_A_c, 'replacement');
// تحقّق دقيق من ترتيب FIFO الفعلي: B استهلك الاستحقاق الأقدم (91300، granted_at
// الأسبق) أولاً، ثم A (عند إعادة محاولتها لاحقاً) استهلكت الاستحقاق الآخر
// المتبقي (91301) بالذات — لا مجرّد التحقق من اختلاف id صفَّي الـLedger
// (ذلك يكون صحيحاً دوماً بداهةً لاختلاف الهاتف/المناسبة، ولا يُثبت شيئاً عن
// الاستحقاقات نفسها).
check('C. الاستحقاق الأقدم (91300) استهلكته B تحديداً', $wpdb->raw_entitlement_row($ent_ids_C[0])['consumed_by_ledger_id'] ?? null, $ledger_B_c['id'] ?? null);
check('C. الاستحقاق المتبقي (91301) استهلكته إعادة محاولة A تحديداً', $wpdb->raw_entitlement_row($ent_ids_C[1])['consumed_by_ledger_id'] ?? null, $ledger_A_c['id'] ?? null);

// D) سباق: محاولة إعادة failed مقابل مطالبة جديدة أخرى — القفل المشترك يمنع الازدواج.
reset_test_state();
$user_D = 7103; $cycle_D = 'CYCLE-BLOCKER-D';
$event_A_d = 9131; $phone_A_d = '0593000001';
seed_active_catalog_user($user_D, $cycle_D, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 91400, $user_D, $cycle_D, 1);
seed_catalog_queue($event_A_d, $user_D, $cycle_D, [$phone_A_d], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'error', 'message' => 'bad number'])];
$handler->cron_process_queue($event_A_d);
check('D. تمهيد: Ledger أصبح failed', PGE_Invitation_Credit_Ledger::find_entry($cycle_D, $event_A_d, $phone_A_d, 'replacement')['status'] ?? null, 'failed');
// محاكاة عامل آخر يحمل قفل replacement الآن فعلياً (مطالبة جديدة متزامنة افتراضية).
$rep_lock_D = PGE_Replacement_Entitlements::build_replacement_credit_lock_name($user_D, $cycle_D);
$wpdb->held_locks[$rep_lock_D] = true;
$calls_before_D = $GLOBALS['__test_remote_post_calls'];
seed_catalog_queue($event_A_d, $user_D, $cycle_D, [$phone_A_d], 1, 'replacement');
$handler->cron_process_queue($event_A_d);
$queue_D_locked = get_option('pge_wa_queue_' . $event_A_d);
check('D. إعادة المحاولة تُحجَب أثناء انشغال القفل (لا ازدواج)', $queue_D_locked['results'][$phone_A_d]['status'] ?? null, 'ledger_error');
check('D. Cartat لم يُستدعَ أثناء انشغال القفل', $GLOBALS['__test_remote_post_calls'], $calls_before_D);
unset($wpdb->held_locks[$rep_lock_D]);
// بعد تحرير القفل: إعادة محاولة طبيعية تنجح دون أي ازدواج.
seed_catalog_queue($event_A_d, $user_D, $cycle_D, [$phone_A_d], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-D-retry'])];
$handler->cron_process_queue($event_A_d);
check('D. بعد تحرير القفل: إعادة المحاولة تنجح بشكل طبيعي', PGE_Replacement_Entitlements::count_consumed($user_D, $cycle_D), 1);

// E) صف consumed موجود: already_consumed، بلا أي حجز توفر جديد (العدّادات لا تتغيّر).
reset_test_state();
$user_E = 7104; $cycle_E = 'CYCLE-BLOCKER-E';
$event_E = 9141; $phone_E = '0594000001';
seed_active_catalog_user($user_E, $cycle_E, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 91500, $user_E, $cycle_E, 1);
$pre_claim_E = PGE_Invitation_Credit_Ledger::claim_for_delivery($user_E, $cycle_E, $event_E, $phone_E, 'replacement', 1);
PGE_Invitation_Credit_Ledger::mark_consumed_with_token($pre_claim_E['id'], $pre_claim_E['attempt_token']);
PGE_Replacement_Entitlements::claim_oldest_granted_for_ledger($user_E, $cycle_E, $pre_claim_E['id']);
$consumed_before_E = PGE_Replacement_Entitlements::count_consumed($user_E, $cycle_E);
seed_catalog_queue($event_E, $user_E, $cycle_E, [$phone_E], 1, 'replacement');
$handler->cron_process_queue($event_E);
$queue_E_after = get_option('pge_wa_queue_' . $event_E);
check('E. نتيجة الهاتف skipped_already_consumed', $queue_E_after['results'][$phone_E]['status'] ?? null, 'skipped_already_consumed');
check('E. عدّاد الاستحقاقات المُستهلَكة لم يتغيّر', PGE_Replacement_Entitlements::count_consumed($user_E, $cycle_E), $consumed_before_E);

// F) صف reserved نشط (Lease صالح): in_progress.
reset_test_state();
$user_F = 7105; $cycle_F = 'CYCLE-BLOCKER-F';
$event_F = 9151; $phone_F = '0595000001';
seed_active_catalog_user($user_F, $cycle_F, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 91600, $user_F, $cycle_F, 1);
PGE_Invitation_Credit_Ledger::claim_for_delivery($user_F, $cycle_F, $event_F, $phone_F, 'replacement', 1); // reserved بتوكن نشط
seed_catalog_queue($event_F, $user_F, $cycle_F, [$phone_F], 1, 'replacement');
$handler->cron_process_queue($event_F);
$queue_F_after = get_option('pge_wa_queue_' . $event_F);
check('F. نتيجة الهاتف skipped_in_progress', $queue_F_after['results'][$phone_F]['status'] ?? null, 'skipped_in_progress');

// G) صف reserved منتهي الـLease: استعادة الحجز تعمل، بلا حجز استحقاق ثانٍ (نفس الصف يُعاد استخدامه).
reset_test_state();
$GLOBALS['__test_now_override'] = '2026-01-01 00:00:00';
$user_G = 7106; $cycle_G = 'CYCLE-BLOCKER-G';
$event_G = 9161; $phone_G = '0596000001';
seed_active_catalog_user($user_G, $cycle_G, 10, 0, 5, 0);
seed_granted_entitlements($wpdb, 91700, $user_G, $cycle_G, 1);
$pre_claim_G = PGE_Invitation_Credit_Ledger::claim_for_delivery($user_G, $cycle_G, $event_G, $phone_G, 'replacement', 1); // reserved بتوكن، T0
$GLOBALS['__test_now_override'] = '2026-01-01 00:02:10'; // +130 ثانية > ATTEMPT_LEASE_SECONDS(120)
seed_catalog_queue($event_G, $user_G, $cycle_G, [$phone_G], 1, 'replacement');
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-G-reclaim'])];
$handler->cron_process_queue($event_G);
$queue_G_after = get_option('pge_wa_queue_' . $event_G);
$ledger_G_after = PGE_Invitation_Credit_Ledger::find_entry($cycle_G, $event_G, $phone_G, 'replacement');
check('G. استعادة الحجز بعد انتهاء Lease تنجح بالإرسال', $queue_G_after['results'][$phone_G]['status'] ?? null, 'sent');
check('G. نفس صف Ledger الأصلي أُعيد استخدامه (لا صف ثانٍ)', $ledger_G_after['id'] ?? null, $pre_claim_G['id'] ?? null);
check('G. استحقاق واحد بالضبط استُهلك (لا حجز إضافي بسبب الاستعادة)', PGE_Replacement_Entitlements::count_consumed($user_G, $cycle_G), 1);
$GLOBALS['__test_now_override'] = null;

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
