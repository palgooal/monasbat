<?php
/**
 * اختبار تنفيذي — تحديث سياسة القسم 5.1 (Numeric Phone Cell Policy).
 *
 * ==========================================================================
 * الخلفية
 * ==========================================================================
 * السياسة السابقة: أي خلية جوال في XLSX ليست Shared String حرفياً
 * (type !== 's') كانت تُرفَض فوراً (invalid_phone_cell_type) — حتى لو كانت
 * القيمة رقماً صحيحاً تماماً بلا أي فقدان أرقام (مثال حقيقي من تقرير
 * المستخدم: 970599000932 وَ 972599000932، كلاهما رُفضا رغم صحتهما الكاملة).
 *
 * السياسة الجديدة (راجع PGE_Invitation_Excel_Import_Service::
 * resolve_safe_numeric_phone_text() في includes/class-pge-invitation-excel-
 * import.php): خلية Numeric تُقبَل فقط إذا كانت PHP int حقيقي (لا كسر، لا
 * صيغة علمية)، قابلة لتحويل رقم→نص→رقم بلا أي تغيير بالقيمة (round-trip)،
 * وطولها ضمن حدود معقولة لرقم هاتف حقيقي (7-20 رقماً). أي مؤشر فقدان بيانات
 * (float/كسر/صيغة علمية/NaN/Infinity/نوع غير رقمي أصلاً/طول غير منطقي) يُبقي
 * الرفض كما كان تماماً — بلا أي تخمين لصفر بادئ مفقود، وبلا اعتماد على الطول
 * لإضافة أي رقم.
 *
 * النطاق: XLSX Parser فقط (resolve_safe_numeric_phone_text() + build_row()
 * في class-pge-invitation-excel-import.php). لا تغيير على Upload/Preview/
 * Confirm/Validation/UI/CSV — هذا الملف يختبر طبقة القراءة/التحويل فقط عبر
 * build_from_rows_ex()، تماماً بنفس منهجية tests/test-excel-import-parser.php
 * (راجع الملاحظة المنهجية هناك بخصوص قيد بيئة php-wasm/ext-simplexml).
 *
 * التشغيل: php tests/test-excel-import-numeric-phone-policy.php (أو عبر
 * harness php-wasm المستخدَم في هذا المشروع لتشغيل ملفات PHP حقيقية).
 */

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/class-pge-invitation-excel-import.php';

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
function check_true($label, $condition) { check($label, (bool) $condition, true); }

$total = 0; $passed = 0; $failures = [];

/**
 * نفس شكل خلية SimpleXLSXEx::valueEx() الرسمي — الحد الأدنى المُستهلَك فعلياً
 * هو type/value (طابق tests/test-excel-import-parser.php حرفياً).
 */
function xcell($type, $value)
{
    return ['type' => $type, 'name' => '', 'value' => $value, 'href' => '', 'f' => '', 'format' => '',
            's' => 0, 'css' => '', 'r' => '', 'hidden' => false, 'width' => 0, 'height' => 0, 'comment' => ''];
}
function header_row()
{
    return [xcell('s', 'الاسم'), xcell('s', 'رقم الجوال'), xcell('s', 'ملاحظة')];
}

$SVC = 'PGE_Invitation_Excel_Import_Service';

echo "=== Numeric صحيح (المثال الحقيقي من تقرير المستخدم) ===\n";

/**
 * ملاحظة منهجية مهمة — قيد بيئة الاختبار (ليس قيداً في الكود الإنتاجي):
 * كل بنايات php-wasm المتوفرة هنا (PHP 8.0 حتى 8.5) مُصرَّفة كـwasm32
 * (Emscripten القياسي، بلا دعم memory64) — أي PHP_INT_SIZE=4 وَ
 * PHP_INT_MAX=2147483647 هنا تحديداً، خلافاً لأي استضافة WordPress حقيقية
 * تقريباً (64-bit، PHP_INT_MAX=9223372036854775807). الأثر العملي: عدد صحيح
 * حرفي بـ12 رقماً مثل 970599000932 يبقى PHP int حقيقياً على 64-bit (وهذا ما
 * سيحدث فعلياً في الإنتاج)، لكن PHP نفسها (وليس كودنا) تُحوِّله تلقائياً إلى
 * float عند تحليل السكربت على 32-bit فقط بسبب تجاوزه PHP_INT_MAX هنا. هذا لا
 * يكشف أي خلل في resolve_safe_numeric_phone_text() — الدالة تتصرف بشكل صحيح
 * تماماً في الحالتين (تقبل PHP int، ترفض PHP float)؛ التمثيل الأولي للعدد هو
 * ما يختلف حسب المنصة، لا منطق الفحص. لذلك تُبنى التوقعات أدناه ديناميكياً
 * حسب PHP_INT_SIZE الفعلي لحظة التشغيل، بلا افتراض مسبق لأي منصة.
 */
$is_64bit_platform = (PHP_INT_SIZE >= 8);
echo "(معلومة بيئة: PHP_INT_SIZE=" . PHP_INT_SIZE . " — " . ($is_64bit_platform ? '64-bit' : '32-bit (قيد php-wasm المعروف)') . ")\n";

// 1) 970599000932 — المثال الأول من تقرير المستخدم مباشرة. type='' يحاكي ما
// تعيده SimpleXLSX فعلياً لخلية رقمية عادية (لا 't' في XML الأصلي).
$r1 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 1'), xcell('', 970599000932), xcell('s', '')],
]);
check(
    '1. status صحيح حسب PHP_INT_SIZE لهذه المنصة لـ970599000932 (Numeric)',
    $r1['rows'][0]['status'],
    $is_64bit_platform ? 'valid' : 'invalid_phone_cell_type'
);
if ($is_64bit_platform) {
    check('1. الرقم محفوظ حرفياً بلا أي تغيير (64-bit)', $r1['rows'][0]['phone'], '970599000932');
} else {
    check('1. مرفوض بأمان على 32-bit (القيمة أصبحت float هنا فعلياً، لا int) — لا فقدان صامت', $r1['rows'][0]['phone'], '');
}

// 2) 972599000932 — المثال الثاني من تقرير المستخدم مباشرة.
$r2 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('', 'ضيف 2'), xcell('', 972599000932), xcell('s', '')],
]);
// ملاحظة: عمود الاسم هنا type='' عمداً لإثبات أن السياسة الجديدة تخص عمود
// الجوال فقط — عمود الاسم لا يمر أصلاً عبر resolve_safe_numeric_phone_text().
check(
    '2. status صحيح حسب PHP_INT_SIZE لهذه المنصة لـ972599000932 (Numeric)',
    $r2['rows'][0]['status'],
    $is_64bit_platform ? 'valid' : 'invalid_phone_cell_type'
);
if ($is_64bit_platform) {
    check('2. الرقم محفوظ حرفياً بلا أي تغيير (64-bit)', $r2['rows'][0]['phone'], '972599000932');
} else {
    check('2. مرفوض بأمان على 32-bit (القيمة أصبحت float هنا فعلياً، لا int) — لا فقدان صامت', $r2['rows'][0]['phone'], '');
}

// 3) رقم Numeric صحيح آخر أقصر (9 أرقام، ضمن الحدود 7-20) — type='n' الصريح.
$r3 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 3'), xcell('n', 599123456), xcell('s', '')],
]);
check('3. status=valid لرقم 9 أرقام (n صريح)', $r3['rows'][0]['status'], 'valid');
check('3. الرقم محفوظ حرفياً', $r3['rows'][0]['phone'], '599123456');

echo "\n=== لا تخمين لصفر بادئ مفقود ===\n";

// 4) 0599000932 محفوظ كخلية Text حرفياً (type='s') — يجب أن يبقى يعمل تماماً
// كما كان (المسار النصي الموثوق لم يُمَس إطلاقاً بهذا التحديث).
$r4 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 4'), xcell('s', '0599000932'), xcell('s', '')],
]);
check('4. status=valid لنص محفوظ بصفر بادئ', $r4['rows'][0]['status'], 'valid');
check('4. الصفر البادئ محفوظ حرفياً (لا فقدان)', $r4['rows'][0]['phone'], '0599000932');

// 5) نفس الرقم بلا الصفر البادئ لكن كـNumeric حقيقي (599000932) — يجب أن
// يُقبَل كما هو تماماً بلا أي محاولة لإضافة صفر بادئ مُخمَّن. النظام لا يخترع
// بيانات: لا يعرف إن كان الرقم الأصلي "0599000932" أم "599000932" فعلاً —
// فيتركه كما ورد حرفياً في الخلية.
$r5 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 5'), xcell('', 599000932), xcell('s', '')],
]);
check('5. status=valid بلا تخمين صفر بادئ', $r5['rows'][0]['status'], 'valid');
check('5. الرقم كما ورد فعلياً — 9 أرقام بلا صفر مُضاف', $r5['rows'][0]['phone'], '599000932');
check_true('5. لم يُضَف صفر بادئ ولا أي رقم آخر (لا اختراع بيانات)', strlen($r5['rows'][0]['phone']) === 9);

echo "\n=== مؤشرات فقدان بيانات — رفض كما هو (بلا تغيير في السلوك) ===\n";

// 6) Scientific Notation حقيقية كما تُنتِجها SimpleXLSX فعلياً لقيمة مُخزَّنة
// بصيغة علمية في XML الخام (float، وليس string — راجع SimpleXLSX::value()،
// دالة is_numeric+cast الداخلية تُحوِّل مثل هذه القيم إلى float دائماً).
$r6 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 6'), xcell('', 9.70599e11), xcell('s', '')],
]);
check('6. status=invalid_phone_cell_type لـScientific Notation (float)', $r6['rows'][0]['status'], 'invalid_phone_cell_type');
check('6. phone فارغ (لا تخمين)', $r6['rows'][0]['phone'], '');

// 7) Decimal حقيقي (كسر فعلي في الخلية الرقمية).
$r7 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 7'), xcell('', 599123456.5), xcell('s', '')],
]);
check('7. status=invalid_phone_cell_type لكسر عشري حقيقي', $r7['rows'][0]['status'], 'invalid_phone_cell_type');
check('7. phone فارغ (لا تخمين)', $r7['rows'][0]['phone'], '');

// 8) قيمة قصيرة بشكل غير منطقي (5 أرقام فقط — أقل من الحد الأدنى 7).
$r8 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 8'), xcell('', 12345), xcell('s', '')],
]);
check('8. status=invalid_phone_cell_type لرقم قصير (5 أرقام)', $r8['rows'][0]['status'], 'invalid_phone_cell_type');
check('8. phone فارغ (لا تخمين)', $r8['rows'][0]['phone'], '');

// 8ب) قيمة قصيرة جداً (رقم واحد فقط) — حالة حدّية إضافية.
$r8b = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 8ب'), xcell('', 5), xcell('s', '')],
]);
check('8ب. status=invalid_phone_cell_type لرقم من خانة واحدة', $r8b['rows'][0]['status'], 'invalid_phone_cell_type');

// 9) قيمة غير رقمية أصلاً (Boolean) — لا تصل حتى لفحص الطول/الكسر.
$r9 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 9'), xcell('b', true), xcell('s', '')],
]);
check('9. status=invalid_phone_cell_type لخلية Boolean', $r9['rows'][0]['status'], 'invalid_phone_cell_type');
check('9. phone فارغ (لا تخمين)', $r9['rows'][0]['phone'], '');

// 9ب) قيمة غير رقمية أصلاً (نص صيغة/Formula، type='str') — سلسلة نصية غير
// موثوقة (ليست 's')، وليست int أيضاً → رفض.
$r9b = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 9ب'), xcell('str', '=A1+A2'), xcell('s', '')],
]);
check('9ب. status=invalid_phone_cell_type لنص صيغة (formula)', $r9b['rows'][0]['status'], 'invalid_phone_cell_type');

// 10) NaN — مستحيلة بنيوياً كـint في PHP؛ تصل هنا كـfloat NAN وتُرفَض عبر
// فحص is_int() أولاً (فحص is_finite() الإضافي دفاعي فقط، غير قابل للوصول
// عملياً بعد بوابة is_int()، لكنه موثَّق ومُختبَر هنا بأي حال لإثبات وجوده).
$r10 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 10'), xcell('', NAN), xcell('s', '')],
]);
check('10. status=invalid_phone_cell_type لقيمة NaN', $r10['rows'][0]['status'], 'invalid_phone_cell_type');

// 11) Infinity — نفس المنطق أعلاه (float غير محدود).
$r11 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 11'), xcell('', INF), xcell('s', '')],
]);
check('11. status=invalid_phone_cell_type لقيمة Infinity', $r11['rows'][0]['status'], 'invalid_phone_cell_type');

// 12) رقم سالب — لا رقم هاتف حقيقي سالب؛ السماح به كان سيعني الاعتماد على
// pge_norm_phone() لحذف علامة "-" ضمناً بصمت، وهو تخمين غير مقبول.
$r12 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 12'), xcell('', -599123456), xcell('s', '')],
]);
check('12. status=invalid_phone_cell_type لرقم سالب', $r12['rows'][0]['status'], 'invalid_phone_cell_type');
check('12. phone فارغ (لا تخمين حذف الإشارة)', $r12['rows'][0]['phone'], '');

echo "\n=== Backward Compatibility — خلايا Text كما كانت تماماً ===\n";

// 13) Text عادي صحيح — لا علاقة له بالسياسة الرقمية الجديدة إطلاقاً، يجب أن
// يستمر بالعمل حرفياً كما كان قبل هذا التحديث.
$r13 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 13'), xcell('s', '0599123456'), xcell('s', 'ملاحظة عادية')],
]);
check('13. status=valid لنص عادي (بلا تغيير)', $r13['rows'][0]['status'], 'valid');
check('13. الرقم محفوظ حرفياً كنص', $r13['rows'][0]['phone'], '0599123456');

// 14) Text لرقم بصيغة تشبه Scientific Notation لكنه نص حرفي (يجب أن يُطبَّع
// عبر pge_norm_phone() كالمعتاد — نص موثوق، لا يمر بفحص resolve_safe_numeric
// _phone_text() إطلاقاً، فيُحتفَظ فقط بالأرقام).
$r14 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'ضيف 14'), xcell('s', '5.99123E+11'), xcell('s', '')],
]);
check('14. status=valid (نص، لا علاقة له بسياسة Numeric)', $r14['rows'][0]['status'], 'valid');
check_true('14. pge_norm_phone() الموجودة فقط طبَّعت النص كالمعتاد (لم تُرفَض كخلية)', $r14['rows'][0]['phone'] !== '');

echo "\n=== CSV — بلا أي تغيير إطلاقاً (Regression) ===\n";

// 15) CSV لا يملك Cell Types أصلاً — لا علاقة له بهذه السياسة على الإطلاق.
// راجع build_from_csv_rows(): $phone_raw يُمرَّر كنص موثوق دائماً (true سابقاً،
// الآن $phone_raw نفسه) — لم يتغيّر أي سطر فعلي في مسار CSV.
$r15 = $SVC::build_from_csv_rows([
    ['الاسم', 'رقم الجوال', 'ملاحظة'],
    ['ضيف 15', '970599000932', ''],
]);
check('15. CSV: status=valid كالمعتاد', $r15['rows'][0]['status'], 'valid');
check('15. CSV: الرقم محفوظ حرفياً كالمعتاد', $r15['rows'][0]['phone'], '970599000932');

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
} else {
    echo "كل اختبارات سياسة الخلايا الرقمية (Numeric Phone Cell Policy) نجحت.\n";
}
