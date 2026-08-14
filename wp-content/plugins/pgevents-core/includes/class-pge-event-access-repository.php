<?php
if (!defined('ABSPATH')) exit;

/**
 * Read and transactional group-write access to the five event-access tables.
 *
 * The repository is deliberately static: it owns no request or user state,
 * performs no authorization, and executes SQL only after the schema-readiness
 * and event-scope guards pass. Group mutations and their audit records share
 * one transaction; the remaining domains stay read-only in this phase.
 */
class PGE_Event_Access_Repository
{
    const DEFAULT_PER_PAGE = 20;
    const MAX_PER_PAGE = 100;
    const MAX_PHONE_BATCH = 200;

    const GROUP_STATUSES = ['active', 'archived'];
    const MEMBERSHIP_STATUSES = ['active', 'revoked'];
    const MEMBERSHIP_ROLES = ['manager', 'viewer'];
    const AUDIT_ENTITY_TYPES = ['group', 'membership', 'group_access', 'guest_assignment', 'event'];
    const AUDIT_ACTIONS = [
        'group_created',
        'group_renamed',
        'group_archived',
        'default_group_changed',
        'membership_created',
        'membership_reactivated',
        'membership_role_changed',
        'membership_revoked',
        'group_access_granted',
        'group_access_revoked',
        'guest_group_assigned',
        'guest_group_moved',
        'guest_group_unassigned',
    ];

    const GROUP_WRITE_AUDIT_ACTIONS = [
        'group_created',
        'group_renamed',
        'default_group_changed',
        'group_archived',
    ];

    public static function create_group($event_id, $name, $actor_user_id, $make_default = false)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($actor_user_id) || !is_bool($make_default)) return self::invalid_input();
        $name = self::normalize_group_name_input($name);
        if ($name instanceof WP_Error) return $name;

        return self::with_group_transaction($event_id, function (array $groups) use ($event_id, $name, $actor_user_id, $make_default) {
            $duplicate = self::find_locked_group_by_key($groups, $name['name_key']);
            if ($duplicate !== null) return self::duplicate_group();

            $previous_default = self::locked_default_group($groups);
            if ($previous_default instanceof WP_Error) return $previous_default;
            $now = current_time('mysql', true);

            if ($make_default && $previous_default !== null) {
                $cleared = self::update_group_default_slot($event_id, $previous_default['id'], null, $now);
                if ($cleared instanceof WP_Error) return $cleared;
            }

            $table = self::table('groups');
            if ($table instanceof WP_Error) return $table;
            $slot_sql = $make_default ? '%d' : 'NULL';
            $args = [$event_id, $name['name'], $name['name_key'], 'active'];
            if ($make_default) $args[] = 1;
            $args = array_merge($args, [$actor_user_id, $now, $now]);
            global $wpdb;
            $wpdb->insert_id = 0;
            $inserted = self::mutation_query(
                "INSERT INTO $table (event_id, name, name_key, status, default_slot, created_by_user_id, created_at, updated_at, archived_at) VALUES (%d, %s, %s, %s, $slot_sql, %d, %s, %s, NULL)",
                $args
            );
            if ($inserted instanceof WP_Error) {
                return self::translate_duplicate_after_failure($event_id, $name['name_key'], null, $inserted);
            }
            if ($inserted !== 1) return self::database_error();

            $group_id = $wpdb->insert_id ?? null;
            if (!is_int($group_id) || $group_id <= 0) return self::database_error();

            $audit = self::insert_group_audit($event_id, $actor_user_id, 'group_created', $group_id, null, $now);
            if ($audit instanceof WP_Error) return $audit;
            if ($make_default) {
                $metadata = ['previous_group_id' => $previous_default === null ? null : $previous_default['id']];
                $audit = self::insert_group_audit($event_id, $actor_user_id, 'default_group_changed', $group_id, $metadata, $now);
                if ($audit instanceof WP_Error) return $audit;
            }

            $group = self::read_group_inside_transaction($event_id, $group_id);
            if ($group instanceof WP_Error) return $group;
            if ($group['name'] !== $name['name']
                || $group['name_key'] !== $name['name_key']
                || $group['status'] !== 'active'
                || $group['is_default'] !== $make_default
                || $group['created_by_user_id'] !== $actor_user_id
                || $group['created_at'] !== $now
                || $group['updated_at'] !== $now
                || $group['archived_at'] !== null) {
                return self::database_error();
            }
            return ['changed' => true, 'group' => $group];
        });
    }

    public static function rename_group($event_id, $group_id, $expected_name, $new_name, $actor_user_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($group_id) || !self::valid_id($actor_user_id)) return self::invalid_input();
        $expected = self::normalize_group_name_input($expected_name);
        if ($expected instanceof WP_Error) return $expected;
        $new = self::normalize_group_name_input($new_name);
        if ($new instanceof WP_Error) return $new;

        return self::with_group_transaction($event_id, function (array $groups) use ($event_id, $group_id, $expected, $new, $actor_user_id) {
            $group = self::find_locked_group_by_id($groups, $group_id);
            if ($group === null) return self::not_found();
            if ($group['status'] !== 'active') return self::invalid_state();
            if ($group['name'] !== $expected['name']) return self::concurrent_update();
            if ($group['name'] === $new['name'] && $group['name_key'] === $new['name_key']) {
                return ['changed' => false, 'group' => $group];
            }
            $duplicate = self::find_locked_group_by_key($groups, $new['name_key'], $group_id);
            if ($duplicate !== null) return self::duplicate_group();

            $now = current_time('mysql', true);
            $table = self::table('groups');
            if ($table instanceof WP_Error) return $table;
            $updated = self::mutation_query(
                "UPDATE $table SET name = %s, name_key = %s, updated_at = %s WHERE event_id = %d AND id = %d AND status = %s",
                [$new['name'], $new['name_key'], $now, $event_id, $group_id, 'active']
            );
            if ($updated instanceof WP_Error) {
                return self::translate_duplicate_after_failure($event_id, $new['name_key'], $group_id, $updated);
            }
            if ($updated !== 1) return $updated === 0 ? self::concurrent_update() : self::database_error();
            $audit = self::insert_group_audit($event_id, $actor_user_id, 'group_renamed', $group_id, null, $now);
            if ($audit instanceof WP_Error) return $audit;
            $group = self::read_group_inside_transaction($event_id, $group_id);
            if ($group instanceof WP_Error) return $group;
            return ['changed' => true, 'group' => $group];
        });
    }

    public static function set_default_group($event_id, $group_id, $actor_user_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($group_id) || !self::valid_id($actor_user_id)) return self::invalid_input();

        return self::with_group_transaction($event_id, function (array $groups) use ($event_id, $group_id, $actor_user_id) {
            $target = self::find_locked_group_by_id($groups, $group_id);
            if ($target === null) return self::not_found();
            if ($target['status'] !== 'active') return self::invalid_state();
            $previous = self::locked_default_group($groups);
            if ($previous instanceof WP_Error) return $previous;
            if ($previous !== null && $previous['id'] === $group_id) {
                return ['changed' => false, 'group' => $target];
            }

            $now = current_time('mysql', true);
            if ($previous !== null) {
                $cleared = self::update_group_default_slot($event_id, $previous['id'], null, $now);
                if ($cleared instanceof WP_Error) return $cleared;
            }
            $set = self::update_group_default_slot($event_id, $group_id, 1, $now);
            if ($set instanceof WP_Error) return $set;
            $metadata = ['previous_group_id' => $previous === null ? null : $previous['id']];
            $audit = self::insert_group_audit($event_id, $actor_user_id, 'default_group_changed', $group_id, $metadata, $now);
            if ($audit instanceof WP_Error) return $audit;
            $group = self::read_group_inside_transaction($event_id, $group_id);
            if ($group instanceof WP_Error) return $group;
            return ['changed' => true, 'group' => $group];
        });
    }

    public static function archive_group($event_id, $group_id, $actor_user_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($group_id) || !self::valid_id($actor_user_id)) return self::invalid_input();

        return self::with_group_transaction($event_id, function (array $groups) use ($event_id, $group_id, $actor_user_id) {
            $target = self::find_locked_group_by_id($groups, $group_id);
            if ($target === null) return self::not_found();
            if ($target['status'] === 'archived') return ['changed' => false, 'group' => $target];

            $was_default = $target['is_default'];
            $now = current_time('mysql', true);
            $table = self::table('groups');
            if ($table instanceof WP_Error) return $table;
            $updated = self::mutation_query(
                "UPDATE $table SET status = %s, name_key = NULL, default_slot = NULL, archived_at = %s, updated_at = %s WHERE event_id = %d AND id = %d AND status = %s",
                ['archived', $now, $now, $event_id, $group_id, 'active']
            );
            if ($updated instanceof WP_Error) return $updated;
            if ($updated !== 1) return $updated === 0 ? self::concurrent_update() : self::database_error();
            $audit = self::insert_group_audit($event_id, $actor_user_id, 'group_archived', $group_id, ['was_default' => $was_default], $now);
            if ($audit instanceof WP_Error) return $audit;
            $group = self::read_group_inside_transaction($event_id, $group_id);
            if ($group instanceof WP_Error) return $group;
            return ['changed' => true, 'group' => $group];
        });
    }

    public static function get_group($event_id, $group_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($group_id)) return self::invalid_input();

        $table = self::table('groups');
        if ($table instanceof WP_Error) return $table;
        $row = self::read_optional_row(
            "SELECT * FROM $table WHERE event_id = %d AND id = %d LIMIT 2",
            [$event_id, $group_id]
        );
        if ($row instanceof WP_Error) return $row;
        if ($row === null) return self::not_found();
        return self::normalize_group($row, $event_id);
    }

    public static function list_groups($event_id, $filters = [], $page = 1, $per_page = self::DEFAULT_PER_PAGE)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        $pagination = self::validate_pagination($page, $per_page);
        if ($pagination instanceof WP_Error) return $pagination;
        if (!is_array($filters) || !self::only_filters($filters, ['status'])) return self::invalid_filter();

        $where = ['event_id = %d'];
        $args = [$event_id];
        if (array_key_exists('status', $filters)) {
            if (!is_string($filters['status']) || !in_array($filters['status'], self::GROUP_STATUSES, true)) {
                return self::invalid_filter();
            }
            $where[] = 'status = %s';
            $args[] = $filters['status'];
        }

        $table = self::table('groups');
        if ($table instanceof WP_Error) return $table;
        return self::paginate(
            $table,
            implode(' AND ', $where),
            $args,
            'ORDER BY id ASC',
            $pagination,
            function ($row) use ($event_id) { return self::normalize_group($row, $event_id); }
        );
    }

    public static function get_default_group($event_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        $table = self::table('groups');
        if ($table instanceof WP_Error) return $table;
        $row = self::read_optional_row(
            "SELECT * FROM $table WHERE event_id = %d AND default_slot = %d AND status = %s LIMIT 2",
            [$event_id, 1, 'active']
        );
        return $row instanceof WP_Error || $row === null ? $row : self::normalize_group($row, $event_id);
    }

    public static function find_active_group_by_name_key($event_id, $name_key)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!is_string($name_key)) return self::invalid_input();
        $name_key = trim($name_key);
        if ($name_key === '' || self::string_length($name_key) > 160) return self::invalid_input();

        $table = self::table('groups');
        if ($table instanceof WP_Error) return $table;
        $row = self::read_optional_row(
            "SELECT * FROM $table WHERE event_id = %d AND name_key = %s AND status = %s LIMIT 2",
            [$event_id, $name_key, 'active']
        );
        return $row instanceof WP_Error || $row === null ? $row : self::normalize_group($row, $event_id);
    }

    public static function count_groups($event_id, $status = null)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if ($status !== null && (!is_string($status) || !in_array($status, self::GROUP_STATUSES, true))) {
            return self::invalid_filter();
        }
        $table = self::table('groups');
        if ($table instanceof WP_Error) return $table;
        $where = 'event_id = %d';
        $args = [$event_id];
        if ($status !== null) {
            $where .= ' AND status = %s';
            $args[] = $status;
        }
        return self::read_count("SELECT COUNT(*) FROM $table WHERE $where", $args);
    }

    public static function get_membership($event_id, $membership_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($membership_id)) return self::invalid_input();
        return self::get_membership_scoped($event_id, $membership_id, true);
    }

    public static function get_membership_for_user($event_id, $user_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($user_id)) return self::invalid_input();
        $table = self::table('memberships');
        if ($table instanceof WP_Error) return $table;
        $row = self::read_optional_row(
            "SELECT * FROM $table WHERE event_id = %d AND user_id = %d LIMIT 2",
            [$event_id, $user_id]
        );
        return $row instanceof WP_Error || $row === null ? $row : self::normalize_membership($row, $event_id);
    }

    public static function list_memberships($event_id, $filters = [], $page = 1, $per_page = self::DEFAULT_PER_PAGE)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        $pagination = self::validate_pagination($page, $per_page);
        if ($pagination instanceof WP_Error) return $pagination;
        if (!is_array($filters) || !self::only_filters($filters, ['status', 'role'])) return self::invalid_filter();

        $where = ['event_id = %d'];
        $args = [$event_id];
        foreach (['status' => self::MEMBERSHIP_STATUSES, 'role' => self::MEMBERSHIP_ROLES] as $key => $allowed) {
            if (!array_key_exists($key, $filters)) continue;
            if (!is_string($filters[$key]) || !in_array($filters[$key], $allowed, true)) return self::invalid_filter();
            $where[] = "$key = %s";
            $args[] = $filters[$key];
        }

        $table = self::table('memberships');
        if ($table instanceof WP_Error) return $table;
        return self::paginate(
            $table,
            implode(' AND ', $where),
            $args,
            'ORDER BY id ASC',
            $pagination,
            function ($row) use ($event_id) { return self::normalize_membership($row, $event_id); }
        );
    }

    public static function list_group_ids_for_membership($event_id, $membership_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($membership_id)) return self::invalid_input();
        $membership = self::get_membership_scoped($event_id, $membership_id, true);
        if ($membership instanceof WP_Error) return $membership;

        $access = self::table('access');
        $groups = self::table('groups');
        if ($access instanceof WP_Error || $groups instanceof WP_Error) return self::database_error();
        $rows = self::read_results(
            "SELECT a.event_id, a.group_id, g.event_id AS related_event_id FROM $access a LEFT JOIN $groups g ON g.id = a.group_id WHERE a.event_id = %d AND a.membership_id = %d ORDER BY a.group_id ASC",
            [$event_id, $membership_id]
        );
        if ($rows instanceof WP_Error) return $rows;
        return self::normalize_scoped_ids($rows, $event_id, 'group_id');
    }

    public static function list_membership_ids_for_group($event_id, $group_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($group_id)) return self::invalid_input();
        $group = self::get_group_scoped($event_id, $group_id, true);
        if ($group instanceof WP_Error) return $group;

        $access = self::table('access');
        $memberships = self::table('memberships');
        if ($access instanceof WP_Error || $memberships instanceof WP_Error) return self::database_error();
        $rows = self::read_results(
            "SELECT a.event_id, a.membership_id, m.event_id AS related_event_id FROM $access a LEFT JOIN $memberships m ON m.id = a.membership_id WHERE a.event_id = %d AND a.group_id = %d ORDER BY a.membership_id ASC",
            [$event_id, $group_id]
        );
        if ($rows instanceof WP_Error) return $rows;
        return self::normalize_scoped_ids($rows, $event_id, 'membership_id');
    }

    public static function membership_has_group_access($event_id, $membership_id, $group_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($membership_id) || !self::valid_id($group_id)) return self::invalid_input();
        $membership = self::get_membership_scoped($event_id, $membership_id, true);
        if ($membership instanceof WP_Error) return $membership;
        $group = self::get_group_scoped($event_id, $group_id, true);
        if ($group instanceof WP_Error) return $group;

        $access = self::table('access');
        if ($access instanceof WP_Error) return $access;
        $row = self::read_optional_row(
            "SELECT event_id, membership_id, group_id FROM $access WHERE event_id = %d AND membership_id = %d AND group_id = %d LIMIT 2",
            [$event_id, $membership_id, $group_id]
        );
        if ($row instanceof WP_Error) return $row;
        if ($row === null) return false;
        $row_event = self::db_positive_int($row['event_id'] ?? null);
        $row_membership = self::db_positive_int($row['membership_id'] ?? null);
        $row_group = self::db_positive_int($row['group_id'] ?? null);
        if ($row_event === null || $row_membership === null || $row_group === null) return self::database_error();
        if ($row_event !== $event_id) return self::cross_event();
        if ($row_membership !== $membership_id || $row_group !== $group_id) return self::database_error();
        return true;
    }

    public static function get_guest_assignment($event_id, $guest_phone)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        $phone = self::normalize_phone_input($guest_phone);
        if ($phone instanceof WP_Error) return $phone;
        $assignments = self::table('assignments');
        $groups = self::table('groups');
        if ($assignments instanceof WP_Error || $groups instanceof WP_Error) return self::database_error();
        $row = self::read_optional_row(
            "SELECT a.*, g.event_id AS related_event_id FROM $assignments a LEFT JOIN $groups g ON g.id = a.group_id WHERE a.event_id = %d AND a.guest_phone = %s LIMIT 2",
            [$event_id, $phone]
        );
        return $row instanceof WP_Error || $row === null ? $row : self::normalize_assignment($row, $event_id);
    }

    public static function list_group_assignments($event_id, $group_id, $page = 1, $per_page = self::DEFAULT_PER_PAGE)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($group_id)) return self::invalid_input();
        $pagination = self::validate_pagination($page, $per_page);
        if ($pagination instanceof WP_Error) return $pagination;
        $group = self::get_group_scoped($event_id, $group_id, true);
        if ($group instanceof WP_Error) return $group;

        $assignments = self::table('assignments');
        $groups = self::table('groups');
        if ($assignments instanceof WP_Error || $groups instanceof WP_Error) return self::database_error();
        return self::paginate(
            "$assignments a LEFT JOIN $groups g ON g.id = a.group_id",
            'a.event_id = %d AND a.group_id = %d',
            [$event_id, $group_id],
            'ORDER BY a.id ASC',
            $pagination,
            function ($row) use ($event_id) { return self::normalize_assignment($row, $event_id); },
            'a.*, g.event_id AS related_event_id'
        );
    }

    public static function count_group_assignments($event_id, $group_id)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!self::valid_id($group_id)) return self::invalid_input();
        $group = self::get_group_scoped($event_id, $group_id, true);
        if ($group instanceof WP_Error) return $group;
        $table = self::table('assignments');
        if ($table instanceof WP_Error) return $table;
        return self::read_count(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND group_id = %d",
            [$event_id, $group_id]
        );
    }

    /**
     * Map a bounded phone list to a list of typed assignment records.
     *
     * The public result is list<array{guest_phone:string,group_id:int}> rather
     * than an associative phone map because PHP coerces numeric-string keys.
     */
    public static function map_guest_groups($event_id, $guest_phones)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        if (!is_array($guest_phones) || !self::is_list($guest_phones) || count($guest_phones) > self::MAX_PHONE_BATCH) {
            return self::invalid_input();
        }

        $phones = [];
        $seen = [];
        foreach ($guest_phones as $value) {
            $phone = self::normalize_phone_input($value);
            if ($phone instanceof WP_Error) return $phone;
            $lookup_key = 'phone:' . $phone;
            if (isset($seen[$lookup_key])) continue;
            $seen[$lookup_key] = true;
            $phones[] = $phone;
        }
        if (!$phones) return [];

        $assignments = self::table('assignments');
        $groups = self::table('groups');
        if ($assignments instanceof WP_Error || $groups instanceof WP_Error) return self::database_error();
        $placeholders = implode(', ', array_fill(0, count($phones), '%s'));
        $rows = self::read_results(
            "SELECT a.*, g.event_id AS related_event_id FROM $assignments a LEFT JOIN $groups g ON g.id = a.group_id WHERE a.event_id = %d AND a.guest_phone IN ($placeholders) ORDER BY a.id ASC",
            array_merge([$event_id], $phones)
        );
        if ($rows instanceof WP_Error) return $rows;

        $found = [];
        foreach ($rows as $row) {
            $normalized = self::normalize_assignment($row, $event_id);
            if ($normalized instanceof WP_Error) return $normalized;
            $phone = $normalized['guest_phone'];
            $lookup_key = 'phone:' . $phone;
            if (!isset($seen[$lookup_key]) || array_key_exists($lookup_key, $found)) return self::database_error();
            $found[$lookup_key] = $normalized['group_id'];
        }

        // Return a list of typed records. A normalized phone cannot safely be
        // a PHP array key because an all-digit value may be coerced to int.
        $map = [];
        foreach ($phones as $phone) {
            $lookup_key = 'phone:' . $phone;
            if (array_key_exists($lookup_key, $found)) {
                $map[] = [
                    'guest_phone' => $phone,
                    'group_id' => $found[$lookup_key],
                ];
            }
        }
        return $map;
    }

    public static function list_audit($event_id, $filters = [], $page = 1, $per_page = self::DEFAULT_PER_PAGE)
    {
        $guard = self::guard_event($event_id);
        if ($guard instanceof WP_Error) return $guard;
        $pagination = self::validate_pagination($page, $per_page);
        if ($pagination instanceof WP_Error) return $pagination;
        $allowed_filters = ['action', 'entity_type', 'actor_user_id', 'entity_id'];
        if (!is_array($filters) || !self::only_filters($filters, $allowed_filters)) return self::invalid_filter();

        $where = ['event_id = %d'];
        $args = [$event_id];
        if (array_key_exists('action', $filters)) {
            $action = $filters['action'];
            if (!is_string($action) || $action === '' || strlen($action) > 40 || !in_array($action, self::AUDIT_ACTIONS, true)) {
                return self::invalid_filter();
            }
            $where[] = 'action = %s';
            $args[] = $action;
        }
        if (array_key_exists('entity_type', $filters)) {
            $type = $filters['entity_type'];
            if (!is_string($type) || !in_array($type, self::AUDIT_ENTITY_TYPES, true)) return self::invalid_filter();
            $where[] = 'entity_type = %s';
            $args[] = $type;
        }
        foreach (['actor_user_id', 'entity_id'] as $key) {
            if (!array_key_exists($key, $filters)) continue;
            if (!self::valid_id($filters[$key])) return self::invalid_filter();
            $where[] = "$key = %d";
            $args[] = $filters[$key];
        }

        $table = self::table('audit');
        if ($table instanceof WP_Error) return $table;
        return self::paginate(
            $table,
            implode(' AND ', $where),
            $args,
            'ORDER BY created_at DESC, id DESC',
            $pagination,
            function ($row) use ($event_id) { return self::normalize_audit($row, $event_id); }
        );
    }

    private static function normalize_group_name_input($value)
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return self::invalid_name();
        }
        if (strpos($value, '<') !== false || strpos($value, '>') !== false
            || preg_match('/[\p{Cf}\p{Co}\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $value) === 1) {
            return self::invalid_name();
        }
        $whitespace = '[\p{Z}\x{0009}\x{000A}\x{000D}]';
        $name = preg_replace('/\A' . $whitespace . '+|' . $whitespace . '+\z/u', '', $value);
        $name = is_string($name) ? preg_replace('/' . $whitespace . '+/u', ' ', $name) : null;
        if (!is_string($name) || $name === '') return self::invalid_name();

        $name_length = self::unicode_codepoint_length($name);
        if ($name_length === null || $name_length > 191) return self::invalid_name();
        $name_key = strtr($name, array_combine(range('A', 'Z'), range('a', 'z')));
        $key_length = self::unicode_codepoint_length($name_key);
        if ($name_key === '' || $key_length === null || $key_length > 160) return self::invalid_name();
        return ['name' => $name, 'name_key' => $name_key];
    }

    private static function unicode_codepoint_length($value)
    {
        $matched = preg_match_all('/./us', $value, $unused);
        return is_int($matched) && $matched >= 0 ? $matched : null;
    }

    private static function with_group_transaction($event_id, $operation)
    {
        global $wpdb;
        if (!is_callable($operation)) return self::database_error();
        $previous_error = isset($wpdb->last_error) ? $wpdb->last_error : '';
        $wpdb->last_error = '';
        $started = false;
        try {
            $begin = self::control_query('START TRANSACTION');
            if ($begin instanceof WP_Error) return $begin;
            $started = true;

            $table = self::table('groups');
            if ($table instanceof WP_Error) {
                self::rollback_transaction();
                $started = false;
                return $table;
            }
            $rows = self::read_results("SELECT * FROM $table WHERE event_id = %d ORDER BY id FOR UPDATE", [$event_id]);
            if ($rows instanceof WP_Error) {
                self::rollback_transaction();
                $started = false;
                return $rows;
            }
            $groups = self::normalize_locked_groups($rows, $event_id);
            if ($groups instanceof WP_Error) {
                self::rollback_transaction();
                $started = false;
                return $groups;
            }

            $result = call_user_func($operation, $groups);
            if ($result instanceof WP_Error) {
                self::rollback_transaction();
                $started = false;
                return $result;
            }
            if (!is_array($result) || !array_key_exists('changed', $result) || !array_key_exists('group', $result)) {
                self::rollback_transaction();
                $started = false;
                return self::database_error();
            }
            $commit = self::control_query('COMMIT');
            if ($commit instanceof WP_Error) {
                self::rollback_transaction();
                $started = false;
                return self::database_error();
            }
            $started = false;
            return $result;
        } catch (Throwable $e) {
            if ($started) self::rollback_transaction();
            return self::database_error();
        } finally {
            $wpdb->last_error = $previous_error;
        }
    }

    private static function control_query($sql)
    {
        global $wpdb;
        $previous_error = isset($wpdb->last_error) ? $wpdb->last_error : '';
        $wpdb->last_error = '';
        try {
            $result = $wpdb->query($sql);
        } catch (Throwable $e) {
            $wpdb->last_error = $previous_error;
            return self::database_error();
        }
        $query_error = (string) $wpdb->last_error;
        $wpdb->last_error = $previous_error;
        if ($query_error !== '' || !is_int($result) || $result !== 0) {
            return self::database_error();
        }
        return true;
    }

    private static function rollback_transaction()
    {
        self::control_query('ROLLBACK');
    }

    private static function mutation_query($sql, array $args)
    {
        global $wpdb;
        $query = self::prepare_query($sql, $args);
        if ($query instanceof WP_Error) return $query;
        $previous_error = isset($wpdb->last_error) ? $wpdb->last_error : '';
        $wpdb->last_error = '';
        try {
            $result = $wpdb->query($query);
        } catch (Throwable $e) {
            $wpdb->last_error = $previous_error;
            return self::database_error();
        }
        $query_error = (string) $wpdb->last_error;
        $wpdb->last_error = $previous_error;
        return $query_error === '' && is_int($result) && $result >= 0 ? $result : self::database_error();
    }

    private static function normalize_locked_groups(array $rows, $event_id)
    {
        $groups = [];
        $seen = [];
        foreach ($rows as $row) {
            $group = self::normalize_group($row, $event_id);
            if ($group instanceof WP_Error || isset($seen[$group['id']])) return self::database_error();
            $seen[$group['id']] = true;
            $groups[] = $group;
        }
        return $groups;
    }

    private static function find_locked_group_by_id(array $groups, $group_id)
    {
        foreach ($groups as $group) {
            if ($group['id'] === $group_id) return $group;
        }
        return null;
    }

    private static function find_locked_group_by_key(array $groups, $name_key, $except_id = null)
    {
        foreach ($groups as $group) {
            if ($group['status'] === 'active' && $group['name_key'] === $name_key && $group['id'] !== $except_id) return $group;
        }
        return null;
    }

    private static function locked_default_group(array $groups)
    {
        $default = null;
        foreach ($groups as $group) {
            if (!$group['is_default']) continue;
            if ($default !== null || $group['status'] !== 'active') return self::database_error();
            $default = $group;
        }
        return $default;
    }

    private static function update_group_default_slot($event_id, $group_id, $slot, $now)
    {
        $table = self::table('groups');
        if ($table instanceof WP_Error) return $table;
        if ($slot === null) {
            $result = self::mutation_query(
                "UPDATE $table SET default_slot = NULL, updated_at = %s WHERE event_id = %d AND id = %d AND status = %s AND default_slot = %d",
                [$now, $event_id, $group_id, 'active', 1]
            );
        } else {
            $result = self::mutation_query(
                "UPDATE $table SET default_slot = %d, updated_at = %s WHERE event_id = %d AND id = %d AND status = %s AND default_slot IS NULL",
                [1, $now, $event_id, $group_id, 'active']
            );
        }
        if ($result instanceof WP_Error) return $result;
        if ($result === 1) return true;
        return $result === 0 ? self::concurrent_update() : self::database_error();
    }

    private static function insert_group_audit($event_id, $actor_user_id, $action, $group_id, $metadata, $now)
    {
        if (!in_array($action, self::GROUP_WRITE_AUDIT_ACTIONS, true) || !self::valid_id($actor_user_id) || !self::valid_id($group_id)) {
            return self::database_error();
        }
        $table = self::table('audit');
        if ($table instanceof WP_Error) return $table;
        if ($metadata === null) {
            $result = self::mutation_query(
                "INSERT INTO $table (event_id, actor_user_id, action, entity_type, entity_id, metadata, created_at) VALUES (%d, %d, %s, %s, %d, NULL, %s)",
                [$event_id, $actor_user_id, $action, 'group', $group_id, $now]
            );
        } else {
            if (!is_array($metadata) || self::is_list($metadata) || !function_exists('wp_json_encode')) return self::database_error();
            $json = wp_json_encode($metadata);
            if (!is_string($json) || $json === '') return self::database_error();
            $result = self::mutation_query(
                "INSERT INTO $table (event_id, actor_user_id, action, entity_type, entity_id, metadata, created_at) VALUES (%d, %d, %s, %s, %d, %s, %s)",
                [$event_id, $actor_user_id, $action, 'group', $group_id, $json, $now]
            );
        }
        if ($result instanceof WP_Error) return $result;
        return $result === 1 ? true : self::database_error();
    }

    private static function read_group_inside_transaction($event_id, $group_id)
    {
        $table = self::table('groups');
        if ($table instanceof WP_Error) return $table;
        $row = self::read_optional_row("SELECT * FROM $table WHERE event_id = %d AND id = %d LIMIT 2", [$event_id, $group_id]);
        if ($row instanceof WP_Error) return $row;
        if ($row === null) return self::database_error();
        return self::normalize_group($row, $event_id);
    }

    private static function translate_duplicate_after_failure($event_id, $name_key, $except_id, $fallback)
    {
        $table = self::table('groups');
        if ($table instanceof WP_Error) return $fallback;
        $row = self::read_optional_row(
            "SELECT * FROM $table WHERE event_id = %d AND name_key = %s AND status = %s LIMIT 2",
            [$event_id, $name_key, 'active']
        );
        if ($row instanceof WP_Error || $row === null) return $fallback;
        $group = self::normalize_group($row, $event_id);
        if ($group instanceof WP_Error) return $fallback;
        return $group['id'] !== $except_id ? self::duplicate_group() : $fallback;
    }

    private static function guard_event($event_id)
    {
        if (!class_exists('PGE_Event_Access_Schema')
            || !method_exists('PGE_Event_Access_Schema', 'is_ready')
            || !PGE_Event_Access_Schema::is_ready()) {
            return new WP_Error('schema_not_ready', 'Event access storage is not ready.');
        }
        if (!self::valid_id($event_id)) return self::invalid_input();
        if (get_post_type($event_id) !== 'pge_event') return self::not_found();
        return true;
    }

    private static function get_group_scoped($event_id, $group_id, $required)
    {
        $table = self::table('groups');
        if ($table instanceof WP_Error) return $table;
        $row = self::read_optional_row(
            "SELECT * FROM $table WHERE event_id = %d AND id = %d LIMIT 2",
            [$event_id, $group_id]
        );
        if ($row instanceof WP_Error) return $row;
        if ($row === null) return $required ? self::not_found() : null;
        return self::normalize_group($row, $event_id);
    }

    private static function get_membership_scoped($event_id, $membership_id, $required)
    {
        $table = self::table('memberships');
        if ($table instanceof WP_Error) return $table;
        $row = self::read_optional_row(
            "SELECT * FROM $table WHERE event_id = %d AND id = %d LIMIT 2",
            [$event_id, $membership_id]
        );
        if ($row instanceof WP_Error) return $row;
        if ($row === null) return $required ? self::not_found() : null;
        return self::normalize_membership($row, $event_id);
    }

    private static function paginate($from, $where, array $args, $order, array $pagination, $normalizer, $select = '*')
    {
        $total = self::read_count("SELECT COUNT(*) FROM $from WHERE $where", $args);
        if ($total instanceof WP_Error) return $total;
        $rows = self::read_results(
            "SELECT $select FROM $from WHERE $where $order LIMIT %d OFFSET %d",
            array_merge($args, [$pagination['per_page'], $pagination['offset']])
        );
        if ($rows instanceof WP_Error) return $rows;
        $items = [];
        foreach ($rows as $row) {
            $item = call_user_func($normalizer, $row);
            if ($item instanceof WP_Error) return $item;
            $items[] = $item;
        }
        return [
            'items' => $items,
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : intdiv($total - 1, $pagination['per_page']) + 1,
        ];
    }

    private static function validate_pagination($page, $per_page)
    {
        if (!self::valid_id($page) || !self::valid_id($per_page) || $per_page > self::MAX_PER_PAGE) {
            return self::invalid_input();
        }
        $base = $page - 1;
        if ($base > intdiv(PHP_INT_MAX, $per_page)) return self::invalid_input();
        return ['page' => $page, 'per_page' => $per_page, 'offset' => $base * $per_page];
    }

    private static function read_optional_row($sql, array $args)
    {
        $rows = self::read_results($sql, $args);
        if ($rows instanceof WP_Error) return $rows;
        if (count($rows) > 1) return self::database_error();
        return $rows ? $rows[0] : null;
    }

    private static function read_count($sql, array $args)
    {
        $value = self::read_scalar($sql, $args);
        if ($value instanceof WP_Error) return $value;
        $count = self::db_nonnegative_int($value);
        return $count === null ? self::database_error() : $count;
    }

    private static function read_results($sql, array $args)
    {
        global $wpdb;
        $query = self::prepare_query($sql, $args);
        if ($query instanceof WP_Error) return $query;
        $previous_error = isset($wpdb->last_error) ? $wpdb->last_error : '';
        $wpdb->last_error = '';
        try {
            $rows = $wpdb->get_results($query, ARRAY_A);
        } catch (Throwable $e) {
            $wpdb->last_error = $previous_error;
            return self::database_error();
        }
        $query_error = (string) $wpdb->last_error;
        $wpdb->last_error = $previous_error;
        if ($query_error !== '' || !is_array($rows)) return self::database_error();
        foreach ($rows as $row) {
            if (!is_array($row)) return self::database_error();
        }
        return $rows;
    }

    private static function read_scalar($sql, array $args)
    {
        global $wpdb;
        $query = self::prepare_query($sql, $args);
        if ($query instanceof WP_Error) return $query;
        $previous_error = isset($wpdb->last_error) ? $wpdb->last_error : '';
        $wpdb->last_error = '';
        try {
            $value = $wpdb->get_var($query);
        } catch (Throwable $e) {
            $wpdb->last_error = $previous_error;
            return self::database_error();
        }
        $query_error = (string) $wpdb->last_error;
        $wpdb->last_error = $previous_error;
        return $query_error !== '' ? self::database_error() : $value;
    }

    private static function prepare_query($sql, array $args)
    {
        global $wpdb;
        $previous_error = isset($wpdb->last_error) ? $wpdb->last_error : '';
        $wpdb->last_error = '';
        try {
            $query = call_user_func_array([$wpdb, 'prepare'], array_merge([$sql], $args));
        } catch (Throwable $e) {
            $wpdb->last_error = $previous_error;
            return self::database_error();
        }
        $prepare_error = (string) $wpdb->last_error;
        $wpdb->last_error = $previous_error;
        return is_string($query) && $query !== '' && $prepare_error === ''
            ? $query
            : self::database_error();
    }

    private static function table($key)
    {
        global $wpdb;
        $map = [
            'groups' => 'pge_event_invitation_groups',
            'memberships' => 'pge_event_host_memberships',
            'access' => 'pge_event_host_group_access',
            'assignments' => 'pge_invitation_group_assignments',
            'audit' => 'pge_event_access_audit_log',
        ];
        $prefix = isset($wpdb->prefix) && is_string($wpdb->prefix) ? $wpdb->prefix : '';
        if (!isset($map[$key]) || preg_match('/\A[A-Za-z0-9_]+\z/', $prefix) !== 1) return self::database_error();
        return $prefix . $map[$key];
    }

    private static function normalize_group($row, $event_id)
    {
        if (!is_array($row)) return self::database_error();
        foreach (['id', 'event_id', 'name', 'name_key', 'status', 'default_slot', 'created_by_user_id', 'created_at', 'updated_at', 'archived_at'] as $key) {
            if (!array_key_exists($key, $row)) return self::database_error();
        }
        $id = self::db_positive_int($row['id'] ?? null);
        $row_event = self::db_positive_int($row['event_id'] ?? null);
        $creator = self::db_positive_int($row['created_by_user_id'] ?? null);
        $default_slot = $row['default_slot'] ?? null;
        if ($id === null || $row_event === null || $creator === null) return self::database_error();
        if ($row_event !== $event_id) return self::cross_event();
        if (!is_string($row['name'] ?? null)
            || !self::nullable_string($row['name_key'] ?? null)
            || !is_string($row['status'] ?? null)
            || !in_array($row['status'], self::GROUP_STATUSES, true)
            || !self::required_string($row['created_at'] ?? null)
            || !self::required_string($row['updated_at'] ?? null)
            || !self::nullable_string($row['archived_at'] ?? null)) {
            return self::database_error();
        }
        $slot = $default_slot === null ? null : self::db_positive_int($default_slot);
        if ($default_slot !== null && $slot !== 1) return self::database_error();
        if ($row['status'] === 'active'
            && (!is_string($row['name_key']) || $row['name_key'] === '' || $row['archived_at'] !== null)) {
            return self::database_error();
        }
        if ($row['status'] === 'archived'
            && ($row['name_key'] !== null || $slot !== null || !self::required_string($row['archived_at']))) {
            return self::database_error();
        }
        return [
            'id' => $id,
            'event_id' => $row_event,
            'name' => $row['name'],
            'name_key' => $row['name_key'],
            'status' => $row['status'],
            'is_default' => $slot === 1,
            'created_by_user_id' => $creator,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'archived_at' => $row['archived_at'],
        ];
    }

    private static function normalize_membership($row, $event_id)
    {
        if (!is_array($row)) return self::database_error();
        foreach (['id', 'event_id', 'user_id', 'role', 'status', 'created_by_user_id', 'activated_at', 'revoked_at', 'created_at', 'updated_at'] as $key) {
            if (!array_key_exists($key, $row)) return self::database_error();
        }
        $id = self::db_positive_int($row['id'] ?? null);
        $row_event = self::db_positive_int($row['event_id'] ?? null);
        $user = self::db_positive_int($row['user_id'] ?? null);
        $creator = self::db_positive_int($row['created_by_user_id'] ?? null);
        if ($id === null || $row_event === null || $user === null || $creator === null) return self::database_error();
        if ($row_event !== $event_id) return self::cross_event();
        if (!is_string($row['role'] ?? null) || !in_array($row['role'], self::MEMBERSHIP_ROLES, true)
            || !is_string($row['status'] ?? null) || !in_array($row['status'], self::MEMBERSHIP_STATUSES, true)
            || !self::required_string($row['activated_at'] ?? null)
            || !self::nullable_string($row['revoked_at'] ?? null)
            || !self::required_string($row['created_at'] ?? null)
            || !self::required_string($row['updated_at'] ?? null)) {
            return self::database_error();
        }
        return [
            'id' => $id,
            'event_id' => $row_event,
            'user_id' => $user,
            'role' => $row['role'],
            'status' => $row['status'],
            'created_by_user_id' => $creator,
            'activated_at' => $row['activated_at'],
            'revoked_at' => $row['revoked_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function normalize_assignment($row, $event_id)
    {
        if (!is_array($row)) return self::database_error();
        foreach (['id', 'event_id', 'guest_phone', 'group_id', 'assigned_by_user_id', 'created_at', 'updated_at', 'related_event_id'] as $key) {
            if (!array_key_exists($key, $row)) return self::database_error();
        }
        $id = self::db_positive_int($row['id'] ?? null);
        $row_event = self::db_positive_int($row['event_id'] ?? null);
        $group = self::db_positive_int($row['group_id'] ?? null);
        $assigner = self::db_positive_int($row['assigned_by_user_id'] ?? null);
        $related_event = self::db_positive_int($row['related_event_id'] ?? null);
        $phone = $row['guest_phone'] ?? null;
        $normalized_phone = is_string($phone) && function_exists('pge_event_guests_norm_phone')
            ? pge_event_guests_norm_phone($phone)
            : null;
        if ($id === null || $row_event === null || $group === null || $assigner === null || $related_event === null
            || !is_string($phone) || $phone === '' || strlen($phone) > 32
            || !is_string($normalized_phone) || $normalized_phone !== $phone
            || !self::required_string($row['created_at'] ?? null)
            || !self::required_string($row['updated_at'] ?? null)) {
            return self::database_error();
        }
        if ($row_event !== $event_id || $related_event !== $event_id) return self::cross_event();
        return [
            'id' => $id,
            'event_id' => $row_event,
            'guest_phone' => $phone,
            'group_id' => $group,
            'assigned_by_user_id' => $assigner,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function normalize_audit($row, $event_id)
    {
        if (!is_array($row)) return self::database_error();
        foreach (['id', 'event_id', 'actor_user_id', 'action', 'entity_type', 'entity_id', 'metadata', 'created_at'] as $key) {
            if (!array_key_exists($key, $row)) return self::database_error();
        }
        $id = self::db_positive_int($row['id'] ?? null);
        $row_event = self::db_positive_int($row['event_id'] ?? null);
        $actor = self::db_positive_int($row['actor_user_id'] ?? null);
        $entity = ($row['entity_id'] ?? null) === null ? null : self::db_positive_int($row['entity_id']);
        if ($id === null || $row_event === null || $actor === null || (($row['entity_id'] ?? null) !== null && $entity === null)) {
            return self::database_error();
        }
        if ($row_event !== $event_id) return self::cross_event();
        if (!is_string($row['action'] ?? null) || !in_array($row['action'], self::AUDIT_ACTIONS, true)
            || !is_string($row['entity_type'] ?? null) || !in_array($row['entity_type'], self::AUDIT_ENTITY_TYPES, true)
            || !self::required_string($row['created_at'] ?? null)) {
            return self::database_error();
        }
        $metadata = null;
        if (($row['metadata'] ?? null) !== null) {
            if (!is_string($row['metadata'])) return self::database_error();
            $metadata = json_decode($row['metadata'], true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($metadata)) return self::database_error();
        }
        return [
            'id' => $id,
            'event_id' => $row_event,
            'actor_user_id' => $actor,
            'action' => $row['action'],
            'entity_type' => $row['entity_type'],
            'entity_id' => $entity,
            'metadata' => $metadata,
            'created_at' => $row['created_at'],
        ];
    }

    private static function normalize_scoped_ids(array $rows, $event_id, $field)
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $row_event = self::db_positive_int($row['event_id'] ?? null);
            $related_event = self::db_positive_int($row['related_event_id'] ?? null);
            $id = self::db_positive_int($row[$field] ?? null);
            if ($row_event === null || $related_event === null || $id === null) return self::database_error();
            if ($row_event !== $event_id || $related_event !== $event_id) return self::cross_event();
            if (isset($seen[$id])) return self::database_error();
            $seen[$id] = true;
            $out[] = $id;
        }
        return $out;
    }

    private static function normalize_phone_input($value)
    {
        if (!is_string($value) || !function_exists('pge_event_guests_norm_phone')) return self::invalid_input();
        $phone = pge_event_guests_norm_phone($value);
        if (!is_string($phone) || $phone === '' || strlen($phone) > 32) return self::invalid_input();
        return $phone;
    }

    private static function db_positive_int($value)
    {
        $value = self::db_nonnegative_int($value);
        return $value !== null && $value > 0 ? $value : null;
    }

    private static function db_nonnegative_int($value)
    {
        if (is_int($value)) return $value >= 0 ? $value : null;
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) return null;
        if (strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }
        return (int) $value;
    }

    private static function valid_id($value)
    {
        return is_int($value) && $value > 0;
    }

    private static function only_filters(array $filters, array $allowed)
    {
        foreach (array_keys($filters) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) return false;
        }
        return true;
    }

    private static function is_list(array $values)
    {
        if ($values === []) return true;
        return array_keys($values) === range(0, count($values) - 1);
    }

    private static function required_string($value)
    {
        return is_string($value) && $value !== '';
    }

    private static function nullable_string($value)
    {
        return $value === null || is_string($value);
    }

    private static function string_length($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function invalid_input()
    {
        return new WP_Error('invalid_input', 'Invalid repository input.');
    }

    private static function invalid_name()
    {
        return new WP_Error('invalid_name', 'Invalid invitation group name.');
    }

    private static function duplicate_group()
    {
        return new WP_Error('duplicate_group', 'An active invitation group with this identity already exists.');
    }

    private static function invalid_state()
    {
        return new WP_Error('invalid_state', 'The invitation group is not in a writable state.');
    }

    private static function concurrent_update()
    {
        return new WP_Error('concurrent_update', 'The invitation group changed during the operation.');
    }

    private static function invalid_filter()
    {
        return new WP_Error('invalid_filter', 'Invalid repository filter.');
    }

    private static function not_found()
    {
        return new WP_Error('not_found', 'Requested event access record was not found.');
    }

    private static function cross_event()
    {
        return new WP_Error('cross_event', 'Event access record belongs to another event.');
    }

    private static function database_error()
    {
        return new WP_Error('database_error', 'Unable to access event access storage.');
    }
}
