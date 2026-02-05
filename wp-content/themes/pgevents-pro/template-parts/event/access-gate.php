<?php
defined('ABSPATH') || exit;

$event_id = get_the_ID();

// =============================
// Helpers
// =============================
if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v)
    {
        $v = (string) $v;
        $v = trim($v);
        return preg_replace('/\D+/', '', $v);
    }
}

if (!function_exists('pge_get_invited_phones')) {
    function pge_get_invited_phones($event_id)
    {
        $raw = get_post_meta($event_id, '_pge_invited_phones', true);

        if (is_array($raw)) {
            $phones = $raw;
        } else {
            $raw = (string) $raw;
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            $lines = array_filter(array_map('trim', explode("\n", $raw)));
            $phones = $lines;
        }

        $out = [];
        foreach ($phones as $p) {
            $n = pge_norm_phone($p);
            if ($n !== '') $out[] = $n;
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('pge_is_host_or_admin')) {
    function pge_is_host_or_admin($event_id)
    {
        if (current_user_can('administrator')) return true;

        $author_id = (int) get_post_field('post_author', $event_id);
        $uid = get_current_user_id();
        if ($uid && $uid === $author_id) return true;

        if (current_user_can('edit_post', $event_id)) return true;

        return false;
    }
}

if (!function_exists('pge_access_cookie_name')) {
    function pge_access_cookie_name($event_id)
    {
        return 'pge_event_access_' . (int) $event_id;
    }
}

if (!function_exists('pge_make_access_token')) {
    function pge_make_access_token($event_id, $phone, $code)
    {
        $payload = (int) $event_id . '|' . pge_norm_phone($phone) . '|' . trim((string) $code);
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'pge_salt_fallback';
        return hash_hmac('sha256', $payload, $salt);
    }
}

if (!function_exists('pge_cookie_is_valid')) {
    function pge_cookie_is_valid($event_id, $token)
    {
        $token = (string) $token;
        if ($token === '') return false;

        $code = trim((string) get_post_meta($event_id, '_pge_invite_code', true));
        if ($code === '') return false;

        $invited = pge_get_invited_phones($event_id);
        if (empty($invited)) return false;

        foreach ($invited as $p) {
            $expected = pge_make_access_token($event_id, $p, $code);
            if (hash_equals($expected, $token)) return true;
        }
        return false;
    }
}

// =============================
// Access Logic
// =============================
$is_host = pge_is_host_or_admin($event_id);

$cookie_name = pge_access_cookie_name($event_id);
$cookie_token = isset($_COOKIE[$cookie_name]) ? sanitize_text_field($_COOKIE[$cookie_name]) : '';
$has_cookie_access = pge_cookie_is_valid($event_id, $cookie_token);

$access_ok = ($is_host || $has_cookie_access);
$access_error = '';

// Handle submit
if (!$access_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pge_access_submit'])) {
    check_admin_referer('pge_event_access_' . $event_id);

    $code  = isset($_POST['invite_code']) ? sanitize_text_field($_POST['invite_code']) : '';
    $phone = isset($_POST['guest_phone']) ? sanitize_text_field($_POST['guest_phone']) : '';

    $code = trim($code);
    $phone_n = pge_norm_phone($phone);

    $saved_code = trim((string) get_post_meta($event_id, '_pge_invite_code', true));
    $invited = pge_get_invited_phones($event_id);

    if ($saved_code === '') {
        $access_error = 'هذه المناسبة غير مهيّأة بعد (لا يوجد رمز دعوة). تواصل مع المضيف.';
    } elseif ($code === '' || $phone_n === '') {
        $access_error = 'فضلاً أدخل رمز الدعوة ورقم الجوال.';
    } elseif (!hash_equals($saved_code, $code)) {
        $access_error = 'رمز الدعوة غير صحيح.';
    } elseif (empty($invited)) {
        $access_error = 'قائمة المدعوين غير مُضافة بعد. تواصل مع المضيف.';
    } elseif (!in_array($phone_n, $invited, true)) {
        $access_error = 'رقم الجوال غير موجود ضمن قائمة المدعوين.';
    } else {
        $token = pge_make_access_token($event_id, $phone_n, $saved_code);

        setcookie($cookie_name, $token, time() + 7 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE[$cookie_name] = $token;

        $phone_cookie = 'pge_event_phone_' . (int) $event_id;

        // احفظ رقم الضيف (للاستخدام في RSVP)
        setcookie($phone_cookie, $phone_n, time() + 7 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE[$phone_cookie] = $phone_n;


        wp_safe_redirect(get_permalink($event_id));
        exit;
    }
}

// =============================
// If access denied: show gate and STOP page rendering
// =============================
if (!$access_ok) : ?>
    <section class="relative overflow-hidden">
        <div class="absolute inset-0 -z-10 bg-gradient-to-b from-indigo-50/70 via-white to-slate-50"></div>
        <div class="pointer-events-none absolute -top-28 start-[-10rem] h-96 w-96 rounded-full bg-indigo-500/15 blur-3xl"></div>
        <div class="pointer-events-none absolute top-28 end-[-10rem] h-96 w-96 rounded-full bg-slate-900/10 blur-3xl"></div>

        <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8 lg:py-14">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">

                <div class="inline-flex items-center gap-2 rounded-full bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    وصول محمي
                </div>

                <h1 class="mt-3 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">
                    هذه الصفحة خاصة بالمدعوين
                </h1>

                <p class="mt-2 text-sm leading-6 text-slate-600">
                    أدخل <span class="font-bold">رمز الدعوة</span> و<span class="font-bold">رقم الجوال</span> المسجل ضمن المدعوين لعرض تفاصيل المناسبة.
                </p>

                <?php if ($access_error): ?>
                    <div class="mt-5 rounded-2xl bg-rose-50 p-4 text-sm text-rose-800 ring-1 ring-rose-200">
                        <?php echo esc_html($access_error); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="mt-6 grid gap-4 sm:grid-cols-2">
                    <?php wp_nonce_field('pge_event_access_' . $event_id); ?>

                    <div>
                        <label class="text-xs font-semibold text-slate-600">رمز الدعوة</label>
                        <input name="invite_code" autocomplete="one-time-code"
                            class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                            placeholder="مثال: A9X2-6K" />
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-600">رقم الجوال</label>
                        <input name="guest_phone" inputmode="numeric" autocomplete="tel"
                            class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                            placeholder="05xxxxxxxx" />
                    </div>

                    <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <div class="text-sm text-slate-700">
                            سيتم حفظ دخولك على هذا الجهاز لمدة 7 أيام.
                        </div>
                        <button type="submit" name="pge_access_submit" value="1"
                            class="rounded-2xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                            دخول
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </section>

<?php
    // 👇 أهم سطر: امنع مواصلة العرض
    return false;
endif;

// ✅ إذا الوصول مسموح
return true;
