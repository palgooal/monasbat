<?php
if (!defined('ABSPATH')) exit;

/**
 * Return the single canonical RSVP row for an event and normalized phone.
 *
 * Two rows are fetched deliberately so a corrupt duplicate identity can never
 * degrade into a silent first-row selection. This helper is read-only and does
 * not expose duplicate candidates.
 *
 * @return array{status:'found'|'not_found'|'integrity_error',row:?object,reason?:string}
 */
if (!function_exists('pge_rsvp_find_canonical_by_phone')) {
    function pge_rsvp_find_canonical_by_phone($event_id, $guest_phone_raw): array
    {
        global $wpdb;

        $event_id = (int) $event_id;
        $phone = function_exists('pge_norm_phone')
            ? pge_norm_phone($guest_phone_raw)
            : preg_replace('/\D+/', '', (string) $guest_phone_raw);

        if ($event_id <= 0 || $phone === '') {
            return ['status' => 'not_found', 'row' => null];
        }

        $table = $wpdb->prefix . 'pge_event_rsvps';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_id = %d AND guest_phone = %s ORDER BY id ASC LIMIT 2",
            $event_id,
            $phone
        ));

        if (!is_array($rows)) {
            error_log("PGE RSVP lookup error: event_id={$event_id} reason=rsvp_lookup_failed");
            return ['status' => 'integrity_error', 'row' => null, 'reason' => 'rsvp_lookup_failed'];
        }

        $count = count($rows);
        if ($count === 0) {
            return ['status' => 'not_found', 'row' => null];
        }
        if ($count === 1) {
            return ['status' => 'found', 'row' => $rows[0]];
        }

        error_log("PGE RSVP integrity error: event_id={$event_id} reason=duplicate_rsvp_identity count={$count}");
        return ['status' => 'integrity_error', 'row' => null, 'reason' => 'duplicate_rsvp_identity'];
    }
}
