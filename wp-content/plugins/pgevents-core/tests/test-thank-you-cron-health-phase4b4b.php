<?php
/**
 * Central standalone verification for Phase 4B-4B Cron health and recovery.
 *
 * Run: php tests/test-thank-you-cron-health-phase4b4b.php
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

$GLOBALS['ch_options'] = [];
$GLOBALS['ch_cron'] = [];
$GLOBALS['ch_fail_hooks'] = [];
$GLOBALS['ch_events'] = [];
$GLOBALS['ch_spawn_count'] = 0;
$GLOBALS['ch_actions'] = [];

function add_option($key, $value, $deprecated = '', $autoload = true)
{
    if (array_key_exists($key, $GLOBALS['ch_options'])) return false;
    $GLOBALS['ch_options'][$key] = $value;
    return true;
}

function get_option($key, $default = false)
{
    return array_key_exists($key, $GLOBALS['ch_options'])
        ? $GLOBALS['ch_options'][$key]
        : $default;
}

function update_option($key, $value, $autoload = null)
{
    $changed = !array_key_exists($key, $GLOBALS['ch_options'])
        || $GLOBALS['ch_options'][$key] !== $value;
    $GLOBALS['ch_options'][$key] = $value;
    return $changed;
}

function delete_option($key)
{
    if (!array_key_exists($key, $GLOBALS['ch_options'])) return false;
    unset($GLOBALS['ch_options'][$key]);
    return true;
}

function current_time($type, $gmt = false)
{
    return gmdate('Y-m-d H:i:s');
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['ch_actions'][$hook] = compact('callback', 'priority', 'accepted_args');
}

function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    if (!empty($GLOBALS['ch_fail_hooks'][$hook])) return false;
    $GLOBALS['ch_cron'][] = compact('timestamp', 'hook', 'args');
    return true;
}

function wp_next_scheduled($hook, $args = [])
{
    $timestamps = [];
    foreach ($GLOBALS['ch_cron'] as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            $timestamps[] = $event['timestamp'];
        }
    }
    return $timestamps ? min($timestamps) : false;
}

function spawn_cron()
{
    $GLOBALS['ch_spawn_count']++;
}

function wp_generate_uuid4()
{
    static $id = 1;
    return 'health-token-' . $id++;
}

function get_post_type($event_id)
{
    return isset($GLOBALS['ch_events'][(int) $event_id]) ? 'pge_event' : null;
}

function get_post($event_id)
{
    return isset($GLOBALS['ch_events'][(int) $event_id])
        ? (object) ['ID' => (int) $event_id, 'post_title' => 'Health Event']
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

class Fake_Health_WPDB
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

$GLOBALS['wpdb'] = new Fake_Health_WPDB();

require_once dirname(__DIR__) . '/includes/class-pge-message-type.php';

class PGE_Message_Batch
{
    public static $next = 1;
    public static function generate_batch_id() { return 'health-batch-' . self::$next++; }
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

class PGE_Thank_You_Transport_Factory
{
    public static function resolve(): PGE_Cartat_Transport { return new PGE_Cartat_Transport(); }
}

class PGE_Message_Recipient_Resolver
{
    public static $recipients = [];
    public static $eligible = [];

    public static function resolve(int $event_id, string $message_type, string $filter): array
    {
        return ['recipients' => self::$recipients[$event_id] ?? []];
    }

    public static function resolve_thank_you_recipient(int $event_id, int $rsvp_id, string $lifecycle): array
    {
        $recipient = self::$eligible[$event_id][$rsvp_id] ?? null;
        if (!is_array($recipient) || $recipient['lifecycle_started_at'] !== $lifecycle) {
            return ['eligible' => false, 'reason' => 'lifecycle_changed'];
        }
        return ['eligible' => true, 'reason' => 'eligible', 'recipient' => $recipient];
    }
}

class PGE_Thank_You_Message_Service
{
    public static $calls = [];

    public static function process_recipient(
        int $event_id,
        int $actor_user_id,
        string $batch_id,
        array $recipient,
        PGE_Cartat_Transport $transport,
        array $event_context = [],
        ?string $expected_lifecycle_started_at = null
    ): array {
        self::$calls[] = [$event_id, $batch_id, $recipient['rsvp_id']];
        return ['status' => 'sent', 'reason' => 'accepted', 'claimed' => true];
    }
}

require_once dirname(__DIR__) . '/includes/class-pge-thank-you-batch-store.php';
require_once dirname(__DIR__) . '/includes/class-pge-thank-you-batch-worker.php';

function seed_health_event(int $event_id, int $count = 1): void
{
    $GLOBALS['ch_events'][$event_id] = true;
    PGE_Message_Recipient_Resolver::$recipients[$event_id] = [];
    PGE_Message_Recipient_Resolver::$eligible[$event_id] = [];
    for ($offset = 1; $offset <= $count; $offset++) {
        $id = $event_id * 1000 + $offset;
        $recipient = [
            'phone' => '966500000000',
            'name' => 'Private Guest',
            'rsvp_id' => $id,
            'lifecycle_started_at' => '2026-08-01 10:00:00',
        ];
        PGE_Message_Recipient_Resolver::$recipients[$event_id][] = $recipient;
        PGE_Message_Recipient_Resolver::$eligible[$event_id][$id] = $recipient;
    }
}

function remove_health_events(int $event_id, string $batch_id, ?string $hook = null): void
{
    $GLOBALS['ch_cron'] = array_values(array_filter(
        $GLOBALS['ch_cron'],
        static function ($event) use ($event_id, $batch_id, $hook) {
            $matches = $event['args'] === [$event_id, $batch_id]
                && ($hook === null || $event['hook'] === $hook);
            return !$matches;
        }
    ));
}

function set_health_event(int $event_id, string $batch_id, string $hook, int $timestamp): void
{
    remove_health_events($event_id, $batch_id, $hook);
    $GLOBALS['ch_cron'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => [$event_id, $batch_id]];
}

// Fixed contracts and initial scheduling.
check('worker hook unchanged', PGE_Thank_You_Batch_Worker::WORKER_HOOK, 'pge_process_thank_you_batch');
check('watchdog hook unchanged', PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK, 'pge_watchdog_thank_you_batch');
check('chunk remains four', PGE_Thank_You_Batch_Worker::CHUNK_SIZE, 4);
check('continuation remains 25 seconds', PGE_Thank_You_Batch_Worker::RETRY_DELAY_SECONDS, 25);
check('watchdog remains 120 seconds', PGE_Thank_You_Batch_Worker::WATCHDOG_DELAY_SECONDS, 120);
check('claim/processing lease remains 120 seconds', PGE_Thank_You_Claim::CLAIM_LEASE_SECONDS, 120);

seed_health_event(1, 2);
$created = PGE_Thank_You_Batch_Worker::create_batch(1, 7);
$batch = $created['batch_id'];
$manifest = PGE_Thank_You_Batch_Store::get($batch);
check('batch starts normally', $created['result'], 'started');
check_true('initial worker scheduled', wp_next_scheduled(PGE_Thank_You_Batch_Worker::WORKER_HOOK, [1, $batch]) !== false);
check_true('initial watchdog scheduled', wp_next_scheduled(PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK, [1, $batch]) !== false);
check('worker heartbeat initially empty', $manifest['last_worker_tick_at'], '');
check('watchdog heartbeat initially empty', $manifest['last_watchdog_tick_at'], '');
check('schedule failure initially empty', $manifest['last_schedule_failure_type'], '');

$health = PGE_Thank_You_Batch_Worker::get_batch_health(1, $batch);
check('health reports active', $health['batch_status'], 'active');
check('health reports work', $health['has_work'], true);
check('health reports total', $health['total'], 2);
check('fresh batch is not stuck', $health['stuck'], false);
check('health has worker timestamp', $health['next_worker_tick_at'] > 0, true);
check('health has watchdog timestamp', $health['next_watchdog_tick_at'] > 0, true);
$health_json = json_encode($health);
check('health contains no phone', strpos($health_json, '9665') === false, true);
check('health contains no guest name', strpos($health_json, 'Private Guest') === false, true);
check('health contains no RSVP id', strpos($health_json, '1001') === false, true);
check('health contains no lifecycle marker', strpos($health_json, '2026-08-01') === false, true);
check('health contains no raw items', array_key_exists('items', $health), false);

// Heartbeats do not masquerade as queue progress.
$manifest['updated_at'] = gmdate('Y-m-d H:i:s', time() - 400);
PGE_Thank_You_Batch_Store::save_health_metadata($manifest);
remove_health_events(1, $batch, PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK);
PGE_Thank_You_Batch_Worker::watchdog(1, $batch);
$after_watchdog = PGE_Thank_You_Batch_Store::get($batch);
check_true('watchdog heartbeat recorded', $after_watchdog['last_watchdog_tick_at'] !== '');
check('watchdog heartbeat preserves progress timestamp', $after_watchdog['updated_at'], $manifest['updated_at']);
check_true('watchdog guarantees next watchdog', wp_next_scheduled(PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK, [1, $batch]) !== false);

remove_health_events(1, $batch, PGE_Thank_You_Batch_Worker::WORKER_HOOK);
PGE_Thank_You_Batch_Worker::run(1, $batch);
$after_worker = PGE_Thank_You_Batch_Store::get($batch);
check_true('worker heartbeat recorded', $after_worker['last_worker_tick_at'] !== '');
check('worker processes bounded chunk', count(PGE_Thank_You_Message_Service::$calls), 2);
check('worker completes small batch', $after_worker['status'], 'complete');

// Stuck classification is derived and does not change the state machine.
seed_health_event(2, 1);
$batch2 = PGE_Thank_You_Batch_Worker::create_batch(2, 7)['batch_id'];
$manifest2 = PGE_Thank_You_Batch_Store::get($batch2);
$manifest2['updated_at'] = gmdate('Y-m-d H:i:s', time() - 301);
PGE_Thank_You_Batch_Store::save_health_metadata($manifest2);
remove_health_events(2, $batch2);
$stuck = PGE_Thank_You_Batch_Worker::get_batch_health(2, $batch2);
check('dual-loss stale batch is stuck', $stuck['stuck'], true);
check('dual-loss reason is whitelisted', $stuck['stuck_reason'], 'no_scheduled_events');
check('stuck classification keeps active status', $stuck['batch_status'], 'active');

set_health_event(2, $batch2, PGE_Thank_You_Batch_Worker::WORKER_HOOK, time() - 121);
set_health_event(2, $batch2, PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK, time() + 120);
$overdue = PGE_Thank_You_Batch_Worker::get_batch_health(2, $batch2);
check('overdue worker is stuck', $overdue['stuck'], true);
check('overdue worker reason', $overdue['stuck_reason'], 'worker_overdue');
check('worker overdue flag', $overdue['worker_overdue'], true);

set_health_event(2, $batch2, PGE_Thank_You_Batch_Worker::WORKER_HOOK, time() + 25);
set_health_event(2, $batch2, PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK, time() + 120);
$scheduled_stale = PGE_Thank_You_Batch_Worker::get_batch_health(2, $batch2);
check('stale progress with healthy events is not stuck', $scheduled_stale['stuck'], false);

remove_health_events(2, $batch2, PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK);
check('old batch with only near worker is not stuck', PGE_Thank_You_Batch_Worker::get_batch_health(2, $batch2)['stuck'], false);
set_health_event(2, $batch2, PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK, time() + 120);
remove_health_events(2, $batch2, PGE_Thank_You_Batch_Worker::WORKER_HOOK);
check('old batch with only near watchdog is not stuck', PGE_Thank_You_Batch_Worker::get_batch_health(2, $batch2)['stuck'], false);

set_health_event(2, $batch2, PGE_Thank_You_Batch_Worker::WORKER_HOOK, time() + 25);
set_health_event(2, $batch2, PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK, time() - 121);
$watchdog_overdue = PGE_Thank_You_Batch_Worker::get_batch_health(2, $batch2);
check('overdue watchdog is stuck', $watchdog_overdue['stuck'], true);
check('overdue watchdog reason', $watchdog_overdue['stuck_reason'], 'watchdog_overdue');

$manifest2['updated_at'] = gmdate('Y-m-d H:i:s', time() - 100);
PGE_Thank_You_Batch_Store::save_health_metadata($manifest2);
remove_health_events(2, $batch2);
check('fresh dual-loss is not classified stuck', PGE_Thank_You_Batch_Worker::get_batch_health(2, $batch2)['stuck'], false);

// Initial scheduling failures remain persisted and diagnosable.
seed_health_event(20, 1);
$GLOBALS['ch_fail_hooks'] = [
    PGE_Thank_You_Batch_Worker::WORKER_HOOK => true,
    PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK => true,
];
$failed_start = PGE_Thank_You_Batch_Worker::create_batch(20, 7);
check('dual initial schedule failure is explicit', $failed_start['reason'], 'batch_schedule_failed');
check_true('failed schedule retains persisted manifest', is_array(PGE_Thank_You_Batch_Store::get($failed_start['batch_id'])));
check('failed schedule retains active index', PGE_Thank_You_Batch_Store::get_active_batch_id(20), $failed_start['batch_id']);
$failed_manifest = PGE_Thank_You_Batch_Store::get($failed_start['batch_id']);
check('schedule failure type is safe', $failed_manifest['last_schedule_failure_type'], 'watchdog_schedule_failed');
check_true('schedule failure timestamp recorded', $failed_manifest['last_schedule_failed_at'] !== '');
check('schedule metadata contains no raw error', strpos(json_encode($failed_manifest), 'WP_Error') === false, true);

seed_health_event(21, 1);
$GLOBALS['ch_fail_hooks'] = [PGE_Thank_You_Batch_Worker::WORKER_HOOK => true];
$degraded_start = PGE_Thank_You_Batch_Worker::create_batch(21, 7);
check('one successful recovery path starts batch', $degraded_start['result'], 'started');
check_true('watchdog survives worker schedule failure', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK,
    [21, $degraded_start['batch_id']]
) !== false);
$GLOBALS['ch_fail_hooks'] = [];

seed_health_event(22, 1);
$GLOBALS['ch_fail_hooks'] = [PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK => true];
$watchdog_failed_start = PGE_Thank_You_Batch_Worker::create_batch(22, 7);
check('watchdog-only initial schedule failure remains recoverable', $watchdog_failed_start['result'], 'started');
check_true('worker survives watchdog schedule failure', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WORKER_HOOK,
    [22, $watchdog_failed_start['batch_id']]
) !== false);
check('watchdog schedule failure is recorded safely',
    PGE_Thank_You_Batch_Store::get($watchdog_failed_start['batch_id'])['last_schedule_failure_type'],
    'watchdog_schedule_failed'
);
$GLOBALS['ch_fail_hooks'] = [];

// Start re-arms the same active batch after dual event loss.
seed_health_event(30, 1);
$first30 = PGE_Thank_You_Batch_Worker::create_batch(30, 7);
$next_id_before = PGE_Message_Batch::$next;
remove_health_events(30, $first30['batch_id']);
$recovered30 = PGE_Thank_You_Batch_Worker::create_batch(30, 8);
check('dual-loss recovery returns existing contract', $recovered30['result'], 'active_batch_exists');
check('dual-loss recovery returns same batch', $recovered30['batch_id'], $first30['batch_id']);
check('dual-loss recovery creates no second batch', PGE_Message_Batch::$next, $next_id_before);
check_true('dual-loss recovery restores worker', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WORKER_HOOK,
    [30, $first30['batch_id']]
) !== false);
check_true('dual-loss recovery restores watchdog', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK,
    [30, $first30['batch_id']]
) !== false);
$cron_count_before_healthy_start = count($GLOBALS['ch_cron']);
PGE_Thank_You_Batch_Worker::create_batch(30, 10);
check('healthy active Start creates no duplicate schedules', count($GLOBALS['ch_cron']), $cron_count_before_healthy_start);

remove_health_events(30, $first30['batch_id']);
$GLOBALS['ch_fail_hooks'] = [
    PGE_Thank_You_Batch_Worker::WORKER_HOOK => true,
    PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK => true,
];
$recovery_failure = PGE_Thank_You_Batch_Worker::create_batch(30, 9);
check('dual-loss rearm failure is explicit', $recovery_failure['reason'], 'batch_recovery_failed');
check('rearm failure still creates no second batch', PGE_Message_Batch::$next, $next_id_before);
$GLOBALS['ch_fail_hooks'] = [];

seed_health_event(31, 1);
$batch31 = PGE_Thank_You_Batch_Worker::create_batch(31, 7)['batch_id'];
remove_health_events(31, $batch31, PGE_Thank_You_Batch_Worker::WORKER_HOOK);
PGE_Thank_You_Batch_Worker::create_batch(31, 7);
check_true('worker-only loss is rearmed', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WORKER_HOOK,
    [31, $batch31]
) !== false);

seed_health_event(32, 1);
$batch32 = PGE_Thank_You_Batch_Worker::create_batch(32, 7)['batch_id'];
remove_health_events(32, $batch32, PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK);
PGE_Thank_You_Batch_Worker::create_batch(32, 7);
check_true('watchdog-only loss is rearmed', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK,
    [32, $batch32]
) !== false);

seed_health_event(33, 1);
$batch33 = PGE_Thank_You_Batch_Worker::create_batch(33, 7)['batch_id'];
remove_health_events(33, $batch33);
PGE_Cartat_Transport::$credentials = false;
$credential_outage_rearm = PGE_Thank_You_Batch_Worker::create_batch(33, 7);
check('existing batch rearms before credential gate', $credential_outage_rearm['result'], 'active_batch_exists');
check_true('credential outage still restores recovery event',
    wp_next_scheduled(PGE_Thank_You_Batch_Worker::WORKER_HOOK, [33, $batch33]) !== false
    || wp_next_scheduled(PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK, [33, $batch33]) !== false
);
PGE_Cartat_Transport::$credentials = true;

// Dangling index and missing-index contracts.
seed_health_event(40, 1);
PGE_Thank_You_Batch_Store::set_active_batch_id(40, 'missing-manifest');
$dangling_health = PGE_Thank_You_Batch_Worker::get_batch_health(40, 'missing-manifest');
check('dangling index is safely diagnosable', $dangling_health['stuck_reason'], 'dangling_active_index');
$fresh40 = PGE_Thank_You_Batch_Worker::create_batch(40, 7);
check('dangling index allows new batch', $fresh40['result'], 'started');
check_true('dangling index replaced by new identity', $fresh40['batch_id'] !== 'missing-manifest');

seed_health_event(41, 1);
$batch41 = PGE_Thank_You_Batch_Worker::create_batch(41, 7)['batch_id'];
PGE_Thank_You_Batch_Store::clear_active_batch_id(41, $batch41);
remove_health_events(41, $batch41, PGE_Thank_You_Batch_Worker::WORKER_HOOK);
PGE_Thank_You_Batch_Worker::run(41, $batch41);
check('known worker completes without active index', PGE_Thank_You_Batch_Store::get($batch41)['status'], 'complete');
check('worker does not recreate missing index blindly', PGE_Thank_You_Batch_Store::get_active_batch_id(41), '');

seed_health_event(42, 1);
$batch42 = PGE_Thank_You_Batch_Worker::create_batch(42, 7)['batch_id'];
$manifest42 = PGE_Thank_You_Batch_Store::get($batch42);
$manifest42['items'][0]['status'] = PGE_Thank_You_Batch_Store::ITEM_SENT;
$manifest42['status'] = PGE_Thank_You_Batch_Store::STATUS_ACTIVE;
$manifest42['updated_at'] = gmdate('Y-m-d H:i:s', time() - 400);
PGE_Thank_You_Batch_Store::save_health_metadata($manifest42);
remove_health_events(42, $batch42);
check('active manifest with no pending work is not stuck', PGE_Thank_You_Batch_Worker::get_batch_health(42, $batch42)['stuck'], false);

$manifest42['status'] = PGE_Thank_You_Batch_Store::STATUS_COMPLETE;
PGE_Thank_You_Batch_Store::save_health_metadata($manifest42);
$replacement42 = PGE_Thank_You_Batch_Worker::create_batch(42, 7);
check('complete manifest with stale index allows replacement', $replacement42['result'], 'started');
check_true('replacement has a new batch id', $replacement42['batch_id'] !== $batch42);

// Watchdog and lock contention recovery never send synchronously.
seed_health_event(50, 1);
$batch50 = PGE_Thank_You_Batch_Worker::create_batch(50, 7)['batch_id'];
remove_health_events(50, $batch50);
$calls_before_watchdog = count(PGE_Thank_You_Message_Service::$calls);
PGE_Thank_You_Batch_Worker::watchdog(50, $batch50);
check('watchdog performs no send', count(PGE_Thank_You_Message_Service::$calls), $calls_before_watchdog);
check_true('watchdog restores missing worker', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WORKER_HOOK,
    [50, $batch50]
) !== false);
check_true('watchdog restores itself', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK,
    [50, $batch50]
) !== false);

remove_health_events(50, $batch50);
$GLOBALS['ch_fail_hooks'] = [PGE_Thank_You_Batch_Worker::WORKER_HOOK => true];
PGE_Thank_You_Batch_Worker::watchdog(50, $batch50);
check('watchdog records worker rearm failure safely',
    PGE_Thank_You_Batch_Store::get($batch50)['last_schedule_failure_type'],
    'worker_schedule_failed'
);
$GLOBALS['ch_fail_hooks'] = [];

seed_health_event(51, 1);
$batch51 = PGE_Thank_You_Batch_Worker::create_batch(51, 7)['batch_id'];
remove_health_events(51, $batch51, PGE_Thank_You_Batch_Worker::WORKER_HOOK);
$GLOBALS['wpdb']->deny_prefix = 'pge_ty_batch_tick_';
$before_busy = PGE_Thank_You_Batch_Worker::get_status(51, $batch51);
$busy_calls = count(PGE_Thank_You_Message_Service::$calls);
PGE_Thank_You_Batch_Worker::run(51, $batch51);
$after_busy = PGE_Thank_You_Batch_Worker::get_status(51, $batch51);
check('busy tick sends nothing', count(PGE_Thank_You_Message_Service::$calls), $busy_calls);
check('busy tick mutates no items', $after_busy['queued'], $before_busy['queued']);
check_true('busy tick preserves continuation', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WORKER_HOOK,
    [51, $batch51]
) !== false);
$GLOBALS['wpdb']->deny_prefix = '';

seed_health_event(52, 5);
$batch52 = PGE_Thank_You_Batch_Worker::create_batch(52, 7)['batch_id'];
remove_health_events(52, $batch52, PGE_Thank_You_Batch_Worker::WORKER_HOOK);
PGE_Thank_You_Batch_Worker::run(52, $batch52);
check('bounded tick leaves continuation work', PGE_Thank_You_Batch_Worker::get_status(52, $batch52)['queued'], 1);
check_true('bounded tick schedules continuation', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WORKER_HOOK,
    [52, $batch52]
) !== false);

seed_health_event(53, 1);
$batch53 = PGE_Thank_You_Batch_Worker::create_batch(53, 7)['batch_id'];
$manifest53 = PGE_Thank_You_Batch_Store::get($batch53);
$manifest53['items'][0]['status'] = PGE_Thank_You_Batch_Store::ITEM_PROCESSING;
$manifest53['items'][0]['processing_started_at'] = PGE_Thank_You_Batch_Store::now();
$manifest53['updated_at'] = PGE_Thank_You_Batch_Store::now();
PGE_Thank_You_Batch_Store::save_health_metadata($manifest53);
remove_health_events(53, $batch53);
check('processing under lease is not stuck', PGE_Thank_You_Batch_Worker::get_batch_health(53, $batch53)['stuck'], false);

seed_health_event(54, 1);
$batch54 = PGE_Thank_You_Batch_Worker::create_batch(54, 7)['batch_id'];
$manifest54 = PGE_Thank_You_Batch_Store::get($batch54);
$manifest54['items'][0]['status'] = PGE_Thank_You_Batch_Store::ITEM_WAITING;
$manifest54['items'][0]['reason'] = 'active_claim';
$manifest54['items'][0]['next_attempt_at'] = gmdate('Y-m-d H:i:s', time() - 1);
PGE_Thank_You_Batch_Store::save($manifest54);
remove_health_events(54, $batch54, PGE_Thank_You_Batch_Worker::WORKER_HOOK);
PGE_Thank_You_Batch_Worker::run(54, $batch54);
check('stale claim waiting item resumes', PGE_Thank_You_Batch_Worker::get_status(54, $batch54)['sent'], 1);

// Simulated 30-minute Cron outage recovers stale processing and sends once.
seed_health_event(60, 1);
$batch60 = PGE_Thank_You_Batch_Worker::create_batch(60, 7)['batch_id'];
$manifest60 = PGE_Thank_You_Batch_Store::get($batch60);
$manifest60['items'][0]['status'] = PGE_Thank_You_Batch_Store::ITEM_PROCESSING;
$manifest60['items'][0]['processing_started_at'] = gmdate('Y-m-d H:i:s', time() - 1800);
$manifest60['items'][0]['processing_token'] = 'crashed-runtime';
$manifest60['updated_at'] = gmdate('Y-m-d H:i:s', time() - 1800);
PGE_Thank_You_Batch_Store::save_health_metadata($manifest60);
remove_health_events(60, $batch60);
$outage_health = PGE_Thank_You_Batch_Worker::get_batch_health(60, $batch60);
check('processing far beyond lease with no events is stuck', $outage_health['stuck'], true);
check('outage health retains processing count', $outage_health['processing'], 1);
$outage_rearm = PGE_Thank_You_Batch_Worker::create_batch(60, 7);
check('outage Start returns same batch', $outage_rearm['batch_id'], $batch60);
remove_health_events(60, $batch60, PGE_Thank_You_Batch_Worker::WORKER_HOOK);
$before_outage_send = count(PGE_Thank_You_Message_Service::$calls);
PGE_Thank_You_Batch_Worker::run(60, $batch60);
check('stale processing recovers to sent', PGE_Thank_You_Batch_Worker::get_status(60, $batch60)['sent'], 1);
check('outage recovery sends exactly once', count(PGE_Thank_You_Message_Service::$calls) - $before_outage_send, 1);

// Complete batches never re-arm.
remove_health_events(60, $batch60);
PGE_Thank_You_Batch_Worker::watchdog(60, $batch60);
check('complete watchdog does not rearm worker', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WORKER_HOOK,
    [60, $batch60]
), false);
check('complete watchdog does not rearm itself', wp_next_scheduled(
    PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK,
    [60, $batch60]
), false);
check('complete batch is never stuck', PGE_Thank_You_Batch_Worker::get_batch_health(60, $batch60)['stuck'], false);

$worker_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-thank-you-batch-worker.php');
$store_source = file_get_contents(dirname(__DIR__) . '/includes/class-pge-thank-you-batch-store.php');
check('no recurring cron introduced', strpos($worker_source, 'wp_schedule_event') === false, true);
check('no cron unscheduling introduced', strpos($worker_source, 'wp_unschedule_event') === false, true);
check('no AJAX endpoint introduced', stripos($worker_source . $store_source, 'wp_ajax') === false, true);
check('no raw error logging introduced', stripos($worker_source . $store_source, 'error_log') === false, true);
check('schedule calls are centralized', substr_count($worker_source, 'wp_schedule_single_event('), 1);

echo "Thank You Cron Health Phase 4B-4B: {$passed}/{$total} passed\n";
if ($failures) {
    foreach ($failures as $failure) echo "FAIL: {$failure}\n";
    exit(1);
}
exit(0);
