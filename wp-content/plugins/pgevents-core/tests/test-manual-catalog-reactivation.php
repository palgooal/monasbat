<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لإصلاح "إعادة التفعيل اليدوي لنفس
 * Catalog Tier" — Mon_Events_Users::refresh_catalog_tier_snapshot() الجديدة،
 * ووصلها في includes/manual-package-activation-ajax.php، بلا أي تعديل على
 * حارس Idempotency في Mon_Events_Users::activate_catalog_tier() نفسه (يبقى
 * سلوك Webhook سلة كما هو تماماً — يُثبَت ذلك صراحةً في اختبارَي 1/2 أدناه).
 *
 * نفس بنية الاختبارات القائمة بالفعل في هذا المجلد (Fake $wpdb في الذاكرة،
 * Stubs ووردبريس دنيا، استدعاء معالجات AJAX الحقيقية المُسجَّلة عبر add_action()
 * وهمية) — الملفات الإنتاجية الحقيقية تُحمَّل وتُنفَّذ دون أي تعديل عليها.
 *
 * السيناريوهات المطلوبة صراحةً (12 اختباراً):
 *   1) Salla Webhook: نفس order_id مرتين → التفعيل الثاني بلا تأثير (no-op).
 *   2) Salla: order_id جديد → تفعيل عادي يمضي قدماً (تراكم رصيد حقيقي).
 *   3) تفعيل يدوي أول لمستخدم بلا باقة → ينجح.
 *   4) إعادة تفعيل يدوي لنفس Tier بعد تعديل guest_limit من 100 إلى 50 → يتحدّث Snapshot إلى 50.
 *   5) إعادة تفعيل يدوي لنفس Tier بعد تعديل ميزة → يتحدّث Snapshot الميزات وفق العقد.
 *   6) التحقق أن رصيد الدعوات لا يتضاعف.
 *   7) التحقق أن الرصيد البديل لا يتضاعف.
 *   8) التحقق من سلوك credit_cycle_id — يبقى كما هو (لا يتغيّر) عند إعادة التفعيل اليدوية.
 *   9) تفعيل يدوي (Override) على مستخدم فعّال بالفعل → حماية confirm_override تبقى سليمة.
 *   10) حماية Catalog → Legacy تبقى بلا تغيير.
 *   11) لوحة التحكم بعد إعادة التفعيل تعرض guest_limit الجديد.
 *   12) إنفاذ حد المدعوين يستخدم القيمة الجديدة فعلياً.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل:
 *   node /tmp/phpcheck/run_generic.mjs tests/test-manual-catalog-reactivation.php
 */

define('ABSPATH', __DIR__ . '/');

function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

$GLOBALS['__test_actions'] = [];
function add_action($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['__test_actions'][$hook][] = $callback;
}
function get_registered_action($hook)
{
    return $GLOBALS['__test_actions'][$hook][0] ?? null;
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim((string) $v); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($v) { return trim(strip_tags((string) $v)); }
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
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s) { return strlen((string) $s); }
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
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
    }
}

$GLOBALS['__test_logged_in'] = true;
$GLOBALS['__test_is_admin'] = true;
function is_user_logged_in() { return $GLOBALS['__test_logged_in']; }
function current_user_can($cap, $object_id = null)
{
    if ($cap === 'manage_options') {
        return $GLOBALS['__test_is_admin'];
    }
    return false;
}
$GLOBALS['__test_current_user_id'] = 999;
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }

function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

if (!class_exists('Test_Wp_Die_Exception')) {
    class Test_Wp_Die_Exception extends \Exception {}
}
$GLOBALS['__test_json_response'] = null;
function wp_send_json_success($data = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['__test_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function call_ajax_handler(callable $handler): array
{
    $GLOBALS['__test_json_response'] = null;
    $_POST_backup = $_POST;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // متوقَّع دائماً.
    }
    $_POST = $_POST_backup;
    $raw = $GLOBALS['__test_json_response'];
    if ($raw === null) {
        return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

$GLOBALS['__test_options']        = [];
$GLOBALS['__test_user_meta']      = [];
$GLOBALS['__test_users_by_id']    = [];
$GLOBALS['__test_users_by_email'] = [];

function get_option($name, $default = false) { return $GLOBALS['__test_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['__test_options'][$name] = $value; return true; }

function get_user_meta($user_id, $key, $single = false)
{
    $value = $GLOBALS['__test_user_meta'][$user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_user_meta($user_id, $key, $value) { $GLOBALS['__test_user_meta'][$user_id][$key] = $value; return true; }
function delete_user_meta($user_id, $key) { unset($GLOBALS['__test_user_meta'][$user_id][$key]); return true; }
function metadata_exists($type, $object_id, $meta_key) { return ($GLOBALS['__test_user_meta'][$object_id][$meta_key] ?? '') !== ''; }
function reset_test_user($user_id) { $GLOBALS['__test_user_meta'][$user_id] = []; }

function set_test_user($id, $email, $display_name = '')
{
    $GLOBALS['__test_users_by_id'][$id] = (object) ['ID' => $id, 'user_email' => $email, 'display_name' => $display_name ?: ('User ' . $id), 'user_login' => 'user' . $id];
    $GLOBALS['__test_users_by_email'][$email] = $id;
}
function get_user_by($field, $value)
{
    if ($field === 'id') {
        return $GLOBALS['__test_users_by_id'][$value] ?? false;
    }
    if ($field === 'email') {
        $id = $GLOBALS['__test_users_by_email'][$value] ?? null;
        return $id === null ? false : $GLOBALS['__test_users_by_id'][$id];
    }
    return false;
}

// ── Fake $wpdb — plans/tiers/tier_features/manual-activation-audit ─────────
class Fake_Wpdb_Manual_Reactivation
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';

    public $plans = [];
    public $tiers = [];
    public $tier_features = [];
    public $audit = [];

    private $plans_next_id = 1;
    private $tiers_next_id = 1;
    private $tier_features_next_id = 1;
    private $audit_next_id = 1;

    public function get_charset_collate() { return ''; }

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
        if (strpos($sql_or_table, $this->prefix . 'pge_manual_package_activation_audit') !== false) {
            return 'audit';
        }
        if (strpos($sql_or_table, $this->prefix . 'mon_tier_features') !== false) {
            return 'tier_features';
        }
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

        $store = &$this->{$which};
        $rows = array_values($store);

        if ($which === 'audit' && preg_match('/target_user_id\s*=\s*(\d+)/', $sql, $um)) {
            $target = (int) $um[1];
            $rows = array_values(array_filter($rows, function ($r) use ($target) {
                return (int) $r['target_user_id'] === $target;
            }));
            usort($rows, function ($a, $b) { return $b['id'] <=> $a['id']; });
            if (preg_match('/LIMIT\s+(\d+)/i', $sql, $lm)) {
                $rows = array_slice($rows, 0, (int) $lm[1]);
            }
            return $rows;
        }

        if (preg_match('/WHERE\s+(.*?)(\s+ORDER\s+BY|\s+LIMIT|$)/is', $sql, $m)) {
            $where = trim($m[1]);
            if ($where !== '') {
                $conditions = preg_split('/\bAND\b/i', $where);
                foreach ($conditions as $cond) {
                    $cond = trim($cond);
                    if ($cond === '') continue;
                    if (preg_match("/^(\\w+)\\s*=\\s*'([^']*)'$/", $cond, $cm)) {
                        [$field, $value] = [$cm[1], $cm[2]];
                    } elseif (preg_match('/^(\\w+)\\s*=\\s*(-?\\d+)$/', $cond, $cm)) {
                        [$field, $value] = [$cm[1], $cm[2]];
                    } else {
                        continue;
                    }
                    $rows = array_values(array_filter($rows, function ($r) use ($field, $value) {
                        return array_key_exists($field, $r) && (string) $r[$field] === (string) $value;
                    }));
                }
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
        $idProp = $which . '_next_id';
        $id = $this->$idProp++;
        $this->{$which}[$id] = array_merge(['id' => $id], $data);
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $which = $this->which_table($table);
        if ($which === null) return false;
        $id = $where['id'] ?? null;
        if ($id !== null) {
            if (!isset($this->{$which}[$id])) return 0;
            foreach ($data as $k => $v) {
                $this->{$which}[$id][$k] = $v;
            }
            return 1;
        }
        // دعم UPDATE بشرط (tier_id, feature_key) — نمط set_tier_feature_value().
        if ($which === 'tier_features' && isset($where['tier_id'], $where['feature_key'])) {
            foreach ($this->tier_features as $tid => &$row) {
                if ((int) ($row['tier_id'] ?? 0) === (int) $where['tier_id'] && (string) ($row['feature_key'] ?? '') === (string) $where['feature_key']) {
                    foreach ($data as $k => $v) {
                        $row[$k] = $v;
                    }
                    return 1;
                }
            }
        }
        return 0;
    }

    public function seed_plan($id, array $row) { $this->plans[$id] = array_merge(['id' => $id], $row); if ($id >= $this->plans_next_id) $this->plans_next_id = $id + 1; }
    public function seed_tier($id, array $row) { $this->tiers[$id] = array_merge(['id' => $id], $row); if ($id >= $this->tiers_next_id) $this->tiers_next_id = $id + 1; }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Manual_Reactivation();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

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
require_once __DIR__ . '/../includes/class-pge-manual-package-activation-audit.php';
require_once __DIR__ . '/../includes/manual-package-activation-ajax.php';
require_once __DIR__ . '/../includes/event-factory.php';

// ── أدوات الاختبار ──────────────────────────────────────────────────────
$total = 0; $passed = 0; $failures = [];
function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS  $label\n"; }
    else { $failures[] = $label; echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}
function check_true($label, $cond) { check($label, (bool) $cond, true); }

function post_fields(array $extra = [])
{
    return array_merge(['nonce' => wp_create_nonce('pge_manual_pkg_activation')], $extra);
}
function run(callable $handler, array $fields): array
{
    $_POST = post_fields($fields);
    return call_ajax_handler($handler);
}

// ── تجهيز باقة و Tier أساسيَين ──────────────────────────────────────────
$plan = PGE_Catalog::create_plan(['plan_key' => 'gold', 'name' => 'الباقة الذهبية', 'plan_type' => 'personal', 'status' => 'active']);

$tierA = PGE_Catalog::create_tier([
    'plan_id' => $plan['id'], 'tier_key' => 'gold_tier', 'name' => 'المستوى الذهبي',
    'price' => 500, 'currency' => 'SAR', 'salla_product_id' => null, 'status' => 'active', 'sort_order' => 0,
    'guest_limit' => 100, 'events_count' => 3, 'invitation_credit_limit' => 50, 'replacement_credit_limit' => 10,
]);

$GLOBALS['__test_options']['mon_packages_settings'] = [
    'plan_1' => ['name' => 'باقة قديمة', 'guest_limit' => 40, 'host_photos' => 8, 'events_count' => 2, 'wa_messages' => 5],
];

$activate_handler = get_registered_action('wp_ajax_pge_manual_activation_activate');
check_true('نقطة نهاية التفعيل مسجَّلة فعلياً عبر add_action', $activate_handler !== null);

// ============================================================================
// 1) Salla Webhook: نفس order_id مرتين → التفعيل الثاني بلا تأثير (no-op)
// ============================================================================
echo "\n=== اختبار 1: Salla — نفس order_id مرتين ===\n";
set_test_user(701, 'salla-dup@example.test');
reset_test_user(701);

$r1a = Mon_Events_Users::activate_catalog_tier(701, $plan['id'], $tierA['id'], 'ORDER-DUP-1');
check_true('1. التفعيل الأول (order_id=ORDER-DUP-1) نجح', $r1a === true);
$cycle_after_first = get_user_meta(701, '_mon_credit_cycle_id', true);
$total_after_first = (int) get_user_meta(701, '_mon_invitation_credit_total', true);
$activated_at_first = get_user_meta(701, '_mon_package_activated_at', true);
check('1. رصيد الدعوات بعد التفعيل الأول = 50', $total_after_first, 50);

$r1b = Mon_Events_Users::activate_catalog_tier(701, $plan['id'], $tierA['id'], 'ORDER-DUP-1');
check_true('1. إعادة إرسال نفس order_id ترجع true (بلا خطأ)', $r1b === true);
check('1. credit_cycle_id لم يتغيّر (لا كتابة إطلاقاً — no-op حقيقي)', get_user_meta(701, '_mon_credit_cycle_id', true), $cycle_after_first);
check('1. رصيد الدعوات لم يتضاعف (يبقى 50 لا 100)', (int) get_user_meta(701, '_mon_invitation_credit_total', true), 50);
check('1. _mon_package_activated_at لم يتغيّر (لا كتابة إطلاقاً)', get_user_meta(701, '_mon_package_activated_at', true), $activated_at_first);

// ============================================================================
// 2) Salla: order_id جديد → تفعيل عادي يمضي قدماً (تراكم حقيقي)
// ============================================================================
echo "\n=== اختبار 2: Salla — order_id جديد (تجديد حقيقي) ===\n";
$r2 = Mon_Events_Users::activate_catalog_tier(701, $plan['id'], $tierA['id'], 'ORDER-DUP-2');
check_true('2. تفعيل بـorder_id جديد نجح', $r2 === true);
check_true('2. credit_cycle_id تغيّر فعلياً (تفعيل جديد حقيقي)', get_user_meta(701, '_mon_credit_cycle_id', true) !== $cycle_after_first);
check('2. رصيد الدعوات تراكم إلى 100 (50 متبقٍ + 50 جديد)', (int) get_user_meta(701, '_mon_invitation_credit_total', true), 100);
check('2. _mon_last_order_id تحدَّث إلى ORDER-DUP-2', get_user_meta(701, '_mon_last_order_id', true), 'ORDER-DUP-2');

// ============================================================================
// 3) تفعيل يدوي أول لمستخدم بلا باقة → ينجح
// ============================================================================
echo "\n=== اختبار 3: تفعيل يدوي أول (بلا باقة سابقة) ===\n";
set_test_user(801, 'manual-reactivation@example.test');
reset_test_user(801);

$res3 = run($activate_handler, [
    'target_user_id' => 801, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tierA['id'],
    'reason' => 'تفعيل أول', 'confirm_override' => 0,
]);
check_true('3. نجاح التفعيل اليدوي الأول', $res3['success'] === true);
check('3. _mon_guest_limit = 100 (قيمة الـTier وقتها)', (int) get_user_meta(801, '_mon_guest_limit', true), 100);
check('3. _mon_invitation_credit_total = 50', (int) get_user_meta(801, '_mon_invitation_credit_total', true), 50);
check('3. _mon_replacement_credit_total = 10', (int) get_user_meta(801, '_mon_replacement_credit_total', true), 10);
$cycle_801_first = get_user_meta(801, '_mon_credit_cycle_id', true);
check_true('3. credit_cycle_id مكتوب (سلسلة غير فارغة)', is_string($cycle_801_first) && $cycle_801_first !== '');

// ============================================================================
// 4) إعادة تفعيل يدوي لنفس Tier بعد تعديل guest_limit 100→50
// ============================================================================
echo "\n=== اختبار 4: إعادة تفعيل يدوي لنفس Tier بعد تعديل guest_limit ===\n";
$tierA_after_edit = PGE_Catalog::update_tier($tierA['id'], [
    'plan_id' => $plan['id'], 'tier_key' => 'gold_tier', 'price' => 500, 'currency' => 'SAR',
    'salla_product_id' => null, 'status' => 'active', 'sort_order' => 0,
    'guest_limit' => 50,
]);
check('4. تعديل الـTier نفسه إلى guest_limit=50 نجح', $tierA_after_edit['guest_limit'] ?? null, 50);

// بلا confirm_override أولاً — يجب أن يُحظَر (يُثبِت اختبار 9 لاحقاً بمزيد
// من التفصيل، هنا فقط تمهيد ضروري لإكمال السيناريو بالتأكيد الصريح).
$res4_blocked = run($activate_handler, [
    'target_user_id' => 801, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tierA['id'],
    'reason' => 'محاولة بلا تأكيد', 'confirm_override' => 0,
]);
check('4. إعادة التفعيل بلا تأكيد تُحظَر أولاً (active_package_exists)', $res4_blocked['data']['reason'] ?? null, 'active_package_exists');
check('4. Snapshot لم يتغيّر بعد المحاولة المحظورة (يبقى 100)', (int) get_user_meta(801, '_mon_guest_limit', true), 100);

$res4 = run($activate_handler, [
    'target_user_id' => 801, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tierA['id'],
    'reason' => 'تحديث guest_limit بعد تعديل الإدارة', 'confirm_override' => 1,
]);
check_true('4. إعادة التفعيل بتأكيد صريح نجحت', $res4['success'] === true);
check('4. Snapshot المستخدم 801 أصبح guest_limit=50 بعد إعادة التفعيل', (int) get_user_meta(801, '_mon_guest_limit', true), 50);

// ============================================================================
// 5) إعادة تفعيل يدوي بعد تعديل ميزة (Feature Snapshot)
// ============================================================================
echo "\n=== اختبار 5: إعادة تفعيل يدوي بعد تعديل ميزة (admin_supervisor_limit) ===\n";
$features_before = get_user_meta(801, '_mon_package_features', true);
$version_before = (int) get_user_meta(801, '_mon_package_feature_version', true);
check_true('5. لا صف tier_features بعد قبل الضبط → القيمة الافتراضية للسجل (int)0)', is_int($features_before['admin_supervisor_limit'] ?? null));

// إضافة قيمة صريحة للميزة على مستوى الـTier، ثم إعادة تفعيل بتأكيد.
$wpdb->insert($wpdb->prefix . 'mon_tier_features', [
    'tier_id' => $tierA['id'], 'feature_key' => 'admin_supervisor_limit', 'feature_value' => '7',
    'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
]);

$res5 = run($activate_handler, [
    'target_user_id' => 801, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tierA['id'],
    'reason' => 'تحديث بعد تعديل ميزة', 'confirm_override' => 1,
]);
check_true('5. إعادة التفعيل بعد تعديل الميزة نجحت', $res5['success'] === true);
$features_after = get_user_meta(801, '_mon_package_features', true);
check('5. Snapshot الميزات يعكس القيمة الجديدة admin_supervisor_limit=7', $features_after['admin_supervisor_limit'] ?? null, 7);
check_true('5. رقم إصدار Snapshot الميزات تزايد فعلياً', (int) get_user_meta(801, '_mon_package_feature_version', true) > $version_before);

// ============================================================================
// 6/7) رصيد الدعوات والرصيد البديل لا يتضاعفان عبر كل إعادات التفعيل أعلاه
// ============================================================================
echo "\n=== اختبار 6/7: رصيد الدعوات والرصيد البديل لم يتضاعفا ===\n";
check('6. _mon_invitation_credit_total يبقى 50 عبر كل إعادات التفعيل (لا تراكم إطلاقاً)', (int) get_user_meta(801, '_mon_invitation_credit_total', true), 50);
check('6. _mon_invitation_credit_used يبقى 0', (int) get_user_meta(801, '_mon_invitation_credit_used', true), 0);
check('7. _mon_replacement_credit_total يبقى 10 (لا تراكم إطلاقاً)', (int) get_user_meta(801, '_mon_replacement_credit_total', true), 10);
check('7. _mon_replacement_credit_used يبقى 0', (int) get_user_meta(801, '_mon_replacement_credit_used', true), 0);

// ============================================================================
// 8) credit_cycle_id يبقى كما هو عبر كل إعادات التفعيل اليدوية
// ============================================================================
echo "\n=== اختبار 8: credit_cycle_id لا يتغيّر عند إعادة التفعيل اليدوية ===\n";
check('8. credit_cycle_id بعد اختبار 4 وَ5 مطابق تماماً لقيمته الأصلية من اختبار 3', get_user_meta(801, '_mon_credit_cycle_id', true), $cycle_801_first);
check('8. _mon_last_order_id يبقى فارغاً (لم يُنشأ ولم يُحذف بشكل غير متوقع)', get_user_meta(801, '_mon_last_order_id', true), '');

// ============================================================================
// 9) تفعيل يدوي (Override) على مستخدم فعّال بالفعل → حماية confirm_override سليمة
// ============================================================================
echo "\n=== اختبار 9: حماية confirm_override سليمة (Tier مختلف كذلك) ===\n";
$tierB = PGE_Catalog::create_tier([
    'plan_id' => $plan['id'], 'tier_key' => 'silver_tier', 'name' => 'المستوى الفضي',
    'price' => 300, 'currency' => 'SAR', 'salla_product_id' => null, 'status' => 'active', 'sort_order' => 1,
    'guest_limit' => 30, 'events_count' => 2, 'invitation_credit_limit' => 20, 'replacement_credit_limit' => 3,
]);
$res9_blocked = run($activate_handler, [
    'target_user_id' => 801, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tierB['id'],
    'reason' => 'محاولة تبديل Tier بلا تأكيد', 'confirm_override' => 0,
]);
check('9. تبديل Tier مختلف بلا تأكيد يُحظَر أيضاً (نفس البوابة)', $res9_blocked['data']['reason'] ?? null, 'active_package_exists');
check('9. tier_id لم يتغيّر بعد الحظر (يبقى الذهبي)', (int) get_user_meta(801, '_mon_catalog_tier_id', true), (int) $tierA['id']);

$res9_confirmed = run($activate_handler, [
    'target_user_id' => 801, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tierB['id'],
    'reason' => 'تبديل Tier بتأكيد صريح', 'confirm_override' => 1,
]);
check_true('9. تبديل Tier مختلف بتأكيد صريح ينجح (يستخدم activate_catalog_tier العادية لا refresh)', $res9_confirmed['success'] === true);
check('9. tier_id أصبح الفضي فعلياً', (int) get_user_meta(801, '_mon_catalog_tier_id', true), (int) $tierB['id']);
check_true('9. Tier مختلف = تفعيل حقيقي جديد → credit_cycle_id تغيّر هذه المرة (بخلاف اختبار 8)', get_user_meta(801, '_mon_credit_cycle_id', true) !== $cycle_801_first);

// ============================================================================
// 10) حماية Catalog → Legacy تبقى بلا تغيير
// ============================================================================
echo "\n=== اختبار 10: حماية Catalog → Legacy بلا تغيير ===\n";
$res10 = run($activate_handler, [
    'target_user_id' => 801, 'source' => 'legacy', 'plan_key' => 'plan_1',
    'reason' => 'محاولة تخفيض إلى Legacy', 'confirm_override' => 1,
]);
check('10. تفعيل Legacy لمستخدم مصدره الحالي catalog يُحظَر حتى مع التأكيد', $res10['success'], false);
check('10. المصدر يبقى catalog بلا أي تغيير', get_user_meta(801, '_mon_package_source', true), 'catalog');

// ============================================================================
// 11/12) لوحة التحكم وإنفاذ حد المدعوين يقرآن guest_limit الجديد (=50 من اختبار 4)
// ============================================================================
echo "\n=== اختبار 11/12: القراءة المركزية (لوحة التحكم + الإنفاذ) تعكس القيمة الجديدة ===\n";
// pge_get_catalog_user_plan_limits_for_events() هي نفس دالة القراءة المركزية
// التي تعتمد عليها كل من dashboard-main.php (بطاقة الحدود) ومسارات الإنفاذ
// الفعلية (Manual Create/Bulk Add/Excel Confirm) — لم تُعدَّل في هذه المهمة،
// تُقرأ هنا فقط لإثبات أنها تعكس Snapshot المُحدَّث تلقائياً عبر refresh_catalog_tier_snapshot().
$limits_801 = pge_get_catalog_user_plan_limits_for_events(801);
check('11. لوحة التحكم/القراءة المركزية تعرض guest_limit=30 (Tier الفضي الحالي فعلياً بعد اختبار 9)', $limits_801['guest_limit'] ?? null, 30);

// إثبات مباشر ومعزول لسيناريو "50" تحديداً (اختبار 4) عبر مستخدم مستقل، بلا
// تداخل مع تبديل الـTier في اختبار 9 أعلاه — يعكس بدقة نص السيناريو المطلوب:
// "تعديل guest_limit 100→50 ثم إعادة تفعيل نفس Tier".
set_test_user(802, 'guest-limit-isolated@example.test');
reset_test_user(802);
run($activate_handler, [
    'target_user_id' => 802, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tierA['id'],
    'reason' => 'تفعيل أول (guest_limit=50 وقتها بعد تعديل اختبار 4)', 'confirm_override' => 0,
]);
check('12. تفعيل جديد يقرأ مباشرة القيمة الحالية للـTier (50) — Snapshot المستخدم 802', (int) get_user_meta(802, '_mon_guest_limit', true), 50);
$limits_802 = pge_get_catalog_user_plan_limits_for_events(802);
check('12. الإنفاذ الفعلي (Manual Create/Bulk Add/Excel) يقرأ guest_limit=50 عبر نفس الدالة المركزية', $limits_802['guest_limit'] ?? null, 50);

// ── ملخص ────────────────────────────────────────────────────────────────
echo "\nالنتيجة: $passed / $total نجحت.\n";
if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
