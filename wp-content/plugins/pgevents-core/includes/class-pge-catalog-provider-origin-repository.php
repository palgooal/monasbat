<?php
if (!defined('ABSPATH')) exit;

/** Durable provider-order identity for Catalog activations. */
final class PGE_Catalog_Provider_Origin_Repository
{
    private static function table() { return PGE_Catalog_Activation_Schema::origins_table(); }

    public static function create($catalog_activation_id, $provider, $merchant_id, $external_customer_id, $external_order_id)
    {
        $catalog_activation_id = self::positive_integer($catalog_activation_id);
        $merchant_id = self::positive_integer($merchant_id);
        $provider = self::required_string($provider);
        $external_customer_id = self::required_string($external_customer_id);
        $external_order_id = self::required_string($external_order_id);
        if (!$catalog_activation_id || !$merchant_id || $provider === '' || $external_customer_id === '' || $external_order_id === '') {
            return ['result' => 'error', 'reason' => 'invalid_identity'];
        }
        global $wpdb;
        $inserted = $wpdb->insert(self::table(), [
            'catalog_activation_id' => $catalog_activation_id, 'provider' => $provider,
            'merchant_id' => $merchant_id, 'external_customer_id' => $external_customer_id,
            'external_order_id' => $external_order_id, 'created_at' => current_time('mysql', true),
        ], ['%d', '%s', '%d', '%s', '%s', '%s']);
        if ($inserted) return ['result' => 'created', 'id' => (int) $wpdb->insert_id];
        $insert_error = (string) ($wpdb->last_error ?? '');
        $by_order = self::find_by_order($provider, $merchant_id, $external_order_id);
        $by_activation = self::find_by_activation_and_provider($catalog_activation_id, $provider);
        if (is_array($by_order)) return ['result' => 'conflict', 'reason' => 'provider_order', 'record' => $by_order];
        if (is_array($by_activation)) return ['result' => 'conflict', 'reason' => 'activation_provider', 'record' => $by_activation];
        return ['result' => 'db_error', 'reason' => $insert_error === '' ? 'insert_failed' : 'insert_error'];
    }

    public static function find_by_order($provider, $merchant_id, $external_order_id)
    {
        $provider = self::required_string($provider);
        $merchant_id = self::positive_integer($merchant_id);
        $external_order_id = self::required_string($external_order_id);
        if ($provider === '' || !$merchant_id || $external_order_id === '') return null;
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE provider = %s AND merchant_id = %d AND external_order_id = %s LIMIT 1',
            $provider, $merchant_id, $external_order_id
        ), ARRAY_A);
    }

    public static function find_by_activation_and_provider($catalog_activation_id, $provider)
    {
        $catalog_activation_id = self::positive_integer($catalog_activation_id);
        $provider = self::required_string($provider);
        if (!$catalog_activation_id || $provider === '') return null;
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE catalog_activation_id = %d AND provider = %s LIMIT 1',
            $catalog_activation_id, $provider
        ), ARRAY_A);
    }

    private static function required_string($value)
    {
        return is_scalar($value) && !is_bool($value) ? trim((string) $value) : '';
    }

    private static function positive_integer($value)
    {
        if (!is_scalar($value) || is_bool($value)) return 0;
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? 0 : (int) $value;
    }
}
