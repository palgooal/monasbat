<?php
if (!defined('ABSPATH')) exit;

add_action('show_user_profile', 'pge_extra_user_profile_fields');
add_action('edit_user_profile', 'pge_extra_user_profile_fields');

function pge_extra_user_profile_fields($user)
{ ?>
    <h3>إعدادات إضافية لـ PgEvents</h3>
    <table class="form-table">
        <tr>
            <th><label for="pge_bio">النبذة الشخصية</label></th>
            <td><textarea name="pge_bio" id="pge_bio" rows="5" cols="30"><?php echo esc_html(get_the_author_meta('pge_bio', $user->ID)); ?></textarea></td>
        </tr>
        <tr>
            <th><label for="pge_cover_url">رابط صورة الغلاف</label></th>
            <td><input type="text" name="pge_cover_url" id="pge_cover_url" value="<?php echo esc_url(get_the_author_meta('pge_cover_url', $user->ID)); ?>" class="regular-text" /></td>
        </tr>
    </table>
<?php }

function pge_save_extra_user_profile_fields($user_id)
{
    if (!current_user_can('edit_user', $user_id)) return false;
    update_user_meta($user_id, 'pge_bio', $_POST['pge_bio']);
    update_user_meta($user_id, 'pge_cover_url', esc_url_raw($_POST['pge_cover_url']));
}
add_action('personal_options_update', 'pge_save_extra_user_profile_fields');
add_action('edit_user_profile_update', 'pge_save_extra_user_profile_fields');
