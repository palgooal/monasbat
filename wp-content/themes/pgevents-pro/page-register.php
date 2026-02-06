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
                        <h1 class="text-2xl font-extrabold tracking-tight">إنشاء حساب جديد</h1>
                        <p class="mt-2 text-sm text-slate-600">
                            ابدأ الآن بإدارة مناسباتك ودعواتك من لوحة تحكم واحدة.
                        </p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-200">
                        اشتراك جديد
                    </span>
                </div>

                <?php if ($register_notice): ?>
                    <div class="mt-4 rounded-2xl bg-amber-50 p-3 text-sm font-semibold text-amber-800 ring-1 ring-amber-200">
                        <?php echo esc_html($register_notice); ?>
                    </div>
                <?php endif; ?>

                <?php if ($register_error): ?>
                    <div class="mt-4 rounded-2xl bg-rose-50 p-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                        <?php echo esc_html($register_error); ?>
                    </div>
                <?php endif; ?>

                <form id="registerForm" class="mt-6 space-y-4" action="<?php echo esc_url(home_url('/register/')); ?>" method="post" <?php echo $registration_enabled ? '' : 'aria-disabled="true"'; ?>>
                    <div class="space-y-5">
                        <div>
                            <p class="text-xs font-extrabold text-slate-500">معلومات التواصل</p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label for="full_name" class="text-xs font-semibold text-slate-600">الاسم الكامل</label>
                                    <input
                                        id="full_name"
                                        name="full_name"
                                        type="text"
                                        autocomplete="name"
                                        value="<?php echo esc_attr($full_name_value); ?>"
                                        <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                        class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                                        placeholder="مثال: أحمد علي"
                                        required />
                                </div>

                                <div>
                                    <label for="user_phone" class="text-xs font-semibold text-slate-600">رقم الجوال</label>
                                    <input
                                        id="user_phone"
                                        name="user_phone"
                                        type="tel"
                                        inputmode="tel"
                                        autocomplete="tel"
                                        value="<?php echo esc_attr($phone_value); ?>"
                                        <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                        class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                                        placeholder="05XXXXXXXX"
                                        required />
                                </div>

                                <div>
                                    <label for="user_email" class="text-xs font-semibold text-slate-600">البريد الإلكتروني</label>
                                    <input
                                        id="user_email"
                                        name="user_email"
                                        type="email"
                                        autocomplete="email"
                                        dir="ltr"
                                        value="<?php echo esc_attr($email_value); ?>"
                                        <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                        class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                                        placeholder="example@email.com"
                                        required />
                                </div>
                            </div>
                        </div>

                        <div>
                            <p class="text-xs font-extrabold text-slate-500">بيانات الحساب</p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="pass1" class="text-xs font-semibold text-slate-600">كلمة المرور</label>
                                    <div class="relative mt-2">
                                        <input
                                            id="pass1"
                                            name="pass1"
                                            type="password"
                                            autocomplete="new-password"
                                            <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                            class="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                                            placeholder="••••••••"
                                            minlength="8"
                                            required />
                                        <button
                                            id="togglePassword"
                                            type="button"
                                            <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                            class="absolute inset-y-0 end-3 my-auto h-8 rounded-lg px-3 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                                            إظهار
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <label for="pass2" class="text-xs font-semibold text-slate-600">تأكيد كلمة المرور</label>
                                    <input
                                        id="pass2"
                                        name="pass2"
                                        type="password"
                                        autocomplete="new-password"
                                        <?php echo $registration_enabled ? '' : 'disabled'; ?>
                                        class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                                        placeholder="••••••••"
                                        minlength="8"
                                        required />
                                </div>
                            </div>
                        </div>
                    </div>

                    <label class="inline-flex items-start gap-2 text-sm text-slate-600">
                        <input
                            id="agree"
                            name="agree"
                            type="checkbox"
                            value="1"
                            <?php echo $registration_enabled ? '' : 'disabled'; ?>
                            class="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-900"
                            required />
                        أوافق على الشروط وسياسة الخصوصية.
                    </label>

                    <input id="user_login_hidden" type="hidden" name="user_login" value="<?php echo esc_attr($login_hidden_value); ?>" />
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
                    <?php wp_nonce_field('pge_register_action', 'pge_register_nonce'); ?>

                    <button
                        type="submit"
                        <?php echo $registration_enabled ? '' : 'disabled'; ?>
                        class="group inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-b from-slate-900 to-slate-800 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:from-slate-800 hover:to-slate-700">
                        إنشاء الحساب
                        <span class="text-white/80 transition group-hover:-translate-x-0.5">←</span>
                    </button>
                </form>

                <div class="mt-6 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <p class="text-sm text-slate-700">
                        لديك حساب بالفعل؟
                        <a href="<?php echo esc_url(wp_login_url($redirect_to)); ?>" class="font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4">
                            الدخول إلى الحساب
                        </a>
                    </p>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
    const registerForm = document.getElementById('registerForm');
    const userEmail = document.getElementById('user_email');
    const userPhone = document.getElementById('user_phone');
    const userLoginHidden = document.getElementById('user_login_hidden');
    const pass1 = document.getElementById('pass1');
    const pass2 = document.getElementById('pass2');
    const togglePassword = document.getElementById('togglePassword');

    if (togglePassword && pass1 && pass2) {
        togglePassword.addEventListener('click', () => {
            const isPassword = pass1.type === 'password';
            pass1.type = isPassword ? 'text' : 'password';
            pass2.type = isPassword ? 'text' : 'password';
            togglePassword.textContent = isPassword ? 'إخفاء' : 'إظهار';
        });
    }

    if (registerForm && userEmail && userPhone && userLoginHidden) {
        registerForm.addEventListener('submit', () => {
            const phoneDigits = (userPhone.value || '').replace(/\D/g, '');
            userLoginHidden.value = phoneDigits;
        });
    }
</script>

<?php get_footer(); ?>
