<?php
namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Divider extends Widget_Base {

	public function get_name() {
		return 'kleo-divider';
	}
	public function get_title() {
		return __( 'Divider', 'k-elements' );
	}
	public function get_icon() {
		return 'eicon-divider';
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
				'label' => __( 'Type', 'k-elements' ),
				'type' => Controls_Manager::SELECT2,
				'options' => [
					'full'   => 'Full',
					'long'   => 'Long',
					'short'  => 'Short',
					'double' => 'Double'
				],
				'default' => 'full',
				'description' => __( "The type of the divider.", "k-elements" )
			]
		);

		$this->add_control(
			'double',
			[
				'label' => __( 'Double border', 'k-elements' ),
				'type' => Controls_Manager::SELECT2,
				'options' => [
					''   => 'No',
					'yes'   => 'Yes',
				],
				'default' => '',
				'description' => __( "Have the divider double lined.", "k-elements" )
			]
		);
		$this->add_control(
			'position',
			[
				'label' => __( 'Position', 'k-elements' ),
				'type' => Controls_Manager::SELECT2,
				'options' => [
					'center' => 'Center',
					'left'   => 'Left',
					'right'  => 'Right'
				],
				'default' => 'center',
			]
		);

		$this->add_control(
			'icon',
			[
				'label'   => __( 'Icon', 'k-elements' ),
				'type'    => Controls_Manager::ICON,
			]
		);

		$this->add_control(
			'icon_size',
			[
				'label' => __( 'Icon size', 'k-elements' ),
				'type' => Controls_Manager::SELECT2,
				'options' => [
					'' => 'Normal',
					'large'  => 'Large'
				],
				'default' => '',
			]
		);

		$this->add_control(
			'text',
			[
				'label' => __( 'Text', 'k-elements' ),
				'type' => Controls_Manager::TEXT,
				'default' => '',
				'description' => 'This text wil show inside the divider'
			]
		);

		$this->end_controls_section();

	}
	protected function render() {
		$values = $this->get_settings();

		$settings = [
			'type',
			'double',
			'position',
			'icon',
			'icon_size',
			'text',
		];
		$attributes = '';
		foreach ( $settings as $setting) {
			$attributes .= ' ' . $setting . '="'. $values[ $setting ] .'"';
		}

		echo do_shortcode( '[kleo_divider' . $attributes . ']' );
	}

}
