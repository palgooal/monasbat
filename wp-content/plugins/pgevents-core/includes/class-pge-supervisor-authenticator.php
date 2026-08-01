<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Authenticator — Entry Check-in Supervisors، Phase 3
 * تصحيح معماري (Blocking Issue: "Invitation Acceptance and Supervisor
 * Authentication are two different responsibilities. They must remain
 * decoupled.")
 * ============================================================================
 * "3. Authentication Orchestrator — Responsible ONLY for coordinating the
 * flow. Responsibilities: Validate invitation token → Accept invitation →
 * Verify assignment became ACTIVE → Create Supervisor Session → Return
 * authenticated session. The Orchestrator owns the workflow. Neither
 * Assignment Service nor Session Service should own the other."
 *
 * هذا الملف هو المكان الوحيد في المشروع الذي "يعرف" بوجود كل من
 * PGE_Supervisor_Assignment_Service (Phase 2) وPGE_Supervisor_Session
 * (Phase 3) معاً ويربط بينهما. لا أي منهما يستدعي الآخر أو يعرف بوجوده —
 * راجع التوثيق أعلى كل من الملفين لتأكيد ذلك صراحة.
 *
 * **صفر كتابة/قراءة مباشرة على أي جدول من هنا** — هذا الملف لا يحتوي أي
 * $wpdb إطلاقاً. كل تفاعل مع البيانات يمر حصراً عبر الواجهات العامة
 * للخدمتين: PGE_Supervisor_Assignment_Service::accept_invitation() /
 * ::get_assignment_state() (الأخيرة أُضيفت في هذا التصحيح تحديداً لتفادي
 * حاجة هذا المنسِّق لقراءة mon_event_supervisors مباشرة بنفسه)،
 * وPGE_Supervisor_Session::create_session().
 *
 * ============================================================================
 * دورة المصادقة الكاملة (Requirement 2 — Phase 3، لا تغيير في التسلسل نفسه)
 * ============================================================================
 * Invitation URL → Validate raw token → Compare SHA-256 hash → Validate
 * assignment → Validate status → Create Supervisor Session → Redirect to
 * supervisor portal.
 *
 * الخطوات الأربع الأولى (توكن خام ← هاش ← إسناد ← حالة) بالكامل داخل
 * PGE_Supervisor_Assignment_Service::accept_invitation() (Phase 2، معتمدة،
 * غير مُعدَّلة هنا في منطقها الداخلي — أُضيف فقط get_assignment_state()
 * كواجهة قراءة عامة جديدة، راجع تعليقها هناك). خطوة "التحقق من أن الإسناد
 * أصبح ACTIVE فعلاً" هي مسؤولية *هذا* المنسِّق (وليست جزءاً من accept_
 * invitation() نفسها ولا من create_session()) — تُنفَّذ عبر استدعاء
 * get_assignment_state() صراحة، لا عبر قراءة SQL مباشرة. الخطوة الأخيرة
 * (إنشاء الجلسة) عبر PGE_Supervisor_Session::create_session() فقط.
 *
 * "Redirect to supervisor portal" وصف مفاهيمي فقط — لا تنفيذ فعلي لأي
 * wp_redirect()/route/صفحة هنا (Requirement 9: "No UI... Only authentication
 * foundation")، بنفس التفسير المعتمد في Phase 2/3. authenticate() دالة PHP
 * صرفة، بلا أي أثر HTTP جانبي.
 *
 * ============================================================================
 * معالجة الفشل الجزئي (Failure Handling) — القاعدة المطلوبة صراحةً
 * ============================================================================
 * "If: Invitation Acceptance succeeds BUT Session creation fails. Then: The
 * assignment MUST remain ACTIVE. Never roll back to INVITED. Never consume
 * history. The invitation token has already been consumed. The supervisor
 * may authenticate again later using the approved future authentication
 * entry point. Do NOT attempt to recreate the invitation."
 *
 * هذه القاعدة **لا تحتاج أي كود تراجع (Rollback) لتُطبَّق — بل، العكس تماماً:
 * الالتزام بها يعني عمداً عدم كتابة أي كود تراجع إطلاقاً.** السبب: accept_
 * invitation() (Phase 2) عملية مكتملة وذرية بذاتها بالفعل — بمجرد نجاحها
 * ($wpdb->update() ناجح فعلياً على صف mon_event_supervisors)، الإسناد أصبح
 * active والتوكن أصبح مُستهلَكاً (invitation_token_hash = NULL) بشكل دائم
 * ونهائي، بصرف النظر عمّا يحدث بعد ذلك في أي خطوة لاحقة. create_session()
 * عملية منفصلة تماماً على جدول مختلف تماماً (mon_supervisor_sessions) — فشلها
 * لا يملك، ولا يجب أن يملك، أي طريقة للتأثير رجعياً على الكتابة التي سبق أن
 * التزمت (commit) في mon_event_supervisors. أي محاولة "لإعادة الإسناد إلى
 * invited" أو "لإعادة توليد توكن دعوة جديد تلقائياً" عند فشل الجلسة ستكون
 * انتهاكاً مباشراً لمبدأ Append-Only History المعتمد منذ تصحيح Phase 1/2
 * البنيوي (راجع class-mon-catalog-schema.php، upgrade_to_1_10_0()) —
 * "دعوة جديدة يجب أن تُنشئ صفاً جديداً دائماً، لا تعديل صف تاريخي أبداً"،
 * وinvited هنا حالة تاريخية سابقة انتهت فعلياً، لا حالة حاضرة يمكن العودة
 * إليها.
 *
 * authenticate() أدناه، عند فشل create_session()، تُعيد ببساطة خطأً
 * ('result' => 'error', 'stage' => 'session') يحمل معلومات كافية للمستدعي،
 * دون لمس صف الإسناد بأي شكل بعد تلك اللحظة. هذا "عدم الفعل" (عدم كتابة أي
 * كود تراجع) هو التنفيذ الصحيح والمقصود للقاعدة، لا نقصاً في التنفيذ.
 *
 * ============================================================================
 * التعافي بعد فشل جزئي (Recovery) — موثَّق صراحةً، غير مُنفَّذ كنقطة دخول هنا
 * ============================================================================
 * "Future authentication endpoints may issue a new Supervisor Session for
 * an already ACTIVE assignment without requiring invitation acceptance
 * again. This is intentional."
 *
 * الإسناد الذي وصل status='active' لكن بلا جلسة ناجحة (بسبب فشل create_
 * session() المؤقت) ليس "عالقاً" ولا "معطوباً" — أي استدعاء مستقبلي لـ
 * PGE_Supervisor_Session::create_session($assignment_id, $event_id) مباشرة
 * (من أي نقطة مصادقة مستقبلية معتمَدة، خارج نطاق Phase 3 الحالي: لا تنفيذ
 * لأي نقطة دخول HTTP/UI هنا بعد) سينجح فوراً بافتراض توفر البنية التحتية
 * (اتصال قاعدة بيانات سليم) في تلك اللحظة — بلا الحاجة لأي رابط دعوة جديد،
 * بلا استدعاء accept_invitation() مرة ثانية إطلاقاً. هذا هو بالضبط نفس
 * المسار الذي أثبتته اختبارات Phase 3 الأصلية لسيناريو "إعادة توليد الجلسة"
 * (tests/test-supervisor-session.php، السيناريو 5) — إصدار جلسة إضافية
 * لإسناد نشط بالفعل دون إعادة عبور تدفّق قبول الدعوة. القاعدة نفسها تنطبق
 * حرفياً على حالة "الفشل الجزئي" هنا: لا فرق جوهري بين "أريد جلسة ثانية لأني
 * أستخدم جهازاً آخر" و"أريد جلسة لأن الأولى فشلت في الإنشاء" — كلتاهما
 * "إصدار جلسة جديدة لإسناد ACTIVE بالفعل"، تماماً كما تسمح به create_session()
 * دون أي شرط مسبق غير وجود assignment_id وevent_id صالحين.
 */

class PGE_Supervisor_Authenticator
{
    /**
     * تنسيق تدفّق المصادقة الكامل (Requirement 2). المسؤولية الوحيدة لهذه
     * الدالة: **الترتيب**، لا التنفيذ الداخلي لأي من الخطوتين. لا $wpdb هنا،
     * لا SQL، لا معرفة بأسماء الجداول أو الأعمدة في أي من الخدمتين.
     *
     * @return array{result: string, ...}
     *   'authenticated' — نجحت المصادقة بالكامل. يتضمن assignment_id، event_id،
     *                      session_token (الخام، مرة واحدة فقط)، session_id.
     *   'error'          — فشل في أي خطوة. يتضمن 'stage':
     *                        'invitation' — فشل قبول الدعوة نفسه (توكن غير
     *                          صالح، حالة غير مقبولة، أو تعذّر التحقق من
     *                          نجاح التفعيل بعد قبول ظاهري ناجح).
     *                        'session'    — قبول الدعوة نجح فعلاً (الإسناد
     *                          أصبح active، التوكن استُهلِك بشكل دائم) لكن
     *                          إنشاء الجلسة فشل. **لا تراجع يحدث في هذه
     *                          الحالة** — راجع توثيق "معالجة الفشل الجزئي"
     *                          أعلى هذا الملف. 'reason' تحمل سبب فشل
     *                          create_session() حرفياً.
     */
    public static function authenticate($raw_invitation_token): array
    {
        // ── الخطوة 1: قبول الدعوة (مسؤولية PGE_Supervisor_Assignment_Service
        // وحدها — توكن خام ← هاش ← إسناد ← حالة ← تفعيل، كل ذلك داخلياً هناك) ──
        $invitation_result = PGE_Supervisor_Assignment_Service::accept_invitation($raw_invitation_token);

        $accepted_result = (string) ($invitation_result['result'] ?? '');
        if (!in_array($accepted_result, ['accepted', 'already_active'], true)) {
            return [
                'result' => 'error',
                'stage' => 'invitation',
                'reason' => $invitation_result['reason'] ?? 'invitation_failed',
                'status' => $invitation_result['status'] ?? null,
            ];
        }

        $assignment_id = (int) ($invitation_result['id'] ?? 0);
        if ($assignment_id <= 0) {
            return ['result' => 'error', 'stage' => 'invitation', 'reason' => 'missing_assignment_id'];
        }

        // ── الخطوة 2: التحقق من أن الإسناد أصبح ACTIVE فعلياً — مسؤولية
        // المنسِّق نفسه (Requirement 2: "Verify assignment became ACTIVE"،
        // خطوة منفصلة صراحة عن accept_invitation() في وصف RFC). عبر الواجهة
        // العامة الجديدة get_assignment_state() فقط — لا SQL مباشر هنا ──────
        $assignment = PGE_Supervisor_Assignment_Service::get_assignment_state($assignment_id);

        if ($assignment === null || (string) ($assignment['status'] ?? '') !== 'active') {
            return ['result' => 'error', 'stage' => 'invitation', 'reason' => 'assignment_not_active_after_acceptance'];
        }

        $event_id = (int) ($assignment['event_id'] ?? 0);

        // ── الخطوة 3: إنشاء الجلسة (مسؤولية PGE_Supervisor_Session وحدها) ──
        // عند الفشل هنا: راجع توثيق "معالجة الفشل الجزئي" أعلى الملف — لا
        // تراجع، لا إعادة إنشاء دعوة، لا لمس لصف الإسناد بعد هذه النقطة.
        $session_result = PGE_Supervisor_Session::create_session($assignment_id, $event_id);
        if (($session_result['result'] ?? '') !== 'created') {
            return [
                'result' => 'error',
                'stage' => 'session',
                'reason' => $session_result['reason'] ?? 'session_creation_failed',
            ];
        }

        return [
            'result' => 'authenticated',
            'assignment_id' => $assignment_id,
            'event_id' => $event_id,
            'session_token' => $session_result['session_token'],
            'session_id' => $session_result['id'],
            'expires_at' => $session_result['expires_at'],
        ];
    }
}

/**
 * دالة عامة رفيعة (thin wrapper) — تُبقي pge_supervisor_authenticate() متاحة
 * بنفس التوقيع والسلوك الذي استُهلِك به فعلياً في اختبارات Phase 3 الأصلية
 * (tests/test-supervisor-session.php)، دون الحاجة لإعادة كتابة أي استدعاء
 * قائم. لا منطق هنا إطلاقاً — تفويض مباشر بالكامل لـ
 * PGE_Supervisor_Authenticator::authenticate().
 */
if (!function_exists('pge_supervisor_authenticate')) {
    function pge_supervisor_authenticate($raw_invitation_token): array
    {
        return PGE_Supervisor_Authenticator::authenticate($raw_invitation_token);
    }
}
