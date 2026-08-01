<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Attendance Statistics Service — Entry Check-in Supervisors، Phase 5
 * (Final Fix: Enforced Statistics Access Boundary)
 * ============================================================================
 * "Attendance Statistics Engine" RFC — "This becomes the ONLY service
 * responsible for attendance calculations. No controller or template may
 * calculate statistics directly." هذا الملف يحسب فقط — لا منطق عرض، لا HTML،
 * لا أي استهلاك مباشر من قالب/AJAX (ذاك عمل PGE_Attendance_Dashboard_Provider،
 * الذي يتحقق من التفويض ثم يستدعي هذا الملف).
 *
 * ============================================================================
 * حدود الوصول المُنفَّذة في الكود (Phase 5 Final Fix)
 * ============================================================================
 * هذا الملف لا يعرف شيئاً عن: المستخدم الحالي، جلسة المشرف، هوية المضيف،
 * صلاحيات ووردبريس، طلبات HTTP، AJAX، REST، Nonces، أو أي قالب — بالضبط كما
 * يتطلّب الـRFC. لا منطق تفويض هنا إطلاقاً، ولا يُضاف أي منطق تفويض هنا مستقبلاً.
 *
 * **لا يمكن إنشاء كائن من هذا الصف من خارجه إطلاقاً**:
 *   1. الباني (__construct) خاص (private) — أي `new PGE_Attendance_Statistics_
 *      Service()` من أي ملف آخر يفشل فوراً بخطأ PHP صريح (Fatal Error: Call to
 *      private constructor from invalid context).
 *   2. الدوال العامة (get_attendance_summary/get_recent_checkins/get_supervisor_
 *      summary) هي دوال كائن (instance methods) لا static — لا يمكن استدعاؤها
 *      إطلاقاً بلا كائن صالح أصلاً. وحماية إضافية عابرة للإصدارات: كل دالة
 *      عامة تُشير إلى `$this` صراحةً في أول سطر — إن حاول أحدهم استدعاءها
 *      Statically بلا كائن (PGE_Attendance_Statistics_Service::get_attendance_
 *      summary(1))، فـPHP نفسه يرفض ذلك بخطأ فادح "Using $this when not in
 *      object context" على كل إصدارات PHP المدعومة (وليس فقط PHP 8+، حيث
 *      استدعاء دالة كائن Statically بلا مرجعية $this داخلها قد يمرّ بصمت في
 *      PHP 7.x دون هذا الحرس الصريح).
 *   3. المُنشئ الوحيد المخوَّل لكائن صالح من هذا الصف هو PGE_Attendance_
 *      Dashboard_Provider حصراً، عبر آلية داخلية خاصة (private) تستخدم
 *      ReflectionClass::newInstanceWithoutConstructor() — لا تُعرَض أي دالة
 *      عامة (public factory/getter) في أي مكان تُعيد أو تبني كائناً من هذا
 *      الصف؛ Provider هو المالك الوحيد لتلك الآلية ولا يكشفها لأي مستهلك آخر.
 * هذا يجعل التدفّق الحقيقي الوحيد الممكن هو:
 *   Authorized Consumer → PGE_Attendance_Dashboard_Provider (تفويض) →
 *   PGE_Attendance_Statistics_Service (حساب) → قاعدة البيانات.
 * لا مسار بديل يتجاوز طبقة التفويض ممكن معمارياً (لا "public singleton"، ولا
 * "public static get_instance()"، ولا "global helper" يُعيد الكائن الخام).
 *
 * ============================================================================
 * مصدر الحقيقة الوحيد (Requirement 2)
 * ============================================================================
 * كل حساب هنا يُشتَق حصراً من مصدرين، بلا استثناء:
 *   1. wp_pge_event_rsvps — جدول RSVP/الحضور (rsvp-handler.php + Phase 4 Schema).
 *   2. wp_pge_checkin_audit_log — سجل التدقيق الذري Append-Only (Phase 4 Schema).
 * لا قراءة من _pge_invited_guests (قائمة المدعوين الخام)، ولا من أي قيمة
 * محسوبة/مخبَّأة في الواجهة، ولا وثوق بأي حالة من المتصفح.
 *
 * ============================================================================
 * قرار تعريف "Total Invitations" (توثيق صريح لقرار تصميم)
 * ============================================================================
 * "Total Invitations" هنا = عدد صفوف wp_pge_event_rsvps للمناسبة (كل صف
 * RSVP يمثّل "سجل دعوة" حسب المصطلح المُعتمَد في هذا المشروع منذ تصحيحات
 * Phase 4 — راجع تعليقات PGE_Checkin_Recorder: "The protected entity is the
 * RSVP / invitation record"). لا تُحتسَب هنا الأسماء الموجودة في
 * _pge_invited_guests فقط بلا صف RSVP فعلي بعد (Requirement 2 يمنع القراءة من
 * ذلك المصدر أصلاً لأغراض الإحصاء) — هذا قرار تصميم مقصود ومُوثَّق، لا سهو.
 *
 * ============================================================================
 * Expected vs Actual — استقلال تام (Requirement 4)
 * ============================================================================
 *   - expected_guests: SUM(1 + companions) عبر **كل** صفوف RSVP للمناسبة (بصرف
 *     النظر عن حالة تسجيل الحضور) — "كم شخصاً متوقَّعاً إجمالاً وفق كل الردود
 *     المسجَّلة حتى الآن؟".
 *   - actual_attendees: SUM(actual_entered_count) عبر صفوف RSVP **المسجَّل
 *     حضورها فقط** (checked_in = 1) — "كم شخصاً دخل فعلياً؟". العمود يبقى
 *     NULL/غير مُحتسَب لأي صف لم يُسجَّل حضوره بعد (نفس دلالة Phase 4 Schema).
 * لا دمج بين الاثنين إطلاقاً في أي حساب — كل منهما عمود مستقل تماماً في نتيجة
 * get_attendance_summary()، ويُقرآن من نفس الاستعلام المُجمَّع الواحد لتفادي
 * أي احتمال تضارب بين مصدرين مختلفين لنفس الرقم.
 *
 * ============================================================================
 * الاتساق عبر طرق التسجيل المختلفة (Requirement 6)
 * ============================================================================
 * لا استعلام هنا يُفلتر أو يتفرَّع بناءً على عمود checkin_method/method — كل
 * الحسابات تعتمد فقط على checked_in/actual_entered_count (RSVP) وaudit_log
 * (بصرف النظر عن قيمة method فيها). بما أن PGE_Checkin_Recorder هو الكاتب
 * الوحيد لهذين المصدرين لكل من QR واليدوي (ومستقبلاً أي واجهة أخرى تُستخدَم
 * عبر نفس Recorder)، فالنتائج متطابقة بنيوياً بصرف النظر عن طريقة التسجيل —
 * لا حاجة لأي معالجة خاصة هنا لضمان ذلك.
 *
 * ============================================================================
 * الأداء (Requirement 7) — استعلامات مُجمَّعة، لا N+1
 * ============================================================================
 * كل دالة هنا تنفّذ عدداً ثابتاً من الاستعلامات (لا يتناسب مع عدد صفوف RSVP في
 * المناسبة): get_attendance_summary() استعلام واحد فقط (SUM/COUNT مُجمَّعة)؛
 * get_recent_checkins() استعلامان فقط (audit_log محدود بـLIMIT، ثم دفعة واحدة
 * IN (...) على RSVP بدل استعلام منفرد لكل سطر)؛ get_supervisor_summary()
 * استعلامان فقط (GROUP BY مُجمَّع على audit_log، ثم دفعة واحدة عبر
 * PGE_Supervisor_Assignment_Service::list_assignments_for_event()). يدعم هذا
 * مناسبات كبيرة دون تدهور أداء مع نمو عدد الضيوف/عمليات تسجيل الحضور.
 */
class PGE_Attendance_Statistics_Service
{
    /** الحد الافتراضي لعدد "آخر عمليات تسجيل الحضور" المُعادة. */
    const DEFAULT_RECENT_LIMIT = 10;

    /**
     * باني خاص — لا يمكن استدعاؤه إطلاقاً من خارج هذا الصف. الكائن الوحيد
     * الصالح من هذا الصف يُبنى حصراً داخل PGE_Attendance_Dashboard_Provider عبر
     * ReflectionClass::newInstanceWithoutConstructor()، الذي يتخطّى هذا الباني
     * أصلاً (لا حالة داخلية يهيّئها، فلا ضرر من تخطّيه لصاحب الوصول المخوَّل
     * الوحيد). أي محاولة `new self()`/`new PGE_Attendance_Statistics_Service()`
     * من أي ملف آخر في المشروع تفشل بخطأ PHP فادح صريح، وليس فقط تحذيراً.
     */
    private function __construct()
    {
    }

    private static function rsvps_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_event_rsvps';
    }

    private static function audit_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_checkin_audit_log';
    }

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
     * تقنيع الهاتف للعرض الآمن — نفس القاعدة المُستخدَمة في
     * PGE_Supervisor_Portal_Bootstrap::mask_phone() وPGE_Guest_Resolution_
     * Service::mask_phone_for_display() حرفياً، مُكرَّرة عمداً هنا (نفس القرار
     * المعماري المُوثَّق سابقاً: لا اعتماد جديد بين الملفات لأجل ثلاثة أسطر).
     */
    private static function mask_phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $len = strlen($digits);
        if ($len <= 4) {
            return $digits;
        }
        return str_repeat('•', $len - 4) . substr($digits, -4);
    }

    /**
     * ========================================================================
     * ملخّص الحضور (Requirement 3/4) — الدالة المركزية الوحيدة لكل الأرقام
     * ========================================================================
     * استعلام SQL واحد مُجمَّع (COUNT/SUM عبر CASE WHEN) — لا حلقة PHP على
     * صفوف RSVP إطلاقاً (Requirement 7).
     *
     * دالة كائن (instance method) عمداً — لا يمكن استدعاؤها بلا كائن صالح من
     * هذا الصف، والكائن الصالح الوحيد مملوك لـPGE_Attendance_Dashboard_Provider
     * حصراً (راجع تعليق الصف أعلاه).
     *
     * @return array{
     *   event_id:int, total_invitations:int, checked_in_invitations:int,
     *   pending_invitations:int, expected_guests:int, actual_attendees:int,
     *   attendance_rate:float, average_guests_per_invitation:float
     * }
     */
    public function get_attendance_summary(int $event_id): array
    {
        // حرس عبور الإصدارات: استدعاء هذه الدالة Statically بلا كائن (حتى لو
        // تجاوز أحدهم الباني الخاص بطريقة ما) يفشل هنا فوراً بخطأ PHP فادح
        // "Using $this when not in object context" على كل إصدارات PHP.
        $this;

        $event_id = self::normalize_positive_id($event_id);
        if ($event_id === 0) {
            return self::empty_attendance_summary(0);
        }

        global $wpdb;
        $table = self::rsvps_table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS total_invitations,
                    COALESCE(SUM(CASE WHEN checked_in = 1 THEN 1 ELSE 0 END), 0) AS checked_in_invitations,
                    COALESCE(SUM(1 + companions), 0) AS expected_guests,
                    COALESCE(SUM(CASE WHEN checked_in = 1 THEN actual_entered_count ELSE 0 END), 0) AS actual_attendees
                FROM $table
                WHERE event_id = %d",
                $event_id
            ),
            ARRAY_A
        );

        if ($row === null) {
            return self::empty_attendance_summary($event_id);
        }

        $total_invitations = (int) ($row['total_invitations'] ?? 0);
        $checked_in_invitations = (int) ($row['checked_in_invitations'] ?? 0);
        $expected_guests = (int) ($row['expected_guests'] ?? 0);
        $actual_attendees = (int) ($row['actual_attendees'] ?? 0);
        $pending_invitations = max(0, $total_invitations - $checked_in_invitations);

        $attendance_rate = $total_invitations > 0
            ? round($checked_in_invitations / $total_invitations, 4)
            : 0.0;
        $average_guests_per_invitation = $total_invitations > 0
            ? round($expected_guests / $total_invitations, 4)
            : 0.0;

        return [
            'event_id' => $event_id,
            'total_invitations' => $total_invitations,
            'checked_in_invitations' => $checked_in_invitations,
            'pending_invitations' => $pending_invitations,
            'expected_guests' => $expected_guests,
            'actual_attendees' => $actual_attendees,
            'attendance_rate' => $attendance_rate,
            'average_guests_per_invitation' => $average_guests_per_invitation,
        ];
    }

    private static function empty_attendance_summary(int $event_id): array
    {
        return [
            'event_id' => $event_id,
            'total_invitations' => 0,
            'checked_in_invitations' => 0,
            'pending_invitations' => 0,
            'expected_guests' => 0,
            'actual_attendees' => 0,
            'attendance_rate' => 0.0,
            'average_guests_per_invitation' => 0.0,
        ];
    }

    /**
     * ========================================================================
     * آخر عمليات تسجيل حضور (Requirement 5 — Dashboard Provider)
     * ========================================================================
     * استعلامان فقط: audit_log محدود بـLIMIT (مُرتَّب زمنياً تنازلياً)، ثم دفعة
     * واحدة على RSVP عبر IN (...) لجلب اسم/هاتف الضيف — لا استعلام منفرد لكل
     * سطر (Requirement 7).
     *
     * دالة كائن عمداً — نفس القيد المطبَّق على get_attendance_summary() أعلاه.
     *
     * @return array<int,array{rsvp_id:int,assignment_id:int,method:string,expected_count:int,actual_count:int,checked_in_at:string,guest_name:string,masked_phone:string}>
     */
    public function get_recent_checkins(int $event_id, int $limit = self::DEFAULT_RECENT_LIMIT): array
    {
        // نفس حرس عبور الإصدارات المُوثَّق أعلاه.
        $this;

        $event_id = self::normalize_positive_id($event_id);
        $limit = $limit > 0 ? $limit : self::DEFAULT_RECENT_LIMIT;
        if ($event_id === 0) {
            return [];
        }

        global $wpdb;
        $audit_table = self::audit_table_name();

        $audit_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT rsvp_id, assignment_id, method, expected_count, actual_count, created_at
                FROM $audit_table
                WHERE event_id = %d
                ORDER BY created_at DESC, id DESC
                LIMIT %d",
                $event_id,
                $limit
            ),
            ARRAY_A
        );

        if (empty($audit_rows)) {
            return [];
        }

        $rsvp_ids = array_values(array_unique(array_map(function ($r) {
            return (int) $r['rsvp_id'];
        }, $audit_rows)));

        $rsvp_by_id = self::fetch_rsvp_rows_by_ids($rsvp_ids);

        $result = [];
        foreach ($audit_rows as $row) {
            $rsvp_id = (int) $row['rsvp_id'];
            $rsvp = $rsvp_by_id[$rsvp_id] ?? null;
            $result[] = [
                'rsvp_id' => $rsvp_id,
                'assignment_id' => (int) $row['assignment_id'],
                'method' => (string) $row['method'],
                'expected_count' => (int) $row['expected_count'],
                'actual_count' => (int) $row['actual_count'],
                'checked_in_at' => (string) $row['created_at'],
                'guest_name' => (string) ($rsvp['guest_name'] ?? ''),
                'masked_phone' => $rsvp ? self::mask_phone((string) $rsvp['guest_phone']) : '',
            ];
        }

        return $result;
    }

    /** جلب دفعي بمعرّفات RSVP — استعلام واحد بصرف النظر عن عدد المعرّفات. */
    private static function fetch_rsvp_rows_by_ids(array $rsvp_ids): array
    {
        $rsvp_ids = array_values(array_filter(array_map('intval', $rsvp_ids), function ($id) {
            return $id > 0;
        }));
        if (empty($rsvp_ids)) {
            return [];
        }

        global $wpdb;
        $table = self::rsvps_table_name();
        $placeholders = implode(',', array_fill(0, count($rsvp_ids), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, guest_phone, guest_name FROM $table WHERE id IN ($placeholders)",
                $rsvp_ids
            ),
            ARRAY_A
        );

        $by_id = [];
        foreach ((array) $rows as $row) {
            $by_id[(int) $row['id']] = $row;
        }
        return $by_id;
    }

    /**
     * ========================================================================
     * ملخّص المشرفين (Requirement 5 — Dashboard Provider)
     * ========================================================================
     * استعلامان فقط: تجميع GROUP BY على audit_log، ثم دفعة واحدة عبر
     * PGE_Supervisor_Assignment_Service::list_assignments_for_event() (المصدر
     * الوحيد المخوَّل لقراءة جدول الإسناد — لا قراءة مباشرة له هنا). كل مشرف
     * مُسنَد للمناسبة يظهر في النتيجة حتى لو لم يسجّل أي حضور بعد
     * (checkins_recorded = 0) — دمج كامل، لا اقتصار على من ظهر في audit_log.
     *
     * دالة كائن عمداً — نفس القيد المطبَّق أعلاه.
     *
     * @return array<int,array{assignment_id:int,supervisor_name:string,masked_phone:string,status:string,checkins_recorded:int,guests_recorded:int}>
     */
    public function get_supervisor_summary(int $event_id): array
    {
        // نفس حرس عبور الإصدارات المُوثَّق أعلاه.
        $this;

        $event_id = self::normalize_positive_id($event_id);
        if ($event_id === 0) {
            return [];
        }

        global $wpdb;
        $audit_table = self::audit_table_name();

        $agg_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT assignment_id, COUNT(*) AS checkins_recorded, COALESCE(SUM(actual_count), 0) AS guests_recorded
                FROM $audit_table
                WHERE event_id = %d
                GROUP BY assignment_id",
                $event_id
            ),
            ARRAY_A
        );

        $agg_by_assignment = [];
        foreach ((array) $agg_rows as $row) {
            $agg_by_assignment[(int) $row['assignment_id']] = [
                'checkins_recorded' => (int) $row['checkins_recorded'],
                'guests_recorded' => (int) $row['guests_recorded'],
            ];
        }

        $assignments = class_exists('PGE_Supervisor_Assignment_Service')
            ? PGE_Supervisor_Assignment_Service::list_assignments_for_event($event_id)
            : [];

        $result = [];
        foreach ($assignments as $assignment) {
            $assignment_id = (int) ($assignment['id'] ?? 0);
            if ($assignment_id <= 0) {
                continue;
            }
            $agg = $agg_by_assignment[$assignment_id] ?? ['checkins_recorded' => 0, 'guests_recorded' => 0];
            $result[] = [
                'assignment_id' => $assignment_id,
                'supervisor_name' => (string) ($assignment['supervisor_name'] ?? ''),
                'masked_phone' => self::mask_phone((string) ($assignment['supervisor_phone'] ?? '')),
                'status' => (string) ($assignment['status'] ?? ''),
                'checkins_recorded' => $agg['checkins_recorded'],
                'guests_recorded' => $agg['guests_recorded'],
            ];
        }

        return $result;
    }
}
