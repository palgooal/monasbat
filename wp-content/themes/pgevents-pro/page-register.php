<?php
defined('ABSPATH') || exit;

$default_redirect = home_url('/dashboard/');
$requested_redirect = isset($_REQUEST['redirect_to']) ? wp_unslash($_REQUEST['redirect_to']) : '';
$redirect_to = $requested_redirect ? wp_validate_redirect($requested_redirect, $default_redirect) : $default_redirect;

if (is_user_logged_in()) {
    wp_safe_redirect($redirect_to);
    exit;
}

$registration_enabled = (bool) get_option('users_can_register');
$register_error = '';
$register_notice = '';

$full_name_value = '';
$phone_value = '';
$email_value = '';
$login_hidden_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nonce = isset($_POST['pge_register_nonce']) ? sanitize_text_field(wp_unslash($_POST['pge_register_nonce'])) : '';

    $full_name_value = isset($_POST['full_name']) ? sanitize_text_field(wp_unslash($_POST['full_name'])) : '';
    $phone_value = isset($_POST['user_phone']) ? sanitize_text_field(wp_unslash($_POST['user_phone'])) : '';
    $email_value = isset($_POST['user_email']) ? sanitize_email(wp_unslash($_POST['user_email'])) : '';
    $login_hidden_value = isset($_POST['user_login']) ? sanitize_user(wp_unslash($_POST['user_login']), true) : '';
    $agree = !empty($_POST['agree']);

    $pass1 = isset($_POST['pass1']) ? (string) wp_unslash($_POST['pass1']) : '';
    $pass2 = isset($_POST['pass2']) ? (string) wp_unslash($_POST['pass2']) : '';
    $phone_digits_value = preg_replace('/\D+/', '', $phone_value);

    if (isset($_POST['redirect_to'])) {
        $posted_redirect = wp_unslash($_POST['redirect_to']);
        $redirect_to = $posted_redirect ? wp_validate_redirect($posted_redirect, $default_redirect) : $default_redirect;
    }

    if (!$nonce || !wp_verify_nonce($nonce, 'pge_register_action')) {
        $register_error = 'انتهت الجلسة، أعد المحاولة.';
    } elseif (!$registration_enabled) {
        $register_error = 'التسجيل مغلق حالياً.';
    } elseif ($full_name_value === '') {
        $register_error = 'يرجى إدخال الاسم الكامل.';
    } elseif ($phone_digits_value === '') {
        $register_error = 'يرجى إدخال رقم جوال صحيح.';
    } elseif (!is_email($email_value)) {
        $register_error = 'يرجى إدخال بريد إلكتروني صحيح.';
    } elseif (email_exists($email_value)) {
        $register_error = 'هذا البريد الإلكتروني مستخدم مسبقاً.';
    } elseif (strlen($pass1) < 8) {
        $register_error = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
    } elseif ($pass1 !== $pass2) {
        $register_error = 'كلمتا المرور غير متطابقتين.';
    } elseif (!$agree) {
        $register_error = 'يجب الموافقة على الشروط وسياسة الخصوصية.';
    } else {
        $phone_digits = $phone_digits_value;

        // Username is always the phone number for direct phone login.
        $user_login = sanitize_user($phone_digits, true);
        $login_hidden_value = $user_login;

        if ($user_login === '') {
            $register_error = 'تعذر استخدام رقم الجوال كاسم مستخدم.';
        } elseif (username_exists($user_login)) {
            $register_error = 'رقم الجوال مستخدم مسبقاً.';
        } else {
            $existing_phone_users = get_users([
                'meta_key'   => 'pge_phone',
                'meta_value' => $phone_digits,
                'fields'     => 'ids',
                'number'     => 1,
            ]);

            if (!empty($existing_phone_users)) {
                $register_error = 'رقم الجوال مستخدم مسبقاً.';
            }
        }

        if ($register_error === '') {
            $new_user_id = wp_create_user($user_login, $pass1, $email_value);

            if (is_wp_error($new_user_id)) {
                $register_error = 'تعذر إنشاء الحساب. حاول مرة أخرى.';
            } else {
                $full_name_parts = preg_split('/\s+/', trim($full_name_value), 2);
                $first_name = isset($full_name_parts[0]) ? $full_name_parts[0] : '';
                $last_name = isset($full_name_parts[1]) ? $full_name_parts[1] : '';

                wp_update_user([
                    'ID'           => $new_user_id,
                    'display_name' => $full_name_value,
                    'nickname'     => $full_name_value,
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                ]);

                if ($phone_digits !== '') {
                    update_user_meta($new_user_id, 'pge_phone', $phone_digits);
                    update_user_meta($new_user_id, 'billing_phone', $phone_digits);
                    update_user_meta($new_user_id, 'phone_number', $phone_digits);
                }

                wp_set_current_user($new_user_id);
                wp_set_auth_cookie($new_user_id, true, is_ssl());

                $registered_user = get_user_by('id', $new_user_id);
                if ($registered_user) {
                    do_action('wp_login', $registered_user->user_login, $registered_user);
                }

                wp_safe_redirect($redirect_to);
                exit;
            }
        }
    }
}

if (!$registration_enabled && $register_error === '') {
    $register_notice = 'التسجيل الجديد غير متاح حالياً. يمكنك تسجيل الدخول إذا كان لديك حساب.';
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
                            <circle cx="9.5" cy="8.5" r="4"></circle>
                            <path d="M2.5 21v-1a6.5 6.5 0 0 1 6.5-6.5h1a6.5 6.5 0 0 1 6.5 6.5v1"></path>
                            <path d="M19 8.5h4M21 6.5v4"></path>
                        </svg>
                    </span>
                    <h2 class="font-arabic text-2xl font-extrabold leading-tight tracking-tight text-foreground sm:text-3xl">إنشاء حساب جديد</h2>
                    <p class="mt-3 text-[15px] leading-relaxed text-foreground/70">
                        أنشئ حسابك للبدء في إدارة مناسباتك ودعواتك
                    </p>
                </div>

                <?php if ($register_notice): ?>
                    <div class="mt-6 rounded-2xl bg-secondary p-3.5 text-sm font-semibold text-foreground/80 ring-1 ring-border">
                        <?php echo esc_html($register_notice); ?>
                    </div>
                <?php endif; ?>

                <?php if ($register_error): ?>
                    <div class="mt-6 rounded-2xl bg-destructive/10 p-3.5 text-sm font-semibold text-destructive-text ring-1 ring-destructive/20">
                        <?php echo esc_html($register_error); ?>
                    </div>
                <?php endif; ?>

                <form id="registerForm" class="mt-8 space-y-5" action="<?php echo esc_url(home_url('/register/')); ?>" method="post" <?php echo $registration_enabled ? '' : 'aria-disabled="true"'; ?>>

                    <div>
                        <label for="full_name" class="text-[15px] font-bold text-foreground/80">الاسم الكامل</label>
                        <div class="relative mt-2.5">
                            <input
                                id="full_name"
                                name="full_name"
                                type="text"
                                autocomplete="name"
                                value="<?php echo esc_attr($full_name_value); ?>"
                                <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-[15px] text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/35 focus:border-primary disabled:bg-secondary/60 disabled:text-foreground/40"
                                placeholder="مثال: أحمد علي"
                                required />
                            <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-foreground/35">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                    <circle cx="12" cy="8" r="4"></circle>
                                    <path d="M4 20a8 8 0 0 1 16 0"></path>
                                </svg>
                            </span>
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="user_phone" class="text-[15px] font-bold text-foreground/80">رقم الجوال</label>
                            <div class="relative mt-2.5">
                                <input
                                    id="user_phone"
                                    name="user_phone"
                                    type="tel"
                                    inputmode="tel"
                                    autocomplete="tel"
                                    value="<?php echo esc_attr($phone_value); ?>"
                                    <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                    class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-[15px] text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/35 focus:border-primary disabled:bg-secondary/60 disabled:text-foreground/40"
                                    placeholder="05XXXXXXXX"
                                    required />
                                <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-foreground/35">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                        <rect x="6" y="2" width="12" height="20" rx="2.5"></rect>
                                        <path d="M10 18h4"></path>
                                    </svg>
                                </span>
                            </div>
                        </div>

                        <div>
                            <label for="user_email" class="text-[15px] font-bold text-foreground/80">البريد الإلكتروني</label>
                            <div class="relative mt-2.5">
                                <input
                                    id="user_email"
                                    name="user_email"
                                    type="email"
                                    autocomplete="email"
                                    dir="ltr"
                                    value="<?php echo esc_attr($email_value); ?>"
                                    <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                    class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-[15px] text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/35 focus:border-primary disabled:bg-secondary/60 disabled:text-foreground/40"
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
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="pass1" class="text-[15px] font-bold text-foreground/80">كلمة المرور</label>
                            <div class="relative mt-2.5">
                                <input
                                    id="pass1"
                                    name="pass1"
                                    type="password"
                                    autocomplete="new-password"
                                    <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                    class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-[15px] text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/35 focus:border-primary disabled:bg-secondary/60 disabled:text-foreground/40"
                                    placeholder="••••••••"
                                    minlength="8"
                                    required />
                                <button
                                    id="togglePassword"
                                    type="button"
                                    aria-label="إظهار كلمة المرور"
                                    <?php echo $registration_enabled ? '' : 'disabled'; ?>
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

                        <div>
                            <label for="pass2" class="text-[15px] font-bold text-foreground/80">تأكيد كلمة المرور</label>
                            <div class="relative mt-2.5">
                                <input
                                    id="pass2"
                                    name="pass2"
                                    type="password"
                                    autocomplete="new-password"
                                    <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                    class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-[15px] text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/35 focus:border-primary disabled:bg-secondary/60 disabled:text-foreground/40"
                                    placeholder="••••••••"
                                    minlength="8"
                                    required />
                                <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-foreground/35">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                        <rect x="5" y="11" width="14" height="9" rx="2.5"></rect>
                                        <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
                                    </svg>
                                </span>
                            </div>
                        </div>
                    </div>

                    <label class="flex items-start gap-2.5 pt-1 text-sm font-medium text-foreground/70">
                        <input
                            id="agree"
                            name="agree"
                            type="checkbox"
                            value="1"
                            style="accent-color: var(--color-gold);"
                            <?php echo $registration_enabled ? '' : 'disabled'; ?>
                            class="mt-0.5 h-[18px] w-[18px] shrink-0 rounded-[6px] border-border"
                            required />
                        <span>أوافق على <span class="font-bold text-foreground">الشروط</span> و<span class="font-bold text-foreground">سياسة الخصوصية</span>.</span>
                    </label>

                    <input id="user_login_hidden" type="hidden" name="user_login" value="<?php echo esc_attr($login_hidden_value); ?>" />
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
                    <?php wp_nonce_field('pge_register_action', 'pge_register_nonce'); ?>

                    <button
                        type="submit"
                        <?php echo $registration_enabled ? '' : 'disabled'; ?>
                        class="group flex h-14 w-full items-center justify-center gap-2 rounded-2xl bg-primary text-[15px] font-bold text-white transition-colors duration-200 hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-60">
                        <span class="transition-transform duration-200 group-hover:-translate-x-1">←</span>
                        إنشاء الحساب
                    </button>
                </form>

                <div class="my-7 flex items-center gap-4">
                    <span class="h-px flex-1 bg-border"></span>
                    <span class="text-xs font-bold text-foreground/65">أو</span>
                    <span class="h-px flex-1 bg-border"></span>
                </div>

                <a
                    href="<?php echo esc_url(wp_login_url($redirect_to)); ?>"
                    class="flex h-14 w-full items-center justify-center gap-2.5 rounded-2xl border-[1.5px] border-gold bg-white text-[15px] font-bold text-gold transition-colors duration-200 hover:bg-gold/[0.06]">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                        <path d="M10 17l5-5-5-5"></path>
                        <path d="M15 12H3"></path>
                    </svg>
                    لديك حساب بالفعل؟ تسجيل الدخول
                </a>
            </div>
        </section>
    </main>
</div>

<script>
    const registerForm = document.getElementById('registerForm');
    const userPhone = document.getElementById('user_phone');
    const userLoginHidden = document.getElementById('user_login_hidden');
    const passwordInput = document.getElementById('pass1');
    const pass2Input = document.getElementById('pass2');
    const togglePasswordBtn = document.getElementById('togglePassword');
    const eyeOpenIcon = document.getElementById('eyeOpenIcon');
    const eyeOffIcon = document.getElementById('eyeOffIcon');

    if (togglePasswordBtn && passwordInput && pass2Input && eyeOpenIcon && eyeOffIcon) {
        togglePasswordBtn.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            pass2Input.type = isPassword ? 'text' : 'password';
            togglePasswordBtn.setAttribute('aria-label', isPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
            eyeOpenIcon.classList.toggle('hidden', !isPassword);
            eyeOffIcon.classList.toggle('hidden', isPassword);
        });
    }

    if (registerForm && userPhone && userLoginHidden) {
        registerForm.addEventListener('submit', () => {
            const phoneDigits = (userPhone.value || '').replace(/\D/g, '');
            userLoginHidden.value = phoneDigits;
        });
    }
</script>

<?php get_footer(); ?>
