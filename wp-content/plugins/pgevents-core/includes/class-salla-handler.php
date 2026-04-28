<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Mon_Salla_Handler
 * النسخة المتوافقة مع الهيكلية الشاملة للباقات
 */
class Mon_Salla_Handler
{
    private $webhook_secret;
    private $client_id;
    private $client_secret;

    public function __construct()
    {
        // كل المفاتيح تُقرأ من لوحة التحكم (DB) أو wp-config كـ fallback
        $this->webhook_secret = defined('PGE_SALLA_WEBHOOK_SECRET')
            ? PGE_SALLA_WEBHOOK_SECRET
            : (string) get_option('pge_salla_webhook_secret', '');

        $this->client_id = defined('PGE_SALLA_CLIENT_ID')
            ? PGE_SALLA_CLIENT_ID
            : (string) get_option('pge_salla_client_id', '');

        $this->client_secret = defined('PGE_SALLA_CLIENT_SECRET')
            ? PGE_SALLA_CLIENT_SECRET
            : (string) get_option('pge_salla_client_secret', '');

        add_action('rest_api_init', [$this, 'register_webhook_route']);
        $this->init_admin_hooks();
    }

    public function register_webhook_route()
    {
        register_rest_route('mon/v1', '/salla-callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_salla_notification'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_salla_notification($request)
    {
        $payload   = $request->get_body();
        $signature = $request->get_header('x_salla_signature');

        if (!$this->is_valid_signature($payload, $signature)) {
            return new WP_REST_Response(['error' => 'Invalid signature'], 401);
        }

        $data        = json_decode($payload, true);
        $event       = $data['event']    ?? '';
        $event_data  = $data['data']     ?? [];
        $merchant_id = (int) ($data['merchant'] ?? 0);

        // ── S1: توجيه الحدث حسب نوعه ──────────────────────────────────────
        switch ($event) {

            // أحداث الطلبات
            case 'order.created':
            case 'order.updated':
            case 'order.payment.updated':
                return $this->handle_order_event($event_data);

            // تجديد التوكن (كل 14 يوم)
            case 'app.store.authorize':
                return $this->handle_app_authorize($event_data, $merchant_id);

            // تثبيت / إلغاء / تحديث التطبيق
            case 'app.installed':
                return $this->handle_app_installed($event_data, $merchant_id);

            case 'app.store.uninstall':
            case 'app.uninstalled':
                return $this->handle_app_uninstalled($merchant_id);

            case 'app.updated':
                return $this->handle_app_updated($event_data, $merchant_id);

            default:
                error_log("ℹ️ Salla Webhook: حدث غير معالج — " . $event);
                return new WP_REST_Response(['status' => 'ignored', 'event' => $event], 200);
        }
    }

    // ── معالج أحداث الطلبات (order.*) ─────────────────────────────────────
    private function handle_order_event($order_data)
    {
        if (empty($order_data)) {
            return new WP_REST_Response(['message' => 'No Data'], 200);
        }

        $status_slug    = $order_data['status']['slug'] ?? '';
        $customer_email = $order_data['customer']['email'] ?? '';

        $activation_statuses   = ['completed', 'delivered'];
        $deactivation_statuses = ['canceled', 'refunded', 'returned'];

        if (in_array($status_slug, $activation_statuses)) {
            $this->process_user_and_plan($order_data);
            return new WP_REST_Response(['status' => 'success', 'message' => 'Package Activated'], 200);
        } elseif (in_array($status_slug, $deactivation_statuses)) {
            $this->deactivate_user_package($customer_email);
            return new WP_REST_Response(['status' => 'deactivated', 'message' => 'Package Revoked'], 200);
        }

        return new WP_REST_Response(['status' => 'ignored', 'message' => 'Status: ' . $status_slug], 200);
    }

    // ── S2: حفظ توكنات App Store (يصلنا كل 14 يوم) ──────────────────────
    private function handle_app_authorize($data, $merchant_id)
    {
        if (empty($data['access_token'])) {
            error_log("⚠️ Salla app.store.authorize: بيانات التوكن مفقودة للمتجر $merchant_id");
            return new WP_REST_Response(['error' => 'Missing token data'], 400);
        }

        $tokens = [
            'access_token'  => sanitize_text_field($data['access_token']  ?? ''),
            'refresh_token' => sanitize_text_field($data['refresh_token'] ?? ''),
            'expires'       => (int) ($data['expires']    ?? 0),
            'scope'         => sanitize_text_field($data['scope']         ?? ''),
            'token_type'    => sanitize_text_field($data['token_type']    ?? 'bearer'),
            'updated_at'    => current_time('mysql'),
        ];

        update_option('pge_salla_tokens_' . $merchant_id, $tokens, false);

        error_log("✅ Salla tokens saved for merchant: $merchant_id | expires: " . date('Y-m-d H:i', $tokens['expires']));

        return new WP_REST_Response(['status' => 'authorized'], 200);
    }

    // ── S3a: تثبيت التطبيق ────────────────────────────────────────────────
    private function handle_app_installed($data, $merchant_id)
    {
        update_option('pge_salla_install_' . $merchant_id, [
            'installed_at' => current_time('mysql'),
            'merchant_id'  => $merchant_id,
            'data'         => $data,
        ], false);

        error_log("📦 Salla app installed for merchant: $merchant_id");

        return new WP_REST_Response(['status' => 'installed'], 200);
    }

    // ── S3b: إلغاء تثبيت التطبيق ─────────────────────────────────────────
    private function handle_app_uninstalled($merchant_id)
    {
        delete_option('pge_salla_tokens_'  . $merchant_id);
        delete_option('pge_salla_install_' . $merchant_id);

        error_log("🗑️ Salla app uninstalled for merchant: $merchant_id — tokens deleted");

        return new WP_REST_Response(['status' => 'uninstalled'], 200);
    }

    // ── S3c: تحديث التطبيق (التوكن سيصل بعدها في app.store.authorize) ────
    private function handle_app_updated($data, $merchant_id)
    {
        error_log("🔄 Salla app updated for merchant: $merchant_id — awaiting new token");
        return new WP_REST_Response(['status' => 'noted'], 200);
    }

    /**
     * دالة إلغاء تفعيل الباقة
     */
    private function deactivate_user_package($email)
    {
        $user = get_user_by('email', $email);
        if ($user) {
            update_user_meta($user->ID, '_mon_package_status', 'expired'); // أو canceled
            // تصفير الحدود لضمان عدم قدرته على استخدام النظام
            update_user_meta($user->ID, '_mon_guest_limit', 0);
            update_user_meta($user->ID, '_mon_host_photos_limit', 0);
            update_user_meta($user->ID, '_mon_events_limit', 0);
            update_user_meta($user->ID, '_mon_active_features', []); // مسح المميزات

            error_log("❌ Salla Webhook: Package revoked for user: " . $email);
        }
    }

    private function is_valid_signature($payload, $signature)
    {
        if (empty($signature) || empty($this->webhook_secret)) return false;
        $computed_signature = hash_hmac('sha256', $payload, $this->webhook_secret);
        return hash_equals((string)$computed_signature, (string)$signature);
    }

    private function process_user_and_plan($order_data)
    {
        $customer_email = $order_data['customer']['email'] ?? '';
        if (!$customer_email) return;

        // 1. معالجة المستخدم (إنشاء أو جلب)
        $user = get_user_by('email', $customer_email);
        if (!$user) {
            $user = $this->create_new_salla_user($order_data);
        }

        if (!$user) return;

        // 2. معالجة الباقة المشتراة
        foreach ($order_data['items'] as $item) {
            $salla_product_id = (string)($item['product']['id'] ?? ($item['product_id'] ?? ''));

            // البحث عن مفتاح الباقة (plan_1, plan_2...) بناءً على ID سلة
            $plan_key = $this->map_product_to_plan($salla_product_id);

            if ($plan_key) {
                // استدعاء كلاس المستخدمين لتوزيع المميزات والحدود
                Mon_Events_Users::activate_user_package($customer_email, [
                    'order_id' => $order_data['id'],
                    'plan_key' => $plan_key
                ]);

                update_user_meta($user->ID, '_created_via_salla', 'yes');
                set_transient('mon_salla_success_' . $user->ID, "تم تفعيل " . $plan_key . " للعميل: " . $customer_email, 60);
                break;
            }
        }
    }

    private function create_new_salla_user($order_data)
    {
        $email = $order_data['customer']['email'];
        $random_password = wp_generate_password(12, false);
        $user_id = wp_create_user($email, $random_password, $email);

        if (is_wp_error($user_id)) return false;

        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $order_data['customer']['first_name'] ?? '',
            'last_name'    => $order_data['customer']['last_name'] ?? '',
            'display_name' => $order_data['customer']['full_name'] ?? $email,
        ]);

        $user = get_user_by('id', $user_id);
        $this->send_welcome_email($user);
        return $user;
    }

    private function map_product_to_plan($salla_product_id)
    {
        $plans = get_option('mon_packages_settings', []);
        foreach ($plans as $plan_key => $data) {
            if (isset($data['salla_id']) && (string)$data['salla_id'] === (string)$salla_product_id) {
                return $plan_key;
            }
        }
        return false;
    }

    private function send_welcome_email($user)
    {
        $key = get_password_reset_key($user);
        $set_password_url = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user->user_login), 'login');

        $subject = 'تم إنشاء حسابك وتفعيل باقتك بنجاح! 🎉';
        $message = "مرحباً، لقد تم تفعيل اشتراكك في " . get_bloginfo('name') . ".\n\n";
        $message .= "لإدارة مناسباتك، يرجى تعيين كلمة المرور عبر الرابط التالي:\n" . $set_password_url;

        wp_mail($user->user_email, $subject, $message);
    }

    private function init_admin_hooks()
    {
        // عرض إشعار النجاح في لوحة التحكم
        add_action('admin_notices', function () {
            $msg = get_transient('mon_salla_success_' . get_current_user_id());
            if ($msg) {
                echo "<div class='notice notice-success is-dismissible'><p>{$msg} ✅</p></div>";
                delete_transient('mon_salla_success_' . get_current_user_id());
            }
        });

        // إضافة أعمدة في جدول المستخدمين لمراقبة الاشتراكات
        add_filter('manage_users_columns', function ($cols) {
            $cols['mon_plan'] = 'الباقة الحالية';
            $cols['mon_source'] = 'المصدر';
            return $cols;
        });

        add_filter('manage_users_custom_column', function ($val, $col, $user_id) {
            if ($col === 'mon_plan') {
                $plan_name = get_user_meta($user_id, '_mon_package_name', true);
                return $plan_name ? "<mark><strong>$plan_name</strong></mark>" : '--';
            }
            if ($col === 'mon_source') {
                $source = get_user_meta($user_id, '_created_via_salla', true);
                return ($source === 'yes') ? '🛒 سلة' : '👤 يدوي';
            }
            return $val;
        }, 10, 3);
    }
}

new Mon_Salla_Handler();
