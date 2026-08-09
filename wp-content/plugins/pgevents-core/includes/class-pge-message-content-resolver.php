<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Message Content Resolver — Messaging Architecture Phase 1
 * ============================================================================
 * نقطة البناء الموحَّدة الوحيدة لمحتوى رسالة واتساب (نص + صورة اختيارية)،
 * بحسب message_type (راجع تقرير Phase 0 — Contract + Architecture). تحل
 * محل التكرار الثلاثي القائم فعلياً في class-cartat-handler.php
 * (send_invitations/ajax_test_send/cron_process_queue) لبناء رسالة الدعوة —
 * بلا أي تغيير في السلوك الناتج (راجع docs/MESSAGING-ARCHITECTURE.md).
 *
 * مسؤولية هذا الملف: CONTENT فقط (اختيار القالب + استبدال المتغيرات +
 * تحديد رابط الصورة). لا علاقة له إطلاقاً بـ: Invitation Credits، حالة
 * الـQueue، الـLedger، تفسير نتيجة المزوّد (Provider)، إعادة المحاولة، أو
 * السجلات (Logs) — تلك مسؤوليات الطبقات المستدعية وحدها، كما هي اليوم بلا
 * أي تغيير.
 *
 * لا يستدعي أي Provider، ولا PGE_Cartat_Transport، ولا أي شيء متعلق
 * بالإرسال الفعلي — دالة بناء محتوى بحتة (Pure-ish: القراءة الوحيدة من
 * خارج المعاملات هي get_post_meta عبر pge_wa_get_templates() والصورة
 * البارزة عند عدم تمرير image_url صراحةً في $context، تماماً كما تفعل
 * المواضع الثلاثة الحالية اليوم).
 */

class PGE_Message_Content_Resolver
{
    /**
     * بناء محتوى رسالة واحدة حسب النوع.
     *
     * @param string $message_type  قيمة من PGE_Message_Type::ALL (تُطبَّع
     *   عبر PGE_Message_Type::normalize()؛ قيمة غير صالحة أو غير معروفة
     *   تُعامَل كـ PGE_Message_Type::INVITATION دفاعياً — نفس القيمة
     *   الوحيدة المُستخدَمة فعلياً في كل الاستدعاءات الإنتاجية اليوم، فلا
     *   أثر عملي لهذا الـfallback حالياً).
     * @param int    $event_id
     * @param array  $context قيم السياق (guest_name, event_name, event_date,
     *   event_date_line, guest_phone, event_url, invite_code, location_line,
     *   image_url). كل مفتاح غائب يُعامَل كسلسلة فارغة عند التصيير (نفس
     *   `?? ''` المُستخدَم أصلاً في مواضع البناء الحالية)، باستثناء
     *   image_url لـinvitation: إن غاب المفتاح كلياً من $context (وليس
     *   فارغاً فقط) يُحسَب حديثاً عبر get_the_post_thumbnail_url() — لتُبقي
     *   استدعاءً مباشراً للـResolver بلا سياق صورة يعمل بشكل صحيح، بينما
     *   المواضع الثلاثة المُعاد بناؤها (Part 9) تمرّر image_url دائماً
     *   صراحةً (محسوبة مرة واحدة خارج حلقة المستلمين، تماماً كسلوكها الحالي
     *   — لا استدعاء إضافي لـget_the_post_thumbnail_url() لكل هاتف).
     *
     * @return array{text:string,image_url:?string}
     */
    public static function resolve(string $message_type, int $event_id, array $context = []): array
    {
        $type = PGE_Message_Type::normalize($message_type) ?? PGE_Message_Type::INVITATION;

        $templates = function_exists('pge_wa_get_templates') ? pge_wa_get_templates($event_id) : [];

        if ($type === PGE_Message_Type::REMINDER) {
            $tpl = $templates['reminder'] ?? (function_exists('pge_wa_default_reminder_template') ? pge_wa_default_reminder_template() : '');
            $vars = [
                'guest_name'      => (string) ($context['guest_name']      ?? ''),
                'event_name'      => (string) ($context['event_name']      ?? ''),
                'event_date'      => (string) ($context['event_date']      ?? ''),
                'event_date_line' => (string) ($context['event_date_line'] ?? ''),
                'guest_phone'     => (string) ($context['guest_phone']     ?? ''),
                'event_url'       => (string) ($context['event_url']       ?? ''),
                'invite_code'     => (string) ($context['invite_code']     ?? ''),
                'location_line'   => (string) ($context['location_line']   ?? ''),
            ];

            return [
                'text'      => function_exists('pge_wa_render_template') ? pge_wa_render_template($tpl, $vars) : $tpl,
                'image_url' => null, // Text only — قرار Phase 0 الصريح
            ];
        }

        if ($type === PGE_Message_Type::THANK_YOU) {
            $tpl = $templates['thank_you'] ?? (function_exists('pge_wa_default_thank_you_template') ? pge_wa_default_thank_you_template() : '');
            $vars = [
                'guest_name' => (string) ($context['guest_name'] ?? ''),
                'event_name' => (string) ($context['event_name'] ?? ''),
                'event_date' => (string) ($context['event_date'] ?? ''),
            ];

            return [
                'text'      => function_exists('pge_wa_render_template') ? pge_wa_render_template($tpl, $vars) : $tpl,
                'image_url' => null, // Text only — قرار Phase 0 الصريح
            ];
        }

        // PGE_Message_Type::INVITATION — يجب أن يُنتج بالضبط نفس النص/الصورة
        // المُنتَجَين اليوم في send_invitations()/ajax_test_send()/cron_process_queue().
        $tpl = $templates['invite'] ?? (function_exists('pge_wa_default_invite_template') ? pge_wa_default_invite_template() : '');
        $vars = [
            'guest_name'      => (string) ($context['guest_name']      ?? ''),
            'event_name'      => (string) ($context['event_name']      ?? ''),
            'event_date'      => (string) ($context['event_date']      ?? ''),
            'event_date_line' => (string) ($context['event_date_line'] ?? ''),
            'guest_phone'     => (string) ($context['guest_phone']     ?? ''),
        ];
        $text = function_exists('pge_wa_render_template') ? pge_wa_render_template($tpl, $vars) : $tpl;

        $image_url = array_key_exists('image_url', $context)
            ? (string) $context['image_url']
            : (string) get_the_post_thumbnail_url($event_id, 'full');

        return [
            'text'      => $text,
            'image_url' => $image_url !== '' ? $image_url : null,
        ];
    }
}
