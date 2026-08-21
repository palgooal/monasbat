<?php
/**
 * ============================================================================
 * Owner/Admin — Invitation Team UI (H1C-UI-2 — Invitation Team UI Integration)
 * ============================================================================
 * القالب عرض فقط: يستدعي حصراً الأفعال الموجودة أصلاً في
 * includes/additional-inviter-ajax.php (W8/W9) وincludes/additional-inviter-
 * onboarding-ajax.php (W10) وincludes/event-access-ajax.php
 * (pge_event_access_list_groups) — لا $wpdb هنا، لا استدعاء مباشر لـ
 * PGE_Event_Access_Repository/PGE_Additional_Inviter/PGE_Additional_Inviter_
 * Onboarding/Authorization Core، ولا أي قرار تفويض أمني موازٍ في JavaScript.
 * Backend يبقى المرجع الوحيد لكل قرار صلاحية.
 *
 * نفس نمط الـPresentation Gate في templates/event-groups.php حرفياً (H1C-UI-1
 * Fix Pass 1 — Issue A): current_user_can('administrator') أو post_author ===
 * الفاعل — بلا edit_post وحدها (أوسع من نطاق H1C Owner/Admin الفعلي). هذا
 * مجرد فحص عرض يمنع فتح شاشة لن تنجح طلباتها لاحقاً — السلطة الحقيقية تبقى
 * حصراً عند AJAX handlers فوق Application Service كما هي.
 *
 * نطاق هذه الشاشة (H1C-UI-2): عرض الدعاة الإضافيين الفعّالين + الدعوات
 * المعلّقة + إضافة داعٍ إضافي جديد عبر onboarding (بريد فقط — لا user_id، لا
 * بحث مستخدمين). تعديل الحصة (Section 5 من الموجز) مُؤجَّل (DEFER) عمداً في
 * هذه المرحلة — لا يوقف بقية الشاشة، ويمكن إضافته لاحقاً كتحسين منفصل بلا أي
 * تغيير على ما هو موجود هنا.
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

// نفس شرط event-groups.php حرفياً — راجع الشرح أعلى الملف.
$can_manage = current_user_can('administrator')
    || ((int) $event_post->post_author === get_current_user_id());

if (!$can_manage) {
    wp_safe_redirect(home_url('/dashboard/?tab=events'));
    exit;
}

$mgmt_nonce = wp_create_nonce('pge_event_manage_nonce');
$event_title = get_the_title($event_id);
$manage_url = home_url('/event-manage/' . $event_id . '/');

get_header();
?>
<div dir="rtl" class="max-w-5xl mx-auto px-4 py-6" id="pgeInviteTeamRoot"
     data-event-id="<?php echo esc_attr((string) $event_id); ?>"
     data-nonce="<?php echo esc_attr($mgmt_nonce); ?>"
     data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">

  <div id="toast" role="status" aria-live="polite"
       style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99;min-width:200px;max-width:88vw;padding:11px 20px;border-radius:99px;font-size:13px;font-weight:700;text-align:center;pointer-events:none;opacity:0;transition:opacity .25s;"></div>

  <!-- ══ رأس الصفحة ══════════════════════════════════════════════ -->
  <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
    <div>
      <a href="<?php echo esc_url($manage_url); ?>" class="text-sm text-foreground/65 hover:underline">&larr; رجوع لإدارة المناسبة</a>
      <h1 class="mt-1 text-xl font-extrabold text-foreground">فريق الدعوة — <?php echo esc_html($event_title); ?></h1>
    </div>
  </div>
  <p class="text-sm text-foreground/60 mb-5">أضف أشخاصًا لمساعدتك في دعوة وإدارة ضيوف مجموعات محددة.</p>

  <!-- ══ القسم 1: الدعاة الإضافيون ══════════════════════════════════ -->
  <section class="rounded-2xl border border-border bg-white overflow-hidden mb-5" aria-labelledby="activeInvitersHeading">
    <div class="px-4 py-3 border-b border-border bg-secondary/20">
      <h2 id="activeInvitersHeading" class="text-sm font-extrabold text-foreground">الدعاة الإضافيون</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm" id="activeInvitersTable">
        <caption class="sr-only">قائمة الدعاة الإضافيين الفعّالين لهذه المناسبة</caption>
        <thead>
          <tr class="border-b border-border bg-secondary/30 text-right text-xs font-bold text-foreground/70">
            <th scope="col" class="px-4 py-3">الاسم</th>
            <th scope="col" class="px-4 py-3">المجموعة</th>
            <th scope="col" class="px-4 py-3">الحصة</th>
            <th scope="col" class="px-4 py-3">المتاح</th>
            <th scope="col" class="px-4 py-3">سيحضر</th>
            <th scope="col" class="px-4 py-3">لم يرد</th>
            <th scope="col" class="px-4 py-3">اعتذر</th>
          </tr>
        </thead>
        <tbody id="activeInvitersTableBody"></tbody>
      </table>
    </div>
    <div id="activeInvitersLoading" class="hidden px-4 py-10 text-center text-sm text-foreground/60">جارٍ التحميل...</div>
    <div id="activeInvitersEmpty" class="hidden px-4 py-10 text-center text-sm text-foreground/60">لا يوجد دعاة إضافيون فعّالون بعد.</div>
    <div id="activeInvitersError" class="hidden px-4 py-10 text-center text-sm text-destructive-text"></div>
  </section>

  <!-- ══ القسم 2: دعوات قيد الانتظار ══════════════════════════════════ -->
  <section class="rounded-2xl border border-border bg-white overflow-hidden mb-5" aria-labelledby="pendingInvitesHeading">
    <div class="px-4 py-3 border-b border-border bg-secondary/20">
      <h2 id="pendingInvitesHeading" class="text-sm font-extrabold text-foreground">دعوات قيد الانتظار</h2>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm" id="pendingInvitesTable">
        <caption class="sr-only">قائمة دعوات الانضمام قيد الانتظار</caption>
        <thead>
          <tr class="border-b border-border bg-secondary/30 text-right text-xs font-bold text-foreground/70">
            <th scope="col" class="px-4 py-3">الاسم</th>
            <th scope="col" class="px-4 py-3">البريد</th>
            <th scope="col" class="px-4 py-3">المجموعة</th>
            <th scope="col" class="px-4 py-3">الحصة</th>
            <th scope="col" class="px-4 py-3">تاريخ الانتهاء</th>
            <th scope="col" class="px-4 py-3">إجراءات</th>
          </tr>
        </thead>
        <tbody id="pendingInvitesTableBody"></tbody>
      </table>
    </div>
    <div id="pendingInvitesLoading" class="hidden px-4 py-10 text-center text-sm text-foreground/60">جارٍ التحميل...</div>
    <div id="pendingInvitesEmpty" class="hidden px-4 py-10 text-center text-sm text-foreground/60">لا توجد دعوات قيد الانتظار حالياً.</div>
    <div id="pendingInvitesError" class="hidden px-4 py-10 text-center text-sm text-destructive-text"></div>
  </section>

  <!-- ══ القسم 3: إضافة داعٍ إضافي ═══════════════════════════════════ -->
  <section class="rounded-2xl border border-border bg-white p-4" aria-labelledby="createInviteHeading">
    <h2 id="createInviteHeading" class="text-sm font-extrabold text-foreground mb-3">إضافة داعٍ إضافي</h2>
    <form id="createInviteForm" class="grid gap-2.5 sm:grid-cols-2">
      <div>
        <label for="inviteDisplayName" class="text-xs font-semibold text-foreground/70">الاسم</label>
        <input id="inviteDisplayName" name="display_name" type="text" required placeholder="اسم الداعي"
               class="mt-1 h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
      </div>
      <div>
        <label for="inviteEmail" class="text-xs font-semibold text-foreground/70">البريد الإلكتروني</label>
        <input id="inviteEmail" name="invitee_email" type="email" required placeholder="name@example.com" dir="ltr"
               class="mt-1 h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
      </div>
      <div>
        <label for="inviteGroup" class="text-xs font-semibold text-foreground/70">المجموعة</label>
        <select id="inviteGroup" name="group_id" required
                class="mt-1 h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary bg-white">
          <option value="">اختر مجموعة...</option>
        </select>
      </div>
      <div>
        <label for="inviteQuota" class="text-xs font-semibold text-foreground/70">الحصة</label>
        <input id="inviteQuota" name="allocated_quota" type="number" min="1" step="1" required placeholder="مثال: 20"
               class="mt-1 h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
      </div>
      <div class="sm:col-span-2">
        <button id="createInviteSubmit" type="submit" class="h-11 rounded-xl bg-primary px-5 text-sm font-bold text-white">إرسال الدعوة</button>
      </div>
    </form>
    <div id="createInviteMsg" class="hidden mt-2.5 text-xs font-semibold rounded-xl px-3 py-2" role="alert"></div>
  </section>

</div>
<script>
(function () {
  'use strict';

  var root = document.getElementById('pgeInviteTeamRoot');
  if (!root) return;

  var CONFIG = {
    ajaxUrl: root.getAttribute('data-ajax-url'),
    nonce: root.getAttribute('data-nonce'),
    eventId: root.getAttribute('data-event-id'),
  };

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

  function formatDate(raw) {
    if (!raw) return '—';
    var d = new Date(String(raw).replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return escapeHtml(raw);
    return d.toLocaleDateString('ar-SA', { year: 'numeric', month: 'short', day: 'numeric' });
  }

  // ── القسم 1: الدعاة الإضافيون الفعّالون ──────────────────────────────
  var activeBody = document.getElementById('activeInvitersTableBody');
  var activeLoading = document.getElementById('activeInvitersLoading');
  var activeEmpty = document.getElementById('activeInvitersEmpty');
  var activeError = document.getElementById('activeInvitersError');

  function fetchActiveInviters() {
    activeLoading.classList.remove('hidden');
    activeEmpty.classList.add('hidden');
    activeError.classList.add('hidden');
    activeBody.innerHTML = '';

    postAjax('pge_additional_inviter_list', { page: 1, per_page: 50 }).then(function (json) {
      activeLoading.classList.add('hidden');
      if (!json || !json.success) {
        activeError.classList.remove('hidden');
        activeError.textContent = (json && json.data && json.data.message) || 'تعذّر تحميل قائمة الدعاة';
        return;
      }
      var items = (json.data && json.data.items) || [];
      if (items.length === 0) {
        activeEmpty.classList.remove('hidden');
        return;
      }
      items.forEach(function (inv) {
        var tr = document.createElement('tr');
        tr.className = 'border-b border-border/60 last:border-0';
        tr.innerHTML =
          '<td class="px-4 py-3 font-semibold text-foreground">' + escapeHtml(inv.display_name || '—') + '</td>' +
          '<td class="px-4 py-3 text-foreground/75">' + escapeHtml(inv.group_name || '—') + '</td>' +
          '<td class="px-4 py-3 text-foreground/75">' + escapeHtml(inv.allocated_quota) + '</td>' +
          '<td class="px-4 py-3 text-foreground/75">' + escapeHtml(inv.available) + '</td>' +
          '<td class="px-4 py-3 text-emerald-700 font-semibold">' + escapeHtml(inv.attending) + '</td>' +
          '<td class="px-4 py-3 text-foreground/60">' + escapeHtml(inv.pending) + '</td>' +
          '<td class="px-4 py-3 text-destructive-text">' + escapeHtml(inv.declined) + '</td>';
        activeBody.appendChild(tr);
      });
    }).catch(function () {
      activeLoading.classList.add('hidden');
      activeError.classList.remove('hidden');
      activeError.textContent = 'تعذّر الاتصال بالخادم';
    });
  }

  // ── القسم 2: دعوات قيد الانتظار ──────────────────────────────────────
  var pendingBody = document.getElementById('pendingInvitesTableBody');
  var pendingLoading = document.getElementById('pendingInvitesLoading');
  var pendingEmpty = document.getElementById('pendingInvitesEmpty');
  var pendingError = document.getElementById('pendingInvitesError');

  function fetchPendingInvites() {
    pendingLoading.classList.remove('hidden');
    pendingEmpty.classList.add('hidden');
    pendingError.classList.add('hidden');
    pendingBody.innerHTML = '';

    postAjax('pge_additional_inviter_onboarding_list_pending', { page: 1, per_page: 50 }).then(function (json) {
      pendingLoading.classList.add('hidden');
      if (!json || !json.success) {
        pendingError.classList.remove('hidden');
        pendingError.textContent = (json && json.data && json.data.message) || 'تعذّر تحميل الدعوات المعلّقة';
        return;
      }
      var items = (json.data && json.data.items) || [];
      if (items.length === 0) {
        pendingEmpty.classList.remove('hidden');
        return;
      }
      items.forEach(function (inv) {
        var tr = document.createElement('tr');
        tr.className = 'border-b border-border/60 last:border-0';
        tr.setAttribute('data-invitation-id', inv.invitation_id);
        tr.innerHTML =
          '<td class="px-4 py-3 font-semibold text-foreground">' + (escapeHtml(inv.display_name) || '—') + '</td>' +
          '<td class="px-4 py-3 text-foreground/75" dir="ltr">' + escapeHtml(inv.contact_masked) + '</td>' +
          '<td class="px-4 py-3 text-foreground/75">' + escapeHtml(inv.group_name || '—') + '</td>' +
          '<td class="px-4 py-3 text-foreground/75">' + escapeHtml(inv.allocated_quota) + '</td>' +
          '<td class="px-4 py-3 text-foreground/60">' + formatDate(inv.expires_at) + '</td>' +
          '<td class="px-4 py-3"><button type="button" class="revoke-invite-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold text-destructive-text">إلغاء الدعوة</button></td>';
        tr.querySelector('.revoke-invite-btn').addEventListener('click', function (e) {
          revokeInvite(inv.invitation_id, e.target);
        });
        pendingBody.appendChild(tr);
      });
    }).catch(function () {
      pendingLoading.classList.add('hidden');
      pendingError.classList.remove('hidden');
      pendingError.textContent = 'تعذّر الاتصال بالخادم';
    });
  }

  function revokeInvite(invitationId, btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    postAjax('pge_additional_inviter_onboarding_revoke', { invitation_id: invitationId }).then(function (json) {
      if (json && json.success) {
        showToast('تم إلغاء الدعوة');
        fetchPendingInvites();
      } else {
        btn.disabled = false;
        showToast((json && json.data && json.data.message) || 'تعذّر إلغاء الدعوة', true);
      }
    }).catch(function () {
      btn.disabled = false;
      showToast('تعذّر الاتصال بالخادم', true);
    });
  }

  // ── القسم 3: إضافة داعٍ إضافي ─────────────────────────────────────────
  var groupSelect = document.getElementById('inviteGroup');

  function loadGroupsIntoSelect() {
    postAjax('pge_event_access_list_groups', { page: 1, per_page: 100 }).then(function (json) {
      if (!json || !json.success) return;
      var items = (json.data && json.data.items) || [];
      items.forEach(function (group) {
        if (group.status === 'archived') return;
        var opt = document.createElement('option');
        opt.value = group.id;
        opt.textContent = group.name;
        groupSelect.appendChild(opt);
      });
    }).catch(function () {});
  }

  var createForm = document.getElementById('createInviteForm');
  var createSubmitBtn = document.getElementById('createInviteSubmit');
  var createMsgEl = document.getElementById('createInviteMsg');
  var createInFlight = false;

  createForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (createInFlight) return;
    createInFlight = true;
    createSubmitBtn.disabled = true;

    var payload = {
      display_name: document.getElementById('inviteDisplayName').value,
      invitee_email: document.getElementById('inviteEmail').value,
      group_id: groupSelect.value,
      allocated_quota: document.getElementById('inviteQuota').value,
    };

    postAjax('pge_additional_inviter_onboarding_invite', payload).then(function (json) {
      createInFlight = false;
      createSubmitBtn.disabled = false;

      createMsgEl.classList.remove('hidden');
      if (json && json.success) {
        createMsgEl.textContent = 'تم إرسال الدعوة بنجاح';
        createMsgEl.style.background = '#dcfce7';
        createMsgEl.style.color = '#166534';
        createForm.reset();
        fetchPendingInvites();
      } else {
        createMsgEl.textContent = (json && json.data && json.data.message) || 'تعذّر إرسال الدعوة';
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

  // ── التحميل الأولي ────────────────────────────────────────────────────
  fetchActiveInviters();
  fetchPendingInvites();
  loadGroupsIntoSelect();
})();
</script>
<?php get_footer(); ?>
