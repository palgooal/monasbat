<section id="features" class="py-12 sm:py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="max-w-2xl">
                <div class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                    <span class="h-2 w-2 rounded-full bg-indigo-500"></span>
                    مميزات مصممة لرفع الحضور وتقليل العشوائية
                </div>

                <h2 class="mt-3 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">
                    كل ما تحتاجه لإدارة مناسبة بشكل احترافي
                </h2>

                <p class="mt-2 text-sm leading-6 text-slate-600">
                    من صفحة ضيف أنيقة إلى جمع الردود والتذكيرات… منصّة مناسبات تختصر عليك الوقت وتزيد وضوح التنظيم.
                </p>
            </div>

            <a href="<?php echo esc_url(is_user_logged_in() ? home_url('/create-event/') : wp_login_url(home_url('/create-event/'))); ?>"
                class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-extrabold text-white shadow-sm hover:bg-slate-800">
                إنشاء مناسبة الآن
                <span class="ms-2 opacity-80">➜</span>
            </a>
        </div>

        <!-- Features grid -->
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <!-- Feature 1 -->
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="grid h-11 w-11 place-items-center rounded-2xl bg-indigo-50 text-indigo-700 ring-1 ring-indigo-100">
                        ✓
                    </div>
                    <div>
                        <div class="text-sm font-extrabold">RSVP محفوظ ومنظم</div>
                        <p class="mt-1 text-sm text-slate-600">
                            اجمع أسماء الضيوف + أرقامهم + المرافقين + الملاحظات… ووداعًا للعشوائية في واتساب.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Feature 2 -->
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="grid h-11 w-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100">
                        ⏱
                    </div>
                    <div>
                        <div class="text-sm font-extrabold">عداد وقت + تذكيرات</div>
                        <p class="mt-1 text-sm text-slate-600">
                            الضيف يعرف الموعد بدقة، والمضيف يرتاح: تجربة “واضحة” تقلل الاستفسارات والتأخير.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Feature 3 -->
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="grid h-11 w-11 place-items-center rounded-2xl bg-slate-100 text-slate-700 ring-1 ring-slate-200">
                        ⛶
                    </div>
                    <div>
                        <div class="text-sm font-extrabold">QR للدخول والتحقق</div>
                        <p class="mt-1 text-sm text-slate-600">
                            دخول أسرع وتنظيم أفضل عند البوابة—مناسب للحفلات والفعاليات والندوات.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Feature 4 -->
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="grid h-11 w-11 place-items-center rounded-2xl bg-amber-50 text-amber-700 ring-1 ring-amber-100">
                        ✦
                    </div>
                    <div>
                        <div class="text-sm font-extrabold">مشاركة فورية</div>
                        <p class="mt-1 text-sm text-slate-600">
                            مشاركة عبر واتساب، نسخ رابط، أو مشاركة النظام… بدون تعقيد وبنقرة واحدة.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Feature 5 -->
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="grid h-11 w-11 place-items-center rounded-2xl bg-fuchsia-50 text-fuchsia-700 ring-1 ring-fuchsia-100">
                        🖼
                    </div>
                    <div>
                        <div class="text-sm font-extrabold">ألبوم صور وفيديو</div>
                        <p class="mt-1 text-sm text-slate-600">
                            اجمع أجمل اللحظات من الضيوف في مكان واحد (مع صلاحيات حسب إعدادات المضيف).
                        </p>
                    </div>
                </div>
            </div>

            <!-- Feature 6 -->
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="grid h-11 w-11 place-items-center rounded-2xl bg-cyan-50 text-cyan-700 ring-1 ring-cyan-100">
                        ⚙
                    </div>
                    <div>
                        <div class="text-sm font-extrabold">إعدادات خصوصية مرنة</div>
                        <p class="mt-1 text-sm text-slate-600">
                            مناسبة خاصة أو عامة؟ تحكم في الألبوم، الدردشة، وإظهار الأعداد… حسب احتياجك.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trust strip -->
        <div class="mt-8 rounded-3xl border border-slate-200 bg-white p-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs text-slate-500">مصمم للمضيف</div>
                    <div class="mt-1 text-sm font-extrabold">إدارة سهلة من لوحة واحدة</div>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs text-slate-500">مصمم للضيف</div>
                    <div class="mt-1 text-sm font-extrabold">تجربة واضحة ومريحة للجوال</div>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <div class="text-xs text-slate-500">مصمم للأمان</div>
                    <div class="mt-1 text-sm font-extrabold">Nonce + صلاحيات + حماية بيانات</div>
                </div>
            </div>
        </div>

    </div>
</section>