<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Login Delivery — Supervisor Login Architecture
 * (Post-Activation Login) RFC، تنفيذ
 * ============================================================================
 * الطبقة الوحيدة المسموح لها بمعرفة وجود Cartat لغرض توصيل رابط دخول — تماماً
 * كما أن PGE_Supervisor_Invitation_Delivery هي الطبقة الوحيدة لتوصيل رابط
 * دعوة عبر Cartat. **PGE_Supervisor_Login_Service (توليد/التزام التوكن) لا
 * تعرف بوجود هذا الملف ولا بوجود Cartat إطلاقاً** — هذا الملف يستدعيها فقط،
 * لا العكس.
 *
 * ============================================================================
 * قرار Option A هنا (وليس Option B كما في تسليم دعوة Cartat) — قرار مقصود، موثَّق
 * ============================================================================
 * PGE_Supervisor_Invitation_Delivery::deliver() يُطبِّق Option B: لا يُستبدَل
 * هاش التوكن إلا بعد أن يقبل Cartat الرسالة فعلياً، فيبقى التوكن القديم
 * سارياً عند فشل الإرسال. هذا الملف **لا يُطبِّق نفس الترتيب** — يستدعي
 * PGE_Supervisor_Login_Service::generate() أولاً (والتي تلتزم فوراً، Option A،
 * راجع توثيقها) ثم يحاول الإرسال عبر Cartat بالرابط الناتج. إن فشل الإرسال،
 * **التوكن الجديد يبقى ملتزَماً فعلياً** (لم يعد التوكن القديم صالحاً) —
 * الفرق الجوهري عن تسليم الدعوة.
 *
 * السبب: نص RFC لتوكن الدخول لا يشترط صراحة "لا إبطال قبل قبول التسليم"
 * (بخلاف RFC تسليم الدعوة الذي فرض ذلك حرفياً)، بل ينص ببساطة "Each generation
 * invalidates the previous login token" بلا أي شرط على نجاح التسليم — وهذه
 * الطبقة تُطبِّق ذلك حرفياً: التوليد (وبالتالي التدوير) يحدث بصرف النظر عن
 * نتيجة محاولة الإرسال اللاحقة. الأثر العملي عند فشل Cartat: المضيف يرى
 * رسالة "تم توليد الرابط، لكن تعذّر إرسال واتساب" ويمكنه استخدام "نسخ رابط
 * الدخول" (نفس الرابط بالضبط، لأنه بالفعل مُلتزَم) لإرساله يدوياً — لا حاجة
 * لتوليد رابط ثانٍ. هذا موثَّق أيضاً في docs/SUPERVISOR-LOGIN-LIFECYCLE.md
 * وفي "المخاطر" بالتقرير النهائي لهذا التنفيذ كقرار تصميم واعٍ قابل للمراجعة.
 *
 * ============================================================================
 * التدقيق
 * ============================================================================
 * لا حدث تدقيق إضافي هنا خاص بمحاولة الإرسال نفسها (بخلاف تسليم الدعوة الذي
 * يملك دورة delivery_requested/attempted/provider_accepted/delivery_failed
 * الغنية) — نص RFC لتوكن الدخول يذكر فقط 'login_link_generated' كحدث تدقيق
 * توليد واحد (تُكتَب فعلياً داخل PGE_Supervisor_Login_Service::generate() عند
 * الالتزام الناجح، بصرف النظر عن نتيجة الإرسال اللاحقة هنا) — لا اختراع حدث
 * تدقيق ثانٍ غير مطلوب صراحةً.
 */
class PGE_Supervisor_Login_Delivery
{
    /**
     * النص العربي المعتمَد لرسالة رابط الدخول — منفصل تماماً عن نص رسالة
     * الدعوة (PGE_Supervisor_Invitation_Delivery::build_message()، غير
     * مُعدَّلة هنا بأي شكل).
     */
    private static function build_message(string $event_name, string $login_url): string
    {
        $safe_event_name = sanitize_text_field($event_name);
        if ($safe_event_name === '') {
            $safe_event_name = 'المناسبة';
        }

        return "رابط تسجيل دخولك كمشرف دخول لمناسبة \"{$safe_event_name}\".\n"
            . "اضغط الرابط التالي للدخول إلى بوابة تسجيل الحضور:\n"
            . "{$login_url}\n"
            . "هذا الرابط خاص بك، ويُبطَل تلقائياً عند طلب رابط دخول جديد.";
    }

    /**
     * توليد رابط دخول جديد ومحاولة إرساله عبر Cartat. التوكن يُلتزَم فعلياً
     * (عبر PGE_Supervisor_Login_Service::generate()) **قبل** محاولة الإرسال
     * وبصرف النظر عن نتيجتها (راجع توثيق Option A أعلى الملف).
     *
     * @return array{result:string, reason?:string, status?:string, id?:int, login_url?:string}
     *   'sent'                      — تولَّد الرابط والتُزِم فعلياً، وقَبِل
     *                                  Cartat طلب الإرسال. **login_url غير
     *                                  مُعاد هنا عمداً** (وصل الضيف/المشرف
     *                                  مباشرة عبر واتساب، لا حاجة لعرضه في
     *                                  لوحة الإدارة).
     *   'generated_delivery_failed' — تولَّد الرابط والتُزِم فعلياً، لكن Cartat
     *                                  رفض/تعذّر الاتصال به. **login_url
     *                                  مُعاد هنا** — الرابط صالح وجاهز، يمكن
     *                                  للمضيف نسخه يدوياً بدل توليد رابط ثانٍ.
     *   'error'                     — فشل التوليد نفسه (تفويض/أهلية/قفل
     *                                  مشغول/فشل التزام) — لا محاولة إرسال
     *                                  حدثت أصلاً. يتضمن 'reason'.
     */
    public static function deliver($assignment_id, $actor_user_id): array
    {
        if (!class_exists('PGE_Supervisor_Login_Service')) {
            return ['result' => 'error', 'reason' => 'service_unavailable'];
        }

        $generation = PGE_Supervisor_Login_Service::generate($assignment_id, $actor_user_id);
        if (($generation['result'] ?? '') !== 'generated') {
            return $generation;
        }

        $login_url = (string) ($generation['login_url'] ?? '');
        $event_id = (int) ($generation['event_id'] ?? 0);
        $normalized_assignment_id = (int) ($generation['id'] ?? 0);

        // ── عقد pge_wa_provider الصريح: التسليم عبر Cartat فقط (نفس شرط
        // PGE_Supervisor_Invitation_Delivery::deliver() حرفياً) ────────────
        $active_provider = (string) get_option('pge_wa_provider', 'cartat');
        if ($active_provider !== 'cartat' || !class_exists('PGE_Supervisor_Assignment_Service')) {
            return ['result' => 'generated_delivery_failed', 'reason' => 'provider_not_active', 'id' => $normalized_assignment_id, 'login_url' => $login_url];
        }

        $assignment = PGE_Supervisor_Assignment_Service::get_assignment_state($normalized_assignment_id);
        $phone = (string) ($assignment['supervisor_phone'] ?? '');
        if ($phone === '') {
            return ['result' => 'generated_delivery_failed', 'reason' => 'assignment_incomplete', 'id' => $normalized_assignment_id, 'login_url' => $login_url];
        }

        if (!class_exists('PGE_Cartat_Transport')) {
            return ['result' => 'generated_delivery_failed', 'reason' => 'transport_unavailable', 'id' => $normalized_assignment_id, 'login_url' => $login_url];
        }

        $transport = new PGE_Cartat_Transport();
        if (!$transport->has_credentials()) {
            return ['result' => 'generated_delivery_failed', 'reason' => 'missing_settings', 'id' => $normalized_assignment_id, 'login_url' => $login_url];
        }

        $event_name = 'المناسبة';
        $event = get_post($event_id);
        if ($event !== null) {
            $event_name = (string) ($event->post_title ?? 'المناسبة');
        }

        $message = self::build_message($event_name, $login_url);
        $wa_number = $transport->format_number($phone);

        $raw_result = $transport->send_text($wa_number, $message);
        $outcome = $transport->interpret_result($raw_result);

        if ($outcome !== 'accepted') {
            $failure_category = ($outcome === 'transport_error') ? 'transport_error' : 'provider_rejected';
            return ['result' => 'generated_delivery_failed', 'reason' => $failure_category, 'id' => $normalized_assignment_id, 'login_url' => $login_url];
        }

        return ['result' => 'sent', 'id' => $normalized_assignment_id];
    }
}
