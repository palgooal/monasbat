<?php
/**
 * ============================================================================
 * H1C-W10 — صفحة قبول دعوة "داعٍ إضافي" (Additional Inviter Onboarding)
 * ============================================================================
 * تُعرَض حصراً على GET /additional-inviter/join/{token}/ عندما يكون التوكن
 * صالحاً فعلياً (PGE_Additional_Inviter_Onboarding::preview_onboarding_
 * token() === صفيف صالح، لا WP_Error). **لا استهلاك، لا إنشاء حساب، لا
 * عضوية تُنشأ في هذه الصفحة أو قبلها على GET** — كل ما حدث قبل هذا الملف هو
 * قراءة بحتة (preview_onboarding_token()، الذي بدوره لا يُنفِّذ أي UPDATE).
 *
 * نفس فلسفة "Link Preview Safety" المعتمَدة في templates/supervisor-login-
 * confirm.php: معاينات الروابط الآلية (WhatsApp، فاحصات أمنية بريدية،
 * Prefetch) قد تصل لهذا الرابط بـGET قبل أن يفتحه المدعو فعلياً — هذه الصفحة
 * لا تُنفِّذ أي أثر جانبي عند العرض، فقط عند ضغط المستخدم الحقيقي على الزر
 * (طلب POST حقيقي عبر JavaScript إلى admin-ajax.php، لا نموذج HTML عادي
 * يُرسِل GET بالخطأ).
 *
 * الإتمام الفعلي (سواء لحساب موجود أو حساب جديد) لا يحدث على مسار الصفحة
 * هذا إطلاقاً — يحدث حصراً عبر الإجراء العام الوحيد
 * `pge_additional_inviter_onboarding_complete` (مسجَّل wp_ajax_nopriv_، راجع
 * includes/additional-inviter-onboarding-ajax.php)، الذي يتحقق بدوره من
 * التوكن الخام من جديد (لا وثوق بما عرضته هذه الصفحة سابقاً) قبل أي تنفيذ.
 *
 * متغيّرات مطلوبة من المستدعي (includes/routing.php) قبل require هذا الملف:
 *   $pge_ai_join_token           التوكن الخام كما ورد في الرابط
 *   $pge_ai_join_preview         صفيف preview_onboarding_token() الناجح
 *                                 (valid/has_existing_account/
 *                                 invitee_email_masked/event_title)
 *   $pge_ai_join_complete_nonce  قيمة nonce جاهزة (wp_create_nonce())
 *   $pge_ai_join_ajax_url        admin_url('admin-ajax.php')
 *
 * لا يُقرأ/يُعرَض في هذه الصفحة إطلاقاً: البريد الإلكتروني الكامل غير
 * المُقنَّع، user_id، invitation_id، event_id، group_id، أو أي معرِّف داخلي
 * آخر — فقط: البريد المُقنَّع (مثال: ah***@example.com)، عنوان المناسبة،
 * وحقلا كلمة المرور/الاسم عند إنشاء حساب جديد.
 */
if (!defined('ABSPATH')) exit;

$raw_token = isset($pge_ai_join_token) ? (string) $pge_ai_join_token : '';
$preview = isset($pge_ai_join_preview) && is_array($pge_ai_join_preview) ? $pge_ai_join_preview : [];
$complete_nonce = isset($pge_ai_join_complete_nonce) ? (string) $pge_ai_join_complete_nonce : '';
$ajax_url = isset($pge_ai_join_ajax_url) ? (string) $pge_ai_join_ajax_url : admin_url('admin-ajax.php');

$has_existing_account = !empty($preview['has_existing_account']);
$masked_email = isset($preview['invitee_email_masked']) ? (string) $preview['invitee_email_masked'] : '';
$event_title = isset($preview['event_title']) ? (string) $preview['event_title'] : '';

status_header(200);
nocache_headers();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>قبول دعوة الانضمام</title>
    <?php wp_head(); ?>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 16px; box-sizing: border-box; }
        .pge-ai-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.08); padding: 32px; max-width: 420px; width: 100%; }
        .pge-ai-card h1 { font-size: 20px; margin: 0 0 8px; text-align: center; }
        .pge-ai-card p.pge-ai-sub { color: #555; line-height: 1.7; text-align: center; margin: 0 0 24px; }
        .pge-ai-field { margin-bottom: 16px; }
        .pge-ai-field label { display: block; font-size: 13px; color: #333; margin-bottom: 6px; }
        .pge-ai-field input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .pge-ai-btn { width: 100%; padding: 12px; border: none; border-radius: 8px; background: #2563eb; color: #fff; font-size: 15px; font-weight: bold; cursor: pointer; }
        .pge-ai-btn:disabled { opacity: .6; cursor: default; }
        .pge-ai-msg { margin-top: 16px; font-size: 14px; text-align: center; line-height: 1.7; }
        .pge-ai-msg.pge-ai-error { color: #b91c1c; }
        .pge-ai-msg.pge-ai-success { color: #15803d; }
        .pge-ai-hint { font-size: 12px; color: #888; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="pge-ai-card">
        <h1>دعوة انضمام كداعٍ إضافي</h1>
        <p class="pge-ai-sub">
            <?php if ($event_title !== '') : ?>
                تمت دعوتك للانضمام كداعٍ إضافي في مناسبة "<?php echo esc_html($event_title); ?>".
            <?php else : ?>
                تمت دعوتك للانضمام كداعٍ إضافي في إحدى المناسبات.
            <?php endif; ?>
            <?php if ($masked_email !== '') : ?>
                <br>البريد المرتبط بهذه الدعوة: <strong><?php echo esc_html($masked_email); ?></strong>
            <?php endif; ?>
        </p>

        <div id="pgeAiForm">
            <?php if ($has_existing_account) : ?>
                <p class="pge-ai-sub">لديك حساب مسجَّل بهذا البريد بالفعل. اضغط الزر أدناه لإتمام الانضمام مباشرة.</p>
            <?php else : ?>
                <div class="pge-ai-field">
                    <label for="pgeAiDisplayName">الاسم الذي سيظهر</label>
                    <input type="text" id="pgeAiDisplayName" maxlength="191">
                </div>
                <div class="pge-ai-field">
                    <label for="pgeAiPassword">كلمة مرور الحساب الجديد</label>
                    <input type="password" id="pgeAiPassword" minlength="8" autocomplete="new-password">
                    <div class="pge-ai-hint">8 أحرف على الأقل.</div>
                </div>
            <?php endif; ?>
            <button type="button" class="pge-ai-btn" id="pgeAiSubmitBtn">إتمام الانضمام</button>
        </div>

        <div id="pgeAiMsg" class="pge-ai-msg" style="display:none;"></div>
    </div>

    <script>
    (function () {
        var hasExisting = <?php echo $has_existing_account ? 'true' : 'false'; ?>;
        var token = <?php echo wp_json_encode($raw_token); ?>;
        var nonce = <?php echo wp_json_encode($complete_nonce); ?>;
        var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;

        var btn = document.getElementById('pgeAiSubmitBtn');
        var msg = document.getElementById('pgeAiMsg');
        var formWrap = document.getElementById('pgeAiForm');

        function showMsg(text, isError) {
            msg.textContent = text;
            msg.className = 'pge-ai-msg ' + (isError ? 'pge-ai-error' : 'pge-ai-success');
            msg.style.display = 'block';
        }

        btn.addEventListener('click', function () {
            var params = new URLSearchParams();
            params.set('action', 'pge_additional_inviter_onboarding_complete');
            params.set('token', token);
            params.set('nonce', nonce);

            if (hasExisting) {
                params.set('mode', 'existing');
            } else {
                var displayName = document.getElementById('pgeAiDisplayName').value || '';
                var password = document.getElementById('pgeAiPassword').value || '';
                if (password.length < 8) {
                    showMsg('كلمة المرور يجب ألا تقل عن 8 أحرف.', true);
                    return;
                }
                params.set('mode', 'new');
                params.set('display_name', displayName);
                params.set('password', password);
            }

            btn.disabled = true;
            btn.textContent = 'جارِ التنفيذ...';

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        formWrap.style.display = 'none';
                        showMsg('تم الانضمام بنجاح.', false);
                    } else {
                        var reason = data && data.data && data.data.message ? data.data.message : 'تعذّر إتمام الانضمام حالياً.';
                        showMsg(reason, true);
                        btn.disabled = false;
                        btn.textContent = 'إتمام الانضمام';
                    }
                })
                .catch(function () {
                    showMsg('تعذّر الاتصال بالخادم، تحقق من اتصالك وحاول مرة أخرى.', true);
                    btn.disabled = false;
                    btn.textContent = 'إتمام الانضمام';
                });
        });
    })();
    </script>

    <?php wp_footer(); ?>
</body>
</html>
<?php
exit;
