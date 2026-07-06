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
 * Helpers — تُحمَّل من helpers.php تلقائياً، هذا احتياطي فقط
 */
if (!function_exists('pge_norm_phone')) {
    function pge_norm_phone($v) { return preg_replace('/\D+/', '', trim((string)$v)); }
}
if (!function_exists('pge_get_invited_phones')) {
    function pge_get_invited_phones($event_id) {
        $raw = get_post_meta($event_id, '_pge_invited_phones', true);
        $phones = is_array($raw) ? $raw : array_filter(array_map('trim', explode("\n", str_replace(["\r\n","\r"],"\n",(string)$raw))));
        $out = [];
        foreach ($phones as $p) { $n = pge_norm_phone($p); if ($n !== '') $out[] = $n; }
        return array_values(array_unique($out));
    }
}

/**
 * Helper: جلب بيانات RSVP من الجدول الحقيقي
 * يُعيد: yes, no, checkins (أعداد) + map (phone=>reply) + checkin_map (phone=>true)
 */
function pge_dashboard_get_rsvp_stats(int $event_id): array {
    global $wpdb;
    $table = $wpdb->prefix . 'pge_event_rsvps';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT guest_phone, reply, checked_in FROM {$table} WHERE event_id = %d",
        $event_id
    ), ARRAY_A);

    $yes = $no = $checkins = 0;
    $map = [];
    $checkin_map = [];
    foreach ($rows as $r) {
        $map[$r['guest_phone']] = $r['reply'];
        if ($r['reply'] === 'yes') $yes++;
        if ($r['reply'] === 'no')  $no++;
        if ((int)$r['checked_in'] === 1) {
            $checkins++;
            $checkin_map[$r['guest_phone']] = true;
        }
    }
    return compact('yes', 'no', 'checkins', 'map', 'checkin_map');
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
 * Stats for selected event — من الجدول الحقيقي
 */
$invited_phones = $selected_event_id ? pge_get_invited_phones($selected_event_id) : [];
$total_invited  = count($invited_phones);

$rsvp_stats    = $selected_event_id ? pge_dashboard_get_rsvp_stats($selected_event_id) : ['yes'=>0,'no'=>0,'checkins'=>0,'map'=>[],'checkin_map'=>[]];
$yes_count     = $rsvp_stats['yes'];
$no_count      = $rsvp_stats['no'];
$checkins_count= $rsvp_stats['checkins'];
$rsvp_map      = $rsvp_stats['map'];         // phone => 'yes'|'no'
$checkin_map   = $rsvp_stats['checkin_map']; // phone => true (إذا سجّل دخول)
$pending_count = max(0, $total_invited - ($yes_count + $no_count));

// KPI across all events — من الجدول الحقيقي
$all_invited_total = 0;
$all_yes_total     = 0;
$all_no_total      = 0;
$all_checkins_total= 0;

foreach ($events as $ev) {
    $eid  = (int) $ev->ID;
    $all_invited_total += count(pge_get_invited_phones($eid));
    $ev_stats = pge_dashboard_get_rsvp_stats($eid);
    $all_yes_total      += $ev_stats['yes'];
    $all_no_total       += $ev_stats['no'];
    $all_checkins_total += $ev_stats['checkins'];
}

// معلومات الباقة
$plan_key    = (string) get_user_meta($user_id, '_mon_package_key', true);
$plan_name   = (string) get_user_meta($user_id, '_mon_package_name', true);
$plan_status = (string) get_user_meta($user_id, '_mon_package_status', true);
$events_limit= (int)   get_user_meta($user_id, '_mon_events_limit', true);
$events_used = (new WP_Query(['post_type'=>'pge_event','author'=>$user_id,'post_status'=>['publish','draft','pending'],'posts_per_page'=>-1,'fields'=>'ids']))->found_posts;
$events_left = max(0, $events_limit - $events_used);

// معلومات إضافية للعرض فقط (الباقة/الميزات) — قراءة فقط عبر الدوال المساعدة الحالية، بدون أي حساب جديد
$plan_limits           = function_exists('pge_get_user_plan_limits_for_events') ? pge_get_user_plan_limits_for_events($user_id) : [];
$guest_limit_per_event = isset($plan_limits['guest_limit']) ? (int) $plan_limits['guest_limit'] : 0;
$wa_messages_limit     = isset($plan_limits['wa_messages']) ? (int) $plan_limits['wa_messages'] : null;

$feature_labels = [
    'header_img'    => 'صورة غلاف مخصصة',
    'event_barcode' => 'باركود الدخول',
    'event_date'    => 'عرض تاريخ المناسبة',
    'countdown'     => 'العد التنازلي',
    'google_map'    => 'خرائط قوقل',
    'stc_pay'       => 'هدايا STC Pay',
    'guest_photos'  => 'صور من الضيوف',
    'guest_video'   => 'فيديو من الضيوف',
    'public_chat'   => 'الدردشة العامة',
    'private_chat'  => 'الدردشة الخاصة',
    'prev_events'   => 'عرض المناسبات السابقة',
    'next_events'   => 'عرض المناسبات القادمة',
    'guest_history' => 'سجل الضيوف',
    'archive'       => 'أرشفة المناسبة',
];
$active_feature_labels = [];
if (function_exists('pge_plan_feature_enabled_for_events')) {
    foreach ($feature_labels as $fkey => $flabel) {
        if (pge_plan_feature_enabled_for_events($plan_limits, $fkey)) {
            $active_feature_labels[] = $flabel;
        }
    }
}

$selected_event_title = $selected_event_id ? get_the_title($selected_event_id) : '';
$selected_event_date = $selected_event_id ? (string) get_post_meta($selected_event_id, '_pge_event_date', true) : '';
$selected_response_rate = $total_invited > 0 ? (int) round((($yes_count + $no_count) / $total_invited) * 100) : 0;
$selected_attendance_rate = $total_invited > 0 ? (int) round(($yes_count / $total_invited) * 100) : 0;
$selected_checkin_rate = $yes_count > 0 ? (int) round(($checkins_count / $yes_count) * 100) : 0;
$selected_manage_url = $selected_event_id ? home_url('/event-manage/' . $selected_event_id . '/') : home_url('/dashboard/?tab=operations');

$host_display_name = $current_user->display_name ?: $current_user->user_login;

?>
<main class="min-h-screen w-full max-w-full overflow-x-hidden bg-background font-arabic text-foreground" dir="rtl">

    <!-- App toolbar -->
    <header class="sticky top-0 z-50 border-b border-border bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
            <div class="flex min-w-0 items-center gap-3">
                <span aria-hidden="true" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                        <rect x="3" y="5" width="18" height="16" rx="3"></rect>
                        <path d="M3 10h18M8 3v4M16 3v4"></path>
                    </svg>
                </span>
                <div class="min-w-0 leading-tight">
                    <div class="truncate text-sm font-extrabold text-foreground">لوحة المضيف</div>
                    <div class="truncate text-xs text-foreground/65">إدارة المناسبات • المدعوين • الدعوات</div>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <a href="<?php echo esc_url(home_url('/create-event/')); ?>"
                    class="group inline-flex h-11 items-center gap-2 rounded-2xl bg-primary px-4 text-sm font-bold text-white transition-colors duration-200 hover:bg-primary-hover">
                    إنشاء مناسبة جديدة
                    <span aria-hidden="true" class="transition-transform duration-200 group-hover:-translate-x-0.5">➜</span>
                </a>
            </div>
        </div>
    </header>

    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">

        <!-- Welcome hero -->
        <section class="relative overflow-hidden rounded-[28px] border border-border bg-white p-6 shadow-[0_20px_60px_-15px_rgba(45,25,20,0.10)] sm:p-8">
            <svg aria-hidden="true" class="pointer-events-none absolute -top-10 -start-10 h-56 w-56 text-gold opacity-[0.06]" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="1.4">
                <path d="M10 190C40 150 30 90 70 60C100 38 130 45 150 20" stroke-linecap="round"/>
                <circle cx="70" cy="60" r="5"/>
                <circle cx="102" cy="46" r="4"/>
                <circle cx="132" cy="34" r="3.5"/>
                <path d="M70 60c10-6 18-4 24 4M102 46c8-5 16-3 21 4"/>
            </svg>
            <svg aria-hidden="true" class="pointer-events-none absolute -bottom-14 -end-14 h-64 w-64 rotate-180 text-gold opacity-[0.06]" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="1.4">
                <path d="M10 190C40 150 30 90 70 60C100 38 130 45 150 20" stroke-linecap="round"/>
                <circle cx="70" cy="60" r="5"/>
                <circle cx="102" cy="46" r="4"/>
                <circle cx="132" cy="34" r="3.5"/>
                <path d="M70 60c10-6 18-4 24 4M102 46c8-5 16-3 21 4"/>
            </svg>

            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <div class="inline-flex items-center gap-2 rounded-full bg-gold/10 px-3 py-1 text-xs font-bold text-gold-text ring-1 ring-gold/20">
                        أهلاً بك مجدداً
                    </div>
                    <h1 class="mt-3 text-2xl font-extrabold leading-tight tracking-tight text-foreground sm:text-3xl">
                        مرحباً، <?php echo esc_html($host_display_name); ?>
                    </h1>
                    <p class="mt-2 max-w-lg text-[15px] leading-relaxed text-foreground/70">
                        إدارة مناسباتك ودعواتك من مكان واحد
                    </p>
                </div>

                <div class="flex shrink-0 flex-wrap gap-3">
                    <a href="<?php echo esc_url(home_url('/create-event/')); ?>"
                        class="flex h-12 items-center justify-center gap-2 rounded-2xl bg-primary px-5 text-sm font-bold text-white transition-colors duration-200 hover:bg-primary-hover">
                        إنشاء مناسبة جديدة
                    </a>
                    <a href="<?php echo esc_url(home_url('/dashboard/?tab=events')); ?>"
                        class="dashboard-secondary-cta flex h-12 items-center justify-center gap-2 rounded-2xl border-[1.5px] border-gold bg-white px-5 text-sm font-bold text-gold-text transition-colors duration-200 hover:bg-gold/[0.06]">
                        إدارة المناسبات
                    </a>
                </div>
            </div>
        </section>

        <!-- Quick Actions: اختيار المناسبة النشطة للعمل عليها (منفصلة عن الترحيب) -->
        <section class="mt-6">
            <h2 class="px-1 text-sm font-extrabold text-foreground/70">إجراءات سريعة</h2>
            <div class="mt-3 rounded-3xl border border-border bg-white p-4 shadow-sm sm:p-5">
                <?php if (empty($events)): ?>
                    <div class="rounded-2xl bg-secondary/60 p-4 text-sm text-foreground/70 ring-1 ring-border">
                        لا توجد مناسبات حتى الآن. أنشئ أول مناسبة للبدء.
                    </div>
                <?php else: ?>
                    <form method="get" id="dashboardEventForm" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <label for="dashboardEventSelect" class="sr-only">اختر المناسبة</label>
                        <select id="dashboardEventSelect" name="event"
                            class="h-12 min-w-0 rounded-2xl border border-border bg-white px-4 text-sm text-foreground outline-none focus:border-primary sm:w-72">
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
                        <button class="h-12 rounded-2xl bg-foreground px-5 text-sm font-bold text-white transition-colors hover:bg-foreground/90">
                            تطبيق
                        </button>
                        <?php if ($selected_event_id): ?>
                            <a href="<?php echo esc_url(get_permalink($selected_event_id)); ?>"
                                class="flex h-12 items-center justify-center rounded-2xl border border-border bg-white px-5 text-sm font-bold text-foreground/80 transition-colors hover:bg-secondary/60">
                                فتح الدعوة
                            </a>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <!-- Package / quota summary -->
        <section class="mt-6">
            <h2 class="px-1 text-sm font-extrabold text-foreground/70">حالة الباقة والحصص</h2>
            <div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">

                <!-- الباقة الحالية -->
                <div class="min-w-0 rounded-3xl border border-border bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex items-center gap-2">
                        <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gold/10 text-gold">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-4.5 w-4.5">
                                <path d="M12 3l2.5 5.5L20 9l-4 4 1 6-5-3-5 3 1-6-4-4 5.5-.5L12 3Z"></path>
                            </svg>
                        </span>
                        <span class="text-xs font-bold text-foreground/70">الباقة الحالية</span>
                    </div>
                    <?php if ($plan_name || $plan_key): ?>
                        <div class="mt-3 truncate text-lg font-extrabold text-foreground"><?php echo esc_html($plan_name ?: $plan_key); ?></div>
                        <div class="mt-1.5">
                            <?php if ($plan_status === 'active'): ?>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700 ring-1 ring-emerald-200">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3 w-3"><path d="M20 6 9 17l-5-5"/></svg>
                                    نشطة
                                </span>
                            <?php elseif ($plan_status): ?>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-destructive/10 px-2.5 py-1 text-[11px] font-bold text-destructive-text ring-1 ring-destructive/20">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3 w-3"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                    <?php echo esc_html($plan_status); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="mt-3 text-sm font-bold text-foreground/70">لا توجد باقة نشطة</div>
                        <a href="<?php echo esc_url(home_url('/packages/')); ?>"
                            class="mt-2 inline-flex h-11 items-center rounded-xl bg-gold-text px-3 text-xs font-bold text-white transition-colors hover:bg-gold-text-hover">
                            تصفح الباقات
                        </a>
                    <?php endif; ?>
                </div>

                <!-- عدد المناسبات المتبقية -->
                <div class="min-w-0 rounded-3xl border border-border bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex items-center gap-2">
                        <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-4.5 w-4.5">
                                <rect x="3" y="5" width="18" height="16" rx="3"></rect>
                                <path d="M3 10h18M8 3v4M16 3v4"></path>
                            </svg>
                        </span>
                        <span class="text-xs font-bold text-foreground/70">المناسبات المتبقية</span>
                    </div>
                    <?php if ($events_limit > 0): ?>
                        <div class="mt-3 text-lg font-extrabold text-foreground">
                            <?php echo (int) $events_left; ?> <span class="text-sm font-semibold text-foreground/65">من <?php echo (int) $events_limit; ?></span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-secondary">
                            <div class="h-full bg-primary transition-all" style="width: <?php echo min(100, (int)round(($events_used/$events_limit)*100)); ?>%"></div>
                        </div>
                        <?php if ($events_left <= 0): ?>
                            <div class="mt-2 text-[11px] font-bold text-destructive-text">
                                استُنفد الحد —
                                <a href="<?php echo esc_url(home_url('/packages/')); ?>" class="underline">ترقية الباقة</a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="mt-3 text-sm font-semibold text-foreground/65">غير محدد</div>
                    <?php endif; ?>
                </div>

                <!-- عدد المدعوين المتاح -->
                <div class="min-w-0 rounded-3xl border border-border bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex items-center gap-2">
                        <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-4.5 w-4.5">
                                <circle cx="9" cy="8" r="3.2"></circle>
                                <path d="M2.5 20v-.8a5.8 5.8 0 0 1 5.8-5.8h1.4a5.8 5.8 0 0 1 5.8 5.8v.8"></path>
                                <circle cx="17.5" cy="8.5" r="2.4"></circle>
                                <path d="M15.8 13.6a4.6 4.6 0 0 1 5.7 4.5v.9"></path>
                            </svg>
                        </span>
                        <span class="text-xs font-bold text-foreground/70">المدعوين لكل مناسبة</span>
                    </div>
                    <?php if ($guest_limit_per_event > 0): ?>
                        <div class="mt-3 text-lg font-extrabold text-foreground"><?php echo (int) $guest_limit_per_event; ?></div>
                        <div class="mt-1 text-[11px] text-foreground/65">الحد الأقصى المسموح به</div>
                    <?php else: ?>
                        <div class="mt-3 text-sm font-semibold text-foreground/65">غير محدد</div>
                    <?php endif; ?>
                </div>

                <!-- رسائل واتساب المتاحة -->
                <div class="min-w-0 rounded-3xl border border-border bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex items-center gap-2">
                        <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-4.5 w-4.5">
                                <path d="M12 3a9 9 0 0 0-7.7 13.6L3 21l4.5-1.2A9 9 0 1 0 12 3Z"></path>
                            </svg>
                        </span>
                        <span class="text-xs font-bold text-foreground/70">رسائل واتساب المتاحة</span>
                    </div>
                    <?php if ($wa_messages_limit !== null && $wa_messages_limit > 0): ?>
                        <div class="mt-3 text-lg font-extrabold text-foreground"><?php echo (int) $wa_messages_limit; ?></div>
                        <div class="mt-1 text-[11px] text-foreground/65">رسالة ضمن باقتك</div>
                    <?php else: ?>
                        <div class="mt-3 text-sm font-semibold text-foreground/65">غير مفعّلة</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($active_feature_labels)): ?>
                <div class="mt-3 min-w-0 rounded-3xl border border-border bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex items-center gap-2">
                        <span aria-hidden="true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gold/10 text-gold">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-4.5 w-4.5">
                                <path d="M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8"></path>
                            </svg>
                        </span>
                        <span class="text-xs font-bold text-foreground/70">الميزات المفعّلة</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <?php foreach ($active_feature_labels as $flabel): ?>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-secondary/70 px-3 py-1.5 text-xs font-semibold text-foreground/80 ring-1 ring-border">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3 w-3 text-primary"><path d="M20 6 9 17l-5-5"/></svg>
                                <?php echo esc_html($flabel); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- Tabs -->
        <section class="mt-6">
            <div class="flex flex-wrap gap-2 rounded-3xl border border-border bg-white p-3 shadow-sm">
                <button class="dashboard-tab-btn flex h-11 items-center rounded-2xl bg-primary px-4 text-sm font-bold text-white" data-tab="overview">نظرة عامة</button>
                <button class="dashboard-tab-btn flex h-11 items-center rounded-2xl border border-border bg-white px-4 text-sm font-bold text-foreground/70 transition-colors hover:bg-secondary/50" data-tab="events">المناسبات</button>
                <button class="dashboard-tab-btn flex h-11 items-center rounded-2xl border border-border bg-white px-4 text-sm font-bold text-foreground/70 transition-colors hover:bg-secondary/50" data-tab="operations">العمليات</button>
                <button class="dashboard-tab-btn flex h-11 items-center rounded-2xl border border-border bg-white px-4 text-sm font-bold text-foreground/70 transition-colors hover:bg-secondary/50" data-tab="reports">التقارير</button>
            </div>
        </section>

        <!-- Overview -->
        <div id="dashboardPanelOverview" class="dashboard-panel mt-4">
            <section class="grid gap-4 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <div class="min-w-0 rounded-3xl border border-border bg-white p-6 shadow-sm">
                        <div class="text-sm font-extrabold text-foreground">نصيحة لرفع نسبة الحضور</div>
                        <p class="mt-2 text-sm leading-relaxed text-foreground/70">
                            أرسل الدعوة مبكراً مع تذكير تلقائي قبل المناسبة بـ 24 ساعة لرفع نسبة الحضور.
                        </p>

                        <div class="mt-5 grid grid-cols-4 gap-2 text-center">
                            <div class="min-w-0 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                                <div id="selectedInvitedCount" class="text-lg font-extrabold text-foreground"><?php echo (int) $total_invited; ?></div>
                                <div class="mt-1 text-[11px] text-foreground/65">مدعو</div>
                            </div>
                            <div class="min-w-0 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                                <div id="selectedYesCount" class="text-lg font-extrabold text-emerald-700"><?php echo (int) $yes_count; ?></div>
                                <div class="mt-1 text-[11px] text-foreground/65">سيحضر</div>
                            </div>
                            <div class="min-w-0 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                                <div id="selectedNoCount" class="text-lg font-extrabold text-destructive-text"><?php echo (int) $no_count; ?></div>
                                <div class="mt-1 text-[11px] text-foreground/65">اعتذر</div>
                            </div>
                            <div class="min-w-0 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                                <div id="selectedCheckinsCount" class="text-lg font-extrabold text-foreground"><?php echo (int) $checkins_count; ?></div>
                                <div class="mt-1 text-[11px] text-foreground/65">Check-in</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-7">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="min-w-0 rounded-3xl border border-border bg-white p-5 shadow-sm">
                            <div class="text-xs font-bold text-foreground/65">إجمالي المدعوين</div>
                            <div class="mt-2 text-3xl font-extrabold text-foreground"><?php echo (int) $all_invited_total; ?></div>
                        </div>
                        <div class="min-w-0 rounded-3xl border border-border bg-white p-5 shadow-sm">
                            <div class="text-xs font-bold text-foreground/65">حضور مؤكد</div>
                            <div class="mt-2 text-3xl font-extrabold text-emerald-700"><?php echo (int) $all_yes_total; ?></div>
                        </div>
                        <div class="min-w-0 rounded-3xl border border-border bg-white p-5 shadow-sm">
                            <div class="text-xs font-bold text-foreground/65">اعتذار</div>
                            <div class="mt-2 text-3xl font-extrabold text-destructive-text"><?php echo (int) $all_no_total; ?></div>
                        </div>
                        <div class="min-w-0 rounded-3xl border border-border bg-white p-5 shadow-sm">
                            <div class="text-xs font-bold text-foreground/65">تسجيل دخول</div>
                            <div id="allCheckinsKpiTop" class="mt-2 text-3xl font-extrabold text-foreground"><?php echo (int) $all_checkins_total; ?></div>
                        </div>
                    </div>

                    <div class="mt-4 min-w-0 rounded-3xl border border-border bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="text-sm font-extrabold text-foreground">المناسبة النشطة</div>
                                <?php if ($selected_event_id): ?>
                                    <div class="mt-1 truncate text-sm font-bold text-foreground"><?php echo esc_html($selected_event_title); ?></div>
                                    <div class="mt-1 text-xs text-foreground/65">
                                        <?php echo $selected_event_date ? esc_html(date_i18n('j F Y', strtotime($selected_event_date))) : '—'; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-1 text-sm text-foreground/70">اختر مناسبة من أعلى الصفحة.</div>
                                <?php endif; ?>
                            </div>

                            <?php if ($selected_event_id): ?>
                                <a href="<?php echo esc_url(get_permalink($selected_event_id)); ?>"
                                    class="flex h-11 shrink-0 items-center rounded-2xl border border-border bg-white px-4 text-sm font-bold text-foreground/80 transition-colors hover:bg-secondary/60">
                                    فتح صفحة الدعوة
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php if ($selected_event_id): ?>
                            <div class="mt-4 space-y-3">
                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs text-foreground/65">
                                        <span>نسبة التفاعل مع الدعوة</span>
                                        <span id="selectedResponseRateText" class="font-extrabold text-foreground/80"><?php echo (int) $selected_response_rate; ?>%</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-secondary">
                                        <div id="selectedResponseRateBar" class="h-full bg-primary" style="width: <?php echo (int) $selected_response_rate; ?>%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs text-foreground/65">
                                        <span>نسبة تأكيد الحضور</span>
                                        <span id="selectedAttendanceRateText" class="font-extrabold text-emerald-700"><?php echo (int) $selected_attendance_rate; ?>%</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-secondary">
                                        <div id="selectedAttendanceRateBar" class="h-full bg-emerald-500" style="width: <?php echo (int) $selected_attendance_rate; ?>%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="mb-1 flex items-center justify-between text-xs text-foreground/65">
                                        <span>نسبة Check-in من المؤكدين</span>
                                        <span id="selectedCheckinRateText" class="font-extrabold text-foreground/80"><?php echo (int) $selected_checkin_rate; ?>%</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-secondary">
                                        <div id="selectedCheckinRateBar" class="h-full bg-gold" style="width: <?php echo (int) $selected_checkin_rate; ?>%"></div>
                                    </div>
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
                        <h2 class="text-xl font-extrabold text-foreground">مناسباتي</h2>
                        <p class="mt-1 text-sm text-foreground/70">عرض وإدارة كل مناسباتك</p>
                    </div>
                </div>

                <?php if (empty($events)): ?>
                    <!-- Empty state -->
                    <div class="mt-4 flex flex-col items-center rounded-[28px] border border-border bg-white px-6 py-14 text-center">
                        <span aria-hidden="true" class="mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="h-9 w-9">
                                <rect x="3" y="6" width="18" height="15" rx="3"></rect>
                                <path d="M3 11h18M8 3v4M16 3v4"></path>
                                <path d="M12 15v3M10.5 16.5h3"></path>
                            </svg>
                        </span>
                        <h3 class="text-lg font-extrabold text-foreground">لم تنشئ أي مناسبة بعد</h3>
                        <p class="mt-2 max-w-sm text-sm leading-relaxed text-foreground/65">
                            ابدأ الآن وأنشئ دعوتك الأولى في دقائق، وشاركها مع ضيوفك بكل سهولة.
                        </p>
                        <a href="<?php echo esc_url(home_url('/create-event/')); ?>"
                            class="mt-6 flex h-12 items-center justify-center rounded-2xl bg-primary px-6 text-sm font-bold text-white transition-colors duration-200 hover:bg-primary-hover">
                            أنشئ مناسبتك الأولى
                        </a>
                    </div>
                <?php else: ?>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <?php foreach ($events as $ev):
                            $eid = (int) $ev->ID;
                            $date = (string) get_post_meta($eid, '_pge_event_date', true);
                            $address = (string) get_post_meta($eid, '_pge_event_address', true);
                            $is_archived = (get_post_status($eid) === 'private');
                            $status = $is_archived ? 'past' : (($date && strtotime($date) >= strtotime('today')) ? 'upcoming' : 'active');
                            $badge = $is_archived ? ['سابقة', 'bg-secondary/70', 'text-foreground/70', 'ring-border'] : ($status === 'upcoming' ? ['قادمة', 'bg-primary/10', 'text-primary-text', 'ring-primary/20'] :
                                ['حالياً', 'bg-emerald-50', 'text-emerald-700', 'ring-emerald-200']);

                            $inv       = pge_get_invited_phones($eid);
                            $ev_stats  = pge_dashboard_get_rsvp_stats($eid);
                            $yes       = $ev_stats['yes'];
                            $no        = $ev_stats['no'];
                            $ckc       = $ev_stats['checkins'];
                        ?>
                            <div class="flex min-w-0 flex-col rounded-[24px] border border-border bg-white p-5 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <span class="inline-flex items-center gap-1.5 rounded-full <?php echo esc_attr($badge[1]); ?> px-3 py-1 text-xs font-bold <?php echo esc_attr($badge[2]); ?> ring-1 <?php echo esc_attr($badge[3]); ?>">
                                            <?php echo esc_html($badge[0]); ?>
                                        </span>
                                        <div class="mt-3 truncate text-lg font-extrabold leading-snug text-foreground" title="<?php echo esc_attr(get_the_title($eid)); ?>">
                                            <?php echo esc_html(get_the_title($eid)); ?>
                                        </div>
                                        <div class="mt-1 flex items-center gap-1.5 text-sm text-foreground/70">
                                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5 shrink-0"><rect x="3" y="5" width="18" height="16" rx="3"></rect><path d="M3 10h18M8 3v4M16 3v4"></path></svg>
                                            <span class="truncate"><?php echo $date ? esc_html(date_i18n('j F Y', strtotime($date))) : '—'; ?></span>
                                        </div>
                                        <?php if ($address !== ''): ?>
                                            <div class="mt-1 flex items-center gap-1.5 text-sm text-foreground/70">
                                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5 shrink-0"><path d="M12 21s-7-4.35-7-10a7 7 0 0 1 14 0c0 5.65-7 10-7 10Z"></path><circle cx="12" cy="11" r="2.3"></circle></svg>
                                                <span class="truncate"><?php echo esc_html($address); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <span aria-hidden="true" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                            <circle cx="9" cy="8" r="3"></circle>
                                            <path d="M2.5 20v-.6a6 6 0 0 1 6-6h1a6 6 0 0 1 6 6v.6"></path>
                                        </svg>
                                    </span>
                                </div>

                                <div class="mt-4 grid grid-cols-4 gap-2 text-center">
                                    <div class="min-w-0 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                                        <div class="text-lg font-extrabold text-foreground"><?php echo (int) count($inv); ?></div>
                                        <div class="mt-1 text-[11px] text-foreground/65">مدعو</div>
                                    </div>
                                    <div class="min-w-0 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                                        <div class="text-lg font-extrabold text-emerald-700"><?php echo (int) $yes; ?></div>
                                        <div class="mt-1 text-[11px] text-foreground/65">حضور</div>
                                    </div>
                                    <div class="min-w-0 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                                        <div class="text-lg font-extrabold text-destructive-text"><?php echo (int) $no; ?></div>
                                        <div class="mt-1 text-[11px] text-foreground/65">اعتذر</div>
                                    </div>
                                    <div class="min-w-0 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                                        <div class="text-lg font-extrabold text-foreground"><?php echo (int) $ckc; ?></div>
                                        <div class="mt-1 text-[11px] text-foreground/65">Check-in</div>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <a href="<?php echo esc_url(home_url('/event-manage/' . $eid . '/')); ?>"
                                        class="flex h-11 items-center rounded-2xl bg-primary px-4 text-sm font-bold text-white transition-colors duration-200 hover:bg-primary-hover">
                                        إدارة المناسبة
                                    </a>

                                    <?php if (!$is_archived): ?>
                                        <a href="<?php echo esc_url(home_url('/edit-event/' . $eid . '/')); ?>"
                                            class="flex h-11 items-center rounded-2xl border border-border bg-white px-4 text-sm font-bold text-foreground/80 transition-colors hover:bg-secondary/60">
                                            تعديل
                                        </a>
                                    <?php endif; ?>

                                    <a href="<?php echo esc_url(get_permalink($eid)); ?>"
                                        class="flex h-11 items-center rounded-2xl border-[1.5px] border-gold bg-white px-4 text-sm font-bold text-gold-text transition-colors duration-200 hover:bg-gold/[0.06]">
                                        عرض الدعوة
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- Guests + Check-in -->
        <div id="dashboardPanelOperations" class="dashboard-panel mt-4 hidden">
            <section class="grid gap-4 lg:grid-cols-12">
                <!-- Guests -->
                <div class="lg:col-span-7">
                    <div class="min-w-0 rounded-3xl border border-border bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-lg font-extrabold text-foreground">المدعوين</h3>
                                <p class="mt-1 text-sm text-foreground/70">بحث + فلترة (RSVP) — للمناسبة المختارة</p>
                            </div>
                        </div>

                        <?php if (!$selected_event_id): ?>
                            <div class="mt-4 rounded-2xl bg-secondary/60 p-4 text-sm text-foreground/70 ring-1 ring-border">
                                اختر مناسبة من الأعلى لعرض المدعوين.
                            </div>
                        <?php else: ?>
                            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                                <div class="sm:col-span-2">
                                    <label for="guestSearch" class="sr-only">بحث برقم الجوال</label>
                                    <input id="guestSearch"
                                        class="h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm text-foreground outline-none placeholder:text-foreground/35 focus:border-primary"
                                        placeholder="ابحث برقم الجوال..." />
                                </div>
                                <div class="flex flex-wrap gap-2 sm:justify-end">
                                    <button class="guest-filter flex h-11 items-center rounded-2xl bg-primary px-4 text-sm font-bold text-white" data-status="all">الكل</button>
                                    <button class="guest-filter flex h-11 items-center rounded-2xl border border-border bg-white px-4 text-sm font-bold text-foreground/70 hover:bg-secondary/50" data-status="yes">سيحضر</button>
                                    <button class="guest-filter flex h-11 items-center rounded-2xl border border-border bg-white px-4 text-sm font-bold text-foreground/70 hover:bg-secondary/50" data-status="no">اعتذر</button>
                                    <button class="guest-filter flex h-11 items-center rounded-2xl border border-border bg-white px-4 text-sm font-bold text-foreground/70 hover:bg-secondary/50" data-status="pending">لم يرد</button>
                                </div>
                            </div>

                            <div class="mt-5 overflow-hidden rounded-2xl border border-border">
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-secondary/50 text-foreground/70">
                                            <tr>
                                                <th class="px-4 py-3 text-start font-bold">الهاتف</th>
                                                <th class="px-4 py-3 text-start font-bold">الحالة</th>
                                                <th class="px-4 py-3 text-start font-bold">Check-in</th>
                                            </tr>
                                        </thead>
                                        <tbody id="guestTbody" class="divide-y divide-border">
                                            <?php foreach ($invited_phones as $ph):
                                                $reply  = isset($rsvp_map[$ph]) ? (string) $rsvp_map[$ph] : '';
                                                $status = $reply === 'yes' ? 'yes' : ($reply === 'no' ? 'no' : 'pending');
                                                $checked = isset($checkin_map[$ph]) ? 'yes' : 'no';
                                            ?>
                                                <tr class="guest-row" data-status="<?php echo esc_attr($status); ?>" data-phone="<?php echo esc_attr($ph); ?>" data-checked="<?php echo esc_attr($checked); ?>">
                                                    <td class="px-4 py-3 font-bold text-foreground"><?php echo esc_html($ph); ?></td>
                                                    <td class="px-4 py-3">
                                                        <?php if ($status === 'yes'): ?>
                                                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200">سيحضر</span>
                                                        <?php elseif ($status === 'no'): ?>
                                                            <span class="rounded-full bg-destructive/10 px-3 py-1 text-xs font-bold text-destructive-text ring-1 ring-destructive/20">اعتذر</span>
                                                        <?php else: ?>
                                                            <span class="rounded-full bg-secondary/70 px-3 py-1 text-xs font-bold text-foreground/70 ring-1 ring-border">لم يرد</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <?php if ($checked === 'yes'): ?>
                                                            <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-bold text-primary-text ring-1 ring-primary/20">تم</span>
                                                        <?php else: ?>
                                                            <span class="text-foreground/65">—</span>
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
                    <div class="min-w-0 rounded-3xl border border-border bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-extrabold text-foreground">إدارة الدخول</h3>
                            <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-bold text-primary-text ring-1 ring-primary/20">QR</span>
                        </div>
                        <p class="mt-2 text-sm text-foreground/70">أدخل رقم جوال الضيف لتسجيل دخوله (Check-in).</p>

                        <?php if (!$selected_event_id): ?>
                            <div class="mt-4 rounded-2xl bg-secondary/60 p-4 text-sm text-foreground/70 ring-1 ring-border">
                                اختر مناسبة أولاً.
                            </div>
                        <?php else: ?>
                            <div class="mt-5 rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
                                <label for="checkinPhone" class="text-xs font-bold text-foreground/70">رقم الهاتف</label>
                                <input id="checkinPhone"
                                    class="mt-2 h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm text-foreground outline-none placeholder:text-foreground/35 focus:border-primary"
                                    placeholder="05xxxxxxxx" />
                                <button id="checkinBtn"
                                    class="mt-3 flex h-12 w-full items-center justify-center rounded-2xl bg-emerald-600 text-sm font-bold text-white transition-colors hover:bg-emerald-500">
                                    تسجيل دخول
                                </button>
                                <div id="checkinMsg" class="mt-3 text-sm text-foreground/70" role="status" aria-live="polite"></div>
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
                <div class="min-w-0 rounded-3xl border border-border bg-white p-6 shadow-sm lg:col-span-7">
                    <h3 class="text-lg font-extrabold text-foreground">ملخص الأداء</h3>
                    <p class="mt-1 text-sm text-foreground/70">قراءة سريعة لأداء كل المناسبات على مستوى الدعوات والحضور.</p>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="min-w-0 rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
                            <div class="text-xs font-bold text-foreground/65">إجمالي المدعوين</div>
                            <div class="mt-2 text-2xl font-extrabold text-foreground"><?php echo (int) $all_invited_total; ?></div>
                        </div>
                        <div class="min-w-0 rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
                            <div class="text-xs font-bold text-foreground/65">حضور مؤكد</div>
                            <div class="mt-2 text-2xl font-extrabold text-emerald-700"><?php echo (int) $all_yes_total; ?></div>
                        </div>
                        <div class="min-w-0 rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
                            <div class="text-xs font-bold text-foreground/65">اعتذار</div>
                            <div class="mt-2 text-2xl font-extrabold text-destructive-text"><?php echo (int) $all_no_total; ?></div>
                        </div>
                        <div class="min-w-0 rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
                            <div class="text-xs font-bold text-foreground/65">Check-ins</div>
                            <div id="allCheckinsKpiReports" class="mt-2 text-2xl font-extrabold text-foreground"><?php echo (int) $all_checkins_total; ?></div>
                        </div>
                    </div>
                </div>

                <div class="min-w-0 rounded-3xl border border-border bg-white p-6 shadow-sm lg:col-span-5">
                    <h3 class="text-lg font-extrabold text-foreground">إجراءات سريعة</h3>
                    <p class="mt-1 text-sm text-foreground/70">انتقل مباشرة للإجراءات الأكثر استخدامًا.</p>

                    <div class="mt-5 space-y-2">
                        <a href="<?php echo esc_url(home_url('/create-event/')); ?>"
                            class="flex h-12 items-center justify-center rounded-2xl bg-primary text-center text-sm font-bold text-white transition-colors duration-200 hover:bg-primary-hover">إنشاء مناسبة جديدة</a>
                        <a href="<?php echo esc_url(home_url('/dashboard/?tab=events')); ?>"
                            class="flex h-12 items-center justify-center rounded-2xl border border-border bg-white text-center text-sm font-bold text-foreground/80 transition-colors hover:bg-secondary/60">عرض كل المناسبات</a>
                        <a href="<?php echo esc_url($selected_manage_url); ?>"
                            class="flex h-12 items-center justify-center rounded-2xl border border-border bg-white text-center text-sm font-bold text-foreground/80 transition-colors hover:bg-secondary/60">إدارة المدعوين والدخول</a>
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
            btn.classList.toggle('bg-primary', isActive);
            btn.classList.toggle('text-white', isActive);
            btn.classList.toggle('border', !isActive);
            btn.classList.toggle('border-border', !isActive);
            btn.classList.toggle('bg-white', !isActive);
            btn.classList.toggle('text-foreground/70', !isActive);
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
                b.classList.remove('bg-primary', 'text-white');
                b.classList.add('border', 'border-border', 'bg-white', 'text-foreground/70');
            });

            btn.classList.add('bg-primary', 'text-white');
            btn.classList.remove('border', 'border-border', 'bg-white', 'text-foreground/70');

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
                checkinCell.innerHTML = '<span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-bold text-primary-text ring-1 ring-primary/20">تم</span>';
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
