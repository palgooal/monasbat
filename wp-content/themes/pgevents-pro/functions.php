<?php
if (! defined('ABSPATH')) exit;

// 1. دعم القالب
function pgevents_setup()
{
    add_theme_support('post-thumbnails');
    add_theme_support('title-tag');
}
add_action('after_setup_theme', 'pgevents_setup');

// 2. التحقق من وجود الإضافة الأساسية
add_action('admin_notices', function () {
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    // المسار الجديد بعد تعديل اسم المجلد
    if (! is_plugin_active('pgevents-core/pgevents-core.php')) {
        echo '<div class="notice notice-error"><p>تنبيه: قالب PgEvents Pro يتطلب تفعيل إضافة <b>PgEvents Core</b> ليعمل بشكل صحيح.</p></div>';
    }
});

// 3. استدعاء Tailwind v4 و FontAwesome
function pgevents_enqueue_assets()
{
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css');
    wp_enqueue_script('tailwind-v4', 'https://unpkg.com/@tailwindcss/browser@4', array(), null, false);
}
add_action('wp_enqueue_scripts', 'pgevents_enqueue_assets');

add_action('admin_init', function () {
    if (!current_user_can('administrator') && !(defined('DOING_AJAX') && DOING_AJAX)) {
        wp_redirect(home_url('/profile/')); // توجيههم لصفحة الملف الشخصي
        exit;
    }
});

/**
 * استقبال تحديثات الملف الشخصي من الصفحة الأمامية
 */
add_action('wp_ajax_pge_update_user_profile', function () {
    if (!is_user_logged_in()) wp_send_json_error();

    $user_id = get_current_user_id();

    if (isset($_POST['pge_bio'])) {
        update_user_meta($user_id, 'pge_bio', sanitize_textarea_field($_POST['pge_bio']));
    }

    if (isset($_POST['pge_cover_url'])) {
        update_user_meta($user_id, 'pge_cover_url', esc_url_raw($_POST['pge_cover_url']));
    }

    wp_send_json_success();
});

// إخفاء شريط ووردبريس العلوي لغير المديرين
if (!current_user_can('administrator')) {
    show_admin_bar(false);
}
// إخفاء شريط الأدوات لغير المديرين
add_action('after_setup_theme', function () {
    if (!current_user_can('administrator') && !is_admin()) {
        show_admin_bar(false);
    }
});
