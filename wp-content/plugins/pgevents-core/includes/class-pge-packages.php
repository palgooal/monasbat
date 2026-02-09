<?php
if (!defined('ABSPATH')) exit;

class PGE_Packages
{
    /**
     * جلب حدود الباقة من الإعدادات المخزنة
     */
    public static function get_package_settings($plan_id)
    {
        $settings = get_option('mon_packages_settings', self::get_default_plans());
        // التأكد من أن الإعدادات مصفوفة
        if (!is_array($settings)) $settings = self::get_default_plans();

        return isset($settings[$plan_id]) ? $settings[$plan_id] : $settings['plan_1'];
    }

    /**
     * جلب حدود باقة المستخدم الحالي
     */
    public static function get_user_plan_limits($user_id)
    {
        $plan_id = get_user_meta($user_id, 'pge_current_plan', true);
        if (!$plan_id) {
            $plan_id = get_user_meta($user_id, '_mon_package_key', true);
        }
        if (!$plan_id) {
            $plan_id = 'plan_1';
        }

        $limits = self::get_package_settings($plan_id);

        $events_limit = get_user_meta($user_id, '_mon_events_limit', true);
        if ($events_limit !== '') {
            $limits['events_count'] = (int) $events_limit;
        }

        $guest_limit = get_user_meta($user_id, '_mon_guest_limit', true);
        if ($guest_limit !== '') {
            $limits['guest_limit'] = (int) $guest_limit;
        }

        $host_photos_limit = get_user_meta($user_id, '_mon_host_photos_limit', true);
        if ($host_photos_limit !== '') {
            $limits['host_photos'] = (int) $host_photos_limit;
        }

        $wa_limit = get_user_meta($user_id, '_mon_wa_limit', true);
        if ($wa_limit !== '') {
            $limits['wa_messages'] = (int) $wa_limit;
        }

        $active_features = get_user_meta($user_id, '_mon_active_features', true);
        if (is_array($active_features)) {
            $active_features = array_map('strval', $active_features);
            foreach (self::get_feature_keys() as $feature_key) {
                $limits[$feature_key] = in_array($feature_key, $active_features, true) ? 1 : 0;
            }
        }

        return is_array($limits) ? $limits : [];
    }

    /**
     * القيم الافتراضية (Fallback) لضمان عدم اختفاء الباقات أبداً
     */
    public static function get_feature_keys()
    {
        return [
            'header_img',
            'event_barcode',
            'event_date',
            'countdown',
            'google_map',
            'stc_pay',
            'guest_photos',
            'guest_video',
            'public_chat',
            'private_chat',
            'prev_events',
            'next_events',
            'guest_history',
            'archive',
        ];
    }

    public static function is_feature_enabled($limits, $key)
    {
        if (!is_array($limits) || $key === '' || !array_key_exists($key, $limits)) {
            return false;
        }

        $value = $limits[$key];
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return ((int) $value) === 1;

        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'on', 'yes', 'true'], true);
    }

    public static function get_default_plans()
    {
        return [
            'plan_1' => ['name' => 'الباقة الأساسية', 'price' => '49',  'guest_limit' => 15,  'host_photos' => 10, 'events_count' => 1, 'salla_url' => '#', 'color' => 'blue'],
            'plan_2' => ['name' => 'الباقة الفضية',   'price' => '149', 'guest_limit' => 50,  'host_photos' => 25, 'events_count' => 3, 'salla_url' => '#', 'color' => 'purple'],
            'plan_3' => ['name' => 'الباقة الذهبية',   'price' => '299', 'guest_limit' => 200, 'host_photos' => 50, 'events_count' => 10, 'salla_url' => '#', 'color' => 'amber'],
            'plan_4' => ['name' => 'الباقة الماسية',   'price' => '450', 'guest_limit' => 500, 'host_photos' => 70, 'events_count' => 20, 'salla_url' => '#', 'color' => 'emerald'],
        ];
    }

    /**
     * عرض الباقات بتصميم عصري
     */
    public static function display_packages()
    {
        // 1. محاولة جلب البيانات من الخيارات
        $stored_plans = get_option('mon_packages_settings');

        // 2. التحقق: إذا كانت البيانات فارغة أو ليست مصفوفة، استخدم الافتراضي
        if (empty($stored_plans) || !is_array($stored_plans)) {
            $plans = self::get_default_plans();
        } else {
            $plans = $stored_plans;
        }

        $user_id = get_current_user_id();
        $current_plan = get_user_meta($user_id, 'pge_current_plan', true);
        if (!$current_plan) {
            $current_plan = get_user_meta($user_id, '_mon_package_key', true);
        }
        if (!$current_plan) {
            $current_plan = 'plan_1';
        }
        $colors = ['plan_1' => 'blue', 'plan_2' => 'purple', 'plan_3' => 'amber', 'plan_4' => 'emerald'];

        ob_start(); ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 py-10 text-right" dir="rtl">
            <?php foreach ($plans as $id => $pkg):
                if (empty($pkg['name'])) continue;

                $is_current = ($current_plan === $id);
                $color = $colors[$id] ?? 'blue';
                $check = '<i class="fas fa-check-circle text-green-500 text-lg"></i>';
                $cross = '<i class="fas fa-times-circle text-red-300 text-lg"></i>';
            ?>
                <div class="relative bg-white border-2 <?php echo $is_current ? "border-{$color}-500 shadow-2xl scale-105 z-10" : 'border-gray-100 shadow-sm'; ?> rounded-3xl p-6 transition-all hover:shadow-lg flex flex-col hover:-translate-y-1">

                    <?php if ($is_current): ?>
                        <span class="absolute -top-4 right-1/2 translate-x-1/2 bg-<?php echo $color; ?>-500 text-white px-6 py-1 rounded-full text-xs font-bold shadow-lg">باقتك الحالية</span>
                    <?php endif; ?>

                    <div class="mb-6 text-center">
                        <h3 class="text-xl font-bold text-gray-800"><?php echo esc_html($pkg['name']); ?></h3>
                        <div class="mt-3 inline-block bg-gray-50 px-4 py-2 rounded-2xl">
                            <span class="text-4xl font-black text-gray-900"><?php echo esc_html($pkg['price']); ?></span>
                            <span class="text-gray-500 text-sm font-bold">ريال</span>
                        </div>
                    </div>

                    <ul class="space-y-4 mb-8 text-sm flex-grow">
                        <li class="flex justify-between items-center border-b border-gray-50 pb-2">
                            <span class="text-gray-600"><i class="fas fa-users w-6 text-gray-400"></i> عدد المدعوين</span>
                            <span class="font-black text-gray-800"><?php echo esc_html($pkg['guest_limit'] ?? 0); ?></span>
                        </li>
                        <li class="flex justify-between items-center border-b border-gray-50 pb-2">
                            <span class="text-gray-600"><i class="fas fa-calendar-check w-6 text-gray-400"></i> المناسبات</span>
                            <span class="font-black text-gray-800"><?php echo esc_html($pkg['events_count'] ?? 1); ?></span>
                        </li>
                        <li class="flex justify-between items-center border-b border-gray-50 pb-2">
                            <span class="text-gray-600"><i class="fas fa-camera w-6 text-gray-400"></i> صور المضيف</span>
                            <span class="font-black text-gray-800"><?php echo esc_html($pkg['host_photos'] ?? 0); ?></span>
                        </li>

                        <li class="flex justify-between items-center border-b border-gray-50 pb-2">
                            <span class="text-gray-600"><i class="fas fa-comments w-6 text-gray-400"></i> دردشة عامة</span>
                            <span><?php echo (isset($pkg['public_chat']) && ($pkg['public_chat'] == 'on' || $pkg['public_chat'] == 1)) ? $check : $cross; ?></span>
                        </li>
                        <li class="flex justify-between items-center border-b border-gray-50 pb-2">
                            <span class="text-gray-600"><i class="fas fa-map-marked-alt w-6 text-gray-400"></i> قوقل ماب</span>
                            <span><?php echo (isset($pkg['google_map']) && ($pkg['google_map'] == 'on' || $pkg['google_map'] == 1)) ? $check : $cross; ?></span>
                        </li>
                        <li class="flex justify-between items-center border-b border-gray-50 pb-2">
                            <span class="text-gray-600"><i class="fas fa-money-bill-wave w-6 text-gray-400"></i> هدايا STCPay</span>
                            <span><?php echo (isset($pkg['stc_pay']) && ($pkg['stc_pay'] == 'on' || $pkg['stc_pay'] == 1)) ? $check : $cross; ?></span>
                        </li>

                        <li class="flex justify-between items-center pt-2">
                            <span class="text-gray-500 font-bold leading-tight"><i class="fab fa-whatsapp w-6 text-green-500"></i> رسائل واتساب</span>
                            <span class="bg-green-50 text-green-700 px-2 py-1 rounded-lg font-black"><?php echo esc_html($pkg['wa_messages'] ?? 0); ?></span>
                        </li>
                    </ul>

                    <a href="<?php echo esc_url($pkg['salla_url'] ?? '#'); ?>"
                        class="block text-center w-full py-4 rounded-2xl font-bold transition-all shadow-md <?php echo $is_current ? 'bg-gray-100 text-gray-400 cursor-default' : "bg-{$color}-600 text-white hover:bg-{$color}-700 hover:shadow-{$color}-200"; ?>">
                        <?php echo $is_current ? 'باقتك الحالية' : 'ترقية الآن عبر سلة'; ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

<?php
        return ob_get_clean();
    }
}
add_shortcode('pge_packages', ['PGE_Packages', 'display_packages']);
