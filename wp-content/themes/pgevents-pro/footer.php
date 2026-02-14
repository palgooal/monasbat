<?php if (!function_exists('elementor_theme_do_location') || !elementor_theme_do_location('footer')) : ?>
<footer class="border-t border-slate-200 bg-white">
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="text-sm font-extrabold">مناسبات</div>
                <p class="mt-2 text-sm text-slate-600">
                    منصّة لإنشاء دعوات مناسبات احترافية مع RSVP، QR، وتنظيم ذكي.
                </p>
            </div>

            <div>
                <div class="text-sm font-bold">المنتج</div>
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    <li><a href="#how" class="hover:text-slate-900">كيف تعمل</a></li>
                    <li><a href="#pricing" class="hover:text-slate-900">الباقات</a></li>
                    <li><a href="#faq" class="hover:text-slate-900">الأسئلة الشائعة</a></li>
                </ul>
            </div>

            <div>
                <div class="text-sm font-bold">الحساب</div>
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    <?php if (is_user_logged_in()) : ?>
                        <li><a href="<?php echo esc_url(home_url('/dashboard/')); ?>" class="hover:text-slate-900">لوحة التحكم</a></li>
                    <?php else : ?>
                        <li><a href="<?php echo esc_url(wp_login_url()); ?>" class="hover:text-slate-900">تسجيل الدخول</a></li>
                        <li><a href="<?php echo esc_url(wp_registration_url()); ?>" class="hover:text-slate-900">إنشاء حساب</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div>
                <div class="text-sm font-bold">قانوني</div>
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    <li><a href="#" class="hover:text-slate-900">سياسة الخصوصية</a></li>
                    <li><a href="#" class="hover:text-slate-900">الشروط والأحكام</a></li>
                </ul>
            </div>
        </div>

        <div class="mt-8 border-t border-slate-200 pt-6 text-center text-sm text-slate-500">
            © <?php echo date('Y'); ?> جميع الحقوق محفوظة — منصة مناسبات
        </div>

    </div>
</footer>
<?php endif; ?>

<?php wp_footer(); ?>
</body>

</html>
