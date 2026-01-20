<?php
if (! defined('ABSPATH')) exit;

class Mon_Event_Packages
{

    public static function get_package_limits($plan_id)
    {
        $plans = [
            'plan_1' => ['name' => 'الباقة 1', 'guest_limit' => 15, 'photos_limit' => 10, 'countdown' => false, 'video_promo' => 'youtube_only'],
            'plan_2' => ['name' => 'الباقة 2', 'guest_limit' => 30, 'photos_limit' => 25, 'countdown' => true, 'video_promo' => 'youtube_only'],
            'plan_3' => ['name' => 'الباقة 3', 'guest_limit' => 200, 'photos_limit' => 50, 'countdown' => true, 'video_promo' => 1],
            'plan_4' => ['name' => 'الباقة 4', 'guest_limit' => 500, 'photos_limit' => 70, 'countdown' => true, 'video_promo' => 2]
        ];
        return isset($plans[$plan_id]) ? $plans[$plan_id] : $plans['plan_1'];
    }

    // دالة للتحقق من القدرة على إضافة ضيوف
    public static function can_add_guest($user_id, $event_id)
    {
        $current_plan = get_user_meta($user_id, 'mon_current_plan', true) ?: 'plan_1';
        $limits = self::get_package_limits($current_plan);

        // نستخدم الكلاس الموجود مسبقاً في مشروعك لجلب عدد المدعوين
        $invites_class = mon_events_mvp()->invites();
        // نفترض وجود دالة لجلب العدد أو نحسبها من الميتا
        $current_guests = get_post_meta($event_id, '_mon_invites_count', true) ?: 0;

        return (int)$current_guests < (int)$limits['guest_limit'];
    }
}

// نقل الـ Shortcode خارج الكلاس أو تركه كدالة مستقلة لسهولة الاستدعاء
add_shortcode('mon_packages', 'mon_display_packages');

function mon_display_packages()
{
    // الكود الذي كتبته سابقاً مع إضافة esc_url للأمان
    $packages = [
        'plan_1' => ['name' => 'باقة ١', 'price' => '49', 'guests' => '15', 'photos' => '10', 'video' => 'يوتيوب'],
        'plan_2' => ['name' => 'باقة ٢', 'price' => '69', 'guests' => '30', 'photos' => '25', 'video' => 'يوتيوب'],
        'plan_3' => ['name' => 'باقة ٣', 'price' => '199', 'guests' => '200', 'photos' => '50', 'video' => 'رفع فيديو'],
        'plan_4' => ['name' => 'باقة ٤', 'price' => '450', 'guests' => '500', 'photos' => '70', 'video' => '2 فيديو'],
    ];

    $html = '<div class="mon-packages-container">';
    foreach ($packages as $id => $pkg) {
        $html .= '
        <div class="mon-package-card ' . esc_attr($id) . '">
            <h3>' . esc_html($pkg['name']) . '</h3>
            <div class="price">' . esc_html($pkg['price']) . ' <span>ريال</span></div>
            <ul>
                <li>عدد المدعوين: ' . esc_html($pkg['guests']) . '</li>
                <li>رفع صور: ' . esc_html($pkg['photos']) . '</li>
                <li>الفيديو: ' . esc_html($pkg['video']) . '</li>
                <li>باركود المناسبة: مدعوم</li>
            </ul>
            <a href="' . esc_url(mon_get_salla_link($id)) . '" class="buy-btn">اشترك الآن</a>
        </div>';
    }
    $html .= '</div>';
    return $html;
}

function mon_get_salla_link($plan_id)
{
    $links = [
        'plan_1' => 'https://salla.sa/your-store/product-1',
        'plan_2' => 'https://salla.sa/your-store/product-2',
        'plan_3' => 'https://salla.sa/your-store/product-3',
        'plan_4' => 'https://salla.sa/your-store/product-4',
    ];
    return isset($links[$plan_id]) ? $links[$plan_id] : '#';
}
