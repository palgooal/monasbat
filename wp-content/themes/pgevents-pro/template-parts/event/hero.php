<?php
defined('ABSPATH') || exit;

$event_id = get_the_ID();

// --- Meta
$event_date_raw  = (string) get_post_meta($event_id, '_pge_event_date',     true);
$event_time      = (string) get_post_meta($event_id, '_pge_event_time',     true);
$event_address   = (string) get_post_meta($event_id, '_pge_event_address',  true); // اسم القاعة / العنوان
$map_url         = (string) get_post_meta($event_id, '_pge_event_location', true); // رابط Google Maps

// معرّف صورة الغلاف — نستخدم wp_get_attachment_image() لاحقاً بدل رابط ثابت
// واحد، لأنها تُنتج srcset/sizes تلقائياً (صور بدقّات متعددة يختارها المتصفح
// حسب عرض العمود الفعلي)، فلا نُجبر على تصغير صورة الدعوة الأصلية.
$cover_id = get_post_thumbnail_id($event_id);

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

<!-- ============================
     رأس الدعوة — شارة + عنوان المناسبة + سطر ترحيبي
============================ -->
<div class="relative overflow-hidden pt-4 pb-4 text-center lg:pt-0 lg:pb-3" dir="rtl">

    <!-- زخرفة زهرية خفيفة -->
    <svg aria-hidden="true" class="pointer-events-none absolute -top-8 start-1/2 h-40 w-40 -translate-x-1/2 text-gold opacity-[0.08] rtl:translate-x-1/2" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="1.4">
        <circle cx="100" cy="60" r="18"></circle>
        <circle cx="70" cy="90" r="18"></circle>
        <circle cx="130" cy="90" r="18"></circle>
        <circle cx="100" cy="110" r="18"></circle>
        <circle cx="100" cy="90" r="10"></circle>
    </svg>

    <div class="relative mx-auto max-w-md">
        <div class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-3.5 py-1.5 text-xs font-semibold text-primary-text ring-1 ring-primary/20">
            <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full bg-primary"></span>
            دعوة خاصة
        </div>

        <p class="mt-3 text-sm font-semibold text-foreground/60 sm:text-base">
            يسرنا دعوتكم
        </p>
        <h1 class="mt-1 text-3xl font-extrabold leading-tight text-foreground sm:text-4xl lg:text-5xl">
            <?php echo esc_html($title); ?>
        </h1>
        <p class="mt-2 text-sm leading-relaxed text-foreground/75 sm:text-base">
            نتشرف بحضوركم لهذه المناسبة الخاصة
        </p>
    </div>
</div>

<!-- ============================
     الدعوة نفسها — العمل الفني كما هو، بلا اقتصاص
     (ليست صورة بانر: هذه هي بطاقة الدعوة، تُعرَض كاملة بأبعادها الأصلية
      سواء كانت طولية/مربعة/عرضية — object-contain بلا أي قصّ. العرض
      يُحدَّده عمود الشبكة في single-pge_event.php وليس هذا الملف.)
============================ -->
<div class="w-full pb-2" dir="rtl">

    <div class="overflow-hidden rounded-[28px] border border-border/70 bg-white p-2.5 shadow-[0_16px_50px_-20px_rgba(45,25,20,0.14)] sm:p-4 lg:p-5">
        <?php if ($cover_id): ?>
            <?php
            echo wp_get_attachment_image(
                $cover_id,
                'full',
                false,
                [
                    'class' => 'mx-auto block w-full h-auto max-h-[800px] rounded-2xl object-contain',
                    'alt'   => $title,
                    // يعكس عرض عمود الدعوة الفعلي: عمود واسع (~680px) من lg فأعلى،
                    // وعرض الشاشة تقريباً على الجوال/التابلت (عمود واحد مكدَّس).
                    'sizes' => '(min-width: 1024px) 680px, 100vw',
                ]
            );
            ?>
        <?php else: ?>
            <div class="flex flex-col items-center justify-center rounded-2xl bg-gradient-to-b from-primary/90 to-primary-hover px-8 py-16 text-center">
                <span aria-hidden="true" class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/15 text-2xl ring-1 ring-white/25">🎉</span>
                <h2 class="mt-3 text-2xl font-extrabold text-white sm:text-3xl">
                    <?php echo esc_html($title); ?>
                </h2>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================
     معلومات المناسبة + العد التنازلي — قسم موحّد واحد أسفل الدعوة
============================ -->
<div class="w-full pb-2" dir="rtl">

    <div class="overflow-hidden rounded-[28px] border border-border/70 bg-white shadow-sm">

        <!-- التاريخ / الوقت / الموقع -->
        <div class="px-5 py-5">
            <div class="flex flex-wrap gap-2.5">

                <?php if ($display_date): ?>
                    <div class="flex items-center gap-2 rounded-2xl bg-secondary/60 px-4 py-2.5 ring-1 ring-border">
                        <span aria-hidden="true" class="text-base">📅</span>
                        <span class="text-sm font-semibold text-foreground"><?php echo esc_html($display_date); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($event_time): ?>
                    <div class="flex items-center gap-2 rounded-2xl bg-secondary/60 px-4 py-2.5 ring-1 ring-border">
                        <span aria-hidden="true" class="text-base">🕒</span>
                        <span class="text-sm font-semibold text-foreground"><?php echo esc_html($event_time); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($has_location): ?>
                    <div class="flex flex-1 items-center justify-between gap-2 rounded-2xl bg-secondary/60 px-4 py-2.5 ring-1 ring-border">
                        <div class="flex min-w-0 items-center gap-2">
                            <span aria-hidden="true" class="shrink-0 text-base">📍</span>
                            <span class="truncate text-sm font-semibold text-foreground">
                                <?php echo $event_address !== '' ? esc_html($event_address) : 'الموقع على الخريطة'; ?>
                            </span>
                        </div>
                        <?php if ($map_url): ?>
                            <a href="<?php echo esc_url($map_url); ?>"
                               target="_blank" rel="noopener"
                               class="flex h-11 shrink-0 items-center justify-center rounded-xl bg-foreground px-4 text-xs font-bold text-white hover:opacity-90">
                                الخريطة
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- العد التنازلي — امتداد لنفس البطاقة، بلا حدود/ظل منفصلين -->
        <?php if ($iso_datetime):
            // ── حالة المناسبة بناءً على التاريخ الحالي ──
            $event_ts  = strtotime(str_replace('T', ' ', $iso_datetime));
            $now       = time();
            $is_past   = $event_ts && $event_ts < $now;
            $is_today  = $event_ts && date('Y-m-d', $event_ts) === date('Y-m-d');
            $days_left = (!$is_past && $event_ts) ? (int) ceil(($event_ts - $now) / 86400) : 0;
        ?>
            <div class="border-t border-border/70">

            <?php if ($is_past): ?>
                <!-- ── المناسبة انتهت ── -->
                <div class="flex items-center justify-between px-5 py-4">
                    <div>
                        <div class="text-sm font-extrabold text-foreground">انتهت المناسبة</div>
                        <div class="mt-0.5 text-xs text-foreground/75">شكراً للحضور 🎉</div>
                    </div>
                    <span class="rounded-full bg-secondary px-3 py-1 text-[11px] font-bold text-foreground/75">مضت</span>
                </div>

            <?php else: ?>
                <!-- ── العد التنازلي ── -->
                <div class="flex items-center justify-between px-5 pt-4 pb-2">
                    <span class="text-sm font-extrabold text-foreground">
                        <?php echo $is_today ? 'اليوم! 🎉' : 'الوقت المتبقي'; ?>
                    </span>
                    <?php if ($is_today): ?>
                        <span class="animate-pulse rounded-full bg-destructive px-2.5 py-0.5 text-[11px] font-bold text-white">الآن 🔴</span>
                    <?php elseif ($days_left <= 3): ?>
                        <span class="rounded-full bg-gold px-2.5 py-0.5 text-[11px] font-bold text-white">قريباً ⚡</span>
                    <?php else: ?>
                        <span class="rounded-full bg-primary px-2.5 py-0.5 text-[11px] font-bold text-white"><?php echo (int)$days_left; ?> يوم</span>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-4 gap-2 px-5 pb-4"
                     data-countdown="<?php echo esc_attr($iso_datetime); ?>">
                    <div class="flex flex-col items-center rounded-2xl bg-secondary/60 py-3 ring-1 ring-border">
                        <span class="cd-days text-2xl font-extrabold text-foreground">—</span>
                        <span class="mt-1 text-[11px] text-foreground/75">يوم</span>
                    </div>
                    <div class="flex flex-col items-center rounded-2xl bg-secondary/60 py-3 ring-1 ring-border">
                        <span class="cd-hours text-2xl font-extrabold text-foreground">—</span>
                        <span class="mt-1 text-[11px] text-foreground/75">ساعة</span>
                    </div>
                    <div class="flex flex-col items-center rounded-2xl bg-secondary/60 py-3 ring-1 ring-border">
                        <span class="cd-mins text-2xl font-extrabold text-foreground">—</span>
                        <span class="mt-1 text-[11px] text-foreground/75">دقيقة</span>
                    </div>
                    <div class="flex flex-col items-center rounded-2xl bg-secondary/60 py-3 ring-1 ring-border">
                        <span class="cd-secs text-2xl font-extrabold text-foreground">—</span>
                        <span class="mt-1 text-[11px] text-foreground/75">ثانية</span>
                    </div>
                </div>
            <?php endif; ?>

            </div>
        <?php endif; ?>
    </div>

    <!-- أزرار الإجراءات -->
    <div class="mt-3 grid grid-cols-2 gap-3">
        <a href="#rsvp"
           class="flex h-14 items-center justify-center rounded-2xl bg-primary text-sm font-bold text-white shadow-md transition-colors hover:bg-primary-hover active:scale-[.98]">
            تأكيد الحضور ✅
        </a>

        <button type="button"
            class="js-add-to-calendar flex h-14 items-center justify-center rounded-2xl border border-border bg-white text-sm font-bold text-foreground hover:bg-secondary/40 active:scale-[.98]"
            data-title="<?php echo esc_attr($title); ?>"
            data-location="<?php echo esc_attr($event_address ?: 'الموقع على الخريطة'); ?>"
            data-start="<?php echo esc_attr($iso_datetime); ?>">
            📅 أضف للتقويم
        </button>
    </div>

</div>

<?php // السلوك (JS) موحَّد بالكامل في assets/js/event.js — لا سكربت مكرر هنا. ?>
