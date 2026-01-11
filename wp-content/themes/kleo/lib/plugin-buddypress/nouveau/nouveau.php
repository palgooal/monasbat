<?php

namespace Kleo\Buddypress;

class Nouveau {

	public function __construct() {

		if ( ! function_exists( 'youzify' ) ) {
			add_filter( 'bp_get_template_stack', [ $this, 'rewrite_template' ], 99999 );
			add_action( 'bp_init', [ $this, 'bp_init' ] );
			add_action( 'after_setup_theme', [ $this, 'after_setup_theme' ], 20 );
			add_filter('bp_nouveau_enqueue_styles', [ $this, 'filter_bp_styles' ] );
		}

		/* Dynamic styles */
		require_once( KLEO_LIB_DIR . '/plugin-buddypress/nouveau/dynamic.php' );

	}

	public function after_setup_theme() {

		add_theme_support( 'buddypress-use-nouveau' );
		//remove_theme_support( 'buddypress-use-legacy' );
	}

	public function bp_init() {

		// Get Templates Location.
		if ( function_exists( 'bp_register_template_stack' ) && ! defined( 'BP_PLATFORM_VERSION' ) ) {

			bp_register_template_stack( [ $this, 'get_child_template_path' ], - 1 );
			bp_register_template_stack( [ $this, 'get_template_path' ], - 1 );
		}
	}

	/**
	 * BuddyPress templates path
	 *
	 * @return string
	 */
	public function get_template_path() {
		return get_template_directory() . '/buddypress-nouveau/';
	}

	/**
	 * BuddyPress templates path
	 *
	 * @return string
	 */
	public function get_child_template_path() {
		return get_stylesheet_directory() . '/buddypress-nouveau/';
	}

	/**
	 * Force our template path
	 *
	 * @param $stack
	 *
	 * @return mixed
	 */
	public function rewrite_template( $stack ) {

		$theme_paths = [
			get_template_directory(),
		];

		if ( is_child_theme() ) {
			$theme_paths[] = get_stylesheet_directory();
		}

		foreach ( $theme_paths as $theme_path ) {
			$key = array_search( $theme_path . '/buddypress', $stack, true );

			if ( $key !== false ) {
				unset( $stack[ $key ] );
			}
		}

		if ( ! defined( 'BP_PLATFORM_VERSION' ) ) {
			array_unshift( $stack, get_template_directory() . '/buddypress-nouveau/' );
			if ( is_child_theme() ) {
				array_unshift( $stack, get_stylesheet_directory() . '/buddypress-nouveau/' );
			}
		}

		return $stack;
	}

	/**
	 * Filter BuddyPress styles
	 *
	 * @param $styles
	 *
	 * @return array
	 */
	public function filter_bp_styles( $styles ) {
		
		if ( isset($styles['bp-nouveau-priority-nav']) ) {
			unset( $styles['bp-nouveau-priority-nav'] );
		} 
		
		return $styles;
	}

}

new Nouveau();
