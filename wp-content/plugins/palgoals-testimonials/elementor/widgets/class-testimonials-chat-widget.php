<?php

/**
 * Elementor Testimonials Chat widget.
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

/**
 * Testimonials chat widget.
 */
class PG_Testimonials_Chat_Widget extends PG_Testimonials_Grid_Widget
{

    /**
     * Widget slug.
     *
     * @return string
     */
    public function get_name()
    {
        return 'palgoals-testimonials-chat';
    }

    /**
     * Widget title.
     *
     * @return string
     */
    public function get_title()
    {
        return __('Testimonials Chat', 'palgoals-testimonials');
    }

    /**
     * Widget icon.
     *
     * @return string
     */
    public function get_icon()
    {
        return 'eicon-comments';
    }

    /**
     * Widget keywords.
     *
     * @return array
     */
    public function get_keywords()
    {
        return array('testimonial', 'chat', 'conversation', 'whatsapp', 'messenger', 'palgoals');
    }

    /**
     * Register widget controls.
     *
     * @return void
     */
    protected function register_controls()
    {
        $this->register_query_controls();
        $this->register_chat_layout_controls();
        $this->register_style_controls();
    }

    /**
     * Backward-compatible layout registration alias.
     *
     * @return void
     */
    protected function register_layout_controls()
    {
        $this->register_chat_layout_controls();
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
                'label'   => __('Messages to Show', 'palgoals-testimonials'),
                'type'    => Controls_Manager::NUMBER,
                'default' => 5,
                'min'     => 1,
                'max'     => 20,
            )
        );

        $this->add_control(
            'orderby',
            array(
                'label'     => __('Order By', 'palgoals-testimonials'),
                'type'      => Controls_Manager::SELECT,
                'default'   => 'date',
                'options'   => array(
                    'date' => __('Date', 'palgoals-testimonials'),
                    'rand' => __('Random', 'palgoals-testimonials'),
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
     * Register chat layout controls.
     *
     * @return void
     */
    protected function register_chat_layout_controls()
    {
        $this->start_controls_section(
            'section_layout',
            array(
                'label' => __('Chat Layout', 'palgoals-testimonials'),
            )
        );

        $this->add_control(
            'bubble_alignment_mode',
            array(
                'label'   => __('Bubble Alignment Mode', 'palgoals-testimonials'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'alternating',
                'options' => array(
                    'alternating' => __('Alternating', 'palgoals-testimonials'),
                    'all-left'    => __('All Left', 'palgoals-testimonials'),
                    'all-right'   => __('All Right', 'palgoals-testimonials'),
                ),
            )
        );

        $this->add_control(
            'sender_type',
            array(
                'label'   => __('Sender Type', 'palgoals-testimonials'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'auto',
                'options' => array(
                    'auto'     => __('Auto', 'palgoals-testimonials'),
                    'customer' => __('Customer', 'palgoals-testimonials'),
                    'brand'    => __('Admin / Brand', 'palgoals-testimonials'),
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
            'show_name',
            array(
                'label'        => __('Show Name', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Yes', 'palgoals-testimonials'),
                'label_off'    => __('No', 'palgoals-testimonials'),
                'default'      => 'yes',
                'return_value' => 'yes',
            )
        );

        $this->add_control(
            'show_timestamp',
            array(
                'label'        => __('Show Timestamp', 'palgoals-testimonials'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Yes', 'palgoals-testimonials'),
                'label_off'    => __('No', 'palgoals-testimonials'),
                'default'      => '',
                'return_value' => 'yes',
            )
        );

        $this->add_control(
            'bubble_style',
            array(
                'label'   => __('Bubble Style', 'palgoals-testimonials'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'whatsapp',
                'options' => array(
                    'whatsapp' => __('WhatsApp', 'palgoals-testimonials'),
                    'minimal'  => __('Minimal', 'palgoals-testimonials'),
                    'card'     => __('Card-like', 'palgoals-testimonials'),
                ),
            )
        );

        $this->add_responsive_control(
            'message_gap',
            array(
                'label'      => __('Spacing Between Messages', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px'),
                'default'    => array(
                    'size' => 18,
                    'unit' => 'px',
                ),
                'range'      => array(
                    'px' => array(
                        'min' => 0,
                        'max' => 60,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonials-chat' => '--pg-chat-gap: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_responsive_control(
            'container_max_width',
            array(
                'label'      => __('Max Width', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px', '%'),
                'default'    => array(
                    'size' => 820,
                    'unit' => 'px',
                ),
                'range'      => array(
                    'px' => array(
                        'min' => 320,
                        'max' => 1400,
                    ),
                    '%' => array(
                        'min' => 40,
                        'max' => 100,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonials-chat' => 'max-width: {{SIZE}}{{UNIT}};',
                ),
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
            'section_style_bubble',
            array(
                'label' => __('Bubble', 'palgoals-testimonials'),
                'tab'   => Controls_Manager::TAB_STYLE,
            )
        );

        $this->add_control(
            'left_bubble_background_color',
            array(
                'label'     => __('Left Bubble Background', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonials-chat' => '--pg-chat-left-bg: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'right_bubble_background_color',
            array(
                'label'     => __('Right Bubble Background', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-testimonials-chat' => '--pg-chat-right-bg: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'bubble_text_color',
            array(
                'label'     => __('Text Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-chat-bubble' => 'color: {{VALUE}};',
                    '{{WRAPPER}} .pg-chat-content' => 'color: {{VALUE}};',
                ),
            )
        );

        $this->add_responsive_control(
            'bubble_content_gap',
            array(
                'label'      => __('Bubble Inner Spacing', 'palgoals-testimonials'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => array('px'),
                'default'    => array(
                    'size' => 10,
                    'unit' => 'px',
                ),
                'range'      => array(
                    'px' => array(
                        'min' => 0,
                        'max' => 32,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-chat-bubble' => 'gap: {{SIZE}}{{UNIT}};',
                ),
            )
        );

        $this->add_responsive_control(
            'bubble_border_radius',
            array(
                'label'      => __('Bubble Border Radius', 'palgoals-testimonials'),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => array('px', '%'),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-chat-bubble' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ),
            )
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            array(
                'name'     => 'bubble_border',
                'selector' => '{{WRAPPER}} .pg-chat-bubble',
            )
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            array(
                'name'     => 'bubble_box_shadow',
                'selector' => '{{WRAPPER}} .pg-chat-bubble',
            )
        );

        $this->add_control(
            'bubble_text_alignment',
            array(
                'label'     => __('Text Alignment', 'palgoals-testimonials'),
                'type'      => Controls_Manager::CHOOSE,
                'default'   => 'left',
                'options'   => array(
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
                'toggle'    => false,
                'selectors' => array(
                    '{{WRAPPER}} .pg-chat-bubble' => 'text-align: {{VALUE}};',
                ),
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
                    'size' => 44,
                    'unit' => 'px',
                ),
                'range'      => array(
                    'px' => array(
                        'min' => 24,
                        'max' => 96,
                    ),
                ),
                'selectors'  => array(
                    '{{WRAPPER}} .pg-testimonials-chat' => '--pg-chat-avatar-size: {{SIZE}}{{UNIT}};',
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
                'selector' => '{{WRAPPER}} .pg-chat-name',
            )
        );

        $this->add_control(
            'name_color',
            array(
                'label'     => __('Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-chat-name' => 'color: {{VALUE}};',
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
                'selector' => '{{WRAPPER}} .pg-chat-content',
            )
        );

        $this->add_control(
            'content_color',
            array(
                'label'     => __('Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-chat-content' => 'color: {{VALUE}};',
                ),
            )
        );

        $this->add_control(
            'timestamp_color',
            array(
                'label'     => __('Timestamp Color', 'palgoals-testimonials'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => array(
                    '{{WRAPPER}} .pg-chat-timestamp' => 'color: {{VALUE}};',
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
    protected function render()
    {
        $settings     = $this->get_settings_for_display();
        $testimonials = $this->get_testimonials($settings);

        if (empty($testimonials)) {
            $this->render_chat_empty_state($settings);
            return;
        }

        $wrapper_classes = array(
            'pg-testimonials-chat',
            'pg-testimonials-chat--align-' . $this->get_alignment_mode($settings),
            'pg-testimonials-chat--bubble-' . $this->get_bubble_style($settings),
            'pg-testimonials-chat--sender-' . $this->get_sender_type_setting($settings),
        );

        echo '<div class="' . esc_attr(implode(' ', $wrapper_classes)) . '">';

        foreach ($testimonials as $index => $testimonial) {
            $this->render_chat_message($testimonial, $index, $settings);
        }

        echo '</div>';
    }

    /**
     * Render the empty state.
     *
     * @return void
     */
    protected function render_chat_empty_state($settings = array())
    {
        echo '<div class="pg-testimonials-chat pg-testimonials-chat--empty"><p class="pg-testimonials__empty">' . esc_html($this->get_empty_state_message($settings)) . '</p></div>';
    }

    /**
     * Build an empty-state message for the current widget settings.
     *
     * @param array $settings Widget settings.
     * @return string
     */
    protected function get_empty_state_message($settings)
    {
        $source = isset($settings['source']) ? $settings['source'] : 'latest';

        if ('manual' !== $source) {
            return __('No testimonials found.', 'palgoals-testimonials');
        }

        $manual_ids = $this->get_manual_include_ids($settings);

        if (empty($manual_ids)) {
            return __('Add one or more testimonial IDs in Manual source mode to display chat messages.', 'palgoals-testimonials');
        }

        if (1 === count($manual_ids)) {
            $post_id = $manual_ids[0];
            $post    = get_post($post_id);

            if (! $post || \Palgoals_Testimonials_CPT::POST_TYPE !== $post->post_type) {
                return __('The selected testimonial could not be found.', 'palgoals-testimonials');
            }

            if ('publish' !== $post->post_status) {
                return __('The selected testimonial is not published.', 'palgoals-testimonials');
            }

            $status = get_post_meta($post_id, \Palgoals_Testimonials_CPT::META_STATUS, true);

            if ('' !== $status && \Palgoals_Testimonials_CPT::STATUS_ACTIVE !== $status) {
                return __('The selected testimonial is inactive and cannot be displayed.', 'palgoals-testimonials');
            }
        }

        return __('The selected testimonials are unavailable, unpublished, or inactive.', 'palgoals-testimonials');
    }

    /**
     * Render a single chat message.
     *
     * @param array $testimonial Prepared testimonial payload.
     * @param int   $index       Loop index.
     * @param array $settings    Widget settings.
     * @return void
     */
    protected function render_chat_message($testimonial, $index, $settings)
    {
        $side           = $this->get_message_side($index, $settings);
        $sender         = $this->get_message_sender($index, $settings);
        $display_name   = $this->get_display_name($testimonial);
        $show_avatar    = ! empty($settings['show_avatar']);
        $show_name      = ! empty($settings['show_name']);
        $show_timestamp = ! empty($settings['show_timestamp']);
        $timestamp      = $show_timestamp ? $this->get_message_timestamp($testimonial) : array();
        $meta_visible   = ($show_name && '' !== $display_name) || (! empty($timestamp['label']));
        $message_class  = array(
            'pg-chat-message',
            'pg-chat-message--' . $side,
            'pg-chat-message--sender-' . $sender,
            $show_avatar ? 'pg-chat-message--has-avatar' : 'pg-chat-message--avatar-hidden',
        );

        echo '<div class="' . esc_attr(implode(' ', $message_class)) . '">';

        if ($show_avatar) {
            $this->render_chat_avatar($testimonial, $display_name);
        }

        echo '<div class="pg-chat-bubble">';

        if ($meta_visible) {
            echo '<div class="pg-chat-meta">';

            if ($show_name && '' !== $display_name) {
                echo '<div class="pg-chat-name">' . esc_html($display_name) . '</div>';
            }

            if (! empty($timestamp['label']) && ! empty($timestamp['datetime'])) {
                echo '<time class="pg-chat-timestamp" datetime="' . esc_attr($timestamp['datetime']) . '">' . esc_html($timestamp['label']) . '</time>';
            }

            echo '</div>';
        }

        echo '<div class="pg-chat-content">' . wp_kses_post(wpautop($testimonial['content'])) . '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render the avatar markup.
     *
     * @param array  $testimonial Prepared testimonial payload.
     * @param string $display_name Display name.
     * @return void
     */
    protected function render_chat_avatar($testimonial, $display_name)
    {
        echo '<div class="pg-chat-avatar">';

        if (! empty($testimonial['photo_id'])) {
            echo wp_get_attachment_image(
                $testimonial['photo_id'],
                'thumbnail',
                false,
                array(
                    'class'   => 'pg-chat-avatar__image',
                    'loading' => 'lazy',
                    'alt'     => esc_attr($display_name),
                )
            );
        } else {
            echo '<span class="pg-chat-avatar__fallback" aria-hidden="true">' . esc_html($testimonial['initials']) . '</span>';
        }

        echo '</div>';
    }

    /**
     * Resolve a visual message side.
     *
     * @param int   $index    Loop index.
     * @param array $settings Widget settings.
     * @return string
     */
    protected function get_message_side($index, $settings)
    {
        $alignment_mode = $this->get_alignment_mode($settings);

        if ('all-left' === $alignment_mode) {
            return 'left';
        }

        if ('all-right' === $alignment_mode) {
            return 'right';
        }

        return 0 === ($index % 2) ? 'left' : 'right';
    }

    /**
     * Resolve a sender class.
     *
     * @param int   $index    Loop index.
     * @param array $settings Widget settings.
     * @return string
     */
    protected function get_message_sender($index, $settings)
    {
        $sender_type = $this->get_sender_type_setting($settings);

        if ('auto' !== $sender_type) {
            return $sender_type;
        }

        return 0 === ($index % 2) ? 'customer' : 'brand';
    }

    /**
     * Parse manual include IDs with backward-compatible support for strings or arrays.
     *
     * @param array $settings Widget settings.
     * @return array
     */
    protected function get_manual_include_ids($settings)
    {
        $value = isset($settings['include_ids']) ? $settings['include_ids'] : array();

        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value);
        }

        $ids = array_map('absint', (array) $value);
        $ids = array_filter($ids);

        return array_values(array_unique($ids));
    }

    /**
     * Resolve the alignment mode.
     *
     * @param array $settings Widget settings.
     * @return string
     */
    protected function get_alignment_mode($settings)
    {
        $value = isset($settings['bubble_alignment_mode']) ? $settings['bubble_alignment_mode'] : 'alternating';

        return in_array($value, array('alternating', 'all-left', 'all-right'), true) ? $value : 'alternating';
    }

    /**
     * Resolve the sender type setting.
     *
     * @param array $settings Widget settings.
     * @return string
     */
    protected function get_sender_type_setting($settings)
    {
        $value = isset($settings['sender_type']) ? $settings['sender_type'] : 'auto';

        return in_array($value, array('auto', 'customer', 'brand'), true) ? $value : 'auto';
    }

    /**
     * Resolve the bubble style setting.
     *
     * @param array $settings Widget settings.
     * @return string
     */
    protected function get_bubble_style($settings)
    {
        $value = isset($settings['bubble_style']) ? $settings['bubble_style'] : 'whatsapp';

        return in_array($value, array('whatsapp', 'minimal', 'card'), true) ? $value : 'whatsapp';
    }

    /**
     * Resolve a display name.
     *
     * @param array $testimonial Prepared testimonial payload.
     * @return string
     */
    protected function get_display_name($testimonial)
    {
        if (! empty($testimonial['name'])) {
            return (string) $testimonial['name'];
        }

        return __('Customer', 'palgoals-testimonials');
    }

    /**
     * Build timestamp data.
     *
     * @param array $testimonial Prepared testimonial payload.
     * @return array
     */
    protected function get_message_timestamp($testimonial)
    {
        $post_id = isset($testimonial['id']) ? absint($testimonial['id']) : 0;

        if ($post_id <= 0) {
            return array();
        }

        return array(
            'label'    => get_the_date(get_option('date_format'), $post_id),
            'datetime' => get_post_time('c', false, $post_id),
        );
    }
}
