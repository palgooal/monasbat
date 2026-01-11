<?php
/**
 * Elementor integration
 */

namespace K_Elements\Compat\Elementor;

use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Config {

	private static $instance = null;

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function init() {
		add_action( 'elementor/elements/categories_registered', [ $this, 'add_widget_categories' ], 9 );
		add_action( 'elementor/widgets/widgets_registered', [ $this, 'widgets_registered' ], 9 );

		//JS for Elements only on edit screen
		if ( defined( 'ELEMENTOR_VERSION' ) && isset( $_GET['elementor-preview'] ) ) {
			add_action( 'wp_footer', [ $this, 'wp_footer' ] );
		}

        add_action('elementor/init', function () {

            $path = K_ELEM_PLUGIN_DIR . 'compat/elementor/';

	        require_once $path . 'traits/Wp_Trait.php';

	        // Query helpers.
	        require_once $path . 'Ajax.php';
	        require_once $path . 'FunctionCaller.php';
	        require_once $path . 'controls/Query.php';
	        require_once $path . 'Controls.php';

	        new Ajax();

	        add_action( 'elementor/controls/controls_registered', [ new Controls(), 'on_controls_registered' ] );
	        add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'after_load_panel_assets' ] );

        });

	}

	/**
	 * After load panel assets
	 *
	 * @return void
	 */
	public function after_load_panel_assets() {
		wp_enqueue_script(
			'stax-visibility-script-editor',
			K_ELEM_PLUGIN_URL . '/compat/elementor/assets/js/editor.js',
			[],
			K_ELEM_VERSION,
			false
		);
	}

	public function get_elements() {
		$elements = [
			'posts'          => [
				'cat'   => 'kleo-elements',
				'class' => 'Posts',
			],
			'news-focus'     => [
				'cat'   => 'kleo-elements',
				'class' => 'NewsFocus',
			],
			'news-highlight' => [
				'cat'   => 'kleo-elements',
				'class' => 'NewsHighlight',
			],
			'news-puzzle'    => [
				'cat'   => 'kleo-elements',
				'class' => 'NewsPuzzle',
			],
			'news-ticker'    => [
				'cat'   => 'kleo-elements',
				'class' => 'NewsTicker',
			],
			'search'         => [
				'cat'   => 'kleo-elements',
				'class' => 'Search',
			],
			'divider'        => [
				'cat'   => 'kleo-elements',
				'class' => 'Divider',
			],
			'portfolio'      => [
				'cat'   => 'kleo-elements',
				'class' => 'Portfolio',
			],
			'stats'          => [
				'cat'   => 'kleo-elements',
				'class' => 'Stats',
			],
			'login'          => [
				'cat'   => 'kleo-elements',
				'class' => 'Login',
			],
			'register'       => [
				'cat'   => 'kleo-elements',
				'class' => 'Register',
			],
			'revslider'      => [
				'cat'   => 'kleo-elements',
				'class' => 'Revslider',
			],
			'social-share'   => [
				'cat'   => 'kleo-elements',
				'class' => 'SocialShare',
			],
			'tabs'           => [
				'cat'   => 'kleo-elements',
				'class' => 'Tabs',
			],
			'testimonials'   => [
				'cat'   => 'kleo-elements',
				'class' => 'Testimonials',
			],
			'clients'        => [
				'cat'   => 'kleo-elements',
				'class' => 'Clients',
			],
		];

		if ( function_exists( 'bp_is_active' ) ) {
			$elements += [
				'bp-activity-page'    => [
					'cat'   => 'kleo-elements',
					'class' => 'BpActivityPage',
				],
				'bp-activity-stream'  => [
					'cat'   => 'kleo-elements',
					'class' => 'BpActivityStream',
				],
				'bp-groups-carousel'  => [
					'cat'   => 'kleo-elements',
					'class' => 'BpGroupsCarousel',
				],
				'bp-groups-grid'      => [
					'cat'   => 'kleo-elements',
					'class' => 'BpGroupsGrid',
				],
				'bp-groups-masonry'   => [
					'cat'   => 'kleo-elements',
					'class' => 'BpGroupsMasonry',
				],
				'bp-members-carousel' => [
					'cat'   => 'kleo-elements',
					'class' => 'BpMembersCarousel',
				],
				'bp-members-grid'     => [
					'cat'   => 'kleo-elements',
					'class' => 'BpMembersGrid',
				],
				'bp-members-masonry'   => [
					'cat'   => 'kleo-elements',
					'class' => 'BpMembersMasonry',
				],
			];
		}

		if ( class_exists( 'bbPress' ) ) {
			$elements += [
				'bbp-search' => [
					'cat'   => 'kleo-elements',
					'class' => 'BbPressSearch',
				]
			];
		}

		return $elements;
	}

	public function get_tpl_path( $name ) {
		$widget_file   = 'overrides/elementor/' . $name . '.php';
		$template_file = locate_template( $widget_file );
		if ( ! $template_file || ! is_readable( $template_file ) ) {
			$template_file = __DIR__ . '/widgets/' . $name . '.php';
		}
		if ( $template_file && is_readable( $template_file ) ) {
			return $template_file;
		}

		return false;
	}

	public function add_widget_categories( $elements ) {

		$elements->add_category( 'kleo-elements',
			[
				'title' => 'KLEO',
				'icon'  => 'fa fa-plug'
			]
		);

	}

	public function widgets_registered() {

		if ( defined( 'ELEMENTOR_PATH' ) && class_exists( 'Elementor\Widget_Base' ) ) {

            // get our own widgets up and running.
			if ( class_exists( 'Elementor\Plugin' ) ) {
				if ( is_callable( 'Elementor\Plugin', 'instance' ) ) {
					$elementor = \Elementor\Plugin::instance();

					if ( isset( $elementor->widgets_manager ) ) {
						if ( method_exists( $elementor->widgets_manager, 'register_widget_type' ) ) {

							$elements = $this->get_elements();
							foreach ( $elements as $k => $element ) {
								if ( $template_file = $this->get_tpl_path( $k ) ) {

									require_once $template_file;
									$class_name = 'K_Elements\\Compat\\Elementor\\Widgets\\' . $element['class'];
									\Elementor\Plugin::instance()->widgets_manager->register_widget_type( new $class_name() );
								}
							}
						}
					}
				}
			}
		}
	}


	/**
	 * Loads only in edit mode
	 */
	public function wp_footer() {
		?>
        <script>
            jQuery(function ($) {

                if (typeof elementor != "undefined" && typeof elementor.settings.page != "undefined") {
                    elementor.settings.page.addChangeCallback('svq_transparent_header', svqElementorRefreshPage);
                    elementor.settings.page.addChangeCallback('svq_transparent_menu_color', svqElementorRefreshPage);
                }

                function svqElementorRefreshPage(newValue) {

                    elementor.reloadPreview();
                }

                jQuery(window).on('elementor/frontend/init', function () {
                    if (window.elementorFrontend) {
                        elementorFrontend.hooks.addAction('frontend/element_ready/widget', function ($scope) {
                            if (jQuery($scope).find('.kleo-carousel-items')) {
                                setTimeout(function () {
                                    if ($.fn.carouFredSel) {
                                        KLEO.main.carouselItems()
                                    }
                                }, 200);
                            }
                        });
                    }
                });
            });
        </script>
		<?php
	}

	/**
	 * Generate custom query controls
	 *
	 * @param $el
	 */
	public static function generate_query_controls( $el ) {

		$ptargs = [
			'public' => true
		];

		$post_types = [];
		$posts      = get_post_types( $ptargs, 'objects' );
		foreach ( $posts as $post_type ) {
			$post_types[ strtolower( $post_type->name ) ] = $post_type->name;
		}


		$el->add_control(
			'post_type',
			[
				'label'    => __( 'Post type', 'k-elements' ),
				'type'     => Controls_Manager::SELECT2,
				'multiple' => true,
				'default'  => [ 'post' ],
				'options'  => $post_types,
			]

		);
		$el->add_control(
			'size',
			[
				'label'   => __( 'Post count ', 'k-elements' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => '12',
			]
		);

		$el->add_control(
			'categories',
			[
				'label'    => __( 'Categories ', 'k-elements' ),
				'type'     => 'stax_query',
				'query_type'  => 'fields',
				'object_type' => 'category',
				'label_block' => true,
				'multiple' => true,
			]
		);
		$el->add_control(
			'tags',
			[
				'label'       => __( 'Tags', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Add comma separated tag IDs', 'k-elements' ),
			]
		);

		$el->add_control(
			'tax_query',
			[
				'label'       => __( 'Taxonomies ', 'k-elements' ),
				'description' => __( 'Add comma separated taxonomy IDs', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
			]
		);

		$el->add_control(
			'authors',
			[
				'label'    => __( 'Authors', 'k-elements' ),
				'type'     => 'stax_query',
				'query_type'  => 'fields',
				'object_type' => 'user',
				'label_block' => true,
				'multiple' => true,
			]
		);

		$el->add_control(
			'order_by',
			[
				'label'   => __( 'Order by ', 'k-elements' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => [
					''              => '',
					'date'          => 'Date',
					'author'        => 'Author',
					'title'         => 'Title',
					'modified'      => 'Modified',
					'random'        => 'Random',
					'comment_count' => 'Comment_count',
					'menu_order'    => 'Menu_order'
				]
			]
		);

		$el->add_control(
			'order',
			[
				'label'   => __( 'Sort Order ', 'k-elements' ),
				'type'    => Controls_Manager::SELECT2,
				'default' => '',
				'options' => [
					''     => '',
					'ASC'  => 'ASC',
					'DESC' => 'DESC',
				]
			]
		);

		$el->add_control(
			'query_offset',
			[
				'label'   => __( 'Query Offset', 'k-elements' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 0,
			]
		);

	}

	public static function generate_query_string( $values, $query_name = 'posts_query' ) {

		$attributes = '';
		$attributes .= ' ' . $query_name . '="size:' . $values['size'] . '|';
		$attributes .= ! empty ( $values['order_by'] ) ? 'order_by:' . $values['order_by'] . '|' : '';
		$attributes .= ! empty ( $values['order'] ) ? 'order:' . $values['order'] . '|' : '';
		$attributes .= ! empty ( $values['post_type'] ) ? 'post_type:' . implode( ',', $values['post_type'] ) . '|' : '';
		$attributes .= ! empty ( $values['authors'] ) ? 'authors:' . implode( ',', $values['authors'] ) . '|' : '';
		$attributes .= ! empty ( $values['categories'] ) ? 'categories:' . implode( ',', $values['categories'] ) . '|' : '';
		$attributes .= ! empty ( $values['tags'] ) ? 'tags:' . $values['tags'] . '|' : '';
		$attributes .= ! empty ( $values['tax_query'] ) ? 'tax_query:' . $values['tax_query'] . '|' : '';
		$attributes .= ! empty ( $values['by_id'] ) ? 'by_id:' . $values['by_id'] . '|' : '';
		$attributes .= '"';
		$attributes .= ! empty( $values['query_offset'] ) ? ' query_offset="' . $values['query_offset'] . '"' : '';

		return $attributes;
	}
}

Config::get_instance()->init();
