<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بلا أي بنية اختبارات جديدة) لمهمة
 * "Invitation Credits Engine — المرحلة الثالثة A: ربط محرك الرصيد بمسار
 * Queue/Cron الفعلي عبر كارتات، مع منع التزامن وتجاوز الرصيد".
 *
 * يحمّل الملفين الحقيقيين التاليين دون أي تعديل عليهما:
 *   - includes/class-pge-invitation-credit-ledger.php
 *   - includes/class-cartat-handler.php
 *
 * لا يحتاج هذا الملف class-pge-catalog.php ولا class-mon-catalog-schema.php
 * ولا class-mon-events-users.php إطلاقاً — الاختبارات هنا تُحاكي حالة
 * المستخدم (User Meta) والمناسبة (Post/Post Meta) مباشرة كمدخلات جاهزة،
 * بدل المرور عبر Tier/Catalog CRUD الحقيقي (ذاك مُختبَر بالكامل في
 * test-invitation-credit-ledger.php).
 *
 * محاكاة GET_LOCK/RELEASE_LOCK هنا (خريطة "محجوز/غير محجوز" ضمن عملية PHP
 * واحدة متزامنة) لا تحاكي تزامناً حقيقياً بين اتصالين منفصلين — راجع
 * التعليق أعلى قسم "قفل Cron" أدناه لتفاصيل حدود اختبار #35 تحديداً.
 *
 * wp_send_json_success()/wp_send_json_error() الحقيقيتان تستدعيان wp_die()
 * (تُنهيان الطلب فعلياً) — هنا تُستبدَلان بدالتين تَرميان استثناءً مخصَّصاً
 * (PGE_Test_Ajax_Halt) يُلتقَط في كل اختبار AJAX، بنفس الأسلوب المعتمَد في
 * مجموعات اختبارات ووردبريس الحقيقية لمشاكل wp_die() المماثلة.
 *
 * التشغيل:
 *   php tests/test-cartat-credits-queue.php
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
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
}
// قابلة للتحكم بها من الاختبارات (إصلاح Blocker الـLease) — راجع نفس الآلية
// في test-invitation-credit-ledger.php لتفاصيل السبب.
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
    // دعم اختبار 34 (synchronization_error): حجب كتابة مفتاح محدد عمداً
    // لمحاكاة فشل صامت في update_user_meta() الحقيقية دون كسر بقية السلوك.
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

// ── مخزن wp_options عام (يشمل الـQueue نفسها وpending) ─────────────────────

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

// ── قوالب واتساب (نسخة اختبار مبسّطة، لا علاقة لها بمنطق الرصيد) ───────────

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
function get_the_post_thumbnail_url($post_id, $size = 'full') { return ''; } // بلا صورة → send_text_message
function get_permalink($post_id) { return 'https://example.test/event/' . $post_id . '/'; }

$GLOBALS['__test_scheduled_events'] = [];
function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    $GLOBALS['__test_scheduled_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];
    return true;
}
function spawn_cron() { return true; }

// ── محاكاة wp_remote_post/Cartat API (رد قابل للتحكم به لكل استدعاء) ───────

$GLOBALS['__test_remote_post_queue'] = [];
$GLOBALS['__test_remote_post_calls'] = 0;

function wp_remote_post($url, $args = [])
{
    $GLOBALS['__test_remote_post_calls']++;
    if (!empty($GLOBALS['__test_remote_post_queue'])) {
        return array_shift($GLOBALS['__test_remote_post_queue']);
    }
    // افتراضي: نجاح عام إن لم يُحدَّد رد مسبقاً لهذا الاستدعاء تحديداً.
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

// ── اعتراض wp_send_json_success/error (تنهيان الطلب فعلياً في ووردبريس) ────

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

/** يستدعي ajax_queue_start() ويلتقط الاستثناء دائماً، يُعيد ['success'=>bool,'payload'=>mixed] */
function call_ajax_queue_start(Mon_Cartat_Handler $handler)
{
    try {
        $handler->ajax_queue_start();
        return ['success' => null, 'payload' => 'DID_NOT_HALT']; // خطأ في الاختبار نفسه لو وصلنا هنا
    } catch (PGE_Test_Ajax_Halt $e) {
        return ['success' => $e->success, 'payload' => $e->payload];
    }
}

// ── محاكاة Resolver المركزي (pge_get_user_plan_limits_for_events) ──────────
// نسخة اختبار مبسّطة تُكرِّر العقد الجوهري فقط للدالة الحقيقية في
// event-factory.php (بوابة active + سلامة رقمية) دون جرّ كل تبعيات ذلك
// الملف (PGE_Packages وغيرها) — غير مطلوبة هنا لأن التركيز على مسار
// Catalog حصراً في هذه الاختبارات.

function pge_get_user_plan_limits_for_events($user_id)
{
    $status = (string) get_user_meta($user_id, '_mon_package_status', true);
    if ($status !== 'active') {
        return ['invitation_credit_total' => 0, 'invitation_credit_used' => 0, 'invitation_credit_remaining' => 0];
    }

    $total = get_user_meta($user_id, '_mon_invitation_credit_total', true);
    $used  = get_user_meta($user_id, '_mon_invitation_credit_used', true);
    $total = is_numeric($total) ? max(0, (int) $total) : 0;
    $used  = is_numeric($used) ? max(0, (int) $used) : 0;

    return [
        'invitation_credit_total'     => $total,
        'invitation_credit_used'      => $used,
        'invitation_credit_remaining' => max(0, $total - $used),
    ];
}

// ── Fake $wpdb: جدول Ledger فقط + محاكاة GET_LOCK/RELEASE_LOCK ─────────────
// (نفس تصميم Fake_Wpdb_Ledger في test-invitation-credit-ledger.php، بلا
// جداول plans/tiers غير المطلوبة هنا إطلاقاً.)

class Fake_Wpdb_Cartat_Credits
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $held_locks = [];

    private $ledger_rows = [];
    private $ledger_unique_index = [];
    private $ledger_next_id = 1;

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

    private function table_matches($sql_or_table)
    {
        return strpos($sql_or_table, $this->prefix . 'mon_invitation_credit_ledger') !== false;
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
            if (!$this->table_matches($sql)) {
                return null;
            }
            $rows = array_values($this->ledger_rows);
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
        if (!$this->table_matches($sql)) {
            return [];
        }
        $rows = array_values($this->ledger_rows);
        return $this->apply_where($rows, $sql);
    }

    private function apply_where(array $rows, $sql)
    {
        if (preg_match('/WHERE\s+(.+?)(LIMIT|$)/is', $sql, $m)) {
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

    private function ledger_unique_key(array $row)
    {
        return ($row['credit_cycle_id'] ?? '') . '|' . ($row['event_id'] ?? '') . '|' . ($row['guest_phone'] ?? '') . '|' . ($row['credit_type'] ?? '');
    }

    public function insert($table, $data, $format = null)
    {
        $this->last_error = '';
        if (!$this->table_matches($table)) {
            return false;
        }
        $key = $this->ledger_unique_key($data);
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
        if (!$this->table_matches($table)) {
            return false;
        }
        $id = $where['id'] ?? null;
        if ($id === null || !isset($this->ledger_rows[$id])) {
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
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Cartat_Credits();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// API Token لازم لتجاوز فحص "لم يتم ضبط Cartat API Token" في المُنشئ.
$GLOBALS['__test_options']['pge_cartat_api_token']    = 'TEST-TOKEN-1234';
$GLOBALS['__test_options']['pge_cartat_country_code'] = '966';

// ── تحميل الملفين الحقيقيين من المشروع (بلا أي تعديل عليهما) ───────────────

require_once __DIR__ . '/../includes/class-pge-invitation-credit-ledger.php';
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

/** إعادة ضبط مخازن الاختبار المشتركة بين السيناريوهات لتفادي أي تسرّب حالة. */
function reset_test_state()
{
    $GLOBALS['__test_remote_post_queue'] = [];
    $GLOBALS['__test_remote_post_calls'] = 0;
    $GLOBALS['__test_block_meta_key']    = null;
}

$handler = new Mon_Cartat_Handler();

echo "=== المرحلة الثالثة A: Queue start (21-25) ===\n";

// ── Queue start (21-25) ──────────────────────────────────────────────────────

// 21) user_id يؤخذ من post_author لا من أي مصدر آخر.
reset_test_state();
$event_21 = 8001;
$subscriber_21 = 9301;
$GLOBALS['__test_posts'][$event_21] = ['post_title' => 'مناسبة 21', 'post_author' => $subscriber_21];
$GLOBALS['__test_invited_phones'][$event_21] = ['0591111111'];
// المستخدم بلا _mon_package_source إطلاقاً (لا Catalog ولا Legacy فعلي) —
// كافٍ لاختبار أن post_author هو المصدر المُلتقَط، بصرف النظر عن نتيجة is_catalog.
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_21];
$resp_21 = call_ajax_queue_start($handler);
check_true('21. ajax_queue_start() نجح', $resp_21['success'] === true);
$queue_21 = get_option('pge_wa_queue_' . $event_21);
check('21. subscriber_user_id في الـQueue = post_author بالضبط', $queue_21['subscriber_user_id'] ?? null, $subscriber_21);

// 22) Catalog active يخزن credit_cycle_id وinvitation_credit_total من Snapshot/Resolver.
reset_test_state();
$event_22 = 8002;
$subscriber_22 = 9302;
$GLOBALS['__test_posts'][$event_22] = ['post_title' => 'مناسبة 22', 'post_author' => $subscriber_22];
$GLOBALS['__test_invited_phones'][$event_22] = ['0592222222'];
$GLOBALS['__test_user_meta'][$subscriber_22] = [
    '_mon_package_source'          => 'catalog',
    '_mon_package_status'          => 'active',
    '_mon_credit_cycle_id'         => 'CYCLE-22-ABCDEF',
    '_mon_invitation_credit_total' => 50,
    '_mon_invitation_credit_used'  => 5,
];
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_22];
$resp_22 = call_ajax_queue_start($handler);
check_true('22. ajax_queue_start() نجح لمشترك Catalog نشط', $resp_22['success'] === true);
$queue_22 = get_option('pge_wa_queue_' . $event_22);
check_true('22. is_catalog = true', $queue_22['is_catalog'] === true);
check('22. credit_cycle_id مخزَّن كما في Snapshot', $queue_22['credit_cycle_id'] ?? null, 'CYCLE-22-ABCDEF');
check('22. credit_type = primary', $queue_22['credit_type'] ?? null, 'primary');
check('22. invitation_credit_total = القيمة المُحلَّلة من الـResolver (50)', $queue_22['invitation_credit_total'] ?? null, 50);

// 23) Legacy لا يستخدم Ledger — is_catalog=false، بلا أي فحص/منع جديد.
reset_test_state();
$event_23 = 8003;
$subscriber_23 = 9303;
$GLOBALS['__test_posts'][$event_23] = ['post_title' => 'مناسبة 23', 'post_author' => $subscriber_23];
$GLOBALS['__test_invited_phones'][$event_23] = ['0593333333'];
$GLOBALS['__test_user_meta'][$subscriber_23] = [
    '_mon_package_status' => 'active', // Legacy قديم قد يملك هذا المفتاح لكن بلا _mon_package_source=catalog
];
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_23];
$resp_23 = call_ajax_queue_start($handler);
check_true('23. ajax_queue_start() نجح لمستخدم Legacy (بلا أي منع جديد)', $resp_23['success'] === true);
$queue_23 = get_option('pge_wa_queue_' . $event_23);
check_true('23. is_catalog = false لمستخدم Legacy', $queue_23['is_catalog'] === false);

// 24) expired Catalog يُمنع — لا Queue تُنشأ إطلاقاً.
reset_test_state();
$event_24 = 8004;
$subscriber_24 = 9304;
$GLOBALS['__test_posts'][$event_24] = ['post_title' => 'مناسبة 24', 'post_author' => $subscriber_24];
$GLOBALS['__test_invited_phones'][$event_24] = ['0594444444'];
$GLOBALS['__test_user_meta'][$subscriber_24] = [
    '_mon_package_source' => 'catalog',
    '_mon_package_status' => 'expired', // منتهٍ — ليس active
];
delete_option('pge_wa_queue_' . $event_24);
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_24];
$resp_24 = call_ajax_queue_start($handler);
check_true('24. ajax_queue_start() يُرفَض لاشتراك Catalog منتهٍ', $resp_24['success'] === false);
check('24. لا تُنشأ Queue إطلاقاً للاشتراك المنتهي', get_option('pge_wa_queue_' . $event_24, 'NONE'), 'NONE');

// 25) Queue نشطة لنفس الحدث تمنع بدء Queue ثانية.
reset_test_state();
$event_25 = 8005;
$subscriber_25 = 9305;
$GLOBALS['__test_posts'][$event_25] = ['post_title' => 'مناسبة 25', 'post_author' => $subscriber_25];
$GLOBALS['__test_invited_phones'][$event_25] = ['0595555555'];
update_option('pge_wa_queue_' . $event_25, ['status' => 'running', 'event_id' => $event_25]);
$_POST = ['nonce' => 'x', 'event_id' => (string) $event_25];
$resp_25 = call_ajax_queue_start($handler);
check_true('25. ajax_queue_start() يُرفَض عند وجود Queue running بالفعل', $resp_25['success'] === false);

echo "\n=== المرحلة الثالثة A: Cron (26-36) ===\n";

// ── Cron (26-36) ──────────────────────────────────────────────────────────────

/** يبني ويخزّن Queue Catalog جاهزة للاختبار مباشرة (بدل المرور عبر ajax_queue_start() في كل مرة). */
function seed_catalog_queue($event_id, $subscriber_user_id, $credit_cycle_id, $phones, $credit_limit)
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
        'is_catalog'              => true,
        'subscriber_user_id'      => $subscriber_user_id,
        'credit_cycle_id'         => $credit_cycle_id,
        'credit_type'             => 'primary',
        'invitation_credit_total' => $credit_limit,
    ];
    update_option('pge_wa_queue_' . $event_id, $queue, false);
    return $queue;
}

// 26) اختلاف دورة الاشتراك يُلغي Queue بلا أي إرسال.
reset_test_state();
$event_26 = 8101;
$subscriber_26 = 9401;
$GLOBALS['__test_user_meta'][$subscriber_26] = ['_mon_credit_cycle_id' => 'NEW-CYCLE-26'];
seed_catalog_queue($event_26, $subscriber_26, 'OLD-CYCLE-26', ['0601111111'], 10);
$handler->cron_process_queue($event_26);
$queue_26_after = get_option('pge_wa_queue_' . $event_26);
check('26. status أصبحت cancelled', $queue_26_after['status'] ?? null, 'cancelled');
check('26. cancel_reason = credit_cycle_changed', $queue_26_after['cancel_reason'] ?? null, 'credit_cycle_changed');
check('26. لا أي استدعاء لكارتات', $GLOBALS['__test_remote_post_calls'], 0);

// 27) claimed + accepted → consumed.
reset_test_state();
$event_27 = 8102;
$subscriber_27 = 9402;
$cycle_27 = 'CYCLE-27';
$GLOBALS['__test_user_meta'][$subscriber_27] = [
    '_mon_credit_cycle_id'         => $cycle_27,
    '_mon_package_status'          => 'active',
    '_mon_invitation_credit_total' => 10,
    '_mon_invitation_credit_used'  => 0,
];
seed_catalog_queue($event_27, $subscriber_27, $cycle_27, ['0602222222'], 10);
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-27'])];
$handler->cron_process_queue($event_27);
$queue_27_after = get_option('pge_wa_queue_' . $event_27);
check('27. نتيجة الهاتف sent', $queue_27_after['results']['0602222222']['status'] ?? null, 'sent');
$entry_27 = PGE_Invitation_Credit_Ledger::find_entry($cycle_27, $event_27, '0602222222', 'primary');
check('27. صف Ledger أصبح consumed', $entry_27['status'] ?? null, 'consumed');

// 28) consumed سابقاً → لا استدعاء لكارتات، تخطٍّ فوري.
reset_test_state();
$event_28 = 8103;
$subscriber_28 = 9403;
$cycle_28 = 'CYCLE-28';
$GLOBALS['__test_user_meta'][$subscriber_28] = ['_mon_credit_cycle_id' => $cycle_28, '_mon_package_status' => 'active'];
$pre_claim_28 = PGE_Invitation_Credit_Ledger::claim_for_delivery($subscriber_28, $cycle_28, $event_28, '0603333333', 'primary', 10);
PGE_Invitation_Credit_Ledger::mark_consumed_with_token($pre_claim_28['id'], $pre_claim_28['attempt_token']);
seed_catalog_queue($event_28, $subscriber_28, $cycle_28, ['0603333333'], 10);
$handler->cron_process_queue($event_28);
$queue_28_after = get_option('pge_wa_queue_' . $event_28);
check('28. نتيجة الهاتف skipped_already_consumed', $queue_28_after['results']['0603333333']['status'] ?? null, 'skipped_already_consumed');
check('28. لا أي استدعاء لكارتات', $GLOBALS['__test_remote_post_calls'], 0);

// 29) in_progress → لا استدعاء لكارتات، تخطٍّ فوري (لا فشل).
reset_test_state();
$event_29 = 8104;
$subscriber_29 = 9404;
$cycle_29 = 'CYCLE-29';
$GLOBALS['__test_user_meta'][$subscriber_29] = ['_mon_credit_cycle_id' => $cycle_29, '_mon_package_status' => 'active'];
PGE_Invitation_Credit_Ledger::claim_for_delivery($subscriber_29, $cycle_29, $event_29, '0604444444', 'primary', 10); // reserved بتوكن نشط، بلا إنهاء
seed_catalog_queue($event_29, $subscriber_29, $cycle_29, ['0604444444'], 10);
$handler->cron_process_queue($event_29);
$queue_29_after = get_option('pge_wa_queue_' . $event_29);
check('29. نتيجة الهاتف skipped_in_progress', $queue_29_after['results']['0604444444']['status'] ?? null, 'skipped_in_progress');
check('29. لا أي استدعاء لكارتات', $GLOBALS['__test_remote_post_calls'], 0);

// 30) limit_exceeded → لا استدعاء لكارتات.
reset_test_state();
$event_30 = 8105;
$subscriber_30 = 9405;
$cycle_30 = 'CYCLE-30';
$GLOBALS['__test_user_meta'][$subscriber_30] = ['_mon_credit_cycle_id' => $cycle_30, '_mon_package_status' => 'active'];
seed_catalog_queue($event_30, $subscriber_30, $cycle_30, ['0605555555'], 0); // credit_limit = 0
$handler->cron_process_queue($event_30);
$queue_30_after = get_option('pge_wa_queue_' . $event_30);
check('30. نتيجة الهاتف skipped_limit_exceeded', $queue_30_after['results']['0605555555']['status'] ?? null, 'skipped_limit_exceeded');
check('30. لا أي استدعاء لكارتات', $GLOBALS['__test_remote_post_calls'], 0);

// 31) rejected → failed بلا خصم (User Meta used تبقى كما هي).
reset_test_state();
$event_31 = 8106;
$subscriber_31 = 9406;
$cycle_31 = 'CYCLE-31';
$GLOBALS['__test_user_meta'][$subscriber_31] = [
    '_mon_credit_cycle_id'         => $cycle_31,
    '_mon_package_status'          => 'active',
    '_mon_invitation_credit_total' => 10,
    '_mon_invitation_credit_used'  => 0,
];
seed_catalog_queue($event_31, $subscriber_31, $cycle_31, ['0606666666'], 10);
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'error', 'message' => 'invalid number'])];
$handler->cron_process_queue($event_31);
$queue_31_after = get_option('pge_wa_queue_' . $event_31);
check('31. نتيجة الهاتف failed', $queue_31_after['results']['0606666666']['status'] ?? null, 'failed');
$entry_31 = PGE_Invitation_Credit_Ledger::find_entry($cycle_31, $event_31, '0606666666', 'primary');
check('31. صف Ledger أصبح failed (لا consumed)', $entry_31['status'] ?? null, 'failed');
check('31. _mon_invitation_credit_used لم يتغيّر (يبقى 0)', (int) get_user_meta($subscriber_31, '_mon_invitation_credit_used', true), 0);

// 32) transport_error → الصف يبقى reserved، بلا خصم.
reset_test_state();
$event_32 = 8107;
$subscriber_32 = 9407;
$cycle_32 = 'CYCLE-32';
$GLOBALS['__test_user_meta'][$subscriber_32] = [
    '_mon_credit_cycle_id'         => $cycle_32,
    '_mon_package_status'          => 'active',
    '_mon_invitation_credit_total' => 10,
    '_mon_invitation_credit_used'  => 0,
];
seed_catalog_queue($event_32, $subscriber_32, $cycle_32, ['0607777777'], 10);
$GLOBALS['__test_remote_post_queue'][] = new WP_Error('http_request_failed', 'timeout');
$handler->cron_process_queue($event_32);
$queue_32_after = get_option('pge_wa_queue_' . $event_32);
check('32. نتيجة الهاتف ambiguous_transport_error', $queue_32_after['results']['0607777777']['status'] ?? null, 'ambiguous_transport_error');
$entry_32 = PGE_Invitation_Credit_Ledger::find_entry($cycle_32, $event_32, '0607777777', 'primary');
check('32. صف Ledger يبقى reserved (لا consumed ولا failed)', $entry_32['status'] ?? null, 'reserved');
check('32. _mon_invitation_credit_used لم يتغيّر (يبقى 0)', (int) get_user_meta($subscriber_32, '_mon_invitation_credit_used', true), 0);

// 33) accepted يزيد used إلى count_consumed() الفعلي (لا used+1 غير ذري).
reset_test_state();
$event_33 = 8108;
$subscriber_33 = 9408;
$cycle_33 = 'CYCLE-33';
$GLOBALS['__test_user_meta'][$subscriber_33] = [
    '_mon_credit_cycle_id'         => $cycle_33,
    '_mon_package_status'          => 'active',
    '_mon_invitation_credit_total' => 10,
    '_mon_invitation_credit_used'  => 0,
];
seed_catalog_queue($event_33, $subscriber_33, $cycle_33, ['0608888881', '0608888882'], 10);
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-33A'])];
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-33B'])];
$handler->cron_process_queue($event_33);
$actual_consumed_33 = PGE_Invitation_Credit_Ledger::count_consumed($subscriber_33, $cycle_33, 'primary');
check('33. count_consumed() الفعلي = 2 (كلا الهاتفين قُبِلا)', $actual_consumed_33, 2);
check('33. _mon_invitation_credit_used = count_consumed() الفعلي بالضبط', (int) get_user_meta($subscriber_33, '_mon_invitation_credit_used', true), $actual_consumed_33);

// 34) فشل تحديث User Meta لا يُلغي consumed — Ledger يبقى مصدر الحقيقة.
reset_test_state();
$event_34 = 8109;
$subscriber_34 = 9409;
$cycle_34 = 'CYCLE-34';
$GLOBALS['__test_user_meta'][$subscriber_34] = [
    '_mon_credit_cycle_id'         => $cycle_34,
    '_mon_package_status'          => 'active',
    '_mon_invitation_credit_total' => 10,
    '_mon_invitation_credit_used'  => 0,
];
seed_catalog_queue($event_34, $subscriber_34, $cycle_34, ['0609999999'], 10);
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-34'])];
$log_file_34 = WP_CONTENT_DIR . '/cartat-webhook.log';
file_put_contents($log_file_34, ''); // تفريغ السجل قبل الاختبار لضمان فحص واضح
$GLOBALS['__test_block_meta_key'] = '_mon_invitation_credit_used'; // محاكاة فشل صامت لهذا المفتاح تحديداً
$handler->cron_process_queue($event_34);
$GLOBALS['__test_block_meta_key'] = null; // إلغاء المحاكاة فوراً بعد الاستدعاء
$entry_34 = PGE_Invitation_Credit_Ledger::find_entry($cycle_34, $event_34, '0609999999', 'primary');
check('34. صف Ledger يبقى consumed رغم فشل مزامنة User Meta', $entry_34['status'] ?? null, 'consumed');
$log_contents_34 = file_exists($log_file_34) ? file_get_contents($log_file_34) : '';
check_true('34. تسجيل synchronization_error في اللوغ', strpos($log_contents_34, 'synchronization_error') !== false);

// 35) تشغيل Cron "متزامن" لنفس الحدث لا يرسل مرتين (محاكاة عبر حجز القفل يدوياً
// قبل الاستدعاء — راجع ملاحظة الحدود المنهجية أعلى قسم Cron في هذا الملف؛
// لا تزامن حقيقي متعدد الاتصالات ممكن داخل عملية PHP واحدة متزامنة).
reset_test_state();
$event_35 = 8110;
$subscriber_35 = 9410;
$cycle_35 = 'CYCLE-35';
$GLOBALS['__test_user_meta'][$subscriber_35] = ['_mon_credit_cycle_id' => $cycle_35, '_mon_package_status' => 'active'];
seed_catalog_queue($event_35, $subscriber_35, $cycle_35, ['0610000000'], 10);
$queue_35_before = get_option('pge_wa_queue_' . $event_35);
$cron_lock_name_35 = 'pge_wa_cron_' . md5((string) $event_35);
$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $cron_lock_name_35, 0)); // محاكاة "تشغيلة أخرى تعمل بالفعل"
$handler->cron_process_queue($event_35);
$queue_35_after_locked = get_option('pge_wa_queue_' . $event_35);
check('35. الـQueue لم تتغيّر إطلاقاً أثناء القفل المحجوز', $queue_35_after_locked, $queue_35_before);
check('35. لا أي استدعاء لكارتات أثناء القفل المحجوز', $GLOBALS['__test_remote_post_calls'], 0);
$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $cron_lock_name_35)); // تحرير يدوي لمحاكاة انتهاء "التشغيلة الأخرى"
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-35'])];
$handler->cron_process_queue($event_35);
$queue_35_after_release = get_option('pge_wa_queue_' . $event_35);
check('35. بعد تحرير القفل، تشغيلة طبيعية تُرسل بنجاح', $queue_35_after_release['results']['0610000000']['status'] ?? null, 'sent');

// 36) Legacy يستمر بالسلوك السابق تماماً — بلا Ledger وبلا أي حالة جديدة.
reset_test_state();
$event_36 = 8111;
$subscriber_36 = 9411;
$queue_36 = [
    'event_id' => $event_36, 'status' => 'queued', 'phones' => ['0611111111'],
    'guests_map' => [], 'event_name' => 'مناسبة Legacy', 'event_date' => '',
    'image_url' => '', 'event_url' => 'https://example.test/event/' . $event_36 . '/',
    'invite_code' => '', 'offset' => 0, 'total' => 1, 'results' => [],
    'created_at' => time(), 'done_at' => null, 'cancel_reason' => null,
    'is_catalog' => false, 'subscriber_user_id' => $subscriber_36,
    'credit_cycle_id' => '', 'credit_type' => 'primary', 'invitation_credit_total' => 0,
];
update_option('pge_wa_queue_' . $event_36, $queue_36, false);
$GLOBALS['__test_remote_post_queue'][] = ['body' => json_encode(['status' => 'sent', 'id' => 'MSG-36'])];
$handler->cron_process_queue($event_36);
$queue_36_after = get_option('pge_wa_queue_' . $event_36);
check('36. نتيجة الهاتف sent (نفس السلوك القديم تماماً)', $queue_36_after['results']['0611111111']['status'] ?? null, 'sent');
check('36. لا صف Ledger أُنشئ لمستخدم Legacy', PGE_Invitation_Credit_Ledger::count_consumed($subscriber_36, '', 'primary'), 0);
check('36. _mon_invitation_credit_used لم يُكتب إطلاقاً لمستخدم Legacy', get_user_meta($subscriber_36, '_mon_invitation_credit_used', true), '');

echo "\n=== إصلاح Blocker: Attempt Lease بعد transport_error فعلي عبر Cron ===\n";

// 9) transport_error فعلي عبر cron_process_queue() يترك الصف reserved بتوكن
// نشط، ثم claim_for_delivery() مباشر لاحق (يحاكي محاولة يدوية أو تشغيلة Cron
// أخرى) ضمن المهلة → in_progress (لا يُعاد إرسال الرسالة).
reset_test_state();
$GLOBALS['__test_now_override'] = '2026-01-01 00:00:00';
$event_9      = 8112;
$subscriber_9 = 9412;
$cycle_9      = 'CYCLE-LEASE-9';
$GLOBALS['__test_user_meta'][$subscriber_9] = [
    '_mon_credit_cycle_id'         => $cycle_9,
    '_mon_package_status'          => 'active',
    '_mon_invitation_credit_total' => 10,
    '_mon_invitation_credit_used'  => 0,
];
seed_catalog_queue($event_9, $subscriber_9, $cycle_9, ['0620000001'], 10);
$GLOBALS['__test_remote_post_queue'][] = new WP_Error('http_request_failed', 'timeout');
$handler->cron_process_queue($event_9);
$queue_9_after = get_option('pge_wa_queue_' . $event_9);
check('9. تمهيد: transport_error فعلي سجّل ambiguous_transport_error', $queue_9_after['results']['0620000001']['status'] ?? null, 'ambiguous_transport_error');

$claim_9 = PGE_Invitation_Credit_Ledger::claim_for_delivery($subscriber_9, $cycle_9, $event_9, '0620000001', 'primary', 10);
check('9. transport_error ثم Claim قبل انتهاء Lease → in_progress', $claim_9['result'] ?? null, 'in_progress');

// 10) نفس الصف، لكن بعد تقدّم الوقت 130 ثانية (أكبر من ATTEMPT_LEASE_SECONDS
// = 120) → المهلة انتهت، claim_for_delivery() يُعامله كصف غير مملوك ويعيد
// المطالبة به بتوكن جديد على نفس الصف (لا صف ثانٍ).
$GLOBALS['__test_now_override'] = '2026-01-01 00:02:10';
$claim_10 = PGE_Invitation_Credit_Ledger::claim_for_delivery($subscriber_9, $cycle_9, $event_9, '0620000001', 'primary', 10);
check('10. transport_error ثم Claim بعد انتهاء Lease → claimed (نفس الصف بتوكن جديد)', $claim_10['result'] ?? null, 'claimed');
check('10. نفس id الصف الأصلي — لا صف ثانٍ', $claim_10['id'] ?? null, $claim_9['id'] ?? null);
$GLOBALS['__test_now_override'] = null; // إعادة الوقت الافتراضي

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
