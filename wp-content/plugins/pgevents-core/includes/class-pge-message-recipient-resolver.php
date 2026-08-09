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
 * all) — العقد هنا عام مستقبلياً (message_type كمعامل) لكن لا استهلاك فعلي
 * لأي نوع آخر بعد.
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
     * @return array{recipients:array<int,array{phone:string,name:string,code:string}>,skipped_invalid_phone:int,filter:string,message_type:?string}
     */
    public static function resolve(int $event_id, string $message_type, string $filter): array
    {
        $normalized_type = PGE_Message_Type::normalize($message_type);
        $normalized_filter = self::normalize_filter($filter);

        if ($event_id <= 0 || $normalized_type === null) {
            return [
                'recipients'            => [],
                'skipped_invalid_phone' => 0,
                'filter'                => $normalized_filter,
                'message_type'          => $normalized_type,
            ];
        }

        $all_phones = function_exists('pge_get_invited_phones') ? pge_get_invited_phones($event_id) : [];
        $guests_map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
        $rsvp = function_exists('pge_event_guests_load_rsvp_from_db')
            ? pge_event_guests_load_rsvp_from_db($event_id)
            : ['map' => []];

        $seen = [];
        $recipients = [];
        $skipped_invalid_phone = 0;

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
            'filter'                => $normalized_filter,
            'message_type'          => $normalized_type,
        ];
    }

    /** عدد المستلمين فقط — تُستخدَم لمعاينة "عدد المستلمين المتوقَّع" (PART 22). */
    public static function count(int $event_id, string $message_type, string $filter): int
    {
        return count(self::resolve($event_id, $message_type, $filter)['recipients']);
    }
}
