<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Message Batch — Messaging Architecture Phase 2 (Foundation)
 * ============================================================================
 * Helper واحد صغير لتوليد batch_id: مُعرِّف قوي وعشوائي، جديد لكل عملية إرسال
 * مقصودة (Reminder أو Thank You لاحقاً)، لا يعتمد على timestamp وحده ولا على
 * event_id فقط، ولا يعرض أي secret. لا Queue هنا — Contract فقط (PART 8).
 *
 * يستخدم wp_generate_uuid4() (WordPress Core منذ 4.7، wp-includes/functions.php)
 * — لا Dependency جديدة، لا مكتبة UUID خارجية. عشوائي تماماً (UUID v4)، بلا
 * أي علاقة بالوقت أو بأي معرّف عمل آخر — يفي بكل الشروط الأربعة أعلاه بلا
 * حاجة لأي منطق إضافي.
 */
class PGE_Message_Batch
{
    /**
     * توليد batch_id جديد فريد لكل استدعاء (UUID v4).
     *
     * @return string
     */
    public static function generate_batch_id(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        // احتياط دفاعي فقط (لا يُتوقَّع تفعيله عملياً — wp_generate_uuid4()
        // متوفرة في كل بيئة ووردبريس مدعومة منذ 4.7): UUID v4 يدوي عبر
        // random_bytes(), بلا أي اعتماد على time()/event_id.
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
