<?php
if (!defined('ABSPATH')) exit;

/** Durable desired-state store and ownership boundary for Salla memberships. */
final class PGE_Salla_Membership_Sync_Store
{
    const DESIRED_MEMBER = 'member';
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_RETRY = 'retry';
    const STATUS_AMBIGUOUS = 'ambiguous';
    const STATUS_SATISFIED = 'satisfied';
    const STATUS_FAILED = 'failed';
    const LEASE_SECONDS = 300;

    private static function table()
    {
        return PGE_Salla_Sync_Schema::table_name();
    }

    /** Establish desired_state=member without duplicating an identity row. */
    public static function request_member($merchant_id, $customer_id, $group_id)
    {
        $ids = self::normalize_identity($merchant_id, $customer_id, $group_id);
        if ($ids === null) {
            return ['result' => 'error', 'reason' => 'invalid_identity'];
        }

        global $wpdb;
        $lock = self::identity_lock_name(...$ids);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) !== 1) {
            return ['result' => 'error', 'reason' => 'lock_not_acquired'];
        }

        try {
            $row = self::find_identity(...$ids);
            $now = current_time('mysql', true);
            if ($row === null) {
                $inserted = $wpdb->insert(self::table(), [
                    'merchant_id' => $ids[0], 'salla_customer_id' => $ids[1], 'group_id' => $ids[2],
                    'desired_state' => self::DESIRED_MEMBER, 'desired_revision' => 1,
                    'status' => self::STATUS_PENDING, 'attempt_count' => 0,
                    'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ], ['%d', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s']);
                return $inserted ? ['result' => 'created', 'id' => (int) $wpdb->insert_id] : ['result' => 'error', 'reason' => 'insert_failed'];
            }

            if ((string) $row['desired_state'] === self::DESIRED_MEMBER) {
                return ['result' => (string) $row['status'] === self::STATUS_SATISFIED ? 'satisfied' : 'pending', 'id' => (int) $row['id']];
            }

            $revision = (int) $row['desired_revision'] + 1;
            $updated = $wpdb->update(self::table(), [
                'desired_state' => self::DESIRED_MEMBER, 'desired_revision' => $revision,
                'status' => self::STATUS_PENDING, 'attempt_token' => null,
                'attempt_revision' => null, 'attempt_started_at' => null,
                'next_attempt_at' => $now, 'last_error_code' => null, 'updated_at' => $now,
            ], ['id' => (int) $row['id'], 'desired_revision' => (int) $row['desired_revision']]);
            return $updated ? ['result' => 'updated', 'id' => (int) $row['id'], 'desired_revision' => $revision] : ['result' => 'error', 'reason' => 'update_failed'];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function find_due($limit = 10)
    {
        global $wpdb;
        $limit = max(1, min(50, (int) $limit));
        $now = current_time('mysql', true);
        $lease_cutoff = gmdate('Y-m-d H:i:s', self::now() - self::LEASE_SECONDS);
        $sql = $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE ((status IN (%s,%s,%s) AND (next_attempt_at IS NULL OR next_attempt_at <= %s)) OR (status = %s AND attempt_started_at <= %s)) ORDER BY id ASC LIMIT %d",
            self::STATUS_PENDING, self::STATUS_RETRY, self::STATUS_AMBIGUOUS, $now,
            self::STATUS_PROCESSING, $lease_cutoff, $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public static function claim($id)
    {
        $id = self::positive_integer($id);
        if (!$id) return ['result' => 'error', 'reason' => 'invalid_id'];
        global $wpdb;
        $lock = 'pge_salla_sync_row_' . $id;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) !== 1) {
            return ['result' => 'error', 'reason' => 'lock_not_acquired'];
        }
        try {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A);
            if (!is_array($row)) return ['result' => 'missing'];
            $status = (string) $row['status'];
            $now_ts = self::now();
            $due = in_array($status, [self::STATUS_PENDING, self::STATUS_RETRY, self::STATUS_AMBIGUOUS], true)
                && (empty($row['next_attempt_at']) || strtotime((string) $row['next_attempt_at'] . ' UTC') <= $now_ts);
            $expired = $status === self::STATUS_PROCESSING
                && !empty($row['attempt_started_at'])
                && strtotime((string) $row['attempt_started_at'] . ' UTC') <= ($now_ts - self::LEASE_SECONDS);
            if (!$due && !$expired) return ['result' => $status === self::STATUS_PROCESSING ? 'in_progress' : 'not_due'];

            $token = bin2hex(random_bytes(16));
            $revision = (int) $row['desired_revision'];
            $now = current_time('mysql', true);
            $updated = $wpdb->update(self::table(), [
                'status' => self::STATUS_PROCESSING, 'attempt_count' => (int) $row['attempt_count'] + 1,
                'attempt_token' => $token, 'attempt_revision' => $revision,
                'attempt_started_at' => $now, 'last_attempt_at' => $now,
                'next_attempt_at' => null, 'updated_at' => $now,
            ], ['id' => $id, 'desired_revision' => $revision]);
            if (!$updated) return ['result' => 'error', 'reason' => 'claim_update_failed'];
            return [
                'result' => 'claimed', 'id' => $id, 'attempt_token' => $token,
                'desired_revision' => $revision, 'desired_state' => (string) $row['desired_state'],
                'merchant_id' => (int) $row['merchant_id'], 'salla_customer_id' => (int) $row['salla_customer_id'],
                'group_id' => (int) $row['group_id'], 'attempt_count' => (int) $row['attempt_count'] + 1,
                'reconcile_first' => $status === self::STATUS_AMBIGUOUS,
            ];
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function mark_satisfied(array $claim)
    {
        return self::finalize($claim, self::STATUS_SATISFIED, null, null, true);
    }

    public static function mark_retry(array $claim, $error_code, $delay_seconds, $ambiguous = false)
    {
        $delay = max(1, min(3600, (int) $delay_seconds));
        return self::finalize($claim, $ambiguous ? self::STATUS_AMBIGUOUS : self::STATUS_RETRY, $error_code, gmdate('Y-m-d H:i:s', self::now() + $delay), false);
    }

    public static function mark_failed(array $claim, $error_code)
    {
        return self::finalize($claim, self::STATUS_FAILED, $error_code, null, false);
    }

    private static function finalize(array $claim, $status, $error_code, $next_attempt_at, $success)
    {
        $id = self::positive_integer($claim['id'] ?? 0);
        $revision = self::positive_integer($claim['desired_revision'] ?? 0);
        $token = is_string($claim['attempt_token'] ?? null) ? trim($claim['attempt_token']) : '';
        if (!$id || !$revision || $token === '') return false;
        $now = current_time('mysql', true);
        $data = [
            'status' => $status, 'attempt_token' => null, 'attempt_revision' => null,
            'attempt_started_at' => null, 'next_attempt_at' => $next_attempt_at,
            'last_error_code' => self::safe_error_code($error_code), 'updated_at' => $now,
        ];
        if ($success) $data['last_success_at'] = $now;
        global $wpdb;
        $updated = $wpdb->update(self::table(), $data, [
            'id' => $id, 'status' => self::STATUS_PROCESSING,
            'attempt_token' => $token, 'desired_revision' => $revision,
            'attempt_revision' => $revision,
        ]);
        return $updated !== false && $updated > 0;
    }

    public static function has_due_work()
    {
        return count(self::find_due(1)) > 0;
    }

    /** Unix timestamp of the next pending/retry/lease-recovery opportunity. */
    public static function next_due_timestamp()
    {
        global $wpdb;
        $next_attempt = $wpdb->get_var($wpdb->prepare(
            'SELECT MIN(next_attempt_at) FROM ' . self::table() . ' WHERE status IN (%s,%s,%s)',
            self::STATUS_PENDING, self::STATUS_RETRY, self::STATUS_AMBIGUOUS
        ));
        $processing_started = $wpdb->get_var($wpdb->prepare(
            'SELECT MIN(attempt_started_at) FROM ' . self::table() . ' WHERE status = %s',
            self::STATUS_PROCESSING
        ));
        $candidates = [];
        if (is_string($next_attempt) && $next_attempt !== '') $candidates[] = strtotime($next_attempt . ' UTC');
        if (is_string($processing_started) && $processing_started !== '') {
            $candidates[] = strtotime($processing_started . ' UTC') + self::LEASE_SECONDS;
        }
        $candidates = array_values(array_filter($candidates, static fn($value) => is_int($value) && $value > 0));
        return $candidates ? min($candidates) : null;
    }

    public static function get($id)
    {
        global $wpdb;
        $id = self::positive_integer($id);
        return $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A) : null;
    }

    private static function find_identity($merchant_id, $customer_id, $group_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE merchant_id = %d AND salla_customer_id = %d AND group_id = %d LIMIT 1',
            $merchant_id, $customer_id, $group_id
        ), ARRAY_A);
    }

    private static function normalize_identity($merchant_id, $customer_id, $group_id)
    {
        $ids = [self::positive_integer($merchant_id), self::positive_integer($customer_id), self::positive_integer($group_id)];
        return in_array(0, $ids, true) ? null : $ids;
    }

    private static function identity_lock_name($merchant_id, $customer_id, $group_id)
    {
        return 'pge_salla_sync_' . md5($merchant_id . ':' . $customer_id . ':' . $group_id);
    }

    private static function safe_error_code($code)
    {
        $code = is_scalar($code) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $code)) : '';
        return $code === '' ? null : substr($code, 0, 100);
    }

    private static function now()
    {
        return (int) current_time('timestamp', true);
    }

    private static function positive_integer($value)
    {
        if (!is_scalar($value) || is_bool($value)) return 0;
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? 0 : (int) $value;
    }
}
