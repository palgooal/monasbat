<?php
defined('ABSPATH') || exit;

$event_id = get_the_ID();
$author_id = (int) get_post_field('post_author', $event_id);

/**
 * =============================
 * Plan / Permissions (from plugin)
 * =============================
 */
$limits = [
    'guest_photos' => 0,
    'guest_video'  => 0,
    'public_chat'  => 0,
    'private_chat' => 0,
    'google_map'   => 0,
];

if (class_exists('PGE_Packages') && $author_id > 0) {
    $limits = array_merge($limits, (array) PGE_Packages::get_user_plan_limits($author_id));
}

// Plan flags
$can_album_photos = !empty($limits['guest_photos']); // guest uploads photos
$can_album_video  = !empty($limits['guest_video']);  // guest uploads video
$can_public_chat  = !empty($limits['public_chat']);
$can_private_chat = !empty($limits['private_chat']);

// Page data (meta placeholders)
$notes        = (string) get_post_meta($event_id, '_pge_event_notes', true);
$invite_code_raw = (string) get_post_meta($event_id, '_pge_invite_code', true);
$invite_code  = function_exists('pge_normalize_invite_code')
    ? pge_normalize_invite_code($invite_code_raw)
    : strtoupper(trim($invite_code_raw));
$qr_url       = (string) get_post_meta($event_id, '_pge_qr_url', true); // optional if you store it
$share_url    = get_permalink($event_id);

// small helper for safe text
$notes = trim(wp_strip_all_tags($notes));
?>

<section class="mx-auto max-w-6xl px-4 pb-6 sm:px-6 lg:px-8">
    <div class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-7">

        <!-- Tabs -->
        <div class="flex flex-wrap gap-2">

            <button type="button"
                class="event-tab-btn rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
                data-tab="details"
                aria-controls="event-tab-details"
                aria-selected="true">
                التفاصيل
            </button>

            <button type="button"
                class="event-tab-btn rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                data-tab="album"
                aria-controls="event-tab-album"
                aria-selected="false"
                <?php echo ($can_album_photos || $can_album_video) ? '' : 'disabled'; ?>>
                الألبوم
                <?php if (!($can_album_photos || $can_album_video)): ?>
                    <span class="ms-2 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">حسب الباقة</span>
                <?php endif; ?>
            </button>

            <button type="button"
                class="event-tab-btn rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                data-tab="chat"
                aria-controls="event-tab-chat"
                aria-selected="false"
                <?php echo ($can_public_chat || $can_private_chat) ? '' : 'disabled'; ?>>
                الدردشة
                <?php if (!($can_public_chat || $can_private_chat)): ?>
                    <span class="ms-2 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">حسب الباقة</span>
                <?php endif; ?>
            </button>
        </div>

        <div class="mt-4">
            <!-- DETAILS -->
            <div class="event-tab-panel" id="event-tab-details">
                <div class="grid gap-4 lg:grid-cols-3">

                    <!-- QR -->
                    <div class="rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-extrabold">QR للدعوة</div>
                            <span class="rounded-full bg-indigo-600 px-2 py-0.5 text-[11px] font-semibold text-white">Scan</span>
                        </div>

                        <div class="mt-4 flex items-center gap-4">
                            <div class="h-24 w-24 rounded-2xl bg-slate-200 ring-1 ring-slate-200"></div>

                            <div class="flex-1">
                                <div class="text-sm font-semibold">شارك QR بسرعة</div>
                                <p class="mt-1 text-sm text-slate-600">مناسب للدخول السريع والتحقق عند البوابة.</p>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button type="button"
                                        class="js-open-qr rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                                        عرض QR
                                    </button>

                                    <?php if (!empty($qr_url)): ?>
                                        <a href="<?php echo esc_url($qr_url); ?>"
                                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                                            download>
                                            تحميل
                                        </a>
                                    <?php else: ?>
                                        <button type="button"
                                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50 js-copy-link"
                                            data-copy="<?php echo esc_attr($share_url); ?>">
                                            نسخ رابط
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($invite_code)): ?>
                                    <div class="mt-3 text-xs text-slate-500">
                                        رمز الدعوة: <span class="font-bold text-slate-700"><?php echo esc_html($invite_code); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="rounded-3xl border border-slate-200 bg-white p-5 lg:col-span-2">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-extrabold">ملاحظات للمناسبة</div>
                                <div class="mt-1 text-sm text-slate-600">قد يضيف المضيف تعليمات خاصة بالدخول أو اللباس.</div>
                            </div>
                            <span class="rounded-2xl bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                                تحديثات تلقائية
                            </span>
                        </div>

                        <div class="mt-4 rounded-2xl bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-slate-200">
                            <?php echo $notes !== '' ? esc_html($notes) : '— لا توجد ملاحظات إضافية حاليًا —'; ?>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div class="text-xs text-slate-500">الدخول</div>
                                <div class="mt-1 text-sm font-bold">بالـ QR</div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div class="text-xs text-slate-500">الخصوصية</div>
                                <div class="mt-1 text-sm font-bold">محمي للمدعوين</div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div class="text-xs text-slate-500">المزايا</div>
                                <div class="mt-1 text-sm font-bold">حسب الباقة</div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ALBUM -->
            <div class="event-tab-panel hidden" id="event-tab-album">
                <div class="rounded-3xl border border-slate-200 bg-white p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="text-sm font-extrabold">ألبوم الصور</div>
                            <div class="mt-1 text-sm text-slate-600">الرفع قد يكون محدودًا حسب الباقة.</div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="button"
                                class="rounded-2xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"
                                <?php echo $can_album_photos ? '' : 'disabled'; ?>>
                                رفع صورة
                            </button>

                            <button type="button"
                                class="rounded-2xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                                <?php echo $can_album_video ? '' : 'disabled'; ?>>
                                رفع فيديو
                            </button>
                        </div>
                    </div>

                    <?php if (!($can_album_photos || $can_album_video)): ?>
                        <div class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
                            الألبوم غير متاح في باقتك الحالية. يمكنك الترقية لتفعيل رفع الصور/الفيديو.
                        </div>
                    <?php endif; ?>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <?php for ($i = 0; $i < 8; $i++): ?>
                            <div class="h-32 rounded-2xl bg-slate-200 ring-1 ring-slate-200"></div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- CHAT -->
            <div class="event-tab-panel hidden" id="event-tab-chat">
                <div class="grid gap-4 lg:grid-cols-3">

                    <div class="rounded-3xl border border-slate-200 bg-white p-5 lg:col-span-1">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-extrabold">الدردشات</div>
                            <span class="rounded-full bg-slate-50 px-2 py-0.5 text-[11px] font-semibold text-slate-700 ring-1 ring-slate-200">
                                عام/خاص
                            </span>
                        </div>

                        <?php if (!($can_public_chat || $can_private_chat)): ?>
                            <div class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
                                الدردشة غير متاحة في باقتك الحالية.
                            </div>
                        <?php endif; ?>

                        <div class="mt-4 space-y-2">
                            <button type="button"
                                class="w-full rounded-2xl bg-slate-900 px-4 py-3 text-start text-sm font-semibold text-white"
                                <?php echo $can_public_chat ? '' : 'disabled'; ?>>
                                الدردشة العامة
                                <div class="mt-1 text-xs text-white/70">رسائل الضيوف…</div>
                            </button>

                            <button type="button"
                                class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-start text-sm font-semibold text-slate-800 hover:bg-slate-50"
                                <?php echo $can_private_chat ? '' : 'disabled'; ?>>
                                رسالة للمضيف
                                <div class="mt-1 text-xs text-slate-500">خاص</div>
                            </button>
                        </div>

                        <div class="mt-5 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200">
                            <div class="text-sm font-bold text-amber-900">تنبيه</div>
                            <div class="mt-1 text-sm text-amber-900/80">
                                يمنع الإساءة أو نشر الروابط. قد يتم حظر المخالفين.
                            </div>
                        </div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-5 lg:col-span-2">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-extrabold">الدردشة العامة</div>
                                <div class="mt-1 text-xs text-slate-500">هذه واجهة تجريبية (سنربطها لاحقًا بالبلجن).</div>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                متصل
                            </span>
                        </div>

                        <div class="mt-4 h-72 space-y-3 overflow-auto rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                            <div class="flex gap-2">
                                <div class="h-9 w-9 rounded-2xl bg-slate-200"></div>
                                <div class="max-w-[75%] rounded-2xl bg-white p-3 ring-1 ring-slate-200">
                                    <div class="text-xs font-bold text-slate-800">سارة</div>
                                    <div class="mt-1 text-sm text-slate-700">متحمسة جدًا! مبروك 🎉</div>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <div class="max-w-[75%] rounded-2xl bg-slate-900 p-3 text-white">
                                    <div class="text-xs font-bold text-white/80">أنت</div>
                                    <div class="mt-1 text-sm">الله يبارك فيك 🙏</div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 flex gap-2">
                            <input class="h-12 flex-1 rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-slate-900"
                                placeholder="اكتب رسالة..." />
                            <button class="h-12 rounded-2xl bg-slate-900 px-5 text-sm font-semibold text-white hover:bg-slate-800">
                                إرسال
                            </button>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>

    <!-- QR Modal -->
    <div id="eventQrModal" class="fixed inset-0 z-[999] hidden">
        <div class="absolute inset-0 bg-slate-950/50 js-close-qr"></div>

        <div class="relative mx-auto mt-24 w-[92%] max-w-md rounded-3xl bg-white p-5 shadow-xl">
            <div class="flex items-center justify-between">
                <div class="text-sm font-extrabold">QR للدخول</div>
                <button type="button" class="js-close-qr rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    إغلاق
                </button>
            </div>

            <div class="mt-4 rounded-3xl bg-slate-50 p-6 text-center ring-1 ring-slate-200">
                <div class="mx-auto h-44 w-44 rounded-2xl bg-slate-200"></div>
                <div class="mt-3 text-xs text-slate-500">اعرضه عند البوابة</div>

                <div class="mt-4">
                    <button type="button"
                        class="js-copy-link rounded-2xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                        data-copy="<?php echo esc_attr($share_url); ?>">
                        نسخ رابط الدعوة
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Minimal JS (move later to assets/js/event-tabs.js) -->
    <script>
        (function() {
            const btns = document.querySelectorAll('.event-tab-btn');
            const panels = {
                details: document.getElementById('event-tab-details'),
                album: document.getElementById('event-tab-album'),
                chat: document.getElementById('event-tab-chat'),
            };

            function activate(tab) {
                Object.keys(panels).forEach(key => {
                    if (!panels[key]) return;
                    panels[key].classList.toggle('hidden', key !== tab);
                });

                btns.forEach(b => {
                    const isActive = b.dataset.tab === tab;
                    b.setAttribute('aria-selected', isActive ? 'true' : 'false');

                    // active styles
                    if (isActive) {
                        b.classList.add('bg-slate-900', 'text-white');
                        b.classList.remove('border', 'border-slate-200', 'bg-white', 'text-slate-800');
                    } else {
                        b.classList.remove('bg-slate-900', 'text-white');
                        b.classList.add('border', 'border-slate-200', 'bg-white', 'text-slate-800');
                    }
                });
            }

            btns.forEach(b => {
                b.addEventListener('click', () => {
                    if (b.disabled) return;
                    activate(b.dataset.tab);
                });
            });

            // QR Modal
            const modal = document.getElementById('eventQrModal');
            document.querySelectorAll('.js-open-qr').forEach(el => {
                el.addEventListener('click', () => {
                    if (!modal) return;
                    modal.classList.remove('hidden');
                });
            });
            document.querySelectorAll('.js-close-qr').forEach(el => {
                el.addEventListener('click', () => {
                    if (!modal) return;
                    modal.classList.add('hidden');
                });
            });

            // Copy
            document.querySelectorAll('.js-copy-link').forEach(el => {
                el.addEventListener('click', async () => {
                    const txt = el.getAttribute('data-copy') || '';
                    if (!txt) return;
                    try {
                        await navigator.clipboard.writeText(txt);
                        el.textContent = 'تم النسخ ✅';
                        setTimeout(() => (el.textContent = 'نسخ الرابط'), 1200);
                    } catch (e) {
                        // fallback
                        const ta = document.createElement('textarea');
                        ta.value = txt;
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        ta.remove();
                        el.textContent = 'تم النسخ ✅';
                        setTimeout(() => (el.textContent = 'نسخ الرابط'), 1200);
                    }
                });
            });

            // default tab
            activate('details');
        })();
    </script>
</section>
