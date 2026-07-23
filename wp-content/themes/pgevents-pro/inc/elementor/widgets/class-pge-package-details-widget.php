<?php
defined('ABSPATH') || exit;

if (!class_exists('\Elementor\Widget_Base')) {
    return;
}

class PGE_Package_Details_Widget extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'pge_package_details';
    }

    public function get_title()
    {
        return __('PGE Package Details', 'pgevents');
    }

    public function get_icon()
    {
        return 'eicon-price-table';
    }

    public function get_categories()
    {
        return ['general'];
    }

    public function get_keywords()
    {
        return ['package', 'details', 'pricing', 'event', 'pge'];
    }

    protected function register_controls()
    {
        $this->start_controls_section('content', [
            'label' => __('المحتوى', 'pgevents'),
        ]);
        $this->add_control('preview_plan_key', [
            'label' => __('مفتاح الباقة للمعاينة', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'halwa_classic',
            'description' => __('يُستخدم داخل محرر Elementor فقط عند عدم وجود ?plan في الرابط.', 'pgevents'),
        ]);
        $this->add_control('show_features', [
            'label' => __('عرض المزايا', 'pgevents'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);
        $this->add_control('show_tiers', [
            'label' => __('عرض مستويات المدعوين', 'pgevents'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'default' => 'yes',
        ]);
        $this->add_control('button_text', [
            'label' => __('نص زر المتابعة', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'متابعة',
        ]);
        $this->add_control('empty_title', [
            'label' => __('عنوان عدم توفر الباقة', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'الباقة غير متاحة',
        ]);
        $this->add_control('empty_message', [
            'label' => __('رسالة عدم توفر الباقة', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXTAREA,
            'default' => 'تعذر العثور على الباقة المطلوبة أو أنها غير متاحة حاليًا.',
        ]);
        $this->end_controls_section();

        $this->start_controls_section('colors', [
            'label' => __('الألوان', 'pgevents'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('primary_color', [
            'label' => __('اللون الرئيسي', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .pge-package-details' => '--pge-package-details-primary: {{VALUE}};',
            ],
        ]);
        $this->add_control('text_color', [
            'label' => __('لون النص', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .pge-package-details, {{WRAPPER}} .pge-package-details-empty' => '--pge-package-details-text: {{VALUE}};',
            ],
        ]);
        $this->add_control('muted_color', [
            'label' => __('لون النص الثانوي', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .pge-package-details, {{WRAPPER}} .pge-package-details-empty' => '--pge-package-details-muted: {{VALUE}};',
            ],
        ]);
        $this->add_control('border_color', [
            'label' => __('لون الحدود', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .pge-package-details, {{WRAPPER}} .pge-package-details-empty' => '--pge-package-details-border: {{VALUE}};',
            ],
        ]);
        $this->add_control('card_background', [
            'label' => __('خلفية البطاقة', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .pge-package-details, {{WRAPPER}} .pge-package-details-empty' => '--pge-package-details-background: {{VALUE}};',
            ],
        ]);
        $this->end_controls_section();
    }

    protected function is_elementor_preview()
    {
        if (!class_exists('\Elementor\Plugin') || empty(\Elementor\Plugin::$instance)) {
            return false;
        }

        $plugin = \Elementor\Plugin::$instance;
        $editing = isset($plugin->editor)
            && is_object($plugin->editor)
            && method_exists($plugin->editor, 'is_edit_mode')
            && $plugin->editor->is_edit_mode();
        $previewing = isset($plugin->preview)
            && is_object($plugin->preview)
            && method_exists($plugin->preview, 'is_preview_mode')
            && $plugin->preview->is_preview_mode();

        return $editing || $previewing;
    }

    private function get_requested_plan_key($settings)
    {
        $plan_key = '';
        if (isset($_GET['plan']) && !is_array($_GET['plan'])) {
            $plan_key = sanitize_key(wp_unslash($_GET['plan']));
        }
        if ($plan_key === '' && $this->is_elementor_preview()) {
            $plan_key = sanitize_key((string) ($settings['preview_plan_key'] ?? ''));
        }

        return $plan_key;
    }

    private function get_features($plan)
    {
        if (!isset($plan['features']) || !is_string($plan['features'])) {
            return [];
        }
        $decoded = json_decode($plan['features'], true);
        if (!is_array($decoded)) {
            return [];
        }

        $features = [];
        foreach ($decoded as $feature) {
            if (!is_string($feature)) {
                continue;
            }
            $feature = trim($feature);
            if ($feature !== '') {
                $features[] = $feature;
            }
        }
        return $features;
    }

    private function get_valid_tiers($plan_id)
    {
        try {
            $tiers = PGE_Catalog::get_plan_tiers($plan_id);
        } catch (\Throwable $exception) {
            return [];
        }
        if (!is_array($tiers)) {
            return [];
        }

        $valid = [];
        foreach ($tiers as $tier) {
            if (!is_array($tier) || ($tier['status'] ?? '') !== 'active') {
                continue;
            }
            $tier_id = absint($tier['id'] ?? 0);
            if ($tier_id < 1 || !array_key_exists('price', $tier) || !is_numeric($tier['price'])) {
                continue;
            }
            $salla_product_id = is_string($tier['salla_product_id'] ?? null)
                ? trim($tier['salla_product_id'])
                : '';
            $salla_url = $this->get_https_purchase_url($tier['salla_url'] ?? '');
            $valid[] = [
                'id' => $tier_id,
                'name' => is_string($tier['name'] ?? null) ? trim($tier['name']) : '',
                'guest_limit' => isset($tier['guest_limit']) && is_numeric($tier['guest_limit'])
                    ? (int) $tier['guest_limit']
                    : 0,
                'price' => (float) $tier['price'],
                'currency' => is_string($tier['currency'] ?? null) ? trim($tier['currency']) : '',
                'salla_product_id' => $salla_product_id,
                'salla_url' => $salla_url,
                'is_purchase_available' => $salla_product_id !== '' && $salla_url !== '',
            ];
        }
        return $valid;
    }

    private function get_https_purchase_url($url)
    {
        if (!is_string($url)) {
            return '';
        }

        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
        ) {
            return '';
        }

        $sanitized_url = esc_url_raw($url, ['https']);
        return $sanitized_url !== '' ? $sanitized_url : '';
    }

    private function format_price($price)
    {
        $price = (float) $price;
        if (floor($price) === $price) {
            return number_format_i18n($price, 0);
        }
        return rtrim(number_format_i18n($price, 2), '0');
    }

    private function format_currency_for_display($currency)
    {
        $currency = (string) $currency;

        if ($currency === 'SAR') {
            return 'ر.س';
        }

        return $currency;
    }

    private function render_styles()
    {
        ?>
        <style>
            .pge-package-details,
            .pge-package-details-empty {
                --pge-package-details-primary: #b65f49;
                --pge-package-details-text: #252525;
                --pge-package-details-muted: #6b7280;
                --pge-package-details-border: #e5e7eb;
                --pge-package-details-background: #fff;
                box-sizing: border-box;
                color: var(--pge-package-details-text);
            }
            .pge-package-details {
                margin-inline: auto;
                max-width: 1120px;
                width: 100%;
            }
            .pge-package-details *,
            .pge-package-details *::before,
            .pge-package-details *::after,
            .pge-package-details-empty *,
            .pge-package-details-empty *::before,
            .pge-package-details-empty *::after { box-sizing: inherit; }
            .pge-package-details-summary {
                background: var(--pge-package-details-background);
                border: 1px solid var(--pge-package-details-border);
                border-radius: 16px;
                margin-bottom: 20px;
                padding: 26px 28px;
            }
            .pge-package-details-title,
            .pge-package-details-section-title,
            .pge-package-details-empty-title {
                color: var(--pge-package-details-text);
                margin: 0;
            }
            .pge-package-details-title {
                font-size: clamp(28px, 4vw, 42px);
                font-weight: 700;
                line-height: 1.25;
                text-decoration: none;
            }
            .pge-package-details-starting {
                color: var(--pge-package-details-muted);
                margin-top: 14px;
                text-align: right;
            }
            .pge-package-details-starting-label,
            .pge-package-details-selected-label {
                display: block;
                font-size: 13px;
                margin-bottom: 4px;
            }
            .pge-package-details-starting-label {
                font-size: 14px;
                line-height: 1.5;
            }
            .pge-package-details-price-line,
            .pge-package-details-selected-amount {
                align-items: baseline;
                display: inline-flex;
                gap: 6px;
            }
            .pge-package-details-starting-value,
            .pge-package-details-selected-value {
                color: var(--pge-package-details-primary);
                font-size: 28px;
                font-weight: 700;
                line-height: 1.2;
            }
            .pge-package-details-currency {
                color: var(--pge-package-details-muted);
                font-size: 14px;
                margin-inline-start: 4px;
            }
            .pge-package-details-content {
                align-items: start;
                display: grid;
                gap: 20px;
                grid-template-columns: minmax(0, 1fr) minmax(300px, .78fr);
            }
            .pge-package-details-content.is-single { grid-template-columns: minmax(0, 1fr); }
            .pge-package-details-card,
            .pge-package-details-empty {
                background: var(--pge-package-details-background);
                border: 1px solid var(--pge-package-details-border);
                border-radius: 16px;
                padding: 24px;
            }
            .pge-package-details-section-title {
                font-size: 20px;
                margin-bottom: 18px;
            }
            .pge-package-details-features-list {
                display: grid;
                gap: 14px;
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .pge-package-details-feature {
                align-items: flex-start;
                display: flex;
                gap: 12px;
                line-height: 1.75;
            }
            .pge-package-details-feature-mark {
                color: var(--pge-package-details-primary);
                flex: 0 0 auto;
                font-weight: 700;
                line-height: 1.75;
            }
            .pge-package-details-tiers {
                border: 0;
                margin: 0 0 22px;
                min-width: 0;
                padding: 0;
            }
            .pge-package-details-tiers-list { display: grid; gap: 10px; }
            .pge-package-details-tier {
                min-width: 0;
            }
            .pge-package-details-tier-input {
                accent-color: var(--pge-package-details-primary);
                flex: 0 0 auto;
                height: 18px;
                margin: 0;
                width: 18px;
            }
            .pge-package-details-tier-label {
                align-items: center;
                background: var(--pge-package-details-background);
                border: 1px solid var(--pge-package-details-border);
                border-radius: 12px;
                cursor: pointer;
                display: grid;
                gap: 12px;
                grid-template-columns: auto minmax(0, 1fr);
                padding: 15px 16px;
                width: 100%;
            }
            .pge-package-details-tier-label:has(.pge-package-details-tier-input:checked) {
                background: color-mix(in srgb, var(--pge-package-details-primary) 7%, var(--pge-package-details-background));
                border-color: var(--pge-package-details-primary);
                box-shadow: inset 0 0 0 1px var(--pge-package-details-primary);
            }
            .pge-package-details-tier-label:has(.pge-package-details-tier-input:focus-visible) {
                outline: 2px solid var(--pge-package-details-primary);
                outline-offset: 2px;
            }
            .pge-package-details-tier-label.is-unavailable { cursor: not-allowed; }
            .pge-package-details-tier-content {
                align-items: center;
                display: flex;
                gap: 16px;
                justify-content: space-between;
                min-width: 0;
            }
            .pge-package-details-tier-name { display: block; font-weight: 700; }
            .pge-package-details-tier-guests {
                color: var(--pge-package-details-muted);
                display: block;
                font-size: 13px;
                margin-top: 3px;
            }
            .pge-package-details-tier-unavailable {
                color: var(--pge-package-details-muted);
                display: block;
                font-size: 12px;
                line-height: 1.6;
                margin-top: 5px;
            }
            .pge-package-details-tier-price {
                align-items: baseline;
                color: var(--pge-package-details-primary);
                display: inline-flex;
                flex: 0 0 auto;
                gap: 5px;
                font-weight: 700;
            }
            .pge-package-details-selected-price {
                border-top: 1px solid var(--pge-package-details-border);
                margin-bottom: 18px;
                padding-top: 18px;
            }
            .pge-package-details-selected-label {
                color: var(--pge-package-details-muted);
                font-size: 14px;
                font-weight: 600;
            }
            .pge-package-details-selected-value {
                font-size: 34px;
            }
            .pge-package-details-button {
                align-items: center;
                background: var(--pge-package-details-primary);
                border: 1px solid var(--pge-package-details-primary);
                border-radius: 10px;
                color: #fff;
                display: flex;
                justify-content: center;
                font-weight: 700;
                min-height: 52px;
                padding: 10px 18px;
                text-align: center;
                text-decoration: none;
                width: 100%;
            }
            .pge-package-details-button:not(.is-disabled):hover { color: #fff; opacity: .9; }
            .pge-package-details-button:focus-visible {
                color: #fff;
                outline: 3px solid color-mix(in srgb, var(--pge-package-details-primary) 35%, transparent);
                outline-offset: 3px;
            }
            .pge-package-details-button.is-disabled {
                cursor: not-allowed;
                opacity: .55;
                pointer-events: none;
            }
            .pge-package-details-purchase-unavailable {
                color: var(--pge-package-details-muted);
                font-size: 13px;
                line-height: 1.6;
                margin: 0 0 10px;
                text-align: center;
            }
            .pge-package-details-empty { text-align: center; }
            .pge-package-details-empty-title { font-size: 22px; margin-bottom: 8px; }
            .pge-package-details-empty-message {
                color: var(--pge-package-details-muted);
                margin: 0;
            }
            @media (max-width: 767px) {
                .pge-package-details-summary {
                    margin-bottom: 16px;
                    padding: 20px 18px;
                }
                .pge-package-details-title {
                    font-size: clamp(26px, 9vw, 34px);
                }
                .pge-package-details-starting { margin-top: 10px; }
                .pge-package-details-content { grid-template-columns: minmax(0, 1fr); }
                .pge-package-details-card,
                .pge-package-details-empty { padding: 18px; }
                .pge-package-details-tier-label {
                    padding: 14px;
                }
                .pge-package-details-tier-content {
                    gap: 10px;
                }
                .pge-package-details-selected-value {
                    font-size: 31px;
                }
            }
            @media (max-width: 420px) {
                .pge-package-details-tier-content {
                    align-items: flex-start;
                    flex-direction: column;
                    gap: 6px;
                }
            }
        </style>
        <?php
    }

    private function render_empty_state($settings)
    {
        ?>
        <div class="pge-package-details-empty" dir="rtl">
            <h2 class="pge-package-details-empty-title"><?php echo esc_html((string) ($settings['empty_title'] ?? '')); ?></h2>
            <p class="pge-package-details-empty-message"><?php echo esc_html((string) ($settings['empty_message'] ?? '')); ?></p>
        </div>
        <?php
        $this->render_styles();
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $plan_key = $this->get_requested_plan_key($settings);

        if ($plan_key === '' || !class_exists('PGE_Catalog')) {
            $this->render_empty_state($settings);
            return;
        }

        try {
            $plan = PGE_Catalog::get_plan_by_key($plan_key);
        } catch (\Throwable $exception) {
            $plan = null;
        }
        if (!is_array($plan) || ($plan['status'] ?? '') !== 'active') {
            $this->render_empty_state($settings);
            return;
        }

        $plan_id = absint($plan['id'] ?? 0);
        if ($plan_id < 1) {
            $this->render_empty_state($settings);
            return;
        }

        $features = $this->get_features($plan);
        $tiers = $this->get_valid_tiers($plan_id);
        $show_features = ($settings['show_features'] ?? '') === 'yes' && $features !== [];
        $show_tiers = ($settings['show_tiers'] ?? '') === 'yes' && $tiers !== [];
        $default_tier = null;
        foreach ($tiers as $tier) {
            if ($tier['is_purchase_available']) {
                $default_tier = $tier;
                break;
            }
        }
        $minimum_tier = null;
        foreach ($tiers as $tier) {
            if ($minimum_tier === null || $tier['price'] < $minimum_tier['price']) {
                $minimum_tier = $tier;
            }
        }

        $instance_id = sanitize_html_class((string) $this->get_id());
        if ($instance_id === '') {
            $instance_id = 'instance-' . spl_object_id($this);
        }
        $wrapper_id = 'pge-package-details-' . $instance_id;
        $radio_name = 'pge_package_tier_' . $instance_id;
        $content_class = 'pge-package-details-content' . (!$show_features ? ' is-single' : '');
        ?>
        <section
            id="<?php echo esc_attr($wrapper_id); ?>"
            class="pge-package-details"
            dir="rtl"
            data-plan-id="<?php echo esc_attr($plan_id); ?>"
            data-plan-key="<?php echo esc_attr($plan_key); ?>"
        >
            <header class="pge-package-details-summary">
                <h2 class="pge-package-details-title"><?php echo esc_html((string) ($plan['name'] ?? '')); ?></h2>
                <div class="pge-package-details-starting">
                    <?php if ($minimum_tier !== null) : ?>
                        <span class="pge-package-details-starting-label"><?php echo esc_html__('تبدأ الباقة من', 'pgevents'); ?></span>
                        <span class="pge-package-details-price-line">
                            <span class="pge-package-details-starting-value"><?php echo esc_html($this->format_price($minimum_tier['price'])); ?></span>
                            <?php if ($minimum_tier['currency'] !== '') : ?>
                                <span class="pge-package-details-currency"><?php echo esc_html($this->format_currency_for_display($minimum_tier['currency'])); ?></span>
                            <?php endif; ?>
                        </span>
                    <?php else : ?>
                        <span class="pge-package-details-starting-value"><?php echo esc_html__('تواصل معنا', 'pgevents'); ?></span>
                    <?php endif; ?>
                </div>
            </header>

            <div class="<?php echo esc_attr($content_class); ?>">
                <?php if ($show_features) : ?>
                    <div class="pge-package-details-card pge-package-details-features">
                        <h3 class="pge-package-details-section-title"><?php echo esc_html__('مزايا الباقة', 'pgevents'); ?></h3>
                        <ul class="pge-package-details-features-list">
                            <?php foreach ($features as $feature) : ?>
                                <li class="pge-package-details-feature">
                                    <span class="pge-package-details-feature-mark" aria-hidden="true">✓</span>
                                    <span class="pge-package-details-feature-text"><?php echo esc_html($feature); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="pge-package-details-card pge-package-details-purchase">
                    <?php if ($show_tiers) : ?>
                        <fieldset class="pge-package-details-tiers">
                            <legend class="pge-package-details-section-title"><?php echo esc_html__('اختر عدد المدعوين', 'pgevents'); ?></legend>
                            <div class="pge-package-details-tiers-list">
                                <?php foreach ($tiers as $tier) : ?>
                                    <?php
                                    $radio_id = $wrapper_id . '-tier-' . $tier['id'];
                                    $is_purchase_available = $tier['is_purchase_available'];
                                    $is_default_tier = $default_tier !== null && $tier['id'] === $default_tier['id'];
                                    ?>
                                    <div class="pge-package-details-tier">
                                        <label
                                            class="pge-package-details-tier-label<?php echo $is_purchase_available ? '' : ' is-unavailable'; ?>"
                                            for="<?php echo esc_attr($radio_id); ?>"
                                            <?php if (!$is_purchase_available) : ?>aria-disabled="true"<?php endif; ?>
                                        >
                                            <input
                                                id="<?php echo esc_attr($radio_id); ?>"
                                                class="pge-package-details-tier-input"
                                                type="radio"
                                                name="<?php echo esc_attr($radio_name); ?>"
                                                value="<?php echo esc_attr($tier['id']); ?>"
                                                data-tier-id="<?php echo esc_attr($tier['id']); ?>"
                                                data-price-display="<?php echo esc_attr($this->format_price($tier['price'])); ?>"
                                                data-currency="<?php echo esc_attr($tier['currency']); ?>"
                                                data-currency-display="<?php echo esc_attr($this->format_currency_for_display($tier['currency'])); ?>"
                                                data-purchase-available="<?php echo $is_purchase_available ? '1' : '0'; ?>"
                                                data-salla-url="<?php echo esc_url($tier['salla_url'], ['https']); ?>"
                                                <?php checked($is_default_tier); ?>
                                                <?php disabled(!$is_purchase_available); ?>
                                            >
                                            <span class="pge-package-details-tier-content">
                                                <span>
                                                    <span class="pge-package-details-tier-name"><?php echo esc_html($tier['name']); ?></span>
                                                    <?php if ($tier['guest_limit'] > 0) : ?>
                                                        <span class="pge-package-details-tier-guests">
                                                            <?php echo esc_html(sprintf(__('حتى %s مدعو', 'pgevents'), number_format_i18n($tier['guest_limit']))); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!$is_purchase_available) : ?>
                                                        <span class="pge-package-details-tier-unavailable">
                                                            <?php echo esc_html__('غير متاح للشراء حاليًا', 'pgevents'); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="pge-package-details-tier-price">
                                                    <?php echo esc_html($this->format_price($tier['price'])); ?>
                                                    <?php if ($tier['currency'] !== '') : ?>
                                                        <span class="pge-package-details-currency"><?php echo esc_html($this->format_currency_for_display($tier['currency'])); ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endif; ?>

                    <div class="pge-package-details-selected-price" aria-live="polite">
                        <span class="pge-package-details-selected-label"><?php echo esc_html__('السعر المختار', 'pgevents'); ?></span>
                        <?php if ($default_tier !== null) : ?>
                            <span class="pge-package-details-selected-amount">
                                <span class="pge-package-details-selected-value" data-selected-price><?php echo esc_html($this->format_price($default_tier['price'])); ?></span>
                                <span class="pge-package-details-currency" data-selected-currency<?php echo $default_tier['currency'] === '' ? ' hidden' : ''; ?>><?php echo esc_html($this->format_currency_for_display($default_tier['currency'])); ?></span>
                            </span>
                        <?php else : ?>
                            <span class="pge-package-details-selected-value" data-selected-price>&mdash;</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($default_tier === null) : ?>
                        <p class="pge-package-details-purchase-unavailable" data-purchase-unavailable-message>
                            <?php echo esc_html__('لا توجد مستويات متاحة للشراء حاليًا.', 'pgevents'); ?>
                        </p>
                    <?php endif; ?>

                    <a
                        class="pge-package-details-button<?php echo $default_tier === null ? ' is-disabled' : ''; ?>"
                        <?php if ($default_tier !== null) : ?>href="<?php echo esc_url($default_tier['salla_url'], ['https']); ?>"<?php endif; ?>
                        role="button"
                        data-plan-id="<?php echo esc_attr($plan_id); ?>"
                        data-plan-key="<?php echo esc_attr($plan_key); ?>"
                        data-tier-id="<?php echo esc_attr($default_tier['id'] ?? ''); ?>"
                        <?php if ($default_tier === null) : ?>aria-disabled="true" tabindex="-1"<?php endif; ?>
                    ><?php echo esc_html((string) ($settings['button_text'] ?? '')); ?></a>
                </div>
            </div>
        </section>
        <?php $this->render_styles(); ?>
        <script>
            (function () {
                var wrapper = document.getElementById(<?php echo wp_json_encode($wrapper_id); ?>);
                if (!wrapper) {
                    return;
                }
                var button = wrapper.querySelector('.pge-package-details-button');
                var price = wrapper.querySelector('[data-selected-price]');
                var currency = wrapper.querySelector('[data-selected-currency]');
                var radios = wrapper.querySelectorAll('.pge-package-details-tier-input');

                function isValidHttpsUrl(value) {
                    if (!value) {
                        return false;
                    }
                    try {
                        return new URL(value).protocol === 'https:';
                    } catch (error) {
                        return false;
                    }
                }

                function updateButton(radio) {
                    if (!button) {
                        return;
                    }
                    var sallaUrl = radio ? radio.dataset.sallaUrl || '' : '';
                    var isAvailable = Boolean(
                        radio
                        && radio.dataset.purchaseAvailable === '1'
                        && isValidHttpsUrl(sallaUrl)
                    );

                    button.dataset.tierId = isAvailable ? radio.dataset.tierId || '' : '';
                    button.classList.toggle('is-disabled', !isAvailable);
                    if (isAvailable) {
                        button.setAttribute('href', sallaUrl);
                        button.removeAttribute('aria-disabled');
                        button.removeAttribute('tabindex');
                    } else {
                        button.removeAttribute('href');
                        button.setAttribute('aria-disabled', 'true');
                        button.setAttribute('tabindex', '-1');
                    }
                }

                if (button) {
                    button.addEventListener('click', function (event) {
                        if (button.getAttribute('aria-disabled') === 'true' || !button.hasAttribute('href')) {
                            event.preventDefault();
                        }
                    });
                }
                radios.forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        if (!radio.checked) {
                            return;
                        }
                        if (price) {
                            price.textContent = radio.dataset.priceDisplay || '';
                        }
                        if (currency) {
                            currency.textContent = radio.dataset.currencyDisplay || radio.dataset.currency || '';
                            currency.hidden = !radio.dataset.currency;
                        }
                        if (button) {
                            updateButton(radio);
                        }
                    });
                });

                var checkedRadio = wrapper.querySelector('.pge-package-details-tier-input:checked');
                if (checkedRadio) {
                    updateButton(checkedRadio);
                }
            }());
        </script>
        <?php
    }
}
