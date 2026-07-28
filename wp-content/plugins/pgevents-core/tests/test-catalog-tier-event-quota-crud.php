<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ Commit 2 من معمارية Event Quota
 * المعتمدة: normalize_event_quota_mode()/normalize_event_quota_limit() في
 * class-pge-catalog.php، ودمجهما في create_tier()/update_tier().
 *
 * نطاق هذا الملف: طبقة Catalog (Normalization + CRUD) فقط. لا Snapshot، لا
 * Activation، لا إنشاء مناسبات — تلك خارج نطاق Commit 2 تماماً.
 *
 * قسم إضافي (5) يُعيد إنتاج منطق $validate_event_quota_fields المُعرَّف
 * inline داخل pgevents-core.php (دالة إغلاق ضمن معالج POST لصفحة إدارة
 * Tiers) كدالة PHP مستقلة هنا، لأن تحميل pgevents-core.php الحقيقي في هذا
 * الاختبار غير عملي (ملف صفحة إدارية ينفّذ منطق عرض/POST فور التحميل، يعتمد
 * على عشرات دوال ووردبريس الإدارية الحقيقية — على عكس class-pge-catalog.php
 * الذي هو مكتبة صافية بلا آثار جانبية عند التحميل). هذا النسخ منطقي فقط
 * (نفس الشروط والنتائج بالضبط)، وليس require() فعلياً للملف الحقيقي — أي
 * تعديل مستقبلي على $validate_event_quota_fields في pgevents-core.php يجب
 * أن يُطابقه تعديل يدوي هنا للحفاظ على دقة هذا الاختبار.
 *
 * يحمّل includes/class-pge-catalog.php الحقيقي دون أي تعديل، مع Fake_Wpdb
 * بنفس أسلوب tests/test-catalog-tier-events-count.php (بديل كافٍ لأشكال
 * الاستعلامات الفعلية فقط، لا محرّك SQL عام — MySQL غير متاح في هذه البيئة).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل:
 *   php tests/test-catalog-tier-event-quota-crud.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب لتحميل class-pge-catalog.php الحقيقي) ──

define('ABSPATH', __DIR__ . '/');

function add_action(...$args) { /* no-op */ }
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

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── Fake $wpdb — بديل كافٍ فقط لـ INSERT/UPDATE/SELECT على mon_plan_tiers/mon_plans ──
// (نفس منطق Fake_Wpdb في tests/test-catalog-tier-events-count.php، منسوخ هنا
// لأن هذا ملف عملية PHP مستقل تماماً بمخزن ذاكرة خاص به).

class Fake_Wpdb_Event_Quota_Crud
{
    public $prefix = 'wp_';
    public $insert_id = 0;

    public $plans = [];
    public $tiers = [];

    private $plans_next_id = 1;
    private $tiers_next_id = 1;

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
            if ($m[0] === '%d') {
                return (string) (int) $val;
            }
            return "'" . addslashes((string) $val) . "'";
        }, $query);
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

$GLOBALS['wpdb'] = new Fake_Wpdb_Event_Quota_Crud();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

require_once __DIR__ . '/../includes/class-pge-catalog.php';

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
        $actual_str = var_export($actual, true);
        $expected_str = var_export($expected, true);
        echo "FAIL  $label (expected $expected_str, got $actual_str)\n";
    }
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

// ── نسخة منطقية من $validate_event_quota_fields في pgevents-core.php (راجع
// توثيق أعلى الملف لسبب عدم require()) ───────────────────────────────────

function validate_event_quota_fields_mirror($mode_raw, $limit_raw)
{
    $mode = is_string($mode_raw) ? strtolower(trim($mode_raw)) : '';
    if ($mode !== 'unlimited') {
        $mode = 'limited';
    }

    if ($mode === 'unlimited') {
        return ['valid' => true, 'message' => '', 'mode' => 'unlimited', 'limit' => '1'];
    }

    $limit_trimmed = trim((string) $limit_raw);
    if (!preg_match('/^[1-9][0-9]*$/', $limit_trimmed)) {
        return ['valid' => false, 'message' => 'invalid', 'mode' => 'limited', 'limit' => $limit_trimmed];
    }

    return ['valid' => true, 'message' => '', 'mode' => 'limited', 'limit' => $limit_trimmed];
}

// ══════════════════════════════════════════════════════════════════════
echo "=== قسم 1: normalize_event_quota_mode() ===\n";
// ══════════════════════════════════════════════════════════════════════

$mode_ref = new ReflectionMethod('PGE_Catalog', 'normalize_event_quota_mode');
$mode_ref->setAccessible(true);

check('1. \'limited\' → limited', $mode_ref->invoke(null, 'limited'), 'limited');
check('2. \'unlimited\' → unlimited', $mode_ref->invoke(null, 'unlimited'), 'unlimited');
check('3. \'UNLIMITED\' (uppercase) → unlimited (case-insensitive)', $mode_ref->invoke(null, 'UNLIMITED'), 'unlimited');
check('4. \'  unlimited  \' (مسافات) → unlimited (trim)', $mode_ref->invoke(null, '  unlimited  '), 'unlimited');
check('5. نص غير صالح (\'xyz\') → limited (تطبيع آمن، لا رفض)', $mode_ref->invoke(null, 'xyz'), 'limited');
check('6. نص فارغ (\'\') → limited', $mode_ref->invoke(null, ''), 'limited');
check('7. null → limited', $mode_ref->invoke(null, null), 'limited');
check('8. int (5) → limited (نوع خاطئ)', $mode_ref->invoke(null, 5), 'limited');
check('9. bool (true) → limited (نوع خاطئ)', $mode_ref->invoke(null, true), 'limited');
check('10. array → limited (نوع خاطئ)', $mode_ref->invoke(null, ['unlimited']), 'limited');

// ══════════════════════════════════════════════════════════════════════
echo "\n=== قسم 2: normalize_event_quota_limit() ===\n";
// ══════════════════════════════════════════════════════════════════════

$limit_ref = new ReflectionMethod('PGE_Catalog', 'normalize_event_quota_limit');
$limit_ref->setAccessible(true);

check('1. int(5) → 5', $limit_ref->invoke(null, 5), 5);
check_true('1. النتيجة من النوع int', is_int($limit_ref->invoke(null, 5)));
check('2. int(0) → 1 (صفر غير صالح لحصة مناسبات)', $limit_ref->invoke(null, 0), 1);
check('3. int(-3) → 1 (سالب)', $limit_ref->invoke(null, -3), 1);
check('4. string(\'5\') → 5', $limit_ref->invoke(null, '5'), 5);
check_true('4. النتيجة من النوع int', is_int($limit_ref->invoke(null, '5')));
check('5. string(\'\') (فارغ) → 1', $limit_ref->invoke(null, ''), 1);
check('6. string(\'0\') → 1', $limit_ref->invoke(null, '0'), 1);
check('7. string(\'-5\') (سالب نصي) → 1', $limit_ref->invoke(null, '-5'), 1);
check('8. string(\'abc\') (غير رقمي) → 1', $limit_ref->invoke(null, 'abc'), 1);
check('9. string(\'5.5\') (عشري) → 1', $limit_ref->invoke(null, '5.5'), 1);
check('10. string(\'01\') (صفر بادئ) → 1', $limit_ref->invoke(null, '01'), 1);
check('11. string(\'  7  \') (مسافات) → 7', $limit_ref->invoke(null, '  7  '), 7);
check('12. float(5.5) → 1 (نوع غير مدعوم)', $limit_ref->invoke(null, 5.5), 1);
check('13. bool(true) → 1', $limit_ref->invoke(null, true), 1);
check('14. array → 1', $limit_ref->invoke(null, [5]), 1);
check('15. null → 1', $limit_ref->invoke(null, null), 1);

// ── تجهيز باقة أساسية (mon_plans) ─────────────────────────────────────

$wpdb->seed_plan(1, ['plan_key' => 'basic_plan', 'name' => 'باقة أساسية', 'plan_type' => 'personal', 'status' => 'active']);

// ══════════════════════════════════════════════════════════════════════
echo "\n=== قسم 3: create_tier() — event_quota_mode/event_quota_limit ===\n";
// ══════════════════════════════════════════════════════════════════════

$tier_default = PGE_Catalog::create_tier([
    'plan_id' => 1, 'tier_key' => 'eq_default', 'name' => 'مستوى افتراضي',
    'price' => 100, 'currency' => 'SAR', 'salla_product_id' => null,
    'status' => 'active', 'sort_order' => 0,
    // event_quota_mode/event_quota_limit غائبان عمداً — صلب هذا السيناريو
]);
check_true('1. create_tier() نجح بلا حقول Event Quota', $tier_default !== null);
check('1. event_quota_mode الافتراضي = limited', $tier_default['event_quota_mode'] ?? null, 'limited');
check('1. event_quota_limit الافتراضي = 1', $tier_default['event_quota_limit'] ?? null, 1);
check_true('1. event_quota_limit من النوع int', is_int($tier_default['event_quota_limit'] ?? null));

$tier_unlimited = PGE_Catalog::create_tier([
    'plan_id' => 1, 'tier_key' => 'eq_unlimited', 'name' => 'مستوى غير محدود',
    'price' => 500, 'currency' => 'SAR', 'salla_product_id' => null,
    'status' => 'active', 'sort_order' => 1,
    'event_quota_mode' => 'unlimited', 'event_quota_limit' => '7',
]);
check_true('2. create_tier() نجح مع mode=unlimited', $tier_unlimited !== null);
check('2. event_quota_mode = unlimited', $tier_unlimited['event_quota_mode'] ?? null, 'unlimited');
check('2. event_quota_limit المُخزَّن = 7 (التطبيع لا يعرف عن mode، هذا متوقع)', $tier_unlimited['event_quota_limit'] ?? null, 7);

$tier_invalid_input = PGE_Catalog::create_tier([
    'plan_id' => 1, 'tier_key' => 'eq_invalid', 'name' => 'مستوى قيم غير صالحة',
    'price' => 50, 'currency' => 'SAR', 'salla_product_id' => null,
    'status' => 'active', 'sort_order' => 2,
    'event_quota_mode' => 'not_a_real_mode', 'event_quota_limit' => 'not_a_number',
]);
check_true('3. create_tier() نجح رغم قيم Event Quota غير صالحة (لا رفض — تطبيع آمن)', $tier_invalid_input !== null);
check('3. event_quota_mode غير الصالح → limited', $tier_invalid_input['event_quota_mode'] ?? null, 'limited');
check('3. event_quota_limit غير الصالح → 1', $tier_invalid_input['event_quota_limit'] ?? null, 1);

$tier_negative_limit = PGE_Catalog::create_tier([
    'plan_id' => 1, 'tier_key' => 'eq_negative', 'name' => 'مستوى رقم سالب',
    'price' => 60, 'currency' => 'SAR', 'salla_product_id' => null,
    'status' => 'active', 'sort_order' => 3,
    'event_quota_mode' => 'limited', 'event_quota_limit' => -5,
]);
check('4. event_quota_limit سالب (-5) → 1', $tier_negative_limit['event_quota_limit'] ?? null, 1);

// ══════════════════════════════════════════════════════════════════════
echo "\n=== قسم 4: update_tier() — event_quota_mode/event_quota_limit ===\n";
// ══════════════════════════════════════════════════════════════════════

// ── غياب المفتاحين كلياً → لا تغيير على القيمة الحالية ────────────────

$updated_no_touch = PGE_Catalog::update_tier($tier_unlimited['id'], [
    'plan_id' => 1, 'tier_key' => 'eq_unlimited', 'price' => 550, 'currency' => 'SAR',
    'salla_product_id' => null, 'status' => 'active', 'sort_order' => 1,
    // event_quota_mode/event_quota_limit غائبان عمداً — صلب هذا السيناريو
]);
check('1. update_tier() بدون حقول Event Quota → mode يبقى unlimited', $updated_no_touch['event_quota_mode'] ?? null, 'unlimited');
check('1. update_tier() بدون حقول Event Quota → limit يبقى 7', $updated_no_touch['event_quota_limit'] ?? null, 7);

// ── تحديث صريح إلى قيم أخرى ────────────────────────────────────────────

$updated_change = PGE_Catalog::update_tier($tier_unlimited['id'], [
    'plan_id' => 1, 'tier_key' => 'eq_unlimited', 'price' => 550, 'currency' => 'SAR',
    'salla_product_id' => null, 'status' => 'active', 'sort_order' => 1,
    'event_quota_mode' => 'limited', 'event_quota_limit' => '3',
]);
check('2. update_tier() mode=limited صراحة يغيّرها فعلاً', $updated_change['event_quota_mode'] ?? null, 'limited');
check('2. update_tier() limit=3 صراحة يغيّرها فعلاً', $updated_change['event_quota_limit'] ?? null, 3);

// ── سيناريو 5 من مواصفة Commit 2: Old Tier → Open Edit → Save بلا تغيير
// يجب أن يبقى limited/1. نحاكي هذا بإرسال نفس القيم الحالية للمستوى
// الافتراضي (limited/1) دون أي تغيير — يجب ألا يتحوّل إلى Unlimited بالخطأ.

$old_tier_resave = PGE_Catalog::update_tier($tier_default['id'], [
    'plan_id' => 1, 'tier_key' => 'eq_default', 'price' => 100, 'currency' => 'SAR',
    'salla_product_id' => null, 'status' => 'active', 'sort_order' => 0,
    'event_quota_mode' => 'limited', 'event_quota_limit' => '1',
]);
check_true('3. حفظ مستوى قديم بلا تغيير نجح', $old_tier_resave !== null);
check('3. لا تحويل عرضي إلى Unlimited — mode يبقى limited', $old_tier_resave['event_quota_mode'] ?? null, 'limited');
check('3. لا تحويل عرضي للرقم — limit يبقى 1', $old_tier_resave['event_quota_limit'] ?? null, 1);

// ── قيم غير صالحة عبر update_tier() → تطبيع آمن، بلا رفض للتحديث بأكمله ─

$updated_invalid = PGE_Catalog::update_tier($tier_default['id'], [
    'plan_id' => 1, 'tier_key' => 'eq_default', 'price' => 120, 'currency' => 'SAR',
    'salla_product_id' => null, 'status' => 'active', 'sort_order' => 0,
    'event_quota_mode' => 'garbage', 'event_quota_limit' => 'garbage',
]);
check_true('4. update_tier() نجح رغم قيم Event Quota غير صالحة', $updated_invalid !== null);
check('4. event_quota_mode غير الصالح → limited', $updated_invalid['event_quota_mode'] ?? null, 'limited');
check('4. event_quota_limit غير الصالح → 1', $updated_invalid['event_quota_limit'] ?? null, 1);
check('4. السعر تحدَّث فعلاً إلى 120 (بقية الحقول لم تتأثر)', (float) $updated_invalid['price'], 120.0);

// ── لا تأثير على events_count/invitation_credit_limit/replacement_credit_limit ─

check('5. events_count لم يتأثر بأي تعديل Event Quota أعلاه (يبقى 1، الافتراضي)', $updated_invalid['events_count'] ?? null, 1);
check('5. invitation_credit_limit لم يتأثر (يبقى 0، الافتراضي)', $updated_invalid['invitation_credit_limit'] ?? null, 0);
check('5. replacement_credit_limit لم يتأثر (يبقى 0، الافتراضي)', $updated_invalid['replacement_credit_limit'] ?? null, 0);

// ══════════════════════════════════════════════════════════════════════
echo "\n=== قسم 5: مرآة منطق \$validate_event_quota_fields (تحقّق نموذج الإدارة) ===\n";
// ══════════════════════════════════════════════════════════════════════

$r = validate_event_quota_fields_mirror('unlimited', '');
check_true('1. Unlimited + رقم فارغ → مقبول', $r['valid'] === true);

$r = validate_event_quota_fields_mirror('unlimited', '999');
check_true('2. Unlimited + رقم عشوائي → مقبول (الرقم يُتجاهَل)', $r['valid'] === true);
check('2. الخادم يُرسِل قيمة ثابتة \'1\' بغض النظر عن الرقم المُرسَل', $r['limit'], '1');

$r = validate_event_quota_fields_mirror('limited', '');
check_true('3. Limited + رقم فارغ → مرفوض', $r['valid'] === false);

$r = validate_event_quota_fields_mirror('limited', '0');
check_true('4. Limited + صفر → مرفوض', $r['valid'] === false);

$r = validate_event_quota_fields_mirror('limited', '-5');
check_true('5. Limited + سالب → مرفوض', $r['valid'] === false);

$r = validate_event_quota_fields_mirror('limited', 'abc');
check_true('6. Limited + نص غير صالح → مرفوض', $r['valid'] === false);

$r = validate_event_quota_fields_mirror('limited', '3');
check_true('7. Limited + رقم صحيح موجب → مقبول', $r['valid'] === true);
check('7. القيمة المطبَّعة = 3', $r['limit'], '3');

$r = validate_event_quota_fields_mirror('garbage_mode', '3');
check('8. mode غير صالح في الإغلاق نفسه → limited (نفس تطبيع Catalog)', $r['mode'], 'limited');

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
