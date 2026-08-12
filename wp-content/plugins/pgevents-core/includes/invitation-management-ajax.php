<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Host Invitation Management AJAX — Entry Check-in Supervisors، Phase 9
 * ============================================================================
 * "Host Invitation Management" RFC — "Host UI → Invitation Controller/AJAX →
 * Invitation Service → Invitation Repository → Database."
 *
 * التفويض: pge_event_guests_user_can_manage($event_id) — نفس الدالة
 * المُستخدَمة فعلاً لإدارة المدعوين ولإدارة المشرفين (Phase 8) حرفياً — لا
 * اختراع فحص تفويض مختلف. Nonce نفسه المُستخدَم أصلاً لكل عمليات إدارة
 * المناسبة: 'pge_event_manage_nonce'.
 *
 * "Never trust invitation_id alone. Always validate event_id + ownership":
 * مُعرِّف الدعوة هنا هو رقم الهاتف المُطبَّع ضمن مناسبة واحدة (راجع تعليق
 * class-pge-invitation-repository.php للتبرير الكامل) — بما أن تخزين الضيوف
 * مُقسَّم أصلاً بمفتاح post meta *لكل مناسبة على حدة*، فمحاولة تمرير هاتف
 * يخصّ مناسبة أخرى مع event_id مختلف تفشل بنيوياً في PGE_Invitation_
 * Repository (not_found) — بالإضافة لتحقّق ملكية المناسبة نفسها (event_id)
 * هنا أولاً، قبل أي وصول لبيانات الدعوة إطلاقاً.
 *
 * دوال مُسمَّاة (لا closures) — نفس اصطلاح checkin-ajax.php/supervisor-
 * management-ajax.php المُتَّبع عمداً للاختبار المباشر بالاسم.
 */

require_once __DIR__ . '/class-pge-invitation-service.php';
require_once __DIR__ . '/class-pge-invitation-export.php'; // Phase 9C — طبقة تصدير قراءة فقط
require_once __DIR__ . '/class-pge-xlsx-writer.php';        // Phase 9C — كاتب XLSX بلا اعتماديات
require_once __DIR__ . '/class-pge-invitation-bulk-add.php'; // RC1 Fix Pack 3A — Parser/Validator للإضافة الجماعية
require_once __DIR__ . '/class-pge-invitation-excel-import.php'; // Excel Import Phase 2/3 — قراءة/تحقق/تكرار فقط

/**
 * ============================================================================
 * بوابتا نطاق مركزيتان — Phase 9B (Resend/QR Regeneration) وPhase 9C (Export)
 * ============================================================================
 * خلفية: "Phase 9A Final Fix" أدخل ثابتاً واحداً يُغلِق أربعة معالجات معاً
 * (Resend/QR Regeneration/CSV Export/Excel Export) حين لم يكن أيٌّ منها
 * مُعتمَداً. Phase 9B اعتمدت Resend/QR Regeneration فقط (ثابت مستقل)، والآن
 * Phase 9C تعتمد Export أيضاً — كلا الثابتين true الآن:
 *
 *   - PGE_INVITATION_MGMT_RESEND_QR_ENABLED (true): Phase 9B معتمَدة.
 *   - PGE_INVITATION_MGMT_EXPORT_ENABLED (true، Phase 9C معتمَدة الآن):
 *     يُسجِّل wp_ajax_pge_invitation_mgmt_export_csv وwp_ajax_pge_invitation_
 *     mgmt_export_excel فعلياً. التصدير الآن حدث تدقيق فعلي أيضاً
 *     ('export_completed' — راجع class-pge-invitation-management-audit.php).
 */
if (!defined('PGE_INVITATION_MGMT_RESEND_QR_ENABLED')) {
    define('PGE_INVITATION_MGMT_RESEND_QR_ENABLED', true);
}
if (!defined('PGE_INVITATION_MGMT_EXPORT_ENABLED')) {
    define('PGE_INVITATION_MGMT_EXPORT_ENABLED', true);
}
/**
 * RC1 Fix Pack 3A ("Invitation Bulk Add Migration") — بوابة نطاق ثالثة، بنفس
 * نمط RESEND_QR_ENABLED/EXPORT_ENABLED أعلاه: true فور اعتماد هذه المرحلة.
 * تُسجِّل wp_ajax_pge_invitation_mgmt_bulk_preview وwp_ajax_pge_invitation_
 * mgmt_bulk_confirm فعلياً.
 */
if (!defined('PGE_INVITATION_MGMT_BULK_ADD_ENABLED')) {
    define('PGE_INVITATION_MGMT_BULK_ADD_ENABLED', true);
}
/**
 * RC1 Fix Pack 3B ("Legacy Guest Panel Retirement — Hard Delete Migration")
 * — بوابة نطاق رابعة، بنفس نمط الثوابت الثلاثة أعلاه: true فور اعتماد هذه
 * المرحلة. تُسجِّل wp_ajax_pge_invitation_mgmt_delete وwp_ajax_pge_
 * invitation_mgmt_bulk_delete فعلياً.
 */
if (!defined('PGE_INVITATION_MGMT_DELETE_ENABLED')) {
    define('PGE_INVITATION_MGMT_DELETE_ENABLED', true);
}
/**
 * Messaging Architecture Phase 3 ("Manual Reminder") — بوابة نطاق خامسة، بنفس
 * نمط الثوابت الأربعة أعلاه: true فور اعتماد هذه المرحلة. تُسجِّل wp_ajax_
 * pge_invitation_mgmt_reminder_preview وwp_ajax_pge_invitation_mgmt_save_
 * reminder_template وwp_ajax_pge_invitation_mgmt_send_reminder وwp_ajax_
 * pge_invitation_mgmt_reminder_status فعلياً. هذا مفتاح "مرحلة معتمدة أم لا"
 * بحت — لا علاقة له بأي Feature Entitlement/Tier Gate (PART 19 من تكليف
 * Phase 3: reminder_message ميزة Baseline لكل الباقات، لا بوابة ميزة هنا ولا
 * في أي مكان آخر من مسار الإرسال).
 */
if (!defined('PGE_INVITATION_MGMT_REMINDER_ENABLED')) {
    define('PGE_INVITATION_MGMT_REMINDER_ENABLED', true);
}
if (!defined('PGE_INVITATION_MGMT_THANK_YOU_ENABLED')) {
    define('PGE_INVITATION_MGMT_THANK_YOU_ENABLED', true);
}

if (!function_exists('pge_invitation_mgmt_validate_request')) {
    /**
     * RC1 Fix Pack 2 (A9 — Duplicate validate_request()): غلاف رقيق يستدعي
     * المدقِّق المشترك pge_mgmt_validate_request() (helpers.php، يُحمَّل
     * قبل هذا الملف) — بلا أي تغيير سلوكي (نفس رسائل الخطأ/reason/nonce/
     * تفويض حرفياً). الاسم أُبقي كما هو عمداً لتفادي تعديل أي نقطة استدعاء
     * داخل هذا الملف.
     *
     * @return int event_id عند النجاح (ينهي الطلب عبر wp_send_json_error عند الفشل).
     */
    function pge_invitation_mgmt_validate_request()
    {
        return pge_mgmt_validate_request();
    }
}

if (!function_exists('pge_invitation_mgmt_filters_from_request')) {
    function pge_invitation_mgmt_filters_from_request(): array
    {
        return [
            'search'            => isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
            'rsvp_status'       => isset($_POST['rsvp_status']) ? sanitize_text_field(wp_unslash($_POST['rsvp_status'])) : '',
            'invitation_status' => isset($_POST['invitation_status']) ? sanitize_text_field(wp_unslash($_POST['invitation_status'])) : '',
            'attendance_status' => isset($_POST['attendance_status']) ? sanitize_text_field(wp_unslash($_POST['attendance_status'])) : '',
            'sort_by'           => isset($_POST['sort_by']) ? sanitize_text_field(wp_unslash($_POST['sort_by'])) : 'name',
            'sort_dir'          => isset($_POST['sort_dir']) ? sanitize_text_field(wp_unslash($_POST['sort_dir'])) : 'asc',
            'page'              => isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1,
            'per_page'          => isset($_POST['per_page']) ? (int) $_POST['per_page'] : 20,
        ];
    }
}

if (!function_exists('pge_invitation_mgmt_reshape_row')) {
    /**
     * إعادة تشكيل صف للعرض — لا تسريب لأي حقل داخلي غير ضروري (لا يوجد أصلاً
     * أي معرِّف رقمي داخلي هنا، الهاتف نفسه هو المفتاح المعروض أصلاً في كل
     * لوحة إدارة مدعوين قائمة في المشروع).
     */
    function pge_invitation_mgmt_reshape_row(array $row): array
    {
        return [
            'phone'              => $row['phone'],
            'name'               => $row['name'],
            'note'               => $row['note'],
            'code'               => $row['code'],
            'rsvp_status'        => $row['rsvp_status'],
            'rsvp_status_label'  => $row['rsvp_status_label'],
            'attendance_status'  => $row['attendance_status'],
            'invitation_status'  => $row['invitation_status'],
            'qr_status'          => $row['qr_status'],
            'invited_at'         => $row['invited_at'],
            'updated_at'         => $row['updated_at'],
        ];
    }
}

// ── قائمة الدعوات (بحث + فلاتر + ترقيم + فرز) ───────────────────────────────
function pge_invitation_mgmt_list_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();
    $filters = pge_invitation_mgmt_filters_from_request();

    $page_data = PGE_Invitation_Service::list_invitations($event_id, $filters);
    $items = array_map('pge_invitation_mgmt_reshape_row', $page_data['items']);

    wp_send_json_success([
        'items'       => $items,
        'total'       => (int) $page_data['total'],
        'page'        => (int) $page_data['page'],
        'per_page'    => (int) $page_data['per_page'],
        'total_pages' => (int) $page_data['total_pages'],
    ]);
}
add_action('wp_ajax_pge_invitation_mgmt_list', 'pge_invitation_mgmt_list_handler');

// ── إنشاء دعوة ────────────────────────────────────────────────────────────
function pge_invitation_mgmt_create_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

    $result = PGE_Invitation_Service::create($event_id, $phone, $name, $note, get_current_user_id());
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'created') {
        wp_send_json_success(['message' => 'تمت إضافة الدعوة', 'phone' => $result['phone']]);
    }
    if ($outcome === 'duplicate') {
        wp_send_json_error(['message' => 'هذا الرقم مدعو بالفعل لهذه المناسبة', 'reason' => 'duplicate']);
    }
    // Guest Limit Unification RFC — Part G: فشل آمن بلا كتابة جزئية —
    // PGE_Invitation_Service::create() ترفض الإنشاء بالكامل (لا Repository،
    // لا Audit) قبل أي كتابة عندما تكون الحصة ممتلئة.
    if ($outcome === 'quota_exceeded') {
        wp_send_json_error(['message' => 'تم الوصول إلى الحد الأقصى للمدعوين في هذه المناسبة.', 'reason' => 'guest_limit_reached']);
    }

    wp_send_json_error(['message' => 'تعذّر إنشاء الدعوة', 'reason' => (string) ($result['reason'] ?? 'unknown_error')]);
}
add_action('wp_ajax_pge_invitation_mgmt_create', 'pge_invitation_mgmt_create_handler');

// ── تعديل دعوة ────────────────────────────────────────────────────────────
function pge_invitation_mgmt_edit_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    $old_phone = isset($_POST['old_phone']) ? sanitize_text_field(wp_unslash($_POST['old_phone'])) : '';
    $new_phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

    $result = PGE_Invitation_Service::edit($event_id, $old_phone, $new_phone, $name, $note, get_current_user_id());
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'updated') {
        wp_send_json_success(['message' => 'تم تحديث الدعوة', 'phone' => $result['phone']]);
    }
    if ($outcome === 'duplicate') {
        wp_send_json_error(['message' => 'رقم الجوال الجديد مستخدَم بالفعل ضمن هذه المناسبة', 'reason' => 'duplicate']);
    }
    if ($outcome === 'error' && ($result['reason'] ?? '') === 'not_found') {
        wp_send_json_error(['message' => 'الدعوة غير موجودة ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    wp_send_json_error(['message' => 'تعذّر تحديث الدعوة', 'reason' => (string) ($result['reason'] ?? 'unknown_error')]);
}
add_action('wp_ajax_pge_invitation_mgmt_edit', 'pge_invitation_mgmt_edit_handler');

// ── إلغاء دعوة ────────────────────────────────────────────────────────────
function pge_invitation_mgmt_cancel_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
    $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

    $result = PGE_Invitation_Service::cancel($event_id, $phone, $reason, get_current_user_id());
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'cancelled') {
        wp_send_json_success(['message' => 'تم إلغاء الدعوة']);
    }
    if ($outcome === 'already_cancelled') {
        wp_send_json_error(['message' => 'هذه الدعوة مُلغاة بالفعل', 'reason' => 'already_cancelled']);
    }
    if ($outcome === 'error' && ($result['reason'] ?? '') === 'not_found') {
        wp_send_json_error(['message' => 'الدعوة غير موجودة ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    wp_send_json_error(['message' => 'تعذّر إلغاء الدعوة', 'reason' => (string) ($result['reason'] ?? 'unknown_error')]);
}
add_action('wp_ajax_pge_invitation_mgmt_cancel', 'pge_invitation_mgmt_cancel_handler');

// ── إعادة إرسال (Phase 9B — مُعتمَدة، مُسجَّلة أدناه، قابلة للوصول) ──────────
function pge_invitation_mgmt_resend_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();
    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

    $result = PGE_Invitation_Service::resend($event_id, $phone, get_current_user_id());
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'resent') {
        wp_send_json_success(['message' => 'تمت إعادة الإرسال']);
    }
    if ($outcome === 'error' && ($result['reason'] ?? '') === 'cancelled') {
        wp_send_json_error(['message' => 'لا يمكن إعادة الإرسال لدعوة مُلغاة', 'reason' => 'cancelled']);
    }
    if ($outcome === 'error' && ($result['reason'] ?? '') === 'not_found') {
        wp_send_json_error(['message' => 'الدعوة غير موجودة ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    wp_send_json_error(['message' => 'تعذّرت إعادة الإرسال', 'reason' => (string) ($result['reason'] ?? 'unknown_error')]);
}
// Phase 9B: مُسجَّلة عمداً — Resend مُعتمَدة الآن ضمن نطاق Phase 9B.
if (PGE_INVITATION_MGMT_RESEND_QR_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_resend', 'pge_invitation_mgmt_resend_handler');
}

// ── تجديد رمز الدعوة (QR Regeneration) — (Phase 9B — مُعتمَدة، مُسجَّلة أدناه) ──
// Phase 9B QR Architecture Final Fix: PGE_Invitation_Repository::regenerate_qr()
// لم يعد يُعيد مفتاح 'code' (لم يعد يمسّ invite_code إطلاقاً) — يُعيد
// 'qr_version' (بدائيّ التدوير الجديد بعد التجديد). لا كشف لأي حمولة QR
// موقَّعة فعلية هنا — فقط رقم الإصدار (بيانات غير سرّية، غير كافية وحدها
// لبناء QR صالح دون معرفة rsvp_id الحقيقي من جهة الخادم).
function pge_invitation_mgmt_qr_regenerate_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();
    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

    $result = PGE_Invitation_Service::regenerate_qr($event_id, $phone, get_current_user_id());
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'regenerated') {
        wp_send_json_success(['message' => 'تم تجديد رمز الدعوة', 'qr_version' => $result['qr_version']]);
    }
    if ($outcome === 'error' && ($result['reason'] ?? '') === 'cancelled') {
        wp_send_json_error(['message' => 'لا يمكن تجديد رمز دعوة مُلغاة', 'reason' => 'cancelled']);
    }
    if ($outcome === 'error' && ($result['reason'] ?? '') === 'not_found') {
        wp_send_json_error(['message' => 'الدعوة غير موجودة ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    wp_send_json_error(['message' => 'تعذّر تجديد رمز الدعوة', 'reason' => (string) ($result['reason'] ?? 'unknown_error')]);
}
// Phase 9B: مُسجَّلة عمداً — QR Regeneration مُعتمَدة الآن ضمن نطاق Phase 9B.
if (PGE_INVITATION_MGMT_RESEND_QR_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_qr_regenerate', 'pge_invitation_mgmt_qr_regenerate_handler');
}

// ── تصدير CSV/Excel (Phase 9C — مُعتمَدة، مُسجَّلة أدناه) ──────────────────────
/**
 * اسم ملف آمن للتنزيل — يعتمد حصراً على event_id (عدد صحيح مُوثَّق مسبقاً في
 * pge_invitation_mgmt_validate_request()) وامتداد ثابت معروف؛ لا نص من
 * المستخدم يدخل مسار الملف إطلاقاً (لا Path Traversal ممكن بنيوياً)،
 * وsanitize_file_name() الحالية (نواة ووردبريس) تُطبَّق فوق ذلك دفاعاً إضافياً.
 */
if (!function_exists('pge_invitation_mgmt_export_filename')) {
    function pge_invitation_mgmt_export_filename(int $event_id, string $extension): string
    {
        $raw = 'invitations-' . $event_id . '.' . $extension;
        return function_exists('sanitize_file_name') ? sanitize_file_name($raw) : preg_replace('/[^A-Za-z0-9_\-\.]/', '', $raw);
    }
}

/**
 * تسجيل حدث تدقيق تصدير واحد — راجع تعليق export_completed في
 * class-pge-invitation-management-audit.php للتبرير الكامل (لا تعديل على
 * بنية الجدول، Format/Record count داخل عمود reason الحالي كـJSON مضغوط).
 * لا تُخزَّن محتويات التصدير نفسها إطلاقاً — فقط الصيغة والعدد.
 */
if (!function_exists('pge_invitation_mgmt_record_export_audit')) {
    function pge_invitation_mgmt_record_export_audit(int $event_id, string $format, int $count, int $actor_user_id): void
    {
        if (!class_exists('PGE_Invitation_Management_Audit')) {
            return;
        }
        PGE_Invitation_Management_Audit::record(
            $event_id,
            PGE_Invitation_Management_Audit::EVENT_LEVEL_PHONE_SENTINEL,
            $actor_user_id,
            'export_completed',
            wp_json_encode(['format' => $format, 'count' => $count])
        );
    }
}

if (!function_exists('pge_invitation_mgmt_log_export_failure')) {
    /**
     * RC1 Fix Pack 2 (A21 — Silent Export Failures): تشخيص خادمي عند فشل بناء
     * بيانات التصدير — يُعيد استخدام آلية التسجيل الموجودة فعلاً في المشروع
     * (error_log()، بنفس اصطلاح `[tag] key=value` المُتَّبع أصلاً في
     * includes/replacement-entitlement-grant.php: `[replacement_entitlement_error]
     * event_id=... user_id=... reason=...`) — بلا اختراع آلية تسجيل ثانية.
     *
     * لا تغيير في سلوك العميل إطلاقاً: استجابة wp_send_json_error() في نقطتَي
     * الاستدعاء (CSV/Excel) تبقى حرفياً كما كانت (نفس 'message'/'reason')؛
     * هذا التسجيل إضافي وخادمي بحت، لا يظهر في الاستجابة بأي شكل.
     *
     * ممنوع تسجيله عمداً هنا: اسم الضيف، رقم الجوال، بيانات QR، أو أي محتوى
     * من صفوف التصدير نفسها. رسالة الاستثناء (getMessage()) لا تُسجَّل
     * عمداً أيضاً — لتفادي أي احتمال، ولو نظرياً، لتسريب بيانات ضمن نص
     * استثناء صادر من طبقة أعمق؛ فئة الاستثناء وحدها (get_class()) بيانات
     * تشخيصية وصفية غير حساسة وتكفي للتتبّع الأولي في السجلّات.
     *
     * الحقول المُسجَّلة (مطابقة لمتطلَّبات RFC حرفياً): event_id, user_id,
     * format (csv/xlsx), timestamp, failure_category.
     */
    function pge_invitation_mgmt_log_export_failure(int $event_id, string $format, \Throwable $e): void
    {
        error_log(sprintf(
            '[invitation_export_failed] event_id=%d user_id=%d format=%s timestamp=%s failure_category=export_build_failed exception_class=%s',
            $event_id,
            (int) get_current_user_id(),
            $format,
            current_time('mysql'),
            get_class($e)
        ));
    }
}

/**
 * تصدير CSV — يبني كامل مجموعة البيانات أولاً (قبل إرسال أي ترويسة/مخرَجات)
 * حتى يبقى أي خطأ محتمَل قابلاً للإرجاع كـJSON نظيف (wp_send_json_error) بلا
 * أي احتمال لتسريب استثناء/تتبّع مكدَّس (stack trace) إلى المتصفح داخل ملف
 * منزَّل جزئياً. بعد نجاح البناء فقط: ترويسات صحيحة، ثم بث كل سطر مباشرة
 * (echo لكل صف على حدة، لا سلسلة نصية واحدة ضخمة في الذاكرة) — يدعم قوائم
 * دعوات كبيرة بذاكرة ثابتة تقريباً بدل تناسبها مع حجم المناسبة.
 */
function pge_invitation_mgmt_export_csv_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();
    $filters = pge_invitation_mgmt_filters_from_request();

    try {
        $dataset = PGE_Invitation_Export::build_dataset($event_id, $filters);
    } catch (\Throwable $e) {
        pge_invitation_mgmt_log_export_failure($event_id, 'csv', $e); // RC1 Fix Pack 2 (A21)
        wp_send_json_error(['message' => 'تعذّر تجهيز بيانات التصدير', 'reason' => 'export_build_failed']);
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . pge_invitation_mgmt_export_filename($event_id, 'csv') . '"');
        header('X-Content-Type-Options: nosniff');
    }

    echo PGE_Invitation_Export::CSV_BOM;
    echo PGE_Invitation_Export::csv_line($dataset['header']);
    foreach ($dataset['rows'] as $row) {
        echo PGE_Invitation_Export::csv_line($row);
    }

    pge_invitation_mgmt_record_export_audit($event_id, 'csv', $dataset['count'], get_current_user_id());

    if (function_exists('wp_die')) { wp_die('', '', ['response' => 200]); }
    exit;
}
if (PGE_INVITATION_MGMT_EXPORT_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_export_csv', 'pge_invitation_mgmt_export_csv_handler');
}

/**
 * تصدير Excel — .xlsx حقيقي (حاوية ZIP + XML OOXML صالحة) عبر PGE_Xlsx_Writer
 * (لا مكتبة Composer قائمة في المشروع — "الحد الأدنى من التنفيذ المدعوم"
 * المطلوب صراحةً؛ راجع توثيق القرار في أعلى class-pge-xlsx-writer.php). لا
 * جدول HTML بامتداد .xls كما كان في التنفيذ السابق (Phase 9C يمنع ذلك
 * صراحةً: "Do NOT generate HTML disguised as Excel").
 */
function pge_invitation_mgmt_export_excel_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();
    $filters = pge_invitation_mgmt_filters_from_request();

    try {
        $dataset = PGE_Invitation_Export::build_dataset($event_id, $filters);
        $binary = PGE_Xlsx_Writer::build(array_merge([$dataset['header']], $dataset['rows']));
    } catch (\Throwable $e) {
        pge_invitation_mgmt_log_export_failure($event_id, 'xlsx', $e); // RC1 Fix Pack 2 (A21)
        wp_send_json_error(['message' => 'تعذّر تجهيز بيانات التصدير', 'reason' => 'export_build_failed']);
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . pge_invitation_mgmt_export_filename($event_id, 'xlsx') . '"');
        header('Content-Length: ' . strlen($binary));
        header('X-Content-Type-Options: nosniff');
    }
    echo $binary;

    pge_invitation_mgmt_record_export_audit($event_id, 'xlsx', $dataset['count'], get_current_user_id());

    if (function_exists('wp_die')) { wp_die('', '', ['response' => 200]); }
    exit;
}
if (PGE_INVITATION_MGMT_EXPORT_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_export_excel', 'pge_invitation_mgmt_export_excel_handler');
}

// ── استيراد المدعوين من Excel — تنزيل النموذج الرسمي (Phase 1) ─────────────
/**
 * "Import Guests from Excel" — راجع docs/EXCEL-GUEST-IMPORT-SPEC.md.
 * Phase 1: endpoint تنزيل النموذج الرسمي الفارغ (أدناه مباشرة). بوابة نطاق
 * واحدة تُغطّي كل endpoints الاستيراد الحالية (Template + Preview الآن في
 * Phase 3، انظر لاحقاً في هذا الملف) — بنفس نمط بقية ثوابت هذا الملف
 * (PGE_INVITATION_MGMT_EXPORT_ENABLED إلخ). لا يزال لا يوجد Confirm/Import
 * فعلي حتى الآن — Phase لاحقة غير مُعتمَدة بعد.
 */
if (!defined('PGE_INVITATION_MGMT_EXCEL_IMPORT_ENABLED')) {
    define('PGE_INVITATION_MGMT_EXCEL_IMPORT_ENABLED', true);
}

if (!function_exists('pge_invitation_mgmt_excel_template_filename')) {
    /**
     * اسم ملف ثابت (لا مدخل مستخدم في التسمية إطلاقاً) — النموذج عام وغير
     * مرتبط ببيانات مناسبة بعينها.
     */
    function pge_invitation_mgmt_excel_template_filename(): string
    {
        $raw = 'excel-import-template.xlsx';
        return function_exists('sanitize_file_name') ? sanitize_file_name($raw) : preg_replace('/[^A-Za-z0-9_\-\.]/', '', $raw);
    }
}

/**
 * تنزيل نموذج Excel الرسمي — 3 أعمدة بالضبط: الاسم | رقم الجوال | ملاحظة
 * (القسم 6 من الوثيقة المعتمدة، Template Contract). عمود "رقم الجوال"
 * (الفهرس 1، 0-based) يُمرَّر ضمن $text_columns لـ PGE_Xlsx_Writer::build()
 * فيُنسَّق كـ Text Cell (numFmtId="49") — يمنع Excel من حذف صفر بادئ أو
 * تحويله لصيغة علمية حين يملأ المستخدم الجوال لاحقاً (القسم 5.1، Phone
 * Column Contract). التحقق من الصلاحية عبر نفس حارس كل معالجات هذا الملف
 * (pge_mgmt_validate_request()) — النموذج نفسه ثابت وعام، $event_id يُستخدَم
 * فقط للتأكد أن الطالب مسجّل دخول ولديه صلاحية إدارة مناسبة فعلية، بلا أي
 * تأثير على محتوى الملف.
 */
function pge_invitation_mgmt_excel_template_handler()
{
    pge_invitation_mgmt_validate_request();

    $binary = PGE_Xlsx_Writer::build(
        [['الاسم', 'رقم الجوال', 'ملاحظة']],
        [1]
    );

    if (!headers_sent()) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . pge_invitation_mgmt_excel_template_filename() . '"');
        header('Content-Length: ' . strlen($binary));
        header('X-Content-Type-Options: nosniff');
    }
    echo $binary;

    if (function_exists('wp_die')) { wp_die('', '', ['response' => 200]); }
    exit;
}
if (PGE_INVITATION_MGMT_EXCEL_IMPORT_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_excel_template', 'pge_invitation_mgmt_excel_template_handler');
}

// ── استيراد المدعوين من Excel — Phase 3: Upload + Parse + Validate + Duplicate Detection + Preview ──
/**
 * ============================================================================
 * "Import Guests from Excel" — Phase 3 فقط (راجع docs/EXCEL-GUEST-IMPORT-SPEC.md)
 * ============================================================================
 * الهدف الحصري لهذه المرحلة: ربط طبقة قراءة Excel (Phase 2) بمنطق التحقق +
 * فحص التكرار، وعرض شاشة Preview فقط. **لا إنشاء أي مدعو هنا إطلاقاً** — لا
 * استدعاء PGE_Invitation_Service::create()، لا PGE_Invitation_Bulk_Add_
 * Service، لا Audit، لا QR، لا Invitation Code، لا INSERT قاعدة بيانات، ولا
 * حذف للملف المؤقت (يبقى على القرص + الـ transient قائماً — التنظيف مسؤولية
 * مرحلة الـ Confirm اللاحقة حصراً، غير موجودة بعد).
 *
 * المسار الكامل لهذا المعالج الوحيد:
 *   Upload → تخزين مؤقت (5.3) → إنشاء upload_token/Transient (5.2) →
 *   parse_file() (Phase 2) → apply_duplicate_detection() (Phase 3) →
 *   Preview Response.
 *
 * upload_token: نفس التصميم المعتمَد في القسم 5.2 من الوثيقة — WordPress
 * Transient قياسي `pge_excel_import_{token}` يحمل user_id/event_id/file_path/
 * file_type/created_at، TTL ساعة واحدة (HOUR_IN_SECONDS). المتصفح يعرف
 * upload_token فقط — file_path لا يُرسَل للعميل إطلاقاً في أي استجابة.
 */
if (!defined('PGE_INVITATION_MGMT_EXCEL_IMPORT_MAX_BYTES')) {
    // 2MB — حد أقصى موثَّق صراحةً في القسم 7 (جدول المخاطر) من الوثيقة المعتمدة.
    define('PGE_INVITATION_MGMT_EXCEL_IMPORT_MAX_BYTES', 2 * 1024 * 1024);
}

if (!function_exists('pge_invitation_mgmt_excel_upload_error_message')) {
    function pge_invitation_mgmt_excel_upload_error_message(string $reason): string
    {
        $messages = [
            'no_file'              => 'لم يتم اختيار ملف',
            'upload_error'          => 'تعذّر رفع الملف، حاول مرة أخرى',
            'file_too_large'        => 'حجم الملف أكبر من الحد المسموح (2 ميجابايت)',
            'unsupported_extension' => 'صيغة الملف غير مدعومة. المسموح: xlsx أو csv فقط.',
            'invalid_mime'          => 'نوع الملف الفعلي لا يطابق الامتداد',
            'storage_failed'        => 'تعذّر تخزين الملف مؤقتاً على الخادم',
        ];
        return $messages[$reason] ?? ($reason !== '' ? $reason : 'خطأ غير معروف');
    }
}

/**
 * القسم 5.3 من الوثيقة: مجلد مخصص `wp-content/uploads/pge-excel-import/`،
 * وليس `sys_get_temp_dir()`/`wp_tempnam()` (سبب القرار موثَّق بالكامل هناك).
 */
if (!function_exists('pge_invitation_mgmt_excel_import_base_dir')) {
    function pge_invitation_mgmt_excel_import_base_dir(): string
    {
        $uploads = wp_upload_dir();
        $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        return rtrim($basedir, '/\\') . '/pge-excel-import';
    }
}

/**
 * حماية المجلد: `.htaccess` يمنع تنفيذ PHP ويمنع الوصول المباشر لأي ملف
 * داخل المجلد بالكامل (القسم 5.3) — بنفس فلسفة `.htaccess` الحالي في جذر
 * المشروع. يُنشَأ مرة واحدة فقط عند أول استخدام فعلي للمجلد (idempotent).
 */
if (!function_exists('pge_invitation_mgmt_excel_import_ensure_protected_dir')) {
    function pge_invitation_mgmt_excel_import_ensure_protected_dir(string $dir): void
    {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $htaccess_path = $dir . '/.htaccess';
        if (!file_exists($htaccess_path)) {
            $contents = "Options -Indexes\n"
                . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
                . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
                . "<FilesMatch \"\\.(php|phtml|php\\d)$\">\n"
                . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
                . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
                . "</FilesMatch>\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
            @file_put_contents($htaccess_path, $contents);
        }

        $index_path = $dir . '/index.html';
        if (!file_exists($index_path)) {
            @file_put_contents($index_path, '');
        }
    }
}

/**
 * ملصق حالة الصف لجدول Preview (✅/⚠) — يُطبَّق فقط على العرض، لا يغيّر قيمة
 * 'status' الفعلية المُرجَعة (تبقى الحالة التفصيلية الخام كما هي).
 */
if (!function_exists('pge_invitation_mgmt_excel_import_status_label')) {
    function pge_invitation_mgmt_excel_import_status_label(string $status): string
    {
        $labels = [
            'valid'                   => '✅ صالح',
            'duplicate'               => '⚠ مكرر',
            'invalid_phone'           => '⚠ رقم غير صالح',
            'invalid_phone_cell_type' => '⚠ رقم غير صالح',
            'missing_name'            => '⚠ اسم مفقود',
            'missing_phone'           => '⚠ رقم مفقود',
            'empty_row'               => '⚠ صف فارغ',
        ];
        return $labels[$status] ?? $status;
    }
}

if (!function_exists('pge_invitation_mgmt_reshape_excel_import_row')) {
    function pge_invitation_mgmt_reshape_excel_import_row(array $row): array
    {
        $shaped = [
            'name'         => (string) $row['name'],
            'phone'        => (string) $row['phone'],
            'note'         => (string) $row['note'],
            'status'       => (string) $row['status'],
            'status_label' => pge_invitation_mgmt_excel_import_status_label((string) $row['status']),
        ];
        // Phase 4 — يُضاف فقط من مسار Confirm (نفس نمط pge_invitation_mgmt_reshape_bulk_row()).
        if (isset($row['result'])) {
            $shaped['result'] = (string) $row['result'];
        }
        return $shaped;
    }
}

/**
 * أغلفة رقيقة حول `is_uploaded_file()`/`move_uploaded_file()` الأصليتين —
 * كلتاهما تتطلبان سياق رفع HTTP حقيقياً بنيوياً (PHP الأساسية)، فلا يمكن
 * محاكاتهما في اختبار CLI تنفيذي حقيقي بدون هذا الغلاف القابل للاستبدال
 * (نفس اصطلاح `function_exists()` guard المُتَّبع فعلاً في هذا الملف/المشروع
 * لإتاحة استبدال دوال WP من طبقة الاختبار قبل تحميل هذا الملف — مثال:
 * `pge_generate_invite_code()` في اختبارات أخرى). الإنتاج يستخدم السلوك
 * الحقيقي دائماً؛ لا تغيير سلوكي هنا على الإطلاق.
 */
if (!function_exists('pge_invitation_mgmt_is_uploaded_file')) {
    function pge_invitation_mgmt_is_uploaded_file(string $path): bool
    {
        return is_uploaded_file($path);
    }
}
if (!function_exists('pge_invitation_mgmt_move_uploaded_file')) {
    function pge_invitation_mgmt_move_uploaded_file(string $src, string $dst): bool
    {
        return move_uploaded_file($src, $dst);
    }
}

/**
 * المعالج الوحيد لهذه المرحلة — Upload Endpoint الكامل (رفع + تخزين مؤقت +
 * تحليل + تحقق + فحص تكرار + Preview)، بلا أي كتابة بيانات إطلاقاً.
 */
function pge_invitation_mgmt_excel_preview_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        wp_send_json_error(['message' => pge_invitation_mgmt_excel_upload_error_message('no_file'), 'reason' => 'no_file']);
    }
    $file = $_FILES['file'];

    $error_code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error_code === UPLOAD_ERR_NO_FILE) {
        wp_send_json_error(['message' => pge_invitation_mgmt_excel_upload_error_message('no_file'), 'reason' => 'no_file']);
    }
    if ($error_code !== UPLOAD_ERR_OK) {
        wp_send_json_error(['message' => pge_invitation_mgmt_excel_upload_error_message('upload_error'), 'reason' => 'upload_error']);
    }

    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size <= 0 || $size > PGE_INVITATION_MGMT_EXCEL_IMPORT_MAX_BYTES) {
        wp_send_json_error(['message' => pge_invitation_mgmt_excel_upload_error_message('file_too_large'), 'reason' => 'file_too_large']);
    }

    $original_name = isset($file['name']) ? (string) $file['name'] : '';
    $ext = strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION));
    if (!in_array($ext, PGE_Invitation_Excel_Import_Service::SUPPORTED_TYPES, true)) {
        wp_send_json_error(['message' => pge_invitation_mgmt_excel_upload_error_message('unsupported_extension'), 'reason' => 'unsupported_extension']);
    }

    $tmp_path = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    if ($tmp_path === '' || !pge_invitation_mgmt_is_uploaded_file($tmp_path)) {
        wp_send_json_error(['message' => pge_invitation_mgmt_excel_upload_error_message('upload_error'), 'reason' => 'upload_error']);
    }

    // مطابقة MIME حقيقية (finfo) — طبقة دفاع إضافية إلى جانب فحص الامتداد
    // (القسم 7 من الوثيقة، خطر "رفع ملفات ضارة"). fallback صامت إن غاب finfo.
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = $finfo ? finfo_file($finfo, $tmp_path) : false;
        if ($finfo) finfo_close($finfo);

        $allowed_mimes = $ext === 'xlsx'
            ? ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream']
            : ['text/plain', 'text/csv', 'application/csv'];

        if ($real_mime && !in_array($real_mime, $allowed_mimes, true)) {
            wp_send_json_error(['message' => pge_invitation_mgmt_excel_upload_error_message('invalid_mime'), 'reason' => 'invalid_mime']);
        }
    }

    $base_dir = pge_invitation_mgmt_excel_import_base_dir();
    pge_invitation_mgmt_excel_import_ensure_protected_dir($base_dir);

    $event_dir = $base_dir . '/' . $event_id;
    if (!file_exists($event_dir)) {
        wp_mkdir_p($event_dir);
    }

    // اسم الملف الداخلي = token عشوائي فقط — لا يُقرأ ولا يُخزَّن اسم الملف
    // الأصلي الذي رفعه المستخدم بأي حال (القسم 5.3).
    $token = bin2hex(random_bytes(16));
    $target_path = $event_dir . '/' . $token . '.' . $ext;

    $moved = pge_invitation_mgmt_move_uploaded_file($tmp_path, $target_path);
    if (!$moved) {
        wp_send_json_error(['message' => pge_invitation_mgmt_excel_upload_error_message('storage_failed'), 'reason' => 'storage_failed']);
    }

    // القسم 5.2: الـ Transient يُنشَأ فور تخزين الملف، قبل أي محاولة تحليل —
    // حتى لو فشل parse_file() لاحقاً، يبقى الملف + الـ transient قائمَين (لا
    // حذف في هذه المرحلة، ممنوع صراحةً في نطاق Phase 3 الحالي).
    set_transient('pge_excel_import_' . $token, [
        'user_id'    => get_current_user_id(),
        'event_id'   => $event_id,
        'file_path'  => $target_path,
        'file_type'  => $ext,
        'created_at' => time(),
    ], HOUR_IN_SECONDS);

    $parsed = PGE_Invitation_Excel_Import_Service::parse_file($target_path, $ext);
    if (!$parsed['ok']) {
        wp_send_json_error([
            'message'      => (string) $parsed['message'],
            'reason'       => (string) $parsed['error'],
            'upload_token' => $token,
        ]);
    }

    $rows = $parsed['rows'];
    PGE_Invitation_Excel_Import_Service::apply_duplicate_detection($event_id, $rows);
    $summary = PGE_Invitation_Excel_Import_Service::summarize_preview($rows);

    // ── حدود الباقة (Guest Limit/Package Quota) ─────────────────────────
    // إضافة بحتة بعد اكتمال منطق Preview الحالي بالكامل — لا تغيير في
    // parse_file()/apply_duplicate_detection()/summarize_preview() ولا في
    // أي من قيمها. الغرض حصراً: إخبار الواجهة كم سيتم استيراده فعلياً إذا
    // تجاوزت الصفوف الصالحة الحصة المتبقية في باقة المضيف، حتى تعرض بطاقة
    // "حدود الباقة" وتُحدِّث نص زر التأكيد. الرقم النهائي الفعلي يُعاد
    // احتسابه من الصفر عند Confirm (لا اعتماد على ما يُحسَب هنا).
    $quota = function_exists('pge_resolve_guest_quota_status')
        ? pge_resolve_guest_quota_status($event_id)
        : ['mode' => 'unlimited', 'limit' => null, 'current' => 0, 'remaining' => null];

    $valid_rows_count = (int) ($summary['valid'] ?? 0);
    $will_import = $valid_rows_count;
    $quota_exceeded = false;
    if ($quota['mode'] === 'limited' && $valid_rows_count > (int) $quota['remaining']) {
        $will_import = (int) $quota['remaining'];
        $quota_exceeded = true;
    }

    wp_send_json_success([
        'upload_token' => $token,
        'summary'      => $summary,
        'rows'         => array_map('pge_invitation_mgmt_reshape_excel_import_row', $rows),
        'quota'        => [
            'mode'           => $quota['mode'],
            'limit'          => $quota['limit'],
            'current'        => $quota['current'],
            'remaining'      => $quota['remaining'],
            'valid_rows'     => $valid_rows_count,
            'will_import'    => $will_import,
            'quota_exceeded' => $quota_exceeded,
        ],
        // "سيتم تنفيذ الاستيراد في المرحلة التالية." — لا زر تأكيد بعد، لا Import هنا.
        'note'         => 'سيتم تنفيذ الاستيراد في المرحلة التالية.',
    ]);
}
if (PGE_INVITATION_MGMT_EXCEL_IMPORT_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_excel_preview', 'pge_invitation_mgmt_excel_preview_handler');
}

/**
 * تنظيف مؤكَّد (حذف الملف المؤقت + الـ transient) — يُستدعى فقط بعد إثبات
 * أن الطلب الحالي يملك هذا الـ token فعلاً (القسم 7 من EXCEL-GUEST-IMPORT-
 * SPEC.md: "لا تحذف ملفاً لا دليل على صلته بالطلب الحالي"). لهذا لا يوجد
 * استدعاء لهذه الدالة في نقاط الرفض المبكرة (token غير موجود / يخص مستخدماً
 * أو مناسبة أخرى) — فقط بعد نقطة التحقق من user_id + event_id.
 *
 * ملاحظة تصميم مهمة: لا اعتماد على `finally` في المعالج أدناه رغم أنها كانت
 * ستعمل داخل اختبارات CLI (حيث wp_send_json_error/success تُحاكَى برمي
 * استثناء قابل للالتقاط). في ووردبريس الحقيقي تستدعي هاتان الدالتان
 * wp_die()، وهي بشكل افتراضي exit()/die() — إنهاء عملية PHP صلب لا يُنفِّذ
 * كتل `finally`. لذلك يُستدعى هذا التنظيف صراحةً وبشكل مباشر قبل كل نقطة
 * إرسال استجابة بعد إثبات الملكية، لا داخل `finally`.
 */
if (!function_exists('pge_invitation_mgmt_excel_cleanup')) {
    function pge_invitation_mgmt_excel_cleanup(string $token, string $file_path): void
    {
        if ($file_path !== '' && file_exists($file_path)) {
            @unlink($file_path);
        }
        delete_transient('pge_excel_import_' . $token);
    }
}

/**
 * ── تأكيد الاستيراد الفعلي (Confirm) ─────────────────────────────────────
 * Phase 4 — EXCEL-GUEST-IMPORT-SPEC.md. يقبل من المتصفح: nonce + event_id +
 * upload_token فقط. "Forbidden to receive or trust: rows, names, phones,
 * notes, counts, statuses, file_path, preview data" — كل هذه تُعاد بناؤها
 * من الملف الخام المخزَّن على الخادم فقط.
 *
 * التسلسل الإلزامي: upload_token → إعادة التحقق من التفويض → إعادة تحميل
 * الـ transient → التحقق من user_id + event_id → قراءة file_path من الـ
 * transient حصراً → إعادة تحليل نفس الملف الخام → إعادة التحقق من الصفوف →
 * إعادة تشغيل فحص التكرار من حالة المناسبة الحالية → استيراد الصفوف الصالحة
 * فقط → تدقيق → حذف الملف المؤقت + الـ transient → إرجاع النتيجة النهائية.
 *
 * "Never rely on status=duplicate/valid coming from Preview" — الصفوف تُعاد
 * تحليلها وفحص تكرارها من الصفر هنا (القسم 3)، لأن ضيفاً قد يكون أُضيف
 * يدوياً بين Preview وConfirm (الاختبار الإلزامي #12/#9 Race Condition).
 *
 * رسالة الرفض الموحَّدة أدناه (token غير موجود / يخص مستخدماً آخر / يخص
 * مناسبة أخرى) مقصودة أمنياً — لمنع مهاجم من التمييز بين "Token غير موجود"
 * و"Token موجود لكن يخص طرفاً آخر" (Token enumeration).
 */
function pge_invitation_mgmt_excel_confirm_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();
    $token = isset($_POST['upload_token']) ? sanitize_text_field(wp_unslash($_POST['upload_token'])) : '';
    $actor_user_id = get_current_user_id();

    $expired_message = 'انتهت صلاحية جلسة الاستيراد أو تم تنفيذها مسبقاً.';

    if ($token === '') {
        wp_send_json_error(['message' => $expired_message, 'reason' => 'invalid_token']);
    }

    $transient_key = 'pge_excel_import_' . $token;
    $stored = get_transient($transient_key);

    if (!is_array($stored)) {
        // لا دليل على ملكية أي ملف بعد — بلا cleanup هنا عمداً (القسم 7).
        wp_send_json_error(['message' => $expired_message, 'reason' => 'token_not_found']);
    }

    $stored_user_id = isset($stored['user_id']) ? (int) $stored['user_id'] : 0;
    $stored_event_id = isset($stored['event_id']) ? (int) $stored['event_id'] : 0;
    $stored_file_path = isset($stored['file_path']) ? (string) $stored['file_path'] : '';
    $stored_file_type = isset($stored['file_type']) ? (string) $stored['file_type'] : '';

    if ($stored_user_id !== $actor_user_id || $stored_event_id !== $event_id) {
        // نفس الرسالة/السبب الموحَّد عمداً — راجع تعليق منع Token enumeration أعلاه.
        wp_send_json_error(['message' => $expired_message, 'reason' => 'token_not_found']);
    }

    // من هذه النقطة: الملكية مُثبَتة — أي رفض لاحق يُنظِّف الملف + الـ transient أولاً.
    if ($stored_file_type === '' || !in_array($stored_file_type, PGE_Invitation_Excel_Import_Service::SUPPORTED_TYPES, true)) {
        pge_invitation_mgmt_excel_cleanup($token, $stored_file_path);
        wp_send_json_error(['message' => $expired_message, 'reason' => 'invalid_file_type']);
    }

    if ($stored_file_path === '' || !is_readable($stored_file_path)) {
        pge_invitation_mgmt_excel_cleanup($token, $stored_file_path);
        wp_send_json_error(['message' => $expired_message, 'reason' => 'file_missing']);
    }

    // القسم 2: إعادة تحليل الملف الخام نفسه من الصفر — لا اعتماد إطلاقاً على نتائج Preview السابقة.
    $parsed = PGE_Invitation_Excel_Import_Service::parse_file($stored_file_path, $stored_file_type);
    if (!$parsed['ok']) {
        pge_invitation_mgmt_excel_cleanup($token, $stored_file_path);
        wp_send_json_error(['message' => (string) $parsed['message'], 'reason' => (string) $parsed['error']]);
    }

    $rows = $parsed['rows'];

    // القسم 3: فحص تكرار جديد كلياً من حالة المناسبة الحالية لحظة التأكيد.
    PGE_Invitation_Excel_Import_Service::apply_duplicate_detection($event_id, $rows);

    $imported = 0;
    $duplicates = 0;
    $invalid = 0;
    $empty = 0;
    $failed = 0;
    $quota_exceeded_count = 0;
    $valid_before_import = 0;

    // ── حدود الباقة (Guest Limit/Package Quota) — Guest Limit Unification RFC، Part I ──
    // لم يعد هذا الملف يحسب سقفاً محلياً مجمَّداً (import_cap) ولا يقرر بنفسه
    // متى يتوقف الاستيراد — تلك كانت سلطة إنفاذ ثانية موازية لـ
    // PGE_Invitation_Service::create()، وقد أُزيلت هنا صراحةً (RFC: "Do NOT
    // rely on a frozen import_cap as the final authority... Excel no longer
    // has a second authoritative quota algorithm"). كل صف valid يستدعي الآن
    // create() دون أي بوابة سابقة — هي وحدها من تقرر (عبر قفل ذري لكل
    // مناسبة، راجع class-pge-invitation-service.php) القبول أو الرفض
    // بحصة_exceeded، بناءً على الحالة الحية لحظة ذلك الصف بالذات، لا لحظة
    // بداية هذا الطلب.
    foreach ($rows as &$row) {
        $status = (string) ($row['status'] ?? '');

        if ($status === PGE_Invitation_Excel_Import_Service::STATUS_DUPLICATE) {
            $duplicates++;
            $row['result'] = 'duplicate';
            continue;
        }

        if ($status === 'empty_row') {
            $empty++;
            $row['result'] = 'empty';
            continue;
        }

        if ($status !== 'valid') {
            // missing_name / missing_phone / invalid_phone / invalid_phone_cell_type
            $invalid++;
            $row['result'] = 'invalid';
            continue;
        }

        $valid_before_import++;

        // القسم 4: مسار الإنشاء الوحيد المسموح — بلا INSERT مباشر، بلا
        // كتابة مباشرة على Repository، بلا استدعاء دالة حفظ خريطة الضيوف
        // مباشرة من هنا، بلا أي Service مواز. Guest Limit Unification RFC،
        // Part I: يُستدعى لكل صف valid بلا استثناء — Service::create() هي
        // السلطة الوحيدة التي تقرر created/duplicate/quota_exceeded الآن.
        try {
            $create_result = PGE_Invitation_Service::create(
                $event_id,
                (string) $row['phone'],
                (string) $row['name'],
                (string) $row['note'],
                $actor_user_id
            );

            $outcome = (string) ($create_result['result'] ?? '');
            if ($outcome === 'created') {
                $imported++;
                $row['result'] = 'imported';
            } elseif ($outcome === 'duplicate') {
                // Race condition (القسم 9): اكتُشف كمكرَّر داخل create() نفسها
                // (أُضيف يدوياً بين Preview وConfirm) — لا إعادة محاولة، لا إضافة مضاعفة.
                $duplicates++;
                $row['result'] = 'duplicate';
            } elseif ($outcome === 'quota_exceeded') {
                // حصة المدعوين ممتلئة فعلياً لحظة هذا الصف بالذات (فحص حي
                // داخل قفل ذري لكل مناسبة، لا سقف مجمَّد من بداية الطلب) —
                // الصفوف اللاحقة تستمر في المحاولة (Best-Effort، مطابق
                // لـBulk Add) وقد تُصنَّف quota_exceeded هي الأخرى.
                $quota_exceeded_count++;
                $row['result'] = 'quota_exceeded';
            } else {
                $failed++;
                $row['result'] = 'failed';
            }
        } catch (\Throwable $e) {
            // القسم 5: Best-effort — فشل صف واحد لا يوقف بقية الصفوف. لا تسريب
            // لتفاصيل الاستثناء الداخلية (لا رسالة، لا Stack Trace) في الاستجابة.
            error_log(sprintf(
                '[pge_excel_import_row_failed] event_id=%d user_id=%d exception_class=%s',
                $event_id,
                $actor_user_id,
                get_class($e)
            ));
            $failed++;
            $row['result'] = 'failed';
        }
    }
    unset($row);

    // إعادة حساب حدود الباقة مرة أخيرة بعد اكتمال الاستيراد فعلياً — Guest
    // Limit Unification RFC، Part I: لا سقف مجمَّد يُحسَب هنا بعد الآن (كل
    // صف قرَّر مصيره بنفسه عبر Service::create() أعلاه)؛ هذا الاستدعاء
    // الأخير غرضه وحيد: عكس current/remaining الحقيقيين في الاستجابة بعد
    // أن أصبح المستوردون الجدد جزءاً من المناسبة، لعرض الواجهة أو زر
    // "ترقية الباقة" مستقبلي.
    $quota_after_import = function_exists('pge_resolve_guest_quota_status')
        ? pge_resolve_guest_quota_status($event_id)
        : ['mode' => 'unlimited', 'limit' => null, 'current' => 0, 'remaining' => null];

    // "total_rows == imported + duplicates + invalid + empty + failed +
    // quota_exceeded" — هوية جامعة حقيقية عبر 6 دِلاء نهائية الآن (أُضيف
    // quota_exceeded لحدود الباقة). valid_before_import حقل وصفي منفصل فقط
    // (عدد الصفوف valid فور فحص التكرار الطازج، قبل أي محاولة create() أو
    // بوابة حدود الباقة)، وليس جزءاً من هذا الجمع، تماماً كما كان.
    $result_summary = [
        'total_rows'          => count($rows),
        'valid_before_import' => $valid_before_import,
        'imported'            => $imported,
        'duplicates'          => $duplicates,
        'invalid'             => $invalid,
        'empty'               => $empty,
        'failed'              => $failed,
        'quota_exceeded'      => $quota_exceeded_count,
    ];

    // القسم 6: حدث تدقيق واحد على مستوى المناسبة دائماً عند اكتمال العملية
    // هيكلياً (حتى لو imported=0) — نفس سياسة bulk_create_completed الحالية
    // لـ Bulk Add تماماً. Payload صارم: {"source":"excel","count":<imported>}
    // فقط — بلا اسم ملف، بلا أرقام هواتف، بلا صفوف، بلا token، بلا مسار.
    if (class_exists('PGE_Invitation_Management_Audit')) {
        PGE_Invitation_Management_Audit::record(
            $event_id,
            PGE_Invitation_Management_Audit::EVENT_LEVEL_BULK_ADD_SENTINEL,
            $actor_user_id,
            'bulk_create_completed',
            wp_json_encode(['source' => 'excel', 'count' => $imported])
        );
    }

    // القسم 7: تنظيف مضمون بعد النجاح — استدعاء صريح مباشر (لا `finally`،
    // راجع تعليق pge_invitation_mgmt_excel_cleanup() أعلاه لسبب ذلك).
    pge_invitation_mgmt_excel_cleanup($token, $stored_file_path);

    wp_send_json_success([
        'summary' => $result_summary,
        'rows'    => array_map('pge_invitation_mgmt_reshape_excel_import_row', $rows),
        // حدود الباقة كما احتُسبت طازجة لحظة هذا التأكيد تحديداً (بعد إعادة
        // الحساب أعلاه) — تفيد الواجهة في صياغة رسالة النتيجة، وتُبقي مكاناً
        // موثَّقاً واضحاً لأي زر "ترقية الباقة" مستقبلي يحتاج معرفة limit/
        // current/remaining الحاليين دون استعلام إضافي. لا تنفيذ لهذا الزر
        // في هذه المرحلة — Hook/placeholder فقط (راجع excelQuotaUpgradeHook
        // في event-invitations.php).
        'quota'   => [
            'mode'      => $quota_after_import['mode'],
            'limit'     => $quota_after_import['limit'],
            'current'   => $quota_after_import['current'],
            'remaining' => $quota_after_import['remaining'],
        ],
    ]);
}
if (PGE_INVITATION_MGMT_EXCEL_IMPORT_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_excel_confirm', 'pge_invitation_mgmt_excel_confirm_handler');
}

// ── الإضافة الجماعية (RC1 Fix Pack 3A — "Invitation Bulk Add Migration") ────
/**
 * رسائل الأخطاء الموحَّدة (Preview وConfirm معاً) — رموز عمل مستقرة
 * (RFC Security: "Return stable business errors. Never expose stack
 * traces.") لكل سبب ممكن من PGE_Invitation_Bulk_Add_Service.
 */
if (!function_exists('pge_invitation_mgmt_bulk_add_error_message')) {
    function pge_invitation_mgmt_bulk_add_error_message(string $reason): string
    {
        $messages = [
            'payload_too_large'            => 'النص الملصوق أكبر من الحد المسموح',
            'too_many_lines'                => 'عدد الأسطر يتجاوز الحد المسموح (500 سطر كحد أقصى للدفعة الواحدة)',
            'malformed_ambiguous_separator' => 'سطر غير مفهوم: استخدم فاصلة أو تبويب للفصل، لا كليهما',
            'unsupported_extra_columns'     => 'سطر يحتوي أعمدة إضافية غير مدعومة',
            'phone_missing'                 => 'رقم الجوال مفقود',
            'phone_too_long'                => 'رقم الجوال أطول من المسموح',
            'name_too_long'                 => 'اسم الضيف أطول من المسموح',
            'name_required'                 => 'اسم الضيف مطلوب لإنشاء الدعوة',
            'duplicate_in_batch'            => 'مكرَّر داخل النص الملصوق نفسه',
            'duplicate_in_event'            => 'هذا الرقم مدعو بالفعل لهذه المناسبة',
            // Guest Limit Unification RFC — Part H: نتيجة حقيقية من
            // PGE_Invitation_Service::create() الآن، لا حالة نظرية.
            'guest_limit_reached'           => 'تم الوصول إلى الحد الأقصى للمدعوين في هذه المناسبة',
        ];
        return $messages[$reason] ?? 'خطأ غير معروف';
    }
}

/**
 * إعادة تشكيل صف للعرض — نفس فلسفة pge_invitation_mgmt_reshape_row() أعلاه
 * (لا تسريب لأي حقل داخلي غير ضروري). يُستخدَم لصفوف preview() وconfirm()
 * معاً — confirm() تضيف مفتاح 'result' فوق ما يُعيده preview() فقط.
 */
if (!function_exists('pge_invitation_mgmt_reshape_bulk_row')) {
    function pge_invitation_mgmt_reshape_bulk_row(array $row): array
    {
        $shaped = [
            'line_number'      => (int) $row['line_number'],
            'guest_name'       => (string) $row['guest_name'],
            'phone'            => (string) $row['phone'],
            'normalized_phone' => (string) $row['normalized_phone'],
            'status'           => (string) $row['status'],
            'error'            => $row['error'] !== null ? pge_invitation_mgmt_bulk_add_error_message((string) $row['error']) : null,
        ];
        if (isset($row['result'])) {
            $shaped['result'] = (string) $row['result'];
        }
        return $shaped;
    }
}

/**
 * ── معاينة (Preview) ─────────────────────────────────────────────────────
 * قراءة فقط بالكامل — لا إنشاء دعوة واحدة هنا (RFC: "No invitation is
 * created during preview.").
 */
function pge_invitation_mgmt_bulk_preview_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();
    $raw_text = isset($_POST['raw_text']) ? sanitize_textarea_field(wp_unslash($_POST['raw_text'])) : '';

    $result = PGE_Invitation_Bulk_Add_Service::preview($event_id, $raw_text);

    if (!$result['ok']) {
        wp_send_json_error([
            'message' => pge_invitation_mgmt_bulk_add_error_message((string) $result['reason']),
            'reason'  => $result['reason'],
        ]);
    }

    wp_send_json_success([
        'rows'    => array_map('pge_invitation_mgmt_reshape_bulk_row', $result['rows']),
        'summary' => $result['summary'],
    ]);
}
if (PGE_INVITATION_MGMT_BULK_ADD_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_bulk_preview', 'pge_invitation_mgmt_bulk_preview_handler');
}

/**
 * ── تأكيد (Confirm) ──────────────────────────────────────────────────────
 * "Never trust the preview result sent by the browser." — يقبل raw_text
 * فقط (لا مصفوفة صفوف من العميل)، يُعيد التحقّق بالكامل من الصفر
 * (PGE_Invitation_Bulk_Add_Service::confirm() يستدعي parse()+
 * check_duplicates() من جديد داخلياً)، ثم يُنشئ كل صف "valid" فعلياً عبر
 * PGE_Invitation_Service::create() الحالية — Best-Effort صفاً صفاً.
 *
 * تدقيق: حدث واحد فقط 'bulk_create_completed' على مستوى المناسبة بعد كل
 * الصفوف (بغضّ النظر عن العدد) — كل دعوة أُنشئت بنجاح سجَّلت أصلاً حدث
 * 'created' الفردي الحالي بنفسه عبر PGE_Invitation_Service::create()، بلا
 * أي تغيير هناك.
 */
function pge_invitation_mgmt_bulk_confirm_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();
    $raw_text = isset($_POST['raw_text']) ? sanitize_textarea_field(wp_unslash($_POST['raw_text'])) : '';
    $actor_user_id = get_current_user_id();

    $result = PGE_Invitation_Bulk_Add_Service::confirm($event_id, $raw_text, $actor_user_id);

    if (!$result['ok']) {
        wp_send_json_error([
            'message' => pge_invitation_mgmt_bulk_add_error_message((string) $result['reason']),
            'reason'  => $result['reason'],
        ]);
    }

    if (class_exists('PGE_Invitation_Management_Audit')) {
        PGE_Invitation_Management_Audit::record(
            $event_id,
            PGE_Invitation_Management_Audit::EVENT_LEVEL_BULK_ADD_SENTINEL,
            $actor_user_id,
            'bulk_create_completed',
            wp_json_encode($result['summary'])
        );
    }

    wp_send_json_success([
        'rows'    => array_map('pge_invitation_mgmt_reshape_bulk_row', $result['rows']),
        'summary' => $result['summary'],
    ]);
}
if (PGE_INVITATION_MGMT_BULK_ADD_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_bulk_confirm', 'pge_invitation_mgmt_bulk_confirm_handler');
}

// ── الحذف الفعلي (RC1 Fix Pack 3B — "Legacy Guest Panel Retirement / Hard Delete Migration") ──
/**
 * "Hard Delete must preserve current business semantics. Do NOT silently
 * convert it into Cancel." — يستدعي PGE_Invitation_Service::delete()
 * مباشرة (لا Cancel، لا مسار موازٍ). "Never trust invitation_id alone.
 * Always validate event_id + ownership": نفس نمط بقية معالجات هذا الملف —
 * pge_invitation_mgmt_validate_request() يتحقّق من الملكية أولاً، ثم الهاتف
 * يُطابَق حصراً ضمن خريطة ضيوف *هذه* المناسبة (event_id) عبر Repository.
 */
function pge_invitation_mgmt_delete_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();
    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

    $result = PGE_Invitation_Service::delete($event_id, $phone, get_current_user_id());
    $outcome = (string) ($result['result'] ?? '');

    if ($outcome === 'deleted') {
        wp_send_json_success(['message' => 'تم حذف الدعوة نهائياً']);
    }
    if ($outcome === 'error' && ($result['reason'] ?? '') === 'not_found') {
        wp_send_json_error(['message' => 'الدعوة غير موجودة ضمن هذه المناسبة', 'reason' => 'not_found']);
    }

    wp_send_json_error(['message' => 'تعذّر حذف الدعوة', 'reason' => (string) ($result['reason'] ?? 'unknown_error')]);
}
if (PGE_INVITATION_MGMT_DELETE_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_delete', 'pge_invitation_mgmt_delete_handler');
}

/**
 * ── الحذف الجماعي (Bulk Delete) ──────────────────────────────────────────
 * "If the legacy panel currently supports Bulk Delete, migrate it as well.
 * Reuse the same deletion path repeatedly. Do not create a second delete
 * algorithm." — حلقة صريحة تستدعي PGE_Invitation_Service::delete() مرة لكل
 * هاتف (نفس الدالة المفردة أعلاه بالضبط، ونفس الدالة التي يستدعيها معالج
 * wp_ajax_pge_event_guest_bulk_delete القديم في event-guests.php الآن أيضاً
 * — نقطة تنفيذ حذف واحدة فقط في كامل المشروع). Best-Effort صفاً صفاً؛ فشل
 * هاتف واحد (غير موجود أصلاً) لا يوقف بقية الدفعة — نفس فلسفة Best-Effort
 * المُعتمَدة في Bulk Add (RC1 Fix Pack 3A) دون أي تكرار للكود.
 *
 * تدقيق: "One row per deleted invitation. No event-level batch audit
 * required." — كل استدعاء ناجح لـdelete() يُسجِّل حدث 'deleted' فردياً
 * بنفسه عبر الخدمة؛ لا حدث دفعي إضافي يُسجَّل هنا (خلافاً لـ'bulk_create_
 * completed' في Bulk Add — RFC يمنع ذلك صراحة لهذه العملية تحديداً).
 */
function pge_invitation_mgmt_bulk_delete_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    $phones_raw = $_POST['phones'] ?? [];
    $candidates = is_array($phones_raw) ? $phones_raw : preg_split('/[\s,]+/', (string) wp_unslash($phones_raw));

    $phones = [];
    foreach ((array) $candidates as $candidate) {
        $phone = sanitize_text_field(wp_unslash(is_scalar($candidate) ? $candidate : ''));
        $normalized = function_exists('pge_event_guests_norm_phone') ? pge_event_guests_norm_phone($phone) : $phone;
        if ($normalized !== '') $phones[$normalized] = $normalized;
    }
    $phones = array_values($phones);

    if (empty($phones)) {
        wp_send_json_error(['message' => 'اختر دعوة واحدة على الأقل للحذف', 'reason' => 'no_phones']);
    }

    $actor_user_id = get_current_user_id();
    $deleted = 0;
    $not_found = 0;
    foreach ($phones as $phone) {
        $result = PGE_Invitation_Service::delete($event_id, $phone, $actor_user_id);
        if (($result['result'] ?? '') === 'deleted') {
            $deleted++;
        } else {
            $not_found++;
        }
    }

    wp_send_json_success([
        'message'   => sprintf('تم حذف %d دعوة نهائياً.', $deleted),
        'deleted'   => $deleted,
        'not_found' => $not_found,
        'total'     => count($phones),
    ]);
}
if (PGE_INVITATION_MGMT_DELETE_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_bulk_delete', 'pge_invitation_mgmt_bulk_delete_handler');
}

// ══════════════════════════════════════════════════════════════════════════
// Messaging Architecture Phase 3 ("Manual Reminder") — Preview/Template
// Save/Send/Status. نفس تفويض بقية هذا الملف بالضبط
// (pge_invitation_mgmt_validate_request(): nonce + login + ملكية المناسبة
// عبر pge_event_guests_user_can_manage() — لا يمنح المشرف (Supervisor) أي
// صلاحية هنا، جلسته منفصلة تماماً عن is_user_logged_in()/current WP user
// ولا تُمرَّر عبر nonce إدارة المناسبة أصلاً — PART 18 من تكليف Phase 3).
// لا Feature Gate على reminder_message هنا ولا في أي مكان (PART 19).
// ══════════════════════════════════════════════════════════════════════════

/**
 * معاينة قبل الإرسال (PART 21/22): عدد المستلمين المتوقَّع (Estimate — العدد
 * الملزم الفعلي يُعاد حسابه من جديد عند send فقط) + نص القالب الحالي
 * (المحفوظ أو الافتراضي) + عيّنة تصيير بسيطة بضيف وهمي. قراءة فقط بالكامل —
 * لا batch_id، لا صفوف تتبّع، لا إرسال.
 */
function pge_invitation_mgmt_reminder_preview_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    $filter = isset($_POST['filter']) ? sanitize_text_field(wp_unslash($_POST['filter'])) : PGE_Message_Recipient_Resolver::FILTER_PENDING;
    $filter = PGE_Message_Recipient_Resolver::normalize_filter($filter);

    $recipient_count = PGE_Message_Recipient_Resolver::count($event_id, PGE_Message_Type::REMINDER, $filter);

    $templates = function_exists('pge_wa_get_templates') ? pge_wa_get_templates($event_id) : [];
    $template_text = (string) ($templates['reminder'] ?? '');

    $event = get_post($event_id);
    $event_name = $event ? $event->post_title : '';
    $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
    $event_date = $event_date_raw
        ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
        : '';

    $sample = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::REMINDER, $event_id, [
        'guest_name' => 'ضيف تجريبي',
        'event_name' => $event_name,
        'event_date' => $event_date,
    ]);
    $preview_image_url = PGE_Reminder_Message_Service::resolve_event_featured_image_url($event_id);

    wp_send_json_success([
        'filter'           => $filter,
        'recipient_count'  => $recipient_count,
        'template'         => $template_text,
        'preview_text'     => $sample['text'],
        'image_available'   => $preview_image_url !== null,
        'preview_image_url' => $preview_image_url,
    ]);
}
if (PGE_INVITATION_MGMT_REMINDER_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_reminder_preview', 'pge_invitation_mgmt_reminder_preview_handler');
}

/**
 * حفظ قالب Reminder لهذه المناسبة فقط (PART 17): لا Endpoint عام لحفظ كل
 * القوالب موجود حالياً في المشروع لإعادة استخدامه (تحقّقنا فعلياً قبل
 * الكتابة — راجع Preflight Audit في التقرير النهائي)، فأضفنا أقل طريقة ممكنة
 * مقصورة على مفتاح _pge_wa_tpl_reminder وحده — لا تلمس invite/yes/no/invalid/
 * thank_you. نفس نمط sanitize_textarea_field المُستخدَم لحقول نصية طويلة في
 * هذا المشروع (note الضيف مثلاً).
 */
function pge_invitation_mgmt_save_reminder_template_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    $template = isset($_POST['template']) ? sanitize_textarea_field(wp_unslash($_POST['template'])) : '';
    if ($template === '') {
        wp_send_json_error(['message' => 'نص القالب لا يمكن أن يكون فارغاً', 'reason' => 'empty_template']);
    }
    if (mb_strlen($template) > 2000) {
        wp_send_json_error(['message' => 'نص القالب طويل جداً', 'reason' => 'template_too_long']);
    }

    update_post_meta($event_id, '_pge_wa_tpl_reminder', $template);

    wp_send_json_success(['message' => 'تم حفظ نص التذكير', 'template' => $template]);
}
if (PGE_INVITATION_MGMT_REMINDER_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_save_reminder_template', 'pge_invitation_mgmt_save_reminder_template_handler');
}

/**
 * إرسال التذكير الفعلي (PART 16): الخادم هو Authoritative بالكامل — لا يقبل
 * phones/rendered rows/message body النهائي/batch_id/counts من العميل. كل ما
 * يُقبَل من $_POST هنا: nonce (عبر pge_invitation_mgmt_validate_request())،
 * event_id (نفسه)، وfilter وinclude_image كنية 0/1 فقط. batch_id يُولَّد داخل
 * PGE_Reminder_Message_
 * Service::send_reminder_batch() نفسها، والمستلمون يُحسَبون هناك أيضاً — لا
 * تكرار منطق هنا، هذا المعالج غلاف رقيق حول الـService فقط (PART 9: "لا تضع
 * هذا المنطق كله داخل AJAX handler").
 */
function pge_invitation_mgmt_send_reminder_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    $filter = isset($_POST['filter']) ? sanitize_text_field(wp_unslash($_POST['filter'])) : PGE_Message_Recipient_Resolver::FILTER_PENDING;
    $filter = PGE_Message_Recipient_Resolver::normalize_filter($filter);
    // Intent فقط؛ أي image_url/media_url/file_path من العميل يُتجاهَل تماماً.
    $include_image = isset($_POST['include_image']) && (string) wp_unslash($_POST['include_image']) === '1';

    $result = PGE_Reminder_Message_Service::send_reminder_batch($event_id, $filter, get_current_user_id(), $include_image);
    $outcome = (string) ($result['result'] ?? 'error');

    if ($outcome === 'started') {
        wp_send_json_success([
            'batch_id'              => $result['batch_id'],
            'total_targeted'        => $result['total_targeted'],
            'skipped_invalid_phone' => $result['skipped_invalid_phone'],
            'sent'                  => $result['sent'],
            'failed'                => $result['failed'],
            'ambiguous'             => $result['ambiguous'],
            'queued_remaining'      => $result['queued_remaining'],
            'in_progress'           => $result['queued_remaining'] > 0,
        ]);
    }

    $reason = (string) ($result['reason'] ?? 'unknown_error');
    $messages = [
        'invalid_event'            => 'مناسبة غير صالحة',
        'no_provider_credentials'  => 'لم يتم ضبط Cartat API Token في الإعدادات',
        'operation_in_progress'    => '⏳ توجد عملية إرسال تذكير أخرى قيد البدء لهذه المناسبة الآن. حاول بعد لحظات.',
        'no_recipients'            => 'لا يوجد مستلمون مطابقون لهذا الفلتر حالياً',
        'tracking_creation_failed' => 'تعذّر تجهيز عملية الإرسال، حاول مرة أخرى',
    ];

    wp_send_json_error(['message' => $messages[$reason] ?? 'تعذّر بدء إرسال التذكير', 'reason' => $reason]);
}
if (PGE_INVITATION_MGMT_REMINDER_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_send_reminder', 'pge_invitation_mgmt_send_reminder_handler');
}

/**
 * تقرير حالة دفعة تذكير جارية (PART 24) — تُستدعى دورياً من الواجهة فقط حين
 * queued_remaining > 0 عند send، بنفس فلسفة ajax_queue_status() الحالية —
 * قراءة فقط عبر PGE_Reminder_Message_Service::batch_status() (لا حالة Queue
 * مشتركة مع مسار الدعوة).
 */
function pge_invitation_mgmt_reminder_status_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    $batch_id = isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '';
    if ($batch_id === '') {
        wp_send_json_error(['message' => 'batch_id مفقود', 'reason' => 'missing_batch_id']);
    }

    $status = PGE_Reminder_Message_Service::batch_status($event_id, $batch_id);

    wp_send_json_success([
        'total'      => $status['total'],
        'sent'       => $status['sent'],
        'failed'     => $status['failed'],
        'ambiguous'  => $status['ambiguous'],
        'pending'    => $status['pending'],
        'done'       => $status['pending'] === 0,
    ]);
}
if (PGE_INVITATION_MGMT_REMINDER_ENABLED) {
    add_action('wp_ajax_pge_invitation_mgmt_reminder_status', 'pge_invitation_mgmt_reminder_status_handler');
}

// ============================================================================
// Messaging Architecture Phase 4B-3A — Manual Thank You AJAX only.
// These handlers are thin, server-authoritative adapters. No UI, template save,
// recipient input, synchronous Claim/Send, retry, or polling side effect.
// ============================================================================

/**
 * Advisory, read-only delivery classification for the Thank You Preview UX.
 * Eligibility remains owned by the Recipient Resolver; Claim remains the
 * atomic once-only authority when the worker executes a recipient.
 *
 * @param array<int,array<string,mixed>> $recipients
 * @return array{ready_to_send:int,already_sent:int,in_progress:int,active_batch:bool}
 */
function pge_invitation_mgmt_classify_thank_you_preview(int $event_id, array $recipients): array
{
    $active_batch = false;
    $active_items = [];
    $active_batch_id = PGE_Thank_You_Batch_Store::get_active_batch_id($event_id);

    if ($active_batch_id !== '') {
        $manifest = PGE_Thank_You_Batch_Store::get($active_batch_id);
        if (is_array($manifest)
            && (int) ($manifest['event_id'] ?? 0) === $event_id
            && (string) ($manifest['status'] ?? '') === PGE_Thank_You_Batch_Store::STATUS_ACTIVE) {
            $active_batch = true;
            foreach ((array) ($manifest['items'] ?? []) as $item) {
                $status = (string) ($item['status'] ?? '');
                if (!in_array($status, [
                    PGE_Thank_You_Batch_Store::ITEM_QUEUED,
                    PGE_Thank_You_Batch_Store::ITEM_PROCESSING,
                    PGE_Thank_You_Batch_Store::ITEM_WAITING,
                ], true)) {
                    continue;
                }

                $rsvp_id = (int) ($item['rsvp_id'] ?? 0);
                $lifecycle = is_scalar($item['lifecycle_started_at'] ?? null)
                    ? trim((string) $item['lifecycle_started_at'])
                    : '';
                if ($rsvp_id > 0 && $lifecycle !== '') {
                    $active_items[$rsvp_id . '|' . $lifecycle] = true;
                }
            }
        }
    }

    $counts = [
        'ready_to_send' => 0,
        'already_sent' => 0,
        'in_progress' => 0,
        'active_batch' => $active_batch,
    ];

    foreach ($recipients as $recipient) {
        $rsvp_id = (int) ($recipient['rsvp_id'] ?? 0);
        $lifecycle = is_scalar($recipient['lifecycle_started_at'] ?? null)
            ? trim((string) $recipient['lifecycle_started_at'])
            : '';
        if ($rsvp_id <= 0 || $lifecycle === '') {
            continue;
        }

        if (PGE_Thank_You_Claim::is_sent($rsvp_id)) {
            $counts['already_sent']++;
            continue;
        }

        if (isset($active_items[$rsvp_id . '|' . $lifecycle])
            || !PGE_Thank_You_Claim::can_send($rsvp_id)) {
            $counts['in_progress']++;
            continue;
        }

        $counts['ready_to_send']++;
    }

    return $counts;
}

/** Preview current Thank You eligibility and a PII-free sample. */
function pge_invitation_mgmt_thank_you_preview_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    try {
        $resolved = PGE_Message_Recipient_Resolver::resolve(
            $event_id,
            PGE_Message_Type::THANK_YOU,
            'checked_in'
        );

        $event = get_post($event_id);
        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        $preview = PGE_Message_Content_Resolver::resolve(
            PGE_Message_Type::THANK_YOU,
            $event_id,
            [
                'guest_name' => 'ضيفنا الكريم',
                'event_name' => $event ? (string) $event->post_title : '',
                'event_date' => $event_date_raw !== ''
                    ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
                    : '',
            ]
        );
        $classification = pge_invitation_mgmt_classify_thank_you_preview(
            $event_id,
            is_array($resolved['recipients'] ?? null) ? $resolved['recipients'] : []
        );
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => 'تعذّرت معاينة رسالة الشكر',
            'reason' => 'internal_error',
        ]);
    }

    wp_send_json_success([
        'eligible'                  => (int) ($resolved['eligible'] ?? 0),
        'ready_to_send'             => (int) ($classification['ready_to_send'] ?? 0),
        'already_sent'              => (int) ($classification['already_sent'] ?? 0),
        'in_progress'               => (int) ($classification['in_progress'] ?? 0),
        'active_batch'              => !empty($classification['active_batch']),
        'total_current_invitations' => (int) ($resolved['total_current_invitations'] ?? 0),
        'skipped_invalid_phone'     => (int) ($resolved['skipped_invalid_phone'] ?? 0),
        'skipped_cancelled'         => (int) ($resolved['skipped_cancelled'] ?? 0),
        'skipped_not_checked_in'    => (int) ($resolved['skipped_not_checked_in'] ?? 0),
        'skipped_no_rsvp'           => (int) ($resolved['skipped_no_rsvp'] ?? 0),
        'skipped_integrity_error'   => (int) ($resolved['skipped_integrity_error'] ?? 0),
        'skipped_stale_lifecycle'   => (int) ($resolved['skipped_stale_lifecycle'] ?? 0),
        'preview_text'              => (string) ($preview['text'] ?? ''),
    ]);
}

/** Start an async-only durable batch, or return the current active batch. */
function pge_invitation_mgmt_thank_you_start_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();

    try {
        $result = PGE_Thank_You_Batch_Worker::create_batch($event_id, get_current_user_id());
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => 'تعذّر بدء إرسال رسائل الشكر',
            'reason' => 'internal_error',
        ]);
    }

    $outcome = (string) ($result['result'] ?? 'error');
    if ($outcome === 'started' || $outcome === 'active_batch_exists') {
        wp_send_json_success([
            'batch_id' => (string) ($result['batch_id'] ?? ''),
            'status' => is_array($result['status'] ?? null) ? $result['status'] : [],
            'existing' => $outcome === 'active_batch_exists',
        ]);
    }

    $reason = (string) ($result['reason'] ?? 'internal_error');
    if ($outcome === 'no_eligible') {
        $reason = 'no_eligible';
    }
    if ($outcome === 'busy' || $reason === 'operation_locked') {
        $reason = 'batch_in_progress';
    }
    $messages = [
        'invalid_event' => 'مناسبة غير صالحة',
        'no_eligible' => 'لا يوجد حاضرون مؤهلون لإرسال رسالة الشكر حالياً',
        'batch_in_progress' => 'توجد عملية بدء دفعة شكر أخرى قيد التنفيذ',
        'no_provider_credentials' => 'لم يتم ضبط Cartat API Token في الإعدادات',
        'batch_persistence_failed' => 'تعذّر تجهيز دفعة رسائل الشكر',
        'batch_id_generation_failed' => 'تعذّر تجهيز دفعة رسائل الشكر',
        'internal_error' => 'تعذّر بدء إرسال رسائل الشكر',
    ];
    if (!array_key_exists($reason, $messages)) {
        $reason = 'internal_error';
    }

    wp_send_json_error(['message' => $messages[$reason], 'reason' => $reason]);
}

/** Read a PII-free batch summary. This endpoint has no recovery side effects. */
function pge_invitation_mgmt_thank_you_status_handler()
{
    $event_id = pge_invitation_mgmt_validate_request();
    $batch_id = isset($_POST['batch_id'])
        ? sanitize_text_field(wp_unslash($_POST['batch_id']))
        : '';
    if ($batch_id === '') {
        wp_send_json_error(['message' => 'batch_id مفقود', 'reason' => 'missing_batch_id']);
    }

    try {
        $manifest = PGE_Thank_You_Batch_Store::get($batch_id);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => 'تعذّر قراءة حالة دفعة رسائل الشكر',
            'reason' => 'internal_error',
        ]);
    }
    if (!is_array($manifest)) {
        wp_send_json_error(['message' => 'دفعة رسائل الشكر غير موجودة', 'reason' => 'batch_not_found']);
    }
    if ((int) ($manifest['event_id'] ?? 0) !== $event_id) {
        wp_send_json_error(['message' => 'الدفعة لا تنتمي إلى هذه المناسبة', 'reason' => 'batch_event_mismatch']);
    }

    try {
        $status = PGE_Thank_You_Batch_Worker::get_status($event_id, $batch_id);
    } catch (\Throwable $e) {
        wp_send_json_error([
            'message' => 'تعذّر قراءة حالة دفعة رسائل الشكر',
            'reason' => 'internal_error',
        ]);
    }
    if (!is_array($status)) {
        wp_send_json_error(['message' => 'دفعة رسائل الشكر غير موجودة', 'reason' => 'batch_not_found']);
    }

    wp_send_json_success([
        'batch_id'        => (string) ($status['batch_id'] ?? ''),
        'total'           => (int) ($status['total'] ?? 0),
        'queued'          => (int) ($status['queued'] ?? 0),
        'processing'      => (int) ($status['processing'] ?? 0),
        'waiting'         => (int) ($status['waiting'] ?? 0),
        'sent'            => (int) ($status['sent'] ?? 0),
        'failed'          => (int) ($status['failed'] ?? 0),
        'ambiguous'       => (int) ($status['ambiguous'] ?? 0),
        'skipped'         => (int) ($status['skipped'] ?? 0),
        'skipped_reasons' => is_array($status['skipped_reasons'] ?? null)
            ? $status['skipped_reasons']
            : [],
        'complete'        => !empty($status['complete']),
        'started_at'      => (string) ($status['started_at'] ?? ''),
        'updated_at'      => (string) ($status['updated_at'] ?? ''),
    ]);
}

if (PGE_INVITATION_MGMT_THANK_YOU_ENABLED) {
    add_action(
        'wp_ajax_pge_invitation_mgmt_thank_you_preview',
        'pge_invitation_mgmt_thank_you_preview_handler'
    );
    add_action(
        'wp_ajax_pge_invitation_mgmt_thank_you_start',
        'pge_invitation_mgmt_thank_you_start_handler'
    );
    add_action(
        'wp_ajax_pge_invitation_mgmt_thank_you_status',
        'pge_invitation_mgmt_thank_you_status_handler'
    );
}
