<?php

/**
 * Elementor Testimonials Slider widget.
 *
 * @package PalgoalsTestimonials
 */

namespace Palgoals\Testimonials\Elementor\Widgets;

if (! defined('ABSPATH')) {
    exit;
}

use Elementor\Controls_Manager;

/**
 * Testimonials slider widget.
 */
class PG_Testimonials_Slider_Widget extends PG_Testimonials_Grid_Widget
{

    /**
     * Ensure slider styles are available.
     *
     * @return array
     */
    public function get_style_depends()
    {
        \Palgoals_Testimonials_Renderer::register_assets();

        $depends      = array(\Palgoals_Testimonials_Renderer::STYLE_HANDLE);
        $swiper_style = \Palgoals_Testimonials_Renderer::get_swiper_style_handle();

        if ($swiper_style) {
            $depends[] = $swiper_style;
        }

        return array_values(array_unique($depends));
    }

    /**
     * Ensure slider scripts are available.
     *
     * @return array
     */
    public function get_script_depends()
    {
        \Palgoals_Testimonials_Renderer::register_assets();

        $depends       = array();
        $swiper_script = \Palgoals_Testimonials_Renderer::get_swiper_script_handle();

        if ($swiper_script) {
            $depends[] = $swiper_script;
        }

        $depends[] = \Palgoals_Testimonials_Renderer::SCRIPT_HANDLE;

        return array_values(array_unique($depends));
    }

    /**
     * Widget slug.
     *
     * @return string
     */
    public function get_name()
    {
        return 'palgoals-testimonials-slider';
    }

    /**
     * Widget title.
     *
     * @return string
     */
    public function get_title()
    {
        return __('Testimonials Slider', 'palgoals-testimonials');
    }

    /**
     * Widget icon.
     *
     * @return string
     */
    public function get_icon()
    {
        return 'eicon-slider-push';
    }

    /**
     * Widget keywords.
     *
     * @return array
     */
    public function get_keywords()
    {
        return array('testimonial', 'testimonials', 'review', 'slider', 'carousel', 'palgoals');
    }

    /**
     * Register widget controls.
     *
     * @return void
     */
    protected function register_controls()
    {
        $this->register_query_controls();
        $this->register_slider_layout_controls();
        $this->register_style_controls();
    }

    /**
     * Register slider layout controls.
     *
     * @return void
     */
    protected function register_slider_layout_controls()
    {
        $this->start_controls_section(
            'section_layout',
            array(
                'label' => __('Layout', 'palgoals-testimonials'),
            )
        );

        $this->add_responsive_control(
            'slides_per_view',
            array(
                'label'          => __('Slides Per View', 'palgoals-testimonials'),
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
                'selectors'      => array(
                    '{{WRAPPER}} .pg-testimonials__slider:not(.palgoals-is-initialized) .swiper-wrapper' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
                ),
            )
        );

        $this->add_responsive_control(
            'grid_gap',
            array(
                'label'      => __('Gap Between Items', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px'),
                'default'    => array(
                    'size' => 24,
                    'unit' => 'px',
                ),
                'range'      => array(
                    'px' => array(
                        'min' => 0,
                        'max' => 80,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonials' => '--pg-gap: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_control(
            'alignment',
            array(
                'label'        => __('Card Alignment', 'palgoals-testimonials'),
                'type'         => Controls_Manager::CHOOSE,
                'default'      => 'left',
                'options'      => array(
                    'left'   => array(
                        'title' => __('Left', 'palgoals-testimonials'),
                        'icon'  => 'eicon-text-align-left',
                    ),
                    'center' => array(
                        'title' => __('Center', 'palgoals-testimonials'),
                        'icon'  => 'eicon-text-align-center',
                    ),
                    'right'  => array(
                        'title' => __('Right', 'palgoals-testimonials'),
                        'icon'  => 'eicon-text-align-right',
                    ),
                ),
                'toggle'       => false,
                'prefix_class' => 'pg-testimonials--align-',
            )
        );

        $this->add_control(
            'equal_height',
            array(
                'label'        => __('Equal Height', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Yes', 'palgoals-testimonials'),
                'label_off'    => __('No', 'palgoals-testimonials'),
                'default'      => 'yes',
                'return_value' => 'yes',
                'prefix_class' => 'pg-testimonials--equal-height-',
            )
        );

        $this->add_control(
            'skin',
            array(
                'label'        => __('Skin', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SELECT,
                'default'      => 'modern',
                'options'      => array(
                    'modern'  => __('Modern', 'palgoals-testimonials'),
                    'minimal' => __('Minimal', 'palgoals-testimonials'),
                    'boxed'   => __('Boxed', 'palgoals-testimonials'),
                ),
                'prefix_class' => 'pg-testimonials--skin-',
            )
        );

        $this->add_control(
            'show_avatar',
            array(
                'label'        => __('Show Avatar', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Yes', 'palgoals-testimonials'),
                'label_off'    => __('No', 'palgoals-testimonials'),
                'default'      => 'yes',
                'return_value' => 'yes',
            )
        );

        $this->add_control(
            'show_rating',
            array(
                'label'        => __('Show Rating', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Yes', 'palgoals-testimonials'),
                'label_off'    => __('No', 'palgoals-testimonials'),
                'default'      => 'yes',
                'return_value' => 'yes',
            )
        );

        $this->add_control(
            'show_arrows',
            array(
                'label'        => __('Show Arrows', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Show', 'palgoals-testimonials'),
                'label_off'    => __('Hide', 'palgoals-testimonials'),
                'default'      => 'yes',
                'return_value' => 'yes',
            )
        );

        $this->add_control(
            'show_dots',
            array(
                'label'        => __('Show Dots', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Show', 'palgoals-testimonials'),
                'label_off'    => __('Hide', 'palgoals-testimonials'),
                'default'      => 'yes',
                'return_value' => 'yes',
            )
        );

        $this->add_control(
            'loop',
            array(
                'label'        => __('Loop Slides', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Yes', 'palgoals-testimonials'),
                'label_off'    => __('No', 'palgoals-testimonials'),
                'default'      => 'yes',
                'return_value' => 'yes',
            )
        );

        $this->add_control(
            'autoplay',
            array(
                'label'        => __('Autoplay', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('On', 'palgoals-testimonials'),
                'label_off'    => __('Off', 'palgoals-testimonials'),
                'default'      => '',
                'return_value' => 'yes',
            )
        );

        $this->add_control(
            'autoplay_speed',
            array(
                'label'     => __('Autoplay Speed (ms)', 'palgoals-testimonials'),
                'type'      => Controls_Manager::NUMBER,
                'default'   => 4500,
                'min'       => 1000,
                'step'      => 100,
                'condition' => array(
                    'autoplay' => 'yes',
                ),
            )
        );

        $this->add_control(
            'pause_on_hover',
            array(
                'label'        => __('Pause On Hover', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Yes', 'palgoals-testimonials'),
                'label_off'    => __('No', 'palgoals-testimonials'),
                'default'      => 'yes',
                'return_value' => 'yes',
                'condition'    => array(
                    'autoplay' => 'yes',
                ),
            )
        );

        $this->add_control(
            'transition_speed',
            array(
                'label'   => __('Transition Speed (ms)', 'palgoals-testimonials'),
                'type'    => Controls_Manager::NUMBER,
                'default' => 650,
                'min'     => 100,
                'step'    => 50,
            )
        );

        $this->end_controls_section();
    }

    /**
     * Backward-compatible alias for layout registration.
     *
     * @return void
     */
    protected function register_layout_controls()
    {
        $this->register_slider_layout_controls();
    }

    /**
     * Register slider-specific style controls.
     *
     * @return void
     */
    protected function register_style_controls()
    {
        parent::register_style_controls();

        $this->start_controls_section(
            'section_style_slider_controls',
            array(
                'label' => __('Slider Controls', 'palgoals-testimonials'),
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_responsive_control(
            'slider_button_size',
            array(
                'label'      => __('Arrow Size', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px'),
                'range'      => array(
                    'px' => array(
                        'min' => 28,
                        'max' => 80,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonials' => '--pg-slider-button-size: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_control(
            'slider_button_bg',
            array(
                'label'     => __('Arrow Background', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonials' => '--pg-slider-button-bg: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'slider_button_color',
            array(
                'label'     => __('Arrow Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonials' => '--pg-slider-button-color: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'slider_pagination_color',
            array(
                'label'     => __('Pagination Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonials' => '--pg-slider-pagination-color: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'slider_pagination_active_color',
            array(
                'label'     => __('Active Pagination Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonials' => '--pg-slider-pagination-active-color: {{VALUE}};',
                ),
            )
        );

        $this->end_controls_section();
    }

    /**
     * Render the widget output.
     *
     * @return void
     */
    protected function render()
    {
        $settings     = $this->normalize_slider_settings($this->get_settings_for_display());
        $testimonials = $this->get_testimonials($settings);

        \Palgoals_Testimonials_Asset_Manager::enqueue_slider_assets();

        if (empty($testimonials)) {
            $this->render_empty_state('pg-testimonials pg-testimonials--slider');
            return;
        }

        $mobile_slides  = $this->get_responsive_setting($settings, 'slides_per_view_mobile', 1);
        $tablet_slides  = $this->get_responsive_setting($settings, 'slides_per_view_tablet', 2);
        $desktop_slides = $this->get_responsive_setting($settings, 'slides_per_view', 3);
        $max_visible    = max($mobile_slides, $tablet_slides, $desktop_slides);
        $show_arrows    = $this->is_enabled($settings, 'show_arrows', 'show_navigation');
        $show_dots      = $this->is_enabled($settings, 'show_dots', 'show_pagination');
        $show_controls  = $show_arrows || $show_dots;
        $auto_height    = 'yes' !== (isset($settings['equal_height']) ? $settings['equal_height'] : 'yes') && 1 === $max_visible;
        $swiper_options = array(
            'speed'         => max(100, absint(isset($settings['transition_speed']) ? $settings['transition_speed'] : 650)),
            'spaceBetween'  => 24,
            'watchOverflow' => true,
            'autoHeight'    => $auto_height,
            'layout'        => 'carousel',
            'slidesPerView' => $mobile_slides,
            'breakpoints'   => array(
                0    => array(
                    'slidesPerView' => $mobile_slides,
                ),
                768  => array(
                    'slidesPerView' => $tablet_slides,
                ),
                1024 => array(
                    'slidesPerView' => $desktop_slides,
                ),
            ),
            'loop'          => ! empty($settings['loop']) && count($testimonials) > $max_visible,
        );

        if (! empty($settings['autoplay'])) {
            $swiper_options['autoplay'] = array(
                'delay'                => max(1000, absint(isset($settings['autoplay_speed']) ? $settings['autoplay_speed'] : 4500)),
                'disableOnInteraction' => false,
                'pauseOnMouseEnter'    => ! empty($settings['pause_on_hover']),
            );
        }

        echo '<div class="pg-testimonials pg-testimonials--slider">';
        echo '<div class="pg-testimonials__slider js-palgoals-swiper" data-swiper-options="' . esc_attr(wp_json_encode($swiper_options)) . '">';
        echo '<div class="swiper-wrapper">';

        foreach ($testimonials as $testimonial) {
            echo '<div class="swiper-slide pg-testimonials__slide">';
            $this->render_testimonial_card($testimonial, $this->get_card_render_settings($settings));
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';

        if ($show_controls) {
            $prev_icon = is_rtl() ? '&rarr;' : '&larr;';
            $next_icon = is_rtl() ? '&larr;' : '&rarr;';

            echo '<div class="pg-testimonials__controls">';

            if ($show_arrows) {
                echo '<button class="pg-testimonials__button pg-testimonials__button--prev" type="button" aria-label="' . esc_attr__('Previous testimonials', 'palgoals-testimonials') . '">' . wp_kses_post($prev_icon) . '</button>';
            }

            if ($show_dots) {
                echo '<div class="pg-testimonials__pagination" aria-hidden="true"></div>';
            }

            if ($show_arrows) {
                echo '<button class="pg-testimonials__button pg-testimonials__button--next" type="button" aria-label="' . esc_attr__('Next testimonials', 'palgoals-testimonials') . '">' . wp_kses_post($next_icon) . '</button>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Resolve a responsive numeric setting.
     *
     * @param array  $settings Widget settings.
     * @param string $key      Setting key.
     * @param int    $default  Default value.
     * @return int
     */
    protected function get_responsive_setting($settings, $key, $default)
    {
        return max(1, absint(isset($settings[$key]) ? $settings[$key] : $default));
    }

    /**
     * Normalize interim slider settings for render and preview.
     *
     * @param array $settings Raw widget settings.
     * @return array
     */
    protected function normalize_slider_settings($settings)
    {
        if (! isset($settings['show_arrows']) && isset($settings['show_navigation'])) {
            $settings['show_arrows'] = $settings['show_navigation'];
        }

        if (! isset($settings['show_dots']) && isset($settings['show_pagination'])) {
            $settings['show_dots'] = $settings['show_pagination'];
        }

        return $settings;
    }

    /**
     * Resolve an enabled switcher setting with backward-compatible fallback.
     *
     * @param array  $settings Widget settings.
     * @param string $key      Preferred key.
     * @param string $fallback Fallback key.
     * @return bool
     */
    protected function is_enabled($settings, $key, $fallback = '')
    {
        if (isset($settings[$key])) {
            return ! empty($settings[$key]);
        }

        if ($fallback && isset($settings[$fallback])) {
            return ! empty($settings[$fallback]);
        }

        return false;
    }
}
