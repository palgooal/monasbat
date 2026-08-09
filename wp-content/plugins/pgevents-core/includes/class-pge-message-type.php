<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Message Type — Messaging Architecture Phase 0/1
 * ============================================================================
 * العقد الوحيد لقيم message_type المسموحة في نظام الرسائل (راجع تقرير
 * Phase 0 — Contract + Architecture لنظام رسائل المناسبة). هذه طبقة تعريف
 * برمجي فقط، بلا أي منطق إرسال، بلا استعلام قاعدة بيانات — بنفس فلسفة
 * PGE_Feature_Registry (تعريف ثابت + دالة تحقق واحدة، لا أكثر).
 *
 * لماذا كلاس بثوابت لا PHP Enum: المشروع لا يفترض توفر PHP 8.1+ في بيئة
 * الإنتاج، وكل الأنماط المشابهة القائمة فعلاً (مثل ALLOWED_CREDIT_TYPES في
 * PGE_Invitation_Credit_Ledger) تستخدم ثوابت/مصفوفات ثابتة + دالة تطبيع
 * صريحة — لا Enum في أي مكان بالمشروع. نتبع نفس القرار الهندسي القائم، لا
 * نخترع نمطاً جديداً.
 *
 * Phase 1 (الحالية): message_type يُستخدَم داخلياً فقط في طبقة بناء المحتوى
 * (PGE_Message_Content_Resolver). لا Caller إنتاجي يستخدم قيمة غير
 * 'invitation' بعد — لا Reminder، لا Thank You فعلياً.
 */

class PGE_Message_Type
{
    const INVITATION = 'invitation';
    const REMINDER   = 'reminder';
    const THANK_YOU  = 'thank_you';

    /**
     * القيم الرسمية المسموحة، بنفس نمط ALLOWED_CREDIT_TYPES في الـLedger —
     * أي قيمة أخرى غير هذه الثلاث تُرفَض صراحةً في normalize().
     *
     * @var string[]
     */
    const ALL = [
        self::INVITATION,
        self::REMINDER,
        self::THANK_YOU,
    ];

    /**
     * تطبيع والتحقق من قيمة message_type — بنفس حرفية
     * PGE_Invitation_Credit_Ledger::normalize_credit_type(): trim ثم
     * strtolower ثم مطابقة تامة (in_array(...,true)) ضد self::ALL. لا
     * تخمين، لا Truthiness، لا مطابقة جزئية.
     *
     * @param mixed $value
     * @return string|null القيمة المطبَّعة إن كانت صالحة، أو null صراحةً
     *   لأي قيمة غير معروفة (بما في ذلك null/فارغ/نوع غير نصي).
     */
    public static function normalize($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, self::ALL, true) ? $normalized : null;
    }

    /**
     * هل القيمة المُمرَّرة (بعد التطبيع) قيمة message_type صالحة؟ غلاف
     * مريح حول normalize() لمواضع تحتاج bool فقط بلا القيمة المطبَّعة نفسها.
     *
     * @param mixed $value
     * @return bool
     */
    public static function is_valid($value): bool
    {
        return self::normalize($value) !== null;
    }
}
