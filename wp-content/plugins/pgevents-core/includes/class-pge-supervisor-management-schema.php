<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Supervisor Management Schema — Entry Check-in Supervisors، Phase 8
 * ============================================================================
 * "Host Supervisor Management" RFC، Requirement Audit — يوفِّر بنية قاعدة
 * البيانات لجدول تدقيق إدارة المشرفين فقط: {$wpdb->prefix}pge_supervisor_
 * mgmt_audit_log (append-only، راجع class-pge-supervisor-management-audit.php
 * للاستهلاك الوحيد المسموح به). لا تعديل هنا على أي جدول آخر (لا mon_event_
 * supervisors، لا mon_supervisor_sessions، لا pge_checkin_audit_log).
 *
 * نمط الترقية يطابق حرفياً PGE_Checkin_Schema (Phase 4) — رقم إصدار مخزَّن في
 * wp_options، dbDelta() لإنشاء الجدول الجديد، تحقّق فعلي عبر SHOW COLUMNS قبل
 * اعتماد نجاح الترقية (لا افتراض نجاح dbDelta() بشكل أعمى).
 */
class PGE_Supervisor_Management_Schema
{
    const SCHEMA_VERSION = '1.0.0';
    const VERSION_OPTION = 'pge_supervisor_mgmt_schema_version';

    private static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_supervisor_mgmt_audit_log';
    }

    /**
     * نقطة الدخول الوحيدة — آمنة للاستدعاء من register_activation_hook ومن
     * plugins_loaded على حد سواء (نفس فلسفة Mon_Catalog_Schema/PGE_Checkin_Schema).
     */
    public static function maybe_upgrade()
    {
        $stored_version = (string) get_option(self::VERSION_OPTION, '');

        if ($stored_version === self::SCHEMA_VERSION) {
            return;
        }

        if (self::ensure_audit_table()) {
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
        }
        // فشل جزئي: لا يُحدَّث الإصدار المخزَّن، فيُعاد المحاولة كاملةً في
        // الطلب التالي (نفس فلسفة الفشل الجزئي المُتَّبعة في بقية ملفات المشروع).
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
            assignment_id BIGINT(20) UNSIGNED NOT NULL,
            actor_user_id BIGINT(20) UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY assignment_id (assignment_id)
        ) $charset_collate;";

        dbDelta($sql);

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required = ['id', 'event_id', 'assignment_id', 'actor_user_id', 'action', 'reason', 'created_at'];
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

register_activation_hook(PGE_PATH . 'pgevents-core.php', ['PGE_Supervisor_Management_Schema', 'maybe_upgrade']);
add_action('plugins_loaded', ['PGE_Supervisor_Management_Schema', 'maybe_upgrade']);
