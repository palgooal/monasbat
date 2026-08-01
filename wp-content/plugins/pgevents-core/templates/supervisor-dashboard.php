<?php
/**
 * ============================================================================
 * Supervisor Attendance Dashboard — Entry Check-in Supervisors، Phase 6
 * ============================================================================
 * "Supervisor Attendance Dashboard UI" RFC — "This phase is presentation
 * only. Every statistic must come exclusively from PGE_Attendance_Dashboard_
 * Provider. No UI component may calculate attendance numbers. No SQL may
 * exist in templates. No attendance calculations may exist in controllers."
 *
 * التدفّق الإلزامي (بلا أي انحراف): Supervisor Dashboard (هذا القالب) →
 * PGE_Supervisor_Portal_Middleware::authorize() (نفس آلية Phase 3.5 الحالية،
 * بلا تعديل) → PGE_Attendance_Dashboard_Provider::get_dashboard() (نفس واجهة
 * Phase 5 الحالية، بلا تعديل — تُعيد تنفيذ تحقّقها الخاص داخلياً أيضاً، تكرار
 * دفاعي مقصود) → Attendance Statistics Engine → قاعدة البيانات. هذا الملف لا
 * يستدعي PGE_Attendance_Statistics_Service مباشرة إطلاقاً، ولا $wpdb، ولا أي
 * استعلام SQL بأي شكل.
 *
 * قالب مستقل (نفس فلسفة templates/supervisor-portal.php حرفياً — المشرف لا
 * يملك حساب ووردبريس، فلا معنى لـget_header()/get_footer() من الثيم).
 *
 * القيم المعروضة أدناه (بطاقات الملخّص، آخر عمليات التسجيل، قسم المشرف) هي
 * حرفياً نفس المفاتيح التي تُعيدها PGE_Attendance_Dashboard_Provider::
 * get_dashboard()['data'] — لا حساب/تجميع/تنسيق رقمي جديد هنا عدا تنسيق عرض
 * بحت (مثل تحويل نسبة 0.75 إلى النص "75%"، أو اختيار تسمية عربية لحالة/طريقة
 * معروفة) — هذا تنسيق عرض (Presentation Formatting)، وليس حساب إحصاء جديد؛
 * الرقم/الحالة الخام لم تتغيّر، فقط طريقة كتابته.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('PGE_Supervisor_Portal_Middleware') || !class_exists('PGE_Attendance_Dashboard_Provider')) {
    // بيئة غير مكتملة — رفض آمن، لا كشف تفاصيل داخلية (Fail Closed)، نفس نمط
    // supervisor-portal.php حرفياً.
    status_header(401);
    nocache_headers();
    wp_die('تعذّر تحميل لوحة الحضور حالياً.', 'خطأ', ['response' => 401]);
}

// ── الخطوة 1: التفويض (لا وثوق بأي معامل طلب — نفس Middleware المُستخدَمة في
// supervisor-portal.php وcheckin-ajax.php حرفياً، بلا أي تعديل عليها) ───────
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
    <title><?php echo esc_html($title); ?> — لوحة الحضور</title>
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

// ── الخطوة 2: جلب حزمة بيانات اللوحة كاملة عبر Provider حصراً (Requirement:
// "The flow must remain: Supervisor Dashboard → Provider → Authorization →
// Statistics Engine → Database") — لا استدعاء آخر بديل، ولا استعلام مباشر ──
$event_id = (int) $authorization['assignment_id'] > 0 ? (int) $authorization['event_id'] : 0;
$assignment_id = (int) $authorization['assignment_id'];

$dashboard = PGE_Attendance_Dashboard_Provider::get_dashboard($event_id);

if (($dashboard['result'] ?? '') !== 'authorized') {
    $http_status = (int) ($dashboard['http_status'] ?? 403);
    $reason = (string) ($dashboard['reason'] ?? 'unauthorized');
    $messages_dashboard = [
        'event_mismatch'   => 'تعذّر العثور على بيانات هذه المناسبة المرتبطة بجلستك.',
        'invalid_event_id' => 'معرِّف المناسبة غير صالح.',
    ];
    $title = 'تعذّر عرض لوحة الحضور';
    $message = $messages_dashboard[$reason] ?? 'تعذّر تحميل بيانات لوحة الحضور حالياً.';

    status_header($http_status);
    nocache_headers();
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($title); ?></title>
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

$data = $dashboard['data'] ?? [];
$event_summary = $data['event_summary'] ?? [];
$supervisor_summary = is_array($data['supervisor_summary'] ?? null) ? $data['supervisor_summary'] : [];
$attendance_summary = is_array($data['attendance_summary'] ?? null) ? $data['attendance_summary'] : [];
$recent_checkins = is_array($data['recent_checkins'] ?? null) ? $data['recent_checkins'] : [];
$quick_actions = is_array($data['quick_actions'] ?? null) ? $data['quick_actions'] : [];
$dashboard_metadata = is_array($data['dashboard_metadata'] ?? null) ? $data['dashboard_metadata'] : [];
$generated_at = (string) ($dashboard_metadata['generated_at'] ?? '');

// ── نهاية تحضير البيانات (Data Preparation) — كل ما بعد هذا السطر عرض/تنسيق
// بحت فقط، بلا أي استعلام أو حساب إحصاء جديد؛ المصفوفات الأربع أعلاه فقط هي
// ما يُعرَض حرفياً أدناه ("Display only values returned by the Provider"). ──

// --- تنسيق عرض بحت (Presentation Formatting) — لا حساب إحصاء جديد ---------
$attendance_rate_percent = round(((float) ($attendance_summary['attendance_rate'] ?? 0)) * 100, 1);
$average_guests_display = number_format((float) ($attendance_summary['average_guests_per_invitation'] ?? 0), 2);
$is_empty_dashboard = ((int) ($attendance_summary['total_invitations'] ?? 0)) === 0;

$status_labels = [
    'invited' => 'مدعو',
    'pending' => 'قيد الانتظار',
    'active'  => 'نشط',
    'revoked' => 'مُلغى',
];
$method_labels = [
    'qr'     => 'QR',
    'manual' => 'يدوي',
];

// اسم المشرف/حالته الظاهرة على الشاشة الحالية — بحث بسيط (lookup)، لا حساب،
// عن الصف المطابق لـassignment_id الجلسة الحالية داخل supervisor_summary
// (المُعاد أصلاً من Provider) — لا استدعاء جديد لأي خدمة أخرى.
$current_supervisor_row = null;
foreach ($supervisor_summary as $__row) {
    if ((int) ($__row['assignment_id'] ?? 0) === $assignment_id) {
        $current_supervisor_row = $__row;
        break;
    }
}
// "آخر نشاط" — بحث بسيط (lookup) عن أحدث سطر في recent_checkins (المُرتَّبة
// أصلاً الأحدث أولاً من قِبَل Provider) لنفس assignment_id — ليس حساباً
// إحصائياً جديداً، مجرّد أول تطابق ضمن قائمة مُرتَّبة أصلاً.
$current_supervisor_last_activity = '';
foreach ($recent_checkins as $__row) {
    if ((int) ($__row['assignment_id'] ?? 0) === $assignment_id) {
        $current_supervisor_last_activity = (string) ($__row['checked_in_at'] ?? '');
        break;
    }
}
unset($__row);

$nonce = wp_create_nonce('pge_supervisor_dashboard_nonce');
$ajax_url = admin_url('admin-ajax.php');
$logout_url = wp_nonce_url(home_url('/supervisor/logout/'), 'pge_supervisor_logout');
$event_title = (string) ($event_summary['title'] ?? '');

status_header(200);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($event_title !== '' ? $event_title . ' — لوحة الحضور' : 'لوحة الحضور'); ?></title>
    <?php wp_head(); ?>
</head>
<body>
<div class="relative min-h-screen bg-background font-arabic" dir="rtl" data-testid="supervisor-dashboard-root" data-current-assignment-id="<?php echo esc_attr((string) $assignment_id); ?>">
<div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">

    <!-- رأس اللوحة -->
    <header class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-foreground"><?php echo esc_html($event_title !== '' ? $event_title : 'لوحة الحضور'); ?></h1>
            <p class="mt-1 text-xs text-foreground/60">
                عبر <span data-role="via-badge"><?php echo esc_html((string) ($dashboard['via'] ?? '')); ?></span> —
                <span data-role="last-updated-label" aria-live="polite">آخر تحديث: <span data-role="last-updated-time" data-generated-at="<?php echo esc_attr($generated_at); ?>"><?php echo esc_html($generated_at !== '' ? $generated_at : 'الآن'); ?></span></span>
                <span data-role="refresh-indicator" class="hidden" aria-hidden="true">⟳</span>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?php echo esc_url($logout_url); ?>"
               class="rounded-xl px-3 py-1.5 text-xs font-bold text-destructive-text transition-colors hover:bg-destructive/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-destructive">
                تسجيل الخروج
            </a>
        </div>
    </header>

    <div data-role="error-banner" class="mb-4 hidden rounded-2xl bg-destructive/10 p-4 text-sm font-medium text-destructive-text" role="alert" aria-live="assertive">
        تعذّر تحديث البيانات، سيُعاد المحاولة تلقائياً.
    </div>

    <!-- حالة "لا بيانات بعد" -->
    <div data-role="empty-state" class="<?php echo $is_empty_dashboard ? '' : 'hidden'; ?> mb-6 rounded-2xl border border-dashed border-border bg-white p-8 text-center" role="status">
        <p class="text-sm font-bold text-foreground/70">لا توجد بيانات حضور بعد لهذه المناسبة.</p>
    </div>

    <!-- هيكل تحميل (Loading Skeleton) — مخفي افتراضياً، يُستخدَم فقط أثناء
         إعادة تحميل كاملة يدوية عبر JS، لا يُعرَض على أول تحميل (SSR) -->
    <div data-role="loading-skeleton" class="hidden mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-hidden="true">
        <?php for ($__i = 0; $__i < 4; $__i++): ?>
        <div class="h-24 animate-pulse rounded-2xl bg-secondary/40"></div>
        <?php endfor; unset($__i); ?>
    </div>

    <!-- بطاقات الملخّص (Requirement: القيم فقط من Provider، بلا حساب جانبي) -->
    <section aria-labelledby="summary-cards-heading" class="mb-8">
        <h2 id="summary-cards-heading" class="mb-3 text-sm font-bold uppercase tracking-wide text-foreground/50">ملخّص الحضور</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" data-role="summary-cards">
            <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="total_invitations">
                <p class="text-xs font-bold text-foreground/50">إجمالي الدعوات</p>
                <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="total_invitations"><?php echo esc_html((string) (int) ($attendance_summary['total_invitations'] ?? 0)); ?></p>
            </div>
            <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="checked_in_invitations">
                <p class="text-xs font-bold text-foreground/50">الدعوات المسجَّلة</p>
                <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="checked_in_invitations"><?php echo esc_html((string) (int) ($attendance_summary['checked_in_invitations'] ?? 0)); ?></p>
            </div>
            <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="pending_invitations">
                <p class="text-xs font-bold text-foreground/50">الدعوات المُعلَّقة</p>
                <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="pending_invitations"><?php echo esc_html((string) (int) ($attendance_summary['pending_invitations'] ?? 0)); ?></p>
            </div>
            <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="expected_guests">
                <p class="text-xs font-bold text-foreground/50">الضيوف المتوقَّعون</p>
                <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="expected_guests"><?php echo esc_html((string) (int) ($attendance_summary['expected_guests'] ?? 0)); ?></p>
            </div>
            <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="actual_attendees">
                <p class="text-xs font-bold text-foreground/50">الحاضرون فعلياً</p>
                <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="actual_attendees"><?php echo esc_html((string) (int) ($attendance_summary['actual_attendees'] ?? 0)); ?></p>
            </div>
            <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="attendance_rate">
                <p class="text-xs font-bold text-foreground/50">نسبة الحضور</p>
                <p class="mt-2 text-2xl font-extrabold text-foreground"><span data-metric="attendance_rate_percent"><?php echo esc_html((string) $attendance_rate_percent); ?></span>%</p>
            </div>
            <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="average_guests_per_invitation">
                <p class="text-xs font-bold text-foreground/50">متوسط الضيوف لكل دعوة</p>
                <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="average_guests_per_invitation"><?php echo esc_html($average_guests_display); ?></p>
            </div>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- آخر عمليات تسجيل الحضور -->
        <section aria-labelledby="recent-checkins-heading" class="lg:col-span-2">
            <h2 id="recent-checkins-heading" class="mb-3 text-sm font-bold uppercase tracking-wide text-foreground/50">آخر عمليات تسجيل الحضور</h2>
            <div class="overflow-hidden rounded-2xl border border-border bg-white">
                <ul class="divide-y divide-border" data-role="recent-checkins-list">
                    <?php if (empty($recent_checkins)): ?>
                    <li class="p-5 text-center text-sm text-foreground/60" data-role="recent-checkins-empty">لا توجد عمليات تسجيل حضور بعد.</li>
                    <?php else: foreach ($recent_checkins as $__checkin): ?>
                    <li class="flex items-center justify-between gap-3 p-4" data-row="recent-checkin">
                        <div>
                            <p class="text-sm font-bold text-foreground" data-field="guest_name"><?php echo esc_html((string) ($__checkin['guest_name'] !== '' ? $__checkin['guest_name'] : 'ضيف')); ?></p>
                            <p class="mt-0.5 text-xs text-foreground/60" data-field="checked_in_at"><?php echo esc_html((string) ($__checkin['checked_in_at'] ?? '')); ?></p>
                        </div>
                        <div class="flex items-center gap-3 text-xs">
                            <span class="rounded-full bg-secondary/40 px-2 py-1 font-bold text-foreground/70" data-field="method">
                                <?php echo esc_html($method_labels[$__checkin['method'] ?? ''] ?? (string) ($__checkin['method'] ?? '')); ?>
                            </span>
                            <span class="text-foreground/60">
                                متوقَّع <span data-field="expected_count"><?php echo esc_html((string) (int) ($__checkin['expected_count'] ?? 0)); ?></span> /
                                فعلي <span data-field="actual_count"><?php echo esc_html((string) (int) ($__checkin['actual_count'] ?? 0)); ?></span>
                            </span>
                        </div>
                    </li>
                    <?php endforeach; unset($__checkin); endif; ?>
                </ul>
            </div>
        </section>

        <div class="flex flex-col gap-6">
            <!-- قسم المشرف -->
            <section aria-labelledby="supervisor-section-heading">
                <h2 id="supervisor-section-heading" class="mb-3 text-sm font-bold uppercase tracking-wide text-foreground/50">بيانات المشرف</h2>
                <div class="rounded-2xl border border-border bg-white p-5" data-role="supervisor-section">
                    <p class="text-sm font-bold text-foreground" data-field="supervisor_name"><?php echo esc_html((string) ($current_supervisor_row['supervisor_name'] ?? '')); ?></p>
                    <p class="mt-1 text-xs text-foreground/60" data-field="assigned_event"><?php echo esc_html($event_title); ?></p>
                    <p class="mt-2 text-xs font-bold" data-field="assignment_status">
                        <?php
                        $__status = (string) ($current_supervisor_row['status'] ?? '');
                        echo esc_html($status_labels[$__status] ?? ($__status !== '' ? $__status : '—'));
                        ?>
                    </p>
                    <p class="mt-2 text-xs text-foreground/60" data-field="last_activity">
                        آخر نشاط: <?php echo esc_html($current_supervisor_last_activity !== '' ? $current_supervisor_last_activity : 'لا يوجد نشاط بعد'); ?>
                    </p>
                </div>
            </section>

            <!-- إجراءات سريعة -->
            <section aria-labelledby="quick-actions-heading">
                <h2 id="quick-actions-heading" class="mb-3 text-sm font-bold uppercase tracking-wide text-foreground/50">إجراءات سريعة</h2>
                <div class="flex flex-col gap-2" data-role="quick-actions">
                    <button type="button" disabled aria-disabled="true"
                            class="flex items-center justify-between rounded-xl border border-border bg-secondary/30 px-4 py-3 text-sm font-bold text-foreground/40"
                            data-action="manual-checkin" title="قريباً">
                        تسجيل يدوي
                        <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-foreground/50">قريباً</span>
                    </button>
                    <button type="button" disabled aria-disabled="true"
                            class="flex items-center justify-between rounded-xl border border-border bg-secondary/30 px-4 py-3 text-sm font-bold text-foreground/40"
                            data-action="qr-checkin" title="قريباً">
                        مسح QR
                        <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-foreground/50">قريباً</span>
                    </button>
                    <button type="button"
                            class="rounded-xl bg-primary px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            data-action="refresh-dashboard">
                        تحديث اللوحة
                    </button>
                </div>
            </section>
        </div>
    </div>

</div>
</div>
<script>
(function () {
    "use strict";
    var CONFIG = {
        ajaxUrl: <?php echo wp_json_encode($ajax_url); ?>,
        nonce: <?php echo wp_json_encode($nonce); ?>,
        action: "pge_supervisor_dashboard_data",
        pollIntervalMs: 30000,
        currentAssignmentId: <?php echo (int) $assignment_id; ?>,
        eventTitle: <?php echo wp_json_encode($event_title); ?>,
        statusLabels: <?php echo wp_json_encode($status_labels); ?>,
        methodLabels: <?php echo wp_json_encode($method_labels); ?>
    };
    var isRefreshing = false;
    var pollTimer = null;

    function byRole(role) { return document.querySelector('[data-role="' + role + '"]'); }
    function byMetric(name) { return document.querySelector('[data-metric="' + name + '"]'); }

    function showErrorBanner(show) {
        var el = byRole('error-banner');
        if (el) el.classList.toggle('hidden', !show);
    }
    function setRefreshIndicator(active) {
        var el = byRole('refresh-indicator');
        if (el) el.classList.toggle('hidden', !active);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ────────────────────────────────────────────────────────────────────
    // اللقطة الواحدة الذرية (Phase 6 Final Fix — "Atomic Dashboard Snapshot")
    // التدفّق الإلزامي: استلام الحزمة كاملة ↓ التحقّق من الحزمة (validatePayload
    // يرمي استثناءً فوراً على أي قسم ناقص/تالف قبل أي لمسة DOM) ↓ بناء كل شيء
    // في الذاكرة أولاً (دوال build*) ↓ التزام واحد يستبدل كل الأقسام معاً
    // (applySnapshot) — لا تحديث جزئي أبداً؛ فشل أي خطوة (رمي استثناء) يُلغي
    // اللقطة بأكملها ويُبقي اللقطة السابقة كما هي تماماً (يُلتقَط الاستثناء في
    // fetchDashboard فقط، بعد اكتمال كل عمليات البناء/الالتزام). ─────────────

    function validatePayload(data) {
        if (!data || typeof data !== 'object') throw new Error('payload_not_object');

        var summary = data.attendance_summary;
        if (!summary || typeof summary !== 'object') throw new Error('missing_attendance_summary');
        ['total_invitations', 'checked_in_invitations', 'pending_invitations', 'expected_guests', 'actual_attendees', 'attendance_rate', 'average_guests_per_invitation'].forEach(function (key) {
            if (summary[key] === undefined) throw new Error('missing_summary_field_' + key);
        });

        if (!Array.isArray(data.recent_checkins)) throw new Error('missing_recent_checkins');
        if (!Array.isArray(data.supervisor_summary)) throw new Error('missing_supervisor_summary');
        if (!data.quick_actions || typeof data.quick_actions !== 'object') throw new Error('missing_quick_actions');

        var meta = data.dashboard_metadata;
        if (!meta || typeof meta !== 'object' || typeof meta.generated_at !== 'string' || meta.generated_at === '') {
            throw new Error('missing_dashboard_metadata');
        }
    }

    function buildSummaryUpdates(summary) {
        var keys = ['total_invitations', 'checked_in_invitations', 'pending_invitations', 'expected_guests', 'actual_attendees', 'average_guests_per_invitation'];
        var updates = [];
        keys.forEach(function (key) {
            var el = byMetric(key);
            if (!el) throw new Error('missing_metric_element_' + key);
            updates.push({ el: el, text: String(summary[key]) });
        });
        var rateEl = byMetric('attendance_rate_percent');
        if (!rateEl) throw new Error('missing_metric_element_attendance_rate_percent');
        updates.push({ el: rateEl, text: String(Math.round(summary.attendance_rate * 1000) / 10) });
        return updates;
    }

    function buildRecentCheckinsHtml(list) {
        if (!list.length) {
            return '<li class="p-5 text-center text-sm text-foreground/60" data-role="recent-checkins-empty">لا توجد عمليات تسجيل حضور بعد.</li>';
        }
        return list.map(function (row) {
            var methodRaw = row.method || '';
            var methodLabel = CONFIG.methodLabels[methodRaw] !== undefined ? CONFIG.methodLabels[methodRaw] : methodRaw;
            var guestName = row.guest_name ? row.guest_name : 'ضيف';
            return '' +
                '<li class="flex items-center justify-between gap-3 p-4" data-row="recent-checkin" data-assignment-id="' + escapeHtml(row.assignment_id != null ? row.assignment_id : '') + '">' +
                    '<div>' +
                        '<p class="text-sm font-bold text-foreground" data-field="guest_name">' + escapeHtml(guestName) + '</p>' +
                        '<p class="mt-0.5 text-xs text-foreground/60" data-field="checked_in_at">' + escapeHtml(row.checked_in_at || '') + '</p>' +
                    '</div>' +
                    '<div class="flex items-center gap-3 text-xs">' +
                        '<span class="rounded-full bg-secondary/40 px-2 py-1 font-bold text-foreground/70" data-field="method">' + escapeHtml(methodLabel) + '</span>' +
                        '<span class="text-foreground/60">متوقَّع <span data-field="expected_count">' + escapeHtml(row.expected_count != null ? row.expected_count : 0) + '</span> / فعلي <span data-field="actual_count">' + escapeHtml(row.actual_count != null ? row.actual_count : 0) + '</span></span>' +
                    '</div>' +
                '</li>';
        }).join('');
    }

    // نفس منطق البحث البسيط (lookup) المُستخدَم في القالب من جهة الخادم (SSR)
    // حرفياً — لا حساب إحصاء جديد، فقط إيجاد الصف المطابق ضمن مصفوفة مُعادة
    // أصلاً من Provider.
    function findCurrentSupervisorRow(supervisors, currentAssignmentId) {
        for (var i = 0; i < supervisors.length; i++) {
            if (Number(supervisors[i].assignment_id) === Number(currentAssignmentId)) return supervisors[i];
        }
        return null;
    }
    function findCurrentSupervisorLastActivity(recent, currentAssignmentId) {
        for (var i = 0; i < recent.length; i++) {
            if (Number(recent[i].assignment_id) === Number(currentAssignmentId)) {
                return recent[i].checked_in_at || '';
            }
        }
        return '';
    }

    function buildSupervisorHtml(supervisors, recent) {
        var row = findCurrentSupervisorRow(supervisors, CONFIG.currentAssignmentId);
        var lastActivity = findCurrentSupervisorLastActivity(recent, CONFIG.currentAssignmentId);
        var statusRaw = row && row.status ? row.status : '';
        var statusLabel = CONFIG.statusLabels[statusRaw] !== undefined ? CONFIG.statusLabels[statusRaw] : (statusRaw !== '' ? statusRaw : '—');
        return '' +
            '<p class="text-sm font-bold text-foreground" data-field="supervisor_name">' + escapeHtml(row && row.supervisor_name ? row.supervisor_name : '') + '</p>' +
            '<p class="mt-1 text-xs text-foreground/60" data-field="assigned_event">' + escapeHtml(CONFIG.eventTitle) + '</p>' +
            '<p class="mt-2 text-xs font-bold" data-field="assignment_status">' + escapeHtml(statusLabel) + '</p>' +
            '<p class="mt-2 text-xs text-foreground/60" data-field="last_activity">آخر نشاط: ' + escapeHtml(lastActivity !== '' ? lastActivity : 'لا يوجد نشاط بعد') + '</p>';
    }

    function applySnapshot(data) {
        // ── 1) التحقّق أولاً — قبل أي لمسة DOM إطلاقاً ──────────────────
        validatePayload(data);

        var summary = data.attendance_summary;
        var recent = data.recent_checkins;
        var supervisors = data.supervisor_summary;
        var meta = data.dashboard_metadata;

        // ── 2) البناء في الذاكرة فقط — لا كتابة DOM بعد ─────────────────
        var summaryUpdates = buildSummaryUpdates(summary);
        var recentHtml = buildRecentCheckinsHtml(recent);
        var supervisorHtml = buildSupervisorHtml(supervisors, recent);
        var lastUpdatedText = String(meta.generated_at);

        var summaryCardsEl = byRole('summary-cards');
        var recentListEl = byRole('recent-checkins-list');
        var supervisorSectionEl = byRole('supervisor-section');
        var lastUpdatedEl = byRole('last-updated-time');
        if (!summaryCardsEl || !recentListEl || !supervisorSectionEl || !lastUpdatedEl) {
            throw new Error('dashboard_dom_targets_missing');
        }

        // ── 3) الالتزام — كل الأقسام تُستبدَل معاً ضمن نفس دورة الأحداث
        // (نفس اللقطة/generated_at لكل الأقسام دفعة واحدة، بلا فاصل مرئي
        // بين قسم وآخر) ───────────────────────────────────────────────
        summaryUpdates.forEach(function (u) { u.el.textContent = u.text; });
        var emptyEl = byRole('empty-state');
        if (emptyEl) emptyEl.classList.toggle('hidden', (summary.total_invitations || 0) !== 0);
        recentListEl.innerHTML = recentHtml;
        supervisorSectionEl.innerHTML = supervisorHtml;
        lastUpdatedEl.textContent = lastUpdatedText;
        lastUpdatedEl.setAttribute('data-generated-at', lastUpdatedText);
    }

    function fetchDashboard() {
        if (isRefreshing) return; // Requirement: "skip the next cycle" إن كانت دورة سابقة لا تزال قيد التنفيذ
        isRefreshing = true;
        setRefreshIndicator(true);

        var body = new URLSearchParams();
        body.set('action', CONFIG.action);
        body.set('nonce', CONFIG.nonce);

        fetch(CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json && json.success) {
                    applySnapshot(json.data); // قد يرمي استثناءً — يُلتقَط أدناه في catch()
                    showErrorBanner(false);
                } else {
                    showErrorBanner(true);
                }
            })
            .catch(function () {
                // أي فشل هنا — سواء في الجلب نفسه، أو في التحقّق من الحزمة،
                // أو أثناء تجهيز/تنفيذ أي قسم من أقسامها — يجب ألا يُغيّر أي
                // شيء على الشاشة الحالية إطلاقاً (البند: الإبقاء على اللقطة
                // السابقة ظاهرة تماماً كما هي، وعرض تنبيه غير حاجب فقط، دون
                // خلط جيلين مختلفين من البيانات). لا نحدّث "آخر تحديث" هنا —
                // قيمتها تأتي حصراً من نفس generated_at الخاص بلقطة ناجحة
                // كاملة.
                showErrorBanner(true);
            })
            .finally(function () {
                isRefreshing = false;
                setRefreshIndicator(false);
            });
    }

    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(fetchDashboard, CONFIG.pollIntervalMs);
    }

    var refreshBtn = document.querySelector('[data-action="refresh-dashboard"]');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            fetchDashboard();
            startPolling(); // إعادة ضبط المؤقّت — لا نُنفِّذ طلباً مكرَّراً بعد ثوانٍ قليلة (Requirement: "Do not duplicate requests")
        });
    }

    // لا طلب فوري عند التحميل — الصفحة مُصيَّرة على الخادم (SSR) ببيانات
    // حديثة فعلاً؛ أول تحديث AJAX يبدأ بعد دورة الاستطلاع الأولى فقط.
    startPolling();
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
<?php
exit;
