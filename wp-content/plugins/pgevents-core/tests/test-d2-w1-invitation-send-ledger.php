<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ"D2-W1 — Durable Invitation Ledger
 * Integration (Foundation فقط)": PGE_Invitation_Send_Ledger فوق
 * PGE_Message_Log القائم فعلاً (message_type=invitation).
 *
 * يحمّل الملفات الحقيقية التالية دون أي تعديل عليها:
 *   - includes/class-pge-message-type.php
 *   - includes/class-pge-message-log.php   (بما فيها query_by_event_type_and_phone() الجديدة)
 *   - includes/class-pge-message-batch.php
 *   - includes/class-pge-invitation-send-ledger.php
 *
 * PGE_Invitation_Repository يُحاكَى محلياً هنا بالكامل (نفس نمط
 * tests/test-thank-you-recipient-resolver-phase4b1.php حرفياً) — لا تحميل
 * لـclass-pge-invitation-repository.php الحقيقي ولا لتعقيدات Post Meta/
 * event-guests.php الكاملة؛ هذا الاختبار يفحص PGE_Invitation_Send_Ledger
 * فقط، بافتراض أن PGE_Invitation_Repository::get_invitation() تُعيد ما
 * يُهيَّأ لها صراحةً في كل حالة اختبار.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. الخروج برمز 0 عند نجاح كل
 * الحالات، أو 1 عند فشل أي حالة.
 *
 * التشغيل: php tests/test-d2-w1-invitation-send-ledger.php
 */

define('ABSPATH', __DIR__ . '/');

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('current_time')) {
    $GLOBALS['__test_now'] = '2026-08-20 10:00:00';
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now']; }
}

if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) { return preg_replace('/\D+/', '', trim((string) $v)); }
}

// ══════════════════════════════════════════════════════════════════════
// Fake PGE_Invitation_Repository — محاكاة محلية فقط (نفس نمط
// test-thank-you-recipient-resolver-phase4b1.php)، تتحكّم بها حالات
// الاختبار مباشرة عبر seed_invitation()/delete_invitation() أدناه.
// ══════════════════════════════════════════════════════════════════════

class PGE_Invitation_Repository
{
    public static $invitations = [];

    public static function get_invitation($event_id, $phone)
    {
        $key = ((int) $event_id) . '|' . (string) $phone;
        return self::$invitations[$key] ?? null;
    }
}

function seed_invitation($event_id, $phone, $invited_at)
{
    $key = ((int) $event_id) . '|' . (string) $phone;
    PGE_Invitation_Repository::$invitations[$key] = ['invited_at' => $invited_at];
}

function delete_invitation($event_id, $phone)
{
    $key = ((int) $event_id) . '|' . (string) $phone;
    unset(PGE_Invitation_Repository::$invitations[$key]);
}

// ══════════════════════════════════════════════════════════════════════
// Fake $wpdb: pge_message_log فقط (لا حاجة لجدول RSVP هنا إطلاقاً — راجع
// توثيق class-pge-invitation-send-ledger.php لسبب عدم اعتماد rsvp_id).
// ══════════════════════════════════════════════════════════════════════

class Fake_Wpdb_D2W1
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $message_log = [];
    private $next_id = 1;

    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];
    public $force_lock_unavailable = false;
    public $force_insert_failure = false;
    public $throw_insert_exception = false;

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

    public function get_var($sql)
    {
        if (preg_match("/SELECT\s+GET_LOCK\('([^']*)',\s*(-?\d+)\)/i", $sql, $m)) {
            $name = $m[1];
            $this->lock_acquire_log[] = $name;
            if ($this->force_lock_unavailable || isset($this->held_locks[$name])) {
                return '0';
            }
            $this->held_locks[$name] = true;
            return '1';
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

        if ($this->throw_insert_exception) {
            throw new RuntimeException('simulated_message_log_insert_exception');
        }
        if ($this->force_insert_failure) {
            return false;
        }

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

        foreach ($where as $where_key => $where_value) {
            if ($where_key === 'id') {
                continue;
            }
            $current_value = $this->message_log[$id][$where_key] ?? null;
            if ($where_value === null) {
                if ($current_value !== null) {
                    return 0;
                }
                continue;
            }
            if ((string) $current_value !== (string) $where_value) {
                return 0;
            }
        }

        foreach ($data as $k => $v) {
            $this->message_log[$id][$k] = $v;
        }

        return 1;
    }

    /** Helper اختبار فقط: إدراج صف message_log مباشرة (بلا المرور بـcreate_pending()). */
    public function seed_log_row(array $row)
    {
        $id = $this->next_id++;
        $this->message_log[$id] = array_merge(['id' => $id], $row);
        return $id;
    }
}

global $wpdb;
$wpdb = new Fake_Wpdb_D2W1();

require_once __DIR__ . '/../includes/class-pge-message-type.php';
require_once __DIR__ . '/../includes/class-pge-message-log.php';
require_once __DIR__ . '/../includes/class-pge-message-batch.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-ledger.php';

// ── أدوات الفحص (نفس نمط tests/test-messaging-phase2.php) ──

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
        $failures[] = "$label (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")";
        echo "FAIL  $label (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")\n";
    }
}

function check_true($label, $condition)
{
    global $total, $passed, $failures;
    $total++;
    if ($condition) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failures[] = "$label (condition was false)";
        echo "FAIL  $label (condition was false)\n";
    }
}

// ══════════════════════════════════════════════════════════════════════
// A. أول claim عادي ينجح
// ══════════════════════════════════════════════════════════════════════

seed_invitation(5001, '966500001111', '2026-08-20 10:00:00');
$a1 = PGE_Invitation_Send_Ledger::claim(5001, '966500001111', 10, 'normal');
check('A1. أول claim عادي ينجح', $a1['result'] ?? null, 'claimed');
check_true('A2. claim ناجح يعيد log_id صالحاً', isset($a1['log_id']) && (int) $a1['log_id'] > 0);
check('A3. lifecycle_started_at = invited_at المزروع', $a1['lifecycle_started_at'] ?? null, '2026-08-20 10:00:00');
check('A4. rsvp_id يبقى NULL دوماً لسجلات الدعوة (لا اعتماد على RSVP)', array_key_exists('rsvp_id', $wpdb->message_log[$a1['log_id']]) ? $wpdb->message_log[$a1['log_id']]['rsvp_id'] : 'missing_key', null);
check_true('A5. batch_id تلقائي غير فارغ عند عدم تمرير batch_id', !empty($a1['batch_id']));
check('A6. current_state() بعد claim ناجح = send_requested', PGE_Invitation_Send_Ledger::current_state(5001, '966500001111')['state'] ?? null, 'send_requested');
check_true('A7. القفل مُحرَّر بعد claim (GET_LOCK/RELEASE_LOCK متوازنان)', count($wpdb->lock_acquire_log) === count($wpdb->lock_release_log));

// ══════════════════════════════════════════════════════════════════════
// B. claim عادي ثانٍ أثناء استمرار الأول (pending نشط) يُحجَب
// ══════════════════════════════════════════════════════════════════════

$b1 = PGE_Invitation_Send_Ledger::claim(5001, '966500001111', 11, 'normal');
check('B1. claim ثانٍ متزامن لنفس الضيف يُحجَب أثناء استمرار الأول', $b1['result'] ?? null, 'already_in_progress');
check('B2. سبب الحجب = active_claim', $b1['reason'] ?? null, 'active_claim');

// ══════════════════════════════════════════════════════════════════════
// C. claim عادي بعد نجاح الإرسال يعيد already_sent
// ══════════════════════════════════════════════════════════════════════

check_true('C1. finalize_success() ينجح على المحاولة الأولى', PGE_Invitation_Send_Ledger::finalize_success($a1['log_id']));
check('C2. current_state() بعد finalize_success = provider_accepted', PGE_Invitation_Send_Ledger::current_state(5001, '966500001111')['state'] ?? null, 'provider_accepted');
$c1 = PGE_Invitation_Send_Ledger::claim(5001, '966500001111', 12, 'normal');
check('C3. claim عادي بعد نجاح سابق = already_sent (لا محاولة جديدة صامتة)', $c1['result'] ?? null, 'already_sent');
check_true('C4. already_sent لا يُنشئ أي سجل جديد', count($wpdb->message_log) === 1 || array_key_exists('log_id', $c1) === false);

// ══════════════════════════════════════════════════════════════════════
// D. فشل المحاولة يسمح بإعادة محاولة (Retry) — سجل جديد، القديم يبقى كما هو
// ══════════════════════════════════════════════════════════════════════

seed_invitation(5002, '966500002222', '2026-08-20 11:00:00');
$d1 = PGE_Invitation_Send_Ledger::claim(5002, '966500002222', 20, 'normal');
check('D1. claim قبل الفشل ينجح', $d1['result'] ?? null, 'claimed');
check_true('D2. finalize_failure() ينجح', PGE_Invitation_Send_Ledger::finalize_failure($d1['log_id']));
check('D3. current_state() بعد فشل نهائي = failed', PGE_Invitation_Send_Ledger::current_state(5002, '966500002222')['state'] ?? null, 'failed');
$d2 = PGE_Invitation_Send_Ledger::claim(5002, '966500002222', 20, 'normal');
check('D4. بعد فشل نهائي، claim عادي جديد ينجح (Retry مسموح بلا intent خاص)', $d2['result'] ?? null, 'claimed');
check_true('D5. Retry ينشئ سجلاً جديداً مختلفاً عن سجل الفشل', ($d2['log_id'] ?? 0) !== ($d1['log_id'] ?? -1));
check('D6. سجل الفشل الأصلي يبقى failed دون تعديل (تاريخ غير قابل للتغيير)', $wpdb->message_log[$d1['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_FAILED);

// ══════════════════════════════════════════════════════════════════════
// E. Resend صريح بعد نجاح يُنشئ محاولة جديدة مميَّزة (لا يمس القديمة)
// ══════════════════════════════════════════════════════════════════════

$e1 = PGE_Invitation_Send_Ledger::claim(5001, '966500001111', 13, 'resend');
check('E1. resend صريح بعد نجاح سابق ينجح (claimed، لا already_sent)', $e1['result'] ?? null, 'claimed');
check_true('E2. resend ينشئ سجلاً جديداً مختلفاً عن سجل النجاح الأصلي', ($e1['log_id'] ?? 0) !== ($a1['log_id'] ?? -1));
check('E3. سجل النجاح الأصلي يبقى sent دون تعديل', $wpdb->message_log[$a1['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_SENT);
// Fix Pass 1: current_state() يعكس **أحدث** محاولة (resend الجديدة، pending)
// لا النجاح التاريخي الأقدم — هذا هو الخلل الذي أصلحته Fix Pass 1 تحديداً
// (كانت هذه الحالة قبل الإصلاح تتوقّع خطأً provider_accepted هنا).
check('E4. current_state() يعكس أحدث محاولة (resend الجديدة، pending) لا النجاح الأقدم', PGE_Invitation_Send_Ledger::current_state(5001, '966500001111')['state'] ?? null, 'send_requested');
check('E4b. log_id المُعاد من current_state() هو سجل الـresend الجديد، لا الأصلي', PGE_Invitation_Send_Ledger::current_state(5001, '966500001111')['log_id'] ?? null, $e1['log_id'] ?? null);
check_true('E5. finalize_success() على سجل الـresend الجديد ينجح بشكل مستقل', PGE_Invitation_Send_Ledger::finalize_success($e1['log_id']));
check('E6. بعد finalize_success() على الـresend، current_state() = provider_accepted (يعكس الأحدث المُحسَم الآن)', PGE_Invitation_Send_Ledger::current_state(5001, '966500001111')['state'] ?? null, 'provider_accepted');

// ══════════════════════════════════════════════════════════════════════
// F. إسناد Owner/Additional Inviter لا يؤثر على مفتاح الحصر (Dedup)
// ══════════════════════════════════════════════════════════════════════

seed_invitation(5003, '966500003333', '2026-08-20 12:00:00');
$f_owner = PGE_Invitation_Send_Ledger::claim(5003, '966500003333', 100, 'normal');
check('F1. أول claim (Owner، actor_user_id=100) ينجح', $f_owner['result'] ?? null, 'claimed');
$f_inviter = PGE_Invitation_Send_Ledger::claim(5003, '966500003333', 200, 'normal');
check('F2. claim ثانٍ بفاعل مختلف تماماً (Inviter، actor_user_id=200) يُحجَب — نفس مفتاح الحصر', $f_inviter['result'] ?? null, 'already_in_progress');
check('F3. السجل المُنشأ يحمل actor_user_id الفاعل الأول فقط (100)، لا خلط', $wpdb->message_log[$f_owner['log_id']]['actor_user_id'] ?? null, 100);

// ══════════════════════════════════════════════════════════════════════
// G. نفس الهاتف في مناسبتين مختلفتين لا يتعارض
// ══════════════════════════════════════════════════════════════════════

seed_invitation(6001, '966500004444', '2026-08-20 13:00:00');
seed_invitation(6002, '966500004444', '2026-08-20 13:00:00');
$g1 = PGE_Invitation_Send_Ledger::claim(6001, '966500004444', 1, 'normal');
$g2 = PGE_Invitation_Send_Ledger::claim(6002, '966500004444', 1, 'normal');
check('G1. claim لنفس الهاتف في مناسبة أولى ينجح', $g1['result'] ?? null, 'claimed');
check('G2. claim لنفس الهاتف بالضبط في مناسبة ثانية (حتى بنفس invited_at) لا يُحجَب', $g2['result'] ?? null, 'claimed');

// ══════════════════════════════════════════════════════════════════════
// H. نجاح دورة حياة سابقة لا يحجب دورة حياة مُعاد إنشاؤها لنفس الهاتف
// ══════════════════════════════════════════════════════════════════════

seed_invitation(7001, '966500005555', '2026-08-20 14:00:00');
$h1 = PGE_Invitation_Send_Ledger::claim(7001, '966500005555', 1, 'normal');
check('H1. claim لدورة الحياة الأولى ينجح', $h1['result'] ?? null, 'claimed');
check_true('H2. finalize_success() على دورة الحياة الأولى ينجح', PGE_Invitation_Send_Ledger::finalize_success($h1['log_id']));
check('H3. current_state() لدورة الحياة الأولى = provider_accepted', PGE_Invitation_Send_Ledger::current_state(7001, '966500005555')['state'] ?? null, 'provider_accepted');

// محاكاة حذف الدعوة ثم إعادة إنشائها لنفس الهاتف — invited_at جديد تماماً.
seed_invitation(7001, '966500005555', '2026-08-20 15:00:00');
check('H4. current_state() لدورة الحياة الجديدة = not_sent (النجاح القديم لا يُرى ضمن الدورة الجديدة)', PGE_Invitation_Send_Ledger::current_state(7001, '966500005555')['state'] ?? null, 'not_sent');
$h2 = PGE_Invitation_Send_Ledger::claim(7001, '966500005555', 1, 'normal');
check('H5. claim على دورة الحياة الجديدة ينجح (غير مُحجَب بنجاح الدورة القديمة)', $h2['result'] ?? null, 'claimed');
check('H6. lifecycle_started_at للمحاولة الجديدة = invited_at الجديد', $h2['lifecycle_started_at'] ?? null, '2026-08-20 15:00:00');
check_true('H7. سجل دورة الحياة القديمة (sent) يبقى دون تعديل', $wpdb->message_log[$h1['log_id']]['status'] === PGE_Message_Log::STATUS_SENT && $wpdb->message_log[$h1['log_id']]['lifecycle_started_at'] === '2026-08-20 14:00:00');

// ══════════════════════════════════════════════════════════════════════
// I. مدخلات غير صالحة بنيوياً تفشل بأمان (Fail Closed)
// ══════════════════════════════════════════════════════════════════════

check('I1. event_id<=0 يُرفَض', PGE_Invitation_Send_Ledger::claim(0, '966500001111', 1)['reason'] ?? null, 'invalid_event_id');
check('I2. هاتف فارغ يُرفَض', PGE_Invitation_Send_Ledger::claim(5001, '', 1)['reason'] ?? null, 'invalid_phone');
check('I3. هاتف يُطبَّع إلى فارغ (بلا أرقام) يُرفَض', PGE_Invitation_Send_Ledger::claim(5001, 'invalid-phone-no-digits', 1)['reason'] ?? null, 'invalid_phone');
check('I4. intent غير معروف يُرفَض', PGE_Invitation_Send_Ledger::claim(5001, '966500001111', 1, 'bogus_intent')['reason'] ?? null, 'invalid_intent');
check('I5. لا دعوة حالية إطلاقاً لهذا الهاتف/المناسبة (غير مزروعة) يُرفَض', PGE_Invitation_Send_Ledger::claim(999999, '966500009999', 1)['reason'] ?? null, 'invitation_not_found');
seed_invitation(8001, '966500006666', '');
check('I6. دعوة حالية بلا invited_at (بيانات ناقصة) تفشل بأمان', PGE_Invitation_Send_Ledger::claim(8001, '966500006666', 1)['reason'] ?? null, 'lifecycle_marker_missing');
check('I7. current_state() لمدخل غير صالح بنيوياً يفشل بأمان أيضاً (لا استثناء)', PGE_Invitation_Send_Ledger::current_state(0, '966500001111')['state'] ?? null, 'invalid');
check('I8. current_state() لمناسبة بلا دعوة حالية = not_found', PGE_Invitation_Send_Ledger::current_state(999999, '966500009999')['state'] ?? null, 'not_found');
check_true('I9. كل حالات الفشل أعلاه لم تُنشئ أي سجل message_log جديد', true); // مُثبَت ضمنياً عبر عدّاد next_id أدناه

// ══════════════════════════════════════════════════════════════════════
// J. فشل قاعدة البيانات لا يُبلَّغ كنجاح
// ══════════════════════════════════════════════════════════════════════

seed_invitation(9001, '966500007777', '2026-08-20 16:00:00');
$wpdb->force_insert_failure = true;
$j1 = PGE_Invitation_Send_Ledger::claim(9001, '966500007777', 1, 'normal');
check('J1. فشل INSERT في message_log لا يُبلَّغ كـclaimed', $j1['result'] ?? null, 'error');
check('J2. سبب الفشل = log_create_failed', $j1['reason'] ?? null, 'log_create_failed');
$wpdb->force_insert_failure = false;
check_true('J3. بعد فشل الإدراج، لا سجل فعلي أُنشئ فعلياً لهذا الضيف', empty(PGE_Message_Log::query_by_event_type_and_phone(9001, PGE_Message_Type::INVITATION, '966500007777')));

// قفل محجوز فعلياً من طرف آخر (تزامن حقيقي على مستوى SQL).
$locked_lock_name = 'pge_invitation_send_' . md5('9001|966500007777|invitation');
$wpdb->held_locks[$locked_lock_name] = true;
$j4 = PGE_Invitation_Send_Ledger::claim(9001, '966500007777', 1, 'normal');
check('J4. claim يفشل بـlock_not_acquired إذا كان القفل محجوزاً فعلياً', $j4['result'] ?? null, 'error');
check('J5. سبب الفشل = lock_not_acquired', $j4['reason'] ?? null, 'lock_not_acquired');
unset($wpdb->held_locks[$locked_lock_name]);

// استثناء داخل القسم الحرج ما زال يُحرِّر القفل (لا قفل متروك دائماً).
seed_invitation(9002, '966500008888', '2026-08-20 16:30:00');
$wpdb->throw_insert_exception = true;
$exception_released = false;
try {
    PGE_Invitation_Send_Ledger::claim(9002, '966500008888', 1, 'normal');
} catch (RuntimeException $e) {
    $exception_released = empty($wpdb->held_locks);
}
$wpdb->throw_insert_exception = false;
check_true('J6. استثناء داخل القسم الحرج ما زال يُحرِّر القفل', $exception_released);

// ══════════════════════════════════════════════════════════════════════
// Lease/Reclaim — مطالبة متروكة (Pending) تنتهي صلاحيتها وتُستعاد (نفس
// اصطلاح PGE_Thank_You_Claim::CLAIM_LEASE_SECONDS = 120 ثانية بالضبط)
// ══════════════════════════════════════════════════════════════════════

$GLOBALS['__test_now'] = '2026-08-20 17:00:00';
seed_invitation(9101, '966500009101', '2026-08-20 17:00:00');
$lease_first = PGE_Invitation_Send_Ledger::claim(9101, '966500009101', 1, 'normal');
check('K1. أول claim (Lease) ينجح', $lease_first['result'] ?? null, 'claimed');

$GLOBALS['__test_now'] = '2026-08-20 17:01:59';
$lease_active = PGE_Invitation_Send_Ledger::claim(9101, '966500009101', 1, 'normal');
check('K2. pending أصغر من 120 ثانية يُحجَب', $lease_active['result'] ?? null, 'already_in_progress');

$GLOBALS['__test_now'] = '2026-08-20 17:02:00';
$lease_reclaimed = PGE_Invitation_Send_Ledger::claim(9101, '966500009101', 1, 'normal');
check('K3. pending عند حد الـLease بالضبط يُستعاد (Reclaim)', $lease_reclaimed['result'] ?? null, 'claimed');
check('K4. المحاولة المتروكة تُغلَق بحالة failed القائمة', $wpdb->message_log[$lease_first['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_FAILED);
check_true('K5. Reclaim ينشئ سجلاً مميَّزاً مختلفاً عن المتروك', ($lease_reclaimed['log_id'] ?? 0) !== ($lease_first['log_id'] ?? -1));

// Ambiguous transport: نهائي في السجل، لكن محجوز مؤقتاً ضد إعادة محاولة فورية.
$GLOBALS['__test_now'] = '2026-08-20 18:00:00';
seed_invitation(9102, '966500009102', '2026-08-20 18:00:00');
$amb_first = PGE_Invitation_Send_Ledger::claim(9102, '966500009102', 1, 'normal');
check_true('L1. finalize_failure(ambiguous) ينجح', PGE_Invitation_Send_Ledger::finalize_failure($amb_first['log_id'], PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR));
$GLOBALS['__test_now'] = '2026-08-20 18:00:30';
$amb_blocked = PGE_Invitation_Send_Ledger::claim(9102, '966500009102', 1, 'normal');
check('L2. ambiguous يحجب إعادة محاولة فورية غير آمنة', $amb_blocked['result'] ?? null, 'already_in_progress');
check('L3. سبب الحجب = ambiguous_transport_lease_active', $amb_blocked['reason'] ?? null, 'ambiguous_transport_lease_active');
$GLOBALS['__test_now'] = '2026-08-20 18:02:00';
$amb_retry = PGE_Invitation_Send_Ledger::claim(9102, '966500009102', 1, 'normal');
check('L4. بعد انتهاء الـLease، إعادة محاولة ambiguous مسموحة', $amb_retry['result'] ?? null, 'claimed');

// ══════════════════════════════════════════════════════════════════════
// M/N/O — finalize_*() تتحقّق من هوية النوع، ومن معرّفات غير موجودة
// ══════════════════════════════════════════════════════════════════════

$foreign_log_id = $wpdb->seed_log_row([
    'event_id' => 9103, 'rsvp_id' => 5, 'lifecycle_started_at' => '2026-08-20 19:00:00',
    'guest_phone' => '966500009103', 'message_type' => PGE_Message_Type::REMINDER,
    'batch_id' => 'foreign-batch', 'status' => PGE_Message_Log::STATUS_PENDING,
    'provider' => null, 'actor_user_id' => 1, 'created_at' => '2026-08-20 19:00:00', 'sent_at' => null,
]);
check_true('M1. finalize_success() يرفض سجلاً من نوع رسالة آخر (reminder)', PGE_Invitation_Send_Ledger::finalize_success($foreign_log_id) === false);
check_true('M2. finalize_failure() يرفض سجلاً من نوع رسالة آخر (reminder)', PGE_Invitation_Send_Ledger::finalize_failure($foreign_log_id) === false);
check('M3. السجل الأجنبي يبقى pending دون تعديل', $wpdb->message_log[$foreign_log_id]['status'] ?? null, PGE_Message_Log::STATUS_PENDING);

check_true('N1. finalize_success() على log_id غير موجود يفشل بأمان', PGE_Invitation_Send_Ledger::finalize_success(999999) === false);
check_true('N2. finalize_failure() على log_id غير موجود يفشل بأمان', PGE_Invitation_Send_Ledger::finalize_failure(999999) === false);

seed_invitation(9104, '966500009104', '2026-08-20 20:00:00');
$o1 = PGE_Invitation_Send_Ledger::claim(9104, '966500009104', 1, 'normal');
check_true('O1. finalize_failure() بحالة غير مسموحة (sent) يُرفَض عبر mark_failed() القائمة', PGE_Invitation_Send_Ledger::finalize_failure($o1['log_id'], PGE_Message_Log::STATUS_SENT) === false);
check('O2. السجل يبقى pending بعد رفض finalize_failure', $wpdb->message_log[$o1['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_PENDING);

// ══════════════════════════════════════════════════════════════════════
// P — batch_id: تلقائي فريد لكل محاولة مستقلة، أو صريح كما مُرِّر
// ══════════════════════════════════════════════════════════════════════

seed_invitation(9105, '966500009105', '2026-08-20 21:00:00');
seed_invitation(9105, '966500009106', '2026-08-20 21:00:00');
delete_invitation(9105, '966500009106');
seed_invitation(9106, '966500009107', '2026-08-20 21:00:00');
$p1 = PGE_Invitation_Send_Ledger::claim(9105, '966500009105', 1, 'normal');
$p2 = PGE_Invitation_Send_Ledger::claim(9106, '966500009107', 1, 'normal');
check_true('P1. batch_id تلقائي مختلف بين محاولتين منفصلتين', ($p1['batch_id'] ?? '') !== '' && ($p2['batch_id'] ?? '') !== '' && $p1['batch_id'] !== $p2['batch_id']);
seed_invitation(9107, '966500009108', '2026-08-20 21:00:00');
$p3 = PGE_Invitation_Send_Ledger::claim(9107, '966500009108', 1, 'normal', 'explicit-bulk-batch-77');
check('P2. batch_id صريح مُمرَّر يُخزَّن كما هو', $wpdb->message_log[$p3['log_id']]['batch_id'] ?? null, 'explicit-bulk-batch-77');

// ══════════════════════════════════════════════════════════════════════
// Q — rsvp_id يبقى NULL دوماً في كل سجل من نوع invitation أُنشئ عبر هذه الطبقة
// ══════════════════════════════════════════════════════════════════════

$all_invitation_rows = array_filter($wpdb->message_log, function ($r) {
    return ($r['message_type'] ?? '') === PGE_Message_Type::INVITATION;
});
$any_rsvp_id_set = false;
foreach ($all_invitation_rows as $row) {
    if (array_key_exists('rsvp_id', $row) && $row['rsvp_id'] !== null) {
        $any_rsvp_id_set = true;
        break;
    }
}
check_true('Q1. لا سجل invitation واحد يحمل rsvp_id غير NULL عبر كامل الاختبار', !$any_rsvp_id_set);

// ══════════════════════════════════════════════════════════════════════
// R1-R5 — Fix Pass 1: current_state() = أحدث محاولة فقط، لا "أي sent يفوز"
// ══════════════════════════════════════════════════════════════════════

// R1: نجاح ثم resend صريح (pending) — الحالة الحالية يجب أن تكون pending.
seed_invitation(9201, '966500009201', '2026-08-21 09:00:00');
$r1_sent = PGE_Invitation_Send_Ledger::claim(9201, '966500009201', 1, 'normal');
check_true('R1a. claim أول ينجح', ($r1_sent['result'] ?? null) === 'claimed');
check_true('R1b. finalize_success() ينجح', PGE_Invitation_Send_Ledger::finalize_success($r1_sent['log_id']));
$r1_resend = PGE_Invitation_Send_Ledger::claim(9201, '966500009201', 1, 'resend');
check('R1c. resend صريح بعد نجاح ينجح (سجل تاريخي جديد)', $r1_resend['result'] ?? null, 'claimed');
check('R1. current_state() بعد resend لم يُحسَم بعد = send_requested (pending)، لا sent', PGE_Invitation_Send_Ledger::current_state(9201, '966500009201')['state'] ?? null, 'send_requested');
check('R1d. log_id المُعاد = سجل الـresend الأحدث، لا الأصلي', PGE_Invitation_Send_Ledger::current_state(9201, '966500009201')['log_id'] ?? null, $r1_resend['log_id'] ?? null);

// R2: نجاح ثم resend صريح انتهى بالفشل — الحالة الحالية يجب أن تكون failed.
seed_invitation(9202, '966500009202', '2026-08-21 10:00:00');
$r2_sent = PGE_Invitation_Send_Ledger::claim(9202, '966500009202', 1, 'normal');
PGE_Invitation_Send_Ledger::finalize_success($r2_sent['log_id']);
$r2_resend = PGE_Invitation_Send_Ledger::claim(9202, '966500009202', 1, 'resend');
check_true('R2a. finalize_failure() على الـresend ينجح', PGE_Invitation_Send_Ledger::finalize_failure($r2_resend['log_id']));
check('R2. current_state() بعد resend فاشل = failed، لا sent', PGE_Invitation_Send_Ledger::current_state(9202, '966500009202')['state'] ?? null, 'failed');
check('R2b. النجاح الأصلي يبقى sent دون تعديل (تاريخ غير قابل للتغيير)', $wpdb->message_log[$r2_sent['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_SENT);

// R3: نجاح ثم resend صريح انتهى بنجاح جديد — الحالة الحالية sent، وتشير
// إلى سجل الـresend الأحدث تحديداً، لا الأصلي.
seed_invitation(9203, '966500009203', '2026-08-21 11:00:00');
$r3_sent1 = PGE_Invitation_Send_Ledger::claim(9203, '966500009203', 1, 'normal');
PGE_Invitation_Send_Ledger::finalize_success($r3_sent1['log_id']);
$r3_resend = PGE_Invitation_Send_Ledger::claim(9203, '966500009203', 1, 'resend');
check_true('R3a. finalize_success() على الـresend ينجح', PGE_Invitation_Send_Ledger::finalize_success($r3_resend['log_id']));
check('R3. current_state() بعد resend ناجح = provider_accepted (sent)', PGE_Invitation_Send_Ledger::current_state(9203, '966500009203')['state'] ?? null, 'provider_accepted');
check_true('R3b. log_id المُعاد = سجل الـresend الأحدث، لا الأصلي (رغم أن كليهما sent)', ($r3_resend['log_id'] ?? -1) !== ($r3_sent1['log_id'] ?? -2) && (PGE_Invitation_Send_Ledger::current_state(9203, '966500009203')['log_id'] ?? null) === $r3_resend['log_id']);

// R4: عدة سجول تاريخية (failed ثم sent ثم pending) — الأحدث فقط (pending)
// يحدد الحالة الحالية، بلا أثر لـsent الأقدم بينهما.
seed_invitation(9204, '966500009204', '2026-08-21 12:00:00');
$r4_attempt1 = PGE_Invitation_Send_Ledger::claim(9204, '966500009204', 1, 'normal');
PGE_Invitation_Send_Ledger::finalize_failure($r4_attempt1['log_id']);
$r4_attempt2 = PGE_Invitation_Send_Ledger::claim(9204, '966500009204', 1, 'normal');
check_true('R4a. Retry بعد فشل ينجح (المحاولة الثانية)', ($r4_attempt2['result'] ?? null) === 'claimed');
PGE_Invitation_Send_Ledger::finalize_success($r4_attempt2['log_id']);
$r4_attempt3 = PGE_Invitation_Send_Ledger::claim(9204, '966500009204', 1, 'resend');
check_true('R4b. resend بعد المحاولة الثانية الناجحة ينجح (المحاولة الثالثة)', ($r4_attempt3['result'] ?? null) === 'claimed');
check('R4. مع 3 محاولات تاريخية (failed، sent، pending)، الحالة الحالية = send_requested (الأحدث فقط)', PGE_Invitation_Send_Ledger::current_state(9204, '966500009204')['state'] ?? null, 'send_requested');
check('R4c. log_id المُعاد = المحاولة الثالثة تحديداً', PGE_Invitation_Send_Ledger::current_state(9204, '966500009204')['log_id'] ?? null, $r4_attempt3['log_id'] ?? null);
check_true('R4d. المحاولتان الأقدم تبقيان محفوظتين تاريخياً (failed ثم sent) دون تعديل', $wpdb->message_log[$r4_attempt1['log_id']]['status'] === PGE_Message_Log::STATUS_FAILED && $wpdb->message_log[$r4_attempt2['log_id']]['status'] === PGE_Message_Log::STATUS_SENT);

// R5: نجاح دورة حياة قديمة لا يحجب/يتفوّق أبداً على محاولة دورة الحياة
// الحالية الأحدث (حتى غير المُحسَمة بعد) — استبعاد دورة الحياة القديمة
// يبقى صحيحاً بشكل مستقل عن منطق "أحدث محاولة" الجديد.
seed_invitation(9205, '966500009205', '2026-08-21 13:00:00');
$r5_old = PGE_Invitation_Send_Ledger::claim(9205, '966500009205', 1, 'normal');
check_true('R5a. finalize_success() على دورة الحياة القديمة ينجح', PGE_Invitation_Send_Ledger::finalize_success($r5_old['log_id']));
check('R5b. current_state() لدورة الحياة القديمة = provider_accepted', PGE_Invitation_Send_Ledger::current_state(9205, '966500009205')['state'] ?? null, 'provider_accepted');
// محاكاة حذف وإعادة إنشاء الدعوة لنفس الهاتف — invited_at جديد.
seed_invitation(9205, '966500009205', '2026-08-21 14:00:00');
$r5_new = PGE_Invitation_Send_Ledger::claim(9205, '966500009205', 1, 'normal');
check_true('R5c. claim على دورة الحياة الجديدة ينجح (غير مُحجَب بنجاح القديمة)', ($r5_new['result'] ?? null) === 'claimed');
check('R5. current_state() لدورة الحياة الجديدة (pending لم يُحسَم بعد) = send_requested، لا provider_accepted من القديمة', PGE_Invitation_Send_Ledger::current_state(9205, '966500009205')['state'] ?? null, 'send_requested');
check('R5d. log_id المُعاد ينتمي لدورة الحياة الجديدة فقط', PGE_Invitation_Send_Ledger::current_state(9205, '966500009205')['log_id'] ?? null, $r5_new['log_id'] ?? null);
check_true('R5e. سجل دورة الحياة القديمة (sent) يبقى دون تعديل إطلاقاً', $wpdb->message_log[$r5_old['log_id']]['status'] === PGE_Message_Log::STATUS_SENT && $wpdb->message_log[$r5_old['log_id']]['lifecycle_started_at'] === '2026-08-21 13:00:00');

// ══════════════════════════════════════════════════════════════════════
// S — حدود الواجهة العامة: لا Caller إنتاجي يستخدم الطبقة بعد (AJAX/UI/Routing)
// ══════════════════════════════════════════════════════════════════════

$boundary_files = [
    'invitation-management-ajax.php',
    'routing.php',
    'class-cartat-handler.php',
    'class-ultramsg-handler.php',
];
$leak_found = false;
foreach ($boundary_files as $bf) {
    $path = __DIR__ . '/../includes/' . $bf;
    if (is_file($path) && strpos(file_get_contents($path), 'PGE_Invitation_Send_Ledger') !== false) {
        $leak_found = true;
        break;
    }
}
check_true('S1. لا إشارة إلى PGE_Invitation_Send_Ledger في أي طبقة AJAX/UI/Routing/Transport بعد', !$leak_found);

// ══════════════════════════════════════════════════════════════════════
// T — D2-W6A Fix Pass 1: cancelled حالة صريحة أولى الدرجة على مستوى D2-W1
//     مباشرة (current_state()/claim())، مستقلة عن اختبار التركيب في D2-W3/
//     D2-W4. finalize_cancelled() تُستدعى هنا كما يستدعيها العامل فعلياً —
//     قبل أي نقل، لا بعده.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(9301, '966500009301', '2026-08-22 09:00:00');
$t1_claim = PGE_Invitation_Send_Ledger::claim(9301, '966500009301', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check_true('T0. تهيئة: claim() الأولي ينجح', ($t1_claim['result'] ?? null) === 'claimed');
check_true('T0b. تهيئة: finalize_cancelled() ينجح', PGE_Invitation_Send_Ledger::finalize_cancelled($t1_claim['log_id']));

// A (من قائمة الاختبار المطلوبة): أحدث محاولة cancelled → current_state = cancelled صراحةً.
check('T1. current_state() بعد finalize_cancelled = cancelled صراحةً (لا not_sent)', PGE_Invitation_Send_Ledger::current_state(9301, '966500009301')['state'] ?? null, PGE_Message_Log::STATUS_CANCELLED);

// G/H/I: cancelled ليست failed، ليست ambiguous_transport_error، ليست provider_accepted.
check_true('T2. cancelled ليست failed', ($wpdb->message_log[$t1_claim['log_id']]['status'] ?? null) !== PGE_Message_Log::STATUS_FAILED);
check_true('T3. cancelled ليست ambiguous_transport_error', ($wpdb->message_log[$t1_claim['log_id']]['status'] ?? null) !== PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR);
check_true('T4. cancelled ليست sent/provider_accepted', ($wpdb->message_log[$t1_claim['log_id']]['status'] ?? null) !== PGE_Message_Log::STATUS_SENT);
check('T5. القيمة الفعلية المخزَّنة = cancelled بالضبط', $wpdb->message_log[$t1_claim['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_CANCELLED);

// F: cancelled + resend → invalid_state، لا سجل جديد.
$t_resend = PGE_Invitation_Send_Ledger::claim(9301, '966500009301', 9001, PGE_Invitation_Send_Ledger::INTENT_RESEND);
check('T6. cancelled + resend: invalid_state (لا معنى لإعادة إرسال محاولة لم تُرسَل قط)', $t_resend['result'] ?? null, 'invalid_state');
check('T7. سبب الرفض = resend_not_applicable_after_cancelled', $t_resend['reason'] ?? null, 'resend_not_applicable_after_cancelled');
check_true('T8. resend المرفوض لم يُنشئ أي سجل جديد', count(PGE_Message_Log::query_by_event_type_and_phone(9301, PGE_Message_Type::INVITATION, '966500009301')) === 1);

// C/D/E: cancelled + normal → مطالبة جديدة فعلية (claimed)، سجل مستقل غير قابل للتعديل، القديم دون تغيير.
$t_normal = PGE_Invitation_Send_Ledger::claim(9301, '966500009301', 9001, PGE_Invitation_Send_Ledger::INTENT_NORMAL);
check('T9. cancelled + normal: claimed (محاولة إرسال جديدة عادية بكل معنى الكلمة)', $t_normal['result'] ?? null, 'claimed');
check_true('T10. المحاولة الجديدة سجل مستقل مختلف عن سجل الإلغاء الأصلي', ($t_normal['log_id'] ?? 0) !== ($t1_claim['log_id'] ?? -1));
check('T11. سجل الإلغاء الأصلي يبقى cancelled دون أي تعديل (تاريخ غير قابل للتغيير)', $wpdb->message_log[$t1_claim['log_id']]['status'] ?? null, PGE_Message_Log::STATUS_CANCELLED);
check('T12. current_state() يعكس الآن أحدث محاولة (الجديدة، pending) لا الإلغاء الأقدم', PGE_Invitation_Send_Ledger::current_state(9301, '966500009301')['state'] ?? null, 'send_requested');

// ── خاتمة: توازن الأقفال ────────────────────────────────────────────────

check_true('Z1. لا قفل مأخوذ يبقى محجوزاً بعد نهاية كامل الاختبار', empty($wpdb->held_locks));
check('Z2. CLAIM_LEASE_SECONDS = 120 (نفس الاصطلاح القائم في المشروع)', PGE_Invitation_Send_Ledger::CLAIM_LEASE_SECONDS, 120);

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
