<?php
/**
 * Homepage-only native header for the authorized local preview.
 *
 * Authentication URLs deliberately reuse WordPress helpers used by the
 * existing pge_login_button widget; this partial owns presentation only.
 */
defined('ABSPATH') || exit;

$home_url = home_url('/');
if (function_exists('pge_is_native_home_preview') && pge_is_native_home_preview()) {
    $home_url = add_query_arg('pge_native_home_preview', '1', $home_url);
}

$is_logged_in = is_user_logged_in();
$dashboard_url = home_url('/dashboard/');
$login_url = wp_login_url($dashboard_url);
$start_url = '#hilwah-packages';
$account_label = 'حسابي';
$account_trigger_id = '';
$account_menu_id = '';
$logout_url = '';

if ($is_logged_in) {
    $current_user = wp_get_current_user();
    if ($current_user && $current_user->exists()) {
        $account_label = trim((string) $current_user->display_name);
        if ($account_label === '') {
            $account_label = trim((string) $current_user->user_login);
        }
    }
    if ($account_label === '') {
        $account_label = 'حسابي';
    }

    $account_trigger_id = wp_unique_id('pge-native-account-trigger-');
    $account_menu_id = wp_unique_id('pge-native-account-menu-');
    $logout_url = wp_logout_url(home_url('/'));
}
?>
<header class="pge-native-home-header" dir="rtl" data-pge-native-header>
    <div class="pge-native-home-header__inner">
        <a class="pge-native-home-header__brand" href="<?php echo esc_url($home_url); ?>" aria-label="حلوة — الصفحة الرئيسية">
            <span class="pge-native-home-header__mark" aria-hidden="true">ح</span>
            <span class="pge-native-home-header__wordmark">
                <strong>حلوة</strong>
                <small dir="ltr">HILWAH</small>
            </span>
        </a>

        <button class="pge-native-home-header__toggle" type="button" aria-expanded="false" aria-controls="pge-native-home-navigation" data-pge-native-menu-toggle>
            <span class="screen-reader-text">فتح القائمة</span>
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>

        <div id="pge-native-home-navigation" class="pge-native-home-header__panel" data-pge-native-menu-panel>
            <nav class="pge-native-home-header__nav" aria-label="التنقل الرئيسي">
                <a href="#hilwah-features">المميزات</a>
                <a href="#hilwah-packages">الباقات</a>
            </nav>

            <div class="pge-native-home-header__actions">
                <?php if ($is_logged_in) : ?>
                    <div class="pge-native-home-header__account" data-pge-account-menu>
                        <button type="button" id="<?php echo esc_attr($account_trigger_id); ?>" class="pge-native-home-header__account-trigger" aria-haspopup="true" aria-expanded="false" aria-controls="<?php echo esc_attr($account_menu_id); ?>" data-pge-account-trigger>
                            <span class="pge-native-home-header__account-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.2"></circle><path d="M4.5 19.2v-.4a7.5 7.5 0 0 1 15 0v.4"></path></svg>
                            </span>
                            <span class="pge-native-home-header__account-name"><?php echo esc_html($account_label); ?></span>
                            <svg class="pge-account-chevron" aria-hidden="true" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"></path></svg>
                        </button>
                        <div id="<?php echo esc_attr($account_menu_id); ?>" class="pge-native-home-header__account-menu hidden" role="menu" aria-labelledby="<?php echo esc_attr($account_trigger_id); ?>" data-pge-account-dropdown>
                            <a href="<?php echo esc_url($dashboard_url); ?>" role="menuitem" data-pge-account-item>لوحة التحكم</a>
                            <a href="<?php echo esc_url($logout_url); ?>" role="menuitem" data-pge-account-item>تسجيل الخروج</a>
                        </div>
                    </div>
                <?php else : ?>
                    <a class="pge-native-home-header__login" href="<?php echo esc_url($login_url); ?>">تسجيل الدخول</a>
                <?php endif; ?>
                <a class="pge-native-home-header__start" href="<?php echo esc_url($start_url); ?>">ابدأ الآن</a>
            </div>
        </div>
    </div>
</header>
