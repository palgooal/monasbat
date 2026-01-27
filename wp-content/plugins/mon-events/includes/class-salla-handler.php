<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Mon_Salla_Handler
 * معالجة طلبات الربط مع منصة سلة وتحديث باقات الأعضاء تلقائياً
 */
class Mon_Salla_Handler
{
    // الإعدادات الخاصة بمتجرك في سلة
    private $webhook_secret = 'c76c9b516b18bf41ed71475c926e5d59feb006a3609e9053b942c04c06bdc8a3';

    public function __construct()
    {
        // تسجيل مسار الـ API الخاص بـ Webhook سلة
        add_action('rest_api_init', [$this, 'register_webhook_route']);

        // تسجيل وظائف العرض في لوحة تحكم ووردبريس
        $this->init_admin_hooks();
    }

    /**
     * تسجيل مسار الـ Webhook
     */
    public function register_webhook_route()
    {
        register_rest_route('mon/v1', '/salla-callback', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'handle_salla_notification'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * معالجة الإشارة القادمة من سلة
     */
    public function handle_salla_notification($request)
    {
        $payload   = $request->get_body();
        $signature = $request->get_header('x-salla-signature');
        $data      = json_decode($payload, true);

        // 1. تسجيل المحاولة في ملف السجل للديبيج
        $this->log_request($payload, $signature);

        // 2. التحقق من التوقيع (الأمان)
        if (!$this->is_valid_signature($payload, $signature)) {
            return new WP_REST_Response(['message' => 'Unauthorized Signature'], 401);
        }

        // 3. التحقق من نوع الحدث والحالة
        $event = $data['event'] ?? '';
        if ($event === 'order.status.updated' || $event === 'order.created' || $event === 'order.updated') {

            $status_slug = $data['data']['status']['slug'] ?? '';
            $allowed_statuses = ['completed', 'delivered', 'in_progress'];

            // الترقية إذا كانت الحالة مكتملة أو الحدث هو إنشاء طلب جديد
            if (in_array($status_slug, $allowed_statuses) || $event === 'order.created') {
                $this->process_upgrade($data['data']);
            }
        }

        return new WP_REST_Response(['status' => 'success'], 200);
    }

    /**
     * التحقق من التوقيع الرقمي لمنع التلاعب بالبيانات
     */
    private function is_valid_signature($payload, $signature)
    {
        $computed_signature = hash_hmac('sha256', $payload, $this->webhook_secret);
        return hash_equals((string)$signature, (string)$computed_signature);
    }

    /**
     * معالجة تحديث باقة المستخدم
     */
    private function process_upgrade($order_data)
    {
        $customer_email = $order_data['customer']['email'] ?? '';
        $user = get_user_by('email', $customer_email);

        if ($user) {
            foreach ($order_data['items'] as $item) {
                $salla_product_id = (string)$item['product']['id'];
                $plan_id = $this->map_product_to_plan($salla_product_id);

                if ($plan_id) {
                    // تحديث بيانات المستخدم
                    update_user_meta($user->ID, 'mon_current_plan', $plan_id);
                    update_user_meta($user->ID, 'mon_plan_updated_at', current_time('mysql'));

                    // تعيين تنبيه نجاح يظهر في الإدارة
                    set_transient('mon_salla_success_' . get_current_user_id(), "تمت ترقية {$user->display_name} إلى " . strtoupper($plan_id), 60);
                }
            }
        }
    }

    /**
     * ربط معرفات منتجات سلة بأسماء الباقات في الموقع
     */
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

    /**
     * تسجيل العمليات في ملف نصي للرجوع إليها
     */
    private function log_request($payload, $signature)
    {
        $log_entry = "--- إشارة مستلمة (" . date('Y-m-d H:i:s') . ") ---\n";
        $log_entry .= "Signature: " . ($signature ?: 'NONE') . "\n";
        $log_entry .= "Payload: " . $payload . "\n";
        $log_entry .= "------------------------------------------\n\n";
        file_put_contents(dirname(__FILE__) . '/salla_debug_log.txt', $log_entry, FILE_APPEND);
    }

    /**
     * تهيئة وظائف لوحة التحكم (العرض والإشعارات)
     */
    private function init_admin_hooks()
    {
        // إشعار النجاح الأخضر
        add_action('admin_notices', function () {
            $msg = get_transient('mon_salla_success_' . get_current_user_id());
            if ($msg) {
                echo "<div class='notice notice-success is-dismissible'><p>{$msg} ✅</p></div>";
                delete_transient('mon_salla_success_' . get_current_user_id());
            }
        });

        // إضافة عمود الباقة لجدول الأعضاء
        add_filter('manage_users_columns', function ($cols) {
            $cols['mon_plan'] = 'الباقة المشترك بها';
            return $cols;
        });

        add_filter('manage_users_custom_column', function ($val, $col, $user_id) {
            if ($col === 'mon_plan') {
                $p = get_user_meta($user_id, 'mon_current_plan', true);
                return $p ? "<span style='color:#2271b1; font-weight:bold;'>" . strtoupper($p) . "</span>" : '<span style="color:#999;">لا يوجد باقة</span>';
            }
            return $val;
        }, 10, 3);
    }
}

// تشغيل الكلاس
new Mon_Salla_Handler();
