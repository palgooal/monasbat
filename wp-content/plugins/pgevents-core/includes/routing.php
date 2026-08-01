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

    // لا تحقّق nonce إن لم تكن هناك أصلاً جلسة لإبطالها — لا حاجة لحماية طلب
    // لا يُغيِّر أي شيء فعلياً على أي حال.
    if ($raw_token !== '' && wp_verify_nonce($nonce, 'pge_supervisor_logout')) {
        PGE_Supervisor_Session::logout($raw_token);
    }

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
