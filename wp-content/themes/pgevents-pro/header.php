<!doctype html>
<html <?php language_attributes(); ?> dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php wp_head(); ?>
</head>

<body <?php body_class('bg-slate-50 text-slate-900'); ?>>
    <?php wp_body_open(); ?>

    <?php if (function_exists('elementor_theme_do_location') && elementor_theme_do_location('header')) : ?>
        <?php return; ?>
    <?php endif; ?>

    <header class="sticky top-0 z-50 border-b border-slate-200 bg-white/80 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">

            <!-- Logo / Brand -->
            <a href="<?php echo esc_url(home_url('/')); ?>" class="flex items-center gap-3">
                <div class="h-9 w-9 rounded-2xl bg-slate-900"></div>
                <div class="leading-tight">
                    <div class="text-sm font-extrabold">مناسبات</div>
                    <div class="text-xs text-slate-500">دعوات ذكية</div>
                </div>
            </a>

            <!-- Navigation -->
            <nav class="hidden items-center gap-6 text-sm font-semibold text-slate-700 md:flex">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="hover:text-slate-900">
                    الرئيسية
                </a>
                <a href="#how" class="hover:text-slate-900">
                    كيف تعمل
                </a>
                <a href="#pricing" class="hover:text-slate-900">
                    الباقات
                </a>
                <a href="#faq" class="hover:text-slate-900">
                    الأسئلة
                </a>
            </nav>

            <!-- Actions -->
            <div class="flex items-center gap-2">
                <?php if (is_user_logged_in()) : ?>
                    <a href="<?php echo esc_url(home_url('/dashboard/')); ?>"
                        class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                        لوحة التحكم
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url(wp_login_url(home_url('/create-event/'))); ?>"
                        class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                        تسجيل الدخول
                    </a>
                <?php endif; ?>

                <a href="<?php echo esc_url(is_user_logged_in() ? home_url('/create-event/') : wp_login_url(home_url('/create-event/'))); ?>"
                    class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-sm font-extrabold text-white hover:bg-slate-800">
                    إنشاء مناسبة
                    <span class="opacity-80">➜</span>
                </a>
            </div>

        </div>
    </header>
