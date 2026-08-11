<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Thank You Claim — Messaging Architecture Phase 2 (Foundation)
 * ============================================================================
 * الحد الأدنى المركزي للتحكم في thank_you_sent_at على {$wpdb->prefix}
 * pge_event_rsvps (راجع class-pge-messaging-schema.php للعمود). لا يرسل أي
 * رسالة — Contract + Idempotency فقط (PART 2/10/11). Caller مستقبلي (Phase
 * لاحقة) هو من يستدعي claim() قبل محاولة الإرسال الفعلي، ثم finalize_success()
 * أو finalize_failure() بعد معرفة نتيجة المزوّد.
 *
 * ============================================================================
 * عقد الحالة (PART 10) — ثابت وحاسم
 * ============================================================================
 *   thank_you_sent_at = NULL      → لم يُرسَل الشكر بعد (قابل لمحاولة جديدة).
 *   thank_you_sent_at != NULL     → أُرسل الشكر بنجاح مسبقاً، نهائياً.
 * لا قيمة وسيطة تعني "قيد الإرسال" — العمود لا يُكتَب إطلاقاً إلا عند
 * finalize_success() المؤكَّد. هذا يستبعد بنيوياً أن يبدو فشل نهائي كنجاح
 * (لن يحدث أن تُقرأ thank_you_sent_at غير فارغة لمحاولة فشلت أو لا تزال
 * جارية).
 *
 * ============================================================================
 * القرار المعماري: خيار B (In-progress guard منفصل)، لا خيار A
 * ============================================================================
 * قارَنّا بين:
 *   A) Claim مباشر عبر كتابة thank_you_sent_at فوراً عند بدء المحاولة، ثم
 *      Rollback (مسح القيمة) عند الفشل.
 *   B) Guard منفصل تماماً عن thank_you_sent_at أثناء "قيد التنفيذ"،
 *      وthank_you_sent_at لا يُكتَب إلا عند النجاح المؤكَّد فقط.
 * اخترنا (B): الخطر البنيوي الحقيقي في (A) هو أن أي انقطاع غير متوقَّع
 * (تعطّل العملية، انتهاء وقت الطلب، إلخ) بين "بدء المحاولة" و"Rollback" يترك
 * thank_you_sent_at مكتوبة رغم عدم إرسال أي رسالة فعلياً — يكذب العقد أعلاه
 * بشكل دائم لا يمكن اكتشافه لاحقاً بلا تدخّل يدوي. (B) يحتاج "غرفة انتظار"
 * (In-progress Guard) منفصلة لا تلمس العمود المُتعاقَد عليه إطلاقاً — استخدمنا
 * سجل message_log نفسه (status='pending') كـGuard، مربوطاً بـevent_id + rsvp_id
 * + RSVP.created_at الحالية. المطالبة الحديثة تمنع التوازي؛ المتروكة تتقادم
 * بعد Lease، وLifecycle أقدم لا تحجب Lifecycle جديدة تعيد استخدام rsvp_id.
 *
 * القفل (GET_LOCK) يُستخدَم هنا فقط للحظة القِصار اللازمة لإنشاء سجل الـGuard
 * ذرياً (فحص + INSERT معاً) — نفس فلسفة PGE_Invitation_Credit_Ledger::
 * claim_for_delivery() ونفس نمط PGE_Checkin_Recorder::build_lock_name()
 * حرفياً. لا يُحمَل القفل عبر الإرسال الفعلي الخارجي (الذي قد يستغرق ثوانٍ) —
 * ذلك يحدث *بعد* release القفل هنا تماماً، بين claim() وfinalize_*()، بنفس
 * `finalize_*()` تعيد أخذ القفل القصير وتتحقق من marker الحي قبل أي كتابة؛
 * لذلك لا يمكن لنتيجة متأخرة من Lifecycle أقدم أن تكتب على Current Snapshot.
 */
class PGE_Thank_You_Claim
{
    /**
     * 120s تطابق Attempt Lease المعتمدة في Invitation Credit Ledger، وتفوق
     * Cartat HTTP timeout (20s) بهامش واضح من دون تجميد المطالبة إلى الأبد.
     */
    public const CLAIM_LEASE_SECONDS = 120;

    private static function rsvps_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_event_rsvps';
    }

    /** بنفس حرفية PGE_Checkin_Recorder::build_lock_name(). */
    private static function build_lock_name(int $event_id, int $rsvp_id): string
    {
        return 'pge_thank_you_' . md5($event_id . '|' . $rsvp_id);
    }

    /** قراءة Current Snapshot المطلوبة للـClaim؛ لا lookup بالهاتف. */
    private static function find_rsvp($rsvp_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, event_id, guest_phone, created_at, thank_you_sent_at FROM " . self::rsvps_table_name() . " WHERE id = %d",
            (int) $rsvp_id
        ));
    }

    private static function lifecycle_marker($rsvp_row): string
    {
        return is_object($rsvp_row) && is_scalar($rsvp_row->created_at ?? null)
            ? trim((string) $rsvp_row->created_at)
            : '';
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

    private static function log_matches_lifecycle(array $log, $rsvp_row): bool
    {
        $marker = self::lifecycle_marker($rsvp_row);
        return $marker !== ''
            && (int) ($log['event_id'] ?? 0) === (int) ($rsvp_row->event_id ?? 0)
            && (int) ($log['rsvp_id'] ?? 0) === (int) ($rsvp_row->id ?? 0)
            && (string) ($log['message_type'] ?? '') === PGE_Message_Type::THANK_YOU
            && (string) ($log['lifecycle_started_at'] ?? '') === $marker;
    }

    /**
     * هل أُرسل الشكر بنجاح مسبقاً لهذا الصف؟ (thank_you_sent_at != NULL)
     *
     * @param int $rsvp_id
     * @return bool
     */
    public static function is_sent($rsvp_id): bool
    {
        $rsvp_id = (int) $rsvp_id;
        if ($rsvp_id <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::rsvps_table_name();

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT thank_you_sent_at FROM $table WHERE id = %d",
            $rsvp_id
        ));

        return $value !== null && $value !== '';
    }

    /**
     * هل يمكن البدء بمحاولة إرسال شكر جديدة الآن؟ (لم يُرسَل بعد، ولا محاولة
     * pending نشطة حالياً). فحص سريع غير ذري — للعرض/الفلترة فقط، لا يُعتمَد
     * عليه للحماية من التزامن (claim() هي الحارس الذري الحقيقي الوحيد).
     *
     * @param int $rsvp_id
     * @return bool
     */
    public static function can_send($rsvp_id): bool
    {
        $rsvp_id = (int) $rsvp_id;
        if ($rsvp_id <= 0) {
            return false;
        }

        $rsvp_row = self::find_rsvp($rsvp_id);
        if (!$rsvp_row || self::lifecycle_marker($rsvp_row) === '') {
            return false;
        }

        if ($rsvp_row->thank_you_sent_at !== null && $rsvp_row->thank_you_sent_at !== '') {
            return false;
        }

        return !self::has_active_claim($rsvp_row);
    }

    /**
     * هل يوجد سجل message_log بحالة pending لنوع thank_you لهذا rsvp_id
     * حالياً؟ (الـGuard الفعلي المستخدَم داخل claim()).
     */
    private static function has_active_claim($rsvp_row): bool
    {
        $claims = PGE_Message_Log::query_claims_for_lifecycle(
            (int) $rsvp_row->event_id,
            (int) $rsvp_row->id,
            self::lifecycle_marker($rsvp_row)
        );

        foreach ($claims as $claim) {
            $status = (string) ($claim['status'] ?? '');
            if (($status === PGE_Message_Log::STATUS_PENDING
                    || $status === PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR)
                && !self::is_lease_expired($claim['created_at'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * المطالبة الذرية الوحيدة بمحاولة إرسال شكر جديدة. لنفس (event_id +
     * rsvp_id)، لا يمكن لعمليتين متزامنتين النجاح كلتاهما — واحدة فقط تحصل
     * على 'claimed'، والأخرى تحصل على 'already_in_progress' أو 'already_sent'.
     *
     * لا يكتب thank_you_sent_at إطلاقاً هنا — فقط يُنشئ سجل message_log
     * بحالة pending (عبر PGE_Message_Log::create_pending()) كـGuard.
     *
     * @param int         $event_id
     * @param int         $rsvp_id
     * @param string      $guest_phone
     * @param string      $batch_id
     * @param int         $actor_user_id
     * @param string|null $provider
     * @return array{result:string,log_id?:int,reason?:string}
     *   result: 'claimed' | 'already_sent' | 'already_in_progress' | 'error'
     */
    public static function claim($event_id, $rsvp_id, $guest_phone, $batch_id, $actor_user_id = 0, $provider = null): array
    {
        $event_id = (int) $event_id;
        $rsvp_id = (int) $rsvp_id;

        if ($event_id <= 0) {
            return ['result' => 'error', 'reason' => 'invalid_event_id'];
        }
        if ($rsvp_id <= 0) {
            return ['result' => 'error', 'reason' => 'invalid_rsvp_id'];
        }

        $batch_id = is_scalar($batch_id) ? trim((string) $batch_id) : '';
        if ($batch_id === '') {
            return ['result' => 'error', 'reason' => 'invalid_batch_id'];
        }

        global $wpdb;
        $lock_name = self::build_lock_name($event_id, $rsvp_id);

        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ((int) $got_lock !== 1) {
            return ['result' => 'error', 'reason' => 'lock_not_acquired'];
        }

        try {
            // إعادة قراءة الحالة الحيّة **داخل** القفل — بـid، مع التأكد أن
            // الصف ينتمي فعلاً لنفس المناسبة المُمرَّرة (تكامل الاستدعاء).
            $rsvp_row = self::find_rsvp($rsvp_id);

            if (!$rsvp_row || (int) $rsvp_row->event_id !== $event_id) {
                return ['result' => 'error', 'reason' => 'rsvp_not_found'];
            }

            $lifecycle_started_at = self::lifecycle_marker($rsvp_row);
            if ($lifecycle_started_at === '') {
                return ['result' => 'error', 'reason' => 'lifecycle_marker_missing'];
            }

            if ($rsvp_row->thank_you_sent_at !== null && $rsvp_row->thank_you_sent_at !== '') {
                return ['result' => 'already_sent'];
            }

            $claims = PGE_Message_Log::query_claims_for_lifecycle($event_id, $rsvp_id, $lifecycle_started_at);
            foreach ($claims as $existing_claim) {
                $status = (string) ($existing_claim['status'] ?? '');
                if ($status !== PGE_Message_Log::STATUS_PENDING
                    && $status !== PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR) {
                    continue;
                }

                if (!self::is_lease_expired($existing_claim['created_at'] ?? null)) {
                    return [
                        'result' => 'already_in_progress',
                        'reason' => $status === PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR
                            ? 'ambiguous_transport_lease_active'
                            : 'active_claim',
                    ];
                }

                // Pending المتروكة تُغلق باستخدام status قائم. السجل الغامض
                // نهائي أصلاً؛ بعد انتهاء Lease يكفي تجاهله والسماح بمحاولة.
                if ($status === PGE_Message_Log::STATUS_PENDING
                    && !PGE_Message_Log::mark_failed((int) ($existing_claim['id'] ?? 0))) {
                    return ['result' => 'error', 'reason' => 'stale_claim_close_failed'];
                }
            }

            $log_id = PGE_Message_Log::create_pending([
                'event_id'      => $event_id,
                'rsvp_id'       => $rsvp_id,
                'lifecycle_started_at' => $lifecycle_started_at,
                'guest_phone'   => (string) ($rsvp_row->guest_phone ?? $guest_phone),
                'message_type'  => PGE_Message_Type::THANK_YOU,
                'batch_id'      => $batch_id,
                'provider'      => $provider,
                'actor_user_id' => $actor_user_id,
            ]);

            if ($log_id === false) {
                return ['result' => 'error', 'reason' => 'log_create_failed'];
            }

            return [
                'result' => 'claimed',
                'log_id' => $log_id,
                'lifecycle_started_at' => $lifecycle_started_at,
            ];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * إنهاء محاولة ناجحة: بعد fencing الـLifecycle، يُكتَب thank_you_sent_at
     * أولاً بشرط SQL يطابق marker وNULL، ثم يتحول الـLog pending → sent. ترتيب
     * الأمان مقصود: فشل تحديث الـLog بعد تثبيت العمود يمنع duplicate retry لأن
     * claim التالية ترى already_sent.
     *
     * @param int $log_id  المعرّف المُعاد من claim() الناجحة.
     * @param int $rsvp_id
     * @return bool
     */
    public static function finalize_success($log_id, $rsvp_id): bool
    {
        $log_id = (int) $log_id;
        $rsvp_id = (int) $rsvp_id;
        if ($log_id <= 0 || $rsvp_id <= 0) {
            return false;
        }

        $initial_log = PGE_Message_Log::find_by_id($log_id);
        if (!$initial_log || (int) ($initial_log['rsvp_id'] ?? 0) !== $rsvp_id) {
            return false;
        }

        global $wpdb;
        $event_id = (int) ($initial_log['event_id'] ?? 0);
        if ($event_id <= 0) {
            return false;
        }

        $lock_name = self::build_lock_name($event_id, $rsvp_id);
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ((int) $got_lock !== 1) {
            return false;
        }

        try {
            $log = PGE_Message_Log::find_by_id($log_id);
            $rsvp_row = self::find_rsvp($rsvp_id);
            if (!$log || !$rsvp_row
                || (string) ($log['status'] ?? '') !== PGE_Message_Log::STATUS_PENDING
                || !self::log_matches_lifecycle($log, $rsvp_row)) {
                return false;
            }

            if ($rsvp_row->thank_you_sent_at !== null && $rsvp_row->thank_you_sent_at !== '') {
                return false;
            }

            // شرط lifecycle_started_at على RSVP هو الـfence الذري ضد reset
            // متزامن أو finalize متأخر من lifecycle أقدم.
            $updated = $wpdb->update(
                self::rsvps_table_name(),
                ['thank_you_sent_at' => current_time('mysql', true)],
                [
                    'id' => $rsvp_id,
                    'event_id' => $event_id,
                    'created_at' => (string) $log['lifecycle_started_at'],
                    'thank_you_sent_at' => null,
                ],
                ['%s'],
                ['%d', '%d', '%s', '%s']
            );

            if ($updated === false || $updated === 0) {
                return false;
            }

            // إن تعذّر تحديث الـLog بعد تثبيت النجاح، تبقى علامة RSVP هي
            // الحارس الأقوى: أي claim لاحقة ستعيد already_sent لا إعادة إرسال.
            return PGE_Message_Log::mark_sent($log_id);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * إنهاء محاولة فاشلة: سجل الـLog يتحول pending → failed |
     * ambiguous_transport_error. failed يسمح بإعادة فورية؛ ambiguous يبقى
     * حاجزاً زمنياً حتى نهاية Lease لتجنب duplicate retry. لا يُلمَس
     * thank_you_sent_at إطلاقاً هنا.
     *
     * @param int    $log_id
     * @param string $status إحدى PGE_Message_Log::TERMINAL_FAILURE_STATUSES.
     * @return bool
     */
    public static function finalize_failure($log_id, $status = PGE_Message_Log::STATUS_FAILED): bool
    {
        $log_id = (int) $log_id;
        $log = PGE_Message_Log::find_by_id($log_id);
        if (!$log || (string) ($log['status'] ?? '') !== PGE_Message_Log::STATUS_PENDING) {
            return false;
        }

        $event_id = (int) ($log['event_id'] ?? 0);
        $rsvp_id = (int) ($log['rsvp_id'] ?? 0);
        if ($event_id <= 0 || $rsvp_id <= 0) {
            return false;
        }

        global $wpdb;
        $lock_name = self::build_lock_name($event_id, $rsvp_id);
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ((int) $got_lock !== 1) {
            return false;
        }

        try {
            $log = PGE_Message_Log::find_by_id($log_id);
            $rsvp_row = self::find_rsvp($rsvp_id);
            if (!$log || !$rsvp_row
                || (string) ($log['status'] ?? '') !== PGE_Message_Log::STATUS_PENDING
                || !self::log_matches_lifecycle($log, $rsvp_row)) {
                return false;
            }

            return PGE_Message_Log::mark_failed($log_id, $status);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}
