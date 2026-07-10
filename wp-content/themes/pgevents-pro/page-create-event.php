<?php
defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    auth_redirect();
}

$user_id = get_current_user_id();
$user = wp_get_current_user();

$user_plan_key = (string) get_user_meta($user_id, '_mon_package_key', true);
if ($user_plan_key === '') {
    $user_plan_key = (string) get_user_meta($user_id, 'pge_current_plan', true);
}

$active_features = get_user_meta($user_id, '_mon_active_features', true);
$has_plan_context = ($user_plan_key !== '') || (is_array($active_features) && !empty($active_features));
$plan_limits = ($has_plan_context && class_exists('PGE_Packages')) ? (array) PGE_Packages::get_user_plan_limits($user_id) : [];

$feature_enabled = static function (array $limits, $key) {
    if (class_exists('PGE_Packages') && method_exists('PGE_Packages', 'is_feature_enabled')) {
        return PGE_Packages::is_feature_enabled($limits, $key);
    }

    $value = $limits[$key] ?? 0;
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return ((int) $value) === 1;
    $value = strtolower(trim((string) $value));
    return in_array($value, ['1', 'on', 'yes', 'true'], true);
};

$allowed_limit_meta = get_user_meta($user_id, '_mon_events_limit', true);

if ($allowed_limit_meta !== '') {
    $allowed_limit = (int) $allowed_limit_meta;
} else {
    $allowed_limit = $user_plan_key !== '' ? (int) ($plan_limits['events_count'] ?? 0) : 0;
}
$plan_name = (string) get_user_meta($user_id, '_mon_package_name', true);
if ($plan_name === '') {
    $plan_name = (string) ($has_plan_context ? ($plan_limits['name'] ?? 'الباقة الحالية') : 'بدون باقة');
}

$can_google_map = $feature_enabled($plan_limits, 'google_map');
$can_header_img = $feature_enabled($plan_limits, 'header_img');
$can_public_chat = $feature_enabled($plan_limits, 'public_chat');
$can_private_chat = $feature_enabled($plan_limits, 'private_chat');
$can_guest_photos = $feature_enabled($plan_limits, 'guest_photos');
$can_guest_video = $feature_enabled($plan_limits, 'guest_video');
$wa_limit = isset($plan_limits['wa_messages']) ? (int) $plan_limits['wa_messages'] : (int) get_user_meta($user_id, '_mon_wa_limit', true);

// نستثني 'private' لأنها مناسبات مؤرشفة ولا تُحسب في الحصة
$user_events_query = new WP_Query([
    'post_type'      => 'pge_event',
    'post_status'    => ['publish', 'draft', 'pending'],
    'author'         => $user_id,
    'posts_per_page' => -1,
    'fields'         => 'ids',
]);
$current_count = (int) $user_events_query->found_posts;
$remaining = max(0, $allowed_limit - $current_count);
$has_quota = $allowed_limit > 0 && $remaining > 0;

$saved_phone = (string) get_user_meta($user_id, 'pge_phone', true);
if ($saved_phone === '') {
    $saved_phone = (string) get_user_meta($user_id, 'billing_phone', true);
}
if ($saved_phone === '') {
    $saved_phone = (string) get_user_meta($user_id, 'phone_number', true);
}
$saved_phone = preg_replace('/\D+/', '', $saved_phone);

// معلومات إضافية للعرض فقط في الشريط الجانبي (قراءة فقط من نفس $plan_limits المحمّل أعلاه، بدون أي منطق جديد)
$guest_limit_display = isset($plan_limits['guest_limit']) ? (int) $plan_limits['guest_limit'] : null;

$active_badges = [];
if ($can_google_map)   $active_badges[] = 'خريطة Google';
if ($can_header_img)   $active_badges[] = 'صورة الهيدر';
if ($can_public_chat)  $active_badges[] = 'دردشة عامة';
if ($can_private_chat) $active_badges[] = 'دردشة خاصة';
if ($can_guest_photos) $active_badges[] = 'ألبوم صور';
if ($can_guest_video)  $active_badges[] = 'فيديو الضيوف';
if ($wa_limit > 0)     $active_badges[] = 'واتساب (' . $wa_limit . ')';

get_header();
?>

<div class="relative min-h-screen overflow-hidden bg-background" dir="rtl">
    <div class="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-b from-primary/10 via-background to-secondary/40"></div>
    <div class="pointer-events-none absolute -top-20 start-[-7rem] h-80 w-80 rounded-full bg-primary/20 blur-3xl"></div>
    <div class="pointer-events-none absolute top-28 end-[-8rem] h-96 w-96 rounded-full bg-gold/20 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-28 start-1/3 h-72 w-72 rounded-full bg-primary/10 blur-3xl"></div>

    <main class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:py-16">

        <!-- Hero -->
        <section class="relative overflow-hidden rounded-[28px] border border-border bg-white p-6 shadow-[0_20px_60px_-15px_rgba(45,25,20,0.10)] sm:p-10">
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

            <div class="relative mx-auto max-w-xl text-center">
                <div class="inline-flex items-center gap-2 rounded-full bg-gold/10 px-3 py-1 text-xs font-bold text-gold-text ring-1 ring-gold/20">
                    خطوة إنشاء مناسبة جديدة
                </div>
                <h1 class="mt-4 text-2xl font-extrabold leading-tight tracking-tight text-foreground sm:text-3xl">
                    أنشئ مناسبتك
                </h1>
                <p class="mx-auto mt-3 max-w-md text-[15px] leading-relaxed text-foreground/70">
                    ابدأ بإدخال المعلومات الأساسية، وسنساعدك على تجهيز دعواتك وإدارة حضور ضيوفك بكل سهولة.
                </p>
                <div class="mt-6">
                    <a href="#event-form-start"
                        class="group inline-flex h-14 items-center justify-center gap-2 rounded-2xl bg-primary px-7 text-sm font-bold text-white shadow-sm shadow-primary/30 transition-colors duration-200 hover:bg-primary-hover">
                        ابدأ الآن
                        <span aria-hidden="true" class="transition-transform duration-200 group-hover:-translate-x-0.5">←</span>
                    </a>
                </div>
            </div>
        </section>

        <!-- Section guide — honest reflection of the single-page form below.
             No numbers, no step/progress semantics, no "review" claim: this is a plain
             outline of the three sections that follow, not a wizard. -->
        <section class="mt-6 rounded-[28px] border border-border bg-white px-5 py-5 shadow-sm sm:px-8">
            <ul class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <li class="flex flex-1 items-center gap-3">
                    <span aria-hidden="true" class="h-2 w-2 shrink-0 rounded-full bg-primary"></span>
                    <span class="text-sm font-bold text-foreground">معلومات المناسبة</span>
                </li>
                <li aria-hidden="true" class="hidden h-px flex-1 bg-border sm:block"></li>
                <li class="flex flex-1 items-center gap-3">
                    <span aria-hidden="true" class="h-2 w-2 shrink-0 rounded-full bg-primary"></span>
                    <span class="text-sm font-bold text-foreground">معلومات المضيف</span>
                </li>
                <li aria-hidden="true" class="hidden h-px flex-1 bg-border sm:block"></li>
                <li class="flex flex-1 items-center gap-3">
                    <span aria-hidden="true" class="h-2 w-2 shrink-0 rounded-full bg-primary"></span>
                    <span class="text-sm font-bold text-foreground">خيارات إضافية</span>
                </li>
            </ul>
        </section>

        <?php if (!$has_quota): ?>
            <section class="mt-6 rounded-[28px] border border-destructive/20 bg-destructive/10 p-5 sm:p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <span aria-hidden="true" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-destructive-text ring-1 ring-destructive/20">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                            <path d="M12 9v4M12 17h.01"/>
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <div class="text-sm font-extrabold text-destructive-text">لا يمكنك إنشاء مناسبة جديدة الآن</div>
                        <p class="mt-1 text-sm leading-relaxed text-foreground/70">
                            لقد استهلكت الحد المتاح في باقتك الحالية (<?php echo esc_html((string) $allowed_limit); ?> مناسبة). يمكنك ترقية باقتك للحصول على عدد أكبر من المناسبات ومزايا إضافية.
                        </p>
                        <a href="<?php echo esc_url(home_url('/packages/')); ?>"
                            class="mt-3 inline-flex h-11 items-center rounded-xl border-[1.5px] border-gold bg-white px-4 text-xs font-bold text-gold-text transition-colors duration-200 hover:bg-gold/[0.06]">
                            ترقية الباقة
                        </a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <div id="createEventMsg" class="mt-6 hidden rounded-2xl p-4 text-sm font-semibold" role="status" aria-live="polite"></div>

        <div id="event-form-start" class="mt-6 scroll-mt-6 lg:flex lg:items-start lg:gap-8">
            <form id="createEventForm" class="min-w-0 flex-1 space-y-6" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('pge_create_event_action', 'pge_event_nonce'); ?>

                <!-- Card 1: معلومات المناسبة -->
                <div class="rounded-[28px] border border-border bg-white p-6 shadow-sm sm:p-8">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-4.5 w-4.5">
                                <path d="M12 3l2.5 5.5L20 9l-4 4 1 6-5-3-5 3 1-6-4-4 5.5-.5L12 3Z"></path>
                            </svg>
                        </span>
                        <h2 class="text-lg font-extrabold text-foreground">معلومات المناسبة</h2>
                    </div>

                    <div class="mt-5 space-y-4">
                        <div>
                            <label for="event_title" class="text-xs font-semibold text-foreground">اسم المناسبة</label>
                            <div class="relative mt-2">
                                <input
                                    id="event_title"
                                    name="event_title"
                                    type="text"
                                    class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-sm text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/65 focus:border-primary disabled:bg-secondary/60 disabled:text-foreground/40"
                                    placeholder="مثال: حفل زفاف أحمد"
                                    required
                                    <?php echo $has_quota ? '' : 'disabled'; ?> />
                                <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-foreground/35">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                        <path d="M12 2 9.5 8.5 3 9l5 4.6L6.5 20 12 16.3 17.5 20 16 13.6l5-4.6-6.5-.5L12 2Z"/>
                                    </svg>
                                </span>
                            </div>
                        </div>

                        <div>
                            <label for="event_date" class="text-xs font-semibold text-foreground">تاريخ ووقت المناسبة</label>
                            <input
                                id="event_date"
                                name="event_date"
                                type="datetime-local"
                                class="mt-2 h-14 w-full rounded-2xl border border-border bg-white px-4 text-sm text-foreground outline-none transition-shadow duration-200 focus:border-primary disabled:bg-secondary/60 disabled:text-foreground/40"
                                required
                                <?php echo $has_quota ? '' : 'disabled'; ?> />
                        </div>

                        <div>
                            <label for="event_location" class="text-xs font-semibold text-foreground">رابط الموقع (Google Maps)</label>
                            <div class="relative mt-2">
                                <input
                                    id="event_location"
                                    name="event_location"
                                    type="url"
                                    dir="ltr"
                                    class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-sm text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/65 focus:border-primary disabled:bg-secondary/60 disabled:text-foreground/40"
                                    placeholder="https://maps.app.goo.gl/..."
                                    <?php echo ($has_quota && $can_google_map) ? '' : 'disabled'; ?> />
                                <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-foreground/35">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
                                        <circle cx="12" cy="10" r="3"/>
                                    </svg>
                                </span>
                            </div>
                            <p class="mt-2 text-xs <?php echo $can_google_map ? 'text-foreground/65' : 'text-destructive-text'; ?>">
                                <?php echo $can_google_map ? 'هذه الميزة متاحة حسب باقتك.' : 'ميزة Google Map غير متاحة في باقتك الحالية.'; ?>
                            </p>
                        </div>

                        <div>
                            <label for="event_address" class="text-xs font-semibold text-foreground">اسم القاعة / العنوان الكتابي</label>
                            <div class="relative mt-2">
                                <input
                                    id="event_address"
                                    name="event_address"
                                    type="text"
                                    class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-sm text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/65 focus:border-primary disabled:bg-secondary/60 disabled:text-foreground/40"
                                    placeholder="مثال: قاعة الأفراح — شارع الملك فهد، الرياض"
                                    <?php echo $has_quota ? '' : 'disabled'; ?> />
                                <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-foreground/35">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                        <path d="M4 21V8l8-5 8 5v13"/>
                                        <path d="M9 21v-6h6v6"/>
                                    </svg>
                                </span>
                            </div>
                            <p class="mt-2 text-xs text-foreground/65">يُرسَل مع رابط الموقع في رسالة واتساب عند التأكيد.</p>
                        </div>
                    </div>
                </div>

                <!-- Card 2: معلومات المضيف -->
                <div class="rounded-[28px] border border-border bg-white p-6 shadow-sm sm:p-8">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-4.5 w-4.5">
                                <circle cx="12" cy="8" r="4"></circle>
                                <path d="M4 20a8 8 0 0 1 16 0"></path>
                            </svg>
                        </span>
                        <h2 class="text-lg font-extrabold text-foreground">معلومات المضيف</h2>
                    </div>

                    <div class="mt-5">
                        <label for="host_phone" class="text-xs font-semibold text-foreground">رقم جوال المضيف</label>
                        <div class="relative mt-2">
                            <input
                                id="host_phone"
                                name="host_phone"
                                type="tel"
                                inputmode="tel"
                                dir="ltr"
                                value="<?php echo esc_attr($saved_phone); ?>"
                                class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-sm text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/65 focus:border-primary disabled:bg-secondary/60 disabled:text-foreground/40"
                                placeholder="05XXXXXXXX"
                                required
                                <?php echo $has_quota ? '' : 'disabled'; ?> />
                            <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-foreground/35">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                    <rect x="6" y="2" width="12" height="20" rx="2.5"></rect>
                                    <path d="M10 18h4"></path>
                                </svg>
                            </span>
                        </div>
                        <p class="mt-2 text-xs text-foreground/65">يُستخدم للتواصل معك ولإرسال إشعارات المناسبة.</p>
                    </div>
                </div>

                <!-- Card 3: خيارات إضافية -->
                <div class="rounded-[28px] border border-border bg-white p-6 shadow-sm sm:p-8">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-4.5 w-4.5">
                                <rect x="3" y="3" width="18" height="18" rx="3"/>
                                <path d="M3 15l4.5-4.5a2 2 0 0 1 2.8 0L15 15M13 13l2-2a2 2 0 0 1 2.8 0L21 14"/>
                            </svg>
                        </span>
                        <h2 class="text-lg font-extrabold text-foreground">خيارات إضافية</h2>
                    </div>

                    <div class="mt-5 space-y-5">
                        <div>
                            <label for="featured_image" class="text-xs font-semibold text-foreground">الصورة البارزة</label>
                            <input
                                id="featured_image"
                                name="featured_image"
                                type="file"
                                accept="image/*"
                                class="mt-2 block w-full cursor-pointer rounded-2xl border border-border bg-white px-4 py-3 text-sm text-foreground/80 file:me-3 file:rounded-xl file:border-0 file:bg-primary file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:file:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-60"
                                <?php echo ($has_quota && $can_header_img) ? '' : 'disabled'; ?> />
                            <p class="mt-2 text-xs <?php echo $can_header_img ? 'text-foreground/65' : 'text-destructive-text'; ?>">
                                <?php echo $can_header_img ? 'اختياري: ستكون الصورة الرئيسية التي تظهر في المعاينة.' : 'ميزة صورة الهيدر غير متاحة في باقتك الحالية.'; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Secondary action stays inside the form for small/tablet flow; primary submit lives in the sidebar -->
                <div class="lg:hidden">
                    <a
                        href="<?php echo esc_url(home_url('/dashboard/?tab=events')); ?>"
                        class="inline-flex h-12 w-full items-center justify-center rounded-2xl border-[1.5px] border-gold bg-white px-5 text-sm font-bold text-gold-text transition-colors duration-200 hover:bg-gold/[0.06]">
                        العودة للوحة التحكم
                    </a>
                </div>
            </form>

            <!-- Sidebar summary (same form via createEventSubmit's form attribute) -->
            <aside class="mt-6 lg:mt-0 lg:w-[340px] lg:shrink-0">
                <div class="rounded-[28px] border border-border bg-white p-6 shadow-sm lg:sticky lg:top-6">
                    <h2 class="text-sm font-extrabold text-foreground/70">ملخص الباقة</h2>

                    <div class="mt-4 flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-foreground/65">الباقة الحالية</span>
                        <span class="truncate rounded-full bg-secondary/60 px-3 py-1 text-xs font-bold text-foreground ring-1 ring-border">
                            <?php echo esc_html($plan_name); ?>
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="min-w-0 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                            <div class="text-[11px] font-semibold text-foreground/65">المناسبات المتبقية</div>
                            <div class="mt-1 text-lg font-extrabold <?php echo $has_quota ? 'text-foreground' : 'text-destructive-text'; ?>">
                                <?php echo esc_html((string) $remaining); ?> <span class="text-xs font-semibold text-foreground/50">/ <?php echo esc_html((string) $allowed_limit); ?></span>
                            </div>
                        </div>
                        <div class="min-w-0 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                            <div class="text-[11px] font-semibold text-foreground/65">حد المدعوين</div>
                            <div class="mt-1 text-lg font-extrabold text-foreground">
                                <?php echo $guest_limit_display !== null ? esc_html((string) $guest_limit_display) : '—'; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($wa_limit > 0): ?>
                        <div class="mt-3 flex items-center justify-between gap-2 rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                            <span class="text-xs font-semibold text-foreground/65">رسائل واتساب المتاحة</span>
                            <span class="text-sm font-extrabold text-foreground"><?php echo esc_html((string) $wa_limit); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="mt-4">
                        <div class="text-xs font-semibold text-foreground/65">الميزات المفعّلة</div>
                        <?php if (!empty($active_badges)): ?>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <?php foreach ($active_badges as $badge): ?>
                                    <span class="rounded-full bg-gold/10 px-2.5 py-1 text-[11px] font-semibold text-foreground ring-1 ring-gold/20">
                                        <span aria-hidden="true" class="text-gold-text">✓</span> <?php echo esc_html($badge); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="mt-2 text-xs leading-relaxed text-foreground/65">
                                باقتك الأساسية لا تتضمن ميزات إضافية حالياً.
                                <a href="<?php echo esc_url(home_url('/packages/')); ?>" class="font-semibold text-gold-text underline underline-offset-4">ترقية للحصول على مزيد من المميزات</a>
                            </p>
                        <?php endif; ?>
                    </div>

                    <p class="mt-4 text-xs leading-relaxed text-foreground/65">
                        بعد الإنشاء، سيتم تجهيز صفحة دعوة خاصة بمناسبتك يمكنك مشاركتها فوراً مع ضيوفك.
                    </p>

                    <div class="mt-5 border-t border-gold/30"></div>

                    <button
                        id="createEventSubmit"
                        type="submit"
                        form="createEventForm"
                        <?php echo $has_quota ? '' : 'disabled'; ?>
                        class="group mt-5 inline-flex h-14 w-full items-center justify-center gap-2 rounded-2xl bg-primary px-5 text-sm font-bold text-white shadow-sm shadow-primary/30 transition-colors duration-200 hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-60">
                        إنشاء المناسبة
                        <span aria-hidden="true" class="text-white/80 transition-transform duration-200 group-hover:-translate-x-0.5">←</span>
                    </button>

                    <a
                        href="<?php echo esc_url(home_url('/dashboard/?tab=events')); ?>"
                        class="mt-2.5 hidden h-12 w-full items-center justify-center rounded-2xl border-[1.5px] border-gold bg-white px-5 text-sm font-bold text-gold-text transition-colors duration-200 hover:bg-gold/[0.06] lg:flex">
                        العودة للوحة التحكم
                    </a>
                </div>
            </aside>
        </div>
    </main>
</div>

<script>
    const createEventForm = document.getElementById('createEventForm');
    const createEventSubmit = document.getElementById('createEventSubmit');
    const createEventMsg = document.getElementById('createEventMsg');
    const eventDateInput = document.getElementById('event_date');

    if (eventDateInput && !eventDateInput.value) {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        eventDateInput.min = now.toISOString().slice(0, 16);
    }

    function showCreateEventMessage(type, text) {
        if (!createEventMsg) return;
        createEventMsg.classList.remove('hidden', 'bg-destructive/10', 'text-destructive', 'text-destructive-text', 'ring-destructive/20', 'bg-emerald-50', 'text-emerald-800', 'ring-emerald-200', 'ring-1');
        if (type === 'success') {
            createEventMsg.classList.add('bg-emerald-50', 'text-emerald-800', 'ring-1', 'ring-emerald-200');
        } else {
            createEventMsg.classList.add('bg-destructive/10', 'text-destructive-text', 'ring-1', 'ring-destructive/20');
        }
        createEventMsg.textContent = text;
    }

    if (createEventForm && createEventSubmit && !createEventSubmit.disabled) {
        createEventForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (!createEventForm.checkValidity()) {
                createEventForm.reportValidity();
                const firstInvalid = createEventForm.querySelector(':invalid');
                if (firstInvalid && typeof firstInvalid.focus === 'function') {
                    firstInvalid.focus();
                }
                return;
            }

            createEventSubmit.disabled = true;
            createEventSubmit.textContent = 'جاري الإنشاء...';

            const formData = new FormData(createEventForm);
            formData.append('action', 'pge_create_new_event');

            try {
                const response = await fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData
                });

                const json = await response.json();

                if (json && json.success && json.data && json.data.redirect_url) {
                    showCreateEventMessage('success', 'تم إنشاء المناسبة بنجاح، جاري التحويل...');
                    window.location.href = json.data.redirect_url;
                    return;
                }

                const errorText = (json && json.data) ? json.data : 'تعذر إنشاء المناسبة، حاول مرة أخرى.';
                showCreateEventMessage('error', errorText);
            } catch (err) {
                showCreateEventMessage('error', 'تعذر الاتصال بالخادم، حاول لاحقًا.');
            } finally {
                createEventSubmit.disabled = false;
                createEventSubmit.innerHTML = 'إنشاء المناسبة <span aria-hidden="true" class="text-white/80 transition-transform duration-200 group-hover:-translate-x-0.5">←</span>';
            }
        });
    }
</script>

<?php get_footer(); ?>
