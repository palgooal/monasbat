<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Attendance Dashboard Provider — Entry Check-in Supervisors، Phase 5
 * ============================================================================
 * "Attendance Statistics Engine" RFC، Requirement 5: "Dashboard Provider —
 * Create a read-only provider returning: Event Summary, Supervisor Summary,
 * Attendance Summary, Recent Check-ins. Everything prepared for future UI.
 * No HTML generation." وRequirement 8 (Authorization) وRequirement 9 (No UI).
 *
 * هذا الملف هو **حدود الطلب المُخوَّل الوحيدة** لإحصاءات الحضور — أي مستهلك
 * مستقبلي (AJAX/API/تطبيق جوال) يجب أن يمرّ عبر get_dashboard() هنا حصراً. هذا
 * لم يعد مجرد اتفاق توثيقي: PGE_Attendance_Statistics_Service صار غير قابل
 * للإنشاء من خارج هذا الملف إطلاقاً (باني خاص + دوال كائن لا static) — راجع
 * تعليق ذلك الملف. المسار الوحيد الممكن معمارياً هو:
 *   Authorized Consumer → get_dashboard() (هنا) → authorize() (تفويض قبل أي
 *   استعلام) → engine() (الآلية الداخلية الخاصة أدناه) →
 *   PGE_Attendance_Statistics_Service (حساب) → قاعدة البيانات.
 * لا استعلام تجميع واحد يُنفَّذ قبل نجاح authorize() — راجع ترتيب get_dashboard()
 * أدناه: authorize() يُستدعى وتُفحَص نتيجته أولاً، وengine() لا يُستدعى مطلقاً
 * إلا بعد نجاح ذلك.
 *
 * ============================================================================
 * الآلية الداخلية الوحيدة لبناء المحرّك — engine() (private)
 * ============================================================================
 * PGE_Attendance_Statistics_Service::__construct() خاص، فلا يمكن لأي كود بناء
 * كائن منه عبر `new` مباشرة. الآلية الوحيدة المخوَّلة لبناء كائن صالح منه هي
 * دالة engine() الخاصة أدناه، عبر ReflectionClass::newInstanceWithoutConstructor()
 * (يتخطّى الباني الخاص عمداً — آمن هنا لأن الصف عديم الحالة الداخلية أصلاً).
 * هذه الدالة private ولا تُعرَض عبر أي getter عام في أي مكان — لا سبيل لأي
 * مستهلك خارجي (حتى لو امتلك مرجعاً لهذا الصف) للحصول على الكائن الخام.
 *
 * ============================================================================
 * اللقطة الواحدة الذرية (Phase 6 Final Fix — "Atomic Dashboard Snapshot")
 * ============================================================================
 * "Every dashboard refresh must represent one logical snapshot. Never mix
 * data from two different moments." — get_dashboard() يبقى استدعاءً واحداً
 * فقط (One Provider call) يُعيد حزمة واحدة (One dashboard payload) تحتوي كل
 * الأقسام معاً: event_summary وsupervisor_summary وattendance_summary
 * وrecent_checkins (كما كانت) بالإضافة إلى quick_actions (حالة أزرار الإجراءات
 * السريعة — ثابتة، لا منطق أعمال جديد ولا تغيير سلوك) وdashboard_metadata
 * (generated_at/snapshot_id — طابع زمني واحد يُحسَب مرّة واحدة فقط لكل نداء،
 * ويُشارَك بين كل الأقسام في نفس الحزمة — لا طابع زمني منفصل لكل قسم). هذا لا
 * يغيّر منطق التفويض ولا الحساب إطلاقاً — إضافة بيانات وصفية بحتة فقط.
 *
 * ============================================================================
 * التفويض (Requirement 8) — "Authenticated Supervisor Session OR Authorized
 * Host. Never expose another event's statistics."
 * ============================================================================
 * مسندان اثنان فقط، بهذا الترتيب:
 *   1. "Authorized Host" — pge_is_host_or_admin($event_id) الحالية والمركزية
 *      (helpers.php) — نفس الدالة المُستخدَمة في كل AJAX handler عبر المشروع
 *      كله (Requirement 1 هنا: "No controller may calculate statistics
 *      directly" يُقابله معمارياً "No controller may reinvent authorization
 *      directly" — إعادة استخدام الدالة المركزية الموجودة فعلاً، لا دالة
 *      تفويض موازية جديدة).
 *   2. "Authenticated Supervisor Session" — PGE_Supervisor_Portal_Middleware::
 *      authorize() (Phase 3.5) — **يجب** أن يتطابق event_id المُستخرَج من
 *      الجلسة الموثوقة مع event_id المطلوب صراحةً هنا؛ عدم التطابق (مشرف نشط
 *      وجلسته صالحة، لكن لمناسبة أخرى) يُرفَض صراحةً بـ'event_mismatch'/403 —
 *      هذا هو إنفاذ "Never expose another event's statistics" فعلياً (Cross-
 *      event isolation).
 * لا وثوق بأي event_id من $_POST/$_GET/حقل مخفي في أي من المسندين — Host عبر
 * pge_is_host_or_admin() نفسها تتحقق من post_author الحقيقي، والمشرف عبر
 * الجلسة الموثوقة فقط (تماماً كـMiddleware الأصلية).
 */
class PGE_Attendance_Dashboard_Provider
{
    /**
     * كائن المحرّك المُنشأ داخلياً — private، لا يُعرَض عبر أي getter عام. يُبنى
     * مرة واحدة فقط لكل دورة طلب (memoization بسيطة)، ولا يُبنى إطلاقاً قبل
     * نجاح التفويض (راجع get_dashboard() أدناه).
     *
     * @var PGE_Attendance_Statistics_Service|null
     */
    private static $engine_instance = null;

    /**
     * نقطة الدخول الوحيدة المُخوَّلة — تُعيد إما رفضاً صريحاً (401/403/404) أو
     * حزمة بيانات كاملة جاهزة لواجهة مستقبلية (بلا أي HTML/تنسيق عرض).
     *
     * الترتيب هنا مقصود ومُلزِم: authorize() يُستدعى ويُفحَص **قبل** أي استدعاء
     * لـengine()/PGE_Attendance_Statistics_Service — رفض التفويض يعني عدم تنفيذ
     * أي استعلام تجميع إطلاقاً (Query Execution Guard).
     *
     * @return array{result:'denied',reason:string,http_status:int}
     *       | array{result:'error',reason:string}
     *       | array{result:'authorized',via:string,data:array{event_summary:array,supervisor_summary:array,attendance_summary:array,recent_checkins:array,quick_actions:array,dashboard_metadata:array{generated_at:string,snapshot_id:string}}}
     *
     * ============================================================================
     * Phase 10 ("Event Operations") — إضافة إضافية بحتة، متوافقة خلفياً
     * ============================================================================
     * $recent_checkins_limit مُعامل اختياري جديد (افتراضه 10 — **نفس القيمة
     * الافتراضية الحالية لـPGE_Attendance_Statistics_Service::get_recent_checkins()
     * تماماً**، وهي نفسها القيمة التي كانت تُستخدَم ضمنياً هنا سابقاً عبر عدم
     * تمرير أي معامل إطلاقاً). أي مستهلك حالي يستدعي get_dashboard($event_id)
     * بمعامل واحد فقط (dashboard-ajax.php/Phase 6) يحصل على **نفس السلوك
     * الحالي بالضبط، بلا أي تغيير** — Zero Behavior Change لكل الاستدعاءات
     * القائمة (يُثبَت ذلك صراحةً في tests/test-event-operations.php).
     *
     * لماذا هذا ليس "تعديلاً" على منطق محظور: لا تغيير على PGE_Attendance_
     * Statistics_Service (الصف المُجمَّد فعلياً حسب Architecture Freeze) ولا
     * على get_recent_checkins() نفسها ولا على أي حساب/استعلام داخلها — الدالة
     * كانت أصلاً تقبل $limit (راجع تعليقها)، وهذا التغيير هنا هو فقط تمرير
     * (Pass-Through) لقيمة كانت تُهمَل ضمنياً من قبل. PGE_Attendance_Dashboard_
     * Provider نفسه (هذا الملف) غير مُدرَج في قائمة "Architecture Freeze" لهذه
     * المرحلة (Invitation/QR/Guest Resolution/Attendance Recorder/Attendance
     * Statistics/Invitation CRUD-Search-Filtering-Export/Supervisor Identity-
     * Sessions/Authorization/Quota/Audit/Delivery Request) — فهو طبقة التجميع
     * ذاتها التي تطلب هذه المرحلة صراحةً البناء فوقها.
     *
     * سبب الحاجة: قسم "آخر عمليات تسجيل الحضور" في لوحة عمليات المناسبة
     * (Event Operations Dashboard) يتطلَّب "عدد قابل للتهيئة، افتراضه 20" —
     * أكبر من افتراض لوحة المشرف (10، Phase 6، غير مُغيَّر). التفويض/الحساب/
     * الاستعلام كلها كما هي تماماً؛ فقط عدد الصفوف المُعادة يتغيَّر.
     */
    public static function get_dashboard(int $event_id, int $recent_checkins_limit = 10): array
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) {
            return ['result' => 'denied', 'reason' => 'invalid_event_id', 'http_status' => 404];
        }

        // التفويض أولاً — لا استدعاء لـengine()/Statistics Service قبل هذا السطر.
        $authorization = self::authorize($event_id);
        if (($authorization['result'] ?? '') !== 'authorized') {
            return $authorization;
        }

        $engine = self::engine();
        if ($engine === null) {
            return ['result' => 'error', 'reason' => 'statistics_service_unavailable'];
        }

        // حماية دنيا لقيمة المعامل الجديد فقط (Sanitization بحتة، لا حساب) —
        // نفس نطاق $limit الذي توثِّقه get_recent_checkins() نفسها أصلاً.
        $recent_checkins_limit = ($recent_checkins_limit > 0) ? $recent_checkins_limit : 10;

        // طابع زمني واحد يُحسَب هنا مرّة واحدة فقط لكل نداء — يُشارَك بين كل
        // أقسام الحزمة الواحدة أدناه (لا استدعاء current_time() منفصل لكل
        // قسم)، ضامناً أن كل الأقسام المعروضة تخصّ نفس اللحظة المنطقية بالضبط.
        $generated_at = function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s');
        $snapshot_id = function_exists('wp_generate_uuid4')
            ? (string) wp_generate_uuid4()
            : md5($event_id . '|' . $generated_at . '|' . wp_rand());

        return [
            'result' => 'authorized',
            'via' => (string) $authorization['via'],
            'data' => [
                'event_summary' => self::get_event_summary($event_id),
                'supervisor_summary' => $engine->get_supervisor_summary($event_id),
                'attendance_summary' => $engine->get_attendance_summary($event_id),
                'recent_checkins' => $engine->get_recent_checkins($event_id, $recent_checkins_limit),
                'quick_actions' => self::get_quick_actions_state(),
                'dashboard_metadata' => [
                    'generated_at' => $generated_at,
                    'snapshot_id' => $snapshot_id,
                ],
            ],
        ];
    }

    /**
     * حالة أزرار الإجراءات السريعة — بيانات وصفية ثابتة حالياً (Phase 6 Final
     * Fix Requirement: "Quick Actions State" جزء من نفس الحزمة الواحدة)؛ لا
     * منطق أعمال جديد، ولا تغيير في سلوك الأزرار الفعلي إطلاقاً (Scope Guard:
     * "Do NOT change Quick Action behaviour") — مجرّد نقل توصيف حالتها إلى
     * البيانات بدل قيم مُثبَّتة في القالب فقط، لضمان أنها جزء من نفس اللقطة.
     *
     * @return array<string,array{enabled:bool,reason:?string}>
     */
    private static function get_quick_actions_state(): array
    {
        return [
            'manual_checkin' => ['enabled' => false, 'reason' => 'coming_soon'],
            'qr_checkin' => ['enabled' => false, 'reason' => 'coming_soon'],
            'refresh_dashboard' => ['enabled' => true, 'reason' => null],
        ];
    }

    /**
     * الآلية الداخلية الوحيدة المخوَّلة لبناء كائن PGE_Attendance_Statistics_
     * Service — private، غير مُعرَّضة عبر أي getter عام. تستخدم Reflection
     * عمداً لتخطّي الباني الخاص لذلك الصف (الصف عديم الحالة الداخلية، فلا ضرر
     * من تخطّي الباني لصاحب الوصول المخوَّل الوحيد). لا كود آخر في المشروع
     * يمتلك أو يحتاج امتلاك هذه القدرة.
     */
    private static function engine(): ?PGE_Attendance_Statistics_Service
    {
        if (self::$engine_instance !== null) {
            return self::$engine_instance;
        }
        if (!class_exists('PGE_Attendance_Statistics_Service')) {
            return null;
        }
        try {
            $reflection = new ReflectionClass('PGE_Attendance_Statistics_Service');
            self::$engine_instance = $reflection->newInstanceWithoutConstructor();
        } catch (\Throwable $e) {
            return null;
        }
        return self::$engine_instance;
    }

    /**
     * @return array{result:'authorized',via:string}
     *       | array{result:'denied',reason:string,http_status:int}
     */
    private static function authorize(int $event_id): array
    {
        // 1. مضيف المناسبة أو أدمن — الدالة المركزية الموجودة فعلاً، بلا تكرار.
        if (function_exists('pge_is_host_or_admin') && pge_is_host_or_admin($event_id)) {
            return ['result' => 'authorized', 'via' => 'host'];
        }

        // 2. جلسة مشرف موثوقة — يجب أن تتطابق مع نفس المناسبة المطلوبة تحديداً.
        if (class_exists('PGE_Supervisor_Portal_Middleware')) {
            $authz = PGE_Supervisor_Portal_Middleware::authorize();

            if (($authz['result'] ?? '') === 'authorized') {
                if ((int) ($authz['event_id'] ?? 0) === $event_id) {
                    return ['result' => 'authorized', 'via' => 'supervisor'];
                }
                // مصرَّح فعلاً، لكن لمناسبة مختلفة تماماً — لا كشف لإحصاء مناسبة
                // أخرى إطلاقاً (Requirement 8: "Never expose another event's
                // statistics") — Cross-event isolation صريحة.
                return ['result' => 'denied', 'reason' => 'event_mismatch', 'http_status' => 403];
            }

            // غير مصرَّح أصلاً — إعادة استخدام كود HTTP الصريح من Middleware
            // نفسها (401/403/404 حسب السبب الحقيقي، نفس فلسفة Phase 3.5).
            return [
                'result' => 'denied',
                'reason' => (string) ($authz['reason'] ?? 'unauthorized'),
                'http_status' => (int) ($authz['http_status'] ?? 401),
            ];
        }

        return ['result' => 'denied', 'reason' => 'unauthorized', 'http_status' => 401];
    }

    /**
     * ملخّص المناسبة — بيانات عرض غير حساسة فقط (لا هاتف مضيف خام). قراءة فقط
     * من WordPress Post/Post Meta الحالية، بلا أي إعادة تنفيذ لمنطق آخر.
     *
     * @return array{event_id:int,title:string,date:string,address:string,host_name:string}
     */
    private static function get_event_summary(int $event_id): array
    {
        $title = function_exists('get_the_title') ? (string) get_the_title($event_id) : '';
        $date = (string) get_post_meta($event_id, '_pge_event_date', true);
        $address = (string) get_post_meta($event_id, '_pge_event_address', true);

        $host_name = '';
        $author_id = (int) get_post_field('post_author', $event_id);
        if ($author_id > 0 && function_exists('get_userdata')) {
            $user = get_userdata($author_id);
            if ($user) {
                $host_name = (string) $user->display_name;
            }
        }

        return [
            'event_id' => $event_id,
            'title' => $title,
            'date' => $date,
            'address' => $address,
            'host_name' => $host_name,
        ];
    }
}
