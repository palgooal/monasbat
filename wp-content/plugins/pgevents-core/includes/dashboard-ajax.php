<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Supervisor Attendance Dashboard AJAX — Entry Check-in Supervisors، Phase 6
 * ============================================================================
 * "Supervisor Attendance Dashboard UI" RFC — "The flow must remain: Supervisor
 * Dashboard → PGE_Attendance_Dashboard_Provider → Authorization → Attendance
 * Statistics Engine → Database. The UI is only a consumer."
 *
 * هذا الملف **رقيق بالكامل** — لا حساب إحصاء هنا، لا SQL، لا منطق تفويض جديد.
 * كل ما يفعله: nonce → PGE_Supervisor_Portal_Middleware::authorize() (الآلية
 * الموجودة فعلاً، بنفس نمط checkin-ajax.php حرفياً — event_id مصدره الجلسة
 * الموثوقة حصراً، لا $_POST['event_id'] إطلاقاً) → تفويض الاستدعاء لـ
 * PGE_Attendance_Dashboard_Provider::get_dashboard() (الذي يُعيد تنفيذ تحقّقه
 * الخاص داخلياً أيضاً — تكرار دفاعي مقصود، لا يُضعِف شيئاً) → wp_send_json_*.
 *
 * لا تعديل هنا على PGE_Attendance_Dashboard_Provider ولا PGE_Attendance_
 * Statistics_Service ولا أي طبقة تفويض — استهلاك فقط، تماماً كما يتطلّب الـRFC:
 * "No UI component may calculate attendance numbers... No attendance
 * calculations may exist in controllers."
 *
 * مُسجَّلة على wp_ajax_ وwp_ajax_nopriv_ معاً — المشرف لا يملك بالضرورة حساب
 * ووردبريس (نفس تبرير checkin-ajax.php).
 */

/**
 * pge_supervisor_dashboard_data — جلب حزمة بيانات لوحة الحضور كاملة (ملخّص
 * المناسبة، ملخّص المشرفين، ملخّص الحضور، آخر عمليات التسجيل) لأجل التحميل
 * الأول عبر JS أو دورات التحديث التلقائي (Auto Refresh، كل 30 ثانية افتراضياً).
 * POST: nonce فقط — لا event_id من الطلب إطلاقاً (نفس قاعدة checkin-ajax.php).
 */
add_action('wp_ajax_pge_supervisor_dashboard_data', 'pge_supervisor_dashboard_data_handler');
add_action('wp_ajax_nopriv_pge_supervisor_dashboard_data', 'pge_supervisor_dashboard_data_handler');
function pge_supervisor_dashboard_data_handler()
{
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'pge_supervisor_dashboard_nonce')) {
        wp_send_json_error(['message' => 'رمز الأمان غير صالح', 'reason' => 'invalid_nonce'], 401);
    }

    if (!class_exists('PGE_Supervisor_Portal_Middleware')) {
        wp_send_json_error(['message' => 'تعذّر التحقق من الجلسة', 'reason' => 'middleware_unavailable'], 401);
    }

    // event_id يأتي حصراً من الجلسة الموثوقة — لا وثوق بأي معامل طلب.
    $authorization = PGE_Supervisor_Portal_Middleware::authorize();
    if (($authorization['result'] ?? '') !== 'authorized') {
        wp_send_json_error(
            ['message' => 'غير مصرَّح', 'reason' => (string) ($authorization['reason'] ?? 'unauthorized')],
            (int) ($authorization['http_status'] ?? 401)
        );
    }

    $event_id = (int) $authorization['event_id'];

    if (!class_exists('PGE_Attendance_Dashboard_Provider')) {
        wp_send_json_error(['message' => 'تعذّر تحميل بيانات اللوحة', 'reason' => 'provider_unavailable'], 500);
    }

    $dashboard = PGE_Attendance_Dashboard_Provider::get_dashboard($event_id);
    if (($dashboard['result'] ?? '') !== 'authorized') {
        wp_send_json_error(
            ['message' => 'غير مصرَّح', 'reason' => (string) ($dashboard['reason'] ?? 'unauthorized')],
            (int) ($dashboard['http_status'] ?? 401)
        );
    }

    wp_send_json_success($dashboard['data'] ?? []);
}
