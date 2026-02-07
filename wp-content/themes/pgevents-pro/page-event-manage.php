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

$can_manage = function_exists('pge_event_guests_user_can_manage')
    ? pge_event_guests_user_can_manage($event_id)
    : (current_user_can('administrator') || ((int) $event_post->post_author === get_current_user_id()));

if (!$can_manage) {
    wp_safe_redirect(home_url('/dashboard/?tab=events'));
    exit;
}

$guests_map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
$stats = function_exists('pge_event_guests_get_stats')
    ? pge_event_guests_get_stats($event_id, $guests_map)
    : ['total' => count($guests_map), 'yes' => 0, 'no' => 0, 'pending' => count($guests_map), 'checked' => 0];
$invite_code_raw = (string) get_post_meta($event_id, '_pge_invite_code', true);
$invite_code = function_exists('pge_normalize_invite_code')
    ? pge_normalize_invite_code($invite_code_raw)
    : strtoupper(trim($invite_code_raw));

$event_date = (string) get_post_meta($event_id, '_pge_event_date', true);
$event_date_label = $event_date ? date_i18n('j F Y - g:i a', strtotime(str_replace('T', ' ', $event_date))) : '—';
$manage_nonce = wp_create_nonce('pge_event_manage_nonce');
$dashboard_url = home_url('/dashboard/?tab=events&event=' . $event_id);
$event_url = get_permalink($event_id);
$event_image_url = get_the_post_thumbnail_url($event_id, 'full');
$event_image_url = $event_image_url ? (string) $event_image_url : '';
$edit_url = home_url('/edit-event/' . $event_id . '/');

get_header();
?>

<div class="relative min-h-screen overflow-hidden" dir="rtl">
    <div class="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-b from-indigo-50/70 via-white to-slate-50"></div>
    <div class="pointer-events-none absolute -top-20 start-[-7rem] h-80 w-80 rounded-full bg-indigo-500/15 blur-3xl"></div>
    <div class="pointer-events-none absolute top-28 end-[-8rem] h-96 w-96 rounded-full bg-slate-900/10 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-28 start-1/3 h-72 w-72 rounded-full bg-emerald-500/10 blur-3xl"></div>

    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:py-12">
        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight">إدارة المدعوين</h1>
                    <p class="mt-1 text-sm text-slate-600">المناسبة: <?php echo esc_html(get_the_title($event_id)); ?></p>
                    <p class="mt-1 text-xs text-slate-500"><?php echo esc_html($event_date_label); ?></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="<?php echo esc_url($event_url); ?>" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">فتح الدعوة</a>
                    <a href="<?php echo esc_url($edit_url); ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">تعديل المناسبة</a>
                    <a href="<?php echo esc_url($dashboard_url); ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">العودة للوحة التحكم</a>
                </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs font-semibold text-slate-500">إجمالي المدعوين</div>
                    <div class="mt-1 text-2xl font-extrabold"><?php echo (int) $stats['total']; ?></div>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs font-semibold text-slate-500">سيحضر</div>
                    <div class="mt-1 text-2xl font-extrabold text-emerald-700"><?php echo (int) $stats['yes']; ?></div>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs font-semibold text-slate-500">اعتذر</div>
                    <div class="mt-1 text-2xl font-extrabold text-rose-700"><?php echo (int) $stats['no']; ?></div>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs font-semibold text-slate-500">لم يرد</div>
                    <div class="mt-1 text-2xl font-extrabold"><?php echo (int) $stats['pending']; ?></div>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs font-semibold text-slate-500">Check-ins</div>
                    <div class="mt-1 text-2xl font-extrabold"><?php echo (int) $stats['checked']; ?></div>
                </div>
            </div>
        </section>

        <div id="manageMsg" class="mt-4 hidden rounded-2xl p-3 text-sm font-semibold"></div>

        <section class="mt-4 grid gap-4 lg:grid-cols-12">
            <div class="lg:col-span-7 space-y-4">
                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="w-full sm:max-w-md">
                            <input id="guestSearch" type="text" placeholder="ابحث بالاسم أو رقم الجوال أو الملاحظة..."
                                class="h-11 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="status-filter rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white" data-status="all">الكل</button>
                            <button type="button" class="status-filter rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-status="yes">سيحضر</button>
                            <button type="button" class="status-filter rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-status="no">اعتذر</button>
                            <button type="button" class="status-filter rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" data-status="pending">لم يرد</button>
                        </div>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-lg font-extrabold">قائمة المدعوين</h2>
                        <div class="flex flex-wrap gap-2">
                            <button id="bulkDeleteBtn" type="button" disabled class="rounded-2xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-50">حذف المحدد</button>
                            <button id="bulkWhatsappBtn" type="button" disabled class="rounded-2xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50">واتساب للمحدد</button>
                            <button id="whatsappAllBtn" type="button" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">واتساب للكل</button>
                            <button id="exportCsvBtn" type="button" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">تصدير CSV</button>
                        </div>
                    </div>

                    <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-slate-600">
                                    <tr>
                                        <th class="px-3 py-3 text-start font-semibold">
                                            <input id="selectAllGuests" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
                                        </th>
                                        <th class="px-3 py-3 text-start font-semibold">الاسم</th>
                                        <th class="px-3 py-3 text-start font-semibold">الجوال</th>
                                        <th class="px-3 py-3 text-start font-semibold">الملاحظة</th>
                                        <th class="px-3 py-3 text-start font-semibold">RSVP</th>
                                        <th class="px-3 py-3 text-start font-semibold">Check-in</th>
                                        <th class="px-3 py-3 text-start font-semibold">إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody id="guestsTableBody" class="divide-y divide-slate-100">
                                    <?php if (empty($guests_map)): ?>
                                        <tr>
                                            <td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500">لا يوجد مدعوون بعد.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($guests_map as $phone => $guest):
                                            $row = function_exists('pge_event_guests_get_row_payload')
                                                ? pge_event_guests_get_row_payload($event_id, $guest)
                                                : ['phone' => $phone, 'name' => '', 'note' => '', 'status' => 'pending', 'status_label' => 'لم يرد', 'checked' => 'no'];
                                            $name = (string) ($row['name'] ?? '');
                                            $note = (string) ($row['note'] ?? '');
                                            $status = (string) ($row['status'] ?? 'pending');
                                            $status_label = (string) ($row['status_label'] ?? 'لم يرد');
                                            $checked = (string) ($row['checked'] ?? 'no');
                                        ?>
                                            <tr class="guest-row" data-phone="<?php echo esc_attr($phone); ?>" data-name="<?php echo esc_attr($name); ?>" data-note="<?php echo esc_attr($note); ?>" data-status="<?php echo esc_attr($status); ?>" data-checked="<?php echo esc_attr($checked); ?>">
                                                <td class="px-3 py-3">
                                                    <input type="checkbox" class="guest-checkbox h-4 w-4 rounded border-slate-300" data-phone="<?php echo esc_attr($phone); ?>" />
                                                </td>
                                                <td class="px-3 py-3 font-semibold text-slate-900"><?php echo $name !== '' ? esc_html($name) : '—'; ?></td>
                                                <td class="px-3 py-3 font-mono text-slate-800"><?php echo esc_html($phone); ?></td>
                                                <td class="px-3 py-3 text-slate-600"><?php echo $note !== '' ? esc_html($note) : '—'; ?></td>
                                                <td class="px-3 py-3">
                                                    <?php if ($status === 'yes'): ?>
                                                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200"><?php echo esc_html($status_label); ?></span>
                                                    <?php elseif ($status === 'no'): ?>
                                                        <span class="rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 ring-1 ring-rose-200"><?php echo esc_html($status_label); ?></span>
                                                    <?php else: ?>
                                                        <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200"><?php echo esc_html($status_label); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <?php if ($checked === 'yes'): ?>
                                                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-200">تم</span>
                                                    <?php else: ?>
                                                        <span class="text-slate-400">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <div class="flex flex-wrap gap-2">
                                                        <button type="button" class="guest-wa-btn rounded-xl bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-500" data-phone="<?php echo esc_attr($phone); ?>" data-name="<?php echo esc_attr($name); ?>">واتساب</button>
                                                        <button type="button" class="guest-edit-btn rounded-xl border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-800 hover:bg-slate-50" data-phone="<?php echo esc_attr($phone); ?>">تعديل</button>
                                                        <button type="button" class="guest-delete-btn rounded-xl bg-rose-600 px-3 py-1 text-xs font-semibold text-white hover:bg-rose-500" data-phone="<?php echo esc_attr($phone); ?>">حذف</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-5 space-y-4">
                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-lg font-extrabold">رمز الدعوة</h3>
                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-200">Access</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-600">الضيف يدخل الرمز مع رقم جواله لفتح الدعوة.</p>

                    <div class="mt-4 space-y-3">
                        <div class="flex gap-2">
                            <input
                                id="inviteCodeInput"
                                type="text"
                                dir="ltr"
                                maxlength="9"
                                value="<?php echo esc_attr($invite_code); ?>"
                                class="h-11 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold tracking-widest outline-none placeholder:text-slate-400 focus:border-slate-900"
                                placeholder="AB12-CD34" />
                            <button
                                id="saveInviteCodeBtn"
                                type="button"
                                class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                حفظ
                            </button>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <button
                                id="regenInviteCodeBtn"
                                type="button"
                                class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                                توليد جديد
                            </button>
                            <button
                                id="copyInviteCodeBtn"
                                type="button"
                                class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                                نسخ الرمز
                            </button>
                        </div>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-extrabold">إضافة مدعو</h3>
                    <div class="mt-4 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-sm font-extrabold text-slate-900">رسالة واتساب</h4>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200">Template</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-600">خصص نص الدعوة قبل الإرسال. استخدم المتغيرات التالية داخل الرسالة.</p>

                        <textarea
                            id="whatsappTemplateInput"
                            rows="6"
                            class="mt-3 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                            placeholder="اكتب نص رسالة الدعوة..."></textarea>

                        <div class="mt-2 flex flex-wrap gap-2 text-[11px] text-slate-600">
                            <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">{{guest_name}}</span>
                            <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">{{event_title}}</span>
                            <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">{{event_url}}</span>
                            <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">{{image_url}}</span>
                            <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">{{invite_code}}</span>
                            <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">{{guest_phone}}</span>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                id="resetWhatsappTemplateBtn"
                                type="button"
                                class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50">
                                استعادة الافتراضي
                            </button>
                            <button
                                id="copyWhatsappPreviewBtn"
                                type="button"
                                class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50">
                                نسخ المعاينة
                            </button>
                        </div>

                        <div class="mt-3 rounded-2xl bg-white p-3 text-xs text-slate-700 ring-1 ring-slate-200">
                            <div class="mb-1 font-semibold text-slate-700">معاينة</div>
                            <pre id="whatsappPreviewText" class="whitespace-pre-wrap break-words font-sans text-xs leading-6 text-slate-700"></pre>
                        </div>
                    </div>

                    <form id="addGuestForm" class="mt-6 space-y-3">
                        <div>
                            <label for="addGuestName" class="text-xs font-semibold text-slate-600">الاسم (اختياري)</label>
                            <input id="addGuestName" name="name" type="text" class="mt-2 h-11 w-full rounded-2xl border border-slate-200 px-4 text-sm outline-none focus:border-slate-900" placeholder="مثال: أحمد علي" />
                        </div>
                        <div>
                            <label for="addGuestPhone" class="text-xs font-semibold text-slate-600">رقم الجوال</label>
                            <input id="addGuestPhone" name="phone" type="tel" inputmode="tel" class="mt-2 h-11 w-full rounded-2xl border border-slate-200 px-4 text-sm outline-none focus:border-slate-900" placeholder="05XXXXXXXX" required />
                        </div>
                        <div>
                            <label for="addGuestNote" class="text-xs font-semibold text-slate-600">ملاحظة (اختياري)</label>
                            <input id="addGuestNote" name="note" type="text" class="mt-2 h-11 w-full rounded-2xl border border-slate-200 px-4 text-sm outline-none focus:border-slate-900" placeholder="VIP / عائلة / ..." />
                        </div>
                        <button type="submit" class="w-full rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">إضافة المدعو</button>
                    </form>
                </div>

                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-extrabold">إضافة جماعية</h3>
                    <p class="mt-1 text-sm text-slate-600">ضع كل رقم في سطر منفصل.</p>
                    <form id="bulkGuestForm" class="mt-4 space-y-3">
                        <textarea id="bulkPhones" name="phones_text" rows="6" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-slate-900" placeholder="05XXXXXXXX&#10;05YYYYYYYY&#10;9665ZZZZZZZZ"></textarea>
                        <button type="submit" class="w-full rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-500">إضافة الأرقام</button>
                    </form>
                </div>

                <div id="editGuestCard" class="hidden rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-extrabold">تعديل المدعو</h3>
                    <form id="editGuestForm" class="mt-4 space-y-3">
                        <input type="hidden" id="editOldPhone" name="old_phone" />
                        <div>
                            <label for="editGuestName" class="text-xs font-semibold text-slate-600">الاسم</label>
                            <input id="editGuestName" name="name" type="text" class="mt-2 h-11 w-full rounded-2xl border border-slate-200 px-4 text-sm outline-none focus:border-slate-900" />
                        </div>
                        <div>
                            <label for="editGuestPhone" class="text-xs font-semibold text-slate-600">رقم الجوال</label>
                            <input id="editGuestPhone" name="phone" type="tel" inputmode="tel" class="mt-2 h-11 w-full rounded-2xl border border-slate-200 px-4 text-sm outline-none focus:border-slate-900" required />
                        </div>
                        <div>
                            <label for="editGuestNote" class="text-xs font-semibold text-slate-600">الملاحظة</label>
                            <input id="editGuestNote" name="note" type="text" class="mt-2 h-11 w-full rounded-2xl border border-slate-200 px-4 text-sm outline-none focus:border-slate-900" />
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="flex-1 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">حفظ التعديل</button>
                            <button id="cancelEditGuestBtn" type="button" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
    window.PGE_EVENT_MANAGE = {
        ajax: "<?php echo esc_js(admin_url('admin-ajax.php')); ?>",
        nonce: "<?php echo esc_js($manage_nonce); ?>",
        eventId: "<?php echo (int) $event_id; ?>",
        eventUrl: "<?php echo esc_js($event_url); ?>",
        eventTitle: "<?php echo esc_js(get_the_title($event_id)); ?>",
        eventImage: "<?php echo esc_js($event_image_url); ?>"
    };
</script>

<script>
    const cfg = window.PGE_EVENT_MANAGE || {};
    const msg = document.getElementById('manageMsg');
    const inviteCodeInput = document.getElementById('inviteCodeInput');
    const saveInviteCodeBtn = document.getElementById('saveInviteCodeBtn');
    const regenInviteCodeBtn = document.getElementById('regenInviteCodeBtn');
    const copyInviteCodeBtn = document.getElementById('copyInviteCodeBtn');
    const addForm = document.getElementById('addGuestForm');
    const bulkForm = document.getElementById('bulkGuestForm');
    const editForm = document.getElementById('editGuestForm');
    const editCard = document.getElementById('editGuestCard');
    const cancelEditBtn = document.getElementById('cancelEditGuestBtn');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const bulkWhatsappBtn = document.getElementById('bulkWhatsappBtn');
    const whatsappAllBtn = document.getElementById('whatsappAllBtn');
    const exportCsvBtn = document.getElementById('exportCsvBtn');
    const selectAllGuests = document.getElementById('selectAllGuests');
    const searchInput = document.getElementById('guestSearch');
    const statusFilterBtns = document.querySelectorAll('.status-filter');
    const whatsappTemplateInput = document.getElementById('whatsappTemplateInput');
    const resetWhatsappTemplateBtn = document.getElementById('resetWhatsappTemplateBtn');
    const copyWhatsappPreviewBtn = document.getElementById('copyWhatsappPreviewBtn');
    const whatsappPreviewText = document.getElementById('whatsappPreviewText');
    const whatsappTemplateStorageKey = cfg.eventId ? `pge_whatsapp_template_${cfg.eventId}` : 'pge_whatsapp_template';

    let activeStatus = 'all';

    function showMsg(type, text) {
        if (!msg) return;
        msg.classList.remove('hidden', 'bg-rose-50', 'text-rose-800', 'ring-rose-200', 'bg-emerald-50', 'text-emerald-800', 'ring-emerald-200', 'ring-1');
        if (type === 'success') {
            msg.classList.add('bg-emerald-50', 'text-emerald-800', 'ring-1', 'ring-emerald-200');
        } else {
            msg.classList.add('bg-rose-50', 'text-rose-800', 'ring-1', 'ring-rose-200');
        }
        msg.textContent = text;
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

    function normalizeWhatsappPhone(phone) {
        let digits = normPhone(phone);
        if (!digits) return '';

        if (digits.startsWith('00')) {
            digits = digits.slice(2);
        }

        if (digits.startsWith('0') && digits.length === 10 && digits[1] === '5') {
            digits = `966${digits.slice(1)}`;
        } else if (digits.length === 9 && digits.startsWith('5')) {
            digits = `966${digits}`;
        }

        if (digits.length < 8 || digits.length > 15) {
            return '';
        }

        return digits;
    }

    function buildInviteMessageLegacy(name, phone) {
        const inviteCode = normalizeInviteCode(inviteCodeInput ? inviteCodeInput.value : '');
        const guestName = (name || '').toString().trim();
        const title = (cfg.eventTitle || '').toString().trim();
        const link = (cfg.eventUrl || window.location.href || '').toString().trim();

        if (!inviteCode || !link) return '';

        const lines = [];
        lines.push(guestName ? `مرحباً ${guestName}` : 'مرحباً');
        lines.push(title ? `دعوة لحضور: ${title}` : 'دعوة لحضور المناسبة');
        lines.push(`رابط الدعوة: ${link}`);
        lines.push(`رمز الدعوة: ${inviteCode}`);
        lines.push(`رقمك المسجل: ${normPhone(phone)}`);
        return lines.join('\n');
    }

    function getDefaultWhatsappTemplate() {
        return [
            'مرحباً {{guest_name}}',
            'يسعدنا دعوتك لحضور: {{event_title}}',
            'رابط الدعوة: {{event_url}}',
            'صورة الدعوة: {{image_url}}',
            'رمز الدعوة: {{invite_code}}',
            'رقمك المسجل: {{guest_phone}}',
        ].join('\n');
    }

    function getWhatsappTemplateValue() {
        const text = (whatsappTemplateInput ? whatsappTemplateInput.value : '').toString().trim();
        return text || getDefaultWhatsappTemplate();
    }

    function renderWhatsappTemplate(template, context) {
        let output = (template || '').toString();
        Object.entries(context).forEach(([key, value]) => {
            const safeValue = (value == null ? '' : String(value));
            const bracesPattern = new RegExp(`\\{\\{\\s*${key}\\s*\\}\\}`, 'g');
            const simplePattern = new RegExp(`\\{\\s*${key}\\s*\\}`, 'g');
            output = output.replace(bracesPattern, safeValue);
            output = output.replace(simplePattern, safeValue);
        });
        return output;
    }

    function buildInviteMessage(name, phone) {
        const inviteCode = normalizeInviteCode(inviteCodeInput ? inviteCodeInput.value : '');
        const guestNameRaw = (name || '').toString().trim();
        const guestPhone = normPhone(phone);
        const title = (cfg.eventTitle || '').toString().trim();
        const link = (cfg.eventUrl || window.location.href || '').toString().trim();
        const imageUrl = (cfg.eventImage || '').toString().trim();

        if (!inviteCode || !link) return '';

        const context = {
            guest_name: guestNameRaw || 'ضيفنا الكريم',
            event_title: title || 'مناسبتنا',
            event_url: link,
            image_url: imageUrl || link,
            invite_code: inviteCode,
            guest_phone: guestPhone || '',
        };

        return renderWhatsappTemplate(getWhatsappTemplateValue(), context).trim();
    }

    function updateWhatsappPreview() {
        if (!whatsappPreviewText) return;

        const firstRow = getRows()[0];
        const sampleName = firstRow ? ((firstRow.dataset.name || '').toString().trim() || 'ضيفنا الكريم') : 'ضيفنا الكريم';
        const samplePhone = firstRow ? normPhone(firstRow.dataset.phone || '') : '05XXXXXXXX';
        const rendered = buildInviteMessage(sampleName, samplePhone);

        if (!rendered) {
            whatsappPreviewText.textContent = 'احفظ رمز الدعوة أولاً لإظهار المعاينة.';
            return;
        }

        whatsappPreviewText.textContent = rendered;
    }

    function getWhatsappUrl(phone, name, silent = false) {
        const inviteCode = normalizeInviteCode(inviteCodeInput ? inviteCodeInput.value : '');
        if (!inviteCode) {
            if (!silent) showMsg('error', 'احفظ رمز الدعوة أولاً قبل الإرسال.');
            return '';
        }

        const waPhone = normalizeWhatsappPhone(phone);
        if (!waPhone) {
            if (!silent) showMsg('error', 'رقم الجوال غير صالح لواتساب. استخدم رقمًا بصيغة دولية مثل 9665xxxxxxx.');
            return '';
        }

        const text = buildInviteMessage(name, phone);
        if (!text) {
            if (!silent) showMsg('error', 'تعذر تجهيز نص الدعوة.');
            return '';
        }

        return `https://wa.me/${waPhone}?text=${encodeURIComponent(text)}`;
    }

    function openWhatsappInvite(phone, name, silent = false) {
        const url = getWhatsappUrl(phone, name, silent);
        if (!url) return false;

        const win = window.open(url, '_blank', 'noopener');
        if (!win && !silent) {
            showMsg('error', 'المتصفح منع فتح واتساب. اسمح بفتح النوافذ المنبثقة.');
        }
        return !!win;
    }

    function normPhone(v) {
        return (v || '').toString().replace(/\D+/g, '');
    }

    function getRows() {
        return Array.from(document.querySelectorAll('.guest-row'));
    }

    function getSelectedPhones() {
        return Array.from(document.querySelectorAll('.guest-checkbox:checked'))
            .map((el) => normPhone(el.dataset.phone || ''))
            .filter(Boolean);
    }

    function refreshBulkDeleteState() {
        const hasSelection = getSelectedPhones().length > 0;
        if (bulkDeleteBtn) bulkDeleteBtn.disabled = !hasSelection;
        if (bulkWhatsappBtn) bulkWhatsappBtn.disabled = !hasSelection;
    }

    function applyFilters() {
        const q = (searchInput?.value || '').toLowerCase().trim();
        getRows().forEach((row) => {
            const text = `${row.dataset.name || ''} ${row.dataset.phone || ''} ${row.dataset.note || ''}`.toLowerCase();
            const statusOk = activeStatus === 'all' || (row.dataset.status || 'pending') === activeStatus;
            const queryOk = q === '' || text.includes(q);
            row.style.display = (statusOk && queryOk) ? '' : 'none';
        });
    }

    async function postAction(action, payload = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', cfg.nonce || '');
        fd.append('event_id', cfg.eventId || '');

        Object.entries(payload).forEach(([k, v]) => {
            if (Array.isArray(v)) {
                v.forEach((item) => fd.append(`${k}[]`, item));
            } else {
                fd.append(k, v == null ? '' : String(v));
            }
        });

        const res = await fetch(cfg.ajax, {
            method: 'POST',
            body: fd
        });
        return res.json();
    }

    function reloadSoon() {
        window.setTimeout(() => window.location.reload(), 350);
    }

    async function saveInviteCode(explicitCode = null) {
        const normalized = explicitCode == null
            ? normalizeInviteCode(inviteCodeInput ? inviteCodeInput.value : '')
            : normalizeInviteCode(explicitCode);

        try {
            const json = await postAction('pge_event_set_invite_code', {
                invite_code: normalized
            });

            if (json && json.success) {
                const nextCode = normalizeInviteCode((json.data && json.data.invite_code) ? json.data.invite_code : normalized);
                if (inviteCodeInput) {
                    inviteCodeInput.value = nextCode;
                }
                showMsg('success', (json.data && json.data.message) ? json.data.message : 'تم تحديث رمز الدعوة');
            } else {
                showMsg('error', (json && json.data) ? json.data : 'تعذر حفظ رمز الدعوة');
            }
        } catch (err) {
            showMsg('error', 'تعذر الاتصال بالخادم');
        }
    }

    statusFilterBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            activeStatus = btn.dataset.status || 'all';
            statusFilterBtns.forEach((b) => {
                b.classList.remove('bg-slate-900', 'text-white');
                b.classList.add('border', 'border-slate-200', 'bg-white', 'text-slate-800');
            });
            btn.classList.add('bg-slate-900', 'text-white');
            btn.classList.remove('border', 'border-slate-200', 'bg-white', 'text-slate-800');
            applyFilters();
        });
    });

    if (inviteCodeInput) {
        inviteCodeInput.value = normalizeInviteCode(inviteCodeInput.value || '');
        inviteCodeInput.addEventListener('input', () => {
            inviteCodeInput.value = normalizeInviteCode(inviteCodeInput.value);
            updateWhatsappPreview();
        });
    }

    if (saveInviteCodeBtn) {
        saveInviteCodeBtn.addEventListener('click', async () => {
            await saveInviteCode();
            updateWhatsappPreview();
        });
    }

    if (regenInviteCodeBtn) {
        regenInviteCodeBtn.addEventListener('click', async () => {
            const newCode = generateInviteCode();
            if (inviteCodeInput) inviteCodeInput.value = newCode;
            await saveInviteCode(newCode);
            updateWhatsappPreview();
        });
    }

    if (copyInviteCodeBtn) {
        copyInviteCodeBtn.addEventListener('click', async () => {
            const code = normalizeInviteCode(inviteCodeInput ? inviteCodeInput.value : '');
            if (!code) {
                showMsg('error', 'لا يوجد رمز دعوة للنسخ');
                return;
            }
            try {
                await navigator.clipboard.writeText(code);
                showMsg('success', 'تم نسخ رمز الدعوة');
            } catch (err) {
                showMsg('error', 'تعذر نسخ الرمز');
            }
        });
    }

    if (whatsappTemplateInput) {
        const defaultTemplate = getDefaultWhatsappTemplate();
        let templateValue = '';
        try {
            templateValue = (window.localStorage && localStorage.getItem(whatsappTemplateStorageKey)) || '';
        } catch (e) {
            templateValue = '';
        }

        whatsappTemplateInput.value = (templateValue || defaultTemplate).toString();
        whatsappTemplateInput.addEventListener('input', () => {
            try {
                if (window.localStorage) {
                    localStorage.setItem(whatsappTemplateStorageKey, whatsappTemplateInput.value || '');
                }
            } catch (e) {}
            updateWhatsappPreview();
        });
    }

    if (resetWhatsappTemplateBtn) {
        resetWhatsappTemplateBtn.addEventListener('click', () => {
            const defaultTemplate = getDefaultWhatsappTemplate();
            if (whatsappTemplateInput) {
                whatsappTemplateInput.value = defaultTemplate;
            }
            try {
                if (window.localStorage) {
                    localStorage.setItem(whatsappTemplateStorageKey, defaultTemplate);
                }
            } catch (e) {}
            updateWhatsappPreview();
            showMsg('success', 'تمت استعادة قالب رسالة واتساب الافتراضي.');
        });
    }

    if (copyWhatsappPreviewBtn) {
        copyWhatsappPreviewBtn.addEventListener('click', async () => {
            const text = (whatsappPreviewText ? whatsappPreviewText.textContent : '').toString().trim();
            if (!text) {
                showMsg('error', 'لا توجد معاينة قابلة للنسخ.');
                return;
            }
            try {
                await navigator.clipboard.writeText(text);
                showMsg('success', 'تم نسخ نص المعاينة.');
            } catch (err) {
                showMsg('error', 'تعذر نسخ نص المعاينة.');
            }
        });
    }

    updateWhatsappPreview();

    if (searchInput) searchInput.addEventListener('input', applyFilters);

    document.addEventListener('change', (e) => {
        if (e.target && e.target.classList.contains('guest-checkbox')) refreshBulkDeleteState();
    });

    if (selectAllGuests) {
        selectAllGuests.addEventListener('change', () => {
            const checked = !!selectAllGuests.checked;
            document.querySelectorAll('.guest-checkbox').forEach((el) => {
                el.checked = checked;
            });
            refreshBulkDeleteState();
        });
    }

    if (addForm) {
        addForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const data = new FormData(addForm);
            const phone = normPhone(data.get('phone'));
            if (!phone) {
                showMsg('error', 'أدخل رقم جوال صحيح');
                return;
            }

            try {
                const json = await postAction('pge_event_guest_add', {
                    phone,
                    name: data.get('name') || '',
                    note: data.get('note') || ''
                });
                if (json && json.success) {
                    showMsg('success', (json.data && json.data.message) ? json.data.message : 'تمت الإضافة');
                    addForm.reset();
                    reloadSoon();
                } else {
                    showMsg('error', (json && json.data) ? json.data : 'تعذر تنفيذ العملية');
                }
            } catch (err) {
                showMsg('error', 'تعذر الاتصال بالخادم');
            }
        });
    }

    if (bulkForm) {
        bulkForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const data = new FormData(bulkForm);
            const phonesText = (data.get('phones_text') || '').toString().trim();
            if (!phonesText) {
                showMsg('error', 'أدخل أرقام الجوال أولاً');
                return;
            }

            try {
                const json = await postAction('pge_event_guest_bulk_add', {
                    phones_text: phonesText
                });
                if (json && json.success) {
                    showMsg('success', (json.data && json.data.message) ? json.data.message : 'تمت الإضافة الجماعية');
                    bulkForm.reset();
                    reloadSoon();
                } else {
                    showMsg('error', (json && json.data) ? json.data : 'تعذر تنفيذ العملية');
                }
            } catch (err) {
                showMsg('error', 'تعذر الاتصال بالخادم');
            }
        });
    }

    if (editForm) {
        editForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const data = new FormData(editForm);
            const oldPhone = normPhone(data.get('old_phone'));
            const phone = normPhone(data.get('phone'));
            if (!oldPhone || !phone) {
                showMsg('error', 'رقم الجوال غير صالح');
                return;
            }

            try {
                const json = await postAction('pge_event_guest_update', {
                    old_phone: oldPhone,
                    phone,
                    name: data.get('name') || '',
                    note: data.get('note') || ''
                });
                if (json && json.success) {
                    showMsg('success', (json.data && json.data.message) ? json.data.message : 'تم التحديث');
                    reloadSoon();
                } else {
                    showMsg('error', (json && json.data) ? json.data : 'تعذر تنفيذ العملية');
                }
            } catch (err) {
                showMsg('error', 'تعذر الاتصال بالخادم');
            }
        });
    }

    if (cancelEditBtn && editCard) {
        cancelEditBtn.addEventListener('click', () => {
            editCard.classList.add('hidden');
        });
    }

    document.addEventListener('click', async (e) => {
        const waBtn = e.target.closest('.guest-wa-btn');
        if (waBtn) {
            const phone = normPhone(waBtn.dataset.phone || '');
            const name = (waBtn.dataset.name || '').toString();
            if (!phone) {
                showMsg('error', 'رقم الجوال غير صالح.');
                return;
            }

            openWhatsappInvite(phone, name);
            return;
        }

        const editBtn = e.target.closest('.guest-edit-btn');
        if (editBtn) {
            const row = editBtn.closest('.guest-row');
            if (!row || !editCard || !editForm) return;

            editForm.querySelector('#editOldPhone').value = row.dataset.phone || '';
            editForm.querySelector('#editGuestPhone').value = row.dataset.phone || '';
            editForm.querySelector('#editGuestName').value = row.dataset.name || '';
            editForm.querySelector('#editGuestNote').value = row.dataset.note || '';
            editCard.classList.remove('hidden');
            editCard.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
            return;
        }

        const delBtn = e.target.closest('.guest-delete-btn');
        if (delBtn) {
            const phone = normPhone(delBtn.dataset.phone || '');
            if (!phone) return;
            if (!window.confirm('هل تريد حذف هذا المدعو؟')) return;

            try {
                const json = await postAction('pge_event_guest_delete', {
                    phone
                });
                if (json && json.success) {
                    showMsg('success', (json.data && json.data.message) ? json.data.message : 'تم الحذف');
                    reloadSoon();
                } else {
                    showMsg('error', (json && json.data) ? json.data : 'تعذر تنفيذ العملية');
                }
            } catch (err) {
                showMsg('error', 'تعذر الاتصال بالخادم');
            }
        }
    });

    if (bulkWhatsappBtn) {
        bulkWhatsappBtn.addEventListener('click', () => {
            const inviteCode = normalizeInviteCode(inviteCodeInput ? inviteCodeInput.value : '');
            if (!inviteCode) {
                showMsg('error', 'احفظ رمز الدعوة أولاً قبل الإرسال.');
                return;
            }

            const phones = getSelectedPhones();
            if (phones.length === 0) return;

            let opened = 0;
            phones.forEach((phone, idx) => {
                const row = document.querySelector(`.guest-row[data-phone="${phone}"]`);
                const name = row ? (row.dataset.name || '') : '';
                window.setTimeout(() => {
                    if (openWhatsappInvite(phone, name, true)) {
                        opened += 1;
                    }
                }, idx * 220);
            });

            showMsg('success', `جاري فتح واتساب للمحدد (${phones.length}). قد يمنع المتصفح بعض النوافذ.`);
        });
    }

    if (whatsappAllBtn) {
        whatsappAllBtn.addEventListener('click', () => {
            const inviteCode = normalizeInviteCode(inviteCodeInput ? inviteCodeInput.value : '');
            if (!inviteCode) {
                showMsg('error', 'احفظ رمز الدعوة أولاً قبل الإرسال.');
                return;
            }

            const rows = getRows();
            if (!rows.length) {
                showMsg('error', 'لا يوجد مدعوون لإرسال الدعوة.');
                return;
            }

            let opened = 0;
            rows.forEach((row, idx) => {
                const phone = normPhone(row.dataset.phone || '');
                const name = (row.dataset.name || '').toString();
                if (!phone) return;
                window.setTimeout(() => {
                    if (openWhatsappInvite(phone, name, true)) {
                        opened += 1;
                    }
                }, idx * 220);
            });

            showMsg('success', `جاري فتح واتساب لكل المدعوين (${rows.length}). قد يمنع المتصفح بعض النوافذ.`);
        });
    }

    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', async () => {
            const phones = getSelectedPhones();
            if (phones.length === 0) return;
            if (!window.confirm(`حذف ${phones.length} مدعو؟`)) return;

            try {
                const json = await postAction('pge_event_guest_bulk_delete', {
                    phones
                });
                if (json && json.success) {
                    showMsg('success', (json.data && json.data.message) ? json.data.message : 'تم الحذف');
                    reloadSoon();
                } else {
                    showMsg('error', (json && json.data) ? json.data : 'تعذر تنفيذ العملية');
                }
            } catch (err) {
                showMsg('error', 'تعذر الاتصال بالخادم');
            }
        });
    }

    if (exportCsvBtn) {
        exportCsvBtn.addEventListener('click', () => {
            const rows = getRows();
            if (!rows.length) {
                showMsg('error', 'لا توجد بيانات للتصدير');
                return;
            }
            const data = [
                ['الاسم', 'الجوال', 'الملاحظة', 'RSVP', 'Check-in']
            ];
            rows.forEach((row) => {
                const statusCell = row.querySelector('td:nth-child(5)');
                const checkCell = row.querySelector('td:nth-child(6)');
                data.push([
                    row.dataset.name || '',
                    row.dataset.phone || '',
                    row.dataset.note || '',
                    (statusCell ? statusCell.textContent : '').trim().replace(/\s+/g, ' '),
                    (checkCell ? checkCell.textContent : '').trim().replace(/\s+/g, ' '),
                ]);
            });

            const esc = (v) => `"${String(v || '').replace(/"/g, '""')}"`;
            const csv = '\uFEFF' + data.map((r) => r.map(esc).join(',')).join('\n');
            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `event-guests-<?php echo (int) $event_id; ?>.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }

    if (whatsappAllBtn && getRows().length === 0) {
        whatsappAllBtn.disabled = true;
        whatsappAllBtn.classList.add('cursor-not-allowed', 'opacity-50');
    }

    refreshBulkDeleteState();
    applyFilters();
</script>

<?php get_footer(); ?>
