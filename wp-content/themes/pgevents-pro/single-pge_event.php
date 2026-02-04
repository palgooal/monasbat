<?php
defined('ABSPATH') || exit;

get_header();

if (have_posts()) :
    while (have_posts()) : the_post(); ?>

        <main id="primary" class="min-h-screen bg-slate-50 text-slate-900">

            <?php
            // ✅ Access Gate must run FIRST and must STOP rendering
            $allowed = include locate_template('template-parts/event/access-gate.php');

            if (!$allowed) {
                // تم عرض شاشة الدخول داخل access-gate.php
                // ✅ نوقف الصفحة هنا
                echo '</main>';
                get_footer();
                exit;
            }

            // ✅ Hero + Summary
            get_template_part('template-parts/event/hero');

            // ✅ Tabs (Details / Album / Chat)
            get_template_part('template-parts/event/tabs');

            // ✅ RSVP
            get_template_part('template-parts/event/rsvp');
            ?>

        </main>

<?php endwhile;
endif;

get_footer();
