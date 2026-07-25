<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Replacement Entitlements — سجل استحقاقات Replacement Credits
 * ============================================================================
 * Invitation Credits Engine، المرحلة 4A: "تأسيس بنية الاستحقاقات فقط". هذه
 * الطبقة (Repository) هي الوسيط الوحيد المسموح للتعامل مع جدول
 * {$wpdb->prefix}mon_replacement_entitlements (راجع class-mon-catalog-schema.php
 * لتعريف الجدول والقيد UNIQUE).
 *
 * لا تُستدعى دوالها حالياً من أي مسار RSVP أو Queue أو Cartat — هذه المرحلة
 * بنية بيانات وخدمات داخلية فقط، بلا أي منح تلقائي عند اعتذار ضيف، وبلا أي
 * إرسال replacement فعلي. لا تلمس أي user meta (_mon_replacement_credit_*)
 * إطلاقاً؛ هذا السجل مستقل تماماً عن تلك العدادات في هذه المرحلة.
 *
 * القرار المعماري النهائي (راجع مقارنة Design 1 مقابل Design 2 في تقرير
 * التدقيق السابق): جدول مستقل تماماً عن mon_invitation_credit_ledger، لا
 * عمود إضافي (replacement_granted) داخل جدول الـLedger. هذا يفصل بوضوح بين
 * مفهومين مختلفين جوهرياً — "محاولة تسليم" (Ledger) و"استحقاق مُكتسَب" (هذا
 * الجدول) — بدل خلطهما في جدول/كلاس واحد.
 *
 * هذا الملف **يقرأ فقط** من mon_invitation_credit_ledger (عبر
 * PGE_Invitation_Credit_Ledger::table_name() العامة + استعلام SELECT مباشر
 * في create_entitlement()/mark_consumed() للتحقق من صف primary/replacement)
 * — لا يستدعي أي دالة كتابة فيها، ولا يعدّل class-pge-invitation-credit-
 * ledger.php بأي شكل.
 *
 * الحماية الحقيقية ضد منح استحقاق مزدوج لنفس المعتذر هي القيد UNIQUE
 * unique_replacement_entitlement (credit_cycle_id, event_id,
 * source_guest_phone) على مستوى قاعدة البيانات نفسها — لا أي فحص تطبيقي
 * "افحص ثم أدرج" بمفرده؛ create_entitlement() أدناه تعتمد أولاً على محاولة
 * INSERT فعلية وتُفسِّر فشلها بسبب تعارض المفتاح كنتيجة "already_exists"
 * آمنة، بنفس نمط PGE_Invitation_Credit_Ledger::create_reservation() تماماً.
 */

class PGE_Replacement_Entitlements
{
    /**
     * القيم الرسمية المسموحة لـstatus. الانتقالات المسموحة بينها (تُفرَض في
     * mark_consumed()/mark_voided() لا هنا): granted → consumed (عبر
     * mark_consumed() فقط، بشرط صف replacement/consumed صالح)، وgranted →
     * voided (عبر mark_voided() فقط — أداة آلية معدّة للاستخدام لاحقاً، بلا
     * أي منطق عمل يقرر متى تُستدعى في هذه المرحلة). كلتا consumed وvoided
     * حالتان نهائيتان — لا مسار عكسي ولا تجاوز مرحلة.
     */
    private const ALLOWED_STATUSES = [
        'granted',
        'consumed',
        'voided',
    ];

    /**
     * اسم جدول الاستحقاقات مع بادئة $wpdb->prefix.
     */
    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'mon_replacement_entitlements';
    }

    /**
     * تطبيع معرّف موجب (user_id/event_id/id الصف/source_ledger_id/
     * consumed_by_ledger_id). نفس قاعدة normalize_positive_id() الخاصة في
     * PGE_Invitation_Credit_Ledger بالضبط — مُكرَّرة هنا عمداً بدل استدعاء
     * دالة خاصة (private) في كلاس آخر؛ نفس نمط التكرار الصغير المقبول أصلاً
     * في المشروع (راجع pge_event_guests_norm_phone مقابل pge_norm_phone).
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
     * تطبيع رقم جوال المعتذر — نفس دالة تطبيع الجوال الحالية بالضبط
     * (pge_norm_phone() في helpers.php إن كانت محمَّلة، أو نفس منطقها
     * كاحتياط)، بنفس نمط PGE_Invitation_Credit_Ledger::normalize_guest_phone()
     * تماماً (مُكرَّرة هنا لنفس السبب أعلاه — دالة خاصة في كلاس آخر).
     */
    private static function normalize_guest_phone($value)
    {
        if (function_exists('pge_norm_phone')) {
            return pge_norm_phone($value);
        }

        return preg_replace('/\D+/', '', trim((string) $value));
    }

    /**
     * تطبيع والتحقق من قيمة status مقابل self::ALLOWED_STATUSES. لا تُستدعى
     * من أي مسار خارجي حالياً (لا دالة عامة تقبل status كمعامل مباشر) —
     * موجودة للاستخدام الدفاعي الداخلي ولتوثيق القيم الرسمية صراحة.
     */
    private static function normalize_status($value)
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
     * قراءة صف واحد من mon_invitation_credit_ledger بمعرّفه — قراءة مباشرة
     * (لا عبر أي دالة في PGE_Invitation_Credit_Ledger، فهي لا تملك دالة
     * "get by id" عامة، ولا يجوز إضافة واحدة لها في هذه المرحلة). تُستخدَم
     * من create_entitlement() (التحقق من صف primary) وmark_consumed()
     * (التحقق من صف replacement).
     */
    private static function get_ledger_row_by_id(int $ledger_id): ?array
    {
        global $wpdb;
        $ledger_table = class_exists('PGE_Invitation_Credit_Ledger')
            ? PGE_Invitation_Credit_Ledger::table_name()
            : $wpdb->prefix . 'mon_invitation_credit_ledger';

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $ledger_table WHERE id = %d LIMIT 1", $ledger_id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * قراءة صف استحقاق بمعرّفه (id). تُعيد null عند id غير صالح أو صف غير
     * موجود — نفس نمط PGE_Invitation_Credit_Ledger::find_entry() (بلا خطأ
     * مركّب، فهي قارئة بسيطة لا معالج أوامر).
     */
    public static function get_entitlement($id)
    {
        $normalized_id = self::normalize_positive_id($id);
        if ($normalized_id === 0) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE id = %d LIMIT 1", $normalized_id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * قراءة صف استحقاق بمفتاحه الفريد الثلاثي الكامل (نفس أعمدة القيد
     * UNIQUE unique_replacement_entitlement بالضبط: credit_cycle_id،
     * event_id، source_guest_phone). تُطبِّع كل معامل بنفس قواعد
     * create_entitlement() أدناه؛ فشل أي تطبيع يُعيد null فوراً دون استعلام.
     */
    public static function get_entitlement_by_source($credit_cycle_id, $event_id, $source_guest_phone)
    {
        $normalized_event_id = self::normalize_positive_id($event_id);
        if ($normalized_event_id === 0) {
            return null;
        }

        $credit_cycle_id = is_string($credit_cycle_id) ? trim($credit_cycle_id) : '';
        if ($credit_cycle_id === '') {
            return null;
        }

        $normalized_phone = self::normalize_guest_phone($source_guest_phone);
        if ($normalized_phone === '') {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_name() . " WHERE credit_cycle_id = %s AND event_id = %d AND source_guest_phone = %s LIMIT 1",
                $credit_cycle_id,
                $normalized_event_id,
                $normalized_phone
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * ============================================================================
     * إنشاء استحقاق Replacement Credit واحد لضيف اعتذر بعد إرسال primary ناجح.
     * ============================================================================
     * تطبيع كامل لكل معامل أولاً (user_id/event_id/source_ledger_id موجبة،
     * credit_cycle_id غير فارغ، source_guest_phone غير فارغ بعد التطبيع) —
     * أي فشل يُعيد ['result' => 'error', 'reason' => ...] فوراً دون أي
     * استعلام.
     *
     * ثم تُقرأ صف source من mon_invitation_credit_ledger (عبر source_ledger_id)
     * وتُشترَط جميع الشروط التالية معاً قبل أي محاولة إنشاء:
     *  - الصف موجود فعلاً (source_ledger_not_found).
     *  - credit_type = 'primary' (source_not_primary) — يرفض استخدام صف
     *    replacement كمصدر لاستحقاق آخر.
     *  - status = 'consumed' (source_not_consumed) — يرفض reserved/failed/
     *    refunded على حد سواء؛ لم يُستهلك أي primary credit فعلياً بعده، فلا
     *    معنى لاستحقاق "استرجاع" شيء لم يُصرَف بعد.
     *  - user_id مطابق تماماً (source_user_mismatch).
     *  - event_id مطابق تماماً (source_event_mismatch).
     *  - guest_phone مطابق بعد التطبيع (source_phone_mismatch).
     *  - credit_cycle_id مطابق تماماً (source_cycle_mismatch) — الاستحقاق
     *    يُنسَب دائماً لنفس الدورة التي حملها صف primary الأصلي، لا الدورة
     *    الحالية للمستخدم.
     *
     * الحماية الذرية ضد التكرار (منح مزدوج لنفس المعتذر) تعتمد حصراً على
     * القيد UNIQUE unique_replacement_entitlement عبر محاولة $wpdb->insert()
     * مباشرة — بنفس فلسفة PGE_Invitation_Credit_Ledger::create_reservation()
     * تماماً؛ لا فحص "SELECT ثم INSERT" منفصل بوصفه حماية وحيدة.
     *
     * القيم المُعادة (result) الثلاث المميَّزة صراحةً:
     *  - 'created': استحقاق جديد أُنشئ فعلاً — يتضمن 'id' للصف الجديد.
     *  - 'already_exists': الاستحقاق موجود مسبقاً لنفس المفتاح الثلاثي (لم
     *    يُنشأ صف ثانٍ) — يتضمن 'id' للصف الموجود، بصرف النظر عن حالته
     *    الحالية (granted/consumed/voided).
     *  - 'error': فشل تحقق أو فشل SQL غير متعلق بالتكرار — يتضمن 'reason'.
     */
    public static function create_entitlement($user_id, $credit_cycle_id, $event_id, $source_guest_phone, $source_ledger_id)
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

        $normalized_phone = self::normalize_guest_phone($source_guest_phone);
        if ($normalized_phone === '') {
            return ['result' => 'error', 'reason' => 'invalid_source_guest_phone'];
        }

        $normalized_source_ledger_id = self::normalize_positive_id($source_ledger_id);
        if ($normalized_source_ledger_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_source_ledger_id'];
        }

        $source_row = self::get_ledger_row_by_id($normalized_source_ledger_id);
        if ($source_row === null) {
            return ['result' => 'error', 'reason' => 'source_ledger_not_found'];
        }

        if ((string) ($source_row['credit_type'] ?? '') !== 'primary') {
            return ['result' => 'error', 'reason' => 'source_not_primary'];
        }

        if ((string) ($source_row['status'] ?? '') !== 'consumed') {
            return ['result' => 'error', 'reason' => 'source_not_consumed'];
        }

        if ((int) ($source_row['user_id'] ?? 0) !== $normalized_user_id) {
            return ['result' => 'error', 'reason' => 'source_user_mismatch'];
        }

        if ((int) ($source_row['event_id'] ?? 0) !== $normalized_event_id) {
            return ['result' => 'error', 'reason' => 'source_event_mismatch'];
        }

        if (self::normalize_guest_phone($source_row['guest_phone'] ?? '') !== $normalized_phone) {
            return ['result' => 'error', 'reason' => 'source_phone_mismatch'];
        }

        if (trim((string) ($source_row['credit_cycle_id'] ?? '')) !== $credit_cycle_id) {
            return ['result' => 'error', 'reason' => 'source_cycle_mismatch'];
        }

        global $wpdb;
        $now = current_time('mysql', true);

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'user_id'            => $normalized_user_id,
                'credit_cycle_id'    => $credit_cycle_id,
                'event_id'           => $normalized_event_id,
                'source_guest_phone' => $normalized_phone,
                'source_ledger_id'   => $normalized_source_ledger_id,
                'status'             => 'granted',
                'granted_at'         => $now,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            ['%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted) {
            return ['result' => 'created', 'id' => (int) $wpdb->insert_id];
        }

        $last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
        $looks_like_duplicate_key = $last_error !== '' && stripos($last_error, 'duplicate') !== false;

        $existing = self::get_entitlement_by_source($credit_cycle_id, $normalized_event_id, $normalized_phone);
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
     * عدد الاستحقاقات التي مُنحت فعلياً على الإطلاق لنفس (user_id,
     * credit_cycle_id) — بصرف النظر عن حالتها الحالية (granted/consumed/
     * voided). مقياس تاريخي/تدقيقي: "كم استحقاقاً مُنح إجمالاً هذه الدورة".
     */
    public static function count_granted($user_id, $credit_cycle_id)
    {
        $normalized_user_id = self::normalize_positive_id($user_id);
        if ($normalized_user_id === 0) {
            return 0;
        }

        $credit_cycle_id = is_string($credit_cycle_id) ? trim($credit_cycle_id) : '';
        if ($credit_cycle_id === '') {
            return 0;
        }

        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_name() . " WHERE user_id = %d AND credit_cycle_id = %s",
            $normalized_user_id,
            $credit_cycle_id
        ));

        return (int) $count;
    }

    /**
     * عدد الاستحقاقات المُستهلَكة فعلياً (status='consumed') لنفس (user_id,
     * credit_cycle_id).
     */
    public static function count_consumed($user_id, $credit_cycle_id)
    {
        return self::count_by_status($user_id, $credit_cycle_id, 'consumed');
    }

    /**
     * عدد الاستحقاقات المتاحة حالياً للصرف (status='granted' فقط — لم
     * تُستهلَك ولم تُبطَل) لنفس (user_id, credit_cycle_id). هذا هو "الرصيد
     * الحي" القابل للاستخدام الآن، بخلاف count_granted() (إجمالي تاريخي).
     */
    public static function count_available($user_id, $credit_cycle_id)
    {
        return self::count_by_status($user_id, $credit_cycle_id, 'granted');
    }

    /**
     * دالة داخلية مشتركة لـcount_consumed()/count_available() — نفس نمط
     * PGE_Invitation_Credit_Ledger::count_by_status() الخاصة.
     */
    private static function count_by_status($user_id, $credit_cycle_id, string $status)
    {
        $normalized_user_id = self::normalize_positive_id($user_id);
        if ($normalized_user_id === 0) {
            return 0;
        }

        $credit_cycle_id = is_string($credit_cycle_id) ? trim($credit_cycle_id) : '';
        if ($credit_cycle_id === '') {
            return 0;
        }

        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_name() . " WHERE user_id = %d AND credit_cycle_id = %s AND status = %s",
            $normalized_user_id,
            $credit_cycle_id,
            $status
        ));

        return (int) $count;
    }

    /**
     * ============================================================================
     * استهلاك استحقاق: ربطه بصف replacement/consumed حقيقي في الـLedger.
     * ============================================================================
     * يعمل فقط من status='granted' إلى 'consumed'. يتطلب consumed_by_ledger_id
     * صالحاً (معرّف موجب)، ويشترط أن صف Ledger المشار إليه:
     *  - موجود فعلاً (consumed_by_ledger_not_found).
     *  - credit_type = 'replacement' (consumed_by_not_replacement) — يرفض
     *    ربط استحقاق بصف primary.
     *  - status = 'consumed' (consumed_by_not_consumed) — يرفض reserved/
     *    failed/refunded؛ الاستحقاق يُستهلَك فقط بإرسال replacement ناجح
     *    فعلياً، لا بمجرد محاولة.
     *  - user_id مطابق لصاحب الاستحقاق (consumed_by_user_mismatch).
     *  - credit_cycle_id مطابق لدورة الاستحقاق (consumed_by_cycle_mismatch).
     * لا يُشترَط تطابق event_id أو guest_phone بين الاستحقاق وصف الاستهلاك
     * عمداً — الإرسال البديل قد يذهب لأي ضيف/مناسبة أخرى ضمن نفس المستخدم
     * والدورة؛ فقط "من نفس الرصيد" هو الشرط.
     *
     * Idempotent: استدعاء متكرر بنفس consumed_by_ledger_id على استحقاق مُستهلَك
     * فعلاً يُعيد 'already_consumed' بنجاح دون أي كتابة إضافية. محاولة ربط
     * استحقاق مُستهلَك فعلاً بصف replacement **مختلف** تُرفَض صراحة
     * (consumed_by_mismatch) — يمنع إعادة توجيه/ازدواج صرف نفس الاستحقاق.
     *
     * التحديث الفعلي مشروط ذرياً بـ status='granted' في WHERE (بنفس فلسفة
     * mark_consumed_with_token() في الـLedger) كحماية إضافية ضد سباق نادر
     * بين القراءة الأولى وهذا التحديث.
     */
    public static function mark_consumed($entitlement_id, $consumed_by_ledger_id)
    {
        $normalized_id = self::normalize_positive_id($entitlement_id);
        if ($normalized_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_entitlement_id'];
        }

        $normalized_ledger_id = self::normalize_positive_id($consumed_by_ledger_id);
        if ($normalized_ledger_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_consumed_by_ledger_id'];
        }

        $entitlement = self::get_entitlement($normalized_id);
        if ($entitlement === null) {
            return ['result' => 'error', 'reason' => 'entitlement_not_found'];
        }

        $current_status = (string) ($entitlement['status'] ?? '');

        if ($current_status === 'consumed') {
            $existing_ledger_id = (int) ($entitlement['consumed_by_ledger_id'] ?? 0);
            if ($existing_ledger_id === $normalized_ledger_id) {
                return ['result' => 'already_consumed', 'id' => $normalized_id];
            }
            return ['result' => 'error', 'reason' => 'consumed_by_mismatch'];
        }

        if ($current_status === 'voided') {
            return ['result' => 'error', 'reason' => 'entitlement_voided'];
        }

        if ($current_status !== 'granted') {
            return ['result' => 'error', 'reason' => 'invalid_entitlement_state'];
        }

        $consuming_row = self::get_ledger_row_by_id($normalized_ledger_id);
        if ($consuming_row === null) {
            return ['result' => 'error', 'reason' => 'consumed_by_ledger_not_found'];
        }

        if ((string) ($consuming_row['credit_type'] ?? '') !== 'replacement') {
            return ['result' => 'error', 'reason' => 'consumed_by_not_replacement'];
        }

        if ((string) ($consuming_row['status'] ?? '') !== 'consumed') {
            return ['result' => 'error', 'reason' => 'consumed_by_not_consumed'];
        }

        if ((int) ($consuming_row['user_id'] ?? 0) !== (int) $entitlement['user_id']) {
            return ['result' => 'error', 'reason' => 'consumed_by_user_mismatch'];
        }

        if (trim((string) ($consuming_row['credit_cycle_id'] ?? '')) !== trim((string) $entitlement['credit_cycle_id'])) {
            return ['result' => 'error', 'reason' => 'consumed_by_cycle_mismatch'];
        }

        global $wpdb;
        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            self::table_name(),
            [
                'status'                => 'consumed',
                'consumed_by_ledger_id' => $normalized_ledger_id,
                'consumed_at'           => $now,
                'updated_at'            => $now,
            ],
            ['id' => $normalized_id, 'status' => 'granted'],
            ['%s', '%d', '%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false || $updated === 0) {
            return ['result' => 'error', 'reason' => 'concurrent_state_change'];
        }

        return ['result' => 'consumed', 'id' => $normalized_id];
    }

    /**
     * ============================================================================
     * إبطال استحقاق (أداة آلية فقط — بلا منطق عمل يقرر متى تُستدعى).
     * ============================================================================
     * يعمل فقط من status='granted' إلى 'voided'. لا يمكن إبطال استحقاق
     * مُستهلَك فعلاً (status='consumed') — يُرفَض صراحة. Idempotent: استدعاء
     * متكرر على استحقاق مُبطَل أصلاً يُعيد 'already_voided' بنجاح دون أي
     * كتابة إضافية. لا voided_at (لا عمود مخصص لها في هذه المرحلة، بحسب
     * Schema المطلوب حرفياً) — updated_at يحمل وقت الإبطال.
     *
     * تنبيه معماري (لا يُطبَّق هنا): هذه المرحلة لا تحدد **متى** يجب إبطال
     * استحقاق (مثلاً: عند حذف المعتذر، أو عند تراجعه عن الاعتذار) — هذا قرار
     * عمل منفصل يحتاج تفعيلاً صريحاً في مرحلة لاحقة.
     */
    public static function mark_voided($entitlement_id)
    {
        $normalized_id = self::normalize_positive_id($entitlement_id);
        if ($normalized_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_entitlement_id'];
        }

        $entitlement = self::get_entitlement($normalized_id);
        if ($entitlement === null) {
            return ['result' => 'error', 'reason' => 'entitlement_not_found'];
        }

        $current_status = (string) ($entitlement['status'] ?? '');

        if ($current_status === 'voided') {
            return ['result' => 'already_voided', 'id' => $normalized_id];
        }

        if ($current_status === 'consumed') {
            return ['result' => 'error', 'reason' => 'cannot_void_consumed'];
        }

        if ($current_status !== 'granted') {
            return ['result' => 'error', 'reason' => 'invalid_entitlement_state'];
        }

        global $wpdb;
        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            self::table_name(),
            [
                'status'     => 'voided',
                'updated_at' => $now,
            ],
            ['id' => $normalized_id, 'status' => 'granted'],
            ['%s', '%s'],
            ['%d', '%s']
        );

        if ($updated === false || $updated === 0) {
            return ['result' => 'error', 'reason' => 'concurrent_state_change'];
        }

        return ['result' => 'voided', 'id' => $normalized_id];
    }

    /**
     * اسم قفل GET_LOCK لحماية عمليتَي "الحجز الذري لمرسلة replacement" (في
     * Mon_Cartat_Handler، قبل claim_for_delivery) و"اختيار واستهلاك أقدم
     * استحقاق" (claim_oldest_granted_for_ledger() أدناه) — المرحلة 4C. نطاق
     * القفل: مستخدم + دورة رصيد فقط (بلا credit_type — هذا الرصيد Replacement
     * حصراً بطبيعته). اسم مستقل تماماً عن
     * PGE_Invitation_Credit_Ledger::build_credit_lock_name() الخاصة (بادئة
     * مختلفة كلياً: pge_replacement_credit_ مقابل pge_credit_) — لا تعارض
     * تسمية ولا احتمال Deadlock ناتج عن تشابه أسماء بين القفلين.
     *
     * عامة (public) عمداً: مصدر تسمية واحد يستدعيه كل من Mon_Cartat_Handler
     * (مرحلة الحجز) وclaim_oldest_granted_for_ledger() هنا (مرحلة الاستهلاك)
     * — يضمن استخدام الاسم نفسه بالضبط دون تكرار الصياغة يدوياً في كلاسين.
     */
    public static function build_replacement_credit_lock_name($user_id, $credit_cycle_id): string
    {
        return 'pge_replacement_credit_' . md5($user_id . '|' . $credit_cycle_id);
    }

    /**
     * قراءة صف استحقاق مرتبط فعلاً بـconsumed_by_ledger_id محدد — فحص
     * Idempotency الخاص بـclaim_oldest_granted_for_ledger() فقط.
     */
    private static function find_by_consumed_ledger_id(int $consumed_by_ledger_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE consumed_by_ledger_id = %d LIMIT 1", $consumed_by_ledger_id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * ============================================================================
     * اختيار واستهلاك أقدم استحقاق granted (FIFO) وربطه بصف Ledger مُستهلَك
     * فعلياً بالفعل (المرحلة 4C: Replacement Credit Consumption During Cartat
     * Queue Delivery).
     * ============================================================================
     * تُستدعى **فقط** بعد نجاح
     * PGE_Invitation_Credit_Ledger::mark_consumed_with_token() على صف
     * replacement فعلي — لا قبل ذلك إطلاقاً (لا حجز/تخمين مسبق لاستحقاق قبل
     * تأكد الإرسال الفعلي). $consumed_by_ledger_id هو معرّف ذلك الصف بالضبط.
     *
     * الحماية الذرية ضد اختيار عاملين متزامنَين لنفس "أقدم استحقاق granted":
     * GET_LOCK باسم build_replacement_credit_lock_name($user_id,
     * $credit_cycle_id) يُغلّف القراءة+الاختيار+mark_consumed() معاً — بنفس
     * فلسفة PGE_Invitation_Credit_Ledger::claim_for_delivery() تماماً؛ ممنوع
     * "SELECT ثم UPDATE" بلا قفل مطلقاً هنا.
     *
     * Idempotent بالكامل ومزدوج الفحص (قبل القفل وتحت القفل): إن وُجد
     * استحقاق مرتبط فعلاً بنفس consumed_by_ledger_id (من محاولة سابقة نجحت)
     * يُعاد 'already_linked' فوراً دون أي بحث أو كتابة إضافية — يسمح
     * باستدعاء هذه الدالة بأمان من كل من مسار cron_process_queue() المباشر
     * (بعد نجاح Cartat) **و** reconcile_consumed_replacement_ledger() (إصلاح
     * لاحق قابل لإعادة التشغيل) دون أي خطر ربط مزدوج.
     *
     * القيم المُعادة:
     *  - ['result'=>'already_linked','id'=>int]: استحقاق مرتبط مسبقاً بهذا
     *    الـledger_id بالذات — العملية آمنة ومكتملة فعلاً (Idempotent).
     *  - ['result'=>'consumed','id'=>int]: استحقاق granted جديد اختير
     *    واستُهلك الآن فعلاً وربط بهذا الـledger_id.
     *  - ['result'=>'error','reason'=>string]: تحقّق مدخلات فشل، تعذّر
     *    القفل (lock_not_acquired)، لا يوجد أي استحقاق granted متاح
     *    (no_entitlement_available)، أو فشل mark_consumed() الداخلي لأي سبب
     *    آخر (سباق نادر جداً رغم القفل — 'reason' يطابق سبب mark_consumed()
     *    نفسه بالضبط).
     */
    public static function claim_oldest_granted_for_ledger($user_id, $credit_cycle_id, $consumed_by_ledger_id): array
    {
        $normalized_user_id = self::normalize_positive_id($user_id);
        if ($normalized_user_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_user_id'];
        }

        $credit_cycle_id = is_string($credit_cycle_id) ? trim($credit_cycle_id) : '';
        if ($credit_cycle_id === '') {
            return ['result' => 'error', 'reason' => 'invalid_credit_cycle_id'];
        }

        $normalized_ledger_id = self::normalize_positive_id($consumed_by_ledger_id);
        if ($normalized_ledger_id === 0) {
            return ['result' => 'error', 'reason' => 'invalid_consumed_by_ledger_id'];
        }

        // فحص Idempotency أولاً — بلا قفل، قراءة بحتة رخيصة. إن وُجد ربط
        // سابق فعلي، لا حاجة إطلاقاً لدخول القفل أو أي منطق اختيار.
        $already = self::find_by_consumed_ledger_id($normalized_ledger_id);
        if ($already !== null) {
            return ['result' => 'already_linked', 'id' => (int) $already['id']];
        }

        global $wpdb;
        $lock_name = self::build_replacement_credit_lock_name($normalized_user_id, $credit_cycle_id);

        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ((int) $got_lock !== 1) {
            return ['result' => 'error', 'reason' => 'lock_not_acquired'];
        }

        try {
            // إعادة فحص Idempotency تحت القفل — احتياط أخير ضد سباق نادر جداً
            // بين الفحص الأول أعلاه (بلا قفل) ولحظة الحصول على القفل فعلياً.
            $already = self::find_by_consumed_ledger_id($normalized_ledger_id);
            if ($already !== null) {
                return ['result' => 'already_linked', 'id' => (int) $already['id']];
            }

            $oldest = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM " . self::table_name() . " WHERE user_id = %d AND credit_cycle_id = %s AND status = %s ORDER BY granted_at ASC, id ASC LIMIT 1",
                    $normalized_user_id,
                    $credit_cycle_id,
                    'granted'
                ),
                ARRAY_A
            );

            if ($oldest === null) {
                return ['result' => 'error', 'reason' => 'no_entitlement_available'];
            }

            return self::mark_consumed((int) $oldest['id'], $normalized_ledger_id);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}
