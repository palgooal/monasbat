<?php
if (!defined('ABSPATH')) exit;

/** Phase 4: deterministic event-end scheduling and durable reconciliation. */
final class PGE_Catalog_Event_Lifecycle_Service
{
    const CRON_HOOK = 'pge_catalog_event_lifecycle_reconcile';
    const CRON_SCHEDULE = 'pge_every_five_minutes';
    const BATCH_SIZE = 50;
    const RETRY_SECONDS = 300;
    const PLUS_PLAN_KEY = 'halwa_plus';

    public static function register()
    {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action('init', [__CLASS__, 'ensure_scheduled']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_due']);
    }

    public static function cron_schedules($schedules)
    {
        $schedules[self::CRON_SCHEDULE] = ['interval' => self::RETRY_SECONDS, 'display' => 'Every five minutes'];
        return $schedules;
    }

    public static function ensure_scheduled()
    {
        if (wp_next_scheduled(self::CRON_HOOK) === false) {
            wp_schedule_event(time() + self::RETRY_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    /** Parse a stored Riyadh wall-clock and return canonical UTC instants. */
    public static function resolve_times($raw)
    {
        if (!is_string($raw) || trim($raw) === '') return self::error('event_date_missing');
        $raw = trim($raw);
        $timezone = new DateTimeZone('Asia/Riyadh');
        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $raw, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
                && $date->format($format) === $raw) {
                $start = $date->setTimezone(new DateTimeZone('UTC'));
                return [
                    'result' => 'resolved',
                    'start_utc' => $start->format('Y-m-d H:i:s'),
                    'end_utc' => $start->modify('+6 hours')->format('Y-m-d H:i:s'),
                ];
            }
        }
        return self::error('event_date_invalid');
    }

    /** Metadata that must be committed atomically with the first Plus binding. */
    public static function initial_binding_metadata($event_id, $now_utc = null)
    {
        $event_id = absint($event_id);
        if (!$event_id || !get_post($event_id)) {
            return [
                'next_reconcile_at' => self::retry_at($now_utc),
                'last_error_code' => 'bound_event_missing',
            ];
        }
        $times = self::resolve_times((string) get_post_meta($event_id, '_pge_event_date', true));
        if (is_wp_error($times)) {
            return [
                'next_reconcile_at' => self::retry_at($now_utc),
                'last_error_code' => $times->get_error_code(),
            ];
        }
        return ['next_reconcile_at' => $times['end_utc'], 'last_error_code' => null];
    }

    /** Called after a durable binding. Scheduling failure never reverses binding. */
    public static function schedule_bound_event($activation_id, $event_id, $now_utc = null)
    {
        $activation = PGE_Catalog_Activation_Repository::find_by_id(absint($activation_id));
        return self::schedule_row($activation, absint($event_id), $now_utc);
    }

    /** Called after an event-date edit. Terminal activations are immutable. */
    public static function reschedule_event($event_id, $now_utc = null)
    {
        $event_id = absint($event_id);
        $binding = $event_id ? PGE_Catalog_Event_Binding_Repository::find_by_event_id($event_id) : null;
        if (!is_array($binding)) return ['result' => 'not_bound'];
        $activation = PGE_Catalog_Activation_Repository::find_by_id((int) $binding['catalog_activation_id']);
        if (!is_array($activation)) return self::error('activation_missing');
        if ((string) $activation['lifecycle_state'] !== PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND) {
            return ['result' => 'terminal_noop', 'state' => (string) $activation['lifecycle_state']];
        }
        return self::with_lock($activation, function ($fresh) use ($event_id, $now_utc) {
            return self::schedule_row($fresh, $event_id, $now_utc);
        });
    }

    /** Serialize the date write with reconciliation for bound activations. */
    public static function update_event_date($event_id, $raw_date, $now_utc = null)
    {
        $event_id = absint($event_id);
        $binding = $event_id ? PGE_Catalog_Event_Binding_Repository::find_by_event_id($event_id) : null;
        if (!is_array($binding)) {
            update_post_meta($event_id, '_pge_event_date', $raw_date);
            return ['result' => 'not_bound'];
        }
        $activation = PGE_Catalog_Activation_Repository::find_by_id((int) $binding['catalog_activation_id']);
        if (!is_array($activation)) return self::error('activation_missing');
        return self::with_lock($activation, function ($fresh) use ($event_id, $raw_date, $now_utc) {
            update_post_meta($event_id, '_pge_event_date', $raw_date);
            if ((string) $fresh['lifecycle_state'] !== PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND) {
                return ['result' => 'terminal_noop', 'state' => (string) $fresh['lifecycle_state']];
            }
            return self::schedule_row($fresh, $event_id, $now_utc);
        });
    }

    public static function run_due()
    {
        $rows = PGE_Catalog_Activation_Repository::find_due_reconciliation(self::BATCH_SIZE);
        $results = [];
        foreach ($rows as $row) $results[] = self::reconcile((int) $row['id']);
        return $results;
    }

    public static function reconcile($activation_id, $now_utc = null)
    {
        $activation = PGE_Catalog_Activation_Repository::find_by_id(absint($activation_id));
        if (!is_array($activation)) return self::error('activation_missing');
        return self::with_lock($activation, function ($fresh) use ($now_utc) {
            if ((string) $fresh['lifecycle_state'] !== PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND) {
                return ['result' => 'noop', 'state' => (string) $fresh['lifecycle_state']];
            }
            if ((string) ($fresh['plan_key'] ?? '') !== self::PLUS_PLAN_KEY) return ['result' => 'not_applicable'];
            $bindings = PGE_Catalog_Event_Binding_Repository::find_by_activation_id((int) $fresh['id']);
            if (count($bindings) !== 1) return self::record_error($fresh, 'event_binding_unresolved', $now_utc);
            $event_id = (int) $bindings[0]['event_id'];
            if (!get_post($event_id)) return self::record_error($fresh, 'bound_event_missing', $now_utc);
            $times = self::resolve_times((string) get_post_meta($event_id, '_pge_event_date', true));
            if (is_wp_error($times)) return self::record_error($fresh, $times->get_error_code(), $now_utc);
            $now = self::normalise_now($now_utc);
            if ($now < $times['end_utc']) {
                return PGE_Catalog_Activation_Repository::update_reconciliation((int) $fresh['id'], (int) $fresh['revision'], [
                    'next_reconcile_at' => $times['end_utc'], 'last_reconciled_at' => $now, 'last_error_code' => null,
                ]);
            }
            if (!class_exists('PGE_Salla_Plus_Eligibility_Resolver')) return self::end_serialized($fresh, $now, null);
            return PGE_Salla_Plus_Eligibility_Resolver::with_activation_identity_lock((int) $fresh['id'], function ($identity) use ($fresh, $now) {
                return self::end_serialized($fresh, $now, $identity);
            });
        });
    }

    /** Event activation lock is already held; membership identity lock is nested second. */
    private static function end_serialized(array $expected, $now, $membership_identity)
    {
        $fresh = PGE_Catalog_Activation_Repository::find_by_id((int) $expected['id']);
        if (!is_array($fresh)) return self::error('activation_missing');
        if ((string) $fresh['lifecycle_state'] !== PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND) {
            return ['result' => 'noop', 'state' => (string) $fresh['lifecycle_state']];
        }
        $ended = PGE_Catalog_Activation_Repository::compare_and_swap_state(
            (int) $fresh['id'], PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND,
            (int) $fresh['revision'], PGE_Catalog_Activation_Repository::STATE_ENDED,
            ['ended_at' => $now, 'last_reconciled_at' => $now, 'next_reconcile_at' => null, 'last_error_code' => null]
        );
        if (($ended['result'] ?? '') === 'updated' && is_array($membership_identity)) {
            // Projection failure is reported but never reverses the durable ended state.
            $ended['membership_projection'] = PGE_Salla_Plus_Eligibility_Resolver::recompute_and_project_locked(
                (int) $membership_identity['merchant_id'],
                (int) $membership_identity['salla_customer_id']
            );
        }
        return $ended;
    }

    private static function schedule_row($activation, $event_id, $now_utc)
    {
        if (!is_array($activation)) return self::error('activation_missing');
        if ((string) ($activation['lifecycle_state'] ?? '') !== PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND) {
            return ['result' => 'noop', 'state' => (string) ($activation['lifecycle_state'] ?? '')];
        }
        if ((string) ($activation['plan_key'] ?? '') !== self::PLUS_PLAN_KEY) return ['result' => 'not_applicable'];
        if (!$event_id || !get_post($event_id)) return self::record_error($activation, 'bound_event_missing', $now_utc);
        $times = self::resolve_times((string) get_post_meta($event_id, '_pge_event_date', true));
        if (is_wp_error($times)) return self::record_error($activation, $times->get_error_code(), $now_utc);
        return PGE_Catalog_Activation_Repository::update_reconciliation((int) $activation['id'], (int) $activation['revision'], [
            'next_reconcile_at' => $times['end_utc'], 'last_error_code' => null,
        ]);
    }

    private static function record_error(array $activation, $code, $now_utc)
    {
        $saved = PGE_Catalog_Activation_Repository::update_reconciliation((int) $activation['id'], (int) $activation['revision'], [
            'next_reconcile_at' => self::retry_at($now_utc), 'last_reconciled_at' => self::normalise_now($now_utc), 'last_error_code' => $code,
        ]);
        $saved['error_code'] = $code;
        return $saved;
    }

    private static function retry_at($now_utc)
    {
        return gmdate('Y-m-d H:i:s', strtotime(self::normalise_now($now_utc) . ' UTC') + self::RETRY_SECONDS);
    }

    private static function with_lock(array $activation, callable $callback)
    {
        global $wpdb;
        $name = PGE_Catalog_Event_Binding_Service::lock_name((string) ($activation['activation_id'] ?? ''));
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
        if ($locked !== 1) return self::error('activation_lock_unavailable');
        try {
            $fresh = PGE_Catalog_Activation_Repository::find_by_id((int) $activation['id']);
            return is_array($fresh) ? $callback($fresh) : self::error('activation_missing');
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    private static function normalise_now($now)
    {
        if (is_string($now) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $now)) return $now;
        return current_time('mysql', true);
    }

    private static function error($code)
    {
        return function_exists('wp_error') ? wp_error($code) : new WP_Error($code);
    }
}
