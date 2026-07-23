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

            // 3. تخزين "الحدود والكميات" — max() يضمن عدم تخزين 0 في events_count بسبب حقل فارغ
            update_user_meta($user->ID, '_mon_guest_limit',       max(0, (int)($plan_details['guest_limit']  ?? 0)));
            update_user_meta($user->ID, '_mon_host_photos_limit', max(0, (int)($plan_details['host_photos']  ?? 0)));
            update_user_meta($user->ID, '_mon_events_limit',      max(1, (int)($plan_details['events_count'] ?? 1)));
            update_user_meta($user->ID, '_mon_wa_limit',          max(0, (int)($plan_details['wa_messages']  ?? 0)));

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

    /**
     * تفعيل استحقاق مستوى من Catalog وحفظ Snapshot مستقل عن إعدادات Legacy.
     *
     * @return true|WP_Error
     */
    public static function activate_catalog_tier($user_id, $plan_id, $tier_id, $external_order_id = '')
    {
        $user_id = self::normalize_positive_id($user_id);
        if ($user_id === 0) {
            return new WP_Error('invalid_user_id', 'معرّف المستخدم غير صالح.');
        }

        if (!get_user_by('id', $user_id)) {
            return new WP_Error('user_not_found', 'تعذر العثور على المستخدم.');
        }

        $plan_id = self::normalize_positive_id($plan_id);
        if ($plan_id === 0) {
            return new WP_Error('invalid_plan_id', 'معرّف الباقة غير صالح.');
        }

        $tier_id = self::normalize_positive_id($tier_id);
        if ($tier_id === 0) {
            return new WP_Error('invalid_tier_id', 'معرّف المستوى غير صالح.');
        }

        if (!class_exists('PGE_Catalog')) {
            return new WP_Error('catalog_unavailable', 'كتالوج الباقات غير متاح حاليًا.');
        }

        $plan = PGE_Catalog::get_plan($plan_id);
        if (!is_array($plan)) {
            return new WP_Error('plan_not_found', 'تعذر العثور على الباقة المطلوبة.');
        }

        if (($plan['status'] ?? '') !== 'active') {
            return new WP_Error('inactive_plan', 'الباقة المطلوبة غير نشطة.');
        }

        $tier = PGE_Catalog::get_tier($tier_id);
        if (!is_array($tier)) {
            return new WP_Error('tier_not_found', 'تعذر العثور على مستوى الباقة المطلوب.');
        }

        if (absint($tier['plan_id'] ?? 0) !== absint($plan['id'] ?? 0)) {
            return new WP_Error('tier_plan_mismatch', 'مستوى الباقة لا يتبع الباقة المطلوبة.');
        }

        if (($tier['status'] ?? '') !== 'active') {
            return new WP_Error('inactive_tier', 'مستوى الباقة المطلوب غير نشط.');
        }

        $price = trim((string) ($tier['price'] ?? ''));
        if ($price === '' || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $price)) {
            return new WP_Error('invalid_price', 'سعر مستوى الباقة غير صالح.');
        }

        $currency = sanitize_text_field((string) ($tier['currency'] ?? ''));
        if ($currency === '') {
            return new WP_Error('invalid_currency', 'عملة مستوى الباقة غير صالحة.');
        }

        $plan_key = sanitize_key((string) ($plan['plan_key'] ?? ''));
        if ($plan_key === '') {
            return new WP_Error('invalid_plan_key', 'مفتاح الباقة غير صالح.');
        }

        $guest_limit = $tier['guest_limit'] ?? null;
        if ($guest_limit !== null) {
            $guest_limit = is_string($guest_limit) ? trim($guest_limit) : $guest_limit;
            if (
                !(is_int($guest_limit) && $guest_limit >= 0)
                && !(is_string($guest_limit) && preg_match('/^[0-9]+$/', $guest_limit))
            ) {
                return new WP_Error('invalid_guest_limit', 'حد المدعوين في مستوى الباقة غير صالح.');
            }
            $guest_limit = absint($guest_limit);
        }

        $external_order_id = is_scalar($external_order_id)
            ? trim(sanitize_text_field((string) $external_order_id))
            : '';

        $current_source = (string) get_user_meta($user_id, '_mon_package_source', true);
        $current_status = (string) get_user_meta($user_id, '_mon_package_status', true);
        $current_plan_id = absint(get_user_meta($user_id, '_mon_catalog_plan_id', true));
        $current_tier_id = absint(get_user_meta($user_id, '_mon_catalog_tier_id', true));
        $current_order_id = (string) get_user_meta($user_id, '_mon_last_order_id', true);

        if (
            $current_source === 'catalog'
            && $current_status === 'active'
            && $current_plan_id === $plan_id
            && $current_tier_id === $tier_id
            && $current_order_id === $external_order_id
        ) {
            return true;
        }

        $features = self::normalize_catalog_features($plan['features'] ?? null);
        $snapshot = [
            '_mon_package_source'      => 'catalog',
            '_mon_catalog_plan_id'     => $plan_id,
            '_mon_catalog_tier_id'     => $tier_id,
            '_mon_catalog_plan_key'    => $plan_key,
            '_mon_catalog_plan_name'   => sanitize_text_field((string) ($plan['name'] ?? '')),
            '_mon_catalog_tier_key'    => sanitize_key((string) ($tier['tier_key'] ?? '')),
            '_mon_catalog_tier_name'   => sanitize_text_field((string) ($tier['name'] ?? '')),
            '_mon_package_status'      => 'active',
            '_mon_package_activated_at'=> current_time('mysql', true),
            '_mon_package_price'       => $price,
            '_mon_package_currency'    => $currency,
            // القيمة الفارغة تمثل NULL في Catalog بوضوح، ولا تتحول إلى صفر.
            '_mon_guest_limit'         => $guest_limit === null ? '' : $guest_limit,
            '_mon_salla_product_id'    => sanitize_text_field((string) ($tier['salla_product_id'] ?? '')),
            '_mon_catalog_features'    => $features,
        ];

        if ($external_order_id !== '') {
            $snapshot['_mon_last_order_id'] = $external_order_id;
        }

        foreach ($snapshot as $meta_key => $meta_value) {
            if (!self::update_user_meta_safely($user_id, $meta_key, $meta_value)) {
                return new WP_Error('meta_update_failed', 'تعذر حفظ استحقاق الباقة للمستخدم.');
            }
        }

        if ($external_order_id === '') {
            delete_user_meta($user_id, '_mon_last_order_id');
        }
        delete_user_meta($user_id, '_mon_package_deactivated_at');

        return true;
    }

    /**
     * إلغاء استحقاق Catalog فقط مع الإبقاء على Snapshot المحفوظ.
     *
     * @return true|WP_Error
     */
    public static function deactivate_catalog_tier($user_id, $external_order_id = '')
    {
        $user_id = self::normalize_positive_id($user_id);
        if ($user_id === 0) {
            return new WP_Error('invalid_user_id', 'معرّف المستخدم غير صالح.');
        }

        if (!get_user_by('id', $user_id)) {
            return new WP_Error('user_not_found', 'تعذر العثور على المستخدم.');
        }

        if ((string) get_user_meta($user_id, '_mon_package_source', true) !== 'catalog') {
            return new WP_Error('not_catalog_entitlement', 'لا يملك المستخدم استحقاق باقة من Catalog.');
        }

        $external_order_id = is_scalar($external_order_id)
            ? trim(sanitize_text_field((string) $external_order_id))
            : '';

        if (
            $external_order_id !== ''
            && (string) get_user_meta($user_id, '_mon_last_order_id', true) !== $external_order_id
        ) {
            return new WP_Error('order_mismatch', 'رقم الطلب لا يطابق طلب الاستحقاق الحالي.');
        }

        if ((string) get_user_meta($user_id, '_mon_package_status', true) === 'expired') {
            return true;
        }

        if (!self::update_user_meta_safely($user_id, '_mon_package_status', 'expired')) {
            return new WP_Error('meta_update_failed', 'تعذر إلغاء استحقاق الباقة للمستخدم.');
        }

        if (!self::update_user_meta_safely($user_id, '_mon_package_deactivated_at', current_time('mysql', true))) {
            return new WP_Error('meta_update_failed', 'تعذر حفظ وقت إلغاء استحقاق الباقة.');
        }

        return true;
    }

    private static function normalize_positive_id($value)
    {
        if (is_int($value)) {
            return $value > 0 ? absint($value) : 0;
        }

        if (is_string($value)) {
            $value = trim($value);
            return preg_match('/^[1-9][0-9]*$/', $value) ? absint($value) : 0;
        }

        return 0;
    }

    private static function normalize_catalog_features($raw_features)
    {
        if (is_string($raw_features)) {
            $raw_features = trim($raw_features);
            $decoded = $raw_features === '' ? [] : json_decode($raw_features, true);
            $raw_features = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($raw_features)) {
            $raw_features = [];
        }

        $features = [];
        foreach ($raw_features as $feature) {
            if (!is_scalar($feature)) {
                continue;
            }

            $feature = trim(sanitize_text_field((string) $feature));
            if ($feature !== '') {
                $features[] = $feature;
            }
        }

        return array_values($features);
    }

    private static function update_user_meta_safely($user_id, $meta_key, $meta_value)
    {
        if (
            metadata_exists('user', $user_id, $meta_key)
            && self::meta_values_match(get_user_meta($user_id, $meta_key, true), $meta_value)
        ) {
            return true;
        }

        $updated = update_user_meta($user_id, $meta_key, $meta_value);
        if ($updated !== false) {
            return true;
        }

        return metadata_exists('user', $user_id, $meta_key)
            && self::meta_values_match(get_user_meta($user_id, $meta_key, true), $meta_value);
    }

    private static function meta_values_match($stored_value, $expected_value)
    {
        if (is_array($expected_value)) {
            return is_array($stored_value) && $stored_value === $expected_value;
        }

        return (string) $stored_value === (string) $expected_value;
    }
}
