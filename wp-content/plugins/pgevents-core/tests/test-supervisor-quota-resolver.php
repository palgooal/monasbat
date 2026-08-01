<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس أسلوب
 * tests/test-invitation-credit-accumulation.php) لـEntry Check-in
 * Supervisors، Phase 1 ("Supervisor Entitlement Foundation" RFC) —
 * pge_resolve_supervisor_quota_status()، بالإضافة إلى إثبات تنفيذي فعلي بأن
 * Requirement 4 (Snapshot) محقَّق فعلاً عبر بنية Phase 4 القائمة مسبقاً
 * (build_tier_features_snapshot()) بلا أي تعديل جديد على
 * Mon_Events_Users::activate_catalog_tier() في هذا الـCommit.
 *
 * السيناريوهات المطلوبة صراحةً (Requirement 9):
 *   - Registry: مغطاة في tests/test-supervisor-schema.php (لا تكرار هنا).
 *   - Resolver: legacy/catalog، snapshot مفقود (خطأ تكامل)، مناسبة غير
 *     موجودة، مالك غير صالح.
 *   - Snapshot: activate_catalog_tier() الحقيقية تكتب admin_supervisor_limit
 *     ضمن _mon_package_features تلقائياً (بلا أي تعديل جديد عليها).
 *   - حسابات الحصة: allowed/used/remaining لعدة قيم Tier وعدة صفوف مشرفين.
 *   - السلوك لكل مناسبة (Per-Event): مناسبتان لنفس المالك بحصتين مستقلتين
 *     تماماً في العدّ (used مستقل لكل event_id، بخلاف Event Quota الذي يُعَدّ
 *     على مستوى دورة التفعيل الكاملة).
 *   - انحدار Event Quota: pge_resolve_event_quota_status() يعمل بلا أي تأثير.
 *   - انحدار Invitation Credits: Mon_Events_Users::activate_catalog_tier()
 *     لا يزال يراكم رصيد الدعوات كما في Commit 9 بلا أي تغيير.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج. لا تعديل على أي كود إنتاج
 * تم إجراؤه لأجل هذا الملف.
 *
 * التشغيل: php tests/test-supervisor-quota-resolver.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (نفس الحد الأدنى المستخدَم في
// test-invitation-credit-accumulation.php) ──────────────────────────────────

define('ABSPATH', __DIR__ . '/');

function add_action(...$args) { /* no-op */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) {
    function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
}
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
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false) {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags($text);
        if ($remove_breaks) {
            $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
        }
        return trim($text);
    }
}
if (!function_exists('wp_generate_uuid4')) {
    $GLOBALS['__test_uuid_counter'] = 0;
    function wp_generate_uuid4() {
        $GLOBALS['__test_uuid_counter']++;
        return 'test-uuid-' . $GLOBALS['__test_uuid_counter'];
    }
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

// ── User Meta + Posts وهميان في الذاكرة (نفس نمط test-event-quota-snapshot.php،
// بالإضافة إلى get_post() المطلوبة حصراً لـpge_resolve_supervisor_quota_status()) ──

$GLOBALS['__test_user_meta'] = [];
$GLOBALS['__test_users_by_id'] = [];
$GLOBALS['__test_posts'] = [];

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}

function update_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
    return true;
}

function delete_user_meta($user_id, $key)
{
    unset($GLOBALS['__test_user_meta'][$user_id][$key]);
    return true;
}

function metadata_exists($type, $object_id, $meta_key)
{
    $value = $GLOBALS['__test_user_meta'][$object_id][$meta_key] ?? '';
    return $value !== '';
}

function set_test_user_meta($user_id, $key, $value)
{
    $GLOBALS['__test_user_meta'][$user_id][$key] = $value;
}

function reset_test_user($user_id)
{
    $GLOBALS['__test_user_meta'][$user_id] = [];
    $GLOBALS['__test_users_by_id'][$user_id] = true;
}

function get_user_by($field, $value)
{
    if ($field === 'id') {
        return !empty($GLOBALS['__test_users_by_id'][$value]) ? (object) ['ID' => (int) $value] : false;
    }
    return false;
}

/**
 * set_test_event() — تسجيل "مناسبة" وهمية بسيطة (post_type + post_author
 * فقط، وهما الحقلان الوحيدان اللذان يقرأهما pge_resolve_supervisor_quota_status()
 * عبر get_post()). لا حاجة لأي حقل WP_Post آخر.
 */
function set_test_event($event_id, $author_id, $post_type = 'pge_event')
{
    $GLOBALS['__test_posts'][$event_id] = (object) [
        'ID'          => $event_id,
        'post_type'   => $post_type,
        'post_author' => $author_id,
    ];
}

function get_post($event_id)
{
    return $GLOBALS['__test_posts'][$event_id] ?? null;
}

// ── Fake $wpdb — يخدم mon_plans/mon_plan_tiers (PGE_Catalog)،
// mon_tier_features (PGE_Tier_Features)، ومنسوخ حرفياً عن
// Fake_Wpdb_Credit_Accumulation في test-invitation-credit-accumulation.php،
// مع إضافة دعم mon_event_supervisors (insert()/get_var() فقط — الشكل الوحيد
// المطلوب من pge_count_active_event_supervisors()). ──────────────────────────

class Fake_Wpdb_Supervisor_Resolver
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $plans = [];
    public $tiers = [];
    public $tier_features = [];
    public $supervisors = [];

    private $plans_next_id = 1;
    private $tiers_next_id = 1;
    private $tier_features_next_id = 1;
    private $supervisors_next_id = 1;

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

    private function which_table($sql_or_table)
    {
        if (strpos($sql_or_table, $this->prefix . 'mon_tier_features') !== false) {
            return 'tier_features';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plan_tiers') !== false) {
            return 'tiers';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plans') !== false) {
            return 'plans';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_event_supervisors') !== false) {
            return 'supervisors';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $rows = $this->get_results($sql, $output);
        if ($rows === null) {
            return null;
        }
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which === null || $which === 'supervisors') {
            return [];
        }

        $rows = array_values(
            $which === 'tiers' ? $this->tiers : ($which === 'plans' ? $this->plans : $this->tier_features)
        );

        if (preg_match('/WHERE\s+(.+)$/is', $sql, $m)) {
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

    /**
     * get_var() — يخدم حصراً الشكل الوحيد الذي تُصدره
     * pge_count_active_event_supervisors(): SELECT COUNT(*) FROM
     * {prefix}mon_event_supervisors WHERE event_id = N AND status NOT IN
     * ('a','b'). ليس محرّك SQL عاماً.
     */
    public function get_var($sql)
    {
        $table = $this->prefix . 'mon_event_supervisors';
        $pattern = '/FROM\s+' . preg_quote($table, '/') . '\s+WHERE\s+event_id\s*=\s*(\d+)\s+AND\s+status\s+NOT\s+IN\s*\(([^)]*)\)/i';

        if (preg_match($pattern, $sql, $m)) {
            $event_id = (int) $m[1];
            $excluded = array_map(function ($v) {
                return trim($v, " '");
            }, explode(',', $m[2]));

            $count = 0;
            foreach ($this->supervisors as $row) {
                if ((int) $row['event_id'] === $event_id && !in_array((string) $row['status'], $excluded, true)) {
                    $count++;
                }
            }
            return (string) $count;
        }

        return null;
    }

    public function insert($table, $data, $format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }
        if ($which === 'tiers') {
            $id = $this->tiers_next_id++;
            $this->tiers[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'plans') {
            $id = $this->plans_next_id++;
            $this->plans[$id] = array_merge(['id' => $id], $data);
        } elseif ($which === 'supervisors') {
            $id = $this->supervisors_next_id++;
            $this->supervisors[$id] = array_merge(['id' => $id], $data);
        } else {
            $id = $this->tier_features_next_id++;
            $this->tier_features[$id] = array_merge(['id' => $id], $data);
        }
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }
        $id = $where['id'] ?? null;
        if ($id === null) {
            return false;
        }
        $store = $which === 'tiers' ? 'tiers' : ($which === 'plans' ? 'plans' : ($which === 'supervisors' ? 'supervisors' : 'tier_features'));
        if (!isset($this->{$store}[$id])) {
            return 0;
        }
        foreach ($data as $k => $v) {
            $this->{$store}[$id][$k] = $v;
        }
        return 1;
    }

    public function seed_plan($id, array $row)
    {
        $this->plans[$id] = array_merge(['id' => $id], $row);
        if ($id >= $this->plans_next_id) {
            $this->plans_next_id = $id + 1;
        }
    }

    /**
     * seed_supervisor() — إدراج مباشر لصف إسناد مشرف، بلا المرور عبر أي AJAX
     * أو منطق دعوة فعلي (غير موجود في هذه المرحلة أصلاً) — محاكاة "حالة بيانات
     * موجودة مسبقاً" فقط، لاختبار عدّ pge_count_active_event_supervisors().
     */
    public function seed_supervisor($event_id, $status, $phone = null)
    {
        $id = $this->supervisors_next_id++;
        $this->supervisors[$id] = [
            'id'               => $id,
            'event_id'         => $event_id,
            'status'           => $status,
            'supervisor_phone' => $phone ?? ('05' . $id),
        ];
        return $id;
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Supervisor_Resolver();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── تحميل الملفات الحقيقية (بلا أي تعديل عليها) ─────────────────────────────

require_once __DIR__ . '/../includes/class-pge-catalog.php';
require_once __DIR__ . '/../includes/class-pge-feature-registry.php';
require_once __DIR__ . '/../includes/class-pge-tier-features.php';
require_once __DIR__ . '/../includes/feature-resolver.php';
require_once __DIR__ . '/../includes/class-mon-events-users.php';
require_once __DIR__ . '/../includes/supervisor-quota-resolver.php';

// ── WP_Query الوهمية اللازمة فقط لتحميل event-factory.php (لا حاجة لأي منطق
// حقيقي منها في هذا الملف — pge_resolve_event_quota_status() هنا يُستدعى فقط
// لسيناريو الانحدار، ولا يستدعي WP_Query إلا في الفرع Legacy الذي لا نستخدمه). ──

if (!class_exists('WP_Query')) {
    class WP_Query
    {
        public $found_posts = 0;
        public function __construct($args = []) { $this->found_posts = 0; }
    }
}
if (!function_exists('pge_get_user_plan_limits_for_events')) {
    function pge_get_user_plan_limits_for_events($user_id) { return ['events_count' => 0]; }
}

require_once __DIR__ . '/../includes/event-factory.php';

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

function check_wp_error($label, $result, $expected_code)
{
    check_true("$label (WP_Error)", $result instanceof WP_Error);
    if ($result instanceof WP_Error) {
        check("$label (code=$expected_code)", $result->get_error_code(), $expected_code);
    }
}

$wpdb->seed_plan(1, [
    'plan_key'  => 'basic_plan',
    'name'      => 'باقة أساسية',
    'plan_type' => 'personal',
    'status'    => 'active',
]);

function make_test_tier($tier_key, $sort_order, array $extra = [])
{
    return PGE_Catalog::create_tier(array_merge([
        'plan_id'          => 1,
        'tier_key'         => $tier_key,
        'name'             => 'مستوى اختبار ' . $tier_key,
        'price'            => 100,
        'currency'         => 'SAR',
        'salla_product_id' => null,
        'status'           => 'active',
        'sort_order'       => $sort_order,
    ], $extra));
}

// ============================================================================
// السيناريو 1: Snapshot — إثبات تنفيذي أن admin_supervisor_limit يُكتَب فعلاً
// ضمن _mon_package_features عند activate_catalog_tier() الحقيقية، بلا أي
// تعديل جديد على تلك الدالة (Requirement 4 محقَّق ببنية Phase 4 القائمة).
// ============================================================================
echo "=== السيناريو 1: Snapshot (admin_supervisor_limit ضمن _mon_package_features) ===\n";

reset_test_user(9501);
$tier1 = make_test_tier('sup1_no_tier_value', 1);
// لا قيمة Tier Feature مضبوطة صراحةً لـadmin_supervisor_limit هنا — يجب أن
// تُستخدَم قيمة Registry الافتراضية 'TBD' المُفسَّرة (int) = 0.
$result_1 = Mon_Events_Users::activate_catalog_tier(9501, 1, $tier1['id'], 'SUP-ORDER-1');
check_true('1. activate_catalog_tier() نجح', $result_1 === true);

$snapshot_1 = get_user_meta(9501, '_mon_package_features', true);
check_true('1. Snapshot الميزات أُنشئ كمصفوفة', is_array($snapshot_1));
check_true("1. مفتاح admin_supervisor_limit موجود ضمن Snapshot تلقائياً (بلا أي تعديل جديد على activate_catalog_tier)", array_key_exists('admin_supervisor_limit', $snapshot_1));
check("1. القيمة الافتراضية (TBD) تُفسَّر (int) = 0 عند غياب قيمة Tier صريحة", $snapshot_1['admin_supervisor_limit'] ?? null, 0);

reset_test_user(9502);
$tier2 = make_test_tier('sup1_with_tier_value', 2);
PGE_Tier_Features::set_tier_feature_value($tier2['id'], 'admin_supervisor_limit', '3');
$result_2 = Mon_Events_Users::activate_catalog_tier(9502, 1, $tier2['id'], 'SUP-ORDER-2');
check_true('2. activate_catalog_tier() نجح (Tier بقيمة مشرفين صريحة = 3)', $result_2 === true);

$snapshot_2 = get_user_meta(9502, '_mon_package_features', true);
check("2. Snapshot يعكس قيمة Tier الصريحة (int) 3، لا TBD", $snapshot_2['admin_supervisor_limit'] ?? null, 3);

// ============================================================================
// السيناريو 2: Resolver — مستخدم Legacy (بلا Snapshot إطلاقاً)
// ============================================================================
echo "\n=== السيناريو 2: Resolver — Legacy ===\n";

reset_test_user(9503); // بلا _mon_package_source إطلاقاً = Legacy فعلياً
set_test_event(701, 9503);

$legacy_result = pge_resolve_supervisor_quota_status(701);
check_true('3. Legacy: النتيجة ليست WP_Error', !($legacy_result instanceof WP_Error));
check("3. Legacy: mode = legacy", $legacy_result['mode'] ?? null, 'legacy');
check("3. Legacy: source = legacy_unsupported", $legacy_result['source'] ?? null, 'legacy_unsupported');
check("3. Legacy: allowed = 0 (لا مصدر بيانات حقيقي لعدد مشرفين Legacy)", $legacy_result['allowed'] ?? null, 0);
check("3. Legacy: remaining = 0 دائماً", $legacy_result['remaining'] ?? null, 0);

// ============================================================================
// السيناريو 3: Resolver — Catalog بـSnapshot مفقود (خطأ تكامل صريح)
// ============================================================================
echo "\n=== السيناريو 3: Resolver — Catalog Snapshot مفقود (خطأ تكامل) ===\n";

reset_test_user(9504);
set_test_user_meta(9504, '_mon_package_source', 'catalog');
// لا _mon_package_features إطلاقاً — يحاكي تفعيلاً سابقاً على Phase 4 نفسها.
set_test_event(702, 9504);

$missing_snapshot_result = pge_resolve_supervisor_quota_status(702);
check_wp_error('4. Catalog بلا Snapshot ميزات إطلاقاً', $missing_snapshot_result, 'supervisor_snapshot_missing');

reset_test_user(9505);
set_test_user_meta(9505, '_mon_package_source', 'catalog');
set_test_user_meta(9505, '_mon_package_features', ['google_maps' => true]); // بلا مفتاح admin_supervisor_limit
set_test_event(703, 9505);

$missing_key_result = pge_resolve_supervisor_quota_status(703);
check_wp_error('5. Catalog مع Snapshot موجود لكن بلا مفتاح admin_supervisor_limit', $missing_key_result, 'supervisor_snapshot_missing');

// ============================================================================
// السيناريو 4: Resolver — Catalog مع Snapshot صحيح، حسابات الحصة والاستخدام
// ============================================================================
echo "\n=== السيناريو 4: Resolver — Catalog Snapshot صحيح (حسابات الحصة) ===\n";

reset_test_user(9506);
$tier4 = make_test_tier('sup4_limit3', 4);
PGE_Tier_Features::set_tier_feature_value($tier4['id'], 'admin_supervisor_limit', '3');
Mon_Events_Users::activate_catalog_tier(9506, 1, $tier4['id'], 'SUP-ORDER-4');
set_test_event(704, 9506);

$r4a = pge_resolve_supervisor_quota_status(704);
check("6. allowed = 3، used = 0 (لا صفوف إسناد بعد)", [$r4a['allowed'] ?? null, $r4a['used'] ?? null], [3, 0]);
check("6. remaining = 3", $r4a['remaining'] ?? null, 3);
check("6. mode = limited، source = catalog_snapshot", [$r4a['mode'] ?? null, $r4a['source'] ?? null], ['limited', 'catalog_snapshot']);

$wpdb->seed_supervisor(704, 'active');
$wpdb->seed_supervisor(704, 'invited');
$r4b = pge_resolve_supervisor_quota_status(704);
check("7. بعد إضافة صفَّين فعّالين: used = 2، remaining = 1", [$r4b['used'] ?? null, $r4b['remaining'] ?? null], [2, 1]);

$wpdb->seed_supervisor(704, 'revoked');
$wpdb->seed_supervisor(704, 'expired');
$r4c = pge_resolve_supervisor_quota_status(704);
check_true("8. صفوف revoked/expired لا تُحتسَب ضمن used (يبقى 2)", ($r4c['used'] ?? null) === 2);

$wpdb->seed_supervisor(704, 'pending');
$r4d = pge_resolve_supervisor_quota_status(704);
check("9. بعد إضافة صف ثالث فعّال (pending): used = 3، remaining = 0 (الحصة مكتملة تماماً)", [$r4d['used'] ?? null, $r4d['remaining'] ?? null], [3, 0]);

$wpdb->seed_supervisor(704, 'active');
$r4e = pge_resolve_supervisor_quota_status(704);
check("10. تجاوز الحصة (used=4 > allowed=3): remaining يبقى 0 (clamp، لا سالب)", $r4e['remaining'] ?? null, 0);

// ============================================================================
// السيناريو 5: قرار العمل النهائي — الحصة لكل مناسبة (PER EVENT)، لا لكل مستخدم
// ============================================================================
echo "\n=== السيناريو 5: Per-Event (مناسبتان لنفس المالك، عدّ مستقل تماماً) ===\n";

set_test_event(705, 9506); // نفس المالك 9506 (نفس Snapshot: allowed=3)، مناسبة مختلفة
$r5a = pge_resolve_supervisor_quota_status(705);
check("11. مناسبة أخرى لنفس المالك: allowed = 3 (نفس Snapshot المالك)، لكن used = 0 (عدّ مستقل تماماً عن المناسبة 704)", [$r5a['allowed'] ?? null, $r5a['used'] ?? null], [3, 0]);

$wpdb->seed_supervisor(705, 'active');
$r5b = pge_resolve_supervisor_quota_status(705);
check_true("12. إضافة مشرف للمناسبة 705 لا يغيّر عدّ المناسبة 704 إطلاقاً", pge_resolve_supervisor_quota_status(704)['used'] === 4);
check("12. المناسبة 705 نفسها: used = 1 الآن", $r5b['used'] ?? null, 1);

// ============================================================================
// السيناريو 6: حالات الخطأ الأساسية (event_id غير صالح / مناسبة غير موجودة)
// ============================================================================
echo "\n=== السيناريو 6: حالات الخطأ الأساسية ===\n";

check_wp_error('13. event_id = 0 غير صالح', pge_resolve_supervisor_quota_status(0), 'invalid_event_id');
check_wp_error('14. event_id سالب غير صالح', pge_resolve_supervisor_quota_status(-5), 'invalid_event_id');
check_wp_error('15. مناسبة غير موجودة إطلاقاً', pge_resolve_supervisor_quota_status(999999), 'event_not_found');

set_test_event(706, 9506, 'post'); // post_type مختلف — ليست pge_event
check_wp_error('16. معرّف يخصّ post_type مختلف (ليست pge_event)', pge_resolve_supervisor_quota_status(706), 'event_not_found');

// ============================================================================
// السيناريو 7: انحدار Event Quota — pge_resolve_event_quota_status() بلا تأثير
// ============================================================================
echo "\n=== السيناريو 7: انحدار Event Quota ===\n";

reset_test_user(9507);
$tier7 = make_test_tier('sup7_event_quota_regression', 7, [
    'event_quota_mode'  => 'limited',
    'event_quota_limit' => 5,
]);
Mon_Events_Users::activate_catalog_tier(9507, 1, $tier7['id'], 'SUP-ORDER-7');

$event_quota_result = pge_resolve_event_quota_status(9507);
check_true('17. pge_resolve_event_quota_status() لا يزال يعمل بلا أي تأثير من كود Phase 1', !($event_quota_result instanceof WP_Error));
check("17. mode = limited، allowed = 5 (بلا أي تغيير)", [$event_quota_result['mode'] ?? null, $event_quota_result['allowed'] ?? null], ['limited', 5]);

// ============================================================================
// السيناريو 8: انحدار Invitation Credits — التراكم عبر التجديد (Commit 9) بلا تأثير
// ============================================================================
echo "\n=== السيناريو 8: انحدار Invitation Credits (تراكم Commit 9) ===\n";

reset_test_user(9508);
$tier8 = make_test_tier('sup8_invitation_regression', 8, [
    'invitation_credit_limit' => 50,
]);
Mon_Events_Users::activate_catalog_tier(9508, 1, $tier8['id'], 'SUP-ORDER-8A');
check('18. أول تفعيل: _mon_invitation_credit_total = 50 (بلا أي تأثير من Phase 1)', get_user_meta(9508, '_mon_invitation_credit_total', true), 50);

set_test_user_meta(9508, '_mon_invitation_credit_used', 20);
Mon_Events_Users::activate_catalog_tier(9508, 1, $tier8['id'], 'SUP-ORDER-8B');
check('19. تجديد بعد استخدام جزئي: Total الجديد = 80 (30 متبقٍ + 50 جديد، بلا أي تأثير من Phase 1)', get_user_meta(9508, '_mon_invitation_credit_total', true), 80);

// ============================================================================
// السيناريو 9: تصحيح معماري — Append-Only History (لا قيد UNIQUE بعد الآن)
// ============================================================================
// إثبات تنفيذي للمشكلة المحظورة المذكورة صراحة: دعوة هاتف ← قبول ← إلغاء
// لاحقاً (revoked) ← دعوة نفس الهاتف لنفس المناسبة مرة أخرى. القيد UNIQUE
// (event_id, supervisor_phone) القديم كان سيرفض هذا الإدراج الثاني تماماً؛
// الفهرس العادي الجديد (event_phone) يسمح به، والصف التاريخي يبقى بلا أي
// تعديل عليه (Append-Only حقيقي، لا Update لمحاكاة دعوة جديدة).
echo "\n=== السيناريو 9: Append-Only History (دعوة → قبول → إلغاء → دعوة مجدداً) ===\n";

reset_test_user(9509);
$tier9 = make_test_tier('sup9_append_only', 9);
PGE_Tier_Features::set_tier_feature_value($tier9['id'], 'admin_supervisor_limit', '5');
Mon_Events_Users::activate_catalog_tier(9509, 1, $tier9['id'], 'SUP-ORDER-9');
set_test_event(707, 9509);

$phone_9 = '0512345678';

// 1) دعوة (invited)
$row_a_id = $wpdb->seed_supervisor(707, 'invited', $phone_9);
check_true('20. الإسناد الأول (invited) أُنشئ بنجاح', $row_a_id > 0);

// 2) قبول (invited → active)
$table_supervisors = $wpdb->prefix . 'mon_event_supervisors';
$updated_accept = $wpdb->update($table_supervisors, ['status' => 'active'], ['id' => $row_a_id]);
check_true('21. القبول (active) نجح على نفس الصف A', $updated_accept === 1);
check('21. الصف A أصبح active فعلاً', $wpdb->supervisors[$row_a_id]['status'] ?? null, 'active');

// 3) إلغاء لاحق (active → revoked)
$updated_revoke = $wpdb->update($table_supervisors, ['status' => 'revoked'], ['id' => $row_a_id]);
check_true('22. الإلغاء (revoked) نجح على نفس الصف A', $updated_revoke === 1);
check('22. الصف A أصبح revoked فعلاً', $wpdb->supervisors[$row_a_id]['status'] ?? null, 'revoked');

// 4) دعوة نفس الهاتف لنفس المناسبة مرة أخرى — يجب أن تنجح الآن (لا قيد UNIQUE)
$row_b_id = $wpdb->seed_supervisor(707, 'invited', $phone_9);
check_true('23. الدعوة الثانية لنفس (event_id, supervisor_phone) بعد الإلغاء نجحت (لم تُرفَض بقيد فريد)', $row_b_id > 0);
check_true('23ب. الصف B صف جديد فعلاً، لا نفس معرّف الصف A', $row_b_id !== $row_a_id);

// 5) الصف التاريخي (A) لم يُعدَّل إطلاقاً بسبب الدعوة الثانية — لا يزال revoked
check('24. الصف التاريخي A لا يزال موجوداً وبحالته revoked (Append-Only — لم يُلمَس)', $wpdb->supervisors[$row_a_id]['status'] ?? null, 'revoked');
check('24. هاتف الصف A لم يتغيّر', $wpdb->supervisors[$row_a_id]['supervisor_phone'] ?? null, $phone_9);
check('24ب. الصف الجديد B بحالة invited', $wpdb->supervisors[$row_b_id]['status'] ?? null, 'invited');

// 6) كلا الصفّين (التاريخي + الجديد) موجودان معاً فعلياً — لا استبدال
$rows_for_phone = array_filter($wpdb->supervisors, function ($r) use ($phone_9) {
    return (int) $r['event_id'] === 707 && $r['supervisor_phone'] === $phone_9;
});
check('25. عدد صفوف (event=707, phone=' . $phone_9 . ') = 2 بالضبط (تاريخي + جديد، لا استبدال)', count($rows_for_phone), 2);

// 7) الـResolver يعدّ الصف النشط الجديد فقط، ويتجاهل الصف التاريخي المُلغى
$r9 = pge_resolve_supervisor_quota_status(707);
check('26. pge_resolve_supervisor_quota_status(): used = 1 (الصف الجديد invited فقط، revoked مُستبعَد)', $r9['used'] ?? null, 1);
check('26. remaining = 4 (allowed=5 - used=1)', $r9['remaining'] ?? null, 4);

// ============================================================================
// السيناريو 10: القاعدة التجارية المستقبلية (توثيق فقط — لا تنفيذ في Phase 1)
// ============================================================================
// "لا يجوز وجود أكثر من إسناد نشط واحد لنفس (event_id, phone)" — قاعدة
// تُطبَّق تطبيقياً في Phase 2 فقط (منطق إنشاء الدعوة + قفل GET_LOCK)، وليست
// موجودة في Phase 1 (لا دالة إنشاء دعوة إطلاقاً بعد). هذا الاختبار يوثّق
// بصدق الحالة الراهنة: قاعدة البيانات وحدها **لا تمنع** اليوم إدراج صفّين
// نشطين معاً لنفس (event_id, phone) — وهذا متوقَّع ومقصود في Phase 1 (لا قيد
// DB يفرض هذا التفرّد بتصميم صريح)، وليس عيباً. Phase 2 هو من يجب أن يضيف
// الفحص التطبيقي (SELECT فحص الحالات النشطة أولاً ثم INSERT) محمياً بقفل.
echo "\n=== السيناريو 10: توثيق — لا إنفاذ DB لتفرّد الإسناد النشط (مؤجَّل لـPhase 2) ===\n";

set_test_event(708, 9509); // نفس المالك (allowed=5)، مناسبة أخرى مستقلة تماماً
$phone_10 = '0587654321';

$dup_active_1 = $wpdb->seed_supervisor(708, 'active', $phone_10);
$dup_active_2 = $wpdb->seed_supervisor(708, 'active', $phone_10); // نفس الهاتف، نفس المناسبة، كلاهما active معاً

check_true('27. إدراج إسنادين "نشطين" معاً لنفس (event_id, phone) ينجح اليوم على مستوى DB (لا إنفاذ DB — متوقَّع في Phase 1)', $dup_active_1 > 0 && $dup_active_2 > 0 && $dup_active_1 !== $dup_active_2);

$r10 = pge_resolve_supervisor_quota_status(708);
check_true('27ب. الـResolver (قراءة إحصائية بحتة) يعدّهما كلاهما ضمن used = 2 — لأنه لا يفرض القاعدة التجارية، فقط يقرأ الواقع', ($r10['used'] ?? null) === 2);

// ملاحظة توثيقية صريحة: هذا السلوك (السماح بإسنادين نشطين معاً) يجب أن يُغلَق
// حصراً عبر منطق إنشاء الدعوة في Phase 2 (غير موجود بعد)، محمياً بقفل
// GET_LOCK (أو ما يعادله) مشتق من (event_id, supervisor_phone المُطبَّع) —
// نفس فلسفة PGE_Invitation_Credit_Ledger::claim_for_delivery() وقفل
// pge_handle_event_creation() تماماً. لا تنفيذ لهذا القفل في هذا الـCommit.
echo "ملاحظة: منع الإسنادات النشطة المزدوجة مؤجَّل تطبيقياً لـPhase 2 (موثَّق، غير مُنفَّذ هنا).\n";

// ── ملخص ────────────────────────────────────────────────────────────────

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
