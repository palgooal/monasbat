<?php
// ===============================
// AJAX: Check-in guest
// action: pge_checkin_guest
// ===============================
add_action('wp_ajax_pge_checkin_guest', function () {

    // أمن
    if (!is_user_logged_in()) wp_send_json_error(__('غير مصرح', 'pgevents'));

    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'pge_checkin_nonce')) {
        wp_send_json_error(__('Nonce غير صالح', 'pgevents'));
    }

    $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
    if (!$event_id || get_post_type($event_id) !== 'pge_event') {
        wp_send_json_error(__('حدث غير صالح', 'pgevents'));
    }

    // صلاحيات: صاحب المناسبة أو Admin
    $uid = get_current_user_id();
    $author_id = (int) get_post_field('post_author', $event_id);
    if (!current_user_can('administrator') && $uid !== $author_id && !current_user_can('edit_post', $event_id)) {
        wp_send_json_error(__('ليس لديك صلاحية لإدارة هذه المناسبة', 'pgevents'));
    }

    // الهاتف
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $phone_n = preg_replace('/\D+/', '', $phone);
    if ($phone_n === '') wp_send_json_error(__('أدخل رقم هاتف صحيح', 'pgevents'));

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
        wp_send_json_error(__('رقم الهاتف غير موجود ضمن المدعوين', 'pgevents'));
    }

    // خزّن check-in — في نفس الجدول الحقيقي الذي تُقرأ منه كل إحصاءات لوحة التحكم
    // (wp_pge_event_rsvps.checked_in)، بدل _pge_checkins Post Meta القديم الذي لا تقرأ
    // منه لوحة التحكم أبداً. هذا يوحّد الكتابة والقراءة على مصدر واحد فقط.
    global $wpdb;
    $rsvp_table = $wpdb->prefix . 'pge_event_rsvps';

    $already_checked = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT checked_in FROM {$rsvp_table} WHERE event_id = %d AND guest_phone = %s LIMIT 1",
        $event_id,
        $phone_n
    ));

    // إذا مسجل مسبقًا
    if ($already_checked === 1) {
        wp_send_json_success(['message' => __('مسجل مسبقًا', 'pgevents'), 'already' => true]);
    }

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$rsvp_table} (event_id, guest_phone, reply, checked_in, checked_in_at)
         VALUES (%d, %s, 'pending', 1, NOW())
         ON DUPLICATE KEY UPDATE checked_in = 1, checked_in_at = NOW()",
        $event_id,
        $phone_n
    ));

    wp_send_json_success(['message' => __('تم تسجيل الدخول', 'pgevents')]);
});
