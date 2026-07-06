<?php
defined('ABSPATH') || exit;

$default_redirect = home_url('/dashboard/');
$requested_redirect = isset($_REQUEST['redirect_to']) ? wp_unslash($_REQUEST['redirect_to']) : '';
$redirect_to = $requested_redirect ? wp_validate_redirect($requested_redirect, $default_redirect) : $default_redirect;

$page_id = (int) get_queried_object_id();
$use_elementor_login = function_exists('pge_is_elementor_built_page') && pge_is_elementor_built_page($page_id);
if ($use_elementor_login) {
    get_header();
    if (have_posts()) {
        while (have_posts()) {
            the_post();
            the_content();
        }
    }
    get_footer();
    return;
}

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
        $auth_login = $login_value;

        // Allow login by phone even if formatted with spaces or symbols.
        if (strpos($login_value, '@') === false) {
            $phone_candidate = preg_replace('/\D+/', '', $login_value);
            if ($phone_candidate !== '') {
                $auth_login = $phone_candidate;

                if (!username_exists($auth_login)) {
                    $users_by_phone = get_users([
                        'number'     => 1,
                        'fields'     => 'all',
                        'meta_query' => [
                            'relation' => 'OR',
                            ['key' => 'pge_phone', 'value' => $phone_candidate],
                            ['key' => 'billing_phone', 'value' => $phone_candidate],
                            ['key' => 'phone_number', 'value' => $phone_candidate],
                        ],
                    ]);

                    if (!empty($users_by_phone) && $users_by_phone[0] instanceof WP_User) {
                        $auth_login = $users_by_phone[0]->user_login;
                    }
                }
            }
        }

        $user = wp_signon([
            'user_login'    => $auth_login,
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

<div class="relative min-h-screen overflow-hidden bg-background font-arabic" dir="rtl">

    <!-- زخارف خلفية ناعمة (نفس نمط الزخرفة الموحّد عبر المنصّة) -->
    <svg aria-hidden="true" class="pointer-events-none absolute -top-10 -start-10 h-56 w-56 text-gold opacity-[0.06]" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="1.4">
        <path d="M10 190C40 150 30 90 70 60C100 38 130 45 150 20" stroke-linecap="round"/>
        <circle cx="70" cy="60" r="5"/>
        <circle cx="102" cy="46" r="4"/>
        <circle cx="132" cy="34" r="3.5"/>
        <path d="M70 60c10-6 18-4 24 4M102 46c8-5 16-3 21 4"/>
    </svg>
    <svg aria-hidden="true" class="pointer-events-none absolute -bottom-14 -end-14 h-64 w-64 rotate-180 text-gold opacity-[0.06]" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="1.4">
        <path d="M10 190C40 150 30 90 70 60C100 38 130 45 150 20" stroke-linecap="round"/>
        <circle cx="70" cy="60" r="5"/>
        <circle cx="102" cy="46" r="4"/>
        <circle cx="132" cy="34" r="3.5"/>
        <path d="M70 60c10-6 18-4 24 4M102 46c8-5 16-3 21 4"/>
    </svg>
    <div class="pointer-events-none absolute top-1/3 start-1/2 h-96 w-96 -translate-x-1/2 rounded-full bg-primary/[0.04] blur-3xl"></div>

    <main class="relative mx-auto flex min-h-screen max-w-2xl flex-col items-center justify-center px-4 py-16 sm:px-6">
        <section class="w-full max-w-[560px]">
            <div class="rounded-[28px] border border-border bg-white px-6 py-10 shadow-[0_20px_60px_-15px_rgba(45,25,20,0.10)] sm:px-12 sm:py-12">

                <div class="flex flex-col items-center text-center">
                    <span class="mb-5 inline-flex h-20 w-20 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="h-9 w-9">
                            <rect x="5" y="11" width="14" height="9" rx="2.5"></rect>
                            <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
                        </svg>
                    </span>
                    <h2 class="font-arabic text-2xl font-extrabold leading-tight tracking-tight text-foreground sm:text-3xl">تسجيل الدخول</h2>
                    <p class="mt-3 text-[15px] leading-relaxed text-foreground/70">
                        أدخل بيانات حسابك للوصول إلى لوحة التحكم
                    </p>
                </div>

                <?php if ($login_notice): ?>
                    <div class="mt-6 rounded-2xl bg-emerald-50 p-3.5 text-sm font-semibold text-emerald-800 ring-1 ring-emerald-200">
                        <?php echo esc_html($login_notice); ?>
                    </div>
                <?php endif; ?>

                <?php if ($login_error): ?>
                    <div class="mt-6 rounded-2xl bg-destructive/10 p-3.5 text-sm font-semibold text-destructive-text ring-1 ring-destructive/20">
                        <?php echo esc_html($login_error); ?>
                    </div>
                <?php endif; ?>

                <form class="mt-8 space-y-5" action="<?php echo esc_url(home_url('/login/')); ?>" method="post" novalidate>
                    <div>
                        <label for="log" class="text-[15px] font-bold text-foreground/80">اسم المستخدم أو البريد الإلكتروني</label>
                        <div class="relative mt-2.5">
                            <input
                                id="log"
                                name="log"
                                type="text"
                                autocomplete="username"
                                value="<?php echo esc_attr($login_value); ?>"
                                class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-[15px] text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/35 focus:border-primary"
                                placeholder="example@email.com"
                                required />
                            <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-foreground/35">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                    <path d="m3 7 9 6 9-6"></path>
                                </svg>
                            </span>
                        </div>
                    </div>

                    <div>
                        <label for="pwd" class="text-[15px] font-bold text-foreground/80">كلمة المرور</label>
                        <div class="relative mt-2.5">
                            <input
                                id="pwd"
                                name="pwd"
                                type="password"
                                autocomplete="current-password"
                                class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-[15px] text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/35 focus:border-primary"
                                placeholder="أدخل كلمة المرور"
                                required />
                            <button
                                id="togglePassword"
                                type="button"
                                aria-label="إظهار كلمة المرور"
                                class="absolute inset-y-0 end-1 my-auto flex h-10 w-10 items-center justify-center rounded-xl text-foreground/60 transition-colors hover:text-primary">
                                <svg id="eyeOpenIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="hidden h-5 w-5">
                                    <path d="M1.5 12S5 5 12 5s10.5 7 10.5 7-3.5 7-10.5 7S1.5 12 1.5 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <svg id="eyeOffIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                    <path d="M3 3l18 18"></path>
                                    <path d="M10.6 10.6a3 3 0 0 0 4.24 4.24"></path>
                                    <path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 10.5 8 10.5 8a13.16 13.16 0 0 1-3.17 4.34M6.4 6.35A13.14 13.14 0 0 0 1.5 12s3.5 8 10.5 8a10.9 10.9 0 0 0 5.05-1.19"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 pt-1">
                        <a
                            href="<?php echo esc_url(wp_lostpassword_url(home_url('/login/'))); ?>"
                            class="rounded-md text-sm font-bold text-primary-text transition-colors hover:underline">
                            نسيت كلمة المرور؟
                        </a>

                        <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium text-foreground/70">
                            تذكرني
                            <input
                                name="rememberme"
                                type="checkbox"
                                value="forever"
                                style="accent-color: var(--color-gold);"
                                class="h-[18px] w-[18px] rounded-[6px] border-border" />
                        </label>
                    </div>

                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
                    <input type="hidden" name="testcookie" value="1" />
                    <?php wp_nonce_field('pge_login_action', 'pge_login_nonce'); ?>

                    <button
                        name="wp-submit"
                        type="submit"
                        class="group flex h-14 w-full items-center justify-center gap-2 rounded-2xl bg-primary text-[15px] font-bold text-white transition-colors duration-200 hover:bg-primary-hover">
                        <span class="transition-transform duration-200 group-hover:-translate-x-1">←</span>
                        دخول إلى الحساب
                    </button>
                </form>

                <div class="my-7 flex items-center gap-4">
                    <span class="h-px flex-1 bg-border"></span>
                    <span class="text-xs font-bold text-foreground/65">أو</span>
                    <span class="h-px flex-1 bg-border"></span>
                </div>

                <a
                    href="<?php echo esc_url(add_query_arg('redirect_to', $redirect_to, wp_registration_url())); ?>"
                    class="flex h-14 w-full items-center justify-center gap-2.5 rounded-2xl border-[1.5px] border-gold bg-white text-[15px] font-bold text-gold transition-colors duration-200 hover:bg-gold/[0.06]">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                        <circle cx="9" cy="8" r="4"></circle>
                        <path d="M2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1"></path>
                        <path d="M19 8h4M21 6v4"></path>
                    </svg>
                    ليس لديك حساب؟ أنشئ حسابك الآن
                </a>
            </div>
        </section>
    </main>
</div>

<script>
    const passwordInput = document.getElementById('pwd');
    const togglePasswordBtn = document.getElementById('togglePassword');
    const eyeOpenIcon = document.getElementById('eyeOpenIcon');
    const eyeOffIcon = document.getElementById('eyeOffIcon');

    if (passwordInput && togglePasswordBtn && eyeOpenIcon && eyeOffIcon) {
        togglePasswordBtn.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            togglePasswordBtn.setAttribute('aria-label', isPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
            eyeOpenIcon.classList.toggle('hidden', !isPassword);
            eyeOffIcon.classList.toggle('hidden', isPassword);
        });
    }
</script>

<?php get_footer(); ?>
