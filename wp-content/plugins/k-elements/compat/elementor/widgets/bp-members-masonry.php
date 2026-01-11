<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class BpMembersMasonry extends Widget_Base {

	public function get_name() {
		return 'kleo-members-masonry';
	}

	public function get_title() {
		return __( 'Members Masonry', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-gallery-masonry';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	private function get_member_types() {
		$kleo_member_types = array( "all" => 'All' );
		if ( function_exists( 'bp_get_member_types' ) ) {
			$kleo_member_types += bp_get_member_types( array(), 'names' );
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
				'label'       => __( "Member Type", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $this->get_member_types(),
				'default'     => 'all',
				'description' => __( "Choose what members types to show." )
			]
		);

		$this->add_control(
			'type',
			[
				'label'       => __( "Filter", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'active'       => 'Active',
					'newest'       => 'Newest',
					'popular'      => 'Popular',
					'alphabetical' => 'Alphabetical',
					'online'       => 'Online',
					'random'       => 'Random'
				],
				'default'     => 'active',
				'description' => __( "The type of members to display." )
			]
		);

		$this->add_control(
			'number',
			[
				'label'       => __( "Number of members", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '12',
				'description' => __( "How many members to get.", 'k-elements' )
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
					''      => 'Small',
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

		/*$this->add_control(
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
		);*/


		$this->end_controls_section();

	}

	protected function render() {
		$values = $this->get_settings();

		$settings   = [
			'member_type',
			'type',
			'number',
			'rounded',
			'avatarsize',
			'width_height',
			/*'online',*/
		];
		$attributes = '';
		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}

		echo '<div class="buddypress">';
		echo '<div id="buddypress" class="buddypress-wrap bp-dir-hori-nav members">';
		echo do_shortcode( '[kleo_bp_members_masonry' . $attributes . ']' );
		echo '</div>';
		echo '</div>';
	}
}
