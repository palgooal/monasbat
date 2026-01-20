<?php
if (!defined('ABSPATH')) exit;

class Mon_Salla_Handler
{
    private $client_id     = '82febe64-c582-46d5-8dd2-c7938eddf2de';
    private $client_secret = '07ac5a341a0dcf57669205d05544ae61d9c5e4d64a5230d46c0ae85aebf95503';
    private $webhook_secret = 'c76c9b516b18bf41ed71475c926e5d59feb006a3609e9053b942c04c06bdc8a3';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_webhook_route']);
    }

    public function register_webhook_route()
    {
        register_rest_route('mon/v1', '/salla-callback', [
            'methods'  => ['GET', 'POST'], // أبقينا GET مؤقتاً للاختبار
            'callback' => [$this, 'handle_salla_notification'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_salla_notification($request)
    {
        $payload = $request->get_body();
        $signature = $request->get_header('x-salla-signature');

        // إذا تم فتح الرابط يدوياً في المتصفح
        if (empty($signature)) {
            return new WP_REST_Response([
                'status' => 'active',
                'message' => 'الرابط يعمل بنجاح وبانتظار بيانات سلة.'
            ], 200);
        }

        if (!$this->is_valid_signature($payload, $signature)) {
            return new WP_REST_Response(['message' => 'Unauthorized Signature'], 401);
        }

        $data = json_decode($payload, true);
        if (isset($data['event']) && $data['event'] === 'order.status.updated') {
            if ($data['data']['status']['id'] === 'completed') {
                $this->process_upgrade($data['data']);
            }
        }

        return new WP_REST_Response(['status' => 'success'], 200);
    }

    private function is_valid_signature($payload, $signature)
    {
        if (is_null($signature)) return false;
        $computed_signature = hash_hmac('sha256', $payload, $this->webhook_secret);
        return hash_equals((string)$signature, (string)$computed_signature);
    }

    private function process_upgrade($order_data)
    {
        $customer_email = $order_data['customer']['email'];
        $user = get_user_by('email', $customer_email);
        if ($user) {
            foreach ($order_data['items'] as $item) {
                $plan_id = $this->map_product_to_plan($item['product']['id']);
                if ($plan_id) {
                    update_user_meta($user->ID, 'mon_current_plan', $plan_id);
                    update_user_meta($user->ID, 'mon_plan_updated_at', current_time('mysql'));
                }
            }
        }
    }

    private function map_product_to_plan($salla_product_id)
    {
        $mapping = [
            '726730757'  => 'plan_1',
            '2000884195' => 'plan_2',
            '1940642506' => 'plan_3',
            '1689335334' => 'plan_4',
        ];
        return $mapping[$salla_product_id] ?? false;
    }


}
new Mon_Salla_Handler();

// كود لعرض الباقة الحالية في جدول الأعضاء للتأكد من نجاح العملية
add_filter('manage_users_columns', function ($columns) {
    $columns['mon_plan'] = 'الباقة المشترك بها';
    return $columns;
});

add_filter('manage_users_custom_column', function ($value, $column_name, $user_id) {
    if ($column_name === 'mon_plan') {
        $plan = get_user_meta($user_id, 'mon_current_plan', true);
        return $plan ? "<strong>" . strtoupper($plan) . "</strong>" : 'لا يوجد اشتراك';
    }
    return $value;
}, 10, 3);
