<?php
if (!defined('ABSPATH')) exit;

/**
 * Durable, non-autoloaded WordPress persistence for asynchronous Thank You
 * batches. Message Log remains attempt history; this manifest is queue state.
 */
class PGE_Thank_You_Batch_Store
{
    const VERSION = 1;
    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETE = 'complete';

    const ITEM_QUEUED = 'queued';
    const ITEM_PROCESSING = 'processing';
    const ITEM_WAITING = 'waiting';
    const ITEM_SENT = 'sent';
    const ITEM_FAILED = 'failed';
    const ITEM_AMBIGUOUS = 'ambiguous';
    const ITEM_SKIPPED = 'skipped';

    private static function batch_key(string $batch_id): string
    {
        return 'pge_thank_you_batch_' . md5($batch_id);
    }

    private static function active_key(int $event_id): string
    {
        return 'pge_thank_you_active_batch_' . $event_id;
    }

    public static function now(): string
    {
        return function_exists('current_time')
            ? (string) current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }

    /** @param array<int,array<string,mixed>> $recipients */
    public static function create(
        string $batch_id,
        int $event_id,
        int $actor_user_id,
        array $recipients
    ): bool {
        if ($batch_id === '' || $event_id <= 0 || empty($recipients)) {
            return false;
        }

        $items = [];
        foreach ($recipients as $recipient) {
            $rsvp_id = (int) ($recipient['rsvp_id'] ?? 0);
            $lifecycle_started_at = is_scalar($recipient['lifecycle_started_at'] ?? null)
                ? trim((string) $recipient['lifecycle_started_at'])
                : '';
            if ($rsvp_id <= 0 || $lifecycle_started_at === '') {
                continue;
            }

            $items[] = [
                'rsvp_id'              => $rsvp_id,
                'lifecycle_started_at' => $lifecycle_started_at,
                'status'               => self::ITEM_QUEUED,
                'reason'               => '',
                'processing_started_at'=> '',
                'processing_token'     => '',
                'next_attempt_at'      => '',
            ];
        }

        if (empty($items)) {
            return false;
        }

        $now = self::now();
        $manifest = [
            'version'       => self::VERSION,
            'batch_id'      => $batch_id,
            'event_id'      => $event_id,
            'actor_user_id' => $actor_user_id,
            'status'        => self::STATUS_ACTIVE,
            'started_at'    => $now,
            'updated_at'    => $now,
            'completed_at'  => '',
            'items'         => $items,
        ];

        return (bool) add_option(self::batch_key($batch_id), $manifest, '', false);
    }

    /** @return array<string,mixed>|null */
    public static function get(string $batch_id): ?array
    {
        $value = get_option(self::batch_key($batch_id), null);
        return is_array($value) ? $value : null;
    }

    public static function delete(string $batch_id): bool
    {
        return $batch_id !== '' && (bool) delete_option(self::batch_key($batch_id));
    }

    /** @param array<string,mixed> $manifest */
    public static function save(array $manifest): bool
    {
        $batch_id = is_scalar($manifest['batch_id'] ?? null)
            ? trim((string) $manifest['batch_id'])
            : '';
        if ($batch_id === '') {
            return false;
        }

        $manifest['updated_at'] = self::now();
        return (bool) update_option(self::batch_key($batch_id), $manifest, false);
    }

    public static function get_active_batch_id(int $event_id): string
    {
        $value = get_option(self::active_key($event_id), '');
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function set_active_batch_id(int $event_id, string $batch_id): bool
    {
        if ($event_id <= 0 || $batch_id === '') {
            return false;
        }
        return (bool) update_option(self::active_key($event_id), $batch_id, false);
    }

    public static function clear_active_batch_id(int $event_id, string $expected_batch_id): bool
    {
        if (self::get_active_batch_id($event_id) !== $expected_batch_id) {
            return false;
        }
        return (bool) delete_option(self::active_key($event_id));
    }

    /** @param array<string,mixed> $manifest */
    public static function is_complete(array $manifest): bool
    {
        foreach ((array) ($manifest['items'] ?? []) as $item) {
            if (in_array((string) ($item['status'] ?? ''), [
                self::ITEM_QUEUED,
                self::ITEM_PROCESSING,
                self::ITEM_WAITING,
            ], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return aggregate, PII-free progress for future status endpoints.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public static function summary(array $manifest): array
    {
        $counts = [
            'queued' => 0,
            'processing' => 0,
            'waiting' => 0,
            'sent' => 0,
            'failed' => 0,
            'ambiguous' => 0,
            'skipped' => 0,
        ];
        $skipped_reasons = [];

        foreach ((array) ($manifest['items'] ?? []) as $item) {
            $status = (string) ($item['status'] ?? '');
            if ($status === self::ITEM_WAITING) {
                $counts['waiting']++;
            } elseif (isset($counts[$status])) {
                $counts[$status]++;
            }

            if ($status === self::ITEM_SKIPPED) {
                $reason = (string) ($item['reason'] ?? 'unspecified');
                $skipped_reasons[$reason] = ($skipped_reasons[$reason] ?? 0) + 1;
            }
        }

        return [
            'batch_id'        => (string) ($manifest['batch_id'] ?? ''),
            'total'           => count((array) ($manifest['items'] ?? [])),
            'queued'          => $counts['queued'],
            'processing'      => $counts['processing'],
            'waiting'         => $counts['waiting'],
            'sent'            => $counts['sent'],
            'failed'          => $counts['failed'],
            'ambiguous'       => $counts['ambiguous'],
            'skipped'         => $counts['skipped'],
            'skipped_reasons' => $skipped_reasons,
            'complete'        => (string) ($manifest['status'] ?? '') === self::STATUS_COMPLETE,
            'started_at'      => (string) ($manifest['started_at'] ?? ''),
            'updated_at'      => (string) ($manifest['updated_at'] ?? ''),
            'completed_at'    => (string) ($manifest['completed_at'] ?? ''),
        ];
    }
}
