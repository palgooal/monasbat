<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use K_Elements\Compat\Elementor\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class NewsFocus extends Widget_Base {

	public function get_name() {
		return 'kleo-news-focus';
	}

	public function get_title() {
		return __( 'News Focus', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-archive-posts';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_news_focus',
			[
				'label' => __( 'Settings', 'k-elements' ),
			]
		);

		Config::generate_query_controls( $this );

		$this->add_control(
			'featured',
			[
				'label'   => __( 'Featured posts on left side', 'k-elements' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 1,
			]
		);
		$this->add_control(
			'new_tab',
			[
				'label'   => __( "Open in a new tab", 'k-elements' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => false,
			]
		);

		$this->end_controls_section();

	}

	protected function render() {

		$values     = $this->get_settings();
		$attributes = Config::generate_query_string( $values );

		$attributes .= ! empty( $values['new_tab'] ) ? ' new_tab=' . $values['new_tab'] : '';
		$attributes .= ' featured="' . $values['featured'] . '"';

		echo do_shortcode( '[kleo_news_focus' . $attributes . ']' );
	}
}
