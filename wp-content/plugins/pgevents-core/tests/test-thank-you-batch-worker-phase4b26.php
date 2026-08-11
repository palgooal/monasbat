<?php
/**
 * Central standalone verification for Phase 4B-2.6 durable Thank You batches.
 *
 * Run: php tests/test-thank-you-batch-worker-phase4b26.php
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

$GLOBALS['bw_options'] = [];
$GLOBALS['bw_autoload'] = [];
$GLOBALS['bw_cron'] = [];
$GLOBALS['bw_actions'] = [];
$GLOBALS['bw_spawn_count'] = 0;
$GLOBALS['bw_events'] = [];

function add_option($key, $value, $deprecated = '', $autoload = true)
{
    if (array_key_exists($key, $GLOBALS['bw_options'])) return false;
    $GLOBALS['bw_options'][$key] = $value;
    $GLOBALS['bw_autoload'][$key] = $autoload;
    return true;
}

function get_option($key, $default = false)
{
    return array_key_exists($key, $GLOBALS['bw_options'])
        ? $GLOBALS['bw_options'][$key]
        : $default;
}

function update_option($key, $value, $autoload = null)
{
    $changed = !array_key_exists($key, $GLOBALS['bw_options'])
        || $GLOBALS['bw_options'][$key] !== $value;
    $GLOBALS['bw_options'][$key] = $value;
    if ($autoload !== null) $GLOBALS['bw_autoload'][$key] = $autoload;
    return $changed;
}

function delete_option($key)
{
    if (!array_key_exists($key, $GLOBALS['bw_options'])) return false;
    unset($GLOBALS['bw_options'][$key], $GLOBALS['bw_autoload'][$key]);
    return true;
}

function current_time($type, $gmt = false)
{
    return gmdate('Y-m-d H:i:s');
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['bw_actions'][$hook] = compact('callback', 'priority', 'accepted_args');
}

function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    $GLOBALS['bw_cron'][] = compact('timestamp', 'hook', 'args');
    return true;
}

function wp_next_scheduled($hook, $args = [])
{
    foreach ($GLOBALS['bw_cron'] as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) return $event['timestamp'];
    }
    return false;
}

function spawn_cron()
{
    $GLOBALS['bw_spawn_count']++;
}

function wp_generate_uuid4()
{
    static $id = 1;
    return 'processing-token-' . $id++;
}

function get_post_type($event_id)
{
    return isset($GLOBALS['bw_events'][(int) $event_id]) ? 'pge_event' : null;
}

function get_post($event_id)
{
    return isset($GLOBALS['bw_events'][(int) $event_id])
        ? (object) ['ID' => (int) $event_id, 'post_title' => 'Event ' . (int) $event_id]
        : null;
}

function get_post_meta($event_id, $key, $single = false)
{
    return $key === '_pge_event_date' ? '2026-08-20T18:30' : '';
}

function date_i18n($format, $timestamp = null)
{
    return '20 August 2026 — 6:30 pm';
}

class Fake_WPDB
{
    public $locks = [];
    public $deny_prefix = '';

    public function prepare($query, ...$args)
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%s/', "'" . str_replace("'", "''", (string) $arg) . "'", $query, 1);
        }
        return $query;
    }

    public function get_var($query)
    {
        if (!preg_match("/GET_LOCK\('([^']+)'/", $query, $match)) return null;
        $name = $match[1];
        if (($this->deny_prefix !== '' && strpos($name, $this->deny_prefix) === 0)
            || !empty($this->locks[$name])) return 0;
        $this->locks[$name] = true;
        return 1;
    }

    public function query($query)
    {
        if (preg_match("/RELEASE_LOCK\('([^']+)'/", $query, $match)) {
            unset($this->locks[$match[1]]);
        }
        return 1;
    }
}

$GLOBALS['wpdb'] = new Fake_WPDB();

require_once dirname(__DIR__) . '/includes/class-pge-message-type.php';

class PGE_Message_Batch
{
    public static $next = 1;
    public static function generate_batch_id() { return 'durable-batch-' . self::$next++; }
}

class PGE_Thank_You_Claim
{
    const CLAIM_LEASE_SECONDS = 120;
}

class PGE_Cartat_Transport
{
    public static $credentials = true;
    public function has_credentials(): bool { return self::$credentials; }
}

class PGE_Message_Recipient_Resolver
{
    public static $recipients = [];
    public static $eligible = [];
    public static $ineligible_reasons = [];
    public static $resolve_calls = [];
    public static $target_calls = [];

    public static function resolve(int $event_id, string $message_type, string $filter): array
    {
        self::$resolve_calls[] = compact('event_id', 'message_type', 'filter');
        return ['recipients' => self::$recipients[$event_id] ?? []];
    }

    public static function resolve_thank_you_recipient(int $event_id, int $rsvp_id, string $lifecycle): array
    {
        self::$target_calls[] = compact('event_id', 'rsvp_id', 'lifecycle');
        $recipient = self::$eligible[$event_id][$rsvp_id] ?? null;
        if (!is_array($recipient)) {
            return [
                'eligible' => false,
                'reason' => self::$ineligible_reasons[$event_id][$rsvp_id] ?? 'no_longer_eligible',
            ];
        }
        if ((string) $recipient['lifecycle_started_at'] !== $lifecycle) {
            return ['eligible' => false, 'reason' => 'lifecycle_changed'];
        }
        return ['eligible' => true, 'reason' => 'eligible', 'recipient' => $recipient];
    }
}

class PGE_Thank_You_Message_Service
{
    public static $calls = [];
    public static $plans = [];

    public static function process_recipient(
        int $event_id,
        int $actor_user_id,
        string $batch_id,
        array $recipient,
        PGE_Cartat_Transport $transport,
        array $event_context = [],
        ?string $expected_lifecycle_started_at = null
    ): array {
        self::$calls[] = compact(
            'event_id', 'actor_user_id', 'batch_id', 'recipient',
            'event_context', 'expected_lifecycle_started_at'
        );
        $rsvp_id = (int) $recipient['rsvp_id'];
        $plan = self::$plans[$rsvp_id] ?? null;
        if (is_array($plan) && isset($plan[0]) && is_array($plan[0])) {
            $outcome = array_shift(self::$plans[$rsvp_id]);
            return $outcome;
        }
        return is_array($plan) ? $plan : ['status' => 'sent', 'reason' => 'accepted', 'claimed' => true];
    }
}

require_once dirname(__DIR__) . '/includes/class-pge-thank-you-batch-store.php';
require_once dirname(__DIR__) . '/includes/class-pge-thank-you-batch-worker.php';

function seed_event(int $event_id, int $count): void
{
    $GLOBALS['bw_events'][$event_id] = true;
    $recipients = [];
    $offsets = $count > 0 ? range(1, $count) : [];
    foreach ($offsets as $offset) {
        $id = $event_id * 1000 + $offset;
        $recipient = [
            'phone' => '9665' . str_pad((string) $id, 8, '0', STR_PAD_LEFT),
            'name' => 'Guest ' . $id,
            'rsvp_id' => $id,
            'lifecycle_started_at' => '2026-08-10 10:' . str_pad((string) ($offset % 60), 2, '0', STR_PAD_LEFT),
        ];
        $recipients[] = $recipient;
        PGE_Message_Recipient_Resolver::$eligible[$event_id][$id] = $recipient;
    }
    PGE_Message_Recipient_Resolver::$recipients[$event_id] = $recipients;
}

function clear_scheduled_worker(int $event_id, string $batch_id): void
{
    $GLOBALS['bw_cron'] = array_values(array_filter(
        $GLOBALS['bw_cron'],
        function ($event) use ($event_id, $batch_id) {
            return !($event['hook'] === PGE_Thank_You_Batch_Worker::WORKER_HOOK
                && $event['args'] === [$event_id, $batch_id]);
        }
    ));
}

function run_tick(int $event_id, string $batch_id): void
{
    clear_scheduled_worker($event_id, $batch_id);
    PGE_Thank_You_Batch_Worker::run($event_id, $batch_id);
}

function run_to_completion(int $event_id, string $batch_id, int $limit = 200): int
{
    $ticks = 0;
    while ($ticks < $limit) {
        $status = PGE_Thank_You_Batch_Worker::get_status($event_id, $batch_id);
        if ($status && $status['complete']) break;
        run_tick($event_id, $batch_id);
        $ticks++;
    }
    return $ticks;
}

// Input/systemic validation and the zero-recipient policy.
check('invalid event rejected', PGE_Thank_You_Batch_Worker::create_batch(999, 7)['reason'], 'invalid_event');
seed_event(1, 1);
PGE_Cartat_Transport::$credentials = false;
check('missing credentials rejected before persistence', PGE_Thank_You_Batch_Worker::create_batch(1, 7)['reason'], 'no_provider_credentials');
PGE_Cartat_Transport::$credentials = true;
seed_event(2, 0);
check('empty resolver returns no_eligible', PGE_Thank_You_Batch_Worker::create_batch(2, 7)['result'], 'no_eligible');
check('empty resolver creates no active index', PGE_Thank_You_Batch_Store::get_active_batch_id(2), '');

// Durable manifest, privacy boundary, async-only creation, and active guard.
seed_event(10, 5);
$calls_before_create = count(PGE_Thank_You_Message_Service::$calls);
$created = PGE_Thank_You_Batch_Worker::create_batch(10, 77);
$batch = $created['batch_id'];
$manifest = PGE_Thank_You_Batch_Store::get($batch);
check('batch creation starts', $created['result'], 'started');
check_true('server generates non-empty batch id', is_string($batch) && $batch !== '');
check('manifest event id', $manifest['event_id'], 10);
check('manifest actor id', $manifest['actor_user_id'], 77);
check('manifest starts active', $manifest['status'], 'active');
check('manifest contains five items', count($manifest['items']), 5);
check('manifest item has RSVP identity', $manifest['items'][0]['rsvp_id'], 10001);
check_true('manifest item has lifecycle marker', $manifest['items'][0]['lifecycle_started_at'] !== '');
check('manifest stores no phone', array_key_exists('phone', $manifest['items'][0]), false);
check('manifest stores no name', array_key_exists('name', $manifest['items'][0]), false);
check('manifest stores no message text', strpos(json_encode($manifest), 'Guest ') === false, true);
check('manifest option is non-autoloaded', in_array(false, $GLOBALS['bw_autoload'], true), true);
check('active index points directly to batch', PGE_Thank_You_Batch_Store::get_active_batch_id(10), $batch);
check('creation performs zero synchronous sends', count(PGE_Thank_You_Message_Service::$calls), $calls_before_create);
check_true('creation schedules worker', (bool) wp_next_scheduled(PGE_Thank_You_Batch_Worker::WORKER_HOOK, [10, $batch]));
check_true('creation schedules watchdog', (bool) wp_next_scheduled(PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK, [10, $batch]));
check_true('creation nudges cron', $GLOBALS['bw_spawn_count'] > 0);
$duplicate = PGE_Thank_You_Batch_Worker::create_batch(10, 88);
check('same-event concurrent batch is rejected', $duplicate['result'], 'active_batch_exists');
check('duplicate returns existing batch id', $duplicate['batch_id'], $batch);

// Chunking and completion.
$before_tick = count(PGE_Thank_You_Message_Service::$calls);
run_tick(10, $batch);
$after_first = PGE_Thank_You_Batch_Worker::get_status(10, $batch);
check('first tick processes exactly chunk size', count(PGE_Thank_You_Message_Service::$calls) - $before_tick, 4);
check('first tick sent four', $after_first['sent'], 4);
check('first tick leaves one queued', $after_first['queued'], 1);
check('first tick does not complete', $after_first['complete'], false);
run_tick(10, $batch);
$complete = PGE_Thank_You_Batch_Worker::get_status(10, $batch);
check('second tick sends final recipient', $complete['sent'], 5);
check('completed batch marked complete', $complete['complete'], true);
check_true('completed timestamp set', $complete['completed_at'] !== '');
check('completion clears matching active index', PGE_Thank_You_Batch_Store::get_active_batch_id(10), '');

// Revalidation: lifecycle and current eligibility can change after creation.
seed_event(11, 3);
$created11 = PGE_Thank_You_Batch_Worker::create_batch(11, 9);
$batch11 = $created11['batch_id'];
$ids11 = array_column(PGE_Message_Recipient_Resolver::$recipients[11], 'rsvp_id');
unset(PGE_Message_Recipient_Resolver::$eligible[11][$ids11[0]]);
PGE_Message_Recipient_Resolver::$eligible[11][$ids11[1]]['lifecycle_started_at'] = 'NEW-LIFECYCLE';
$service_before_revalidation = count(PGE_Thank_You_Message_Service::$calls);
run_tick(11, $batch11);
$status11 = PGE_Thank_You_Batch_Worker::get_status(11, $batch11);
check('no longer eligible item skipped', $status11['skipped_reasons']['no_longer_eligible'], 1);
check('changed lifecycle item skipped', $status11['skipped_reasons']['lifecycle_changed'], 1);
check('only still-current item reaches Claim/service', count(PGE_Thank_You_Message_Service::$calls) - $service_before_revalidation, 1);
check('still-current item sends', $status11['sent'], 1);
check('revalidation batch completes', $status11['complete'], true);

// Authoritative lifecycle mutations all stop before Claim/service.
$mutation_reasons = [
    'hard_deleted',
    'phone_change_source',
    'cancelled',
    'checkin_reset',
];
foreach ($mutation_reasons as $offset => $reason) {
    $event_id = 110 + $offset;
    seed_event($event_id, 1);
    $rsvp_id = PGE_Message_Recipient_Resolver::$recipients[$event_id][0]['rsvp_id'];
    $batch_mutation = PGE_Thank_You_Batch_Worker::create_batch($event_id, 7)['batch_id'];
    unset(PGE_Message_Recipient_Resolver::$eligible[$event_id][$rsvp_id]);
    PGE_Message_Recipient_Resolver::$ineligible_reasons[$event_id][$rsvp_id] = $reason;
    $before_mutation = count(PGE_Thank_You_Message_Service::$calls);
    run_tick($event_id, $batch_mutation);
    $mutation_status = PGE_Thank_You_Batch_Worker::get_status($event_id, $batch_mutation);
    check("{$reason} becomes skipped", $mutation_status['skipped_reasons'][$reason], 1);
    check("{$reason} stops before Claim/service", count(PGE_Thank_You_Message_Service::$calls), $before_mutation);
}

seed_event(120, 1);
$rsvp120 = PGE_Message_Recipient_Resolver::$recipients[120][0]['rsvp_id'];
$batch120 = PGE_Thank_You_Batch_Worker::create_batch(120, 7)['batch_id'];
PGE_Message_Recipient_Resolver::$eligible[120][$rsvp120]['lifecycle_started_at'] = 'PHONE-TARGET-NEW';
$before_target = count(PGE_Thank_You_Message_Service::$calls);
run_tick(120, $batch120);
$status120 = PGE_Thank_You_Batch_Worker::get_status(120, $batch120);
check('phone-change target new lifecycle is skipped by old batch', $status120['skipped_reasons']['lifecycle_changed'], 1);
check('phone-change target new lifecycle stops before Claim/service', count(PGE_Thank_You_Message_Service::$calls), $before_target);

// Active Claim remains recoverable/waiting and resumes after its lease window.
seed_event(12, 1);
$rsvp12 = PGE_Message_Recipient_Resolver::$recipients[12][0]['rsvp_id'];
PGE_Thank_You_Message_Service::$plans[$rsvp12] = [
    ['status' => 'waiting', 'reason' => 'active_claim', 'claimed' => false],
    ['status' => 'sent', 'reason' => 'accepted', 'claimed' => true],
];
$batch12 = PGE_Thank_You_Batch_Worker::create_batch(12, 7)['batch_id'];
run_tick(12, $batch12);
$waiting12 = PGE_Thank_You_Batch_Worker::get_status(12, $batch12);
check('active claim is recoverable processing state', $waiting12['processing'], 1);
check('active claim does not complete batch', $waiting12['complete'], false);
$manifest12 = PGE_Thank_You_Batch_Store::get($batch12);
$manifest12['items'][0]['next_attempt_at'] = gmdate('Y-m-d H:i:s', time() - 1);
PGE_Thank_You_Batch_Store::save($manifest12);
run_tick(12, $batch12);
$done12 = PGE_Thank_You_Batch_Worker::get_status(12, $batch12);
check('waiting item is retried after lease', $done12['sent'], 1);
check('waiting batch eventually completes', $done12['complete'], true);

// Tick lock contention must do no work and must self-reschedule.
seed_event(13, 2);
$batch13 = PGE_Thank_You_Batch_Worker::create_batch(13, 7)['batch_id'];
clear_scheduled_worker(13, $batch13);
$GLOBALS['wpdb']->deny_prefix = 'pge_ty_batch_tick_';
$calls_before_busy = count(PGE_Thank_You_Message_Service::$calls);
PGE_Thank_You_Batch_Worker::run(13, $batch13);
check('busy tick performs no send', count(PGE_Thank_You_Message_Service::$calls), $calls_before_busy);
check_true('busy tick is rescheduled', (bool) wp_next_scheduled(PGE_Thank_You_Batch_Worker::WORKER_HOOK, [13, $batch13]));
$GLOBALS['wpdb']->deny_prefix = '';
run_to_completion(13, $batch13);
check('busy batch later completes', PGE_Thank_You_Batch_Worker::get_status(13, $batch13)['complete'], true);

// Operation lock contention prevents competing batch creation.
seed_event(14, 1);
$GLOBALS['wpdb']->deny_prefix = 'pge_ty_batch_op_';
$busy_create = PGE_Thank_You_Batch_Worker::create_batch(14, 7);
check('busy operation lock is reported', $busy_create['result'], 'busy');
check('busy operation lock creates no active index', PGE_Thank_You_Batch_Store::get_active_batch_id(14), '');
$GLOBALS['wpdb']->deny_prefix = '';

// Crash after reservation is recovered by the processing lease.
seed_event(15, 1);
$batch15 = PGE_Thank_You_Batch_Worker::create_batch(15, 7)['batch_id'];
$manifest15 = PGE_Thank_You_Batch_Store::get($batch15);
$manifest15['items'][0]['status'] = PGE_Thank_You_Batch_Store::ITEM_PROCESSING;
$manifest15['items'][0]['processing_started_at'] = gmdate('Y-m-d H:i:s', time() - 121);
$manifest15['items'][0]['processing_token'] = 'crashed-worker';
PGE_Thank_You_Batch_Store::save($manifest15);
run_tick(15, $batch15);
$done15 = PGE_Thank_You_Batch_Worker::get_status(15, $batch15);
check('stale processing item is recovered', $done15['sent'], 1);
check('crash recovery completes batch', $done15['complete'], true);

// Watchdog restores a lost worker schedule.
seed_event(16, 1);
$batch16 = PGE_Thank_You_Batch_Worker::create_batch(16, 7)['batch_id'];
clear_scheduled_worker(16, $batch16);
PGE_Thank_You_Batch_Worker::watchdog(16, $batch16);
check_true('watchdog restores missing worker', (bool) wp_next_scheduled(PGE_Thank_You_Batch_Worker::WORKER_HOOK, [16, $batch16]));
run_to_completion(16, $batch16);
check('watchdog-recovered batch completes', PGE_Thank_You_Batch_Worker::get_status(16, $batch16)['complete'], true);

// Partial outcome classification remains terminal and independently counted.
seed_event(17, 4);
$ids17 = array_column(PGE_Message_Recipient_Resolver::$recipients[17], 'rsvp_id');
PGE_Thank_You_Message_Service::$plans[$ids17[0]] = ['status' => 'sent', 'reason' => 'accepted', 'claimed' => true];
PGE_Thank_You_Message_Service::$plans[$ids17[1]] = ['status' => 'failed', 'reason' => 'rejected', 'claimed' => true];
PGE_Thank_You_Message_Service::$plans[$ids17[2]] = ['status' => 'ambiguous', 'reason' => 'transport_error', 'claimed' => true];
PGE_Thank_You_Message_Service::$plans[$ids17[3]] = ['status' => 'skipped', 'reason' => 'already_sent', 'claimed' => false];
$batch17 = PGE_Thank_You_Batch_Worker::create_batch(17, 7)['batch_id'];
run_tick(17, $batch17);
$status17 = PGE_Thank_You_Batch_Worker::get_status(17, $batch17);
check('partial sent count', $status17['sent'], 1);
check('partial failed count', $status17['failed'], 1);
check('partial ambiguous count', $status17['ambiguous'], 1);
check('partial skipped count', $status17['skipped'], 1);
check('partial reason breakdown', $status17['skipped_reasons']['already_sent'], 1);
check('terminal partial outcomes complete', $status17['complete'], true);

// Scale: no synchronous sends and bounded chunking at 25, 100, and 400.
foreach ([25, 100, 400] as $scale) {
    $event_id = 1000 + $scale;
    seed_event($event_id, $scale);
    $before_create = count(PGE_Thank_You_Message_Service::$calls);
    $created_scale = PGE_Thank_You_Batch_Worker::create_batch($event_id, 42);
    check("{$scale} creation is async-only", count(PGE_Thank_You_Message_Service::$calls), $before_create);
    $ticks = run_to_completion($event_id, $created_scale['batch_id'], 110);
    $status_scale = PGE_Thank_You_Batch_Worker::get_status($event_id, $created_scale['batch_id']);
    check("{$scale} batch sends all", $status_scale['sent'], $scale);
    check("{$scale} batch completes", $status_scale['complete'], true);
    check("{$scale} uses expected bounded ticks", $ticks, (int) ceil($scale / 4));
}

// Public status is aggregate-only and source guards preserve phase boundaries.
$public_status = PGE_Thank_You_Batch_Worker::get_status(10, $batch);
$public_json = json_encode($public_status);
check('status exposes total', $public_status['total'], 5);
check('status contains no phone', strpos($public_json, '9665') === false, true);
check('status contains no RSVP id', strpos($public_json, '10001') === false, true);
check('status contains no actor id', array_key_exists('actor_user_id', $public_status), false);
check('status contains no message text', strpos($public_json, 'Guest') === false, true);

$store_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-thank-you-batch-store.php');
$worker_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-thank-you-batch-worker.php');
$service_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-thank-you-message-service.php');
$resolver_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-message-recipient-resolver.php');
check('worker chunk is exactly four', PGE_Thank_You_Batch_Worker::CHUNK_SIZE, 4);
check('worker reuses recipient resolver', strpos($worker_source, 'resolve_thank_you_recipient') !== false, true);
check('production resolver exposes lifecycle snapshot', strpos($resolver_source, "'lifecycle_started_at'") !== false, true);
check('worker reuses Thank You service', strpos($worker_source, 'PGE_Thank_You_Message_Service::process_recipient') !== false, true);
check('worker has no media send', strpos($worker_source, 'send_media') === false, true);
check('worker has no direct HTTP', strpos($worker_source, 'wp_remote_') === false, true);
check('worker has no direct Claim orchestration', strpos($worker_source, 'PGE_Thank_You_Claim::claim') === false, true);
check('worker has no transient persistence', stripos($worker_source . $store_source, 'transient') === false, true);
check('store disables option autoload', substr_count($store_source, ', false)') >= 2, true);
check('no Invitation Credit Ledger reference', strpos($worker_source . $store_source, 'PGE_Invitation_Credit_Ledger') === false, true);
check('no replacement credit reference', stripos($worker_source . $store_source, 'replacement_credit') === false, true);
check('no AJAX registration', stripos($worker_source, 'wp_ajax') === false, true);
check('no UI code', stripos($worker_source, 'template') === false, true);
check('service remains text-only', strpos($service_source, 'send_media') === false, true);
check('worker source does not contain phone snapshot field', strpos($store_source, "'phone'") === false, true);
check('runtime creates no credit classes', class_exists('PGE_Invitation_Credit_Ledger', false), false);

echo "Thank You Batch Worker Phase 4B-2.6: {$passed}/{$total} passed\n";
if ($failures) {
    foreach ($failures as $failure) echo "FAIL: {$failure}\n";
    exit(1);
}
exit(0);
