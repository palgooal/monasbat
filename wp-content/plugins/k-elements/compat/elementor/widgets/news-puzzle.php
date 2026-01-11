<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use K_Elements\Compat\Elementor\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class NewsPuzzle extends Widget_Base {

	public function get_name() {
		return 'kleo-news-puzzle';
	}

	public function get_title() {
		return __( 'News Puzzle', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-posts-grid';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_news_puzzle',
			[
				'label' => __( 'Settings', 'k-elements' ),
			]
		);

		$this->add_control(
			'per_row',
			[
				'label'       => __( 'Posts per Row', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'2' => '2',
					'3' => '3',
					'4' => '4',
				],
				'default'     => 3,
				'description' => 'How many posts to show on one line'
			]
		);

		$this->add_control(
			'new_tab',
			[
				'label'   => __( 'Open links in a new tab', 'k-elements' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => false,
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_news_puzzle_query',
			[
				'label' => __( 'Query', 'k-elements' ),
			]
		);

		Config::generate_query_controls( $this );


		$this->end_controls_section();

	}

	protected function render() {

		$values     = $this->get_settings();
		$attributes = Config::generate_query_string( $values );

		$attributes .= ! empty( $values['new_tab'] ) ? ' new_tab=' . $values['new_tab'] : '';
		$attributes .= ' per_row="' . $values['per_row'] . '"';

		echo do_shortcode( '[kleo_news_puzzle' . $attributes . ']' );
	}
}
