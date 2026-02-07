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

get_header();
?>

<div class="relative min-h-screen overflow-hidden" dir="rtl">
    <div class="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-b from-indigo-50/70 via-white to-slate-50"></div>
    <div class="pointer-events-none absolute -top-20 start-[-7rem] h-80 w-80 rounded-full bg-indigo-500/15 blur-3xl"></div>
    <div class="pointer-events-none absolute top-28 end-[-8rem] h-96 w-96 rounded-full bg-slate-900/10 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-28 start-1/3 h-72 w-72 rounded-full bg-emerald-500/10 blur-3xl"></div>

    <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:py-16">
        <section>
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-extrabold tracking-tight">تعديل المناسبة</h1>
                        <p class="mt-2 text-sm text-slate-600">
                            حدّث بيانات المناسبة ثم احفظ التغييرات.
                        </p>
                    </div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">
                        تعديل مباشر
                    </span>
                </div>

                <div class="mt-5 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs font-semibold text-slate-500">رابط المناسبة</div>
                    <a href="<?php echo esc_url($event_url); ?>" class="mt-1 block break-all text-sm font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4">
                        <?php echo esc_html($event_url); ?>
                    </a>
                </div>

                <div id="editEventMsg" class="mt-5 hidden rounded-2xl p-3 text-sm font-semibold"></div>

                <form id="editEventForm" class="mt-5 space-y-4" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="post" novalidate>
                    <?php wp_nonce_field('pge_edit_event_action', 'pge_event_nonce'); ?>
                    <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />

                    <div>
                        <label for="event_title" class="text-xs font-semibold text-slate-600">اسم المناسبة</label>
                        <input
                            id="event_title"
                            name="event_title"
                            type="text"
                            value="<?php echo esc_attr($event_title); ?>"
                            class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                            required />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="event_date" class="text-xs font-semibold text-slate-600">تاريخ ووقت المناسبة</label>
                            <input
                                id="event_date"
                                name="event_date"
                                type="datetime-local"
                                value="<?php echo esc_attr($event_date_input); ?>"
                                class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none focus:border-slate-900"
                                required />
                        </div>

                        <div>
                            <label for="host_phone" class="text-xs font-semibold text-slate-600">رقم جوال المضيف</label>
                            <input
                                id="host_phone"
                                name="host_phone"
                                type="tel"
                                inputmode="tel"
                                dir="ltr"
                                value="<?php echo esc_attr($host_phone); ?>"
                                class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                                placeholder="05XXXXXXXX"
                                required />
                        </div>
                    </div>

                    <div>
                        <label for="event_location" class="text-xs font-semibold text-slate-600">رابط الموقع (Google Maps)</label>
                        <input
                            id="event_location"
                            name="event_location"
                            type="url"
                            dir="ltr"
                            value="<?php echo esc_attr($event_location); ?>"
                            class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                            placeholder="https://maps.app.goo.gl/..."
                            required />
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            id="editEventSubmit"
                            type="submit"
                            class="group inline-flex flex-1 items-center justify-center gap-2 rounded-2xl bg-gradient-to-b from-slate-900 to-slate-800 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:from-slate-800 hover:to-slate-700">
                            حفظ التعديلات
                            <span class="text-white/80 transition group-hover:-translate-x-0.5">←</span>
                        </button>
                        <a
                            href="<?php echo esc_url($dashboard_url); ?>"
                            class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                            الرجوع للوحة التحكم
                        </a>
                    </div>
                </form>
            </div>
        </section>
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
