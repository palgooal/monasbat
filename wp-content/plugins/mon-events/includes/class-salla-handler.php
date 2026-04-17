<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Mon_Salla_Handler
 * معالجة طلبات سلة: تفعيل الباقات، إنشاء حسابات ووردبريس، وإرسال روابط تعيين كلمة المرور.
 */
class Mon_Salla_Handler
{
    private $webhook_secret = '60ae230590b3549e1f162a38e45dc3c24db4cb976b609bdbae37905b2799722a';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_webhook_route']);
        $this->init_admin_hooks();
    }

    public function register_webhook_route()
    {
        register_rest_route('mon/v1', '/salla-callback', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'handle_salla_notification'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_salla_notification($request)
    {
        $payload   = $request->get_body();
        $signature = $request->get_header('x-salla-signature');
        $data      = json_decode($payload, true);

        $this->log_request($payload, $signature);

        if (!$this->is_valid_signature($payload, $signature)) {
            return new WP_REST_Response(['message' => 'Unauthorized Signature'], 401);
        }

        $order_data = isset($data['data']['items']) ? $data['data'] : ($data['data']['order'] ?? null);
        
        if (!$order_data) {
            return new WP_REST_Response(['message' => 'Invalid Data'], 200);
        }

        $event = $data['event'] ?? '';
        $status_slug = $order_data['status']['slug'] ?? '';
        
        $allowed_statuses = ['completed', 'delivered', 'in_progress'];

        if (in_array($status_slug, $allowed_statuses) || $event === 'order.created') {
            $this->process_upgrade($order_data);
        }

        return new WP_REST_Response(['status' => 'success'], 200);
    }

    private function is_valid_signature($payload, $signature)
    {
        $computed_signature = hash_hmac('sha256', $payload, $this->webhook_secret);
        return hash_equals((string)$signature, (string)$computed_signature);
    }

    private function process_upgrade($order_data)
    {
        $customer_email = $order_data['customer']['email'] ?? '';
        if (!$customer_email) return;

        $user = get_user_by('email', $customer_email);

        // --- إنشاء المستخدم الجديد إذا لم يكن موجوداً ---
        if (!$user) {
            $random_password = wp_generate_password(12, false);
            $user_id = wp_create_user($customer_email, $random_password, $customer_email);
            
            if (is_wp_error($user_id)) {
                error_log("Salla Webhook Error: " . $user_id->get_error_message());
                return;
            }

            $user = get_user_by('id', $user_id);
            
            wp_update_user([
                'ID'           => $user_id,
                'first_name'   => $order_data['customer']['first_name'] ?? '',
                'last_name'    => $order_data['customer']['last_name'] ?? '',
                'display_name' => $order_data['customer']['full_name'] ?? $customer_email,
            ]);
            
            update_user_meta($user_id, '_created_via_salla', 'yes');
            
            // إرسال بريد تفعيل الحساب (تعيين كلمة المرور)
            $this->send_welcome_email($user);
        }

        // --- تفعيل الباقة (للمستخدم الجديد والقديم) ---
        if ($user) {
            foreach ($order_data['items'] as $item) {
                $salla_product_id = (string)($item['product_id'] ?? ($item['product']['id'] ?? ''));
                $plan_id = $this->map_product_to_plan($salla_product_id);

                if ($plan_id) {
                    update_user_meta($user->ID, 'mon_current_plan', $plan_id);
                    update_user_meta($user->ID, '_mon_package_status', 'active');
                    update_user_meta($user->ID, '_mon_package_id', $plan_id);
                    update_user_meta($user->ID, 'mon_plan_updated_at', current_time('mysql'));
                    
                    set_transient('mon_salla_success_' . $user->ID, "تم تفعيل باقة لعميل: " . $customer_email, 60);
                    break;
                }
            }
        }
    }

    /**
     * إرسال بريد إلكتروني ترحيبي مع رابط تعيين كلمة المرور
     */
    private function send_welcome_email($user)
    {
        // توليد مفتاح آمن ورابط لتعيين كلمة المرور
        $key = get_password_reset_key($user);
        $set_password_url = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user->user_login), 'login');

        $subject = 'تم إنشاء حسابك بنجاح! 🎉 - ' . get_bloginfo('name');
        $first_name = get_user_meta($user->ID, 'first_name', true) ?: 'عميلنا العزيز';

        $message = "مرحباً " . $first_name . "،\n\n";
        $message .= "شكراً لاشتراكك معنا. لقد تم إنشاء حسابك وتفعيل باقتك تلقائياً بناءً على طلبك من سلة.\n\n";
        $message .= "لكي تتمكن من الدخول وإدارة مناسباتك، يرجى تعيين كلمة المرور الخاصة بك عبر الرابط التالي:\n";
        $message .= $set_password_url . "\n\n";
        $message .= "بعد تعيين كلمة المرور، يمكنك تسجيل الدخول في أي وقت عبر الموقع مباشرة.\n\n";
        $message .= "نتمنى لك رحلة سعيدة معنا!";

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        wp_mail($user->user_email, $subject, $message, $headers);
    }

private function map_product_to_plan($salla_product_id)
{
    $plans = get_option('mon_packages_settings', []);
    foreach ($plans as $plan_key => $data) {
        if (isset($data['salla_id']) && (string)$data['salla_id'] === (string)$salla_product_id) {
            return $plan_key; // سيعيد plan_1, plan_2 الخ..
        }
    }
    return false;
}

    private function log_request($payload, $signature)
    {
        $log_entry = "--- استقبال إشارة (" . date('Y-m-d H:i:s') . ") ---\n";
        $log_entry .= "Signature: " . ($signature ?: 'NONE') . "\n";
        $log_entry .= "Payload: " . $payload . "\n";
        $log_entry .= "------------------------------------------\n\n";
        file_put_contents(dirname(__FILE__) . '/salla_debug_log.txt', $log_entry, FILE_APPEND);
    }

    private function init_admin_hooks()
    {
        add_action('admin_notices', function () {
            $msg = get_transient('mon_salla_success_' . get_current_user_id());
            if ($msg) {
                echo "<div class='notice notice-success is-dismissible'><p>{$msg} ✅</p></div>";
                delete_transient('mon_salla_success_' . get_current_user_id());
            }
        });

        add_filter('manage_users_columns', function ($cols) {
            $cols['mon_plan'] = 'الباقة';
            $cols['mon_source'] = 'المصدر';
            return $cols;
        });

        add_filter('manage_users_custom_column', function ($val, $col, $user_id) {
            if ($col === 'mon_plan') {
                $p = get_user_meta($user_id, 'mon_current_plan', true);
                return $p ? "<strong>" . strtoupper($p) . "</strong>" : '--';
            }
            if ($col === 'mon_source') {
                $source = get_user_meta($user_id, '_created_via_salla', true);
                return ($source === 'yes') ? '<span class="dashicons dashicons-cart" title="سلة"></span> سلة' : 'يدوي';
            }
            return $val;
        }, 10, 3);
    }
}

new Mon_Salla_Handler();