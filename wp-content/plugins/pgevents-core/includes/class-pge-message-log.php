<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Message Log — Messaging Architecture Phase 2 (Foundation)
 * ============================================================================
 * طبقة Tracking عامة وحيدة لكل محاولة إرسال رسالة (أي message_type من
 * PGE_Message_Type::ALL) — Tracking فقط، بلا Business Logic إطلاقاً: لا
 * تعرف شيئاً عن Invitation Credits، Provider payloads، أو منطق القرار متى
 * تُرسَل رسالة. الوسيط الوحيد للقراءة/الكتابة على
 * {$wpdb->prefix}pge_message_log (راجع class-pge-messaging-schema.php للبنية).
 *
 * Privacy (راجع docs/MESSAGING-ARCHITECTURE.md §Phase 2 Privacy): لا تُخزَّن
 * أبداً في هذا الجدول: نص الرسالة، QR، رمز الدعوة، tokens، بيانات اعتماد
 * المزوّد، أو استجابة API الخام. guest_phone يُخزَّن بصيغته المُطبَّعة القياسية
 * (pge_norm_phone()) فقط — لا بيانات شخصية إضافية.
 *
 * لا Caller إنتاجي يستخدم هذه الطبقة بعد في Phase 2 — أساس معماري فقط،
 * تختبره حصرياً tests/test-messaging-phase2.php.
 */
class PGE_Message_Log
{
    /**
     * Status Contract (PART 7) — أصغر مجموعة تخدم Reminder/Thank You فعلياً:
     *   pending                    — أُنشئ سجل المحاولة، لم يُحسَم مصيرها بعد.
     *   sent                       — نجح الإرسال (Provider قبل الرسالة صراحة).
     *   failed                     — فشل صريح (Provider رفض الرسالة).
     *   ambiguous_transport_error  — خطأ نقل غامض (لا نعرف هل وصلت الرسالة
     *     فعلياً أم لا)، بنفس مفهوم transport_error القائم فعلياً في
     *     cron_process_queue() لمسار الدعوة الحالي — بلا تكرار منطق، فقط نفس
     *     الاسم الدلالي هنا.
     * لا 'duplicate_operation': ذلك مفهوم خاص بالـQueue (منع تنفيذ مضاعف)
     * وليس حالة رسالة بحد ذاتها — لا علاقة له بهذا الجدول.
     * لا 'already_sent': نتيجة Claim (راجع class-pge-thank-you-claim.php)
     * وليست حالة سجل — سجل لا يُنشأ أصلاً إن كانت الرسالة مُرسَلة سلفاً.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_AMBIGUOUS_TRANSPORT_ERROR = 'ambiguous_transport_error';

    const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_AMBIGUOUS_TRANSPORT_ERROR,
    ];

    /** حالات نهائية صالحة لإنهاء سجل pending (كل ما عدا pending نفسها). */
    const TERMINAL_FAILURE_STATUSES = [
        self::STATUS_FAILED,
        self::STATUS_AMBIGUOUS_TRANSPORT_ERROR,
    ];

    private static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_message_log';
    }

    /**
     * إنشاء سجل محاولة جديد بحالة 'pending'. يرفض صراحةً أي message_type غير
     * معروف (PART 6 — لا قائمة موازية جديدة، PGE_Message_Type هو مصدر الحقيقة
     * الوحيد). يرفض أيضاً event_id/batch_id غير صالحين.
     *
     * @param array{event_id:int,rsvp_id?:int|null,lifecycle_started_at?:string|null,guest_phone?:string,message_type:string,batch_id:string,provider?:string|null,actor_user_id?:int} $data
     * @return int|false معرّف السجل الجديد، أو false عند رفض/فشل.
     */
    public static function create_pending(array $data)
    {
        $event_id = isset($data['event_id']) ? (int) $data['event_id'] : 0;
        if ($event_id <= 0) {
            return false;
        }

        $normalized_type = PGE_Message_Type::normalize($data['message_type'] ?? null);
        if ($normalized_type === null) {
            return false;
        }

        $batch_id = isset($data['batch_id']) && is_scalar($data['batch_id']) ? trim((string) $data['batch_id']) : '';
        if ($batch_id === '') {
            return false;
        }

        $rsvp_id = null;
        if (array_key_exists('rsvp_id', $data) && $data['rsvp_id'] !== null && $data['rsvp_id'] !== '') {
            $rsvp_id = (int) $data['rsvp_id'];
            if ($rsvp_id <= 0) {
                $rsvp_id = null;
            }
        }

        $lifecycle_started_at = null;
        if (array_key_exists('lifecycle_started_at', $data) && is_scalar($data['lifecycle_started_at'])) {
            $candidate = trim((string) $data['lifecycle_started_at']);
            if ($candidate !== '') {
                $lifecycle_started_at = $candidate;
            }
        }

        $guest_phone_raw = $data['guest_phone'] ?? '';
        $guest_phone = function_exists('pge_norm_phone')
            ? pge_norm_phone($guest_phone_raw)
            : preg_replace('/\D+/', '', (string) $guest_phone_raw);

        $provider = null;
        if (array_key_exists('provider', $data) && $data['provider'] !== null && $data['provider'] !== '') {
            $provider = is_scalar($data['provider']) ? (string) $data['provider'] : null;
        }

        $actor_user_id = isset($data['actor_user_id']) ? max(0, (int) $data['actor_user_id']) : 0;

        global $wpdb;

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'event_id'      => $event_id,
                'rsvp_id'       => $rsvp_id,
                'lifecycle_started_at' => $lifecycle_started_at,
                'guest_phone'   => $guest_phone,
                'message_type'  => $normalized_type,
                'batch_id'      => $batch_id,
                'status'        => self::STATUS_PENDING,
                'provider'      => $provider,
                'actor_user_id' => $actor_user_id,
                'created_at'    => current_time('mysql', true),
                'sent_at'       => null,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if (!$inserted) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /** جلب سجل واحد بمعرّفه؛ قراءة فقط للاستخدام في fencing الخاص بالـClaim. */
    public static function find_by_id($log_id): ?array
    {
        $log_id = (int) $log_id;
        if ($log_id <= 0) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE id = %d",
            $log_id
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * سجلات Thank You لهوية Claim واحدة داخل lifecycle واحدة. لا قرار Lease
     * هنا؛ الطبقة تحفظ/تقرأ فقط، وPGE_Thank_You_Claim يملك Business Logic.
     *
     * @return array<int,array>
     */
    public static function query_claims_for_lifecycle($event_id, $rsvp_id, $lifecycle_started_at): array
    {
        $event_id = (int) $event_id;
        $rsvp_id = (int) $rsvp_id;
        $lifecycle_started_at = is_scalar($lifecycle_started_at) ? trim((string) $lifecycle_started_at) : '';
        if ($event_id <= 0 || $rsvp_id <= 0 || $lifecycle_started_at === '') {
            return [];
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE event_id = %d AND rsvp_id = %d AND message_type = %s AND lifecycle_started_at = %s ORDER BY id ASC",
            $event_id,
            $rsvp_id,
            PGE_Message_Type::THANK_YOU,
            $lifecycle_started_at
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * إنهاء سجل pending بنجاح: pending → sent، ذرياً (WHERE id=%d AND
     * status='pending' — لا يُنهي إلا سجلاً لا يزال pending فعلياً؛ استدعاء
     * مكرر على نفس السجل بعد أول نجاح يفشل بأمان (idempotent no-op)).
     *
     * @param int $log_id
     * @return bool
     */
    public static function mark_sent($log_id): bool
    {
        $log_id = (int) $log_id;
        if ($log_id <= 0) {
            return false;
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::table_name(),
            [
                'status'  => self::STATUS_SENT,
                'sent_at' => current_time('mysql', true),
            ],
            [
                'id'     => $log_id,
                'status' => self::STATUS_PENDING,
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * إنهاء سجل pending بفشل: pending → failed | ambiguous_transport_error،
     * ذرياً بنفس شرط mark_sent(). يرفض أي status غير ضمن
     * TERMINAL_FAILURE_STATUSES (لا 'sent' هنا — لذلك mark_sent() منفصلة).
     *
     * @param int    $log_id
     * @param string $status إحدى TERMINAL_FAILURE_STATUSES.
     * @return bool
     */
    public static function mark_failed($log_id, $status = self::STATUS_FAILED): bool
    {
        $log_id = (int) $log_id;
        if ($log_id <= 0) {
            return false;
        }

        if (!in_array($status, self::TERMINAL_FAILURE_STATUSES, true)) {
            return false;
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::table_name(),
            [
                'status' => $status,
            ],
            [
                'id'     => $log_id,
                'status' => self::STATUS_PENDING,
            ],
            ['%s'],
            ['%d', '%s']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * كل سجلات دفعة إرسال واحدة (batch_id)، الأقدم أولاً.
     *
     * @return array<int,array>
     */
    public static function query_by_batch($batch_id): array
    {
        $batch_id = is_scalar($batch_id) ? trim((string) $batch_id) : '';
        if ($batch_id === '') {
            return [];
        }

        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE batch_id = %s ORDER BY id ASC", $batch_id),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * كل سجلات مناسبة معيَّنة من نوع رسالة معيَّن، الأقدم أولاً. ترفض
     * message_type غير معروف (تعيد مصفوفة فارغة، لا استثناء).
     *
     * @return array<int,array>
     */
    public static function query_by_event_type($event_id, $message_type): array
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) {
            return [];
        }

        $normalized_type = PGE_Message_Type::normalize($message_type);
        if ($normalized_type === null) {
            return [];
        }

        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE event_id = %d AND message_type = %s ORDER BY id ASC",
                $event_id,
                $normalized_type
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }
}
