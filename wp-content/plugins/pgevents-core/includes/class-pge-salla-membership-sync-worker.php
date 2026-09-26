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
            $removal_enabled = PGE_Salla_Not_Member_Removal_Feature::enabled();
            $rows = PGE_Salla_Membership_Sync_Store::find_due(max(1, min(50, (int) $limit)), $removal_enabled);
            foreach ($rows as $row) {
                $claim = PGE_Salla_Membership_Sync_Store::claim($row['id'] ?? 0);
                if (($claim['result'] ?? '') !== 'claimed') continue;
                self::process_claim($claim);
                $processed++;
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        $next_due = PGE_Salla_Membership_Sync_Store::next_due_timestamp(PGE_Salla_Not_Member_Removal_Feature::enabled());
        if ($next_due !== null) {
            self::schedule_worker(max(1, $next_due - (int) current_time('timestamp', true)));
        }
        return ['result' => 'ok', 'processed' => $processed];
    }

    public static function process_claim(array $claim)
    {
        if (($claim['desired_state'] ?? '') === PGE_Salla_Membership_Sync_Store::DESIRED_NOT_MEMBER) {
            if (!PGE_Salla_Not_Member_Removal_Feature::enabled() && !self::requires_disabled_reconciliation($claim)) {
                return self::defer_disabled_removal($claim);
            }
            return self::process_removal_claim($claim);
        }
        if (($claim['desired_state'] ?? '') !== PGE_Salla_Membership_Sync_Store::DESIRED_MEMBER) {
            PGE_Salla_Membership_Sync_Store::mark_failed($claim, 'unsupported_desired_state');
            return ['result' => 'invalid', 'reason' => 'unsupported_desired_state'];
        }
        $service = new PGE_Salla_Customer_Groups_Service();
        if (!empty($claim['reconcile_first'])) {
            $membership = $service->customer_is_in_group($claim['merchant_id'], $claim['salla_customer_id'], $claim['group_id']);
            if (!is_wp_error($membership)) {
                if (!empty($membership['is_member'])) {
                    PGE_Salla_Membership_Sync_Store::mark_satisfied($claim);
                    return ['result' => 'satisfied'];
                }
            } else {
                self::finalize_error($claim, $membership, true);
                return ['result' => 'error'];
            }
        }

        $result = $service->add_customer_to_group($claim['merchant_id'], $claim['salla_customer_id'], $claim['group_id']);
        if (!is_wp_error($result)) {
            PGE_Salla_Membership_Sync_Store::mark_satisfied($claim);
            return ['result' => 'satisfied'];
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
            return ['result' => 'error'];
        }
        self::finalize_error($claim, $result, false);
        return ['result' => 'error'];
    }

    private static function process_removal_claim(array $claim)
    {
        global $wpdb;
        $lock = PGE_Salla_Membership_Sync_Store::identity_lock_name($claim['merchant_id'], $claim['salla_customer_id'], $claim['group_id']);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) !== 1) {
            return self::finish_removal_finalization($claim, 'retry', 'lock_not_acquired', ['result' => 'retryable_removal', 'reason' => 'lock_not_acquired']);
        }
        try {
            $valid = PGE_Salla_Membership_Sync_Store::revalidate_claim($claim);
            if (($valid['result'] ?? '') !== 'valid') return ['result' => 'stale_claim'];

            $eligibility = PGE_Salla_Plus_Eligibility_Resolver::resolve($claim['merchant_id'], $claim['salla_customer_id']);
            if (empty($eligibility['authoritative'])) {
                return self::finish_removal_finalization($claim, 'retry', 'eligibility_query_failed', ['result' => 'eligibility_unavailable']);
            }
            if (!empty($eligibility['eligible'])) {
                $restored = PGE_Salla_Membership_Sync_Store::request_state_locked($claim['merchant_id'], $claim['salla_customer_id'], $claim['group_id'], PGE_Salla_Membership_Sync_Store::DESIRED_MEMBER);
                return in_array(($restored['result'] ?? ''), ['created', 'updated', 'pending', 'satisfied'], true) ? ['result' => 'eligible_again'] : ['result' => 'finalization_failed'];
            }

            $service = new PGE_Salla_Customer_Groups_Service();
            $snapshot = PGE_Salla_Membership_Sync_Store::removal_snapshot($claim);
            if (($snapshot['result'] ?? '') === 'found' && ($snapshot['state'] ?? '') !== 'retryable') {
                return self::reconcile_removal($claim, $service, $snapshot['groups']);
            }
            if (($snapshot['result'] ?? '') !== 'none' && ($snapshot['result'] ?? '') !== 'found') return ['result' => 'stale_claim'];
            if (!PGE_Salla_Not_Member_Removal_Feature::enabled()) return self::defer_disabled_removal($claim);

            $details = $service->get_customer_details($claim['merchant_id'], $claim['salla_customer_id']);
            if (is_wp_error($details)) return self::finish_removal_error($claim, $details, false);
            if (empty($details['exists'])) {
                return self::finish_removal_finalization($claim, 'failed', 'customer_not_found', ['result' => 'customer_not_found']);
            }
            $groups = $details['groups'];
            if (!in_array((int) $claim['group_id'], $groups, true)) {
                $valid = PGE_Salla_Membership_Sync_Store::revalidate_claim($claim);
                if (($valid['result'] ?? '') !== 'valid') return ['result' => 'stale_claim'];
                return self::finish_removal_finalization($claim, 'satisfied', null, ['result' => 'already_absent']);
            }

            $remaining = array_values(array_filter($groups, static fn($id) => (int) $id !== (int) $claim['group_id']));
            sort($remaining, SORT_NUMERIC);
            $saved = PGE_Salla_Membership_Sync_Store::save_removal_snapshot($claim, $remaining, 'prepared');
            if (($saved['result'] ?? '') !== 'saved') {
                if (($saved['result'] ?? '') === 'db_error') return self::finish_removal_finalization($claim, 'retry', 'snapshot_db_error', ['result' => 'remote_http_error', 'reason' => 'snapshot_not_saved']);
                return ['result' => 'stale_claim', 'reason' => 'snapshot_not_saved'];
            }
            if ((PGE_Salla_Membership_Sync_Store::revalidate_claim($claim)['result'] ?? '') !== 'valid') return ['result' => 'stale_claim'];
            if (!PGE_Salla_Not_Member_Removal_Feature::enabled()) return self::defer_disabled_removal($claim);

            $updated = $service->update_customer_groups($claim['merchant_id'], $claim['salla_customer_id'], $details['first_name'], $remaining);
            if (is_wp_error($updated)) {
                $code = $updated->get_error_code();
                if ($code === 'salla_not_member_removal_disabled') return self::defer_disabled_removal($claim);
                $ambiguous = $code === 'salla_customer_update_transport_error'
                    || $code === 'salla_customer_update_ambiguous_response'
                    || self::error_http_status($updated) >= 500;
                if (!$ambiguous) return self::finish_removal_error($claim, $updated, false);
                PGE_Salla_Membership_Sync_Store::save_removal_snapshot($claim, $remaining, 'ambiguous');
                return self::reconcile_removal($claim, $service, $remaining, true);
            }
            return self::reconcile_removal($claim, $service, $remaining);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function reconcile_removal(array $claim, $service, array $snapshot_groups, $after_ambiguous_put = false)
    {
        if ((PGE_Salla_Membership_Sync_Store::revalidate_claim($claim)['result'] ?? '') !== 'valid') return ['result' => 'stale_claim'];
        $details = $service->get_customer_details($claim['merchant_id'], $claim['salla_customer_id']);
        if (is_wp_error($details)) {
            return self::finish_removal_finalization($claim, 'ambiguous', 'reconciliation_unavailable', ['result' => $after_ambiguous_put ? 'transport_ambiguous' : 'reconciliation_unavailable']);
        }
        if (empty($details['exists'])) {
            return self::finish_removal_finalization($claim, 'failed', 'customer_not_found', ['result' => 'customer_not_found']);
        }
        $live = $details['groups'];
        foreach ($snapshot_groups as $required) {
            if (!in_array((int) $required, $live, true)) {
                return self::finish_removal_finalization($claim, 'failed', 'reconciliation_conflict', ['result' => 'reconciliation_conflict']);
            }
        }
        if (in_array((int) $claim['group_id'], $live, true)) {
            $saved = PGE_Salla_Membership_Sync_Store::save_removal_snapshot($claim, $snapshot_groups, 'retryable');
            if (($saved['result'] ?? '') !== 'saved') {
                return ['result' => ($saved['result'] ?? '') === 'stale_claim' ? 'stale_finalization' : 'finalization_failed'];
            }
            if (!PGE_Salla_Not_Member_Removal_Feature::enabled()) return self::defer_disabled_removal($claim);
            return self::finish_removal_finalization($claim, 'retry', 'retryable_removal', ['result' => 'retryable_removal']);
        }
        return self::finish_removal_finalization($claim, 'satisfied', null, ['result' => 'removed']);
    }

    private static function finish_removal_error(array $claim, $error, $ambiguous)
    {
        $code = is_wp_error($error) ? $error->get_error_code() : 'remote_http_error';
        $http = is_wp_error($error) ? self::error_http_status($error) : 0;
        if ($http === 401) {
            return self::finish_removal_finalization($claim, 'failed', 'unauthorized_after_token_recovery', ['result' => 'unauthorized_after_token_recovery']);
        }
        $map = [
            'salla_customer_details_malformed_groups' => 'malformed_groups_response',
            'salla_customer_details_invalid_response' => 'malformed_groups_response',
            'salla_customer_details_invalid_json' => 'malformed_groups_response',
            'salla_customer_details_missing_update_field' => 'missing_update_field',
        ];
        $result = $map[$code] ?? 'remote_http_error';
        $retryable = $ambiguous || $code === 'salla_customer_details_transport_error' || $http === 429 || $http >= 500;
        return self::finish_removal_finalization($claim, $retryable ? ($ambiguous ? 'ambiguous' : 'retry') : 'failed', $result, ['result' => $result]);
    }

    private static function finish_removal_finalization(array $claim, $mode, $error_code, array $success_result)
    {
        if ($mode === 'satisfied') $ok = PGE_Salla_Membership_Sync_Store::mark_satisfied($claim);
        elseif ($mode === 'failed') $ok = PGE_Salla_Membership_Sync_Store::mark_failed($claim, $error_code);
        else $ok = PGE_Salla_Membership_Sync_Store::mark_retry($claim, $error_code, self::backoff_seconds((int) ($claim['attempt_count'] ?? 1)), $mode === 'ambiguous');
        if ($ok) return $success_result;
        $valid = PGE_Salla_Membership_Sync_Store::revalidate_claim($claim);
        return ['result' => ($valid['result'] ?? '') === 'valid' ? 'finalization_failed' : 'stale_finalization'];
    }

    private static function requires_disabled_reconciliation(array $claim)
    {
        return !empty($claim['reconcile_first'])
            && is_array($claim['removal_snapshot_groups'] ?? null)
            && (string) ($claim['removal_snapshot_state'] ?? '') !== 'retryable';
    }

    private static function defer_disabled_removal(array $claim)
    {
        return self::finish_removal_finalization($claim, 'retry', 'removal_disabled', ['result' => 'removal_disabled']);
    }

    private static function error_http_status($error)
    {
        $data = is_wp_error($error) ? $error->get_error_data() : null;
        return is_array($data) ? (int) ($data['http_status'] ?? 0) : 0;
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
        if (class_exists('PGE_Salla_Plus_Eligibility_Resolver')) PGE_Salla_Plus_Eligibility_Resolver::recover(50);
        if (PGE_Salla_Membership_Sync_Store::has_due_work(PGE_Salla_Not_Member_Removal_Feature::enabled())) self::schedule_worker(1);
        self::ensure_recovery_scheduled();
    }
}

add_action(PGE_Salla_Membership_Sync_Worker::WORKER_HOOK, [PGE_Salla_Membership_Sync_Worker::class, 'run_batch']);
add_action(PGE_Salla_Membership_Sync_Worker::RECOVERY_HOOK, [PGE_Salla_Membership_Sync_Worker::class, 'run_recovery']);
add_action('init', [PGE_Salla_Membership_Sync_Worker::class, 'ensure_recovery_scheduled']);
