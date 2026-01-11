<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;

Container::make( 'theme_options', 'Custom Fonts' )
         ->set_page_file( 'k-fonts' )
         ->add_fields( array(
		         Field::make( 'complex', 'custom_fonts', 'Fonts' )
		              ->set_layout( 'tabbed-horizontal' )
		              ->add_fields( array(
			              Field::make( 'text', 'title', 'Name' ),
			              Field::make( 'text', 'name', 'Font Family Name' ),
			              Field::make( 'select', 'weight', 'Font Weight' )->add_options( array(
				              '400' => 'Regular',
				              '100' => '100',
				              '200' => '200',
				              '300' => '300',
				              '500' => '500',
				              '600' => '600',
				              '700' => '700',
				              '800' => '800',
				              '900' => '900',
			              ) ),
			              Field::make( 'select', 'style', 'Font Style' )->add_options( array(
				              'normal' => 'Normal',
				              'italic' => 'Italic',
			              ) ),
			              Field::make( 'file', 'woff2', '.woff2 font file' ),
			              Field::make( 'file', 'woff', '.woff font file' ),
			              Field::make( 'file', 'ttf', '.ttf font file' ),
			              Field::make( 'file', 'eot', '.eot font file' ),
			              Field::make( 'file', 'svg', '.svg font file' ),
		              ) )->set_header_template( '
				<% if (title) { %>
					<%- title %>
				<% } else { %>
					Font
				<% } %>'
			         ),
	         )
         );


add_action( 'carbon_fields_theme_options_container_saved', function ( $data ) {
	update_option( 'k-fonts', carbon_get_theme_option( 'custom_fonts' ) );
} );
add_action( 'carbon_fields_theme_options_container_saved', 'kleo_write_dynamic_css_file');


add_filter( "redux/kleo_" . str_replace( " ", "_", strtolower( wp_get_theme() ) ) . "/field/typography/custom_fonts", function ( $array ) {

	$fonts_options = get_option( 'k-fonts' );
	$fonts         = [];
	if ( $fonts_options && ! empty( $fonts_options ) ) {
		foreach ( $fonts_options as $font ) {
			$fonts[ $font['name'] ] = $font['name'];
		}
	}
	if ( ! empty( $fonts ) ) {

		$array["Custom Fonts"] = $fonts;


	}

	return $array;

} );
