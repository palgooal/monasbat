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

// عبر الدالة المركزية حصراً (Catalog-aware/Legacy-aware) بدل استدعاء
// PGE_Packages::get_user_plan_limits() مباشرة — كانت تُهمِل ميزات مضيف
// Catalog بالكامل لأنها لا تعرف إلا مفاتيح Legacy.
if ($author_id > 0 && function_exists('pge_get_user_plan_limits_for_events')) {
    $limits = array_merge($limits, (array) pge_get_user_plan_limits_for_events($author_id));
}

$feature_on = static function ($limits, $key) {
    return function_exists('pge_plan_feature_enabled_for_events')
        ? pge_plan_feature_enabled_for_events($limits, $key)
        : !empty($limits[$key]);
};

$can_album_photos = $feature_on($limits, 'guest_photos');
$can_album_video  = $feature_on($limits, 'guest_video');
$can_public_chat  = $feature_on($limits, 'public_chat');
$can_private_chat = $feature_on($limits, 'private_chat');

// =============================
// Data
// =============================
$notes           = trim(wp_strip_all_tags((string) get_post_meta($event_id, '_pge_event_notes', true)));
$invite_code_raw = (string) get_post_meta($event_id, '_pge_invite_code', true);
$invite_code     = function_exists('pge_normalize_invite_code')
    ? pge_normalize_invite_code($invite_code_raw)
    : strtoupper(trim($invite_code_raw));

// معلومات الموقع (لعرضها في قسم "الموقع" ضمن التفاصيل)
$event_address = (string) get_post_meta($event_id, '_pge_event_address',  true);
$map_url       = (string) get_post_meta($event_id, '_pge_event_location', true);
$has_location  = ($map_url !== '' || $event_address !== '');

$share_url = get_permalink($event_id);

// QR الحقيقي من invite_code
$qr_img_url = '';
if ($invite_code !== '' && function_exists('pge_generate_qr_url')) {
    $qr_img_url = pge_generate_qr_url($invite_code, 320);
}
?>

<div class="w-full pt-4 pb-4" dir="rtl">

    <!-- ===========================
         بطاقة الدخول (QR + رمز الدعوة + مشاركة)
         — كل ما يخص "الدخول والمشاركة" مجمّع هنا في مكان واحد بدل تشتيته
    =========================== -->
    <div class="overflow-hidden rounded-[28px] border border-border/70 bg-white shadow-[0_1px_3px_rgba(20,10,10,0.04)]">

        <!-- رأس البطاقة -->
        <div class="flex items-center justify-between border-b border-border/70 px-5 py-4">
            <div>
                <div class="text-lg font-extrabold text-foreground">بطاقة دخولك 🎟</div>
                <div class="mt-0.5 text-xs text-foreground/75">أرِها عند الباب للدخول السريع</div>
            </div>
            <span class="rounded-full bg-primary px-3 py-1 text-xs font-bold text-white">QR</span>
        </div>

        <!-- QR Image -->
        <div class="flex flex-col items-center px-5 py-5">
            <?php if ($qr_img_url): ?>
                <div class="overflow-hidden rounded-3xl bg-white p-3 shadow-inner ring-1 ring-border">
                    <img src="<?php echo esc_url($qr_img_url); ?>"
                         alt="QR رمز الدعوة"
                         class="h-44 w-44 object-contain">
                </div>

                <?php if ($invite_code): ?>
                    <div class="mt-3 flex items-center gap-2 rounded-2xl bg-secondary/60 px-5 py-3 ring-1 ring-border">
                        <span class="text-xs text-foreground/75">رمز الدعوة:</span>
                        <span class="text-base font-extrabold tracking-widest text-foreground">
                            <?php echo esc_html($invite_code); ?>
                        </span>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- placeholder إذا لم يكن هناك رمز -->
                <div class="flex h-44 w-44 items-center justify-center rounded-3xl bg-secondary/60 ring-1 ring-border">
                    <div class="text-center">
                        <div aria-hidden="true" class="text-4xl">🎟</div>
                        <div class="mt-2 text-xs text-foreground/75">لم يُضَف رمز الدعوة بعد</div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- أزرار الدخول والمشاركة — كل الإجراءات المرتبطة مجمّعة معاً -->
            <div class="mt-4 grid w-full grid-cols-2 gap-3">
                <button type="button"
                    class="js-open-qr flex h-11 items-center justify-center gap-2 rounded-2xl bg-foreground text-sm font-semibold text-white hover:opacity-90">
                    🔍 تكبير QR
                </button>

                <button type="button"
                    class="js-share-wa flex h-11 items-center justify-center gap-2 rounded-2xl border border-border bg-white text-sm font-semibold text-foreground/80 hover:bg-secondary/40"
                    data-title="<?php echo esc_attr(get_the_title()); ?>"
                    data-url="<?php echo esc_attr($share_url); ?>">
                    <span aria-hidden="true">📲</span> واتساب
                </button>

                <button type="button"
                    class="js-copy-link col-span-2 flex h-11 items-center justify-center gap-2 rounded-2xl border border-border bg-white text-sm font-semibold text-foreground/80 hover:bg-secondary/40"
                    data-copy="<?php echo esc_attr($share_url); ?>">
                    🔗 نسخ رابط الدعوة
                </button>
            </div>
        </div>
    </div>

    <!-- ===========================
         تبويبات إضافية (الألبوم / الدردشة)
         (الموقع مُدرَج أصلاً ضمن بطاقة الدعوة الرئيسية أعلى الصفحة — لا داعي لتكراره هنا)
    =========================== -->
    <div class="mt-4 overflow-hidden rounded-[28px] border border-border/70 bg-white shadow-[0_1px_3px_rgba(20,10,10,0.04)]">

        <!-- شريط التبويبات (قابل للتمرير) -->
        <div class="flex gap-2 overflow-x-auto border-b border-border/70 px-4 py-3 scrollbar-hide">
            <button type="button"
                class="event-tab-btn flex h-11 shrink-0 items-center rounded-xl bg-foreground px-5 text-sm font-bold text-white"
                data-tab="details" aria-selected="true">
                التفاصيل
            </button>

            <button type="button"
                class="event-tab-btn flex h-11 shrink-0 items-center rounded-xl border border-border bg-white px-5 text-sm font-semibold text-foreground/80"
                data-tab="album" aria-selected="false"
                <?php echo ($can_album_photos || $can_album_video) ? '' : 'disabled'; ?>>
                الألبوم
                <?php if (!($can_album_photos || $can_album_video)): ?>
                    <span aria-hidden="true" class="ms-1 text-[10px] text-foreground/75">🔒</span>
                <?php endif; ?>
            </button>

            <button type="button"
                class="event-tab-btn flex h-11 shrink-0 items-center rounded-xl border border-border bg-white px-5 text-sm font-semibold text-foreground/80"
                data-tab="chat" aria-selected="false"
                <?php echo ($can_public_chat || $can_private_chat) ? '' : 'disabled'; ?>>
                الدردشة
                <?php if (!($can_public_chat || $can_private_chat)): ?>
                    <span aria-hidden="true" class="ms-1 text-[10px] text-foreground/75">🔒</span>
                <?php endif; ?>
            </button>
        </div>

        <!-- ─── تبويب: التفاصيل ─── -->
        <div class="event-tab-panel p-5" id="event-tab-details">
            <div class="grid grid-cols-3 gap-2">
                <div class="flex flex-col items-center rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                    <span aria-hidden="true" class="text-xl">🚪</span>
                    <span class="mt-1 text-[10px] text-foreground/75">الدخول</span>
                    <span class="mt-0.5 text-xs font-bold text-foreground">بالـ QR</span>
                </div>
                <div class="flex flex-col items-center rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                    <span aria-hidden="true" class="text-xl">🔒</span>
                    <span class="mt-1 text-[10px] text-foreground/75">الخصوصية</span>
                    <span class="mt-0.5 text-xs font-bold text-foreground">للمدعوين</span>
                </div>
                <div class="flex flex-col items-center rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
                    <span aria-hidden="true" class="text-xl">✨</span>
                    <span class="mt-1 text-[10px] text-foreground/75">المزايا</span>
                    <span class="mt-0.5 text-xs font-bold text-foreground">حسب الباقة</span>
                </div>
            </div>
        </div>

        <!-- ─── تبويب: الألبوم ─── -->
        <div class="event-tab-panel hidden p-5" id="event-tab-album">
            <?php if (!($can_album_photos || $can_album_video)): ?>
                <div class="flex flex-col items-center py-8 text-center">
                    <span aria-hidden="true" class="text-5xl">🖼</span>
                    <p class="mt-3 text-sm font-semibold text-foreground">الألبوم غير متاح في باقتك الحالية</p>
                    <p class="mt-1 text-xs text-foreground/75">يمكن الترقية لتفعيل رفع الصور والفيديو</p>
                </div>
            <?php else: ?>
                <div class="mb-4 flex flex-wrap gap-2">
                    <button type="button"
                        class="flex h-11 items-center gap-2 rounded-xl bg-foreground px-4 text-sm font-semibold text-white"
                        <?php echo $can_album_photos ? '' : 'disabled'; ?>>
                        📷 رفع صورة
                    </button>
                    <button type="button"
                        class="flex h-11 items-center gap-2 rounded-xl border border-border bg-white px-4 text-sm font-semibold text-foreground"
                        <?php echo $can_album_video ? '' : 'disabled'; ?>>
                        🎬 رفع فيديو
                    </button>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="aspect-square rounded-2xl bg-secondary/60 ring-1 ring-border"></div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ─── تبويب: الدردشة ─── -->
        <div class="event-tab-panel hidden p-5" id="event-tab-chat">
            <?php if (!($can_public_chat || $can_private_chat)): ?>
                <div class="flex flex-col items-center py-8 text-center">
                    <span aria-hidden="true" class="text-5xl">💬</span>
                    <p class="mt-3 text-sm font-semibold text-foreground">الدردشة غير متاحة في باقتك الحالية</p>
                    <p class="mt-1 text-xs text-foreground/75">يمكن الترقية لتفعيل التواصل بين الضيوف</p>
                </div>
            <?php else: ?>
                <!-- Chat UI -->
                <div class="h-64 space-y-3 overflow-auto rounded-2xl bg-secondary/60 p-4 ring-1 ring-border">
                    <div class="flex gap-2">
                        <div class="h-8 w-8 shrink-0 rounded-xl bg-secondary"></div>
                        <div class="max-w-[75%] rounded-2xl bg-white p-3 ring-1 ring-border">
                            <div class="text-xs font-bold text-foreground">سارة</div>
                            <div class="mt-1 text-sm text-foreground/80">متحمسة جدًا! مبروك 🎉</div>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <div class="max-w-[75%] rounded-2xl bg-foreground p-3 text-white">
                            <div class="text-xs font-bold text-white/70">أنت</div>
                            <div class="mt-1 text-sm">الله يبارك فيك 🙏</div>
                        </div>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <input class="h-12 flex-1 rounded-2xl border-2 border-border bg-white px-4 text-sm text-foreground outline-none placeholder:text-foreground/50 focus:border-primary"
                        placeholder="اكتب رسالة..." />
                    <button class="h-12 w-12 shrink-0 rounded-2xl bg-foreground text-lg text-white hover:opacity-90">
                        ←
                    </button>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ===========================
         رسالة المضيف — تُخفى بالكامل إن لم توجد رسالة (لا بطاقة فارغة)
    =========================== -->
    <?php if ($notes !== ''): ?>
        <div class="mt-4 overflow-hidden rounded-[28px] border border-border/70 bg-white p-5 shadow-[0_1px_3px_rgba(20,10,10,0.04)]">
            <div class="flex items-center gap-2">
                <span aria-hidden="true" class="text-base">📝</span>
                <span class="text-lg font-extrabold text-foreground">رسالة المضيف</span>
            </div>
            <div class="mt-3 rounded-2xl bg-secondary/60 p-4 text-sm leading-relaxed text-foreground/80 ring-1 ring-border">
                <?php echo nl2br(esc_html($notes)); ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- ============================
     Modal: QR مكبّر
============================ -->
<div id="eventQrModal" class="fixed inset-0 z-[999] hidden" dir="rtl">
    <div class="absolute inset-0 bg-slate-950/70 backdrop-blur-sm js-close-qr"></div>

    <div class="absolute inset-x-4 top-1/2 -translate-y-1/2 mx-auto max-w-xs rounded-3xl bg-white p-6 shadow-2xl">
        <div class="mb-5 flex items-center justify-between">
            <div class="text-lg font-extrabold text-foreground">بطاقة دخولك 🎟</div>
            <button type="button"
                aria-label="إغلاق"
                class="js-close-qr flex h-11 w-11 items-center justify-center rounded-xl border border-border bg-secondary/40 text-lg text-foreground hover:bg-secondary/70">
                ✕
            </button>
        </div>

        <div class="flex flex-col items-center rounded-3xl bg-secondary/60 p-4 ring-1 ring-border">
            <?php if ($qr_img_url): ?>
                <img src="<?php echo esc_url($qr_img_url); ?>"
                     alt="QR رمز الدعوة"
                     class="h-56 w-56 rounded-2xl object-contain">
            <?php else: ?>
                <div class="flex h-56 w-56 items-center justify-center rounded-2xl bg-secondary">
                    <span aria-hidden="true" class="text-4xl">🎟</span>
                </div>
            <?php endif; ?>

            <?php if ($invite_code): ?>
                <div class="mt-3 text-center">
                    <div class="text-xs text-foreground/75">رمز الدعوة</div>
                    <div class="text-2xl font-extrabold tracking-widest text-foreground"><?php echo esc_html($invite_code); ?></div>
                </div>
            <?php endif; ?>

            <p class="mt-2 text-xs text-foreground/75">اعرضه عند البوابة للدخول السريع</p>
        </div>

        <button type="button"
            class="js-copy-link mt-4 flex h-12 w-full items-center justify-center gap-2 rounded-2xl border-2 border-border bg-white text-sm font-semibold text-foreground hover:bg-secondary/40"
            data-copy="<?php echo esc_attr($share_url); ?>">
            🔗 نسخ رابط الدعوة
        </button>
    </div>
</div>

<?php // السلوك (JS) موحَّد بالكامل في assets/js/event.js — لا سكربت مكرر هنا.
// حالة قفل تبويب "الألبوم"/"الدردشة" تُقرأ في event.js مباشرة من خاصية
// disabled على الزر (مضبوطة أدناه عبر PHP) — لا حاجة لتمرير أي متغيّر JS إضافي. ?>
