<?php
if (!defined('ABSPATH')) exit;

/** Bounded WP-Cron convergence worker for durable Salla membership state. */
final class PGE_Salla_Membership_Sync_Worker
{
    const WORKER_HOOK = 'pge_salla_membership_sync_worker';
    const RECOVERY_HOOK = 'pge_salla_membership_sync_recovery';
    const BATCH_SIZE = 10;
    const RECOVERY_INTERVAL_SECONDS = 900;
    const MAX_BACKOFF_SECONDS = 3600;

    public static function run_batch($limit = self::BATCH_SIZE)
    {
        global $wpdb;
        $lock = 'pge_salla_sync_worker';
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 0)) !== 1) {
            return ['result' => 'busy', 'processed' => 0];
        }
        $processed = 0;
        try {
            $rows = PGE_Salla_Membership_Sync_Store::find_due(max(1, min(50, (int) $limit)));
            foreach ($rows as $row) {
                $claim = PGE_Salla_Membership_Sync_Store::claim($row['id'] ?? 0);
                if (($claim['result'] ?? '') !== 'claimed') continue;
                self::process_claim($claim);
                $processed++;
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        $next_due = PGE_Salla_Membership_Sync_Store::next_due_timestamp();
        if ($next_due !== null) {
            self::schedule_worker(max(1, $next_due - (int) current_time('timestamp', true)));
        }
        return ['result' => 'ok', 'processed' => $processed];
    }

    private static function process_claim(array $claim)
    {
        if (($claim['desired_state'] ?? '') !== PGE_Salla_Membership_Sync_Store::DESIRED_MEMBER) {
            PGE_Salla_Membership_Sync_Store::mark_failed($claim, 'unsupported_desired_state');
            return;
        }
        $service = new PGE_Salla_Customer_Groups_Service();
        if (!empty($claim['reconcile_first'])) {
            $membership = $service->customer_is_in_group($claim['merchant_id'], $claim['salla_customer_id'], $claim['group_id']);
            if (!is_wp_error($membership)) {
                if (!empty($membership['is_member'])) {
                    PGE_Salla_Membership_Sync_Store::mark_satisfied($claim);
                    return;
                }
            } else {
                self::finalize_error($claim, $membership, true);
                return;
            }
        }

        $result = $service->add_customer_to_group($claim['merchant_id'], $claim['salla_customer_id'], $claim['group_id']);
        if (!is_wp_error($result)) {
            PGE_Salla_Membership_Sync_Store::mark_satisfied($claim);
            return;
        }

        if ($result->get_error_code() === 'salla_customer_group_transport_error') {
            $membership = $service->customer_is_in_group($claim['merchant_id'], $claim['salla_customer_id'], $claim['group_id']);
            if (is_wp_error($membership)) {
                self::finalize_error($claim, $membership, true);
            } elseif (!empty($membership['is_member'])) {
                PGE_Salla_Membership_Sync_Store::mark_satisfied($claim);
            } else {
                self::finalize_error($claim, $result, true);
            }
            return;
        }
        self::finalize_error($claim, $result, false);
    }

    private static function finalize_error(array $claim, $error, $ambiguous)
    {
        $code = is_wp_error($error) ? $error->get_error_code() : 'unknown_error';
        $data = is_wp_error($error) ? $error->get_error_data() : null;
        $http = is_array($data) ? (int) ($data['http_status'] ?? 0) : 0;
        $retryable = $ambiguous || $http === 429 || $http >= 500
            || in_array($code, [
                'salla_customer_group_transport_error', 'salla_customer_details_transport_error',
                'salla_token_refresh_transport_error', 'salla_token_refresh_lock_failed',
                'salla_token_lock_unavailable',
            ], true);
        if ($http && in_array($http, [400, 401, 403, 422], true)) $retryable = false;

        if ($retryable) {
            PGE_Salla_Membership_Sync_Store::mark_retry(
                $claim,
                $code,
                self::backoff_seconds((int) ($claim['attempt_count'] ?? 1)),
                $ambiguous
            );
        } else {
            PGE_Salla_Membership_Sync_Store::mark_failed($claim, $code);
        }
    }

    public static function backoff_seconds($attempt_count)
    {
        $power = max(0, min(6, (int) $attempt_count - 1));
        return min(self::MAX_BACKOFF_SECONDS, 60 * (2 ** $power));
    }

    public static function schedule_worker($delay = 1)
    {
        if (wp_next_scheduled(self::WORKER_HOOK) !== false) return false;
        return wp_schedule_single_event((int) current_time('timestamp', true) + max(0, (int) $delay), self::WORKER_HOOK) !== false;
    }

    public static function ensure_recovery_scheduled()
    {
        if (wp_next_scheduled(self::RECOVERY_HOOK) !== false) return false;
        return wp_schedule_single_event((int) current_time('timestamp', true) + self::RECOVERY_INTERVAL_SECONDS, self::RECOVERY_HOOK) !== false;
    }

    public static function run_recovery()
    {
        if (PGE_Salla_Membership_Sync_Store::has_due_work()) self::schedule_worker(1);
        self::ensure_recovery_scheduled();
    }
}

add_action(PGE_Salla_Membership_Sync_Worker::WORKER_HOOK, [PGE_Salla_Membership_Sync_Worker::class, 'run_batch']);
add_action(PGE_Salla_Membership_Sync_Worker::RECOVERY_HOOK, [PGE_Salla_Membership_Sync_Worker::class, 'run_recovery']);
add_action('init', [PGE_Salla_Membership_Sync_Worker::class, 'ensure_recovery_scheduled']);
