<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Checkin Recorder — Guest Check-in Engine، Phase 4 (مُصحَّح: Blocker Fix)
 * ============================================================================
 * "Guest Check-in Engine" RFC، Requirement 3 (Attendance Recording)،
 * Requirement 4 (Validation)، Requirement 5 (Duplicate Protection)،
 * Requirement 6 (Attendance Audit Log).
 *
 * الوسيط الوحيد المسموح به للكتابة على أعمدة الحضور الثلاثة الجديدة في
 * wp_pge_event_rsvps (checked_in_by_assignment_id/checkin_method/
 * actual_entered_count) وعلى جدول wp_pge_checkin_audit_log بالكامل. لا ملف
 * آخر يكتب على أي منهما.
 *
 * ============================================================================
 * تصحيح معماري (Blocking Issue): الهوية الموثوقة هي rsvp_id، لا الهاتف
 * ============================================================================
 * "The protected entity is the RSVP / invitation record, NOT the phone
 * number. Phone is only a lookup attribute. It is not a stable invitation
 * identity." — هذه النسخة تستبدل التصميم الأصلي (الذي كان يستخدم الهاتف
 * كمفتاح قفل وكهوية تحديث/تكرار) بالكامل:
 *
 *   - القفل يُبنى من (event_id, rsvp_id) حصراً — لا الهاتف إطلاقاً.
 *   - القراءة الحيّة داخل القفل تتم بـ"WHERE id = rsvp_id" حصراً.
 *   - فحص "already checked in" يعتمد على rsvp_id حصراً.
 *   - عملية التحديث (UPDATE) هدفها "WHERE id = rsvp_id" حصراً — أبداً
 *     "WHERE guest_phone = ...".
 *   - سجل التدقيق يُسجَّل بـrsvp_id.
 *   - الهاتف لا يظهر في أي من الأربعة أعلاه؛ يبقى معلوماتياً/قابلاً للعرض
 *     فقط ضمن Guest Object الوارد (لأغراض العرض للمستخدم لاحقاً إن لزم).
 *
 * هذا يضمن أن "Two different RSVP records within the same event using the
 * same phone number" حالة مدعومة بالكامل: تسجيل حضور السجل الأول لا يقفل
 * الثاني، ولا يُعلَم كمسجَّل حضوره، ولا يمنع تسجيله لاحقاً، ولا يُعاد استخدام
 * هويته في سجل التدقيق — لكل RSVP قفل مستقل وحالة حضور مستقلة تماماً.
 *
 * ============================================================================
 * عقد الاستدعاء (Recorder Contract) — بعد اكتمال Guest Resolution فقط
 * ============================================================================
 * record_guest_checkin(int $assignment_id, array $guest, int $actual_entered_count, string $method)
 *
 * $guest هو "Guest Object" الموحَّد الصادر حصراً عن PGE_Guest_Resolution_Service
 * (resolve_from_qr()/resolve_by_rsvp_id()/resolve_and_materialize_by_phone()) —
 * ويجب أن يحمل بالفعل: event_id، rsvp_id (int موجب — لا null، لا صفر)،
 * expected_guest_count. إن وصل rsvp_id فارغاً/غير موجب تُرفَض العملية فوراً
 * قبل أي محاولة قفل أو قراءة — لا معنى لتسجيل حضور بلا هوية RSVP مستقرة.
 *
 * مسارا QR واليدوي كلاهما ينتجان نفس بنية Guest Object، وكلاهما يستدعيان
 * هذه الدالة نفسها بعد ذلك — لا منطق تسجيل منفصل بينهما (Requirement:
 * "Do NOT create separate recording logic for QR and manual check-in").
 *
 * ============================================================================
 * التدفّق الذري (يجب أن يبقى بالضبط بهذا الترتيب)
 * ============================================================================
 * 1. التحقق من صلاحية Guest Object الموثوق (rsvp_id/event_id موجودان وصحيحان).
 * 2. بناء اسم القفل من event_id + rsvp_id.
 * 3. GET_LOCK.
 * 4. إعادة قراءة صف RSVP **داخل** القفل (بـid، لا هاتف).
 * 5. التأكد أن الصف ينتمي فعلاً لنفس المناسبة.
 * 6. التأكد أنه لم يُسجَّل حضوره مسبقاً.
 * 7. التحقق من العدد الفعلي (Requirement 4).
 * 8. كتابة حقول الحضور (UPDATE بـid).
 * 9. إلحاق سطر تدقيق (Append-Only).
 * 10. RELEASE_LOCK داخل finally.
 *
 * "No database write may occur before the lock is acquired" — لا كتابة قبل
 * الحصول على القفل هنا إطلاقاً؛ خطوة "تثبيت" صف RSVP لضيف لم يردّ قط (لو
 * لزمت) منفصلة تماماً وتسبق استدعاء هذه الدالة بالكامل (راجع
 * PGE_Guest_Resolution_Service::resolve_and_materialize_by_phone()) — ذلك
 * يقع ضمن "Guest Resolution"، لا ضمن "Attendance Recording" الذي يبدأ فقط
 * من هذه النقطة.
 *
 * ============================================================================
 * "من المصدر الحقيقي الوحيد؟" (Requirement 8 — لا وثوق بمعاملات الطلب)
 * ============================================================================
 * assignment_id يجب أن يصل لهذه الدالة **حصراً** من نتيجة
 * PGE_Supervisor_Portal_Middleware::authorize() الناجحة (طبقة الاستدعاء —
 * checkin-ajax.php)، لا من أي حقل $_POST مباشر. كطبقة دفاع إضافية (Defense in
 * Depth، لا بديلاً عن ذلك التحقق) هذه الدالة تتحقق أيضاً بنفسها أن
 * assignment_id المُمرَّر إسناد **نشط فعلاً** لنفس event_id عبر
 * PGE_Supervisor_Assignment_Service::get_assignment_state() — قراءة فقط، لا
 * علاقة لها بمصادقة الجلسة نفسها (تبقى حصراً مسؤولية Middleware).
 */
class PGE_Checkin_Recorder
{
    const VALID_METHODS = ['qr', 'manual'];

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

    /**
     * اسم القفل يُشتَق حصراً من (event_id, rsvp_id) — لا الهاتف إطلاقاً
     * (Blocking Issue: "The lock must uniquely represent one RSVP inside one
     * event"). md5() لضمان طول ثابت وآمن (نفس نمط بقية المشروع).
     */
    private static function build_lock_name(int $event_id, int $rsvp_id): string
    {
        return 'pge_checkin_' . md5($event_id . '|' . $rsvp_id);
    }

    /**
     * تسجيل حضور ضيف واحد — نقطة الكتابة الوحيدة لكل من الحضور اليدوي ونتيجة
     * مسح QR على حد سواء (كلاهما يستدعي هذه الدالة نفسها بعد الحصول على
     * Guest Object من PGE_Guest_Resolution_Service — لا منطق كتابة مكرَّر).
     *
     * @param int   $assignment_id        موثوق (من Middleware::authorize()).
     * @param array $guest                Guest Object الموحَّد (يجب أن يحمل rsvp_id).
     * @param int   $actual_entered_count العدد الفعلي المُدخَل (Requirement 4).
     * @param string $method              'qr'|'manual' فقط.
     * @return array{result:string, reason?:string, guest?:array, expected_count?:int}
     */
    public static function record_guest_checkin(int $assignment_id, array $guest, int $actual_entered_count, string $method): array
    {
        $assignment_id = (int) $assignment_id;

        if (!in_array($method, self::VALID_METHODS, true)) {
            return ['result' => 'error', 'reason' => 'invalid_method'];
        }
        if ($assignment_id <= 0) {
            return ['result' => 'error', 'reason' => 'invalid_assignment_id'];
        }

        $event_id = isset($guest['event_id']) ? (int) $guest['event_id'] : 0;
        $rsvp_id = isset($guest['rsvp_id']) ? (int) $guest['rsvp_id'] : 0;

        if ($event_id <= 0) {
            return ['result' => 'error', 'reason' => 'invalid_event_id'];
        }

        // "The Recorder must NOT use phone as: Lock identity, Attendance
        // identity, Duplicate-check identity, Update target." — بلا rsvp_id
        // موثوق لا يمكن المتابعة إطلاقاً؛ الهاتف لا يُستخدَم كبديل أبداً.
        if ($rsvp_id <= 0) {
            return ['result' => 'error', 'reason' => 'missing_rsvp_id'];
        }

        // دفاع إضافي (Requirement 8) — الإسناد نشط فعلاً ولنفس المناسبة
        // تحديداً. لا يستبدل تحقّق الجلسة في Middleware، يضيف طبقة ثانية فقط.
        if (!class_exists('PGE_Supervisor_Assignment_Service')) {
            return ['result' => 'error', 'reason' => 'assignment_service_unavailable'];
        }
        $assignment = PGE_Supervisor_Assignment_Service::get_assignment_state($assignment_id);
        if ($assignment === null || (string) ($assignment['status'] ?? '') !== 'active' || (int) ($assignment['event_id'] ?? 0) !== $event_id) {
            return ['result' => 'error', 'reason' => 'assignment_not_authorized'];
        }

        global $wpdb;
        $rsvp_table = self::rsvps_table_name();
        $audit_table = self::audit_table_name();
        $lock_name = self::build_lock_name($event_id, $rsvp_id);

        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ((int) $got_lock !== 1) {
            return ['result' => 'error', 'reason' => 'lock_not_acquired'];
        }

        try {
            // إعادة قراءة الحالة الحيّة **داخل** القفل — بـid حصراً، لا هاتف
            // (لا كتابة قبل هذه النقطة إطلاقاً).
            $existing = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $rsvp_table WHERE id = %d LIMIT 1", $rsvp_id),
                ARRAY_A
            );

            if ($existing === null) {
                return ['result' => 'error', 'reason' => 'rsvp_not_found'];
            }

            // "Confirm RSVP belongs to event" — تحقّق دفاعي إضافي رغم أن
            // Guest Resolution يضمن هذا مسبقاً؛ لا وثوق أعمى بأي طبقة سابقة.
            if ((int) ($existing['event_id'] ?? 0) !== $event_id) {
                return ['result' => 'error', 'reason' => 'event_mismatch'];
            }

            // "Guest must not already be checked in" (Requirement 3) — الفحص
            // بـrsvp_id (id الصف) حصراً، لا بالهاتف؛ هذا يضمن أن تسجيل حضور
            // سجل RSVP آخر يشارك نفس الهاتف لا يؤثر إطلاقاً على هذا الصف.
            if ((int) ($existing['checked_in'] ?? 0) === 1) {
                return ['result' => 'already_checked_in', 'guest' => self::guest_snapshot($existing)];
            }

            // العدد المتوقَّع يُعاد اشتقاقه من الصف الحيّ المُعاد قراءته
            // للتو داخل القفل (لا من Guest Object المحتمل تقادمه) — نفس مصدر
            // الحقيقة الوحيد المستخدَم لبقية هذا التدفّق.
            $companions = (int) ($existing['companions'] ?? 0);
            $expected_count = 1 + $companions;

            // Requirement 4: حد أدنى 1 (لا حضور صفري)، حد أقصى = المتوقَّع.
            if ($actual_entered_count < 1 || $actual_entered_count > $expected_count) {
                return [
                    'result' => 'error',
                    'reason' => 'invalid_actual_count',
                    'expected_count' => $expected_count,
                ];
            }

            $now = current_time('mysql', true);

            // هدف التحديث id الصف حصراً — أبداً guest_phone.
            $updated = $wpdb->update(
                $rsvp_table,
                [
                    'checked_in' => 1,
                    'checked_in_at' => $now,
                    'checked_in_by_assignment_id' => $assignment_id,
                    'checkin_method' => $method,
                    'actual_entered_count' => $actual_entered_count,
                ],
                ['id' => $rsvp_id],
                ['%d', '%s', '%d', '%s', '%d'],
                ['%d']
            );

            if ($updated === false) {
                return ['result' => 'error', 'reason' => 'update_failed'];
            }

            // Requirement 6: سجل تدقيق Append-Only — سطر جديد دائماً، مربوط
            // بـrsvp_id (لا هاتف) — لكل RSVP سجل تدقيق مستقل تماماً حتى لو
            // شارك هاتفاً مع RSVP آخر.
            $audit_inserted = $wpdb->insert(
                $audit_table,
                [
                    'event_id' => $event_id,
                    'rsvp_id' => $rsvp_id,
                    'assignment_id' => $assignment_id,
                    'method' => $method,
                    'expected_count' => $expected_count,
                    'actual_count' => $actual_entered_count,
                    'entry_type' => 'confirmation',
                    'created_at' => $now,
                ],
                ['%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s']
            );

            if (!$audit_inserted) {
                return ['result' => 'error', 'reason' => 'audit_log_failed'];
            }

            $final_row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $rsvp_table WHERE id = %d LIMIT 1", $rsvp_id),
                ARRAY_A
            );

            return [
                'result' => 'checked_in',
                'guest' => self::guest_snapshot($final_row ?: $existing),
                'expected_count' => $expected_count,
                'actual_count' => $actual_entered_count,
                'audit_log_id' => (int) $wpdb->insert_id,
            ];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * لقطة عرض مبسَّطة لضيف بعد الكتابة — لا تُستهلَك كـ"Guest Object" الكامل
     * (ذاك حصراً من PGE_Guest_Resolution_Service)، فقط الحقول اللازمة لعرض
     * نتيجة الكتابة مباشرة للمستدعي بلا استعلام إضافي. مبنية من الصف نفسه
     * (id/event_id/guest_phone) — الهاتف هنا معلوماتي/عرضي فقط، لا هوية.
     */
    private static function guest_snapshot(array $row): array
    {
        return [
            'event_id' => isset($row['event_id']) ? (int) $row['event_id'] : null,
            'rsvp_id' => isset($row['id']) ? (int) $row['id'] : null,
            'phone' => (string) ($row['guest_phone'] ?? ''),
            'checked_in' => isset($row['checked_in']) ? ((int) $row['checked_in'] === 1) : false,
            'checked_in_at' => $row['checked_in_at'] ?? null,
            'checkin_method' => $row['checkin_method'] ?? null,
            'actual_entered_count' => isset($row['actual_entered_count']) && $row['actual_entered_count'] !== null
                ? (int) $row['actual_entered_count']
                : null,
        ];
    }
}
