<?php
defined('ABSPATH') || exit;

/**
 * E2E-01 — Registration Welcome Email.
 *
 * نطاق ضيق ومقصود: بريد ترحيبي معاملاتي (transactional) واحد يُرسَل مرة
 * واحدة عند نجاح التسجيل الذاتي العام للعميل العادي (page-register.php في
 * القالب النشط)، لإعلامه بأن حسابه أُنشئ وتوجيهه لتسجيل الدخول/لوحة
 * التحكم واختيار باقة. هذا ليس تحقق بريد إلكتروني (email verification) —
 * لا يُستخدم لمنع الدخول أو تفعيل الحساب، فالحساب مُفعَّل والجلسة قائمة
 * بالفعل بغض النظر عن نجاح إرسال هذا البريد من عدمه.
 *
 * حدود صارمة ومتعمَّدة:
 * - لا علاقة إطلاقاً بخط أنابيب D2 لإرسال الدعوات
 *   (PGE_Invitation_Send_Application/Ledger/Queue/Worker/Orchestrator)،
 *   ولا بخط Reminder، ولا بخط Thank You — كل تلك الأنابيب تُرسِل عبر
 *   Cartat/UltraMsg (واتساب) لضيوف/دعوات مناسبة محددة، بينما هذا الملف
 *   بريد حساب عميل عادي عبر wp_mail() فقط، بلا أي اتصال بـ Cartat/UltraMsg
 *   ولا بـpge_message_log (ذلك الجدول مخصص حصراً لمحاولات المراسلة
 *   الخاصة بالمناسبة/الضيف، وليس لبريد حساب المستخدم نفسه).
 * - لا Hook عريض على user_register — الاستدعاء صريح ومقصود فقط من مسار
 *   نجاح التسجيل الذاتي العام (page-register.php)، حتى لا يُرسَل هذا
 *   البريد سهواً لحسابات Additional Inviter (لها بريدها الترحيبي المستقل
 *   عبر class-pge-additional-inviter-onboarding.php)، ولا لمستخدمين يُنشَأون
 *   من wp-admin، ولا لمستخدمين مستوردين، ولا لحسابات نظام/خدمة داخلية.
 * - لا كلمة مرور، لا Hash، لا Cookie جلسة، لا Nonce حسّاس يُرسَل في البريد
 *   إطلاقاً — فقط اسم العرض والبريد الإلكتروني ورابط تسجيل الدخول/اللوحة.
 * - فشل الإرسال (wp_mail() ترجع false) لا يُفشِل التسجيل ولا الجلسة —
 *   التسجيل نجح بالفعل قبل استدعاء هذه الدالة؛ فقط يُسجَّل الفشل بأمان عبر
 *   error_log() (user_id فقط، بلا نص الرسالة أو البريد أو كلمة المرور).
 */
final class PGE_Registration_Email
{
    /**
     * يُستدعى مرة واحدة فقط، فوراً بعد نجاح إنشاء الحساب وتأسيس الجلسة، من
     * مسار التسجيل الذاتي العام حصراً. لا يرمي استثناءً أبداً ولا يُغيّر أي
     * حالة تسجيل — يُعيد bool فقط للتشخيص الاختياري من المستدعي (المستدعي
     * الحالي لا يتحقق من القيمة المُعادة عمداً، لأن فشل البريد لا يجب أن
     * يغيّر أي سلوك في مسار التسجيل).
     */
    public static function send_welcome($user_id)
    {
        $user_id = (int) $user_id;

        if ($user_id <= 0) {
            self::log_failure($user_id, 'invalid_user_id');
            return false;
        }

        $user = get_userdata($user_id);

        if (!$user || empty($user->user_email) || !is_email($user->user_email)) {
            self::log_failure($user_id, 'missing_or_invalid_user_email');
            return false;
        }

        $display_name = ($user->display_name !== '' && $user->display_name !== null)
            ? $user->display_name
            : $user->user_login;

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $dashboard_url = home_url('/dashboard/');
        $login_url = wp_login_url($dashboard_url);

        $subject = self::build_subject($site_name);
        $body = self::build_body($display_name, $user->user_email, $site_name, $dashboard_url, $login_url);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = (bool) wp_mail($user->user_email, $subject, $body, $headers);

        if (!$sent) {
            self::log_failure($user_id, 'wp_mail_returned_false');
        }

        return $sent;
    }

    private static function build_subject($site_name)
    {
        // esc_html غير مناسب لعنوان الرسالة (Subject header وليس HTML)؛
        // wp_specialchars_decode أعلاه كافٍ لاسم الموقع هنا.
        return sprintf('مرحباً بك في %s — تم إنشاء حسابك بنجاح', $site_name);
    }

    private static function build_body($display_name, $email, $site_name, $dashboard_url, $login_url)
    {
        $display_name_html = esc_html($display_name);
        $email_html = esc_html($email);
        $site_name_html = esc_html($site_name);
        $dashboard_url_attr = esc_url($dashboard_url);
        $login_url_attr = esc_url($login_url);

        ob_start();
        ?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
</head>
<body style="margin:0;padding:0;background-color:#f7f3ee;font-family:Tahoma,Arial,sans-serif;" dir="rtl">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f7f3ee;padding:32px 0;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background-color:#ffffff;border-radius:16px;overflow:hidden;">
<tr>
<td style="padding:32px 28px 8px 28px;text-align:right;">
<h2 style="margin:0 0 12px 0;font-size:20px;color:#2d1914;">مرحباً <?php echo $display_name_html; ?>،</h2>
<p style="margin:0 0 16px 0;font-size:15px;line-height:1.7;color:#4a3a34;">
تم إنشاء حسابك في <strong><?php echo $site_name_html; ?></strong> بنجاح، وأصبح بإمكانك الدخول
إلى لوحة تحكمك مباشرة.
</p>
<p style="margin:0 0 20px 0;font-size:14px;line-height:1.7;color:#6b5a52;">
البريد الإلكتروني المسجَّل: <span dir="ltr" style="unicode-bidi:embed;"><?php echo $email_html; ?></span>
</p>
<p style="margin:0 0 24px 0;text-align:center;">
<a href="<?php echo $dashboard_url_attr; ?>" style="display:inline-block;background-color:#8a4a2f;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:14px 32px;border-radius:12px;">
الذهاب إلى لوحة التحكم
</a>
</p>
<p style="margin:0 0 20px 0;font-size:14px;line-height:1.7;color:#4a3a34;">
الخطوة التالية: اختيار وتفعيل الباقة المناسبة لك للبدء في إنشاء مناسباتك ودعواتك.
</p>
<p style="margin:0 0 4px 0;font-size:13px;color:#8a7a72;">
إذا لم يعمل الزر أعلاه، يمكنك تسجيل الدخول من الرابط التالي:
<br />
<a href="<?php echo $login_url_attr; ?>" style="color:#8a4a2f;word-break:break-all;"><?php echo $login_url_attr; ?></a>
</p>
</td>
</tr>
<tr>
<td style="padding:16px 28px 28px 28px;border-top:1px solid #f0e8e0;text-align:right;">
<p style="margin:12px 0 0 0;font-size:12px;line-height:1.6;color:#9a8a82;">
لن نرسل كلمة المرور الخاصة بك عبر البريد الإلكتروني أبداً. إذا احتجت لإعادة تعيينها، استخدم رابط
"نسيت كلمة المرور" في صفحة تسجيل الدخول.
</p>
<p style="margin:12px 0 0 0;font-size:12px;color:#b0a49c;">
<?php echo $site_name_html; ?>
</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    private static function log_failure($user_id, $reason)
    {
        if (function_exists('error_log')) {
            error_log(sprintf('[PGE Registration Email] send_welcome failed user_id=%d reason=%s', (int) $user_id, (string) $reason));
        }
    }
}
