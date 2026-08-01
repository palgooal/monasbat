<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Cartat Transport — Supervisor Invitation Delivery via Cartat، تنفيذ
 * ============================================================================
 * "Architecture Choice: Implement Option B: Extract a minimal shared Cartat
 * transport... It must handle only: Loading existing Cartat credentials,
 * Phone normalization/formatting, HTTP transport, Text sending, Media
 * sending, Provider-result interpretation, Safe non-sensitive diagnostics."
 *
 * قبل هذا الملف كانت طبقة النقل (قراءة الاعتماد، بناء رقم واتساب، تنفيذ
 * الطلب، تفسير النتيجة) مُطعَّمة بالكامل كدوال `private` داخل
 * Mon_Cartat_Handler (إرسال دعوات الضيوف حصراً) — لا مُرسِل عام قابل لإعادة
 * الاستخدام. هذا الملف هو الاستخراج الحرفي لتلك الدوال الأربع + دالة تفسير
 * النتيجة، بلا أي تغيير في منطقها الداخلي (نفس الـEndpoint، نفس الـHeaders،
 * نفس timeout=20، نفس منطق تفسير status===error/success===false)، لتصبح
 * قابلة للاستهلاك من أي مستهلك آخر — بدايةً بـ PGE_Supervisor_Invitation_
 * Delivery (دعوات المشرفين).
 *
 * ما لا يحتويه هذا الملف عمداً (نفس القيد الصريح في التكليف): لا تركيب نص
 * دعوة الضيف، لا تركيب نص دعوة المشرف، لا منطق RSVP، لا دورة حياة إسناد، لا
 * إنشاء جلسة، لا دلالات تدقيق (Audit) — كل ذلك يبقى في مستهلكي هذه الطبقة،
 * لا فيها.
 *
 * الإعدادات: نفس مفاتيح wp_options الحالية حصراً — `pge_cartat_api_token`،
 * `pge_cartat_country_code`. لا حقول جديدة، لا إعدادات خاصة بالمشرفين.
 *
 * بعد هذا الاستخراج: هذا هو تطبيق النقل الوحيد لـCartat في المشروع بالكامل —
 * Mon_Cartat_Handler يُفوِّض إليه داخلياً (لا نسخة موازية من نفس المنطق).
 */
class PGE_Cartat_Transport
{
    private string $api_token;
    private string $api_base = 'https://api.cartat.net';
    private string $country_code;

    public function __construct()
    {
        $this->api_token    = (string) get_option('pge_cartat_api_token', '');
        $this->country_code = (string) get_option('pge_cartat_country_code', '966');
    }

    /**
     * هل الاعتماد الأساسي (API Token) مضبوط؟ — نفس فحص
     * `empty($this->api_token)` الذي كان مُكرَّراً في ثلاثة مواضع داخل
     * Mon_Cartat_Handler سابقاً (ajax_test_send/handle_send_invitations_ajax/
     * ajax_queue_start)، الآن نقطة واحدة.
     */
    public function has_credentials(): bool
    {
        return $this->api_token !== '';
    }

    /**
     * تحويل رقم الجوال إلى صيغة واتساب الدولية (966XXXXXXXXX) — نفس منطق
     * format_wa_number() الأصلي حرفياً، بلا أي تغيير.
     */
    public function format_number(string $phone): string
    {
        $phone = pge_norm_phone($phone); // أرقام فقط

        // 00XXXXXXXXX → الرقم يحمل كود الدولة بعد الـ 00
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }
        // 0XXXXXXXXX → رقم محلي، أضف كود الدولة بدل الصفر
        elseif (str_starts_with($phone, '0')) {
            $phone = $this->country_code . substr($phone, 1);
        }
        // رقم قصير (أقل من 10) بدون كود دولة → أضفه
        elseif (strlen($phone) < 10) {
            $phone = $this->country_code . $phone;
        }
        // رقم ≥ 10 أرقام لا يبدأ بـ 0 → كود الدولة موجود مسبقاً (972, 966, 962...)

        return $phone;
    }

    /** إرسال رسالة نصية — تدعم نصاً عادياً يحوي رابطاً بلا أي قيد على المحتوى. */
    public function send_text(string $number, string $message): ?array
    {
        return $this->request('/message/text', [
            'number'  => $number,
            'message' => $message,
        ]);
    }

    /** إرسال رسالة وسائط (صورة + تسمية توضيحية) — تُستخدَم لدعوات الضيوف فقط اليوم. */
    public function send_media(string $number, string $media_url, string $caption = ''): ?array
    {
        return $this->request('/message/media', [
            'number'    => $number,
            'media_url' => $media_url,
            'caption'   => $caption,
        ]);
    }

    /**
     * تفسير موحَّد لنتيجة request() — نفس interpret_cartat_result() الأصلية
     * حرفياً (Invitation Credits Engine، المرحلة الثالثة A):
     *  - 'transport_error': $result === null — لم تصل أي استجابة JSON مفهومة.
     *  - 'rejected': استجابة JSON فعلية لكن برفض صريح (status==='error' أو
     *    success===false).
     *  - 'accepted': أي استجابة أخرى (status=queued/sent/success أو غياب
     *    الحقلين كليّاً) — تطابق تماماً ما كان يُعتبَر نجاحاً ضمنياً سابقاً.
     */
    public function interpret_result($result): string
    {
        if ($result === null) {
            return 'transport_error';
        }

        if (
            (isset($result['status']) && $result['status'] === 'error')
            || (isset($result['success']) && $result['success'] === false)
        ) {
            return 'rejected';
        }

        return 'accepted';
    }

    /**
     * تنفيذ الطلب الفعلي عبر wp_remote_post() — نفس api_request() الأصلية
     * حرفياً (نفس الـHeaders، نفس timeout=20، نفس httpversion='1.1' لتجنّب
     * مشاكل HTTP/2، نفس sslverify=true). التشخيص عبر error_log() هنا "غير
     * حسّاس" بالتصميم: يسجّل الـEndpoint والاستجابة المفكوكة (JSON) فقط — لا
     * الـAuthorization header ولا أي بيانات اعتماد إطلاقاً، بنفس ما كان عليه
     * الكود الأصلي تماماً.
     */
    private function request(string $endpoint, array $body): ?array
    {
        $response = wp_remote_post($this->api_base . $endpoint, [
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_token,
                'Expect'        => '', // منع Expect: 100-continue الذي يُعلّق الاتصال
            ],
            'body'        => wp_json_encode($body),
            'timeout'     => 20,
            'httpversion' => '1.1',  // تجنب مشاكل HTTP/2
            'sslverify'   => true,
        ]);

        if (is_wp_error($response)) {
            error_log('❌ Cartat API Error: ' . $response->get_error_message());
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        error_log('📤 Cartat API Response [' . $endpoint . ']: ' . json_encode($decoded));
        return $decoded;
    }
}
