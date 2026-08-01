<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Checkin AJAX Endpoints — Guest Check-in Engine، Phase 4
 * ============================================================================
 * "Guest Check-in Engine" RFC — "Everything must execute through the
 * authenticated Supervisor Portal." وRequirement 8: "Authorization — Every
 * operation must require: Authenticated Supervisor Session, Active
 * Assignment, Matching Event. Never trust request parameters."
 *
 * كل معالج هنا يستدعي PGE_Supervisor_Portal_Middleware::authorize() **أولاً**
 * وقبل أي قراءة لـ$_POST متعلقة بهوية/صلاحية — event_id وassignment_id
 * المُستخدَمان بعد ذلك في كل هذا الملف مصدرهما **حصراً** نتيجة authorize()
 * الموثوقة، لا $_POST['event_id'] أو أي حقل مخفي إطلاقاً (لا وجود لأي حقل
 * event_id في أي طلب هنا أصلاً).
 *
 * مُسجَّلة على كل من wp_ajax_ وwp_ajax_nopriv_ معاً (بنفس نمط pge_rsvp_submit
 * الحالي) — المشرف لا يملك بالضرورة حساب ووردبريس (Phase 3)، لكن حالة نادرة
 * ممكنة نظرياً (مضيف مسجَّل دخول ووردبريس وهو أيضاً مشرف نشط على مناسبة أخرى)
 * يجب ألا تُحرَم من نفس المسار.
 *
 * لا منطق أعمال هنا إطلاقاً — كل معالج رقيق: nonce → authorize() → تفويض
 * الاستدعاء لخدمة الأعمال الحقيقية (QR/Resolution/Recorder) → wp_send_json_*.
 */

if (!function_exists('pge_supervisor_checkin_require_middleware')) {
    /**
     * تحقّق مشترك لكل معالجات هذا الملف: nonce ثم authorize(). تُنهي الطلب
     * فوراً (wp_send_json_error + كود HTTP الصريح من Middleware) عند الرفض.
     *
     * @return array{assignment_id:int,event_id:int,session_id:int} عند النجاح فقط.
     */
    function pge_supervisor_checkin_require_middleware(): array
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pge_supervisor_checkin_nonce')) {
            wp_send_json_error(['message' => 'رمز الأمان غير صالح', 'reason' => 'invalid_nonce'], 401);
        }

        if (!class_exists('PGE_Supervisor_Portal_Middleware')) {
            wp_send_json_error(['message' => 'تعذّر التحقق من الجلسة', 'reason' => 'middleware_unavailable'], 401);
        }

        $authorization = PGE_Supervisor_Portal_Middleware::authorize();
        if (($authorization['result'] ?? '') !== 'authorized') {
            wp_send_json_error(
                ['message' => 'غير مصرَّح', 'reason' => (string) ($authorization['reason'] ?? 'unauthorized')],
                (int) ($authorization['http_status'] ?? 401)
            );
        }

        return $authorization;
    }
}

/**
 * pge_supervisor_checkin_scan — Requirement 1/2: تحقّق QR ثم حلّ الضيف.
 * POST: nonce, qr_payload
 */
add_action('wp_ajax_pge_supervisor_checkin_scan', 'pge_supervisor_checkin_scan_handler');
add_action('wp_ajax_nopriv_pge_supervisor_checkin_scan', 'pge_supervisor_checkin_scan_handler');
function pge_supervisor_checkin_scan_handler()
{
    $authorization = pge_supervisor_checkin_require_middleware();
    $event_id = (int) $authorization['event_id'];

    $qr_payload = isset($_POST['qr_payload']) ? (string) wp_unslash($_POST['qr_payload']) : '';
    if ($qr_payload === '') {
        wp_send_json_error(['message' => 'لا توجد بيانات QR', 'reason' => 'empty_payload']);
    }

    if (!class_exists('PGE_Guest_Resolution_Service')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'resolution_service_unavailable']);
    }

    $resolution = PGE_Guest_Resolution_Service::resolve_from_qr($event_id, $qr_payload);
    if (($resolution['result'] ?? '') !== 'found') {
        wp_send_json_error(['message' => 'رمز QR غير صالح لهذه المناسبة', 'reason' => (string) ($resolution['reason'] ?? 'invalid_qr')]);
    }

    wp_send_json_success(['guest' => $resolution['guest']]);
}

/**
 * pge_supervisor_checkin_search — Requirement 7: بحث يدوي (رمز/اسم/جوال).
 * POST: nonce, query
 */
add_action('wp_ajax_pge_supervisor_checkin_search', 'pge_supervisor_checkin_search_handler');
add_action('wp_ajax_nopriv_pge_supervisor_checkin_search', 'pge_supervisor_checkin_search_handler');
function pge_supervisor_checkin_search_handler()
{
    $authorization = pge_supervisor_checkin_require_middleware();
    $event_id = (int) $authorization['event_id'];

    $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
    if ($query === '') {
        wp_send_json_error(['message' => 'أدخل نصاً للبحث', 'reason' => 'empty_query']);
    }

    if (!class_exists('PGE_Guest_Resolution_Service')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'resolution_service_unavailable']);
    }

    $result = PGE_Guest_Resolution_Service::search($event_id, $query);

    wp_send_json_success(['guests' => $result['guests'] ?? []]);
}

/**
 * pge_supervisor_checkin_confirm — Requirement 3/4/5/6: تسجيل الحضور الفعلي.
 * POST: nonce, identifier_type ('qr'|'rsvp_id'|'phone'), identifier_value,
 *       attendance_origin ('qr'|'manual')، actual_count
 *
 * ============================================================================
 * Phase 7 Final Fix ("Audit Method Integrity") — فصل تام بين مفهومين مختلفين
 * ============================================================================
 * "Invitation Resolution" (identifier_type/identifier_value — "ما هي الدعوة؟")
 * منفصل تماماً عن "Attendance Origin" (attendance_origin — "كيف نفَّذ المشرف
 * التسجيل فعلياً؟"). لا يُشتَق أحدهما من الآخر إطلاقاً بعد الآن. مسار البحث
 * اليدوي يُعيد استخدام نفس آلية الحلّ المُوقَّعة (identifier_type='qr' مع
 * مرجع build_payload()) تماماً كما يفعل مسار الكاميرا — لكن أصل العملية
 * الحقيقي (qr مقابل manual) يصل الآن صراحةً من الواجهة عبر attendance_origin،
 * لا يُخمَّن من شكل identifier_type/الحمولة/المرجع. القيمة تُتحقَّق صراحة ضد
 * PGE_Checkin_Recorder::VALID_METHODS نفسها (لا قائمة موازية جديدة) — أي قيمة
 * غير معروفة تُرفَض فوراً، بلا أي قيمة افتراضية صامتة.
 *
 * Phase 9A Final Fix ("Enforce Cancellation in the Real Check-in Path"):
 * الحقول الثلاثة (resolve_from_qr/resolve_by_rsvp_id/resolve_by_phone عبر
 * PGE_Guest_Resolution_Service) قد تُعيد الآن result='cancelled' (reason:
 * invitation_cancelled) لدعوة مُلغاة إدارياً — تُرفَض هنا صراحةً **قبل** أي
 * استدعاء لـPGE_Checkin_Recorder، بنفس نمط معالجة 'ambiguous' أدناه تماماً.
 * لا تعديل على Recorder نفسه إطلاقاً — الرفض يحدث في طبقة الحلّ فقط، فلا
 * تُكتَب أي أعمدة حضور ولا أي سطر تدقيق حضور لدعوة مُلغاة.
 *
 * تصحيح معماري #2 (Blocking Issue، لا يزال سارياً بلا تغيير): identifier_type='phone'
 * يستدعي حصراً PGE_Guest_Resolution_Service::resolve_by_phone() — **قراءة
 * فقط**، لا تُنشئ أي صف RSVP إطلاقاً ("Check-in must never create invitation
 * or RSVP records"). قد تُعيد نتيجة 'ambiguous' إن تطابق أكثر من صف RSVP واحد
 * مع نفس الهاتف ضمن المناسبة — في هذه الحالة تُعاد قائمة مرشَّحين آمنة للعرض
 * (بلا أي rsvp_id خام) دون تسجيل أي حضور؛ اختيار المشرف لاحقاً لأحد المرشَّحين
 * يُرسَل كـidentifier_type='qr' بنفس مرجعه المُوقَّع (إعادة استخدام مسار الحلّ
 * الموجود فعلاً، بلا أي منطق حلّ جديد) مع attendance_origin='manual' صراحةً.
 */
add_action('wp_ajax_pge_supervisor_checkin_confirm', 'pge_supervisor_checkin_confirm_handler');
add_action('wp_ajax_nopriv_pge_supervisor_checkin_confirm', 'pge_supervisor_checkin_confirm_handler');
function pge_supervisor_checkin_confirm_handler()
{
    $authorization = pge_supervisor_checkin_require_middleware();
    $event_id = (int) $authorization['event_id'];
    $assignment_id = (int) $authorization['assignment_id'];

    $identifier_type = isset($_POST['identifier_type']) ? sanitize_text_field(wp_unslash($_POST['identifier_type'])) : '';
    $identifier_value = isset($_POST['identifier_value']) ? (string) wp_unslash($_POST['identifier_value']) : '';
    $actual_count = isset($_POST['actual_count']) ? (int) $_POST['actual_count'] : 0;

    if (!in_array($identifier_type, ['qr', 'rsvp_id', 'phone'], true) || $identifier_value === '') {
        wp_send_json_error(['message' => 'بيانات غير مكتملة', 'reason' => 'invalid_identifier']);
    }

    if (!class_exists('PGE_Guest_Resolution_Service') || !class_exists('PGE_Checkin_Recorder')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'services_unavailable']);
    }

    // Phase 7 Final Fix: أصل التسجيل الحقيقي يصل صراحةً من الطلب — لا يُشتَق
    // من identifier_type ولا من شكل identifier_value/الحمولة/المرجع إطلاقاً.
    // يُتحقَّق ضد نفس قائمة Recorder المُعتمَدة (PGE_Checkin_Recorder::
    // VALID_METHODS) — مصدر وحيد للقيم المسموحة، لا قائمة موازية. أي قيمة
    // غير معروفة أو مفقودة تُرفَض فوراً، بلا أي افتراض صامت.
    $attendance_origin = isset($_POST['attendance_origin']) ? sanitize_text_field(wp_unslash($_POST['attendance_origin'])) : '';
    if (!in_array($attendance_origin, PGE_Checkin_Recorder::VALID_METHODS, true)) {
        wp_send_json_error(['message' => 'قيمة أصل تسجيل الحضور غير صالحة', 'reason' => 'invalid_attendance_origin']);
    }
    $method = $attendance_origin;

    if ($identifier_type === 'qr') {
        $resolution = PGE_Guest_Resolution_Service::resolve_from_qr($event_id, $identifier_value);
    } elseif ($identifier_type === 'phone') {
        $resolution = PGE_Guest_Resolution_Service::resolve_by_phone($event_id, $identifier_value);
    } else {
        $rsvp_id = (int) $identifier_value;
        $resolution = PGE_Guest_Resolution_Service::resolve_by_rsvp_id($event_id, $rsvp_id);
    }

    // Blocking Issue #2: أكثر من صف RSVP يطابق نفس الهاتف — لا اختيار صامت
    // لأيٍّ منها. تُعاد قائمة مرشَّحين آمنة (بلا rsvp_id خام) بلا أي تسجيل
    // حضور؛ اختيار المشرف لاحقاً يُرسَل كـidentifier_type='qr' بنفس المرجع
    // المُوقَّع لأحد هؤلاء المرشَّحين (راجع resolve_by_phone()).
    if (($resolution['result'] ?? '') === 'ambiguous') {
        wp_send_json_error([
            'message' => 'يوجد أكثر من دعوة بنفس رقم الجوال. الرجاء اختيار الدعوة الصحيحة.',
            'reason' => 'ambiguous_phone_match',
            'candidates' => $resolution['candidates'] ?? [],
        ]);
    }

    // Phase 9A Final Fix ("Enforce Cancellation in the Real Check-in Path") —
    // القاعدة السلطوية على مستوى الخادم: دعوة مُلغاة لا تُسجَّل حضورها أبداً.
    // يُرفَض هنا صراحةً **قبل** أي وصول لـ$guest/PGE_Checkin_Recorder — لا
    // كتابة حضور، لا سطر تدقيق حضور. خطأ عمل ثابت: invitation_cancelled.
    if (($resolution['result'] ?? '') === 'cancelled') {
        wp_send_json_error([
            'message' => 'هذه الدعوة مُلغاة، لا يمكن تسجيل الحضور',
            'reason' => 'invitation_cancelled',
        ]);
    }

    if (($resolution['result'] ?? '') !== 'found') {
        wp_send_json_error(['message' => 'تعذّر العثور على الضيف', 'reason' => (string) ($resolution['reason'] ?? 'not_found')]);
    }

    $guest = $resolution['guest'];

    // تحقّق دفاعي إضافي: الضيف المُحلَّل ينتمي فعلاً لنفس المناسبة الموثوقة
    // (Requirement 3: "Guest must belong to event") — لا يجب أن يختلفا أبداً
    // بعد resolve_*() أعلاه، لكن نتحقق صراحة بدل الوثوق الأعمى.
    if ((int) ($guest['event_id'] ?? 0) !== $event_id) {
        wp_send_json_error(['message' => 'الضيف لا ينتمي لهذه المناسبة', 'reason' => 'event_mismatch']);
    }

    $result = PGE_Checkin_Recorder::record_guest_checkin(
        $assignment_id,
        $guest,
        $actual_count,
        $method
    );

    if (($result['result'] ?? '') === 'already_checked_in') {
        wp_send_json_success(['already' => true, 'guest' => $result['guest']]);
    }

    if (($result['result'] ?? '') !== 'checked_in') {
        wp_send_json_error([
            'message' => 'تعذّر تسجيل الحضور',
            'reason' => (string) ($result['reason'] ?? 'unknown_error'),
            'expected_count' => $result['expected_count'] ?? null,
        ]);
    }

    wp_send_json_success([
        'guest' => $result['guest'],
        'expected_count' => $result['expected_count'],
        'actual_count' => $result['actual_count'],
    ]);
}
