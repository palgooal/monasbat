<?php
/**
 * Elementor widget for chat screenshots.
 *
 * @package PalgoalsTestimonials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

class Palgoals_Chat_Screenshots_Widget extends Widget_Base {

	/**
	 * Ensure widget styles are available in Elementor preview.
	 *
	 * @return array
	 */
	public function get_style_depends() {
		Palgoals_Testimonials_Renderer::register_assets();

		$depends = array( Palgoals_Testimonials_Renderer::STYLE_HANDLE );

		if ( $this->is_elementor_preview_context() ) {
			$swiper_style = Palgoals_Testimonials_Renderer::get_swiper_style_handle();

			if ( $swiper_style ) {
				$depends[] = $swiper_style;
			}
		}

		return $depends;
	}

	/**
	 * Scripts are loaded conditionally during render.
	 *
	 * @return array
	 */
	public function get_script_depends() {
		if ( ! $this->is_elementor_preview_context() ) {
			return array();
		}

		Palgoals_Testimonials_Renderer::register_assets();

		$depends       = array();
		$swiper_script = Palgoals_Testimonials_Renderer::get_swiper_script_handle();

		if ( $swiper_script ) {
			$depends[] = $swiper_script;
		}

		$depends[] = Palgoals_Testimonials_Renderer::SCRIPT_HANDLE;

		return $depends;
	}

	/**
	 * Determine whether the widget is loading inside Elementor preview/edit mode.
	 *
	 * @return bool
	 */
	protected function is_elementor_preview_context() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$plugin = \Elementor\Plugin::instance();

		if ( isset( $plugin->editor ) && method_exists( $plugin->editor, 'is_edit_mode' ) && $plugin->editor->is_edit_mode() ) {
			return true;
		}

		if ( isset( $plugin->preview ) && method_exists( $plugin->preview, 'is_preview_mode' ) && $plugin->preview->is_preview_mode() ) {
			return true;
		}

		return false;
	}

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'palgoals-chat-screenshots';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Palgoals Chat Screenshots', 'palgoals-testimonials' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-gallery-grid';
	}

	/**
	 * Widget category.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'palgoals-elements' );
	}

	/**
	 * Widget keywords.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'chat', 'whatsapp', 'screenshot', 'proof', 'palgoals' );
	}

	/**
	 * Register widget controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Content', 'palgoals-testimonials' ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'palgoals-testimonials' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'masonry',
				'options' => array(
					'grid'     => __( 'Grid', 'palgoals-testimonials' ),
					'masonry'  => __( 'Masonry', 'palgoals-testimonials' ),
					'slider'   => __( 'Slider', 'palgoals-testimonials' ),
					'carousel' => __( 'Carousel', 'palgoals-testimonials' ),
				),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Number of Screenshots', 'palgoals-testimonials' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 6,
				'min'     => 1,
				'max'     => 24,
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => __( 'Order', 'palgoals-testimonials' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'DESC',
				'options' => array(
					'DESC' => __( 'DESC', 'palgoals-testimonials' ),
					'ASC'  => __( 'ASC', 'palgoals-testimonials' ),
				),
			)
		);

		$this->add_control(
			'show_title',
			array(
				'label'        => __( 'Show Title', 'palgoals-testimonials' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'palgoals-testimonials' ),
				'label_off'    => __( 'Hide', 'palgoals-testimonials' ),
				'default'      => '',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'show_caption',
			array(
				'label'        => __( 'Show Note', 'palgoals-testimonials' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'palgoals-testimonials' ),
				'label_off'    => __( 'Hide', 'palgoals-testimonials' ),
				'default'      => '',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'open_full_image',
			array(
				'label'        => __( 'Open Full Image', 'palgoals-testimonials' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'palgoals-testimonials' ),
				'label_off'    => __( 'No', 'palgoals-testimonials' ),
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'autoplay_speed',
			array(
				'label'       => __( 'Auto Slider Speed (ms)', 'palgoals-testimonials' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'step'        => 100,
				'condition'   => array(
					'layout' => array( 'slider', 'carousel' ),
				),
				'description' => __( 'Set to 0 to disable autoplay.', 'palgoals-testimonials' ),
			)
		);

		$this->add_responsive_control(
			'columns',
			array(
				'label'          => __( 'Responsive Columns', 'palgoals-testimonials' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => '3',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'options'        => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'condition'      => array(
					'layout!' => 'slider',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_card',
			array(
				'label' => __( 'Card', 'palgoals-testimonials' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'card_background',
			array(
				'label'     => __( 'Card Background', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-chat-shot-card' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'card_radius',
			array(
				'label'      => __( 'Border Radius', 'palgoals-testimonials' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .palgoals-chat-shot-card'        => 'border-radius: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .palgoals-chat-shot-card__media' => 'border-radius: calc({{SIZE}}{{UNIT}} - 8px);',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow',
				'selector' => '{{WRAPPER}} .palgoals-chat-shot-card',
			)
		);

		$this->add_responsive_control(
			'card_spacing',
			array(
				'label'      => __( 'Gap', 'palgoals-testimonials' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 80,
					),
				),
				'description' => __( 'Controls the space between screenshots.', 'palgoals-testimonials' ),
				'selectors'  => array(
					'{{WRAPPER}} .palgoals-chat-shots' => '--palgoals-card-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_image',
			array(
				'label' => __( 'Image', 'palgoals-testimonials' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'image_border',
				'selector' => '{{WRAPPER}} .palgoals-chat-shot-card__media',
			)
		);

		$this->add_control(
			'image_fit',
			array(
				'label'     => __( 'Image Display', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'contain',
				'options'   => array(
					'contain' => __( 'Contain', 'palgoals-testimonials' ),
					'cover'   => __( 'Cover', 'palgoals-testimonials' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .palgoals-chat-shot-card__image' => 'object-fit: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'image_position',
			array(
				'label'     => __( 'Image Position', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'top center',
				'options'   => array(
					'top center'    => __( 'Top Center', 'palgoals-testimonials' ),
					'center center' => __( 'Center', 'palgoals-testimonials' ),
					'bottom center' => __( 'Bottom Center', 'palgoals-testimonials' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .palgoals-chat-shot-card__image' => 'object-position: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'image_height',
			array(
				'label'      => __( 'Image Height', 'palgoals-testimonials' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'vh' ),
				'range'      => array(
					'px' => array(
						'min' => 160,
						'max' => 1200,
					),
					'vh' => array(
						'min' => 20,
						'max' => 100,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .palgoals-chat-shot-card__media' => 'height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .palgoals-chat-shot-card__image' => 'height: 100%;',
				),
			)
		);

		$this->add_responsive_control(
			'image_width',
			array(
				'label'      => __( 'Image Width', 'palgoals-testimonials' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( '%', 'px', 'vw' ),
				'range'      => array(
					'%' => array(
						'min' => 20,
						'max' => 100,
					),
					'px' => array(
						'min' => 120,
						'max' => 1200,
					),
					'vw' => array(
						'min' => 10,
						'max' => 100,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .palgoals-chat-shots:not(.palgoals-chat-shots--masonry) .palgoals-chat-shot-card__media' => 'width: {{SIZE}}{{UNIT}}; max-width: 100%; margin-inline: auto;',
					'{{WRAPPER}} .palgoals-chat-shots--masonry .palgoals-chat-shot-card' => 'width: {{SIZE}}{{UNIT}}; max-width: 100%; margin-inline: auto;',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_typography',
			array(
				'label' => __( 'Typography', 'palgoals-testimonials' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'label'    => __( 'Title', 'palgoals-testimonials' ),
				'selector' => '{{WRAPPER}} .palgoals-chat-shot-card__title',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'caption_typography',
				'label'    => __( 'Note', 'palgoals-testimonials' ),
				'selector' => '{{WRAPPER}} .palgoals-chat-shot-card__text',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		echo Palgoals_Testimonials_Screenshots_Renderer::render(
			array(
				'layout'          => isset( $settings['layout'] ) ? $settings['layout'] : 'masonry',
				'limit'           => isset( $settings['limit'] ) ? $settings['limit'] : 6,
				'order'           => isset( $settings['order'] ) ? $settings['order'] : 'DESC',
				'columns'         => isset( $settings['columns'] ) ? $settings['columns'] : 3,
				'columns_tablet'  => isset( $settings['columns_tablet'] ) ? $settings['columns_tablet'] : 2,
				'columns_mobile'  => isset( $settings['columns_mobile'] ) ? $settings['columns_mobile'] : 1,
				'show_title'      => isset( $settings['show_title'] ) && 'yes' === $settings['show_title'],
				'show_caption'    => isset( $settings['show_caption'] ) && 'yes' === $settings['show_caption'],
				'open_full_image' => isset( $settings['open_full_image'] ) && 'yes' === $settings['open_full_image'],
				'autoplay_speed'  => isset( $settings['autoplay_speed'] ) ? $settings['autoplay_speed'] : 0,
			)
		);
	}
}
