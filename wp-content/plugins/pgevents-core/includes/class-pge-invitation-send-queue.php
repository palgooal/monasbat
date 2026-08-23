<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Send Queue — D2-W5 ("Durable Queue Integration Contract")
 * ============================================================================
 * يُنفِّذ **حصراً** خطوة "الطابور" (Enqueue) لمحاولة إرسال دعوة واحدة (ضيف
 * واحد) بعد أن أصبحت مطالبة (Claim) دائمة فعلياً عبر D2-W4
 * (PGE_Invitation_Send_Application::request_send_for_actor() →
 * PGE_Invitation_Send_Ledger::claim()). **لا شيء بعد ذلك**: لا Cartat، لا
 * UltraMsg، لا استدعاء مزوّد من أي نوع، لا worker ينفّذ نقلاً فعلياً، لا
 * finalize_success()/finalize_failure()، لا AJAX، لا UI، لا إرسال جماعي.
 *
 * السلسلة الكاملة المعتمدة لهذه المرحلة فقط:
 *   طلب مُخوَّل (D2-W4) → مطالبة دائمة موجودة في الـLedger (pge_message_log،
 *   status=pending) → عنصر طابور يُنشأ/قابل للاسترداد (هذا الملف) → عنصر
 *   الطابور يشير إلى المحاولة الدائمة (log_id) → **لا نقل يُستدعى هنا**.
 *
 * -----------------------------------------------------------------------
 * البنية التحتية القائمة التي جرى فحصها قبل التصميم (راجع تقرير D2-W5
 * النهائي لتفاصيل الفحص الكامل):
 *
 * 1) طابور الدعوة **القديم** (`pge_wa_queue_{event_id}` في `wp_options`،
 *    يستهلكه `class-cartat-handler.php::cron_process_queue()`) — **غير
 *    مُعاد استخدامه هنا إطلاقاً**. مُطعَّم بعمق بمنطق Invitation Credit
 *    Ledger/Replacement Entitlements في كل فرع تقريباً (نفس الاستنتاج
 *    الموثَّق فعلياً في docs/MESSAGING-ARCHITECTURE.md §5.4 لنفس السبب
 *    بالضبط عند تصميم طابور Reminder) — توسيعه لدعم مسار D2 (الذي لا يعرف
 *    شيئاً عن الرصيد أصلاً، وسلطة إرساله الوحيدة هي D2-W3/W4) كان يعني إما
 *    تفريعاً خطراً داخل دالة حسّاسة، أو خطر تسرّب منطق رصيد لمسار لا يجب أن
 *    يعرف عنه شيئاً. هوية عنصره أيضاً مبنية على الهاتف الخام لكل مناسبة، لا
 *    على log_id — بنية غير متوافقة مع عقد Idempotency المطلوب هنا (القسم 6
 *    من التكليف: `log_id` هو مفتاح الهوية الوحيد المعتمد، **وليس** actor أو
 *    هاتف).
 * 2) طابور Reminder (`docs/MESSAGING-ARCHITECTURE.md` §5.4): اختار عمداً
 *    عدم بناء أي تخزين "طابور" منفصل إطلاقاً — "حالة الطابور *هي* صفوف
 *    `message_log` نفسها (status=pending لكل batch_id)". سابقة معمارية
 *    قوية بأن `pge_message_log` وحده كافٍ أحياناً بلا طبقة إضافية — لكنها
 *    لا تكفي هنا بمفردها لأن التكليف (القسم 6/10/11) يتطلب صراحة: (أ) مفهوم
 *    "طابور" تشغيلي منفصل عن حالة الـLedger (لا يجوز خلط الاثنين في آلة
 *    حالة واحدة)، (ب) مفتاح Idempotency صريح على مستوى log_id مستقل عن حالة
 *    الصف، (ج) قراءة استرداد (Recovery Read) تُميّز "معلّق بلا عمل طابور
 *    نشط" عن "معلّق ومُمثَّل بالفعل بعمل طابور نشط" — تمييز لا يمكن التعبير
 *    عنه بعمود `status` وحده على صف الـLedger نفسه.
 * 3) `PGE_Thank_You_Batch_Store` (`class-pge-thank-you-batch-store.php`،
 *    Phase 4B-2.6): **السابقة المعمارية المُتَّبَعة فعلياً هنا** — تخزين
 *    دائم (Durable) عبر `add_option()`/`get_option()`/`update_option()`/
 *    `delete_option()` القياسية في WordPress، غير autoloaded (`false`
 *    كوسيط رابع)، أي صفوف حقيقية في جدول `wp_options` (جدول WordPress
 *    الأساسي القائم فعلاً — **لا** جدول/عمود/Migration جديد إطلاقاً)، لا
 *    Transient (التي قد تُستبدَل بـObject Cache خارجي قابل للفقد عند وجود
 *    Redis/Memcached — بالضبط سبب رفض Thank You Batch Store لها صراحةً في
 *    توثيقها الخاص). **الفرق الجوهري عن Thank You**: تلك مصممة لدفعة كاملة
 *    (Manifest بعناصر متعددة لكل مستلم)؛ هذا الملف أبسط بكثير — عنصر طابور
 *    واحد **لكل محاولة D2 مفردة (لكل log_id)**، لأن الوحدة الأساسية في D2
 *    هي "إرسال ضيف واحد" (راجع توثيق D2-W1/D2-W3/D2-W4) لا دفعة.
 *
 * -----------------------------------------------------------------------
 * قرار الديمومة (DEC-009 — راجع docs/DECISION-LOG.md): `pge_message_log`
 * يبقى **المصدر الوحيد الموثوق لحالة الإرسال** (Source of Truth) — صف
 * `pending` فيه **هو** "طُلِب الإرسال/تمت المطالبة"، بلا تغيير على عقد
 * D2-W2 إطلاقاً. عنصر الطابور هنا يجيب فقط على سؤال تشغيلي منفصل تماماً:
 * "هل توجد فعلاً عملية طابور نشطة تمثّل هذه المحاولة؟" — إن فُقِد عنصر
 * الطابور (خطأ تخزين، إعادة تشغيل عملية، فقدان Object Cache وهمي غير وارد
 * هنا لأننا لا نستخدم Transient أصلاً) تبقى المطالبة الدائمة في الـLedger
 * سليمة تماماً وقابلة للاكتشاف والاسترداد عبر find_recoverable_pending_
 * attempts() أدناه — Queue failure **لا يساوي أبداً** Provider send
 * failure، ولا يُستدعى `finalize_failure()` هنا إطلاقاً لهذا السبب.
 *
 * -----------------------------------------------------------------------
 * D2-W5 Fix Pass 1 — تنظيف الحمولة إلى مرجع تشغيلي بحت (لا لقطة حالة/
 * تفويض): عنصر الطابور **لا يخزّن الآن سوى** `log_id` (المرجع الوحيد إلى
 * المحاولة الدائمة) و`queued_at` (بيانات تشغيلية بحتة). **أُزيلَت** `status`
 * و`actor_user_id` و`batch_id` من الحمولة المُخزَّنة — كانت جميعها تكراراً
 * لبيانات موثوقة أصلاً في `pge_message_log` (القسم "قاعدة مصدر الحقيقة"
 * أدناه)، لا حاجة تشغيلية فعلية لأيٍّ منها داخل هذا الملف نفسه (لا فرز، لا
 * فلترة، لا قرار يعتمد عليها هنا). أي Worker مستقبلي **يجب** أن يقرأ
 * `actor_user_id`/`status`/`event_id`/`message_type`/`batch_id`/حقول
 * الـLifecycle من `PGE_Message_Log::find_by_id($log_id)` مباشرة — لا من
 * عنصر الطابور. لا Snapshot تفويض هنا إطلاقاً بأي شكل: لا حقل `authorized`
 * ولا ما يعادله، ولا حتى `actor_user_id` نفسه بعد الآن — أي Worker مستقبلي
 * **يجب** أن يُعيد التفويض بالحالة الحالية (`authorize_send_for_actor()`)
 * قبل أي نقل فعلي، بالضبط كما يقتضي DEC-009.
 *
 * -----------------------------------------------------------------------
 * **قاعدة مصدر الحقيقة (Source-of-Truth Rule) — القاعدة الحاكمة لهذا
 * الملف بالكامل:**
 *
 *   عنصر الطابور (QUEUE ITEM) = مرجع تشغيلي بحت (Operational Reference)،
 *   لا أكثر: "هل توجد فعلاً عملية طابور نشطة تشير إلى log_id كذا؟"
 *
 *   محاولة الـLedger (LEDGER ATTEMPT، صف `pge_message_log`) = السلطة
 *   الوحيدة الموثوقة (Authoritative) لحالة/نطاق الإرسال بالكامل: الحالة
 *   (pending/sent/failed/ambiguous_transport_error)، الفاعل (actor_user_id)،
 *   المناسبة (event_id)، نوع الرسالة (message_type)، الدفعة (batch_id)،
 *   وحقول تحصين دورة الحياة (rsvp_id/lifecycle_started_at).
 *
 *   عنصر الطابور **لا يجوز أبداً** أن يُعامَل كلقطة تفويض (Authorization
 *   Snapshot) ولا كلقطة حالة إرسال (Send-State Snapshot) — وجوده فقط يعني
 *   "توجد نيّة تشغيلية بمعالجة هذا log_id لاحقاً"، ولا يعني إطلاقاً أن
 *   الفاعل ما زال مخوَّلاً الآن، ولا يعني حالة الإرسال الحالية للمحاولة —
 *   كلاهما يجب أن يُقرآ طازجاً من `pge_message_log` دوماً عند الحاجة
 *   الفعلية، لا من هذا الملف.
 *
 * -----------------------------------------------------------------------
 * حدود العامل (Worker Boundary — القسم 13 من التكليف): **لا Worker هنا
 * إطلاقاً**. هذا الملف يوفّر فقط: enqueue (كتابة عنصر طابور)، قراءة/فحص
 * وجوده، وقراءة استرداد محدودة (Recovery Read) لمحاولات معلّقة غير
 * مُمثَّلة بعمل طابور نشط. لا dequeue فعلي، لا معالجة، لا حالة "processing"
 * (لأنه لا يوجد مُستهلِك بعد يمكن أن ينقل عنصراً إليها) — يُترَك عمداً
 * للمرحلة التالية (D2-W6+) التي ستملك: إعادة تفويض وقت التنفيذ → بناء
 * الرسالة → نقل Cartat → إنهاء الـLedger.
 */
class PGE_Invitation_Send_Queue
{
    /** نتائج enqueue_claimed_attempt() المستقرة الخارجية. */
    const RESULT_QUEUED = 'queued';
    const RESULT_ALREADY_QUEUED = 'already_queued';
    const RESULT_REJECTED = 'rejected';
    const RESULT_ERROR = 'error';

    /** الحد الأقصى الآمن الثابت لأي قراءة استرداد — يمنع مسحاً غير محدود بصرف النظر عن القيمة المطلوبة. */
    const MAX_RECOVERY_LIMIT = 500;

    private static function queue_option_key(int $log_id): string
    {
        return 'pge_invsend_queue_' . $log_id;
    }

    private static function now(): string
    {
        return function_exists('current_time')
            ? (string) current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }

    private static function result_shape($code, $log_id, $item, $reason): array
    {
        return [
            'result' => $code,
            'log_id' => $log_id,
            'item'   => $item,
            'reason' => $reason,
        ];
    }

    /**
     * حدود الطفرة الوحيدة المعتمدة في هذا الملف: تُنشئ عنصر طابور واحداً
     * يشير إلى محاولة D2 دائمة موجودة فعلاً (log_id)، أو تعيد نتيجة idempotent
     * إن كان العنصر موجوداً بالفعل. **لا تستدعي أي طبقة نقل، ولا تستدعي
     * claim()/finalize_*() إطلاقاً** — هذه الطبقة تفترض أن المطالبة الدائمة
     * سبق إنشاؤها فعلياً عبر D2-W4 قبل هذا الاستدعاء.
     *
     * مصدر الثقة الوحيد: قراءة طازجة لصف pge_message_log عبر log_id —
     * **لا** event_id/actor_user_id/batch_id/phone من العميل تُقبَل أو
     * تُستخدَم هنا إطلاقاً (القسم 4 من التكليف). لو استُدعيت بمعطى غير صالح
     * أو محاولة غير موجودة/غير من نوع invitation/غير pending (نهائية
     * بالفعل: sent/failed/ambiguous_transport_error) تفشل مغلقة (Fail
     * Closed) بـRESULT_REJECTED — لا طابور أبداً لمحاولة نهائية.
     *
     * **D2-W5 Fix Pass 1**: القراءة أعلاه (event_id/message_type/status/
     * batch_id) تبقى بالكامل لأغراض **التحقق فقط** (row exists، message_
     * type=invitation، status=pending، سلامة الصف عموماً) — بعد اجتياز
     * التحقق، **لا يُخزَّن في عنصر الطابور سوى log_id وqueued_at**. لا
     * تكرار لأي حقل من حقول الـLedger الموثوقة لمجرد الراحة (Convenience) —
     * راجع "قاعدة مصدر الحقيقة" في توثيق رأس الملف.
     *
     * @param int $log_id معرّف صف pge_message_log الدائم (من نتيجة D2-W4
     *                    الناجحة: request_send_for_actor()['log_id']).
     * @return array{result:string,log_id:int,item:?array{log_id:int,queued_at:string},reason:?string}
     */
    public static function enqueue_claimed_attempt($log_id): array
    {
        $normalized_log_id = is_scalar($log_id) ? (int) $log_id : 0;
        if ($normalized_log_id <= 0) {
            return self::result_shape(self::RESULT_REJECTED, $normalized_log_id, null, 'invalid_log_id');
        }

        if (!class_exists('PGE_Message_Log') || !class_exists('PGE_Message_Type')) {
            return self::result_shape(self::RESULT_ERROR, $normalized_log_id, null, 'dependency_unavailable');
        }

        $row = PGE_Message_Log::find_by_id($normalized_log_id);
        if ($row === null) {
            return self::result_shape(self::RESULT_REJECTED, $normalized_log_id, null, 'not_found');
        }

        $message_type = isset($row['message_type']) && is_scalar($row['message_type'])
            ? (string) $row['message_type']
            : '';
        if ($message_type !== PGE_Message_Type::INVITATION) {
            return self::result_shape(self::RESULT_REJECTED, $normalized_log_id, null, 'wrong_message_type');
        }

        $status = isset($row['status']) && is_scalar($row['status']) ? (string) $row['status'] : '';
        if ($status !== PGE_Message_Log::STATUS_PENDING) {
            // نهائية بالفعل (sent/failed/ambiguous_transport_error) — لا طابور أبداً لمحاولة منتهية.
            return self::result_shape(self::RESULT_REJECTED, $normalized_log_id, null, 'not_pending');
        }

        // تحقّق سلامة صف عام إضافي (لا يزال قراءة فقط، لا تخزين): صف D2
        // دائم صحيح يحمل دوماً event_id موجباً وbatch_id غير فارغ (من D2-W1/
        // D2-W4) — صف بلا ذلك ليس مطالبة D2 شرعية، فيُرفَض مغلقاً. القيمتان
        // **لا تُخزَّنان** في عنصر الطابور أدناه — راجع "D2-W5 Fix Pass 1"
        // في توثيق رأس الملف.
        $event_id = isset($row['event_id']) ? (int) $row['event_id'] : 0;
        $batch_id = isset($row['batch_id']) && is_scalar($row['batch_id']) ? trim((string) $row['batch_id']) : '';
        if ($event_id <= 0 || $batch_id === '') {
            return self::result_shape(self::RESULT_REJECTED, $normalized_log_id, null, 'invalid_attempt_data');
        }

        // حمولة عنصر الطابور — الحد الأدنى المطلق فقط (D2-W5 Fix Pass 1):
        // مرجع (log_id) + بيانات تشغيلية بحتة (queued_at). **لا** status،
        // **لا** actor_user_id، **لا** batch_id، **لا** event_id/message_
        // type، **لا** هاتف/محتوى رسالة/group_id/أي حقل "authorized" أو ما
        // يعادله. كل ما سبق مُتاح دوماً بقراءة طازجة واحدة عبر
        // PGE_Message_Log::find_by_id($log_id) — لا تكرار لأي منها هنا
        // لمجرد الراحة (راجع "قاعدة مصدر الحقيقة" في توثيق رأس الملف).
        $item = [
            'log_id'    => $normalized_log_id,
            'queued_at' => self::now(),
        ];

        // add_option() ذرّية فعلياً تحت تزامن حقيقي بفضل UNIQUE KEY على
        // option_name في جدول wp_options نفسه — نفس الأساس الذي اعتمدت
        // عليه PGE_Thank_You_Batch_Store::create() ضمنياً. استدعاءان
        // متزامنان فعلياً لنفس log_id: أحدهما فقط ينجح بالـINSERT الحقيقي؛
        // الآخر يفشل بأمان ويُعاد توجيهه أدناه إلى already_queued — عنصر
        // طابور منطقي واحد فقط ينتج مهما تكرر النداء لنفس log_id.
        $added = add_option(self::queue_option_key($normalized_log_id), $item, '', false);
        if ($added) {
            // D2-W7 Fix Pass 1 — إشارة داخلية فقط (Work-Available Signal):
            // تُطلَق حصراً هنا (إنشاء عنصر طابور جديد فعلياً)، أبداً عند
            // already_queued الـIdempotent أدناه — تحل قيداً صادقاً وثَّقه
            // تقرير D2-W7 النهائي (لا مسار إيقاظ Bootstrap مُثبَت بعد
            // enqueue ناجح). هذا الملف لا يستدعي أي مُنسِّق مباشرة ولا
            // يعرف بوجوده إطلاقاً — do_action() قياسية بحتة، طبقة Hook
            // داخلية فقط (يستمع PGE_Invitation_Send_Orchestrator وحده إن
            // كان محمَّلاً). مُحاطة بـfunction_exists() فقط كي تبقى ملفات
            // اختبار D2-W5 القائمة (التي لا تُعرِّف do_action()) تعمل دون
            // أي تعديل عليها.
            if (function_exists('do_action')) {
                do_action('pge_invitation_send_work_available', $normalized_log_id);
            }
            return self::result_shape(self::RESULT_QUEUED, $normalized_log_id, $item, null);
        }

        // add_option() أعادت false: إما أن العنصر موجود فعلاً (Idempotent —
        // النتيجة المتوقَّعة والآمنة لإعادة enqueue لنفس log_id)، أو فشل
        // كتابة حقيقي. التمييز عبر إعادة قراءة فعلية — لا افتراض.
        $existing = self::get($normalized_log_id);
        if ($existing !== null && (int) ($existing['log_id'] ?? 0) === $normalized_log_id) {
            return self::result_shape(self::RESULT_ALREADY_QUEUED, $normalized_log_id, $existing, null);
        }

        // فشل تخزين حقيقي — المطالبة الدائمة في الـLedger تبقى سليمة تماماً
        // بلا أي لمس؛ هذا الملف لا يُنهي (finalize) ولا يُغيِّر حالة الصف
        // إطلاقاً لمجرد فشل كتابة الطابور. قابل لإعادة المحاولة لاحقاً
        // (retryable) عبر استدعاء enqueue_claimed_attempt() مجدداً بنفس
        // log_id، أو عبر find_recoverable_pending_attempts() أدناه.
        return self::result_shape(self::RESULT_ERROR, $normalized_log_id, null, 'enqueue_write_failed');
    }

    /** قراءة عنصر طابور واحد بمعرّف log_id، أو null إن لم يوجد. */
    public static function get($log_id): ?array
    {
        $normalized_log_id = is_scalar($log_id) ? (int) $log_id : 0;
        if ($normalized_log_id <= 0) {
            return null;
        }

        $value = get_option(self::queue_option_key($normalized_log_id), null);
        return is_array($value) ? $value : null;
    }

    /** هل توجد فعلاً عملية طابور نشطة تمثّل هذا log_id؟ */
    public static function is_queued($log_id): bool
    {
        return self::get($log_id) !== null;
    }

    /**
     * إزالة عنصر الطابور — للاستخدام المستقبلي فقط من طبقة استهلاك (Worker)
     * لم تُبنَ بعد في هذه المرحلة. لا مُستدعٍ إنتاجي حالياً لهذا الملف.
     */
    public static function remove($log_id): bool
    {
        $normalized_log_id = is_scalar($log_id) ? (int) $log_id : 0;
        if ($normalized_log_id <= 0) {
            return false;
        }

        return (bool) delete_option(self::queue_option_key($normalized_log_id));
    }

    /**
     * قراءة استرداد محدودة (القسم 11 من التكليف): محاولات دعوة معلّقة
     * (message_type=invitation، status=pending) في الـLedger **غير**
     * مُمثَّلة بالفعل بعنصر طابور نشط — أي "يتيمة" (مطالبة دائمة بلا عمل
     * طابور مرافق لها، غالباً بسبب فشل enqueue سابق). محدودة العدد دوماً
     * (MAX_RECOVERY_LIMIT) — لا مسح غير محدود. الاستعلام الأساسي
     * (PGE_Message_Log::query_pending_by_type()) يعتمد فهرس status القائم
     * فعلياً في Schema (راجع docs/MESSAGING-ARCHITECTURE.md §4.1: KEY status
     * (status)) — لا فهرس جديد ولا Schema Change مطلوب.
     *
     * لا يُنشئ هذه الدالة أي عنصر طابور بنفسها ولا تُغيّر أي حالة — قراءة
     * بحتة، الإنشاء الفعلي عبر re_enqueue_recoverable() أدناه أو استدعاء
     * enqueue_claimed_attempt() مباشرة من مُستدعٍ مستقبلي.
     *
     * @return array<int,array> صفوف pge_message_log الخام المؤهَّلة للاسترداد.
     */
    public static function find_recoverable_pending_attempts($limit = 50): array
    {
        $normalized_limit = is_scalar($limit) ? (int) $limit : 50;
        $normalized_limit = max(1, min(self::MAX_RECOVERY_LIMIT, $normalized_limit));

        if (!class_exists('PGE_Message_Log') || !class_exists('PGE_Message_Type')) {
            return [];
        }

        $pending_rows = PGE_Message_Log::query_pending_by_type(PGE_Message_Type::INVITATION, $normalized_limit);

        $recoverable = [];
        foreach ($pending_rows as $row) {
            $log_id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($log_id <= 0) {
                continue;
            }
            if (self::is_queued($log_id)) {
                // مُمثَّلة بالفعل بعمل طابور نشط — ليست يتيمة، لا استرداد مكرَّر.
                continue;
            }
            $recoverable[] = $row;
        }

        return $recoverable;
    }

    /**
     * وسيلة مساعدة صغيرة داخلية فقط: تجد المحاولات اليتيمة القابلة
     * للاسترداد (محدودة) وتُعيد طابرتها فعلياً واحدة تلو الأخرى عبر نفس
     * enqueue_claimed_attempt() أعلاه — بلا منطق مطالبة/طابور مكرَّر. **لا
     * مُستدعٍ إنتاجي حالياً** (لا Cron، لا Admin UI) — مُعرَّضة فقط لاستخدام
     * مستقبلي (D2-W6+ أو أداة تشغيل داخلية صغيرة)، ومُختبَرة هنا لإثبات
     * صحة السلوك المتوقَّع منها مسبقاً.
     *
     * @return array<int,array> نتيجة enqueue_claimed_attempt() لكل log_id مُعاد طابرته، مفهرسة بـlog_id.
     */
    public static function re_enqueue_recoverable($max = 20): array
    {
        $candidates = self::find_recoverable_pending_attempts($max);

        $results = [];
        foreach ($candidates as $row) {
            $log_id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($log_id <= 0) {
                continue;
            }
            $results[$log_id] = self::enqueue_claimed_attempt($log_id);
        }

        return $results;
    }

    /**
     * D2-W7 — قراءة استرداد محدودة إضافية (Additive فقط، لا تعديل على أي
     * دالة قائمة أعلاه): المتممة الحرفية لـfind_recoverable_pending_
     * attempts() — بدلاً من إعادة المحاولات **اليتيمة** (غير المُمثَّلة بعمل
     * طابور)، تُعيد محاولات دعوة معلّقة (pending) **المُمثَّلة فعلاً** بعنصر
     * طابور نشط حالياً. هذه هي "العمل المُطابَر" الفعلي الذي يستهلكه أي
     * منسِّق/Worker (D2-W7 PGE_Invitation_Send_Orchestrator تحديداً) —
     * قراءة بحتة، بلا أي أثر جانبي، بلا إنشاء/إزالة عنصر طابور، بلا استدعاء
     * Worker/Transport/Authorization من هنا إطلاقاً (تبقى تلك حصراً مسؤولية
     * المستدعي). نفس حدود find_recoverable_pending_attempts() تماماً: محدودة
     * العدد دوماً (MAX_RECOVERY_LIMIT)، نفس مصدر الاستعلام الأساسي
     * (PGE_Message_Log::query_pending_by_type() — الفهرس القائم فعلاً على
     * status، لا فهرس/Schema جديد).
     *
     * @return array<int,array> صفوف pge_message_log الخام المُمثَّلة بعمل طابور نشط.
     */
    public static function find_queued_pending_attempts($limit = 50): array
    {
        $normalized_limit = is_scalar($limit) ? (int) $limit : 50;
        $normalized_limit = max(1, min(self::MAX_RECOVERY_LIMIT, $normalized_limit));

        if (!class_exists('PGE_Message_Log') || !class_exists('PGE_Message_Type')) {
            return [];
        }

        $pending_rows = PGE_Message_Log::query_pending_by_type(PGE_Message_Type::INVITATION, $normalized_limit);

        $queued = [];
        foreach ($pending_rows as $row) {
            $log_id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($log_id <= 0) {
                continue;
            }
            if (!self::is_queued($log_id)) {
                // يتيمة (بلا عمل طابور نشط) — ليست "عملاً مُطابَراً"، خارج نطاق هذه الدالة (راجع find_recoverable_pending_attempts()).
                continue;
            }
            $queued[] = $row;
        }

        return $queued;
    }
}
