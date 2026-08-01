<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Manual Link Service — Supervisor Manual Invitation Link:
 * Secure One-Time Generation، تنفيذ
 * ============================================================================
 * النقطة المركزية الوحيدة المسموح بها لتوليد رابط دعوة مشرف يدوياً (بديل
 * طوارئ عند تعذّر واتساب) — منفصلة تماماً عن PGE_Supervisor_Invitation_
 * Delivery (تسليم Cartat الفعلي). هذا الملف **لا يستدعي Cartat إطلاقاً ولا
 * يعرف بوجوده** — التوليد هنا هو "التسليم" نفسه من منظور هذه الميزة: الرابط
 * يُعاد للواجهة مباشرة ليُنسَخ يدوياً، لا يُرسَل عبر أي قناة.
 *
 * القرار المعتمَد صراحةً (Option A): توكن واحد نشط لكل إسناد، تدويرة جديدة
 * بكل طلب ناجح، القديم يُبطَل ضمنياً فور التزام الجديد (استبدال العمود نفسه)
 * — بخلاف Option B المُستخدَمة في تسليم Cartat (حيث القديم يبقى سارياً حتى
 * *قبول* مزوّد خارجي). لا مزوّد خارجي هنا، فلا داعي لتأجيل الإبطال: إما يُلتزَم
 * الهاش الجديد ذرياً بنجاح تام، أو لا يتغيّر شيء إطلاقاً ولا يُعاد أي رابط.
 *
 * إعادة استخدام صريحة (لا تكرار منطق توليد/التزام توكن ثانٍ، ولا مسار قبول
 * ثانٍ):
 *  - PGE_Supervisor_Assignment_Service::generate_delivery_token() — نفس مولّد
 *    التوكن الخام/الهاش المُستخدَم في تسليم Cartat وإعادة الإرسال حرفياً.
 *  - PGE_Supervisor_Assignment_Service::commit_new_token_hash() — نفس الالتزام
 *    الذري (WHERE id + الحالة المتحقَّقة) المُستخدَم في كل مسارات تدوير التوكن.
 *  - includes/routing.php's `/supervisor/accept/{token}/` — الرابط المُولَّد
 *    هنا يُقبَل عبر نفس المسار الفعلي بلا أي تعديل عليه؛ هذا الملف لا يعرف
 *    عن PGE_Supervisor_Authenticator/PGE_Supervisor_Session شيئاً إطلاقاً.
 *  - PGE_Supervisor_Management_Audit::record() — نفس نموذج التدقيق append-only
 *    القائم فعلاً؛ لا جدول/عمود جديد.
 *  - GET_LOCK/RELEASE_LOCK — نفس اصطلاح PGE_Supervisor_Invitation_Delivery::
 *    build_lock_name() تماماً (md5() من مُعرِّف الإسناد)، باسم قفل مستقل خاص
 *    بهذه الميزة تحديداً (pge_supervisor_manual_link_*) حتى لا يتنافس طلب توليد
 *    رابط يدوي مع طلب تسليم Cartat متزامن على نفس الإسناد (لا تعارض وظيفي بين
 *    الميزتين، لكن استقلال القفلين أوضح وأأمن من مشاركة قفل واحد لغرضين).
 *
 * التفويض (هل المستدعي مضيف مخوَّل لهذه المناسبة، وهل الإسناد يخصّ فعلاً هذه
 * المناسبة؟) مسؤولية الطبقة المستدعية بالكامل (supervisor-management-ajax.php،
 * عبر pge_supervisor_mgmt_validate_request()/pge_supervisor_mgmt_load_owned_
 * assignment() القائمتين فعلاً) — لا تُعاد هنا ("Never trust assignment_id
 * alone" يُطبَّق في تلك الطبقة، قبل حتى استدعاء generate() أدناه).
 *
 * ============================================================================
 * ترتيب الالتزام الآمن (Safe Commit Ordering) — حرج، 10 خطوات وفق التكليف
 * ============================================================================
 *  1. التفويض — يُفترَض مُنجَزاً من الطبقة المستدعية قبل الوصول لهذه الدالة.
 *  2. GET_LOCK خاص بهذا الإسناد تحديداً.
 *  3. إعادة قراءة الإسناد تحت القفل (لا وثوق بأي قراءة سابقة قبل الحصول عليه).
 *  4. التحقّق من الأهلية: invited/pending فقط (نفس ACCEPTABLE_STATUSES).
 *  5. توليد توكن خام وهاش جديدين — بلا أي كتابة على invitation_token_hash.
 *  6. بناء رابط القبول من التوكن الجديد الخام (نفس بنية رابط تسليم Cartat).
 *  7. التزام الهاش الجديد ذرياً (WHERE id + الحالة المتحقَّقة في الخطوة 4).
 *  8. نجاح الالتزام فقط: التوكن القديم مُبطَل ضمنياً (استُبدِل العمود)، تدقيق
 *     صادق واحد (manual_link_generated)، إعادة الرابط الخام مرة واحدة فقط.
 *  9. فشل الالتزام: 'token_commit_failed' — **لا** رابط يُعاد إطلاقاً، **لا**
 *     تدقيق نجاح يُكتَب.
 * 10. تحرير القفل في كل مسار خروج (finally) — بلا استثناء.
 * "لا تُعِد أبداً رابطاً لم يُلتزَم هاش توكنه فعلاً."
 *
 * ============================================================================
 * التدقيق (Audit)
 * ============================================================================
 * حدث صادق واحد فقط عند النجاح: 'manual_link_generated' — event_id/
 * assignment_id/actor_user_id/action/الطابع الزمني فقط (عبر PGE_Supervisor_
 * Management_Audit::record() القائمة أصلاً؛ عمود reason يبقى فارغاً هنا — لا
 * بيانات حسّاسة). لا توكن خام، لا رابط، لا هاش، لا رقم هاتف، لا نص رسالة يُكتَب
 * في أي سجل تدقيق إطلاقاً. طلبات فاشلة (غير مخوَّلة/غير مؤهَّلة/قفل مشغول/فشل
 * التزام) **لا** تكتب 'manual_link_generated' — ولا يُخترَع نموذج تدقيق فشل
 * ثانٍ هنا (نفس فلسفة عدم تسجيل رفض/انشغال قفل مبكر في تسليم Cartat).
 *
 * ما لا يفعله هذا الملف عمداً (Scope Guard صريح من التكليف):
 *  - لا يستدعي PGE_Cartat_Transport ولا أي مزوّد واتساب/SMS/بريد/OTP إطلاقاً.
 *  - لا انتهاء صلاحية للتوكن، لا إعادة إرسال مجدوَلة، لا مهمة خلفية/طابور.
 *  - لا يُنشئ حساب WordPress بأي شكل.
 *  - لا يلمس الحضور/QR/الإحصاء/إدارة الدعوات/عمليات المناسبة/الحصة.
 *  - لا تخزين للتوكن الخام في أي مكان (لا DB، لا سجل، لا Transient) — الهاش
 *    فقط يُخزَّن، عبر commit_new_token_hash() القائمة أصلاً.
 */
class PGE_Supervisor_Manual_Link_Service
{
    /** الحالات المؤهَّلة لتوليد رابط يدوي — نفس ACCEPTABLE_STATUSES في Assignment Service. */
    const ELIGIBLE_STATUSES = ['invited', 'pending'];

    /** مهلة GET_LOCK بالثواني — نفس القيمة الهندسية المعتمَدة في بقية الخدمة. */
    const LOCK_TIMEOUT_SECONDS = 5;

    /**
     * اسم قفل مستقل خاص بهذه الميزة (لا يشارك قفل تسليم Cartat) — نفس اصطلاح
     * md5() المُستخدَم في PGE_Supervisor_Invitation_Delivery::build_lock_name().
     */
    private static function build_lock_name(int $assignment_id): string
    {
        return 'pge_supervisor_manual_link_' . md5((string) $assignment_id);
    }

    /**
     * بناء رابط القبول الكنسي — نفس بنية PGE_Supervisor_Invitation_Delivery::
     * build_acceptance_url() حرفياً: التوكن الخام الشفّاف فقط، بلا assignment_id/
     * event_id/phone/user_id/أي معرِّف قاعدة بيانات. HTTPS تلقائياً عند تفعيله
     * في الموقع (home_url() تحترم إعداد WordPress نفسه). لا مسار قبول ثانٍ —
     * نفس /supervisor/accept/{token}/ الفعلي المُعرَّف في includes/routing.php.
     */
    private static function build_acceptance_url(string $raw_token): string
    {
        return home_url('/supervisor/accept/' . rawurlencode($raw_token) . '/');
    }

    private static function audit(int $event_id, int $assignment_id, int $actor_user_id, string $action): void
    {
        if (class_exists('PGE_Supervisor_Management_Audit')) {
            PGE_Supervisor_Management_Audit::record($event_id, $assignment_id, $actor_user_id, $action, '');
        }
    }

    /**
     * توليد رابط دعوة يدوي جديد — نقطة الدخول العامة الوحيدة. تُستدعى حصراً من
     * pge_supervisor_mgmt_manual_link_handler() (supervisor-management-ajax.php)
     * بعد أن يكون ذلك الملف قد أتمّ nonce + تسجيل الدخول + تفويض المضيف + تحميل
     * الإسناد المملوك فعلياً لنفس المناسبة (pge_supervisor_mgmt_load_owned_
     * assignment()) — هذه الدالة لا تُعيد أياً من ذلك التفويض.
     *
     * @return array{result:string, reason?:string, status?:string, id?:int, invitation_url?:string}
     *   'generated' — التُزِم هاش التوكن الجديد فعلياً، والرابط المُعاد هو
     *                 الرابط الكنسي الوحيد الصالح الآن لهذا الإسناد.
     *   'error'     — لم يتغيّر شيء إطلاقاً (تفويض/أهلية/قفل مشغول/فشل التزام).
     *                 يتضمّن 'reason'؛ **لا** invitation_url في هذه الحالة أبداً.
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
            // مشغول من طلب توليد آخر لنفس الإسناد بالتحديد — خطأ عمل ثابت،
            // بلا أي سجل تدقيق (لم يُلتزَم شيء بعد من منظور هذه الدالة).
            return ['result' => 'error', 'reason' => 'lock_busy'];
        }

        try {
            // ── خطوة 3-4: إعادة القراءة تحت القفل + تحقّق أهلية واحد فقط —
            // بالضبط كما ينصّ التسلسل المعتمَد صراحةً (لا فحص مسبق خارج القفل
            // يُكرِّر نفس المنطق). ──
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

            // ── خطوة 5-6: توليد بلا كتابة + بناء الرابط ─────────────────────
            $token = PGE_Supervisor_Assignment_Service::generate_delivery_token();
            $acceptance_url = self::build_acceptance_url($token['raw']);

            // ── خطوة 7: التزام ذري (id + نفس الحالة المتحقَّقة في الخطوة 4) ──
            $commit = PGE_Supervisor_Assignment_Service::commit_new_token_hash($assignment_id, $current_status, $token['hash']);
            if (($commit['result'] ?? '') !== 'committed') {
                // خطوة 9: فشل الالتزام — لا رابط يُعاد، لا تدقيق نجاح يُكتَب.
                return ['result' => 'error', 'reason' => 'token_commit_failed'];
            }

            // ── خطوة 8: نجاح الالتزام — التوكن القديم مُبطَل ضمنياً (استُبدِل
            // العمود عبر commit_new_token_hash() أعلاه)، تدقيق صادق واحد،
            // إعادة الرابط الخام مرة واحدة فقط. ──
            self::audit($event_id, $assignment_id, $actor_user_id, 'manual_link_generated');

            return ['result' => 'generated', 'id' => $assignment_id, 'invitation_url' => $acceptance_url];
        } finally {
            // خطوة 10 — بلا استثناء، على كل مسار خروج.
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}
