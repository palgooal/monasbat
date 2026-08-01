<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Login Authenticator — Supervisor Login Architecture
 * (Post-Activation Login) RFC، تنفيذ
 * ============================================================================
 * منسِّق مصادقة دخول مستقل تماماً عن PGE_Supervisor_Authenticator (الذي يبقى
 * حصرياً لمسار قبول الدعوة — "Acceptance remains invitation-only"، **غير
 * مُعدَّل هنا بأي شكل**). هذا الملف هو المكان الوحيد الذي "يعرف" بوجود كل من
 * PGE_Supervisor_Assignment_Service::consume_login_token() وPGE_Supervisor_
 * Session::create_session() معاً لغرض تسجيل الدخول (بخلاف Authenticator
 * الأصلي الذي يربط accept_invitation() بـcreate_session() لغرض القبول) —
 * نفس فلسفة الفصل المعمارية تماماً، مُطبَّقة على مسار مستقل بالكامل.
 *
 * **صفر كتابة/قراءة مباشرة على أي جدول من هنا** — لا $wpdb إطلاقاً. كل تفاعل
 * عبر الواجهات العامة: consume_login_token()، create_session()،
 * PGE_Supervisor_Management_Audit::record().
 *
 * ============================================================================
 * دورة المصادقة (مطابقة لبنية PGE_Supervisor_Authenticator لكن بلا أي انتقال حالة)
 * ============================================================================
 * Login URL → استهلاك توكن الدخول (لا تغيير على status، فقط تصفير
 * login_token_hash) → إنشاء جلسة مشرف جديدة → تدقيق (login_authenticated
 * عند النجاح، login_failed عند فشل معروف السبب).
 *
 * لا "معالجة فشل جزئي" هنا بنفس تعقيد Authenticator الأصلي (لا انتقال حالة
 * يمكن أن "ينجح جزئياً" هنا أصلاً — consume_login_token() إما تنجح بالكامل
 * وتُصفِّر التوكن، أو تفشل بالكامل ولا شيء يتغيّر؛ فشل create_session()
 * اللاحق لا يملك أي أثر رجعي على استهلاك التوكن، تماماً كما في Authenticator
 * الأصلي — التوكن يبقى مُستهلَكاً، والمشرف "قد يحتاج تدويرة رابط دخول جديدة"
 * لا "إعادة القبول"، لأن القبول أصلاً غير معنيّ هنا إطلاقاً).
 */
class PGE_Supervisor_Login_Authenticator
{
    private static function audit(int $event_id, int $assignment_id, int $actor_user_id, string $action): void
    {
        if (class_exists('PGE_Supervisor_Management_Audit')) {
            PGE_Supervisor_Management_Audit::record($event_id, $assignment_id, $actor_user_id, $action, '');
        }
    }

    /**
     * تنسيق تدفّق مصادقة الدخول الكامل. المسؤولية الوحيدة: **الترتيب**، لا
     * التنفيذ الداخلي لأي من الخطوتين.
     *
     * @return array{result: string, ...}
     *   'authenticated' — نجحت المصادقة بالكامل. يتضمن assignment_id، event_id،
     *                      session_token (الخام، مرة واحدة فقط)، session_id.
     *   'error'          — فشل في أي خطوة. يتضمن 'stage':
     *                        'login'   — فشل استهلاك توكن الدخول نفسه (توكن
     *                          غير صالح/مُستهلَك مسبقاً، أو الإسناد لم يعد
     *                          active). تدقيق login_failed يُكتَب هنا إن كان
     *                          الإسناد معروفاً (استُخرِج من الصف قبل الفشل).
     *                        'session' — استهلاك التوكن نجح فعلاً (لا يمكن
     *                          التراجع عنه) لكن إنشاء الجلسة فشل. **لا تدقيق
     *                          login_failed هنا** — الدخول نفسه لم "يفشل"
     *                          منطقياً (الهوية تأكَّدت)، فقط تعذّر فتح الجلسة.
     */
    public static function authenticate($raw_login_token): array
    {
        if (!class_exists('PGE_Supervisor_Assignment_Service') || !class_exists('PGE_Supervisor_Session')) {
            return ['result' => 'error', 'stage' => 'login', 'reason' => 'service_unavailable'];
        }

        $consume_result = PGE_Supervisor_Assignment_Service::consume_login_token($raw_login_token);
        $consume_outcome = (string) ($consume_result['result'] ?? '');

        if ($consume_outcome !== 'consumed') {
            $reason = (string) ($consume_result['reason'] ?? 'login_failed');

            // تدقيق login_failed صادق فقط إن استطعنا الاستدلال على الإسناد
            // بيقين — consume_login_token() تُعيد id/event_id لفرعي
            // 'assignment_not_active'/'token_already_used_or_invalid' (الصف
            // معروف فعلياً)، لكن **ليس** لفرع 'invalid_token' (لا صف مطابق
            // للهاش أصلاً إطلاقاً — لا شيء نُسنِد إليه التدقيق منطقياً، نفس
            // القيد البنيوي القائم فعلاً في مسار قبول الدعوة لتوكن غير صالح
            // تماماً؛ لا نكتب تدقيقاً مجهول الإسناد أبداً).
            $failed_assignment_id = (int) ($consume_result['id'] ?? 0);
            $failed_event_id = (int) ($consume_result['event_id'] ?? 0);
            if ($failed_assignment_id > 0 && $failed_event_id > 0) {
                self::audit($failed_event_id, $failed_assignment_id, 0, 'login_failed');
            }

            return ['result' => 'error', 'stage' => 'login', 'reason' => $reason, 'status' => $consume_result['status'] ?? null];
        }

        $assignment_id = (int) ($consume_result['id'] ?? 0);
        $event_id = (int) ($consume_result['event_id'] ?? 0);

        if ($assignment_id <= 0 || $event_id <= 0) {
            return ['result' => 'error', 'stage' => 'login', 'reason' => 'missing_assignment_id'];
        }

        $session_result = PGE_Supervisor_Session::create_session($assignment_id, $event_id);
        if (($session_result['result'] ?? '') !== 'created') {
            return [
                'result' => 'error',
                'stage' => 'session',
                'reason' => $session_result['reason'] ?? 'session_creation_failed',
            ];
        }

        self::audit($event_id, $assignment_id, 0, 'login_authenticated');

        return [
            'result' => 'authenticated',
            'assignment_id' => $assignment_id,
            'event_id' => $event_id,
            'session_token' => $session_result['session_token'],
            'session_id' => $session_result['id'],
            'expires_at' => $session_result['expires_at'],
        ];
    }
}
