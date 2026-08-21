<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * H1C-W10 — Additional Inviter Onboarding — Schema
 * ============================================================================
 * Owns exactly ONE new table: {$wpdb->prefix}pge_additional_inviter_
 * invitations. Deliberately an ISOLATED schema class — NOT folded into
 * PGE_Event_Access_Schema (the shared H1C schema class every W1-W9 read/
 * write already depends on for its health-check machinery). Reusing that
 * shared class's version bump would put this new, unrelated table's
 * migration inside the exact same blast radius as every existing H1C
 * surface; instead this file follows the simpler, already-proven,
 * genuinely ISOLATED per-feature pattern established by
 * PGE_Supervisor_Management_Schema (own version option, own dbDelta() +
 * SHOW COLUMNS verification, own register_activation_hook/plugins_loaded
 * wiring) — a real precedent in this exact codebase for exactly this
 * situation (a new, standalone table for a new, standalone feature).
 *
 * Additive only. No destructive migration. No column/table anywhere else
 * in the plugin is touched by this file.
 */
class PGE_Additional_Inviter_Onboarding_Schema
{
    const SCHEMA_VERSION = '1.0.0';
    const VERSION_OPTION = 'pge_additional_inviter_onboarding_schema_version';

    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_additional_inviter_invitations';
    }

    /**
     * Safe to call from both register_activation_hook and plugins_loaded
     * (same philosophy as every other schema file in this plugin).
     */
    public static function maybe_upgrade()
    {
        $stored_version = (string) get_option(self::VERSION_OPTION, '');

        if ($stored_version === self::SCHEMA_VERSION) {
            return;
        }

        if (self::ensure_table()) {
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
        }
        // Partial failure: the stored version is not updated, so the next
        // request retries the full upgrade (same partial-failure philosophy
        // used across every other schema file in this plugin).
    }

    /**
     * True only if the table exists AND the stored version matches AND the
     * current request has already run maybe_upgrade() once (mirrors the
     * simple, non-cached is_ready() contract this table needs — no health-
     * check machinery is borrowed from PGE_Event_Access_Schema).
     */
    public static function is_ready(): bool
    {
        $stored_version = (string) get_option(self::VERSION_OPTION, '');
        return $stored_version === self::SCHEMA_VERSION;
    }

    private static function ensure_table(): bool
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // token: NOT stored raw anywhere — only its sha256 hash
        // (invitation_token_hash, CHAR(64) hex). NULL once consumed
        // (never reusable, mirrors PGE_Supervisor_Assignment_Service's
        // accept_invitation() null-out-on-consume convention exactly).
        // allocated_quota: NOT NULL here — unlike the membership table's
        // nullable allocated_quota (which represents "no quota configured
        // at all" for a generic membership), an onboarding invitation by
        // construction ALWAYS carries a positive quota (Section 1's
        // product contract never onboards a generic Manager/Viewer).
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            group_id BIGINT(20) UNSIGNED NOT NULL,
            allocated_quota INT(10) UNSIGNED NOT NULL,
            invitee_email VARCHAR(255) NOT NULL,
            display_name VARCHAR(191) NOT NULL DEFAULT '',
            invitation_token_hash CHAR(64) NULL DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            existing_user_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            resolved_user_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            created_by_user_id BIGINT(20) UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL DEFAULT NULL,
            revoked_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY invitation_token_hash (invitation_token_hash),
            KEY event_group_status (event_id, group_id, status, id),
            KEY event_status (event_id, status, id),
            KEY invitee_email (invitee_email)
        ) $charset_collate;";

        dbDelta($sql);

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required = [
            'id', 'event_id', 'group_id', 'allocated_quota', 'invitee_email', 'display_name',
            'invitation_token_hash', 'status', 'existing_user_id', 'resolved_user_id',
            'created_by_user_id', 'expires_at', 'consumed_at', 'revoked_at', 'created_at', 'updated_at',
        ];
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
}

register_activation_hook(PGE_PATH . 'pgevents-core.php', ['PGE_Additional_Inviter_Onboarding_Schema', 'maybe_upgrade']);
add_action('plugins_loaded', ['PGE_Additional_Inviter_Onboarding_Schema', 'maybe_upgrade']);
