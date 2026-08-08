<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Manual Package Activation — "التفعيل اليدوي للباقات من لوحة الإدارة" RFC
 * ============================================================================
 * أداة إدارية رسمية (Admin-only) بديلة عن Webhook سلة لحالات: الدعم الفني،
 * التعويض، عملاء VIP، الاختبار، تعافي فشل Webhook، نقل الاشتراك. **ليست**
 * بديلاً عن تكامل سلة، ولا تُعدِّل أي شيء في class-salla-handler.php.
 *
 * القيد المعماري الصارم (غير قابل للتفاوض): هذا الملف **لا يكتب أي
 * user_meta مباشرة** و**لا يحتوي أي منطق تفعيل جديد**. نقطة النهاية الوحيدة
 * التي تُنفِّذ تغييراً فعلياً (pge_manual_activation_activate) تستدعي حصراً:
 *   - Mon_Events_Users::activate_catalog_tier()  — لباقات Catalog.
 *   - Mon_Events_Users::activate_user_package()  — لباقات Legacy.
 * نفس الـService الحرفي الذي ينتهي إليه Webhook سلة (class-salla-handler.php
 * → process_catalog_match()/process_order_packages()) — راجع خريطة التدقيق
 * المعمارية (Phase 1) المُسلَّمة قبل أي تنفيذ. لا نسخ/لصق لمنطق الكتابة، لا
 * "نسخة مختصرة" — بالضبط نفس الاستدعاء.
 *
 * كل نقاط النهاية هنا محمية بـ current_user_can('manage_options') +
 * check_ajax_referer() — لا وصول لأي دور آخر (مضيف/مشرف/ضيف) إطلاقاً.
 */

/**
 * فحص صلاحية موحّد لكل نقاط نهاية هذا الملف — أدمن فقط + nonce صالح.
 * ينهي الطلب مباشرة عبر wp_send_json_error() عند الفشل (بنفس نمط
 * pge_mgmt_validate_request() المُستخدَم في بقية لوحات الإدارة بالمشروع).
 */
if (!function_exists('pge_manual_activation_guard')) {
    function pge_manual_activation_guard()
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'غير مصرح', 'reason' => 'forbidden']);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pge_manual_pkg_activation')) {
            wp_send_json_error(['message' => 'رمز الأمان غير صالح', 'reason' => 'invalid_nonce']);
        }
    }
}

/**
 * قراءة "هل لدى هذا المستخدم باقة فعالة حالياً؟" — قراءة meta فقط، لا كتابة.
 * تعمل على النظامين معاً لأن كليهما يكتب نفس مفتاح _mon_package_status.
 *
 * plan_id/tier_id (Manual Reactivation Fix): تُعادان فقط عندما يكون المصدر
 * الحالي 'catalog' والحالة 'active' — تُستخدَمان حصراً في نقطة نهاية التفعيل
 * لاكتشاف "إعادة تفعيل نفس Tier" (راجع wp_ajax_pge_manual_activation_activate
 * أدناه)؛ 0/0 في أي حالة أخرى (Legacy أو لا استحقاق فعال أصلاً).
 */
if (!function_exists('pge_manual_activation_get_current_status')) {
    function pge_manual_activation_get_current_status($user_id)
    {
        $user_id = (int) $user_id;
        $status = (string) get_user_meta($user_id, '_mon_package_status', true);
        $source = (string) get_user_meta($user_id, '_mon_package_source', true);
        $source = $source === 'catalog' ? 'catalog' : 'legacy';

        $label = '';
        $plan_id = 0;
        $tier_id = 0;
        if ($status === 'active') {
            if ($source === 'catalog') {
                $label = (string) get_user_meta($user_id, '_mon_catalog_tier_name', true);
                $plan_id = absint(get_user_meta($user_id, '_mon_catalog_plan_id', true));
                $tier_id = absint(get_user_meta($user_id, '_mon_catalog_tier_id', true));
            } else {
                $label = (string) get_user_meta($user_id, '_mon_package_name', true);
            }
        }

        return [
            'is_active' => $status === 'active',
            'status'    => $status,
            'source'    => $source,
            'label'     => $label,
            'plan_id'   => $plan_id,
            'tier_id'   => $tier_id,
        ];
    }
}

/**
 * 1) بحث المستخدم بالاسم أو البريد — WP_User_Query قياسية، بلا أي منطق عمل
 * جديد. لا يُقبَل إدخال معرّف (ID) خام من الواجهة كأساس بحث.
 */
add_action('wp_ajax_pge_manual_activation_search_users', function () {
    pge_manual_activation_guard();

    $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
    $term = trim($term);

    if (mb_strlen($term) < 2) {
        wp_send_json_success(['users' => []]);
    }

    $query = new WP_User_Query([
        'search'         => '*' . esc_attr($term) . '*',
        'search_columns' => ['user_login', 'user_email', 'display_name'],
        'number'         => 20,
        'orderby'        => 'display_name',
        'order'          => 'ASC',
    ]);

    $results = [];
    foreach ($query->get_results() as $user) {
        $status = pge_manual_activation_get_current_status($user->ID);
        $results[] = [
            'id'              => (int) $user->ID,
            'display_name'    => $user->display_name,
            'email'           => $user->user_email,
            'has_active'      => $status['is_active'],
            'active_label'    => $status['label'],
            'active_source'   => $status['source'],
        ];
    }

    wp_send_json_success(['users' => $results]);
});

/**
 * 2) قائمة الباقات — من نفس المصادر الحالية بالضبط، بلا أي قائمة جديدة:
 *    - Catalog: PGE_Catalog::get_plans() + get_plan_tiers() (حالة active فقط).
 *    - Legacy: get_option('mon_packages_settings') (نفس مصدر صفحة إعدادات الباقات).
 */
add_action('wp_ajax_pge_manual_activation_list_packages', function () {
    pge_manual_activation_guard();

    $options = [];

    if (class_exists('PGE_Catalog')) {
        $plans = PGE_Catalog::get_plans();
        if (is_array($plans)) {
            foreach ($plans as $plan) {
                if ((string) ($plan['status'] ?? '') !== 'active') {
                    continue;
                }
                $plan_id = (int) ($plan['id'] ?? 0);
                if ($plan_id <= 0) {
                    continue;
                }
                $tiers = PGE_Catalog::get_plan_tiers($plan_id);
                if (!is_array($tiers)) {
                    continue;
                }
                foreach ($tiers as $tier) {
                    if ((string) ($tier['status'] ?? '') !== 'active') {
                        continue;
                    }
                    $tier_id = (int) ($tier['id'] ?? 0);
                    if ($tier_id <= 0) {
                        continue;
                    }
                    $options[] = [
                        'source'  => 'catalog',
                        'plan_id' => $plan_id,
                        'tier_id' => $tier_id,
                        'label'   => sprintf(
                            'Catalog — %s / %s',
                            (string) ($plan['name'] ?? ''),
                            (string) ($tier['name'] ?? '')
                        ),
                    ];
                }
            }
        }
    }

    $legacy_plans = get_option('mon_packages_settings', []);
    if (is_array($legacy_plans)) {
        foreach ($legacy_plans as $plan_key => $details) {
            if (!is_array($details) || empty($details['name'])) {
                continue;
            }
            $options[] = [
                'source'   => 'legacy',
                'plan_key' => (string) $plan_key,
                'label'    => 'Legacy — ' . (string) $details['name'],
            ];
        }
    }

    wp_send_json_success(['packages' => $options]);
});

/**
 * 3) معاينة قبل التفعيل — قراءة فقط، بلا أي كتابة. تعرض بالضبط نفس القيم
 * التي سيُنشئها الاستدعاء الحقيقي للـService (لا حساب مختلف موازٍ).
 */
add_action('wp_ajax_pge_manual_activation_preview', function () {
    pge_manual_activation_guard();

    $source = isset($_POST['source']) ? sanitize_text_field(wp_unslash($_POST['source'])) : '';

    if ($source === 'catalog') {
        $plan_id = absint($_POST['plan_id'] ?? 0);
        $tier_id = absint($_POST['tier_id'] ?? 0);

        $plan = class_exists('PGE_Catalog') ? PGE_Catalog::get_plan($plan_id) : null;
        $tier = class_exists('PGE_Catalog') ? PGE_Catalog::get_tier($tier_id) : null;

        if (!$plan || !$tier || (int) ($tier['plan_id'] ?? 0) !== $plan_id) {
            wp_send_json_error(['message' => 'الباقة/المستوى غير موجود', 'reason' => 'not_found']);
        }

        $features_preview = [];
        if (class_exists('PGE_Feature_Registry') && class_exists('PGE_Tier_Features')) {
            $registry = PGE_Feature_Registry::all();
            $tier_rows = PGE_Tier_Features::get_all_tier_features($tier_id);
            $tier_values = [];
            if (is_array($tier_rows)) {
                foreach ($tier_rows as $row) {
                    $tier_values[(string) ($row['feature_key'] ?? '')] = (string) ($row['feature_value'] ?? '');
                }
            }
            foreach ($registry as $key => $def) {
                $raw = array_key_exists($key, $tier_values) ? $tier_values[$key] : (string) ($def['default'] ?? '');
                $features_preview[] = [
                    'key'   => $key,
                    'label' => (string) ($def['admin_label'] ?? $key),
                    'value' => $raw,
                ];
            }
        }

        wp_send_json_success([
            'source'                   => 'catalog',
            'name'                     => (string) ($plan['name'] ?? '') . ' — ' . (string) ($tier['name'] ?? ''),
            'plan_type'                => (string) ($plan['plan_type'] ?? ''),
            'guest_limit'              => $tier['guest_limit'] !== null ? (int) $tier['guest_limit'] : null,
            'events_count'             => isset($tier['events_count']) ? (int) $tier['events_count'] : null,
            'event_quota_mode'         => (string) ($tier['event_quota_mode'] ?? ''),
            'event_quota_limit'        => (int) ($tier['event_quota_limit'] ?? 0),
            'invitation_credit_limit'  => (int) ($tier['invitation_credit_limit'] ?? 0),
            'replacement_credit_limit' => (int) ($tier['replacement_credit_limit'] ?? 0),
            'price'                    => (string) ($tier['price'] ?? ''),
            'currency'                 => (string) ($tier['currency'] ?? ''),
            'features'                 => $features_preview,
        ]);
    }

    if ($source === 'legacy') {
        $plan_key = isset($_POST['plan_key']) ? sanitize_text_field(wp_unslash($_POST['plan_key'])) : '';
        $legacy_plans = get_option('mon_packages_settings', []);
        $details = is_array($legacy_plans) ? ($legacy_plans[$plan_key] ?? null) : null;

        if (!is_array($details) || empty($details['name'])) {
            wp_send_json_error(['message' => 'الباقة غير موجودة', 'reason' => 'not_found']);
        }

        $features_preview = [];
        foreach ($details as $key => $value) {
            if ($value == '1') {
                $features_preview[] = ['key' => (string) $key, 'label' => (string) $key, 'value' => '1'];
            }
        }

        wp_send_json_success([
            'source'       => 'legacy',
            'name'         => (string) $details['name'],
            'plan_type'    => 'legacy',
            'guest_limit'  => max(0, (int) ($details['guest_limit'] ?? 0)),
            'events_count' => max(1, (int) ($details['events_count'] ?? 1)),
            'host_photos'  => max(0, (int) ($details['host_photos'] ?? 0)),
            'wa_messages'  => max(0, (int) ($details['wa_messages'] ?? 0)),
            'features'     => $features_preview,
        ]);
    }

    wp_send_json_error(['message' => 'مصدر باقة غير معروف', 'reason' => 'invalid_source']);
});

/**
 * 4) التفعيل الفعلي — الاستدعاء الوحيد الذي يُحدِث تغييراً في البيانات.
 * يستدعي حصراً Mon_Events_Users::activate_catalog_tier() أو
 * Mon_Events_Users::activate_user_package() — لا كتابة user_meta هنا إطلاقاً.
 */
add_action('wp_ajax_pge_manual_activation_activate', function () {
    pge_manual_activation_guard();

    $target_user_id = absint($_POST['target_user_id'] ?? 0);
    $source = isset($_POST['source']) ? sanitize_text_field(wp_unslash($_POST['source'])) : '';
    $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
    $reason = trim($reason);
    $confirm_override = !empty($_POST['confirm_override']);

    if ($target_user_id <= 0 || !get_user_by('id', $target_user_id)) {
        wp_send_json_error(['message' => 'المستخدم غير موجود', 'reason' => 'invalid_user']);
    }

    if ($reason === '') {
        wp_send_json_error(['message' => 'سبب التفعيل اليدوي إلزامي', 'reason' => 'reason_required']);
    }

    if (!in_array($source, ['catalog', 'legacy'], true)) {
        wp_send_json_error(['message' => 'مصدر باقة غير معروف', 'reason' => 'invalid_source']);
    }

    // فحص الأمان: باقة فعالة حالياً تتطلب تأكيداً صريحاً قبل المتابعة.
    $current = pge_manual_activation_get_current_status($target_user_id);
    if ($current['is_active'] && !$confirm_override) {
        wp_send_json_error([
            'message'          => 'هذا المستخدم لديه باقة فعالة حالياً',
            'reason'           => 'active_package_exists',
            'requires_confirm' => true,
            'current_source'   => $current['source'],
            'current_label'    => $current['label'],
        ]);
    }

    $package_identifier = '';
    $result_ok = false;
    $error_message = '';

    if ($source === 'catalog') {
        $plan_id = absint($_POST['plan_id'] ?? 0);
        $tier_id = absint($_POST['tier_id'] ?? 0);

        $plan = class_exists('PGE_Catalog') ? PGE_Catalog::get_plan($plan_id) : null;
        $tier = class_exists('PGE_Catalog') ? PGE_Catalog::get_tier($tier_id) : null;
        if (!$plan || !$tier || (int) ($tier['plan_id'] ?? 0) !== $plan_id) {
            wp_send_json_error(['message' => 'الباقة/المستوى غير موجود', 'reason' => 'not_found']);
        }

        $package_identifier = 'plan_id:' . $plan_id . ' tier_id:' . $tier_id;

        // Manual Reactivation Fix: إعادة تفعيل نفس Tier فعلياً لنفس المستخدم
        // (نفس plan_id/tier_id الحاليين المخزَّنين له بالفعل — وهذا لا يصل
        // إلى هنا أصلاً إلا بعد تجاوز بوابة confirm_override أعلاه، أي أن
        // المسؤول أكَّد العملية صراحةً) ليست "تفعيلاً جديداً" تجارياً، بل
        // طلب تحديث الحقول الوصفية (مثل guest_limit) لتطابق آخر تعديل على
        // تعريف الـTier نفسه. activate_catalog_tier() تحتوي حارس Idempotency
        // مصمَّم لحماية Webhook سلة من إعادة تسليم نفس الطلب — عند التفعيل
        // اليدوي (external_order_id فارغ دائماً) هذا الحارس يتطابق خطأً مع
        // "نفس الطلب" فيرجع true فوراً بلا كتابة أي شيء، فتبقى القيم القديمة
        // معروضة. الحل: عند اكتشاف تطابق Tier الحالي فعلياً، نستدعي
        // refresh_catalog_tier_snapshot() بدلاً من activate_catalog_tier() —
        // تُحدِّث الحقول الوصفية فقط ولا تلمس رصيد الدعوات/البديل ولا
        // credit_cycle_id ولا _mon_last_order_id إطلاقاً (راجع تعليقاتها في
        // class-mon-events-users.php). لا تغيير على activate_catalog_tier()
        // نفسها ولا على حارسها — سلوك Webhook سلة يبقى كما هو تماماً.
        $is_same_tier_reactivation = (
            $current['is_active']
            && $current['source'] === 'catalog'
            && $current['plan_id'] === $plan_id
            && $current['tier_id'] === $tier_id
        );

        $activation_result = $is_same_tier_reactivation
            ? Mon_Events_Users::refresh_catalog_tier_snapshot($target_user_id)
            : Mon_Events_Users::activate_catalog_tier($target_user_id, $plan_id, $tier_id, '');

        if (is_wp_error($activation_result)) {
            $error_message = $activation_result->get_error_message();
        } else {
            $result_ok = (bool) $activation_result;
        }
    } elseif ($source === 'legacy') {
        $plan_key = isset($_POST['plan_key']) ? sanitize_text_field(wp_unslash($_POST['plan_key'])) : '';
        $legacy_plans = get_option('mon_packages_settings', []);
        if (!is_array($legacy_plans) || empty($legacy_plans[$plan_key]['name'])) {
            wp_send_json_error(['message' => 'الباقة غير موجودة', 'reason' => 'not_found']);
        }

        $package_identifier = 'plan_key:' . $plan_key;

        $user = get_user_by('id', $target_user_id);

        // نفس الاستدعاء الحرفي الذي يستخدمه process_order_packages() في
        // class-salla-handler.php عند مطابقة Legacy — order_id يُميَّز صراحة
        // كتفعيل يدوي (بادئة MANUAL-) بدلاً من رقم طلب سلة حقيقي، لأنه غير موجود.
        $response = Mon_Events_Users::activate_user_package($user->user_email, [
            'plan_key' => $plan_key,
            'order_id' => 'MANUAL-' . current_time('timestamp') . '-' . get_current_user_id(),
        ]);

        if ($response instanceof WP_REST_Response) {
            $status_code = $response->get_status();
            $data = $response->get_data();
            if ($status_code === 200) {
                $result_ok = true;
            } else {
                $error_message = is_array($data) && !empty($data['message']) ? (string) $data['message'] : 'فشل التفعيل';
            }
        } else {
            $error_message = 'استجابة غير متوقعة من خدمة التفعيل';
        }
    }

    if (!$result_ok) {
        wp_send_json_error(['message' => $error_message !== '' ? $error_message : 'فشل التفعيل', 'reason' => 'activation_failed']);
    }

    // سجل تدقيق مستقل — بعد نجاح التفعيل الحقيقي فقط، لا بيانات حساسة.
    if (class_exists('PGE_Manual_Package_Activation_Audit')) {
        PGE_Manual_Package_Activation_Audit::record(
            $target_user_id,
            get_current_user_id(),
            $source,
            $package_identifier,
            $current['is_active'],
            $reason
        );
    }

    wp_send_json_success([
        'message' => 'تم تفعيل الباقة بنجاح',
        'user_id' => $target_user_id,
        'source'  => $source,
    ]);
});
