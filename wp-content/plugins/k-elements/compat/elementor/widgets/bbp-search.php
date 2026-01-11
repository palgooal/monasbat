<?php

namespace K_Elements\Compat\Elementor\Widgets;


use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class BbPressSearch extends Widget_Base {

	public function get_name() {
		return 'kleo-bbp-search';
	}

	public function get_title() {
		return __( 'bbPress Search', 'k-elements' );
	}

	public function get_icon() {
		return 'fa fa-search';
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
			'forum_id',
			[
				'label'       => __( 'Forum ID', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( "Enter a Forum ID to search just in that forum. Leave blank to search in all forums.", 'k-elements' )
			]
        );
        
        $this->add_control(
			'placeholder',
			[
				'label'       => __( 'Placeholder', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( "Placeholder to show when the input is empty.", 'k-elements' )
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'       => __( 'Button text color', 'k-elements' ),
				'type'        => Controls_Manager::COLOR,
				'default'     => '#f2f2f2'
			]
        );
        
        $this->add_control(
			'bg_color',
			[
				'label'       => __( 'Button background color', 'k-elements' ),
				'type'        => Controls_Manager::COLOR,
				'default'     => '#e74c3c'
			]
		);

		$this->end_controls_section();

	}

	protected function render() {
		$values = $this->get_settings();

		$settings   = [
			'forum_id',
			'placeholder',
            'text_color',
            'bg_color'
		];
		$attributes = '';
		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}

		echo do_shortcode( '[kleo_bbp_header_search' . $attributes . ']' );
	}
}
