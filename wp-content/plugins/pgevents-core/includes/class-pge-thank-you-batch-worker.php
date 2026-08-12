<?php
if (!defined('ABSPATH')) exit;

/** Durable asynchronous worker for manual Thank You batches. */
class PGE_Thank_You_Batch_Worker
{
    const WORKER_HOOK = 'pge_process_thank_you_batch';
    const WATCHDOG_HOOK = 'pge_watchdog_thank_you_batch';
    const CHUNK_SIZE = 4;
    const RETRY_DELAY_SECONDS = 25;
    const WATCHDOG_DELAY_SECONDS = 120;
    const STUCK_AFTER_SECONDS = 300;
    const EVENT_OVERDUE_SECONDS = 120;

    /** @return array<string,mixed> */
    public static function create_batch(int $event_id, int $actor_user_id): array
    {
        if ($event_id <= 0 || get_post_type($event_id) !== 'pge_event') {
            return ['result' => 'error', 'reason' => 'invalid_event'];
        }

        $lock = self::acquire_lock(self::operation_lock_name($event_id));
        if (!$lock) {
            return ['result' => 'busy', 'reason' => 'operation_locked'];
        }

        try {
            $active_batch_id = PGE_Thank_You_Batch_Store::get_active_batch_id($event_id);
            if ($active_batch_id !== '') {
                $active = PGE_Thank_You_Batch_Store::get($active_batch_id);
                if (is_array($active)
                    && (string) ($active['status'] ?? '') === PGE_Thank_You_Batch_Store::STATUS_ACTIVE) {
                    if (!self::ensure_recovery_events($event_id, $active_batch_id)) {
                        return [
                            'result' => 'error',
                            'reason' => 'batch_recovery_failed',
                            'batch_id' => $active_batch_id,
                            'status' => PGE_Thank_You_Batch_Store::summary($active),
                        ];
                    }
                    return [
                        'result' => 'active_batch_exists',
                        'batch_id' => $active_batch_id,
                        'status' => PGE_Thank_You_Batch_Store::summary($active),
                    ];
                }
                PGE_Thank_You_Batch_Store::clear_active_batch_id($event_id, $active_batch_id);
            }

            $transport = PGE_Thank_You_Transport_Factory::resolve();
            if (!$transport->has_credentials()) {
                return ['result' => 'error', 'reason' => 'no_provider_credentials'];
            }

            $resolved = PGE_Message_Recipient_Resolver::resolve(
                $event_id,
                PGE_Message_Type::THANK_YOU,
                'checked_in'
            );
            $recipients = is_array($resolved['recipients'] ?? null)
                ? $resolved['recipients']
                : [];
            $snapshot = [];
            foreach ($recipients as $recipient) {
                $rsvp_id = (int) ($recipient['rsvp_id'] ?? 0);
                $lifecycle = is_scalar($recipient['lifecycle_started_at'] ?? null)
                    ? trim((string) $recipient['lifecycle_started_at'])
                    : '';
                if ($rsvp_id > 0 && $lifecycle !== '') {
                    $snapshot[] = [
                        'rsvp_id' => $rsvp_id,
                        'lifecycle_started_at' => $lifecycle,
                    ];
                }
            }

            if (empty($snapshot)) {
                return ['result' => 'no_eligible', 'batch_id' => null];
            }

            $batch_id = PGE_Message_Batch::generate_batch_id();
            $batch_id = is_scalar($batch_id) ? trim((string) $batch_id) : '';
            if ($batch_id === '') {
                return ['result' => 'error', 'reason' => 'batch_id_generation_failed'];
            }

            if (!PGE_Thank_You_Batch_Store::create($batch_id, $event_id, $actor_user_id, $snapshot)) {
                return ['result' => 'error', 'reason' => 'batch_persistence_failed'];
            }
            if (!PGE_Thank_You_Batch_Store::set_active_batch_id($event_id, $batch_id)) {
                PGE_Thank_You_Batch_Store::delete($batch_id);
                return ['result' => 'error', 'reason' => 'batch_persistence_failed'];
            }
        } finally {
            self::release_lock(self::operation_lock_name($event_id));
        }

        $worker_scheduled = self::schedule_worker($event_id, $batch_id, 1);
        $watchdog_scheduled = self::schedule_watchdog($event_id, $batch_id);
        if (!$worker_scheduled && !$watchdog_scheduled) {
            return [
                'result' => 'error',
                'reason' => 'batch_schedule_failed',
                'batch_id' => $batch_id,
                'status' => PGE_Thank_You_Batch_Store::summary(
                    PGE_Thank_You_Batch_Store::get($batch_id) ?: []
                ),
            ];
        }
        self::spawn_cron();
        $manifest = PGE_Thank_You_Batch_Store::get($batch_id);

        return [
            'result' => 'started',
            'batch_id' => $batch_id,
            'status' => is_array($manifest) ? PGE_Thank_You_Batch_Store::summary($manifest) : [],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function get_status(int $event_id, string $batch_id): ?array
    {
        $manifest = PGE_Thank_You_Batch_Store::get($batch_id);
        if (!is_array($manifest) || (int) ($manifest['event_id'] ?? 0) !== $event_id) {
            return null;
        }
        return PGE_Thank_You_Batch_Store::summary($manifest);
    }

    /**
     * Return PII-free operational diagnostics for a known batch.
     *
     * @return array<string,mixed>|null
     */
    public static function get_batch_health(int $event_id, string $batch_id): ?array
    {
        $manifest = PGE_Thank_You_Batch_Store::get($batch_id);
        if (!is_array($manifest)) {
            if ($batch_id !== ''
                && PGE_Thank_You_Batch_Store::get_active_batch_id($event_id) === $batch_id) {
                return self::missing_manifest_health($event_id, $batch_id);
            }
            return null;
        }
        if ((int) ($manifest['event_id'] ?? 0) !== $event_id) {
            return null;
        }

        $summary = PGE_Thank_You_Batch_Store::summary($manifest);
        $args = [$event_id, $batch_id];
        $next_worker = wp_next_scheduled(self::WORKER_HOOK, $args);
        $next_watchdog = wp_next_scheduled(self::WATCHDOG_HOOK, $args);
        $now = time();
        $updated_at = self::timestamp((string) ($manifest['updated_at'] ?? ''));
        $age_seconds = $updated_at > 0 ? max(0, $now - $updated_at) : 0;
        $has_work = ((int) $summary['queued'] + (int) $summary['processing']
            + (int) $summary['waiting']) > 0;
        $worker_overdue = $next_worker !== false
            && (int) $next_worker < $now - self::EVENT_OVERDUE_SECONDS;
        $watchdog_overdue = $next_watchdog !== false
            && (int) $next_watchdog < $now - self::EVENT_OVERDUE_SECONDS;
        $no_scheduled_events = $next_worker === false && $next_watchdog === false;
        $active = (string) ($manifest['status'] ?? '') === PGE_Thank_You_Batch_Store::STATUS_ACTIVE;
        $stuck = $active && $has_work && $age_seconds > self::STUCK_AFTER_SECONDS
            && ($no_scheduled_events || $worker_overdue || $watchdog_overdue);
        $stuck_reason = '';
        if ($stuck) {
            if ($no_scheduled_events) {
                $stuck_reason = 'no_scheduled_events';
            } elseif ($worker_overdue) {
                $stuck_reason = 'worker_overdue';
            } else {
                $stuck_reason = 'watchdog_overdue';
            }
        }

        return [
            'event_id' => $event_id,
            'batch_id' => $batch_id,
            'batch_status' => (string) ($manifest['status'] ?? ''),
            'total' => (int) $summary['total'],
            'queued' => (int) $summary['queued'],
            'processing' => (int) $summary['processing'],
            'waiting' => (int) $summary['waiting'],
            'sent' => (int) $summary['sent'],
            'failed' => (int) $summary['failed'],
            'ambiguous' => (int) $summary['ambiguous'],
            'skipped' => (int) $summary['skipped'],
            'started_at' => (string) $summary['started_at'],
            'updated_at' => (string) $summary['updated_at'],
            'completed_at' => (string) $summary['completed_at'],
            'last_worker_tick_at' => (string) ($manifest['last_worker_tick_at'] ?? ''),
            'last_watchdog_tick_at' => (string) ($manifest['last_watchdog_tick_at'] ?? ''),
            'last_schedule_failed_at' => (string) ($manifest['last_schedule_failed_at'] ?? ''),
            'last_schedule_failure_type' => (string) ($manifest['last_schedule_failure_type'] ?? ''),
            'has_work' => $has_work,
            'age_seconds' => $age_seconds,
            'next_worker_tick_at' => $next_worker === false ? 0 : (int) $next_worker,
            'next_watchdog_tick_at' => $next_watchdog === false ? 0 : (int) $next_watchdog,
            'worker_overdue' => $worker_overdue,
            'watchdog_overdue' => $watchdog_overdue,
            'stuck' => $stuck,
            'stuck_reason' => $stuck_reason,
        ];
    }

    public static function run(int $event_id, string $batch_id): void
    {
        $manifest = PGE_Thank_You_Batch_Store::get($batch_id);
        if (!is_array($manifest)
            || (int) ($manifest['event_id'] ?? 0) !== $event_id
            || (string) ($manifest['status'] ?? '') !== PGE_Thank_You_Batch_Store::STATUS_ACTIVE) {
            return;
        }
        if (!self::record_heartbeat($event_id, $batch_id, 'worker')) {
            self::schedule_worker($event_id, $batch_id, self::RETRY_DELAY_SECONDS);
            return;
        }
        self::schedule_watchdog($event_id, $batch_id);
        $reservation = self::reserve_chunk($event_id, $batch_id);
        if ($reservation === null) {
            self::schedule_worker($event_id, $batch_id, self::RETRY_DELAY_SECONDS);
            return;
        }
        if (empty($reservation['items'])) {
            self::finish_or_reschedule($event_id, $batch_id);
            return;
        }

        $transport = PGE_Thank_You_Transport_Factory::resolve();
        if (!$transport->has_credentials()) {
            foreach ($reservation['items'] as $item) {
                self::record_outcome($event_id, $batch_id, $item, [
                    'status' => PGE_Thank_You_Batch_Store::ITEM_WAITING,
                    'reason' => 'no_provider_credentials',
                ]);
            }
            self::schedule_worker($event_id, $batch_id, self::RETRY_DELAY_SECONDS);
            return;
        }

        $event = get_post($event_id);
        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        $event_context = [
            'event_name' => $event ? (string) $event->post_title : 'مناسبتنا',
            'event_date' => $event_date_raw !== ''
                ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
                : '',
        ];

        foreach ($reservation['items'] as $item) {
            $eligibility = PGE_Message_Recipient_Resolver::resolve_thank_you_recipient(
                $event_id,
                (int) $item['rsvp_id'],
                (string) $item['lifecycle_started_at']
            );
            if (empty($eligibility['eligible']) || !is_array($eligibility['recipient'] ?? null)) {
                self::record_outcome($event_id, $batch_id, $item, [
                    'status' => PGE_Thank_You_Batch_Store::ITEM_SKIPPED,
                    'reason' => (string) ($eligibility['reason'] ?? 'no_longer_eligible'),
                ]);
                continue;
            }

            $outcome = PGE_Thank_You_Message_Service::process_recipient(
                $event_id,
                (int) $reservation['actor_user_id'],
                $batch_id,
                $eligibility['recipient'],
                $transport,
                $event_context,
                (string) $item['lifecycle_started_at']
            );
            self::record_outcome($event_id, $batch_id, $item, $outcome);
        }

        self::finish_or_reschedule($event_id, $batch_id);
    }

    public static function watchdog(int $event_id, string $batch_id): void
    {
        $manifest = PGE_Thank_You_Batch_Store::get($batch_id);
        if (!is_array($manifest)
            || (int) ($manifest['event_id'] ?? 0) !== $event_id
            || (string) ($manifest['status'] ?? '') !== PGE_Thank_You_Batch_Store::STATUS_ACTIVE) {
            return;
        }

        self::record_heartbeat($event_id, $batch_id, 'watchdog');
        if (!wp_next_scheduled(self::WORKER_HOOK, [$event_id, $batch_id])) {
            if (self::schedule_worker($event_id, $batch_id, 1)) {
                self::spawn_cron();
            }
        }
        self::schedule_watchdog($event_id, $batch_id);
    }

    /** @return array<string,mixed>|null */
    private static function reserve_chunk(int $event_id, string $batch_id): ?array
    {
        $lock_name = self::tick_lock_name($event_id, $batch_id);
        if (!self::acquire_lock($lock_name)) {
            return null;
        }

        try {
            $manifest = PGE_Thank_You_Batch_Store::get($batch_id);
            if (!is_array($manifest)
                || (int) ($manifest['event_id'] ?? 0) !== $event_id
                || (string) ($manifest['status'] ?? '') !== PGE_Thank_You_Batch_Store::STATUS_ACTIVE) {
                return ['items' => [], 'actor_user_id' => 0];
            }

            $now = time();
            foreach ($manifest['items'] as &$item) {
                $status = (string) ($item['status'] ?? '');
                if ($status === PGE_Thank_You_Batch_Store::ITEM_PROCESSING
                    && self::timestamp((string) ($item['processing_started_at'] ?? ''))
                        <= $now - PGE_Thank_You_Claim::CLAIM_LEASE_SECONDS) {
                    self::reset_item_to_queued($item);
                } elseif ($status === PGE_Thank_You_Batch_Store::ITEM_WAITING
                    && self::timestamp((string) ($item['next_attempt_at'] ?? '')) <= $now) {
                    self::reset_item_to_queued($item);
                }
            }
            unset($item);

            $reserved = [];
            foreach ($manifest['items'] as &$item) {
                if (count($reserved) >= self::CHUNK_SIZE) {
                    break;
                }
                if ((string) ($item['status'] ?? '') !== PGE_Thank_You_Batch_Store::ITEM_QUEUED) {
                    continue;
                }

                $token = function_exists('wp_generate_uuid4')
                    ? wp_generate_uuid4()
                    : uniqid('pge_', true);
                $item['status'] = PGE_Thank_You_Batch_Store::ITEM_PROCESSING;
                $item['processing_started_at'] = PGE_Thank_You_Batch_Store::now();
                $item['processing_token'] = $token;
                $item['reason'] = '';
                $reserved[] = $item;
            }
            unset($item);

            PGE_Thank_You_Batch_Store::save($manifest);
            return [
                'items' => $reserved,
                'actor_user_id' => (int) ($manifest['actor_user_id'] ?? 0),
            ];
        } finally {
            self::release_lock($lock_name);
        }
    }

    /** @param array<string,mixed> $reserved @param array<string,mixed> $outcome */
    private static function record_outcome(
        int $event_id,
        string $batch_id,
        array $reserved,
        array $outcome
    ): void {
        $lock_name = self::tick_lock_name($event_id, $batch_id);
        if (!self::acquire_lock($lock_name)) {
            self::schedule_worker($event_id, $batch_id, self::RETRY_DELAY_SECONDS);
            return;
        }

        try {
            $manifest = PGE_Thank_You_Batch_Store::get($batch_id);
            if (!is_array($manifest)) {
                return;
            }
            foreach ($manifest['items'] as &$item) {
                if ((int) ($item['rsvp_id'] ?? 0) !== (int) ($reserved['rsvp_id'] ?? 0)
                    || (string) ($item['processing_token'] ?? '')
                        !== (string) ($reserved['processing_token'] ?? '')) {
                    continue;
                }

                $status = (string) ($outcome['status'] ?? PGE_Thank_You_Batch_Store::ITEM_SKIPPED);
                $allowed = [
                    PGE_Thank_You_Batch_Store::ITEM_WAITING,
                    PGE_Thank_You_Batch_Store::ITEM_SENT,
                    PGE_Thank_You_Batch_Store::ITEM_FAILED,
                    PGE_Thank_You_Batch_Store::ITEM_AMBIGUOUS,
                    PGE_Thank_You_Batch_Store::ITEM_SKIPPED,
                ];
                $item['status'] = in_array($status, $allowed, true)
                    ? $status
                    : PGE_Thank_You_Batch_Store::ITEM_SKIPPED;
                $item['reason'] = (string) ($outcome['reason'] ?? 'unspecified');
                $item['processing_started_at'] = '';
                $item['processing_token'] = '';
                $item['next_attempt_at'] = $item['status'] === PGE_Thank_You_Batch_Store::ITEM_WAITING
                    ? gmdate('Y-m-d H:i:s', time() + PGE_Thank_You_Claim::CLAIM_LEASE_SECONDS)
                    : '';
                break;
            }
            unset($item);
            PGE_Thank_You_Batch_Store::save($manifest);
        } finally {
            self::release_lock($lock_name);
        }
    }

    private static function finish_or_reschedule(int $event_id, string $batch_id): void
    {
        $lock_name = self::tick_lock_name($event_id, $batch_id);
        if (!self::acquire_lock($lock_name)) {
            self::schedule_worker($event_id, $batch_id, self::RETRY_DELAY_SECONDS);
            return;
        }

        $complete = false;
        try {
            $manifest = PGE_Thank_You_Batch_Store::get($batch_id);
            if (!is_array($manifest)) {
                return;
            }
            $complete = PGE_Thank_You_Batch_Store::is_complete($manifest);
            if ($complete) {
                $manifest['status'] = PGE_Thank_You_Batch_Store::STATUS_COMPLETE;
                $manifest['completed_at'] = PGE_Thank_You_Batch_Store::now();
                PGE_Thank_You_Batch_Store::save($manifest);
                PGE_Thank_You_Batch_Store::clear_active_batch_id($event_id, $batch_id);
            }
        } finally {
            self::release_lock($lock_name);
        }

        if (!$complete) {
            self::schedule_worker($event_id, $batch_id, self::RETRY_DELAY_SECONDS);
            self::schedule_watchdog($event_id, $batch_id);
        }
    }

    /** @param array<string,mixed> $item */
    private static function reset_item_to_queued(array &$item): void
    {
        $item['status'] = PGE_Thank_You_Batch_Store::ITEM_QUEUED;
        $item['reason'] = '';
        $item['processing_started_at'] = '';
        $item['processing_token'] = '';
        $item['next_attempt_at'] = '';
    }

    private static function schedule_worker(int $event_id, string $batch_id, int $delay): bool
    {
        return self::schedule_event(
            $event_id,
            $batch_id,
            self::WORKER_HOOK,
            max(1, $delay),
            'worker_schedule_failed'
        );
    }

    private static function schedule_watchdog(int $event_id, string $batch_id): bool
    {
        return self::schedule_event(
            $event_id,
            $batch_id,
            self::WATCHDOG_HOOK,
            self::WATCHDOG_DELAY_SECONDS,
            'watchdog_schedule_failed'
        );
    }

    private static function schedule_event(
        int $event_id,
        string $batch_id,
        string $hook,
        int $delay,
        string $failure_type
    ): bool {
        $args = [$event_id, $batch_id];
        if (wp_next_scheduled($hook, $args) !== false) {
            return true;
        }

        $scheduled = wp_schedule_single_event(time() + $delay, $hook, $args);
        if ($scheduled === true && wp_next_scheduled($hook, $args) !== false) {
            return true;
        }

        self::record_schedule_failure($event_id, $batch_id, $failure_type);
        return false;
    }

    private static function ensure_recovery_events(int $event_id, string $batch_id): bool
    {
        $health = self::get_batch_health($event_id, $batch_id);
        if (!is_array($health) || $health['batch_status'] !== PGE_Thank_You_Batch_Store::STATUS_ACTIVE) {
            return false;
        }
        $had_worker = (int) $health['next_worker_tick_at'] > 0;
        $had_watchdog = (int) $health['next_watchdog_tick_at'] > 0;
        $worker_ok = $had_worker || self::schedule_worker($event_id, $batch_id, 1);
        $watchdog_ok = $had_watchdog || self::schedule_watchdog($event_id, $batch_id);
        if ((!$had_worker && $worker_ok) || (!$had_watchdog && $watchdog_ok)) {
            self::spawn_cron();
        }
        return $worker_ok || $watchdog_ok;
    }

    private static function record_heartbeat(int $event_id, string $batch_id, string $type): bool
    {
        $field = $type === 'watchdog' ? 'last_watchdog_tick_at' : 'last_worker_tick_at';
        return self::update_health_metadata($event_id, $batch_id, [$field => PGE_Thank_You_Batch_Store::now()]);
    }

    private static function record_schedule_failure(
        int $event_id,
        string $batch_id,
        string $failure_type
    ): void {
        $allowed = ['worker_schedule_failed', 'watchdog_schedule_failed'];
        if (!in_array($failure_type, $allowed, true)) {
            return;
        }
        self::update_health_metadata($event_id, $batch_id, [
            'last_schedule_failed_at' => PGE_Thank_You_Batch_Store::now(),
            'last_schedule_failure_type' => $failure_type,
        ]);
    }

    /** @param array<string,string> $metadata */
    private static function update_health_metadata(
        int $event_id,
        string $batch_id,
        array $metadata
    ): bool {
        $lock_name = self::tick_lock_name($event_id, $batch_id);
        if (!self::acquire_lock($lock_name)) {
            return false;
        }
        try {
            $manifest = PGE_Thank_You_Batch_Store::get($batch_id);
            if (!is_array($manifest)
                || (int) ($manifest['event_id'] ?? 0) !== $event_id
                || (string) ($manifest['status'] ?? '') !== PGE_Thank_You_Batch_Store::STATUS_ACTIVE) {
                return false;
            }
            foreach ($metadata as $field => $value) {
                $manifest[$field] = $value;
            }
            return PGE_Thank_You_Batch_Store::save_health_metadata($manifest);
        } finally {
            self::release_lock($lock_name);
        }
    }

    /** @return array<string,mixed> */
    private static function missing_manifest_health(int $event_id, string $batch_id): array
    {
        return [
            'event_id' => $event_id,
            'batch_id' => $batch_id,
            'batch_status' => 'missing',
            'total' => 0,
            'queued' => 0,
            'processing' => 0,
            'waiting' => 0,
            'sent' => 0,
            'failed' => 0,
            'ambiguous' => 0,
            'skipped' => 0,
            'started_at' => '',
            'updated_at' => '',
            'completed_at' => '',
            'last_worker_tick_at' => '',
            'last_watchdog_tick_at' => '',
            'last_schedule_failed_at' => '',
            'last_schedule_failure_type' => '',
            'has_work' => false,
            'age_seconds' => 0,
            'next_worker_tick_at' => 0,
            'next_watchdog_tick_at' => 0,
            'worker_overdue' => false,
            'watchdog_overdue' => false,
            'stuck' => true,
            'stuck_reason' => 'dangling_active_index',
        ];
    }

    private static function spawn_cron(): void
    {
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    private static function operation_lock_name(int $event_id): string
    {
        return 'pge_ty_batch_op_' . md5((string) $event_id);
    }

    private static function tick_lock_name(int $event_id, string $batch_id): string
    {
        return 'pge_ty_batch_tick_' . md5($event_id . '|' . $batch_id);
    }

    private static function acquire_lock(string $name): bool
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) === 1;
    }

    private static function release_lock(string $name): void
    {
        global $wpdb;
        if (is_object($wpdb) && method_exists($wpdb, 'query')) {
            $wpdb->query($wpdb->prepare('DO RELEASE_LOCK(%s)', $name));
        }
    }

    private static function timestamp(string $mysql_time): int
    {
        $timestamp = strtotime($mysql_time . ' UTC');
        return $timestamp === false ? 0 : $timestamp;
    }
}

add_action(PGE_Thank_You_Batch_Worker::WORKER_HOOK, [PGE_Thank_You_Batch_Worker::class, 'run'], 10, 2);
add_action(PGE_Thank_You_Batch_Worker::WATCHDOG_HOOK, [PGE_Thank_You_Batch_Worker::class, 'watchdog'], 10, 2);
