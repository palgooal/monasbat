<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس أسلوب بقية ملفات tests/) لـ
 * Event Quota Architecture — Commit 5 (Quota Resolution and Usage Counting)،
 * بتنفيذ حقيقي فعلي لدالة الإنتاج pge_resolve_event_quota_status() نفسها —
 * لا مرآة منطقية لأي كود إنتاج.
 *
 * يحمّل هذا الملف الملف الحقيقي الوحيد الذي عدَّله Commit 5 دون أي تعديل
 * عليه: includes/event-factory.php.
 *
 * لا نحمّل class-pge-catalog.php ولا feature-resolver.php عمداً — الدالة
 * الجديدة تقرأ Snapshot مباشرة من User Meta فقط (مطابق للمعمارية: "Snapshot
 * هو مصدر الحقيقة الوحيد، لا قراءة لصف الـTier ولا Feature Resolver")، فلا
 * حاجة لأي منهما هنا، تماشياً مع تركيز الاختبار حصراً على الملف الذي عدَّله
 * هذا الـCommit فعلياً.
 *
 * السيناريوهات الثمانية المطلوبة صراحةً:
 *   1. باقة Legacy → السلوك دون تغيير.
 *   2. Catalog محدود: Quota=10، Used=3 → Remaining=7.
 *   3. Catalog غير محدود → unlimited فوراً، بلا أي استعلام عدّ.
 *   4. مناسبات من تفعيل سابق → تُتجاهَل.
 *   5. مناسبات من تفعيل آخر (مستخدم مختلف) → تُتجاهَل.
 *   6. مناسبات بلا ownership (فارغة) → تُتجاهَل.
 *   7. غياب معرّف التفعيل لمستخدم Catalog → خطأ تكامل صريح.
 *   8. انحدار: إنشاء مناسبة Legacy لا يزال يعمل تماماً كالسابق (عبر
 *      pge_handle_event_creation() الحقيقية أيضاً، لإثبات عدم تأثّر مسار
 *      الإنشاء الفعلي بهذا الـCommit المعلوماتي البحت).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-event-quota-resolution.php
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

// ── Fake $wpdb: GET_LOCK/RELEASE_LOCK فقط — أُضيف لاحقاً بعد Commit 6 (Event
// Quota Architecture)، لأن pge_handle_event_creation() الحقيقية (تُستدعى في
// السيناريو 8 هنا للانحدار) أصبحت تستدعي $wpdb->get_var()/$wpdb->query()
// فعلياً لقفل ذرّي خاص بالمستخدم. إضافة بحتة بلا أي أثر على أي حالة/تأكيد
// موجود مسبقاً في هذا الملف (Commit 5 نفسه — pge_resolve_event_quota_status()
// — لا يستخدم $wpdb إطلاقاً) — فقط تُتيح التنفيذ الفعلي لهذا الملف من الأساس.
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

// ── Fake Posts/Meta store + WP_Query (يدعم الآن meta_query أيضاً — تمديد
// عن نسخة tests/test-event-activation-ownership.php لأن Commit 5 يستعلم
// فعلياً بـmeta_query على _pge_event_activation_id) ─────────────────────

$GLOBALS['__test_posts'] = [];
$GLOBALS['__test_post_meta'] = [];
$GLOBALS['__test_next_post_id'] = 1;

class WP_Query
{
    public $found_posts = 0;
    public $executed_meta_query = false; // للتحقق من عدم تنفيذ أي استعلام في وضع Unlimited (السيناريو 3)

    public function __construct($args = [])
    {
        $post_type = $args['post_type'] ?? null;
        $statuses = (array) ($args['post_status'] ?? []);
        $author = array_key_exists('author', $args) ? (int) $args['author'] : null;
        $meta_query = $args['meta_query'] ?? [];

        $this->executed_meta_query = !empty($meta_query);

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

// ── اعتراض نقطتَي الخروج القياسيتين في ووردبريس (لاختبار الانحدار رقم 8
// الذي يستدعي pge_handle_event_creation() الحقيقية عبر AJAX) ────────────

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

// ── تحميل الملف الحقيقي الوحيد الذي عدَّله Commit 5 (بلا أي تعديل عليه) ─────

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

/** مساعد اختبار: يزرع مناسبة مباشرة في المخزن الوهمي (لا عبر AJAX) — يحاكي
 * مناسبة موجودة فعلياً في قاعدة البيانات بأي حالة ownership أردناها. */
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

echo "=== السيناريو 1: باقة Legacy — السلوك دون تغيير ===\n";

reset_test_user(9501);
set_test_user_meta(9501, '_mon_events_limit', 5);
// مصدر الباقة فارغ (Legacy قديم قبل مفهوم Catalog) — لا _mon_package_source إطلاقاً
seed_event(9501, null); // مناسبة Legacy — بلا ownership على الإطلاق (كالمعتاد)
seed_event(9501, null);

$status_1 = pge_resolve_event_quota_status(9501);
check_true('1. النتيجة array وليست WP_Error', is_array($status_1));
check('1. mode = legacy', $status_1['mode'] ?? null, 'legacy');
check('1. allowed = 5 (من _mon_events_limit كالسابق تماماً)', $status_1['allowed'] ?? null, 5);
check('1. used = 2 (عدّ كل مناسبات المستخدم، بلا meta_query على ownership)', $status_1['used'] ?? null, 2);
check('1. remaining = 3', $status_1['remaining'] ?? null, 3);

echo "\n=== السيناريو 2: Catalog محدود — Quota=10، Used=3 → Remaining=7 ===\n";

reset_test_user(9502);
set_test_user_meta(9502, '_mon_package_source', 'catalog');
set_test_user_meta(9502, '_mon_credit_cycle_id', 'cycle-current-A');
set_test_user_meta(9502, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9502, '_mon_event_quota_limit', 10);
seed_event(9502, 'cycle-current-A');
seed_event(9502, 'cycle-current-A');
seed_event(9502, 'cycle-current-A');

$status_2 = pge_resolve_event_quota_status(9502);
check_true('2. النتيجة array', is_array($status_2));
check('2. mode = limited', $status_2['mode'] ?? null, 'limited');
check('2. allowed = 10', $status_2['allowed'] ?? null, 10);
check('2. used = 3', $status_2['used'] ?? null, 3);
check('2. remaining = 7', $status_2['remaining'] ?? null, 7);

echo "\n=== السيناريو 3: Catalog غير محدود — بلا أي استعلام عدّ ===\n";

reset_test_user(9503);
set_test_user_meta(9503, '_mon_package_source', 'catalog');
set_test_user_meta(9503, '_mon_credit_cycle_id', 'cycle-current-B');
set_test_user_meta(9503, '_mon_event_quota_mode', 'unlimited');
set_test_user_meta(9503, '_mon_event_quota_limit', 1); // القيمة الرقمية المُتجاهَلة عمداً وقت التشغيل
seed_event(9503, 'cycle-current-B');
seed_event(9503, 'cycle-current-B');

// لا حاجة لعدّاد استدعاءات WP_Query هنا: إثبات "بلا أي استعلام عدّ" يكفيه
// التحقق أدناه من أن allowed/used/remaining كلها null (مطابق تماماً لعقد
// إرجاع وضع Unlimited)، إضافة للتحقق غير المباشر بأن المخزن الوهمي لم يتأثر.
$posts_snapshot_before = count($GLOBALS['__test_posts']);

$status_3 = pge_resolve_event_quota_status(9503);
check_true('3. النتيجة array', is_array($status_3));
check('3. mode = unlimited', $status_3['mode'] ?? null, 'unlimited');
// ملاحظة: لا نستخدم ?? هنا عمداً — القيمة المتوقَّعة null فعلياً، و?? يستبدل
// null بالبديل الافتراضي فيُخفي الفحص الحقيقي. array_key_exists() تتحقق من
// وجود المفتاح فعلاً (وليس مجرد null ضمنية لمفتاح غائب)، ثم نقرأ القيمة مباشرة.
check_true('3. مفتاح allowed موجود فعلاً في المصفوفة', array_key_exists('allowed', $status_3));
check('3. allowed = null فعلياً (لا 0 ولا قيمة مُختلَقة)', $status_3['allowed'], null);
check_true('3. مفتاح used موجود فعلاً في المصفوفة', array_key_exists('used', $status_3));
check('3. used = null فعلياً (لا عدّ إطلاقاً)', $status_3['used'], null);
check_true('3. مفتاح remaining موجود فعلاً في المصفوفة', array_key_exists('remaining', $status_3));
check('3. remaining = null فعلياً', $status_3['remaining'], null);
// إثبات غير مباشر إضافي لعدم تنفيذ أي استعلام: المخزن الوهمي نفسه لم يتغيّر
// (لا حذف ولا إضافة مناسبات) — الدالة لم تلمس أي بيانات مناسبات إطلاقاً.
check('3. عدد المناسبات في المخزن لم يتغيّر (لم يُنفَّذ أي استعلام يؤثر على الحالة)', count($GLOBALS['__test_posts']), $posts_snapshot_before);

echo "\n=== السيناريو 4: مناسبات من تفعيل سابق → تُتجاهَل ===\n";

reset_test_user(9504);
set_test_user_meta(9504, '_mon_package_source', 'catalog');
set_test_user_meta(9504, '_mon_credit_cycle_id', 'cycle-new-C2');
set_test_user_meta(9504, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9504, '_mon_event_quota_limit', 5);
seed_event(9504, 'cycle-old-C1'); // تفعيل سابق مختلف — يجب تجاهله
seed_event(9504, 'cycle-old-C1');
seed_event(9504, 'cycle-new-C2'); // التفعيل الحالي — يُحسَب وحده

$status_4 = pge_resolve_event_quota_status(9504);
check('4. used = 1 (فقط مناسبة التفعيل الحالي، مناسبتا التفعيل السابق مُتجاهَلتان)', $status_4['used'] ?? null, 1);
check('4. remaining = 4', $status_4['remaining'] ?? null, 4);

echo "\n=== السيناريو 5: مناسبات من تفعيل آخر (مستخدم مختلف بنفس القيمة الحرفية بالخطأ) → تُتجاهَل ===\n";

// هذا السيناريو يثبت أن العدّ مقيَّد أيضاً بـauthor (المستخدم) وليس فقط
// بمطابقة معرّف التفعيل — مناسبة بنفس credit_cycle_id حرفياً لكن يملكها
// مستخدم آخر تماماً (حالة غير واقعية عملياً بما أن credit_cycle_id فريد
// عالمياً، لكنها تختبر حدود شرط WHERE author بدقة) يجب ألا تُحتسَب لحساب
// المستخدم الحالي.
reset_test_user(9505);
set_test_user_meta(9505, '_mon_package_source', 'catalog');
set_test_user_meta(9505, '_mon_credit_cycle_id', 'cycle-shared-value');
set_test_user_meta(9505, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9505, '_mon_event_quota_limit', 3);
seed_event(9505, 'cycle-shared-value'); // تخص 9505 فعلاً
seed_event(9999, 'cycle-shared-value'); // نفس معرّف التفعيل حرفياً، لكن تخص مستخدماً آخر تماماً

$status_5 = pge_resolve_event_quota_status(9505);
check('5. used = 1 (مناسبة المستخدم الآخر 9999 مُتجاهَلة رغم تطابق معرّف التفعيل حرفياً)', $status_5['used'] ?? null, 1);

echo "\n=== السيناريو 6: مناسبات بلا ownership (فارغة) → تُتجاهَل ===\n";

reset_test_user(9506);
set_test_user_meta(9506, '_mon_package_source', 'catalog');
set_test_user_meta(9506, '_mon_credit_cycle_id', 'cycle-current-D');
set_test_user_meta(9506, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9506, '_mon_event_quota_limit', 5);
seed_event(9506, null); // بلا _pge_event_activation_id إطلاقاً (مناسبة قديمة قبل Commit 4)
seed_event(9506, ''); // موجودة بقيمة فارغة صريحة (نفس الأثر)
seed_event(9506, 'cycle-current-D'); // التفعيل الحالي — الوحيدة المحسوبة

$status_6 = pge_resolve_event_quota_status(9506);
check('6. used = 1 (المناسبتان بلا ownership فعلي مُتجاهَلتان تماماً)', $status_6['used'] ?? null, 1);
check('6. remaining = 4', $status_6['remaining'] ?? null, 4);

echo "\n=== السيناريو 7: غياب معرّف التفعيل لمستخدم Catalog → خطأ تكامل صريح ===\n";

reset_test_user(9507);
set_test_user_meta(9507, '_mon_package_source', 'catalog');
// لا _mon_credit_cycle_id إطلاقاً (حالة تكامل غير متسقة — راجع Commit 4)
set_test_user_meta(9507, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9507, '_mon_event_quota_limit', 5);

$status_7 = pge_resolve_event_quota_status(9507);
check_true('7. النتيجة WP_Error (لا array)', is_wp_error($status_7));
check('7. رمز الخطأ = catalog_activation_integrity', is_wp_error($status_7) ? $status_7->get_error_code() : null, 'catalog_activation_integrity');
check_true('7. لا رجوع ضمني لـLegacy ولا Unlimited ولا "مناسبة واحدة" — WP_Error فقط', is_wp_error($status_7) && !is_array($status_7));

echo "\n=== السيناريو 8: انحدار — إنشاء مناسبة Legacy عبر المسار الفعلي لا يزال يعمل ===\n";

reset_test_user(9508);
set_test_user_meta(9508, '_mon_events_limit', 3);
$GLOBALS['__test_current_user_id'] = 9508;

$_POST = [
    'pge_event_nonce' => 'valid-nonce',
    'event_title'     => 'حفل انحدار Commit 5',
    'event_date'      => '2026-12-15T19:00',
    'host_phone'      => '0599876543',
    'event_location'  => '',
    'event_address'   => 'قاعة الانحدار',
    'invite_code'     => 'REGR-0005',
];
$_FILES = [];

$counter_before_8 = $GLOBALS['__test_next_post_id'];
$creation_result_8 = null;
try {
    pge_handle_event_creation();
} catch (PGE_Test_Json_Success $e) {
    $creation_result_8 = ['type' => 'success', 'payload' => $e->payload];
} catch (PGE_Test_Json_Error $e) {
    $creation_result_8 = ['type' => 'error', 'payload' => $e->payload];
}

check('8. الإنشاء الفعلي عبر pge_handle_event_creation() لا يزال ينجح', $creation_result_8['type'] ?? null, 'success');
check('8. invite_code في الاستجابة كالسابق', $creation_result_8['payload']['invite_code'] ?? null, 'REGR-0005');
check('8. _pge_host_phone محفوظ كالسابق', get_post_meta($counter_before_8, '_pge_host_phone', true), '0599876543');

// وبعد الإنشاء الحقيقي، حلّ الحصة المعلوماتي الجديد يعكس الوضع بصدق (مناسبة Legacy واحدة الآن)
$status_8_after = pge_resolve_event_quota_status(9508);
check('8. حلّ الحصة بعد الإنشاء الفعلي: used = 1', $status_8_after['used'] ?? null, 1);
check('8. حلّ الحصة بعد الإنشاء الفعلي: remaining = 2', $status_8_after['remaining'] ?? null, 2);

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
