<?php
/*
 * Post types creation class
 *
 */


abstract class Kleo_Post_types {

	protected $labels;

	public function __construct() {
		$this->labels = array();

	}

	/**
	 * Create the labels to be used in post type creation
	 *
	 * @param string $token The post type for which to setup labels
	 * @param string $singular Label for singular post type
	 * @param string $plural Label for plural post type
	 * @param string $menu Menu item label
	 *
	 * @return array            Labels array
	 * @since  1.0
	 */
	protected function get_labels( $token, $singular, $plural, $menu ) {
		$labels = array(
			'name'               => sprintf( esc_html_x( '%s', 'post type general name', 'kleo' ), $plural ),
			'singular_name'      => sprintf( esc_html_x( '%s', 'post type singular name', 'kleo' ), $singular ),
			'add_new'            => sprintf( esc_html__( 'Add New %s', 'kleo' ), $singular ),
			'add_new_item'       => sprintf( esc_html__( 'Add New %s', 'kleo' ), $singular ),
			'edit_item'          => sprintf( esc_html__( 'Edit %s', 'kleo' ), $singular ),
			'new_item'           => sprintf( esc_html__( 'New %s', 'kleo' ), $singular ),
			'all_items'          => sprintf( esc_html__( 'All %s', 'kleo' ), $plural ),
			'view_item'          => sprintf( esc_html__( 'View %s', 'kleo' ), $singular ),
			'search_items'       => sprintf( esc_html__( 'Search %s', 'kleo' ), $plural ),
			'not_found'          => sprintf( esc_html__( 'No %s found', 'kleo' ), strtolower( $plural ) ),
			'not_found_in_trash' => sprintf( esc_html__( 'No %s found in Trash', 'kleo' ), strtolower( $plural ) ),
			'parent_item_colon'  => '',
			'menu_name'          => sprintf( esc_html_x( '%s', 'post type menu name', 'kleo' ), $menu )
		);

		return $labels;
	} // End get_labels()

}

/**
 * Show other post types in tag archive page
 *
 * @param object $query
 *
 * @return object
 */
if ( ! function_exists( 'kleo_archive_add_custom_types' ) ) {
	function kleo_archive_add_custom_types( $query ) {
		$post_args = array(
			'public'   => true,
			'_builtin' => false
		);

		$types_return = 'objects'; // names or objects, note names is the default
		$post_types   = get_post_types( $post_args, $types_return );

		$kleo_post_types = array( 'post', 'page' );

		foreach ( $post_types as $post_type ) {
			$kleo_post_types[] = $post_type->name;
		}

		if ( is_tag() && empty( $query->query_vars['suppress_filters'] ) && $query->is_main_query() ) {
			$query->set( 'post_type', $kleo_post_types );
		}

		return $query;
	}
}

add_filter( 'pre_get_posts', 'kleo_archive_add_custom_types' );

/**
 * Show other post types in tag archive page
 *
 * @param object $query
 *
 * @return object
 */
if ( ! function_exists( 'kleo_search_by_custom_post_types' ) ) {
	function kleo_search_by_custom_post_types( $query ) {

		if ( isset( $_GET['post_type'] ) && is_array( $_GET['post_type'] ) && $query->is_main_query() && $query->is_search() ) {
			$post_types = $_GET['post_type'];
			foreach ( $post_types as $k => $post_type ) {
				if ( ! post_type_exists( $post_type ) ) {
					unset( $post_types[$k] );
				}
			}
			if ( ! empty( $post_types ) ) {
				$query->set( 'post_type', $post_types );
			}
		}
		return $query;
	}
}
add_filter( 'pre_get_posts', 'kleo_search_by_custom_post_types' );
