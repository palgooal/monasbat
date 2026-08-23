<?php
/**
 * D2-W7 — Worker Scheduling / Queue Consumption Orchestration.
 *
 * يختبر حصراً includes/class-pge-invitation-send-orchestrator.php: اكتشاف
 * العمل عبر D2-W5 (مُزيَّفة هنا بالكامل — نفس اصطلاح استبدال الاعتماديات
 * القائم أصلاً في اختبارات D2-W4/D2-W5/D2-W6)، تنفيذ الدفعة عبر
 * PGE_Invitation_Send_Worker::process_log_id() (مُزيَّفة أيضاً — سلوكها
 * الحقيقي مُثبَت بالفعل حصراً في tests/test-d2-w6-invitation-send-worker.php)،
 * جدولة WP-Cron (wp_next_scheduled/wp_schedule_single_event/spawn_cron
 * مُزيَّفة)، وقفل الدفعة (Fake $wpdb يُحاكي GET_LOCK/RELEASE_LOCK حقيقيين —
 * نفس نمط Fake_WPDB القائم في عدة ملفات اختبار أخرى بالمشروع).
 *
 * Run: php tests/test-d2-w7-invitation-send-orchestrator.php
 */

define('ABSPATH', __DIR__ . '/');

$total = 0;
$passed = 0;
$failures = [];

function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) {
        $passed++;
        return;
    }
    $failures[] = $label . ' — expected: ' . var_export($expected, true)
        . ' | actual: ' . var_export($actual, true);
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

// ══════════════════════════════════════════════════════════════════════
// تزييف بنية WordPress التحتية الدنيا فقط (Cron + add_action) — نفس
// اصطلاح test-thank-you-batch-worker-phase4b26.php حرفياً.
// ══════════════════════════════════════════════════════════════════════

$GLOBALS['w7_cron'] = [];
$GLOBALS['w7_actions'] = [];
$GLOBALS['w7_spawn_count'] = 0;
$GLOBALS['w7_do_action_log'] = [];

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['w7_actions'][] = compact('hook', 'callback', 'priority', 'accepted_args');
}

/**
 * محاكاة حقيقية (لا مجرد تسجيل) لِـdo_action() — تستدعي فعلياً كل مستمع
 * مُسجَّل عبر add_action() لنفس الـHook، بنفس عدد المعاملات المُعلَن
 * (accepted_args). ضرورية هنا تحديداً (خلافاً لملفات اختبار أخرى في
 * المشروع لا تختبر تفويض Hook فعلياً): D2-W7 Fix Pass 1 يعتمد على أن
 * إطلاق pge_invitation_send_work_available/'init' فعلياً يُشغِّل مستمع
 * المُنسِّق دون أي نداء مباشر مكتوب في الاختبار نفسه — هذا هو بالضبط ما
 * تثبته هذه المحاكاة.
 */
function do_action($hook, ...$args)
{
    $GLOBALS['w7_do_action_log'][] = ['hook' => $hook, 'args' => $args];
    foreach ($GLOBALS['w7_actions'] as $reg) {
        if ($reg['hook'] !== $hook) {
            continue;
        }
        $cb_args = array_slice($args, 0, max(0, (int) $reg['accepted_args']));
        call_user_func_array($reg['callback'], $cb_args);
    }
}

function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    $GLOBALS['w7_cron'][] = compact('timestamp', 'hook', 'args');
    return true;
}

function wp_next_scheduled($hook, $args = [])
{
    foreach ($GLOBALS['w7_cron'] as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['timestamp'];
        }
    }
    return false;
}

function spawn_cron()
{
    $GLOBALS['w7_spawn_count']++;
}

function w7_clear_cron()
{
    $GLOBALS['w7_cron'] = [];
    $GLOBALS['w7_spawn_count'] = 0;
}

// ══════════════════════════════════════════════════════════════════════
// Fake $wpdb — محاكاة GET_LOCK/RELEASE_LOCK حقيقيين (نفس فلسفة Fake_WPDB
// القائمة في test-thank-you-batch-worker-phase4b26.php وملفات أخرى عدة).
// ══════════════════════════════════════════════════════════════════════

class W7_Fake_WPDB
{
    public $locks = [];

    public function prepare($query, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
            $arg = $args[$i] ?? '';
            $i++;
            if ($m[0] === '%d') {
                return (string) (int) $arg;
            }
            return "'" . str_replace("'", "''", (string) $arg) . "'";
        }, $query);
    }

    public function get_var($query)
    {
        if (preg_match("/GET_LOCK\('([^']+)',\s*(-?\d+)\)/", $query, $m)) {
            $name = $m[1];
            if (!empty($this->locks[$name])) {
                return 0;
            }
            $this->locks[$name] = true;
            return 1;
        }
        return null;
    }

    public function query($query)
    {
        if (preg_match("/RELEASE_LOCK\('([^']+)'\)/", $query, $m)) {
            unset($this->locks[$m[1]]);
        }
        return 1;
    }
}

$GLOBALS['wpdb'] = new W7_Fake_WPDB();

// ══════════════════════════════════════════════════════════════════════
// تزييف اعتماديتَي المُنسِّق الوحيدتين: D2-W5 (Queue) وD2-W6 (Worker) —
// سلوكهما الحقيقي مُثبَت بالفعل في مجموعتَي اختباراتهما الخاصتين؛ هذا الملف
// يختبر فقط منطق التنسيق نفسه (اكتشاف/جدولة/دفعة/قفل)، لا يُعيد إثبات D2-W5/
// D2-W6.
// ══════════════════════════════════════════════════════════════════════

class PGE_Invitation_Send_Queue
{
    public static $queued_rows = [];
    public static $recoverable_rows = [];
    public static $recover_results = [];
    public static $calls = [
        'find_queued_pending_attempts'      => [],
        'find_recoverable_pending_attempts' => [],
        're_enqueue_recoverable'            => [],
    ];

    public static function reset()
    {
        self::$queued_rows = [];
        self::$recoverable_rows = [];
        self::$recover_results = [];
        self::$calls = [
            'find_queued_pending_attempts'      => [],
            'find_recoverable_pending_attempts' => [],
            're_enqueue_recoverable'            => [],
        ];
    }

    public static function find_queued_pending_attempts($limit = 50): array
    {
        self::$calls['find_queued_pending_attempts'][] = (int) $limit;
        return array_slice(self::$queued_rows, 0, (int) $limit);
    }

    public static function find_recoverable_pending_attempts($limit = 50): array
    {
        self::$calls['find_recoverable_pending_attempts'][] = (int) $limit;
        return array_slice(self::$recoverable_rows, 0, (int) $limit);
    }

    public static function re_enqueue_recoverable($max = 20): array
    {
        self::$calls['re_enqueue_recoverable'][] = (int) $max;
        return self::$recover_results;
    }
}

class PGE_Invitation_Send_Worker
{
    public static $call_log = [];
    public static $results_by_id = [];

    public static function reset()
    {
        self::$call_log = [];
        self::$results_by_id = [];
    }

    public static function process_log_id($log_id): array
    {
        $id = (int) $log_id;
        self::$call_log[] = $id;
        $result = self::$results_by_id[$id] ?? ['result' => 'sent', 'log_id' => $id, 'reason' => 'accepted'];

        // يُحاكي سلوك D2-W6 الحقيقي بدقة (راجع remove_queue_item() في
        // class-pge-invitation-send-worker.php الحقيقي): عنصر الطابور
        // يُزال فقط عند نتيجة نهائية فعلية — أي نتيجة ما عدا
        // retryable_error يبقى الصف كما هو ليُعاد اكتشافه لاحقاً. ضروري كي
        // تختبر أقسام Q/R أدناه استمرار/عدم استمرار الجدولة بدلالة العمل
        // المتبقي الفعلي، لا حالة ثابتة.
        if (($result['result'] ?? '') !== 'retryable_error') {
            PGE_Invitation_Send_Queue::$queued_rows = array_values(array_filter(
                PGE_Invitation_Send_Queue::$queued_rows,
                function ($row) use ($id) {
                    return (int) ($row['id'] ?? 0) !== $id;
                }
            ));
        }

        return $result;
    }
}

require_once dirname(__DIR__) . '/includes/class-pge-invitation-send-orchestrator.php';

function w7_reset()
{
    PGE_Invitation_Send_Queue::reset();
    PGE_Invitation_Send_Worker::reset();
    w7_clear_cron();
    $GLOBALS['wpdb']->locks = [];
    $GLOBALS['w7_do_action_log'] = [];
    // ملاحظة: $GLOBALS['w7_actions'] عمداً **لا** يُصفَّر هنا — تسجيلات
    // add_action() تحدث مرة واحدة فقط عند تحميل الملف (require_once)، تماماً
    // كما في الإنتاج الحقيقي؛ إعادة تصفيرها بين الأقسام كانت ستُخفي عيباً
    // حقيقياً لو أُعيد التسجيل خطأً عند كل تحميل.
}

// ══════════════════════════════════════════════════════════════════════
// A. run_batch() — يكتشف العمل حصراً عبر find_queued_pending_attempts()
//    ويُنفِّذ Worker::process_log_id() مرة واحدة فقط لكل صف مُكتشَف.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 101], ['id' => 102], ['id' => 103]];
$a = PGE_Invitation_Send_Orchestrator::run_batch(10);
check('A1. processed = عدد الصفوف المكتشَفة', $a['processed'], 3);
check('A2. Worker استُدعي بالضبط لكل log_id مرة واحدة، بنفس الترتيب', PGE_Invitation_Send_Worker::$call_log, [101, 102, 103]);
check('A3. نتائج Worker تُعاد كما هي دون أي تعديل', $a['results'][0]['result'] ?? null, 'sent');
check('A4. لا سبب فشل (reason=null) عند نجاح التشغيل', $a['reason'], null);

// ══════════════════════════════════════════════════════════════════════
// B. run_batch() — يمرِّر limit كما هو إلى find_queued_pending_attempts().
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1], ['id' => 2]];
PGE_Invitation_Send_Orchestrator::run_batch(7);
check('B1. limit المطلوب (7) مُرِّر حرفياً إلى find_queued_pending_attempts()', PGE_Invitation_Send_Queue::$calls['find_queued_pending_attempts'], [7]);

// ══════════════════════════════════════════════════════════════════════
// C. run_batch() — طابور فارغ: processed=0، لا استدعاء Worker إطلاقاً.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
$c = PGE_Invitation_Send_Orchestrator::run_batch(10);
check('C1. processed = 0 عند عدم وجود عمل مُطابَر', $c['processed'], 0);
check_true('C2. لا استدعاء Worker إطلاقاً', empty(PGE_Invitation_Send_Worker::$call_log));

// ══════════════════════════════════════════════════════════════════════
// D. run_batch() — استدعاء واحد بالضبط لكل log_id مميَّز (لا تكرار).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 55]];
PGE_Invitation_Send_Orchestrator::run_batch(10);
check('D1. log_id واحد → استدعاء Worker واحد بالضبط', count(PGE_Invitation_Send_Worker::$call_log), 1);

// ══════════════════════════════════════════════════════════════════════
// E. run_batch() — قفل الدفعة يمنع تشغيلاً متزامناً: القفل محجوز مسبقاً
//    → batch_lock_not_acquired، لا استدعاء Worker إطلاقاً.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 9]];
$GLOBALS['wpdb']->locks['pge_invsend_orchestrator_batch_lock'] = true; // محاكاة تشغيل آخر يحمل القفل فعلاً
$e = PGE_Invitation_Send_Orchestrator::run_batch(10);
check('E1. processed = 0 عند تعذر الحصول على قفل الدفعة', $e['processed'], 0);
check('E2. reason = batch_lock_not_acquired', $e['reason'], 'batch_lock_not_acquired');
check_true('E3. لا استدعاء Worker إطلاقاً أثناء تعارض القفل', empty(PGE_Invitation_Send_Worker::$call_log));

// ══════════════════════════════════════════════════════════════════════
// F. run_batch() — القفل يُحرَّر دوماً بعد التشغيل (finally) — تشغيل تالٍ
//    ينجح بلا أي تسرّب قفل.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 21]];
PGE_Invitation_Send_Orchestrator::run_batch(10);
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 22]];
$f = PGE_Invitation_Send_Orchestrator::run_batch(10);
check('F1. تشغيل ثانٍ بعد تشغيل ناجح لا يُعاق بقفل مُتسرِّب', $f['reason'], null);
check('F2. Worker استُدعي في كلا التشغيلين (21 ثم 22)', PGE_Invitation_Send_Worker::$call_log, [21, 22]);

// ══════════════════════════════════════════════════════════════════════
// G. run_batch() — has_more صحيح: true عند امتلاء الدفعة بالضبط، false غير ذلك.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1], ['id' => 2]];
$g1 = PGE_Invitation_Send_Orchestrator::run_batch(2);
check_true('G1. has_more=true عند امتلاء الدفعة بالضبط (2 صف لِـlimit=2)', $g1['has_more']);

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1]];
$g2 = PGE_Invitation_Send_Orchestrator::run_batch(5);
check_true('G2. has_more=false عندما الصفوف المكتشَفة أقل من limit', !$g2['has_more']);

// ══════════════════════════════════════════════════════════════════════
// H. run_batch() — كل أنواع نتائج Worker تُعاد كما هي دون أي تفسير/تعديل
//    من المُنسِّق (لا Hot Loop، لا إعادة معالجة لأي نتيجة داخل نفس التشغيل).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [
    ['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4],
    ['id' => 5], ['id' => 6], ['id' => 7], ['id' => 8],
];
PGE_Invitation_Send_Worker::$results_by_id = [
    1 => ['result' => 'sent', 'log_id' => 1, 'reason' => 'accepted'],
    2 => ['result' => 'failed', 'log_id' => 2, 'reason' => 'rejected'],
    3 => ['result' => 'ambiguous_transport_error', 'log_id' => 3, 'reason' => 'transport_error'],
    4 => ['result' => 'not_authorized', 'log_id' => 4, 'reason' => 'authorization_lapsed'],
    5 => ['result' => 'lifecycle_mismatch', 'log_id' => 5, 'reason' => 'lifecycle_changed'],
    6 => ['result' => 'already_terminal', 'log_id' => 6, 'reason' => 'status_sent'],
    7 => ['result' => 'retryable_error', 'log_id' => 7, 'reason' => 'execution_lock_not_acquired'],
    8 => ['result' => 'invalid', 'log_id' => 8, 'reason' => 'invalid_log_id'],
];
$h = PGE_Invitation_Send_Orchestrator::run_batch(10);
check('H1. processed = 8 (كل الأنواع مُعالَجة مرة واحدة لكل منها)', $h['processed'], 8);
check('H2. نتيجة retryable_error تُعاد كما هي بلا أي إعادة استدعاء فوري لنفس log_id', array_count_values(PGE_Invitation_Send_Worker::$call_log)[7] ?? 0, 1);
check_true('H3. كل الأنواع الثمانية حاضرة في results بلا فقدان', count($h['results']) === 8);

// ══════════════════════════════════════════════════════════════════════
// I. schedule_if_needed() — يُجدوِل عند وجود عمل ولا جدولة سابقة، ويستدعي spawn_cron().
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1]];
$i = PGE_Invitation_Send_Orchestrator::schedule_if_needed(5);
check_true('I1. يُجدوِل Tick جديد فعلاً (يُعيد true)', $i);
check('I2. حدث Cron واحد فقط مُسجَّل', count($GLOBALS['w7_cron']), 1);
check('I3. Hook المُجدوَل هو CRON_HOOK الصحيح', $GLOBALS['w7_cron'][0]['hook'], PGE_Invitation_Send_Orchestrator::CRON_HOOK);
check('I4. spawn_cron() استُدعيت مرة واحدة', $GLOBALS['w7_spawn_count'], 1);

// ══════════════════════════════════════════════════════════════════════
// J. schedule_if_needed() — لا تكرار: مُجدوَل فعلاً (Dedup عبر wp_next_scheduled).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1]];
$GLOBALS['w7_cron'][] = ['timestamp' => time() + 25, 'hook' => PGE_Invitation_Send_Orchestrator::CRON_HOOK, 'args' => []];
$j = PGE_Invitation_Send_Orchestrator::schedule_if_needed(5);
check('J1. لا يُجدوِل Tick ثانياً (يُعيد false)', $j, false);
check('J2. لا يزال حدث واحد فقط مُسجَّل (لا تكرار)', count($GLOBALS['w7_cron']), 1);

// ══════════════════════════════════════════════════════════════════════
// K. schedule_if_needed() — لا عمل إطلاقاً (لا مُطابَر ولا قابل للاسترداد)
//    → لا جدولة غير ضرورية.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
$k = PGE_Invitation_Send_Orchestrator::schedule_if_needed(5);
check('K1. لا يُجدوِل بلا عمل حقيقي (يُعيد false)', $k, false);
check_true('K2. لا حدث Cron مُسجَّل إطلاقاً', empty($GLOBALS['w7_cron']));
check('K3. spawn_cron() لم تُستدعَ إطلاقاً', $GLOBALS['w7_spawn_count'], 0);

// ══════════════════════════════════════════════════════════════════════
// L. schedule_if_needed() — عمل قابل للاسترداد فقط (لا عمل مُطابَر) لا يزال
//    يُجدوِل — العمل ليس مصدره الطابور المباشر حصراً.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$recoverable_rows = [['id' => 77]];
$l = PGE_Invitation_Send_Orchestrator::schedule_if_needed(5);
check_true('L1. يُجدوِل استناداً إلى عمل قابل للاسترداد فقط', $l);

// ══════════════════════════════════════════════════════════════════════
// M. schedule_if_needed() — يحترم delay_seconds المُمرَّر فعلياً (ويحصر
//    القيم السالبة إلى صفر).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1]];
$before = time();
PGE_Invitation_Send_Orchestrator::schedule_if_needed(25);
$after = time();
$scheduled_ts = $GLOBALS['w7_cron'][0]['timestamp'];
check_true('M1. الطابع الزمني المُجدوَل ضمن [now+25, now+25] بهامش تسامح ثانيتين', $scheduled_ts >= $before + 25 && $scheduled_ts <= $after + 27);

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1]];
$before2 = time();
PGE_Invitation_Send_Orchestrator::schedule_if_needed(-100);
$scheduled_ts2 = $GLOBALS['w7_cron'][0]['timestamp'];
check_true('M2. delay_seconds سالب يُحصَر إلى صفر (لا جدولة في الماضي)', $scheduled_ts2 >= $before2 && $scheduled_ts2 <= $before2 + 2);

// ══════════════════════════════════════════════════════════════════════
// N. recover_and_schedule() — يستدعي re_enqueue_recoverable(RECOVERY_LIMIT)
//    مرة واحدة بالضبط، ويُجدوِل إن بقي عمل.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$recover_results = [201 => ['result' => 'queued']];
PGE_Invitation_Send_Queue::$recoverable_rows = [['id' => 201]]; // ما يزال قابلاً للاكتشاف (يُحاكي: أُعيد طابرته لكن find_recoverable لا يزال يعكس الحالة القديمة في هذا Fake البسيط) — أو مُطابَر فعلياً:
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 201]];
$n = PGE_Invitation_Send_Orchestrator::recover_and_schedule();
check('N1. re_enqueue_recoverable(RECOVERY_LIMIT) استُدعيت مرة واحدة بالضبط بالحد الصحيح', PGE_Invitation_Send_Queue::$calls['re_enqueue_recoverable'], [PGE_Invitation_Send_Orchestrator::RECOVERY_LIMIT]);
check('N2. النتيجة المُعادة من re_enqueue_recoverable() تُعاد كما هي في recovered', $n['recovered'], PGE_Invitation_Send_Queue::$recover_results);
check_true('N3. scheduled=true لأن عملاً حقيقياً بقي (queued_rows غير فارغة)', $n['scheduled']);

// ══════════════════════════════════════════════════════════════════════
// O. recover_and_schedule() — لا استرداد ولا عمل مُطابَر → لا جدولة.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
$o = PGE_Invitation_Send_Orchestrator::recover_and_schedule();
check_true('O1. scheduled=false بلا أي عمل حقيقي', !$o['scheduled']);
check_true('O2. لا حدث Cron مُسجَّل', empty($GLOBALS['w7_cron']));

// ══════════════════════════════════════════════════════════════════════
// P. run_cron_tick() — تركيب بحت: re_enqueue_recoverable() مرة واحدة،
//    run_batch() مرة واحدة، schedule_if_needed(RETRY_DELAY_SECONDS) مرة واحدة.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 301]];
// عمل قابل للاسترداد يبقى موجوداً (Fake لا يُسقِط recoverable_rows تلقائياً
// عند re_enqueue_recoverable — راجع توثيق N/Q) كي تعكس P4 فعلياً حالة
// "لا يزال هناك عمل حقيقي، لذا جُدوِل استمرار" — لا حالة استنفاد (تلك حالة R).
PGE_Invitation_Send_Queue::$recoverable_rows = [['id' => 999]];
$before_p = time();
$p = PGE_Invitation_Send_Orchestrator::run_cron_tick();
check('P1. re_enqueue_recoverable() استُدعيت مرة واحدة بالضبط', count(PGE_Invitation_Send_Queue::$calls['re_enqueue_recoverable']), 1);
// find_queued_pending_attempts() تُستدعى مرتين ضمن Tick واحد: مرة عبر
// run_batch() بحد الدفعة (DEFAULT_BATCH_SIZE)، ومرة أخرى عبر فحص
// schedule_if_needed() الداخلي (بحد 1) للتأكد من وجود عمل قبل الجدولة —
// كلا الاستدعاءين قراءة بحتة بلا أثر جانبي، لا ازدواج تنفيذ فعلي.
check('P2. find_queued_pending_attempts() استُدعيت مرتين بالضبط (دفعة واحدة + فحص جدولة واحد)، بالحدَّين الصحيحين', PGE_Invitation_Send_Queue::$calls['find_queued_pending_attempts'], [PGE_Invitation_Send_Orchestrator::DEFAULT_BATCH_SIZE, 1]);
check('P3. Worker استُدعي للسجل المُطابَر (301) خلال هذا الـTick', PGE_Invitation_Send_Worker::$call_log, [301]);
check_true('P4. الطابع الزمني للجدولة يعكس RETRY_DELAY_SECONDS (25) لا IMMEDIATE_DELAY_SECONDS', count($GLOBALS['w7_cron']) === 1 && $GLOBALS['w7_cron'][0]['timestamp'] >= $before_p + PGE_Invitation_Send_Orchestrator::RETRY_DELAY_SECONDS);

// ══════════════════════════════════════════════════════════════════════
// Q. run_cron_tick() — يُجدوِل استمراراً عندما تبقى دفعة ممتلئة (has_more)
//    حتى لو لم يبقَ عمل قابل للاسترداد.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1], ['id' => 2]]; // = DEFAULT_BATCH_SIZE سيكون أكبر عادة، لكن find_queued يُقصَّر بالـlimit الفعلي المُمرَّر (10) — هنا فقط صفّان أقل من limit، has_more=false، فلا استمرار عبر has_more. نستخدم بدلاً: عمل يبقى قابلاً للاسترداد.
PGE_Invitation_Send_Queue::$recoverable_rows = [['id' => 3]];
$q = PGE_Invitation_Send_Orchestrator::run_cron_tick();
check_true('Q1. scheduled_continuation=true لأن عملاً قابلاً للاسترداد لا يزال موجوداً بعد الدفعة', $q['scheduled_continuation']);

// ══════════════════════════════════════════════════════════════════════
// R. run_cron_tick() — لا استمرار عند استنفاد كل العمل (لا مُطابَر، لا قابل للاسترداد).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 401]];
$r = PGE_Invitation_Send_Orchestrator::run_cron_tick();
check_true('R1. scheduled_continuation=false عند استنفاد كل العمل بعد الدفعة', !$r['scheduled_continuation']);
check_true('R2. لا حدث Cron مُسجَّل إطلاقاً بعد الاستنفاد الكامل', empty($GLOBALS['w7_cron']));

// ══════════════════════════════════════════════════════════════════════
// S. اكتشاف العمل حصراً عبر D2-W5 — لا استعلام SQL مباشر على pge_message_log
//    من هذا الملف (فحص مصدر Source-Scan).
// ══════════════════════════════════════════════════════════════════════

// فحص المصدر يعمل على الكود الفعلي فقط بعد إزالة كل التعليقات (// و/* */
// وDocblocks) — التوثيق الأعلى للملف نفسه يذكر عمداً كل الاستدعاءات
// الممنوعة بالاسم (لتوضيح الحدود للقارئ البشري)، فلا يصح فحص النص الخام
// كما هو (كان سيُنتج إيجابيات كاذبة Fail على التوثيق نفسه، لا على الكود).
function w7_strip_comments($source)
{
    $tokens = token_get_all($source);
    $out = '';
    foreach ($tokens as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

$orchestrator_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-invitation-send-orchestrator.php');
$orchestrator_code = w7_strip_comments($orchestrator_source);
check_true('S1. لا إشارة مباشرة لجدول pge_message_log في الكود الفعلي (بعد إزالة التعليقات)', strpos($orchestrator_code, 'pge_message_log') === false);
check_true('S2. لا استدعاء PGE_Message_Log:: مباشر من هذا الملف', strpos($orchestrator_code, 'PGE_Message_Log::') === false);

// ══════════════════════════════════════════════════════════════════════
// T. الحالة النهائية 'cancelled' مُستبعَدة بنيوياً — لا فلترة إضافية بهذا
//    الملف (تُستبعَد فعلياً في مصدر البيانات نفسه D2-W5، راجع توثيق رأس الملف).
// ══════════════════════════════════════════════════════════════════════

check_true('T1. لا أي إشارة لِـ"cancelled" كفلتر عمل داخل منطق التنفيذ (مُستبعَدة بنيوياً عبر D2-W5 فقط، لا تكرار منطق هنا)', strpos($orchestrator_code, "'cancelled'") === false && strpos($orchestrator_code, 'STATUS_CANCELLED') === false);

// ══════════════════════════════════════════════════════════════════════
// U. تعارض قفل الدفعة لا يُنتج أي ازدواج تنفيذ لنفس log_id.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 501]];
$GLOBALS['wpdb']->locks['pge_invsend_orchestrator_batch_lock'] = true;
PGE_Invitation_Send_Orchestrator::run_batch(10); // يُرفَض بسبب القفل
unset($GLOBALS['wpdb']->locks['pge_invsend_orchestrator_batch_lock']);
PGE_Invitation_Send_Orchestrator::run_batch(10); // ينجح الآن
check('U1. log_id=501 عولج مرة واحدة بالضبط رغم محاولتَي run_batch (الأولى مرفوضة بالقفل)', PGE_Invitation_Send_Worker::$call_log, [501]);

// ══════════════════════════════════════════════════════════════════════
// V. لا تداخل مع قفل D2-W6 الخاص بكل log_id — لا إشارة لاسم/نمط قفل
//    execution_lock_name() الخاص بـPGE_Invitation_Send_Worker من هذا الملف.
// ══════════════════════════════════════════════════════════════════════

check_true('V1. لا إشارة لبادئة قفل التنفيذ الخاص بـD2-W6 (pge_invsend_exec_) في هذا الملف', strpos($orchestrator_code, 'pge_invsend_exec_') === false);
check_true('V2. لا إعادة تعريف/استدعاء execution_lock_name من هذا الملف', strpos($orchestrator_code, 'execution_lock_name') === false);

// ══════════════════════════════════════════════════════════════════════
// W. حد الدفعة الأقصى الدفاعي (MAX_BATCH_SIZE) يُطبَّق دوماً بصرف النظر عن
//    القيمة المطلوبة.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Orchestrator::run_batch(999999);
check('W1. limit المُمرَّر فعلياً إلى find_queued_pending_attempts() لا يتجاوز MAX_BATCH_SIZE أبداً', PGE_Invitation_Send_Queue::$calls['find_queued_pending_attempts'], [PGE_Invitation_Send_Orchestrator::MAX_BATCH_SIZE]);

w7_reset();
PGE_Invitation_Send_Orchestrator::run_batch(0);
check('W2. limit=0 (أو أقل) يُصحَّح دوماً إلى حد أدنى 1', PGE_Invitation_Send_Queue::$calls['find_queued_pending_attempts'], [1]);

// ══════════════════════════════════════════════════════════════════════
// X. تسجيلات Hook عند تحميل الملف — أربعة بالضبط (D2-W7 Fix Pass 1 أضافت
//    ثلاثة إلى معالج CRON_HOOK الأصلي)، كل واحد يُفوِّض للمُنسِّق حصراً.
// ══════════════════════════════════════════════════════════════════════

check('X1. add_action() استُدعيت 4 مرات بالضبط عند تحميل الملف (CRON_HOOK + work_available + RECOVERY_CRON_HOOK + init)', count($GLOBALS['w7_actions']), 4);
check('X2. التسجيل الأول: CRON_HOOK ← run_cron_tick', [$GLOBALS['w7_actions'][0]['hook'], $GLOBALS['w7_actions'][0]['callback']], [PGE_Invitation_Send_Orchestrator::CRON_HOOK, [PGE_Invitation_Send_Orchestrator::class, 'run_cron_tick']]);
check('X3. التسجيل الثاني: pge_invitation_send_work_available ← work_available', [$GLOBALS['w7_actions'][1]['hook'], $GLOBALS['w7_actions'][1]['callback']], ['pge_invitation_send_work_available', [PGE_Invitation_Send_Orchestrator::class, 'work_available']]);
check('X4. التسجيل الثالث: RECOVERY_CRON_HOOK ← run_recovery_check', [$GLOBALS['w7_actions'][2]['hook'], $GLOBALS['w7_actions'][2]['callback']], [PGE_Invitation_Send_Orchestrator::RECOVERY_CRON_HOOK, [PGE_Invitation_Send_Orchestrator::class, 'run_recovery_check']]);
check('X5. التسجيل الرابع: init ← ensure_recovery_scheduled (ضمان الإقلاع)', [$GLOBALS['w7_actions'][3]['hook'], $GLOBALS['w7_actions'][3]['callback']], ['init', [PGE_Invitation_Send_Orchestrator::class, 'ensure_recovery_scheduled']]);
check('X6. لا تسجيل add_action() خامس غير مُتوقَّع في نص الملف', substr_count($orchestrator_code, 'add_action(') === 4, true);

// ══════════════════════════════════════════════════════════════════════
// Y. لا AJAX/UI إطلاقاً في هذا الملف (فحص مصدر).
// ══════════════════════════════════════════════════════════════════════

check_true('Y1. لا wp_ajax_ في نص الملف', strpos($orchestrator_code, 'wp_ajax_') === false);
check_true('Y2. لا add_menu_page/add_submenu_page في نص الملف', strpos($orchestrator_code, 'add_menu_page') === false && strpos($orchestrator_code, 'add_submenu_page') === false);
check_true('Y3. لا wp_enqueue_script/wp_enqueue_style في نص الملف', strpos($orchestrator_code, 'wp_enqueue_') === false);
check_true('Y4. لا check_ajax_referer/wp_send_json في نص الملف', strpos($orchestrator_code, 'check_ajax_referer') === false && strpos($orchestrator_code, 'wp_send_json') === false);

// ══════════════════════════════════════════════════════════════════════
// Z. لا اعتماد على Cartat/UltraMsg/نقل أي نوع من هذا الملف (فحص مصدر).
// ══════════════════════════════════════════════════════════════════════

check_true('Z1. لا إشارة لِـCartat في نص الملف', strpos($orchestrator_code, 'Cartat') === false);
check_true('Z2. لا إشارة لِـUltraMsg في نص الملف', strpos($orchestrator_code, 'UltraMsg') === false);
check_true('Z3. لا send_text()/send_media() في نص الملف', strpos($orchestrator_code, 'send_text') === false && strpos($orchestrator_code, 'send_media') === false);

// ══════════════════════════════════════════════════════════════════════
// AA. لا Schema/Migration جديد من هذا الملف (فحص مصدر).
// ══════════════════════════════════════════════════════════════════════

check_true('AA1. لا CREATE TABLE في نص الملف', stripos($orchestrator_code, 'CREATE TABLE') === false);
check_true('AA2. لا dbDelta في نص الملف', strpos($orchestrator_code, 'dbDelta') === false);
check_true('AA3. لا ALTER TABLE في نص الملف', stripos($orchestrator_code, 'ALTER TABLE') === false);

// ══════════════════════════════════════════════════════════════════════
// AB. لا استدعاء مباشر إطلاقاً لطبقات claim/finalize/إعادة التخويل من هذا
//     الملف — نقطة التنفيذ الوحيدة المسموحة هي PGE_Invitation_Send_Worker::
//     process_log_id() فقط (فحص مصدر شامل).
// ══════════════════════════════════════════════════════════════════════

$forbidden_calls = [
    '::claim(', 'finalize_success', 'finalize_failure', 'finalize_cancelled',
    'resolve_context', 'can_send_guest_invitation', 'get_guest_assignment',
    'PGE_Message_Content_Resolver', 'PGE_Event_Access_Authorization',
    'PGE_Event_Access_Repository', 'PGE_Invitation_Repository',
    'PGE_Invitation_Send_Ledger', 'PGE_Invitation_Send_State',
    'PGE_Invitation_Send_Application',
];
foreach ($forbidden_calls as $needle) {
    check_true("AB. لا إشارة إطلاقاً لِـ\"$needle\" في نص الملف", strpos($orchestrator_code, $needle) === false);
}
check_true('AB-final. نقطة التنفيذ الوحيدة هي PGE_Invitation_Send_Worker::process_log_id فعلياً موجودة', strpos($orchestrator_code, 'PGE_Invitation_Send_Worker::process_log_id') !== false);
check('AB-count. PGE_Invitation_Send_Worker::process_log_id تُستدعى مرة واحدة فقط في كامل نص الملف (موضع تنفيذ وحيد)', substr_count($orchestrator_code, 'PGE_Invitation_Send_Worker::process_log_id'), 1);

// ══════════════════════════════════════════════════════════════════════
// AC. get_status() — تركيب صحيح لكل الحقول.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
$ac1 = PGE_Invitation_Send_Orchestrator::get_status();
check_true('AC1. has_work=false بلا أي عمل', !$ac1['has_work']);
check_true('AC2. cron_scheduled=false بلا جدولة', !$ac1['cron_scheduled']);
check('AC3. next_run_at=null بلا جدولة', $ac1['next_run_at'], null);
check_true('AC4. appears_stuck=false بلا عمل أصلاً (لا شيء عالق لأن لا شيء موجود)', !$ac1['appears_stuck']);

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1]];
$ac2 = PGE_Invitation_Send_Orchestrator::get_status();
check_true('AC5. has_queued_work=true عند وجود عمل مُطابَر', $ac2['has_queued_work']);
check_true('AC6. has_work=true بالتبعية', $ac2['has_work']);
check_true('AC7. appears_stuck=true: عمل موجود + لا Tick مُجدوَل + لا قفل مشغول', $ac2['appears_stuck']);

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1]];
$GLOBALS['w7_cron'][] = ['timestamp' => time() + 25, 'hook' => PGE_Invitation_Send_Orchestrator::CRON_HOOK, 'args' => []];
$ac3 = PGE_Invitation_Send_Orchestrator::get_status();
check_true('AC8. cron_scheduled=true عند وجود Tick مُجدوَل فعلاً', $ac3['cron_scheduled']);
check_true('AC9. appears_stuck=false عند وجود Tick مُجدوَل رغم وجود عمل (السلسلة تعمل فعلياً)', !$ac3['appears_stuck']);
check_true('AC10. next_run_at ليس null عند وجود جدولة', $ac3['next_run_at'] !== null);

// ══════════════════════════════════════════════════════════════════════
// AD. get_status() — Probe القفل غير حاجز ولا يُسرِّب القفل (يُحرَّر فوراً).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1]];
$GLOBALS['wpdb']->locks['pge_invsend_orchestrator_batch_lock'] = true; // قفل مشغول فعلياً بتشغيل آخر
$ad1 = PGE_Invitation_Send_Orchestrator::get_status();
check_true('AD1. lock_appears_busy=true عندما القفل محجوز فعلياً بتشغيل آخر', $ad1['lock_appears_busy']);
check_true('AD2. appears_stuck=false عندما القفل مشغول فعلياً (تشغيل جارٍ حقيقي، ليس توقفاً)', !$ad1['appears_stuck']);
unset($GLOBALS['wpdb']->locks['pge_invsend_orchestrator_batch_lock']);

w7_reset();
PGE_Invitation_Send_Orchestrator::get_status();
$ad2 = PGE_Invitation_Send_Orchestrator::run_batch(10); // يجب أن ينجح — لا قفل مُتسرِّب من فحص get_status() السابق
check('AD3. run_batch() بعد get_status() لا يزال قادراً على الحصول على القفل (لا تسرّب من Probe)', $ad2['reason'], null);

// ══════════════════════════════════════════════════════════════════════
// D2-W7 Fix Pass 1 — البلوكر: إثبات مسارَي إيقاظ حقيقيَين (Bootstrap
// Wake-Up)، لا مجرد نقاط دخول مُعرَّضة بلا مُستدعٍ. من هنا فصاعداً do_action()
// الحقيقية (المُعرَّفة أعلاه) تُستخدَم فعلياً لتشغيل المستمعين المُسجَّلين —
// لا نداء مباشر مكتوب لِـwork_available()/ensure_recovery_scheduled() في أي
// من الاختبارات التالية؛ الإطلاق وحده هو ما يُشغِّلها، تماماً كما سيحدث في
// الإنتاج الحقيقي.
// ══════════════════════════════════════════════════════════════════════

$queue_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-invitation-send-queue.php');
$queue_code = w7_strip_comments($queue_source);

// ══════════════════════════════════════════════════════════════════════
// AE. إثبات ثابت (Source-Scan) على جانب D2-W5: do_action() تُطلَق حصراً في
//     مسار الإنشاء الجديد فعلياً (RESULT_QUEUED)، قبل أي من فرعَي
//     already_queued/error نصياً — أي: قبل تنفيذها لا يمكن الوصول إليهما.
// ══════════════════════════════════════════════════════════════════════

check_true('AE1. do_action(pge_invitation_send_work_available) موجودة فعلياً في queue.php', strpos($queue_code, "do_action('pge_invitation_send_work_available'") !== false);
check_true('AE2. do_action() تسبق نصياً فرع RESULT_ALREADY_QUEUED (أي: داخل مسار الإنشاء الجديد فقط، لا مسار الـIdempotent — يُقارَن بموضع استخدامها الفعلي في return، لا بتعريف الثابت أعلى الملف)', strpos($queue_code, "do_action('pge_invitation_send_work_available'") < strpos($queue_code, 'self::RESULT_ALREADY_QUEUED, $normalized_log_id, $existing'));
check_true('AE3. do_action() تسبق نصياً فرع RESULT_ERROR (enqueue_write_failed)', strpos($queue_code, "do_action('pge_invitation_send_work_available'") < strpos($queue_code, "'enqueue_write_failed'"));
check_true('AE4. do_action() مُحاطة بـfunction_exists() دفاعياً (لا Fatal في بيئات اختبار D2-W5 القائمة التي لا تُعرِّف do_action())', strpos($queue_code, "function_exists('do_action')") !== false);
check_true('AE5. لا إشارة لِـCartat/UltraMsg في queue.php', strpos($queue_code, 'Cartat') === false && strpos($queue_code, 'UltraMsg') === false);
check_true('AE6. لا send_text()/send_media() في queue.php', strpos($queue_code, 'send_text') === false && strpos($queue_code, 'send_media') === false);

// ══════════════════════════════════════════════════════════════════════
// AF. إيقاظ enqueue ناجح — إطلاق حقيقي لِـdo_action() (يُحاكي queue.php
//     فعلياً بعد الإنشاء الجديد) يُشغِّل مستمع المُنسِّق تلقائياً بلا أي نداء
//     مباشر لـschedule_if_needed() مكتوب هنا (A + B من موجز الإصلاح).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 701]]; // العمل المُطابَر حديثاً — القابل للاكتشاف الآن عبر find_queued_pending_attempts()
$before_af = time();
do_action('pge_invitation_send_work_available', 701); // = بالضبط ما تُطلِقه queue.php الحقيقية الآن عند enqueue ناجح
check('AF1. حدث Cron واحد بالضبط جُدوِلَ تلقائياً — بلا أي نداء مباشر لِـschedule_if_needed() في هذا الاختبار', count($GLOBALS['w7_cron']), 1);
check('AF2. الحدث المُجدوَل هو CRON_HOOK نفسه', $GLOBALS['w7_cron'][0]['hook'] ?? null, PGE_Invitation_Send_Orchestrator::CRON_HOOK);
check_true('AF3. الطابع الزمني يعكس IMMEDIATE_DELAY_SECONDS (إيقاظ فوري)، لا RETRY_DELAY_SECONDS', ($GLOBALS['w7_cron'][0]['timestamp'] ?? 0) >= $before_af + PGE_Invitation_Send_Orchestrator::IMMEDIATE_DELAY_SECONDS && ($GLOBALS['w7_cron'][0]['timestamp'] ?? 0) < $before_af + PGE_Invitation_Send_Orchestrator::RETRY_DELAY_SECONDS);

// ══════════════════════════════════════════════════════════════════════
// AG. لا ازدواج جدولة (Cron Storm) من إطلاقات متكررة — enqueue ناجحة متتالية
//     لعدة log_id مختلفة (C)، وإطلاقات مُعادة مُحاكية لمسار Idempotent (D).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1], ['id' => 2], ['id' => 3]];
do_action('pge_invitation_send_work_available', 1);
do_action('pge_invitation_send_work_available', 2);
do_action('pge_invitation_send_work_available', 3);
check('AG1. ثلاث إطلاقات enqueue ناجحة متتالية → حدث Cron واحد بالضبط (Dedup عبر wp_next_scheduled)', count($GLOBALS['w7_cron']), 1);

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 9]];
for ($i = 0; $i < 5; $i++) {
    // حتى لو أُطلِقت الإشارة خطأً عدة مرات (مثلاً محاكاة مسار Idempotent) —
    // لا ازدواج أبداً، لأن الحاكم الوحيد هو wp_next_scheduled عند المستمع.
    do_action('pge_invitation_send_work_available', 9);
}
check('AG2. خمس إطلاقات مُكرَّرة لنفس log_id → لا يزال حدث Cron واحد بالضبط', count($GLOBALS['w7_cron']), 1);

// ══════════════════════════════════════════════════════════════════════
// AH. ضمان إقلاع شبكة الأمان الدورية — عبر 'init' الحقيقي فقط، بلا أي نداء
//     مباشر لِـensure_recovery_scheduled() مكتوب هنا (يُثبِت "مسار إيقاظ حقيقي
//     مُسجَّل"، لا مجرد دالة مُعرَّضة — القسم 5 من موجز الإصلاح).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
$before_ah = time();
do_action('init'); // = بالضبط ما يحدث عند كل تحميل صفحة WordPress حقيقي
check('AH1. RECOVERY_CRON_HOOK جُدوِلَ تلقائياً عند أول init، بلا أي enqueue سابق إطلاقاً', count($GLOBALS['w7_cron']), 1);
check('AH2. الحدث المُجدوَل هو RECOVERY_CRON_HOOK نفسه', $GLOBALS['w7_cron'][0]['hook'] ?? null, PGE_Invitation_Send_Orchestrator::RECOVERY_CRON_HOOK);
check_true('AH3. الطابع الزمني يعكس RECOVERY_CHECK_INTERVAL_SECONDS (900)', ($GLOBALS['w7_cron'][0]['timestamp'] ?? 0) >= $before_ah + PGE_Invitation_Send_Orchestrator::RECOVERY_CHECK_INTERVAL_SECONDS);

do_action('init'); // init ثانٍ (تحميل صفحة تالٍ) — Dedup يمنع التكرار
check('AH4. init ثانٍ لا يُضاعِف الجدولة (لا يزال حدث واحد بالضبط)', count($GLOBALS['w7_cron']), 1);

// ══════════════════════════════════════════════════════════════════════
// AI. تشغيل شبكة الأمان الدورية فعلياً (RECOVERY_CRON_HOOK يُطلَق) — يستعيد
//     اليتيم، يُجدوِل CRON_HOOK إن نتج عمل، ويُعيد جدولة نفسه دوماً (E–H).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$recoverable_rows = [['id' => 801]]; // يتيم: pending بلا طابور ولا Cron مُجدوَل أصلاً
$before_ai = time();
do_action(PGE_Invitation_Send_Orchestrator::RECOVERY_CRON_HOOK); // = بالضبط ما يحدث عند إطلاق الـTick الدوري
check('AI1. re_enqueue_recoverable() استُدعيت مرة واحدة بالحد الصحيح (I: محدودة العدد)', PGE_Invitation_Send_Queue::$calls['re_enqueue_recoverable'], [PGE_Invitation_Send_Orchestrator::RECOVERY_LIMIT]);
check_true('AI2. CRON_HOOK جُدوِلَ (H: الـWorker Cron أصبح مُجدوَلاً الآن) لأن عملاً قابلاً للاسترداد وُجد', wp_next_scheduled(PGE_Invitation_Send_Orchestrator::CRON_HOOK) !== false);
check_true('AI3. RECOVERY_CRON_HOOK أُعيدت جدولته لنفسه أيضاً (السلسلة لا تتوقف أبداً)', wp_next_scheduled(PGE_Invitation_Send_Orchestrator::RECOVERY_CRON_HOOK) !== false);
check_true('AI4. الطابع الزمني لإعادة جدولة RECOVERY_CRON_HOOK يعكس 900 ثانية مجدداً', wp_next_scheduled(PGE_Invitation_Send_Orchestrator::RECOVERY_CRON_HOOK) >= $before_ai + PGE_Invitation_Send_Orchestrator::RECOVERY_CHECK_INTERVAL_SECONDS);
check_true('AI5 (J). Worker لم يُستدعَ إطلاقاً من داخل معالج شبكة الأمان — لا مسح/معالجة مباشرة هنا', empty(PGE_Invitation_Send_Worker::$call_log));

// ══════════════════════════════════════════════════════════════════════
// AJ. نظام فارغ تماماً (L) — لا عمل مُطابَر ولا قابل للاسترداد إطلاقاً: init
//     يُجدوِل شبكة الأمان فقط (نبضها الدوري المتوقَّع)، بلا أي حدث CRON_HOOK
//     وبلا أي استدعاء Worker.
// ══════════════════════════════════════════════════════════════════════

w7_reset();
do_action('init');
check('AJ1. RECOVERY_CRON_HOOK وحده مُجدوَل — نبض شبكة الأمان المتوقَّع حتى بلا عمل', count($GLOBALS['w7_cron']), 1);
check_true('AJ2. لا CRON_HOOK مُجدوَل إطلاقاً (لا عمل حقيقي لتنفيذه)', wp_next_scheduled(PGE_Invitation_Send_Orchestrator::CRON_HOOK) === false);
check_true('AJ3. لا استدعاء Worker إطلاقاً في نظام فارغ', empty(PGE_Invitation_Send_Worker::$call_log));

// نفّذ الـTick الدوري نفسه أيضاً على نظام فارغ — يجب أن يبقى هادئاً (K/L):
w7_reset();
PGE_Invitation_Send_Orchestrator::run_recovery_check();
check_true('AJ4. run_recovery_check() على نظام فارغ لا يُشغِّل Worker إطلاقاً', empty(PGE_Invitation_Send_Worker::$call_log));
check_true('AJ5. run_recovery_check() على نظام فارغ لا يُجدوِل CRON_HOOK (لا عمل)', wp_next_scheduled(PGE_Invitation_Send_Orchestrator::CRON_HOOK) === false);
check_true('AJ6. run_recovery_check() تُعيد جدولة نفسها رغم عدم وجود عمل (شبكة أمان دائمة، لا مشروطة)', wp_next_scheduled(PGE_Invitation_Send_Orchestrator::RECOVERY_CRON_HOOK) !== false);

// ══════════════════════════════════════════════════════════════════════
// AK. فصل السلسلتين — run_cron_tick() (سلسلة العمل العادية) لا يُجدوِل ولا
//     يُعيد جدولة RECOVERY_CRON_HOOK إطلاقاً (K: كل سلسلة مستقلة عن الأخرى).
// ══════════════════════════════════════════════════════════════════════

w7_reset();
PGE_Invitation_Send_Queue::$queued_rows = [['id' => 1]];
PGE_Invitation_Send_Orchestrator::run_cron_tick();
check_true('AK1. run_cron_tick() لا يُجدوِل RECOVERY_CRON_HOOK إطلاقاً', wp_next_scheduled(PGE_Invitation_Send_Orchestrator::RECOVERY_CRON_HOOK) === false);

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
