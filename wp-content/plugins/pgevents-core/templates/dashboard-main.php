<?php

/**
 * Template: Host Dashboard (Modern)
 */
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
    auth_redirect();
}

get_header();

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

/**
 * Helpers
 */
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

/**
 * Load events
 */
$events_q = new WP_Query([
    'post_type'      => 'pge_event',
    'author'         => $user_id,
    'post_status'    => ['publish', 'private'],
    'posts_per_page' => -1,
    'meta_key'       => '_pge_event_date',
    'orderby'        => 'meta_value',
    'order'          => 'ASC',
]);

$events = $events_q->posts;

// Pick selected event
$selected_event_id = isset($_GET['event']) ? (int) $_GET['event'] : 0;
if (!$selected_event_id && !empty($events)) {
    $selected_event_id = (int) $events[0]->ID;
}

// Security: ensure selected event belongs to user
if ($selected_event_id) {
    $author_id = (int) get_post_field('post_author', $selected_event_id);
    if ($author_id !== $user_id && !current_user_can('administrator')) {
        $selected_event_id = 0;
    }
}

/**
 * Stats for selected event
 */
$invited_phones = $selected_event_id ? pge_get_invited_phones($selected_event_id) : [];
$total_invited  = count($invited_phones);

// RSVP counts (we will store RSVP in post meta array for now: _pge_rsvp_map)
$rsvp_map = $selected_event_id ? (array) get_post_meta($selected_event_id, '_pge_rsvp_map', true) : [];
$yes_count = 0;
$no_count = 0;

foreach ($invited_phones as $ph) {
    $row = $rsvp_map[$ph] ?? null;
    if (is_array($row) && ($row['reply'] ?? '') === 'yes') $yes_count++;
    if (is_array($row) && ($row['reply'] ?? '') === 'no')  $no_count++;
}
$pending_count = max(0, $total_invited - ($yes_count + $no_count));

// Check-ins counts (meta array: _pge_checkins => [phone => timestamp])
$checkins = $selected_event_id ? (array) get_post_meta($selected_event_id, '_pge_checkins', true) : [];
$checkins_count = is_array($checkins) ? count($checkins) : 0;

// KPI across all events (simple)
$all_invited_total = 0;
$all_yes_total = 0;
$all_no_total = 0;
$all_checkins_total = 0;

foreach ($events as $ev) {
    $eid = (int) $ev->ID;
    $inv = pge_get_invited_phones($eid);
    $all_invited_total += count($inv);

    $map = (array) get_post_meta($eid, '_pge_rsvp_map', true);
    foreach ($inv as $ph) {
        $row = $map[$ph] ?? null;
        if (is_array($row) && ($row['reply'] ?? '') === 'yes') $all_yes_total++;
        if (is_array($row) && ($row['reply'] ?? '') === 'no')  $all_no_total++;
    }

    $cks = (array) get_post_meta($eid, '_pge_checkins', true);
    $all_checkins_total += is_array($cks) ? count($cks) : 0;
}

$selected_event_title = $selected_event_id ? get_the_title($selected_event_id) : '';
$selected_event_date = $selected_event_id ? (string) get_post_meta($selected_event_id, '_pge_event_date', true) : '';
$selected_response_rate = $total_invited > 0 ? (int) round((($yes_count + $no_count) / $total_invited) * 100) : 0;
$selected_attendance_rate = $total_invited > 0 ? (int) round(($yes_count / $total_invited) * 100) : 0;
$selected_checkin_rate = $yes_count > 0 ? (int) round(($checkins_count / $yes_count) * 100) : 0;

?>
<main class="min-h-screen bg-slate-50 text-slate-900" dir="rtl">

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
                <a href="<?php echo esc_url(home_url('/create-event/')); ?>"
                    class="group inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-slate-900 to-slate-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-slate-800 hover:to-slate-700">
                    إنشاء مناسبة جديدة
                    <span class="text-white/80 transition group-hover:translate-x-0.5">➜</span>
                </a>
            </div>
        </div>
    </header>

    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h1 class="text-xl font-extrabold">لوحة التحكم</h1>
                    <p class="mt-1 text-sm text-slate-600">إدارة المناسبات والمدعوين وعمليات الدخول من شاشة واحدة.</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="<?php echo esc_url(home_url('/create-event/')); ?>"
                        class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">إنشاء مناسبة</a>
                    <a href="<?php echo esc_url(home_url('/packages/')); ?>"
                        class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">الباقات</a>
                    <?php if ($selected_event_id): ?>
                        <a href="<?php echo esc_url(get_permalink($selected_event_id)); ?>"
                            class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">فتح الدعوة</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-4">
                <?php if (empty($events)): ?>
                    <div class="rounded-2xl bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-slate-200">
                        لا توجد مناسبات حتى الآن. أنشئ أول مناسبة للبدء.
                    </div>
                <?php else: ?>
                    <form method="get" id="dashboardEventForm" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <select id="dashboardEventSelect" name="event" class="h-11 rounded-2xl border border-slate-200 bg-white px-4 text-sm sm:w-72">
                            <?php foreach ($events as $ev):
                                $eid = (int) $ev->ID;
                                $date = (string) get_post_meta($eid, '_pge_event_date', true);
                            ?>
                                <option value="<?php echo $eid; ?>" <?php selected($eid, $selected_event_id); ?>>
                                    <?php echo esc_html(get_the_title($eid)); ?>
                                    <?php if ($date) echo ' — ' . esc_html(date_i18n('j F Y', strtotime($date))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="tab" id="dashboardTabField" value="overview" />
                        <button class="h-11 rounded-2xl bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800">
                            تطبيق
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="mt-4">
            <div class="flex flex-wrap gap-2 rounded-3xl border border-slate-200 bg-white p-3 shadow-sm">
                <button class="dashboard-tab-btn rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white" data-tab="overview">نظرة عامة</button>
                <button class="dashboard-tab-btn rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-tab="events">المناسبات</button>
                <button class="dashboard-tab-btn rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-tab="operations">العمليات</button>
                <button class="dashboard-tab-btn rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-tab="reports">التقارير</button>
            </div>
        </section>

        <!-- Top: Create + KPIs -->
        <div id="dashboardPanelOverview" class="dashboard-panel mt-4">
            <section class="grid gap-4 lg:grid-cols-12">
            <div class="lg:col-span-5">
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                        جاهز خلال دقائق
                    </div>
                    <h1 class="mt-4 text-2xl font-extrabold tracking-tight">أنشئ دعوتك القادمة بسرعة</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        اختر القالب، أضف التفاصيل، فعّل RSVP، ثم شارك الرابط أو QR.
                    </p>

                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        <a href="<?php echo esc_url(home_url('/create-event/')); ?>"
                            class="text-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                            إنشاء مناسبة
                        </a>
                        <a href="<?php echo esc_url(home_url('/packages/')); ?>"
                            class="text-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                            الباقات
                        </a>
                    </div>

                    <div class="mt-5 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <div class="text-xs font-semibold text-slate-500">نصيحة</div>
                        <div class="mt-1 text-sm text-slate-700">
                            أرسل الدعوة مبكرًا مع تذكير تلقائي قبل المناسبة بـ 24 ساعة لرفع نسبة الحضور.
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-7">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="text-xs font-semibold text-slate-500">إجمالي المدعوين</div>
                        <div class="mt-2 text-3xl font-extrabold"><?php echo (int) $all_invited_total; ?></div>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="text-xs font-semibold text-slate-500">حضور مؤكد</div>
                        <div class="mt-2 text-3xl font-extrabold text-emerald-700"><?php echo (int) $all_yes_total; ?></div>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="text-xs font-semibold text-slate-500">اعتذار</div>
                        <div class="mt-2 text-3xl font-extrabold text-rose-700"><?php echo (int) $all_no_total; ?></div>
                    </div>
                    <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="text-xs font-semibold text-slate-500">تسجيل دخول</div>
                        <div id="allCheckinsKpiTop" class="mt-2 text-3xl font-extrabold"><?php echo (int) $all_checkins_total; ?></div>
                    </div>
                </div>

                <div class="mt-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="text-sm font-extrabold">المناسبة النشطة</div>
                            <?php if ($selected_event_id): ?>
                                <div class="mt-1 text-sm font-semibold text-slate-900"><?php echo esc_html($selected_event_title); ?></div>
                                <div class="mt-1 text-xs text-slate-500">
                                    <?php echo $selected_event_date ? esc_html(date_i18n('j F Y', strtotime($selected_event_date))) : '—'; ?>
                                </div>
                            <?php else: ?>
                                <div class="mt-1 text-sm text-slate-600">اختر مناسبة من أعلى الصفحة.</div>
                            <?php endif; ?>
                        </div>

                        <?php if ($selected_event_id): ?>
                            <a href="<?php echo esc_url(get_permalink($selected_event_id)); ?>"
                                class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">فتح صفحة الدعوة</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($selected_event_id): ?>
                        <div class="mt-4 space-y-3">
                            <div>
                                <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
                                    <span>نسبة التفاعل مع الدعوة</span>
                                    <span id="selectedResponseRateText" class="font-extrabold text-slate-700"><?php echo (int) $selected_response_rate; ?>%</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div id="selectedResponseRateBar" class="h-full bg-indigo-500" style="width: <?php echo (int) $selected_response_rate; ?>%"></div>
                                </div>
                            </div>

                            <div>
                                <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
                                    <span>نسبة تأكيد الحضور</span>
                                    <span id="selectedAttendanceRateText" class="font-extrabold text-emerald-700"><?php echo (int) $selected_attendance_rate; ?>%</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div id="selectedAttendanceRateBar" class="h-full bg-emerald-500" style="width: <?php echo (int) $selected_attendance_rate; ?>%"></div>
                                </div>
                            </div>

                            <div>
                                <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
                                    <span>نسبة Check-in من المؤكدين</span>
                                    <span id="selectedCheckinRateText" class="font-extrabold text-slate-700"><?php echo (int) $selected_checkin_rate; ?>%</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div id="selectedCheckinRateBar" class="h-full bg-slate-900" style="width: <?php echo (int) $selected_checkin_rate; ?>%"></div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-4 gap-2 text-center">
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div id="selectedInvitedCount" class="text-lg font-extrabold"><?php echo (int) $total_invited; ?></div>
                                <div class="mt-1 text-[11px] text-slate-500">مدعو</div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div id="selectedYesCount" class="text-lg font-extrabold text-emerald-700"><?php echo (int) $yes_count; ?></div>
                                <div class="mt-1 text-[11px] text-slate-500">سيحضر</div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div id="selectedNoCount" class="text-lg font-extrabold text-rose-700"><?php echo (int) $no_count; ?></div>
                                <div class="mt-1 text-[11px] text-slate-500">اعتذر</div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div id="selectedCheckinsCount" class="text-lg font-extrabold"><?php echo (int) $checkins_count; ?></div>
                                <div class="mt-1 text-[11px] text-slate-500">Check-in</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            </section>
        </div>

        <!-- Events list -->
        <div id="dashboardPanelEvents" class="dashboard-panel mt-4 hidden">
            <section>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-extrabold">مناسباتي</h2>
                    <p class="mt-1 text-sm text-slate-600">عرض وفتح المناسبات</p>
                </div>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                <?php if (empty($events)): ?>
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-600">
                        لا توجد مناسبات بعد — أنشئ أول مناسبة الآن.
                    </div>
                    <?php else: foreach ($events as $ev):
                        $eid = (int) $ev->ID;
                        $date = (string) get_post_meta($eid, '_pge_event_date', true);
                        $is_archived = (get_post_status($eid) === 'private');
                        $status = $is_archived ? 'past' : (($date && strtotime($date) >= strtotime('today')) ? 'upcoming' : 'active');
                        $badge = $is_archived ? ['سابقة', 'bg-slate-100', 'text-slate-700', 'ring-slate-200'] : ($status === 'upcoming' ? ['قادمة', 'bg-indigo-50', 'text-indigo-700', 'ring-indigo-200'] :
                            ['حالياً', 'bg-emerald-50', 'text-emerald-700', 'ring-emerald-200']);

                        $inv = pge_get_invited_phones($eid);
                        $map = (array) get_post_meta($eid, '_pge_rsvp_map', true);
                        $yes = 0;
                        $no = 0;
                        foreach ($inv as $ph) {
                            $row = $map[$ph] ?? null;
                            if (is_array($row) && ($row['reply'] ?? '') === 'yes') $yes++;
                            if (is_array($row) && ($row['reply'] ?? '') === 'no') $no++;
                        }
                        $cks = (array) get_post_meta($eid, '_pge_checkins', true);
                        $ckc = is_array($cks) ? count($cks) : 0;
                    ?>
                        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="inline-flex items-center gap-2 rounded-full <?php echo esc_attr($badge[1]); ?> px-3 py-1 text-xs font-semibold <?php echo esc_attr($badge[2]); ?> ring-1 <?php echo esc_attr($badge[3]); ?>">
                                        <?php echo esc_html($badge[0]); ?>
                                    </div>
                                    <div class="mt-3 text-lg font-extrabold"><?php echo esc_html(get_the_title($eid)); ?></div>
                                    <div class="mt-1 text-sm text-slate-600">
                                        <?php echo $date ? esc_html(date_i18n('j F Y', strtotime($date))) : '—'; ?>
                                    </div>
                                </div>
                                <div class="h-10 w-10 rounded-2xl bg-slate-100 ring-1 ring-slate-200"></div>
                            </div>

                            <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                                <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                    <div class="text-lg font-extrabold"><?php echo (int) count($inv); ?></div>
                                    <div class="mt-1 text-[11px] text-slate-500">مدعو</div>
                                </div>
                                <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                    <div class="text-lg font-extrabold text-emerald-700"><?php echo (int) $yes; ?></div>
                                    <div class="mt-1 text-[11px] text-slate-500">حضور</div>
                                </div>
                                <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                    <div class="text-lg font-extrabold"><?php echo (int) $ckc; ?></div>
                                    <div class="mt-1 text-[11px] text-slate-500">Check-in</div>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="<?php echo esc_url(get_permalink($eid)); ?>"
                                    class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">فتح</a>

                                <a href="<?php echo esc_url(add_query_arg(['event' => $eid, 'tab' => 'operations'], home_url('/dashboard/'))); ?>"
                                    class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">إدارة</a>

                                <?php if (!$is_archived): ?>
                                    <a href="<?php echo esc_url(home_url('/edit-event/' . $eid . '/')); ?>"
                                        class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">تعديل</a>
                                <?php endif; ?>
                            </div>
                        </div>
                <?php endforeach;
                endif; ?>
            </div>
            </section>
        </div>

        <!-- Guests + Check-in -->
        <div id="dashboardPanelOperations" class="dashboard-panel mt-4 hidden">
            <section class="grid gap-4 lg:grid-cols-12">
            <!-- Guests -->
            <div class="lg:col-span-7">
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-extrabold">المدعوين</h3>
                            <p class="mt-1 text-sm text-slate-600">بحث + فلترة (RSVP) — للمناسبة المختارة</p>
                        </div>
                    </div>

                    <?php if (!$selected_event_id): ?>
                        <div class="mt-4 rounded-2xl bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-slate-200">
                            اختر مناسبة من الأعلى لعرض المدعوين.
                        </div>
                    <?php else: ?>
                        <div class="mt-5 grid gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <input id="guestSearch"
                                    class="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                                    placeholder="ابحث برقم الجوال..." />
                            </div>
                            <div class="flex flex-wrap gap-2 sm:justify-end">
                                <button class="guest-filter rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white" data-status="all">الكل</button>
                                <button class="guest-filter rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-status="yes">سيحضر</button>
                                <button class="guest-filter rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-status="no">اعتذر</button>
                                <button class="guest-filter rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-status="pending">لم يرد</button>
                            </div>
                        </div>

                        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200">
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 text-slate-600">
                                        <tr>
                                            <th class="px-4 py-3 text-start font-semibold">الهاتف</th>
                                            <th class="px-4 py-3 text-start font-semibold">الحالة</th>
                                            <th class="px-4 py-3 text-start font-semibold">Check-in</th>
                                        </tr>
                                    </thead>
                                    <tbody id="guestTbody" class="divide-y divide-slate-100">
                                        <?php foreach ($invited_phones as $ph):
                                            $row = $rsvp_map[$ph] ?? null;
                                            $reply = is_array($row) ? ($row['reply'] ?? '') : '';
                                            $status = $reply === 'yes' ? 'yes' : ($reply === 'no' ? 'no' : 'pending');
                                            $checked = isset($checkins[$ph]) ? 'yes' : 'no';
                                        ?>
                                            <tr class="guest-row" data-status="<?php echo esc_attr($status); ?>" data-phone="<?php echo esc_attr($ph); ?>" data-checked="<?php echo esc_attr($checked); ?>">
                                                <td class="px-4 py-3 font-semibold text-slate-900"><?php echo esc_html($ph); ?></td>
                                                <td class="px-4 py-3">
                                                    <?php if ($status === 'yes'): ?>
                                                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">سيحضر</span>
                                                    <?php elseif ($status === 'no'): ?>
                                                        <span class="rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 ring-1 ring-rose-200">اعتذر</span>
                                                    <?php else: ?>
                                                        <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">لم يرد</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <?php if ($checked === 'yes'): ?>
                                                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-200">تم</span>
                                                    <?php else: ?>
                                                        <span class="text-slate-400">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Check-in -->
            <div class="lg:col-span-5">
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-extrabold">إدارة الدخول</h3>
                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-200">QR</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-600">أدخل رقم جوال الضيف لتسجيل دخوله (Check-in).</p>

                    <?php if (!$selected_event_id): ?>
                        <div class="mt-4 rounded-2xl bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-slate-200">
                            اختر مناسبة أولاً.
                        </div>
                    <?php else: ?>
                        <div class="mt-5 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <label class="text-xs font-semibold text-slate-600">رقم الهاتف</label>
                            <input id="checkinPhone"
                                class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                                placeholder="05xxxxxxxx" />
                            <button id="checkinBtn"
                                class="mt-3 w-full rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-500">
                                تسجيل دخول
                            </button>
                            <div id="checkinMsg" class="mt-3 text-sm text-slate-600"></div>
                        </div>

                        <script>
                            window.PGE_CHECKIN = {
                                ajax: "<?php echo esc_js(admin_url('admin-ajax.php')); ?>",
                                nonce: "<?php echo esc_js(wp_create_nonce('pge_checkin_nonce')); ?>",
                                event_id: "<?php echo (int) $selected_event_id; ?>"
                            };
                        </script>
                    <?php endif; ?>
                </div>
            </div>
            </section>
        </div>

        <div id="dashboardPanelReports" class="dashboard-panel mt-4 hidden">
            <section class="grid gap-4 lg:grid-cols-12">
                <div class="lg:col-span-7 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-extrabold">ملخص الأداء</h3>
                    <p class="mt-1 text-sm text-slate-600">قراءة سريعة لأداء كل المناسبات على مستوى الدعوات والحضور.</p>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <div class="text-xs font-semibold text-slate-500">إجمالي المدعوين</div>
                            <div class="mt-2 text-2xl font-extrabold"><?php echo (int) $all_invited_total; ?></div>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <div class="text-xs font-semibold text-slate-500">حضور مؤكد</div>
                            <div class="mt-2 text-2xl font-extrabold text-emerald-700"><?php echo (int) $all_yes_total; ?></div>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <div class="text-xs font-semibold text-slate-500">اعتذار</div>
                            <div class="mt-2 text-2xl font-extrabold text-rose-700"><?php echo (int) $all_no_total; ?></div>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <div class="text-xs font-semibold text-slate-500">Check-ins</div>
                            <div id="allCheckinsKpiReports" class="mt-2 text-2xl font-extrabold"><?php echo (int) $all_checkins_total; ?></div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-extrabold">إجراءات سريعة</h3>
                    <p class="mt-1 text-sm text-slate-600">انتقل مباشرة للإجراءات الأكثر استخدامًا.</p>

                    <div class="mt-5 space-y-2">
                        <a href="<?php echo esc_url(home_url('/create-event/')); ?>"
                            class="block rounded-2xl bg-slate-900 px-4 py-3 text-center text-sm font-semibold text-white hover:bg-slate-800">إنشاء مناسبة جديدة</a>
                        <a href="<?php echo esc_url(home_url('/dashboard/?tab=events')); ?>"
                            class="block rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center text-sm font-semibold text-slate-800 hover:bg-slate-50">عرض كل المناسبات</a>
                        <a href="<?php echo esc_url(home_url('/dashboard/?tab=operations')); ?>"
                            class="block rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center text-sm font-semibold text-slate-800 hover:bg-slate-50">إدارة المدعوين والدخول</a>
                    </div>
                </div>
            </section>
        </div>

    </div>

</main>

<script>
    const dashboardTabButtons = document.querySelectorAll('.dashboard-tab-btn');
    const dashboardPanels = document.querySelectorAll('.dashboard-panel');
    const dashboardTabField = document.getElementById('dashboardTabField');
    const dashboardEventForm = document.getElementById('dashboardEventForm');
    const dashboardEventSelect = document.getElementById('dashboardEventSelect');
    const dashboardTabs = ['overview', 'events', 'operations', 'reports'];
    const DASHBOARD_TAB_KEY = 'pge_dashboard_active_tab';
    const DASHBOARD_EVENT_KEY = 'pge_dashboard_selected_event';

    function getLocalStorageItem(key) {
        try {
            return window.localStorage ? window.localStorage.getItem(key) : null;
        } catch (e) {
            return null;
        }
    }

    function setLocalStorageItem(key, value) {
        try {
            if (window.localStorage) {
                window.localStorage.setItem(key, value);
            }
        } catch (e) {}
    }

    function activateDashboardTab(tab) {
        const activeTab = dashboardTabs.includes(tab) ? tab : 'overview';

        dashboardPanels.forEach(panel => {
            panel.classList.add('hidden');
        });

        const activePanel = document.getElementById(`dashboardPanel${activeTab.charAt(0).toUpperCase()}${activeTab.slice(1)}`);
        if (activePanel) activePanel.classList.remove('hidden');

        dashboardTabButtons.forEach(btn => {
            const isActive = btn.dataset.tab === activeTab;
            btn.classList.toggle('bg-slate-900', isActive);
            btn.classList.toggle('text-white', isActive);
            btn.classList.toggle('border', !isActive);
            btn.classList.toggle('border-slate-200', !isActive);
            btn.classList.toggle('bg-white', !isActive);
            btn.classList.toggle('text-slate-800', !isActive);
        });

        if (dashboardTabField) {
            dashboardTabField.value = activeTab;
        }

        setLocalStorageItem(DASHBOARD_TAB_KEY, activeTab);

        return activeTab;
    }

    dashboardTabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const nextTab = activateDashboardTab(btn.dataset.tab || 'overview');
            const url = new URL(window.location.href);
            url.searchParams.set('tab', nextTab);
            history.replaceState({}, '', url.toString());
        });
    });

    const urlParams = new URLSearchParams(window.location.search);
    const initialTab = urlParams.get('tab') || getLocalStorageItem(DASHBOARD_TAB_KEY) || 'overview';
    const activeInitialTab = activateDashboardTab(initialTab);

    if (dashboardEventSelect) {
        const hasEventInUrl = !!urlParams.get('event');
        const savedEventId = getLocalStorageItem(DASHBOARD_EVENT_KEY);
        const hasSavedOption = !!savedEventId && Array.from(dashboardEventSelect.options).some(option => option.value === savedEventId);

        if (!hasEventInUrl && hasSavedOption && dashboardEventSelect.value !== savedEventId) {
            dashboardEventSelect.value = savedEventId;
            const url = new URL(window.location.href);
            url.searchParams.set('event', savedEventId);
            url.searchParams.set('tab', activeInitialTab);
            window.location.replace(url.toString());
        } else {
            setLocalStorageItem(DASHBOARD_EVENT_KEY, dashboardEventSelect.value);
        }

        dashboardEventSelect.addEventListener('change', () => {
            setLocalStorageItem(DASHBOARD_EVENT_KEY, dashboardEventSelect.value || '');
        });
    }

    if (dashboardEventForm) {
        dashboardEventForm.addEventListener('submit', () => {
            if (dashboardTabField) {
                setLocalStorageItem(DASHBOARD_TAB_KEY, dashboardTabField.value || 'overview');
            }
            if (dashboardEventSelect) {
                setLocalStorageItem(DASHBOARD_EVENT_KEY, dashboardEventSelect.value || '');
            }
        });
    }

    // Guest search + status filter
    const guestFilters = document.querySelectorAll('.guest-filter');
    const guestRows = document.querySelectorAll('.guest-row');
    const guestSearch = document.getElementById('guestSearch');

    let activeGuestStatus = 'all';

    function applyGuestFilters() {
        if (!guestSearch) return;
        const q = (guestSearch.value || '').toLowerCase().trim();

        guestRows.forEach(row => {
            const status = row.dataset.status;
            const phone = (row.dataset.phone || '').toLowerCase();

            const matchStatus = (activeGuestStatus === 'all' || activeGuestStatus === status);
            const matchQuery = (!q || phone.includes(q));

            row.style.display = (matchStatus && matchQuery) ? '' : 'none';
        });
    }

    guestFilters.forEach(btn => {
        btn.addEventListener('click', () => {
            activeGuestStatus = btn.dataset.status;

            guestFilters.forEach(b => {
                b.classList.remove('bg-slate-900', 'text-white');
                b.classList.add('border', 'border-slate-200', 'bg-white', 'text-slate-800');
            });

            btn.classList.add('bg-slate-900', 'text-white');
            btn.classList.remove('border', 'border-slate-200', 'bg-white', 'text-slate-800');

            applyGuestFilters();
        });
    });

    if (guestSearch) guestSearch.addEventListener('input', applyGuestFilters);

    // Check-in AJAX
    const checkinBtn = document.getElementById('checkinBtn');
    const checkinPhone = document.getElementById('checkinPhone');
    const checkinMsg = document.getElementById('checkinMsg');
    const selectedYesCountEl = document.getElementById('selectedYesCount');
    const selectedCheckinsCountEl = document.getElementById('selectedCheckinsCount');
    const selectedCheckinRateTextEl = document.getElementById('selectedCheckinRateText');
    const selectedCheckinRateBarEl = document.getElementById('selectedCheckinRateBar');
    const allCheckinsKpiTopEl = document.getElementById('allCheckinsKpiTop');
    const allCheckinsKpiReportsEl = document.getElementById('allCheckinsKpiReports');

    function normalizePhone(v) {
        return (v || '').toString().replace(/\D+/g, '');
    }

    function readNumber(el) {
        if (!el) return 0;
        const parsed = parseInt((el.textContent || '0').replace(/[^\d-]/g, ''), 10);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function writeNumber(el, value) {
        if (!el) return;
        el.textContent = String(Math.max(0, value));
    }

    function incrementNumber(el, step = 1) {
        writeNumber(el, readNumber(el) + step);
    }

    function updateSelectedCheckinRate() {
        const confirmed = readNumber(selectedYesCountEl);
        const checkinsTotal = readNumber(selectedCheckinsCountEl);
        const rate = confirmed > 0 ? Math.round((checkinsTotal / confirmed) * 100) : 0;
        const clampedRate = Math.max(0, Math.min(100, rate));

        if (selectedCheckinRateTextEl) {
            selectedCheckinRateTextEl.textContent = `${clampedRate}%`;
        }
        if (selectedCheckinRateBarEl) {
            selectedCheckinRateBarEl.style.width = `${clampedRate}%`;
        }
    }

    function markGuestRowChecked(phoneDigits) {
        if (!phoneDigits) return {
            found: false,
            alreadyChecked: false
        };
        const row = document.querySelector(`.guest-row[data-phone="${phoneDigits}"]`);
        if (!row) return {
            found: false,
            alreadyChecked: false
        };

        const alreadyChecked = row.dataset.checked === 'yes';
        if (!alreadyChecked) {
            row.dataset.checked = 'yes';
            const checkinCell = row.querySelector('td:nth-child(3)');
            if (checkinCell) {
                checkinCell.innerHTML = '<span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-200">تم</span>';
            }
        }

        return {
            found: true,
            alreadyChecked
        };
    }

    if (checkinBtn && checkinPhone && window.PGE_CHECKIN) {
        checkinBtn.addEventListener('click', async () => {
            const rawPhone = checkinPhone.value || '';
            const normalizedPhone = normalizePhone(rawPhone);
            if (!normalizedPhone) {
                checkinMsg.textContent = 'أدخل رقم هاتف صحيح';
                return;
            }

            checkinMsg.textContent = '...جاري الحفظ';
            checkinBtn.disabled = true;
            const fd = new FormData();
            fd.append('action', 'pge_checkin_guest');
            fd.append('nonce', window.PGE_CHECKIN.nonce);
            fd.append('event_id', window.PGE_CHECKIN.event_id);
            fd.append('phone', rawPhone);

            try {
                const res = await fetch(window.PGE_CHECKIN.ajax, {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();

                if (json && json.success) {
                    const payload = (json.data && typeof json.data === 'object') ? json.data : {};
                    const alreadyOnServer = !!payload.already;
                    const serverMessage = payload.message || 'تم تسجيل الدخول';

                    if (alreadyOnServer) {
                        checkinMsg.textContent = serverMessage;
                    } else {
                        const rowState = markGuestRowChecked(normalizedPhone);
                        if (!rowState.alreadyChecked) {
                            incrementNumber(selectedCheckinsCountEl);
                            incrementNumber(allCheckinsKpiTopEl);
                            incrementNumber(allCheckinsKpiReportsEl);
                            updateSelectedCheckinRate();
                        }
                        checkinMsg.textContent = `✅ ${serverMessage}`;
                    }

                    checkinPhone.value = '';
                    checkinPhone.focus();
                } else {
                    const errorText = (json && typeof json.data === 'string') ? json.data : 'حدث خطأ';
                    checkinMsg.textContent = `❌ ${errorText}`;
                }
            } catch (e) {
                checkinMsg.textContent = '❌ تعذر الاتصال بالخادم';
            } finally {
                checkinBtn.disabled = false;
            }
        });
    }
</script>

<?php get_footer(); ?>
