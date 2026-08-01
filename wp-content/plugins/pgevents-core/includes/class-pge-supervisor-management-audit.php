<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Management Audit — Entry Check-in Supervisors، Phase 8
 * ============================================================================
 * "Host Supervisor Management" RFC، Requirement Audit: "Record: Created,
 * Edited, Invitation resent, Revoked. Include Actor, Timestamp, Reason.
 * Append-only. Never overwrite."
 *
 * جدول مستقل تماماً عن {$wpdb->prefix}pge_checkin_audit_log (Phase 4 —
 * class-pge-checkin-schema.php): ذاك يُسجِّل عمليات تسجيل حضور فعلية عند
 * البوابة، هذا يُسجِّل عمليات إدارة "دورة حياة" إسناد المشرف من طرف المضيف
 * (إنشاء/تعديل/إعادة إرسال/إلغاء) — مفهومان مختلفان تماماً، لا داعي لخلطهما
 * في جدول واحد. هذا الملف هو الوسيط الوحيد للكتابة/القراءة على
 * {$wpdb->prefix}pge_supervisor_mgmt_audit_log.
 *
 * Append-Only بحت: لا UPDATE ولا DELETE على أي صف من أي كود في المشروع
 * إطلاقاً — record() تُنفِّذ INSERT فقط.
 */
class PGE_Supervisor_Management_Audit
{
    private static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_supervisor_mgmt_audit_log';
    }

    /**
     * تسجيل حدث تدقيق واحد — INSERT فقط، بلا أي تعديل على أي صف قائم.
     *
     * @param int    $event_id
     * @param int    $assignment_id
     * @param int    $actor_user_id المستخدم الذي نفَّذ الإجراء (المضيف الحالي).
     * @param string $action        'created'|'edited'|'invitation_resent'|'revoked'
     * @param string $reason        سبب اختياري (يُستخدَم فعلياً عند الإلغاء).
     * @return bool
     */
    public static function record($event_id, $assignment_id, $actor_user_id, $action, $reason = ''): bool
    {
        $event_id = (int) $event_id;
        $assignment_id = (int) $assignment_id;
        $actor_user_id = (int) $actor_user_id;
        $action = is_scalar($action) ? (string) $action : '';
        $reason = is_scalar($reason) ? (string) $reason : '';

        if ($event_id <= 0 || $assignment_id <= 0 || $action === '') {
            return false;
        }

        global $wpdb;

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'event_id'       => $event_id,
                'assignment_id'  => $assignment_id,
                'actor_user_id'  => $actor_user_id,
                'action'         => $action,
                'reason'         => $reason,
                'created_at'     => current_time('mysql', true),
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s']
        );

        return (bool) $inserted;
    }

    /**
     * قراءة سجل التدقيق الكامل لإسناد واحد — قراءة فقط، مُرتَّبة زمنياً
     * (الأقدم أولاً)، بلا أي تعديل.
     *
     * @return array<int,array>
     */
    public static function list_for_assignment($assignment_id): array
    {
        $assignment_id = (int) $assignment_id;
        if ($assignment_id <= 0) {
            return [];
        }

        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE assignment_id = %d ORDER BY id ASC", $assignment_id),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }
}
