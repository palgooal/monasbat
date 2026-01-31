<?php
if (!defined('ABSPATH')) exit;

/**
 * معالجة إنشاء مناسبة جديدة عبر AJAX مع فحص الحصة (Quota)
 */
add_action('wp_ajax_pge_create_new_event', 'pge_handle_event_creation');

function pge_handle_event_creation()
{
    if (!is_user_logged_in()) wp_send_json_error('يجب تسجيل الدخول أولاً');

    if (!isset($_POST['pge_event_nonce']) || !wp_verify_nonce($_POST['pge_event_nonce'], 'pge_create_event_action')) {
        wp_send_json_error('خطأ في التحقق من الأمان');
    }

    $user_id = get_current_user_id();

    // --- نظام فحص الحصة (Quota System) ---
    // هنا نحدد الحد الأقصى بناءً على الباقة (مؤقتاً سنضع رقم 5 كمثال)
    // يمكنك لاحقاً جلب هذا الرقم من بيانات العضوية
    $allowed_limit = 5;

    $user_events = get_posts(array(
        'post_type'   => 'pge_event',
        'post_status' => array('publish', 'private', 'draft', 'pending'), // نحسب كل شيء استهلكه المستخدم
        'author'      => $user_id,
        'posts_per_page' => -1
    ));

    if (count($user_events) >= $allowed_limit) {
        wp_send_json_error('لقد استنفدت الحد الأقصى للمناسبات في باقتك الحالية (' . $allowed_limit . '). يرجى الترقية لإضافة المزيد.');
    }
    // --------------------------------------

    $title = sanitize_text_field($_POST['event_title']);
    $date = sanitize_text_field($_POST['event_date']);
    $location = esc_url_raw($_POST['event_location']);
    $phone = sanitize_text_field($_POST['host_phone']);

    $post_data = array(
        'post_title'    => $title,
        'post_status'   => 'publish',
        'post_type'     => 'pge_event',
        'post_author'   => $user_id,
    );

    $post_id = wp_insert_post($post_data);

    if ($post_id) {
        update_post_meta($post_id, '_pge_event_date', $date);
        update_post_meta($post_id, '_pge_event_location', $location);
        update_post_meta($post_id, '_pge_host_phone', $phone);

        wp_send_json_success(array('redirect_url' => get_permalink($post_id)));
    }

    wp_send_json_error('حدث خطأ أثناء إنشاء المناسبة');
}

/**
 * معالجة تحديث المناسبة عبر AJAX
 */
add_action('wp_ajax_pge_handle_event_update', 'pge_handle_event_update');

function pge_handle_event_update()
{
    if (!is_user_logged_in()) wp_send_json_error('غير مسموح');

    $event_id = intval($_POST['event_id']);
    $post = get_post($event_id);

    if (!$post || $post->post_author != get_current_user_id()) {
        wp_send_json_error('ليس لديك صلاحية لتعديل هذه المناسبة');
    }

    if (!isset($_POST['pge_event_nonce']) || !wp_verify_nonce($_POST['pge_event_nonce'], 'pge_edit_event_action')) {
        wp_send_json_error('خطأ في التحقق من الأمان (Nonce)');
    }

    $updated_post = array(
        'ID'         => $event_id,
        'post_title' => sanitize_text_field($_POST['event_title']),
    );

    $result = wp_update_post($updated_post);

    if ($result) {
        update_post_meta($event_id, '_pge_event_date', sanitize_text_field($_POST['event_date']));
        update_post_meta($event_id, '_pge_event_location', esc_url_raw($_POST['event_location']));
        update_post_meta($event_id, '_pge_host_phone', sanitize_text_field($_POST['host_phone']));

        wp_send_json_success('تم تحديث البيانات بنجاح');
    } else {
        wp_send_json_error('فشل تحديث قاعدة البيانات');
    }
}

/**
 * أرشفة المناسبة (إغلاقها) بدلاً من الحذف
 */
add_action('wp_ajax_pge_archive_event', 'pge_handle_event_archiving');

function pge_handle_event_archiving()
{
    if (!is_user_logged_in()) wp_send_json_error('غير مسموح');

    $event_id = intval($_POST['event_id']);
    $post = get_post($event_id);

    if (!$post || $post->post_author != get_current_user_id()) {
        wp_send_json_error('ليس لديك صلاحية لإغلاق هذه المناسبة');
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pge_archive_event_nonce')) {
        wp_send_json_error('خطأ في التحقق من الأمان');
    }

    // نستخدم 'private' لضمان عدم ظهورها للجمهور مع بقاء صاحبها قادراً على رؤيتها في الداشبورد
    $result = wp_update_post(array(
        'ID'          => $event_id,
        'post_status' => 'private'
    ));

    if ($result) {
        wp_send_json_success('تم إغلاق المناسبة وأرشفتها بنجاح');
    } else {
        wp_send_json_error('فشل في إغلاق المناسبة');
    }
}
