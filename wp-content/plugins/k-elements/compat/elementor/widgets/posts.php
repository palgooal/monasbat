<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use K_Elements\Compat\Elementor\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class Posts extends Widget_Base {

	public function get_name() {
		return 'kleo-posts';
	}

	public function get_title() {
		return esc_html__( 'Posts (Kleo)', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-posts-group';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	protected function register_controls() {

		global $kleo_config;

		$this->start_controls_section(
			'section_groups_settings',
			[
				'label' => esc_html__( 'Settings', 'k-elements' ),
			]
		);

		$this->add_control(
			'post_layout',
			[
				'label'   => esc_html__( 'Listing type', 'k-elements' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'grid',
				'options' => [
					'grid'     => esc_html__( 'Grid', 'k-elements' ),
					'small'    => esc_html__( 'Small', 'k-elements' ),
					'standard' => esc_html__( 'Standard', 'k-elements' ),
				]
			]
		);

		$this->add_control(
			'columns',
			[
				'label'     => esc_html__( 'Items per row', 'k-elements' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '4',
				'options'   => [
					2 => '2',
					3 => '3',
					4 => '4',
					5 => '5',
					6 => '6'
				],
				'condition' => [
					'post_layout' => 'grid'
				],
			]
		);

		if ( isset( $kleo_config['blog_layouts'] ) ) {
			$this->add_control(
				'show_switcher',
				[
					'label'        => esc_html__( 'Show Layout Switcher', 'k-elements' ),
					'type'         => Controls_Manager::SWITCHER,
					'label_on'     => esc_html__( 'Yes', 'k-elements' ),
					'label_off'    => esc_html__( 'No', 'k-elements' ),
					'return_value' => '1',
					'default'      => '0',
					'description'  => esc_html__( 'This allows the visitor to change posts layout.', 'k-elements' ),
				]
			);

			$this->add_control(
				'switcher_layouts',
				[
					'label'     => esc_html__( 'Switcher Layouts', 'k-elements' ),
					'type'      => Controls_Manager::SELECT2,
					'default'   => array_keys( $kleo_config['blog_layouts'] ),
					'options'   => $kleo_config['blog_layouts'],
					'multiple'  => true,
					'condition' => [
						'show_switcher' => '1'
					],
				]
			);
		}

		$this->add_control(
			'show_thumb',
			[
				'label'     => esc_html__( 'Show Thumbnail image', 'k-elements' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'yes',
				'options'   => [
					'yes'    => esc_html__( 'Yes', 'k-elements' ),
					'just_1' => esc_html__( 'Just first post', 'k-elements' ),
					'just_2' => esc_html__( 'Just first two posts', 'k-elements' ),
					'just_3' => esc_html__( 'Just first three posts', 'k-elements' ),
					'no'     => esc_html__( 'No', 'k-elements' ),
				],
				'condition' => [
					'post_layout' => 'standard'
				],
			]
		);

		$this->add_control(
			'show_meta',
			[
				'label'        => esc_html__( 'Show Post Meta', 'k-elements' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'k-elements' ),
				'label_off'    => esc_html__( 'No', 'k-elements' ),
				'return_value' => '1',
				'default'      => '1',
			]
		);

		$this->add_control(
			'inline_meta',
			[
				'label'        => esc_html__( 'Inline Post Meta', 'k-elements' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'k-elements' ),
				'label_off'    => esc_html__( 'No', 'k-elements' ),
				'return_value' => '1',
				'default'      => '0',
				'description'  => esc_html__( 'Applies to Standard Layout only. Shows the post meta elements in one line if enabled', 'k-elements' ),
				'condition'    => [
					'show_meta' => '1'
				],
			]
		);

		$this->add_control(
			'show_footer',
			[
				'label'        => esc_html__( 'Show Post Footer', 'k-elements' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'k-elements' ),
				'label_off'    => esc_html__( 'No', 'k-elements' ),
				'return_value' => '1',
				'default'      => '1',
				'description'  => esc_html__( 'Show read more button and post likes', 'k-elements' ),
			]
		);

		$this->add_control(
			'load_more',
			[
				'label'        => esc_html__( 'Enable Load More', 'k-elements' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'k-elements' ),
				'label_off'    => esc_html__( 'No', 'k-elements' ),
				'return_value' => '1',
				'default'      => '0',
				'description'  => esc_html__( 'Enable Load more posts via AJAX', 'k-elements' ),
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_posts_query',
			[
				'label' => __( 'Query', 'k-elements' ),
			]
		);

		Config::generate_query_controls( $this );

		$this->end_controls_section();

	}

	protected function render() {

		$values = $this->get_settings_for_display();

		if ( ! isset( $values['switcher_layouts'] ) ) {
			$values['switcher_layouts'] = [];
		}

		$attributes = Config::generate_query_string( $values, 'loop' );
		$attributes .= ' post_layout="' . $values['post_layout'] . '"';
		$attributes .= ' columns="' . $values['columns'] . '"';
		$attributes .= ' show_switcher="' . $values['show_switcher'] . '"';
		$attributes .= ' show_thumb="' . $values['show_thumb'] . '"';
		$attributes .= ' switcher_layouts="' . implode( ',', $values['switcher_layouts'] ) . '"';
		$attributes .= ' show_meta="' . $values['show_meta'] . '"';
		$attributes .= ' inline_meta="' . $values['inline_meta'] . '"';
		$attributes .= ' show_footer="' . $values['show_footer'] . '"';
		$attributes .= ' load_more="' . $values['load_more'] . '"';

		echo do_shortcode( '[vc_posts_grid' . $attributes . ']' );

	}

}
