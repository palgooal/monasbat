<?php if (!function_exists('elementor_theme_do_location') || !elementor_theme_do_location('footer')) : ?>
<footer class="border-t border-gold/20 bg-footer font-arabic" dir="rtl">
    <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-10">

        <div class="mb-10 leading-tight">
            <div class="font-arabic text-xl font-extrabold text-gold-text">حلوة</div>
            <div class="-mt-1 text-[10px] font-semibold tracking-[0.25em] text-gold-text/70">HILWAH</div>
        </div>

        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="text-[15px] font-extrabold text-foreground">الشركة</div>
                <ul class="mt-4 space-y-3 text-sm text-foreground/60">
                    <li><a href="<?php echo esc_url(home_url('/#about')); ?>" class="transition-colors hover:text-primary">من نحن</a></li>
                    <li><a href="<?php echo esc_url(home_url('/#contact')); ?>" class="transition-colors hover:text-primary">تواصل معنا</a></li>
                    <li><a href="<?php echo esc_url(home_url('/#faq')); ?>" class="transition-colors hover:text-primary">الأسئلة الشائعة</a></li>
                </ul>
            </div>

            <div>
                <div class="text-[15px] font-extrabold text-foreground">المناسبات</div>
                <ul class="mt-4 space-y-3 text-sm text-foreground/60">
                    <li><a href="#" class="transition-colors hover:text-primary">حفلات الزفاف</a></li>
                    <li><a href="#" class="transition-colors hover:text-primary">أعياد الميلاد</a></li>
                    <li><a href="#" class="transition-colors hover:text-primary">حفلات التخرج</a></li>
                    <li><a href="#" class="transition-colors hover:text-primary">جميع المناسبات</a></li>
                </ul>
            </div>

            <div>
                <div class="text-[15px] font-extrabold text-foreground">قانوني</div>
                <ul class="mt-4 space-y-3 text-sm text-foreground/60">
                    <li><a href="#" class="transition-colors hover:text-primary">سياسة الخصوصية</a></li>
                    <li><a href="#" class="transition-colors hover:text-primary">الشروط والأحكام</a></li>
                </ul>
            </div>

            <div>
                <div class="text-[15px] font-extrabold text-foreground">تابعنا</div>
                <div class="mt-4 flex items-center gap-3">
                    <a href="#" aria-label="Instagram" class="flex h-10 w-10 items-center justify-center rounded-full border border-border bg-white text-foreground/60 transition-colors hover:border-primary hover:text-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5">
                            <rect x="3" y="3" width="18" height="18" rx="5"></rect>
                            <circle cx="12" cy="12" r="4"></circle>
                            <circle cx="17.2" cy="6.8" r="0.8" fill="currentColor" stroke="none"></circle>
                        </svg>
                    </a>
                    <a href="#" aria-label="TikTok" class="flex h-10 w-10 items-center justify-center rounded-full border border-border bg-white text-foreground/60 transition-colors hover:border-primary hover:text-primary">
                        <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5">
                            <path d="M14 3c.3 2 1.8 3.6 4 3.9v2.6c-1.4 0-2.8-.4-4-1.2v6.1a5.6 5.6 0 1 1-5.6-5.6c.3 0 .6 0 .9.1v2.7a2.9 2.9 0 1 0 2 2.8V3h2.7Z" />
                        </svg>
                    </a>
                    <a href="#" aria-label="X" class="flex h-10 w-10 items-center justify-center rounded-full border border-border bg-white text-foreground/60 transition-colors hover:border-primary hover:text-primary">
                        <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                            <path d="M18.9 3H21l-6.3 7.2L22 21h-6.1l-4.8-6.3L5.6 21H3.5l6.7-7.6L3 3h6.2l4.3 5.8L18.9 3Zm-1.1 16.1h1.2L7.3 4.8H6L17.8 19.1Z" />
                        </svg>
                    </a>
                    <a href="#" aria-label="WhatsApp" class="flex h-10 w-10 items-center justify-center rounded-full border border-border bg-white text-foreground/60 transition-colors hover:border-primary hover:text-primary">
                        <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5">
                            <path d="M12 3a9 9 0 0 0-7.7 13.6L3 21l4.5-1.2A9 9 0 1 0 12 3Zm0 1.8a7.2 7.2 0 0 1 6.1 11l-.2.4.1.4.7 2.5-2.6-.7-.4-.1-.4.2a7.2 7.2 0 1 1-3.3-13.7Zm-2.6 3.6c-.2 0-.5 0-.7.4-.2.3-.9.9-.9 2.2s.9 2.5 1 2.7c.1.2 1.8 2.8 4.4 3.8 2.2.9 2.6.7 3.1.6.5 0 1.6-.6 1.8-1.3.2-.6.2-1.2.2-1.3-.1-.1-.3-.2-.6-.4-.3-.2-1.6-.8-1.9-.9-.3-.1-.4-.1-.6.1-.2.3-.7.9-.8 1-.2.2-.3.2-.6.1-.3-.2-1.2-.5-2.3-1.4-.9-.8-1.4-1.7-1.6-2-.2-.3 0-.5.1-.6l.4-.5c.1-.2.2-.3.2-.5.1-.2 0-.4 0-.5-.1-.1-.6-1.6-.9-2.1-.2-.5-.4-.4-.6-.4Z" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>

        <div class="mt-12 flex flex-col items-center gap-3 border-t border-border pt-6 text-sm text-foreground/50 sm:flex-row sm:justify-between">
            <div>© حلوة <?php echo esc_html(date('Y')); ?> — جميع الحقوق محفوظة</div>
            <div class="flex items-center gap-1.5">
                صنع في المملكة العربية السعودية
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5 text-primary">
                    <path d="M12 21s-7.5-4.6-10-9.3C.4 8.4 2.2 5 5.6 5c2 0 3.5 1.1 4.4 2.6C10.9 6.1 12.4 5 14.4 5c3.4 0 5.2 3.4 3.6 6.7C19.5 16.4 12 21 12 21Z" />
                </svg>
            </div>
        </div>

    </div>
</footer>
<?php endif; ?>

<?php wp_footer(); ?>
</body>

</html>
