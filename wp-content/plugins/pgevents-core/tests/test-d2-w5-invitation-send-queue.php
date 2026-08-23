<?php
/**
 * ============================================================================
 * D2-W5 — "Durable Queue Integration Contract" — اختبارات مستقلة
 * ============================================================================
 * Harness ذاتي بلا PHPUnit (نفس نمط D2-W1/D2-W2/D2-W3/D2-W4 تماماً) — يحمّل
 * الملفات الإنتاجية الحقيقية عبر require_once ويشغّلها فوق $wpdb وهمي +
 * دوال wp_options وهمية (add_option/get_option/update_option/delete_option)
 * تحاكي سلوك WordPress الحقيقي (بما فيه فشل add_option() الذرّي عند وجود
 * المفتاح مسبقاً — نفس الأساس الذي يعتمد عليه enqueue_claimed_attempt()
 * فعلياً للـIdempotency). لا اتصال MySQL/WordPress حقيقي هنا — راجع "حدود
 * التحقق من التخزين الحقيقي" في التقرير النهائي.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['__test_now'] = '2026-08-23 10:00:00';
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now']; }
}
if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($value) { return preg_replace('/\D+/', '', (string) $value); }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $code;
        private $message;
        public function __construct($code = '', $message = '', $data = null)
        {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}

// ══════════════════════════════════════════════════════════════════════
// دوال wp_options وهمية — نفس الاصطلاح الحقيقي: add_option() تفشل (false)
// إن كان المفتاح موجوداً مسبقاً (Idempotent/Atomic — نفس ضمان UNIQUE KEY
// على option_name في جدول wp_options الحقيقي)، وإلا تُنشئ وتُعيد true.
// ══════════════════════════════════════════════════════════════════════

$GLOBALS['w5_options'] = [];
$GLOBALS['w5_force_add_option_failure'] = false;

if (!function_exists('add_option')) {
    function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
    {
        if (!empty($GLOBALS['w5_force_add_option_failure'])) {
            return false; // فشل كتابة حقيقي مُحاكى — لا شيء يُخزَّن.
        }
        if (array_key_exists($name, $GLOBALS['w5_options'])) {
            return false; // موجود مسبقاً — نفس سلوك WordPress الحقيقي تماماً.
        }
        $GLOBALS['w5_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['w5_options']) ? $GLOBALS['w5_options'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = 'yes')
    {
        $GLOBALS['w5_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        if (!array_key_exists($name, $GLOBALS['w5_options'])) {
            return false;
        }
        unset($GLOBALS['w5_options'][$name]);
        return true;
    }
}

// ══════════════════════════════════════════════════════════════════════
// $wpdb وهمي — نفس شكل Fake_Wpdb_D2W4/D2W3 (prepare/get_row/get_results/
// insert/update)، مع إضافة دعم فعلي لـLIMIT (غير موجود في نسخ الأسابيع
// السابقة، مطلوب هنا فعلياً لاستعلام query_pending_by_type() الجديد).
// ══════════════════════════════════════════════════════════════════════

class Fake_Wpdb_D2W5
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $message_log = [];
    private $next_id = 1;

    public function next_manual_id()
    {
        return $this->next_id++;
    }

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
        $id = $where['id'] ?? null;
        if ($id === null || !isset($this->message_log[$id])) {
            return 0;
        }
        $this->message_log[$id] = array_merge($this->message_log[$id], $data);
        return 1;
    }
}

$wpdb = new Fake_Wpdb_D2W5();

require_once PGE_PATH . 'includes/class-pge-message-type.php';
require_once PGE_PATH . 'includes/class-pge-message-log.php';
require_once PGE_PATH . 'includes/class-pge-invitation-send-queue.php';

function pge_strip_php_comments($source)
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $code .= is_array($token) ? $token[1] : $token;
    }
    return $code;
}

// ══════════════════════════════════════════════════════════════════════
// أدوات التتبّع والمساعدة
// ══════════════════════════════════════════════════════════════════════

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
        $failures[] = "$label (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")";
    }
}

function check_true($label, $condition)
{
    global $total, $passed, $failures;
    $total++;
    if ($condition) {
        $passed++;
    } else {
        $failures[] = "$label (condition was false)";
    }
}

/** يزرع صفاً في pge_message_log مباشرة (بلا المرور بـcreate_pending() — تحكّم دقيق كامل بكل حقل). */
function seed_log_row($id, array $overrides = [])
{
    global $wpdb;
    $defaults = [
        'id'                   => $id,
        'event_id'             => 8001,
        'rsvp_id'               => null,
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

// ══════════════════════════════════════════════════════════════════════
// A. محاولة معلّقة مُطالَب بها (claimed pending) يمكن طابرتها.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(101, ['event_id' => 8001, 'batch_id' => 'batch-A', 'actor_user_id' => 9001]);
$a = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(101);
check('A1. محاولة pending تُطابَر بنجاح', $a['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_QUEUED);
check('A2. log_id في النتيجة = 101', $a['log_id'] ?? null, 101);
check('A3. عنصر الطابور موجود فعلياً عبر get()', PGE_Invitation_Send_Queue::is_queued(101), true);
check('A4. عنصر الطابور نفسه يحمل log_id = 101 (D2-W5 Fix Pass 1: المرجع الوحيد المُخزَّن)', PGE_Invitation_Send_Queue::get(101)['log_id'] ?? null, 101);
check_true('A5. عنصر الطابور يحمل queued_at غير فارغ', !empty(PGE_Invitation_Send_Queue::get(101)['queued_at'] ?? ''));

// ══════════════════════════════════════════════════════════════════════
// B. محاولة نهائية (sent) لا يمكن طابرتها.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(102, ['status' => PGE_Message_Log::STATUS_SENT]);
$b = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(102);
check('B1. محاولة sent: مرفوضة، لا طابور', $b['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_REJECTED);
check('B2. السبب not_pending', $b['reason'] ?? null, 'not_pending');
check('B3. لا عنصر طابور أُنشئ لها', PGE_Invitation_Send_Queue::is_queued(102), false);

// ══════════════════════════════════════════════════════════════════════
// C. محاولة نهائية (failed) لا يمكن طابرتها كـ"نفس المحاولة".
// ══════════════════════════════════════════════════════════════════════

seed_log_row(103, ['status' => PGE_Message_Log::STATUS_FAILED]);
$c = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(103);
check('C1. محاولة failed: مرفوضة، لا طابور لنفس log_id', $c['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_REJECTED);
check('C2. لا عنصر طابور أُنشئ لها', PGE_Invitation_Send_Queue::is_queued(103), false);

// ══════════════════════════════════════════════════════════════════════
// D. log_id غير موجود إطلاقاً — فشل مغلق.
// ══════════════════════════════════════════════════════════════════════

$d = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(999999);
check('D1. log_id غير موجود: مرفوض', $d['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_REJECTED);
check('D2. السبب not_found', $d['reason'] ?? null, 'not_found');

// ══════════════════════════════════════════════════════════════════════
// E. message_type خاطئ — فشل مغلق (لا طابور إلا لـinvitation).
// ══════════════════════════════════════════════════════════════════════

seed_log_row(104, ['message_type' => PGE_Message_Type::REMINDER]);
$e = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(104);
check('E1. message_type=reminder: مرفوض', $e['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_REJECTED);
check('E2. السبب wrong_message_type', $e['reason'] ?? null, 'wrong_message_type');

// ══════════════════════════════════════════════════════════════════════
// F. تكرار enqueue لنفس log_id: Idempotent.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(105, ['batch_id' => 'batch-F']);
$f1 = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(105);
$f2 = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(105);
check('F1. أول enqueue: queued', $f1['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_QUEUED);
check('F2. ثاني enqueue لنفس log_id: already_queued', $f2['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_ALREADY_QUEUED);
check('F3. عنصر الطابور المُعاد من already_queued يحمل نفس log_id الأصلي', $f2['item']['log_id'] ?? null, 105);
check('F4. queued_at في عنصر already_queued مطابق حرفياً للعنصر الأصلي المُنشأ أول مرة (لم يُعَد إنشاؤه)', $f2['item']['queued_at'] ?? null, $f1['item']['queued_at'] ?? null);

// ══════════════════════════════════════════════════════════════════════
// G. محاكاة نداءين "متزامنين" فعلياً لنفس log_id: عنصر طابور منطقي واحد
// فقط ينتج — نفس منهجية D2-W4 O1/O2: تسلسل حتمي يحاكي التزامن، اعتماداً
// على الضمان الحقيقي (INSERT ذرّي فريد على مستوى option_name) لا على أي
// قفل تطبيقي إضافي هنا (لا يوجد أصلاً في enqueue_claimed_attempt()).
// ══════════════════════════════════════════════════════════════════════

seed_log_row(106, ['batch_id' => 'batch-G']);
$g1 = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(106);
$g2 = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(106);
check('G1. النداء الأول (يمثّل الفائز في السباق الحقيقي): queued', $g1['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_QUEUED);
check('G2. النداء الثاني (يمثّل الخاسر في السباق الحقيقي): already_queued، ليس خطأ ولا تكراراً', $g2['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_ALREADY_QUEUED);
check_true('G3. مفتاح wp_options واحد فقط فعلياً لهذا log_id (لا نسخة مكرَّرة تحت أي اسم)', count(array_filter(array_keys($GLOBALS['w5_options']), function ($k) { return strpos($k, 'pge_invsend_queue_106') === 0; })) === 1);

// ══════════════════════════════════════════════════════════════════════
// H. log_ids مختلفة تُطابَر باستقلالية تامة.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(107, ['batch_id' => 'batch-H1']);
seed_log_row(108, ['batch_id' => 'batch-H2']);
$h1 = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(107);
$h2 = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(108);
check('H1. log_id الأول: queued', $h1['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_QUEUED);
check('H2. log_id الثاني: queued باستقلالية', $h2['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_QUEUED);
check('H3. عنصر الطابور الأول يحمل log_id=107 بالضبط', PGE_Invitation_Send_Queue::get(107)['log_id'] ?? null, 107);
check('H4. عنصر الطابور الثاني يحمل log_id=108 بالضبط — لا تصادم', PGE_Invitation_Send_Queue::get(108)['log_id'] ?? null, 108);

// ══════════════════════════════════════════════════════════════════════
// I. actor_user_id (D2-W5 Fix Pass 1): **لا يُخزَّن إطلاقاً** في عنصر
// الطابور بعد الآن — يبقى متاحاً فقط بالقراءة الطازجة من السجل الدائم
// نفسه (PGE_Message_Log::find_by_id())، فلا "إعادة كتابة" ممكنة أصلاً لأنه
// لا نسخة محلية له لتُكتَب. لا معامل actor في توقيع enqueue_claimed_
// attempt() إطلاقاً يمكن أن يُحقَن أو "يُعيد كتابته".
// ══════════════════════════════════════════════════════════════════════

seed_log_row(109, ['actor_user_id' => 9077]);
$i1 = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(109);
check_true('I1. لا مفتاح actor_user_id إطلاقاً في عنصر الطابور نفسه', !array_key_exists('actor_user_id', $i1['item'] ?? []));
check('I2. actor_user_id يبقى متاحاً حصراً عبر إعادة قراءة السجل الدائم مباشرة (find_by_id) = 9077', PGE_Message_Log::find_by_id(109)['actor_user_id'] ?? null, 9077);
$reflection_method = new ReflectionMethod('PGE_Invitation_Send_Queue', 'enqueue_claimed_attempt');
check('I3. توقيع enqueue_claimed_attempt() معامل واحد فقط (log_id) — لا معامل actor قابل للحقن من مستدعٍ', $reflection_method->getNumberOfParameters(), 1);

// ══════════════════════════════════════════════════════════════════════
// J. لا لقطة تفويض موثوقة (authorization snapshot) داخل عنصر الطابور.
// ══════════════════════════════════════════════════════════════════════

$j_item = PGE_Invitation_Send_Queue::get(101);
check_true('J1. لا مفتاح "authorized" أو ما يعادله في عنصر الطابور', !array_key_exists('authorized', $j_item) && !array_key_exists('authorization', $j_item) && !array_key_exists('capability', $j_item));

// ══════════════════════════════════════════════════════════════════════
// K. عقد الحمولة الدنيا الحصري (D2-W5 Fix Pass 1): log_id + queued_at
// فقط — لا محتوى رسالة، لا سرّ مزوّد، لا حالة، لا فاعل، لا مناسبة، لا
// دفعة. قائمة مفاتيح مضبوطة تماماً + فحوصات سلبية صريحة لكل حقل مطلوب
// التأكّد من غيابه تحديداً (متطلبات القسم "TEST UPDATE" 1-5).
// ══════════════════════════════════════════════════════════════════════

check('K1. مفاتيح عنصر الطابور مضبوطة بالضبط: log_id/queued_at فقط — لا أكثر ولا أقل', (function ($item) {
    $keys = array_keys($item);
    sort($keys);
    return $keys;
})($j_item), ['log_id', 'queued_at']);
check_true('K2. عنصر الطابور يحمل log_id فعلياً', array_key_exists('log_id', $j_item));
check_true('K3. عنصر الطابور يحمل queued_at فعلياً', array_key_exists('queued_at', $j_item));
check_true('K4. عنصر الطابور لا يحمل مفتاح "status" إطلاقاً (كانت مُخزَّنة سابقاً — أُزيلت في Fix Pass 1)', !array_key_exists('status', $j_item));
check_true('K5. عنصر الطابور لا يحمل مفتاح "actor_user_id" إطلاقاً (كانت مُخزَّنة سابقاً — أُزيلت في Fix Pass 1)', !array_key_exists('actor_user_id', $j_item));
check_true('K6. عنصر الطابور لا يحمل مفتاح "batch_id" إطلاقاً (كانت مُخزَّنة سابقاً — أُزيلت في Fix Pass 1، راجع القسم T لتوثيق السبب)', !array_key_exists('batch_id', $j_item));
check_true('K7. لا مفتاح event_id/phone/guest_phone/message/content/provider/secret/group_id/image_url/authorized/authorization/capability في عنصر الطابور', empty(array_intersect(array_keys($j_item), ['event_id', 'phone', 'guest_phone', 'message', 'content', 'text', 'provider', 'secret', 'group_id', 'image_url', 'authorized', 'authorization', 'capability'])));

// ══════════════════════════════════════════════════════════════════════
// L. فشل enqueue يترك المحاولة الدائمة (pending) سليمة تماماً بلا لمس.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(110, ['batch_id' => 'batch-L']);
$GLOBALS['w5_force_add_option_failure'] = true;
$l1 = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(110);
$GLOBALS['w5_force_add_option_failure'] = false;
check('L1. فشل تخزين حقيقي: النتيجة error، ليست queued', $l1['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_ERROR);
check('L2. لا عنصر طابور أُنشئ فعلياً رغم محاولة الكتابة', PGE_Invitation_Send_Queue::is_queued(110), false);
check('L3. السجل الدائم لا يزال pending تماماً كما كان — لم يُلمَس إطلاقاً', $wpdb->message_log[110]['status'], PGE_Message_Log::STATUS_PENDING);

// ══════════════════════════════════════════════════════════════════════
// M. enqueue الفاشل قابل لإعادة المحاولة/الاسترداد بعد زوال سبب الفشل.
// ══════════════════════════════════════════════════════════════════════

$m1 = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(110);
check('M1. إعادة محاولة enqueue لنفس log_id بعد زوال الفشل: queued فعلياً', $m1['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_QUEUED);

// ══════════════════════════════════════════════════════════════════════
// N. الاسترداد يجد محاولة معلّقة يتيمة (بلا عنصر طابور نشط).
// ══════════════════════════════════════════════════════════════════════

seed_log_row(111, ['batch_id' => 'batch-N']); // pending، لم تُطابَر بعد إطلاقاً
$n_recoverable = PGE_Invitation_Send_Queue::find_recoverable_pending_attempts(50);
$n_ids = array_map(function ($r) { return (int) $r['id']; }, $n_recoverable);
check_true('N1. الاسترداد يجد log_id=111 (معلّقة، بلا عمل طابور نشط)', in_array(111, $n_ids, true));

// ══════════════════════════════════════════════════════════════════════
// O. عنصر مُطابَر بالفعل لا يُكرَّر عبر الاسترداد.
// ══════════════════════════════════════════════════════════════════════

check_true('O1. log_id=101 (مُطابَر بالفعل في الحالة A) غير موجود في نتيجة الاسترداد', !in_array(101, $n_ids, true));
check_true('O2. log_id=105/106/107/108/109/110 (مُطابَرة بالفعل) كلها غائبة عن الاسترداد', empty(array_intersect([105, 106, 107, 108, 109, 110], $n_ids)));

// ══════════════════════════════════════════════════════════════════════
// P. لا استدعاء Cartat/UltraMsg إطلاقاً داخل الملف.
// ══════════════════════════════════════════════════════════════════════

$queue_src = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-send-queue.php');
$queue_code_only = pge_strip_php_comments($queue_src);
check_true('P1. لا Cartat/UltraMsg في الكود التنفيذي لملف الطابور', strpos($queue_code_only, 'Cartat') === false && strpos($queue_code_only, 'UltraMsg') === false);

// ══════════════════════════════════════════════════════════════════════
// Q. لا finalize_success()/finalize_failure() ولا claim() إطلاقاً — الطابور
// لا يلمس الـLedger كتابةً بأي شكل، ولا يستدعي Application Layer.
// ══════════════════════════════════════════════════════════════════════

$forbidden_terms_q = ['::finalize_success(', '::finalize_failure(', '::claim(', '$wpdb', '->insert(', '->update(', 'GET_LOCK', 'RELEASE_LOCK'];
$found_q = [];
foreach ($forbidden_terms_q as $term) {
    if (strpos($queue_code_only, $term) !== false) {
        $found_q[] = $term;
    }
}
check_true('Q1. لا finalize_success/finalize_failure/claim/$wpdb مباشر/insert/update/GET_LOCK داخل ملف الطابور — كل كتابة عبر add_option/get_option/update_option/delete_option القياسية فقط', empty($found_q));

// ══════════════════════════════════════════════════════════════════════
// R. لا AJAX ولا أي أثر جانبي على الواجهة.
// ══════════════════════════════════════════════════════════════════════

$forbidden_terms_r = ['wp_ajax_', 'add_action(', 'add_filter(', 'wp_send_json', 'echo ', 'print '];
$found_r = [];
foreach ($forbidden_terms_r as $term) {
    if (strpos($queue_code_only, $term) !== false) {
        $found_r[] = $term;
    }
}
check_true('R1. لا AJAX/hooks/echo/print — لا أثر جانبي على الواجهة داخل ملف الطابور', empty($found_r));

// ══════════════════════════════════════════════════════════════════════
// S. حقول تحصين دورة الحياة (lifecycle fencing) لا تُلمَس إطلاقاً — enqueue
// قراءة فقط على السجل الدائم، لا كتابة عليه بأي شكل.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(112, ['rsvp_id' => 555, 'lifecycle_started_at' => '2026-08-20 08:00:00', 'batch_id' => 'batch-S']);
$before_s = $wpdb->message_log[112];
PGE_Invitation_Send_Queue::enqueue_claimed_attempt(112);
$after_s = $wpdb->message_log[112];
check('S1. rsvp_id لم يتغيّر بعد enqueue', $after_s['rsvp_id'], $before_s['rsvp_id']);
check('S2. lifecycle_started_at لم يتغيّر بعد enqueue', $after_s['lifecycle_started_at'], $before_s['lifecycle_started_at']);
check('S3. status السجل نفسه لم يتغيّر بعد enqueue (يبقى pending، لا كتابة على الـLedger هنا)', $after_s['status'], PGE_Message_Log::STATUS_PENDING);

// ══════════════════════════════════════════════════════════════════════
// T. batch_id (D2-W5 Fix Pass 1 — توثيق قرار الإزالة صراحة): لا حاجة
// تشغيلية فعلية لـbatch_id داخل هذا الملف نفسه — لا enqueue_claimed_
// attempt() ولا get()/is_queued()/remove()/find_recoverable_pending_
// attempts() تستخدمه في أي قرار أو فرز أو فلترة. batch_id **يبقى**
// موثوقاً بالكامل على السجل الدائم نفسه (pge_message_log، من D2-W1/D2-W4
// دون تغيير) — أي مستهلك مستقبلي يحتاجه يقرأه عبر
// PGE_Message_Log::find_by_id($log_id)['batch_id']، لا من عنصر الطابور.
// ══════════════════════════════════════════════════════════════════════

check_true('T1. batch_id لا يظهر إطلاقاً في عنصر الطابور نفسه (لا تكرار لبيانات الـLedger)', !array_key_exists('batch_id', PGE_Invitation_Send_Queue::get(112) ?? []));
check('T2. batch_id يبقى سليماً وموثوقاً على السجل الدائم نفسه (لم يُلمَس، ولا حاجة لتكراره)', PGE_Message_Log::find_by_id(112)['batch_id'] ?? null, 'batch-S');

// ══════════════════════════════════════════════════════════════════════
// U. Resend صريح لنفس الضيف يُنشئ log_id مختلفاً (سلوك D2-W1/D2-W4 القائم)
// وبالتالي عنصر طابور شرعي مستقل تماماً.
// ══════════════════════════════════════════════════════════════════════

seed_log_row(113, ['guest_phone' => '966500000009', 'status' => PGE_Message_Log::STATUS_SENT, 'batch_id' => 'batch-U-first']); // المحاولة الأصلية الناجحة سابقاً
seed_log_row(114, ['guest_phone' => '966500000009', 'status' => PGE_Message_Log::STATUS_PENDING, 'batch_id' => 'batch-U-resend']); // resend لاحق: log_id مختلف تماماً (سلوك claim() الحقيقي في D2-W1)
$u_old = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(113);
$u_new = PGE_Invitation_Send_Queue::enqueue_claimed_attempt(114);
check('U1. المحاولة الأصلية (sent، نهائية): مرفوضة دوماً — لا طابور لها', $u_old['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_REJECTED);
check('U2. محاولة resend (log_id مختلف تماماً): queued بنجاح، مستقلة تماماً', $u_new['result'] ?? null, PGE_Invitation_Send_Queue::RESULT_QUEUED);

// ══════════════════════════════════════════════════════════════════════
// V. لا Dedup عابر للمحاولات (Cross-Attempt) يُلغي شرعية الـresend — مفتاح
// الطابور هو log_id فقط، ليس الضيف/المناسبة/الفاعل.
// ══════════════════════════════════════════════════════════════════════

check_true('V1. log_id=113 (المحاولة القديمة) غير مُطابَر إطلاقاً', !PGE_Invitation_Send_Queue::is_queued(113));
check_true('V2. log_id=114 (الـresend الجديد لنفس الضيف بالضبط) مُطابَر بنجاح ومستقل', PGE_Invitation_Send_Queue::is_queued(114));
check('V3. عنصر طابور 114 يحمل log_id=114 حصراً — مفتاح Dedup هو log_id فقط، لا الضيف/المناسبة/batch_id المشترك بينهما', PGE_Invitation_Send_Queue::get(114)['log_id'] ?? null, 114);
check_true('V4. لا مفتاح wp_options مشترك بين 113 و114 (مفتاحان منفصلان تماماً مُشتقّان من log_id فقط)', !array_key_exists('pge_invsend_queue_113', $GLOBALS['w5_options']) && array_key_exists('pge_invsend_queue_114', $GLOBALS['w5_options']));

// ── ملخص ────────────────────────────────────────────────────────────────

echo "النتيجة: $passed / $total نجحت.\n";

if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}

exit(0);
