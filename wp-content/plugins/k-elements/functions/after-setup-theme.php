<?php

/* Re-enable theme auto-update for Go Pricing Tables */
add_action( 'admin_init', 'kleo_go_pricing_enable_updates', 11 );
function kleo_go_pricing_enable_updates() {
	if ( class_exists( 'GW_GoPricing_Update' ) ) {
		remove_filter( 'pre_set_site_transient_update_plugins', array(
			GW_GoPricing_Update::instance(),
			'check_update'
		) );
		remove_filter( 'plugins_api', array( GW_GoPricing_Update::instance(), 'update_info' ), 10 );
	}
}

/* Disable VC auto-update */
add_action( 'admin_init', 'kleo_vc_disable_update', 9 );
function kleo_vc_disable_update() {
	if ( function_exists( 'vc_license' ) && function_exists( 'vc_updater' ) && ! vc_license()->isActivated() ) {

		remove_filter( 'upgrader_pre_download', array( vc_updater(), 'preUpgradeFilter' ), 10 );
		remove_filter( 'pre_set_site_transient_update_plugins', array(
			vc_updater()->updateManager(),
			'check_update',
		) );

	}
}

/* Add PMPRO Metaboxes to Sensei course and lesson */
if ( function_exists( 'pmpro_url' ) ) {
	function kleo_sensei_pmpro_metabox() {
		add_meta_box( 'pmpro_page_meta', 'Require Membership', 'pmpro_page_meta', 'course', 'side' );
		add_meta_box( 'pmpro_page_meta', 'Require Membership', 'pmpro_page_meta', 'lesson', 'side' );
	}

	add_action( 'init', 'kleo_sensei_pmpro_cpt_init', 20 );
	function kleo_sensei_pmpro_cpt_init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', 'kleo_sensei_pmpro_metabox' );
		}
	}
}


/*
 * Force URLs in srcset attributes into HTTPS scheme.
 * This is particularly useful when you're running a Flexible SSL frontend like Cloudflare
 */

add_filter( 'wp_calculate_image_srcset', 'kleo_ssl_srcset' );

if ( ! function_exists( 'kleo_ssl_srcset' ) ) {
	function kleo_ssl_srcset( $sources ) {
		if ( is_ssl() ) {
			foreach ( $sources as $source ) {
				$source['url'] = set_url_scheme( $source['url'], 'https' );
			}
		}

		return $sources;
	}
}

/* Remove all query strings from all static resources */

if ( sq_option( 'perf_remove_query', 0 ) == 1 ) {

	add_action( 'init', 'pre_remove_query_strings_static_resources' );

	if ( ! function_exists( 'pre_remove_query_strings_static_resources' ) ) {
		function pre_remove_query_strings_static_resources() {
			function remove_cssjs_ver( $src ) {
				if ( strpos( $src, '?ver=' ) ) {
					$src = remove_query_arg( 'ver', $src );
				}

				return $src;
			}

			add_filter( 'style_loader_src', 'remove_cssjs_ver', 10, 2 );
			add_filter( 'script_loader_src', 'remove_cssjs_ver', 10, 2 );
		}
	}
}

/***************************************************
 * :: Theme options link in Admin bar
 ***************************************************/

add_action( 'admin_bar_menu', 'kleo_add_adminbar_options', 100 );

/**
 * @param WP_Admin_Bar $admin_bar
 */
function kleo_add_adminbar_options( $admin_bar ) {
	if ( is_super_admin() && ! is_admin() ) {
		$admin_bar->add_menu( array(
			'id'    => 'theme-options',
			'title' => esc_html__( 'Theme options', 'kleo' ),
			'href'  => get_admin_url() . 'admin.php?page=kleo_options',
			'meta'  => array(
				'title'  => esc_html__( 'Theme options', 'kleo' ),
				'target' => '_blank',
			),
		) );
	}
}

// Sensei compat
add_action( 'wp_login', 'kleo_sensei_fix_redirect', 1 );

function kleo_sensei_fix_redirect() {
	if ( function_exists( 'kleo_remove_filters_for_class' ) && class_exists( 'Sensei_Teacher' ) ) {
		kleo_remove_filters_for_class( 'wp_login', 'Sensei_Teacher', 'teacher_login_redirect', 10 );
	}
}


/***************************************************
 * TOP TOOLBAR - ADMIN BAR
 * Enable or disable the bar, depending of the theme option setting
 ***************************************************/
if ( sq_option( 'admin_bar', 1 ) == '0' ):
	remove_action( 'wp_footer', 'wp_admin_bar_render', 1000 );
	add_filter( 'show_admin_bar', '__return_false' );
endif;
