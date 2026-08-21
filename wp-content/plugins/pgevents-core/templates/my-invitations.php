<?php
/**
 * ============================================================================
 * Additional Inviter Self-Service — "دعواتي" (H1C-UI-2 — Invitation Team UI
 * Integration)
 * ============================================================================
 * القالب عرض فقط: يستدعي حصراً الأفعال الموجودة أصلاً في
 * includes/additional-inviter-ajax.php (W8/W9 — pge_additional_inviter_get_
 * my_quota / pge_additional_inviter_create_guest) وincludes/event-access-
 * ajax.php (pge_event_access_list_guests) — لا $wpdb هنا، لا استدعاء مباشر
 * لأي طبقة Repository/Authorization/Application Service.
 *
 * Presentation Gate (Section 10 من موجز H1C-UI-2): تسجيل دخول فقط — بلا أي
 * إعادة تنفيذ لتفويض "هل هذا المستخدم داعٍ إضافي فعّال لهذه المناسبة؟" هنا؛
 * تلك السلطة تبقى حصراً عند PGE_Additional_Inviter::get_quota_status_for_
 * self() (المُستدعاة عبر AJAX أدناه) — إن لم يكن المستخدم داعياً إضافياً
 * فعّالاً لهذه المناسبة، سيُرجِع ذلك الاستدعاء not_authorized/misconfigured
 * وتُعرَض حالة خطأ بسيطة بدل المحتوى، لا حجب على مستوى القالب.
 *
 * نطاق هذه الشاشة: ملخص الحصة الذاتية + إضافة ضيف (بلا اختيار مجموعة — الخادم
 * يحلّها من العضوية الوحيدة) + قائمة الضيوف المُسنَدة (نطاق القراءة بالكامل
 * من مسؤولية Backend عبر list_accessible_guests_for_actor()). لا
 * edit/delete/move/cancel — غير موجودة في هذه الواجهة أصلاً.
 */
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
    auth_redirect();
}

$event_id = (int) get_query_var('event_id');
$event_post = $event_id ? get_post($event_id) : null;

if (!$event_id || !$event_post || $event_post->post_type !== 'pge_event') {
    wp_safe_redirect(home_url('/dashboard/'));
    exit;
}

$mgmt_nonce = wp_create_nonce('pge_event_manage_nonce');
$event_title = get_the_title($event_id);

get_header();
?>
<div dir="rtl" class="max-w-4xl mx-auto px-4 py-6" id="pgeMyInvitesRoot"
     data-event-id="<?php echo esc_attr((string) $event_id); ?>"
     data-nonce="<?php echo esc_attr($mgmt_nonce); ?>"
     data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">

  <div id="toast" role="status" aria-live="polite"
       style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99;min-width:200px;max-width:88vw;padding:11px 20px;border-radius:99px;font-size:13px;font-weight:700;text-align:center;pointer-events:none;opacity:0;transition:opacity .25s;"></div>

  <!-- ══ رأس الصفحة ══════════════════════════════════════════════ -->
  <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
    <div>
      <a href="<?php echo esc_url(home_url('/dashboard/')); ?>" class="text-sm text-foreground/65 hover:underline">&larr; رجوع للوحة التحكم</a>
      <h1 class="mt-1 text-xl font-extrabold text-foreground">دعواتي — <?php echo esc_html($event_title); ?></h1>
    </div>
  </div>

  <div id="myInvitesLoading" class="px-4 py-10 text-center text-sm text-foreground/60">جارٍ التحميل...</div>
  <div id="myInvitesError" class="hidden rounded-2xl border border-border bg-white px-4 py-10 text-center text-sm text-destructive-text"></div>

  <div id="myInvitesContent" class="hidden">

    <!-- ══ ملخص الحصة ══════════════════════════════════════════════ -->
    <section class="rounded-2xl border border-border bg-white p-4 mb-5" aria-labelledby="quotaSummaryHeading">
      <h2 id="quotaSummaryHeading" class="text-sm font-extrabold text-foreground mb-3">ملخص الحصة</h2>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-center">
        <div class="rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
          <div id="quotaAllocated" class="text-lg font-extrabold text-foreground">—</div>
          <div class="mt-1 text-[11px] text-foreground/65">الحصة المخصصة</div>
        </div>
        <div class="rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
          <div id="quotaOccupied" class="text-lg font-extrabold text-foreground">—</div>
          <div class="mt-1 text-[11px] text-foreground/65">المستخدم</div>
        </div>
        <div class="rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
          <div id="quotaAvailable" class="text-lg font-extrabold text-primary">—</div>
          <div class="mt-1 text-[11px] text-foreground/65">المتاح</div>
        </div>
        <div class="rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
          <div id="quotaAttending" class="text-lg font-extrabold text-emerald-700">—</div>
          <div class="mt-1 text-[11px] text-foreground/65">سيحضر</div>
        </div>
        <div class="rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
          <div id="quotaPending" class="text-lg font-extrabold text-foreground/70">—</div>
          <div class="mt-1 text-[11px] text-foreground/65">لم يرد</div>
        </div>
        <div class="rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
          <div id="quotaDeclined" class="text-lg font-extrabold text-destructive-text">—</div>
          <div class="mt-1 text-[11px] text-foreground/65">اعتذر</div>
        </div>
        <div class="rounded-2xl bg-secondary/60 p-3 ring-1 ring-border">
          <div id="quotaCancelled" class="text-lg font-extrabold text-foreground/60">—</div>
          <div class="mt-1 text-[11px] text-foreground/65">دعوات ملغاة</div>
        </div>
      </div>
    </section>

    <!-- ══ إضافة ضيف ══════════════════════════════════════════════ -->
    <section class="rounded-2xl border border-border bg-white p-4 mb-5" aria-labelledby="addGuestHeading">
      <h2 id="addGuestHeading" class="text-sm font-extrabold text-foreground mb-3">إضافة ضيف</h2>
      <form id="addGuestForm" class="grid gap-2.5 sm:grid-cols-3">
        <div>
          <label for="guestName" class="text-xs font-semibold text-foreground/70">الاسم</label>
          <input id="guestName" name="name" type="text" required placeholder="اسم الضيف"
                 class="mt-1 h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
        </div>
        <div>
          <label for="guestPhone" class="text-xs font-semibold text-foreground/70">الهاتف</label>
          <input id="guestPhone" name="phone" type="text" required placeholder="05xxxxxxxx" dir="ltr"
                 class="mt-1 h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
        </div>
        <div>
          <label for="guestNote" class="text-xs font-semibold text-foreground/70">ملاحظة (اختياري)</label>
          <input id="guestNote" name="note" type="text" placeholder="ملاحظة"
                 class="mt-1 h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
        </div>
        <div class="sm:col-span-3">
          <button id="addGuestSubmit" type="submit" class="h-11 rounded-xl bg-primary px-5 text-sm font-bold text-white">إضافة الضيف</button>
        </div>
      </form>
      <div id="addGuestMsg" class="hidden mt-2.5 text-xs font-semibold rounded-xl px-3 py-2" role="alert"></div>
    </section>

    <!-- ══ قائمة ضيوفي ══════════════════════════════════════════════ -->
    <section class="rounded-2xl border border-border bg-white overflow-hidden" aria-labelledby="myGuestsHeading">
      <div class="px-4 py-3 border-b border-border bg-secondary/20">
        <h2 id="myGuestsHeading" class="text-sm font-extrabold text-foreground">ضيوفي</h2>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm" id="myGuestsTable">
          <caption class="sr-only">قائمة الضيوف الذين دعوتهم</caption>
          <thead>
            <tr class="border-b border-border bg-secondary/30 text-right text-xs font-bold text-foreground/70">
              <th scope="col" class="px-4 py-3">الاسم</th>
              <th scope="col" class="px-4 py-3">الهاتف</th>
              <th scope="col" class="px-4 py-3">الحالة</th>
              <th scope="col" class="px-4 py-3">تسجيل الدخول</th>
            </tr>
          </thead>
          <tbody id="myGuestsTableBody"></tbody>
        </table>
      </div>
      <div id="myGuestsLoading" class="hidden px-4 py-10 text-center text-sm text-foreground/60">جارٍ التحميل...</div>
      <div id="myGuestsEmpty" class="hidden px-4 py-10 text-center text-sm text-foreground/60">لم تُضِف أي ضيوف بعد.</div>
      <div id="myGuestsError" class="hidden px-4 py-10 text-center text-sm text-destructive-text"></div>
    </section>

  </div>
</div>
<script>
(function () {
  'use strict';

  var root = document.getElementById('pgeMyInvitesRoot');
  if (!root) return;

  var CONFIG = {
    ajaxUrl: root.getAttribute('data-ajax-url'),
    nonce: root.getAttribute('data-nonce'),
    eventId: root.getAttribute('data-event-id'),
  };

  var toastEl = document.getElementById('toast');
  var loadingEl = document.getElementById('myInvitesLoading');
  var errorEl = document.getElementById('myInvitesError');
  var contentEl = document.getElementById('myInvitesContent');

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

  // ── ملخص الحصة (بوابة العرض الفعلية: نجاح هذا الاستدعاء = صلاحية حقيقية) ──
  function fetchQuota() {
    postAjax('pge_additional_inviter_get_my_quota', {}).then(function (json) {
      loadingEl.classList.add('hidden');

      if (!json || !json.success) {
        errorEl.classList.remove('hidden');
        errorEl.textContent = (json && json.data && json.data.message) || 'ليس لديك دعوة نشطة لهذه المناسبة';
        return;
      }

      contentEl.classList.remove('hidden');
      var d = json.data;
      document.getElementById('quotaAllocated').textContent = d.allocated;
      document.getElementById('quotaOccupied').textContent = d.occupied;
      document.getElementById('quotaAvailable').textContent = d.available;
      document.getElementById('quotaAttending').textContent = d.attending;
      document.getElementById('quotaPending').textContent = d.pending;
      document.getElementById('quotaDeclined').textContent = d.declined;
      document.getElementById('quotaCancelled').textContent = d.cancelled;

      fetchMyGuests();
    }).catch(function () {
      loadingEl.classList.add('hidden');
      errorEl.classList.remove('hidden');
      errorEl.textContent = 'تعذّر الاتصال بالخادم';
    });
  }

  // ── قائمة الضيوف المُسنَدة (نطاق القراءة بالكامل من مسؤولية Backend) ────
  var guestsBody = document.getElementById('myGuestsTableBody');
  var guestsLoading = document.getElementById('myGuestsLoading');
  var guestsEmpty = document.getElementById('myGuestsEmpty');
  var guestsError = document.getElementById('myGuestsError');

  function fetchMyGuests() {
    guestsLoading.classList.remove('hidden');
    guestsEmpty.classList.add('hidden');
    guestsError.classList.add('hidden');
    guestsBody.innerHTML = '';

    postAjax('pge_event_access_list_guests', { page: 1, per_page: 100 }).then(function (json) {
      guestsLoading.classList.add('hidden');
      if (!json || !json.success) {
        guestsError.classList.remove('hidden');
        guestsError.textContent = (json && json.data && json.data.message) || 'تعذّر تحميل قائمة الضيوف';
        return;
      }
      var items = (json.data && json.data.items) || [];
      if (items.length === 0) {
        guestsEmpty.classList.remove('hidden');
        return;
      }
      items.forEach(function (item) {
        var guest = item.guest || {};
        var tr = document.createElement('tr');
        tr.className = 'border-b border-border/60 last:border-0';
        tr.innerHTML =
          '<td class="px-4 py-3 font-semibold text-foreground">' + (escapeHtml(guest.name) || '<span class="text-foreground/50 font-normal">بدون اسم</span>') + '</td>' +
          '<td class="px-4 py-3 text-foreground/75 font-mono" dir="ltr">' + escapeHtml(guest.phone) + '</td>' +
          '<td class="px-4 py-3"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 bg-secondary/60 ring-border text-foreground/70">' + escapeHtml(guest.status_label || '') + '</span></td>' +
          '<td class="px-4 py-3 text-foreground/70">' + (guest.checked ? '✔️' : '—') + '</td>';
        guestsBody.appendChild(tr);
      });
    }).catch(function () {
      guestsLoading.classList.add('hidden');
      guestsError.classList.remove('hidden');
      guestsError.textContent = 'تعذّر الاتصال بالخادم';
    });
  }

  // ── إضافة ضيف (لا group_id يُرسَل — الخادم يحلّه من العضوية الوحيدة) ────
  var addForm = document.getElementById('addGuestForm');
  var addSubmitBtn = document.getElementById('addGuestSubmit');
  var addMsgEl = document.getElementById('addGuestMsg');
  var addInFlight = false;

  addForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (addInFlight) return;
    addInFlight = true;
    addSubmitBtn.disabled = true;

    var payload = {
      name: document.getElementById('guestName').value,
      phone: document.getElementById('guestPhone').value,
      note: document.getElementById('guestNote').value,
    };

    postAjax('pge_additional_inviter_create_guest', payload).then(function (json) {
      addInFlight = false;
      addSubmitBtn.disabled = false;

      addMsgEl.classList.remove('hidden');
      if (json && json.success) {
        addMsgEl.textContent = 'تمت إضافة الضيف بنجاح';
        addMsgEl.style.background = '#dcfce7';
        addMsgEl.style.color = '#166534';
        addForm.reset();
        // لا حساب quota محلياً — إعادة جلب من الخادم دائماً.
        fetchQuota();
      } else {
        addMsgEl.textContent = (json && json.data && json.data.message) || 'تعذّر إضافة الضيف';
        addMsgEl.style.background = '#fee2e2';
        addMsgEl.style.color = '#991b1b';
      }
    }).catch(function () {
      addInFlight = false;
      addSubmitBtn.disabled = false;
      addMsgEl.classList.remove('hidden');
      addMsgEl.textContent = 'تعذّر الاتصال بالخادم';
      addMsgEl.style.background = '#fee2e2';
      addMsgEl.style.color = '#991b1b';
    });
  });

  // ── التحميل الأولي ────────────────────────────────────────────────────
  fetchQuota();
})();
</script>
<?php get_footer(); ?>
