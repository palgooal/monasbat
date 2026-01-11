<?php
/*
 * Functions used by K-elements only
 */


function find_shortcode_template( $shortcode ) {
	if ( file_exists( trailingslashit( get_stylesheet_directory() ) . 'k_elements/' . $shortcode . '.php' ) ) {
		return trailingslashit( get_stylesheet_directory() ) . 'k_elements/' . $shortcode . '.php';
	} elseif ( file_exists( trailingslashit( get_template_directory() ) . 'k_elements/' . $shortcode . '.php' ) ) {
		return trailingslashit( get_template_directory() ) . 'k_elements/' . $shortcode . '.php';
	} else {
		return trailingslashit( K_ELEM_PLUGIN_DIR ) . 'shortcodes/templates/' . $shortcode . '.php';
	}
}

function kleo_shortcode_not_found() {
	return "!! Shortcode template not found !!";
}


/* Buddypress */
if ( ! function_exists( 'kleo_bp_member_stats' ) ):

	/**
	 * @param bool|int|string $field
	 * @param bool|string $value
	 * @param bool $online
	 */
	function kleo_bp_member_stats( $field = false, $value = false, $online = false ) {
		global $wpdb;
		$bp_prefix = bp_core_get_table_prefix();

		if ( ! $field || ! $value ) {
			$where = '';
		} else {
			if ( is_int( $field ) ) {
				$where = " WHERE field_id = '" . $field . "' AND value = '" . esc_sql( $value ) . "'";
			} else {
				$where = " WHERE name = '" . $field . "' AND value = '" . esc_sql( $value ) . "'";
			}
		}

		$sql = "SELECT " . $bp_prefix . "bp_xprofile_data.user_id FROM " . $bp_prefix . "bp_xprofile_data
				JOIN " . $bp_prefix . "bp_xprofile_fields ON "
		       . $bp_prefix . "bp_xprofile_data.field_id = " . $bp_prefix . "bp_xprofile_fields.id
				$where";

		$match_ids = $wpdb->get_col( $sql );

		if ( ! $online ) {
			return count( $match_ids );
		}

		if ( ! $match_ids ) {
			$match_ids = array( 0 );
		}

		if ( ! empty( $match_ids ) ) {
			$include_members = '&include=' . implode( ',', $match_ids );
		} else {
			$include_members = '';
		}

		$i = 0;
		if ( bp_has_members( 'user_id=0&type=online&per_page=999999999&populate_extras=0' . $include_members ) ) :
			while ( bp_members() ) : bp_the_member();
				$i ++;
			endwhile;
		endif;

		return apply_filters( 'kleo_bp_member_stats', $i, $value );
	}
endif;


if ( ! function_exists( 'get_profile_id_by_name' ) ) :
	/**
	 * Return profile field ID by profile name
	 *
	 * @param string $name
	 *
	 * @return integer
	 * @global object $wpdb
	 *
	 */
	function get_profile_id_by_name( $name ) {
		global $wpdb;
		if ( ! isset( $name ) ) {
			return false;
		}

		$sql = "SELECT id FROM " . $wpdb->base_prefix . "bp_xprofile_fields WHERE name = '" . $name . "'";

		return $wpdb->get_var( $sql );
	}
endif;


if ( ! function_exists( 'get_group_id_by_name' ) ) :
	function get_group_id_by_name( $name ) {
		global $wpdb;
		if ( ! isset( $name ) ) {
			return false;
		}

		$sql = "SELECT id FROM " . $wpdb->base_prefix . "bp_xprofile_groups WHERE name = '" . $name . "'";

		return $wpdb->get_var( $sql );
	}
endif;


/**
 * @param $content
 * @param bool $autop
 *
 * @return string
 * @since 4.2
 */
function kleo_remove_wpautop( $content, $autop = false ) {

	if ( $autop ) {
		$content = preg_replace( '/<\/?p\>/', "", $content );
	}

	return do_shortcode( shortcode_unautop( $content ) );
}

if ( ! function_exists( 'kleo_build_query_loop' ) ) {
	/**
	 * Build WP_Query args from string
	 *
	 * @param string $posts_query
	 *
	 * @return array
	 */
	function kleo_build_query_loop( $posts_query ) {
		$args = [ 'post_status' => 'publish' ];

		$posts_query = explode( '|', $posts_query );
		if ( ! empty( $posts_query ) ) {
			foreach ( $posts_query as $item ) {
				$item = explode( ':', $item );
				if ( isset( $item[1] ) ) {
					if ( 'size' == $item[0] ) {
						$item[0] = 'posts_per_page';
						if ( 'all' == strtolower( $item[1] ) ) {
							$item[1] = - 1;
						} else {
							$item[1] = (int) $item[1];
						}
						$args[ $item[0] ] = $item[1];

					} elseif ( 'order_by' == $item[0] ) {
						$args['orderby'] = $item[1];
					} elseif ( 'categories' == $item[0] ) {
						$args['cat'] = $item[1];
					} elseif ( 'tags' == $item[0] ) {
						$val = kleo_vc_parse_tags( $item[1] );
						if ( ! empty( $val['tag__in'] ) ) {
							$args['tag__in'] = $val['tag__in'];
						}
						if ( ! empty( $val['tag__not_in'] ) ) {
							$args['tag__not_in'] = $val['tag__not_in'];
						}
					} elseif ( 'authors' == $item[0] ) {
						$args['author'] = $item[1];
					} elseif ( 'by_id' == $item[0] ) {
						$args['post__in'] = explode( ',', $item[1] );
					} elseif ( 'post_type' == $item[0] ) {
						$args['post_type'] = explode( ',', $item[1] );
					} elseif ( 'tax_query' == $item[0] ) {
						$args['tax_query'] = kleo_parse_tax_query( $item[1] );
						$item[1]           = kleo_parse_tax_query( $item[1] );
					} else {
						$args[ $item[0] ] = $item[1];
					}
				}
			}
		}

		return $args;
	}

}

function kleo_vc_parse_tags( $value ) {
	$args     = $in = $not_in = [];
	$tags_ids = kleo_string_to_array( $value );

	foreach ( $tags_ids as $tag ) {
		$tag = (int) $tag;
		if ( $tag < 0 ) {
			$not_in[] = abs( $tag );
		} else {
			$in[] = $tag;
		}
	}
	$args['tag__in']     = $in;
	$args['tag__not_in'] = $not_in;

	return $args;
}

if ( ! function_exists( 'kleo_parse_tax_query' ) ) {

	function kleo_parse_tax_query( $value ) {

		$args['tax_query'] = array( 'relation' => 'AND' );

		$terms              = kleo_string_to_array( $value );
		$negative_term_list = array();

		foreach ( $terms as $term ) {
			if ( (int) $term < 0 ) {
				$negative_term_list[] = abs( $term );
			}
		}

		$not_in = array();
		$in     = array();

		$terms = get_terms( kleo_get_taxonomies(), array( 'include' => array_map( 'abs', $terms ) ) );
		foreach ( $terms as $t ) {
			if ( in_array( (int) $t->term_id, $negative_term_list, true ) ) {
				$not_in[ $t->taxonomy ][] = $t->term_id;
			} else {
				$in[ $t->taxonomy ][] = $t->term_id;
			}
		}

		foreach ( $in as $taxonomy => $terms ) {
			$args['tax_query'][] = array(
				'field'    => 'term_id',
				'taxonomy' => $taxonomy,
				'terms'    => $terms,
				'operator' => 'IN',
			);
		}
		foreach ( $not_in as $taxonomy => $terms ) {
			$args['tax_query'][] = array(
				'field'    => 'term_id',
				'taxonomy' => $taxonomy,
				'terms'    => $terms,
				'operator' => 'NOT IN',
			);
		}

		return $args['tax_query'];
	}
}

if ( ! function_exists( 'kleo_string_to_array' ) ) {
	function kleo_string_to_array( $value ) {
		$valid_values = array();
		$list         = preg_split( '/\,[\s]*/', $value );
		foreach ( $list as $v ) {
			if ( strlen( $v ) > 0 ) {
				$valid_values[] = $v;
			}
		}

		return $valid_values;
	}
}

if ( ! function_exists( 'kleo_get_taxonomies' ) ) {
	function kleo_get_taxonomies() {
		$taxonomy_exclude   = (array) apply_filters( 'get_categories_taxonomy', 'category' );
		$taxonomy_exclude[] = 'post_tag';
		$taxonomies         = array();
		foreach ( get_taxonomies() as $taxonomy ) {
			if ( ! in_array( $taxonomy, $taxonomy_exclude, true ) ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}
}

/**
 * @param $content
 * @param bool $autop
 *
 * @return string
 * @since 4.2
 */
if ( ! function_exists( 'kleo_remove_wpautop' ) ) {
	function kleo_remove_wpautop( $content, $autop = false ) {

		if ( $autop ) {
			$content = wpautop( preg_replace( '/<\/?p\>/', "\n", $content ) . "\n" );
		}

		return do_shortcode( shortcode_unautop( $content ) );
	}
}

if ( ! function_exists( 'kleo_remove_filters_for_class' ) ) {
	/**
	 * Allow to remove method for an hook when, it's a class method used and class don't have variable, but you know the class name
	 *
	 * @param string $hook_name
	 * @param string $class_name
	 * @param string $method_name
	 * @param int $priority
	 *
	 * @return bool
	 */
	function kleo_remove_filters_for_class( $hook_name = '', $class_name = '', $method_name = '', $priority = 10 ) {
		global $wp_filter;
		// Take only filters on right hook name and priority
		if ( ! isset( $wp_filter[ $hook_name ] ) || ! isset( $wp_filter[ $hook_name ][ $priority ] ) || ! is_array( $wp_filter[ $hook_name ][ $priority ] ) ) {
			return false;
		}
		// Loop on filters registered
		foreach ( (array) $wp_filter[ $hook_name ][ $priority ] as $unique_id => $filter_array ) {
			// Test if filter is an array ! (always for class/method)
			if ( isset( $filter_array['function'] ) && is_array( $filter_array['function'] ) ) {
				// Test if object is a class, class and method is equal to param !
				if ( is_object( $filter_array['function'][0] ) && get_class( $filter_array['function'][0] ) && get_class( $filter_array['function'][0] ) == $class_name && $filter_array['function'][1] == $method_name ) {
					// Test for WordPress >= 4.7 WP_Hook class (https://make.wordpress.org/core/2016/09/08/wp_hook-next-generation-actions-and-filters/)
					if ( is_a( $wp_filter[ $hook_name ], 'WP_Hook' ) ) {
						unset( $wp_filter[ $hook_name ]->callbacks[ $priority ][ $unique_id ] );
					} else {
						unset( $wp_filter[ $hook_name ][ $priority ][ $unique_id ] );
					}
				}
			}
		}

		return false;
	}
}
