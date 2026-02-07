<?php

/**
 * Plugin Name: PgEvents Core
 * Description: المحرك البرمجي لنظام المناسبات - شركة بال قول.
 * Version: 1.0.0
 * Author: Pal Goal Team
 */

if (!defined('ABSPATH')) exit;

define('PGE_URL', plugin_dir_url(__FILE__));
define('PGE_PATH', plugin_dir_path(__FILE__));

// 1. استدعاء المكونات الأساسية (Logic)
require_once PGE_PATH . 'includes/cpts.php';
require_once PGE_PATH . 'includes/metaboxes.php';
require_once PGE_PATH . 'includes/user-profiles.php';
require_once PGE_PATH . 'includes/rsvp-handler.php';
require_once PGE_PATH . 'includes/event-factory.php';
require_once PGE_PATH . 'includes/admin-mods.php';
require_once PGE_PATH . 'includes/class-pge-packages.php';
include_once PGE_PATH . 'includes/checkin-handler.php';
include_once PGE_PATH . 'includes/ajax.php';
require_once PGE_PATH . 'includes/event-guests.php';


// أضف هذا السطر هنا (مهم جداً لحل خطأ 500)
require_once PGE_PATH . 'includes/class-mon-events-users.php';

// 2. المحرك الرئيسي للربط مع سلة (Webhook Handler)
require_once PGE_PATH . 'includes/class-salla-handler.php';

// 2. استدعاء نظام التوجيه (Routing) - بديل الصفحات التقليدية
require_once PGE_PATH . 'includes/routing.php';

add_action('init', function () {
    $rewrite_version = '1.0.4';

    if (get_option('pge_rewrite_version') !== $rewrite_version) {
        flush_rewrite_rules(false);
        update_option('pge_rewrite_version', $rewrite_version);
    }
}, 20);

// 3. تحديث الروابط عند التفعيل لضمان عمل الـ Endpoints
register_activation_hook(__FILE__, function () {
    // 1. تسجيل نوع المنشورات
    pge_register_event_post_type();
    add_rewrite_rule('^dashboard/?$', 'index.php?pge_action=dashboard', 'top');
    add_rewrite_rule('^create-event/?$', 'index.php?pge_action=create_event', 'top');
    add_rewrite_rule('^edit-event/([0-9]+)/?$', 'index.php?pge_action=edit_event&event_id=$matches[1]', 'top');
    add_rewrite_rule('^event-manage/([0-9]+)/?$', 'index.php?pge_action=event_manage&event_id=$matches[1]', 'top');
    add_rewrite_rule('^login/?$', 'index.php?pge_action=login', 'top');
    add_rewrite_rule('^register/?$', 'index.php?pge_action=register', 'top');
    add_rewrite_rule('^forgot-password/?$', 'index.php?pge_action=forgot_password', 'top');
    flush_rewrite_rules();
    update_option('pge_rewrite_version', '1.0.4');
});

// 4. تحديث الروابط عند التعطيل (تنظيف)
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

// add_action('init', function () {
//     flush_rewrite_rules();
// });
