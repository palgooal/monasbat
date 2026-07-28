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

            // حاجز حماية Catalog — يُفحَص قبل أي update_user_meta بلا استثناء.
            // هذا مسار تفعيل Legacy (نظير deactivate_user_package())، وقد
            // يصل فعلياً عبر Webhook طلب Legacy لمستخدم مصدر اشتراكه الحالي
            // Catalog (مثلاً عميل اشترى منتج Legacy قديم بنفس بريده بعد أن
            // فعّل Catalog). Catalog يبقى مصدر الحقيقة الوحيد، بصرف النظر عن
            // active/expired — بلا أي مزج أو fallback.
            $legacy_write_allowed = function_exists('pge_is_legacy_write_allowed_for_user')
                ? pge_is_legacy_write_allowed_for_user($user->ID)
                : (get_user_meta($user->ID, '_mon_package_source', true) !== 'catalog');

            if (!$legacy_write_allowed) {
                error_log(sprintf(
                    '⛔ [legacy_activation_blocked_for_catalog] user_id=%d order_id=%s reason=subscription_source_is_catalog',
                    $user->ID,
                    sanitize_text_field((string) ($data['order_id'] ?? ''))
                ));

                return new WP_REST_Response([
                    'status'  => 'blocked',
                    'message' => 'لا يمكن تفعيل باقة Legacy لهذا المستخدم لأن اشتراكه الحالي مُدار بواسطة Catalog.',
                    'user_id' => $user->ID,
                ], 409);
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

        // رصيد الدعوات (الأساسي والبديل) — مرحلة "تأسيس بيانات فقط" لنظام
        // Invitation Credits Engine: العمودان الجديدان في mon_plan_tiers هما
        // INT UNSIGNED NOT NULL DEFAULT 0 (غير NULLable مثل guest_limit)،
        // فلا حاجة هنا لأي تمييز NULL/فارغ — absint() كافية ومباشرة، وتُعيد
        // 0 دفاعياً حتى لو غاب المفتاح من صف tier قديم بشكل غير متوقع.
        $invitation_credit_limit = absint($tier['invitation_credit_limit'] ?? 0);
        $replacement_credit_limit = absint($tier['replacement_credit_limit'] ?? 0);

        // Event Quota (Commit 3 من معمارية Event Quota المعتمدة) — قراءة
        // دفاعية بنفس أسلوب invitation_credit_limit/replacement_credit_limit
        // أعلاه بالضبط (absint()-style casting، بلا استدعاء
        // PGE_Catalog::normalize_event_quota_*() الخاصتين private، وبلا رفض
        // للتفعيل عند قيمة غير متوقعة): عمودا mon_plan_tiers.event_quota_mode/
        // event_quota_limit مضمونان صالحين دوماً من مسار CRUD الوحيد الذي
        // يكتبهما (PGE_Catalog::create_tier()/update_tier() — Commit 2)، لذا
        // هذه القراءة دفاعية فقط ضد صف tier غير متوقع (تماماً كتعليق
        // invitation_credit_limit أعلاه)، لا تحقّقاً أساسياً.
        //
        // القرار المعماري الحاسم لهذا الـCommit: القيمتان المكتوبتان في
        // Snapshot المستخدم أدناه (_mon_event_quota_mode/_mon_event_quota_limit)
        // هما "الاستحقاق التجاري المشترى في لحظة هذا التفعيل تحديداً" —
        // نسخة مجمَّدة من صف الـTier الحالي وقت التفعيل، وليست إشارة حية لصف
        // الـTier. إن عدَّل المسؤول لاحقاً event_quota_limit لنفس الـTier (من
        // 3 إلى 5 مثلاً)، لا يتأثر أي مستخدم مُفعَّل مسبقاً على هذا الـTier
        // إطلاقاً — Snapshot ذلك المستخدم يبقى 3 كما اشتراه بالضبط، ولا
        // تُعاد قراءته من الـTier مطلقاً بعد هذه اللحظة. فقط تفعيل جديد لاحق
        // (تجديد/ترقية/تخفيض — أي استدعاء لا يمر من فرع "تكرار طلب متطابق"
        // أدناه) يقرأ القيمة الحالية للـTier ويكتب Snapshot جديداً بها. هذا
        // يطابق حرفياً نفس مبدأ Snapshot المُطبَّق فعلاً على guest_limit/
        // invitation_credit_total/replacement_credit_total أعلاه — لا مبدأ
        // جديد، إعادة استخدام للمبدأ القائم فقط.
        $event_quota_mode = is_string($tier['event_quota_mode'] ?? null)
            ? strtolower(trim((string) $tier['event_quota_mode']))
            : 'limited';
        if ($event_quota_mode !== 'unlimited') {
            $event_quota_mode = 'limited';
        }

        $event_quota_limit_raw = $tier['event_quota_limit'] ?? 1;
        $event_quota_limit = (is_int($event_quota_limit_raw) || (is_string($event_quota_limit_raw) && preg_match('/^[0-9]+$/', trim($event_quota_limit_raw))))
            ? (int) $event_quota_limit_raw
            : 1;
        if ($event_quota_limit < 1) {
            $event_quota_limit = 1;
        }

        // معرّف دورة الرصيد (Invitation Credits Engine — المرحلة الثانية):
        // يُولَّد فريداً عند كل كتابة Snapshot فعلية أدناه (لا عند الاستدعاء
        // المتطابق تماماً الذي يُعيد true مبكراً أسفل هذا السطر — تلك حالة
        // "تكرار نفس الطلب" لا "تفعيل جديد"، فلا يجوز أن تُبدِّل دورة رصيد
        // المستخدم بلا سبب). الغرض: فصل استهلاك الاشتراك الحالي عن أي دورة
        // سابقة لنفس المستخدم — سجل الاستهلاك الذري (PGE_Invitation_Credit_Ledger)
        // يستخدم credit_cycle_id هذا كجزء من مفتاحه الفريد، لا plan_id/tier_id،
        // تحديداً لأن نفس الـTier قد يُعاد تفعيله لاحقاً بدورة استهلاك جديدة
        // بالكامل. لا منطق Webhook idempotency هنا؛ هذا توليد قيمة فقط.
        $credit_cycle_id = self::generate_credit_cycle_id();

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

        // ====================================================================
        // Phase 4 — Commit 2: Snapshot Integration (docs/FEATURES-PHASE-4-SPEC.md
        // §16 "ترتيب البناء والكتابة"). Snapshot الميزات يُبنى ويُكتَب كخطوة
        // أولى، قبل بدء الحلقة أدناه الخاصة ببيانات Catalog والرصيد بالكامل
        // (بلا أي تغيير على تلك الحلقة أو محتواها) — لتقليل نافذة رؤية
        // Resolver لمستخدم Catalog+Active بلا Snapshot ميزات مكتملة (تلك
        // الحلقة هي من تكتب لاحقاً _mon_package_status = 'active').
        //
        // سياسة الفشل (§16، لا Rollback، لا Transaction حقيقية):
        // - فشل بناء Snapshot (build_tier_features_snapshot() تُعيد WP_Error):
        //   يُعاد نفس الخطأ فوراً، صفر كتابة من أي نوع.
        // - فشل كتابة _mon_package_features: WP_Error فوري، لا زيادة Version
        //   مُعتمَدة، ولا بدء للحلقة الحالية.
        // - فشل كتابة _mon_package_feature_version (بعد نجاح Snapshot):
        //   WP_Error فوري، ولا بدء للحلقة الحالية.
        // ====================================================================
        $feature_snapshot = self::build_tier_features_snapshot($tier_id);
        if (is_wp_error($feature_snapshot)) {
            return $feature_snapshot;
        }

        $next_feature_version = self::get_next_package_feature_version($user_id);

        if (!self::update_user_meta_safely($user_id, '_mon_package_features', $feature_snapshot)) {
            return new WP_Error('meta_update_failed', 'تعذر حفظ Snapshot ميزات الباقة للمستخدم.');
        }

        if (!self::update_user_meta_safely($user_id, '_mon_package_feature_version', $next_feature_version)) {
            return new WP_Error('meta_update_failed', 'تعذر حفظ رقم إصدار Snapshot ميزات الباقة للمستخدم.');
        }

        $features = self::normalize_catalog_features($plan['features'] ?? null);

        // ====================================================================
        // Commit 9 — Invitation Credit Accumulation Across Renewals (تغيير
        // سياسة تجارية، لا علاقة له بمعمارية Event Quota إطلاقاً — لا لمس هنا
        // على $event_quota_mode/$event_quota_limit أعلاه ولا على أي من حقولهما
        // في $snapshot أدناه).
        //
        // القاعدة الجديدة: كل تفعيل حقيقي (تجديد/ترقية/تخفيض — أي استدعاء لا
        // يمر من فرع "تكرار طلب متطابق تماماً" أعلاه، فلا تراكم مضاعف عند
        // إعادة تسليم نفس الـWebhook) يُضيف رصيد الـTier الجديد إلى المتبقي
        // الفعلي غير المستهلك من الدورة الحالية، بدل تصفير الرصيد بالكامل كما
        // كان سابقاً:
        //   المتبقي الحالي = Total الحالي - Used الحالي (بحد أدنى صفر)
        //   Total الجديد   = المتبقي الحالي + رصيد الـTier الجديد
        //   Used الجديد    = 0 دائماً (يبقى هذا الجزء بلا تغيير عن السابق)
        // ينطبق هذا بالتساوي على رصيد الدعوات الأساسي (invitation) والبديل
        // (replacement) — نفس الصيغة حرفياً لكليهما.
        $current_invitation_total = absint(get_user_meta($user_id, '_mon_invitation_credit_total', true));
        $current_invitation_used  = absint(get_user_meta($user_id, '_mon_invitation_credit_used', true));
        $current_invitation_remaining = max(0, $current_invitation_total - $current_invitation_used);
        $new_invitation_credit_total = $current_invitation_remaining + $invitation_credit_limit;

        $current_replacement_total = absint(get_user_meta($user_id, '_mon_replacement_credit_total', true));
        $current_replacement_used  = absint(get_user_meta($user_id, '_mon_replacement_credit_used', true));
        $current_replacement_remaining = max(0, $current_replacement_total - $current_replacement_used);
        $new_replacement_credit_total = $current_replacement_remaining + $replacement_credit_limit;

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
            // Event Quota Snapshot (Commit 3) — يُكتَبان معاً دائماً، لا أحدهما
            // بدون الآخر (راجع التعليق أعلى حساب $event_quota_mode/
            // $event_quota_limit لسبب "التجميد عند لحظة الشراء" وعزلهما عن أي
            // تعديل لاحق على صف الـTier). لا قراءة لهذين المفتاحين في أي مكان
            // آخر من الكود بعد — ذلك خارج نطاق هذا الـCommit تماماً.
            '_mon_event_quota_mode'    => $event_quota_mode,
            '_mon_event_quota_limit'   => $event_quota_limit,
            '_mon_salla_product_id'    => sanitize_text_field((string) ($tier['salla_product_id'] ?? '')),
            '_mon_catalog_features'    => $features,
            // Snapshot رصيد الدعوات (Commit 9 — سياسة تراكمية جديدة، راجع
            // التعليق التفصيلي أعلى $current_invitation_total): كل تفعيل حقيقي
            // (سواء أول تفعيل أو تجديد/ترقية/تخفيض لاحق) يضيف رصيد الـTier
            // الجديد إلى المتبقي الفعلي غير المستهلك من الدورة الحالية — لا
            // تصفير كامل بعد الآن. Used يبقى دائماً صفراً عند كل تفعيل جديد
            // (بلا تغيير عن السابق). ملاحظة: فرع "نفس البيانات تماماً فمُطابقة
            // مسبقة" أعلى هذه الدالة (return true المبكرة) لا يمر من هنا
            // إطلاقاً، فاستدعاء هذه الدالة بنفس المعطيات تماماً (تكرار Webhook
            // مثلاً) لا يُضيف رصيداً مضاعفاً بالخطأ — التراكم يحدث فقط عند
            // تفعيل مختلف فعلياً (Duplicate Webhook idempotency بلا تغيير).
            '_mon_invitation_credit_total'  => $new_invitation_credit_total,
            '_mon_invitation_credit_used'   => 0,
            '_mon_replacement_credit_total' => $new_replacement_credit_total,
            '_mon_replacement_credit_used'  => 0,
            '_mon_credit_cycle_id'          => $credit_cycle_id,
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

    /**
     * توليد معرّف دورة رصيد فريد (UUID v4). تُفضَّل wp_generate_uuid4() إن
     * كانت متاحة (متوفرة في ووردبريس الفعلي منذ 4.7)؛ الاحتياط عند غيابها
     * (كبيئة اختبار معزولة بلا ووردبريس حقيقي) يُنتج UUID v4 يدوياً بنفس
     * خوارزمية ووردبريس الفعلية: 16 بايت عشوائية عبر random_bytes() (آمنة
     * تشفيرياً)، مع ضبط bits الإصدار (0100) والمتغيّر (10) وفق RFC 4122.
     */
    private static function generate_credit_cycle_id()
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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

    /**
     * ========================================================================
     * Phase 4 — Commit 1/2: Snapshot Builder + Integration
     * ========================================================================
     * وفق docs/FEATURES-PHASE-4-SPEC.md حرفياً (§6 مصدر البيانات، §7/"قرار
     * شكل Snapshot" شكل الناتج، §16 عقد فشل Repository وترتيب البناء
     * والكتابة، §17 عقد الإصدار، §18 قيود الأداء)، وDEC-001/DEC-002/DEC-003
     * في docs/DECISION-LOG.md.
     *
     * الدوال أدناه (Commit 1) تُستدعى الآن فعلياً من داخل
     * activate_catalog_tier() (Commit 2، راجع بداية الدالة أعلاه) — لا تزال
     * كل الكتابة الفعلية لـUser Meta محصورة داخل activate_catalog_tier()
     * نفسها؛ الدوال هنا تبقى بناء/حساباً في الذاكرة فقط، بلا أي كتابة من
     * داخلها هي (لا update_user_meta() ولا مكافئها في أي من الدوال الثلاث
     * أدناه).
     */

    /**
     * Snapshot Builder — يبني في الذاكرة فقط مصفوفة ميزات Tier كاملة، مُفسَّرة
     * نهائياً، لكل مفاتيح Feature Registry المعروفة وقت الاستدعاء.
     *
     * المصدر (docs/FEATURES-PHASE-4-SPEC.md §6): PGE_Tier_Features::get_all_tier_features($tier_id)
     * (Phase 2، استدعاء واحد فقط) + PGE_Feature_Registry::all() (Phase 1،
     * استدعاء واحد فقط). لا استدعاء لأي Public Resolver API ولا لأي دالة
     * داخلية معتمِدة على أولوية Snapshot (pge_get_user_feature_value(),
     * pge_get_user_package_features(), pge_user_has_feature(),
     * pge_feature_resolver_resolve_raw_value(),
     * pge_feature_resolver_build_bulk_context()) — ممنوع صراحة وفق المرجع،
     * لأنها تقرأ Snapshot الحالي/القديم كأولوية أولى.
     *
     * البناء يتكرر على مفاتيح Registry حصراً، لا على صفوف Tier — أي صف Tier
     * لمفتاح غير موجود في Registry يُتجاهَل تلقائياً بلا أثر على الناتج.
     *
     * عقد الإرجاع (لا قيمة ثالثة ممكنة):
     * - array مسطّحة (feature_key => قيمة مُفسَّرة نهائية bool|int) عند
     *   النجاح، تحتوي دوماً كل مفاتيح Registry الحالية (19 اليوم) بلا
     *   استثناء — مفتاح بلا صف Tier يُملأ بقيمة Default من Registry
     *   مُفسَّرة (مثال: (int) 'TBD' = 0). بلا type/label/metadata/raw_value/
     *   source/tier_id/plan_id داخل القيم.
     * - WP_Error عند $tier_id غير صالح (absint() = 0)، أو عند فشل استعلام
     *   فعلي لـget_all_tier_features() (تُعيد false وفق DEC-002 — لا يُعامَل
     *   كـTier فارغ شرعاً؛ Tier فارغ فعلياً (get_all_tier_features() === [])
     *   حالة نجاح صحيحة تُنتج Snapshot كاملة من Registry Default).
     * - لا تُعاد أبداً false أو null، ولا تُرمى Exception.
     *
     * @param mixed $tier_id
     * @return array|WP_Error
     */
    private static function build_tier_features_snapshot($tier_id)
    {
        $tier_id = absint($tier_id);
        if ($tier_id === 0) {
            return new WP_Error('invalid_tier_id', 'معرّف المستوى غير صالح لبناء Snapshot الميزات.');
        }

        if (!class_exists('PGE_Feature_Registry') || !class_exists('PGE_Tier_Features')) {
            return new WP_Error('feature_layer_unavailable', 'طبقة الميزات (Registry/Repository) غير متاحة حالياً.');
        }

        $registry = PGE_Feature_Registry::all();

        // استدعاء واحد فقط — لا حلقة استعلامات لكل مفتاح (لا N+1)، مطابقاً
        // لمبدأ إصلاح N+1 المُعتمَد فعلياً في Resolver (Commit 1.1).
        $tier_rows = PGE_Tier_Features::get_all_tier_features($tier_id);
        if ($tier_rows === false) {
            // فشل استعلام فعلي (DEC-002) — يُوقِف البناء فوراً، لا يُعامَل
            // كـ"Tier فارغ شرعاً" (docs/FEATURES-PHASE-4-SPEC.md §16: تمييز
            // متعمَّد عن سلوك امتصاص الفشل وقت القراءة في Resolver/DEC-003).
            return new WP_Error('tier_features_repository_failure', 'تعذر قراءة ميزات المستوى من قاعدة البيانات.');
        }

        // تحويل صفوف Tier (get_all_tier_features() تُعيد [] عند النجاح بلا
        // صفوف، أو مصفوفة صفوف ARRAY_A) إلى خريطة بحث feature_key => raw
        // value خام. صف بمفتاح غير موجود في Registry يُستبعَد هنا صراحة —
        // "صف يتيم" يُتجاهَل تماماً، بلا أثر على الناتج النهائي.
        $tier_map = [];
        foreach ($tier_rows as $row) {
            if (!is_array($row) || !array_key_exists('feature_key', $row)) {
                continue;
            }

            $feature_key = (string) $row['feature_key'];
            if (!array_key_exists($feature_key, $registry)) {
                continue;
            }

            $tier_map[$feature_key] = $row['feature_value'] ?? null;
        }

        // الحلقة الوحيدة في هذه الدالة تتكرر على مفاتيح Registry حصراً —
        // لا استدعاء Repository إضافي بداخلها (كل القراءة تمت أعلاه مرة
        // واحدة)، فلا N+1 محتمَل هنا.
        $snapshot = [];
        foreach ($registry as $feature_key => $definition) {
            $type = (is_array($definition) && isset($definition['type']))
                ? (string) $definition['type']
                : '';

            if (array_key_exists($feature_key, $tier_map)) {
                $raw_value = $tier_map[$feature_key];
            } else {
                $raw_value = (is_array($definition) && array_key_exists('default', $definition))
                    ? $definition['default']
                    : null;
            }

            $snapshot[$feature_key] = self::interpret_feature_value_for_snapshot($type, $raw_value);
        }

        return $snapshot;
    }

    /**
     * تفسير قيمة ميزة واحدة وفق نوعها لغرض Snapshot Builder أعلاه.
     *
     * تُعيد استخدام pge_feature_resolver_interpret_by_type() من
     * includes/feature-resolver.php (دالة تفسير خالصة — لا تقرأ $user_id
     * ولا Snapshot ولا أي مصدر بيانات خاص بمستخدم) إن كانت مُعرَّفة، وفق
     * الاستثناء الصريح المسموح في docs/FEATURES-PHASE-4-SPEC.md §6. هذا لا
     * يُعدِّل Resolver ولا يُنشئ مساراً جديداً — استدعاء لدالة عامة موجودة
     * أصلاً، مُحمَّلة قبل هذا الملف بلا شرط في pgevents-core.php.
     *
     * احتياط دفاعي فقط (غير متوقَّع الحدوث عملياً وفق ترتيب require_once
     * الحالي): إن لم تكن الدالة متاحة لأي سبب، تُطبَّق نفس قاعدتَي التفسير
     * حرفياً هنا (Boolean وفق PACKAGE-FEATURE-MATRIX.md §7/
     * event-factory.php:333-344؛ Integer/Percentage عبر (int) صريح وفق
     * DEC-003) — بلا أي تعديل على includes/feature-resolver.php نفسه.
     *
     * @param string $type
     * @param mixed  $raw_value
     * @return mixed
     */
    private static function interpret_feature_value_for_snapshot($type, $raw_value)
    {
        if (function_exists('pge_feature_resolver_interpret_by_type')) {
            return pge_feature_resolver_interpret_by_type($type, $raw_value);
        }

        if ($type === 'boolean') {
            $value = strtolower(trim((string) $raw_value));
            return in_array($value, ['1', 'on', 'yes', 'true'], true);
        }

        if ($type === 'integer' || $type === 'percentage') {
            return (int) $raw_value;
        }

        return $raw_value;
    }

    /**
     * Version Helper — يحسب رقم الإصدار التالي لـSnapshot ميزات مستخدم، في
     * الذاكرة فقط. لا كتابة User Meta من داخل هذه الدالة نفسها — القيمة
     * المُعادة منها تُكتَب في activate_catalog_tier() (Commit 2، التي تستدعي
     * هذه الدالة فعلياً الآن قبل بدء الحلقة الحالية للرصيد/بيانات Catalog).
     *
     * وفق docs/FEATURES-PHASE-4-SPEC.md §17: القيمة الحالية تُقرأ عبر
     * absint(get_user_meta($user_id, '_mon_package_feature_version', true))
     * ثم +1 بالضبط. قيمة مفقودة أو تالفة (غير رقمية) تؤول لـ0 عبر absint()
     * بلا تمييز مطلوب بينهما، فتُنتج 1 تلقائياً — بلا حاجة لفرع خاص.
     *
     * تسمية الدالة تستخدم "feature" (مفرد) لا "features"، مطابقةً حرفياً
     * لاسم مفتاح User Meta الفعلي في PACKAGE-FEATURE-MATRIX.md §9:
     * `_mon_package_feature_version`.
     *
     * @param mixed $user_id
     * @return int
     */
    private static function get_next_package_feature_version($user_id)
    {
        $user_id = absint($user_id);
        $current = absint(get_user_meta($user_id, '_mon_package_feature_version', true));

        return $current + 1;
    }
}
