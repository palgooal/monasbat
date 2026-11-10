<?php
/** Independent administrative controls for preview and public rendering. */
defined('ABSPATH') || exit;

function pge_native_home_public_enabled()
{
    if (defined('PGE_NATIVE_HOME_PUBLIC')) {
        return PGE_NATIVE_HOME_PUBLIC === true;
    }
    return in_array(get_option('pge_native_home_public', '0'), ['1', 1], true);
}

function pge_save_native_public()
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wp_die('طلب غير مسموح.', '', ['response' => 405]);
    }
    if (!current_user_can('manage_options')) {
        wp_die('غير مصرح.', '', ['response' => 403]);
    }
    check_admin_referer('pge_save_native_public');
    if (defined('PGE_NATIVE_HOME_PUBLIC')) {
        wp_die('النشر محكوم بالتصريح الخادمي.', '', ['response' => 403]);
    }
    if (array_key_exists('enabled', $_POST) && !in_array($_POST['enabled'], ['1', '0'], true)) {
        wp_die('قيمة غير صالحة.', '', ['response' => 400]);
    }
    $value = $_POST['enabled'] ?? '0';
    update_option('pge_native_home_public', $value, false);
    if (get_option('pge_native_home_public', null) !== $value) {
        wp_die('تعذر التحقق من حفظ حالة النشر. أعد المحاولة.', '', ['response' => 500]);
    }
    wp_safe_redirect(admin_url('options-general.php?page=pge-native-preview'));
    exit;
}
add_action('admin_post_pge_save_native_public', 'pge_save_native_public');

function pge_native_home_production_preview_enabled()
{
    if (defined('PGE_NATIVE_HOME_PRODUCTION_PREVIEW')) {
        return PGE_NATIVE_HOME_PRODUCTION_PREVIEW === true;
    }
    // WordPress may return an integer immediately after update, or a string from DB.
    return in_array(get_option('pge_native_home_production_preview', '0'), [1, '1'], true);
}

add_action('admin_menu', function () {
    add_options_page('معاينة حلوة', 'معاينة حلوة', 'manage_options', 'pge-native-preview', 'pge_native_preview_admin_page');
});

function pge_native_preview_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('غير مصرح.', '', ['response' => 403]);
    }
    $locked = defined('PGE_NATIVE_HOME_PRODUCTION_PREVIEW');
    ?>
    <div class="wrap">
        <h1>معاينة حلوة</h1>
        <p>هذه المعاينة للمدير المصرّح له فقط، ولا تنشر الصفحة للعامة.</p>
        <?php if ($locked) : ?>
            <p><strong><?php echo esc_html(pge_native_home_production_preview_enabled()
                ? 'التصريح الخادمي يفرض تفعيل المعاينة ويتقدم على الإعداد الإداري.'
                : 'التصريح الخادمي يمنع المعاينة ويتقدم على الإعداد الإداري.'); ?></strong></p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="pge_save_native_preview">
            <?php wp_nonce_field('pge_save_native_preview'); ?>
            <p><label><input type="checkbox" name="enabled" value="1" <?php checked(pge_native_home_production_preview_enabled()); disabled($locked); ?>> معاينة الصفحة الرئيسية الجديدة على الإنتاج</label></p>
            <?php if (!$locked) { submit_button('حفظ'); } ?>
        </form>
        <?php if (pge_native_home_production_preview_enabled()) : ?>
            <p><a href="<?php echo esc_url(add_query_arg('pge_native_home_preview', '1', home_url('/'))); ?>">فتح معاينة الصفحة الرئيسية</a></p>
        <?php endif; ?>
        <hr>
        <h2>تفعيل الصفحة الرئيسية الجديدة للزوار</h2>
        <p>هذا الإعداد مستقل عن المعاينة. تعطيله يعيد الصفحة الأصلية للطلبات الجديدة التي تصل إلى WordPress، ولا يمحو صفحات مخزنة سابقًا.</p>
        <p><strong><?php echo esc_html(pge_native_home_public_enabled() ? 'حالة النشر الفعلية: مفعّل' : 'حالة النشر الفعلية: معطّل'); ?></strong></p>
        <?php if (defined('PGE_NATIVE_HOME_PUBLIC')) : ?>
            <p>الثابت الخادمي يتحكم بالنشر؛ لا يمكن تغييره من هذه الصفحة. القيم غير المنطقية تعني التعطيل.</p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="pge_save_native_public">
            <?php wp_nonce_field('pge_save_native_public'); ?>
            <p><label><input type="checkbox" name="enabled" value="1" <?php checked(pge_native_home_public_enabled()); disabled(defined('PGE_NATIVE_HOME_PUBLIC')); ?>> تفعيل الصفحة الرئيسية الجديدة للزوار</label></p>
            <?php if (!defined('PGE_NATIVE_HOME_PUBLIC')) { submit_button('حفظ حالة النشر'); } ?>
        </form>
    </div>
    <?php
}

function pge_save_native_preview()
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wp_die('طلب غير مسموح.', '', ['response' => 405]);
    }
    if (!current_user_can('manage_options')) {
        wp_die('غير مصرح.', '', ['response' => 403]);
    }
    check_admin_referer('pge_save_native_preview');
    if (defined('PGE_NATIVE_HOME_PRODUCTION_PREVIEW')) {
        wp_die('الإعداد محكوم بالتصريح الخادمي.', '', ['response' => 403]);
    }
    if (isset($_POST['enabled']) && $_POST['enabled'] !== '1') {
        wp_die('قيمة غير صالحة.', '', ['response' => 400]);
    }
    update_option('pge_native_home_production_preview', isset($_POST['enabled']) ? '1' : '0', false);
    wp_safe_redirect(admin_url('options-general.php?page=pge-native-preview'));
    exit;
}
add_action('admin_post_pge_save_native_preview', 'pge_save_native_preview');
