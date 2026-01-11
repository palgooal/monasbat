<?php

function kleo_get_required_plugins() {

	/* Delete plugin version transient on Install plugins page */
	$kleo_rem_plugin_transient = false;

	$required_plugins = array(
		array(
			'name'               => 'K Elements',
			// The plugin name
			'slug'               => 'k-elements',
			// The plugin slug (typically the folder name)
			'source'             => kleo_get_plugin_src( 'k-elements' ),
			// The plugin source
			'required'           => true,
			// If false, the plugin is only 'recommended' instead of required
			//'version'            => kleo_get_plugin_version( 'k-elements' ),
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Part of KLEO theme, it adds shortcodes and various functionality.',
		),
		array(
			'name'               => 'K Fonts',
			// The plugin name
			'slug'               => 'k-fonts',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => kleo_get_plugin_version( 'k-fonts' ),
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'source'             => kleo_get_plugin_src( 'k-fonts' ),
			// The plugin source
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Add custom fonts to KLEO theme.',
		),
		/*array(
			'name'               => 'Stax Header Builder',
			// The plugin name
			'slug'               => 'stax',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Build your header in front-end area, unlimited options.',
		),*/
		array(
			'name'               => 'Buddypress',
			// The plugin name
			'slug'               => 'buddypress',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '2.3.2.1',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Build any type of community website with member profiles, activity streams, user groups, messaging, and more.',
		),

		array(
			'name'               => 'bbPress',
			// The plugin name
			'slug'               => 'bbpress',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Allows you to create a forum on your WordPress site',
		),
		array(
			'name'               => 'WPBakery Page Builder',
			// The plugin name
			'slug'               => 'js_composer',
			// The plugin slug (typically the folder name)
			'version'            => kleo_get_plugin_version( 'js_composer' ),
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'source'             => kleo_get_plugin_src( 'js_composer' ),
			// The plugin source
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Build pages with an advanced Drag&Drop interface',
		),
		array(
			'name'               => 'Revolution Slider',
			// The plugin name
			'slug'               => 'revslider',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => kleo_get_plugin_version( 'revslider' ),
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'source'             => kleo_get_plugin_src( 'revslider' ),
			// The plugin source
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Create advanced and beautiful sliders and also one page sites.',
		),
		array(
			'name'               => 'Youzify - Extend BuddyPress User Profile',
			// The plugin name
			'slug'               => 'youzify',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => kleo_get_plugin_version( 'youzify' ),
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'source'             => kleo_get_plugin_src( 'youzify' ),
			// The plugin source
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Adds new options and features to BuddyPress.',
		),
		array(
			'name'               => 'HubSpot All-In-One Marketing',
			// The plugin name
			'slug'               => 'leadin',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			// The plugin source
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'The ultimate free plugin for Forms, Popups, Live Chat.',
		),
		array(
			'name'               => 'Go Pricing',
			// The plugin name
			'slug'               => 'go_pricing',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => kleo_get_plugin_version( 'go_pricing' ),
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'source'             => kleo_get_plugin_src( 'go_pricing' ),
			// The plugin source
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'You can build amazing pricing & compare tables with this plugin.',
		),
		array(
			'name'               => 'Essential Grid',
			'slug'               => 'essential-grid',
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => kleo_get_plugin_version( 'essential-grid' ),
			'source'             => kleo_get_plugin_src( 'essential-grid' ),
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Display various content formats in a highly customizable grid.',
		),
		array(
			'name'               => 'KLEO Monetizer',
			// The plugin name
			'slug'               => 'sq-kleo-monetizer',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => kleo_get_plugin_version( 'sq-kleo-monetizer' ),
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'source'             => kleo_get_plugin_src( 'sq-kleo-monetizer' ),
			// The plugin source
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Add banners or content in different site areas. See Theme options - Monetizer.',
		),
		array(
			'name'               => 'Sidebar Generator',
			// The plugin name
			'slug'               => 'sq-sidebar-generator',
			// The plugin slug (typically the folder name)
			'version'            => kleo_get_plugin_version( 'sq-sidebar-generator' ),
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'source'             => kleo_get_plugin_src( 'sq-sidebar-generator' ),
			// The plugin source
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Generates as many sidebars as you need. Then place them on any page you wish.',
		),
		array(
			'name'               => 'Envato Market - Theme updates',
			// The plugin name
			'slug'               => 'envato-market',
			// The plugin slug (typically the folder name)
			'source'             => 'https://envato.github.io/wp-envato-market/dist/envato-market.zip',
			// The plugin source
			'required'           => true,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '2.0.1',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Automatic theme updates from Envato Market.',
		),
		array(
			'name'               => 'rtMedia',
			// The plugin name
			'slug'               => 'buddypress-media',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Allows BuddyPress users to create image, video or audio galleries.',
		),
		array(
			'name'               => 'WooCommerce',
			// The plugin name
			'slug'               => 'woocommerce',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '2.4.5',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Create an advanced Shop right in your WordPress site. ',
		),
		array(
			'name'               => 'YITH WooCommerce Wishlist',
			// The plugin name
			'slug'               => 'yith-woocommerce-wishlist',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '1.1.2',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Adds Wishlist functionality to your WooCommerce shop',
		),
		array(
			'name'               => 'Paid Memberships Pro',
			// The plugin name
			'slug'               => 'paid-memberships-pro',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '1.8',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Add memberships levels and create access restrictions for your users.',
		),
		array(
			'name'               => 'BP Profile Search',
			// The plugin name
			'slug'               => 'bp-profile-search',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '4.3.1',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Search your BuddyPress Members Directory by profile fields(used on Get Connected demo).',
		),
		array(
			'name'               => 'MailChimp for WordPress',
			// The plugin name
			'slug'               => 'mailchimp-for-wp',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '3.1',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Subscribe your WordPress site visitors to your MailChimp lists, with ease.',
		),
		array(
			'name'               => 'Geodirectory',
			// The plugin name
			'slug'               => 'geodirectory',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '1.6.5',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Create huge location-based business directories',
		),
		array(
			'name'               => 'Contact Form 7',
			// The plugin name
			'slug'               => 'contact-form-7',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '4.4.2',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Simple but flexible contact form plugin.',
		),
		array(
			'name'               => 'Sensei',
			// The plugin name
			'slug'               => 'sensei',
			// The plugin slug (typically the folder name)
			'required'           => false,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '3.8.1',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'source'             => 'https://github.com/Automattic/sensei/releases/download/version%2F3.8.1/sensei-lms.zip',
			// The plugin source
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'A learning management plugin that allows you to sell courses.',
		),
	);

	return $required_plugins;
}

$kleo_theme = SVQ_FW::instance();

//add required plugins
$kleo_theme->tgm_plugins = kleo_get_required_plugins();
require_once SVQ_FW_DIR . '/lib/class-tgm-plugin-activation.php';
add_action( 'tgmpa_register', array( $kleo_theme, 'required_plugins' ) );


/**
 * Get the source of the plugin depending on the version available
 *
 * @param string $name
 *
 * @return string
 */
function kleo_get_plugin_src( $name ) {

	$api_url = SVQ_FW::get_config( 'updates_api_url' );
	$api_url = add_query_arg( 'action', 'download', $api_url );
	$api_url = add_query_arg( 'slug', $name . '.zip', $api_url );

	return $api_url;
}

/**
 * @param string $name Plugin name
 *
 * @return mixed|string
 */
function kleo_get_plugin_version( $name ) {

	$version        = '';
	$transient_name = 'kleo_plugin_v_' . $name;
	if ( isset( $_GET['sq_force_updates'] ) ) {
		delete_transient( $transient_name );
	}

	if ( $version = get_transient( $transient_name ) ) {
		return $version;
	}

	$url = SVQ_FW::get_config( 'updates_api_url' );
	$url = add_query_arg( 'action', 'get_metadata', $url );
	$url = add_query_arg( 'slug', $name, $url );

	$purchase_get = wp_remote_get( $url );

	// Check for error
	if ( ! is_wp_error( $purchase_get ) ) {
		$response = wp_remote_retrieve_body( $purchase_get );

		// Check for error
		if ( ! is_wp_error( $response ) ) {
			$response = json_decode( $response, false );
			if ( isset( $response->version ) ) {
				$version = $response->version;
			}
		}
	}

	set_transient( $transient_name, $version, 43200 );

	return $version;
}

add_filter( 'http_request_args', static function ( $args, $url ) {
	$api_url = SVQ_FW::get_config( 'updates_api_url' );
	if ( $api_url && strpos( $url, (string) $api_url ) !== false ) {
		$args['headers']['Theme-Version'] = SVQ_THEME_VERSION;
		$args['headers']['Theme-Id']      = SVQ_FW::get_config( 'item_id' );
		$args['headers']['Package-Code']  = SVQ_FW::get_saved_purchase_code();
	}

	return $args;
}, 10, 2 );
