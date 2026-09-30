<?php
if (!defined('ABSPATH')) exit;

/**
 * One-off DEC-PLUS-MIG-01 historical backfill.
 *
 * This class is deliberately not loaded by plugin bootstrap and registers no
 * hook. It is hard-scoped to the single reviewed pre-launch activation.
 */
final class PGE_DEC_Plus_MIG_01_Backfill
{
    const USER_ID = 380;
    const PLAN_ID = 2;
    const TIER_ID = 6;
    const PLAN_KEY = 'halwa_plus';
    const TIER_KEY = 'guests_100';
    const PROVIDER = 'salla';
    const MERCHANT_ID = 392732220;
    const CUSTOMER_ID = '1888007575';
    const ORDER_ID = '1576373696';
    const PRODUCT_ID = '1539650850';
    const ACTIVATION_ID = '6daa5ee5-5bda-43a6-a392-ad75402b8c21';
    const IDEMPOTENCY_KEY = 'salla:392732220:1576373696';
    const ACTIVATED_AT = '2026-09-15 20:12:37';

    public static function execute()
    {
        // Detect durable state before applying mutable first-run User Meta
        // preconditions. Final replay classification still happens while the
        // canonical user/order/event locks are held.
        $preflight = self::inspect_contract(false);
        if (!in_array(($preflight['result'] ?? ''), ['ready', 'durable_state_present'], true)) return $preflight;

        global $wpdb;
        $user_lock = 'pge_catalog_activation_user_' . self::USER_ID;
        if (!self::acquire_lock($user_lock)) return self::stopped('user_lock_not_acquired');

        try {
            $order_result = PGE_Catalog_Order_Revocation_Service::with_order_lock(
                self::PROVIDER,
                self::MERCHANT_ID,
                self::ORDER_ID,
                function () {
                    $event_lock = PGE_Catalog_Event_Binding_Service::lock_name(self::ACTIVATION_ID);
                    if (!self::acquire_lock($event_lock)) return self::stopped('event_lock_not_acquired');
                    try {
                        return self::execute_locked();
                    } finally {
                        self::release_lock($event_lock);
                    }
                }
            );
            if (($order_result['result'] ?? '') === 'lock_not_acquired') return self::stopped('order_lock_not_acquired');
            if (($order_result['result'] ?? '') === 'invalid') return self::stopped('invalid_order_lock_identity');
            return $order_result;
        } finally {
            self::release_lock($user_lock);
        }
    }

    private static function execute_locked()
    {
        $guard = self::inspect_contract(true);
        if (($guard['result'] ?? '') === 'already_backfilled') return $guard;
        if (($guard['result'] ?? '') !== 'ready') return $guard;

        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) return self::stopped('transaction_start_failed');

        $inside = self::inspect_contract(true);
        if (($inside['result'] ?? '') !== 'ready') {
            return self::rollback_result($inside);
        }

        $snapshot = self::projection_snapshot();
        if (!is_string($snapshot)) return self::rollback_stop('projection_encoding_failed');

        $created = PGE_Catalog_Activation_Repository::create_preparing([
            'activation_id' => self::ACTIVATION_ID,
            'user_id' => self::USER_ID,
            'plan_id' => self::PLAN_ID,
            'tier_id' => self::TIER_ID,
            'plan_key' => self::PLAN_KEY,
            'tier_key' => self::TIER_KEY,
            'activation_source' => 'backfill',
            'idempotency_key' => self::IDEMPOTENCY_KEY,
            'external_order_id' => self::ORDER_ID,
            'projection_snapshot' => $snapshot,
        ]);
        if (($created['result'] ?? '') !== 'created') {
            return self::rollback_stop('activation_create_' . self::safe_reason($created));
        }

        $catalog_activation_id = (int) $created['id'];
        $origin = PGE_Catalog_Provider_Origin_Repository::create(
            $catalog_activation_id,
            self::PROVIDER,
            self::MERCHANT_ID,
            self::CUSTOMER_ID,
            self::ORDER_ID
        );
        if (($origin['result'] ?? '') !== 'created') {
            return self::rollback_stop('origin_create_' . self::safe_reason($origin));
        }

        $cas = PGE_Catalog_Activation_Repository::compare_and_swap_state(
            $catalog_activation_id,
            PGE_Catalog_Activation_Repository::STATE_PREPARING,
            1,
            PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND,
            ['activated_at' => self::ACTIVATED_AT, 'next_reconcile_at' => null, 'last_error_code' => null]
        );
        if (($cas['result'] ?? '') !== 'updated') {
            return self::rollback_stop('activation_cas_' . self::safe_reason($cas));
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return self::stopped('storage_uncertain');
        }

        return [
            'result' => 'backfilled',
            'activation_id' => self::ACTIVATION_ID,
            'catalog_activation_id' => $catalog_activation_id,
            'lifecycle_state' => PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND,
            'membership_projection' => 'not_requested',
        ];
    }

    private static function inspect_contract($classify_existing)
    {
        // A revocation tombstone is authoritative even when an otherwise exact
        // durable backfill already exists. This check precedes replay detection
        // and is repeated under the canonical order lock.
        self::clear_database_error();
        $tombstone = PGE_Catalog_Order_Revocation_Repository::find(self::PROVIDER, self::MERCHANT_ID, self::ORDER_ID);
        if (is_array($tombstone)) return self::stopped('revocation_tombstone_exists');
        if (self::database_failed()) return self::stopped('tombstone_lookup_failed');

        self::clear_database_error();
        $by_activation = PGE_Catalog_Activation_Repository::find_by_activation_id(self::ACTIVATION_ID);
        if (self::database_failed()) return self::stopped('activation_lookup_failed');
        self::clear_database_error();
        $by_idempotency = PGE_Catalog_Activation_Repository::find_by_idempotency_key(self::IDEMPOTENCY_KEY);
        if (self::database_failed()) return self::stopped('idempotency_lookup_failed');
        self::clear_database_error();
        $origin = PGE_Catalog_Provider_Origin_Repository::find_by_order(self::PROVIDER, self::MERCHANT_ID, self::ORDER_ID);
        if (self::database_failed()) return self::stopped('origin_lookup_failed');

        if (is_array($by_activation) || is_array($by_idempotency) || is_array($origin)) {
            return $classify_existing
                ? self::classify_existing($by_activation, $by_idempotency, $origin)
                : ['result' => 'durable_state_present'];
        }

        // These mutable historical values are creation preconditions only.
        // Once the durable record exists, replay is decided solely from the
        // canonical durable contract above.
        if (!get_user_by('id', self::USER_ID)) return self::stopped('user_not_found');
        foreach (self::expected_meta() as $key => $expected) {
            $actual = get_user_meta(self::USER_ID, $key, true);
            if (!self::meta_matches($actual, $expected)) {
                return self::stopped('user_meta_mismatch', ['meta_key' => $key]);
            }
        }

        $event_id = self::find_event_for_cycle();
        if ($event_id === false) return self::stopped('event_lookup_failed');
        if ($event_id > 0) return self::stopped('event_exists_for_cycle', ['event_id' => $event_id]);

        return ['result' => 'ready'];
    }

    private static function classify_existing($by_activation, $by_idempotency, $origin)
    {
        if (!is_array($by_activation) || !is_array($by_idempotency) || !is_array($origin)) {
            return self::stopped('partial_existing_state');
        }
        if ((int) $by_activation['id'] !== (int) $by_idempotency['id']) {
            return self::stopped('activation_identity_conflict');
        }
        if (!self::activation_matches($by_activation) || !self::origin_matches($origin, $by_activation)) {
            return self::stopped('existing_state_mismatch');
        }
        $event_id = self::find_event_for_cycle();
        if ($event_id === false) return self::stopped('event_lookup_failed');
        if ($event_id > 0) return self::stopped('event_exists_for_cycle', ['event_id' => $event_id]);
        self::clear_database_error();
        $bindings = PGE_Catalog_Event_Binding_Repository::find_by_activation_id((int) $by_activation['id']);
        if (self::database_failed()) return self::stopped('binding_lookup_failed');
        if (!empty($bindings)) return self::stopped('event_binding_exists');
        if ((string) ($by_activation['projection_snapshot'] ?? '') !== self::projection_snapshot()) {
            return self::stopped('projection_snapshot_mismatch');
        }
        return [
            'result' => 'already_backfilled',
            'activation_id' => self::ACTIVATION_ID,
            'catalog_activation_id' => (int) $by_activation['id'],
            'lifecycle_state' => PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND,
            'membership_projection' => 'not_requested',
        ];
    }

    private static function activation_matches(array $row)
    {
        return (string) ($row['activation_id'] ?? '') === self::ACTIVATION_ID
            && (int) ($row['user_id'] ?? 0) === self::USER_ID
            && (int) ($row['plan_id'] ?? 0) === self::PLAN_ID
            && (int) ($row['tier_id'] ?? 0) === self::TIER_ID
            && (string) ($row['plan_key'] ?? '') === self::PLAN_KEY
            && (string) ($row['tier_key'] ?? '') === self::TIER_KEY
            && (string) ($row['activation_source'] ?? '') === 'backfill'
            && (string) ($row['idempotency_key'] ?? '') === self::IDEMPOTENCY_KEY
            && (string) ($row['external_order_id'] ?? '') === self::ORDER_ID
            && (string) ($row['lifecycle_state'] ?? '') === PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND
            && (int) ($row['revision'] ?? 0) === 2
            && (int) ($row['projection_attempts'] ?? -1) === 0
            && (string) ($row['activated_at'] ?? '') === self::ACTIVATED_AT
            && self::is_null_field($row, 'ended_at')
            && self::is_null_field($row, 'revoked_at')
            && self::is_null_field($row, 'last_error_code')
            && self::is_null_field($row, 'next_reconcile_at')
            && self::is_null_field($row, 'last_reconciled_at');
    }

    private static function is_null_field(array $row, $field)
    {
        return array_key_exists($field, $row) && $row[$field] === null;
    }

    private static function origin_matches(array $origin, array $activation)
    {
        return (int) ($origin['catalog_activation_id'] ?? 0) === (int) ($activation['id'] ?? 0)
            && (string) ($origin['provider'] ?? '') === self::PROVIDER
            && (int) ($origin['merchant_id'] ?? 0) === self::MERCHANT_ID
            && (string) ($origin['external_customer_id'] ?? '') === self::CUSTOMER_ID
            && (string) ($origin['external_order_id'] ?? '') === self::ORDER_ID;
    }

    private static function projection_snapshot()
    {
        $meta = self::expected_meta();
        $initial_used = [
            '_mon_invitation_credit_used' => (int) $meta['_mon_invitation_credit_used'],
            '_mon_replacement_credit_used' => (int) $meta['_mon_replacement_credit_used'],
        ];
        unset($meta['_mon_invitation_credit_used'], $meta['_mon_replacement_credit_used']);
        return wp_json_encode([
            'meta' => $meta,
            'credit_cycle' => ['id' => self::ACTIVATION_ID, 'initial_used' => $initial_used],
            'delete' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function expected_meta()
    {
        return [
            '_mon_package_source' => 'catalog',
            '_mon_catalog_plan_id' => self::PLAN_ID,
            '_mon_catalog_tier_id' => self::TIER_ID,
            '_mon_catalog_plan_key' => self::PLAN_KEY,
            '_mon_catalog_tier_key' => self::TIER_KEY,
            '_mon_package_status' => 'active',
            '_mon_credit_cycle_id' => self::ACTIVATION_ID,
            '_mon_last_order_id' => self::ORDER_ID,
            '_mon_salla_product_id' => self::PRODUCT_ID,
            '_mon_package_activated_at' => self::ACTIVATED_AT,
            '_mon_package_price' => '0.00',
            '_mon_package_currency' => 'SAR',
            '_mon_guest_limit' => 100,
            '_mon_event_quota_mode' => 'limited',
            '_mon_event_quota_limit' => 1,
            '_mon_invitation_credit_total' => 100,
            '_mon_invitation_credit_used' => 0,
            '_mon_replacement_credit_total' => 40,
            '_mon_replacement_credit_used' => 0,
        ];
    }

    private static function find_event_for_cycle()
    {
        global $wpdb;
        self::clear_database_error();
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value = %s LIMIT 1",
            'pge_event', '_pge_event_activation_id', self::ACTIVATION_ID
        ));
        if (self::database_failed()) return false;
        return $id === null ? 0 : (int) $id;
    }

    private static function meta_matches($actual, $expected)
    {
        if (is_int($expected)) return is_scalar($actual) && !is_bool($actual) && (string) (int) $actual === (string) $expected;
        return is_scalar($actual) && !is_bool($actual) && (string) $actual === (string) $expected;
    }

    private static function acquire_lock($name)
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 5)) === 1;
    }

    private static function release_lock($name)
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    private static function rollback_stop($reason)
    {
        return self::rollback_result(self::stopped($reason));
    }

    private static function rollback_result(array $result)
    {
        global $wpdb;
        if ($wpdb->query('ROLLBACK') === false) return self::stopped('storage_uncertain');
        return $result;
    }

    private static function safe_reason(array $result)
    {
        $reason = (string) ($result['reason'] ?? $result['result'] ?? 'failed');
        $reason = preg_replace('/[^a-z0-9_\-]/', '', strtolower($reason));
        return $reason === '' ? 'failed' : $reason;
    }

    private static function clear_database_error()
    {
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) $wpdb->last_error = '';
    }

    private static function database_failed()
    {
        global $wpdb;
        return (string) ($wpdb->last_error ?? '') !== '';
    }

    private static function stopped($reason, array $context = [])
    {
        return array_merge(['result' => 'stopped', 'reason' => $reason], $context);
    }
}
