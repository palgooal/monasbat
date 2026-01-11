<?php
namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class BpMembersCarousel extends Widget_Base {

	public function get_name() {
		return 'kleo-members-carousel';
	}
	public function get_title() {
		return __( 'Members Carousel', 'k-elements' );
	}
	public function get_icon() {
		return 'eicon-carousel';
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
				'label' => __( 'Member Type', 'k-elements' ),
				'type' => Controls_Manager::SELECT,
				'options' => $this->get_types(),
				'default' => 'all',
				'description' => __( "The type of members to display.", "k-elements" )
			]
		);

		$this->add_control(
			'type',
			[
				'label' => __( 'Filter', 'k-elements' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'active'       => 'Active',
					'newest'       => 'Newest',
					'popular'      => 'Popular',
					'online'       => 'Online',
					'alphabetical' => 'Alphabetical',
					'random'       => 'Random'
				],
				'default' => 'active',
				'description' => __( "How to sort the members.", "k-elements" )
			]
		);

		$this->add_control(
			'number',
			[
				'label' => __( 'Number of members', 'k-elements' ),
				'type' => Controls_Manager::TEXT,
				'default' => '12',
				'description' => 'How many members to get.'
			]
		);
		$this->add_control(
			'min_items',
			[
				'label' => __( 'Minimum Items', 'k-elements' ),
				'type' => Controls_Manager::TEXT,
				'default' => '1',
				'description' => 'Minimum number of items to show on the screen'
			]
		);
		$this->add_control(
			'max_items',
			[
				'label' => __( 'Maximum Items', 'k-elements' ),
				'type' => Controls_Manager::TEXT,
				'default' => '6',
				'description' => 'Maximum number of items to show on the screen'
			]
		);

		$this->add_control(
			'image_size',
			[
				'label' => __( 'Image Type', 'k-elements' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'full'       => 'Full',
					'thumb'       => 'Thumb',

				],
				'default' => 'full',
				'description' => __( "Avatar size to get from BuddyPress", "k-elements" )
			]
		);

		$this->add_control(
			'rounded',
			[
				'label' => __( 'Avatar type', 'k-elements' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'rounded' => 'Rounded',
					'square'  => 'Square'

				],
				'default' => 'rounded',
				'description' => __( "Rounded or square avatar", "k-elements" )
			]
		);

		$this->add_control(
			'item_width',
			[
				'label' => __( 'Image Width', 'k-elements' ),
				'type' => Controls_Manager::TEXT,
				'default' => '150',
				'description' => 'The size of the displayed member image"'
			]
		);

		$this->add_control(
			'autoplay',
			[
				'label' => __( 'Auto play', 'k-elements' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'' => 'No',
					'yes'  => 'Yes'

				],
				'default' => '',
				'description' => __( "If the carousel should play automatically", "k-elements" )
			]
		);

		$this->add_control(
			'online',
			[
				'label' => __( 'Online status', 'k-elements' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'show' => 'Show',
					'noshow'  => 'Hide'

				],
				'default' => 'show',
				'description' => __( "Show dot for online status", "k-elements" )
			]
		);

		$this->end_controls_section();

	}
	protected function render() {
		$values = $this->get_settings();

		$settings = [
			'member_type',
			'type',
			'number',
			'min_items',
			'max_items',
			'image_size',
			'rounded',
			'item_width',
			'autoplay',
			'online',
		];
		$attributes = '';
		foreach ( $settings as $setting) {
			$attributes .= ' ' . $setting . '="'. $values[ $setting ] .'"';
		}

		echo do_shortcode( '[kleo_bp_members_carousel' . $attributes . ']' );
	}

}
