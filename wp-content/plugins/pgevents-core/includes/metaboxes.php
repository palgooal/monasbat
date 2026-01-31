<?php
if (!defined('ABSPATH')) exit;

function pge_add_event_metaboxes()
{
    add_meta_box('pge_event_details', 'إعدادات المناسبة والتواصل', 'pge_event_details_callback', 'pge_event', 'normal', 'high');
}
add_action('add_meta_boxes', 'pge_add_event_metaboxes');

function pge_event_details_callback($post)
{
    $date = get_post_meta($post->ID, '_pge_event_date', true);
    $location = get_post_meta($post->ID, '_pge_event_location', true);
    $host_phone = get_post_meta($post->ID, '_pge_host_phone', true);
    wp_nonce_field('pge_save_event_meta', 'pge_event_nonce');
?>
    <div style="padding: 10px 0;">
        <p>
            <label style="display:block; font-weight:bold; margin-bottom:5px;">تاريخ ووقت المناسبة:</label>
            <input type="datetime-local" name="pge_event_date" value="<?php echo esc_attr($date); ?>" style="width:100%; max-width:400px;">
        </p>
        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
        <p>
            <label style="display:block; font-weight:bold; margin-bottom:5px;">رابط موقع القاعة (Google Maps):</label>
            <input type="url" name="pge_event_location" value="<?php echo esc_url($location); ?>" placeholder="https://goo.gl/maps/..." style="width:100%; max-width:400px;">
        </p>
        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
        <p>
            <label style="display:block; font-weight:bold; margin-bottom:5px;">رقم واتساب المضيف:</label>
            <input type="text" name="pge_host_phone" value="<?php echo esc_attr($host_phone); ?>" placeholder="9665xxxxxxxx" style="width:100%; max-width:400px;">
        </p>
    </div>
<?php
}

function pge_save_event_meta($post_id)
{
    if (!isset($_POST['pge_event_nonce']) || !wp_verify_nonce($_POST['pge_event_nonce'], 'pge_save_event_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (isset($_POST['pge_event_date'])) update_post_meta($post_id, '_pge_event_date', sanitize_text_field($_POST['pge_event_date']));
    if (isset($_POST['pge_event_location'])) update_post_meta($post_id, '_pge_event_location', esc_url_raw($_POST['pge_event_location']));
    if (isset($_POST['pge_host_phone'])) update_post_meta($post_id, '_pge_host_phone', sanitize_text_field($_POST['pge_host_phone']));
}
add_action('save_post', 'pge_save_event_meta');
