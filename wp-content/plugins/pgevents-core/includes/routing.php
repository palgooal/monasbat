<?php
if (!defined('ABSPATH')) exit;

/**
 * تسجيل المسارات البرمجية (Virtual Routes) للنظام
 */
add_action('init', function () {
    // ── رابط دعوة قصير: /e/{ID} → يُعيد التوجيه لصفحة المناسبة ──────────────
    add_rewrite_rule('^e/([0-9]+)/?$', 'index.php?pge_short_event=$matches[1]', 'top');

    // 1. مسار إنشاء مناسبة جديدة: monasbat.test/create-event/
    add_rewrite_rule('^create-event/?$', 'index.php?pge_action=create_event', 'top');

    // أضف هذه القاعدة داخل دالة init في ملف routing.php
    add_rewrite_rule('edit-event/([0-9]+)/?$', 'index.php?pge_action=edit_event&event_id=$matches[1]', 'top');
    add_rewrite_rule('event-manage/([0-9]+)/?$', 'index.php?pge_action=event_manage&event_id=$matches[1]', 'top');

    // 6. إدارة مشرفي الدخول للمضيف (Entry Check-in Supervisors — Phase 8):
    // monasbat.test/event-manage/{ID}/supervisors/ — معرِّف المناسبة في
    // الرابط عمداً (بخلاف مسارات /supervisor/* أعلاه): هذا مسار للمضيف
    // (حساب ووردبريس مسجَّل دخول)، لا لجلسة مشرف مستقلة، فالتفويض هنا حصراً
    // عبر pge_event_guests_user_can_manage($event_id) (نفس تفويض إدارة
    // المدعوين)، لا عبر PGE_Supervisor_Portal_Middleware.
    add_rewrite_rule('event-manage/([0-9]+)/supervisors/?$', 'index.php?pge_action=event_supervisors&event_id=$matches[1]', 'top');

    // 7. إدارة دعوات الضيوف للمضيف (Entry Check-in Supervisors — Phase 9):
    // monasbat.test/event-manage/{ID}/invitations/ — نفس فلسفة المسار أعلاه
    // تماماً (معرِّف مناسبة في الرابط، تفويض مضيف عادي لا جلسة مشرف).
    add_rewrite_rule('event-manage/([0-9]+)/invitations/?$', 'index.php?pge_action=event_invitations&event_id=$matches[1]', 'top');

    // 8. لوحة عمليات المناسبة الحيّة للمضيف (Entry Check-in Supervisors —
    // Phase 10 "Event Operations"): monasbat.test/event-manage/{ID}/operations/
    // — نفس فلسفة مسارَي supervisors/invitations أعلاه تماماً (معرِّف مناسبة
    // في الرابط، تفويض مضيف عادي عبر pge_event_guests_user_can_manage()، لا
    // جلسة مشرف مستقلة).
    add_rewrite_rule('event-manage/([0-9]+)/operations/?$', 'index.php?pge_action=event_operations&event_id=$matches[1]', 'top');

    // 2. مسار لوحة التحكم الرئيسية: monasbat.test/dashboard/
    add_rewrite_rule('^dashboard/?$', 'index.php?pge_action=dashboard', 'top');
    add_rewrite_rule('^login/?$', 'index.php?pge_action=login', 'top');
    add_rewrite_rule('^register/?$', 'index.php?pge_action=register', 'top');
    add_rewrite_rule('^forgot-password/?$', 'index.php?pge_action=forgot_password', 'top');

    // 3. بوابة المشرف (Entry Check-in Supervisors — Phase 3.5): monasbat.test/supervisor/
    // لا معرِّف مناسبة في الرابط عمداً — جلسة المشرف (Phase 3) تُعرِّف إسناداً
    // واحداً بذاتها، لا حاجة لـ$_GET['event_id'] (Requirement 2: لا تُقرأ أي
    // هوية من معاملات الطلب). راجع includes/class-pge-supervisor-portal-
    // middleware.php للتفويض الفعلي.
    add_rewrite_rule('^supervisor/logout/?$', 'index.php?pge_action=supervisor_logout', 'top');

    // 3ب. قبول دعوة المشرف عبر واتساب (Supervisor Invitation Delivery via
    // Cartat): monasbat.test/supervisor/accept/{token}/ — التوكن الخام
    // (64 حرف hex، bin2hex(random_bytes(32))) هو المُعرِّف الوحيد في الرابط،
    // لا معرِّف إسناد ولا معرِّف مناسبة (نفس فلسفة /supervisor/ أعلاه تماماً).
    // هذا المسار Route فقط — محوِّل HTTP رفيع لا أكثر، يستدعي
    // PGE_Supervisor_Authenticator::authenticate() الموجودة والمعتمدة فعلياً
    // (Phase 3) دون أي إعادة تنفيذ لمنطقها. راجع المعالج أسفل هذا الملف تحت
    // template_redirect لكامل التفصيل والتوثيق.
    add_rewrite_rule('^supervisor/accept/([^/]+)/?$', 'index.php?pge_action=supervisor_accept_invitation&pge_token=$matches[1]', 'top');

    // 3ج. مصادقة توكن الدخول (Supervisor Login Architecture — Post-Activation
    // Login RFC): monasbat.test/supervisor/login/{token}/ — مسار مستقل تماماً
    // عن /supervisor/accept/{token}/ أعلاه ("Do NOT reuse /supervisor/accept/
    // {token}. Acceptance remains invitation-only." حرفياً). التوكن الخام هو
    // المُعرِّف الوحيد في الرابط، بلا معرِّف إسناد/مناسبة. يجب تسجيل هذا النمط
    // (الذي يتطلّب مقطعاً إضافياً بعد /login/) — أي ترتيب نسبةً لنمط الصفحة
    // المجرَّدة أدناه آمن فعلياً لأن كليهما مُرسى بـ$ ولا يتداخلان (نفس مبدأ
    // /supervisor/accept/{token}/ مقابل /supervisor/ أعلاه).
    add_rewrite_rule('^supervisor/login/([^/]+)/?$', 'index.php?pge_action=supervisor_login_authenticate&pge_login_token=$matches[1]', 'top');

    // 3د. صفحة طلب رابط الدخول الذاتية (Supervisor Login Architecture):
    // monasbat.test/supervisor/login/ — بلا توكن في الرابط. المشرف يُدخِل رقم
    // جواله فقط؛ لا event_id ولا أي معرِّف داخلي هنا (نفس فلسفة /supervisor/
    // تماماً). راجع templates/supervisor-login.php لمنطق "إن كانت هناك جلسة
    // سارية بالفعل، أعِد التوجيه إلى /supervisor/checkin/ مباشرة" — هذا
    // المنطق يعيش في القالب نفسه (نفس اتفاقية supervisor-portal.php)، لا هنا.
    add_rewrite_rule('^supervisor/login/?$', 'index.php?pge_action=supervisor_login_request', 'top');

    add_rewrite_rule('^supervisor/?$', 'index.php?pge_action=supervisor_portal', 'top');

    // 4. لوحة إحصاءات الحضور للمشرف (Entry Check-in Supervisors — Phase 6):
    // monasbat.test/supervisor/dashboard/ — نفس فلسفة عدم وجود معرِّف مناسبة في
    // الرابط (جلسة المشرف تُعرِّف المناسبة بذاتها عبر PGE_Supervisor_Portal_
    // Middleware::authorize()، بلا أي وثوق بمعاملات الطلب).
    add_rewrite_rule('^supervisor/dashboard/?$', 'index.php?pge_action=supervisor_dashboard', 'top');

    // 5. واجهة تسجيل الحضور للمشرف (Entry Check-in Supervisors — Phase 7):
    // monasbat.test/supervisor/checkin/ — نفس فلسفة عدم وجود معرِّف مناسبة في
    // الرابط تماماً كسابقيه (جلسة المشرف تُعرِّف المناسبة بذاتها).
    add_rewrite_rule('^supervisor/checkin/?$', 'index.php?pge_action=supervisor_checkin', 'top');

});

/**
 * تسجيل المتغيرات لكي يفهمها محرك ووردبريس
 */
add_filter('query_vars', function ($vars) {
    $vars[] = 'pge_action';
    $vars[] = 'event_id';
    $vars[] = 'pge_short_event';
    $vars[] = 'pge_token';
    $vars[] = 'pge_login_token';
    return $vars;
});

// إعادة التوجيه للرابط القصير /e/{ID}
add_action('template_redirect', function () {
    $short_id = (int) get_query_var('pge_short_event');
    if ($short_id <= 0) return;

    $post = get_post($short_id);
    if (!$post || $post->post_type !== 'pge_event') {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }

    wp_redirect(get_permalink($short_id), 301);
    exit;
}, 1);

/**
 * ============================================================================
 * تسجيل خروج المشرف (Entry Check-in Supervisors — Phase 3.5، Requirement 4)
 * ============================================================================
 * "Implement logout endpoint. Destroy Supervisor Session. Redirect to
 * authentication entry point."
 *
 * إجراء صريح (nonce إلزامي) لا مسار ضمني — لا فرق بين "لا كوكي" و"كوكي غير
 * صالح أصلاً" في النتيجة (كلاهما "لا شيء لإبطاله فعلياً")، لكن كليهما يُعاد
 * توجيههما بأمان لنفس الوجهة بلا أي رسالة خطأ مربكة لمستخدم خرج بالفعل.
 *
 * ملاحظة صريحة (تُذكَر أيضاً في تقرير هذه المرحلة تحت "Risks"): لا يوجد بعد
 * في المشروع مسار HTTP فعلي "لقبول دعوة مشرف" (PGE_Supervisor_Authenticator::
 * authenticate() لا يُستدعى من أي Controller/Route حتى تاريخه — Phase 3/3.5
 * لم تُكلَّفا ببناء هذا المسار). لذلك "نقطة دخول المصادقة" التي يُفترض
 * التوجيه إليها هنا غير مُعرَّفة بعد بشكل ملموس؛ التوجيه الحالي هو الصفحة
 * الرئيسية (home_url('/')) كافتراضي آمن ومحايد، قابل للاستبدال بسطر واحد فور
 * تعريف ذلك المسار في مرحلة لاحقة.
 */
/**
 * ينفّذ إبطال جلسة مشرف + تدقيق 'logout' الشرطي معاً — مُستخرَجة كدالة
 * مسمّاة مستقلة (بدل بقائها داخل الـ closure مباشرة) خصيصاً لتكون قابلة
 * للاختبار التنفيذي المباشر دون المرور عبر add_action('template_redirect')
 * (الذي لا يمكن استدعاؤه من مجموعة اختبارات bootstrap-only). لا منطق جديد
 * هنا — نقل حرفي للكتلة التي كانت داخل الـ closure فقط.
 *
 * @param string $raw_token التوكن الخام من كوكي الجلسة (فارغ = لا شيء لإبطاله)
 * @param string $nonce قيمة nonce من $_GET['_wpnonce']
 * @return array نتيجة PGE_Supervisor_Session::logout() كما هي، أو
 *               ['result' => 'skipped'] إن لم يوجد توكن/nonce صالح
 */
if (!function_exists('pge_supervisor_process_logout_token')) {
    function pge_supervisor_process_logout_token(string $raw_token, string $nonce): array
    {
        // لا تحقّق nonce إن لم تكن هناك أصلاً جلسة لإبطالها — لا حاجة لحماية طلب
        // لا يُغيِّر أي شيء فعلياً على أي حال.
        if ($raw_token === '' || !wp_verify_nonce($nonce, 'pge_supervisor_logout')) {
            return ['result' => 'skipped'];
        }

        $logout_result = PGE_Supervisor_Session::logout($raw_token);

        // تدقيق 'logout' (Supervisor Login Architecture RFC) — فقط عند
        // إبطال فعلي جديد ('logged_out')، لا عند 'already_revoked' (طلب خروج
        // مكرَّر على جلسة مُبطَلة أصلاً لا يُنتج حدث تدقيق ثانٍ — Append-Only
        // بلا ضجيج). لا يُغيِّر هذا أي شيء في الإسناد/الحالة/التفعيل/القبول —
        // فقط سجل تدقيق إضافي، بلا أي أثر على منطق logout() نفسه.
        if (($logout_result['result'] ?? '') === 'logged_out' && class_exists('PGE_Supervisor_Management_Audit')) {
            $logged_out_assignment_id = (int) ($logout_result['assignment_id'] ?? 0);
            $logged_out_event_id = (int) ($logout_result['event_id'] ?? 0);
            if ($logged_out_assignment_id > 0 && $logged_out_event_id > 0) {
                PGE_Supervisor_Management_Audit::record($logged_out_event_id, $logged_out_assignment_id, 0, 'logout', '');
            }
        }

        return $logout_result;
    }
}

add_action('template_redirect', function () {
    $action = get_query_var('pge_action');
    if ($action !== 'supervisor_logout') return;

    $default_redirect = home_url('/');

    if (!class_exists('PGE_Supervisor_Session')) {
        wp_safe_redirect($default_redirect);
        exit;
    }

    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    $raw_token = isset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME])
        ? (string) $_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]
        : '';

    pge_supervisor_process_logout_token($raw_token, $nonce);

    // إتلاف الكوكي من طرف المتصفح دائماً، بصرف النظر عن نجاح/فشل الإبطال في
    // قاعدة البيانات أعلاه — لا يبقى أي أثر للجلسة على هذا الجهاز.
    if (!headers_sent()) {
        setcookie(PGE_Supervisor_Session::SESSION_COOKIE_NAME, '', time() - YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    }
    unset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]);

    wp_safe_redirect($default_redirect);
    exit;
}, 1);

/**
 * ============================================================================
 * قبول دعوة المشرف عبر واتساب — Supervisor Invitation Delivery via Cartat
 * ============================================================================
 * "The route is only an HTTP adapter. Do not duplicate assignment acceptance
 * or session logic inside routing.php." — هذا المعالج لا يحتوي أي منطق قبول
 * دعوة ولا أي منطق جلسة بنفسه: يستخرج التوكن من الرابط فقط، يستدعي
 * PGE_Supervisor_Authenticator::authenticate() الموجودة والمعتمدة فعلياً
 * (Phase 3/Blocker fix #3)، ثم يترجم نتيجتها إلى (أ) كوكي جلسة + إعادة توجيه
 * عند النجاح، أو (ب) صفحة خطأ RTL آمنة ودنيا عند الفشل — تماماً بنفس فلسفة
 * الرفض الآمن في templates/supervisor-portal.php أعلاه.
 *
 * لا تسجيل (logging) للتوكن الخام في أي مسار من هذا المعالج — لا عبر
 * error_log ولا عبر أي استدعاء آخر؛ التحقق من "شكل" التوكن (Requirement:
 * "validate token shape without logging") يحدث محلياً بمقارنة نمط فقط دون
 * أي كتابة لقيمته في أي مكان.
 */
if (!function_exists('pge_supervisor_accept_token_shape_valid')) {
    /**
     * تحقّق "شكل" التوكن فقط — دالة نقية بحتة (لا $wpdb، لا استعلام، لا
     * تسجيل، بلا أي أثر جانبي)، مُستخرَجة عمداً لتكون قابلة للاختبار مباشرة
     * (tests/test-supervisor-cartat-delivery.php) بمعزل عن exit()/الكوكي/
     * إعادة التوجيه في المعالج أدناه. bin2hex(random_bytes(32)) يُنتج دائماً
     * 64 حرف hex صغير — أي قيمة أخرى شكلياً غير صالحة برفض فوري بلا استعلام.
     */
    function pge_supervisor_accept_token_shape_valid(string $raw_token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $raw_token);
    }
}

if (!function_exists('pge_supervisor_accept_classify_auth_error')) {
    /**
     * ترجمة نتيجة فاشلة من PGE_Supervisor_Authenticator::authenticate() إلى
     * إحدى صفحات الخطأ الآمنة الست (بلا حالة 'authenticated' — تُعالَج في
     * المعالج نفسه، لا هنا) — دالة نقية بحتة (bool/string in، array out، بلا
     * $wpdb/كوكي/exit)، مُستخرَجة عمداً من جسم المعالج لتكون قابلة للاختبار
     * التنفيذي المباشر لكل مسار من مسارات هذا الجدول دون تشغيل exit() فعلياً.
     * لا كشف لاسم reason التقني الخام في أي رسالة — فقط عنوان/رسالة عربيان
     * ثابتان مسبقاً لكل فئة.
     *
     * @return array{title:string, message:string, http_status:int}
     */
    function pge_supervisor_accept_classify_auth_error(array $auth_result): array
    {
        $stage = (string) ($auth_result['stage'] ?? '');
        $reason = (string) ($auth_result['reason'] ?? '');

        if ($stage === 'session') {
            // حالة 5: قبول الدعوة نجح فعلياً (التوكن استُهلِك بشكل دائم) لكن
            // إنشاء الجلسة فشل — القاعدة المعتمدة صراحة في class-pge-
            // supervisor-authenticator.php (معالجة الفشل الجزئي): لا تراجع،
            // لا إعادة توليد دعوة، لا اقتراح "أعد فتح هذا الرابط" (التوكن لم
            // يعد صالحاً أصلاً بعد نجاح القبول). الرسالة هنا صادقة بالكامل.
            return [
                'title' => 'تعذّر إكمال تسجيل الدخول',
                'message' => 'تم تأكيد قبولك للدعوة بنجاح، لكن تعذّر فتح جلسة الدخول تلقائياً حالياً. تواصل مع المضيف لمساعدتك في الدخول إلى بوابة المشرف.',
                'http_status' => 500,
            ];
        }

        if ($reason === 'assignment_not_acceptable') {
            // حالة 3: الإسناد موجود لكن حالته لا تسمح بالقبول (مُلغى/منتهٍ).
            return [
                'title' => 'الدعوة لم تعد سارية',
                'message' => 'تم إلغاء دعوتك للإشراف على هذه المناسبة أو لم تعد سارية. تواصل مع المضيف إذا كنت تظن أن هذا خطأ.',
                'http_status' => 403,
            ];
        }

        if ($reason === 'invalid_token' || $reason === 'token_already_used_or_invalid') {
            // حالة 2: توكن غير موجود أصلاً، أو استُهلِك مسبقاً (قبول سابق
            // ناجح أو تعارض تزامن مع طلب آخر لنفس الرابط).
            return [
                'title' => 'الرابط غير صالح',
                'message' => 'تم استخدام رابط الدعوة هذا مسبقاً أو لم يعد صالحاً. تواصل مع المضيف للحصول على رابط دخول جديد.',
                'http_status' => 410,
            ];
        }

        // حالة 4: أي سبب آخر غير متوقَّع من مرحلة "invitation" (مثلاً:
        // assignment_not_active_after_acceptance، missing_assignment_id) —
        // رسالة عامة مؤقتة آمنة، بلا كشف لاسم السبب التقني نفسه.
        return [
            'title' => 'تعذّر تسجيل الدخول',
            'message' => 'تعذّر إكمال تسجيل الدخول حالياً. حاول مرة أخرى بعد قليل، أو تواصل مع المضيف إذا استمرت المشكلة.',
            'http_status' => 500,
        ];
    }
}

if (!function_exists('pge_render_supervisor_accept_error')) {
    /**
     * صفحة خطأ RTL آمنة ودنيا — نفس البنية البصرية (البطاقة المستديرة) في
     * templates/supervisor-portal.php حرفياً، لضمان اتساق تجربة المستخدم بين
     * كل صفحات بوابة المشرف. لا تُعرِض هذه الدالة أبداً: التوكن الخام، معرِّف
     * الإسناد، معرِّف المناسبة، رسالة قاعدة بيانات، أو أي أثر تقني داخلي —
     * فقط عنوان ورسالة عربيتان مُعدَّتان سلفاً من قائمة ثابتة.
     */
    function pge_render_supervisor_accept_error(string $title, string $message, int $http_status): void
    {
        status_header($http_status);
        nocache_headers();
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($title); ?> — بوابة المشرف</title>
    <?php wp_head(); ?>
</head>
<body>
    <div class="relative flex min-h-screen items-center justify-center bg-background px-4 font-arabic" dir="rtl">
        <div class="w-full max-w-sm rounded-3xl border border-border bg-white p-8 text-center shadow-xl">
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
}

add_action('template_redirect', function () {
    $action = get_query_var('pge_action');
    if ($action !== 'supervisor_accept_invitation') return;

    // فشل آمن إن كانت البيئة غير مكتملة — نفس نمط supervisor-portal.php.
    if (!class_exists('PGE_Supervisor_Authenticator') || !class_exists('PGE_Supervisor_Session')) {
        // حالة 6: الخدمة غير متاحة حالياً (فشل تحميل — لا كشف تفاصيل داخلية).
        pge_render_supervisor_accept_error('تعذّر تسجيل الدخول', 'تعذّر تحميل بوابة المشرف حالياً. حاول لاحقاً.', 503);
    }

    $raw_token_from_url = (string) get_query_var('pge_token');
    $raw_token_from_url = rawurldecode($raw_token_from_url);

    // ── حالة 1: شكل التوكن غير صالح (Requirement: "validate token shape
    // without logging") — تحقّق نمطي محلي بحت (pge_supervisor_accept_token_
    // shape_valid())، بلا أي استعلام قاعدة بيانات وبلا أي تسجيل لقيمة التوكن
    // نفسها في أي مكان ──────────────────────────────────────────────────────
    if (!pge_supervisor_accept_token_shape_valid($raw_token_from_url)) {
        pge_render_supervisor_accept_error(
            'رابط الدعوة غير صالح',
            'رابط الدعوة غير صالح. تأكد من نسخ الرابط بالكامل من رسالة واتساب، أو تواصل مع المضيف للحصول على رابط جديد.',
            400
        );
    }

    // ── المحوِّل الفعلي: استدعاء واحد لدالة التنسيق الموجودة فعلاً، بلا أي
    // إعادة تنفيذ لخطواتها الداخلية هنا ─────────────────────────────────────
    $auth_result = PGE_Supervisor_Authenticator::authenticate($raw_token_from_url);
    $result = (string) ($auth_result['result'] ?? '');

    if ($result === 'authenticated') {
        $session_token = (string) ($auth_result['session_token'] ?? '');
        $expires_at = (string) ($auth_result['expires_at'] ?? '');
        $expires_timestamp = $expires_at !== '' ? strtotime($expires_at) : (time() + PGE_Supervisor_Session::SESSION_TTL_SECONDS);

        if ($session_token !== '' && !headers_sent()) {
            // كوكي الجلسة: HttpOnly دائماً، Secure عند HTTPS، SameSite=Lax
            // (رابط قادم من واتساب — طلب تصفّح عادي من موقع خارجي، يحتاج
            // Lax لا Strict كي تُرسَل الكوكي عند أول تحميل بعد الضغط على
            // الرابط)، مسار الكوكي الجذر (نفس اتفاقية إتلافها في معالج
            // supervisor_logout أعلاه)، بلا أي معرِّف داخلي في قيمتها —
            // القيمة هي التوكن الخام نفسه فقط، تماماً كما تُقرَأ في
            // pge_is_active_supervisor_for_event().
            setcookie(PGE_Supervisor_Session::SESSION_COOKIE_NAME, $session_token, [
                'expires' => $expires_timestamp,
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // إعادة توجيه للمسار الرسمي لبوابة المشرف — لا أي معرِّف داخلي
        // (assignment_id/event_id) في رابط إعادة التوجيه.
        wp_safe_redirect(home_url('/supervisor/'));
        exit;
    }

    // ── فشل — ترجمة الأسباب المستقرة الصادرة عن Authenticator إلى إحدى
    // صفحات الخطأ الست الآمنة عبر pge_supervisor_accept_classify_auth_error()
    // (دالة نقية مُختبَرة مباشرة)، دون كشف أي تفصيل داخلي إضافي هنا ──────────
    $error_page = pge_supervisor_accept_classify_auth_error($auth_result);
    pge_render_supervisor_accept_error($error_page['title'], $error_page['message'], $error_page['http_status']);
}, 1);

/**
 * ============================================================================
 * مصادقة توكن الدخول — Supervisor Login Architecture (Post-Activation Login) RFC
 * ============================================================================
 * محوِّل HTTP رفيع تماماً — بلا أي منطق مصادقة/جلسة بنفسه، مطابق بنيوياً
 * لمعالج supervisor_accept_invitation أعلاه، لكنه **مسار مستقل بالكامل**:
 * يستدعي PGE_Supervisor_Login_Authenticator::authenticate() (لا Authenticator
 * الأصلي إطلاقاً). عند النجاح: نفس آلية كوكي الجلسة بالضبط (PGE_Supervisor_
 * Session::SESSION_COOKIE_NAME — الجلسة نفسها مفهوم مشترك بين المسارين، لا
 * "جلسة دخول" منفصلة)، لكن إعادة التوجيه إلى /supervisor/checkin/ مباشرة
 * (لا /supervisor/) — المشرف مُفعَّل بالفعل، لا حاجة لعرض شاشة الترحيب
 * الأولى (portal shell) في كل تسجيل دخول لاحق.
 *
 * pge_supervisor_accept_token_shape_valid()/pge_render_supervisor_accept_error()
 * المُعرَّفتان أعلاه (نقيتان بالكامل، لا محتوى خاص بالدعوة في منطقهما — فحص
 * شكل hex عام، وبطاقة خطأ RTL عامة بعنوان/رسالة/حالة HTTP كمعاملات) تُعاد
 * استخدامهما هنا حرفياً — إعادة استخدام أداة عامة، لا "إعادة استخدام خدمة
 * الدعوة" (الممنوعة صراحةً هي إعادة استخدام PGE_Supervisor_Authenticator/
 * accept_invitation()/المسار نفسه، لا أي دالة نقية عامة الغرض).
 */
if (!function_exists('pge_supervisor_login_classify_auth_error')) {
    /**
     * ترجمة نتيجة فاشلة من PGE_Supervisor_Login_Authenticator::authenticate()
     * إلى صفحة خطأ آمنة — دالة نقية بحتة، مستقلة تماماً عن pge_supervisor_
     * accept_classify_auth_error() (رسائل/أسباب مختلفة بالكامل، لا مشاركة
     * منطق فعلي، فقط تشابه بنيوي في الشكل العام).
     *
     * @return array{title:string, message:string, http_status:int}
     */
    function pge_supervisor_login_classify_auth_error(array $auth_result): array
    {
        $stage = (string) ($auth_result['stage'] ?? '');
        $reason = (string) ($auth_result['reason'] ?? '');

        if ($stage === 'session') {
            // تحقّقت الهوية فعلياً (توكن الدخول استُهلِك بنجاح) لكن تعذّر فتح
            // الجلسة — لا "إعادة محاولة بنفس الرابط" (استُهلِك فعلاً ونهائياً)؛
            // يحتاج المشرف رابط دخول جديداً من المضيف أو من /supervisor/login/.
            return [
                'title' => 'تعذّر فتح الجلسة',
                'message' => 'تم التحقق من هويتك بنجاح، لكن تعذّر فتح جلسة الدخول تلقائياً حالياً. اطلب رابط دخول جديداً من المضيف أو من صفحة تسجيل الدخول.',
                'http_status' => 500,
            ];
        }

        if ($reason === 'assignment_not_active') {
            return [
                'title' => 'لم يعد وصولك فعّالاً',
                'message' => 'لم يعد وصولك كمشرف على هذه المناسبة فعّالاً. تواصل مع المضيف إذا كنت تظن أن هذا خطأ.',
                'http_status' => 403,
            ];
        }

        if ($reason === 'invalid_token' || $reason === 'token_already_used_or_invalid') {
            return [
                'title' => 'رابط الدخول غير صالح',
                'message' => 'تم استخدام رابط الدخول هذا مسبقاً أو لم يعد صالحاً. اطلب رابط دخول جديداً من المضيف أو من صفحة تسجيل الدخول.',
                'http_status' => 410,
            ];
        }

        return [
            'title' => 'تعذّر تسجيل الدخول',
            'message' => 'تعذّر إكمال تسجيل الدخول حالياً. حاول مرة أخرى بعد قليل، أو تواصل مع المضيف إذا استمرت المشكلة.',
            'http_status' => 500,
        ];
    }
}

add_action('template_redirect', function () {
    $action = get_query_var('pge_action');
    if ($action !== 'supervisor_login_authenticate') return;

    if (!class_exists('PGE_Supervisor_Login_Authenticator') || !class_exists('PGE_Supervisor_Session')) {
        pge_render_supervisor_accept_error('تعذّر تسجيل الدخول', 'تعذّر تحميل بوابة المشرف حالياً. حاول لاحقاً.', 503);
    }

    $raw_token_from_url = (string) get_query_var('pge_login_token');
    $raw_token_from_url = rawurldecode($raw_token_from_url);

    if (!pge_supervisor_accept_token_shape_valid($raw_token_from_url)) {
        pge_render_supervisor_accept_error(
            'رابط الدخول غير صالح',
            'رابط الدخول غير صالح. تأكد من نسخ الرابط بالكامل، أو اطلب رابطاً جديداً من المضيف.',
            400
        );
    }

    $auth_result = PGE_Supervisor_Login_Authenticator::authenticate($raw_token_from_url);
    $result = (string) ($auth_result['result'] ?? '');

    if ($result === 'authenticated') {
        $session_token = (string) ($auth_result['session_token'] ?? '');
        $expires_at = (string) ($auth_result['expires_at'] ?? '');
        $expires_timestamp = $expires_at !== '' ? strtotime($expires_at) : (time() + PGE_Supervisor_Session::SESSION_TTL_SECONDS);

        if ($session_token !== '' && !headers_sent()) {
            setcookie(PGE_Supervisor_Session::SESSION_COOKIE_NAME, $session_token, [
                'expires' => $expires_timestamp,
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // إعادة توجيه إلى واجهة تسجيل الحضور مباشرة (لا بوابة الترحيب الأولى) —
        // الفرق الوحيد المقصود عن نتيجة مسار /supervisor/accept/ أعلاه.
        wp_safe_redirect(home_url('/supervisor/checkin/'));
        exit;
    }

    $error_page = pge_supervisor_login_classify_auth_error($auth_result);
    pge_render_supervisor_accept_error($error_page['title'], $error_page['message'], $error_page['http_status']);
}, 1);

/**
 * التوجيه الذكي للملفات (Template Loader)
 */
add_filter('template_include', function ($template) {
    $action = get_query_var('pge_action');

    // توجيه مسار إنشاء المناسبة
    if ($action === 'create_event') {
        $theme_create_template = locate_template('page-create-event.php');
        if ($theme_create_template && file_exists($theme_create_template)) {
            return $theme_create_template;
        }

        $plugin_create_template = PGE_PATH . 'templates/dashboard-create.php';
        if (file_exists($plugin_create_template)) {
            return $plugin_create_template;
        }
    }

    if ($action === 'edit_event') {
        $theme_edit_template = locate_template('page-edit-event.php');
        if ($theme_edit_template && file_exists($theme_edit_template)) {
            return $theme_edit_template;
        }

        $plugin_edit_template = PGE_PATH . 'templates/dashboard-edit.php';
        if (file_exists($plugin_edit_template)) {
            return $plugin_edit_template;
        }
    }

    if ($action === 'event_manage') {
        $theme_manage_template = locate_template('page-event-manage.php');
        if ($theme_manage_template && file_exists($theme_manage_template)) {
            return $theme_manage_template;
        }

        $plugin_manage_template = PGE_PATH . 'templates/event-manage.php';
        if (file_exists($plugin_manage_template)) {
            return $plugin_manage_template;
        }
    }

    // إدارة مشرفي الدخول للمضيف (Phase 8) — نفس نمط theme-first/plugin-
    // fallback المُتَّبع لـevent_manage/edit_event/create_event حرفياً (هذا
    // مسار مضيف عادي بحساب ووردبريس، لا شِلّة أمنية مثل supervisor_portal).
    if ($action === 'event_supervisors') {
        $theme_supervisors_template = locate_template('page-event-supervisors.php');
        if ($theme_supervisors_template && file_exists($theme_supervisors_template)) {
            return $theme_supervisors_template;
        }

        $plugin_supervisors_template = PGE_PATH . 'templates/event-supervisors.php';
        if (file_exists($plugin_supervisors_template)) {
            return $plugin_supervisors_template;
        }
    }

    // إدارة دعوات الضيوف للمضيف (Phase 9) — نفس نمط theme-first/plugin-
    // fallback المُتَّبع أعلاه حرفياً.
    if ($action === 'event_invitations') {
        $theme_invitations_template = locate_template('page-event-invitations.php');
        if ($theme_invitations_template && file_exists($theme_invitations_template)) {
            return $theme_invitations_template;
        }

        $plugin_invitations_template = PGE_PATH . 'templates/event-invitations.php';
        if (file_exists($plugin_invitations_template)) {
            return $plugin_invitations_template;
        }
    }

    // لوحة عمليات المناسبة الحيّة للمضيف (Phase 10) — نفس نمط theme-first/
    // plugin-fallback المُتَّبع لـevent_supervisors/event_invitations حرفياً.
    if ($action === 'event_operations') {
        $theme_operations_template = locate_template('page-event-operations.php');
        if ($theme_operations_template && file_exists($theme_operations_template)) {
            return $theme_operations_template;
        }

        $plugin_operations_template = PGE_PATH . 'templates/event-operations.php';
        if (file_exists($plugin_operations_template)) {
            return $plugin_operations_template;
        }
    }

    if ($action === 'login') {
        $theme_login_template = locate_template('page-login.php');
        if ($theme_login_template && file_exists($theme_login_template)) {
            return $theme_login_template;
        }

        $plugin_login_template = PGE_PATH . 'templates/login.php';
        if (file_exists($plugin_login_template)) {
            return $plugin_login_template;
        }
    }

    if ($action === 'register') {
        $theme_register_template = locate_template('page-register.php');
        if ($theme_register_template && file_exists($theme_register_template)) {
            return $theme_register_template;
        }

        $plugin_register_template = PGE_PATH . 'templates/register.php';
        if (file_exists($plugin_register_template)) {
            return $plugin_register_template;
        }
    }

    if ($action === 'forgot_password') {
        $theme_forgot_template = locate_template('page-forgot-password.php');
        if ($theme_forgot_template && file_exists($theme_forgot_template)) {
            return $theme_forgot_template;
        }

        $plugin_forgot_template = PGE_PATH . 'templates/forgot-password.php';
        if (file_exists($plugin_forgot_template)) {
            return $plugin_forgot_template;
        }
    }

    // توجيه مسار لوحة التحكم (التي كانت سابقاً page-profile.php)
    if ($action === 'dashboard') {
        $main_dashboard = PGE_PATH . 'templates/dashboard-main.php';
        if (file_exists($main_dashboard)) {
            return $main_dashboard;
        }
    }

    // بوابة المشرف (Entry Check-in Supervisors — Phase 3.5). لا locate_template()
    // هنا عمداً (بخلاف login/register/create-event) — هذه شِلّة أمنية بسيطة
    // بلا أي محتوى قابل للتحرير عبر Elementor، بنفس فلسفة dashboard-main.php:
    // القالب يعيش في الإضافة فقط، لا في الثيم.
    if ($action === 'supervisor_portal') {
        $supervisor_portal_template = PGE_PATH . 'templates/supervisor-portal.php';
        if (file_exists($supervisor_portal_template)) {
            return $supervisor_portal_template;
        }
    }

    // صفحة طلب رابط الدخول الذاتية (Supervisor Login Architecture) — نفس
    // فلسفة عدم استخدام locate_template() المُتّبَعة في supervisor_portal
    // تماماً (شِلّة أمنية بسيطة، لا محتوى قابل للتحرير عبر Elementor).
    if ($action === 'supervisor_login_request') {
        $supervisor_login_template = PGE_PATH . 'templates/supervisor-login.php';
        if (file_exists($supervisor_login_template)) {
            return $supervisor_login_template;
        }
    }

    // لوحة إحصاءات الحضور للمشرف (Phase 6) — نفس فلسفة عدم استخدام
    // locate_template() المُتّبَعة في supervisor_portal أعلاه تحديداً.
    if ($action === 'supervisor_dashboard') {
        $supervisor_dashboard_template = PGE_PATH . 'templates/supervisor-dashboard.php';
        if (file_exists($supervisor_dashboard_template)) {
            return $supervisor_dashboard_template;
        }
    }

    // واجهة تسجيل الحضور للمشرف (Phase 7) — نفس فلسفة عدم استخدام
    // locate_template() المُتّبَعة في supervisor_portal/supervisor_dashboard.
    if ($action === 'supervisor_checkin') {
        $supervisor_checkin_template = PGE_PATH . 'templates/supervisor-checkin.php';
        if (file_exists($supervisor_checkin_template)) {
            return $supervisor_checkin_template;
        }
    }

    return $template;
});
