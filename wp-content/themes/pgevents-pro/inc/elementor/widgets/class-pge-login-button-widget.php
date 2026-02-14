<?php
defined('ABSPATH') || exit;

if (!class_exists('\Elementor\Widget_Base')) {
    return;
}

class PGE_Login_Button_Widget extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'pge_login_button';
    }

    public function get_title()
    {
        return __('PGE Login Button', 'pgevents');
    }

    public function get_icon()
    {
        return 'eicon-lock-user';
    }

    public function get_categories()
    {
        return ['general'];
    }

    protected function register_controls()
    {
        $this->start_controls_section(
            'section_content',
            [
                'label' => __('Content', 'pgevents'),
            ]
        );

        $this->add_control(
            'logged_out_text',
            [
                'label' => __('Logged-out Label', 'pgevents'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => 'تسجيل الدخول',
            ]
        );

        $this->add_control(
            'logged_in_text',
            [
                'label' => __('Logged-in Label', 'pgevents'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => 'لوحة التحكم',
            ]
        );

        $this->add_control(
            'logged_in_url',
            [
                'label' => __('Logged-in URL', 'pgevents'),
                'type' => \Elementor\Controls_Manager::URL,
                'default' => [
                    'url' => home_url('/dashboard/'),
                ],
                'placeholder' => home_url('/dashboard/'),
                'show_external' => true,
            ]
        );

        $this->add_control(
            'logged_out_redirect',
            [
                'label' => __('Redirect After Login', 'pgevents'),
                'type' => \Elementor\Controls_Manager::URL,
                'default' => [
                    'url' => home_url('/dashboard/'),
                ],
                'placeholder' => home_url('/dashboard/'),
                'show_external' => false,
            ]
        );

        $this->add_control(
            'full_width',
            [
                'label' => __('Full Width', 'pgevents'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'label_on' => __('Yes', 'pgevents'),
                'label_off' => __('No', 'pgevents'),
                'return_value' => 'yes',
                'default' => '',
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style',
            [
                'label' => __('Button', 'pgevents'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'text_color',
            [
                'label' => __('Text Color', 'pgevents'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .pge-login-widget-btn' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'bg_color',
            [
                'label' => __('Background Color', 'pgevents'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .pge-login-widget-btn' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'hover_text_color',
            [
                'label' => __('Hover Text Color', 'pgevents'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .pge-login-widget-btn:hover' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'hover_bg_color',
            [
                'label' => __('Hover Background', 'pgevents'),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .pge-login-widget-btn:hover' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'typography',
                'selector' => '{{WRAPPER}} .pge-login-widget-btn',
            ]
        );

        $this->add_responsive_control(
            'padding',
            [
                'label' => __('Padding', 'pgevents'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem'],
                'selectors' => [
                    '{{WRAPPER}} .pge-login-widget-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'border_radius',
            [
                'label' => __('Border Radius', 'pgevents'),
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%'],
                'selectors' => [
                    '{{WRAPPER}} .pge-login-widget-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $is_logged_in = is_user_logged_in();

        if ($is_logged_in) {
            $label = trim((string) ($settings['logged_in_text'] ?? ''));
            if ($label === '') {
                $label = 'لوحة التحكم';
            }

            $logged_in_url = $settings['logged_in_url'] ?? [];
            $url = !empty($logged_in_url['url']) ? $logged_in_url['url'] : home_url('/dashboard/');
            $is_external = !empty($logged_in_url['is_external']);
            $nofollow = !empty($logged_in_url['nofollow']);
        } else {
            $label = trim((string) ($settings['logged_out_text'] ?? ''));
            if ($label === '') {
                $label = 'تسجيل الدخول';
            }

            $redirect_url = !empty($settings['logged_out_redirect']['url']) ? $settings['logged_out_redirect']['url'] : home_url('/dashboard/');
            $url = wp_login_url($redirect_url);
            $is_external = false;
            $nofollow = false;
        }

        $classes = [
            'pge-login-widget-btn',
            'inline-flex',
            'items-center',
            'justify-center',
            'gap-2',
            'rounded-xl',
            'bg-slate-900',
            'px-4',
            'py-2',
            'text-sm',
            'font-semibold',
            'text-white',
            'transition',
            'hover:bg-slate-800',
        ];

        if (($settings['full_width'] ?? '') === 'yes') {
            $classes[] = 'w-full';
        }

        $this->add_render_attribute('button', 'class', implode(' ', $classes));
        $this->add_render_attribute('button', 'href', esc_url($url));

        if ($is_external) {
            $this->add_render_attribute('button', 'target', '_blank');
        }

        if ($nofollow) {
            $this->add_render_attribute('button', 'rel', 'nofollow');
        }

        echo '<a ' . $this->get_render_attribute_string('button') . '>';
        echo esc_html($label);
        echo '</a>';
    }
}
