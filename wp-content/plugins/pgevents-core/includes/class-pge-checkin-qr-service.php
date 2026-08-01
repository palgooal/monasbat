<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Checkin QR Service — Guest Check-in Engine، Phase 4
 * ============================================================================
 * "Guest Check-in Engine" RFC، Requirement 1: "QR Validation Service — Create
 * a dedicated service. Responsibilities: Validate QR payload, Validate
 * signature, Validate event, Validate invitation, Reject tampered QR codes,
 * Reject reused invalid payloads. No UI logic."
 *
 * هذا الملف **قراءة فقط** بالكامل تجاه wp_pge_event_rsvps (تحقّق وجود الصف
 * فقط، لا كتابة إطلاقاً)، ولا يحتوي أي HTML/echo/wp_die — طبقة تحقّق منطقي
 * بحتة قابلة للاستدعاء من أي واجهة مستقبلية (AJAX، REST، CLI) بلا تغيير.
 *
 * ============================================================================
 * صيغة الحمولة الكنسية (Canonical Payload Format) — Phase 9B QR Architecture
 * Final Fix، تُبطِل الصيغة السابقة (v1) بالكامل
 * ============================================================================
 * أربعة أجزاء مفصولة بـ"|": `event_id|rsvp_id|qr_version|signature`
 *   - event_id: معرّف المناسبة كما وُلِّد وقت إنشاء QR (بيانات، لا سلطة —
 *     السلطة الفعلية تأتي حصراً من event_id المُصادَق عليه عبر جلسة المشرف،
 *     الممرَّر كمعامل $authorized_event_id في validate()، لا من داخل QR نفسه).
 *   - rsvp_id: معرّف صف الضيف في wp_pge_event_rsvps (لا بيانات ضيف خام —
 *     لا اسم، لا جوال — بنفس مبدأ "لا بيانات ضيف خام داخل QR" في الوثيقة).
 *   - qr_version: بدائيّ التدوير (rotation primitive) — رقم صحيح موجب، لا
 *     سرّ (آمن للكشف/التسجيل). هذه الطبقة لا "تفهم" معناه الإداري (Phase 9،
 *     PGE_Invitation_Repository::get_qr_version()) — فقط توقِّعه كجزء بيانات
 *     خام ضمن الحمولة. مطابقة القيمة مع "الإصدار النشط الحالي" لتلك الدعوة
 *     مسؤولية طبقة أعلى (PGE_Guest_Resolution_Service::is_qr_version_
 *     current())، بفصل معماري مقصود — راجع docs/INVITATION-QR-ARCHITECTURE.md.
 *   - signature: wp_hash($event_id.'|'.$rsvp_id.'|'.$qr_version.'|'.CONTEXT)
 *     — يُغطّي الحقول الأربعة معاً (event_id/rsvp_id/qr_version)، فأي تلاعب
 *     بأي جزء منها (بما فيه qr_version نفسه) يُبطِل التوقيع فوراً.
 *
 * توليد لحظي بلا تخزين (كما في v1): لا جدول توكنات جديد — الحمولة تُشتَق
 * حسابياً من (event_id, rsvp_id, qr_version) في أي لحظة.
 *
 * ============================================================================
 * ملاحظة نطاق صريحة (Scope Note) — مُحدَّثة
 * ============================================================================
 * هذا الملف يوفّر build_payload() (لبناء الحمولة) وvalidate() (للتحقق منها)
 * فقط — طبقة تحقّق بنيوي/تشفيري بحتة، لا معرفة لها بمعنى qr_version الإداري
 * (Phase 9). منتِجو QR الفعليون (class-cartat-handler.php/class-ultramsg-
 * handler.php/checkin-ui-ajax.php) يستهلكون الآن PGE_Guest_Resolution_
 * Service::build_scanner_qr_payload() الكنسية (لا هذا الملف مباشرة) لضمان
 * تمرير qr_version الحالي الصحيح دائماً — راجع تلك الدالة والوثيقة أعلاه.
 */
class PGE_Checkin_QR_Service
{
    /**
     * سلسلة سياق ثابتة داخل التوقيع — تمنع أي احتمال (نظري) لإعادة استخدام
     * نفس (event_id, rsvp_id, qr_version) الموقَّعة لغرض مختلف تماماً في أي
     * مكان آخر من المشروع يستخدم wp_hash() بنفس المُدخَلات الخام. رُقِّمت
     * v2 (Phase 9B QR Architecture Final Fix) عمداً — أي حمولة v1 قديمة
     * (ثلاثة أجزاء فقط، بلا qr_version) تفشل الآن حتماً كـmalformed_payload
     * (لا تنسيق قديم مقبول، لا فك تشفير مزدوج).
     */
    private const CONTEXT = 'pge_checkin_qr_v2';

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

    private static function sign(int $event_id, int $rsvp_id, int $qr_version): string
    {
        return wp_hash($event_id . '|' . $rsvp_id . '|' . $qr_version . '|' . self::CONTEXT);
    }

    /**
     * بناء حمولة QR كنسية لضيف مُحدَّد ضمن مناسبة مُحدَّدة بإصدار مُحدَّد.
     * **لا تُستدعى مباشرة من أي منتِج QR** — الاستدعاء الصحيح دائماً عبر
     * PGE_Guest_Resolution_Service::build_scanner_qr_payload() التي تجلب
     * qr_version الحالي الصحيح أولاً (راجع ملاحظة النطاق أعلاه).
     */
    public static function build_payload(int $event_id, int $rsvp_id, int $qr_version): string
    {
        $event_id = self::normalize_positive_id($event_id);
        $rsvp_id = self::normalize_positive_id($rsvp_id);
        $qr_version = $qr_version > 0 ? $qr_version : 1;

        return $event_id . '|' . $rsvp_id . '|' . $qr_version . '|' . self::sign($event_id, $rsvp_id, $qr_version);
    }

    /**
     * التحقق البنيوي/التشفيري الكامل من حمولة QR (خطوات 1-4 من "Validation"
     * في Phase 9B QR Architecture Final Fix؛ خطوة 5 — مطابقة qr_version مع
     * الإصدار الإداري النشط — مسؤولية الطبقة الأعلى عمداً، لا هذا الملف).
     *
     * @param int    $authorized_event_id المناسبة الموثوقة للمشرف الحالي —
     *                                     مصدرها حصراً PGE_Supervisor_Portal_
     *                                     Middleware::authorize()، لا أي معامل
     *                                     طلب آخر.
     * @param string $raw_payload         النص الخام كما وصل من الماسح.
     * @return array{result:'valid','rsvp_id':int,'event_id':int,'qr_version':int}|array{result:'invalid','reason':string}
     */
    public static function validate(int $authorized_event_id, string $raw_payload): array
    {
        $authorized_event_id = self::normalize_positive_id($authorized_event_id);
        if ($authorized_event_id === 0) {
            return ['result' => 'invalid', 'reason' => 'invalid_authorized_event'];
        }

        $raw_payload = trim($raw_payload);
        if ($raw_payload === '') {
            return ['result' => 'invalid', 'reason' => 'empty_payload'];
        }

        // 1) تحقّق التنسيق: أربعة أجزاء بالضبط، لا أكثر ولا أقل (Reject
        // tampered/legacy). لا سقوط احتياطي لأي صيغة أخرى (invite_code
        // خام، v1 القديمة ذات الثلاثة أجزاء) — استُبعِد نهائياً هنا.
        $parts = explode('|', $raw_payload);
        if (count($parts) !== 4) {
            return ['result' => 'invalid', 'reason' => 'malformed_payload'];
        }

        [$payload_event_id_raw, $payload_rsvp_id_raw, $payload_qr_version_raw, $signature] = $parts;

        $payload_event_id = self::normalize_positive_id($payload_event_id_raw);
        $payload_rsvp_id = self::normalize_positive_id($payload_rsvp_id_raw);
        $payload_qr_version = self::normalize_positive_id($payload_qr_version_raw);

        if ($payload_event_id === 0 || $payload_rsvp_id === 0 || $payload_qr_version === 0 || $signature === '') {
            return ['result' => 'invalid', 'reason' => 'malformed_payload'];
        }

        // 2) تحقّق التوقيع (Validate signature) — hash_equals() لا مقارنة
        // مباشرة، بنفس نمط access-gate.php حرفياً. أي تعديل على أي جزء من
        // الحمولة (بما فيه qr_version) يُبطِل التوقيع فوراً (Reject tampered).
        $expected_signature = self::sign($payload_event_id, $payload_rsvp_id, $payload_qr_version);
        if (!hash_equals($expected_signature, (string) $signature)) {
            return ['result' => 'invalid', 'reason' => 'signature_mismatch'];
        }

        // 3) تحقّق المناسبة (Validate event) — event_id المُستخرَج من QR يجب أن
        // يطابق **تماماً** المناسبة الموثوقة لجلسة المشرف الحالية. هذا يرفض
        // QR مناسبة أخرى حتى لو كان توقيعه صحيحاً تماماً لمناسبته الأصلية.
        if ($payload_event_id !== $authorized_event_id) {
            return ['result' => 'invalid', 'reason' => 'event_mismatch'];
        }

        // 4) تحقّق الدعوة (Validate invitation) — الصف لا يزال موجوداً فعلياً
        // في wp_pge_event_rsvps ضمن نفس المناسبة (Reject reused invalid
        // payloads: لو حُذف الضيف لاحقاً، يُرفَض QR القديم رغم توقيعه الصحيح).
        global $wpdb;
        $table = $wpdb->prefix . 'pge_event_rsvps';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM $table WHERE id = %d AND event_id = %d LIMIT 1", $payload_rsvp_id, $payload_event_id),
            ARRAY_A
        );

        if ($row === null) {
            return ['result' => 'invalid', 'reason' => 'invitation_not_found'];
        }

        return ['result' => 'valid', 'rsvp_id' => $payload_rsvp_id, 'event_id' => $payload_event_id, 'qr_version' => $payload_qr_version];
    }
}
