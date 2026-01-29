<?php
if (!defined('ABSPATH')) exit;

class Mon_Salla_API {
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_webhook_route']);
    }

    public function register_webhook_route() {
        register_rest_route('mon-events/v1', '/salla-webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_webhook_data'],
            'permission_callback' => '__return_true', 
        ]);
    }

    public function handle_webhook_data($request) {
        $body = $request->get_json_params();

        // 1. استخراج البيانات الأساسية من الـ Payload
        // الحدث في السجل الأخير هو order.updated
        $order_data = $body['data'] ?? [];
        $customer_email = $order_data['customer']['email'] ?? '';
        $order_status = $order_data['status']['slug'] ?? '';
        $order_id = $order_data['id'] ?? '';

        // 2. التحقق من اكتمال الطلب وتوفر الإيميل
        if ($customer_email && $order_status === 'completed') {
            
            // استخراج اسم الباقة (أول منتج في الطلب)
            $package_name = '';
            if (!empty($order_data['items'])) {
                $package_name = $order_data['items'][0]['name'];
            }

            // إرسال البيانات للتفعيل في كلاس المستخدمين
            return Mon_Events_Users::activate_user_package($customer_email, [
                'id'           => $order_id,
                'package_name' => $package_name,
                'full_data'    => $order_data
            ]);
        }

        return new WP_REST_Response([
            'status'  => 'ignored',
            'reason'  => 'Order not completed or no email found',
            'current_status' => $order_status
        ], 200);
    }
}