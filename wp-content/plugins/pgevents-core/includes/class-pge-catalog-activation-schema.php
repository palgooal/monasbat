<?php
if (!defined('ABSPATH')) exit;

/** Durable schema foundation for Catalog Activation History. */
final class PGE_Catalog_Activation_Schema
{
    const SCHEMA_VERSION = '1.1.0';
    const VERSION_OPTION = 'pge_catalog_activation_schema_version';

    public static function activations_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_catalog_activations';
    }

    public static function events_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_catalog_activation_events';
    }

    public static function origins_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_catalog_activation_origins';
    }

    public static function revocations_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_catalog_order_revocations';
    }

    /** @return string[] */
    public static function get_schema_sql()
    {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();
        $activations = self::activations_table();
        $events = self::events_table();
        $origins = self::origins_table();
        $revocations = self::revocations_table();

        return [
            "CREATE TABLE $activations (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                activation_id VARCHAR(64) NOT NULL,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                plan_id BIGINT(20) UNSIGNED NOT NULL,
                tier_id BIGINT(20) UNSIGNED NOT NULL,
                plan_key VARCHAR(100) NOT NULL,
                tier_key VARCHAR(100) NOT NULL,
                activation_source VARCHAR(20) NOT NULL,
                idempotency_key VARCHAR(191) NOT NULL,
                external_order_id VARCHAR(191) NULL,
                projection_snapshot LONGTEXT NOT NULL,
                lifecycle_state VARCHAR(32) NOT NULL,
                revision BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
                projection_attempts BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                last_error_code VARCHAR(100) NULL,
                next_reconcile_at DATETIME NULL,
                activated_at DATETIME NULL,
                ended_at DATETIME NULL,
                revoked_at DATETIME NULL,
                last_reconciled_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY activation_id (activation_id),
                UNIQUE KEY idempotency_key (idempotency_key),
                KEY user_lifecycle (user_id, lifecycle_state),
                KEY plus_lifecycle (plan_key, lifecycle_state),
                KEY due_reconciliation (lifecycle_state, next_reconcile_at)
            ) $collate;",
            "CREATE TABLE $events (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                catalog_activation_id BIGINT(20) UNSIGNED NOT NULL,
                event_id BIGINT(20) UNSIGNED NOT NULL,
                bound_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_id (event_id),
                KEY catalog_activation_id (catalog_activation_id)
            ) $collate;",
            "CREATE TABLE $origins (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                catalog_activation_id BIGINT(20) UNSIGNED NOT NULL,
                provider VARCHAR(32) NOT NULL,
                merchant_id BIGINT(20) UNSIGNED NOT NULL,
                external_customer_id VARCHAR(191) NOT NULL,
                external_order_id VARCHAR(191) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY provider_order (provider, merchant_id, external_order_id),
                UNIQUE KEY activation_provider (catalog_activation_id, provider),
                KEY provider_customer (provider, merchant_id, external_customer_id)
            ) $collate;",
            "CREATE TABLE $revocations (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                provider VARCHAR(32) NOT NULL,
                merchant_id BIGINT(20) UNSIGNED NOT NULL,
                external_order_id VARCHAR(191) NOT NULL,
                reason VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY provider_order (provider, merchant_id, external_order_id)
            ) $collate;",
        ];
    }

    public static function maybe_upgrade()
    {
        $stored = (string) get_option(self::VERSION_OPTION, '');
        if ($stored === self::SCHEMA_VERSION && self::postconditions_hold()) {
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (self::get_schema_sql() as $sql) {
            dbDelta($sql);
        }

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
        return self::table_contract_holds(self::activations_table(), [
            'id' => ['bigint unsigned', false, null, true],
            'activation_id' => ['varchar(64)', false, null, false],
            'user_id' => ['bigint unsigned', false, null, false],
            'plan_id' => ['bigint unsigned', false, null, false],
            'tier_id' => ['bigint unsigned', false, null, false],
            'plan_key' => ['varchar(100)', false, null, false],
            'tier_key' => ['varchar(100)', false, null, false],
            'activation_source' => ['varchar(20)', false, null, false],
            'idempotency_key' => ['varchar(191)', false, null, false],
            'external_order_id' => ['varchar(191)', true, null, false],
            'projection_snapshot' => ['longtext', false, null, false],
            'lifecycle_state' => ['varchar(32)', false, null, false],
            'revision' => ['bigint unsigned', false, '1', false],
            'projection_attempts' => ['bigint unsigned', false, '0', false],
            'last_error_code' => ['varchar(100)', true, null, false],
            'next_reconcile_at' => ['datetime', true, null, false],
            'activated_at' => ['datetime', true, null, false],
            'ended_at' => ['datetime', true, null, false],
            'revoked_at' => ['datetime', true, null, false],
            'last_reconciled_at' => ['datetime', true, null, false],
            'created_at' => ['datetime', false, null, false],
            'updated_at' => ['datetime', false, null, false],
        ], [
            'PRIMARY' => [false, ['id']],
            'activation_id' => [false, ['activation_id']],
            'idempotency_key' => [false, ['idempotency_key']],
            'user_lifecycle' => [true, ['user_id', 'lifecycle_state']],
            'plus_lifecycle' => [true, ['plan_key', 'lifecycle_state']],
            'due_reconciliation' => [true, ['lifecycle_state', 'next_reconcile_at']],
        ]) && self::table_contract_holds(self::events_table(), [
            'id' => ['bigint unsigned', false, null, true],
            'catalog_activation_id' => ['bigint unsigned', false, null, false],
            'event_id' => ['bigint unsigned', false, null, false],
            'bound_at' => ['datetime', false, null, false],
            'created_at' => ['datetime', false, null, false],
            'updated_at' => ['datetime', false, null, false],
        ], [
            'PRIMARY' => [false, ['id']],
            'event_id' => [false, ['event_id']],
            'catalog_activation_id' => [true, ['catalog_activation_id']],
        ]) && self::table_contract_holds(self::origins_table(), [
            'id' => ['bigint unsigned', false, null, true],
            'catalog_activation_id' => ['bigint unsigned', false, null, false],
            'provider' => ['varchar(32)', false, null, false],
            'merchant_id' => ['bigint unsigned', false, null, false],
            'external_customer_id' => ['varchar(191)', false, null, false],
            'external_order_id' => ['varchar(191)', false, null, false],
            'created_at' => ['datetime', false, null, false],
        ], [
            'PRIMARY' => [false, ['id']],
            'provider_order' => [false, ['provider', 'merchant_id', 'external_order_id']],
            'activation_provider' => [false, ['catalog_activation_id', 'provider']],
            'provider_customer' => [true, ['provider', 'merchant_id', 'external_customer_id']],
        ]) && self::table_contract_holds(self::revocations_table(), [
            'id' => ['bigint unsigned', false, null, true],
            'provider' => ['varchar(32)', false, null, false],
            'merchant_id' => ['bigint unsigned', false, null, false],
            'external_order_id' => ['varchar(191)', false, null, false],
            'reason' => ['varchar(64)', false, null, false],
            'created_at' => ['datetime', false, null, false],
        ], [
            'PRIMARY' => [false, ['id']],
            'provider_order' => [false, ['provider', 'merchant_id', 'external_order_id']],
        ]);
    }

    private static function table_contract_holds($table, array $required_columns, array $required_indexes)
    {
        global $wpdb;
        $columns = $wpdb->get_results('SHOW COLUMNS FROM ' . $table, ARRAY_A);
        if (!is_array($columns) || !$columns) return false;
        $found_columns = [];
        foreach ($columns as $column) {
            $field = (string) ($column['Field'] ?? '');
            if ($field !== '') $found_columns[$field] = $column;
        }
        foreach ($required_columns as $field => [$type, $nullable, $default, $auto_increment]) {
            if (!isset($found_columns[$field])) return false;
            $actual = $found_columns[$field];
            if (self::normalize_column_type($actual['Type'] ?? '') !== self::normalize_column_type($type)) return false;
            if (((string) ($actual['Null'] ?? '') === 'YES') !== $nullable) return false;
            if (!self::defaults_match($actual['Default'] ?? null, $default)) return false;
            $has_auto_increment = stripos((string) ($actual['Extra'] ?? ''), 'auto_increment') !== false;
            if ($has_auto_increment !== $auto_increment) return false;
        }

        $indexes = $wpdb->get_results('SHOW INDEX FROM ' . $table, ARRAY_A);
        if (!is_array($indexes)) return false;
        $found = [];
        foreach ($indexes as $index) {
            $name = (string) ($index['Key_name'] ?? '');
            $sequence = (int) ($index['Seq_in_index'] ?? 0);
            if ($name === '' || $sequence < 1) continue;
            $found[$name]['columns'][$sequence] = (string) ($index['Column_name'] ?? '');
            $found[$name]['non_unique'] = (int) ($index['Non_unique'] ?? 1);
        }
        foreach ($required_indexes as $name => [$non_unique, $expected_columns]) {
            if (!isset($found[$name])) return false;
            ksort($found[$name]['columns']);
            if (array_values($found[$name]['columns']) !== $expected_columns) return false;
            if ((bool) $found[$name]['non_unique'] !== $non_unique) return false;
        }
        return true;
    }

    private static function normalize_column_type($type)
    {
        $type = strtolower(trim((string) $type));
        $type = preg_replace('/\s+/', ' ', $type);
        // MySQL 8 omits integer display widths; they are not storage semantics.
        return preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type);
    }

    private static function defaults_match($actual, $expected)
    {
        if ($actual === null || $expected === null) return $actual === null && $expected === null;
        return (string) $actual === (string) $expected;
    }
}

register_activation_hook(PGE_PATH . 'pgevents-core.php', ['PGE_Catalog_Activation_Schema', 'maybe_upgrade']);
add_action('plugins_loaded', ['PGE_Catalog_Activation_Schema', 'maybe_upgrade']);
