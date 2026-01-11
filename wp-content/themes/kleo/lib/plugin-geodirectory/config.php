<?php

// Run all hooks and filters after theme is loaded
add_action( 'after_setup_theme', 'kleo_geodir_init', 999 );
function kleo_geodir_init() {
// Add specific class in Geo Directory pages

	// Main wrapper open / close
	remove_action( 'geodir_wrapper_open', 'geodir_action_wrapper_open', 10 );
	add_action( 'geodir_wrapper_open', 'kleo_geodir_action_wrapper_open', 9 );
	remove_action( 'geodir_wrapper_close', 'geodir_action_wrapper_close', 10 );
	add_action( 'geodir_wrapper_close', 'kleo_geodir_action_wrapper_close', 9 );

	// Remove GeoDirectory home page breadcrumbs
	remove_action( 'geodir_home_before_main_content', 'geodir_breadcrumb', 20 );
	remove_action( 'geodir_location_before_main_content', 'geodir_breadcrumb', 20 );

	// Remove GeoDirectory listing page title and breadcrumbs
	remove_action( 'geodir_listings_before_main_content', 'geodir_breadcrumb', 20 );
	remove_action( 'geodir_listings_page_title', 'geodir_action_listings_title', 10 );

	// Remove GeoDirectory details page title and breadcrumbs
	remove_action( 'geodir_detail_before_main_content', 'geodir_breadcrumb', 20 );
	remove_action( 'geodir_details_main_content', 'geodir_action_page_title', 20 );

	// Remove GeoDirectory search page title and breadcrumbs
	remove_action( 'geodir_search_before_main_content', 'geodir_breadcrumb', 20 );
	remove_action( 'geodir_search_page_title', 'geodir_action_search_page_title', 10 );

	// Remove GeoDirectory author page title and breadcrumbs
	remove_action( 'geodir_author_before_main_content', 'geodir_breadcrumb', 20 );
	remove_action( 'geodir_author_page_title', 'geodir_action_author_page_title', 10 );

	remove_action( 'geodir_add_listing_page_title', 'geodir_action_add_listing_page_title', 10 );

}


// Add specific class in Geo Directory pages
add_action( 'wp', 'kleo_geodir_body_class_init', 999 );
function kleo_geodir_body_class_init() {
	if ( function_exists( 'geodir_is_geodir_page' ) ) {
		if ( geodir_is_geodir_page() || is_page( get_option( 'geodir_location_page' ) ) ) {
			add_filter( 'body_class', 'kleo_geodir_body_class' );
			function kleo_geodir_body_class( $classes ) {
				$classes[] = 'kleo-geodir';

				return $classes;
			}
		}
	}
}

// Main wrapper open
function kleo_geodir_action_wrapper_open() {
	kleo_switch_layout( 'no' );
	get_template_part( 'page-parts/general-title-section' );
	get_template_part( 'page-parts/general-before-wrap' );
}

// Main wrapper close
function kleo_geodir_action_wrapper_close() {
	get_template_part( 'page-parts/general-after-wrap' );
}

// Add GeoDirectory styling
add_action( 'wp_enqueue_scripts', 'kleo_geodir_css', 999 );
function kleo_geodir_css() {
	wp_register_style( 'kleo-geodir', trailingslashit( get_template_directory_uri() ) . 'lib/plugin-geodirectory/kleo-geodir.css', array(), SVQ_THEME_VERSION, 'all' );
	wp_enqueue_style( 'kleo-geodir' );
}

add_filter( 'geodir_location_switcher_menu_li_class', 'kleo_geodir_menu_li_class', 10, 1 );
add_filter( 'geodir_menu_li_class', 'kleo_geodir_menu_li_class', 10, 1 );
function kleo_geodir_menu_li_class( $class ) {
	if ( strpos( $class, 'menu-item-has-children' ) !== false || strpos( $class, 'gd-location-switcher' ) !== false ) {
		$class .= " kleo-gd-dropdown dropdown ";
	}

	return $class;
}

add_filter( 'geodir_location_switcher_menu_sub_ul_class', 'kleo_geodir_sub_menu_ul_class', 10, 1 );
add_filter( 'geodir_sub_menu_ul_class', 'kleo_geodir_sub_menu_ul_class', 10, 1 );
function kleo_geodir_sub_menu_ul_class( $class ) {
	$class .= " dropdown-menu ";

	return $class;
}


add_action( 'wp_footer', 'kleo_geodir_wp_footer' );
function kleo_geodir_wp_footer() {
	?>
    <script>
        jQuery(document).ready(function () {
            jQuery('.kleo-gd-dropdown > a').addClass('js-activated').append('<span class="caret"></span>');
        });
    </script>
	<?php
}

add_action( 'geodir_article_close', 'kleo_geodir_social_share' );
function kleo_geodir_social_share() {
	get_template_part( 'page-parts/posts-social-share' );
}


/* V1 logic */
if ( defined( 'GEODIRECTORY_VERSION' ) && version_compare( GEODIRECTORY_VERSION, '2.0', '<' ) ) {

	function kleo_geodir_breadcrumb_separator() {
		return '<span class="sep"></span>';
	}

	add_filter( 'geodir_breadcrumb_separator', 'kleo_geodir_breadcrumb_separator' );

	function kleo_geodir_breadcrumb_data() {
		ob_start();
		geodir_breadcrumb();
		$breadcrumb = ob_get_clean();
		$breadcrumb = str_replace( '<div class="geodir-breadcrumb clearfix">', '<div class="kleo_framework breadcrumb kleo-custom-breadcrumb">', $breadcrumb );
		$breadcrumb = str_replace( '<ul id="breadcrumbs"><li>', '', $breadcrumb );
		$breadcrumb = str_replace( '</li></ul>', '', $breadcrumb );

		return $breadcrumb;
	}

	function kleo_geodir_breadcrumb_replace() {
		if ( geodir_is_geodir_page() || is_page( get_option( 'geodir_location_page' ) ) ) {
			add_filter( 'kleo_breadcrumb_data', 'kleo_geodir_breadcrumb_data' );
		}
	}

	add_filter( 'wp', 'kleo_geodir_breadcrumb_replace' );
}


/***************************************************
 * :: Custom main menu select for each page
 ***************************************************/
if ( ! is_admin() ) {
	add_filter( 'wp_nav_menu_args', 'kleo_geodir_set_custom_menu' );
}
/**
 * @param array $args
 *
 * @return array
 */
function kleo_geodir_set_custom_menu( $args = [] ) {

	if ( 'primary' != $args['theme_location'] ) {
		return $args;
	}

	global $post;

	if ( ! empty( $post ) ) {

		if ( 'primary' == $args['theme_location'] ) {

			global $geodirectory, $post;
			$id = $post->ID;


			$setting      = '';
			$general_page = false;

			if ( get_post_type() == 'gd_place' ) {
				$setting = sq_option( 'geodir_menu_place', 'default' );
			} elseif ( ! empty( $geodirectory->settings['page_add'] ) && $geodirectory->settings['page_add'] == $id ) {
				$setting = sq_option( 'geodir_menu_add', 'default' );
			} elseif ( ! empty( $geodirectory->settings['page_location'] ) && $geodirectory->settings['page_location'] == $id ) {
				$setting = sq_option( 'geodir_menu_location', 'default' );
			} elseif ( ! empty( $geodirectory->settings['page_search'] ) && $geodirectory->settings['page_search'] == $id ) {
				$setting = sq_option( 'geodir_menu_search', 'default' );
			} elseif ( ! empty( $geodirectory->settings['page_details'] ) && $geodirectory->settings['page_details'] == $id ) {
				$setting = sq_option( 'geodir_menu_details', 'default' );
			} elseif ( ! empty( $geodirectory->settings['page_archive'] ) && $geodirectory->settings['page_archive'] == $id ) {
				$setting = sq_option( 'geodir_menu_archive', 'default' );
			} elseif ( ! empty( $geodirectory->settings['page_archive_item'] ) && $geodirectory->settings['page_archive_item'] == $id ) {
				$setting = sq_option( 'geodir_menu_archive_item', 'default' );
			} elseif ( function_exists( 'geodir_is_cpt_template_page' ) && geodir_is_cpt_template_page( $id ) ) {
				$setting      = sq_option( 'geodir_menu_default', 'default' );
				$general_page = true;
			}

			if ( $setting !== '' ) {
				if ( $general_page !== true ) {
					if ( $setting == 'default' ) {
						$general_setting = sq_option( 'geodir_menu_default', 'default' );
						$setting         = $general_setting;
					}
				}
				if ( is_nav_menu( $setting ) ) {
					$args['menu'] = $setting;
				}
			}

		}

	} // END if(!empty($post))

	return $args;
}

add_filter( 'kleo_sidebar_name', function ( $name ) {

	if ( ! function_exists( 'geodir_is_geodir_page' ) || ! geodir_is_geodir_page() ) {
		return $name;
	}

	global $geodirectory, $post;

	if ( ! isset( $post->ID ) ) {
		return $name;
	}

	$id           = $post->ID;
	$setting      = '';
	$general_page = false;

	if ( get_post_type() == 'gd_place' and ! empty( sq_option( 'geodir_sidebar_place' ) ) ) {
		$setting = sq_option( 'geodir_sidebar_place', '' );
	} elseif ( get_post_type() == 'gd_place' and ! empty( $geodirectory->settings['page_add'] ) && $geodirectory->settings['page_add'] == $id ) {
		$setting = sq_option( 'geodir_sidebar_add', '' );
	} elseif ( ! empty( $geodirectory->settings['page_location'] ) && $geodirectory->settings['page_location'] == $id ) {
		$setting = sq_option( 'geodir_sidebar_location', '' );
	} elseif ( ! empty( $geodirectory->settings['page_search'] ) && $geodirectory->settings['page_search'] == $id ) {
		$setting = sq_option( 'geodir_sidebar_search', '' );
	} elseif ( ! empty( $geodirectory->settings['page_details'] ) && $geodirectory->settings['page_details'] == $id ) {
		$setting = sq_option( 'geodir_sidebar_details', '' );
	} elseif ( ! empty( $geodirectory->settings['page_archive'] ) && $geodirectory->settings['page_archive'] == $id ) {
		$setting = sq_option( 'geodir_sidebar_archive', '' );
	} elseif ( ! empty( $geodirectory->settings['page_archive_item'] ) && $geodirectory->settings['page_archive_item'] == $id ) {
		$setting = sq_option( 'geodir_sidebar_archive_item', '' );
	} elseif ( function_exists( 'geodir_is_cpt_template_page' ) && geodir_is_cpt_template_page( $id ) ) {
		$setting      = sq_option( 'geodir_sidebar_default', '' );
		$general_page = true;
	}

	// Set general value if is the case
	if ( ( $setting == 'default' || $setting == '' ) && $general_page !== true ) {
		$setting = sq_option( 'geodir_sidebar_default', '' );
	}

	if ( $setting != 'default' && $setting != '' ) {
		$name = $setting;
	}

	return $name;

} );


add_action( 'after_setup_theme', 'kleo_geodir_options', 14 );
function kleo_geodir_options() {

	if ( ! class_exists( 'Redux' ) ) {
		return;
	}

	$kleo_menus   = wp_get_nav_menus();
	$menu_options = [ 'default' => 'Site Default' ];

	foreach ( $kleo_menus as $menu ) {
		$menu_options[ $menu->slug ] = $menu->name;
	}

	$geodir_pages = [
		[
			'id'   => 'place',
			'name' => 'Place',
		],
		[
			'id'   => 'add',
			'name' => 'Add Listing',
		],
		[
			'id'   => 'location',
			'name' => 'Location',
		],
		[
			'id'   => 'search',
			'name' => 'Search',
		],
		[
			'id'   => 'details',
			'name' => 'Details',
		],
		[
			'id'   => 'archive',
			'name' => 'Archive',
		],
		[
			'id'   => 'archive_item',
			'name' => 'Archive item',
		]
	];
	$fields       = [];
	$fields[]     = [
		'id'       => 'geodir_menu_default',
		'type'     => 'select',
		'title'    => 'GeoDirectory Primary menu',
		'subtitle' => esc_html__( 'Default primary menu for all GeoDirectory pages', 'kleo' ),
		'options'  => $menu_options,
		'default'  => 'default',
	];

	$fields[] = [
		'id'       => 'geodir_sidebar_default',
		'type'     => 'select',
		'title'    => 'GeoDirectory Default sidebar',
		'subtitle' => esc_html__( 'Default sidebar for all GeoDirectory pages', 'kleo' ),
		'options'  => '',
		'data'     => 'sidebar',
		'default'  => '',
	];

	$menu_options['default'] = 'Default(Set above)';

	foreach ( $geodir_pages as $geodir_page ) {
		$fields[] = [
			'id'       => 'geodir_menu_' . $geodir_page['id'],
			'type'     => 'select',
			'title'    => $geodir_page['name'] . ' - Primary menu',
			'subtitle' => sprintf( esc_html__( 'Override primary menu for %s.', 'kleo' ), $geodir_page['name'] ),
			'options'  => $menu_options,
			'default'  => 'default',
		];

		$fields[] = [
			'id'       => 'geodir_sidebar_' . $geodir_page['id'],
			'type'     => 'select',
			'title'    => $geodir_page['name'] . ' - Sidebar',
			'subtitle' => sprintf( esc_html__( 'Override sidebar for %s.', 'kleo' ), $geodir_page['name'] ),
			'options'  => '',
			'data'     => 'sidebar',
			'default'  => 'default',
		];
	}

	$section = array(
		//'icon'       => 'el-globe',
		'icon_class' => 'icon-large',
		'title'      => esc_html__( 'GeoDirectory', 'kleo' ),
		'customizer' => false,
		'desc'       => '<p class="description">' . esc_html__( 'GeoDirectory plugin settings', 'kleo' ) . '</p>',
		'fields'     => $fields,
	);

	Redux::setSection( 'kleo_' . KLEO_DOMAIN, $section );
}