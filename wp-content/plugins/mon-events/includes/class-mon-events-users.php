<?php
if (!defined('ABSPATH')) exit;

class Mon_Events_Users {

    public static function activate_user_package($email, $data) {
        $user = get_user_by('email', $email);

        if ($user) {
            // تفعيل الصلاحيات
            update_user_meta($user->ID, '_mon_package_status', 'active');
            update_user_meta($user->ID, '_mon_package_name', $data['package_name']);
            update_user_meta($user->ID, '_mon_last_order_id', $data['id']);
            update_user_meta($user->ID, '_mon_activation_date', current_time('mysql'));

            // يمكنك هنا إضافة لوج (Log) بسيط للتأكد
            error_log("Successfully activated {$data['package_name']} for user: $email");

            return new WP_REST_Response(['status' => 'success', 'message' => 'Package activated'], 200);
        }

        return new WP_REST_Response(['status' => 'error', 'message' => 'User not found in WP'], 404);
    }
}