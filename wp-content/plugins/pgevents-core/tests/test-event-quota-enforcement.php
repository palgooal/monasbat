<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس أسلوب بقية ملفات tests/) لـ
 * Event Quota Architecture — Commit 6 (Atomic Quota Enforcement)، بتنفيذ
 * حقيقي فعلي لدالة الإنتاج pge_handle_event_creation() المُعدَّلة نفسها —
 * لا مرآة منطقية لأي كود إنتاج.
 *
 * يحمّل هذا الملف الملف الحقيقي الوحيد الذي عدَّله Commit 6 دون أي تعديل
 * عليه: includes/event-factory.php.
 *
 * محاكاة GET_LOCK/RELEASE_LOCK هنا (خريطة "محجوز/غير محجوز" ضمن عملية PHP
 * واحدة، بنفس فلسفة tests/test-invitation-credit-ledger.php وغيره من ملفات
 * هذا المشروع) كافية لإثبات: (أ) توازن GET_LOCK/RELEASE_LOCK في كل نقطة
 * خروج (لا تسريب قفل)، و(ب) أن اسم القفل مشتق من user_id فقط (مستخدمون
 * مختلفون لا يتشاركون نفس القفل) — لكنها لا تُثبت سلوك MySQL الفعلي تحت
 * تزامن حقيقي متعدد الاتصالات (قيد موثَّق أيضاً في كل ملفات الأقفال السابقة
 * في هذا المشروع).
 *
 * السيناريوهات الثمانية المطلوبة صراحةً + تحقق شامل من عدم تسريب القفل:
 *   1. مستخدم Legacy → السلوك دون تغيير.
 *   2. Catalog: Quota=10، Used=9 → الإنشاء ينجح.
 *   3. Catalog: Quota=10، Used=10 → الإنشاء مرفوض.
 *   4. Unlimited → الإنشاء ينجح، بلا أي إنفاذ عدّ.
 *   5. خطأ تكامل Catalog → الإنشاء مرفوض.
 *   6. فشل التحقق من الملكية → حذف المناسبة.
 *   7. تحرير القفل بعد: النجاح، رفض الحصة، فشل التكامل، فشل الملكية، فشل الإدراج.
 *   8. انحدار: سلوك إنشاء المناسبة الحالي محفوظ بالكامل.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-event-quota-enforcement.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

define('ABSPATH', __DIR__ . '/');

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
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}

$GLOBALS['__test_is_logged_in'] = true;
$GLOBALS['__test_current_user_id'] = 0;
function is_user_logged_in() { return $GLOBALS['__test_is_logged_in']; }
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid-nonce'; }
function pge_user_has_feature($user_id, $feature_key) { return false; }

// ── User Meta وهمي في الذاكرة ────────────────────────────────────────────

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

// ── Fake $wpdb: GET_LOCK/RELEASE_LOCK فقط (لا جداول — event-factory.php لا
// يستخدم $wpdb لأي شيء آخر) — بنفس فلسفة Fake_Wpdb في
// tests/test-invitation-credit-ledger.php (خريطة "محجوز/غير محجوز" ضمن
// عملية PHP واحدة، كافية لاختبار توازن GET_LOCK/RELEASE_LOCK فقط) ─────────

class Fake_Wpdb_Event_Lock
{
    public $held_locks = [];
    public $lock_acquire_log = [];
    public $lock_release_log = [];
    public $force_lock_unavailable = false;

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
            $name = $m[1];
            $this->lock_acquire_log[] = $name;
            if ($this->force_lock_unavailable) {
                return 0;
            }
            $this->held_locks[$name] = true;
            return 1;
        }
        return null;
    }

    public function query($sql)
    {
        if (preg_match("/SELECT\\s+RELEASE_LOCK\\('([^']*)'\\)/i", $sql, $m)) {
            $name = $m[1];
            $this->lock_release_log[] = $name;
            unset($this->held_locks[$name]);
            return 1;
        }
        return false;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Event_Lock();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── Fake Posts/Meta store + WP_Query (يدعم meta_query، بنفس نسخة
// tests/test-event-quota-resolution.php) ────────────────────────────────

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
        $meta_query = $args['meta_query'] ?? [];

        $count = 0;
        foreach ($GLOBALS['__test_posts'] as $post_id => $post) {
            if ($post_type !== null && $post['post_type'] !== $post_type) {
                continue;
            }
            if (!empty($statuses) && !in_array($post['post_status'], $statuses, true)) {
                continue;
            }
            if ($author !== null && (int) $post['post_author'] !== $author) {
                continue;
            }
            if (!empty($meta_query)) {
                $matches_all = true;
                foreach ($meta_query as $clause) {
                    if (!is_array($clause) || !isset($clause['key'])) {
                        continue;
                    }
                    $actual = $GLOBALS['__test_post_meta'][$post_id][$clause['key']] ?? '';
                    $expected = $clause['value'] ?? '';
                    $compare = $clause['compare'] ?? '=';
                    $matched = ($compare === '=')
                        ? ((string) $actual === (string) $expected)
                        : true;
                    if (!$matched) {
                        $matches_all = false;
                        break;
                    }
                }
                if (!$matches_all) {
                    continue;
                }
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

// ── اعتراض نقطتَي الخروج القياسيتين في ووردبريس ─────────────────────────

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

// ── تحميل الملف الحقيقي الوحيد الذي عدَّله Commit 6 (بلا أي تعديل عليه) ─────

require_once __DIR__ . '/../includes/event-factory.php';

// ── أدوات الاختبار ──────────────────────────────────────────────────────

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

function seed_event($author_id, $activation_id_or_null, $post_status = 'publish')
{
    $id = $GLOBALS['__test_next_post_id']++;
    $GLOBALS['__test_posts'][$id] = [
        'post_type'   => 'pge_event',
        'post_status' => $post_status,
        'post_author' => (int) $author_id,
        'post_title'  => 'مناسبة اختبار ' . $id,
    ];
    $meta = [];
    if ($activation_id_or_null !== null) {
        $meta['_pge_event_activation_id'] = $activation_id_or_null;
    }
    $GLOBALS['__test_post_meta'][$id] = $meta;
    return $id;
}

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

echo "=== السيناريو 1: مستخدم Legacy — السلوك دون تغيير ===\n";

reset_test_user(9601);
set_test_user_meta(9601, '_mon_events_limit', 3);
seed_event(9601, null);
$GLOBALS['__test_current_user_id'] = 9601;

$result_1a = run_create_event();
check('1. مستخدم Legacy ضمن الحد → الإنشاء ينجح', $result_1a['type'], 'success');
check_true('1. القفل مُحرَّر بعد النجاح', empty($wpdb->held_locks));

seed_event(9601, null); // الآن 2 مناسبات + الجديدة أعلاه = 3، يساوي الحد
$result_1b = run_create_event();
check('1. عند بلوغ الحد بالضبط → الرفض (بوابة Legacy القديمة سليمة)', $result_1b['type'], 'error');
check_true('1. رسالة الرفض تحتوي نص استنفاد الحد المعتاد', is_string($result_1b['payload']) && strpos($result_1b['payload'], 'استنفدت') !== false);
check_true('1. القفل مُحرَّر بعد رفض Legacy أيضاً', empty($wpdb->held_locks));

echo "\n=== السيناريو 2: Catalog — Quota=10، Used=9 → الإنشاء ينجح ===\n";

reset_test_user(9602);
set_test_user_meta(9602, '_mon_package_source', 'catalog');
set_test_user_meta(9602, '_mon_credit_cycle_id', 'cycle-s2');
set_test_user_meta(9602, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9602, '_mon_event_quota_limit', 10);
for ($i = 0; $i < 9; $i++) {
    seed_event(9602, 'cycle-s2');
}
$GLOBALS['__test_current_user_id'] = 9602;

$result_2 = run_create_event();
check('2. Used=9 من Quota=10 → الإنشاء ينجح', $result_2['type'], 'success');
check_true('2. القفل مُحرَّر بعد النجاح', empty($wpdb->held_locks));

echo "\n=== السيناريو 3: Catalog — Quota=10، Used=10 → الإنشاء مرفوض ===\n";

reset_test_user(9603);
set_test_user_meta(9603, '_mon_package_source', 'catalog');
set_test_user_meta(9603, '_mon_credit_cycle_id', 'cycle-s3');
set_test_user_meta(9603, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9603, '_mon_event_quota_limit', 10);
for ($i = 0; $i < 10; $i++) {
    seed_event(9603, 'cycle-s3');
}
$GLOBALS['__test_current_user_id'] = 9603;

$posts_before_3 = count($GLOBALS['__test_posts']);
$result_3 = run_create_event();
check('3. Used=10 من Quota=10 → الإنشاء مرفوض', $result_3['type'], 'error');
check_true('3. رسالة الرفض تحتوي نص استنفاد الحد (10 من 10)', is_string($result_3['payload']) && strpos($result_3['payload'], '10') !== false && strpos($result_3['payload'], 'استنفدت') !== false);
check('3. لا مناسبة جديدة أُنشئت فعلياً', count($GLOBALS['__test_posts']), $posts_before_3);
check_true('3. القفل مُحرَّر بعد رفض الحصة', empty($wpdb->held_locks));

echo "\n=== السيناريو 4: Unlimited — الإنشاء ينجح بلا أي إنفاذ عدّ ===\n";

reset_test_user(9604);
set_test_user_meta(9604, '_mon_package_source', 'catalog');
set_test_user_meta(9604, '_mon_credit_cycle_id', 'cycle-s4');
set_test_user_meta(9604, '_mon_event_quota_mode', 'unlimited');
set_test_user_meta(9604, '_mon_event_quota_limit', 1); // مُتجاهَلة عمداً
// نزرع عدداً كبيراً من المناسبات — لإثبات أن Unlimited لا يرفض إطلاقاً بصرف
// النظر عن أي عدد، أي رقم "1" أعلاه لا قيمة له وقت التشغيل.
for ($i = 0; $i < 50; $i++) {
    seed_event(9604, 'cycle-s4');
}
$GLOBALS['__test_current_user_id'] = 9604;

$result_4 = run_create_event();
check('4. Unlimited مع 50 مناسبة موجودة فعلاً → الإنشاء ينجح رغم ذلك', $result_4['type'], 'success');
check_true('4. القفل مُحرَّر بعد النجاح', empty($wpdb->held_locks));

echo "\n=== السيناريو 5: خطأ تكامل Catalog (بلا credit_cycle_id) → الإنشاء مرفوض ===\n";

reset_test_user(9605);
set_test_user_meta(9605, '_mon_package_source', 'catalog');
// لا _mon_credit_cycle_id إطلاقاً
set_test_user_meta(9605, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9605, '_mon_event_quota_limit', 5);
$GLOBALS['__test_current_user_id'] = 9605;

$posts_before_5 = count($GLOBALS['__test_posts']);
$result_5 = run_create_event();
check('5. خطأ تكامل Catalog → الإنشاء مرفوض', $result_5['type'], 'error');
check('5. نفس رسالة الخطأ العامة (لا رسالة جديدة)', $result_5['payload'], 'حدث خطأ أثناء إنشاء المناسبة، يرجى المحاولة لاحقاً.');
check('5. لا مناسبة جديدة أُنشئت', count($GLOBALS['__test_posts']), $posts_before_5);
check_true('5. القفل مُحرَّر بعد فشل التكامل', empty($wpdb->held_locks));

echo "\n=== السيناريو 6: فشل التحقق من الملكية → حذف المناسبة ===\n";

reset_test_user(9606);
set_test_user_meta(9606, '_mon_package_source', 'catalog');
set_test_user_meta(9606, '_mon_credit_cycle_id', 'cycle-s6');
set_test_user_meta(9606, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9606, '_mon_event_quota_limit', 5);
$GLOBALS['__test_current_user_id'] = 9606;

$GLOBALS['__test_force_activation_id_readback_mismatch'] = true;
$counter_before_6 = $GLOBALS['__test_next_post_id'];
$result_6 = run_create_event();
check('6. فشل التحقق من الملكية → مرفوض بنفس الرسالة العامة', $result_6['type'], 'error');
check('6. نص الرسالة', $result_6['payload'], 'حدث خطأ أثناء إنشاء المناسبة، يرجى المحاولة لاحقاً.');
check_true('6. المناسبة حُذفت فعلياً', !isset($GLOBALS['__test_posts'][$counter_before_6]));
check_true('6. القفل مُحرَّر بعد فشل الملكية', empty($wpdb->held_locks));
$GLOBALS['__test_force_activation_id_readback_mismatch'] = false;

echo "\n=== السيناريو 7: تحرير القفل في كل نقاط الخروج (فشل الإدراج تحديداً) ===\n";

reset_test_user(9607);
set_test_user_meta(9607, '_mon_events_limit', 5);
$GLOBALS['__test_current_user_id'] = 9607;
$GLOBALS['__test_force_wp_insert_post_failure'] = true;

$result_7 = run_create_event();
check('7. فشل wp_insert_post() → مرفوض بالرسالة العامة', $result_7['type'], 'error');
check_true('7. القفل مُحرَّر بعد فشل الإدراج (لا تسريب)', empty($wpdb->held_locks));
$GLOBALS['__test_force_wp_insert_post_failure'] = false;

// تأكيد إضافي: أسماء الأقفال المُستخدَمة عبر كل السيناريوهات أعلاه مشتقة من
// user_id فقط — مستخدمون مختلفون استخدموا أسماء أقفال مختلفة بالضرورة (وإلا
// لكانت النتائج قد تداخلت). نتحقق من عدم وجود تكرار غير متوقَّع بين مستخدمين
// مختلفين استخدموا نفس الاسم حرفياً (لا ينبغي أن يحدث هذا إطلاقاً بما أن كل
// اسم = 'pge_event_create_' . md5(user_id) فريد لكل رقم مستخدم مختلف).
$unique_users_tested = [9601, 9602, 9603, 9604, 9605, 9606, 9607];
$expected_lock_names = array_map(function ($uid) { return 'pge_event_create_' . md5((string) $uid); }, $unique_users_tested);
check_true('7. كل مستخدم من السيناريوهات أعلاه استخدم اسم قفل مختلفاً خاصاً به', count(array_unique($expected_lock_names)) === count($unique_users_tested));
foreach ($expected_lock_names as $idx => $expected_name) {
    check_true("7. اسم القفل الفعلي المُستخدَم للمستخدم {$unique_users_tested[$idx]} يطابق الاشتقاق المتوقَّع", in_array($expected_name, $wpdb->lock_acquire_log, true));
}

echo "\n=== السيناريو 8: انحدار — سلوك إنشاء المناسبة الحالي محفوظ بالكامل ===\n";

reset_test_user(9608);
set_test_user_meta(9608, '_mon_events_limit', 5);
$GLOBALS['__test_current_user_id'] = 9608;

$counter_before_8 = $GLOBALS['__test_next_post_id'];
$result_8 = run_create_event([
    'event_title'   => 'مناسبة انحدار Commit 6',
    'event_date'    => '2026-12-20T21:00',
    'host_phone'    => '0511122233',
    'event_address' => 'قاعة الانحدار السادسة',
    'invite_code'   => 'REGR-0006',
]);
check('8. الإنشاء العادي لا يزال ينجح', $result_8['type'], 'success');
check('8. invite_code في الاستجابة كالسابق', $result_8['payload']['invite_code'] ?? null, 'REGR-0006');
check_true('8. redirect_url موجود كالسابق', isset($result_8['payload']['redirect_url']));
check('8. _pge_event_date محفوظ كالسابق', get_post_meta($counter_before_8, '_pge_event_date', true), '2026-12-20T21:00');
check('8. _pge_host_phone محفوظ كالسابق', get_post_meta($counter_before_8, '_pge_host_phone', true), '0511122233');
check('8. _pge_event_address محفوظ كالسابق', get_post_meta($counter_before_8, '_pge_event_address', true), 'قاعة الانحدار السادسة');
check_true('8. القفل مُحرَّر بعد الانحدار الناجح', empty($wpdb->held_locks));

// اختبار حقول ناقصة (عنوان فارغ) لا يزال يرفض بنفس الرسالة، والقفل يُحرَّر أيضاً
reset_test_user(9609);
set_test_user_meta(9609, '_mon_events_limit', 5);
$GLOBALS['__test_current_user_id'] = 9609;
$result_9 = run_create_event(['event_title' => '']);
check('8. عنوان فارغ → نفس رسالة الرفض المعتادة', $result_9['payload'] ?? null, 'يرجى إدخال اسم المناسبة.');
check_true('8. القفل مُحرَّر بعد رفض التحقق من الحقول', empty($wpdb->held_locks));

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
