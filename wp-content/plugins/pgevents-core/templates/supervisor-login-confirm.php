<?php
/**
 * ============================================================================
 * صفحة تأكيد الدخول — Supervisor Login Link Preview Safety Fix
 * ============================================================================
 * تُعرَض حصراً على GET /supervisor/login/{token}/ عندما يكون التوكن صالحاً
 * فعلياً (PGE_Supervisor_Assignment_Service::peek_login_token() === 'valid').
 * **لا استهلاك، لا جلسة، لا كوكي، لا تدقيق يحدث في هذه الصفحة أو قبلها على
 * GET** — كل ما حدث قبل هذا الملف هو قراءة SELECT بحتة (peek_login_token()).
 *
 * السبب: روابط الدخول تُفتَح غالباً أولاً بواسطة معاينات الروابط الآلية
 * (WhatsApp/تطبيقات المراسلة تجلب OpenGraph metadata)، زواحف/فاحصات أمنية،
 * أو Prefetch من المتصفح — جميعها تُرسِل GET **قبل** أن يضغط المشرف الحقيقي
 * على الرابط. لو كان GET يستهلك التوكن مباشرة (كما كان الحال سابقاً)، فقد
 * تُستهلَك من معاينة آلية قبل وصول المشرف الفعلي إليها — هذه الصفحة توقف GET
 * عند "تأكيد بلا أثر جانبي"؛ الاستهلاك الفعلي يحدث فقط عند ضغط المشرف على
 * الزر (POST حقيقي، لا يمكن لمعاينة رابط تلقائية تنفيذه).
 *
 * النموذج يُرسِل POST لنفس مسار الرابط بالضبط (نفس التوكن في الـpath نفسه —
 * لا حقل توكن مخفي إضافي)، مع nonce وحيد الغرض (`pge_supervisor_login_confirm`)
 * يتحقق منه معالج POST في routing.php قبل أي استدعاء لـ
 * PGE_Supervisor_Login_Authenticator::authenticate().
 *
 * متغيّرات مطلوبة من المستدعي (routing.php) قبل require هذا الملف:
 *   $pge_login_confirm_url    رابط POST الكامل (نفس رابط GET الحالي)
 *   $pge_login_confirm_nonce  قيمة nonce جاهزة (wp_create_nonce())
 */
if (!defined('ABSPATH')) exit;

$confirm_url = isset($pge_login_confirm_url) ? (string) $pge_login_confirm_url : '';
$confirm_nonce = isset($pge_login_confirm_nonce) ? (string) $pge_login_confirm_nonce : '';

status_header(200);
nocache_headers();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تأكيد تسجيل الدخول — بوابة المشرف</title>
    <?php wp_head(); ?>
</head>
<body>
<div class="relative flex min-h-screen items-center justify-center bg-background px-4 font-arabic" dir="rtl">
    <div class="w-full max-w-sm rounded-3xl border border-border bg-white p-8 text-center shadow-xl">
        <span class="mx-auto mb-4 inline-flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="h-7 w-7">
                <path d="M20 21a8 8 0 0 0-16 0"></path>
                <circle cx="12" cy="8" r="5"></circle>
            </svg>
        </span>
        <h1 class="text-lg font-extrabold text-foreground">تأكيد تسجيل الدخول</h1>
        <p class="mt-3 text-sm leading-relaxed text-foreground/75">
            اضغط الزر أدناه لإكمال تسجيل دخولك إلى لوحة المشرف. لن يكتمل الدخول
            إلا بعد ضغط الزر صراحةً.
        </p>

        <form method="post" action="<?php echo esc_url($confirm_url); ?>" class="mt-6">
            <input type="hidden" name="pge_login_confirm_nonce" value="<?php echo esc_attr($confirm_nonce); ?>">
            <button id="pgeSupLoginConfirmBtn" type="submit" class="h-12 w-full rounded-xl bg-primary text-sm font-bold text-white">
                الدخول إلى لوحة المشرف
            </button>
        </form>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
<?php
exit;
