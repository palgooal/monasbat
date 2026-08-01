<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Service — Entry Check-in Supervisors، Phase 9
 * ============================================================================
 * "Host Invitation Management" RFC — "Business rules belong only in
 * services. Templates are rendering only." الوسيط الوحيد بين طبقة الـAJAX
 * (invitation-management-ajax.php) وPGE_Invitation_Repository — كل عملية
 * كتابة ناجحة هنا تُسجِّل تدقيقاً واحداً عبر PGE_Invitation_Management_Audit
 * (باستثناء القراءة/القائمة/التصدير — لا أثر جانبي لها).
 *
 * لا SQL هنا، لا قراءة/كتابة مباشرة على post meta أو wp_pge_event_rsvps —
 * كل ذلك حصراً داخل PGE_Invitation_Repository.
 */

require_once __DIR__ . '/class-pge-invitation-repository.php';
require_once __DIR__ . '/class-pge-invitation-management-audit.php';

class PGE_Invitation_Service
{
    public static function list_invitations($event_id, array $args = []): array
    {
        return PGE_Invitation_Repository::list_invitations($event_id, $args);
    }

    /**
     * صفوف التصدير — نفس الفلترة/البحث المُطبَّقة في list_invitations()، لكن
     * بلا ترقيم (Requirement: "Export... Current filtered result only" —
     * أي كامل النتيجة المُرشَّحة، لا صفحة واحدة منها فقط).
     *
     * Phase 9C ("Invitation Export") — إصلاح: list_invitations() تفرض حداً
     * أقصى فعلياً على per_page لا يتجاوز 200 — أي قيمة أكبر (كانت 100000 هنا
     * سابقاً) **لا تُقصّ إلى 200**، بل تُرفَض بالكامل وتعود للافتراضي 20 (راجع
     * `$per_page = ($per_page > 0 && $per_page <= 200) ? $per_page : 20;` في
     * class-pge-invitation-repository.php — منطق ترقيم مُجمَّد، لم يُلمَس هنا
     * إطلاقاً). كانت النتيجة العملية أن أي تصدير لمناسبة بأكثر من 20 دعوة
     * يُقطَع صامتاً عند 20 صفاً فقط — اكتُشف هذا فعلياً أثناء اختبار "تصدير
     * كبير" التنفيذي (250 دعوة → 20 سطراً فقط في المخرَجات)، لا افتراضاً
     * نظرياً. الإصلاح: استهلاك آلية الترقيم **الحالية بلا أي تعديل عليها**
     * (Invitation Pagination مُجمَّدة معمارياً هذه المرحلة) عبر تكرار الاستدعاء
     * صفحة صفحة بحد per_page=200 (الحد الأقصى المسموح به فعلياً) وتجميع كل
     * الصفحات بالترتيب — نفس خوارزمية الفرز/الفلترة الوحيدة، بلا أي نسخة ثانية
     * منها، ونفس عدد استعلامات post meta الإجمالي تقريباً (لا علاقة بـN+1
     * قاعدة بيانات — post meta تُحمَّل عبر get_post_meta() لكل استدعاء أساساً،
     * غير مكلف لحجم قوائم الدعوات الواقعي لهذا المشروع).
     */
    public static function get_export_rows($event_id, array $args = []): array
    {
        $per_page = 200; // الحد الأقصى الفعلي المسموح به في list_invitations() — لا تعديل هناك.
        $page = 1;
        $rows = [];

        do {
            $page_args = $args;
            $page_args['page'] = $page;
            $page_args['per_page'] = $per_page;

            $result = PGE_Invitation_Repository::list_invitations($event_id, $page_args);
            $items = $result['items'] ?? [];
            if (empty($items)) {
                break;
            }

            $rows = array_merge($rows, $items);
            $page++;
        } while ($page <= (int) ($result['total_pages'] ?? 0));

        return $rows;
    }

    public static function create($event_id, $phone, $name, $note, $actor_user_id)
    {
        $result = PGE_Invitation_Repository::create($event_id, $phone, $name, $note);

        if (($result['result'] ?? '') === 'created') {
            PGE_Invitation_Management_Audit::record($event_id, $result['phone'], $actor_user_id, 'created', '');
        }

        return $result;
    }

    public static function edit($event_id, $old_phone, $new_phone, $name, $note, $actor_user_id)
    {
        $result = PGE_Invitation_Repository::edit($event_id, $old_phone, $new_phone, $name, $note);

        if (($result['result'] ?? '') === 'updated') {
            PGE_Invitation_Management_Audit::record($event_id, $result['phone'], $actor_user_id, 'edited', '');
        }

        return $result;
    }

    public static function cancel($event_id, $phone, $reason, $actor_user_id)
    {
        $result = PGE_Invitation_Repository::cancel($event_id, $phone, $reason);

        if (($result['result'] ?? '') === 'cancelled') {
            $normalized_phone = function_exists('pge_event_guests_norm_phone') ? pge_event_guests_norm_phone($phone) : $phone;
            PGE_Invitation_Management_Audit::record($event_id, $normalized_phone, $actor_user_id, 'cancelled', $reason);
        }

        return $result;
    }

    /**
     * ============================================================================
     * RC1 Fix Pack 3B — "Legacy Guest Panel Retirement (Hard Delete Migration)"
     * ============================================================================
     * "Hard Delete must preserve current business semantics. Do NOT silently
     * convert it into Cancel. Cancel and Delete remain different operations."
     * — هذه الدالة **لا تستدعي cancel() ولا تُغيِّر `_pge_invitation_status`
     * إلى ملغاة**؛ تحذف صف الضيف نهائياً من `_pge_invited_guests` عبر
     * PGE_Invitation_Repository::delete() (راجع توثيقها الكامل هناك).
     *
     * تدقيق: حدث 'deleted' واحد فقط عند النجاح — صف واحد لكل دعوة محذوفة
     * (لا حدث دفعي على مستوى المناسبة هنا؛ "One row per deleted invitation.
     * No event-level batch audit required." — الحذف الجماعي في الـAJAX
     * Controller يستدعي هذه الدالة نفسها مرة لكل هاتف، فيُنتج تلقائياً صفاً
     * واحداً لكل دعوة بلا أي كود إضافي).
     */
    public static function delete($event_id, $phone, $actor_user_id)
    {
        $result = PGE_Invitation_Repository::delete($event_id, $phone);

        if (($result['result'] ?? '') === 'deleted') {
            $normalized_phone = function_exists('pge_event_guests_norm_phone') ? pge_event_guests_norm_phone($phone) : $phone;
            PGE_Invitation_Management_Audit::record($event_id, $normalized_phone, $actor_user_id, 'deleted', '');
        }

        return $result;
    }

    /**
     * Phase 9B (مُعتمَدة) — معالج AJAX مُسجَّل فعلياً في الإنتاج (راجع
     * PGE_INVITATION_MGMT_RESEND_QR_ENABLED في invitation-management-ajax.php).
     * لا تُنشئ دعوة/ضيف/RSVP/إسناد جديداً، لا تستهلك حصة، لا تُصفِّر تاريخ
     * الحضور/التدقيق — فقط تُحدِّث updated_at (عبر Repository).
     *
     * Phase 9B Final Fix ("Resend semantics"): لا قناة تسليم فعلية مربوطة
     * بهذه العملية إطلاقاً (لا بريد/SMS/واتساب/مُرسِل إشعارات) — لذلك تُسجَّل
     * حدث تدقيق 'delivery_requested' (لا 'resent') عند النجاح: طلب تسليم فقط،
     * لا تسليم فعلي. الخدمة/الـRepository/الاستدعاء هنا لم يتغيَّروا إطلاقاً —
     * فقط اسم حدث التدقيق المكتوب (راجع class-pge-invitation-management-
     * audit.php للتوثيق الكامل وخطة التوافق المستقبلي).
     */
    public static function resend($event_id, $phone, $actor_user_id)
    {
        $result = PGE_Invitation_Repository::resend($event_id, $phone);

        if (($result['result'] ?? '') === 'resent') {
            $normalized_phone = function_exists('pge_event_guests_norm_phone') ? pge_event_guests_norm_phone($phone) : $phone;
            PGE_Invitation_Management_Audit::record($event_id, $normalized_phone, $actor_user_id, 'delivery_requested', '');
        }

        return $result;
    }

    /**
     * Phase 9B (مُعتمَدة) — معالج AJAX مُسجَّل فعلياً في الإنتاج (نفس الثابت
     * أعلى resend()).
     *
     * Phase 9B QR Architecture Final Fix: **لا تُولِّد ولا تستبدل invite_code
     * إطلاقاً بعد الآن** — التصحيح السابق ("تُولِّد رمزاً جديداً بالكامل...
     * يُبطِل الرمز القديم بالاستبدال") كان يُساوي خطأً بين Invitation Identity/
     * invite_code وبين Scanner QR Credential؛ هذا التمييز اعتُبر معمارياً
     * خاطئاً وأُلغي صراحةً. الدالة الآن تُفوِّض حصراً لـPGE_Invitation_
     * Repository::regenerate_qr() التي **تُدوِّر qr_version فقط** (بدائيّ
     * تدوير مُخزَّن لكل دعوة ضمن _pge_invitation_status[phone]) — القرار
     * الإداري "من يزور المدعو الآن" (invite_code، الاسم، الملاحظة) يبقى بلا
     * أي تغيير. لا تمسّ RSVP/الحضور/الإحصائيات إطلاقاً، وتُسجِّل حدث تدقيق
     * واحد 'qr_regenerated' عند النجاح — راجع docs/INVITATION-QR-ARCHITECTURE.md
     * للعقد الكامل (الحمولة الكنسية، سياسة التوافق مع الدعوات القديمة، أخطاء
     * العمل المستقرة).
     */
    public static function regenerate_qr($event_id, $phone, $actor_user_id)
    {
        $result = PGE_Invitation_Repository::regenerate_qr($event_id, $phone);

        if (($result['result'] ?? '') === 'regenerated') {
            $normalized_phone = function_exists('pge_event_guests_norm_phone') ? pge_event_guests_norm_phone($phone) : $phone;
            PGE_Invitation_Management_Audit::record($event_id, $normalized_phone, $actor_user_id, 'qr_regenerated', '');
        }

        return $result;
    }
}
