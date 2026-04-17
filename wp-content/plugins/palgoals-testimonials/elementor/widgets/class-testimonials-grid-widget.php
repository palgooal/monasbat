<?php

/**
 * Elementor Testimonials Grid widget.
 *
 * @package PalgoalsTestimonials
 */

namespace Palgoals\Testimonials\Elementor\Widgets;

if (! defined('ABSPATH')) {
    exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

/**
 * Testimonials grid widget.
 */
class PG_Testimonials_Grid_Widget extends Widget_Base
{

    /**
     * Ensure shared frontend styles are available in Elementor preview.
     *
     * @return array
     */
    public function get_style_depends()
    {
        \Palgoals_Testimonials_Renderer::register_assets();

        return array(\Palgoals_Testimonials_Renderer::STYLE_HANDLE);
    }

    /**
     * Widget slug.
     *
     * @return string
     */
    public function get_name()
    {
        return 'palgoals-testimonials-grid';
    }

    /**
     * Widget title.
     *
     * @return string
     */
    public function get_title()
    {
        return __('Testimonials Grid', 'palgoals-testimonials');
    }

    /**
     * Widget icon.
     *
     * @return string
     */
    public function get_icon()
    {
        return 'eicon-posts-grid';
    }

    /**
     * Widget category.
     *
     * @return array
     */
    public function get_categories()
    {
        return array('palgoals-elements');
    }

    /**
     * Widget keywords.
     *
     * @return array
     */
    public function get_keywords()
    {
        return array('testimonial', 'testimonials', 'review', 'grid', 'palgoals');
    }

    /**
     * Register widget controls.
     *
     * @return void
     */
    protected function register_controls()
    {
        $this->register_query_controls();
        $this->register_layout_controls();
        $this->register_style_controls();
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
                'default' => 'latest',
                'options' => array(
                    'manual'   => __('Manual', 'palgoals-testimonials'),
                    'latest'   => __('Latest', 'palgoals-testimonials'),
                    'category' => __('Category', 'palgoals-testimonials'),
                ),
            )
        );

        $this->add_control(
            'include_ids',
            array(
                'label'       => __('Include Specific IDs', 'palgoals-testimonials'),
                'type'        => Controls_Manager::TEXT,
                'placeholder' => '12, 18, 24',
                'description' => __('Enter testimonial post IDs separated by commas.', 'palgoals-testimonials'),
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
            'posts_per_page',
            array(
                'label'   => __('Posts Per Page', 'palgoals-testimonials'),
                'type'    => Controls_Manager::NUMBER,
                'default' => 6,
                'min'     => 1,
                'max'     => 24,
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
                'label'   => __('Order', 'palgoals-testimonials'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'DESC',
                'options' => array(
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

        $this->add_responsive_control(
            'columns',
            array(
                'label'          => __('Columns', 'palgoals-testimonials'),
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
                    '{{WRAPPER}} .pg-testimonials__grid' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
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

        $this->end_controls_section();
    }

    /**
     * Register style controls.
     *
     * @return void
     */
    protected function register_style_controls()
    {
        $this->start_controls_section(
            'section_style_card',
            array(
                'label' => __('Card', 'palgoals-testimonials'),
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_control(
            'card_background_color',
            array(
                'label'     => __('Background Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonial-card' => 'background-color: {{VALUE}};',
                ),
            )
        );

        $this->add_responsive_control(
            'card_padding',
            array(
                'label'      => __('Padding', 'palgoals-testimonials'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => array('px', '%'),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonial-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ),
            )
        );

        $this->add_responsive_control(
            'card_border_radius',
            array(
                'label'      => __('Border Radius', 'palgoals-testimonials'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => array('px', '%'),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonial-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ),
            )
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            array(
                'name'     => 'card_border',
                'selector' => '{{WRAPPER}} .pg-testimonial-card',
            )
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            array(
                'name'     => 'card_box_shadow',
                'selector' => '{{WRAPPER}} .pg-testimonial-card',
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_avatar',
            array(
                'label' => __('Avatar', 'palgoals-testimonials'),
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_responsive_control(
            'avatar_size',
            array(
                'label'      => __('Size', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px'),
                'default'    => array(
                    'size' => 72,
                    'unit' => 'px',
                ),
                'range'      => array(
                    'px' => array(
                        'min' => 32,
                        'max' => 140,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonial-card__avatar' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_responsive_control(
            'avatar_border_radius',
            array(
                'label'      => __('Border Radius', 'palgoals-testimonials'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => array('px', '%'),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonial-card__avatar-image, {{WRAPPER}} .pg-testimonial-card__avatar-fallback' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ),
            )
        );

        $this->add_responsive_control(
            'avatar_spacing',
            array(
                'label'      => __('Spacing', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px'),
                'default'    => array(
                    'size' => 16,
                    'unit' => 'px',
                ),
                'range'      => array(
                    'px' => array(
                        'min' => 0,
                        'max' => 48,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonial-card__header' => 'gap: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_name',
            array(
                'label' => __('Name', 'palgoals-testimonials'),
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            array(
                'name'     => 'name_typography',
                'selector' => '{{WRAPPER}} .pg-testimonial-card__name',
            )
        );

        $this->add_control(
            'name_color',
            array(
                'label'     => __('Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonial-card__name, {{WRAPPER}} .pg-testimonial-card__name a' => 'color: {{VALUE}};',
                ),
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_role',
            array(
                'label' => __('Role', 'palgoals-testimonials'),
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            array(
                'name'     => 'role_typography',
                'selector' => '{{WRAPPER}} .pg-testimonial-card__role',
            )
        );

        $this->add_control(
            'role_color',
            array(
                'label'     => __('Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonial-card__role' => 'color: {{VALUE}};',
                ),
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_rating',
            array(
                'label' => __('Rating', 'palgoals-testimonials'),
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_responsive_control(
            'rating_star_size',
            array(
                'label'      => __('Star Size', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px'),
                'default'    => array(
                    'size' => 18,
                    'unit' => 'px',
                ),
                'range'      => array(
                    'px' => array(
                        'min' => 10,
                        'max' => 40,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonial-card__star' => 'font-size: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_control(
            'rating_color',
            array(
                'label'     => __('Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonial-card__star.is-active' => 'color: {{VALUE}};',
                ),
            )
        );

        $this->add_responsive_control(
            'rating_spacing',
            array(
                'label'      => __('Spacing', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px'),
                'default'    => array(
                    'size' => 0,
                    'unit' => 'px',
                ),
                'range'      => array(
                    'px' => array(
                        'min' => 0,
                        'max' => 40,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonial-card__rating' => 'margin-bottom: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_content',
            array(
                'label' => __('Content', 'palgoals-testimonials'),
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            array(
                'name'     => 'content_typography',
                'selector' => '{{WRAPPER}} .pg-testimonial-card__content',
            )
        );

        $this->add_control(
            'content_color',
            array(
                'label'     => __('Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonial-card__content' => 'color: {{VALUE}};',
                ),
            )
        );

        $this->add_responsive_control(
            'content_line_height',
            array(
                'label'      => __('Line Height', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('em', 'px'),
                'default'    => array(
                    'size' => 1.8,
                    'unit' => 'em',
                ),
                'range'      => array(
                    'em' => array(
                        'min' => 1,
                        'max' => 3,
                        'step' => 0.1,
                    ),
                    'px' => array(
                        'min' => 16,
                        'max' => 60,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonial-card__content' => 'line-height: {{SIZE}}{{UNIT}};',
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
        $settings     = $this->get_settings_for_display();
        $testimonials = $this->get_testimonials($settings);

        if (empty($testimonials)) {
            $this->render_empty_state();
            return;
        }

        echo '<div class="pg-testimonials">';
        echo '<div class="pg-testimonials__grid">';

        foreach ($testimonials as $testimonial) {
            $this->render_testimonial_card($testimonial, $this->get_card_render_settings($settings));
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Render the empty state.
     *
     * @param string $wrapper_classes Wrapper class list.
     * @return void
     */
    protected function render_empty_state($wrapper_classes = 'pg-testimonials')
    {
        echo '<div class="' . esc_attr($wrapper_classes) . '"><p class="pg-testimonials__empty">' . esc_html__('No testimonials found.', 'palgoals-testimonials') . '</p></div>';
    }

    /**
     * Build normalized query settings from widget settings.
     *
     * @param array $settings Widget settings.
     * @return array
     */
    protected function get_query_settings($settings)
    {
        return array(
            'source'         => isset($settings['source']) ? $settings['source'] : 'latest',
            'posts_per_page' => isset($settings['posts_per_page']) ? $settings['posts_per_page'] : 6,
            'orderby'        => isset($settings['orderby']) ? $settings['orderby'] : 'date',
            'order'          => isset($settings['order']) ? $settings['order'] : 'DESC',
            'include_ids'    => isset($settings['include_ids']) ? $settings['include_ids'] : '',
            'categories'     => isset($settings['categories']) ? $settings['categories'] : array(),
        );
    }

    /**
     * Fetch testimonials through the shared query layer.
     *
     * @param array $settings Widget settings.
     * @return array
     */
    protected function get_testimonials($settings)
    {
        return \Palgoals_Testimonials_Query::get_testimonials($this->get_query_settings($settings));
    }

    /**
     * Build card render settings.
     *
     * @param array $settings Widget settings.
     * @return array
     */
    protected function get_card_render_settings($settings)
    {
        return array(
            'show_avatar' => ! isset($settings['show_avatar']) || ! empty($settings['show_avatar']),
            'show_rating' => ! isset($settings['show_rating']) || ! empty($settings['show_rating']),
        );
    }

    /**
     * Build query arguments from widget settings.
     *
     * @param array $settings Widget settings.
     * @return array
     */
    protected function get_query_args($settings)
    {
        return \Palgoals_Testimonials_Query::build_testimonial_query_args($this->get_query_settings($settings));
    }

    /**
     * Render one testimonial card.
     *
     * @param array|int $testimonial Testimonial payload or post ID.
     * @param array     $settings    Card render settings.
     * @return void
     */
    protected function render_testimonial_card($testimonial, $settings = array())
    {
        if (! is_array($testimonial)) {
            $testimonial = \Palgoals_Testimonials_Query::prepare_testimonial(absint($testimonial));
        }

        echo \Palgoals_Testimonial_Card_Renderer::render($testimonial, $settings);
    }

    /**
     * Render the testimonial avatar.
     *
     * @param int    $photo_id Attachment ID.
     * @param string $name     Client name.
     * @return void
     */
    protected function render_avatar($photo_id, $name)
    {
        echo '<div class="pg-testimonial-card__avatar">';

        if ($photo_id > 0) {
            echo wp_get_attachment_image(
                $photo_id,
                'thumbnail',
                false,
                array(
                    'class'   => 'pg-testimonial-card__avatar-image',
                    'loading' => 'lazy',
                    'alt'     => esc_attr($name),
                )
            );
        } else {
            echo '<span class="pg-testimonial-card__avatar-fallback" aria-hidden="true">' . esc_html($this->get_initials($name)) . '</span>';
        }

        echo '</div>';
    }

    /**
     * Build rating markup.
     *
     * @param int $rating Rating value.
     * @return string
     */
    protected function get_rating_markup($rating)
    {
        $rating = max(0, min(5, absint($rating)));
        $output = '<div class="pg-testimonial-card__rating" aria-label="' . esc_attr(sprintf(__('%d out of 5 stars', 'palgoals-testimonials'), $rating)) . '">';

        for ($index = 1; $index <= 5; $index++) {
            $classes = 'pg-testimonial-card__star';

            if ($index <= $rating) {
                $classes .= ' is-active';
            }

            $output .= '<span class="' . esc_attr($classes) . '" aria-hidden="true">&#9733;</span>';
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Return a combined role label from the testimonial meta.
     *
     * @param int $post_id Testimonial post ID.
     * @return string
     */
    protected function get_role_label($post_id)
    {
        $position = trim((string) get_post_meta($post_id, \Palgoals_Testimonials_CPT::META_POSITION, true));
        $company  = trim((string) get_post_meta($post_id, \Palgoals_Testimonials_CPT::META_COMPANY, true));
        $parts    = array_filter(array($position, $company));

        return implode(' • ', $parts);
    }

    /**
     * Parse a comma-separated ID list.
     *
     * @param string $value Raw input.
     * @return array
     */
    protected function parse_post_ids($value)
    {
        $ids = preg_split('/\s*,\s*/', (string) $value);
        $ids = array_map('absint', $ids);
        $ids = array_filter($ids);

        return array_values(array_unique($ids));
    }

    /**
     * Map widget order values to WP_Query values.
     *
     * @param string $orderby Order setting.
     * @return string
     */
    protected function map_orderby($orderby)
    {
        $allowed = array('date', 'title', 'rand');

        if (in_array($orderby, $allowed, true)) {
            return $orderby;
        }

        return 'date';
    }

    /**
     * Build category options for the query control.
     *
     * @return array
     */
    protected function get_category_options()
    {
        $terms   = get_terms(
            array(
                'taxonomy'   => \Palgoals_Testimonials_CPT::CATEGORY_TAXONOMY,
                'hide_empty' => false,
            )
        );
        $options = array();

        if (is_wp_error($terms) || empty($terms)) {
            return $options;
        }

        foreach ($terms as $term) {
            $options[$term->term_id] = $term->name;
        }

        return $options;
    }

    /**
     * Build initials for the avatar fallback.
     *
     * @param string $name Full name.
     * @return string
     */
    protected function get_initials($name)
    {
        return \Palgoals_Testimonials_Query::get_initials($name);
    }
}
