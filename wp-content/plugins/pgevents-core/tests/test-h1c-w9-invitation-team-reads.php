<?php
/**
 * Phase H1C-W9 — Invitation Team Read & Discoverability Backend.
 *
 * Executes the REAL production classes (PGE_Event_Access_Repository,
 * PGE_Event_Access_Authorization[_Context], PGE_Additional_Inviter, and
 * the two new AJAX handlers in additional-inviter-ajax.php) against a
 * fake $wpdb — no logical mirror of any of this phase's own new code.
 *
 * Scope discipline: this suite does NOT re-test quota arithmetic (W8's
 * job, already proven 82/82) — every fixture leaves group assignments
 * empty, so occupied/attending/pending/declined/cancelled are trivially
 * 0 in every row here; the suite asserts only on the NEW logic this phase
 * adds (authorization/EC1, row shape/privacy, misconfiguration handling,
 * self-scoping, AJAX transport boundary).
 *
 * Run: php tests/test-h1c-w9-invitation-team-reads.php
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

final class WP_Error
{
    private $c; private $m;
    function __construct($c = '', $m = '') { $this->c = $c; $this->m = $m; }
    function get_error_code() { return $this->c; }
    function get_error_message() { return $this->m; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function code($v) { return $v instanceof WP_Error ? $v->get_error_code() : null; }

class WP_User
{
    public $ID;
    public $display_name;
    function __construct($id, $display_name) { $this->ID = $id; $this->display_name = $display_name; }
}

// ── WordPress primitive mocks ──────────────────────────────────────────
$GLOBALS['w9_posts'] = [];       // event_id => ['type'=>'pge_event','author'=>id,'title'=>string]
$GLOBALS['w9_admins'] = [];      // user_id => true
$GLOBALS['w9_users'] = [];       // user_id => display_name (string) — absent/false = deleted/missing user
$GLOBALS['w9_ready'] = true;
$GLOBALS['w9_current_user'] = 0;
$GLOBALS['w9_logged_in'] = true;
$GLOBALS['w9_nonce_valid'] = true;
$GLOBALS['w9_actions'] = [];     // hook => [callback, ...]

function get_post_type($id) { return $GLOBALS['w9_posts'][$id]['type'] ?? false; }
function get_post_field($field, $id) { return $field === 'post_author' ? ($GLOBALS['w9_posts'][$id]['author'] ?? 0) : null; }
function get_post($id) {
    if (!isset($GLOBALS['w9_posts'][$id])) return null;
    $p = $GLOBALS['w9_posts'][$id];
    return (object) ['ID' => $id, 'post_type' => $p['type'], 'post_author' => $p['author'], 'post_title' => $p['title'] ?? ''];
}
function user_can($user_id, $cap) { return $cap === 'administrator' && !empty($GLOBALS['w9_admins'][$user_id]); }
function get_userdata($id) {
    if (!array_key_exists($id, $GLOBALS['w9_users']) || $GLOBALS['w9_users'][$id] === false) return false;
    return new WP_User($id, $GLOBALS['w9_users'][$id]);
}
function is_user_logged_in() { return (bool) $GLOBALS['w9_logged_in']; }
function get_current_user_id() { return (int) $GLOBALS['w9_current_user']; }
function wp_verify_nonce($nonce, $action) { return $GLOBALS['w9_nonce_valid'] ? 1 : false; }
function sanitize_text_field($v) { return trim((string) $v); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function wp_unslash($v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
if (!function_exists('add_action')) {
    function add_action($hook, $cb, ...$rest) { $GLOBALS['w9_actions'][$hook][] = $cb; return true; }
}
if (!function_exists('register_activation_hook')) { function register_activation_hook(...$a) { return true; } }
if (!function_exists('register_deactivation_hook')) { function register_deactivation_hook(...$a) { return true; } }
if (!function_exists('add_filter')) { function add_filter(...$a) { return true; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }
if (!function_exists('do_action')) { function do_action(...$a) { return null; } }

class PGE_W9_JsonSignal extends Exception
{
    public $payload;
    function __construct($payload) { parent::__construct('json_signal'); $this->payload = $payload; }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($d = null) { throw new PGE_W9_JsonSignal(['success' => false, 'data' => $d]); }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($d = null) { throw new PGE_W9_JsonSignal(['success' => true, 'data' => $d]); }
}

final class PGE_Event_Access_Schema { static function is_ready() { return $GLOBALS['w9_ready']; } static function maybe_upgrade() { return false; } }

// ── Fake $wpdb: routes by table substring, same convention as the W8
//    repository test harness (tests/test-h1c-w8-additional-inviter.php).
//    Fixtures are populated directly (no INSERT round-trip needed — this
//    suite performs no writes at all). ──────────────────────────────────
final class PGE_W9_WPDB
{
    public $prefix = 'w9_';
    public $last_error = '';
    public $insert_id = 0;
    public $memberships = [];
    public $groups = [];
    public $access = [];
    public $assignments = [];
    private $prepared = [];
    private $seq = 0;

    function add_membership($id, $event_id, $user_id, $role, $status, $quota) {
        $this->memberships[$id] = [
            'id' => (string) $id, 'event_id' => (string) $event_id, 'user_id' => (string) $user_id,
            'role' => $role, 'status' => $status,
            'allocated_quota' => $quota === null ? null : (string) $quota,
            'created_by_user_id' => '501', 'activated_at' => '2026-08-01 10:00:00',
            'revoked_at' => $status === 'revoked' ? '2026-08-05 10:00:00' : null,
            'created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00',
        ];
    }
    function add_group($id, $event_id, $name) {
        $this->groups[$id] = [
            'id' => (string) $id, 'event_id' => (string) $event_id, 'name' => $name,
            'name_key' => strtolower($name), 'status' => 'active', 'default_slot' => null,
            'created_by_user_id' => '501', 'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00', 'archived_at' => null,
        ];
    }
    function add_access($id, $event_id, $membership_id, $group_id) {
        $this->access[$id] = [
            'id' => (string) $id, 'event_id' => (string) $event_id, 'membership_id' => (string) $membership_id,
            'group_id' => (string) $group_id, 'granted_by_user_id' => '501', 'created_at' => '2026-08-01 10:00:00',
        ];
    }

    function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $t = '__W9_' . (++$this->seq) . '__';
        $this->prepared[$t] = [$sql, $args];
        return $t;
    }
    private function resolve($q) { return $this->prepared[$q] ?? [$q, []]; }

    function query($q) { [$s] = $this->resolve($q); $this->last_error = ''; return 0; }

    function get_results($q, $fmt = null) {
        [$s, $a] = $this->resolve($q);
        $this->last_error = '';

        // H1C-W9 predicate queries — routed BEFORE the generic single-table
        // memberships branch below, whose substring check would otherwise
        // also match these (and whose 'user_id = %d' detection would
        // misread the self-list variant's bind args).
        if (strpos($s, '_pge_event_host_memberships') !== false && strpos($s, 'allocated_quota IS NOT NULL') !== false) {
            $by_user = strpos($s, 'WHERE user_id = %d') !== false;
            $key_val = (int) $a[0];
            $rows = array_values(array_filter($this->memberships, function ($r) use ($by_user, $key_val) {
                if ($by_user) { if ((int) $r['user_id'] !== $key_val) return false; }
                else { if ((int) $r['event_id'] !== $key_val) return false; }
                return $r['status'] === 'active' && $r['role'] === 'manager' && $r['allocated_quota'] !== null;
            }));
            if ($by_user) {
                usort($rows, fn($x, $y) => ((int) $x['event_id'] <=> (int) $y['event_id']) ?: ((int) $x['id'] <=> (int) $y['id']));
            } else {
                usort($rows, fn($x, $y) => (int) $x['id'] <=> (int) $y['id']);
            }
            if (strpos($s, 'OFFSET %d') !== false && count($a) >= 5) {
                $per_page = (int) $a[count($a) - 2];
                $offset = (int) $a[count($a) - 1];
                $rows = array_slice($rows, $offset, $per_page);
            }
            return $rows;
        }

        if (strpos($s, 'FROM w9_pge_event_host_group_access a LEFT JOIN w9_pge_event_invitation_groups') !== false) {
            // list_group_ids_for_membership: access a JOIN groups g
            $event_id = $a[0] ?? null; $membership_id = $a[1] ?? null;
            $rows = array_values(array_filter($this->access, fn($r) => (int) $r['event_id'] === (int) $event_id && (int) $r['membership_id'] === (int) $membership_id));
            usort($rows, fn($x, $y) => (int) $x['group_id'] <=> (int) $y['group_id']);
            $out = [];
            foreach ($rows as $r) {
                $group = $this->groups[(int) $r['group_id']] ?? null;
                $out[] = ['event_id' => $r['event_id'], 'group_id' => $r['group_id'], 'related_event_id' => $group['event_id'] ?? null];
            }
            return $out;
        }
        if (strpos($s, 'FROM w9_pge_event_host_group_access a LEFT JOIN w9_pge_event_host_memberships') !== false) {
            // list_membership_ids_for_group: access a JOIN memberships m
            $event_id = $a[0] ?? null; $group_id = $a[1] ?? null;
            $rows = array_values(array_filter($this->access, fn($r) => (int) $r['event_id'] === (int) $event_id && (int) $r['group_id'] === (int) $group_id));
            usort($rows, fn($x, $y) => (int) $x['membership_id'] <=> (int) $y['membership_id']);
            $out = [];
            foreach ($rows as $r) {
                $member = $this->memberships[(int) $r['membership_id']] ?? null;
                $out[] = ['event_id' => $r['event_id'], 'membership_id' => $r['membership_id'], 'related_event_id' => $member['event_id'] ?? null];
            }
            return $out;
        }
        if (strpos($s, 'FROM w9_pge_invitation_group_assignments a LEFT JOIN w9_pge_event_invitation_groups') !== false) {
            // list_group_assignments: assignments a JOIN groups g — every W9
            // fixture leaves $this->assignments empty (see file docblock),
            // so this always returns [] here; kept for structural parity
            // with the Repository's real query shape.
            $event_id = $a[0] ?? null;
            $rows = array_values(array_filter($this->assignments, fn($r) => (int) $r['event_id'] === (int) $event_id));
            if (isset($a[1]) && strpos($s, 'group_id = %d') !== false) {
                $rows = array_values(array_filter($rows, fn($r) => (int) $r['group_id'] === (int) $a[1]));
            }
            usort($rows, fn($x, $y) => (int) $x['id'] <=> (int) $y['id']);
            if (strpos($s, 'OFFSET %d') !== false && count($a) >= 2) {
                $per_page = (int) $a[count($a) - 2];
                $offset = (int) $a[count($a) - 1];
                $rows = array_slice($rows, $offset, $per_page);
            }
            $out = [];
            foreach ($rows as $r) {
                $group = $this->groups[(int) $r['group_id']] ?? null;
                $out[] = $r + ['related_event_id' => $group['event_id'] ?? null];
            }
            return $out;
        }
        if (strpos($s, 'w9_pge_event_invitation_groups') !== false) {
            $rows = array_values(array_filter($this->groups, fn($r) => (int) $r['event_id'] === $a[0]));
            if (isset($a[1]) && strpos($s, 'id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $a[1]));
            return $rows;
        }
        if (strpos($s, 'w9_pge_event_host_memberships') !== false) {
            $rows = array_values(array_filter($this->memberships, fn($r) => (int) $r['event_id'] === $a[0]));
            if (isset($a[1]) && strpos($s, 'user_id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['user_id'] === $a[1]));
            elseif (isset($a[1]) && strpos($s, 'id = %d') !== false) $rows = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $a[1]));
            usort($rows, fn($x, $y) => (int) $x['id'] <=> (int) $y['id']);
            return $rows;
        }
        $this->last_error = 'unexpected read: ' . $s;
        return null;
    }

    function get_var($q) {
        [$s, $a] = $this->resolve($q);
        $this->last_error = '';
        if (strpos($s, 'COUNT(*)') !== false && strpos($s, '_pge_event_host_memberships') !== false && strpos($s, 'allocated_quota IS NOT NULL') !== false) {
            $by_user = strpos($s, 'WHERE user_id = %d') !== false;
            $key_val = (int) $a[0];
            $rows = array_values(array_filter($this->memberships, function ($r) use ($by_user, $key_val) {
                if ($by_user) { if ((int) $r['user_id'] !== $key_val) return false; }
                else { if ((int) $r['event_id'] !== $key_val) return false; }
                return $r['status'] === 'active' && $r['role'] === 'manager' && $r['allocated_quota'] !== null;
            }));
            return count($rows);
        }
        if (strpos($s, 'COUNT(*)') !== false && strpos($s, 'w9_pge_invitation_group_assignments') !== false) {
            $rows = array_values(array_filter($this->assignments, fn($r) => (int) $r['event_id'] === $a[0] && (int) $r['group_id'] === $a[1]));
            return count($rows);
        }
        return null;
    }
}

require_once PGE_PATH . 'includes/class-pge-event-access-repository.php';
require_once PGE_PATH . 'includes/class-pge-event-access-authorization.php';
require_once PGE_PATH . 'includes/class-pge-additional-inviter.php';
require_once PGE_PATH . 'includes/event-access-ajax.php';
require_once PGE_PATH . 'includes/additional-inviter-ajax.php';

global $wpdb;
$wpdb = new PGE_W9_WPDB();

$results = [];
function check($label, $cond) {
    global $results;
    $results[] = [$label, (bool) $cond];
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
}

function reset_fixtures() {
    global $wpdb;
    $wpdb = new PGE_W9_WPDB();
    $GLOBALS['w9_posts'] = [];
    $GLOBALS['w9_admins'] = [];
    $GLOBALS['w9_users'] = [];
    $GLOBALS['w9_ready'] = true;
    $GLOBALS['w9_current_user'] = 0;
    $GLOBALS['w9_logged_in'] = true;
    $GLOBALS['w9_nonce_valid'] = true;
}

// ============================================================================
// Section A — Owner list: authorization matrix + EC1 indistinguishability +
// correct filtering to active-quota-managers only.
// ============================================================================
reset_fixtures();
$GLOBALS['w9_posts'][10] = ['type' => 'pge_event', 'author' => 501, 'title' => 'حفل زفاف'];
$GLOBALS['w9_admins'][900] = true;
$GLOBALS['w9_users'] = [501 => 'المالك', 502 => 'أحمد', 503 => 'سالم', 504 => 'خالد', 505 => 'وليد'];
$wpdb->add_membership(1, 10, 502, 'manager', 'active', 50);   // qualifies
$wpdb->add_group(1, 10, 'مجموعة أ');
$wpdb->add_access(1, 10, 1, 1);
$wpdb->add_membership(2, 10, 503, 'manager', 'active', null); // generic manager, quota=null — excluded
$wpdb->add_membership(3, 10, 504, 'viewer', 'active', null);  // viewer — excluded
$wpdb->add_membership(4, 10, 505, 'manager', 'revoked', 30);  // revoked quota-configured — excluded (Section 7)
$wpdb->add_group(2, 10, 'مجموعة ب');
$wpdb->add_access(4, 10, 4, 2);

$owner_result = PGE_Additional_Inviter::list_additional_inviters_for_owner(10, 501, 1, 20);
check('A1. Owner sees exactly 1 item (the single active quota-enabled manager)', is_array($owner_result) && count($owner_result['items']) === 1);
check('A1b. Owner list excludes generic manager / viewer / revoked quota-configured rows', is_array($owner_result) && $owner_result['total'] === 1);

$admin_result = PGE_Additional_Inviter::list_additional_inviters_for_owner(10, 900, 1, 20);
check('A2. Admin (non-owner) can list', is_array($admin_result) && count($admin_result['items']) === 1);

$manager_denied = PGE_Additional_Inviter::list_additional_inviters_for_owner(10, 502, 1, 20);
check('A3. A plain active Manager is denied', code($manager_denied) === 'not_authorized');

$viewer_denied = PGE_Additional_Inviter::list_additional_inviters_for_owner(10, 504, 1, 20);
check('A4. A plain active Viewer is denied', code($viewer_denied) === 'not_authorized');

$stranger_denied = PGE_Additional_Inviter::list_additional_inviters_for_owner(10, 999, 1, 20);
check('A5. A complete stranger is denied', code($stranger_denied) === 'not_authorized');

$nonexistent_denied = PGE_Additional_Inviter::list_additional_inviters_for_owner(777, 999, 1, 20);
check('A6. A nonexistent event yields the SAME error code as a real-but-unauthorized one (EC1 indistinguishability)', code($nonexistent_denied) === code($stranger_denied));

// ============================================================================
// Section B — Row shape completeness; no sensitive WP fields.
// ============================================================================
$owner_item = $owner_result['items'][0];
$expected_owner_keys = ['membership_id', 'user_id', 'display_name', 'group_id', 'group_name', 'role', 'status', 'allocated_quota', 'occupied', 'available', 'attending', 'pending', 'declined', 'cancelled'];
check('B1. Owner-list item has exactly the specified key set — no extra/sensitive fields', array_keys($owner_item) === $expected_owner_keys);
check('B1b. Owner-list item never carries password/capabilities/user_meta-shaped keys', !array_key_exists('password', $owner_item) && !array_key_exists('user_pass', $owner_item) && !array_key_exists('capabilities', $owner_item));

// ============================================================================
// Section C — Deleted/missing WP user doesn't crash the list.
// ============================================================================
reset_fixtures();
$GLOBALS['w9_posts'][10] = ['type' => 'pge_event', 'author' => 501, 'title' => 'حفل'];
$GLOBALS['w9_users'] = [501 => 'المالك']; // 506 deliberately absent — "deleted" user
$wpdb->add_membership(1, 10, 506, 'manager', 'active', 40);
$wpdb->add_group(1, 10, 'مجموعة');
$wpdb->add_access(1, 10, 1, 1);

$deleted_user_result = PGE_Additional_Inviter::list_additional_inviters_for_owner(10, 501, 1, 20);
check('C1. A row whose WP user no longer exists does not crash the list', is_array($deleted_user_result) && count($deleted_user_result['items']) === 1);
check('C2. That row falls back to the safe placeholder display name', is_array($deleted_user_result) && $deleted_user_result['items'][0]['display_name'] === 'مستخدم غير متاح');

// ============================================================================
// Section D — Misconfiguration handling (0 groups, >1 groups, duplicate
// active-quota-inviter corruption) — consistent with resolve_quota_status()'s
// existing safe (skip, don't crash) handling.
// ============================================================================
reset_fixtures();
$GLOBALS['w9_posts'][10] = ['type' => 'pge_event', 'author' => 501, 'title' => 'حفل'];
$GLOBALS['w9_users'] = [501 => 'المالك', 502 => 'زيرو مجموعات', 503 => 'مجموعتان', 504 => 'سليم', 601 => 'أ', 602 => 'ب'];
$wpdb->add_membership(1, 10, 502, 'manager', 'active', 20); // 0 granted groups
$wpdb->add_membership(2, 10, 503, 'manager', 'active', 25); // 2 granted groups
$wpdb->add_group(1, 10, 'مجموعة 1');
$wpdb->add_group(2, 10, 'مجموعة 2');
$wpdb->add_access(1, 10, 2, 1);
$wpdb->add_access(2, 10, 2, 2);
$wpdb->add_membership(3, 10, 504, 'manager', 'active', 15); // healthy control row
$wpdb->add_group(3, 10, 'مجموعة 3');
$wpdb->add_access(3, 10, 3, 3);
// duplicate-active-quota-inviter corruption: two active quota managers both on group 4
$wpdb->add_membership(4, 10, 601, 'manager', 'active', 10);
$wpdb->add_membership(5, 10, 602, 'manager', 'active', 10);
$wpdb->add_group(4, 10, 'مجموعة 4');
$wpdb->add_access(4, 10, 4, 4);
$wpdb->add_access(5, 10, 5, 4);

$misconfig_result = PGE_Additional_Inviter::list_additional_inviters_for_owner(10, 501, 1, 20);
$misconfig_ids = is_array($misconfig_result) ? array_column($misconfig_result['items'], 'membership_id') : [];
check('D1. A quota-configured manager with 0 granted groups is silently skipped', !in_array(1, $misconfig_ids, true));
check('D2. A quota-configured manager with 2 granted groups is silently skipped', !in_array(2, $misconfig_ids, true));
check('D3. Duplicate-active-quota-inviter corruption on one group: BOTH conflicting rows are skipped', !in_array(4, $misconfig_ids, true) && !in_array(5, $misconfig_ids, true));
check('D4. A healthy row elsewhere on the same page is unaffected by the other rows\' misconfiguration', in_array(3, $misconfig_ids, true));
check('D5. The list itself never fails/crashes despite 4 of 5 candidate rows being misconfigured', is_array($misconfig_result));

// ============================================================================
// Section E — Self list: correct self-scoping, no spoofing, empty state.
// ============================================================================
reset_fixtures();
$GLOBALS['w9_posts'][20] = ['type' => 'pge_event', 'author' => 700, 'title' => 'مناسبة أولى'];
$GLOBALS['w9_posts'][21] = ['type' => 'pge_event', 'author' => 701, 'title' => 'مناسبة ثانية'];
$GLOBALS['w9_posts'][22] = ['type' => 'pge_event', 'author' => 702, 'title' => 'مناسبة يملكها الفاعل نفسه'];
$GLOBALS['w9_users'] = [800 => 'الداعي الإضافي'];
// event 20: actor 800 is a real Additional Inviter
$wpdb->add_membership(1, 20, 800, 'manager', 'active', 30);
$wpdb->add_group(1, 20, 'مجموعة');
$wpdb->add_access(1, 20, 1, 1);
// event 21: actor 800 is a real Additional Inviter (second qualifying event)
$wpdb->add_membership(2, 21, 800, 'manager', 'active', 45);
$wpdb->add_group(2, 21, 'مجموعة');
$wpdb->add_access(2, 21, 2, 2);
// event 22: actor 800 is merely the Owner (post_author) — not a membership row at all
// (no membership fixture added for user 800 on event 22 — ownership alone must not appear)
// event 22 also carries actor 800's OWN revoked quota-configured membership,
// proving revoked rows are excluded even for an event the actor also owns.
$wpdb->add_membership(3, 22, 800, 'manager', 'revoked', 10);
$wpdb->add_group(3, 22, 'مجموعة');

$self_result_800 = PGE_Additional_Inviter::list_my_additional_inviter_events(800, 1, 20);
$self_event_ids_800 = is_array($self_result_800) ? array_column($self_result_800['items'], 'event_id') : [];
sort($self_event_ids_800);
check('E1. Actor sees exactly their own 2 qualifying events, across different events', $self_event_ids_800 === [20, 21]);
check('E2. Actor does NOT see an event they merely own (author) without a real membership row', count($self_event_ids_800) === 2);
check('E3. Actor does NOT see their own revoked quota-configured membership (event 22 excluded)', !in_array(22, $self_event_ids_800, true));

// E4: generic (quota=null) manager membership must not appear for a fresh actor
$GLOBALS['w9_posts'][23] = ['type' => 'pge_event', 'author' => 700, 'title' => 'مناسبة ثالثة'];
$wpdb->add_membership(10, 23, 801, 'manager', 'active', null);
$self_result_801 = PGE_Additional_Inviter::list_my_additional_inviter_events(801, 1, 20);
check('E4. A generic (quota=null) manager membership never appears in the self list', is_array($self_result_801) && count($self_result_801['items']) === 0);

// E5: no cross-user contamination / no spoofing via the actor parameter
$self_ids_check_800_again = array_column(PGE_Additional_Inviter::list_my_additional_inviter_events(800, 1, 20)['items'], 'event_id');
sort($self_ids_check_800_again);
$self_ids_801 = array_column(PGE_Additional_Inviter::list_my_additional_inviter_events(801, 1, 20)['items'], 'event_id');
check('E5. Two different actors get correctly isolated, non-contaminated result sets', $self_ids_check_800_again === [20, 21] && !in_array(23, $self_ids_check_800_again, true) && count($self_ids_801) === 0);

// E6: empty state for a real user with zero qualifying events is success, not an error
$self_result_empty = PGE_Additional_Inviter::list_my_additional_inviter_events(999999, 1, 20);
check('E6. A real user with zero qualifying events gets ok=true with an empty items list (not an error)', is_array($self_result_empty) && !($self_result_empty instanceof WP_Error) && $self_result_empty['items'] === [] && $self_result_empty['total'] === 0);

$self_item = $self_result_800['items'][0];
$expected_self_keys = ['event_id', 'event_title', 'group_id', 'group_name', 'allocated', 'occupied', 'available', 'attending', 'pending', 'declined', 'cancelled'];
check('B2. Self-list item has exactly the specified key set (no membership_id, no owner email/PII)', array_keys($self_item) === $expected_self_keys);

// ============================================================================
// Section F — AJAX transport-boundary checks.
// ============================================================================
check('F1. Exactly 2 W9 AJAX actions are registered', count($GLOBALS['w9_actions']['wp_ajax_pge_additional_inviter_list'] ?? []) === 1
    && count($GLOBALS['w9_actions']['wp_ajax_pge_additional_inviter_list_my_events'] ?? []) === 1);
check('F1b. No nopriv variant exists for either new action', !isset($GLOBALS['w9_actions']['wp_ajax_nopriv_pge_additional_inviter_list'])
    && !isset($GLOBALS['w9_actions']['wp_ajax_nopriv_pge_additional_inviter_list_my_events']));

reset_fixtures();
$GLOBALS['w9_posts'][10] = ['type' => 'pge_event', 'author' => 501, 'title' => 'حفل'];
$GLOBALS['w9_users'] = [501 => 'المالك', 502 => 'أحمد'];
$wpdb->add_membership(1, 10, 502, 'manager', 'active', 50);
$wpdb->add_group(1, 10, 'مجموعة');
$wpdb->add_access(1, 10, 1, 1);

$GLOBALS['w9_logged_in'] = false;
$_POST = ['event_id' => '10', 'nonce' => 'x'];
try {
    pge_additional_inviter_list_handler();
    check('F2. Owner-list handler requires login', false);
} catch (PGE_W9_JsonSignal $e) {
    check('F2. Owner-list handler requires login', $e->payload['data']['reason'] === 'not_logged_in');
}
$GLOBALS['w9_logged_in'] = true;

$GLOBALS['w9_current_user'] = 501;
$_POST = ['nonce' => 'x']; // event_id missing
try {
    pge_additional_inviter_list_handler();
    check('F3. Owner-list handler requires event_id', false);
} catch (PGE_W9_JsonSignal $e) {
    check('F3. Owner-list handler requires event_id', $e->payload['data']['reason'] === 'invalid_input');
}

// F4: a client-supplied actor/user identity field is never consulted — the
// actor is 502 (a plain Manager, would be denied) via the session, while a
// spoofed 'actor_user_id'/'user_id' field in $_POST claims the Owner (501).
$GLOBALS['w9_current_user'] = 502;
$_POST = ['event_id' => '10', 'nonce' => 'x', 'actor_user_id' => '501', 'user_id' => '501'];
try {
    pge_additional_inviter_list_handler();
    check('F4. A spoofed actor/user_id $_POST field is ignored — actor comes only from the session', false);
} catch (PGE_W9_JsonSignal $e) {
    check('F4. A spoofed actor/user_id $_POST field is ignored — actor comes only from the session', $e->payload['success'] === false && $e->payload['data']['reason'] === 'not_authorized');
}

// F5: the self-events handler takes no event_id and ignores one if supplied.
$GLOBALS['w9_current_user'] = 502;
$_POST = ['nonce' => 'x', 'event_id' => '999999']; // must be ignored entirely
try {
    pge_additional_inviter_list_my_events_handler();
    check('F5. Self-events handler never reached (unexpected)', false);
} catch (PGE_W9_JsonSignal $e) {
    check('F5. Self-events handler ignores any supplied event_id and still succeeds', $e->payload['success'] === true);
}

// F6: successful Owner-list call returns the expected payload shape.
$GLOBALS['w9_current_user'] = 501;
$_POST = ['event_id' => '10', 'nonce' => 'x'];
try {
    pge_additional_inviter_list_handler();
    check('F6. Successful Owner-list call reaches wp_send_json_success (unexpected non-throw)', false);
} catch (PGE_W9_JsonSignal $e) {
    $d = $e->payload['data'];
    check('F6. Successful Owner-list call returns items/page/per_page/total/total_pages', $e->payload['success'] === true
        && array_keys($d) === ['items', 'page', 'per_page', 'total', 'total_pages']
        && count($d['items']) === 1);
}

$_POST = [];

// ============================================================================
$fail = count(array_filter($results, fn($r) => !$r[1]));
echo "\n----------------------------------------\n";
echo 'H1C-W9: ' . (count($results) - $fail) . '/' . count($results) . " passed\n";
exit($fail ? 1 : 0);
