<?php
if (!defined('ABSPATH')) exit;

/** Persistence-only event bindings for Catalog activations. */
final class PGE_Catalog_Event_Binding_Repository
{
    private static function table() { return PGE_Catalog_Activation_Schema::events_table(); }

    public static function create($catalog_activation_id, $event_id, $bound_at = null)
    {
        $catalog_activation_id = self::positive_integer($catalog_activation_id);
        $event_id = self::positive_integer($event_id);
        if (!$catalog_activation_id || !$event_id) return ['result' => 'error', 'reason' => 'invalid_identity'];
        $now = current_time('mysql', true);
        global $wpdb;
        $inserted = $wpdb->insert(self::table(), [
            'catalog_activation_id' => $catalog_activation_id, 'event_id' => $event_id,
            'bound_at' => is_string($bound_at) && trim($bound_at) !== '' ? trim($bound_at) : $now,
            'created_at' => $now, 'updated_at' => $now,
        ], ['%d', '%d', '%s', '%s', '%s']);
        if ($inserted) return ['result' => 'created', 'id' => (int) $wpdb->insert_id];
        $insert_error = (string) ($wpdb->last_error ?? '');
        $existing = self::find_by_event_id($event_id);
        if (is_array($existing)) return ['result' => 'conflict', 'reason' => 'event_id', 'record' => $existing];
        return ['result' => 'db_error', 'reason' => $insert_error === '' ? 'insert_failed' : 'insert_error'];
    }

    public static function find_by_event_id($event_id)
    {
        global $wpdb;
        $event_id = self::positive_integer($event_id);
        return $event_id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE event_id = %d LIMIT 1', $event_id), ARRAY_A) : null;
    }

    public static function find_by_activation_id($catalog_activation_id)
    {
        global $wpdb;
        $catalog_activation_id = self::positive_integer($catalog_activation_id);
        if (!$catalog_activation_id) return [];
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE catalog_activation_id = %d ORDER BY id ASC', $catalog_activation_id), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private static function positive_integer($value)
    {
        if (!is_scalar($value) || is_bool($value)) return 0;
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? 0 : (int) $value;
    }
}
