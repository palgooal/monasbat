<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class Register extends Widget_Base {

	public function get_name() {
		return 'kleo-register';
	}

	public function get_title() {
		return __( 'Kleo Register', 'k-elements' );
	}

	public function get_icon() {
		return 'fa fa-wpforms';
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
			'register_title',
			[
				'label'       => __( 'Register Title', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'Create Account',
			]
		);

		$this->add_control(
			'style',
			[
				'label'       => __( 'Style', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'default'				=> 'Theme Default',
					'white'				=> 'Transparent White',
					'black'				=> 'Transparent Black',
				],
				'default'     => 'default',
				'description' => __( "Form style. If you don't use this form with a background behind then you should set the form style to 'Theme Default." )
			]
        );
        
        $this->add_control(
			'input_size',
			[
				'label'       => __( 'Input Size', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'normal'				=> 'Normal',
					'large'				    => 'Large',
				],
				'default'     => 'normal',
				'description' => __( 'Form input sizes.' )
			]
        );

        $this->add_control(
			'bp_plugins_hook',
			[
				'label'       => __( 'Enable BuddyPress hooks', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					''				=> 'Yes',
					'no'		    => 'No',
				],
				'default'     => '',
				'description' => __( 'Allow plugins to hook into the form using bp_before_registration_submit_buttons.' )
			]
        );

		$this->end_controls_section();

	}

	protected function render() {
		$values = $this->get_settings();

		$settings   = [
			'register_title',
			'style',
			'input_size',
			'bp_plugins_hook',
		];
		$attributes = '';
		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}

		if ( \Elementor\Plugin::instance()->editor->is_edit_mode() ) {
			$attributes .= ' show_for_users="yes"';
		}

		echo do_shortcode( '[kleo_register' . $attributes . ']' );
	}
}
