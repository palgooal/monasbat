<?php
/**
 * Phase B — Canonical RSVP Lookup Foundation.
 * Executes the production helper and all scoped consumers against a Fake $wpdb.
 * No real database is touched.
 *
 * Run: php tests/test-rsvp-canonical-lookup-phase-b.php
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');
define('WP_CONTENT_DIR', sys_get_temp_dir());

$GLOBALS['pge_test_hooks'] = [];
function add_action($hook, $callback, ...$args) { $GLOBALS['pge_test_hooks'][$hook] = $callback; }
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function register_rest_route(...$args) {}

function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function wp_unslash($value) { return $value; }
function __($text, $domain = null) { return $text; }
function pge_norm_phone($value) { return preg_replace('/\D+/', '', trim((string) $value)); }
function current_time($type = 'mysql', $gmt = false) { return '2026-08-11 12:00:00'; }

$GLOBALS['pge_test_options'] = [];
function get_option($key, $default = false) { return $GLOBALS['pge_test_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['pge_test_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['pge_test_options'][$key]); return true; }

$GLOBALS['pge_test_meta'] = [];
$GLOBALS['pge_test_posts'] = [];
function get_post_meta($post_id, $key, $single = false) { return $GLOBALS['pge_test_meta'][$post_id][$key] ?? ''; }
function update_post_meta($post_id, $key, $value) { $GLOBALS['pge_test_meta'][$post_id][$key] = $value; return true; }
function get_post_type($post_id) { return $GLOBALS['pge_test_posts'][$post_id]['post_type'] ?? false; }
function get_post_field($field, $post_id) { return $GLOBALS['pge_test_posts'][$post_id][$field] ?? ''; }
function get_current_user_id() { return 77; }
function is_user_logged_in() { return true; }
function current_user_can($capability, $object_id = null) { return $capability === 'edit_post'; }
function wp_verify_nonce($nonce, $action) { return true; }
function pge_get_user_plan_limits_for_events($user_id) { return ['guest_limit' => 0]; }
function pge_get_invited_phones($event_id) { return array_keys((array) ($GLOBALS['pge_test_meta'][$event_id]['_pge_invited_guests'] ?? [])); }
function pge_maybe_grant_replacement_entitlement(...$args) { $GLOBALS['pge_test_grants'][] = $args; }

class PGE_Test_Json_Halt extends Exception {}
$GLOBALS['pge_test_json'] = null;
function wp_send_json_success($data = null) { $GLOBALS['pge_test_json'] = ['success' => true, 'data' => $data]; throw new PGE_Test_Json_Halt(); }
function wp_send_json_error($data = null, $status = null) { $GLOBALS['pge_test_json'] = ['success' => false, 'data' => $data]; throw new PGE_Test_Json_Halt(); }

class WP_REST_Response
{
    public $data;
    public $status;
    public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
}

class PGE_Invitation_Repository
{
    public static function current_or_null($event_id, $phone, $row) { return $row; }
    public static function is_rsvp_row_current($event_id, $phone, $created_at) { return true; }
    public static function get_invitation_status($event_id, $phone) { return 'active'; }
}

class PGE_Test_Wpdb_Phase_B
{
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $rsvps = [];
    public $insert_id = 0;
    public $last_error = '';
    public $write_count = 0;
    public $last_lookup_sql = '';
    public $legacy_events = [];
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
        return preg_match('/\b' . preg_quote($column, '/') . '\s*=\s*(\d+)/', $sql, $match) ? (int) $match[1] : null;
    }

    private function string_where($sql, $column)
    {
        return preg_match('/\b' . preg_quote($column, '/') . "\s*=\s*'([^']*)'/", $sql, $match) ? stripslashes($match[1]) : null;
    }

    public function seed($event_id, $phone, array $extra = [])
    {
        $id = $this->next_id++;
        $this->rsvps[$id] = array_merge([
            'id' => $id, 'event_id' => (int) $event_id, 'guest_phone' => (string) $phone,
            'reply' => 'pending', 'companions' => 0, 'note' => null, 'checked_in' => 0,
            'checked_in_at' => null, 'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
        ], $extra);
        return $id;
    }

    public function get_results($sql, $output = null)
    {
        if (strpos($sql, 'pge_event_rsvps') === false) return [];
        $event_id = $this->int_where($sql, 'event_id');
        $phone = $this->string_where($sql, 'guest_phone');
        if ($phone !== null) $this->last_lookup_sql = $sql;
        $rows = array_values(array_filter($this->rsvps, function ($row) use ($event_id, $phone) {
            if ($event_id !== null && (int) $row['event_id'] !== $event_id) return false;
            if ($phone !== null && (string) $row['guest_phone'] !== $phone) return false;
            return true;
        }));
        usort($rows, fn($a, $b) => $a['id'] <=> $b['id']);
        if (stripos($sql, 'LIMIT 2') !== false) $rows = array_slice($rows, 0, 2);
        return $output === ARRAY_A ? $rows : array_map(fn($row) => (object) $row, $rows);
    }

    public function get_row($sql, $output = null)
    {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_var($sql)
    {
        if (strpos($sql, 'COALESCE(SUM(1 + companions), 0)') !== false) {
            $event_id = $this->int_where($sql, 'event_id');
            $sum = 0;
            foreach ($this->rsvps as $row) {
                if ((int) $row['event_id'] === $event_id && $row['reply'] === 'yes') $sum += 1 + (int) $row['companions'];
            }
            return $sum;
        }
        if (strpos($sql, 'COUNT(DISTINCT p.ID)') !== false) return count($this->legacy_events);
        return null;
    }

    public function get_col($sql) { return strpos($sql, 'DISTINCT p.ID') !== false ? array_keys($this->legacy_events) : []; }

    public function insert($table, $data, $formats = null)
    {
        if (strpos($table, 'pge_event_rsvps') === false) return false;
        foreach ($this->rsvps as $row) {
            if ((int) $row['event_id'] === (int) $data['event_id'] && $row['guest_phone'] === $data['guest_phone']) return false;
        }
        $this->write_count++;
        $this->insert_id = $this->seed($data['event_id'], $data['guest_phone'], $data);
        return 1;
    }

    public function update($table, $data, $where, $formats = null, $where_formats = null)
    {
        $id = (int) ($where['id'] ?? 0);
        if (!isset($this->rsvps[$id])) return false;
        $this->write_count++;
        $this->rsvps[$id] = array_merge($this->rsvps[$id], $data);
        return 1;
    }

    public function query($sql)
    {
        if (stripos($sql, 'INSERT INTO') === 0 && preg_match("/VALUES\s*\((\d+),\s*'([^']+)'/", $sql, $match)) {
            $event_id = (int) $match[1];
            $phone = $match[2];
            foreach ($this->rsvps as $id => $row) {
                if ((int) $row['event_id'] === $event_id && $row['guest_phone'] === $phone) {
                    $this->write_count++;
                    $this->rsvps[$id]['checked_in'] = 1;
                    return 1;
                }
            }
            $this->write_count++;
            $this->seed($event_id, $phone, ['checked_in' => 1]);
            return 1;
        }
        return 0;
    }
}

$GLOBALS['wpdb'] = new PGE_Test_Wpdb_Phase_B();

require_once PGE_PATH . 'includes/rsvp-handler.php';
require_once PGE_PATH . 'includes/event-guests.php';
require_once PGE_PATH . 'includes/class-pge-message-type.php';
require_once PGE_PATH . 'includes/class-pge-message-recipient-resolver.php';
require_once PGE_PATH . 'includes/class-pge-guest-resolution-service.php';
require_once PGE_PATH . 'includes/class-pge-cartat-transport.php';
require_once PGE_PATH . 'includes/class-cartat-handler.php';
require_once PGE_PATH . 'includes/class-ultramsg-handler.php';
require_once PGE_PATH . 'includes/rsvp-migration.php';
require_once PGE_PATH . 'includes/ajax.php';

$passed = 0;
$failed = 0;
function check_phase_b($label, $condition)
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$label}\n"; }
    else { $failed++; echo "FAIL: {$label}\n"; }
}
function seed_event_phase_b($event_id, array $phones = [])
{
    $GLOBALS['pge_test_posts'][$event_id] = ['post_type' => 'pge_event', 'post_author' => 77];
    $map = [];
    foreach ($phones as $phone) $map[pge_norm_phone($phone)] = ['phone' => pge_norm_phone($phone), 'name' => 'Guest', 'note' => '', 'code' => 'CODE-0001'];
    $GLOBALS['pge_test_meta'][$event_id]['_pge_invited_guests'] = $map;
    $GLOBALS['pge_test_meta'][$event_id]['_pge_invited_phones'] = array_keys($map);
}
function invoke_record_phase_b($class, $event_id, $phone, $reply)
{
    $reflection = new ReflectionClass($class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('record_rsvp');
    $method->setAccessible(true);
    return $method->invoke($instance, $event_id, $phone, $reply);
}

// Canonical helper (1-6).
$lookup = pge_rsvp_find_canonical_by_phone(10, '050-000-0001');
check_phase_b('1. no row => not_found', $lookup['status'] === 'not_found' && $lookup['row'] === null);
$one_id = $GLOBALS['wpdb']->seed(11, '0500000002');
$lookup = pge_rsvp_find_canonical_by_phone(11, '0500000002');
check_phase_b('2. one row => found', $lookup['status'] === 'found' && (int) $lookup['row']->id === $one_id);
$GLOBALS['wpdb']->seed(12, '0500000003'); $GLOBALS['wpdb']->seed(12, '0500000003');
$lookup = pge_rsvp_find_canonical_by_phone(12, '0500000003');
check_phase_b('3. two rows => integrity_error', $lookup['status'] === 'integrity_error');
$GLOBALS['wpdb']->seed(13, '0500000004');
$lookup = pge_rsvp_find_canonical_by_phone(13, '050-000-0004');
check_phase_b('4. phone normalized before lookup', $lookup['status'] === 'found' && strpos($GLOBALS['wpdb']->last_lookup_sql, "guest_phone = '0500000004'") !== false);
$GLOBALS['wpdb']->seed(14, '0500000005');
$lookup = pge_rsvp_find_canonical_by_phone(15, '0500000005');
check_phase_b('5. other event isolated', $lookup['status'] === 'not_found');
$lookup = pge_rsvp_find_canonical_by_phone(12, '0500000003');
check_phase_b('6. integrity_error exposes no row/candidates', $lookup['row'] === null && !isset($lookup['candidates']));

// Web writer (7-9).
seed_event_phase_b(20, ['0500000010']);
$web_id = $GLOBALS['wpdb']->seed(20, '0500000010', ['reply' => 'pending']);
$result = pge_save_rsvp_response(20, '0500000010', 'yes', 1, '', false);
check_phase_b('7. Web found updates canonical row', $result['success'] && $GLOBALS['wpdb']->rsvps[$web_id]['reply'] === 'yes');
seed_event_phase_b(21, ['0500000011']);
$result = pge_save_rsvp_response(21, '0500000011', 'yes', 0, '', false);
check_phase_b('8. Web not_found preserves insert behavior', $result['success'] && pge_rsvp_find_canonical_by_phone(21, '0500000011')['status'] === 'found');
seed_event_phase_b(22, ['0500000012']);
$GLOBALS['wpdb']->seed(22, '0500000012'); $GLOBALS['wpdb']->seed(22, '0500000012');
$writes = $GLOBALS['wpdb']->write_count;
$result = pge_save_rsvp_response(22, '0500000012', 'yes', 0, '', false);
check_phase_b('9. Web integrity_error performs no write', !$result['success'] && $GLOBALS['wpdb']->write_count === $writes);

// Provider writers (10-13).
$cartat_id = $GLOBALS['wpdb']->seed(30, '0500000020', ['reply' => 'pending']);
$returned = invoke_record_phase_b('Mon_Cartat_Handler', 30, '0500000020', 'yes');
check_phase_b('10. Cartat found preserves update behavior', $returned === $cartat_id && $GLOBALS['wpdb']->rsvps[$cartat_id]['reply'] === 'yes');
$GLOBALS['wpdb']->seed(31, '0500000021'); $GLOBALS['wpdb']->seed(31, '0500000021');
$writes = $GLOBALS['wpdb']->write_count;
$returned = invoke_record_phase_b('Mon_Cartat_Handler', 31, '0500000021', 'yes');
check_phase_b('11. Cartat integrity_error performs no write', $returned === -1 && $GLOBALS['wpdb']->write_count === $writes);
$ultra_id = $GLOBALS['wpdb']->seed(32, '0500000022', ['reply' => 'pending']);
$returned = invoke_record_phase_b('Mon_UltraMsg_Handler', 32, '0500000022', 'no');
check_phase_b('12. UltraMsg found preserves update behavior', $returned === $ultra_id && $GLOBALS['wpdb']->rsvps[$ultra_id]['reply'] === 'no');
$GLOBALS['wpdb']->seed(33, '0500000023'); $GLOBALS['wpdb']->seed(33, '0500000023');
$writes = $GLOBALS['wpdb']->write_count;
$returned = invoke_record_phase_b('Mon_UltraMsg_Handler', 33, '0500000023', 'yes');
check_phase_b('13. UltraMsg integrity_error performs no write', $returned === -1 && $GLOBALS['wpdb']->write_count === $writes);

// Migration + legacy check-in (14-15).
seed_event_phase_b(40, ['0500000030']);
$GLOBALS['wpdb']->legacy_events[40] = true;
$GLOBALS['pge_test_meta'][40]['_pge_rsvp_map'] = ['g_0500000030' => ['reply' => 'yes', 'companions' => 0, 'note' => '']];
$GLOBALS['wpdb']->seed(40, '0500000030'); $GLOBALS['wpdb']->seed(40, '0500000030');
$writes = $GLOBALS['wpdb']->write_count;
$report = pge_migrate_legacy_rsvp_meta(['dry_run' => false, 'batch_size' => 25, 'offset' => 0]);
check_phase_b('14. Migration integrity_error is conflict/no write', $report['conflicts'] === 1 && $GLOBALS['wpdb']->write_count === $writes);
seed_event_phase_b(41, ['0500000031']);
$GLOBALS['wpdb']->seed(41, '0500000031'); $GLOBALS['wpdb']->seed(41, '0500000031');
$_POST = ['nonce' => 'ok', 'event_id' => 41, 'phone' => '0500000031'];
$writes = $GLOBALS['wpdb']->write_count;
$GLOBALS['pge_test_json'] = null;
try { ($GLOBALS['pge_test_hooks']['wp_ajax_pge_checkin_guest'])(); } catch (PGE_Test_Json_Halt $e) {}
check_phase_b('15. Legacy Check-in integrity_error performs no write', $GLOBALS['pge_test_json']['success'] === false && $GLOBALS['wpdb']->write_count === $writes);

// Readers (16-21).
seed_event_phase_b(50, ['0500000040']);
$GLOBALS['wpdb']->seed(50, '0500000040', ['reply' => 'yes']);
$map = pge_event_guests_load_rsvp_from_db(50);
check_phase_b('16. event-guests normal row works', ($map['map']['0500000040'] ?? '') === 'yes');
seed_event_phase_b(51, ['0500000041']);
$GLOBALS['wpdb']->seed(51, '0500000041', ['reply' => 'yes']); $GLOBALS['wpdb']->seed(51, '0500000041', ['reply' => 'no']);
$map = pge_event_guests_load_rsvp_from_db(51);
check_phase_b('17. event-guests duplicate is not silently overwritten', !isset($map['map']['0500000041']) && !empty($map['integrity_errors']['0500000041']));
seed_event_phase_b(52, ['0500000042']);
$GLOBALS['wpdb']->seed(52, '0500000042', ['reply' => 'pending']);
$resolved = PGE_Message_Recipient_Resolver::resolve(52, PGE_Message_Type::REMINDER, 'pending');
check_phase_b('18. Reminder normal recipient behavior unchanged', array_column($resolved['recipients'], 'phone') === ['0500000042']);
seed_event_phase_b(53, ['0500000043']);
$GLOBALS['wpdb']->seed(53, '0500000043'); $GLOBALS['wpdb']->seed(53, '0500000043');
$resolved = PGE_Message_Recipient_Resolver::resolve(53, PGE_Message_Type::REMINDER, 'all');
check_phase_b('19. Reminder corrupt phone is skipped', $resolved['recipients'] === [] && $resolved['skipped_integrity_error'] === 1);
check_phase_b('20. corrupt rows cannot create duplicate Reminder recipients', count($resolved['recipients']) === 0);
seed_event_phase_b(54, ['0500000044']);
$GLOBALS['wpdb']->seed(54, '0500000044'); $GLOBALS['wpdb']->seed(54, '0500000044');
$resolved_guest = PGE_Guest_Resolution_Service::resolve_by_phone(54, '0500000044');
check_phase_b('21. Guest Resolution duplicate does not auto-select', $resolved_guest['result'] === 'ambiguous' && $resolved_guest['candidates'] === []);

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
