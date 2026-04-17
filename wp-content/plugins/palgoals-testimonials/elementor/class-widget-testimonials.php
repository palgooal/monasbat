<?php
/**
 * Elementor widget for testimonials.
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

class Palgoals_Testimonials_Widget extends Widget_Base {

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
		return 'palgoals-testimonials';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Palgoals Testimonials', 'palgoals-testimonials' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-testimonial-carousel';
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
		return array( 'testimonial', 'review', 'customer', 'palgoals' );
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
				'default' => 'grid',
				'options' => array(
					'grid'     => __( 'Grid', 'palgoals-testimonials' ),
					'slider'   => __( 'Slider', 'palgoals-testimonials' ),
					'masonry'  => __( 'Masonry', 'palgoals-testimonials' ),
					'carousel' => __( 'Carousel', 'palgoals-testimonials' ),
				),
			)
		);

		$this->add_control(
			'skin',
			array(
				'label'   => __( 'Design', 'palgoals-testimonials' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'default',
				'options' => array(
					'default'   => __( 'Default', 'palgoals-testimonials' ),
					'editorial' => __( 'Editorial Split', 'palgoals-testimonials' ),
				),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Number of Testimonials', 'palgoals-testimonials' ),
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
			'rating',
			array(
				'label'   => __( 'Minimum Rating', 'palgoals-testimonials' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '0',
				'options' => array(
					'0' => __( 'All Ratings', 'palgoals-testimonials' ),
					'5' => __( '5 Stars', 'palgoals-testimonials' ),
					'4' => __( '4 Stars & Up', 'palgoals-testimonials' ),
					'3' => __( '3 Stars & Up', 'palgoals-testimonials' ),
					'2' => __( '2 Stars & Up', 'palgoals-testimonials' ),
					'1' => __( '1 Star & Up', 'palgoals-testimonials' ),
				),
			)
		);

		$this->add_control(
			'show_photo',
			array(
				'label'        => __( 'Show Photo', 'palgoals-testimonials' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'palgoals-testimonials' ),
				'label_off'    => __( 'Hide', 'palgoals-testimonials' ),
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'show_company',
			array(
				'label'        => __( 'Show Company Name', 'palgoals-testimonials' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'palgoals-testimonials' ),
				'label_off'    => __( 'Hide', 'palgoals-testimonials' ),
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'show_rating',
			array(
				'label'        => __( 'Show Rating', 'palgoals-testimonials' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'palgoals-testimonials' ),
				'label_off'    => __( 'Hide', 'palgoals-testimonials' ),
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'autoplay_speed',
			array(
				'label'       => __( 'Auto Slider Speed (ms)', 'palgoals-testimonials' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 5000,
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
			'section_intro',
			array(
				'label'     => __( 'Intro', 'palgoals-testimonials' ),
				'condition' => array(
					'skin' => 'editorial',
				),
			)
		);

		$this->add_control(
			'intro_eyebrow',
			array(
				'label'       => __( 'Eyebrow', 'palgoals-testimonials' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Real Customers', 'palgoals-testimonials' ),
				'placeholder' => __( 'Real Customers', 'palgoals-testimonials' ),
			)
		);

		$this->add_control(
			'intro_title',
			array(
				'label'       => __( 'Title', 'palgoals-testimonials' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Customers Feedback', 'palgoals-testimonials' ),
				'placeholder' => __( 'Customers Feedback', 'palgoals-testimonials' ),
			)
		);

		$this->add_control(
			'intro_description',
			array(
				'label'       => __( 'Description', 'palgoals-testimonials' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => __( 'Real feedback from clients who trusted Palgoals with their projects.', 'palgoals-testimonials' ),
				'placeholder' => __( 'Add a short introduction above the testimonials.', 'palgoals-testimonials' ),
				'rows'        => 4,
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
					'{{WRAPPER}} .palgoals-testimonials' => '--palgoals-card-bg: {{VALUE}};',
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
					'{{WRAPPER}} .palgoals-testimonial-card' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'card_border',
				'selector' => '{{WRAPPER}} .palgoals-testimonial-card',
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow',
				'selector' => '{{WRAPPER}} .palgoals-testimonial-card',
			)
		);

		$this->add_responsive_control(
			'card_padding',
			array(
				'label'      => __( 'Padding', 'palgoals-testimonials' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .palgoals-testimonial-card' => 'padding: {{SIZE}}{{UNIT}};',
				),
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
				'description' => __( 'Controls the space between testimonial cards.', 'palgoals-testimonials' ),
				'selectors'  => array(
					'{{WRAPPER}} .palgoals-testimonials' => '--palgoals-card-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_avatar',
			array(
				'label' => __( 'Avatar', 'palgoals-testimonials' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'avatar_size',
			array(
				'label'      => __( 'Size', 'palgoals-testimonials' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 40,
						'max' => 140,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .palgoals-testimonials' => '--palgoals-avatar-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'avatar_radius',
			array(
				'label'      => __( 'Border Radius', 'palgoals-testimonials' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
					'%'  => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .palgoals-testimonial-avatar__image, {{WRAPPER}} .palgoals-testimonial-avatar__fallback' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'avatar_border',
				'selector' => '{{WRAPPER}} .palgoals-testimonial-avatar__image, {{WRAPPER}} .palgoals-testimonial-avatar__fallback',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_intro',
			array(
				'label'     => __( 'Intro', 'palgoals-testimonials' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'skin' => 'editorial',
				),
			)
		);

		$this->add_control(
			'intro_eyebrow_color',
			array(
				'label'     => __( 'Eyebrow Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials__eyebrow' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'intro_title_color',
			array(
				'label'     => __( 'Title Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials__title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'intro_description_color',
			array(
				'label'     => __( 'Description Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials__description' => 'color: {{VALUE}};',
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

		$this->add_control(
			'name_color',
			array(
				'label'     => __( 'Client Name Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonial-name, {{WRAPPER}} .palgoals-testimonial-name a' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'name_hover_color',
			array(
				'label'     => __( 'Client Name Hover Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonial-name a:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'meta_color',
			array(
				'label'     => __( 'Meta Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonial-meta' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'company_color',
			array(
				'label'     => __( 'Company Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonial-meta__company' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'content_color',
			array(
				'label'     => __( 'Content Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonial-content' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'name_typography',
				'label'    => __( 'Client Name', 'palgoals-testimonials' ),
				'selector' => '{{WRAPPER}} .palgoals-testimonial-name',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'meta_typography',
				'label'    => __( 'Meta', 'palgoals-testimonials' ),
				'selector' => '{{WRAPPER}} .palgoals-testimonial-meta',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'content_typography',
				'label'    => __( 'Content', 'palgoals-testimonials' ),
				'selector' => '{{WRAPPER}} .palgoals-testimonial-content',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_rating',
			array(
				'label' => __( 'Rating', 'palgoals-testimonials' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'star_color',
			array(
				'label'     => __( 'Star Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials' => '--palgoals-star-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'star_inactive_color',
			array(
				'label'     => __( 'Inactive Star Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonial-stars' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_navigation',
			array(
				'label' => __( 'Navigation', 'palgoals-testimonials' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'nav_button_bg_color',
			array(
				'label'     => __( 'Button Background', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials__button' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'nav_button_text_color',
			array(
				'label'     => __( 'Button Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials__button' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'nav_button_border_color',
			array(
				'label'     => __( 'Button Border Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials__button' => 'border-color: {{VALUE}}; border-style: solid; border-width: 1px;',
				),
			)
		);

		$this->add_control(
			'nav_button_hover_bg_color',
			array(
				'label'     => __( 'Button Hover Background', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials__button:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'nav_button_hover_text_color',
			array(
				'label'     => __( 'Button Hover Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials__button:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'nav_pagination_color',
			array(
				'label'     => __( 'Pagination Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials__pagination .swiper-pagination-bullet' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'nav_pagination_active_color',
			array(
				'label'     => __( 'Pagination Active Color', 'palgoals-testimonials' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .palgoals-testimonials__pagination .swiper-pagination-bullet-active' => 'background-color: {{VALUE}};',
				),
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

		echo Palgoals_Testimonials_Renderer::render(
			array(
				'skin'           => isset( $settings['skin'] ) ? $settings['skin'] : 'default',
				'layout'         => isset( $settings['layout'] ) ? $settings['layout'] : 'grid',
				'limit'          => isset( $settings['limit'] ) ? $settings['limit'] : 6,
				'order'          => isset( $settings['order'] ) ? $settings['order'] : 'DESC',
				'rating'         => isset( $settings['rating'] ) ? $settings['rating'] : 0,
				'columns'        => isset( $settings['columns'] ) ? $settings['columns'] : 3,
				'columns_tablet' => isset( $settings['columns_tablet'] ) ? $settings['columns_tablet'] : 2,
				'columns_mobile' => isset( $settings['columns_mobile'] ) ? $settings['columns_mobile'] : 1,
				'show_photo'     => isset( $settings['show_photo'] ) && 'yes' === $settings['show_photo'],
				'show_company'   => isset( $settings['show_company'] ) && 'yes' === $settings['show_company'],
				'show_rating'    => isset( $settings['show_rating'] ) && 'yes' === $settings['show_rating'],
				'intro_eyebrow'  => isset( $settings['intro_eyebrow'] ) ? $settings['intro_eyebrow'] : '',
				'intro_title'    => isset( $settings['intro_title'] ) ? $settings['intro_title'] : '',
				'intro_description' => isset( $settings['intro_description'] ) ? $settings['intro_description'] : '',
				'autoplay_speed' => isset( $settings['autoplay_speed'] ) ? $settings['autoplay_speed'] : 5000,
			)
		);
	}
}
