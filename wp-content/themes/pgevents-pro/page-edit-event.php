<?php
defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    auth_redirect();
}

$event_id = (int) get_query_var('event_id');
$event_post = $event_id ? get_post($event_id) : null;

if (!$event_id || !$event_post || $event_post->post_type !== 'pge_event') {
    wp_safe_redirect(home_url('/dashboard/?tab=events'));
    exit;
}

$can_edit = current_user_can('administrator')
    || ((int) $event_post->post_author === get_current_user_id())
    || current_user_can('edit_post', $event_id);

if (!$can_edit) {
    wp_safe_redirect(home_url('/dashboard/?tab=events'));
    exit;
}

$event_title = (string) $event_post->post_title;
$event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
$event_location = (string) get_post_meta($event_id, '_pge_event_location', true);
$event_address  = (string) get_post_meta($event_id, '_pge_event_address',  true);
$host_phone = (string) get_post_meta($event_id, '_pge_host_phone', true);

$event_date_input = '';
if ($event_date_raw !== '') {
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $event_date_raw)) {
        $event_date_input = $event_date_raw;
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date_raw)) {
        $event_date_input = $event_date_raw . 'T20:00';
    } else {
        $ts = strtotime(str_replace('T', ' ', $event_date_raw));
        if ($ts) {
            $event_date_input = date('Y-m-d\TH:i', $ts);
        }
    }
}

$dashboard_url = home_url('/dashboard/?tab=events&event=' . $event_id);
$event_url = get_permalink($event_id);
$featured_image_url = get_the_post_thumbnail_url($event_id, 'large');

get_header();
?>

<div class="relative min-h-screen overflow-hidden bg-background" dir="rtl">
    <div class="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-b from-primary/10 via-background to-secondary/40"></div>
    <div class="pointer-events-none absolute -top-20 start-[-7rem] h-80 w-80 rounded-full bg-primary/20 blur-3xl"></div>
    <div class="pointer-events-none absolute top-28 end-[-8rem] h-96 w-96 rounded-full bg-gold/20 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-28 start-1/3 h-72 w-72 rounded-full bg-primary/10 blur-3xl"></div>

    <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:py-16">

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
                    تعديل مباشر
                </div>
                <h1 class="mt-4 text-2xl font-extrabold leading-tight tracking-tight text-foreground sm:text-3xl">
                    تعديل المناسبة
                </h1>
                <p class="mx-auto mt-3 max-w-md text-[15px] leading-relaxed text-foreground/70">
                    قم بتحديث بيانات مناسبتك ثم احفظ التغييرات.
                </p>
            </div>
        </section>

        <!-- Section guide — visual only, no step/progress semantics (same as Create Event) -->
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

        <div class="mt-6 rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
            <div class="text-xs font-semibold text-foreground/65">رابط المناسبة</div>
            <a href="<?php echo esc_url($event_url); ?>" class="mt-1 block break-all text-sm font-semibold text-foreground underline decoration-border underline-offset-4">
                <?php echo esc_html($event_url); ?>
            </a>
        </div>

        <div id="editEventMsg" class="mt-6 hidden rounded-2xl p-4 text-sm font-semibold" role="status" aria-live="polite"></div>

        <form id="editEventForm" class="mt-6 space-y-6" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="post" enctype="multipart/form-data" novalidate>
            <?php wp_nonce_field('pge_edit_event_action', 'pge_event_nonce'); ?>
            <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />

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
                                value="<?php echo esc_attr($event_title); ?>"
                                class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-sm text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/65 focus:border-primary"
                                required />
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
                            value="<?php echo esc_attr($event_date_input); ?>"
                            class="mt-2 h-14 w-full rounded-2xl border border-border bg-white px-4 text-sm text-foreground outline-none transition-shadow duration-200 focus:border-primary"
                            required />
                    </div>

                    <div>
                        <label for="event_location" class="text-xs font-semibold text-foreground">رابط الموقع (Google Maps)</label>
                        <div class="relative mt-2">
                            <input
                                id="event_location"
                                name="event_location"
                                type="url"
                                dir="ltr"
                                value="<?php echo esc_attr($event_location); ?>"
                                class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-sm text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/65 focus:border-primary"
                                placeholder="https://maps.app.goo.gl/..."
                                required />
                            <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-foreground/35">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
                                    <circle cx="12" cy="10" r="3"/>
                                </svg>
                            </span>
                        </div>
                    </div>

                    <div>
                        <label for="event_address" class="text-xs font-semibold text-foreground">اسم القاعة / العنوان الكتابي</label>
                        <div class="relative mt-2">
                            <input
                                id="event_address"
                                name="event_address"
                                type="text"
                                value="<?php echo esc_attr($event_address); ?>"
                                class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-sm text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/65 focus:border-primary"
                                placeholder="مثال: قاعة الأفراح — شارع الملك فهد، الرياض" />
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
                            value="<?php echo esc_attr($host_phone); ?>"
                            class="h-14 w-full rounded-2xl border border-border bg-white ps-4 pe-12 text-sm text-foreground outline-none transition-shadow duration-200 placeholder:text-foreground/65 focus:border-primary"
                            placeholder="05XXXXXXXX"
                            required />
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
                        <?php if ($featured_image_url): ?>
                            <div class="mt-2 overflow-hidden rounded-2xl border border-border bg-secondary/60">
                                <img src="<?php echo esc_url($featured_image_url); ?>" alt="" class="h-44 w-full object-cover" />
                            </div>
                        <?php endif; ?>
                        <input
                            id="featured_image"
                            name="featured_image"
                            type="file"
                            accept="image/*"
                            class="mt-2 block w-full cursor-pointer rounded-2xl border border-border bg-white px-4 py-3 text-sm text-foreground/80 file:me-3 file:rounded-xl file:border-0 file:bg-primary file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:file:bg-primary-hover" />
                        <p class="mt-2 text-xs text-foreground/65">اختياري: ارفع صورة جديدة لاستبدال الصورة الحالية.</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    id="editEventSubmit"
                    type="submit"
                    class="group inline-flex h-14 flex-1 items-center justify-center gap-2 rounded-2xl bg-primary px-5 text-sm font-bold text-white shadow-sm shadow-primary/30 transition-colors duration-200 hover:bg-primary-hover">
                    حفظ التعديلات
                    <span aria-hidden="true" class="text-white/80 transition-transform duration-200 group-hover:-translate-x-0.5">←</span>
                </button>
                <a
                    href="<?php echo esc_url($dashboard_url); ?>"
                    class="inline-flex h-14 items-center justify-center rounded-2xl border-[1.5px] border-gold bg-white px-5 text-sm font-bold text-gold-text transition-colors duration-200 hover:bg-gold/[0.06]">
                    الرجوع للوحة التحكم
                </a>
            </div>
        </form>
    </main>
</div>

<script>
    const editEventForm = document.getElementById('editEventForm');
    const editEventSubmit = document.getElementById('editEventSubmit');
    const editEventMsg = document.getElementById('editEventMsg');
    const editDashboardUrl = <?php echo wp_json_encode($dashboard_url); ?>;
    function showEditEventMessage(type, text) {
        if (!editEventMsg) return;
        editEventMsg.classList.remove('hidden', 'bg-rose-50', 'text-rose-800', 'ring-rose-200', 'bg-emerald-50', 'text-emerald-800', 'ring-emerald-200', 'ring-1');
        if (type === 'success') {
            editEventMsg.classList.add('bg-emerald-50', 'text-emerald-800', 'ring-1', 'ring-emerald-200');
        } else {
            editEventMsg.classList.add('bg-rose-50', 'text-rose-800', 'ring-1', 'ring-rose-200');
        }
        editEventMsg.textContent = text;
    }

    if (editEventForm && editEventSubmit) {
        editEventForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            editEventSubmit.disabled = true;
            editEventSubmit.textContent = 'جاري الحفظ...';

            const formData = new FormData(editEventForm);
            formData.append('action', 'pge_handle_event_update');

            try {
                const response = await fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData
                });

                const json = await response.json();

                if (json && json.success) {
                    const successText = (json.data && typeof json.data === 'string') ? json.data : 'تم حفظ التعديلات بنجاح.';
                    showEditEventMessage('success', successText);
                    window.setTimeout(() => {
                        window.location.href = editDashboardUrl;
                    }, 700);
                    return;
                }

                const errorText = (json && json.data) ? json.data : 'تعذر حفظ التعديلات، حاول مرة أخرى.';
                showEditEventMessage('error', errorText);
            } catch (err) {
                showEditEventMessage('error', 'تعذر الاتصال بالخادم، حاول لاحقًا.');
            } finally {
                editEventSubmit.disabled = false;
                editEventSubmit.innerHTML = 'حفظ التعديلات <span class="text-white/80 transition group-hover:-translate-x-0.5">←</span>';
            }
        });
    }
</script>

<?php get_footer(); ?>
