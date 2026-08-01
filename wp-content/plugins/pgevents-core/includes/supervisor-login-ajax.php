<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Supervisor Login (Self-Service Request) AJAX — Supervisor Login Architecture
 * (Post-Activation Login) RFC، تنفيذ
 * ============================================================================
 * نقطة نهاية عامة واحدة (wp_ajax_nopriv — **لا حساب WordPress ولا جلسة مشرف
 * قائمة مطلوبة هنا إطلاقاً**، فالمشرف بلا أي منهما تحديداً هو من يحتاج هذه
 * الصفحة) يُطلَق عليها من templates/supervisor-login.php حصراً. مسؤوليتها
 * الوحيدة: تلقّي رقم جوال، البحث عن إسنادات نشطة مطابقة عبر كل المناسبات
 * (PGE_Supervisor_Assignment_Service::find_active_assignments_by_phone())،
 * وطلب توليد+تسليم رابط دخول لكل مطابقة عبر PGE_Supervisor_Login_Delivery
 * (نفس الطبقة المُستخدَمة لزر "إرسال رابط الدخول" للمضيف — لا تكرار منطق).
 *
 * ============================================================================
 * منع تعداد الأرقام (Phone Enumeration) — إلزامي، حرج أمنياً
 * ============================================================================
 * الاستجابة الناجحة **مطابقة تماماً بصرف النظر عن وجود تطابق فعلي أم لا** —
 * سواء كان الرقم مسجَّلاً كمشرف نشط في مناسبة واحدة، عدة مناسبات، أو غير
 * مسجَّل إطلاقاً، النص المُعاد للمتصفح واحد حرفياً. أي اختلاف في الرسالة أو
 * زمن الاستجابة أو حالة HTTP بين "الرقم موجود" و"الرقم غير موجود" يُمكِّن
 * مهاجماً من اكتشاف أي رقم جوال مُسجَّل كمشرف عبر تجربة كل الأرقام الممكنة —
 * هذا الملف مصمَّم عمداً لمنع ذلك بالكامل. لا فرع منطقي واحد فيه يُنتج نصاً
 * مختلفاً بناءً على نتيجة البحث.
 *
 * ============================================================================
 * التدقيق
 * ============================================================================
 * لكل إسناد مطابق فعلياً: حدث login_requested (يُثبِت أن طلباً ذاتياً وصل
 * لهذا الإسناد تحديداً)، ثم login_link_generated (تُكتَب داخل PGE_Supervisor_
 * Login_Service::generate() عند الالتزام الناجح، عبر سلسلة الاستدعاء
 * PGE_Supervisor_Login_Delivery::deliver() ← generate()). لا تدقيق إطلاقاً
 * إن لم يوجد أي تطابق (لا إسناد نُسنِد إليه أي حدث).
 */
if (!function_exists('pge_supervisor_login_request_handler')) {
    function pge_supervisor_login_request_handler()
    {
        // نفس رسالة النجاح الموحَّدة، تُستخدَم في كل مسار نجاح بصرف النظر عن
        // وجود تطابق — مُعرَّفة أولاً لضمان استخدام نفس النص حرفياً دائماً.
        $uniform_message = 'إذا كان هذا الرقم مسجَّلاً كمشرف نشط، ستصلك رسالة واتساب تحتوي رابط الدخول خلال لحظات.';

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pge_supervisor_login_request')) {
            // فشل nonce ليس "تسريب معلومة عن الرقم" — رسالة عامة مستقلة تماماً
            // عن أي رقم هاتف (الطلب لم يُعالَج إطلاقاً بعد هذه النقطة).
            wp_send_json_error(['message' => 'تعذّر إرسال الطلب، أعد تحميل الصفحة وحاول مرة أخرى.']);
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

        if ($phone !== '' && class_exists('PGE_Supervisor_Assignment_Service') && class_exists('PGE_Supervisor_Login_Delivery')) {
            $matches = PGE_Supervisor_Assignment_Service::find_active_assignments_by_phone($phone);

            foreach ($matches as $match) {
                $assignment_id = (int) ($match['id'] ?? 0);
                $event_id = (int) ($match['event_id'] ?? 0);
                if ($assignment_id <= 0 || $event_id <= 0) {
                    continue;
                }

                if (class_exists('PGE_Supervisor_Management_Audit')) {
                    PGE_Supervisor_Management_Audit::record($event_id, $assignment_id, 0, 'login_requested', '');
                }

                // نتيجة deliver() لا تؤثر إطلاقاً على الاستجابة المُعادة
                // للمتصفح (تبقى موحَّدة دائماً) — فقط تولِّد/تُرسِل الرابط
                // فعلياً في الخلفية.
                PGE_Supervisor_Login_Delivery::deliver($assignment_id, 0);
            }
        }

        // نفس الاستجابة تماماً، سواء وُجد $phone فارغاً، أو الخدمات غير
        // محمَّلة، أو لم يوجد أي تطابق، أو وُجد تطابق واحد أو أكثر.
        wp_send_json_success(['message' => $uniform_message]);
    }
}
add_action('wp_ajax_nopriv_pge_supervisor_login_request', 'pge_supervisor_login_request_handler');
add_action('wp_ajax_pge_supervisor_login_request', 'pge_supervisor_login_request_handler');
