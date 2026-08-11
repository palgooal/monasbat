<?php
/**
 * Standalone orchestration verification for Phase 4B-2.
 * Production Service + Content Resolver are loaded; external boundaries are
 * deterministic fakes for Recipient Resolver, Claim, Batch, and Cartat.
 * The real Resolver and Claim contracts remain covered by their own required
 * regression suites.
 *
 * Run: php tests/test-thank-you-message-service-phase4b2.php
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

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

$GLOBALS['ty_events'] = [];

function get_post_type($event_id)
{
    return isset($GLOBALS['ty_events'][(int) $event_id]) ? 'pge_event' : null;
}

function get_post($event_id)
{
    if (!isset($GLOBALS['ty_events'][(int) $event_id])) {
        return null;
    }
    return (object) ['ID' => (int) $event_id, 'post_title' => $GLOBALS['ty_events'][(int) $event_id]];
}

function get_post_meta($event_id, $key, $single = false)
{
    return $key === '_pge_event_date' ? '2026-08-20T18:30' : '';
}

function date_i18n($format, $timestamp = null)
{
    return '20 August 2026 — 6:30 pm';
}

function pge_norm_phone($value)
{
    return preg_replace('/\D+/', '', (string) $value);
}

function pge_wa_get_templates($event_id)
{
    return [];
}

function pge_wa_default_thank_you_template()
{
    return 'شكراً لحضوركم {{event_name}}، سعدنا بمشاركتكم.';
}

function pge_wa_render_template($template, array $vars)
{
    foreach ($vars as $key => $value) {
        $template = str_replace('{{' . $key . '}}', (string) $value, $template);
    }
    return $template;
}

require_once dirname(__DIR__) . '/includes/class-pge-message-type.php';
require_once dirname(__DIR__) . '/includes/class-pge-message-content-resolver.php';

class PGE_Message_Log
{
    const STATUS_FAILED = 'failed';
    const STATUS_AMBIGUOUS_TRANSPORT_ERROR = 'ambiguous_transport_error';
}

class PGE_Message_Batch
{
    public static $next = 1;

    public static function generate_batch_id()
    {
        return 'thank-you-batch-' . self::$next++;
    }
}

class PGE_Message_Recipient_Resolver
{
    public static $results = [];
    public static $calls = [];

    public static function resolve(int $event_id, string $message_type, string $filter): array
    {
        self::$calls[] = compact('event_id', 'message_type', 'filter');
        return self::$results[$event_id] ?? [
            'recipients' => [],
            'eligible' => 0,
            'message_type' => 'thank_you',
            'filter' => 'checked_in',
        ];
    }
}

class PGE_Thank_You_Claim
{
    const CLAIM_LEASE_SECONDS = 120;

    public static $now = 1000;
    public static $next_log_id = 1;
    public static $states = [];
    public static $logs = [];
    public static $claim_calls = [];
    public static $success_calls = [];
    public static $failure_calls = [];

    public static function seed(int $rsvp_id, string $lifecycle = 'L1', array $overrides = []): void
    {
        self::$states[$rsvp_id] = array_merge([
            'lifecycle' => $lifecycle,
            'sent' => false,
            'active_until' => 0,
            'active_lifecycle' => '',
            'claim_error' => false,
            'finalize_success' => true,
            'finalize_failure' => true,
        ], $overrides);
    }

    public static function reset_lifecycle(int $rsvp_id, string $lifecycle): void
    {
        self::$states[$rsvp_id]['lifecycle'] = $lifecycle;
        self::$states[$rsvp_id]['sent'] = false;
    }

    public static function claim($event_id, $rsvp_id, $guest_phone, $batch_id, $actor_user_id = 0, $provider = null): array
    {
        $rsvp_id = (int) $rsvp_id;
        $state = self::$states[$rsvp_id] ?? null;
        self::$claim_calls[] = [
            'event_id' => (int) $event_id,
            'rsvp_id' => $rsvp_id,
            'guest_phone' => (string) $guest_phone,
            'batch_id' => (string) $batch_id,
            'actor_user_id' => (int) $actor_user_id,
            'provider' => $provider,
            'lifecycle_started_at' => $state['lifecycle'] ?? '',
        ];
        if (!$state || $state['claim_error']) {
            return ['result' => 'error', 'reason' => 'fixture_claim_error'];
        }
        if ($state['sent']) {
            return ['result' => 'already_sent'];
        }
        if ($state['active_until'] > self::$now
            && $state['active_lifecycle'] === $state['lifecycle']) {
            return ['result' => 'already_in_progress', 'reason' => 'active_claim'];
        }

        $log_id = self::$next_log_id++;
        self::$logs[$log_id] = [
            'rsvp_id' => $rsvp_id,
            'lifecycle' => $state['lifecycle'],
            'status' => 'pending',
        ];
        return [
            'result' => 'claimed',
            'log_id' => $log_id,
            'lifecycle_started_at' => $state['lifecycle'],
        ];
    }

    public static function finalize_success($log_id, $rsvp_id): bool
    {
        self::$success_calls[] = [(int) $log_id, (int) $rsvp_id];
        $state = &self::$states[(int) $rsvp_id];
        if (!$state['finalize_success']) {
            return false;
        }
        $state['sent'] = true;
        self::$logs[(int) $log_id]['status'] = 'sent';
        return true;
    }

    public static function finalize_failure($log_id, $status = PGE_Message_Log::STATUS_FAILED): bool
    {
        self::$failure_calls[] = [(int) $log_id, (string) $status];
        $log = self::$logs[(int) $log_id] ?? null;
        if (!$log) {
            return false;
        }
        $state = &self::$states[(int) $log['rsvp_id']];
        if (!$state['finalize_failure']) {
            return false;
        }
        self::$logs[(int) $log_id]['status'] = (string) $status;
        if ($status === PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR) {
            $state['active_until'] = self::$now + self::CLAIM_LEASE_SECONDS;
            $state['active_lifecycle'] = $state['lifecycle'];
        } else {
            $state['active_until'] = 0;
            $state['active_lifecycle'] = '';
        }
        return true;
    }

    public static function is_sent($rsvp_id): bool
    {
        return !empty(self::$states[(int) $rsvp_id]['sent']);
    }
}

class PGE_Cartat_Transport
{
    public static $credentials = true;
    public static $plans = [];
    public static $text_calls = [];
    public static $media_calls = [];

    public function has_credentials(): bool
    {
        return self::$credentials;
    }

    public function format_number(string $phone): string
    {
        return pge_norm_phone($phone);
    }

    public function send_text(string $number, string $message): ?array
    {
        self::$text_calls[] = compact('number', 'message');
        $plan = array_shift(self::$plans);
        if ($plan instanceof \Throwable) {
            throw $plan;
        }
        return $plan;
    }

    public function send_media(string $number, string $media_url, string $caption = ''): ?array
    {
        self::$media_calls[] = compact('number', 'media_url', 'caption');
        return ['status' => 'sent'];
    }

    public function interpret_result($result): string
    {
        if ($result === null) {
            return 'transport_error';
        }
        if ((isset($result['status']) && $result['status'] === 'error')
            || (isset($result['success']) && $result['success'] === false)) {
            return 'rejected';
        }
        return 'accepted';
    }
}

class PGE_Thank_You_Transport_Factory
{
    public static function resolve(): PGE_Cartat_Transport
    {
        return new PGE_Cartat_Transport();
    }
}

require_once dirname(__DIR__) . '/includes/class-pge-thank-you-message-service.php';

function add_event(int $event_id, string $name, array $recipients): void
{
    $GLOBALS['ty_events'][$event_id] = $name;
    PGE_Message_Recipient_Resolver::$results[$event_id] = [
        'recipients' => $recipients,
        'total_current_invitations' => count($recipients),
        'eligible' => count($recipients),
        'skipped_integrity_error' => 0,
        'message_type' => 'thank_you',
        'filter' => 'checked_in',
    ];
}

function recipient(string $phone, int $rsvp_id, string $name = ''): array
{
    return compact('phone', 'rsvp_id', 'name');
}

function send_event(int $event_id): array
{
    return PGE_Thank_You_Message_Service::send_thank_you_batch($event_id, 77);
}

// Accepted delivery and once-only boundary.
add_event(1, 'مناسبة القبول', [recipient('966500000001', 101, 'ضيف أول')]);
PGE_Thank_You_Claim::seed(101);
PGE_Cartat_Transport::$plans[] = ['status' => 'sent'];
$accepted = send_event(1);
check('accepted result completes', $accepted['result'], 'completed');
check('eligible recipient counted', $accepted['eligible'], 1);
check('recipient is claimed first', $accepted['claimed'], 1);
check('accepted recipient counted sent', $accepted['sent'], 1);
check('Cartat text called once', count(PGE_Cartat_Transport::$text_calls), 1);
check('Cartat receives normalized number', PGE_Cartat_Transport::$text_calls[0]['number'], '966500000001');
check('exact Thank You text reaches send_text', PGE_Cartat_Transport::$text_calls[0]['message'], 'شكراً لحضوركم مناسبة القبول، سعدنا بمشاركتكم.');
check('accepted invokes finalize_success', count(PGE_Thank_You_Claim::$success_calls), 1);
check('accepted sets once-only state', PGE_Thank_You_Claim::is_sent(101), true);
check('Claim provider is Cartat', PGE_Thank_You_Claim::$claim_calls[0]['provider'], 'cartat');
check('Claim receives actor id', PGE_Thank_You_Claim::$claim_calls[0]['actor_user_id'], 77);
check('Claim owns lifecycle marker', PGE_Thank_You_Claim::$claim_calls[0]['lifecycle_started_at'], 'L1');
check('resolver called with thank_you', PGE_Message_Recipient_Resolver::$calls[0]['message_type'], 'thank_you');
check('resolver called with checked_in intent', PGE_Message_Recipient_Resolver::$calls[0]['filter'], 'checked_in');
check('resolver summary is returned without recipients/PII', array_key_exists('recipients', $accepted['resolver']), false);
$text_calls_after_success = count(PGE_Cartat_Transport::$text_calls);
$second = send_event(1);
check('second attempt counted already sent', $second['skipped_already_sent'], 1);
check('second attempt does not claim successfully', $second['claimed'], 0);
check('second attempt makes no delivery', count(PGE_Cartat_Transport::$text_calls), $text_calls_after_success);

// Confirmed rejection finalizes failed and permits a later intentional retry.
add_event(2, 'مناسبة الرفض', [recipient('966500000002', 201)]);
PGE_Thank_You_Claim::seed(201);
PGE_Cartat_Transport::$plans[] = ['status' => 'error'];
$rejected = send_event(2);
check('rejected delivery counted failed', $rejected['failed'], 1);
check('rejected does not mark sent', PGE_Thank_You_Claim::is_sent(201), false);
$last_failure = end(PGE_Thank_You_Claim::$failure_calls);
check('rejected finalizes with existing failed status', $last_failure[1], 'failed');
PGE_Cartat_Transport::$plans[] = ['success' => true];
$rejected_retry = send_event(2);
check('retry after confirmed rejection is allowed', $rejected_retry['sent'], 1);

// Ambiguous transport fences immediate retry, then allows retry after lease.
add_event(3, 'مناسبة الغموض', [recipient('966500000003', 301)]);
PGE_Thank_You_Claim::seed(301);
PGE_Cartat_Transport::$plans[] = null;
$ambiguous = send_event(3);
check('transport error counted ambiguous', $ambiguous['ambiguous'], 1);
check('ambiguous does not mark sent', PGE_Thank_You_Claim::is_sent(301), false);
$last_failure = end(PGE_Thank_You_Claim::$failure_calls);
check('ambiguous uses existing ambiguous status', $last_failure[1], 'ambiguous_transport_error');
$calls_before_lease_retry = count(PGE_Cartat_Transport::$text_calls);
$lease_blocked = send_event(3);
check('retry before lease expiry is active claim', $lease_blocked['skipped_active_claim'], 1);
check('retry before lease makes no delivery', count(PGE_Cartat_Transport::$text_calls), $calls_before_lease_retry);
PGE_Thank_You_Claim::$now += 121;
PGE_Cartat_Transport::$plans[] = ['status' => 'queued'];
$lease_retry = send_event(3);
check('retry after lease expiry sends', $lease_retry['sent'], 1);

// An exception after the transport starts is ambiguous, never a certain failure.
add_event(4, 'مناسبة الاستثناء', [recipient('966500000004', 401)]);
PGE_Thank_You_Claim::seed(401);
PGE_Cartat_Transport::$plans[] = new RuntimeException('fixture transport exception');
$exception = send_event(4);
check('transport exception counted ambiguous', $exception['ambiguous'], 1);
check('transport exception does not mark sent', PGE_Thank_You_Claim::is_sent(401), false);
$exception_retry = send_event(4);
check('transport exception blocks immediate duplicate', $exception_retry['skipped_active_claim'], 1);

// Resolver is the only eligibility authority: excluded cases never reach Claim/Transport.
$excluded = [
    10 => 'cancelled checked-in invitation',
    11 => 'checked_in=0',
    12 => 'RSVP=yes checked_in=0',
    13 => 'hard-deleted historical RSVP',
    14 => 'reinvite reset checked_in=0',
    15 => 'phone-change old source',
    16 => 'integrity_error',
    17 => 'stale lifecycle',
];
foreach ($excluded as $event_id => $label) {
    add_event($event_id, $label, []);
    $claims_before = count(PGE_Thank_You_Claim::$claim_calls);
    $sends_before = count(PGE_Cartat_Transport::$text_calls);
    $empty = send_event($event_id);
    check($label . ' has zero eligible recipients', $empty['eligible'], 0);
    check($label . ' performs no Claim', count(PGE_Thank_You_Claim::$claim_calls), $claims_before);
    check($label . ' performs no Send', count(PGE_Cartat_Transport::$text_calls), $sends_before);
}

// RSVP reply value never overrides checked_in eligibility delivered by Resolver.
add_event(18, 'RSVP no checked in', [recipient('966500000018', 1801)]);
PGE_Thank_You_Claim::seed(1801);
PGE_Cartat_Transport::$plans[] = ['status' => 'sent'];
check('RSVP=no + checked_in=1 sends', send_event(18)['sent'], 1);
add_event(19, 'Pending checked in', [recipient('966500000019', 1901)]);
PGE_Thank_You_Claim::seed(1901);
PGE_Cartat_Transport::$plans[] = ['status' => 'sent'];
check('pending + checked_in=1 sends', send_event(19)['sent'], 1);

// Reused RSVP id in a new lifecycle is independently claimable after new check-in.
add_event(20, 'Lifecycle A', [recipient('966500000020', 2001)]);
PGE_Thank_You_Claim::seed(2001, 'L-A');
PGE_Cartat_Transport::$plans[] = ['status' => 'sent'];
check('old lifecycle sends once', send_event(20)['sent'], 1);
PGE_Thank_You_Claim::reset_lifecycle(2001, 'L-B');
add_event(20, 'Lifecycle B reset', []);
check('new lifecycle reset is not eligible before check-in', send_event(20)['eligible'], 0);
add_event(20, 'Lifecycle B', [recipient('966500000020', 2001)]);
PGE_Cartat_Transport::$plans[] = ['status' => 'sent'];
$new_lifecycle = send_event(20);
check('new lifecycle sends after new check-in', $new_lifecycle['sent'], 1);
$last_claim = end(PGE_Thank_You_Claim::$claim_calls);
check('new lifecycle Claim uses new marker', $last_claim['lifecycle_started_at'], 'L-B');

// Old lifecycle ambiguity does not block a newly reset lifecycle.
add_event(21, 'Old ambiguous lifecycle', [recipient('966500000021', 2101)]);
PGE_Thank_You_Claim::seed(2101, 'OLD');
PGE_Cartat_Transport::$plans[] = null;
check('old lifecycle becomes ambiguous', send_event(21)['ambiguous'], 1);
PGE_Thank_You_Claim::reset_lifecycle(2101, 'NEW');
PGE_Cartat_Transport::$plans[] = ['status' => 'sent'];
check('old lifecycle claim does not block new lifecycle', send_event(21)['sent'], 1);

// Phone-change target is sent only when Resolver exposes it after the new check-in.
add_event(22, 'Phone target reset', []);
$phone_reset = send_event(22);
check('phone-change target reset sends nothing', $phone_reset['sent'], 0);
PGE_Thank_You_Claim::seed(2202, 'PHONE-NEW');
add_event(22, 'Phone target checked', [recipient('966500000222', 2202)]);
PGE_Cartat_Transport::$plans[] = ['status' => 'sent'];
$phone_checked = send_event(22);
check('phone-change target sends after check-in', $phone_checked['sent'], 1);
$last_text = end(PGE_Cartat_Transport::$text_calls);
check('phone change sends only to target number', $last_text['number'], '966500000222');

// Mixed batch continues after partial failures and classifies every recipient.
add_event(30, 'دفعة جزئية', [
    recipient('966500000301', 3001),
    recipient('966500000302', 3002),
    recipient('966500000303', 3003),
    recipient('966500000304', 3004),
    recipient('966500000305', 3005),
]);
PGE_Thank_You_Claim::seed(3001);
PGE_Thank_You_Claim::seed(3002);
PGE_Thank_You_Claim::seed(3003, 'L1', ['sent' => true]);
PGE_Thank_You_Claim::seed(3004);
PGE_Thank_You_Claim::seed(3005, 'L1', [
    'active_until' => PGE_Thank_You_Claim::$now + 120,
    'active_lifecycle' => 'L1',
]);
PGE_Cartat_Transport::$plans[] = ['status' => 'sent'];
PGE_Cartat_Transport::$plans[] = ['success' => false];
PGE_Cartat_Transport::$plans[] = null;
$partial = send_event(30);
check('partial batch eligible count', $partial['eligible'], 5);
check('partial batch claimed count', $partial['claimed'], 3);
check('partial batch accepted count', $partial['sent'], 1);
check('partial batch rejected count', $partial['failed'], 1);
check('partial batch ambiguous count', $partial['ambiguous'], 1);
check('partial batch already sent count', $partial['skipped_already_sent'], 1);
check('partial batch active claim count', $partial['skipped_active_claim'], 1);

// Claim errors and missing credentials fail before any delivery.
add_event(31, 'Claim error', [recipient('966500000031', 3101)]);
PGE_Thank_You_Claim::seed(3101, 'L1', ['claim_error' => true]);
$sends_before_claim_error = count(PGE_Cartat_Transport::$text_calls);
$claim_error = send_event(31);
check('claim error counted', $claim_error['skipped_claim_error'], 1);
check('claim error causes no DB-finalize equivalent', $claim_error['claimed'], 0);
check('claim error causes no Transport call', count(PGE_Cartat_Transport::$text_calls), $sends_before_claim_error);

add_event(32, 'No credentials', [recipient('966500000032', 3201)]);
PGE_Thank_You_Claim::seed(3201);
PGE_Cartat_Transport::$credentials = false;
$claims_before_credentials = count(PGE_Thank_You_Claim::$claim_calls);
$no_credentials = send_event(32);
check('missing Cartat credentials is systemic error', $no_credentials['reason'], 'no_provider_credentials');
check('missing credentials causes no Claim', count(PGE_Thank_You_Claim::$claim_calls), $claims_before_credentials);
PGE_Cartat_Transport::$credentials = true;

// Accepted response with an uncommitted local finalize is fenced as ambiguous.
add_event(33, 'Finalize failure', [recipient('966500000033', 3301)]);
PGE_Thank_You_Claim::seed(3301, 'L1', ['finalize_success' => false]);
PGE_Cartat_Transport::$plans[] = ['status' => 'sent'];
$finalize_failure = send_event(33);
check('accepted but uncommitted finalize is ambiguous', $finalize_failure['ambiguous'], 1);
check('accepted finalize failure is not reported sent', $finalize_failure['sent'], 0);
check('accepted finalize failure fences immediate retry', send_event(33)['skipped_active_claim'], 1);

// Static scope guards: Text only, Cartat only, no credits, no direct DB/HTTP.
$service_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-thank-you-message-service.php');
check('Service never calls media transport', strpos($service_source, 'send_media') === false, true);
check('Service never references UltraMsg', stripos($service_source, 'ultramsg') === false, true);
check('Service never references Invitation Credit Ledger', strpos($service_source, 'PGE_Invitation_Credit_Ledger') === false, true);
check('Service never references Replacement Entitlements', strpos($service_source, 'PGE_Replacement_Entitlements') === false, true);
check('Service contains no credit_type', strpos($service_source, 'credit_type') === false, true);
check('Service contains no direct wp_remote transport', strpos($service_source, 'wp_remote_') === false, true);
check('Service contains no direct database access', strpos($service_source, '$wpdb') === false, true);
check('Service registers no AJAX/Cron hook', strpos($service_source, 'add_action') === false, true);
check('runtime made no media calls', count(PGE_Cartat_Transport::$media_calls), 0);
check('Credit Ledger class was not loaded', class_exists('PGE_Invitation_Credit_Ledger', false), false);
check('Replacement Entitlements class was not loaded', class_exists('PGE_Replacement_Entitlements', false), false);

echo "Thank You Message Service Phase 4B-2: {$passed}/{$total} passed\n";
if ($failures) {
    foreach ($failures as $failure) {
        echo "FAIL: {$failure}\n";
    }
    exit(1);
}
exit(0);
