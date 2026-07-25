<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Credit Ledger — سجل ذري لاستهلاك رصيد الدعوات
 * ============================================================================
 * Invitation Credits Engine، المرحلة الثانية: "تأسيس دورة الرصيد وسجل
 * الاستهلاك الذري فقط". هذه الطبقة (Repository) هي الوسيط الوحيد المسموح
 * للتعامل مع جدول {$wpdb->prefix}mon_invitation_credit_ledger (راجع
 * class-mon-catalog-schema.php لتعريف الجدول والقيد UNIQUE).
 *
 * لا تُستدعى دوالها حالياً من أي مسار إرسال واتساب أو RSVP أو حذف مدعوين —
 * هذه المرحلة بنية بيانات وخدمات داخلية فقط، بلا أي خصم أو استرداد فعلي.
 * لا تلمس أي من user meta الأربعة (_mon_invitation_credit_used وغيرها)
 * إطلاقاً؛ سجل الاستهلاك مستقل تماماً عن تلك العدادات في هذه المرحلة.
 *
 * الحماية الحقيقية ضد الخصم المزدوج (عند تشغيل أكثر من مسار إرسال متزامن:
 * يدوي/Queue/Cron/Cartat/UltraMsg) هي القيد UNIQUE KEY
 * unique_credit_consumption (credit_cycle_id, event_id, guest_phone,
 * credit_type) على مستوى قاعدة البيانات نفسها — لا أي فحص تطبيقي "افحص ثم
 * أدرج" بمفرده؛ create_reservation() أدناه تعتمد أولاً على محاولة INSERT
 * فعلية وتُفسِّر فشلها بسبب تعارض المفتاح كنتيجة "already_exists" آمنة،
 * لا كخطأ قاتل.
 */

class PGE_Invitation_Credit_Ledger
{
    /**
     * القيم الرسمية المسموحة لـcredit_type — بنفس نمط ALLOWED_* في
     * class-pge-catalog.php (ثوابت خاصة، تُستخدم فقط داخل دوال normalize_*).
     */
    private const ALLOWED_CREDIT_TYPES = [
        'primary',
        'replacement',
    ];

    /**
     * القيم الرسمية المسموحة لـstatus. الانتقالات المسموحة بينها (يُفرَض
     * ذلك في mark_consumed()/mark_refunded()/mark_consumed_with_token()/
     * mark_failed_with_token()/claim_for_delivery() لا هنا):
     * reserved → consumed → refunded، وreserved → failed → reserved (عبر
     * claim_for_delivery() فقط — إعادة محاولة صف فشل سابقاً)، بلا أي مسار
     * عكسي آخر أو تجاوز مرحلة. failed أُضيفت في المرحلة الثالثة A (1.6.0)
     * لتمييز "الرسالة رُفضت من Cartat صراحةً" عن "لا تزال قيد المحاولة".
     */
    private const ALLOWED_STATUSES = [
        'reserved',
        'consumed',
        'refunded',
        'failed',
    ];

    /**
     * مدة صلاحية "محاولة التسليم" بالثواني (Attempt Lease) — إصلاح Blocker
     * منطقي في المرحلة الثالثة A: عند transport_error يبقى الصف reserved
     * بتوكن نشط (متعمَّد، راجع توثيق claim_for_delivery() أدناه)، وبدون سقف
     * زمني كان أي claim لاحق لنفس المدعو يعيد in_progress إلى الأبد — تجميد
     * دائم للمدعو وللرصيد المحجوز معاً. هذا الثابت هو المصدر الوحيد لقيمة
     * المهلة (لا تكرار لها في أي موضع آخر بالملف)، ويُستخدَم حصراً داخل
     * is_lease_expired() أدناه.
     *
     * القيمة 120 ثانية: أكبر بهامش أمان واضح من timeout الفعلي لـ
     * wp_remote_post() في Mon_Cartat_Handler::api_request() (20 ثانية)،
     * ومطابقة لقيمة @set_time_limit(120) المستخدمة فعلاً في
     * Mon_Cartat_Handler::send_invitations()/cron_process_queue() — أي
     * طلب Cartat حقيقي (ناجحاً أو فاشلاً بوضوح) يجب أن يكون قد حسم أمره
     * خلال هذه المدة في كل الأحوال الطبيعية.
     */
    public const ATTEMPT_LEASE_SECONDS = 120;

    /**
     * اسم جدول السجل مع بادئة $wpdb->prefix.
     */
    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'mon_invitation_credit_ledger';
    }

    /**
     * هل انتهت مهلة (Lease) محاولة تسليم بدأت عند $attempt_started_at؟ مقارنة
     * الوقت تتم عبر strtotime() على كلا الطرفين بعد إلحاق ' UTC' صراحةً —
     * كلاهما (القيمة المخزَّنة والوقت الحالي) يُكتَبان دائماً عبر
     * current_time('mysql', true) (GMT صراحةً، وسيط $gmt=true)، فإلحاق UTC
     * على الطرفين معاً يضمن حساب الفارق بدقة بصرف النظر عن المنطقة الزمنية
     * الافتراضية لخادم PHP نفسه (لا اعتماد على إعداد timezone للسيرفر).
     * قيمة فارغة أو غير قابلة للتحليل تُعامَل كمنتهية الصلاحية دفاعياً (أكثر
     * أماناً من افتراض أنها لا تزال سارية إلى الأبد).
     */
    private static function is_lease_expired($attempt_started_at): bool
    {
        if (empty($attempt_started_at)) {
            return true;
        }

        $started_ts = strtotime((string) $attempt_started_at . ' UTC');
        if ($started_ts === false) {
            return true;
        }

        $now_ts = strtotime(current_time('mysql', true) . ' UTC');
        if ($now_ts === false) {
            return true;
        }

        return ($now_ts - $started_ts) >= self::ATTEMPT_LEASE_SECONDS;
    }

    /**
     * تطبيع والتحقق من قيمة credit_type مقابل self::ALLOWED_CREDIT_TYPES.
     * تقبل string فقط؛ أي نوع آخر يُعيد false فوراً. تُطبَّع القيمة عبر
     * trim() ثم strtolower()، والمقارنة عبر in_array(..., true) (strict).
     * عند النجاح تُعاد القيمة المطبَّعة؛ وإلا تُعاد false — نفس نمط false =
     * رفض المدخل المستخدم في class-pge-catalog.php (لا استثناء يُرمى هنا،
     * اتساقاً مع بقية المشروع الذي يعتمد قيم إرجاع مميّزة بدل Exceptions).
     */
    public static function normalize_credit_type($value)
    {
        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALLOWED_CREDIT_TYPES, true)) {
            return false;
        }

        return $normalized;
    }

    /**
     * تطبيع والتحقق من قيمة status مقابل self::ALLOWED_STATUSES. نفس قواعد
     * normalize_credit_type() تماماً (string فقط، trim+lowercase،
     * in_array strict)، وتُعيد false عند الفشل.
     */
    public static function normalize_status($value)
    {
        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALLOWED_STATUSES, true)) {
            return false;
        }

        return $normalized;
    }

    /**
     * تطبيع معرّف موجب (user_id/event_id/id الصف). نفس قاعدة
     * normalize_positive_id() في class-mon-events-users.php وget_plan()/
     * get_tier() في class-pge-catalog.php: int موجب، أو string مكوّنة بالكامل
     * من رقم صحيح موجب (نمط ^[1-9][0-9]*$). أي شيء آخر يُعيد 0.
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
     * تطبيع رقم جوال المدعو قبل أي كتابة أو قراءة — عبر pge_norm_phone()
     * الحالية في helpers.php إن كانت محمَّلة (الحالة الطبيعية داخل الإضافة
     * الفعلية)، أو نفس منطقها كاحتياط (إزالة كل ما ليس رقماً) إن لم تكن
     * الدالة متاحة بعد (مثل بيئة اختبار معزولة). لا تكرار لمنطق تطبيع جديد.
     */
    private static function normalize_guest_phone($value)
    {
        if (function_exists('pge_norm_phone')) {
            return pge_norm_phone($value);
        }

        return preg_replace('/\D+/', '', trim((string) $value));
    }

    /**
     * قراءة صف واحد بمفتاحه الفريد الرباعي الكامل (نفس أعمدة القيد UNIQUE
     * بالضبط). تُطبِّع كل معامل بنفس قواعد create_reservation() أدناه؛ فشل
     * أي تطبيع يُعيد null فوراً دون استعلام. لا شرط على status هنا — تُقرأ
     * أي حالة (reserved/consumed/refunded).
     */
    public static function find_entry($credit_cycle_id, $event_id, $guest_phone, $credit_type)
    {
        $normalized_event_id = self::normalize_positive_id($event_id);
        if ($normalized_event_id === 0) {
            return null;
        }

        $credit_cycle_id = is_string($credit_cycle_id) ? trim($credit_cycle_id) : '';
        if ($credit_cycle_id === '') {
            return null;
        }

        $normalized_phone = self::normalize_guest_phone($guest_phone);
        if ($normalized_phone === '') {
            return null;
        }

        $normalized_type = self::normalize_credit_type($credit_type);
        if ($normalized_type === false) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_name() . " WHERE credit_cycle_id = %s AND event_id = %d AND guest_phone = %s AND credit_type = %s LIMIT 1",
                $credit_cycle_id,
                $normalized_event_id,
                $normalized_phone,
                $normalized_type
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * إنشاء حجز رصيد (status=reserved) لمدعو واحد ضمن مناسبة ودورة اشتراك
     * محددتين. تطبيع كامل لكل معامل أولاً (user_id/event_id موجبان،
     * credit_cycle_id غير فارغ، guest_phone غير فارغ بعد التطبيع، credit_type
     * ضمن القيم المسموحة) — أي فشل يُعيد ['result' => 'error', 'reason' => ...]
     * فوراً دون أي استعلام.
     *
     * الحماية الذرية ضد التكرار (خصم مزدوج عبر مسارين متزامنين لنفس المفتاح
     * الرباعي) تعتمد حصراً على القيد UNIQUE unique_credit_consumption عبر
     * محاولة $wpdb->insert() مباشرة — لا فحص "SELECT ثم INSERT" منفصل بوصفه
     * حماية وحيدة (Race Condition حقيقية لو اعتُمد عليه بمفرده). عند فشل
     * الإدخال، نميّز تعارض المفتاح الفريد (نتيجة متوقعة وآمنة) عن أي خطأ SQL
     * آخر عبر فحص $wpdb->last_error أولاً، ثم كاحتياط إضافي (نصوص أخطاء
     * محركات/إصدارات MySQL تختلف) نتحقق مباشرة من وجود الصف عبر find_entry()
     * — وجوده يعني تعارض UNIQUE حتماً، إذ لا مسار آخر ينتج نفس المفتاح
     * الرباعي بالضبط.
     *
     * القيم المُعادة (result) الثلاث المميَّزة صراحةً بحسب طلب المهمة:
     *  - 'created': صف جديد أُنشئ فعلاً — يتضمن 'id' للصف الجديد.
     *  - 'already_exists': الصف موجود مسبقاً (لم يُنشأ صف ثانٍ) — يتضمن 'id'
     *    للصف الموجود.
     *  - 'error': فشل حقيقي (تحقق فشل، أو خطأ SQL غير متعلق بالتكرار) —
     *    يتضمن 'reason' نصياً موجزاً، بلا أي تفاصيل SQL خام تُعرَض للمستخدم
     *    النهائي (هذا القيد لا يُعرَض لأي واجهة أصلاً في هذه المرحلة).
     */
    public static function create_reservation($user_id, $credit_cycle_id, $event_id, $guest_phone, $credit_type)
    {
        $normalized_user_id = self::normalize_positive_id($user_id);
        if ($normalized_user_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_user_id'];
        }

        $normalized_event_id = self::normalize_positive_id($event_id);
        if ($normalized_event_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_event_id'];
        }

        $credit_cycle_id = is_string($credit_cycle_id) ? trim($credit_cycle_id) : '';
        if ($credit_cycle_id === '') {
            return ['result' => 'error', 'reason' => 'invalid_credit_cycle_id'];
        }

        $normalized_phone = self::normalize_guest_phone($guest_phone);
        if ($normalized_phone === '') {
            return ['result' => 'error', 'reason' => 'invalid_guest_phone'];
        }

        $normalized_type = self::normalize_credit_type($credit_type);
        if ($normalized_type === false) {
            return ['result' => 'error', 'reason' => 'invalid_credit_type'];
        }

        global $wpdb;

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'user_id'         => $normalized_user_id,
                'credit_cycle_id' => $credit_cycle_id,
                'event_id'        => $normalized_event_id,
                'guest_phone'     => $normalized_phone,
                'credit_type'     => $normalized_type,
                'status'          => 'reserved',
                'created_at'      => current_time('mysql', true),
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted) {
            return ['result' => 'created', 'id' => (int) $wpdb->insert_id];
        }

        $last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
        $looks_like_duplicate_key = $last_error !== '' && stripos($last_error, 'duplicate') !== false;

        $existing = self::find_entry($credit_cycle_id, $normalized_event_id, $normalized_phone, $normalized_type);
        if ($existing !== null) {
            return ['result' => 'already_exists', 'id' => (int) $existing['id']];
        }

        if ($looks_like_duplicate_key) {
            // تعارض مفتاح واضح من رسالة الخطأ، لكن لم نجد الصف بالقراءة
            // (حالة نظرية غير متوقعة عملياً) — لا نُعيد "created" كذباً.
            return ['result' => 'error', 'reason' => 'duplicate_key_but_entry_not_found'];
        }

        return ['result' => 'error', 'reason' => 'insert_failed'];
    }

    /**
     * تحويل صف من reserved إلى consumed فقط. مشروط بقراءة الحالة الحالية
     * أولاً (SELECT id, status)، ثم تحديث ذري بشرط WHERE id + status='reserved'
     * معاً — وليس بـid فقط — لضمان أن أي سباق فعلي بين استدعاءين متزامنين
     * لا يمكن أن يُنتج انتقالاً غير مسموح: حتى لو قرأ الاستدعاءان "reserved"
     * كلاهما في نفس اللحظة، فـ$wpdb->update() الثاني الذي ينفَّذ فعلياً على
     * قاعدة البيانات (بعد الأول) لن يجد أي صف يطابق status='reserved' بعد
     * الآن (تغيّر إلى 'consumed' فعلاً)، فيُعيد 0 صفاً متأثراً بدل تحديث خاطئ.
     *
     * consumed تبقى consumed دون أي تغيير أو خطأ (Idempotent — تُعيد true).
     * refunded لا تتحول consumed أبداً في هذه المرحلة (تُعيد false).
     * صف غير موجود، أو id غير صالح، تُعيد false أيضاً.
     */
    public static function mark_consumed($id)
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return false;
        }

        global $wpdb;
        $table = self::table_name();

        $current = $wpdb->get_row(
            $wpdb->prepare("SELECT id, status FROM $table WHERE id = %d", $normalized_id),
            ARRAY_A
        );

        if ($current === null) {
            return false;
        }

        if ($current['status'] === 'consumed') {
            return true;
        }

        if ($current['status'] !== 'reserved') {
            return false;
        }

        $updated = $wpdb->update(
            $table,
            [
                'status'      => 'consumed',
                'consumed_at' => current_time('mysql', true),
            ],
            [
                'id'     => $normalized_id,
                'status' => 'reserved',
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        // false = خطأ SQL فعلي فقط. 0 صف متأثر (سباق نادر تفوَّق فيه استدعاء
        // آخر) لا يُعتبر فشلاً هنا — الحالة النهائية المطلوبة (consumed)
        // تحقّقت فعلياً بواسطة الاستدعاء الآخر، فهذا نجاح من منظور المستدعي.
        return $updated !== false;
    }

    /**
     * تحويل صف من consumed إلى refunded فقط — بنفس فلسفة mark_consumed()
     * تماماً (قراءة أولاً، تحديث ذري مشروط بـid + status='consumed' معاً).
     * refunded تبقى refunded دون تغيير (Idempotent — true). reserved لا
     * تتحول refunded أبداً في هذه المرحلة (false). صف غير موجود أو id غير
     * صالح → false.
     */
    public static function mark_refunded($id)
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return false;
        }

        global $wpdb;
        $table = self::table_name();

        $current = $wpdb->get_row(
            $wpdb->prepare("SELECT id, status FROM $table WHERE id = %d", $normalized_id),
            ARRAY_A
        );

        if ($current === null) {
            return false;
        }

        if ($current['status'] === 'refunded') {
            return true;
        }

        if ($current['status'] !== 'consumed') {
            return false;
        }

        $updated = $wpdb->update(
            $table,
            [
                'status'      => 'refunded',
                'refunded_at' => current_time('mysql', true),
            ],
            [
                'id'     => $normalized_id,
                'status' => 'consumed',
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        return $updated !== false;
    }

    // ══════════════════════════════════════════════════════════════════════
    // المرحلة الثالثة A — "محاولة تسليم مملوكة" (Owned Delivery Attempt)
    // ══════════════════════════════════════════════════════════════════════
    // مسار مستقل تماماً عن create_reservation()/mark_consumed()/
    // mark_refunded() أعلاه (تُبقيان كما هما بلا أي تغيير في عقدهما لأن
    // اختبارات المرحلة الثانية تعتمد عليهما). هذا المسار الجديد هو الوحيد
    // المُستخدَم من داخل Mon_Cartat_Handler::cron_process_queue() — يضيف
    // طبقتَي حماية اثنتين معاً ضد التزامن الحقيقي (راجع تدقيق المرحلة
    // الثالثة قبل التنفيذ):
    //   1) قفل GET_LOCK/RELEASE_LOCK على مستوى (user_id, credit_cycle_id,
    //      credit_type) — يمنع تشغيلتين متزامنتين (Cron مزدوج، أو AJAX +
    //      Cron) من قراءة نفس عدّ reserved+consumed ثم كلتيهما تحسبان أن
    //      المقعد الأخير في الرصيد متاح لهما معاً (Race Condition حقيقي على
    //      فحص السقف، لا يحميه القيد UNIQUE وحده — القيد يمنع فقط تكرار
    //      *نفس* المدعو، لا تجاوز *إجمالي* الرصيد بين مدعوين مختلفين).
    //   2) attempt_token عشوائي لكل "مطالبة" (claim) ناجحة — صف reserved
    //      "قديم" (من محاولة سابقة توقفت لأي سبب) لا يُعتبر تصريحاً مفتوحاً
    //      لأي عامل جديد لإنهائه؛ فقط حامل التوكن الحالي (آخر من نجح بـ
    //      claim_for_delivery() لهذا الصف) يستطيع استدعاء
    //      mark_consumed_with_token()/mark_failed_with_token() بنجاح.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * توليد attempt_token عشوائي صعب التخمين — 32 بايت عبر random_bytes()
     * (آمن تشفيرياً) محوَّلة إلى 64 حرف hex، تملأ عمود VARCHAR(64) بالضبط.
     */
    private static function generate_attempt_token(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * اسم قفل GET_LOCK مشتق وآمن من (user_id, credit_cycle_id, credit_type)
     * — نطاق القفل هو "مصدر الرصيد" الواحد (مستخدم + دورة + نوع)، لا صف
     * واحد ولا كل الجدول: هذا يسمح بمعالجة مدعوين مختلفين لمستخدمين مختلفين
     * (أو دورات/أنواع مختلفة لنفس المستخدم) بالتوازي التام، بينما يُسلسِل
     * فقط أي عمليتين تتنافسان فعلياً على نفس مجموعة الرصيد المحدودة. يُبنى
     * عبر md5() (32 حرف hex ثابت الطول) بادئة بنص واضح — الطول الإجمالي
     * (43 حرفاً) آمن ضمن حد MySQL التاريخي لأسماء GET_LOCK (64 حرفاً).
     */
    private static function build_credit_lock_name($user_id, $credit_cycle_id, $credit_type): string
    {
        return 'pge_credit_' . md5($user_id . '|' . $credit_cycle_id . '|' . $credit_type);
    }

    /**
     * عدّ الصفوف status IN ('reserved','consumed') — الاستهلاك الفعلي
     * الحالي لمصدر رصيد واحد (لا يُفلتَر بـevent_id عمداً: الرصيد مشترك بين
     * كل مناسبات المستخدم ضمن نفس الدورة، وفق القرار التجاري المعتمد).
     * دالة داخلية خاصة تفترض أن معاملاتها مُطبَّعة بالفعل من المستدعي
     * (claim_for_delivery() الوحيدة التي تستدعيها) — لا تطبيع مكرر هنا.
     */
    private static function count_reserved_or_consumed_unsafe($user_id, $credit_cycle_id, $credit_type): int
    {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_name() . " WHERE user_id = %d AND credit_cycle_id = %s AND credit_type = %s AND status IN ('reserved','consumed')",
            $user_id,
            $credit_cycle_id,
            $credit_type
        ));

        return (int) $count;
    }

    /**
     * "مطالبة" ذرية وآمنة بحق محاولة تسليم دعوة واحدة لمدعو واحد، مع تحقّق
     * سقف الرصيد بشكل صحيح تحت تزامن حقيقي. هذه هي نقطة الدخول الوحيدة
     * المطلوبة من Mon_Cartat_Handler::cron_process_queue() قبل أي استدعاء
     * لـCartat API — لا create_reservation() القديمة (تلك بلا قفل ولا
     * تحقّق سقف، مصمَّمة فقط لضمان عدم تكرار *نفس* المدعو، لا لمنع تجاوز
     * الرصيد الإجمالي).
     *
     * القفل يُغلّف كل منطق القراءة+القرار+الكتابة معاً داخل try/finally —
     * RELEASE_LOCK يُنفَّذ دائماً بصرف النظر عن نقطة الخروج (نجاح أو أي نوع
     * فشل)، لضمان عدم بقاء القفل معلَّقاً لبقية عمر اتصال قاعدة البيانات.
     *
     * عقود الإرجاع الخمسة (بالضبط كما وردت في المهمة):
     *  - ['result'=>'claimed','id'=>int,'attempt_token'=>string]: صف
     *    reserved جديد أو مُعاد استخدامه (من failed)، مع توكن جديد — التزم
     *    بإرسال الرسالة الآن ثم استدعاء mark_consumed_with_token()/
     *    mark_failed_with_token() بهذا التوكن بالضبط.
     *  - ['result'=>'already_consumed','id'=>int]: هذا المدعو استهلك رصيده
     *    فعلاً ضمن هذه الدورة — لا ترسل مجدداً.
     *  - ['result'=>'in_progress','id'=>int]: صف reserved بتوكن نشط **ولم
     *    تنتهِ مهلته بعد** (ضمن ATTEMPT_LEASE_SECONDS من attempt_started_at
     *    — راجع is_lease_expired()) — عامل آخر يملكه الآن فعلياً، أو لم
     *    يُنهَ بعد. لا ترسل، لا تعتبرها فشلاً. توكن نشط لكن *منتهي المهلة*
     *    (مثل صف تُرِك عمداً reserved بعد transport_error غامض) **لا** يُعيد
     *    in_progress — يُعامَل كصف غير مملوك ويُعاد المطالبة به فوراً
     *    (claimed بتوكن جديد)، منعاً لتجميد المدعو والرصيد إلى الأبد.
     *  - ['result'=>'limit_exceeded']: لا صف لهذا المدعو، والرصيد
     *    (reserved+consumed) بلغ السقف بالفعل — لا ترسل.
     *  - ['result'=>'error','reason'=>string]: تحقّق مدخلات فشل، أو تعذّر
     *    الحصول على القفل، أو خطأ SQL فعلي — لا ترسل، لا تفترض أي حالة.
     */
    public static function claim_for_delivery($user_id, $credit_cycle_id, $event_id, $guest_phone, $credit_type, $credit_limit)
    {
        $normalized_user_id = self::normalize_positive_id($user_id);
        if ($normalized_user_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_user_id'];
        }

        $normalized_event_id = self::normalize_positive_id($event_id);
        if ($normalized_event_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_event_id'];
        }

        $credit_cycle_id = is_string($credit_cycle_id) ? trim($credit_cycle_id) : '';
        if ($credit_cycle_id === '') {
            return ['result' => 'error', 'reason' => 'invalid_credit_cycle_id'];
        }

        $normalized_phone = self::normalize_guest_phone($guest_phone);
        if ($normalized_phone === '') {
            return ['result' => 'error', 'reason' => 'invalid_guest_phone'];
        }

        $normalized_type = self::normalize_credit_type($credit_type);
        if ($normalized_type === false) {
            return ['result' => 'error', 'reason' => 'invalid_credit_type'];
        }

        if (is_int($credit_limit) && $credit_limit >= 0) {
            $normalized_limit = $credit_limit;
        } elseif (is_string($credit_limit) && preg_match('/^[0-9]+$/', trim($credit_limit))) {
            $normalized_limit = (int) trim($credit_limit);
        } else {
            return ['result' => 'error', 'reason' => 'invalid_credit_limit'];
        }

        global $wpdb;
        $table = self::table_name();
        $lock_name = self::build_credit_lock_name($normalized_user_id, $credit_cycle_id, $normalized_type);

        // GET_LOCK(name, timeout_seconds) — 5 ثوانٍ انتظار كافية لعملية سريعة
        // (قراءة صف + INSERT/UPDATE واحد)؛ 1 = نجح الحصول على القفل، 0 =
        // مشغول من جلسة أخرى حتى انتهاء المهلة، NULL = خطأ فعلي.
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ((int) $got_lock !== 1) {
            return ['result' => 'error', 'reason' => 'lock_not_acquired'];
        }

        try {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE credit_cycle_id = %s AND event_id = %d AND guest_phone = %s AND credit_type = %s LIMIT 1",
                    $credit_cycle_id,
                    $normalized_event_id,
                    $normalized_phone,
                    $normalized_type
                ),
                ARRAY_A
            );

            $now = current_time('mysql', true);

            if ($existing !== null) {
                $existing_id = (int) $existing['id'];
                $existing_status = (string) ($existing['status'] ?? '');

                if ($existing_status === 'consumed') {
                    return ['result' => 'already_consumed', 'id' => $existing_id];
                }

                if ($existing_status === 'refunded') {
                    // القرار الصريح لهذه المرحلة: لا يُعاد استخدام صف refunded
                    // كدعوة primary/replacement جديدة — يحتاج ذلك تعريف
                    // "المدعو البديل" الكامل، خارج نطاق هذه المرحلة.
                    return ['result' => 'error', 'reason' => 'refunded_reuse_not_allowed'];
                }

                if ($existing_status === 'reserved') {
                    $has_active_token = !empty($existing['attempt_token']);

                    // إصلاح Blocker (راجع توثيق ATTEMPT_LEASE_SECONDS أعلاه):
                    // توكن نشط لا يعني بالضرورة "لا يزال مملوكاً" — إن تجاوز
                    // عمره مهلة الـLease (غالباً transport_error سابق تُرِك
                    // عمداً reserved، راجع القسم الخاص بذلك في
                    // Mon_Cartat_Handler::cron_process_queue()) يُعامَل تماماً
                    // كصف "غير مملوك" ويُسمح بمطالبة جديدة عليه — بلا إنشاء
                    // صف ثانٍ إطلاقاً، فقط تحديث نفس الصف بتوكن جديد.
                    if ($has_active_token && !self::is_lease_expired($existing['attempt_started_at'] ?? null)) {
                        return ['result' => 'in_progress', 'id' => $existing_id];
                    }

                    // إما بلا توكن نشط أصلاً (صف من مسار create_reservation()
                    // القديم لم يُستهلَك بعد)، أو Lease التوكن الحالي منتهية —
                    // كلتا الحالتين تُعامَلان بنفس المنطق: مطالبة جديدة مشروعة
                    // على نفس الصف، بتوكن جديد يُبطل تلقائياً أي توكن قديم (لن
                    // يتطابق بعد الآن مع mark_consumed_with_token()/
                    // mark_failed_with_token() لعامل قديم يحمل التوكن السابق).
                    $token = self::generate_attempt_token();
                    $updated = $wpdb->update(
                        $table,
                        ['attempt_token' => $token, 'attempt_started_at' => $now, 'last_attempt_at' => $now],
                        ['id' => $existing_id, 'status' => 'reserved'],
                        ['%s', '%s', '%s'],
                        ['%d', '%s']
                    );
                    if ($updated === false || $updated === 0) {
                        return ['result' => 'error', 'reason' => 'claim_update_failed'];
                    }
                    return ['result' => 'claimed', 'id' => $existing_id, 'attempt_token' => $token];
                }

                if ($existing_status === 'failed') {
                    $token = self::generate_attempt_token();
                    $updated = $wpdb->update(
                        $table,
                        [
                            'status'             => 'reserved',
                            'attempt_token'      => $token,
                            'attempt_started_at' => $now,
                            'last_attempt_at'    => $now,
                        ],
                        ['id' => $existing_id, 'status' => 'failed'],
                        ['%s', '%s', '%s', '%s'],
                        ['%d', '%s']
                    );
                    if ($updated === false || $updated === 0) {
                        return ['result' => 'error', 'reason' => 'claim_update_failed'];
                    }
                    return ['result' => 'claimed', 'id' => $existing_id, 'attempt_token' => $token];
                }

                return ['result' => 'error', 'reason' => 'unknown_status'];
            }

            // لا صف موجود لهذا المدعو بعد — تحقّق السقف قبل الحجز، تحت حماية
            // نفس القفل (لا يمكن لعامل آخر يشارك نفس مصدر الرصيد أن يقرأ أو
            // يكتب بينما هذا العامل يحمل القفل).
            $current_count = self::count_reserved_or_consumed_unsafe($normalized_user_id, $credit_cycle_id, $normalized_type);
            if ($current_count >= $normalized_limit) {
                return ['result' => 'limit_exceeded'];
            }

            $token = self::generate_attempt_token();
            $inserted = $wpdb->insert(
                $table,
                [
                    'user_id'            => $normalized_user_id,
                    'credit_cycle_id'    => $credit_cycle_id,
                    'event_id'           => $normalized_event_id,
                    'guest_phone'        => $normalized_phone,
                    'credit_type'        => $normalized_type,
                    'status'             => 'reserved',
                    'attempt_token'      => $token,
                    'attempt_started_at' => $now,
                    'last_attempt_at'    => $now,
                    'created_at'         => $now,
                ],
                ['%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            if (!$inserted) {
                return ['result' => 'error', 'reason' => 'insert_failed'];
            }

            return ['result' => 'claimed', 'id' => (int) $wpdb->insert_id, 'attempt_token' => $token];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * إنهاء محاولة تسليم بنجاح: reserved → consumed، مشروط بتطابق
     * attempt_token بالضبط (لا id وحده). أي عدم تطابق — توكن خاطئ، توكن
     * قديم استُبدل بمطالبة أحدث، أو حالة ليست reserved أصلاً — يُعيد false
     * دون أي تعديل. يمسح attempt_token عند النجاح (القرار الموثَّق: مسح لا
     * إبقاء، لأن المحاولة انتهت نهائياً ولا فائدة من الاحتفاظ بتوكن منتهي
     * الصلاحية). لا تُستخدَم mark_consumed($id) القديمة في هذا المسار.
     */
    public static function mark_consumed_with_token($id, $attempt_token)
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return false;
        }

        $attempt_token = is_string($attempt_token) ? trim($attempt_token) : '';
        if ($attempt_token === '') {
            return false;
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::table_name(),
            [
                'status'        => 'consumed',
                'consumed_at'   => current_time('mysql', true),
                'attempt_token' => null,
            ],
            [
                'id'            => $normalized_id,
                'status'        => 'reserved',
                'attempt_token' => $attempt_token,
            ],
            ['%s', '%s', '%s'],
            ['%d', '%s', '%s']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * إنهاء محاولة تسليم بفشل صريح (رفض Cartat الرسالة): reserved → failed،
     * بنفس شرط تطابق attempt_token تماماً كـmark_consumed_with_token(). لا
     * تُغيّر consumed ولا refunded إطلاقاً (الشرط WHERE status='reserved'
     * يمنع ذلك بنيوياً). يمسح attempt_token عند النجاح لنفس السبب أعلاه —
     * الصف failed يبقى قابلاً لمطالبة جديدة لاحقاً عبر claim_for_delivery()
     * (تُنشئ توكناً جديداً عند إعادة المحاولة).
     */
    public static function mark_failed_with_token($id, $attempt_token)
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return false;
        }

        $attempt_token = is_string($attempt_token) ? trim($attempt_token) : '';
        if ($attempt_token === '') {
            return false;
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::table_name(),
            [
                'status'        => 'failed',
                'attempt_token' => null,
            ],
            [
                'id'            => $normalized_id,
                'status'        => 'reserved',
                'attempt_token' => $attempt_token,
            ],
            ['%s', '%s'],
            ['%d', '%s', '%s']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * عدّ الصفوف status=consumed لمستخدم ودورة رصيد ونوع رصيد محددين.
     * تطبيع كامل للمعاملات؛ فشل أي منها يُعيد 0 دون استعلام (لا Fatal، لا
     * استثناء — نفس فلسفة القيم الآمنة الافتراضية المعتمدة في المشروع).
     */
    public static function count_consumed($user_id, $credit_cycle_id, $credit_type)
    {
        return self::count_by_status($user_id, $credit_cycle_id, $credit_type, 'consumed');
    }

    /**
     * عدّ الصفوف status=reserved، بنفس قواعد count_consumed() تماماً.
     */
    public static function count_reserved($user_id, $credit_cycle_id, $credit_type)
    {
        return self::count_by_status($user_id, $credit_cycle_id, $credit_type, 'reserved');
    }

    /**
     * ============================================================================
     * عدّ الصفوف status=reserved "المملوكة فعلياً حالياً" فقط (المرحلة 4C).
     * ============================================================================
     * إضافة جديدة ضيقة النطاق ومتوافقة خلفياً بالكامل — لا تُعدِّل
     * count_reserved() أعلاه ولا سلوكها الحالي بأي شكل (تُستخدَم count_reserved()
     * حصرياً من مسارات التقارير/التدقيق القديمة كما هي).
     *
     * الفرق الوحيد عن count_reserved(): تستبعد أي صف reserved انتهت مهلة
     * Lease محاولته (is_lease_expired()) أو لا يملك attempt_token نشطاً أصلاً
     * — أي صف "متروك" فعلياً وقابل لإعادة المطالبة به عبر claim_for_delivery()
     * القادمة (تماماً بنفس المعيار الذي يستخدمه claim_for_delivery() داخلياً
     * لتقرير in_progress مقابل "غير مملوك"، راجع التوثيق هناك)، فلا يُحتسَب
     * ضمن "الاستهلاك الفعلي الجاري" لأي حساب سقف يعتمد على هذه الدالة.
     *
     * غرضها الوحيد حالياً: حساب Replacement Credits الذري (المرحلة 4C) في
     * Mon_Cartat_Handler، حيث الفرق بين معدود Ledger القديم (count_reserved
     * البسيطة، تشمل صفوفاً متروكة قد تُحجب سعة فعلية بلا داعٍ) ومعدود دقيق
     * "نشط حقيقة الآن" مهم لتفادي رفض حجوزات مشروعة بسبب صف Lease منتهٍ لم
     * يُستبدَل بعد. القراءة نفسها لا تُعدِّل أي صف ولا تُبطل أي Lease — هي
     * قراءة بحتة فقط؛ الإبطال الفعلي يبقى حصراً من اختصاص claim_for_delivery().
     *
     * تُنفَّذ عبر مسح صفوف reserved المرشَّحة كاملة (نطاق ضيق أصلاً: مستخدم +
     * دورة + نوع رصيد واحد، غالباً صفر أو صف واحد على الأكثر عملياً) ثم
     * استدعاء is_lease_expired() الخاصة لكل صف — نفس الكلاس، فلا حاجة لتكرار
     * منطقها أو جعلها عامة.
     */
    public static function count_active_reserved($user_id, $credit_cycle_id, $credit_type): int
    {
        $normalized_user_id = self::normalize_positive_id($user_id);
        if ($normalized_user_id === 0) {
            return 0;
        }

        $credit_cycle_id = is_string($credit_cycle_id) ? trim($credit_cycle_id) : '';
        if ($credit_cycle_id === '') {
            return 0;
        }

        $normalized_type = self::normalize_credit_type($credit_type);
        if ($normalized_type === false) {
            return 0;
        }

        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT attempt_token, attempt_started_at FROM " . self::table_name() . " WHERE user_id = %d AND credit_cycle_id = %s AND credit_type = %s AND status = %s",
            $normalized_user_id,
            $credit_cycle_id,
            $normalized_type,
            'reserved'
        ), ARRAY_A);

        if (!is_array($rows)) {
            return 0;
        }

        $active_count = 0;
        foreach ($rows as $row) {
            $has_active_token = !empty($row['attempt_token']);
            if ($has_active_token && !self::is_lease_expired($row['attempt_started_at'] ?? null)) {
                $active_count++;
            }
        }

        return $active_count;
    }

    /**
     * المنطق المشترك الفعلي بين count_consumed()/count_reserved() — status
     * ثابتة مضمونة الصحة دوماً (تُمرَّر داخلياً فقط من الدالتين أعلاه، لا من
     * أي مستدعٍ خارجي)، فلا حاجة لتطبيعها هنا.
     */
    private static function count_by_status($user_id, $credit_cycle_id, $credit_type, $status)
    {
        $normalized_user_id = self::normalize_positive_id($user_id);
        if ($normalized_user_id === 0) {
            return 0;
        }

        $credit_cycle_id = is_string($credit_cycle_id) ? trim($credit_cycle_id) : '';
        if ($credit_cycle_id === '') {
            return 0;
        }

        $normalized_type = self::normalize_credit_type($credit_type);
        if ($normalized_type === false) {
            return 0;
        }

        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_name() . " WHERE user_id = %d AND credit_cycle_id = %s AND credit_type = %s AND status = %s",
            $normalized_user_id,
            $credit_cycle_id,
            $normalized_type,
            $status
        ));

        return (int) $count;
    }
}
