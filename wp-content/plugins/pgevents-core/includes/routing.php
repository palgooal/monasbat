<?php
if (!defined('ABSPATH')) exit;

/**
 * تسجيل المسارات البرمجية (Virtual Routes) للنظام
 */
add_action('init', function () {
    // 1. مسار إنشاء مناسبة جديدة: monasbat.test/create-event/
    add_rewrite_rule('^create-event/?$', 'index.php?pge_action=create_event', 'top');

    // أضف هذه القاعدة داخل دالة init في ملف routing.php
    add_rewrite_rule('edit-event/([0-9]+)/?$', 'index.php?pge_action=edit_event&event_id=$matches[1]', 'top');

    // 2. مسار لوحة التحكم الرئيسية: monasbat.test/dashboard/
    add_rewrite_rule('^dashboard/?$', 'index.php?pge_action=dashboard', 'top');
    add_rewrite_rule('^login/?$', 'index.php?pge_action=login', 'top');
    add_rewrite_rule('^register/?$', 'index.php?pge_action=register', 'top');
    add_rewrite_rule('^forgot-password/?$', 'index.php?pge_action=forgot_password', 'top');


});

/**
 * تسجيل المتغيرات لكي يفهمها محرك ووردبريس
 */
add_filter('query_vars', function ($vars) {
    $vars[] = 'pge_action';
    $vars[] = 'event_id';
    return $vars;
});

/**
 * التوجيه الذكي للملفات (Template Loader)
 */
add_filter('template_include', function ($template) {
    $action = get_query_var('pge_action');

    // توجيه مسار إنشاء المناسبة
    if ($action === 'create_event') {
        $create_template = PGE_PATH . 'templates/dashboard-create.php';
        if (file_exists($create_template)) {
            return $create_template;
        }
    }

    if (get_query_var('pge_action') === 'edit_event') {
        $edit_template = PGE_PATH . 'templates/dashboard-edit.php';
        if (file_exists($edit_template)) return $edit_template;
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

    return $template;
});
