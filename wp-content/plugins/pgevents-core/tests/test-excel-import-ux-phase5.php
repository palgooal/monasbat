<?php
/**
 * اختبار محتوى/عقد ثابت (Static Content/Contract Scan) — Phase 5 من "استيراد
 * المدعوين من Excel" (docs/EXCEL-GUEST-IMPORT-SPEC.md): مراجعة UX/UI فقط حول
 * تدفق الاستيراد الموجود فعلياً، بلا أي تغيير على منطق الاستيراد (Parser،
 * Duplicate Detection، Confirm، Upload Token، Temporary Storage، Audit).
 *
 * هذا الملف لا يُنفِّذ أي معالج AJAX حقيقي (Phase 5 لا تلمس أي ملف PHP خلف
 * الواجهة) — إنما يفحص **المحتوى الفعلي الحقيقي** لـ templates/event-
 * invitations.php (نفس منهجية الفحص النصّي المُتَّبعة في test-rc1-fixpack1.php/
 * test-rc1-fixpack2.php/test-rc1-fixpack3a.php لفحص محتوى نفس الملف) للتأكد
 * من وجود كل عنصر واجهة مطلوب فعلياً في الملف الحقيقي على القرص، لا نسخة
 * منطقية موازية.
 *
 * $__pge_template_source_override يسمح لهارنس الاختبار (بيئات لا يُحلّ فيها
 * __DIR__، مثل php-wasm) بحقن بايتات الملف الحقيقية مباشرة؛ في أي بيئة PHP
 * حقيقية يُقرأ الملف من القرص فعلياً كالمعتاد (نفس اصطلاح Phase 2/3/4).
 */

$total = 0; $passed = 0; $failures = [];
function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS  $label\n"; }
    else { $failures[] = $label; echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}
function check_true($label, $cond) { check($label, (bool) $cond, true); }
function check_contains($label, $haystack, $needle) { check_true($label, is_string($haystack) && strpos($haystack, $needle) !== false); }
function check_not_contains($label, $haystack, $needle) { check_true($label, is_string($haystack) && strpos($haystack, $needle) === false); }

/**
 * يستخرج جسم دالة JS مُسمَّاة بعدّ الأقواس المتوازن — بحث عن أول
 * `function NAME(` ثم توازن `{`/`}` بدءاً من أول `{` بعدها. مستخدَمة لتضييق
 * فحوصات نصّية معيّنة (مثل payload الـConfirm) لجسم المعالج المقصود حصراً.
 */
function extract_js_function_body(string $source, string $function_name): string
{
    $needle = 'function ' . $function_name . '(';
    $start = strpos($source, $needle);
    if ($start === false) return '';
    $brace_open = strpos($source, '{', $start);
    if ($brace_open === false) return '';
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace_open; $i < $len; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($source, $brace_open, $i - $brace_open + 1);
        }
    }
    return '';
}

/**
 * يستخرج جسم مُعالِج حدث مربوط بـ`addEventListener('click', function () { ... })`
 * بحثاً عن سلسلة الاستدعاء بعد `document.getElementById('ID')` أو المتغيّر
 * المُعطى، بنفس منهجية عدّ الأقواس المتوازن أعلاه — تُستخدَم لتضييق فحص عقد
 * postAjax('pge_invitation_mgmt_excel_confirm', ...) لمُعالِج زر Confirm حصراً.
 */
function extract_listener_body(string $source, string $anchor): string
{
    $start = strpos($source, $anchor);
    if ($start === false) return '';
    $brace_open = strpos($source, '{', $start);
    if ($brace_open === false) return '';
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace_open; $i < $len; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($source, $brace_open, $i - $brace_open + 1);
        }
    }
    return '';
}

$template_source = isset($__pge_template_source_override)
    ? $__pge_template_source_override
    : file_get_contents(__DIR__ . '/../templates/event-invitations.php');

check_true('0. تم تحميل مصدر event-invitations.php للفحص (طول > 0)', strlen($template_source) > 0);

echo "=== Phase 5 — Excel Import UX/UI (فحص محتوى ثابت) ===\n";

// 1) زر الاستيراد موجود
check_contains('1. زر "استيراد من Excel" موجود (openExcelImportBtn)', $template_source, 'id="openExcelImportBtn"');
check_contains('1ب. زر الاستيراد ثانوي (نفس تنسيق border-border مثل زر الإضافة الجماعية)، ليس Primary', $template_source, 'id="openExcelImportBtn" class="h-11 px-4 rounded-xl border border-border');

// 2) Download Template موجود (زر الرأس + رابط داخل حالة الرفع)
check_contains('2. زر تحميل النموذج في الرأس موجود (downloadExcelTemplateBtn)', $template_source, 'id="downloadExcelTemplateBtn"');
check_contains('2ب. رابط تحميل النموذج داخل حالة الرفع نفسها موجود (excelInlineTemplateBtn)', $template_source, 'id="excelInlineTemplateBtn"');

// 3) xlsx/csv مذكورتان
check_contains('3. ذِكر صيغة .xlsx في حالة الرفع', $template_source, '.xlsx');
check_contains('3ب. ذِكر صيغة .csv في حالة الرفع', $template_source, '.csv');

// 4) xls موضحة كغير مدعومة
check_contains('4. صيغة .xls موضَّحة صراحة كغير مدعومة', $template_source, '.xls</span> القديمة غير مدعومة');

// 5) Phone guidance ظاهر
check_contains('5. تنبيه عمود الجوال ظاهر بالنص المطلوب', $template_source, 'لضمان حفظ أرقام الجوال بشكل صحيح، استخدم نموذج Excel الرسمي ولا تغيّر تنسيق عمود رقم الجوال.');

// 6) Upload state
check_contains('6. حالة الرفع (excelUploadState) موجودة', $template_source, 'id="excelUploadState"');
check_contains('6ب. عنوان حالة الرفع: "استيراد المدعوين من Excel"', $template_source, 'استيراد المدعوين من Excel');
check_contains('6ج. الوصف القصير المطلوب حرفياً', $template_source, 'ارفع ملف Excel أو CSV يحتوي على الاسم ورقم الجوال والملاحظة.');

// 7) Validating state (Phase 5.1: النص المحدَّث + Spinner)
check_contains('7. حالة التحقق (excelValidatingState) موجودة', $template_source, 'id="excelValidatingState"');
check_contains('7ب. نص "جارٍ رفع الملف والتحقق منه..." موجود (Phase 5.1)', $template_source, 'جارٍ رفع الملف والتحقق منه...');

// 8) Preview summary
check_contains('8. صندوق ملخص المعاينة (excelSummaryBox) موجود', $template_source, 'id="excelSummaryBox"');
check_contains("8ب. تسمية 'إجمالي الصفوف' ضمن الملخص", $template_source, "label: 'إجمالي الصفوف'");
check_contains("8ج. تسمية 'سيتم استيراد' ضمن الملخص", $template_source, "label: 'سيتم استيراد'");
check_contains("8د. تسمية 'مكرَّر' ضمن الملخص", $template_source, "label: 'مكرَّر' }");

// 9) Preview table headers
check_contains('9. رأس عمود "الاسم" في جدول المعاينة', $template_source, '<th scope="col" class="px-2.5 py-2 bg-secondary/95">الاسم</th>');
check_contains('9ب. رأس عمود "الجوال" في جدول المعاينة', $template_source, '<th scope="col" class="px-2.5 py-2 bg-secondary/95">الجوال</th>');
check_contains('9ج. رأس عمود "الملاحظة" في جدول المعاينة', $template_source, '<th scope="col" class="px-2.5 py-2 bg-secondary/95">الملاحظة</th>');
check_contains('9د. رأس عمود "الحالة" في جدول المعاينة', $template_source, '<th scope="col" class="px-2.5 py-2 bg-secondary/95">الحالة</th>');

// 10) status labels عربية — يُعرَض row.status_label القادم من الخادم (عربي جاهز)، لا status الخام كنص ظاهر
$render_preview_rows_body = extract_js_function_body($template_source, 'excelRenderPreviewRows');
check_true('10. جدول المعاينة يعرض row.status_label (عربي جاهز من الخادم)', strpos($render_preview_rows_body, 'row.status_label') !== false);
check_true("10ب. لا عرض نصّي مباشر لـrow.status الخام (يُستخدَم فقط لاختيار فئة الشارة CSS)", substr_count($render_preview_rows_body, 'row.status]') <= 1 && strpos($render_preview_rows_body, '>' . '\' + escapeHtml(row.status)') === false);

// 11) valid=0 يعطل Confirm + رسالة توضيحية
check_contains('11. منطق تعطيل Confirm عند عدم وجود صفوف صالحة', $template_source, 'var canImport = !!excelUploadToken && validCount > 0;');
check_contains('11ب. excelConfirmBtn.disabled يُشتَق من canImport', $template_source, 'excelConfirmBtn.disabled = !canImport;');
check_contains('11ج. رسالة "لا توجد صفوف صالحة للاستيراد." موجودة في الترميز', $template_source, 'لا توجد صفوف صالحة للاستيراد.');
check_contains('11د. إظهار/إخفاء رسالة عدم وجود صفوف صالحة مرتبط بـvalidCount', $template_source, "excelNoValidMsg.classList.toggle('hidden', validCount !== 0);");

// 12) Confirm يعرض عدد valid الحقيقي
check_contains('12. نص زر Confirm يتضمَّن عدد valid الحقيقي القادم من Preview', $template_source, "excelConfirmBtn.textContent = 'استيراد ' + validCount + ' مدعو';");

// 13) Double-click prevention
$confirm_listener = extract_listener_body($template_source, "excelConfirmBtn.addEventListener('click', function () {");
check_true('13. حارس الدخول المزدوج في بداية معالج نقرة Confirm (excelInFlight)', strpos($confirm_listener, 'if (excelInFlight || !excelUploadToken) return;') !== false);
check_true('13ب. تعطيل الزر فوراً داخل نفس المعالج قبل أي طلب شبكة', strpos($confirm_listener, 'excelConfirmBtn.disabled = true;') !== false);

// 14) Importing state (Phase 5.1: Spinner + النص المحدَّث "جارٍ استيراد المدعوين...")
check_contains('14. حالة المعالجة (excelProcessingState) موجودة مع aria-live', $template_source, 'id="excelProcessingState" class="hidden py-10 text-center" aria-live="polite">');
check_contains('14أ. نص "جارٍ استيراد المدعوين..." ظاهر داخل حالة المعالجة', $template_source, 'جارٍ استيراد المدعوين...');
check_true('14ب. نص الزر نفسه يتحوَّل إلى "جارٍ استيراد المدعوين..." عند النقر', strpos($confirm_listener, "excelConfirmBtn.textContent = 'جارٍ استيراد المدعوين...';") !== false);
check_true('14ج. لا عرض لـupload_token أو أي معلومة تقنية داخل نص حالة المعالجة', strpos($confirm_listener, "'جارٍ استيراد المدعوين...'") !== false && strpos($confirm_listener, 'excelUploadToken +') === false);

// 15) Result success message
check_contains("15. رسالة النجاح الواضحة عند imported > 0 وبلا تخطٍّ", $template_source, "resultText = 'تم استيراد ' + imported + ' مدعو بنجاح.';");

// 16) Partial success message
check_contains('16. رسالة النجاح الجزئي عند وجود صفوف مستوردة وأخرى متخطّاة معاً', $template_source, "resultText = 'تم استيراد ' + imported + ' مدعو، وتعذر استيراد ' + skipped + '.';");

// 17) imported=0 لا يظهر Success مضلل
$confirm_then_body = extract_js_function_body($template_source, 'function (json) {') ;
// نطاق أدق: نفحص فرعي شرط imported=0 داخل معالج Confirm تحديداً (السلسلة أعلاه ضمن نفس confirm_listener).
check_true('17. عند total_rows>0 وimported=0: النص لا يحتوي "بنجاح" (لا رسالة نجاح مضلِّلة)', strpos($confirm_listener, "resultText = 'لم يُضَف أي مدعو") !== false);
check_true('17ب. showToast لا يُستدعى إلا إذا imported > 0', strpos($confirm_listener, 'if (imported > 0) showToast(resultText, false);') !== false);

// 18-21) تحويل رموز أخطاء الخادم إلى رسائل عربية صديقة
check_contains('18. تعيين رسالة عربية لـtoken_not_found', $template_source, "token_not_found: 'انتهت جلسة الاستيراد أو تم تنفيذها مسبقاً. أعد رفع الملف.'");
check_contains('19. تعيين رسالة عربية لـunsupported_extension', $template_source, "unsupported_extension: 'صيغة الملف غير مدعومة. استخدم XLSX أو CSV.'");
check_contains('20. تعيين رسالة عربية لـinvalid_columns', $template_source, "invalid_columns: 'تنسيق الأعمدة لا يطابق نموذج الاستيراد.'");
check_contains('21. تعيين رسالة عربية لملف Excel تالف (malformed_xlsx/xlsx_parse_error)', $template_source, "malformed_xlsx: 'تعذر قراءة ملف Excel. تأكد من أن الملف غير تالف.'");
check_contains('21ب. تعيين رسالة عربية إضافية لـxlsx_parse_error (نفس الرسالة، رمز خادم مختلف)', $template_source, "xlsx_parse_error: 'تعذر قراءة ملف Excel. تأكد من أن الملف غير تالف.'");
check_true(
    '21ج. لا تسريب لأي stack trace/PHP error/مسار ملف/رمز حالة داخلي ضمن خريطة الرسائل نفسها',
    strpos($template_source, 'Stack trace') === false && strpos($template_source, '.php on line') === false
);

// 22) لا token ظاهر بالواجهة — لا نص/HTML يُدرِج قيمة excelUploadToken داخل عنصر مرئي للمستخدم
check_true(
    '22. excelUploadToken لا يُدرَج في أي .textContent/.innerHTML ظاهر (استخدام حصراً لإرسال الطلب والتحقق الداخلي)',
    !preg_match('/\.(textContent|innerHTML)\s*=[^;]*excelUploadToken/', $template_source)
);

// 23) لا file_path ظاهر — السلسلة الحرفية 'file_path' غائبة كلياً عن طبقة الواجهة (الخادم لا يُرسلها أصلاً)
check_not_contains('23. لا أي ذِكر لـfile_path داخل event-invitations.php', $template_source, 'file_path');

// 24) aria dialog
check_contains('24. excelImportModal يحمل role="dialog"', $template_source, 'id="excelImportModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog"');
check_contains('24ب. excelImportModal يحمل aria-modal="true"', $template_source, 'aria-modal="true" aria-labelledby="excelImportHeading"');

// 25) aria-live/alert
check_contains('25. excelFileInfo يحمل aria-live="polite"', $template_source, 'id="excelFileInfo" class="hidden mt-1.5 text-[11px] text-foreground/60" aria-live="polite"');
check_contains('25ب. excelValidatingState يحمل aria-live="polite" (Phase 5.1)', $template_source, 'id="excelValidatingState" class="hidden py-10 text-center" aria-live="polite"');
check_contains('25ج. excelResultMsg يحمل aria-live="polite"', $template_source, 'id="excelResultMsg" class="mb-3 text-sm font-bold text-foreground" aria-live="polite"');
check_contains('25د. excelImportErrorMsg يحمل role="alert"', $template_source, 'id="excelImportErrorMsg" class="hidden mb-3 text-xs font-semibold rounded-xl px-3 py-2 bg-destructive/10 text-destructive-text" role="alert"');
check_contains('25هـ. excelNoValidMsg يحمل role="alert"', $template_source, 'id="excelNoValidMsg" class="hidden mt-2 text-xs font-semibold text-destructive-text" role="alert"');

// 26) Mobile overflow guard
check_contains('26. صف أزرار حالة الرفع قابل للالتفاف (flex-wrap) لمنع overflow أفقي', $template_source, "<div class=\"flex flex-wrap justify-end gap-2 mt-3\">\n          <button type=\"button\" id=\"excelCancelBtn\"");
check_contains('26ب. صف أزرار حالة المعاينة قابل للالتفاف (flex-wrap)', $template_source, "<div class=\"flex flex-wrap justify-end gap-2 mt-3\">\n          <button type=\"button\" id=\"excelBackBtn\"");
check_contains('26ج. غلاف جدول المعاينة يدعم scroll أفقي وعمودي محدود (overflow-x-auto + max-h-72 overflow-y-auto)', $template_source, 'overflow-x-auto rounded-xl border border-border max-h-72 overflow-y-auto');

// 27) لا تغيير في Payload الـConfirm — upload_token فقط
check_contains(
    "27. استدعاء postAjax('pge_invitation_mgmt_excel_confirm', ...) يحمل upload_token فقط (لا تغيير عن Phase 4)",
    $template_source,
    "postAjax('pge_invitation_mgmt_excel_confirm', { upload_token: excelUploadToken })"
);

// 28) لا إرسال rows/counts للBackend
check_true("28. جسم معالج Confirm لا يحوي أي إرسال لمفتاح 'rows:' ضمن استدعاء postAjax", !preg_match('/postAjax\([^)]*rows\s*:/', $confirm_listener));
check_true("28ب. جسم معالج Confirm لا يحوي أي إرسال لمفتاح 'count:' ضمن استدعاء postAjax", !preg_match('/postAjax\([^)]*count\s*:/', $confirm_listener));

// 29) القائمة تتحدث بعد نجاح العملية
$close_result_listener = extract_listener_body($template_source, "document.getElementById('excelCloseResultBtn').addEventListener('click', function () {");
check_true("29. زر إغلاق النتيجة يستدعي fetchList(1) لتحديث القائمة", strpos($close_result_listener, 'fetchList(1);') !== false);

// 30) بقية Bulk Add UI لم تتأثر
check_contains('30. نافذة الإضافة الجماعية (bulkAddModal) لا تزال موجودة بلا مساس', $template_source, 'id="bulkAddModal"');
check_contains('30ب. bulkOpenModal لا تزال موجودة', $template_source, 'function bulkOpenModal()');
check_contains('30ج. BULK_STATUS_LABELS لا تزال موجودة بنفس القيم', $template_source, "valid: 'صالح', invalid: 'غير صالح',");
check_contains('30د. bulkRenderSummaryBadges لا تزال موجودة ومنفصلة عن excelRenderSummaryBadges', $template_source, 'function bulkRenderSummaryBadges(container, summary, keyLabels)');
check_true('30هـ. لا تعارض تسمية: الدوال الخاصة بـExcel جميعها مسبوقة بـexcel وليست bulk', strpos($template_source, 'function excelRenderSummaryBadges(container, summary, keyLabels)') !== false);

// ============================================================================
// Phase 5.1 — Loading Feedback فقط (Spinner/تعطيل أزرار)، بلا Queue/Polling/SSE
// ============================================================================

// Spinner مرئي في حالتَي التحقق والمعالجة (Tailwind animate-spin فقط، بلا مكتبة جديدة).
check_contains('31. Spinner ظاهر في حالة التحقق (excelValidatingState)', $template_source, 'border-4 border-border border-t-primary animate-spin');
check_true('31ب. Spinner واحد على الأقل في حالة المعالجة أيضاً (نمط مطابق)', substr_count($template_source, 'border-4 border-border border-t-primary animate-spin') >= 2);
check_contains('31ج. تلميح "قد تستغرق العملية عدة ثوانٍ" بلا أرقام/تقدير زمني', $template_source, 'إذا كان الملف كبيراً فقد تستغرق العملية عدة ثوانٍ.');
check_true('31د. لا رقم تقديري (بالثواني/الدقائق) مذكور في التلميح', !preg_match('/\d+\s*(ثانية|ثوانٍ|دقيقة|دقائق)/u', $template_source));

// منع Double Submit: تعطيل صريح لكل زر قادر على إطلاق طلب جديد أو مقاطعة الحالة.
check_contains('32. زر الرفع يُعطَّل أثناء طلب Preview', $template_source, 'excelUploadBtn.disabled = true;');
check_contains('32ب. حقل اختيار الملف يُعطَّل أثناء طلب Preview', $template_source, 'excelFileInput.disabled = true;');
check_contains('32ج. زر إغلاق الـModal (×) يُعطَّل أثناء طلب Preview', $template_source, 'closeExcelImportBtn.disabled = true;');
check_true('32د. زر إغلاق الـModal يُعاد تفعيله في مسار النجاح لطلب Preview', strpos($template_source, "closeExcelImportBtn.disabled = false;") !== false);
check_true(
    '32هـ. زر إغلاق الـModal يُعاد تفعيله في مسار فشل الاتصال (catch) لطلب Preview أيضاً',
    (function () use ($template_source) {
        $anchor = "postFileAjax('pge_invitation_mgmt_excel_preview', file).then(function (json) {";
        $start = strpos($template_source, $anchor);
        if ($start === false) return false;
        $catch_pos = strpos($template_source, '.catch(function () {', $start);
        $next_block = substr($template_source, $catch_pos, 260);
        return strpos($next_block, 'closeExcelImportBtn.disabled = false;') !== false;
    })()
);
check_true('32و. حارس excelInFlight لا يزال أول سطر فعلي في معالج نقرة Confirm (منع تنفيذ متزامن)', strpos(ltrim($confirm_listener, "{ \n\r\t"), 'if (excelInFlight || !excelUploadToken) return;') === 0);
check_true('32ز. زر إغلاق الـModal يُعطَّل أيضاً عند بدء Confirm', strpos($confirm_listener, 'closeExcelImportBtn.disabled = true;') !== false);
check_true('32ح. زر إغلاق الـModal يُعاد تفعيله بعد استجابة Confirm (نجاح أو فشل)', strpos($confirm_listener, 'closeExcelImportBtn.disabled = false;') !== false);

// Accessibility: Spinner لا يعتمد على اللون وحده — دائماً مرافَق بنص داخل نفس الحاوية aria-live.
check_true('33. Spinner يحمل aria-hidden="true" (زخرفي بحت، النص المرافق هو الحامل الدلالي)', substr_count($template_source, 'animate-spin" aria-hidden="true">') >= 2);
check_true(
    '33ب. كل Spinner مرافَق بنص داخل نفس الحاوية aria-live="polite" (لا اعتماد على اللون فقط)',
    strpos($template_source, 'aria-live="polite">' . "\n        <div class=\"mx-auto mb-3 h-8 w-8 rounded-full border-4 border-border border-t-primary animate-spin\" aria-hidden=\"true\"></div>\n        <p class=\"text-sm font-semibold text-foreground/70\">جارٍ رفع الملف والتحقق منه...") !== false
    && strpos($template_source, 'aria-live="polite">' . "\n        <div class=\"mx-auto mb-3 h-8 w-8 rounded-full border-4 border-border border-t-primary animate-spin\" aria-hidden=\"true\"></div>\n        <p class=\"text-sm font-semibold text-foreground/70\">جارٍ استيراد المدعوين...") !== false
);

// Performance: لا مكتبة/Animation ثقيلة جديدة — فقط Tailwind الموجود (animate-spin مدمجة أصلاً).
check_not_contains('34. لا استيراد مكتبة Animation جديدة (مثل GSAP/Lottie/anime.js)', $template_source, 'gsap');
check_not_contains('34ب. لا وسم <script src= خارجي جديد داخل هذا الملف', $template_source, '<script src=');

// Regression الأساسي المضمَّن هنا: لا Endpoint/Route/Queue/Polling/SSE جديد.
check_not_contains('35. لا استدعاء setInterval (لا Polling)', $template_source, 'setInterval(');
check_not_contains('35ب. لا استخدام EventSource (لا SSE)', $template_source, 'EventSource');
check_not_contains('35ج. لا استخدام WebSocket', $template_source, 'WebSocket');
check_true('35د. لا إجراء AJAX جديد يخص Excel غير المعروفَين أصلاً (preview/confirm فقط)', substr_count($template_source, "pge_invitation_mgmt_excel_") === substr_count($template_source, 'pge_invitation_mgmt_excel_preview') + substr_count($template_source, 'pge_invitation_mgmt_excel_confirm') + substr_count($template_source, 'pge_invitation_mgmt_excel_template'));

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if ($failures) {
    echo "فشل:\n";
    foreach ($failures as $f) echo " - $f\n";
} else {
    echo "كل اختبارات Phase 5 (Excel Import UX/UI) نجحت.\n";
}
