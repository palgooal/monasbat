<?php
defined('ABSPATH') || exit;

$event_id   = get_the_ID();
$author_id  = (int) get_post_field('post_author', $event_id);

// =============================
// Helpers (fallback if not loaded)
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

// =============================
// Plan limits: from EVENT OWNER plan (author)
// =============================
$plan_limits = ['guest_limit' => 0];

if (class_exists('PGE_Packages')) {
    $plan_limits = array_merge($plan_limits, (array) PGE_Packages::get_user_plan_limits($author_id));
}

$guest_limit = (int) ($plan_limits['guest_limit'] ?? 0); // 0 means unlimited? (we'll treat 0 as unlimited)
$is_host     = pge_is_host_or_admin($event_id);

// =============================
// Identify guest phone (cookie preferred)
// =============================
$guest_phone_cookie_name = 'pge_event_guest_phone_' . (int) $event_id;
$guest_phone_cookie      = isset($_COOKIE[$guest_phone_cookie_name]) ? sanitize_text_field($_COOKIE[$guest_phone_cookie_name]) : '';
$guest_phone_cookie      = pge_norm_phone($guest_phone_cookie);

// RSVP storage meta
$meta_key = '_pge_rsvp_records';
$records  = get_post_meta($event_id, $meta_key, true);
if (!is_array($records)) $records = [];

// Compute totals
function pge_rsvp_count_for_record($rec)
{
    $reply = $rec['reply'] ?? 'no';
    $companions = (int) ($rec['companions'] ?? 0);
    $companions = max(0, $companions);

    if ($reply !== 'yes') return 0;

    // guest himself + companions
    return 1 + $companions;
}

$total_attending = 0;
foreach ($records as $k => $rec) {
    $total_attending += pge_rsvp_count_for_record($rec);
}

$remaining = ($guest_limit > 0) ? max(0, $guest_limit - $total_attending) : null;

// Messages
$err = '';
$ok  = '';

// Prefill (for this guest)
$current_key = '';
$current_rec = null;

// Determine key if possible
if ($is_host) {
    $current_key = 'host_' . (int) get_current_user_id();
    $current_rec = $records[$current_key] ?? null;
} elseif ($guest_phone_cookie !== '') {
    $current_key = 'g_' . $guest_phone_cookie;
    $current_rec = $records[$current_key] ?? null;
}

// Defaults
$pref_reply      = $current_rec['reply'] ?? 'yes';
$pref_companions = isset($current_rec['companions']) ? (int) $current_rec['companions'] : 0;
$pref_note       = (string) ($current_rec['note'] ?? '');

// =============================
// Handle submit
// =============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pge_rsvp_submit'])) {
    if (!isset($_POST['pge_rsvp_nonce']) || !wp_verify_nonce($_POST['pge_rsvp_nonce'], 'pge_rsvp_' . $event_id)) {
        $err = 'تعذر التحقق من الطلب. أعد المحاولة.';
    } else {
        $reply      = isset($_POST['reply']) ? sanitize_text_field($_POST['reply']) : 'no';
        $reply      = in_array($reply, ['yes', 'no'], true) ? $reply : 'no';

        $companions = isset($_POST['companions']) ? (int) $_POST['companions'] : 0;
        $companions = max(0, $companions);

        // حط سقف منطقي للمرافقين (حتى ما يصير Abuse)
        $companions = min($companions, 20);

        $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';
        $note = trim($note);

        // Determine phone
        $submitted_phone = isset($_POST['guest_phone']) ? sanitize_text_field($_POST['guest_phone']) : '';
        $submitted_phone = pge_norm_phone($submitted_phone);

        $phone = $guest_phone_cookie !== '' ? $guest_phone_cookie : $submitted_phone;

        // Host key
        if ($is_host) {
            $key = 'host_' . (int) get_current_user_id();
        } else {
            if ($phone === '') {
                $err = 'فضلاً أدخل رقم الجوال.';
            } else {
                // Must be invited
                $invited = pge_get_invited_phones($event_id);
                if (!in_array($phone, $invited, true)) {
                    $err = 'رقم الجوال غير موجود ضمن قائمة المدعوين.';
                }
                $key = 'g_' . $phone;
            }
        }

        if ($err === '') {
            // Capacity check (only if reply yes and guest_limit > 0)
            $new_count = ($reply === 'yes') ? (1 + $companions) : 0;

            $old_count = 0;
            if (isset($records[$key])) {
                $old_count = pge_rsvp_count_for_record($records[$key]);
            }

            $new_total = $total_attending - $old_count + $new_count;

            if ($guest_limit > 0 && $reply === 'yes' && $new_total > $guest_limit) {
                $allowed = max(0, $guest_limit - ($total_attending - $old_count));
                $err = 'عذرًا، العدد تجاوز الحد المتاح. المتبقي لك: ' . (int) $allowed;
            } else {
                // Save record
                $records[$key] = [
                    'reply'      => $reply,
                    'companions' => $companions,
                    'note'       => $note,
                    'updated_at' => current_time('mysql'),
                ];

                update_post_meta($event_id, $meta_key, $records);

                // If guest (not host) and cookie missing, set it now for convenience
                if (!$is_host && $guest_phone_cookie === '' && $phone !== '') {
                    setcookie($guest_phone_cookie_name, $phone, time() + 7 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
                    $_COOKIE[$guest_phone_cookie_name] = $phone;
                }

                // Refresh computed totals after save
                $records = get_post_meta($event_id, $meta_key, true);
                if (!is_array($records)) $records = [];

                $total_attending = 0;
                foreach ($records as $k => $rec) $total_attending += pge_rsvp_count_for_record($rec);
                $remaining = ($guest_limit > 0) ? max(0, $guest_limit - $total_attending) : null;

                // Prefill update
                $pref_reply = $reply;
                $pref_companions = $companions;
                $pref_note = $note;

                $ok = 'تم حفظ ردّك بنجاح ✅';
            }
        }
    }
}
?>

<section id="rsvp" class="mx-auto max-w-6xl px-4 pb-14 sm:px-6 lg:px-8">
    <div class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-7">

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="text-sm font-extrabold">تأكيد الحضور (RSVP)</div>
                <div class="mt-1 text-sm text-slate-600">اختر ردّك الآن — ويمكن تعديله لاحقًا.</div>
            </div>

            <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                سريع • أقل من دقيقة
            </span>
        </div>

        <!-- Summary -->
        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                <div class="text-xs text-slate-500">الحد الأعلى</div>
                <div class="mt-1 text-base font-extrabold">
                    <?php echo $guest_limit > 0 ? esc_html($guest_limit) : 'غير محدد'; ?>
                </div>
            </div>

            <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                <div class="text-xs text-slate-500">المؤكدون الآن</div>
                <div class="mt-1 text-base font-extrabold"><?php echo esc_html($total_attending); ?></div>
            </div>

            <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                <div class="text-xs text-slate-500">المتبقي</div>
                <div class="mt-1 text-base font-extrabold">
                    <?php echo $guest_limit > 0 ? esc_html($remaining) : '—'; ?>
                </div>
            </div>
        </div>

        <?php if ($err): ?>
            <div class="mt-5 rounded-2xl bg-rose-50 p-4 text-sm text-rose-800 ring-1 ring-rose-200">
                <?php echo esc_html($err); ?>
            </div>
        <?php endif; ?>

        <?php if ($ok): ?>
            <div class="mt-5 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-800 ring-1 ring-emerald-200">
                <?php echo esc_html($ok); ?>
            </div>
        <?php endif; ?>

        <form class="mt-5" method="post" action="#rsvp">
            <?php wp_nonce_field('pge_rsvp_' . $event_id, 'pge_rsvp_nonce'); ?>

            <div class="grid gap-4 lg:grid-cols-3">

                <!-- Reply -->
                <div class="lg:col-span-1">
                    <label class="text-xs font-semibold text-slate-600">ردّك</label>

                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <button type="button" data-rsvp="yes"
                            class="rsvp-btn rounded-2xl px-4 py-3 text-sm font-semibold hover:opacity-95 <?php echo ($pref_reply === 'yes') ? 'bg-emerald-600 text-white' : 'border border-slate-200 bg-white text-slate-800 hover:bg-slate-50'; ?>">
                            سأحضر ✅
                        </button>

                        <button type="button" data-rsvp="no"
                            class="rsvp-btn rounded-2xl px-4 py-3 text-sm font-semibold hover:opacity-95 <?php echo ($pref_reply === 'no') ? 'bg-slate-900 text-white' : 'border border-slate-200 bg-white text-slate-800 hover:bg-slate-50'; ?>">
                            أعتذر ❌
                        </button>
                    </div>

                    <input type="hidden" name="reply" id="rsvpReply" value="<?php echo esc_attr($pref_reply); ?>">
                </div>

                <!-- Companions -->
                <div class="lg:col-span-1">
                    <label class="text-xs font-semibold text-slate-600">عدد المرافقين</label>
                    <input type="number" min="0" max="20" name="companions"
                        value="<?php echo esc_attr($pref_companions); ?>"
                        class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none focus:border-slate-900"
                        placeholder="مثال: 2" />
                    <div class="mt-2 text-xs text-slate-500">سيتم احتسابك + المرافقين ضمن الحد الأعلى.</div>
                </div>

                <!-- Note -->
                <div class="lg:col-span-1">
                    <label class="text-xs font-semibold text-slate-600">ملاحظة للمضيف (اختياري)</label>
                    <input name="note"
                        value="<?php echo esc_attr($pref_note); ?>"
                        class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                        placeholder="حساسية طعام / وصول متأخر..." />
                </div>

                <!-- Guest phone (only if not host and cookie missing) -->
                <?php if (!$is_host && $guest_phone_cookie === ''): ?>
                    <div class="lg:col-span-3">
                        <label class="text-xs font-semibold text-slate-600">رقم الجوال (للتأكيد)</label>
                        <input name="guest_phone" inputmode="numeric" autocomplete="tel"
                            class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                            placeholder="05xxxxxxxx" />
                        <div class="mt-2 text-xs text-slate-500">لن يُقبل إلا إذا كان ضمن قائمة المدعوين.</div>
                    </div>
                <?php endif; ?>

            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                <div class="text-sm text-slate-700">
                    <span class="font-bold">ملاحظة:</span> يمكنك تعديل ردّك لاحقًا.
                </div>

                <button type="submit" name="pge_rsvp_submit" value="1"
                    class="rounded-2xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                    حفظ الرد
                </button>
            </div>
        </form>

        <!-- Tiny JS: toggle reply buttons -->
        <script>
            (function() {
                const btns = document.querySelectorAll('.rsvp-btn');
                const input = document.getElementById('rsvpReply');
                if (!btns.length || !input) return;

                function setActive(val) {
                    input.value = val;
                    btns.forEach(b => {
                        const v = b.getAttribute('data-rsvp');
                        const active = (v === val);

                        b.classList.remove('bg-emerald-600', 'bg-slate-900', 'text-white', 'border', 'border-slate-200', 'bg-white', 'text-slate-800', 'hover:bg-slate-50');

                        if (active && v === 'yes') {
                            b.classList.add('bg-emerald-600', 'text-white');
                        } else if (active && v === 'no') {
                            b.classList.add('bg-slate-900', 'text-white');
                        } else {
                            b.classList.add('border', 'border-slate-200', 'bg-white', 'text-slate-800', 'hover:bg-slate-50');
                        }
                    });
                }

                btns.forEach(b => b.addEventListener('click', () => setActive(b.getAttribute('data-rsvp'))));
            })();
        </script>

    </div>
</section>