<?php
/**
 * Phase H1C-A2 — H1B Authorization Core (Fix Pass).
 *
 * Exercises the real PGE_Event_Access_Authorization /
 * PGE_Event_Access_Authorization_Context classes together with the real
 * (unmodified-in-behavior) PGE_Event_Access_Repository, against a small
 * in-memory fake $wpdb. No real database. No AJAX/UI is exercised — this
 * phase fixes the authorization core only, it does not wire it to any
 * production endpoint.
 *
 * This fix pass hardens three things found by external review:
 *   FIX A — a trusted, unforgeable AuthorizationContext object (Section K).
 *   FIX B — Manager guest projection now includes 'note' (Section H).
 *   FIX C — Owner/Admin never read the membership table at all (Section C9-C13).
 *
 * Run: php tests/test-event-access-authorization-phase-h1c-a2.php
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
// WordPress function shims — only what resolve_context()/Repository reads
// actually call. No current_user_can()/get_current_user_id()/nonce/session
// shim exists on purpose: the Authorization Core must never call them.
// ──────────────────────────────────────────────────────────────

function get_post_type($post_id)
{
    return $GLOBALS['a2_posts'][(int) $post_id]['post_type'] ?? false;
}

function get_post_field($field, $post_id)
{
    if (!isset($GLOBALS['a2_posts'][(int) $post_id][$field])) return '';
    return (string) $GLOBALS['a2_posts'][(int) $post_id][$field];
}

function user_can($user_id, $capability)
{
    if ($capability !== 'administrator') return false;
    return in_array((int) $user_id, $GLOBALS['a2_admins'], true);
}

function current_time($type, $gmt = 0)
{
    return '2026-01-01 00:00:00';
}

function wp_json_encode($value)
{
    return json_encode($value);
}

// ──────────────────────────────────────────────────────────────
// Fake schema — always "ready" unless a test flips it, and proves
// maybe_upgrade() is never invoked by any read path exercised here.
// ──────────────────────────────────────────────────────────────

class PGE_Event_Access_Schema
{
    public static function is_ready()
    {
        return $GLOBALS['a2_schema_ready'];
    }

    public static function maybe_upgrade()
    {
        throw new RuntimeException('maybe_upgrade() must never be called by an Authorization/Repository read path.');
    }
}

// ──────────────────────────────────────────────────────────────
// In-memory fixture store + a small SQL-shape dispatcher. The Authorization
// Core only ever drives three real Repository read call shapes (membership
// by user, membership by id used inside list_group_ids_for_membership, and
// the new list_group_assignments_for_groups). Anything unrecognized is a
// hard test failure rather than a silent empty result, so a query shape
// this harness does not know about cannot be mistaken for "no data".
// ──────────────────────────────────────────────────────────────

function a2_tables()
{
    return [
        'groups' => 'wp_pge_event_invitation_groups',
        'memberships' => 'wp_pge_event_host_memberships',
        'access' => 'wp_pge_event_host_group_access',
        'assignments' => 'wp_pge_invitation_group_assignments',
    ];
}

function a2_reset_db()
{
    $GLOBALS['a2_db'] = [
        'groups' => [],
        'memberships' => [],
        'access' => [],
        'assignments' => [],
        'next_id' => 1,
        'query_count' => 0,
    ];
    $GLOBALS['a2_posts'] = [];
    $GLOBALS['a2_admins'] = [];
    $GLOBALS['a2_schema_ready'] = true;
}

function a2_next_id()
{
    return $GLOBALS['a2_db']['next_id']++;
}

function a2_event($event_id, $author_id)
{
    $GLOBALS['a2_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => $author_id];
}

function a2_group($event_id, $status = 'active', $name = 'Group')
{
    $id = a2_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['a2_db']['groups'][$id] = [
        'id' => $id,
        'event_id' => $event_id,
        'name' => $name,
        'name_key' => $status === 'active' ? strtolower($name) . '-' . $id : null,
        'status' => $status,
        'default_slot' => null,
        'created_by_user_id' => 1,
        'created_at' => $now,
        'updated_at' => $now,
        'archived_at' => $status === 'archived' ? $now : null,
    ];
    return $id;
}

function a2_membership($event_id, $user_id, $role, $status = 'active')
{
    $id = a2_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['a2_db']['memberships'][$id] = [
        'id' => $id,
        'event_id' => $event_id,
        'user_id' => $user_id,
        'role' => $role,
        'status' => $status,
        'created_by_user_id' => 1,
        'activated_at' => $now,
        'revoked_at' => $status === 'revoked' ? $now : null,
        'created_at' => $now,
        'updated_at' => $now,
        // H1C-W8: additive nullable column now present on every real row.
        'allocated_quota' => null,
    ];
    return $id;
}

function a2_access($event_id, $membership_id, $group_id)
{
    $id = a2_next_id();
    $GLOBALS['a2_db']['access'][] = [
        'id' => $id,
        'event_id' => $event_id,
        'membership_id' => $membership_id,
        'group_id' => $group_id,
        'granted_by_user_id' => 1,
        'created_at' => '2026-01-01 00:00:00',
    ];
    return $id;
}

function a2_assignment($event_id, $phone, $group_id)
{
    $id = a2_next_id();
    $now = '2026-01-01 00:00:00';
    $GLOBALS['a2_db']['assignments'][$id] = [
        'id' => $id,
        'event_id' => $event_id,
        'guest_phone' => $phone,
        'group_id' => $group_id,
        'assigned_by_user_id' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    return $id;
}

function a2_dispatch_select($sql)
{
    $t = a2_tables();
    $sql = trim($sql);

    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND user_id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $user_id = (int) $m[2];
        return array_values(array_filter($GLOBALS['a2_db']['memberships'], function ($r) use ($event_id, $user_id) {
            return $r['event_id'] === $event_id && $r['user_id'] === $user_id;
        }));
    }

    if (preg_match('/^SELECT \* FROM ' . preg_quote($t['memberships'], '/') . ' WHERE event_id = (\d+) AND id = (\d+) LIMIT 2$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $id = (int) $m[2];
        return array_values(array_filter($GLOBALS['a2_db']['memberships'], function ($r) use ($event_id, $id) {
            return $r['event_id'] === $event_id && $r['id'] === $id;
        }));
    }

    $join = '/^SELECT a\.event_id, a\.group_id, g\.event_id AS related_event_id FROM '
        . preg_quote($t['access'], '/') . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.membership_id = (\d+) ORDER BY a\.group_id ASC$/';
    if (preg_match($join, $sql, $m)) {
        $event_id = (int) $m[1];
        $membership_id = (int) $m[2];
        $out = [];
        foreach ($GLOBALS['a2_db']['access'] as $row) {
            if ($row['event_id'] !== $event_id || $row['membership_id'] !== $membership_id) continue;
            $group = $GLOBALS['a2_db']['groups'][$row['group_id']] ?? null;
            $out[] = ['event_id' => $row['event_id'], 'group_id' => $row['group_id'], 'related_event_id' => $group['event_id'] ?? null];
        }
        return $out;
    }

    $scoped = '/^SELECT a\.\*, g\.event_id AS related_event_id FROM ' . preg_quote($t['assignments'], '/')
        . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.group_id IN \(([\d, ]+)\) ORDER BY a\.id ASC LIMIT (\d+) OFFSET (\d+)$/';
    if (preg_match($scoped, $sql, $m)) {
        $event_id = (int) $m[1];
        $ids = array_map('intval', array_map('trim', explode(',', $m[2])));
        $limit = (int) $m[3];
        $offset = (int) $m[4];
        $rows = [];
        foreach ($GLOBALS['a2_db']['assignments'] as $row) {
            if ($row['event_id'] !== $event_id || !in_array($row['group_id'], $ids, true)) continue;
            $group = $GLOBALS['a2_db']['groups'][$row['group_id']] ?? null;
            $rows[] = array_merge($row, ['related_event_id' => $group['event_id'] ?? null]);
        }
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return array_slice($rows, $offset, $limit);
    }

    return null;
}

function a2_dispatch_scalar($sql)
{
    $t = a2_tables();
    $sql = trim($sql);
    $pattern = '/^SELECT COUNT\(\*\) FROM ' . preg_quote($t['assignments'], '/') . ' a LEFT JOIN ' . preg_quote($t['groups'], '/')
        . ' g ON g\.id = a\.group_id WHERE a\.event_id = (\d+) AND a\.group_id IN \(([\d, ]+)\)$/';
    if (preg_match($pattern, $sql, $m)) {
        $event_id = (int) $m[1];
        $ids = array_map('intval', array_map('trim', explode(',', $m[2])));
        $count = 0;
        foreach ($GLOBALS['a2_db']['assignments'] as $row) {
            if ($row['event_id'] === $event_id && in_array($row['group_id'], $ids, true)) $count++;
        }
        return $count;
    }
    return false;
}

class PGE_A2_Fake_WPDB
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
        $GLOBALS['a2_db']['query_count']++;
        $rows = a2_dispatch_select($sql);
        if ($rows === null) {
            $this->last_error = 'a2-fake-wpdb: unrecognized SELECT: ' . $sql;
            return null;
        }
        return $rows;
    }

    public function get_var($sql)
    {
        $GLOBALS['a2_db']['query_count']++;
        $value = a2_dispatch_scalar($sql);
        if ($value === false) {
            $this->last_error = 'a2-fake-wpdb: unrecognized scalar query: ' . $sql;
            return null;
        }
        return $value;
    }

    public function query($sql)
    {
        $this->last_error = 'a2-fake-wpdb: unexpected mutating query() call: ' . $sql;
        return false;
    }

    public function delete($table, $where, $formats)
    {
        $this->last_error = 'a2-fake-wpdb: unexpected delete() call';
        return false;
    }

    public function insert($table, $data, $formats = null)
    {
        $this->last_error = 'a2-fake-wpdb: unexpected insert() call';
        return false;
    }
}

a2_reset_db();
global $wpdb;
$wpdb = new PGE_A2_Fake_WPDB();

require_once PGE_PATH . 'includes/helpers.php';
require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';
require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';

// ──────────────────────────────────────────────────────────────
// Assertion helpers
// ──────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$failures = [];

function a2_ok($label, $condition, $extra = '')
{
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failed++;
        $failures[] = $label . ($extra !== '' ? " ({$extra})" : '');
        echo "FAIL: $label" . ($extra !== '' ? " ({$extra})" : '') . "\n";
    }
}

function a2_code($result)
{
    return $result instanceof WP_Error ? $result->get_error_code() : null;
}

function a2_allowed($decision)
{
    return is_array($decision) && ($decision['allowed'] ?? null) === true;
}

function a2_denied($decision)
{
    return is_array($decision) && ($decision['allowed'] ?? null) === false;
}

$A = 'PGE_Event_Access_Authorization';
$R = 'PGE_Event_Access_Repository';
$CTX = 'PGE_Event_Access_Authorization_Context';

// ══════════════════════════════════════════════════════════════
// Section A — Context resolution: Owner / Admin / edit_post-alone
// ══════════════════════════════════════════════════════════════

a2_reset_db();
a2_event(100, 50); // event 100, post_author = user 50

$ctx = $A::resolve_context(100, 50);
a2_ok('A1 event author resolves as owner', $ctx instanceof PGE_Event_Access_Authorization_Context && $ctx->is_owner() === true && $ctx->is_admin() === false);
a2_ok('A2 owner needs no membership row', $ctx instanceof PGE_Event_Access_Authorization_Context && $ctx->membership_id() === null && $ctx->membership_role() === null);

$ctx_stranger = $A::resolve_context(100, 51);
a2_ok('A3 non-author non-admin non-member is neither owner nor admin', $ctx_stranger instanceof PGE_Event_Access_Authorization_Context && $ctx_stranger->is_owner() === false && $ctx_stranger->is_admin() === false);
a2_ok('A4 stranger has no collaborator authority', $ctx_stranger instanceof PGE_Event_Access_Authorization_Context && $ctx_stranger->membership_role() === null && $ctx_stranger->granted_group_ids() === []);

$GLOBALS['a2_admins'] = [77];
$ctx_admin = $A::resolve_context(100, 77);
a2_ok('A5 administrator recognized regardless of ownership', $ctx_admin instanceof PGE_Event_Access_Authorization_Context && $ctx_admin->is_admin() === true && $ctx_admin->is_owner() === false);
a2_ok('A6 administrator needs no membership row', $ctx_admin instanceof PGE_Event_Access_Authorization_Context && $ctx_admin->membership_id() === null);

// H1B Owner is exclusively post_author + Admin override — there is no
// "edit_post capability" shim in this harness at all (Section 7: edit_post
// alone must not grant H1B authority, and this class never calls
// current_user_can()/user meta capability checks for edit_post in the
// first place, only user_can($actor,'administrator') and post_author).
a2_ok('A7 no current_user_can()/edit_post shim exists in this harness', !function_exists('current_user_can'));

// ══════════════════════════════════════════════════════════════
// Section B — Context resolution: Manager / Viewer, active / revoked
// ══════════════════════════════════════════════════════════════

a2_reset_db();
a2_event(200, 60);
$g1 = a2_group(200, 'active', 'Family');
$g2 = a2_group(200, 'active', 'Friends');
$mgr_membership = a2_membership(200, 61, 'manager', 'active');
a2_access(200, $mgr_membership, $g1);

$ctx_mgr = $A::resolve_context(200, 61);
a2_ok('B1 active manager resolves with role manager', $ctx_mgr instanceof PGE_Event_Access_Authorization_Context && $ctx_mgr->membership_role() === 'manager' && $ctx_mgr->membership_status() === 'active');
a2_ok('B2 active manager granted_group_ids reflects explicit grants only', $ctx_mgr instanceof PGE_Event_Access_Authorization_Context && $ctx_mgr->granted_group_ids() === [$g1]);

$viewer_membership = a2_membership(200, 62, 'viewer', 'active');
a2_access(200, $viewer_membership, $g2);
$ctx_viewer = $A::resolve_context(200, 62);
a2_ok('B3 active viewer resolves with role viewer', $ctx_viewer instanceof PGE_Event_Access_Authorization_Context && $ctx_viewer->membership_role() === 'viewer');
a2_ok('B4 viewer granted_group_ids reflects explicit grants only', $ctx_viewer instanceof PGE_Event_Access_Authorization_Context && $ctx_viewer->granted_group_ids() === [$g2]);

$revoked_membership = a2_membership(200, 63, 'manager', 'revoked');
$ctx_revoked = $A::resolve_context(200, 63);
a2_ok('B5 revoked membership yields no authority role', $ctx_revoked instanceof PGE_Event_Access_Authorization_Context && $ctx_revoked->membership_role() === null && $ctx_revoked->granted_group_ids() === []);
a2_ok('B6 revoked membership status is still reported for diagnostics', $ctx_revoked instanceof PGE_Event_Access_Authorization_Context && $ctx_revoked->membership_status() === 'revoked');

// ══════════════════════════════════════════════════════════════
// Section C — Fail-closed context resolution
// ══════════════════════════════════════════════════════════════

a2_ok('C1 actor_user_id <= 0 is rejected', a2_code($A::resolve_context(200, 0)) === 'invalid_input');
a2_ok('C2 negative actor_user_id is rejected', a2_code($A::resolve_context(200, -5)) === 'invalid_input');
a2_ok('C3 event_id <= 0 is rejected', a2_code($A::resolve_context(0, 61)) === 'invalid_input');
a2_ok('C4 non-existent event is rejected', a2_code($A::resolve_context(999999, 61)) === 'not_found');

a2_event(201, 60);
$GLOBALS['a2_posts'][201]['post_type'] = 'post'; // wrong post type
a2_ok('C5 wrong post type is rejected', a2_code($A::resolve_context(201, 60)) === 'not_found');

$GLOBALS['a2_schema_ready'] = false;
a2_ok('C6 schema-not-ready fails closed (propagated from Repository guard)', a2_code($A::resolve_context(200, 61)) === 'schema_not_ready');
$GLOBALS['a2_schema_ready'] = true;

// Duplicate/ambiguous membership row for the same (event, user): the real
// Repository's own read_optional_row() already refuses to disambiguate
// more than one matching row and returns database_error — Authorization
// must propagate that WP_Error rather than picking either row or falling
// back to any role.
a2_membership(200, 64, 'manager', 'active');
a2_membership(200, 64, 'viewer', 'active');
a2_ok('C7 duplicate/ambiguous membership fails closed via Repository propagation', a2_code($A::resolve_context(200, 64)) === 'database_error');

// Malformed role: bypasses fixture helper to insert a row Repository's own
// normalize_membership() must reject (role outside manager/viewer).
$bad_id = a2_next_id();
$GLOBALS['a2_db']['memberships'][$bad_id] = [
    'id' => $bad_id, 'event_id' => 200, 'user_id' => 65, 'role' => 'superuser', 'status' => 'active',
    'created_by_user_id' => 1, 'activated_at' => '2026-01-01 00:00:00', 'revoked_at' => null,
    'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    // H1C-W8: present so this row's ONLY corruption is the malformed role
    // (the thing C8 actually intends to test), not an incidental missing-
    // column failure that happens to share the same database_error code.
    'allocated_quota' => null,
];
a2_ok('C8 malformed role fails closed via Repository propagation, never becomes Viewer', a2_code($A::resolve_context(200, 65)) === 'database_error');

// ──────────────────────────────────────────────────────────────
// FIX C — Owner/Admin membership independence: the same corruption
// patterns that fail a plain collaborator closed (C7/C8 above) must never
// affect Owner/Admin, because resolve() proves is_admin/is_owner from
// post_type + user_can()/post_author alone and never reads the membership
// table for them at all.
// ──────────────────────────────────────────────────────────────

// Owner (user 60, post_author of event 200) gets the exact same
// duplicate-membership corruption that made user 64 fail closed above.
a2_membership(200, 60, 'manager', 'active');
a2_membership(200, 60, 'viewer', 'active');
$ctx_owner_with_corruption = $A::resolve_context(200, 60);
a2_ok('C9 (FIX C) owner with corrupt/duplicate H1B membership rows still resolves as owner', $ctx_owner_with_corruption instanceof PGE_Event_Access_Authorization_Context && $ctx_owner_with_corruption->is_owner() === true);
a2_ok('C10 (FIX C) owner context is unaffected by the corruption — no membership row is attached', $ctx_owner_with_corruption instanceof PGE_Event_Access_Authorization_Context && $ctx_owner_with_corruption->membership_id() === null && $ctx_owner_with_corruption->membership_role() === null);

// Admin (user 90) gets a malformed-role membership row, the same shape
// that made user 65 fail closed above (C8).
$GLOBALS['a2_admins'][] = 90;
$admin_corrupt_id = a2_next_id();
$GLOBALS['a2_db']['memberships'][$admin_corrupt_id] = [
    'id' => $admin_corrupt_id, 'event_id' => 200, 'user_id' => 90, 'role' => 'not-a-real-role', 'status' => 'active',
    'created_by_user_id' => 1, 'activated_at' => '2026-01-01 00:00:00', 'revoked_at' => null,
    'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    // H1C-W8: present so this row's ONLY corruption is the malformed role.
    'allocated_quota' => null,
];
$ctx_admin_with_corruption = $A::resolve_context(200, 90);
a2_ok('C11 (FIX C) admin with a malformed H1B membership row still resolves as admin', $ctx_admin_with_corruption instanceof PGE_Event_Access_Authorization_Context && $ctx_admin_with_corruption->is_admin() === true);
a2_ok('C12 (FIX C) admin context is unaffected by the corruption — no membership row is attached', $ctx_admin_with_corruption instanceof PGE_Event_Access_Authorization_Context && $ctx_admin_with_corruption->membership_id() === null);

// Contrast, re-affirmed with a fresh user id: a normal collaborator (not
// owner, not admin) with the same corruption pattern still fails closed —
// the short-circuit above is Owner/Admin-specific, not a general relaxation.
a2_membership(200, 67, 'manager', 'active');
a2_membership(200, 67, 'viewer', 'active');
a2_ok('C13 (FIX C contrast) a plain collaborator with duplicate membership rows still fails closed', a2_code($A::resolve_context(200, 67)) === 'database_error');

// ══════════════════════════════════════════════════════════════
// Section D — Structural / membership-admin / group-access-admin / audit
// capability contract: Owner and Admin only.
// ══════════════════════════════════════════════════════════════

$owner_ctx = $A::resolve_context(200, 60);
$GLOBALS['a2_admins'] = [90];
$admin_ctx = $A::resolve_context(200, 90);
$mgr_ctx = $A::resolve_context(200, 61);
$viewer_ctx = $A::resolve_context(200, 62);

foreach ([
    'can_manage_event_structure',
    'can_manage_membership',
    'can_manage_group_access',
    'can_view_audit',
    'can_view_unassigned_guests',
] as $capability) {
    a2_ok("D owner is allowed: $capability", a2_allowed($A::$capability($owner_ctx)));
    a2_ok("D admin is allowed: $capability", a2_allowed($A::$capability($admin_ctx)));
    a2_ok("D manager is denied: $capability", a2_denied($A::$capability($mgr_ctx)));
    a2_ok("D viewer is denied: $capability", a2_denied($A::$capability($viewer_ctx)));
}

// ══════════════════════════════════════════════════════════════
// Section E — Group visibility: granted vs ungranted, incl. archived
// ══════════════════════════════════════════════════════════════

a2_ok('E1 owner can view any group', a2_allowed($A::can_view_group($owner_ctx, $g1)) && a2_allowed($A::can_view_group($owner_ctx, $g2)));
a2_ok('E2 admin can view any group', a2_allowed($A::can_view_group($admin_ctx, $g1)) && a2_allowed($A::can_view_group($admin_ctx, $g2)));
a2_ok('E3 manager can view granted group', a2_allowed($A::can_view_group($mgr_ctx, $g1)));
a2_ok('E4 manager cannot view ungranted group', a2_denied($A::can_view_group($mgr_ctx, $g2)));
a2_ok('E5 viewer can view granted group', a2_allowed($A::can_view_group($viewer_ctx, $g2)));
a2_ok('E6 viewer cannot view ungranted group', a2_denied($A::can_view_group($viewer_ctx, $g1)));

// A previously-granted archived group stays readable (access rows survive
// archive_group()); the Authorization Core allows the read, Repository
// still governs whether a *mutation* into it is valid (Section 20).
$g_archived = a2_group(200, 'archived', 'Old Cousins');
a2_access(200, $mgr_membership, $g_archived);
$mgr_ctx_after_archive = $A::resolve_context(200, 61);
a2_ok('E7 archived-but-granted group is still visible to manager', a2_allowed($A::can_view_group($mgr_ctx_after_archive, $g_archived)));

// ══════════════════════════════════════════════════════════════
// Section F — Guest visibility + enumeration resistance
// (Sections 18-19, 32, 43)
// ══════════════════════════════════════════════════════════════

a2_ok('F1 owner sees an unassigned guest (assigned_group_id=null)', a2_allowed($A::can_view_guest($owner_ctx, null)));
a2_ok('F2 admin sees an unassigned guest', a2_allowed($A::can_view_guest($admin_ctx, null)));
a2_ok('F3 manager cannot see an unassigned guest', a2_denied($A::can_view_guest($mgr_ctx, null)));
a2_ok('F4 viewer cannot see an unassigned guest', a2_denied($A::can_view_guest($viewer_ctx, null)));

a2_ok('F5 manager sees a guest assigned to a granted group', a2_allowed($A::can_view_guest($mgr_ctx, $g1)));
a2_ok('F6 manager cannot see a guest assigned to an ungranted group', a2_denied($A::can_view_guest($mgr_ctx, $g2)));

$deny_unassigned = $A::can_view_guest($mgr_ctx, null);
$deny_ungranted = $A::can_view_guest($mgr_ctx, $g2);
a2_ok(
    'F7 enumeration resistance: unassigned-guest denial and ungranted-group denial are shape-identical',
    $deny_unassigned === $deny_ungranted
);

$deny_ungranted_group_read = $A::can_view_group($mgr_ctx, $g2);
$deny_nonexistent_group_read = $A::can_view_group($mgr_ctx, 999999);
a2_ok(
    'F8 enumeration resistance: ungranted-existing-group and nonexistent-group denials are shape-identical',
    $deny_ungranted_group_read === $deny_nonexistent_group_read
);

// ══════════════════════════════════════════════════════════════
// Section G — Guest mutation authorization: assign / move / unassign
// (Sections 21-23)
// ══════════════════════════════════════════════════════════════

a2_ok('G1 owner can assign', a2_allowed($A::can_assign_guest($owner_ctx)));
a2_ok('G2 admin can assign', a2_allowed($A::can_assign_guest($admin_ctx)));
a2_ok('G3 manager cannot assign (initial assignment requires unassigned-pool visibility)', a2_denied($A::can_assign_guest($mgr_ctx)));
a2_ok('G4 viewer cannot assign', a2_denied($A::can_assign_guest($viewer_ctx)));

a2_ok('G5 owner can move between any two groups', a2_allowed($A::can_move_guest($owner_ctx, $g1, $g2)));
a2_ok('G6 admin can move between any two groups', a2_allowed($A::can_move_guest($admin_ctx, $g1, $g2)));
a2_ok('G7 manager can move granted -> granted', a2_allowed($A::can_move_guest($mgr_ctx_after_archive, $g1, $g_archived)));
a2_ok('G8 manager cannot move granted -> ungranted', a2_denied($A::can_move_guest($mgr_ctx, $g1, $g2)));
a2_ok('G9 manager cannot move ungranted -> granted', a2_denied($A::can_move_guest($mgr_ctx, $g2, $g1)));
a2_ok('G10 manager same-group move (G1->G1) requires G1 itself to be granted, never a bypass', a2_allowed($A::can_move_guest($mgr_ctx, $g1, $g1)));
a2_ok('G11 manager same-ungranted-group move is still denied (no bypass via no-op)', a2_denied($A::can_move_guest($mgr_ctx, $g2, $g2)));
a2_ok('G12 viewer cannot move', a2_denied($A::can_move_guest($viewer_ctx, $g2, $g2)));

a2_ok('G13 owner can unassign even with an unresolved current group', a2_allowed($A::can_unassign_guest($owner_ctx, null)));
a2_ok('G14 admin can unassign even with an unresolved current group', a2_allowed($A::can_unassign_guest($admin_ctx, null)));
a2_ok('G15 manager can unassign from a resolvable granted current group', a2_allowed($A::can_unassign_guest($mgr_ctx, $g1)));
a2_ok('G16 manager cannot unassign from an ungranted current group', a2_denied($A::can_unassign_guest($mgr_ctx, $g2)));
a2_ok('G17 manager cannot unassign when the current group is unresolved/malformed', a2_denied($A::can_unassign_guest($mgr_ctx, null)));
a2_ok('G18 viewer cannot unassign under any circumstance', a2_denied($A::can_unassign_guest($viewer_ctx, $g2)));

// ══════════════════════════════════════════════════════════════
// Section H — Guest projection: real field names only, no invented ones
// FIX B: Manager keeps 'note' (operational), still excludes 'code'.
// Viewer excludes both 'note' and 'code'.
// ══════════════════════════════════════════════════════════════

$full_payload = [
    'phone' => '970000012345',
    'name' => 'Test Guest',
    'note' => 'internal seating note',
    'code' => 'ABCD-1234',
    'status' => 'yes',
    'status_label' => 'سيحضر',
    'checked' => false,
];

$owner_projection = $A::project_guest_fields($owner_ctx, $full_payload);
$admin_projection = $A::project_guest_fields($admin_ctx, $full_payload);
a2_ok('H1 owner projection includes every real field', $owner_projection === $full_payload);
a2_ok('H2 admin projection includes every real field', $admin_projection === $full_payload);

$mgr_projection = $A::project_guest_fields($mgr_ctx, $full_payload);
$viewer_projection = $A::project_guest_fields($viewer_ctx, $full_payload);
$expected_manager = ['phone' => '970000012345', 'name' => 'Test Guest', 'note' => 'internal seating note', 'status' => 'yes', 'status_label' => 'سيحضر', 'checked' => false];
$expected_viewer = ['phone' => '970000012345', 'name' => 'Test Guest', 'status' => 'yes', 'status_label' => 'سيحضر', 'checked' => false];
a2_ok('H3 (FIX B) manager projection includes note', array_key_exists('note', $mgr_projection) && $mgr_projection['note'] === 'internal seating note');
a2_ok('H4 (FIX B) manager projection excludes code', !array_key_exists('code', $mgr_projection));
a2_ok('H5 manager projection matches the exact expected operational shape', $mgr_projection === $expected_manager);
a2_ok('H6 (FIX B) viewer projection excludes note', !array_key_exists('note', $viewer_projection));
a2_ok('H7 (FIX B) viewer projection excludes code', !array_key_exists('code', $viewer_projection));
a2_ok('H8 viewer projection matches the exact expected read-only shape', $viewer_projection === $expected_viewer);
a2_ok('H9 viewer projection carries no internal note text at all', strpos(json_encode($viewer_projection), 'internal seating note') === false);
a2_ok('H10 neither manager nor viewer projection carries the invite code', !array_key_exists('code', $mgr_projection) && !array_key_exists('code', $viewer_projection));

// Payload missing a proposed-but-nonexistent field must never be invented
// (still true for all three tiers).
$partial_payload = ['phone' => '970000099999', 'name' => 'Partial Guest'];
$partial_projection_viewer = $A::project_guest_fields($viewer_ctx, $partial_payload);
$partial_projection_manager = $A::project_guest_fields($mgr_ctx, $partial_payload);
a2_ok('H11 viewer projection never invents a field absent from the real payload', $partial_projection_viewer === $partial_payload && !array_key_exists('status', $partial_projection_viewer));
a2_ok('H12 manager projection never invents a field absent from the real payload (e.g. no note key if none exists)', $partial_projection_manager === $partial_payload && !array_key_exists('note', $partial_projection_manager));

// ══════════════════════════════════════════════════════════════
// Section I — Scoped guest read (Sections 28-30)
// ══════════════════════════════════════════════════════════════

a2_assignment(200, '970000011111', $g1); // granted to manager
a2_assignment(200, '970000022222', $g2); // NOT granted to manager
a2_assignment(200, '970000033333', $g_archived); // granted (archived group)

$before = $GLOBALS['a2_db']['query_count'];
$scoped = $A::list_scoped_group_assignments($mgr_ctx_after_archive);
$after = $GLOBALS['a2_db']['query_count'];
a2_ok('I1 scoped read for manager succeeds', is_array($scoped) && array_key_exists('items', $scoped));
$phones = array_map(function ($row) { return $row['guest_phone']; }, $scoped['items'] ?? []);
sort($phones);
a2_ok('I2 scoped read returns only assignments in granted groups', $phones === ['970000011111', '970000033333']);
a2_ok('I3 scoped read never returns a guest from an ungranted group', !in_array('970000022222', $phones, true));

// Zero granted groups: must short-circuit to an empty result without
// issuing a single query (Section 28: never load PII outside scope, not
// even by querying and filtering after the fact).
$empty_membership = a2_membership(200, 66, 'viewer', 'active');
$empty_ctx = $A::resolve_context(200, 66);
$before_empty = $GLOBALS['a2_db']['query_count'];
$empty_result = $A::list_scoped_group_assignments($empty_ctx);
$after_empty = $GLOBALS['a2_db']['query_count'];
a2_ok('I4 zero-granted-group actor gets an empty result', $empty_result === ['items' => [], 'page' => 1, 'per_page' => PGE_Event_Access_Repository::DEFAULT_PER_PAGE, 'total' => 0, 'total_pages' => 0]);
a2_ok('I5 zero-granted-group scoped read issues no query at all', $after_empty === $before_empty);

a2_ok('I6 scoped read is refused for owner (must read via Repository directly, not this scoping wrapper)', a2_code($A::list_scoped_group_assignments($owner_ctx)) === 'invalid_scope');
a2_ok('I7 scoped read is refused for admin', a2_code($A::list_scoped_group_assignments($admin_ctx)) === 'invalid_scope');
a2_ok('I8 scoped read is refused for a revoked-membership context', a2_code($A::list_scoped_group_assignments($ctx_revoked)) === 'invalid_scope');

// ══════════════════════════════════════════════════════════════
// Section J — PII boundary in denial results
// ══════════════════════════════════════════════════════════════

$denial = $A::can_view_guest($mgr_ctx, $g2);
a2_ok('J1 a denial result contains no phone-shaped digit string', preg_match('/\d{6,}/', json_encode($denial)) !== 1);
a2_ok('J2 a denial result contains no guest name text', strpos(json_encode($denial), 'Test Guest') === false);
$all_reasons = [
    PGE_Event_Access_Authorization::REASON_ADMIN,
    PGE_Event_Access_Authorization::REASON_OWNER,
    PGE_Event_Access_Authorization::REASON_GRANTED_SCOPE,
    PGE_Event_Access_Authorization::REASON_NOT_AUTHORIZED,
    PGE_Event_Access_Authorization::REASON_INVALID_CONTEXT,
];
a2_ok('J3 the full reason-code taxonomy is a small fixed enumerable set', $all_reasons === array_unique($all_reasons) && count($all_reasons) === 5);
foreach ($all_reasons as $reason) {
    a2_ok("J4 reason code '$reason' is static text, never guest data", preg_match('/^[a-z_]+$/', $reason) === 1);
}

// WP_Error database/schema failures never leak SQL or wpdb internals.
$db_error = $A::resolve_context(200, 64); // duplicate-membership case from Section C
a2_ok('J5 a database_error WP_Error carries no SQL text', is_wp_error($db_error) && strpos($db_error->get_error_message(), 'SELECT') === false);

// ══════════════════════════════════════════════════════════════
// Section K — FIX A: trusted context / forgery resistance
// ══════════════════════════════════════════════════════════════

// K1-K7: a plain array that mimics the OLD (pre-fix) context shape,
// including a fully "complete" shape with is_admin/is_owner/role/scope set
// however an attacker likes, must never be treated as a trusted context by
// any decision method — there is no array shape that satisfies `instanceof
// PGE_Event_Access_Authorization_Context`.

$forged_admin = ['actor_user_id' => 1, 'event_id' => 200, 'is_admin' => true, 'is_owner' => false, 'membership_id' => null, 'membership_role' => null, 'membership_status' => null, 'granted_group_ids' => []];
$forged_owner = ['actor_user_id' => 1, 'event_id' => 200, 'is_admin' => false, 'is_owner' => true, 'membership_id' => null, 'membership_role' => null, 'membership_status' => null, 'granted_group_ids' => []];
$forged_manager = ['actor_user_id' => 999, 'event_id' => 200, 'is_admin' => false, 'is_owner' => false, 'membership_id' => 9999, 'membership_role' => 'manager', 'membership_status' => 'active', 'granted_group_ids' => [$g1, $g2]];
$forged_viewer = ['actor_user_id' => 999, 'event_id' => 200, 'is_admin' => false, 'is_owner' => false, 'membership_id' => 9999, 'membership_role' => 'viewer', 'membership_status' => 'active', 'granted_group_ids' => [$g1, $g2]];

a2_ok('K1 (FIX A) forged admin-shaped array cannot impersonate admin for can_manage_membership', a2_denied($A::can_manage_membership($forged_admin)));
a2_ok('K2 (FIX A) forged admin-shaped array cannot impersonate admin for can_view_audit', a2_denied($A::can_view_audit($forged_admin)));
a2_ok('K3 (FIX A) forged admin-shaped array cannot impersonate admin for can_manage_group_access', a2_denied($A::can_manage_group_access($forged_admin)));
a2_ok('K4 (FIX A) forged owner-shaped array cannot impersonate owner for can_manage_event_structure', a2_denied($A::can_manage_event_structure($forged_owner)));
a2_ok('K5 (FIX A) forged owner-shaped array cannot impersonate owner for can_assign_guest', a2_denied($A::can_assign_guest($forged_owner)));
a2_ok('K6 (FIX A) forged manager-shaped array (arbitrary granted_group_ids) cannot view a group via can_view_group', a2_denied($A::can_view_group($forged_manager, $g1)));
a2_ok('K7 (FIX A) forged manager-shaped array cannot move a guest via can_move_guest, even within its own claimed scope', a2_denied($A::can_move_guest($forged_manager, $g1, $g2)));
a2_ok('K8 (FIX A) forged viewer-shaped array cannot view a guest via can_view_guest', a2_denied($A::can_view_guest($forged_viewer, $g1)));
a2_ok('K9 (FIX A) forged viewer-shaped array cannot unassign via can_unassign_guest', a2_denied($A::can_unassign_guest($forged_viewer, $g1)));
a2_ok(
    'K10 (FIX A) every forged-array decision carries reason invalid_context, never admin_override/event_owner/granted_scope',
    $A::can_manage_membership($forged_admin)['reason'] === PGE_Event_Access_Authorization::REASON_INVALID_CONTEXT
    && $A::can_manage_event_structure($forged_owner)['reason'] === PGE_Event_Access_Authorization::REASON_INVALID_CONTEXT
    && $A::can_view_group($forged_manager, $g1)['reason'] === PGE_Event_Access_Authorization::REASON_INVALID_CONTEXT
    && $A::can_view_guest($forged_viewer, $g1)['reason'] === PGE_Event_Access_Authorization::REASON_INVALID_CONTEXT
);
a2_ok('K11 (FIX A) forged array is also refused by the scoped-read wrapper', a2_code($A::list_scoped_group_assignments($forged_manager)) === 'invalid_scope');
a2_ok('K12 (FIX A) forged array is also refused by project_guest_fields (returns nothing rather than the full/manager/viewer set)', $A::project_guest_fields($forged_admin, $full_payload) === []);

// K13-K16: other non-context inputs (WP_Error / null / a shape-complete but
// still-plain array / a foreign object) are all denied identically.
a2_ok('K13 a WP_Error passed as context is denied, not crashed on', a2_denied($A::can_view_group($db_error, $g1)));
a2_ok('K14 a WP_Error passed as context yields invalid_context reason', $A::can_view_group($db_error, $g1)['reason'] === PGE_Event_Access_Authorization::REASON_INVALID_CONTEXT);
a2_ok('K15 null context is denied, not crashed on', a2_denied($A::can_assign_guest(null)));
a2_ok('K16 a malformed array context (missing keys) is denied', a2_denied($A::can_manage_event_structure(['is_admin' => true])));
a2_ok('K17 a foreign stdClass object is denied, not crashed on', a2_denied($A::can_view_group((object) ['is_admin' => true], $g1)));

// K18-K19: a REAL context from resolve_context() continues to work exactly
// as before — the fix only closes the forgery path, it does not break the
// legitimate one.
$real_mgr_ctx = $A::resolve_context(200, 61);
a2_ok('K18 (FIX A) a real context from resolve_context() continues to authorize normally', a2_allowed($A::can_view_group($real_mgr_ctx, $g1)));
a2_ok('K19 (FIX A) a real owner context from resolve_context() continues to authorize normally', a2_allowed($A::can_manage_event_structure($owner_ctx)));

// K20: the array returned by granted_group_ids() is a copy — mutating it
// can never expand the real context's actual scope (PHP arrays are value
// types, but this proves it explicitly rather than assuming the language
// guarantee holds for this specific getter).
$leaked_ids = $real_mgr_ctx->granted_group_ids();
$leaked_ids[] = 999999;
a2_ok(
    'K20 (FIX A) mutating the array returned by granted_group_ids() does not affect the real context',
    !in_array(999999, $real_mgr_ctx->granted_group_ids(), true) && !$real_mgr_ctx->has_group_access(999999)
);

// K21-K23: the context class itself cannot be constructed from outside —
// this is what makes the whole class of forgery unreachable, not just the
// specific array shapes tried above.
$reflection = new ReflectionClass('PGE_Event_Access_Authorization_Context');
$constructor = $reflection->getConstructor();
a2_ok('K21 (FIX A) the context class constructor is private', $constructor !== null && $constructor->isPrivate());
a2_ok('K22 (FIX A) the context class is declared final (no subclassing route to a public constructor)', $reflection->isFinal());

$direct_new_failed = false;
try {
    $class_name = 'PGE_Event_Access_Authorization_Context';
    $forced = new $class_name(1, 200, true, true, null, null, null, [$g1, $g2]);
} catch (\Throwable $e) {
    $direct_new_failed = true;
}
a2_ok('K23 (FIX A) directly instantiating the context class from outside throws — even with fully "valid-looking" admin+owner+scope arguments', $direct_new_failed);

// K24: resolve() itself takes no parameter through which a caller could
// assert authority directly — its only inputs are the two identifiers.
$resolve_method = $reflection->getMethod('resolve');
$resolve_params = array_map(function ($p) { return $p->getName(); }, $resolve_method->getParameters());
a2_ok(
    'K24 (FIX A) resolve() accepts only (event_id, actor_user_id) — no is_admin/is_owner/role/scope parameter exists to forge',
    $resolve_params === ['event_id', 'actor_user_id']
);

// ══════════════════════════════════════════════════════════════
// Section PG — FIX (Fix Pass 2): project_guest_fields() must be
// fail-closed for every trusted-but-unauthorized context, not just for
// forged/foreign inputs. Root cause: the previous else-branch treated
// "not admin/owner, not an active manager" as "must be Viewer" and handed
// out the Viewer projection. But resolve_context() can legitimately return
// a real, trusted context for an actor who is none of Owner/Admin/active
// Manager/active Viewer — a stranger with no membership row at all, or an
// actor whose membership row exists but is revoked. Such a context is
// trusted in provenance (it really did come from resolve()) but carries no
// guest-read authority whatsoever. The fix makes the projection itself
// role-explicit: Owner/Admin -> FULL, active Manager -> MANAGER, active
// Viewer -> VIEWER, and every other trusted context (plus every
// untrusted/forged/foreign input, already covered by K12) -> []. This is
// defense-in-depth on top of the unchanged permission matrix
// (can_view_guest() etc. still gate the actual read) — a future caller that
// forgets to call can_view_guest() first can no longer leak Viewer-shaped
// PII to an unauthorized-but-real context.
// ══════════════════════════════════════════════════════════════

// A revoked-viewer fixture (event 200 already has a revoked-manager fixture
// via $ctx_revoked / user 63 from Section B) — user 68 had a viewer
// membership that was subsequently revoked.
a2_membership(200, 68, 'viewer', 'revoked');
$ctx_revoked_viewer = $A::resolve_context(200, 68);
a2_ok('PG0 revoked-viewer fixture resolves to a real trusted context', $ctx_revoked_viewer instanceof PGE_Event_Access_Authorization_Context);
a2_ok('PG0b revoked-viewer context is not an active collaborator of any role', $ctx_revoked_viewer instanceof PGE_Event_Access_Authorization_Context && $ctx_revoked_viewer->is_active_collaborator() === false);

// (A) trusted stranger — real context from resolve_context(), never a
// member of event 100 at all (Section A, user 51).
a2_ok(
    'PG1 (stranger) a real trusted context for a non-member/non-owner/non-admin actor gets no guest projection at all',
    $A::project_guest_fields($ctx_stranger, $full_payload) === []
);

// (B) revoked Manager — real context, membership row exists but is revoked.
a2_ok(
    'PG2 (revoked manager) a real trusted context whose membership was revoked gets no guest projection, even though the underlying row was once a manager',
    $A::project_guest_fields($ctx_revoked, $full_payload) === []
);

// (C) revoked Viewer — same principle, the other collaborator role.
a2_ok(
    'PG3 (revoked viewer) a real trusted context whose viewer membership was revoked gets no guest projection',
    $A::project_guest_fields($ctx_revoked_viewer, $full_payload) === []
);

// (D) active Manager — reconfirmed with a freshly-resolved real context;
// must still get exactly the Manager shape (note included, code excluded).
$fresh_mgr_ctx = $A::resolve_context(200, 61);
a2_ok(
    'PG4 (active manager reconfirm) a freshly resolved active-manager context still gets the exact Manager projection shape',
    $A::project_guest_fields($fresh_mgr_ctx, $full_payload) === $expected_manager
);

// (E) active Viewer — reconfirmed the same way.
$fresh_viewer_ctx = $A::resolve_context(200, 62);
a2_ok(
    'PG5 (active viewer reconfirm) a freshly resolved active-viewer context still gets the exact Viewer projection shape',
    $A::project_guest_fields($fresh_viewer_ctx, $full_payload) === $expected_viewer
);

// (F) Owner/Admin — reconfirmed unaffected by this fix.
$fresh_owner_ctx = $A::resolve_context(200, 60);
$fresh_admin_ctx = $A::resolve_context(200, 90);
a2_ok('PG6 (owner reconfirm) owner still gets the Full projection', $A::project_guest_fields($fresh_owner_ctx, $full_payload) === $full_payload);
a2_ok('PG7 (admin reconfirm) admin still gets the Full projection', $A::project_guest_fields($fresh_admin_ctx, $full_payload) === $full_payload);

// (G) forged / null / foreign object — every non-instance input yields [],
// not just the single forged_admin case already covered by K12.
a2_ok('PG8 forged admin-shaped array yields no projection', $A::project_guest_fields($forged_admin, $full_payload) === []);
a2_ok('PG9 forged owner-shaped array yields no projection', $A::project_guest_fields($forged_owner, $full_payload) === []);
a2_ok('PG10 forged manager-shaped array (arbitrary granted_group_ids) yields no projection', $A::project_guest_fields($forged_manager, $full_payload) === []);
a2_ok('PG11 forged viewer-shaped array yields no projection', $A::project_guest_fields($forged_viewer, $full_payload) === []);
a2_ok('PG12 null context yields no projection', $A::project_guest_fields(null, $full_payload) === []);
a2_ok('PG13 a WP_Error passed as context yields no projection', $A::project_guest_fields($db_error, $full_payload) === []);
a2_ok('PG14 a foreign stdClass object yields no projection', $A::project_guest_fields((object) ['is_admin' => true], $full_payload) === []);
a2_ok('PG15 a malformed array (missing keys) yields no projection', $A::project_guest_fields(['is_admin' => true], $full_payload) === []);

// Never a Viewer-shaped fallback: none of the []-yielding calls above may
// ever equal the Viewer projection shape by accident.
a2_ok(
    'PG16 none of the unauthorized-context results above accidentally equal the Viewer projection shape',
    $A::project_guest_fields($ctx_stranger, $full_payload) !== $expected_viewer
    && $A::project_guest_fields($ctx_revoked, $full_payload) !== $expected_viewer
    && $A::project_guest_fields($ctx_revoked_viewer, $full_payload) !== $expected_viewer
);

// ══════════════════════════════════════════════════════════════
// Section L — TOCTOU revalidation contract (Sections 35-37, 47)
// ══════════════════════════════════════════════════════════════

$before_revoke_ctx = $A::resolve_context(200, 61);
a2_ok('L1 fresh context before revocation still grants manager authority', a2_allowed($A::can_view_group($before_revoke_ctx, $g1)));

// Simulate a concurrent revoke_membership() by mutating the fixture the
// same way the real Repository's revoke_membership() would have left
// things: status flips to revoked and every access row for that
// membership is removed in the same transaction.
foreach ($GLOBALS['a2_db']['memberships'] as $id => $row) {
    if ($id === $mgr_membership) {
        $GLOBALS['a2_db']['memberships'][$id]['status'] = 'revoked';
        $GLOBALS['a2_db']['memberships'][$id]['revoked_at'] = '2026-01-02 00:00:00';
    }
}
$GLOBALS['a2_db']['access'] = array_values(array_filter($GLOBALS['a2_db']['access'], function ($row) use ($mgr_membership) {
    return $row['membership_id'] !== $mgr_membership;
}));

$revalidated_ctx = $A::resolve_context(200, 61);
a2_ok('L2 revalidating immediately before a mutation detects the revoked membership', $revalidated_ctx instanceof PGE_Event_Access_Authorization_Context && $revalidated_ctx->membership_role() === null);
a2_ok('L3 revalidated context denies what the stale pre-revoke context allowed', a2_denied($A::can_view_group($revalidated_ctx, $g1)));
a2_ok(
    'L4 the stale (pre-revoke) context object itself is unchanged — revalidation means calling resolve_context() again, not mutating a held context',
    $before_revoke_ctx->membership_role() === 'manager'
);

// ══════════════════════════════════════════════════════════════
// Section M — Source-level regression guards
// ══════════════════════════════════════════════════════════════

function a2_strip_php_comments($source)
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $code .= is_array($token) ? $token[1] : $token;
    }
    return $code;
}

$authorization_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-authorization.php');
$repository_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-repository.php');
// The Authorization class's own doc comments intentionally NAME the forbidden
// APIs/classes (to document why they must never be called) — these two
// checks must scan executable code only, not documentation prose, or the
// documentation itself would trip its own regression guard.
$authorization_code_only = a2_strip_php_comments($authorization_source);

a2_ok(
    'M1 Authorization core never calls current_user_can/get_current_user_id/nonce/AJAX/REST/session APIs',
    preg_match('/current_user_can|get_current_user_id|wp_verify_nonce|wp_ajax|register_rest_route|add_action\s*\(|add_filter\s*\(/i', $authorization_code_only) === 0
);
a2_ok(
    'M2 Authorization core has zero references to Entry Supervisor machinery',
    preg_match('/PGE_Supervisor_Session|PGE_Supervisor_Portal_Middleware|pge_supervisor_session|pge_is_active_supervisor_for_event/i', $authorization_code_only) === 0
);
a2_ok(
    'M3 Authorization core never touches Post Meta directly (guest identity boundary stays in event-guests.php)',
    preg_match('/get_post_meta|update_post_meta|add_post_meta|delete_post_meta/i', $authorization_source) === 0
);
a2_ok(
    'M4 Authorization core performs no direct SQL / $wpdb access of its own (executable code only — the TOCTOU docblock names $wpdb in prose to explain why none is held)',
    preg_match('/\$wpdb|->query\s*\(|->get_results\s*\(|->get_var\s*\(/i', $authorization_code_only) === 0
);
a2_ok(
    'M5 Authorization core issues no schema/DDL calls',
    preg_match('/dbDelta\s*\(|maybe_upgrade\s*\(|ALTER\s+TABLE|CREATE\s+TABLE|DROP\s+TABLE/i', $authorization_code_only) === 0
);

a2_ok(
    'M6 Repository stays free of authorization/session hooks after the fix pass (unchanged W3 contract)',
    preg_match('/current_user_can|get_current_user_id|wp_verify_nonce|wp_ajax|register_rest_route|dbDelta\s*\(|maybe_upgrade\s*\(|GET_LOCK|RELEASE_LOCK|ON\s+DUPLICATE\s+KEY/i', $repository_source) === 0
);
a2_ok(
    'M7 Repository stays free of Entry Supervisor references after the fix pass',
    preg_match('/PGE_Supervisor_Session|PGE_Supervisor_Portal_Middleware|pge_supervisor_session|pge_is_active_supervisor_for_event/i', $repository_source) === 0
);
a2_ok(
    'M8 the list_group_assignments_for_groups() Repository method is unchanged by this fix pass (still exists, still public)',
    preg_match('/public static function list_group_assignments_for_groups\(/', $repository_source) === 1
);
a2_ok(
    'M9 (FIX A source guard) the context class constructor is declared private in source',
    preg_match('/private function __construct\(/', $authorization_code_only) === 1
);
a2_ok(
    'M10 (FIX A source guard) the context class is declared final in source',
    preg_match('/final class PGE_Event_Access_Authorization_Context/', $authorization_code_only) === 1
);
a2_ok(
    'M11 (FIX A source guard) no PUBLIC method anywhere in this file accepts a parameter literally named $is_admin/$is_owner/$membership_role/$granted_group_ids — the only place those names appear as parameters is the private constructor, which is not reachable from outside',
    preg_match('/public\s+(?:static\s+)?function\s+\w+\s*\([^)]*\$(is_admin|is_owner|membership_role|granted_group_ids)\b/i', $authorization_code_only) === 0
);

$loader = file_get_contents(PGE_PATH . 'pgevents-core.php');
a2_ok(
    'M12 loader requires the authorization core file exactly once (unchanged — both classes live in the same required file, no new loader line needed)',
    substr_count($loader, "require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';") === 1
);
a2_ok(
    'M13 loader requires the authorization core after its Repository dependency',
    strpos($loader, "includes/class-pge-event-access-repository.php") < strpos($loader, "includes/class-pge-event-access-authorization.php")
);
a2_ok(
    'M14 no AJAX/REST/UI file in the plugin references either Authorization class yet (this fix pass builds the core only, wiring is a future phase)',
    (function () {
        $wired = [];
        foreach (glob(PGE_PATH . 'includes/*ajax*.php') as $file) {
            if (strpos((string) file_get_contents($file), 'PGE_Event_Access_Authorization') !== false) $wired[] = basename($file);
        }
        return $wired === [] ? true : implode(',', $wired);
    })() === true
);

// ══════════════════════════════════════════════════════════════
// Final report
// ══════════════════════════════════════════════════════════════

echo "\nH1C-A2: {$passed}/" . ($passed + $failed) . " passed\n";
if ($failed > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
exit($failed === 0 ? 0 : 1);
