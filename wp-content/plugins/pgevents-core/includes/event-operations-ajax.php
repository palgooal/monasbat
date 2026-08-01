<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Event Operations — Entry Check-in Supervisors، Phase 10 ("Event Operations")
 * ============================================================================
 * "Event Operations" RFC — "Pure orchestration layer for the live event...
 * aggregating existing capabilities. No new business logic. No duplicate
 * business logic. Reuse every approved service."
 *
 * هذا الملف رقيق بالكامل: لا SQL هنا، لا حساب إحصاء جديد، لا منطق تفويض
 * جديد — فقط تجميع (Orchestration) لنتائج خدمات مُعتمَدة موجودة فعلاً:
 *
 *   - PGE_Attendance_Dashboard_Provider::get_dashboard() (Phase 5/6، غير
 *     مُعدَّلة في حسابها — إضافة Phase 10 الوحيدة هناك هي معامل $recent_
 *     checkins_limit الاختياري، توافقي خلفياً بالكامل، راجع توثيق تلك
 *     الدالة). يبقى مصدر Live Statistics/Supervisor Summary/Recent
 *     Check-ins/Event Summary الوحيد.
 *   - PGE_Invitation_Service::list_invitations() (Phase 9، غير مُعدَّلة) —
 *     مصدران مستقلان هنا: (أ) بحث الدعوات ضمن "البحث السريع الموحَّد"،
 *     (ب) عدّاد "الدعوات المُلغاة" (غير موجود أصلاً في attendance_summary
 *     لأن حالة الإلغاء تعيش في Invitation Repository، لا جدول RSVP — راجع
 *     docs/EVENT-OPERATIONS.md §"قرارات النطاق").
 *   - PGE_Supervisor_Assignment_Service::list_assignments_for_event_page()
 *     (Phase 8، غير مُعدَّلة) — مصدران مستقلان أيضاً: (أ) بحث المشرفين ضمن
 *     نفس البحث السريع الموحَّد، (ب) "آخر نشاط" (updated_at) لكل مشرف — دمج
 *     عرض بحت مع supervisor_summary العائد من Dashboard Provider، بلا أي
 *     استعلام SQL هنا.
 *
 * التفويض: pge_event_guests_user_can_manage($event_id) — نفس دالة كل صفحات
 * إدارة المضيف الأخرى (Supervisors/Invitations) حرفياً؛ Dashboard Provider
 * يُعيد أيضاً تنفيذ تفويضه الداخلي الخاص (pge_is_host_or_admin) عند
 * get_dashboard() — تكرار دفاعي مقصود، نمط مُعتمَد فعلياً في هذا المشروع
 * (راجع نفس النمط في templates/supervisor-dashboard.php).
 *
 * قراءة الصفحة نفسها لا تُسجِّل أي حدث تدقيق (Requirement: "Viewing the
 * dashboard must NOT create audit entries") — لا استدعاء لأي Audit::record()
 * في هذا الملف إطلاقاً؛ فقط الإجراءات القديمة المُعاد استخدامها من الواجهة
 * (تجديد QR/تصدير) تستمر بتسجيل تدقيقها الخاص كما كانت (بلا تغيير هناك).
 */

require_once __DIR__ . '/class-pge-attendance-dashboard-provider.php';
require_once __DIR__ . '/class-pge-invitation-service.php';
require_once __DIR__ . '/class-pge-supervisor-assignment-service.php';

if (!defined('PGE_EVENT_OPERATIONS_ENABLED')) {
    define('PGE_EVENT_OPERATIONS_ENABLED', true);
}

/**
 * تفويض/تحقق موحَّد لكل نقاط AJAX هذه — نسخة طبق الأصل من
 * pge_invitation_mgmt_validate_request() (invitation-management-ajax.php)
 * وpge_supervisor_mgmt_validate_request() (supervisor-management-ajax.php):
 * نفس nonce ('pge_event_manage_nonce')، نفس تسلسل الفحص (nonce → login →
 * event_id صالح → صلاحية الإدارة) — لا اختراع تحقق مختلف لصفحة جديدة.
 */
if (!function_exists('pge_event_ops_validate_request')) {
    /**
     * RC1 Fix Pack 2 (A9 — Duplicate validate_request()): غلاف رقيق يستدعي
     * المدقِّق المشترك pge_mgmt_validate_request() (helpers.php، يُحمَّل قبل
     * هذا الملف) — بلا أي تغيير سلوكي (نفس رسائل الخطأ/reason/nonce/تفويض
     * حرفياً). الاسم أُبقي كما هو عمداً لتفادي تعديل أي نقطة استدعاء داخل
     * هذا الملف.
     *
     * @return int event_id عند النجاح (ينهي الطلب عبر wp_send_json_error عند الفشل).
     */
    function pge_event_ops_validate_request()
    {
        return pge_mgmt_validate_request();
    }
}

/**
 * عدّاد "الدعوات المُلغاة" — قراءة واحدة إضافية عبر PGE_Invitation_Service::
 * list_invitations() المُعتمَدة أصلاً (Phase 9)، بفلتر invitation_status=
 * cancelled وper_page=1 (لا حاجة لأي صف فعلي، فقط 'total' — أقل حمولة ممكنة،
 * لا N+1: استعلام واحد إضافي فقط لكل تحديث لوحة، بلا علاقة بعدد الدعوات).
 * هذا **ليس** حساباً جديداً داخل محرك الإحصاء (PGE_Attendance_Statistics_
 * Service يبقى بلا لمس) — مجرَّد عدّاد مُشتَق من خدمة أخرى مُعتمَدة أصلاً
 * ومُدمَج هنا للعرض فقط، تماماً كما "attendance_rate_percent" في Phase 6
 * كانت "تنسيق عرض بحت" لرقم عائد أصلاً من الخدمة.
 */
if (!function_exists('pge_event_ops_cancelled_count')) {
    function pge_event_ops_cancelled_count(int $event_id): int
    {
        if (!class_exists('PGE_Invitation_Service')) {
            return 0;
        }
        $page = PGE_Invitation_Service::list_invitations($event_id, [
            'invitation_status' => 'cancelled',
            'page' => 1,
            'per_page' => 1,
        ]);
        return (int) ($page['total'] ?? 0);
    }
}

/**
 * دمج "آخر نشاط" (updated_at) لكل مشرف مع supervisor_summary العائد من
 * Dashboard Provider — قراءة إضافية واحدة عبر PGE_Supervisor_Assignment_
 * Service::list_assignments_for_event_page() المُعتمَدة أصلاً (Phase 8)، ثم
 * دمج بمفتاح assignment_id/id بالذاكرة فقط (Lookup، لا استعلام إضافي لكل
 * مشرف — O(1) استعلام واحد لكل تحديث لوحة بصرف النظر عن عدد المشرفين، ضمن
 * حد list_assignments_for_event_page الحالي (100 كحد أقصى فعلي — نفس القيد
 * المُجمَّد في تلك الدالة، غير مُعدَّل هنا؛ مشرف يتجاوز هذا الترتيب نادراً ما
 * يظهر بلا "آخر نشاط" فقط، بلا أي خطأ). "آخر نشاط" هنا هو نفسه المصدر
 * المُعتمَد أصلاً في pge_supervisor_mgmt_reshape_row() (updated_at الحالي —
 * لا تتبُّع جديد، لا عمود جديد، لا معنى "متصل الآن" مُخترَع).
 *
 * @param array<int,array> $supervisor_summary من Dashboard Provider
 * @return array<int,array> نفس الصفوف + مفتاح last_activity إضافي
 */
if (!function_exists('pge_event_ops_merge_supervisor_last_activity')) {
    function pge_event_ops_merge_supervisor_last_activity(int $event_id, array $supervisor_summary): array
    {
        if (!class_exists('PGE_Supervisor_Assignment_Service')) {
            foreach ($supervisor_summary as &$row) {
                $row['last_activity'] = '';
            }
            unset($row);
            return $supervisor_summary;
        }

        $assignments_page = PGE_Supervisor_Assignment_Service::list_assignments_for_event_page($event_id, '', 1, 100);
        $last_activity_by_id = [];
        foreach (($assignments_page['items'] ?? []) as $assignment_row) {
            $assignment_id = (int) ($assignment_row['id'] ?? 0);
            if ($assignment_id > 0) {
                $last_activity_by_id[$assignment_id] = (string) ($assignment_row['updated_at'] ?? '');
            }
        }

        foreach ($supervisor_summary as &$row) {
            $assignment_id = (int) ($row['assignment_id'] ?? 0);
            $row['last_activity'] = $last_activity_by_id[$assignment_id] ?? '';
        }
        unset($row);

        return $supervisor_summary;
    }
}

/**
 * ── لوحة عمليات المناسبة (اللقطة الكاملة) ───────────────────────────────────
 * Requirement: "Live Refresh — lightweight polling every 15 seconds. Only
 * Statistics/Recent activity/Supervisor availability refreshed." هذه النقطة
 * تُستدعى من (1) التحميل الأول (SSR في event-operations.php نفسها، استدعاء
 * مباشر بلا AJAX)، و(2) كل دورة استطلاع لاحقة (Fetch من نفس القالب) — نفس
 * الحزمة بالضبط في الحالتين، بلا أي فرق في المصدر أو الحساب.
 */
function pge_event_ops_dashboard_handler()
{
    $event_id = pge_event_ops_validate_request();

    $recent_limit = isset($_POST['recent_limit']) ? (int) $_POST['recent_limit'] : 20;
    if ($recent_limit <= 0 || $recent_limit > 100) {
        $recent_limit = 20;
    }

    $dashboard = PGE_Attendance_Dashboard_Provider::get_dashboard($event_id, $recent_limit);

    if (($dashboard['result'] ?? '') !== 'authorized') {
        // دفاعي فقط — pge_event_ops_validate_request() أعلاه رفض بالفعل أي
        // مستخدم غير مخوَّل؛ لا يُفترَض الوصول لهذا الفرع لمضيف حقيقي.
        wp_send_json_error([
            'message' => 'تعذّر تحميل لوحة العمليات',
            'reason' => (string) ($dashboard['reason'] ?? 'unauthorized'),
        ]);
    }

    $data = $dashboard['data'];

    $data['attendance_summary']['cancelled_invitations'] = pge_event_ops_cancelled_count($event_id);
    $data['supervisor_summary'] = pge_event_ops_merge_supervisor_last_activity($event_id, $data['supervisor_summary']);

    wp_send_json_success($data);
}
if (PGE_EVENT_OPERATIONS_ENABLED) {
    add_action('wp_ajax_pge_event_ops_dashboard', 'pge_event_ops_dashboard_handler');
}

/**
 * ── البحث السريع الموحَّد ────────────────────────────────────────────────────
 * Requirement: "One unified search box... Guest name/Phone/Invitation
 * code/Supervisor... Do NOT build a new search engine." استدعاءان مستقلان
 * لمحرِّكَي بحث مُعتمَدين أصلاً فقط، بلا أي منطق مطابقة جديد هنا: نتائج
 * الدعوات من PGE_Invitation_Service::list_invitations() (نفس محرك بحث Phase
 * 9 حرفياً)، ونتائج المشرفين من PGE_Supervisor_Assignment_Service::list_
 * assignments_for_event_page() (نفس محرك بحث Phase 8 حرفياً) — النتيجتان
 * تُوسَمان بنوعها فقط (type: invitation|supervisor) وتُعادان معاً، بلا أي
 * دمج/ترتيب/تصنيف موحَّد مصطنع بينهما.
 */
function pge_event_ops_search_handler()
{
    $event_id = pge_event_ops_validate_request();
    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';

    $invitation_items = [];
    if (function_exists('pge_invitation_mgmt_reshape_row')) {
        $inv_page = PGE_Invitation_Service::list_invitations($event_id, [
            'search' => $q,
            'page' => 1,
            'per_page' => 10,
        ]);
        $invitation_items = array_map('pge_invitation_mgmt_reshape_row', $inv_page['items'] ?? []);
    }

    $supervisor_items = [];
    if (class_exists('PGE_Supervisor_Assignment_Service') && function_exists('pge_supervisor_mgmt_reshape_row')) {
        $sup_page = PGE_Supervisor_Assignment_Service::list_assignments_for_event_page($event_id, $q, 1, 10);
        $supervisor_items = array_map('pge_supervisor_mgmt_reshape_row', $sup_page['items'] ?? []);
    }

    wp_send_json_success([
        'invitations' => $invitation_items,
        'supervisors' => $supervisor_items,
    ]);
}
if (PGE_EVENT_OPERATIONS_ENABLED) {
    add_action('wp_ajax_pge_event_ops_search', 'pge_event_ops_search_handler');
}
