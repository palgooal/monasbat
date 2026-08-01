<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Invitation Delivery — Supervisor Invitation Delivery via
 * Cartat، تنفيذ
 * ============================================================================
 * النقطة المركزية الوحيدة المسموح بها لطلب تسليم فعلي لدعوة مشرف عبر واتساب
 * (Cartat). مسؤوليتها حصراً: التحقق من أهلية الإسناد، بناء رابط القبول، بناء
 * نص الرسالة العربي، طلب تدوير توكن آمن (بلا إبطال مبكر)، الإرسال عبر
 * PGE_Cartat_Transport، تسجيل تدقيق تسليم صادق، وإعادة نتيجة عمل موحَّدة.
 *
 * ما لا تفعله هذا الملف عمداً (Scope Guard صريح من التكليف):
 *  - لا تُفعِّل الإسناد (accept_invitation() تبقى حصراً استجابةً لرابط القبول
 *    الفعلي عبر PGE_Supervisor_Authenticator — راجع includes/routing.php).
 *  - لا تُنشئ جلسة مشرف (PGE_Supervisor_Session تبقى مسؤولية منفصلة تماماً).
 *  - لا تلمس الحضور/الإحصاء/QR/إدارة الدعوات (Guest Invitations).
 *  - لا تُنشئ حساب WordPress بأي شكل.
 *  - لا تشتق event_id من أي مُدخَل عميل مباشر — تُشتَق حصراً من صف الإسناد
 *    نفسه (PGE_Supervisor_Assignment_Service::get_assignment_state()) بعد
 *    التحقق من ملكيته، لا من $_POST['event_id'] (Requirement صريح: "Do NOT
 *    read client-controlled event ownership without authorization").
 *  - التفويض (هل المستدعي مضيف مخوَّل لهذه المناسبة؟) مسؤولية الطبقة
 *    المستدعية (supervisor-management-ajax.php، عبر pge_supervisor_mgmt_
 *    validate_request()/pge_supervisor_mgmt_load_owned_assignment() القائمتين
 *    فعلاً) — لا تُعاد هنا.
 *
 * ============================================================================
 * ترتيب تدوير التوكن الآمن (Token Safety and Delivery Ordering) — حرج
 * ============================================================================
 * "Do NOT invalidate the existing active token before a new delivery request
 * has been accepted by Cartat." التسلسل الفعلي المُنفَّذ أدناه بالضبط:
 *   1. تحقّق من الأهلية والتفويض (يفترض المستدعي فوَّض أصلاً؛ هنا فحص أهلية
 *      حالة الإسناد فقط: invited/pending).
 *   2. GET_LOCK خاص بهذا الإسناد تحديداً.
 *   3. توليد توكن خام وهاش جديدين — بلا أي كتابة على invitation_token_hash.
 *   4. بناء رابط القبول من التوكن الجديد الخام.
 *   5. محاولة التسليم عبر PGE_Cartat_Transport.
 *   6. رفض/فشل نقل: **لا** استبدال invitation_token_hash — التوكن القديم
 *      يبقى سارياً كما هو تماماً، وتُسجَّل delivery_failed.
 *   7. قبول المزوّد: استبدال invitation_token_hash ذرياً (WHERE id + الحالة
 *      المتحقَّقة معاً) عبر commit_new_token_hash()، تحديث invited_at، إبطال
 *      التوكن القديم ضمنياً (استبدال العمود)، وتُسجَّل provider_accepted.
 *   8. تحرير القفل في كل مسار خروج (finally).
 * لا حالة وسيطة يمكن أن ينتج عنها "توكن قديم مُبطَل + توكن جديد لم يُقبَل من
 * Cartat قط" — إما يبقى القديم سارياً بالكامل، أو يُستبدَل بالجديد بعد قبول
 * فعلي موثَّق من المزوّد فقط.
 *
 * ============================================================================
 * دورة التدقيق الصادقة (Audit Lifecycle)
 * ============================================================================
 * delivery_requested → delivery_attempted → provider_accepted | delivery_failed
 * append-only بالكامل عبر PGE_Supervisor_Management_Audit::record() القائمة
 * فعلاً (بلا أي عمود/جدول جديد؛ action هو VARCHAR(20) حر بالفعل). لا 'delivered'
 * إطلاقاً هنا (Cartat لا يؤكد استلام الجهاز فعلياً، فقط قبول طلب الإرسال) ولا
 * 'invitation_resent' (تسمية مضلِّلة لعملية لم تكن تُسلِّم شيئاً فعلياً قبل
 * هذا التنفيذ). لا رفض/انشغال قفل مبكر يُسجَّل تدقيقاً — فقط محاولات وصلت
 * فعلياً لمرحلة "طلب تسليم حقيقي تحت القفل".
 *
 * لا بيانات حسّاسة تُسجَّل في أي صف تدقيق: لا توكن خام، لا رابط قبول، لا رقم
 * هاتف كامل، لا نص رسالة، لا جسم استجابة Cartat. عمود 'reason' يحمل فقط فئة
 * فشل مُطبَّعة (missing_settings/transport_error/provider_rejected/...).
 */
class PGE_Supervisor_Invitation_Delivery
{
    /** الحالات المؤهَّلة لطلب تسليم — نفس ACCEPTABLE_STATUSES في Assignment Service. */
    const ELIGIBLE_STATUSES = ['invited', 'pending'];

    /** مهلة GET_LOCK بالثواني — نفس القيمة الهندسية المعتمَدة في create_supervisor_assignment(). */
    const LOCK_TIMEOUT_SECONDS = 5;

    private static function build_lock_name(int $assignment_id): string
    {
        return 'pge_supervisor_delivery_' . md5((string) $assignment_id);
    }

    /**
     * بناء رابط القبول الكنسي — التوكن الخام الشفّاف فقط، بلا assignment_id/
     * event_id/phone/user_id/أي معرِّف قاعدة بيانات. HTTPS تلقائياً عند
     * تفعيله في الموقع (home_url() تحترم إعداد WordPress نفسه).
     */
    private static function build_acceptance_url(string $raw_token): string
    {
        return home_url('/supervisor/accept/' . rawurlencode($raw_token) . '/');
    }

    /**
     * النص العربي المعتمَد حرفياً من التكليف. event_name يُطبَّع عبر
     * sanitize_text_field() (يزيل الوسوم/الأسطر الزائدة) — لا معرّفات داخلية
     * تُدرَج في الرسالة أبداً.
     */
    private static function build_message(string $event_name, string $acceptance_url): string
    {
        $safe_event_name = sanitize_text_field($event_name);
        if ($safe_event_name === '') {
            $safe_event_name = 'المناسبة';
        }

        return "تمت دعوتك كمشرف دخول لمناسبة \"{$safe_event_name}\".\n"
            . "اضغط الرابط التالي لقبول الدعوة والدخول إلى لوحة المشرف:\n"
            . "{$acceptance_url}\n"
            . "هذا الرابط خاص بك ويُستخدم مرة واحدة فقط.";
    }

    private static function audit(int $event_id, int $assignment_id, int $actor_user_id, string $action, string $reason = ''): void
    {
        if (class_exists('PGE_Supervisor_Management_Audit')) {
            PGE_Supervisor_Management_Audit::record($event_id, $assignment_id, $actor_user_id, $action, $reason);
        }
    }

    /**
     * طلب تسليم دعوة مشرف فعلي — نقطة الدخول العامة الوحيدة. تُستدعى من
     * إنشاء مشرف جديد ومن إعادة الإرسال على حد سواء (نفس منطق الترتيب الآمن
     * لكليهما، بلا تكرار).
     *
     * @return array{result:string, reason?:string, status?:string, id?:int}
     *   'provider_accepted' — قَبِل Cartat طلب الإرسال فعلياً، والتوكن الجديد
     *                          هو الساري الآن.
     *   'delivery_failed'   — رُفض الطلب أو فشل النقل؛ التوكن القديم (إن وُجد)
     *                          ما يزال سارياً كما كان.
     *   'error'              — فشل قبل حتى محاولة الإرسال (تفويض/أهلية/إعدادات
     *                          غير مكتملة/قفل مشغول). يتضمن 'reason'.
     */
    public static function deliver($assignment_id, $actor_user_id): array
    {
        $assignment_id = (int) $assignment_id;
        $actor_user_id = (int) $actor_user_id;

        if ($assignment_id <= 0) {
            return ['result' => 'error', 'reason' => 'invalid_assignment_id'];
        }

        if (!class_exists('PGE_Supervisor_Assignment_Service')) {
            return ['result' => 'error', 'reason' => 'service_unavailable'];
        }

        $assignment = PGE_Supervisor_Assignment_Service::get_assignment_state($assignment_id);
        if ($assignment === null) {
            return ['result' => 'error', 'reason' => 'assignment_not_found'];
        }

        $current_status = (string) ($assignment['status'] ?? '');
        if (!in_array($current_status, self::ELIGIBLE_STATUSES, true)) {
            return ['result' => 'error', 'reason' => 'not_eligible', 'status' => $current_status];
        }

        $event_id = (int) ($assignment['event_id'] ?? 0);
        $phone = (string) ($assignment['supervisor_phone'] ?? '');
        if ($event_id <= 0 || $phone === '') {
            return ['result' => 'error', 'reason' => 'assignment_incomplete'];
        }

        // ── عقد pge_wa_provider الصريح: التسليم عبر Cartat فقط ──────────────
        $active_provider = (string) get_option('pge_wa_provider', 'cartat');
        if ($active_provider !== 'cartat') {
            return ['result' => 'error', 'reason' => 'provider_not_active'];
        }

        if (!class_exists('PGE_Cartat_Transport')) {
            return ['result' => 'error', 'reason' => 'transport_unavailable'];
        }

        $transport = new PGE_Cartat_Transport();
        if (!$transport->has_credentials()) {
            return ['result' => 'error', 'reason' => 'missing_settings'];
        }

        global $wpdb;
        $lock_name = self::build_lock_name($assignment_id);
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, self::LOCK_TIMEOUT_SECONDS));

        if ((int) $got_lock !== 1) {
            // مشغول من طلب تسليم آخر لنفس الإسناد بالتحديد — خطأ عمل ثابت،
            // بلا أي سجل تدقيق (لم يبدأ أي "طلب" فعلي بعد من منظور هذه الدالة).
            return ['result' => 'error', 'reason' => 'lock_busy'];
        }

        try {
            // ── إعادة التحقق من الأهلية تحت القفل (قد تكون الحالة تغيّرت بين
            // الفحص الأول أعلاه وحصولنا على القفل فعلياً — نفس نمط إعادة
            // القراءة تحت القفل في Mon_Cartat_Handler::cron_process_queue()) ──
            $assignment = PGE_Supervisor_Assignment_Service::get_assignment_state($assignment_id);
            if ($assignment === null) {
                return ['result' => 'error', 'reason' => 'assignment_not_found'];
            }

            $current_status = (string) ($assignment['status'] ?? '');
            if (!in_array($current_status, self::ELIGIBLE_STATUSES, true)) {
                return ['result' => 'error', 'reason' => 'not_eligible', 'status' => $current_status];
            }

            $event_name = 'المناسبة';
            $event = get_post($event_id);
            if ($event !== null) {
                $event_name = (string) ($event->post_title ?? 'المناسبة');
            }

            self::audit($event_id, $assignment_id, $actor_user_id, 'delivery_requested');

            // ── توليد توكن جديد بلا أي كتابة بعد — القديم يبقى سارياً حتى إشعار آخر ──
            $token = PGE_Supervisor_Assignment_Service::generate_delivery_token();
            $acceptance_url = self::build_acceptance_url($token['raw']);
            $message = self::build_message($event_name, $acceptance_url);
            $wa_number = $transport->format_number($phone);

            self::audit($event_id, $assignment_id, $actor_user_id, 'delivery_attempted');

            $raw_result = $transport->send_text($wa_number, $message);
            $outcome = $transport->interpret_result($raw_result);

            if ($outcome !== 'accepted') {
                $failure_category = ($outcome === 'transport_error') ? 'transport_error' : 'provider_rejected';
                self::audit($event_id, $assignment_id, $actor_user_id, 'delivery_failed', $failure_category);
                return ['result' => 'delivery_failed', 'reason' => $failure_category];
            }

            // ── قبل المزوّد فعلياً — الآن فقط يجوز استبدال هاش التوكن ────────
            $commit = PGE_Supervisor_Assignment_Service::commit_new_token_hash($assignment_id, $current_status, $token['hash']);
            if (($commit['result'] ?? '') !== 'committed') {
                // حالة نادرة جداً: قَبِل Cartat الرسالة، لكن حالة الإسناد تغيّرت
                // (مثلاً إلغاء متزامن) قبل وصول هذا الالتزام. لا نتراجع عن أي
                // شيء أُرسِل فعلاً — نسجّل الفشل بصدق ونعيد خطأً واضحاً.
                self::audit($event_id, $assignment_id, $actor_user_id, 'delivery_failed', 'token_commit_failed');
                return ['result' => 'delivery_failed', 'reason' => 'token_commit_failed'];
            }

            self::audit($event_id, $assignment_id, $actor_user_id, 'provider_accepted');

            return ['result' => 'provider_accepted', 'id' => $assignment_id];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}
