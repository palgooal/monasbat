<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class BpGroupsMasonry extends Widget_Base {

	public function get_name() {
		return 'kleo-groups-masonry';
	}

	public function get_title() {
		return __( 'Groups Masonry', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-gallery-masonry';
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
				'label'       => __( "Number of groups", 'k-elements' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => '12',
				'description' => __( "How many groups to get.", 'k-elements' )
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
				'description' => __( 'Rounded or square avatar', 'k-elements' )
			]
		);

		$this->add_control(
			'avatarsize',
			[
				'label'       => __( 'Avatar size', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					''		=> 'Small',
					'large' => 'Large'

				],
				'default'     => '',
				'description' => __( 'Small or large avatar', 'k-elements' )
			]
		);

		$this->add_control(
			'width_height',
			[
				'label'       => __( 'Width & Height', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Example: 200x200', 'k-elements' ),
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
			'rounded',
			'avatarsize',
			'width_height',
		];
		$attributes = '';
		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}
		echo '<div class="buddypress">';
		echo '<div id="buddypress" class="buddypress-wrap bp-dir-hori-nav groups">';
		echo do_shortcode( '[kleo_bp_groups_masonry' . $attributes . ']' );
		echo '</div>';
		echo '</div>';
	}
}
