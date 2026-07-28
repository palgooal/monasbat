<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('pge_normalize_invite_code')) {
    function pge_normalize_invite_code($code)
    {
        $code = strtoupper(trim((string) $code));
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        if ($code === '') return '';

        $code = substr($code, 0, 8);
        if (strlen($code) > 4) {
            $code = substr($code, 0, 4) . '-' . substr($code, 4);
        }

        return $code;
    }
}

if (!function_exists('pge_generate_invite_code')) {
    function pge_generate_invite_code()
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $raw = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < 8; $i++) {
            $raw .= $chars[random_int(0, $max)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }
}

if (!function_exists('pge_handle_featured_image_upload')) {
    function pge_handle_featured_image_upload($field_name, $post_id)
    {
        if (empty($_FILES[$field_name]) || !is_array($_FILES[$field_name])) {
            return 0;
        }

        $file = $_FILES[$field_name];
        $filename = isset($file['name']) ? (string) $file['name'] : '';
        if ($filename === '') {
            return 0;
        }

        $error_code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error_code === UPLOAD_ERR_NO_FILE) {
            return 0;
        }

        if ($error_code !== UPLOAD_ERR_OK) {
            return new WP_Error('pge_featured_image_upload_error', 'تعذر رفع الصورة البارزة. حاول مرة أخرى.');
        }

        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachment_id = media_handle_upload($field_name, $post_id, [], ['test_form' => false]);
        if (is_wp_error($attachment_id)) {
            return new WP_Error('pge_featured_image_upload_error', $attachment_id->get_error_message());
        }

        set_post_thumbnail($post_id, (int) $attachment_id);
        return (int) $attachment_id;
    }
}

if (!function_exists('pge_get_user_plan_limits_for_events')) {
    /**
     * نقطة الدخول الموحّدة لحدود/صلاحيات المستخدم — تبقى نفس الاسم وشكل
     * المخرجات كما كانت (events_count, guest_limit, host_photos, wa_messages
     * + مفاتيح الميزات كـ 0/1)، لكنها الآن تتفرّع أولاً حسب مصدر الباقة:
     *
     * - إن كان _mon_package_source === 'catalog': يُستخدم مسار Catalog حصراً
     *   (pge_get_catalog_user_plan_limits_for_events) — بلا أي قراءة أو دمج
     *   مع مفاتيح Legacy إطلاقاً، حتى لو كانت موجودة لدى نفس المستخدم.
     * - أي قيمة أخرى لـ _mon_package_source (بما فيها الفراغ لمستخدمي
     *   Legacy القدامى الذين لم يُكتب لهم هذا المفتاح أصلاً): يستمر مسار
     *   Legacy الحالي دون أي تغيير في السلوك أو الترتيب.
     */
    function pge_get_user_plan_limits_for_events($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) return [];

        $package_source = (string) get_user_meta($user_id, '_mon_package_source', true);
        if ($package_source === 'catalog') {
            return pge_get_catalog_user_plan_limits_for_events($user_id);
        }

        $plan_key = (string) get_user_meta($user_id, '_mon_package_key', true);
        if ($plan_key === '') {
            $plan_key = (string) get_user_meta($user_id, 'pge_current_plan', true);
        }

        $active_features = get_user_meta($user_id, '_mon_active_features', true);
        $has_active_features = is_array($active_features) && !empty($active_features);
        $has_plan_context = ($plan_key !== '') || $has_active_features;

        if ($has_plan_context && class_exists('PGE_Packages')) {
            $limits = (array) PGE_Packages::get_user_plan_limits($user_id);
            if (!empty($limits)) {
                // إضافة مفاتيح رصيد الدعوات الستة بصفر فقط — بنية إرجاع
                // موحّدة عبر كل المسارات (Catalog وLegacy)، دون أي تغيير في
                // أي قيمة Legacy فعلية موجودة مسبقاً في $limits (اتحاد "+"
                // لا يستبدل مفتاحاً موجوداً، ويضيف فقط المفاتيح الغائبة).
                // Legacy لا يملك مفهوم رصيد دعوات إطلاقاً في هذه المرحلة —
                // القيم هنا صفرية دائماً وليست حداً فعلياً يُنفَّذ عليه شيء.
                return $limits + pge_zero_invitation_credit_limits();
            }
        }

        $limits = [];
        $limits['events_count'] = (int) get_user_meta($user_id, '_mon_events_limit', true);
        $limits['guest_limit'] = (int) get_user_meta($user_id, '_mon_guest_limit', true);
        $limits['host_photos'] = (int) get_user_meta($user_id, '_mon_host_photos_limit', true);
        $limits['wa_messages'] = (int) get_user_meta($user_id, '_mon_wa_limit', true);

        if (is_array($active_features)) {
            foreach ($active_features as $feature_key) {
                $limits[(string) $feature_key] = 1;
            }
        }

        return $limits + pge_zero_invitation_credit_limits();
    }
}

if (!function_exists('pge_zero_invitation_credit_limits')) {
    /**
     * القيم الست الصفرية لحقول رصيد الدعوات، تُستخدم في كل نقطة إرجاع لا
     * تملك رصيداً فعلياً (Legacy بكل مساريه، وCatalog غير النشط/الناقص).
     * دالة واحدة صغيرة بدل تكرار نفس المصفوفة الحرفية في أكثر من مكان —
     * أي تعديل مستقبلي لأسماء هذه المفاتيح يحتاج تغييراً في مكان واحد فقط.
     */
    function pge_zero_invitation_credit_limits()
    {
        return [
            'invitation_credit_total'      => 0,
            'invitation_credit_used'       => 0,
            'invitation_credit_remaining'  => 0,
            'replacement_credit_total'     => 0,
            'replacement_credit_used'      => 0,
            'replacement_credit_remaining' => 0,
        ];
    }
}

if (!function_exists('pge_get_catalog_user_plan_limits_for_events')) {
    /**
     * مسار Catalog المعزول تماماً عن Legacy. تُستدعى فقط من داخل
     * pge_get_user_plan_limits_for_events() عندما يكون
     * _mon_package_source === 'catalog'. ممنوع هنا أي قراءة لمفاتيح Legacy
     * (لا _mon_package_key، لا _mon_active_features، لا pge_current_plan) —
     * القرار المعماري الحاسم هو عدم دمج المصدرين إطلاقاً لنفس المستخدم.
     *
     * عقد المخرجات مطابق تماماً لما يعيده المسار Legacy: نفس المفاتيح
     * (events_count, guest_limit, host_photos, wa_messages) ونفس مفاتيح
     * الميزات (0/1) التي تقرأها pge_plan_feature_enabled_for_events() لاحقاً.
     *
     * ملاحظة تصميم مهمة (راجع أيضاً تعليق activate_catalog_tier() حول
     * القيمة الفارغة الممثِّلة لـNULL): أعمدة events_count/host_photos_limit/
     * wa_messages_limit في mon_plan_tiers هي INT UNSIGNED NULL — أي أن NULL
     * محتمل معمارياً (احتمال دعم "غير محدود" مستقبلاً). لا يوجد حالياً أي
     * تمثيل فعلي لـ"غير محدود" في أي مكان من نظام Legacy الحالي (كل حدوده
     * أرقام صريحة، وأي قيمة meta فارغة تُحوَّل بالفعل إلى 0 عبر (int) في كل
     * نقاط الاستخدام الحالية). لذلك، وتفادياً لاختراع قيمة جديدة غير موجودة
     * في النظام، تُعامَل قيمة NULL هنا كصفر (0) تماماً كما يُعامَل أي حد
     * Legacy غائب — وهذا سلوك آمن (يمنع الوصول بدل أن يسمح به خطأً) وليس
     * افتراضاً بأن "غير محدود = رقم عشوائي". إن احتاج المنتج لاحقاً لدعم
     * "غير محدود" فعلياً فهذا قرار تصميم منفصل يتطلب تعديل نقاط الاستهلاك
     * أيضاً (event-factory.php وغيره تقارن حالياً بـ >= رقم صريح)، خارج نطاق
     * هذه المرحلة.
     */
    function pge_get_catalog_user_plan_limits_for_events($user_id)
    {
        $limits = [
            'events_count' => 0,
            'guest_limit'  => 0,
            'host_photos'  => 0,
            'wa_messages'  => 0,
        ] + pge_zero_invitation_credit_limits();

        if (class_exists('PGE_Packages')) {
            foreach (PGE_Packages::get_feature_keys() as $feature_key) {
                $limits[(string) $feature_key] = 0;
            }
        }

        // البوابة الحاسمة: حالة غير active تعني صلاحيات صفرية آمنة، بلا أي
        // رجوع لبيانات Legacy مهما كانت موجودة لدى نفس المستخدم.
        $status = (string) get_user_meta($user_id, '_mon_package_status', true);
        if ($status !== 'active') {
            return $limits;
        }

        // guest_limit: مصدره الوحيد Snapshot _mon_guest_limit المكتوب وقت
        // activate_catalog_tier() — لا حاجة لأي استعلام إضافي هنا. القيمة
        // الفارغة (NULL في tier وقت التفعيل) تُعامَل كـ0 (انظر ملاحظة أعلى
        // الدالة).
        $guest_limit_meta = get_user_meta($user_id, '_mon_guest_limit', true);
        if ($guest_limit_meta !== '' && $guest_limit_meta !== false) {
            $limits['guest_limit'] = (int) $guest_limit_meta;
        }

        // رصيد الدعوات (الأساسي والبديل) — يُقرأ حصراً من Snapshot المستخدم
        // (المفاتيح الأربعة المكتوبة داخل activate_catalog_tier()، تماماً
        // كـ_mon_guest_limit أعلاه)، ولا يُستخدم tier.invitation_credit_limit/
        // tier.replacement_credit_limit هنا كـfallback إطلاقاً حتى لو كان
        // الـSnapshot ناقصاً أو غائباً — القرار المعماري الصريح لهذه المرحلة:
        // "Snapshot هو مصدر حدود الاشتراك الفعلي بعد التفعيل"، بخلاف
        // events_count/host_photos/wa_messages أسفل هذا الكتلة التي تُقرأ من
        // صف الـtier الحي لأنها لا تُخزَّن كـSnapshot أصلاً. قراءة آمنة: أي
        // قيمة meta غير رقمية (array تالفة، نص غير رقمي، فارغة) تُعامَل كصفر
        // بدل أي Warning/Fatal — is_numeric() لا تُصدر أي تحذير على array.
        $invitation_credit_total_meta  = get_user_meta($user_id, '_mon_invitation_credit_total', true);
        $invitation_credit_used_meta   = get_user_meta($user_id, '_mon_invitation_credit_used', true);
        $replacement_credit_total_meta = get_user_meta($user_id, '_mon_replacement_credit_total', true);
        $replacement_credit_used_meta  = get_user_meta($user_id, '_mon_replacement_credit_used', true);

        $limits['invitation_credit_total'] = is_numeric($invitation_credit_total_meta)
            ? max(0, (int) $invitation_credit_total_meta)
            : 0;
        $limits['invitation_credit_used'] = is_numeric($invitation_credit_used_meta)
            ? max(0, (int) $invitation_credit_used_meta)
            : 0;
        $limits['invitation_credit_remaining'] = max(0, $limits['invitation_credit_total'] - $limits['invitation_credit_used']);

        $limits['replacement_credit_total'] = is_numeric($replacement_credit_total_meta)
            ? max(0, (int) $replacement_credit_total_meta)
            : 0;
        $limits['replacement_credit_used'] = is_numeric($replacement_credit_used_meta)
            ? max(0, (int) $replacement_credit_used_meta)
            : 0;
        $limits['replacement_credit_remaining'] = max(0, $limits['replacement_credit_total'] - $limits['replacement_credit_used']);

        // بقية الحدود (events_count/host_photos/wa_messages) غير مخزَّنة في
        // أي User Meta من Catalog، فمصدرها الوحيد صف الـ tier نفسه.
        $catalog_plan_id = absint(get_user_meta($user_id, '_mon_catalog_plan_id', true));
        $catalog_tier_id = absint(get_user_meta($user_id, '_mon_catalog_tier_id', true));

        if ($catalog_tier_id > 0 && class_exists('PGE_Catalog')) {
            $tier = PGE_Catalog::get_tier($catalog_tier_id);

            // فحص اتساق دفاعي: إن كان الـ tier موجوداً لكنه لا يتبع نفس
            // الباقة المخزَّنة لدى المستخدم، تُعتبر البيانات غير موثوقة
            // ويُكتفى بالحدود الصفرية الآمنة بدل قراءة أرقام قد لا تخص
            // اشتراك المستخدم فعلياً.
            if (is_array($tier) && $catalog_plan_id > 0 && absint($tier['plan_id'] ?? 0) !== $catalog_plan_id) {
                $tier = null;
            }

            if (is_array($tier)) {
                if (array_key_exists('events_count', $tier) && $tier['events_count'] !== null) {
                    $limits['events_count'] = (int) $tier['events_count'];
                }
                if (array_key_exists('host_photos_limit', $tier) && $tier['host_photos_limit'] !== null) {
                    $limits['host_photos'] = (int) $tier['host_photos_limit'];
                }
                if (array_key_exists('wa_messages_limit', $tier) && $tier['wa_messages_limit'] !== null) {
                    $limits['wa_messages'] = (int) $tier['wa_messages_limit'];
                }
            }
        }

        // الميزات: من _mon_catalog_features حصراً (Snapshot وقت التفعيل)،
        // وليس _mon_active_features (Legacy). قراءة آمنة فقط — بلا أي كتابة.
        $catalog_features = pge_normalize_catalog_features_meta(
            get_user_meta($user_id, '_mon_catalog_features', true)
        );
        foreach ($catalog_features as $feature_key) {
            $limits[$feature_key] = 1;
        }

        return $limits;
    }
}

if (!function_exists('pge_normalize_catalog_features_meta')) {
    /**
     * تطبيع آمن لقيمة _mon_catalog_features مهما كان شكلها الفعلي في
     * القاعدة (array عادية — الحالة الطبيعية عبر update_user_meta/
     * get_user_meta، أو نص JSON، أو نص serialized، أو قيمة فارغة/تالفة).
     * لا تُغيّر أي بيانات مخزَّنة — قراءة فقط. أي شكل غير معروف يُعيد []
     * بدل أي خطأ، لضمان عدم حدوث Fatal Error.
     */
    function pge_normalize_catalog_features_meta($raw_value)
    {
        if (is_array($raw_value)) {
            $list = $raw_value;
        } elseif (is_string($raw_value)) {
            $trimmed = trim($raw_value);
            if ($trimmed === '') {
                $list = [];
            } else {
                $maybe_unserialized = function_exists('maybe_unserialize')
                    ? maybe_unserialize($trimmed)
                    : @unserialize($trimmed);

                if (is_array($maybe_unserialized)) {
                    $list = $maybe_unserialized;
                } else {
                    $decoded = json_decode($trimmed, true);
                    $list = is_array($decoded) ? $decoded : [];
                }
            }
        } else {
            $list = [];
        }

        $features = [];
        foreach ($list as $feature) {
            if (!is_scalar($feature)) {
                continue;
            }
            $feature = trim((string) $feature);
            if ($feature === '') {
                continue;
            }
            $features[] = $feature;
        }

        return array_values(array_unique($features));
    }
}

if (!function_exists('pge_plan_feature_enabled_for_events')) {
    function pge_plan_feature_enabled_for_events($limits, $feature_key)
    {
        if (class_exists('PGE_Packages') && method_exists('PGE_Packages', 'is_feature_enabled')) {
            return PGE_Packages::is_feature_enabled((array) $limits, (string) $feature_key);
        }

        $value = is_array($limits) && isset($limits[$feature_key]) ? $limits[$feature_key] : 0;
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return ((int) $value) === 1;
        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'on', 'yes', 'true'], true);
    }
}

if (!function_exists('pge_resolve_event_quota_status')) {
    /**
     * Event Quota Architecture — Commit 5: حلّ الحصة وحساب الاستخدام.
     *
     * دالة معلوماتية بحتة (Read-Only) فقط: لا تُستدعى من أي مسار إنشاء
     * مناسبة حالياً (لا وصل بـpge_handle_event_creation() في هذا الـCommit)،
     * ولا ترفض ولا تمنع أي شيء — تُعيد فقط الأرقام الصحيحة (allowed/used/
     * remaining) أو حالة unlimited/legacy/خطأ تكامل. الإنفاذ الفعلي (منع
     * إنشاء مناسبة عند الاستنفاد) Commit لاحق منفصل تماماً، خارج نطاق هذا
     * الـCommit عمداً.
     *
     * — Legacy (_mon_package_source !== 'catalog'، بما فيها الفراغ): بلا أي
     *   تغيير في السلوك إطلاقاً. نفس مصدر الحد
     *   (pge_get_user_plan_limits_for_events()['events_count']) ونفس منطق
     *   العدّ الموجود مسبقاً في بوابة الحصة الحالية داخل
     *   pge_handle_event_creation() أدناه (كل مناسبات المستخدم النشطة/
     *   المسودة/المعلَّقة بحسب author، بلا أي meta_query على ownership —
     *   Legacy لا يملك مفهوم Activation Ownership إطلاقاً، ولا Commit 4 لمسه).
     *
     * — Catalog (_mon_package_source === 'catalog'): يُستبعَد تماماً أي رجوع
     *   لـ_mon_events_limit القديم. Snapshot (_mon_event_quota_mode/
     *   _mon_event_quota_limit، المكتوبان حصراً داخل
     *   Mon_Events_Users::activate_catalog_tier() منذ Commit 3) هو مصدر
     *   الحقيقة الوحيد — بلا أي قراءة لصف mon_plan_tiers (Tier الحيّ)، ولا
     *   لـPGE_Feature_Registry، ولا لأي Feature Resolver إطلاقاً.
     *
     *   سلامة التفعيل أولاً: مصدر catalog بلا _mon_credit_cycle_id (راجع
     *   Commit 4 لمناقشة متى يمكن أن يحدث هذا فعلياً) يُعامَل كخطأ تكامل
     *   بيانات صريح فوري (WP_Error) — بلا أي رجوع ضمني لـLegacy، ولا افتراض
     *   Unlimited، ولا افتراض "مناسبة واحدة مسموحة" كقيمة آمنة بديلة. فشل
     *   صريح مقصود، مطابق حرفياً لتعليمات هذا الـCommit.
     *
     *   Unlimited: تُعاد النتيجة فوراً بلا أي استعلام عدّ إطلاقاً (allowed/
     *   used/remaining = null) — لا داعي لمعرفة "المُستخدَم" حين لا حصة تحدّه.
     *
     *   Limited: يُحسَب allowed من _mon_event_quota_limit (بنفس أسلوب
     *   القراءة الدفاعية المُستخدَم في activate_catalog_tier() نفسها —
     *   Snapshot مضمونة الصلاحية أصلاً من مسار الكتابة الوحيد، فهذه قراءة
     *   دفاعية إضافية فقط)، ويُحسَب used بعدّ مناسبات هذا التفعيل الحالي
     *   بالذات حصراً: تطابق تام (لا مقارنة "غير فارغ") بين
     *   _pge_event_activation_id لكل مناسبة و_mon_credit_cycle_id الحالي —
     *   هذا يستبعد تلقائياً وبنفس الاستعلام: مناسبات بلا ownership (قيمة
     *   فارغة، كحال أي مستخدم Legacy سابق أو تفعيل Catalog ناقص — راجع
     *   Commit 4)، مناسبات تفعيل سابق مختلف لنفس المستخدم، ومناسبات Legacy
     *   (لا تحمل هذا المفتاح أصلاً).
     *
     * ملاحظة توثيق: remaining هنا هو الفرق الحسابي المباشر (allowed - used)
     * بلا أي تقييد بحد أدنى صفر — هذا الـCommit معلوماتي بحت وقد يعكس بصدق
     * حالة استخدام تتجاوز الحصة (ممكنة حالياً فعلياً بما أن لا إنفاذ بعد في
     * أي Commit سابق)؛ حسم ما إذا كان يجب Clamp هذه القيمة عند العرض/الإنفاذ
     * قرار لاحق خارج نطاق هذا الـCommit عمداً.
     *
     * @return array|WP_Error
     *   نجاح: ['mode' => 'legacy'|'unlimited'|'limited',
     *          'allowed' => int|null, 'used' => int|null,
     *          'remaining' => int|null].
     *   فشل تكامل Catalog: WP_Error('catalog_activation_integrity', ...).
     */
    function pge_resolve_event_quota_status($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('invalid_user_id', 'معرّف مستخدم غير صالح.');
        }

        $package_source = (string) get_user_meta($user_id, '_mon_package_source', true);

        if ($package_source !== 'catalog') {
            $plan_limits = pge_get_user_plan_limits_for_events($user_id);
            $allowed = (int) ($plan_limits['events_count'] ?? 0);

            $legacy_query = new WP_Query(array(
                'post_type'      => 'pge_event',
                'post_status'    => array('publish', 'draft', 'pending'),
                'author'         => $user_id,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ));
            $used = (int) $legacy_query->found_posts;

            return array(
                'mode'      => 'legacy',
                'allowed'   => $allowed,
                'used'      => $used,
                'remaining' => $allowed - $used,
            );
        }

        $credit_cycle_id = (string) get_user_meta($user_id, '_mon_credit_cycle_id', true);
        if ($credit_cycle_id === '') {
            return new WP_Error(
                'catalog_activation_integrity',
                'حساب Catalog هذا في حالة غير متسقة: لا يوجد معرّف تفعيل (credit_cycle_id) رغم أن مصدر الباقة catalog.'
            );
        }

        $quota_mode_raw = get_user_meta($user_id, '_mon_event_quota_mode', true);
        $quota_mode = is_string($quota_mode_raw) ? strtolower(trim($quota_mode_raw)) : 'limited';
        if ($quota_mode !== 'unlimited') {
            $quota_mode = 'limited';
        }

        if ($quota_mode === 'unlimited') {
            return array(
                'mode'      => 'unlimited',
                'allowed'   => null,
                'used'      => null,
                'remaining' => null,
            );
        }

        $quota_limit_raw = get_user_meta($user_id, '_mon_event_quota_limit', true);
        $allowed = (is_int($quota_limit_raw) || (is_string($quota_limit_raw) && preg_match('/^[0-9]+$/', trim($quota_limit_raw))))
            ? (int) $quota_limit_raw
            : 1;
        if ($allowed < 1) {
            $allowed = 1;
        }

        $used_query = new WP_Query(array(
            'post_type'      => 'pge_event',
            'post_status'    => array('publish', 'draft', 'pending'),
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_pge_event_activation_id',
                    'value'   => $credit_cycle_id,
                    'compare' => '=',
                ),
            ),
        ));
        $used = (int) $used_query->found_posts;

        return array(
            'mode'      => 'limited',
            'allowed'   => $allowed,
            'used'      => $used,
            'remaining' => $allowed - $used,
        );
    }
}

/**
 * معالجة إنشاء مناسبة جديدة عبر AJAX مع فحص الحصة (Quota) الديناميكية
 */
add_action('wp_ajax_pge_create_new_event', 'pge_handle_event_creation');

function pge_handle_event_creation()
{
    // 1. التحقق من تسجيل الدخول
    if (!is_user_logged_in()) {
        wp_send_json_error('يجب تسجيل الدخول أولاً');
    }

    // 2. التحقق من الأمان (Nonce)
    if (!isset($_POST['pge_event_nonce']) || !wp_verify_nonce($_POST['pge_event_nonce'], 'pge_create_event_action')) {
        wp_send_json_error('خطأ في التحقق من الأمان');
    }

    $user_id = get_current_user_id();

    // ====================================================================
    // Event Quota Architecture — Commit 6: Atomic Quota Enforcement.
    //
    // قفل GET_LOCK خاص بهذا المستخدم فقط (مشتق من user_id وحده — لا يحجب
    // مستخدمين آخرين إطلاقاً، ويمنع نفس المستخدم من إنشاء مناسبتين
    // متزامنتين تتجاوزان الحصة معاً) يُغلّف كامل التدفّق التالي: حلّ الحصة
    // (Legacy كما هو تماماً، أو Catalog عبر pge_resolve_event_quota_status()
    // من Commit 5) ← التحقق من الحقول ← الإدراج الفعلي (Commit 4) ← التحقق
    // من ملكية التفعيل. نفس أسلوب القفل المُثبَت فعلياً في هذا المشروع
    // (PGE_Invitation_Credit_Ledger::claim_for_delivery()): GET_LOCK بمهلة
    // انتظار قصيرة، واسم مشتق عبر md5() لضمان طول ثابت آمن ضمن حد MySQL.
    //
    // تنبيه حاسم لسلامة القفل: wp_send_json_error()/wp_send_json_success()
    // تستدعيان wp_die() داخلياً في ووردبريس الفعلي، والتي تُنهي الطلب فوراً
    // عبر exit()/die() — وPHP لا يُنفِّذ أي "finally" معلَّق بعد exit()/die()
    // إطلاقاً (خلافاً لرمي استثناء، حيث يُنفَّذ finally أثناء الانتشار). لذا
    // لا يجوز الاعتماد على try/finally وحده هنا؛ القفل يُحرَّر صراحةً عبر
    // $release_event_creation_lock() قبل كل استدعاء وحيد لأي من الدالتين من
    // هذه النقطة فصاعداً، بلا استثناء واحد. طبقة الحماية الأخيرة أدناه
    // (catch (\Error)) مخصَّصة لأخطاء PHP وقت التشغيل غير المتوقعة إطلاقاً
    // (لا لأي مسار تحكّم اعتيادي في هذا الملف — الأخطاء المتوقَّعة هنا تُمثَّل
    // دوماً بكائنات WP_Error التي تُعاد كقيمة، لا تُرمى كاستثناء، تماشياً مع
    // نمط بقية هذا المشروع بالكامل).
    // ====================================================================

    global $wpdb;
    $event_creation_lock_name = 'pge_event_create_' . md5((string) $user_id);

    $got_event_creation_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $event_creation_lock_name, 5));
    if ((int) $got_event_creation_lock !== 1) {
        wp_send_json_error('حدث خطأ أثناء إنشاء المناسبة، يرجى المحاولة لاحقاً.');
    }

    $release_event_creation_lock = function () use ($wpdb, $event_creation_lock_name) {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $event_creation_lock_name));
    };

    try {
        // --- [نظام فحص الحصة] ---
        //
        // pge_get_user_plan_limits_for_events() ما زالت تُستدعى دوماً (Legacy
        // وCatalog معاً) لأنها المصدر الوحيد لميزات لا علاقة لها بالحصة
        // إطلاقاً (header_img أدناه) — لكن قيمتها 'events_count' لم تعد
        // تُستخدَم لحساب حصة مستخدمي Catalog بعد الآن (ذلك حصراً عبر
        // pge_resolve_event_quota_status()، Snapshot فقط، بلا أي قراءة لصف
        // الـTier أو Registry أو Feature Resolver).
        $plan_limits = pge_get_user_plan_limits_for_events($user_id);
        $package_source = (string) get_user_meta($user_id, '_mon_package_source', true);

        if ($package_source !== 'catalog') {
            // ---- Legacy: حرفياً بلا أي تغيير عن السلوك الموجود مسبقاً ----
            $allowed_limit = (int) ($plan_limits['events_count'] ?? 0);

            // جلب عدد المناسبات الفعّالة للمستخدم (نستثني المؤرشفة — status=private + meta _pge_archived=1)
            $user_events_query = new WP_Query(array(
                'post_type'      => 'pge_event',
                'post_status'    => array('publish', 'draft', 'pending'),
                'author'         => $user_id,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ));
            $current_count = $user_events_query->found_posts;

            if ($current_count >= $allowed_limit) {
                $release_event_creation_lock();
                if ($allowed_limit <= 0) {
                    $error_msg = 'عذراً، ليس لديك باقة نشطة. يرجى الاشتراك في إحدى الباقات لتمكن من إنشاء مناسبات.';
                } else {
                    $error_msg = sprintf(
                        'لقد استنفدت الحد الأقصى للمناسبات في باقتك الحالية (%d من %d). يرجى الترقية لإضافة المزيد.',
                        $current_count,
                        $allowed_limit
                    );
                }
                wp_send_json_error($error_msg);
            }
        } else {
            // ---- Catalog: Commit 5 resolver فقط، Snapshot + Ownership ----
            $quota_status = pge_resolve_event_quota_status($user_id);

            if (is_wp_error($quota_status)) {
                // خطأ تكامل بيانات صريح (مثلاً مصدر catalog بلا
                // credit_cycle_id) — يُرفض الإنشاء بنفس مسار الخطأ العام
                // الحالي، بلا أي رجوع ضمني أو رسالة جديدة.
                $release_event_creation_lock();
                wp_send_json_error('حدث خطأ أثناء إنشاء المناسبة، يرجى المحاولة لاحقاً.');
            }

            if (($quota_status['mode'] ?? '') === 'limited') {
                $quota_allowed = (int) ($quota_status['allowed'] ?? 0);
                $quota_used = (int) ($quota_status['used'] ?? 0);
                if ($quota_used >= $quota_allowed) {
                    $release_event_creation_lock();
                    $error_msg = sprintf(
                        'لقد استنفدت الحد الأقصى للمناسبات في باقتك الحالية (%d من %d). يرجى الترقية لإضافة المزيد.',
                        $quota_used,
                        $quota_allowed
                    );
                    wp_send_json_error($error_msg);
                }
            }
            // mode === 'unlimited': بلا أي فحص عدّ إطلاقاً — تُتابَع العملية.
        }
        // --------------------------------------------------------

        // 3. استلام وتنظيف البيانات
        $title    = sanitize_text_field($_POST['event_title'] ?? '');
        $date     = sanitize_text_field($_POST['event_date'] ?? '');
        $can_google_map = pge_user_has_feature($user_id, 'google_maps');
        $can_header_img = pge_plan_feature_enabled_for_events($plan_limits, 'header_img');
        $location = $can_google_map ? esc_url_raw($_POST['event_location'] ?? '') : '';
        $address  = sanitize_text_field($_POST['event_address'] ?? '');
        $phone    = sanitize_text_field($_POST['host_phone'] ?? '');
        $invite_code = isset($_POST['invite_code']) ? pge_normalize_invite_code(wp_unslash($_POST['invite_code'])) : '';
        if ($invite_code === '') {
            $invite_code = pge_generate_invite_code();
        }

        // 3.1 التحقق من الحقول المطلوبة على الخادم — لا يجوز الاعتماد على تحقق المتصفح
        // فقط (novalidate/JS يمكن تجاوزهما بطلب مباشر)، لذا هذا هو حد السلامة الفعلي.
        if ($title === '') {
            $release_event_creation_lock();
            wp_send_json_error('يرجى إدخال اسم المناسبة.');
        }
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $date) || strtotime($date) === false) {
            $release_event_creation_lock();
            wp_send_json_error('يرجى إدخال تاريخ ووقت صحيح للمناسبة.');
        }
        $phone_normalized = function_exists('pge_norm_phone') ? pge_norm_phone($phone) : preg_replace('/\D+/', '', (string) $phone);
        if ($phone_normalized === '') {
            $release_event_creation_lock();
            wp_send_json_error('يرجى إدخال رقم جوال صحيح للمضيف.');
        }

        // 4. إدراج المناسبة في قاعدة البيانات (Commit 4 — ownership meta،
        // بلا أي تغيير في منطقها هنا).
        $activation_id = (string) get_user_meta($user_id, '_mon_credit_cycle_id', true);

        $post_data = array(
            'post_title'   => $title,
            'post_status'  => 'publish',
            'post_type'    => 'pge_event',
            'post_author'  => $user_id,
            'meta_input'   => array(
                '_pge_event_activation_id' => $activation_id,
            ),
        );

        $post_id = wp_insert_post($post_data);

        if ($post_id) {
            // تحقّق فوري من ملكية التفعيل (Commit 4، بلا أي تغيير في منطقه هنا).
            $stored_activation_id = (string) get_post_meta($post_id, '_pge_event_activation_id', true);
            if ($stored_activation_id !== $activation_id) {
                wp_delete_post($post_id, true);
                $release_event_creation_lock();
                wp_send_json_error('حدث خطأ أثناء إنشاء المناسبة، يرجى المحاولة لاحقاً.');
            }

            // تخزين الميتا داتا الإضافية
            update_post_meta($post_id, '_pge_event_date', $date);
            update_post_meta($post_id, '_pge_event_location', $location);
            update_post_meta($post_id, '_pge_event_address',  $address);
            update_post_meta($post_id, '_pge_host_phone', $phone);
            update_post_meta($post_id, '_pge_invite_code', $invite_code);
            if ($can_header_img) {
                $featured_upload = pge_handle_featured_image_upload('featured_image', $post_id);
                if (is_wp_error($featured_upload)) {
                    wp_delete_post($post_id, true);
                    $release_event_creation_lock();
                    wp_send_json_error($featured_upload->get_error_message());
                }
            }

            $release_event_creation_lock();
            wp_send_json_success(array(
                'message'      => 'تم إنشاء المناسبة بنجاح!',
                'redirect_url' => get_permalink($post_id),
                'invite_code'  => $invite_code,
            ));
        }

        $release_event_creation_lock();
        wp_send_json_error('حدث خطأ أثناء إنشاء المناسبة، يرجى المحاولة لاحقاً.');
    } catch (\Error $unexpected_error) {
        // شبكة أمان أخيرة لخطأ PHP وقت تشغيل غير متوقَّع إطلاقاً في أي مما
        // سبق. كل نقاط الخروج الطبيعية أعلاه تُحرِّر القفل صراحةً بالفعل قبل
        // الوصول لهذا الفرع؛ استدعاء إضافي هنا آمن تماماً ولا يُسبِّب أي أثر
        // جانبي حتى لو كان القفل محرَّراً بالفعل (RELEASE_LOCK على قفل غير
        // محجوز من هذه الجلسة يُعيد ببساطة 0).
        $release_event_creation_lock();
        wp_send_json_error('حدث خطأ أثناء إنشاء المناسبة، يرجى المحاولة لاحقاً.');
    }
}

/**
 * معالجة تحديث المناسبة عبر AJAX
 */
add_action('wp_ajax_pge_handle_event_update', 'pge_handle_event_update');

function pge_handle_event_update()
{
    if (!is_user_logged_in()) wp_send_json_error('غير مسموح');

    $event_id = intval($_POST['event_id']);
    $post = get_post($event_id);

    // التحقق من الملكية
    if (!$post || $post->post_author != get_current_user_id()) {
        wp_send_json_error('ليس لديك صلاحية لتعديل هذه المناسبة');
    }

    if (!isset($_POST['pge_event_nonce']) || !wp_verify_nonce($_POST['pge_event_nonce'], 'pge_edit_event_action')) {
        wp_send_json_error('خطأ في التحقق من الأمان (Nonce)');
    }

    $updated_post = array(
        'ID'         => $event_id,
        'post_title' => sanitize_text_field($_POST['event_title']),
    );

    $result = wp_update_post($updated_post);

    if ($result) {
        $plan_limits = pge_get_user_plan_limits_for_events(get_current_user_id());
        $can_google_map = pge_user_has_feature(get_current_user_id(), 'google_maps');
        $can_header_img = pge_plan_feature_enabled_for_events($plan_limits, 'header_img');

        update_post_meta($event_id, '_pge_event_date',     sanitize_text_field($_POST['event_date']));
        update_post_meta($event_id, '_pge_event_location', $can_google_map ? esc_url_raw($_POST['event_location'] ?? '') : '');
        update_post_meta($event_id, '_pge_event_address',  sanitize_text_field($_POST['event_address'] ?? ''));
        update_post_meta($event_id, '_pge_host_phone',     sanitize_text_field($_POST['host_phone']));

        $invite_code = isset($_POST['invite_code']) ? pge_normalize_invite_code(wp_unslash($_POST['invite_code'])) : '';
        if ($invite_code === '') {
            $invite_code = pge_normalize_invite_code((string) get_post_meta($event_id, '_pge_invite_code', true));
            if ($invite_code === '') {
                $invite_code = pge_generate_invite_code();
            }
        }
        update_post_meta($event_id, '_pge_invite_code', $invite_code);
        if ($can_header_img) {
            $featured_upload = pge_handle_featured_image_upload('featured_image', $event_id);
            if (is_wp_error($featured_upload)) {
                wp_send_json_error($featured_upload->get_error_message());
            }
        }

        wp_send_json_success('تم تحديث البيانات بنجاح');
    } else {
        wp_send_json_error('فشل تحديث قاعدة البيانات');
    }
}

add_action('wp_ajax_pge_event_set_invite_code', 'pge_event_set_invite_code');

function pge_event_set_invite_code()
{
    if (!is_user_logged_in()) wp_send_json_error('غير مصرح');

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'pge_event_manage_nonce')) {
        wp_send_json_error('رمز الأمان غير صالح');
    }

    $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
    if (!$event_id || get_post_type($event_id) !== 'pge_event') {
        wp_send_json_error('مناسبة غير صالحة');
    }

    $can_manage = false;
    if (function_exists('pge_event_guests_user_can_manage')) {
        $can_manage = pge_event_guests_user_can_manage($event_id);
    } else {
        $uid = get_current_user_id();
        $author_id = (int) get_post_field('post_author', $event_id);
        $can_manage = current_user_can('administrator') || ($uid && $uid === $author_id) || current_user_can('edit_post', $event_id);
    }

    if (!$can_manage) {
        wp_send_json_error('ليس لديك صلاحية إدارة هذه المناسبة');
    }

    $invite_code = isset($_POST['invite_code']) ? pge_normalize_invite_code(wp_unslash($_POST['invite_code'])) : '';
    if ($invite_code === '') {
        $invite_code = pge_generate_invite_code();
    }

    update_post_meta($event_id, '_pge_invite_code', $invite_code);

    wp_send_json_success([
        'message'     => 'تم تحديث رمز الدعوة',
        'invite_code' => $invite_code,
    ]);
}

/**
 * أرشفة المناسبة (تحويلها لخاص) بدلاً من الحذف لضمان بقائها ضمن الحصة
 */
add_action('wp_ajax_pge_archive_event', 'pge_handle_event_archiving');

function pge_handle_event_archiving()
{
    if (!is_user_logged_in()) wp_send_json_error('غير مسموح');

    $event_id = intval($_POST['event_id']);
    $post = get_post($event_id);

    if (!$post || $post->post_author != get_current_user_id()) {
        wp_send_json_error('ليس لديك صلاحية لإغلاق هذه المناسبة');
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pge_archive_event_nonce')) {
        wp_send_json_error('خطأ في التحقق من الأمان');
    }

    $result = wp_update_post(array(
        'ID'          => $event_id,
        'post_status' => 'private'
    ));

    if ($result) {
        // نضع علامة أرشفة حتى لا تُحسب في حصة الباقة
        update_post_meta($event_id, '_pge_archived', '1');
        wp_send_json_success('تم إغلاق المناسبة وأرشفتها بنجاح');
    } else {
        wp_send_json_error('فشل في إغلاق المناسبة');
    }
}
