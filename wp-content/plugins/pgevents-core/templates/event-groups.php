<?php
/**
 * ============================================================================
 * Event Group Management UI — H1C-UI-1 (Group Management UI Integration)
 * ============================================================================
 * أول تكامل UI حقيقي مع H1C Event Access. هذا القالب عرض فقط: يبني الواجهة
 * ويستدعي includes/event-access-ajax.php حصراً (pge_event_access_list_groups /
 * pge_event_access_create_group / pge_event_access_list_guests) — لا $wpdb هنا،
 * لا استدعاء مباشر لـPGE_Event_Access_Repository أو PGE_Event_Access_Authorization،
 * ولا أي قرار تفويض أمني موازٍ في JavaScript. Backend (Application Service +
 * Authorization Core) يبقى المرجع الوحيد لكل قرار صلاحية.
 *
 * نطاق هذه المرحلة فقط (H1C-UI-1): عرض/إنشاء مجموعات + عرض ضيوف مجموعة واحدة
 * عبر السطح المقروء الموجود أصلاً. لا rename/archive/set_default، لا
 * Membership/Manager/Viewer، لا grant/revoke group access، لا دعوة متعاونين،
 * لا Check-in، لا إدارة Supervisor، لا Audit UI — حتى لو كانت الـAPIs موجودة.
 *
 * نفس نمط قالب templates/event-supervisors.php حرفياً في nonce الإدارة
 * (pge_event_manage_nonce) و get_header()/get_footer() من الثيم — لكن بوابة
 * العرض (Presentation Gate) هنا مختلفة عمداً عن pge_event_guests_user_can_manage()
 * (Fix Pass 1 — Issue A): تلك الدالة تسمح أيضاً بمجرد current_user_can('edit_post',
 * $event_id) وحدها، وهو أوسع من نطاق H1C Owner/Admin الفعلي. مُتحقَّق من المصدر
 * مباشرة (class-pge-event-access-authorization.php::resolve()): H1C Owner/Admin =
 * WP Administrator (user_can(..., 'administrator')) أو post_author === الفاعل
 * — بلا أي فحص edit_post إطلاقاً. الشرط أدناه هو أصغر presentation check ممكن
 * يطابق هذا التعريف حرفياً (نفس النمط الاحتياطي الموجود أصلاً في
 * page-event-manage.php لنفس الغرض)، لمنع فتح شاشة لن تستطيع طلباتها النجاح
 * لاحقاً — بلا أي تكرار لمنطق Authorization Core وبلا تغيير في السلطة
 * الحقيقية، التي تبقى حصراً عند PGE_Event_Access_Application_Service +
 * Authorization Core على كل طلب AJAX كما كانت.
 *
 * ملاحظة عن عدد ضيوف المجموعة: السطح العام الحالي pge_event_access_list_guests
 * لا يوفر فلترة بمعرف مجموعة على مستوى الخادم (لا معامل group_id في عقده
 * الحالي) ولا يوجد سطح عام لعدد الضيوف لكل مجموعة (count_group_assignments غير
 * موصول بـAJAX). لذلك: قائمة المجموعات لا تعرض عدداً تقديرياً للضيوف (تفادياً
 * لعرض رقم غير حقيقي)، بينما تفاصيل المجموعة عند فتحها تحسب العدد الحقيقي من
 * نفس بيانات الضيوف المُرجَعة فعلياً من pge_event_access_list_guests (نفس
 * البيانات التي يراها Owner/Admin أصلاً بالكامل عبر هذا السطح)، مُصفّاة على
 * جهاز المستخدم حسب group_id — لا بيانات إضافية تُكشَف، ولا endpoint جديد.
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

// H1C-UI-1 Fix Pass 1 (Issue A): إدارة المجموعات H1C — نطاق Owner/Admin فقط،
// مطابق حرفياً لتعريف H1C Authorization Core (لا edit_post وحدها). راجع
// الشرح الكامل أعلى الملف.
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
<div dir="rtl" class="max-w-5xl mx-auto px-4 py-6" id="pgeGroupsRoot"
     data-event-id="<?php echo esc_attr((string) $event_id); ?>"
     data-nonce="<?php echo esc_attr($mgmt_nonce); ?>"
     data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">

  <div id="toast" role="status" aria-live="polite"
       style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99;min-width:200px;max-width:88vw;padding:11px 20px;border-radius:99px;font-size:13px;font-weight:700;text-align:center;pointer-events:none;opacity:0;transition:opacity .25s;"></div>

  <!-- ══ رأس الصفحة ══════════════════════════════════════════════ -->
  <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
    <div>
      <a href="<?php echo esc_url($manage_url); ?>" class="text-sm text-foreground/65 hover:underline">&larr; رجوع لإدارة المناسبة</a>
      <h1 class="mt-1 text-xl font-extrabold text-foreground">مجموعات الضيوف — <?php echo esc_html($event_title); ?></h1>
    </div>
  </div>
  <p class="text-sm text-foreground/60 mb-5">المجموعات تساعدك على تنظيم قائمة المدعوين — مثلاً حسب العائلة أو جهة الدعوة — لتسهيل متابعتهم لاحقاً.</p>

  <!-- ══ نموذج إضافة مجموعة جديدة ═══════════════════════════════════ -->
  <section class="rounded-2xl border border-border bg-white p-4 mb-5" aria-labelledby="createGroupHeading">
    <h2 id="createGroupHeading" class="text-sm font-extrabold text-foreground mb-3">إضافة مجموعة جديدة</h2>
    <form id="createGroupForm" class="flex flex-wrap gap-2.5 items-start">
      <div class="flex-1 min-w-[200px]">
        <label for="newGroupName" class="sr-only">اسم المجموعة</label>
        <input id="newGroupName" name="name" type="text" required placeholder="اسم المجموعة (مثال: عائلة العريس)"
               class="h-11 w-full rounded-xl border border-border px-3 text-sm outline-none focus:border-primary" />
      </div>
      <button id="createGroupSubmit" type="submit" class="h-11 rounded-xl bg-primary px-5 text-sm font-bold text-white">إضافة مجموعة</button>
    </form>
    <div id="createGroupMsg" class="hidden mt-2.5 text-xs font-semibold rounded-xl px-3 py-2" role="alert"></div>
  </section>

  <!-- ══ قائمة المجموعات ══════════════════════════════════════════════ -->
  <div class="rounded-2xl border border-border bg-white overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm" id="groupsTable">
        <caption class="sr-only">قائمة مجموعات الضيوف لهذه المناسبة</caption>
        <thead>
          <tr class="border-b border-border bg-secondary/30 text-right text-xs font-bold text-foreground/70">
            <th scope="col" class="px-4 py-3">اسم المجموعة</th>
            <th scope="col" class="px-4 py-3">الحالة</th>
            <th scope="col" class="px-4 py-3">افتراضية؟</th>
            <th scope="col" class="px-4 py-3">إجراءات</th>
          </tr>
        </thead>
        <tbody id="groupsTableBody">
          <!-- تُملأ عبر renderGroupRows() أدناه. -->
        </tbody>
      </table>
    </div>
    <div id="groupsLoadingState" class="hidden px-4 py-10 text-center text-sm text-foreground/60">جارٍ التحميل...</div>
    <div id="groupsEmptyState" class="hidden px-4 py-10 text-center text-sm text-foreground/60">لا توجد مجموعات بعد لهذه المناسبة.</div>
  </div>

  <!-- ══ ترقيم الصفحات ════════════════════════════════════════════ -->
  <div id="groupsPagination" class="flex items-center justify-center gap-2 mt-4" role="navigation" aria-label="ترقيم صفحات المجموعات"></div>

  <!-- ══ تفاصيل المجموعة (بطاقة مضمَّنة، تظهر عند اختيار مجموعة) ═══════════ -->
  <div id="groupDetailCard" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="groupDetailHeading">
    <div class="w-full max-w-lg max-h-[85vh] flex flex-col rounded-2xl bg-white p-5">
      <div class="flex items-center justify-between gap-3 mb-3">
        <h2 id="groupDetailHeading" class="text-sm font-extrabold text-foreground">تفاصيل المجموعة</h2>
        <button type="button" id="closeGroupDetailBtn" class="h-9 px-3 rounded-lg border border-border text-xs font-semibold text-foreground/70">إغلاق</button>
      </div>
      <div id="groupDetailBody" class="flex-1 overflow-y-auto">
        <div id="groupDetailLoading" class="px-2 py-8 text-center text-sm text-foreground/60">جارٍ تحميل ضيوف المجموعة...</div>
        <div id="groupDetailError" class="hidden px-2 py-8 text-center text-sm text-destructive-text"></div>
        <div id="groupDetailEmpty" class="hidden px-2 py-8 text-center text-sm text-foreground/60">لا يوجد ضيوف في هذه المجموعة حتى الآن.</div>
        <div id="groupDetailNote" class="hidden mb-3 text-xs font-semibold rounded-xl px-3 py-2 bg-secondary/50 text-foreground/70"></div>
        <ul id="groupDetailGuestList" class="hidden divide-y divide-border/60"></ul>
      </div>
    </div>
  </div>

</div>
<script>
(function () {
  'use strict';

  var root = document.getElementById('pgeGroupsRoot');
  if (!root) return;

  var CONFIG = {
    ajaxUrl: root.getAttribute('data-ajax-url'),
    nonce: root.getAttribute('data-nonce'),
    eventId: root.getAttribute('data-event-id'),
  };

  // السقف الأمني لعدد الصفحات التي تُجلَب عند حساب ضيوف مجموعة واحدة (Group
  // Detail) — الحماية الوحيدة ضد مناسبة بعدد ضيوف استثنائي جداً؛ لا تأثير له
  // في الاستخدام الطبيعي (100 ضيف لكل صفحة × 20 صفحة = 2000 ضيف كحد أقصى قبل
  // إظهار ملاحظة صريحة بعدم اكتمال العرض، بدل تجميد الصفحة بحلقة غير محدودة).
  var GROUP_DETAIL_MAX_PAGES = 20;
  var GROUP_DETAIL_PER_PAGE = 100;

  var state = { page: 1, perPage: 20 };

  var toastEl = document.getElementById('toast');
  var tableBody = document.getElementById('groupsTableBody');
  var loadingEl = document.getElementById('groupsLoadingState');
  var emptyEl = document.getElementById('groupsEmptyState');
  var paginationEl = document.getElementById('groupsPagination');

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

  // ── قائمة المجموعات ──────────────────────────────────────────────────
  function renderGroupRows(items) {
    tableBody.innerHTML = '';

    items.forEach(function (group) {
      var tr = document.createElement('tr');
      tr.className = 'border-b border-border/60 last:border-0';
      tr.setAttribute('data-id', group.id);

      var statusLabel = group.status === 'archived' ? 'مؤرشفة' : 'نشطة';
      var isDefault = !!group.is_default;

      tr.innerHTML =
        '<td class="px-4 py-3 font-semibold text-foreground">' + escapeHtml(group.name) + '</td>' +
        '<td class="px-4 py-3"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 bg-secondary/60 ring-border text-foreground/70">' + escapeHtml(statusLabel) + '</span></td>' +
        '<td class="px-4 py-3 text-xs text-foreground/65">' + (isDefault ? '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 bg-primary/10 ring-primary/30 text-primary">افتراضية</span>' : '—') + '</td>' +
        '<td class="px-4 py-3">' +
          '<button type="button" class="open-group-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">فتح المجموعة</button>' +
        '</td>';

      tr.querySelector('.open-group-btn').addEventListener('click', function () { openGroupDetail(group); });

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
      btn.className = 'h-9 min-w-[36px] rounded-lg text-xs font-bold ' +
        (p === page ? 'bg-primary text-white' : 'border border-border text-foreground/70');
      (function (targetPage) {
        btn.addEventListener('click', function () { fetchGroups(targetPage); });
      })(p);
      paginationEl.appendChild(btn);
    }
  }

  function fetchGroups(page) {
    state.page = page;

    loadingEl.classList.remove('hidden');
    emptyEl.classList.add('hidden');
    tableBody.innerHTML = '';

    postAjax('pge_event_access_list_groups', { page: page, per_page: state.perPage }).then(function (json) {
      loadingEl.classList.add('hidden');

      if (!json || !json.success) {
        showToast((json && json.data && json.data.message) || 'تعذّر تحميل المجموعات', true);
        emptyEl.classList.remove('hidden');
        return;
      }

      var data = json.data;
      renderGroupRows(data.items || []);
      renderPagination(data.page || 1, data.total_pages || 0);
    }).catch(function () {
      loadingEl.classList.add('hidden');
      showToast('تعذّر الاتصال بالخادم', true);
    });
  }

  // ── إنشاء مجموعة جديدة ───────────────────────────────────────────────
  var createForm = document.getElementById('createGroupForm');
  var createSubmitBtn = document.getElementById('createGroupSubmit');
  var createMsgEl = document.getElementById('createGroupMsg');
  var createInFlight = false;

  createForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (createInFlight) return; // منع إرسال مكرَّر أثناء طلب قائم بالفعل
    createInFlight = true;
    createSubmitBtn.disabled = true;

    var name = document.getElementById('newGroupName').value;

    postAjax('pge_event_access_create_group', { name: name }).then(function (json) {
      createInFlight = false;
      createSubmitBtn.disabled = false;

      createMsgEl.classList.remove('hidden');
      if (json && json.success) {
        createMsgEl.textContent = 'تمت إضافة المجموعة';
        createMsgEl.style.background = '#dcfce7';
        createMsgEl.style.color = '#166534';
        createForm.reset();
        fetchGroups(1);
      } else {
        createMsgEl.textContent = (json && json.data && json.data.message) || 'تعذّر إضافة المجموعة';
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

  // ── تفاصيل المجموعة: ضيوف هذه المجموعة فقط ──────────────────────────────
  // pge_event_access_list_guests لا يوفر فلترة بمعرف مجموعة على مستوى
  // الخادم (لا معامل group_id في عقده الحالي)، لذا تُجلَب صفحات ضيوف
  // المناسبة كاملة (نفس البيانات التي يراها Owner/Admin أصلاً بلا فلترة عبر
  // هذا السطح تحديداً) وتُصفَّى هنا حسب group_id — لا بيانات إضافية تُكشَف
  // ولا endpoint جديد. السقف GROUP_DETAIL_MAX_PAGES حماية فقط ضد عدد ضيوف
  // استثنائي، لا يؤثر في الاستخدام الطبيعي.
  var detailCard = document.getElementById('groupDetailCard');
  var detailHeading = document.getElementById('groupDetailHeading');
  var detailLoading = document.getElementById('groupDetailLoading');
  var detailError = document.getElementById('groupDetailError');
  var detailEmpty = document.getElementById('groupDetailEmpty');
  var detailNote = document.getElementById('groupDetailNote');
  var detailGuestList = document.getElementById('groupDetailGuestList');
  var detailRequestSeq = 0;

  function resetDetailPanel() {
    detailLoading.classList.remove('hidden');
    detailError.classList.add('hidden');
    detailEmpty.classList.add('hidden');
    detailNote.classList.add('hidden');
    detailGuestList.classList.add('hidden');
    detailGuestList.innerHTML = '';
  }

  function openGroupDetail(group) {
    detailHeading.textContent = 'ضيوف مجموعة: ' + group.name;
    resetDetailPanel();
    detailCard.classList.remove('hidden');

    var mySeq = ++detailRequestSeq;
    var matched = [];

    function fetchPage(page) {
      postAjax('pge_event_access_list_guests', { page: page, per_page: GROUP_DETAIL_PER_PAGE }).then(function (json) {
        if (mySeq !== detailRequestSeq) return; // أُغلقت النافذة أو فُتحت مجموعة أخرى أثناء الجلب

        if (!json || !json.success) {
          detailLoading.classList.add('hidden');
          detailError.classList.remove('hidden');
          detailError.textContent = (json && json.data && json.data.message) || 'تعذّر تحميل ضيوف المجموعة';
          return;
        }

        var data = json.data;
        (data.items || []).forEach(function (item) {
          if (item && item.group_id === group.id) matched.push(item);
        });

        var totalPages = data.total_pages || 0;
        var reachedCap = page >= GROUP_DETAIL_MAX_PAGES && page < totalPages;

        if (page < totalPages && page < GROUP_DETAIL_MAX_PAGES) {
          fetchPage(page + 1);
          return;
        }

        detailLoading.classList.add('hidden');
        if (reachedCap) {
          detailNote.classList.remove('hidden');
          detailNote.textContent = 'قد لا تظهر جميع ضيوف هذه المجموعة — عدد ضيوف المناسبة كبير جداً لعرضهم كاملين هنا.';
        }
        renderGroupGuests(matched);
      }).catch(function () {
        if (mySeq !== detailRequestSeq) return;
        detailLoading.classList.add('hidden');
        detailError.classList.remove('hidden');
        detailError.textContent = 'تعذّر الاتصال بالخادم';
      });
    }

    fetchPage(1);
  }

  function renderGroupGuests(items) {
    if (items.length === 0) {
      detailEmpty.classList.remove('hidden');
      return;
    }

    detailGuestList.classList.remove('hidden');
    items.forEach(function (item) {
      var guest = item.guest || {};
      var li = document.createElement('li');
      li.className = 'py-2.5 flex items-center justify-between gap-3';
      li.innerHTML =
        '<div class="min-w-0">' +
          '<div class="font-semibold text-foreground truncate">' + (escapeHtml(guest.name) || '<span class="text-foreground/50 font-normal">بدون اسم</span>') + '</div>' +
          '<div class="text-xs text-foreground/60 font-mono" dir="ltr">' + escapeHtml(guest.phone) + '</div>' +
        '</div>' +
        '<span class="shrink-0 inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 bg-secondary/60 ring-border text-foreground/70">' + escapeHtml(guest.status_label || '') + '</span>';
      detailGuestList.appendChild(li);
    });
  }

  document.getElementById('closeGroupDetailBtn').addEventListener('click', function () {
    detailRequestSeq++; // يُلغي أي جلب صفحات لا يزال قيد التنفيذ لهذه المجموعة
    detailCard.classList.add('hidden');
  });

  // ── التحميل الأولي ────────────────────────────────────────────────────
  fetchGroups(1);
})();
</script>
<?php get_footer(); ?>
