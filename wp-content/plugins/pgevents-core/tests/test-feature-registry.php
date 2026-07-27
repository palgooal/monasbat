<?php
/**
 * اختبار مركّز وقائم بذاته (بلا PHPUnit، بنفس نمط بقية ملفات tests/ في هذا
 * المشروع) لـ Phase 1 — Commit 2، وفق docs/FEATURES-PHASE-1-SPEC.md §9
 * (Commit 2) و§11 (Testing Checklist).
 *
 * يختبر includes/class-pge-feature-registry.php الحقيقي فقط، دون أي تعديل
 * عليه. لا اعتماد على قاعدة بيانات، جداول ووردبريس، Catalog، Tier Features،
 * Resolver، Snapshot، لوحة إدارة، أو أي صفحة أمامية — Registry طبقة بيانات
 * ثابتة معزولة بالكامل (§0/§6 من الوثيقة المعمارية)، ولا يتطلب تحميلها أي
 * Stub أبعد من ثابت ABSPATH نفسه (الحارس الوحيد أعلى الملف الحقيقي).
 *
 * التشغيل:
 *   php tests/test-feature-registry.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

// ── الحد الأدنى المطلق لتحميل الملف الحقيقي: فقط ABSPATH (الحارس الوحيد في
// أعلى class-pge-feature-registry.php). لا Stubs أخرى مطلوبة إطلاقاً — لا
// $wpdb، لا add_action، لا أي دالة ووردبريس أخرى، لأن الملف الحقيقي لا
// يستدعي أياً منها. ──────────────────────────────────────────────────────

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../includes/class-pge-feature-registry.php';

// ── أدوات الاختبار (نفس نمط check()/check_true() في بقية ملفات tests/) ────

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

// ── الحقول الثمانية المعتمدة حصراً (PACKAGE-FEATURE-MATRIX.md §6) ──────────

$expected_field_names = [
    'key', 'type', 'default', 'category', 'admin_label',
    'description', 'validation', 'lifecycle',
];
sort($expected_field_names);

// ── مفاتيح الميزات التسعة عشر المعتمدة حصراً (§6)، بلا اختراع أي مفتاح جديد ─

$expected_feature_keys = [
    'host_limit',
    'admin_supervisor_limit',
    'invitation_design_limit',
    'event_website',
    'google_maps',
    'guest_qr',
    'rsvp',
    'attendance_statistics',
    'guest_comments',
    'event_photo_album',
    'gift_feature',
    'invitation_message',
    'reminder_message',
    'thank_you_message',
    'decline_message',
    'custom_invitation_image',
    'custom_reminder_image',
    'custom_thank_you_image',
    'support_services_discount_percentage',
];
sort($expected_feature_keys);

echo "=== Phase 1 — Commit 2: اختبارات PGE_Feature_Registry ===\n";

// ── 1) العدد الإجمالي = 19 بالضبط ──────────────────────────────────────────

$all_features = PGE_Feature_Registry::all();

check_true('1. all() تُعيد Array', is_array($all_features));
check('1. عدد الميزات المُعادة من all() = 19 بالضبط', count($all_features), 19);

$actual_feature_keys = array_keys($all_features);
sort($actual_feature_keys);
check('1. مفاتيح الميزات مطابقة حرفياً لقائمة §6 المعتمدة (19 مفتاحاً)', $actual_feature_keys, $expected_feature_keys);

// ── 2) كل ميزة تحتوي على الحقول الثمانية فقط — لا أكثر ولا أقل ────────────

foreach ($all_features as $feature_key => $definition) {
    check_true("2. [$feature_key] التعريف Array", is_array($definition));

    $actual_field_names = array_keys($definition);
    sort($actual_field_names);
    check("2. [$feature_key] الحقول الثمانية فقط (لا حقل تاسع، لا حقل ناقص)", $actual_field_names, $expected_field_names);

    // الحقل الداخلي 'key' يجب أن يطابق مفتاح الـArray الخارجي نفسه — تحقق
    // اتساق إضافي (لا يمنع وحده تكرار المفاتيح، لكنه يكشف أي خطأ نسخ يدوي).
    check("2. [$feature_key] الحقل الداخلي 'key' يطابق مفتاح الـArray", $definition['key'] ?? null, $feature_key);
}

// ── 3) لا تكرار (Duplicate) لأي feature_key ────────────────────────────────
// مفاتيح PHP Array نصية لا يمكن أن تتكرر بنيوياً؛ التحقق هنا يثبت ذلك صراحة
// بمقارنة عدد المفاتيح الفريدة بعدد عناصر all() الكلي، بدل افتراض ضمانة اللغة فقط.

$unique_keys_count = count(array_unique(array_keys($all_features)));
check('3. عدد المفاتيح الفريدة = عدد الميزات الكلي (لا Duplicate Definition)', $unique_keys_count, count($all_features));

// ── 4) has() ────────────────────────────────────────────────────────────────

check_true("4. has('google_maps') = true (مفتاح موجود)", PGE_Feature_Registry::has('google_maps'));
check_true("4. has('rsvp') = true (مفتاح موجود)", PGE_Feature_Registry::has('rsvp'));
check_true("4. has('support_services_discount_percentage') = true (مفتاح موجود)", PGE_Feature_Registry::has('support_services_discount_percentage'));

check('4. has() تُعيد false لمفتاح غير موجود', PGE_Feature_Registry::has('non_existent_feature_key'), false);
check('4. has() تُعيد false لسلسلة فارغة', PGE_Feature_Registry::has(''), false);

// ── 5) get() ──────────────────────────────────────────────────────────────

$google_maps_def = PGE_Feature_Registry::get('google_maps');
check_true('5. get() لمفتاح موجود تُعيد Array', is_array($google_maps_def));
check("5. get('google_maps')['type'] = boolean", $google_maps_def['type'] ?? null, 'boolean');
check("5. get('google_maps')['default'] = true", $google_maps_def['default'] ?? null, true);
check("5. get('google_maps')['category'] = event_experience", $google_maps_def['category'] ?? null, 'event_experience');
check("5. get('google_maps')['lifecycle'] = production", $google_maps_def['lifecycle'] ?? null, 'production');

$actual_google_maps_fields = array_keys($google_maps_def);
sort($actual_google_maps_fields);
check("5. get('google_maps') تُعيد التعريف الكامل بالحقول الثمانية", $actual_google_maps_fields, $expected_field_names);

// رفض صريح: get() لمفتاح غير موجود تُعيد null صراحة (لا قيمة أخرى ملتبسة) —
// FEATURES-IMPLEMENTATION-PLAN.md §"Phase 1 — خطوات التنفيذ" رقم 4: "رفض
// صريح (قيمة null/استثناء متحكَّم به)... لا تخمين".
check('5. get() لمفتاح غير موجود تُعيد null صراحة (رفض صريح، لا تخمين)', PGE_Feature_Registry::get('non_existent_feature_key'), null);

// null من get() غير ملتبس أبداً: لا ميزة معرَّفة في Registry قيمتها الكاملة null.
$has_null_definition = false;
foreach ($all_features as $definition) {
    if ($definition === null) {
        $has_null_definition = true;
        break;
    }
}
check('5. لا ميزة معرَّفة قيمتها الكاملة null (null من get() لا لبس فيه)', $has_null_definition, false);

// ── 6) all() ──────────────────────────────────────────────────────────────

check('6. all() تُعيد نفس عدد الميزات (19) عند استدعاء ثانٍ (تناسق الكاش الداخلي)', count(PGE_Feature_Registry::all()), 19);
check_true('6. مفاتيح all() تطابق كل مفاتيح §6 (لا مفتاح مفقود، لا مفتاح زائد)', $actual_feature_keys === $expected_feature_keys);

// ── عيّنة على أنواع الحقول الثلاثة (boolean / integer / percentage) — تحقق
// وصفي فقط من بيانات Registry كما هي، بلا أي تفسير أو تحويل نوع (ليس من
// اختصاص هذه الطبقة إطلاقاً — ذلك عمل الـResolver في Phase 3، غير موجود بعد) ─

check("عيّنة النوع: host_limit → type = integer", PGE_Feature_Registry::get('host_limit')['type'] ?? null, 'integer');
check("عيّنة النوع: rsvp → type = boolean", PGE_Feature_Registry::get('rsvp')['type'] ?? null, 'boolean');
check("عيّنة النوع: support_services_discount_percentage → type = percentage", PGE_Feature_Registry::get('support_services_discount_percentage')['type'] ?? null, 'percentage');

// ── القيمة 'TBD' لثلاث ميزات معلَّقة صراحة في §10 من الوثيقة المعمارية — لا
// اختراع لقيمة رقمية بديلة (host_limit, admin_supervisor_limit,
// invitation_design_limit) ────────────────────────────────────────────────

check("القيمة المعلَّقة: host_limit → default = 'TBD'", PGE_Feature_Registry::get('host_limit')['default'] ?? null, 'TBD');
check("القيمة المعلَّقة: admin_supervisor_limit → default = 'TBD'", PGE_Feature_Registry::get('admin_supervisor_limit')['default'] ?? null, 'TBD');
check("القيمة المعلَّقة: invitation_design_limit → default = 'TBD'", PGE_Feature_Registry::get('invitation_design_limit')['default'] ?? null, 'TBD');

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
