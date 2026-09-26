<?php
if (!defined('ABSPATH')) exit;

/** Persistence primitives for Catalog activations; orchestration belongs elsewhere. */
final class PGE_Catalog_Activation_Repository
{
    const STATE_PREPARING = 'preparing';
    const STATE_ACTIVE_UNBOUND = 'active_unbound';
    const STATE_ACTIVE_BOUND = 'active_bound';
    const STATE_ENDED = 'ended';
    const STATE_REVOKED = 'revoked';

    private static function table() { return PGE_Catalog_Activation_Schema::activations_table(); }

    public static function create_preparing(array $attributes)
    {
        $required_strings = ['activation_id', 'plan_key', 'tier_key', 'activation_source', 'idempotency_key', 'projection_snapshot'];
        foreach ($required_strings as $key) {
            if (!isset($attributes[$key]) || !is_string($attributes[$key]) || ($key !== 'projection_snapshot' && trim($attributes[$key]) === '')) {
                return ['result' => 'error', 'reason' => 'invalid_' . $key];
            }
        }
        foreach (['user_id', 'plan_id', 'tier_id'] as $key) {
            if (self::positive_integer($attributes[$key] ?? 0) === 0) return ['result' => 'error', 'reason' => 'invalid_' . $key];
        }
        $source = trim($attributes['activation_source']);
        if (!in_array($source, ['salla', 'manual', 'backfill'], true)) return ['result' => 'error', 'reason' => 'invalid_activation_source'];

        $now = current_time('mysql', true);
        $data = [
            'activation_id' => trim($attributes['activation_id']),
            'user_id' => (int) $attributes['user_id'], 'plan_id' => (int) $attributes['plan_id'], 'tier_id' => (int) $attributes['tier_id'],
            'plan_key' => trim($attributes['plan_key']), 'tier_key' => trim($attributes['tier_key']),
            'activation_source' => $source, 'idempotency_key' => trim($attributes['idempotency_key']),
            'external_order_id' => self::nullable_string($attributes['external_order_id'] ?? null),
            'projection_snapshot' => $attributes['projection_snapshot'],
            'lifecycle_state' => self::STATE_PREPARING, 'revision' => 1, 'projection_attempts' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ];
        global $wpdb;
        $inserted = $wpdb->insert(self::table(), $data, ['%s','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s']);
        if ($inserted) return ['result' => 'created', 'id' => (int) $wpdb->insert_id];

        $insert_error = (string) ($wpdb->last_error ?? '');
        $by_activation = self::find_by_activation_id($data['activation_id']);
        $by_idempotency = self::find_by_idempotency_key($data['idempotency_key']);
        if (is_array($by_activation)) {
            return ['result' => 'conflict', 'reason' => 'activation_id', 'record' => $by_activation];
        }
        if (is_array($by_idempotency)) {
            return ['result' => 'conflict', 'reason' => 'idempotency_key', 'record' => $by_idempotency];
        }
        return ['result' => 'db_error', 'reason' => $insert_error === '' ? 'insert_failed' : 'insert_error'];
    }

    public static function find_by_activation_id($activation_id)
    {
        return self::find_string('activation_id', $activation_id);
    }

    public static function find_by_idempotency_key($idempotency_key)
    {
        return self::find_string('idempotency_key', $idempotency_key);
    }

    public static function find_by_id($id)
    {
        global $wpdb;
        $id = self::positive_integer($id);
        return $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A) : null;
    }

    /** Read and lock one activation row inside the caller's transaction. */
    public static function find_by_id_for_update($id)
    {
        global $wpdb;
        $id = self::positive_integer($id);
        return $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1 FOR UPDATE', $id), ARRAY_A) : null;
    }

    /**
     * @return array{result:string,id?:int,revision?:int,current_state?:string,current_revision?:int,reason?:string}
     */
    public static function compare_and_swap_state($id, $expected_state, $expected_revision, $new_state, array $metadata = [])
    {
        $id = self::positive_integer($id);
        $expected_revision = self::positive_integer($expected_revision);
        if (!$id || !$expected_revision || !self::valid_state($expected_state) || !self::valid_state($new_state)) {
            return ['result' => 'invalid'];
        }
        // Terminality is a persistence invariant. Detailed non-terminal
        // transition policy remains the responsibility of later orchestration.
        if (in_array($expected_state, [self::STATE_ENDED, self::STATE_REVOKED], true)) {
            return self::classify_cas_miss($id, $expected_state, $expected_revision);
        }
        $data = ['lifecycle_state' => $new_state, 'revision' => $expected_revision + 1, 'updated_at' => current_time('mysql', true)];
        foreach (['activated_at', 'ended_at', 'revoked_at', 'last_reconciled_at', 'next_reconcile_at', 'last_error_code'] as $field) {
            if (array_key_exists($field, $metadata)) {
                $data[$field] = $field === 'last_error_code' ? self::safe_error_code($metadata[$field]) : self::nullable_string($metadata[$field]);
            }
        }
        global $wpdb;
        $updated = $wpdb->update(self::table(), $data, [
            'id' => $id, 'lifecycle_state' => $expected_state, 'revision' => $expected_revision,
        ]);
        if ($updated === false || (string) ($wpdb->last_error ?? '') !== '') {
            return ['result' => 'db_error', 'id' => $id];
        }
        if ((int) $updated === 1) {
            return ['result' => 'updated', 'id' => $id, 'revision' => $expected_revision + 1];
        }
        return self::classify_cas_miss($id, $expected_state, $expected_revision);
    }

    /**
     * Record projection failure metadata without changing lifecycle state.
     *
     * @return array{result:string,id?:int,revision?:int,projection_attempts?:int,current_state?:string,current_revision?:int}
     */
    public static function record_projection_failure($id, $expected_revision, $error_code, $next_reconcile_at)
    {
        $id = self::positive_integer($id);
        $expected_revision = self::positive_integer($expected_revision);
        if (!$id || !$expected_revision) return ['result' => 'invalid'];

        global $wpdb;
        $row = self::find_by_id($id);
        if ((string) ($wpdb->last_error ?? '') !== '') return ['result' => 'db_error', 'id' => $id];
        if (!is_array($row)) return ['result' => 'missing', 'id' => $id];

        $state = (string) ($row['lifecycle_state'] ?? '');
        $current_revision = (int) ($row['revision'] ?? 0);
        if (in_array($state, [self::STATE_ENDED, self::STATE_REVOKED], true)) {
            return ['result' => 'terminal', 'id' => $id, 'current_state' => $state, 'current_revision' => $current_revision];
        }
        if ($current_revision !== $expected_revision) {
            return ['result' => 'stale', 'id' => $id, 'current_state' => $state, 'current_revision' => $current_revision];
        }

        $next_attempts = (int) ($row['projection_attempts'] ?? 0) + 1;
        $updated = $wpdb->update(self::table(), [
            'projection_attempts' => $next_attempts,
            'last_error_code' => self::safe_error_code($error_code),
            'next_reconcile_at' => self::nullable_string($next_reconcile_at),
            'revision' => $expected_revision + 1,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id, 'revision' => $expected_revision]);

        if ($updated === false || (string) ($wpdb->last_error ?? '') !== '') {
            return ['result' => 'db_error', 'id' => $id];
        }
        if ((int) $updated === 1) {
            return [
                'result' => 'recorded', 'id' => $id,
                'revision' => $expected_revision + 1,
                'projection_attempts' => $next_attempts,
            ];
        }

        $current = self::find_by_id($id);
        if ((string) ($wpdb->last_error ?? '') !== '') return ['result' => 'db_error', 'id' => $id];
        if (!is_array($current)) return ['result' => 'missing', 'id' => $id];
        $current_state = (string) ($current['lifecycle_state'] ?? '');
        $current_revision = (int) ($current['revision'] ?? 0);
        if (in_array($current_state, [self::STATE_ENDED, self::STATE_REVOKED], true)) {
            return ['result' => 'terminal', 'id' => $id, 'current_state' => $current_state, 'current_revision' => $current_revision];
        }
        if ($current_revision !== $expected_revision) {
            return ['result' => 'stale', 'id' => $id, 'current_state' => $current_state, 'current_revision' => $current_revision];
        }

        // A matched, non-terminal row with an unchanged revision should have
        // changed attempts and revision. Zero affected rows is therefore an
        // unexplained persistence failure, not a stale success.
        return ['result' => 'db_error', 'id' => $id];
    }

    public static function find_due_reconciliation($limit = 20)
    {
        global $wpdb;
        $limit = max(1, min(100, (int) $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE lifecycle_state = %s AND next_reconcile_at IS NOT NULL AND next_reconcile_at <= %s ORDER BY next_reconcile_at ASC, id ASC LIMIT %d',
            self::STATE_ACTIVE_BOUND, current_time('mysql', true), $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** CAS-safe reconciliation metadata update without changing lifecycle state. */
    public static function update_reconciliation($id, $expected_revision, array $metadata = [])
    {
        $id = self::positive_integer($id);
        $expected_revision = self::positive_integer($expected_revision);
        if (!$id || !$expected_revision) return ['result' => 'invalid'];

        $allowed = ['next_reconcile_at', 'last_reconciled_at', 'last_error_code'];
        $data = ['revision' => $expected_revision + 1, 'updated_at' => current_time('mysql', true)];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $metadata)) continue;
            $data[$field] = $field === 'last_error_code'
                ? self::safe_error_code($metadata[$field])
                : self::nullable_string($metadata[$field]);
        }
        if (count($data) === 2) return ['result' => 'invalid'];

        global $wpdb;
        $updated = $wpdb->update(self::table(), $data, [
            'id' => $id,
            'lifecycle_state' => self::STATE_ACTIVE_BOUND,
            'revision' => $expected_revision,
        ]);
        if ($updated === false || (string) ($wpdb->last_error ?? '') !== '') return ['result' => 'db_error', 'id' => $id];
        if ((int) $updated === 1) return ['result' => 'updated', 'id' => $id, 'revision' => $expected_revision + 1];
        return self::classify_cas_miss($id, self::STATE_ACTIVE_BOUND, $expected_revision);
    }

    private static function find_string($column, $value)
    {
        if (!is_string($value) || trim($value) === '') return null;
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE $column = %s LIMIT 1", trim($value)), ARRAY_A);
    }

    private static function classify_cas_miss($id, $expected_state, $expected_revision)
    {
        global $wpdb;
        $row = self::find_by_id($id);
        if ((string) ($wpdb->last_error ?? '') !== '') return ['result' => 'db_error', 'id' => $id];
        if (!is_array($row)) return ['result' => 'missing', 'id' => $id];
        $state = (string) ($row['lifecycle_state'] ?? '');
        $revision = (int) ($row['revision'] ?? 0);
        if (in_array($state, [self::STATE_ENDED, self::STATE_REVOKED], true)) {
            return ['result' => 'terminal', 'id' => $id, 'current_state' => $state, 'current_revision' => $revision];
        }
        return [
            'result' => 'stale', 'id' => $id, 'current_state' => $state,
            'current_revision' => $revision, 'expected_state' => $expected_state,
            'expected_revision' => $expected_revision,
        ];
    }

    private static function valid_state($state)
    {
        return is_string($state) && in_array($state, [self::STATE_PREPARING, self::STATE_ACTIVE_UNBOUND, self::STATE_ACTIVE_BOUND, self::STATE_ENDED, self::STATE_REVOKED], true);
    }

    private static function nullable_string($value)
    {
        if ($value === null) return null;
        return is_scalar($value) && !is_bool($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function safe_error_code($value)
    {
        $value = is_scalar($value) && !is_bool($value) ? strtolower((string) $value) : '';
        $value = preg_replace('/[^a-z0-9_\-]/', '', $value);
        return $value === '' ? null : substr($value, 0, 100);
    }

    private static function positive_integer($value)
    {
        if (!is_scalar($value) || is_bool($value)) return 0;
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? 0 : (int) $value;
    }
}
