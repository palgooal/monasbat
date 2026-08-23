<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Send Worker — Model D2، D2-W6 (Worker-Time Reauthorization +
 * Cartat Transport Execution — تنفيذ محاولة واحدة فقط)
 * ============================================================================
 * الطبقة الوحيدة التي تُنفِّذ فعلياً محاولة إرسال دعوة واحدة مُطالَب بها
 * (claimed) سلفاً عبر D2-W4، بعد أن أثبت D2-W5 وجودها في طابور دائم. هذا
 * الملف هو أول نقطة في كامل خط الأنابيب (Pipeline) التي تستدعي فعلياً
 * PGE_Cartat_Transport — لا استدعاء Cartat في أي طبقة سابقة (D2-W1 حتى
 * D2-W5).
 *
 * السلسلة المطلوبة بالضبط (log_id من الطابور):
 *   قراءة سجل الدفتر الموثوق (Ledger) من جديد → تحقّق أن المحاولة لا تزال
 *   قابلة للتنفيذ → إعادة تخويل طازجة تماماً وقت التنفيذ (Worker-Time
 *   Reauthorization) → إعادة قراءة سياق الضيف/الدعوة الحالي → بناء رسالة
 *   الدعوة عبر القناة الإنتاجية القائمة فعلاً → نقل Cartat → إنهاء دائم
 *   (finalize) → إزالة عنصر الطابور وفق سياسة محدَّدة بدقة.
 *
 * ============================================================================
 * القاعدة الأهم: عنصر الطابور (Queue Item) ليس مصدر الحقيقة إطلاقاً (D2-W5)
 * ============================================================================
 * عنصر PGE_Invitation_Send_Queue لا يحتوي إلا log_id + queued_at (راجع
 * D2-W5 Fix Pass 1) — هذا الملف لا يقرأ منه أبداً actor_user_id/event_id/
 * status/batch_id/guest_phone. الهوية الكاملة تُعاد قراءتها حصراً عبر
 * PGE_Message_Log::find_by_id($log_id) — سجل pge_message_log نفسه هو
 * المصدر الوحيد الموثوق لكل حقل يُستخدَم في هذا الملف.
 *
 * ============================================================================
 * لماذا لا يُعاد استدعاء PGE_Invitation_Send_Application::
 * authorize_send_for_actor() هنا — قرار معماري موثَّق صراحة، لا سهواً
 * ============================================================================
 * قد يبدو للوهلة الأولى أن إعادة التخويل عند وقت التنفيذ (DEC-009) تعني
 * ببساطة إعادة استدعاء authorize_send_for_actor() (D2-W3) — لكن هذا **غير
 * صحيح فعلياً هنا تحديداً**: تلك الدالة تُركِّب فوق PGE_Invitation_Send_State
 * ::resolve() (D2-W2)، والتي تُشتِق in_progress **حصراً** من
 * `state === 'send_requested'` (أي: هل توجد محاولة pending حالياً؟) **بلا أي
 * حساب لانتهاء الـLease** (موثَّق صراحة في رأس ملف D2-W2 نفسه — ذلك الحساب
 * حصراً شأن claim() الذرية). بما أن السجل الذي يُنفِّذه هذا العامل الآن **هو
 * نفسه** أحدث محاولة (لا يزال pending وقت التنفيذ)، فإن أي استدعاء لـ
 * authorize_send_for_actor() لنفس event_id/guest_phone سيقرأ حتماً
 * state=send_requested/in_progress=true من سجله **الخاص**، ويُعيد
 * RESULT_ALREADY_IN_PROGRESS دوماً — بصرف النظر تماماً عن صلاحية الفاعل
 * الفعلية. هذا يجعل تلك الدالة غير قابلة للاستخدام بنيوياً لهذا الغرض: عامل
 * حقيقي لن يصل أبداً إلى "authorized" عبر هذا المسار لسجله الخاص.
 *
 * البديل الصحيح المُطبَّق هنا: استدعاء طبقة القرار (Authorization Core) وحدها
 * مباشرة — PGE_Event_Access_Authorization::resolve_context() +
 * PGE_Event_Access_Repository::get_guest_assignment() (طازجان تماماً وقت
 * التنفيذ) + PGE_Event_Access_Authorization::can_send_guest_invitation()
 * (القسم 6 من الموجز حرفياً: Owner/Admin يحتفظ بالسلطة، الداعي الإضافي يجب
 * أن يستوفي active+manager+allocated_quota!=NULL+مجموعة واحدة ممنوحة حالياً+
 * الضيف داخلها حالياً). هذا **تخطٍّ متعمَّد** لطبقتي D2-W2/D2-W3 بالكامل —
 * لا تكرار لمنطقهما، فقط استدعاء مباشر للطبقة الأساسية الأدنى (H1B/H1C) التي
 * يُركَّب هو نفسه فوقها أصلاً. لا Cache، لا نتيجة تخويل قديمة تُعامَل كصالحة.
 *
 * ============================================================================
 * D2-W6A — إنهاء pre-transport محلول: PGE_Message_Log::STATUS_CANCELLED
 * ============================================================================
 * D2-W6 وثَّق هنا صراحةً قيداً: STATUS_FAILED/STATUS_AMBIGUOUS_TRANSPORT_ERROR
 * (المفردتان الوحيدتان المتاحتان وقتها لـfinalize_failure()) لا تُعبِّران
 * بأمان عن "التخويل لم يعد صالحاً وقت التنفيذ" — كلتاهما تفترضان محاولة نقل
 * فعلية حدثت، بينما Cartat لم يُستدعَ إطلاقاً هنا. D2-W6A حلّ هذا بعد بحث
 * صريح في العقد القائم لـpge_message_log (راجع docs/DECISION-LOG.md DEC-009
 * — هذا إكمال تقني له، لا قراراً معمارياً جديداً):
 *
 *   - عمود status هو VARCHAR(30) NOT NULL (راجع class-pge-messaging-schema.php
 *     ::ensure_message_log_table()) — **ليس ENUM** — فإضافة قيمة جديدة للعمود
 *     لا تتطلب أي Migration/Schema Change إطلاقاً.
 *   - لا يوجد أي عمود "سبب"/"خطأ"/Metadata منفصل على الجدول — فالتمييز بين
 *     سبب الإلغاء (تخويل ساقط مقابل دورة حياة تغيّرت) يبقى متاحاً فقط في قيمة
 *     الإرجاع الآنية لهذا الملف (RESULT_NOT_AUTHORIZED مقابل
 *     RESULT_LIFECYCLE_MISMATCH + reason)، لا في السجل الدائم نفسه — هذا قيد
 *     متبقٍّ صادق، مُبلَّغ عنه صراحةً، لا مخفي.
 *
 * الحل: PGE_Message_Log::STATUS_CANCELLED = 'cancelled' — حالة نهائية رابعة،
 * مستقلة تماماً عن TERMINAL_FAILURE_STATUSES (لا تُضاف إليه عمداً — راجع
 * توثيق رأس class-pge-message-log.php لتفصيل استقلالها الكامل عن
 * mark_failed()/latest_failure_status في D2-W2)، عبر طبقة مستقلة تماماً:
 * PGE_Message_Log::mark_cancelled() ← PGE_Invitation_Send_Ledger::
 * finalize_cancelled(). ضمان صارم: 'cancelled' تعني حصراً أن Cartat لم
 * يُستدعَ إطلاقاً لهذه المحاولة — لا استثناء.
 *
 * التطبيق هنا (كلا الفرعين اللذين كانا يتوقفان بلا إنهاء في D2-W6):
 *
 *   1. §6 — سقوط التخويل وقت التنفيذ (can_send_guest_invitation() = false):
 *      يُستدعى الآن finalize_cancelled(). نجاح → إزالة عنصر الطابور +
 *      الإعادة RESULT_NOT_AUTHORIZED (reason='authorization_lapsed'). فشل
 *      الكتابة نفسها (نادر، دفاعي) → **لا Cartat يُستدعى، عنصر الطابور
 *      يبقى كما هو (غير مُزال)**، الإعادة RESULT_RETRYABLE_ERROR
 *      (reason='cancellation_write_failed') — قابل للاستعادة الكاملة، بلا
 *      أي حالة نهائية خاطئة تُفرَض قسراً.
 *   2. §8 — عدم تطابق دورة الحياة (finalize_lifecycle_mismatch()): تحوَّلت
 *      من finalize_failure(STATUS_FAILED) (اصطلاح Thank You القديم) إلى
 *      finalize_cancelled() — القسم 7 من موجز D2-W6A يفضِّل هذا صراحةً الآن
 *      بعد توفر المفردة، **بلا أي تعديل على Thank You نفسه**. نفس تماثل
 *      النجاح/الفشل أعلاه.
 *
 * فرع ثالث لم يُعدَّل عمداً — context_unavailable (§6، resolve_context()
 * يُعيد WP_Error): هذا الفرع القائم أصلاً في D2-W6 يبقى كما هو (لا إنهاء، لا
 * إزالة طابور) لأن WP_Error هنا قد يعني خطأ DB عابر قابل للاستعادة
 * (database_error()) بقدر ما قد يعني تخويلاً ساقطاً فعلياً — تصنيفه يتطلب
 * تفكيك WP_Error داخل PGE_Event_Access_Authorization_Context::resolve() وهو
 * خارج حدود ملفات D2-W6A المصرَّح بها؛ راجع القسم X من تقرير D2-W6A النهائي.
 *
 * D2-W2/D2-W1: 'cancelled' **لم** يُمنَح قيمة state مستقلة في current_state()
 * عمداً — ذلك يفرض قراراً منتجياً غير محلول هنا (هل تُسمح إعادة محاولة عادية
 * بعد إلغاء، أم تتطلب نية استئناف صريحة؟) — راجع توثيق class-pge-invitation-
 * send-ledger.php لتفصيل كامل. claim() يعامل 'cancelled' مثل 'failed' تماماً
 * (Retry مسموح دوماً) بلا أي تغيير في منطقه — سلوك موثَّق، لا سهو.
 *
 * ============================================================================
 * النطاق (D2-W6 فقط)
 * ============================================================================
 * محاولة واحدة فقط لكل استدعاء process_log_id() — لا تكرار على كل عناصر
 * الطابور (ذلك عمل D2-W7+ مستقبلي غير موجود هنا)، لا Cron جديد، لا AJAX/UI،
 * لا Bulk، لا هجرة طابور Owner القديم، لا UltraMsg. لا claim() يُستدعى هنا
 * إطلاقاً (العامل يستهلك محاولة موجودة سلفاً، لا يُنشئ محاولة جديدة). لا
 * فحص حصة (Quota) — الحصة تحكم إنشاء الضيف فقط، لا قابلية إرساله (القسم 17
 * من الموجز). لا بوابة RSVP إضافية — نفس سلوك send_invitations() الإنتاجي
 * الحالي تماماً (يرسل بصرف النظر عن حالة الرد).
 *
 * تختبره حصراً tests/test-d2-w6-invitation-send-worker.php.
 */
final class PGE_Invitation_Send_Worker
{
    /** نجح الإرسال فعلياً — المزوّد قبل الرسالة صراحة، والدفتر أُنهي 'sent'. */
    const RESULT_SENT = 'sent';

    /** رفض صريح من المزوّد — الدفتر أُنهي 'failed'. */
    const RESULT_FAILED = 'failed';

    /** نتيجة نقل غامضة، أو فشل حفظ محلي بعد قبول المزوّد — الدفتر أُنهي 'ambiguous_transport_error'. */
    const RESULT_AMBIGUOUS = PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR;

    /** التخويل الحالي (وقت التنفيذ) لم يعد يُثبت سلطة الفاعل — الدفتر أُنهي 'cancelled' منذ D2-W6A (أو، لفرع context_unavailable، ترك دون إنهاء — راجع توثيق الملف أعلاه). */
    const RESULT_NOT_AUTHORIZED = 'not_authorized';

    /** دورة الحياة الحالية للدعوة تختلف عن دورة حياة المحاولة المُطالَب بها — لا نقل، إنهاء آمن (STATUS_CANCELLED منذ D2-W6A — راجع توثيق رأس الملف). */
    const RESULT_LIFECYCLE_MISMATCH = 'lifecycle_mismatch';

    /** السجل نهائي أصلاً (sent/failed/ambiguous) قبل وصول العامل — لا مساس، تنظيف عنصر طابور بالٍ فقط. */
    const RESULT_ALREADY_TERMINAL = 'already_terminal';

    /** فشل تقني قبل أي محاولة نقل (قفل تنفيذ محجوز، اعتماد Cartat غير مضبوط، فشل قراءة دفاعي) — قابل للاستعادة، بلا أي مساس بالدفتر/الطابور. */
    const RESULT_RETRYABLE_ERROR = 'retryable_error';

    /** مدخل غير صالح شكلياً، أو سجل غير موجود، أو نوع رسالة خاطئ. */
    const RESULT_INVALID = 'invalid';

    private static function execution_lock_name(int $log_id): string
    {
        return 'pge_invsend_exec_' . md5((string) $log_id);
    }

    /**
     * نقطة الدخول الوحيدة — تنفيذ محاولة واحدة فقط، مُعرَّفة بـlog_id سجل
     * pge_message_log. آمنة للاستدعاء المباشر حتى بلا عنصر طابور موجود
     * إطلاقاً (القسم 20-N من الموجز) — العامل لا يعتمد على وجود عنصر الطابور
     * لأي قرار، فقط يُزيله عند الحاجة وفق السياسة الموثَّقة أدناه.
     *
     * @param mixed $log_id
     * @return array{result:string,log_id:int,reason:?string}
     */
    public static function process_log_id($log_id): array
    {
        $normalized_log_id = is_scalar($log_id) ? (int) $log_id : 0;
        if ($normalized_log_id <= 0) {
            return self::outcome(self::RESULT_INVALID, $normalized_log_id, 'invalid_log_id');
        }

        if (!class_exists('PGE_Message_Log')
            || !class_exists('PGE_Message_Type')
            || !class_exists('PGE_Invitation_Send_Ledger')
            || !class_exists('PGE_Invitation_Repository')
            || !class_exists('PGE_Event_Access_Authorization')
            || !class_exists('PGE_Event_Access_Repository')
            || !class_exists('PGE_Message_Content_Resolver')
            || !class_exists('PGE_Cartat_Transport')) {
            return self::outcome(self::RESULT_RETRYABLE_ERROR, $normalized_log_id, 'dependency_unavailable');
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return self::outcome(self::RESULT_RETRYABLE_ERROR, $normalized_log_id, 'dependency_unavailable');
        }

        // ========================================================================
        // القسم 15 من الموجز — حصر تنفيذ حقيقي (لا تكرار Cartat لنفس log_id):
        // GET_LOCK قصير (بلا انتظار — timeout=0، نفس اصطلاح
        // PGE_Thank_You_Batch_Worker::acquire_lock() لأقفال الدورة، لا اصطلاح
        // claim() الذي ينتظر 5 ثوانٍ — هنا لا داعي للانتظار: عامل آخر يُنفِّذ
        // فعلياً هذا الـlog_id الآن يعني ببساطة "أعد المحاولة لاحقاً"، لا حاجة
        // للحجب). نطاق القفل = log_id وحده — مختلف تماماً عن قفل claim() في
        // D2-W1 (المُحصور بـevent_id+phone، غير مُستخدَم هنا إطلاقاً — العامل
        // لا يستدعي claim() مطلقاً).
        // ========================================================================
        $lock_name = self::execution_lock_name($normalized_log_id);
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 0));
        if ((int) $got_lock !== 1) {
            return self::outcome(self::RESULT_RETRYABLE_ERROR, $normalized_log_id, 'execution_lock_not_acquired');
        }

        try {
            return self::execute_locked($normalized_log_id);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /** كل المنطق الفعلي يعمل داخل قفل التنفيذ أعلاه فقط. */
    private static function execute_locked(int $log_id): array
    {
        // ====================================================================
        // §4 — إعادة قراءة الدفتر (Ledger) فقط، بلا أي ثقة بعنصر الطابور.
        // ====================================================================
        $row = PGE_Message_Log::find_by_id($log_id);
        if (!is_array($row)) {
            self::remove_queue_item($log_id);
            return self::outcome(self::RESULT_INVALID, $log_id, 'log_not_found');
        }

        $message_type = is_scalar($row['message_type'] ?? null) ? (string) $row['message_type'] : '';
        if ($message_type !== PGE_Message_Type::INVITATION) {
            self::remove_queue_item($log_id);
            return self::outcome(self::RESULT_INVALID, $log_id, 'wrong_message_type');
        }

        // ====================================================================
        // §5 — التحقق قبل التنفيذ: سجل نهائي أصلاً لا يُمَس إطلاقاً.
        // ====================================================================
        $status = is_scalar($row['status'] ?? null) ? (string) $row['status'] : '';
        if ($status !== PGE_Message_Log::STATUS_PENDING) {
            self::remove_queue_item($log_id);
            return self::outcome(self::RESULT_ALREADY_TERMINAL, $log_id, 'status_' . ($status !== '' ? $status : 'unknown'));
        }

        $event_id = isset($row['event_id']) ? (int) $row['event_id'] : 0;
        $actor_user_id = isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : 0;
        $guest_phone_raw = is_scalar($row['guest_phone'] ?? null) ? (string) $row['guest_phone'] : '';
        $guest_phone = function_exists('pge_norm_phone')
            ? pge_norm_phone($guest_phone_raw)
            : preg_replace('/\D+/', '', $guest_phone_raw);
        $stored_lifecycle = is_scalar($row['lifecycle_started_at'] ?? null) ? trim((string) $row['lifecycle_started_at']) : '';

        if ($event_id <= 0 || $actor_user_id <= 0 || $guest_phone === '' || $stored_lifecycle === '') {
            // دفاعي بحت — claim() الذرية (D2-W1) لا تُنشئ فعلياً سجلاً بهذا
            // الشكل في أي مسار حقيقي. لا مساس بالسجل/الطابور — قابل للاستعادة.
            return self::outcome(self::RESULT_RETRYABLE_ERROR, $log_id, 'malformed_ledger_row');
        }

        // ====================================================================
        // §8 — تحصين دورة الحياة (Lifecycle Fencing): إعادة اشتقاق دورة
        // الحياة الحالية فعلياً الآن، مطابقة حرفية مقابل القيمة المُخزَّنة وقت
        // المطالبة (Claim). نفس مصدر invited_at الذي استخدمه claim() نفسه —
        // PGE_Invitation_Repository::get_invitation()، طازج تماماً هنا.
        // ====================================================================
        $invitation = PGE_Invitation_Repository::get_invitation($event_id, $guest_phone);
        if (!is_array($invitation)) {
            if (self::finalize_lifecycle_mismatch($log_id)) {
                self::remove_queue_item($log_id);
                return self::outcome(self::RESULT_LIFECYCLE_MISMATCH, $log_id, 'invitation_not_found');
            }
            // فشل كتابة الإلغاء نفسه — لا Cartat، لا مساس بالطابور، قابل للاستعادة بالكامل.
            return self::outcome(self::RESULT_RETRYABLE_ERROR, $log_id, 'cancellation_write_failed');
        }

        $current_lifecycle = is_scalar($invitation['invited_at'] ?? null) ? trim((string) $invitation['invited_at']) : '';
        if ($current_lifecycle === '' || $current_lifecycle !== $stored_lifecycle) {
            if (self::finalize_lifecycle_mismatch($log_id)) {
                self::remove_queue_item($log_id);
                return self::outcome(self::RESULT_LIFECYCLE_MISMATCH, $log_id, 'lifecycle_changed');
            }
            // فشل كتابة الإلغاء نفسه — لا Cartat، لا مساس بالطابور، قابل للاستعادة بالكامل.
            return self::outcome(self::RESULT_RETRYABLE_ERROR, $log_id, 'cancellation_write_failed');
        }

        // ====================================================================
        // §6 — إعادة تخويل طازجة تماماً وقت التنفيذ. راجع توثيق رأس الملف
        // لسبب استدعاء طبقة القرار (Authorization Core) مباشرة هنا، لا
        // authorize_send_for_actor()/PGE_Invitation_Send_State.
        // ====================================================================
        $context = PGE_Event_Access_Authorization::resolve_context($event_id, $actor_user_id);
        if (!($context instanceof PGE_Event_Access_Authorization_Context)) {
            return self::outcome(self::RESULT_NOT_AUTHORIZED, $log_id, 'context_unavailable');
        }

        $assignment = PGE_Event_Access_Repository::get_guest_assignment($event_id, $guest_phone);
        if ($assignment instanceof WP_Error) {
            // فشل قراءة دفاعي — لا حكم تخويل يُتَّخَذ هنا إطلاقاً (لا نُبلِّغ
            // not_authorized عن شيء لم نستطع التحقق منه أصلاً). قابل
            // للاستعادة بالكامل — لا مساس بالسجل/الطابور.
            return self::outcome(self::RESULT_RETRYABLE_ERROR, $log_id, 'assignment_unavailable');
        }
        $guest_group_id = (is_array($assignment) && isset($assignment['group_id']) && (int) $assignment['group_id'] > 0)
            ? (int) $assignment['group_id']
            : null;

        $decision = PGE_Event_Access_Authorization::can_send_guest_invitation($context, $guest_group_id);
        if (empty($decision['allowed'])) {
            // D2-W6A — راجع توثيق رأس الملف: إنهاء pending → cancelled عبر
            // finalize_cancelled()، لا Cartat يُستدعى إطلاقاً في أي مسار هنا.
            if (PGE_Invitation_Send_Ledger::finalize_cancelled($log_id)) {
                self::remove_queue_item($log_id);
                return self::outcome(self::RESULT_NOT_AUTHORIZED, $log_id, 'authorization_lapsed');
            }
            // فشل كتابة الإلغاء نفسه — لا Cartat، لا مساس بالطابور، قابل للاستعادة بالكامل.
            return self::outcome(self::RESULT_RETRYABLE_ERROR, $log_id, 'cancellation_write_failed');
        }

        // ====================================================================
        // §9 — بناء الرسالة عبر نفس القناة الإنتاجية القائمة فعلياً
        // (Mon_Cartat_Handler::send_invitations()) — بلا أي تكرار لمنطق
        // القالب هنا، فقط نفس متغيرات السياق المحسوبة بنفس الطريقة تماماً.
        // ====================================================================
        $guest_name = (is_scalar($invitation['name'] ?? null) && trim((string) $invitation['name']) !== '')
            ? (string) $invitation['name']
            : 'ضيفنا العزيز';

        $event = function_exists('get_post') ? get_post($event_id) : null;
        $event_name = (is_object($event) && isset($event->post_title) && trim((string) $event->post_title) !== '')
            ? (string) $event->post_title
            : 'مناسبتنا';

        $event_date_raw = function_exists('get_post_meta') ? (string) get_post_meta($event_id, '_pge_event_date', true) : '';
        $event_date = '';
        if ($event_date_raw !== '' && function_exists('strtotime') && function_exists('date_i18n')) {
            $timestamp = strtotime(str_replace('T', ' ', $event_date_raw));
            $event_date = $timestamp !== false ? date_i18n('j F Y — g:i a', $timestamp) : '';
        }
        $event_date_line = $event_date !== '' ? "\n📅 {$event_date}" : '';

        $image_url = function_exists('get_the_post_thumbnail_url') ? (string) get_the_post_thumbnail_url($event_id, 'full') : '';

        $content = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::INVITATION, $event_id, [
            'guest_name'      => $guest_name,
            'event_name'      => $event_name,
            'event_date'      => $event_date,
            'event_date_line' => $event_date_line,
            'guest_phone'     => $guest_phone,
            'image_url'       => $image_url,
        ]);
        $caption = is_scalar($content['text'] ?? null) ? (string) $content['text'] : '';
        $phone_image_url = (isset($content['image_url']) && is_scalar($content['image_url']) && (string) $content['image_url'] !== '')
            ? (string) $content['image_url']
            : null;

        // ====================================================================
        // §10 — Cartat فقط. لا UltraMsg، لا wa.me، لا رابط متصفح.
        // ====================================================================
        $transport = self::resolve_transport();
        if (!$transport->has_credentials()) {
            // حالة بنية تحتية سابقة للنقل (غير خاصة بهذا log_id تحديداً) —
            // قابلة للاستعادة بالكامل، بلا أي مساس بالسجل/الطابور.
            return self::outcome(self::RESULT_RETRYABLE_ERROR, $log_id, 'cartat_not_configured');
        }

        $wa_number = $transport->format_number($guest_phone);

        try {
            $transport_result = $phone_image_url !== null
                ? $transport->send_media($wa_number, $phone_image_url, $caption)
                : $transport->send_text($wa_number, $caption);
            $outcome_code = $transport->interpret_result($transport_result);
        } catch (\Throwable $e) {
            $outcome_code = 'transport_error';
        }

        // ====================================================================
        // §11/§12 — تخطيط نتيجة النقل، بنفس نمط
        // PGE_Thank_You_Message_Service::process_recipient() حرفياً لحالة
        // "نجح النقل لكن فشل الحفظ المحلي" — لا إعادة محاولة نقل تلقائية أبداً.
        // ====================================================================
        if ($outcome_code === 'accepted') {
            if (PGE_Invitation_Send_Ledger::finalize_success($log_id)) {
                self::remove_queue_item($log_id);
                return self::outcome(self::RESULT_SENT, $log_id, 'accepted');
            }

            $finalized_ambiguous = PGE_Invitation_Send_Ledger::finalize_failure(
                $log_id,
                PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR
            );
            if ($finalized_ambiguous || self::is_now_terminal($log_id)) {
                self::remove_queue_item($log_id);
            }
            return self::outcome(self::RESULT_AMBIGUOUS, $log_id, 'finalize_error');
        }

        if ($outcome_code === 'rejected') {
            if (PGE_Invitation_Send_Ledger::finalize_failure($log_id, PGE_Message_Log::STATUS_FAILED)) {
                self::remove_queue_item($log_id);
                return self::outcome(self::RESULT_FAILED, $log_id, 'rejected');
            }
            if (self::is_now_terminal($log_id)) {
                self::remove_queue_item($log_id);
            }
            return self::outcome(self::RESULT_AMBIGUOUS, $log_id, 'finalize_error');
        }

        // 'transport_error' — غامضة دوماً وفق الاصطلاح القائم فعلياً في
        // المشروع (Thank You/الطابور القديم) — لا 'failed' أبداً لهذه الحالة.
        if (PGE_Invitation_Send_Ledger::finalize_failure($log_id, PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR)) {
            self::remove_queue_item($log_id);
            return self::outcome(self::RESULT_AMBIGUOUS, $log_id, 'transport_error');
        }
        if (self::is_now_terminal($log_id)) {
            self::remove_queue_item($log_id);
        }
        return self::outcome(self::RESULT_AMBIGUOUS, $log_id, 'transport_error');
    }

    /**
     * §8 — إنهاء آمن لعدم تطابق دورة الحياة. D2-W6A: تحوَّل من
     * finalize_failure(STATUS_FAILED) (اصطلاح Thank You القديم الذي اقتبسه
     * D2-W6 حرفياً) إلى finalize_cancelled() — Cartat لم يُستدعَ إطلاقاً في
     * هذا المسار، وSTATUS_CANCELLED يُعبِّر عن ذلك بدقة بلا التظاهر برفض
     * مزوّد لم يحدث. لا تعديل مقابل على Thank You نفسه — راجع توثيق رأس
     * الملف.
     */
    private static function finalize_lifecycle_mismatch(int $log_id): bool
    {
        return PGE_Invitation_Send_Ledger::finalize_cancelled($log_id);
    }

    private static function is_now_terminal(int $log_id): bool
    {
        $row = PGE_Message_Log::find_by_id($log_id);
        return is_array($row) && (string) ($row['status'] ?? '') !== PGE_Message_Log::STATUS_PENDING;
    }

    /**
     * §13 — إزالة عنصر الطابور وفق سياسة محدَّدة بدقة (راجع كل موضع استدعاء
     * أعلاه): فقط عند نتيجة دفتر نهائية فعلية (sent/failed/ambiguous/
     * cancelled — D2-W6A)، أو سجل غير موجود/نوع خاطئ/نهائي أصلاً. لا إزالة
     * أبداً عند خطأ بنية تحتية قابل للاستعادة (retryable_error) — بما فيه
     * فشل كتابة الإلغاء نفسه (cancellation_write_failed، D2-W6A) — ولا عند
     * context_unavailable (راجع توثيق رأس الملف) — كلاهما يترك المحاولة
     * والطابور كما هما تماماً كي تُعاد المحاولة/الفحص لاحقاً، بلا فقدان أي
     * إمكانية استعادة (D2-W5::find_recoverable_pending_attempts() يبقى
     * قادراً على اكتشاف أي محاولة pending يتيمة بلا عنصر طابور، إن أُزيل
     * عنصر الطابور خطأً في أي حالة أخرى مستقبلية).
     */
    private static function remove_queue_item(int $log_id): void
    {
        if (class_exists('PGE_Invitation_Send_Queue')) {
            PGE_Invitation_Send_Queue::remove($log_id);
        }
    }

    /**
     * نقطة الحصول الوحيدة على طبقة نقل Cartat — استدعاء مباشر بلا أي Factory
     * جديدة (PGE_Thank_You_Transport_Factory موثَّقة صراحة بأنها لـManual
     * Thank You فقط — لا إعادة استخدام هنا). الاختبار (tests/test-d2-w6-*)
     * يُعرِّف نسخته الخاصة من PGE_Cartat_Transport بدلاً من تحميل الملف
     * الحقيقي — بنفس اصطلاح استبدال الاعتماديات القائم أصلاً في اختبارات
     * D2-W4/D2-W5 (PGE_Event_Access_Repository/PGE_Invitation_Repository
     * المزيَّفتان هناك)، بلا أي نقطة حقن جديدة تُضاف لكود الإنتاج هنا.
     */
    private static function resolve_transport(): PGE_Cartat_Transport
    {
        return new PGE_Cartat_Transport();
    }

    private static function outcome(string $code, int $log_id, ?string $reason = null): array
    {
        return [
            'result' => $code,
            'log_id' => $log_id,
            'reason' => $reason,
        ];
    }
}
