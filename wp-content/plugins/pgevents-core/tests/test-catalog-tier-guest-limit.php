<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لدعم guest_limit الكامل في نظام
 * Catalog: PGE_Catalog::create_tier()/update_tier()، Snapshot عند التفعيل
 * (Mon_Events_Users::activate_catalog_tier())، وقراءة الإنفاذ عبر
 * pge_get_catalog_user_plan_limits_for_events() في event-factory.php.
 *
 * نفس البنية والفلسفة المستخدمة في tests/test-catalog-tier-events-count.php
 * (Fake_Wpdb في الذاكرة يحاكي فقط أشكال الاستعلامات الفعلية، بلا خادم MySQL
 * حقيقي) — الملفات الإنتاجية الحقيقية (class-pge-catalog.php،
 * class-mon-events-users.php، event-factory.php) تُحمَّل وتُنفَّذ دون أي
 * تعديل عليها.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل:
 *   php tests/test-catalog-tier-guest-limit.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب لتحميل الملفات الحقيقية) ─────

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
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($v) { return trim(strip_tags((string) $v)); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url, $protocols = null) { return $url; }
}
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }
}

$GLOBALS['__test_user_meta']      = [];
$GLOBALS['__test_users_by_id']    = [];
$GLOBALS['__test_users_by_email'] = [];
$GLOBALS['__test_options']        = [];

function get_option($name, $default = false)
{
    return $GLOBALS['__test_options'][$name] ?? $default;
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;
        public function __construct($data = [], $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

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

function set_test_user_id($user_id)
{
    $GLOBALS['__test_users_by_id'][$user_id] = true;
}

function reset_test_user($user_id)
{
    $GLOBALS['__test_user_meta'][$user_id] = [];
}

function get_user_by($field, $value)
{
    if ($field === 'id') {
        return !empty($GLOBALS['__test_users_by_id'][$value]) ? (object) ['ID' => (int) $value] : false;
    }
    if ($field === 'email') {
        $id = $GLOBALS['__test_users_by_email'][$value] ?? null;
        return $id === null ? false : (object) ['ID' => (int) $id];
    }
    return false;
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

// ── Fake $wpdb — نفس الكلاس المستخدم في test-catalog-tier-events-count.php ─

class Fake_Wpdb
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $plans = [];
    public $tiers = [];

    public $show_columns_override = null;

    private $plans_next_id = 1;
    private $tiers_next_id = 1;

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $val = $args[$i] ?? '';
            $i++;
            if ($m[0] === '%d') {
                return (string) (int) $val;
            }
            return "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    public function get_charset_collate()
    {
        return '';
    }

    private function which_table($sql_or_table)
    {
        if (strpos($sql_or_table, $this->prefix . 'mon_plan_tiers') !== false) {
            return 'tiers';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_plans') !== false) {
            return 'plans';
        }
        return null;
    }

    public function get_row($sql, $output = null)
    {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_results($sql, $output = null)
    {
        $which = $this->which_table($sql);
        if ($which === null) {
            return [];
        }
        $rows = array_values($which === 'tiers' ? $this->tiers : $this->plans);

        if (stripos($sql, 'SHOW COLUMNS FROM') === 0) {
            $columns = $this->show_columns_override ?? [
                'id', 'plan_id', 'tier_key', 'name', 'guest_limit', 'events_count',
                'host_photos_limit', 'wa_messages_limit',
                'invitation_credit_limit', 'replacement_credit_limit',
                'price', 'currency', 'salla_product_id', 'salla_sku', 'salla_url',
                'sort_order', 'status', 'created_at', 'updated_at',
            ];
            $out = [];
            foreach ($columns as $field) {
                $out[] = ['Field' => $field];
            }
            return $out;
        }

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

    public function insert($table, $data, $format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) {
            return false;
        }
        if ($which === 'tiers') {
            $id = $this->tiers_next_id++;
            $this->tiers[$id] = array_merge(['id' => $id], $data);
        } else {
            $id = $this->plans_next_id++;
            $this->plans[$id] = array_merge(['id' => $id], $data);
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
        if ($which === 'tiers') {
            if (!isset($this->tiers[$id])) {
                return 0;
            }
            foreach ($data as $k => $v) {
                $this->tiers[$id][$k] = $v;
            }
        } else {
            if (!isset($this->plans[$id])) {
                return 0;
            }
            foreach ($data as $k => $v) {
                $this->plans[$id][$k] = $v;
            }
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
}

$GLOBALS['wpdb'] = new Fake_Wpdb();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

// ── تحميل الملفات الحقيقية من المشروع (بلا أي تعديل عليها) ─────────────────

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../includes/class-pge-catalog.php';
require_once __DIR__ . '/../includes/class-pge-feature-registry.php';
require_once __DIR__ . '/../includes/class-pge-tier-features.php';
require_once __DIR__ . '/../includes/class-mon-events-users.php';
require_once __DIR__ . '/../includes/event-factory.php';

// ── أدوات الاختبار (نفس نمط check()/check_true() في الملف الآخر) ──────────

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
        $actual_str = var_export($actual, true);
        $expected_str = var_export($expected, true);
        echo "FAIL  $label (expected $expected_str, got $actual_str)\n";
    }
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

// ── تجهيز باقة أساسية (mon_plans) تُستخدم كأب لكل المستويات في هذا الاختبار ─

$wpdb->seed_plan(1, [
    'plan_key'  => 'basic_plan',
    'name'      => 'باقة أساسية',
    'plan_type' => 'personal',
    'status'    => 'active',
]);

echo "=== guest_limit: CRUD + Snapshot + Enforcement ===\n";

// ── 1) Create Tier with guest_limit=100 ────────────────────────────────────

$tier_100 = PGE_Catalog::create_tier([
    'plan_id'          => 1,
    'tier_key'         => 'gl_100',
    'name'             => 'مستوى بحد 100',
    'price'            => 100,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 0,
    'guest_limit'      => 100,
]);
check_true('1. create_tier() نجح مع guest_limit=100', $tier_100 !== null);
check('1. guest_limit المخزَّن = 100', $tier_100['guest_limit'] ?? null, 100);
check_true('1. guest_limit من النوع int', is_int($tier_100['guest_limit'] ?? null));

// ── 2) Create Tier with guest_limit=NULL (المفتاح غائب) ────────────────────

$tier_null = PGE_Catalog::create_tier([
    'plan_id'          => 1,
    'tier_key'         => 'gl_null',
    'name'             => 'مستوى بلا حد',
    'price'            => 50,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 1,
    // guest_limit غائب عمداً — صلب السيناريو 2
]);
check_true('2. create_tier() نجح بلا guest_limit', $tier_null !== null);
check_true('2. مفتاح guest_limit موجود ضمن الصف الناتج (يُخزَّن دائماً حتى لو null)', is_array($tier_null) && array_key_exists('guest_limit', $tier_null));
check('2. guest_limit المخزَّن = null', $tier_null['guest_limit'], null);

// ── 3) Update Tier with guest_limit=50 ──────────────────────────────────────

$tier_updated = PGE_Catalog::update_tier($tier_100['id'], [
    'plan_id'          => 1,
    'tier_key'         => 'gl_100',
    'price'            => 100,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 0,
    'guest_limit'      => 50,
]);
check_true('3. update_tier() نجح مع guest_limit=50', $tier_updated !== null);
check('3. guest_limit بعد التحديث = 50', $tier_updated['guest_limit'] ?? null, 50);

// ── 4) Update without guest_limit — القيمة القديمة لا تتغيّر ────────────────

$tier_no_touch = PGE_Catalog::update_tier($tier_100['id'], [
    'plan_id'          => 1,
    'tier_key'         => 'gl_100',
    'price'            => 120,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 0,
    // guest_limit غائب عمداً — صلب السيناريو 4
]);
check_true('4. update_tier() نجح بدون guest_limit', $tier_no_touch !== null);
check('4. guest_limit يبقى 50 (لم يُلمَس)', $tier_no_touch['guest_limit'] ?? null, 50);

// ── 5) Negative value becomes NULL (على create وupdate كلاهما) ─────────────

$tier_negative_create = PGE_Catalog::create_tier([
    'plan_id'          => 1,
    'tier_key'         => 'gl_negative',
    'name'             => 'مستوى قيمة سالبة',
    'price'            => 75,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 2,
    'guest_limit'      => -10,
]);
check_true('5. create_tier() نجح مع guest_limit سالب', $tier_negative_create !== null);
check('5. guest_limit سالب (-10) عند الإنشاء → null', $tier_negative_create['guest_limit'], null);

$tier_negative_update = PGE_Catalog::update_tier($tier_100['id'], [
    'plan_id'          => 1,
    'tier_key'         => 'gl_100',
    'price'            => 120,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 0,
    'guest_limit'      => -5,
]);
check('5. guest_limit سالب (-5) عند التحديث → null', $tier_negative_update['guest_limit'], null);

// إعادة القيمة إلى 100 حرفياً لاستخدامها في اختبارات Snapshot أدناه ─────────

$tier_restored = PGE_Catalog::update_tier($tier_100['id'], [
    'plan_id'          => 1,
    'tier_key'         => 'gl_100',
    'price'            => 100,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 0,
    'guest_limit'      => 100,
]);
check('استعادة guest_limit=100 بعد اختبارات القيم السالبة', $tier_restored['guest_limit'] ?? null, 100);

// ── 6) Activation copies the correct value into the Snapshot ───────────────

set_test_user_id(9101);
reset_test_user(9101);

$activation_100 = Mon_Events_Users::activate_catalog_tier(9101, 1, $tier_restored['id']);
check_true('6. activate_catalog_tier() نجح (guest_limit=100)', $activation_100 === true);
check('6. _mon_guest_limit في Snapshot = 100', (int) get_user_meta(9101, '_mon_guest_limit', true), 100);

// ── 7) Manual Package Activation is unaffected ──────────────────────────────
// manual-package-activation-ajax.php يستدعي نفس Mon_Events_Users::activate_catalog_tier()
// دون أي منطق guest_limit إضافي خاص به — استدعاء ثانٍ مطابق لنفس المسار
// يثبت أن التفعيل اليدوي غير متأثر بأي شكل بتعديلات CRUD أعلاه.

set_test_user_id(9102);
reset_test_user(9102);

$manual_activation = Mon_Events_Users::activate_catalog_tier(9102, 1, $tier_restored['id']);
check_true('7. تفعيل يدوي (نفس مسار Manual Package Activation) نجح', $manual_activation === true);
check('7. _mon_guest_limit للتفعيل اليدوي = 100 (نفس النتيجة، غير متأثر)', (int) get_user_meta(9102, '_mon_guest_limit', true), 100);

// ── 8) Guest Limit Enforcement reads the new value ──────────────────────────
// pge_get_catalog_user_plan_limits_for_events() هي القراءة المركزية التي
// يعتمد عليها الإنفاذ الفعلي (Manual Create/Bulk Add/Excel Confirm)، ولم
// تُعدَّل في هذه المهمة — تُقرأ فقط للتأكد أنها تعكس Snapshot الجديد تلقائياً.

update_user_meta(9101, '_mon_package_status', 'active');
update_user_meta(9101, '_mon_catalog_plan_id', 1);
update_user_meta(9101, '_mon_catalog_tier_id', $tier_restored['id']);

$limits_9101 = pge_get_catalog_user_plan_limits_for_events(9101);
check('8. الإنفاذ يقرأ guest_limit=100 من Snapshot عبر pge_get_catalog_user_plan_limits_for_events()', $limits_9101['guest_limit'] ?? null, 100);

// ── 9) Unlimited Tier remains Unlimited ─────────────────────────────────────

set_test_user_id(9103);
reset_test_user(9103);

$activation_unlimited = Mon_Events_Users::activate_catalog_tier(9103, 1, $tier_null['id']);
check_true('9. activate_catalog_tier() نجح (Tier بلا حد/NULL)', $activation_unlimited === true);
check('9. _mon_guest_limit في Snapshot لمستوى بلا حد = "" (يمثّل NULL، لا صفراً)', get_user_meta(9103, '_mon_guest_limit', true), '');

update_user_meta(9103, '_mon_package_status', 'active');
update_user_meta(9103, '_mon_catalog_plan_id', 1);
update_user_meta(9103, '_mon_catalog_tier_id', $tier_null['id']);

$limits_9103 = pge_get_catalog_user_plan_limits_for_events(9103);
check('9. المستوى "بلا حد" يبقى بلا حد عند القراءة (guest_limit=0 = بلا حد وفق الاصطلاح الموحَّد)', $limits_9103['guest_limit'] ?? null, 0);

// ── 10) Old Snapshot does not change after a Tier is edited later ──────────

set_test_user_id(9104);
reset_test_user(9104);

$activation_before_edit = Mon_Events_Users::activate_catalog_tier(9104, 1, $tier_restored['id']);
check_true('10. تفعيل قبل تعديل Tier نجح (guest_limit=100 وقتها)', $activation_before_edit === true);
check('10. Snapshot المستخدم 9104 عند التفعيل = 100', (int) get_user_meta(9104, '_mon_guest_limit', true), 100);

$tier_after_later_edit = PGE_Catalog::update_tier($tier_restored['id'], [
    'plan_id'          => 1,
    'tier_key'         => 'gl_100',
    'price'            => 100,
    'currency'         => 'SAR',
    'salla_product_id' => null,
    'status'           => 'active',
    'sort_order'       => 0,
    'guest_limit'      => 999,
]);
check('10. تعديل الـTier لاحقاً إلى guest_limit=999 نجح على صف الـTier نفسه', $tier_after_later_edit['guest_limit'] ?? null, 999);
check('10. Snapshot المستخدم 9104 (القديم) يبقى 100 ولا يتأثر بتعديل الـTier اللاحق', (int) get_user_meta(9104, '_mon_guest_limit', true), 100);

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
