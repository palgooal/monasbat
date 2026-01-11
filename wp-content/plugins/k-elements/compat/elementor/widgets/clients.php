<?php
namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Clients extends Widget_Base {

	public function get_name() {
		return 'kleo-clients';
	}
	public function get_title() {
		return __( 'Clients', 'k-elements' );
	}
	public function get_icon() {
		return 'eicon-person';
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
			'number',
			[
				'label' => __( 'Number of logos', 'k-elements' ),
				'type' => Controls_Manager::NUMBER,
				'default'     => '5',
			]
		);

		$this->add_control(
			'target',
			[
				'label' => __( 'Open links in new window', 'k-elements' ),
				'type' => Controls_Manager::SWITCHER,
				'label_off'    => esc_html__( 'No', 'k-elements' ),
				'label_on'     => esc_html__( 'Yes', 'k-elements' ),
				'default'      => '',
				'return_value' => '_blank',
			]
		);

		$client_tags = array();
		$defined_tags = get_terms( 'clients-tag' );
		if ( is_array( $defined_tags ) && ! empty( $defined_tags ) ) {
			foreach ( $defined_tags as $tag ) {
				$client_tags[ $tag->term_id ] = $tag->name;
			}
		}

		$this->add_control(
			'tags',
			[
				'label' => __( 'Filter by Tags', 'k-elements' ),
				'type' => Controls_Manager::SELECT2,
				'options' => $client_tags,
				'multiple' => true,
			]
		);


		$this->end_controls_section();

	}

	protected function render() {

		$values = $this->get_settings();
		$values['tags'] = implode( ',', $values['tags'] );

		$settings   = [
			'number',
			'target',
			'tags',
		];

		$attributes = '';
		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}

		echo do_shortcode( '[kleo_clients' . $attributes . ']' );
		
	}

}
