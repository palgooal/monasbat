<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـ"التفعيل اليدوي للباقات من لوحة
 * الإدارة" — includes/manual-package-activation-ajax.php،
 * class-pge-manual-package-activation-audit.php. يستدعي معالجات AJAX
 * الحقيقية (المسجَّلة عبر add_action الحقيقي في الملف الحقيقي، المُلتقَطة هنا
 * عبر add_action() وهمية تُخزِّن Callback فقط)، ويستدعي Mon_Events_Users::
 * activate_catalog_tier()/activate_user_package() الحقيقيتين مباشرة — لا نسخ
 * منطق مصطنعة.
 *
 * السيناريوهات المطلوبة صراحةً (7 اختبارات + انحدار):
 *   1) تفعيل يدوي لمستخدم بلا باقة (Catalog وLegacy).
 *   2) تفعيل يدوي لمستخدم لديه باقة فعالة (حظر بلا تأكيد، نجاح مع تأكيد).
 *   3) تطابق 100% بين نتيجة المسار اليدوي ونتيجة استدعاء نفس الـService مباشرة
 *      (محاكاة ما يفعله Webhook سلة).
 *   4) Guest Limit يعمل (Catalog وLegacy).
 *   5) Invitation Credits تعمل (Catalog فقط، كما في الإنتاج).
 *   6) Features تعمل (Snapshot الحقيقي يُكتب، والمعاينة تعرضه).
 *   7) Audit Logging يعمل (سجل واحد لكل تفعيل، بلا بيانات حساسة).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: node check.js (php-parser) للتحقق النحوي، ثم تتبّع يدوي للمسارات
 * (بيئة الاختبار الحالية بلا مفسّر PHP حقيقي — راجع التقرير النهائي).
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
    // بيئات نادرة بلا mbstring — بديل كافٍ للاختبار فقط.
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

// ── حالة تسجيل الدخول/الصلاحية — قابلة للتحكم من كل سيناريو ────────────────
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
$GLOBALS['__test_current_user_id'] = 999; // "الأدمن" المنفِّذ للتفعيل اليدوي
function get_current_user_id() { return $GLOBALS['__test_current_user_id']; }

// ── Nonce ────────────────────────────────────────────────────────────────
function wp_create_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals('test-nonce-' . sanitize_key($action), (string) $nonce) ? 1 : false; }

// ── wp_die/JSON capture (نفس أسلوب test-supervisor-management.php حرفياً) ──
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

// ── Options / User Meta / Users (نفس نمط test-catalog-tier-events-count.php) ─
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
function set_test_user_meta($user_id, $key, $value) { $GLOBALS['__test_user_meta'][$user_id][$key] = $value; }
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
class Fake_Wpdb_Manual_Activation
{
    public $prefix = 'wp_';
    public $insert_id = 0;

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

        // ORDER BY id DESC LIMIT N — نمط list_for_user() في PGE_Manual_Package_Activation_Audit فقط.
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

        // WHERE عام (يتوقف عند ORDER BY/LIMIT إن وُجدا) — يدعم AND من المساواة فقط.
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
        if ($id === null || !isset($this->{$which}[$id])) return 0;
        foreach ($data as $k => $v) {
            $this->{$which}[$id][$k] = $v;
        }
        return 1;
    }

    public function seed_plan($id, array $row) { $this->plans[$id] = array_merge(['id' => $id], $row); if ($id >= $this->plans_next_id) $this->plans_next_id = $id + 1; }
    public function seed_tier($id, array $row) { $this->tiers[$id] = array_merge(['id' => $id], $row); if ($id >= $this->tiers_next_id) $this->tiers_next_id = $id + 1; }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Manual_Activation();
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

// ── تجهيز بيانات Catalog أساسية ─────────────────────────────────────────
$plan = PGE_Catalog::create_plan(['plan_key' => 'gold', 'name' => 'الباقة الذهبية', 'plan_type' => 'personal', 'status' => 'active']);
$tier = PGE_Catalog::create_tier([
    'plan_id' => $plan['id'], 'tier_key' => 'gold_tier', 'name' => 'المستوى الذهبي',
    'price' => 500, 'currency' => 'SAR', 'salla_product_id' => null, 'status' => 'active', 'sort_order' => 0,
    'guest_limit' => 250, 'events_count' => 3, 'invitation_credit_limit' => 120, 'replacement_credit_limit' => 15,
]);
// ملاحظة اكتُشفت أثناء التنفيذ الفعلي لهذا الاختبار: PGE_Catalog::create_tier()
// الحقيقية لا تكتب guest_limit إطلاقاً (مؤكَّد أيضاً في تعليق tests/test-catalog-
// tier-events-count.php: "guest_limit غير موجود ضمن مخرجات create_tier/update_tier
// — لم يُلمَس إطلاقاً") — لا توجد أي واجهة CRUD حالياً في المشروع تكتب هذا
// العمود لمستوى Catalog؛ القيمة الوحيدة الممكنة حالياً هي عبر إدخال مباشر في
// قاعدة البيانات. هذا خارج نطاق مهمة التفعيل اليدوي تماماً (لا يجوز لمس
// Package Resolver/Catalog CRUD هنا) — نُثبِّت القيمة مباشرة في $wpdb الوهمي
// لمحاكاة صف Tier له guest_limit فعلي مُدخَل مسبقاً، تماماً كحال أي Tier حقيقي
// في قاعدة الإنتاج تم ضبط هذا العمود له يدوياً وقت إنشاء بنية القاعدة.
$wpdb->tiers[$tier['id']]['guest_limit'] = 250;
$tier = PGE_Catalog::get_tier($tier['id']);

// مستوى ثانٍ لنفس الباقة — يُستخدَم في اختبار 2 لمحاكاة "إعادة تفعيل بمستوى
// مختلف لمستخدم Catalog نشط أصلاً" (بلا الاصطدام بحارس pge_is_legacy_write_
// allowed_for_user()، الذي يحظر أي كتابة Legacy لمستخدم مصدره الحالي catalog
// عمداً — سلوك حماية صحيح وموثَّق، وليس خللاً؛ راجع التقرير النهائي).
$tier_silver = PGE_Catalog::create_tier([
    'plan_id' => $plan['id'], 'tier_key' => 'silver_tier', 'name' => 'المستوى الفضي',
    'price' => 300, 'currency' => 'SAR', 'salla_product_id' => null, 'status' => 'active', 'sort_order' => 1,
    'events_count' => 2, 'invitation_credit_limit' => 60, 'replacement_credit_limit' => 5,
]);
$wpdb->tiers[$tier_silver['id']]['guest_limit'] = 100;
$tier_silver = PGE_Catalog::get_tier($tier_silver['id']);

$GLOBALS['__test_options']['mon_packages_settings'] = [
    'plan_1' => ['name' => 'باقة قديمة', 'guest_limit' => 40, 'host_photos' => 8, 'events_count' => 2, 'wa_messages' => 5, 'google_map' => '1'],
];

$activate_handler = get_registered_action('wp_ajax_pge_manual_activation_activate');
$preview_handler  = get_registered_action('wp_ajax_pge_manual_activation_preview');
$list_handler     = get_registered_action('wp_ajax_pge_manual_activation_list_packages');
check_true('نقاط النهاية الأربع مسجَّلة فعلياً عبر add_action', $activate_handler !== null && $preview_handler !== null && $list_handler !== null && get_registered_action('wp_ajax_pge_manual_activation_search_users') !== null);

echo "\n=== اختبار 1: تفعيل يدوي لمستخدم بلا باقة (Catalog) ===\n";
set_test_user(101, 'catalog-user@example.test');
reset_test_user(101);
$res1 = run($activate_handler, [
    'target_user_id' => 101, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tier['id'],
    'reason' => 'اختبار', 'confirm_override' => 0,
]);
check_true('1. نجاح التفعيل Catalog لمستخدم بلا باقة', $res1['success'] === true);
check('1. _mon_package_source = catalog', get_user_meta(101, '_mon_package_source', true), 'catalog');
check('1. _mon_package_status = active', get_user_meta(101, '_mon_package_status', true), 'active');

echo "\n=== اختبار 1ب: تفعيل يدوي لمستخدم بلا باقة (Legacy) ===\n";
set_test_user(102, 'legacy-user@example.test');
reset_test_user(102);
$res1b = run($activate_handler, [
    'target_user_id' => 102, 'source' => 'legacy', 'plan_key' => 'plan_1',
    'reason' => 'تعويض', 'confirm_override' => 0,
]);
check_true('1ب. نجاح التفعيل Legacy لمستخدم بلا باقة', $res1b['success'] === true);
check('1ب. _mon_package_key = plan_1', get_user_meta(102, '_mon_package_key', true), 'plan_1');
check('1ب. _mon_package_status = active', get_user_meta(102, '_mon_package_status', true), 'active');

echo "\n=== اختبار 2: مستخدم لديه باقة فعالة — حظر بلا تأكيد، نجاح مع تأكيد ===\n";
$res2_blocked = run($activate_handler, [
    'target_user_id' => 101, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tier_silver['id'],
    'reason' => 'محاولة بلا تأكيد', 'confirm_override' => 0,
]);
check('2. حظر التفعيل بلا تأكيد: success=false', $res2_blocked['success'], false);
check('2. سبب الحظر active_package_exists', $res2_blocked['data']['reason'] ?? null, 'active_package_exists');
check('2. لا تغيير على _mon_catalog_tier_id (يبقى المستوى الذهبي)', (int) get_user_meta(101, '_mon_catalog_tier_id', true), (int) $tier['id']);

$res2_confirmed = run($activate_handler, [
    'target_user_id' => 101, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tier_silver['id'],
    'reason' => 'ترقية/تخفيض بموافقة العميل', 'confirm_override' => 1,
]);
check_true('2. نجاح التفعيل مع تأكيد صريح', $res2_confirmed['success'] === true);
check('2. المستوى استُبدل فعلياً (tier_id = المستوى الفضي)', (int) get_user_meta(101, '_mon_catalog_tier_id', true), (int) $tier_silver['id']);

echo "\n=== اختبار 2ب: حارس حماية Catalog — لا يمكن تفعيل Legacy يدوياً لمستخدم مصدره الحالي catalog حتى مع التأكيد ===\n";
// هذا يثبت أن الأداة الجديدة تحترم حارس pge_is_legacy_write_allowed_for_user()
// الموجود فعلاً (المُستخدَم أيضاً من الويبهوك ومن الأداة اليدوية القديمة) —
// التأكيد (confirm_override) يُعالِج فقط تحذير "باقة فعالة"، لا يتجاوز أبداً
// حماية مصدر الحقيقة Catalog. سلوك محافظ متعمَّد، غير قابل للتفاوض.
$res2b = run($activate_handler, [
    'target_user_id' => 101, 'source' => 'legacy', 'plan_key' => 'plan_1',
    'reason' => 'محاولة تخفيض إلى Legacy', 'confirm_override' => 1,
]);
check('2ب. حظر تفعيل Legacy لمستخدم Catalog حتى مع التأكيد: success=false', $res2b['success'], false);
check('2ب. المصدر الحالي يبقى catalog بلا أي تغيير', get_user_meta(101, '_mon_package_source', true), 'catalog');

echo "\n=== اختبار 3: تطابق 100% بين المسار اليدوي واستدعاء الـService مباشرة (محاكاة Webhook) ===\n";
set_test_user(201, 'manual-path@example.test');
reset_test_user(201);
run($activate_handler, ['target_user_id' => 201, 'source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tier['id'], 'reason' => 'مطابقة', 'confirm_override' => 0]);
$manual_meta = $GLOBALS['__test_user_meta'][201];

set_test_user(202, 'webhook-path@example.test');
reset_test_user(202);
Mon_Events_Users::activate_catalog_tier(202, $plan['id'], $tier['id'], ''); // محاكاة ما يفعله class-salla-handler.php حرفياً
$webhook_meta = $GLOBALS['__test_user_meta'][202];

// credit_cycle_id مُصمَّم عمداً ليكون فريداً عند كل استدعاء تفعيل حقيقي منفصل
// (راجع توثيق generate_credit_cycle_id() في class-mon-events-users.php) — هذا
// ليس اختلافاً بين المسارين، بل سلوك صحيح متطابق في كلا المسارين (كلاهما
// يستدعي نفس الدالة الخاصة). نستبعده من مقارنة المساواة الصارمة، ونتحقق فقط
// أنه string غير فارغ في كلا الحالتين ومختلف بينهما (إثبات أنه فعلاً يُولَّد
// من جديد في كل استدعاء منفصل، لا قيمة ثابتة/مصطنعة).
$manual_cycle = $manual_meta['_mon_credit_cycle_id'] ?? null;
$webhook_cycle = $webhook_meta['_mon_credit_cycle_id'] ?? null;
unset($manual_meta['_mon_credit_cycle_id'], $webhook_meta['_mon_credit_cycle_id']);

ksort($manual_meta);
ksort($webhook_meta);
check_true('3. كل مفاتيح Meta الأخرى (باستثناء credit_cycle_id العشوائي) مطابقة تماماً بين المسار اليدوي ومسار Webhook', $manual_meta === $webhook_meta);
check_true('3. credit_cycle_id سلسلة غير فارغة في كلا المسارين', is_string($manual_cycle) && $manual_cycle !== '' && is_string($webhook_cycle) && $webhook_cycle !== '');
check_true('3. عدد مفاتيح Meta المكتوبة متطابق تماماً (' . count($manual_meta) . ' مفتاحاً)', count($manual_meta) === count($webhook_meta) && count($manual_meta) > 10);
check_true('3. المسار اليدوي لا يكتب _created_via_salla (مؤشر منشأ خاص بسلة فقط)', !array_key_exists('_created_via_salla', $manual_meta));

echo "\n=== اختبار 4: Guest Limit ===\n";
check('4. Catalog: _mon_guest_limit = 250 (من Tier)', (int) get_user_meta(201, '_mon_guest_limit', true), 250);
check('4. Legacy: _mon_guest_limit = 40 (من plan_1)', (int) get_user_meta(102, '_mon_guest_limit', true), 40);

echo "\n=== اختبار 5: Invitation Credits (Catalog فقط) ===\n";
check('5. _mon_invitation_credit_total = 120', (int) get_user_meta(201, '_mon_invitation_credit_total', true), 120);
check('5. _mon_invitation_credit_used = 0', (int) get_user_meta(201, '_mon_invitation_credit_used', true), 0);
check('5. _mon_replacement_credit_total = 15', (int) get_user_meta(201, '_mon_replacement_credit_total', true), 15);
check('5. Legacy لا يكتب رصيد دعوات إطلاقاً', get_user_meta(102, '_mon_invitation_credit_total', true), '');

echo "\n=== اختبار 6: Features ===\n";
check_true('6. _mon_package_features (Catalog Snapshot) مكتوبة فعلياً', is_array(get_user_meta(201, '_mon_package_features', true)) && count(get_user_meta(201, '_mon_package_features', true)) === count(PGE_Feature_Registry::all()));
$preview_res = run($preview_handler, ['source' => 'catalog', 'plan_id' => $plan['id'], 'tier_id' => $tier['id']]);
check_true('6. معاينة Catalog ترجع كل مفاتيح Registry', $preview_res['success'] === true && count($preview_res['data']['features']) === count(PGE_Feature_Registry::all()));
$preview_legacy = run($preview_handler, ['source' => 'legacy', 'plan_key' => 'plan_1']);
check_true('6. معاينة Legacy تعرض google_map ضمن الميزات المفعّلة (قيمتها 1)', $preview_legacy['success'] === true && in_array('google_map', array_column($preview_legacy['data']['features'], 'key'), true));

echo "\n=== اختبار 7: Audit Logging ===\n";
$audit_rows = PGE_Manual_Package_Activation_Audit::list_for_user(201);
check('7. سجل تدقيق واحد لتفعيل المستخدم 201', count($audit_rows), 1);
check('7. actor_user_id = 999 (الأدمن المنفِّذ)', (int) $audit_rows[0]['actor_user_id'], 999);
check('7. package_source = catalog', $audit_rows[0]['package_source'], 'catalog');
check('7. reason محفوظ حرفياً', $audit_rows[0]['reason'], 'مطابقة');
check_true('7. لا حقل بريد/جوال في سجل التدقيق (بلا بيانات حساسة)', !array_key_exists('email', $audit_rows[0]) && !array_key_exists('phone', $audit_rows[0]) && !array_key_exists('guest_phone', $audit_rows[0]));

$audit_rows_101 = PGE_Manual_Package_Activation_Audit::list_for_user(101);
check('7. سجلّان للمستخدم 101 (تفعيل Catalog أولي + إعادة تفعيل بمستوى آخر بتأكيد؛ محاولة Legacy المحظورة لا تُسجَّل)', count($audit_rows_101), 2);
check_true('7. السجل الأحدث أولاً (ORDER BY id DESC)', $audit_rows_101[0]['id'] > $audit_rows_101[1]['id']);
check('7. had_active_package=1 عند تسجيل تفعيل الاستبدال', (int) $audit_rows_101[0]['had_active_package'], 1);
check('7. had_active_package=0 عند التفعيل الأول لمستخدم جديد', (int) $audit_rows_101[1]['had_active_package'], 0);

echo "\n=== انحدار: حماية الصلاحية والمدخلات ===\n";
$GLOBALS['__test_is_admin'] = false;
$res_forbidden = run($activate_handler, ['target_user_id' => 101, 'source' => 'legacy', 'plan_key' => 'plan_1', 'reason' => 'x']);
check('انحدار: مستخدم بلا manage_options يُرفض', $res_forbidden['data']['reason'] ?? null, 'forbidden');
$GLOBALS['__test_is_admin'] = true;

set_test_user(301, 'no-reason@example.test');
reset_test_user(301);
$res_no_reason = run($activate_handler, ['target_user_id' => 301, 'source' => 'legacy', 'plan_key' => 'plan_1', 'reason' => '']);
check('انحدار: سبب فارغ يُرفض', $res_no_reason['data']['reason'] ?? null, 'reason_required');

$res_bad_nonce = call_ajax_handler(function () use ($activate_handler) {
    $_POST = ['nonce' => 'invalid', 'target_user_id' => 301, 'source' => 'legacy', 'plan_key' => 'plan_1', 'reason' => 'x'];
    $activate_handler();
});
check('انحدار: nonce غير صالح يُرفض', $res_bad_nonce['data']['reason'] ?? null, 'invalid_nonce');

$res_unknown_pkg = run($activate_handler, ['target_user_id' => 301, 'source' => 'legacy', 'plan_key' => 'no_such_plan', 'reason' => 'x']);
check('انحدار: باقة Legacy غير موجودة يُرفض', $res_unknown_pkg['data']['reason'] ?? null, 'not_found');

// ── ملخص ────────────────────────────────────────────────────────────────
echo "\nالنتيجة: $passed / $total نجحت.\n";
if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
