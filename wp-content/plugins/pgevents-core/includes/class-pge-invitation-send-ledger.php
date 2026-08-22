<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Send Ledger — Model D2، D2-W1 (Durable Invitation Ledger
 * Integration — Foundation فقط، بلا إرسال فعلي)
 * ============================================================================
 * الأساس المعماري الوحيد لتتبّع محاولات إرسال **الدعوة** (message_type=
 * invitation) بشكل ذري ودائم، عبر إعادة استخدام {$wpdb->prefix}
 * pge_message_log القائم فعلاً (PGE_Message_Log) — بلا جدول جديد، بلا عمود
 * جديد، بلا Migration. يطبّق نفس فلسفة PGE_Thank_You_Claim (Claim ذري عبر
 * GET_LOCK قصير المدى + سجلات تاريخية غير قابلة للتعديل)، مع اختلاف جوهري
 * واحد موثَّق أدناه في هوية دورة الحياة.
 *
 * ============================================================================
 * القرار المعماري: هوية الضيف الدائمة = event_id + normalized_phone (لا
 * rsvp_id) — راجع docs/DECISION-LOG.md § DEC-009 (تحديث لاحق 2026-08-22)
 * ============================================================================
 * على عكس PGE_Thank_You_Claim (الذي يفترض دوماً وجود صف RSVP فعلي، لأن الشكر
 * لا يُرسَل إلا بعد تسجيل حضور فعلي أصلاً)، إرسال **الدعوة** يسبق أي رد RSVP.
 * الفحص المباشر لـPGE_Invitation_Repository::create() (Phase C) يُثبِت أن صف
 * RSVP (wp_pge_event_rsvps) **لا يُنشَأ إطلاقاً** عند إنشاء أول دعوة لهاتف
 * جديد ضمن مناسبة — reset_rsvp_for_new_invitation_lifecycle() تُعيد
 * 'not_found' بلا أي INSERT في هذه الحالة تحديداً (صف RSVP يُنشَأ لاحقاً،
 * فقط حين يتفاعل الضيف فعلياً مع رابط الرد عبر rsvp-handler.php). لذلك لا
 * يمكن اعتماد rsvp_id/RSVP.created_at كمرساة Lifecycle لهذا النوع من
 * الرسائل — الصف قد لا يكون موجوداً إطلاقاً وقت محاولة الإرسال.
 *
 * البديل الصحيح المُستخدَم هنا: `invited_at` من `_pge_invitation_status`
 * (عبر PGE_Invitation_Repository::get_invitation()، قراءة فقط، بلا أي تعديل
 * على تلك الطبقة) — هو نفسه الطابع الزمني الذي يأخذ قيمة جديدة في كل مرة
 * تبدأ فيها دورة حياة دعوة جديدة لنفس الهاتف (حذف ثم إعادة إنشاء، عبر
 * create() نفسها)، تماماً بنفس الدور الذي يلعبه RSVP.created_at لصف Thank
 * You — لذلك يُستخدَم هنا كقيمة `lifecycle_started_at` (نفس العمود الموجود
 * أصلاً في pge_message_log، بلا أي تعديل بنيوي عليه: DATETIME NULL، والقيمة
 * المخزَّنة نص MySQL DATETIME من نفس current_time('mysql', true) في كلتا
 * الحالتين — توافق نوع كامل، لا تحويل).
 *
 * نتيجة مباشرة ومقصودة: عمود `rsvp_id` في كل سجل تُنشئه هذه الطبقة يبقى
 * دوماً NULL (لا معنى له لهذا النوع من الرسائل تحديداً) — الفصل الحقيقي بين
 * دورات الحياة يعتمد حصراً على `event_id + guest_phone + lifecycle_started_at`
 * بالضبط كما نصّ عليه القرار المعتمد صراحةً (لا rsvp_id في معادلة الهوية).
 *
 * قيد معروف ومُوثَّق صراحةً (لم يُطلَب حلّه هنا، لا يُخفى): دقة `invited_at`
 * هي الثانية (MySQL DATETIME). حذف دعوة وإعادة إنشائها لنفس الهاتف خلال نفس
 * الثانية (عملية إدارية يدوية متتالية جداً، لا سيناريو مستخدم عادي) قد ينتج
 * نظرياً نفس القيمة، فتبدو دورة الحياة الجديدة وكأنها امتداد للقديمة. هذا
 * احتمال هامشي جداً موجود بالفعل بنفس دقة current_time('mysql') في أجزاء
 * أخرى قائمة من النظام، ويُذكَر صراحةً في تقرير هذه المرحلة كمخاطرة متبقية
 * معروفة (Residual)، لا كخلل مموَّه أو تجاهل.
 *
 * ============================================================================
 * الاختلاف عن PGE_Thank_You_Claim: لا عمود سلطة منفصل يحتاج حماية
 * ============================================================================
 * Thank You يحمي عموداً منفصلاً (thank_you_sent_at) هو مصدر الحقيقة النهائي
 * لحالة "أُرسل الشكر"، لذلك تحتاج finalize_success()/finalize_failure() هناك
 * إعادة أخذ القفل والتحقق من marker حيّ قبل أي كتابة على ذلك العمود. هنا لا
 * يوجد عمود مماثل — سجل pge_message_log نفسه (status) هو مصدر الحقيقة
 * الوحيد لحالة إرسال الدعوة، وPGE_Message_Log::mark_sent()/mark_failed()
 * ذريان أصلاً (WHERE id=%d AND status='pending') — فلا حاجة لقفل إضافي في
 * finalize_*() هنا، فقط تحقّق من هوية النوع قبل التفويض المباشر لتلك الطبقة.
 *
 * ============================================================================
 * النطاق (D2-W1 فقط)
 * ============================================================================
 * لا إرسال فعلي، لا Authorization (تسجيل actor_user_id كما وصل فقط، بلا أي
 * حكم عليه — التخويل الفعلي مسؤولية طبقة تطبيقية مستقبلية D2-W3)، لا AJAX،
 * لا UI، لا Queue/Worker، لا UltraMsg، لا إرسال جماعي (لكن claim() مصمَّمة
 * كي يُبنى الإرسال الجماعي فوقها لاحقاً عبر batch_id مشترك — راجع DEC-009).
 * لا Caller إنتاجي يستخدم هذه الطبقة بعد. تختبرها حصراً
 * tests/test-d2-w1-invitation-send-ledger.php.
 */
class PGE_Invitation_Send_Ledger
{
    /**
     * 120 ثانية — نفس القيمة المعتمدة حرفياً في PGE_Thank_You_Claim::
     * CLAIM_LEASE_SECONDS وInvitation Credit Ledger (اصطلاح قائم في المشروع،
     * لا قيمة جديدة مخترَعة لهذه المرحلة).
     */
    public const CLAIM_LEASE_SECONDS = 120;

    /** نيّة إرسال عادية: لا تُنشئ محاولة جديدة بعد نجاح سابق لنفس دورة الحياة (تعيد already_sent). */
    public const INTENT_NORMAL = 'normal';

    /** إعادة إرسال صريحة: قد تُنشئ محاولة جديدة حتى بعد نجاح سابق — سجل تاريخي جديد، لا تعديل على القديم. */
    public const INTENT_RESEND = 'resend';

    public const VALID_INTENTS = [self::INTENT_NORMAL, self::INTENT_RESEND];

    /**
     * نطاق القفل = هوية الضيف الدائمة فقط (event_id + هاتف مُطبَّع +
     * message_type=invitation) — عمداً **بلا** actor_user_id، بنفس حرفية
     * PGE_Thank_You_Claim::build_lock_name(). هذا هو أساس منع التزامن
     * الحقيقي: نفس المضيف من تبويبين، أو Owner وAdditional Inviter معاً على
     * نفس الضيف، يجب أن يتنافسا على نفس القفل بالضبط — راجع توثيق claim().
     */
    private static function build_lock_name(int $event_id, string $normalized_phone): string
    {
        return 'pge_invitation_send_' . md5($event_id . '|' . $normalized_phone . '|' . PGE_Message_Type::INVITATION);
    }

    private static function normalize_phone($value): string
    {
        return function_exists('pge_norm_phone')
            ? pge_norm_phone($value)
            : preg_replace('/\D+/', '', (string) $value);
    }

    private static function is_lease_expired($started_at): bool
    {
        if (!is_scalar($started_at) || trim((string) $started_at) === '') {
            return true;
        }

        $started_ts = strtotime(trim((string) $started_at) . ' UTC');
        $now_ts = strtotime(current_time('mysql', true) . ' UTC');
        if ($started_ts === false || $now_ts === false) {
            return true;
        }

        return ($now_ts - $started_ts) >= self::CLAIM_LEASE_SECONDS;
    }

    /**
     * تحقّق شكلي بحت من هوية الاستدعاء (بلا أي قراءة قاعدة بيانات) — يُستخدَم
     * لبناء اسم القفل قبل أخذه، وكفحص "fail closed" مبكر لمدخلات غير صالحة
     * بنيوياً (event_id/هاتف)، مستقل تماماً عن resolve_current_lifecycle()
     * أدناه (التي تقرأ من PGE_Invitation_Repository فعلياً).
     *
     * @return array{ok:bool,event_id?:int,phone?:string,reason?:string}
     */
    private static function validate_identity($event_id, $guest_phone_raw): array
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) {
            return ['ok' => false, 'reason' => 'invalid_event_id'];
        }

        $normalized_phone = self::normalize_phone($guest_phone_raw);
        if ($normalized_phone === '') {
            return ['ok' => false, 'reason' => 'invalid_phone'];
        }

        return ['ok' => true, 'event_id' => $event_id, 'phone' => $normalized_phone];
    }

    /**
     * دورة الحياة الحالية لدعوة (event_id + هاتف مُطبَّع مُتحقَّق منه شكلياً
     * بالفعل عبر validate_identity()) — قراءة فقط، بلا أي أثر جانبي. يعتمد
     * حصراً على PGE_Invitation_Repository::get_invitation() (طبقة الدعوة
     * الإدارية القائمة فعلاً، Phase 9) — راجع توثيق الملف أعلاه لسبب عدم
     * استخدام RSVP.created_at هنا.
     *
     * @return array{status:'found'|'not_found'|'invalid',lifecycle_started_at?:string,reason?:string}
     */
    private static function resolve_current_lifecycle(int $event_id, string $normalized_phone): array
    {
        if (!class_exists('PGE_Invitation_Repository')) {
            return ['status' => 'invalid', 'reason' => 'invitation_repository_unavailable'];
        }

        $invitation = PGE_Invitation_Repository::get_invitation($event_id, $normalized_phone);
        if (!is_array($invitation)) {
            // لا دعوة حالية إطلاقاً لهذا الهاتف — محذوفة ولم تُعَد إنشاؤها، أو
            // لم تُنشأ قط. Fail-closed: لا مطالبة/حالة بلا دعوة حالية حقيقية.
            return ['status' => 'not_found'];
        }

        $lifecycle_started_at = trim((string) ($invitation['invited_at'] ?? ''));
        if ($lifecycle_started_at === '') {
            return ['status' => 'invalid', 'reason' => 'lifecycle_marker_missing'];
        }

        return ['status' => 'found', 'lifecycle_started_at' => $lifecycle_started_at];
    }

    /**
     * سجلات دورة الحياة الحالية فقط (event_id + هاتف + invitation + مطابقة
     * lifecycle_started_at الحرفية) — سجلات دورة حياة أقدم تبقى تاريخية بلا
     * أي تأثير على القرار (نجاح دورة حياة سابقة لا يحجب دورة حياة مُعاد
     * إنشاؤها لاحقاً لنفس الهاتف).
     *
     * @return array<int,array>
     */
    private static function current_lifecycle_rows(int $event_id, string $normalized_phone, string $lifecycle_started_at): array
    {
        $all_rows = PGE_Message_Log::query_by_event_type_and_phone(
            $event_id,
            PGE_Message_Type::INVITATION,
            $normalized_phone
        );

        return array_values(array_filter($all_rows, function ($row) use ($lifecycle_started_at) {
            return (string) ($row['lifecycle_started_at'] ?? '') === $lifecycle_started_at;
        }));
    }

    /**
     * حالة الإرسال الحالية المُشتقّة من التاريخ (قراءة فقط، بلا Claim، بلا
     * قفل) — لا تُطابَق أبداً مع حالة رد RSVP (رد الضيف وحالة إرسال الدعوة
     * مفهومان منفصلان تماماً، لا علاقة بينهما هنا). مطابقة حالات
     * PGE_Message_Log الموجودة أصلاً — لا مفردات حالة موازية جديدة:
     *   sent                      → 'provider_accepted' (المزوّد قبل الرسالة
     *                                صراحة؛ لا يعني تأكيد استلام الجهاز فعلياً)
     *   pending                   → 'send_requested' (محاولة قيد التنفيذ)
     *   failed/ambiguous (كلاهما ضمن TERMINAL_FAILURE_STATUSES) → 'failed'
     *   لا سجلات لدورة الحياة الحالية → 'not_sent'
     *   لا دعوة حالية لهذا الهاتف إطلاقاً → 'not_found'
     *
     * ========================================================================
     * Fix Pass 1 (تصحيح): الحالة الحالية = أحدث محاولة فقط ضمن دورة الحياة
     * الحالية — لا أولوية "أي سجل sent يفوز" كما كانت النسخة الأولى.
     * ========================================================================
     * دفتر تاريخي بمحاولات متعددة (Resend صريح بعد نجاح، مثلاً) يعني أن سجل
     * 'sent' القديم قد لا يعود يعكس الحقيقة الحالية إن تلته محاولة resend لم
     * تُحسَم بعد أو انتهت بفشل. المصدر الوحيد الصحيح للحالة الحالية هو **أحدث
     * محاولة ضمن دورة الحياة الحالية فقط** (current_lifecycle_rows() تُرجِع
     * الصفوف مُرتَّبة id ASC — نفس ترتيب query_by_event_type_and_phone() في
     * الطبقة الأدنى — فآخر عنصر = أحدث معرّف = أحدث محاولة). محاولات أقدم ضمن
     * نفس دورة الحياة تبقى تاريخية قابلة للاستعلام (عبر
     * PGE_Message_Log::query_by_event_type_and_phone() مباشرة) لكن بلا أي
     * تأثير على current_state(). سجلات دورة حياة أقدم (invited_at مختلف) تبقى
     * مُستبعَدة بالكامل عبر current_lifecycle_rows()، بغضّ النظر عن ترتيب
     * المعرّفات — هذا الاستبعاد غير متأثر بهذا التصحيح.
     * أمثلة: [sent, pending] → الأحدث pending → 'send_requested' (لا
     * 'provider_accepted'). [sent, failed] → الأحدث failed → 'failed'.
     *
     * @return array{state:string,log_id?:?int,status?:?string,lifecycle_started_at?:string,reason?:string}
     */
    public static function current_state($event_id, $guest_phone): array
    {
        $identity = self::validate_identity($event_id, $guest_phone);
        if (!$identity['ok']) {
            return ['state' => 'invalid', 'reason' => $identity['reason']];
        }

        $resolved = self::resolve_current_lifecycle($identity['event_id'], $identity['phone']);
        if ($resolved['status'] === 'invalid') {
            return ['state' => 'invalid', 'reason' => $resolved['reason']];
        }
        if ($resolved['status'] === 'not_found') {
            return ['state' => 'not_found'];
        }

        $lifecycle_started_at = $resolved['lifecycle_started_at'];
        $rows = self::current_lifecycle_rows($identity['event_id'], $identity['phone'], $lifecycle_started_at);
        if (empty($rows)) {
            return ['state' => 'not_sent', 'lifecycle_started_at' => $lifecycle_started_at];
        }

        // أحدث محاولة وحدها تحدد الحالة الحالية (راجع توثيق Fix Pass 1 أعلاه).
        $latest = $rows[count($rows) - 1];
        $status = (string) ($latest['status'] ?? '');

        // خريطة حالة واحدة صريحة — بلا اختراع مفردات جديدة: تُعيد استخدام
        // ثوابت PGE_Message_Log::STATUS_* الموجودة أصلاً حرفياً كقيمة الحالة
        // لكل من sent/pending/failed/ambiguous (لا 'تغيير مفردات DB بلا داعٍ').
        switch ($status) {
            case PGE_Message_Log::STATUS_SENT:
                $state = 'provider_accepted';
                break;
            case PGE_Message_Log::STATUS_PENDING:
                $state = 'send_requested';
                break;
            case PGE_Message_Log::STATUS_FAILED:
                $state = 'failed';
                break;
            case PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR:
                // حالة مميَّزة صراحة عن 'failed' — "غامضة" وفق عقد
                // PGE_Message_Log نفسه (TERMINAL_FAILURE_STATUSES تضمّها مع
                // failed لأغراض القفل/الحصر فقط، لا لأغراض العرض هنا).
                $state = PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR;
                break;
            default:
                $state = 'not_sent';
                break;
        }

        return [
            'state' => $state,
            'log_id' => isset($latest['id']) ? (int) $latest['id'] : null,
            'status' => $status,
            'lifecycle_started_at' => $lifecycle_started_at,
        ];
    }

    /**
     * المطالبة الذرية الوحيدة بمحاولة إرسال دعوة جديدة. نطاق الحصر (Dedup)
     * = event_id + normalized_phone + message_type=invitation فقط — **ليس**
     * actor_user_id عمداً (راجع DEC-009 التحديث: نفس هدف الإرسال، بغضّ النظر
     * عمَّن يضغط الزر — Owner أو Additional Inviter، أو نفس الفاعل من تبويبين
     * متزامنين، يجب أن يتنافسا على نفس القفل ونفس سجل التتبّع؛ لا "ضمان تسليم
     * لمرة واحدة" فعلي يُدَّعى هنا، فقط منع تكرار محاولة نشطة). هذه الطبقة
     * **لا** تُخوِّل الفاعل (Authorization تبقى مسؤولية طبقة تطبيقية مستقبلية
     * D2-W3، غير موجودة بعد) — فقط تُسجِّل actor_user_id المُمرَّر كما هو، بلا
     * أي حكم/تمييز بين Owner وAdditional Inviter داخل هذه الطبقة إطلاقاً.
     *
     * @param int         $event_id
     * @param string      $guest_phone
     * @param int         $actor_user_id
     * @param string      $intent   self::INTENT_NORMAL (افتراضي) أو self::INTENT_RESEND.
     * @param string|null $batch_id مُمرَّر من المستدعي (لتجميع دفعة إرسال جماعي
     *                              فوق هذا الأساس، إرسال مفرد هو الأساس دوماً —
     *                              راجع DEC-009)، أو null لتوليد batch_id جديد
     *                              تلقائياً لمحاولة مفردة مستقلة.
     * @return array{result:string,reason?:string,log_id?:int,batch_id?:string,lifecycle_started_at?:string}
     *   result: 'claimed' | 'already_sent' | 'already_in_progress' | 'invalid_state' | 'error'
     */
    public static function claim($event_id, $guest_phone, $actor_user_id, $intent = self::INTENT_NORMAL, $batch_id = null): array
    {
        $intent = is_scalar($intent) ? (string) $intent : '';
        if (!in_array($intent, self::VALID_INTENTS, true)) {
            return ['result' => 'invalid_state', 'reason' => 'invalid_intent'];
        }

        $identity = self::validate_identity($event_id, $guest_phone);
        if (!$identity['ok']) {
            return ['result' => 'invalid_state', 'reason' => $identity['reason']];
        }

        $actor_user_id = max(0, (int) $actor_user_id);

        global $wpdb;
        $lock_name = self::build_lock_name($identity['event_id'], $identity['phone']);
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ((int) $got_lock !== 1) {
            return ['result' => 'error', 'reason' => 'lock_not_acquired'];
        }

        try {
            // إعادة القراءة الحقيقية تحدث **داخل** القفل — نفس فلسفة
            // PGE_Thank_You_Claim::claim() تماماً (لا اعتماد على أي حالة قُرئت
            // قبل أخذ القفل).
            $resolved = self::resolve_current_lifecycle($identity['event_id'], $identity['phone']);
            if ($resolved['status'] === 'invalid') {
                return ['result' => 'invalid_state', 'reason' => $resolved['reason']];
            }
            if ($resolved['status'] === 'not_found') {
                return ['result' => 'invalid_state', 'reason' => 'invitation_not_found'];
            }

            $lifecycle_started_at = $resolved['lifecycle_started_at'];
            $rows = self::current_lifecycle_rows($identity['event_id'], $identity['phone'], $lifecycle_started_at);

            // ========================================================================
            // Fix Pass 1: القرار يعتمد على **أحدث محاولة فقط** ضمن دورة الحياة
            // الحالية — بنفس منطق current_state() تماماً (راجع توثيقها أعلاه).
            // تحت الاستخدام الطبيعي عبر claim() فقط، لا يمكن لصف غير الأحدث أن
            // يبقى pending/ambiguous نشطاً: أي محاولة جديدة تُمنَع أصلاً ما دامت
            // محاولة سابقة pending نشطة (already_in_progress أدناه)، وأي pending
            // منتهي الـLease يُستعاد (Reclaim) فوراً هنا قبل إنشاء أي محاولة
            // تالية — فلا يبقى سوى الأحدث قابلاً لأن يكون غير نهائي (Terminal).
            // ========================================================================
            $latest = !empty($rows) ? $rows[count($rows) - 1] : null;
            $latest_status = $latest !== null ? (string) ($latest['status'] ?? '') : '';

            if ($latest_status === PGE_Message_Log::STATUS_SENT) {
                if ($intent === self::INTENT_NORMAL) {
                    return ['result' => 'already_sent', 'lifecycle_started_at' => $lifecycle_started_at];
                }
                // intent === resend: سجل النجاح الأحدث لا يُلمَس — يُتابَع
                // مباشرة لإنشاء محاولة تاريخية جديدة أدناه.
            } elseif ($latest_status === PGE_Message_Log::STATUS_PENDING
                || $latest_status === PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR) {
                if (!self::is_lease_expired($latest['created_at'] ?? null)) {
                    return [
                        'result' => 'already_in_progress',
                        'reason' => $latest_status === PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR
                            ? 'ambiguous_transport_lease_active'
                            : 'active_claim',
                        'lifecycle_started_at' => $lifecycle_started_at,
                    ];
                }

                // Pending متروكة (Lease منتهٍ) تُغلَق بحالة قائمة (failed) قبل
                // المتابعة — إعادة محاولة تقنية (Retry)، لا Resend جديد. سجل
                // Ambiguous منتهي الـLease نهائي أصلاً (Terminal)؛ يُترَك كما
                // هو ويُتجاوَز فقط (لا mark_failed() مكرَّر على سجل نهائي أصلاً).
                if ($latest_status === PGE_Message_Log::STATUS_PENDING
                    && !PGE_Message_Log::mark_failed((int) ($latest['id'] ?? 0))) {
                    return ['result' => 'error', 'reason' => 'stale_claim_close_failed'];
                }
            }
            // $latest_status === STATUS_FAILED (أو لا صفوف إطلاقاً بعد): إعادة
            // محاولة (Retry) مسموحة دوماً — تُتابَع مباشرة لإنشاء محاولة جديدة.

            $resolved_batch_id = is_scalar($batch_id) ? trim((string) $batch_id) : '';
            if ($resolved_batch_id === '') {
                $resolved_batch_id = PGE_Message_Batch::generate_batch_id();
            }

            $log_id = PGE_Message_Log::create_pending([
                'event_id'             => $identity['event_id'],
                'rsvp_id'              => null,
                'lifecycle_started_at' => $lifecycle_started_at,
                'guest_phone'          => $identity['phone'],
                'message_type'         => PGE_Message_Type::INVITATION,
                'batch_id'             => $resolved_batch_id,
                'actor_user_id'        => $actor_user_id,
            ]);

            if ($log_id === false) {
                return ['result' => 'error', 'reason' => 'log_create_failed'];
            }

            return [
                'result' => 'claimed',
                'log_id' => $log_id,
                'batch_id' => $resolved_batch_id,
                'lifecycle_started_at' => $lifecycle_started_at,
            ];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * إنهاء محاولة ناجحة: pending → sent، عبر التفويض المباشر لـ
     * PGE_Message_Log::mark_sent() (ذرية أصلاً بشرط status='pending' —
     * راجع توثيق الملف أعلاه لسبب عدم الحاجة لقفل/إعادة تحقّق إضافيين هنا،
     * خلافاً لـPGE_Thank_You_Claim::finalize_success()). يتحقّق فقط أن
     * السجل موجود وأن نوعه فعلاً invitation قبل التفويض — لا يفترض ذلك.
     *
     * @param int $log_id المعرّف المُعاد من claim() الناجحة (result=claimed).
     * @return bool
     */
    public static function finalize_success($log_id): bool
    {
        $log_id = (int) $log_id;
        if ($log_id <= 0) {
            return false;
        }

        $log = PGE_Message_Log::find_by_id($log_id);
        if (!$log || (string) ($log['message_type'] ?? '') !== PGE_Message_Type::INVITATION) {
            return false;
        }

        return PGE_Message_Log::mark_sent($log_id);
    }

    /**
     * إنهاء محاولة فاشلة: pending → failed | ambiguous_transport_error، عبر
     * التفويض المباشر لـPGE_Message_Log::mark_failed() (نفس منطق
     * finalize_success() أعلاه — لا قفل/إعادة تحقّق إضافيين مطلوبين).
     *
     * @param int    $log_id
     * @param string $status إحدى PGE_Message_Log::TERMINAL_FAILURE_STATUSES.
     * @return bool
     */
    public static function finalize_failure($log_id, $status = PGE_Message_Log::STATUS_FAILED): bool
    {
        $log_id = (int) $log_id;
        if ($log_id <= 0) {
            return false;
        }

        $log = PGE_Message_Log::find_by_id($log_id);
        if (!$log || (string) ($log['message_type'] ?? '') !== PGE_Message_Type::INVITATION) {
            return false;
        }

        return PGE_Message_Log::mark_failed($log_id, $status);
    }
}
