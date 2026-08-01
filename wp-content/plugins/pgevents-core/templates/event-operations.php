<?php
/**
 * ============================================================================
 * لوحة عمليات المناسبة الحيّة (Event Operations Dashboard) — Entry Check-in
 * Supervisors، Phase 10 ("Event Operations" RFC)
 * ============================================================================
 * "Pure orchestration interface for the live event day. Aggregate existing
 * capabilities. No redesign of business logic. No duplication of business
 * logic. Reuse every approved service."
 *
 * هذا الملف عرض فقط — كل بيانات الأقسام الخمسة تأتي حصراً من خدمات مُعتمَدة
 * غير مُعدَّلة في حسابها (راجع includes/event-operations-ajax.php للتفصيل
 * الكامل، وdocs/EVENT-OPERATIONS.md للمعمارية): PGE_Attendance_Dashboard_
 * Provider (الإحصاء/المشرفون/آخر الحضور)، PGE_Invitation_Service (عدّاد
 * الملغاة + بحث الدعوات)، PGE_Supervisor_Assignment_Service (آخر نشاط
 * المشرف + بحث المشرفين). لا SQL هنا، لا حساب إحصاء جديد.
 *
 * قالب مضيف عادي (نفس فلسفة event-supervisors.php/event-invitations.php):
 * get_header()/get_footer() من الثيم، نفس تفويض pge_event_guests_user_can_
 * manage() حرفياً.
 *
 * القراءة الأولية هنا (SSR) هي استدعاء **مباشر** لنفس المسار الذي ستستدعيه
 * كل دورة استطلاع لاحقة عبر AJAX (pge_event_ops_dashboard) — لا فرق في
 * المصدر أو الحساب بين أول تحميل والتحديثات اللاحقة (نفس مبدأ event-
 * invitations.php/event-supervisors.php).
 *
 * Requirement: "Viewing the dashboard must NOT create audit entries" — لا
 * استدعاء لأي Audit::record() في هذا الملف أو في event-operations-ajax.php
 * إطلاقاً؛ فقط الإجراءات القديمة المُعاد استخدامها (تجديد QR/تصدير) تستمر
 * بتسجيل تدقيقها الخاص كما كانت (بلا تغيير هناك).
 */
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
    auth_redirect();
}

$event_id = (int) get_query_var('event_id');
$event_post = $event_id ? get_post($event_id) : null;

if (!$event_id || !$event_post || $event_post->post_type !== 'pge_event') {
    wp_safe_redirect(home_url('/dashboard/?tab=events'));
    exit;
}

$can_manage = function_exists('pge_event_guests_user_can_manage')
    ? pge_event_guests_user_can_manage($event_id)
    : (current_user_can('administrator') || ((int) $event_post->post_author === get_current_user_id()));

if (!$can_manage) {
    wp_safe_redirect(home_url('/dashboard/?tab=events'));
    exit;
}

$mgmt_nonce = wp_create_nonce('pge_event_manage_nonce');
$event_title = get_the_title($event_id);
$manage_url = home_url('/event-manage/' . $event_id . '/');
$invitations_url = home_url('/event-manage/' . $event_id . '/invitations/');
$supervisors_url = home_url('/event-manage/' . $event_id . '/supervisors/');
$ajax_url = admin_url('admin-ajax.php');

$default_recent_limit = 20;
$poll_interval_ms = 15000;

// ── قراءة أولية (SSR) — نفس استدعاء pge_event_ops_dashboard_handler()
// بالضبط، بلا AJAX لأول تحميل (تفادي وميض التحميل). ──────────────────────
$initial_summary = [];
$initial_supervisors = [];
$initial_attendance = [];
$initial_recent = [];
$initial_generated_at = '';

if (class_exists('PGE_Attendance_Dashboard_Provider')) {
    $initial_dashboard = PGE_Attendance_Dashboard_Provider::get_dashboard($event_id, $default_recent_limit);
    if (($initial_dashboard['result'] ?? '') === 'authorized') {
        $initial_data = $initial_dashboard['data'];
        $initial_summary = is_array($initial_data['event_summary'] ?? null) ? $initial_data['event_summary'] : [];
        $initial_attendance = is_array($initial_data['attendance_summary'] ?? null) ? $initial_data['attendance_summary'] : [];
        $initial_attendance['cancelled_invitations'] = function_exists('pge_event_ops_cancelled_count') ? pge_event_ops_cancelled_count($event_id) : 0;
        $initial_supervisors = is_array($initial_data['supervisor_summary'] ?? null) ? $initial_data['supervisor_summary'] : [];
        if (function_exists('pge_event_ops_merge_supervisor_last_activity')) {
            $initial_supervisors = pge_event_ops_merge_supervisor_last_activity($event_id, $initial_supervisors);
        }
        $initial_recent = is_array($initial_data['recent_checkins'] ?? null) ? $initial_data['recent_checkins'] : [];
        $initial_generated_at = (string) ($initial_data['dashboard_metadata']['generated_at'] ?? '');
    }
}

$attendance_rate_percent = round(((float) ($initial_attendance['attendance_rate'] ?? 0)) * 100, 1);
$supervisor_count = count($initial_supervisors);
$is_empty_event = ((int) ($initial_attendance['total_invitations'] ?? 0)) === 0;

$status_labels = [
    'invited' => 'بانتظار القبول',
    'pending' => 'بانتظار القبول',
    'active'  => 'نشط',
    'revoked' => 'ملغى',
];
$method_labels = [
    'qr'     => 'QR',
    'manual' => 'يدوي',
];

// بحث بسيط (Lookup) لربط اسم المشرف باسمه في صفوف "آخر عمليات تسجيل
// الحضور" — البيانتان (recent_checkins وsupervisor_summary) عائدتان أصلاً
// من نفس لقطة Dashboard Provider الواحدة، لا استعلام إضافي هنا.
$supervisor_name_by_assignment = [];
foreach ($initial_supervisors as $__sup_row) {
    $supervisor_name_by_assignment[(int) ($__sup_row['assignment_id'] ?? 0)] = (string) ($__sup_row['supervisor_name'] ?? '');
}
unset($__sup_row);

get_header();
?>
<div dir="rtl" class="max-w-6xl mx-auto px-4 py-6" id="pgeEventOpsRoot"
     data-event-id="<?php echo esc_attr((string) $event_id); ?>"
     data-nonce="<?php echo esc_attr($mgmt_nonce); ?>"
     data-ajax-url="<?php echo esc_url($ajax_url); ?>"
     data-poll-interval-ms="<?php echo (int) $poll_interval_ms; ?>"
     data-recent-limit="<?php echo (int) $default_recent_limit; ?>">

  <div id="toast" role="status" aria-live="polite"
       style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99;min-width:200px;max-width:88vw;padding:11px 20px;border-radius:99px;font-size:13px;font-weight:700;text-align:center;pointer-events:none;opacity:0;transition:opacity .25s;"></div>

  <!-- ══ رأس الصفحة ══════════════════════════════════════════════ -->
  <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <a href="<?php echo esc_url($manage_url); ?>" class="text-sm text-foreground/65 hover:underline">&larr; رجوع لإدارة المناسبة</a>
      <h1 class="mt-1 text-xl font-extrabold text-foreground">لوحة عمليات المناسبة — <?php echo esc_html($event_title); ?></h1>
      <p class="mt-1 text-xs text-foreground/60">
        <span data-role="last-updated-label" aria-live="polite">آخر تحديث: <span data-role="last-updated-time" data-generated-at="<?php echo esc_attr($initial_generated_at); ?>"><?php echo esc_html($initial_generated_at !== '' ? $initial_generated_at : 'الآن'); ?></span></span>
        <span data-role="refresh-indicator" class="hidden" aria-hidden="true">⟳</span>
      </p>
    </div>
  </header>

  <div data-role="error-banner" class="mb-4 hidden rounded-2xl bg-destructive/10 p-4 text-sm font-medium text-destructive-text" role="alert" aria-live="assertive">
    تعذّر تحديث بيانات اللوحة، سيُعاد المحاولة تلقائياً.
  </div>

  <div data-role="empty-state" class="<?php echo $is_empty_event ? '' : 'hidden'; ?> mb-6 rounded-2xl border border-dashed border-border bg-white p-8 text-center" role="status">
    <p class="text-sm font-bold text-foreground/70">لا توجد بيانات دعوات/حضور بعد لهذه المناسبة.</p>
  </div>

  <!-- ══ 1) ملخّص الحضور الحيّ (Live Attendance Summary) ═══════════════ -->
  <section aria-labelledby="ops-summary-heading" class="mb-8">
    <h2 id="ops-summary-heading" class="mb-3 text-sm font-bold uppercase tracking-wide text-foreground/50">ملخّص الحضور الحيّ</h2>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" data-role="summary-cards">
      <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="total_invitations">
        <p class="text-xs font-bold text-foreground/50">إجمالي الدعوات</p>
        <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="total_invitations"><?php echo esc_html((string) (int) ($initial_attendance['total_invitations'] ?? 0)); ?></p>
      </div>
      <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="checked_in_invitations">
        <p class="text-xs font-bold text-foreground/50">تم تسجيل حضورها</p>
        <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="checked_in_invitations"><?php echo esc_html((string) (int) ($initial_attendance['checked_in_invitations'] ?? 0)); ?></p>
      </div>
      <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="pending_invitations">
        <p class="text-xs font-bold text-foreground/50">قيد الانتظار</p>
        <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="pending_invitations"><?php echo esc_html((string) (int) ($initial_attendance['pending_invitations'] ?? 0)); ?></p>
      </div>
      <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="cancelled_invitations">
        <p class="text-xs font-bold text-foreground/50">دعوات مُلغاة</p>
        <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="cancelled_invitations"><?php echo esc_html((string) (int) ($initial_attendance['cancelled_invitations'] ?? 0)); ?></p>
      </div>
      <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="attendance_rate">
        <p class="text-xs font-bold text-foreground/50">نسبة الحضور</p>
        <p class="mt-2 text-2xl font-extrabold text-foreground"><span data-metric="attendance_rate_percent"><?php echo esc_html((string) $attendance_rate_percent); ?></span>%</p>
      </div>
      <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="expected_guests">
        <p class="text-xs font-bold text-foreground/50">الضيوف المتوقَّعون</p>
        <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="expected_guests"><?php echo esc_html((string) (int) ($initial_attendance['expected_guests'] ?? 0)); ?></p>
      </div>
      <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="actual_attendees">
        <p class="text-xs font-bold text-foreground/50">الحاضرون فعلياً</p>
        <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="actual_attendees"><?php echo esc_html((string) (int) ($initial_attendance['actual_attendees'] ?? 0)); ?></p>
      </div>
      <div class="rounded-2xl border border-border bg-white p-5 shadow-sm" data-card="supervisor_count">
        <p class="text-xs font-bold text-foreground/50">عدد المشرفين</p>
        <p class="mt-2 text-2xl font-extrabold text-foreground" data-metric="supervisor_count"><?php echo esc_html((string) (int) $supervisor_count); ?></p>
      </div>
    </div>
  </section>

  <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <!-- ══ 3) آخر عمليات تسجيل الحضور (Recent Check-ins) ══════════════ -->
    <section aria-labelledby="ops-recent-heading" class="lg:col-span-2">
      <h2 id="ops-recent-heading" class="mb-3 text-sm font-bold uppercase tracking-wide text-foreground/50">آخر عمليات تسجيل الحضور</h2>
      <div class="overflow-hidden rounded-2xl border border-border bg-white">
        <ul class="divide-y divide-border" data-role="recent-checkins-list">
          <?php if (empty($initial_recent)): ?>
          <li class="p-5 text-center text-sm text-foreground/60" data-role="recent-checkins-empty">لا توجد عمليات تسجيل حضور بعد.</li>
          <?php else: foreach ($initial_recent as $__checkin): ?>
          <li class="flex items-center justify-between gap-3 p-4" data-row="recent-checkin">
            <div>
              <p class="text-sm font-bold text-foreground" data-field="guest_name"><?php echo esc_html((string) ($__checkin['guest_name'] !== '' ? $__checkin['guest_name'] : 'ضيف')); ?></p>
              <p class="mt-0.5 text-xs text-foreground/60" data-field="checked_in_at"><?php echo esc_html((string) ($__checkin['checked_in_at'] ?? '')); ?></p>
              <p class="mt-0.5 text-xs text-foreground/60" data-field="supervisor_name">المشرف: <?php echo esc_html($supervisor_name_by_assignment[(int) ($__checkin['assignment_id'] ?? 0)] ?? '—'); ?></p>
            </div>
            <div class="flex items-center gap-3 text-xs">
              <span class="rounded-full bg-secondary/40 px-2 py-1 font-bold text-foreground/70" data-field="method">
                <?php echo esc_html($method_labels[$__checkin['method'] ?? ''] ?? (string) ($__checkin['method'] ?? '')); ?>
              </span>
              <span class="rounded-full bg-emerald-100 px-2 py-1 font-bold text-emerald-800">حضر</span>
            </div>
          </li>
          <?php endforeach; unset($__checkin); endif; ?>
        </ul>
      </div>
    </section>

    <div class="flex flex-col gap-6">
      <!-- ══ 2) حالة المشرفين الحاليّة (Current Supervisor Status) ═══════ -->
      <section aria-labelledby="ops-supervisors-heading">
        <h2 id="ops-supervisors-heading" class="mb-3 text-sm font-bold uppercase tracking-wide text-foreground/50">حالة المشرفين</h2>
        <div class="overflow-hidden rounded-2xl border border-border bg-white">
          <ul class="divide-y divide-border" data-role="supervisor-status-list">
            <?php if (empty($initial_supervisors)): ?>
            <li class="p-5 text-center text-sm text-foreground/60" data-role="supervisor-status-empty">لا يوجد مشرفون بعد لهذه المناسبة.</li>
            <?php else: foreach ($initial_supervisors as $__sup): ?>
            <li class="p-4" data-row="supervisor-status" data-assignment-id="<?php echo esc_attr((string) ($__sup['assignment_id'] ?? 0)); ?>">
              <p class="text-sm font-bold text-foreground" data-field="supervisor_name"><?php echo esc_html((string) ($__sup['supervisor_name'] ?? '')); ?></p>
              <p class="mt-1 text-xs font-bold" data-field="status">
                <?php $__status = (string) ($__sup['status'] ?? ''); echo esc_html($status_labels[$__status] ?? ($__status !== '' ? $__status : '—')); ?>
              </p>
              <p class="mt-1 text-xs text-foreground/60" data-field="last_activity">آخر نشاط: <?php echo esc_html(($__sup['last_activity'] ?? '') !== '' ? $__sup['last_activity'] : 'لا يوجد نشاط بعد'); ?></p>
              <p class="mt-1 text-xs text-foreground/60" data-field="checkins_recorded">عمليات تسجيل: <?php echo esc_html((string) (int) ($__sup['checkins_recorded'] ?? 0)); ?></p>
            </li>
            <?php endforeach; unset($__sup, $__status); endif; ?>
          </ul>
        </div>
      </section>

      <!-- ══ 5) اختصارات تشغيلية (Operational Shortcuts) ═════════════════ -->
      <section aria-labelledby="ops-shortcuts-heading">
        <h2 id="ops-shortcuts-heading" class="mb-3 text-sm font-bold uppercase tracking-wide text-foreground/50">اختصارات تشغيلية</h2>
        <div class="flex flex-col gap-2" data-role="operational-shortcuts">
          <a href="<?php echo esc_url($invitations_url); ?>"
             class="flex items-center justify-between rounded-xl border border-border bg-white px-4 py-3 text-sm font-bold text-foreground hover:bg-secondary/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            فتح إدارة الدعوات
          </a>
          <a href="<?php echo esc_url($supervisors_url); ?>"
             class="flex items-center justify-between rounded-xl border border-border bg-white px-4 py-3 text-sm font-bold text-foreground hover:bg-secondary/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            فتح إدارة المشرفين
          </a>
          <button type="button" id="opsExportCsvBtn"
                  class="flex items-center justify-between rounded-xl border border-border bg-white px-4 py-3 text-sm font-bold text-foreground hover:bg-secondary/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            تصدير كل الدعوات (CSV)
          </button>
          <button type="button" id="opsExportExcelBtn"
                  class="flex items-center justify-between rounded-xl border border-border bg-white px-4 py-3 text-sm font-bold text-foreground hover:bg-secondary/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            تصدير كل الدعوات (Excel)
          </button>
        </div>
      </section>
    </div>
  </div>

  <!-- ══ 4) بحث سريع موحَّد (Invitation Quick Search) ═══════════════════ -->
  <section aria-labelledby="ops-search-heading" class="mt-8">
    <h2 id="ops-search-heading" class="mb-3 text-sm font-bold uppercase tracking-wide text-foreground/50">بحث سريع (اسم/جوال/رمز دعوة/مشرف)</h2>
    <label for="opsSearchInput" class="sr-only">ابحث عن دعوة أو مشرف</label>
    <input id="opsSearchInput" type="search" placeholder="ابحث عن دعوة أو مشرف..."
           class="h-11 w-full max-w-md rounded-xl border border-border px-4 text-sm outline-none focus:border-primary focus-visible:ring-2 focus-visible:ring-primary" />

    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
      <div>
        <h3 class="mb-2 text-xs font-bold text-foreground/60">نتائج الدعوات</h3>
        <ul class="divide-y divide-border rounded-2xl border border-border bg-white" data-role="search-invitations-list" aria-live="polite">
          <li class="p-4 text-center text-xs text-foreground/50" data-role="search-invitations-empty">اكتب للبحث ضمن الدعوات.</li>
        </ul>
      </div>
      <div>
        <h3 class="mb-2 text-xs font-bold text-foreground/60">نتائج المشرفين</h3>
        <ul class="divide-y divide-border rounded-2xl border border-border bg-white" data-role="search-supervisors-list" aria-live="polite">
          <li class="p-4 text-center text-xs text-foreground/50" data-role="search-supervisors-empty">اكتب للبحث ضمن المشرفين.</li>
        </ul>
      </div>
    </div>
  </section>

</div>
<?php get_footer(); ?>
<script>
(function () {
  'use strict';

  var root = document.getElementById('pgeEventOpsRoot');
  if (!root) return;

  var CONFIG = {
    ajaxUrl: root.getAttribute('data-ajax-url'),
    nonce: root.getAttribute('data-nonce'),
    eventId: root.getAttribute('data-event-id'),
    pollIntervalMs: parseInt(root.getAttribute('data-poll-interval-ms'), 10) || 15000,
    recentLimit: parseInt(root.getAttribute('data-recent-limit'), 10) || 20,
    invitationsUrl: <?php echo wp_json_encode($invitations_url); ?>,
    supervisorsUrl: <?php echo wp_json_encode($supervisors_url); ?>,
    statusLabels: <?php echo wp_json_encode($status_labels); ?>,
    methodLabels: <?php echo wp_json_encode($method_labels); ?>,
    rsvpStatusLabels: { 'yes': 'سيحضر', 'no': 'اعتذر', 'pending': 'لم يرد' }
  };

  function byRole(role) { return document.querySelector('[data-role="' + role + '"]'); }
  function byMetric(name) { return document.querySelector('[data-metric="' + name + '"]'); }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function showErrorBanner(show) {
    var el = byRole('error-banner');
    if (el) el.classList.toggle('hidden', !show);
  }
  function setRefreshIndicator(active) {
    var el = byRole('refresh-indicator');
    if (el) el.classList.toggle('hidden', !active);
  }

  function postAjax(action, extraParams) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', CONFIG.nonce);
    body.set('event_id', CONFIG.eventId);
    for (var key in extraParams) {
      if (Object.prototype.hasOwnProperty.call(extraParams, key)) {
        body.set(key, extraParams[key]);
      }
    }
    return fetch(CONFIG.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(function (res) { return res.json(); });
  }

  // ────────────────────────────────────────────────────────────────────────
  // 1) لقطة اللوحة (إحصاء + مشرفون + آخر حضور) — نفس مبدأ "اللقطة الذرية"
  // الحالي في supervisor-dashboard.php: تحقّق كامل أولاً، ثم بناء بالذاكرة،
  // ثم التزام واحد يستبدل كل الأقسام معاً.
  // ────────────────────────────────────────────────────────────────────────
  var isRefreshingDashboard = false;
  var dashboardPollTimer = null;

  function validateDashboardPayload(data) {
    if (!data || typeof data !== 'object') throw new Error('payload_not_object');
    var summary = data.attendance_summary;
    if (!summary || typeof summary !== 'object') throw new Error('missing_attendance_summary');
    if (!Array.isArray(data.recent_checkins)) throw new Error('missing_recent_checkins');
    if (!Array.isArray(data.supervisor_summary)) throw new Error('missing_supervisor_summary');
  }

  function buildSummaryUpdates(summary) {
    var keys = ['total_invitations', 'checked_in_invitations', 'pending_invitations', 'cancelled_invitations', 'expected_guests', 'actual_attendees'];
    var updates = [];
    keys.forEach(function (key) {
      var el = byMetric(key);
      if (el) updates.push({ el: el, text: String(summary[key] != null ? summary[key] : 0) });
    });
    var rateEl = byMetric('attendance_rate_percent');
    if (rateEl) updates.push({ el: rateEl, text: String(Math.round((summary.attendance_rate || 0) * 1000) / 10) });
    return updates;
  }

  function buildRecentCheckinsHtml(list, supervisorsById) {
    if (!list.length) {
      return '<li class="p-5 text-center text-sm text-foreground/60" data-role="recent-checkins-empty">لا توجد عمليات تسجيل حضور بعد.</li>';
    }
    return list.map(function (row) {
      var methodLabel = CONFIG.methodLabels[row.method] !== undefined ? CONFIG.methodLabels[row.method] : (row.method || '');
      var guestName = row.guest_name ? row.guest_name : 'ضيف';
      var supName = supervisorsById[Number(row.assignment_id)] || '—';
      return '' +
        '<li class="flex items-center justify-between gap-3 p-4" data-row="recent-checkin">' +
          '<div>' +
            '<p class="text-sm font-bold text-foreground" data-field="guest_name">' + escapeHtml(guestName) + '</p>' +
            '<p class="mt-0.5 text-xs text-foreground/60" data-field="checked_in_at">' + escapeHtml(row.checked_in_at || '') + '</p>' +
            '<p class="mt-0.5 text-xs text-foreground/60" data-field="supervisor_name">المشرف: ' + escapeHtml(supName) + '</p>' +
          '</div>' +
          '<div class="flex items-center gap-3 text-xs">' +
            '<span class="rounded-full bg-secondary/40 px-2 py-1 font-bold text-foreground/70" data-field="method">' + escapeHtml(methodLabel) + '</span>' +
            '<span class="rounded-full bg-emerald-100 px-2 py-1 font-bold text-emerald-800">حضر</span>' +
          '</div>' +
        '</li>';
    }).join('');
  }

  function buildSupervisorStatusHtml(list) {
    if (!list.length) {
      return '<li class="p-5 text-center text-sm text-foreground/60" data-role="supervisor-status-empty">لا يوجد مشرفون بعد لهذه المناسبة.</li>';
    }
    return list.map(function (row) {
      var statusRaw = row.status || '';
      var statusLabel = CONFIG.statusLabels[statusRaw] !== undefined ? CONFIG.statusLabels[statusRaw] : (statusRaw || '—');
      return '' +
        '<li class="p-4" data-row="supervisor-status" data-assignment-id="' + escapeHtml(row.assignment_id != null ? row.assignment_id : '') + '">' +
          '<p class="text-sm font-bold text-foreground" data-field="supervisor_name">' + escapeHtml(row.supervisor_name || '') + '</p>' +
          '<p class="mt-1 text-xs font-bold" data-field="status">' + escapeHtml(statusLabel) + '</p>' +
          '<p class="mt-1 text-xs text-foreground/60" data-field="last_activity">آخر نشاط: ' + escapeHtml(row.last_activity ? row.last_activity : 'لا يوجد نشاط بعد') + '</p>' +
          '<p class="mt-1 text-xs text-foreground/60" data-field="checkins_recorded">عمليات تسجيل: ' + escapeHtml(row.checkins_recorded != null ? row.checkins_recorded : 0) + '</p>' +
        '</li>';
    }).join('');
  }

  function applyDashboardSnapshot(data) {
    validateDashboardPayload(data);

    var summary = data.attendance_summary;
    var recent = data.recent_checkins;
    var supervisors = data.supervisor_summary;
    var meta = data.dashboard_metadata || {};

    var supervisorsById = {};
    supervisors.forEach(function (s) { supervisorsById[Number(s.assignment_id)] = s.supervisor_name || ''; });

    var summaryUpdates = buildSummaryUpdates(summary);
    var recentHtml = buildRecentCheckinsHtml(recent, supervisorsById);
    var supervisorHtml = buildSupervisorStatusHtml(supervisors);
    var supervisorCountText = String(supervisors.length);

    var recentListEl = byRole('recent-checkins-list');
    var supervisorListEl = byRole('supervisor-status-list');
    var lastUpdatedEl = byRole('last-updated-time');
    if (!recentListEl || !supervisorListEl || !lastUpdatedEl) throw new Error('ops_dom_targets_missing');

    // ── الالتزام — كل الأقسام تُستبدَل معاً (نفس اللقطة/generated_at) ──────
    summaryUpdates.forEach(function (u) { u.el.textContent = u.text; });
    var countEl = byMetric('supervisor_count');
    if (countEl) countEl.textContent = supervisorCountText;
    var emptyEl = byRole('empty-state');
    if (emptyEl) emptyEl.classList.toggle('hidden', (summary.total_invitations || 0) !== 0);
    recentListEl.innerHTML = recentHtml;
    supervisorListEl.innerHTML = supervisorHtml;
    lastUpdatedEl.textContent = String(meta.generated_at || '');
    lastUpdatedEl.setAttribute('data-generated-at', String(meta.generated_at || ''));
  }

  function fetchDashboard() {
    if (isRefreshingDashboard) return; // تخطّي الدورة إن كانت دورة سابقة لا تزال قيد التنفيذ
    isRefreshingDashboard = true;
    setRefreshIndicator(true);

    postAjax('pge_event_ops_dashboard', { recent_limit: CONFIG.recentLimit })
      .then(function (json) {
        if (json && json.success) {
          applyDashboardSnapshot(json.data);
          showErrorBanner(false);
        } else {
          showErrorBanner(true);
        }
      })
      .catch(function () { showErrorBanner(true); })
      .finally(function () {
        isRefreshingDashboard = false;
        setRefreshIndicator(false);
      });
  }

  function startDashboardPolling() {
    if (dashboardPollTimer) clearInterval(dashboardPollTimer);
    // Requirement: "lightweight polling every 15 seconds by default" — لا
    // WebSockets، لا SSE، لا إعادة تحميل كاملة للصفحة، فقط fetch دوري خفيف.
    dashboardPollTimer = setInterval(fetchDashboard, CONFIG.pollIntervalMs);
  }

  // لا طلب فوري عند التحميل — الصفحة مُصيَّرة على الخادم (SSR) ببيانات حديثة
  // فعلاً؛ أول تحديث AJAX يبدأ بعد دورة الاستطلاع الأولى فقط (15 ثانية).
  startDashboardPolling();

  // ────────────────────────────────────────────────────────────────────────
  // 2) البحث السريع الموحَّد — بحث مع تأخير (Debounce)، يُلغي استجابات البحث
  // المتأخرة (Cancel Stale Searches)، لا يُنشِّط استطلاع اللوحة (Requirement:
  // "Only Statistics/Recent activity/Supervisor availability refreshed").
  // ────────────────────────────────────────────────────────────────────────
  var searchRequestSeq = 0;

  function renderSearchInvitations(items) {
    var listEl = byRole('search-invitations-list');
    if (!listEl) return;
    if (!items.length) {
      listEl.innerHTML = '<li class="p-4 text-center text-xs text-foreground/50" data-role="search-invitations-empty">لا نتائج.</li>';
      return;
    }
    listEl.innerHTML = items.map(function (row) {
      var rsvpLabel = CONFIG.rsvpStatusLabels[row.rsvp_status] || row.rsvp_status || '—';
      return '' +
        '<li class="p-3 text-xs" data-row="search-invitation">' +
          '<p class="font-bold text-foreground">' + escapeHtml(row.name || 'بدون اسم') + ' <span class="font-normal text-foreground/60" dir="ltr">' + escapeHtml(row.phone || '') + '</span></p>' +
          '<p class="mt-0.5 text-foreground/60">الحالة: ' + escapeHtml(rsvpLabel) + ' — الحضور: ' + escapeHtml(row.attendance_status || '—') + '</p>' +
          '<div class="mt-1.5 flex flex-wrap gap-1.5">' +
            '<a class="rounded-lg border border-border px-2 py-1 font-semibold text-foreground/70 hover:bg-secondary/30" href="' + escapeHtml(CONFIG.invitationsUrl) + '">فتح الدعوة</a>' +
            '<button type="button" class="ops-qr-regen-btn rounded-lg border border-border px-2 py-1 font-semibold text-foreground/70 hover:bg-secondary/30" data-phone="' + escapeHtml(row.phone || '') + '">تجديد QR</button>' +
          '</div>' +
        '</li>';
    }).join('');

    Array.prototype.forEach.call(listEl.querySelectorAll('.ops-qr-regen-btn'), function (btn) {
      btn.addEventListener('click', function () { handleQrRegenerate(btn.getAttribute('data-phone'), btn); });
    });
  }

  function renderSearchSupervisors(items) {
    var listEl = byRole('search-supervisors-list');
    if (!listEl) return;
    if (!items.length) {
      listEl.innerHTML = '<li class="p-4 text-center text-xs text-foreground/50" data-role="search-supervisors-empty">لا نتائج.</li>';
      return;
    }
    listEl.innerHTML = items.map(function (row) {
      return '' +
        '<li class="p-3 text-xs" data-row="search-supervisor">' +
          '<p class="font-bold text-foreground">' + escapeHtml(row.name || 'بدون اسم') + ' <span class="font-normal text-foreground/60" dir="ltr">' + escapeHtml(row.phone || '') + '</span></p>' +
          '<p class="mt-0.5 text-foreground/60">الحالة: ' + escapeHtml(row.status_label || row.status || '—') + '</p>' +
          '<div class="mt-1.5">' +
            '<a class="rounded-lg border border-border px-2 py-1 font-semibold text-foreground/70 hover:bg-secondary/30" href="' + escapeHtml(CONFIG.supervisorsUrl) + '">فتح المشرف</a>' +
          '</div>' +
        '</li>';
    }).join('');
  }

  function handleQrRegenerate(phone, btn) {
    if (!phone || btn.disabled) return;
    btn.disabled = true;
    postAjax('pge_invitation_mgmt_qr_regenerate', { phone: phone }).then(function (json) {
      btn.disabled = false;
      if (json && json.success) {
        showToast(json.data && json.data.message ? json.data.message : 'تم تجديد رمز الدعوة', false);
      } else {
        showToast((json && json.data && json.data.message) || 'تعذّر تجديد رمز الدعوة', true);
      }
    }).catch(function () {
      btn.disabled = false;
      showToast('تعذّر الاتصال بالخادم', true);
    });
  }

  var toastEl = document.getElementById('toast');
  function showToast(message, isError) {
    if (!toastEl) return;
    toastEl.textContent = message;
    toastEl.style.background = isError ? '#fee2e2' : '#dcfce7';
    toastEl.style.color = isError ? '#991b1b' : '#166534';
    toastEl.style.opacity = '1';
    window.clearTimeout(showToast._t);
    showToast._t = window.setTimeout(function () { toastEl.style.opacity = '0'; }, 2600);
  }

  function runSearch(q) {
    var mySeq = ++searchRequestSeq;
    postAjax('pge_event_ops_search', { q: q }).then(function (json) {
      if (mySeq !== searchRequestSeq) return; // استجابة بحث متأخرة — تُهمَل
      if (!json || !json.success) return;
      renderSearchInvitations(json.data.invitations || []);
      renderSearchSupervisors(json.data.supervisors || []);
    }).catch(function () {
      if (mySeq !== searchRequestSeq) return;
    });
  }

  var searchInput = document.getElementById('opsSearchInput');
  var searchTimer = null;
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      window.clearTimeout(searchTimer);
      var value = searchInput.value;
      searchTimer = window.setTimeout(function () { runSearch(value); }, 300);
    });
  }

  // ────────────────────────────────────────────────────────────────────────
  // 3) تصدير كل الدعوات — نفس نمط event-invitations.php حرفياً (نموذج مخفٍ
  // يُقدَّم POST مباشرة لنقطة الـAJAX الموجودة أصلاً، لا fetch/blob).
  // ────────────────────────────────────────────────────────────────────────
  function triggerExport(action) {
    var form = document.createElement('form');
    form.method = 'post';
    form.action = CONFIG.ajaxUrl;
    form.style.display = 'none';

    function addField(name, value) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    }
    addField('action', action);
    addField('nonce', CONFIG.nonce);
    addField('event_id', CONFIG.eventId);

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
  }

  var exportCsvBtn = document.getElementById('opsExportCsvBtn');
  if (exportCsvBtn) exportCsvBtn.addEventListener('click', function () { triggerExport('pge_invitation_mgmt_export_csv'); });
  var exportExcelBtn = document.getElementById('opsExportExcelBtn');
  if (exportExcelBtn) exportExcelBtn.addEventListener('click', function () { triggerExport('pge_invitation_mgmt_export_excel'); });
})();
</script>
<?php
