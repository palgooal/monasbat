<?php
if (!defined('ABSPATH')) exit;

// CTA URLs
$start_url = is_user_logged_in() ? home_url('/create-event/') : wp_login_url(home_url('/create-event/'));
$dash_url  = is_user_logged_in() ? home_url('/dashboard/') : wp_login_url(home_url('/dashboard/'));

// 1) Get plans from plugin option (fallback to defaults)
$plans = get_option('mon_packages_settings');
if (empty($plans) || !is_array($plans)) {
    if (class_exists('PGE_Packages') && method_exists('PGE_Packages', 'get_default_plans')) {
        $plans = PGE_Packages::get_default_plans();
    } else {
        // ultimate fallback (never break UI)
        $plans = [
            'plan_1' => ['name' => 'Starter',  'price' => '0',   'guest_limit' => 15,  'events_count' => 1,  'wa_messages' => 0,  'salla_url' => '#'],
            'plan_2' => ['name' => 'Standard', 'price' => '149', 'guest_limit' => 50,  'events_count' => 3,  'wa_messages' => 50, 'salla_url' => '#'],
            'plan_3' => ['name' => 'Premium',  'price' => '299', 'guest_limit' => 200, 'events_count' => 10, 'wa_messages' => 200, 'salla_url' => '#'],
            'plan_4' => ['name' => 'Platinum', 'price' => '450', 'guest_limit' => 500, 'events_count' => 20, 'wa_messages' => 500, 'salla_url' => '#'],
        ];
    }
}

// Ensure 4 plans always (plan_1..plan_4)
for ($i = 1; $i <= 4; $i++) {
    $key = "plan_$i";
    if (!isset($plans[$key]) || !is_array($plans[$key])) {
        $plans[$key] = ['name' => "باقة $i", 'price' => '', 'guest_limit' => '', 'events_count' => '', 'wa_messages' => '', 'salla_url' => '#'];
    }
}

// Visual mapping (static classes to avoid Tailwind purge issues)
$plan_ui = [
    'plan_1' => [
        'badge' => ['text' => 'مناسب للتجربة', 'cls' => 'bg-slate-50 text-slate-700 ring-slate-200'],
        'btn'   => 'border border-slate-200 bg-white text-slate-800 hover:bg-slate-50',
        'dot'   => 'bg-emerald-500',
    ],
    'plan_2' => [
        'badge' => ['text' => 'الأكثر توازنًا', 'cls' => 'bg-indigo-50 text-indigo-700 ring-indigo-200'],
        'btn'   => 'border border-slate-200 bg-white text-slate-800 hover:bg-slate-50',
        'dot'   => 'bg-emerald-500',
    ],
    'plan_3' => [
        'popular' => true,
        'badge' => ['text' => 'أفضل قيمة', 'cls' => 'bg-white/10 text-white/80 ring-white/15'],
        'btn'   => 'bg-white text-slate-900 hover:bg-slate-100',
        'dot'   => 'bg-emerald-400',
    ],
    'plan_4' => [
        'badge' => ['text' => 'للفعاليات الكبيرة', 'cls' => 'bg-amber-50 text-amber-800 ring-amber-200'],
        'btn'   => 'border border-slate-200 bg-white text-slate-800 hover:bg-slate-50',
        'dot'   => 'bg-emerald-500',
    ],
];

$popular_plan_id = 'plan_3'; // ثابت حالياً (نقدر نخليه من الإعدادات لاحقاً)
?>

<section id="pricing" class="py-12 sm:py-16">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="max-w-2xl">
                <div class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                    <span class="h-2 w-2 rounded-full bg-fuchsia-500"></span>
                    باقات مرنة حسب حجم مناسبتك
                </div>

                <h2 class="mt-3 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">
                    اختر الباقة المناسبة
                </h2>

                <p class="mt-2 text-sm leading-6 text-slate-600">
                    اربط الشراء عبر سلة من لوحة الإدارة—وصفحة الباقات تتحدّث تلقائيًا بدون تعديل كود.
                </p>
            </div>

            <a href="<?php echo esc_url($start_url); ?>"
                class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-extrabold text-white shadow-sm hover:bg-slate-800">
                ابدأ الآن
                <span class="ms-2 opacity-80">➜</span>
            </a>
        </div>

        <!-- Cards -->
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?php for ($i = 1; $i <= 4; $i++):
                $id  = "plan_$i";
                $p   = $plans[$id] ?? [];
                $ui  = $plan_ui[$id] ?? $plan_ui['plan_1'];

                $name = $p['name'] ?? ("باقة $i");
                $price = trim((string)($p['price'] ?? ''));

                $guest_limit  = $p['guest_limit'] ?? '—';
                $events_count = $p['events_count'] ?? '—';
                $wa_messages  = $p['wa_messages'] ?? '—';

                $salla_url = isset($p['salla_url']) ? trim((string)$p['salla_url']) : '';
                $has_salla = (!empty($salla_url) && $salla_url !== '#');

                $is_popular = ($id === $popular_plan_id);
                $is_free = ($price === '' || $price === '0' || strtolower($price) === 'free' || $price === 'مجاني');

                // Button logic:
                // - if salla exists => go to salla with "ترقية عبر سلة"
                // - else => go to start_url with "ابدأ الآن" (or "ابدأ مجانًا" for free)
                $btn_url   = $has_salla ? $salla_url : $start_url;
                $btn_label = $has_salla ? 'ترقية عبر سلة' : ($is_free ? 'ابدأ مجانًا' : 'ابدأ الآن');
                $btn_label_popular = $has_salla ? 'اشترك الآن عبر سلة' : ($is_free ? 'ابدأ مجانًا' : 'ابدأ الآن');
            ?>

                <?php if ($is_popular): ?>
                    <!-- Popular card -->
                    <div class="relative rounded-3xl border border-slate-900 bg-slate-900 p-6 text-white shadow-sm">
                        <div class="absolute -top-3 start-6">
                            <span class="rounded-full bg-white px-3 py-1 text-xs font-extrabold text-slate-900">
                                الأكثر شيوعًا
                            </span>
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="text-sm font-extrabold"><?php echo esc_html($name); ?></div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold ring-1 <?php echo esc_attr($ui['badge']['cls']); ?>">
                                <?php echo esc_html($ui['badge']['text']); ?>
                            </span>
                        </div>

                        <div class="mt-4">
                            <div class="flex items-end gap-2">
                                <div class="text-3xl font-extrabold"><?php echo $is_free ? 'مجاني' : esc_html($price ?: '—'); ?></div>
                                <div class="text-sm text-white/70"><?php echo $is_free ? '/ مناسبة' : '/ شهريًا'; ?></div>
                            </div>
                            <p class="mt-2 text-sm text-white/80">
                                الأفضل لمعظم المناسبات: دخول QR + ألبوم + تفاعل وخصوصية.
                            </p>
                        </div>

                        <ul class="mt-5 space-y-3 text-sm text-white/90">
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full <?php echo esc_attr($ui['dot']); ?>"></span>
                                حد المدعوين: <span class="ms-auto font-extrabold"><?php echo esc_html($guest_limit); ?></span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full <?php echo esc_attr($ui['dot']); ?>"></span>
                                عدد المناسبات: <span class="ms-auto font-extrabold"><?php echo esc_html($events_count); ?></span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full <?php echo esc_attr($ui['dot']); ?>"></span>
                                رسائل واتساب: <span class="ms-auto font-extrabold"><?php echo esc_html($wa_messages); ?></span>
                            </li>

                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full <?php echo esc_attr($ui['dot']); ?>"></span>
                                QR للدخول والتحقق
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full <?php echo esc_attr($ui['dot']); ?>"></span>
                                ألبوم صور (رفع الضيوف)
                            </li>
                        </ul>

                        <a href="<?php echo esc_url($btn_url); ?>"
                            class="mt-6 inline-flex w-full justify-center rounded-2xl px-5 py-3 text-sm font-extrabold <?php echo esc_attr($ui['btn']); ?>"
                            <?php echo $has_salla ? 'rel="nofollow noopener" target="_blank"' : ''; ?>>
                            <?php echo esc_html($btn_label_popular); ?>
                        </a>

                        <?php if (!$has_salla): ?>
                            <div class="mt-4 rounded-2xl bg-white/10 p-4 ring-1 ring-white/15">
                                <div class="text-xs text-white/70">ملاحظة</div>
                                <div class="mt-1 text-sm font-semibold">
                                    اربط رابط الشراء من إعدادات الباقات في لوحة الإدارة.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- Normal card -->
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-extrabold"><?php echo esc_html($name); ?></div>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold ring-1 <?php echo esc_attr($ui['badge']['cls']); ?>">
                                <?php echo esc_html($ui['badge']['text']); ?>
                            </span>
                        </div>

                        <div class="mt-4">
                            <div class="flex items-end gap-2">
                                <div class="text-3xl font-extrabold"><?php echo $is_free ? 'مجاني' : esc_html($price ?: '—'); ?></div>
                                <div class="text-sm text-slate-500"><?php echo $is_free ? '/ مناسبة' : '/ شهريًا'; ?></div>
                            </div>
                            <p class="mt-2 text-sm text-slate-600">
                                <?php
                                if ($id === 'plan_1') {
                                    echo 'ابدأ بسرعة وتعرّف على النظام.';
                                } elseif ($id === 'plan_2') {
                                    echo 'خيار متوازن للمناسبات الصغيرة والمتوسطة.';
                                } elseif ($id === 'plan_4') {
                                    echo 'مثالية للفعاليات الكبيرة والفِرق.';
                                } else {
                                    echo 'باقة مناسبة لمعظم الاستخدامات.';
                                }
                                ?>
                            </p>
                        </div>

                        <ul class="mt-5 space-y-3 text-sm text-slate-700">
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full <?php echo esc_attr($ui['dot']); ?>"></span>
                                حد المدعوين: <span class="ms-auto font-extrabold"><?php echo esc_html($guest_limit); ?></span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full <?php echo esc_attr($ui['dot']); ?>"></span>
                                عدد المناسبات: <span class="ms-auto font-extrabold"><?php echo esc_html($events_count); ?></span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-1 h-2 w-2 rounded-full <?php echo esc_attr($ui['dot']); ?>"></span>
                                رسائل واتساب: <span class="ms-auto font-extrabold"><?php echo esc_html($wa_messages); ?></span>
                            </li>
                            <li class="flex items-start gap-2 text-slate-500">
                                <span class="mt-1 h-2 w-2 rounded-full bg-slate-300"></span>
                                المزايا التفصيلية تُدار من لوحة الإعدادات
                            </li>
                        </ul>

                        <a href="<?php echo esc_url($btn_url); ?>"
                            class="mt-6 inline-flex w-full justify-center rounded-2xl px-5 py-3 text-sm font-semibold <?php echo esc_attr($ui['btn']); ?>"
                            <?php echo $has_salla ? 'rel="nofollow noopener" target="_blank"' : ''; ?>>
                            <?php echo esc_html($btn_label); ?>
                        </a>
                    </div>
                <?php endif; ?>

            <?php endfor; ?>
        </div>

        <!-- Comparison strip -->
        <div class="mt-8 rounded-3xl border border-slate-200 bg-white p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-sm font-extrabold text-slate-900">مقارنة سريعة</div>
                    <div class="mt-1 text-sm text-slate-600">
                        إن كان لديك بوابة دخول وتحتاج QR وألبوم—اختر <?php echo esc_html($plans[$popular_plan_id]['name'] ?? 'الباقة الشائعة'); ?>.
                        وللفعاليات الكبيرة والفِرق—اختر <?php echo esc_html($plans['plan_4']['name'] ?? 'الباقة الكبرى'); ?>.
                    </div>
                </div>

                <a href="<?php echo esc_url($dash_url); ?>"
                    class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-6 py-3 text-sm font-extrabold text-white hover:bg-slate-800">
                    لوحة التحكم
                    <span class="ms-2 opacity-80">➜</span>
                </a>
            </div>
        </div>

    </div>
</section>