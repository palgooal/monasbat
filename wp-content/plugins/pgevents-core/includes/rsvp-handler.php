<?php
if (!defined('ABSPATH')) exit;

function pge_create_rsvp_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'pge_rsvp';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id BIGINT(20) UNSIGNED NOT NULL,
        guest_phone VARCHAR(32) NOT NULL,
        guest_name VARCHAR(191) NULL,
        companions INT(11) DEFAULT 0,
        note TEXT NULL,
        reply VARCHAR(10) DEFAULT 'pending',
        checked_in TINYINT(1) DEFAULT 0,
        checked_in_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY event_phone (event_id, guest_phone),
        KEY event_id (event_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(PGE_PATH . 'pgevents-core.php', 'pge_create_rsvp_table');

add_action('wp_ajax_pge_rsvp_submit', 'pge_rsvp_submit');
add_action('wp_ajax_nopriv_pge_rsvp_submit', 'pge_rsvp_submit');

function pge_rsvp_submit()
{
    if (empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pge_rsvp_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
    }

    $event_id = absint($_POST['event_id'] ?? 0);
    if (!$event_id) wp_send_json_error(['message' => 'Invalid event']);

    $reply = sanitize_text_field($_POST['reply'] ?? 'pending');
    if (!in_array($reply, ['yes', 'no', 'pending'], true)) $reply = 'pending';

    $companions = intval($_POST['companions'] ?? 0);
    if ($companions < 0) $companions = 0;

    $note = sanitize_text_field($_POST['note'] ?? '');

    // الضيف: خذ الهاتف من cookie بعد المرور من access gate
    $phone_cookie = 'pge_event_phone_' . $event_id;
    $guest_phone = isset($_COOKIE[$phone_cookie]) ? preg_replace('/\D+/', '', (string) $_COOKIE[$phone_cookie]) : '';

    // المضيف/المدير ممكن يمرر phone (اختياري)
    if ((current_user_can('administrator') || get_current_user_id() === (int) get_post_field('post_author', $event_id)) && !empty($_POST['guest_phone'])) {
        $guest_phone = preg_replace('/\D+/', '', sanitize_text_field($_POST['guest_phone']));
    }

    if ($guest_phone === '') wp_send_json_error(['message' => 'No guest phone']);

    // تحقق أن الرقم موجود ضمن المدعوين (إلا لو مضيف/أدمن)
    $is_host = current_user_can('administrator') || (get_current_user_id() && get_current_user_id() === (int) get_post_field('post_author', $event_id));
    if (!$is_host) {
        $invited = get_post_meta($event_id, '_pge_invited_phones', true);
        $invited_list = [];

        if (is_array($invited)) $invited_list = $invited;
        else {
            $raw = str_replace(["\r\n", "\r"], "\n", (string) $invited);
            $invited_list = array_filter(array_map('trim', explode("\n", $raw)));
        }

        $invited_norm = array_map(fn($p) => preg_replace('/\D+/', '', (string)$p), $invited_list);

        if (!in_array($guest_phone, $invited_norm, true)) {
            wp_send_json_error(['message' => 'Phone not invited']);
        }
    }

    global $wpdb;
    $table = $wpdb->prefix . 'pge_event_rsvps';

    // Upsert (بسبب UNIQUE)
    $data = [
        'event_id' => $event_id,
        'guest_phone' => $guest_phone,
        'companions' => $companions,
        'note' => $note,
        'reply' => $reply,
    ];

    $formats = ['%d', '%s', '%d', '%s', '%s'];

    $existing_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE event_id=%d AND guest_phone=%s LIMIT 1",
        $event_id,
        $guest_phone
    ));

    if ($existing_id) {
        $wpdb->update($table, $data, ['id' => (int)$existing_id], $formats, ['%d']);
    } else {
        $wpdb->insert($table, $data, $formats);
    }

    wp_send_json_success(['message' => 'RSVP saved']);
}

// Check-in (للمضيف فقط)
add_action('wp_ajax_pge_checkin_submit', function () {
    if (!current_user_can('administrator')) {
        $event_id = absint($_POST['event_id'] ?? 0);
        $author_id = (int) get_post_field('post_author', $event_id);
        if (!$event_id || get_current_user_id() !== $author_id) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
    }

    $event_id = absint($_POST['event_id'] ?? 0);
    $phone = preg_replace('/\D+/', '', sanitize_text_field($_POST['guest_phone'] ?? ''));
    if (!$event_id || $phone === '') wp_send_json_error(['message' => 'Invalid']);

    global $wpdb;
    $table = $wpdb->prefix . 'pge_event_rsvps';

    $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (event_id, guest_phone, reply, checked_in, checked_in_at)
         VALUES (%d, %s, 'pending', 1, NOW())
         ON DUPLICATE KEY UPDATE checked_in=1, checked_in_at=NOW()",
        $event_id,
        $phone
    ));

    wp_send_json_success(['message' => 'Checked in']);
});
