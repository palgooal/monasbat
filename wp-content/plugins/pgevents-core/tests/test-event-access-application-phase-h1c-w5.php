<?php
/**
 * Phase H1C-W5 — Owner/Admin Membership Lifecycle Write Wiring.
 *
 * Independent suite (deliberately NOT added inside H1C-W4 — same precedent
 * as W3/W4's own independent files: "Membership Lifecycle" is a distinct
 * write domain from "Group Lifecycle"). Exercises the real
 * PGE_Event_Access_Application_Service write methods
 * (create_membership_for_actor / change_membership_role_for_actor /
 * revoke_membership_for_actor / reactivate_membership_for_actor), the real
 * PGE_Event_Access_Authorization[_Context] decision layer (unmodified —
 * can_manage_membership() is the same flat Owner/Admin-only gate shape
 * already proven for can_manage_event_structure()/can_manage_group_access()
 * in H1C-W3/W4), the real PGE_Event_Access_Repository transactional
 * mutation methods (create_membership()/change_membership_role()/
 * revoke_membership()/reactivate_membership() — unmodified), and the real
 * AJAX handlers in event-access-ajax.php
 * (pge_event_access_create_membership_handler /
 * pge_event_access_change_membership_role_handler /
 * pge_event_access_revoke_membership_handler /
 * pge_event_access_reactivate_membership_handler) — against a small
 * in-memory fake $wpdb (H1B relational tables only) and real WordPress
 * function shims. No real database, no real network, no UI.
 *
 * This domain is the fourth and final write surface wired through the
 * Application Service. Unlike W2's partially Manager-scoped guest-assignment
 * surface, W3/W4/W5 use flat Owner/Admin-only management capabilities.
 * Among these Owner/Admin-only domains, W5's Repository methods are also
 * the ones that consult get_userdata() (require_user()), and its REVOKE
 * operation performs a genuine same-transaction CASCADE delete against a
 * second table (group-access) — this suite therefore also models a real
 * $wpdb->delete() call (W3/W4's fake $wpdb never needed one to behave
 * correctly; H1B-W2's own fake $wpdb is the closest precedent and is the
 * model followed here).
 *
 * Per the H1C-W5 brief: REVOKE is "the most important operation" because of
 * its cascade — Sections F/Q below place extra weight on BEFORE/AFTER state
 * assertions (not mutation counters alone) and on proving the cascade is
 * atomic (a forced mid-cascade failure rolls back BOTH the membership row
 * and the group-access rows to their pre-transaction state).
 *
 * Run: php tests/test-event-access-application-phase-h1c-w5.php
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

// ──────────────────────────────────────────────────────────────
// Minimal WordPress error primitive
// ──────────────────────────────────────────────────────────────

class WP_Error
{
    private $code;
    private $message;

    public function __construct($code = '', $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

// ──────────────────────────────────────────────────────────────
// Hook registration
// ──────────────────────────────────────────────────────────────

$GLOBALS['w5_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['w5_registered_hooks'][$hook] = true; }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

// ──────────────────────────────────────────────────────────────
// WordPress function shims
// ──────────────────────────────────────────────────────────────

function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function wp_unslash($value)
{
    if (is_array($value)) return array_map('wp_unslash', $value);
    return is_string($value) ? stripslashes($value) : $value;
}
function wp_json_encode($data) { return json_encode($data); }
function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; }

$GLOBALS['w5_current_user_id'] = 0;
$GLOBALS['w5_logged_in'] = true;
$GLOBALS['w5_admins'] = [];
function get_current_user_id() { return $GLOBALS['w5_current_user_id']; }
function is_user_logged_in() { return $GLOBALS['w5_logged_in']; }
function user_can($user_id, $capability)
{
    if ($capability !== 'administrator') return false;
    return in_array((int) $user_id, $GLOBALS['w5_admins'], true);
}

$GLOBALS['w5_posts'] = [];
function get_post_type($post_id)
{
    return $GLOBALS['w5_posts'][(int) $post_id]['post_type'] ?? false;
}
function get_post_field($field, $post_id)
{
    if (!isset($GLOBALS['w5_posts'][(int) $post_id][$field])) return '';
    return (string) $GLOBALS['w5_posts'][(int) $post_id][$field];
}

// Target-user existence, consulted only by Repository::require_user() for
// create_membership/change_membership_role/reactivate_membership (NOT
// revoke_membership, which deliberately tolerates a deleted WP user — see
// Section F).
$GLOBALS['w5_users'] = [];
function get_userdata($user_id)
{
    return !empty($GLOBALS['w5_users'][(int) $user_id]) ? (object) ['ID' => (int) $user_id] : false;
}

// ──────────────────────────────────────────────────────────────
// Fake schema
// ──────────────────────────────────────────────────────────────

class PGE_Event_Access_Schema
{
    public static function is_ready() { return $GLOBALS['w5_schema_ready']; }
    public static function maybe_upgrade() { throw new RuntimeException('maybe_upgrade() must never be called by a W5 write path.'); }
}

// ──────────────────────────────────────────────────────────────
// In-memory fixture store + SQL dispatcher (H1B relational tables only).
// ──────────────────────────────────────────────────────────────

function w5_tables()
{
    return [
        'groups' => 'wp_pge_event_invitation_groups',
        'memberships' => 'wp_pge_event_host_memberships',
        'access' => 'wp_pge_event_host_group_access',
        'audit' => 'wp_pge_event_access_audit_log',
    ];
}

function w5_reset_db()
{
    $GLOBALS['w5_db'] = [
        'groups' => [],
        'memberships' => [],
        'access' => [],
        'audit' => [],
        'next_id' => 1,
    ];
    $GLOBALS['w5_posts'] = [];
    $GLOBALS['w5_users'] = [];
    $GLOBALS['w5_admins'] = [];
    $GLOBALS['w5_schema_ready'] = true;
    $GLOBALS['w5_current_user_id'] = 0;
    $GLOBALS['w5_logged_in'] = true;
    $GLOBALS['w5_mutation_calls'] = 0;
    $GLOBALS['w5_query_log'] = [];
    $GLOBALS['w5_force_insert_fail'] = false;
    $GLOBALS['w5_force_update_miss'] = false;
    $GLOBALS['w5_force_delete_undercount'] = false;
    $GLOBALS['w5_force_audit_fail'] = false;
}

function w5_next_id() { return $GLOBALS['w5_db']['next_id']++; }

function w5_event($event_id, $author_id)
{
    $GLOBALS['w5_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => $author_id];
}

function w5_user($user_id)
{
    $GLOBALS['w5_users'][(int) $user_id] = true;
}

function w5_group($event_id, $status = 'active', $name = 'Group', $is_default = false)
{
    $id = w5_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['w5_db']['groups'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'name' => $name,
        'name_key' => $status === 'active' ? strtolower($name) : null,
        'status' => $status, 'default_slot' => ($is_default && $status === 'active') ? 1 : null,
        'created_by_user_id' => 1, 'created_at' => $now, 'updated_at' => $now,
        'archived_at' => $status === 'archived' ? $now : null,
    ];
    return $id;
}

// Also registers the target user as a real WP user by default (matches
// the ordinary case a membership row would only exist for a real WP user).
// Tests that specifically need a dangling/deleted-user membership row
// unregister the user afterward via unset($GLOBALS['w5_users'][...]).
function w5_membership($event_id, $user_id, $role, $status = 'active')
{
    $id = w5_next_id();
    $now = '2026-01-01 00:00:00';
    w5_user($user_id);
    $GLOBALS['w5_db']['memberships'][$id] = [
        'id' => $id, 'event_id' => $event_id, 'user_id' => $user_id, 'role' => $role, 'status' => $status,
        'created_by_user_id' => 1, 'activated_at' => $now, 'revoked_at' => $status === 'revoked' ? $now : null,
        'created_at' => $now, 'updated_at' => $now,
        // H1C-W8: additive nullable column now present on every real row.
        'allocated_quota' => null,
    ];
    return $id;
}

function w5_access($event_id, $membership_id, $group_id)
{
    $id = w5_next_id();
    $GLOBALS['w5_db']['access'][] = [
        'id' => $id, 'event_id' => $event_id, 'membership_id' => $membership_id, 'group_id' => $group_id,
        'granted_by_user_id' => 1, 'created_at' => '2026-01-01 00:00:00',
    ];
    return $id;
}

function w5_access_count($membership_id)
{
    return count(array_filter($GLOBALS['w5_db']['access'], function ($r) use ($membership_id) { return $r['membership_id'] === $membership_id; }));
}

function w5_dispatch_select($sql)
{
    $t = w5_tables();
    $sql = trim($sql);

    // lock_groups()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['groups'], '/') . ' WHERE event_id = (\d+) ORDER BY id FOR UPDATE$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $rows = array_values(array_filter($GLOBALS['w5_db']['groups'], function ($r) use ($event_id) { return $r['event_id'] === $event_id; }));
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    // lock_memberships()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) ORDER BY id FOR UPDATE$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $rows = array_values(array_filter($GLOBALS['w5_db']['memberships'], function ($r) use ($event_id) { return $r['event_id'] === $event_id; }));
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    // lock_membership_access()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['access'], '/') . ' WHERE event_id = (\d+) AND membership_id = (\d+) ORDER BY id FOR UPDATE$/', $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2];
        $rows = array_values(array_filter($GLOBALS['w5_db']['access'], function ($r) use ($event_id, $membership_id) {
            return $r['event_id'] === $event_id && $r['membership_id'] === $membership_id;
        }));
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $rows;
    }

    // lock_access_relation()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['access'], '/') . ' WHERE event_id = (\d+) AND membership_id = (\d+) AND group_id = (\d+) ORDER BY id FOR UPDATE$/', $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2]; $group_id = (int) $m[3];
        return array_values(array_filter($GLOBALS['w5_db']['access'], function ($r) use ($event_id, $membership_id, $group_id) {
            return $r['event_id'] === $event_id && $r['membership_id'] === $membership_id && $r['group_id'] === $group_id;
        }));
    }

    // read_access_relation_inside_transaction()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['access'], '/') . ' WHERE event_id = (\d+) AND membership_id = (\d+) AND group_id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2]; $group_id = (int) $m[3];
        $rows = array_values(array_filter($GLOBALS['w5_db']['access'], function ($r) use ($event_id, $membership_id, $group_id) {
            return $r['event_id'] === $event_id && $r['membership_id'] === $membership_id && $r['group_id'] === $group_id;
        }));
        return array_slice($rows, 0, 2);
    }

    // read_membership_inside_transaction() / get_membership_scoped()
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $id = (int) $m[2];
        $rows = array_values(array_filter($GLOBALS['w5_db']['memberships'], function ($r) use ($event_id, $id) {
            return $r['event_id'] === $event_id && $r['id'] === $id;
        }));
        return array_slice($rows, 0, 2);
    }

    // read_membership_for_user_inside_transaction() / get_membership_for_user()
    // (the latter is called by PGE_Event_Access_Authorization_Context::resolve()
    // for every non-Owner/non-Admin actor).
    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND user_id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1]; $user_id = (int) $m[2];
        return array_values(array_filter($GLOBALS['w5_db']['memberships'], function ($r) use ($event_id, $user_id) {
            return $r['event_id'] === $event_id && $r['user_id'] === $user_id;
        }));
    }

    // list_group_ids_for_membership() join (Authorization Context resolve()
    // for an active collaborator, and W3's grant/revoke integration checks).
    $join = '/^SELECT a\.event_id, a\.group_id, g\.event_id AS related_event_id FROM '
        . preg_quote($t['access'], '/') . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.membership_id = (\d+) ORDER BY a\.group_id ASC$/';
    if (preg_match($join, $sql, $m)) {
        $event_id = (int) $m[1]; $membership_id = (int) $m[2];
        $out = [];
        foreach ($GLOBALS['w5_db']['access'] as $row) {
            if ($row['event_id'] !== $event_id || $row['membership_id'] !== $membership_id) continue;
            $group = $GLOBALS['w5_db']['groups'][$row['group_id']] ?? null;
            $out[] = ['event_id' => $row['event_id'], 'group_id' => $row['group_id'], 'related_event_id' => $group['event_id'] ?? null];
        }
        return $out;
    }

    return null;
}

function w5_dispatch_mutation($sql)
{
    $t = w5_tables();
    $sql = trim($sql);

    if ($sql === 'START TRANSACTION') {
        $GLOBALS['w5_snapshot'] = [$GLOBALS['w5_db']['memberships'], $GLOBALS['w5_db']['access'], $GLOBALS['w5_db']['audit'], $GLOBALS['w5_db']['next_id']];
        return ['handled' => true, 'result' => 0];
    }
    if ($sql === 'COMMIT') {
        $GLOBALS['w5_snapshot'] = null;
        return ['handled' => true, 'result' => 0];
    }
    if ($sql === 'ROLLBACK') {
        if ($GLOBALS['w5_snapshot'] !== null) {
            [$GLOBALS['w5_db']['memberships'], $GLOBALS['w5_db']['access'], $GLOBALS['w5_db']['audit'], $GLOBALS['w5_db']['next_id']] = $GLOBALS['w5_snapshot'];
        }
        $GLOBALS['w5_snapshot'] = null;
        return ['handled' => true, 'result' => 0];
    }

    // create_membership()'s INSERT.
    $insertMembership = '/^INSERT INTO ' . preg_quote($t['memberships'], '/')
        . ' \(event_id, user_id, role, status, created_by_user_id, activated_at, revoked_at, created_at, updated_at\) VALUES \((\d+), (\d+), \'([^\']*)\', \'([^\']*)\', (\d+), \'([^\']*)\', NULL, \'([^\']*)\', \'([^\']*)\'\)$/';
    if (preg_match($insertMembership, $sql, $m)) {
        if ($GLOBALS['w5_force_insert_fail']) {
            return ['handled' => true, 'result' => 0];
        }
        $GLOBALS['w5_mutation_calls']++;
        $id = w5_next_id();
        $GLOBALS['w5_db']['memberships'][$id] = [
            'id' => $id, 'event_id' => (int) $m[1], 'user_id' => (int) $m[2], 'role' => $m[3], 'status' => $m[4],
            'created_by_user_id' => (int) $m[5], 'activated_at' => $m[6], 'revoked_at' => null,
            'created_at' => $m[7], 'updated_at' => $m[8],
            // H1C-W8: ordinary create_membership() never sets this column;
            // it stays at its DEFAULT NULL, matching the additive schema.
            'allocated_quota' => null,
        ];
        return ['handled' => true, 'result' => 1, 'insert_id' => $id];
    }

    // change_membership_role()'s UPDATE.
    $roleUpdate = '/^UPDATE ' . preg_quote($t['memberships'], '/')
        . ' SET role = \'([^\']*)\', updated_at = \'([^\']*)\' WHERE event_id = (\d+) AND id = (\d+) AND status = \'([^\']*)\' AND role = \'([^\']*)\'$/';
    if (preg_match($roleUpdate, $sql, $m)) {
        $row = $GLOBALS['w5_db']['memberships'][(int) $m[4]] ?? null;
        if ($row === null || $row['event_id'] !== (int) $m[3] || $row['status'] !== $m[5] || $row['role'] !== $m[6]) {
            return ['handled' => true, 'result' => 0];
        }
        if ($GLOBALS['w5_force_update_miss']) { $GLOBALS['w5_force_update_miss'] = false; return ['handled' => true, 'result' => 0]; }
        $GLOBALS['w5_mutation_calls']++;
        $GLOBALS['w5_db']['memberships'][(int) $m[4]]['role'] = $m[1];
        $GLOBALS['w5_db']['memberships'][(int) $m[4]]['updated_at'] = $m[2];
        return ['handled' => true, 'result' => 1];
    }

    // revoke_membership()'s status UPDATE (only issued when status_changed,
    // i.e. the membership was active).
    $revokeUpdate = '/^UPDATE ' . preg_quote($t['memberships'], '/')
        . ' SET status = \'([^\']*)\', revoked_at = \'([^\']*)\', updated_at = \'([^\']*)\' WHERE event_id = (\d+) AND id = (\d+) AND status = \'([^\']*)\'$/';
    if (preg_match($revokeUpdate, $sql, $m)) {
        $row = $GLOBALS['w5_db']['memberships'][(int) $m[5]] ?? null;
        if ($row === null || $row['event_id'] !== (int) $m[4] || $row['status'] !== $m[6]) {
            return ['handled' => true, 'result' => 0];
        }
        if ($GLOBALS['w5_force_update_miss']) { $GLOBALS['w5_force_update_miss'] = false; return ['handled' => true, 'result' => 0]; }
        $GLOBALS['w5_mutation_calls']++;
        $GLOBALS['w5_db']['memberships'][(int) $m[5]]['status'] = $m[1];
        $GLOBALS['w5_db']['memberships'][(int) $m[5]]['revoked_at'] = $m[2];
        $GLOBALS['w5_db']['memberships'][(int) $m[5]]['updated_at'] = $m[3];
        return ['handled' => true, 'result' => 1];
    }

    // reactivate_membership()'s UPDATE.
    $reactivateUpdate = '/^UPDATE ' . preg_quote($t['memberships'], '/')
        . ' SET status = \'([^\']*)\', role = \'([^\']*)\', activated_at = \'([^\']*)\', revoked_at = NULL, updated_at = \'([^\']*)\' WHERE event_id = (\d+) AND id = (\d+) AND status = \'([^\']*)\'$/';
    if (preg_match($reactivateUpdate, $sql, $m)) {
        $row = $GLOBALS['w5_db']['memberships'][(int) $m[6]] ?? null;
        if ($row === null || $row['event_id'] !== (int) $m[5] || $row['status'] !== $m[7]) {
            return ['handled' => true, 'result' => 0];
        }
        if ($GLOBALS['w5_force_update_miss']) { $GLOBALS['w5_force_update_miss'] = false; return ['handled' => true, 'result' => 0]; }
        $GLOBALS['w5_mutation_calls']++;
        $GLOBALS['w5_db']['memberships'][(int) $m[6]]['status'] = $m[1];
        $GLOBALS['w5_db']['memberships'][(int) $m[6]]['role'] = $m[2];
        $GLOBALS['w5_db']['memberships'][(int) $m[6]]['activated_at'] = $m[3];
        $GLOBALS['w5_db']['memberships'][(int) $m[6]]['revoked_at'] = null;
        $GLOBALS['w5_db']['memberships'][(int) $m[6]]['updated_at'] = $m[4];
        return ['handled' => true, 'result' => 1];
    }

    // grant_group_access()'s INSERT (W3's public method — used only by this
    // suite's Section T compatibility/integration checks).
    $insertAccess = '/^INSERT INTO ' . preg_quote($t['access'], '/')
        . ' \(event_id, membership_id, group_id, granted_by_user_id, created_at\) VALUES \((\d+), (\d+), (\d+), (\d+), \'([^\']*)\'\)$/';
    if (preg_match($insertAccess, $sql, $m)) {
        $GLOBALS['w5_mutation_calls']++;
        $id = w5_next_id();
        $GLOBALS['w5_db']['access'][] = [
            'id' => $id, 'event_id' => (int) $m[1], 'membership_id' => (int) $m[2], 'group_id' => (int) $m[3],
            'granted_by_user_id' => (int) $m[4], 'created_at' => $m[5],
        ];
        return ['handled' => true, 'result' => 1, 'insert_id' => $id];
    }

    $insertAudit = '/^INSERT INTO ' . preg_quote($t['audit'], '/')
        . ' \(event_id, actor_user_id, action, entity_type, entity_id, metadata, created_at\) VALUES \((\d+), (\d+), \'([^\']*)\', \'([^\']*)\', (\d+), \'([^\']*)\', \'([^\']*)\'\)$/';
    if (preg_match($insertAudit, $sql, $m)) {
        if ($GLOBALS['w5_force_audit_fail']) {
            $GLOBALS['w5_force_audit_fail'] = false;
            return ['handled' => true, 'result' => 0];
        }
        $GLOBALS['w5_mutation_calls']++;
        $GLOBALS['w5_db']['audit'][] = [
            'event_id' => (int) $m[1], 'actor_user_id' => (int) $m[2], 'action' => $m[3],
            'entity_type' => $m[4], 'entity_id' => (int) $m[5],
            // See the identical note in the W3/W4 suites: prepare()'s %s
            // handling addslashes()-escapes the JSON string's own double
            // quotes before quoting it for the SQL literal, so the captured
            // text must be stripslashes()'d back before json_decode() can
            // parse it — otherwise json_decode() silently returns null.
            'metadata' => json_decode(stripslashes($m[6]), true), 'created_at' => $m[7],
        ];
        return ['handled' => true, 'result' => 1];
    }

    return ['handled' => false];
}

class PGE_W5_Fake_WPDB
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;

    public function prepare($sql, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : ("'" . addslashes((string) $v) . "'");
        }, $sql);
    }

    public function get_results($sql, $output = null)
    {
        $GLOBALS['w5_query_log'][] = $sql;
        $rows = w5_dispatch_select($sql);
        if ($rows === null) {
            $this->last_error = 'w5-fake-wpdb: unrecognized SELECT: ' . $sql;
            return null;
        }
        $this->last_error = '';
        return $rows;
    }

    public function get_var($sql)
    {
        $GLOBALS['w5_query_log'][] = $sql;
        $this->last_error = 'w5-fake-wpdb: unrecognized scalar query: ' . $sql;
        return null;
    }

    public function query($sql)
    {
        $GLOBALS['w5_query_log'][] = $sql;
        $outcome = w5_dispatch_mutation($sql);
        if (!$outcome['handled']) {
            $this->last_error = 'w5-fake-wpdb: unexpected mutating query() call: ' . $sql;
            return false;
        }
        $this->last_error = '';
        if (array_key_exists('insert_id', $outcome)) $this->insert_id = $outcome['insert_id'];
        return $outcome['result'];
    }

    /**
     * Real deletion logic (unlike W3/W4's fake $wpdb, which never needed
     * one) — this is the ONLY table any W5 Repository path deletes from
     * (group-access), via revoke_membership()'s cascade or W3's
     * revoke_group_access(). Every $where key is matched by strict integer
     * equality against the row. w5_force_delete_undercount lets a single
     * test simulate a partial/inconsistent delete: the row(s) are genuinely
     * removed from the in-memory table but the reported count is one less
     * than actually deleted, exactly like a real driver that silently lost
     * a row would look from the caller's point of view — this is what lets
     * Section F prove revoke_membership()'s own "$deleted !== $count"
     * consistency check (and the transaction ROLLBACK it triggers) with a
     * real, physically-inconsistent delete rather than merely asserting a
     * mocked return value.
     */
    public function delete($table, $where, $formats)
    {
        $t = w5_tables();
        if ($table !== $t['access']) {
            $this->last_error = 'w5-fake-wpdb: unexpected delete() call on ' . $table;
            return false;
        }
        $kept = [];
        $deleted = 0;
        foreach ($GLOBALS['w5_db']['access'] as $row) {
            $matches = true;
            foreach ($where as $k => $v) {
                if (!array_key_exists($k, $row) || (int) $row[$k] !== (int) $v) { $matches = false; break; }
            }
            if ($matches) { $deleted++; continue; }
            $kept[] = $row;
        }
        $GLOBALS['w5_db']['access'] = $kept;
        $this->last_error = '';
        $GLOBALS['w5_mutation_calls']++;
        if ($GLOBALS['w5_force_delete_undercount']) {
            $GLOBALS['w5_force_delete_undercount'] = false;
            return max(0, $deleted - 1);
        }
        return $deleted;
    }

    public function insert($table, $data, $formats = null)
    {
        $this->last_error = 'w5-fake-wpdb: unexpected insert() call';
        return false;
    }
}

w5_reset_db();
global $wpdb;
$wpdb = new PGE_W5_Fake_WPDB();

// ──────────────────────────────────────────────────────────────
// AJAX/JSON stubs
// ──────────────────────────────────────────────────────────────

class Test_Wp_Die_Exception extends \Exception {}

$GLOBALS['w5_json_response'] = null;
function wp_send_json_success($data = null)
{
    $GLOBALS['w5_json_response'] = json_encode(['success' => true, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_success');
}
function wp_send_json_error($data = null, $status_code = null)
{
    $GLOBALS['w5_json_response'] = json_encode(['success' => false, 'data' => $data]);
    throw new Test_Wp_Die_Exception('wp_send_json_error');
}
function wp_die($message = '', $title = '', $args = [])
{
    throw new Test_Wp_Die_Exception('wp_die');
}

function w5_call_ajax_handler(callable $handler): array
{
    $GLOBALS['w5_json_response'] = null;
    try {
        $handler();
    } catch (Test_Wp_Die_Exception $e) {
        // expected
    }
    $raw = $GLOBALS['w5_json_response'];
    if ($raw === null) return ['success' => false, 'data' => ['reason' => 'no_response_captured']];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'data' => ['reason' => 'invalid_json']];
}

function w5_nonce($action) { return 'test-nonce-' . sanitize_key($action); }
function wp_create_nonce($action) { return w5_nonce($action); }
function wp_verify_nonce($nonce, $action) { return hash_equals(w5_nonce($action), (string) $nonce) ? 1 : false; }

require_once PGE_PATH . 'includes/event-guests.php';
require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';
require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';
require_once PGE_PATH . 'includes/class-pge-event-access-application-service.php';
require_once PGE_PATH . 'includes/event-access-ajax.php';

$passed = 0;
$failed = 0;
function w5_ok($label, $condition) { global $passed, $failed; if ($condition) { $passed++; echo "PASS: $label\n"; } else { $failed++; echo "FAIL: $label\n"; } }
function w5_code($value) { return $value instanceof WP_Error ? $value->get_error_code() : null; }

function w5_strip_comments($php_source)
{
    $out = '';
    foreach (token_get_all('<?php ' . $php_source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
}

function w5_ajax_post($fields)
{
    $_POST = array_merge(['nonce' => w5_nonce('pge_event_manage_nonce')], $fields);
}

$AS = 'PGE_Event_Access_Application_Service';
$Auth = 'PGE_Event_Access_Authorization';
$Repo = 'PGE_Event_Access_Repository';

// ──────────────────────────────────────────────────────────────
// Matrix fixture — event 10, owner=1, admin=2, an active manager
// membership (user 3) and viewer membership (user 5) usable as REVOKE/
// CHANGE-ROLE/REACTIVATE targets, a revoked manager (user 6) and revoked
// viewer (user 7) usable as REACTIVATE targets, a stranger (user 99, never
// registered as a WP user, never given a membership row — this is
// deliberate: Section Q/R depend on a stranger who does not exist as a WP
// user either, so "denied before any existence-sensitive lookup" can be
// proven honestly), a group (g1) for cascade/grant integration tests, and
// user 50 registered as a real WP user with NO membership row, usable as a
// fresh CREATE target.
// ──────────────────────────────────────────────────────────────

function w5_matrix_fixture()
{
    w5_reset_db();
    w5_event(10, 1); // owner = user 1
    w5_user(1);
    $GLOBALS['w5_admins'] = [2];
    w5_user(2);
    $g1 = w5_group(10, 'active', 'Group One');
    $mgr = w5_membership(10, 3, 'manager');       // active manager — CHANGE_ROLE/REVOKE target
    $viewer = w5_membership(10, 5, 'viewer');     // active viewer
    $revoked_mgr = w5_membership(10, 6, 'manager', 'revoked');   // REACTIVATE target
    $revoked_viewer = w5_membership(10, 7, 'viewer', 'revoked'); // REACTIVATE target
    w5_access($g1 !== null ? 10 : 10, $mgr, $g1); // manager has a group-access grant — REVOKE cascade fixture
    w5_user(50); // a fresh WP user with no membership row — CREATE target
    return [
        'g1' => $g1, 'owner' => 1, 'admin' => 2,
        'manager' => 3, 'manager_membership' => $mgr,
        'viewer' => 5, 'viewer_membership' => $viewer,
        'revoked_manager' => 6, 'revoked_manager_membership' => $revoked_mgr,
        'revoked_viewer' => 7, 'revoked_viewer_membership' => $revoked_viewer,
        'stranger' => 99, 'new_user' => 50,
    ];
}

// ──────────────────────────────────────────────────────────────
// Section C — Authorization matrix, all four operations, across Owner /
// Admin / Manager / Viewer / Revoked Manager / Revoked Viewer / Stranger.
// can_manage_membership() is a flat Owner/Admin-only gate with NO
// group-scoping parameter (proven directly from A2 Section D + its own
// docblock — see the H1C-W5 report), so a Manager's own granted-group scope
// has zero bearing on the outcome. Every denial is proven to issue ZERO
// Repository mutation queries.
// ──────────────────────────────────────────────────────────────

$w5_ops = [
    'create' => function ($actor_id, $event_id, $f) use ($AS) {
        return call_user_func([$AS, 'create_membership_for_actor'], $event_id, $actor_id, $f['new_user'], 'viewer');
    },
    'change_role' => function ($actor_id, $event_id, $f) use ($AS) {
        return call_user_func([$AS, 'change_membership_role_for_actor'], $event_id, $actor_id, $f['manager_membership'], 'manager', 'viewer');
    },
    'revoke' => function ($actor_id, $event_id, $f) use ($AS) {
        return call_user_func([$AS, 'revoke_membership_for_actor'], $event_id, $actor_id, $f['manager_membership']);
    },
    'reactivate' => function ($actor_id, $event_id, $f) use ($AS) {
        return call_user_func([$AS, 'reactivate_membership_for_actor'], $event_id, $actor_id, $f['revoked_manager_membership'], 'manager');
    },
];

foreach ($w5_ops as $op => $call) {
    foreach ([
        ['owner', true], ['admin', true], ['manager', false], ['viewer', false],
        ['revoked_manager', false], ['revoked_viewer', false], ['stranger', false],
    ] as [$who, $expect_allowed]) {
        $f = w5_matrix_fixture();
        $before_mutations = $GLOBALS['w5_mutation_calls'];
        $result = $call($f[$who], 10, $f);
        $allowed = !($result instanceof WP_Error);
        w5_ok("C-$op $who allowed=" . ($expect_allowed ? 'true' : 'false'), $allowed === $expect_allowed
            && ($expect_allowed || w5_code($result) === 'not_authorized')
            && ($expect_allowed || $GLOBALS['w5_mutation_calls'] === $before_mutations));
    }
}

// ──────────────────────────────────────────────────────────────
// Section D — CREATE behavior
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'create_membership_for_actor'], 10, $f['owner'], $f['new_user'], 'viewer');
w5_ok('D1 owner create success shape', is_array($result) && $result['ok'] === true && $result['changed'] === true
    && $result['membership_id'] > 0 && $result['user_id'] === $f['new_user'] && $result['role'] === 'viewer' && $result['status'] === 'active');
w5_ok('D1b create emits exactly one audit row (membership_created) with role metadata', count($GLOBALS['w5_db']['audit']) === 1
    && $GLOBALS['w5_db']['audit'][0]['action'] === 'membership_created' && $GLOBALS['w5_db']['audit'][0]['entity_type'] === 'membership'
    && $GLOBALS['w5_db']['audit'][0]['metadata']['role'] === 'viewer');

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'create_membership_for_actor'], 10, $f['owner'], $f['manager'], 'viewer'); // user 3 already has an ACTIVE membership
w5_ok('D2 duplicate against an ACTIVE existing membership is public reason duplicate', w5_code($result) === 'duplicate');

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'create_membership_for_actor'], 10, $f['owner'], $f['revoked_manager'], 'viewer'); // user 6 has a REVOKED membership
w5_ok('D2b duplicate against a REVOKED existing membership is ALSO duplicate (find_locked_membership_by_user matches regardless of status)', w5_code($result) === 'duplicate');

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'create_membership_for_actor'], 10, $f['owner'], 999999, 'viewer'); // no WP user 999999
w5_ok('D3 create for a nonexistent WP user is not_found', w5_code($result) === 'not_found');

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'create_membership_for_actor'], 10, $f['owner'], $f['new_user'], 'owner'); // 'owner' is never a valid membership role
w5_ok('D4 create with an invalid role string (Repository-level) is invalid_input', w5_code($result) === 'invalid_input');

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'create_membership_for_actor'], 10, $f['owner'], $f['new_user'], ['array']);
w5_ok('D5 create with a non-string role (Application-level strict type gate) is invalid_input', w5_code($result) === 'invalid_input');

$f = w5_matrix_fixture();
$GLOBALS['w5_schema_ready'] = false;
$result = call_user_func([$AS, 'create_membership_for_actor'], 10, $f['owner'], $f['new_user'], 'viewer');
w5_ok('D6 schema-not-ready create maps to write_unavailable', w5_code($result) === 'write_unavailable');
$GLOBALS['w5_schema_ready'] = true;

$f = w5_matrix_fixture();
$GLOBALS['w5_force_insert_fail'] = true;
$before_audit = count($GLOBALS['w5_db']['audit']);
$result = call_user_func([$AS, 'create_membership_for_actor'], 10, $f['owner'], $f['new_user'], 'viewer');
w5_ok('D7 forced INSERT failure is database_error', w5_code($result) === 'database_error');
w5_ok('D7b failed create emits no false audit row', count($GLOBALS['w5_db']['audit']) === $before_audit);
$GLOBALS['w5_force_insert_fail'] = false;

foreach ([
    [-1, $f['owner'], $f['new_user'], 'viewer', 'negative event_id'],
    [10, -1, $f['new_user'], 'viewer', 'negative actor_user_id'],
    [10, $f['owner'], 0, 'viewer', 'zero user_id'],
] as [$eid, $actor, $uid, $role, $label]) {
    $f = w5_matrix_fixture();
    $r = call_user_func([$AS, 'create_membership_for_actor'], $eid, $actor, $uid, $role);
    w5_ok("D8 create strict-rejects $label as invalid_input", w5_code($r) === 'invalid_input');
}

// ──────────────────────────────────────────────────────────────
// Section E — CHANGE ROLE behavior
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'change_membership_role_for_actor'], 10, $f['owner'], $f['manager_membership'], 'manager', 'viewer');
w5_ok('E1 owner change-role success shape', is_array($result) && $result['changed'] === true && $result['role'] === 'viewer' && $result['status'] === 'active');
w5_ok('E1b change-role emits exactly one audit row (membership_role_changed) with previous/new role metadata', count($GLOBALS['w5_db']['audit']) === 1
    && $GLOBALS['w5_db']['audit'][0]['action'] === 'membership_role_changed'
    && $GLOBALS['w5_db']['audit'][0]['metadata']['previous_role'] === 'manager' && $GLOBALS['w5_db']['audit'][0]['metadata']['new_role'] === 'viewer');

$f = w5_matrix_fixture();
$before_audit = count($GLOBALS['w5_db']['audit']);
$result = call_user_func([$AS, 'change_membership_role_for_actor'], 10, $f['owner'], $f['manager_membership'], 'manager', 'manager'); // same role
w5_ok('E2 change-role to the exact same expected/new role is a no-op (changed=false), no audit', $result['changed'] === false && count($GLOBALS['w5_db']['audit']) === $before_audit);

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'change_membership_role_for_actor'], 10, $f['owner'], $f['manager_membership'], 'viewer', 'manager'); // wrong expected_role
w5_ok('E3 change-role with a mismatched expected_role is concurrent_update', w5_code($result) === 'concurrent_update');
w5_ok('E3b mismatched expected_role never mutated the membership row', $GLOBALS['w5_db']['memberships'][$f['manager_membership']]['role'] === 'manager');

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'change_membership_role_for_actor'], 10, $f['owner'], $f['revoked_manager_membership'], 'manager', 'viewer');
w5_ok('E4 change-role on a REVOKED membership is invalid_state', w5_code($result) === 'invalid_state');

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'change_membership_role_for_actor'], 10, $f['owner'], 999999, 'manager', 'viewer');
w5_ok('E5 change-role of a missing membership_id is not_found', w5_code($result) === 'not_found');

w5_reset_db();
w5_event(10, 1); w5_event(11, 1); w5_user(1);
$foreign_membership = w5_membership(11, 40, 'manager');
$result = call_user_func([$AS, 'change_membership_role_for_actor'], 10, 1, $foreign_membership, 'manager', 'viewer');
w5_ok('E6 change-role with a cross-event membership_id (real row, wrong event) is not_found', w5_code($result) === 'not_found');

$f = w5_matrix_fixture();
unset($GLOBALS['w5_users'][$f['manager']]); // target user deleted after the membership was created
$result = call_user_func([$AS, 'change_membership_role_for_actor'], 10, $f['owner'], $f['manager_membership'], 'manager', 'viewer');
w5_ok('E7 change-role requires the target user to still exist — not_found if deleted (unlike REVOKE)', w5_code($result) === 'not_found');

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'change_membership_role_for_actor'], 10, $f['owner'], $f['manager_membership'], 'manager', 'owner'); // invalid new_role
w5_ok('E8 change-role with an invalid new_role string (Repository-level, post-authority) is invalid_input', w5_code($result) === 'invalid_input');

$f = w5_matrix_fixture();
$GLOBALS['w5_force_update_miss'] = true;
$before_audit = count($GLOBALS['w5_db']['audit']);
$result = call_user_func([$AS, 'change_membership_role_for_actor'], 10, $f['owner'], $f['manager_membership'], 'manager', 'viewer');
w5_ok('E9 a lost race on the role UPDATE itself (forced) is concurrent_update', w5_code($result) === 'concurrent_update');
w5_ok('E9b raced change-role emits no false audit row, and did not mutate the role', count($GLOBALS['w5_db']['audit']) === $before_audit
    && $GLOBALS['w5_db']['memberships'][$f['manager_membership']]['role'] === 'manager');

// Change-role never touches group-access grants (H1B-W2's own proven
// contract) — the manager's access row from the fixture must survive.
$f = w5_matrix_fixture();
$access_before = w5_access_count($f['manager_membership']);
call_user_func([$AS, 'change_membership_role_for_actor'], 10, $f['owner'], $f['manager_membership'], 'manager', 'viewer');
w5_ok('E10 change-role does not touch group-access grants', w5_access_count($f['manager_membership']) === $access_before && $access_before >= 1);

// ──────────────────────────────────────────────────────────────
// Section F — REVOKE behavior. The most important operation of the four:
// this is the ONLY membership-lifecycle write that cascades into a second
// table (group-access), atomically, in the same transaction. Every
// assertion below checks BEFORE/AFTER row-level state, not merely a
// mutation counter, per the brief's explicit Section 26 instruction.
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
$access_before = w5_access_count($f['manager_membership']);
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['owner'], $f['manager_membership']);
w5_ok('F1 owner revoke of an active membership WITH access grants succeeds', is_array($result) && $result['changed'] === true && $result['status'] === 'revoked');
w5_ok('F1b the membership row itself is now revoked with a real revoked_at', $GLOBALS['w5_db']['memberships'][$f['manager_membership']]['status'] === 'revoked'
    && $GLOBALS['w5_db']['memberships'][$f['manager_membership']]['revoked_at'] !== null);
w5_ok('F1c ALL of this membership\'s group-access rows were cascade-deleted, atomically, in the same call (before had >=1, after has 0)', $access_before >= 1 && w5_access_count($f['manager_membership']) === 0);
w5_ok('F1d exactly one audit row (membership_revoked) records status_changed=true and the real revoked count', count($GLOBALS['w5_db']['audit']) === 1
    && $GLOBALS['w5_db']['audit'][0]['action'] === 'membership_revoked'
    && $GLOBALS['w5_db']['audit'][0]['metadata']['status_changed'] === true
    && $GLOBALS['w5_db']['audit'][0]['metadata']['revoked_group_access_count'] === $access_before);

$f = w5_matrix_fixture();
$before_audit = count($GLOBALS['w5_db']['audit']);
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['owner'], $f['revoked_manager_membership']); // already revoked, zero access
w5_ok('F2 revoking an already-revoked membership with ZERO access rows is a true no-op (changed=false), NOT an error', is_array($result) && $result['changed'] === false);
w5_ok('F2b true no-op emits no audit row', count($GLOBALS['w5_db']['audit']) === $before_audit);

$f = w5_matrix_fixture();
// Data-corruption fixture: a REVOKED membership that still carries a stale
// access row (H1B-W2's own "revoke revoked cleans stale access" case).
w5_access(10, $f['revoked_manager_membership'], $f['g1']);
$before_audit = count($GLOBALS['w5_db']['audit']);
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['owner'], $f['revoked_manager_membership']);
w5_ok('F3 revoking an already-revoked membership WITH stale access rows still succeeds (changed=true) and cleans the stale access', is_array($result) && $result['changed'] === true
    && w5_access_count($f['revoked_manager_membership']) === 0);
w5_ok('F3b the stale-access-cleanup audit records status_changed=false (the membership itself was already revoked)', end($GLOBALS['w5_db']['audit'])['metadata']['status_changed'] === false);

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['owner'], 999999);
w5_ok('F4 revoke of a missing membership_id is not_found', w5_code($result) === 'not_found');

w5_reset_db();
w5_event(10, 1); w5_event(11, 1); w5_user(1);
$foreign_membership2 = w5_membership(11, 41, 'manager');
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, 1, $foreign_membership2);
w5_ok('F5 revoke with a cross-event membership_id (real row, wrong event) is not_found', w5_code($result) === 'not_found');

$f = w5_matrix_fixture();
unset($GLOBALS['w5_users'][$f['manager']]); // target WP user deleted
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['owner'], $f['manager_membership']);
w5_ok('F6 revoke TOLERATES a deleted target WP user — unique among the four operations (H1B-W2\'s own proven contract)', is_array($result) && $result['changed'] === true);

$f = w5_matrix_fixture();
$before_membership_status = $GLOBALS['w5_db']['memberships'][$f['manager_membership']];
$before_access = $GLOBALS['w5_db']['access'];
$GLOBALS['w5_force_update_miss'] = true;
$before_audit = count($GLOBALS['w5_db']['audit']);
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['owner'], $f['manager_membership']);
w5_ok('F7 a lost race on the status UPDATE itself (forced) is concurrent_update', w5_code($result) === 'concurrent_update');
w5_ok('F7b the whole transaction rolled back — membership row is BYTE-IDENTICAL to its pre-call state (still active, access grants intact)',
    $GLOBALS['w5_db']['memberships'][$f['manager_membership']] === $before_membership_status && $GLOBALS['w5_db']['access'] === $before_access);
w5_ok('F7c raced revoke emits no false audit row', count($GLOBALS['w5_db']['audit']) === $before_audit);

$f = w5_matrix_fixture();
$before_membership_status2 = $GLOBALS['w5_db']['memberships'][$f['manager_membership']];
$before_access2 = $GLOBALS['w5_db']['access'];
$GLOBALS['w5_force_delete_undercount'] = true;
$before_audit2 = count($GLOBALS['w5_db']['audit']);
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['owner'], $f['manager_membership']);
w5_ok('F8 (Section 26 — cascade atomicity) a mid-cascade access-delete count mismatch (forced) is concurrent_update, never a silent partial cascade', w5_code($result) === 'concurrent_update');
w5_ok('F8b the ENTIRE transaction rolled back on the forced cascade mismatch — membership row is BYTE-IDENTICAL to its pre-call state (still active)',
    $GLOBALS['w5_db']['memberships'][$f['manager_membership']] === $before_membership_status2);
w5_ok('F8c the ENTIRE transaction rolled back on the forced cascade mismatch — access rows are BYTE-IDENTICAL to their pre-call state (the physically-deleted row was restored by ROLLBACK)',
    $GLOBALS['w5_db']['access'] === $before_access2);
w5_ok('F8d raced cascade emits no false audit row', count($GLOBALS['w5_db']['audit']) === $before_audit2);

$f = w5_matrix_fixture();
$before_membership_status3 = $GLOBALS['w5_db']['memberships'][$f['manager_membership']];
$before_access3 = $GLOBALS['w5_db']['access'];
$GLOBALS['w5_force_audit_fail'] = true;
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['owner'], $f['manager_membership']);
w5_ok('F9 (Section 26 — rollback on audit failure) a forced audit-insert failure during revoke is database_error', w5_code($result) === 'database_error');
w5_ok('F9b the ENTIRE transaction rolled back on the forced audit failure — BOTH the membership row and the access rows are BYTE-IDENTICAL to their pre-call state',
    $GLOBALS['w5_db']['memberships'][$f['manager_membership']] === $before_membership_status3 && $GLOBALS['w5_db']['access'] === $before_access3);

// Denial never mutates or audits, even against a real, existing membership.
$f = w5_matrix_fixture();
$before_audit = count($GLOBALS['w5_db']['audit']);
$before_mutations = $GLOBALS['w5_mutation_calls'];
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['stranger'], $f['manager_membership']);
w5_ok('F10 authorization denial (revoke) never mutates and never audits, even though a real membership with real access grants exists', w5_code($result) === 'not_authorized'
    && count($GLOBALS['w5_db']['audit']) === $before_audit && $GLOBALS['w5_mutation_calls'] === $before_mutations
    && w5_access_count($f['manager_membership']) >= 1);

// ──────────────────────────────────────────────────────────────
// Section G — REACTIVATE behavior
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'reactivate_membership_for_actor'], 10, $f['owner'], $f['revoked_manager_membership'], 'manager'); // same role as before revoke
w5_ok('G1 owner reactivate with the SAME role as before still succeeds (changed=true — reactivation is a real status transition, not a same-role no-op)', is_array($result) && $result['changed'] === true && $result['role'] === 'manager' && $result['status'] === 'active');
w5_ok('G1b reactivate emits exactly one audit row (membership_reactivated) with previous/new role metadata', count($GLOBALS['w5_db']['audit']) === 1
    && $GLOBALS['w5_db']['audit'][0]['action'] === 'membership_reactivated'
    && $GLOBALS['w5_db']['audit'][0]['metadata']['previous_role'] === 'manager' && $GLOBALS['w5_db']['audit'][0]['metadata']['new_role'] === 'manager');

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'reactivate_membership_for_actor'], 10, $f['owner'], $f['revoked_viewer_membership'], 'manager'); // DIFFERENT role than before revoke
w5_ok('G2 reactivate with a DIFFERENT role than before revoke succeeds — role is caller-supplied, not restored (role flexibility per H1B-W2)', is_array($result) && $result['changed'] === true && $result['role'] === 'manager');

$f = w5_matrix_fixture();
$before_audit = count($GLOBALS['w5_db']['audit']);
$result = call_user_func([$AS, 'reactivate_membership_for_actor'], 10, $f['owner'], $f['manager_membership'], 'manager'); // already active, same role
w5_ok('G3 reactivate of an ALREADY-ACTIVE membership with the SAME role is a no-op (changed=false), no audit', $result['changed'] === false && count($GLOBALS['w5_db']['audit']) === $before_audit);

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'reactivate_membership_for_actor'], 10, $f['owner'], $f['manager_membership'], 'viewer'); // already active, DIFFERENT role
w5_ok('G4 reactivate of an ALREADY-ACTIVE membership with a DIFFERENT role is invalid_state (must use CHANGE ROLE, not REACTIVATE)', w5_code($result) === 'invalid_state');

$f = w5_matrix_fixture();
// Data-corruption fixture: a revoked membership that still carries latent access.
w5_access(10, $f['revoked_manager_membership'], $f['g1']);
$result = call_user_func([$AS, 'reactivate_membership_for_actor'], 10, $f['owner'], $f['revoked_manager_membership'], 'manager');
w5_ok('G5 reactivate REFUSES a revoked membership with latent access rows (data-integrity refusal — database_error, not silently reactivated)', w5_code($result) === 'database_error');

$f = w5_matrix_fixture();
$result = call_user_func([$AS, 'reactivate_membership_for_actor'], 10, $f['owner'], 999999, 'manager');
w5_ok('G6 reactivate of a missing membership_id is not_found', w5_code($result) === 'not_found');

w5_reset_db();
w5_event(10, 1); w5_event(11, 1); w5_user(1);
$foreign_membership3 = w5_membership(11, 42, 'manager', 'revoked');
$result = call_user_func([$AS, 'reactivate_membership_for_actor'], 10, 1, $foreign_membership3, 'manager');
w5_ok('G7 reactivate with a cross-event membership_id (real row, wrong event) is not_found', w5_code($result) === 'not_found');

$f = w5_matrix_fixture();
unset($GLOBALS['w5_users'][$f['revoked_manager']]); // target user deleted
$result = call_user_func([$AS, 'reactivate_membership_for_actor'], 10, $f['owner'], $f['revoked_manager_membership'], 'manager');
w5_ok('G8 reactivate requires the target user to still exist — not_found if deleted (unlike REVOKE)', w5_code($result) === 'not_found');

$f = w5_matrix_fixture();
$GLOBALS['w5_force_update_miss'] = true;
$before_audit = count($GLOBALS['w5_db']['audit']);
$result = call_user_func([$AS, 'reactivate_membership_for_actor'], 10, $f['owner'], $f['revoked_manager_membership'], 'manager');
w5_ok('G9 a lost race on the reactivate UPDATE itself (forced) is concurrent_update', w5_code($result) === 'concurrent_update');
w5_ok('G9b raced reactivate emits no false audit row, and did not mutate the membership row', count($GLOBALS['w5_db']['audit']) === $before_audit
    && $GLOBALS['w5_db']['memberships'][$f['revoked_manager_membership']]['status'] === 'revoked');

// G10 — Product-contract integration proof (Section 11/29): reactivation
// does NOT restore prior grants. Revoke a membership WITH access, confirm
// access is gone, reactivate it, confirm access is STILL gone, then prove
// a SEPARATE grant_group_access_for_actor() call (W3's own public method)
// is required and succeeds — matching H1B-W2's own
// "reactivate then grant succeeds" integration proof, at the Application
// Service layer this time.
$f = w5_matrix_fixture();
call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['owner'], $f['manager_membership']);
w5_ok('G10a after revoke, the membership has zero access rows', w5_access_count($f['manager_membership']) === 0);
$reactivate_result = call_user_func([$AS, 'reactivate_membership_for_actor'], 10, $f['owner'], $f['manager_membership'], 'manager');
w5_ok('G10b reactivate succeeds (membership was revoked with zero latent access, per G10a)', is_array($reactivate_result) && $reactivate_result['changed'] === true);
w5_ok('G10c reactivate did NOT restore the prior group-access grant on its own — access is still zero', w5_access_count($f['manager_membership']) === 0);
$grant_result = call_user_func(['PGE_Event_Access_Application_Service', 'grant_group_access_for_actor'], 10, $f['owner'], $f['manager_membership'], $f['g1']);
w5_ok('G10d a SEPARATE grant_group_access_for_actor() call after reactivate succeeds and is the only way access comes back', is_array($grant_result) && $grant_result['changed'] === true && w5_access_count($f['manager_membership']) === 1);

// ──────────────────────────────────────────────────────────────
// Section H — Application-layer strict input validation (ids), for the
// three id-bearing operations beyond CREATE (Section D8 already covers
// CREATE's own ids).
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
foreach ([
    ['change_membership_role_for_actor', [10, -1, $f['manager_membership'], 'manager', 'viewer'], 'negative actor_user_id'],
    ['change_membership_role_for_actor', [10, $f['owner'], 0, 'manager', 'viewer'], 'zero membership_id'],
    ['revoke_membership_for_actor', [10, $f['owner'], -5], 'negative membership_id'],
    ['reactivate_membership_for_actor', [10, $f['owner'], 0, 'manager'], 'zero membership_id'],
    ['reactivate_membership_for_actor', [10, $f['owner'], $f['revoked_manager_membership'], ['x']], 'array role'],
] as [$method, $args, $label]) {
    $r = call_user_func_array([$AS, $method], $args);
    w5_ok("H1 $method strict-rejects $label as invalid_input", w5_code($r) === 'invalid_input');
}

// ──────────────────────────────────────────────────────────────
// Section I — AJAX: login/nonce/validation/end-to-end success/no-nopriv.
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
$GLOBALS['w5_logged_in'] = false;
w5_ajax_post(['event_id' => '10', 'target_user_id' => (string) $f['new_user'], 'role' => 'viewer']);
$resp = w5_call_ajax_handler('pge_event_access_create_membership_handler');
w5_ok('I1 create AJAX not logged in is denied before any application call', $resp['success'] === false && $resp['data']['reason'] === 'not_logged_in');
$GLOBALS['w5_logged_in'] = true;

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['owner'];
$_POST = ['nonce' => 'garbage', 'event_id' => '10', 'target_user_id' => (string) $f['new_user'], 'role' => 'viewer'];
$resp = w5_call_ajax_handler('pge_event_access_create_membership_handler');
w5_ok('I2 create AJAX bad nonce is denied', $resp['success'] === false && $resp['data']['reason'] === 'invalid_nonce');

foreach ([
    ['event_id' => '', 'target_user_id' => '50', 'role' => 'viewer'],
    ['event_id' => '10', 'role' => 'viewer'], // missing target_user_id
    ['event_id' => '10', 'target_user_id' => '50'], // missing role
    ['event_id' => '10', 'target_user_id' => '50', 'role' => ['a']],
] as $bad) {
    $f = w5_matrix_fixture();
    $GLOBALS['w5_current_user_id'] = $f['owner'];
    w5_ajax_post($bad);
    $resp = w5_call_ajax_handler('pge_event_access_create_membership_handler');
    w5_ok('I3 create AJAX strict-rejects malformed field ' . json_encode($bad), $resp['success'] === false && $resp['data']['reason'] === 'invalid_input');
}

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['owner'];
w5_ajax_post(['event_id' => '10', 'target_user_id' => (string) $f['new_user'], 'role' => 'viewer']);
$resp = w5_call_ajax_handler('pge_event_access_create_membership_handler');
w5_ok('I4 create AJAX end-to-end success shape', $resp['success'] === true && $resp['data']['changed'] === true && $resp['data']['role'] === 'viewer' && $resp['data']['status'] === 'active');

foreach ([
    ['event_id' => '10', 'membership_id' => '1'], // missing expected_role/new_role
    ['event_id' => '10', 'expected_role' => 'manager', 'new_role' => 'viewer'], // missing membership_id
] as $bad) {
    $f = w5_matrix_fixture();
    $GLOBALS['w5_current_user_id'] = $f['owner'];
    w5_ajax_post($bad);
    $resp = w5_call_ajax_handler('pge_event_access_change_membership_role_handler');
    w5_ok('I5 change-role AJAX strict-rejects malformed field ' . json_encode($bad), $resp['success'] === false && $resp['data']['reason'] === 'invalid_input');
}

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['owner'];
w5_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['manager_membership'], 'expected_role' => 'manager', 'new_role' => 'viewer']);
$resp = w5_call_ajax_handler('pge_event_access_change_membership_role_handler');
w5_ok('I6 change-role AJAX end-to-end success shape', $resp['success'] === true && $resp['data']['role'] === 'viewer');

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['owner'];
w5_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['manager_membership']]);
$resp = w5_call_ajax_handler('pge_event_access_revoke_membership_handler');
w5_ok('I7 revoke AJAX end-to-end success shape', $resp['success'] === true && $resp['data']['status'] === 'revoked');

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['owner'];
w5_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['revoked_manager_membership'], 'role' => 'manager']);
$resp = w5_call_ajax_handler('pge_event_access_reactivate_membership_handler');
w5_ok('I8 reactivate AJAX end-to-end success shape', $resp['success'] === true && $resp['data']['status'] === 'active');

foreach ([
    'pge_event_access_revoke_membership_handler' => ['event_id' => '', 'membership_id' => '1'],
    'pge_event_access_reactivate_membership_handler' => ['event_id' => '10', 'membership_id' => '0', 'role' => 'manager'],
] as $handler => $bad) {
    $f = w5_matrix_fixture();
    $GLOBALS['w5_current_user_id'] = $f['owner'];
    w5_ajax_post($bad);
    $resp = w5_call_ajax_handler($handler);
    w5_ok("I9 $handler strict-rejects malformed field " . json_encode($bad), $resp['success'] === false && $resp['data']['reason'] === 'invalid_input');
}

$ajax_write_source = file_get_contents(PGE_PATH . 'includes/event-access-ajax.php');
$ajax_write_code = w5_strip_comments($ajax_write_source);
w5_ok('I10 no wp_ajax_nopriv_ registered for any of the four membership actions (source)', preg_match('/wp_ajax_nopriv_pge_event_access_(create|change_membership_role|revoke|reactivate)_membership/', $ajax_write_source) === 0);
w5_ok('I11 wp_ajax_ hooks ARE registered for all four actions (real add_action() calls captured at require-time)',
    isset($GLOBALS['w5_registered_hooks']['wp_ajax_pge_event_access_create_membership'])
    && isset($GLOBALS['w5_registered_hooks']['wp_ajax_pge_event_access_change_membership_role'])
    && isset($GLOBALS['w5_registered_hooks']['wp_ajax_pge_event_access_revoke_membership'])
    && isset($GLOBALS['w5_registered_hooks']['wp_ajax_pge_event_access_reactivate_membership']));
w5_ok('I12 no wp_ajax_nopriv_ hook was ever actually registered (runtime proof, not just source grep)',
    !isset($GLOBALS['w5_registered_hooks']['wp_ajax_nopriv_pge_event_access_create_membership'])
    && !isset($GLOBALS['w5_registered_hooks']['wp_ajax_nopriv_pge_event_access_change_membership_role'])
    && !isset($GLOBALS['w5_registered_hooks']['wp_ajax_nopriv_pge_event_access_revoke_membership'])
    && !isset($GLOBALS['w5_registered_hooks']['wp_ajax_nopriv_pge_event_access_reactivate_membership']));

// ──────────────────────────────────────────────────────────────
// Section J — Actor spoof (Section 20 of the brief): a client-sent
// actor_user_id/user_id-as-actor/manager_id/owner_id field must be
// completely ignored; the real session (a denied Manager) governs.
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['manager'];
$before_mutations = $GLOBALS['w5_mutation_calls'];
w5_ajax_post([
    'event_id' => '10', 'membership_id' => (string) $f['viewer_membership'],
    'actor_user_id' => (string) $f['admin'], 'manager_id' => (string) $f['admin'], 'owner_id' => (string) $f['admin'],
]);
$resp = w5_call_ajax_handler('pge_event_access_revoke_membership_handler');
w5_ok('J1 (actor spoof) spoofed actor_user_id/manager_id/owner_id fields are ignored — real Manager session denied', $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');
w5_ok('J2 (actor spoof) Repository mutation was never invoked for the spoofed/denied request', $GLOBALS['w5_mutation_calls'] === $before_mutations);

w5_ok('J3 AJAX source never reads $_POST[\'actor_user_id\']/[\'manager_id\']/[\'owner_id\'] in a way that assigns the acting identity (comments stripped)', preg_match('/\$_POST\[\'(actor_user_id|manager_id|owner_id)\'\]/', $ajax_write_code) === 0);

// A spoofed 'target_user_id' field on CREATE is legitimate (it is the
// CREATE TARGET, not the actor) — the actor still comes only from the
// session. Prove a denied Manager spoofing target_user_id=owner still
// cannot create a membership FOR the owner via this endpoint.
$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['manager'];
$before_mutations = $GLOBALS['w5_mutation_calls'];
w5_ajax_post(['event_id' => '10', 'target_user_id' => (string) $f['owner'], 'role' => 'viewer', 'actor_user_id' => (string) $f['owner']]);
$resp = w5_call_ajax_handler('pge_event_access_create_membership_handler');
w5_ok('J4 a denied Manager cannot use CREATE\'s legitimate target_user_id field to smuggle actor authority — still not_authorized', $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');
w5_ok('J5 the spoofed CREATE request triggered zero Repository mutations', $GLOBALS['w5_mutation_calls'] === $before_mutations);

// ──────────────────────────────────────────────────────────────
// Section K — Error mapping via the real AJAX handler + real public error
// mapper (pge_event_access_public_membership_write_error()).
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['owner'];
w5_ajax_post(['event_id' => '10', 'target_user_id' => (string) $f['manager'], 'role' => 'viewer']); // duplicate
$resp = w5_call_ajax_handler('pge_event_access_create_membership_handler');
w5_ok('K1 duplicate maps to public reason duplicate, no internal detail leaked', $resp['success'] === false && $resp['data']['reason'] === 'duplicate' && strpos(json_encode($resp), 'wp_pge_') === false);

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['owner'];
w5_ajax_post(['event_id' => '10', 'membership_id' => '999999']);
$resp = w5_call_ajax_handler('pge_event_access_revoke_membership_handler');
w5_ok('K2 not_found maps to public reason not_found', $resp['success'] === false && $resp['data']['reason'] === 'not_found');

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['owner'];
w5_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['revoked_manager_membership'], 'expected_role' => 'manager', 'new_role' => 'viewer']);
$resp = w5_call_ajax_handler('pge_event_access_change_membership_role_handler');
w5_ok('K3 invalid_state maps to public reason invalid_state', $resp['success'] === false && $resp['data']['reason'] === 'invalid_state');

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['owner'];
w5_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['manager_membership'], 'expected_role' => 'viewer', 'new_role' => 'manager']);
$resp = w5_call_ajax_handler('pge_event_access_change_membership_role_handler');
w5_ok('K4 concurrent_update maps to public reason concurrent_update', $resp['success'] === false && $resp['data']['reason'] === 'concurrent_update');

$f = w5_matrix_fixture();
$GLOBALS['w5_schema_ready'] = false;
$GLOBALS['w5_current_user_id'] = $f['owner'];
w5_ajax_post(['event_id' => '10', 'target_user_id' => (string) $f['new_user'], 'role' => 'viewer']);
$resp = w5_call_ajax_handler('pge_event_access_create_membership_handler');
w5_ok('K5 write_unavailable maps to public reason write_unavailable', $resp['success'] === false && $resp['data']['reason'] === 'write_unavailable');
$GLOBALS['w5_schema_ready'] = true;

$f = w5_matrix_fixture();
$GLOBALS['w5_force_insert_fail'] = true;
$GLOBALS['w5_current_user_id'] = $f['owner'];
w5_ajax_post(['event_id' => '10', 'target_user_id' => (string) $f['new_user'], 'role' => 'viewer']);
$resp = w5_call_ajax_handler('pge_event_access_create_membership_handler');
w5_ok('K6 database_error (forced insert failure) maps to generic public reason server_error, no SQL/table leak', $resp['success'] === false && $resp['data']['reason'] === 'server_error' && strpos(json_encode($resp), 'wp_pge_') === false && strpos(json_encode($resp), 'INSERT') === false);
$GLOBALS['w5_force_insert_fail'] = false;

$f = w5_matrix_fixture();
$GLOBALS['w5_current_user_id'] = $f['stranger'];
w5_ajax_post(['event_id' => '10', 'membership_id' => (string) $f['manager_membership']]);
$resp = w5_call_ajax_handler('pge_event_access_revoke_membership_handler');
w5_ok('K7 not_authorized maps to public reason not_authorized', $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');

// ──────────────────────────────────────────────────────────────
// Section L — Source-level guards (Section 32 of the brief).
// ──────────────────────────────────────────────────────────────

$as_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$as_code = w5_strip_comments($as_source);

w5_ok('L1 Application Service source never reads $_POST anywhere (comments stripped)', strpos($as_code, '$_POST') === false);
w5_ok('L2 Application Service source never calls current_user_can()/get_current_user_id() anywhere (comments stripped)', preg_match('/current_user_can\s*\(|get_current_user_id\s*\(/', $as_code) === 0);
w5_ok('L3 AJAX handlers never call Repository directly for membership lifecycle (comments stripped, scoped to the W5 handler functions)',
    (function () use ($ajax_write_code) {
        $start = strpos($ajax_write_code, 'function pge_event_access_create_membership_handler');
        $section = substr($ajax_write_code, $start);
        return strpos($section, 'PGE_Event_Access_Repository::') === false;
    })());
w5_ok('L4 AJAX source never references the Authorization class by name (comments stripped)', strpos($ajax_write_code, 'PGE_Event_Access_Authorization') === false);

foreach (['create_membership_for_actor', 'change_membership_role_for_actor', 'revoke_membership_for_actor', 'reactivate_membership_for_actor'] as $method) {
    $pos = strpos($as_code, "function $method");
    $next_pos = strpos($as_code, 'function ', $pos + 10);
    $body = $next_pos !== false ? substr($as_code, $pos, $next_pos - $pos) : substr($as_code, $pos);
    w5_ok("L5 $method uses the shared resolve_event_actor_context() gate (no independent PGE_Event_Access_Authorization::resolve_context() call of its own)",
        strpos($body, 'resolve_event_actor_context') !== false
        && strpos($body, 'PGE_Event_Access_Authorization::resolve_context') === false);
    w5_ok("L6 $method makes its own can_manage_membership() capability decision (the W3-style inline pattern, not a dedicated W4-style wrapper)",
        strpos($body, 'PGE_Event_Access_Authorization::can_manage_membership') !== false);
    // Section 9: cascade logic must stay owned by Repository — the
    // Application Service method bodies must never contain their own
    // DELETE-shaped access-table mutation logic; each calls the real
    // Repository method exactly once and maps its result.
    w5_ok("L7 $method never reimplements cascade/delete logic itself (no DELETE-shaped SQL fragment, no direct access-table array manipulation)",
        stripos($body, 'DELETE FROM') === false && strpos($body, "'access'") === false);
}

w5_ok('L8 revoke_membership_for_actor calls PGE_Event_Access_Repository::revoke_membership() exactly once (cascade ownership stays in Repository)',
    (function () use ($as_code) {
        $pos = strpos($as_code, 'function revoke_membership_for_actor');
        $next_pos = strpos($as_code, 'function ', $pos + 10);
        $body = substr($as_code, $pos, $next_pos - $pos);
        return substr_count($body, 'PGE_Event_Access_Repository::revoke_membership(') === 1;
    })());

$repo_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-repository.php');
$repo_code = w5_strip_comments($repo_source);
w5_ok('L9 (regression sanity) Repository itself still never touches Post Meta or the guest projection (comments stripped)', strpos($repo_code, 'pge_event_guests_save_map') === false && strpos($repo_code, 'PGE_Event_Guest_Read_Projection') === false);

// ──────────────────────────────────────────────────────────────
// Section M — Cross-event aggregate confirmation (per-operation cases
// already proven individually in Sections E/F/G above).
// ──────────────────────────────────────────────────────────────

w5_reset_db();
w5_event(10, 1); w5_event(11, 1); w5_user(1);
$gB_membership = w5_membership(11, 43, 'manager');
foreach ([
    ['change_membership_role_for_actor', [10, 1, $gB_membership, 'manager', 'viewer']],
    ['revoke_membership_for_actor', [10, 1, $gB_membership]],
    ['reactivate_membership_for_actor', [10, 1, $gB_membership, 'manager']],
] as [$method, $args]) {
    $result = call_user_func_array([$AS, $method], $args);
    w5_ok("M1 $method with a cross-event membership_id is not_found (fail closed)", w5_code($result) === 'not_found');
}

// ──────────────────────────────────────────────────────────────
// Section N — Event-Context Enumeration Privacy (Case A: nonexistent
// event). Mirrors W1-W4's already-proven EC1 pattern: a denied actor's
// public response must be IDENTICAL whether event_id refers to a real
// event they are denied on, or to an event_id that does not exist at all.
// ──────────────────────────────────────────────────────────────

$w5_nonexistent_event_id = 999999; // never registered via w5_event() in any fixture below

$f = w5_matrix_fixture();
foreach ($w5_ops as $op => $call) {
    foreach ([
        ['manager', $f['manager']], ['viewer', $f['viewer']],
        ['revoked_manager', $f['revoked_manager']], ['revoked_viewer', $f['revoked_viewer']],
        ['stranger', $f['stranger']],
    ] as [$who, $actor_id]) {
        $before_mut = $GLOBALS['w5_mutation_calls'];
        $before_audit = count($GLOBALS['w5_db']['audit']);
        $code_existing = w5_code($call($actor_id, 10, $f));
        $mut_ok_existing = $GLOBALS['w5_mutation_calls'] === $before_mut && count($GLOBALS['w5_db']['audit']) === $before_audit;

        $before_mut = $GLOBALS['w5_mutation_calls'];
        $before_audit = count($GLOBALS['w5_db']['audit']);
        $code_missing = w5_code($call($actor_id, $w5_nonexistent_event_id, $f));
        $mut_ok_missing = $GLOBALS['w5_mutation_calls'] === $before_mut && count($GLOBALS['w5_db']['audit']) === $before_audit;

        w5_ok("N1 $op $who on an EXISTING event is not_authorized", $code_existing === 'not_authorized');
        w5_ok("N1 $op $who on a NONEXISTENT event is ALSO not_authorized, never a distinct not_found", $code_missing === 'not_authorized');
        w5_ok("N1 $op $who: existing-denied vs nonexistent-event are publicly indistinguishable (identical WP_Error code)", $code_existing === $code_missing);
        w5_ok("N1 $op $who: zero Repository mutations / zero audit rows on both sides", $mut_ok_existing && $mut_ok_missing);
    }
}

// AJAX-layer full-response byte-identity check.
$w5_handlers = [
    'create' => ['pge_event_access_create_membership_handler', ['target_user_id' => '50', 'role' => 'viewer']],
    'change_role' => ['pge_event_access_change_membership_role_handler', ['membership_id' => '1', 'expected_role' => 'manager', 'new_role' => 'viewer']],
    'revoke' => ['pge_event_access_revoke_membership_handler', ['membership_id' => '1']],
    'reactivate' => ['pge_event_access_reactivate_membership_handler', ['membership_id' => '1', 'role' => 'manager']],
];
foreach ($w5_handlers as $op => $handler_spec) {
    [$handler_fn, $extra_fields] = $handler_spec;
    foreach ([['manager', $f['manager']], ['stranger', $f['stranger']]] as [$who, $actor_id]) {
        $GLOBALS['w5_current_user_id'] = $actor_id;
        w5_ajax_post(array_merge(['event_id' => '10'], $extra_fields));
        $resp_existing = w5_call_ajax_handler($handler_fn);

        $GLOBALS['w5_current_user_id'] = $actor_id;
        w5_ajax_post(array_merge(['event_id' => (string) $w5_nonexistent_event_id], $extra_fields));
        $resp_missing = w5_call_ajax_handler($handler_fn);

        w5_ok("N2 AJAX $op $who: existing-denied response is not_authorized", $resp_existing['success'] === false && $resp_existing['data']['reason'] === 'not_authorized');
        w5_ok("N2 AJAX $op $who: nonexistent-event response is ALSO not_authorized, never not_found", $resp_missing['success'] === false && $resp_missing['data']['reason'] === 'not_authorized');
        w5_ok("N2 AJAX $op $who: full public response (reason AND message) is byte-for-byte identical between existing-denied and nonexistent-event", $resp_existing['data'] === $resp_missing['data']);
    }
}

// ──────────────────────────────────────────────────────────────
// Section O — Event-Context Enumeration Privacy (Case B: a real, existing
// event where the actor's OWN membership row is ambiguous/duplicated).
// Isolated to its OWN dedicated event (30), completely separate from every
// other fixture's event, per the H1C-EC1 follow-up precedent: Repository's
// lock_memberships() deliberately fails the WHOLE event closed with
// database_error the instant it finds ANY duplicate user_id row anywhere
// in that event — not just on the ambiguous actor's own row — so this
// fixture must never share an event with any positive-path assertion.
// ──────────────────────────────────────────────────────────────

function w5_ambiguous_membership_fixture()
{
    w5_reset_db();
    w5_event(30, 1); // owner = user 1, isolated event
    w5_user(1);
    w5_user(50);
    // Actor 8 deliberately has TWO active membership rows for the SAME
    // (event_id=30, user_id=8) pair — PGE_Event_Access_Repository::
    // get_membership_for_user() issues "... LIMIT 2" and fails closed with
    // database_error the instant it sees more than one row, inside
    // PGE_Event_Access_Authorization_Context::resolve(), strictly before
    // any role/capability decision — a PRE-AUTHORITY failure exactly like
    // the event-does-not-exist case.
    w5_membership(30, 8, 'manager');
    w5_membership(30, 8, 'viewer');
    // Dummy target ids for $w5_ops's change_role/revoke/reactivate closures
    // below — a PRE-AUTHORITY failure is reached before any of these
    // values is ever read, so their exact (nonexistent) value is
    // irrelevant to what is being proven here.
    return ['owner' => 1, 'ambiguous_actor' => 8, 'new_user' => 50, 'manager_membership' => 1, 'revoked_manager_membership' => 1];
}

$w5_nonexistent_event_id_o = 888888; // never registered in this fixture

$fo = w5_ambiguous_membership_fixture();
foreach ($w5_ops as $op => $call) {
    $before_mut = $GLOBALS['w5_mutation_calls'];
    $before_audit = count($GLOBALS['w5_db']['audit']);
    $code_existing = w5_code($call($fo['ambiguous_actor'], 30, $fo));
    $mut_ok_existing = $GLOBALS['w5_mutation_calls'] === $before_mut && count($GLOBALS['w5_db']['audit']) === $before_audit;

    $before_mut = $GLOBALS['w5_mutation_calls'];
    $before_audit = count($GLOBALS['w5_db']['audit']);
    $code_missing = w5_code($call($fo['ambiguous_actor'], $w5_nonexistent_event_id_o, $fo));
    $mut_ok_missing = $GLOBALS['w5_mutation_calls'] === $before_mut && count($GLOBALS['w5_db']['audit']) === $before_audit;

    w5_ok("O1 $op ambiguous-membership actor on the REAL event 30 is not_authorized, NOT a distinct database_error", $code_existing === 'not_authorized');
    w5_ok("O1 $op ambiguous-membership actor on a NONEXISTENT event is ALSO not_authorized", $code_missing === 'not_authorized');
    w5_ok("O1 $op: existing (ambiguous-membership) vs nonexistent-event are publicly indistinguishable (identical WP_Error code)", $code_existing === $code_missing);
    w5_ok("O1 $op: zero Repository mutations / zero audit rows on both sides", $mut_ok_existing && $mut_ok_missing);
}

// O2/O3 — regression sanity + post-authority fidelity. Deliberately run on
// a SEPARATE, CLEAN event (31) rather than reusing event 30 itself.
//
// Unlike W3/W4 (whose write methods never call lock_memberships() at all),
// every one of W5's four Repository methods calls lock_memberships($event_id)
// as their own first transactional step — and that guard is a WHOLE-EVENT
// scan (Section 12 of the brief: "already-discovered lock_memberships()
// bug... fails whole event closed on duplicate user_id membership rows...
// do not fix it in W5"). So a real Owner performing an ordinary
// create/revoke/reactivate on event 30 would ALSO hit that pre-existing,
// deliberate, out-of-scope guard (because actor 8's two rows live in that
// same event) and get a real database_error — a genuine consequence of the
// already-known Repository behavior, not a regression introduced by this
// phase, but an unrelated failure mode that would make an "unaffected"
// assertion on event 30 itself meaningless. Event 31 isolates the
// regression-sanity/fidelity proof from that unrelated, already-logged
// guard, exactly as Section 12 requires ("if it affects W5 tests, isolate
// fixture"). See Section AI of the H1C-W5 report for this adjacent finding.
w5_reset_db();
w5_event(31, 1);
w5_user(1);
w5_user(50);
$result = call_user_func([$AS, 'create_membership_for_actor'], 31, 1, 50, 'viewer');
w5_ok('O2 an unrelated real Owner on a CLEAN event can still CREATE normally — the widened PRE-AUTHORITY collapse does not affect ordinary owner writes', is_array($result) && $result['changed'] === true);

$result3 = call_user_func([$AS, 'revoke_membership_for_actor'], 31, 1, 999999);
w5_ok('O3 Owner on a CLEAN real event with a genuinely missing membership_id still returns real not_found (the widened PRE-AUTHORITY collapse did not spill into POST-authority errors)', w5_code($result3) === 'not_found');

// ──────────────────────────────────────────────────────────────
// Section P — User-ID Enumeration Resistance (CREATE-specific, Section 18
// of the brief). A denied actor must learn NOTHING about whether a target
// user_id exists, is missing, or is already a member — the response must
// be identical for all three target shapes.
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
$create_targets = [
    'existing WP user with no membership' => $f['new_user'], // 50, real WP user, no membership row
    'nonexistent WP user id' => 777777,                      // no get_userdata() row at all
    'already-a-member WP user' => $f['manager'],              // 3, real WP user WITH an active membership
];
$responses = [];
foreach ($create_targets as $label => $target_user_id) {
    $result = call_user_func([$AS, 'create_membership_for_actor'], 10, $f['stranger'], $target_user_id, 'viewer');
    w5_ok("P1 a denied Stranger creating against target ($label) is not_authorized, never not_found/duplicate", w5_code($result) === 'not_authorized');
    $responses[] = w5_code($result);
}
w5_ok('P2 all three target-user shapes produce the IDENTICAL denied response for a Stranger (no user-existence oracle)', count(array_unique($responses)) === 1);

// AJAX-layer confirmation, same three target shapes, full response compared.
$resp_by_target = [];
foreach ($create_targets as $label => $target_user_id) {
    $GLOBALS['w5_current_user_id'] = $f['stranger'];
    w5_ajax_post(['event_id' => '10', 'target_user_id' => (string) $target_user_id, 'role' => 'viewer']);
    $resp_by_target[$label] = w5_call_ajax_handler('pge_event_access_create_membership_handler');
}
$first = null;
$all_identical = true;
foreach ($resp_by_target as $label => $resp) {
    w5_ok("P3 AJAX create by a denied Stranger against target ($label) is not_authorized", $resp['success'] === false && $resp['data']['reason'] === 'not_authorized');
    if ($first === null) { $first = $resp['data']; } elseif ($resp['data'] !== $first) { $all_identical = false; }
}
w5_ok('P4 AJAX create: full public response is byte-for-byte identical across all three target-user shapes for a denied Stranger', $all_identical);

// Positive-path confirmation: a proven Owner genuinely DOES get the real,
// honest not_found for a nonexistent target user (fidelity, not a blind
// collapse of every not_found anywhere in CREATE).
$f = w5_matrix_fixture();
$owner_result = call_user_func([$AS, 'create_membership_for_actor'], 10, $f['owner'], 777777, 'viewer');
w5_ok('P5 a proven Owner creating against a genuinely nonexistent target user still gets the real not_found (post-authority fidelity preserved)', w5_code($owner_result) === 'not_found');

// ──────────────────────────────────────────────────────────────
// Section Q — Membership-ID Enumeration Resistance (CHANGE_ROLE/REVOKE/
// REACTIVATE). A denied actor must learn nothing about whether a
// membership_id exists from the endpoint's response.
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
foreach (['change_role', 'revoke', 'reactivate'] as $op) {
    $call = $w5_ops[$op];
    $code_existing_target = w5_code($call($f['stranger'], 10, $f)); // real membership_id (manager_membership / revoked_manager_membership per op)
    $code_missing_target = w5_code((function () use ($op, $AS, $f) {
        switch ($op) {
            case 'change_role': return call_user_func([$AS, 'change_membership_role_for_actor'], 10, $f['stranger'], 999999, 'manager', 'viewer');
            case 'revoke': return call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['stranger'], 999999);
            case 'reactivate': return call_user_func([$AS, 'reactivate_membership_for_actor'], 10, $f['stranger'], 999999, 'manager');
        }
    })());
    w5_ok("Q1 $op by a denied Stranger against a REAL membership_id is not_authorized, never not_found", $code_existing_target === 'not_authorized');
    w5_ok("Q1 $op by a denied Stranger against a MISSING membership_id is ALSO not_authorized", $code_missing_target === 'not_authorized');
    w5_ok("Q1 $op: real-membership vs missing-membership are publicly indistinguishable for a denied actor (identical code)", $code_existing_target === $code_missing_target);
}

// ──────────────────────────────────────────────────────────────
// Section R — Self-management audit sanity (Section 6 of the brief): an
// Owner/Admin who ALSO happens to hold an ordinary membership row can
// target that row like any other real membership — no special-casing
// exists (or is needed) because Owner/Admin authority never consults the
// membership table in the first place.
// ──────────────────────────────────────────────────────────────

$f = w5_matrix_fixture();
$owner_also_manager = w5_membership(10, $f['owner'], 'manager'); // Owner (user 1) also happens to hold a membership row
$result = call_user_func([$AS, 'revoke_membership_for_actor'], 10, $f['owner'], $owner_also_manager);
w5_ok('R1 an Owner can target their OWN incidental membership row exactly like any other — no self-management special case needed or present', is_array($result) && $result['changed'] === true);

echo "\nH1C-W5: {$passed}/" . ($passed + $failed) . " passed\n";
exit($failed === 0 ? 0 : 1);
