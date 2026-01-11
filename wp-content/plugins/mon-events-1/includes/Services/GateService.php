<?php

namespace MonEvents\Services;

use MonEvents\Helpers;

if (!defined('ABSPATH')) exit;

class GateService
{
    private Helpers $helpers;

    public function __construct(Helpers $helpers)
    {
        $this->helpers = $helpers;
    }

    public function is_event_post(int $post_id): bool
    {
        return get_post_type($post_id) === 'event';
    }

    /**
     * Get invited list (structured first, fallback raw)
     * Returns: [ phone_norm => ['name' => '...'] ]
     */
    public function get_invites_structured(int $event_id): array
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) return [];

        $invites = get_post_meta($event_id, '_mon_invites', true);
        if (is_array($invites) && !empty($invites)) {
            $out = [];
            foreach ($invites as $phone => $row) {
                $p = $this->helpers->normalize_phone($phone);
                if (!$p) continue;
                $out[$p] = [
                    'name' => sanitize_text_field($row['name'] ?? ''),
                ];
            }
            ksort($out);
            return $out;
        }

        // Fallback raw list
        $raw = (string) get_post_meta($event_id, '_mon_invited_phones', true);
        $out = $this->helpers->parse_invites_from_raw_list($raw);
        ksort($out);
        return $out;
    }

    /**
     * Check if normalized phone exists in invites list
     */
    public function is_phone_invited(int $event_id, string $phone_raw_or_norm): bool
    {
        $event_id = (int) $event_id;
        $phone_norm = $this->helpers->normalize_phone($phone_raw_or_norm);

        if ($event_id <= 0 || $phone_norm === '') return false;

        $invites = $this->get_invites_structured($event_id);
        return isset($invites[$phone_norm]);
    }

    /**
     * Gate passed:
     * - Host/Admin bypass
     * - Signed cookie valid AND phone still invited
     */
    public function gate_passed(int $event_id): bool
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) return false;

        $author_id = (int) get_post_field('post_author', $event_id);

        // Host/Admin bypass
        if (is_user_logged_in() && (
            get_current_user_id() === $author_id ||
            current_user_can('edit_post', $event_id) ||
            current_user_can('manage_options')
        )) {
            return true;
        }

        $cookie_name = $this->cookie_name($event_id);
        if (empty($_COOKIE[$cookie_name])) return false;

        [$ok, $cid, $phone_norm] = $this->helpers->verify_invite_cookie_value($_COOKIE[$cookie_name]);
        if (!$ok || (int)$cid !== $event_id) return false;

        return $this->is_phone_invited($event_id, $phone_norm);
    }

    /**
     * Get gate phone from cookie (if gate passed)
     */
    public function gate_phone(int $event_id): string
    {
        $event_id = (int) $event_id;
        $cookie_name = $this->cookie_name($event_id);

        if (empty($_COOKIE[$cookie_name])) return '';
        [$ok, $cid, $phone_norm] = $this->helpers->verify_invite_cookie_value($_COOKIE[$cookie_name]);

        if (!$ok || (int)$cid !== $event_id) return '';
        return $phone_norm ?: '';
    }

    public function cookie_name(int $event_id): string
    {
        return 'mon_inv_' . (int)$event_id;
    }

    /**
     * Set signed cookie after successful check
     */
    public function set_gate_cookie(int $event_id, string $phone_raw_or_norm, int $days = 30): bool
    {
        $event_id = (int) $event_id;
        $phone_norm = $this->helpers->normalize_phone($phone_raw_or_norm);

        if ($event_id <= 0 || $phone_norm === '') return false;

        $value = $this->helpers->make_invite_cookie_value($event_id, $phone_norm);

        return setcookie(
            $this->cookie_name($event_id),
            $value,
            time() + ($days * DAY_IN_SECONDS),
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN ?: '',
            is_ssl(),
            true
        );
    }

    /**
     * Clear cookie (logout from gate)
     */
    public function clear_gate_cookie(int $event_id): void
    {
        setcookie(
            $this->cookie_name($event_id),
            '',
            time() - DAY_IN_SECONDS,
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN ?: '',
            is_ssl(),
            true
        );
    }
}
