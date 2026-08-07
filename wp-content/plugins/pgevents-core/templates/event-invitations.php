<?php
/**
 * ============================================================================
 * Host Invitation Management — Entry Check-in Supervisors، Phase 9
 * ============================================================================
 * "Host Invitation Management" RFC — "Templates are rendering only." هذا
 * الملف عرض فقط: يقرأ صفحة أولى (SSR) عبر PGE_Invitation_Service::
 * list_invitations() (قراءة فقط)، ثم يُسلِّم كل عملية كتابة لنقاط AJAX في
 * includes/invitation-management-ajax.php حصراً — لا SQL/post-meta هنا، لا
 * قرار تفويض إضافي (نفس pge_event_guests_user_can_manage() المُستخدَمة في
 * page-event-manage.php/templates/event-supervisors.php حرفياً).
 *
 * قالب مضيف عادي (نفس فلسفة templates/event-supervisors.php، Phase 8):
 * get_header()/get_footer() من الثيم — المضيف يملك حساب ووردبريس فعلياً.
 *
 * ============================================================================
 * Phase 9B ("Invitation Lifecycle — Resend + QR Regeneration")
 * ============================================================================
 * أُعيد هنا عنصرا واجهة اثنان فقط: "إعادة إرسال" و"تجديد رمز QR" — نقطتا
 * الـAJAX المقابلتان لهما مُسجَّلتان الآن فعلياً (راجع PGE_INVITATION_MGMT_
 * RESEND_QR_ENABLED في invitation-management-ajax.php، Phase 9B مُعتمَدة).
 * زران يظهران فقط للدعوات غير المُلغاة (نفس شرط زر "إلغاء" أصلاً) — دعوة
 * مُلغاة لا معنى لإعادة إرسالها أو تجديد رمزها (Repository يرفض الاثنين
 * أصلاً لدعوة مُلغاة).
 *
 * ============================================================================
 * Phase 9C ("Invitation Export") — مُعتمَدة
 * ============================================================================
 * زرا "تصدير CSV"/"تصدير Excel" في رأس الصفحة (لا تعديل/إعادة تصميم لبقية
 * الواجهة، لا نافذة منبثقة جديدة — نفس تنسيق أزرار الفلترة الحالية بالضبط).
 * كل تصدير يُرسَل عبر نموذج <form> مخفٍ يُبنى ديناميكياً ويُقدَّم POST مباشرة
 * لنقطة الـAJAX (لا fetch/blob — يسمح للمتصفح بمعالجة ترويسة Content-
 * Disposition: attachment كتنزيل ملف حقيقي فوراً)، حاملاً **فلاتر/ترتيب
 * الصفحة الحاليَّين بالضبط** (state.search/rsvp_status/invitation_status/
 * attendance_status/sort_by/sort_dir) — "Export respects the currently
 * applied filters... preserve the same ordering."
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
$ajax_url = admin_url('admin-ajax.php');

$rsvp_status_labels = ['yes' => 'سيحضر', 'no' => 'اعتذر', 'pending' => 'لم يرد'];
$invitation_status_labels = ['active' => 'نشطة', 'cancelled' => 'مُلغاة'];
$attendance_status_labels = ['yes' => 'حضر', 'no' => 'لم يحضر بعد'];
$qr_status_labels = ['issued' => 'صادر', 'not_issued' => 'بدون رمز'];

// ── قراءة أولية (SSR) للصفحة الأولى — نفس ما ستُعيده نقطة AJAX لاحقاً بالضبط. ──
$initial_page = (class_exists('PGE_Invitation_Service'))
    ? PGE_Invitation_Service::list_invitations($event_id, ['page' => 1, 'per_page' => 20])
    : ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 0];

get_header();
?>
<div dir="rtl" class="max-w-6xl mx-auto px-4 py-6" id="pgeInvMgmtRoot"
     data-event-id="<?php echo esc_attr((string) $event_id); ?>"
     data-nonce="<?php echo esc_attr($mgmt_nonce); ?>"
     data-ajax-url="<?php echo esc_url($ajax_url); ?>">

  <div id="toast" role="status" aria-live="polite"
       style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99;min-width:200px;max-width:88vw;padding:11px 20px;border-radius:99px;font-size:13px;font-weight:700;text-align:center;pointer-events:none;opacity:0;transition:opacity .25s;"></div>

  <!-- ══ رأس الصفحة ══════════════════════════════════════════════ -->
  <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div>
      <a href="<?php echo esc_url($manage_url); ?>" class="text-sm text-foreground/65 hover:underline">&larr; رجوع لإدارة المناسبة</a>
      <h1 class="mt-1 text-xl font-extrabold text-foreground">إدارة الدعوات — <?php echo esc_html($event_title); ?></h1>
    </div>
    <!-- Phase 9C: زرا تصدير الدعوات — يحترمان الفلاتر/الترتيب الحاليَّين (state)، انظر triggerInvitationExport() أدناه. نفس تنسيق زر "إغلاق" الحالي (h-11 border-border) — لا تصميم جديد. -->
    <!-- RC1 Fix Pack 3A ("Invitation Bulk Add Migration"): زر فتح نافذة الإضافة الجماعية — نفس تنسيق أزرار التصدير المجاورة بالضبط، لا تصميم جديد. -->
    <div class="flex flex-wrap gap-2">
      <!-- RC1 Fix Pack 3B ("Legacy Guest Panel Retirement — Hard Delete Migration"): زر حذف الدعوات المحدَّدة — نفس تنسيق أزرار cancel-inv-btn (bg-destructive/10)، مُعطَّل حتى يُحدَّد صف واحد على الأقل (نفس فلسفة bulkDeleteBtn/refreshBulkDeleteState القديمة في page-event-manage.php). -->
      <button type="button" id="bulkDeleteInvBtn" disabled class="h-11 px-4 rounded-xl bg-destructive/10 text-destructive-text text-sm font-semibold disabled:opacity-40">حذف المحدَّد</button>
      <button type="button" id="openBulkAddBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">إضافة جماعية</button>
      <!-- استيراد المدعوين من Excel — Phase 1 فقط (docs/EXCEL-GUEST-IMPORT-SPEC.md): زر تنزيل النموذج الرسمي حصراً، بلا Modal رفع/معاينة/استيراد (Phases لاحقة غير مُعتمَدة بعد). -->
      <button type="button" id="downloadExcelTemplateBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">📥 تحميل نموذج Excel</button>
      <button type="button" id="exportCsvBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">تصدير CSV</button>
      <button type="button" id="exportExcelBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">تصدير Excel</button>
    </div>
  </div>

  <!-- ══ نموذج إنشاء دعوة جديدة ══════════════════════════════════ -->
  <section class="rounded-2xl border border-border bg-white p-4 mb-5" aria-labelledby="createInvHeading">
    <h2 id="createInvHeading" class="text-sm font-extrabold text-foreground mb-3">إضافة دعوة جديدة</h2>
    <form id="createInvForm" class="flex flex-wrap gap-2.5 items-start">
      <div class="flex-1 min-w-[160px]">
        <label for="newInvName" class="sr-only">اسم الضيف</label>
        <input id="newInvName" type="text" required placeholder="اسم الضيف"
               class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
      </div>
      <div class="flex-1 min-w-[160px]">
        <label for="newInvPhone" class="sr-only">رقم الجوال</label>
        <input id="newInvPhone" type="tel" inputmode="tel" required placeholder="رقم الجوال"
               class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
      </div>
      <div class="flex-1 min-w-[160px]">
        <label for="newInvNote" class="sr-only">ملاحظة (اختياري)</label>
        <input id="newInvNote" type="text" placeholder="ملاحظة (اختياري)"
               class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
      </div>
      <button id="createInvSubmit" type="submit" class="h-11 rounded-xl bg-primary px-5 text-sm font-bold text-white">إضافة</button>
    </form>
    <div id="createInvMsg" class="hidden mt-2.5 text-xs font-semibold rounded-xl px-3 py-2" role="alert"></div>
  </section>

  <!-- ══ بحث + فلاتر ═════════════════════════════════════════════ -->
  <div class="flex flex-wrap gap-2.5 mb-3">
    <div class="flex-1 min-w-[200px]">
      <label for="invSearchInput" class="sr-only">ابحث بالاسم أو الجوال أو رمز الدعوة</label>
      <input id="invSearchInput" type="search" placeholder="ابحث بالاسم أو الجوال أو رمز الدعوة..."
             class="h-11 w-full rounded-xl border border-border px-4 text-sm outline-none focus:border-primary" />
    </div>
    <select id="filterRsvpStatus" class="h-11 rounded-xl border border-border px-3 text-sm">
      <option value="all">كل حالات الرد</option>
      <option value="yes">سيحضر</option>
      <option value="no">اعتذر</option>
      <option value="pending">لم يرد</option>
    </select>
    <select id="filterInvitationStatus" class="h-11 rounded-xl border border-border px-3 text-sm">
      <option value="all">كل حالات الدعوة</option>
      <option value="active">نشطة</option>
      <option value="cancelled">مُلغاة</option>
    </select>
    <select id="filterAttendanceStatus" class="h-11 rounded-xl border border-border px-3 text-sm">
      <option value="all">كل حالات الحضور</option>
      <option value="yes">حضر</option>
      <option value="no">لم يحضر بعد</option>
    </select>
  </div>

  <!-- ══ القائمة ══════════════════════════════════════════════════ -->
  <div class="rounded-2xl border border-border bg-white overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm" id="invTable">
        <caption class="sr-only">قائمة دعوات الضيوف لهذه المناسبة</caption>
        <thead>
          <tr class="border-b border-border bg-secondary/30 text-right text-xs font-bold text-foreground/70">
            <th scope="col" class="px-4 py-3 w-10"><label class="sr-only" for="invSelectAll">تحديد الكل</label><input id="invSelectAll" type="checkbox" class="h-4 w-4 rounded border-border accent-primary" /></th>
            <th scope="col" class="px-4 py-3"><button type="button" class="sort-btn" data-sort="name">الاسم</button></th>
            <th scope="col" class="px-4 py-3"><button type="button" class="sort-btn" data-sort="phone">الجوال</button></th>
            <th scope="col" class="px-4 py-3">حالة الدعوة</th>
            <th scope="col" class="px-4 py-3">حالة الرد</th>
            <th scope="col" class="px-4 py-3">حالة الحضور</th>
            <th scope="col" class="px-4 py-3">رمز الدعوة (QR)</th>
            <th scope="col" class="px-4 py-3"><button type="button" class="sort-btn" data-sort="invited_at">تاريخ الدعوة</button></th>
            <th scope="col" class="px-4 py-3"><button type="button" class="sort-btn" data-sort="updated_at">آخر تحديث</button></th>
            <th scope="col" class="px-4 py-3">إجراءات</th>
          </tr>
        </thead>
        <tbody id="invTableBody"></tbody>
      </table>
    </div>
    <div id="invLoadingState" class="hidden px-4 py-10 text-center text-sm text-foreground/60">جارٍ التحميل...</div>
    <div id="invEmptyState" class="hidden px-4 py-10 text-center text-sm text-foreground/60">لا توجد دعوات مطابقة.</div>
  </div>

  <div id="invPagination" class="flex items-center justify-center gap-2 mt-4" role="navigation" aria-label="ترقيم صفحات الدعوات"></div>

  <!-- ══ نموذج التعديل ═══════════════════════════════════════════ -->
  <div id="editInvCard" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="editInvHeading">
    <div class="w-full max-w-sm rounded-2xl bg-white p-5">
      <h2 id="editInvHeading" class="text-sm font-extrabold text-foreground mb-3">تعديل الدعوة</h2>
      <form id="editInvForm" class="space-y-2.5">
        <input type="hidden" id="editInvOldPhone" />
        <label for="editInvName" class="sr-only">الاسم</label>
        <input id="editInvName" type="text" placeholder="الاسم" class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
        <label for="editInvPhone" class="sr-only">رقم الجوال</label>
        <input id="editInvPhone" type="tel" inputmode="tel" required placeholder="رقم الجوال" class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
        <label for="editInvNote" class="sr-only">ملاحظة</label>
        <input id="editInvNote" type="text" placeholder="ملاحظة" class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
        <div id="editInvMsg" class="hidden text-xs font-semibold rounded-xl px-3 py-2" role="alert"></div>
        <div class="flex gap-2">
          <button type="submit" class="flex-1 h-11 rounded-xl bg-primary text-sm font-bold text-white">حفظ</button>
          <button type="button" id="cancelEditInvBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">إغلاق</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══ RC1 Fix Pack 3B ("Legacy Guest Panel Retirement — Hard Delete Migration") —
       نافذة تأكيد الحذف. نفس نمط نافذة "تعديل الدعوة" أعلاه حرفياً (dialog/
       fixed/bg-black40/rounded-2xl) — لا تصميم جديد. تُستخدَم لكلٍّ من الحذف
       المفرد والجماعي (deleteInvPhones يحمل قائمة الهاتف/الهواتف الحالية).
       "No browser confirm(). Use the existing modal design language." —
       تأكيد صريح داخل الصفحة نفسها يشرح بوضوح: الحذف نهائي، قد يتأثر سجلّ
       الحضور، لا يمكن التراجع. ══ -->
  <div id="deleteInvModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="deleteInvHeading">
    <div class="w-full max-w-sm rounded-2xl bg-white p-5">
      <h2 id="deleteInvHeading" class="text-sm font-extrabold text-foreground mb-3">تأكيد الحذف</h2>
      <div id="deleteInvBody" class="text-sm text-foreground/70 space-y-1.5 mb-4"></div>
      <div id="deleteInvMsg" class="hidden text-xs font-semibold rounded-xl px-3 py-2 mb-3" role="alert"></div>
      <div class="flex gap-2">
        <button type="button" id="deleteInvConfirmBtn" class="flex-1 h-11 rounded-xl bg-destructive/10 text-destructive-text ring-1 ring-destructive/30 text-sm font-bold hover:bg-destructive/20">حذف نهائياً</button>
        <button type="button" id="deleteInvCancelBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">إلغاء</button>
      </div>
    </div>
  </div>

  <!-- ══ RC1 Fix Pack 3A ("Invitation Bulk Add Migration") — نافذة الإضافة الجماعية ══
       نفس نمط نافذة "تعديل الدعوة" أعلاه حرفياً (dialog/fixed/bg-black40/rounded-2xl)
       — لا تصميم جديد، لا إعادة تصميم للصفحة. أربع حالات ظاهرة تُبدَّل عبر hidden:
       إدخال (bulkInputState) → معاينة (bulkPreviewState) → معالجة (bulkProcessingState)
       → نتيجة (bulkResultState)، بالإضافة إلى شريط خطأ عام (bulkAddErrorMsg) يظهر
       فوق أي حالة عند رفض الخادم للطلب بأكمله (حدود الحجم/عدد الأسطر). -->
  <div id="bulkAddModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="bulkAddHeading">
    <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-5">
      <div class="flex items-center justify-between mb-3">
        <h2 id="bulkAddHeading" class="text-sm font-extrabold text-foreground">إضافة جماعية</h2>
        <button type="button" id="closeBulkAddBtn" class="h-9 w-9 rounded-lg border border-border text-sm font-bold text-foreground/70" aria-label="إغلاق">×</button>
      </div>

      <div id="bulkAddErrorMsg" class="hidden mb-3 text-xs font-semibold rounded-xl px-3 py-2 bg-destructive/10 text-destructive-text" role="alert"></div>

      <!-- حالة الإدخال -->
      <div id="bulkInputState">
        <label for="bulkAddTextarea" class="block text-xs font-semibold text-foreground/70 mb-1.5">
          الصق دعوة واحدة في كل سطر — رقم فقط، أو «الاسم,الرقم»، أو «الاسم ثم تبويب ثم الرقم»
        </label>
        <textarea id="bulkAddTextarea" rows="10" placeholder="أحمد محمد,0500000000&#10;0500000001"
                  class="w-full rounded-xl border border-border p-3 text-sm outline-none focus:border-primary font-mono" dir="ltr"></textarea>
        <p class="mt-1.5 text-[11px] text-foreground/50">حتى 500 سطر لكل دفعة. الاسم مطلوب لإنشاء الدعوة (نفس شرط نموذج «إضافة دعوة» أعلاه) — الأسطر بلا اسم ستظهر في المعاينة كـ«غير صالحة» ويمكنك تصحيحها قبل التأكيد.</p>
        <div class="flex justify-end gap-2 mt-3">
          <button type="button" id="bulkCancelBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">إلغاء</button>
          <button type="button" id="bulkPreviewBtn" class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white">معاينة</button>
        </div>
      </div>

      <!-- حالة المعاينة -->
      <div id="bulkPreviewState" class="hidden">
        <div id="bulkSummaryBox" class="flex flex-wrap gap-2 mb-3"></div>
        <div class="overflow-x-auto rounded-xl border border-border max-h-72 overflow-y-auto">
          <table class="w-full text-xs">
            <caption class="sr-only">معاينة صفوف الإضافة الجماعية قبل التأكيد</caption>
            <thead>
              <tr class="border-b border-border bg-secondary/30 text-right font-bold text-foreground/70">
                <th scope="col" class="px-2.5 py-2">#</th>
                <th scope="col" class="px-2.5 py-2">الاسم</th>
                <th scope="col" class="px-2.5 py-2">الجوال</th>
                <th scope="col" class="px-2.5 py-2">الحالة</th>
              </tr>
            </thead>
            <tbody id="bulkPreviewBody"></tbody>
          </table>
        </div>
        <div class="flex justify-end gap-2 mt-3">
          <button type="button" id="bulkBackBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">رجوع</button>
          <button type="button" id="bulkConfirmBtn" class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white">تأكيد الإنشاء</button>
        </div>
      </div>

      <!-- حالة المعالجة -->
      <div id="bulkProcessingState" class="hidden py-10 text-center text-sm text-foreground/60">جارٍ الإنشاء...</div>

      <!-- حالة النتيجة -->
      <div id="bulkResultState" class="hidden">
        <div id="bulkResultSummaryBox" class="flex flex-wrap gap-2 mb-3"></div>
        <div class="overflow-x-auto rounded-xl border border-border max-h-72 overflow-y-auto">
          <table class="w-full text-xs">
            <caption class="sr-only">نتيجة الإضافة الجماعية</caption>
            <thead>
              <tr class="border-b border-border bg-secondary/30 text-right font-bold text-foreground/70">
                <th scope="col" class="px-2.5 py-2">#</th>
                <th scope="col" class="px-2.5 py-2">الاسم</th>
                <th scope="col" class="px-2.5 py-2">الجوال</th>
                <th scope="col" class="px-2.5 py-2">النتيجة</th>
              </tr>
            </thead>
            <tbody id="bulkResultBody"></tbody>
          </table>
        </div>
        <div class="flex justify-end gap-2 mt-3">
          <button type="button" id="bulkCloseResultBtn" class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white">إغلاق</button>
        </div>
      </div>
    </div>
  </div>

</div>
<?php get_footer(); ?>
<script>
(function () {
  'use strict';

  var root = document.getElementById('pgeInvMgmtRoot');
  if (!root) return;

  var CONFIG = {
    ajaxUrl: root.getAttribute('data-ajax-url'),
    nonce: root.getAttribute('data-nonce'),
    eventId: root.getAttribute('data-event-id'),
  };

  var RSVP_LABELS = <?php echo wp_json_encode($rsvp_status_labels); ?>;
  var INV_LABELS = <?php echo wp_json_encode($invitation_status_labels); ?>;
  var ATT_LABELS = <?php echo wp_json_encode($attendance_status_labels); ?>;
  var QR_LABELS = <?php echo wp_json_encode($qr_status_labels); ?>;

  var state = { page: 1, search: '', rsvp_status: 'all', invitation_status: 'all', attendance_status: 'all', sort_by: 'name', sort_dir: 'asc', requestSeq: 0 };

  var tableBody = document.getElementById('invTableBody');
  var loadingEl = document.getElementById('invLoadingState');
  var emptyEl = document.getElementById('invEmptyState');
  var paginationEl = document.getElementById('invPagination');
  var toastEl = document.getElementById('toast');

  function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function showToast(message, isError) {
    toastEl.textContent = message;
    toastEl.style.background = isError ? '#fee2e2' : '#dcfce7';
    toastEl.style.color = isError ? '#991b1b' : '#166534';
    toastEl.style.opacity = '1';
    window.clearTimeout(showToast._t);
    showToast._t = window.setTimeout(function () { toastEl.style.opacity = '0'; }, 2600);
  }

  function postAjax(action, extraParams) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', CONFIG.nonce);
    body.set('event_id', CONFIG.eventId);
    for (var key in extraParams) {
      if (Object.prototype.hasOwnProperty.call(extraParams, key)) body.set(key, extraParams[key]);
    }
    return fetch(CONFIG.ajaxUrl, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(function (res) { return res.json(); });
  }

  // Phase 9C ("Invitation Export"): تنزيل ملف حقيقي عبر تقديم نموذج <form>
  // POST مخفٍ مباشرة لنقطة الـAJAX — لا fetch/blob (fetch لا يُشغِّل تنزيل
  // ملف من استجابة Content-Disposition: attachment تلقائياً). يحمل نفس فلاتر/
  // ترتيب state الحاليَّين بالضبط ("respects the currently applied filters...
  // preserve the same ordering") — لا فلترة/فرز مستقل هنا إطلاقاً.
  function triggerInvitationExport(action) {
    var form = document.createElement('form');
    form.method = 'post';
    form.action = CONFIG.ajaxUrl;
    form.style.display = 'none';

    var fields = {
      action: action,
      nonce: CONFIG.nonce,
      event_id: CONFIG.eventId,
      search: state.search,
      rsvp_status: state.rsvp_status,
      invitation_status: state.invitation_status,
      attendance_status: state.attendance_status,
      sort_by: state.sort_by,
      sort_dir: state.sort_dir,
    };
    for (var key in fields) {
      if (!Object.prototype.hasOwnProperty.call(fields, key)) continue;
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = fields[key] == null ? '' : fields[key];
      form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
    window.setTimeout(function () { document.body.removeChild(form); }, 1000);
  }

  // استيراد المدعوين من Excel — Phase 1 فقط: تنزيل النموذج الرسمي حصراً
  // (لا فلاتر تُرسَل، النموذج ثابت). نفس نمط نموذج POST المخفي المُستخدَم
  // في triggerInvitationExport() أعلاه — تنزيل حقيقي عبر تنقّل المتصفح، لا
  // fetch/XHR (يفتح مربع "حفظ الملف" مباشرة، بلا معالجة Blob يدوية).
  function triggerExcelTemplateDownload() {
    var form = document.createElement('form');
    form.method = 'post';
    form.action = CONFIG.ajaxUrl;
    form.style.display = 'none';

    var fields = {
      action: 'pge_invitation_mgmt_excel_template',
      nonce: CONFIG.nonce,
      event_id: CONFIG.eventId,
    };
    for (var key in fields) {
      if (!Object.prototype.hasOwnProperty.call(fields, key)) continue;
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = fields[key] == null ? '' : fields[key];
      form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
    window.setTimeout(function () { document.body.removeChild(form); }, 1000);
  }

  function renderRows(items) {
    tableBody.innerHTML = '';
    items.forEach(function (row) {
      var tr = document.createElement('tr');
      tr.className = 'border-b border-border/60 last:border-0';
      var isCancelled = row.invitation_status === 'cancelled';

      tr.innerHTML =
        '<td class="px-4 py-3"><label class="sr-only">تحديد ' + (escapeHtml(row.name) || escapeHtml(row.phone)) + '</label><input type="checkbox" class="inv-row-checkbox h-4 w-4 rounded border-border accent-primary" data-phone="' + escapeHtml(row.phone) + '" /></td>' +
        '<td class="px-4 py-3 font-semibold text-foreground">' + (escapeHtml(row.name) || '<span class="text-foreground/50 font-normal">بدون اسم</span>') + '</td>' +
        '<td class="px-4 py-3 font-mono text-foreground/80" dir="ltr">' + escapeHtml(row.phone) + '</td>' +
        '<td class="px-4 py-3">' + escapeHtml(INV_LABELS[row.invitation_status] || row.invitation_status) + '</td>' +
        '<td class="px-4 py-3">' + escapeHtml(RSVP_LABELS[row.rsvp_status] || row.rsvp_status) + '</td>' +
        '<td class="px-4 py-3">' + escapeHtml(ATT_LABELS[row.attendance_status] || row.attendance_status) + '</td>' +
        '<td class="px-4 py-3">' + escapeHtml(QR_LABELS[row.qr_status] || row.qr_status) + (row.code ? ' <span class="font-mono text-xs">(' + escapeHtml(row.code) + ')</span>' : '') + '</td>' +
        '<td class="px-4 py-3 text-xs text-foreground/65">' + escapeHtml(row.invited_at || '—') + '</td>' +
        '<td class="px-4 py-3 text-xs text-foreground/65">' + escapeHtml(row.updated_at || '—') + '</td>' +
        '<td class="px-4 py-3">' +
          '<div class="flex flex-wrap gap-1.5">' +
            '<button type="button" class="edit-inv-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">تعديل</button>' +
            (!isCancelled ? '<button type="button" class="resend-inv-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">إعادة إرسال</button>' : '') +
            (!isCancelled ? '<button type="button" class="regen-qr-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">تجديد QR</button>' : '') +
            (!isCancelled ? '<button type="button" class="cancel-inv-btn h-9 px-2.5 rounded-lg bg-destructive/10 text-destructive-text text-xs font-semibold">إلغاء</button>' : '') +
            '<button type="button" class="delete-inv-btn h-9 px-2.5 rounded-lg bg-destructive/10 text-destructive-text text-xs font-semibold">حذف</button>' +
          '</div>' +
        '</td>';

      tr.querySelector('.edit-inv-btn').addEventListener('click', function () { openEditForm(row); });
      var cancelBtn = tr.querySelector('.cancel-inv-btn');
      if (cancelBtn) cancelBtn.addEventListener('click', function () { handleCancel(row.phone, cancelBtn); });
      var resendBtn = tr.querySelector('.resend-inv-btn');
      if (resendBtn) resendBtn.addEventListener('click', function () { handleResend(row.phone, resendBtn); });
      var regenBtn = tr.querySelector('.regen-qr-btn');
      if (regenBtn) regenBtn.addEventListener('click', function () { handleRegenQr(row.phone, regenBtn); });
      var deleteBtn = tr.querySelector('.delete-inv-btn');
      if (deleteBtn) deleteBtn.addEventListener('click', function () { openDeleteModal([{ phone: row.phone, name: row.name }]); });
      var rowCheckbox = tr.querySelector('.inv-row-checkbox');
      if (rowCheckbox) rowCheckbox.addEventListener('change', refreshBulkDeleteInvState);

      tableBody.appendChild(tr);
    });
    emptyEl.classList.toggle('hidden', items.length !== 0);
    if (invSelectAll) invSelectAll.checked = false;
    refreshBulkDeleteInvState();
  }

  function renderPagination(page, totalPages) {
    paginationEl.innerHTML = '';
    if (totalPages <= 1) return;
    for (var p = 1; p <= totalPages; p++) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = String(p);
      btn.className = 'h-9 w-9 rounded-lg text-xs font-bold ' + (p === page ? 'bg-primary text-white' : 'border border-border text-foreground/70');
      (function (targetPage) { btn.addEventListener('click', function () { fetchList(targetPage); }); })(p);
      paginationEl.appendChild(btn);
    }
  }

  function fetchList(page) {
    state.page = page;
    var mySeq = ++state.requestSeq;
    loadingEl.classList.remove('hidden');
    emptyEl.classList.add('hidden');

    postAjax('pge_invitation_mgmt_list', {
      page: page, search: state.search, rsvp_status: state.rsvp_status,
      invitation_status: state.invitation_status, attendance_status: state.attendance_status,
      sort_by: state.sort_by, sort_dir: state.sort_dir,
    }).then(function (json) {
      if (mySeq !== state.requestSeq) return;
      loadingEl.classList.add('hidden');
      if (!json || !json.success) { showToast((json && json.data && json.data.message) || 'تعذّر تحميل القائمة', true); return; }
      renderRows(json.data.items || []);
      renderPagination(json.data.page || 1, json.data.total_pages || 0);
    }).catch(function () {
      if (mySeq !== state.requestSeq) return;
      loadingEl.classList.add('hidden');
      showToast('تعذّر الاتصال بالخادم', true);
    });
  }

  var searchInput = document.getElementById('invSearchInput');
  var searchTimer = null;
  searchInput.addEventListener('input', function () {
    window.clearTimeout(searchTimer);
    var value = searchInput.value;
    searchTimer = window.setTimeout(function () { state.search = value; fetchList(1); }, 300);
  });

  ['filterRsvpStatus', 'filterInvitationStatus', 'filterAttendanceStatus'].forEach(function (id) {
    document.getElementById(id).addEventListener('change', function (e) {
      if (id === 'filterRsvpStatus') state.rsvp_status = e.target.value;
      if (id === 'filterInvitationStatus') state.invitation_status = e.target.value;
      if (id === 'filterAttendanceStatus') state.attendance_status = e.target.value;
      fetchList(1);
    });
  });

  document.querySelectorAll('.sort-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var field = btn.getAttribute('data-sort');
      if (state.sort_by === field) { state.sort_dir = state.sort_dir === 'asc' ? 'desc' : 'asc'; }
      else { state.sort_by = field; state.sort_dir = 'asc'; }
      fetchList(state.page);
    });
  });

  var createForm = document.getElementById('createInvForm');
  var createSubmitBtn = document.getElementById('createInvSubmit');
  var createMsgEl = document.getElementById('createInvMsg');
  var createInFlight = false;
  createForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (createInFlight) return;
    createInFlight = true;
    createSubmitBtn.disabled = true;
    postAjax('pge_invitation_mgmt_create', {
      name: document.getElementById('newInvName').value,
      phone: document.getElementById('newInvPhone').value,
      note: document.getElementById('newInvNote').value,
    }).then(function (json) {
      createInFlight = false;
      createSubmitBtn.disabled = false;
      createMsgEl.classList.remove('hidden');
      if (json && json.success) {
        createMsgEl.textContent = json.data.message || 'تمت الإضافة';
        createMsgEl.style.background = '#dcfce7'; createMsgEl.style.color = '#166534';
        createForm.reset();
        fetchList(1);
      } else {
        createMsgEl.textContent = (json && json.data && json.data.message) || 'تعذّرت الإضافة';
        createMsgEl.style.background = '#fee2e2'; createMsgEl.style.color = '#991b1b';
      }
    }).catch(function () {
      createInFlight = false; createSubmitBtn.disabled = false;
      createMsgEl.classList.remove('hidden');
      createMsgEl.textContent = 'تعذّر الاتصال بالخادم';
    });
  });

  var editCard = document.getElementById('editInvCard');
  var editForm = document.getElementById('editInvForm');
  var editOldPhoneInput = document.getElementById('editInvOldPhone');
  var editNameInput = document.getElementById('editInvName');
  var editPhoneInput = document.getElementById('editInvPhone');
  var editNoteInput = document.getElementById('editInvNote');
  var editMsgEl = document.getElementById('editInvMsg');
  var editInFlight = false;

  function openEditForm(row) {
    editOldPhoneInput.value = row.phone;
    editNameInput.value = row.name || '';
    editPhoneInput.value = row.phone || '';
    editNoteInput.value = row.note || '';
    editMsgEl.classList.add('hidden');
    editCard.classList.remove('hidden');
  }
  document.getElementById('cancelEditInvBtn').addEventListener('click', function () { editCard.classList.add('hidden'); });

  editForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (editInFlight) return;
    editInFlight = true;
    postAjax('pge_invitation_mgmt_edit', {
      old_phone: editOldPhoneInput.value, phone: editPhoneInput.value,
      name: editNameInput.value, note: editNoteInput.value,
    }).then(function (json) {
      editInFlight = false;
      if (json && json.success) {
        editCard.classList.add('hidden');
        showToast(json.data.message || 'تم التحديث', false);
        fetchList(state.page);
      } else {
        editMsgEl.classList.remove('hidden');
        editMsgEl.textContent = (json && json.data && json.data.message) || 'تعذّر التحديث';
      }
    }).catch(function () { editInFlight = false; editMsgEl.classList.remove('hidden'); editMsgEl.textContent = 'تعذّر الاتصال بالخادم'; });
  });

  function handleResend(phone, btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    postAjax('pge_invitation_mgmt_resend', { phone: phone }).then(function (json) {
      btn.disabled = false;
      if (json && json.success) { showToast(json.data.message || 'تمت إعادة الإرسال', false); }
      else { showToast((json && json.data && json.data.message) || 'تعذّرت إعادة الإرسال', true); }
    }).catch(function () { btn.disabled = false; showToast('تعذّر الاتصال بالخادم', true); });
  }

  function handleRegenQr(phone, btn) {
    if (btn.disabled) return;
    if (!window.confirm('سيتم إبطال رمز QR الحالي فوراً واستبداله برمز جديد. متابعة؟')) return;
    btn.disabled = true;
    postAjax('pge_invitation_mgmt_qr_regenerate', { phone: phone }).then(function (json) {
      btn.disabled = false;
      if (json && json.success) { showToast(json.data.message || 'تم تجديد رمز الدعوة', false); fetchList(state.page); }
      else { showToast((json && json.data && json.data.message) || 'تعذّر تجديد رمز الدعوة', true); }
    }).catch(function () { btn.disabled = false; showToast('تعذّر الاتصال بالخادم', true); });
  }

  function handleCancel(phone, btn) {
    if (btn.disabled) return;
    if (!window.confirm('هل تريد إلغاء هذه الدعوة؟')) return;
    btn.disabled = true;
    postAjax('pge_invitation_mgmt_cancel', { phone: phone, reason: '' }).then(function (json) {
      btn.disabled = false;
      if (json && json.success) { showToast(json.data.message || 'تم الإلغاء', false); fetchList(state.page); }
      else { showToast((json && json.data && json.data.message) || 'تعذّر الإلغاء', true); }
    }).catch(function () { btn.disabled = false; showToast('تعذّر الاتصال بالخادم', true); });
  }

  // ══ RC1 Fix Pack 3B ("Legacy Guest Panel Retirement — Hard Delete Migration") ══
  // إعادة استخدام postAjax()/escapeHtml()/showToast()/fetchList() الحالية أعلاه
  // حرفياً — لا نسخة ثانية من أي منها. نفس فلسفة "Never trust the preview
  // result sent by the browser" غير مطلوبة هنا (لا معاينة/تأكيد بخطوتين
  // للحذف في RFC)، لكن التأكيد نفسه يمرّ إجبارياً عبر نافذة داخل الصفحة (لا
  // window.confirm()) قبل أي طلب AJAX فعلي.
  var invSelectAll = document.getElementById('invSelectAll');
  var bulkDeleteInvBtn = document.getElementById('bulkDeleteInvBtn');
  var deleteInvModal = document.getElementById('deleteInvModal');
  var deleteInvBody = document.getElementById('deleteInvBody');
  var deleteInvMsg = document.getElementById('deleteInvMsg');
  var deleteInvConfirmBtn = document.getElementById('deleteInvConfirmBtn');
  var deleteInvCancelBtn = document.getElementById('deleteInvCancelBtn');
  var deleteInvTargets = []; // [{phone,name}, ...] — الدفعة الحالية المطلوب حذفها.
  var deleteInFlight = false;

  function getSelectedInvRows() {
    return Array.from(document.querySelectorAll('.inv-row-checkbox:checked')).map(function (el) {
      var tr = el.closest('tr');
      var nameCell = tr ? tr.children[1] : null;
      return { phone: el.getAttribute('data-phone') || '', name: nameCell ? nameCell.textContent.trim() : '' };
    }).filter(function (r) { return r.phone; });
  }

  function refreshBulkDeleteInvState() {
    if (bulkDeleteInvBtn) bulkDeleteInvBtn.disabled = getSelectedInvRows().length === 0;
  }

  if (invSelectAll) {
    invSelectAll.addEventListener('change', function () {
      document.querySelectorAll('.inv-row-checkbox').forEach(function (cb) { cb.checked = invSelectAll.checked; });
      refreshBulkDeleteInvState();
    });
  }

  function openDeleteModal(targets) {
    deleteInvTargets = targets;
    deleteInvMsg.classList.add('hidden');
    deleteInvMsg.textContent = '';
    var count = targets.length;
    var lines = [];
    if (count === 1) {
      var label = targets[0].name ? targets[0].name : targets[0].phone;
      lines.push('<p>سيتم حذف دعوة «' + escapeHtml(label) + '» نهائياً من هذه المناسبة.</p>');
    } else {
      lines.push('<p>سيتم حذف <strong>' + count + '</strong> دعوة نهائياً من هذه المناسبة.</p>');
    }
    lines.push('<p>قد يتأثر سجلّ الحضور المرتبط بها.</p>');
    lines.push('<p class="font-bold text-destructive-text">لا يمكن التراجع عن هذا الإجراء.</p>');
    deleteInvBody.innerHTML = lines.join('');
    deleteInvModal.classList.remove('hidden');
  }
  function closeDeleteModal() {
    deleteInvModal.classList.add('hidden');
    deleteInvTargets = [];
  }

  if (deleteInvCancelBtn) deleteInvCancelBtn.addEventListener('click', closeDeleteModal);

  if (bulkDeleteInvBtn) {
    bulkDeleteInvBtn.addEventListener('click', function () {
      var targets = getSelectedInvRows();
      if (!targets.length) return;
      openDeleteModal(targets);
    });
  }

  if (deleteInvConfirmBtn) {
    deleteInvConfirmBtn.addEventListener('click', function () {
      if (deleteInFlight || !deleteInvTargets.length) return;
      deleteInFlight = true;
      deleteInvConfirmBtn.disabled = true;

      // ملاحظة تنفيذية: postAjax() تستخدم URLSearchParams.set() لكل مفتاح —
      // لا تدعم تكرار نفس المفتاح لمصفوفة (phones[]=a&phones[]=b) بنيوياً.
      // لذا تُرسَل الهواتف كنص واحد مفصول بفواصل تحت مفتاح عادي 'phones' —
      // معالج bulk_delete في الخادم يدعم أصلاً هذه الصيغة (preg_split على
      // الفواصل/المسافات) كمسار fallback، نفس ما يفعله المعالج القديم
      // pge_event_guest_bulk_delete في event-guests.php حرفياً.
      var isBulk = deleteInvTargets.length > 1;
      var request = isBulk
        ? postAjax('pge_invitation_mgmt_bulk_delete', { phones: deleteInvTargets.map(function (t) { return t.phone; }).join(',') })
        : postAjax('pge_invitation_mgmt_delete', { phone: deleteInvTargets[0].phone });

      request.then(function (json) {
        deleteInFlight = false;
        deleteInvConfirmBtn.disabled = false;
        if (json && json.success) {
          closeDeleteModal();
          showToast((json.data && json.data.message) || 'تم الحذف', false);
          fetchList(1);
        } else {
          deleteInvMsg.classList.remove('hidden');
          deleteInvMsg.style.background = '#fee2e2'; deleteInvMsg.style.color = '#991b1b';
          deleteInvMsg.textContent = (json && json.data && json.data.message) || 'تعذّر الحذف';
        }
      }).catch(function () {
        deleteInFlight = false;
        deleteInvConfirmBtn.disabled = false;
        deleteInvMsg.classList.remove('hidden');
        deleteInvMsg.style.background = '#fee2e2'; deleteInvMsg.style.color = '#991b1b';
        deleteInvMsg.textContent = 'تعذّر الاتصال بالخادم';
      });
    });
  }

  var INITIAL = <?php echo wp_json_encode([
      'items'       => array_map('pge_invitation_mgmt_reshape_row', is_array($initial_page['items']) ? $initial_page['items'] : []),
      'page'        => (int) $initial_page['page'],
      'total_pages' => (int) $initial_page['total_pages'],
  ]); ?>;

  var exportCsvBtn = document.getElementById('exportCsvBtn');
  var exportExcelBtn = document.getElementById('exportExcelBtn');
  if (exportCsvBtn) exportCsvBtn.addEventListener('click', function () { triggerInvitationExport('pge_invitation_mgmt_export_csv'); });
  if (exportExcelBtn) exportExcelBtn.addEventListener('click', function () { triggerInvitationExport('pge_invitation_mgmt_export_excel'); });

  var downloadExcelTemplateBtn = document.getElementById('downloadExcelTemplateBtn');
  if (downloadExcelTemplateBtn) downloadExcelTemplateBtn.addEventListener('click', triggerExcelTemplateDownload);

  // ══ RC1 Fix Pack 3A ("Invitation Bulk Add Migration") ═══════════════════
  // إعادة استخدام postAjax()/escapeHtml()/showToast()/fetchList() الحالية
  // أعلاه حرفياً — لا نسخة ثانية من أي منها.
  var BULK_STATUS_LABELS = {
    valid: 'صالح', invalid: 'غير صالح',
    duplicate_in_batch: 'مكرَّر داخل النص', duplicate_in_event: 'مكرَّر بالمناسبة',
  };
  var BULK_RESULT_LABELS = {
    created: 'أُنشئت', duplicate: 'مكرَّرة', invalid: 'غير صالحة', failed: 'فشلت',
  };
  var BULK_STATUS_BADGE_CLASS = {
    valid: 'bg-green-100 text-green-800', invalid: 'bg-destructive/10 text-destructive-text',
    duplicate_in_batch: 'bg-amber-100 text-amber-800', duplicate_in_event: 'bg-amber-100 text-amber-800',
  };
  var BULK_RESULT_BADGE_CLASS = {
    created: 'bg-green-100 text-green-800', duplicate: 'bg-amber-100 text-amber-800',
    invalid: 'bg-destructive/10 text-destructive-text', failed: 'bg-destructive/10 text-destructive-text',
  };

  var bulkModal = document.getElementById('bulkAddModal');
  var bulkTextarea = document.getElementById('bulkAddTextarea');
  var bulkErrorMsg = document.getElementById('bulkAddErrorMsg');
  var bulkInputState = document.getElementById('bulkInputState');
  var bulkPreviewState = document.getElementById('bulkPreviewState');
  var bulkProcessingState = document.getElementById('bulkProcessingState');
  var bulkResultState = document.getElementById('bulkResultState');
  var bulkSummaryBox = document.getElementById('bulkSummaryBox');
  var bulkPreviewBody = document.getElementById('bulkPreviewBody');
  var bulkResultSummaryBox = document.getElementById('bulkResultSummaryBox');
  var bulkResultBody = document.getElementById('bulkResultBody');
  var bulkInFlight = false;

  function bulkShowState(name) {
    bulkInputState.classList.toggle('hidden', name !== 'input');
    bulkPreviewState.classList.toggle('hidden', name !== 'preview');
    bulkProcessingState.classList.toggle('hidden', name !== 'processing');
    bulkResultState.classList.toggle('hidden', name !== 'result');
  }

  function bulkShowError(message) {
    bulkErrorMsg.textContent = message;
    bulkErrorMsg.classList.remove('hidden');
  }

  function bulkClearError() {
    bulkErrorMsg.classList.add('hidden');
    bulkErrorMsg.textContent = '';
  }

  function bulkRenderSummaryBadges(container, summary, keyLabels) {
    container.innerHTML = '';
    keyLabels.forEach(function (kl) {
      var span = document.createElement('span');
      span.className = 'inline-flex items-center gap-1 rounded-lg bg-secondary/40 px-2.5 py-1 text-xs font-bold text-foreground/75';
      span.textContent = kl.label + ': ' + (summary[kl.key] != null ? summary[kl.key] : 0);
      container.appendChild(span);
    });
  }

  function bulkRenderPreviewRows(rows) {
    bulkPreviewBody.innerHTML = '';
    rows.forEach(function (row) {
      var tr = document.createElement('tr');
      tr.className = 'border-b border-border/60 last:border-0';
      var badgeClass = BULK_STATUS_BADGE_CLASS[row.status] || 'bg-secondary/40 text-foreground/70';
      var statusLabel = BULK_STATUS_LABELS[row.status] || row.status;
      tr.innerHTML =
        '<td class="px-2.5 py-2 text-foreground/60">' + escapeHtml(row.line_number) + '</td>' +
        '<td class="px-2.5 py-2 font-semibold text-foreground">' + (escapeHtml(row.guest_name) || '<span class="text-foreground/40">—</span>') + '</td>' +
        '<td class="px-2.5 py-2 font-mono text-foreground/80" dir="ltr">' + escapeHtml(row.phone) + '</td>' +
        '<td class="px-2.5 py-2"><span class="inline-block rounded-md px-2 py-0.5 font-bold ' + badgeClass + '">' + escapeHtml(statusLabel) + '</span>' +
          (row.error ? '<div class="mt-0.5 text-[11px] text-foreground/55">' + escapeHtml(row.error) + '</div>' : '') +
        '</td>';
      bulkPreviewBody.appendChild(tr);
    });
  }

  function bulkRenderResultRows(rows) {
    bulkResultBody.innerHTML = '';
    rows.forEach(function (row) {
      var tr = document.createElement('tr');
      tr.className = 'border-b border-border/60 last:border-0';
      var badgeClass = BULK_RESULT_BADGE_CLASS[row.result] || 'bg-secondary/40 text-foreground/70';
      var resultLabel = BULK_RESULT_LABELS[row.result] || row.result;
      tr.innerHTML =
        '<td class="px-2.5 py-2 text-foreground/60">' + escapeHtml(row.line_number) + '</td>' +
        '<td class="px-2.5 py-2 font-semibold text-foreground">' + (escapeHtml(row.guest_name) || '<span class="text-foreground/40">—</span>') + '</td>' +
        '<td class="px-2.5 py-2 font-mono text-foreground/80" dir="ltr">' + escapeHtml(row.phone) + '</td>' +
        '<td class="px-2.5 py-2"><span class="inline-block rounded-md px-2 py-0.5 font-bold ' + badgeClass + '">' + escapeHtml(resultLabel) + '</span>' +
          (row.error ? '<div class="mt-0.5 text-[11px] text-foreground/55">' + escapeHtml(row.error) + '</div>' : '') +
        '</td>';
      bulkResultBody.appendChild(tr);
    });
  }

  function bulkOpenModal() {
    bulkClearError();
    bulkShowState('input');
    bulkModal.classList.remove('hidden');
  }
  function bulkCloseModal() {
    bulkModal.classList.add('hidden');
  }

  var openBulkAddBtn = document.getElementById('openBulkAddBtn');
  if (openBulkAddBtn) openBulkAddBtn.addEventListener('click', bulkOpenModal);
  document.getElementById('closeBulkAddBtn').addEventListener('click', bulkCloseModal);
  document.getElementById('bulkCancelBtn').addEventListener('click', bulkCloseModal);
  document.getElementById('bulkBackBtn').addEventListener('click', function () { bulkClearError(); bulkShowState('input'); });

  document.getElementById('bulkPreviewBtn').addEventListener('click', function () {
    if (bulkInFlight) return;
    bulkClearError();
    var text = bulkTextarea.value;
    if (!text || !text.trim()) { bulkShowError('الصق دعوة واحدة على الأقل قبل المعاينة'); return; }
    bulkInFlight = true;
    postAjax('pge_invitation_mgmt_bulk_preview', { raw_text: text }).then(function (json) {
      bulkInFlight = false;
      if (!json || !json.success) {
        bulkShowError((json && json.data && json.data.message) || 'تعذّرت المعاينة');
        return;
      }
      bulkRenderSummaryBadges(bulkSummaryBox, json.data.summary, [
        { key: 'total', label: 'الإجمالي' }, { key: 'valid', label: 'صالح' },
        { key: 'invalid', label: 'غير صالح' }, { key: 'duplicate', label: 'مكرَّر' },
      ]);
      bulkRenderPreviewRows(json.data.rows);
      bulkShowState('preview');
    }).catch(function () { bulkInFlight = false; bulkShowError('تعذّر الاتصال بالخادم'); });
  });

  document.getElementById('bulkConfirmBtn').addEventListener('click', function () {
    if (bulkInFlight) return;
    bulkInFlight = true;
    bulkClearError();
    bulkShowState('processing');
    // "Never trust the preview result sent by the browser": نُرسل raw_text
    // نفسه فقط من جديد — الخادم يُعيد التحليل والتحقّق بالكامل بنفسه، لا
    // إرسال لأي حالة/نتيجة معاينة محسوبة في المتصفح.
    postAjax('pge_invitation_mgmt_bulk_confirm', { raw_text: bulkTextarea.value }).then(function (json) {
      bulkInFlight = false;
      if (!json || !json.success) {
        bulkShowState('preview');
        bulkShowError((json && json.data && json.data.message) || 'تعذّر إنشاء الدعوات');
        return;
      }
      bulkRenderSummaryBadges(bulkResultSummaryBox, json.data.summary, [
        { key: 'total', label: 'الإجمالي' }, { key: 'created', label: 'أُنشئت' },
        { key: 'duplicate', label: 'مكرَّرة' }, { key: 'invalid', label: 'غير صالحة' }, { key: 'failed', label: 'فشلت' },
      ]);
      bulkRenderResultRows(json.data.rows);
      bulkShowState('result');
      showToast('اكتملت الإضافة الجماعية', false);
    }).catch(function () {
      bulkInFlight = false;
      bulkShowState('preview');
      bulkShowError('تعذّر الاتصال بالخادم');
    });
  });

  document.getElementById('bulkCloseResultBtn').addEventListener('click', function () {
    bulkTextarea.value = '';
    bulkCloseModal();
    fetchList(1);
  });

  renderRows(INITIAL.items);
  renderPagination(INITIAL.page, INITIAL.total_pages);
})();
</script>
