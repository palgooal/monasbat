<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Login Service — Supervisor Login Architecture
 * (Post-Activation Login) RFC، تنفيذ
 * ============================================================================
 * النقطة المركزية الوحيدة المسموح بها لتوليد توكن دخول لمشرف **مُفعَّل بالفعل**
 * (status = 'active'). مسؤوليتها حصراً وفق نص RFC الحرفي: "Generate login
 * token. Commit hash. Rotate previous login token. Validate eligibility.
 * Audit." — **لا شيء غير ذلك**: لا تستدعي Cartat، لا تعرف بوجود
 * PGE_Cartat_Transport إطلاقاً (راجع includes/class-pge-supervisor-login-
 * delivery.php للطبقة الوحيدة المسموح لها بمعرفة Cartat)، ولا تُنشئ جلسة
 * (مسؤولية PGE_Supervisor_Login_Authenticator وحدها، ملف مستقل تماماً).
 *
 * ============================================================================
 * توكن الدخول مقابل توكن الدعوة — فصل معماري كامل، لا خلط إطلاقاً
 * ============================================================================
 * | | توكن الدعوة (invitation_token_hash) | توكن الدخول (login_token_hash، هذا الملف) |
 * |---|---|---|
 * | الغرض | تفعيل المشرف لأول مرة | إنشاء جلسة دخول جديدة لمشرف مُفعَّل بالفعل |
 * | الأهلية | invited/pending فقط | active فقط |
 * | أثر الاستهلاك | ينقل الحالة إلى active | **لا يُغيِّر status إطلاقاً** |
 * | عدد مرات الاستخدام | مرة واحدة، للأبد | كثيراً — تدويرة جديدة بكل طلب |
 * | العمود | invitation_token_hash | login_token_hash (عمود مستقل تماماً) |
 * | الخدمة المسؤولة | PGE_Supervisor_Assignment_Service::accept_invitation() | PGE_Supervisor_Assignment_Service::consume_login_token() (هذا الملف يولِّد فقط، لا يستهلك) |
 *
 * "Never reuse invitation service" — هذا الملف لا يستدعي accept_invitation()
 * ولا PGE_Supervisor_Invitation_Delivery ولا PGE_Supervisor_Authenticator
 * إطلاقاً؛ يستدعي فقط PGE_Supervisor_Assignment_Service::generate_delivery_
 * token() (مولّد التوكن العام المشترك — نفس bin2hex(random_bytes(32))/sha256()
 * المُستخدَمَين لكل توكنات المشروع، **لا مولّد ثانٍ**) وcommit_new_login_token_
 * hash() (الجديدة، عمود مستقل)، وPGE_Supervisor_Management_Audit::record()
 * القائمة أصلاً.
 *
 * ============================================================================
 * ترتيب الالتزام (Option A — تدوير فوري، بلا انتظار تسليم خارجي)
 * ============================================================================
 * بخلاف تسليم دعوة Cartat (Option B — لا إبطال قبل قبول المزوّد)، توليد توكن
 * الدخول هنا يلتزم فوراً بمجرد نجاح التحقق من الأهلية تحت القفل — بصرف النظر
 * عمّا سيحدث بعد ذلك للرابط (نسخ يدوي فوري، أو إرسال Cartat لاحق عبر
 * PGE_Supervisor_Login_Delivery). هذا مطابق حرفياً لنص RFC ("Each generation
 * invalidates the previous login token" — بلا أي شرط "بعد نجاح التسليم"،
 * بخلاف نص RFC الدعوة اليدوية الذي فرض صراحة Option A لسبب مختلف [عدم وجود
 * مزوّد خارجي أصلاً هناك]، بينما هنا التبسيط قرار تصميم واعٍ لتطابق النص
 * الحرفي الأبسط لهذا RFC تحديداً). التوثيق الكامل لهذا القرار وتداعياته على
 * "ماذا يحدث إن فشل إرسال Cartat بعد الالتزام؟" موجود في docs/SUPERVISOR-
 * LOGIN-LIFECYCLE.md §"قرار Option A" وفي التقرير النهائي لهذا التنفيذ.
 *
 * 1. تحقّق الأهلية الأولي مُفتَرَض من الطبقة المستدعية (AJAX) — تحقّق تفويض
 *    المضيف/Administrator عبر pge_supervisor_mgmt_validate_request()/
 *    pge_supervisor_mgmt_load_owned_assignment() قبل استدعاء generate() هنا،
 *    تماماً كما في PGE_Supervisor_Manual_Link_Service (لا تكرار هنا).
 * 2. GET_LOCK خاص بهذا الإسناد (بادئة مستقلة عن أقفال الدعوة/الرابط اليدوي).
 * 3. إعادة قراءة الإسناد تحت القفل.
 * 4. التحقّق من الأهلية: active فقط (عكس تماماً أهلية الدعوة اليدوية).
 * 5. توليد توكن خام وهاش جديدين — بلا كتابة.
 * 6. بناء رابط الدخول الكنسي (/supervisor/login/{token}/ — مسار مستقل تماماً
 *    عن /supervisor/accept/{token}/).
 * 7. التزام الهاش الجديد ذرياً (WHERE id + status = 'active').
 * 8. نجاح الالتزام: تدقيق صادق واحد (login_link_generated)، إعادة الرابط مرة واحدة.
 * 9. فشل الالتزام: لا رابط يُعاد، لا تدقيق نجاح.
 * 10. تحرير القفل في كل مسار خروج.
 */
class PGE_Supervisor_Login_Service
{
    /** الحالة المؤهَّلة الوحيدة لتوليد توكن دخول — عكس ACCEPTABLE_STATUSES تماماً. */
    const ELIGIBLE_STATUSES = ['active'];

    /** مهلة GET_LOCK بالثواني — نفس القيمة الهندسية المعتمَدة في بقية الخدمات. */
    const LOCK_TIMEOUT_SECONDS = 5;

    /** اسم قفل مستقل تماماً عن أقفال تسليم الدعوة والرابط اليدوي. */
    private static function build_lock_name(int $assignment_id): string
    {
        return 'pge_supervisor_login_' . md5((string) $assignment_id);
    }

    /**
     * بناء رابط الدخول الكنسي — مسار مستقل تماماً عن /supervisor/accept/
     * ("Do NOT reuse /supervisor/accept/{token}. Acceptance remains
     * invitation-only." — حرفياً). التوكن الخام الشفّاف فقط في الرابط، بلا
     * أي معرِّف داخلي.
     */
    private static function build_login_url(string $raw_token): string
    {
        return home_url('/supervisor/login/' . rawurlencode($raw_token) . '/');
    }

    private static function audit(int $event_id, int $assignment_id, int $actor_user_id, string $action): void
    {
        if (class_exists('PGE_Supervisor_Management_Audit')) {
            PGE_Supervisor_Management_Audit::record($event_id, $assignment_id, $actor_user_id, $action, '');
        }
    }

    /**
     * توليد رابط دخول جديد لمشرف مُفعَّل — نقطة الدخول العامة الوحيدة.
     *
     * @return array{result:string, reason?:string, status?:string, id?:int, login_url?:string}
     *   'generated' — التُزِم هاش التوكن الجديد فعلياً؛ الرابط المُعاد هو
     *                 رابط الدخول الوحيد الصالح الآن لهذا الإسناد (السابق،
     *                 إن وُجد، مُبطَل ضمنياً).
     *   'error'     — لم يتغيّر شيء إطلاقاً. يتضمن 'reason'.
     */
    public static function generate($assignment_id, $actor_user_id): array
    {
        $assignment_id = (int) $assignment_id;
        $actor_user_id = (int) $actor_user_id;

        if ($assignment_id <= 0) {
            return ['result' => 'error', 'reason' => 'invalid_assignment_id'];
        }

        if (!class_exists('PGE_Supervisor_Assignment_Service')) {
            return ['result' => 'error', 'reason' => 'service_unavailable'];
        }

        global $wpdb;
        $lock_name = self::build_lock_name($assignment_id);
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, self::LOCK_TIMEOUT_SECONDS));

        if ((int) $got_lock !== 1) {
            return ['result' => 'error', 'reason' => 'lock_busy'];
        }

        try {
            $assignment = PGE_Supervisor_Assignment_Service::get_assignment_state($assignment_id);
            if ($assignment === null) {
                return ['result' => 'error', 'reason' => 'assignment_not_found'];
            }

            $current_status = (string) ($assignment['status'] ?? '');
            if (!in_array($current_status, self::ELIGIBLE_STATUSES, true)) {
                return ['result' => 'error', 'reason' => 'not_eligible', 'status' => $current_status];
            }

            $event_id = (int) ($assignment['event_id'] ?? 0);
            if ($event_id <= 0) {
                return ['result' => 'error', 'reason' => 'assignment_incomplete'];
            }

            $token = PGE_Supervisor_Assignment_Service::generate_delivery_token();
            $login_url = self::build_login_url($token['raw']);

            $commit = PGE_Supervisor_Assignment_Service::commit_new_login_token_hash($assignment_id, $current_status, $token['hash']);
            if (($commit['result'] ?? '') !== 'committed') {
                return ['result' => 'error', 'reason' => 'token_commit_failed'];
            }

            self::audit($event_id, $assignment_id, $actor_user_id, 'login_link_generated');

            return ['result' => 'generated', 'id' => $assignment_id, 'event_id' => $event_id, 'login_url' => $login_url];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}
