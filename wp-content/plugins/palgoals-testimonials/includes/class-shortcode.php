<?php
/**
 * Shortcode handler.
 *
 * @package PalgoalsTestimonials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Palgoals_Testimonials_Shortcode {

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'palgoals_testimonials', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render the testimonials shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'skin'    => 'default',
				'layout'  => 'grid',
				'columns' => 3,
				'rating'  => 0,
				'limit'   => 6,
				'order'   => 'DESC',
			),
			$atts,
			'palgoals_testimonials'
		);

		return Palgoals_Testimonials_Renderer::render(
			array(
				'skin'           => sanitize_key( $atts['skin'] ),
				'layout'         => sanitize_key( $atts['layout'] ),
				'columns'        => absint( $atts['columns'] ),
				'columns_tablet' => max( 1, min( 3, absint( $atts['columns'] ) ) ),
				'columns_mobile' => 1,
				'rating'         => absint( $atts['rating'] ),
				'limit'          => absint( $atts['limit'] ),
				'order'          => sanitize_text_field( $atts['order'] ),
			)
		);
	}
}
