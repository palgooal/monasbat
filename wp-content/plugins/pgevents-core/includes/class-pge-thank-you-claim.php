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
 * سجل message_log نفسه (status='pending') كـGuard: طالما هناك سجل pending
 * لنفس rsvp_id من نوع thank_you، لا مطالبة جديدة تُقبَل؛ عند failure ينتقل
 * السجل من pending إلى failed (يُحرِّر الـGuard فوراً، يسمح بإعادة المحاولة)،
 * وthank_you_sent_at يبقى NULL طوال ذلك تماماً — بلا أي استثناء.
 *
 * القفل (GET_LOCK) يُستخدَم هنا فقط للحظة القِصار اللازمة لإنشاء سجل الـGuard
 * ذرياً (فحص + INSERT معاً) — نفس فلسفة PGE_Invitation_Credit_Ledger::
 * claim_for_delivery() ونفس نمط PGE_Checkin_Recorder::build_lock_name()
 * حرفياً. لا يُحمَل القفل عبر الإرسال الفعلي الخارجي (الذي قد يستغرق ثوانٍ) —
 * ذلك يحدث *بعد* release القفل هنا تماماً، بين claim() وfinalize_*()، بنفس
 * تصميم الـLedger (claim_for_delivery() ثم mark_consumed_with_token()/
 * mark_failed_with_token() بلا قفل، محمي بـstatus+معرّف السجل فقط).
 *
 * ============================================================================
 * قيد معروف مقبول صراحة لهذه المرحلة (Foundation فقط — راجع
 * docs/MESSAGING-ARCHITECTURE.md)
 * ============================================================================
 * إذا تعطَّلت العملية بعد claim() (سجل pending أُنشئ) ولم يُستدعَ finalize_*()
 * إطلاقاً (لا نجاح ولا فشل)، يبقى سجل الـGuard pending إلى الأبد ويمنع أي
 * claim() لاحق لنفس rsvp_id — بالضبط نفس فئة الخطر التي واجهها
 * PGE_Invitation_Credit_Ledger::claim_for_delivery() تاريخياً (حُلَّت لاحقاً
 * عبر آلية Lease مستقلة، بعد الشحن الأول). لا آلية Lease/Reclaim هنا بعد —
 * غير مطلوبة في Phase 2 (لا إرسال فعلي بعد يجعل هذا الخطر واقعياً)، وتُترَك
 * صراحةً لمرحلة تفعيل الإرسال الفعلي إن ثبتت الحاجة.
 */
class PGE_Thank_You_Claim
{
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

        if (self::is_sent($rsvp_id)) {
            return false;
        }

        return !self::has_active_claim($rsvp_id);
    }

    /**
     * هل يوجد سجل message_log بحالة pending لنوع thank_you لهذا rsvp_id
     * حالياً؟ (الـGuard الفعلي المستخدَم داخل claim()).
     */
    private static function has_active_claim($rsvp_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pge_message_log';

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE rsvp_id = %d AND message_type = %s AND status = %s",
            (int) $rsvp_id,
            PGE_Message_Type::THANK_YOU,
            PGE_Message_Log::STATUS_PENDING
        ));

        return $count > 0;
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
            $rsvp_row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, event_id, thank_you_sent_at FROM " . self::rsvps_table_name() . " WHERE id = %d",
                $rsvp_id
            ));

            if (!$rsvp_row || (int) $rsvp_row->event_id !== $event_id) {
                return ['result' => 'error', 'reason' => 'rsvp_not_found'];
            }

            if ($rsvp_row->thank_you_sent_at !== null && $rsvp_row->thank_you_sent_at !== '') {
                return ['result' => 'already_sent'];
            }

            if (self::has_active_claim($rsvp_id)) {
                return ['result' => 'already_in_progress'];
            }

            $log_id = PGE_Message_Log::create_pending([
                'event_id'      => $event_id,
                'rsvp_id'       => $rsvp_id,
                'guest_phone'   => $guest_phone,
                'message_type'  => PGE_Message_Type::THANK_YOU,
                'batch_id'      => $batch_id,
                'provider'      => $provider,
                'actor_user_id' => $actor_user_id,
            ]);

            if ($log_id === false) {
                return ['result' => 'error', 'reason' => 'log_create_failed'];
            }

            return ['result' => 'claimed', 'log_id' => $log_id];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * إنهاء محاولة ناجحة: سجل الـLog يتحول pending → sent، وthank_you_sent_at
     * يُكتَب الآن فقط (لأول مرة في دورة حياة هذه المحاولة) — ذرياً (WHERE
     * thank_you_sent_at IS NULL، دفاعياً إضافياً فوق ضمان الـGuard).
     *
     * @param int $log_id  المعرّف المُعاد من claim() الناجحة.
     * @param int $rsvp_id
     * @return bool
     */
    public static function finalize_success($log_id, $rsvp_id): bool
    {
        $rsvp_id = (int) $rsvp_id;
        if ($rsvp_id <= 0) {
            return false;
        }

        if (!PGE_Message_Log::mark_sent($log_id)) {
            return false;
        }

        global $wpdb;
        $updated = $wpdb->update(
            self::rsvps_table_name(),
            ['thank_you_sent_at' => current_time('mysql', true)],
            ['id' => $rsvp_id, 'thank_you_sent_at' => null],
            ['%s'],
            ['%d', '%s']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * إنهاء محاولة فاشلة: سجل الـLog يتحول pending → failed |
     * ambiguous_transport_error — يُحرِّر الـGuard فوراً فيسمح بإعادة
     * المحاولة عبر claim() جديدة. thank_you_sent_at لا يُلمَس إطلاقاً هنا.
     *
     * @param int    $log_id
     * @param string $status إحدى PGE_Message_Log::TERMINAL_FAILURE_STATUSES.
     * @return bool
     */
    public static function finalize_failure($log_id, $status = PGE_Message_Log::STATUS_FAILED): bool
    {
        return PGE_Message_Log::mark_failed($log_id, $status);
    }
}
