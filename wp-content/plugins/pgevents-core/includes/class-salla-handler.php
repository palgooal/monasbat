<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Mon_Salla_Handler
 * النسخة المتوافقة مع الهيكلية الشاملة للباقات
 */
class Mon_Salla_Handler
{
    // سر الويب هوك الخاص بمتجر سلة
    private $webhook_secret = 'c76c9b516b18bf41ed71475c926e5d59feb006a3609e9053b942c04c06bdc8a3';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_webhook_route']);
        $this->init_admin_hooks();
    }

    public function register_webhook_route()
    {
        register_rest_route('mon/v1', '/salla-callback', [
            // السماح بـ GET للاختبار عبر المتصفح و POST لاستقبال بيانات سلة
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'handle_salla_notification'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_salla_notification($request)
    {
        $payload = $request->get_body();
        $data = json_decode($payload, true);

        $order_data = $data['data'] ?? null;
        if (!$order_data) return new WP_REST_Response(['message' => 'No Data'], 200);

        $status_slug = $order_data['status']['slug'] ?? '';

        // 1. تحديد الحالات "الآمنة" فقط للتفعيل
        // 'completed' للطلبات المدفوعة إلكترونياً
        // 'delivered' أو 'in_progress' حسب سير عمل متجرك
        $allowed_statuses = ['completed', 'delivered'];

        // 2. التحقق: لن يتم التفعيل إلا إذا كانت الحالة ضمن المسموح
        if (in_array($status_slug, $allowed_statuses)) {
            $this->process_user_and_plan($order_data);
            return new WP_REST_Response(['status' => 'success', 'message' => 'Package Activated'], 200);
        } else {
            // تسجيل لوج بسيط للحالات التي لم يتم تفعيلها (مثل under_review)
            error_log("Salla Webhook: Order {$order_data['id']} skipped. Status: {$status_slug}");
            return new WP_REST_Response(['status' => 'ignored', 'message' => 'Waiting for completed status'], 200);
        }
    }

    private function is_valid_signature($payload, $signature)
    {
        $computed_signature = hash_hmac('sha256', $payload, $this->webhook_secret);
        return hash_equals((string)$signature, (string)$computed_signature);
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
