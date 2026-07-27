<?php
defined('ABSPATH') || exit;

/**
 * ============================================================
 * Entry Card — بطاقة دخولك (QR + رمز الدعوة + مشاركة) + Modal التكبير
 * ============================================================
 * استُخرِجت حرفياً (بلا أي تغيير في المنطق أو الترميز أو الأنماط) من
 * template-parts/event/tabs.php القديم، الذي كان يجمع هذه البطاقة مع
 * مكوّن التبويبات (تفاصيل/ألبوم/دردشة) معاً — ما كان يمنع نقل أحدهما بمعزل
 * عن الآخر. هذا الملف الآن مسؤولية واحدة فقط: بطاقة الدخول ونافذة تكبير QR
 * المرتبطة بها (نفس أزرار js-open-qr/js-close-qr/js-share-wa/js-copy-link،
 * بلا أي تعديل على assets/js/event.js).
 *
 * لا اعتماد هنا على أي متغيّر خاص بمكوّن التبويبات (لا $limits، لا
 * $can_album_photos، لا $can_public_chat، لا $notes) — فقط ما تحتاجه بطاقة
 * الدخول فعلياً: رمز الدعوة، رابط QR، ورابط المشاركة.
 */

$event_id = get_the_ID();

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

<?php // السلوك (JS) موحَّد بالكامل في assets/js/event.js — لا سكربت مكرر هنا. ?>
