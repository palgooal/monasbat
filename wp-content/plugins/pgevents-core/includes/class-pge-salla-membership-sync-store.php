<?php
if (!defined('ABSPATH')) exit;

/** Durable desired-state store and ownership boundary for Salla memberships. */
final class PGE_Salla_Membership_Sync_Store
{
    const DESIRED_MEMBER = 'member';
    const DESIRED_NOT_MEMBER = 'not_member';
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
        return self::request_state($merchant_id, $customer_id, $group_id, self::DESIRED_MEMBER);
    }

    public static function request_not_member($merchant_id, $customer_id, $group_id)
    {
        return self::request_state($merchant_id, $customer_id, $group_id, self::DESIRED_NOT_MEMBER);
    }

    private static function request_state($merchant_id, $customer_id, $group_id, $desired_state)
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
            return self::request_state_locked($ids[0], $ids[1], $ids[2], $desired_state);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** Caller must hold identity_lock_name() for this exact identity. */
    public static function request_state_locked($merchant_id, $customer_id, $group_id, $desired_state)
    {
        $ids = self::normalize_identity($merchant_id, $customer_id, $group_id);
        if ($ids === null || !in_array($desired_state, [self::DESIRED_MEMBER, self::DESIRED_NOT_MEMBER], true)) return ['result'=>'error','reason'=>'invalid_identity'];
        global $wpdb; $row=self::find_identity(...$ids); $now=current_time('mysql',true);
        if ($row === null) {
            $inserted=$wpdb->insert(self::table(),['merchant_id'=>$ids[0],'salla_customer_id'=>$ids[1],'group_id'=>$ids[2],'desired_state'=>$desired_state,'desired_revision'=>1,'status'=>self::STATUS_PENDING,'attempt_count'=>0,'next_attempt_at'=>$now,'created_at'=>$now,'updated_at'=>$now],['%d','%d','%d','%s','%d','%s','%d','%s','%s','%s']);
            return $inserted?['result'=>'created','id'=>(int)$wpdb->insert_id,'desired_revision'=>1]:['result'=>'error','reason'=>'insert_failed'];
        }
        if ((string)$row['desired_state']===$desired_state) return ['result'=>(string)$row['status']===self::STATUS_SATISFIED?'satisfied':'pending','id'=>(int)$row['id'],'desired_revision'=>(int)$row['desired_revision']];
        $revision=(int)$row['desired_revision']+1;
        $updated=$wpdb->update(self::table(),['desired_state'=>$desired_state,'desired_revision'=>$revision,'status'=>self::STATUS_PENDING,'attempt_token'=>null,'attempt_revision'=>null,'attempt_started_at'=>null,'next_attempt_at'=>$now,'last_error_code'=>null,'removal_snapshot_groups'=>null,'removal_snapshot_revision'=>null,'removal_snapshot_state'=>null,'updated_at'=>$now],['id'=>(int)$row['id'],'desired_revision'=>(int)$row['desired_revision']]);
        return $updated?['result'=>'updated','id'=>(int)$row['id'],'desired_revision'=>$revision]:['result'=>'error','reason'=>'update_failed'];
    }

    public static function find_due($limit = 10, $include_not_member = true)
    {
        global $wpdb;
        $limit = max(1, min(50, (int) $limit));
        $now = current_time('mysql', true);
        $lease_cutoff = gmdate('Y-m-d H:i:s', self::now() - self::LEASE_SECONDS);
        $desired_clause = $include_not_member ? '' : $wpdb->prepare(
            ' AND (desired_state = %s OR (desired_state = %s AND removal_snapshot_groups IS NOT NULL AND removal_snapshot_state IN (%s,%s)))',
            self::DESIRED_MEMBER, self::DESIRED_NOT_MEMBER, 'prepared', 'ambiguous'
        );
        $order = $include_not_member ? 'id ASC' : $wpdb->prepare('CASE WHEN desired_state = %s THEN 0 ELSE 1 END ASC, id ASC', self::DESIRED_MEMBER);
        $sql = $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE (((status IN (%s,%s,%s) AND (next_attempt_at IS NULL OR next_attempt_at <= %s)) OR (status = %s AND attempt_started_at <= %s))$desired_clause) ORDER BY $order LIMIT %d",
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
                'removal_snapshot_groups' => self::decode_snapshot($row, $revision),
                'removal_snapshot_state' => (string) ($row['removal_snapshot_state'] ?? ''),
                'reconcile_first' => $status === self::STATUS_AMBIGUOUS || self::decode_snapshot($row, $revision) !== null,
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

    public static function has_due_work($include_not_member = true)
    {
        return count(self::find_due(1, $include_not_member)) > 0;
    }

    /** Unix timestamp of the next pending/retry/lease-recovery opportunity. */
    public static function next_due_timestamp($include_not_member = true)
    {
        global $wpdb;
        $desired_clause = $include_not_member ? '' : $wpdb->prepare(
            ' AND (desired_state = %s OR (desired_state = %s AND removal_snapshot_groups IS NOT NULL AND removal_snapshot_state IN (%s,%s)))',
            self::DESIRED_MEMBER, self::DESIRED_NOT_MEMBER, 'prepared', 'ambiguous'
        );
        $next_attempt = $wpdb->get_var($wpdb->prepare(
            'SELECT MIN(next_attempt_at) FROM ' . self::table() . ' WHERE status IN (%s,%s,%s)' . $desired_clause,
            self::STATUS_PENDING, self::STATUS_RETRY, self::STATUS_AMBIGUOUS
        ));
        $processing_started = $wpdb->get_var($wpdb->prepare(
            'SELECT MIN(attempt_started_at) FROM ' . self::table() . ' WHERE status = %s' . $desired_clause,
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

    /** Read-only operational counters; no schema or state mutation. */
    public static function diagnostics()
    {
        global $wpdb;
        $table = self::table();
        $now = current_time('mysql', true);
        $lease_cutoff = gmdate('Y-m-d H:i:s', self::now() - self::LEASE_SECONDS);
        $scalar = static function ($sql) use ($wpdb) { return (int) $wpdb->get_var($sql); };
        return [
            'flag_enabled' => PGE_Salla_Not_Member_Removal_Feature::enabled(),
            'not_member_total' => $scalar($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE desired_state=%s", self::DESIRED_NOT_MEMBER)),
            'not_member_due_while_disabled' => $scalar($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE desired_state=%s AND removal_snapshot_groups IS NOT NULL AND removal_snapshot_state IN (%s,%s) AND ((status IN (%s,%s,%s) AND (next_attempt_at IS NULL OR next_attempt_at<=%s)) OR (status=%s AND attempt_started_at<=%s))",
                self::DESIRED_NOT_MEMBER, 'prepared', 'ambiguous',
                self::STATUS_PENDING, self::STATUS_RETRY, self::STATUS_AMBIGUOUS, $now,
                self::STATUS_PROCESSING, $lease_cutoff
            )),
            'not_member_processing' => $scalar($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE desired_state=%s AND status=%s", self::DESIRED_NOT_MEMBER, self::STATUS_PROCESSING)),
            'not_member_ambiguous' => $scalar($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE desired_state=%s AND status=%s", self::DESIRED_NOT_MEMBER, self::STATUS_AMBIGUOUS)),
            'removal_disabled_last_error' => $scalar($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE last_error_code=%s", 'removal_disabled')),
            'reconciliation_conflicts' => $scalar($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE last_error_code=%s", 'reconciliation_conflict')),
        ];
    }

    public static function get($id)
    {
        global $wpdb;
        $id = self::positive_integer($id);
        return $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A) : null;
    }

    /** Verify that the exact claimed operation still owns this identity row. */
    public static function revalidate_claim(array $claim)
    {
        $id = self::positive_integer($claim['id'] ?? 0);
        $revision = self::positive_integer($claim['desired_revision'] ?? 0);
        $token = is_string($claim['attempt_token'] ?? null) ? trim($claim['attempt_token']) : '';
        if (!$id || !$revision || $token === '') return ['result' => 'invalid'];
        $row = self::get($id);
        if (!is_array($row)) return ['result' => 'missing'];
        foreach (['merchant_id', 'salla_customer_id', 'group_id'] as $field) {
            if ((int) ($row[$field] ?? 0) !== (int) ($claim[$field] ?? 0)) return ['result' => 'stale_claim'];
        }
        if ((string) ($row['status'] ?? '') !== self::STATUS_PROCESSING
            || (string) ($row['desired_state'] ?? '') !== (string) ($claim['desired_state'] ?? '')
            || (int) ($row['desired_revision'] ?? 0) !== $revision
            || (int) ($row['attempt_revision'] ?? 0) !== $revision
            || !hash_equals((string) ($row['attempt_token'] ?? ''), $token)) {
            return ['result' => 'stale_claim'];
        }
        return ['result' => 'valid', 'row' => $row];
    }

    /** Persist authoritative pre-GET evidence without binding it to a lease token. */
    public static function save_removal_snapshot(array $claim, array $non_target_groups, $state = 'prepared')
    {
        $valid = self::revalidate_claim($claim);
        if (($valid['result'] ?? '') !== 'valid') return $valid;
        $groups = self::normalize_groups($non_target_groups);
        if ($groups === null || !in_array($state, ['prepared', 'ambiguous', 'retryable'], true)) return ['result' => 'invalid'];
        $json = wp_json_encode($groups);
        if (!is_string($json)) return ['result' => 'invalid'];
        global $wpdb;
        $updated = $wpdb->update(self::table(), [
            'removal_snapshot_groups' => $json,
            'removal_snapshot_revision' => (int) $claim['desired_revision'],
            'removal_snapshot_state' => $state,
            'updated_at' => current_time('mysql', true),
        ], [
            'id' => (int) $claim['id'], 'status' => self::STATUS_PROCESSING,
            'desired_state' => self::DESIRED_NOT_MEMBER,
            'desired_revision' => (int) $claim['desired_revision'],
            'attempt_revision' => (int) $claim['desired_revision'],
            'attempt_token' => (string) $claim['attempt_token'],
        ]);
        if ($updated === false) return ['result' => 'db_error'];
        if ($updated < 1) return ['result' => 'stale_claim'];
        return ['result' => 'saved', 'groups' => $groups];
    }

    public static function removal_snapshot(array $claim)
    {
        $valid = self::revalidate_claim($claim);
        if (($valid['result'] ?? '') !== 'valid') return $valid;
        $groups = self::decode_snapshot($valid['row'], (int) $claim['desired_revision']);
        return $groups === null
            ? ['result' => 'none']
            : ['result' => 'found', 'groups' => $groups, 'state' => (string) ($valid['row']['removal_snapshot_state'] ?? '')];
    }

    private static function find_identity($merchant_id, $customer_id, $group_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE merchant_id = %d AND salla_customer_id = %d AND group_id = %d LIMIT 1',
            $merchant_id, $customer_id, $group_id
        ), ARRAY_A);
    }

    private static function decode_snapshot(array $row, $revision)
    {
        if ((int) ($row['removal_snapshot_revision'] ?? 0) !== (int) $revision) return null;
        $raw = $row['removal_snapshot_groups'] ?? null;
        if (!is_string($raw) || $raw === '') return null;
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) return null;
        return self::normalize_groups($decoded);
    }

    private static function normalize_groups(array $groups)
    {
        $normalized = [];
        foreach ($groups as $group) {
            $id = self::positive_integer($group);
            if (!$id) return null;
            $normalized[$id] = $id;
        }
        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    private static function normalize_identity($merchant_id, $customer_id, $group_id)
    {
        $ids = [self::positive_integer($merchant_id), self::positive_integer($customer_id), self::positive_integer($group_id)];
        return in_array(0, $ids, true) ? null : $ids;
    }

    public static function identity_lock_name($merchant_id, $customer_id, $group_id)
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
