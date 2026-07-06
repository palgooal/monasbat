<!doctype html>
<html <?php language_attributes(); ?> dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        /* يمنع سكرول أفقي غير مرغوب فيه على مستوى الموقع بالكامل، ناتج عن عناصر توضع خارج الشاشة
           مثل رابط "Skip to content" (hfe-skip-link) الذي يوضع عند left:-9999px بدون تحجيم. */
        html, body { overflow-x: hidden; max-width: 100%; }
    </style>

    <?php wp_head(); ?>
</head>

<body <?php body_class('bg-background text-foreground font-arabic'); ?>>
    <?php wp_body_open(); ?>

    <?php if (function_exists('elementor_theme_do_location') && elementor_theme_do_location('header')) : ?>
        <?php return; ?>
    <?php endif; ?>

    <header class="sticky top-0 z-50 border-b border-gold/25 bg-white font-arabic" dir="rtl">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-5 sm:px-6 lg:px-10 lg:py-6">

            <!-- Logo / Brand: حلوة (HILWAH) -->
            <a href="<?php echo esc_url(home_url('/')); ?>" class="flex shrink-0 items-center gap-2 py-1">
                <div class="leading-tight">
                    <div class="font-arabic text-2xl font-extrabold text-gold-text">حلوة</div>
                    <div class="-mt-1 text-[10px] font-semibold tracking-[0.25em] text-gold-text/70">HILWAH</div>
                </div>
            </a>

            <!-- Navigation -->
            <nav class="hidden items-center gap-7 text-[15px] font-semibold text-foreground/70 lg:flex">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="transition-colors hover:text-primary">
                    الرئيسية
                </a>
                <a href="<?php echo esc_url(home_url('/#events')); ?>" class="transition-colors hover:text-primary">
                    المناسبات
                </a>
                <a href="<?php echo esc_url(home_url('/#features')); ?>" class="transition-colors hover:text-primary">
                    المميزات
                </a>
                <a href="<?php echo esc_url(home_url('/#pricing')); ?>" class="transition-colors hover:text-primary">
                    الباقات
                </a>
                <a href="<?php echo esc_url(home_url('/#faq')); ?>" class="transition-colors hover:text-primary">
                    الأسئلة الشائعة
                </a>
                <a href="<?php echo esc_url(home_url('/#contact')); ?>" class="transition-colors hover:text-primary">
                    تواصل معنا
                </a>
            </nav>

            <!-- Actions -->
            <div class="flex shrink-0 items-center gap-3">
                <?php if (is_user_logged_in()) : ?>
                    <a href="<?php echo esc_url(home_url('/dashboard/')); ?>"
                        class="rounded-2xl bg-primary px-5 py-2.5 text-sm font-bold text-white transition-colors duration-200 hover:bg-primary-hover">
                        لوحة التحكم
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url(wp_login_url(home_url('/create-event/'))); ?>"
                        class="rounded-2xl bg-primary px-5 py-2.5 text-sm font-bold text-white transition-colors duration-200 hover:bg-primary-hover">
                        ابدأ الآن
                    </a>
                <?php endif; ?>

                <a href="<?php echo esc_url(is_user_logged_in() ? home_url('/dashboard/') : add_query_arg('redirect_to', home_url('/dashboard/'), wp_registration_url())); ?>"
                    class="hidden rounded-2xl border-[1.5px] border-gold bg-white px-5 py-2 text-sm font-bold text-gold transition-colors duration-200 hover:bg-gold/[0.06] sm:inline-flex">
                    إنشاء حساب
                </a>
            </div>

        </div>
    </header>
