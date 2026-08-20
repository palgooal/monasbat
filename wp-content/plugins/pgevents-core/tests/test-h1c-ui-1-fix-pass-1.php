<?php
/**
 * اختبار انحدار صغير — H1C-UI-1 Fix Pass 1 (Page Gate Alignment + Footer/Script
 * Ordering) — templates/event-groups.php فقط.
 *
 * لا يبني WordPress bootstrap كامل (لا حاجة له لهاتين النقطتين تحديداً). بدلاً
 * من ذلك:
 *  - يستخرج تعبير $can_manage الفعلي من مصدر القالب حرفياً عبر Regex، ثم
 *    يُنفِّذه فعلياً (eval) ضمن 3 سيناريوهات محاكاة لإثبات أن السلوك الفعلي
 *    (وليس افتراضاً منطقياً) يطابق نطاق H1C Owner/Admin بالضبط: لا نسخة
 *    منطقية موازية للشرط — نفس النص المصدري بالحرف يُنفَّذ.
 *  - يتحقق من ترتيب get_footer() مقابل </script> عبر مواضع النص الفعلية في
 *    الملف (لا تخمين).
 *
 * التشغيل: php tests/test-h1c-ui-1-fix-pass-1.php
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

$template_path = __DIR__ . '/../templates/event-groups.php';
check_true('0. ملف القالب موجود', file_exists($template_path));
$source = file_get_contents($template_path);

// ============================================================================
// السيناريو أ — Issue A: استخراج تعبير $can_manage الفعلي من المصدر وتنفيذه
// ============================================================================
$gate_pattern = '/\$can_manage\s*=\s*(.*?);\s*\n\nif \(!\$can_manage\)/s';
$has_gate = (bool) preg_match($gate_pattern, $source, $m);
check_true('A1. تم العثور على تعبير $can_manage في القالب', $has_gate);

$gate_expr = $has_gate ? trim($m[1]) : '';

check_true('A2. الشرط لا يستدعي pge_event_guests_user_can_manage()', strpos($gate_expr, 'pge_event_guests_user_can_manage') === false);
check_true("A3. الشرط لا يحتوي على current_user_can('edit_post'", strpos($gate_expr, "'edit_post'") === false);
check_true("A4. الشرط يحتوي على current_user_can('administrator')", strpos($gate_expr, "'administrator'") !== false);
check_true('A5. الشرط يقارن post_author مع get_current_user_id()', strpos($gate_expr, 'post_author') !== false && strpos($gate_expr, 'get_current_user_id') !== false);

// تنفيذ التعبير المُستخرَج فعلياً (نفس نص المصدر بالحرف) ضمن 3 سيناريوهات.
function run_gate_scenario($gate_expr, $is_admin, $current_user_id, $post_author_id)
{
    $GLOBALS['__scn_is_admin'] = $is_admin;
    $GLOBALS['__scn_uid'] = $current_user_id;

    if (!function_exists('current_user_can')) {
        function current_user_can($cap, $object_id = null) {
            if ($cap === 'administrator') return $GLOBALS['__scn_is_admin'];
            // edit_post أو أي قدرة أخرى: يجب ألا يُستدعى إطلاقاً بواسطة الشرط
            // (Issue A) — لو استُدعي، فهذا يعني الشرط عاد يعتمد على edit_post.
            $GLOBALS['__scn_unexpected_cap_call'] = $cap;
            return true; // لو استُدعي فعلياً، نجعله "سخياً" ليكشف الخطأ عبر check منفصل
        }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id() { return $GLOBALS['__scn_uid']; }
    }

    $event_post = (object) ['post_author' => $post_author_id];
    $GLOBALS['__scn_unexpected_cap_call'] = null;

    $can_manage = null;
    eval('$can_manage = ' . $gate_expr . ';');
    return [(bool) $can_manage, $GLOBALS['__scn_unexpected_cap_call']];
}

if ($has_gate) {
    // السيناريو 1: Administrator (post_author لا يطابق) → يجب أن يمرّ.
    [$r1, $unexpected1] = run_gate_scenario($gate_expr, true, 501, 999);
    check_true('A6. Administrator يجتاز page gate', $r1);
    check('A6b. لا استدعاء غير متوقَّع لقدرة أخرى (Administrator scenario)', $unexpected1, null);

    // السيناريو 2: Owner فعلي (post_author مطابق، ليس Administrator) → يجب أن يمرّ.
    [$r2, $unexpected2] = run_gate_scenario($gate_expr, false, 501, 501);
    check_true('A7. Owner (post_author مطابق) يجتاز page gate', $r2);
    check('A7b. لا استدعاء غير متوقَّع لقدرة أخرى (Owner scenario)', $unexpected2, null);

    // السيناريو 3: ليس Administrator وليس post_author (لو كان يملك edit_post
    // فقط لن يُستدعى إطلاقاً من هذا الشرط) → يجب أن يُرفَض.
    [$r3, $unexpected3] = run_gate_scenario($gate_expr, false, 501, 999);
    check('A8. مستخدم edit_post-only (ليس Admin وليس post_author) لا يجتاز page gate', $r3, false);
    check('A8b. الشرط لم يستدعِ current_user_can() بأي قدرة غير administrator (لا اعتماد على edit_post)', $unexpected3, null);
}

// ============================================================================
// السيناريو ب — Issue B: ترتيب get_footer() مقابل </script>
// ============================================================================
$pos_script_open = strpos($source, '<script>');
$pos_script_close = strrpos($source, '</script>');
$pos_get_footer = strrpos($source, 'get_footer();');

check_true('B1. الملف يحتوي على <script>', $pos_script_open !== false);
check_true('B2. الملف يحتوي على </script>', $pos_script_close !== false);
check_true('B3. الملف يحتوي على استدعاء get_footer()', $pos_get_footer !== false);

if ($pos_script_open !== false && $pos_script_close !== false && $pos_get_footer !== false) {
    check_true('B4. </script> يقع بعد <script> (بنية سليمة)', $pos_script_close > $pos_script_open);
    check_true('B5. get_footer() يقع بعد </script> (الترتيب الصحيح — لا يسبق JS الخاص بالقالب)', $pos_get_footer > $pos_script_close);
}

// تأكيد إضافي: get_footer() لا يظهر إطلاقاً بين <script> و</script> (أي: لا
// تكرار خاطئ للاستدعاء داخل الكتلة البرمجية نفسها).
if ($pos_script_open !== false && $pos_script_close !== false) {
    $script_block = substr($source, $pos_script_open, $pos_script_close - $pos_script_open);
    check_true('B6. لا استدعاء get_footer() داخل كتلة <script>...</script>', strpos($script_block, 'get_footer()') === false);
}

echo "\n----------------------------------------\n";
echo "Total: $total, Passed: $passed, Failed: " . count($failures) . "\n";
if (count($failures) > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
exit(0);
