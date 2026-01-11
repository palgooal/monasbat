<?php

/*
Plugin Name: K Elements
Plugin URL: http://seventhqueen.com/
Description: WordPress elements using easy to add shortcodes
Version: 5.5.0
Author: SeventhQueen
Author URI: http://seventhqueen.com/
Domain Path: /languages
Text Domain: k-elements
*/

// =============================================================================
// TABLE OF CONTENTS
// -----------------------------------------------------------------------------
//   01. Define Constants
//	 02. Load textdomain
//   03. Require Files
// =============================================================================


// Define Constants
// =============================================================================

if ( ! defined( 'K_ELEM_VERSION' ) ) {
	define( 'K_ELEM_VERSION', '5.5.0' );
}

// Plugin Folder Path
if ( ! defined( 'K_ELEM_PLUGIN_DIR' ) ) {
	define( 'K_ELEM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Plugin Folder URL
if ( ! defined( 'K_ELEM_PLUGIN_URL' ) ) {
	define( 'K_ELEM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Plugin Root File
if ( ! defined( 'K_ELEM_PLUGIN_FILE' ) ) {
	define( 'K_ELEM_PLUGIN_FILE', __FILE__ );
}


// Load textdomain
// =============================================================================

add_action( 'plugins_loaded', 'k_elements_load_textdomain' );
function k_elements_load_textdomain() {
	load_plugin_textdomain( 'k-elements', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}


// Require Files
// =============================================================================

add_action( 'after_setup_theme', 'k_elements_init_helpers', 1 );
function k_elements_init_helpers() {
	if ( ! class_exists( 'SVQ_FW' ) && ! class_exists( 'Kleo' ) ) {
		require_once( trailingslashit( K_ELEM_PLUGIN_DIR ) . 'functions/helpers.php' );
	}

	if ( ! class_exists( 'Aq_Resize' ) ) {
		require_once( trailingslashit( K_ELEM_PLUGIN_DIR ) . 'lib/aq-resizer.php' );
	}
}

add_action( 'init', 'k_elements_init', 8 );
function k_elements_init() {
	require_once( trailingslashit( K_ELEM_PLUGIN_DIR ) . 'functions/functions.php' );
	// require_once( trailingslashit( K_ELEM_PLUGIN_DIR ) . 'admin/tiny_mce.php' );
	require_once( trailingslashit( K_ELEM_PLUGIN_DIR ) . 'shortcodes/shortcodes.php' );
}

// Compatibility with Visual composer plugin.
if ( function_exists( 'vc_set_as_theme' ) ) {
	require_once( trailingslashit( K_ELEM_PLUGIN_DIR ) . 'compat/plugin-js-composer/config.php' );
}

/* Import functionality */
require_once K_ELEM_PLUGIN_DIR . '/lib/sq-import/import.php';

add_action( 'plugins_loaded', 'k_elements_dependencies' );
/**
 * Load Theme options
 */
function k_elements_dependencies() {

	if ( is_admin() || is_customize_preview() || isset( $_GET['customize_changeset_uuid'] ) ) {
		//Options panel
		if ( ! class_exists( 'ReduxFramework' ) && file_exists( K_ELEM_PLUGIN_DIR . '/lib/options/framework.php' ) ) {
			require_once( K_ELEM_PLUGIN_DIR . '/lib/options/framework.php' );
		}

		require_once( K_ELEM_PLUGIN_DIR . '/lib/redux-vendor-support/redux-vendor-support.php' );
	}
}

add_action( 'init', 'k_elements_initialize_meta_boxes', 9999 );
/**
 * Load the metabox class.
 */
function k_elements_initialize_meta_boxes() {
	if ( ! is_admin() ) {
		return;
	}

	if ( ! class_exists( 'kleo_Meta_Box' ) ) {
		require_once trailingslashit( K_ELEM_PLUGIN_DIR ) . 'lib/metaboxes/init.php';
	}
}

/***************************************************
 * :: Include widgets
 ***************************************************/

$kleo_widgets = array(
	'recent_posts.php'
);

$kleo_widgets = apply_filters( 'kleo_widgets', $kleo_widgets );

foreach ( $kleo_widgets as $widget ) {
	$file_path = trailingslashit( K_ELEM_PLUGIN_DIR ) . 'widgets/' . $widget;

	if ( file_exists( $file_path ) ) {
		require_once( $file_path );
	}
}

/***************************************************
 * :: Include modules
 ***************************************************/

add_action( 'after_setup_theme', 'k_elements_modules', 12 );
function k_elements_modules() {
	if ( ! function_exists( 'sq_option' ) ) {
		return;
	}

	require_once trailingslashit( K_ELEM_PLUGIN_DIR ) . 'functions/after-setup-theme.php';

	require_once trailingslashit( K_ELEM_PLUGIN_DIR ) . 'post-types/post-types.php';

	/* Testimonials post type */
	if ( sq_option( 'module_testimonials', 1 ) == 1 ) {
		require_once trailingslashit( K_ELEM_PLUGIN_DIR ) . 'post-types/testimonials.php';
	}

	/* Clients post type */
	if ( sq_option( 'module_clients', 1 ) == 1 ) {
		require_once trailingslashit( K_ELEM_PLUGIN_DIR ) . 'post-types/clients.php';
	}

	/* Load Elementor */
	require_once trailingslashit( K_ELEM_PLUGIN_DIR ) . 'compat/elementor/config.php';

	$kleo_modules = array(
		'item-likes.php',
		'facebook-login.php',
		'ajax-login.php',
		'portfolio.php',
		'contact-form.php'
	);

	$kleo_modules = apply_filters( 'kleo_modules', $kleo_modules );

	foreach ( $kleo_modules as $module ) {
		$file_path = trailingslashit( K_ELEM_PLUGIN_DIR ) . 'modules/' . $module;

		if ( file_exists( $file_path ) ) {
			require_once( $file_path );
		}
	}
}

require 'lib/plugin-update-checker/plugin-update-checker.php';

$kleo_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://updates.seventhqueen.com/check/?action=get_metadata&slug=k-elements',
	__FILE__,
	'k-elements'
);
