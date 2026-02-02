<?php
$start_url = is_user_logged_in() ? home_url('/create-event/') : wp_login_url(home_url('/create-event/'));
$dash_url  = is_user_logged_in() ? home_url('/dashboard/') : wp_login_url(home_url('/dashboard/'));
?>

<section class="py-12 sm:py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        <!-- ✅ removed bg-white, fixed stacking, improved contrast -->
        <div class="relative overflow-hidden rounded-3xl border border-white/10 shadow-md">

            <!-- Background accents (✅ NOT -z-10) -->
            <div class="absolute inset-0 z-0 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-800"></div>
            <div class="pointer-events-none absolute -top-24 start-[-8rem] z-0 h-72 w-72 rounded-full bg-white/10 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-24 end-[-8rem] z-0 h-72 w-72 rounded-full bg-indigo-400/20 blur-3xl"></div>

            <!-- Content -->
            <div class="relative z-10 p-6 sm:p-10 lg:p-12">
                <div class="grid gap-8 lg:grid-cols-12 lg:items-center">

                    <!-- Copy -->
                    <div class="lg:col-span-7">
                        <div class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/15">
                            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                            جاهز خلال دقائق • يعمل على الجوال • RTL/LTR
                        </div>

                        <h3 class="mt-4 text-2xl font-extrabold tracking-tight text-white sm:text-3xl">
                            جهّز مناسبتك القادمة بشكل يليق بها
                        </h3>

                        <p class="mt-3 text-sm leading-6 text-white/85">
                            أنشئ صفحة ضيوف احترافية، اجمع RSVP تلقائيًا، وشارك الدعوة بسهولة.
                            ابدأ الآن—وعدّل التفاصيل لاحقًا بدون تعقيد.
                        </p>

                        <!-- Mini points -->
                        <div class="mt-6 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-2xl bg-white/10 p-4 ring-1 ring-white/15">
                                <div class="text-xs text-white/70">تنظيم</div>
                                <div class="mt-1 text-sm font-extrabold text-white">RSVP مرتب</div>
                            </div>
                            <div class="rounded-2xl bg-white/10 p-4 ring-1 ring-white/15">
                                <div class="text-xs text-white/70">دخول</div>
                                <div class="mt-1 text-sm font-extrabold text-white">QR سريع</div>
                            </div>
                            <div class="rounded-2xl bg-white/10 p-4 ring-1 ring-white/15">
                                <div class="text-xs text-white/70">مشاركة</div>
                                <div class="mt-1 text-sm font-extrabold text-white">واتساب + رابط</div>
                            </div>
                        </div>

                        <!-- CTAs -->
                        <div class="mt-8 flex flex-wrap items-center gap-3">
                            <a href="<?php echo esc_url($start_url); ?>"
                                class="inline-flex items-center justify-center rounded-2xl bg-white px-7 py-3 text-sm font-extrabold text-slate-950 shadow-sm hover:bg-slate-100">
                                إنشاء مناسبة الآن
                                <span class="ms-2 opacity-70">➜</span>
                            </a>

                            <a href="<?php echo esc_url($dash_url); ?>"
                                class="inline-flex items-center justify-center rounded-2xl bg-white/10 px-7 py-3 text-sm font-semibold text-white ring-1 ring-white/15 hover:bg-white/15">
                                لوحة التحكم
                            </a>

                            <a href="#pricing"
                                class="inline-flex items-center justify-center rounded-2xl bg-transparent px-6 py-3 text-sm font-semibold text-white/90 underline decoration-white/30 underline-offset-4 hover:text-white">
                                استعرض الباقات
                            </a>
                        </div>

                        <div class="mt-4 text-xs text-white/75">
                            * يمكنك البدء مجانًا، ثم الترقية لاحقًا حسب احتياجك.
                        </div>
                    </div>

                    <!-- Side card -->
                    <div class="lg:col-span-5">
                        <div class="rounded-3xl bg-white/10 p-6 ring-1 ring-white/15">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-extrabold text-white">لماذا منصّة مناسبات؟</div>
                                <span class="rounded-full bg-emerald-400/20 px-3 py-1 text-xs font-semibold text-emerald-200 ring-1 ring-emerald-300/20">
                                    موثوقة
                                </span>
                            </div>

                            <div class="mt-4 space-y-3 text-sm text-white/85">
                                <div class="flex items-start gap-2">
                                    <span class="mt-1 h-2 w-2 rounded-full bg-emerald-300"></span>
                                    تمنع ضياع التفاصيل داخل الرسائل.
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="mt-1 h-2 w-2 rounded-full bg-emerald-300"></span>
                                    تقلل الاستفسارات وتزيد وضوح الموعد والموقع.
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="mt-1 h-2 w-2 rounded-full bg-emerald-300"></span>
                                    تساعدك تعرف العدد المتوقع بدقة.
                                </div>
                            </div>

                            <div class="mt-6 rounded-2xl bg-white/10 p-4 ring-1 ring-white/15">
                                <div class="text-xs text-white/70">جاهز للخطوة التالية؟</div>
                                <div class="mt-1 text-sm font-semibold text-white">
                                    أنشئ مناسبة وشاهد صفحة الضيوف مباشرة.
                                </div>
                            </div>

                            <a href="<?php echo esc_url($start_url); ?>"
                                class="mt-5 inline-flex w-full justify-center rounded-2xl bg-emerald-400 px-6 py-3 text-sm font-extrabold text-slate-950 hover:bg-emerald-300">
                                ابدأ الآن
                            </a>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</section>