<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Feature Registry — المرجع الوحيد لتعريف ميزات طبقة Features
 * ============================================================================
 * Phase 1 (Feature Registry) وفق docs/FEATURES-PHASE-1-SPEC.md، مبني حرفياً
 * على docs/PACKAGE-FEATURE-MATRIX.md §6 (Feature Registry) و§8 (لا Feature
 * بلا Registry أولاً).
 *
 * هذه الطبقة **تعريف برمجي فقط، لا قاعدة بيانات** — لا استعلام SQL، ولا أي
 * تفاعل مع أي جدول. تفسير القيم (boolean/integer/percentage) ليس من مهام
 * هذه الطبقة إطلاقاً — ذلك مهمة الـResolver (Phase 3، غير موجود بعد).
 *
 * Registry Independence (§6): لا حقل هنا يشير إلى أي plan_key أو tier_id
 * محدَّد — الميزات مُعرَّفة على مستوى النظام كله، لا على مستوى باقة معينة.
 *
 * Registry Provider (§6): الوصول لهذه التعريفات يجب أن يمر حصراً عبر
 * get()/has()/all() أدناه — لا يجوز لأي كود مستهلِك قراءة $features الداخلية
 * مباشرة. get() لمفتاح غير موجود تُعيد null صراحة (§ "خطوات التنفيذ" رقم 4
 * في FEATURES-IMPLEMENTATION-PLAN.md Phase 1 — رفض صريح، لا تخمين)؛ لا ميزة
 * معرَّفة هنا قيمتها null، لذا null من get() يعني "غير موجود" بلا لبس.
 *
 * لا استهلاك لهذه الطبقة من أي صفحة مستخدم أو Resolver بعد (Phase 1 فقط).
 */

class PGE_Feature_Registry
{
    /**
     * الكاش الداخلي للتعريفات — يُبنى مرة واحدة فقط لكل Request.
     *
     * @var array<string, array>|null
     */
    private static $features = null;

    /**
     * بنية بيانات ثابتة لكل ميزة، منقولة حرفياً من PACKAGE-FEATURE-MATRIX.md
     * §6 (جدول Feature Registry)، بنفس الحقول الثمانية المعرَّفة في عناوين
     * ذلك الجدول: key, type, default, category, admin_label, description,
     * validation, lifecycle.
     *
     * القيمة 'TBD' في حقل default لثلاث ميزات (host_limit,
     * admin_supervisor_limit, invitation_design_limit) منقولة حرفياً كما هي
     * في §6 — القيمة الرقمية الفعلية معلَّقة صراحة في §10 من الوثيقة
     * المعمارية، ولا يجوز اختراعها هنا.
     *
     * @return array<string, array>
     */
    private static function features(): array
    {
        if (self::$features !== null) {
            return self::$features;
        }

        self::$features = [
            'host_limit' => [
                'key'         => 'host_limit',
                'type'        => 'integer',
                'default'     => 'TBD',
                'category'    => 'credits_and_limits',
                'admin_label' => 'عدد المضيفين الإضافيين',
                'description' => 'عدد الأشخاص الإضافيين المسموح تعيينهم كمضيفين لنفس المناسبة',
                'validation'  => 'عدد صحيح ≥ 0',
                'lifecycle'   => 'planned',
            ],
            'admin_supervisor_limit' => [
                'key'         => 'admin_supervisor_limit',
                'type'        => 'integer',
                'default'     => 'TBD',
                'category'    => 'credits_and_limits',
                'admin_label' => 'عدد مشرفي الدخول',
                'description' => 'عدد الأشخاص المسموح تفويضهم لتسجيل حضور الضيوف (Check-in)',
                'validation'  => 'عدد صحيح ≥ 0',
                'lifecycle'   => 'planned',
            ],
            'invitation_design_limit' => [
                'key'         => 'invitation_design_limit',
                'type'        => 'integer',
                'default'     => 'TBD',
                'category'    => 'credits_and_limits',
                'admin_label' => 'عدد تصاميم الدعوة',
                'description' => 'المعنى غير محسوم بعد (قوالب/صور/تصاميم مخصصة) — راجع §10 من الوثيقة المعمارية',
                'validation'  => 'عدد صحيح ≥ 0 (بعد حسم المعنى)',
                'lifecycle'   => 'planned',
            ],
            'event_website' => [
                'key'         => 'event_website',
                'type'        => 'boolean',
                'default'     => true,
                'category'    => 'event_experience',
                'admin_label' => 'موقع الحفل الإلكتروني',
                'description' => 'صفحة دعوة عامة مستقلة لكل مناسبة',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'production',
            ],
            'google_maps' => [
                'key'         => 'google_maps',
                'type'        => 'boolean',
                'default'     => true,
                'category'    => 'event_experience',
                'admin_label' => 'خرائط Google',
                'description' => 'إظهار/تفعيل حقل رابط الموقع على خرائط Google',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'production',
            ],
            'guest_qr' => [
                'key'         => 'guest_qr',
                'type'        => 'boolean',
                'default'     => true,
                'category'    => 'event_experience',
                'admin_label' => 'QR لكل دعوة',
                'description' => 'رمز QR يُرسَل لكل ضيف عند تأكيد الحضور',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'production',
            ],
            'rsvp' => [
                'key'         => 'rsvp',
                'type'        => 'boolean',
                'default'     => true,
                'category'    => 'event_experience',
                'admin_label' => 'تأكيد الحضور والاعتذار',
                'description' => 'تسجيل ردود الضيوف في wp_pge_event_rsvps',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'production',
            ],
            'attendance_statistics' => [
                'key'         => 'attendance_statistics',
                'type'        => 'boolean',
                'default'     => true,
                'category'    => 'event_experience',
                'admin_label' => 'إحصائية الحضور',
                'description' => 'عرض مؤشرات الحضور في لوحة المضيف',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'production',
            ],
            'guest_comments' => [
                'key'         => 'guest_comments',
                'type'        => 'boolean',
                'default'     => false,
                'category'    => 'event_experience',
                'admin_label' => 'التعليقات ورسائل التهنئة',
                'description' => 'تفعيل تبويب تعليقات/تهنئة للضيوف — التطابق مع public_chat/private_chat الحالي غير مؤكد',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'backend',
            ],
            'event_photo_album' => [
                'key'         => 'event_photo_album',
                'type'        => 'boolean',
                'default'     => false,
                'category'    => 'event_experience',
                'admin_label' => 'ألبوم صور المناسبة',
                'description' => 'ألبوم رسمي — التطابق مع guest_photos الحالي غير مؤكد',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'backend',
            ],
            'gift_feature' => [
                'key'         => 'gift_feature',
                'type'        => 'boolean',
                'default'     => false,
                'category'    => 'event_experience',
                'admin_label' => 'خاصية إرسال هدية',
                'description' => 'يتحكم المضيف نفسه بتفعيلها (لا المسؤول فقط كما في stc_pay الحالي)',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'backend',
            ],
            'invitation_message' => [
                'key'         => 'invitation_message',
                'type'        => 'boolean',
                'default'     => true,
                'category'    => 'messaging',
                'admin_label' => 'رسالة الدعوة الأولى',
                'description' => 'إرسال قالب invite عبر واتساب',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'production',
            ],
            'reminder_message' => [
                'key'         => 'reminder_message',
                'type'        => 'boolean',
                'default'     => false,
                'category'    => 'messaging',
                'admin_label' => 'رسالة تذكير',
                'description' => 'يتطلب قالب جديد + آلية جدولة غير موجودة اليوم',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'planned',
            ],
            'thank_you_message' => [
                'key'         => 'thank_you_message',
                'type'        => 'boolean',
                'default'     => false,
                'category'    => 'messaging',
                'admin_label' => 'رسالة شكر للحضور',
                'description' => 'يتطلب قالب جديد + آلية اكتشاف انتهاء المناسبة غير موجودة اليوم',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'planned',
            ],
            'decline_message' => [
                'key'         => 'decline_message',
                'type'        => 'boolean',
                'default'     => false,
                'category'    => 'messaging',
                'admin_label' => 'رسالة خاصة للمعتذرين',
                'description' => 'القالب (no) موجود فعلياً لكنه يعمل اليوم للجميع بلا بوابة Feature',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'backend',
            ],
            'custom_invitation_image' => [
                'key'         => 'custom_invitation_image',
                'type'        => 'boolean',
                'default'     => false,
                'category'    => 'messaging',
                'admin_label' => 'صورة دعوة مخصصة',
                'description' => 'صورة مستقلة عن الصورة البارزة العامة للمناسبة',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'planned',
            ],
            'custom_reminder_image' => [
                'key'         => 'custom_reminder_image',
                'type'        => 'boolean',
                'default'     => false,
                'category'    => 'messaging',
                'admin_label' => 'صورة تذكير مخصصة',
                'description' => 'يعتمد على reminder_message أولاً',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'planned',
            ],
            'custom_thank_you_image' => [
                'key'         => 'custom_thank_you_image',
                'type'        => 'boolean',
                'default'     => false,
                'category'    => 'messaging',
                'admin_label' => 'صورة شكر مخصصة',
                'description' => 'يعتمد على thank_you_message أولاً',
                'validation'  => 'true/false فقط',
                'lifecycle'   => 'planned',
            ],
            'support_services_discount_percentage' => [
                'key'         => 'support_services_discount_percentage',
                'type'        => 'percentage',
                'default'     => 0,
                'category'    => 'commercial',
                'admin_label' => 'خصم الخدمات المساندة',
                'description' => 'لا "خدمات مساندة" كمنتج قابل للطلب اليوم — القيمة بلا معنى تشغيلي بعد',
                'validation'  => '0-100 عدد صحيح',
                'lifecycle'   => 'planned',
            ],
        ];

        return self::$features;
    }

    /**
     * Registry Provider — التحقق من وجود ميزة معرَّفة.
     */
    public static function has(string $feature_key): bool
    {
        return array_key_exists($feature_key, self::features());
    }

    /**
     * Registry Provider — قراءة تعريف ميزة واحدة كاملاً.
     * تُعيد null صراحة إذا لم يكن المفتاح معرَّفاً في Registry (رفض صريح،
     * لا تخمين — لا ميزة معرَّفة هنا قيمتها null، فـnull من هنا لا لبس فيه).
     *
     * @return array|null
     */
    public static function get(string $feature_key)
    {
        $features = self::features();

        if (!array_key_exists($feature_key, $features)) {
            return null;
        }

        return $features[$feature_key];
    }

    /**
     * Registry Provider — قراءة كل التعريفات (19 ميزة بالضبط).
     *
     * @return array<string, array>
     */
    public static function all(): array
    {
        return self::features();
    }
}
