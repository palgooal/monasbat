<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class BpMembersGrid extends Widget_Base {

	public function get_name() {
		return 'kleo-members-grid';
	}

	public function get_title() {
		return __( 'Members Grid', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-gallery-grid';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	public function get_types() {
		$kleo_member_types = array( "all" => 'All' );
		if ( function_exists( 'bp_get_member_types' ) ) {
			$kleo_member_types += bp_get_member_types( [], 'names' );
		}

		return $kleo_member_types;
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_register_form',
			[
				'label' => __( 'Settings', 'k-elements' ),
			]
		);


		$this->add_control(
			'member_type',
			[
				'label'       => __( 'Member Type', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $this->get_types(),
				'default'     => 'all',
				'description' => __( "The type of members to display.", "k-elements" )
			]
		);

		$this->add_control(
			'type',
			[
				'label'       => __( 'Filter', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'active'       => 'Active',
					'newest'       => 'Newest',
					'popular'      => 'Popular',
					'online'       => 'Online',
					'alphabetical' => 'Alphabetical',
					'random'       => 'Random'
				],
				'default'     => 'active',
				'description' => __( "How to sort the members.", "k-elements" )
			]
		);

		$this->add_control(
			'number',
			[
				'label'       => __( 'Number of members', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '8',
				'description' => 'How many members you want to display..'
			]
		);


		$this->add_control(
			'perline',
			[
				'label'       => __( 'Members per line', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'one'    => '1',
					'two'    => '2',
					'three'  => '3',
					'four'   => '4',
					'five'   => '5',
					'six'    => '6',
					'seven'  => '7',
					'eight'  => '8',
					'nine'   => '9',
					'ten'    => '10',
					'eleven' => '11',
					'twelve' => '12'

				],
				'default'     => '8',
				'description' => __( "Avatar size to get from BuddyPress", "k-elements" )
			]
		);

		$this->add_control(
			'rounded',
			[
				'label'       => __( 'Avatar type', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'rounded' => 'Rounded',
					'square'  => 'Square'

				],
				'default'     => 'rounded',
				'description' => __( "Rounded or square avatar", "k-elements" )
			]
		);

		$this->add_control(
			'avatarsize',
			[
				'label'       => __( 'Avatar size', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'' => 'Small',
					'large'  => 'Large'

				],
				'default'     => '',
				'description' => __( "Small or large avatar", "k-elements" )
			]
		);

		$this->add_control(
			'width_height',
			[
				'label'       => __( 'Width & Height', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => 'Example: 200x200',
				'condition' => [
					'avatarsize' => 'large',
				],
			]
		);


		$this->add_control(
			'animation',
			[
				'label'       => __( 'Animation', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					''   => 'None',
					'fade'   => 'Fade',
					'appear' => 'Appear'

				],
				'default'     => '',
				'description' => __( "Elements will appear animated one by one.", "k-elements" )
			]
		);


		$this->end_controls_section();

	}

	protected function render() {
		$values = $this->get_settings();

		$settings   = [
			'member_type',
			'type',
			'number',
			'perline',
			'rounded',
			'avatarsize',
			'width_height',
			'animation',
		];
		$attributes = '';
		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}

		echo do_shortcode( '[kleo_bp_members_grid' . $attributes . ']' );
	}

}
