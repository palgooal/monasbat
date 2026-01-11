<?php

namespace Seventhqueen\Panel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 *
 * The main plugin handler class is responsible for initializing Plugin. The
 * class registers and all the components required to run the plugin.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Instance.
	 *
	 * Holds the plugin instance.
	 *
	 * @since 1.0.0
	 * @access public
	 * @static
	 *
	 * @var Plugin
	 */
	public static $instance = null;

	/**
	 * Clone.
	 *
	 * Disable class cloning and throw an error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object. Therefore, we don't want the object to be cloned.
	 *
	 * @access public
	 * @since 1.0.0
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Something went wrong.', 'kleo' ), '1.0.0' );
	}

	/**
	 * Wakeup.
	 *
	 * Disable unserializing of the class.
	 *
	 * @access public
	 * @since 1.0.0
	 */
	public function __wakeup() {
		// Unserializing instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Something went wrong.', 'kleo' ), '1.0.0' );
	}

	/**
	 * Instance.
	 *
	 * Ensures only one instance of the plugin class is loaded or can be loaded.
	 *
	 * @param array $config
	 *
	 * @return Plugin An instance of the class.
	 * @since 1.0.0
	 * @access public
	 * @static
	 *
	 */
	public static function instance( $config = [] ) {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self( $config );

			/**
			 * Panel loaded.
			 *
			 * Fires when Panel was fully loaded and instantiated.
			 *
			 * @since 1.0.0
			 */
			do_action( 'seventhqueen/import/loaded' );
		}

		return self::$instance;
	}

	public $config = [];

	public function get_config( $name ) {
		if ( isset( $this->config[ $name ] ) ) {
			return $this->config[ $name ];
		}

		return false;
	}

	public function init_config( $config = [] ) {
		if ( empty( $config ) ) {
			return;
		}
		$defaults     = array(
			'theme_name'      => 'KLEO',
			'theme_lower'     => 'kleo',
			'slug'            => 'sq-panel',
			'priority_addons' => [],
			'item_id'         => '',
			'purchase_link'   => '#',
		);
		$this->config = wp_parse_args( $config, $defaults );
	}

	/**
	 * Plugin constructor.
	 *
	 * Initializing plugin.
	 *
	 * @param array $config
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function __construct( $config = [] ) {
		$this->register_autoloader();

		$this->init_config( $config );

		$this->set_constants();
		$this->set_hooks();

		Addons_Manager::instance();


	}

	private function set_constants() {
		if ( ! defined( 'SVQ_PANEL_DIR' ) ) {
			define( 'SVQ_PANEL_DIR', SVQ_IMPORT_BASE_PATH );
		}

		if ( ! defined( 'SVQ_PANEL_URI' ) ) {
			define( 'SVQ_PANEL_URI', SVQ_IMPORT_BASE_URL );
		}
	}

	public function set_hooks() {

		add_action( 'admin_menu', array( $this, 'register_panel_page' ) );
		add_action( 'admin_init', array( $this, 'redirect_to_panel' ), 0 );

		add_filter( 'http_request_args', [ $this, 'stop_update_theme' ], 5, 2 );

		add_action( 'wp_ajax_sq_theme_registration', array( $this, 'theme_registration' ) );
		add_action( 'after_switch_theme', array( $this, 'check_code_at_activation' ) );

		if ( ( isset( $_GET['page'] ) && $_GET['page'] == $this->config['slug'] ) ||
		     ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'sq_do_plugin_action' ) ) {

			add_action( 'admin_init', array( $this, 'config_addons' ), 12 );

			add_action( 'admin_enqueue_scripts', array( $this, 'panel_scripts' ) );
		}

	}

	/**
	 * Register CSS & JS Files
	 */
	function panel_scripts() {
		//CSS
		wp_register_style( $this->config['slug'], SVQ_PANEL_URI . "assets/css/theme-panel.css", array(), SVQ_THEME_VERSION, "all" );
		wp_enqueue_style( $this->config['slug'] );

		//JS
		wp_enqueue_script( 'jquery-ui-tooltip' );
		wp_register_script( $this->config['slug'], SVQ_PANEL_URI . "assets/js/theme-panel.js", array( 'jquery' ), SVQ_THEME_VERSION, true );
		wp_enqueue_script( $this->config['slug'] );
	}

	public function register_panel_page() {
		add_theme_page(
			sprintf( esc_html__( '%s Panel', 'kleo' ), $this->config['theme_name'] ),
			sprintf( esc_html__( '%s Panel', 'kleo' ), $this->config['theme_name'] ),
			'manage_options',
			$this->config['slug'],
			array( $this, 'panel_page' )
		);
	}

	function panel_page() {

		require SVQ_PANEL_DIR . 'templates/welcome.php';

	}

	public function redirect_to_panel() {
		// Theme activation redirect
		global $pagenow;
		if ( isset( $_GET['activated'] ) && $pagenow == 'themes.php' ) {

			wp_redirect( admin_url( "themes.php?page=" . $this->config['slug'] ) );
			exit;
		}
	}

	/**
	 * Make sure no conflict exists with WP Repo themes
	 *
	 * @param $r
	 * @param $url
	 *
	 * @return mixed
	 */
	public function stop_update_theme( $r, $url ) {
		if ( 0 !== strpos( $url, 'https://api.wordpress.org/themes/update-check/1.1/' ) ) {
			return $r; // Not a theme update request. Bail immediately.
		}

		$themes = json_decode( $r['body']['themes'] );
		$theme  = get_template();
		unset( $themes->themes->$theme );
		$r['body']['themes'] = json_encode( $themes );

		return $r;
	}

	/**
	 * Register theme
	 */
	public function theme_registration() {
		if ( ! isset( $_POST['sq_nonce'] ) || ! wp_verify_nonce( $_POST['sq_nonce'], 'sq_theme_registration' ) ) {
			wp_send_json_error( [ 'error' => 'Sorry, your nonce did not verify.' ] );
		}

		$option_name = "envato_purchase_code_" . $this->config['item_id'];
		$tf_code     = isset( $_POST['code'] ) ? $_POST['code'] : '';

		if ( ! empty( $tf_code ) ) {
			$has_purchased = $this->is_active( $tf_code, true, true );

			if ( is_wp_error( $has_purchased ) ) {
				wp_send_json_error( [ 'error' => $has_purchased->get_error_message() ] );

				return;
			}

			if ( $has_purchased ) {
				//Update code
				update_option( $option_name, $tf_code );

				wp_send_json_success( array( 'message' => esc_html__( 'License successfully activated. Please refresh page.', 'kleo' ) ) );

				return;
			}

			wp_send_json_error( array( 'error' => esc_html__( 'Purchase code is not valid. Please check the license provided!', 'kleo' ) ) );

			return;
		}

		wp_send_json_error( array( 'error' => esc_html__( 'Please enter your purchase code.', 'kleo' ) ) );

	}


	public function check_code_at_activation() {
		$this->verify_purchase();
	}


	public function config_addons() {

		/* Move elements first */
		if ( empty( $this->config['priority_addons'] ) ) {
			return;
		}

		$priority_list = array_reverse( $this->config['priority_addons'] );
		foreach ( $priority_list as $item ) {
			if ( isset( Addons_Manager::instance()->plugins[ $item ] ) ) {
				Addons_Manager::instance()->plugins = array( $item => Addons_Manager::instance()->plugins[ $item ] ) + Addons_Manager::instance()->plugins;
			}
		}

		$prepend = array(
			$this->config['theme_lower'] . '-child' => array(
				'addon_type'  => 'child_theme',
				'name'        => $this->config['theme_name'] . ' child theme',
				'slug'        => $this->config['theme_lower'] . '-child',
				'source'      => get_template_directory() . '/lib/inc/' . $this->config['theme_lower'] . '-child.zip',
				'source_type' => 'bundled',
				'version'     => '1.0',
				'required'    => true,
				'description' => wp_kses_post( sprintf( __( 'Always activate the child theme to safely update %s and for better customization. <a href="%s" target="_blank">More on Child Themes</a>.', 'kleo' ),
					$this->config['theme_name'],
					'https://codex.wordpress.org/Child_Themes'
				) ),
			)
		);

		Addons_Manager::instance()->plugins = $prepend + Addons_Manager::instance()->plugins;
	}

	public function is_active( $code = '', $force = false, $with_error = false ) {
		return true;
		
		$data = $this->verify_purchase( $code, $force );

		if ( is_wp_error( $data ) ) {
			if ( $with_error ) {
				return $data;
			}

			return false;
		}

		return is_array( $data ) && $data['active'] === true;
	}

	public function verify_purchase( $tf_code = '', $force = false ) {

		if ( $this->get_config( 'item_id' ) == '' ) {

			return new \WP_Error( 'invalid', esc_html__( 'Something is not configured properly. Please contact theme author.', 'typer' ) );
		}

		if ( $tf_code === '' ) {
			$tf_code = get_option( 'envato_purchase_code_' . $this->get_config( 'item_id' ), '' );
		}

		if ( $tf_code && $tf_code !== '' ) {

			if ( $force ) {
				delete_transient( 'svq_license_' . $this->get_config( 'item_id' ) );
			}

			if ( $license_data = get_transient( 'svq_license_' . $this->get_config( 'item_id' ) ) ) {

				return $license_data;
			}

			$data = $this->get_purchase_data( $tf_code );

			if ( is_wp_error( $data ) ) {

				return $data;
			}

			if ( isset( $data->supported_until ) ) {
				$license_data                    = [];
				$license_data['active']          = true;
				$license_data['supported_until'] = $data->supported_until;

				set_transient( 'svq_license_' . $this->get_config( 'item_id' ), $license_data, 60 * 60 * 24 );

				return $license_data;
			}

			return new \WP_Error( 'invalid', esc_html__( 'Purchase code is not valid, make sure you pasted it right.', 'kleo' ) );
		}

		return new \WP_Error( 'invalid', esc_html__( 'Purchase code is empty', 'typer' ) );
	}


	/**
	 * @param $code
	 *
	 * @return array|mixed|\WP_Error
	 */
	public function get_purchase_data( $code ) {
	
		$theme = $this->get_config( 'item_id' );

		$purchase_get = wp_remote_get( 'https://updates.seventhqueen.com/verify-purchase/?product=' . $theme . '&code=' . $code );

		// Check for error
		if ( ! is_wp_error( $purchase_get ) ) {
			$response = wp_remote_retrieve_body( $purchase_get );

			// Check for error
			if ( $response ) {
				return json_decode( $response );
			}
		}

		return $purchase_get;

	}


	/**
	 * Register autoloader.
	 *
	 * Sweetcore autoloader loads all the classes needed to run the plugin.
	 *
	 * @since 1.6.0
	 * @access private
	 */
	private function register_autoloader() {
		require SVQ_IMPORT_BASE_PATH . 'inc/Autoloader.php';

		Autoloader::run();
	}

}
