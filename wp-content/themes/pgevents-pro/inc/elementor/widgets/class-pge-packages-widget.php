<?php
defined('ABSPATH') || exit;

if (!class_exists('\Elementor\Widget_Base')) {
    return;
}

class PGE_Packages_Widget extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'pge_packages';
    }

    public function get_title()
    {
        return __('PGE Packages', 'pgevents');
    }

    public function get_icon()
    {
        return 'eicon-price-table';
    }

    public function get_categories()
    {
        return ['general'];
    }

    protected function register_controls()
    {
        $this->start_controls_section('content', ['label' => __('Content', 'pgevents')]);
        $this->add_control('show_heading', [
            'label' => __('Show Heading', 'pgevents'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);
        $this->add_control('heading', [
            'label' => __('Heading', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'الباقات',
            'condition' => ['show_heading' => 'yes'],
        ]);
        $this->add_control('subtitle', [
            'label' => __('Subheading', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'اختر الباقة المناسبة لك',
            'condition' => ['show_heading' => 'yes'],
        ]);
        $this->add_control('featured_plan', [
            'label' => __('Featured Plan', 'pgevents'),
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => 'plan_2',
            'options' => [
                'plan_1' => 'Plan 1',
                'plan_2' => 'Plan 2',
                'plan_3' => 'Plan 3',
                'plan_4' => 'Plan 4',
            ],
        ]);
        $this->add_control('featured_badge', [
            'label' => __('Featured Badge', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'الأكثر طلبا',
        ]);
        $this->add_control('currency', [
            'label' => __('Currency', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'ريال',
        ]);
        $this->add_control('button_text', [
            'label' => __('Button Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'اختر هذه الباقة',
        ]);
        $this->add_control('max_features', [
            'label' => __('Max Features', 'pgevents'),
            'type' => \Elementor\Controls_Manager::NUMBER,
            'default' => 7,
            'min' => 3,
            'max' => 12,
        ]);
        $this->add_control('fallback_url', [
            'label' => __('Fallback URL', 'pgevents'),
            'type' => \Elementor\Controls_Manager::URL,
            'default' => ['url' => home_url('/packages/')],
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style_cards', [
            'label' => __('Cards', 'pgevents'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('grid_gap', [
            'label' => __('Grid Gap', 'pgevents'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'range' => ['px' => ['min' => 8, 'max' => 48]],
            'default' => ['size' => 24],
            'selectors' => ['{{WRAPPER}} .pge-pkg-grid' => 'gap: {{SIZE}}px;'],
        ]);
        $this->add_control('card_bg', [
            'label' => __('Card Background', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-pkg-card' => 'background: {{VALUE}};'],
        ]);
        $this->add_control('card_border', [
            'label' => __('Card Border', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-pkg-card' => 'border-color: {{VALUE}};'],
        ]);
        $this->add_control('featured_border', [
            'label' => __('Featured Border', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-pkg-card.is-featured' => 'border-color: {{VALUE}};'],
        ]);
        $this->add_control('card_radius', [
            'label' => __('Card Radius', 'pgevents'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'range' => ['px' => ['min' => 0, 'max' => 40]],
            'default' => ['size' => 22],
            'selectors' => ['{{WRAPPER}} .pge-pkg-card' => 'border-radius: {{SIZE}}px;'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
            'name' => 'card_shadow',
            'selector' => '{{WRAPPER}} .pge-pkg-card',
        ]);
        $this->add_control('title_color', [
            'label' => __('Title Color', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-pkg-title' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('price_color', [
            'label' => __('Price Color', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-pkg-price-value' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('feature_color', [
            'label' => __('Feature Color', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-pkg-feature-text' => 'color: {{VALUE}};'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'title_typo',
            'selector' => '{{WRAPPER}} .pge-pkg-title',
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'price_typo',
            'selector' => '{{WRAPPER}} .pge-pkg-price-value',
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'feature_typo',
            'selector' => '{{WRAPPER}} .pge-pkg-feature-text',
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style_button', [
            'label' => __('Buttons & Badge', 'pgevents'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('button_bg', [
            'label' => __('Button Background', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-pkg-btn' => 'background: {{VALUE}};'],
        ]);
        $this->add_control('button_text_color', [
            'label' => __('Button Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-pkg-btn' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('button_border', [
            'label' => __('Button Border', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-pkg-btn' => 'border-color: {{VALUE}};'],
        ]);
        $this->add_control('featured_btn_start', [
            'label' => __('Featured Button Start', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'default' => '#cf4f69',
            'selectors' => ['{{WRAPPER}} .pge-packages' => '--pge-fbtn-start: {{VALUE}};'],
        ]);
        $this->add_control('featured_btn_end', [
            'label' => __('Featured Button End', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'default' => '#d6a03f',
            'selectors' => ['{{WRAPPER}} .pge-packages' => '--pge-fbtn-end: {{VALUE}};'],
        ]);
        $this->add_control('badge_start', [
            'label' => __('Badge Start', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'default' => '#cf4f69',
            'selectors' => ['{{WRAPPER}} .pge-packages' => '--pge-badge-start: {{VALUE}};'],
        ]);
        $this->add_control('badge_end', [
            'label' => __('Badge End', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'default' => '#d6a03f',
            'selectors' => ['{{WRAPPER}} .pge-packages' => '--pge-badge-end: {{VALUE}};'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'button_typo',
            'selector' => '{{WRAPPER}} .pge-pkg-btn',
        ]);
        $this->end_controls_section();
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $defaults = (class_exists('PGE_Packages') && method_exists('PGE_Packages', 'get_default_plans'))
            ? (array) PGE_Packages::get_default_plans()
            : [];
        $stored = get_option('mon_packages_settings', []);
        if (!is_array($stored)) $stored = [];

        $plans = [];
        foreach (['plan_1', 'plan_2', 'plan_3', 'plan_4'] as $key) {
            $plans[$key] = array_merge((array) ($defaults[$key] ?? []), (array) ($stored[$key] ?? []));
        }

        $flag_label = [
            'header_img' => 'صورة هيدر كبيرة',
            'event_barcode' => 'باركود للمناسبة',
            'google_map' => 'موقع قوقل',
            'guest_photos' => 'رفع صور من الضيوف',
            'guest_video' => 'روابط يوتيوب',
            'public_chat' => 'دردشة عامة',
            'private_chat' => 'دردشة خاصة',
            'countdown' => 'عداد تنازلي',
            'stc_pay' => 'هدايا STC Pay',
        ];

        $is_on = static function ($v) {
            if (is_bool($v)) return $v;
            if (is_numeric($v)) return ((int) $v) === 1;
            return in_array(strtolower(trim((string) $v)), ['1', 'on', 'yes', 'true'], true);
        };

        $make_features = function ($plan) use ($flag_label, $is_on, $settings) {
            $items = [];
            $events = (int) ($plan['events_count'] ?? 0);
            $guests = (int) ($plan['guest_limit'] ?? 0);
            $photos = (int) ($plan['host_photos'] ?? 0);
            if ($events > 0) $items[] = ($events === 1) ? 'مناسبة واحدة' : ($events . ' مناسبات');
            if ($guests > 0) $items[] = $guests . ' مدعو';
            if ($photos > 0) $items[] = 'رفع ' . $photos . ' صورة';
            foreach ($flag_label as $k => $label) {
                if ($is_on($plan[$k] ?? 0)) $items[] = $label;
            }
            $max = max(3, (int) ($settings['max_features'] ?? 7));
            return array_slice(array_values(array_unique($items)), 0, $max);
        };

        $featured = (string) ($settings['featured_plan'] ?? 'plan_2');
        $fallback_url = !empty($settings['fallback_url']['url']) ? $settings['fallback_url']['url'] : home_url('/packages/');
        $btn_text = trim((string) ($settings['button_text'] ?? '')) ?: 'اختر هذه الباقة';
        $currency = trim((string) ($settings['currency'] ?? '')) ?: 'ريال';
        $badge = trim((string) ($settings['featured_badge'] ?? '')) ?: 'الأكثر طلبا';

        echo '<div class="pge-packages" dir="rtl">';
        if (($settings['show_heading'] ?? '') === 'yes') {
            echo '<div class="pge-pkg-head">';
            echo '<h2 class="pge-pkg-head-title">' . esc_html((string) ($settings['heading'] ?? '')) . '</h2>';
            echo '<p class="pge-pkg-head-sub">' . esc_html((string) ($settings['subtitle'] ?? '')) . '</p>';
            echo '</div>';
        }
        echo '<div class="pge-pkg-grid">';
        foreach ($plans as $key => $plan) {
            $name = (string) ($plan['name'] ?? $key);
            $price = (string) ($plan['price'] ?? '0');
            $url = (!empty($plan['salla_url']) && $plan['salla_url'] !== '#') ? $plan['salla_url'] : $fallback_url;
            $is_featured = ($key === $featured);
            $features = $make_features($plan);
            echo '<article class="pge-pkg-card' . ($is_featured ? ' is-featured' : '') . '">';
            if ($is_featured) echo '<span class="pge-pkg-badge">' . esc_html($badge) . '</span>';
            echo '<h3 class="pge-pkg-title">' . esc_html($name) . '</h3>';
            echo '<div class="pge-pkg-price"><span class="pge-pkg-price-value">' . esc_html($price) . '</span><span class="pge-pkg-price-currency">' . esc_html($currency) . '</span></div>';
            echo '<ul class="pge-pkg-features">';
            foreach ($features as $text) {
                echo '<li class="pge-pkg-feature"><span class="pge-pkg-feature-text">' . esc_html($text) . '</span><span class="pge-pkg-check">✓</span></li>';
            }
            echo '</ul>';
            echo '<a class="pge-pkg-btn' . ($is_featured ? ' is-featured-btn' : '') . '" href="' . esc_url($url) . '">' . esc_html($btn_text) . '</a>';
            echo '</article>';
        }
        echo '</div></div>';
        ?>
        <style>
            .pge-packages{--pge-badge-start:#cf4f69;--pge-badge-end:#d6a03f;--pge-fbtn-start:#cf4f69;--pge-fbtn-end:#d6a03f}
            .pge-pkg-head{text-align:center;margin-bottom:20px}.pge-pkg-head-title{margin:0 0 6px;font-size:2rem;font-weight:800}.pge-pkg-head-sub{margin:0;color:#6b7280}
            .pge-pkg-grid{display:grid;grid-template-columns:1fr;gap:24px}@media(min-width:768px){.pge-pkg-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(min-width:1280px){.pge-pkg-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
            .pge-pkg-card{position:relative;display:flex;flex-direction:column;border:1px solid #e5e7eb;border-radius:22px;padding:30px 24px;background:#fff}.pge-pkg-card.is-featured{border-width:2px}
            .pge-pkg-badge{position:absolute;top:0;left:50%;transform:translate(-50%,-50%);padding:6px 16px;border-radius:999px;color:#fff;background:linear-gradient(90deg,var(--pge-badge-start),var(--pge-badge-end));font-size:.9rem;font-weight:700;white-space:nowrap}
            .pge-pkg-title{text-align:center;margin:0;font-size:2rem;font-weight:800;color:#1f2937}
            .pge-pkg-price{margin-top:16px;display:flex;align-items:baseline;justify-content:center;gap:8px;flex-direction:row-reverse}
            .pge-pkg-price-value{font-size:3rem;font-weight:800;line-height:1;color:#c66b4f}.pge-pkg-price-currency{font-size:1.4rem;color:#6b7280}
            .pge-pkg-features{list-style:none;margin:24px 0;padding:0;display:flex;flex-direction:column;gap:11px}
            .pge-pkg-feature{display:flex;align-items:center;justify-content:space-between;gap:12px}.pge-pkg-feature-text{color:#4b5563;line-height:1.5}
            .pge-pkg-check{width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#f5d9de;color:#cf4f69;font-size:13px;font-weight:700;flex:none}
            .pge-pkg-btn{margin-top:auto;display:block;text-align:center;text-decoration:none;border:1px solid #e5e7eb;border-radius:14px;background:#fff;color:#1f2937;padding:14px 16px;font-weight:700;transition:.2s}
            .pge-pkg-btn:hover{background:#f8fafc;border-color:#d1d5db}.pge-pkg-btn.is-featured-btn{border-color:transparent;color:#fff;background:linear-gradient(90deg,var(--pge-fbtn-start),var(--pge-fbtn-end))}
        </style>
        <?php
    }
}
