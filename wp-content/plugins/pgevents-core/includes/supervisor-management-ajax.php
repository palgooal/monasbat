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

if (!function_exists('pge_supervisor_delivery_result_message')) {
    /**
     * ============================================================================
     * رسالة عرض صادقة لنتيجة PGE_Supervisor_Invitation_Delivery::deliver()
     * (Supervisor Invitation Delivery via Cartat — تنفيذ)
     * ============================================================================
     * تنسيق عرض بحت (نفس فلسفة pge_supervisor_mgmt_reshape_row() أعلاه) — لا
     * منطق أعمال هنا، فقط ترجمة فئة فشل مُطبَّعة إلى نص عربي مفهوم للمضيف. لا
     * تكشف أبداً: التوكن الخام، رابط القبول، جسم استجابة Cartat، أو أي معرّف
     * قاعدة بيانات داخلي.
     */
    function pge_supervisor_delivery_result_message(array $delivery): string
    {
        if (($delivery['result'] ?? '') === 'provider_accepted') {
            return 'تم قبول طلب التسليم عبر واتساب';
        }

        $reason = (string) ($delivery['reason'] ?? '');
        $status = (string) ($delivery['status'] ?? '');

        if ($reason === 'not_eligible' && $status === 'active') {
            return 'المشرف نشط بالفعل — لا حاجة لإعادة الإرسال';
        }
        if ($reason === 'not_eligible' && $status === 'revoked') {
            return 'تم إلغاء إسناد هذا المشرف — لا يمكن إرسال دعوة';
        }

        $labels = [
            'provider_not_active'    => 'مزوّد واتساب النشط ليس Cartat',
            'missing_settings'       => 'إعدادات Cartat غير مكتملة',
            'transport_error'        => 'تعذّر الاتصال بواتساب (خطأ شبكة مؤقت)',
            'provider_rejected'      => 'رفض واتساب إرسال الرسالة',
            'lock_busy'              => 'يوجد طلب إرسال قيد التنفيذ بالفعل لهذا المشرف، حاول لاحقاً',
            'token_commit_failed'    => 'تعذّر تثبيت رابط الدعوة (تغيّرت حالة الإسناد أثناء الإرسال)',
            'assignment_not_found'   => 'المشرف غير موجود',
            'not_eligible'           => 'حالة الإسناد الحالية لا تسمح بإرسال دعوة',
            'assignment_incomplete'  => 'بيانات الإسناد غير مكتملة',
            'service_unavailable'    => 'الخدمة غير متاحة حالياً',
            'transport_unavailable'  => 'خدمة الإرسال غير متاحة حالياً',
            'invalid_assignment_id'  => 'معرّف غير صالح',
        ];

        return $labels[$reason] ?? 'تعذّر إتمام عملية الإرسال';
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
        $new_assignment_id = (int) $result['id'];

        if (class_exists('PGE_Supervisor_Management_Audit')) {
            PGE_Supervisor_Management_Audit::record($event_id, $new_assignment_id, get_current_user_id(), 'created', '');
        }

        // Supervisor Invitation Delivery via Cartat — تنفيذ: محاولة تسليم
        // فعلي فوراً بعد الإنشاء (نفس مسار deliver() المستخدَم لإعادة
        // الإرسال أدناه — لا تكرار منطق). نجاح/فشل التسليم لا يُبطِل نجاح
        // إنشاء الإسناد نفسه (الإسناد أُنشئ فعلاً بصرف النظر عن نتيجة
        // الإرسال) — الرسالة المُعادة صادقة بكلا الجزأين.
        $delivery_message = 'تمت إضافة المشرف';
        if (class_exists('PGE_Supervisor_Invitation_Delivery')) {
            $delivery = PGE_Supervisor_Invitation_Delivery::deliver($new_assignment_id, get_current_user_id());
            $delivery_outcome = (string) ($delivery['result'] ?? '');

            $delivery_message = ($delivery_outcome === 'provider_accepted')
                ? 'تمت إضافة المشرف وإرسال الدعوة عبر واتساب'
                : 'تمت إضافة المشرف، لكن تعذّر إرسال الدعوة عبر واتساب: ' . pge_supervisor_delivery_result_message($delivery);
        }

        wp_send_json_success([
            'message' => $delivery_message,
            'id'      => $new_assignment_id,
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
// Supervisor Invitation Delivery via Cartat — تنفيذ: يستدعي الآن
// PGE_Supervisor_Invitation_Delivery::deliver() بدل PGE_Supervisor_
// Assignment_Service::resend_invitation() مباشرة — الأخيرة تبقى موجودة
// وموثَّقة (راجع توثيقها) لكنها لم تعد تُستدعى من مسار الإنتاج هذا: deliver()
// تُطبِّق ترتيب تدوير التوكن الآمن (لا إبطال قبل قبول Cartat فعلياً) وتُسجِّل
// دورة تدقيق صادقة (delivery_requested/attempted/provider_accepted|failed)
// بدل الحدث المضلِّل 'invitation_resent' السابق (كان يُسجَّل حتى عندما لم
// يكن هناك أي تسليم فعلي).
function pge_supervisor_mgmt_resend_handler()
{
    $event_id = pge_supervisor_mgmt_validate_request();

    if (!class_exists('PGE_Supervisor_Assignment_Service') || !class_exists('PGE_Supervisor_Invitation_Delivery')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'service_unavailable']);
    }

    $assignment_id = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;

    $owned = pge_supervisor_mgmt_load_owned_assignment($event_id, $assignment_id);
    if ($owned === null) {
        wp_send_json_error(['message' => 'المشرف غير موجود ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    $delivery = PGE_Supervisor_Invitation_Delivery::deliver($assignment_id, get_current_user_id());
    $outcome = (string) ($delivery['result'] ?? '');

    if ($outcome === 'provider_accepted') {
        // ملاحظة صريحة: لا التوكن الخام ولا رابط القبول يُعادان للواجهة
        // إطلاقاً — التسليم تم فعلياً عبر واتساب، لا حاجة لعرضهما في لوحة الإدارة.
        wp_send_json_success(['message' => pge_supervisor_delivery_result_message($delivery)]);
    }

    $reason = (string) ($delivery['reason'] ?? 'unknown_error');
    wp_send_json_error([
        'message' => 'تعذّرت إعادة إرسال الدعوة: ' . pge_supervisor_delivery_result_message($delivery),
        'reason'  => $reason,
    ]);
}
add_action('wp_ajax_pge_supervisor_mgmt_resend', 'pge_supervisor_mgmt_resend_handler');

// ── توليد رابط دعوة يدوي — Supervisor Manual Invitation Link: Secure One-Time
// Generation، تنفيذ (بديل طوارئ عند تعذّر واتساب؛ منفصل تماماً عن Cartat) ──
// نفس نمط pge_supervisor_mgmt_resend_handler() أعلاه حرفياً: validate_request()
// → load_owned_assignment() (عزل مناسبات + وجود، "Never trust assignment_id
// alone") → استدعاء الخدمة المخصَّصة → wp_send_json_success/error. التفويض
// (مضيف مخوَّل لهذه المناسبة تحديداً) يتم بالكامل هنا وفي load_owned_assignment()
// قبل حتى استدعاء PGE_Supervisor_Manual_Link_Service::generate() — تلك الدالة
// لا تُعيد أي فحص تفويض بنفسها (راجع توثيقها).
if (!function_exists('pge_supervisor_manual_link_result_message')) {
    /**
     * رسالة عرض صادقة لنتيجة PGE_Supervisor_Manual_Link_Service::generate() —
     * نفس فلسفة pge_supervisor_delivery_result_message() أعلاه تماماً (تنسيق
     * عرض بحت، لا منطق أعمال). لا تكشف أبداً: التوكن الخام، الرابط، الهاش، أو
     * أي معرّف قاعدة بيانات داخلي غير مقصود للعرض.
     */
    function pge_supervisor_manual_link_result_message(array $result): string
    {
        $reason = (string) ($result['reason'] ?? '');
        $status = (string) ($result['status'] ?? '');

        if ($reason === 'not_eligible' && $status === 'active') {
            return 'المشرف نشط بالفعل — لا حاجة لرابط دعوة جديد';
        }
        if ($reason === 'not_eligible' && $status === 'revoked') {
            return 'تم إلغاء إسناد هذا المشرف — لا يمكن توليد رابط دعوة';
        }

        $labels = [
            'lock_busy'             => 'يوجد طلب توليد رابط قيد التنفيذ بالفعل لهذا المشرف، حاول لاحقاً',
            'token_commit_failed'   => 'تعذّر توليد رابط الدعوة (تغيّرت حالة الإسناد أثناء التوليد)',
            'assignment_not_found'  => 'المشرف غير موجود',
            'not_eligible'          => 'حالة الإسناد الحالية لا تسمح بتوليد رابط دعوة',
            'assignment_incomplete' => 'بيانات الإسناد غير مكتملة',
            'service_unavailable'   => 'الخدمة غير متاحة حالياً',
            'invalid_assignment_id' => 'معرّف غير صالح',
        ];

        return $labels[$reason] ?? 'تعذّر توليد رابط الدعوة';
    }
}

function pge_supervisor_mgmt_manual_link_handler()
{
    $event_id = pge_supervisor_mgmt_validate_request();

    if (!class_exists('PGE_Supervisor_Manual_Link_Service')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'service_unavailable']);
    }

    $assignment_id = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;

    $owned = pge_supervisor_mgmt_load_owned_assignment($event_id, $assignment_id);
    if ($owned === null) {
        wp_send_json_error(['message' => 'المشرف غير موجود ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    $result = PGE_Supervisor_Manual_Link_Service::generate($assignment_id, get_current_user_id());
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'generated') {
        // العقد الوحيد المسموح به: الرابط فقط، بلا أي حقل حسّاس إضافي (لا
        // توكن خام منفصل، لا هاش، لا assignment_id غير ضروري، لا بيانات DB
        // داخلية، لا انتهاء صلاحية غير موجود فعلياً).
        wp_send_json_success(['invitation_url' => (string) $result['invitation_url']]);
    }

    $reason = (string) ($result['reason'] ?? 'unknown_error');
    wp_send_json_error([
        'message' => pge_supervisor_manual_link_result_message($result),
        'reason'  => $reason,
    ]);
}
add_action('wp_ajax_pge_supervisor_mgmt_manual_link', 'pge_supervisor_mgmt_manual_link_handler');

// ── رابط/إرسال الدخول — Supervisor Login Architecture (Post-Activation Login)
// RFC، تنفيذ. نفس نمط manual_link أعلاه حرفياً: validate_request() → load_
// owned_assignment() → استدعاء الخدمة المخصَّصة → wp_send_json_success/error.
// الفرق الجوهري عن manual_link: الأهلية هنا active فقط (عكس تماماً)، ولا
// علاقة إطلاقاً بـPGE_Supervisor_Manual_Link_Service ولا بتوكن الدعوة —
// PGE_Supervisor_Login_Service/PGE_Supervisor_Login_Delivery مستقلتان تماماً.
if (!function_exists('pge_supervisor_login_result_message')) {
    /**
     * رسالة عرض صادقة لنتيجة PGE_Supervisor_Login_Service::generate()/
     * PGE_Supervisor_Login_Delivery::deliver() — نفس فلسفة pge_supervisor_
     * manual_link_result_message()/pge_supervisor_delivery_result_message()
     * أعلاه (تنسيق عرض بحت). لا تكشف أبداً: التوكن الخام، الرابط، الهاش.
     */
    function pge_supervisor_login_result_message(array $result): string
    {
        $outcome = (string) ($result['result'] ?? '');

        if ($outcome === 'sent') {
            return 'تم إرسال رابط الدخول عبر واتساب';
        }
        if ($outcome === 'generated_delivery_failed') {
            return 'تم توليد رابط الدخول، لكن تعذّر إرساله عبر واتساب — استخدم "نسخ رابط الدخول" لإرساله يدوياً';
        }

        $reason = (string) ($result['reason'] ?? '');
        $status = (string) ($result['status'] ?? '');

        if ($reason === 'not_eligible' && $status !== '') {
            $status_labels = [
                'invited' => 'بانتظار قبول الدعوة أولاً',
                'pending' => 'بانتظار قبول الدعوة أولاً',
                'revoked' => 'تم إلغاء إسناده',
                'expired' => 'انتهى إسناده',
            ];
            $status_note = $status_labels[$status] ?? 'حالته الحالية لا تسمح بذلك';
            return 'لا يمكن توليد رابط دخول قبل أن يصبح المشرف نشطاً — ' . $status_note;
        }

        $labels = [
            'lock_busy'              => 'يوجد طلب توليد رابط دخول قيد التنفيذ بالفعل لهذا المشرف، حاول لاحقاً',
            'token_commit_failed'    => 'تعذّر توليد رابط الدخول (تغيّرت حالة الإسناد أثناء التوليد)',
            'assignment_not_found'   => 'المشرف غير موجود',
            'assignment_incomplete'  => 'بيانات الإسناد غير مكتملة',
            'service_unavailable'    => 'الخدمة غير متاحة حالياً',
            'transport_unavailable'  => 'خدمة الإرسال غير متاحة حالياً',
            'invalid_assignment_id'  => 'معرّف غير صالح',
            'provider_not_active'    => 'مزوّد واتساب النشط ليس Cartat',
            'missing_settings'       => 'إعدادات Cartat غير مكتملة',
            'transport_error'        => 'تعذّر الاتصال بواتساب (خطأ شبكة مؤقت)',
            'provider_rejected'      => 'رفض واتساب إرسال الرسالة',
        ];

        return $labels[$reason] ?? 'تعذّر إتمام العملية';
    }
}

function pge_supervisor_mgmt_login_link_handler()
{
    $event_id = pge_supervisor_mgmt_validate_request();

    if (!class_exists('PGE_Supervisor_Login_Service')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'service_unavailable']);
    }

    $assignment_id = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;

    $owned = pge_supervisor_mgmt_load_owned_assignment($event_id, $assignment_id);
    if ($owned === null) {
        wp_send_json_error(['message' => 'المشرف غير موجود ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    $result = PGE_Supervisor_Login_Service::generate($assignment_id, get_current_user_id());
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'generated') {
        // نفس عقد manual_link: حقل واحد فقط، لا شيء حسّاس إضافي.
        wp_send_json_success(['login_url' => (string) $result['login_url']]);
    }

    $reason = (string) ($result['reason'] ?? 'unknown_error');
    wp_send_json_error([
        'message' => pge_supervisor_login_result_message($result),
        'reason'  => $reason,
    ]);
}
add_action('wp_ajax_pge_supervisor_mgmt_login_link', 'pge_supervisor_mgmt_login_link_handler');

function pge_supervisor_mgmt_send_login_handler()
{
    $event_id = pge_supervisor_mgmt_validate_request();

    if (!class_exists('PGE_Supervisor_Login_Delivery')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'service_unavailable']);
    }

    $assignment_id = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;

    $owned = pge_supervisor_mgmt_load_owned_assignment($event_id, $assignment_id);
    if ($owned === null) {
        wp_send_json_error(['message' => 'المشرف غير موجود ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    $result = PGE_Supervisor_Login_Delivery::deliver($assignment_id, get_current_user_id());
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'sent') {
        // نفس ملاحظة pge_supervisor_mgmt_resend_handler(): لا رابط يُعاد
        // للواجهة إطلاقاً هنا — وصل مباشرة عبر واتساب.
        wp_send_json_success(['message' => pge_supervisor_login_result_message($result)]);
    }

    // 'generated_delivery_failed' و'error' كلاهما يُعاملان كفشل من منظور
    // هذا الزر تحديداً (لم يصل شيء عبر واتساب فعلياً) — الرسالة توجّه
    // المضيف صراحة لاستخدام "نسخ رابط الدخول" بدلاً من إعادة المحاولة، لأن
    // الرابط (إن كان قد تولَّد فعلاً في حالة generated_delivery_failed) لا
    // يُكشَف هنا عمداً (نفس نطاق تعرّض manual_link/login_link فقط، لا هذا
    // الزر).
    $reason = (string) ($result['reason'] ?? 'unknown_error');
    wp_send_json_error([
        'message' => pge_supervisor_login_result_message($result),
        'reason'  => $reason,
    ]);
}
add_action('wp_ajax_pge_supervisor_mgmt_send_login', 'pge_supervisor_mgmt_send_login_handler');

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
