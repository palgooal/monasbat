<?php
/**
 * E2E-01 — Registration Welcome Email.
 *
 * تصنيف الأدلة (مهم — لا خلط بين النوعين):
 *
 * القسم 1 = سلوك تشغيلي حقيقي (Runtime Unit/Standalone Test). يُشغِّل
 * الصنف الحقيقي includes/class-pge-registration-email.php فعلياً (PHP
 * ينفِّذه، لا محاكاة منطقية) دون أي تعديل عليه، عبر Stubs دنيا لدوال
 * ووردبريس الأولية التي يستدعيها فقط (get_userdata/is_email/
 * wp_specialchars_decode/get_bloginfo/home_url/wp_login_url/esc_html/
 * esc_url/wp_mail/error_log). هذا تنفيذ PHP حقيقي للصنف الحقيقي، لكنه
 * ليس اختبار تكامل — لا WordPress حقيقي، لا قاعدة بيانات، لا خادم HTTP
 * فعلي، لا طلب POST حقيقي إلى /register/.
 *
 * الأقسام 2 و3 = فحص نصي/حدودي (Source/Boundary Scan) — لا تُشغِّل أي
 * كود إطلاقاً. تفحص محتوى الملفات الحقيقية (page-register.php في القالب،
 * class-pge-registration-email.php، class-pge-additional-inviter-
 * onboarding.php) كنص خام (file_get_contents + string/regex) لإثبات حدود
 * السلك الفعلي ومواضع الاستخدام النسبية (قبل/بعد نقاط محددة في الملف) —
 * نفس نمط فحوصات الحدود (Boundary/Source-Scan) في ملفات tests/test-d2-
 * w*.php الموجودة فعلاً في هذا المشروع. هذا النمط يُثبت "ما يحويه/لا
 * يحويه الملف الحقيقي وبأي ترتيب نصي"، وليس "ما يحدث فعلياً عند تشغيل
 * WordPress حياً" — الفرق مقصود ومُوثَّق هنا صراحة.
 *
 * لا يوجد في هذا الملف أو في المشروع حالياً أي اختبار تكامل HTTP كامل
 * لووردبريس (لا طلب POST حقيقي لـ/register/، لا WordPress bootstrap حقيقي،
 * لا قاعدة بيانات حقيقية) يُغطي مسار التسجيل الذاتي كاملاً من المتصفح حتى
 * البريد. هذا الملف هو الدليل الآلي المتاح فقط، ويجب عدم وصفه كاختبار
 * تكامل كامل في أي تقرير.
 *
 * التشغيل:
 *   php tests/test-registration-email.php
 *
 * الخروج برمز 0 عند نجاح كل الحالات، أو 1 عند فشل أي حالة.
 */

define('ABSPATH', __DIR__ . '/');

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

function check_contains($label, $haystack, $needle)
{
    check_true($label, is_string($haystack) && strpos($haystack, $needle) !== false);
}

function check_not_contains($label, $haystack, $needle)
{
    check_true($label, is_string($haystack) && strpos($haystack, $needle) === false);
}

// ── Stubs دنيا لدوال ووردبريس التي يستدعيها الصنف الحقيقي فقط ──────────────

class RE_Test_WP_User
{
    public $ID;
    public $user_email;
    public $display_name;
    public $user_login;
    function __construct($id, $email, $display_name, $user_login)
    {
        $this->ID = $id;
        $this->user_email = $email;
        $this->display_name = $display_name;
        $this->user_login = $user_login;
    }
}

$GLOBALS['re_users'] = [];        // id => RE_Test_WP_User
$GLOBALS['re_mail_log'] = [];     // [['to'=>,'subject'=>,'body'=>,'headers'=>]]
$GLOBALS['re_mail_should_fail'] = false;
$GLOBALS['re_error_log'] = [];
$GLOBALS['re_bloginfo_name'] = 'منصبات';

function get_userdata($id)
{
    return $GLOBALS['re_users'][(int) $id] ?? false;
}

function is_email($v)
{
    return (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $v);
}

function wp_specialchars_decode($v, $flags = null)
{
    return htmlspecialchars_decode((string) $v, ENT_QUOTES);
}

function get_bloginfo($key)
{
    return $key === 'name' ? $GLOBALS['re_bloginfo_name'] : '';
}

function home_url($path = '')
{
    return 'https://example.test' . $path;
}

function wp_login_url($redirect = '')
{
    return 'https://example.test/wp-login.php' . ($redirect ? ('?redirect_to=' . rawurlencode($redirect)) : '');
}

function esc_html($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function esc_url($v)
{
    return (string) $v;
}

function wp_mail($to, $subject, $body, $headers = '', $attachments = [])
{
    if (!empty($GLOBALS['re_mail_should_fail'])) {
        return false;
    }
    $GLOBALS['re_mail_log'][] = [
        'to' => $to,
        'subject' => $subject,
        'body' => $body,
        'headers' => $headers,
    ];
    return true;
}

if (!function_exists('error_log')) {
    function error_log($m)
    {
        $GLOBALS['re_error_log'][] = $m;
        return true;
    }
}

// إعادة تعريف error_log الحقيقية غير ممكنة (دالة PHP أصلية)، لكن الصنف
// الحقيقي يستدعيها عبر function_exists('error_log') فقط إن لم تكن معرَّفة
// مسبقاً — بما أن PHP توفرها دوماً كدالة أصلية، لن يُستدعى الـstub أعلاه
// فعلياً؛ بدلاً من ذلك نلتقط سجلات الفشل عبر error_log الحقيقية بتحويل
// وجهتها مؤقتاً لملف مؤقت، ثم نقرأه — أدق من الاعتماد على function_exists.
$re_log_file = tempnam(sys_get_temp_dir(), 're_log_');
ini_set('error_log', $re_log_file);

require_once __DIR__ . '/../includes/class-pge-registration-email.php';

// ═══════════════════════════════════════════════════════════════════════
// القسم 1 — سلوك تشغيلي حقيقي (Runtime Unit/Standalone) لـ
// PGE_Registration_Email::send_welcome() الفعلي، عبر Stubs دنيا لووردبريس
// ═══════════════════════════════════════════════════════════════════════

// --- A/B/D: تسجيل ناجح، wp_mail ناجح ---
$GLOBALS['re_users'] = [];
$GLOBALS['re_mail_log'] = [];
$GLOBALS['re_mail_should_fail'] = false;
file_put_contents($re_log_file, '');

$GLOBALS['re_users'][501] = new RE_Test_WP_User(501, 'ahmed@example.test', 'أحمد علي', '501000501');

$result_success = PGE_Registration_Email::send_welcome(501);

check_true('A: send_welcome() يُعيد true عند نجاح wp_mail', $result_success);
check('A: عدد محاولات الإرسال = 1 بالضبط', count($GLOBALS['re_mail_log']), 1);

$sent = $GLOBALS['re_mail_log'][0] ?? null;
check('B: المستلم = بريد المستخدم الصحيح', $sent['to'] ?? null, 'ahmed@example.test');
check_contains('B: الموضوع يحوي اسم العرض أو اسم الموقع (رسالة موجَّهة للمستخدم الصحيح)', $sent['subject'] ?? '', 'منصبات');
check_contains('D: الجسم يحوي رابط لوحة التحكم', $sent['body'] ?? '', 'https://example.test/dashboard/');
check_contains('D: الجسم يحوي رابط تسجيل الدخول', $sent['body'] ?? '', 'wp-login.php');
check_true('Headers: يحدد Content-Type: text/html; charset=UTF-8', is_array($sent['headers'] ?? null) && in_array('Content-Type: text/html; charset=UTF-8', $sent['headers'], true));

// --- C: لا كلمة مرور إطلاقاً في أي مكان بالمخرجات ---
$fake_password = 'S3cr3t-P@ssw0rd-should-never-appear';
check_not_contains('C: كلمة المرور غير موجودة في الموضوع', $sent['subject'] ?? '', $fake_password);
check_not_contains('C: كلمة المرور غير موجودة في الجسم', $sent['body'] ?? '', $fake_password);
check_not_contains('C: كلمة المرور غير موجودة في الترويسات', implode('|', (array) ($sent['headers'] ?? [])), $fake_password);
check_true('C: الدالة send_welcome() لا تملك أي وسيط password/pass1/pass2 في توقيعها (فحص Reflection)', (function () {
    $ref = new ReflectionMethod('PGE_Registration_Email', 'send_welcome');
    $names = array_map(function ($p) { return $p->getName(); }, $ref->getParameters());
    foreach ($names as $n) {
        if (stripos($n, 'pass') !== false) {
            return false;
        }
    }
    return true;
})());

// --- G (نصف أول): لا استدعاء error_log عند النجاح ---
clearstatcache();
$log_after_success = file_exists($re_log_file) ? (string) file_get_contents($re_log_file) : '';
check('G: لا سجل أخطاء عند نجاح الإرسال', trim($log_after_success), '');

// --- E: نجاح wp_mail لا يُغيّر أي حالة تسجيل (send_welcome لا تلمس المستخدم) ---
check_true('E: المستخدم ما زال كما هو بعد send_welcome (لم يُحذف/يُعدَّل من الدالة)', isset($GLOBALS['re_users'][501]) && $GLOBALS['re_users'][501]->ID === 501);

// --- F/G: فشل wp_mail لا يُفشِل شيئاً غير الإرسال نفسه، ويُسجَّل بأمان ---
$GLOBALS['re_mail_log'] = [];
$GLOBALS['re_mail_should_fail'] = true;
file_put_contents($re_log_file, '');

$result_fail = PGE_Registration_Email::send_welcome(501);

check('F: send_welcome() يُعيد false عند فشل wp_mail (لكن هذا لا يُستخدَم لإفشال التسجيل من المستدعي)', $result_fail, false);
check_true('F: المستخدم ما زال موجوداً بعد فشل الإرسال (لا Rollback)', isset($GLOBALS['re_users'][501]));

clearstatcache();
$log_after_failure = file_exists($re_log_file) ? (string) file_get_contents($re_log_file) : '';
check_true('G: فشل wp_mail يُسجَّل عبر error_log', strpos($log_after_failure, 'PGE Registration Email') !== false);
check_contains('G: السجل يحوي user_id', $log_after_failure, '501');
check_not_contains('G: السجل لا يحوي بريد المستخدم', $log_after_failure, 'ahmed@example.test');
check_not_contains('G: السجل لا يحوي كلمة مرور وهمية', $log_after_failure, $fake_password);
check_not_contains('G: السجل لا يحوي نص الرسالة (الموضوع)', $log_after_failure, 'تم إنشاء حسابك');

$GLOBALS['re_mail_should_fail'] = false;

// --- مستخدم غير موجود / بريد غير صالح: لا محاولة إرسال إطلاقاً ---
$GLOBALS['re_mail_log'] = [];
$result_missing = PGE_Registration_Email::send_welcome(9999);
check('مستخدم غير موجود: يُعيد false', $result_missing, false);
check('مستخدم غير موجود: لا محاولة wp_mail', count($GLOBALS['re_mail_log']), 0);

$GLOBALS['re_users'][502] = new RE_Test_WP_User(502, '', 'بلا بريد', '502000502');
$result_no_email = PGE_Registration_Email::send_welcome(502);
check('بريد فارغ: يُعيد false', $result_no_email, false);
check('بريد فارغ: لا محاولة wp_mail', count($GLOBALS['re_mail_log']), 0);

// --- user_id غير صالح ---
check('user_id = 0: يُعيد false بلا محاولة إرسال', PGE_Registration_Email::send_welcome(0), false);

// ═══════════════════════════════════════════════════════════════════════
// القسم 2 — فحص نصي/حدودي (Source/Boundary Scan) لملفات الإنتاج الحقيقية
// — لا يُشغِّل أي كود، وليس اختبار تكامل HTTP لووردبريس (راجع توثيق رأس
// الملف). يُثبت حدود السلك الفعلي فقط عبر فحص نص الملفات نفسها.
// ═══════════════════════════════════════════════════════════════════════

$class_file_path = __DIR__ . '/../includes/class-pge-registration-email.php';
$class_file_src = (string) file_get_contents($class_file_path);
// الدفتر التوثيقي (docblock) أعلى الملف يذكر Cartat/UltraMsg/D2/pge_message_log
// عمداً — فقط ليشرح أن الصنف لا يعتمد عليها (توثيق حدود، لا اعتماد فعلي).
// نُزيله قبل فحص L حتى لا يُبلَّغ عن ذكرها التوثيقي كاعتماد فعلي وهمي؛
// جسم الصنف بعده لا يحوي أي `/** */` أخرى (فقط تعليقات `//` أحادية السطر).
$class_file_src_no_docblock = preg_replace('/\/\*\*.*?\*\//s', '', $class_file_src, 1);

$register_template_path = __DIR__ . '/../../../themes/pgevents-pro/page-register.php';
$register_template_src = file_exists($register_template_path) ? (string) file_get_contents($register_template_path) : '';
check_true('ملف القالب page-register.php موجود وقابل للقراءة لفحص السلك', $register_template_src !== '');

$ai_onboarding_path = __DIR__ . '/../includes/class-pge-additional-inviter-onboarding.php';
$ai_onboarding_src = file_exists($ai_onboarding_path) ? (string) file_get_contents($ai_onboarding_path) : '';

// --- L: لا اعتماد على Cartat/UltraMsg/D2/Reminder/Thank You داخل الصنف نفسه ---
$forbidden_deps = [
    'Cartat', 'UltraMsg', 'PGE_Invitation_Send', 'pge_message_log',
    'PGE_Message_Log', 'PGE_Reminder_Message_Service', 'PGE_Thank_You',
];
foreach ($forbidden_deps as $dep) {
    check_not_contains("L: class-pge-registration-email.php لا يشير إلى $dep (خارج التوثيق)", $class_file_src_no_docblock, $dep);
}

// --- لا Hook عريض من الصنف نفسه (لا add_action على الإطلاق داخله) ---
check_not_contains('لا add_action() داخل class-pge-registration-email.php (صنف سلبي، استدعاء صريح فقط)', $class_file_src, 'add_action(');

// --- J: بريد Additional Inviter لا يستدعي هذا الصنف إطلاقاً ---
check_true('J: ملف onboarding الخاص بـAdditional Inviter موجود للفحص', $ai_onboarding_src !== '');
check_not_contains('J: class-pge-additional-inviter-onboarding.php لا يستدعي PGE_Registration_Email', $ai_onboarding_src, 'PGE_Registration_Email');

// --- H/I/A: نقطة الاستدعاء الفعلية الوحيدة داخل page-register.php، بعد شروط
// النجاح فقط. نستخدم توقيع الاستدعاء الفعلي الكامل (مع وسيط $new_user_id
// وفاصلة منقوطة) كـneedle، لا اسم الدالة المجرَّد — لأن التعليق التوضيحي
// أعلى الاستدعاء الفعلي يذكر اسم الدالة نصياً أيضاً (`send_welcome()` بلا
// وسيط) للتوثيق، وهذا لا يجب أن يُحتسَب كاستدعاء ثانٍ. ---
$call_needle = 'PGE_Registration_Email::send_welcome(';
$real_call_needle = 'PGE_Registration_Email::send_welcome($new_user_id);';
$mention_count = substr_count($register_template_src, $call_needle);
$real_call_count = substr_count($register_template_src, $real_call_needle);
check_true('A/H/I: التعليق التوضيحي يذكر اسم الدالة نصياً أيضاً (سياق متوقَّع، وليس استدعاءً)', $mention_count >= $real_call_count);
check('A/H/I: استدعاء send_welcome($new_user_id) الفعلي مرة واحدة بالضبط في page-register.php', $real_call_count, 1);

$call_pos = strpos($register_template_src, $real_call_needle);
$auth_cookie_pos = strpos($register_template_src, 'wp_set_auth_cookie(');
// ملف القالب يحوي "wp_safe_redirect($redirect_to);" مرتين: مرة في أعلى
// الملف (توجيه فوري إن كان المستخدم مسجَّلاً دخوله بالفعل — غير ذي صلة)،
// ومرة في نهاية مسار نجاح التسجيل (المعنية هنا). نبحث ابتداءً من موضع
// الاستدعاء نفسه لضمان إيجاد توجيه مسار النجاح تحديداً، لا التوجيه المبكر
// غير ذي الصلة في أعلى الملف.
$redirect_pos = $call_pos !== false ? strpos($register_template_src, 'wp_safe_redirect($redirect_to);', $call_pos) : false;

check_true('ترتيب السلك: الاستدعاء يقع بعد wp_set_auth_cookie() (بعد تأسيس الجلسة)', $call_pos !== false && $auth_cookie_pos !== false && $call_pos > $auth_cookie_pos);
check_true('ترتيب السلك: الاستدعاء يقع قبل wp_safe_redirect (لا يمنع التوجيه)', $call_pos !== false && $redirect_pos !== false && $call_pos < $redirect_pos);

// إثبات أن القيمة المُعادة من send_welcome() غير مستخدَمة للتحكم بأي شرط
// بين نقطة الاستدعاء والتوجيه (أي: فشل البريد لا يمكن أن يمنع التوجيه من
// الناحية البنيوية، لأنه لا يوجد أي "if" على نتيجتها في هذا النطاق).
$between = substr($register_template_src, $call_pos, max(0, $redirect_pos - $call_pos));
check_not_contains('فشل send_welcome() لا يُستخدَم شرطياً قبل التوجيه (لا if() على قيمتها)', preg_replace('/\s+/', ' ', $between), 'if (');

// الاستدعاء محاط بـclass_exists() دفاعياً
check_contains('الاستدعاء محمي بـclass_exists(\'PGE_Registration_Email\')', $register_template_src, "class_exists('PGE_Registration_Email')");

// --- H: التحقق من أن الاستدعاء يقع داخل فرع النجاح فقط، لا فروع الأخطاء ---
// كل فروع الأخطاء (تكرار، تحقق فاشل) تكتب في $register_error ثم لا تصل
// إطلاقاً لسطر wp_create_user (تدفق تنفيذي تسلسلي واحد لا تفرّع فيه بعد
// $register_error === '') — نتحقق أن نقطة الاستدعاء تقع بعد wp_create_user
// الناجح (أي بعد آخر ظهور لـ"is_wp_error($new_user_id)") لإثبات أنها لا
// يمكن أن تُنفَّذ إلا بعد إنشاء مستخدم حقيقي بنجاح.
$create_user_check_pos = strpos($register_template_src, 'is_wp_error($new_user_id)');
check_true('H/I: الاستدعاء يقع بعد التحقق من نجاح wp_create_user() (لا يمكن الوصول إليه من مسار خطأ)', $call_pos !== false && $create_user_check_pos !== false && $call_pos > $create_user_check_pos);

// --- K: لا استدعاء آخر للدالة من أي مكان آخر في المشروع (wp-admin/استيراد/نظام) ---
$includes_dir = __DIR__ . '/../includes';
$other_call_sites = 0;
$other_call_files = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($includes_dir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    if ($file->getPathname() === realpath($class_file_path)) {
        continue; // تعريف الصنف نفسه، وليس استدعاءً
    }
    $src = (string) file_get_contents($file->getPathname());
    $c = substr_count($src, $call_needle);
    if ($c > 0) {
        $other_call_sites += $c;
        $other_call_files[] = $file->getFilename();
    }
}
check('K: لا استدعاءات لـsend_welcome() من أي ملف آخر داخل includes/ (لا wp-admin/استيراد/نظام)', $other_call_sites, 0);
if ($other_call_sites > 0) {
    echo '      ملفات مخالفة: ' . implode(', ', $other_call_files) . "\n";
}

// ═══════════════════════════════════════════════════════════════════════
// القسم 3 — إثبات عدم تسرب $pass1/$pass2 (فحص نصي/حدودي على page-
// register.php الحقيقي — ليس اختباراً تشغيلياً). كلمة المرور الحقيقية لا
// تصل أبداً إلى PGE_Registration_Email::send_welcome() في مسار الإنتاج
// (لا وسيط password في توقيعها إطلاقاً — مُثبَت في القسم 1 عبر Reflection)،
// لذا لا يمكن ولا يجب إثبات عدم تسربها بتمرير قيمة وهمية للدالة نفسها؛
// الإثبات الصحيح الوحيد هو فحص السلك الفعلي في page-register.php نفسه
// لإثبات أن $pass1/$pass2 لا يُستخدَمان في أي مسار نصي يصل لاستدعاء
// send_welcome()، ولا لـwp_mail()/error_log() على الإطلاق.
// ═══════════════════════════════════════════════════════════════════════

// --- $pass1 يُستخدَم في wp_create_user() فقط كما هو متوقع ---
check_contains('$pass1 يُستخدَم كوسيط ثانٍ في wp_create_user() كما هو متوقع', $register_template_src, 'wp_create_user($user_login, $pass1, $email_value)');

// --- عدد ظهورات $pass1/$pass2 في كامل الملف يطابق الاستخدامات المعروفة
// حصراً (قراءة $_POST، تحقق الطول/التطابق، ووسيط wp_create_user لـ$pass1
// فقط) — أي ظهور إضافي غير متوقَّع قد يعني تسرباً جديداً يستدعي فحصاً
// يدوياً فورياً لو ظهر مستقبلاً (Regression Guard صريح).
$pass1_count = substr_count($register_template_src, '$pass1');
$pass2_count = substr_count($register_template_src, '$pass2');
check('عدد ظهورات $pass1 في page-register.php = 4 بالضبط (قراءة POST، تحقق الطول، تحقق التطابق، wp_create_user) — لا ظهور إضافي', $pass1_count, 4);
check('عدد ظهورات $pass2 في page-register.php = 2 بالضبط (قراءة POST، تحقق التطابق) — لا ظهور إضافي', $pass2_count, 2);

// --- كل ظهورات $pass1/$pass2 تقع نصياً قبل نقطة استدعاء send_welcome()
// (أي: كلاهما "متقاعد" تماماً من التنفيذ التسلسلي قبل أن يبدأ مسار البريد
// إطلاقاً — لا مسار تنفيذي ممكن لتمريرهما بعد تلك النقطة) ---
$pass1_last_pos = strrpos($register_template_src, '$pass1');
$pass2_last_pos = strrpos($register_template_src, '$pass2');
check_true('$pass1: آخر ظهور له يقع قبل استدعاء send_welcome() — لا مسار تنفيذي ممكن لتمريره', $pass1_last_pos !== false && $call_pos !== false && $pass1_last_pos < $call_pos);
check_true('$pass2: آخر ظهور له يقع قبل استدعاء send_welcome() — لا مسار تنفيذي ممكن لتمريره', $pass2_last_pos !== false && $call_pos !== false && $pass2_last_pos < $call_pos);

// --- $pass1/$pass2 ليسا ضمن نص استدعاء send_welcome() الفعلي نفسه ---
check_not_contains('$pass1 ليس ضمن وسائط استدعاء send_welcome() الفعلي', $real_call_needle, '$pass1');
check_not_contains('$pass2 ليس ضمن وسائط استدعاء send_welcome() الفعلي', $real_call_needle, '$pass2');

// --- page-register.php لا يستدعي wp_mail()/error_log() إطلاقاً بنفسه، فلا
// مسار مباشر من القالب لتسريب $pass1/$pass2 عبرهما (لأنه لا استدعاء أصلاً
// لأيّهما من هذا الملف — كل الإرسال/التسجيل يمر حصراً عبر send_welcome()) --
check('page-register.php لا يستدعي wp_mail() إطلاقاً', substr_count($register_template_src, 'wp_mail('), 0);
check('page-register.php لا يستدعي error_log() إطلاقاً', substr_count($register_template_src, 'error_log('), 0);

// --- داخل الصنف نفسه: لا إشارة لأي متغير/اسم متعلق بكلمة المرور في بناء
// الموضوع/الجسم أو في استدعاء wp_mail()/error_log() — تعزيز إضافي فوق
// فحص Reflection في القسم 1 (الذي أثبت أصلاً عدم وجود وسيط password) ---
$password_indicators = ['pass1', 'pass2', 'user_pass', '$password', 'PASSWORD'];
foreach ($password_indicators as $ind) {
    check_not_contains("لا إشارة لـ\"$ind\" في جسم class-pge-registration-email.php (خارج التوثيق)", $class_file_src_no_docblock, $ind);
}

// ═══════════════════════════════════════════════════════════════════════
// الخلاصة
// ═══════════════════════════════════════════════════════════════════════

@unlink($re_log_file);

echo "\n============================================\n";
echo "الإجمالي: $total | ناجح: $passed | فاشل: " . count($failures) . "\n";
if ($failures) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "كل الحالات ناجحة.\n";
exit(0);
