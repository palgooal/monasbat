<?php
defined('ABSPATH') || exit;

/**
 * Template Name: Host Dashboard
 */

if (!is_user_logged_in()) {
    wp_safe_redirect(wp_login_url(get_permalink()));
    exit;
}

get_header();

$user_id = get_current_user_id();

/**
 * Helpers
 */
if (!function_exists('pge_dash_get_events')) {
    function pge_dash_get_events($user_id)
    {
        return get_posts([
            'post_type'      => 'pge_event',
            'post_status'    => ['publish', 'private'],
            'posts_per_page' => 50,
            'author'         => $user_id,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
    }
}

if (!function_exists('pge_dash_event_stats')) {
    function pge_dash_event_stats($event_id)
    {
        // invited phones list
        $invited_raw = get_post_meta($event_id, '_pge_invited_phones', true);
        $invited = [];

        if (is_array($invited_raw)) {
            $invited = $invited_raw;
        } else {
            $raw = (string)$invited_raw;
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            $lines = array_filter(array_map('trim', explode("\n", $raw)));
            $invited = $lines;
        }

        $invited_count = 0;
        foreach ($invited as $p) {
            $p = preg_replace('/\D+/', '', (string)$p);
            if ($p !== '') $invited_count++;
        }

        // RSVP data map: [phone => {reply, ...}]
        $rsvp = get_post_meta($event_id, '_pge_rsvp_data', true);
        if (!is_array($rsvp)) $rsvp = [];

        $yes = 0;
        $no  = 0;
        foreach ($rsvp as $row) {
            $reply = is_array($row) ? ($row['reply'] ?? '') : '';
            if ($reply === 'yes') $yes++;
            if ($reply === 'no') $no++;
        }

        // Check-ins: [phone => timestamp]
        $checkins = get_post_meta($event_id, '_pge_checkins', true);
        if (!is_array($checkins)) $checkins = [];
        $checkins_count = count($checkins);

        return [
            'invited'   => $invited_count,
            'yes'       => $yes,
            'no'        => $no,
            'checkins'  => $checkins_count,
        ];
    }
}

$events = pge_dash_get_events($user_id);

// KPI totals across all events
$kpi_invited = 0;
$kpi_yes = 0;
$kpi_no = 0;
$kpi_checkins = 0;

foreach ($events as $ev) {
    $s = pge_dash_event_stats($ev->ID);
    $kpi_invited  += $s['invited'];
    $kpi_yes      += $s['yes'];
    $kpi_no       += $s['no'];
    $kpi_checkins += $s['checkins'];
}

?>

<main class="min-h-screen bg-slate-50 text-slate-900">

    <!-- Header -->
    <header class="sticky top-0 z-50 border-b bg-white/80 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                <div class="h-9 w-9 rounded-2xl bg-slate-900"></div>
                <div class="leading-tight">
                    <div class="text-sm font-extrabold">لوحة المضيف</div>
                    <div class="text-xs text-slate-500">إدارة المناسبات • المدعوين • الدعوات</div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="<?php echo esc_url(home_url('/profile/')); ?>"
                    class="hidden rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 sm:inline-flex">
                    إعدادات الحساب
                </a>
                <a href="<?php echo esc_url(home_url('/create-event/')); ?>"
                    class="group inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-slate-900 to-slate-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-slate-800 hover:to-slate-700">
                    إنشاء مناسبة جديدة
                    <span class="text-white/80 transition group-hover:translate-x-0.5">➜</span>
                </a>
            </div>
        </div>
    </header>

    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">

        <!-- Top: Quick actions + KPIs -->
        <section class="grid gap-4 lg:grid-cols-12">
            <!-- Create card -->
            <div id="create" class="lg:col-span-5">
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                جاهز خلال دقائق
                            </div>
                            <h1 class="mt-4 text-2xl font-extrabold tracking-tight">أنشئ دعوتك القادمة بسرعة</h1>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                اختر القالب، أضف التفاصيل، فعّل RSVP، ثم شارك الرابط أو QR.
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        <a href="<?php echo esc_url(home_url('/create-event/')); ?>"
                            class="text-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                            إنشاء مناسبة
                        </a>
                        <a href="<?php echo esc_url(home_url('/#templates')); ?>"
                            class="text-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                            استعراض القوالب
                        </a>
                    </div>

                    <div class="mt-5 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <div class="text-xs font-semibold text-slate-500">نصيحة تسويقية</div>
                        <div class="mt-1 text-sm text-slate-700">
                            أرسل الدعوة مبكرًا مع تذكير تلقائي قبل المناسبة بـ 24 ساعة لرفع نسبة الحضور.
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPIs -->
            <div class="lg:col-span-7">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="text-xs font-semibold text-slate-500">إجمالي المدعوين</div>
                        <div class="mt-2 text-3xl font-extrabold"><?php echo number_format_i18n($kpi_invited); ?></div>
                        <div class="mt-2 text-xs text-slate-500">لكل مناسباتك</div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="text-xs font-semibold text-slate-500">حضور مؤكد</div>
                        <div class="mt-2 text-3xl font-extrabold text-emerald-700"><?php echo number_format_i18n($kpi_yes); ?></div>
                        <div class="mt-2 text-xs text-slate-500">RSVP Yes</div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="text-xs font-semibold text-slate-500">اعتذار</div>
                        <div class="mt-2 text-3xl font-extrabold text-rose-700"><?php echo number_format_i18n($kpi_no); ?></div>
                        <div class="mt-2 text-xs text-slate-500">RSVP No</div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="text-xs font-semibold text-slate-500">تسجيل دخول QR</div>
                        <div class="mt-2 text-3xl font-extrabold"><?php echo number_format_i18n($kpi_checkins); ?></div>
                        <div class="mt-2 text-xs text-slate-500">Check-ins</div>
                    </div>
                </div>

                <!-- Quick toolbar -->
                <div class="mt-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="text-sm font-extrabold">إجراءات سريعة</div>
                            <div class="mt-1 text-sm text-slate-600">أرسل واتساب / تذكير / شكر — بضغطة</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button class="rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800" type="button">
                                إرسال دعوات واتساب
                            </button>
                            <button class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50" type="button">
                                إرسال تذكير
                            </button>
                            <button class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50" type="button">
                                رسالة شكر بعد المناسبة
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Events list -->
        <section class="mt-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-extrabold">مناسباتي</h2>
                    <p class="mt-1 text-sm text-slate-600">إدارة المناسبات الحالية والقادمة والسابقة</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button class="event-filter rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white" data-status="all">الكل</button>
                    <button class="event-filter rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-status="upcoming">قادمة</button>
                    <button class="event-filter rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-status="active">حالياً</button>
                    <button class="event-filter rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-status="past">سابقة</button>
                </div>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                <?php if (empty($events)) : ?>
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-600">
                        لا توجد مناسبات بعد. ابدأ بإنشاء مناسبة جديدة.
                    </div>
                <?php else : ?>
                    <?php foreach ($events as $ev) :
                        $event_id = $ev->ID;
                        $title = get_the_title($event_id);

                        $date = (string)get_post_meta($event_id, '_pge_event_date', true);
                        $time = (string)get_post_meta($event_id, '_pge_event_time', true);
                        $status = 'upcoming';

                        // تصنيف بسيط حسب التاريخ
                        $today = date('Y-m-d');
                        if ($date && $date < $today) $status = 'past';
                        if ($date && $date === $today) $status = 'active';

                        $s = pge_dash_event_stats($event_id);
                        $permalink = get_permalink($event_id);
                    ?>
                        <div class="event-card rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" data-status="<?php echo esc_attr($status); ?>">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1
                                        <?php
                                        echo $status === 'upcoming' ? 'bg-indigo-50 text-indigo-700 ring-indigo-200' : '';
                                        echo $status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : '';
                                        echo $status === 'past' ? 'bg-slate-100 text-slate-700 ring-slate-200' : '';
                                        ?>">
                                        <?php echo $status === 'upcoming' ? 'قادمة' : ($status === 'active' ? 'حالياً' : 'سابقة'); ?>
                                    </div>

                                    <div class="mt-3 text-lg font-extrabold"><?php echo esc_html($title); ?></div>
                                    <div class="mt-1 text-sm text-slate-600">
                                        <?php
                                        if ($date) echo esc_html(date_i18n('j F Y', strtotime($date)));
                                        if ($time) echo ' • ' . esc_html($time);
                                        ?>
                                    </div>
                                </div>
                                <div class="h-10 w-10 rounded-2xl bg-slate-100 ring-1 ring-slate-200"></div>
                            </div>

                            <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                                <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                    <div class="text-lg font-extrabold"><?php echo number_format_i18n($s['invited']); ?></div>
                                    <div class="mt-1 text-[11px] text-slate-500">مدعو</div>
                                </div>
                                <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                    <div class="text-lg font-extrabold text-emerald-700"><?php echo number_format_i18n($s['yes']); ?></div>
                                    <div class="mt-1 text-[11px] text-slate-500">حضور</div>
                                </div>
                                <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                    <div class="text-lg font-extrabold"><?php echo number_format_i18n($s['checkins']); ?></div>
                                    <div class="mt-1 text-[11px] text-slate-500">Check-in</div>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="<?php echo esc_url($permalink); ?>"
                                    class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                    فتح
                                </a>
                                <a href="<?php echo esc_url(add_query_arg(['edit_event' => $event_id], home_url('/create-event/'))); ?>"
                                    class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                                    تعديل
                                </a>
                                <button type="button"
                                    class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 js-share"
                                    data-link="<?php echo esc_attr($permalink); ?>">
                                    مشاركة
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Guests management + Check-in -->
        <section class="mt-8 grid gap-4 lg:grid-cols-12">
            <div class="lg:col-span-12">
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-extrabold">المدعوين (قريبًا)</h3>
                    <p class="mt-1 text-sm text-slate-600">
                        الخطوة التالية: ربط جدول المدعوين بحدث محدد + قراءة RSVP + تسجيل Check-in.
                    </p>

                    <div class="mt-4 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200 text-sm text-amber-900/90">
                        حالياً عرضنا “مناسباتي” و KPI الحقيقيين.
                        الآن نضيف اختيار مناسبة من Dropdown فوق جدول المدعوين لتظهر بياناتها.
                    </div>
                </div>
            </div>
        </section>

    </div>

    <footer class="border-t bg-white">
        <div class="mx-auto max-w-6xl px-4 py-10 text-center text-sm text-slate-500 sm:px-6 lg:px-8">
            © جميع الحقوق محفوظة — منصة مناسبات
        </div>
    </footer>

</main>

<script>
    // Filters (same logic)
    const eventFilters = document.querySelectorAll('.event-filter');
    const eventCards = document.querySelectorAll('.event-card');

    eventFilters.forEach(btn => {
        btn.addEventListener('click', () => {
            const status = btn.dataset.status;

            eventFilters.forEach(b => {
                b.classList.remove('bg-slate-900', 'text-white');
                b.classList.add('border', 'border-slate-200', 'bg-white', 'text-slate-800');
            });

            btn.classList.add('bg-slate-900', 'text-white');
            btn.classList.remove('border', 'border-slate-200', 'bg-white', 'text-slate-800');

            eventCards.forEach(card => {
                const s = card.dataset.status;
                card.style.display = (status === 'all' || status === s) ? '' : 'none';
            });
        });
    });

    // Share
    document.querySelectorAll('.js-share').forEach(btn => {
        btn.addEventListener('click', async () => {
            const link = btn.dataset.link;
            try {
                if (navigator.share) {
                    await navigator.share({
                        title: 'دعوة مناسبة',
                        url: link
                    });
                } else {
                    await navigator.clipboard.writeText(link);
                    alert('تم نسخ رابط الدعوة');
                }
            } catch (e) {}
        });
    });
</script>

<?php get_footer(); ?>