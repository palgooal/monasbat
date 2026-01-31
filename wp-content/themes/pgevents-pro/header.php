<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="<?php echo pge_direction(); ?>">

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
    <style>
        /* تخصيص الخطوط بناءً على اللغة */
        :lang(ar) {
            font-family: 'Cairo', sans-serif;
        }

        :lang(en) {
            font-family: 'Inter', sans-serif;
        }

        .nav-link {
            transition: all 0.3s ease;
            position: relative;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--color-pg-primary, #2271b1);
            transition: width 0.3s;
        }

        .nav-link:hover::after {
            width: 100%;
        }
    </style>
</head>

<body <?php body_class('bg-gray-50 ' . pge_align()); ?>>
    <?php wp_body_open(); ?>

    <header class="bg-white/90 backdrop-blur-md sticky top-0 z-50 border-b border-gray-100 shadow-sm">
        <div class="container mx-auto px-4 h-20 flex items-center justify-between">

            <div class="flex items-center <?php echo is_rtl() ? 'order-last' : 'order-first'; ?>">
                <a href="<?php echo home_url(); ?>" class="text-2xl font-black text-slate-800 flex items-center gap-2">
                    <span class="text-blue-600 tracking-tighter italic">PgEvents</span><span class="font-light text-slate-400">Pro</span>
                </a>
            </div>

            <nav class="hidden md:flex items-center gap-8 text-sm font-bold text-slate-600">
                <a href="<?php echo home_url(); ?>" class="nav-link hover:text-blue-600">الرئيسية</a>
                <a href="<?php echo home_url('/events'); ?>" class="nav-link hover:text-blue-600">تصفح المناسبات</a>
                <a href="<?php echo home_url('/prices'); ?>" class="nav-link hover:text-blue-600">باقات الاشتراك</a>
                <a href="<?php echo home_url('/support'); ?>" class="nav-link hover:text-blue-600">الدعم الفني</a>
            </nav>

            <div class="flex items-center gap-4">
                <?php if (is_user_logged_in()) :
                    $user = wp_get_current_user(); ?>
                    <div class="flex items-center gap-2 bg-slate-100 px-3 py-1.5 rounded-full border border-slate-200">
                        <span class="text-xs font-bold text-slate-700"><?php echo esc_html($user->display_name); ?></span>
                        <div class="w-7 h-7 bg-blue-600 text-white rounded-full flex items-center justify-center text-[10px]">
                            <?php echo strtoupper(substr($user->user_login, 0, 1)); ?>
                        </div>
                    </div>
                <?php else : ?>
                    <a href="<?php echo wp_login_url(); ?>" class="text-sm font-bold text-slate-500 hover:text-blue-600">دخول</a>
                    <a href="<?php echo home_url('/prices'); ?>" class="bg-blue-600 text-white px-5 py-2 rounded-xl text-sm font-bold shadow-lg shadow-blue-200 hover:scale-105 transition-transform">
                        ابدأ الآن
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if (!is_admin()) : ?>
        <div class="bg-slate-50 border-b border-slate-100 py-2">
            <div class="container mx-auto px-4 text-center">
                <p class="text-[11px] text-slate-500 font-medium">
                    ✨ هل ترغب في زيادة عدد الصور؟ <a href="<?php echo home_url('/prices'); ?>" class="text-blue-600 font-bold hover:underline">قم بترقية باقتك الآن</a> واستمتع بمميزات إضافية!
                </p>
            </div>
        </div>
    <?php endif; ?>