<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class Search extends Widget_Base {

	public function get_name() {
		return 'kleo-search';
	}

	public function get_title() {
		return __( 'Search Form', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-site-search';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_register_form',
			[
				'label' => __( 'Settings', 'k-elements' ),
			]
		);


		$this->add_control(
			'form_style',
			[
				'label'       => __( 'Form Style', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'default'     => 'Default',
					'transparent' => 'Transparent'
				],
				'default'     => 'default',
				'description' => __( "This affects the look of the form. Default has a border and works for white backgrounds." )
			]
		);

		$this->add_control(
			'type',
			[
				'label'       => __( 'Form Type', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'both'        => 'Form submit + AJAX results',
					'form_submit' => 'Just Form Submit',
					'ajax'        => 'Just AJAX results'
				],
				'default'     => 'both',
				'description' => __( 'Here you can disable Form Submit or AJAX results.' )
			]
		);

		$kleo_post_types = array();

		if ( function_exists( 'bp_is_active' ) ) {
			$kleo_post_types['members'] = 'Members';
			$kleo_post_types['groups']  = 'Groups';
		}
		$kleo_post_types['post'] = 'Posts';
		$kleo_post_types['page'] = 'Pages';

		$args = array(
			'public'   => true,
			'_builtin' => false
		);

		$types_return = 'objects'; // names or objects, note names is the default
		$post_types   = get_post_types( $args, $types_return );

		$except_post_types = array( 'kleo_clients', 'kleo-testimonials', 'topic', 'reply' );

		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, $except_post_types ) ) {
				continue;
			}
			$kleo_post_types[ $post_type->name ] = $post_type->labels->name;
		}

		$this->add_control(
			'context',
			[
				'label'       => __( 'Search context', 'k-elements' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => $kleo_post_types,
				'multiple'    => true,
				'description' => __( "Leave unchecked to search in all content. What content do you want to search for. For example if you select just Members then the form submit will go to members directory. Same applies for Forums and Products.", 'k-elements' )
			]
		);

		$this->add_control(
			'placeholder',
			[
				'label'       => __( "Placeholder", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( "Placeholder to show when the input is empty.", 'k-elements' )
			]
		);


		$this->end_controls_section();

	}

	protected function render() {
		$values = $this->get_settings();

		$settings   = [
			'form_style',
			'type',
			'context',
			'placeholder'
		];
		$attributes = '';
		foreach ( $settings as $setting ) {
			if ( is_array( $values[ $setting ] ) ) {
				$val = implode( ',', $values[ $setting ] );
			} else {
				$val = $values[ $setting ];
			}
			$attributes .= ' ' . $setting . '="' . $val . '"';
		}

		echo do_shortcode( '[kleo_search_form' . $attributes . ']' );
	}
}
