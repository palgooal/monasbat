<?php
if (!defined('ABSPATH')) exit;

class Mon_Salla_API
{

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_webhook_route']);
    }

    public function register_webhook_route()
    {
        register_rest_route('mon-events/v1', '/salla-webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_webhook_data'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_webhook_data($request)
    {
        $body = $request->get_json_params();

        // 1. استخراج البيانات الأساسية
        $order_data     = $body['data'] ?? [];
        $customer_email = $order_data['customer']['email'] ?? '';
        $order_status   = $order_data['status']['slug'] ?? '';
        $order_id       = $order_data['id'] ?? '';

        // 2. التحقق من حالة الطلب (مكتمل)
        if ($customer_email && $order_status === 'completed') {

            // 3. البحث عن الـ Salla Product ID داخل الطلب
            $purchased_salla_id = '';
            if (!empty($order_data['items'])) {
                $purchased_salla_id = $order_data['items'][0]['product']['id'] ?? '';
            }

            // 4. مطابقة ID المنتج القادم من سلة مع الإعدادات في لوحة التحكم
            $matched_plan_key = $this->find_plan_by_salla_id($purchased_salla_id);

            if ($matched_plan_key) {
                // استدعاء كلاس المستخدمين للتفعيل بناءً على مفتاح الباقة المكتشف (plan_1, plan_2, etc.)
                return Mon_Events_Users::activate_user_package($customer_email, [
                    'order_id'   => $order_id,
                    'plan_key'   => $matched_plan_key,
                    'full_data'  => $order_data
                ]);
            } else {
                return new WP_REST_Response([
                    'status' => 'error',
                    'message' => 'Product ID not recognized in plugin settings',
                    'salla_id' => $purchased_salla_id
                ], 200);
            }
        }

        return new WP_REST_Response([
            'status'  => 'ignored',
            'reason'  => 'Order not completed or no email found'
        ], 200);
    }

    /**
     * دالة مساعدة للبحث عن مفتاح الباقة باستخدام ID منتج سلة
     */
    private function find_plan_by_salla_id($salla_id)
    {
        if (empty($salla_id)) return false;

        $plans = get_option('mon_packages_settings', []);

        foreach ($plans as $plan_key => $plan_data) {
            if (isset($plan_data['salla_id']) && (string)$plan_data['salla_id'] === (string)$salla_id) {
                return $plan_key; // يعيد plan_1 أو plan_2 الخ..
            }
        }

        return false;
    }
}
