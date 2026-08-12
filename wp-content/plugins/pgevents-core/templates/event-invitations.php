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
      <!-- Messaging Architecture Phase 3 ("Manual Reminder"): زر فتح نافذة إرسال تذكير يدوي — نفس تنسيق الأزرار المجاورة بالضبط، لا تصميم جديد. -->
      <button type="button" id="openReminderBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">🔔 إرسال تذكير</button>
      <!-- Messaging Architecture Phase 4B-3B: Manual Thank You واجهة فقط فوق AJAX المنشورة، بلا فلتر مستلمين أو صلاحية Client موازية. -->
      <button type="button" id="openThankYouBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70"
              aria-controls="thankYouModal" aria-expanded="false">🎉 إرسال شكر للحاضرين</button>
      <!-- استيراد المدعوين من Excel (docs/EXCEL-GUEST-IMPORT-SPEC.md): تنزيل النموذج الرسمي (Phase 1) + Modal رفع/معاينة/استيراد كامل (Phase 3 رفع+معاينة، Phase 4 تأكيد+استيراد فعلي). -->
      <button type="button" id="downloadExcelTemplateBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">📥 تحميل نموذج Excel</button>
      <button type="button" id="openExcelImportBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">📤 استيراد من Excel</button>
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

  <!-- ══ "Import Guests from Excel" (Phase 3: رفع+معاينة، Phase 4: تأكيد+استيراد فعلي،
       Phase 5: UX فقط — لا تغيير على أي منطق/عقد Backend) — نافذة استيراد Excel ══
       نفس نمط نافذة bulkAddModal أعلاه حرفياً (dialog/fixed/bg-black40/rounded-2xl،
       نفس فئات الجدول/الشارات/الأزرار) — لا تصميم جديد. ست حالات تُبدَّل عبر hidden
       (حالة رئيسية واحدة ظاهرة في كل لحظة، بالإضافة لشريط خطأ عام اختياري فوقها):
       رفع (excelUploadState) → تحقّق (excelValidatingState) → معاينة
       (excelPreviewState) → معالجة (excelProcessingState) → نتيجة
       (excelResultState) → خطأ (excelImportErrorMsg، شريط وليس حالة كاملة، بنفس
       نمط bulkAddErrorMsg). زر التأكيد يُرسل upload_token فقط (لا صفوف/بيانات
       معاينة من المتصفح إطلاقاً — الخادم يُعيد التحليل والتحقّق وفحص التكرار من
       الصفر عند Confirm؛ Phase 5 لم يغيّر شيئاً من هذا العقد). ══ -->
  <div id="excelImportModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="excelImportHeading">
    <!-- بنية Header/Body/Footer (تحسين UX — بلا أي تغيير على Upload/Preview
         Logic/Confirm/Validation/Duplicate Detection/Parsing/AJAX/Backend):
         اللوحة نفسها flex-col بارتفاع أقصى ثابت، overflow-hidden (لا Scrollbar
         للوحة كلها) — كل حالة تُمرَّر داخلياً بنفسها عند الحاجة بدل تمدّد اللوحة
         كلها. حالة المعاينة (excelPreviewState) تحديداً هي المعاد هيكلتها فعلياً
         لثلاثة أجزاء (ملخص ثابت أعلى / جدول قابل للتمرير بارتفاع محدود / أزرار
         ورسائل ثابتة أسفل) — الحالات الأخرى قصيرة بطبيعتها ولا تعاني من نفس
         المشكلة، فأُبقيت ببنيتها الأصلية + overflow-y-auto احترازي فقط.
         تحديث حجم النافذة (ملاحظة UX: "Review Dialog صغير" لا "Full Screen
         Page"): max-h-[90vh] → max-h-[72vh] — يبقى جزء واضح من الصفحة الخلفية
         مرئياً خلف الـoverlay، ويُشبه نوافذ المراجعة في Google Drive/GitHub/
         Notion/Stripe بدل نافذة تكاد تملأ الشاشة. -->
    <div class="flex w-full max-w-2xl max-h-[72vh] flex-col overflow-hidden rounded-2xl bg-white">

      <!-- Header ثابت: عنوان + زر إغلاق + شريط الخطأ العام. يظهر في كل الحالات، لا يتمرّر أبداً. -->
      <div class="shrink-0 border-b border-border px-5 pt-5 pb-3">
        <div class="flex items-center justify-between">
          <h2 id="excelImportHeading" class="text-sm font-extrabold text-foreground">استيراد من Excel</h2>
          <button type="button" id="closeExcelImportBtn" class="h-9 w-9 rounded-lg border border-border text-sm font-bold text-foreground/70" aria-label="إغلاق">×</button>
        </div>
        <div id="excelImportErrorMsg" class="hidden mt-3 text-xs font-semibold rounded-xl px-3 py-2 bg-destructive/10 text-destructive-text" role="alert"></div>
      </div>

      <!-- حالة الرفع — Phase 5 (UX): عنوان + وصف قصير + صيغ مدعومة + تنبيه عمود الجوال + رابط النموذج، بلا شرح تقني (Scientific Notation/Cell Types) للمستخدم العادي. -->
      <div id="excelUploadState" class="overflow-y-auto px-5 py-4">
        <h3 class="text-sm font-extrabold text-foreground mb-1">استيراد المدعوين من Excel</h3>
        <p class="text-xs text-foreground/60 mb-3">ارفع ملف Excel أو CSV يحتوي على الاسم ورقم الجوال والملاحظة.</p>

        <label for="excelFileInput" class="block text-xs font-semibold text-foreground/70 mb-1.5">اختر الملف</label>
        <input id="excelFileInput" type="file" accept=".xlsx,.csv" class="block w-full text-sm rounded-xl border border-border p-3" />
        <div id="excelFileInfo" class="hidden mt-1.5 text-[11px] text-foreground/60" aria-live="polite"></div>

        <p class="mt-2 text-[11px] text-foreground/50">الصيغ المدعومة: <span class="font-semibold">.xlsx</span> و<span class="font-semibold">.csv</span> — صيغة <span class="font-semibold">.xls</span> القديمة غير مدعومة.</p>

        <div class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-[11px] text-amber-800">
          لضمان حفظ أرقام الجوال بشكل صحيح، استخدم نموذج Excel الرسمي ولا تغيّر تنسيق عمود رقم الجوال.
        </div>

        <button type="button" id="excelInlineTemplateBtn" class="mt-2 text-[11px] font-semibold text-primary underline">📥 تحميل نموذج Excel</button>

        <div class="flex flex-wrap justify-end gap-2 mt-3">
          <button type="button" id="excelCancelBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">إلغاء</button>
          <button type="button" id="excelUploadBtn" class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white">رفع ومعاينة</button>
        </div>
      </div>

      <!-- حالة التحقق — Phase 5/5.1 (UX فقط): تظهر أثناء رفع/تحليل الملف (Preview) —
           Spinner + نص + تلميح، بلا اعتماد على اللون وحده (نص مرافق دائماً). -->
      <div id="excelValidatingState" class="hidden overflow-y-auto px-5 py-10 text-center" aria-live="polite">
        <div class="mx-auto mb-3 h-8 w-8 rounded-full border-4 border-border border-t-primary animate-spin" aria-hidden="true"></div>
        <p class="text-sm font-semibold text-foreground/70">جارٍ رفع الملف والتحقق منه...</p>
        <p class="mt-1 text-[11px] text-foreground/45">إذا كان الملف كبيراً فقد تستغرق العملية عدة ثوانٍ.</p>
      </div>

      <!-- حالة المعاينة — مُعاد هيكلتها لثلاثة أجزاء داخلية (Header/Body/Footer
           الفعليان لهذه الحالة تحديداً، وهي الوحيدة التي تحتوي جدولاً قد يطول):
           flex-1 min-h-0 تجعلها تملأ الارتفاع المتبقّي أسفل هيدر اللوحة، محدودة
           بسقف اللوحة (max-h-[90vh]) — الجدول وحده يتمدد/يتمرّر داخلياً. -->
      <div id="excelPreviewState" class="hidden flex min-h-0 flex-1 flex-col px-5 py-4">
        <!-- الجزء الثابت العلوي (ملخص الاستيراد) — لا يتمرّر أبداً. -->
        <div class="shrink-0">
          <div id="excelSummaryBox" class="flex flex-wrap gap-2 mb-1.5" aria-live="polite"></div>
          <div id="excelSummaryDetails" class="hidden mb-3 text-[11px] text-foreground/55"></div>
        </div>

        <!-- الجزء القابل للتمرير — جدول Preview فقط، بارتفاع أقصى محدود (38vh،
             ضمن نافذة مضغوطة إجمالاً 72vh) بصرف النظر عن عدد الصفوف — لا يتمدد
             الـModal معه إطلاقاً. -->
        <div class="min-h-0 max-h-[38vh] flex-1 overflow-x-auto overflow-y-auto rounded-xl border border-border">
          <table class="w-full text-xs">
            <caption class="sr-only">معاينة صفوف استيراد Excel قبل التأكيد</caption>
            <thead>
              <tr class="sticky top-0 z-10 border-b border-border bg-secondary/30 text-right font-bold text-foreground/70">
                <th scope="col" class="px-2.5 py-2 bg-secondary/95">الاسم</th>
                <th scope="col" class="px-2.5 py-2 bg-secondary/95">الجوال</th>
                <th scope="col" class="px-2.5 py-2 bg-secondary/95">الملاحظة</th>
                <th scope="col" class="px-2.5 py-2 bg-secondary/95">الحالة</th>
              </tr>
            </thead>
            <tbody id="excelPreviewBody"></tbody>
          </table>
        </div>

        <!-- الجزء الثابت السفلي (رسائل + أزرار) — يبقى ظاهراً دائماً، لا يتمرّر مع الجدول. -->
        <div class="shrink-0">
          <p id="excelPreviewTruncatedMsg" class="hidden mt-2 text-[11px] text-foreground/55" aria-live="polite"></p>

          <!-- بطاقة "مشاكل خارج المعاينة" — تحسين عرض بحت (لا Backend/Parsing/
               Validation/Confirm): تنبّه المستخدم لصفوف بها مشكلة تقع بعد أول
               15 صفاً المعروضة، بلا الحاجة لعرض الجدول كاملاً. مضغوطة بصرياً
               عمداً (padding أقل + قائمة بارتفاع أقصى محدود وتمرير داخلي خاص
               بها) حتى لا تدفع أزرار الـFooter الثابت للأسفل مهما طال عدد
               المشاكل المسرودة (حتى 10 كحد أقصى أصلاً). -->
          <div id="excelOutOfPreviewIssuesCard" class="hidden mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5" aria-live="polite">
            <p class="text-[11px] font-bold text-amber-800">صفوف تحتوي على مشاكل خارج المعاينة</p>
            <ul id="excelOutOfPreviewIssuesList" class="mt-1 max-h-16 list-none space-y-0.5 overflow-y-auto text-[11px] text-amber-800"></ul>
            <p id="excelOutOfPreviewIssuesMore" class="hidden mt-0.5 text-[11px] text-amber-700"></p>
          </div>

          <!-- بطاقة "حدود الباقة" — Guest Limit/Package Quota: تظهر فقط عندما
               تتجاوز الصفوف الصالحة العدد المتبقي في باقة المضيف لهذه
               المناسبة. عرض بحت (لا تغيير في Parser/Validation/Duplicate
               Detection/Audit/Excel Logic) — الأرقام تأتي حصراً من حقل
               quota الجديد في استجابة Preview (pge_resolve_guest_quota_status()،
               نفس مصدر guest_limit المعروض فعلياً في لوحة التحكم كـ"المدعوين
               لكل مناسبة"). عند التأكيد الفعلي يُعاد احتساب هذا الحد من
               الصفر على الخادم — هذه الأرقام إعلامية فقط أثناء المعاينة،
               ولا يُعتمَد عليها في تنفيذ الاستيراد نفسه. مضغوطة بصرياً بنفس
               فلسفة بطاقة "مشاكل خارج المعاينة" أعلاه حتى لا تدفع أزرار
               الـFooter الثابت للأسفل. -->
          <div id="excelQuotaLimitCard" class="hidden mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5" aria-live="polite">
            <p class="text-[11px] font-bold text-amber-800">حدود الباقة</p>
            <ul class="mt-1 list-none space-y-0.5 text-[11px] text-amber-800">
              <li>حد الباقة: <span id="excelQuotaLimitValue"></span></li>
              <li>المدعوون الحاليون: <span id="excelQuotaCurrentValue"></span></li>
              <li>المتبقي: <span id="excelQuotaRemainingValue"></span></li>
              <li>عدد الصفوف الصالحة: <span id="excelQuotaValidRowsValue"></span></li>
              <li>سيتم استيراد: <span id="excelQuotaWillImportValue"></span> فقط</li>
            </ul>
            <p id="excelQuotaLimitMsg" class="mt-1 text-[11px] font-semibold text-amber-900"></p>
            <!-- Hook/placeholder لزر "ترقية الباقة" مستقبلي — غير مُفعَّل ولا
                 مُنفَّذ في هذه المرحلة صراحةً (خارج النطاق الحالي). يبقى
                 مخفياً وفارغاً؛ أي تنفيذ مستقبلي يُضيف زراً داخل هذا الـdiv
                 فقط دون الحاجة لتعديل بنية البطاقة أعلاه. -->
            <div id="excelQuotaUpgradeHook" class="hidden mt-1.5"></div>
          </div>

          <p id="excelNoValidMsg" class="hidden mt-2 text-xs font-semibold text-destructive-text" role="alert">لا توجد صفوف صالحة للاستيراد.</p>
          <div class="flex flex-wrap justify-end gap-2 mt-3">
            <button type="button" id="excelBackBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">اختيار ملف آخر</button>
            <button type="button" id="excelConfirmBtn" class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white disabled:opacity-40">استيراد المدعوين الصالحين</button>
          </div>
        </div>
      </div>

      <!-- حالة المعالجة — Phase 5.1 (UX فقط): Spinner + نص + تلميح، بلا أي أرقام/تقدير زمني. -->
      <div id="excelProcessingState" class="hidden overflow-y-auto px-5 py-10 text-center" aria-live="polite">
        <div class="mx-auto mb-3 h-8 w-8 rounded-full border-4 border-border border-t-primary animate-spin" aria-hidden="true"></div>
        <p class="text-sm font-semibold text-foreground/70">جارٍ استيراد المدعوين...</p>
        <p class="mt-1 text-[11px] text-foreground/45">إذا كان الملف كبيراً فقد تستغرق العملية عدة ثوانٍ.</p>
      </div>

      <!-- حالة النتيجة -->
      <div id="excelResultState" class="hidden overflow-y-auto px-5 py-4">
        <p id="excelResultMsg" class="mb-3 text-sm font-bold text-foreground" aria-live="polite"></p>
        <div id="excelResultSummaryBox" class="flex flex-wrap gap-2 mb-3"></div>
        <div class="flex justify-end gap-2 mt-3">
          <button type="button" id="excelCloseResultBtn" class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white">إغلاق</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ Messaging Architecture Phase 3 ("Manual Reminder") — نافذة إرسال تذكير ══ -->
  <div id="reminderModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="reminderHeading">
    <div class="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-5">
      <div class="flex items-center justify-between mb-3">
        <h2 id="reminderHeading" class="text-sm font-extrabold text-foreground">إرسال رسالة تذكير</h2>
        <button type="button" id="closeReminderBtn" class="h-9 w-9 rounded-lg border border-border text-sm font-bold text-foreground/70" aria-label="إغلاق">×</button>
      </div>

      <div id="reminderErrorMsg" class="hidden mb-3 text-xs font-semibold rounded-xl px-3 py-2 bg-destructive/10 text-destructive-text" role="alert"></div>

      <!-- حالة الإعداد: الفلتر + العدد + القالب + المعاينة -->
      <div id="reminderSetupState">
        <p class="text-xs font-bold text-foreground/70 mb-2">المستهدَفون</p>
        <div class="flex flex-col gap-2 mb-3">
          <label class="flex items-center gap-2 text-sm text-foreground">
            <input type="radio" name="reminderFilter" value="pending" checked class="h-4 w-4" />
            الذين لم يردوا
          </label>
          <label class="flex items-center gap-2 text-sm text-foreground">
            <input type="radio" name="reminderFilter" value="all" class="h-4 w-4" />
            جميع المدعوين
          </label>
        </div>

        <p class="text-xs text-foreground/60 mb-3">عدد المستلمين المتوقَّع: <span id="reminderRecipientCount" class="font-bold text-foreground">—</span> <span class="text-[11px]">(العدد النهائي يُعاد حسابه من الخادم عند الإرسال)</span></p>

        <label for="reminderTemplateInput" class="block text-xs font-bold text-foreground/70 mb-1.5">نص رسالة التذكير</label>
        <textarea id="reminderTemplateInput" rows="4" class="w-full rounded-xl border border-border p-3 text-sm outline-none focus:border-primary" maxlength="2000"></textarea>
        <div class="flex justify-end mt-1.5">
          <button type="button" id="saveReminderTemplateBtn" class="h-9 px-3 rounded-lg border border-border text-xs font-semibold text-foreground/70">حفظ نص التذكير لهذه المناسبة</button>
        </div>

        <div class="mt-3">
          <label class="flex items-center gap-2 text-sm text-foreground">
            <input type="checkbox" id="reminderIncludeImage" class="h-4 w-4" disabled />
            إرفاق صورة الدعوة مع التذكير
          </label>
          <p id="reminderImageUnavailableNote" class="hidden mt-1 text-[11px] text-foreground/60">لا توجد صورة دعوة متاحة لهذه المناسبة.</p>
        </div>

        <div class="mt-3 rounded-xl border border-border bg-secondary/20 p-3">
          <p class="text-[11px] font-bold text-foreground/60 mb-1">معاينة (لضيف تجريبي)</p>
          <img id="reminderPreviewImage" alt="صورة الدعوة" class="hidden mb-2 h-20 w-20 rounded-lg object-cover" />
          <p id="reminderPreviewText" class="text-xs text-foreground/80 whitespace-pre-line"></p>
        </div>

        <div class="flex justify-end gap-2 mt-4">
          <button type="button" id="cancelReminderBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">إلغاء</button>
          <button type="button" id="sendReminderBtn" class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white disabled:opacity-40">إرسال التذكير</button>
        </div>
      </div>

      <!-- حالة الإرسال الجاري -->
      <div id="reminderSendingState" class="hidden py-10 text-center" aria-live="polite">
        <div class="mx-auto mb-3 h-8 w-8 rounded-full border-4 border-border border-t-primary animate-spin" aria-hidden="true"></div>
        <p class="text-sm font-semibold text-foreground/70">جارٍ إرسال رسائل التذكير...</p>
        <p id="reminderSendingProgress" class="mt-1 text-[11px] text-foreground/45"></p>
      </div>

      <!-- حالة النتيجة -->
      <div id="reminderResultState" class="hidden py-4">
        <p id="reminderResultMsg" class="mb-3 text-sm font-bold text-foreground" aria-live="polite"></p>
        <div id="reminderResultSummaryBox" class="flex flex-wrap gap-2 mb-3"></div>
        <div class="flex justify-end gap-2 mt-3">
          <button type="button" id="closeReminderResultBtn" class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white">إغلاق</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ Messaging Architecture Phase 4B-3B — Manual Thank You UI ══ -->
  <div id="thankYouModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
       role="dialog" aria-modal="true" aria-labelledby="thankYouHeading" aria-describedby="thankYouDialogDescription" aria-hidden="true">
    <div class="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-5">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h2 id="thankYouHeading" class="text-sm font-extrabold text-foreground">إرسال شكر للحاضرين</h2>
          <p id="thankYouDialogDescription" class="mt-1 text-xs text-foreground/60">يُرسل الشكر للحاضرين المسجّل حضورهم فعلياً فقط.</p>
        </div>
        <button type="button" id="closeThankYouBtn" class="h-9 w-9 rounded-lg border border-border text-sm font-bold text-foreground/70" aria-label="إغلاق نافذة إرسال الشكر">×</button>
      </div>

      <!-- A. Loading Preview -->
      <div id="thankYouLoadingState" class="py-10 text-center" aria-live="polite">
        <div class="mx-auto mb-3 h-8 w-8 rounded-full border-4 border-border border-t-primary animate-spin" aria-hidden="true"></div>
        <p class="text-sm font-semibold text-foreground/70">جارٍ تجهيز معاينة رسالة الشكر...</p>
      </div>

      <!-- B. Ready -->
      <div id="thankYouReadyState" class="hidden">
        <div class="rounded-xl bg-secondary/20 p-3">
          <p class="text-xs font-bold text-foreground/60">الحاضرون المؤهلون للشكر</p>
          <p id="thankYouEligibleCount" class="mt-1 text-xl font-extrabold text-foreground" aria-live="polite">0</p>
          <p id="thankYouEligibilitySummary" class="mt-1 text-xs text-foreground/70"></p>
          <p id="thankYouSkippedPreviewNote" class="hidden mt-1 text-xs text-foreground/60">بعض المدعوين غير مؤهلين للإرسال حالياً.</p>
          <p id="thankYouActiveBatchNotice" class="hidden mt-2 text-xs font-semibold text-foreground/70" role="status">توجد عملية إرسال شكر جارية حالياً.</p>
        </div>

        <div class="mt-3 rounded-xl border border-border bg-secondary/20 p-3">
          <p class="text-[11px] font-bold text-foreground/60 mb-1">معاينة رسالة الشكر</p>
          <p id="thankYouPreviewText" class="text-xs text-foreground/80 whitespace-pre-line"></p>
        </div>

        <p id="thankYouNoEligibleMsg" class="hidden mt-3 text-sm font-semibold text-foreground/70" role="status">لا يوجد حضور مؤهل لإرسال رسالة شكر حالياً.</p>

        <div class="flex justify-end gap-2 mt-4">
          <button type="button" id="cancelThankYouBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">إلغاء</button>
          <button type="button" id="startThankYouBtn" disabled class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white disabled:opacity-40">بدء إرسال الشكر</button>
        </div>
      </div>

      <!-- C. Starting Batch -->
      <div id="thankYouStartingState" class="hidden py-10 text-center" aria-live="polite">
        <div class="mx-auto mb-3 h-8 w-8 rounded-full border-4 border-border border-t-primary animate-spin" aria-hidden="true"></div>
        <p class="text-sm font-semibold text-foreground/70">جارٍ بدء إرسال رسائل الشكر...</p>
        <p class="mt-1 text-[11px] text-foreground/45">يمكنك إغلاق النافذة؛ ستستمر الدفعة على الخادم.</p>
      </div>

      <!-- D. Processing -->
      <div id="thankYouProcessingState" class="hidden py-10 text-center" aria-live="polite">
        <div class="mx-auto mb-3 h-8 w-8 rounded-full border-4 border-border border-t-primary animate-spin" aria-hidden="true"></div>
        <p class="text-sm font-semibold text-foreground/70">جارٍ إرسال رسائل الشكر...</p>
        <p id="thankYouProcessingProgress" class="mt-2 text-sm font-bold text-foreground"></p>
        <p id="thankYouProcessingSummary" class="mt-1 text-xs text-foreground/60"></p>
        <p class="mt-3 text-[11px] text-foreground/45">يمكنك إغلاق النافذة؛ سيستمر الإرسال على الخادم.</p>
      </div>

      <!-- E. Complete -->
      <div id="thankYouCompleteState" class="hidden py-4">
        <p id="thankYouCompleteMsg" class="mb-3 text-sm font-bold text-foreground" aria-live="polite"></p>
        <div id="thankYouCompleteSummary" class="flex flex-wrap gap-2 mb-3"></div>
        <div id="thankYouCompleteDetails" class="text-xs text-foreground/70"></div>
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" id="closeThankYouResultBtn" class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white">إغلاق</button>
        </div>
      </div>

      <!-- F. Error -->
      <div id="thankYouErrorState" class="hidden py-4" role="alert">
        <p id="thankYouErrorMsg" class="rounded-xl px-3 py-2 bg-destructive/10 text-sm font-semibold text-destructive-text"></p>
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" id="closeThankYouErrorBtn" class="h-11 px-5 rounded-xl bg-primary text-sm font-bold text-white">إغلاق</button>
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
    created: 'أُنشئت', duplicate: 'مكرَّرة', invalid: 'غير صالحة', failed: 'فشلت', quota_exceeded: 'تجاوز حد الباقة',
  };
  var BULK_STATUS_BADGE_CLASS = {
    valid: 'bg-green-100 text-green-800', invalid: 'bg-destructive/10 text-destructive-text',
    duplicate_in_batch: 'bg-amber-100 text-amber-800', duplicate_in_event: 'bg-amber-100 text-amber-800',
  };
  var BULK_RESULT_BADGE_CLASS = {
    created: 'bg-green-100 text-green-800', duplicate: 'bg-amber-100 text-amber-800',
    invalid: 'bg-destructive/10 text-destructive-text', failed: 'bg-destructive/10 text-destructive-text',
    quota_exceeded: 'bg-amber-100 text-amber-800',
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
        { key: 'quota_exceeded', label: 'تجاوز حد الباقة' },
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

  // ══ "Import Guests from Excel" — Phase 3 (رفع+معاينة) / Phase 4 (تأكيد+استيراد) ══
  // إعادة استخدام postAjax()/escapeHtml()/showToast()/fetchList() الحالية أعلاه
  // حرفياً. postFileAjax() الجديدة أدناه ضرورية فقط لأن postAjax() الحالية
  // تُرسل application/x-www-form-urlencoded (لا تدعم رفع ملفات) — رفع الملف
  // الفعلي يحتاج multipart/form-data عبر FormData. زر التأكيد لا يستخدم
  // postFileAjax() إطلاقاً — يُرسل upload_token فقط عبر postAjax() العادية،
  // بلا أي ملف أو صفوف ("Never trust the preview result sent by the browser").
  function postFileAjax(action, file) {
    var formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', CONFIG.nonce);
    formData.append('event_id', CONFIG.eventId);
    formData.append('file', file);
    return fetch(CONFIG.ajaxUrl, {
      method: 'POST', credentials: 'same-origin', body: formData,
    }).then(function (res) { return res.json(); });
  }

  var EXCEL_STATUS_BADGE_CLASS = {
    valid: 'bg-green-100 text-green-800',
    duplicate: 'bg-amber-100 text-amber-800',
    invalid_phone: 'bg-destructive/10 text-destructive-text',
    invalid_phone_cell_type: 'bg-destructive/10 text-destructive-text',
    missing_name: 'bg-destructive/10 text-destructive-text',
    missing_phone: 'bg-destructive/10 text-destructive-text',
    empty_row: 'bg-secondary/40 text-foreground/70',
  };
  // Phase 5 (UX فقط): رسائل عربية صديقة مبنية على 'reason' الحقيقي القادم من
  // الخادم (Phase 1-4 بلا أي تغيير) — بديل أوضح من رسالة الخادم الخام عند
  // توفّر reason معروف، مع سقوط آمن على رسالة الخادم ثم رسالة عامة أخيراً.
  // لا كشف لأي stack trace/مسار/token/رمز حالة داخلي في أي مسار هنا.
  var EXCEL_ERROR_REASON_MESSAGES = {
    no_file: 'لم يتم اختيار أي ملف.',
    upload_error: 'حدث خطأ أثناء رفع الملف. حاول مرة أخرى.',
    file_too_large: 'حجم الملف أكبر من الحد المسموح.',
    unsupported_extension: 'صيغة الملف غير مدعومة. استخدم XLSX أو CSV.',
    invalid_mime: 'نوع الملف غير مطابق للامتداد. تأكد من أن الملف سليم.',
    storage_failed: 'تعذّر حفظ الملف على الخادم. حاول مرة أخرى.',
    unreadable_file: 'تعذّر قراءة الملف المحدَّد.',
    xlsx_parse_error: 'تعذر قراءة ملف Excel. تأكد من أن الملف غير تالف.',
    malformed_xlsx: 'تعذر قراءة ملف Excel. تأكد من أن الملف غير تالف.',
    malformed_csv: 'الملف لا يبدو ملف CSV صالحاً.',
    invalid_columns: 'تنسيق الأعمدة لا يطابق نموذج الاستيراد.',
    invalid_token: 'انتهت جلسة الاستيراد أو تم تنفيذها مسبقاً. أعد رفع الملف.',
    token_not_found: 'انتهت جلسة الاستيراد أو تم تنفيذها مسبقاً. أعد رفع الملف.',
    invalid_file_type: 'انتهت جلسة الاستيراد أو تم تنفيذها مسبقاً. أعد رفع الملف.',
    file_missing: 'انتهت جلسة الاستيراد أو تم تنفيذها مسبقاً. أعد رفع الملف.',
  };
  function excelResolveErrorMessage(json, fallback) {
    var data = json && json.data;
    if (data && data.reason && EXCEL_ERROR_REASON_MESSAGES[data.reason]) return EXCEL_ERROR_REASON_MESSAGES[data.reason];
    if (data && data.message) return data.message;
    return fallback;
  }
  // هل هذا الرفض في مرحلة Confirm يعني أن التوكن استُهلِك/انتهى فعلاً؟ في
  // هذه الحالة فقط لا فائدة من محاولة Confirm بنفس الملف مرة أخرى — يجب
  // إعادة الرفع من الصفر (نفس فلسفة القسم 15 من المواصفة).
  var EXCEL_TOKEN_DEAD_REASONS = { invalid_token: true, token_not_found: true, invalid_file_type: true, file_missing: true };

  function excelFormatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' بايت';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' كيلوبايت';
    return (bytes / (1024 * 1024)).toFixed(1) + ' ميجابايت';
  }

  var excelModal = document.getElementById('excelImportModal');
  var excelFileInput = document.getElementById('excelFileInput');
  var excelFileInfo = document.getElementById('excelFileInfo');
  var excelErrorMsg = document.getElementById('excelImportErrorMsg');
  var excelUploadState = document.getElementById('excelUploadState');
  var excelValidatingState = document.getElementById('excelValidatingState');
  var excelPreviewState = document.getElementById('excelPreviewState');
  var excelProcessingState = document.getElementById('excelProcessingState');
  var excelResultState = document.getElementById('excelResultState');
  var excelSummaryBox = document.getElementById('excelSummaryBox');
  var excelSummaryDetails = document.getElementById('excelSummaryDetails');
  var excelPreviewBody = document.getElementById('excelPreviewBody');
  var excelPreviewTruncatedMsg = document.getElementById('excelPreviewTruncatedMsg');
  var excelOutOfPreviewIssuesCard = document.getElementById('excelOutOfPreviewIssuesCard');
  var excelOutOfPreviewIssuesList = document.getElementById('excelOutOfPreviewIssuesList');
  var excelOutOfPreviewIssuesMore = document.getElementById('excelOutOfPreviewIssuesMore');
  var excelQuotaLimitCard = document.getElementById('excelQuotaLimitCard');
  var excelQuotaLimitValue = document.getElementById('excelQuotaLimitValue');
  var excelQuotaCurrentValue = document.getElementById('excelQuotaCurrentValue');
  var excelQuotaRemainingValue = document.getElementById('excelQuotaRemainingValue');
  var excelQuotaValidRowsValue = document.getElementById('excelQuotaValidRowsValue');
  var excelQuotaWillImportValue = document.getElementById('excelQuotaWillImportValue');
  var excelQuotaLimitMsg = document.getElementById('excelQuotaLimitMsg');
  var excelNoValidMsg = document.getElementById('excelNoValidMsg');
  var excelResultMsg = document.getElementById('excelResultMsg');
  var excelResultSummaryBox = document.getElementById('excelResultSummaryBox');
  var excelConfirmBtn = document.getElementById('excelConfirmBtn');
  var excelUploadBtn = document.getElementById('excelUploadBtn');
  var excelInFlight = false;
  var excelUploadToken = null; // upload_token الحالي — العنصر الوحيد المُرسَل لـConfirm.

  function excelShowState(name) {
    excelUploadState.classList.toggle('hidden', name !== 'upload');
    excelValidatingState.classList.toggle('hidden', name !== 'validating');
    excelPreviewState.classList.toggle('hidden', name !== 'preview');
    excelProcessingState.classList.toggle('hidden', name !== 'processing');
    excelResultState.classList.toggle('hidden', name !== 'result');
  }
  function excelShowError(message) {
    excelErrorMsg.textContent = message;
    excelErrorMsg.classList.remove('hidden');
  }
  function excelClearError() {
    excelErrorMsg.classList.add('hidden');
    excelErrorMsg.textContent = '';
  }

  // اختيار ملف جديد — عرض اسمه/نوعه/حجمه محلياً فقط داخل الواجهة (لا يُرسَل
  // هذا العرض لأي Audit، ولا يُغيّر عقد الرفع — file.name يُرسَل أصلاً ضمن
  // الرفع الفعلي multipart كجزء بنيوي من العملية، بصرف النظر عن هذا العرض).
  if (excelFileInput) {
    excelFileInput.addEventListener('change', function () {
      var file = excelFileInput.files && excelFileInput.files[0];
      if (!file) { excelFileInfo.classList.add('hidden'); excelFileInfo.textContent = ''; return; }
      excelFileInfo.textContent = file.name + ' — ' + (file.type || 'ملف') + ' — ' + excelFormatFileSize(file.size);
      excelFileInfo.classList.remove('hidden');
    });
  }

  var excelInlineTemplateBtn = document.getElementById('excelInlineTemplateBtn');
  if (excelInlineTemplateBtn) excelInlineTemplateBtn.addEventListener('click', triggerExcelTemplateDownload);

  function excelRenderSummaryBadges(container, summary, keyLabels) {
    container.innerHTML = '';
    keyLabels.forEach(function (kl) {
      var span = document.createElement('span');
      span.className = 'inline-flex items-center gap-1 rounded-lg bg-secondary/40 px-2.5 py-1 text-xs font-bold text-foreground/75';
      span.textContent = kl.label + ': ' + (summary[kl.key] != null ? summary[kl.key] : 0);
      container.appendChild(span);
    });
  }

  // حد عرض Preview فقط (لا علاقة له بعدد الصفوف المستوردة فعلياً — ذلك يُحسَم
  // بالكامل من طرف الخادم عبر excelUploadToken عند Confirm، بصرف النظر عمّا
  // عُرِض في الجدول). ملفات كبيرة تعرض أول 15 صفاً فقط (هدف Preview مراجعة
  // سريعة لا استعراض كامل الملف، ضمن نافذة Dialog مضغوطة) — تحسين عرض بحت،
  // بلا أي تغيير في منطق الاستيراد. (كانت 30 سابقاً، خُفِّضت لملاءمة الحجم
  // الجديد المضغوط للـModal.)
  var EXCEL_PREVIEW_DISPLAY_LIMIT = 15;

  // حد بطاقة "مشاكل خارج المعاينة" — أول 10 مشاكل فقط تُسرَد صراحةً، والباقي
  // يُلخَّص بسطر عددي واحد. تحسين عرض إضافي فوق حد الـ30 صفاً أعلاه، بنفس
  // الفلسفة: لا يُغيّر أي شيء في ما يُستورَد فعلياً، فقط ما يُعرَض للمستخدم.
  var EXCEL_OUT_OF_PREVIEW_ISSUES_LIMIT = 10;

  // يبني قائمة "الصف N — سبب المشكلة" لأي صف status !== 'valid' يقع بعد أول
  // EXCEL_PREVIEW_DISPLAY_LIMIT صفاً (أي غير ظاهر أصلاً في الجدول). رقم الصف
  // المعروض هو رقم صف Excel الحقيقي كما يراه المستخدم في الملف (فهرس المصفوفة
  // + 2: +1 لأن rows[] تبدأ فهرستها من صفر، و+1 إضافي لأن الصف الأول في الملف
  // هو صف الهيدر ولا يُحسَب ضمن rows[] أصلاً — راجع build_from_rows_ex()).
  function excelRenderOutOfPreviewIssues(rows) {
    excelOutOfPreviewIssuesList.innerHTML = '';
    excelOutOfPreviewIssuesMore.classList.add('hidden');
    excelOutOfPreviewIssuesMore.textContent = '';

    var hiddenRows = rows.length > EXCEL_PREVIEW_DISPLAY_LIMIT ? rows.slice(EXCEL_PREVIEW_DISPLAY_LIMIT) : [];
    var issues = [];
    hiddenRows.forEach(function (row, i) {
      if (row.status !== 'valid') {
        issues.push({ rowNumber: EXCEL_PREVIEW_DISPLAY_LIMIT + i + 2, label: row.status_label });
      }
    });

    if (issues.length === 0) {
      excelOutOfPreviewIssuesCard.classList.add('hidden');
      return;
    }

    issues.slice(0, EXCEL_OUT_OF_PREVIEW_ISSUES_LIMIT).forEach(function (issue) {
      var li = document.createElement('li');
      li.textContent = 'الصف ' + issue.rowNumber + ' — ' + issue.label; // textContent فقط — لا innerHTML، بلا حاجة لـescapeHtml.
      excelOutOfPreviewIssuesList.appendChild(li);
    });

    if (issues.length > EXCEL_OUT_OF_PREVIEW_ISSUES_LIMIT) {
      excelOutOfPreviewIssuesMore.textContent = '... وهناك ' + (issues.length - EXCEL_OUT_OF_PREVIEW_ISSUES_LIMIT) + ' مشكلة إضافية.';
      excelOutOfPreviewIssuesMore.classList.remove('hidden');
    }

    excelOutOfPreviewIssuesCard.classList.remove('hidden');
  }

  // بطاقة "حدود الباقة" — عرض بحت فقط (لا تغيير في الاستيراد نفسه): تُظهر
  // للمستخدم أن الصفوف الصالحة في ملفه تتجاوز الحصة المتبقية من مدعوي
  // باقته لهذه المناسبة تحديداً، وأن عدد المستوردين فعلياً سيُقتصَر على
  // العدد المتبقي فقط. الأرقام تأتي حصراً من حقل quota في استجابة Preview
  // (الخادم — pge_resolve_guest_quota_status()). لا حساب من جانب المتصفح.
  function excelRenderQuotaCard(quota) {
    if (!quota || quota.mode !== 'limited' || !quota.quota_exceeded) {
      excelQuotaLimitCard.classList.add('hidden');
      return null;
    }

    excelQuotaLimitValue.textContent = quota.limit;
    excelQuotaCurrentValue.textContent = quota.current;
    excelQuotaRemainingValue.textContent = quota.remaining;
    excelQuotaValidRowsValue.textContent = quota.valid_rows;
    excelQuotaWillImportValue.textContent = quota.will_import;

    excelQuotaLimitMsg.textContent = quota.will_import > 0
      ? 'سيتم استيراد أول ' + quota.will_import + ' مدعواً صالحاً فقط لأن هذا هو العدد المتبقي في باقتك.'
      : 'باقتك ممتلئة حالياً — لا يمكن استيراد أي مدعو جديد حتى ترقية الباقة.';

    excelQuotaLimitCard.classList.remove('hidden');
    return quota.will_import;
  }

  function excelRenderPreviewRows(rows) {
    excelPreviewBody.innerHTML = '';
    var total = rows.length;
    var rowsToRender = total > EXCEL_PREVIEW_DISPLAY_LIMIT ? rows.slice(0, EXCEL_PREVIEW_DISPLAY_LIMIT) : rows;
    rowsToRender.forEach(function (row) {
      var tr = document.createElement('tr');
      tr.className = 'border-b border-border/60 last:border-0';
      var badgeClass = EXCEL_STATUS_BADGE_CLASS[row.status] || 'bg-secondary/40 text-foreground/70';
      tr.innerHTML =
        '<td class="px-2.5 py-2 font-semibold text-foreground">' + (escapeHtml(row.name) || '<span class="text-foreground/40">—</span>') + '</td>' +
        '<td class="px-2.5 py-2 font-mono text-foreground/80" dir="ltr">' + (escapeHtml(row.phone) || '—') + '</td>' +
        '<td class="px-2.5 py-2 text-foreground/70">' + (escapeHtml(row.note) || '—') + '</td>' +
        '<td class="px-2.5 py-2"><span class="inline-block rounded-md px-2 py-0.5 font-bold ' + badgeClass + '">' + escapeHtml(row.status_label) + '</span></td>';
      excelPreviewBody.appendChild(tr);
    });

    // رسالة توضيحية أسفل الجدول عند القص فقط — الاستيراد الفعلي (Confirm) يظل
    // يشمل جميع الصفوف الصالحة دائماً، هذا القص للعرض المرئي فقط.
    if (total > EXCEL_PREVIEW_DISPLAY_LIMIT) {
      excelPreviewTruncatedMsg.textContent = 'يتم عرض أول ' + EXCEL_PREVIEW_DISPLAY_LIMIT + ' صفاً فقط للمراجعة، وسيتم استيراد جميع الصفوف الصالحة.';
      excelPreviewTruncatedMsg.classList.remove('hidden');
    } else {
      excelPreviewTruncatedMsg.classList.add('hidden');
      excelPreviewTruncatedMsg.textContent = '';
    }

    excelRenderOutOfPreviewIssues(rows);
  }

  var closeExcelImportBtn = document.getElementById('closeExcelImportBtn');

  function excelOpenModal() {
    excelClearError();
    excelFileInput.value = '';
    excelFileInfo.classList.add('hidden');
    excelFileInfo.textContent = '';
    excelUploadToken = null;
    closeExcelImportBtn.disabled = false; // إعادة ضبط احترازية (Phase 5.1) عند كل فتح.
    excelShowState('upload');
    excelModal.classList.remove('hidden');
  }
  function excelCloseModal() {
    excelModal.classList.add('hidden');
  }

  var openExcelImportBtn = document.getElementById('openExcelImportBtn');
  if (openExcelImportBtn) openExcelImportBtn.addEventListener('click', excelOpenModal);
  closeExcelImportBtn.addEventListener('click', excelCloseModal);
  document.getElementById('excelCancelBtn').addEventListener('click', excelCloseModal);
  document.getElementById('excelBackBtn').addEventListener('click', function () {
    excelClearError();
    excelUploadToken = null;
    excelFileInput.value = '';
    excelFileInfo.classList.add('hidden');
    excelFileInfo.textContent = '';
    excelShowState('upload');
  });

  // Phase 5 (UX): حالة "جارٍ التحقق" ظاهرة أثناء الطلب. Phase 5.1 (UX فقط،
  // بلا Queue/Polling/SSE): تعطيل صريح لكل الأزرار القادرة على إطلاق طلب
  // جديد أو مقاطعة الحالة أثناء الطلب — زر الرفع، اختيار ملف جديد، وزر
  // إغلاق الـModal (×) أيضاً (الأزرار الأخرى داخل excelUploadState/
  // excelPreviewState خامدة بنيوياً بالفعل لأن حاويتها hidden أثناء
  // validating/processing — لا حاجة لتعطيلها فردياً).
  document.getElementById('excelUploadBtn').addEventListener('click', function () {
    if (excelInFlight) return;
    excelClearError();
    var file = excelFileInput.files && excelFileInput.files[0];
    if (!file) { excelShowError('اختر ملفاً أولاً'); return; }
    excelInFlight = true;
    excelUploadBtn.disabled = true;
    excelFileInput.disabled = true;
    closeExcelImportBtn.disabled = true;
    excelShowState('validating');
    postFileAjax('pge_invitation_mgmt_excel_preview', file).then(function (json) {
      excelInFlight = false;
      excelUploadBtn.disabled = false;
      excelFileInput.disabled = false;
      closeExcelImportBtn.disabled = false;
      if (!json || !json.success) {
        excelShowState('upload');
        excelShowError(excelResolveErrorMessage(json, 'تعذّرت معاينة الملف'));
        return;
      }
      excelUploadToken = json.data.upload_token || null;
      var summary = json.data.summary || {};
      excelRenderSummaryBadges(excelSummaryBox, summary, [
        { key: 'total', label: 'إجمالي الصفوف' },
        { key: 'valid', label: 'سيتم استيراد' },
        { key: 'duplicate', label: 'مكرَّر' },
      ]);
      // تفاصيل إضافية اختيارية (القسم 7: "الأرقام الأساسية أهم من التفاصيل") —
      // سطر خفيف منفصل، يظهر فقط للبنود التي قيمتها أكبر من صفر فعلياً.
      var detailParts = [];
      [
        { key: 'missing_name', label: 'اسم مفقود' },
        { key: 'missing_phone', label: 'رقم مفقود' },
        { key: 'invalid_phone', label: 'رقم غير صالح' },
        { key: 'empty_row', label: 'صف فارغ' },
      ].forEach(function (kl) {
        var v = summary[kl.key];
        if (v) detailParts.push(kl.label + ': ' + v);
      });
      if (detailParts.length) {
        excelSummaryDetails.textContent = detailParts.join(' · ');
        excelSummaryDetails.classList.remove('hidden');
      } else {
        excelSummaryDetails.classList.add('hidden');
        excelSummaryDetails.textContent = '';
      }
      excelRenderPreviewRows(json.data.rows || []);
      var validCount = summary.valid || 0;
      // حدود الباقة (Guest Limit/Package Quota): إن كانت الصفوف الصالحة
      // تتجاوز الحصة المتبقية، يعرض زر التأكيد العدد الفعلي الذي سيُستورَد
      // (المتبقي)، لا إجمالي الصفوف الصالحة — إعلامي بحت هنا، والخادم يعيد
      // فرض هذا الحد من الصفر بشكل مستقل تماماً عند الضغط فعلياً على تأكيد.
      var cappedByQuota = excelRenderQuotaCard(json.data.quota || null);
      var willImportCount = cappedByQuota !== null ? cappedByQuota : validCount;
      var canImport = !!excelUploadToken && willImportCount > 0;
      excelConfirmBtn.textContent = 'استيراد ' + willImportCount + ' مدعو';
      excelConfirmBtn.disabled = !canImport;
      excelNoValidMsg.classList.toggle('hidden', validCount !== 0);
      excelShowState('preview');
    }).catch(function () {
      excelInFlight = false;
      excelUploadBtn.disabled = false;
      excelFileInput.disabled = false;
      closeExcelImportBtn.disabled = false;
      excelShowState('upload');
      excelShowError('تعذّر الاتصال بالخادم');
    });
  });

  excelConfirmBtn.addEventListener('click', function () {
    if (excelInFlight || !excelUploadToken) return;
    excelInFlight = true;
    // Phase 5.1: تعطيل فوري لكل زر قادر على إطلاق طلب جديد أو مقاطعة الحالة —
    // Confirm نفسه، وإغلاق الـModal (×). "رفع ملف جديد"/"تحميل نموذج" خامدان
    // بنيوياً بالفعل (داخل excelUploadState المخفية أثناء processing).
    excelConfirmBtn.disabled = true; // منع نقرة ثانية فوراً (القسم 11).
    excelConfirmBtn.textContent = 'جارٍ استيراد المدعوين...';
    closeExcelImportBtn.disabled = true;
    excelClearError();
    excelShowState('processing');
    // "Confirm receives token only" — لا صفوف/بيانات معاينة تُرسَل من المتصفح
    // إطلاقاً؛ الخادم يُعيد التحليل + التحقّق + فحص التكرار من الصفر.
    postAjax('pge_invitation_mgmt_excel_confirm', { upload_token: excelUploadToken }).then(function (json) {
      excelInFlight = false;
      closeExcelImportBtn.disabled = false;
      var tokenWasAlreadyConsumed = excelUploadToken !== null;
      excelUploadToken = null; // التوكن يُستهلَك دائماً من جانب الخادم بعد Confirm — لا إعادة استخدام.
      if (!json || !json.success) {
        var reason = json && json.data && json.data.reason;
        excelShowState('upload');
        excelFileInput.value = '';
        excelFileInfo.classList.add('hidden');
        excelFileInfo.textContent = '';
        // القسم 15: إذا انتهى/استُهلِك التوكن، لا محاولة إعادة استخدامه — العودة
        // الكاملة لحالة الرفع (بالفعل أعلاه) هي "إعادة رفع الملف".
        excelShowError(excelResolveErrorMessage(json, tokenWasAlreadyConsumed && reason && EXCEL_TOKEN_DEAD_REASONS[reason] ? 'انتهت جلسة الاستيراد أو تم تنفيذها مسبقاً. أعد رفع الملف.' : 'تعذّر تنفيذ الاستيراد'));
        return;
      }
      var s = json.data.summary || {};
      var imported = s.imported || 0;
      var skipped = (s.total_rows || 0) - imported;
      var quotaExceededCount = s.quota_exceeded || 0;
      excelRenderSummaryBadges(excelResultSummaryBox, s, [
        { key: 'imported', label: 'تم استيراد' },
        { key: 'duplicates', label: 'تم تخطي المكرر' },
        { key: 'invalid', label: 'غير صالح' },
        { key: 'failed', label: 'فشل' },
        { key: 'quota_exceeded', label: 'تجاوز حد الباقة' },
      ]);
      // القسم 12/13: رسالة نجاح واضحة فقط إذا imported>0، بلا نجاح مضلِّل عند
      // imported=0، وصياغة نجاح جزئي واضحة عند وجود صفوف مستوردة وأخرى لا.
      // حدود الباقة (Guest Limit/Package Quota): فرعان إضافيان جديدان أعلى
      // ترتيب الفحص (لأن quotaExceededCount=0 دائماً للاستيرادات التي لا
      // علاقة لها بالباقة، فالفروع القديمة أدناه تبقى تعمل بلا أي تغيير في
      // سلوكها لتلك الحالات).
      var resultText;
      if (imported > 0 && quotaExceededCount > 0) {
        resultText = 'تم استيراد ' + imported + ' مدعواً. لم يتم استيراد ' + quotaExceededCount + ' مدعوين بسبب تجاوز حد الباقة.';
      } else if (imported === 0 && quotaExceededCount > 0) {
        resultText = 'باقتك ممتلئة حالياً — لم يتم استيراد أي مدعو بسبب تجاوز حد الباقة.';
      } else if (imported > 0 && skipped === 0) {
        resultText = 'تم استيراد ' + imported + ' مدعو بنجاح.';
      } else if (imported > 0 && skipped > 0) {
        resultText = 'تم استيراد ' + imported + ' مدعو، وتعذر استيراد ' + skipped + '.';
      } else if ((s.total_rows || 0) > 0 && (s.duplicates || 0) === (s.total_rows || 0)) {
        resultText = 'كل الصفوف كانت مكرَّرة مسبقاً — لم يُضَف أي مدعو جديد.';
      } else if ((s.total_rows || 0) > 0) {
        resultText = 'لم يُضَف أي مدعو — لم توجد صفوف صالحة للاستيراد.';
      } else {
        resultText = 'لم يتم استيراد أي مدعو.';
      }
      excelResultMsg.textContent = resultText;
      excelShowState('result');
      if (imported > 0) showToast(resultText, false);
    }).catch(function () {
      excelInFlight = false;
      closeExcelImportBtn.disabled = false;
      excelShowState('upload');
      excelShowError('تعذّر الاتصال بالخادم');
    });
  });

  document.getElementById('excelCloseResultBtn').addEventListener('click', function () {
    excelFileInput.value = '';
    excelFileInfo.classList.add('hidden');
    excelFileInfo.textContent = '';
    excelCloseModal();
    fetchList(1);
  });

  // ══════════════════════════════════════════════════════════════════════
  // Messaging Architecture Phase 3 ("Manual Reminder")
  // ══════════════════════════════════════════════════════════════════════
  var reminderModal = document.getElementById('reminderModal');
  var reminderErrorMsg = document.getElementById('reminderErrorMsg');
  var reminderSetupState = document.getElementById('reminderSetupState');
  var reminderSendingState = document.getElementById('reminderSendingState');
  var reminderSendingProgress = document.getElementById('reminderSendingProgress');
  var reminderResultState = document.getElementById('reminderResultState');
  var reminderResultMsg = document.getElementById('reminderResultMsg');
  var reminderResultSummaryBox = document.getElementById('reminderResultSummaryBox');
  var reminderRecipientCount = document.getElementById('reminderRecipientCount');
  var reminderTemplateInput = document.getElementById('reminderTemplateInput');
  var reminderPreviewText = document.getElementById('reminderPreviewText');
  var reminderIncludeImage = document.getElementById('reminderIncludeImage');
  var reminderImageUnavailableNote = document.getElementById('reminderImageUnavailableNote');
  var reminderPreviewImage = document.getElementById('reminderPreviewImage');
  var sendReminderBtn = document.getElementById('sendReminderBtn');
  var saveReminderTemplateBtn = document.getElementById('saveReminderTemplateBtn');
  var reminderInFlight = false;
  var reminderPollTimer = null;
  var reminderImageAvailable = false;

  function reminderShowState(name) {
    reminderSetupState.classList.toggle('hidden', name !== 'setup');
    reminderSendingState.classList.toggle('hidden', name !== 'sending');
    reminderResultState.classList.toggle('hidden', name !== 'result');
  }

  function reminderShowError(message) {
    reminderErrorMsg.textContent = message;
    reminderErrorMsg.classList.remove('hidden');
  }

  function reminderClearError() {
    reminderErrorMsg.classList.add('hidden');
    reminderErrorMsg.textContent = '';
  }

  function reminderCurrentFilter() {
    var checked = document.querySelector('input[name="reminderFilter"]:checked');
    return checked ? checked.value : 'pending';
  }

  function reminderUpdateImagePreview() {
    var showImage = reminderImageAvailable && reminderIncludeImage.checked && reminderPreviewImage.getAttribute('src');
    reminderPreviewImage.classList.toggle('hidden', !showImage);
  }

  function reminderRefreshPreview() {
    reminderRecipientCount.textContent = '…';
    postAjax('pge_invitation_mgmt_reminder_preview', { filter: reminderCurrentFilter() }).then(function (res) {
      if (!res || !res.success) {
        reminderShowError((res && res.data && res.data.message) || 'تعذّر جلب المعاينة');
        return;
      }
      reminderRecipientCount.textContent = res.data.recipient_count;
      reminderTemplateInput.value = res.data.template;
      reminderPreviewText.textContent = res.data.preview_text;
      reminderImageAvailable = !!res.data.image_available;
      reminderIncludeImage.disabled = !reminderImageAvailable;
      reminderImageUnavailableNote.classList.toggle('hidden', reminderImageAvailable);
      if (reminderImageAvailable && res.data.preview_image_url) {
        reminderPreviewImage.src = res.data.preview_image_url;
      } else {
        reminderPreviewImage.removeAttribute('src');
      }
      reminderUpdateImagePreview();
    }).catch(function () {
      reminderShowError('تعذّر الاتصال بالخادم');
    });
  }

  function reminderOpenModal() {
    reminderClearError();
    reminderShowState('setup');
    document.querySelector('input[name="reminderFilter"][value="pending"]').checked = true;
    reminderIncludeImage.checked = false;
    reminderImageAvailable = false;
    reminderIncludeImage.disabled = true;
    reminderImageUnavailableNote.classList.add('hidden');
    reminderPreviewImage.removeAttribute('src');
    reminderUpdateImagePreview();
    reminderModal.classList.remove('hidden');
    reminderRefreshPreview();
  }

  function reminderCloseModal() {
    if (reminderPollTimer) { window.clearTimeout(reminderPollTimer); reminderPollTimer = null; }
    reminderModal.classList.add('hidden');
  }

  var REMINDER_ERROR_MESSAGES = {
    invalid_event: 'مناسبة غير صالحة',
    no_provider_credentials: 'لم يتم ضبط إعدادات واتساب لهذه المناسبة بعد',
    operation_in_progress: 'توجد عملية إرسال تذكير أخرى قيد البدء الآن. حاول بعد لحظات.',
    no_recipients: 'لا يوجد مستلمون مطابقون لهذا الفلتر حالياً',
    tracking_creation_failed: 'تعذّر تجهيز عملية الإرسال، حاول مرة أخرى',
  };

  function reminderRenderResult(data) {
    var total = data.total_targeted || 0;
    var sent = data.sent || 0;
    var failed = (data.failed || 0) + (data.ambiguous || 0);
    var skipped = (data.skipped_invalid_phone || 0) + (data.queued_remaining_failed || 0);

    reminderResultMsg.textContent = 'تم إرسال التذكير إلى ' + sent + ' مدعواً من أصل ' + total + '.'
      + (failed > 0 ? ' تعذّر الإرسال إلى ' + failed + '.' : '')
      + (skipped > 0 ? ' تم تخطي ' + skipped + '.' : '');

    reminderResultSummaryBox.innerHTML = '';
    [
      { label: 'الإجمالي المستهدَف', value: total },
      { label: 'تم الإرسال', value: sent },
      { label: 'تعذّر الإرسال', value: failed },
      { label: 'تم التخطي', value: data.skipped_invalid_phone || 0 },
    ].forEach(function (item) {
      var span = document.createElement('span');
      span.className = 'inline-flex items-center gap-1 rounded-lg bg-secondary/40 px-2.5 py-1 text-xs font-bold text-foreground/75';
      span.textContent = item.label + ': ' + item.value;
      reminderResultSummaryBox.appendChild(span);
    });
  }

  function reminderPollStatus(batchId, lastKnown) {
    postAjax('pge_invitation_mgmt_reminder_status', { batch_id: batchId }).then(function (res) {
      if (!res || !res.success) return;
      var s = res.data;
      reminderSendingProgress.textContent = 'تم حتى الآن: ' + s.sent + ' من ' + s.total;
      if (s.done) {
        reminderShowState('result');
        reminderRenderResult({
          total_targeted: s.total,
          sent: s.sent,
          failed: s.failed,
          ambiguous: s.ambiguous,
          skipped_invalid_phone: lastKnown.skipped_invalid_phone,
        });
        showToast('اكتمل إرسال التذكير', false);
      } else {
        reminderPollTimer = window.setTimeout(function () { reminderPollStatus(batchId, lastKnown); }, 4000);
      }
    }).catch(function () {
      reminderPollTimer = window.setTimeout(function () { reminderPollStatus(batchId, lastKnown); }, 6000);
    });
  }

  var openReminderBtn = document.getElementById('openReminderBtn');
  if (openReminderBtn) openReminderBtn.addEventListener('click', reminderOpenModal);
  document.getElementById('closeReminderBtn').addEventListener('click', reminderCloseModal);
  document.getElementById('cancelReminderBtn').addEventListener('click', reminderCloseModal);

  Array.prototype.forEach.call(document.querySelectorAll('input[name="reminderFilter"]'), function (radio) {
    radio.addEventListener('change', reminderRefreshPreview);
  });
  reminderIncludeImage.addEventListener('change', reminderUpdateImagePreview);

  saveReminderTemplateBtn.addEventListener('click', function () {
    reminderClearError();
    var text = reminderTemplateInput.value.trim();
    if (!text) { reminderShowError('نص القالب لا يمكن أن يكون فارغاً'); return; }
    saveReminderTemplateBtn.disabled = true;
    postAjax('pge_invitation_mgmt_save_reminder_template', { template: text }).then(function (res) {
      saveReminderTemplateBtn.disabled = false;
      if (res && res.success) {
        showToast('تم حفظ نص التذكير', false);
        reminderRefreshPreview();
      } else {
        reminderShowError((res && res.data && res.data.message) || 'تعذّر حفظ نص التذكير');
      }
    }).catch(function () {
      saveReminderTemplateBtn.disabled = false;
      reminderShowError('تعذّر الاتصال بالخادم');
    });
  });

  sendReminderBtn.addEventListener('click', function () {
    if (reminderInFlight) return;
    reminderClearError();
    reminderInFlight = true;
    sendReminderBtn.disabled = true;
    document.getElementById('closeReminderBtn').disabled = true;
    reminderShowState('sending');
    reminderSendingProgress.textContent = '';

    var filter = reminderCurrentFilter();
    postAjax('pge_invitation_mgmt_send_reminder', {
      filter: filter,
      include_image: reminderIncludeImage.checked ? 1 : 0,
    }).then(function (res) {
      reminderInFlight = false;
      document.getElementById('closeReminderBtn').disabled = false;
      if (!res || !res.success) {
        var reason = res && res.data && res.data.reason;
        reminderShowState('setup');
        reminderShowError((res && res.data && res.data.message) || REMINDER_ERROR_MESSAGES[reason] || 'تعذّر بدء إرسال التذكير');
        return;
      }
      var data = res.data;
      if (data.in_progress) {
        reminderSendingProgress.textContent = 'تم حتى الآن: ' + data.sent + ' من ' + data.total_targeted;
        reminderPollTimer = window.setTimeout(function () { reminderPollStatus(data.batch_id, data); }, 4000);
      } else {
        reminderShowState('result');
        reminderRenderResult(data);
      }
    }).catch(function () {
      reminderInFlight = false;
      document.getElementById('closeReminderBtn').disabled = false;
      reminderShowState('setup');
      reminderShowError('تعذّر الاتصال بالخادم');
    });
  });

  document.getElementById('closeReminderResultBtn').addEventListener('click', function () {
    reminderCloseModal();
  });

  // ══════════════════════════════════════════════════════════════════════
  // Messaging Architecture Phase 4B-3B — Manual Thank You UI
  // واجهة مستقلة فوق Preview/Start/Status الحالية؛ لا تختار مستلمين ولا ترسل
  // نص الرسالة أو بيانات مزوّد، ولا تنفّذ Claim/Send داخل طلب Start.
  // ══════════════════════════════════════════════════════════════════════
  var thankYouModal = document.getElementById('thankYouModal');
  var thankYouLoadingState = document.getElementById('thankYouLoadingState');
  var thankYouReadyState = document.getElementById('thankYouReadyState');
  var thankYouStartingState = document.getElementById('thankYouStartingState');
  var thankYouProcessingState = document.getElementById('thankYouProcessingState');
  var thankYouCompleteState = document.getElementById('thankYouCompleteState');
  var thankYouErrorState = document.getElementById('thankYouErrorState');
  var thankYouEligibleCount = document.getElementById('thankYouEligibleCount');
  var thankYouEligibilitySummary = document.getElementById('thankYouEligibilitySummary');
  var thankYouSkippedPreviewNote = document.getElementById('thankYouSkippedPreviewNote');
  var thankYouActiveBatchNotice = document.getElementById('thankYouActiveBatchNotice');
  var thankYouPreviewText = document.getElementById('thankYouPreviewText');
  var thankYouNoEligibleMsg = document.getElementById('thankYouNoEligibleMsg');
  var thankYouProcessingProgress = document.getElementById('thankYouProcessingProgress');
  var thankYouProcessingSummary = document.getElementById('thankYouProcessingSummary');
  var thankYouCompleteMsg = document.getElementById('thankYouCompleteMsg');
  var thankYouCompleteSummary = document.getElementById('thankYouCompleteSummary');
  var thankYouCompleteDetails = document.getElementById('thankYouCompleteDetails');
  var thankYouErrorMsg = document.getElementById('thankYouErrorMsg');
  var openThankYouBtn = document.getElementById('openThankYouBtn');
  var closeThankYouBtn = document.getElementById('closeThankYouBtn');
  var startThankYouBtn = document.getElementById('startThankYouBtn');
  var thankYouPollTimer = null;
  var thankYouStartInFlight = false;
  var thankYouSession = 0;
  var thankYouBatchId = '';
  var thankYouLastFocusedElement = null;

  var THANK_YOU_ERROR_MESSAGES = {
    invalid_nonce: 'انتهت صلاحية الجلسة. حدّث الصفحة وحاول مرة أخرى.',
    not_logged_in: 'يجب تسجيل الدخول لتنفيذ هذه العملية.',
    unauthorized: 'لا تملك صلاحية تنفيذ هذه العملية.',
    forbidden: 'لا تملك صلاحية تنفيذ هذه العملية.',
    invalid_event: 'تعذّر التحقق من المناسبة المطلوبة.',
    no_eligible: 'لم يعد هناك حضور مؤهل لإرسال رسالة شكر حالياً.',
    batch_in_progress: 'توجد دفعة شكر قيد البدء حالياً. حاول بعد لحظات.',
    missing_batch_id: 'تعذّر متابعة دفعة رسائل الشكر.',
    batch_not_found: 'تعذّر العثور على دفعة رسائل الشكر.',
    batch_event_mismatch: 'تعذّر التحقق من دفعة رسائل الشكر.',
    no_provider_credentials: 'خدمة إرسال الرسائل غير مهيأة حالياً.',
    batch_persistence_failed: 'تعذّر تجهيز دفعة رسائل الشكر. حاول مرة أخرى.',
    batch_id_generation_failed: 'تعذّر تجهيز دفعة رسائل الشكر. حاول مرة أخرى.',
    internal_error: 'تعذّر تنفيذ العملية. حاول مرة أخرى.',
  };

  function thankYouShowState(name) {
    thankYouLoadingState.classList.toggle('hidden', name !== 'loading');
    thankYouReadyState.classList.toggle('hidden', name !== 'ready');
    thankYouStartingState.classList.toggle('hidden', name !== 'starting');
    thankYouProcessingState.classList.toggle('hidden', name !== 'processing');
    thankYouCompleteState.classList.toggle('hidden', name !== 'complete');
    thankYouErrorState.classList.toggle('hidden', name !== 'error');
    thankYouModal.setAttribute('aria-busy', (name === 'loading' || name === 'starting') ? 'true' : 'false');
  }

  function thankYouStopPolling() {
    if (thankYouPollTimer) {
      window.clearTimeout(thankYouPollTimer);
      thankYouPollTimer = null;
    }
  }

  function thankYouErrorMessage(response, fallback) {
    var reason = response && response.data && response.data.reason;
    return THANK_YOU_ERROR_MESSAGES[reason] || fallback;
  }

  function thankYouShowError(message) {
    thankYouStopPolling();
    thankYouErrorMsg.textContent = message;
    thankYouShowState('error');
    document.getElementById('closeThankYouErrorBtn').focus();
  }

  function thankYouLoadPreview(sessionId) {
    postAjax('pge_invitation_mgmt_thank_you_preview', {}).then(function (res) {
      if (sessionId !== thankYouSession || thankYouModal.classList.contains('hidden')) return;
      if (!res || !res.success) {
        thankYouShowError(thankYouErrorMessage(res, 'تعذّر تجهيز معاينة رسالة الشكر.'));
        return;
      }

      var data = res.data || {};
      var eligible = Math.max(0, Number(data.eligible) || 0);
      var readyToSend = Math.max(0, Number(data.ready_to_send) || 0);
      var alreadySent = Math.max(0, Number(data.already_sent) || 0);
      var inProgress = Math.max(0, Number(data.in_progress) || 0);
      var activeBatch = data.active_batch === true;
      var total = Math.max(0, Number(data.total_current_invitations) || 0);
      var excluded = Math.max(0, total - eligible);
      thankYouEligibleCount.textContent = eligible;
      if (eligible === 0) {
        thankYouEligibilitySummary.textContent = 'لن تبدأ أي عملية إرسال.';
      } else if (readyToSend === eligible) {
        thankYouEligibilitySummary.textContent = eligible + ' من الحاضرين مؤهلون. توجد ' + readyToSend + ' رسائل شكر جاهزة للإرسال الآن.';
      } else if (readyToSend === 0 && alreadySent === eligible) {
        thankYouEligibilitySummary.textContent = 'سبق إرسال الشكر إلى جميع الحاضرين المؤهلين وعددهم ' + eligible + '. لا توجد رسائل جديدة للإرسال.';
      } else if (readyToSend === 0 && inProgress === 1) {
        thankYouEligibilitySummary.textContent = 'سبق إرسال الشكر إلى ' + alreadySent + '، وهناك شخص واحد قيد المعالجة أو انتظار تأكيد محاولة سابقة. لا توجد رسائل جديدة الآن.';
      } else if (inProgress === 0) {
        thankYouEligibilitySummary.textContent = eligible + ' مؤهلون: ' + readyToSend + ' جاهزون للإرسال، وسبق إرسال الشكر إلى ' + alreadySent + '.';
      } else {
        thankYouEligibilitySummary.textContent = eligible + ' مؤهلون: ' + readyToSend + ' جاهزون للإرسال، وسبق إرسال الشكر إلى ' + alreadySent + '، و' + inProgress + ' قيد المعالجة أو الانتظار.';
      }
      thankYouSkippedPreviewNote.classList.toggle('hidden', excluded === 0);
      thankYouActiveBatchNotice.classList.toggle('hidden', !activeBatch);
      thankYouPreviewText.textContent = data.preview_text || '';
      thankYouNoEligibleMsg.classList.toggle('hidden', eligible > 0);
      startThankYouBtn.disabled = readyToSend === 0 && !activeBatch;
      startThankYouBtn.textContent = activeBatch ? 'متابعة عملية الإرسال' : 'بدء إرسال الشكر';
      thankYouShowState('ready');
      (!startThankYouBtn.disabled ? startThankYouBtn : document.getElementById('cancelThankYouBtn')).focus();
    }).catch(function () {
      if (sessionId !== thankYouSession || thankYouModal.classList.contains('hidden')) return;
      thankYouShowError('تعذّر الاتصال بالخادم. حاول مرة أخرى لاحقاً.');
    });
  }

  function thankYouOpenModal() {
    thankYouSession += 1;
    thankYouStopPolling();
    thankYouStartInFlight = false;
    thankYouBatchId = '';
    startThankYouBtn.disabled = true;
    thankYouEligibleCount.textContent = '0';
    thankYouEligibilitySummary.textContent = '';
    thankYouSkippedPreviewNote.classList.add('hidden');
    thankYouActiveBatchNotice.classList.add('hidden');
    thankYouPreviewText.textContent = '';
    thankYouNoEligibleMsg.classList.add('hidden');
    startThankYouBtn.textContent = 'بدء إرسال الشكر';
    thankYouLastFocusedElement = document.activeElement;
    thankYouShowState('loading');
    thankYouModal.classList.remove('hidden');
    thankYouModal.setAttribute('aria-hidden', 'false');
    openThankYouBtn.setAttribute('aria-expanded', 'true');
    closeThankYouBtn.focus();
    thankYouLoadPreview(thankYouSession);
  }

  function thankYouCloseModal() {
    thankYouSession += 1;
    thankYouStopPolling();
    thankYouStartInFlight = false;
    thankYouModal.classList.add('hidden');
    thankYouModal.setAttribute('aria-hidden', 'true');
    openThankYouBtn.setAttribute('aria-expanded', 'false');
    if (thankYouLastFocusedElement && typeof thankYouLastFocusedElement.focus === 'function') {
      thankYouLastFocusedElement.focus();
    }
  }

  function thankYouRenderStatus(status) {
    var total = Math.max(0, Number(status.total) || 0);
    var sent = Math.max(0, Number(status.sent) || 0);
    var failed = Math.max(0, Number(status.failed) || 0);
    var ambiguous = Math.max(0, Number(status.ambiguous) || 0);
    var skipped = Math.max(0, Number(status.skipped) || 0);
    var processed = Math.min(total, sent + failed + ambiguous + skipped);
    thankYouProcessingProgress.textContent = 'تمت معالجة: ' + processed + ' من ' + total;
    thankYouProcessingSummary.textContent = 'تم الإرسال: ' + sent
      + ' · تعذر الإرسال: ' + failed
      + ' · تعذر تأكيد الحالة: ' + ambiguous
      + ' · تم التخطي: ' + skipped;
  }

  function thankYouRenderComplete(status) {
    var total = Math.max(0, Number(status.total) || 0);
    var sent = Math.max(0, Number(status.sent) || 0);
    var failed = Math.max(0, Number(status.failed) || 0);
    var ambiguous = Math.max(0, Number(status.ambiguous) || 0);
    var skipped = Math.max(0, Number(status.skipped) || 0);
    var hasPartialResult = failed > 0 || ambiguous > 0 || skipped > 0;

    thankYouCompleteMsg.textContent = hasPartialResult
      ? 'اكتملت معالجة رسائل الشكر مع نتائج تحتاج إلى المراجعة.'
      : 'اكتمل إرسال رسائل الشكر.';
    thankYouCompleteSummary.innerHTML = '';
    [
      { label: 'الإجمالي', value: total },
      { label: 'تم الإرسال', value: sent },
      { label: 'تعذر الإرسال', value: failed },
      { label: 'تعذر تأكيد الحالة', value: ambiguous },
      { label: 'تم التخطي', value: skipped },
    ].forEach(function (item) {
      var span = document.createElement('span');
      span.className = 'inline-flex items-center gap-1 rounded-lg bg-secondary/40 px-2.5 py-1 text-xs font-bold text-foreground/75';
      span.textContent = item.label + ': ' + item.value;
      thankYouCompleteSummary.appendChild(span);
    });

    var details = [];
    if (failed > 0) details.push('تعذر إرسال ' + failed + ' رسالة.');
    if (ambiguous > 0) details.push('تعذر تأكيد حالة ' + ambiguous + ' من الرسائل.');
    if (skipped > 0) details.push('تم تخطي ' + skipped + ' لأنها لم تعد مؤهلة أو سبق إرسال الشكر لها.');
    thankYouCompleteDetails.textContent = details.join(' ');
    thankYouShowState('complete');
    document.getElementById('closeThankYouResultBtn').focus();
  }

  function thankYouSchedulePoll(batchId) {
    thankYouStopPolling();
    thankYouPollTimer = window.setTimeout(function () {
      thankYouPollStatus(batchId);
    }, 4000);
  }

  function thankYouPollStatus(batchId) {
    var sessionId = thankYouSession;
    postAjax('pge_invitation_mgmt_thank_you_status', { batch_id: batchId }).then(function (res) {
      if (sessionId !== thankYouSession || thankYouModal.classList.contains('hidden')) return;
      if (!res || !res.success) {
        thankYouShowError(thankYouErrorMessage(res, 'تعذّر متابعة حالة إرسال رسائل الشكر.'));
        return;
      }

      thankYouRenderStatus(res.data || {});
      if (res.data && res.data.complete) {
        thankYouStopPolling();
        thankYouRenderComplete(res.data);
        return;
      }
      thankYouSchedulePoll(batchId);
    }).catch(function () {
      if (sessionId !== thankYouSession || thankYouModal.classList.contains('hidden')) return;
      thankYouProcessingSummary.textContent = 'تعذر تحديث الحالة مؤقتاً. ستتم المحاولة مجدداً.';
      thankYouSchedulePoll(batchId);
    });
  }

  function thankYouStartBatch() {
    if (thankYouStartInFlight || startThankYouBtn.disabled) return;
    thankYouStartInFlight = true;
    startThankYouBtn.disabled = true;
    thankYouShowState('starting');
    var sessionId = thankYouSession;

    postAjax('pge_invitation_mgmt_thank_you_start', {}).then(function (res) {
      if (sessionId !== thankYouSession || thankYouModal.classList.contains('hidden')) return;
      thankYouStartInFlight = false;
      if (!res || !res.success) {
        thankYouShowError(thankYouErrorMessage(res, 'تعذّر بدء إرسال رسائل الشكر. حاول مرة أخرى.'));
        return;
      }

      thankYouBatchId = String((res.data && res.data.batch_id) || '');
      if (!thankYouBatchId) {
        thankYouShowError('تعذّر متابعة دفعة رسائل الشكر.');
        return;
      }

      thankYouShowState('processing');
      thankYouRenderStatus((res.data && res.data.status) || {});
      if (res.data && res.data.status && res.data.status.complete) {
        thankYouRenderComplete(res.data.status);
        return;
      }
      thankYouSchedulePoll(thankYouBatchId);
    }).catch(function () {
      if (sessionId !== thankYouSession || thankYouModal.classList.contains('hidden')) return;
      thankYouStartInFlight = false;
      thankYouShowError('تعذّر الاتصال بالخادم. حاول مرة أخرى لاحقاً.');
    });
  }

  if (openThankYouBtn) openThankYouBtn.addEventListener('click', thankYouOpenModal);
  closeThankYouBtn.addEventListener('click', thankYouCloseModal);
  document.getElementById('cancelThankYouBtn').addEventListener('click', thankYouCloseModal);
  document.getElementById('closeThankYouResultBtn').addEventListener('click', thankYouCloseModal);
  document.getElementById('closeThankYouErrorBtn').addEventListener('click', thankYouCloseModal);
  startThankYouBtn.addEventListener('click', thankYouStartBatch);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !thankYouModal.classList.contains('hidden')) thankYouCloseModal();
  });
  window.addEventListener('beforeunload', thankYouStopPolling);

  renderRows(INITIAL.items);
  renderPagination(INITIAL.page, INITIAL.total_pages);
})();
</script>
