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

    /**
     * سكربت التفاعل (فتح/إغلاق القائمة، الوصولية، لوحة المفاتيح) — مسجَّل في functions.php
     * عبر wp_register_script() ضمن نفس نمط تحميل الأصول الحالي بالثيم (pgevents_enqueue_assets)،
     * ويُحمَّل عبر Elementor تلقائياً فقط عند وجود هذا الويدجت في الصفحة (لا تحميل عام غير ضروري).
     */
    public function get_script_depends()
    {
        return ['pge-login-button'];
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
        $full_width = (($settings['full_width'] ?? '') === 'yes');

        if ($is_logged_in) {
            $this->render_logged_in($settings, $full_width);
            return;
        }

        $this->render_logged_out($settings, $full_width);
    }

    /**
     * حالة تسجيل الدخول — بدون تغيير: رابط واحد "تسجيل الدخول" بنفس الأنماط الحالية
     * (خلفية بيضاء + حدّ ذهبي + نص ذهبي + ارتفاع 44px)، ونفس منطق wp_login_url() تماماً كما كان.
     */
    private function render_logged_out(array $settings, bool $full_width)
    {
        $label = trim((string) ($settings['logged_out_text'] ?? ''));
        if ($label === '') {
            $label = 'تسجيل الدخول';
        }

        $redirect_url = !empty($settings['logged_out_redirect']['url']) ? $settings['logged_out_redirect']['url'] : home_url('/dashboard/');
        $url = wp_login_url($redirect_url);

        $classes = [
            'pge-login-widget-btn',
            'inline-flex',
            'h-11',
            'items-center',
            'justify-center',
            'whitespace-nowrap',
            'rounded-2xl',
            'text-sm',
            'transition-colors',
            'duration-200',
            'border-[1.5px]',
            'border-gold',
            'bg-white',
            'px-5',
            'font-bold',
            'text-gold-text',
            'hover:bg-gold/[0.06]',
        ];

        if ($full_width) {
            $classes[] = 'w-full';
        }

        $this->add_render_attribute('button', 'class', implode(' ', $classes));
        $this->add_render_attribute('button', 'href', esc_url($url));

        echo '<a ' . $this->get_render_attribute_string('button') . '>';
        echo esc_html($label);
        echo '</a>';
    }

    /**
     * حالة تسجيل الدخول — قائمة حساب منسدلة (accessible dropdown) بدل رابط واحد.
     * المشغّل (trigger) زر حقيقي (لتوافق ARIA menu-button pattern)، والعناصر روابط <a> عادية
     * بدون أي تغيير على مسارات/منطق الدخول والخروج الحالي — فقط عرض/تفاعل.
     */
    private function render_logged_in(array $settings, bool $full_width)
    {
        // اسم المستخدم بأمان: display_name ثم user_login، وإلا "حسابي" — بدون افتراض أي حقل غير موجود
        $current_user = wp_get_current_user();
        $account_label = '';
        if ($current_user && $current_user->exists()) {
            $account_label = trim((string) $current_user->display_name);
            if ($account_label === '') {
                $account_label = trim((string) $current_user->user_login);
            }
        }
        if ($account_label === '') {
            $account_label = 'حسابي';
        }

        // رابط لوحة التحكم: نفس إعداد "Logged-in URL" الحالي في الويدجت (بدون مسار جديد)
        $logged_in_url = $settings['logged_in_url'] ?? [];
        $dashboard_url = !empty($logged_in_url['url']) ? $logged_in_url['url'] : home_url('/dashboard/');
        $dashboard_is_external = !empty($logged_in_url['is_external']);
        $dashboard_nofollow = !empty($logged_in_url['nofollow']);

        // تسمية عنصر "لوحة التحكم" في القائمة: نعيد استخدام إعداد "Logged-in Label" الحالي
        // (كان نص الرابط الوحيد سابقاً) بدل إهماله، افتراضياً "لوحة التحكم"
        $dashboard_label = trim((string) ($settings['logged_in_text'] ?? ''));
        if ($dashboard_label === '') {
            $dashboard_label = 'لوحة التحكم';
        }

        // لا يوجد مسار "ملف شخصي" مستقل مسجَّل في routing.php (استُبدل سابقاً بلوحة التحكم)
        // — لذلك يُحذف هذا العنصر بدل اختراع مسار غير موجود، بحسب المتطلبات.
        $has_profile_route = false;

        // تسجيل الخروج: توليد الرابط حصراً عبر wp_logout_url() (يحمل nonce تلقائياً)،
        // مع إعادة توجيه لصفحة الموقع الرئيسية بعد الخروج.
        $logout_url = wp_logout_url(home_url('/'));

        $trigger_id = wp_unique_id('pge-account-trigger-');
        $menu_id = wp_unique_id('pge-account-menu-');

        $trigger_classes = [
            'pge-login-widget-btn',
            'inline-flex',
            'h-11',
            'items-center',
            'justify-center',
            'gap-2',
            'whitespace-nowrap',
            'rounded-2xl',
            'bg-transparent',
            'px-3',
            'text-sm',
            'font-semibold',
            'text-foreground',
            'transition-colors',
            'duration-200',
            'hover:bg-primary/5',
            'hover:text-primary',
        ];
        if ($full_width) {
            $trigger_classes[] = 'w-full';
        }

        $wrapper_classes = ['pge-account-menu', 'relative', 'inline-block'];
        if ($full_width) {
            $wrapper_classes[] = 'block';
            $wrapper_classes[] = 'w-full';
        }

        $item_class = 'flex h-11 items-center gap-2.5 rounded-xl px-3 text-sm font-semibold text-foreground transition-colors duration-200 hover:bg-secondary/60 hover:text-primary';
        $logout_item_class = 'flex h-11 items-center gap-2.5 rounded-xl px-3 text-sm font-semibold text-destructive-text transition-colors duration-200 hover:bg-destructive/10';

        ?>
        <div class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>" data-pge-account-menu>
            <button
                type="button"
                id="<?php echo esc_attr($trigger_id); ?>"
                class="<?php echo esc_attr(implode(' ', $trigger_classes)); ?>"
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="<?php echo esc_attr($menu_id); ?>"
                data-pge-account-trigger
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4 shrink-0">
                    <circle cx="12" cy="8" r="3.2"></circle>
                    <path d="M4.5 19.2v-.4a7.5 7.5 0 0 1 7.5-7.5 7.5 7.5 0 0 1 7.5 7.5v.4"></path>
                </svg>
                <span class="max-w-[8rem] truncate"><?php echo esc_html($account_label); ?></span>
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pge-account-chevron h-4 w-4 shrink-0 transition-transform duration-200">
                    <path d="m6 9 6 6 6-6"></path>
                </svg>
            </button>

            <div
                id="<?php echo esc_attr($menu_id); ?>"
                class="pge-account-dropdown absolute end-0 top-full z-50 mt-2 hidden min-w-[200px] max-w-[calc(100vw-2rem)] rounded-2xl border border-border bg-white p-1.5 shadow-[0_20px_45px_-15px_rgba(45,25,20,0.25)]"
                role="menu"
                aria-labelledby="<?php echo esc_attr($trigger_id); ?>"
                data-pge-account-dropdown
            >
                <a
                    href="<?php echo esc_url($dashboard_url); ?>"
                    role="menuitem"
                    class="<?php echo esc_attr($item_class); ?>"
                    <?php echo $dashboard_is_external ? 'target="_blank"' : ''; ?>
                    <?php echo $dashboard_nofollow ? 'rel="nofollow"' : ''; ?>
                    data-pge-account-item
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4 shrink-0">
                        <rect x="3" y="5" width="18" height="16" rx="3"></rect>
                        <path d="M3 10h18M8 3v4M16 3v4"></path>
                    </svg>
                    <?php echo esc_html($dashboard_label); ?>
                </a>

                <?php if ($has_profile_route) : ?>
                    <?php /* عنصر "الملف الشخصي" — لا يُعرض حالياً لعدم وجود مسار مستقل مسجَّل */ ?>
                <?php endif; ?>

                <div class="my-1 h-px bg-border" role="separator"></div>

                <a
                    href="<?php echo esc_url($logout_url); ?>"
                    role="menuitem"
                    class="<?php echo esc_attr($logout_item_class); ?>"
                    data-pge-account-item
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4 shrink-0">
                        <path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3"></path>
                        <path d="M16 17l5-5-5-5"></path>
                        <path d="M21 12H9"></path>
                    </svg>
                    تسجيل الخروج
                </a>
            </div>
        </div>
        <?php
    }
}
