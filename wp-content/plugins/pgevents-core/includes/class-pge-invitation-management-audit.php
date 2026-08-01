<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Management Audit — Entry Check-in Supervisors، Phase 9
 * ============================================================================
 * "Host Invitation Management" RFC، Requirement Audit: "Append-only. Record:
 * Create, Edit, Cancel, Resend, QR Regeneration. Actor, Timestamp, Reason
 * where applicable. Never overwrite history." — راجع "Phase 9B Final Fix"
 * أدناه: حدث "Resend" يُسجَّل فعلياً باسم 'delivery_requested' (لا 'resent')
 * لأن لا قناة تسليم فعلية مربوطة بعد.
 *
 * جدول مستقل تماماً عن pge_checkin_audit_log (Phase 4، حضور فعلي عند البوابة)
 * وعن pge_supervisor_mgmt_audit_log (Phase 8، دورة حياة إسناد المشرف) — هذا
 * الملف الوسيط الوحيد للكتابة/القراءة على pge_invitation_mgmt_audit_log.
 * Append-Only بحت: لا UPDATE ولا DELETE على أي صف إطلاقاً.
 */
class PGE_Invitation_Management_Audit
{
    /**
     * ========================================================================
     * Phase 9B Final Fix ("Resend semantics") — 'resent' استُبدلت نهائياً بـ
     * 'delivery_requested'
     * ========================================================================
     * المشروع الآن **بلا أي قناة تسليم فعلية مربوطة** لهذه العملية (لا بريد،
     * لا SMS، لا واتساب، لا مُرسِل إشعارات) — resend() (الخدمة/الـRepository)
     * تُحدِّث updated_at في `_pge_invitation_status` فقط، بلا إرسال أي شيء
     * فعلياً. تسجيل action='resent' كان يزعم ضمنياً أن رسالة أُعيد إرسالها
     * بالفعل — ادّعاء غير صحيح لا يعكس الواقع. 'delivery_requested' يُوثِّق
     * المعنى الحقيقي بدقة: "المضيف طلب إعادة تسليم، لم يُنفَّذ أي تسليم فعلي
     * بعد" — الخدمة/الواجهة/نقطة الـAJAX لم تتغيَّر (كما هي بالضبط)، فقط اسم
     * حدث التدقيق المكتوب. 'resent' حُذفت نهائياً من ACTIONS/ACTIVE_ACTIONS —
     * لا يجوز أن يُكتَب هذا الاسم بعد الآن مهما كان مصدر الاستدعاء.
     *
     * توافق مستقبلي (Future compatibility): حين تُبنى مراحل إشعارات فعلية
     * لاحقاً (بريد/SMS/واتساب)، لن يُعاد تفسير 'delivery_requested' الموجودة
     * تاريخياً ولا تُحذَف/تُعدَّل (Append-Only يبقى قاعدة صارمة) — بدلاً من ذلك
     * تُضاف أنواع أحداث تدقيق **جديدة** فقط (مثال: 'actual_delivery' عند بدء
     * محاولة التسليم الفعلي فعلياً، ثم 'delivery_result' يحمل النتيجة النهائية
     * ناجحة/فاشلة) إلى ACTIONS/ACTIVE_ACTIONS في مرحلة تلك RFC، بحيث تبقى
     * الصفوف القديمة سجلاً تاريخياً صحيحاً وثابت المعنى: "طُلب تسليم في هذا
     * التوقيت" — لا أكثر ولا أقل، والتسلسل الزمني الكامل (طلب ← محاولة ← نتيجة)
     * يُقرأ لاحقاً من تعدد الصفوف نفسها عبر list_for_invitation()، لا من تعديل
     * صف واحد.
     */
    /**
     * Phase 9C ("Invitation Export"): أُضيف 'export_completed' — "Export is a
     * business action. Append exactly one audit event... Store only: User,
     * Event, Format, Record count, Timestamp. Never store exported contents."
     * لا عمود جديد في الجدول (بنية الجدول مُجمَّدة صراحة هذه المرحلة): User=
     * actor_user_id، Event=event_id، Timestamp=created_at (أعمدة موجودة أصلاً)،
     * وFormat+Record count يُرمَّزان معاً داخل عمود `reason` النصي الموجود
     * أصلاً (JSON مضغوط، مثال: {"format":"csv","count":42}) — لا تعديل على
     * الجدول/record()/list_for_invitation() إطلاقاً. `guest_phone` هنا سطر
     * على مستوى المناسبة كاملة (لا دعوة واحدة محدَّدة) — يُستخدَم سنتينل ثابت
     * موثَّق '__event_export__' (نص عادي في نفس عمود VARCHAR الحالي، بلا أي
     * تغيير بنيوي)، فلا يظهر ضمن list_for_invitation() لأي دعوة فردية (سلوك
     * مقصود: لا تسريب لسجل التصدير داخل تاريخ دعوة بعينها).
     */
    /**
     * RC1 Fix Pack 3A ("Invitation Bulk Add Migration"): أُضيف
     * 'bulk_create_completed' — حدث تدقيق واحد على مستوى المناسبة (لا دعوة
     * واحدة) يُسجَّل مرة واحدة بعد كل عملية "تأكيد إضافة جماعية"، بغضّ النظر
     * عن عدد الصفوف. لا عمود جديد (نفس قيد export_completed أعلاه): العدّاد
     * الكامل (Total/Created/Duplicate/Invalid/Failed) يُرمَّز JSON مضغوطاً
     * داخل عمود `reason` النصي الحالي. **لا يُخزَّن النص الملصوق الخام، ولا
     * أي اسم مدعو أو رقم جوال في هذا السطر على مستوى الدفعة إطلاقاً** — كل
     * دعوة تُنشأ بنجاح ضمن الدفعة تُسجِّل حدث 'created' الفردي الحالي بنفسه
     * (بلا تغيير) عبر PGE_Invitation_Service::create()، الذي يحمل الهاتف
     * الفردي أصلاً — هذا السطر الإضافي ملخّص إحصائي فقط.
     */
    /**
     * RC1 Fix Pack 3B ("Legacy Guest Panel Retirement — Hard Delete
     * Migration"): أُضيف 'deleted' — حدث تدقيق فردي واحد لكل دعوة تُحذَف
     * نهائياً (حذف مفرد أو ضمن حذف جماعي، بلا فرق) عبر PGE_Invitation_
     * Service::delete(). لا عمود جديد، لا حدث دفعي على مستوى المناسبة (خلافاً
     * لـ'bulk_create_completed' أعلاه) — "One row per deleted invitation. No
     * event-level batch audit required." حسب RFC صراحة.
     */
    const ACTIONS = ['created', 'edited', 'cancelled', 'deleted', 'delivery_requested', 'qr_regenerated', 'export_completed', 'bulk_create_completed'];

    /**
     * سنتينل هاتف لسجلات التدقيق على مستوى المناسبة كاملة (لا دعوة واحدة) —
     * راجع تعليق export_completed أعلاه. ثابت واحد مُعاد استخدامه في كل مكان
     * (لا نص حرفي مُكرَّر) لضمان تطابقه دائماً بين الكتابة والقراءة المستقبلية.
     */
    const EVENT_LEVEL_PHONE_SENTINEL = '__event_export__';

    /**
     * سنتينل مستقل تماماً عن EVENT_LEVEL_PHONE_SENTINEL أعلاه — تعمّد عدم
     * إعادة استخدام سنتينل التصدير هنا رغم تشابه الفكرة (حدث مستوى مناسبة)،
     * حفاظاً على وضوح دلالي كامل عند القراءة المستقبلية لأي سجل تدقيق (لا
     * التباس بين "تصدير" و"إضافة جماعية" لمن يقرأ عمود guest_phone مباشرة).
     */
    const EVENT_LEVEL_BULK_ADD_SENTINEL = '__event_bulk_add__';

    /**
     * سطح التدقيق النشط فعلياً حالياً — آلية بوابة مقصودة (وليست جزءاً من
     * مخطط/بنية جدول التدقيق نفسه، وليست تعديلاً على record()/list_for_
     * invitation() كعقد) تسمح باعتماد أنواع الإجراءات تدريجياً مرحلة بمرحلة،
     * دون أي تعديل على "Audit architecture" (الجدول/الطريقة تبقيان كما هما).
     * راجع تعليق ACTIONS أعلاه لتاريخ استبدال 'resent' بـ'delivery_requested'
     * ولإضافة 'export_completed' في Phase 9C.
     */
    const ACTIVE_ACTIONS_PHASE_9C = ['created', 'edited', 'cancelled', 'deleted', 'delivery_requested', 'qr_regenerated', 'export_completed', 'bulk_create_completed'];

    private static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_invitation_mgmt_audit_log';
    }

    /**
     * @param int    $event_id
     * @param string $guest_phone   الهاتف المُطبَّع (المفتاح الطبيعي للدعوة ضمن مناسبة واحدة).
     * @param int    $actor_user_id المضيف/الأدمن الذي نفَّذ الإجراء.
     * @param string $action        إحدى قيم ACTIONS.
     * @param string $reason        سبب اختياري (يُستخدَم فعلياً عند الإلغاء).
     * @return bool
     */
    public static function record($event_id, $guest_phone, $actor_user_id, $action, $reason = ''): bool
    {
        $event_id = (int) $event_id;
        $guest_phone = is_scalar($guest_phone) ? (string) $guest_phone : '';
        $actor_user_id = (int) $actor_user_id;
        $action = is_scalar($action) ? (string) $action : '';
        $reason = is_scalar($reason) ? (string) $reason : '';

        if ($event_id <= 0 || $guest_phone === '' || $action === '') {
            return false;
        }

        // بوابة سطح التدقيق — راجع تعليق ACTIVE_ACTIONS_PHASE_9C أعلاه.
        if (!in_array($action, self::ACTIVE_ACTIONS_PHASE_9C, true)) {
            return false;
        }

        global $wpdb;

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'event_id'      => $event_id,
                'guest_phone'   => $guest_phone,
                'action'        => $action,
                'actor_user_id' => $actor_user_id,
                'reason'        => $reason,
                'created_at'    => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s']
        );

        return (bool) $inserted;
    }

    /**
     * سجل التدقيق الكامل لدعوة واحدة (مناسبة + هاتف) — قراءة فقط، مُرتَّبة
     * زمنياً (الأقدم أولاً).
     *
     * @return array<int,array>
     */
    public static function list_for_invitation($event_id, $guest_phone): array
    {
        $event_id = (int) $event_id;
        $guest_phone = is_scalar($guest_phone) ? (string) $guest_phone : '';
        if ($event_id <= 0 || $guest_phone === '') {
            return [];
        }

        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE event_id = %d AND guest_phone = %s ORDER BY id ASC",
                $event_id,
                $guest_phone
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }
}
