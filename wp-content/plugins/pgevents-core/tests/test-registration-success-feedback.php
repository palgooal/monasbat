<?php
/**
 * E2E-01 FIX PASS 2 — Registration Success Feedback UX (one-time dashboard
 * notice), retargeted to the ACTUAL production /dashboard/ template.
 *
 * سبب إعادة الكتابة: FIX PASS 1 أضاف المستهلِك إلى
 * wp-content/themes/pgevents-pro/page-dashboard.php، وهو قالب لا يُستخدَم
 * إطلاقاً لمسار /dashboard/ الحقيقي على الإنتاج. أثبت التدقيق أن
 * wp-content/plugins/pgevents-core/includes/routing.php يُسجِّل
 * add_rewrite_rule('^dashboard/?$', 'index.php?pge_action=dashboard', 'top')
 * ثم template_include filter يُحوِّل pge_action=dashboard مباشرةً إلى
 * PGE_PATH . 'templates/dashboard-main.php' بلا أي locate_template() لصالح
 * الثيم (بخلاف register/login/create_event وغيرها التي تتبع نمط
 * theme-first/plugin-fallback). لذا نُقِل المستهلِك الحقيقي إلى
 * templates/dashboard-main.php، وأُزيلت الإضافة من page-dashboard.php، وهذا
 * الملف يختبر المسار الصحيح الآن + يُثبِت أن القديم أُزيل فعلاً + يُثبِت
 * أن التوجيه نفسه يُشير لنفس الملف المفحوص هنا.
 *
 * تصنيف الأدلة (لا خلط بين الأنواع):
 *
 * القسم 1 = تنفيذ حقيقي لمقتطف مُستخرَج من الكود الحقيقي (Extracted
 * Statement Execution). المنطق الجديد مضمَّن مباشرة داخل ملفَي قالب
 * إجرائيين (page-register.php/templates/dashboard-main.php) يستدعي كلاهما
 * exit/redirect ولا يمكن require()هما كاملين بأمان داخل عملية اختبار واحدة.
 * لذا نستخرج المقتطف الحرفي الدقيق (سطر/كتلة) من نص الملف الحقيقي عبر
 * Regex/مواضع نصية، ثم نُنفِّذه فعلياً عبر eval() ضمن Stubs دنيا لـ
 * set_transient/get_transient/delete_transient. هذا تنفيذ PHP حقيقي لأسطر
 * الإنتاج الحرفية نفسها (لا إعادة كتابة/محاكاة منطقية لها) — لكنه ليس
 * اختبار وحدة على دالة/صنف مستقل قابل للاستدعاء المباشر (لا يوجد هنا)،
 * وليس اختبار تكامل HTTP لووردبريس.
 *
 * القسم 2 = فحص نصي/حدودي (Source/Boundary Scan) — لا يُشغِّل أي كود. يفحص
 * محتوى page-register.php/templates/dashboard-main.php/page-dashboard.php/
 * includes/routing.php الحقيقيين كنص خام لإثبات الترتيب النسبي، عدم
 * التكرار، عدم الاعتماد على $_GET/$_REQUEST، عدم تسرّب أي بيانات شخصية،
 * إزالة المستهلِك القديم فعلياً، وأن التوجيه الحقيقي يُشير لنفس الملف
 * المفحوص في القسم 1.
 *
 * لا يوجد اختبار تكامل HTTP كامل لووردبريس في هذا الملف أو في المشروع
 * حالياً يُغطي هذا التدفق من متصفح حقيقي (عبر rewrite rules الفعلية لخادم
 * الإنتاج) حتى قاعدة بيانات حقيقية — هذا الملف هو الدليل الآلي المتاح فقط،
 * ويجب عدم وصفه كاختبار تكامل كامل أو كإثبات لسلوك خادم الإنتاج نفسه.
 *
 * التشغيل:
 *   php tests/test-registration-success-feedback.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

define('MINUTE_IN_SECONDS', 60);

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

function check_contains($label, $haystack, $needle)
{
    check_true($label, is_string($haystack) && strpos($haystack, $needle) !== false);
}

function check_not_contains($label, $haystack, $needle)
{
    check_true($label, is_string($haystack) && strpos($haystack, $needle) === false);
}

// أسطر التعليق (// ...) قد تشرح عمداً أن الكود لا يعتمد على $_GET/$_REQUEST
// — فوجود هذه السلاسل الحرفية داخل نص التعليق التوضيحي نفسه ليس دليل
// اعتماد فعلي في الكود. لتفادي إيجابيات كاذبة، تُستبعَد أسطر التعليق بالكامل
// (كل سطر يبدأ — بعد إزالة الفراغات — بـ//) قبل فحص أي اعتماد فعلي على
// المدخلات الزائرة في هذه الفحوصات تحديداً؛ لا تُستخدَم هذه النسخة المُنظَّفة
// في أي فحص آخر.
function strip_line_comments($src)
{
    $lines = explode("\n", $src);
    foreach ($lines as $i => $line) {
        if (strpos(ltrim($line), '//') === 0) {
            $lines[$i] = '';
        }
    }
    return implode("\n", $lines);
}

$register_path = __DIR__ . '/../../../themes/pgevents-pro/page-register.php';
$old_dashboard_path = __DIR__ . '/../../../themes/pgevents-pro/page-dashboard.php';
$dashboard_path = __DIR__ . '/../templates/dashboard-main.php';
$routing_path = __DIR__ . '/../includes/routing.php';

$register_src = file_exists($register_path) ? (string) file_get_contents($register_path) : '';
$old_dashboard_src = file_exists($old_dashboard_path) ? (string) file_get_contents($old_dashboard_path) : '';
$dashboard_src = file_exists($dashboard_path) ? (string) file_get_contents($dashboard_path) : '';
$routing_src = file_exists($routing_path) ? (string) file_get_contents($routing_path) : '';

check_true('ملف القالب page-register.php موجود وقابل للقراءة', $register_src !== '');
check_true('ملف القالب القديم page-dashboard.php موجود وقابل للقراءة', $old_dashboard_src !== '');
check_true('ملف القالب الحقيقي templates/dashboard-main.php موجود وقابل للقراءة', $dashboard_src !== '');
check_true('ملف التوجيه includes/routing.php موجود وقابل للقراءة', $routing_src !== '');

// ═══════════════════════════════════════════════════════════════════════
// R12 — التوجيه الحقيقي يُشير لنفس الملف المفحوص هنا (routing source scan)
// ═══════════════════════════════════════════════════════════════════════

check_contains('R12: قاعدة إعادة الكتابة الحقيقية تُحوِّل /dashboard/ إلى pge_action=dashboard', $routing_src, "add_rewrite_rule('^dashboard/?$', 'index.php?pge_action=dashboard', 'top')");

$dash_action_pos = strpos($routing_src, "if (\$action === 'dashboard')");
check_true('R12: يوجد فرع template_include صريح لـ $action === \'dashboard\'', $dash_action_pos !== false);

$dash_action_block = $dash_action_pos !== false
    ? substr($routing_src, $dash_action_pos, 220)
    : '';
check_contains('R12: فرع dashboard يُحوِّل مباشرةً إلى templates/dashboard-main.php (بلا locate_template لصالح الثيم)', $dash_action_block, "PGE_PATH . 'templates/dashboard-main.php'");
check_not_contains('R12: فرع dashboard لا يستخدم locate_template() (بخلاف register/login — يذهب للإضافة مباشرةً)', $dash_action_block, 'locate_template');

// ═══════════════════════════════════════════════════════════════════════
// R11 — القالب القديم page-dashboard.php لم يعد يحوي مستهلِك/عرض E2E-01
// ═══════════════════════════════════════════════════════════════════════

check('R11: لا إشارة إطلاقاً لـpge_registration_success في القالب القديم page-dashboard.php', substr_count($old_dashboard_src, 'pge_registration_success'), 0);
check('R11: لا إشارة إطلاقاً لتعليق E2E-01 في القالب القديم page-dashboard.php', substr_count($old_dashboard_src, 'E2E-01'), 0);

// ═══════════════════════════════════════════════════════════════════════
// القسم 1 — تنفيذ حقيقي لمقتطف مُستخرَج (Extracted Statement Execution)
// ═══════════════════════════════════════════════════════════════════════

// --- R1: استخراج سطر set_transient(...) الحرفي من page-register.php ---
$set_transient_pattern = '/set_transient\(\s*\'pge_registration_success_\'\s*\.\s*\$new_user_id\s*,\s*1\s*,\s*MINUTE_IN_SECONDS\s*\*\s*5\s*\)\s*;/';
$set_transient_matches = [];
preg_match_all($set_transient_pattern, $register_src, $set_transient_matches);
check('R1: استخراج سطر set_transient(...) الحرفي من page-register.php — مرة واحدة بالضبط', count($set_transient_matches[0]), 1);
$extracted_register_stmt = $set_transient_matches[0][0] ?? '';

$GLOBALS['rsf_set_transient_calls'] = [];
function set_transient($key, $value, $ttl)
{
    $GLOBALS['rsf_set_transient_calls'][] = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
    return true;
}

$new_user_id = 777;
if ($extracted_register_stmt !== '') {
    eval($extracted_register_stmt);
}

check('R1: استدعاء set_transient() واحد بالضبط بعد تنفيذ السطر الحقيقي', count($GLOBALS['rsf_set_transient_calls']), 1);
$call0 = $GLOBALS['rsf_set_transient_calls'][0] ?? ['key' => null, 'value' => null, 'ttl' => null];
check('R1: مفتاح الـTransient = pge_registration_success_777 (خاص بهذا المستخدم فقط)', $call0['key'], 'pge_registration_success_777');
check('R1: القيمة المخزَّنة = 1 فقط (لا بيانات شخصية)', $call0['value'], 1);
check('R1: مدة الصلاحية = MINUTE_IN_SECONDS * 5 (تنتهي تلقائياً، لا تبقى للأبد)', $call0['ttl'], MINUTE_IN_SECONDS * 5);

// --- R2/R3/R4/R6 — استخراج كتلة استهلاك الإشعار الحرفية من dashboard-main.php ---
$dash_start_needle = '$pge_show_registration_success = false;';
$dash_delete_needle = 'delete_transient($pge_registration_success_key);';
$dash_start_pos = strpos($dashboard_src, $dash_start_needle);
$dash_delete_pos = $dash_start_pos !== false ? strpos($dashboard_src, $dash_delete_needle, $dash_start_pos) : false;
$dash_block_end_pos = false;
if ($dash_delete_pos !== false) {
    $close_brace_pos = strpos($dashboard_src, '}', $dash_delete_pos);
    $dash_block_end_pos = $close_brace_pos !== false ? $close_brace_pos + 1 : false;
}
$extracted_dashboard_block = ($dash_start_pos !== false && $dash_block_end_pos !== false)
    ? substr($dashboard_src, $dash_start_pos, $dash_block_end_pos - $dash_start_pos)
    : '';

check_true('R2: استخراج كتلة استهلاك الإشعار الحرفية من templates/dashboard-main.php', $extracted_dashboard_block !== '');
check_contains('R2: الكتلة المُستخرَجة تحوي get_transient() كما هو متوقع', $extracted_dashboard_block, 'get_transient(');
check_contains('R2: الكتلة المُستخرَجة تحوي delete_transient() كما هو متوقع', $extracted_dashboard_block, 'delete_transient(');

$GLOBALS['rsf_transient_store'] = [];
$GLOBALS['rsf_delete_transient_calls'] = [];
function get_transient($key)
{
    return $GLOBALS['rsf_transient_store'][$key] ?? false;
}
function delete_transient($key)
{
    $GLOBALS['rsf_delete_transient_calls'][] = $key;
    unset($GLOBALS['rsf_transient_store'][$key]);
    return true;
}

// R3: نفس المستخدم المُسجَّل حديثاً يرى الإشعار ويُستهلَك فوراً
$GLOBALS['rsf_transient_store'] = ['pge_registration_success_501' => 1];
$GLOBALS['rsf_delete_transient_calls'] = [];
$user_id = 501;
$pge_show_registration_success = null;
eval($extracted_dashboard_block);
check_true('R3: الإشعار يظهر عندما تكون العلامة موجودة لنفس المستخدم الحالي', $pge_show_registration_success === true);
check('R4: delete_transient() يُستدعى مرة واحدة بالمفتاح الصحيح (استهلاك فوري لمرة واحدة)', $GLOBALS['rsf_delete_transient_calls'], ['pge_registration_success_501']);
check_true('R4: العلامة فعلياً حُذفت من التخزين بعد الاستهلاك', !isset($GLOBALS['rsf_transient_store']['pge_registration_success_501']));

// R5/R7: زيارة مباشرة بلا تسجيل (لا علامة على الإطلاق) — لا إشعار
$GLOBALS['rsf_transient_store'] = [];
$GLOBALS['rsf_delete_transient_calls'] = [];
$user_id = 502;
$pge_show_registration_success = null;
eval($extracted_dashboard_block);
check_true('R5/R7: الإشعار لا يظهر عند عدم وجود أي علامة (زيارة مباشرة بلا تسجيل، أو تكرار الزيارة بعد الاستهلاك)', $pge_show_registration_success === false);
check('R5/R7: لا استدعاء delete_transient() عند عدم وجود علامة', $GLOBALS['rsf_delete_transient_calls'], []);

// R6: مستخدم آخر لا يستهلك علامة مستخدم مختلف
$GLOBALS['rsf_transient_store'] = ['pge_registration_success_501' => 1];
$GLOBALS['rsf_delete_transient_calls'] = [];
$user_id = 999;
$pge_show_registration_success = null;
eval($extracted_dashboard_block);
check_true('R6: مستخدم آخر (999) لا يرى إشعار نجاح تسجيل مستخدم مختلف (501)', $pge_show_registration_success === false);
check('R6: لا استدعاء delete_transient() لمفتاح خاص بمستخدم آخر', $GLOBALS['rsf_delete_transient_calls'], []);
check_true('R6: علامة المستخدم 501 الأصلية بقيت كما هي (لم تُحذَف عن طريق الخطأ من قراءة مستخدم آخر)', ($GLOBALS['rsf_transient_store']['pge_registration_success_501'] ?? null) === 1);

// ═══════════════════════════════════════════════════════════════════════
// القسم 2 — فحص نصي/حدودي (Source/Boundary Scan)
// ═══════════════════════════════════════════════════════════════════════

// --- ترتيب السلك في page-register.php: send_welcome() → set_transient() → wp_safe_redirect ---
$send_welcome_needle = 'PGE_Registration_Email::send_welcome($new_user_id);';
$send_welcome_pos = strpos($register_src, $send_welcome_needle);
check('استدعاء send_welcome() الفعلي مرة واحدة بالضبط في page-register.php (بلا تغيير عن المراجعة السابقة)', substr_count($register_src, $send_welcome_needle), 1);

$transient_pos = strpos($register_src, "set_transient('pge_registration_success_", $send_welcome_pos !== false ? $send_welcome_pos : 0);
check_true('الترتيب: send_welcome() يقع قبل set_transient()', $send_welcome_pos !== false && $transient_pos !== false && $send_welcome_pos < $transient_pos);

$redirect_needle = 'wp_safe_redirect($redirect_to);';
$redirect_pos = $transient_pos !== false ? strpos($register_src, $redirect_needle, $transient_pos) : false;
check_true('الترتيب: set_transient() يقع قبل wp_safe_redirect', $transient_pos !== false && $redirect_pos !== false && $transient_pos < $redirect_pos);

$between_welcome_and_transient = ($send_welcome_pos !== false && $transient_pos !== false)
    ? substr($register_src, $send_welcome_pos, $transient_pos - $send_welcome_pos)
    : '';
check_not_contains('لا "if (" على نتيجة send_welcome() بين نقطتَي الاستدعاء (فشل البريد لا يمنع إنشاء العلامة) — R10', preg_replace('/\s+/', ' ', $between_welcome_and_transient), 'if (');

// --- set_transient() لا يظهر إلا في فرع النجاح (بعد نجاح wp_create_user) ---
$create_user_check_pos = strpos($register_src, 'is_wp_error($new_user_id)');
check_true('set_transient() يقع بعد التحقق من نجاح wp_create_user() — غير قابل للوصول من أي فرع خطأ (فشل تحقق/تكرار) — R7/R8', $transient_pos !== false && $create_user_check_pos !== false && $transient_pos > $create_user_check_pos);
check('عدد ظهورات set_transient(\'pge_registration_success_ في كامل page-register.php = 1 بالضبط', substr_count($register_src, "set_transient('pge_registration_success_"), 1);

// --- لا بيانات شخصية داخل استدعاء set_transient() نفسه — R9 ---
$pii_indicators = ['$email', '$phone', '$pass1', '$pass2', '$full_name', 'user_email', 'user_login'];
foreach ($pii_indicators as $ind) {
    check_not_contains("R9: لا إشارة لـ\"$ind\" داخل سطر set_transient() نفسه (لا بيانات شخصية في قيمة العلامة)", $extracted_register_stmt, $ind);
}

// --- كتلة استهلاك الإشعار نفسها (وليس templates/dashboard-main.php كاملاً؛
// هذا ملف لوحة تحكم حقيقي كبير وله استخدامات أخرى مشروعة لـ$_GET في مزايا
// أخرى غير متعلقة إطلاقاً بإشعار النجاح، مثل $_GET['event'] لاختيار مناسبة
// لعرض إحصاءاتها) لا تعتمد على $_GET/$_REQUEST لإظهار الإشعار — R8 ---
// (بعد استبعاد أسطر التعليق — انظر strip_line_comments أعلاه — لأن التعليق
// التوضيحي المُضاف في هذه المهمة يذكر "$_GET/$_REQUEST" نصاً ليشرح عدم
// الاعتماد عليهما، لا لأن الكود الفعلي يستخدمهما)
$notice_consumer_scope_code_only = strip_line_comments($extracted_dashboard_block);
check('R8: كتلة استهلاك الإشعار لا تحوي أي إشارة فعلية (خارج التعليقات) لـ$_GET', substr_count($notice_consumer_scope_code_only, '$_GET'), 0);
check('R8: كتلة استهلاك الإشعار لا تحوي أي إشارة فعلية (خارج التعليقات) لـ$_REQUEST', substr_count($notice_consumer_scope_code_only, '$_REQUEST'), 0);
check_not_contains('R8: لا معامل استعلام علني ?registered= يُستخدَم كمصدر وحيد للإشعار', $dashboard_src, "'registered'");

// --- مفتاح الـTransient في القالبين يستخدم نفس البادئة الحرفية بالضبط ---
check_contains('templates/dashboard-main.php يستخدم نفس بادئة المفتاح pge_registration_success_ الحرفية', $dashboard_src, "'pge_registration_success_' . \$user_id");

// --- $user_id في templates/dashboard-main.php مصدره $current_user->ID (المستخدم الحالي المُصادَق فعلياً)، غير قابل لإعادة التعيين من أي مدخل زائر قبل بناء المفتاح ---
$dash_user_id_def_pos = strpos($dashboard_src, '$user_id = $current_user->ID;');
$dash_key_build_pos = strpos($dashboard_src, "\$pge_registration_success_key = 'pge_registration_success_' . \$user_id;");
check_true('$user_id في templates/dashboard-main.php يُعرَّف من $current_user->ID (المستخدم المُصادَق) قبل بناء مفتاح العلامة', $dash_user_id_def_pos !== false && $dash_key_build_pos !== false && $dash_user_id_def_pos < $dash_key_build_pos);
$between_userid_and_key = ($dash_user_id_def_pos !== false && $dash_key_build_pos !== false)
    ? substr($dashboard_src, $dash_user_id_def_pos, $dash_key_build_pos - $dash_user_id_def_pos)
    : '';
check_not_contains('لا إعادة تعيين لـ$user_id من $_GET/$_POST/$_REQUEST بين التعريف وبناء المفتاح', strip_line_comments($between_userid_and_key), '$_');

// --- كتلة الإشعار في HTML لا تُظهر user_id/بريد/هاتف ---
$notice_start_pos = strpos($dashboard_src, '<?php if ($pge_show_registration_success): ?>');
$notice_end_pos = $notice_start_pos !== false ? strpos($dashboard_src, '<?php endif; ?>', $notice_start_pos) : false;
$notice_html = ($notice_start_pos !== false && $notice_end_pos !== false)
    ? substr($dashboard_src, $notice_start_pos, ($notice_end_pos + strlen('<?php endif; ?>')) - $notice_start_pos)
    : '';
check_true('كتلة إشعار النجاح موجودة ومحدَّدة بوضوح (if/endif) مرة واحدة', $notice_html !== '');
check('كتلة إشعار النجاح تظهر مرة واحدة بالضبط في templates/dashboard-main.php', substr_count($dashboard_src, '<?php if ($pge_show_registration_success): ?>'), 1);

check_not_contains('R9: كتلة HTML الخاصة بالإشعار لا تُظهر "$user_id"', $notice_html, '$user_id');
check_not_contains('R9: كتلة HTML الخاصة بالإشعار لا تُظهر "$email"', $notice_html, '$email');
check_not_contains('R9: كتلة HTML الخاصة بالإشعار لا تُظهر "user_email"', $notice_html, 'user_email');
check_not_contains('R9: كتلة HTML الخاصة بالإشعار لا تُظهر "$phone"', $notice_html, '$phone');
check_not_contains('R9: كتلة HTML الخاصة بالإشعار لا تُظهر "user_login"', $notice_html, 'user_login');
check_not_contains('R9: كتلة HTML الخاصة بالإشعار لا تُظهر "ID"', $notice_html, 'ID');

check_contains('كتلة الإشعار تستخدم role="status" (دلالة وصولية واضحة)', $notice_html, 'role="status"');
check_contains('كتلة الإشعار تستخدم لون النجاح المعتمد فعلياً في هذه اللوحة (emerald)', $notice_html, 'emerald');

echo "\n============================================\n";
echo "الإجمالي: $total | ناجح: $passed | فاشل: " . count($failures) . "\n";
if (count($failures) > 0) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "كل الحالات ناجحة.\n";
exit(0);
