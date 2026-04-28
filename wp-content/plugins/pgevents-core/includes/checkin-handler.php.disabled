<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_pge_checkin_guest', function () {
    if (!is_user_logged_in()) wp_send_json_error('يجب تسجيل الدخول');

    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'pge_checkin_nonce')) {
        wp_send_json_error('Nonce غير صالح');
    }

    $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
    $phone = isset($_POST['phone']) ? preg_replace('/\D+/', '', (string) $_POST['phone']) : '';

    if (!$event_id || !$phone) wp_send_json_error('أدخل رقم الهاتف');

    // تحقق أن المناسبة للمستخدم الحالي (أو أدمن)
    $author_id = (int) get_post_field('post_author', $event_id);
    $uid = get_current_user_id();
    if (!current_user_can('administrator') && $author_id !== $uid) {
        wp_send_json_error('غير مصرح');
    }

    // تحقق أن الهاتف ضمن المدعوين
    $raw = get_post_meta($event_id, '_pge_invited_phones', true);
    $invited = [];

    if (is_array($raw)) $invited = $raw;
    else {
        $raw = (string) $raw;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $invited = array_filter(array_map('trim', explode("\n", $raw)));
    }

    $norm = [];
    foreach ($invited as $p) {
        $n = preg_replace('/\D+/', '', (string) $p);
        if ($n !== '') $norm[] = $n;
    }
    $norm = array_values(array_unique($norm));

    if (!in_array($phone, $norm, true)) {
        wp_send_json_error('رقم الجوال غير موجود ضمن قائمة المدعوين');
    }

    $checkins = (array) get_post_meta($event_id, '_pge_checkins', true);
    if (!is_array($checkins)) $checkins = [];

    $checkins[$phone] = current_time('mysql'); // timestamp
    update_post_meta($event_id, '_pge_checkins', $checkins);

    wp_send_json_success(true);
});
