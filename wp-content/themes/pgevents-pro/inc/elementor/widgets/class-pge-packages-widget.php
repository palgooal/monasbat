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
        $this->add_control('data_source', [
            'label' => __('مصدر البيانات', 'pgevents'),
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => 'manual',
            'options' => [
                'manual'  => __('يدوي — النظام القديم', 'pgevents'),
                'catalog' => __('الكتالوج', 'pgevents'),
            ],
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
            'condition' => ['data_source' => 'manual'],
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
            'condition' => ['data_source' => 'manual'],
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
            'selectors' => ['{{WRAPPER}} .pge-pkg-grid' => 'gap: {{SIZE}}px; --pge-pkg-gap: {{SIZE}}px;'],
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

    /**
     * يبني مصفوفة الباقات الأربع الثابتة من النظام القديم (mon_packages_settings)
     * بلا أي تغيير في القيم أو المنطق — نفس الكود الذي كان مباشرة داخل render()
     * قبل إضافة وضع Catalog، منقولاً كما هو ضمن مصفوفة موحّدة الشكل
     * (key/name/price_text/currency/features/url/is_featured) ليشترك مع وضع
     * Catalog في حلقة الطباعة نفسها دون تكرار HTML.
     */
    private function build_manual_packages($settings)
    {
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
        $currency = trim((string) ($settings['currency'] ?? '')) ?: 'ريال';

        $packages = [];
        foreach ($plans as $key => $plan) {
            $packages[] = [
                'key'         => $key,
                'name'        => (string) ($plan['name'] ?? $key),
                'price_text'  => (string) ($plan['price'] ?? '0'),
                'currency'    => $currency,
                'features'    => $make_features($plan),
                'url'         => (!empty($plan['salla_url']) && $plan['salla_url'] !== '#') ? $plan['salla_url'] : $fallback_url,
                'is_featured' => ($key === $featured),
                'has_starting_price' => false,
            ];
        }

        return $packages;
    }

    /**
     * يبني مصفوفة الباقات من PGE_Catalog (الباقات النشطة فقط، بترتيب
     * get_plans() نفسه). تُعيد [] إن لم يوجد الكلاس أصلاً أو لم توجد أي باقة
     * نشطة — الاستدعاء في render() يُترجم [] إلى fallback فوري للوضع اليدوي
     * في نفس الطلب، دون لمس إعداد data_source المحفوظ في Elementor.
     */
    private function build_catalog_packages($settings)
    {
        if (!class_exists('PGE_Catalog')) {
            return [];
        }

        try {
            $plans = PGE_Catalog::get_plans();
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($plans)) {
            $plans = [];
        }

        $fallback_url = !empty($settings['fallback_url']['url'])
            ? esc_url_raw($settings['fallback_url']['url'])
            : '';
        if ($fallback_url === '') {
            $fallback_url = '#';
        }
        $max_features = max(3, (int) ($settings['max_features'] ?? 7));

        $packages = [];
        foreach ($plans as $plan) {
            if (!is_array($plan) || ($plan['status'] ?? '') !== 'active') {
                continue;
            }

            try {
                $tiers = PGE_Catalog::get_plan_tiers($plan['id'] ?? 0);
            } catch (\Throwable $e) {
                $tiers = [];
            }
            if (!is_array($tiers)) {
                $tiers = [];
            }

            $min_price = null;
            $min_currency = '';
            foreach ($tiers as $tier) {
                if (!is_array($tier) || ($tier['status'] ?? '') !== 'active') {
                    continue;
                }
                if (!isset($tier['price']) || !is_numeric($tier['price'])) {
                    continue;
                }
                $tier_price = (float) $tier['price'];
                if ($min_price === null || $tier_price < $min_price) {
                    $min_price = $tier_price;
                    $min_currency = trim((string) ($tier['currency'] ?? ''));
                }
            }

            if ($min_price === null) {
                $price_text = __('تواصل معنا', 'pgevents');
                $currency_text = '';
            } elseif (floor($min_price) === $min_price) {
                $price_text = number_format_i18n($min_price, 0);
                $currency_text = $min_currency;
            } else {
                $price_text = number_format_i18n($min_price, 2);
                if (substr($price_text, -1) === '0') {
                    $price_text = substr($price_text, 0, -1);
                }
                $currency_text = $min_currency;
            }

            $decoded = null;
            $features_raw = $plan['features'] ?? null;
            if (is_string($features_raw) && trim($features_raw) !== '') {
                $decoded = json_decode($features_raw, true);
            }
            $features = [];
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_string($item)) {
                        $features[] = $item;
                    }
                }
            }
            $features = array_slice($features, 0, $max_features);

            $plan_key = sanitize_key((string) ($plan['plan_key'] ?? ''));
            $details_url = $fallback_url;
            if ($plan_key !== '') {
                $details_url = add_query_arg(
                    'plan',
                    $plan_key,
                    home_url('/packages/')
                );
            }

            $packages[] = [
                'plan_key'    => $plan_key,
                'name'        => (string) ($plan['name'] ?? ''),
                'price_text'  => $price_text,
                'currency'    => $currency_text,
                'features'    => $features,
                'url'         => $details_url,
                'is_featured' => false,
                'has_starting_price' => ($min_price !== null),
            ];
        }

        return $packages;
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();

        $data_source = $settings['data_source'] ?? 'manual';
        if (!in_array($data_source, ['manual', 'catalog'], true)) {
            $data_source = 'manual';
        }

        $packages = null;
        $is_catalog = false;
        if ($data_source === 'catalog') {
            $packages = $this->build_catalog_packages($settings);
            $is_catalog = !empty($packages);
        }
        if (empty($packages)) {
            $packages = $this->build_manual_packages($settings);
        }

        $btn_text = trim((string) ($settings['button_text'] ?? '')) ?: 'اختر هذه الباقة';
        $display_btn_text = $is_catalog ? __('عرض التفاصيل', 'pgevents') : $btn_text;
        $badge = trim((string) ($settings['featured_badge'] ?? '')) ?: 'الأكثر طلبا';
        $center_catalog_cards = $is_catalog && count($packages) < 4;

        echo '<div class="pge-packages" dir="rtl">';
        if (($settings['show_heading'] ?? '') === 'yes') {
            echo '<div class="pge-pkg-head">';
            echo '<h2 class="pge-pkg-head-title">' . esc_html((string) ($settings['heading'] ?? '')) . '</h2>';
            echo '<p class="pge-pkg-head-sub">' . esc_html((string) ($settings['subtitle'] ?? '')) . '</p>';
            echo '</div>';
        }
        echo '<div class="pge-pkg-grid' . ($center_catalog_cards ? ' is-catalog-centered' : '') . '">';
        foreach ($packages as $pkg) {
            $is_featured = !empty($pkg['is_featured']);
            echo '<article class="pge-pkg-card' . ($is_featured ? ' is-featured' : '') . '">';
            if ($is_featured) echo '<span class="pge-pkg-badge">' . esc_html($badge) . '</span>';
            echo '<h3 class="pge-pkg-title">' . esc_html($pkg['name']) . '</h3>';
            $has_starting_price = !empty($pkg['has_starting_price']);
            echo '<div class="pge-pkg-price' . ($has_starting_price ? ' has-starting-price' : '') . '">';
            if ($has_starting_price) {
                echo '<span class="pge-pkg-price-prefix">' . esc_html__('ابتداءً من', 'pgevents') . '</span>';
            }
            echo '<span class="pge-pkg-price-value">' . esc_html($pkg['price_text']) . '</span>';
            if ((string) $pkg['currency'] !== '') {
                echo '<span class="pge-pkg-price-currency">' . esc_html($pkg['currency']) . '</span>';
            }
            echo '</div>';
            echo '<ul class="pge-pkg-features">';
            foreach ($pkg['features'] as $text) {
                echo '<li class="pge-pkg-feature"><span class="pge-pkg-feature-text">' . esc_html($text) . '</span><span class="pge-pkg-check">✓</span></li>';
            }
            echo '</ul>';
            echo '<a class="pge-pkg-btn' . ($is_featured ? ' is-featured-btn' : '') . '" href="' . esc_url($pkg['url']) . '">' . esc_html($display_btn_text) . '</a>';
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
            .pge-pkg-btn:hover{background:#f8fafc;border-color:#d1d5db}.pge-pkg-btn.is-featured-btn{border-color:transparent;color:#fff;background:linear-gradient(90deg,var(--pge-fbtn-start),var(--pge-fbtn-end))}<?php if ($is_catalog) echo '.pge-pkg-price.has-starting-price{flex-wrap:wrap}.pge-pkg-price-prefix{flex:0 0 100%;text-align:center;font-size:.9rem;color:#6b7280;line-height:1.5}.pge-pkg-grid.is-catalog-centered{display:flex;flex-wrap:wrap;justify-content:center}.pge-pkg-grid.is-catalog-centered .pge-pkg-card{flex:0 1 100%;min-width:0}@media(min-width:768px){.pge-pkg-grid.is-catalog-centered .pge-pkg-card{flex-basis:calc((100% - var(--pge-pkg-gap,24px))/2)}}@media(min-width:1280px){.pge-pkg-grid.is-catalog-centered .pge-pkg-card{flex-basis:calc((100% - var(--pge-pkg-gap,24px) - var(--pge-pkg-gap,24px) - var(--pge-pkg-gap,24px))/4)}}'; ?>

        </style>
        <?php
    }
}
