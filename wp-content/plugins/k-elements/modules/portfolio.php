<?php
/*
*
*	Portfolio Items Functions
*	KLEO Framework
*
*/

if ( sq_option( 'module_portfolio', 1 ) != 1 ) {
	return;
}

/* Register Custom Post Type
 * ==================================================
*/

class Portfolio_Post_Type extends Kleo_Post_types {

	public function __construct() {
		$this->labels              = [];
		$portfolio_name            = sq_option( 'portfolio_name', 'Portfolio' );
		$this->labels['portfolio'] = array(
			'singular' => esc_html__( 'Portfolio item', 'kleo' ),
			'plural'   => esc_html__( $portfolio_name, 'k-elements' ),
			'menu'     => esc_html__( 'Portfolio', 'kleo' ),
		);

		add_action( 'init', array( $this, 'setup_post_type' ), 7 );
	}

	/**
	 * Setup Portfolio post type
	 * @return void
	 * @since  1.5
	 */
	public function setup_post_type() {

		$has_archive = sq_option( 'portfolio_custom_archive', '0' ) == '1' ? false : true;

		/* Default WordPress post archive page */
		if ( $has_archive ) {
			$slug = sq_option( 'portfolio_slug', 'portfolio' );
			$slug = apply_filters( 'kleo_portfolio_slug', $slug );
		} /* Custom page for archive */
		else {
			$page_id = sq_option( 'portfolio_page' );
			$page    = get_post( $page_id );
			$slug    = $page->post_name;
		}

		$args = array(
			'labels'             => $this->get_labels( 'portfolio', $this->labels['portfolio']['singular'], $this->labels['portfolio']['plural'], $this->labels['portfolio']['menu'] ),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'menu_icon'          => 'dashicons-format-image',
			'query_var'          => true,
			'rewrite'            => array(
				'slug'       => esc_attr( $slug ),
				'feeds'      => true,
				'with_front' => false,
			),
			'has_archive'        => $has_archive,
			'hierarchical'       => false,
			'menu_position'      => 20, // Below "Pages"
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'custom-fields' ),
		);

		register_post_type( 'portfolio', $args );

		register_taxonomy_for_object_type( 'post_tag', 'portfolio' );


		$args = array(
			'label'             => esc_html_x( 'Portfolio Categories', 'category label', 'kleo' ),
			'singular_label'    => esc_html_x( 'Portfolio Category', 'category singular label', 'kleo' ),
			'public'            => true,
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_in_nav_menus' => false,
			'args'              => array( 'orderby' => 'term_order' ),
			'rewrite'           => array(
				'slug'         => apply_filters( 'kleo_portfolio_cat_slug', 'portfolio-category' ),
				'with_front'   => false,
				'hierarchical' => true,
			),
			'query_var'         => true,
		);

		register_taxonomy( apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ), 'portfolio', $args );


	} // End setup_portfolio_post_type()

}

$kleo_portfolio = new Portfolio_Post_Type();
if ( class_exists( 'SVQ_FW' ) ) {
	SVQ_FW::set_module( 'portfolio', $kleo_portfolio );
}

add_action( 'wp_ajax_nopriv_portfolio_items', 'sq_portfolio_ajax' );
add_action( 'wp_ajax_portfolio_items', 'sq_portfolio_ajax' );

function sq_portfolio_ajax() {
	// Check the nonce, if it fails the function will break
	check_ajax_referer( 'kleo-ajax-portfolio-nonce', 'security' );

	/* If not our action, bail out */
	if ( ! isset( $_POST['action'] ) || 'portfolio_items' != $_POST['action'] ) {
		return false;
	}

	$cat_id   = $_POST['pid'];
	$count_id = (int) $_POST['pitem'];
	$post_id  = $_POST['post_id'];
	$cat      = get_term_by( 'id', $cat_id, apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ) );

	$args = get_transient( 'kleo_portfolio_' . $post_id . '_' . $count_id );
	if ( $args ) {
		$args = maybe_unserialize( $args );
	}

	if ( ! is_array( $args ) ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Something went wrong. Please reload page.', 'kleo' ) ) );
		exit;
	}
	$args['filter']   = '';
	$args['category'] = $cat->slug;

	if ( isset( $_POST['url'] ) ) {
		$str_url = $_POST['url'];
		$str_arr = explode( '/', $str_url );
		$found   = 0;
		foreach ( $str_arr as $index => $value ) {
			if ( 'page' == $value ) {
				$found = $index;
			}
		}
		if ( 0 !== $found ) {
			$place         = $found + 1;
			$paged         = $str_arr[ $place ];
			$args['paged'] = $paged;
		}
	}

	$items = call_user_func_array( 'kleo_portfolio_items', $args );
	wp_send_json_success( array( 'message' => $items ) );
	exit;
}


/* PORTFOLIO ITEMS
================================================== */
if ( ! function_exists( 'kleo_portfolio_items' ) ) {

	function kleo_portfolio_items(
		$display_type = 'default',
		$title_style = 'normal',
		$columns = 4,
		$item_count = null,
		$pagination = 'no',
		$filter = 'yes',
		$excerpt = 'yes',
		$img_width = '',
		$img_height = '',
		$category = '',
		$exclude_categories = array(),
		$ajax = false,
		$paged = ''
	) {


		/* OUTPUT VARIABLE
		================================================== */
		$portfolio_items_output = '';
		$count                  = 0;

		/* CATEGORY SLUG
		================================================== */
		if ( $category == 'All' || $category == 'all' ) {
			$category = '';
		}
		//$category_slug = str_replace( '_', '-', $category );

		/* PORTFOLIO QUERY
		================================================== */

		if ( '' == $paged ) {
			if ( get_query_var( 'paged' ) ) {
				$paged = get_query_var( 'paged' );
			} elseif ( get_query_var( 'page' ) ) {
				$paged = get_query_var( 'page' );
			} else {
				$paged = 1;
			}
		}


		if ( '' == $exclude_categories ) {
			$exclude_categories = [];
		}

		$portfolio_args = array(
			'post_type'                                                          => 'portfolio',
			'post_status'                                                        => 'publish',
			'paged'                                                              => $paged,
			apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ) => $category,
			'posts_per_page'                                                     => $item_count,
		);

		if ( ! empty( $exclude_categories )) {
			$portfolio_args['tax_query'] = [
				[
					'taxonomy' => apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ),
					'field'    => 'id',
					'terms'    => $exclude_categories,
					'operator' => 'NOT IN',
				]
			];
		}
		if ( ! empty( $category )) {
			$portfolio_args['tax_query'] = [
				[
					'taxonomy' => apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ),
					'field'    => 'slug',
					'terms'    => $category,
				]
			];
		}


		$portfolio_args = apply_filters( 'portfolio_args', $portfolio_args );

		$portfolio_items = new WP_Query( $portfolio_args );


		/* Class config
		================================================== */
		$classes = '';

		$classes .= ' ' . $display_type . '-style';

		if ( $display_type === 'overlay' ) {
			if ( $title_style === 'hover' ) {
				$classes .= ' anim-title';
			}
		}

		if ( $excerpt === 'no' ) {
			$classes .= ' hide-excerpt';
		}

		$classes .= ' per-row-' . $columns;

		/* IMAGE SIZE
		 * Set global variables to be available in the portfolio template part
		================================================== */
		if ( $img_width != '' && $img_height != '' ) {
			global $portfolio_img_width, $portfolio_img_height;
			$portfolio_img_width  = $img_width;
			$portfolio_img_height = $img_height;
		}

		/* ITEMS OUTPUT
		================================================== */

		if ( $ajax ) {
			global $portfolio_count;
			$portfolio_count ++;

			$all_options = compact(
				'display_type',
				'title_style',
				'columns',
				'item_count',
				'pagination',
				'filter',
				'excerpt',
				'img_width',
				'img_height',
				'category',
				'exclude_categories',
				'ajax'
			);
			set_transient( 'kleo_portfolio_' . get_the_ID() . '_' . $portfolio_count, serialize( $all_options ) );
		} else {
			$portfolio_count = 0;
		}


		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			/* it's an AJAX call */
		} else {
			$portfolio_items_output .= '<div class="portfolio-data" style="display: none;">';
			$portfolio_items_output .= wp_nonce_field( 'kleo-ajax-portfolio-nonce', 'portfolio-security', true, false );
			$portfolio_items_output .= '<input type="hidden" name="pitem" value="' . $portfolio_count . '">';
			$portfolio_items_output .= '<input type="hidden" name="post_id" value="' . get_the_ID() . '">';
			$portfolio_items_output .= '</div>';
		}

		if ( 'yes' == $filter ) {
			$portfolio_items_output .= kleo_portfolio_filter( $category, $exclude_categories, $ajax );
		}

		$portfolio_items_output .= '<div class="portfolio-wrapper">';

		$portfolio_items_output .= '<ul class="portfolio-items responsive-cols kleo-masonry ' . $classes . '">' . "\n";

		$cats = array();

		while ( $portfolio_items->have_posts() ) : $portfolio_items->the_post();

			if ( 'yes' == $filter ) {
				$post_categories = wp_get_post_categories( get_the_ID() );
				foreach ( $post_categories as $c ) {
					$cat                = get_category( $c );
					$cats[ $cat->slug ] = $cat->slug;
				}
			}

			ob_start();
			get_template_part( 'page-parts/portfolio-masonry' );
			$portfolio_items_output .= ob_get_clean();

			$count ++;

		endwhile;

		wp_reset_postdata();

		$portfolio_items_output .= '</ul>' . "\n";

		/* PAGINATION OUTPUT
		================================================== */
		if ( 'yes' == $pagination ) {
			$class_prefix = '';
			if ( $ajax ) {
				$class_prefix = 'ajax-';
			}

			$portfolio_items_output .= '<div class="' . $class_prefix . 'pagination-wrap">';
			$portfolio_items_output .= kleo_pagination( $portfolio_items->max_num_pages, false, $paged );
			$portfolio_items_output .= '</div>';
		}

		$portfolio_items_output .= '</div>';


		/* FUNCTION OUTPUT
		================================================== */

		return $portfolio_items_output;
	}
}


/* PORTFOLIO FILTER
================================================== */
if ( ! function_exists( 'kleo_portfolio_filter' ) ) {
	function kleo_portfolio_filter( $parent_category = '', $exclude_categories = array(), $ajax = false ) {

		$filter_output = $tax_terms = '';

		if ( $parent_category == '' || $parent_category == 'all' ) {
			$tax_terms = kleo_get_category_list( apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ), 0 );
		} else {
			$tax_terms = kleo_get_category_list( apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ), 0, $parent_category );
		}

		$class_prefix = '';
		if ( $ajax ) {
			$class_prefix = 'ajax-';
		}

		$filter_output .= '<div class="' . $class_prefix . 'filter-wrap row clearfix">' . "\n";
		$filter_output .= '<ul class="portfolio-filter-tabs bar-styling filtering col-sm-12 clearfix">' . "\n";
		$filter_output .= '<li class="all selected"><a data-filter="*" href="#"><span class="item-name">' . esc_html__( 'All', 'kleo' ) . '</span></a></li>' . "\n";
		foreach ( $tax_terms as $tax_term ) {
			$term = get_term_by( 'slug', $tax_term, apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ) );
			if ( $term && ! in_array( $term->term_id, $exclude_categories ) ) {
				$filter_output .= '<li><a href="#" data-filter=".' . $term->slug . '" data-id="' . $term->term_id . '"><span class="item-name">' . $term->name . '</span></a></li>' . "\n";
			}
		}
		$filter_output .= '</ul></div>' . "\n";

		return $filter_output;
	}
}

// Add portfolio meta box
add_filter( 'kleo_meta_boxes', 'kleo_portfolio_meta' );

function kleo_portfolio_meta( $meta_boxes ) {
	$prefix       = '_kleo_';
	$meta_boxes[] = array(
		'id'         => 'theme_portfolio',
		'title'      => 'Portfolio settings',
		'pages'      => array( 'portfolio' ), // Post type
		'context'    => 'normal',
		'priority'   => 'default',
		'show_names' => true, // Show field names on the left
		'fields'     => array(
			array(
				'name'    => 'Show media on single item page',
				'desc'    => 'If you want to show image/gallery/video before the content on single portfolio page',
				'id'      => $prefix . 'portfolio_media_status',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => 'Default' ),
					array( 'value' => '1', 'name' => 'Yes' ),
					array( 'value' => '0', 'name' => 'No' ),
				),
				'value'   => '',
			),
			array(
				'name'    => 'Media type',
				'desc'    => '',
				'id'      => $prefix . 'media_type',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => 'Featured Image' ),
					array( 'value' => 'slider', 'name' => 'Image Slider' ),
					array( 'value' => 'hosted_video', 'name' => 'Hosted Video' ),
					array( 'value' => 'video', 'name' => 'oEmbed Video' ),

				),
				'value'   => '',
			),
			array(
				'name'  => 'Slider',
				'desc'  => 'Used when you select the Gallery format. Upload an image or enter an URL.',
				'id'    => $prefix . 'slider',
				'type'  => 'file_repeat',
				'allow' => 'url',
			),
			array(
				'name' => 'Video oEmbed URL',
				'desc' => 'Used when you select Video format. Enter a Youtube, Vimeo, Soundcloud, etc URL. See supported services at <a target="_blank" href="http://codex.wordpress.org/Embeds">http://codex.wordpress.org/Embeds</a>.',
				'id'   => $prefix . 'embed',
				'type' => 'oembed',
			),

			array(
				'name' => 'Video Self hosted(mp4)',
				'desc' => 'Used when you select Video format. Upload your MP4 video file. Setting a self hosted video will ignore Video oEmbed above.',
				'id'   => $prefix . 'video_mp4',
				'type' => 'file',
			),
			array(
				'name' => 'Video Self hosted(ogv)',
				'desc' => 'Used when you select Video format. Upload your OGV video file.',
				'id'   => $prefix . 'video_ogv',
				'type' => 'file',
			),
			array(
				'name' => 'Video Self hosted(webm)',
				'desc' => 'Used when you select Video format. Upload your WEBM video file.',
				'id'   => $prefix . 'video_webm',
				'type' => 'file',
			),
			array(
				'name' => 'Video Self hosted Poster',
				'desc' => 'Used to show before the video loads',
				'id'   => $prefix . 'video_poster',
				'type' => 'file',
			),
			array(
				'name'    => 'Top bar status',
				'desc'    => 'Enable/disable site top bar',
				'id'      => $prefix . 'topbar_status',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => 'Default' ),
					array( 'value' => '1', 'name' => 'Visible' ),
					array( 'value' => '0', 'name' => 'Hidden' ),
				),
				'value'   => '',
			),
			array(
				'name'  => 'Hide Header',
				'desc'  => 'Check to hide whole header area',
				'id'    => $prefix . 'hide_header',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name'  => 'Hide Footer',
				'desc'  => 'Check to hide whole footer area',
				'id'    => $prefix . 'hide_footer',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name'  => 'Hide Socket area',
				'desc'  => 'Check to hide the area after footer that contains copyright info.',
				'id'    => $prefix . 'hide_socket',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name' => 'Custom Logo',
				'desc' => 'Use a custom logo for this page only',
				'id'   => $prefix . 'logo',
				'type' => 'file',
			),
			array(
				'name' => 'Custom Logo Retina',
				'desc' => 'Use a custom retina logo for this page only',
				'id'   => $prefix . 'logo_retina',
				'type' => 'file',
			),
			array(
				'name'  => 'Transparent Main menu',
				'desc'  => 'Check to have Main menu background transparent.',
				'id'    => $prefix . 'transparent_menu',
				'type'  => 'checkbox',
				'value' => '1'
			),

			array(
				'name'  => 'Hide the title',
				'desc'  => 'Check to hide the title when displaying the post/page',
				'id'    => $prefix . 'title_checkbox',
				'type'  => 'checkbox',
				'value' => '1'
			),
			array(
				'name'    => 'Breadcrumb',
				'desc'    => '',
				'id'      => $prefix . 'hide_breadcrumb',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => 'Default' ),
					array( 'value' => '0', 'name' => 'Visible' ),
					array( 'value' => '1', 'name' => 'Hidden' )
				),
				'value'   => ''
			),
			array(
				'name'  => 'Hide information',
				'desc'  => 'Check to hide contact info in title section',
				'id'    => $prefix . 'hide_info',
				'type'  => 'checkbox',
				'value' => '1'
			)

		),
	);

	return $meta_boxes;
}

add_filter( 'kleo_localize_app', 'kleo_portfolio_localize' );

function kleo_portfolio_localize( $obj_array ) {
	if ( (int) sq_option( 'portfolio_video_height', 160 ) ) {
		$obj_array['portfolioVideoHeight'] = (int) sq_option( 'portfolio_video_height', 160 );
	}

	return $obj_array;
}