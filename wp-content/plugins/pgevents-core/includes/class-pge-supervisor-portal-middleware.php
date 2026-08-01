<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Portal Middleware — Entry Check-in Supervisors، Phase 3.5
 * ============================================================================
 * "Supervisor Portal Foundation" RFC، Requirement 2: "Authorization Middleware
 * — Every portal request must pass through the authorization layer. Use
 * ONLY: pge_is_active_supervisor_for_event($event_id). Never trust: GET,
 * POST, Phone numbers, Cookies directly, Hidden fields. Only validated
 * Supervisor Session."
 *
 * هذا الملف هو الحارس الوحيد المسموح به لبوابة الوصول إلى Supervisor Portal
 * (Requirement 1: "The route must require a valid Supervisor Session"). لا
 * يكتب على أي جدول، لا يعرف شيئاً عن دورة حياة الدعوة (Phase 2) ولا عن بناء
 * الجلسة نفسها (Phase 3) — فقط يستهلك الواجهات العامة الجاهزة من الملفين
 * class-pge-supervisor-session.php (validate_session()، pge_is_active_
 * supervisor_for_event()) دون أي SQL خاص به.
 *
 * ============================================================================
 * لماذا استدعاءان لا استدعاء واحد؟ (validate_session() ثم pge_is_active_
 * supervisor_for_event($event_id))
 * ============================================================================
 * بوابة البورتال (/supervisor/) لا تحمل event_id في الرابط إطلاقاً (Requirement
 * 1 — جلسة واحدة تُعرِّف إسناداً واحداً فقط، لا حاجة لمعرِّف في الـURL). لذلك
 * لا يمكن استدعاء pge_is_active_supervisor_for_event($event_id) ابتداءً بلا
 * معرفة أي event_id أصلاً — و**المصدر الوحيد الموثوق** لهذا الرقم هو الجلسة
 * نفسها (لا $_GET، لا $_POST، لا حقل مخفي؛ هذا بالضبط ما يمنعه Requirement 2
 * صراحةً). الحل: أولاً نقرأ الكوكي (لا نثق بقيمته، هي مجرد مفتاح بحث خام)
 * ونمرره إلى PGE_Supervisor_Session::validate_session() — هذه هي "الجلسة
 * المصادَق عليها" المذكورة حرفياً في Requirement 2 ("Only validated Supervisor
 * Session")، وهي أيضاً بالضبط ما يطلبه Requirement 5 (وجود الجلسة/الإسناد/
 * الحالة active/الانتماء للمناسبة/عدم الإلغاء/عدم الانتهاء — كل هذا مُنفَّذ
 * فعلياً داخل validate_session() ذاتها). بعد ذلك، وباستخدام event_id المُستخرَج
 * من نتيجة التحقق الموثوقة هذه فقط (لا من أي مُدخَل خارجي)، نستدعي
 * pge_is_active_supervisor_for_event($event_id) كبوابة التفويض **الفعلية
 * والوحيدة المُلزَمة حرفياً بنص Requirement 2** قبل منح أي وصول. هذا لا
 * يُكرِّر منطقاً عشوائياً: pge_is_active_supervisor_for_event() تُعيد نفسها
 * قراءة الكوكي والتحقق داخلياً بشكل مستقل — الاستدعاء المزدوج هو "دفاع
 * متعمّق" (Defense in Depth) لا خطأ تصميم، ويضمن حرفياً أن قرار السماح
 * النهائي يمر عبر دالة التفويض المُسمّاة صراحةً في المتطلب، لا عبر أي مسار
 * بديل يتجاوزها.
 *
 * ============================================================================
 * تصنيف الأخطاء الصريح (Requirement 5)
 * ============================================================================
 * كل سبب رفض من validate_session()/pge_is_active_supervisor_for_event() يُترجَم
 * هنا إلى حالة HTTP صريحة واحدة من ثلاث (401/403/404) — لا حالة "عامة" واحدة
 * تُغطي كل شيء:
 *   401 (Unauthenticated) — لا كوكي جلسة إطلاقاً، أو الجلسة غير موجودة/منتهية/
 *                            مُبطَلة صراحة (Logout) — "لا هوية موثوقة بعد".
 *   403 (Unauthorized)    — هوية موثوقة (جلسة صالحة تقنياً) لكن الإسناد
 *                            المرتبط بها لم يعد active (أُلغي من قِبَل
 *                            المضيف — Requirement 7: "all sessions invalid
 *                            immediately") — "معروف، لكن ممنوع الآن".
 *   404 (Not Found)        — الإسناد أو المناسبة المرتبطة بالجلسة لم تعد
 *                            موجودة فعلياً في قاعدة البيانات (حالة نادرة
 *                            جداً؛ البيانات Append-Only ولا تُحذَف صفوفها
 *                            أبداً في أي مكان من هذا المشروع، لكن التعامل معها
 *                            صراحة أفضل من افتراض استحالتها).
 */
class PGE_Supervisor_Portal_Middleware
{
    /**
     * خريطة سبب رفض validate_session() ← حالة HTTP الصريحة المقابلة
     * (Requirement 5: "401 Unauthenticated / 403 Authenticated but
     * unauthorized / 404 Assignment/Event not found / Expired/Revoked
     * session" — كل حالة تُعامَل صراحة، لا حالة افتراضية توضع للجميع).
     */
    private static function reason_to_http_status(string $reason): int
    {
        $map = [
            // لا هوية موثوقة بعد إطلاقاً (لا كوكي / جلسة غير موجودة / منتهية / مُبطَلة).
            'no_session_cookie'     => 401,
            'invalid_token'         => 401,
            'session_not_found'     => 401,
            'session_revoked'       => 401,
            'session_expired'       => 401,

            // هوية موثوقة (جلسة صالحة تقنياً)، لكن الإسناد لم يعد active.
            'assignment_not_active' => 403,
            'not_authorized_for_event' => 403,

            // الإسناد/المناسبة المرتبطة بالجلسة لم تعد موجودة فعلياً.
            'assignment_not_found'  => 404,
            'event_mismatch'        => 404,
        ];

        return $map[$reason] ?? 401;
    }

    /**
     * البوابة الوحيدة لأي طلب Portal (Requirement 1/2/5). لا تقبل أي معامل
     * من المستدعي — كل ما تحتاجه تقرأه هي بنفسها حصراً من الكوكي الموثوق
     * (PGE_Supervisor_Session::SESSION_COOKIE_NAME)، تماماً كما تفعل
     * pge_is_active_supervisor_for_event() ذاتها.
     *
     * @return array{
     *   result: 'authorized'|'denied',
     *   http_status?: int,
     *   reason?: string,
     *   assignment_id?: int,
     *   event_id?: int,
     *   session_id?: int,
     * }
     */
    public static function authorize(): array
    {
        if (!class_exists('PGE_Supervisor_Session') || !function_exists('pge_is_active_supervisor_for_event')) {
            // بيئة غير مكتملة (ملفات Phase 3 غير محمَّلة) — رفض آمن افتراضياً،
            // لا وصول عند الشك (Fail Closed)، لا كشف تفاصيل داخلية.
            return ['result' => 'denied', 'http_status' => 401, 'reason' => 'session_layer_unavailable'];
        }

        $raw_token = isset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME])
            ? (string) $_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]
            : '';

        if ($raw_token === '') {
            return ['result' => 'denied', 'http_status' => 401, 'reason' => 'no_session_cookie'];
        }

        // الخطوة 1: التحقق الكامل من الجلسة (Requirement 5) — المصدر الموثوق
        // الوحيد لـevent_id/assignment_id، لا أي مُدخَل خارجي آخر.
        $validation = PGE_Supervisor_Session::validate_session($raw_token);

        if (($validation['result'] ?? '') !== 'valid') {
            $reason = (string) ($validation['reason'] ?? 'invalid_session');
            return [
                'result' => 'denied',
                'http_status' => self::reason_to_http_status($reason),
                'reason' => $reason,
            ];
        }

        $event_id = (int) ($validation['event_id'] ?? 0);
        $assignment_id = (int) ($validation['assignment_id'] ?? 0);
        $session_id = (int) ($validation['session_id'] ?? 0);

        if ($event_id <= 0 || $assignment_id <= 0) {
            return ['result' => 'denied', 'http_status' => 404, 'reason' => 'assignment_not_found'];
        }

        // الخطوة 2: دالة التفويض الحقيقية الوحيدة المُلزَمة صراحةً بالمتطلب
        // (Requirement 2) — event_id المُمرَّر هنا مصدره حصراً نتيجة
        // validate_session() الموثوقة أعلاه، لا أي معامل طلب.
        if (!pge_is_active_supervisor_for_event($event_id)) {
            return ['result' => 'denied', 'http_status' => 403, 'reason' => 'not_authorized_for_event'];
        }

        return [
            'result' => 'authorized',
            'assignment_id' => $assignment_id,
            'event_id' => $event_id,
            'session_id' => $session_id,
        ];
    }
}
