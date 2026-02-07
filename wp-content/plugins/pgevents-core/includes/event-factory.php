<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('pge_normalize_invite_code')) {
    function pge_normalize_invite_code($code)
    {
        $code = strtoupper(trim((string) $code));
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        if ($code === '') return '';

        $code = substr($code, 0, 8);
        if (strlen($code) > 4) {
            $code = substr($code, 0, 4) . '-' . substr($code, 4);
        }

        return $code;
    }
}

if (!function_exists('pge_generate_invite_code')) {
    function pge_generate_invite_code()
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $raw = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < 8; $i++) {
            $raw .= $chars[random_int(0, $max)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }
}

/**
 * معالجة إنشاء مناسبة جديدة عبر AJAX مع فحص الحصة (Quota) الديناميكية
 */
add_action('wp_ajax_pge_create_new_event', 'pge_handle_event_creation');

function pge_handle_event_creation()
{
    // 1. التحقق من تسجيل الدخول
    if (!is_user_logged_in()) {
        wp_send_json_error('يجب تسجيل الدخول أولاً');
    }

    // 2. التحقق من الأمان (Nonce)
    if (!isset($_POST['pge_event_nonce']) || !wp_verify_nonce($_POST['pge_event_nonce'], 'pge_create_event_action')) {
        wp_send_json_error('خطأ في التحقق من الأمان');
    }

    $user_id = get_current_user_id();

    // --- [نظام فحص الحصة الديناميكي - Dynamic Quota System] ---

    // جلب الحد الأقصى للمناسبات من باقة المستخدم (القيمة المخزنة عبر سلة)
    // نستخدم القيمة الافتراضية 0 إذا لم يكن لدى المستخدم باقة نشطة
    $allowed_limit = get_user_meta($user_id, '_mon_events_limit', true);

    // التأكد من أن القيمة رقمية (في حال كانت فارغة أو غير مضبوطة)
    $allowed_limit = ($allowed_limit === '') ? 0 : (int)$allowed_limit;

    // جلب عدد المناسبات الحالية للمستخدم (بجميع الحالات ما عدا المحذوفة)
    $user_events_query = new WP_Query(array(
        'post_type'      => 'pge_event',
        'post_status'    => array('publish', 'private', 'draft', 'pending'),
        'author'         => $user_id,
        'posts_per_page' => -1,
        'fields'         => 'ids', // لتحسين الأداء
    ));

    $current_count = $user_events_query->found_posts;

    // الفحص: هل يحق للمستخدم إنشاء مناسبة جديدة؟
    if ($current_count >= $allowed_limit) {
        if ($allowed_limit <= 0) {
            $error_msg = 'عذراً، ليس لديك باقة نشطة. يرجى الاشتراك في إحدى الباقات لتمكن من إنشاء مناسبات.';
        } else {
            $error_msg = sprintf(
                'لقد استنفدت الحد الأقصى للمناسبات في باقتك الحالية (%d من %d). يرجى الترقية لإضافة المزيد.',
                $current_count,
                $allowed_limit
            );
        }
        wp_send_json_error($error_msg);
    }
    // --------------------------------------------------------

    // 3. استلام وتنظيف البيانات
    $title    = sanitize_text_field($_POST['event_title']);
    $date     = sanitize_text_field($_POST['event_date']);
    $location = esc_url_raw($_POST['event_location']);
    $phone    = sanitize_text_field($_POST['host_phone']);
    $invite_code = isset($_POST['invite_code']) ? pge_normalize_invite_code(wp_unslash($_POST['invite_code'])) : '';
    if ($invite_code === '') {
        $invite_code = pge_generate_invite_code();
    }

    // 4. إدراج المناسبة في قاعدة البيانات
    $post_data = array(
        'post_title'   => $title,
        'post_status'  => 'publish',
        'post_type'    => 'pge_event',
        'post_author'  => $user_id,
    );

    $post_id = wp_insert_post($post_data);

    if ($post_id) {
        // تخزين الميتا داتا الإضافية
        update_post_meta($post_id, '_pge_event_date', $date);
        update_post_meta($post_id, '_pge_event_location', $location);
        update_post_meta($post_id, '_pge_host_phone', $phone);
        update_post_meta($post_id, '_pge_invite_code', $invite_code);

        wp_send_json_success(array(
            'message'      => 'تم إنشاء المناسبة بنجاح!',
            'redirect_url' => get_permalink($post_id),
            'invite_code'  => $invite_code,
        ));
    }

    wp_send_json_error('حدث خطأ أثناء إنشاء المناسبة، يرجى المحاولة لاحقاً.');
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

    // التحقق من الملكية
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

        $invite_code = isset($_POST['invite_code']) ? pge_normalize_invite_code(wp_unslash($_POST['invite_code'])) : '';
        if ($invite_code === '') {
            $invite_code = pge_normalize_invite_code((string) get_post_meta($event_id, '_pge_invite_code', true));
            if ($invite_code === '') {
                $invite_code = pge_generate_invite_code();
            }
        }
        update_post_meta($event_id, '_pge_invite_code', $invite_code);

        wp_send_json_success('تم تحديث البيانات بنجاح');
    } else {
        wp_send_json_error('فشل تحديث قاعدة البيانات');
    }
}

add_action('wp_ajax_pge_event_set_invite_code', 'pge_event_set_invite_code');

function pge_event_set_invite_code()
{
    if (!is_user_logged_in()) wp_send_json_error('غير مصرح');

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'pge_event_manage_nonce')) {
        wp_send_json_error('رمز الأمان غير صالح');
    }

    $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
    if (!$event_id || get_post_type($event_id) !== 'pge_event') {
        wp_send_json_error('مناسبة غير صالحة');
    }

    $can_manage = false;
    if (function_exists('pge_event_guests_user_can_manage')) {
        $can_manage = pge_event_guests_user_can_manage($event_id);
    } else {
        $uid = get_current_user_id();
        $author_id = (int) get_post_field('post_author', $event_id);
        $can_manage = current_user_can('administrator') || ($uid && $uid === $author_id) || current_user_can('edit_post', $event_id);
    }

    if (!$can_manage) {
        wp_send_json_error('ليس لديك صلاحية إدارة هذه المناسبة');
    }

    $invite_code = isset($_POST['invite_code']) ? pge_normalize_invite_code(wp_unslash($_POST['invite_code'])) : '';
    if ($invite_code === '') {
        $invite_code = pge_generate_invite_code();
    }

    update_post_meta($event_id, '_pge_invite_code', $invite_code);

    wp_send_json_success([
        'message'     => 'تم تحديث رمز الدعوة',
        'invite_code' => $invite_code,
    ]);
}

/**
 * أرشفة المناسبة (تحويلها لخاص) بدلاً من الحذف لضمان بقائها ضمن الحصة
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
