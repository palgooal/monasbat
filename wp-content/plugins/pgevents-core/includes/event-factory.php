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

if (!function_exists('pge_handle_featured_image_upload')) {
    function pge_handle_featured_image_upload($field_name, $post_id)
    {
        if (empty($_FILES[$field_name]) || !is_array($_FILES[$field_name])) {
            return 0;
        }

        $file = $_FILES[$field_name];
        $filename = isset($file['name']) ? (string) $file['name'] : '';
        if ($filename === '') {
            return 0;
        }

        $error_code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error_code === UPLOAD_ERR_NO_FILE) {
            return 0;
        }

        if ($error_code !== UPLOAD_ERR_OK) {
            return new WP_Error('pge_featured_image_upload_error', 'تعذر رفع الصورة البارزة. حاول مرة أخرى.');
        }

        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachment_id = media_handle_upload($field_name, $post_id, [], ['test_form' => false]);
        if (is_wp_error($attachment_id)) {
            return new WP_Error('pge_featured_image_upload_error', $attachment_id->get_error_message());
        }

        set_post_thumbnail($post_id, (int) $attachment_id);
        return (int) $attachment_id;
    }
}

if (!function_exists('pge_get_user_plan_limits_for_events')) {
    function pge_get_user_plan_limits_for_events($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) return [];

        $plan_key = (string) get_user_meta($user_id, '_mon_package_key', true);
        if ($plan_key === '') {
            $plan_key = (string) get_user_meta($user_id, 'pge_current_plan', true);
        }

        $active_features = get_user_meta($user_id, '_mon_active_features', true);
        $has_active_features = is_array($active_features) && !empty($active_features);
        $has_plan_context = ($plan_key !== '') || $has_active_features;

        if ($has_plan_context && class_exists('PGE_Packages')) {
            $limits = (array) PGE_Packages::get_user_plan_limits($user_id);
            if (!empty($limits)) {
                return $limits;
            }
        }

        $limits = [];
        $limits['events_count'] = (int) get_user_meta($user_id, '_mon_events_limit', true);
        $limits['guest_limit'] = (int) get_user_meta($user_id, '_mon_guest_limit', true);
        $limits['host_photos'] = (int) get_user_meta($user_id, '_mon_host_photos_limit', true);
        $limits['wa_messages'] = (int) get_user_meta($user_id, '_mon_wa_limit', true);

        if (is_array($active_features)) {
            foreach ($active_features as $feature_key) {
                $limits[(string) $feature_key] = 1;
            }
        }

        return $limits;
    }
}

if (!function_exists('pge_plan_feature_enabled_for_events')) {
    function pge_plan_feature_enabled_for_events($limits, $feature_key)
    {
        if (class_exists('PGE_Packages') && method_exists('PGE_Packages', 'is_feature_enabled')) {
            return PGE_Packages::is_feature_enabled((array) $limits, (string) $feature_key);
        }

        $value = is_array($limits) && isset($limits[$feature_key]) ? $limits[$feature_key] : 0;
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return ((int) $value) === 1;
        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'on', 'yes', 'true'], true);
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

    // جلب صلاحيات الباقة الفعلية للمستخدم
    $plan_limits = pge_get_user_plan_limits_for_events($user_id);
    $meta_limit = get_user_meta($user_id, '_mon_events_limit', true);
    $user_plan_key = (string) get_user_meta($user_id, '_mon_package_key', true);
    if ($user_plan_key === '') {
        $user_plan_key = (string) get_user_meta($user_id, 'pge_current_plan', true);
    }

    if ($meta_limit !== '') {
        $allowed_limit = (int) $meta_limit;
    } else {
        $allowed_limit = $user_plan_key !== '' ? (int) ($plan_limits['events_count'] ?? 0) : 0;
    }

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
    $can_google_map = pge_plan_feature_enabled_for_events($plan_limits, 'google_map');
    $can_header_img = pge_plan_feature_enabled_for_events($plan_limits, 'header_img');
    $location = $can_google_map ? esc_url_raw($_POST['event_location'] ?? '') : '';
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
        if ($can_header_img) {
            $featured_upload = pge_handle_featured_image_upload('featured_image', $post_id);
            if (is_wp_error($featured_upload)) {
                wp_delete_post($post_id, true);
                wp_send_json_error($featured_upload->get_error_message());
            }
        }

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
        $plan_limits = pge_get_user_plan_limits_for_events(get_current_user_id());
        $can_google_map = pge_plan_feature_enabled_for_events($plan_limits, 'google_map');
        $can_header_img = pge_plan_feature_enabled_for_events($plan_limits, 'header_img');

        update_post_meta($event_id, '_pge_event_date', sanitize_text_field($_POST['event_date']));
        update_post_meta($event_id, '_pge_event_location', $can_google_map ? esc_url_raw($_POST['event_location'] ?? '') : '');
        update_post_meta($event_id, '_pge_host_phone', sanitize_text_field($_POST['host_phone']));

        $invite_code = isset($_POST['invite_code']) ? pge_normalize_invite_code(wp_unslash($_POST['invite_code'])) : '';
        if ($invite_code === '') {
            $invite_code = pge_normalize_invite_code((string) get_post_meta($event_id, '_pge_invite_code', true));
            if ($invite_code === '') {
                $invite_code = pge_generate_invite_code();
            }
        }
        update_post_meta($event_id, '_pge_invite_code', $invite_code);
        if ($can_header_img) {
            $featured_upload = pge_handle_featured_image_upload('featured_image', $event_id);
            if (is_wp_error($featured_upload)) {
                wp_send_json_error($featured_upload->get_error_message());
            }
        }

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
