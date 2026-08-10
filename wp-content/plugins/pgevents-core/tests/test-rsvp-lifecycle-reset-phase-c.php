<?php
/**
 * Phase C — Authoritative RSVP Lifecycle Reset.
 *
 * Runs production Repository/Service/Bulk/Web code against an in-memory WPDB
 * harness. No migration or real database write is performed.
 *
 * Run: php tests/test-rsvp-lifecycle-reset-phase-c.php
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['pge_c_meta'] = [];
$GLOBALS['pge_c_posts'] = [];
$GLOBALS['pge_c_now'] = '2026-08-11 12:00:00';
$GLOBALS['pge_c_quota'] = ['mode' => 'unlimited', 'limit' => null, 'current' => 0, 'remaining' => null];
$GLOBALS['pge_c_hooks'] = [];
$GLOBALS['pge_c_fail_status_write_once'] = false;

function add_action($hook, $callback, ...$args) { $GLOBALS['pge_c_hooks'][$hook] = $callback; }
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function current_time($type = 'mysql', $gmt = false) { return $GLOBALS['pge_c_now']; }
function pge_norm_phone($value) { return preg_replace('/\D+/', '', (string) $value); }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['pge_c_meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) {
    if ($key === '_pge_invitation_status' && $GLOBALS['pge_c_fail_status_write_once']) {
        $GLOBALS['pge_c_fail_status_write_once'] = false;
        return false;
    }
    $GLOBALS['pge_c_meta'][$id][$key] = $value;
    return true;
}
function delete_post_meta($id, $key) { unset($GLOBALS['pge_c_meta'][$id][$key]); return true; }
function get_post_type($id) { return $GLOBALS['pge_c_posts'][$id]['post_type'] ?? false; }
function get_post_field($field, $id) { return $GLOBALS['pge_c_posts'][$id][$field] ?? ''; }
function pge_resolve_guest_quota_status($event_id) { return $GLOBALS['pge_c_quota']; }
function pge_get_user_plan_limits_for_events($user_id) { return ['guest_limit' => 0]; }
function pge_get_invited_phones($event_id) { return array_keys((array) get_post_meta($event_id, '_pge_invited_guests', true)); }
function pge_maybe_grant_replacement_entitlement(...$args) {}

class PGE_Test_Wpdb_Phase_C
{
    public $prefix = 'wp_';
    public $rsvps = [];
    public $audit = [];
    public $insert_id = 0;
    public $fail_next_rsvp_update = false;
    public $write_count = 0;
    private $next_id = 1;

    public function prepare($sql, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($match) use (&$i, $args) {
            $value = $args[$i++] ?? '';
            return $match[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $sql);
    }

    private function int_where($sql, $column)
    {
        return preg_match('/\b' . preg_quote($column, '/') . '\s*=\s*(\d+)/', $sql, $m) ? (int) $m[1] : null;
    }

    private function string_where($sql, $column)
    {
        return preg_match('/\b' . preg_quote($column, '/') . "\s*=\s*'([^']*)'/", $sql, $m) ? stripslashes($m[1]) : null;
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
            'created_at' => '2026-08-01 00:00:00',
            'updated_at' => '2026-08-01 00:00:00',
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

    public function get_var($sql)
    {
        if (stripos($sql, 'GET_LOCK') !== false) return 1;
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

    public function update($table, $data, $where, $formats = null, $where_formats = null)
    {
        $id = (int) ($where['id'] ?? 0);
        if (strpos($table, 'pge_event_rsvps') === false || !isset($this->rsvps[$id])) return false;
        if ($this->fail_next_rsvp_update) { $this->fail_next_rsvp_update = false; return false; }
        $this->write_count++;
        $this->rsvps[$id] = array_merge($this->rsvps[$id], $data);
        return 1;
    }

    public function insert($table, $data, $formats = null)
    {
        if (strpos($table, 'pge_invitation_mgmt_audit_log') !== false) {
            $this->audit[] = $data;
            return 1;
        }
        if (strpos($table, 'pge_event_rsvps') !== false) {
            $this->write_count++;
            $this->insert_id = $this->seed($data['event_id'], $data['guest_phone'], $data);
            return 1;
        }
        return false;
    }

    public function query($sql) { return 1; }
}

$GLOBALS['wpdb'] = new PGE_Test_Wpdb_Phase_C();

require_once PGE_PATH . 'includes/rsvp-handler.php';
require_once PGE_PATH . 'includes/class-pge-invitation-repository.php';
require_once PGE_PATH . 'includes/class-pge-invitation-service.php';
require_once PGE_PATH . 'includes/class-pge-invitation-bulk-add.php';

$passed = 0;
$failed = 0;
function check_phase_c($label, $condition)
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$label}\n"; }
    else { $failed++; echo "FAIL: {$label}\n"; }
}
function seed_event_c($event_id)
{
    $GLOBALS['pge_c_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => 77];
}
function audit_count_c($event_id, $action)
{
    return count(array_filter($GLOBALS['wpdb']->audit, fn($row) => (int) $row['event_id'] === $event_id && $row['action'] === $action));
}

// No historical RSVP: invitation creation must not manufacture a snapshot.
seed_event_c(100);
$result = PGE_Invitation_Service::create(100, '050-000-0100', 'No RSVP', '', 77);
check_phase_c('1. no historical RSVP => invitation created', $result['result'] === 'created');
check_phase_c('2. no historical RSVP => no RSVP inserted', pge_rsvp_find_canonical_by_phone(100, '0500000100')['status'] === 'not_found');

// Full hard-delete -> re-invite lifecycle.
seed_event_c(101);
PGE_Invitation_Service::create(101, '0500000101', 'Old invite', '', 77);
$old_id = $GLOBALS['wpdb']->seed(101, '0500000101', [
    'guest_name' => 'Historical name', 'reply' => 'yes', 'companions' => 4,
    'note' => 'historical note', 'checked_in' => 1, 'checked_in_at' => '2026-08-02 08:00:00',
    'checked_in_by_assignment_id' => 44, 'checkin_method' => 'qr', 'actual_entered_count' => 5,
    'thank_you_sent_at' => '2026-08-02 09:00:00',
]);
PGE_Invitation_Service::delete(101, '0500000101', 77);
$GLOBALS['pge_c_now'] = '2026-08-11 13:00:00';
$result = PGE_Invitation_Service::create(101, '0500000101', 'New invite', '', 77);
$row = (object) $GLOBALS['wpdb']->rsvps[$old_id];
check_phase_c('3. hard-delete re-invite succeeds', $result['result'] === 'created');
check_phase_c('4. same rsvp_id is reused', (int) $row->id === $old_id);
check_phase_c('5. event_id preserved', (int) $row->event_id === 101);
check_phase_c('6. guest_phone preserved', $row->guest_phone === '0500000101');
check_phase_c('7. guest_name reset', $row->guest_name === null);
check_phase_c('8. reply reset', $row->reply === 'pending');
check_phase_c('9. companions reset', (int) $row->companions === 0);
check_phase_c('10. note reset', $row->note === null);
check_phase_c('11. checked_in reset', (int) $row->checked_in === 0);
check_phase_c('12. checked_in_at reset', $row->checked_in_at === null);
check_phase_c('13. assignment reset', $row->checked_in_by_assignment_id === null);
check_phase_c('14. checkin_method reset', $row->checkin_method === null);
check_phase_c('15. actual_entered_count reset', $row->actual_entered_count === null);
check_phase_c('16. thank_you_sent_at reset', $row->thank_you_sent_at === null);
check_phase_c('17. created_at renewed at lifecycle start', $row->created_at === $GLOBALS['pge_c_now']);
check_phase_c('18. updated_at equals lifecycle start', $row->updated_at === $GLOBALS['pge_c_now']);
check_phase_c('19. created audit recorded after complete success', audit_count_c(101, 'created') === 2);

// Edit/resend are not lifecycle starts.
$snapshot = $GLOBALS['wpdb']->rsvps[$old_id];
PGE_Invitation_Service::edit(101, '0500000101', '0500000101', 'Renamed', 'Edited note', 77);
check_phase_c('20. normal name/note edit does not reset RSVP', $GLOBALS['wpdb']->rsvps[$old_id] === $snapshot);
PGE_Invitation_Service::resend(101, '0500000101', 77);
check_phase_c('21. invitation resend does not reset RSVP', $GLOBALS['wpdb']->rsvps[$old_id] === $snapshot);

// Quota and duplicate checks happen before reset.
seed_event_c(102);
$quota_id = $GLOBALS['wpdb']->seed(102, '0500000102', ['reply' => 'yes', 'checked_in' => 1]);
$quota_before = $GLOBALS['wpdb']->rsvps[$quota_id];
$GLOBALS['pge_c_quota'] = ['mode' => 'limited', 'limit' => 1, 'current' => 1, 'remaining' => 0];
$result = PGE_Invitation_Service::create(102, '0500000102', 'Blocked', '', 77);
check_phase_c('22. quota failure does not reset RSVP', $result['result'] === 'quota_exceeded' && $GLOBALS['wpdb']->rsvps[$quota_id] === $quota_before);
$GLOBALS['pge_c_quota'] = ['mode' => 'unlimited', 'limit' => null, 'current' => 0, 'remaining' => null];

seed_event_c(103);
PGE_Invitation_Service::create(103, '0500000103', 'Live', '', 77);
$duplicate_id = $GLOBALS['wpdb']->seed(103, '0500000103', ['reply' => 'yes', 'checked_in' => 1]);
$duplicate_before = $GLOBALS['wpdb']->rsvps[$duplicate_id];
$result = PGE_Invitation_Service::create(103, '0500000103', 'Duplicate', '', 77);
check_phase_c('23. duplicate live invitation does not reset RSVP', $result['result'] === 'duplicate' && $GLOBALS['wpdb']->rsvps[$duplicate_id] === $duplicate_before);

// Integrity and update failures are fail-closed and leave no invitation/audit.
seed_event_c(104);
$GLOBALS['wpdb']->seed(104, '0500000104');
$GLOBALS['wpdb']->seed(104, '0500000104');
$result = PGE_Invitation_Service::create(104, '0500000104', 'Corrupt', '', 77);
check_phase_c('24. integrity_error blocks creation', $result['result'] === 'error');
check_phase_c('25. integrity_error leaves no partial invitation', !isset(pge_event_guests_get_map(104)['0500000104']));

seed_event_c(105);
$failed_id = $GLOBALS['wpdb']->seed(105, '0500000105', ['reply' => 'yes', 'checked_in' => 1]);
$failed_before = $GLOBALS['wpdb']->rsvps[$failed_id];
$audit_before = audit_count_c(105, 'created');
$GLOBALS['wpdb']->fail_next_rsvp_update = true;
$result = PGE_Invitation_Service::create(105, '0500000105', 'Reset failure', '', 77);
check_phase_c('26. reset failure returns error', $result['result'] === 'error');
check_phase_c('27. reset failure restores/leaves RSVP snapshot', $GLOBALS['wpdb']->rsvps[$failed_id] === $failed_before);
check_phase_c('28. reset failure leaves no partial invitation', !isset(pge_event_guests_get_map(105)['0500000105']));
check_phase_c('29. reset failure writes no created audit', audit_count_c(105, 'created') === $audit_before);

seed_event_c(115);
$storage_id = $GLOBALS['wpdb']->seed(115, '0500000115', ['reply' => 'yes', 'checked_in' => 1, 'thank_you_sent_at' => '2026-08-01 10:00:00']);
$storage_before = $GLOBALS['wpdb']->rsvps[$storage_id];
$storage_audit_before = audit_count_c(115, 'created');
$GLOBALS['pge_c_fail_status_write_once'] = true;
$result = PGE_Invitation_Service::create(115, '0500000115', 'Storage failure', '', 77);
check_phase_c('29a. post-reset invitation storage failure returns error', $result['result'] === 'error');
check_phase_c('29b. storage compensation removes partial invitation', !isset(pge_event_guests_get_map(115)['0500000115']));
check_phase_c('29c. storage compensation restores historical RSVP snapshot', $GLOBALS['wpdb']->rsvps[$storage_id] === $storage_before);
check_phase_c('29d. storage failure writes no created audit', audit_count_c(115, 'created') === $storage_audit_before);

// Identity isolation.
seed_event_c(106); seed_event_c(107);
$same_event_other = $GLOBALS['wpdb']->seed(106, '0500000199', ['reply' => 'yes', 'checked_in' => 1]);
$other_event_same = $GLOBALS['wpdb']->seed(107, '0500000106', ['reply' => 'yes', 'checked_in' => 1]);
$other_a = $GLOBALS['wpdb']->rsvps[$same_event_other];
$other_b = $GLOBALS['wpdb']->rsvps[$other_event_same];
$GLOBALS['wpdb']->seed(106, '0500000106', ['reply' => 'yes', 'checked_in' => 1]);
PGE_Invitation_Service::create(106, '0500000106', 'Target', '', 77);
check_phase_c('30. other guest in same event unaffected', $GLOBALS['wpdb']->rsvps[$same_event_other] === $other_a);
check_phase_c('31. same phone in other event unaffected', $GLOBALS['wpdb']->rsvps[$other_event_same] === $other_b);

// Bulk Add inherits the same Service path; no reset is duplicated in Bulk.
seed_event_c(108);
PGE_Invitation_Service::create(108, '0500000108', 'Before delete', '', 77);
$bulk_id = $GLOBALS['wpdb']->seed(108, '0500000108', ['reply' => 'yes', 'checked_in' => 1, 'thank_you_sent_at' => '2026-08-01 10:00:00']);
PGE_Invitation_Service::delete(108, '0500000108', 77);
$GLOBALS['pge_c_now'] = '2026-08-11 14:00:00';
$bulk = PGE_Invitation_Bulk_Add_Service::confirm(108, "Bulk Guest,0500000108", 77);
check_phase_c('32. Bulk Add re-invite uses authoritative reset', $bulk['summary']['created'] === 1 && $GLOBALS['wpdb']->rsvps[$bulk_id]['reply'] === 'pending');
$bulk_source = file_get_contents(PGE_PATH . 'includes/class-pge-invitation-bulk-add.php');
check_phase_c('33. Bulk Add contains no duplicate reset implementation', strpos($bulk_source, 'reset_rsvp_for_new_invitation_lifecycle') === false);

// Excel Confirm delegates to Service and contains no reset implementation.
$excel_source = file_get_contents(PGE_PATH . 'includes/invitation-management-ajax.php');
check_phase_c('34. Excel Confirm delegates creation to Invitation Service', strpos($excel_source, 'PGE_Invitation_Service::create(') !== false);
check_phase_c('35. Excel Confirm contains no duplicate reset implementation', strpos($excel_source, 'reset_rsvp_for_new_invitation_lifecycle') === false);

// current_or_null is now a read-only stale guard.
seed_event_c(109);
$stale_id = $GLOBALS['wpdb']->seed(109, '0500000109', ['reply' => 'yes', 'checked_in' => 1]);
$stale_before = $GLOBALS['wpdb']->rsvps[$stale_id];
$writes_before = $GLOBALS['wpdb']->write_count;
$guarded = PGE_Invitation_Repository::current_or_null(109, '0500000109', (object) $stale_before);
check_phase_c('36. stale current_or_null returns null', $guarded === null);
check_phase_c('37. current_or_null performs no reset/write', $GLOBALS['wpdb']->write_count === $writes_before && $GLOBALS['wpdb']->rsvps[$stale_id] === $stale_before);

// Existing replies never rewrite lifecycle created_at.
$cartat_source = file_get_contents(PGE_PATH . 'includes/class-cartat-handler.php');
$ultra_source = file_get_contents(PGE_PATH . 'includes/class-ultramsg-handler.php');
check_phase_c('38. Cartat existing-row update does not write created_at', strpos($cartat_source, "['reply' => \$reply, 'created_at'") === false);
check_phase_c('39. UltraMsg existing-row update does not write created_at', strpos($ultra_source, "['reply' => \$reply, 'created_at'") === false);

seed_event_c(110);
PGE_Invitation_Service::create(110, '0500000110', 'Web', '', 77);
$web_id = $GLOBALS['wpdb']->seed(110, '0500000110', ['created_at' => '2026-08-11 15:00:00']);
$web_created_at = $GLOBALS['wpdb']->rsvps[$web_id]['created_at'];
$web = pge_save_rsvp_response(110, '0500000110', 'yes', 1, 'web reply', false);
check_phase_c('40. Web RSVP succeeds for current row', $web['success'] === true);
check_phase_c('41. Web RSVP existing row preserves created_at', $GLOBALS['wpdb']->rsvps[$web_id]['created_at'] === $web_created_at);

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
