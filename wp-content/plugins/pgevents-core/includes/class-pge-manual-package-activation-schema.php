<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Manual Package Activation Schema — "التفعيل اليدوي للباقات من لوحة
 * الإدارة" RFC
 * ============================================================================
 * يوفِّر بنية قاعدة البيانات لجدول تدقيق مستقل تماماً عن كل جداول التدقيق
 * الأخرى (pge_supervisor_mgmt_audit_log، pge_invitation_mgmt_audit_log،
 * pge_checkin_audit_log): {$wpdb->prefix}pge_manual_package_activation_audit
 * (append-only، راجع class-pge-manual-package-activation-audit.php للاستهلاك
 * الوحيد المسموح به).
 *
 * هذا الجدول **لا يخزّن أي بيانات حساسة** — فقط: مَن نفَّذ الإجراء (actor)،
 * على مَن (target_user_id)، أي باقة (package_source + معرّفاتها)، وسبب نصي
 * حرّ اختاره الأدمن (مثال: "تعويض"، "دعم فني"). لا بريد، لا جوال، لا اسم.
 *
 * هذا الجدول **لا علاقة له إطلاقاً** بمنطق التفعيل الفعلي (Mon_Events_Users::
 * activate_catalog_tier()/activate_user_package()) — هو طبقة تسجيل مستقلة
 * تُستدعى بعد نجاح الاستدعاء الحقيقي للـService، لا بديل عنه.
 *
 * نمط الترقية يطابق حرفياً PGE_Supervisor_Management_Schema (Phase 8) —
 * رقم إصدار مخزَّن في wp_options، dbDelta() لإنشاء الجدول الجديد، تحقّق فعلي
 * عبر SHOW COLUMNS قبل اعتماد نجاح الترقية (لا افتراض نجاح dbDelta() بشكل أعمى).
 */
class PGE_Manual_Package_Activation_Schema
{
    const SCHEMA_VERSION = '1.0.0';
    const VERSION_OPTION = 'pge_manual_pkg_activation_schema_version';

    private static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_manual_package_activation_audit';
    }

    /**
     * نقطة الدخول الوحيدة — آمنة للاستدعاء من register_activation_hook ومن
     * plugins_loaded على حد سواء (نفس فلسفة بقية ملفات Schema في المشروع).
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
            target_user_id BIGINT(20) UNSIGNED NOT NULL,
            actor_user_id BIGINT(20) UNSIGNED NOT NULL,
            package_source VARCHAR(20) NOT NULL,
            package_identifier VARCHAR(191) NOT NULL DEFAULT '',
            had_active_package TINYINT(1) NOT NULL DEFAULT 0,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY target_user_id (target_user_id),
            KEY actor_user_id (actor_user_id)
        ) $charset_collate;";

        dbDelta($sql);

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required = ['id', 'target_user_id', 'actor_user_id', 'package_source', 'package_identifier', 'had_active_package', 'reason', 'created_at'];
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

register_activation_hook(PGE_PATH . 'pgevents-core.php', ['PGE_Manual_Package_Activation_Schema', 'maybe_upgrade']);
add_action('plugins_loaded', ['PGE_Manual_Package_Activation_Schema', 'maybe_upgrade']);
