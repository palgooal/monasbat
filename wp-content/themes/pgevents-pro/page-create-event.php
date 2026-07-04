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

get_header();
?>

<div class="relative min-h-screen overflow-hidden bg-background" dir="rtl">
    <div class="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-b from-primary/10 via-background to-secondary/40"></div>
    <div class="pointer-events-none absolute -top-20 start-[-7rem] h-80 w-80 rounded-full bg-primary/20 blur-3xl"></div>
    <div class="pointer-events-none absolute top-28 end-[-8rem] h-96 w-96 rounded-full bg-gold/20 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-28 start-1/3 h-72 w-72 rounded-full bg-primary/10 blur-3xl"></div>

    <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:py-16">
        <section>
            <div class="rounded-3xl border border-border bg-white p-6 shadow-sm shadow-primary/5 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-extrabold tracking-tight text-foreground">إنشاء مناسبة جديدة</h1>
                        <p class="mt-2 text-sm text-foreground/70">
                            أضف بيانات المناسبة، وبعد النشر سيتم إنشاء صفحة الدعوة مباشرة.
                        </p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-primary px-3 py-1 text-xs font-semibold text-white ring-1 ring-primary">
                        منشئ المناسبات
                    </span>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
                        <div class="text-xs font-semibold text-primary">حد الباقة</div>
                        <div class="mt-1 text-xl font-extrabold text-foreground"><?php echo esc_html((string) $allowed_limit); ?></div>
                    </div>
                    <div class="rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
                        <div class="text-xs font-semibold text-primary">المناسبات الحالية</div>
                        <div class="mt-1 text-xl font-extrabold text-foreground"><?php echo esc_html((string) $current_count); ?></div>
                    </div>
                    <div class="rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
                        <div class="text-xs font-semibold text-primary">المتبقي</div>
                        <div class="mt-1 text-xl font-extrabold <?php echo $has_quota ? 'text-emerald-700' : 'text-destructive'; ?>">
                            <?php echo esc_html((string) $remaining); ?>
                        </div>
                    </div>
                </div>

                <div class="mt-4 rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-xs font-semibold text-primary">مميزات الباقة المتاحة</div>
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-primary ring-1 ring-border">
                            <?php echo esc_html($plan_name); ?>
                        </span>
                    </div>

                    <?php
                    $active_badges = [];
                    if ($can_google_map)   $active_badges[] = 'خريطة Google';
                    if ($can_header_img)   $active_badges[] = 'صورة الهيدر';
                    if ($can_public_chat)  $active_badges[] = 'دردشة عامة';
                    if ($can_private_chat) $active_badges[] = 'دردشة خاصة';
                    if ($can_guest_photos) $active_badges[] = 'ألبوم صور';
                    if ($can_guest_video)  $active_badges[] = 'فيديو الضيوف';
                    if ($wa_limit > 0)     $active_badges[] = 'واتساب (' . $wa_limit . ')';
                    ?>

                    <?php if (!empty($active_badges)): ?>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <?php foreach ($active_badges as $badge): ?>
                                <span class="rounded-full bg-gold/15 px-3 py-1 text-[11px] font-semibold text-foreground ring-1 ring-gold/40">
                                    <span class="text-gold">✓</span> <?php echo esc_html($badge); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="mt-2 text-xs text-foreground/50">الباقة الأساسية — <a href="<?php echo esc_url(home_url('/packages/')); ?>" class="text-primary underline">ترقية للحصول على مزيد من المميزات</a></div>
                    <?php endif; ?>
                </div>

                <?php if (!$has_quota): ?>
                    <div class="mt-5 rounded-2xl bg-destructive/10 p-4 text-sm font-semibold text-destructive ring-1 ring-destructive/20">
                        لا يمكنك إنشاء مناسبة جديدة الآن لأن الحد المتاح في باقتك تم استهلاكه.
                        <a href="<?php echo esc_url(home_url('/packages/')); ?>" class="underline underline-offset-4">ترقية الباقة</a>
                    </div>
                <?php endif; ?>

                <div id="createEventMsg" class="mt-5 hidden rounded-2xl p-3 text-sm font-semibold"></div>

                <form id="createEventForm" class="mt-5 space-y-4" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="post" enctype="multipart/form-data" novalidate>
                    <?php wp_nonce_field('pge_create_event_action', 'pge_event_nonce'); ?>

                    <div>
                        <label for="event_title" class="text-xs font-semibold text-foreground">اسم المناسبة</label>
                        <input
                            id="event_title"
                            name="event_title"
                            type="text"
                            class="mt-2 h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm outline-none placeholder:text-foreground/35 focus:border-primary focus:ring-2 focus:ring-primary/10"
                            placeholder="مثال: حفل زفاف أحمد"
                            required
                            <?php echo $has_quota ? '' : 'disabled'; ?> />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="event_date" class="text-xs font-semibold text-foreground">تاريخ ووقت المناسبة</label>
                            <input
                                id="event_date"
                                name="event_date"
                                type="datetime-local"
                                class="mt-2 h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/10"
                                required
                                <?php echo $has_quota ? '' : 'disabled'; ?> />
                        </div>

                        <div>
                            <label for="host_phone" class="text-xs font-semibold text-foreground">رقم جوال المضيف</label>
                            <input
                                id="host_phone"
                                name="host_phone"
                                type="tel"
                                inputmode="tel"
                                dir="ltr"
                                value="<?php echo esc_attr($saved_phone); ?>"
                                class="mt-2 h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm outline-none placeholder:text-foreground/35 focus:border-primary focus:ring-2 focus:ring-primary/10"
                                placeholder="05XXXXXXXX"
                                required
                                <?php echo $has_quota ? '' : 'disabled'; ?> />
                        </div>
                    </div>

                    <div>
                        <label for="event_location" class="text-xs font-semibold text-foreground">رابط الموقع (Google Maps)</label>
                        <input
                            id="event_location"
                            name="event_location"
                            type="url"
                            dir="ltr"
                            class="mt-2 h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm outline-none placeholder:text-foreground/35 focus:border-primary focus:ring-2 focus:ring-primary/10"
                            placeholder="https://maps.app.goo.gl/..."
                            <?php echo ($has_quota && $can_google_map) ? '' : 'disabled'; ?> />
                        <p class="mt-2 text-xs <?php echo $can_google_map ? 'text-foreground/50' : 'text-destructive'; ?>">
                            <?php echo $can_google_map ? 'هذه الميزة متاحة حسب باقتك.' : 'ميزة Google Map غير متاحة في باقتك الحالية.'; ?>
                        </p>
                    </div>

                    <div>
                        <label for="event_address" class="text-xs font-semibold text-foreground">اسم القاعة / العنوان الكتابي</label>
                        <input
                            id="event_address"
                            name="event_address"
                            type="text"
                            class="mt-2 h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm outline-none placeholder:text-foreground/35 focus:border-primary focus:ring-2 focus:ring-primary/10"
                            placeholder="مثال: قاعة الأفراح — شارع الملك فهد، الرياض" />
                        <p class="mt-2 text-xs text-foreground/50">يُرسَل مع رابط الموقع في رسالة واتساب عند التأكيد.</p>
                    </div>

                    <div>
                        <label for="featured_image" class="text-xs font-semibold text-foreground">الصورة البارزة</label>
                        <input
                            id="featured_image"
                            name="featured_image"
                            type="file"
                            accept="image/*"
                            class="mt-2 block w-full cursor-pointer rounded-2xl border border-border bg-white px-4 py-3 text-sm text-foreground/80 file:me-3 file:rounded-xl file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-primary/90"
                            <?php echo ($has_quota && $can_header_img) ? '' : 'disabled'; ?> />
                        <p class="mt-2 text-xs <?php echo $can_header_img ? 'text-foreground/50' : 'text-destructive'; ?>">
                            <?php echo $can_header_img ? 'اختياري: ستكون الصورة الرئيسية التي تظهر في المعاينة.' : 'ميزة صورة الهيدر غير متاحة في باقتك الحالية.'; ?>
                        </p>
                    </div>

                    <details class="rounded-2xl border border-border bg-white">
                        <summary class="cursor-pointer px-4 py-3 text-xs font-semibold text-foreground select-none">
                            إعدادات متقدمة — رمز الدعوة
                        </summary>
                        <div class="border-t border-border px-4 pb-4 pt-3">
                            <label for="invite_code" class="text-xs font-semibold text-foreground">رمز الدعوة</label>
                            <div class="mt-2 flex gap-2">
                                <input
                                    id="invite_code"
                                    name="invite_code"
                                    type="text"
                                    dir="ltr"
                                    maxlength="9"
                                    class="h-12 w-full rounded-2xl border border-border bg-white px-4 text-sm font-semibold tracking-widest outline-none placeholder:text-foreground/35 focus:border-primary focus:ring-2 focus:ring-primary/10"
                                    placeholder="AB12-CD34"
                                    <?php echo $has_quota ? '' : 'disabled'; ?> />
                                <button
                                    id="generateInviteCodeBtn"
                                    type="button"
                                    <?php echo $has_quota ? '' : 'disabled'; ?>
                                    class="shrink-0 rounded-2xl border border-border bg-white px-4 py-3 text-sm font-semibold text-primary hover:bg-secondary/50 disabled:cursor-not-allowed disabled:opacity-60">
                                    توليد
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-foreground/50">اتركه فارغًا وسيتم توليده تلقائيًا. يستخدمه الضيوف للدخول لصفحة المناسبة.</p>
                        </div>
                    </details>

                    <div class="flex flex-wrap gap-2">
                        <button
                            id="createEventSubmit"
                            type="submit"
                            <?php echo $has_quota ? '' : 'disabled'; ?>
                            class="group inline-flex flex-1 items-center justify-center gap-2 rounded-2xl bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-primary/30 hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60">
                            نشر المناسبة
                            <span class="text-white/80 transition group-hover:-translate-x-0.5">←</span>
                        </button>
                        <a
                            href="<?php echo esc_url(home_url('/dashboard/?tab=events')); ?>"
                            class="inline-flex items-center justify-center rounded-2xl border border-border bg-white px-5 py-3 text-sm font-semibold text-foreground hover:bg-secondary/50">
                            العودة للوحة التحكم
                        </a>
                    </div>
                </form>
            </div>
        </section>
    </main>
</div>

<script>
    const createEventForm = document.getElementById('createEventForm');
    const createEventSubmit = document.getElementById('createEventSubmit');
    const createEventMsg = document.getElementById('createEventMsg');
    const eventDateInput = document.getElementById('event_date');
    const inviteCodeInput = document.getElementById('invite_code');
    const generateInviteCodeBtn = document.getElementById('generateInviteCodeBtn');

    if (eventDateInput && !eventDateInput.value) {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        eventDateInput.min = now.toISOString().slice(0, 16);
    }

    function normalizeInviteCode(value) {
        const cleaned = (value || '').toString().toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
        if (cleaned.length > 4) {
            return `${cleaned.slice(0, 4)}-${cleaned.slice(4)}`;
        }
        return cleaned;
    }

    function generateInviteCode() {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let out = '';
        for (let i = 0; i < 8; i += 1) {
            out += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return `${out.slice(0, 4)}-${out.slice(4)}`;
    }

    if (inviteCodeInput && !inviteCodeInput.disabled) {
        if (!inviteCodeInput.value) {
            inviteCodeInput.value = generateInviteCode();
        }

        inviteCodeInput.addEventListener('input', () => {
            inviteCodeInput.value = normalizeInviteCode(inviteCodeInput.value);
        });
    }

    if (generateInviteCodeBtn && inviteCodeInput && !generateInviteCodeBtn.disabled) {
        generateInviteCodeBtn.addEventListener('click', () => {
            inviteCodeInput.value = generateInviteCode();
            inviteCodeInput.focus();
            inviteCodeInput.select();
        });
    }

    function showCreateEventMessage(type, text) {
        if (!createEventMsg) return;
        createEventMsg.classList.remove('hidden', 'bg-destructive/10', 'text-destructive', 'ring-destructive/20', 'bg-emerald-50', 'text-emerald-800', 'ring-emerald-200', 'ring-1');
        if (type === 'success') {
            createEventMsg.classList.add('bg-emerald-50', 'text-emerald-800', 'ring-1', 'ring-emerald-200');
        } else {
            createEventMsg.classList.add('bg-destructive/10', 'text-destructive', 'ring-1', 'ring-destructive/20');
        }
        createEventMsg.textContent = text;
    }

    if (createEventForm && createEventSubmit && !createEventSubmit.disabled) {
        createEventForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            createEventSubmit.disabled = true;
            createEventSubmit.textContent = 'جاري الإنشاء...';

            if (inviteCodeInput) {
                inviteCodeInput.value = normalizeInviteCode(inviteCodeInput.value || '');
            }

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
                createEventSubmit.innerHTML = 'نشر المناسبة <span class="text-white/80 transition group-hover:-translate-x-0.5">←</span>';
            }
        });
    }
</script>

<?php get_footer(); ?>