<?php
defined('ABSPATH') || exit;

$event_id = get_the_ID();

// --- Meta
$event_date_raw  = (string) get_post_meta($event_id, '_pge_event_date',     true);
$event_time      = (string) get_post_meta($event_id, '_pge_event_time',     true);
$event_address   = (string) get_post_meta($event_id, '_pge_event_address',  true); // اسم القاعة / العنوان
$map_url         = (string) get_post_meta($event_id, '_pge_event_location', true); // رابط Google Maps

$cover_url = get_the_post_thumbnail_url($event_id, 'full');

$title     = get_the_title($event_id);
$share_url = get_permalink($event_id);

// تاريخ ووقت
$event_date  = '';
$iso_datetime = '';
if ($event_date_raw) {
    $normalized = str_replace(' ', 'T', (string) $event_date_raw);

    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $normalized)) {
        [$event_date, $parsed_time] = explode('T', $normalized, 2);
        if (!$event_time) $event_time = $parsed_time;
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized)) {
        $event_date = $normalized;
    } else {
        $ts = strtotime(str_replace('T', ' ', (string) $event_date_raw));
        if ($ts) {
            $event_date = date('Y-m-d', $ts);
            if (!$event_time) $event_time = date('H:i', $ts);
        }
    }

    $iso_datetime = $event_date . 'T' . ($event_time ?: '20:30') . ':00';
}

$display_date = $event_date ? date_i18n('j F Y', strtotime($event_date)) : '';

// هل الموقع متاح؟
$has_location = ($map_url !== '' || $event_address !== '');
?>

<div class="mx-auto max-w-lg px-4 pt-6 pb-2" dir="rtl">

    <!-- بطاقة الغلاف الرئيسية -->
    <div class="overflow-hidden rounded-3xl shadow-md">

        <!-- صورة الغلاف -->
        <?php if ($cover_url): ?>
            <div class="relative">
                <img src="<?php echo esc_url($cover_url); ?>"
                     alt="<?php echo esc_attr($title); ?>"
                     class="h-64 w-full object-cover sm:h-80">
                <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(0,0,0,.7),rgba(0,0,0,.25) 50%,transparent)"></div>

                <!-- العنوان فوق الصورة -->
                <div class="absolute bottom-0 w-full p-5">
                    <div class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/25 backdrop-blur-sm">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400"></span>
                        دعوة خاصة
                    </div>
                    <h1 class="mt-2 text-2xl font-extrabold leading-tight text-white drop-shadow-sm sm:text-3xl">
                        <?php echo esc_html($title); ?>
                    </h1>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-gradient-to-l from-indigo-600 to-violet-700 p-8 text-center">
                <div class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/25">
                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400"></span>
                    دعوة خاصة
                </div>
                <h1 class="mt-3 text-2xl font-extrabold text-white sm:text-3xl">
                    <?php echo esc_html($title); ?>
                </h1>
            </div>
        <?php endif; ?>

        <!-- شريط المعلومات السريعة -->
        <div class="bg-white px-5 py-4">
            <div class="flex flex-wrap gap-2">

                <?php if ($display_date): ?>
                    <div class="flex items-center gap-2 rounded-2xl bg-slate-50 px-4 py-2.5 ring-1 ring-slate-200">
                        <span class="text-base">📅</span>
                        <span class="text-sm font-semibold text-slate-800"><?php echo esc_html($display_date); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($event_time): ?>
                    <div class="flex items-center gap-2 rounded-2xl bg-slate-50 px-4 py-2.5 ring-1 ring-slate-200">
                        <span class="text-base">🕐</span>
                        <span class="text-sm font-semibold text-slate-800"><?php echo esc_html($event_time); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($has_location): ?>
                    <div class="flex flex-1 items-center justify-between gap-2 rounded-2xl bg-slate-50 px-4 py-2.5 ring-1 ring-slate-200">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-base shrink-0">📍</span>
                            <span class="truncate text-sm font-semibold text-slate-800">
                                <?php echo $event_address !== '' ? esc_html($event_address) : 'الموقع على الخريطة'; ?>
                            </span>
                        </div>
                        <?php if ($map_url): ?>
                            <a href="<?php echo esc_url($map_url); ?>"
                               target="_blank" rel="noopener"
                               class="shrink-0 rounded-xl bg-slate-900 px-3 py-1 text-xs font-bold text-white hover:bg-slate-700">
                                الخريطة
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- العد التنازلي -->
    <?php if ($iso_datetime):
        // ── حالة المناسبة بناءً على التاريخ الحالي ──
        $event_ts  = strtotime(str_replace('T', ' ', $iso_datetime));
        $now       = time();
        $is_past   = $event_ts && $event_ts < $now;
        $is_today  = $event_ts && date('Y-m-d', $event_ts) === date('Y-m-d');
        $days_left = (!$is_past && $event_ts) ? (int) ceil(($event_ts - $now) / 86400) : 0;
    ?>
        <div class="mt-3 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

        <?php if ($is_past): ?>
            <!-- ── المناسبة انتهت ── -->
            <div class="flex items-center justify-between px-5 py-4">
                <div>
                    <div class="text-sm font-extrabold text-slate-700">انتهت المناسبة</div>
                    <div class="mt-0.5 text-xs text-slate-400">شكراً للحضور 🎉</div>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-[11px] font-bold text-slate-500">مضت</span>
            </div>

        <?php else: ?>
            <!-- ── العد التنازلي ── -->
            <div class="flex items-center justify-between px-5 pt-4 pb-2">
                <span class="text-sm font-extrabold text-slate-900">
                    <?php echo $is_today ? 'اليوم! 🎉' : 'الوقت المتبقي'; ?>
                </span>
                <?php if ($is_today): ?>
                    <span class="animate-pulse rounded-full bg-rose-500 px-2.5 py-0.5 text-[11px] font-bold text-white">الآن 🔴</span>
                <?php elseif ($days_left <= 3): ?>
                    <span class="rounded-full bg-amber-500 px-2.5 py-0.5 text-[11px] font-bold text-white">قريباً ⚡</span>
                <?php else: ?>
                    <span class="rounded-full bg-indigo-600 px-2.5 py-0.5 text-[11px] font-bold text-white"><?php echo (int)$days_left; ?> يوم</span>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-4 gap-2 px-4 pb-4"
                 data-countdown="<?php echo esc_attr($iso_datetime); ?>">
                <div class="flex flex-col items-center rounded-2xl bg-slate-50 py-3 ring-1 ring-slate-200">
                    <span class="cd-days text-2xl font-extrabold text-slate-900">—</span>
                    <span class="mt-1 text-[11px] text-slate-500">يوم</span>
                </div>
                <div class="flex flex-col items-center rounded-2xl bg-slate-50 py-3 ring-1 ring-slate-200">
                    <span class="cd-hours text-2xl font-extrabold text-slate-900">—</span>
                    <span class="mt-1 text-[11px] text-slate-500">ساعة</span>
                </div>
                <div class="flex flex-col items-center rounded-2xl bg-slate-50 py-3 ring-1 ring-slate-200">
                    <span class="cd-mins text-2xl font-extrabold text-slate-900">—</span>
                    <span class="mt-1 text-[11px] text-slate-500">دقيقة</span>
                </div>
                <div class="flex flex-col items-center rounded-2xl bg-slate-50 py-3 ring-1 ring-slate-200">
                    <span class="cd-secs text-2xl font-extrabold text-slate-900">—</span>
                    <span class="mt-1 text-[11px] text-slate-500">ثانية</span>
                </div>
            </div>
        <?php endif; ?>

        </div>
    <?php endif; ?>

    <!-- أزرار الإجراءات -->
    <div class="mt-3 grid grid-cols-2 gap-3">
        <a href="#rsvp"
           class="flex h-14 items-center justify-center rounded-2xl bg-gradient-to-l from-indigo-600 to-violet-600 text-sm font-bold text-white shadow-md shadow-indigo-500/25 hover:from-indigo-500 hover:to-violet-500 active:scale-[.98] transition-transform">
            تأكيد الحضور ✅
        </a>

        <button type="button"
            class="js-add-to-calendar flex h-14 items-center justify-center rounded-2xl border-2 border-slate-200 bg-white text-sm font-bold text-slate-800 hover:bg-slate-50 active:scale-[.98] transition-transform"
            data-title="<?php echo esc_attr($title); ?>"
            data-location="<?php echo esc_attr($event_address ?: 'الموقع على الخريطة'); ?>"
            data-start="<?php echo esc_attr($iso_datetime); ?>">
            📅 أضف للتقويم
        </button>
    </div>

    <!-- زر المشاركة (واتساب + نسخ رابط) -->
    <div class="mt-3 grid grid-cols-2 gap-3">
        <button type="button"
            class="js-share-wa flex h-12 items-center justify-center gap-2 rounded-2xl border-2 border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <span>📲</span> واتساب
        </button>

        <button type="button"
            class="js-copy-link flex h-12 items-center justify-center gap-2 rounded-2xl border-2 border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50"
            data-copy="<?php echo esc_attr($share_url); ?>">
            <span>🔗</span> نسخ الرابط
        </button>
    </div>

</div>

<script>
// ──────────────────────────────
// Countdown Timer
// ──────────────────────────────
(function() {
    const el = document.querySelector('[data-countdown]');
    if (!el) return;

    const target = new Date(el.dataset.countdown).getTime();

    function pad(n) { return String(n).padStart(2, '0'); }

    function tick() {
        const diff = target - Date.now();
        if (diff <= 0) {
            el.querySelector('.cd-days').textContent  = '00';
            el.querySelector('.cd-hours').textContent = '00';
            el.querySelector('.cd-mins').textContent  = '00';
            el.querySelector('.cd-secs').textContent  = '00';
            return;
        }
        el.querySelector('.cd-days').textContent  = pad(Math.floor(diff / 86400000));
        el.querySelector('.cd-hours').textContent = pad(Math.floor((diff % 86400000) / 3600000));
        el.querySelector('.cd-mins').textContent  = pad(Math.floor((diff % 3600000) / 60000));
        el.querySelector('.cd-secs').textContent  = pad(Math.floor((diff % 60000) / 1000));
    }
    tick();
    setInterval(tick, 1000);
})();

// ──────────────────────────────
// نسخ الرابط
// ──────────────────────────────
document.querySelectorAll('.js-copy-link').forEach(function(el) {
    el.addEventListener('click', async function() {
        const txt = el.getAttribute('data-copy') || '';
        if (!txt) return;
        try {
            await navigator.clipboard.writeText(txt);
        } catch(e) {
            const ta = document.createElement('textarea');
            ta.value = txt;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }
        const orig = el.innerHTML;
        el.innerHTML = '<span>✅</span> تم النسخ';
        setTimeout(function() { el.innerHTML = orig; }, 1500);
    });
});

// ──────────────────────────────
// مشاركة واتساب
// ──────────────────────────────
document.querySelectorAll('.js-share-wa').forEach(function(el) {
    el.addEventListener('click', function() {
        const url = <?php echo wp_json_encode(get_permalink($event_id)); ?>;
        const title = <?php echo wp_json_encode($title); ?>;
        window.open('https://wa.me/?text=' + encodeURIComponent('أنت مدعو: ' + title + '\n' + url), '_blank');
    });
});

// ──────────────────────────────
// إضافة للتقويم
// ──────────────────────────────
document.querySelectorAll('.js-add-to-calendar').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const title    = encodeURIComponent(btn.dataset.title || '');
        const location = encodeURIComponent(btn.dataset.location || '');
        const startRaw = btn.dataset.start || '';
        if (!startRaw) return;

        const startDate = new Date(startRaw);
        const endDate   = new Date(startDate.getTime() + 3 * 60 * 60 * 1000); // +3 ساعات

        function toGcalDate(d) {
            return d.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
        }

        const gcal = 'https://www.google.com/calendar/render?action=TEMPLATE'
            + '&text='     + title
            + '&dates='    + toGcalDate(startDate) + '/' + toGcalDate(endDate)
            + '&location=' + location;

        window.open(gcal, '_blank');
    });
});
</script>
