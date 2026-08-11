<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Message Recipient Resolver — Messaging Architecture Phase 3
 * ============================================================================
 * أول استخدام فعلي لمفهوم "Recipient Resolver" في نظام الرسائل (راجع Phase 0
 * Contract). مسؤولية هذا الملف حصراً: تحديد *من* سيستلم رسالة من نوع
 * ومناسبة/فلتر معيَّنين — لا Provider logic (لا Cartat/UltraMsg هنا)، لا UI
 * logic، لا كتابة على أي جدول/Log، لا قرار إرسال. قراءة فقط بالكامل.
 *
 * مصادر الحقيقة المُعاد استخدامها حرفياً (بلا أي منطق SQL/تطبيع جديد):
 *  - pge_get_invited_phones($event_id): قائمة أرقام المدعوين الحاليين
 *    المطبَّعة (helpers.php) — نفس المصدر المُستخدَم في send_invitations().
 *  - pge_event_guests_get_map($event_id): بيانات الاسم/الرمز لكل هاتف
 *    (event-guests.php).
 *  - pge_event_guests_load_rsvp_from_db($event_id): خريطة reply لكل هاتف من
 *    الجدول الحقيقي wp_pge_event_rsvps (event-guests.php، مع static cache
 *    لكل event_id لكل طلب — لا استعلام مكرَّر). تعريف "pending" هنا هو
 *    *بالضبط* نفس التعريف المُستخدَم فعلاً في pge_event_guests_get_row_payload()
 *    لعرض عمود "حالة الرد" في /event-manage/{id}/invitations/:
 *        $status = ($reply === 'yes' || $reply === 'no') ? $reply : 'pending';
 *    أي: لا صف RSVP إطلاقاً، أو صف موجود بقيمة reply غير yes/no — كلاهما
 *    "pending". لا علاقة لـcheck-in بهذا الفلتر إطلاقاً (PART 2 من تكليف
 *    Phase 3 — Check-in ليس له علاقة بفلتر Reminder).
 *
 * Phase 3 الحالية: message_type مدعوم فعلياً هو REMINDER فقط (الفلاتر: pending/
 * all). Phase 4B-1 تضيف Thank You eligibility read-only: دعوة حالية نشطة +
 * canonical RSVP سليمة من Lifecycle الحالية + checked_in=1. لا Claim ولا
 * once-only decision ولا كتابة في هذه الطبقة.
 */
class PGE_Message_Recipient_Resolver
{
    const FILTER_PENDING = 'pending';
    const FILTER_ALL = 'all';

    const ALLOWED_FILTERS = [
        self::FILTER_PENDING,
        self::FILTER_ALL,
    ];

    /**
     * تطبيع قيمة الفلتر — قيمة غير معروفة تُعامَل كـ FILTER_PENDING دفاعياً
     * (نفس فلسفة PGE_Message_Content_Resolver مع message_type غير صالح) —
     * الافتراضي الأكثر أماناً لـReminder هو "من لم يرد" لا "الجميع".
     */
    public static function normalize_filter($value): string
    {
        $normalized = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        return in_array($normalized, self::ALLOWED_FILTERS, true) ? $normalized : self::FILTER_PENDING;
    }

    /**
     * حل قائمة المستلمين لمناسبة + نوع رسالة + فلتر.
     *
     * Reminder and Thank You intentionally return message-specific summary
     * fields; callers must consume the contract for the requested type.
     *
     * @return array<string,mixed>
     */
    public static function resolve(int $event_id, string $message_type, string $filter): array
    {
        $normalized_type = PGE_Message_Type::normalize($message_type);
        $normalized_filter = self::normalize_filter($filter);

        if ($event_id <= 0 || $normalized_type === null) {
            return [
                'recipients'            => [],
                'skipped_invalid_phone' => 0,
                'skipped_integrity_error' => 0,
                'filter'                => $normalized_filter,
                'message_type'          => $normalized_type,
            ];
        }

        if ($normalized_type === PGE_Message_Type::THANK_YOU) {
            return self::resolve_thank_you($event_id);
        }

        $all_phones = function_exists('pge_get_invited_phones') ? pge_get_invited_phones($event_id) : [];
        $guests_map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
        $rsvp = function_exists('pge_event_guests_load_rsvp_from_db')
            ? pge_event_guests_load_rsvp_from_db($event_id)
            : ['map' => []];

        $seen = [];
        $recipients = [];
        $skipped_invalid_phone = 0;
        $skipped_integrity_error = 0;

        foreach ((array) $all_phones as $raw_phone) {
            $norm_phone = function_exists('pge_norm_phone')
                ? pge_norm_phone($raw_phone)
                : preg_replace('/\D+/', '', (string) $raw_phone);

            if ($norm_phone === '') {
                $skipped_invalid_phone++;
                continue;
            }

            // PART 3 — "all": لا نرسل مرتين لنفس رقم الهاتف داخل نفس العملية،
            // حتى لو كانت بنية المدعوين نفسها لا تحوي تكراراً أصلاً (لا نفترض
            // ذلك — الـResolver آمن بذاته).
            if (isset($seen[$norm_phone])) {
                continue;
            }

            if (!empty($rsvp['integrity_errors'][$norm_phone])) {
                $seen[$norm_phone] = true;
                $skipped_integrity_error++;
                continue;
            }

            if ($normalized_filter === self::FILTER_PENDING) {
                $reply = isset($rsvp['map'][$norm_phone]) ? (string) $rsvp['map'][$norm_phone] : '';
                $status = ($reply === 'yes' || $reply === 'no') ? $reply : 'pending';
                if ($status !== 'pending') {
                    continue;
                }
            }

            $seen[$norm_phone] = true;
            $recipients[] = [
                'phone' => $norm_phone,
                'name'  => (string) ($guests_map[$norm_phone]['name'] ?? ''),
                'code'  => (string) ($guests_map[$norm_phone]['code'] ?? ''),
            ];
        }

        return [
            'recipients'            => $recipients,
            'skipped_invalid_phone' => $skipped_invalid_phone,
            'skipped_integrity_error' => $skipped_integrity_error,
            'filter'                => $normalized_filter,
            'message_type'          => $normalized_type,
        ];
    }

    /**
     * Thank You eligibility فقط. تبدأ القراءة من guest map الحالية، لا من
     * صفوف RSVP، حتى لا تتسرّب orphan rows بعد Hard Delete/Phone Change.
     * thank_you_sent_at لا يُفحَص هنا؛ PGE_Thank_You_Claim هي once-only
     * authority الوحيدة في المرحلة اللاحقة.
     *
     * @return array{recipients:array<int,array{phone:string,rsvp_id:int,name:string}>,total_current_invitations:int,eligible:int,skipped_invalid_phone:int,skipped_cancelled:int,skipped_not_checked_in:int,skipped_no_rsvp:int,skipped_integrity_error:int,skipped_stale_lifecycle:int,filter:string,message_type:string}
     */
    private static function resolve_thank_you(int $event_id): array
    {
        $guests_map = function_exists('pge_event_guests_get_map')
            ? (array) pge_event_guests_get_map($event_id)
            : [];

        $summary = [
            'recipients'                 => [],
            'total_current_invitations'  => 0,
            'eligible'                   => 0,
            'skipped_invalid_phone'      => 0,
            'skipped_cancelled'          => 0,
            'skipped_not_checked_in'     => 0,
            'skipped_no_rsvp'            => 0,
            'skipped_integrity_error'    => 0,
            'skipped_stale_lifecycle'    => 0,
            'filter'                     => 'checked_in',
            'message_type'               => PGE_Message_Type::THANK_YOU,
        ];

        $seen = [];
        foreach ($guests_map as $map_phone => $guest) {
            $raw_phone = is_array($guest) ? ($guest['phone'] ?? $map_phone) : $map_phone;
            $phone = function_exists('pge_norm_phone')
                ? pge_norm_phone($raw_phone)
                : preg_replace('/\D+/', '', (string) $raw_phone);

            if ($phone === '') {
                $summary['skipped_invalid_phone']++;
                continue;
            }
            if (isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $summary['total_current_invitations']++;

            if (!class_exists('PGE_Invitation_Repository')) {
                $summary['skipped_stale_lifecycle']++;
                continue;
            }

            $invitation = PGE_Invitation_Repository::get_invitation($event_id, $phone);
            if (!is_array($invitation)) {
                $summary['skipped_stale_lifecycle']++;
                continue;
            }
            if ((string) ($invitation['invitation_status'] ?? 'active') === 'cancelled') {
                $summary['skipped_cancelled']++;
                continue;
            }

            if (!function_exists('pge_rsvp_find_canonical_by_phone')) {
                $summary['skipped_no_rsvp']++;
                continue;
            }

            $lookup = pge_rsvp_find_canonical_by_phone($event_id, $phone);
            $lookup_status = (string) ($lookup['status'] ?? 'integrity_error');
            if ($lookup_status === 'integrity_error') {
                $summary['skipped_integrity_error']++;
                continue;
            }
            if ($lookup_status !== 'found' || !is_object($lookup['row'] ?? null)) {
                $summary['skipped_no_rsvp']++;
                continue;
            }

            $row = $lookup['row'];
            $rsvp_id = (int) ($row->id ?? 0);
            if ($rsvp_id <= 0) {
                $summary['skipped_no_rsvp']++;
                continue;
            }

            if (!PGE_Invitation_Repository::is_rsvp_row_current($event_id, $phone, $row->created_at ?? null)) {
                $summary['skipped_stale_lifecycle']++;
                continue;
            }

            if ((int) ($row->checked_in ?? 0) !== 1) {
                $summary['skipped_not_checked_in']++;
                continue;
            }

            $summary['recipients'][] = [
                'phone'   => $phone,
                'rsvp_id' => $rsvp_id,
                'name'    => is_array($guest) ? (string) ($guest['name'] ?? '') : '',
            ];
            $summary['eligible']++;
        }

        return $summary;
    }

    /** عدد المستلمين فقط — تُستخدَم لمعاينة "عدد المستلمين المتوقَّع" (PART 22). */
    public static function count(int $event_id, string $message_type, string $filter): int
    {
        return count(self::resolve($event_id, $message_type, $filter)['recipients']);
    }
}
