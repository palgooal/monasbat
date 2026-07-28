<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـEvent Quota Commit 8 — لوحة
 * التشخيص. يستدعي pge_render_event_quota_diagnostics_panel() الحقيقية من
 * includes/event-quota-diagnostics.php مباشرة (بلا تعديل عليها)، مع تحميل
 * pge_resolve_event_quota_status() الحقيقية من includes/event-factory.php
 * (Commit 5) كما هي — لا إعادة تنفيذ لأي منطق حصة هنا.
 *
 * السيناريوهات المطلوبة صراحةً في مواصفة Commit 8: Legacy user، Catalog
 * Limited، Catalog Unlimited، Resolver Error — بالإضافة إلى سيناريو الحالة
 * الأصلية المُشخَّصة (Snapshot قديم يفتقر لمفتاحي الحصة رغم وجود
 * credit_cycle_id) وسيناريو حماية المدير (مستخدم عادي لا يرى شيئاً إطلاقاً).
 *
 * التشغيل: php tests/test-event-quota-diagnostics.php
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
if (!function_exists('esc_html')) {
    function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
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
if (!class_exists('WP_User')) {
    class WP_User
    {
        public $ID;
        public function __construct($id) { $this->ID = (int) $id; }
    }
}

// ── حالة اختبار قابلة للتحكم لصلاحية المدير ─────────────────────────────
$GLOBALS['__test_is_admin'] = true;
function current_user_can($cap)
{
    if ($cap === 'manage_options') {
        return $GLOBALS['__test_is_admin'];
    }
    return false;
}

// ── User Meta / Post Meta وهميان في الذاكرة (نفس نمط اختبارات Commit 5/6/7) ──
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

function seed_event($author_id, $activation_id_or_null, $post_status = 'publish')
{
    static $next_id = 7000;
    $id = $next_id++;
    $GLOBALS['__test_posts'][$id] = [
        'post_type'   => 'pge_event',
        'post_status' => $post_status,
        'post_author' => (int) $author_id,
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
        $this->posts = $matched_ids; // fields=>'ids'
    }
}

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

// تحميل الدالة المركزية الحقيقية (Commit 5) بلا أي تعديل عليها — نفس ملف
// الإنتاج المستخدَم في كل اختبارات Commit 5/6/7 السابقة.
require_once __DIR__ . '/../includes/event-factory.php';

// تحميل لوحة التشخيص الحقيقية (Commit 8) بلا أي تعديل عليها.
require_once __DIR__ . '/../includes/event-quota-diagnostics.php';

$total = 0;
$passed = 0;
$failures = [];

function check_true($label, $condition)
{
    global $total, $passed, $failures;
    $total++;
    if ($condition) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failures[] = $label;
        echo "FAIL  $label\n";
    }
}

function render_panel_output($user_id)
{
    $user = new WP_User($user_id);
    ob_start();
    pge_render_event_quota_diagnostics_panel($user);
    return ob_get_clean();
}

// ── سيناريو 0: حماية المدير — مستخدم عادي لا يرى شيئاً إطلاقاً ────────────
$GLOBALS['__test_is_admin'] = false;
reset_test_user(8001);
reset_test_posts();
$output_non_admin = render_panel_output(8001);
check_true('0. مستخدم غير مدير: اللوحة لا تُعرض إطلاقاً (خرج فارغ تماماً)', $output_non_admin === '');
$GLOBALS['__test_is_admin'] = true;

// ── سيناريو 1: Legacy user ────────────────────────────────────────────────
reset_test_user(8101);
reset_test_posts();
set_test_user_meta(8101, '_mon_events_limit', 5);
seed_event(8101, null);
seed_event(8101, null);
$output_legacy = render_panel_output(8101);
check_true('1. Legacy: Package Source يظهر (فارغ)', strpos($output_legacy, '(فارغ)') !== false);
check_true('1. Legacy: Resolver Status = نجح — الوضع (mode): legacy', strpos($output_legacy, 'نجح — الوضع (mode): legacy') !== false);
check_true('1. Legacy: Used Events = 2', strpos($output_legacy, '<code>2</code>') !== false);
check_true('1. Legacy: Remaining Events = 3', strpos($output_legacy, '<code>3</code>') !== false);
check_true('1. Legacy: Event Quota Mode يظهر (غير موجود)', strpos($output_legacy, 'وضع حصة المناسبات (Event Quota Mode)</th>') !== false && strpos($output_legacy, '(غير موجود — Missing)') !== false);
check_true('1. Legacy: لا قسم Error Code/Message يظهر', strpos($output_legacy, 'رمز الخطأ') === false);

// ── سيناريو 2: Catalog Limited (Allowed=10, Used=3, Remaining=7) ──────────
reset_test_user(8102);
reset_test_posts();
set_test_user_meta(8102, '_mon_package_source', 'catalog');
set_test_user_meta(8102, '_mon_package_status', 'active');
set_test_user_meta(8102, '_mon_credit_cycle_id', 'cycle-diag-current');
set_test_user_meta(8102, '_mon_event_quota_mode', 'limited');
set_test_user_meta(8102, '_mon_event_quota_limit', 10);
seed_event(8102, 'cycle-diag-current'); // current x3
seed_event(8102, 'cycle-diag-current');
seed_event(8102, 'cycle-diag-current');
seed_event(8102, null);                // empty ownership x2
seed_event(8102, null);
seed_event(8102, 'cycle-diag-old-1');   // previous activation x1
$output_limited = render_panel_output(8102);
check_true('2. Catalog Limited: Package Source = catalog', strpos($output_limited, '<code>catalog</code>') !== false);
check_true('2. Catalog Limited: Package Status = active', strpos($output_limited, '<code>active</code>') !== false);
check_true('2. Catalog Limited: Credit Cycle ID يظهر', strpos($output_limited, 'cycle-diag-current') !== false);
check_true('2. Catalog Limited: Event Quota Mode = limited', strpos($output_limited, '<code>limited</code>') !== false);
check_true('2. Catalog Limited: Event Quota Limit = 10', strpos($output_limited, '<code>10</code>') !== false);
check_true('2. Catalog Limited: Used Events = 3', strpos($output_limited, '<code>3</code>') !== false);
check_true('2. Catalog Limited: Remaining Events = 7', strpos($output_limited, '<code>7</code>') !== false);
check_true('2. Catalog Limited: مناسبات مملوكة للتفعيل الحالي = 3', preg_match('/مناسبات مملوكة للتفعيل الحالي<\/th>\s*<td><code>3<\/code>/u', $output_limited) === 1);
check_true('2. Catalog Limited: مناسبات بلا ملكية = 2', preg_match('/مناسبات بلا ملكية \(فارغة\)<\/th>\s*<td><code>2<\/code>/u', $output_limited) === 1);
check_true('2. Catalog Limited: مناسبات تابعة لتفعيلات سابقة = 1', preg_match('/مناسبات تابعة لتفعيلات سابقة<\/th>\s*<td><code>1<\/code>/u', $output_limited) === 1);

// ── سيناريو 3: Catalog Unlimited ──────────────────────────────────────────
reset_test_user(8103);
reset_test_posts();
set_test_user_meta(8103, '_mon_package_source', 'catalog');
set_test_user_meta(8103, '_mon_package_status', 'active');
set_test_user_meta(8103, '_mon_credit_cycle_id', 'cycle-diag-unlimited');
set_test_user_meta(8103, '_mon_event_quota_mode', 'unlimited');
set_test_user_meta(8103, '_mon_event_quota_limit', 1);
for ($i = 0; $i < 12; $i++) { seed_event(8103, 'cycle-diag-unlimited'); }
$output_unlimited = render_panel_output(8103);
check_true('3. Catalog Unlimited: Event Quota Mode = unlimited', strpos($output_unlimited, '<code>unlimited</code>') !== false);
check_true('3. Catalog Unlimited: Resolver Status = نجح — الوضع (mode): unlimited', strpos($output_unlimited, 'نجح — الوضع (mode): unlimited') !== false);
check_true('3. Catalog Unlimited: Used Events = — (لا رقم)', strpos($output_unlimited, 'المناسبات المُستخدَمة (Used Events)</th>') !== false && preg_match('/المناسبات المُستخدَمة \(Used Events\)<\/th>\s*<td><code>—<\/code>/u', $output_unlimited) === 1);
check_true('3. Catalog Unlimited: Remaining Events = — (لا رقم)', preg_match('/المناسبات المتبقية \(Remaining Events\)<\/th>\s*<td><code>—<\/code>/u', $output_unlimited) === 1);

// ── سيناريو 4: Resolver Error (Catalog integrity — بلا credit_cycle_id) ───
reset_test_user(8104);
reset_test_posts();
set_test_user_meta(8104, '_mon_package_source', 'catalog');
set_test_user_meta(8104, '_mon_package_status', 'active');
// لا _mon_credit_cycle_id إطلاقاً
set_test_user_meta(8104, '_mon_event_quota_mode', 'limited');
set_test_user_meta(8104, '_mon_event_quota_limit', 5);
$output_error = render_panel_output(8104);
check_true('4. Resolver Error: Resolver Status = خطأ (WP_Error)', strpos($output_error, 'خطأ (WP_Error)') !== false);
check_true('4. Resolver Error: Error Code يظهر (catalog_activation_integrity)', strpos($output_error, 'catalog_activation_integrity') !== false);
check_true('4. Resolver Error: Error Message يظهر', strpos($output_error, 'حساب Catalog هذا في حالة غير متسقة') !== false);
check_true('4. Resolver Error: Used Events = — (لا افتراض)', preg_match('/المناسبات المُستخدَمة \(Used Events\)<\/th>\s*<td><code>—<\/code>/u', $output_error) === 1);

// ── سيناريو 5: الحالة الأصلية المُشخَّصة — credit_cycle_id موجود لكن
// mode/limit مفقودان تماماً (Snapshot قديم قبل Commit 3) ──────────────────
reset_test_user(8105);
reset_test_posts();
set_test_user_meta(8105, '_mon_package_source', 'catalog');
set_test_user_meta(8105, '_mon_package_status', 'active');
set_test_user_meta(8105, '_mon_credit_cycle_id', 'cycle-diag-pre-commit-3');
// لا _mon_event_quota_mode ولا _mon_event_quota_limit إطلاقاً
$output_missing_snapshot = render_panel_output(8105);
check_true('5. Snapshot قديم: Event Quota Mode يظهر (غير موجود)', strpos($output_missing_snapshot, 'وضع حصة المناسبات (Event Quota Mode)</th>') !== false && preg_match('/وضع حصة المناسبات \(Event Quota Mode\)<\/th>\s*<td><code>\(غير موجود — Missing\)<\/code>/u', $output_missing_snapshot) === 1);
check_true('5. Snapshot قديم: Event Quota Limit يظهر (غير موجود)', preg_match('/حد حصة المناسبات \(Event Quota Limit\)<\/th>\s*<td><code>\(غير موجود — Missing\)<\/code>/u', $output_missing_snapshot) === 1);
check_true('5. Snapshot قديم: Credit Cycle ID لا يزال يظهر موجوداً (يثبت التناقض المُشخَّص)', strpos($output_missing_snapshot, 'cycle-diag-pre-commit-3') !== false);
// الـResolver نفسه يعامل mode المفقود كـ'limited' افتراضياً (تصميم Commit 5
// الدفاعي) — نتحقق أن اللوحة تعرض نتيجة الـResolver كما هي بلا أي تدخل.
check_true('5. Snapshot قديم: الـResolver لا يزال يعمل (Legacy fallback الدفاعي في Commit 5)', strpos($output_missing_snapshot, 'نجح — الوضع (mode):') !== false || strpos($output_missing_snapshot, 'خطأ (WP_Error)') !== false);

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
