<?php
defined('ABSPATH') || exit;

$event_id = get_the_ID();

// --- Meta (عدّل المفاتيح حسب تخزينك داخل البلجن)
$event_date  = (string) get_post_meta($event_id, '_pge_event_date', true);     // YYYY-MM-DD
$event_time  = (string) get_post_meta($event_id, '_pge_event_time', true);     // HH:MM (اختياري)
$event_place = (string) get_post_meta($event_id, '_pge_event_place', true);    // نص
$map_url     = (string) get_post_meta($event_id, '_pge_map_url', true);        // رابط خرائط (اختياري)
$cover_id    = (int) get_post_meta($event_id, '_pge_cover_id', true);          // attachment id (اختياري)

$cover_url = $cover_id ? wp_get_attachment_image_url($cover_id, 'full') : '';

$title = get_the_title($event_id);

// تاريخ/وقت لعد تنازلي (JS)
$iso_datetime = '';
if ($event_date) {
    $iso_datetime = $event_date . 'T' . ($event_time ? $event_time : '20:30') . ':00';
}
?>
<header class="relative overflow-hidden">
    <div class="absolute inset-0 -z-10 bg-gradient-to-b from-indigo-50/70 via-white to-slate-50"></div>
    <div class="pointer-events-none absolute -top-28 start-[-10rem] h-96 w-96 rounded-full bg-indigo-500/15 blur-3xl"></div>
    <div class="pointer-events-none absolute top-28 end-[-10rem] h-96 w-96 rounded-full bg-slate-900/10 blur-3xl"></div>

    <div class="mx-auto max-w-6xl px-4 pt-10 pb-6 sm:px-6 lg:px-8 lg:pt-14">
        <div class="rounded-3xl border border-slate-200 bg-white/70 shadow-sm backdrop-blur">
            <div class="relative overflow-hidden rounded-3xl">
                <?php if ($cover_url): ?>
                    <img src="<?php echo esc_url($cover_url); ?>" alt="<?php echo esc_attr($title); ?>" class="h-56 w-full object-cover sm:h-72">
                <?php else: ?>
                    <div class="h-56 w-full bg-slate-200 sm:h-72"></div>
                <?php endif; ?>

                <div class="absolute inset-0 bg-gradient-to-t from-slate-950/60 via-slate-950/20 to-transparent"></div>

                <div class="absolute bottom-0 w-full p-5 sm:p-7">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">

                        <div>
                            <div class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/20">
                                <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                                دعوة خاصة
                                <span class="text-white/60">•</span>
                                دخول عبر QR
                            </div>

                            <h1 class="mt-3 text-2xl font-extrabold tracking-tight text-white sm:text-3xl lg:text-4xl">
                                <?php echo esc_html($title); ?>
                            </h1>

                            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-white/85">
                                <?php if ($event_date): ?>
                                    <span class="rounded-full bg-white/15 px-3 py-1 ring-1 ring-white/20">
                                        <?php echo esc_html(date_i18n('j F Y', strtotime($event_date))); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($event_time): ?>
                                    <span class="rounded-full bg-white/15 px-3 py-1 ring-1 ring-white/20">
                                        <?php echo esc_html($event_time); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($event_place): ?>
                                    <span class="rounded-full bg-white/15 px-3 py-1 ring-1 ring-white/20">
                                        <?php echo esc_html($event_place); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a href="#rsvp" class="inline-flex items-center justify-center rounded-2xl bg-white px-5 py-3 text-sm font-extrabold text-slate-950 shadow-sm hover:bg-slate-50">
                                تأكيد الحضور (RSVP)
                            </a>

                            <button type="button"
                                class="js-add-to-calendar inline-flex items-center justify-center rounded-2xl bg-white/15 px-5 py-3 text-sm font-semibold text-white ring-1 ring-white/20 hover:bg-white/20"
                                data-title="<?php echo esc_attr($title); ?>"
                                data-location="<?php echo esc_attr($event_place); ?>"
                                data-start="<?php echo esc_attr($iso_datetime); ?>">
                                إضافة للتقويم
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-5 sm:p-7">
                <div class="grid gap-4 lg:grid-cols-3">

                    <!-- Countdown -->
                    <section class="rounded-3xl border border-slate-200 bg-white p-5" aria-label="countdown">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-extrabold">الوقت المتبقي</div>
                            <span class="rounded-full bg-emerald-600 px-2 py-0.5 text-[11px] font-semibold text-white">مباشر</span>
                        </div>

                        <div class="mt-4 grid grid-cols-4 gap-2 text-center" data-countdown="<?php echo esc_attr($iso_datetime); ?>">
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div class="cd-days text-xl font-extrabold">—</div>
                                <div class="mt-1 text-[11px] text-slate-500">يوم</div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div class="cd-hours text-xl font-extrabold">—</div>
                                <div class="mt-1 text-[11px] text-slate-500">ساعة</div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div class="cd-mins text-xl font-extrabold">—</div>
                                <div class="mt-1 text-[11px] text-slate-500">دقيقة</div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div class="cd-secs text-xl font-extrabold">—</div>
                                <div class="mt-1 text-[11px] text-slate-500">ثانية</div>
                            </div>
                        </div>

                        <p class="mt-4 text-sm text-slate-600">
                            سيتم إرسال تذكير تلقائي قبل المناسبة (حسب إعدادات المضيف).
                        </p>
                    </section>

                    <!-- Location -->
                    <section class="rounded-3xl border border-slate-200 bg-white p-5" aria-label="location">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-extrabold">الموقع</div>
                            <?php if ($map_url): ?>
                                <a href="<?php echo esc_url($map_url); ?>" target="_blank" rel="noopener"
                                    class="rounded-2xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                                    فتح الخرائط
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="mt-3 text-sm text-slate-600">
                            <?php echo $event_place ? esc_html($event_place) : '—'; ?>
                        </div>

                        <div class="mt-4 h-28 rounded-2xl bg-slate-200 ring-1 ring-slate-200"></div>
                        <div class="mt-3 text-xs text-slate-500">
                            * يمكن للمضيف إخفاء الخريطة حسب الباقة/الإعدادات.
                        </div>
                    </section>

                    <!-- Quick actions -->
                    <section class="rounded-3xl border border-slate-200 bg-white p-5" aria-label="actions">
                        <div class="text-sm font-extrabold">إجراءات سريعة</div>
                        <div class="mt-4 grid gap-2">
                            <button type="button"
                                class="js-open-qr rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                                فتح QR للدخول
                            </button>

                            <button type="button"
                                class="js-share-wa rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                                مشاركة عبر واتساب
                            </button>

                            <button type="button"
                                class="js-copy-link rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                                نسخ رابط الدعوة
                            </button>
                        </div>

                        <div class="mt-4 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200">
                            <div class="text-sm font-bold text-amber-900">نصيحة</div>
                            <div class="mt-1 text-sm text-amber-900/80">
                                أكّد حضورك الآن لتسهيل التنظيم — ويمكن تعديل الرد لاحقًا.
                            </div>
                        </div>
                    </section>

                </div>
            </div>
        </div>
    </div>
</header>