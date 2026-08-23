<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Send Orchestrator — Model D2، D2-W7 (Worker Scheduling /
 * Queue Consumption Orchestration — لا شيء آخر)
 * ============================================================================
 * الطبقة الوحيدة التي **تُكرِّر** فعلياً على عمل الطابور المُطالَب به سلفاً
 * (D2-W4) والمُطابَر فعلياً (D2-W5)، وتستدعي PGE_Invitation_Send_Worker::
 * process_log_id() (D2-W6) لكل log_id — عبر WP-Cron، بلا AJAX/UI/Bulk. هذا
 * الملف **لا يُنفِّذ أي إرسال بنفسه إطلاقاً** — كل تنفيذ فعلي (نقل Cartat،
 * إعادة تخويل وقت التنفيذ، إنهاء الدفتر) يبقى حصراً مسؤولية D2-W6 كما هي،
 * بلا أي تكرار أو تجاوز هنا.
 *
 * ============================================================================
 * الحدود الصارمة (مُثبَتة عبر فحص مصدر Source-Scan في اختبارات هذا الملف)
 * ============================================================================
 * هذا الملف **لا يستدعي إطلاقاً** أياً مما يلي — كلها تبقى حصراً داخل طبقاتها
 * الخاصة (D2-W1/D2-W3/D2-W6):
 *   - PGE_Invitation_Send_Ledger::claim() / finalize_success() /
 *     finalize_failure() / finalize_cancelled()
 *   - PGE_Event_Access_Authorization::resolve_context() /
 *     can_send_guest_invitation()
 *   - PGE_Event_Access_Repository::get_guest_assignment()
 *   - PGE_Message_Content_Resolver::resolve()
 *   - PGE_Cartat_Transport / send_text() / send_media()
 * نقطة التنفيذ الوحيدة المسموحة هنا: PGE_Invitation_Send_Worker::
 * process_log_id($log_id) — نداء واحد فقط لكل log_id في كل تشغيل دفعة واحد
 * (Batch)، بلا أي إعادة معالجة متزامنة لنفس log_id ضمن نفس التشغيل.
 *
 * ============================================================================
 * اكتشاف العمل (Work Discovery) — قراءة فقط عبر D2-W5، لا مسح Message Log مستقل
 * ============================================================================
 *   - العمل "المُطابَر" الجاهز للمعالجة الفورية: PGE_Invitation_Send_Queue::
 *     find_queued_pending_attempts() (دالة D2-W7 الإضافية المُضافة في D2-W5،
 *     Additive بحتة، بلا تعديل على أي دالة D2-W5 قائمة).
 *   - العمل "اليتيم" (محاولة pending بلا عنصر طابور نشط — غالباً فشل enqueue
 *     سابق): يُستعاد عبر PGE_Invitation_Send_Queue::re_enqueue_recoverable()
 *     (كانت موجودة فعلاً في D2-W5 بلا مُستدعٍ إنتاجي — هذا الملف هو أول
 *     مُستدعٍ إنتاجي لها).
 * لا استعلام SQL مباشر جديد على pge_message_log من هذا الملف إطلاقاً — كل
 * قراءة تمر عبر D2-W5 وحده.
 *
 * ============================================================================
 * جدولة WP-Cron — CRON_HOOK (عمل عادي، ذاتي التكرار) + RECOVERY_CRON_HOOK
 * (شبكة أمان دورية منخفضة التردد، D2-W7 Fix Pass 1)
 * ============================================================================
 * CRON_HOOK يُشغِّل دورة واحدة ذاتية التكرار (Self-Perpetuating، بنفس فلسفة
 * PGE_Thank_You_Batch_Worker لكن أبسط بكثير): كل Tick يستعيد اليتامى → يُنفِّذ
 * دفعة واحدة محدودة الحجم → يُجدوِل Tick تالٍ فقط إن بقي عمل فعلي (مُطابَر أو
 * قابل للاسترداد). لا جدولة أبداً بلا عمل حقيقي موجود (يمنع Cron Event متكرر
 * بلا فائدة). Dedup عبر wp_next_scheduled القياسي قبل أي جدولة جديدة — نفس
 * اصطلاح Thank You/Reminder/الطابور القديم حرفياً.
 *
 * -----------------------------------------------------------------------
 * D2-W7 Fix Pass 1 — إيقاظان مستقلان (Bootstrap Wake-Up)، لا استبدال للتصميم أعلاه:
 *
 * التقرير النهائي لـD2-W7 وثَّق صراحة قيداً حقيقياً: schedule_if_needed()
 * كانت "نقطة دخول قابلة لإعادة الاستخدام لمُستدعٍ مستقبلي" بلا أي مُستدعٍ
 * فعلي — أي أن أول جدولة إطلاقاً (بعد enqueue ناجح، أو بعد فشل enqueue
 * يُخلِّف يتيماً بلا طابور ولا Cron) لم تكن مُثبَتة الحدوث فعلياً في الإنتاج.
 * هذا الـFix Pass يحل الإيقاظين معاً، منفصلَين تماماً عن سلسلة CRON_HOOK
 * الذاتية القائمة أعلاه (لا تعديل عليها):
 *
 *   1) إيقاظ enqueue ناجح (فوري): PGE_Invitation_Send_Queue::
 *      enqueue_claimed_attempt() تُطلِق do_action('pge_invitation_send_
 *      work_available', $log_id) حصراً عند إنشاء عنصر طابور جديد فعلياً
 *      (RESULT_QUEUED) — أبداً عند already_queued الـIdempotent (لا ازدواج
 *      جدولة من إعادة enqueue متكررة لنفس log_id). هذا الملف يستمع فقط
 *      (work_available()) ويُفوِّض حصراً لـschedule_if_needed() القائمة —
 *      لا منطق جدولة جديد، Dedup عبر wp_next_scheduled يبقى هو نفسه الحاكم
 *      الوحيد. طبقة Hook داخلية متعمَّدة (لا استدعاء مباشر من Queue
 *      للمُنسِّق) — تُبقي D2-W5 مستقلاً تماماً عن معرفة وجود مُنسِّق أصلاً.
 *
 *   2) إيقاظ شبكة الأمان الدورية (اليتامى بلا طابور ولا Cron): RECOVERY_
 *      CRON_HOOK — Hook مستقل ثانٍ، منخفض التردد جداً (كل
 *      RECOVERY_CHECK_INTERVAL_SECONDS، 15 دقيقة)، ذاتي التكرار **دوماً**
 *      (بخلاف CRON_HOOK: لا يتوقف عن إعادة جدولة نفسه أبداً حتى بلا عمل —
 *      فهو شبكة الأمان الوحيدة القادرة على اكتشاف يتيم مستقبلي لا طابور له
 *      ولا Cron مُجدوَل أصلاً، فلا يصح أن يعتمد على وجود عمل الآن كي يستمر
 *      لاحقاً). معالجه (run_recovery_check()) يستدعي حصراً
 *      recover_and_schedule() ثم يُعيد جدولة نفسه — **لا مسح/معالجة مباشرة
 *      هنا إطلاقاً**، لا Worker ثانٍ. يُضمَن تسجيله فعلياً عبر
 *      ensure_recovery_scheduled() المربوطة بـadd_action('init', ...) في
 *      أسفل هذا الملف — نفس اصطلاح "auto-flush عند init" القائم فعلاً في
 *      pgevents-core.php (بلا register_activation_hook جديد، بلا أي حاجة
 *      لإعادة تفعيل الإضافة كي يبدأ العمل — يبدأ من أول تحميل صفحة تالٍ).
 *
 * الفصل بين المفهومين متعمَّد وصريح: الإيقاظ الفوري (1) هو مسار الكمون
 * الطبيعي لكل إرسال عادي؛ شبكة الأمان الدورية (2) ليست مسار الكمون
 * الأساسي إطلاقاً — فقط صمام أمان بطيء التردد لحالة نادرة (فشل enqueue).
 *
 * ============================================================================
 * القفل (Locking) — طبقة دفاع إضافية، لا بديل عن قفل D2-W6
 * ============================================================================
 * قفل واحد إضافي هنا فقط: قفل مسح الدفعة (Batch-Scan Lock)، GET_LOCK غير
 * حاجز (timeout=0)، نطاقه العملية كاملة (اسم ثابت واحد — لا معنى لنطاق
 * log_id هنا لأن هذا الملف يُكرِّر على مجموعة). الهدف: منع تشغيلَي Cron
 * متزامنَين (تراكب Tick طبيعي إن تأخّر تشغيل سابق) من مسح ومعالجة نفس الدفعة
 * مرتين في نفس اللحظة. **هذا لا يُغني إطلاقاً عن قفل التنفيذ الخاص بكل
 * log_id في PGE_Invitation_Send_Worker::execution_lock_name()** — ذلك القفل
 * يبقى كما هو تماماً، غير مُعدَّل، وهو خط الدفاع الحقيقي ضد تنفيذ نفس
 * log_id مرتين حتى لو تجاوز قفل الدفعة هنا لأي سبب.
 *
 * ============================================================================
 * التعامل مع النتائج — لا Hot Loop، لا إعادة معالجة متزامنة
 * ============================================================================
 * كل نتائج process_log_id() (sent/failed/ambiguous/not_authorized/
 * lifecycle_mismatch/already_terminal/invalid) نهائية بالفعل من منظور هذا
 * الملف — لا فرع خاص يُعاد فيه استدعاء process_log_id() لنفس log_id ضمن نفس
 * التشغيل، بصرف النظر عن النتيجة (retryable_error متضمَّنة: تُترَك للـTick
 * التالي المُجدوَل بفارق RETRY_DELAY_SECONDS، لا إعادة محاولة فورية أبداً).
 * إزالة عنصر الطابور بعد كل نتيجة نهائية تبقى حصراً مسؤولية D2-W6 نفسه
 * (remove_queue_item() الداخلية هناك) — هذا الملف لا يستدعي
 * PGE_Invitation_Send_Queue::remove() مطلقاً بنفسه.
 *
 * ============================================================================
 * تصنيف "ملغى" (cancelled) — لن يُعاد اكتشافه كعمل أبداً، ببنية القراءة نفسها
 * ============================================================================
 * find_queued_pending_attempts()/find_recoverable_pending_attempts() كلاهما
 * يُصفّي عبر PGE_Message_Log::query_pending_by_type() — أي status=pending
 * حصراً (راجع D2-W5). بما أن 'cancelled' حالة نهائية (D2-W6A) لا تُعيد صفها
 * أبداً إلى pending، فهي مُستبعَدة بنيوياً من كلا مصدري العمل هنا — بلا أي
 * فلترة إضافية مطلوبة في هذا الملف. وجود/عدم وجود Cron Event مُجدوَل **لا
 * يُعامَل أبداً كدليل على حالة أي محاولة** — الحالة الوحيدة الموثوقة تبقى
 * صف pge_message_log نفسه (DEC-009) — Cron هنا وسيلة تشغيل فقط، لا لقطة حالة.
 *
 * النطاق (D2-W7 فقط): لا AJAX، لا UI، لا إرسال جماعي (Bulk) يتجاوز هذه
 * الآلية، لا Schema/Migration جديد، لا تعديل على D2-W1 حتى D2-W6.
 *
 * تختبره حصراً tests/test-d2-w7-invitation-send-orchestrator.php.
 */
final class PGE_Invitation_Send_Orchestrator
{
    /** Hook العمل العادي — دورة ذاتية التكرار، تتوقف عند استنفاد العمل (راجع توثيق رأس الملف). */
    const CRON_HOOK = 'pge_invitation_send_worker_run';

    /** حجم الدفعة الافتراضي لكل Tick — اصطلاح مشروع قائم (Thank You/Reminder). */
    const DEFAULT_BATCH_SIZE = 10;

    /** سقف دفاعي صارم لأي حجم دفعة مطلوب — يمنع تشغيلاً متزامناً ضخماً بصرف النظر عن القيمة المطلوبة. */
    const MAX_BATCH_SIZE = 100;

    /** سقف عدد اليتامى المُستعادين في كل دورة استرداد — نفس افتراضي re_enqueue_recoverable() القائم في D2-W5. */
    const RECOVERY_LIMIT = 20;

    /** تأخير إعادة الجدولة القياسي بعد كل Tick — نفس اصطلاح Thank You/Reminder (25 ثانية) حرفياً. */
    const RETRY_DELAY_SECONDS = 25;

    /** تأخير "أقرب وقت ممكن" لأول جدولة بعد ظهور عمل جديد — نفس اصطلاح Thank You (1 ثانية) حرفياً. */
    const IMMEDIATE_DELAY_SECONDS = 1;

    /** D2-W7 Fix Pass 1 — Hook شبكة الأمان الدورية (اليتامى بلا طابور ولا Cron) — مستقل تماماً عن CRON_HOOK، راجع توثيق رأس الملف. */
    const RECOVERY_CRON_HOOK = 'pge_invitation_send_recovery_check';

    /** D2-W7 Fix Pass 1 — فاصل شبكة الأمان الدورية (15 دقيقة) — أبطأ عمداً بكثير من RETRY_DELAY_SECONDS، فهي صمام أمان نادر لا مسار كمون أساسي. */
    const RECOVERY_CHECK_INTERVAL_SECONDS = 900;

    private static function batch_lock_name(): string
    {
        return 'pge_invsend_orchestrator_batch_lock';
    }

    private static function batch_shape(int $processed, array $results, bool $has_more, ?string $reason): array
    {
        return [
            'processed' => $processed,
            'results'   => $results,
            'has_more'  => $has_more,
            'reason'    => $reason,
        ];
    }

    /**
     * تنفيذ دفعة واحدة محدودة فقط — بلا أي إعادة جدولة/استرداد داخلها (تلك
     * مسؤولية run_cron_tick()/recover_and_schedule() أدناه، لا هذه الدالة).
     * تكتشف العمل حصراً عبر PGE_Invitation_Send_Queue::
     * find_queued_pending_attempts()، وتستدعي PGE_Invitation_Send_Worker::
     * process_log_id() مرة واحدة فقط لكل log_id مكتشَف.
     *
     * @param mixed $limit
     * @return array{processed:int,results:array,has_more:bool,reason:?string}
     */
    public static function run_batch($limit = self::DEFAULT_BATCH_SIZE): array
    {
        $normalized_limit = is_scalar($limit) ? (int) $limit : self::DEFAULT_BATCH_SIZE;
        $normalized_limit = max(1, min(self::MAX_BATCH_SIZE, $normalized_limit));

        if (!class_exists('PGE_Invitation_Send_Queue') || !class_exists('PGE_Invitation_Send_Worker')) {
            return self::batch_shape(0, [], false, 'dependency_unavailable');
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return self::batch_shape(0, [], false, 'dependency_unavailable');
        }

        // ====================================================================
        // قفل مسح الدفعة — راجع توثيق رأس الملف: دفاع إضافي فقط، لا يستبدل
        // قفل التنفيذ الخاص بكل log_id في D2-W6 إطلاقاً.
        // ====================================================================
        $lock_name = self::batch_lock_name();
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 0));
        if ((int) $got_lock !== 1) {
            return self::batch_shape(0, [], false, 'batch_lock_not_acquired');
        }

        try {
            $rows = PGE_Invitation_Send_Queue::find_queued_pending_attempts($normalized_limit);

            $results = [];
            foreach ($rows as $row) {
                $log_id = isset($row['id']) ? (int) $row['id'] : 0;
                if ($log_id <= 0) {
                    continue;
                }
                // نداء واحد فقط لكل log_id في هذا التشغيل — لا Hot Loop، لا
                // إعادة معالجة متزامنة (راجع توثيق رأس الملف).
                $results[] = PGE_Invitation_Send_Worker::process_log_id($log_id);
            }

            // امتلاء الدفعة بالكامل يعني احتمال وجود عمل إضافي متبقٍّ خلف
            // الحد — إشارة تقريبية دفاعية فقط، لا استعلام إضافي هنا (المصدر
            // النهائي للقرار يبقى schedule_if_needed() نفسها).
            $has_more = count($rows) >= $normalized_limit;

            return self::batch_shape(count($results), $results, $has_more, null);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * جدولة Tick واحد فقط إن (أ) لم يكن مُجدوَلاً فعلاً (Dedup عبر
     * wp_next_scheduled القياسي)، و(ب) وُجد عمل حقيقي فعلاً (مُطابَر أو قابل
     * للاسترداد) — لا جدولة أبداً بلا عمل حقيقي (يمنع Cron Event متكرر بلا
     * فائدة). نقطة دخول عامة قابلة لإعادة الاستخدام مستقبلاً (مثلاً: عقب
     * enqueue_claimed_attempt() ناجح من مُستدعٍ D2-W4+ مستقبلي لم يوجد بعد).
     *
     * @return bool true إن جُدوِلَ Tick جديد فعلاً الآن، false غير ذلك (مُجدوَل أصلاً، أو لا عمل، أو بنية تحتية غير متاحة).
     */
    public static function schedule_if_needed(int $delay_seconds = self::IMMEDIATE_DELAY_SECONDS): bool
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return false;
        }

        if (wp_next_scheduled(self::CRON_HOOK) !== false) {
            // مُجدوَل فعلاً — لا تكرار (Dedup القياسي، نفس اصطلاح Thank You/Reminder).
            return false;
        }

        $has_work = false;
        if (class_exists('PGE_Invitation_Send_Queue')) {
            $has_work = count(PGE_Invitation_Send_Queue::find_queued_pending_attempts(1)) > 0
                || count(PGE_Invitation_Send_Queue::find_recoverable_pending_attempts(1)) > 0;
        }
        if (!$has_work) {
            return false;
        }

        $normalized_delay = max(0, $delay_seconds);
        $scheduled = wp_schedule_single_event(time() + $normalized_delay, self::CRON_HOOK);
        if ($scheduled === false) {
            return false;
        }

        if (function_exists('spawn_cron')) {
            spawn_cron();
        }

        return true;
    }

    /**
     * يستعيد اليتامى فعلياً (عبر D2-W5::re_enqueue_recoverable() — أول
     * مُستدعٍ إنتاجي لها) ثم يضمن وجود Tick مُجدوَل إن بقي عمل. نقطة دخول
     * عامة مستقلة عن run_cron_tick() — قابلة للاستدعاء يدوياً (أداة تشغيل
     * داخلية صغيرة مستقبلية) بلا تنفيذ دفعة فوراً.
     *
     * @return array{recovered:array,scheduled:bool}
     */
    public static function recover_and_schedule(): array
    {
        $recovered = class_exists('PGE_Invitation_Send_Queue')
            ? PGE_Invitation_Send_Queue::re_enqueue_recoverable(self::RECOVERY_LIMIT)
            : [];

        $scheduled = self::schedule_if_needed(self::IMMEDIATE_DELAY_SECONDS);

        return [
            'recovered' => $recovered,
            'scheduled' => $scheduled,
        ];
    }

    /**
     * D2-W7 Fix Pass 1 — مستمع إشارة "عمل متاح" (Work-Available Listener):
     * تُطلِقها PGE_Invitation_Send_Queue::enqueue_claimed_attempt() حصراً
     * عند إنشاء عنصر طابور جديد فعلياً (RESULT_QUEUED) عبر
     * do_action('pge_invitation_send_work_available', $log_id) — راجع
     * توثيق رأس الملف. تركيب بحت: تفويض حصري لـschedule_if_needed()
     * القائمة بالفعل، بلا أي منطق جدولة إضافي هنا — Dedup عبر
     * wp_next_scheduled يبقى هو الحاكم الوحيد لمنع الازدواج (enqueue متكرر
     * قريب زمنياً لعدة محاولات مختلفة يُنتج نداءات متعددة لهذه الدالة، لكن
     * حدث Cron واحد فقط في النهاية).
     *
     * @param mixed $log_id غير مُستخدَم في القرار — مُمرَّر فقط لتوثيق مصدر الإشارة عند التتبّع.
     * @return bool نفس قيمة إرجاع schedule_if_needed() المُفوَّض إليها.
     */
    public static function work_available($log_id = null): bool
    {
        return self::schedule_if_needed(self::IMMEDIATE_DELAY_SECONDS);
    }

    /**
     * D2-W7 Fix Pass 1 — معالج شبكة الأمان الدورية (RECOVERY_CRON_HOOK):
     * يستدعي حصراً recover_and_schedule() القائمة بالفعل (استرداد اليتامى
     * + جدولة CRON_HOOK إن نتج عمل) ثم يُعيد جدولة **نفسه** دوماً — بخلاف
     * CRON_HOOK، هذه السلسلة لا تتوقف أبداً عن التكرار حتى بلا عمل حالياً،
     * لأنها شبكة الأمان الوحيدة القادرة على اكتشاف يتيم مستقبلي بلا طابور
     * ولا Cron مُجدوَل أصلاً (راجع توثيق رأس الملف). **لا مسح/معالجة مباشرة
     * هنا إطلاقاً، لا Worker ثانٍ** — تركيب بحت فقط. public لأن
     * add_action() يستدعيها عبر [self::class, 'run_recovery_check'].
     *
     * @return array{recovery:array}
     */
    public static function run_recovery_check(): array
    {
        $recovery = self::recover_and_schedule();

        // إعادة جدولة شبكة الأمان نفسها دوماً — بلا أي شرط "وجود عمل"
        // (ذلك شرط CRON_HOOK وحده عبر schedule_if_needed()، ليس هنا).
        self::reschedule_recovery_check();

        return ['recovery' => $recovery];
    }

    /**
     * D2-W7 Fix Pass 1 — نقطة الإدخال (Bootstrap) الوحيدة لشبكة الأمان
     * الدورية: تضمن وجود جدولة فعلية لـRECOVERY_CRON_HOOK دوماً، حتى بلا
     * أي عمل حالي (Dedup عبر wp_next_scheduled فقط — لا بوابة "has_work"
     * هنا، خلافاً لـschedule_if_needed() القائمة لـCRON_HOOK). مربوطة
     * بـadd_action('init', ...) أسفل هذا الملف — كل تحميل صفحة (حتى بلا أي
     * enqueue سابق إطلاقاً) يضمن أن شبكة الأمان تعمل فعلياً في الإنتاج، لا
     * مجرد دالة مُعرَّضة بلا مُستدعٍ. آمنة للاستدعاء المتكرر (Idempotent
     * بالكامل عبر Dedup).
     *
     * @return bool true إن جُدوِلَ تشغيل جديد الآن، false إن كان مُجدوَلاً أصلاً أو تعذّرت البنية التحتية.
     */
    public static function ensure_recovery_scheduled(): bool
    {
        return self::reschedule_recovery_check();
    }

    /** منطق الجدولة/إعادة الجدولة الفعلي المشترك بين run_recovery_check() وensure_recovery_scheduled() — Dedup فقط، بلا بوابة عمل. */
    private static function reschedule_recovery_check(): bool
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return false;
        }

        if (wp_next_scheduled(self::RECOVERY_CRON_HOOK) !== false) {
            return false;
        }

        $scheduled = wp_schedule_single_event(time() + self::RECOVERY_CHECK_INTERVAL_SECONDS, self::RECOVERY_CRON_HOOK);
        return $scheduled !== false;
    }

    /**
     * معالج Cron الوحيد لسلسلة العمل العادية (CRON_HOOK) — تركيب بحت لثلاث
     * دوال عامة أعلاه فقط، بلا أي منطق عمل جديد هنا إطلاقاً (راجع توثيق
     * رأس الملف): استرداد اليتامى → تنفيذ دفعة واحدة → جدولة Tick تالٍ فقط
     * إن بقي عمل فعلي. هذه هي دالة الـHook المُسجَّلة أدناه — public لأن
     * add_action() يستدعيها عبر [self::class, 'run_cron_tick'].
     *
     * @return array{recovered:array,batch:array,scheduled_continuation:bool}
     */
    public static function run_cron_tick(): array
    {
        $recovered = class_exists('PGE_Invitation_Send_Queue')
            ? PGE_Invitation_Send_Queue::re_enqueue_recoverable(self::RECOVERY_LIMIT)
            : [];

        $batch = self::run_batch(self::DEFAULT_BATCH_SIZE);

        $scheduled_continuation = self::schedule_if_needed(self::RETRY_DELAY_SECONDS);

        return [
            'recovered'              => $recovered,
            'batch'                  => $batch,
            'scheduled_continuation' => $scheduled_continuation,
        ];
    }

    /**
     * فحص داخلي صغير فقط (لا واجهة إدارة كاملة — القسم 17 من الموجز يستبعد
     * ذلك عمداً): هل يوجد عمل معلّق؟ هل Tick مُجدوَل؟ هل قفل الدفعة مشغول
     * الآن (Probe غير حاجز — يُحرَّر فوراً إن نجح)؟ 'appears_stuck' إشارة
     * تقريبية فقط: عمل موجود فعلاً + لا Tick مُجدوَل + لا قفل مشغول الآن —
     * أي: توقّفت سلسلة إعادة الجدولة الذاتية دون سبب ظاهر.
     *
     * @return array{has_queued_work:bool,has_recoverable_work:bool,has_work:bool,cron_scheduled:bool,next_run_at:?int,lock_appears_busy:bool,appears_stuck:bool}
     */
    public static function get_status(): array
    {
        $has_queued = false;
        $has_recoverable = false;
        if (class_exists('PGE_Invitation_Send_Queue')) {
            $has_queued = count(PGE_Invitation_Send_Queue::find_queued_pending_attempts(1)) > 0;
            $has_recoverable = count(PGE_Invitation_Send_Queue::find_recoverable_pending_attempts(1)) > 0;
        }
        $has_work = $has_queued || $has_recoverable;

        $next_run = function_exists('wp_next_scheduled') ? wp_next_scheduled(self::CRON_HOOK) : false;
        $cron_scheduled = $next_run !== false;

        $lock_busy = self::is_batch_lock_busy();

        return [
            'has_queued_work'      => $has_queued,
            'has_recoverable_work' => $has_recoverable,
            'has_work'             => $has_work,
            'cron_scheduled'       => $cron_scheduled,
            'next_run_at'          => $cron_scheduled ? (int) $next_run : null,
            'lock_appears_busy'    => $lock_busy,
            'appears_stuck'        => $has_work && !$cron_scheduled && !$lock_busy,
        ];
    }

    /** Probe غير حاجز فقط — يُحرَّر القفل فوراً إن نجح الحصول عليه، لا أثر جانبي دائم. */
    private static function is_batch_lock_busy(): bool
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return false;
        }

        $lock_name = self::batch_lock_name();
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 0));
        if ((int) $got === 1) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            return false;
        }

        return true;
    }
}

// معالج سلسلة العمل العادية — يُستدعى المُنسِّق نفسه فقط، بلا أي منطق إضافي هنا.
add_action(PGE_Invitation_Send_Orchestrator::CRON_HOOK, [PGE_Invitation_Send_Orchestrator::class, 'run_cron_tick']);

// D2-W7 Fix Pass 1 — الإيقاظان المستقلان (راجع توثيق رأس الملف):
//   1) إيقاظ enqueue ناجح فوري — مستمع إشارة عمل داخلية تُطلِقها D2-W5 حصراً
//      عند إنشاء عنصر طابور جديد فعلياً، لا عند already_queued الـIdempotent.
add_action('pge_invitation_send_work_available', [PGE_Invitation_Send_Orchestrator::class, 'work_available']);
//   2) معالج شبكة الأمان الدورية نفسها — تركيب بحت (recover_and_schedule +
//      إعادة جدولة ذاتية دوماً)، لا مسح/معالجة مباشرة هنا.
add_action(PGE_Invitation_Send_Orchestrator::RECOVERY_CRON_HOOK, [PGE_Invitation_Send_Orchestrator::class, 'run_recovery_check']);
//   3) ضمان الإقلاع (Bootstrap) الفعلي لشبكة الأمان — Idempotent عبر Dedup،
//      كل تحميل صفحة (init) يضمن أن السلسلة تعمل فعلياً في الإنتاج، حتى
//      بلا أي enqueue سابق إطلاقاً. نفس اصطلاح "auto-flush عند init" القائم
//      فعلاً في pgevents-core.php — بلا register_activation_hook جديد.
add_action('init', [PGE_Invitation_Send_Orchestrator::class, 'ensure_recovery_scheduled']);
