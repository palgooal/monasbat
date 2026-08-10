<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Repository — Entry Check-in Supervisors، Phase 9
 * ============================================================================
 * "Host Invitation Management" RFC — "Host UI → Invitation Controller/AJAX →
 * Invitation Service → Invitation Repository → Database."
 *
 * هذا الملف طبقة الوصول الوحيدة لبيانات "الدعوة" المُركَّبة من ثلاثة مصادر
 * قائمة فعلاً، بلا اختراع أي تخزين مُكرِّر لها:
 *   1. `_pge_invited_guests` (post meta) — الاسم/الهاتف/الملاحظة/رمز الدعوة
 *      (invite_code) — تُقرَأ/تُكتَب حصراً عبر الدوال الحالية في
 *      event-guests.php (pge_event_guests_get_map()/save_map()/
 *      get_row_payload()/get_stats()) — **بلا إعادة تنفيذ منطقها هنا**.
 *   2. `wp_pge_event_rsvps` (جدول، ممنوع تعديل مخططه بدون مراجعة صريحة وفق
 *      CLAUDE.md) — حالة الرد (RSVP) وحالة الحضور — تُقرَأ حصراً عبر
 *      pge_event_guests_load_rsvp_from_db() الحالية (قراءة فقط، مع cache
 *      ثابت داخلها أصلاً يمنع N+1). Phase C يضيف كتابة واحدة محصورة: reset
 *      دورة الحياة داخل create() فقط؛ بقية عمليات Repository لا تكتب RSVP.
 *   3. `_pge_invitation_status` (post meta جديد، **حصري لهذه المرحلة**) —
 *      حالة الدعوة الإدارية (active/cancelled) + طوابع invited_at/updated_at/
 *      qr_regenerated_at. مفتاح post meta مستقل تماماً عن `_pge_invited_
 *      guests` عمداً: pge_event_guests_save_map() (اللوحة القديمة) تُعيد بناء
 *      `_pge_invited_guests` بالكامل من مصفوفة بأربعة مفاتيح فقط
 *      (phone/name/note/code) في كل حفظ — لو خُزِّنت حالة الدعوة الجديدة داخل
 *      نفس المصفوفة لكانت اللوحة القديمة تحذفها ضمنياً عند أي حفظ تالٍ من
 *      طرفها (تعارض كتابة صامت). تخزينها في مفتاح post meta منفصل تماماً
 *      يمنع هذا التعارض بنيوياً بلا أي تعديل على event-guests.php.
 *
 * القرار الاصطلاحي: "Invitation" في مصطلحات هذه المرحلة يقابل **بالضبط**
 * "الضيف المدعو" الحالي (Guest) — لا كيان جديد، ولا جدول جديد. "Invitation ID"
 * المطلوب حمايته (Requirement: "Never trust invitation_id alone. Always
 * validate event_id + ownership") هو **رقم الهاتف المُطبَّع ضمن مناسبة
 * واحدة** — هذا هو المفتاح الطبيعي الوحيد لصف الضيف أصلاً في `_pge_invited_
 * guests` (بنفس ما تعتمده event-guests.php الحالية حرفياً عبر data-phone في
 * page-event-manage.php)، فلا داعٍ لاختراع معرِّف داخلي جديد يحتاج تعييناً/
 * فهرسة إضافية. بما أن التخزين مقسَّم أصلاً بمفتاح post meta *لكل مناسبة على
 * حدة*، لا يمكن بنيوياً لهاتف في مناسبة أن "يُخلَط" مع مناسبة أخرى — التحقّق
 * الصريح من (event_id + ownership) يبقى مطلوباً مع ذلك على مستوى الطبقة
 * الأعلى (الخدمة/الـAJAX) لأن `event_id` نفسه قد يصل من طلب غير موثوق.
 *
 * ============================================================================
 * Phase 9B QR Architecture Final Fix — تصحيح القرار الاصطلاحي الثاني (مُلغى)
 * ============================================================================
 * القرار الاصطلاحي الثاني الأصلي (أعلاه في نسخة سابقة من هذا الملف) كان
 * يُساوي خطأً بين "Invitation Token"/"QR Reference" وinvite_code — أي أن
 * "QR Regeneration" كانت تُنفَّذ فعلياً كاستبدال لـinvite_code نفسه. ثبت هذا
 * **خاطئاً معمارياً**: invite_code هو رمز بحث/مراجعة يدوية بشرية (Manual
 * Invitation Code) — عرضي بطبيعته، يظهر في نص الرسائل، يُستخدَم في `search()`
 * فقط. بيان اعتماد الماسح الإنتاجي (Scanner QR Credential) كيان **منفصل
 * تماماً** الآن: حمولة مُوقَّعة (event_id|rsvp_id|qr_version|signature) عبر
 * PGE_Checkin_QR_Service — invite_code لا يدخل في هذه الحمولة إطلاقاً بعد
 * الآن. راجع docs/INVITATION-QR-ARCHITECTURE.md للتوثيق الكامل لهذا الفصل.
 * "QR Regeneration" الآن تُدوِّر `qr_version` فقط (`_pge_invitation_status`
 * أعلاه) — **لا تلمس invite_code إطلاقاً**، ولا `_pge_invited_guests` إطلاقاً.
 *
 * القيد المعماري الصريح (يُذكَر في التقرير النهائي كمخاطرة، لا يُحَل هنا):
 * "Cancelled invitation cannot be checked in" — هذا الملف يُسجِّل حالة
 * الإلغاء فقط (`_pge_invitation_status`)؛ محرك التحقّق الفعلي عند تسجيل
 * الحضور (PGE_Guest_Resolution_Service/PGE_Checkin_Recorder، Phase 4) **لا
 * يُعدَّل هنا إطلاقاً** (ممنوع صراحة: "Do NOT modify... Recorder"، وAttendance
 * خارج النطاق) — فلا إنفاذ فعلي عند نقطة المسح اليوم. راجع "Risks" في تقرير
 * هذه المرحلة.
 */

require_once __DIR__ . '/event-guests.php';
require_once __DIR__ . '/rsvp-canonical-lookup.php';

class PGE_Invitation_Repository
{
    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const VALID_STATUSES = [self::STATUS_ACTIVE, self::STATUS_CANCELLED];

    /**
     * Phase 9B QR Architecture Final Fix — إصدار بدائي (rotation primitive) لكل
     * دعوة، مُخزَّن في نفس مفتاح post meta الإداري الحالي `_pge_invitation_status`
     * (لا جدول جديد، لا تعديل على هوية الدعوة/الضيف/RSVP). القيمة الافتراضية
     * لأي دعوة **لا تملك قيمة مخزَّنة بعد** (قديمة/لم تُجدَّد قط) هي هذا الثابت —
     * توافق قديم صريح (Legacy Compatibility)، لا كتابة تلقائية عند القراءة.
     * أول استدعاء لـregenerate_qr() فقط هو من يكتب قيمة صريحة (البداية + 1).
     */
    const DEFAULT_QR_VERSION = 1;

    /**
     * قراءة خريطة حالة الدعوات الإدارية (مفتاح post meta مستقل تماماً).
     *
     * @return array<string,array{status:string,invited_at:string,updated_at:string,cancelled_at:?string,cancel_reason:string,qr_regenerated_at:?string}>
     */
    private static function get_status_map($event_id): array
    {
        $stored = get_post_meta((int) $event_id, '_pge_invitation_status', true);
        return is_array($stored) ? $stored : [];
    }

    private static function save_status_map($event_id, array $map): void
    {
        update_post_meta((int) $event_id, '_pge_invitation_status', $map);
    }

    private static function normalize_phone($value)
    {
        return function_exists('pge_event_guests_norm_phone') ? pge_event_guests_norm_phone($value) : preg_replace('/\D+/', '', trim((string) $value));
    }

    /**
     * صف "دعوة" مُركَّب بالكامل — الاسم/الهاتف/الرمز (Guest map) + حالة
     * الرد/الحضور (RSVP، قراءة فقط) + حالة الدعوة الإدارية (Phase 9 الجديدة).
     * قراءة فقط، بلا أي أثر جانبي.
     *
     * @return array|null null إذا لم يوجد ضيف بهذا الهاتف في هذه المناسبة.
     */
    public static function get_invitation($event_id, $phone): ?array
    {
        $event_id = (int) $event_id;
        $normalized_phone = self::normalize_phone($phone);
        if ($event_id <= 0 || $normalized_phone === '') {
            return null;
        }

        $guests_map = pge_event_guests_get_map($event_id);
        if (!isset($guests_map[$normalized_phone])) {
            return null;
        }

        return self::build_row($event_id, $guests_map[$normalized_phone]);
    }

    private static function build_row($event_id, array $guest): array
    {
        $phone = (string) ($guest['phone'] ?? '');
        $row_payload = pge_event_guests_get_row_payload($event_id, $guest);
        $status_map = self::get_status_map($event_id);
        $status_entry = $status_map[$phone] ?? [];

        $invitation_status = (string) ($status_entry['status'] ?? self::STATUS_ACTIVE);
        if (!in_array($invitation_status, self::VALID_STATUSES, true)) {
            $invitation_status = self::STATUS_ACTIVE;
        }

        return [
            'event_id'           => (int) $event_id,
            'phone'              => $phone,
            'name'               => (string) ($row_payload['name'] ?? ''),
            'note'               => (string) ($row_payload['note'] ?? ''),
            'code'               => (string) ($row_payload['code'] ?? ''),
            'rsvp_status'        => (string) ($row_payload['status'] ?? 'pending'),
            'rsvp_status_label'  => (string) ($row_payload['status_label'] ?? ''),
            'attendance_status'  => (string) ($row_payload['checked'] ?? 'no'),
            'invitation_status'  => $invitation_status,
            'qr_status'          => ((string) ($row_payload['code'] ?? '')) !== '' ? 'issued' : 'not_issued',
            'invited_at'         => (string) ($status_entry['invited_at'] ?? ''),
            'updated_at'         => (string) ($status_entry['updated_at'] ?? ''),
            'cancelled_at'       => $status_entry['cancelled_at'] ?? null,
            'qr_regenerated_at'  => $status_entry['qr_regenerated_at'] ?? null,
        ];
    }

    /**
     * قائمة كل "الدعوات" (الضيوف) لمناسبة واحدة، كصفوف مُركَّبة كاملة —
     * قراءة فقط، لا ترقيم/بحث/فرز هنا (مسؤولية list_invitations() أدناه).
     *
     * @return array<int,array>
     */
    public static function list_all($event_id): array
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) {
            return [];
        }

        $guests_map = pge_event_guests_get_map($event_id);
        $rows = [];
        foreach ($guests_map as $guest) {
            $rows[] = self::build_row($event_id, $guest);
        }

        return $rows;
    }

    /**
     * قائمة مُرشَّحة/مُرتَّبة/مُرقَّمة — تُطبَّق في الذاكرة فوق list_all()
     * (قيد معماري مُوثَّق صراحة في تقرير هذه المرحلة: تخزين الضيوف الحالي
     * post-meta-based، لا جدول DB حقيقي، فلا وجود لترقيم SQL حقيقي على مستوى
     * الاستعلام — راجع "Risks"). قراءة RSVP الأساسية تمرّ عبر
     * pge_event_guests_load_rsvp_from_db() التي تُخزِّن نتيجتها في static
     * cache لكل event_id لمرة واحدة فقط لكل طلب (لا N+1 عبر عدد الضيوف).
     *
     * @param array{search?:string,rsvp_status?:string,invitation_status?:string,attendance_status?:string,sort_by?:string,sort_dir?:string,page?:int,per_page?:int} $args
     * @return array{items:array<int,array>,total:int,page:int,per_page:int,total_pages:int}
     */
    public static function list_invitations($event_id, array $args = []): array
    {
        $rows = self::list_all($event_id);

        $search = is_scalar($args['search'] ?? '') ? trim((string) ($args['search'] ?? '')) : '';
        if ($search !== '') {
            $normalized_search_phone = self::normalize_phone($search);
            $needle = mb_strtolower($search, 'UTF-8');
            $rows = array_values(array_filter($rows, function ($row) use ($needle, $normalized_search_phone) {
                if ($normalized_search_phone !== '' && strpos($row['phone'], $normalized_search_phone) !== false) {
                    return true;
                }
                if ($needle !== '' && mb_strpos(mb_strtolower($row['name'], 'UTF-8'), $needle, 0, 'UTF-8') !== false) {
                    return true;
                }
                if ($needle !== '' && mb_strpos(mb_strtolower($row['code'], 'UTF-8'), $needle, 0, 'UTF-8') !== false) {
                    return true;
                }
                return false;
            }));
        }

        $rsvp_filter = is_scalar($args['rsvp_status'] ?? '') ? (string) ($args['rsvp_status'] ?? '') : '';
        if ($rsvp_filter !== '' && $rsvp_filter !== 'all') {
            $rows = array_values(array_filter($rows, function ($row) use ($rsvp_filter) { return $row['rsvp_status'] === $rsvp_filter; }));
        }

        $invitation_filter = is_scalar($args['invitation_status'] ?? '') ? (string) ($args['invitation_status'] ?? '') : '';
        if ($invitation_filter !== '' && $invitation_filter !== 'all') {
            $rows = array_values(array_filter($rows, function ($row) use ($invitation_filter) { return $row['invitation_status'] === $invitation_filter; }));
        }

        $attendance_filter = is_scalar($args['attendance_status'] ?? '') ? (string) ($args['attendance_status'] ?? '') : '';
        if ($attendance_filter !== '' && $attendance_filter !== 'all') {
            $rows = array_values(array_filter($rows, function ($row) use ($attendance_filter) { return $row['attendance_status'] === $attendance_filter; }));
        }

        $sort_by = in_array(($args['sort_by'] ?? ''), ['name', 'phone', 'invited_at', 'updated_at'], true) ? $args['sort_by'] : 'name';
        $sort_dir = (($args['sort_dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
        usort($rows, function ($a, $b) use ($sort_by, $sort_dir) {
            $cmp = strcmp((string) $a[$sort_by], (string) $b[$sort_by]);
            return $sort_dir === 'desc' ? -$cmp : $cmp;
        });

        $total = count($rows);
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = (int) ($args['per_page'] ?? 20);
        $per_page = ($per_page > 0 && $per_page <= 200) ? $per_page : 20;
        $offset = ($page - 1) * $per_page;

        return [
            'items'       => array_slice($rows, $offset, $per_page),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0,
        ];
    }

    /**
     * إنشاء دعوة (ضيف) جديدة — يُعيد استخدام pge_event_guests_get_map()/
     * save_map() الحاليتين حرفياً (بما فيهما توليد invite_code التلقائي
     * الموجود أصلاً داخل save_map())، ثم يُهيِّئ سجل حالة الدعوة الجديد
     * (active + invited_at/updated_at) في المفتاح المنفصل.
     *
     * A successful call is the authoritative start of a new invitation
     * lifecycle. If a historical canonical RSVP snapshot exists for the same
     * identity, it is reset in place before the invitation becomes visible.
     *
     * @return array{result:string, phone?:string, reason?:string}
     *   'created'  — نجح الإنشاء.
     *   'duplicate'— الهاتف موجود بالفعل ضمن هذه المناسبة.
     *   'error'    — بيانات غير صالحة.
     */
    public static function create($event_id, $phone, $name = '', $note = '')
    {
        $event_id = (int) $event_id;
        $normalized_phone = self::normalize_phone($phone);
        $normalized_name = is_scalar($name) ? trim((string) $name) : '';

        if ($event_id <= 0 || $normalized_phone === '') {
            return ['result' => 'error', 'reason' => 'invalid_phone'];
        }
        if ($normalized_name === '') {
            return ['result' => 'error', 'reason' => 'invalid_name'];
        }

        $guests_map = pge_event_guests_get_map($event_id);
        if (isset($guests_map[$normalized_phone])) {
            return ['result' => 'duplicate', 'phone' => $normalized_phone];
        }

        $status_map = self::get_status_map($event_id);
        $original_guests_map = $guests_map;
        $original_status_map = $status_map;
        $lifecycle_timestamp = current_time('mysql', true);

        // Phase C: the canonical RSVP snapshot is reset only here, after all
        // validation/duplicate checks (and, for every live caller, the Service
        // quota check) but before the new invitation is exposed in post meta.
        $rsvp_reset = self::reset_rsvp_for_new_invitation_lifecycle(
            $event_id,
            $normalized_phone,
            $lifecycle_timestamp
        );
        if (($rsvp_reset['result'] ?? '') !== 'success') {
            return ['result' => 'error', 'reason' => (string) ($rsvp_reset['reason'] ?? 'rsvp_lifecycle_reset_failed')];
        }

        $guests_map[$normalized_phone] = [
            'phone' => $normalized_phone,
            'name'  => $normalized_name,
            'note'  => is_scalar($note) ? trim((string) $note) : '',
        ];
        $saved_guests_map = null;
        $stored_status_map = [];
        try {
            $saved_guests_map = pge_event_guests_save_map($event_id, $guests_map);

            $status_map[$normalized_phone] = [
                'status'             => self::STATUS_ACTIVE,
                'invited_at'         => $lifecycle_timestamp,
                'updated_at'         => $lifecycle_timestamp,
                'cancelled_at'       => null,
                'cancel_reason'      => '',
                'qr_regenerated_at'  => null,
            ];
            self::save_status_map($event_id, $status_map);
            $stored_status_map = self::get_status_map($event_id);
        } catch (\Throwable $e) {
            error_log("PGE invitation storage error: event_id={$event_id} reason=post_meta_write_failed");
        }

        $invitation_stored = is_array($saved_guests_map)
            && isset($saved_guests_map[$normalized_phone])
            && isset($stored_status_map[$normalized_phone])
            && (string) ($stored_status_map[$normalized_phone]['invited_at'] ?? '') === $lifecycle_timestamp;

        if (!$invitation_stored) {
            // Compensation is used instead of a cross-API SQL transaction:
            // update_post_meta() has its own cache semantics. Restore both
            // post-meta maps and the exact historical RSVP snapshot.
            $meta_restored = true;
            try {
                pge_event_guests_save_map($event_id, $original_guests_map);
                self::save_status_map($event_id, $original_status_map);
            } catch (\Throwable $e) {
                $meta_restored = false;
                error_log("PGE invitation lifecycle rollback failed: event_id={$event_id} reason=post_meta_restore_failed");
            }

            $restored_guests_map = pge_event_guests_get_map($event_id);
            $restored_status_map = self::get_status_map($event_id);
            $meta_restored = $meta_restored
                && !isset($restored_guests_map[$normalized_phone])
                && $restored_status_map === $original_status_map;

            $rsvp_restored = self::restore_rsvp_after_failed_invitation_create($event_id, $normalized_phone, $rsvp_reset);
            if (!$meta_restored || !$rsvp_restored) {
                error_log("PGE invitation lifecycle rollback failed: event_id={$event_id} reason=compensation_failed");
                return ['result' => 'error', 'reason' => 'rsvp_lifecycle_rollback_failed'];
            }

            return ['result' => 'error', 'reason' => 'invitation_storage_failed'];
        }

        return ['result' => 'created', 'phone' => $normalized_phone];
    }

    /**
     * Reset the current-snapshot RSVP row for a newly starting invitation
     * lifecycle. No row is inserted when the identity has no historical RSVP.
     *
     * @return array{result:string,status?:string,previous_row?:?object,reason?:string}
     */
    private static function reset_rsvp_for_new_invitation_lifecycle($event_id, $normalized_phone, $lifecycle_timestamp): array
    {
        $lookup = pge_rsvp_find_canonical_by_phone($event_id, $normalized_phone);
        if (($lookup['status'] ?? '') === 'not_found') {
            return ['result' => 'success', 'status' => 'not_found', 'previous_row' => null];
        }
        if (($lookup['status'] ?? '') !== 'found' || !is_object($lookup['row'] ?? null)) {
            return ['result' => 'error', 'reason' => (string) ($lookup['reason'] ?? 'rsvp_integrity_error')];
        }

        $previous_row = clone $lookup['row'];
        $row_id = (int) ($previous_row->id ?? 0);
        if ($row_id <= 0) {
            return ['result' => 'error', 'reason' => 'invalid_rsvp_id'];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pge_event_rsvps';
        $reset = self::rsvp_lifecycle_reset_values($lifecycle_timestamp);
        $updated = $wpdb->update($table, $reset, ['id' => $row_id]);

        if ($updated === false) {
            return ['result' => 'error', 'reason' => 'rsvp_update_failed'];
        }

        $verified = pge_rsvp_find_canonical_by_phone($event_id, $normalized_phone);
        if (($verified['status'] ?? '') !== 'found'
            || (int) ($verified['row']->id ?? 0) !== $row_id
            || !self::rsvp_row_matches_values($verified['row'], $reset)) {
            $rollback = self::restore_rsvp_after_failed_invitation_create(
                $event_id,
                $normalized_phone,
                ['status' => 'reset', 'previous_row' => $previous_row]
            );
            return [
                'result' => 'error',
                'reason' => $rollback ? 'rsvp_reset_postcondition_failed' : 'rsvp_reset_rollback_failed',
            ];
        }

        return ['result' => 'success', 'status' => 'reset', 'previous_row' => $previous_row];
    }

    private static function rsvp_lifecycle_reset_values($lifecycle_timestamp): array
    {
        return [
            'guest_name'                   => null,
            'reply'                        => 'pending',
            'companions'                   => 0,
            'note'                         => null,
            'checked_in'                   => 0,
            'checked_in_at'                => null,
            'checked_in_by_assignment_id'  => null,
            'checkin_method'               => null,
            'actual_entered_count'         => null,
            'thank_you_sent_at'            => null,
            'created_at'                   => (string) $lifecycle_timestamp,
            'updated_at'                   => (string) $lifecycle_timestamp,
        ];
    }

    private static function rsvp_row_matches_values($row, array $values): bool
    {
        if (!is_object($row)) {
            return false;
        }

        foreach ($values as $field => $expected) {
            $actual = $row->{$field} ?? null;
            if ($expected === null) {
                if ($actual !== null) {
                    return false;
                }
            } elseif (in_array($field, ['companions', 'checked_in'], true)) {
                if ((int) $actual !== (int) $expected) {
                    return false;
                }
            } elseif ((string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    private static function restore_rsvp_snapshot($row_id, $previous_row): bool
    {
        if (!is_object($previous_row) || (int) $row_id <= 0) {
            return false;
        }

        $fields = array_keys(self::rsvp_lifecycle_reset_values(''));
        $restore = [];
        foreach ($fields as $field) {
            if (property_exists($previous_row, $field)) {
                $restore[$field] = $previous_row->{$field};
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pge_event_rsvps';
        $restored = $wpdb->update($table, $restore, ['id' => (int) $row_id]);
        return $restored !== false;
    }

    private static function restore_rsvp_after_failed_invitation_create($event_id, $normalized_phone, array $reset_result): bool
    {
        if (($reset_result['status'] ?? '') === 'not_found') {
            return true;
        }

        $previous_row = $reset_result['previous_row'] ?? null;
        $row_id = is_object($previous_row) ? (int) ($previous_row->id ?? 0) : 0;
        if (!self::restore_rsvp_snapshot($row_id, $previous_row)) {
            return false;
        }

        $verified = pge_rsvp_find_canonical_by_phone($event_id, $normalized_phone);
        if (($verified['status'] ?? '') !== 'found' || (int) ($verified['row']->id ?? 0) !== $row_id) {
            return false;
        }

        $values = [];
        foreach (array_keys(self::rsvp_lifecycle_reset_values('')) as $field) {
            if (property_exists($previous_row, $field)) {
                $values[$field] = $previous_row->{$field};
            }
        }
        return self::rsvp_row_matches_values($verified['row'], $values);
    }

    /**
     * تعديل حقول الدعوة القابلة للتغيير فقط (الاسم/الهاتف/الملاحظة) — يُعيد
     * استخدام نفس منطق pge_event_guest_update (الحفاظ على invite_code
     * القائم، الحفاظ على مراجع RSVP/الحضور عبر pge_event_guests_migrate_
     * phone_refs() الحالية عند تغيير الهاتف) — **لا تعديل على أي حقل حضور/
     * إحصاء إطلاقاً**، ولا على أي صف في wp_pge_event_rsvps مباشرة (فقط
     * الترحيل الحالي المُعتمَد أصلاً في event-guests.php).
     *
     * @return array{result:string, phone?:string, reason?:string}
     */
    public static function edit($event_id, $old_phone, $new_phone, $name = '', $note = '')
    {
        $event_id = (int) $event_id;
        $old_normalized = self::normalize_phone($old_phone);
        $new_normalized = self::normalize_phone($new_phone);
        $normalized_name = is_scalar($name) ? trim((string) $name) : '';

        if ($event_id <= 0 || $old_normalized === '' || $new_normalized === '') {
            return ['result' => 'error', 'reason' => 'invalid_phone'];
        }

        $guests_map = pge_event_guests_get_map($event_id);
        if (!isset($guests_map[$old_normalized])) {
            return ['result' => 'error', 'reason' => 'not_found'];
        }
        if ($old_normalized !== $new_normalized && isset($guests_map[$new_normalized])) {
            return ['result' => 'duplicate', 'phone' => $new_normalized];
        }

        $existing_guest = $guests_map[$old_normalized];
        unset($guests_map[$old_normalized]);
        $guests_map[$new_normalized] = [
            'phone' => $new_normalized,
            'name'  => $normalized_name,
            'note'  => is_scalar($note) ? trim((string) $note) : '',
            'code'  => (string) ($existing_guest['code'] ?? ''),
        ];

        if (function_exists('pge_event_guests_migrate_phone_refs')) {
            pge_event_guests_migrate_phone_refs($event_id, $old_normalized, $new_normalized);
        }
        pge_event_guests_save_map($event_id, $guests_map);

        // ترحيل سجل حالة الدعوة الإدارية لنفس المفتاح الجديد إن تغيَّر الهاتف.
        $status_map = self::get_status_map($event_id);
        $entry = $status_map[$old_normalized] ?? ['status' => self::STATUS_ACTIVE, 'invited_at' => '', 'cancelled_at' => null, 'cancel_reason' => '', 'qr_regenerated_at' => null];
        $entry['updated_at'] = current_time('mysql', true);
        if ($old_normalized !== $new_normalized) {
            unset($status_map[$old_normalized]);
            // RC1 Final Release Blocker: الهاتف الجديد قد يكون هاتفاً استُخدم
            // سابقاً لدعوة محذوفة (Hard Delete لا يمنع إعادة استخدام الهاتف)،
            // وقد يترك وراءه صف RSVP يتيماً بذلك الهاتف. بلا تحديث invited_at
            // هنا، يبقى يحمل قيمة الدعوة القديمة عند الهاتف السابق (old_phone)،
            // وقد تكون أقدم من created_at الصف اليتيم عند الهاتف الجديد، فيُعامَل
            // خطأً كـ"حالي" عبر current_or_null()/is_rsvp_row_current(). تغيير
            // الهاتف = دورة حياة جديدة فعلياً عند ذلك الهاتف تحديداً، فتُضبَط
            // invited_at من جديد هنا تماماً كما في create() — نفس المبدأ، بلا
            // عمود جديد.
            $entry['invited_at'] = $entry['updated_at'];
        }
        $status_map[$new_normalized] = $entry;
        self::save_status_map($event_id, $status_map);

        return ['result' => 'updated', 'phone' => $new_normalized];
    }

    /**
     * إلغاء دعوة — "Cancel Invitation" (Requirement: "Cannot cancel twice.
     * Cancelled invitation cannot be checked in."). يُسجِّل الحالة فقط في
     * `_pge_invitation_status`؛ **لا حذف** للضيف من `_pge_invited_guests`
     * إطلاقاً (Append-Only بروح المشروع)، **لا تعديل** على أي صف RSVP.
     *
     * @return array{result:string, reason?:string}
     *   'cancelled'          — نجح الإلغاء.
     *   'already_cancelled'  — كانت مُلغاة بالفعل (لا يجوز إلغاء مرتين).
     *   'error'              — الدعوة غير موجودة.
     */
    public static function cancel($event_id, $phone, $reason = '')
    {
        $event_id = (int) $event_id;
        $normalized_phone = self::normalize_phone($phone);
        if ($event_id <= 0 || $normalized_phone === '') {
            return ['result' => 'error', 'reason' => 'invalid_phone'];
        }

        $guests_map = pge_event_guests_get_map($event_id);
        if (!isset($guests_map[$normalized_phone])) {
            return ['result' => 'error', 'reason' => 'not_found'];
        }

        $status_map = self::get_status_map($event_id);
        $entry = $status_map[$normalized_phone] ?? ['status' => self::STATUS_ACTIVE, 'invited_at' => '', 'qr_regenerated_at' => null];

        if (($entry['status'] ?? self::STATUS_ACTIVE) === self::STATUS_CANCELLED) {
            return ['result' => 'already_cancelled'];
        }

        $now = current_time('mysql', true);
        $entry['status'] = self::STATUS_CANCELLED;
        $entry['cancelled_at'] = $now;
        $entry['cancel_reason'] = is_scalar($reason) ? (string) $reason : '';
        $entry['updated_at'] = $now;
        $status_map[$normalized_phone] = $entry;
        self::save_status_map($event_id, $status_map);

        return ['result' => 'cancelled'];
    }

    /**
     * إعادة إرسال — لا قناة تسليم فعلية في نطاق هذه المرحلة (واتساب/SMS/
     * بريد ممنوعة صراحة). هذه الدالة تحقّق فقط أن الدعوة موجودة وغير مُلغاة،
     * وتُحدِّث updated_at — الاستدعاء الفعلي (تدقيق) من طبقة الخدمة.
     *
     * @return array{result:string, reason?:string}
     */
    public static function resend($event_id, $phone)
    {
        $event_id = (int) $event_id;
        $normalized_phone = self::normalize_phone($phone);
        if ($event_id <= 0 || $normalized_phone === '') {
            return ['result' => 'error', 'reason' => 'invalid_phone'];
        }

        $guests_map = pge_event_guests_get_map($event_id);
        if (!isset($guests_map[$normalized_phone])) {
            return ['result' => 'error', 'reason' => 'not_found'];
        }

        $status_map = self::get_status_map($event_id);
        $entry = $status_map[$normalized_phone] ?? ['status' => self::STATUS_ACTIVE, 'invited_at' => '', 'qr_regenerated_at' => null];
        if (($entry['status'] ?? self::STATUS_ACTIVE) === self::STATUS_CANCELLED) {
            return ['result' => 'error', 'reason' => 'cancelled'];
        }

        $entry['updated_at'] = current_time('mysql', true);
        $status_map[$normalized_phone] = $entry;
        self::save_status_map($event_id, $status_map);

        return ['result' => 'resent'];
    }

    /**
     * ========================================================================
     * RC1 Fix Pack 3B — "Legacy Guest Panel Retirement (Hard Delete Migration)"
     * ========================================================================
     * "Reuse the existing delete implementation exactly. Do NOT redesign
     * deletion behavior. Do NOT invent cascade rules." — هذه الدالة تُعيد
     * استخدام **نفس** الخطوات الثلاث الموجودة فعلياً في المعالج القديم
     * `wp_ajax_pge_event_guest_delete` (event-guests.php) حرفياً وبنفس
     * الترتيب: قراءة الخريطة → `unset()` → `pge_event_guests_remove_phone_
     * refs()` (التي تحذف فعلياً أي مرجع RSVP/حضور قديم في `_pge_rsvp_map`/
     * `_pge_checkins` — موجودة أصلاً، لم تُخترَع هنا) → `pge_event_guests_
     * save_map()`. **لا حذف من `wp_pge_event_rsvps` (الجدول الفعلي الحديث)
     * ولا من `pge_checkin_audit_log` إطلاقاً** — تماماً كما كان السلوك
     * القديم بالضبط (لم يكن يفعل ذلك من قبل، فلا يُضاف الآن — "If it does
     * not [cleanup], do not add new cleanup. Preserve behavior.").
     *
     * الإضافة الوحيدة غير الموجودة في المعالج القديم: تنظيف مفتاح post meta
     * `_pge_invitation_status[$phone]` — هذا المفتاح **من اختراع هذه
     * المرحلة (Phase 9) نفسها**، لم يكن موجوداً وقت كتابة المعالج القديم،
     * فتنظيفه هنا ليس "cascade rule" جديدة على بيانات RSVP/حضور قائمة، بل
     * تناسق داخلي لبيانات Repository نفسها (بالضبط كما تفعل `edit()` أعلاه
     * عند تغيّر الهاتف) — يمنع تسرّب حالة إدارية قديمة (مثلاً "مُلغاة") إلى
     * دعوة مستقبلية لو أُعيد إدخال نفس الهاتف لاحقاً.
     *
     * @return array{result:string, reason?:string}
     *   'deleted' — نجح الحذف الفعلي والنهائي.
     *   'error'   — الدعوة غير موجودة أصلاً ضمن هذه المناسبة.
     */
    public static function delete($event_id, $phone)
    {
        $event_id = (int) $event_id;
        $normalized_phone = self::normalize_phone($phone);
        if ($event_id <= 0 || $normalized_phone === '') {
            return ['result' => 'error', 'reason' => 'invalid_phone'];
        }

        $guests_map = pge_event_guests_get_map($event_id);
        if (!isset($guests_map[$normalized_phone])) {
            return ['result' => 'error', 'reason' => 'not_found'];
        }

        unset($guests_map[$normalized_phone]);
        if (function_exists('pge_event_guests_remove_phone_refs')) {
            pge_event_guests_remove_phone_refs($event_id, $normalized_phone);
        }
        pge_event_guests_save_map($event_id, $guests_map);

        $status_map = self::get_status_map($event_id);
        if (isset($status_map[$normalized_phone])) {
            unset($status_map[$normalized_phone]);
            self::save_status_map($event_id, $status_map);
        }

        return ['result' => 'deleted'];
    }

    /**
     * ============================================================================
     * RC1 Hard Delete Semantics Fix Pack — الحارس الجذري الوحيد لثلاثة موانع حرجة
     * ============================================================================
     * "Hard Delete Semantics Audit" (`docs/HARD-DELETE-SEMANTICS-AUDIT.md`) أثبت
     * تنفيذياً أن `wp_pge_event_rsvps` لا يُمسّ إطلاقاً عند `delete()` (قرار
     * مقصود، راجع تعليق الدالة أعلاه) — فيبقى أي صف RSVP قائم قبل الحذف "يتيماً"
     * وقابلاً للحلّ بالكامل عبر `PGE_Guest_Resolution_Service`، وأي QR صادر
     * لأجله صالحاً، وأي إعادة إنشاء لاحقة بنفس الهاتف تُخاطر بتوريث حالته عبر
     * `pge_save_rsvp_response()` (upsert بالهاتف فقط).
     *
     * الحل الجذري الموحَّد: **دعوة تُحدَّد بهاتفها + طابع `invited_at` الخاص
     * بدورة حياتها الحالية** (يُضبَط مرة واحدة فقط في `create()` أعلاه، ولا
     * يتغيَّر إلا بإعادة إنشاء الدعوة من جديد بعد حذف سابق). صف RSVP "ينتمي"
     * لدورة الحياة الحالية فقط إن كان `created_at` الخاص به **لاحقاً أو مساوياً**
     * لـ`invited_at` — لأن الرد لا يمكن أن يسبق الدعوة في أي تدفّق شرعي. صف
     * أقدم من `invited_at` الحالي (أو لا دعوة حالية إطلاقاً لهذا الهاتف — أي
     * محذوفة ولم تُعَد إنشاؤها) هو بالضرورة بقية من دورة حياة **سابقة** يجب أن
     * تُعامَل كأنها غير موجودة تماماً — بلا أي عمود/جدول/قيد جديد، فقط طابعا
     * وقت موجودان أصلاً (`_pge_invitation_status[phone]['invited_at']` هنا،
     * `wp_pge_event_rsvps.created_at` في الجدول القائم).
     *
     * **نقطة الاستدعاء المنخفضة المستوى** — لا تُستدعى مباشرة من طبقات الكتابة
     * (upsert) بعد الآن؛ طبقات الكتابة تستدعي `current_or_null()` أدناه (الغلاف
     * الموحَّد الوحيد لقرار "هل أُعامِل هذا الصف كموجود أم كأنه غير موجود؟").
     * تبقى `is_rsvp_row_current()` نفسها المُستدعى المباشر الوحيد من:
     *   - `PGE_Guest_Resolution_Service::resolve_by_rsvp_id()`/`resolve_by_phone()`
     *     (Blocker 1 + Blocker 2 — يمنع وصول أي دعوة محذوفة أو صف يتيم سابق
     *     لدورة حياة حالية إلى `PGE_Checkin_Recorder` عبر أي مسار حلّ، بما فيها
     *     `resolve_from_qr()` التي تمرّ عبر `resolve_by_rsvp_id()` أصلاً).
     *   - `current_or_null()` أدناه (Blocker 3 المُوحَّد — راجع توثيقها).
     *
     * توافق قديم صريح (كبقية هذا الملف): غياب `invited_at`/`created_at` (بيانات
     * قديمة جداً أو استدعاء دفاعي بلا سياق كافٍ) يُعامَل كـ"حالي" — لا حظر
     * افتراضي بلا بيانات كافية للمقارنة.
     *
     * @param int         $event_id
     * @param string      $phone           الهاتف كما وصل (يُطبَّع داخلياً).
     * @param string|null $rsvp_created_at طابع `created_at` لصف RSVP المطلوب فحصه.
     * @return bool true = الصف ينتمي لدورة حياة الدعوة الحالية لهذا الهاتف (أو
     *              لا بيانات كافية للمقارنة). false = دعوة محذوفة (لا سجل حالي
     *              إطلاقاً) أو صف من دورة حياة سابقة — يجب مُعامَلته كغير موجود.
     */
    public static function is_rsvp_row_current($event_id, $phone, $rsvp_created_at): bool
    {
        $event_id = (int) $event_id;
        $normalized_phone = self::normalize_phone($phone);
        if ($event_id <= 0 || $normalized_phone === '') {
            return true;
        }

        $invitation = self::get_invitation($event_id, $normalized_phone);
        if (!is_array($invitation)) {
            // لا دعوة حالية لهذا الهاتف إطلاقاً — محذوفة ولم تُعَد إنشاؤها.
            return false;
        }

        $invited_at = (string) ($invitation['invited_at'] ?? '');
        $created_at = is_scalar($rsvp_created_at) ? (string) $rsvp_created_at : '';
        if ($invited_at === '' || $created_at === '') {
            return true;
        }

        return $created_at >= $invited_at;
    }

    /**
     * Read-only lifecycle guard for RSVP writers.
     *
     * A current row is returned unchanged. A stale/orphaned row is treated as
     * absent and is never mutated here. The only lifecycle reset authority is
     * create(), after quota/validation/duplicate checks pass.
     *
     * @return object|array|null
     */
    public static function current_or_null($event_id, $phone, $existing_row)
    {
        if (!$existing_row) {
            return null;
        }

        $created_at = is_array($existing_row) ? ($existing_row['created_at'] ?? null) : ($existing_row->created_at ?? null);

        if (self::is_rsvp_row_current($event_id, $phone, $created_at)) {
            return $existing_row;
        }

        // Read-only fail-safe. Lifecycle reset is authoritative only inside
        // create(); a lookup or reply path must never start a new lifecycle.
        return null;
    }

    /**
     * RC1 Final Release Blocker: RSVP Write Path Unification — إغلاق تكميلي
     * لـBlocker 2 اكتُشِف أثناء اختبار "إعادة استخدام الهاتف بعد QR Rotation":
     * القيد الفريد الحقيقي على wp_pge_event_rsvps (UNIQUE KEY event_phone)
     * يفرض إعادة استخدام نفس rsvp_id الفعلي عند إعادة إنشاء دعوة بنفس الهاتف
     * (create() تُصفِّر الصف بدل إدراج صف جديد — لا خيار آخر ممكن فعلياً تحت
     * عقد Option A). بما أن qr_version الافتراضي كان ثابتاً
     * دوماً (`DEFAULT_QR_VERSION = 1`)، فإن أي QR قديم صادر بالإصدار الافتراضي
     * لدعوة محذوفة (لم تُدوَّر QR لها إطلاقاً) يتطابق تلقائياً مع الإصدار
     * الافتراضي لأي دعوة **جديدة** لاحقة بنفس الهاتف لم تُدوِّر QR لها هي
     * الأخرى بعد — تصادُم قيمة افتراضية ثابتة يُعيد إحياء QR قديم فعلياً.
     *
     * الإصلاح: القيمة الافتراضية (غياب qr_version مخزَّن) لم تعد ثابتة —
     * تُشتَق من `invited_at` لدورة الحياة الحالية (طابع وقت موجود أصلاً، لا
     * قيمة جديدة) عبر `strtotime()`، فتكون فريدة عملياً لكل دورة حياة دعوة
     * (كل `create()` يضبط `invited_at` جديداً دوماً)، ولا يمكن لدورتي حياة
     * مختلفتين لنفس الهاتف أن تتشاركا نفس القيمة الافتراضية أبداً. توافق قديم
     * صريح (نفس فلسفة بقية هذا الملف): غياب `invited_at` نفسه (بيانات دفاعية/
     * قديمة بلا سياق كافٍ) يُعامَل بالسلوك القديم تماماً (`DEFAULT_QR_VERSION`)
     * — لا تغيير على أي دعوة حالية مبنية عبر مسارات لا تضبط invited_at.
     *
     * قراءة فقط، لا كتابة عند القراءة. هذه هي "القيمة الحالية النشطة" التي
     * يجب أن يطابقها أي QR مُوقَّع كي يُقبَل (راجع class-pge-guest-resolution-
     * service.php::is_qr_version_current()). `regenerate_qr()` أدناه تستدعي
     * هذه الدالة نفسها لقراءة "الإصدار الحالي قبل التدوير" — لا نسخة موازية
     * من منطق الاشتقاق الافتراضي في أي مكان آخر.
     */
    public static function get_qr_version($event_id, $phone): int
    {
        $event_id = (int) $event_id;
        $normalized_phone = self::normalize_phone($phone);
        if ($event_id <= 0 || $normalized_phone === '') {
            return self::DEFAULT_QR_VERSION;
        }

        $status_map = self::get_status_map($event_id);
        $entry = $status_map[$normalized_phone] ?? [];

        if (isset($entry['qr_version'])) {
            $version = (int) $entry['qr_version'];
            return $version > 0 ? $version : self::DEFAULT_QR_VERSION;
        }

        $invited_at = (string) ($entry['invited_at'] ?? '');
        if ($invited_at !== '') {
            $ts = strtotime($invited_at);
            if ($ts !== false && $ts > 0) {
                return $ts;
            }
        }

        return self::DEFAULT_QR_VERSION;
    }

    /**
     * ========================================================================
     * Phase 9B QR Architecture Final Fix ("QR Regeneration")
     * ========================================================================
     * "QR is an access credential. QR is NOT invitation identity." — هذه
     * الدالة **لم تعد تلمس invite_code إطلاقاً** (تصحيح جذري عن التنفيذ
     * السابق الذي كان يستبدل invite_code خطأً، خالطاً بين "رمز البحث اليدوي"
     * و"بيان اعتماد الماسح"، وهو بالضبط ما تطلب هذه المرحلة إزالته). بدلاً
     * من ذلك: تُدوِّر (rotate) بدائيّاً رقمياً بسيطاً `qr_version` مُخزَّناً في
     * نفس مفتاح post meta الإداري الحالي `_pge_invitation_status` — لا جدول
     * جديد، لا تعديل على invite_code/RSVP/الحضور/هوية الدعوة إطلاقاً.
     *
     * بعد هذا الاستدعاء: أي QR مُوقَّع سابق (يحمل الإصدار القديم) يفشل فوراً
     * عبر resolve_from_qr() الحقيقية (qr_superseded) — راجع is_qr_version_
     * current() في class-pge-guest-resolution-service.php. توليد صورة QR
     * جديدة فعلية (لإرسالها لاحقاً) مسؤولية طبقة أعلى (منتِج QR)، لا هذه
     * الدالة — هذه الدالة تُدوِّر الحالة الإدارية فقط، بلا أي أثر جانبي آخر.
     *
     * @return array{result:string, qr_version?:int, reason?:string}
     *   'regenerated' — نجح، يتضمن 'qr_version' الجديد (رقمي، ليس سرّاً).
     *   'error'       — الدعوة غير موجودة، أو مُلغاة (لا معنى لتدوير رمز دعوة مُلغاة).
     */
    public static function regenerate_qr($event_id, $phone)
    {
        $event_id = (int) $event_id;
        $normalized_phone = self::normalize_phone($phone);
        if ($event_id <= 0 || $normalized_phone === '') {
            return ['result' => 'error', 'reason' => 'invalid_phone'];
        }

        $guests_map = pge_event_guests_get_map($event_id);
        if (!isset($guests_map[$normalized_phone])) {
            return ['result' => 'error', 'reason' => 'not_found'];
        }

        $status_map = self::get_status_map($event_id);
        $entry = $status_map[$normalized_phone] ?? ['status' => self::STATUS_ACTIVE, 'invited_at' => '', 'cancel_reason' => ''];
        if (($entry['status'] ?? self::STATUS_ACTIVE) === self::STATUS_CANCELLED) {
            return ['result' => 'error', 'reason' => 'cancelled'];
        }

        // يستدعي get_qr_version() نفسها (لا نسخة موازية من اشتقاق القيمة
        // الافتراضية) — يضمن اتساقاً تاماً بين "الإصدار الحالي المقروء" هنا
        // و"الإصدار الحالي" الذي يتحقق منه resolve_from_qr() لاحقاً.
        $current_version = self::get_qr_version($event_id, $normalized_phone);
        $new_version = $current_version + 1;

        $now = current_time('mysql', true);
        $entry['qr_version'] = $new_version;
        $entry['qr_regenerated_at'] = $now;
        $entry['updated_at'] = $now;
        $status_map[$normalized_phone] = $entry;
        self::save_status_map($event_id, $status_map);

        return ['result' => 'regenerated', 'qr_version' => $new_version];
    }
}
