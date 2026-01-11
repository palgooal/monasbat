<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class BpActivityStream extends Widget_Base {

	public function get_name() {
		return 'kleo-activity-stream';
	}

	public function get_title() {
		return __( 'Activity Stream', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-post-list';
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
				'label'       => __( 'Display', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'all'     => 'All',
					'blogs'   => 'Blogs',
					'groups'  => 'Groups',
					'friends' => 'Friends',
					'profile' => 'Profile',
					'status'  => 'Status'
				],
				'default'     => 'all',
				'description' => __( "The type of activity to show. It adds the 'object' parameter as in https://codex.buddypress.org/developer/loops-reference/the-activity-stream-loop/" )
			]
		);

		$this->add_control(
			'filter_action',
			[
				'label'       => __( 'Filter actions', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Example: activity_update. See action parameter from the filters section from https://codex.buddypress.org/developer/loops-reference/the-activity-stream-loop/', 'k-elements' )
			]
		);

		$this->add_control(
			'number',
			[
				'label'       => __( "Number", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '6',
				'description' => __( "How many activity streams to show", 'k-elements' )
			]
		);

		$this->add_control(
			'post_form',
			[
				'label'       => __( "Show post update form", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'no'	  => 'No',
					'yes'     => 'Yes'

				],
				'default'     => 'no',
				'description' => __( "Shows the form to post a new update", 'k-elements' )
			]
        );

		$this->end_controls_section();

	}

	protected function render() {
		$values = $this->get_settings();
		$values['show_button'] = false;

		$settings   = [
			'show',
			'filter_action',
			'number',
			'post_form',
			'show_button'
		];
		$attributes = '';
		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}
		echo '<div id="buddypress">';
		echo do_shortcode( '[kleo_bp_activity_stream' . $attributes . ']' );
		echo '<div>';
	}
}
