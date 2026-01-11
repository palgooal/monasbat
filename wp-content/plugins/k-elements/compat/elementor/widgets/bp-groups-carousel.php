<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class BpGroupsCarousel extends Widget_Base {

	public function get_name() {
		return 'kleo-groups-carousel';
	}

	public function get_title() {
		return __( 'Groups Carousel', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-carousel';
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
				'label'       => __( "Type", "k-elements" ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'active'            => 'Active',
					'newest'            => 'Newest',
					'popular'           => 'Popular',
					'alphabetical'      => 'Alphabetical',
					'most-forum-topics' => 'Most Forum Topics',
					'most-forum-posts'  => 'Most Forum Posts',
					'random'            => 'Random'
				],
				'default'     => 'active',
				'description' => __( "The type of groups to display.", "k-elements" )
			]
		);

		$this->add_control(
			'number',
			[
				'label'       => __( "Number of groups", "k-elements" ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '12',
				'description' => __( "How many groups to get.", "k-elements" )
			]
		);
		$this->add_control(
			'min_items',
			[
				'label'       => __( "Minimum Items", "k-elements" ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '1',
				'description' => __( "Minimum number of items to show on the screen", "k-elements" )
			]
		);
		$this->add_control(
			'max_items',
			[
				'label'       => __( "Maximum Items", "k-elements" ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '6',
				'description' => __( "Maximum number of items to show on the screen", "k-elements" )
			]
		);

		$this->add_control(
			'image_size',
			[
				'label'       => __( "Image Type", "k-elements" ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'full'  => 'Full',
					'thumb' => 'Thumbnail'
				],
				'default'     => 'full',
				'description' => __( "The size to get from buddypress", "k-elements" )
			]
		);

		$this->add_control(
			'autoplay',
			[
				'label'       => __( "Auto play", "k-elements" ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					''    => 'No',
					'yes' => 'Yes'

				],
				'default'     => '',
				'description' => __( "If the carousel should play automatically", "k-elements" )
			]
		);

		$this->add_control(
			'rounded',
			[
				'label'       => __( "Avatar type", "k-elements" ),
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
			'item_width',
			[
				'label'       => __( "Image Width", "k-elements" ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '150',
				'description' => __( "The size of the group image", "k-elements" )
			]
		);

		$this->end_controls_section();

	}

	protected function render() {
		$values = $this->get_settings();

		$settings   = [
			'type',
			'number',
			'min_items',
			'max_items',
			'image_size',
			'autoplay',
			'rounded',
			'item_width'
		];
		$attributes = '';
		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}

		echo do_shortcode( '[kleo_bp_groups_carousel' . $attributes . ']' );
	}

}
