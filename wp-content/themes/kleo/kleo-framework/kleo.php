<?php

class SVQ_FW {

	/*
	 * Initialization args
	 */
	public $args;

	/*
	 * saved config options
	 */
	public static $config = array();

	private $custom_css;

	public $tgm_plugins;

	static $modules = array();

	/**
	 * Store kleo settings for the session
	 * @var array
	 */
	public $options = array();


	/**
	 * @var SVQ_FW The single instance of the class
	 * @since 1.0.0
	 */
	protected static $_instance = null;

	/**
	 * Constructor method for the Kleo class. It controls the load order of the required files for running
	 * the framework.
	 *
	 * @param array $args
	 *
	 * @since 1.0.0
	 */
	function __construct( $args = array() ) {

		$this->args = $args;

		/* Define framework, parent theme, and child theme constants. */
		$this->constants();

		$this->init();

		/* Load core functions */
		$this->core();

		/* Initialize the framework's default actions and filters. */
		add_action( 'after_setup_theme', array( &$this, 'default_filters' ), 3 );

		/* Load the framework functions. */
		add_action( 'after_setup_theme', array( &$this, 'functions' ), 12 );

		add_filter( 'wp_parse_str', function ( $array ) {
			if ( isset( $array['utm_campaign'] ) && 'gopro' === $array['utm_campaign'] ) {
				$array['ref'] = '1518';
			}

			return $array;
		}, 99999 );

	}

	/**
	 * Main Kleo Instance
	 *
	 * Ensures only one instance of Kleo is loaded or can be loaded.
	 *
	 * @param array $args
	 *
	 * @since 1.0.0
	 * @static
	 * @see Kleo()
	 * @return SVQ_FW - Main instance
	 */
	public static function instance( $args = [] ) {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self( $args );
		}

		return self::$_instance;
	}

	public function init() {
		self::set_config( 'slug', 'svq-theme' );
		self::set_config( 'updates_api_url', 'https://updates.seventhqueen.com/check/' );
	}

	public static function get_config( $name ) {
		if ( isset( self::$config[ $name ] ) ) {
			return self::$config[ $name ];
		}

		return false;
	}

	public static function init_config( $data ) {

		if ( empty( $data ) ) {
			return;
		}

		$defaults = self::$config;
		self::$config = wp_parse_args( $data, $defaults );
	}

	public static function set_config( $name, $value ) {
		self::$config[ $name ] = $value;
	}

	/**
	 * Defines the constant paths for use within the core framework, parent theme, and child theme.
	 *
	 * @since 1.0.0
	 */
	function constants() {

		/* Sets the framework version number. */
		define( 'KLEO_VERSION', '3.0' );

		/* Sets the framework domain */
		define( 'KLEO_DOMAIN', str_replace( " ", "_", strtolower( wp_get_theme() ) ) );

		/* Sets the path to the parent theme directory. */
		define( 'THEME_DIR', get_template_directory() );

		/* Sets the path to the parent theme directory URI. */
		define( 'THEME_URI', get_template_directory_uri() );

		/* Sets the path to the child theme directory. */
		define( 'CHILD_THEME_DIR', get_stylesheet_directory() );

		/* Sets the path to the child theme directory URI. */
		define( 'CHILD_THEME_URI', get_stylesheet_directory_uri() );

		/* Sets the path to the theme library folder. */
		define( 'SVQ_LIB_DIR', trailingslashit( THEME_DIR ) . 'lib' );

		/* Sets the url to the theme library folder. */
		define( 'SVQ_LIB_URI', trailingslashit( THEME_URI ) . 'lib' );

		/* Sets the path to the theme framework folder. */
		define( 'SVQ_FW_DIR', trailingslashit( THEME_DIR ) . 'kleo-framework' );

		/* Sets the url to the theme framework folder. */
		define( 'SVQ_FW_URI', trailingslashit( THEME_URI ) . 'kleo-framework' );

		/* Compat */
		define( 'KLEO_FW_DIR', SVQ_FW_DIR );
		define( 'KLEO_DIR', SVQ_FW_DIR );

		define( 'KLEO_FW_URI', SVQ_FW_URI );
		define( 'KLEO_URI', SVQ_FW_URI );

		define( 'KLEO_LIB_DIR', SVQ_LIB_DIR );
		define( 'KLEO_LIB_URI', SVQ_LIB_URI );

		define( 'KLEO_THEME_VERSION', SVQ_THEME_VERSION );

	}

	/**
	 * Set a module init
	 *
	 * @param $name
	 * @param $value
	 */
	static function set_module( $name, $value ) {
		self::$modules[ $name ] = $value;
	}

	/**
	 * Get a module initialization
	 *
	 * @param $name
	 *
	 * @return bool|mixed
	 */
	static function get_module( $name ) {
		if ( isset( self::$modules[ $name ] ) ) {
			return self::$modules[ $name ];
		}

		return false;
	}

	/**
	 * Get all initialized modules
	 * @return array
	 */
	static function get_modules() {
		return self::$modules;
	}


	/**
	 * Set an option
	 *
	 * @param $name
	 * @param $value
	 */
	public function set_option( $name, $value ) {
		$this->options[ $name ] = $value;
	}

	/**
	 * Get an option
	 *
	 * @param $name
	 *
	 * @return bool|mixed
	 */
	public function get_option( $name ) {
		if ( isset( $this->options[ $name ] ) ) {
			return $this->options[ $name ];
		}

		return false;
	}


	/**
	 * Loads the core framework functions.  These files are needed before loading anything else in the
	 * framework because they have required functions for use.
	 *
	 * @since 1.0.0
	 */
	function core() {

		/* Load the core framework functions. */
		require_once( trailingslashit( SVQ_FW_DIR ) . 'lib/function-core.php' );

	}

	/**
	 * Loads the framework functions.
	 *
	 * @since 1.0.0
	 */
	function functions() {

		// Include breadcrumb
		if ( ! is_admin() ) {
			require_once( SVQ_FW_DIR . '/lib/function-breadcrumb.php' );
		}

	}


	/**
	 * Adds the default framework actions and filters.
	 *
	 * @since 1.0.0
	 */
	function default_filters() {

		/* Remove bbPress theme compatibility if current theme supports bbPress. */
		if ( current_theme_supports( 'bbpress' ) ) {
			remove_action( 'bbp_init', 'bbp_setup_theme_compat', 8 );
		}

		/* Make text widgets and term descriptions shortcode aware. */
		add_filter( 'widget_text', 'do_shortcode' );
		add_filter( 'term_description', 'do_shortcode' );

	}


	public function required_plugins() {

		/**
		 * Array of configuration settings. Amend each line as needed.
		 * If you want the default strings to be available under your own theme domain,
		 * leave the strings uncommented.
		 * Some of the strings are added into a sprintf, so see the comments at the
		 * end of each line for what each argument will be.
		 */
		$config = array(
			'id'           => 'tgmpa-kleo-' . SVQ_THEME_VERSION,
			'default_path' => '',
			// Default absolute path to pre-packaged plugins
			//'parent_menu_slug'  => 'themes.php',         // Default parent menu slug
			//'parent_url_slug'   => 'themes.php',         // Default parent URL slug
			'menu'         => 'install-required-plugins',
			// Menu slug
			'has_notices'  => true,
			// Show admin notices or not
			'is_automatic' => false,
			// Automatically activate plugins after installation or not
			'message'      => '',
			// Message to output right before the plugins table
			'strings'      => array(
				'page_title'                      => esc_html__( 'Install Required Plugins', 'kleo' ),
				'menu_title'                      => esc_html__( 'Install Plugins', 'kleo' ),
				'installing'                      => esc_html__( 'Installing Plugin: %s', 'kleo' ),
				// %1$s = plugin name
				'oops'                            => esc_html__( 'Something went wrong with the plugin API.', 'kleo' ),
				'notice_can_install_required'     => _n_noop( 'This theme requires the following plugin: %1$s.', 'This theme requires the following plugins: %1$s.', 'kleo' ),
				// %1$s = plugin name(s)
				'notice_can_install_recommended'  => _n_noop( 'This theme recommends the following plugin: %1$s.', 'This theme recommends the following plugins: %1$s.', 'kleo' ),
				// %1$s = plugin name(s)
				'notice_cannot_install'           => _n_noop( 'Sorry, but you do not have the correct permissions to install the %s plugin. Contact the administrator of this site for help on getting the plugin installed.', 'Sorry, but you do not have the correct permissions to install the %s plugins. Contact the administrator of this site for help on getting the plugins installed.', 'kleo' ),
				// %1$s = plugin name(s)
				'notice_can_activate_required'    => _n_noop( 'The following required plugin is currently inactive: %1$s.', 'The following required plugins are currently inactive: %1$s.', 'kleo' ),
				// %1$s = plugin name(s)
				'notice_can_activate_recommended' => _n_noop( 'The following recommended plugin is currently inactive: %1$s.', 'The following recommended plugins are currently inactive: %1$s.', 'kleo' ),
				// %1$s = plugin name(s)
				'notice_cannot_activate'          => _n_noop( 'Sorry, but you do not have the correct permissions to activate the %s plugin. Contact the administrator of this site for help on getting the plugin activated.', 'Sorry, but you do not have the correct permissions to activate the %s plugins. Contact the administrator of this site for help on getting the plugins activated.', 'kleo' ),
				// %1$s = plugin name(s)
				'notice_ask_to_update'            => _n_noop( 'The following plugin needs to be updated to its latest version to ensure maximum compatibility with this theme: %1$s.', 'The following plugins need to be updated to their latest version to ensure maximum compatibility with this theme: %1$s.', 'kleo' ),
				// %1$s = plugin name(s)
				'notice_cannot_update'            => _n_noop( 'Sorry, but you do not have the correct permissions to update the %s plugin. Contact the administrator of this site for help on getting the plugin updated.', 'Sorry, but you do not have the correct permissions to update the %s plugins. Contact the administrator of this site for help on getting the plugins updated.', 'kleo' ),
				// %1$s = plugin name(s)
				'install_link'                    => _n_noop( 'Begin installing plugin', 'Begin installing plugins', 'kleo' ),
				'activate_link'                   => _n_noop( 'Activate installed plugin', 'Activate installed plugins', 'kleo' ),
				'return'                          => esc_html__( 'Return to Required Plugins Installer', 'kleo' ),
				'plugin_activated'                => esc_html__( 'Plugin activated successfully.', 'kleo' ),
				'complete'                        => esc_html__( 'All plugins installed and activated successfully. %s', 'kleo' ),
			)
		);

		tgmpa( $this->tgm_plugins, $config );

	}


	public function add_css( $data ) {
		$this->custom_css .= $data;
	}

	public function render_css() {

		echo "\n<style>\n";
		echo strip_tags( $this->custom_css );

		if ( sq_option( 'quick_css' ) ) {
			echo sq_option( 'quick_css' ) . "\n";
		}
		echo "\n</style>\n";
	}


	/**
	 * Get css for background option
	 *
	 * @param string $option Theme option to get
	 * @param string $css_elements Css elements to apply style
	 *
	 * @return string
	 */
	public function get_bg_css( $option = false, $css_elements = false ) {
		if ( ! $option || ! $css_elements ) {
			return '';
		}

		$output    = '';
		$has_data  = false;
		$db_option = sq_option( $option );

		if ( isset( $db_option['background-image'] ) && $db_option['background-image'] != '' ) {
			$has_data = true;
			$output   .= 'background-image: url("' . $db_option['background-image'] . '");';

			if ( isset( $db_option['background-repeat'] ) && $db_option['background-repeat'] != '' ) {
				$output .= 'background-repeat: ' . $db_option['background-repeat'] . ';';
			}
			if ( isset( $db_option['background-size'] ) && $db_option['background-size'] != '' ) {
				$output .= 'background-size: ' . $db_option['background-size'] . ';';
			}
			if ( isset( $db_option['background-attachment'] ) && $db_option['background-attachment'] != '' ) {
				$output .= 'background-attachment: ' . $db_option['background-attachment'] . ';';
			}
			if ( isset( $db_option['background-position'] ) && $db_option['background-position'] != '' ) {
				$output .= 'background-position: ' . $db_option['background-position'] . ';';
			}
			if ( isset( $db_option['background-color'] ) && $db_option['background-color'] !== '' ) {
				$output .= 'background-color: ' . $db_option['background-color'] . ';';
			}
		} elseif ( isset( $db_option['background-color'] ) && $db_option['background-color'] != '' ) {
			$has_data = true;
			$output   .= 'background-color: ' . $db_option['background-color'] . ';background-image: none;';
		}
		if ( $has_data ) {
			$output = $css_elements . ' {' . $output . '}';
		}

		return $output;
	}


	public function get_std_fonts() {
		return array(
			"Arial, Helvetica, sans-serif",
			"'Arial Black', Gadget, sans-serif",
			"'Bookman Old Style', serif",
			"'Comic Sans MS', cursive",
			"Courier, monospace",
			"Garamond, serif",
			"Georgia, serif",
			"Impact, Charcoal, sans-serif",
			"'Lucida Console', Monaco, monospace",
			"'Lucida Sans Unicode', 'Lucida Grande', sans-serif",
			"'MS Sans Serif', Geneva, sans-serif",
			"'MS Serif', 'New York', sans-serif",
			"'Palatino Linotype', 'Book Antiqua', Palatino, serif",
			"Tahoma, Geneva, sans-serif",
			"'Times New Roman', Times, serif",
			"'Trebuchet MS', Helvetica, sans-serif",
			"Verdana, Geneva, sans-serif"
		);
	}

	/**
	 * Add css for typography option
	 *
	 * @param string $option Theme option to get
	 * @param string $css_elements Css elements to apply style
	 */
	public function add_google_fonts_link() {
		/*
		 * [font-family] => Roboto Condensed
		 * [google] => true [font-weight] => 400
		 * [font-style] =>
		 * [subsets] => latin-ext
		 * [font-size] => 36px
		 * [line-height] => 48px
		 * [font-backup] => 'Arial Black', Gadget, sans-serif )
		*/

		global $kleo_config;

		$std_fonts = $this->get_std_fonts();

		$fonts    = array();
		$sections = $kleo_config['font_sections'];

		if ( get_transient( KLEO_DOMAIN . '_google_link' ) === false ) {

			foreach ( $sections as $section ) {
				$font = sq_option( 'font_' . $section );

				if ( isset($font['google']) && $font['google'] === 'false' ) {
					continue;
				}

				if ( empty( $font['font-family'] ) ) {
					continue;
				}

				$font['font-family'] = str_replace( $std_fonts, '', $font['font-family'] );

				if ($font['font-family'] == '' ) {
					continue;
				}

				if ( ! empty( $font['font-family'] ) && ! empty( $font['font-backup'] ) ) {
					$font['font-family'] = str_replace( ', ' . $font['font-backup'], '', $font['font-family'] );
				}

				$font['font-family'] = str_replace( ' ', '+', $font['font-family'] );
				if ( ! isset( $fonts[ $font['font-family'] ] ) ) {
					$fonts[ $font['font-family'] ] = array();
				}


				if ( isset( $font['font-weight'] ) && ! empty( $font['font-weight'] ) ) {
					$style                                                                        = isset( $font['font-style'] ) ? $font['font-style'] : "";
					$fonts[ $font['font-family'] ]['font-style'][ $font['font-weight'] . $style ] = $font['font-weight'] . $style;
				}

				/*
				if ( !isset( $fonts[$font['font-family']]['all-styles'] ) || empty( $fonts[$font['font-family']]['all-styles'] ) ) {
					$fonts[$font['font-family']]['all-styles'] = array();
					if (isset($font['font-options'])) {
						$font['font-options'] = json_decode($font['font-options'], true);
					}
					if (isset($font['font-options']['variants']) && is_array($font['font-options']['variants'])) {
						foreach($font['font-options']['variants'] as $variant) {
							$fonts[$font['font-family']]['all-styles'][] = $variant['id'];
						}
					}
				}*/


				if ( isset( $font['subsets'] ) && $font['subsets'] != '' ) {
					$fonts[ $font['font-family'] ]['subset'][] = $font['subsets'];
				}

			}

			if ( ! empty( $fonts ) ) {
				$google_link = $this->makeGoogleWebfontLink( $fonts );
				set_transient( KLEO_DOMAIN . '_google_link', $google_link, 12 * 60 * 60 );
			}
		} else {
			$google_link = get_transient( KLEO_DOMAIN . '_google_link' );
		}

		//Load Google Font
		if ( ! empty( $google_link ) ) {
			add_action( 'wp_enqueue_scripts', function () use ( $google_link ) {
				wp_register_style( 'kleo-google-fonts', $google_link, array(), '', 'all' );
				wp_enqueue_style( 'kleo-google-fonts' );
			} );
		}

	}

	public function add_font_css( $css = '' ) {

		global $kleo_config;
		$std_fonts = $this->get_std_fonts();
		$sections  = $kleo_config['font_sections'];
		$output    = '';


		foreach ( $sections as $section ) {
			$font = sq_option( 'font_' . $section );

			if ( in_array( $font['font-family'], $std_fonts ) ) {
				$is_google = false;
			} else {
				$is_google = true;
			}

			$selector = $section;
			if ( 'header' === $section ) {
				$selector = '#header';
			}

			//family
			if ( $font['font-family'] && '' != $font['font-family'] ) {
				if ( true === $is_google ) {
					$font_backup = '';
					if ( isset( $font['font-backup'] ) && ! empty( $font['font-backup'] ) ) {
						$font_backup = ', ' . $font['font-backup'];
					}
					$output .= $selector . ' {font-family:"' . $font['font-family'] . '"' . $font_backup . ';}';
				} else {
					$output .= $selector . ' {font-family:' . $font['font-family'] . ';}';
				}


				if ( $section === 'h1' ) {
					$output .= '.lead, .lead p, p.lead, article .article-meta .entry-date, .single-attachment .post-time, #buddypress #groups-list .item-title, .popover-title, .nav-tabs > li > a, .nav-pills > li > a, .panel-kleo .panel-title {font-family:' . $font['font-family'] . ';}';
					$output .= '#rtm-gallery-title-container .rtm-gallery-title, #item-body .rtmedia-container h2 {font-family:' . $font['font-family'] . ' !important;}';
				} elseif ( $section === 'body' ) {
					$output .= 'li.bbp-forum-info .bbp-forum-title, .woocommerce #accordion-woo .panel-title, .woocommerce ul.products li.product h3, .woocommerce-page ul.products li.product h3, .woocommerce .kleo-cart-totals .shipping-calculator-button {font-family:' . $font['font-family'] . ';}';
				}
			}
			//size
			if ( isset( $font['font-size'] ) && ! empty( $font['font-size'] ) ) {
				$output .= $selector . ' {font-size:' . $font['font-size'] . ';}';
			}
			//line-height
			if ( isset( $font['line-height'] ) && ! empty( $font['line-height'] ) ) {
				$output .= $selector . ' {line-height:' . $font['line-height'] . ';}';
			}
			//weight
			if ( isset( $font['font-weight'] ) && ! empty( $font['font-weight'] ) ) {
				$output .= $selector . ' {font-weight:' . $font['font-weight'] . ';}';
			}
			//letter spacing
			if ( isset( $font['letter-spacing'] ) && ! empty( $font['letter-spacing'] ) ) {
				$output .= $selector . ' {letter-spacing:' . $font['letter-spacing'] . ';}';
			}
		}
		return $css . $output;
	}

	public function makeGoogleWebfontLink( $fonts ) {
		$link    = "";
		$subsets = array();

		foreach ( $fonts as $family => $font ) {
			if ( ! empty( $link ) ) {
				$link .= "|"; // Append a new font to the string
			}
			$link .= $family;

			if ( isset( $font['font-style'] ) && ! empty( $font['font-style'] ) || ( isset( $font['all-styles'] ) && ! is_array( $font['all-styles'] ) ) ) {
				$link .= ':';
				if ( isset( $font['all-styles'] ) && ! is_array( $font['all-styles'] ) ) {
					$link .= implode( ',', $font['all-styles'] );
				} else if ( ! empty( $font['font-style'] ) ) {
					$link .= implode( ',', $font['font-style'] );
				}
			}

			if ( ! empty( $font['subset'] ) ) {
				foreach ( $font['subset'] as $subset ) {
					if ( ! in_array( $subset, $subsets ) ) {
						array_push( $subsets, $subset );
					}
				}

			}
		}

		if ( ! empty( $subsets ) ) {
			$link .= "&amp;subset=" . implode( ',', $subsets );
		}

		return '//fonts.googleapis.com/css?family=' . $link;

	}


	public static function get_saved_purchase_code() {
		return get_option( 'envato_purchase_code_' . self::get_config( 'item_id' ), '' );
	}

}

class Kleo {

}
