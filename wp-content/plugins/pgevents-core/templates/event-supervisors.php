<?php
/**
 * ============================================================================
 * Host Supervisor Management — Entry Check-in Supervisors، Phase 8
 * ============================================================================
 * "Host Supervisor Management" RFC — "Architecture: Host UI → Authorized Host
 * Controller/AJAX → Supervisor Assignment Service → Database. No template
 * writes. No business rules in templates. No attendance logic."
 *
 * هذا الملف عرض فقط: يقرأ صفحة أولى من القائمة (SSR) عبر PGE_Supervisor_
 * Assignment_Service::list_assignments_for_event_page() وحصة المشرفين عبر
 * pge_resolve_supervisor_quota_status() (كلاهما قراءة فقط، بلا أي منطق أعمال
 * هنا)، ثم يُسلِّم كل عملية كتابة (إنشاء/تعديل/إعادة إرسال/إلغاء) لنقاط AJAX
 * في includes/supervisor-management-ajax.php حصراً — لا $wpdb هنا، لا SQL،
 * لا قرار تفويض إضافي (نفس pge_event_guests_user_can_manage() المُستخدَمة في
 * page-event-manage.php حرفياً).
 *
 * قالب مضيف عادي (بخلاف templates/supervisor-portal.php/supervisor-dashboard.php/
 * supervisor-checkin.php المستقلة تماماً): المضيف هنا يملك حساب ووردبريس
 * فعلياً ومسجَّل دخول بالفعل (نفس افتراض page-event-manage.php)، فيُستخدَم
 * get_header()/get_footer() من الثيم للحصول على نفس هوية الموقع البصرية
 * (متغيرات CSS/الخطوط) تلقائياً بلا تكرارها يدوياً.
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

$status_labels = [
    'invited' => 'بانتظار القبول',
    'pending' => 'بانتظار القبول',
    'active'  => 'نشط',
    'revoked' => 'ملغى',
];

// ── قراءة أولية (SSR) للصفحة الأولى — نفس ما ستُعيده نقطة AJAX لاحقاً بالضبط
// (لا حساب مختلف هنا)، حتى تظهر القائمة فوراً بلا "وميض تحميل" عند أول زيارة. ──
$initial_page = (class_exists('PGE_Supervisor_Assignment_Service'))
    ? PGE_Supervisor_Assignment_Service::list_assignments_for_event_page($event_id, '', 1, 20)
    : ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 0];

$quota_status = function_exists('pge_resolve_supervisor_quota_status') ? pge_resolve_supervisor_quota_status($event_id) : null;
$quota_is_error = is_wp_error($quota_status);
$quota_mode = (!$quota_is_error && is_array($quota_status)) ? (string) ($quota_status['mode'] ?? '') : '';
$quota_allowed = (!$quota_is_error && is_array($quota_status)) ? (int) ($quota_status['allowed'] ?? 0) : 0;
$quota_used = (!$quota_is_error && is_array($quota_status)) ? (int) ($quota_status['used'] ?? 0) : 0;
$quota_remaining = (!$quota_is_error && is_array($quota_status)) ? (int) ($quota_status['remaining'] ?? 0) : 0;

get_header();
?>
<div dir="rtl" class="max-w-5xl mx-auto px-4 py-6" id="pgeSupMgmtRoot"
     data-event-id="<?php echo esc_attr((string) $event_id); ?>"
     data-nonce="<?php echo esc_attr($mgmt_nonce); ?>"
     data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">

  <div id="toast" role="status" aria-live="polite"
       style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99;min-width:200px;max-width:88vw;padding:11px 20px;border-radius:99px;font-size:13px;font-weight:700;text-align:center;pointer-events:none;opacity:0;transition:opacity .25s;"></div>

  <!-- ══ رأس الصفحة ══════════════════════════════════════════════ -->
  <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div>
      <a href="<?php echo esc_url($manage_url); ?>" class="text-sm text-foreground/65 hover:underline">&larr; رجوع لإدارة المناسبة</a>
      <h1 class="mt-1 text-xl font-extrabold text-foreground">مشرفو الدخول — <?php echo esc_html($event_title); ?></h1>
    </div>
    <div id="quotaBadge" class="rounded-full px-4 py-2 text-xs font-bold ring-1 bg-secondary/60 ring-border text-foreground/70">
      <?php if ($quota_is_error): ?>
        تعذّر تحديد حصة المشرفين
      <?php elseif ($quota_mode === 'legacy'): ?>
        هذه الميزة غير متاحة لباقتك الحالية
      <?php else: ?>
        <span data-quota-text>المستخدَم <?php echo (int) $quota_used; ?> / <?php echo (int) $quota_allowed; ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ نموذج إضافة مشرف جديد ═══════════════════════════════════ -->
  <section class="rounded-2xl border border-border bg-white p-4 mb-5" aria-labelledby="createSupHeading">
    <h2 id="createSupHeading" class="text-sm font-extrabold text-foreground mb-3">إضافة مشرف جديد</h2>
    <form id="createSupForm" class="flex flex-wrap gap-2.5 items-start">
      <div class="flex-1 min-w-[160px]">
        <label for="newSupName" class="sr-only">الاسم (اختياري)</label>
        <input id="newSupName" name="name" type="text" placeholder="الاسم (اختياري)"
               class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
      </div>
      <div class="flex-1 min-w-[160px]">
        <label for="newSupPhone" class="sr-only">رقم جوال المشرف</label>
        <input id="newSupPhone" name="phone" type="tel" inputmode="tel" required placeholder="رقم جوال المشرف"
               class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
      </div>
      <button id="createSupSubmit" type="submit" class="h-11 rounded-xl bg-primary px-5 text-sm font-bold text-white">إضافة</button>
    </form>
    <div id="createSupMsg" class="hidden mt-2.5 text-xs font-semibold rounded-xl px-3 py-2" role="alert"></div>
  </section>

  <!-- ══ بحث ══════════════════════════════════════════════════════ -->
  <div class="mb-3">
    <label for="supSearchInput" class="sr-only">ابحث بالاسم أو رقم الجوال</label>
    <input id="supSearchInput" type="search" placeholder="ابحث بالاسم أو رقم الجوال..."
           class="h-11 w-full max-w-sm rounded-xl border border-border px-4 text-sm outline-none focus:border-primary" />
  </div>

  <!-- ══ القائمة ══════════════════════════════════════════════════ -->
  <div class="rounded-2xl border border-border bg-white overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm" id="supTable">
        <caption class="sr-only">قائمة مشرفي الدخول لهذه المناسبة</caption>
        <thead>
          <tr class="border-b border-border bg-secondary/30 text-right text-xs font-bold text-foreground/70">
            <th scope="col" class="px-4 py-3">الاسم</th>
            <th scope="col" class="px-4 py-3">الجوال</th>
            <th scope="col" class="px-4 py-3">الحالة</th>
            <th scope="col" class="px-4 py-3">تاريخ الدعوة</th>
            <th scope="col" class="px-4 py-3">تاريخ القبول</th>
            <th scope="col" class="px-4 py-3">آخر نشاط</th>
            <th scope="col" class="px-4 py-3">إجراءات</th>
          </tr>
        </thead>
        <tbody id="supTableBody">
          <!-- تُملأ عبر renderRows() أدناه؛ الصفوف الأولى (SSR) تُطبَع فوراً هنا لتفادي وميض التحميل. -->
        </tbody>
      </table>
    </div>
    <div id="supLoadingState" class="hidden px-4 py-10 text-center text-sm text-foreground/60">جارٍ التحميل...</div>
    <div id="supEmptyState" class="hidden px-4 py-10 text-center text-sm text-foreground/60">لا يوجد مشرفون بعد لهذه المناسبة.</div>
  </div>

  <!-- ══ ترقيم الصفحات ════════════════════════════════════════════ -->
  <div id="supPagination" class="flex items-center justify-center gap-2 mt-4" role="navigation" aria-label="ترقيم صفحات المشرفين"></div>

  <!-- ══ نموذج التعديل (بطاقة مضمَّنة، تظهر عند الطلب) ═══════════════ -->
  <div id="editSupCard" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="editSupHeading">
    <div class="w-full max-w-sm rounded-2xl bg-white p-5">
      <h2 id="editSupHeading" class="text-sm font-extrabold text-foreground mb-3">تعديل بيانات المشرف</h2>
      <form id="editSupForm" class="space-y-2.5">
        <input type="hidden" id="editSupId" />
        <label for="editSupName" class="sr-only">الاسم</label>
        <input id="editSupName" type="text" placeholder="الاسم" class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
        <label for="editSupPhone" class="sr-only">رقم الجوال</label>
        <input id="editSupPhone" type="tel" inputmode="tel" required placeholder="رقم الجوال" class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
        <div id="editSupMsg" class="hidden text-xs font-semibold rounded-xl px-3 py-2" role="alert"></div>
        <div class="flex gap-2">
          <button type="submit" class="flex-1 h-11 rounded-xl bg-primary text-sm font-bold text-white">حفظ</button>
          <button type="button" id="cancelEditSupBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">إلغاء</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══ نافذة احتياطية لنسخ رابط الدعوة يدوياً (Supervisor Manual Invitation
       Link: Secure One-Time Generation) ═══════════════════════════════════
       تظهر فقط عند تعذّر navigator.clipboard.writeText() (غير متاح في هذا
       المتصفح، أو رفض المستخدم إذن الحافظة). الرابط المعروض هنا هو نفسه
       الرابط الذي أعاده الخادم مرة واحدة فقط في استجابة AJAX السابقة — لا
       استدعاء خلفي ثانٍ هنا إطلاقاً، ولا أي تخزين في localStorage/sessionStorage؛
       يُمسَح من الحقل فور الإغلاق. -->
  <div id="manualLinkModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="manualLinkHeading">
    <div class="w-full max-w-sm rounded-2xl bg-white p-5">
      <h2 id="manualLinkHeading" class="text-sm font-extrabold text-foreground mb-2">رابط دعوة المشرف</h2>
      <!-- عنوان النافذة نصّ افتراضي (رابط الدعوة) — يُستبدَل ديناميكياً عبر
           openManualLinkFallback(url, title) عند استخدامها لرابط الدخول
           (Supervisor Login Architecture RFC)؛ نفس النافذة الاحتياطية
           الواحدة تُعاد استخدامها لكلا نوعَي الرابط — لا نافذة مكرَّرة. -->
      <p class="text-xs text-foreground/60 mb-3">تعذّر النسخ التلقائي — يمكنك تحديد الرابط ونسخه يدوياً:</p>
      <label for="manualLinkInput" class="sr-only">رابط الدعوة</label>
      <input id="manualLinkInput" type="text" readonly dir="ltr"
             class="h-11 w-full rounded-xl border border-border px-3 text-xs font-mono outline-none focus:border-primary" />
      <div id="manualLinkMsg" class="hidden mt-2.5 text-xs font-semibold rounded-xl px-3 py-2" role="alert"></div>
      <div class="flex gap-2 mt-3">
        <button type="button" id="manualLinkCopyBtn" class="flex-1 h-11 rounded-xl bg-primary text-sm font-bold text-white">نسخ الرابط</button>
        <button type="button" id="manualLinkCloseBtn" class="h-11 px-4 rounded-xl border border-border text-sm font-semibold text-foreground/70">إغلاق</button>
      </div>
    </div>
  </div>

</div>
<?php get_footer(); ?>
<script>
(function () {
  'use strict';

  var root = document.getElementById('pgeSupMgmtRoot');
  if (!root) return;

  var CONFIG = {
    ajaxUrl: root.getAttribute('data-ajax-url'),
    nonce: root.getAttribute('data-nonce'),
    eventId: root.getAttribute('data-event-id'),
  };

  var STATUS_LABELS = <?php echo wp_json_encode($status_labels); ?>;

  var state = {
    page: 1,
    search: '',
    requestSeq: 0, // يُستخدَم لتجاهل استجابات بحث متأخرة (Cancel Stale Searches)
  };

  var tableBody = document.getElementById('supTableBody');
  var loadingEl = document.getElementById('supLoadingState');
  var emptyEl = document.getElementById('supEmptyState');
  var paginationEl = document.getElementById('supPagination');
  var quotaBadge = document.getElementById('quotaBadge');
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

  function renderQuota(quota) {
    if (!quota) return;
    if (quota.mode === 'legacy') {
      quotaBadge.textContent = 'هذه الميزة غير متاحة لباقتك الحالية';
      return;
    }
    quotaBadge.textContent = 'المستخدَم ' + quota.used + ' / ' + quota.allowed;
  }

  function renderRows(items) {
    tableBody.innerHTML = '';

    items.forEach(function (row) {
      var tr = document.createElement('tr');
      tr.className = 'border-b border-border/60 last:border-0';
      tr.setAttribute('data-id', row.id);

      // Supervisor Login Architecture (Post-Activation Login) RFC: "Invitation
      // and Login must become two different concepts." canResend/canLogin
      // متنافيان بالضبط بحكم شرطيهما (invited/pending مقابل active) — لا صف
      // يُظهر أزرار الدعوة وأزرار الدخول معاً أبداً ("Do NOT show invitation
      // actions anymore" لحالة active — محقَّق ضمنياً هنا).
      var canResend = (row.status === 'invited' || row.status === 'pending');
      var canLogin = (row.status === 'active');
      var canRevoke = (row.status !== 'revoked');

      // نص زر الإلغاء يتبع السياق (Pending → "Cancel Invitation"، Active →
      // "Revoke Supervisor") — نفس الإجراء الخلفي تماماً (revoke-sup-btn،
      // pge_supervisor_mgmt_revoke) في كلتا الحالتين، لا منطق جديد، تسمية
      // عرض فقط.
      var revokeLabel = canResend ? 'إلغاء الدعوة' : (canLogin ? 'إلغاء إسناد المشرف' : 'إلغاء');

      tr.innerHTML =
        '<td class="px-4 py-3 font-semibold text-foreground">' + (escapeHtml(row.name) || '<span class="text-foreground/50 font-normal">بدون اسم</span>') + '</td>' +
        '<td class="px-4 py-3 font-mono text-foreground/80" dir="ltr">' + escapeHtml(row.phone) + '</td>' +
        '<td class="px-4 py-3"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 bg-secondary/60 ring-border text-foreground/70">' + escapeHtml(row.status_label) + '</span></td>' +
        '<td class="px-4 py-3 text-xs text-foreground/65">' + escapeHtml(row.invited_at || '—') + '</td>' +
        '<td class="px-4 py-3 text-xs text-foreground/65">' + escapeHtml(row.accepted_at || '—') + '</td>' +
        '<td class="px-4 py-3 text-xs text-foreground/65">' + escapeHtml(row.last_activity || '—') + '</td>' +
        '<td class="px-4 py-3">' +
          '<div class="flex flex-wrap gap-1.5">' +
            '<button type="button" class="edit-sup-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">تعديل</button>' +
            // ── أزرار الدعوة (Pending فقط: invited/pending) ──────────────
            (canResend ? '<button type="button" class="resend-sup-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">إعادة إرسال</button>' : '') +
            (canResend ? '<button type="button" class="copy-link-sup-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold" aria-label="نسخ رابط دعوة المشرف">نسخ رابط الدعوة</button>' : '') +
            // ── أزرار الدخول (Active فقط) — Supervisor Login Architecture ──
            (canLogin ? '<button type="button" class="send-login-sup-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">إرسال رابط الدخول</button>' : '') +
            (canLogin ? '<button type="button" class="copy-login-sup-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold" aria-label="نسخ رابط دخول المشرف">نسخ رابط الدخول</button>' : '') +
            (canRevoke ? '<button type="button" class="revoke-sup-btn h-9 px-2.5 rounded-lg bg-destructive/10 text-destructive-text text-xs font-semibold">' + escapeHtml(revokeLabel) + '</button>' : '') +
          '</div>' +
        '</td>';

      tr.querySelector('.edit-sup-btn').addEventListener('click', function () { openEditForm(row); });

      var resendBtn = tr.querySelector('.resend-sup-btn');
      if (resendBtn) resendBtn.addEventListener('click', function () { handleResend(row.id, resendBtn); });

      // Supervisor Manual Invitation Link: Secure One-Time Generation — تنفيذ.
      // راجع handleManualLink() أسفل الملف.
      var copyLinkBtn = tr.querySelector('.copy-link-sup-btn');
      if (copyLinkBtn) copyLinkBtn.addEventListener('click', function () { handleManualLink(row.id, copyLinkBtn); });

      // Supervisor Login Architecture (Post-Activation Login) RFC — تنفيذ.
      // راجع handleSendLogin()/handleCopyLoginLink() أسفل الملف.
      var sendLoginBtn = tr.querySelector('.send-login-sup-btn');
      if (sendLoginBtn) sendLoginBtn.addEventListener('click', function () { handleSendLogin(row.id, sendLoginBtn); });

      var copyLoginBtn = tr.querySelector('.copy-login-sup-btn');
      if (copyLoginBtn) copyLoginBtn.addEventListener('click', function () { handleCopyLoginLink(row.id, copyLoginBtn); });

      var revokeBtn = tr.querySelector('.revoke-sup-btn');
      if (revokeBtn) revokeBtn.addEventListener('click', function () { handleRevoke(row.id, revokeBtn); });

      tableBody.appendChild(tr);
    });

    emptyEl.classList.toggle('hidden', items.length !== 0);
  }

  function renderPagination(page, totalPages) {
    paginationEl.innerHTML = '';
    if (totalPages <= 1) return;

    for (var p = 1; p <= totalPages; p++) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = String(p);
      btn.className = 'h-9 w-9 rounded-lg text-xs font-bold ' + (p === page ? 'bg-primary text-white' : 'border border-border text-foreground/70');
      btn.setAttribute('aria-current', p === page ? 'page' : 'false');
      (function (targetPage) {
        btn.addEventListener('click', function () { fetchList(targetPage, state.search); });
      })(p);
      paginationEl.appendChild(btn);
    }
  }

  function fetchList(page, search) {
    state.page = page;
    state.search = search;

    var mySeq = ++state.requestSeq;

    loadingEl.classList.remove('hidden');
    emptyEl.classList.add('hidden');

    postAjax('pge_supervisor_mgmt_list', { page: page, search: search }).then(function (json) {
      if (mySeq !== state.requestSeq) return; // استجابة بحث متأخرة — تُهمَل (Cancel Stale Searches)
      loadingEl.classList.add('hidden');

      if (!json || !json.success) {
        showToast((json && json.data && json.data.message) || 'تعذّر تحميل القائمة', true);
        return;
      }

      var data = json.data;
      renderRows(data.items || []);
      renderPagination(data.page || 1, data.total_pages || 0);
      renderQuota(data.quota);
    }).catch(function () {
      if (mySeq !== state.requestSeq) return;
      loadingEl.classList.add('hidden');
      showToast('تعذّر الاتصال بالخادم', true);
    });
  }

  // ── بحث مع تأخير (Debounce) ────────────────────────────────────────────
  var searchInput = document.getElementById('supSearchInput');
  var searchTimer = null;
  searchInput.addEventListener('input', function () {
    window.clearTimeout(searchTimer);
    var value = searchInput.value;
    searchTimer = window.setTimeout(function () { fetchList(1, value); }, 300);
  });

  // ── إنشاء مشرف جديد ─────────────────────────────────────────────────────
  var createForm = document.getElementById('createSupForm');
  var createSubmitBtn = document.getElementById('createSupSubmit');
  var createMsgEl = document.getElementById('createSupMsg');
  var createInFlight = false;

  createForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (createInFlight) return; // منع إرسال مكرَّر أثناء طلب قائم بالفعل
    createInFlight = true;
    createSubmitBtn.disabled = true;

    var name = document.getElementById('newSupName').value;
    var phone = document.getElementById('newSupPhone').value;

    postAjax('pge_supervisor_mgmt_create', { name: name, phone: phone }).then(function (json) {
      createInFlight = false;
      createSubmitBtn.disabled = false;

      createMsgEl.classList.remove('hidden');
      if (json && json.success) {
        createMsgEl.textContent = json.data.message || 'تمت الإضافة';
        createMsgEl.style.background = '#dcfce7';
        createMsgEl.style.color = '#166534';
        createForm.reset();
        fetchList(1, state.search);
      } else {
        createMsgEl.textContent = (json && json.data && json.data.message) || 'تعذّر الإضافة';
        createMsgEl.style.background = '#fee2e2';
        createMsgEl.style.color = '#991b1b';
      }
    }).catch(function () {
      createInFlight = false;
      createSubmitBtn.disabled = false;
      createMsgEl.classList.remove('hidden');
      createMsgEl.textContent = 'تعذّر الاتصال بالخادم';
      createMsgEl.style.background = '#fee2e2';
      createMsgEl.style.color = '#991b1b';
    });
  });

  // ── تعديل مشرف ────────────────────────────────────────────────────────
  var editCard = document.getElementById('editSupCard');
  var editForm = document.getElementById('editSupForm');
  var editIdInput = document.getElementById('editSupId');
  var editNameInput = document.getElementById('editSupName');
  var editPhoneInput = document.getElementById('editSupPhone');
  var editMsgEl = document.getElementById('editSupMsg');
  var editInFlight = false;

  function openEditForm(row) {
    editIdInput.value = row.id;
    editNameInput.value = row.name || '';
    editPhoneInput.value = row.phone || '';
    editMsgEl.classList.add('hidden');
    editCard.classList.remove('hidden');
  }

  document.getElementById('cancelEditSupBtn').addEventListener('click', function () {
    editCard.classList.add('hidden');
  });

  editForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (editInFlight) return;
    editInFlight = true;

    postAjax('pge_supervisor_mgmt_edit', {
      assignment_id: editIdInput.value,
      name: editNameInput.value,
      phone: editPhoneInput.value,
    }).then(function (json) {
      editInFlight = false;
      if (json && json.success) {
        editCard.classList.add('hidden');
        showToast(json.data.message || 'تم التحديث', false);
        fetchList(state.page, state.search);
      } else {
        editMsgEl.classList.remove('hidden');
        editMsgEl.textContent = (json && json.data && json.data.message) || 'تعذّر التحديث';
        editMsgEl.style.background = '#fee2e2';
        editMsgEl.style.color = '#991b1b';
      }
    }).catch(function () {
      editInFlight = false;
      editMsgEl.classList.remove('hidden');
      editMsgEl.textContent = 'تعذّر الاتصال بالخادم';
    });
  });

  // ── إعادة إرسال الدعوة ───────────────────────────────────────────────────
  function handleResend(id, btn) {
    if (btn.disabled) return;
    btn.disabled = true;

    postAjax('pge_supervisor_mgmt_resend', { assignment_id: id }).then(function (json) {
      btn.disabled = false;
      if (json && json.success) {
        showToast(json.data.message || 'تمت إعادة الإرسال', false);
        fetchList(state.page, state.search);
      } else {
        showToast((json && json.data && json.data.message) || 'تعذّرت إعادة الإرسال', true);
      }
    }).catch(function () {
      btn.disabled = false;
      showToast('تعذّر الاتصال بالخادم', true);
    });
  }

  // ── نسخ رابط الدعوة (Supervisor Manual Invitation Link: Secure One-Time
  // Generation — تنفيذ) ───────────────────────────────────────────────────
  // بديل طوارئ عند تعذّر واتساب: طلب AJAX مخوَّل واحد فقط لكل ضغطة زر، يُعيد
  // الخادم رابط قبول جاهز الاستخدام (توكن جديد التُزِم فعلياً على الخادم قبل
  // إعادته — راجع includes/class-pge-supervisor-manual-link-service.php)،
  // يُنسَخ محلياً إلى الحافظة. لا توكن/هاش/معرّفات داخلية تصل هذه الدالة
  // إطلاقاً — فقط invitation_url الجاهز. لا تخزين للرابط في localStorage/
  // sessionStorage بأي شكل — يبقى في متغيّر جافاسكريبت محلي (url) ثم في حقل
  // النافذة الاحتياطية إن ظهرت، ويُمسَح من الـDOM عند الإغلاق (راجع
  // closeManualLinkFallback() أدناه).
  var manualLinkModal = document.getElementById('manualLinkModal');
  var manualLinkHeadingEl = document.getElementById('manualLinkHeading');
  var manualLinkInput = document.getElementById('manualLinkInput');
  var manualLinkMsg = document.getElementById('manualLinkMsg');
  var manualLinkCopyBtn = document.getElementById('manualLinkCopyBtn');
  var manualLinkCloseBtn = document.getElementById('manualLinkCloseBtn');
  // نافذة احتياطية واحدة مشتركة بين رابط الدعوة ورابط الدخول (Supervisor
  // Login Architecture RFC) — kindLabel يُحدِّد النص المعروض فقط (العنوان
  // ورسالة نجاح النسخ اليدوي)، لا فرق منطقي آخر بين الحالتين.
  var manualLinkKindLabel = 'رابط الدعوة';

  function showManualLinkMsg(text, isError) {
    manualLinkMsg.classList.remove('hidden');
    manualLinkMsg.textContent = text;
    manualLinkMsg.style.background = isError ? '#fee2e2' : '#dcfce7';
    manualLinkMsg.style.color = isError ? '#991b1b' : '#166534';
  }

  // نافذة احتياطية فقط عند تعذّر النسخ التلقائي — لا تستدعي الخادم مطلقاً
  // (الرابط مُولَّد ومُلتزَم على الخادم مسبقاً)، تعرض فقط الرابط الجاهز أصلاً.
  function openManualLinkFallback(url, kindLabel) {
    manualLinkKindLabel = kindLabel || 'الرابط';
    manualLinkHeadingEl.textContent = manualLinkKindLabel;
    manualLinkInput.value = url;
    manualLinkMsg.classList.add('hidden');
    manualLinkModal.classList.remove('hidden');
    window.setTimeout(function () {
      manualLinkInput.focus();
      manualLinkInput.select();
    }, 0);
  }

  function closeManualLinkFallback() {
    manualLinkModal.classList.add('hidden');
    manualLinkInput.value = ''; // لا يبقى الرابط في الـDOM بعد الإغلاق — لا تخزين متصفح بأي شكل
  }

  manualLinkCloseBtn.addEventListener('click', closeManualLinkFallback);

  manualLinkCopyBtn.addEventListener('click', function () {
    // نسخ يدوي فقط من الرابط المعروض بالفعل في الحقل — لا استدعاء خلفي هنا إطلاقاً.
    manualLinkInput.focus();
    manualLinkInput.select();
    var copied = false;
    try {
      copied = document.execCommand('copy');
    } catch (e) {
      copied = false;
    }
    if (copied) {
      showManualLinkMsg('تم نسخ ' + manualLinkKindLabel + '.', false);
    } else {
      showManualLinkMsg('تعذّر النسخ التلقائي — حدِّد النص وانسخه يدوياً (Ctrl+C).', true);
    }
  });

  function handleManualLink(id, btn) {
    if (btn.disabled) return;
    btn.disabled = true;

    postAjax('pge_supervisor_mgmt_manual_link', { assignment_id: id }).then(function (json) {
      btn.disabled = false;

      if (!json || !json.success) {
        showToast((json && json.data && json.data.message) || 'تعذّر توليد رابط الدعوة', true);
        return;
      }

      var url = (json.data && json.data.invitation_url) || '';
      if (!url) {
        showToast('تعذّر توليد رابط الدعوة', true);
        return;
      }

      if (window.navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(url).then(function () {
          showToast('تم نسخ رابط الدعوة.', false);
        }).catch(function () {
          // فشل النسخ التلقائي فقط — الرابط الجاهز أصلاً لا يُطلَب مجدداً من
          // الخادم، يُعرَض في النافذة الاحتياطية للنسخ اليدوي.
          openManualLinkFallback(url, 'رابط الدعوة');
        });
      } else {
        // navigator.clipboard غير متاح إطلاقاً في هذا المتصفح — نفس النافذة الاحتياطية مباشرة.
        openManualLinkFallback(url, 'رابط الدعوة');
      }
    }).catch(function () {
      btn.disabled = false;
      showToast('تعذّر الاتصال بالخادم', true);
    });
  }

  // ── إرسال/نسخ رابط الدخول (Supervisor Login Architecture RFC — تنفيذ) ────
  // منفصلتان تماماً عن نسخ رابط الدعوة أعلاه (نقطتا AJAX مختلفتان، خدمة خلفية
  // مختلفة تماماً) — تُعادان استخدام نفس النافذة الاحتياطية العامة فقط
  // (openManualLinkFallback/closeManualLinkFallback، معامَلة كأداة عامة).

  // "إرسال رابط الدخول" — عبر واتساب (Cartat)؛ لا رابط يُعاد للواجهة هنا
  // إطلاقاً (نفس فلسفة handleResend()) — وصل مباشرة عبر واتساب عند النجاح،
  // أو فشل الإرسال (والرسالة توجّه لاستخدام "نسخ رابط الدخول" بدلاً منه).
  function handleSendLogin(id, btn) {
    if (btn.disabled) return;
    btn.disabled = true;

    postAjax('pge_supervisor_mgmt_send_login', { assignment_id: id }).then(function (json) {
      btn.disabled = false;
      if (json && json.success) {
        showToast(json.data.message || 'تم إرسال رابط الدخول', false);
      } else {
        showToast((json && json.data && json.data.message) || 'تعذّر إرسال رابط الدخول', true);
      }
    }).catch(function () {
      btn.disabled = false;
      showToast('تعذّر الاتصال بالخادم', true);
    });
  }

  // "نسخ رابط الدخول" — توليد يدوي فوري بلا محاولة إرسال واتساب، بنفس تدفّق
  // handleManualLink() تماماً (طلب AJAX واحد ← نسخ تلقائي ← نافذة احتياطية
  // عند الفشل)، لنقطة نهاية مختلفة (pge_supervisor_mgmt_login_link) وحقل
  // استجابة مختلف (login_url).
  function handleCopyLoginLink(id, btn) {
    if (btn.disabled) return;
    btn.disabled = true;

    postAjax('pge_supervisor_mgmt_login_link', { assignment_id: id }).then(function (json) {
      btn.disabled = false;

      if (!json || !json.success) {
        showToast((json && json.data && json.data.message) || 'تعذّر توليد رابط الدخول', true);
        return;
      }

      var url = (json.data && json.data.login_url) || '';
      if (!url) {
        showToast('تعذّر توليد رابط الدخول', true);
        return;
      }

      if (window.navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(url).then(function () {
          showToast('تم نسخ رابط الدخول.', false);
        }).catch(function () {
          openManualLinkFallback(url, 'رابط الدخول');
        });
      } else {
        openManualLinkFallback(url, 'رابط الدخول');
      }
    }).catch(function () {
      btn.disabled = false;
      showToast('تعذّر الاتصال بالخادم', true);
    });
  }

  // ── إلغاء إسناد مشرف ──────────────────────────────────────────────────
  function handleRevoke(id, btn) {
    if (btn.disabled) return;
    if (!window.confirm('هل تريد إلغاء إسناد هذا المشرف؟ سيفقد الوصول فوراً.')) return;

    btn.disabled = true;

    postAjax('pge_supervisor_mgmt_revoke', { assignment_id: id, reason: '' }).then(function (json) {
      btn.disabled = false;
      if (json && json.success) {
        showToast(json.data.message || 'تم الإلغاء', false);
        fetchList(state.page, state.search);
      } else {
        showToast((json && json.data && json.data.message) || 'تعذّر الإلغاء', true);
      }
    }).catch(function () {
      btn.disabled = false;
      showToast('تعذّر الاتصال بالخادم', true);
    });
  }

  // ── العرض الأولي (SSR) — من نفس البيانات التي قُرئت أعلى القالب ──────────
  var INITIAL = <?php echo wp_json_encode([
      'items'       => array_map('pge_supervisor_mgmt_reshape_row', is_array($initial_page['items']) ? $initial_page['items'] : []),
      'page'        => (int) $initial_page['page'],
      'total_pages' => (int) $initial_page['total_pages'],
  ]); ?>;

  renderRows(INITIAL.items);
  renderPagination(INITIAL.page, INITIAL.total_pages);
})();
</script>
