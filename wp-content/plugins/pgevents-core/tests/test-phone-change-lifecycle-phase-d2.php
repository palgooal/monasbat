<?php
/**
 * Phase D2 — Phone Change Lifecycle central executable harness.
 *
 * Runtime: normal PHP CLI with fake WordPress post meta and fake $wpdb.
 * No PHPUnit, no real WordPress bootstrap, no production/MySQL writes.
 * Run: php tests/test-phone-change-lifecycle-phase-d2.php
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . '/');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

$GLOBALS['d2_meta'] = [];
$GLOBALS['d2_posts'] = [];
$GLOBALS['d2_now'] = '2026-08-11 22:00:00';
$GLOBALS['d2_fail_guest_write_once'] = false;
$GLOBALS['d2_fail_status_write_once'] = false;
$GLOBALS['d2_replacement_calls'] = 0;
$GLOBALS['d2_code'] = 0;

function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function current_time($type = 'mysql', $gmt = false) { return $GLOBALS['d2_now']; }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['d2_meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) {
    if ($key === '_pge_invited_guests' && $GLOBALS['d2_fail_guest_write_once']) {
        $GLOBALS['d2_fail_guest_write_once'] = false;
        return false;
    }
    if ($key === '_pge_invitation_status' && $GLOBALS['d2_fail_status_write_once']) {
        $GLOBALS['d2_fail_status_write_once'] = false;
        return false;
    }
    $GLOBALS['d2_meta'][$id][$key] = $value;
    return true;
}
function delete_post_meta($id, $key) { unset($GLOBALS['d2_meta'][$id][$key]); return true; }
function get_post_type($id) { return $GLOBALS['d2_posts'][$id]['post_type'] ?? false; }
function get_post_field($field, $id) { return $GLOBALS['d2_posts'][$id][$field] ?? ''; }
function get_current_user_id() { return 91; }
function current_user_can(...$args) { return true; }
function pge_norm_phone($value) { return preg_replace('/\D+/', '', (string) $value); }
function pge_normalize_invite_code($value) { return strtoupper(trim((string) $value)); }
function pge_resolve_guest_quota_status($event_id) {
    $count = count((array) pge_event_guests_get_map($event_id));
    return ['mode' => 'limited', 'limit' => 50, 'current' => $count, 'remaining' => 50 - $count];
}
function pge_get_user_plan_limits_for_events($user_id) { return ['guest_limit' => 50]; }
function pge_get_invited_phones($event_id) { return array_keys((array) pge_event_guests_get_map($event_id)); }
function pge_maybe_grant_replacement_entitlement(...$args) { $GLOBALS['d2_replacement_calls']++; }
function pge_generate_invite_code() { $GLOBALS['d2_code']++; return 'D2AA-' . str_pad((string) $GLOBALS['d2_code'], 4, '0', STR_PAD_LEFT); }
function wp_hash($value) { return hash_hmac('sha256', (string) $value, 'phase-d2-test-key'); }

class PGE_Test_Wpdb_D2
{
    public $prefix = 'wp_';
    public $rsvps = [];
    public $audit = [];
    public $insert_id = 0;
    public $fail_next_rsvp_update = false;
    public $ignore_next_rsvp_update = false;
    public $rsvp_write_count = 0;
    public $lock_names = [];
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
            'created_at' => $GLOBALS['d2_now'],
            'updated_at' => $GLOBALS['d2_now'],
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
            return 1;
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
        if ($this->fail_next_rsvp_update) { $this->fail_next_rsvp_update = false; return false; }
        if ($this->ignore_next_rsvp_update) { $this->ignore_next_rsvp_update = false; return 1; }
        $this->rsvp_write_count++;
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

$GLOBALS['wpdb'] = new PGE_Test_Wpdb_D2();

require_once PGE_PATH . 'includes/rsvp-handler.php';
require_once PGE_PATH . 'includes/class-pge-invitation-service.php';
require_once PGE_PATH . 'includes/class-pge-checkin-qr-service.php';
require_once PGE_PATH . 'includes/class-pge-guest-resolution-service.php';
require_once PGE_PATH . 'includes/class-pge-message-type.php';
require_once PGE_PATH . 'includes/class-pge-message-recipient-resolver.php';

$passed = 0;
$failed = 0;
function d2_check($label, $condition)
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$label}\n"; }
    else { $failed++; echo "FAIL: {$label}\n"; }
}
function d2_event($event_id) { $GLOBALS['d2_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => 91]; }
function d2_status($event_id, $phone) { $map = get_post_meta($event_id, '_pge_invitation_status', true); return is_array($map) ? ($map[$phone] ?? []) : []; }
function d2_audit_count($event_id, $action) { return count(array_filter($GLOBALS['wpdb']->audit, fn($row) => (int) $row['event_id'] === $event_id && $row['action'] === $action)); }

// No historical target RSVP: source remains historical and no target row is inserted.
d2_event(601);
$GLOBALS['d2_now'] = '2026-08-11 22:00:00';
PGE_Invitation_Service::create(601, '0500000601', 'Source one', 'old', 91);
$source_one_id = $GLOBALS['wpdb']->seed(601, '0500000601', [
    'guest_name' => 'Source RSVP', 'reply' => 'yes', 'companions' => 2, 'note' => 'source note',
    'checked_in' => 1, 'checked_in_at' => '2026-08-10 10:00:00', 'checked_in_by_assignment_id' => 5,
    'checkin_method' => 'qr', 'actual_entered_count' => 3, 'thank_you_sent_at' => '2026-08-10 11:00:00',
]);
$source_one_before = $GLOBALS['wpdb']->rsvps[$source_one_id];
$code_one = pge_event_guests_get_map(601)['0500000601']['code'];
$GLOBALS['d2_now'] = '2026-08-11 22:10:00';
$result = PGE_Invitation_Service::edit(601, '050-000-0601', '050-000-0691', 'Target one', 'new', 91);
$map = pge_event_guests_get_map(601);
d2_check('1. old to new without target RSVP succeeds', ($result['result'] ?? '') === 'updated');
d2_check('2. source RSVP remains byte-for-byte unchanged', $GLOBALS['wpdb']->rsvps[$source_one_id] === $source_one_before);
d2_check('3. no target RSVP row is inserted', pge_rsvp_find_canonical_by_phone(601, '0500000691')['status'] === 'not_found');
d2_check('4. invitation guest map moves old to new', !isset($map['0500000601']) && isset($map['0500000691']));
d2_check('5. invite_code is preserved across phone change', ($map['0500000691']['code'] ?? '') === $code_one);
$manual_lookup = PGE_Guest_Resolution_Service::search(601, $code_one);
$manual_phones = array_column((array) ($manual_lookup['guests'] ?? []), 'phone');
d2_check('5a. preserved invite_code resolves only the new phone', in_array('0500000691', $manual_phones, true) && !in_array('0500000601', $manual_phones, true));
d2_check('6. invited_at is renewed once for target lifecycle', (d2_status(601, '0500000691')['invited_at'] ?? '') === $GLOBALS['d2_now']);
d2_check('7. source becomes a non-live deleted tombstone', (d2_status(601, '0500000601')['status'] ?? '') === 'deleted');
d2_check('8. target gets an explicit persistent QR version', (int) (d2_status(601, '0500000691')['qr_version'] ?? 0) >= PGE_Invitation_Repository::PERSISTENT_QR_VERSION_FLOOR);
d2_check('9. guest count is unchanged by phone change', count($map) === 1);
d2_check('10. one edited audit is written only after success', d2_audit_count(601, 'edited') === 1);
$audit_601 = array_values(array_filter($GLOBALS['wpdb']->audit, fn($row) => (int) $row['event_id'] === 601));
d2_check('10a. append-only audit preserves old created phone and records edited target', array_column($audit_601, 'guest_phone') === ['0500000601', '0500000691'] && array_column($audit_601, 'action') === ['created', 'edited']);

// Historical target: reuse target id, reset target only, rotate QR, same-second safe.
d2_event(602);
$GLOBALS['d2_now'] = '2026-08-11 23:00:00';
PGE_Invitation_Service::create(602, '0500000602', 'Source two', '', 91);
$source_two_id = $GLOBALS['wpdb']->seed(602, '0500000602', [
    'guest_name' => 'Keep source', 'reply' => 'yes', 'companions' => 1, 'note' => 'keep',
    'checked_in' => 1, 'checked_in_at' => '2026-08-10 12:00:00', 'thank_you_sent_at' => '2026-08-10 13:00:00',
]);
$source_two_before = $GLOBALS['wpdb']->rsvps[$source_two_id];
$source_qr = PGE_Guest_Resolution_Service::build_scanner_qr_payload(602, $source_two_id, '0500000602');
$target_two_id = $GLOBALS['wpdb']->seed(602, '0500000692', [
    'guest_name' => 'Stale target', 'reply' => 'yes', 'companions' => 4, 'note' => 'stale',
    'checked_in' => 1, 'checked_in_at' => '2025-01-01 01:00:00', 'checked_in_by_assignment_id' => 8,
    'checkin_method' => 'manual', 'actual_entered_count' => 5, 'thank_you_sent_at' => '2025-01-01 02:00:00',
    'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 02:00:00',
]);
$status_602 = get_post_meta(602, '_pge_invitation_status', true);
$target_old_version = PGE_Invitation_Repository::PERSISTENT_QR_VERSION_FLOOR + 400;
$status_602['0500000692'] = ['status' => 'deleted', 'qr_version' => $target_old_version, 'deleted_at' => '2025-01-01 03:00:00'];
update_post_meta(602, '_pge_invitation_status', $status_602);
$target_old_qr = PGE_Checkin_QR_Service::build_payload(602, $target_two_id, $target_old_version);
$result = PGE_Invitation_Service::edit(602, '0500000602', '0500000692', 'Target two', '', 91);
$target = $GLOBALS['wpdb']->rsvps[$target_two_id];
$target_new_version = PGE_Invitation_Repository::get_qr_version(602, '0500000692');
$target_new_qr = PGE_Guest_Resolution_Service::build_scanner_qr_payload(602, $target_two_id, '0500000692');
d2_check('11. historical target phone change succeeds', ($result['result'] ?? '') === 'updated');
d2_check('12. historical target reuses the same canonical rsvp_id', (int) $target['id'] === $target_two_id);
d2_check('13. target guest_name is reset', $target['guest_name'] === null);
d2_check('14. target reply is reset to pending', $target['reply'] === 'pending');
d2_check('15. target companions are reset', (int) $target['companions'] === 0);
d2_check('16. target note is reset', $target['note'] === null);
d2_check('17. target checked_in is reset', (int) $target['checked_in'] === 0);
d2_check('18. target check-in metadata is reset', $target['checked_in_at'] === null && $target['checked_in_by_assignment_id'] === null && $target['checkin_method'] === null && $target['actual_entered_count'] === null);
d2_check('19. target thank_you_sent_at is reset', $target['thank_you_sent_at'] === null);
d2_check('20. target created_at and updated_at share lifecycle timestamp', $target['created_at'] === $GLOBALS['d2_now'] && $target['updated_at'] === $GLOBALS['d2_now']);
d2_check('21. source RSVP remains untouched with historical attendance', $GLOBALS['wpdb']->rsvps[$source_two_id] === $source_two_before);
d2_check('22. target QR rotates from its own tombstone', $target_new_version === $target_old_version + 1);
d2_check('23. source old QR is no longer usable', (PGE_Guest_Resolution_Service::resolve_from_qr(602, $source_qr)['result'] ?? '') !== 'found');
d2_check('23a. provider lifecycle guard treats source RSVP as non-current', PGE_Invitation_Repository::current_or_null(602, '0500000602', (object) $GLOBALS['wpdb']->rsvps[$source_two_id]) === null);
d2_check('24. target historical QR remains superseded', (PGE_Guest_Resolution_Service::resolve_from_qr(602, $target_old_qr)['reason'] ?? '') === 'qr_superseded');
d2_check('25. target new QR is valid', (PGE_Guest_Resolution_Service::resolve_from_qr(602, $target_new_qr)['result'] ?? '') === 'found');
d2_check('26. same-second phone change cannot reuse target version', $target_new_version !== $target_old_version);

// Legacy target status without an explicit version is promoted without write-on-read.
d2_event(611);
PGE_Invitation_Service::create(611, '0500000611', 'Legacy source', '', 91);
$legacy_target_id = $GLOBALS['wpdb']->seed(611, '0500000699', ['created_at' => '2025-02-03 04:05:06']);
$legacy_status = get_post_meta(611, '_pge_invitation_status', true);
$legacy_status['0500000699'] = ['status' => 'deleted', 'invited_at' => '2025-02-03 04:05:06'];
update_post_meta(611, '_pge_invitation_status', $legacy_status);
$legacy_version = PGE_Invitation_Repository::get_qr_version(611, '0500000699');
$legacy_entry_before = d2_status(611, '0500000699');
PGE_Invitation_Service::edit(611, '0500000611', '0500000699', 'Legacy target', '', 91);
d2_check('26a. legacy target version read is side-effect free', !array_key_exists('qr_version', $legacy_entry_before) && $legacy_version === strtotime('2025-02-03 04:05:06'));
d2_check('26b. legacy target enters the persistent namespace on phone change', PGE_Invitation_Repository::get_qr_version(611, '0500000699') >= PGE_Invitation_Repository::PERSISTENT_QR_VERSION_FLOOR && (int) $GLOBALS['wpdb']->rsvps[$legacy_target_id]['id'] === $legacy_target_id);

// Duplicate target: no source or target mutation and no audit.
d2_event(603);
PGE_Invitation_Service::create(603, '0500000603', 'Source duplicate', '', 91);
PGE_Invitation_Service::create(603, '0500000693', 'Live target', '', 91);
$duplicate_target_id = $GLOBALS['wpdb']->seed(603, '0500000693', ['reply' => 'yes', 'checked_in' => 1]);
$duplicate_map_before = pge_event_guests_get_map(603);
$duplicate_status_before = get_post_meta(603, '_pge_invitation_status', true);
$duplicate_rsvp_before = $GLOBALS['wpdb']->rsvps[$duplicate_target_id];
$duplicate_audit_before = d2_audit_count(603, 'edited');
$result = PGE_Invitation_Service::edit(603, '0500000603', '0500000693', 'Rejected', '', 91);
d2_check('27. active target is rejected as duplicate', ($result['result'] ?? '') === 'duplicate');
d2_check('28. duplicate rejection does not reset target RSVP', $GLOBALS['wpdb']->rsvps[$duplicate_target_id] === $duplicate_rsvp_before);
d2_check('29. duplicate rejection leaves source and maps untouched', pge_event_guests_get_map(603) === $duplicate_map_before && get_post_meta(603, '_pge_invitation_status', true) === $duplicate_status_before && d2_audit_count(603, 'edited') === $duplicate_audit_before);

// Integrity error before writes.
d2_event(604);
PGE_Invitation_Service::create(604, '0500000604', 'Integrity source', '', 91);
$GLOBALS['wpdb']->seed(604, '0500000694', ['reply' => 'yes']);
$GLOBALS['wpdb']->seed(604, '0500000694', ['reply' => 'no']);
$integrity_map_before = pge_event_guests_get_map(604);
$integrity_status_before = get_post_meta(604, '_pge_invitation_status', true);
$integrity_rows_before = $GLOBALS['wpdb']->rsvps;
$result = PGE_Invitation_Service::edit(604, '0500000604', '0500000694', 'Blocked', '', 91);
d2_check('30. target integrity error returns fail-closed', ($result['result'] ?? '') === 'error');
d2_check('31. integrity error causes no mutation or success audit', pge_event_guests_get_map(604) === $integrity_map_before && get_post_meta(604, '_pge_invitation_status', true) === $integrity_status_before && $GLOBALS['wpdb']->rsvps === $integrity_rows_before && d2_audit_count(604, 'edited') === 0);

// RSVP reset failure before meta writes.
d2_event(605);
PGE_Invitation_Service::create(605, '0500000605', 'Reset source', '', 91);
$reset_target_id = $GLOBALS['wpdb']->seed(605, '0500000695', ['reply' => 'yes', 'checked_in' => 1]);
$reset_map_before = pge_event_guests_get_map(605);
$reset_status_before = get_post_meta(605, '_pge_invitation_status', true);
$reset_row_before = $GLOBALS['wpdb']->rsvps[$reset_target_id];
$GLOBALS['wpdb']->fail_next_rsvp_update = true;
$result = PGE_Invitation_Service::edit(605, '0500000605', '0500000695', 'Reset fail', '', 91);
d2_check('32. target RSVP reset failure returns error', ($result['result'] ?? '') === 'error');
d2_check('33. reset failure leaves RSVP/maps unchanged and no audit', $GLOBALS['wpdb']->rsvps[$reset_target_id] === $reset_row_before && pge_event_guests_get_map(605) === $reset_map_before && get_post_meta(605, '_pge_invitation_status', true) === $reset_status_before && d2_audit_count(605, 'edited') === 0);

// Guest-map storage failure compensates target RSVP and both maps.
d2_event(606);
PGE_Invitation_Service::create(606, '0500000606', 'Storage source', '', 91);
$storage_target_id = $GLOBALS['wpdb']->seed(606, '0500000696', ['reply' => 'yes', 'checked_in' => 1, 'thank_you_sent_at' => '2025-01-01 00:00:00']);
$storage_map_before = pge_event_guests_get_map(606);
$storage_status_before = get_post_meta(606, '_pge_invitation_status', true);
$storage_row_before = $GLOBALS['wpdb']->rsvps[$storage_target_id];
$GLOBALS['d2_fail_guest_write_once'] = true;
$result = PGE_Invitation_Service::edit(606, '0500000606', '0500000696', 'Storage fail', '', 91);
d2_check('34. guest-map storage failure returns error', ($result['result'] ?? '') === 'error');
d2_check('35. storage compensation restores source maps and target RSVP', pge_event_guests_get_map(606) === $storage_map_before && get_post_meta(606, '_pge_invitation_status', true) === $storage_status_before && $GLOBALS['wpdb']->rsvps[$storage_target_id] === $storage_row_before && d2_audit_count(606, 'edited') === 0);

// Status/QR storage failure compensates a guest-map write and reset.
d2_event(607);
PGE_Invitation_Service::create(607, '0500000607', 'Status source', '', 91);
$status_target_id = $GLOBALS['wpdb']->seed(607, '0500000697', ['reply' => 'yes', 'checked_in' => 1]);
$status_map_before_guests = pge_event_guests_get_map(607);
$status_map_before = get_post_meta(607, '_pge_invitation_status', true);
$status_row_before = $GLOBALS['wpdb']->rsvps[$status_target_id];
$GLOBALS['d2_fail_status_write_once'] = true;
$result = PGE_Invitation_Service::edit(607, '0500000607', '0500000697', 'Status fail', '', 91);
d2_check('36. target QR/status storage failure returns error', ($result['result'] ?? '') === 'error');
d2_check('37. status compensation restores all snapshots and writes no audit', pge_event_guests_get_map(607) === $status_map_before_guests && get_post_meta(607, '_pge_invitation_status', true) === $status_map_before && $GLOBALS['wpdb']->rsvps[$status_target_id] === $status_row_before && d2_audit_count(607, 'edited') === 0);

// Isolation, recipient resolution, RSVP writer, credits, and read-only guard.
d2_event(608); d2_event(609);
PGE_Invitation_Service::create(608, '0500000608', 'Moving', '', 91);
PGE_Invitation_Service::create(608, '0500000688', 'Other guest', '', 91);
PGE_Invitation_Service::create(609, '0500000608', 'Other event same phone', '', 91);
$other_guest_before = pge_event_guests_get_map(608)['0500000688'];
$other_event_before = pge_event_guests_get_map(609);
$GLOBALS['d2_replacement_calls'] = 0;
PGE_Invitation_Service::edit(608, '0500000608', '0500000698', 'Moved', '', 91);
d2_check('38. other guest in same event is unaffected', pge_event_guests_get_map(608)['0500000688'] === $other_guest_before);
d2_check('39. same phone in other event is unaffected', pge_event_guests_get_map(609) === $other_event_before);
$recipients = PGE_Message_Recipient_Resolver::resolve(608, PGE_Message_Type::REMINDER, PGE_Message_Recipient_Resolver::FILTER_ALL)['recipients'];
$recipient_phones = array_column($recipients, 'phone');
d2_check('40. Reminder resolves new phone and excludes old phone', in_array('0500000698', $recipient_phones, true) && !in_array('0500000608', $recipient_phones, true));
$rsvp_new = pge_save_rsvp_response(608, '0500000698', 'yes', 0, 'new reply', false);
$rsvp_old = pge_save_rsvp_response(608, '0500000608', 'yes', 0, 'old reply', false);
d2_check('41. RSVP from new phone follows the current invitation', $rsvp_new['success'] === true && pge_rsvp_find_canonical_by_phone(608, '0500000698')['status'] === 'found');
d2_check('42. old phone is rejected by the current invitation gate', $rsvp_old['success'] === false && !isset(pge_event_guests_get_map(608)['0500000608']));
d2_check('43. phone change consumes no replacement/invitation credit side effect', $GLOBALS['d2_replacement_calls'] === 0);
$new_row = pge_rsvp_find_canonical_by_phone(608, '0500000698')['row'];
$writes_before_guard = $GLOBALS['wpdb']->rsvp_write_count;
PGE_Invitation_Repository::current_or_null(608, '0500000698', $new_row);
d2_check('44. current_or_null remains read-only', $GLOBALS['wpdb']->rsvp_write_count === $writes_before_guard);

// Existing Phase C and D1 lifecycle contracts still work after D2.
d2_event(610);
PGE_Invitation_Service::create(610, '0500000610', 'Lifecycle regression', '', 91);
$regression_id = $GLOBALS['wpdb']->seed(610, '0500000610', ['reply' => 'yes', 'checked_in' => 1, 'thank_you_sent_at' => '2025-01-01 00:00:00']);
$regression_v1 = PGE_Invitation_Repository::get_qr_version(610, '0500000610');
PGE_Invitation_Service::delete(610, '0500000610', 91);
PGE_Invitation_Service::create(610, '0500000610', 'Lifecycle renewed', '', 91);
$regression_row = $GLOBALS['wpdb']->rsvps[$regression_id];
$regression_v2 = PGE_Invitation_Repository::get_qr_version(610, '0500000610');
d2_check('45. Phase C reinvite reset remains authoritative', $regression_row['reply'] === 'pending' && (int) $regression_row['checked_in'] === 0 && $regression_row['thank_you_sent_at'] === null);
d2_check('46. Phase D1 hard-delete/reinvite rotation remains intact', $regression_v2 === $regression_v1 + 1);

// Static guards: no parallel legacy writer and no out-of-scope coupling.
$event_guests_source = file_get_contents(PGE_PATH . 'includes/event-guests.php');
$service_source = file_get_contents(PGE_PATH . 'includes/class-pge-invitation-service.php');
$reminder_source = file_get_contents(PGE_PATH . 'includes/class-pge-reminder-message-service.php');
$credit_source = file_get_contents(PGE_PATH . 'includes/class-pge-invitation-credit-ledger.php');
$cartat_source = file_get_contents(PGE_PATH . 'includes/class-cartat-handler.php');
$ultramsg_source = file_get_contents(PGE_PATH . 'includes/class-ultramsg-handler.php');
d2_check('47. legacy update endpoint delegates to Invitation Service', strpos($event_guests_source, 'PGE_Invitation_Service::edit(') !== false);
d2_check('48. lifecycle edit shares the existing per-event lock', count(array_filter($GLOBALS['wpdb']->lock_names, fn($name) => $name === 'pge_invitation_create_' . md5('602'))) >= 2);
d2_check('49. Reminder Service remains free of phone-change implementation', strpos($reminder_source, 'phone_change') === false && strpos($reminder_source, 'STATUS_DELETED') === false);
d2_check('50. credit ledger remains free of phone-change implementation', strpos($credit_source, 'phone_change') === false && strpos($credit_source, 'PGE_Invitation_Repository::edit') === false && strpos($service_source, 'consume') === false);
d2_check('51. Cartat and UltraMsg retain canonical read-only lifecycle guards', strpos($cartat_source, 'pge_rsvp_find_canonical_by_phone') !== false && strpos($cartat_source, 'PGE_Invitation_Repository::current_or_null') !== false && strpos($ultramsg_source, 'pge_rsvp_find_canonical_by_phone') !== false && strpos($ultramsg_source, 'PGE_Invitation_Repository::current_or_null') !== false);

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
