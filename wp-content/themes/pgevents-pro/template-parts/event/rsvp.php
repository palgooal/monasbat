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
// RSVP Records
// =============================
$meta_key = '_pge_rsvp_map';
$records  = get_post_meta($event_id, $meta_key, true);
if (!is_array($records)) $records = get_post_meta($event_id, '_pge_rsvp_records', true);
if (!is_array($records)) $records = [];

function pge_rsvp_count_for_record($rec) {
    if (($rec['reply'] ?? 'no') !== 'yes') return 0;
    return 1 + max(0, (int) ($rec['companions'] ?? 0));
}

$total_attending = 0;
foreach ($records as $rec) $total_attending += pge_rsvp_count_for_record($rec);
$remaining = ($guest_limit > 0) ? max(0, $guest_limit - $total_attending) : null;

// =============================
// الرد الحالي للضيف
// =============================
$current_key = '';
$current_rec = null;
if ($is_host) {
    $current_key = 'host_' . (int) get_current_user_id();
    $current_rec = $records[$current_key] ?? null;
} elseif ($guest_phone_cookie !== '') {
    $current_key = 'g_' . $guest_phone_cookie;
    $current_rec = $records[$current_key] ?? null;
}

$pref_reply      = $current_rec['reply'] ?? 'yes';
$pref_companions = isset($current_rec['companions']) ? (int) $current_rec['companions'] : 0;
$pref_note       = (string) ($current_rec['note'] ?? '');
$already_replied = ($current_rec !== null);

// =============================
// معالجة الإرسال
// =============================
$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pge_rsvp_submit'])) {
    if (!isset($_POST['pge_rsvp_nonce']) || !wp_verify_nonce($_POST['pge_rsvp_nonce'], 'pge_rsvp_' . $event_id)) {
        $err = 'تعذر التحقق من الطلب. أعد المحاولة.';
    } else {
        $reply      = in_array($_POST['reply'] ?? '', ['yes','no'], true) ? sanitize_text_field($_POST['reply']) : 'no';
        $companions = min(max(0, (int) ($_POST['companions'] ?? 0)), 20);
        $note       = trim(sanitize_text_field($_POST['note'] ?? ''));

        $submitted_phone = pge_norm_phone($_POST['guest_phone'] ?? '');
        $phone = $guest_phone_cookie !== '' ? $guest_phone_cookie : $submitted_phone;

        if ($is_host) {
            $key = 'host_' . (int) get_current_user_id();
        } else {
            if ($phone === '') {
                $err = 'فضلاً أدخل رقم الجوال.';
            } else {
                $invited = pge_get_invited_phones($event_id);
                if (!in_array($phone, $invited, true)) {
                    $err = 'رقم الجوال غير موجود ضمن قائمة المدعوين.';
                }
                $key = 'g_' . $phone;
            }
        }

        if ($err === '') {
            $new_count = ($reply === 'yes') ? (1 + $companions) : 0;
            $old_count = isset($records[$key]) ? pge_rsvp_count_for_record($records[$key]) : 0;
            $new_total = $total_attending - $old_count + $new_count;

            if ($guest_limit > 0 && $reply === 'yes' && $new_total > $guest_limit) {
                $allowed = max(0, $guest_limit - ($total_attending - $old_count));
                $err = 'عذرًا، تجاوزت الطاقة المتاحة. الحد المتبقي: ' . (int) $allowed;
            } else {
                $records[$key] = [
                    'reply'      => $reply,
                    'companions' => $companions,
                    'note'       => $note,
                    'updated_at' => current_time('mysql'),
                ];
                update_post_meta($event_id, $meta_key, $records);
                update_post_meta($event_id, '_pge_rsvp_records', $records);

                if (!$is_host && $guest_phone_cookie === '' && $phone !== '') {
                    $hmac = wp_hash($phone . '|' . (int) $event_id);
                    setcookie($guest_phone_cookie_name, $phone . '|' . $hmac, time() + 7 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
                }

                // Refresh
                $records = get_post_meta($event_id, $meta_key, true);
                if (!is_array($records)) $records = [];
                $total_attending = 0;
                foreach ($records as $rec) $total_attending += pge_rsvp_count_for_record($rec);
                $remaining = ($guest_limit > 0) ? max(0, $guest_limit - $total_attending) : null;

                $pref_reply      = $reply;
                $pref_companions = $companions;
                $pref_note       = $note;
                $already_replied = true;

                $ok = ($reply === 'yes')
                    ? 'تم تأكيد حضورك بنجاح! نراك قريبًا 🎉'
                    : 'تم حفظ اعتذارك، شكراً لإبلاغنا 🌸';
            }
        }
    }
}
?>

<section id="rsvp" class="mx-auto max-w-lg px-4 pb-10" dir="rtl">

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

        <!-- رأس القسم -->
        <div class="border-b border-slate-100 px-5 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-base font-extrabold text-slate-900">تأكيد الحضور (RSVP) ✉️</div>
                    <div class="mt-0.5 text-xs text-slate-500">
                        <?php echo $already_replied ? 'ردّك مسجّل — يمكنك التعديل' : 'اختر ردّك الآن'; ?>
                    </div>
                </div>

                <?php if ($guest_limit > 0): ?>
                    <div class="text-right">
                        <div class="text-xs text-slate-500">المؤكدون</div>
                        <div class="text-lg font-extrabold text-slate-900">
                            <?php echo esc_html($total_attending); ?>
                            <span class="text-sm font-normal text-slate-400">/ <?php echo esc_html($guest_limit); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($guest_limit > 0): ?>
                <!-- شريط التقدم -->
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-emerald-500 transition-all duration-500"
                         style="width: <?php echo min(100, round($total_attending / $guest_limit * 100)); ?>%"></div>
                </div>
                <?php if ($remaining !== null && $remaining <= 10): ?>
                    <p class="mt-1.5 text-xs font-semibold text-amber-600">
                        ⚠️ تبقّى <?php echo esc_html($remaining); ?> مقاعد فقط
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="p-5">

            <!-- رسالة نجاح/خطأ -->
            <?php if ($ok): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-200">
                    <span class="text-xl">🎉</span>
                    <p class="text-sm font-semibold text-emerald-800"><?php echo esc_html($ok); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($err): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl bg-rose-50 p-4 ring-1 ring-rose-200">
                    <span class="text-xl">⚠️</span>
                    <p class="text-sm font-semibold text-rose-800"><?php echo esc_html($err); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="#rsvp">
                <?php wp_nonce_field('pge_rsvp_' . $event_id, 'pge_rsvp_nonce'); ?>

                <!-- ─── أزرار الرد الرئيسية ─── -->
                <div class="grid grid-cols-2 gap-3">
                    <button type="button" data-rsvp="yes"
                        class="rsvp-btn flex h-16 flex-col items-center justify-center rounded-2xl text-sm font-extrabold transition-all active:scale-[.97]
                               <?php echo ($pref_reply === 'yes') ? 'bg-emerald-600 text-white shadow-md shadow-emerald-500/30 ring-2 ring-emerald-600' : 'border-2 border-slate-200 bg-white text-slate-700 hover:border-emerald-300 hover:bg-emerald-50'; ?>">
                        <span class="text-2xl leading-none">✅</span>
                        <span class="mt-1">سأحضر</span>
                    </button>

                    <button type="button" data-rsvp="no"
                        class="rsvp-btn flex h-16 flex-col items-center justify-center rounded-2xl text-sm font-extrabold transition-all active:scale-[.97]
                               <?php echo ($pref_reply === 'no') ? 'bg-slate-800 text-white shadow-md shadow-slate-500/30 ring-2 ring-slate-800' : 'border-2 border-slate-200 bg-white text-slate-700 hover:border-slate-400 hover:bg-slate-50'; ?>">
                        <span class="text-2xl leading-none">❌</span>
                        <span class="mt-1">أعتذر</span>
                    </button>
                </div>

                <input type="hidden" name="reply" id="rsvpReply" value="<?php echo esc_attr($pref_reply); ?>">

                <!-- ─── عدد المرافقين (Stepper) ─── -->
                <div id="rsvpCompanionsBlock" class="mt-4 <?php echo ($pref_reply === 'no') ? 'hidden' : ''; ?>">
                    <label class="mb-2 block text-xs font-bold text-slate-700">
                        عدد المرافقين
                        <span class="ms-1 font-normal text-slate-400">(الحد الأعلى: 20)</span>
                    </label>
                    <div class="flex items-center gap-3">
                        <button type="button" id="companionsMinus"
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border-2 border-slate-200 bg-white text-xl font-bold text-slate-700 hover:bg-slate-50 active:scale-95">
                            −
                        </button>
                        <input type="number" name="companions" id="companionsInput"
                               min="0" max="20"
                               value="<?php echo esc_attr($pref_companions); ?>"
                               class="h-12 flex-1 rounded-2xl border-2 border-slate-200 bg-white px-4 text-center text-lg font-extrabold text-slate-900 outline-none focus:border-indigo-500">
                        <button type="button" id="companionsPlus"
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border-2 border-slate-200 bg-white text-xl font-bold text-slate-700 hover:bg-slate-50 active:scale-95">
                            +
                        </button>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-500">سيتم احتسابك معهم ضمن الطاقة الاستيعابية</p>
                </div>

                <!-- ─── ملاحظة للمضيف ─── -->
                <div class="mt-4">
                    <label class="mb-2 block text-xs font-bold text-slate-700">
                        ملاحظة للمضيف <span class="font-normal text-slate-400">(اختياري)</span>
                    </label>
                    <input name="note"
                           value="<?php echo esc_attr($pref_note); ?>"
                           class="h-12 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-indigo-500"
                           placeholder="حساسية طعام، وصول متأخر..." />
                </div>

                <!-- ─── رقم الجوال (إذا لم يكن محفوظاً) ─── -->
                <?php if (!$is_host && $guest_phone_cookie === ''): ?>
                    <div class="mt-4">
                        <label class="mb-2 block text-xs font-bold text-slate-700">رقم الجوال</label>
                        <input name="guest_phone" type="tel" inputmode="numeric" autocomplete="tel"
                               class="h-12 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-indigo-500"
                               placeholder="05XXXXXXXX" />
                        <p class="mt-1.5 text-xs text-slate-500">يجب أن يكون ضمن قائمة المدعوين</p>
                    </div>
                <?php endif; ?>

                <!-- ─── زر الحفظ ─── -->
                <div class="mt-5">
                    <button type="submit" name="pge_rsvp_submit" value="1"
                        class="h-14 w-full rounded-2xl bg-gradient-to-l from-indigo-600 to-violet-600 text-base font-extrabold text-white shadow-lg shadow-indigo-500/25 hover:from-indigo-500 hover:to-violet-500 active:scale-[.98] transition-transform">
                        <?php echo $already_replied ? 'تحديث ردّي' : 'حفظ الرد'; ?>
                    </button>
                    <p class="mt-2 text-center text-xs text-slate-400">يمكن تعديل ردّك في أي وقت</p>
                </div>

            </form>
        </div>
    </div>

</section>

<script>
(function() {
    const btns      = document.querySelectorAll('.rsvp-btn');
    const input     = document.getElementById('rsvpReply');
    const block     = document.getElementById('rsvpCompanionsBlock');
    const cInput    = document.getElementById('companionsInput');
    const cMinus    = document.getElementById('companionsMinus');
    const cPlus     = document.getElementById('companionsPlus');

    if (!input) return;

    function setActive(val) {
        input.value = val;

        // أُظهر/أُخفي المرافقين
        if (block) block.classList.toggle('hidden', val === 'no');

        btns.forEach(function(b) {
            const v = b.getAttribute('data-rsvp');
            const active = (v === val);

            b.className = 'rsvp-btn flex h-16 flex-col items-center justify-center rounded-2xl text-sm font-extrabold transition-all active:scale-[.97] ';

            if (active && v === 'yes') {
                b.className += 'bg-emerald-600 text-white shadow-md shadow-emerald-500/30 ring-2 ring-emerald-600';
            } else if (active && v === 'no') {
                b.className += 'bg-slate-800 text-white shadow-md shadow-slate-500/30 ring-2 ring-slate-800';
            } else if (v === 'yes') {
                b.className += 'border-2 border-slate-200 bg-white text-slate-700 hover:border-emerald-300 hover:bg-emerald-50';
            } else {
                b.className += 'border-2 border-slate-200 bg-white text-slate-700 hover:border-slate-400 hover:bg-slate-50';
            }
        });
    }

    btns.forEach(function(b) {
        b.addEventListener('click', function() { setActive(b.getAttribute('data-rsvp')); });
    });

    // Stepper (+/-)
    if (cMinus && cPlus && cInput) {
        cMinus.addEventListener('click', function() {
            const v = parseInt(cInput.value, 10) || 0;
            cInput.value = Math.max(0, v - 1);
        });
        cPlus.addEventListener('click', function() {
            const v = parseInt(cInput.value, 10) || 0;
            cInput.value = Math.min(20, v + 1);
        });
    }

    // التهيئة الأولية
    setActive(<?php echo wp_json_encode($pref_reply); ?>);
})();
</script>
