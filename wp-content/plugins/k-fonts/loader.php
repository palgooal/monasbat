<?php
/*
Plugin Name: K Fonts
Plugin URL: http://seventhqueen.com/
Description: Add Custom fonts to Kleo Theme
Version: 1.1.0
Author: SeventhQueen
Author URI: http://seventhqueen.com/
Domain Path: /languages
Text Domain: k-fonts
*/

// =============================================================================
// TABLE OF CONTENTS
// -----------------------------------------------------------------------------
//   01. Define Constants
//	 02. Load textdomain
//   03. Require Files
//   04. Enqueue Assets
// =============================================================================


// Define Constants
// =============================================================================

if ( ! defined( 'K_FONTS_VERSION' ) ) {
	define( 'K_FONTS_VERSION', '1.1.0' );
}

// Plugin Folder Path
if ( ! defined( 'K_FONTS_PLUGIN_DIR' ) ) {
	define( 'K_FONTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Plugin Folder URL
if ( ! defined( 'K_FONTS_PLUGIN_URL' ) ) {
	define( 'K_FONTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Plugin Root File
if ( ! defined( 'K_FONTS_PLUGIN_FILE' ) ) {
	define( 'K_FONTS_PLUGIN_FILE', __FILE__ );
}

add_action( 'after_setup_theme', 'svq_load_carbon', 12 );
function svq_load_carbon() {
	if ( ! is_admin() && ! wp_doing_ajax() && ! kleo_k_fonts_is_rest() ) {
		return;
	}

	if ( ! class_exists( '\Carbon_Fields\Container' ) ) {
		$carbon_path = K_FONTS_PLUGIN_DIR . '/inc/carbon-fields/vendor/autoload.php';
		if ( file_exists( $carbon_path ) ) {
			include_once( $carbon_path );
			\Carbon_Fields\Carbon_Fields::boot();
		}
	}
	require_once 'settings.php';
}

add_filter( 'upload_mimes', 'add_custom_upload_mimes' );
function add_custom_upload_mimes( $existing_mimes ) {
	$existing_mimes['otf']   = 'application/x-font-otf';
	$existing_mimes['woff']  = 'application/x-font-woff';
	$existing_mimes['woff2'] = 'application/x-font-woff2';
	$existing_mimes['ttf']   = 'application/x-font-ttf';
	$existing_mimes['svg']   = 'image/svg+xml';
	$existing_mimes['eot']   = 'application/vnd.ms-fontobject';

	return $existing_mimes;
}

function kleo_k_fonts_is_rest() {
	$prefix = rest_get_url_prefix();
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) // (#1)
	     || ( isset( $_GET['rest_route'] ) // (#2)
	          && strpos( trim( $_GET['rest_route'], '\\/' ), $prefix, 0 ) === 0 ) ) {
		return true;
	}

	$rest_url    = wp_parse_url( site_url( $prefix ) );
	$current_url = wp_parse_url( add_query_arg( array() ) );

	return strpos( $current_url['path'], $rest_url['path'], 0 ) === 0;
}

add_filter( 'kleo_add_dynamic_style', function ( $extra = '' ) {
	$fonts_options = get_option( 'k-fonts' );
	$font_types    = [
		'woff2' => 'woff2',
		'woff'  => 'woff',
		'ttf'   => 'truetype',
		'eot'   => '',
		'svg'   => 'svg',
	];

	if ( $fonts_options && ! empty( $fonts_options ) ) {
		foreach ( $fonts_options as $font ) {
			$url = [];
			foreach ( $font_types as $font_type => $font_format ) {
				if ( isset( $font[ $font_type ] ) ) {
					$file = wp_get_attachment_url( $font[ $font_type ] );
					if ( $file ) {
						$url[] = 'url(' . esc_url( $file ) . ')' . ( ! empty( $font_format ) ? ' format("' . $font_format . '")' : '' );
					}
				}
			}

			if ( ! empty( $url ) ) {
				$extra .= '@font-face {
					  font-family: ' . $font['name'] . ';
					  src: ' . implode( ', ', $url ) . ';
					  font-weight: ' . $font['weight'] . ';
					  font-style: ' . $font['style'] . ';
					}';
			}
		}
	}

	return $extra;

} );
