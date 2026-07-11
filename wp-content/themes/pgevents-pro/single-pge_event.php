<?php
defined('ABSPATH') || exit;

get_header();

if (have_posts()) :
    while (have_posts()) : the_post(); ?>

        <?php /* الأنماط الموحّدة الآن مُصرَّفة ضمن output.css العام (input.css) —
                لا سكربت/نمط مكرر هنا. راجع assets/css/input.css. */ ?>

        <main id="primary" class="min-h-screen bg-background pb-6" dir="rtl">

            <?php
            // ✅ Access Gate أولاً — يوقف العرض إذا لم يُسمح بالدخول
            $allowed = include locate_template('template-parts/event/access-gate.php');

            if (!$allowed) {
                echo '</main>';
                get_footer();
                exit;
            }
            ?>

            <!-- خلفية الصفحة: تدرّج ناعم من الأعلى -->
            <div class="pointer-events-none fixed inset-0 -z-10 bg-gradient-to-b from-primary/5 via-background to-background"></div>

            <!-- ============================================================
                 حاوية الصفحة العريضة — تستخدم عرض الشاشة فعلياً على الحاسوب
                 بدل عمود ضيّق واحد في المنتصف. أسفل lg: عمود واحد مكدَّس
                 (الجوال/التابلت). من lg وأعلى: عمودان — الدعوة (الأكبر) +
                 عمود جانبي (RSVP/QR/التفاصيل).
            ============================================================= -->
            <div class="mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8">
                <div class="lg:grid lg:grid-cols-[minmax(0,1.3fr)_minmax(360px,0.7fr)] lg:items-start lg:gap-8 xl:gap-10">

                    <!-- العمود الرئيسي: الدعوة نفسها (العنوان + العمل الفني + التاريخ/الوقت/الموقع + العدّاد) -->
                    <div class="min-w-0">
                        <?php get_template_part('template-parts/event/hero'); ?>
                    </div>

                    <!-- العمود الجانبي: RSVP أولاً (الإجراء الأساسي) ثم QR/المشاركة/التفاصيل
                         — إزاحة علوية خفيفة على lg تُحاذي أول بطاقة في العمود الجانبي مع
                         بطاقة العمل الفني في العمود الرئيسي (بدل محاذاتها مع نص الترويسة
                         فوقها)؛ القيمة (8) مأخوذة من نفس مقياس التباعد المستخدم أصلاً بين
                         العمودين (lg:gap-8) وليست عشوائية -->
                    <div class="mt-6 min-w-0 lg:mt-8 lg:self-start">
                        <?php
                        get_template_part('template-parts/event/rsvp');
                        get_template_part('template-parts/event/tabs');
                        ?>
                    </div>

                </div>
            </div>

        </main>

<?php endwhile;
endif;

get_footer();
