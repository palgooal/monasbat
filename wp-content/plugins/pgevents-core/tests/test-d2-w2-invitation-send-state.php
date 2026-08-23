<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ"D2-W2 — Invitation Send State /
 * Sendability Read Contract": PGE_Invitation_Send_State فوق D2-W1
 * (PGE_Invitation_Send_Ledger) القائم فعلاً — قراءة فقط، بلا أي أثر جانبي.
 *
 * يحمّل الملفات الحقيقية التالية دون أي تعديل عليها:
 *   - includes/class-pge-message-type.php
 *   - includes/class-pge-message-log.php
 *   - includes/class-pge-message-batch.php
 *   - includes/class-pge-invitation-send-ledger.php   (D2-W1 + Fix Pass 1، غير مُعدَّل هنا)
 *   - includes/class-pge-invitation-send-state.php    (D2-W2 الجديد)
 *
 * PGE_Invitation_Repository يُحاكَى محلياً هنا بالكامل (نفس نمط
 * tests/test-d2-w1-invitation-send-ledger.php حرفياً) — لا تحميل لأي ملف
 * إنتاجي حقيقي لتلك الطبقة.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. الخروج برمز 0 عند نجاح كل
 * الحالات، أو 1 عند فشل أي حالة.
 *
 * التشغيل: php tests/test-d2-w2-invitation-send-state.php
 */

define('ABSPATH', __DIR__ . '/');

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('current_time')) {
    $GLOBALS['__test_now'] = '2026-08-22 10:00:00';
    function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['__test_now']; }
}

if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) { return preg_replace('/\D+/', '', trim((string) $v)); }
}

// ══════════════════════════════════════════════════════════════════════
// Fake PGE_Invitation_Repository — محاكاة محلية فقط (نفس نمط D2-W1).
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

// ══════════════════════════════════════════════════════════════════════
// Fake $wpdb: pge_message_log فقط (نفس نمط D2-W1 حرفياً).
// ══════════════════════════════════════════════════════════════════════

class Fake_Wpdb_D2W2
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $message_log = [];
    private $next_id = 1;

    public $held_locks = [];

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
            if (isset($this->held_locks[$name])) {
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
            unset($this->held_locks[$m[1]]);
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
}

global $wpdb;
$wpdb = new Fake_Wpdb_D2W2();

require_once __DIR__ . '/../includes/class-pge-message-type.php';
require_once __DIR__ . '/../includes/class-pge-message-log.php';
require_once __DIR__ . '/../includes/class-pge-message-batch.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-ledger.php';
require_once __DIR__ . '/../includes/class-pge-invitation-send-state.php';

// ── أدوات الفحص (نفس نمط tests/test-d2-w1-invitation-send-ledger.php) ──

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

/**
 * إصلاح ثغرة اختبار مطابقة تماماً لما وُثِّق في D2-W1 Fix Pass 1: عامل `??`
 * يُعامِل قيمة `null` الحقيقية المخزَّنة فعلياً تحت مفتاح موجود وكأنها "غير
 * موجودة"، فيُرجِع القيمة الاحتياطية بدلاً من `null` الحقيقية — ما يجعل أي
 * تأكيد "expected NULL" يفشل بنيوياً دوماً حتى عندما يكون التطبيق صحيحاً
 * تماماً. الحل: array_key_exists() صريحة، تماماً كما أُصلح نفس الخطأ في
 * D2-W1. هذه دالة اختبار فقط — لا صلة لها بـclass-pge-invitation-send-state.php.
 */
function field_or_missing_key($array, $key)
{
    return array_key_exists($key, $array) ? $array[$key] : 'missing_key';
}

/**
 * يُجرِّد التعليقات (// و /* والتوثيق /**‎) من مصدر PHP قبل فحص "المصطلحات
 * المحظورة" في القسم L أدناه. ملف class-pge-invitation-send-state.php
 * مطلوب منه صراحةً (حسب مهمة D2-W2) أن يُوثِّق بوضوح لماذا لا يعتمد على
 * Authorization/Quota/GET_LOCK — أي أن النص العربي التوثيقي نفسه سيحوي
 * كلمات مثل "Quota" أو "GET_LOCK" ضمن جملة تشرح غيابها المتعمَّد. الفحص
 * الحرفي البسيط (strpos على النص الكامل) ينتج إيجابيات كاذبة هنا؛ التجريد
 * أدناه يقتصر الفحص على الكود التنفيذي الفعلي فقط، وهو ما تقصده المهمة
 * فعلياً ("no dependency" وليس "no mention in documentation").
 */
function pge_strip_php_comments($source)
{
    $stripped = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $stripped .= $token[1];
        } else {
            $stripped .= $token;
        }
    }
    return $stripped;
}

// ══════════════════════════════════════════════════════════════════════
// A. لا محاولة إطلاقاً → not_sent / إرسال عادي مسموح
// ══════════════════════════════════════════════════════════════════════

seed_invitation(6001, '966500001001', '2026-08-22 09:00:00');
$a = PGE_Invitation_Send_State::resolve(6001, '966500001001');
check('A1. ok = true (دعوة حالية موجودة)', $a['ok'] ?? null, true);
check('A2. state = not_sent', $a['state'] ?? null, 'not_sent');
check('A3. normal_send_allowed = true', $a['normal_send_allowed'] ?? null, true);
check('A4. resend_required = false', $a['resend_required'] ?? null, false);
check('A5. in_progress = false', $a['in_progress'] ?? null, false);
check('A6. latest_attempt = null (لا محاولة بعد)', field_or_missing_key($a, 'latest_attempt'), null);
check('A7. latest_actor_user_id = null', field_or_missing_key($a, 'latest_actor_user_id'), null);

// ══════════════════════════════════════════════════════════════════════
// B. أحدث محاولة pending → send_requested / إرسال عادي محجوب
// ══════════════════════════════════════════════════════════════════════

$b_claim = PGE_Invitation_Send_Ledger::claim(6001, '966500001001', 501, 'normal');
check_true('B0. claim التمهيدي ينجح', ($b_claim['result'] ?? null) === 'claimed');
$b = PGE_Invitation_Send_State::resolve(6001, '966500001001');
check('B1. state = send_requested', $b['state'] ?? null, 'send_requested');
check('B2. normal_send_allowed = false', $b['normal_send_allowed'] ?? null, false);
check('B3. resend_required = false', $b['resend_required'] ?? null, false);
check('B4. in_progress = true', $b['in_progress'] ?? null, true);
check_true('B5. latest_attempt غير فارغ ويحمل log_id الصحيح', ($b['latest_attempt']['log_id'] ?? null) === $b_claim['log_id']);
check('B6. latest_actor_user_id = 501', $b['latest_actor_user_id'] ?? null, 501);
check('B7. latest_failure_status = null (pending ليست فشلاً)', field_or_missing_key($b, 'latest_failure_status'), null);
check('B8. latest_sent_at = null (لم يُرسَل بعد)', field_or_missing_key($b, 'latest_sent_at'), null);

// ══════════════════════════════════════════════════════════════════════
// C. أحدث محاولة sent → provider_accepted / Resend مطلوب
// ══════════════════════════════════════════════════════════════════════

check_true('C0. finalize_success() ينجح', PGE_Invitation_Send_Ledger::finalize_success($b_claim['log_id']));
$c = PGE_Invitation_Send_State::resolve(6001, '966500001001');
check('C1. state = provider_accepted', $c['state'] ?? null, 'provider_accepted');
check('C2. normal_send_allowed = false', $c['normal_send_allowed'] ?? null, false);
check('C3. resend_required = true', $c['resend_required'] ?? null, true);
check('C4. in_progress = false', $c['in_progress'] ?? null, false);
check_true('C5. latest_sent_at غير فارغ بعد finalize_success', !empty($c['latest_sent_at']));
check('C6. latest_failure_status = null (نجاح، لا فشل)', field_or_missing_key($c, 'latest_failure_status'), null);

// ══════════════════════════════════════════════════════════════════════
// D. أحدث محاولة failed → failed / Retry مسموح (لا Resend صريح لازم)
// ══════════════════════════════════════════════════════════════════════

seed_invitation(6002, '966500001002', '2026-08-22 09:00:00');
$d_claim = PGE_Invitation_Send_Ledger::claim(6002, '966500001002', 502, 'normal');
check_true('D0. finalize_failure() ينجح', PGE_Invitation_Send_Ledger::finalize_failure($d_claim['log_id']));
$d = PGE_Invitation_Send_State::resolve(6002, '966500001002');
check('D1. state = failed', $d['state'] ?? null, 'failed');
check('D2. normal_send_allowed = true (Retry تقني عادي)', $d['normal_send_allowed'] ?? null, true);
check('D3. resend_required = false', $d['resend_required'] ?? null, false);
check('D4. in_progress = false', $d['in_progress'] ?? null, false);
check('D5. latest_failure_status = failed', $d['latest_failure_status'] ?? null, PGE_Message_Log::STATUS_FAILED);
check('D6. latest_actor_user_id = 502', $d['latest_actor_user_id'] ?? null, 502);

// ══════════════════════════════════════════════════════════════════════
// E. ambiguous_transport_error → سلوك محافظ (القرار الأهم في D2-W2)
// ══════════════════════════════════════════════════════════════════════

seed_invitation(6003, '966500001003', '2026-08-22 09:00:00');
$e_claim = PGE_Invitation_Send_Ledger::claim(6003, '966500001003', 503, 'normal');
check_true('E0. finalize_failure(ambiguous) ينجح', PGE_Invitation_Send_Ledger::finalize_failure($e_claim['log_id'], PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR));
$e = PGE_Invitation_Send_State::resolve(6003, '966500001003');
check('E1. state = ambiguous_transport_error (حالة مميَّزة، لا failed)', $e['state'] ?? null, PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR);
check('E2. normal_send_allowed = false (لا Retry تلقائي — راجع التوثيق)', $e['normal_send_allowed'] ?? null, false);
check('E3. resend_required = true (قرار صريح/واعٍ مطلوب، كـResend)', $e['resend_required'] ?? null, true);
check('E4. in_progress = false (نهائية في عقد Message Log، ليست "قيد التنفيذ")', $e['in_progress'] ?? null, false);
check('E5. latest_failure_status = ambiguous_transport_error', $e['latest_failure_status'] ?? null, PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR);

// ══════════════════════════════════════════════════════════════════════
// F. نجاح سابق ثم فشل أحدث → الحالة = failed، لا sent (يعتمد على Fix Pass 1)
// ══════════════════════════════════════════════════════════════════════

seed_invitation(6004, '966500001004', '2026-08-22 09:00:00');
$f1 = PGE_Invitation_Send_Ledger::claim(6004, '966500001004', 504, 'normal');
PGE_Invitation_Send_Ledger::finalize_success($f1['log_id']);
$f2 = PGE_Invitation_Send_Ledger::claim(6004, '966500001004', 505, 'resend');
PGE_Invitation_Send_Ledger::finalize_failure($f2['log_id']);
$f = PGE_Invitation_Send_State::resolve(6004, '966500001004');
check('F1. state = failed (أحدث محاولة فقط، رغم نجاح أقدم)', $f['state'] ?? null, 'failed');
check('F2. latest_actor_user_id = فاعل المحاولة الأحدث (505)، لا الأقدم (504)', $f['latest_actor_user_id'] ?? null, 505);
check_true('F3. latest_attempt.log_id = محاولة الفشل الأحدث', ($f['latest_attempt']['log_id'] ?? null) === $f2['log_id']);

// ══════════════════════════════════════════════════════════════════════
// G. نجاح سابق ثم Resend صريح لم يُحسَم بعد → الحالة = send_requested
// ══════════════════════════════════════════════════════════════════════

seed_invitation(6005, '966500001005', '2026-08-22 09:00:00');
$g1 = PGE_Invitation_Send_Ledger::claim(6005, '966500001005', 506, 'normal');
PGE_Invitation_Send_Ledger::finalize_success($g1['log_id']);
$g2 = PGE_Invitation_Send_Ledger::claim(6005, '966500001005', 507, 'resend');
$g = PGE_Invitation_Send_State::resolve(6005, '966500001005');
check('G1. state = send_requested (لا provider_accepted من النجاح الأقدم)', $g['state'] ?? null, 'send_requested');
check('G2. in_progress = true', $g['in_progress'] ?? null, true);
check_true('G3. latest_attempt.log_id = محاولة الـresend الجديدة', ($g['latest_attempt']['log_id'] ?? null) === $g2['log_id']);

// ══════════════════════════════════════════════════════════════════════
// H. سجلات دورة حياة قديمة مُستبعَدة تماماً
// ══════════════════════════════════════════════════════════════════════

seed_invitation(6006, '966500001006', '2026-08-22 09:00:00');
$h1 = PGE_Invitation_Send_Ledger::claim(6006, '966500001006', 508, 'normal');
PGE_Invitation_Send_Ledger::finalize_success($h1['log_id']);
$h_old = PGE_Invitation_Send_State::resolve(6006, '966500001006');
check('H1. قبل إعادة الإنشاء: state = provider_accepted', $h_old['state'] ?? null, 'provider_accepted');
// محاكاة حذف الدعوة وإعادة إنشائها لنفس الهاتف — invited_at جديد.
seed_invitation(6006, '966500001006', '2026-08-22 10:00:00');
$h_new = PGE_Invitation_Send_State::resolve(6006, '966500001006');
check('H2. بعد إعادة الإنشاء: state = not_sent (النجاح القديم مُستبعَد تماماً)', $h_new['state'] ?? null, 'not_sent');
check('H3. latest_attempt = null لدورة الحياة الجديدة', field_or_missing_key($h_new, 'latest_attempt'), null);
check('H4. normal_send_allowed = true لدورة الحياة الجديدة', $h_new['normal_send_allowed'] ?? null, true);

// ══════════════════════════════════════════════════════════════════════
// I. الإسناد (Attribution) يأتي من أحدث محاولة فقط
// ══════════════════════════════════════════════════════════════════════

seed_invitation(6007, '966500001007', '2026-08-22 09:00:00');
$i1 = PGE_Invitation_Send_Ledger::claim(6007, '966500001007', 601, 'normal');
PGE_Invitation_Send_Ledger::finalize_failure($i1['log_id']);
$i2 = PGE_Invitation_Send_Ledger::claim(6007, '966500001007', 602, 'normal');
$i = PGE_Invitation_Send_State::resolve(6007, '966500001007');
check('I1. latest_actor_user_id = فاعل المحاولة الثانية (602)', $i['latest_actor_user_id'] ?? null, 602);
check_true('I2. latest_attempt.actor_user_id يطابق latest_actor_user_id تماماً', ($i['latest_attempt']['actor_user_id'] ?? null) === $i['latest_actor_user_id']);

// ══════════════════════════════════════════════════════════════════════
// J. عقد القراءة بلا أي أثر جانبي
// ══════════════════════════════════════════════════════════════════════

$rows_before = $wpdb->message_log;
$locks_before = $wpdb->held_locks;
for ($rep = 0; $rep < 5; $rep++) {
    PGE_Invitation_Send_State::resolve(6007, '966500001007');
    PGE_Invitation_Send_State::resolve(999999, '966500009999'); // مسار not_found أيضاً
}
check('J1. لا تغيير على أي صف message_log بعد استدعاءات resolve() متكررة', $wpdb->message_log, $rows_before);
check('J2. لا قفل مأخوذ إطلاقاً عبر resolve() (لا GET_LOCK في القراءة)', $wpdb->held_locks, $locks_before);

// ══════════════════════════════════════════════════════════════════════
// K. هوية غير صالحة بنيوياً تفشل بأمان
// ══════════════════════════════════════════════════════════════════════

$k1 = PGE_Invitation_Send_State::resolve(0, '966500001007');
check('K1. event_id<=0: ok=false', $k1['ok'] ?? null, false);
check('K2. event_id<=0: state=invalid', $k1['state'] ?? null, 'invalid');
check('K3. event_id<=0: reason=invalid_event_id', $k1['reason'] ?? null, 'invalid_event_id');
check('K4. event_id<=0: normal_send_allowed=false (Fail-Closed)', $k1['normal_send_allowed'] ?? null, false);

$k2 = PGE_Invitation_Send_State::resolve(6001, '');
check('K5. هاتف فارغ: ok=false', $k2['ok'] ?? null, false);
check('K6. هاتف فارغ: reason=invalid_phone', $k2['reason'] ?? null, 'invalid_phone');

$k3 = PGE_Invitation_Send_State::resolve(777777, '966500009000');
check('K7. لا دعوة حالية إطلاقاً: ok=false', $k3['ok'] ?? null, false);
check('K8. لا دعوة حالية إطلاقاً: state=not_found', $k3['state'] ?? null, 'not_found');
check('K9. لا دعوة حالية إطلاقاً: normal_send_allowed=false', $k3['normal_send_allowed'] ?? null, false);
check('K10. لا دعوة حالية إطلاقاً: latest_attempt=null', field_or_missing_key($k3, 'latest_attempt'), null);

// ══════════════════════════════════════════════════════════════════════
// L. لا اعتماد على Authorization/مجموعات/حصة داخل الملف الجديد نفسه
// ══════════════════════════════════════════════════════════════════════

$state_src = file_get_contents(__DIR__ . '/../includes/class-pge-invitation-send-state.php');
// نفحص الكود التنفيذي فقط (بعد تجريد التعليقات) — المهمة تطلب صراحةً توثيق
// غياب Authorization/Quota/GET_LOCK داخل الملف نفسه (رأس الملف يشرح بالتفصيل
// لماذا لا حصة ولا قفل هنا)، فالنص التوثيقي سيحوي هذه الكلمات حتماً ضمن شرح
// غيابها المتعمَّد. الفحص الحرفي الأصلي كان يفحص النص الكامل بما فيه
// التعليقات، فيُنتج إيجابية كاذبة. المقصود فعلياً هو "لا اعتماد برمجي"، لا
// "لا ذِكر في التوثيق".
$state_code_only = pge_strip_php_comments($state_src);
$forbidden_terms = [
    'current_user_can', 'wp_get_current_user', 'get_userdata', 'user_can',
    'PGE_Event_Access', 'Additional_Inviter', 'Quota', 'quota',
    'wp_ajax_', 'add_action', 'add_filter',
];
$forbidden_found = [];
foreach ($forbidden_terms as $term) {
    if (strpos($state_code_only, $term) !== false) {
        $forbidden_found[] = $term;
    }
}
check_true('L1. لا إشارة إلى Authorization/Access/Quota/AJAX داخل class-pge-invitation-send-state.php', empty($forbidden_found));
check_true('L2. لا GET_LOCK/RELEASE_LOCK داخل الملف الجديد (قراءة محضة)', strpos($state_code_only, 'GET_LOCK') === false && strpos($state_code_only, 'RELEASE_LOCK') === false);
check_true('L3. لا wpdb->insert()/wpdb->update() داخل الملف الجديد (لا كتابة)', strpos($state_code_only, '->insert(') === false && strpos($state_code_only, '->update(') === false);

// ══════════════════════════════════════════════════════════════════════
// M. D2-W6A Fix Pass 1 — cancelled → قابلية معاكسة تماماً لـambiguous_
//    transport_error (القسم E أعلاه): لا غموض هنا — Cartat لم يُستدعَ إطلاقاً.
// ══════════════════════════════════════════════════════════════════════

seed_invitation(6008, '966500001008', '2026-08-22 09:00:00');
$m_claim = PGE_Invitation_Send_Ledger::claim(6008, '966500001008', 508, 'normal');
check_true('M0. finalize_cancelled() ينجح (Cartat لم يُستدعَ إطلاقاً هنا)', PGE_Invitation_Send_Ledger::finalize_cancelled($m_claim['log_id']));
$m = PGE_Invitation_Send_State::resolve(6008, '966500001008');
check('M1. state = cancelled (حالة صريحة أولى الدرجة، لا not_sent)', $m['state'] ?? null, PGE_Message_Log::STATUS_CANCELLED);
check('M2. normal_send_allowed = true (لا نقل فعلي حدث، إرسال جديد عادي بكل معنى الكلمة)', $m['normal_send_allowed'] ?? null, true);
check('M3. resend_required = false (لا معنى لإعادة إرسال محاولة لم تُرسَل قط)', $m['resend_required'] ?? null, false);
check('M4. in_progress = false', $m['in_progress'] ?? null, false);
check('M5. latest_failure_status = null (cancelled عمداً خارج TERMINAL_FAILURE_STATUSES)', array_key_exists('latest_failure_status', $m) ? $m['latest_failure_status'] : 'MISSING_KEY', null);
check('M6. latest_attempt.status يعكس cancelled بدقة', $m['latest_attempt']['status'] ?? null, PGE_Message_Log::STATUS_CANCELLED);
check('M7. latest_actor_user_id = 508', $m['latest_actor_user_id'] ?? null, 508);

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
