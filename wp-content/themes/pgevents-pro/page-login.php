<?php
defined('ABSPATH') || exit;

$default_redirect = home_url('/dashboard/');
$requested_redirect = isset($_REQUEST['redirect_to']) ? wp_unslash($_REQUEST['redirect_to']) : '';
$redirect_to = $requested_redirect ? wp_validate_redirect($requested_redirect, $default_redirect) : $default_redirect;

if (is_user_logged_in()) {
    wp_safe_redirect($redirect_to);
    exit;
}

$login_error = '';
$login_notice = '';
$login_value = '';

if (isset($_GET['loggedout']) && sanitize_text_field(wp_unslash($_GET['loggedout'])) === 'true') {
    $login_notice = 'تم تسجيل الخروج بنجاح.';
}

if (isset($_GET['checkemail']) && sanitize_text_field(wp_unslash($_GET['checkemail'])) === 'registered') {
    $login_notice = 'تم إنشاء الحساب، راجع بريدك الإلكتروني للتفعيل إن لزم.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nonce = isset($_POST['pge_login_nonce']) ? sanitize_text_field(wp_unslash($_POST['pge_login_nonce'])) : '';
    $login_value = isset($_POST['log']) ? sanitize_text_field(wp_unslash($_POST['log'])) : '';
    $password = isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '';
    $remember = !empty($_POST['rememberme']);

    if (isset($_POST['redirect_to'])) {
        $posted_redirect = wp_unslash($_POST['redirect_to']);
        $redirect_to = $posted_redirect ? wp_validate_redirect($posted_redirect, $default_redirect) : $default_redirect;
    }

    if (!$nonce || !wp_verify_nonce($nonce, 'pge_login_action')) {
        $login_error = 'انتهت الجلسة، أعد المحاولة.';
    } elseif ($login_value === '' || $password === '') {
        $login_error = 'يرجى إدخال اسم المستخدم أو البريد وكلمة المرور.';
    } else {
        $user = wp_signon([
            'user_login'    => $login_value,
            'user_password' => $password,
            'remember'      => $remember,
        ], is_ssl());

        if (is_wp_error($user)) {
            $error_code = $user->get_error_code();

            if (in_array($error_code, ['invalid_username', 'incorrect_password', 'invalid_email'], true)) {
                $login_error = 'بيانات الدخول غير صحيحة.';
            } else {
                $login_error = 'تعذر تسجيل الدخول. حاول مرة أخرى.';
            }
        } else {
            wp_safe_redirect($redirect_to);
            exit;
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
                        <h2 class="text-2xl font-extrabold tracking-tight">تسجيل الدخول</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            أدخل بيانات حسابك للوصول إلى لوحة التحكم.
                        </p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                        آمن ومشفّر
                    </span>
                </div>

                <?php if ($login_notice): ?>
                    <div class="mt-4 rounded-2xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-800 ring-1 ring-emerald-200">
                        <?php echo esc_html($login_notice); ?>
                    </div>
                <?php endif; ?>

                <?php if ($login_error): ?>
                    <div class="mt-4 rounded-2xl bg-rose-50 p-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                        <?php echo esc_html($login_error); ?>
                    </div>
                <?php endif; ?>

                <form class="mt-6 space-y-4" action="<?php echo esc_url(home_url('/login/')); ?>" method="post" novalidate>
                    <div>
                        <label for="log" class="text-xs font-semibold text-slate-600">اسم المستخدم أو البريد الإلكتروني</label>
                        <input
                            id="log"
                            name="log"
                            type="text"
                            autocomplete="username"
                            value="<?php echo esc_attr($login_value); ?>"
                            class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                            placeholder="example@email.com"
                            required />
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label for="pwd" class="text-xs font-semibold text-slate-600">كلمة المرور</label>
                            <a
                                href="<?php echo esc_url(wp_lostpassword_url(home_url('/login/'))); ?>"
                                class="text-xs font-semibold text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-slate-900">
                                نسيت كلمة المرور؟
                            </a>
                        </div>
                        <div class="relative mt-2">
                            <input
                                id="pwd"
                                name="pwd"
                                type="password"
                                autocomplete="current-password"
                                class="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                                placeholder="••••••••"
                                required />
                            <button
                                id="togglePassword"
                                type="button"
                                class="absolute inset-y-0 end-3 my-auto h-8 rounded-lg px-3 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                                إظهار
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                            <input
                                name="rememberme"
                                type="checkbox"
                                value="forever"
                                class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900" />
                            تذكرني
                        </label>

                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
                        <input type="hidden" name="testcookie" value="1" />
                        <?php wp_nonce_field('pge_login_action', 'pge_login_nonce'); ?>
                    </div>

                    <button
                        name="wp-submit"
                        type="submit"
                        class="group inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-b from-slate-900 to-slate-800 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:from-slate-800 hover:to-slate-700">
                        دخول إلى الحساب
                        <span class="text-white/80 transition group-hover:-translate-x-0.5">←</span>
                    </button>
                </form>

                <div class="mt-6 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-sm text-slate-700">
                        ليس لديك حساب؟
                        <a
                            href="<?php echo esc_url(wp_registration_url()); ?>"
                            class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4">
                            أنشئ حسابك الآن
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
    const passwordInput = document.getElementById('pwd');
    const togglePasswordBtn = document.getElementById('togglePassword');

    if (passwordInput && togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            togglePasswordBtn.textContent = isPassword ? 'إخفاء' : 'إظهار';
        });
    }
</script>

<?php get_footer(); ?>
