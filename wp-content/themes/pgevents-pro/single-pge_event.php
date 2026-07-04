<?php
defined('ABSPATH') || exit;

get_header();

if (have_posts()) :
    while (have_posts()) : the_post(); ?>

        <?php /* ── CSS utilities missing from compiled output.css (added after last npm run build) ── */ ?>
        <style>
        /* sizing */
        .max-w-sm{max-width:24rem}.max-w-lg{max-width:32rem}
        .h-14{height:3.5rem}.w-14{width:3.5rem}
        .h-52{height:13rem}.w-52{width:13rem}
        /* misc */
        .aspect-square{aspect-ratio:1/1}
        .scrollbar-hide{scrollbar-width:none}.scrollbar-hide::-webkit-scrollbar{display:none}
        .backdrop-blur-sm{-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px)}
        @keyframes pge-pulse{0%,100%{opacity:1}50%{opacity:.5}}
        .animate-pulse{animation:pge-pulse 2s ease-in-out infinite}
        /* ring-2 */
        .ring-2{outline:2px solid var(--pge-ring,transparent);outline-offset:0}
        .ring-emerald-600{--pge-ring:#059669}.ring-slate-800{--pge-ring:#1e293b}
        /* active scale */
        .active\:scale-\[\.97\]:active{transform:scale(.97)}
        .active\:scale-\[\.98\]:active{transform:scale(.98)}
        /* gradient: bg-gradient-to-l + from-indigo-* + to-violet-* */
        .bg-gradient-to-l{background-image:linear-gradient(to left,var(--pge-from,transparent),var(--pge-to,transparent))}
        .from-indigo-600{--pge-from:#4f46e5}
        .to-violet-600{--pge-to:#7c3aed}.to-violet-700{--pge-to:#6d28d9}
        .hover\:from-indigo-500:hover{--pge-from:#6366f1}
        .hover\:to-violet-500:hover{--pge-to:#8b5cf6}
        </style>

        <main id="primary" class="min-h-screen bg-slate-50 pb-6" dir="rtl">

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
            <div class="pointer-events-none fixed inset-0 -z-10 bg-gradient-to-b from-indigo-50/60 via-slate-50 to-slate-50"></div>

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
