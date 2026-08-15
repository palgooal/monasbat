<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Event Guest Read Projection Schema — Phase H1C-GR1
 * ============================================================================
 * DEC-004 (docs/DECISION-LOG.md): `_pge_invited_guests` (WordPress Post Meta)
 * remains the sole Source of Truth for guest identity. This file owns the
 * structure of two derived, fully rebuildable, non-authoritative tables:
 *
 *   1. {$wpdb->prefix}pge_event_guest_read_projection — a queryable SQL copy
 *      of the guest-identity fields not currently scope-fetchable from Post
 *      Meta (phone/name/note/code only). Rows are tagged with a `generation`
 *      (Freshness Publication Protocol Redesign, this pass): each build
 *      writes to its OWN, previously-unused generation — it never deletes or
 *      overwrites another generation's rows, including the currently active
 *      one. UNIQUE(event_id, generation, guest_phone).
 *   2. {$wpdb->prefix}pge_event_guest_read_projection_state — one row per
 *      event_id holding which generation is currently ACTIVE (trusted,
 *      servable to readers) plus the canonical content fingerprint that
 *      generation was verified against at publish time. See
 *      PGE_Event_Guest_Read_Projection's class docblock for the full
 *      publication protocol and why this design change was necessary.
 *
 * ── Freshness Publication Protocol Redesign (this pass) ─────────────────────
 * The PREVIOUS fix pass (monotonic expected_generation/ready_generation +
 * CAS) is superseded here. It was found to have a real gap: generation
 * numbers were minted in sync_event(), which runs AFTER its own Post Meta
 * write — meaning generation order reflected "order in which projection
 * syncs happened to run", not "order in which Post Meta writes actually
 * landed". A writer whose Post Meta write happened first but whose sync
 * merely started/finished later could still mint a NUMERICALLY HIGHER
 * generation than a writer whose Post Meta write happened more recently,
 * and publish itself as trusted READY over genuinely newer data. See
 * PGE_Event_Guest_Read_Projection's docblock, "Why generation numbers no
 * longer carry authority", for the full analysis and the replacement
 * protocol (content-fingerprint verification against a freshly-read,
 * WordPress-object-cache-bypassing view of Post Meta, performed
 * IMMEDIATELY before any publish/activation).
 *
 * SCHEMA_VERSION bumped to 2.0.0 (structural: `generation` column + new
 * UNIQUE key on the projection table; `active_generation`/
 * `active_fingerprint`/`next_generation` replace the previous
 * `expected_generation`/`ready_generation` on the state table). This is a
 * redesign WITHIN GR1 itself — GR1 has not been committed yet — not a
 * historical migration on top of a shipped GR1.
 *
 * Physical health checks (unchanged in spirit from the previous pass, only
 * the exact key shape changed): InnoDB engine on both tables,
 * UNIQUE(event_id, generation, guest_phone) on the projection table,
 * PRIMARY KEY(event_id) on the state table. If VERSION_OPTION already
 * matches SCHEMA_VERSION but the physical structure does not actually have
 * these, maybe_upgrade() re-runs dbDelta() rather than trusting the stored
 * version number alone.
 *
 * ownership/rollback: both tables are fully derived and disposable — deleted
 * at any time, they are correctly rebuilt from Post Meta via
 * PGE_Event_Guest_Read_Projection::rebuild_event(). This file/these tables
 * never touch PGE_Event_Access_Schema.
 */
class PGE_Event_Guest_Read_Projection_Schema
{
    const SCHEMA_VERSION = '2.0.0';
    const VERSION_OPTION = 'pge_event_guest_read_projection_schema_version';

    public static function projection_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_event_guest_read_projection';
    }

    public static function state_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_event_guest_read_projection_state';
    }

    public static function maybe_upgrade()
    {
        $stored_version = (string) get_option(self::VERSION_OPTION, '');

        if ($stored_version === self::SCHEMA_VERSION && self::is_physically_healthy()) {
            return;
        }

        $projection_ok = self::ensure_projection_table();
        $state_ok = self::ensure_state_table();

        if ($projection_ok && $state_ok && self::is_physically_healthy()) {
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
            return;
        }
        // فشل جزئي أو بنية غير سليمة فعلياً: لا يُحدَّث الإصدار المخزَّن،
        // فيُعاد المحاولة كاملةً (بما فيها فحص السلامة) في الطلب التالي.
    }

    private static function ensure_projection_table(): bool
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::projection_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // generation: which build this row belongs to. A build writes ONLY
        // to its own, never-before-used generation — it never touches any
        // other generation's rows (including whichever is currently
        // active), so a failing/discarded build can never corrupt or
        // destroy what readers are currently being served. See the
        // Freshness Publication Protocol Redesign note above.
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            generation BIGINT(20) UNSIGNED NOT NULL,
            guest_phone VARCHAR(32) NOT NULL,
            guest_name VARCHAR(191) NOT NULL DEFAULT '',
            guest_note TEXT NULL DEFAULT NULL,
            guest_code VARCHAR(16) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_generation_guest (event_id, generation, guest_phone)
        ) $charset_collate;";

        dbDelta($sql);

        return self::table_has_columns($table, [
            'id', 'event_id', 'generation', 'guest_phone', 'guest_name', 'guest_note', 'guest_code', 'updated_at',
        ]);
    }

    private static function ensure_state_table(): bool
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::state_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // active_generation/active_fingerprint: which build is currently
        // trusted, and the canonical content fingerprint it was verified
        // against at the moment it was activated — the actual fact that
        // makes a projection READY (see PGE_Event_Guest_Read_Projection's
        // docblock). next_generation: a plain, non-authoritative slot
        // counter used only to hand each new build attempt a fresh, unused
        // generation id — it carries no ordering/authority meaning by
        // itself.
        $sql = "CREATE TABLE $table (
            event_id BIGINT(20) UNSIGNED NOT NULL,
            active_generation BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            active_fingerprint VARCHAR(64) NOT NULL DEFAULT '',
            next_generation BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'stale',
            row_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
            synced_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (event_id)
        ) $charset_collate;";

        dbDelta($sql);

        return self::table_has_columns($table, [
            'event_id', 'active_generation', 'active_fingerprint', 'next_generation',
            'status', 'row_count', 'synced_at', 'updated_at',
        ]);
    }

    private static function table_has_columns($table, array $required): bool
    {
        global $wpdb;

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $found = [];
        foreach ($columns as $column) {
            $found[] = (string) ($column['Field'] ?? '');
        }

        foreach ($required as $r) {
            if (!in_array($r, $found, true)) {
                return false;
            }
        }

        return true;
    }

    private static function is_physically_healthy(): bool
    {
        $projection_table = self::projection_table_name();
        $state_table = self::state_table_name();

        return self::table_has_columns($projection_table, [
                'id', 'event_id', 'generation', 'guest_phone', 'guest_name', 'guest_note', 'guest_code', 'updated_at',
            ])
            && self::table_engine_is_innodb($projection_table)
            && self::table_has_unique_key_covering($projection_table, ['event_id', 'generation', 'guest_phone'])
            && self::table_has_columns($state_table, [
                'event_id', 'active_generation', 'active_fingerprint', 'next_generation',
                'status', 'row_count', 'synced_at', 'updated_at',
            ])
            && self::table_engine_is_innodb($state_table)
            && self::table_has_primary_key_covering($state_table, ['event_id']);
    }

    private static function table_engine_is_innodb($table): bool
    {
        global $wpdb;

        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));

        return is_string($engine) && strcasecmp($engine, 'InnoDB') === 0;
    }

    private static function table_has_unique_key_covering($table, array $columns): bool
    {
        return self::table_has_key_covering($table, $columns, false);
    }

    private static function table_has_primary_key_covering($table, array $columns): bool
    {
        return self::table_has_key_covering($table, $columns, true);
    }

    private static function table_has_key_covering($table, array $columns, bool $primary_only): bool
    {
        global $wpdb;

        $index_rows = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if (!is_array($index_rows)) {
            return false;
        }

        $by_key = [];
        foreach ($index_rows as $row) {
            if (!is_array($row) || !isset($row['Key_name'], $row['Column_name'], $row['Non_unique'])) {
                return false;
            }
            $key_name = (string) $row['Key_name'];
            if ($primary_only && $key_name !== 'PRIMARY') {
                continue;
            }
            if (!$primary_only && ((int) $row['Non_unique']) !== 0) {
                continue;
            }
            $by_key[$key_name][] = (string) $row['Column_name'];
        }

        sort($columns);
        foreach ($by_key as $cols) {
            $sorted = $cols;
            sort($sorted);
            if ($sorted === $columns) {
                return true;
            }
        }

        return false;
    }
}

register_activation_hook(PGE_PATH . 'pgevents-core.php', ['PGE_Event_Guest_Read_Projection_Schema', 'maybe_upgrade']);
add_action('plugins_loaded', ['PGE_Event_Guest_Read_Projection_Schema', 'maybe_upgrade']);
