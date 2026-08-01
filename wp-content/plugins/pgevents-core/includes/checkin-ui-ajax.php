<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Checkin UI Search AJAX — Entry Check-in Supervisors، Phase 7
 * ============================================================================
 * "Supervisor Check-in User Interface" RFC — "The UI must consume existing
 * services only. No attendance business logic may be duplicated." وقسم
 * الأمان: "Never expose: Raw RSVP IDs, Internal database keys, Authorization
 * state."
 *
 * ============================================================================
 * سبب وجود ملف جديد (لماذا لا نستهلك pge_supervisor_checkin_search مباشرة؟)
 * ============================================================================
 * includes/checkin-ajax.php (Phase 4، غير مُعدَّل هنا إطلاقاً) يوفّر بالفعل
 * pge_supervisor_checkin_search الذي يستدعي PGE_Guest_Resolution_Service::
 * search() ويُعيد نتائجها كما هي — لكن Guest Object الموحَّد من تلك الخدمة
 * يحمل حصراً rsvp_id خاماً ورقم هاتف كاملاً (عقد Phase 4 الأصلي، موثَّق في
 * ملفه الخاص أنه "خارج نطاق" أي تصحيح لاحق). Phase 7 يشترط صراحة عدم كشف
 * rsvp_id خام لواجهة البحث اليدوي متعدد النتائج. بدل تعديل الخدمة الحقيقية
 * أو نقطة AJAX الأصلية (كلاهما ممنوع التعديل — "Do NOT modify the Check-in
 * Engine")، هذا الملف **جديد بالكامل**: طبقة عرض/إعادة تشكيل رقيقة فقط، تستدعي
 * نفس الخدمات الحقيقية القراءة فقط (PGE_Guest_Resolution_Service::
 * search()/resolve_by_phone()/build_scanner_qr_payload() — Phase 9B QR
 * Architecture Final Fix: المولِّد الكنسي الوحيد لمرجع QR، لا PGE_Checkin_
 * QR_Service::build_payload() مباشرة) ثم تُسقِط rsvp_id/الهاتف الخام من الاستجابة،
 * مستبدلة إياهما بـ`reference` (نفس آلية التوقيع الموجودة فعلاً في
 * resolve_by_phone() لحالة 'ambiguous') وهاتف مقنَّع. **لا كتابة إطلاقاً في
 * هذا الملف** — القراءة فقط، تماماً كالخدمتين المُستهلَكتين. لا نداء لـ
 * PGE_Checkin_Recorder هنا مطلقاً (الكتابة تبقى حصراً عبر
 * pge_supervisor_checkin_confirm الموجودة فعلاً في checkin-ajax.php، غير
 * مُعدَّلة، تُستهلَك كما هي من القالب لاحقاً).
 *
 * التفويض/nonce: يُعاد استخدام pge_supervisor_checkin_require_middleware()
 * المُعرَّفة فعلاً في includes/checkin-ajax.php حرفياً (بلا نسخ الكود) — نفس
 * nonce action الحالي 'pge_supervisor_checkin_nonce' يُستخدَم لكل نداءات
 * صفحة تسجيل الحضور (بحث QR/بحث يدوي/تأكيد) بلا اختراع آلية جديدة.
 */

if (!function_exists('pge_checkin_ui_mask_phone')) {
    /**
     * تقنيع الهاتف للعرض الآمن — نفس قاعدة التقنيع المُستخدَمة فعلاً في
     * PGE_Guest_Resolution_Service::mask_phone_for_display() (خاصة هناك، لا
     * يمكن استدعاؤها من هنا) وPGE_Supervisor_Portal_Bootstrap::mask_phone()
     * — إخفاء كل الأرقام إلا آخر 4. دالة تنسيق عرض بحتة، لا منطق أعمال.
     */
    function pge_checkin_ui_mask_phone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        $len = strlen($digits);
        if ($len <= 4) {
            return $digits;
        }
        return str_repeat('•', $len - 4) . substr($digits, -4);
    }
}

if (!function_exists('pge_checkin_ui_reshape_guest')) {
    /**
     * إعادة تشكيل Guest Object الموحَّد (من PGE_Guest_Resolution_Service) إلى
     * صفّ آمن للعرض/الشبكة — بلا rsvp_id خام، بلا هاتف كامل. `reference` يُبنى
     * عبر PGE_Checkin_QR_Service::build_payload() العامة (نفس الآلية المُوقَّعة
     * المُستخدَمة فعلاً لمرشَّحي resolve_by_phone()'s ambiguous)، فيصلح مباشرة
     * كـidentifier_value مع identifier_type='qr' في pge_supervisor_checkin_confirm
     * الحالية غير المُعدَّلة — لا مسار حلّ/تأكيد جديد يُضاف.
     */
    function pge_checkin_ui_reshape_guest(array $guest): array
    {
        $event_id = (int) ($guest['event_id'] ?? 0);
        $rsvp_id = (int) ($guest['rsvp_id'] ?? 0);
        $phone = (string) ($guest['phone'] ?? '');
        // Phase 9B QR Architecture Final Fix: المولِّد الكنسي الوحيد — يضمن
        // qr_version الحالي الصحيح، لا PGE_Checkin_QR_Service::build_payload()
        // مباشرة (التي لم تعد تقبل استدعاءً بمعاملين فقط أصلاً).
        $reference = ($rsvp_id > 0 && $event_id > 0 && class_exists('PGE_Guest_Resolution_Service'))
            ? PGE_Guest_Resolution_Service::build_scanner_qr_payload($event_id, $rsvp_id, $phone)
            : '';

        return [
            'reference' => $reference,
            'name' => (string) ($guest['name'] ?? ''),
            'masked_phone' => pge_checkin_ui_mask_phone($guest['phone'] ?? ''),
            'invite_code' => (string) ($guest['invite_code'] ?? ''),
            'expected_guest_count' => (int) ($guest['expected_guest_count'] ?? 1),
            'checked_in' => (bool) ($guest['checked_in'] ?? false),
            'checked_in_at' => $guest['checked_in_at'] ?? null,
            'checkin_method' => $guest['checkin_method'] ?? null,
            'actual_entered_count' => isset($guest['actual_entered_count']) ? $guest['actual_entered_count'] : null,
        ];
    }
}

/**
 * pge_supervisor_checkin_ui_search — بحث موحَّد (هاتف كامل/رمز دعوة/اسم) لأجل
 * شاشة البحث اليدوي فقط — قراءة بحتة، لا تسجيل حضور هنا إطلاقاً.
 * POST: nonce, query
 *
 * @return void (wp_send_json_*) بالشكل:
 *   {result:'no_results'|'single'|'multiple', candidates: array<int,array>}
 */
add_action('wp_ajax_pge_supervisor_checkin_ui_search', 'pge_supervisor_checkin_ui_search_handler');
add_action('wp_ajax_nopriv_pge_supervisor_checkin_ui_search', 'pge_supervisor_checkin_ui_search_handler');
function pge_supervisor_checkin_ui_search_handler()
{
    // إعادة استخدام حرفية لنفس دالة nonce+authorize المُعرَّفة فعلاً في
    // checkin-ajax.php — لا نسخ/تكرار لمنطق التفويض هنا.
    if (!function_exists('pge_supervisor_checkin_require_middleware')) {
        wp_send_json_error(['message' => 'تعذّر التحقق من الجلسة', 'reason' => 'middleware_unavailable'], 500);
    }
    $authorization = pge_supervisor_checkin_require_middleware();
    $event_id = (int) $authorization['event_id'];

    $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
    if ($query === '') {
        wp_send_json_error(['message' => 'أدخل نصاً للبحث', 'reason' => 'empty_query']);
    }

    if (!class_exists('PGE_Guest_Resolution_Service') || !class_exists('PGE_Checkin_QR_Service')) {
        wp_send_json_error(['message' => 'الخدمة غير متاحة حالياً', 'reason' => 'resolution_service_unavailable']);
    }

    // استعلام "يشبه هاتفاً" (7 أرقام فأكثر) → المسار الموثَّق صراحة في الـRFC:
    // "Use the existing ambiguous-phone contract" — resolve_by_phone() الحالية
    // غير المُعدَّلة، والتي تُميِّز 0/1/أكثر من نتيجة أصلاً بنفسها.
    $normalized_phone = function_exists('pge_norm_phone') ? pge_norm_phone($query) : preg_replace('/\D+/', '', $query);
    $looks_like_phone = ($normalized_phone !== '' && strlen($normalized_phone) >= 7);

    if ($looks_like_phone) {
        $resolution = PGE_Guest_Resolution_Service::resolve_by_phone($event_id, $query);
        $result_kind = (string) ($resolution['result'] ?? 'not_found');

        if ($result_kind === 'not_found') {
            wp_send_json_success(['result' => 'no_results', 'candidates' => []]);
        }

        if ($result_kind === 'ambiguous') {
            // مرشَّحو resolve_by_phone() آمنون أصلاً (reference موقَّع، لا هاتف
            // خام، لا rsvp_id) — يُمرَّرون كما هم بلا أي تعديل إضافي.
            $candidates = [];
            foreach (($resolution['candidates'] ?? []) as $c) {
                $candidates[] = [
                    'reference' => (string) ($c['reference'] ?? ''),
                    'name' => (string) ($c['name'] ?? ''),
                    'masked_phone' => (string) ($c['masked_phone'] ?? ''),
                    'invite_code' => '',
                    'expected_guest_count' => (int) ($c['expected_guest_count'] ?? 1),
                    // حالة الحضور غير معروفة لكل مرشَّح دون استعلام إضافي لكل
                    // صف على حدة (resolve_by_phone() لا تُعيدها لحالة الغموض) —
                    // تُعرَض كـ"غير معروفة" صراحة في الواجهة بدل افتراض قيمة.
                    'checked_in' => null,
                    'checked_in_at' => null,
                    'checkin_method' => null,
                    'actual_entered_count' => null,
                ];
            }
            wp_send_json_success(['result' => 'multiple', 'candidates' => $candidates]);
        }

        if ($result_kind === 'found') {
            wp_send_json_success(['result' => 'single', 'candidates' => [pge_checkin_ui_reshape_guest($resolution['guest'])]]);
        }

        wp_send_json_success(['result' => 'no_results', 'candidates' => []]);
    }

    // استعلام نصّي (اسم/رمز دعوة) → البحث العام الحالي غير المُعدَّل، بإعادة
    // تشكيل كل نتيجة لإسقاط rsvp_id/الهاتف الخام فقط (لا تغيير على منطق
    // المطابقة نفسه).
    $result = PGE_Guest_Resolution_Service::search($event_id, $query);
    $guests = is_array($result['guests'] ?? null) ? $result['guests'] : [];

    if (count($guests) === 0) {
        wp_send_json_success(['result' => 'no_results', 'candidates' => []]);
    }

    $candidates = array_map('pge_checkin_ui_reshape_guest', $guests);
    wp_send_json_success([
        'result' => count($candidates) === 1 ? 'single' : 'multiple',
        'candidates' => $candidates,
    ]);
}
