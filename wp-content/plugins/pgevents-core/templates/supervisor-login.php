<?php
/**
 * ============================================================================
 * Supervisor Login (Self-Service Request) — Supervisor Login Architecture
 * (Post-Activation Login) RFC، تنفيذ
 * ============================================================================
 * monasbat.test/supervisor/login/ — صفحة طلب رابط دخول ذاتية لمشرف مُفعَّل
 * (active) بالفعل يريد الدخول من جديد بعد تسجيل الخروج/انتهاء الجلسة، بلا أي
 * رابط دعوة قديم بحوزته. لا event_id في الرابط، لا معرِّف داخلي — رقم الجوال
 * فقط.
 *
 * "If supervisor already has session: redirect to /supervisor/checkin/.
 * Otherwise: show mobile number field." — يُنفَّذ هنا حرفياً عبر
 * PGE_Supervisor_Portal_Middleware::authorize() القائمة أصلاً (نفس حارس
 * /supervisor/ /supervisor/dashboard/ /supervisor/checkin/ تماماً، بلا أي
 * منطق تفويض مستقل جديد) — إن كانت هناك جلسة صالحة بالفعل، لا داعي أصلاً
 * لعرض نموذج الطلب.
 *
 * قالب مستقل بالكامل (DOCTYPE خاص به، بلا get_header()/get_footer() من
 * الثيم) — نفس فلسفة supervisor-portal.php/access-gate.php حرفياً.
 *
 * الطلب نفسه (AJAX) يُرسَل إلى pge_supervisor_login_request (nopriv — لا
 * حساب WordPress هنا إطلاقاً)، المُعرَّفة في includes/supervisor-login-ajax.php.
 * الاستجابة **موحَّدة دائماً** بصرف النظر عن وجود تطابق فعلي (منع Phone
 * Enumeration) — راجع توثيق تلك الدالة.
 */
if (!defined('ABSPATH')) exit;

if (class_exists('PGE_Supervisor_Portal_Middleware')) {
    $authorization = PGE_Supervisor_Portal_Middleware::authorize();
    if (($authorization['result'] ?? '') === 'authorized') {
        wp_safe_redirect(home_url('/supervisor/checkin/'));
        exit;
    }
}

$request_nonce = wp_create_nonce('pge_supervisor_login_request');
$ajax_url = admin_url('admin-ajax.php');

status_header(200);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل دخول المشرف — بوابة المشرف</title>
    <?php wp_head(); ?>
</head>
<body>
<div class="relative min-h-screen bg-background font-arabic" dir="rtl">
    <main class="mx-auto flex min-h-screen max-w-lg flex-col items-center justify-center px-4 py-12">
        <section class="w-full rounded-[28px] border border-border bg-white p-7 shadow-[0_20px_60px_-15px_rgba(45,25,20,0.10)]" id="pgeSupLoginRoot"
                 data-ajax-url="<?php echo esc_url($ajax_url); ?>"
                 data-nonce="<?php echo esc_attr($request_nonce); ?>">

            <div class="text-center">
                <span class="mx-auto mb-4 inline-flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="h-8 w-8">
                        <path d="M20 21a8 8 0 0 0-16 0"></path>
                        <circle cx="12" cy="8" r="5"></circle>
                    </svg>
                </span>
                <h1 class="text-xl font-extrabold text-foreground">تسجيل دخول المشرف</h1>
                <p class="mt-2 text-sm leading-relaxed text-foreground/65">أدخل رقم جوالك المسجَّل كمشرف دخول لتصلك رسالة تحتوي رابط الدخول.</p>
            </div>

            <form id="pgeSupLoginForm" class="mt-6 space-y-3">
                <div>
                    <label for="pgeSupLoginPhone" class="sr-only">رقم الجوال</label>
                    <input id="pgeSupLoginPhone" name="phone" type="tel" inputmode="tel" required
                           placeholder="رقم الجوال المسجَّل كمشرف"
                           class="h-12 w-full rounded-xl border border-border px-4 text-sm outline-none focus:border-primary" dir="ltr" />
                </div>
                <button id="pgeSupLoginSubmit" type="submit" class="h-12 w-full rounded-xl bg-primary text-sm font-bold text-white">
                    طلب رابط الدخول
                </button>
            </form>

            <div id="pgeSupLoginMsg" class="hidden mt-4 text-sm font-semibold rounded-xl px-4 py-3 text-center" role="status" aria-live="polite"></div>
        </section>
    </main>
</div>
<?php wp_footer(); ?>
<script>
(function () {
  'use strict';

  var root = document.getElementById('pgeSupLoginRoot');
  if (!root) return;

  var ajaxUrl = root.getAttribute('data-ajax-url');
  var nonce = root.getAttribute('data-nonce');

  var form = document.getElementById('pgeSupLoginForm');
  var phoneInput = document.getElementById('pgeSupLoginPhone');
  var submitBtn = document.getElementById('pgeSupLoginSubmit');
  var msgEl = document.getElementById('pgeSupLoginMsg');
  var inFlight = false;

  function showMsg(text, isError) {
    msgEl.classList.remove('hidden');
    msgEl.textContent = text;
    msgEl.style.background = isError ? '#fee2e2' : '#dcfce7';
    msgEl.style.color = isError ? '#991b1b' : '#166534';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (inFlight) return;
    inFlight = true;
    submitBtn.disabled = true;

    var body = new URLSearchParams();
    body.set('action', 'pge_supervisor_login_request');
    body.set('nonce', nonce);
    body.set('phone', phoneInput.value);

    fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(function (res) { return res.json(); }).then(function (json) {
      inFlight = false;
      submitBtn.disabled = false;
      // نفس الرسالة تُعرَض دائماً سواء نجح الطلب أو فشل بسبب معدَّل الطلبات
      // أو أي سبب آخر — الاستجابة نفسها من الخادم موحَّدة (منع تعداد الأرقام).
      if (json && json.success) {
        showMsg((json.data && json.data.message) || 'إذا كان هذا الرقم مسجَّلاً كمشرف نشط، ستصلك رسالة واتساب تحتوي رابط الدخول خلال لحظات.', false);
        form.reset();
      } else {
        showMsg((json && json.data && json.data.message) || 'تعذّر إرسال الطلب، حاول مرة أخرى.', true);
      }
    }).catch(function () {
      inFlight = false;
      submitBtn.disabled = false;
      showMsg('تعذّر الاتصال بالخادم', true);
    });
  });
})();
</script>
</body>
</html>
<?php
exit;
