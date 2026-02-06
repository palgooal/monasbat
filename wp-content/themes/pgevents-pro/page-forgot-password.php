<?php
defined('ABSPATH') || exit;

$default_redirect = home_url('/login/');
$requested_redirect = isset($_REQUEST['redirect_to']) ? wp_unslash($_REQUEST['redirect_to']) : '';
$redirect_to = $requested_redirect ? wp_validate_redirect($requested_redirect, $default_redirect) : $default_redirect;

if (is_user_logged_in()) {
    wp_safe_redirect(home_url('/dashboard/'));
    exit;
}

$lost_error = '';
$lost_notice = '';
$user_login_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nonce = isset($_POST['pge_lost_nonce']) ? sanitize_text_field(wp_unslash($_POST['pge_lost_nonce'])) : '';
    $user_login_value = isset($_POST['user_login']) ? sanitize_text_field(wp_unslash($_POST['user_login'])) : '';

    if (isset($_POST['redirect_to'])) {
        $posted_redirect = wp_unslash($_POST['redirect_to']);
        $redirect_to = $posted_redirect ? wp_validate_redirect($posted_redirect, $default_redirect) : $default_redirect;
    }

    if (!$nonce || !wp_verify_nonce($nonce, 'pge_lost_password_action')) {
        $lost_error = 'انتهت الجلسة، أعد المحاولة.';
    } elseif ($user_login_value === '') {
        $lost_error = 'يرجى إدخال البريد الإلكتروني أو اسم المستخدم.';
    } else {
        $result = retrieve_password($user_login_value);

        if (is_wp_error($result)) {
            $lost_error = 'تعذر إرسال رابط إعادة التعيين. تأكد من البيانات ثم حاول مرة أخرى.';
        } else {
            $lost_notice = 'إذا كانت البيانات صحيحة، تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني.';
            $user_login_value = '';
        }
    }
}

get_header();
?>

<div class="relative min-h-screen overflow-hidden" dir="rtl">
    <div class="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-b from-indigo-50/70 via-white to-slate-50"></div>
    <div class="pointer-events-none absolute -top-20 start-[-7rem] h-80 w-80 rounded-full bg-indigo-500/15 blur-3xl"></div>
    <div class="pointer-events-none absolute top-28 end-[-8rem] h-96 w-96 rounded-full bg-slate-900/10 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-28 start-1/3 h-72 w-72 rounded-full bg-emerald-500/10 blur-3xl"></div>

    <main class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:py-16">
        <section>
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-extrabold tracking-tight">نسيت كلمة المرور</h1>
                        <p class="mt-2 text-sm text-slate-600">
                            أدخل بريدك الإلكتروني أو اسم المستخدم، وسنرسل لك رابط إعادة التعيين.
                        </p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-200">
                        استعادة الحساب
                    </span>
                </div>

                <?php if ($lost_notice): ?>
                    <div class="mt-4 rounded-2xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-800 ring-1 ring-emerald-200">
                        <?php echo esc_html($lost_notice); ?>
                    </div>
                <?php endif; ?>

                <?php if ($lost_error): ?>
                    <div class="mt-4 rounded-2xl bg-rose-50 p-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                        <?php echo esc_html($lost_error); ?>
                    </div>
                <?php endif; ?>

                <form class="mt-6 space-y-4" action="<?php echo esc_url(home_url('/forgot-password/')); ?>" method="post" novalidate>
                    <div>
                        <label for="user_login" class="text-xs font-semibold text-slate-600">اسم المستخدم أو البريد الإلكتروني</label>
                        <input
                            id="user_login"
                            name="user_login"
                            type="text"
                            autocomplete="username"
                            value="<?php echo esc_attr($user_login_value); ?>"
                            class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                            placeholder="example@email.com"
                            required />
                    </div>

                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
                    <?php wp_nonce_field('pge_lost_password_action', 'pge_lost_nonce'); ?>

                    <button
                        type="submit"
                        class="group inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-b from-slate-900 to-slate-800 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:from-slate-800 hover:to-slate-700">
                        إرسال رابط إعادة التعيين
                        <span class="text-white/80 transition group-hover:-translate-x-0.5">←</span>
                    </button>
                </form>

                <div class="mt-6 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <p class="text-sm text-slate-700">
                        تذكرت كلمة المرور؟
                        <a href="<?php echo esc_url(wp_login_url($redirect_to)); ?>" class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4">
                            العودة إلى تسجيل الدخول
                        </a>
                    </p>
                    <p class="mt-2 text-sm text-slate-700">
                        لا تملك حساباً؟
                        <a href="<?php echo esc_url(add_query_arg('redirect_to', $redirect_to, wp_registration_url())); ?>" class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4">
                            إنشاء حساب جديد
                        </a>
                    </p>
                </div>
            </div>
        </section>
    </main>
</div>

<?php get_footer(); ?>
