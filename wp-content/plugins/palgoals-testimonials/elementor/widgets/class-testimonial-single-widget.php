<?php

/**
 * Elementor Testimonial Single widget.
 *
 * @package PalgoalsTestimonials
 */

namespace Palgoals\Testimonials\Elementor\Widgets;

if (! defined('ABSPATH')) {
    exit;
}

use Elementor\Controls_Manager;

/**
 * Single testimonial widget.
 */
class PG_Testimonial_Single_Widget extends PG_Testimonials_Grid_Widget
{

    /**
     * Widget slug.
     *
     * @return string
     */
    public function get_name()
    {
        return 'palgoals-testimonial-single';
    }

    /**
     * Widget title.
     *
     * @return string
     */
    public function get_title()
    {
        return __('Testimonial Single', 'palgoals-testimonials');
    }

    /**
     * Widget icon.
     *
     * @return string
     */
    public function get_icon()
    {
        return 'eicon-testimonial';
    }

    /**
     * Widget keywords.
     *
     * @return array
     */
    public function get_keywords()
    {
        return array('testimonial', 'single testimonial', 'review', 'quote', 'palgoals');
    }

    /**
     * Register query controls.
     *
     * @return void
     */
    protected function register_query_controls()
    {
        $this->start_controls_section(
            'section_query',
            array(
                'label' => __('Query', 'palgoals-testimonials'),
            )
        );

        $this->add_control(
            'source',
            array(
                'label'   => __('Source', 'palgoals-testimonials'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'manual',
                'options' => array(
                    'manual'   => __('Manual', 'palgoals-testimonials'),
                    'latest'   => __('Latest', 'palgoals-testimonials'),
                    'category' => __('Category', 'palgoals-testimonials'),
                ),
            )
        );

        $this->add_control(
            'testimonial_id',
            array(
                'label'       => __('Testimonial ID', 'palgoals-testimonials'),
                'type'        => Controls_Manager::NUMBER,
                'min'         => 1,
                'description' => __('Select one testimonial post ID for manual mode.', 'palgoals-testimonials'),
                'condition'   => array(
                    'source' => 'manual',
                ),
            )
        );

        $this->add_control(
            'categories',
            array(
                'label'       => __('Categories', 'palgoals-testimonials'),
                'type'        => Controls_Manager::SELECT2,
                'options'     => $this->get_category_options(),
                'multiple'    => true,
                'label_block' => true,
                'condition'   => array(
                    'source' => 'category',
                ),
            )
        );

        $this->add_control(
            'orderby',
            array(
                'label'     => __('Order By', 'palgoals-testimonials'),
                'type'      => Controls_Manager::SELECT,
                'default'   => 'date',
                'options'   => array(
                    'date'  => __('Date', 'palgoals-testimonials'),
                    'title' => __('Title', 'palgoals-testimonials'),
                    'rand'  => __('Random', 'palgoals-testimonials'),
                ),
                'condition' => array(
                    'source!' => 'manual',
                ),
            )
        );

        $this->add_control(
            'order',
            array(
                'label'     => __('Order', 'palgoals-testimonials'),
                'type'      => Controls_Manager::SELECT,
                'default'   => 'DESC',
                'options'   => array(
                    'DESC' => __('DESC', 'palgoals-testimonials'),
                    'ASC'  => __('ASC', 'palgoals-testimonials'),
                ),
                'condition' => array(
                    'source!' => 'manual',
                ),
            )
        );

        $this->end_controls_section();
    }

    /**
     * Register layout controls.
     *
     * @return void
     */
    protected function register_layout_controls()
    {
        $this->start_controls_section(
            'section_layout',
            array(
                'label' => __('Layout', 'palgoals-testimonials'),
            )
        );

        $this->add_control(
            'skin',
            array(
                'label'        => __('Skin', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SELECT,
                'default'      => 'modern',
                'options'      => array(
                    'modern'   => __('Modern', 'palgoals-testimonials'),
                    'minimal'  => __('Minimal', 'palgoals-testimonials'),
                    'boxed'    => __('Boxed', 'palgoals-testimonials'),
                    'featured' => __('Featured', 'palgoals-testimonials'),
                ),
                'prefix_class' => 'pg-testimonials--skin-',
            )
        );

        $this->add_control(
            'alignment',
            array(
                'label'        => __('Content Alignment', 'palgoals-testimonials'),
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

        $this->add_responsive_control(
            'max_width',
            array(
                'label'      => __('Max Width', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px', '%'),
                'default'    => array(
                    'size' => 760,
                    'unit' => 'px',
                ),
                'range'      => array(
                    'px' => array(
                        'min' => 320,
                        'max' => 1400,
                    ),
                    '%' => array(
                        'min' => 30,
                        'max' => 100,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonials--single' => 'max-width: {{SIZE}}{{UNIT}};',
                ),
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
            'show_role',
            array(
                'label'        => __('Show Role', 'palgoals-testimonials'),
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
            'show_link',
            array(
                'label'        => __('Show Link', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Yes', 'palgoals-testimonials'),
                'label_off'    => __('No', 'palgoals-testimonials'),
                'default'      => 'yes',
                'return_value' => 'yes',
            )
        );

        $this->add_control(
            'emphasize_quote',
            array(
                'label'        => __('Emphasize Quote', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Yes', 'palgoals-testimonials'),
                'label_off'    => __('No', 'palgoals-testimonials'),
                'default'      => '',
                'return_value' => 'yes',
                'prefix_class' => 'pg-testimonials--quote-emphasis-',
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
        $settings    = $this->get_settings_for_display();
        $testimonial = $this->get_single_testimonial($settings);

        if (empty($testimonial)) {
            $this->render_single_empty_state($settings);
            return;
        }

        echo '<div class="pg-testimonials pg-testimonials--single">';
        $this->render_testimonial_card($testimonial, $this->get_card_render_settings($settings));
        echo '</div>';
    }

    /**
     * Build normalized query settings for one testimonial.
     *
     * @param array $settings Widget settings.
     * @return array
     */
    protected function get_query_settings($settings)
    {
        $query_settings = parent::get_query_settings($settings);
        $source         = isset($settings['source']) ? $settings['source'] : 'manual';
        $manual_ids     = $this->get_manual_include_ids($settings);

        $query_settings['source']         = $source;
        $query_settings['posts_per_page'] = 1;
        $query_settings['include_ids']    = $manual_ids;

        return $query_settings;
    }

    /**
     * Return the first testimonial payload or null.
     *
     * @param array $settings Widget settings.
     * @return array|null
     */
    protected function get_single_testimonial($settings)
    {
        $testimonials = $this->get_testimonials($settings);

        return empty($testimonials) ? null : $testimonials[0];
    }

    /**
     * Build single-card render settings.
     *
     * @param array $settings Widget settings.
     * @return array
     */
    protected function get_card_render_settings($settings)
    {
        $card_settings = parent::get_card_render_settings($settings);
        $card_classes = array();

        if (isset($settings['skin']) && 'featured' === $settings['skin']) {
            $card_classes[] = 'pg-testimonial-card--featured';
        }

        if (! empty($settings['emphasize_quote'])) {
            $card_classes[] = 'pg-testimonial-card--quote-emphasis';
        }

        $card_settings['show_role'] = ! isset($settings['show_role']) || ! empty($settings['show_role']);
        $card_settings['show_link'] = ! isset($settings['show_link']) || ! empty($settings['show_link']);

        if (empty($card_settings['card_classes'])) {
            $card_settings['card_classes'] = array();
        }

        $card_settings['card_classes'] = array_values(
            array_unique(
                array_merge((array) $card_settings['card_classes'], $card_classes)
            )
        );

        return $card_settings;
    }

    /**
     * Render a specific empty state for the single widget.
     *
     * @param array $settings Widget settings.
     * @return void
     */
    protected function render_single_empty_state($settings)
    {
        $message = $this->get_empty_state_message($settings);

        echo '<div class="pg-testimonials pg-testimonials--single"><p class="pg-testimonials__empty">' . esc_html($message) . '</p></div>';
    }

    /**
     * Build an empty-state message for the current single-widget settings.
     *
     * @param array $settings Widget settings.
     * @return string
     */
    protected function get_empty_state_message($settings)
    {
        $source = isset($settings['source']) ? $settings['source'] : 'manual';

        if ('manual' !== $source) {
            return __('No testimonials found.', 'palgoals-testimonials');
        }

        $manual_id = $this->get_manual_testimonial_id($settings);

        if ($manual_id <= 0) {
            return __('Select a testimonial ID in Manual source mode to display one testimonial.', 'palgoals-testimonials');
        }

        $post = get_post($manual_id);

        if (! $post || \Palgoals_Testimonials_CPT::POST_TYPE !== $post->post_type) {
            return __('The selected testimonial could not be found.', 'palgoals-testimonials');
        }

        if ('publish' !== $post->post_status) {
            return __('The selected testimonial is not published.', 'palgoals-testimonials');
        }

        $status = get_post_meta($manual_id, \Palgoals_Testimonials_CPT::META_STATUS, true);

        if ('' !== $status && \Palgoals_Testimonials_CPT::STATUS_ACTIVE !== $status) {
            return __('The selected testimonial is inactive and cannot be displayed.', 'palgoals-testimonials');
        }

        return __('The selected testimonial is unavailable.', 'palgoals-testimonials');
    }

    /**
     * Resolve manual testimonial IDs with backward-compatible fallback support.
     *
     * @param array $settings Widget settings.
     * @return array
     */
    protected function get_manual_include_ids($settings)
    {
        $manual_id = $this->get_manual_testimonial_id($settings);

        if ($manual_id > 0) {
            return array($manual_id);
        }

        if (! empty($settings['include_ids'])) {
            return array_values(array_filter(array_map('absint', (array) $settings['include_ids'])));
        }

        return array();
    }

    /**
     * Resolve a single manual testimonial ID with backward-compatible fallback support.
     *
     * @param array $settings Widget settings.
     * @return int
     */
    protected function get_manual_testimonial_id($settings)
    {
        if (! empty($settings['testimonial_id'])) {
            return absint($settings['testimonial_id']);
        }

        if (empty($settings['include_ids'])) {
            return 0;
        }

        $include_ids = array_values(array_filter(array_map('absint', (array) $settings['include_ids'])));

        return empty($include_ids) ? 0 : $include_ids[0];
    }
}
