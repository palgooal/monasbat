<?php
if (! defined('ABSPATH')) exit;

// 1. دعم القالب وتنسيق الاتجاه
function pgevents_setup()
{
    add_theme_support('post-thumbnails');
    add_theme_support('title-tag');

    // تسجيل القوائم لتمكين التحكم بها من لوحة التحكم
    register_nav_menus([
        'primary' => __('Main Menu', 'pgevents'),
    ]);
}
add_action('after_setup_theme', 'pgevents_setup');

// 2. التحقق من وجود الإضافة الأساسية
add_action('admin_notices', function () {
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    if (! is_plugin_active('pgevents-core/pgevents-core.php')) {
        echo '<div class="notice notice-error"><p>تنبيه: قالب PgEvents Pro يتطلب تفعيل إضافة <b>PgEvents Core</b> ليعمل بشكل صحيح.</p></div>';
    }
});

// 3. استدعاء Tailwind v4 و FontAwesome والخطوط
function pgevents_enqueue_assets()
{
    // خطوط
    wp_enqueue_style(
        'pge-fonts',
        'https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&family=Inter:wght@400;700&display=swap',
        [],
        null
    );

    // FontAwesome
    wp_enqueue_style(
        'font-awesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
        [],
        '6.0.0'
    );

    // ✅ الأفضل: اعتمد على Tailwind compiled (output.css) فقط
    $css_path = get_stylesheet_directory() . '/assets/css/output.css';
    $css_url  = get_stylesheet_directory_uri() . '/assets/css/output.css';

    if (file_exists($css_path)) {
        wp_enqueue_style(
            'pge-tailwind',
            $css_url,
            [],
            filemtime($css_path)
        );
    } else {
        // احتياط: لو ما بنيت الملف بعد
        wp_enqueue_style(
            'pge-tailwind-missing',
            $css_url,
            [],
            wp_get_theme()->get('Version')
        );
    }

    // ✅ JS لصفحة الحدث فقط
    if (is_singular('pge_event')) {
        wp_enqueue_script(
            'pge-event',
            get_stylesheet_directory_uri() . '/assets/js/event.js',
            [],
            wp_get_theme()->get('Version'),
            true
        );
    }

    // ✅ تسجيل (بدون تحميل مباشر) سكربت قائمة الحساب المنسدلة لويدجت PGE Login Button.
    // التسجيل فقط هنا؛ Elementor هو من يُحمّله فعلياً عبر get_script_depends() في الويدجت
    // نفسه، وذلك فقط في الصفحات التي يظهر فيها الويدجت — بدون تحميل عام غير ضروري.
    wp_register_script(
        'pge-login-button',
        get_stylesheet_directory_uri() . '/assets/js/pge-login-button.js',
        [],
        wp_get_theme()->get('Version'),
        true
    );

    // مكوّن Hero الصفحة الرئيسية: تسجيل فقط. Elementor يحمّله عبر
    // get_style_depends() عند إدراج الودجت، لذلك لا يصل CSS أو الخط إلى
    // أي صفحة لا تعرض المكوّن.
    $hilwah_hero_css_path = get_stylesheet_directory() . '/assets/css/hilwah-home-hero.css';
    if (file_exists($hilwah_hero_css_path)) {
        wp_register_style(
            'pge-hilwah-home-hero',
            get_stylesheet_directory_uri() . '/assets/css/hilwah-home-hero.css',
            [],
            filemtime($hilwah_hero_css_path)
        );
    }

    // Native homepage preview assets stay unavailable unless the request has
    // passed the local/development and administrator gate below.
    if (pge_should_render_native_home()) {
        $native_home_css_path = get_stylesheet_directory() . '/assets/css/hilwah-home-native.css';
        if (file_exists($native_home_css_path)) {
            wp_enqueue_style(
                'pge-hilwah-home-native-preview',
                get_stylesheet_directory_uri() . '/assets/css/hilwah-home-native.css',
                ['pge-tailwind'],
                filemtime($native_home_css_path)
            );
        }

        $native_home_js_path = get_stylesheet_directory() . '/assets/js/hilwah-home-native.js';
        if (file_exists($native_home_js_path)) {
            wp_enqueue_script('pge-login-button');
            wp_enqueue_script(
                'pge-hilwah-home-native-preview',
                get_stylesheet_directory_uri() . '/assets/js/hilwah-home-native.js',
                ['pge-login-button'],
                filemtime($native_home_js_path),
                true
            );
        }
    }
}
add_action('wp_enqueue_scripts', 'pgevents_enqueue_assets');

// 4. توجيه المستخدمين ومنع دخولهم للوحة التحكم (لوجيك الصلاحيات الخاص بك)
add_action('admin_init', function () {
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (!current_user_can('administrator')) {
        wp_redirect(home_url('/profile/'));
        exit;
    }
});

// 5. إخفاء شريط الأدوات لغير المديرين
add_action('after_setup_theme', function () {
    if (!current_user_can('administrator') && !is_admin()) {
        show_admin_bar(false);
    }
});

/**
 * 6. دوال مساعدة للتحكم في RTL / LTR داخل القالب
 */
function pge_direction()
{
    return is_rtl() ? 'rtl' : 'ltr';
}

function pge_align()
{
    return is_rtl() ? 'text-right' : 'text-left';
}

/**
 * Elementor compatibility helpers.
 */
if (!function_exists('pge_is_elementor_built_page')) {
    function pge_is_elementor_built_page($post_id = 0)
    {
        $post_id = (int) ($post_id ?: get_the_ID());
        if ($post_id <= 0) return false;
        if (!did_action('elementor/loaded') || !class_exists('\Elementor\Plugin')) return false;

        $document = \Elementor\Plugin::$instance->documents->get($post_id);
        return $document && method_exists($document, 'is_built_with_elementor') && $document->is_built_with_elementor();
    }
}

/**
 * Limits the native homepage preview to explicitly local development work by
 * site administrators. This is intentionally a read-only rendering switch:
 * it does not alter Elementor data or any page metadata.
 */
if (!function_exists('pge_is_native_home_preview')) {
    function pge_is_native_home_preview()
    {
        if (is_admin() || !is_front_page()) {
            return false;
        }

        $environment = function_exists('wp_get_environment_type')
            ? wp_get_environment_type()
            : 'production';
        if (!in_array($environment, ['local', 'development'], true)) {
            return false;
        }

        if (!current_user_can('manage_options')) {
            return false;
        }

        $preview = isset($_GET['pge_native_home_preview'])
            ? sanitize_text_field(wp_unslash($_GET['pge_native_home_preview']))
            : '';

        return $preview === '1';
    }
}

/** Server-only release switch: absent, false or non-boolean values stay OFF. */
function pge_should_render_native_home()
{
    if (is_admin() || !is_front_page()) {
        return false;
    }

    return (defined('PGE_NATIVE_HOME_PUBLIC') && PGE_NATIVE_HOME_PUBLIC === true)
        || pge_is_native_home_preview();
}

// The query is resolved on wp, before template output. Public rendering is
// deliberately independent of these authorized-preview protections.
function pge_protect_native_home_preview()
{
    if (!pge_is_native_home_preview()) {
        return;
    }

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);
    add_filter('wp_robots', 'pge_native_home_preview_robots');
}

function pge_native_home_preview_robots($robots)
{
    unset($robots['index'], $robots['follow']);
    $robots['noindex'] = true;
    $robots['nofollow'] = true;
    return $robots;
}
add_action('wp', 'pge_protect_native_home_preview', 1);

function pge_render_native_home_preview_header()
{
    get_template_part('template-parts/home-native/header');
}

// HFE registers its renderer on wp at priority 10. Keep its document wrapper
// and footer intact, replacing only the header when Native is selected.
add_action('wp', function () {
    if (!pge_should_render_native_home()) {
        return;
    }

    $priority = has_action('hfe_header', 'hfe_render_header');
    if ($priority === false) {
        return;
    }

    remove_action('hfe_header', 'hfe_render_header', $priority);
    if (has_action('hfe_header', 'pge_render_native_home_preview_header') === false) {
        add_action('hfe_header', 'pge_render_native_home_preview_header', $priority);
    }
}, 20);

add_action('elementor/theme/register_locations', function ($elementor_theme_manager) {
    if (is_object($elementor_theme_manager) && method_exists($elementor_theme_manager, 'register_all_core_location')) {
        $elementor_theme_manager->register_all_core_location();
    }
});

add_action('elementor/widgets/register', function ($widgets_manager) {
    $widgets = [
        [
            'file'  => get_stylesheet_directory() . '/inc/elementor/widgets/class-pge-login-screen-widget.php',
            'class' => '\PGE_Login_Screen_Widget',
        ],
        [
            'file'  => get_stylesheet_directory() . '/inc/elementor/widgets/class-pge-login-button-widget.php',
            'class' => '\PGE_Login_Button_Widget',
        ],
        [
            'file'  => get_stylesheet_directory() . '/inc/elementor/widgets/class-pge-packages-widget.php',
            'class' => '\PGE_Packages_Widget',
        ],
        [
            'file'  => get_stylesheet_directory() . '/inc/elementor/widgets/class-pge-package-details-widget.php',
            'class' => '\PGE_Package_Details_Widget',
        ],
        [
            'file'  => get_stylesheet_directory() . '/inc/elementor/widgets/class-pge-hilwah-home-hero-widget.php',
            'class' => '\PGE_Hilwah_Home_Hero_Widget',
        ],
    ];

    foreach ($widgets as $widget) {
        if (empty($widget['file']) || empty($widget['class']) || !file_exists($widget['file'])) {
            continue;
        }

        require_once $widget['file'];
        if (!class_exists($widget['class'])) {
            continue;
        }

        $instance = new $widget['class']();
        if (method_exists($widgets_manager, 'register')) {
            $widgets_manager->register($instance);
            continue;
        }

        if (method_exists($widgets_manager, 'register_widget_type')) {
            $widgets_manager->register_widget_type($instance);
        }
    }
});

/**
 * استقبال تحديثات الملف الشخصي عبر Ajax
 */
// Redirect built-in login URLs to the custom /login/ route.
add_filter('login_url', function ($login_url, $redirect, $force_reauth) {
    if (!defined('PGE_PATH')) {
        return $login_url;
    }

    $custom_login_url = home_url('/login/');

    if (!empty($redirect)) {
        $custom_login_url = add_query_arg('redirect_to', $redirect, $custom_login_url);
    }

    if ($force_reauth) {
        $custom_login_url = add_query_arg('reauth', '1', $custom_login_url);
    }

    return $custom_login_url;
}, 10, 3);

// Redirect built-in registration URLs to the custom /register/ route.
add_filter('register_url', function ($register_url) {
    if (!defined('PGE_PATH')) {
        return $register_url;
    }

    return home_url('/register/');
}, 10, 1);

// Redirect built-in lost password URLs to the custom /forgot-password/ route.
add_filter('lostpassword_url', function ($lostpassword_url, $redirect) {
    if (!defined('PGE_PATH')) {
        return $lostpassword_url;
    }

    $custom_lost_url = home_url('/forgot-password/');

    if (!empty($redirect)) {
        $custom_lost_url = add_query_arg('redirect_to', $redirect, $custom_lost_url);
    }

    return $custom_lost_url;
}, 10, 2);

add_action('wp_ajax_pge_update_user_profile', function () {
    if (!is_user_logged_in()) wp_send_json_error();
    $user_id = get_current_user_id();
    if (isset($_POST['pge_bio'])) {
        update_user_meta($user_id, 'pge_bio', sanitize_textarea_field($_POST['pge_bio']));
    }
    if (isset($_POST['pge_cover_url'])) {
        update_user_meta($user_id, 'pge_cover_url', esc_url_raw($_POST['pge_cover_url']));
    }
    wp_send_json_success();
});
