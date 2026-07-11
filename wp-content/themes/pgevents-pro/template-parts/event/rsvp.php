<?php
defined('ABSPATH') || exit;

$event_id  = get_the_ID();
$author_id = (int) get_post_field('post_author', $event_id);

// =============================
// Helpers
// =============================
if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) {
        return preg_replace('/\D+/', '', trim((string) $v));
    }
}

if (!function_exists('pge_get_invited_phones')) {
    function pge_get_invited_phones($event_id) {
        $raw = get_post_meta($event_id, '_pge_invited_phones', true);
        if (is_array($raw)) { $phones = $raw; }
        else {
            $raw = str_replace(["\r\n","\r"], "\n", (string) $raw);
            $phones = array_filter(array_map('trim', explode("\n", $raw)));
        }
        $out = [];
        foreach ($phones as $p) { $n = pge_norm_phone($p); if ($n !== '') $out[] = $n; }
        return array_values(array_unique($out));
    }
}

if (!function_exists('pge_is_host_or_admin')) {
    function pge_is_host_or_admin($event_id) {
        if (current_user_can('administrator')) return true;
        $uid = get_current_user_id();
        if ($uid && $uid === (int) get_post_field('post_author', $event_id)) return true;
        return current_user_can('edit_post', $event_id);
    }
}

// =============================
// Plan limits
// =============================
$plan_limits = ['guest_limit' => 0];
if (class_exists('PGE_Packages')) {
    $plan_limits = array_merge($plan_limits, (array) PGE_Packages::get_user_plan_limits($author_id));
}
$guest_limit = (int) ($plan_limits['guest_limit'] ?? 0);
$is_host     = pge_is_host_or_admin($event_id);

// =============================
// رقم الضيف من الـ Cookie
// =============================
$guest_phone_cookie_name        = 'pge_event_phone_' . (int) $event_id;
$legacy_guest_phone_cookie_name = 'pge_event_guest_phone_' . (int) $event_id;

$guest_phone_cookie_raw = '';
if (isset($_COOKIE[$guest_phone_cookie_name])) {
    $parts = explode('|', (string) $_COOKIE[$guest_phone_cookie_name], 2);
    if (count($parts) === 2) {
        [$raw_phone, $raw_hmac] = $parts;
        if (hash_equals(wp_hash($raw_phone . '|' . (int) $event_id), $raw_hmac)) {
            $guest_phone_cookie_raw = sanitize_text_field($raw_phone);
        }
    }
} elseif (isset($_COOKIE[$legacy_guest_phone_cookie_name])) {
    $guest_phone_cookie_raw = sanitize_text_field((string) $_COOKIE[$legacy_guest_phone_cookie_name]);
}
$guest_phone_cookie = pge_norm_phone($guest_phone_cookie_raw);

// =============================
// RSVP — من الجدول الحقيقي wp_pge_event_rsvps حصرياً
// (_pge_rsvp_map / _pge_rsvp_records القديمان لم يعودا يُكتَبان من هنا؛
//  راجع pge_save_rsvp_response() في rsvp-handler.php لمصدر الحقيقة الوحيد)
// =============================
global $wpdb;
$rsvp_table = $wpdb->prefix . 'pge_event_rsvps';

$total_attending = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(1 + companions), 0) FROM {$rsvp_table} WHERE event_id = %d AND reply = 'yes'",
    $event_id
));
$remaining = ($guest_limit > 0) ? max(0, $guest_limit - $total_attending) : null;

// =============================
// الرد الحالي للضيف — هوية RSVP هي جوال المضيف نفسه لو كان هو الزائر،
// أو جوال الضيف من الكوكي الموقّع
// =============================
$identity_phone = $is_host
    ? pge_norm_phone((string) get_post_meta($event_id, '_pge_host_phone', true))
    : $guest_phone_cookie;

$current_row = null;
if ($identity_phone !== '') {
    $current_row = $wpdb->get_row($wpdb->prepare(
        "SELECT reply, companions, note FROM {$rsvp_table} WHERE event_id = %d AND guest_phone = %s LIMIT 1",
        $event_id,
        $identity_phone
    ), ARRAY_A);
}

// صف قد يكون موجوداً فقط بسبب check-in (reply='pending') — لا يُعتبر "ردّاً" فعلياً
$already_replied = ($current_row !== null && in_array($current_row['reply'], ['yes', 'no'], true));

$pref_reply      = $already_replied ? $current_row['reply'] : 'yes';
$pref_companions = $already_replied ? (int) $current_row['companions'] : 0;
$pref_note       = $already_replied ? (string) $current_row['note'] : '';

// =============================
// معالجة الإرسال — عبر الدالة المركزية الوحيدة pge_save_rsvp_response()
// =============================
$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pge_rsvp_submit'])) {
    if (!isset($_POST['pge_rsvp_nonce']) || !wp_verify_nonce($_POST['pge_rsvp_nonce'], 'pge_rsvp_' . $event_id)) {
        $err = 'تعذر التحقق من الطلب. أعد المحاولة.';
    } else {
        $reply      = in_array($_POST['reply'] ?? '', ['yes', 'no'], true) ? sanitize_text_field($_POST['reply']) : 'no';
        $companions = (int) ($_POST['companions'] ?? 0);
        $note       = trim(sanitize_text_field($_POST['note'] ?? ''));

        $submitted_phone   = pge_norm_phone($_POST['guest_phone'] ?? '');
        $phone_for_request = $guest_phone_cookie !== '' ? $guest_phone_cookie : $submitted_phone;

        $result = function_exists('pge_save_rsvp_response')
            ? pge_save_rsvp_response($event_id, $phone_for_request, $reply, $companions, $note, $is_host)
            : ['success' => false, 'message' => 'تعذر حفظ الرد، حاول لاحقاً.'];

        if (!$result['success']) {
            $err = $result['message'];
        } else {
            if (!$is_host && $guest_phone_cookie === '' && $result['guest_phone'] !== '') {
                $hmac = wp_hash($result['guest_phone'] . '|' . (int) $event_id);
                setcookie($guest_phone_cookie_name, $result['guest_phone'] . '|' . $hmac, time() + 7 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
            }

            $total_attending = $result['total_attending'];
            $remaining       = $result['remaining'];
            $pref_reply      = $result['reply'];
            $pref_companions = $result['companions'];
            $pref_note       = $note;
            $already_replied = true;

            $ok = ($reply === 'yes')
                ? 'تم تأكيد حضورك بنجاح! نراك قريبًا 🎉'
                : 'تم حفظ اعتذارك، شكراً لإبلاغنا 🌸';
        }
    }
}
?>

<section id="rsvp" class="mx-auto max-w-lg px-4 pb-6" dir="rtl" data-pref-reply="<?php echo esc_attr($pref_reply); ?>">

    <div class="overflow-hidden rounded-3xl border border-border bg-white shadow-sm">

        <!-- رأس القسم -->
        <div class="border-b border-border px-5 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-lg font-extrabold text-foreground">تأكيد الحضور</div>
                    <div class="mt-0.5 text-xs text-foreground/75">
                        <?php echo $already_replied ? 'ردّك مسجّل — يمكنك التعديل' : 'يسعدنا معرفة ردّك'; ?>
                    </div>
                </div>

                <?php if ($guest_limit > 0): ?>
                    <div class="text-right">
                        <div class="text-xs text-foreground/75">المؤكدون</div>
                        <div class="text-lg font-extrabold text-foreground">
                            <?php echo esc_html($total_attending); ?>
                            <span class="text-sm font-normal text-foreground/70">/ <?php echo esc_html($guest_limit); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($guest_limit > 0): ?>
                <!-- شريط التقدم -->
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-secondary">
                    <div class="h-full rounded-full bg-gold transition-all duration-500"
                         style="width: <?php echo min(100, round($total_attending / $guest_limit * 100)); ?>%"></div>
                </div>
                <?php if ($remaining !== null && $remaining <= 10): ?>
                    <p class="mt-1.5 text-xs font-semibold text-gold-text">
                        ⚠️ تبقّى <?php echo esc_html($remaining); ?> مقاعد فقط
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="p-5">

            <!-- بطاقة تأكيد أنيقة إذا كان قد ردّ مسبقاً -->
            <?php if ($already_replied && !$ok): ?>
                <div class="mb-5 rounded-2xl border border-border bg-secondary/50 p-4">
                    <div class="flex items-start gap-3">
                        <span aria-hidden="true" class="text-xl"><?php echo ($pref_reply === 'yes') ? '✅' : '❌'; ?></span>
                        <div>
                            <p class="text-sm font-bold text-foreground">
                                <?php echo ($pref_reply === 'yes') ? 'أنت مؤكد الحضور' : 'أنت معتذر عن الحضور'; ?>
                            </p>
                            <?php if ($pref_reply === 'yes' && $pref_companions > 0): ?>
                                <p class="mt-1 text-xs text-foreground/75">مع <?php echo (int) $pref_companions; ?> من المرافقين</p>
                            <?php endif; ?>
                            <?php if ($pref_note !== ''): ?>
                                <p class="mt-1 text-xs text-foreground/75">ملاحظتك: «<?php echo esc_html($pref_note); ?>»</p>
                            <?php endif; ?>
                            <p class="mt-2 text-xs text-foreground/70">يمكنك تعديل ردّك أدناه في أي وقت</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- رسالة نجاح/خطأ -->
            <?php if ($ok): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl bg-primary/10 p-4 ring-1 ring-primary/20">
                    <span aria-hidden="true" class="text-xl">🎉</span>
                    <p class="text-sm font-semibold text-primary-text"><?php echo esc_html($ok); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($err): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl bg-destructive/10 p-4 ring-1 ring-destructive/20">
                    <span aria-hidden="true" class="text-xl">⚠️</span>
                    <p class="text-sm font-semibold text-destructive-text"><?php echo esc_html($err); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="#rsvp">
                <?php wp_nonce_field('pge_rsvp_' . $event_id, 'pge_rsvp_nonce'); ?>

                <!-- ─── أزرار الرد الرئيسية ─── -->
                <div class="grid grid-cols-2 gap-3">
                    <button type="button" data-rsvp="yes"
                        class="rsvp-btn flex h-16 flex-col items-center justify-center rounded-2xl text-sm font-extrabold transition-all active:scale-[.97]
                               <?php echo ($pref_reply === 'yes') ? 'bg-primary text-white shadow-md ring-2 ring-primary' : 'border-2 border-border bg-white text-foreground hover:border-primary/40 hover:bg-primary/5'; ?>">
                        <span aria-hidden="true" class="text-2xl leading-none">✅</span>
                        <span class="mt-1">سأحضر</span>
                    </button>

                    <button type="button" data-rsvp="no"
                        class="rsvp-btn flex h-16 flex-col items-center justify-center rounded-2xl text-sm font-extrabold transition-all active:scale-[.97]
                               <?php echo ($pref_reply === 'no') ? 'bg-foreground text-white shadow-md ring-2 ring-foreground' : 'border-2 border-border bg-white text-foreground hover:border-foreground/30 hover:bg-secondary/40'; ?>">
                        <span aria-hidden="true" class="text-2xl leading-none">❌</span>
                        <span class="mt-1">أعتذر</span>
                    </button>
                </div>

                <input type="hidden" name="reply" id="rsvpReply" value="<?php echo esc_attr($pref_reply); ?>">

                <!-- ─── عدد المرافقين (Stepper) ─── -->
                <div id="rsvpCompanionsBlock" class="mt-4 <?php echo ($pref_reply === 'no') ? 'hidden' : ''; ?>">
                    <label for="companionsInput" class="mb-2 block text-xs font-bold text-foreground">
                        عدد المرافقين
                        <span class="ms-1 font-normal text-foreground/70">(الحد الأعلى: 20)</span>
                    </label>
                    <div class="flex items-center gap-3">
                        <button type="button" id="companionsMinus" aria-label="إنقاص عدد المرافقين"
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border-2 border-border bg-white text-xl font-bold text-foreground hover:bg-secondary/40 active:scale-95">
                            −
                        </button>
                        <input type="number" name="companions" id="companionsInput"
                               min="0" max="20"
                               value="<?php echo esc_attr($pref_companions); ?>"
                               class="h-12 flex-1 rounded-2xl border-2 border-border bg-white px-4 text-center text-lg font-extrabold text-foreground outline-none focus:border-primary">
                        <button type="button" id="companionsPlus" aria-label="زيادة عدد المرافقين"
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border-2 border-border bg-white text-xl font-bold text-foreground hover:bg-secondary/40 active:scale-95">
                            +
                        </button>
                    </div>
                    <p class="mt-1.5 text-xs text-foreground/75">سيتم احتسابك معهم ضمن الطاقة الاستيعابية</p>
                </div>

                <!-- ─── ملاحظة للمضيف ─── -->
                <div class="mt-4">
                    <label for="rsvpNote" class="mb-2 block text-xs font-bold text-foreground">
                        ملاحظة للمضيف <span class="font-normal text-foreground/70">(اختياري)</span>
                    </label>
                    <input name="note" id="rsvpNote"
                           value="<?php echo esc_attr($pref_note); ?>"
                           class="h-12 w-full rounded-2xl border-2 border-border bg-white px-4 text-sm text-foreground outline-none placeholder:text-foreground/70 focus:border-primary"
                           placeholder="حساسية طعام، وصول متأخر..." />
                </div>

                <!-- ─── رقم الجوال (إذا لم يكن محفوظاً) ─── -->
                <?php if (!$is_host && $guest_phone_cookie === ''): ?>
                    <div class="mt-4">
                        <label for="rsvpGuestPhone" class="mb-2 block text-xs font-bold text-foreground">رقم الجوال</label>
                        <input name="guest_phone" id="rsvpGuestPhone" type="tel" inputmode="numeric" autocomplete="tel"
                               class="h-12 w-full rounded-2xl border-2 border-border bg-white px-4 text-sm text-foreground outline-none placeholder:text-foreground/70 focus:border-primary"
                               placeholder="05XXXXXXXX" />
                        <p class="mt-1.5 text-xs text-foreground/75">يجب أن يكون ضمن قائمة المدعوين</p>
                    </div>
                <?php endif; ?>

                <!-- ─── زر الحفظ ─── -->
                <div class="mt-5">
                    <button type="submit" name="pge_rsvp_submit" value="1"
                        class="h-14 w-full rounded-2xl bg-primary text-base font-extrabold text-white shadow-lg transition-colors hover:bg-primary-hover active:scale-[.98]">
                        <?php echo $already_replied ? 'تحديث ردّي' : 'حفظ الرد'; ?>
                    </button>
                    <p class="mt-2 text-center text-xs text-foreground/70">يمكن تعديل ردّك في أي وقت</p>
                </div>

            </form>
        </div>
    </div>

    <!-- تذييل بسيط -->
    <p class="mt-6 text-center text-xs text-foreground/40">
        مدعوم بـ <span class="font-semibold text-gold-text/80">حلوة</span>
    </p>

</section>

<?php // السلوك (JS) موحَّد بالكامل في assets/js/event.js — لا سكربت مكرر هنا.
// القيمة المفضّلة الأولية تصل عبر data-pref-reply على <section id="rsvp"> أعلاه. ?>
