<?php
if (!defined('ABSPATH')) exit;

/** Database lifecycle for durable Salla customer-group desired state. */
final class PGE_Salla_Sync_Schema
{
    const SCHEMA_VERSION = '1.0.0';
    const VERSION_OPTION = 'pge_salla_sync_schema_version';

    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_salla_membership_sync';
    }

    public static function maybe_upgrade()
    {
        $stored = (string) get_option(self::VERSION_OPTION, '');
        if ($stored === self::SCHEMA_VERSION && self::postconditions_hold()) {
            return true;
        }

        global $wpdb;
        $table = self::table_name();
        $collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            merchant_id BIGINT(20) UNSIGNED NOT NULL,
            salla_customer_id BIGINT(20) UNSIGNED NOT NULL,
            group_id BIGINT(20) UNSIGNED NOT NULL,
            desired_state VARCHAR(20) NOT NULL,
            desired_revision BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempt_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            attempt_token VARCHAR(64) NULL,
            attempt_revision BIGINT(20) UNSIGNED NULL,
            attempt_started_at DATETIME NULL,
            next_attempt_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            last_success_at DATETIME NULL,
            last_error_code VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY membership_identity (merchant_id, salla_customer_id, group_id),
            KEY due_work (status, next_attempt_at),
            KEY processing_lease (status, attempt_started_at)
        ) $collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        if (!self::postconditions_hold()) {
            return false;
        }

        if ($stored !== self::SCHEMA_VERSION) {
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
        }
        return true;
    }

    private static function postconditions_hold()
    {
        global $wpdb;
        $columns = $wpdb->get_results('SHOW COLUMNS FROM ' . self::table_name(), ARRAY_A);
        if (!is_array($columns) || !$columns) {
            return false;
        }

        $found = [];
        foreach ($columns as $column) {
            $found[] = (string) ($column['Field'] ?? '');
        }
        $required = [
            'id', 'merchant_id', 'salla_customer_id', 'group_id', 'desired_state',
            'desired_revision', 'status', 'attempt_count', 'attempt_token',
            'attempt_revision', 'attempt_started_at', 'next_attempt_at',
            'last_attempt_at', 'last_success_at', 'last_error_code',
            'created_at', 'updated_at',
        ];
        if (array_diff($required, $found)) {
            return false;
        }

        $indexes = $wpdb->get_results('SHOW INDEX FROM ' . self::table_name(), ARRAY_A);
        if (!is_array($indexes)) {
            return false;
        }
        $by_name = [];
        $non_unique = [];
        foreach ($indexes as $index) {
            $name = (string) ($index['Key_name'] ?? '');
            $sequence = (int) ($index['Seq_in_index'] ?? 0);
            if ($name !== '' && $sequence > 0) {
                $by_name[$name][$sequence] = (string) ($index['Column_name'] ?? '');
                $non_unique[$name] = (int) ($index['Non_unique'] ?? 1);
            }
        }
        foreach ($by_name as &$parts) {
            ksort($parts);
            $parts = array_values($parts);
        }
        unset($parts);

        return ($by_name['membership_identity'] ?? []) === ['merchant_id', 'salla_customer_id', 'group_id']
            && ($non_unique['membership_identity'] ?? 1) === 0
            && ($by_name['due_work'] ?? []) === ['status', 'next_attempt_at']
            && ($by_name['processing_lease'] ?? []) === ['status', 'attempt_started_at'];
    }
}

register_activation_hook(PGE_PATH . 'pgevents-core.php', ['PGE_Salla_Sync_Schema', 'maybe_upgrade']);
add_action('plugins_loaded', ['PGE_Salla_Sync_Schema', 'maybe_upgrade']);
