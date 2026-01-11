<?php

namespace Kleo\Lib;


class Upgrades {


	/**
	 * Option name that gets saved in the options database table
	 *
	 * @var string
	 */
	private $option_name = 'kleo_version_update';

	/**
	 * Upgrade versions and method callbacks
	 * @var array
	 */
	private $upgrades = [
		'4.9.1' => '_update_4_9_1',
		'5.0.0' => '_update_5_0_0',
	];

	/**
	 * Current plugin version
	 * @var string
	 */
	private $version = SVQ_THEME_VERSION;


	public function __construct() {

		add_action( 'init', [ $this, 'init' ] );
	}

	public function init() {

		$old_upgrades    = get_option( $this->option_name, [] );
		$current_version = $this->version;
		$updated         = false;

		foreach ( $this->upgrades as $version => $method ) {
			$db_version = str_replace( '.', '_', $version );

			if ( ! isset( $old_upgrades[ $db_version ] ) && version_compare( $current_version, $version, '>=' ) ) {

				// Run the upgrade.
				$this->$method();
				$old_upgrades[ $db_version ] = true;
				$updated                     = true;
			}
		}

		// Save successful upgrades.
		if ( $updated ) {
			update_option( $this->option_name, $old_upgrades );
		}

	}

	private function _update_4_9_1() {

		//rtmedia
		update_option( 'rtmedia_inspirebook_release_notice', 'hide' );
		update_option( 'rtmedia_premium_addon_notice', 'hide' );
		update_option( 'rtmedia-update-template-notice-v3_9_4', 'hide' );
		update_site_option( 'install_transcoder_admin_notice', '0' );

		//elementor theme settings
		update_option( 'elementor_disable_typography_schemes', 'yes' );

		// Deprecated
		update_option( 'elementor_page_title_selector', 'h1.page-title' );

		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->kits_manager
				->update_kit_settings_based_on_option( 'page_title_selector', 'h1.page-title' );
		}

		update_option( 'elementor_container_width', '1250' );
		update_option( 'elementor_viewport_lg', '991' );

		// update elementor_scheme_color
		$style_options = kleo_style_options();
		$current       = get_option( 'elementor_scheme_color' );
		if ( isset( $style_options['main'] ) && $style_options['main']['text'] && $style_options['main']['high_bg'] &&
		     $style_options['main']['alt_bg'] ) {

			$style_options = $style_options['main'];

			//texts
			if ( $style_options['text'] ) {
				$current[1] = $style_options['text'];
				$current[3] = $style_options['text'];
			}
			//primary
			if ( $style_options['high_bg'] ) {
				$current[4] = $style_options['high_bg'];
			}
			//secondary
			if ( $style_options['alt_bg'] ) {
				$current[2] = $style_options['alt_bg'];
			}
		} else {
			$current = [
				1 => '#777777',
				2 => '#f7f7f7',
				3 => '#777777',
				4 => '#00b9f7',
			];
		}

		update_option( 'elementor_scheme_color', $current );

		if ( defined( 'ELEMENTOR_PATH' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		/* disable notice to update translation files */
		update_option( 'kleo_mo_files_49', 'hide' );
	}

	private function _update_5_0_0() {

		// remove home page for group and member. set columns dir
		$settings = get_option( 'bp_nouveau_appearance', [] );

		$settings['group_front_page']       = 0;
		$settings['user_front_page']        = 0;
		$settings['members_layout']         = 3;
		$settings['members_friends_layout'] = 3;
		$settings['groups_layout']          = 3;
		$settings['members_group_layout']   = 3;

		update_option( 'bp_nouveau_appearance', $settings );
	}
}

new Upgrades();
