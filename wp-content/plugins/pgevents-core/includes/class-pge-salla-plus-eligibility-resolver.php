<?php
if (!defined('ABSPATH')) exit;

/** Aggregate durable Salla Plus eligibility and desired-state projection. */
final class PGE_Salla_Plus_Eligibility_Resolver
{
    const GROUP_ID = 225189340;
    const RECOVERY_CURSOR_OPTION = 'pge_salla_plus_eligibility_recovery_cursor';
    const RECOVERY_LOCK = 'pge_salla_plus_eligibility_recovery';
    const LOCK_TIMEOUT_SECONDS = 5;

    public static function resolve($merchant_id, $customer_id)
    {
        $merchant_id = absint($merchant_id);
        $customer_id = absint($customer_id);
        if (!$merchant_id || !$customer_id) {
            return ['result' => 'error', 'authoritative' => false, 'eligible' => null, 'merchant_id' => $merchant_id, 'salla_customer_id' => $customer_id, 'active_activation_count' => null, 'reason' => 'invalid_identity'];
        }
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) $wpdb->last_error = '';
        $raw_count = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . PGE_Catalog_Activation_Schema::activations_table() . ' a INNER JOIN ' . PGE_Catalog_Activation_Schema::origins_table() . ' o ON o.catalog_activation_id = a.id WHERE o.provider = %s AND o.merchant_id = %d AND o.external_customer_id = %s AND a.plan_key = %s AND a.lifecycle_state IN (%s,%s)',
            'salla', $merchant_id, (string) $customer_id, 'halwa_plus',
            PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND,
            PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND
        ));
        if ($raw_count === false || $raw_count === null || (string) ($wpdb->last_error ?? '') !== '' || !is_numeric($raw_count)) {
            return ['result' => 'error', 'authoritative' => false, 'eligible' => null, 'merchant_id' => $merchant_id, 'salla_customer_id' => $customer_id, 'active_activation_count' => null, 'reason' => 'eligibility_query_failed'];
        }
        $count = (int) $raw_count;
        return ['result' => 'resolved', 'authoritative' => true, 'eligible' => $count > 0, 'merchant_id' => $merchant_id, 'salla_customer_id' => $customer_id, 'active_activation_count' => $count, 'reason' => $count > 0 ? 'active_salla_plus' : 'no_active_salla_plus'];
    }

    /**
     * Canonical Salla membership boundary. Lock order is user/event activation
     * lock first, then this identity lock. A holder must never acquire a
     * user/event activation lock, preventing X->Y/Y->X deadlocks.
     */
    public static function with_identity_lock($merchant_id, $customer_id, callable $callback, $timeout = self::LOCK_TIMEOUT_SECONDS)
    {
        $merchant_id = absint($merchant_id); $customer_id = absint($customer_id);
        if (!$merchant_id || !$customer_id) return ['result' => 'error', 'reason' => 'invalid_identity'];
        global $wpdb;
        $lock = PGE_Salla_Membership_Sync_Store::identity_lock_name($merchant_id, $customer_id, self::GROUP_ID);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, max(0, (int) $timeout))) !== 1) {
            return ['result' => 'error', 'reason' => 'lock_not_acquired'];
        }
        try { return $callback($merchant_id, $customer_id); }
        finally { $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    /** Serialize a Salla-origin Plus mutation; other activations need no membership lock. */
    public static function with_activation_identity_lock($activation_id, callable $callback)
    {
        $activation_id = absint($activation_id);
        $activation = $activation_id ? PGE_Catalog_Activation_Repository::find_by_id($activation_id) : null;
        if (!is_array($activation)) return ['result' => 'error', 'reason' => 'activation_missing'];
        if ((string) ($activation['plan_key'] ?? '') !== 'halwa_plus' || (string) ($activation['activation_source'] ?? '') !== 'salla') return $callback(null);
        $origin = PGE_Catalog_Provider_Origin_Repository::find_by_activation_and_provider($activation_id, 'salla');
        if (!is_array($origin)) return ['result' => 'error', 'reason' => 'activation_origin_missing'];
        return self::with_identity_lock((int) $origin['merchant_id'], (int) $origin['external_customer_id'], function ($merchant_id, $customer_id) use ($callback) {
            return $callback(['merchant_id' => $merchant_id, 'salla_customer_id' => $customer_id]);
        });
    }

    public static function recompute_and_project($merchant_id, $customer_id)
    {
        return self::with_identity_lock($merchant_id, $customer_id, function ($locked_merchant_id, $locked_customer_id) {
            return self::recompute_and_project_locked($locked_merchant_id, $locked_customer_id);
        });
    }

    /**
     * Caller holds the canonical identity lock. not_member is a mutable
     * projection, never permanent removal authorization. Future removal must
     * reacquire this lock, verify revision/state, and recompute first.
     */
    public static function recompute_and_project_locked($merchant_id, $customer_id)
    {
        $eligibility = self::resolve($merchant_id, $customer_id);
        if (empty($eligibility['authoritative'])) return ['result' => 'error', 'reason' => (string) ($eligibility['reason'] ?? 'eligibility_query_failed')];
        $desired = $eligibility['eligible'] ? PGE_Salla_Membership_Sync_Store::DESIRED_MEMBER : PGE_Salla_Membership_Sync_Store::DESIRED_NOT_MEMBER;
        $stored = PGE_Salla_Membership_Sync_Store::request_state_locked((int) $eligibility['merchant_id'], (int) $eligibility['salla_customer_id'], self::GROUP_ID, $desired);
        return array_merge(['result' => ($stored['result'] ?? 'error'), 'desired_state' => $desired, 'projection' => $stored], $eligibility);
    }

    public static function project_activation($activation_id)
    {
        $origin = PGE_Catalog_Provider_Origin_Repository::find_by_activation_and_provider(absint($activation_id), 'salla');
        if (!is_array($origin)) return ['result' => 'not_applicable'];
        return self::recompute_and_project((int) $origin['merchant_id'], (int) $origin['external_customer_id']);
    }

    /** Durable high-water recovery; failed identities are revisited after wrap. */
    public static function recover($limit = 50)
    {
        global $wpdb; $limit = max(1, min(50, (int) $limit));
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::RECOVERY_LOCK, self::LOCK_TIMEOUT_SECONDS)) !== 1) return [['result' => 'error', 'reason' => 'recovery_lock_not_acquired']];
        try {
            $cursor = max(0, (int) get_option(self::RECOVERY_CURSOR_OPTION, 0));
            $rows = self::recovery_page_after($cursor, $limit);
            if (!$rows && $cursor > 0) {
                update_option(self::RECOVERY_CURSOR_OPTION, 0, false);
                $rows = self::recovery_page_after(0, $limit);
            }
            if (!$rows) return [];
            update_option(self::RECOVERY_CURSOR_OPTION, (int) $rows[count($rows) - 1]['first_origin_id'], false);
            $results = [];
            foreach ($rows as $row) $results[] = self::recompute_and_project((int) $row['merchant_id'], (int) $row['external_customer_id']);
            return $results;
        } finally { $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::RECOVERY_LOCK)); }
    }

    private static function recovery_page_after($cursor, $limit)
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT o.merchant_id,o.external_customer_id,MIN(o.id) first_origin_id FROM ' . PGE_Catalog_Activation_Schema::origins_table() . ' o INNER JOIN ' . PGE_Catalog_Activation_Schema::activations_table() . ' a ON a.id=o.catalog_activation_id WHERE o.provider=%s AND a.plan_key=%s GROUP BY o.merchant_id,o.external_customer_id HAVING MIN(o.id) > %d ORDER BY first_origin_id ASC LIMIT %d',
            'salla', 'halwa_plus', max(0, (int) $cursor), $limit
        ), ARRAY_A);
    }
}
