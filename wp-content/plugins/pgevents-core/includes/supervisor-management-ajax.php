<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Host Supervisor Management AJAX — Entry Check-in Supervisors، Phase 8
 * ============================================================================
 * "Host Supervisor Management" RFC — "Architecture: Host UI → Authorized Host
 * Controller/AJAX → Supervisor Assignment Service → Database. No template
 * writes. No business rules in templates. No attendance logic."
 *
 * التدفّق الإلزامي (بلا أي انحراف): templates/event-supervisors.php (عرض فقط)
 * → هذا الملف (تفويض + تنسيق) → PGE_Supervisor_Assignment_Service (منطق دورة
 * حياة الإسناد، Phase 2/8) → قاعدة البيانات. لا SQL هنا، لا منطق حصة مكرَّر
 * (الحصة تُفرَض داخلياً بالكامل عبر create_supervisor_assignment() → Resolver
 * — Requirement: "never duplicate quota logic").
 *
 * التفويض: pge_event_guests_user_can_manage($event_id) — نفس الدالة
 * المُستخدَمة فعلاً لإدارة المدعوين في event-guests.php حرفياً (لا اختراع فحص
 * تفويض مختلف؛ "المضيف = مالك المناسبة أو Administrator"، بالضبط تعريف الـRFC
 * لـ"Host Authorization"). Nonce نفسه المُستخدَم أصلاً لكل عمليات إدارة
 * المناسبة: 'pge_event_manage_nonce' (نفس نمط event-guests.php).
 *
 * عزل المناسبات (Requirement "Never trust client-side ownership" / اختبار
 * "different event isolation"): كل عملية تستهدف assignment_id تُحمَّل أولاً
 * عبر pge_supervisor_mgmt_load_owned_assignment() التي تتحقّق أن الإسناد
 * ينتمي فعلياً لنفس event_id المُخوَّل — لا وثوق بمجرد امتلاك assignment_id
 * صالح شكلياً.
 *
 * ما لا يفعله هذا الملف عمداً (Scope Guard): لا تعديل على Authentication/
 * Supervisor Session/Recorder/Attendance/Dashboard/Statistics/QR/Invitation
 * Resolution/Check-in UI. لا إرسال واتساب/SMS/بريد فعلي (خارج النطاق، مرحلة
 * لاحقة) — توكن إعادة الإرسال يُحسَب داخلياً فقط، لا يُعاد للواجهة ولا يُرسَل
 * عبر أي قناة تسليم بعد.
 */

if (!function_exists('pge_supervisor_mgmt_validate_request')) {
    /**
     * تحقّق موحَّد (nonce + تسجيل دخول + مناسبة صالحة + تفويض مضيف) — نفس بنية
     * pge_event_guests_validate_request() في event-guests.php حرفياً، مُعاد
     * كتابتها هنا محلياً (لا اعتماد برمجي جديد بين الملفين، بنفس فلسفة DRY
     * المنطقي المُتَّبعة في بقية المشروع) لأن الرسائل هنا JSON منظَّم
     * (message/reason) بدل رسالة نصية واحدة.
     *
     * @return int event_id عند النجاح (ينهي الطلب عبر wp_send_json_error عند الفشل).
     */
    /**
     * RC1 Fix Pack 2 (A9 — Duplicate validate_request()): غلاف رقيق يستدعي
     * المدقِّق المشترك pge_mgmt_validate_request() (helpers.php، يُحمَّل قبل
     * هذا الملف) — بلا أي تغيير سلوكي (نفس رسائل الخطأ/reason/nonce/تفويض
     * حرفياً). الاسم أُبقي كما هو عمداً لتفادي تعديل أي نقطة استدعاء داخل
     * هذا الملف.
     *
     * @return int event_id عند النجاح (ينهي الطلب عبر wp_send_json_error عند الفشل).
     */
    function pge_supervisor_mgmt_validate_request()
    {
        return pge_mgmt_validate_request();
    }
}

if (!function_exists('pge_supervisor_mgmt_load_owned_assignment')) {
    /**
     * تحميل إسناد مع تحقّق ذري أنه ينتمي فعلياً لنفس المناسبة المُخوَّلة —
     * حارس عزل المناسبات (Different Event Isolation). يُعيد null عند أي
     * تعارض، بلا تمييز في السبب بين "غير موجود" و"يخصّ مناسبة أخرى" (لا داعي
     * لتسريب أي معلومة إضافية عبر رسالة الخطأ).
     *
     * @return array|null
     */
    function pge_supervisor_mgmt_load_owned_assignment($event_id, $assignment_id)
    {
        if (!class_exists('PGE_Supervisor_Assignment_Service')) {
            return null;
        }

        $assignment = PGE_Supervisor_Assignment_Service::get_assignment_state($assignment_id);
        if ($assignment === null) {
            return null;
        }

        if ((int) ($assignment['event_id'] ?? 0) !== (int) $event_id) {
            return null;
        }

        return $assignment;
    }
}

if (!function_exists('pge_supervisor_mgmt_reshape_row')) {
    /**
     * إعادة تشكيل صف إسناد خام لعرض آمن ومفهوم في الواجهة — تسمية عربية
     * لحالة معروفة (Presentation Formatting بحت، لا منطق أعمال جديد؛ نفس مبدأ
     * تنسيق العرض المُتَّبع في supervisor-dashboard.php). "آخر نشاط" = عمود
     * updated_at الموجود أصلاً (يتحدَّث فعلياً عند كل انتقال حالة: إنشاء/قبول/
     * إلغاء/تعديل/إعادة إرسال) — لا تتبُّع جديد مُخترَع لأي طابع زمني إضافي.
     */
    function pge_supervisor_mgmt_reshape_row(array $row): array
    {
        $status = (string) ($row['status'] ?? '');
        $status_labels = [
            'invited' => 'بانتظار القبول',
            'pending' => 'بانتظار القبول',
            'active'  => 'نشط',
            'revoked' => 'ملغى',
        ];

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'name'          => (string) ($row['supervisor_name'] ?? ''),
            'phone'         => (string) ($row['supervisor_phone'] ?? ''),
            'status'        => $status,
            'status_label'  => $status_labels[$status] ?? $status,
            'invited_at'    => (string) ($row['invited_at'] ?? ''),
            'accepted_at'   => $row['accepted_at'] ?? null,
            'revoked_at'    => $row['revoked_at'] ?? null,
            'last_activity' => (string) ($row['updated_at'] ?? ($row['invited_at'] ?? '')),
        ];
    }
}

// ── قائمة المشرفين (بحث + ترقيم) — Requirement "Search/Pagination" ──────────
// دوال مُسمَّاة (لا closures) — نفس اصطلاح checkin-ajax.php (Phase 4/7) المُتَّبع
// عمداً لأنه يجعل كل معالج قابلاً للاستدعاء المباشر بالاسم من الاختبارات
// التنفيذية (Testability)، بلا أي مرآة منطقية مصطنعة.
function pge_supervisor_mgmt_list_handler()
{
    $event_id = pge_supervisor_mgmt_validate_request();

    if (!class_exists('PGE_Supervisor_Assignment_Service')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'service_unavailable']);
    }

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;

    $page_data = PGE_Supervisor_Assignment_Service::list_assignments_for_event_page($event_id, $search, $page, 20);
    $items = array_map('pge_supervisor_mgmt_reshape_row', $page_data['items']);

    $quota_payload = null;
    if (function_exists('pge_resolve_supervisor_quota_status')) {
        $quota = pge_resolve_supervisor_quota_status($event_id);
        if (is_array($quota)) {
            $quota_payload = [
                'mode'      => (string) ($quota['mode'] ?? ''),
                'allowed'   => (int) ($quota['allowed'] ?? 0),
                'used'      => (int) ($quota['used'] ?? 0),
                'remaining' => (int) ($quota['remaining'] ?? 0),
            ];
        }
    }

    wp_send_json_success([
        'items'       => $items,
        'total'       => (int) $page_data['total'],
        'page'        => (int) $page_data['page'],
        'per_page'    => (int) $page_data['per_page'],
        'total_pages' => (int) $page_data['total_pages'],
        'quota'       => $quota_payload,
    ]);
}
add_action('wp_ajax_pge_supervisor_mgmt_list', 'pge_supervisor_mgmt_list_handler');

// ── إنشاء مشرف جديد — Requirement "Create Supervisor" ───────────────────────
function pge_supervisor_mgmt_create_handler()
{
    $event_id = pge_supervisor_mgmt_validate_request();

    if (!class_exists('PGE_Supervisor_Assignment_Service')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'service_unavailable']);
    }

    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

    if (trim($phone) === '') {
        wp_send_json_error(['message' => 'أدخل رقم جوال المشرف', 'reason' => 'invalid_phone']);
    }

    $result = PGE_Supervisor_Assignment_Service::create_supervisor_assignment($event_id, get_current_user_id(), $phone, $name);
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'created') {
        if (class_exists('PGE_Supervisor_Management_Audit')) {
            PGE_Supervisor_Management_Audit::record($event_id, (int) $result['id'], get_current_user_id(), 'created', '');
        }

        wp_send_json_success([
            'message' => 'تمت إضافة المشرف',
            'id'      => (int) $result['id'],
        ]);
    }

    if ($outcome === 'duplicate_active') {
        wp_send_json_error(['message' => 'هذا الرقم مُسنَد بالفعل كمشرف نشط لهذه المناسبة', 'reason' => 'duplicate_active']);
    }

    if ($outcome === 'quota_exceeded') {
        wp_send_json_error([
            'message' => 'تم بلوغ الحد الأقصى لعدد مشرفي هذه المناسبة',
            'reason'  => 'quota_exceeded',
            'allowed' => (int) ($result['allowed'] ?? 0),
            'used'    => (int) ($result['used'] ?? 0),
        ]);
    }

    wp_send_json_error(['message' => 'تعذّر إضافة المشرف', 'reason' => (string) ($result['reason'] ?? 'unknown_error')]);
}
add_action('wp_ajax_pge_supervisor_mgmt_create', 'pge_supervisor_mgmt_create_handler');

// ── تعديل بيانات مشرف — Requirement "Edit Supervisor" ───────────────────────
function pge_supervisor_mgmt_edit_handler()
{
    $event_id = pge_supervisor_mgmt_validate_request();

    if (!class_exists('PGE_Supervisor_Assignment_Service')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'service_unavailable']);
    }

    $assignment_id = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;
    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

    $owned = pge_supervisor_mgmt_load_owned_assignment($event_id, $assignment_id);
    if ($owned === null) {
        wp_send_json_error(['message' => 'المشرف غير موجود ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    $result = PGE_Supervisor_Assignment_Service::edit_supervisor_details($assignment_id, $phone, $name);
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'updated') {
        if (class_exists('PGE_Supervisor_Management_Audit')) {
            PGE_Supervisor_Management_Audit::record($event_id, $assignment_id, get_current_user_id(), 'edited', '');
        }
        wp_send_json_success(['message' => 'تم تحديث بيانات المشرف']);
    }

    if ($outcome === 'duplicate_active') {
        wp_send_json_error(['message' => 'رقم الجوال مستخدَم بالفعل لمشرف آخر نشط', 'reason' => 'duplicate_active']);
    }

    wp_send_json_error(['message' => 'تعذّر تحديث بيانات المشرف', 'reason' => (string) ($result['reason'] ?? 'unknown_error')]);
}
add_action('wp_ajax_pge_supervisor_mgmt_edit', 'pge_supervisor_mgmt_edit_handler');

// ── إعادة إرسال الدعوة — Requirement "Resend Invitation" ────────────────────
function pge_supervisor_mgmt_resend_handler()
{
    $event_id = pge_supervisor_mgmt_validate_request();

    if (!class_exists('PGE_Supervisor_Assignment_Service')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'service_unavailable']);
    }

    $assignment_id = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;

    $owned = pge_supervisor_mgmt_load_owned_assignment($event_id, $assignment_id);
    if ($owned === null) {
        wp_send_json_error(['message' => 'المشرف غير موجود ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    $result = PGE_Supervisor_Assignment_Service::resend_invitation($assignment_id);
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'resent') {
        if (class_exists('PGE_Supervisor_Management_Audit')) {
            PGE_Supervisor_Management_Audit::record($event_id, $assignment_id, get_current_user_id(), 'invitation_resent', '');
        }

        // ملاحظة صريحة: $result['invitation_token'] (الخام) لا يُعاد للواجهة
        // عمداً — لا مسار تسليم فعلي (واتساب/SMS/بريد) بُني بعد لهذه المرحلة
        // (خارج النطاق صراحة). القيمة تبقى داخل استجابة الخدمة الخادمية فقط.
        wp_send_json_success(['message' => 'تمت إعادة إرسال الدعوة']);
    }

    if ($outcome === 'error' && ($result['reason'] ?? '') === 'not_resendable') {
        wp_send_json_error(['message' => 'لا يمكن إعادة إرسال الدعوة لهذه الحالة', 'reason' => 'not_resendable']);
    }

    wp_send_json_error(['message' => 'تعذّرت إعادة إرسال الدعوة', 'reason' => (string) ($result['reason'] ?? 'unknown_error')]);
}
add_action('wp_ajax_pge_supervisor_mgmt_resend', 'pge_supervisor_mgmt_resend_handler');

// ── إلغاء إسناد مشرف — Requirement "Revoke Supervisor" ──────────────────────
function pge_supervisor_mgmt_revoke_handler()
{
    $event_id = pge_supervisor_mgmt_validate_request();

    if (!class_exists('PGE_Supervisor_Assignment_Service')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'service_unavailable']);
    }

    $assignment_id = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;
    $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

    $owned = pge_supervisor_mgmt_load_owned_assignment($event_id, $assignment_id);
    if ($owned === null) {
        wp_send_json_error(['message' => 'المشرف غير موجود ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    $result = PGE_Supervisor_Assignment_Service::revoke_supervisor_assignment($assignment_id);
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'revoked') {
        if (class_exists('PGE_Supervisor_Management_Audit')) {
            PGE_Supervisor_Management_Audit::record($event_id, $assignment_id, get_current_user_id(), 'revoked', $reason);
        }
        wp_send_json_success(['message' => 'تم إلغاء إسناد المشرف']);
    }

    if ($outcome === 'error' && ($result['reason'] ?? '') === 'not_revocable') {
        wp_send_json_error(['message' => 'لا يمكن إلغاء هذا الإسناد (ملغى مسبقاً أو حالة غير صالحة)', 'reason' => 'not_revocable']);
    }

    wp_send_json_error(['message' => 'تعذّر إلغاء إسناد المشرف', 'reason' => (string) ($result['reason'] ?? 'unknown_error')]);
}
add_action('wp_ajax_pge_supervisor_mgmt_revoke', 'pge_supervisor_mgmt_revoke_handler');
