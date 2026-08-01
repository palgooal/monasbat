<?php
/**
 * ============================================================================
 * Supervisor Portal — Entry Check-in Supervisors، Phase 3.5
 * ============================================================================
 * "Supervisor Portal Foundation" RFC. قالب هذه الصفحة **لا يقرر أي شيء أمني
 * بنفسه** — كل قرار الوصول يمر حصراً عبر PGE_Supervisor_Portal_Middleware::
 * authorize() (Requirement 2)، وكل بيانات العرض تُجلَب حصراً عبر
 * PGE_Supervisor_Portal_Bootstrap::load() (Requirement 3، قراءة فقط). هذا
 * الملف نفسه لا يقرأ $wpdb، لا يقرأ post meta مباشرة، لا يتحقق من أي كوكي
 * بنفسه — طبقة عرض بحتة فوق الطبقتين أعلاه.
 *
 * قالب مستقل بالكامل (DOCTYPE خاص به، بلا get_header()/get_footer() من
 * الثيم) — بنفس فلسفة access-gate.php للضيوف: المشرف لا يملك حساب WordPress
 * إطلاقاً (Requirement 1)، فلا معنى لتحميل قوالب رأس/تذييل الثيم المبنية على
 * افتراض is_user_logged_in().
 *
 * Requirement 7 — "Create only a minimal shell": اسم المشرف، اسم المناسبة،
 * حالة المصادقة، زر خروج، ونص نائب واحد فقط. لا قائمة مدعوين، لا QR، لا
 * إحصاءات (Requirement 8 يمنعها صراحةً في هذه المرحلة).
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('PGE_Supervisor_Portal_Middleware') || !class_exists('PGE_Supervisor_Portal_Bootstrap')) {
    // بيئة غير مكتملة — رفض آمن، لا كشف تفاصيل داخلية (Fail Closed).
    status_header(401);
    nocache_headers();
    wp_die('تعذّر تحميل بوابة المشرف حالياً.', 'خطأ', ['response' => 401]);
}

$authorization = PGE_Supervisor_Portal_Middleware::authorize();

/**
 * Requirement 5 — كل حالة رفض تُترجَم صراحةً إلى استجابة HTTP ورسالة عربية
 * واضحة خاصة بها (لا رسالة عامة واحدة تُغطي كل الأسباب)، ثم exit فوري — لا
 * أي بيانات مناسبة/مشرف تُحمَّل أو تُعرَض بعد رفض التفويض إطلاقاً.
 */
if (($authorization['result'] ?? '') !== 'authorized') {
    $http_status = (int) ($authorization['http_status'] ?? 401);
    $reason = (string) ($authorization['reason'] ?? '');

    $messages_401 = [
        'no_session_cookie'       => 'لم يتم تسجيل الدخول بعد. الرجاء استخدام رابط الدعوة الذي وصلك لتسجيل الدخول كمشرف.',
        'invalid_token'           => 'جلسة الدخول غير صالحة. الرجاء تسجيل الدخول مجدداً.',
        'session_not_found'       => 'جلسة الدخول غير موجودة أو منتهية. الرجاء تسجيل الدخول مجدداً.',
        'session_revoked'         => 'تم تسجيل خروجك من هذه الجلسة سابقاً. الرجاء تسجيل الدخول مجدداً.',
        'session_expired'         => 'انتهت صلاحية جلسة الدخول. الرجاء تسجيل الدخول مجدداً.',
        'session_layer_unavailable' => 'تعذّر التحقق من الجلسة حالياً. حاول لاحقاً.',
    ];

    $messages_403 = [
        'assignment_not_active'    => 'لم يعد وصولك كمشرف على هذه المناسبة فعّالاً. تواصل مع المضيف إذا كنت تظن أن هذا خطأ.',
        'not_authorized_for_event' => 'لا تملك صلاحية الوصول إلى هذه المناسبة.',
    ];

    $messages_404 = [
        'assignment_not_found' => 'لم يتم العثور على إسناد الإشراف المرتبط بجلستك.',
        'event_mismatch'       => 'تعذّر العثور على المناسبة المرتبطة بجلستك.',
    ];

    switch ($http_status) {
        case 403:
            $title = 'غير مصرَّح';
            $message = $messages_403[$reason] ?? 'لا تملك صلاحية الوصول إلى هذه الصفحة.';
            break;
        case 404:
            $title = 'غير موجود';
            $message = $messages_404[$reason] ?? 'العنصر المطلوب غير موجود.';
            break;
        default:
            $http_status = 401;
            $title = 'الدخول مطلوب';
            $message = $messages_401[$reason] ?? 'الرجاء تسجيل الدخول للوصول إلى هذه الصفحة.';
            break;
    }

    status_header($http_status);
    nocache_headers();
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($title); ?> — بوابة المشرف</title>
    <?php wp_head(); ?>
</head>
<body>
    <div class="relative flex min-h-screen items-center justify-center bg-background px-4 font-arabic" dir="rtl">
        <div class="w-full max-w-sm rounded-3xl border border-border bg-white p-8 text-center shadow-xl">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-destructive/10 text-2xl text-destructive-text">
                ⚠️
            </div>
            <h1 class="text-lg font-extrabold text-foreground"><?php echo esc_html($title); ?></h1>
            <p class="mt-3 text-sm leading-relaxed text-foreground/75"><?php echo esc_html($message); ?></p>
        </div>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
    <?php
    exit;
}

// ── التفويض نجح — تحميل بيانات العرض فقط (Requirement 3) ───────────────────
$portal_data = PGE_Supervisor_Portal_Bootstrap::load(
    (int) $authorization['assignment_id'],
    (int) $authorization['event_id']
);

if (!($portal_data['ok'] ?? false)) {
    // نجح التفويض (جلسة صالحة فعلاً) لكن تعذّر تحميل بيانات العرض — حالة
    // بيانات نادرة (مثلاً: المناسبة نفسها حُذفت من ووردبريس رغم بقاء الإسناد) —
    // 404 صريحة، لا خطأ عام غامض.
    status_header(404);
    nocache_headers();
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>غير موجود — بوابة المشرف</title>
    <?php wp_head(); ?>
</head>
<body>
    <div class="relative flex min-h-screen items-center justify-center bg-background px-4 font-arabic" dir="rtl">
        <div class="w-full max-w-sm rounded-3xl border border-border bg-white p-8 text-center shadow-xl">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-destructive/10 text-2xl text-destructive-text">⚠️</div>
            <h1 class="text-lg font-extrabold text-foreground">تعذّر عرض بيانات المناسبة</h1>
            <p class="mt-3 text-sm leading-relaxed text-foreground/75">لم يتم العثور على بيانات هذه المناسبة. تواصل مع المضيف.</p>
        </div>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
    <?php
    exit;
}

// ── Requirement 4: رابط تسجيل الخروج (nonce إلزامي، بلا أي معرِّف داخلي فيه) ──
$logout_url = wp_nonce_url(home_url('/supervisor/logout/'), 'pge_supervisor_logout');

$supervisor_name = (string) ($portal_data['supervisor_name'] ?? '');
$supervisor_phone_masked = (string) ($portal_data['supervisor_phone_masked'] ?? '');
$event_name = (string) ($portal_data['event_name'] ?? '');
$event_date_display = (string) ($portal_data['event_date_display'] ?? '');
$host_name = (string) ($portal_data['host_name'] ?? '');

status_header(200);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($event_name !== '' ? $event_name . ' — بوابة المشرف' : 'بوابة المشرف'); ?></title>
    <?php wp_head(); ?>
</head>
<body>
<div class="relative min-h-screen bg-background font-arabic" dir="rtl">
    <main class="mx-auto flex min-h-screen max-w-lg flex-col items-center justify-center px-4 py-12">
        <section class="w-full rounded-[28px] border border-border bg-white p-7 shadow-[0_20px_60px_-15px_rgba(45,25,20,0.10)]">

            <div class="flex items-center justify-between">
                <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-800 ring-1 ring-emerald-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    مسجَّل الدخول كمشرف
                </span>

                <a href="<?php echo esc_url($logout_url); ?>"
                   class="rounded-xl px-3 py-1.5 text-xs font-bold text-destructive-text transition-colors hover:bg-destructive/10">
                    تسجيل الخروج
                </a>
            </div>

            <div class="mt-6 text-center">
                <span class="mx-auto mb-4 inline-flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="h-8 w-8">
                        <path d="M20 21a8 8 0 0 0-16 0"></path>
                        <circle cx="12" cy="8" r="5"></circle>
                    </svg>
                </span>

                <?php if ($supervisor_name !== ''): ?>
                    <h1 class="text-xl font-extrabold text-foreground"><?php echo esc_html($supervisor_name); ?></h1>
                <?php else: ?>
                    <h1 class="text-xl font-extrabold text-foreground">مشرف الدخول</h1>
                <?php endif; ?>

                <?php if ($supervisor_phone_masked !== ''): ?>
                    <p class="mt-1 text-sm text-foreground/60" dir="ltr"><?php echo esc_html($supervisor_phone_masked); ?></p>
                <?php endif; ?>
            </div>

            <div class="mt-6 rounded-2xl bg-secondary/40 p-5 text-center">
                <p class="text-xs font-bold uppercase tracking-wide text-foreground/50">المناسبة</p>
                <h2 class="mt-1 text-lg font-extrabold text-foreground"><?php echo esc_html($event_name !== '' ? $event_name : '—'); ?></h2>

                <?php if ($event_date_display !== ''): ?>
                    <p class="mt-1 text-sm text-foreground/70"><?php echo esc_html($event_date_display); ?></p>
                <?php endif; ?>

                <?php if ($host_name !== ''): ?>
                    <p class="mt-2 text-xs text-foreground/60">المضيف: <?php echo esc_html($host_name); ?></p>
                <?php endif; ?>
            </div>

            <div class="mt-6 flex items-start gap-3 rounded-2xl bg-primary/5 p-4 ring-1 ring-primary/10">
                <span class="mt-0.5 text-primary">ℹ️</span>
                <p class="text-sm font-medium text-foreground/75">سيتوفر تسجيل حضور الضيوف في المرحلة القادمة.</p>
            </div>

        </section>
    </main>
</div>
<?php wp_footer(); ?>
</body>
</html>
<?php
exit;
