<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class Login extends Widget_Base {

	public function get_name() {
		return 'kleo-login';
	}

	public function get_title() {
		return __( 'Kleo Login', 'k-elements' );
	}

	public function get_icon() {
		return 'fa fa-arrow-circle-right';
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
			'show',
			[
				'label'       => __( "Show", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'login'		=> 'Login',
					'lostpass'	=> 'Lost Password',
				],
				'default'     => 'login',
				'description' => __( "Initial form to show." )
			]
        );

        $this->add_control(
			'login_title',
			[
				'label'       => __( "Login Title", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'Log in with your credentials',
				'description' => __( "Enter the login title.", 'k-elements' )
			]
		);

		$this->add_control(
			'lostpass_title',
			[
				'label'       => __( "Lost Password Title", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'Forgot your details?',
				'description' => __( "Enter the lost pass title.", 'k-elements' )
			]
		);

		$this->add_control(
			'login_link',
			[
				'label'       => __( "Login Link", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '#',
				'description' => __( "Use # or custom url. Using # will allow inline switching between login and lost password boxes.", 'k-elements' )
			]
		);

		$this->add_control(
			'lostpass_link',
			[
				'label'       => __( "Lost Password Link", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '#',
				'description' => __( "Use # or custom url. Using # will allow inline switching between login and lost password boxes.", 'k-elements' )
			]
		);

		$this->add_control(
			'register_link',
			[
				'label'       => __( "Register Link", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( "Leave empty for WordPress or BuddyPress default url. Use 'hide' or custom url. Using 'hide will allow you to hide any registration information.", 'k-elements' )
			]
		);

		$this->add_control(
			'style',
			[
				'label'       => __( "Style", 'k-elements' ),
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
				'label'       => __( "Input Size", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'normal'				=> 'Normal',
					'large'				    => 'Large',
				],
				'default'     => 'normal',
				'description' => __( "Form input sizes." )
			]
		);
        
        $this->add_control(
			'autofocus_login_field',
			[
				'label'       => __( "Autofocus to the input field", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					''				=> 'Yes',
					'no'		    => 'No',
				],
				'default'     => '',
				'description' => __( "Will focus to the input to type directly and if the form isn't on page view will scroll direcly where the form it's placed." )
			]
        );


		$this->end_controls_section();

	}

	protected function render() {
		$values = $this->get_settings();

		$settings   = [
			'show',
			'login_title',
			'lostpass_title',
			'login_link',
			'lostpass_link',
			'register_link',
			'style',
			'input_size',
			'autofocus_login_field'
		];
		$attributes = '';


		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}

		if ( \Elementor\Plugin::instance()->editor->is_edit_mode() ) {
			$attributes .= ' show_for_users="yes"';
		}

		echo do_shortcode( '[kleo_login' . $attributes . ']' );
	}
}
