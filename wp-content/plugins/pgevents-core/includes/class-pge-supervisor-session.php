<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Session — Entry Check-in Supervisors، Phase 3
 * ============================================================================
 * "Supervisor Authentication" RFC، Requirement 1: "Create a dedicated
 * Supervisor Session. Do NOT reuse WordPress login. Do NOT require WP
 * accounts. The session must identify exactly one supervisor assignment."
 *
 * هذا الملف هو الوسيط الوحيد المسموح به للكتابة على جدول
 * {$wpdb->prefix}mon_supervisor_sessions (Phase 3 — class-mon-catalog-
 * schema.php، upgrade_to_1_12_0()). لا يكتب هذا الملف على mon_event_
 * supervisors إطلاقاً — الكتابة على ذلك الجدول تبقى حصراً عبر
 * PGE_Supervisor_Assignment_Service (Phase 2، غير مُعدَّلة هنا بأي شكل).
 * هذا الملف يقرأ من mon_event_supervisors للتحقق فقط (قراءة، لا كتابة) —
 * حصراً داخل validate_session() للتحقق من حالة الإسناد الحيّة (Requirement 5).
 *
 * ============================================================================
 * تحديث معماري: فصل "إدارة الجلسة" عن "المصادقة" (Blocking Issue)
 * ============================================================================
 * "Invitation Acceptance and Supervisor Authentication are two different
 * responsibilities. They must remain decoupled... 2. PGE_Supervisor_Session:
 * Responsible ONLY for sessions. Responsibilities: Create session, Validate
 * session, Destroy session. Nothing related to invitation lifecycle."
 *
 * كانت هذه الفئة تحوي سابقاً دالة pge_supervisor_authenticate() التي تُنسِّق
 * بين accept_invitation() (Phase 2) وcreate_session() (هنا) معاً — هذا كان
 * يخلط مسؤوليتين مختلفتين تماماً داخل ملف "الجلسة": (أ) انتقال حالة تجاري
 * (قبول الدعوة، مسؤولية PGE_Supervisor_Assignment_Service وحدها)، و(ب) بناء
 * جلسة (مسؤولية هذا الملف وحده). الدالة انتقلت بالكامل إلى ملف مستقل ثالث:
 * includes/class-pge-supervisor-authenticator.php (PGE_Supervisor_Authenticator)
 * — المنسِّق الوحيد الذي يعرف بوجود الخدمتين معاً؛ لا هذا الملف ولا خدمة
 * الإسناد تعرف بوجود الأخرى إطلاقاً بعد هذا التصحيح.
 *
 * هذا الملف بعد التصحيح: لا مرجع لـaccept_invitation()، لا مرجع لأي توكن دعوة،
 * لا معرفة بدورة حياة الإسناد (invited/pending/revoked) — فقط: أنشئ جلسة لهذا
 * assignment_id (مُعطىً جاهزاً من المستدعي)، تحقّق من جلسة، أبطِل جلسة. القراءة
 * الوحيدة من mon_event_supervisors (داخل validate_session()) هي تحقّق "هل لا
 * يزال هذا الإسناد active؟" — قراءة حالة بحتة، لا تفسير لدورة حياة الدعوة.
 *
 * ============================================================================
 * قرار معماري: لا جلسة WordPress، لا حساب WP (Requirement 1)
 * ============================================================================
 * الجلسة هنا كيان مستقل بالكامل: صف في mon_supervisor_sessions يشير فقط إلى
 * assignment_id (صف في mon_event_supervisors). لا wp_set_current_user()، لا
 * wp_generate_auth_cookie()، لا اعتماد على wp_users على الإطلاق — بما يطابق
 * حرفياً "Do NOT reuse WordPress login. Do NOT require WP accounts." الهوية
 * الوحيدة المُثبَتة هنا هي "هذا المتصفح يملك سراً عشوائياً يخص إسناد مشرف
 * مُحدَّد"، لا أي شيء متعلق بحساب مستخدم.
 *
 * التوكن الخام لا يُخزَّن أبداً — لا توكن الدعوة (Phase 2، مُلتزَم به هناك
 * فعلاً) ولا توكن الجلسة الجديد هنا (يُخزَّن sha256(raw) فقط في
 * session_token_hash).
 *
 * ============================================================================
 * منع تثبيت الجلسة (Session Fixation) — Requirement 8
 * ============================================================================
 * كل استدعاء لـcreate_session() يُولِّد سراً عشوائياً جديداً بالكامل
 * (random_bytes(32)، 256 بت إنتروبيا حقيقية) غير مشتق بأي شكل من توكن
 * الدعوة أو من أي قيمة يوفرها الطرف المستدعي — فلا يوجد أي "معرّف مسبق" يمكن
 * لمهاجم تثبيته قبل المصادقة ليصبح موثوقاً بعدها؛ الهوية النهائية بالكامل من
 * صنع الخادم. كل استدعاء لاحق لـcreate_session() لنفس الإسناد (سواء عبر
 * PGE_Supervisor_Authenticator أو عبر أي نقطة مصادقة مستقبلية — راجع "التعافي
 * بعد فشل جزئي" في class-pge-supervisor-authenticator.php) يُنشئ صفاً وسراً
 * جديدين تماماً أيضاً — لا "ترقية" لأي جلسة قائمة مسبقاً، بما يحقق "Regenerate
 * identifiers after authentication" حرفياً في كل مرة.
 *
 * ============================================================================
 * لماذا لا كتابة على mon_supervisor_sessions عند إلغاء الإسناد (Requirement 7)
 * ============================================================================
 * validate_session() تُعيد قراءة حالة الإسناد الحيّة من mon_event_supervisors
 * في كل استدعاء (لا تثق أبداً بأي حالة مخزَّنة مسبقاً في صف الجلسة نفسه) —
 * فور أن يصبح status ≠ 'active' (عبر revoke_supervisor_assignment() في
 * Phase 2، غير المُعدَّلة هنا)، أي جلسة قائمة لنفس assignment_id تُرفَض في
 * الطلب التالي مباشرة، بلا أي حاجة للكتابة على صفوف جلسات قد تكون متعددة.
 */

class PGE_Supervisor_Session
{
    /**
     * اسم الكوكي الذي يحمل توكن الجلسة الخام (لا أي مُعرِّف داخلي). هذا هو
     * "الاسم المعروف" الوحيد الذي تعتمده pge_is_active_supervisor_for_event()
     * لقراءة الجلسة — لا وجود لأي مسار HTTP/AJAX يكتب هذا الكوكي فعلياً في
     * هذا الـCommit (Requirement 9: لا UI بعد)؛ اسم الثابت موثَّق هنا مسبقاً
     * ليكون جاهزاً لأي واجهة مستقبلية دون أي تخمين لاحق لاسمه.
     */
    const SESSION_COOKIE_NAME = 'pge_supervisor_session';

    /**
     * مهلة صلاحية الجلسة الافتراضية بالثواني (12 ساعة). قيمة هندسية بحتة
     * (Engineering Default) — **ليست قاعدة عمل تجارية** بحاجة تفويض منتج،
     * بنفس منطق اختيار مهلة GET_LOCK=5 ثوانٍ في
     * PGE_Supervisor_Assignment_Service::create_supervisor_assignment()
     * (Phase 2). قابلة للتعديل مستقبلاً عبر إعداد لوحة تحكم دون أي أثر
     * معماري على منطق التحقق نفسه.
     */
    const SESSION_TTL_SECONDS = 43200; // 12 * 3600

    private static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'mon_supervisor_sessions';
    }

    private static function assignments_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'mon_event_supervisors';
    }

    /**
     * تطبيع معرّف موجب — نفس قاعدة normalize_positive_id() في
     * class-pge-supervisor-assignment-service.php/class-pge-invitation-
     * credit-ledger.php حرفياً (DRY منطقي، لا اعتماد برمجي جديد بين الملفات).
     */
    private static function normalize_positive_id($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * توليد سرّ جلسة عشوائي آمن — نفس آلية generate_invitation_token() في
     * PGE_Supervisor_Assignment_Service حرفياً (32 بايت عشوائية حقيقية عبر
     * random_bytes، 64 حرف hex). سرّ الجلسة وسرّ الدعوة قيمتان مستقلتان
     * تماماً، مُولَّدتان بشكل منفصل — معرفة إحداهما لا تكشف الأخرى بأي شكل.
     */
    private static function generate_session_token(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * هاش سرّ الجلسة (sha256، بلا ملح) — نفس التبرير المعتمد فعلاً لهاش توكن
     * الدعوة في Phase 2: القيمة المُدخَلة عالية الإنتروبيا أصلاً (256 بت
     * عشوائية حقيقية)، فهاش مباشر كافٍ تماماً.
     */
    private static function hash_session_token(string $raw_token): string
    {
        return hash('sha256', $raw_token);
    }

    /**
     * قراءة صف إسناد واحد بمعرّفه — قراءة فقط، لا كتابة على mon_event_
     * supervisors من هذا الملف إطلاقاً.
     */
    private static function find_assignment_by_id($assignment_id)
    {
        $normalized_id = self::normalize_positive_id($assignment_id);
        if ($normalized_id === 0) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::assignments_table_name() . ' WHERE id = %d', $normalized_id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * إنشاء جلسة مشرف جديدة (Requirement 1/2/3/8) — الطريقة العامة الوحيدة
     * لإنشاء صف جديد في mon_supervisor_sessions. لا تتحقق بنفسها من حالة
     * الإسناد ولا تعرف شيئاً عن دورة حياة الدعوة (مسؤولية المستدعي — راجع
     * PGE_Supervisor_Authenticator::authenticate() في class-pge-supervisor-
     * authenticator.php التي تتحقق فعلياً قبل الاستدعاء)؛ هذا يُبقي
     * create_session() أداة صرف بسيطة "أنشئ جلسة لهذا الإسناد"، قابلة لإعادة
     * الاستخدام مستقبلياً (مثلاً: إصدار جلسة إضافية من جهاز ثانٍ لإسناد نشط
     * بالفعل، أو التعافي بعد فشل جزئي — راجع توثيق "التعافي" في الملف
     * المذكور — بلا الحاجة لإعادة عبور مسار قبول الدعوة الكامل).
     *
     * @return array{result: string, session_token?: string, id?: int}
     */
    public static function create_session($assignment_id, $event_id): array
    {
        $normalized_assignment_id = self::normalize_positive_id($assignment_id);
        if ($normalized_assignment_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_assignment_id'];
        }

        $normalized_event_id = self::normalize_positive_id($event_id);
        if ($normalized_event_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_event_id'];
        }

        global $wpdb;
        $table = self::table_name();

        $raw_token = self::generate_session_token();
        $token_hash = self::hash_session_token($raw_token);
        $now = current_time('mysql', true);
        $expires_at = date('Y-m-d H:i:s', strtotime($now) + self::SESSION_TTL_SECONDS);

        $inserted = $wpdb->insert(
            $table,
            [
                'assignment_id' => $normalized_assignment_id,
                'event_id' => $normalized_event_id,
                'session_token_hash' => $token_hash,
                'issued_at' => $now,
                'expires_at' => $expires_at,
                'revoked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$inserted) {
            return ['result' => 'error', 'reason' => 'insert_failed'];
        }

        return [
            'result' => 'created',
            'id' => (int) $wpdb->insert_id,
            'session_token' => $raw_token,
            'expires_at' => $expires_at,
        ];
    }

    /**
     * التحقق الكامل من جلسة (Requirement 5 — "Every protected request must
     * validate"): وجود الجلسة، وجود الإسناد، حالة الإسناد active، انتماء
     * الإسناد للمناسبة المطلوبة (يُترَك للمستدعي عبر مقارنة event_id المُعاد
     * — راجع pge_is_active_supervisor_for_event())، عدم إلغاء الإسناد (مُطبَّق
     * ضمنياً عبر شرط status = 'active')، عدم انتهاء صلاحية الجلسة.
     *
     * @return array{result: string, assignment_id?: int, event_id?: int, session_id?: int, reason?: string}
     */
    public static function validate_session($raw_token): array
    {
        $raw_token = is_scalar($raw_token) ? trim((string) $raw_token) : '';
        if ($raw_token === '') {
            return ['result' => 'invalid', 'reason' => 'invalid_token'];
        }

        $token_hash = self::hash_session_token($raw_token);

        global $wpdb;
        $table = self::table_name();

        $session = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE session_token_hash = %s LIMIT 1", $token_hash),
            ARRAY_A
        );

        // 1) الجلسة موجودة؟
        if ($session === null) {
            return ['result' => 'invalid', 'reason' => 'session_not_found'];
        }

        // 6) الجلسة لم تُلغَ صراحة (Logout — Requirement 6)
        if (!empty($session['revoked_at'])) {
            return ['result' => 'invalid', 'reason' => 'session_revoked'];
        }

        // 6) الجلسة لم تنتهِ صلاحيتها بعد
        $now = current_time('mysql', true);
        if (strtotime((string) $session['expires_at']) <= strtotime($now)) {
            return ['result' => 'invalid', 'reason' => 'session_expired'];
        }

        // 2) الإسناد موجود؟ (قراءة حيّة من mon_event_supervisors، لا من نسخة
        // مخزَّنة في صف الجلسة — Requirement 7 يعتمد على هذه القراءة الحيّة)
        $assignment_id = (int) $session['assignment_id'];
        $assignment = self::find_assignment_by_id($assignment_id);
        if ($assignment === null) {
            return ['result' => 'invalid', 'reason' => 'assignment_not_found'];
        }

        // 3) حالة الإسناد active بالضبط؟ (يشمل: غير مُلغى — Requirement 7)
        $assignment_status = (string) ($assignment['status'] ?? '');
        if ($assignment_status !== 'active') {
            return ['result' => 'invalid', 'reason' => 'assignment_not_active', 'status' => $assignment_status];
        }

        // 4) الإسناد ينتمي فعلياً لنفس المناسبة المُخزَّنة في صف الجلسة —
        // تحقّق دفاعي إضافي (لا يجب أن يختلفا أبداً؛ event_id لا يتغيّر بعد
        // إنشاء صف الإسناد)، يحمي من أي خطأ بيانات صامت بدل الوثوق الأعمى.
        $live_event_id = (int) ($assignment['event_id'] ?? 0);
        $session_event_id = (int) $session['event_id'];
        if ($live_event_id !== $session_event_id) {
            return ['result' => 'invalid', 'reason' => 'event_mismatch'];
        }

        return [
            'result' => 'valid',
            'assignment_id' => $assignment_id,
            'event_id' => $live_event_id,
            'session_id' => (int) $session['id'],
        ];
    }

    /**
     * تسجيل الخروج الصريح (Requirement 6) — يُبطِل الجلسة عبر revoked_at،
     * لا حذف للصف (بنفس فلسفة Append-Only المُعتمدة في بقية هذا المشروع).
     * Idempotent: استدعاء متكرر لنفس التوكن بعد إبطاله الأول يُعيد
     * 'already_revoked' لا خطأً.
     *
     * @return array{result: string, id?: int, reason?: string}
     */
    public static function logout($raw_token): array
    {
        $raw_token = is_scalar($raw_token) ? trim((string) $raw_token) : '';
        if ($raw_token === '') {
            return ['result' => 'error', 'reason' => 'invalid_token'];
        }

        $token_hash = self::hash_session_token($raw_token);

        global $wpdb;
        $table = self::table_name();

        $session = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE session_token_hash = %s LIMIT 1", $token_hash),
            ARRAY_A
        );

        if ($session === null) {
            return ['result' => 'error', 'reason' => 'invalid_token'];
        }

        $session_id = (int) $session['id'];

        if (!empty($session['revoked_at'])) {
            return ['result' => 'already_revoked', 'id' => $session_id];
        }

        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            $table,
            [
                'revoked_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $session_id,
            ],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false || $updated === 0) {
            return ['result' => 'error', 'reason' => 'concurrent_logout'];
        }

        return ['result' => 'logged_out', 'id' => $session_id];
    }
}

/**
 * ============================================================================
 * pge_is_active_supervisor_for_event($event_id) — دالة التفويض الحقيقية
 * ============================================================================
 * Requirement 4: "This becomes the REAL authorization function. It must
 * use ONLY: Authenticated Supervisor Session. NOT request parameters. NOT
 * phone numbers. NOT hidden fields."
 *
 * هذا هو بالضبط الاسم الذي حُجِز توثيقياً (لا تنفيذياً) في تصحيح Phase 2
 * البنيوي (Blocking Issue #1) لدالة تفويض مستقبلية بتوقيع $event_id فقط —
 * الآن يُنفَّذ فعلياً. لا علاقة له بـ
 * pge_has_active_supervisor_assignment($event_id, $phone) (Phase 2، Lookup
 * بحت، لا تزال قائمة دون أي تعديل) — تلك تبقى للاستخدام الداخلي فقط، غير
 * مصرَّح استخدامها كحارس وصول، بينما هذه الدالة الجديدة هي الحارس الفعلي.
 *
 * المصدر الوحيد للهوية: كوكي PGE_Supervisor_Session::SESSION_COOKIE_NAME —
 * لا $_POST، لا $_GET، لا أي حقل مخفي، لا رقم هاتف بأي شكل. لا وجود لأي
 * مسار HTTP يكتب هذا الكوكي فعلياً بعد (Requirement 9 — لا UI)؛ القراءة هنا
 * جاهزة لأي واجهة مستقبلية تكتبه.
 */
if (!function_exists('pge_is_active_supervisor_for_event')) {
    function pge_is_active_supervisor_for_event($event_id): bool
    {
        $normalized_event_id = (int) $event_id;
        if ($normalized_event_id <= 0) {
            return false;
        }

        $raw_token = isset($_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME])
            ? (string) $_COOKIE[PGE_Supervisor_Session::SESSION_COOKIE_NAME]
            : '';

        if ($raw_token === '') {
            return false;
        }

        $validation = PGE_Supervisor_Session::validate_session($raw_token);

        if (($validation['result'] ?? '') !== 'valid') {
            return false;
        }

        return (int) ($validation['event_id'] ?? 0) === $normalized_event_id;
    }
}
