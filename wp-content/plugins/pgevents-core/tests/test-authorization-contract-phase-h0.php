<?php
/**
 * Phase H0 — Authorization Contract Audit/Fix.
 *
 * Exercises the real authorization helpers and the real Cartat/UltraMsg AJAX
 * handlers with a small fake WordPress runtime. It never touches a database.
 *
 * Run: php tests/test-authorization-contract-phase-h0.php
 */

define('ABSPATH', __DIR__ . '/');

class PGE_H0_Ajax_Stop extends RuntimeException
{
    public bool $success;
    public $data;

    public function __construct(bool $success, $data)
    {
        parent::__construct('ajax-stop');
        $this->success = $success;
        $this->data = $data;
    }
}

$GLOBALS['pge_h0_posts'] = [];
$GLOBALS['pge_h0_options'] = [];
$GLOBALS['pge_h0_user_id'] = 0;
$GLOBALS['pge_h0_admin'] = false;
$GLOBALS['pge_h0_editable'] = [];
$GLOBALS['pge_h0_logged_in'] = false;
$GLOBALS['pge_h0_nonce_valid'] = false;
$GLOBALS['pge_h0_option_reads'] = 0;

function add_action(...$args) {}
function add_filter(...$args) {}
function register_rest_route(...$args) {}
function absint($value) { return abs((int) $value); }
function is_user_logged_in() { return $GLOBALS['pge_h0_logged_in']; }
function wp_verify_nonce($nonce, $action) { return $GLOBALS['pge_h0_nonce_valid']; }
function get_current_user_id() { return (int) $GLOBALS['pge_h0_user_id']; }
function current_user_can($capability, $object_id = null)
{
    if ($capability === 'administrator') return $GLOBALS['pge_h0_admin'];
    if ($capability === 'edit_post') return in_array((int) $object_id, $GLOBALS['pge_h0_editable'], true);
    return false;
}
function get_post_type($post_id)
{
    return $GLOBALS['pge_h0_posts'][(int) $post_id]['post_type'] ?? false;
}
function get_post_field($field, $post_id)
{
    return $GLOBALS['pge_h0_posts'][(int) $post_id][$field] ?? '';
}
function get_option($key, $default = false)
{
    $GLOBALS['pge_h0_option_reads']++;
    return $GLOBALS['pge_h0_options'][$key] ?? $default;
}
function wp_send_json_success($data = null) { throw new PGE_H0_Ajax_Stop(true, $data); }
function wp_send_json_error($data = null) { throw new PGE_H0_Ajax_Stop(false, $data); }
function wp_die($message = '') { throw new PGE_H0_Ajax_Stop(false, $message); }
function status_header($status) {}

class PGE_Cartat_Transport {}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-cartat-handler.php';
require_once __DIR__ . '/../includes/class-ultramsg-handler.php';

class PGE_H0_Cartat_Handler extends Mon_Cartat_Handler
{
    public function __construct() {}
}

class PGE_H0_UltraMsg_Handler extends Mon_UltraMsg_Handler
{
    public int $cron_calls = 0;
    public function __construct() {}
    public function cron_process_queue(int $event_id): void
    {
        $this->cron_calls++;
        echo 'H0_CRON:' . $event_id;
    }
}

function pge_h0_reset(): void
{
    $GLOBALS['pge_h0_posts'] = [
        100 => ['post_type' => 'pge_event', 'post_author' => 10],
        101 => ['post_type' => 'pge_event', 'post_author' => 11],
        200 => ['post_type' => 'post', 'post_author' => 10],
    ];
    $GLOBALS['pge_h0_options'] = [
        'pge_wa_queue_100' => [
            'status' => 'done',
            'provider' => 'ultramsg',
            'total' => 1,
            'offset' => 1,
            'results' => ['966500000001' => ['status' => 'sent', 'time' => 'now']],
            'guests_map' => ['966500000001' => ['name' => 'Sensitive Guest']],
            'done_at' => 'now',
        ],
    ];
    $GLOBALS['pge_h0_user_id'] = 0;
    $GLOBALS['pge_h0_admin'] = false;
    $GLOBALS['pge_h0_editable'] = [];
    $GLOBALS['pge_h0_logged_in'] = false;
    $GLOBALS['pge_h0_nonce_valid'] = false;
    $GLOBALS['pge_h0_option_reads'] = 0;
    $_POST = [];
}

function pge_h0_ajax(callable $call): PGE_H0_Ajax_Stop
{
    try {
        $call();
    } catch (PGE_H0_Ajax_Stop $stop) {
        return $stop;
    }
    throw new RuntimeException('AJAX handler returned without a JSON/wp_die stop');
}

function pge_h0_prepare_request(int $user_id, int $event_id, bool $logged_in = true, bool $nonce = true): void
{
    $GLOBALS['pge_h0_user_id'] = $user_id;
    $GLOBALS['pge_h0_logged_in'] = $logged_in;
    $GLOBALS['pge_h0_nonce_valid'] = $nonce;
    $_POST = ['nonce' => 'test', 'event_id' => $event_id];
}

function pge_h0_named_function_tokens(string $file, string $function_name): string
{
    $tokens = token_get_all(file_get_contents($file));
    $capture = false;
    $named = false;
    $depth = 0;
    $body = '';
    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (!$capture && is_array($token) && $token[0] === T_FUNCTION) {
            $capture = true;
            $named = false;
            $body = $text;
            continue;
        }
        if (!$capture) continue;
        $body .= $text;
        if (!$named && is_array($token) && $token[0] === T_STRING) {
            if ($text !== $function_name) {
                $capture = false;
                $body = '';
                continue;
            }
            $named = true;
        }
        if ($named && $text === '{') $depth++;
        if ($named && $text === '}') {
            $depth--;
            if ($depth === 0) return $body;
        }
    }
    return '';
}

// Child process: prove the authorized run-now path reaches cron processing.
if (($argv[1] ?? '') === 'run-now-owner' || ($argv[1] ?? '') === 'run-now-admin') {
    pge_h0_reset();
    pge_h0_prepare_request(($argv[1] === 'run-now-owner') ? 10 : 99, 100);
    $GLOBALS['pge_h0_admin'] = ($argv[1] === 'run-now-admin');
    (new PGE_H0_UltraMsg_Handler())->ajax_run_now();
    exit(2);
}

$total = 0;
$passed = 0;
$failures = [];
function check(string $label, $actual, $expected): void
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) {
        $passed++;
        echo "PASS  {$label}\n";
        return;
    }
    $failures[] = $label;
    echo "FAIL  {$label} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
}
function check_true(string $label, $actual): void { check($label, (bool) $actual, true); }
function check_false(string $label, $actual): void { check($label, (bool) $actual, false); }

// A. Canonical owner/admin authorization.
pge_h0_reset();
check_false('A1 zero event id is rejected', pge_is_host_or_admin(0));
check_false('A2 negative event id is rejected', pge_is_host_or_admin(-100));
check_false('A3 missing post is rejected', pge_is_host_or_admin(999));
check_false('A4 non-event post type is rejected', pge_is_host_or_admin(200));
$GLOBALS['pge_h0_user_id'] = 12;
check_false('A5 ordinary user is rejected', pge_is_host_or_admin(100));
$GLOBALS['pge_h0_user_id'] = 11;
check_false('A6 owner of another event is rejected', pge_is_host_or_admin(100));
$GLOBALS['pge_h0_user_id'] = 10;
check_true('A7 event owner is accepted', pge_is_host_or_admin(100));
$GLOBALS['pge_h0_user_id'] = 99;
$GLOBALS['pge_h0_admin'] = true;
check_true('A8 administrator is accepted', pge_is_host_or_admin(100));
check_false('A9 administrator cannot turn a non-event into an event', pge_is_host_or_admin(200));

// B. Transitional guest-management authorization retains edit_post.
pge_h0_reset();
check_false('B1 invalid guest-management event is rejected', pge_event_guests_user_can_manage(999));
check_false('B2 non-event guest-management post is rejected', pge_event_guests_user_can_manage(200));
$GLOBALS['pge_h0_user_id'] = 10;
check_true('B3 event owner can manage guests', pge_event_guests_user_can_manage(100));
$GLOBALS['pge_h0_user_id'] = 99;
$GLOBALS['pge_h0_admin'] = true;
check_true('B4 administrator can manage guests', pge_event_guests_user_can_manage(100));
$GLOBALS['pge_h0_admin'] = false;
$GLOBALS['pge_h0_editable'] = [100];
check_true('B5 edit_post compatibility remains for guest management', pge_event_guests_user_can_manage(100));
$GLOBALS['pge_h0_editable'] = [];
check_false('B6 user without authority cannot manage guests', pge_event_guests_user_can_manage(100));

function pge_h0_status_rejection_cases(string $prefix, object $handler): void
{
    $cases = [
        ['logged-out', 10, 100, false, true, false],
        ['invalid-nonce', 10, 100, true, false, false],
        ['ordinary-user', 12, 100, true, true, false],
        ['other-event-owner', 11, 100, true, true, false],
        ['non-event', 10, 200, true, true, false],
        ['missing-event', 10, 999, true, true, false],
    ];
    foreach ($cases as [$name, $user, $event, $logged, $nonce]) {
        pge_h0_reset();
        pge_h0_prepare_request($user, $event, $logged, $nonce);
        $stop = pge_h0_ajax([$handler, 'ajax_queue_status']);
        check_false("{$prefix} {$name} is rejected", $stop->success);
        check("{$prefix} {$name} does not read queue", $GLOBALS['pge_h0_option_reads'], 0);
        check_false("{$prefix} {$name} leaks no report", isset($stop->data['report']));
    }
}

// C/D. Queue status protection and successful owner/admin access.
pge_h0_status_rejection_cases('C Cartat', new PGE_H0_Cartat_Handler());
pge_h0_reset();
pge_h0_prepare_request(10, 100);
$stop = pge_h0_ajax([new PGE_H0_Cartat_Handler(), 'ajax_queue_status']);
check_true('C owner can read Cartat queue status', $stop->success);
check('C owner receives expected report only after authorization', $stop->data['report'][0]['name'] ?? '', 'Sensitive Guest');
pge_h0_reset();
pge_h0_prepare_request(99, 100);
$GLOBALS['pge_h0_admin'] = true;
$stop = pge_h0_ajax([new PGE_H0_Cartat_Handler(), 'ajax_queue_status']);
check_true('C administrator can read Cartat queue status', $stop->success);

pge_h0_status_rejection_cases('D UltraMsg', new PGE_H0_UltraMsg_Handler());
pge_h0_reset();
pge_h0_prepare_request(10, 100);
$stop = pge_h0_ajax([new PGE_H0_UltraMsg_Handler(), 'ajax_queue_status']);
check_true('D owner can read UltraMsg queue status', $stop->success);
check('D provider is returned only after authorization', $stop->data['provider'] ?? '', 'ultramsg');
pge_h0_reset();
pge_h0_prepare_request(99, 100);
$GLOBALS['pge_h0_admin'] = true;
$stop = pge_h0_ajax([new PGE_H0_UltraMsg_Handler(), 'ajax_queue_status']);
check_true('D administrator can read UltraMsg queue status', $stop->success);

// E. UltraMsg run-now rejects before cron/state processing.
$run_now_cases = [
    ['logged-out', 10, 100, false, true],
    ['invalid-nonce', 10, 100, true, false],
    ['ordinary-user', 12, 100, true, true],
    ['other-event-owner', 11, 100, true, true],
    ['non-event', 10, 200, true, true],
    ['missing-event', 10, 999, true, true],
];
foreach ($run_now_cases as [$name, $user, $event, $logged, $nonce]) {
    pge_h0_reset();
    pge_h0_prepare_request($user, $event, $logged, $nonce);
    $handler = new PGE_H0_UltraMsg_Handler();
    $stop = pge_h0_ajax([$handler, 'ajax_run_now']);
    check_false("E run-now {$name} is rejected", $stop->success);
    check("E run-now {$name} does not invoke cron", $handler->cron_calls, 0);
    check("E run-now {$name} does not read queue", $GLOBALS['pge_h0_option_reads'], 0);
}

$php = escapeshellarg(PHP_BINARY);
$self = escapeshellarg(__FILE__);
exec("{$php} {$self} run-now-owner", $owner_output, $owner_code);
check('E owner run-now child exits successfully', $owner_code, 0);
check_true('E owner run-now reaches cron', str_contains(implode("\n", $owner_output), 'H0_CRON:100'));
exec("{$php} {$self} run-now-admin", $admin_output, $admin_code);
check('E administrator run-now child exits successfully', $admin_code, 0);
check_true('E administrator run-now reaches cron', str_contains(implode("\n", $admin_output), 'H0_CRON:100'));

// F. Theme fallbacks preserve the same owner/admin-only event contract.
foreach ([
    __DIR__ . '/../../../themes/pgevents-pro/template-parts/event/rsvp.php',
    __DIR__ . '/../../../themes/pgevents-pro/template-parts/event/access-gate.php',
] as $template) {
    $body = pge_h0_named_function_tokens($template, 'pge_is_host_or_admin');
    check_true('F fallback function exists: ' . basename($template), $body !== '');
    check_true('F fallback validates post type: ' . basename($template), str_contains($body, 'get_post_type'));
    check_false('F fallback does not grant edit_post: ' . basename($template), str_contains($body, 'edit_post'));
}

// G. REST remains unchanged and sensitive plugin data is not registered.
$cpt_source = file_get_contents(__DIR__ . '/../includes/cpts.php');
check_true('G pge_event remains exposed through its existing REST contract', str_contains($cpt_source, "'show_in_rest'       => true"));
check_false('G H0 does not introduce capability_type', str_contains($cpt_source, 'capability_type'));
check_false('G H0 does not introduce map_meta_cap', str_contains($cpt_source, 'map_meta_cap'));
$sensitive_rest_registration = false;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__)));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    if (str_contains(str_replace('\\', '/', $file->getPathname()), '/tests/')) continue;
    $source = file_get_contents($file->getPathname());
    if (
        preg_match('/register_(?:post_)?meta\s*\(/', $source)
        || str_contains($source, 'register_rest_field')
    ) {
        if (preg_match('/_pge_invited|pge_wa_queue|guests_map/', $source)) {
            $sensitive_rest_registration = true;
            break;
        }
    }
}
check_false('G sensitive guest/queue fields are not registered for REST', $sensitive_rest_registration);

echo "\nResult: {$passed}/{$total} passed\n";
if ($failures) {
    echo 'Failures: ' . implode(', ', $failures) . "\n";
    exit(1);
}
exit(0);
