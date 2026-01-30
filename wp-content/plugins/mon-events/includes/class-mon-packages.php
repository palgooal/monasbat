<?php
if (!defined('ABSPATH')) exit;

class Mon_Event_Packages
{
    /**
     * جلب حدود الباقة من الإعدادات المخزنة أو القيم الافتراضية
     */
    public static function get_package_limits($plan_id)
    {
        $settings = get_option('mon_packages_settings', self::get_default_plans());
        return isset($settings[$plan_id]) ? $settings[$plan_id] : $settings['plan_1'];
    }

    /**
     * القيم الافتراضية
     */
    private static function get_default_plans() {
        return [
            'plan_1' => ['name' => 'الباقة 1', 'price' => '49',  'guest_limit' => 15,  'host_photos' => 10, 'salla_id' => '726730757', 'salla_url' => '#'],
            'plan_2' => ['name' => 'الباقة 2', 'price' => '69',  'guest_limit' => 30,  'host_photos' => 25, 'salla_id' => '2000884195', 'salla_url' => '#'],
            'plan_3' => ['name' => 'الباقة 3', 'price' => '199', 'guest_limit' => 200, 'host_photos' => 50, 'salla_id' => '1940642506', 'salla_url' => '#'],
            'plan_4' => ['name' => 'الباقة 4', 'price' => '450', 'guest_limit' => 500, 'host_photos' => 70, 'salla_id' => '1689335334', 'salla_url' => '#'],
        ];
    }

    /**
     * التحقق من قدرة المستخدم على إضافة ضيوف
     */
    public static function can_add_guest($user_id, $event_id)
    {
        $current_plan = get_user_meta($user_id, 'mon_current_plan', true) ?: 'plan_1';
        $limits = self::get_package_limits($current_plan);
        $current_guests = get_post_meta($event_id, '_mon_invites_count', true) ?: 0;
        return (int)$current_guests < (int)$limits['guest_limit'];
    }

    /**
     * دالة العرض (Shortcode)
     */
    public static function mon_display_packages() {
        $plans = get_option('mon_packages_settings', self::get_default_plans());
        
        $output = '<div class="mon-packages-grid">';
        
        for ($i = 1; $i <= 4; $i++) {
            $id = "plan_$i";
            $pkg = $plans[$id] ?? [];
            
            $check = '<span class="mon-icon-yes">✔</span>';
            $cross = '<span class="mon-icon-no">✘</span>';
            
            $output .= '
            <div class="mon-package-card ' . ($i == 3 ? 'featured' : '') . '">
                ' . ($i == 3 ? '<div class="badge">الأكثر طلباً</div>' : '') . '
                <h3 class="pkg-name">' . esc_html($pkg['name'] ?? "باقة $i") . '</h3>
                <div class="pkg-price">' . esc_html($pkg['price'] ?? '0') . ' <span>ريال</span></div>
                
                <ul class="pkg-features">
                    <li><b>👥 مدعوين:</b> ' . esc_html($pkg['guest_limit'] ?? '0') . '</li>
                    <li><b>📸 صور المضيف:</b> ' . esc_html($pkg['host_photos'] ?? '0') . '</li>
                    <li><b>🎥 فيديو برومو:</b> ' . (!empty($pkg['promo_video']) ? $check : $cross) . '</li>
                    <li><b>💾 المساحة:</b> ' . esc_html($pkg['data_size'] ?? '0') . ' ميجا</li>
                    <li><b>💬 دردشة عامة:</b> ' . (!empty($pkg['public_chat']) ? $check : $cross) . '</li>
                    <li><b>🔒 دردشة خاصة:</b> ' . (!empty($pkg['private_chat']) ? $check : $cross) . '</li>
                    <li><b>⏰ عد تنازلي:</b> ' . (!empty($pkg['countdown']) ? $check : $cross) . '</li>
                    <li><b>📍 موقع قوقل:</b> ' . (!empty($pkg['google_map']) ? $check : $cross) . '</li>
                    <li><b>💰 هدايا STCPay:</b> ' . (!empty($pkg['stc_pay']) ? $check : $cross) . '</li>
                    <li><b>📱 واتساب:</b> ' . esc_html($pkg['wa_messages'] ?? '0') . ' رقم</li>
                </ul>';

            if (!empty($pkg['salla_url'])) {
                $output .= '<a href="' . esc_url($pkg['salla_url']) . '" class="pkg-button" target="_blank">اشترِ الآن عبر سلة</a>';
            } else {
                $output .= '<button class="pkg-button disabled">غير متوفر حالياً</button>';
            }

            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= self::get_styles();

        return $output;
    }

    private static function get_styles() {
        return '
        <style>
            .mon-packages-grid { display: flex; gap: 20px; flex-wrap: wrap; justify-content: center; padding: 40px 10px; direction: rtl; }
            .mon-package-card { background: #fff; border: 1px solid #eee; border-radius: 15px; padding: 30px; width: 280px; text-align: center; transition: 0.3s; position: relative; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
            .mon-package-card:hover { transform: translateY(-10px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
            .mon-package-card.featured { border: 2px solid #2271b1; transform: scale(1.05); z-index: 1; }
            .badge { position: absolute; top: -15px; left: 50%; transform: translateX(-50%); background: #2271b1; color: #fff; padding: 5px 15px; border-radius: 20px; font-size: 12px; }
            .pkg-name { font-size: 22px; color: #333; margin-bottom: 15px; font-weight: bold; }
            .pkg-price { font-size: 35px; font-weight: bold; color: #2271b1; margin-bottom: 20px; }
            .pkg-price span { font-size: 16px; color: #777; }
            .pkg-features { list-style: none; padding: 0; margin: 0 0 25px 0; text-align: right; }
            .pkg-features li { padding: 10px 0; border-bottom: 1px solid #f9f9f9; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
            .mon-icon-yes { color: #28a745; font-weight: bold; }
            .mon-icon-no { color: #dc3545; }
            .pkg-button { display: block; background: #2271b1; color: #fff; text-decoration: none; padding: 12px; border-radius: 8px; font-weight: bold; transition: 0.3s; }
            .pkg-button:hover { background: #135e96; color: #fff; }
            .pkg-button.disabled { background: #ccc; cursor: not-allowed; }
        </style>';
    }
}

// تسجيل الـ Shortcode بشكل صحيح
add_shortcode('mon_packages', array('Mon_Event_Packages', 'mon_display_packages'));