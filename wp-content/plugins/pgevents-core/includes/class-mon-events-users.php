<?php
if (!defined('ABSPATH')) exit;

class Mon_Events_Users
{

    public static function activate_user_package($email, $data)
    {
        $user = get_user_by('email', $email);
        $plan_key = $data['plan_key'] ?? ''; // مثل plan_1, plan_2 ...

        if ($user && $plan_key) {
            // 1. جلب إعدادات الباقة من لوحة التحكم التي برمجناها في admin-mods
            $all_plans = get_option('mon_packages_settings', []);
            $plan_details = $all_plans[$plan_key] ?? [];

            if (empty($plan_details)) {
                return new WP_REST_Response(['status' => 'error', 'message' => 'Plan details not found'], 404);
            }

            // 2. تفعيل الحالة وتخزين البيانات الأساسية
            update_user_meta($user->ID, '_mon_package_status', 'active');
            update_user_meta($user->ID, '_mon_package_key', $plan_key); // تخزين المفتاح البرمجي
            update_user_meta($user->ID, '_mon_package_name', $plan_details['name'] ?? 'باقة غير معروفة');
            update_user_meta($user->ID, '_mon_last_order_id', $data['order_id']);
            update_user_meta($user->ID, '_mon_activation_date', current_time('mysql'));

            // 3. تخزين "الحدود والكميات" مباشرة في بيانات المستخدم لسهولة فحصها لاحقاً
            update_user_meta($user->ID, '_mon_guest_limit', $plan_details['guest_limit'] ?? 0);
            update_user_meta($user->ID, '_mon_host_photos_limit', $plan_details['host_photos'] ?? 0);
            update_user_meta($user->ID, '_mon_events_limit', $plan_details['events_count'] ?? 1);
            update_user_meta($user->ID, '_mon_wa_limit', $plan_details['wa_messages'] ?? 0);

            // 4. تخزين "المميزات النشطة" كـ Array لسرعة التحقق
            // سنقوم بتخزين كل مفتاح قيمته 1
            $features = [];
            foreach ($plan_details as $key => $value) {
                if ($value == "1") {
                    $features[] = $key;
                }
            }
            update_user_meta($user->ID, '_mon_active_features', $features);

            error_log("✅ Activated Plan: {$plan_key} for User ID: {$user->ID} (Email: $email)");

            return new WP_REST_Response([
                'status' => 'success',
                'message' => 'Package activated with all features',
                'user_id' => $user->ID
            ], 200);
        }

        // ملاحظة: إذا لم يجد المستخدم، يفضل مستقبلاً إضافة كود إنشاء حساب تلقائي هنا
        return new WP_REST_Response(['status' => 'error', 'message' => 'User not found in WordPress'], 404);
    }
}
