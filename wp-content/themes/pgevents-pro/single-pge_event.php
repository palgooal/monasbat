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

            <?php
            // ✅ القسم الرئيسي (Hero + معلومات + عداد + أزرار)
            get_template_part('template-parts/event/hero');

            // ✅ بطاقة QR + التبويبات (التفاصيل / الألبوم / الدردشة)
            get_template_part('template-parts/event/tabs');

            // ✅ نموذج RSVP
            get_template_part('template-parts/event/rsvp');
            ?>

        </main>

<?php endwhile;
endif;

get_footer();
