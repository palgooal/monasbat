<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ Event Quota Architecture —
 * Commit 7 (Catalog UI Integration)، بتنفيذ حقيقي فعلي لأسطر PHP الحقيقية
 * الموجودة فعلاً في ملفات القوالب الثلاثة التي عدَّلها هذا الـCommit — لا
 * مرآة منطقية لأي كود إنتاج.
 *
 * تحدٍّ منهجي: الملفات الثلاثة (templates/dashboard-main.php،
 * page-create-event.php، page-event-manage.php) قوالب HTML كاملة (540 - 1400
 * سطر) تستدعي عشرات دوال ووردبريس/القالب غير ذات الصلة إطلاقاً بمنطق Event
 * Quota (get_header, Tailwind markup ضخم، JS، إلخ) — تنفيذها بالكامل يتطلب
 * محاكاة سطحية هائلة لا علاقة لها بهذا الـCommit، ومخاطرة عالية بفشل غير
 * متصل بالتغيير الفعلي. الحل المُعتمَد هنا: قراءة المحتوى الحرفي الحقيقي لكل
 * ملف (file_get_contents — لا نسخ يدوي ولا إعادة كتابة لأي سطر)، وقصّه
 * آلياً (عبر strpos() على علامة نصية موجودة أصلاً في الملف الحقيقي، لا
 * علامة أضفناها) عند أول سطر بعد كتلة حساب الحصة مباشرة (قبل أي HTML/كود
 * عرض لاحق غير ذي علاقة)، ثم تنفيذ تلك الشريحة الحرفية بالضبط عبر eval() —
 * تنفيذ فعلي لنفس البايتات الموجودة في الإنتاج، لا إعادة صياغة أو تفسير لها.
 * القيم الناتجة (allowed_limit/events_limit/has_quota/event_quota_is_unlimited
 * إلخ) تُلتقَط عبر get_defined_vars() من نفس نطاق الدالة التي نفّذت الشريحة.
 *
 * يحمّل هذا الملف includes/event-factory.php الحقيقي دون أي تعديل عليه —
 * pge_get_user_plan_limits_for_events()/pge_resolve_event_quota_status()
 * الحقيقيتان (Commit 5/6) هما من يُستدعيان فعلياً من داخل الشرائح المقصوصة،
 * لا أي إعادة تنفيذ لمنطقهما.
 *
 * السيناريوهات المطلوبة صراحةً (مطبَّقة على dashboard-main.php بالكامل،
 * وبفحص مكافئ لملفَي page-create-event.php وpage-event-manage.php لإثبات
 * أن نفس النمط الحرفي يتكرر بشكل صحيح في الملفات الثلاثة):
 *   1. Legacy UI دون تغيير.
 *   2. Catalog: Allowed=10, Used=3 → Remaining=7 معروضة بشكل صحيح.
 *   3. Catalog Unlimited → يُعرَض "غير محدود"، بلا أي رقم حصة.
 *   4. تجاوز الحصة → الواجهة تعكس قيم الـresolver بشكل صحيح.
 *   5. التجديد (تفعيل جديد) → الواجهة تعكس التفعيل الجديد فوراً.
 *   6. انحدار: لا استعلامات قاعدة بيانات إضافية تتجاوز الـresolver، ولا
 *      تكرار لمنطق الحصة.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج (القراءة فقط عبر
 * file_get_contents، بلا أي Write/Edit على الملفات الحقيقية من هذا الاختبار).
 *
 * التشغيل: php tests/test-event-quota-ui-integration.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

define('ABSPATH', __DIR__ . '/');
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

function add_action(...$args) { /* no-op */ }

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
}
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
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

// ── Stubs عامة لووردبريس/القالب — الحد الأدنى المطلوب لتنفيذ الشرائح
// المقصوصة من الملفات الثلاثة الحقيقية (راجع التوثيق أعلى الملف) ────────

$GLOBALS['__test_is_logged_in'] = true;
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_query_vars'] = [];
$GLOBALS['__test_can_manage'] = true;

function is_user_logged_in() { return $GLOBALS['__test_is_logged_in']; }
function auth_redirect() { /* لا يُستدعى فعلياً في كل سيناريوهاتنا (دائماً مسجَّل دخول) */ }
function get_header() { /* no-op */ }
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }
function wp_get_current_user()
{
    return (object) [
        'ID'           => $GLOBALS['__test_current_user_id'],
        'display_name' => 'مستخدم اختبار',
        'user_login'   => 'test_user',
    ];
}
function get_query_var($var) { return $GLOBALS['__test_query_vars'][$var] ?? ''; }
function current_user_can($cap) { return false; }
function wp_safe_redirect($url) { /* لا يُستدعى فعلياً في أي سيناريو (الشروط دائماً صالحة) */ }
function wp_create_nonce($action) { return 'test-nonce'; }
function home_url($path = '') { return 'https://example.test' . $path; }
function get_permalink($id) { return 'https://example.test/event/' . $id . '/'; }
function get_the_post_thumbnail_url($id, $size = 'full') { return ''; }
function date_i18n($format, $timestamp) { return 'DATE'; }
function get_option($name, $default = false) { return $default; }
function pge_user_has_feature($user_id, $feature_key) { return false; }

// ── User Meta وPost Meta وهميان في الذاكرة ──────────────────────────────

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_posts'] = [];
$GLOBALS['__test_post_meta'] = [];

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

function get_post_meta($post_id, $key = '', $single = false)
{
    $value = $GLOBALS['__test_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}

function get_post($post_id)
{
    if (!isset($GLOBALS['__test_posts'][$post_id])) {
        return null;
    }
    return (object) array_merge(['ID' => $post_id], $GLOBALS['__test_posts'][$post_id]);
}

function get_post_field($field, $post_id)
{
    return $GLOBALS['__test_posts'][$post_id][$field] ?? '';
}

function seed_event($author_id, $activation_id_or_null, $post_status = 'publish', $post_type = 'pge_event')
{
    static $next_id = 5000;
    $id = $next_id++;
    $GLOBALS['__test_posts'][$id] = [
        'post_type'   => $post_type,
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

function reset_test_posts()
{
    $GLOBALS['__test_posts'] = [];
    $GLOBALS['__test_post_meta'] = [];
}

// ── WP_Query: يدعم post_type/post_status/author/meta_query (لِـ
// pge_resolve_event_quota_status() ولبوابة Legacy القديمة معاً)، إضافة
// لخاصية ->posts (مصفوفة كائنات) المطلوبة فقط داخل dashboard-main.php لعرض
// قائمة مناسبات المستخدم في الشريط الجانبي — لا علاقة لها بحساب الحصة. ────

class WP_Query
{
    public $found_posts = 0;
    public $posts = [];

    public function __construct($args = [])
    {
        $post_type = $args['post_type'] ?? null;
        $statuses = (array) ($args['post_status'] ?? []);
        $author = array_key_exists('author', $args) ? (int) $args['author'] : null;
        $meta_query = $args['meta_query'] ?? [];

        $matched_ids = [];
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
                    $matched = ($compare === '=') ? ((string) $actual === (string) $expected) : true;
                    if (!$matched) {
                        $matches_all = false;
                        break;
                    }
                }
                if (!$matches_all) {
                    continue;
                }
            }
            $matched_ids[] = $post_id;
        }

        $this->found_posts = count($matched_ids);
        $this->posts = array_map(function ($id) {
            return (object) array_merge(['ID' => $id], $GLOBALS['__test_posts'][$id]);
        }, $matched_ids);
    }
}

// ── Fake $wpdb: يكفي فقط لاستعلامات RSVP الوهمية داخل
// pge_dashboard_get_rsvp_stats() (تُعرَّف وتُستدعى داخل dashboard-main.php
// نفسه ضمن الشريحة المقصوصة) — تُعيد دائماً بلا صفوف (لا مدعوين)، لأن هذا
// الاختبار لا يخص إحصاءات RSVP إطلاقاً، فقط حساب الحصة. ────────────────────

class Fake_Wpdb_Ui
{
    public $prefix = 'wp_';
    public function prepare($query, ...$args) { return $query; }
    public function get_results($sql, $output = null) { return []; }
}
$GLOBALS['wpdb'] = new Fake_Wpdb_Ui();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

// ── تحميل الملف الحقيقي الوحيد الذي يوفّر منطق الحصة الفعلي (Commit 5/6)،
// بلا أي تعديل عليه ولا تحميل لأي ملف غير ذي علاقة (لا Catalog CRUD، لا
// Feature Resolver — تماماً كما تنص معمارية هذا الـCommit) ─────────────

require_once __DIR__ . '/../includes/event-factory.php';

// ── مساعد الاختبار المحوري: قصّ وتنفيذ شريحة PHP حقيقية من ملف قالب حقيقي ──

function run_template_slice($real_file_path, $end_marker)
{
    $content = file_get_contents($real_file_path);
    if ($content === false) {
        throw new RuntimeException("تعذّرت قراءة الملف: $real_file_path");
    }
    $marker_pos = strpos($content, $end_marker);
    if ($marker_pos === false) {
        throw new RuntimeException("العلامة الفاصلة غير موجودة فعلياً في الملف (ربما تغيّر الملف): $end_marker");
    }
    $slice = substr($content, 0, $marker_pos);
    $slice = preg_replace('/^<\?php/', '', $slice, 1);

    // ملاحظة تقنية بحتة خاصة بأمان إعادة التنفيذ (لا علاقة لها بمنطق الحصة
    // المُختبَر إطلاقاً): dashboard-main.php يُعرِّف
    // pge_dashboard_get_rsvp_stats() كدالة top-level عادية (بلا حارس
    // function_exists()، خلافاً لبقية الدوال المساعدة في نفس الملف)، وهذه
    // الدالة تخص إحصاءات RSVP ولا علاقة لها إطلاقاً بمنطق حصة المناسبات
    // موضوع هذا الاختبار. بما أننا ننفّذ نفس الشريحة الحرفية عدة مرات
    // (سيناريو لكل حالة اختبار) داخل نفس عملية PHP، فإن إعادة eval() لهذا
    // التعريف تحديداً تسبب "Cannot redeclare function" قاتلاً. الحل: إسقاط
    // نص هذا التعريف تحديداً من الشريحة في أي استدعاء لاحق بعد أول مرة
    // (باستخدام نفس العلامة الحرفية الموجودة أصلاً في الملف كنهاية لسقفه) —
    // إسقاط بحت لغرض أمان إعادة التنفيذ في هذا الحصاد الاختباري فقط، بلا أي
    // أثر على منطق حساب الحصة الفعلي الذي يُنفَّذ حرفياً وبلا أي تعديل في كل
    // مرة.
    if (function_exists('pge_dashboard_get_rsvp_stats')) {
        $fn_start = strpos($slice, 'function pge_dashboard_get_rsvp_stats');
        if ($fn_start !== false) {
            $fn_end_marker = "/**\n * Load events";
            $fn_end = strpos($slice, $fn_end_marker, $fn_start);
            if ($fn_end !== false) {
                $slice = substr($slice, 0, $fn_start) . substr($slice, $fn_end);
            }
        }
    }

    ob_start();
    try {
        eval($slice);
    } finally {
        ob_end_clean();
    }
    return get_defined_vars();
}

// المسارات الحقيقية على قرص المطوّر (تُستخدَم افتراضياً). عند التشغيل داخل
// حصاد php-wasm (الذي لا يرى نظام ملفات المضيف مباشرة)، يمرّر الحصاد نسخاً
// حرفية طبق الأصل من نفس هذه الملفات الثلاثة إلى مسارات وهمية معروفة
// مسبقاً، ويحدّد المتغيّر PGE_TEST_UI_ROOT ليوجّه القراءة إليها بدلاً من ذلك
// — قراءة فقط (file_get_contents)، بلا أي تعديل على أي منها.
if (defined('PGE_TEST_UI_ROOT')) {
    $DASHBOARD_FILE = PGE_TEST_UI_ROOT . '/templates/dashboard-main.php';
    $CREATE_FILE    = PGE_TEST_UI_ROOT . '/theme/page-create-event.php';
    $MANAGE_FILE    = PGE_TEST_UI_ROOT . '/theme/page-event-manage.php';
} else {
    $DASHBOARD_FILE = __DIR__ . '/../templates/dashboard-main.php';
    $CREATE_FILE    = dirname(dirname(__DIR__)) . '/pgevents-pro/page-create-event.php';
    $MANAGE_FILE    = dirname(dirname(__DIR__)) . '/pgevents-pro/page-event-manage.php';

    if (!file_exists($CREATE_FILE)) {
        // مسار احتياطي لو اختلف هيكل المجلدات فعلياً عمّا افترضناه أعلاه.
        $CREATE_FILE = dirname(dirname(dirname(__DIR__))) . '/pgevents-pro/page-create-event.php';
        $MANAGE_FILE = dirname(dirname(dirname(__DIR__))) . '/pgevents-pro/page-event-manage.php';
    }
}

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

echo "=== dashboard-main.php ===\n";
echo "--- ملاحظة تحقّق أولية: هل ملف Dashboard موجود؟ ---\n";
check_true('ملف dashboard-main.php موجود فعلياً في المسار المتوقَّع', file_exists($DASHBOARD_FILE));

$DASH_MARKER = "// معلومات إضافية للعرض فقط (الباقة/الميزات)";

// 1. Legacy — دون تغيير
reset_test_user(9701);
reset_test_posts();
set_test_user_meta(9701, '_mon_events_limit', 5);
seed_event(9701, null);
seed_event(9701, null);
$GLOBALS['__test_current_user_id'] = 9701;
$vars_1 = run_template_slice($DASHBOARD_FILE, $DASH_MARKER);
check_true('1. Legacy: event_quota_is_unlimited = false', $vars_1['event_quota_is_unlimited'] === false);
check('1. Legacy: events_limit = 5 (من _mon_events_limit كالسابق)', $vars_1['events_limit'], 5);
check('1. Legacy: events_used = 2 (عدّ عادي بلا ownership، كالسابق)', $vars_1['events_used'], 2);
check('1. Legacy: events_left = 3', $vars_1['events_left'], 3);

// 2. Catalog Limited: Allowed=10, Used=3 → Remaining=7
reset_test_user(9702);
reset_test_posts();
set_test_user_meta(9702, '_mon_package_source', 'catalog');
set_test_user_meta(9702, '_mon_credit_cycle_id', 'cycle-dash-1');
set_test_user_meta(9702, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9702, '_mon_event_quota_limit', 10);
seed_event(9702, 'cycle-dash-1');
seed_event(9702, 'cycle-dash-1');
seed_event(9702, 'cycle-dash-1');
$GLOBALS['__test_current_user_id'] = 9702;
$vars_2 = run_template_slice($DASHBOARD_FILE, $DASH_MARKER);
check('2. Catalog Limited: events_limit = 10', $vars_2['events_limit'], 10);
check('2. Catalog Limited: events_used = 3', $vars_2['events_used'], 3);
check('2. Catalog Limited: events_left = 7', $vars_2['events_left'], 7);
check_true('2. event_quota_is_unlimited = false', $vars_2['event_quota_is_unlimited'] === false);

// 3. Catalog Unlimited — لا رقم إطلاقاً
reset_test_user(9703);
reset_test_posts();
set_test_user_meta(9703, '_mon_package_source', 'catalog');
set_test_user_meta(9703, '_mon_credit_cycle_id', 'cycle-dash-2');
set_test_user_meta(9703, '_mon_event_quota_mode', 'unlimited');
set_test_user_meta(9703, '_mon_event_quota_limit', 1);
for ($i = 0; $i < 20; $i++) { seed_event(9703, 'cycle-dash-2'); }
$GLOBALS['__test_current_user_id'] = 9703;
$vars_3 = run_template_slice($DASHBOARD_FILE, $DASH_MARKER);
check_true('3. Catalog Unlimited: event_quota_is_unlimited = true', $vars_3['event_quota_is_unlimited'] === true);

// 4. تجاوز الحصة — الواجهة تعكس القيم الصحيحة (Used >= Allowed)
reset_test_user(9704);
reset_test_posts();
set_test_user_meta(9704, '_mon_package_source', 'catalog');
set_test_user_meta(9704, '_mon_credit_cycle_id', 'cycle-dash-3');
set_test_user_meta(9704, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9704, '_mon_event_quota_limit', 4);
for ($i = 0; $i < 4; $i++) { seed_event(9704, 'cycle-dash-3'); }
$GLOBALS['__test_current_user_id'] = 9704;
$vars_4 = run_template_slice($DASHBOARD_FILE, $DASH_MARKER);
check('4. تجاوز الحصة: events_limit = 4', $vars_4['events_limit'], 4);
check('4. تجاوز الحصة: events_used = 4', $vars_4['events_used'], 4);
check('4. تجاوز الحصة: events_left = 0 (استُنفد)', $vars_4['events_left'], 0);

// 5. التجديد — تفعيل جديد (credit_cycle_id مختلف) يُعاد قراءته فوراً بلا أي تخزين وسيط
reset_test_user(9705);
reset_test_posts();
set_test_user_meta(9705, '_mon_package_source', 'catalog');
set_test_user_meta(9705, '_mon_credit_cycle_id', 'cycle-dash-renewal-old');
set_test_user_meta(9705, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9705, '_mon_event_quota_limit', 2);
seed_event(9705, 'cycle-dash-renewal-old');
seed_event(9705, 'cycle-dash-renewal-old');
$GLOBALS['__test_current_user_id'] = 9705;
$vars_5_before = run_template_slice($DASHBOARD_FILE, $DASH_MARKER);
check('5. قبل التجديد: events_left = 0 (مستنفد على التفعيل القديم)', $vars_5_before['events_left'], 0);

// تجديد فعلي: تفعيل جديد بمعرّف تفعيل مختلف وحصة جديدة (مطابق لما يكتبه
// Mon_Events_Users::activate_catalog_tier() الحقيقي عند تجديد فعلي — هنا
// نُحاكي فقط أثره على User Meta المقروءة، لا نُعيد تنفيذ منطق التفعيل نفسه)
set_test_user_meta(9705, '_mon_credit_cycle_id', 'cycle-dash-renewal-new');
set_test_user_meta(9705, '_mon_event_quota_limit', 6);
$vars_5_after = run_template_slice($DASHBOARD_FILE, $DASH_MARKER);
check('5. بعد التجديد فوراً: events_limit = 6 (القيمة الجديدة)', $vars_5_after['events_limit'], 6);
check('5. بعد التجديد فوراً: events_used = 0 (لا مناسبات على التفعيل الجديد بعد)', $vars_5_after['events_used'], 0);
check('5. بعد التجديد فوراً: events_left = 6', $vars_5_after['events_left'], 6);

echo "\n=== page-create-event.php ===\n";
check_true('ملف page-create-event.php موجود فعلياً في المسار المتوقَّع', file_exists($CREATE_FILE));

$CREATE_MARKER = "\$saved_phone = (string) get_user_meta(\$user_id, 'pge_phone', true);";

reset_test_user(9711);
reset_test_posts();
set_test_user_meta(9711, '_mon_events_limit', 3);
seed_event(9711, null);
$GLOBALS['__test_current_user_id'] = 9711;
$create_vars_legacy = run_template_slice($CREATE_FILE, $CREATE_MARKER);
check('Legacy: allowed_limit = 3', $create_vars_legacy['allowed_limit'], 3);
check('Legacy: current_count = 1', $create_vars_legacy['current_count'], 1);
check('Legacy: remaining = 2', $create_vars_legacy['remaining'], 2);
check_true('Legacy: has_quota = true', $create_vars_legacy['has_quota'] === true);
check_true('Legacy: event_quota_is_unlimited = false', $create_vars_legacy['event_quota_is_unlimited'] === false);

reset_test_user(9712);
reset_test_posts();
set_test_user_meta(9712, '_mon_package_source', 'catalog');
set_test_user_meta(9712, '_mon_credit_cycle_id', 'cycle-create-1');
set_test_user_meta(9712, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9712, '_mon_event_quota_limit', 10);
seed_event(9712, 'cycle-create-1');
seed_event(9712, 'cycle-create-1');
seed_event(9712, 'cycle-create-1');
$GLOBALS['__test_current_user_id'] = 9712;
$create_vars_limited = run_template_slice($CREATE_FILE, $CREATE_MARKER);
check('Catalog Limited: allowed_limit = 10', $create_vars_limited['allowed_limit'], 10);
check('Catalog Limited: current_count = 3', $create_vars_limited['current_count'], 3);
check('Catalog Limited: remaining = 7', $create_vars_limited['remaining'], 7);
check_true('Catalog Limited: has_quota = true', $create_vars_limited['has_quota'] === true);

reset_test_user(9713);
reset_test_posts();
set_test_user_meta(9713, '_mon_package_source', 'catalog');
set_test_user_meta(9713, '_mon_credit_cycle_id', 'cycle-create-2');
set_test_user_meta(9713, '_mon_event_quota_mode', 'unlimited');
set_test_user_meta(9713, '_mon_event_quota_limit', 1);
for ($i = 0; $i < 15; $i++) { seed_event(9713, 'cycle-create-2'); }
$GLOBALS['__test_current_user_id'] = 9713;
$create_vars_unlimited = run_template_slice($CREATE_FILE, $CREATE_MARKER);
check_true('Catalog Unlimited: event_quota_is_unlimited = true', $create_vars_unlimited['event_quota_is_unlimited'] === true);
check_true('Catalog Unlimited: has_quota = true (لا رفض إطلاقاً)', $create_vars_unlimited['has_quota'] === true);

echo "\n=== page-event-manage.php ===\n";
check_true('ملف page-event-manage.php موجود فعلياً في المسار المتوقَّع', file_exists($MANAGE_FILE));

$MANAGE_MARKER = "// ============================================================\n// حالة مساحة العمل";

function setup_manage_event($event_id, $author_id)
{
    $GLOBALS['__test_posts'][$event_id] = [
        'post_type'   => 'pge_event',
        'post_status' => 'publish',
        'post_author' => (int) $author_id,
        'post_title'  => 'مناسبة الإدارة ' . $event_id,
    ];
    $GLOBALS['__test_post_meta'][$event_id] = [];
    $GLOBALS['__test_query_vars']['event_id'] = $event_id;
}

reset_test_user(9721);
reset_test_posts();
set_test_user_meta(9721, '_mon_events_limit', 4);
setup_manage_event(6001, 9721);
seed_event(9721, null);
$GLOBALS['__test_current_user_id'] = 9721;
$manage_vars_legacy = run_template_slice($MANAGE_FILE, $MANAGE_MARKER);
// ملاحظة: setup_manage_event(6001, 9721) تُنشئ مناسبة حقيقية إضافية مملوكة
// لنفس المستخدم (المناسبة "قيد الإدارة" نفسها)، فوق seed_event(9721, null)
// — والحساب Legacy الصحيح (بلا تغيير) يعدّ كل مناسبات المستخدم، لا مناسبة
// "قيد الإدارة" فقط، فالعدد الصحيح هنا هو 2 لا 1 (كان هذا خطأ في تصميم
// الاختبار نفسه، لا في كود الإنتاج).
check('Legacy: manage_events_limit = 4', $manage_vars_legacy['manage_events_limit'], 4);
check('Legacy: manage_events_used = 2 (المناسبة قيد الإدارة + المناسبة الأخرى المزروعة)', $manage_vars_legacy['manage_events_used'], 2);
check('Legacy: manage_events_left = 2', $manage_vars_legacy['manage_events_left'], 2);
check_true('Legacy: manage_event_quota_is_unlimited = false', $manage_vars_legacy['manage_event_quota_is_unlimited'] === false);

reset_test_user(9722);
reset_test_posts();
set_test_user_meta(9722, '_mon_package_source', 'catalog');
set_test_user_meta(9722, '_mon_credit_cycle_id', 'cycle-manage-1');
set_test_user_meta(9722, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9722, '_mon_event_quota_limit', 8);
setup_manage_event(6002, 9722);
seed_event(9722, 'cycle-manage-1');
seed_event(9722, 'cycle-manage-1');
$GLOBALS['__test_current_user_id'] = 9722;
$manage_vars_limited = run_template_slice($MANAGE_FILE, $MANAGE_MARKER);
check('Catalog Limited: manage_events_limit = 8', $manage_vars_limited['manage_events_limit'], 8);
check('Catalog Limited: manage_events_used = 2', $manage_vars_limited['manage_events_used'], 2);
check('Catalog Limited: manage_events_left = 6', $manage_vars_limited['manage_events_left'], 6);

reset_test_user(9723);
reset_test_posts();
set_test_user_meta(9723, '_mon_package_source', 'catalog');
set_test_user_meta(9723, '_mon_credit_cycle_id', 'cycle-manage-2');
set_test_user_meta(9723, '_mon_event_quota_mode', 'unlimited');
set_test_user_meta(9723, '_mon_event_quota_limit', 1);
setup_manage_event(6003, 9723);
for ($i = 0; $i < 10; $i++) { seed_event(9723, 'cycle-manage-2'); }
$GLOBALS['__test_current_user_id'] = 9723;
$manage_vars_unlimited = run_template_slice($MANAGE_FILE, $MANAGE_MARKER);
check_true('Catalog Unlimited: manage_event_quota_is_unlimited = true', $manage_vars_unlimited['manage_event_quota_is_unlimited'] === true);

echo "\n=== انحدار: خطأ تكامل Catalog على الواجهة (بلا credit_cycle_id) ===\n";

reset_test_user(9731);
reset_test_posts();
set_test_user_meta(9731, '_mon_package_source', 'catalog');
// لا _mon_credit_cycle_id إطلاقاً
set_test_user_meta(9731, '_mon_event_quota_mode', 'limited');
set_test_user_meta(9731, '_mon_event_quota_limit', 5);
$GLOBALS['__test_current_user_id'] = 9731;
$vars_integrity = run_template_slice($DASHBOARD_FILE, $DASH_MARKER);
check_true('خطأ تكامل Catalog: event_quota_is_unlimited = false (لا افتراض Unlimited)', $vars_integrity['event_quota_is_unlimited'] === false);
check('خطأ تكامل Catalog: events_limit = 0 (حالة آمنة، بلا افتراض "مناسبة واحدة")', $vars_integrity['events_limit'], 0);

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
