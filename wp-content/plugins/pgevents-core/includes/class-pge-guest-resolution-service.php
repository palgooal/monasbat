<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Guest Resolution Service — Guest Check-in Engine، Phase 4
 * ============================================================================
 * "Guest Check-in Engine" RFC، Requirement 2: "Guest Resolution — Resolve
 * guest from: QR or Manual search. Both paths must produce the same internal
 * Guest object. No duplicated logic." وRequirement 7: "Manual Check-in —
 * Implement search service only. No advanced UI. Support: Invitation code,
 * Guest name, Phone, QR fallback."
 *
 * هذا الملف **قراءة فقط** بالكامل — لا كتابة على wp_pge_event_rsvps ولا على
 * _pge_invited_guests إطلاقاً. مصدرا القراءة:
 *   1. wp_pge_event_rsvps (حالة RSVP/الحضور، عبر $wpdb مباشرة — نفس الجدول
 *      الذي تقرأ منه event-guests.php حالياً).
 *   2. pge_event_guests_get_map($event_id) الحالية (helpers.php/event-guests.php)
 *      — اسم الضيف ورمز دعوته الشخصي، بلا أي إعادة تنفيذ لمنطقها.
 *
 * ============================================================================
 * "Guest Object" الموحَّد (Requirement 2) — نفس البنية بالضبط من كلا المسارين
 * ============================================================================
 * ['event_id', 'rsvp_id' (null إن لم يُجب الضيف على الدعوة بعد قط ولا يوجد صف
 *  RSVP له أصلاً)، 'phone', 'name', 'invite_code', 'companions',
 *  'expected_guest_count' (1 + companions),
 *  'reply', 'checked_in' (bool), 'checked_in_at', 'checked_in_by_assignment_id',
 *  'checkin_method', 'actual_entered_count']
 *
 * كلا المسارين (resolve_from_qr()/search()) يمرّان حصراً عبر build_guest_object()
 * الخاصة الوحيدة أدناه لبناء هذه البنية — لا نسخة موازية في أي منهما
 * (Requirement 2: "No duplicated logic").
 *
 * ============================================================================
 * تصحيح معماري #1 (Blocking Issue): الهوية الموثوقة للتسجيل هي rsvp_id، لا الهاتف
 * ============================================================================
 * "The protected entity is the RSVP / invitation record, NOT the phone
 * number. Phone is only a lookup attribute." — PGE_Checkin_Recorder **لا
 * يقبل أبداً** رقم هاتف كمعامل؛ يستقبل حصراً Guest Object يحمل rsvp_id
 * موثوقاً بالفعل.
 *
 * ============================================================================
 * تصحيح معماري #2 (Blocking Issue): البحث بالهاتف قراءة فقط، لا إنشاء إطلاقاً
 * ============================================================================
 * "Check-in must never create invitation or RSVP records. Guest resolution
 * is read-only." — أُزيلت resolve_and_materialize_by_phone() نهائياً (كانت
 * تُنشئ صف RSVP جديداً لضيف لم يردّ على الدعوة قط قبل هذا التصحيح؛ هذا
 * السلوك محظور الآن بشكل قاطع). البديل الوحيد الآن هو resolve_by_phone()
 * أدناه — **قراءة فقط**، ولا تكتب أي شيء على wp_pge_event_rsvps إطلاقاً.
 *
 * بعد إزالة UNIQUE KEY (event_id, guest_phone) من الجدول الإنتاجي (راجع
 * PGE_Checkin_Schema::ensure_phone_index_not_unique()، SCHEMA_VERSION
 * 1.1.0)، قد يتطابق أكثر من صف RSVP واحد مع نفس رقم الهاتف ضمن المناسبة
 * نفسها. resolve_by_phone() تتعامل مع الحالات الثلاث صراحة:
 *   - 0 نتائج → ['result' => 'not_found', ...]
 *   - نتيجة واحدة فقط → ['result' => 'found', 'guest' => <Guest Object>]
 *     (rsvp_id حقيقي غير فارغ لأن صفاً فعلياً موجود بالفعل — لا حاجة لإنشائه).
 *   - أكثر من نتيجة → ['result' => 'ambiguous', 'candidates' => [...]]
 *     **لا اختيار صامت لأي صف إطلاقاً** — كل مرشَّح يحمل حصراً بيانات عرض
 *     آمنة (اسم، العدد المتوقَّع، هاتف مقنَّع) بالإضافة إلى `reference`: مرجع
 *     **مُوقَّع** (نفس آلية PGE_Checkin_QR_Service::build_payload() تماماً —
 *     `event_id|rsvp_id|hmac` — لا اختراع آلية توقيع جديدة). **لا rsvp_id خام
 *     يصل للمتصفح كمرساة تفويض إطلاقاً** — الحماية نفسها المُطبَّقة أصلاً على
 *     مسار QR. حين يختار المشرف لاحقاً أحد هذين المرشَّحين (واجهة مستقبلية،
 *     غير مُنفَّذة في هذه المرحلة)، طلب التأكيد يجب أن يُعيد هذا الـ`reference`
 *     نفسه كـ`identifier_type=qr` — يُعاد التحقق منه بالكامل عبر
 *     resolve_from_qr()/PGE_Checkin_QR_Service::validate() الموجودتين فعلاً
 *     (لا مسار حلّ جديد يُضاف لهذا الغرض؛ إعادة استخدام كاملة للآلية المُوقَّعة
 *     القائمة). هذا يُوثِّق ويُلبّي صراحة اشتراط: "A later confirmation request
 *     must still resolve the selected invitation through a trusted or signed
 *     reference before passing the Guest Object to the Recorder."
 *
 * ملاحظة نطاق: search() العامة (بحث بجزء من الاسم/رمز الدعوة/جزء من الهاتف،
 * القسم أدناه) لم تُعدَّل ولا علاقة لها بهذا التصحيح — تبقى قائمة على خريطة
 * _pge_invited_guests (مفهرَسة بالهاتف بطبيعتها، مصدر بيانات مختلف تماماً)
 * وتُعيد نتائج متعددة أصلاً حين يتطابق أكثر من ضيف مع الاستعلام. الإصلاح هنا
 * يخص حصراً نقطة "تحديد صف RSVP واحد بعينه بالهاتف الكامل تحضيراً للتسجيل"،
 * حيث كان الافتراض القديم (صف واحد فقط لكل هاتف) خاطئاً بعد إزالة القيد.
 *
 * ============================================================================
 * Phase 9A Final Fix ("Enforce Cancellation in the Real Check-in Path")
 * ============================================================================
 * قرار معماري صريح: "A cancelled invitation must never be checked in... This
 * must be enforced server-side in the shared check-in path... A minimal
 * targeted change to the shared Guest Resolution or Check-in Eligibility
 * layer is explicitly permitted for this fix." — أُضيف حارس أهلية إداري صغير
 * (is_invitation_administratively_cancelled() أدناه) يُستدعى في **نقطة
 * تقارب واحدة فقط**: resolve_by_rsvp_id() — وهي بالفعل المسار المشترك الذي
 * يمرّ عبره كل من: مسح QR (resolve_from_qr() تستدعيها في آخر سطر لها)، تأكيد
 * اختيار مرشَّح من قائمة "ambiguous" (يُرسَل كـidentifier_type='qr' بنفس
 * المرجع المُوقَّع الذي يحلّه resolve_from_qr() عبر نفس المسار)، وidentifier_
 * type='rsvp_id' المباشر — بالإضافة إلى resolve_by_phone() (مسار مستقل ثانٍ
 * حين تُطابِق صف RSVP واحد فقط بالهاتف). **لا تعديل على search()** (قراءة
 * عرض/بحث فقط، لا تُستهلَك أبداً مباشرة من مسار التسجيل).
 *
 * التسلسل المطلوب بالضبط: حلّ الصف (كالسابق تماماً) → تحميل الحالة الإدارية
 * للدعوة (Phase 9، `_pge_invitation_status`) → رفض فوري إن كانت "مُلغاة" (قبل
 * بناء Guest Object، وبالتالي **قبل** أي استدعاء ممكن لـPGE_Checkin_Recorder
 * من طبقة الاستدعاء checkin-ajax.php — لا كتابة حضور، لا سطر تدقيق حضور) →
 * إعادة نتيجة عمل ثابتة 'cancelled' (reason: invitation_cancelled) بدل
 * 'found'. لا تعديل على Recorder/المخطط/الإحصاء/لوحة المشرف/تفويض المشرف/بنية
 * RSVP إطلاقاً — هذا الملف فقط.
 *
 * توافق قديم (State Compatibility): دعوة بلا `_pge_invitation_status` إطلاقاً
 * (سابقة لـPhase 9، أو ضيف لا يملك سجل دعوة إدارية أصلاً) تُعامَل كـ"نشطة"
 * حصراً — لا حظر افتراضي، ولا حاجة لأي ترحيل بيانات. إن لم تكن
 * PGE_Invitation_Repository محمَّلة أصلاً لأي سبب دفاعي (بيئة اختبار Phase 4
 * القديمة مثلاً، لا تُحمِّل ملفات Phase 9) يُعامَل الأمر كذلك كـ"نشطة" — فشل
 * آمن نحو السلوك القديم المعروف، لا حظر شامل غير مبرَّر لكل تسجيلات الحضور.
 */
class PGE_Guest_Resolution_Service
{
    private static function rsvps_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_event_rsvps';
    }

    private static function normalize_positive_id($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            return (int) $value;
        }
        return 0;
    }

    /**
     * قراءة صف RSVP بمعرّفه، مقيَّدة بنفس المناسبة (تحقّق دفاعي إضافي —
     * "Guest must belong to event"، Requirement 3).
     */
    private static function find_rsvp_row_by_id(int $event_id, int $rsvp_id): ?array
    {
        global $wpdb;
        $table = self::rsvps_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d AND event_id = %d LIMIT 1", $rsvp_id, $event_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * قراءة صف RSVP بالهاتف (قد لا يوجد صف بعد — ضيف لم يردّ على الدعوة قط).
     */
    private static function find_rsvp_row_by_phone(int $event_id, string $phone): ?array
    {
        global $wpdb;
        $table = self::rsvps_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE event_id = %d AND guest_phone = %s LIMIT 1", $event_id, $phone),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * البانية الوحيدة لـ"Guest Object" الموحَّد — تُستهلَك من كل مسارات هذا
     * الملف، لا نسخة أخرى منها في أي مكان (Requirement 2).
     */
    private static function build_guest_object(int $event_id, string $phone, ?array $rsvp_row, array $meta): array
    {
        $companions = $rsvp_row ? (int) ($rsvp_row['companions'] ?? 0) : 0;
        $checked_in = $rsvp_row ? ((int) ($rsvp_row['checked_in'] ?? 0) === 1) : false;

        return [
            'event_id' => $event_id,
            'rsvp_id' => $rsvp_row ? (int) $rsvp_row['id'] : null,
            'phone' => $phone,
            'name' => (string) ($meta['name'] ?? ''),
            'invite_code' => (string) ($meta['code'] ?? ''),
            'companions' => $companions,
            'expected_guest_count' => 1 + $companions,
            'reply' => $rsvp_row ? (string) ($rsvp_row['reply'] ?? 'pending') : 'pending',
            'checked_in' => $checked_in,
            'checked_in_at' => $rsvp_row['checked_in_at'] ?? null,
            'checked_in_by_assignment_id' => isset($rsvp_row['checked_in_by_assignment_id']) && $rsvp_row['checked_in_by_assignment_id'] !== null
                ? (int) $rsvp_row['checked_in_by_assignment_id']
                : null,
            'checkin_method' => $rsvp_row['checkin_method'] ?? null,
            'actual_entered_count' => isset($rsvp_row['actual_entered_count']) && $rsvp_row['actual_entered_count'] !== null
                ? (int) $rsvp_row['actual_entered_count']
                : null,
        ];
    }

    /**
     * تحميل بيانات الاسم/رمز الدعوة الشخصي لهاتف مُحدَّد ضمن مناسبة —
     * إعادة استخدام pge_event_guests_get_map() الحالية حصراً، بلا أي قراءة
     * مباشرة بديلة لـ_pge_invited_guests.
     */
    private static function load_guest_meta(int $event_id, string $phone): array
    {
        $map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
        return $map[$phone] ?? ['name' => '', 'code' => ''];
    }

    /**
     * Phase 9A Final Fix — حارس أهلية إداري: هل هذه الدعوة (هاتف ضمن مناسبة)
     * مُلغاة إدارياً (`_pge_invitation_status` = 'cancelled')؟ قراءة فقط،
     * عبر PGE_Invitation_Repository::get_invitation() الحقيقية إن كانت
     * محمَّلة (لا إعادة تنفيذ منطق قراءة الحالة هنا — "Do NOT create logical
     * mirrors of production code"). غياب الطبقة أو غياب سجل الحالة كلاهما
     * يُفسَّر كـ"نشطة" (توافق قديم صريح، لا حظر افتراضي).
     */
    private static function is_invitation_administratively_cancelled(int $event_id, string $phone): bool
    {
        if (!class_exists('PGE_Invitation_Repository')) {
            return false;
        }
        $invitation = PGE_Invitation_Repository::get_invitation($event_id, $phone);
        if (!is_array($invitation)) {
            return false;
        }
        return ((string) ($invitation['invitation_status'] ?? 'active')) === 'cancelled';
    }

    /**
     * ========================================================================
     * Phase 9B QR Architecture Final Fix — إزالة "الانحدار الأمني" (Downgrade
     * Fallback) السابق نهائياً؛ إحلال حارس تدوير إصدار QR بدلاً منه
     * ========================================================================
     * "Phase 9B Final Fix" السابقة أضافت طبقة احتياطية تقبل invite_code خاماً
     * إن فشل التحقق الموقَّع — **أُزيلت بالكامل الآن**. القرار المُصحَّح: "QR is
     * an access credential. QR is NOT invitation identity." — invite_code
     * (رمز البحث اليدوي البشري) يبقى معزولاً تماماً عن مسار الماسح الإنتاجي؛
     * resolve_from_qr() لا تقبل أبداً أي صيغة غير الحمولة المُوقَّعة الكنسية
     * (event_id|rsvp_id|qr_version|signature عبر PGE_Checkin_QR_Service v2).
     *
     * البديل الصحيح لمشكلة "تجديد QR لا يُلاحَظ عبر المسار الإنتاجي" (الدافع
     * الأصلي للانحدار المُزال): بدائيّ تدوير حقيقي per-invitation — `qr_version`
     * (PGE_Invitation_Repository::get_qr_version()/regenerate_qr()). حارس
     * is_qr_version_current() أدناه يقارن qr_version المُستخرَج من الحمولة
     * المُوقَّعة (بعد نجاح التحقّق البنيوي/التشفيري في PGE_Checkin_QR_Service)
     * مع الإصدار الإداري النشط الحالي لتلك الدعوة — أي QR مُوقَّع صحيح تشفيرياً
     * لكن بإصدار قديم (بعد تجديد لاحق) يُرفَض بخطأ عمل ثابت: qr_superseded.
     *
     * هذا **ليس** إعادة تصميم لـGuest Resolution — هو استكمال دقيق لنفس نمط
     * "تحقق تشفيري في PGE_Checkin_QR_Service ← تحقق إداري إضافي هنا" المُتَّبع
     * أصلاً لحارس الإلغاء (is_invitation_administratively_cancelled()) أدناه.
     */
    private static function is_qr_version_current(int $event_id, int $rsvp_id, int $payload_qr_version): bool
    {
        if ($payload_qr_version <= 0) {
            return false;
        }

        $row = self::find_rsvp_row_by_id($event_id, $rsvp_id);
        if ($row === null) {
            return false;
        }

        $phone = (string) $row['guest_phone'];
        $expected_version = class_exists('PGE_Invitation_Repository')
            ? PGE_Invitation_Repository::get_qr_version($event_id, $phone)
            : 1;

        return $payload_qr_version === $expected_version;
    }

    /**
     * ========================================================================
     * المولِّد الكنسي الوحيد (Canonical Generator) لحمولة QR الماسح الإنتاجية
     * ========================================================================
     * "Create or reuse one canonical QR payload generator. All production
     * scanner QR producers must use it." — كل منتِج QR في المشروع (صورة الباب
     * عبر واتساب، مرجع البحث اليدوي، مرشَّحو الغموض في resolve_by_phone())
     * يجب أن يستدعي هذه الدالة حصراً، لا PGE_Checkin_QR_Service::build_payload()
     * مباشرة — تضمن دائماً تمرير qr_version **الحالي الصحيح** لتلك الدعوة
     * تحديداً (لا قيمة ثابتة/افتراضية قد تُصبح قديمة بعد أول تجديد).
     *
     * @param string $phone الهاتف المُطبَّع لصاحب rsvp_id — مطلوب لجلب qr_version
     *                      الإداري الحالي (Phase 9). لا صلة له بمحتوى الحمولة
     *                      نفسها (لا بيانات ضيف خام داخل QR، كما في v1 دوماً).
     * @return string '' إن تعذَّر البناء (event_id/rsvp_id غير صالحين أو
     *                الخدمة غير محمَّلة) — لا استثناء، تعامل صريح بلا قيمة صامتة.
     */
    public static function build_scanner_qr_payload(int $event_id, int $rsvp_id, string $phone): string
    {
        if (!class_exists('PGE_Checkin_QR_Service')) {
            return '';
        }
        $event_id = self::normalize_positive_id($event_id);
        $rsvp_id = self::normalize_positive_id($rsvp_id);
        if ($event_id === 0 || $rsvp_id === 0) {
            return '';
        }

        $qr_version = class_exists('PGE_Invitation_Repository')
            ? PGE_Invitation_Repository::get_qr_version($event_id, $phone)
            : 1;

        return PGE_Checkin_QR_Service::build_payload($event_id, $rsvp_id, $qr_version);
    }

    /**
     * المسار الأول: مسح QR (Requirement 2/Requirement 1 عبر PGE_Checkin_QR_Service).
     * Phase 9B QR Architecture Final Fix: لا سقوط احتياطي لأي صيغة أخرى بعد
     * الآن — حمولة موقَّعة كنسية صحيحة (تشفيرياً + إصدار نشط مطابق) أو رفض.
     *
     * @return array{result:'found',guest:array}|array{result:'not_found'|'invalid'|'cancelled',reason:string}
     */
    public static function resolve_from_qr(int $event_id, string $raw_qr_payload): array
    {
        if (!class_exists('PGE_Checkin_QR_Service')) {
            return ['result' => 'invalid', 'reason' => 'qr_service_unavailable'];
        }

        $validation = PGE_Checkin_QR_Service::validate($event_id, $raw_qr_payload);
        if (($validation['result'] ?? '') !== 'valid') {
            return ['result' => 'invalid', 'reason' => (string) ($validation['reason'] ?? 'invalid_qr')];
        }

        $rsvp_id = (int) $validation['rsvp_id'];
        $payload_qr_version = (int) ($validation['qr_version'] ?? 0);

        if (!self::is_qr_version_current($event_id, $rsvp_id, $payload_qr_version)) {
            return ['result' => 'invalid', 'reason' => 'qr_superseded'];
        }

        return self::resolve_by_rsvp_id($event_id, $rsvp_id);
    }

    /**
     * المسار الثاني: استئناف بضيف مُختار مسبقاً من نتائج بحث يدوي (يعيد
     * التحقق من الانتماء للمناسبة دفاعياً — لا وثوق بأي اختيار سابق للعميل).
     *
     * @return array{result:'found',guest:array}|array{result:'not_found'|'cancelled',reason:string}
     */
    public static function resolve_by_rsvp_id(int $event_id, int $rsvp_id): array
    {
        $event_id = self::normalize_positive_id($event_id);
        $rsvp_id = self::normalize_positive_id($rsvp_id);

        if ($event_id === 0 || $rsvp_id === 0) {
            return ['result' => 'not_found', 'reason' => 'invalid_arguments'];
        }

        $row = self::find_rsvp_row_by_id($event_id, $rsvp_id);
        if ($row === null) {
            return ['result' => 'not_found', 'reason' => 'invitation_not_found'];
        }

        $phone = (string) $row['guest_phone'];

        // RC1 Hard Delete Semantics Fix Pack (Blocker 1 + Blocker 2) — نفس نقطة
        // التقارب المشتركة أدناه (QR + rsvp_id المباشر + تأكيد اختيار مرشَّح):
        // صف RSVP لا ينتمي لدورة حياة الدعوة الحالية لهذا الهاتف (دعوة محذوفة
        // ولم تُعَد إنشاؤها، أو صف يتيم من دورة حياة سابقة قبل إعادة الإنشاء)
        // يُعامَل كأنه غير موجود إطلاقاً — قبل فحص الإلغاء، وقبل أي احتمال
        // لاستدعاء PGE_Checkin_Recorder. راجع
        // PGE_Invitation_Repository::is_rsvp_row_current() للسبب الجذري الكامل.
        if (class_exists('PGE_Invitation_Repository') && !PGE_Invitation_Repository::is_rsvp_row_current($event_id, $phone, $row['created_at'] ?? null)) {
            return ['result' => 'not_found', 'reason' => 'invitation_not_found'];
        }

        // Phase 9A Final Fix: نقطة التقارب المشتركة (QR + rsvp_id المباشر +
        // تأكيد اختيار مرشَّح) — رفض فوري قبل بناء Guest Object، قبل أي
        // احتمال لاستدعاء PGE_Checkin_Recorder من طبقة الاستدعاء.
        if (self::is_invitation_administratively_cancelled($event_id, $phone)) {
            return ['result' => 'cancelled', 'reason' => 'invitation_cancelled'];
        }

        $meta = self::load_guest_meta($event_id, $phone);

        return ['result' => 'found', 'guest' => self::build_guest_object($event_id, $phone, $row, $meta)];
    }

    /**
     * قراءة **كل** صفوف RSVP المطابقة لهاتف مُحدَّد ضمن مناسبة (بلا LIMIT) —
     * بعد إزالة UNIQUE KEY (event_id, guest_phone) قد يكون العدد أكثر من صف
     * واحد؛ resolve_by_phone() أدناه هو المستهلك الوحيد المخوَّل لهذا التمييز
     * الصريح (0/1/أكثر) — find_rsvp_row_by_phone() أعلاه (المفرد، بـLIMIT 1)
     * يبقى للاستخدامات القائمة التي لا علاقة لها بهذا التصحيح (search()).
     */
    private static function find_rsvp_rows_by_phone(int $event_id, string $phone): array
    {
        global $wpdb;
        $table = self::rsvps_table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE event_id = %d AND guest_phone = %s ORDER BY id ASC", $event_id, $phone),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    private static function normalize_phone_value($value): string
    {
        if (function_exists('pge_norm_phone')) {
            return pge_norm_phone($value);
        }
        return preg_replace('/\D+/', '', trim((string) $value));
    }

    /**
     * تقنيع الهاتف للعرض الآمن ضمن مرشَّحي "ambiguous" — نفس قاعدة التقنيع
     * المُستخدَمة أصلاً في PGE_Supervisor_Portal_Bootstrap::mask_phone()
     * (إخفاء كل الأرقام إلا آخر 4)، بلا استيراد اعتمادية على تلك الفئة.
     */
    private static function mask_phone_for_display(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $len = strlen($digits);
        if ($len <= 4) {
            return $digits;
        }
        return str_repeat('•', $len - 4) . substr($digits, -4);
    }

    /**
     * ========================================================================
     * البحث بالهاتف الكامل — قراءة فقط، يدعم 0/1/أكثر من نتيجة صراحة
     * ========================================================================
     * راجع تعليق "تصحيح معماري #2" أعلى الملف للسياق الكامل. **لا تكتب على
     * wp_pge_event_rsvps إطلاقاً** (لا INSERT ولا UPDATE) — الاستبدال المباشر
     * لـresolve_and_materialize_by_phone() المُزالة.
     *
     * @return array{result:'not_found'|'cancelled',reason:string}
     *       | array{result:'found',guest:array}
     *       | array{result:'ambiguous',candidates:array<int,array{reference:string,name:string,expected_guest_count:int,masked_phone:string}>}
     */
    public static function resolve_by_phone(int $event_id, string $phone): array
    {
        $event_id = self::normalize_positive_id($event_id);
        $phone = self::normalize_phone_value($phone);

        if ($event_id === 0 || $phone === '') {
            return ['result' => 'not_found', 'reason' => 'invalid_arguments'];
        }

        $rows = self::find_rsvp_rows_by_phone($event_id, $phone);

        // RC1 Hard Delete Semantics Fix Pack (Blocker 1 + Blocker 2 + Blocker 3)
        // — تُستبعَد هنا أي صفوف لا تنتمي لدورة حياة الدعوة الحالية لهذا الهاتف
        // (نفس الحارس المُستخدَم في resolve_by_rsvp_id()، لا نسخة موازية منه)
        // قبل أي فرع لاحق (not_found/found/ambiguous) — فيبقى صف يتيم من دعوة
        // محذوفة أو من دورة حياة سابقة (قبل إعادة إنشاء بنفس الهاتف) خارج أي
        // نتيجة يمكن الوصول عبرها لـPGE_Checkin_Recorder، وخارج قائمة المرشَّحين
        // عند تعدد الصفوف أيضاً.
        if (class_exists('PGE_Invitation_Repository')) {
            $rows = array_values(array_filter($rows, function ($row) use ($event_id, $phone) {
                return PGE_Invitation_Repository::is_rsvp_row_current($event_id, $phone, $row['created_at'] ?? null);
            }));
        }

        if (count($rows) === 0) {
            return ['result' => 'not_found', 'reason' => 'invitation_not_found'];
        }

        if (count($rows) === 1) {
            // Phase 9A Final Fix: نفس الحارس المُطبَّق في resolve_by_rsvp_id() —
            // مطابقة هاتف واحدة فقط تعني هوية دعوة واحدة واضحة، فتخضع لنفس
            // القاعدة قبل بناء Guest Object.
            if (self::is_invitation_administratively_cancelled($event_id, $phone)) {
                return ['result' => 'cancelled', 'reason' => 'invitation_cancelled'];
            }
            $meta = self::load_guest_meta($event_id, $phone);
            return ['result' => 'found', 'guest' => self::build_guest_object($event_id, $phone, $rows[0], $meta)];
        }

        // أكثر من صف — لا اختيار صامت لأي منها (Blocking Issue #2).
        $meta = self::load_guest_meta($event_id, $phone);
        $masked_phone = self::mask_phone_for_display($phone);
        $candidates = [];
        foreach ($rows as $row) {
            $rsvp_id = (int) ($row['id'] ?? 0);
            if ($rsvp_id <= 0 || !class_exists('PGE_Checkin_QR_Service')) {
                continue;
            }
            $candidates[] = [
                // مرجع مُوقَّع كنسي (المولِّد الوحيد — build_scanner_qr_payload())
                // — لا rsvp_id خام يصل للمتصفح كمرساة تفويض. الاختيار لاحقاً =
                // نفس مسار resolve_from_qr() الموجود فعلاً (لا مسار حلّ جديد).
                // Phase 9B QR Architecture Final Fix: يحمل الآن qr_version
                // الحالي الصحيح لهذه الدعوة (لا صيغة قديمة قد تُرفَض كـqr_superseded).
                'reference' => self::build_scanner_qr_payload($event_id, $rsvp_id, $phone),
                'name' => (string) ($meta['name'] ?? ''),
                'expected_guest_count' => 1 + (int) ($row['companions'] ?? 0),
                'masked_phone' => $masked_phone,
            ];
        }

        return ['result' => 'ambiguous', 'candidates' => $candidates];
    }

    /**
     * المسار الثالث: بحث يدوي (Requirement 7) — رمز دعوة/اسم/جوال، ضمن
     * مدعوّي هذه المناسبة تحديداً فقط (لا تسريب عبر مناسبات أخرى).
     *
     * @return array{result:'found', guests: array<int,array>}
     */
    public static function search(int $event_id, string $query): array
    {
        $event_id = self::normalize_positive_id($event_id);
        $query = trim($query);

        if ($event_id === 0 || $query === '') {
            return ['result' => 'found', 'guests' => []];
        }

        $normalized_code = function_exists('pge_normalize_invite_code') ? pge_normalize_invite_code($query) : '';
        $normalized_phone = function_exists('pge_norm_phone') ? pge_norm_phone($query) : preg_replace('/\D+/', '', $query);

        $map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];

        $matched_phones = [];
        foreach ($map as $phone => $meta) {
            $name = (string) ($meta['name'] ?? '');
            $code = (string) ($meta['code'] ?? '');

            $matches_code = ($normalized_code !== '' && $code !== '' && $code === $normalized_code);
            $matches_phone = ($normalized_phone !== '' && strpos($phone, $normalized_phone) !== false);
            $matches_name = ($name !== '' && stripos($name, $query) !== false);

            if ($matches_code || $matches_phone || $matches_name) {
                $matched_phones[] = $phone;
            }

            // حماية بسيطة من إعادة قائمة مدعوين كاملة عبر استعلام فارغ تقريباً
            // (لا تعداد كامل — Edge Case §14 بند 8 في وثيقة التصميم).
            if (count($matched_phones) >= 25) {
                break;
            }
        }

        $guests = [];
        foreach ($matched_phones as $phone) {
            $row = self::find_rsvp_row_by_phone($event_id, $phone);
            $meta = $map[$phone] ?? ['name' => '', 'code' => ''];
            $guests[] = self::build_guest_object($event_id, $phone, $row, $meta);
        }

        return ['result' => 'found', 'guests' => $guests];
    }
}
