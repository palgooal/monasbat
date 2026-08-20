<?php
if (!defined('ABSPATH')) exit;

/**
 * Additive schema foundation for event invitation groups and WordPress-user hosts.
 *
 * This class owns schema only. It creates no rows, performs no backfill, and grants
 * no access. The stored version is a hint paired with a short-lived health marker;
 * structural and data postconditions are periodically re-proved by the full check.
 */
class PGE_Event_Access_Schema
{
    const SCHEMA_VERSION = '1.2.0';
    const VERSION_OPTION = 'pge_event_access_schema_version';
    const HEALTH_OPTION = 'pge_event_access_schema_health';
    const LAST_ATTEMPT_OPTION = 'pge_event_access_schema_last_attempt';
    const HEALTH_TTL_SECONDS = 43200;
    const RETRY_THROTTLE_SECONDS = 300;
    const LOCK_TIMEOUT_SECONDS = 2;

    /** @var string */
    private static $last_error_code = '';

    /** @var bool */
    private static $running = false;

    /** @var bool */
    private static $request_completed = false;

    /** @var bool */
    private static $request_result = false;

    /** @var string|null */
    private static $request_schema_version = null;

    /** @var bool */
    private static $suppress_logging = false;

    /** @var string|null */
    private static $database_name = null;

    /** @return string */
    public static function get_last_error_code()
    {
        return self::$last_error_code;
    }

    /** Lightweight runtime callback. Full verification is periodic or needed. */
    public static function maybe_upgrade()
    {
        if (self::$running) {
            self::$last_error_code = 'reentry_blocked';
            return false;
        }

        if (self::$request_completed) {
            return self::$request_result;
        }

        $stored_version = (string) get_option(self::VERSION_OPTION, '');
        $health = get_option(self::HEALTH_OPTION, null);
        $attempt = get_option(self::LAST_ATTEMPT_OPTION, null);
        if (self::readiness_state_is_current($stored_version, $health, $attempt)) {
            self::$request_completed = true;
            self::$request_result = true;
            self::$request_schema_version = self::SCHEMA_VERSION;
            self::$last_error_code = '';
            return true;
        }

        // A version change is never delayed by retry throttling.
        if ($stored_version === self::SCHEMA_VERSION && self::retry_is_throttled($attempt)) {
            self::$request_completed = true;
            self::$request_result = false;
            self::$last_error_code = 'retry_throttled';
            return false;
        }

        return self::run_full_check();
    }

    /**
     * Side-effect-free readiness proof for future H1B write guards.
     *
     * A false result never triggers repair, DDL, diagnostics, locks, logging,
     * or option writes. Every future public repository write must fail with
     * schema_not_ready before opening a transaction when this returns false.
     */
    public static function is_ready()
    {
        // A failed full check in this request is authoritative even if every
        // terminal marker write failed and older markers still look healthy.
        if (self::$request_completed
            && self::$request_result === false
            && self::$request_schema_version === self::SCHEMA_VERSION) {
            return false;
        }

        $stored_version = get_option(self::VERSION_OPTION, '');
        $health = get_option(self::HEALTH_OPTION, null);
        $attempt = get_option(self::LAST_ATTEMPT_OPTION, null);

        return self::readiness_state_is_current($stored_version, $health, $attempt);
    }

    /** Activation always proves the complete contract, ignoring cached health. */
    public static function activate()
    {
        return self::run_full_check(true);
    }

    /** Create or repair the additive schema, then prove every postcondition. */
    private static function run_full_check($force = false)
    {
        global $wpdb;

        if (self::$running) {
            self::$last_error_code = 'reentry_blocked';
            return false;
        }

        if (!$force && self::$request_completed) {
            return self::$request_result;
        }

        self::$last_error_code = '';
        self::$running = true;
        self::$database_name = null;
        $lock_name = self::lock_name();
        $got_lock = false;
        $release_attempted = false;
        $result = false;
        $generation = self::new_generation();
        $health_for_cleanup = get_option(self::HEALTH_OPTION, null);
        if (!is_array($health_for_cleanup)) {
            $health_for_cleanup = null;
        }
        $initial_attempt = $generation === false ? null : self::attempt_marker(
            'in_progress',
            'full_check_started',
            $generation,
            self::now()
        );

        try {
            if ($generation === false) {
                $result = self::fail('generation_failed');
            } elseif (!self::write_option_verified(self::LAST_ATTEMPT_OPTION, $initial_attempt)) {
                $result = self::fail('failed_state_write_failed');
            } else {
                $got_lock = (int) $wpdb->get_var(
                    $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, self::LOCK_TIMEOUT_SECONDS)
                ) === 1;

                if (!$got_lock) {
                    self::fail('lock_not_acquired');
                } else {
                    $result = self::perform_full_check();

                    $release_attempted = true;
                    $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
                    if ((int) $released !== 1) {
                        $result = self::fail($released === null ? 'lock_release_error' : 'lock_release_not_owned');
                    } else {
                        $got_lock = false;
                    }

                    // Version and healthy marker are committed only after the
                    // schema work and lock release have both succeeded.
                    if ($result) {
                        $result = self::store_success_state($generation, $initial_attempt, $health_for_cleanup);
                    }
                }
            }
        } catch (\Throwable $e) {
            $result = self::fail('unexpected_upgrade_failure');
        } finally {
            if ($got_lock && !$release_attempted) {
                $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
                if ((int) $released !== 1) {
                    $result = self::fail($released === null ? 'lock_release_error' : 'lock_release_not_owned');
                }
            }

            if (!$result) {
                self::store_failed_state($generation, $initial_attempt, $health_for_cleanup);
            }

            self::$running = false;
            self::$request_completed = true;
            self::$request_result = (bool) $result;
            self::$request_schema_version = self::SCHEMA_VERSION;
        }

        return (bool) $result;
    }

    private static function perform_full_check()
    {
        if (self::probe_schema_postconditions()) {
            return true;
        }

        // Prove every existing table's engine before making any additive
        // repair. A single non-transactional table blocks all schema work.
        if (!self::existing_table_metadata_is_safe()) {
            return false;
        }

        if (!self::ensure_tables_and_columns()) {
            return false;
        }

        // Unique indexes are never attempted until the current data has
        // proved safe for every domain invariant and relationship.
        if (!self::data_postconditions_hold()) {
            return false;
        }

        if (!self::ensure_indexes()) {
            return false;
        }

        return self::schema_postconditions_hold();
    }

    private static function probe_schema_postconditions()
    {
        self::$suppress_logging = true;
        try {
            return self::schema_postconditions_hold();
        } finally {
            self::$suppress_logging = false;
        }
    }

    private static function existing_table_metadata_is_safe()
    {
        foreach (self::table_names() as $key => $table) {
            if (self::table_exists($table)
                && self::read_table_metadata($key, $table) === false) {
                return false;
            }
        }
        return true;
    }

    private static function store_success_state($generation, array $initial_attempt, &$health_for_cleanup)
    {
        $attempt = get_option(self::LAST_ATTEMPT_OPTION, null);
        if (!self::attempt_matches($attempt, 'in_progress', $generation)) {
            return self::fail('attempt_generation_changed');
        }

        $now = self::now();
        $health = [
            'schema_version' => self::SCHEMA_VERSION,
            'checked_at' => $now,
            'status' => 'healthy',
            'generation' => $generation,
        ];
        if (!self::write_option_verified(self::VERSION_OPTION, self::SCHEMA_VERSION)) {
            return self::fail('version_update_failed');
        }

        $health_for_cleanup = $health;
        if (!self::write_option_verified(self::HEALTH_OPTION, $health)) {
            return self::fail('health_marker_write_failed');
        }

        $success = [
            'schema_version' => self::SCHEMA_VERSION,
            'attempted_at' => $initial_attempt['attempted_at'],
            'completed_at' => $now,
            'status' => 'success',
            'reason_code' => 'success',
            'generation' => $generation,
        ];
        if (!self::compare_and_swap_option(self::LAST_ATTEMPT_OPTION, $initial_attempt, $success)) {
            return self::fail('attempt_state_write_failed');
        }
        self::$last_error_code = '';
        return true;
    }

    private static function store_failed_state($generation, $initial_attempt, $health_for_cleanup)
    {
        $now = self::now();
        $reason = self::$last_error_code !== '' ? self::$last_error_code : 'full_check_failed';
        $attempt_transition_required = self::generation_is_valid($generation) && is_array($initial_attempt);
        $attempt_ok = !$attempt_transition_required;
        if ($attempt_transition_required) {
            $failed_attempt = [
                'schema_version' => self::SCHEMA_VERSION,
                'attempted_at' => $now,
                'status' => 'failed',
                'reason_code' => $reason,
                'generation' => $generation,
            ];
            $attempt_ok = self::compare_and_swap_option(
                self::LAST_ATTEMPT_OPTION,
                $initial_attempt,
                $failed_attempt
            );
        }
        $health_ok = true;
        if (is_array($health_for_cleanup)) {
            $health_ok = self::invalidate_health_for_generation($health_for_cleanup, $reason, $now);
        }

        if (!$attempt_ok) {
            self::$last_error_code = 'failed_state_write_failed';
            return false;
        }
        if (!$health_ok) {
            self::$last_error_code = 'health_invalidation_failed';
            return false;
        }
        return true;
    }

    private static function attempt_marker($status, $reason_code, $generation, $attempted_at)
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'attempted_at' => $attempted_at,
            'status' => (string) $status,
            'reason_code' => (string) $reason_code,
            'generation' => (string) $generation,
        ];
    }

    private static function invalidate_health_for_generation(array $expected_health, $reason_code, $now = null)
    {
        $generation = $expected_health['generation'] ?? null;
        if (!self::generation_is_valid($generation)) {
            return false;
        }
        return self::compare_and_swap_option(self::HEALTH_OPTION, $expected_health, [
            'schema_version' => self::SCHEMA_VERSION,
            'checked_at' => $now === null ? self::now() : $now,
            'status' => 'failed',
            'reason_code' => (string) $reason_code,
            'generation' => $generation,
        ]);
    }

    private static function compare_and_swap_option($key, array $expected, array $desired)
    {
        global $wpdb;

        $table = isset($wpdb->options) ? (string) $wpdb->options : '';
        if ($table === '' || preg_match('/\A[A-Za-z0-9_]+\z/', $table) !== 1
            || !function_exists('maybe_serialize')) {
            return false;
        }

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE `$table` SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s",
            maybe_serialize($desired),
            (string) $key,
            maybe_serialize($expected)
        ));
        if ($result !== 1) {
            return false;
        }

        self::clear_option_cache($key);
        return get_option($key, null) === $desired;
    }

    private static function clear_option_cache($key)
    {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        wp_cache_delete((string) $key, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private static function write_option_verified($key, $value)
    {
        update_option($key, $value);
        return get_option($key, null) === $value;
    }

    private static function health_is_current($health)
    {
        if (!is_array($health)
            || ($health['status'] ?? null) !== 'healthy'
            || ($health['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !self::generation_is_valid($health['generation'] ?? null)
            || !is_int($health['checked_at'] ?? null)
            || $health['checked_at'] <= 0) {
            return false;
        }

        $now = self::now();
        return $health['checked_at'] <= $now
            && $health['checked_at'] > ($now - self::HEALTH_TTL_SECONDS);
    }

    private static function readiness_state_is_current($stored_version, $health, $attempt)
    {
        return is_string($stored_version)
            && $stored_version === self::SCHEMA_VERSION
            && self::health_is_current($health)
            && self::attempt_commits_health($attempt, $health);
    }

    private static function attempt_commits_health($attempt, $health)
    {
        return self::attempt_matches($attempt, 'success', $health['generation'] ?? null)
            && is_int($attempt['completed_at'] ?? null)
            && $attempt['completed_at'] > 0
            && $attempt['attempted_at'] <= $attempt['completed_at']
            && $attempt['completed_at'] === ($health['checked_at'] ?? null);
    }

    private static function attempt_is_valid($attempt)
    {
        return is_array($attempt)
            && ($attempt['schema_version'] ?? null) === self::SCHEMA_VERSION
            && is_int($attempt['attempted_at'] ?? null)
            && $attempt['attempted_at'] > 0
            && in_array($attempt['status'] ?? null, ['in_progress', 'failed', 'success'], true)
            && is_string($attempt['reason_code'] ?? null)
            && self::generation_is_valid($attempt['generation'] ?? null);
    }

    private static function attempt_matches($attempt, $status, $generation)
    {
        return self::attempt_is_valid($attempt)
            && $attempt['status'] === $status
            && self::generation_is_valid($generation)
            && $attempt['generation'] === $generation;
    }

    private static function retry_is_throttled($attempt)
    {
        if (!self::attempt_is_valid($attempt)
            || !in_array($attempt['status'], ['failed', 'in_progress'], true)) {
            return false;
        }

        $now = self::now();
        return $attempt['attempted_at'] <= $now
            && $attempt['attempted_at'] > ($now - self::RETRY_THROTTLE_SECONDS);
    }

    private static function new_generation()
    {
        try {
            $generation = function_exists('wp_generate_uuid4')
                ? wp_generate_uuid4()
                : bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return false;
        }
        return self::generation_is_valid($generation) ? $generation : false;
    }

    private static function generation_is_valid($generation)
    {
        return is_string($generation)
            && (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $generation) === 1
                || preg_match('/\A[0-9a-f]{32}\z/', $generation) === 1);
    }

    private static function now()
    {
        return function_exists('current_time') ? (int) current_time('timestamp', true) : time();
    }

    private static function lock_name()
    {
        $tables = self::table_names();
        return 'pge_event_access_schema_' . substr(md5($tables['groups']), 0, 24);
    }

    /** @return array<string,string> */
    private static function table_names()
    {
        global $wpdb;

        return [
            'groups'      => $wpdb->prefix . 'pge_event_invitation_groups',
            'memberships' => $wpdb->prefix . 'pge_event_host_memberships',
            'access'      => $wpdb->prefix . 'pge_event_host_group_access',
            'assignments' => $wpdb->prefix . 'pge_invitation_group_assignments',
            'audit'       => $wpdb->prefix . 'pge_event_access_audit_log',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function table_specs()
    {
        return [
            'groups' => [
                'columns' => [
                    'id'                 => self::column('BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', '/^bigint(?:\(20\))? unsigned$/', false, null, true),
                    'event_id'           => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'name'               => self::column('VARCHAR(191) NOT NULL', '/^varchar\(191\)$/', false),
                    'name_key'           => self::column('VARCHAR(160) NULL DEFAULT NULL', '/^varchar\(160\)$/', true, null),
                    'status'             => self::column("VARCHAR(20) NOT NULL DEFAULT 'active'", '/^varchar\(20\)$/', false, 'active'),
                    'default_slot'       => self::column('TINYINT(1) UNSIGNED NULL DEFAULT NULL', '/^tinyint(?:\(1\))? unsigned$/', true, null),
                    'created_by_user_id' => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'created_at'         => self::column('DATETIME NOT NULL', '/^datetime(?:\(0\))?$/', false),
                    'updated_at'         => self::column('DATETIME NOT NULL', '/^datetime(?:\(0\))?$/', false),
                    'archived_at'        => self::column('DATETIME NULL DEFAULT NULL', '/^datetime(?:\(0\))?$/', true, null),
                ],
                'indexes' => [
                    'PRIMARY'           => self::index(['id'], true, true),
                    'event_active_name' => self::index(['event_id', 'name_key'], true),
                    'event_default_slot'=> self::index(['event_id', 'default_slot'], true),
                    'event_status'      => self::index(['event_id', 'status', 'id']),
                ],
            ],
            'memberships' => [
                'columns' => [
                    'id'                 => self::column('BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', '/^bigint(?:\(20\))? unsigned$/', false, null, true),
                    'event_id'           => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'user_id'            => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'role'               => self::column('VARCHAR(20) NOT NULL', '/^varchar\(20\)$/', false),
                    'status'             => self::column("VARCHAR(20) NOT NULL DEFAULT 'active'", '/^varchar\(20\)$/', false, 'active'),
                    'created_by_user_id' => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'activated_at'       => self::column('DATETIME NOT NULL', '/^datetime(?:\(0\))?$/', false),
                    'revoked_at'         => self::column('DATETIME NULL DEFAULT NULL', '/^datetime(?:\(0\))?$/', true, null),
                    'created_at'         => self::column('DATETIME NOT NULL', '/^datetime(?:\(0\))?$/', false),
                    'updated_at'         => self::column('DATETIME NOT NULL', '/^datetime(?:\(0\))?$/', false),
                    // H1C-W8 (Additional Inviter Quota) — additive, nullable.
                    // NULL = ordinary H1C membership (no additional-inviter
                    // quota configured); a strict positive integer = the
                    // membership is an Additional Inviter with that quota.
                    // 0 is never a valid stored value — see Repository's
                    // valid_id()/set_membership_quota()/
                    // create_additional_inviter_membership() guards. Every
                    // membership row created before W8 has no value for
                    // this column and reads back as NULL automatically
                    // (MySQL's default behavior for a newly added nullable
                    // column with no explicit DEFAULT write on old rows) —
                    // their W1-W7 behavior is unaffected, since nothing in
                    // W1-W7 code paths ever reads this column.
                    'allocated_quota'    => self::column('INT(10) UNSIGNED NULL DEFAULT NULL', '/^int(?:\(10\))? unsigned$/', true, null),
                ],
                'indexes' => [
                    'PRIMARY'           => self::index(['id'], true, true),
                    'event_user'        => self::index(['event_id', 'user_id'], true),
                    'event_status_role' => self::index(['event_id', 'status', 'role', 'id']),
                    'user_status'       => self::index(['user_id', 'status', 'event_id']),
                ],
            ],
            'access' => [
                'columns' => [
                    'id'                 => self::column('BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', '/^bigint(?:\(20\))? unsigned$/', false, null, true),
                    'event_id'           => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'membership_id'      => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'group_id'           => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'granted_by_user_id' => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'created_at'         => self::column('DATETIME NOT NULL', '/^datetime(?:\(0\))?$/', false),
                ],
                'indexes' => [
                    'PRIMARY'          => self::index(['id'], true, true),
                    'membership_group' => self::index(['membership_id', 'group_id'], true),
                    'event_membership' => self::index(['event_id', 'membership_id', 'group_id']),
                    'event_group'      => self::index(['event_id', 'group_id', 'membership_id']),
                ],
            ],
            'assignments' => [
                'columns' => [
                    'id'                  => self::column('BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', '/^bigint(?:\(20\))? unsigned$/', false, null, true),
                    'event_id'            => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'guest_phone'         => self::column('VARCHAR(32) NOT NULL', '/^varchar\(32\)$/', false),
                    'group_id'            => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'assigned_by_user_id' => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'created_at'          => self::column('DATETIME NOT NULL', '/^datetime(?:\(0\))?$/', false),
                    'updated_at'          => self::column('DATETIME NOT NULL', '/^datetime(?:\(0\))?$/', false),
                ],
                'indexes' => [
                    'PRIMARY'     => self::index(['id'], true, true),
                    'event_guest' => self::index(['event_id', 'guest_phone'], true),
                    'event_group' => self::index(['event_id', 'group_id', 'guest_phone']),
                ],
            ],
            'audit' => [
                'columns' => [
                    'id'            => self::column('BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', '/^bigint(?:\(20\))? unsigned$/', false, null, true),
                    'event_id'      => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'actor_user_id' => self::column('BIGINT(20) UNSIGNED NOT NULL', '/^bigint(?:\(20\))? unsigned$/', false),
                    'action'        => self::column('VARCHAR(40) NOT NULL', '/^varchar\(40\)$/', false),
                    'entity_type'   => self::column('VARCHAR(32) NOT NULL', '/^varchar\(32\)$/', false),
                    'entity_id'     => self::column('BIGINT(20) UNSIGNED NULL DEFAULT NULL', '/^bigint(?:\(20\))? unsigned$/', true, null),
                    'metadata'      => self::column('LONGTEXT NULL', '/^longtext$/', true, null),
                    'created_at'    => self::column('DATETIME NOT NULL', '/^datetime(?:\(0\))?$/', false),
                ],
                'indexes' => [
                    'PRIMARY'        => self::index(['id'], true, true),
                    'event_created'  => self::index(['event_id', 'created_at', 'id']),
                    'entity_history' => self::index(['entity_type', 'entity_id', 'id']),
                    'actor_created'  => self::index(['actor_user_id', 'created_at', 'id']),
                ],
            ],
        ];
    }

    private static function column($definition, $type_pattern, $nullable, $default = null, $auto_increment = false)
    {
        return compact('definition', 'type_pattern', 'nullable', 'default', 'auto_increment');
    }

    private static function index(array $columns, $unique = false, $primary = false)
    {
        return compact('columns', 'unique', 'primary');
    }

    /** @return array<string,string> */
    private static function schema_sql()
    {
        global $wpdb;

        $tables = self::table_names();
        $charset_collate = $wpdb->get_charset_collate();

        return [
            'groups' => "CREATE TABLE {$tables['groups']} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id BIGINT(20) UNSIGNED NOT NULL,
                name VARCHAR(191) NOT NULL,
                name_key VARCHAR(160) NULL DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                default_slot TINYINT(1) UNSIGNED NULL DEFAULT NULL,
                created_by_user_id BIGINT(20) UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                archived_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_active_name (event_id, name_key),
                UNIQUE KEY event_default_slot (event_id, default_slot),
                KEY event_status (event_id, status, id)
            ) ENGINE=InnoDB $charset_collate;",
            'memberships' => "CREATE TABLE {$tables['memberships']} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id BIGINT(20) UNSIGNED NOT NULL,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                role VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_by_user_id BIGINT(20) UNSIGNED NOT NULL,
                activated_at DATETIME NOT NULL,
                revoked_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                allocated_quota INT(10) UNSIGNED NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_user (event_id, user_id),
                KEY event_status_role (event_id, status, role, id),
                KEY user_status (user_id, status, event_id)
            ) ENGINE=InnoDB $charset_collate;",
            'access' => "CREATE TABLE {$tables['access']} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id BIGINT(20) UNSIGNED NOT NULL,
                membership_id BIGINT(20) UNSIGNED NOT NULL,
                group_id BIGINT(20) UNSIGNED NOT NULL,
                granted_by_user_id BIGINT(20) UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY membership_group (membership_id, group_id),
                KEY event_membership (event_id, membership_id, group_id),
                KEY event_group (event_id, group_id, membership_id)
            ) ENGINE=InnoDB $charset_collate;",
            'assignments' => "CREATE TABLE {$tables['assignments']} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id BIGINT(20) UNSIGNED NOT NULL,
                guest_phone VARCHAR(32) NOT NULL,
                group_id BIGINT(20) UNSIGNED NOT NULL,
                assigned_by_user_id BIGINT(20) UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_guest (event_id, guest_phone),
                KEY event_group (event_id, group_id, guest_phone)
            ) ENGINE=InnoDB $charset_collate;",
            'audit' => "CREATE TABLE {$tables['audit']} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id BIGINT(20) UNSIGNED NOT NULL,
                actor_user_id BIGINT(20) UNSIGNED NOT NULL,
                action VARCHAR(40) NOT NULL,
                entity_type VARCHAR(32) NOT NULL,
                entity_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                metadata LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY event_created (event_id, created_at, id),
                KEY entity_history (entity_type, entity_id, id),
                KEY actor_created (actor_user_id, created_at, id)
            ) ENGINE=InnoDB $charset_collate;",
        ];
    }

    private static function ensure_tables_and_columns()
    {
        $tables = self::table_names();
        $specs = self::table_specs();
        $sql = self::schema_sql();

        foreach ($tables as $key => $table) {
            if (!self::table_exists($table)) {
                if (!function_exists('dbDelta')) {
                    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                }
                dbDelta($sql[$key]);
                if (!self::table_exists($table)) {
                    return self::fail('table_create_failed_' . $key);
                }
                if (self::read_table_metadata($key, $table) === false) {
                    return false;
                }
                continue;
            }

            // Existing non-transactional tables are operational drift, not a
            // safe additive repair. Never rebuild or convert them implicitly.
            if (self::read_table_metadata($key, $table) === false) {
                return false;
            }

            if (!self::ensure_existing_columns($key, $table, $specs[$key]['columns'])) {
                return false;
            }
        }

        return true;
    }

    private static function ensure_existing_columns($key, $table, array $expected)
    {
        global $wpdb;

        $actual = self::read_columns($table);
        if ($actual === null) {
            return self::fail('column_read_failed_' . $key);
        }

        foreach ($expected as $name => $definition) {
            if (isset($actual[$name])) {
                continue;
            }

            if (!$definition['nullable'] && $definition['default'] === null) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
                if ($count === null || (int) $count !== 0) {
                    return self::fail('unsafe_required_column_' . $key);
                }
            }

            $result = $wpdb->query("ALTER TABLE $table ADD COLUMN $name {$definition['definition']}");
            if ($result === false) {
                return self::fail('column_add_failed_' . $key);
            }

            $actual = self::read_columns($table);
            if ($actual === null || !isset($actual[$name])) {
                return self::fail('column_add_not_verified_' . $key);
            }
        }

        return true;
    }

    private static function ensure_indexes()
    {
        global $wpdb;

        $tables = self::table_names();
        $specs = self::table_specs();

        foreach ($tables as $key => $table) {
            $actual = self::read_indexes($table);
            if ($actual === null) {
                return self::fail('index_read_failed_' . $key);
            }

            foreach ($specs[$key]['indexes'] as $name => $definition) {
                if (isset($actual[$name])) {
                    continue;
                }

                $columns = implode(', ', $definition['columns']);
                if ($definition['primary']) {
                    $clause = "PRIMARY KEY ($columns)";
                } elseif ($definition['unique']) {
                    $clause = "UNIQUE KEY $name ($columns)";
                } else {
                    $clause = "KEY $name ($columns)";
                }

                $result = $wpdb->query("ALTER TABLE $table ADD $clause");
                if ($result === false) {
                    return self::fail('index_add_failed_' . $key . '_' . strtolower($name));
                }

                $actual = self::read_indexes($table);
                if ($actual === null || !isset($actual[$name])) {
                    return self::fail('index_add_not_verified_' . $key . '_' . strtolower($name));
                }
            }
        }

        return true;
    }

    private static function schema_postconditions_hold()
    {
        $tables = self::table_names();
        $specs = self::table_specs();

        foreach ($tables as $key => $table) {
            if (!self::table_exists($table)) {
                return self::fail('table_missing_' . $key);
            }

            $table_metadata = self::read_table_metadata($key, $table);
            if ($table_metadata === false) {
                return false;
            }

            $columns = self::read_columns($table);
            if (!self::columns_match($key, $columns, $specs[$key]['columns'], $table_metadata)) {
                return false;
            }

            $indexes = self::read_indexes($table);
            if (!self::indexes_match($key, $indexes, $specs[$key]['indexes'], $columns, array_keys($specs[$key]['columns']))) {
                return false;
            }

            if (!self::constraints_match($key, $table)) {
                return false;
            }
        }

        return self::data_postconditions_hold();
    }

    private static function columns_match($key, $actual, array $expected, array $table_metadata)
    {
        if ($actual === null) {
            return self::fail('column_read_failed_' . $key);
        }

        foreach ($expected as $name => $definition) {
            if (!isset($actual[$name])) {
                return self::fail('column_missing_' . $key . '_' . $name);
            }

            $column = $actual[$name];
            $type = strtolower(trim((string) ($column['Type'] ?? '')));
            if (preg_match($definition['type_pattern'], $type) !== 1) {
                return self::fail('column_type_mismatch_' . $key . '_' . $name);
            }

            $nullable = strtoupper(trim((string) ($column['Null'] ?? ''))) === 'YES';
            if ($nullable !== (bool) $definition['nullable']) {
                return self::fail('column_nullability_mismatch_' . $key . '_' . $name);
            }

            $actual_default = array_key_exists('Default', $column) ? $column['Default'] : null;
            if ($actual_default !== $definition['default']) {
                return self::fail('column_default_mismatch_' . $key . '_' . $name);
            }

            $extra = strtolower((string) ($column['Extra'] ?? ''));
            $has_auto_increment = strpos($extra, 'auto_increment') !== false;
            if ($has_auto_increment !== (bool) $definition['auto_increment']) {
                return self::fail('column_auto_increment_mismatch_' . $key . '_' . $name);
            }

            if (self::is_text_column($type)
                && !self::column_collation_matches($key, $name, $column, $table_metadata)) {
                return false;
            }
        }

        // An unknown nullable/defaulted column can coexist safely. An unknown
        // required column without a default would make approved inserts fail.
        foreach ($actual as $name => $column) {
            if (isset($expected[$name])) {
                continue;
            }
            $nullable = strtoupper(trim((string) ($column['Null'] ?? ''))) === 'YES';
            $has_default = array_key_exists('Default', $column) && $column['Default'] !== null;
            $extra = strtolower(trim((string) ($column['Extra'] ?? '')));
            $auto_increment = strpos($extra, 'auto_increment') !== false;
            $generated = preg_match('/\b(?:virtual|stored)\s+generated\b|\bgenerated\s+(?:virtual|stored)\b/i', $extra) === 1;
            // Every owned table already has its contract primary AUTO_INCREMENT;
            // a second one is not a realizable MySQL/MariaDB table shape.
            if ($auto_increment) {
                return self::fail('conflicting_auto_increment_column_' . $key);
            }
            if (!$nullable && !$has_default && !$auto_increment && !$generated) {
                return self::fail('conflicting_required_column_' . $key);
            }
            $type = strtolower(trim((string) ($column['Type'] ?? '')));
            if (self::is_text_column($type)
                && !self::column_collation_matches($key, $name, $column, $table_metadata)) {
                return false;
            }
        }

        return true;
    }

    private static function indexes_match($key, $actual, array $expected, array $actual_columns, array $contract_columns)
    {
        if ($actual === null) {
            return self::fail('index_read_failed_' . $key);
        }

        foreach ($expected as $name => $definition) {
            if (!isset($actual[$name])) {
                return self::fail('index_missing_' . $key . '_' . strtolower($name));
            }

            if ($actual[$name]['columns'] !== $definition['columns']) {
                return self::fail('index_columns_mismatch_' . $key . '_' . strtolower($name));
            }

            if (!$actual[$name]['parts_valid'] || !self::index_is_full_column($actual[$name])) {
                return self::fail('index_prefix_mismatch_' . $key . '_' . strtolower($name));
            }

            $is_unique = (int) $actual[$name]['non_unique'] === 0;
            if ($is_unique !== (bool) $definition['unique']) {
                return self::fail('index_uniqueness_mismatch_' . $key . '_' . strtolower($name));
            }
        }

        $approved_unique_shapes = [];
        foreach ($expected as $definition) {
            if ($definition['unique']) {
                $approved_unique_shapes[] = $definition['columns'];
            }
        }

        // Extra ordinary indexes and redundant aliases of approved unique
        // shapes are harmless. A new unique shape over contract columns can
        // reject otherwise valid domain writes.
        foreach ($actual as $name => $definition) {
            if (!$definition['parts_valid']) {
                return self::fail('index_metadata_invalid_' . $key);
            }
            if (isset($expected[$name]) || (int) $definition['non_unique'] !== 0) {
                continue;
            }
            if ($definition['parts_valid']
                && self::index_is_full_column($definition)
                && in_array($definition['columns'], $approved_unique_shapes, true)) {
                continue;
            }
            if (array_intersect($definition['columns'], $contract_columns)
                || !self::extra_unique_is_safe($definition, $actual_columns, $contract_columns)) {
                return self::fail('conflicting_unique_index_' . $key);
            }
        }

        return true;
    }

    private static function extra_unique_is_safe(array $index, array $actual_columns, array $contract_columns)
    {
        if (!$index['parts_valid'] || !self::index_is_full_column($index)) {
            return false;
        }

        $has_omitted_null = false;
        foreach ($index['columns'] as $name) {
            if (in_array($name, $contract_columns, true) || !isset($actual_columns[$name])) {
                return false;
            }

            $column = $actual_columns[$name];
            $extra = strtolower(trim((string) ($column['Extra'] ?? '')));
            if (preg_match('/\b(?:virtual|stored)\s+generated\b|\bgenerated\s+(?:virtual|stored)\b/i', $extra)) {
                return false;
            }

            $nullable = strtoupper(trim((string) ($column['Null'] ?? ''))) === 'YES';
            $default = array_key_exists('Default', $column) ? $column['Default'] : null;
            if ($nullable && $default === null) {
                $has_omitted_null = true;
                continue;
            }

            return false;
        }

        return $has_omitted_null;
    }

    private static function index_is_full_column(array $index)
    {
        foreach ($index['sub_parts'] as $sub_part) {
            if ($sub_part !== null) {
                return false;
            }
        }
        return count($index['columns']) === count($index['sub_parts']);
    }

    private static function data_postconditions_hold()
    {
        global $wpdb;

        $t = self::table_names();
        $checks = [
            'invalid_default_slot' => "SELECT COUNT(*) FROM {$t['groups']} WHERE default_slot IS NOT NULL AND default_slot <> 1",
            'invalid_group_status' => "SELECT COUNT(*) FROM {$t['groups']} WHERE status NOT IN ('active', 'archived')",
            'invalid_active_group' => "SELECT COUNT(*) FROM {$t['groups']} WHERE status = 'active' AND (name_key IS NULL OR name_key = '')",
            'invalid_archived_group' => "SELECT COUNT(*) FROM {$t['groups']} WHERE status = 'archived' AND (name_key IS NOT NULL OR default_slot IS NOT NULL)",
            'invalid_membership_role' => "SELECT COUNT(*) FROM {$t['memberships']} WHERE role NOT IN ('manager', 'viewer')",
            'invalid_membership_status' => "SELECT COUNT(*) FROM {$t['memberships']} WHERE status NOT IN ('active', 'revoked')",
            'duplicate_group_id' => "SELECT COUNT(*) FROM (SELECT id FROM {$t['groups']} GROUP BY id HAVING COUNT(*) > 1) pge_h1a_duplicates",
            'duplicate_membership_id' => "SELECT COUNT(*) FROM (SELECT id FROM {$t['memberships']} GROUP BY id HAVING COUNT(*) > 1) pge_h1a_duplicates",
            'duplicate_group_access_id' => "SELECT COUNT(*) FROM (SELECT id FROM {$t['access']} GROUP BY id HAVING COUNT(*) > 1) pge_h1a_duplicates",
            'duplicate_assignment_id' => "SELECT COUNT(*) FROM (SELECT id FROM {$t['assignments']} GROUP BY id HAVING COUNT(*) > 1) pge_h1a_duplicates",
            'duplicate_audit_id' => "SELECT COUNT(*) FROM (SELECT id FROM {$t['audit']} GROUP BY id HAVING COUNT(*) > 1) pge_h1a_duplicates",
            'duplicate_group_name' => "SELECT COUNT(*) FROM (SELECT event_id, name_key FROM {$t['groups']} WHERE name_key IS NOT NULL GROUP BY event_id, name_key HAVING COUNT(*) > 1) pge_h1a_duplicates",
            'duplicate_default_group' => "SELECT COUNT(*) FROM (SELECT event_id, default_slot FROM {$t['groups']} WHERE default_slot IS NOT NULL GROUP BY event_id, default_slot HAVING COUNT(*) > 1) pge_h1a_duplicates",
            'duplicate_membership' => "SELECT COUNT(*) FROM (SELECT event_id, user_id FROM {$t['memberships']} GROUP BY event_id, user_id HAVING COUNT(*) > 1) pge_h1a_duplicates",
            'duplicate_group_access' => "SELECT COUNT(*) FROM (SELECT membership_id, group_id FROM {$t['access']} GROUP BY membership_id, group_id HAVING COUNT(*) > 1) pge_h1a_duplicates",
            'duplicate_assignment' => "SELECT COUNT(*) FROM (SELECT event_id, guest_phone FROM {$t['assignments']} GROUP BY event_id, guest_phone HAVING COUNT(*) > 1) pge_h1a_duplicates",
            'cross_event_group_access' => "SELECT COUNT(*) FROM {$t['access']} a LEFT JOIN {$t['memberships']} m ON m.id = a.membership_id LEFT JOIN {$t['groups']} g ON g.id = a.group_id WHERE m.id IS NULL OR g.id IS NULL OR a.event_id <> m.event_id OR a.event_id <> g.event_id",
            'cross_event_assignment' => "SELECT COUNT(*) FROM {$t['assignments']} a LEFT JOIN {$t['groups']} g ON g.id = a.group_id WHERE g.id IS NULL OR a.event_id <> g.event_id",
        ];

        foreach ($checks as $code => $sql) {
            $count = $wpdb->get_var($sql);
            if ($count === null) {
                return self::fail('diagnostic_failed_' . $code);
            }
            if ((int) $count > 0) {
                return self::fail($code);
            }
        }

        return true;
    }

    private static function table_exists($table)
    {
        global $wpdb;
        $pattern = $wpdb->esc_like($table);
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $pattern)) === $table;
    }

    /** @return array<string,array>|null */
    private static function read_columns($table)
    {
        global $wpdb;
        $rows = $wpdb->get_results("SHOW FULL COLUMNS FROM $table", ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return null;
        }

        $columns = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Field'] ?? '');
            if ($name !== '') {
                $columns[$name] = $row;
            }
        }
        return $columns;
    }

    /** @return array<string,array{non_unique:int,columns:array<int,string>,sub_parts:array<int,int|null>,parts_valid:bool}>|null */
    private static function read_indexes($table)
    {
        global $wpdb;
        $rows = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return null;
        }

        $indexes = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!isset($indexes[$name])) {
                $non_unique = filter_var($row['Non_unique'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
                $indexes[$name] = [
                    'non_unique' => $non_unique === false ? 1 : (int) $non_unique,
                    'columns' => [],
                    'sub_parts' => [],
                    'parts_valid' => $non_unique !== false,
                ];
            } elseif ((int) ($row['Non_unique'] ?? -1) !== $indexes[$name]['non_unique']) {
                $indexes[$name]['parts_valid'] = false;
            }
            $sequence = filter_var($row['Seq_in_index'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $column = $row['Column_name'] ?? null;
            if ($sequence === false || !is_string($column) || $column === '' || isset($indexes[$name]['columns'][$sequence])) {
                $indexes[$name]['parts_valid'] = false;
                continue;
            }

            if (!array_key_exists('Sub_part', $row)) {
                $indexes[$name]['parts_valid'] = false;
                $sub_part_valid = false;
                $sub_part = null;
            } else {
                [$sub_part_valid, $sub_part] = self::normalize_index_sub_part($row['Sub_part']);
            }
            if (!$sub_part_valid) {
                $indexes[$name]['parts_valid'] = false;
            }
            $indexes[$name]['columns'][$sequence] = $column;
            $indexes[$name]['sub_parts'][$sequence] = $sub_part;
        }

        foreach ($indexes as $name => $definition) {
            ksort($indexes[$name]['columns']);
            ksort($indexes[$name]['sub_parts']);
            $sequences = array_keys($indexes[$name]['columns']);
            if ($sequences !== range(1, count($sequences))) {
                $indexes[$name]['parts_valid'] = false;
            }
            $indexes[$name]['columns'] = array_values($indexes[$name]['columns']);
            $indexes[$name]['sub_parts'] = array_values($indexes[$name]['sub_parts']);
        }
        return $indexes;
    }

    private static function normalize_index_sub_part($value)
    {
        if ($value === null || $value === '') {
            return [true, null];
        }
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $normalized === false ? [false, null] : [true, (int) $normalized];
    }

    private static function database_name()
    {
        global $wpdb;
        if (self::$database_name !== null) {
            return self::$database_name;
        }
        $database = $wpdb->get_var('SELECT DATABASE()');
        if (!is_string($database) || $database === '') {
            return false;
        }
        self::$database_name = $database;
        return self::$database_name;
    }

    private static function constraints_match($key, $table)
    {
        global $wpdb;
        $database = self::database_name();
        if ($database === false) {
            return self::fail('constraint_metadata_database_failed_' . $key);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            $database,
            $table
        ), ARRAY_A);
        if (!is_array($rows)) {
            return self::fail('constraint_metadata_read_failed_' . $key);
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['CONSTRAINT_TYPE']) || !is_string($row['CONSTRAINT_TYPE'])) {
                return self::fail('constraint_metadata_shape_invalid_' . $key);
            }
            $type = strtoupper(trim($row['CONSTRAINT_TYPE']));
            if ($type === 'FOREIGN KEY' || $type === 'CHECK') {
                return self::fail('conflicting_constraint_' . $key);
            }
            if ($type !== 'PRIMARY KEY' && $type !== 'UNIQUE') {
                return self::fail('constraint_metadata_type_unknown_' . $key);
            }
        }

        return true;
    }

    private static function read_table_metadata($key, $table)
    {
        global $wpdb;
        $database = self::database_name();
        if ($database === false) {
            self::fail('table_collation_database_failed_' . $key);
            return false;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT t.ENGINE, t.TABLE_COLLATION, c.CHARACTER_SET_NAME FROM information_schema.TABLES t LEFT JOIN information_schema.COLLATIONS c ON c.COLLATION_NAME = t.TABLE_COLLATION WHERE t.TABLE_SCHEMA = %s AND t.TABLE_NAME = %s',
            $database,
            $table
        ), ARRAY_A);
        if (!is_array($row)
            || !array_key_exists('ENGINE', $row)
            || !is_string($row['ENGINE'])) {
            self::fail('engine_metadata_invalid_' . $key);
            return false;
        }

        // Strip only explicitly approved textual ASCII whitespace. PHP's
        // default trim mask also strips NUL, which must remain a mismatch.
        $engine = trim($row['ENGINE'], " \t\n\r\v\f");
        if ($engine === '') {
            self::fail('engine_metadata_invalid_' . $key);
            return false;
        }

        if (strcasecmp($engine, 'InnoDB') !== 0) {
            self::fail('engine_mismatch_' . $key);
            return false;
        }

        if (!is_string($row['TABLE_COLLATION'] ?? null)
            || $row['TABLE_COLLATION'] === ''
            || !is_string($row['CHARACTER_SET_NAME'] ?? null)
            || $row['CHARACTER_SET_NAME'] === '') {
            self::fail('table_collation_read_failed_' . $key);
            return false;
        }

        $actual_collation = strtolower($row['TABLE_COLLATION']);
        $actual_charset = strtolower($row['CHARACTER_SET_NAME']);
        $expected_charset = strtolower(trim((string) ($wpdb->charset ?? '')));
        $expected_collation = strtolower(trim((string) ($wpdb->collate ?? '')));
        if ($expected_charset === '' || $actual_charset !== $expected_charset) {
            self::fail('table_charset_mismatch_' . $key);
            return false;
        }
        if ($expected_collation !== '' && $actual_collation !== $expected_collation) {
            self::fail('table_collation_mismatch_' . $key);
            return false;
        }

        return ['engine' => 'innodb', 'collation' => $actual_collation, 'charset' => $actual_charset];
    }

    private static function column_collation_matches($key, $name, array $column, array $table_metadata)
    {
        global $wpdb;
        $actual = $column['Collation'] ?? null;
        if (!is_string($actual) || $actual === '') {
            return self::fail('column_collation_missing_' . $key . '_' . $name);
        }
        $actual = strtolower($actual);
        $expected = strtolower(trim((string) ($wpdb->collate ?? '')));
        if ($expected === '') {
            $expected = $table_metadata['collation'];
        }
        if ($actual !== $expected
            || $table_metadata['charset'] !== strtolower(trim((string) ($wpdb->charset ?? '')))) {
            return self::fail('column_collation_mismatch_' . $key . '_' . $name);
        }
        return true;
    }

    private static function is_text_column($type)
    {
        return preg_match('/^(?:varchar\(\d+\)|longtext)$/i', trim((string) $type)) === 1;
    }

    private static function fail($code)
    {
        self::$last_error_code = (string) $code;
        if (!self::$suppress_logging) {
            error_log('PGE event access schema: ' . self::$last_error_code . '.');
        }
        return false;
    }
}

register_activation_hook(PGE_PATH . 'pgevents-core.php', ['PGE_Event_Access_Schema', 'activate']);
add_action('plugins_loaded', ['PGE_Event_Access_Schema', 'maybe_upgrade']);
