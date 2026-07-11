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

if (!function_exists('pge_norm_invite_code')) {
    function pge_norm_invite_code($code)
    {
        if (function_exists('pge_normalize_invite_code')) {
            return pge_normalize_invite_code($code);
        }

        $code = strtoupper(trim((string) $code));
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        if ($code === '') return '';

        $code = substr($code, 0, 8);
        if (strlen($code) > 4) {
            $code = substr($code, 0, 4) . '-' . substr($code, 4);
        }

        return $code;
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
        $payload = (int) $event_id . '|' . pge_norm_phone($phone) . '|' . pge_norm_invite_code($code);
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'pge_salt_fallback';
        return hash_hmac('sha256', $payload, $salt);
    }
}

if (!function_exists('pge_cookie_is_valid')) {
    function pge_cookie_is_valid($event_id, $token)
    {
        $token = (string) $token;
        if ($token === '') return false;

        $invited    = pge_get_invited_phones($event_id);
        if (empty($invited)) return false;

        $event_code = pge_norm_invite_code((string) get_post_meta($event_id, '_pge_invite_code', true));
        $guests_map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];

        foreach ($invited as $p) {
            $personal = isset($guests_map[$p]['code'])
                ? pge_norm_invite_code($guests_map[$p]['code'])
                : '';

            if ($personal !== '') {
                if (hash_equals(pge_make_access_token($event_id, $p, $personal), $token)) return true;
            }

            if ($event_code !== '') {
                if (hash_equals(pge_make_access_token($event_id, $p, $event_code), $token)) return true;
            }
        }
        return false;
    }
}

// =============================
// Event info (للعرض في الغلاف)
// =============================
$event_title = get_the_title($event_id);
$cover_url   = get_the_post_thumbnail_url($event_id, 'large');
$event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
$event_date_display = '';
if ($event_date_raw) {
    $ts = strtotime(str_replace('T', ' ', $event_date_raw));
    if ($ts) $event_date_display = date_i18n('j F Y', $ts);
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
    // تحقق لطيف من الـ nonce — بدون check_admin_referer() التي تعرض شاشة
    // wp_die() العامة عند الفشل. هنا نعيد المستخدم لنفس نموذج الدخول مع
    // رسالة خطأ واضحة قابلة للترجمة بدلاً من قطع التنفيذ بالكامل.
    $access_nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

    if (!wp_verify_nonce($access_nonce, 'pge_event_access_' . $event_id)) {
        $access_error = 'انتهت صلاحية الجلسة، فضلاً أعد إدخال البيانات والمحاولة مجدداً.';
    } else {
        $code  = isset($_POST['invite_code']) ? sanitize_text_field($_POST['invite_code']) : '';
        $phone = isset($_POST['guest_phone']) ? sanitize_text_field($_POST['guest_phone']) : '';

        $code = pge_norm_invite_code($code);
        $phone_n = pge_norm_phone($phone);

        $invited    = pge_get_invited_phones($event_id);
        $guests_map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
        $event_code = pge_norm_invite_code((string) get_post_meta($event_id, '_pge_invite_code', true));

        $personal_code = isset($guests_map[$phone_n]['code'])
            ? pge_norm_invite_code($guests_map[$phone_n]['code'])
            : '';
        $valid_code = ($personal_code !== '') ? $personal_code : $event_code;

        if ($code === '' || $phone_n === '') {
            $access_error = 'فضلاً أدخل رمز الدعوة ورقم الجوال.';
        } elseif ($valid_code === '') {
            $access_error = 'هذه المناسبة غير مهيّأة بعد. تواصل مع المضيف.';
        } elseif (empty($invited)) {
            $access_error = 'قائمة المدعوين غير مُضافة بعد. تواصل مع المضيف.';
        } elseif (!in_array($phone_n, $invited, true)) {
            $access_error = 'رقم الجوال غير موجود ضمن قائمة المدعوين.';
        } elseif (!hash_equals($valid_code, $code)) {
            $access_error = 'رمز الدعوة غير صحيح.';
        } else {
            $token = pge_make_access_token($event_id, $phone_n, $valid_code);

            setcookie($cookie_name, $token, time() + 7 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE[$cookie_name] = $token;

            $phone_cookie = 'pge_event_phone_' . (int) $event_id;
            $phone_hmac       = wp_hash($phone_n . '|' . (int) $event_id);
            $phone_cookie_val = $phone_n . '|' . $phone_hmac;
            setcookie($phone_cookie, $phone_cookie_val, time() + 7 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE[$phone_cookie] = $phone_cookie_val;

            wp_safe_redirect(get_permalink($event_id));
            exit;
        }
    }
}

// =============================
// If access denied: show gate
// =============================
if (!$access_ok) : ?>

<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($event_title); ?> — دعوة خاصة</title>
    <?php wp_head(); ?>
    <style>body { margin: 0; padding: 0; }</style>
    <?php /* .gate-bg / .gate-card والحركة المصاحبة أصبحت مُصرَّفة ضمن output.css
             العام (راجع assets/css/input.css) — لا نمط مكرر هنا. */ ?>
</head>
<body>
<div class="gate-bg">

    <!-- الكارد الرئيسي -->
    <div class="gate-card w-full max-w-sm overflow-hidden rounded-3xl bg-white shadow-2xl">

        <!-- الغلاف / رأس الكارد -->
        <?php if ($cover_url): ?>
            <div class="relative h-52 overflow-hidden">
                <img src="<?php echo esc_url($cover_url); ?>"
                     alt="<?php echo esc_attr($event_title); ?>"
                     class="h-full w-full object-cover">
                <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(0,0,0,.7),rgba(0,0,0,.2) 50%,transparent)"></div>
                <div class="absolute bottom-0 w-full p-5">
                    <h1 class="text-xl font-extrabold leading-tight text-white">
                        <?php echo esc_html($event_title); ?>
                    </h1>
                    <?php if ($event_date_display): ?>
                        <p class="mt-1 text-sm text-white/80">
                            <?php echo esc_html($event_date_display); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-gradient-to-l from-primary to-primary-hover p-6 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white/20 text-3xl">
                    🎉
                </div>
                <h1 class="mt-3 text-xl font-extrabold text-white">
                    <?php echo esc_html($event_title); ?>
                </h1>
                <?php if ($event_date_display): ?>
                    <p class="mt-1 text-sm text-white/80"><?php echo esc_html($event_date_display); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- المحتوى / الفورم -->
        <div class="p-6">

            <div class="mb-5 text-center">
                <div class="inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary-text ring-1 ring-primary/20">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                    دعوة خاصة — للمدعوين فقط
                </div>
                <p class="mt-3 text-sm text-foreground/80">أدخل رمز دعوتك ورقم جوالك للوصول إلى تفاصيل المناسبة</p>
            </div>

            <?php if ($access_error): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl bg-destructive/10 p-4 ring-1 ring-destructive/20">
                    <span class="mt-0.5 text-destructive-text">⚠️</span>
                    <p class="text-sm font-medium text-destructive-text"><?php echo esc_html($access_error); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4">
                <?php wp_nonce_field('pge_event_access_' . $event_id); ?>

                <!-- رمز الدعوة -->
                <div>
                    <label for="accessInviteCode" class="mb-2 block text-xs font-bold text-foreground">رمز الدعوة</label>
                    <input name="invite_code"
                           id="accessInviteCode"
                           autocomplete="one-time-code"
                           dir="ltr"
                           maxlength="9"
                           class="h-14 w-full rounded-2xl border-2 border-border bg-secondary/40 px-4 text-center text-lg font-bold tracking-widest text-foreground outline-none placeholder:text-foreground/65 placeholder:font-normal placeholder:tracking-normal focus:border-primary focus:bg-white"
                           placeholder="XXXX-XXXX"
                           <?php if ($access_error && isset($_POST['invite_code'])): ?>
                               value="<?php echo esc_attr(sanitize_text_field($_POST['invite_code'])); ?>"
                           <?php endif; ?> />
                    <p class="mt-1.5 text-xs text-foreground/75">الرمز المرسل لك عبر واتساب أو الرسائل</p>
                </div>

                <!-- رقم الجوال -->
                <div>
                    <label for="accessGuestPhone" class="mb-2 block text-xs font-bold text-foreground">رقم الجوال</label>
                    <input name="guest_phone"
                           id="accessGuestPhone"
                           type="tel"
                           inputmode="numeric"
                           autocomplete="tel"
                           class="h-14 w-full rounded-2xl border-2 border-border bg-secondary/40 px-4 text-sm text-foreground outline-none placeholder:text-foreground/65 focus:border-primary focus:bg-white"
                           placeholder="05XXXXXXXX"
                           <?php if ($access_error && isset($_POST['guest_phone'])): ?>
                               value="<?php echo esc_attr(sanitize_text_field($_POST['guest_phone'])); ?>"
                           <?php endif; ?> />
                </div>

                <!-- زر الدخول -->
                <button type="submit" name="pge_access_submit" value="1"
                    class="h-14 w-full rounded-2xl bg-primary text-base font-bold text-white shadow-lg transition-colors hover:bg-primary-hover active:scale-[.98]">
                    دخول للمناسبة ←
                </button>

            </form>

            <p class="mt-5 text-center text-xs text-foreground/70">
                يُحفظ دخولك على هذا الجهاز لمدة 7 أيام تلقائياً
            </p>
        </div>
    </div>

    <!-- Branding -->
    <p class="mt-6 text-center text-xs text-white/30">
        مدعوم بـ <span class="font-semibold text-white/50">حلوة</span>
    </p>

</div>

<script>
(function() {
    const inviteInput = document.querySelector('input[name="invite_code"]');
    if (!inviteInput) return;

    function normalizeInviteCode(value) {
        const cleaned = (value || '').toString().toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
        if (cleaned.length > 4) return cleaned.slice(0, 4) + '-' + cleaned.slice(4);
        return cleaned;
    }

    inviteInput.addEventListener('input', function() {
        const pos = inviteInput.selectionStart;
        inviteInput.value = normalizeInviteCode(inviteInput.value);
    });
})();
</script>

<?php wp_footer(); ?>
</body>
</html>

<?php
    return false;
endif;

// ✅ الوصول مسموح
return true;
