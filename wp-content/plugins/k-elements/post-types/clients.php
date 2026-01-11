<?php

/*
 * Clients Post type creation class
 *
 */


class Clients_Post_Type extends Kleo_Post_types {

	public function __construct() {
		$this->labels            = array();
		$this->labels['clients'] = array(
			'singular' => __( 'Client', 'kleo' ),
			'plural'   => __( 'Clients', 'kleo' ),
			'menu'     => __( 'Clients', 'kleo' )
		);

		add_action( 'init', array( &$this, 'setup_post_type' ), 7 );
	}

	/**
	 * Setup Clients post type
	 * @since  1.0
	 * @return void
	 */
	public function setup_post_type() {

		$args = array(
			'labels'             => $this->get_labels( 'clients', $this->labels['clients']['singular'], $this->labels['clients']['plural'], $this->labels['clients']['menu'] ),
			'public'             => true,
			'publicly_queryable' => sq_option( 'client_publicly_queryable', 1 ) ? true : false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'menu_icon'          => 'dashicons-businessman',
			'query_var'          => true,
			'rewrite'            => array( 'slug' => esc_attr( apply_filters( 'kleo_clients_slug', 'clients' ) ) ),
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => 20, // Below "Pages"
			'supports'           => apply_filters( 'kleo_clients_cpt_supports', array( 'title', 'thumbnail' ) )
		);

		register_post_type( 'kleo_clients', apply_filters('clients_post_type_reg', $args) );

		$tag_args = array(
			"label"             => _x( 'Client Tags', 'tag label', 'kleo' ),
			"singular_label"    => _x( 'Client Tag', 'tag singular label', 'kleo' ),
			'public'            => true,
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_in_nav_menus' => false,
			'args'              => array( 'orderby' => 'term_order' ),
			'query_var'         => true
		);

		register_taxonomy( 'clients-tag', 'kleo_clients', $tag_args );

	} // End setup_clients_post_type()

}

$kleo_clients = new Clients_Post_Type();
if( class_exists( 'SVQ_FW' ) ) {
	SVQ_FW::set_module( 'clients', $kleo_clients );
}
