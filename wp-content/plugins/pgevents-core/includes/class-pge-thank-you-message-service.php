<?php
if (!defined('ABSPATH')) exit;

/**
 * Manual Thank You orchestration — Phase 4B-2.
 *
 * Eligibility belongs exclusively to PGE_Message_Recipient_Resolver and
 * once-only/lifecycle fencing belongs exclusively to PGE_Thank_You_Claim.
 * This service only coordinates resolve -> claim -> content -> Cartat text
 * transport -> finalize. It owns no AJAX, UI, queue, cron, credit, schema,
 * RSVP, check-in, or invitation lifecycle behavior.
 */
class PGE_Thank_You_Message_Service
{
    /**
     * Execute the existing Claim -> content -> Cartat text -> finalize path for
     * one recipient. Eligibility must be revalidated by the caller immediately
     * before calling this method.
     *
     * @return array{status:string,reason:string,claimed:bool}
     */
    public static function process_recipient(
        int $event_id,
        int $actor_user_id,
        string $batch_id,
        array $recipient,
        PGE_Cartat_Transport $transport,
        array $event_context = [],
        ?string $expected_lifecycle_started_at = null
    ): array {
        $phone = is_scalar($recipient['phone'] ?? null)
            ? trim((string) $recipient['phone'])
            : '';
        $rsvp_id = (int) ($recipient['rsvp_id'] ?? 0);

        $claim = PGE_Thank_You_Claim::claim(
            $event_id,
            $rsvp_id,
            $phone,
            $batch_id,
            $actor_user_id,
            'cartat'
        );
        $claim_result = (string) ($claim['result'] ?? 'error');

        if ($claim_result === 'already_sent') {
            return ['status' => 'skipped', 'reason' => 'already_sent', 'claimed' => false];
        }
        if ($claim_result === 'already_in_progress') {
            return ['status' => 'waiting', 'reason' => 'active_claim', 'claimed' => false];
        }
        if ($claim_result !== 'claimed') {
            return ['status' => 'skipped', 'reason' => 'claim_error', 'claimed' => false];
        }

        $log_id = (int) ($claim['log_id'] ?? 0);
        if ($log_id <= 0) {
            return ['status' => 'skipped', 'reason' => 'claim_error', 'claimed' => false];
        }

        if ($expected_lifecycle_started_at !== null
            && (string) ($claim['lifecycle_started_at'] ?? '') !== $expected_lifecycle_started_at) {
            PGE_Thank_You_Claim::finalize_failure($log_id, PGE_Message_Log::STATUS_FAILED);
            return ['status' => 'skipped', 'reason' => 'lifecycle_changed', 'claimed' => true];
        }

        try {
            $content = PGE_Message_Content_Resolver::resolve(
                PGE_Message_Type::THANK_YOU,
                $event_id,
                [
                    'guest_name' => (string) ($recipient['name'] ?? ''),
                    'event_name' => (string) ($event_context['event_name'] ?? ''),
                    'event_date' => (string) ($event_context['event_date'] ?? ''),
                ]
            );
            $text = (string) ($content['text'] ?? '');
            $wa_number = $transport->format_number($phone);
        } catch (\Throwable $e) {
            $status = PGE_Thank_You_Claim::finalize_failure($log_id, PGE_Message_Log::STATUS_FAILED)
                ? 'failed'
                : 'ambiguous';
            return ['status' => $status, 'reason' => 'content_error', 'claimed' => true];
        }

        try {
            $transport_result = $transport->send_text($wa_number, $text);
            $outcome = $transport->interpret_result($transport_result);
        } catch (\Throwable $e) {
            $outcome = 'transport_error';
        }

        if ($outcome === 'accepted') {
            if (PGE_Thank_You_Claim::finalize_success($log_id, $rsvp_id)
                || PGE_Thank_You_Claim::is_sent($rsvp_id)) {
                return ['status' => 'sent', 'reason' => 'accepted', 'claimed' => true];
            }

            PGE_Thank_You_Claim::finalize_failure(
                $log_id,
                PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR
            );
            return ['status' => 'ambiguous', 'reason' => 'finalize_error', 'claimed' => true];
        }

        if ($outcome === 'rejected') {
            $status = PGE_Thank_You_Claim::finalize_failure($log_id, PGE_Message_Log::STATUS_FAILED)
                ? 'failed'
                : 'ambiguous';
            return ['status' => $status, 'reason' => 'rejected', 'claimed' => true];
        }

        PGE_Thank_You_Claim::finalize_failure(
            $log_id,
            PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR
        );
        return ['status' => 'ambiguous', 'reason' => 'transport_error', 'claimed' => true];
    }

    /**
     * Compatibility path that processes current eligible recipients
     * synchronously. Durable batches call process_recipient() per queued item.
     *
     * @return array{result:string,reason?:string,batch_id:?string,eligible:int,claimed:int,sent:int,failed:int,ambiguous:int,skipped_already_sent:int,skipped_active_claim:int,skipped_claim_error:int,resolver:array<string,mixed>}
     */
    public static function send_thank_you_batch(int $event_id, int $actor_user_id): array
    {
        $summary = [
            'result'                  => 'completed',
            'batch_id'                => null,
            'eligible'                => 0,
            'claimed'                 => 0,
            'sent'                    => 0,
            'failed'                  => 0,
            'ambiguous'               => 0,
            'skipped_already_sent'    => 0,
            'skipped_active_claim'    => 0,
            'skipped_claim_error'     => 0,
            'resolver'                => [],
        ];

        if ($event_id <= 0 || get_post_type($event_id) !== 'pge_event') {
            $summary['result'] = 'error';
            $summary['reason'] = 'invalid_event';
            return $summary;
        }

        $resolved = PGE_Message_Recipient_Resolver::resolve(
            $event_id,
            PGE_Message_Type::THANK_YOU,
            'checked_in'
        );
        $recipients = is_array($resolved['recipients'] ?? null)
            ? $resolved['recipients']
            : [];
        $summary['eligible'] = count($recipients);
        $summary['resolver'] = $resolved;
        unset($summary['resolver']['recipients']);

        if (empty($recipients)) {
            return $summary;
        }

        $transport = new PGE_Cartat_Transport();
        if (!$transport->has_credentials()) {
            $summary['result'] = 'error';
            $summary['reason'] = 'no_provider_credentials';
            return $summary;
        }

        $batch_id = PGE_Message_Batch::generate_batch_id();
        if (!is_scalar($batch_id) || trim((string) $batch_id) === '') {
            $summary['result'] = 'error';
            $summary['reason'] = 'batch_id_generation_failed';
            return $summary;
        }
        $batch_id = trim((string) $batch_id);
        $summary['batch_id'] = $batch_id;

        $event = get_post($event_id);
        $event_name = $event ? (string) $event->post_title : 'مناسبتنا';
        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        $event_date = $event_date_raw !== ''
            ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
            : '';

        $event_context = ['event_name' => $event_name, 'event_date' => $event_date];
        foreach ($recipients as $recipient) {
            $outcome = self::process_recipient(
                $event_id,
                $actor_user_id,
                $batch_id,
                $recipient,
                $transport,
                $event_context
            );

            if (!empty($outcome['claimed'])) {
                $summary['claimed']++;
            }

            $status = (string) ($outcome['status'] ?? 'skipped');
            $reason = (string) ($outcome['reason'] ?? 'claim_error');
            if (isset($summary[$status])) {
                $summary[$status]++;
            } elseif ($reason === 'already_sent') {
                $summary['skipped_already_sent']++;
            } elseif ($reason === 'active_claim') {
                $summary['skipped_active_claim']++;
            } else {
                $summary['skipped_claim_error']++;
            }
        }

        return $summary;
    }
}
