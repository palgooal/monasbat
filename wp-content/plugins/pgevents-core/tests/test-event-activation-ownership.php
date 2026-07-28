<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس أسلوب بقية ملفات tests/) لـ
 * Event Quota Architecture — Commit 4 (Event Activation Ownership)، بتنفيذ
 * حقيقي فعلي لدالة الإنتاج pge_handle_event_creation() نفسها — لا مرآة
 * منطقية لأي كود إنتاج.
 *
 * يحمّل هذا الملف الملف الحقيقي الوحيد الذي عدَّله Commit 4 دون أي تعديل
 * عليه: includes/event-factory.php — وينفّذ pge_handle_event_creation()
 * الحقيقية مباشرة (نفس الدالة المُعدَّلة).
 *
 * لا نحمّل class-pge-catalog.php ولا feature-resolver.php ولا غيرهما عمداً:
 * pge_get_user_plan_limits_for_events()/pge_plan_feature_enabled_for_events()
 * المُعرَّفتان داخل event-factory.php نفسه تتحققان من class_exists() قبل أي
 * استخدام لـPGE_Packages/PGE_Catalog وتتدهوران بأمان لمسار Legacy المباشر
 * (قراءة User Meta مباشرة) عند غيابهما — هذا يبقي الاختبار مركّزاً حصراً على
 * الملف الذي عدَّله Commit 4 فعلاً، بلا أي ملف غير ذي علاقة (تماشياً مع قاعدة
 * "Do NOT modify unrelated files" في نطاق هذا الـCommit).
 *
 * pge_handle_event_creation() تنتهي دوماً باستدعاء wp_send_json_success()/
 * wp_send_json_error() الحقيقيتين في ووردبريس (اللتان تستدعيان wp_die()
 * داخلياً وتُنهيان الطلب) — لجعل هذا قابلاً للاختبار الفعلي بلا تعديل أي كود
 * إنتاج، نُعرِّف Stub لهاتين الدالتين يرمي Exception قابلاً للالتقاط يحمل
 * الـpayload المُرسَل، بدل الخروج من العملية. هذا اعتراض لنقطتَي الخروج
 * القياسيتين لووردبريس (كما تفعل بقية ملفات tests/ تماماً مع get_user_meta/
 * update_user_meta وغيرها) — وليس إعادة تنفيذ لأي منطق أعمال.
 *
 * السيناريوهات الخمسة المطلوبة صراحةً:
 *   1. مناسبة جديدة → _pge_event_activation_id موجود ويساوي _mon_credit_cycle_id.
 *   2. التحقق من الملكية ينجح (بما في ذلك حالة معرّف فارغ لمستخدم بلا Snapshot).
 *   3. فشل ملكية مُجبَر (حقن قيمة قراءة مغايرة عمداً) → حذف المناسبة.
 *   4. المناسبات القديمة (بلا _pge_event_activation_id) تبقى بلا أي تغيير.
 *   5. انحدار: إنشاء مناسبة عادية ما زال ينجح تماماً كالسابق (كل الحقول
 *      الأخرى، وبوابة الحصة القديمة الموجودة مسبقاً في نفس الدالة لا تزال
 *      تعمل، إثباتاً لعدم إحداث أي ضرر جانبي في الكود المجاور).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-event-activation-ownership.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

define('ABSPATH', __DIR__ . '/');

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب لتحميل/تشغيل event-factory.php) ─

function add_action(...$args) { /* no-op */ }

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($v) { return trim((string) $v); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) { return $v; }
}
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }
}

// ── تحكّم الاختبار: تسجيل دخول/Nonce/معرّف المستخدم الحالي ──────────────────

$GLOBALS['__test_is_logged_in'] = true;
$GLOBALS['__test_current_user_id'] = 0;

function is_user_logged_in() { return $GLOBALS['__test_is_logged_in']; }
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid-nonce'; }

// ── User Meta وهمي في الذاكرة (نفس نمط بقية ملفات tests/) ───────────────────

$GLOBALS['__test_user_meta'] = [];

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function set_test_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
}
function reset_test_user($user_id)
{
    $GLOBALS['__test_user_meta'][$user_id] = [];
}

// ── Fake $wpdb: GET_LOCK/RELEASE_LOCK فقط — أُضيف لاحقاً بعد Commit 6 (Event
// Quota Architecture)، لأن pge_handle_event_creation() الحقيقية أصبحت تستدعي
// $wpdb->get_var()/$wpdb->query() فعلياً لقفل ذرّي خاص بالمستخدم. إضافة بحتة
// بلا أي أثر على أي حالة/تأكيد موجود مسبقاً في هذا الملف (Commit 4 نفسه لا
// علاقة له بالقفل إطلاقاً) — فقط تُتيح التنفيذ الفعلي لهذا الملف من الأساس
// (كان سيفشل بـ"Call to a member function get_var() on null" بدونها).
class Fake_Wpdb_Event_Lock
{
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
        if (preg_match("/SELECT\\s+GET_LOCK\\('([^']*)',\\s*(-?\\d+)\\)/i", $sql, $m)) {
            $this->held_locks[$m[1]] = true;
            return 1;
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
}
$GLOBALS['wpdb'] = new Fake_Wpdb_Event_Lock();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── Fake Posts/Meta store + WP_Query كافٍ فقط لشكل الاستعلام الفعلي الصادر
// عن pge_handle_event_creation() (post_type/post_status/author، بلا JOIN/LIKE) ─

$GLOBALS['__test_posts'] = [];
$GLOBALS['__test_post_meta'] = [];
$GLOBALS['__test_next_post_id'] = 1;
$GLOBALS['__test_force_wp_insert_post_failure'] = false;
$GLOBALS['__test_force_activation_id_readback_mismatch'] = false;

class WP_Query
{
    public $found_posts = 0;

    public function __construct($args = [])
    {
        $post_type = $args['post_type'] ?? null;
        $statuses = (array) ($args['post_status'] ?? []);
        $author = array_key_exists('author', $args) ? (int) $args['author'] : null;

        $count = 0;
        foreach ($GLOBALS['__test_posts'] as $post) {
            if ($post_type !== null && $post['post_type'] !== $post_type) {
                continue;
            }
            if (!empty($statuses) && !in_array($post['post_status'], $statuses, true)) {
                continue;
            }
            if ($author !== null && (int) $post['post_author'] !== $author) {
                continue;
            }
            $count++;
        }
        $this->found_posts = $count;
    }
}

function wp_insert_post($postarr)
{
    if (!empty($GLOBALS['__test_force_wp_insert_post_failure'])) {
        return 0;
    }

    $id = $GLOBALS['__test_next_post_id']++;
    $GLOBALS['__test_posts'][$id] = [
        'post_type'   => $postarr['post_type'] ?? '',
        'post_status' => $postarr['post_status'] ?? 'publish',
        'post_author' => (int) ($postarr['post_author'] ?? 0),
        'post_title'  => $postarr['post_title'] ?? '',
    ];
    $GLOBALS['__test_post_meta'][$id] = [];

    if (isset($postarr['meta_input']) && is_array($postarr['meta_input'])) {
        foreach ($postarr['meta_input'] as $meta_key => $meta_value) {
            $GLOBALS['__test_post_meta'][$id][$meta_key] = $meta_value;
        }
    }

    return $id;
}

function update_post_meta($post_id, $key, $value)
{
    $GLOBALS['__test_post_meta'][$post_id][$key] = $value;
    return true;
}

function get_post_meta($post_id, $key = '', $single = false)
{
    // حقن فشل قراءة مضبوط بدقة على _pge_event_activation_id فقط (Section 3
    // أدناه) — يحاكي أي سبب فعلي يجعل القيمة المقروءة بعد wp_insert_post()
    // مختلفة عمّا أُريد كتابته (meta_input ليست عملية ذرّية واحدة مضمونة في
    // ووردبريس الفعلي)، بلا أي تعديل على production. كل مفتاح آخر يُقرأ من
    // المخزن الحقيقي كالمعتاد.
    if ($key === '_pge_event_activation_id' && !empty($GLOBALS['__test_force_activation_id_readback_mismatch'])) {
        $value = 'CORRUPTED-BY-TEST';
        return $single ? $value : [$value];
    }

    $value = $GLOBALS['__test_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}

function wp_delete_post($post_id, $force_delete = false)
{
    $existed = isset($GLOBALS['__test_posts'][$post_id]);
    unset($GLOBALS['__test_posts'][$post_id]);
    unset($GLOBALS['__test_post_meta'][$post_id]);
    return $existed;
}

function get_post($post_id)
{
    if (!isset($GLOBALS['__test_posts'][$post_id])) {
        return null;
    }
    return (object) array_merge(['ID' => $post_id], $GLOBALS['__test_posts'][$post_id]);
}

function get_permalink($post_id) { return 'https://example.test/event/' . $post_id . '/'; }

// pge_user_has_feature() غير مُعرَّفة في event-factory.php نفسه (تأتي من
// helpers.php الحقيقي غير المُحمَّل هنا عمداً) — Stub ثابت بلا أي علاقة
// بـEvent Quota (خارج نطاق Commit 4 تماماً)، يبقي $can_google_map=false
// دوماً فتُتجنَّب فروع esc_url_raw/الصورة البارزة غير ذات الصلة بهذا الاختبار.
function pge_user_has_feature($user_id, $feature_key) { return false; }

// ── أدوات إرسال JSON: اعتراض نقطتي الخروج القياسيتين في ووردبريس ───────────

class PGE_Test_Json_Success extends Exception
{
    public $payload;
    public function __construct($payload) { $this->payload = $payload; parent::__construct('json_success'); }
}
class PGE_Test_Json_Error extends Exception
{
    public $payload;
    public function __construct($payload) { $this->payload = $payload; parent::__construct('json_error'); }
}
function wp_send_json_success($data = null) { throw new PGE_Test_Json_Success($data); }
function wp_send_json_error($data = null) { throw new PGE_Test_Json_Error($data); }

// ── تحميل الملف الحقيقي الوحيد الذي عدَّله Commit 4 (بلا أي تعديل عليه) ─────

require_once __DIR__ . '/../includes/event-factory.php';

// ── أدوات الاختبار (نفس نمط check()/check_true() القائم فعلاً) ─────────────

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

/**
 * مساعد اختبار: يضبط $_POST بحقول نموذج إنشاء مناسبة صالحة كاملة، وينفّذ
 * pge_handle_event_creation() الحقيقية، ويلتقط نتيجتها (نجاح/فشل) عبر
 * الاستثناءين أعلاه بدل الاعتماد على أي قيمة إرجاع (الدالة الحقيقية لا تُعيد
 * شيئاً؛ تُنهي الطلب دوماً عبر wp_send_json_*).
 */
function run_create_event(array $post_overrides = [])
{
    $_POST = array_merge([
        'pge_event_nonce' => 'valid-nonce',
        'event_title'     => 'حفل اختبار',
        'event_date'      => '2026-12-01T18:00',
        'host_phone'      => '0599123456',
        'event_location'  => '',
        'event_address'   => 'قاعة الاختبار',
        'invite_code'     => '',
    ], $post_overrides);
    $_FILES = [];

    try {
        pge_handle_event_creation();
        return ['type' => 'none', 'payload' => null];
    } catch (PGE_Test_Json_Success $e) {
        return ['type' => 'success', 'payload' => $e->payload];
    } catch (PGE_Test_Json_Error $e) {
        return ['type' => 'error', 'payload' => $e->payload];
    }
}

echo "=== السيناريو 1: مناسبة جديدة — _pge_event_activation_id = _mon_credit_cycle_id ===\n";

reset_test_user(9401);
set_test_user_meta(9401, '_mon_events_limit', 10);
set_test_user_meta(9401, '_mon_credit_cycle_id', 'cycle-alpha-001');
$GLOBALS['__test_current_user_id'] = 9401;

$counter_before_1 = $GLOBALS['__test_next_post_id'];
$result_1 = run_create_event();
check('1. الإنشاء نجح (wp_send_json_success)', $result_1['type'], 'success');
$new_id_1 = $counter_before_1;
check_true('1. مناسبة جديدة فعلياً في المخزن الوهمي', isset($GLOBALS['__test_posts'][$new_id_1]));
check('1. _pge_event_activation_id مكتوب = cycle-alpha-001', get_post_meta($new_id_1, '_pge_event_activation_id', true), 'cycle-alpha-001');
check('1. يساوي _mon_credit_cycle_id للمستخدم بالضبط', get_post_meta($new_id_1, '_pge_event_activation_id', true), get_user_meta(9401, '_mon_credit_cycle_id', true));

echo "\n=== السيناريو 2: التحقق من الملكية ينجح (بما فيه حالة معرّف فارغ) ===\n";

// 2أ. معرّف تفعيل غير فارغ عادي (تكرار تأكيدي مستقل عن السيناريو 1، بمستخدم/دورة مختلفين)
reset_test_user(9402);
set_test_user_meta(9402, '_mon_events_limit', 10);
set_test_user_meta(9402, '_mon_credit_cycle_id', 'cycle-beta-777');
$GLOBALS['__test_current_user_id'] = 9402;
$counter_before_2a = $GLOBALS['__test_next_post_id'];
$result_2a = run_create_event();
check('2أ. الإنشاء نجح', $result_2a['type'], 'success');
check('2أ. الملكية صحيحة (cycle-beta-777)', get_post_meta($counter_before_2a, '_pge_event_activation_id', true), 'cycle-beta-777');

// 2ب. مستخدم بلا _mon_credit_cycle_id إطلاقاً (مثلاً Legacy) — القيمة فارغة،
// التحقق ينجح لأن المخزَّن والمقروء كلاهما "" بالضبط، ولا يُحظَر الإنشاء
// بسبب هذا الحقل بمفرده (مطابق لقاعدة "بلا أي إنفاذ" في هذا الـCommit).
reset_test_user(9403);
set_test_user_meta(9403, '_mon_events_limit', 10);
// لا _mon_credit_cycle_id لهذا المستخدم عمداً
$GLOBALS['__test_current_user_id'] = 9403;
$counter_before_2b = $GLOBALS['__test_next_post_id'];
$result_2b = run_create_event();
check('2ب. الإنشاء نجح رغم غياب _mon_credit_cycle_id تماماً', $result_2b['type'], 'success');
check('2ب. _pge_event_activation_id مكتوب كسلسلة فارغة (لا حظر، لا قيمة مُختلَقة)', get_post_meta($counter_before_2b, '_pge_event_activation_id', true), '');

echo "\n=== السيناريو 3: فشل ملكية مُجبَر → حذف المناسبة ===\n";

reset_test_user(9404);
set_test_user_meta(9404, '_mon_events_limit', 10);
set_test_user_meta(9404, '_mon_credit_cycle_id', 'cycle-gamma-999');
$GLOBALS['__test_current_user_id'] = 9404;

$GLOBALS['__test_force_activation_id_readback_mismatch'] = true;
$counter_before_3 = $GLOBALS['__test_next_post_id'];
$result_3 = run_create_event();
check('3. فشل التحقق يعيد نفس مسار الخطأ العام الحالي', $result_3['type'], 'error');
check('3. نص الرسالة مطابق لمسار الفشل العام الموجود مسبقاً', $result_3['payload'], 'حدث خطأ أثناء إنشاء المناسبة، يرجى المحاولة لاحقاً.');
check_true('3. المناسبة المُنشَأة حُذفت فعلياً (لم تعد موجودة في المخزن)', !isset($GLOBALS['__test_posts'][$counter_before_3]));
check_true('3. Meta المناسبة المحذوفة حُذفت أيضاً بالكامل', !isset($GLOBALS['__test_post_meta'][$counter_before_3]));
$GLOBALS['__test_force_activation_id_readback_mismatch'] = false;

// تأكيد أن الحالة أعلاه ليست دائمة: تفعيل عادي لاحق لنفس المستخدم بلا حقن ينجح
$counter_before_3b = $GLOBALS['__test_next_post_id'];
$result_3b = run_create_event();
check('3ج. تفعيل لاحق بلا حقن فشل ينجح', $result_3b['type'], 'success');
check('3ج. الملكية صحيحة هذه المرة', get_post_meta($counter_before_3b, '_pge_event_activation_id', true), 'cycle-gamma-999');

echo "\n=== السيناريو 4: المناسبات القديمة تبقى بلا أي تغيير ===\n";

// مناسبة "قديمة" تسبق Commit 4 — لا _pge_event_activation_id إطلاقاً، تُزرَع
// مباشرة في المخزن الوهمي (لا عبر pge_handle_event_creation()، تماماً كما
// تُمثِّل مناسبة حقيقية أُنشئت قبل هذا الـCommit في قاعدة بيانات حقيقية).
$legacy_event_id = $GLOBALS['__test_next_post_id']++;
$GLOBALS['__test_posts'][$legacy_event_id] = [
    'post_type'   => 'pge_event',
    'post_status' => 'publish',
    'post_author' => 9405,
    'post_title'  => 'مناسبة قديمة قبل Commit 4',
];
$GLOBALS['__test_post_meta'][$legacy_event_id] = [
    '_pge_event_date'    => '2025-01-01T10:00',
    '_pge_host_phone'    => '0500000000',
    '_pge_invite_code'   => 'AAAA-BBBB',
];
$legacy_meta_snapshot_before = $GLOBALS['__test_post_meta'][$legacy_event_id];

reset_test_user(9405);
set_test_user_meta(9405, '_mon_events_limit', 10);
set_test_user_meta(9405, '_mon_credit_cycle_id', 'cycle-delta-001');
$GLOBALS['__test_current_user_id'] = 9405;

// إنشاء مناسبة جديدة فعلياً لنفس المستخدم — يجب ألا يمسّ هذا إطلاقاً المناسبة القديمة
$result_4 = run_create_event();
check('4. إنشاء مناسبة جديدة (لنفس المستخدم) لا يزال ينجح', $result_4['type'], 'success');
check_true(
    '4. المناسبة القديمة لم تكتسب _pge_event_activation_id (بلا Backfill إطلاقاً)',
    !array_key_exists('_pge_event_activation_id', $GLOBALS['__test_post_meta'][$legacy_event_id])
);
check('4. باقي Meta المناسبة القديمة لم يتغيّر إطلاقاً', $GLOBALS['__test_post_meta'][$legacy_event_id], $legacy_meta_snapshot_before);
check_true('4. المناسبة القديمة نفسها لا تزال موجودة (لم تُحذَف أو تُمَس)', isset($GLOBALS['__test_posts'][$legacy_event_id]));

echo "\n=== السيناريو 5: انحدار — إنشاء عادي ناجح تماماً + بوابة الحصة القديمة سليمة ===\n";

reset_test_user(9406);
set_test_user_meta(9406, '_mon_events_limit', 2);
set_test_user_meta(9406, '_mon_credit_cycle_id', 'cycle-epsilon-500');
$GLOBALS['__test_current_user_id'] = 9406;

$counter_before_5a = $GLOBALS['__test_next_post_id'];
$result_5a = run_create_event([
    'event_title'    => 'مناسبة الانحدار الأولى',
    'event_date'     => '2026-11-05T20:30',
    'host_phone'     => '0512345678',
    'event_address'  => 'قاعة الانحدار',
    'invite_code'    => 'ZZZZ-9999',
]);
check('5أ. الإنشاء الأول نجح', $result_5a['type'], 'success');
check_true('5أ. redirect_url موجود في الاستجابة (سلوك سابق غير مُتأثِّر)', isset($result_5a['payload']['redirect_url']));
check('5أ. invite_code في الاستجابة = القيمة المُرسَلة كما كان سابقاً', $result_5a['payload']['invite_code'] ?? null, 'ZZZZ-9999');
check('5أ. _pge_event_date محفوظ كالسابق تماماً', get_post_meta($counter_before_5a, '_pge_event_date', true), '2026-11-05T20:30');
check('5أ. _pge_host_phone محفوظ كالسابق تماماً', get_post_meta($counter_before_5a, '_pge_host_phone', true), '0512345678');
check('5أ. _pge_event_address محفوظ كالسابق تماماً', get_post_meta($counter_before_5a, '_pge_event_address', true), 'قاعة الانحدار');
check('5أ. _pge_invite_code محفوظ كالسابق تماماً', get_post_meta($counter_before_5a, '_pge_invite_code', true), 'ZZZZ-9999');
check('5أ. _pge_event_activation_id مكتوب أيضاً (الإضافة الجديدة تتعايش مع الحقول القديمة)', get_post_meta($counter_before_5a, '_pge_event_activation_id', true), 'cycle-epsilon-500');

// ثانية (الحد=2) لا تزال مسموحة
$result_5b = run_create_event();
check('5ب. المناسبة الثانية (ضمن الحد=2) لا تزال مسموحة', $result_5b['type'], 'success');

// ثالثة تتجاوز الحد — بوابة الحصة القديمة الموجودة مسبقاً في نفس الدالة (غير
// المرتبطة بـCommit 4 إطلاقاً) يجب أن تبقى تعمل تماماً كما كانت قبل هذا التعديل.
$result_5c = run_create_event();
check('5ج. المناسبة الثالثة تتجاوز الحد → مرفوضة (بوابة الحصة القديمة سليمة، لم تتأثر بـCommit 4)', $result_5c['type'], 'error');
check_true('5ج. رسالة تجاوز الحد كما كانت (تحتوي على نص استنفاد الحد)', is_string($result_5c['payload']) && strpos($result_5c['payload'], 'استنفدت') !== false);

// ── الملخص النهائي ───────────────────────────────────────────────────────

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
