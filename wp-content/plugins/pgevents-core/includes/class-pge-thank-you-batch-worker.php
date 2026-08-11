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

    /** @return array<string,mixed> */
    public static function create_batch(int $event_id, int $actor_user_id): array
    {
        if ($event_id <= 0 || get_post_type($event_id) !== 'pge_event') {
            return ['result' => 'error', 'reason' => 'invalid_event'];
        }

        $transport = new PGE_Cartat_Transport();
        if (!$transport->has_credentials()) {
            return ['result' => 'error', 'reason' => 'no_provider_credentials'];
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
                    return [
                        'result' => 'active_batch_exists',
                        'batch_id' => $active_batch_id,
                        'status' => PGE_Thank_You_Batch_Store::summary($active),
                    ];
                }
                PGE_Thank_You_Batch_Store::clear_active_batch_id($event_id, $active_batch_id);
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

        self::schedule_worker($event_id, $batch_id, 1);
        self::schedule_watchdog($event_id, $batch_id);
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

    public static function run(int $event_id, string $batch_id): void
    {
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

        $transport = new PGE_Cartat_Transport();
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

        self::schedule_watchdog($event_id, $batch_id);
        if (!wp_next_scheduled(self::WORKER_HOOK, [$event_id, $batch_id])) {
            self::schedule_worker($event_id, $batch_id, 1);
            self::spawn_cron();
        }
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

    private static function schedule_worker(int $event_id, string $batch_id, int $delay): void
    {
        $args = [$event_id, $batch_id];
        if (!wp_next_scheduled(self::WORKER_HOOK, $args)) {
            wp_schedule_single_event(time() + max(1, $delay), self::WORKER_HOOK, $args);
        }
    }

    private static function schedule_watchdog(int $event_id, string $batch_id): void
    {
        $args = [$event_id, $batch_id];
        if (!wp_next_scheduled(self::WATCHDOG_HOOK, $args)) {
            wp_schedule_single_event(time() + self::WATCHDOG_DELAY_SECONDS, self::WATCHDOG_HOOK, $args);
        }
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
