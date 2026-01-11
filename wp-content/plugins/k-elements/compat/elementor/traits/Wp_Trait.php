<?php

namespace K_Elements\Compat\Elementor\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

trait Wp_Trait {

	/**
	 * Get users
	 * @return array
	 */
	public static function get_user_fields( $q ) {
		$data = [];

		$users = new \WP_User_Query( array(
			'search'         => '*' . esc_attr( $q ) . '*',
			'search_columns' => array(
				'user_login',
				'user_nicename',
				'user_email',
				'ID'
			),
			'number'         => 20
		) );

		$users_found = $users->get_results();

		if ( $users_found ) {
			foreach ( $users_found as $found ) {
				$data[ $found->ID ] = $found->display_name;
			}
		}

		return $data;
	}

	/**
	 * Get terms
	 * @return array
	 */
	public static function get_category_fields( $q ) {

		$results = [];
		$taxonomy = 'category';

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'search'     => $q,
				'hide_empty' => false,
			]
		);
		if ( ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				$term_name = $term->name;

				//if ( empty( $data['object_type'] ) ) {
				//$taxonomy  = get_taxonomy( $term->taxonomy );
				//$term_name = $taxonomy->labels->singular_name . ': ' . $term_name;
				//}

				$results[ $term->term_id ] = $term_name;
			}
		} else {
			$cat = get_term_by( 'term_id', $q, $taxonomy );
			if ( $cat ) {
				$results[ $cat->term_id ] = $cat->name;
			}
		}

		return $results;
	}


}
