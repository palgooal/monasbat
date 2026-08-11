<?php
/**
 * Phase D1 — QR Continuity Across Invitation Lifecycles.
 *
 * Production Repository/Service/QR/Guest Resolution code runs against an
 * in-memory WordPress/$wpdb harness. No migration or real database write.
 *
 * Run: php tests/test-qr-lifecycle-continuity-phase-d1.php
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['d1_meta'] = [];
$GLOBALS['d1_posts'] = [];
$GLOBALS['d1_now'] = '2026-08-11 20:00:00';
$GLOBALS['d1_quota'] = ['mode' => 'unlimited', 'limit' => null, 'current' => 0, 'remaining' => null];
$GLOBALS['d1_code'] = 0;

function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function current_time($type = 'mysql', $gmt = false) { return $GLOBALS['d1_now']; }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['d1_meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['d1_meta'][$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['d1_meta'][$id][$key]); return true; }
function get_post_type($id) { return $GLOBALS['d1_posts'][$id]['post_type'] ?? false; }
function get_post_field($field, $id) { return $GLOBALS['d1_posts'][$id][$field] ?? ''; }
function pge_resolve_guest_quota_status($event_id) { return $GLOBALS['d1_quota']; }
function pge_get_user_plan_limits_for_events($user_id) { return ['guest_limit' => 0]; }
function pge_get_invited_phones($event_id) { return array_keys((array) pge_event_guests_get_map($event_id)); }
function pge_maybe_grant_replacement_entitlement(...$args) {}
function pge_generate_invite_code() { $GLOBALS['d1_code']++; return 'D1AA-' . str_pad((string) $GLOBALS['d1_code'], 4, '0', STR_PAD_LEFT); }
function wp_hash($value) { return hash_hmac('sha256', (string) $value, 'phase-d1-test-key'); }

class PGE_Test_Wpdb_D1
{
    public $prefix = 'wp_';
    public $rsvps = [];
    public $audit = [];
    public $insert_id = 0;
    public $lock_names = [];
    public $allow_lock = true;
    private $next_id = 1;

    public function prepare($sql, ...$args)
    {
        $index = 0;
        return preg_replace_callback('/%[ds]/', function ($match) use (&$index, $args) {
            $value = $args[$index++] ?? '';
            return $match[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $sql);
    }

    private function int_where($sql, $column)
    {
        return preg_match('/\b' . preg_quote($column, '/') . '\s*=\s*(\d+)/', $sql, $matches) ? (int) $matches[1] : null;
    }

    private function string_where($sql, $column)
    {
        return preg_match('/\b' . preg_quote($column, '/') . "\s*=\s*'([^']*)'/", $sql, $matches) ? stripslashes($matches[1]) : null;
    }

    public function seed($event_id, $phone, array $extra = [])
    {
        $id = $this->next_id++;
        $this->rsvps[$id] = array_merge([
            'id' => $id,
            'event_id' => (int) $event_id,
            'guest_phone' => (string) $phone,
            'guest_name' => null,
            'reply' => 'pending',
            'companions' => 0,
            'note' => null,
            'checked_in' => 0,
            'checked_in_at' => null,
            'checked_in_by_assignment_id' => null,
            'checkin_method' => null,
            'actual_entered_count' => null,
            'thank_you_sent_at' => null,
            'created_at' => $GLOBALS['d1_now'],
            'updated_at' => $GLOBALS['d1_now'],
        ], $extra);
        return $id;
    }

    public function get_results($sql, $output = null)
    {
        if (strpos($sql, 'pge_event_rsvps') === false) return [];
        $event_id = $this->int_where($sql, 'event_id');
        $phone = $this->string_where($sql, 'guest_phone');
        $rows = array_values(array_filter($this->rsvps, function ($row) use ($event_id, $phone) {
            return ($event_id === null || (int) $row['event_id'] === $event_id)
                && ($phone === null || (string) $row['guest_phone'] === $phone);
        }));
        usort($rows, fn($a, $b) => $a['id'] <=> $b['id']);
        if (stripos($sql, 'LIMIT 2') !== false) $rows = array_slice($rows, 0, 2);
        return $output === ARRAY_A ? $rows : array_map(fn($row) => (object) $row, $rows);
    }

    public function get_row($sql, $output = null)
    {
        $id = $this->int_where($sql, 'id');
        $event_id = $this->int_where($sql, 'event_id');
        if ($id === null || !isset($this->rsvps[$id])) return null;
        $row = $this->rsvps[$id];
        if ($event_id !== null && (int) $row['event_id'] !== $event_id) return null;
        return $output === ARRAY_A ? $row : (object) $row;
    }

    public function get_var($sql)
    {
        if (stripos($sql, 'GET_LOCK') !== false) {
            if (preg_match("/GET_LOCK\('([^']+)'/", $sql, $matches)) $this->lock_names[] = $matches[1];
            return $this->allow_lock ? 1 : 0;
        }
        if (strpos($sql, 'COALESCE(SUM(1 + companions), 0)') !== false) {
            $event_id = $this->int_where($sql, 'event_id');
            $sum = 0;
            foreach ($this->rsvps as $row) {
                if ((int) $row['event_id'] === $event_id && $row['reply'] === 'yes') $sum += 1 + (int) $row['companions'];
            }
            return $sum;
        }
        return null;
    }

    public function query($sql) { return 1; }

    public function update($table, $data, $where, $formats = null, $where_formats = null)
    {
        $id = (int) ($where['id'] ?? 0);
        if (strpos($table, 'pge_event_rsvps') === false || !isset($this->rsvps[$id])) return false;
        $this->rsvps[$id] = array_merge($this->rsvps[$id], $data);
        return 1;
    }

    public function insert($table, $data, $formats = null)
    {
        if (strpos($table, 'pge_invitation_mgmt_audit_log') !== false) {
            $this->audit[] = $data;
            $this->insert_id = count($this->audit);
            return 1;
        }
        if (strpos($table, 'pge_event_rsvps') !== false) {
            $this->insert_id = $this->seed($data['event_id'], $data['guest_phone'], $data);
            return 1;
        }
        return false;
    }
}

$GLOBALS['wpdb'] = new PGE_Test_Wpdb_D1();

require_once PGE_PATH . 'includes/rsvp-handler.php';
require_once PGE_PATH . 'includes/class-pge-invitation-service.php';
require_once PGE_PATH . 'includes/class-pge-checkin-qr-service.php';
require_once PGE_PATH . 'includes/class-pge-guest-resolution-service.php';

$passed = 0;
$failed = 0;
function d1_check($label, $condition)
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$label}\n"; }
    else { $failed++; echo "FAIL: {$label}\n"; }
}
function d1_event($event_id) { $GLOBALS['d1_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => 91]; }
function d1_status($event_id, $phone) { $map = get_post_meta($event_id, '_pge_invitation_status', true); return is_array($map) ? ($map[$phone] ?? []) : []; }
function d1_qr_version($payload) { $parts = explode('|', $payload); return isset($parts[2]) ? (int) $parts[2] : 0; }

// Core lifecycle: same rsvp_id, explicit monotonically rotating QR version.
d1_event(501);
$create = PGE_Invitation_Service::create(501, '050-000-0501', 'Lifecycle guest', '', 91);
$v1 = PGE_Invitation_Repository::get_qr_version(501, '0500000501');
d1_check('1. first invitation is created', ($create['result'] ?? '') === 'created');
d1_check('2. first invitation stores an explicit persistent qr_version', $v1 >= PGE_Invitation_Repository::PERSISTENT_QR_VERSION_FLOOR && (int) (d1_status(501, '0500000501')['qr_version'] ?? 0) === $v1);

$rsvp_id = $GLOBALS['wpdb']->seed(501, '0500000501', [
    'guest_name' => 'Old name', 'reply' => 'yes', 'companions' => 3, 'note' => 'old',
    'checked_in' => 1, 'checked_in_at' => '2026-08-11 20:00:00',
    'checked_in_by_assignment_id' => 7, 'checkin_method' => 'qr', 'actual_entered_count' => 4,
    'thank_you_sent_at' => '2026-08-11 20:05:00',
]);
$qr1 = PGE_Guest_Resolution_Service::build_scanner_qr_payload(501, $rsvp_id, '0500000501');
d1_check('3. QR for current lifecycle resolves', (PGE_Guest_Resolution_Service::resolve_from_qr(501, $qr1)['result'] ?? '') === 'found');

$delete = PGE_Invitation_Service::delete(501, '0500000501', 91);
$tombstone = d1_status(501, '0500000501');
$deleted_resolution = PGE_Guest_Resolution_Service::resolve_from_qr(501, $qr1);
d1_check('4. Hard Delete invalidates the current QR', ($delete['result'] ?? '') === 'deleted' && ($deleted_resolution['result'] ?? '') !== 'found');
d1_check('5. tombstone preserves the last qr_version', ($tombstone['status'] ?? '') === 'deleted' && (int) ($tombstone['qr_version'] ?? 0) === $v1);
d1_check('6. tombstone is not an invitation or list/count row', PGE_Invitation_Repository::get_invitation(501, '0500000501') === null && PGE_Invitation_Repository::list_invitations(501)['total'] === 0);
d1_check('7. matching tombstone alone cannot authorize deleted invitation', ($deleted_resolution['reason'] ?? '') === 'invitation_not_found');

// Same timestamp deliberately retained: time cannot be the rotation source.
$recreate = PGE_Invitation_Service::create(501, '0500000501', 'Lifecycle guest again', '', 91);
$v2 = PGE_Invitation_Repository::get_qr_version(501, '0500000501');
$qr2 = PGE_Guest_Resolution_Service::build_scanner_qr_payload(501, $rsvp_id, '0500000501');
d1_check('8. same-phone re-invite succeeds and reuses the same rsvp_id', ($recreate['result'] ?? '') === 'created' && (int) $GLOBALS['wpdb']->rsvps[$rsvp_id]['id'] === $rsvp_id);
d1_check('9. re-invite rotates to previous qr_version + 1', $v2 === $v1 + 1);
d1_check('10. same-second re-invite cannot reuse version or payload', $v2 !== $v1 && $qr2 !== $qr1);
$old_after_reinvite = PGE_Guest_Resolution_Service::resolve_from_qr(501, $qr1);
d1_check('11. old QR remains superseded after re-invite', ($old_after_reinvite['result'] ?? '') === 'invalid' && ($old_after_reinvite['reason'] ?? '') === 'qr_superseded');
d1_check('12. new QR resolves after re-invite', (PGE_Guest_Resolution_Service::resolve_from_qr(501, $qr2)['result'] ?? '') === 'found');

$row = $GLOBALS['wpdb']->rsvps[$rsvp_id];
d1_check('13. Phase C RSVP reset matrix remains intact',
    $row['guest_name'] === null && $row['reply'] === 'pending' && (int) $row['companions'] === 0 && $row['note'] === null
    && (int) $row['checked_in'] === 0 && $row['checked_in_at'] === null && $row['checked_in_by_assignment_id'] === null
    && $row['checkin_method'] === null && $row['actual_entered_count'] === null && $row['thank_you_sent_at'] === null
    && $row['created_at'] === $GLOBALS['d1_now'] && $row['updated_at'] === $GLOBALS['d1_now']);

PGE_Invitation_Service::delete(501, '0500000501', 91);
PGE_Invitation_Service::create(501, '0500000501', 'Third lifecycle', '', 91);
$v3 = PGE_Invitation_Repository::get_qr_version(501, '0500000501');
d1_check('14. repeated delete/re-invite rotates every lifecycle', $v3 === $v2 + 1);
$duplicate_version = $v3;
$duplicate = PGE_Invitation_Service::create(501, '0500000501', 'Duplicate', '', 91);
d1_check('15. duplicate live invitation rejection and version are unchanged', ($duplicate['result'] ?? '') === 'duplicate' && PGE_Invitation_Repository::get_qr_version(501, '0500000501') === $duplicate_version);

// Existing legacy active data remains valid without write-on-read.
d1_event(502);
$legacy_time = '2025-05-06 07:08:09';
pge_event_guests_save_map(502, [['phone' => '0500000502', 'name' => 'Legacy', 'note' => '', 'code' => 'LEGA-0502']]);
update_post_meta(502, '_pge_invitation_status', ['0500000502' => ['status' => 'active', 'invited_at' => $legacy_time, 'updated_at' => $legacy_time]]);
$legacy_id = $GLOBALS['wpdb']->seed(502, '0500000502', ['created_at' => $legacy_time, 'updated_at' => $legacy_time]);
$legacy_version = PGE_Invitation_Repository::get_qr_version(502, '0500000502');
$legacy_qr = PGE_Guest_Resolution_Service::build_scanner_qr_payload(502, $legacy_id, '0500000502');
d1_check('16. legacy active invitation keeps its derived version and valid QR', $legacy_version === strtotime($legacy_time) && (PGE_Guest_Resolution_Service::resolve_from_qr(502, $legacy_qr)['result'] ?? '') === 'found');
d1_check('17. legacy read remains side-effect free', !array_key_exists('qr_version', d1_status(502, '0500000502')));
PGE_Invitation_Service::delete(502, '0500000502', 91);
PGE_Invitation_Service::create(502, '0500000502', 'Legacy renewed', '', 91);
$legacy_next = PGE_Invitation_Repository::get_qr_version(502, '0500000502');
d1_check('18. legacy next lifecycle enters non-colliding persistent namespace', $legacy_next >= PGE_Invitation_Repository::PERSISTENT_QR_VERSION_FLOOR && $legacy_next !== $legacy_version);
d1_check('19. legacy QR is superseded after next lifecycle', (PGE_Guest_Resolution_Service::resolve_from_qr(502, $legacy_qr)['reason'] ?? '') === 'qr_superseded');

d1_event(507);
$high_legacy = PGE_Invitation_Repository::PERSISTENT_QR_VERSION_FLOOR + 500;
update_post_meta(507, '_pge_invitation_status', ['0500000507' => ['status' => 'deleted', 'qr_version' => $high_legacy]]);
PGE_Invitation_Service::create(507, '0500000507', 'High legacy tombstone', '', 91);
d1_check('19b. a high explicit legacy version rotates from its own value', PGE_Invitation_Repository::get_qr_version(507, '0500000507') === $high_legacy + 1);

// Validation and identity isolation.
$tampered = substr($qr2, 0, -1) . (substr($qr2, -1) === 'a' ? 'b' : 'a');
d1_check('20. signature tampering is rejected', (PGE_Checkin_QR_Service::validate(501, $tampered)['reason'] ?? '') === 'signature_mismatch');
d1_check('21. wrong authorized event is rejected', (PGE_Checkin_QR_Service::validate(999, $qr2)['reason'] ?? '') === 'event_mismatch');
$wrong_rsvp = PGE_Checkin_QR_Service::build_payload(501, 999999, $v3);
d1_check('22. wrong rsvp_id is rejected', (PGE_Checkin_QR_Service::validate(501, $wrong_rsvp)['reason'] ?? '') === 'invitation_not_found');

d1_event(503); d1_event(504);
PGE_Invitation_Service::create(503, '0500000503', 'Other guest', '', 91);
$other_guest_version = PGE_Invitation_Repository::get_qr_version(503, '0500000503');
PGE_Invitation_Service::create(504, '0500000501', 'Other event', '', 91);
$other_event_version = PGE_Invitation_Repository::get_qr_version(504, '0500000501');
PGE_Invitation_Service::delete(501, '0500000501', 91);
d1_check('23. unrelated guest remains unaffected', PGE_Invitation_Repository::get_qr_version(503, '0500000503') === $other_guest_version);
d1_check('24. same phone in unrelated event remains unaffected', PGE_Invitation_Repository::get_qr_version(504, '0500000501') === $other_event_version);

// Guest Limit and lock/concurrency behavior.
d1_event(505);
$GLOBALS['d1_quota'] = ['mode' => 'limited', 'limit' => 1, 'current' => 1, 'remaining' => 0];
$quota = PGE_Invitation_Service::create(505, '0500000505', 'Quota', '', 91);
d1_check('25. Guest Limit behavior remains unchanged', ($quota['result'] ?? '') === 'quota_exceeded' && PGE_Invitation_Repository::get_invitation(505, '0500000505') === null);
$GLOBALS['d1_quota'] = ['mode' => 'unlimited', 'limit' => null, 'current' => 0, 'remaining' => null];

d1_event(506);
PGE_Invitation_Service::create(506, '0500000506', 'Concurrent base', '', 91);
$concurrent_id = $GLOBALS['wpdb']->seed(506, '0500000506');
PGE_Invitation_Service::delete(506, '0500000506', 91);
$first_reinvite = PGE_Invitation_Service::create(506, '0500000506', 'Concurrent winner', '', 91);
$winner_version = PGE_Invitation_Repository::get_qr_version(506, '0500000506');
$second_reinvite = PGE_Invitation_Service::create(506, '0500000506', 'Concurrent loser', '', 91);
d1_check('26. two serialized re-invites yield one success and one duplicate', ($first_reinvite['result'] ?? '') === 'created' && ($second_reinvite['result'] ?? '') === 'duplicate' && PGE_Invitation_Repository::get_qr_version(506, '0500000506') === $winner_version);
$event_506_locks = array_values(array_filter($GLOBALS['wpdb']->lock_names, fn($name) => $name === 'pge_invitation_create_' . md5('506')));
d1_check('27. create/delete/re-invite share the existing per-event lock', count($event_506_locks) >= 4 && count(array_unique($event_506_locks)) === 1);
d1_check('28. serialized re-invite still reuses the same RSVP row', (int) $GLOBALS['wpdb']->rsvps[$concurrent_id]['id'] === $concurrent_id);

d1_event(508);
PGE_Invitation_Service::create(508, '0500000508', 'Lock protected', '', 91);
$locked_version = PGE_Invitation_Repository::get_qr_version(508, '0500000508');
$GLOBALS['wpdb']->allow_lock = false;
$blocked_delete = PGE_Invitation_Service::delete(508, '0500000508', 91);
$GLOBALS['wpdb']->allow_lock = true;
d1_check('28b. a competing lifecycle write that cannot acquire the lock changes nothing', ($blocked_delete['reason'] ?? '') === 'lock_not_acquired' && PGE_Invitation_Repository::get_invitation(508, '0500000508') !== null && PGE_Invitation_Repository::get_qr_version(508, '0500000508') === $locked_version);

// Regeneration continues to rotate only the scanner credential.
$invite_before_regen = PGE_Invitation_Repository::get_invitation(506, '0500000506');
$qr_before_regen = PGE_Guest_Resolution_Service::build_scanner_qr_payload(506, $concurrent_id, '0500000506');
$regen = PGE_Invitation_Service::regenerate_qr(506, '0500000506', 91);
$qr_after_regen = PGE_Guest_Resolution_Service::build_scanner_qr_payload(506, $concurrent_id, '0500000506');
$invite_after_regen = PGE_Invitation_Repository::get_invitation(506, '0500000506');
d1_check('29. QR regeneration increments version and supersedes previous QR', ($regen['result'] ?? '') === 'regenerated' && (int) $regen['qr_version'] === $winner_version + 1 && (PGE_Guest_Resolution_Service::resolve_from_qr(506, $qr_before_regen)['reason'] ?? '') === 'qr_superseded');
d1_check('30. regenerated QR is valid', (PGE_Guest_Resolution_Service::resolve_from_qr(506, $qr_after_regen)['result'] ?? '') === 'found');
d1_check('31. regeneration leaves invite_code and invitation identity unchanged', ($invite_before_regen['code'] ?? '') === ($invite_after_regen['code'] ?? '') && ($invite_before_regen['name'] ?? '') === ($invite_after_regen['name'] ?? ''));

// Scope guards for systems that must remain untouched by Phase D1.
$reminder_source = file_get_contents(PGE_PATH . 'includes/class-pge-reminder-message-service.php');
$credit_source = file_get_contents(PGE_PATH . 'includes/class-pge-invitation-credit-ledger.php');
$replacement_source = file_get_contents(PGE_PATH . 'includes/replacement-entitlement-grant.php');
d1_check('32. Reminder Service has no QR lifecycle coupling', strpos($reminder_source, 'qr_version') === false && strpos($reminder_source, 'STATUS_DELETED') === false);
d1_check('33. Invitation and Replacement Credits have no QR lifecycle coupling', strpos($credit_source, 'qr_version') === false && strpos($replacement_source, 'qr_version') === false);

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
