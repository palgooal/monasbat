<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit) لـEntry Check-in Supervisors —
 * يغطي أربعة أجزاء من هذا الملف عبر المراحل المتعاقبة:
 *
 *   1) Registry: ترقية lifecycle لـ admin_supervisor_limit من 'planned' إلى
 *      'production' (Phase 1 Requirement 1)، بلا أي أثر على default='TBD'
 *      أو على أي ميزة أخرى.
 *   2) Schema: جدول mon_event_supervisors الجديد (Phase 1 Requirement 3) —
 *      DB_VERSION، نص Schema، قائمة دوال الترقية، ودالة upgrade_to_1_10_0()
 *      نفسها (تحقّق فعلي عبر SHOW COLUMNS/SHOW INDEX، بنفس فلسفة
 *      tests/test-catalog-schema-event-quota.php حرفياً).
 *   3) Schema: عمود invitation_token_hash (Phase 2 Requirement 3).
 *   4) Schema: جدول mon_supervisor_sessions الجديد (Phase 3 Requirement 1
 *      — "Supervisor Authentication"، راجع includes/class-pge-supervisor-
 *      session.php).
 *
 * Resolver والتكامل مع Snapshot والانحدار على Event Quota/Invitation Credits
 * مغطاة في ملف منفصل (tests/test-supervisor-quota-resolver.php) — Fake $wpdb
 * مختلف تماماً هناك (يحتاج prepare()/get_results()/insert() الحقيقية لتشغيل
 * activate_catalog_tier()، بخلاف Fake بسيط هنا يكفي فقط لـSHOW COLUMNS/INDEX)،
 * بنفس سبب الفصل المُتَّبع فعلاً بين test-catalog-schema-event-quota.php
 * وtest-event-quota-snapshot.php.
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: php tests/test-supervisor-schema.php
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── Stubs عامة لووردبريس (الحد الأدنى المطلوب لتحميل الملفين الحقيقيين) ────

define('ABSPATH', __DIR__ . '/');

function add_action(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ── Fake $wpdb — بديل كافٍ فقط لـ SHOW COLUMNS FROM / SHOW INDEX FROM
// mon_event_supervisors (نفس فلسفة Fake_Wpdb_Event_Quota في
// test-catalog-schema-event-quota.php، مع إضافة SHOW INDEX هنا لأن
// upgrade_to_1_10_0() يتحقق أيضاً من القيد UNIQUE). ──────────────────────────

class Fake_Wpdb_Supervisor_Schema
{
    public $prefix = 'wp_';

    /** @var array<int, string>|null تجاوز اختياري لأعمدة SHOW COLUMNS */
    public $show_columns_override = null;

    /** @var array<int, array>|null تجاوز اختياري لصفوف SHOW INDEX */
    public $show_index_override = null;

    public function get_charset_collate()
    {
        return '';
    }

    public function get_results($sql, $output = null)
    {
        if (stripos($sql, 'SHOW COLUMNS FROM') === 0) {
            $columns = $this->show_columns_override ?? [
                'id', 'event_id', 'user_id', 'supervisor_phone', 'supervisor_name',
                'status', 'invitation_token_hash', 'invited_by_user_id', 'invited_at',
                'accepted_at', 'revoked_at', 'created_at', 'updated_at',
            ];
            $out = [];
            foreach ($columns as $field) {
                $out[] = ['Field' => $field];
            }
            return $out;
        }

        if (stripos($sql, 'SHOW INDEX FROM') === 0) {
            return $this->show_index_override ?? [
                ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Non_unique' => 0],
                ['Key_name' => 'event_id', 'Seq_in_index' => 1, 'Column_name' => 'event_id', 'Non_unique' => 1],
                ['Key_name' => 'user_id', 'Seq_in_index' => 1, 'Column_name' => 'user_id', 'Non_unique' => 1],
                ['Key_name' => 'event_status', 'Seq_in_index' => 1, 'Column_name' => 'event_id', 'Non_unique' => 1],
                ['Key_name' => 'event_status', 'Seq_in_index' => 2, 'Column_name' => 'status', 'Non_unique' => 1],
                // event_phone: فهرس عادي (Non_unique = 1) بعد التصحيح المعماري
                // — لم يعد UNIQUE (كان event_supervisor_phone سابقاً)، ليسمح
                // بتاريخ Append-Only لعدة إسنادات لنفس (event_id, supervisor_phone).
                ['Key_name' => 'event_phone', 'Seq_in_index' => 1, 'Column_name' => 'event_id', 'Non_unique' => 1],
                ['Key_name' => 'event_phone', 'Seq_in_index' => 2, 'Column_name' => 'supervisor_phone', 'Non_unique' => 1],
                // invitation_token_hash (Phase 2): UNIQUE فعلياً (Non_unique = 0)
                // — تفرّد أمني على التوكن نفسه، لا علاقة له بقيد (event_id,
                // supervisor_phone) المُزال معمارياً أعلاه.
                ['Key_name' => 'invitation_token_hash', 'Seq_in_index' => 1, 'Column_name' => 'invitation_token_hash', 'Non_unique' => 0],
            ];
        }

        return [];
    }
}

$GLOBALS['wpdb'] = new Fake_Wpdb_Supervisor_Schema();
global $wpdb;
$wpdb = $GLOBALS['wpdb'];

require_once __DIR__ . '/../includes/class-pge-feature-registry.php';
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

// ============================================================================
// جزء 1: Registry — ترقية lifecycle لـ admin_supervisor_limit فقط
// ============================================================================
echo "=== جزء 1: Registry (admin_supervisor_limit lifecycle → production) ===\n";

$def = PGE_Feature_Registry::get('admin_supervisor_limit');

check_true('1. admin_supervisor_limit لا يزال معرَّفاً في Registry', $def !== null);
check("2. admin_supervisor_limit['lifecycle'] = 'production' (Requirement 1)", $def['lifecycle'] ?? null, 'production');
check("3. admin_supervisor_limit['default'] لا يزال 'TBD' (لم يُخترَع رقم، §10 لا يزال معلَّقاً)", $def['default'] ?? null, 'TBD');
check("4. admin_supervisor_limit['type'] لم يتغيّر = integer", $def['type'] ?? null, 'integer');
check("5. admin_supervisor_limit['category'] لم يتغيّر = credits_and_limits", $def['category'] ?? null, 'credits_and_limits');

// خارج النطاق تماماً — يجب ألا تتأثر أي ميزة أخرى بهذا التعديل
$host_limit_def = PGE_Feature_Registry::get('host_limit');
$invitation_design_def = PGE_Feature_Registry::get('invitation_design_limit');
check("6. host_limit lifecycle يبقى 'planned' (خارج النطاق، لم يُلمَس)", $host_limit_def['lifecycle'] ?? null, 'planned');
check("7. invitation_design_limit lifecycle يبقى 'planned' (خارج النطاق، لم يُلمَس)", $invitation_design_def['lifecycle'] ?? null, 'planned');

check_true('8. عدد ميزات Registry لا يزال 19 بالضبط (لا إضافة/حذف ميزة)', count(PGE_Feature_Registry::all()) === 19);

// ============================================================================
// جزء 2: Schema — جدول mon_event_supervisors (Requirement 3)
// ============================================================================
echo "\n=== جزء 2: Schema (mon_event_supervisors) ===\n";

// ملاحظة: DB_VERSION تقدَّم لاحقاً إلى 1.11.0 (Phase 2 — invitation_token_hash)
// — القيمة النهائية الفعلية تُختبَر في جزء 3 أدناه (تأكيد 26). هذا التأكيد هنا
// يبقى لتوثيق تسلسل الترقية فقط، لا لتثبيت رقم إصدار نهائي مبكراً.
check_true('9. Mon_Catalog_Schema::DB_VERSION على الأقل 1.10.0 (سلسلة الترقية تشمل جدول المشرفين)', version_compare(Mon_Catalog_Schema::DB_VERSION, '1.10.0', '>='));

$schema_sql_ref = new ReflectionMethod('Mon_Catalog_Schema', 'get_schema_sql');
$schema_sql_ref->setAccessible(true);
$schema_sql_parts = $schema_sql_ref->invoke(null);

check_true('10. عدد أجزاء SQL المُعادة من get_schema_sql() أصبح 7 على الأقل (كان 7 بالضبط وقت كتابة هذا التأكيد؛ تقادم إلى 8 لاحقاً في Phase 3 — راجع تأكيد 37 أدناه للقيمة النهائية الفعلية)', count($schema_sql_parts) >= 7);

$supervisors_sql = $schema_sql_parts[6] ?? '';
check_true('11. الجزء السابع هو جدول mon_event_supervisors', strpos($supervisors_sql, 'wp_mon_event_supervisors') !== false);
check_true('12. عمود event_id موجود (BIGINT UNSIGNED NOT NULL)', strpos($supervisors_sql, 'event_id BIGINT(20) UNSIGNED NOT NULL') !== false);
check_true('13. عمود user_id موجود ويقبل NULL (لم يُربَط بمستخدم بعد)', strpos($supervisors_sql, 'user_id BIGINT(20) UNSIGNED NULL') !== false);
check_true('14. عمود status VARCHAR(20) DEFAULT invited (لا ENUM)', strpos($supervisors_sql, "status VARCHAR(20) NOT NULL DEFAULT 'invited'") !== false);
check_true('15. لا ENUM في نص Schema الجديد (يطابق قرار المشروع الصريح)', stripos($supervisors_sql, 'ENUM') === false);
// ملاحظة (Phase 2): عمود invite_token (بهذا الاسم الحرفي القصير من الوثيقة
// المعمارية الأصلية) لا يزال غير موجود — Phase 2 أضافت invitation_token_hash
// بدلاً منه (هاش فقط، لا توكن خام)، تماماً وفق Requirement 3. هذا التأكيد
// يبقى صحيحاً دون أي تعديل لأن الاسمين مختلفان حرفياً (لا تطابق جزئي).
check_true('16. لا عمود invite_token الخام (الاسم القديم من الوثيقة المعمارية) — الموجود فعلياً هو invitation_token_hash فقط', strpos($supervisors_sql, 'invite_token') === false);
// ── تصحيح معماري (Append-Only History، قبل موافقة Phase 1): لا يجوز وجود
// أي قيد UNIQUE على (event_id, supervisor_phone) تحديداً — قيد كهذا يمنع
// إعادة دعوة نفس الهاتف بعد أن يصبح إسناده السابق revoked/expired، بما
// يخالف القاعدة "دعوة جديدة = صف جديد دائماً". فهرس عادي فقط (event_phone)
// يحل محله. (Phase 2 أضافت لاحقاً UNIQUE KEY invitation_token_hash — قيد
// مختلف تماماً على عمود مختلف تماماً، لا علاقة له بهذا التحقق إطلاقاً.)
check_true('17. لا يوجد قيد UNIQUE على (event_id, supervisor_phone) تحديداً (تصحيح معماري)', strpos($supervisors_sql, 'UNIQUE KEY event_supervisor_phone') === false && strpos($supervisors_sql, 'UNIQUE KEY event_phone') === false);
check_true("17ب. فهرس عادي (غير فريد) event_phone (event_id, supervisor_phone) موجود بدلاً منه", strpos($supervisors_sql, 'KEY event_phone (event_id, supervisor_phone)') !== false);
check_true('18. لا FOREIGN KEY فعلية (بنفس نمط بقية جداول هذا الملف)', stripos($supervisors_sql, 'FOREIGN KEY') === false);

// الجداول الستة الأخرى لم تتأثر إطلاقاً (نطاق التعديل: جدول جديد واحد فقط)
check_true('19. mon_plans لا يحتوي event_id', strpos($schema_sql_parts[0] ?? '', 'event_id') === false);
check_true('19. mon_plan_tiers لا يحتوي supervisor_phone', strpos($schema_sql_parts[1] ?? '', 'supervisor_phone') === false);
check_true('19. mon_services لا يحتوي supervisor_phone', strpos($schema_sql_parts[2] ?? '', 'supervisor_phone') === false);
check_true('19. mon_invitation_credit_ledger لا يحتوي supervisor_phone', strpos($schema_sql_parts[3] ?? '', 'supervisor_phone') === false);
check_true('19. mon_replacement_entitlements لا يحتوي supervisor_phone', strpos($schema_sql_parts[4] ?? '', 'supervisor_phone') === false);
check_true('19. mon_tier_features لا يحتوي supervisor_phone', strpos($schema_sql_parts[5] ?? '', 'supervisor_phone') === false);

// قائمة دوال الترقية
$routines_ref = new ReflectionMethod('Mon_Catalog_Schema', 'get_upgrade_routines');
$routines_ref->setAccessible(true);
$routines = $routines_ref->invoke(null);

check_true('20. مفتاح 1.10.0 موجود في get_upgrade_routines()', array_key_exists('1.10.0', $routines));
check('20. 1.10.0 مرتبط بـ upgrade_to_1_10_0()', $routines['1.10.0'] ?? null, ['Mon_Catalog_Schema', 'upgrade_to_1_10_0']);
check_true('20ب. مفتاح 1.9.0 السابق لا يزال موجوداً (لم يُحذف عن طريق الخطأ)', array_key_exists('1.9.0', $routines));

// upgrade_to_1_10_0(): تحقّق فعلي (SHOW COLUMNS + SHOW INDEX) لا افتراض أعمى
$upgrade_ref = new ReflectionMethod('Mon_Catalog_Schema', 'upgrade_to_1_10_0');
$upgrade_ref->setAccessible(true);

$wpdb->show_columns_override = null;
$wpdb->show_index_override = null;
check_true('21. upgrade_to_1_10_0() يعيد true عندما تكون البنية كاملة', $upgrade_ref->invoke(null) === true);

$wpdb->show_columns_override = ['id', 'event_id', 'status']; // أعمدة ناقصة عمداً
check_true('22. upgrade_to_1_10_0() يعيد false عند نقص أعمدة (user_id/supervisor_phone/...)', $upgrade_ref->invoke(null) === false);

$wpdb->show_columns_override = null; // الأعمدة كاملة مجدداً
$wpdb->show_index_override = [
    ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Non_unique' => 0],
    // الفهرس event_phone مفقود عمداً هنا
];
check_true('23. upgrade_to_1_10_0() يعيد false عند نقص الفهرس event_phone (event_id, supervisor_phone)', $upgrade_ref->invoke(null) === false);

$wpdb->show_index_override = [
    ['Key_name' => 'event_phone', 'Seq_in_index' => 1, 'Column_name' => 'supervisor_phone', 'Non_unique' => 1], // ترتيب معكوس
    ['Key_name' => 'event_phone', 'Seq_in_index' => 2, 'Column_name' => 'event_id', 'Non_unique' => 1],
];
check_true('24. upgrade_to_1_10_0() يعيد false عند انعكاس ترتيب أعمدة الفهرس event_phone', $upgrade_ref->invoke(null) === false);

// ── تصحيح معماري: التحقق يجب أن يرفض أيضاً أي رجوع غير مقصود لجعل الفهرس
// فريداً مجدداً (Non_unique = 0) — حتى لو كانت الأعمدة والترتيب صحيحين تماماً.
$wpdb->show_index_override = [
    ['Key_name' => 'event_phone', 'Seq_in_index' => 1, 'Column_name' => 'event_id', 'Non_unique' => 0],
    ['Key_name' => 'event_phone', 'Seq_in_index' => 2, 'Column_name' => 'supervisor_phone', 'Non_unique' => 0],
];
check_true('24ب. upgrade_to_1_10_0() يعيد false إذا كان event_phone فريداً (Non_unique=0) رغم الأعمدة الصحيحة — يمنع رجوع القيد القديم', $upgrade_ref->invoke(null) === false);

$wpdb->show_columns_override = null;
$wpdb->show_index_override = null;
check_true('25. Idempotency — استدعاء أول يعيد true', $upgrade_ref->invoke(null) === true);
check_true('25. Idempotency — استدعاء ثانٍ متكرر يعيد نفس النتيجة true', $upgrade_ref->invoke(null) === true);
check_true('25. Idempotency — استدعاء ثالث متكرر يعيد نفس النتيجة true', $upgrade_ref->invoke(null) === true);

// ============================================================================
// جزء 3: Schema — invitation_token_hash (Phase 2، Requirement 3)
// ============================================================================
echo "\n=== جزء 3: Schema (invitation_token_hash — Phase 2) ===\n";

check_true('26. Mon_Catalog_Schema::DB_VERSION على الأقل 1.11.0 (كان 1.11.0 بالضبط وقت كتابة هذا التأكيد؛ تقادم إلى 1.12.0 لاحقاً في Phase 3 — راجع تأكيد 36 أدناه للقيمة النهائية الفعلية)', version_compare(Mon_Catalog_Schema::DB_VERSION, '1.11.0', '>='));

$schema_sql_parts_p2 = $schema_sql_ref->invoke(null);
$supervisors_sql_p2 = $schema_sql_parts_p2[6] ?? '';
check_true('27. عمود invitation_token_hash موجود (VARCHAR(64) NULL)', strpos($supervisors_sql_p2, 'invitation_token_hash VARCHAR(64) NULL') !== false);
check_true('28. القيد UNIQUE على invitation_token_hash موجود (تفرّد أمني، لا علاقة له بـ event_phone)', strpos($supervisors_sql_p2, 'UNIQUE KEY invitation_token_hash (invitation_token_hash)') !== false);
check_true('29. التوكن الخام نفسه غير مخزَّن كعمود (لا عمود raw_token/invitation_token بلا hash)', strpos($supervisors_sql_p2, 'invitation_token VARCHAR') === false);

check_true('30. مفتاح 1.11.0 موجود في get_upgrade_routines()', array_key_exists('1.11.0', $routines_ref->invoke(null)));
check('30. 1.11.0 مرتبط بـ upgrade_to_1_11_0()', $routines_ref->invoke(null)['1.11.0'] ?? null, ['Mon_Catalog_Schema', 'upgrade_to_1_11_0']);

$upgrade_111_ref = new ReflectionMethod('Mon_Catalog_Schema', 'upgrade_to_1_11_0');
$upgrade_111_ref->setAccessible(true);

$wpdb->show_columns_override = null;
$wpdb->show_index_override = null;
check_true('31. upgrade_to_1_11_0() يعيد true عندما تكون البنية كاملة', $upgrade_111_ref->invoke(null) === true);

$wpdb->show_columns_override = ['id', 'event_id', 'status']; // invitation_token_hash مفقود عمداً
check_true('32. upgrade_to_1_11_0() يعيد false عند نقص عمود invitation_token_hash', $upgrade_111_ref->invoke(null) === false);

$wpdb->show_columns_override = null;
$wpdb->show_index_override = [
    ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Non_unique' => 0],
    // فهرس invitation_token_hash مفقود عمداً هنا
];
check_true('33. upgrade_to_1_11_0() يعيد false عند نقص فهرس invitation_token_hash كاملاً', $upgrade_111_ref->invoke(null) === false);

$wpdb->show_index_override = [
    ['Key_name' => 'invitation_token_hash', 'Seq_in_index' => 1, 'Column_name' => 'invitation_token_hash', 'Non_unique' => 1], // غير فريد بالخطأ
];
check_true('34. upgrade_to_1_11_0() يعيد false إذا كان invitation_token_hash غير فريد (Non_unique=1) — يجب أن يكون UNIQUE فعلياً', $upgrade_111_ref->invoke(null) === false);

$wpdb->show_columns_override = null;
$wpdb->show_index_override = null;
check_true('35. Idempotency — استدعاء أول يعيد true', $upgrade_111_ref->invoke(null) === true);
check_true('35. Idempotency — استدعاء ثانٍ متكرر يعيد نفس النتيجة true', $upgrade_111_ref->invoke(null) === true);

// ============================================================================
// جزء 4: Schema — mon_supervisor_sessions (Phase 3، Requirement 1)
// ============================================================================
echo "\n=== جزء 4: Schema (mon_supervisor_sessions — Phase 3) ===\n";

check('36. Mon_Catalog_Schema::DB_VERSION = 1.12.0', Mon_Catalog_Schema::DB_VERSION, '1.12.0');

$schema_sql_parts_p3 = $schema_sql_ref->invoke(null);
check_true('37. عدد أجزاء SQL أصبح 8 (جدول جلسات جديد واحد)', count($schema_sql_parts_p3) === 8);

$sessions_sql = $schema_sql_parts_p3[7] ?? '';
check_true('38. الجزء الثامن هو جدول mon_supervisor_sessions', strpos($sessions_sql, 'wp_mon_supervisor_sessions') !== false);
check_true('39. عمود assignment_id موجود (BIGINT UNSIGNED NOT NULL)', strpos($sessions_sql, 'assignment_id BIGINT(20) UNSIGNED NOT NULL') !== false);
check_true('40. عمود event_id موجود (denormalized)', strpos($sessions_sql, 'event_id BIGINT(20) UNSIGNED NOT NULL') !== false);
check_true('41. عمود session_token_hash VARCHAR(64) NOT NULL (لا يقبل NULL — بخلاف invitation_token_hash)', strpos($sessions_sql, 'session_token_hash VARCHAR(64) NOT NULL') !== false);
check_true('42. القيد UNIQUE على session_token_hash موجود', strpos($sessions_sql, 'UNIQUE KEY session_token_hash (session_token_hash)') !== false);
check_true('43. عمود expires_at موجود (NOT NULL — دائماً صريح)', strpos($sessions_sql, 'expires_at DATETIME NOT NULL') !== false);
check_true('44. عمود revoked_at موجود ويقبل NULL (Logout صريح فقط)', strpos($sessions_sql, 'revoked_at DATETIME NULL') !== false);
check_true('45. لا التوكن الخام كعمود (لا session_token/raw_token بلا hash)', strpos($sessions_sql, 'session_token VARCHAR') === false && strpos($sessions_sql, 'raw_token') === false);
check_true('46. لا ENUM في نص Schema الجديد', stripos($sessions_sql, 'ENUM') === false);
check_true('47. لا FOREIGN KEY فعلية', stripos($sessions_sql, 'FOREIGN KEY') === false);

// الجداول السبعة الأخرى لم تتأثر إطلاقاً (نطاق التعديل: جدول جديد واحد فقط)
check_true('48. mon_event_supervisors (الجزء السابع) لا يزال كما هو، لا تعديل عليه', strpos($schema_sql_parts_p3[6] ?? '', 'wp_mon_event_supervisors') !== false && strpos($schema_sql_parts_p3[6] ?? '', 'session_token_hash') === false);

check_true('49. مفتاح 1.12.0 موجود في get_upgrade_routines()', array_key_exists('1.12.0', $routines_ref->invoke(null)));
check('49. 1.12.0 مرتبط بـ upgrade_to_1_12_0()', $routines_ref->invoke(null)['1.12.0'] ?? null, ['Mon_Catalog_Schema', 'upgrade_to_1_12_0']);

$upgrade_112_ref = new ReflectionMethod('Mon_Catalog_Schema', 'upgrade_to_1_12_0');
$upgrade_112_ref->setAccessible(true);

$full_session_columns = [
    'id', 'assignment_id', 'event_id', 'session_token_hash',
    'issued_at', 'expires_at', 'revoked_at', 'created_at', 'updated_at',
];
$full_session_index = [
    ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Non_unique' => 0],
    ['Key_name' => 'session_token_hash', 'Seq_in_index' => 1, 'Column_name' => 'session_token_hash', 'Non_unique' => 0],
    ['Key_name' => 'assignment_id', 'Seq_in_index' => 1, 'Column_name' => 'assignment_id', 'Non_unique' => 1],
    ['Key_name' => 'event_id', 'Seq_in_index' => 1, 'Column_name' => 'event_id', 'Non_unique' => 1],
];

$wpdb->show_columns_override = $full_session_columns;
$wpdb->show_index_override = $full_session_index;
check_true('50. upgrade_to_1_12_0() يعيد true عندما تكون البنية كاملة', $upgrade_112_ref->invoke(null) === true);

$wpdb->show_columns_override = ['id', 'assignment_id', 'event_id']; // session_token_hash/expires_at/... مفقودة عمداً
check_true('51. upgrade_to_1_12_0() يعيد false عند نقص أعمدة', $upgrade_112_ref->invoke(null) === false);

$wpdb->show_columns_override = $full_session_columns;
$wpdb->show_index_override = [
    ['Key_name' => 'PRIMARY', 'Seq_in_index' => 1, 'Column_name' => 'id', 'Non_unique' => 0],
    // فهرس session_token_hash مفقود عمداً هنا
];
check_true('52. upgrade_to_1_12_0() يعيد false عند نقص فهرس session_token_hash كاملاً', $upgrade_112_ref->invoke(null) === false);

$wpdb->show_index_override = [
    ['Key_name' => 'session_token_hash', 'Seq_in_index' => 1, 'Column_name' => 'session_token_hash', 'Non_unique' => 1], // غير فريد بالخطأ
];
check_true('53. upgrade_to_1_12_0() يعيد false إذا كان session_token_hash غير فريد (Non_unique=1) — يجب أن يكون UNIQUE فعلياً', $upgrade_112_ref->invoke(null) === false);

$wpdb->show_columns_override = $full_session_columns;
$wpdb->show_index_override = $full_session_index;
check_true('54. Idempotency — استدعاء أول يعيد true', $upgrade_112_ref->invoke(null) === true);
check_true('54. Idempotency — استدعاء ثانٍ متكرر يعيد نفس النتيجة true', $upgrade_112_ref->invoke(null) === true);

$wpdb->show_columns_override = null;
$wpdb->show_index_override = null;

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
