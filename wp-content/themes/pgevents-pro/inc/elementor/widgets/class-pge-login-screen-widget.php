<?php
defined('ABSPATH') || exit;

if (!class_exists('\\Elementor\\Widget_Base')) {
    return;
}

class PGE_Login_Screen_Widget extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'pge_login_screen';
    }

    public function get_title()
    {
        return __('PGE Login Screen', 'pgevents');
    }

    public function get_icon()
    {
        return 'eicon-form-horizontal';
    }

    public function get_categories()
    {
        return ['general'];
    }

    public function get_keywords()
    {
        return ['login', 'signin', 'auth', 'pge', 'تسجيل', 'دخول'];
    }

    protected function register_controls()
    {
        $this->start_controls_section('content', ['label' => __('Content', 'pgevents')]);

        $this->add_control('title_text', [
            'label' => __('Title', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'تسجيل الدخول',
        ]);
        $this->add_control('description_text', [
            'label' => __('Description', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXTAREA,
            'default' => 'أدخل بيانات حسابك للوصول إلى لوحة التحكم.',
        ]);
        $this->add_control('badge_text', [
            'label' => __('Badge', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'آمن ومشفر',
        ]);
        $this->add_control('login_label', [
            'label' => __('Login Label', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'اسم المستخدم أو البريد الإلكتروني',
        ]);
        $this->add_control('password_label', [
            'label' => __('Password Label', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'كلمة المرور',
        ]);
        $this->add_control('forgot_text', [
            'label' => __('Forgot Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'نسيت كلمة المرور؟',
        ]);
        $this->add_control('remember_text', [
            'label' => __('Remember Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'تذكرني',
        ]);
        $this->add_control('submit_text', [
            'label' => __('Submit Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'دخول إلى الحساب',
        ]);
        $this->add_control('show_password_text', [
            'label' => __('Show Password Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'إظهار',
        ]);
        $this->add_control('hide_password_text', [
            'label' => __('Hide Password Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'إخفاء',
        ]);
        $this->add_control('register_prompt', [
            'label' => __('Register Prompt', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'ليس لديك حساب؟',
        ]);
        $this->add_control('register_link_text', [
            'label' => __('Register Link Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'أنشئ حسابك الآن',
        ]);
        $this->add_control('logged_in_notice', [
            'label' => __('Logged-in Message', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'أنت مسجل دخول حاليا.',
        ]);
        $this->add_control('dashboard_button_text', [
            'label' => __('Dashboard Button Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'الذهاب إلى لوحة التحكم',
        ]);

        $this->add_control('login_url', [
            'label' => __('Login URL', 'pgevents'),
            'type' => \Elementor\Controls_Manager::URL,
            'default' => ['url' => home_url('/login/')],
        ]);
        $this->add_control('forgot_url', [
            'label' => __('Forgot URL', 'pgevents'),
            'type' => \Elementor\Controls_Manager::URL,
            'default' => ['url' => wp_lostpassword_url(home_url('/login/'))],
        ]);
        $this->add_control('register_url', [
            'label' => __('Register URL', 'pgevents'),
            'type' => \Elementor\Controls_Manager::URL,
            'default' => ['url' => wp_registration_url()],
        ]);
        $this->add_control('dashboard_url', [
            'label' => __('Dashboard URL', 'pgevents'),
            'type' => \Elementor\Controls_Manager::URL,
            'default' => ['url' => home_url('/dashboard/')],
        ]);
        $this->add_control('redirect_to', [
            'label' => __('Redirect After Login', 'pgevents'),
            'type' => \Elementor\Controls_Manager::URL,
            'default' => ['url' => home_url('/dashboard/')],
        ]);

        $this->end_controls_section();
        $this->start_controls_section('style_card', [
            'label' => __('Card', 'pgevents'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_responsive_control('card_max_width', [
            'label' => __('Max Width', 'pgevents'),
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['px', '%'],
            'range' => [
                'px' => ['min' => 320, 'max' => 1200],
                '%' => ['min' => 40, 'max' => 100],
            ],
            'selectors' => ['{{WRAPPER}} .pge-login-widget' => 'max-width: {{SIZE}}{{UNIT}};'],
        ]);
        $this->add_control('card_bg', [
            'label' => __('Background', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-widget' => 'background-color: {{VALUE}};'],
        ]);
        $this->add_control('card_border', [
            'label' => __('Border Color', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-widget' => 'border-color: {{VALUE}};'],
        ]);
        $this->add_responsive_control('card_padding', [
            'label' => __('Padding', 'pgevents'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em'],
            'selectors' => ['{{WRAPPER}} .pge-login-widget' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_responsive_control('card_radius', [
            'label' => __('Radius', 'pgevents'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors' => ['{{WRAPPER}} .pge-login-widget' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
            'name' => 'card_shadow',
            'selector' => '{{WRAPPER}} .pge-login-widget',
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style_text', [
            'label' => __('Text', 'pgevents'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('title_color', [
            'label' => __('Title Color', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-title' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('description_color', [
            'label' => __('Description Color', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-description' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('label_color', [
            'label' => __('Label Color', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-label' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('link_color', [
            'label' => __('Link Color', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-link' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('link_hover_color', [
            'label' => __('Link Hover', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-link:hover' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('badge_bg', [
            'label' => __('Badge Background', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-badge' => 'background-color: {{VALUE}};'],
        ]);
        $this->add_control('badge_text_color', [
            'label' => __('Badge Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-badge' => 'color: {{VALUE}}; border-color: {{VALUE}};'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'title_typo',
            'selector' => '{{WRAPPER}} .pge-login-title',
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'label_typo',
            'selector' => '{{WRAPPER}} .pge-login-label',
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style_fields', [
            'label' => __('Fields', 'pgevents'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('input_bg', [
            'label' => __('Input Background', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-input' => 'background-color: {{VALUE}};'],
        ]);
        $this->add_control('input_text_color', [
            'label' => __('Input Text', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-input' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('input_placeholder_color', [
            'label' => __('Placeholder', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-input::placeholder' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('input_border_color', [
            'label' => __('Input Border', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-input' => 'border-color: {{VALUE}};'],
        ]);
        $this->add_control('input_focus_border_color', [
            'label' => __('Input Focus Border', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-input:focus' => 'border-color: {{VALUE}};'],
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style_button', [
            'label' => __('Buttons', 'pgevents'),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('button_text_color', [
            'label' => __('Text Color', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-submit' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('button_bg_start', [
            'label' => __('Gradient Start', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-screen' => '--pge-login-btn-start: {{VALUE}};'],
        ]);
        $this->add_control('button_bg_end', [
            'label' => __('Gradient End', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-screen' => '--pge-login-btn-end: {{VALUE}};'],
        ]);
        $this->add_control('button_hover_start', [
            'label' => __('Hover Start', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-screen' => '--pge-login-btn-hover-start: {{VALUE}};'],
        ]);
        $this->add_control('button_hover_end', [
            'label' => __('Hover End', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-screen' => '--pge-login-btn-hover-end: {{VALUE}};'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'button_typo',
            'selector' => '{{WRAPPER}} .pge-login-submit',
        ]);
        $this->add_responsive_control('button_radius', [
            'label' => __('Button Radius', 'pgevents'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors' => ['{{WRAPPER}} .pge-login-submit' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_responsive_control('button_padding', [
            'label' => __('Button Padding', 'pgevents'),
            'type' => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em'],
            'selectors' => ['{{WRAPPER}} .pge-login-submit' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; height: auto;'],
        ]);
        $this->add_control('footer_box_bg', [
            'label' => __('Footer Box Background', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-footer-box' => 'background-color: {{VALUE}};'],
        ]);
        $this->add_control('footer_box_border', [
            'label' => __('Footer Box Border', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-footer-box' => 'border-color: {{VALUE}};'],
        ]);
        $this->add_control('footer_text_color', [
            'label' => __('Footer Text Color', 'pgevents'),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .pge-login-footer-text' => 'color: {{VALUE}};'],
        ]);
        $this->end_controls_section();
    }

    private function resolve_auth_login($login_value)
    {
        $auth_login = $login_value;
        if (strpos($login_value, '@') !== false) {
            return $auth_login;
        }

        $phone_candidate = preg_replace('/\\D+/', '', $login_value);
        if ($phone_candidate === '') {
            return $auth_login;
        }
        $auth_login = $phone_candidate;
        if (username_exists($auth_login)) {
            return $auth_login;
        }

        $users_by_phone = get_users([
            'number'     => 1,
            'fields'     => 'all',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => 'pge_phone', 'value' => $phone_candidate],
                ['key' => 'billing_phone', 'value' => $phone_candidate],
                ['key' => 'phone_number', 'value' => $phone_candidate],
            ],
        ]);

        if (!empty($users_by_phone) && $users_by_phone[0] instanceof WP_User) {
            return $users_by_phone[0]->user_login;
        }

        return $auth_login;
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $widget_id = (string) $this->get_id();
        $form_id = 'pge_login_form_' . $widget_id;
        $nonce_action = 'pge_login_widget_action_' . $widget_id;

        $default_redirect = !empty($settings['redirect_to']['url']) ? $settings['redirect_to']['url'] : home_url('/dashboard/');
        $requested_redirect = isset($_REQUEST['redirect_to']) ? wp_unslash($_REQUEST['redirect_to']) : '';
        $redirect_to = $requested_redirect ? wp_validate_redirect($requested_redirect, $default_redirect) : $default_redirect;

        $login_error = '';
        $login_notice = '';
        $login_value = '';

        if (isset($_GET['loggedout']) && sanitize_text_field(wp_unslash($_GET['loggedout'])) === 'true') {
            $login_notice = 'تم تسجيل الخروج بنجاح.';
        }

        if (isset($_GET['checkemail']) && sanitize_text_field(wp_unslash($_GET['checkemail'])) === 'registered') {
            $login_notice = 'تم إنشاء الحساب، راجع بريدك الإلكتروني للتفعيل إن لزم.';
        }

        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['pge_login_form_id'])
            && sanitize_text_field(wp_unslash($_POST['pge_login_form_id'])) === $form_id
        ) {
            $nonce = isset($_POST['pge_login_nonce']) ? sanitize_text_field(wp_unslash($_POST['pge_login_nonce'])) : '';
            $login_value = isset($_POST['log']) ? sanitize_text_field(wp_unslash($_POST['log'])) : '';
            $password = isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '';
            $remember = !empty($_POST['rememberme']);

            if (isset($_POST['redirect_to'])) {
                $posted_redirect = wp_unslash($_POST['redirect_to']);
                $redirect_to = $posted_redirect ? wp_validate_redirect($posted_redirect, $default_redirect) : $default_redirect;
            }

            if (!$nonce || !wp_verify_nonce($nonce, $nonce_action)) {
                $login_error = 'انتهت الجلسة، أعد المحاولة.';
            } elseif ($login_value === '' || $password === '') {
                $login_error = 'يرجى إدخال اسم المستخدم أو البريد وكلمة المرور.';
            } else {
                $auth_login = $this->resolve_auth_login($login_value);
                $user = wp_signon([
                    'user_login'    => $auth_login,
                    'user_password' => $password,
                    'remember'      => $remember,
                ], is_ssl());

                if (is_wp_error($user)) {
                    $error_code = $user->get_error_code();
                    if (in_array($error_code, ['invalid_username', 'incorrect_password', 'invalid_email'], true)) {
                        $login_error = 'بيانات الدخول غير صحيحة.';
                    } else {
                        $login_error = 'تعذر تسجيل الدخول. حاول مرة أخرى.';
                    }
                } else {
                    wp_safe_redirect($redirect_to);
                    exit;
                }
            }
        }

        $login_action_url = !empty($settings['login_url']['url']) ? $settings['login_url']['url'] : home_url('/login/');
        $forgot_url = !empty($settings['forgot_url']['url']) ? $settings['forgot_url']['url'] : wp_lostpassword_url(home_url('/login/'));
        $register_url = !empty($settings['register_url']['url']) ? $settings['register_url']['url'] : wp_registration_url();
        $dashboard_url = !empty($settings['dashboard_url']['url']) ? $settings['dashboard_url']['url'] : home_url('/dashboard/');

        $register_url = add_query_arg('redirect_to', $redirect_to, $register_url);
        $forgot_url = add_query_arg('redirect_to', $redirect_to, $forgot_url);

        $is_logged_in = is_user_logged_in();

        $title_text = trim((string) ($settings['title_text'] ?? '')) ?: 'تسجيل الدخول';
        $description_text = trim((string) ($settings['description_text'] ?? '')) ?: 'أدخل بيانات حسابك للوصول إلى لوحة التحكم.';
        $badge_text = trim((string) ($settings['badge_text'] ?? '')) ?: 'آمن ومشفر';
        $login_label = trim((string) ($settings['login_label'] ?? '')) ?: 'اسم المستخدم أو البريد الإلكتروني';
        $password_label = trim((string) ($settings['password_label'] ?? '')) ?: 'كلمة المرور';
        $forgot_text = trim((string) ($settings['forgot_text'] ?? '')) ?: 'نسيت كلمة المرور؟';
        $remember_text = trim((string) ($settings['remember_text'] ?? '')) ?: 'تذكرني';
        $submit_text = trim((string) ($settings['submit_text'] ?? '')) ?: 'دخول إلى الحساب';
        $show_password_text = trim((string) ($settings['show_password_text'] ?? '')) ?: 'إظهار';
        $hide_password_text = trim((string) ($settings['hide_password_text'] ?? '')) ?: 'إخفاء';
        $register_prompt = trim((string) ($settings['register_prompt'] ?? '')) ?: 'ليس لديك حساب؟';
        $register_link_text = trim((string) ($settings['register_link_text'] ?? '')) ?: 'أنشئ حسابك الآن';
        $logged_in_notice = trim((string) ($settings['logged_in_notice'] ?? '')) ?: 'أنت مسجل دخول حاليا.';
        $dashboard_button_text = trim((string) ($settings['dashboard_button_text'] ?? '')) ?: 'الذهاب إلى لوحة التحكم';

        $log_input_id = 'pge_login_log_' . $widget_id;
        $pwd_input_id = 'pge_login_pwd_' . $widget_id;
        $toggle_id = 'pge_login_toggle_' . $widget_id;
?>
        <div class="pge-login-screen pge-login-widget-only" dir="rtl">
            <section class="pge-login-widget">
                <div class="pge-login-header">
                    <div>
                        <h2 class="pge-login-title"><?php echo esc_html($title_text); ?></h2>
                        <p class="pge-login-description"><?php echo esc_html($description_text); ?></p>
                    </div>
                    <span class="pge-login-badge"><?php echo esc_html($badge_text); ?></span>
                </div>

                <?php if ($is_logged_in): ?>
                    <div class="pge-login-message pge-login-message-success"><?php echo esc_html($logged_in_notice); ?></div>
                    <a href="<?php echo esc_url($dashboard_url); ?>" class="pge-login-submit pge-login-submit-dashboard">
                        <?php echo esc_html($dashboard_button_text); ?>
                    </a>
                <?php else: ?>
                    <?php if ($login_notice): ?>
                        <div class="pge-login-message pge-login-message-success"><?php echo esc_html($login_notice); ?></div>
                    <?php endif; ?>

                    <?php if ($login_error): ?>
                        <div class="pge-login-message pge-login-message-error"><?php echo esc_html($login_error); ?></div>
                    <?php endif; ?>

                    <form class="pge-login-form" action="<?php echo esc_url($login_action_url); ?>" method="post" novalidate>
                        <div class="pge-login-field-wrap">
                            <label for="<?php echo esc_attr($log_input_id); ?>" class="pge-login-label"><?php echo esc_html($login_label); ?></label>
                            <input id="<?php echo esc_attr($log_input_id); ?>" name="log" type="text" autocomplete="username"
                                value="<?php echo esc_attr($login_value); ?>"
                                class="pge-login-input"
                                placeholder="example@email.com" required />
                        </div>

                        <div class="pge-login-field-wrap">
                            <div class="pge-login-field-header">
                                <label for="<?php echo esc_attr($pwd_input_id); ?>" class="pge-login-label"><?php echo esc_html($password_label); ?></label>
                                <a href="<?php echo esc_url($forgot_url); ?>" class="pge-login-link"><?php echo esc_html($forgot_text); ?></a>
                            </div>
                            <div class="pge-login-password-wrap">
                                <input id="<?php echo esc_attr($pwd_input_id); ?>" name="pwd" type="password" autocomplete="current-password"
                                    class="pge-login-input"
                                    placeholder="••••••••" required />
                                <button id="<?php echo esc_attr($toggle_id); ?>" type="button" class="pge-login-show-btn">
                                    <?php echo esc_html($show_password_text); ?>
                                </button>
                            </div>
                        </div>

                        <div class="pge-login-remember-wrap">
                            <label class="pge-login-remember-label">
                                <input name="rememberme" type="checkbox" value="forever" class="pge-login-checkbox" />
                                <?php echo esc_html($remember_text); ?>
                            </label>
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
                            <input type="hidden" name="testcookie" value="1" />
                            <input type="hidden" name="pge_login_form_id" value="<?php echo esc_attr($form_id); ?>" />
                            <?php wp_nonce_field($nonce_action, 'pge_login_nonce'); ?>
                        </div>

                        <button type="submit" class="pge-login-submit">
                            <?php echo esc_html($submit_text); ?>
                            <span class="pge-login-submit-arrow">←</span>
                        </button>
                    </form>

                    <div class="pge-login-footer-box">
                        <div class="pge-login-footer-text">
                            <?php echo esc_html($register_prompt); ?>
                            <a href="<?php echo esc_url($register_url); ?>" class="pge-login-link">
                                <?php echo esc_html($register_link_text); ?>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <script>
            (function() {
                const pwdInput = document.getElementById('<?php echo esc_js($pwd_input_id); ?>');
                const toggleBtn = document.getElementById('<?php echo esc_js($toggle_id); ?>');
                if (!pwdInput || !toggleBtn) return;

                const showText = <?php echo wp_json_encode($show_password_text); ?>;
                const hideText = <?php echo wp_json_encode($hide_password_text); ?>;

                toggleBtn.addEventListener('click', function() {
                    const isPassword = pwdInput.type === 'password';
                    pwdInput.type = isPassword ? 'text' : 'password';
                    toggleBtn.textContent = isPassword ? hideText : showText;
                });
            })();
        </script>

        <style>
            .pge-login-screen {
                --pge-login-btn-start: #0f172a;
                --pge-login-btn-end: #1e293b;
                --pge-login-btn-hover-start: #1e293b;
                --pge-login-btn-hover-end: #334155;
            }

            .pge-login-widget-only {
                padding: 0;
                background: transparent;
            }

            .pge-login-widget {
                width: 100%;
                max-width: 800px;
                margin: 0 auto;
                border: 1px solid #cbd5e1;
                border-radius: 28px;
                background: #ffffff;
                padding: 38px 40px;
            }
            .pge-login-header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 20px;
                margin-bottom: 24px;
            }

            .pge-login-title {
                margin: 0;
                font-size: 50px;
                font-weight: 800;
                line-height: 1.2;
                color: #0f172a;
            }

            .pge-login-description {
                margin: 10px 0 0;
                font-size: 34px;
                color: #475569;
                line-height: 1.5;
            }

            .pge-login-badge {
                display: inline-flex;
                align-items: center;
                border: 1px solid #86efac;
                background: #ecfdf5;
                color: #047857;
                border-radius: 999px;
                padding: 10px 18px;
                font-size: 16px;
                font-weight: 700;
                line-height: 1;
                white-space: nowrap;
            }

            .pge-login-form { display: flex; flex-direction: column; gap: 16px; margin-top: 14px; }
            .pge-login-field-wrap { display: flex; flex-direction: column; gap: 8px; }
            .pge-login-field-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
            .pge-login-label { color: #475569; font-size: 16px; font-weight: 700; }

            .pge-login-input {
                width: 100%;
                height: 58px;
                border: 1px solid #cbd5e1;
                border-radius: 18px;
                background: #fff;
                padding: 0 18px;
                color: #1e293b;
                font-size: 18px;
                outline: none;
            }
            .pge-login-input::placeholder { color: #94a3b8; }
            .pge-login-input:focus { border-color: #0f172a; }

            .pge-login-password-wrap { position: relative; }
            .pge-login-show-btn {
                position: absolute;
                top: 50%;
                inset-inline-start: 10px;
                transform: translateY(-50%);
                border: 0;
                background: transparent;
                color: #475569;
                font-size: 16px;
                font-weight: 700;
                cursor: pointer;
                padding: 6px 10px;
                border-radius: 10px;
            }
            .pge-login-show-btn:hover { background: #f8fafc; }

            .pge-login-link {
                color: #475569;
                font-size: 16px;
                font-weight: 700;
                text-decoration: underline;
                text-decoration-thickness: 1px;
                text-underline-offset: 4px;
            }
            .pge-login-link:hover { color: #0f172a; }

            .pge-login-remember-wrap { margin-top: 6px; display: flex; justify-content: flex-end; }
            .pge-login-remember-label { display: inline-flex; align-items: center; gap: 10px; color: #475569; font-size: 16px; }
            .pge-login-checkbox { width: 20px; height: 20px; border: 1px solid #94a3b8; border-radius: 4px; }
            .pge-login-submit {
                margin-top: 8px;
                width: 100%;
                border: 1px solid transparent;
                border-radius: 20px;
                background: linear-gradient(90deg, var(--pge-login-btn-start), var(--pge-login-btn-end));
                color: #fff;
                height: 62px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                font-size: 20px;
                font-weight: 700;
                cursor: pointer;
                transition: all .2s ease;
                text-decoration: none;
            }
            .pge-login-submit:hover {
                background: linear-gradient(90deg, var(--pge-login-btn-hover-start), var(--pge-login-btn-hover-end));
            }
            .pge-login-submit-arrow { opacity: .9; }

            .pge-login-footer-box {
                margin-top: 24px;
                border: 1px solid #dbe2ea;
                border-radius: 20px;
                background: #f8fafc;
                padding: 16px 18px;
            }
            .pge-login-footer-text { color: #475569; font-size: 18px; text-align: center; }

            .pge-login-message {
                margin-top: 18px;
                border-radius: 16px;
                padding: 12px 14px;
                font-size: 14px;
                font-weight: 700;
            }
            .pge-login-message-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
            .pge-login-message-error { background: #fff1f2; color: #9f1239; border: 1px solid #fecdd3; }
        </style>
<?php
    }
}
