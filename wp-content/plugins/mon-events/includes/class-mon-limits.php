<?php
if (!defined('ABSPATH')) exit;

class Mon_Event_Limits
{

    // تعريف حدود كل باقة
    private static $plan_configs = [
        'plan_1' => [
            'name'         => 'الباقة الأولى',
            'max_guests'   => 50,
            'allow_photos' => false,
        ],
        'plan_2' => [
            'name'         => 'الباقة الثانية',
            'max_guests'   => 150,
            'allow_photos' => true,
        ],
        'plan_3' => [
            'name'         => 'الباقة الثالثة',
            'max_guests'   => 300,
            'allow_photos' => true,
        ],
        'plan_4' => [
            'name'         => 'الباقة الرابعة (غير محدود)',
            'max_guests'   => 9999,
            'allow_photos' => true,
        ],
    ];

    // دالة للتحقق: هل يمكن للمستخدم إضافة ضيف جديد؟
    public static function can_add_guest($user_id)
    {
        $current_plan = get_user_meta($user_id, 'mon_current_plan', true) ?: 'plan_1'; // افتراضي باقة 1
        $max_allowed = self::$plan_configs[$current_plan]['max_guests'];

        // لنفترض أنك تخزن المدعوين في Custom Post Type باسم 'guest'
        $current_guest_count = count(get_posts([
            'post_type'   => 'guest',
            'author'      => $user_id,
            'post_status' => 'publish',
            'numberposts' => -1
        ]));

        return $current_guest_count < $max_allowed;
    }
}
