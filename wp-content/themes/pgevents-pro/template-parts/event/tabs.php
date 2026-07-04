<?php
defined('ABSPATH') || exit;

$event_id  = get_the_ID();
$author_id = (int) get_post_field('post_author', $event_id);

// =============================
// Plan / Permissions
// =============================
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

$can_album_photos = !empty($limits['guest_photos']);
$can_album_video  = !empty($limits['guest_video']);
$can_public_chat  = !empty($limits['public_chat']);
$can_private_chat = !empty($limits['private_chat']);

// =============================
// Data
// =============================
$notes           = trim(wp_strip_all_tags((string) get_post_meta($event_id, '_pge_event_notes', true)));
$invite_code_raw = (string) get_post_meta($event_id, '_pge_invite_code', true);
$invite_code     = function_exists('pge_normalize_invite_code')
    ? pge_normalize_invite_code($invite_code_raw)
    : strtoupper(trim($invite_code_raw));

$share_url = get_permalink($event_id);

// QR الحقيقي من invite_code
$qr_img_url = '';
if ($invite_code !== '' && function_exists('pge_generate_qr_url')) {
    $qr_img_url = pge_generate_qr_url($invite_code, 320);
}
?>

<div class="mx-auto max-w-lg px-4 pb-4" dir="rtl">

    <!-- ===========================
         QR Card (دائماً مرئي)
    =========================== -->
    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

        <!-- رأس QR -->
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <div class="text-sm font-extrabold text-slate-900">بطاقة دخولك 🎟</div>
                <div class="mt-0.5 text-xs text-slate-500">أرِها عند الباب للدخول السريع</div>
            </div>
            <span class="rounded-full bg-indigo-600 px-3 py-1 text-xs font-bold text-white">QR</span>
        </div>

        <!-- QR Image -->
        <div class="flex flex-col items-center px-5 py-6">
            <?php if ($qr_img_url): ?>
                <div class="overflow-hidden rounded-3xl bg-white p-3 shadow-inner ring-1 ring-slate-200">
                    <img src="<?php echo esc_url($qr_img_url); ?>"
                         alt="QR رمز الدعوة"
                         class="h-52 w-52 object-contain">
                </div>

                <?php if ($invite_code): ?>
                    <div class="mt-4 flex items-center gap-2 rounded-2xl bg-slate-50 px-5 py-3 ring-1 ring-slate-200">
                        <span class="text-xs text-slate-500">رمز الدعوة:</span>
                        <span class="text-base font-extrabold tracking-widest text-slate-900">
                            <?php echo esc_html($invite_code); ?>
                        </span>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- placeholder إذا لم يكن هناك رمز -->
                <div class="flex h-52 w-52 items-center justify-center rounded-3xl bg-slate-100 ring-1 ring-slate-200">
                    <div class="text-center">
                        <div class="text-4xl">🎟</div>
                        <div class="mt-2 text-xs text-slate-500">لم يُضَف رمز الدعوة بعد</div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- أزرار تحت QR -->
            <div class="mt-5 grid w-full grid-cols-2 gap-3">
                <button type="button"
                    class="js-open-qr flex h-11 items-center justify-center gap-2 rounded-2xl bg-slate-900 text-sm font-semibold text-white hover:bg-slate-700">
                    🔍 تكبير QR
                </button>

                <button type="button"
                    class="js-copy-link flex h-11 items-center justify-center gap-2 rounded-2xl border-2 border-slate-200 bg-white text-sm font-semibold text-slate-800 hover:bg-slate-50"
                    data-copy="<?php echo esc_attr($share_url); ?>">
                    🔗 نسخ الرابط
                </button>
            </div>
        </div>
    </div>

    <!-- ===========================
         تبويبات التفاصيل
    =========================== -->
    <div class="mt-3 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">

        <!-- شريط التبويبات (قابل للتمرير) -->
        <div class="flex gap-2 overflow-x-auto border-b border-slate-100 px-4 py-3 scrollbar-hide">
            <button type="button"
                class="event-tab-btn shrink-0 rounded-xl bg-slate-900 px-5 py-2 text-sm font-bold text-white"
                data-tab="details" aria-selected="true">
                التفاصيل
            </button>

            <button type="button"
                class="event-tab-btn shrink-0 rounded-xl border border-slate-200 bg-white px-5 py-2 text-sm font-semibold text-slate-700"
                data-tab="album" aria-selected="false"
                <?php echo ($can_album_photos || $can_album_video) ? '' : 'disabled'; ?>>
                الألبوم
                <?php if (!($can_album_photos || $can_album_video)): ?>
                    <span class="ms-1 text-[10px] text-slate-400">🔒</span>
                <?php endif; ?>
            </button>

            <button type="button"
                class="event-tab-btn shrink-0 rounded-xl border border-slate-200 bg-white px-5 py-2 text-sm font-semibold text-slate-700"
                data-tab="chat" aria-selected="false"
                <?php echo ($can_public_chat || $can_private_chat) ? '' : 'disabled'; ?>>
                الدردشة
                <?php if (!($can_public_chat || $can_private_chat)): ?>
                    <span class="ms-1 text-[10px] text-slate-400">🔒</span>
                <?php endif; ?>
            </button>
        </div>

        <!-- ─── تبويب: التفاصيل ─── -->
        <div class="event-tab-panel p-5" id="event-tab-details">

            <!-- ملاحظات المضيف -->
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-base">📝</span>
                    <span class="text-sm font-extrabold text-slate-900">ملاحظات المضيف</span>
                </div>

                <div class="mt-3 rounded-2xl bg-slate-50 p-4 text-sm leading-relaxed text-slate-700 ring-1 ring-slate-200">
                    <?php echo $notes !== '' ? esc_html($notes) : '<span class="text-slate-400 italic">لا توجد ملاحظات إضافية حاليًا</span>'; ?>
                </div>
            </div>

            <!-- بطاقات معلومات سريعة -->
            <div class="mt-4 grid grid-cols-3 gap-2">
                <div class="flex flex-col items-center rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                    <span class="text-xl">🚪</span>
                    <span class="mt-1 text-[10px] text-slate-500">الدخول</span>
                    <span class="mt-0.5 text-xs font-bold text-slate-800">بالـ QR</span>
                </div>
                <div class="flex flex-col items-center rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                    <span class="text-xl">🔒</span>
                    <span class="mt-1 text-[10px] text-slate-500">الخصوصية</span>
                    <span class="mt-0.5 text-xs font-bold text-slate-800">للمدعوين</span>
                </div>
                <div class="flex flex-col items-center rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200">
                    <span class="text-xl">✨</span>
                    <span class="mt-1 text-[10px] text-slate-500">المزايا</span>
                    <span class="mt-0.5 text-xs font-bold text-slate-800">حسب الباقة</span>
                </div>
            </div>

        </div>

        <!-- ─── تبويب: الألبوم ─── -->
        <div class="event-tab-panel hidden p-5" id="event-tab-album">
            <?php if (!($can_album_photos || $can_album_video)): ?>
                <div class="flex flex-col items-center py-8 text-center">
                    <span class="text-5xl">🖼</span>
                    <p class="mt-3 text-sm font-semibold text-slate-700">الألبوم غير متاح في باقتك الحالية</p>
                    <p class="mt-1 text-xs text-slate-500">يمكن الترقية لتفعيل رفع الصور والفيديو</p>
                </div>
            <?php else: ?>
                <div class="flex flex-wrap gap-2 mb-4">
                    <button type="button"
                        class="flex h-10 items-center gap-2 rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white"
                        <?php echo $can_album_photos ? '' : 'disabled'; ?>>
                        📷 رفع صورة
                    </button>
                    <button type="button"
                        class="flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-800"
                        <?php echo $can_album_video ? '' : 'disabled'; ?>>
                        🎬 رفع فيديو
                    </button>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="aspect-square rounded-2xl bg-slate-100 ring-1 ring-slate-200"></div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ─── تبويب: الدردشة ─── -->
        <div class="event-tab-panel hidden p-5" id="event-tab-chat">
            <?php if (!($can_public_chat || $can_private_chat)): ?>
                <div class="flex flex-col items-center py-8 text-center">
                    <span class="text-5xl">💬</span>
                    <p class="mt-3 text-sm font-semibold text-slate-700">الدردشة غير متاحة في باقتك الحالية</p>
                    <p class="mt-1 text-xs text-slate-500">يمكن الترقية لتفعيل التواصل بين الضيوف</p>
                </div>
            <?php else: ?>
                <!-- Chat UI -->
                <div class="h-64 space-y-3 overflow-auto rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="flex gap-2">
                        <div class="h-8 w-8 shrink-0 rounded-xl bg-slate-200"></div>
                        <div class="max-w-[75%] rounded-2xl bg-white p-3 ring-1 ring-slate-200">
                            <div class="text-xs font-bold text-slate-800">سارة</div>
                            <div class="mt-1 text-sm text-slate-700">متحمسة جدًا! مبروك 🎉</div>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <div class="max-w-[75%] rounded-2xl bg-slate-900 p-3 text-white">
                            <div class="text-xs font-bold text-white/70">أنت</div>
                            <div class="mt-1 text-sm">الله يبارك فيك 🙏</div>
                        </div>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <input class="h-12 flex-1 rounded-2xl border-2 border-slate-200 bg-white px-4 text-sm outline-none placeholder:text-slate-400 focus:border-indigo-500"
                        placeholder="اكتب رسالة..." />
                    <button class="h-12 w-12 shrink-0 rounded-2xl bg-slate-900 text-lg text-white hover:bg-slate-700">
                        ←
                    </button>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ============================
     Modal: QR مكبّر
============================ -->
<div id="eventQrModal" class="fixed inset-0 z-[999] hidden" dir="rtl">
    <div class="absolute inset-0 bg-slate-950/70 backdrop-blur-sm js-close-qr"></div>

    <div class="absolute inset-x-4 top-1/2 -translate-y-1/2 mx-auto max-w-xs rounded-3xl bg-white p-6 shadow-2xl">
        <div class="flex items-center justify-between mb-5">
            <div class="text-base font-extrabold text-slate-900">بطاقة دخولك 🎟</div>
            <button type="button"
                class="js-close-qr flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-lg text-slate-700 hover:bg-slate-100">
                ✕
            </button>
        </div>

        <div class="flex flex-col items-center rounded-3xl bg-slate-50 p-4 ring-1 ring-slate-200">
            <?php if ($qr_img_url): ?>
                <img src="<?php echo esc_url($qr_img_url); ?>"
                     alt="QR رمز الدعوة"
                     class="h-56 w-56 object-contain rounded-2xl">
            <?php else: ?>
                <div class="flex h-56 w-56 items-center justify-center rounded-2xl bg-slate-200">
                    <span class="text-4xl">🎟</span>
                </div>
            <?php endif; ?>

            <?php if ($invite_code): ?>
                <div class="mt-3 text-center">
                    <div class="text-xs text-slate-500">رمز الدعوة</div>
                    <div class="text-2xl font-extrabold tracking-widest text-slate-900"><?php echo esc_html($invite_code); ?></div>
                </div>
            <?php endif; ?>

            <p class="mt-2 text-xs text-slate-500">اعرضه عند البوابة للدخول السريع</p>
        </div>

        <button type="button"
            class="js-copy-link mt-4 flex h-12 w-full items-center justify-center gap-2 rounded-2xl border-2 border-slate-200 bg-white text-sm font-semibold text-slate-800 hover:bg-slate-50"
            data-copy="<?php echo esc_attr($share_url); ?>">
            🔗 نسخ رابط الدعوة
        </button>
    </div>
</div>

<script>
(function() {
    // ─── التبويبات ───
    const btns   = document.querySelectorAll('.event-tab-btn');
    const panels = {
        details: document.getElementById('event-tab-details'),
        album:   document.getElementById('event-tab-album'),
        chat:    document.getElementById('event-tab-chat'),
    };

    function activate(tab) {
        Object.keys(panels).forEach(function(key) {
            if (!panels[key]) return;
            panels[key].classList.toggle('hidden', key !== tab);
        });
        btns.forEach(function(b) {
            const active = b.dataset.tab === tab;
            b.setAttribute('aria-selected', active ? 'true' : 'false');
            if (active) {
                b.className = 'event-tab-btn shrink-0 rounded-xl bg-slate-900 px-5 py-2 text-sm font-bold text-white';
            } else {
                b.className = 'event-tab-btn shrink-0 rounded-xl border border-slate-200 bg-white px-5 py-2 text-sm font-semibold text-slate-700 ' + (b.disabled ? 'opacity-40 cursor-not-allowed' : 'hover:bg-slate-50');
                if (!($can_album_photos || $can_album_video) && b.dataset.tab === 'album') {
                    b.className += ' opacity-40 cursor-not-allowed';
                }
            }
        });
    }

    btns.forEach(function(b) {
        b.addEventListener('click', function() {
            if (b.disabled) return;
            activate(b.dataset.tab);
        });
    });

    activate('details');

    // ─── QR Modal ───
    const modal = document.getElementById('eventQrModal');
    document.querySelectorAll('.js-open-qr').forEach(function(el) {
        el.addEventListener('click', function() { if (modal) modal.classList.remove('hidden'); });
    });
    document.querySelectorAll('.js-close-qr').forEach(function(el) {
        el.addEventListener('click', function() { if (modal) modal.classList.add('hidden'); });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal) modal.classList.add('hidden');
    });

    // ─── نسخ الرابط ───
    document.querySelectorAll('.js-copy-link').forEach(function(el) {
        el.addEventListener('click', async function() {
            const txt = el.getAttribute('data-copy') || '';
            if (!txt) return;
            try { await navigator.clipboard.writeText(txt); }
            catch(e) {
                const ta = document.createElement('textarea');
                ta.value = txt; document.body.appendChild(ta);
                ta.select(); document.execCommand('copy'); ta.remove();
            }
            const orig = el.innerHTML;
            el.innerHTML = '✅ تم النسخ';
            setTimeout(function() { el.innerHTML = orig; }, 1500);
        });
    });
})();

const $can_album_photos = <?php echo json_encode($can_album_photos); ?>;
const $can_album_video  = <?php echo json_encode($can_album_video); ?>;
</script>
