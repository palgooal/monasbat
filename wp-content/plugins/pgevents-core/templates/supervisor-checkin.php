<?php
/**
 * ============================================================================
 * Supervisor Check-in Interface — Entry Check-in Supervisors، Phase 7
 * ============================================================================
 * "Supervisor Check-in User Interface" RFC — "This phase implements the
 * presentation layer only. The existing Phase 4 Check-in Engine must remain
 * the only source of attendance writes. The UI must consume existing
 * services only. No attendance business logic may be duplicated."
 *
 * التدفّق الإلزامي (بلا أي انحراف): Supervisor UI (هذا القالب) → نقطة AJAX
 * مخوَّلة (checkin-ui-ajax.php للبحث فقط — عرض/إعادة تشكيل بحتة، أو
 * checkin-ajax.php الحالية غير المُعدَّلة لمسح QR والتأكيد الفعلي) →
 * PGE_Guest_Resolution_Service/PGE_Checkin_QR_Service (حلّ) →
 * PGE_Checkin_Recorder (الكتابة الوحيدة) → جدول التدقيق → تحديث لوحة الحضور.
 * لا استعلام SQL هنا، لا حساب حضور، لا كتابة مباشرة على قاعدة البيانات من هذا
 * الملف إطلاقاً.
 *
 * قالب مستقل (نفس فلسفة templates/supervisor-portal.php وsupervisor-dashboard.php
 * حرفياً — المشرف لا يملك بالضرورة حساب ووردبريس).
 *
 * ============================================================================
 * قرار تقني: مسح QR عبر BarcodeDetector الأصلية في المتصفح (بلا مكتبة خارجية)
 * ============================================================================
 * لا يوجد في هذا المشروع أي سابقة لتحميل مكتبة JS خارجية عبر CDN (تحقّقنا
 * صراحة قبل الكتابة) — كل سكربت في المشروع مضمَّن محلياً بلا أي اعتمادية.
 * بدل تضمين مكتبة فك تشفير QR كاملة يدوياً (خطر أخطاء دقيقة يصعب اختبارها في
 * بيئة PHP التنفيذية المُعتمَدة لهذا المشروع)، نستخدم Shape Detection API
 * الأصلية للمتصفح (`window.BarcodeDetector`) — بلا أي اعتمادية خارجية، مدعومة
 * فعلياً في متصفحات الجوّال الرئيسية (Chrome/Android — أولوية الـRFC الصريحة:
 * "Support mobile first"). عند عدم توفرها (مثل Safari لسطح المكتب) تُعرَض
 * رسالة واضحة وتحويل فوري لوضع "البحث اليدوي" — وهو أصلاً اشتراط صريح في
 * الـRFC ذاته ("Provide manual fallback")، فلا تعارض ولا حل وسط.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('PGE_Supervisor_Portal_Middleware')) {
    // بيئة غير مكتملة — رفض آمن، لا كشف تفاصيل داخلية (Fail Closed)، نفس نمط
    // supervisor-portal.php/supervisor-dashboard.php حرفياً.
    status_header(401);
    nocache_headers();
    wp_die('تعذّر تحميل واجهة تسجيل الحضور حالياً.', 'خطأ', ['response' => 401]);
}

// ── الخطوة 1: التفويض (لا وثوق بأي معامل طلب — نفس Middleware المُستخدَمة في
// supervisor-portal.php/supervisor-dashboard.php/checkin-ajax.php حرفياً،
// بلا أي تعديل عليها) ────────────────────────────────────────────────────
$authorization = PGE_Supervisor_Portal_Middleware::authorize();

if (($authorization['result'] ?? '') !== 'authorized') {
    $http_status = (int) ($authorization['http_status'] ?? 401);
    $reason = (string) ($authorization['reason'] ?? '');

    $messages_401 = [
        'no_session_cookie'         => 'لم يتم تسجيل الدخول بعد. الرجاء استخدام رابط الدعوة الذي وصلك لتسجيل الدخول كمشرف.',
        'invalid_token'             => 'جلسة الدخول غير صالحة. الرجاء تسجيل الدخول مجدداً.',
        'session_not_found'         => 'جلسة الدخول غير موجودة أو منتهية. الرجاء تسجيل الدخول مجدداً.',
        'session_revoked'           => 'تم تسجيل خروجك من هذه الجلسة سابقاً. الرجاء تسجيل الدخول مجدداً.',
        'session_expired'           => 'انتهت صلاحية جلسة الدخول. الرجاء تسجيل الدخول مجدداً.',
        'session_layer_unavailable' => 'تعذّر التحقق من الجلسة حالياً. حاول لاحقاً.',
    ];
    $messages_403 = [
        'assignment_not_active'    => 'لم يعد وصولك كمشرف على هذه المناسبة فعّالاً. تواصل مع المضيف إذا كنت تظن أن هذا خطأ.',
        'not_authorized_for_event' => 'لا تملك صلاحية الوصول إلى هذه المناسبة.',
    ];
    $messages_404 = [
        'assignment_not_found' => 'لم يتم العثور على إسناد الإشراف المرتبط بجلستك.',
        'event_mismatch'       => 'تعذّر العثور على المناسبة المرتبطة بجلستك.',
    ];

    switch ($http_status) {
        case 403:
            $title = 'غير مصرَّح';
            $message = $messages_403[$reason] ?? 'لا تملك صلاحية الوصول إلى هذه الصفحة.';
            break;
        case 404:
            $title = 'غير موجود';
            $message = $messages_404[$reason] ?? 'العنصر المطلوب غير موجود.';
            break;
        default:
            $http_status = 401;
            $title = 'الدخول مطلوب';
            $message = $messages_401[$reason] ?? 'الرجاء تسجيل الدخول للوصول إلى هذه الصفحة.';
            break;
    }

    status_header($http_status);
    nocache_headers();
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($title); ?> — تسجيل الحضور</title>
    <?php wp_head(); ?>
</head>
<body>
    <div class="relative flex min-h-screen items-center justify-center bg-background px-4 font-arabic" dir="rtl">
        <div class="w-full max-w-sm rounded-3xl border border-border bg-white p-8 text-center shadow-xl" role="alert">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-destructive/10 text-2xl text-destructive-text">⚠️</div>
            <h1 class="text-lg font-extrabold text-foreground"><?php echo esc_html($title); ?></h1>
            <p class="mt-3 text-sm leading-relaxed text-foreground/75"><?php echo esc_html($message); ?></p>
        </div>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
    <?php
    exit;
}

// ── الخطوة 2: معلومات المناسبة/الجلسة الموثوقة (من authorize() حصراً) ──────
$event_id = (int) $authorization['event_id'];
$assignment_id = (int) $authorization['assignment_id'];
$event_title = function_exists('get_the_title') ? (string) get_the_title($event_id) : '';

// ── ملخّص جلسة اختياري (قراءة فقط عبر Provider الحالي غير المُعدَّل — لا
// تعديل على أي طبقة حساب هنا؛ مجرّد استهلاك للعرض الأولي، تماماً كما تفعل
// templates/supervisor-dashboard.php أصلاً) — إن تعذَّر، تُخفى اللوحة فقط بلا
// كسر بقية الصفحة (وظيفة تسجيل الحضور لا تعتمد عليه إطلاقاً). ──────────────
$initial_summary = null;
if (class_exists('PGE_Attendance_Dashboard_Provider')) {
    $dashboard = PGE_Attendance_Dashboard_Provider::get_dashboard($event_id);
    if (($dashboard['result'] ?? '') === 'authorized') {
        $initial_summary = is_array($dashboard['data']['attendance_summary'] ?? null) ? $dashboard['data']['attendance_summary'] : null;
    }
}

$checkin_nonce = wp_create_nonce('pge_supervisor_checkin_nonce');
$dashboard_nonce = wp_create_nonce('pge_supervisor_dashboard_nonce');
$ajax_url = admin_url('admin-ajax.php');
$logout_url = wp_nonce_url(home_url('/supervisor/logout/'), 'pge_supervisor_logout');
$dashboard_url = home_url('/supervisor/dashboard/');

status_header(200);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($event_title !== '' ? $event_title . ' — تسجيل الحضور' : 'تسجيل الحضور'); ?></title>
    <?php wp_head(); ?>
</head>
<body>
<div class="relative min-h-screen bg-background font-arabic" dir="rtl" data-testid="supervisor-checkin-root">
<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6">

    <!-- رأس الصفحة -->
    <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-foreground"><?php echo esc_html($event_title !== '' ? $event_title : 'تسجيل الحضور'); ?></h1>
            <p class="mt-1 text-xs text-foreground/60">تسجيل حضور الضيوف</p>
        </div>
        <div class="flex items-center gap-3 text-xs">
            <a href="<?php echo esc_url($dashboard_url); ?>"
               class="rounded-xl px-3 py-1.5 font-bold text-foreground/70 transition-colors hover:bg-secondary/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                لوحة الحضور
            </a>
            <a href="<?php echo esc_url($logout_url); ?>"
               class="rounded-xl px-3 py-1.5 font-bold text-destructive-text transition-colors hover:bg-destructive/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-destructive">
                تسجيل الخروج
            </a>
        </div>
    </header>

    <!-- شريط خطأ عام غير حاجب -->
    <div data-role="error-banner" class="mb-4 hidden rounded-2xl bg-destructive/10 p-4 text-sm font-medium text-destructive-text" role="alert" aria-live="assertive">
        <span data-role="error-banner-text">حدث خطأ غير متوقَّع.</span>
    </div>

    <!-- إعلان بصري مخفي للقارئ الصوتي (تنقّلات الشاشة) -->
    <div data-role="sr-announcer" class="sr-only" aria-live="polite"></div>

    <!-- ملخّص الجلسة (اختياري — يُخفى إن تعذّر جلبه) -->
    <section aria-labelledby="session-summary-heading" data-role="session-summary" class="<?php echo $initial_summary === null ? 'hidden' : ''; ?> mb-5">
        <h2 id="session-summary-heading" class="mb-2 text-xs font-bold uppercase tracking-wide text-foreground/50">ملخّص الجلسة</h2>
        <div class="grid grid-cols-3 gap-3">
            <div class="rounded-2xl border border-border bg-white p-3 text-center">
                <p class="text-[11px] font-bold text-foreground/50">مسجَّل</p>
                <p class="mt-1 text-lg font-extrabold text-foreground" data-metric="checked_in_invitations"><?php echo esc_html((string) (int) ($initial_summary['checked_in_invitations'] ?? 0)); ?></p>
            </div>
            <div class="rounded-2xl border border-border bg-white p-3 text-center">
                <p class="text-[11px] font-bold text-foreground/50">مُعلَّق</p>
                <p class="mt-1 text-lg font-extrabold text-foreground" data-metric="pending_invitations"><?php echo esc_html((string) (int) ($initial_summary['pending_invitations'] ?? 0)); ?></p>
            </div>
            <div class="rounded-2xl border border-border bg-white p-3 text-center">
                <p class="text-[11px] font-bold text-foreground/50">الإجمالي</p>
                <p class="mt-1 text-lg font-extrabold text-foreground" data-metric="total_invitations"><?php echo esc_html((string) (int) ($initial_summary['total_invitations'] ?? 0)); ?></p>
            </div>
        </div>
    </section>

    <!-- مبدِّل الوضع (Mode Switcher) -->
    <div role="tablist" aria-label="طريقة تسجيل الحضور" class="mb-4 grid grid-cols-2 gap-2 rounded-2xl bg-secondary/30 p-1">
        <button type="button" role="tab" id="mode-tab-qr" aria-selected="true" aria-controls="qr-panel" data-role="mode-tab" data-mode="qr"
                class="rounded-xl px-4 py-2 text-sm font-bold text-foreground transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary" data-active="true">
            مسح QR
        </button>
        <button type="button" role="tab" id="mode-tab-manual" aria-selected="false" aria-controls="manual-panel" data-role="mode-tab" data-mode="manual"
                class="rounded-xl px-4 py-2 text-sm font-bold text-foreground/60 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            بحث يدوي
        </button>
    </div>

    <!-- لوحة مسح QR -->
    <section id="qr-panel" role="tabpanel" aria-labelledby="mode-tab-qr" data-role="qr-panel" class="mb-6">
        <div class="relative overflow-hidden rounded-2xl border border-border bg-black" style="aspect-ratio:3/4;max-height:70vh;">
            <video data-role="qr-video" class="h-full w-full object-cover" playsinline muted autoplay aria-label="عرض كاميرا مسح QR"></video>
            <!-- إطار توجيه المسح (Scan Overlay) — بصري بحت، aria-hidden -->
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center" aria-hidden="true">
                <div class="h-2/3 w-2/3 rounded-2xl border-4 border-white/80" data-role="qr-overlay"></div>
            </div>
            <div data-role="qr-status" class="absolute inset-x-0 bottom-0 bg-black/60 p-2 text-center text-xs font-bold text-white" aria-live="polite">جاري بدء الكاميرا…</div>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="button" data-action="qr-start" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                بدء المسح
            </button>
            <button type="button" data-action="qr-switch-camera" class="hidden rounded-xl border border-border bg-white px-4 py-2 text-sm font-bold text-foreground transition-colors hover:bg-secondary/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                تبديل الكاميرا
            </button>
            <button type="button" data-action="qr-use-manual" class="rounded-xl px-4 py-2 text-sm font-bold text-foreground/70 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                استخدام البحث اليدوي بدلاً من ذلك
            </button>
        </div>
        <div data-role="qr-permission-guidance" class="mt-3 hidden rounded-2xl border border-border bg-secondary/20 p-3 text-xs font-medium text-foreground/70" role="status">
            يحتاج مسح QR إذناً بالوصول إلى الكاميرا. الرجاء السماح بالوصول من إعدادات المتصفح ثم إعادة المحاولة.
        </div>
        <div data-role="qr-unsupported" class="mt-3 hidden rounded-2xl border border-border bg-secondary/20 p-3 text-xs font-medium text-foreground/70" role="status">
            مسح QR غير مدعوم على هذا المتصفح. الرجاء استخدام البحث اليدوي.
        </div>
    </section>

    <!-- لوحة البحث اليدوي -->
    <section id="manual-panel" role="tabpanel" aria-labelledby="mode-tab-manual" data-role="manual-panel" class="mb-6 hidden">
        <label for="manual-search-input" class="mb-1 block text-xs font-bold text-foreground/60">ابحث بالجوال أو رمز الدعوة أو اسم الضيف</label>
        <input type="search" id="manual-search-input" data-role="manual-search-input"
               class="w-full rounded-2xl border border-border bg-white px-4 py-3 text-sm text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
               placeholder="مثال: 05xxxxxxxx أو ABCD-1234 أو اسم الضيف" autocomplete="off">

        <div data-role="search-loading" class="mt-3 hidden text-center text-xs font-bold text-foreground/50" role="status" aria-live="polite">جاري البحث…</div>
        <div data-role="search-no-results" class="mt-3 hidden rounded-2xl border border-dashed border-border bg-white p-4 text-center text-xs font-bold text-foreground/60" role="status">
            لا توجد نتائج مطابقة.
        </div>
        <ul data-role="search-results" class="mt-3 hidden divide-y divide-border overflow-hidden rounded-2xl border border-border bg-white"></ul>
    </section>

    <!-- شاشة التأكيد -->
    <section data-role="confirmation-screen" class="mb-6 hidden rounded-2xl border border-border bg-white p-5" aria-labelledby="confirmation-heading">
        <h2 id="confirmation-heading" tabindex="-1" class="mb-3 text-sm font-extrabold text-foreground">تأكيد تسجيل الحضور</h2>
        <p class="text-sm text-foreground/70">الضيف: <span class="font-bold text-foreground" data-field="confirm-guest-name"></span></p>
        <p class="mt-1 text-sm text-foreground/70">الضيوف المتوقَّعون: <span class="font-bold text-foreground" data-field="confirm-expected-count"></span></p>
        <p class="mt-1 text-sm text-foreground/70">طريقة التسجيل: <span class="font-bold text-foreground" data-field="confirm-method"></span></p>

        <label for="confirm-actual-count" class="mt-3 block text-xs font-bold text-foreground/60">عدد الحاضرين فعلياً</label>
        <input type="number" id="confirm-actual-count" data-field="confirm-actual-count-input" min="1" step="1"
               class="mt-1 w-24 rounded-xl border border-border bg-white px-3 py-2 text-sm text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">

        <div data-role="confirm-validation-error" class="mt-2 hidden text-xs font-bold text-destructive-text" role="alert" aria-live="assertive"></div>

        <div class="mt-4 flex gap-2">
            <button type="button" data-action="confirm-submit"
                    class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:cursor-not-allowed disabled:opacity-50">
                تأكيد الحضور
            </button>
            <button type="button" data-action="confirm-cancel"
                    class="rounded-xl border border-border bg-white px-4 py-2 text-sm font-bold text-foreground transition-colors hover:bg-secondary/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                إلغاء
            </button>
        </div>
    </section>

    <!-- شاشة النجاح -->
    <section data-role="success-screen" class="mb-6 hidden rounded-2xl border border-border bg-white p-5 text-center" aria-labelledby="success-heading" role="status">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-2xl">✅</div>
        <h2 id="success-heading" tabindex="-1" class="text-sm font-extrabold text-foreground">تم تسجيل الحضور بنجاح</h2>
        <p class="mt-2 text-sm text-foreground/70">الضيف: <span class="font-bold text-foreground" data-field="success-guest-name"></span></p>
        <p class="mt-1 text-sm text-foreground/70">عدد الحاضرين: <span class="font-bold text-foreground" data-field="success-actual-count"></span></p>
        <p class="mt-1 text-sm text-foreground/70">الوقت: <span class="font-bold text-foreground" data-field="success-time"></span></p>
        <p class="mt-1 text-sm text-foreground/70">الطريقة: <span class="font-bold text-foreground" data-field="success-method"></span></p>
        <button type="button" data-action="success-continue"
                class="mt-4 rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            التالي
        </button>
    </section>

    <!-- شاشة "مسجَّل مسبقاً" (Duplicate Handling) -->
    <section data-role="duplicate-screen" class="mb-6 hidden rounded-2xl border border-border bg-secondary/20 p-5 text-center" aria-labelledby="duplicate-heading" role="status">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-secondary/40 text-2xl">ℹ️</div>
        <h2 id="duplicate-heading" tabindex="-1" class="text-sm font-extrabold text-foreground">هذا الضيف مسجَّل حضوره مسبقاً</h2>
        <p class="mt-2 text-sm text-foreground/70">الضيف: <span class="font-bold text-foreground" data-field="duplicate-guest-name"></span></p>
        <p class="mt-1 text-sm text-foreground/70">عدد الحاضرين: <span class="font-bold text-foreground" data-field="duplicate-actual-count"></span></p>
        <p class="mt-1 text-sm text-foreground/70">الوقت: <span class="font-bold text-foreground" data-field="duplicate-time"></span></p>
        <p class="mt-1 text-sm text-foreground/70">الطريقة: <span class="font-bold text-foreground" data-field="duplicate-method"></span></p>
        <button type="button" data-action="duplicate-back"
                class="mt-4 rounded-xl border border-border bg-white px-4 py-2 text-sm font-bold text-foreground transition-colors hover:bg-secondary/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            رجوع
        </button>
    </section>

</div>
</div>
<script>
(function () {
    "use strict";

    var CONFIG = {
        ajaxUrl: <?php echo wp_json_encode($ajax_url); ?>,
        checkinNonce: <?php echo wp_json_encode($checkin_nonce); ?>,
        dashboardNonce: <?php echo wp_json_encode($dashboard_nonce); ?>,
        scanAction: "pge_supervisor_checkin_scan",
        searchAction: "pge_supervisor_checkin_ui_search",
        confirmAction: "pge_supervisor_checkin_confirm",
        dashboardAction: "pge_supervisor_dashboard_data",
        searchDebounceMs: 300,
        duplicateScanCooldownMs: 3000
    };

    // ────────────────────────────────────────────────────────────────────
    // مساعدات DOM عامة
    // ────────────────────────────────────────────────────────────────────
    function byRole(role) { return document.querySelector('[data-role="' + role + '"]'); }
    function byField(name) { return document.querySelector('[data-field="' + name + '"]'); }
    function byMetric(name) { return document.querySelector('[data-metric="' + name + '"]'); }

    function announce(text) {
        var el = byRole('sr-announcer');
        if (el) el.textContent = text;
    }
    function showErrorBanner(message) {
        var el = byRole('error-banner');
        var textEl = byRole('error-banner-text');
        if (textEl) textEl.textContent = message || 'حدث خطأ غير متوقَّع.';
        if (el) el.classList.remove('hidden');
    }
    function hideErrorBanner() {
        var el = byRole('error-banner');
        if (el) el.classList.add('hidden');
    }
    function hideAllScreens() {
        ['qr-panel', 'manual-panel', 'confirmation-screen', 'success-screen', 'duplicate-screen'].forEach(function (role) {
            var el = byRole(role);
            if (el) el.classList.add('hidden');
        });
    }

    // ────────────────────────────────────────────────────────────────────
    // خريطة الأسباب → رسائل عربية واضحة (Error Handling Requirement)
    // ────────────────────────────────────────────────────────────────────
    var REASON_MESSAGES = {
        invalid_nonce: 'انتهت صلاحية الجلسة. الرجاء تحديث الصفحة.',
        unauthorized: 'غير مصرَّح بهذا الإجراء. الرجاء تحديث الصفحة.',
        no_session_cookie: 'انتهت الجلسة. الرجاء تسجيل الدخول مجدداً.',
        session_expired: 'انتهت صلاحية الجلسة. الرجاء تسجيل الدخول مجدداً.',
        session_revoked: 'تم تسجيل الخروج من هذه الجلسة. الرجاء تسجيل الدخول مجدداً.',
        assignment_not_active: 'لم تعد مخوَّلاً كمشرف على هذه المناسبة.',
        empty_payload: 'رمز QR فارغ أو غير قابل للقراءة.',
        malformed_payload: 'رمز QR غير صالح.',
        signature_mismatch: 'رمز QR غير صالح أو تم التلاعب به.',
        event_mismatch: 'هذا الرمز يخص مناسبة أخرى.',
        invitation_not_found: 'تعذّر العثور على هذه الدعوة.',
        ambiguous_phone_match: 'يوجد أكثر من دعوة بنفس رقم الجوال. الرجاء اختيار الدعوة الصحيحة من نتائج البحث.',
        empty_query: 'أدخل نصاً للبحث.',
        resolution_service_unavailable: 'الخدمة غير متاحة حالياً.',
        services_unavailable: 'الخدمة غير متاحة حالياً.',
        already_checked_in: 'هذا الضيف مسجَّل حضوره مسبقاً.',
        assignment_not_authorized: 'لم تعد مخوَّلاً لتسجيل الحضور على هذه المناسبة.',
        lock_not_acquired: 'العملية مزدحمة، الرجاء إعادة المحاولة خلال ثوانٍ.',
        rsvp_not_found: 'تعذّر العثور على هذه الدعوة.',
        update_failed: 'تعذّر حفظ تسجيل الحضور، الرجاء إعادة المحاولة.',
        audit_log_failed: 'تعذّر حفظ سجل التدقيق، الرجاء إعادة المحاولة.',
        missing_rsvp_id: 'تعذّر تحديد الدعوة المطلوبة.',
        invalid_identifier: 'بيانات غير مكتملة.',
        invalid_attendance_origin: 'تعذّر تحديد طريقة تسجيل الحضور، الرجاء إعادة المحاولة.',
        unknown_error: 'حدث خطأ غير متوقَّع، الرجاء إعادة المحاولة.'
    };
    function reasonToMessage(reason) {
        return REASON_MESSAGES[reason] || 'حدث خطأ غير متوقَّع، الرجاء إعادة المحاولة.';
    }

    // ────────────────────────────────────────────────────────────────────
    // نداء AJAX عام (لا طلبات مكرَّرة — كل نداء يمرّ من هنا)
    // ────────────────────────────────────────────────────────────────────
    function postAjax(action, nonce, extraFields) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', nonce);
        Object.keys(extraFields || {}).forEach(function (key) {
            body.set(key, extraFields[key]);
        });
        return fetch(CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (res) { return res.json(); });
    }

    // ────────────────────────────────────────────────────────────────────
    // ملخّص الجلسة — تحديث تلقائي بعد كل تسجيل حضور ناجح (Requirement:
    // "Automatically refresh the dashboard snapshot. Do not reload the
    // page.") — نفس نقطة AJAX الحقيقية غير المُعدَّلة من Phase 6/6FF
    // (pge_supervisor_dashboard_data)، بلا أي تعديل على Provider أو منطق
    // الحساب هنا.
    // ────────────────────────────────────────────────────────────────────
    function refreshSessionSummary() {
        postAjax(CONFIG.dashboardAction, CONFIG.dashboardNonce, {}).then(function (json) {
            if (!json || !json.success || !json.data || !json.data.attendance_summary) return;
            var summary = json.data.attendance_summary;
            var panel = byRole('session-summary');
            ['checked_in_invitations', 'pending_invitations', 'total_invitations'].forEach(function (key) {
                var el = byMetric(key);
                if (el && summary[key] !== undefined) el.textContent = summary[key];
            });
            if (panel) panel.classList.remove('hidden');
        }).catch(function () {
            // فشل تحديث الملخّص وحده لا يجب أن يُعطِّل بقية الصفحة أو يُظهر
            // شريط خطأ حاجباً — مجرّد قيمة غير محدَّثة مؤقتاً.
        });
    }

    // ────────────────────────────────────────────────────────────────────
    // حالة الضيف المُعلَّقة قيد التأكيد — تُحفَظ في الذاكرة فقط، لا تُكتَب في
    // الـDOM إطلاقاً (Security: "Never expose Raw RSVP IDs / Internal
    // database keys"). identifierValue هنا دائماً إمّا نص QR الخام الموقَّع
    // فعلياً (من الكاميرا) أو `reference` موقَّع (من نتائج البحث) — أبداً
    // rsvp_id خام غير موقَّع.
    // ────────────────────────────────────────────────────────────────────
    // ────────────────────────────────────────────────────────────────────
    // Phase 7 Final Fix ("Audit Method Integrity"): "حلّ الدعوة" (identifierType/
    // identifierValue — عبر أي آلية موقَّعة) منفصل تماماً عن "أصل تسجيل
    // الحضور" (attendanceOrigin — كيف نفَّذ المشرف العملية فعلياً: مسح كاميرا
    // أم بحث يدوي). لا يُشتَق أحدهما من الآخر هنا إطلاقاً — كلاهما يُمرَّران
    // صراحةً في نفس طلب التأكيد (Recorder/الخادم لا يُخمِّن شيئاً من شكل
    // identifierValue).
    // ────────────────────────────────────────────────────────────────────
    var ATTENDANCE_ORIGIN_LABELS = { qr: 'QR', manual: 'يدوي' };
    var pendingGuest = null; // {name, expectedCount, identifierType, identifierValue, attendanceOrigin, methodLabel}
    var confirmInFlight = false;

    function openConfirmationScreen(guestName, expectedCount, identifierType, identifierValue, attendanceOrigin) {
        pendingGuest = {
            name: guestName || 'ضيف',
            expectedCount: expectedCount || 1,
            identifierType: identifierType,
            identifierValue: identifierValue,
            attendanceOrigin: attendanceOrigin,
            methodLabel: ATTENDANCE_ORIGIN_LABELS[attendanceOrigin] || attendanceOrigin
        };
        hideAllScreens();
        var nameEl = byField('confirm-guest-name');
        var expectedEl = byField('confirm-expected-count');
        var methodEl = byField('confirm-method');
        var inputEl = byField('confirm-actual-count-input');
        var errEl = byRole('confirm-validation-error');
        if (nameEl) nameEl.textContent = pendingGuest.name;
        if (expectedEl) expectedEl.textContent = String(pendingGuest.expectedCount);
        if (methodEl) methodEl.textContent = pendingGuest.methodLabel;
        if (inputEl) {
            inputEl.max = String(pendingGuest.expectedCount);
            inputEl.value = String(pendingGuest.expectedCount);
        }
        if (errEl) { errEl.classList.add('hidden'); errEl.textContent = ''; }
        var screen = byRole('confirmation-screen');
        if (screen) {
            screen.classList.remove('hidden');
            var heading = document.getElementById('confirmation-heading');
            if (heading) heading.focus();
        }
        announce('تم العثور على الضيف: ' + pendingGuest.name);
    }

    function returnToModeScreen() {
        pendingGuest = null;
        hideAllScreens();
        var activeTab = document.querySelector('[data-role="mode-tab"][data-active="true"]');
        var mode = activeTab ? activeTab.getAttribute('data-mode') : 'qr';
        var panel = byRole(mode === 'manual' ? 'manual-panel' : 'qr-panel');
        if (panel) panel.classList.remove('hidden');
        if (mode === 'qr') resumeScanning();
    }

    function submitConfirmation() {
        if (confirmInFlight || !pendingGuest) return;
        var inputEl = byField('confirm-actual-count-input');
        var actualCount = inputEl ? parseInt(inputEl.value, 10) : 0;
        var errEl = byRole('confirm-validation-error');

        if (!actualCount || actualCount < 1) {
            if (errEl) { errEl.textContent = 'أدخل عدداً صحيحاً لا يقل عن 1.'; errEl.classList.remove('hidden'); }
            return;
        }
        if (actualCount > pendingGuest.expectedCount) {
            if (errEl) { errEl.textContent = 'العدد الفعلي لا يمكن أن يتجاوز العدد المتوقَّع (' + pendingGuest.expectedCount + ').'; errEl.classList.remove('hidden'); }
            return;
        }
        if (errEl) { errEl.classList.add('hidden'); errEl.textContent = ''; }

        confirmInFlight = true;
        var btn = document.querySelector('[data-action="confirm-submit"]');
        if (btn) btn.disabled = true;

        postAjax(CONFIG.confirmAction, CONFIG.checkinNonce, {
            identifier_type: pendingGuest.identifierType,
            identifier_value: pendingGuest.identifierValue,
            attendance_origin: pendingGuest.attendanceOrigin,
            actual_count: actualCount
        }).then(function (json) {
            if (json && json.success && json.data && json.data.already) {
                renderDuplicateScreen(json.data);
                return;
            }
            if (json && json.success) {
                renderSuccessScreen(json.data);
                return;
            }
            var reason = (json && json.data && json.data.reason) || 'unknown_error';
            if (reason === 'invalid_actual_count') {
                var expected = (json.data && json.data.expected_count) || pendingGuest.expectedCount;
                if (errEl) { errEl.textContent = 'العدد الفعلي يجب أن يكون بين 1 و' + expected + '.'; errEl.classList.remove('hidden'); }
            } else {
                showErrorBanner(reasonToMessage(reason));
            }
        }).catch(function () {
            showErrorBanner('تعذّر الاتصال بالخادم، تحقّق من الاتصال بالإنترنت.');
        }).finally(function () {
            confirmInFlight = false;
            if (btn) btn.disabled = false;
        });
    }

    function renderSuccessScreen(data) {
        var guest = (data && data.guest) || {};
        hideAllScreens();
        var nameEl = byField('success-guest-name');
        var countEl = byField('success-actual-count');
        var timeEl = byField('success-time');
        var methodEl = byField('success-method');
        if (nameEl) nameEl.textContent = pendingGuest ? pendingGuest.name : '';
        if (countEl) countEl.textContent = String(data.actual_count !== undefined ? data.actual_count : (guest.actual_entered_count || ''));
        if (timeEl) timeEl.textContent = String(guest.checked_in_at || '');
        if (methodEl) methodEl.textContent = pendingGuest ? pendingGuest.methodLabel : '';
        var screen = byRole('success-screen');
        if (screen) {
            screen.classList.remove('hidden');
            var heading = document.getElementById('success-heading');
            if (heading) heading.focus();
        }
        announce('تم تسجيل الحضور بنجاح.');
        hideErrorBanner();
        refreshSessionSummary();
    }

    function renderDuplicateScreen(data) {
        var guest = (data && data.guest) || {};
        hideAllScreens();
        var nameEl = byField('duplicate-guest-name');
        var countEl = byField('duplicate-actual-count');
        var timeEl = byField('duplicate-time');
        var methodEl = byField('duplicate-method');
        if (nameEl) nameEl.textContent = pendingGuest ? pendingGuest.name : '';
        if (countEl) countEl.textContent = String(guest.actual_entered_count !== null && guest.actual_entered_count !== undefined ? guest.actual_entered_count : '');
        if (timeEl) timeEl.textContent = String(guest.checked_in_at || '');
        if (methodEl) methodEl.textContent = String(guest.checkin_method === 'qr' ? 'QR' : (guest.checkin_method === 'manual' ? 'يدوي' : '—'));
        var screen = byRole('duplicate-screen');
        if (screen) {
            screen.classList.remove('hidden');
            var heading = document.getElementById('duplicate-heading');
            if (heading) heading.focus();
        }
        announce('هذا الضيف مسجَّل حضوره مسبقاً.');
        hideErrorBanner();
    }

    // ────────────────────────────────────────────────────────────────────
    // مبدِّل الوضع (Mode Switcher)
    // ────────────────────────────────────────────────────────────────────
    function switchMode(mode) {
        document.querySelectorAll('[data-role="mode-tab"]').forEach(function (tab) {
            var isActive = tab.getAttribute('data-mode') === mode;
            tab.setAttribute('data-active', isActive ? 'true' : 'false');
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.classList.toggle('text-foreground', isActive);
            tab.classList.toggle('text-foreground/60', !isActive);
        });
        hideAllScreens();
        var panel = byRole(mode === 'manual' ? 'manual-panel' : 'qr-panel');
        if (panel) panel.classList.remove('hidden');
        if (mode === 'qr') {
            resumeScanning();
        } else {
            pauseScanning();
        }
    }
    document.querySelectorAll('[data-role="mode-tab"]').forEach(function (tab) {
        tab.addEventListener('click', function () { switchMode(tab.getAttribute('data-mode')); });
    });
    var qrUseManualBtn = document.querySelector('[data-action="qr-use-manual"]');
    if (qrUseManualBtn) qrUseManualBtn.addEventListener('click', function () { switchMode('manual'); });

    // ────────────────────────────────────────────────────────────────────
    // مسح QR — BarcodeDetector الأصلية (بلا اعتمادية خارجية)
    // ────────────────────────────────────────────────────────────────────
    var supportsBarcodeDetector = (typeof window !== 'undefined') && ('BarcodeDetector' in window);
    var detector = supportsBarcodeDetector ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;
    var videoEl = byRole('qr-video');
    var mediaStream = null;
    var scanning = false;
    var paused = false;
    var videoDevices = [];
    var currentDeviceIndex = 0;
    var lastDecodedValue = null;
    var lastDecodedAt = 0;

    function setQrStatus(text) {
        var el = byRole('qr-status');
        if (el) el.textContent = text;
    }

    function stopCamera() {
        scanning = false;
        if (mediaStream) {
            mediaStream.getTracks().forEach(function (track) { track.stop(); });
            mediaStream = null;
        }
    }

    function scanLoop() {
        if (!scanning) return;
        if (!paused && detector && videoEl && videoEl.readyState >= 2) {
            detector.detect(videoEl).then(function (codes) {
                if (codes && codes.length > 0) {
                    handleQrDecoded(codes[0].rawValue);
                }
            }).catch(function () {
                // فشل إطار واحد لا يوقف الحلقة — نحاول الإطار التالي فقط.
            });
        }
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(scanLoop);
        }
    }

    function handleQrDecoded(rawValue) {
        var now = Date.now();
        // منع المسح المكرَّر لنفس الرمز خلال نافذة زمنية قصيرة (Requirement:
        // "Prevent duplicate scans") — إضافة لإيقاف المسح فوراً (paused=true)
        // حتى ينتهي المستخدم من هذه الشاشة أو يلغيها.
        if (rawValue === lastDecodedValue && (now - lastDecodedAt) < CONFIG.duplicateScanCooldownMs) {
            return;
        }
        lastDecodedValue = rawValue;
        lastDecodedAt = now;

        pauseScanning();
        setQrStatus('جاري المعالجة…');

        postAjax(CONFIG.scanAction, CONFIG.checkinNonce, { qr_payload: rawValue }).then(function (json) {
            if (json && json.success && json.data && json.data.guest) {
                var guest = json.data.guest;
                openConfirmationScreen(guest.name, guest.expected_guest_count, 'qr', rawValue, 'qr');
            } else {
                var reason = (json && json.data && json.data.reason) || 'invalid_qr';
                showErrorBanner(reasonToMessage(reason));
                setQrStatus('جاهز للمسح');
                resumeScanning();
            }
        }).catch(function () {
            showErrorBanner('تعذّر الاتصال بالخادم، تحقّق من الاتصال بالإنترنت.');
            setQrStatus('جاهز للمسح');
            resumeScanning();
        });
    }

    function pauseScanning() {
        paused = true;
    }
    function resumeScanning() {
        if (!scanning) return;
        paused = false;
        setQrStatus('جاهز للمسح');
    }

    function listCameras() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return Promise.resolve([]);
        return navigator.mediaDevices.enumerateDevices().then(function (devices) {
            return devices.filter(function (d) { return d.kind === 'videoinput'; });
        });
    }

    function startCamera(deviceId) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            byRole('qr-unsupported').classList.remove('hidden');
            setQrStatus('غير مدعوم');
            return Promise.resolve();
        }
        var constraints = deviceId
            ? { video: { deviceId: { exact: deviceId } } }
            : { video: { facingMode: { ideal: 'environment' } } };

        return navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
            mediaStream = stream;
            if (videoEl) {
                videoEl.srcObject = stream;
                videoEl.play();
            }
            var guidance = byRole('qr-permission-guidance');
            if (guidance) guidance.classList.add('hidden');
            scanning = true;
            paused = false;
            setQrStatus('جاهز للمسح');
            scanLoop();
            return listCameras();
        }).then(function (devices) {
            videoDevices = devices || [];
            var switchBtn = document.querySelector('[data-action="qr-switch-camera"]');
            if (switchBtn) switchBtn.classList.toggle('hidden', videoDevices.length < 2);
        }).catch(function () {
            var guidance = byRole('qr-permission-guidance');
            if (guidance) guidance.classList.remove('hidden');
            setQrStatus('تعذّر الوصول إلى الكاميرا');
        });
    }

    var qrStartBtn = document.querySelector('[data-action="qr-start"]');
    if (qrStartBtn) {
        qrStartBtn.addEventListener('click', function () {
            if (!supportsBarcodeDetector) {
                byRole('qr-unsupported').classList.remove('hidden');
                setQrStatus('غير مدعوم على هذا المتصفح');
                return;
            }
            stopCamera();
            startCamera(null);
        });
    }

    var qrSwitchBtn = document.querySelector('[data-action="qr-switch-camera"]');
    if (qrSwitchBtn) {
        qrSwitchBtn.addEventListener('click', function () {
            if (videoDevices.length < 2) return;
            currentDeviceIndex = (currentDeviceIndex + 1) % videoDevices.length;
            stopCamera();
            startCamera(videoDevices[currentDeviceIndex].deviceId);
        });
    }

    if (!supportsBarcodeDetector) {
        var unsupportedEl = byRole('qr-unsupported');
        if (unsupportedEl) unsupportedEl.classList.remove('hidden');
        setQrStatus('غير مدعوم على هذا المتصفح');
    }

    // ────────────────────────────────────────────────────────────────────
    // البحث اليدوي — Debounce + إلغاء الطلبات القديمة (Cancel Stale Searches)
    // ────────────────────────────────────────────────────────────────────
    var searchDebounceTimer = null;
    var searchRequestToken = 0;

    function renderSearchResults(payload) {
        var loadingEl = byRole('search-loading');
        var noResultsEl = byRole('search-no-results');
        var listEl = byRole('search-results');
        if (loadingEl) loadingEl.classList.add('hidden');

        var result = (payload && payload.result) || 'no_results';
        var candidates = (payload && Array.isArray(payload.candidates)) ? payload.candidates : [];

        if (result === 'no_results' || candidates.length === 0) {
            if (noResultsEl) noResultsEl.classList.remove('hidden');
            if (listEl) { listEl.classList.add('hidden'); listEl.innerHTML = ''; }
            return;
        }

        if (noResultsEl) noResultsEl.classList.add('hidden');
        if (!listEl) return;

        listEl.innerHTML = candidates.map(function (c, index) {
            var statusLabel = c.checked_in === true ? ' (مسجَّل مسبقاً)' : '';
            return '' +
                '<li>' +
                    '<button type="button" class="w-full px-4 py-3 text-right hover:bg-secondary/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary" ' +
                        'data-action="select-candidate" data-index="' + index + '">' +
                        '<span class="block text-sm font-bold text-foreground">' + escapeHtml(c.name || 'ضيف') + statusLabel + '</span>' +
                        '<span class="mt-0.5 block text-xs text-foreground/60">' + escapeHtml(c.masked_phone || '') + ' — متوقَّع ' + escapeHtml(c.expected_guest_count != null ? c.expected_guest_count : 1) + '</span>' +
                    '</button>' +
                '</li>';
        }).join('');
        listEl.classList.remove('hidden');

        listEl.querySelectorAll('[data-action="select-candidate"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-index'), 10);
                var candidate = candidates[idx];
                if (!candidate || !candidate.reference) return;
                openConfirmationScreen(candidate.name, candidate.expected_guest_count, 'qr', candidate.reference, 'manual');
            });
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    var searchInput = document.getElementById('manual-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var query = searchInput.value.trim();
            if (searchDebounceTimer) clearTimeout(searchDebounceTimer);

            var loadingEl = byRole('search-loading');
            var noResultsEl = byRole('search-no-results');
            var listEl = byRole('search-results');

            if (query === '') {
                if (loadingEl) loadingEl.classList.add('hidden');
                if (noResultsEl) noResultsEl.classList.add('hidden');
                if (listEl) { listEl.classList.add('hidden'); listEl.innerHTML = ''; }
                return;
            }

            searchDebounceTimer = setTimeout(function () {
                var myToken = ++searchRequestToken;
                if (loadingEl) loadingEl.classList.remove('hidden');
                if (noResultsEl) noResultsEl.classList.add('hidden');

                postAjax(CONFIG.searchAction, CONFIG.checkinNonce, { query: query }).then(function (json) {
                    // إلغاء الطلبات القديمة: تجاهل أي استجابة وصلت بعد أن كتب
                    // المستخدم استعلاماً أحدث (Requirement: "Cancel stale
                    // searches").
                    if (myToken !== searchRequestToken) return;
                    if (json && json.success) {
                        renderSearchResults(json.data);
                    } else {
                        var reason = (json && json.data && json.data.reason) || 'unknown_error';
                        if (loadingEl) loadingEl.classList.add('hidden');
                        showErrorBanner(reasonToMessage(reason));
                    }
                }).catch(function () {
                    if (myToken !== searchRequestToken) return;
                    if (loadingEl) loadingEl.classList.add('hidden');
                    showErrorBanner('تعذّر الاتصال بالخادم، تحقّق من الاتصال بالإنترنت.');
                });
            }, CONFIG.searchDebounceMs);
        });
    }

    // ────────────────────────────────────────────────────────────────────
    // أزرار شاشات التأكيد/النجاح/التكرار
    // ────────────────────────────────────────────────────────────────────
    var confirmSubmitBtn = document.querySelector('[data-action="confirm-submit"]');
    if (confirmSubmitBtn) confirmSubmitBtn.addEventListener('click', submitConfirmation);

    var confirmCancelBtn = document.querySelector('[data-action="confirm-cancel"]');
    if (confirmCancelBtn) confirmCancelBtn.addEventListener('click', returnToModeScreen);

    var successContinueBtn = document.querySelector('[data-action="success-continue"]');
    if (successContinueBtn) successContinueBtn.addEventListener('click', returnToModeScreen);

    var duplicateBackBtn = document.querySelector('[data-action="duplicate-back"]');
    if (duplicateBackBtn) duplicateBackBtn.addEventListener('click', returnToModeScreen);

    // ────────────────────────────────────────────────────────────────────
    // بدء أولي: وضع QR افتراضياً (Requirement: "Support mobile first")؛ لا
    // بدء كاميرا تلقائياً بلا تفاعل المستخدم (يتطلَّب إذناً صريحاً من
    // المتصفح على أي حال) — المستخدم يضغط "بدء المسح" صراحة.
    // ────────────────────────────────────────────────────────────────────
    hideAllScreens();
    byRole('qr-panel').classList.remove('hidden');
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
<?php
exit;
