<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Management Schema — Entry Check-in Supervisors، Phase 9
 * ============================================================================
 * "Host Invitation Management" RFC، Requirement Audit — يوفِّر بنية قاعدة
 * البيانات لجدول تدقيق إدارة الدعوات فقط: {$wpdb->prefix}pge_invitation_
 * mgmt_audit_log (append-only). لا تعديل هنا على أي جدول آخر — لا
 * wp_pge_event_rsvps (ممنوع لمسه بدون مراجعة صريحة وفق CLAUDE.md، ولم يُحتَج
 * له هنا أصلاً)، لا mon_event_supervisors، لا mon_supervisor_sessions.
 *
 * حالة "الدعوة" (active/cancelled) لا تُخزَّن في جدول جديد أيضاً — تُخزَّن في
 * post meta جديد مستقل تماماً (`_pge_invitation_status`، مفتاح منفصل عن
 * `_pge_invited_guests` الحالي) لتفادي أي تعارض كتابة مع لوحة إدارة المدعوين
 * القديمة (event-guests.php) التي تُعيد كتابة `_pge_invited_guests` بالكامل
 * عند كل حفظ (pge_event_guests_save_map())، ولا تعرف بوجود أي حقل حالة دعوة
 * إضافي — راجع تعليق class-pge-invitation-repository.php للتفصيل الكامل.
 *
 * نمط الترقية يطابق حرفياً PGE_Supervisor_Management_Schema (Phase 8)/
 * PGE_Checkin_Schema (Phase 4) — رقم إصدار مخزَّن في wp_options، dbDelta()
 * لإنشاء الجدول الجديد، تحقّق فعلي عبر SHOW COLUMNS قبل اعتماد نجاح الترقية.
 */
class PGE_Invitation_Management_Schema
{
    const SCHEMA_VERSION = '1.0.0';
    const VERSION_OPTION = 'pge_invitation_mgmt_schema_version';

    private static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_invitation_mgmt_audit_log';
    }

    public static function maybe_upgrade()
    {
        $stored_version = (string) get_option(self::VERSION_OPTION, '');

        if ($stored_version === self::SCHEMA_VERSION) {
            return;
        }

        if (self::ensure_audit_table()) {
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
        }
    }

    private static function ensure_audit_table(): bool
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            guest_phone VARCHAR(32) NOT NULL,
            action VARCHAR(20) NOT NULL,
            actor_user_id BIGINT(20) UNSIGNED NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY event_phone (event_id, guest_phone)
        ) $charset_collate;";

        dbDelta($sql);

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required = ['id', 'event_id', 'guest_phone', 'action', 'actor_user_id', 'reason', 'created_at'];
        $found = [];
        foreach ($columns as $column) {
            $found[] = (string) ($column['Field'] ?? '');
        }

        foreach ($required as $r) {
            if (!in_array($r, $found, true)) {
                return false;
            }
        }

        return true;
    }
}

register_activation_hook(PGE_PATH . 'pgevents-core.php', ['PGE_Invitation_Management_Schema', 'maybe_upgrade']);
add_action('plugins_loaded', ['PGE_Invitation_Management_Schema', 'maybe_upgrade']);
