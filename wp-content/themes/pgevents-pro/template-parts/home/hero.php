<?php
// Routes موجودة عندك عبر البلجن
$create_url = home_url('/create-event/');
$dash_url   = home_url('/dashboard/');

$is_logged_in   = is_user_logged_in();
$primary_cta    = $is_logged_in ? $create_url : wp_login_url($create_url);
$secondary_cta  = $is_logged_in ? $dash_url : wp_login_url($dash_url);
?>

<section class="relative overflow-hidden">
    <!-- Background -->
    <div class="absolute inset-0 -z-10 bg-gradient-to-b from-indigo-50/70 via-white to-slate-50"></div>
    <div class="pointer-events-none absolute -top-28 start-[-10rem] h-96 w-96 rounded-full bg-indigo-500/15 blur-3xl"></div>
    <div class="pointer-events-none absolute top-28 end-[-10rem] h-96 w-96 rounded-full bg-slate-900/10 blur-3xl"></div>

    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:px-8 lg:py-16">
        <div class="grid gap-8 lg:grid-cols-12 lg:items-center">

            <!-- Copy -->
            <div class="lg:col-span-6">
                <div class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    منصّة مناسبات — دعوات ذكية وتنظيم أسهل
                </div>

                <h1 class="mt-4 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl lg:text-5xl">
                    أنشئ دعوتك خلال دقائق…
                    <span class="block text-slate-700/80">واجمع الردود تلقائيًا</span>
                </h1>

                <p class="mt-4 max-w-xl text-base leading-7 text-slate-600">
                    صفحة مناسبة احترافية للضيوف + RSVP محفوظ + QR للدخول + مشاركة سهلة.
                    كل شيء مرتب ومناسب للجوال، مع دعم RTL/LTR.
                </p>

                <!-- Actions -->
                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <a
                        href="<?php echo esc_url($primary_cta); ?>"
                        class="group inline-flex items-center justify-center rounded-2xl bg-slate-900 px-6 py-3 text-sm font-extrabold text-white shadow-sm hover:bg-slate-800">
                        ابدأ الآن
                        <span class="ms-2 opacity-80 transition ltr:group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5">➜</span>
                    </a>

                    <a
                        href="<?php echo esc_url($secondary_cta); ?>"
                        class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                        لوحة التحكم
                    </a>

                    <a
                        href="#how"
                        class="inline-flex items-center justify-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50">
                        كيف تعمل؟
                    </a>
                </div>

                <!-- Mini feature cards -->
                <div class="mt-8 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-3xl bg-white p-4 ring-1 ring-slate-200 shadow-sm">
                        <div class="text-sm font-extrabold text-slate-900">RSVP محفوظ</div>
                        <div class="mt-1 text-sm text-slate-600">أسماء + أرقام + مرافقين</div>
                    </div>

                    <div class="rounded-3xl bg-white p-4 ring-1 ring-slate-200 shadow-sm">
                        <div class="text-sm font-extrabold text-slate-900">QR للدخول</div>
                        <div class="mt-1 text-sm text-slate-600">تحقق سريع عند البوابة</div>
                    </div>

                    <div class="rounded-3xl bg-white p-4 ring-1 ring-slate-200 shadow-sm">
                        <div class="text-sm font-extrabold text-slate-900">مشاركة سريعة</div>
                        <div class="mt-1 text-sm text-slate-600">واتساب + نسخ رابط</div>
                    </div>
                </div>
            </div>

            <!-- Preview card -->
            <div class="lg:col-span-6">
                <!-- ✅ أزلنا bg-white/70 + backdrop-blur لتقوية وضوح الألوان -->
                <div class="rounded-3xl border border-slate-200 bg-white shadow-md">
                    <div class="p-5 sm:p-7">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-extrabold text-slate-900">معاينة صفحة الضيف</div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                جاهزة للجوال
                            </span>
                        </div>

                        <div class="mt-4 overflow-hidden rounded-3xl ring-1 ring-slate-200 bg-white">
                            <div class="h-44 bg-slate-200"></div>

                            <div class="p-4">
                                <div class="text-lg font-extrabold text-slate-900">حفل تخرّج أحمد 2026</div>

                                <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                                    <span class="rounded-full bg-slate-50 px-3 py-1 ring-1 ring-slate-200">13 فبراير</span>
                                    <span class="rounded-full bg-slate-50 px-3 py-1 ring-1 ring-slate-200">8:30 مساءً</span>
                                    <span class="rounded-full bg-slate-50 px-3 py-1 ring-1 ring-slate-200">قاعة النخبة</span>
                                </div>

                                <div class="mt-4 grid grid-cols-2 gap-2">
                                    <div class="rounded-2xl bg-slate-900 px-4 py-3 text-center text-sm font-extrabold text-white">
                                        تأكيد الحضور
                                    </div>
                                    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-center text-sm font-semibold text-slate-800 hover:bg-slate-50">
                                        مشاركة الدعوة
                                    </div>
                                </div>

                                <div class="mt-4 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                                    <div class="text-sm font-extrabold text-slate-900">ملخص الحضور</div>

                                    <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                                        <div class="rounded-2xl bg-white p-3 ring-1 ring-slate-200">
                                            <div class="text-base font-extrabold text-slate-900">200+</div>
                                            <div class="mt-1 text-[11px] text-slate-500">مدعو</div>
                                        </div>
                                        <div class="rounded-2xl bg-white p-3 ring-1 ring-slate-200">
                                            <div class="text-base font-extrabold text-slate-900">145</div>
                                            <div class="mt-1 text-[11px] text-slate-500">مؤكد</div>
                                        </div>
                                        <div class="rounded-2xl bg-white p-3 ring-1 ring-slate-200">
                                            <div class="text-base font-extrabold text-slate-900">12</div>
                                            <div class="mt-1 text-[11px] text-slate-500">اعتذار</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200">
                            <div class="text-sm font-semibold text-amber-900">
                                جرّب الآن: أنشئ مناسبة وشارك رابطها خلال دقائق.
                            </div>
                            <a
                                href="<?php echo esc_url($primary_cta); ?>"
                                class="rounded-2xl bg-white px-5 py-2.5 text-sm font-extrabold text-slate-900 ring-1 ring-amber-200 hover:bg-amber-100/60">
                                إنشاء مناسبة
                            </a>
                        </div>

                    </div>
                </div>
            </div>

        </div>
    </div>
</section>