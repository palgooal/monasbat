<?php
// ===============================
// AJAX: Check-in guest
// action: pge_checkin_guest
// ===============================
add_action('wp_ajax_pge_checkin_guest', function () {

    // أمن
    if (!is_user_logged_in()) wp_send_json_error('غير مصرح');

    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'pge_checkin_nonce')) {
        wp_send_json_error('Nonce غير صالح');
    }

    $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
    if (!$event_id || get_post_type($event_id) !== 'pge_event') {
        wp_send_json_error('حدث غير صالح');
    }

    // صلاحيات: صاحب المناسبة أو Admin
    $uid = get_current_user_id();
    $author_id = (int) get_post_field('post_author', $event_id);
    if (!current_user_can('administrator') && $uid !== $author_id && !current_user_can('edit_post', $event_id)) {
        wp_send_json_error('ليس لديك صلاحية لإدارة هذه المناسبة');
    }

    // الهاتف
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $phone_n = preg_replace('/\D+/', '', $phone);
    if ($phone_n === '') wp_send_json_error('أدخل رقم هاتف صحيح');

    // لازم يكون المدعو موجود ضمن قائمة المدعوين
    $invited = get_post_meta($event_id, '_pge_invited_phones', true);

    if (!is_array($invited)) {
        $raw = (string) $invited;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        $invited = $lines;
    }

    $invited_norm = [];
    foreach ((array)$invited as $p) {
        $n = preg_replace('/\D+/', '', (string)$p);
        if ($n !== '') $invited_norm[$n] = true;
    }

    if (empty($invited_norm[$phone_n])) {
        wp_send_json_error('رقم الهاتف غير موجود ضمن المدعوين');
    }

    // خزّن check-in
    $checkins = (array) get_post_meta($event_id, '_pge_checkins', true);
    if (!is_array($checkins)) $checkins = [];

    // إذا مسجل مسبقًا
    if (isset($checkins[$phone_n])) {
        wp_send_json_success(['message' => 'مسجل مسبقًا', 'already' => true]);
    }

    $checkins[$phone_n] = current_time('timestamp');
    update_post_meta($event_id, '_pge_checkins', $checkins);

    wp_send_json_success(['message' => 'تم تسجيل الدخول']);
});
