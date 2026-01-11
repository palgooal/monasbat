<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class BpGroupsGrid extends Widget_Base {

	public function get_name() {
		return 'kleo-groups-grid';
	}

	public function get_title() {
		return __( 'Groups Grid', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-gallery-grid';
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
			'type',
			[
				'label'       => __( "Type", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'active'				=> 'Active',
					'newest'				=> 'Newest',
					'popular'				=> 'Popular',
					'alphabetical'			=> 'Alphabetical',
					'most-forum-topics' 	=> 'Most Forum Topics',
					'most-forum-posts'		=> 'Most Forum Posts',
					'random'				=> 'Random'
				],
				'default'     => 'active',
				'description' => __( "The type of groups to display." )
			]
		);

		$this->add_control(
			'number',
			[
				'label'       => __( "Maximum members", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '8',
				'description' => __( "How many groups you want to display.", 'k-elements' )
			]
		);


		$this->add_control(
			'perline',
			[
				'label'       => __( "Groups per line", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'one'		=>	'1',
					'two'		=>	'2',
					'three'		=>	'3',
					'four'		=>	'4',
					'five'		=>	'5',
					'six'		=>	'6',
					'seven'		=>	'7',
					'eight'		=>	'8',
					'nine'		=>	'9',
					'ten'		=>	'10',
					'eleven'	=>	'11',
					'twelve'	=>	'12'
				],
				'default'     => '8',
				'description' => __( "How many groups to show per line", 'k-elements' )
			]
		);

		$this->add_control(
			'rounded',
			[
				'label'       => __( "Avatar type", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'rounded' => 'Rounded',
					'square'  => 'Square'

				],
				'default'     => 'rounded',
				'description' => __( "Rounded or square avatar", 'k-elements' )
			]
		);

		$this->add_control(
			'avatarsize',
			[
				'label'       => __( "Avatar size", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					''		=> 'Small',
					'large' => 'Large'

				],
				'default'     => '',
				'description' => __( "Small or large avatar", 'k-elements' )
			]
		);

		$this->add_control(
			'width_height',
			[
				'label'       => __( "Width & Height", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( "Example: 200x200", 'k-elements' ),
				'condition'   => [
					'avatarsize' => 'large',
				],
			]
		);


		$this->end_controls_section();

	}

	protected function render() {
		$values = $this->get_settings();

		$settings   = [
			'type',
			'number',
			'perline',
			'rounded',
			'avatarsize',
			'width_height',
		];
		$attributes = '';
		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}

		echo do_shortcode( '[kleo_bp_groups_grid' . $attributes . ']' );
	}
}
