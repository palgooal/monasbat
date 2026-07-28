<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـ Commit 1 من معمارية Event Quota
 * المعتمدة (راجع محادثة التصميم النهائية — "Architecture Review – Final
 * Approval Revision"): إضافة عمودَي event_quota_mode/event_quota_limit إلى
 * mon_plan_tiers فقط.
 *
 * نطاق هذا الملف يطابق نطاق Commit 1 حرفياً: Schema فقط. لا يختبر CRUD
 * (create_tier/update_tier لا تتعامل مع هذين العمودين بعد — ذلك Commit 2)،
 * ولا Snapshot، ولا إنشاء المناسبات — أي منها خارج نطاق هذا التنفيذ تماماً.
 *
 * يحمّل includes/class-mon-catalog-schema.php الحقيقي دون أي تعديل، مع
 * Fake_Wpdb صغير يكفي فقط لمحاكاة SHOW COLUMNS FROM (الاستعلام الوحيد الذي
 * يُصدره upgrade_to_1_9_0()، بنفس فلسفة upgrade_to_1_4_0()/upgrade_to_1_8_0()
 * التي تسبقه) — لا محرّك SQL عام، ولا حاجة لمحاكاة CREATE TABLE/dbDelta()
 * الفعلية (غير متاحة بلا خادم MySQL في هذه البيئة؛ راجع نفس القيد الموثَّق في
 * tests/test-catalog-tier-events-count.php).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل:
 *   php tests/test-catalog-schema-event-quota.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب لتحميل الملف الحقيقي) ────────

define('ABSPATH', __DIR__ . '/');

function add_action(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op: لا تفعيل حقيقي هنا */ }

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── Fake $wpdb — بديل كافٍ فقط لـ SHOW COLUMNS FROM mon_plan_tiers ─────────

class Fake_Wpdb_Event_Quota
{
    public $prefix = 'wp_';

    /** @var array<int, string>|null تجاوز اختياري لأعمدة SHOW COLUMNS (لمحاكاة بنية ناقصة/كاملة) */
    public $show_columns_override = null;

    public function get_charset_collate()
    {
        return '';
    }

    public function get_results($sql, $output = null)
    {
        if (stripos($sql, 'SHOW COLUMNS FROM') === 0) {
            $columns = $this->show_columns_override ?? [
                'id', 'plan_id', 'tier_key', 'name', 'guest_limit', 'events_count',
                'host_photos_limit', 'wa_messages_limit',
                'invitation_credit_limit', 'replacement_credit_limit',
                'event_quota_mode', 'event_quota_limit',
                'price', 'currency', 'salla_product_id', 'salla_sku', 'salla_url',
                'sort_order', 'status', 'created_at', 'updated_at',
            ];
            $out = [];
            foreach ($columns as $field) {
                $out[] = ['Field' => $field];
            }
            return $out;
        }

        return [];
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Event_Quota();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

require_once __DIR__ . '/../includes/class-mon-catalog-schema.php';

// ── أدوات الاختبار (نفس نمط check()/check_true() في بقية اختبارات المشروع) ─

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

echo "=== Commit 1: Event Quota Schema (event_quota_mode/event_quota_limit) ===\n";

// ── 1) DB_VERSION رُفع فعلياً إلى 1.9.0 ────────────────────────────────────

check('1. Mon_Catalog_Schema::DB_VERSION = 1.9.0', Mon_Catalog_Schema::DB_VERSION, '1.9.0');

// ── 2) نص Schema يحتوي العمودين الجديدين بالتعريف الصحيح تماماً ───────────

$schema_sql_ref = new ReflectionMethod('Mon_Catalog_Schema', 'get_schema_sql');
$schema_sql_ref->setAccessible(true);
$schema_sql_parts = $schema_sql_ref->invoke(null);
$tiers_sql = $schema_sql_parts[1] ?? '';

check_true('2. mon_plan_tiers هو الجدول الثاني في get_schema_sql() (نفس ترتيب events_count/invitation_credit_limit)', strpos($tiers_sql, 'CREATE TABLE') !== false && strpos($tiers_sql, 'tier_key') !== false);
check_true('2. عمود event_quota_mode موجود في نص Schema', strpos($tiers_sql, 'event_quota_mode') !== false);
check_true('2. عمود event_quota_limit موجود في نص Schema', strpos($tiers_sql, 'event_quota_limit') !== false);

check_true('3. event_quota_mode: VARCHAR(20) NOT NULL DEFAULT \'limited\' حرفياً (لا ENUM)', strpos($tiers_sql, "event_quota_mode VARCHAR(20) NOT NULL DEFAULT 'limited'") !== false);
check_true('3. لا يوجد ENUM في أي مكان من نص Schema (يطابق قرار المشروع الصريح)', stripos($tiers_sql, 'ENUM') === false);

check_true('4. event_quota_limit: INT UNSIGNED NOT NULL DEFAULT 1 حرفياً (رقم دائماً، لا NULL)', strpos($tiers_sql, 'event_quota_limit INT UNSIGNED NOT NULL DEFAULT 1') !== false);

// ── 5) لا تعديل على أي عمود موجود مسبقاً في نفس الجدول ─────────────────────

check_true('5. events_count لم يتأثر (لا يزال NULL-able DEFAULT 1، بلا تغيير)', strpos($tiers_sql, 'events_count INT UNSIGNED NULL DEFAULT 1') !== false);
check_true('5. invitation_credit_limit لم يتأثر (لا يزال DEFAULT 0)', strpos($tiers_sql, 'invitation_credit_limit INT UNSIGNED NOT NULL DEFAULT 0') !== false);
check_true('5. replacement_credit_limit لم يتأثر (لا يزال DEFAULT 0)', strpos($tiers_sql, 'replacement_credit_limit INT UNSIGNED NOT NULL DEFAULT 0') !== false);

// ── 6) الجداول الخمسة الأخرى لم تتأثر إطلاقاً (نطاق التعديل: mon_plan_tiers فقط) ─

check_true('6. عدد أجزاء SQL المُعادة من get_schema_sql() لا يزال 6 (لم يُضَف جدول جديد)', count($schema_sql_parts) === 6);
check_true('6. mon_plans (الجزء الأول) لا يحتوي event_quota_mode', strpos($schema_sql_parts[0] ?? '', 'event_quota_mode') === false);
check_true('6. mon_services لا يحتوي event_quota_mode', strpos($schema_sql_parts[2] ?? '', 'event_quota_mode') === false);
check_true('6. mon_invitation_credit_ledger لا يحتوي event_quota_mode', strpos($schema_sql_parts[3] ?? '', 'event_quota_mode') === false);
check_true('6. mon_replacement_entitlements لا يحتوي event_quota_mode', strpos($schema_sql_parts[4] ?? '', 'event_quota_mode') === false);
check_true('6. mon_tier_features لا يحتوي event_quota_mode', strpos($schema_sql_parts[5] ?? '', 'event_quota_mode') === false);

// ── 7) قائمة دوال الترقية تتضمن 1.9.0 مرتبطة بالدالة الصحيحة ───────────────

$routines_ref = new ReflectionMethod('Mon_Catalog_Schema', 'get_upgrade_routines');
$routines_ref->setAccessible(true);
$routines = $routines_ref->invoke(null);

check_true('7. مفتاح 1.9.0 موجود في get_upgrade_routines()', array_key_exists('1.9.0', $routines));
check('7. 1.9.0 مرتبط بـ upgrade_to_1_9_0()', $routines['1.9.0'] ?? null, ['Mon_Catalog_Schema', 'upgrade_to_1_9_0']);

// ── 8) upgrade_to_1_9_0(): تحقّق فعلي (SHOW COLUMNS) لا افتراض أعمى ─────────

$upgrade_190_ref = new ReflectionMethod('Mon_Catalog_Schema', 'upgrade_to_1_9_0');
$upgrade_190_ref->setAccessible(true);

$wpdb->show_columns_override = null; // القائمة الافتراضية الكاملة (تتضمن العمودين الجديدين)
check_true('8. upgrade_to_1_9_0() يعيد true عندما يكون العمودان موجودين فعلاً', $upgrade_190_ref->invoke(null) === true);

$wpdb->show_columns_override = ['id', 'plan_id', 'tier_key', 'name', 'events_count', 'event_quota_mode']; // event_quota_limit ناقص عمداً
check_true('8. upgrade_to_1_9_0() يعيد false عند نقص event_quota_limit فقط', $upgrade_190_ref->invoke(null) === false);

$wpdb->show_columns_override = ['id', 'plan_id', 'tier_key', 'name', 'events_count', 'event_quota_limit']; // event_quota_mode ناقص عمداً
check_true('8. upgrade_to_1_9_0() يعيد false عند نقص event_quota_mode فقط', $upgrade_190_ref->invoke(null) === false);

$wpdb->show_columns_override = ['id', 'plan_id', 'tier_key', 'name']; // كلا العمودين ناقص
check_true('8. upgrade_to_1_9_0() يعيد false عند نقص كلا العمودين', $upgrade_190_ref->invoke(null) === false);

// ── 9) Idempotency: تكرار الاستدعاء بعد اكتمال البنية يبقى true، بلا أي كتابة ─

$wpdb->show_columns_override = null;
check_true('9. upgrade_to_1_9_0() Idempotent — استدعاء أول يعيد true', $upgrade_190_ref->invoke(null) === true);
check_true('9. upgrade_to_1_9_0() Idempotent — استدعاء ثانٍ متكرر يعيد نفس النتيجة true', $upgrade_190_ref->invoke(null) === true);
check_true('9. upgrade_to_1_9_0() Idempotent — استدعاء ثالث متكرر يعيد نفس النتيجة true', $upgrade_190_ref->invoke(null) === true);

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
