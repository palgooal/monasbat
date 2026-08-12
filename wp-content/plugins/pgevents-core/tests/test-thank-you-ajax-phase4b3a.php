<?php
/**
 * Standalone verification for Phase 4B-3A Thank You AJAX endpoints.
 * Production handlers are loaded; authorization and messaging boundaries are
 * deterministic fakes. No database, Cron, Claim, or Transport is executed.
 *
 * Run: php tests/test-thank-you-ajax-phase4b3a.php
 */

define('ABSPATH', __DIR__ . '/');

$total = 0;
$passed = 0;
$failures = [];

function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) {
        $passed++;
        return;
    }
    $failures[] = $label . ' — expected: ' . var_export($expected, true)
        . ' | actual: ' . var_export($actual, true);
}

function check_true($label, $condition) { check($label, (bool) $condition, true); }

class TY_Ajax_Response extends Exception
{
    public $response;
    public function __construct(array $response)
    {
        parent::__construct('ajax_response');
        $this->response = $response;
    }
}

$GLOBALS['ty_ajax_hooks'] = [];
$GLOBALS['ty_ajax_logged_in'] = true;
$GLOBALS['ty_ajax_nonce_valid'] = true;
$GLOBALS['ty_ajax_user_id'] = 77;
$GLOBALS['ty_ajax_events'] = [101 => 77, 202 => 88];
$GLOBALS['ty_ajax_manage'] = [101 => true, 202 => false];
$GLOBALS['ty_ajax_claims'] = 0;
$GLOBALS['ty_ajax_log_writes'] = 0;
$GLOBALS['ty_ajax_sends'] = 0;

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['ty_ajax_hooks'][$hook] = $callback;
}
function add_filter() {}
function sanitize_text_field($value) { return trim((string) $value); }
function wp_unslash($value) { return $value; }
function get_current_user_id() { return (int) $GLOBALS['ty_ajax_user_id']; }
function is_user_logged_in() { return (bool) $GLOBALS['ty_ajax_logged_in']; }
function wp_verify_nonce($nonce, $action)
{
    return $GLOBALS['ty_ajax_nonce_valid'] && $nonce === 'valid-nonce'
        && $action === 'pge_event_manage_nonce';
}
function get_post_type($event_id)
{
    return isset($GLOBALS['ty_ajax_events'][(int) $event_id]) ? 'pge_event' : null;
}
function pge_event_guests_user_can_manage($event_id)
{
    return !empty($GLOBALS['ty_ajax_manage'][(int) $event_id]);
}
function get_post($event_id)
{
    return get_post_type($event_id) === 'pge_event'
        ? (object) ['ID' => (int) $event_id, 'post_title' => 'مناسبة الاختبار']
        : null;
}
function get_post_meta($event_id, $key, $single = false)
{
    return $key === '_pge_event_date' ? '2026-09-20T18:00' : '';
}
function date_i18n($format, $timestamp = null) { return '20 September 2026 — 6:00 pm'; }
function wp_send_json_success($data = null) { throw new TY_Ajax_Response(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null) { throw new TY_Ajax_Response(['success' => false, 'data' => $data]); }

function pge_mgmt_validate_request()
{
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'غير مصرح', 'reason' => 'not_logged_in']);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'pge_event_manage_nonce')) {
        wp_send_json_error(['message' => 'رمز الأمان غير صالح', 'reason' => 'invalid_nonce']);
    }
    $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
    if (!$event_id || get_post_type($event_id) !== 'pge_event') {
        wp_send_json_error(['message' => 'مناسبة غير صالحة', 'reason' => 'invalid_event']);
    }
    if (!pge_event_guests_user_can_manage($event_id)) {
        wp_send_json_error(['message' => 'ليس لديك صلاحية إدارة هذه المناسبة', 'reason' => 'forbidden']);
    }
    return $event_id;
}

class PGE_Message_Type
{
    const THANK_YOU = 'thank_you';
    const REMINDER = 'reminder';
}

class PGE_Message_Recipient_Resolver
{
    const FILTER_PENDING = 'pending';
    public static $calls = [];
    public static $result = [];
    public static function normalize_filter($filter) { return (string) $filter; }
    public static function count($event_id, $type, $filter) { return 0; }
    public static function resolve(int $event_id, string $type, string $filter): array
    {
        self::$calls[] = compact('event_id', 'type', 'filter');
        return self::$result;
    }
}

class PGE_Message_Content_Resolver
{
    public static $calls = [];
    public static function resolve($type, $event_id, array $context): array
    {
        self::$calls[] = compact('type', 'event_id', 'context');
        return ['text' => 'شكراً لحضوركم مناسبة الاختبار.'];
    }
}

class PGE_Reminder_Message_Service
{
    public static function resolve_event_featured_image_url($event_id) { return null; }
    public static function send_reminder_batch() { return ['result' => 'error']; }
    public static function batch_status() { return ['total' => 0, 'sent' => 0, 'failed' => 0, 'ambiguous' => 0, 'pending' => 0]; }
}

class PGE_Thank_You_Batch_Store
{
    const STATUS_ACTIVE = 'active';
    const ITEM_QUEUED = 'queued';
    const ITEM_PROCESSING = 'processing';
    const ITEM_WAITING = 'waiting';

    public static $manifests = [];
    public static $active_by_event = [];

    public static function get_active_batch_id(int $event_id): string
    {
        return (string) (self::$active_by_event[$event_id] ?? '');
    }

    public static function get(string $batch_id): ?array
    {
        return self::$manifests[$batch_id] ?? null;
    }
}

class PGE_Thank_You_Claim
{
    public static $sent = [];
    public static $can_send = [];
    public static $reads = 0;

    public static function is_sent($rsvp_id): bool
    {
        self::$reads++;
        return !empty(self::$sent[(int) $rsvp_id]);
    }

    public static function can_send($rsvp_id): bool
    {
        self::$reads++;
        return self::$can_send[(int) $rsvp_id] ?? true;
    }
}

class PGE_Thank_You_Batch_Worker
{
    public static $next = 1;
    public static $create_calls = [];
    public static $status_calls = [];
    public static $create_plan = null;
    public static $statuses = [];
    public static $scheduled = 0;

    public static function create_batch(int $event_id, int $actor_user_id): array
    {
        self::$create_calls[] = compact('event_id', 'actor_user_id');
        if (self::$create_plan instanceof Throwable) throw self::$create_plan;
        if (is_array(self::$create_plan)) return self::$create_plan;

        $batch_id = 'server-batch-' . self::$next++;
        $status = status_fixture($batch_id, ['total' => 2, 'queued' => 2]);
        PGE_Thank_You_Batch_Store::$manifests[$batch_id] = [
            'batch_id' => $batch_id,
            'event_id' => $event_id,
            'actor_user_id' => $actor_user_id,
            'items' => [
                ['rsvp_id' => 9001, 'lifecycle_started_at' => 'SECRET-LIFECYCLE'],
            ],
        ];
        self::$statuses[$batch_id] = $status;
        self::$scheduled++;
        return ['result' => 'started', 'batch_id' => $batch_id, 'status' => $status];
    }

    public static function get_status(int $event_id, string $batch_id): ?array
    {
        self::$status_calls[] = compact('event_id', 'batch_id');
        $manifest = PGE_Thank_You_Batch_Store::$manifests[$batch_id] ?? null;
        if (!is_array($manifest) || (int) $manifest['event_id'] !== $event_id) return null;
        return self::$statuses[$batch_id] ?? null;
    }
}

function status_fixture(string $batch_id, array $overrides = []): array
{
    return array_merge([
        'batch_id' => $batch_id,
        'total' => 0,
        'queued' => 0,
        'processing' => 0,
        'waiting' => 0,
        'sent' => 0,
        'failed' => 0,
        'ambiguous' => 0,
        'skipped' => 0,
        'skipped_reasons' => [],
        'complete' => false,
        'started_at' => '2026-09-20 10:00:00',
        'updated_at' => '2026-09-20 10:00:00',
        'completed_at' => '',
    ], $overrides);
}

require_once dirname(__DIR__) . '/includes/invitation-management-ajax.php';

function ajax_post(int $event_id = 101, array $extra = []): array
{
    return array_merge(['nonce' => 'valid-nonce', 'event_id' => $event_id], $extra);
}

function call_ajax(string $handler): array
{
    try {
        $handler();
    } catch (TY_Ajax_Response $response) {
        return $response->response;
    }
    throw new RuntimeException('AJAX handler returned without JSON response');
}

function reset_security(): void
{
    $GLOBALS['ty_ajax_logged_in'] = true;
    $GLOBALS['ty_ajax_nonce_valid'] = true;
    $GLOBALS['ty_ajax_manage'][101] = true;
}

// Hook surface: authenticated wp_ajax only.
check_true('preview action registered', isset($GLOBALS['ty_ajax_hooks']['wp_ajax_pge_invitation_mgmt_thank_you_preview']));
check_true('start action registered', isset($GLOBALS['ty_ajax_hooks']['wp_ajax_pge_invitation_mgmt_thank_you_start']));
check_true('status action registered', isset($GLOBALS['ty_ajax_hooks']['wp_ajax_pge_invitation_mgmt_thank_you_status']));
check('no nopriv preview action', isset($GLOBALS['ty_ajax_hooks']['wp_ajax_nopriv_pge_invitation_mgmt_thank_you_preview']), false);
check_true('Thank You AJAX gate enabled', defined('PGE_INVITATION_MGMT_THANK_YOU_ENABLED') && PGE_INVITATION_MGMT_THANK_YOU_ENABLED);

// Preview.
PGE_Message_Recipient_Resolver::$result = [
    'recipients' => [
        ['phone' => '966500000001', 'rsvp_id' => 55, 'lifecycle_started_at' => '2026-09-20 10:00:01', 'name' => 'Private Guest'],
        ['phone' => '966500000002', 'rsvp_id' => 56, 'lifecycle_started_at' => '2026-09-20 10:00:02', 'name' => 'Private Guest 2'],
        ['phone' => '966500000003', 'rsvp_id' => 57, 'lifecycle_started_at' => '2026-09-20 10:00:03', 'name' => 'Private Guest 3'],
    ],
    'eligible' => 3,
    'total_current_invitations' => 9,
    'skipped_invalid_phone' => 1,
    'skipped_cancelled' => 1,
    'skipped_not_checked_in' => 2,
    'skipped_no_rsvp' => 1,
    'skipped_integrity_error' => 1,
    'skipped_stale_lifecycle' => 0,
];
$_POST = ajax_post();
$claims_before = $GLOBALS['ty_ajax_claims'];
$sends_before = $GLOBALS['ty_ajax_sends'];
$preview = call_ajax('pge_invitation_mgmt_thank_you_preview_handler');
check('valid preview succeeds', $preview['success'], true);
check('preview eligible count', $preview['data']['eligible'], 3);
check('all unsent eligible are ready', $preview['data']['ready_to_send'], 3);
check('all unsent has no already-sent recipients', $preview['data']['already_sent'], 0);
check('all unsent has no in-progress recipients', $preview['data']['in_progress'], 0);
check('all unsent has no active batch', $preview['data']['active_batch'], false);
check('all unsent invariant', $preview['data']['ready_to_send'] + $preview['data']['already_sent'] + $preview['data']['in_progress'], $preview['data']['eligible']);
check('preview invitation total', $preview['data']['total_current_invitations'], 9);
check('preview cancelled count', $preview['data']['skipped_cancelled'], 1);
check('preview not checked-in count', $preview['data']['skipped_not_checked_in'], 2);
check('preview no-RSVP count', $preview['data']['skipped_no_rsvp'], 1);
check('preview integrity count', $preview['data']['skipped_integrity_error'], 1);
check('preview text is safe sample', $preview['data']['preview_text'], 'شكراً لحضوركم مناسبة الاختبار.');
$preview_json = json_encode($preview['data']);
check('preview excludes phone PII', strpos($preview_json, '966500000001') === false, true);
check('preview excludes guest list', strpos($preview_json, 'Private Guest') === false, true);
check('preview excludes RSVP ids', strpos($preview_json, '"rsvp_id"') === false, true);
check('preview excludes lifecycle markers', strpos($preview_json, 'lifecycle_started_at') === false, true);
check('preview excludes raw manifest', strpos($preview_json, 'items') === false, true);
check('preview causes no Claim', $GLOBALS['ty_ajax_claims'], $claims_before);
check('preview causes no Message Log writes', $GLOBALS['ty_ajax_log_writes'], 0);
check('preview causes no Send', $GLOBALS['ty_ajax_sends'], $sends_before);
check('preview resolver type', end(PGE_Message_Recipient_Resolver::$calls)['type'], 'thank_you');
check('preview resolver intent', end(PGE_Message_Recipient_Resolver::$calls)['filter'], 'checked_in');

// Advisory delivery classification. Claim/Message Log remain read-only here.
PGE_Thank_You_Claim::$sent = [56 => true];
$_POST = ajax_post();
$some_sent = call_ajax('pge_invitation_mgmt_thank_you_preview_handler');
check('some sent ready count', $some_sent['data']['ready_to_send'], 2);
check('some sent already-sent count', $some_sent['data']['already_sent'], 1);
check('some sent invariant', $some_sent['data']['ready_to_send'] + $some_sent['data']['already_sent'] + $some_sent['data']['in_progress'], 3);

PGE_Thank_You_Claim::$sent = [55 => true, 56 => true, 57 => true];
$_POST = ajax_post();
$all_sent = call_ajax('pge_invitation_mgmt_thank_you_preview_handler');
check('all sent has zero ready', $all_sent['data']['ready_to_send'], 0);
check('all sent count', $all_sent['data']['already_sent'], 3);

PGE_Thank_You_Claim::$sent = [];
PGE_Thank_You_Claim::$can_send = [55 => false];
$_POST = ajax_post();
$active_claim = call_ajax('pge_invitation_mgmt_thank_you_preview_handler');
check('active claim is in progress', $active_claim['data']['in_progress'], 1);
check('active claim is not ready', $active_claim['data']['ready_to_send'], 2);

PGE_Thank_You_Claim::$can_send = [55 => true];
$_POST = ajax_post();
$stale_claim = call_ajax('pge_invitation_mgmt_thank_you_preview_handler');
check('stale claim is reclaimable and ready', $stale_claim['data']['ready_to_send'], 3);
check('stale claim is not in progress', $stale_claim['data']['in_progress'], 0);

PGE_Thank_You_Claim::$can_send = [55 => false];
$_POST = ajax_post();
$ambiguous_active = call_ajax('pge_invitation_mgmt_thank_you_preview_handler');
check('ambiguous active lease is in progress', $ambiguous_active['data']['in_progress'], 1);
PGE_Thank_You_Claim::$can_send = [55 => true];
$_POST = ajax_post();
$ambiguous_expired = call_ajax('pge_invitation_mgmt_thank_you_preview_handler');
check('ambiguous expired lease is ready', $ambiguous_expired['data']['ready_to_send'], 3);

PGE_Thank_You_Claim::$can_send = [];
PGE_Thank_You_Batch_Store::$active_by_event[101] = 'active-preview-batch';
PGE_Thank_You_Batch_Store::$manifests['active-preview-batch'] = [
    'batch_id' => 'active-preview-batch',
    'event_id' => 101,
    'status' => PGE_Thank_You_Batch_Store::STATUS_ACTIVE,
    'items' => [[
        'rsvp_id' => 55,
        'lifecycle_started_at' => '2026-09-20 10:00:01',
        'status' => PGE_Thank_You_Batch_Store::ITEM_QUEUED,
    ]],
];
$_POST = ajax_post();
$active_batch_preview = call_ajax('pge_invitation_mgmt_thank_you_preview_handler');
check('active batch flag', $active_batch_preview['data']['active_batch'], true);
check('active batch queued recipient is in progress', $active_batch_preview['data']['in_progress'], 1);
check('active batch response remains private', strpos(json_encode($active_batch_preview['data']), 'active-preview-batch') === false, true);
PGE_Thank_You_Batch_Store::$active_by_event = [];
unset(PGE_Thank_You_Batch_Store::$manifests['active-preview-batch']);

// A reset/new lifecycle is represented by the Resolver's current recipient and
// a cleared sent marker; the old source identity is absent after phone change.
PGE_Message_Recipient_Resolver::$result['recipients'] = [[
    'phone' => '966500000099',
    'rsvp_id' => 99,
    'lifecycle_started_at' => '2026-09-21 12:00:00',
    'name' => 'Current lifecycle',
]];
PGE_Message_Recipient_Resolver::$result['eligible'] = 1;
PGE_Thank_You_Claim::$sent = [];
$_POST = ajax_post();
$new_lifecycle = call_ajax('pge_invitation_mgmt_thank_you_preview_handler');
check('new lifecycle after reset is ready', $new_lifecycle['data']['ready_to_send'], 1);
check('new lifecycle does not inherit already-sent', $new_lifecycle['data']['already_sent'], 0);
check('phone-change target lifecycle is the only classified recipient', $new_lifecycle['data']['ready_to_send'] + $new_lifecycle['data']['already_sent'] + $new_lifecycle['data']['in_progress'], 1);

PGE_Message_Recipient_Resolver::$result['recipients'] = [];
PGE_Message_Recipient_Resolver::$result['eligible'] = 0;
$_POST = ajax_post();
$none_eligible = call_ajax('pge_invitation_mgmt_thank_you_preview_handler');
check('no eligible has zero ready', $none_eligible['data']['ready_to_send'], 0);
check('no eligible invariant', $none_eligible['data']['ready_to_send'] + $none_eligible['data']['already_sent'] + $none_eligible['data']['in_progress'], 0);
check('classification performs no Claim writes', $GLOBALS['ty_ajax_claims'], $claims_before);
check('classification performs no Message Log writes', $GLOBALS['ty_ajax_log_writes'], 0);

PGE_Message_Recipient_Resolver::$result['recipients'] = [
    ['phone' => '966500000001', 'rsvp_id' => 55, 'lifecycle_started_at' => '2026-09-20 10:00:01', 'name' => 'Private Guest'],
];
PGE_Message_Recipient_Resolver::$result['eligible'] = 1;
PGE_Thank_You_Claim::$sent = [];
PGE_Thank_You_Claim::$can_send = [];

$GLOBALS['ty_ajax_nonce_valid'] = false;
$_POST = ajax_post();
check('preview invalid nonce rejected', call_ajax('pge_invitation_mgmt_thank_you_preview_handler')['data']['reason'], 'invalid_nonce');
reset_security();
$GLOBALS['ty_ajax_logged_in'] = false;
$_POST = ajax_post();
check('preview unauthenticated rejected', call_ajax('pge_invitation_mgmt_thank_you_preview_handler')['data']['reason'], 'not_logged_in');
reset_security();
$_POST = ajax_post(999);
check('preview invalid event rejected', call_ajax('pge_invitation_mgmt_thank_you_preview_handler')['data']['reason'], 'invalid_event');
$_POST = ajax_post(202);
check('preview unauthorized event rejected', call_ajax('pge_invitation_mgmt_thank_you_preview_handler')['data']['reason'], 'forbidden');
reset_security();

// Start: only event and current actor reach the server authority.
PGE_Thank_You_Batch_Worker::$create_plan = null;
$_POST = ajax_post(101, [
    'batch_id' => 'forged-batch',
    'recipients' => [['phone' => '111']],
    'phone' => '111',
    'rsvp_id' => 999,
    'lifecycle_started_at' => 'FORGED',
    'message' => 'FORGED MESSAGE',
    'image_url' => 'https://example.invalid/image.jpg',
    'provider' => 'ultramsg',
    'credit_type' => 'invitation',
]);
$claims_before = $GLOBALS['ty_ajax_claims'];
$sends_before = $GLOBALS['ty_ajax_sends'];
$start = call_ajax('pge_invitation_mgmt_thank_you_start_handler');
$started_batch = $start['data']['batch_id'];
check('valid start succeeds', $start['success'], true);
check_true('batch id generated server-side', strpos($started_batch, 'server-batch-') === 0);
check('forged batch id ignored', $started_batch === 'forged-batch', false);
check('start forwards event only', end(PGE_Thank_You_Batch_Worker::$create_calls)['event_id'], 101);
check('start forwards current actor only', end(PGE_Thank_You_Batch_Worker::$create_calls)['actor_user_id'], 77);
check('start response marks new batch', $start['data']['existing'], false);
check('start initial queued summary', $start['data']['status']['queued'], 2);
check('start performs no Claim', $GLOBALS['ty_ajax_claims'], $claims_before);
check('start performs no synchronous Send', $GLOBALS['ty_ajax_sends'], $sends_before);
check_true('start schedules worker through batch authority', PGE_Thank_You_Batch_Worker::$scheduled > 0);
check('manifest created by server authority', isset(PGE_Thank_You_Batch_Store::$manifests[$started_batch]), true);
check('forged recipients not stored', strpos(json_encode(PGE_Thank_You_Batch_Store::$manifests[$started_batch]), '111') === false, true);

PGE_Thank_You_Batch_Worker::$create_plan = ['result' => 'no_eligible', 'batch_id' => null];
$_POST = ajax_post();
check('no eligible start contract', call_ajax('pge_invitation_mgmt_thank_you_start_handler')['data']['reason'], 'no_eligible');
PGE_Thank_You_Batch_Worker::$create_plan = [
    'result' => 'active_batch_exists',
    'batch_id' => $started_batch,
    'status' => PGE_Thank_You_Batch_Worker::$statuses[$started_batch],
];
$_POST = ajax_post();
$active = call_ajax('pge_invitation_mgmt_thank_you_start_handler');
check('second active batch returns success', $active['success'], true);
check('second active batch returns existing id', $active['data']['batch_id'], $started_batch);
check('second active batch marked existing', $active['data']['existing'], true);
PGE_Thank_You_Batch_Worker::$create_plan = ['result' => 'busy', 'reason' => 'operation_locked'];
$_POST = ajax_post();
check('concurrent start maps to batch_in_progress', call_ajax('pge_invitation_mgmt_thank_you_start_handler')['data']['reason'], 'batch_in_progress');
PGE_Thank_You_Batch_Worker::$create_plan = new RuntimeException('private internal detail');
$_POST = ajax_post();
$internal = call_ajax('pge_invitation_mgmt_thank_you_start_handler');
check('start exception uses internal contract', $internal['data']['reason'], 'internal_error');
check('start exception does not leak detail', strpos(json_encode($internal), 'private internal detail') === false, true);
PGE_Thank_You_Batch_Worker::$create_plan = null;

$GLOBALS['ty_ajax_nonce_valid'] = false;
$_POST = ajax_post();
check('start invalid nonce rejected', call_ajax('pge_invitation_mgmt_thank_you_start_handler')['data']['reason'], 'invalid_nonce');
reset_security();
$_POST = ajax_post(202);
check('start unauthorized rejected', call_ajax('pge_invitation_mgmt_thank_you_start_handler')['data']['reason'], 'forbidden');
reset_security();

// Status: ownership, progress, terminal states, privacy, and read-only behavior.
$_POST = ajax_post();
check('status missing batch id rejected', call_ajax('pge_invitation_mgmt_thank_you_status_handler')['data']['reason'], 'missing_batch_id');
$_POST = ajax_post(101, ['batch_id' => 'missing']);
check('status unknown batch rejected', call_ajax('pge_invitation_mgmt_thank_you_status_handler')['data']['reason'], 'batch_not_found');
PGE_Thank_You_Batch_Store::$manifests['other-event'] = ['event_id' => 202, 'items' => []];
PGE_Thank_You_Batch_Worker::$statuses['other-event'] = status_fixture('other-event');
$_POST = ajax_post(101, ['batch_id' => 'other-event']);
check('status event mismatch rejected', call_ajax('pge_invitation_mgmt_thank_you_status_handler')['data']['reason'], 'batch_event_mismatch');

PGE_Thank_You_Batch_Worker::$statuses[$started_batch] = status_fixture($started_batch, [
    'total' => 8,
    'queued' => 1,
    'processing' => 1,
    'waiting' => 1,
    'sent' => 2,
    'failed' => 1,
    'ambiguous' => 1,
    'skipped' => 1,
    'skipped_reasons' => ['already_sent' => 1],
]);
$manifest_before = PGE_Thank_You_Batch_Store::$manifests;
$scheduled_before = PGE_Thank_You_Batch_Worker::$scheduled;
$claims_before = $GLOBALS['ty_ajax_claims'];
$sends_before = $GLOBALS['ty_ajax_sends'];
$_POST = ajax_post(101, [
    'batch_id' => $started_batch,
    'phone' => 'FORGED',
    'message' => 'FORGED',
    'provider' => 'ultramsg',
]);
$partial = call_ajax('pge_invitation_mgmt_thank_you_status_handler');
check('valid status succeeds', $partial['success'], true);
check('status total', $partial['data']['total'], 8);
check('status queued', $partial['data']['queued'], 1);
check('status processing', $partial['data']['processing'], 1);
check('status waiting', $partial['data']['waiting'], 1);
check('status sent', $partial['data']['sent'], 2);
check('status failed', $partial['data']['failed'], 1);
check('status ambiguous', $partial['data']['ambiguous'], 1);
check('status skipped', $partial['data']['skipped'], 1);
check('status skipped breakdown', $partial['data']['skipped_reasons']['already_sent'], 1);
check('partial status not complete', $partial['data']['complete'], false);
$status_json = json_encode($partial['data']);
check('status exposes no raw manifest', array_key_exists('items', $partial['data']), false);
check('status exposes no phone', strpos($status_json, 'FORGED') === false, true);
check('status exposes no RSVP id', strpos($status_json, '9001') === false, true);
check('status exposes no lifecycle marker', strpos($status_json, 'SECRET-LIFECYCLE') === false, true);
check('status is read-only for manifest', PGE_Thank_You_Batch_Store::$manifests, $manifest_before);
check('status schedules no Cron', PGE_Thank_You_Batch_Worker::$scheduled, $scheduled_before);
check('status causes no Claim', $GLOBALS['ty_ajax_claims'], $claims_before);
check('status causes no Send', $GLOBALS['ty_ajax_sends'], $sends_before);

PGE_Thank_You_Batch_Worker::$statuses[$started_batch] = status_fixture($started_batch, [
    'total' => 3,
    'sent' => 1,
    'failed' => 1,
    'ambiguous' => 1,
    'complete' => true,
]);
$_POST = ajax_post(101, ['batch_id' => $started_batch]);
$complete = call_ajax('pge_invitation_mgmt_thank_you_status_handler');
check('completed batch returns complete', $complete['data']['complete'], true);
check('completed batch preserves failed', $complete['data']['failed'], 1);
check('completed batch preserves ambiguous', $complete['data']['ambiguous'], 1);

// Static scope/security guards.
$source = file_get_contents(dirname(__DIR__) . '/includes/invitation-management-ajax.php');
check('no Thank You nopriv hook in source', strpos($source, 'wp_ajax_nopriv_pge_invitation_mgmt_thank_you') === false, true);
check('no Thank You retry endpoint', strpos($source, 'pge_invitation_mgmt_thank_you_retry') === false, true);
check('no Thank You template-save endpoint', strpos($source, 'save_thank_you_template') === false, true);
check('no direct Cartat send in Thank You handlers', strpos(substr($source, strpos($source, 'Phase 4B-3A')), 'send_text(') === false, true);
check('no credit authority in Thank You handlers', strpos(substr($source, strpos($source, 'Phase 4B-3A')), 'PGE_Invitation_Credit_Ledger') === false, true);

echo "Thank You AJAX Phase 4B-3A: {$passed}/{$total} passed\n";
if ($failures) {
    foreach ($failures as $failure) echo "FAIL: {$failure}\n";
    exit(1);
}
exit(0);
