<?php
if (!defined('ABSPATH')) exit;

/** Phase 3 orchestration for binding Catalog events to durable activations. */
final class PGE_Catalog_Event_Binding_Service
{
    public static function resolve_current($user_id)
    {
        $user_id = absint($user_id);
        if (!$user_id || (string) get_user_meta($user_id, '_mon_package_source', true) !== 'catalog') {
            return self::error('event_activation_invalid');
        }
        if ((string) get_user_meta($user_id, '_mon_package_status', true) !== 'active') {
            return self::error('event_activation_inactive');
        }
        $activation_id = trim((string) get_user_meta($user_id, '_mon_credit_cycle_id', true));
        if ($activation_id === '') return self::error('event_activation_missing');
        $row = PGE_Catalog_Activation_Repository::find_by_activation_id($activation_id);
        if (!is_array($row)) return self::error('event_activation_missing');
        if ((int) ($row['user_id'] ?? 0) !== $user_id
            || (int) ($row['plan_id'] ?? 0) !== absint(get_user_meta($user_id, '_mon_catalog_plan_id', true))
            || (int) ($row['tier_id'] ?? 0) !== absint(get_user_meta($user_id, '_mon_catalog_tier_id', true))) {
            return self::error('event_activation_mismatch');
        }
        $state = (string) ($row['lifecycle_state'] ?? '');
        if (!in_array($state, [PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND, PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND], true)) {
            return self::error('event_activation_' . ($state === '' ? 'invalid' : $state));
        }
        $checked = self::validate_existing_bindings($row);
        if (is_wp_error($checked)) return $checked;
        $result = ['result' => 'ready', 'activation' => $row, 'bindings' => $checked];
        if ((string) ($row['plan_key'] ?? '') === 'halwa_plus' && count($checked) === 1) {
            $event_id = (int) $checked[0]['event_id'];
            if (!function_exists('get_post') || get_post($event_id)) {
                $result['existing_event_id'] = $event_id;
            } else {
                // A permanently deleted Plus event still consumes its durable
                // activation. The binding is history, not reclaimable quota.
                $result['binding_consumed'] = true;
            }
        }
        return $result;
    }

    public static function lock_name($activation_id)
    {
        return 'pge_event_bind_' . md5(trim((string) $activation_id));
    }

    public static function operation_lock_name($operation_id)
    {
        return 'pge_event_operation_' . md5(strtolower(trim((string) $operation_id)));
    }

    public static function valid_operation_id($operation_id)
    {
        return is_string($operation_id) && (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            strtolower(trim($operation_id))
        );
    }

    /** Resolve an event-creation retry by its stable client operation UUID. */
    public static function resolve_creation_operation(array $activation, $user_id, $operation_id)
    {
        $user_id = absint($user_id);
        $operation_id = is_string($operation_id) ? strtolower(trim($operation_id)) : '';
        if (!$user_id || !self::valid_operation_id($operation_id)) return self::error('event_creation_operation_invalid');
        $events = get_posts([
            'post_type' => 'pge_event',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'trash', 'future'],
            'posts_per_page' => 2,
            'fields' => 'ids',
            'meta_key' => '_pge_event_creation_operation_id',
            'meta_value' => $operation_id,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);
        if (empty($events)) return ['result' => 'new', 'operation_id' => $operation_id];
        if (count($events) !== 1) return self::error('event_creation_operation_conflict');
        $event_id = absint(is_object($events[0]) ? $events[0]->ID : $events[0]);
        if (!$event_id
            || (int) get_post_field('post_author', $event_id) !== $user_id
            || (int) ($activation['user_id'] ?? 0) !== $user_id
            || (string) get_post_meta($event_id, '_pge_event_activation_id', true) !== (string) ($activation['activation_id'] ?? '')
            || (string) get_post_meta($event_id, '_pge_event_creation_operation_id', true) !== $operation_id) {
            return self::error('event_creation_operation_conflict');
        }
        return ['result' => 'existing', 'event_id' => $event_id, 'operation_id' => $operation_id];
    }

    public static function bind_event(array $activation, $event_id)
    {
        $id = absint($activation['id'] ?? 0);
        $event_id = absint($event_id);
        $activation_id = trim((string) ($activation['activation_id'] ?? ''));
        if (!$id || !$event_id || $activation_id === '') return self::error('event_binding_invalid');
        if ((string) get_post_meta($event_id, '_pge_event_activation_id', true) !== $activation_id) {
            return self::error('event_activation_meta_mismatch');
        }
        if (!self::begin()) return self::error('event_binding_storage_unavailable');
        $locked = PGE_Catalog_Activation_Repository::find_by_id_for_update($id);
        if (!is_array($locked)) { self::rollback(); return self::error('event_binding_missing'); }
        $state = (string) ($locked['lifecycle_state'] ?? '');
        if (in_array($state, [PGE_Catalog_Activation_Repository::STATE_ENDED, PGE_Catalog_Activation_Repository::STATE_REVOKED], true)) {
            self::rollback(); return self::error('event_binding_terminal');
        }
        if (!in_array($state, [PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND, PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND], true)) {
            self::rollback(); return self::error('event_binding_state');
        }
        $lifecycle_metadata = self::initial_lifecycle_metadata($locked, $event_id);
        $event_binding = PGE_Catalog_Event_Binding_Repository::find_by_event_id($event_id);
        if (is_array($event_binding)) {
            if ((int) ($event_binding['catalog_activation_id'] ?? 0) !== $id) {
                self::rollback(); return self::error('event_binding_conflict');
            }
            $finished = self::ensure_bound_state($locked, $lifecycle_metadata);
            if (is_wp_error($finished)) { self::rollback(); return $finished; }
            if (!self::commit()) { self::rollback(); return self::error('event_binding_storage_uncertain'); }
            return ['result' => 'already_bound', 'event_id' => $event_id, 'activation_id' => $activation_id, 'lifecycle' => $finished];
        }
        $bindings = PGE_Catalog_Event_Binding_Repository::find_by_activation_id($id);
        if ((string) ($locked['plan_key'] ?? '') === 'halwa_plus' && !empty($bindings)) {
            self::rollback(); return self::error('plus_activation_already_bound');
        }
        $made = PGE_Catalog_Event_Binding_Repository::create($id, $event_id);
        if (($made['result'] ?? '') !== 'created') {
            self::rollback();
            $winner = PGE_Catalog_Event_Binding_Repository::find_by_event_id($event_id);
            if (is_array($winner) && (int) ($winner['catalog_activation_id'] ?? 0) === $id) {
                return self::bind_event($activation, $event_id);
            }
            return self::error(($made['result'] ?? '') === 'db_error' ? 'event_binding_db_error' : 'event_binding_conflict');
        }
        $finished = self::ensure_bound_state($locked, $lifecycle_metadata);
        if (is_wp_error($finished)) { self::rollback(); return $finished; }
        if (!self::commit()) { self::rollback(); return self::error('event_binding_storage_uncertain'); }
        return ['result' => 'bound', 'event_id' => $event_id, 'activation_id' => $activation_id, 'lifecycle' => $finished];
    }

    /** Compensate only the post created by the current failed operation. */
    public static function compensate_new_event($event_id, $activation_id, $operation_id, $created_this_invocation)
    {
        $event_id = absint($event_id);
        $activation_id = trim((string) $activation_id);
        $operation_id = is_string($operation_id) ? strtolower(trim($operation_id)) : '';
        if (!$created_this_invocation) return ['result' => 'not_created_here'];
        if (!$event_id || $activation_id === ''
            || !self::valid_operation_id($operation_id)
            || (string) get_post_meta($event_id, '_pge_event_creation_operation_id', true) !== $operation_id
            || (string) get_post_meta($event_id, '_pge_event_activation_id', true) !== $activation_id) {
            return ['result' => 'recovery_incident'];
        }
        return wp_delete_post($event_id, true)
            ? ['result' => 'compensated']
            : ['result' => 'recovery_incident'];
    }

    private static function validate_existing_bindings(array $activation)
    {
        $rows = PGE_Catalog_Event_Binding_Repository::find_by_activation_id((int) $activation['id']);
        foreach ($rows as $row) {
            $event_id = (int) $row['event_id'];
            $post_exists = !function_exists('get_post') || get_post($event_id);
            if ($post_exists && (string) get_post_meta($event_id, '_pge_event_activation_id', true) !== (string) $activation['activation_id']) {
                return self::error('event_binding_ownership_mismatch');
            }
        }
        if ((string) ($activation['plan_key'] ?? '') === 'halwa_plus' && count($rows) > 1) {
            return self::error('plus_activation_binding_conflict');
        }
        return $rows;
    }

    private static function ensure_bound_state(array $locked, $lifecycle_metadata)
    {
        if ($lifecycle_metadata === null) {
            if ((string) $locked['lifecycle_state'] === PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND) return ['result' => 'lifecycle_unavailable'];
            $cas = PGE_Catalog_Activation_Repository::compare_and_swap_state(
                (int) $locked['id'], PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND,
                (int) $locked['revision'], PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND
            );
            return ($cas['result'] ?? '') === 'updated'
                ? ['result' => 'lifecycle_unavailable']
                : self::error('event_binding_cas_' . ($cas['result'] ?? 'db_error'));
        }
        if ((string) $locked['lifecycle_state'] === PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND) {
            if ((string) ($locked['plan_key'] ?? '') !== 'halwa_plus' || !empty($locked['next_reconcile_at'])) return ['result' => 'already_anchored'];
            $repair = PGE_Catalog_Activation_Repository::update_reconciliation(
                (int) $locked['id'], (int) $locked['revision'], $lifecycle_metadata
            );
            if (($repair['result'] ?? '') === 'updated') return ['result' => 'repaired', 'revision' => $repair['revision'] ?? null];
            return self::error('event_binding_lifecycle_' . ($repair['result'] ?? 'db_error'));
        }
        $cas = PGE_Catalog_Activation_Repository::compare_and_swap_state(
            (int) $locked['id'], PGE_Catalog_Activation_Repository::STATE_ACTIVE_UNBOUND,
            (int) $locked['revision'], PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND,
            (string) ($locked['plan_key'] ?? '') === 'halwa_plus' ? $lifecycle_metadata : []
        );
        if (($cas['result'] ?? '') === 'updated') return ['result' => 'anchored', 'revision' => $cas['revision'] ?? null];
        if (($cas['result'] ?? '') === 'stale') {
            $current = PGE_Catalog_Activation_Repository::find_by_id((int) $locked['id']);
            if (is_array($current) && (string) ($current['lifecycle_state'] ?? '') === PGE_Catalog_Activation_Repository::STATE_ACTIVE_BOUND
                && ((string) ($current['plan_key'] ?? '') !== 'halwa_plus' || !empty($current['next_reconcile_at']))) {
                return ['result' => 'already_anchored'];
            }
        }
        return self::error('event_binding_cas_' . ($cas['result'] ?? 'db_error'));
    }

    private static function initial_lifecycle_metadata(array $activation, $event_id)
    {
        if ((string) ($activation['plan_key'] ?? '') !== 'halwa_plus') return [];
        if (!class_exists('PGE_Catalog_Event_Lifecycle_Service')) return null;
        return PGE_Catalog_Event_Lifecycle_Service::initial_binding_metadata($event_id);
    }

    private static function begin(){ global $wpdb; return $wpdb->query('START TRANSACTION') !== false; }
    private static function commit(){ global $wpdb; return $wpdb->query('COMMIT') !== false; }
    private static function rollback(){ global $wpdb; return $wpdb->query('ROLLBACK') !== false; }
    private static function error($code){ return new WP_Error($code, 'تعذر ربط المناسبة بالتفعيل الحالي بأمان.'); }
}
