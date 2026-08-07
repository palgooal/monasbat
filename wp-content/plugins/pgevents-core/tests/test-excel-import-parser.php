<?php
/**
 * اختبار تنفيذي حقيقي — Phase 2 من "استيراد المدعوين من Excel"
 * (docs/EXCEL-GUEST-IMPORT-SPEC.md): طبقة القراءة/التحويل فقط
 * (`PGE_Invitation_Excel_Import_Service`)، بلا أي كتابة بيانات.
 *
 * ==========================================================================
 * ملاحظة منهجية مهمة — قيد بيئة الاختبار (ليس قيداً في الكود الإنتاجي)
 * ==========================================================================
 * بيئة تشغيل هذا الاختبار (php-wasm داخل sandbox التطوير) **لا تملك امتداد
 * ext-simplexml** (تأكَّد بالفحص المباشر: `extension_loaded('simplexml')`
 * ترجع false، و`class_exists('SimpleXMLElement')` ترجع false أيضاً)، رغم أن
 * `ext-libxml` نفسها محمَّلة. هذا امتداد قياسي مفعَّل افتراضياً على أي
 * استضافة WordPress حقيقية تقريباً (ووردبريس نفسه يستخدمه)، لكنه غائب تحديداً
 * عن بناء php-wasm المستخدَم هنا لأغراض الاختبار الآلي فقط.
 *
 * الأثر العملي: استدعاء `\Shuchkin\SimpleXLSX::parse()` الفعلي على ملف xlsx
 * **صالح وحقيقي** يتوقف عند مرحلة تحليل XML الداخلي (بعد نجاح فك ضغط ZIP
 * فعلياً — تم التحقق يدوياً عبر سكربت تمهيدي منفصل قبل كتابة هذا الملف).
 * لذلك، اختبارات محتوى XLSX أدناه (البنود 1-14) تستدعي مباشرة
 * `PGE_Invitation_Excel_Import_Service::build_from_rows_ex()` — وهي الدالة
 * العامة (public) التي صُمِّمت عمداً لتُفصَل عن استدعاء SimpleXLSX نفسه
 * (راجع تعليقها في الكلاس)، وتُغذَّى بمصفوفات اصطناعية بنفس الشكل الحرفي
 * الذي توثِّقه SimpleXLSX رسمياً لـ`rowsEx()` (`type`/`value` لكل خلية).
 * هذا يختبر **منطق العقد وسياسة خلايا الجوال الفعلي 100%** (وهو المنطق الذي
 * كتبناه نحن ويحمل كل مخاطر الأخطاء)، بينما مكتبة SimpleXLSX نفسها (كود
 * طرف ثالث جاهز ومُستخدَم عبر واجهته الرسمية فقط بلا أي تعديل) تبقى خارج
 * نطاق هذا الاختبار تحديداً بسبب قيد البيئة أعلاه.
 *
 * البنود التي **تُنفَّذ فعلياً عبر parse_file() الكامل بمسار ملف حقيقي على
 * القرص** (بلا أي قيد، تشمل مسار SimpleXLSX الحقيقي حين لا يحتاج XML —
 * راجع كل بند): "unsupported xls" (يُرفَض عند بوابة النوع قبل لمس SimpleXLSX
 * إطلاقاً)، و"malformed xlsx" (يُرفَض داخل unzip() نفسها — وهي خطوة فك ضغط
 * ZIP يدوية بحتة داخل SimpleXLSX **لا تحتاج simplexml إطلاقاً**، تم التحقق
 * من هذا يدوياً)، وكل بنود CSV والعامة (لا علاقة لها بـSimpleXLSX أصلاً).
 *
 * التشغيل: php tests/test-excel-import-parser.php (أو عبر harness php-wasm)
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
 * يبني خلية بنفس شكل SimpleXLSXEx::valueEx() الموثَّق رسمياً — الحد الأدنى
 * من الحقول الذي يستهلكه build_from_rows_ex() فعلياً هو type/value، لكن
 * نضيف بقية الحقول الرسمية أيضاً لواقعية أكبر (بلا أثر على المنطق).
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

echo "=== XLSX (عبر build_from_rows_ex — راجع الملاحظة المنهجية أعلاه) ===\n";

// 1) ملف صحيح + 2) header صحيح
$r1 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'محمد أحمد'), xcell('s', '0599123456'), xcell('s', 'ملاحظة أولى')],
    [xcell('s', 'سارة خالد'), xcell('s', '0599654321'), xcell('s', '')],
]);
check_true('1. ok=true لملف صحيح', $r1['ok']);
check('1. عدد الصفوف = 2', count($r1['rows']), 2);
check('1. الصف الأول status=valid', $r1['rows'][0]['status'], 'valid');
check('1. الصف الثاني status=valid', $r1['rows'][1]['status'], 'valid');
check_true('2. header صحيح لم يُرفَض (لا error)', $r1['error'] === null);

// 3) 3 أعمدة فقط — هيدر بأربعة أعمدة يُرفَض (يتقاطع مع بند 13 عمداً لإثبات نفس السلوك)
$r3 = $SVC::build_from_rows_ex([
    [xcell('s', 'الاسم'), xcell('s', 'رقم الجوال'), xcell('s', 'ملاحظة'), xcell('s', 'عمود زائد')],
]);
check_true('3. هيدر بـ4 أعمدة: ok=false', !$r3['ok']);
check('3. error=invalid_columns', $r3['error'], 'invalid_columns');

// 4) اسم عربي
$r4 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'عبدالرحمن الغامدي'), xcell('s', '0599111222'), xcell('s', '')],
]);
check('4. الاسم العربي محفوظ حرفياً', $r4['rows'][0]['name'], 'عبدالرحمن الغامدي');
check('4. status=valid', $r4['rows'][0]['status'], 'valid');

// 5) ملاحظة عربية
$r5 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'خالد'), xcell('s', '0599111222'), xcell('s', 'ضيف VIP يحتاج مقعداً مخصصاً')],
]);
check('5. الملاحظة العربية محفوظة حرفياً', $r5['rows'][0]['note'], 'ضيف VIP يحتاج مقعداً مخصصاً');

// 6) جوال Text صحيح — الصفر البادئ لا يُفقَد
$r6 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'أحمد'), xcell('s', '0599123456'), xcell('s', '')],
]);
check('6. status=valid', $r6['rows'][0]['status'], 'valid');
check('6. الجوال محفوظ بالصفر البادئ', $r6['rows'][0]['phone'], '0599123456');

// 7) جوال Numeric صحيح شكلياً (type='n'، int حقيقي، بلا صيغة علمية/كسر) —
// تحديث سياسة القسم 5.1: لم يعد يُرفَض تلقائياً بمجرد كونه Numeric. راجع
// tests/test-excel-import-numeric-phone-policy.php للتغطية الكاملة للسياسة
// الجديدة (5 شروط) — هذا البند هنا محدَّث فقط ليعكس السلوك الصحيح الجديد
// بدل الرفض القديم، إثباتاً لعدم وجود "انحدار زائف" في مجموعة اختبارات Phase 2.
$r7 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'فيصل'), xcell('n', 599123456), xcell('s', '')],
]);
check('7. status=valid (رقم Numeric صحيح — سياسة محدَّثة، راجع ملف اختبار السياسة الجديدة)', $r7['rows'][0]['status'], 'valid');
check('7. الرقم محفوظ كما هو حرفياً بلا أي تغيير', $r7['rows'][0]['phone'], '599123456');

// 8) Scientific Notation يجب أن يُرفض (لا يزال هذا صحيحاً بعد التحديث — أي
// شيء غير PHP int حقيقي يُرفَض فوراً، بما فيه سلسلة نصية بصيغة علمية).
$r8 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'نورة'), xcell('n', '5.99123E+11'), xcell('s', '')],
]);
check('8. status=invalid_phone_cell_type', $r8['rows'][0]['status'], 'invalid_phone_cell_type');
check('8. phone فارغ (لا تخمين)', $r8['rows'][0]['phone'], '');

// 9) missing name
$r9 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', ''), xcell('s', '0599123456'), xcell('s', 'ملاحظة')],
]);
check('9. status=missing_name', $r9['rows'][0]['status'], 'missing_name');

// 10) missing phone
$r10 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'محمد'), xcell('s', ''), xcell('s', 'ملاحظة')],
]);
check('10. status=missing_phone', $r10['rows'][0]['status'], 'missing_phone');

// 11) empty row — لا Fatal، حالة empty_row
$r11 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('', ''), xcell('', ''), xcell('', '')],
]);
check('11. status=empty_row', $r11['rows'][0]['status'], 'empty_row');
check_true('11. لا استثناء/Fatal (وصلنا هنا بسلام)', true);

// 12) wrong column order
$r12 = $SVC::build_from_rows_ex([
    [xcell('s', 'رقم الجوال'), xcell('s', 'الاسم'), xcell('s', 'ملاحظة')],
]);
check_true('12. ترتيب أعمدة خاطئ: ok=false', !$r12['ok']);
check('12. error=invalid_columns', $r12['error'], 'invalid_columns');

// 13) extra column (نفس بند 3 من زاوية أخرى — هيدر صحيح المحتوى لكن بعمود زائد في النهاية)
$r13 = $SVC::build_from_rows_ex([
    [xcell('s', 'الاسم'), xcell('s', 'رقم الجوال'), xcell('s', 'ملاحظة'), xcell('s', 'كود الدعوة')],
]);
check_true('13. عمود زائد: ok=false', !$r13['ok']);
check('13. error=invalid_columns', $r13['error'], 'invalid_columns');

// 14) repeated Arabic sharedStrings — نفس القيمة النصية مكررة في صفين (محاكاة لما يعيده SimpleXLSX
// بعد فكّ sharedStrings.xml داخلياً؛ الفرضية غير مختبَرة هنا بذاتها، لكن سلوك المحوّل تجاهها هو).
$r14 = $SVC::build_from_rows_ex([
    header_row(),
    [xcell('s', 'محمد أحمد'), xcell('s', '0599111111'), xcell('s', '')],
    [xcell('s', 'محمد أحمد'), xcell('s', '0599222222'), xcell('s', '')],
]);
check('14. الصف الأول: الاسم صحيح', $r14['rows'][0]['name'], 'محمد أحمد');
check('14. الصف الثاني: نفس الاسم المكرر صحيح أيضاً', $r14['rows'][1]['name'], 'محمد أحمد');
check('14. الصف الأول valid', $r14['rows'][0]['status'], 'valid');
check('14. الصف الثاني valid', $r14['rows'][1]['status'], 'valid');

// 15) unsupported xls — تنفيذ كامل حقيقي عبر parse_file() (يُرفَض عند بوابة النوع، قبل أي لمس لـSimpleXLSX)
$r15 = $SVC::parse_file('/tmp/does-not-matter.xls', 'xls');
check_true('15. xls: ok=false', !$r15['ok']);
check('15. error=unsupported_extension', $r15['error'], 'unsupported_extension');

echo "\n=== CSV (تنفيذ كامل حقيقي — ملفات مؤقتة فعلية على القرص) ===\n";

function write_temp_csv($content)
{
    $path = tempnam(sys_get_temp_dir(), 'pge_csv_test_') . '.csv';
    file_put_contents($path, $content);
    return $path;
}

// 16) CSV صحيح
$p16 = write_temp_csv("الاسم,رقم الجوال,ملاحظة\nأحمد علي,0599123456,ملاحظة\n");
$r16 = $SVC::parse_file($p16, 'csv');
check_true('16. CSV صحيح: ok=true', $r16['ok']);
check('16. صف واحد', count($r16['rows']), 1);
check('16. status=valid', $r16['rows'][0]['status'], 'valid');
unlink($p16);

// 17) UTF-8 عربي
$p17 = write_temp_csv("الاسم,رقم الجوال,ملاحظة\nعبدالله السالم,0599111222,ملاحظة عربية طويلة نسبياً للتأكد من UTF-8\n");
$r17 = $SVC::parse_file($p17, 'csv');
check('17. الاسم العربي سليم', $r17['rows'][0]['name'], 'عبدالله السالم');
check('17. الملاحظة العربية سليمة', $r17['rows'][0]['note'], 'ملاحظة عربية طويلة نسبياً للتأكد من UTF-8');
unlink($p17);

// 18) رقم يبدأ بـ0 يبقى محفوظاً (لا يتحول إلى 599123456)
$p18 = write_temp_csv("الاسم,رقم الجوال,ملاحظة\nمحمد,0599123456,\n");
$r18 = $SVC::parse_file($p18, 'csv');
check('18. الصفر البادئ محفوظ', $r18['rows'][0]['phone'], '0599123456');
check_true('18. لا يساوي الرقم بلا الصفر البادئ', $r18['rows'][0]['phone'] !== '599123456');
unlink($p18);

// 19) missing name
$p19 = write_temp_csv("الاسم,رقم الجوال,ملاحظة\n,0599123456,\n");
$r19 = $SVC::parse_file($p19, 'csv');
check('19. status=missing_name', $r19['rows'][0]['status'], 'missing_name');
unlink($p19);

// 20) missing phone
$p20 = write_temp_csv("الاسم,رقم الجوال,ملاحظة\nمحمد,,\n");
$r20 = $SVC::parse_file($p20, 'csv');
check('20. status=missing_phone', $r20['rows'][0]['status'], 'missing_phone');
unlink($p20);

// 21) extra column
$p21 = write_temp_csv("الاسم,رقم الجوال,ملاحظة,عمود زائد\nمحمد,0599123456,,X\n");
$r21 = $SVC::parse_file($p21, 'csv');
check_true('21. عمود زائد: ok=false', !$r21['ok']);
check('21. error=invalid_columns', $r21['error'], 'invalid_columns');
unlink($p21);

// 22) wrong header
$p22 = write_temp_csv("Name,Phone,Note\nمحمد,0599123456,\n");
$r22 = $SVC::parse_file($p22, 'csv');
check_true('22. هيدر خاطئ: ok=false', !$r22['ok']);
check('22. error=invalid_columns', $r22['error'], 'invalid_columns');
unlink($p22);

// 23) empty row — سطر فارغ تماماً وسط بيانات صحيحة
$p23 = write_temp_csv("الاسم,رقم الجوال,ملاحظة\nمحمد,0599123456,\n\nسارة,0599654321,\n");
$r23 = $SVC::parse_file($p23, 'csv');
check_true('23. ok=true (السطر الفارغ لا يُسقِط الملف)', $r23['ok']);
check('23. 3 صفوف بيانات (يشمل الصف الفارغ نفسه)', count($r23['rows']), 3);
check('23. الصف الأوسط status=empty_row', $r23['rows'][1]['status'], 'empty_row');
check('23. الصف الأول valid', $r23['rows'][0]['status'], 'valid');
check('23. الصف الثالث valid', $r23['rows'][2]['status'], 'valid');
unlink($p23);

echo "\n=== عام ===\n";

// 24) unsupported extension
$r24 = $SVC::parse_file('/tmp/whatever.docx', 'docx');
check_true('24. docx: ok=false', !$r24['ok']);
check('24. error=unsupported_extension', $r24['error'], 'unsupported_extension');

// 25) unreadable file
$r25 = $SVC::parse_file('/tmp/this-file-does-not-exist-' . uniqid() . '.csv', 'csv');
check_true('25. ملف غير موجود: ok=false', !$r25['ok']);
check('25. error=unreadable_file', $r25['error'], 'unreadable_file');

// 26) malformed xlsx — بايتات عشوائية غير ZIP بامتداد xlsx (يُرفَض داخل unzip() ذاتها، بلا لمس simplexml)
$p26 = tempnam(sys_get_temp_dir(), 'pge_xlsx_bad_') . '.xlsx';
file_put_contents($p26, 'هذا ليس ملف Excel إطلاقاً — نص عشوائي فقط.');
$r26 = $SVC::parse_file($p26, 'xlsx');
check_true('26. xlsx تالف: ok=false', !$r26['ok']);
check('26. error=malformed_xlsx', $r26['error'], 'malformed_xlsx');
check_true('26. لا Fatal/Exception غير مُعالَجة (وصلنا هنا بسلام)', true);
unlink($p26);

// 27) malformed csv إذا أمكن كشفه — بايتات ثنائية (NUL bytes) بامتداد csv
$p27 = tempnam(sys_get_temp_dir(), 'pge_csv_bad_') . '.csv';
file_put_contents($p27, "الاسم,رقم الجوال,ملاحظة\n" . "\x00\x01\x02\x03binary-garbage\x00");
$r27 = $SVC::parse_file($p27, 'csv');
check_true('27. csv ثنائي/تالف: ok=false', !$r27['ok']);
check('27. error=malformed_csv', $r27['error'], 'malformed_csv');
unlink($p27);

// 28/29/30) لا كتابة بيانات إطلاقاً — تحقّق نصّي من مصدر الكلاس نفسه (بالإضافة لعدم توفر أي $wpdb
// وهمي في هذا الاختبار أصلاً — أي محاولة وصول DB حقيقية كانت ستُسبِّب Fatal فوراً في كل الاختبارات أعلاه).
// ملاحظة: $__pge_class_source_override يسمح لهارنس الاختبار (بيئات لا تُحلّ فيها __DIR__ الحقيقي،
// مثل php-wasm) بحقن نص الملف الحقيقي مباشرة؛ في أي بيئة PHP حقيقية (إنتاج/CI) يُقرأ الملف من القرص
// فعلياً كالمعتاد. القيمة المحقونة في وقت الاختبار هي نفس بايتات الملف الفعلي على القرص، بلا أي تعديل.
$class_source = isset($__pge_class_source_override)
    ? $__pge_class_source_override
    : file_get_contents(__DIR__ . '/../includes/class-pge-invitation-excel-import.php');
check_true('28. تم تحميل مصدر حقيقي للفحص (طول > 0)', strlen((string) $class_source) > 0);

// الكلاس يوثِّق نطاقه المحظور صراحةً في تعليق RFC-style أعلى الملف (يذكر بالاسم
// PGE_Invitation_Service/PGE_Invitation_Repository/Audit كجزء من شرح ما لا يفعله الكلاس)
// — وهذا توثيق مطلوب، وليس استدعاءً فعلياً. لذلك نُجرّد التعليقات (// و /* */) عبر
// token_get_all() الحقيقية (لا regex تخمينية) قبل البحث عن أي استدعاء فعلي في الكود التنفيذي.
function pge_strip_php_comments_for_test($src)
{
    $wrapped = '<?php ' . $src;
    $tokens = token_get_all($wrapped);
    $out = '';
    foreach ($tokens as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}
$code_only = pge_strip_php_comments_for_test($class_source);

check_true('28. لا مرجع لـ$wpdb في الكود التنفيذي (بلا التعليقات)', strpos($code_only, '$wpdb') === false);
check_true('28. لا أوامر SQL كتابة (INSERT/UPDATE/DELETE) في الكود التنفيذي',
    stripos($code_only, 'INSERT INTO') === false
    && stripos($code_only, 'UPDATE ') === false
    && stripos($code_only, 'DELETE FROM') === false);
check_true('29. لا استدعاء فعلي لـPGE_Invitation_Service في الكود التنفيذي (بلا التعليقات)', strpos($code_only, 'PGE_Invitation_Service') === false);
check_true('29. لا استدعاء فعلي لـPGE_Invitation_Repository في الكود التنفيذي (بلا التعليقات)', strpos($code_only, 'PGE_Invitation_Repository') === false);
check_true('29. المرجعان موجودان فقط داخل تعليق توثيقي (تأكيد أن الفحص كان سيلتقطهما فعلاً)',
    strpos($class_source, 'PGE_Invitation_Service') !== false && strpos($class_source, 'PGE_Invitation_Repository') !== false);
check_true('30. لا استدعاء فعلي لـPGE_Invitation_Management_Audit في الكود التنفيذي', strpos($code_only, 'PGE_Invitation_Management_Audit') === false);
check_true('30. لا أي كلمة "Audit" داخل الكود التنفيذي (بلا التعليقات)', stripos($code_only, 'audit') === false);

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
} else {
    echo "كل اختبارات Phase 2 (طبقة قراءة ملفات Excel/CSV) نجحت.\n";
}
