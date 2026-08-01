<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Portal Bootstrap — Entry Check-in Supervisors، Phase 3.5
 * ============================================================================
 * "Supervisor Portal Foundation" RFC، Requirement 3: "Portal Bootstrap —
 * Create the portal shell. Load only: Supervisor assignment, Event
 * information, Host information (if already available). No guests. No
 * invitations. No attendance."
 *
 * هذا الملف **قراءة فقط** بالكامل — لا $wpdb->insert()/update() هنا إطلاقاً.
 * يُستدعى حصراً بعد نجاح PGE_Supervisor_Portal_Middleware::authorize() (لا
 * يتحقق هو نفسه من أي تفويض — ليس مسؤوليته، Requirement يفصل "التفويض" عن
 * "تحميل البيانات" كخطوتين منفصلتين تماماً).
 *
 * القراءة الوحيدة من mon_event_supervisors هنا عبر PGE_Supervisor_Assignment_
 * Service::get_assignment_state($id) — الواجهة العامة الوحيدة المُرخَّصة
 * (راجع توثيقها في class-pge-supervisor-assignment-service.php)، لا SQL خاص
 * بهذا الملف على ذلك الجدول إطلاقاً. قراءة المناسبة عبر get_post()/
 * get_post_meta() القياسيتين لووردبريس (نفس ما يفعله access-gate.php للضيوف).
 *
 * ============================================================================
 * لا معلومات مدعوين، لا إحصاء حضور (Requirement 3/8)
 * ============================================================================
 * لا قراءة من wp_pge_event_rsvps، لا قراءة من _pge_invited_phones/_pge_
 * invited_guests/_pge_checkins — أي منها يُعتبَر "Guest Check-in"/"Attendance
 * Statistics"/"Guest List"، وكلها ممنوعة صراحةً في هذه المرحلة (خارج النطاق
 * بنص الرسالة الموجِّهة لهذه المرحلة بالكامل).
 *
 * ============================================================================
 * عدم كشف المعرِّفات الداخلية (Requirement 6)
 * ============================================================================
 * هذه الدالة **تُعيد** assignment_id/event_id في المصفوفة الناتجة (مطلوبان
 * داخلياً لبقية منطق الخادم — مثل رابط تسجيل الخروج الذي يحتاج معرفة الجلسة
 * ضمنياً عبر الكوكي لا عبر أي معرِّف مكشوف)، لكن **القالب الذي يستهلك هذه
 * البيانات (templates/supervisor-portal.php) ملتزم بعدم طباعة أي من
 * assignment_id/event_id/session_id/أي معرِّف قاعدة بيانات خام في HTML
 * الناتج إطلاقاً** — راجع القالب نفسه للتحقق المباشر من هذا الالتزام. هذا
 * الملف مسؤول عن "ماذا نجلب"، لا عن "ماذا نعرض" — الفصل بين الاثنين مقصود.
 */
class PGE_Supervisor_Portal_Bootstrap
{
    /**
     * تحميل بيانات العرض فقط لبورتال مشرف مُفوَّض بالفعل (Requirement 3).
     * لا تتحقق هذه الدالة من أي تفويض بنفسها — يُفترَض استدعاؤها فقط بعد
     * PGE_Supervisor_Portal_Middleware::authorize() الناجح.
     *
     * @param int $assignment_id مصدره حصراً نتيجة authorize() الموثوقة.
     * @param int $event_id      مصدره حصراً نتيجة authorize() الموثوقة.
     * @return array{
     *   ok: bool,
     *   reason?: string,
     *   supervisor_name?: string,
     *   supervisor_phone_masked?: string,
     *   event_name?: string,
     *   event_date_display?: string,
     *   host_name?: string,
     * }
     */
    public static function load(int $assignment_id, int $event_id): array
    {
        if ($assignment_id <= 0 || $event_id <= 0) {
            return ['ok' => false, 'reason' => 'invalid_arguments'];
        }

        if (!class_exists('PGE_Supervisor_Assignment_Service')) {
            return ['ok' => false, 'reason' => 'assignment_service_unavailable'];
        }

        // ── الإسناد (Supervisor assignment) — عبر الواجهة العامة حصراً ──────
        $assignment = PGE_Supervisor_Assignment_Service::get_assignment_state($assignment_id);
        if ($assignment === null) {
            return ['ok' => false, 'reason' => 'assignment_not_found'];
        }

        // تحقّق دفاعي إضافي: الإسناد المُحمَّل ينتمي فعلياً لنفس المناسبة
        // المطلوبة — لا يجب أن يختلفا أبداً بعد authorize() ناجح، لكن نتحقق
        // صراحة بدل الوثوق الأعمى (بنفس فلسفة validate_session() نفسها).
        if ((int) ($assignment['event_id'] ?? 0) !== $event_id) {
            return ['ok' => false, 'reason' => 'event_mismatch'];
        }

        $supervisor_name = trim((string) ($assignment['supervisor_name'] ?? ''));
        $supervisor_phone = (string) ($assignment['supervisor_phone'] ?? '');

        // ── المناسبة (Event information) — قراءة WordPress قياسية ──────────
        $post = get_post($event_id);
        if (!$post || $post->post_type !== 'pge_event') {
            return ['ok' => false, 'reason' => 'event_not_found'];
        }

        $event_name = get_the_title($post);

        $event_date_display = '';
        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        if ($event_date_raw !== '') {
            $ts = strtotime(str_replace('T', ' ', $event_date_raw));
            if ($ts) {
                $event_date_display = date_i18n('j F Y', $ts);
            }
        }

        // ── المضيف (Host information، إن توفَّر) — post_author فقط ─────────
        $host_name = '';
        $author_id = (int) $post->post_author;
        if ($author_id > 0) {
            $author = get_userdata($author_id);
            if ($author instanceof WP_User) {
                $host_name = (string) $author->display_name;
            }
        }

        return [
            'ok' => true,
            'assignment_id' => $assignment_id,
            'event_id' => $event_id,
            'supervisor_name' => $supervisor_name,
            'supervisor_phone_masked' => self::mask_phone($supervisor_phone),
            'event_name' => $event_name,
            'event_date_display' => $event_date_display,
            'host_name' => $host_name,
        ];
    }

    /**
     * إخفاء جزئي لرقم جوال المشرف عند العرض (Requirement 6: "Only expose
     * data required by the UI") — يُبقي آخر أربعة أرقام فقط ظاهرة، لا حاجة
     * فعلية لعرض الرقم كاملاً في شِلّة عرض بسيطة (اسم/حالة/زر خروج فقط).
     */
    private static function mask_phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $len = strlen($digits);
        if ($len <= 4) {
            return $digits === '' ? '' : str_repeat('•', $len);
        }

        return str_repeat('•', $len - 4) . substr($digits, -4);
    }
}
