<?php
// includes/class-comments-gate.php

if (!defined('ABSPATH')) exit;

class Mon_Events_Comments_Gate
{
    /** @var Mon_Events_MVP */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function register(): void
    {
        // Comments toggles for Events
        add_filter('comments_open', [$this, 'event_comments_open_filter'], 20, 2);
        add_filter('pings_open',    [$this, 'event_comments_open_filter'], 20, 2);

        add_filter('comments_open', [$this, 'event_require_login_for_comments'], 30, 2);
        add_filter('comments_array', [$this, 'event_comments_array_filter'], 20, 2);

        add_action('pre_comment_on_post', [$this, 'event_block_comment_submit'], 10, 1);

        // Allow gated guest comments (bypass "users must be registered" for gated only)
        add_filter('preprocess_comment', [$this, 'event_allow_guest_comment_if_gate'], 5);

        // Open comments by default for newly created events (optional)
        add_action('save_post_event', [$this, 'enable_comments_by_default_for_event'], 20, 3);
    }

    /* --------------------------------------------------------------------------
     * Comments logic
     * -------------------------------------------------------------------------- */

    private function is_event_post($post_id): bool
    {
        return get_post_type($post_id) === 'event';
    }

    private function event_is_past($post_id): bool
    {
        $date = get_post_meta($post_id, '_mon_event_date', true);
        $time = get_post_meta($post_id, '_mon_event_time', true);

        if (!$date) return false;

        $ts = strtotime($date . ($time ? " $time" : " 23:59"));
        if (!$ts) return false;

        return time() > $ts;
    }

    private function event_hide_public_comments($post_id): bool
    {
        return (int) get_post_meta($post_id, '_mon_hide_public_comments', true) === 1;
    }

    private function event_close_comments_after($post_id): bool
    {
        return (int) get_post_meta($post_id, '_mon_close_comments_after', true) === 1;
    }

    public function event_comments_open_filter($open, $post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return $open;
        if (!$this->is_event_post($post_id)) return $open;

        if ($this->event_hide_public_comments($post_id)) return false;
        if ($this->event_close_comments_after($post_id) && $this->event_is_past($post_id)) return false;

        return true;
    }

    public function event_comments_array_filter($comments, $post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return $comments;
        if (!$this->is_event_post($post_id)) return $comments;

        if ($this->event_hide_public_comments($post_id)) return [];

        return $comments;
    }

    public function event_block_comment_submit($post_id)
    {
        $post_id = (int) $post_id;
        if (!$this->is_event_post($post_id)) return;

        $is_hidden      = $this->event_hide_public_comments($post_id);
        $is_closed_after = $this->event_close_comments_after($post_id) && $this->event_is_past($post_id);

        if ($is_hidden || $is_closed_after) {
            wp_die('التعليقات غير متاحة لهذه المناسبة.', 403);
        }
    }

    public function event_require_login_for_comments($open, $post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return $open;
        if (!$this->is_event_post($post_id)) return $open;

        if (!$open) return false;

        // ✅ Allow if logged in OR gate passed
        if (is_user_logged_in()) return true;

        return $this->gate_passed($post_id);
    }

    /**
     * Allow guest comment if gate passed (creates a virtual author/email).
     */
    public function event_allow_guest_comment_if_gate($commentdata)
    {
        $post_id = (int) ($commentdata['comment_post_ID'] ?? 0);
        if ($post_id <= 0 || !$this->is_event_post($post_id)) {
            return $commentdata;
        }

        if (is_user_logged_in()) {
            return $commentdata;
        }

        if (!$this->gate_passed($post_id)) {
            wp_die('لا يمكنك إضافة تعليق قبل اجتياز التحقق.', 403);
        }

        $phone = $this->gate_phone($post_id);

        $commentdata['comment_author']       = $commentdata['comment_author'] ?: ('Guest ' . substr($phone, -4));
        $commentdata['comment_author_email'] = $commentdata['comment_author_email'] ?: ('p' . md5($phone) . '@invite.local');
        $commentdata['comment_author_url']   = '';

        return $commentdata;
    }

    /**
     * Open comments automatically only when creating a new event (not update)
     */
    public function enable_comments_by_default_for_event($post_id, $post, $update)
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!$post || $post->post_type !== 'event') return;

        if ($update) return;

        remove_action('save_post_event', [$this, 'enable_comments_by_default_for_event'], 20);

        wp_update_post([
            'ID'             => $post_id,
            'comment_status' => 'open',
            'ping_status'    => 'closed',
        ]);

        add_action('save_post_event', [$this, 'enable_comments_by_default_for_event'], 20, 3);
    }

    /* --------------------------------------------------------------------------
     * Gate + Cookie + Invites helpers (Frontend safe)
     * -------------------------------------------------------------------------- */

    public function gate_passed($event_id): bool
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

        $cookie_name = 'mon_inv_' . $event_id;
        if (empty($_COOKIE[$cookie_name])) return false;

        [$ok, $cid, $phone_norm] = $this->verify_invite_cookie_value($_COOKIE[$cookie_name]);
        if (!$ok || (int)$cid !== $event_id) return false;

        return $this->is_phone_invited($event_id, $phone_norm);
    }

    public function gate_phone($event_id): string
    {
        $event_id = (int) $event_id;
        $cookie_name = 'mon_inv_' . $event_id;

        if (empty($_COOKIE[$cookie_name])) return '';
        [$ok, $cid, $phone_norm] = $this->verify_invite_cookie_value($_COOKIE[$cookie_name]);
        if (!$ok || (int)$cid !== $event_id) return '';

        return $phone_norm ?: '';
    }

    public function make_invite_cookie_value($event_id, $phone_norm): string
    {
        $payload = $event_id . '|' . $phone_norm;
        $sig = hash_hmac('sha256', $payload, wp_salt('auth'));
        return base64_encode($payload . '|' . $sig);
    }

    public function verify_invite_cookie_value($cookie_value): array
    {
        $decoded = base64_decode((string) $cookie_value, true);
        if (!$decoded) return [false, 0, ''];

        $parts = explode('|', $decoded);
        if (count($parts) !== 3) return [false, 0, ''];

        [$event_id, $phone_norm, $sig] = $parts;

        $event_id   = (int) $event_id;
        $phone_norm = (string) $phone_norm;

        if ($event_id <= 0 || !$phone_norm || !$sig) return [false, 0, ''];

        $payload  = $event_id . '|' . $phone_norm;
        $expected = hash_hmac('sha256', $payload, wp_salt('auth'));

        if (!hash_equals($expected, $sig)) return [false, 0, ''];

        return [true, $event_id, $phone_norm];
    }

    private function normalize_phone($raw, $default_cc = '966'): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if (!$digits) return '';

        if (function_exists('str_starts_with') && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (substr($digits, 0, 2) === '00') {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && substr($digits, 0, 1) === '0') {
            $digits = $default_cc . substr($digits, 1);
        }

        if (strlen($digits) === 9 && substr($digits, 0, 1) === '5') {
            $digits = $default_cc . $digits;
        }

        return $digits;
    }

    private function parse_invites_from_raw_list($raw): array
    {
        $parts = preg_split('/[\r\n,]+/', (string) $raw);
        $out = [];

        foreach ($parts as $p) {
            $phone = $this->normalize_phone(trim($p));
            if (!$phone) continue;
            $out[$phone] = ['name' => ''];
        }

        return $out;
    }

    private function get_invites_structured($event_id): array
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) return [];

        $invites = get_post_meta($event_id, '_mon_invites', true);
        if (is_array($invites) && !empty($invites)) {
            $out = [];
            foreach ($invites as $phone => $row) {
                $p = $this->normalize_phone($phone);
                if (!$p) continue;
                $out[$p] = [
                    'name' => sanitize_text_field($row['name'] ?? ''),
                ];
            }
            return $out;
        }

        $raw = (string) get_post_meta($event_id, '_mon_invited_phones', true);
        return $this->parse_invites_from_raw_list($raw);
    }

    private function is_phone_invited(int $event_id, string $phone_norm): bool
    {
        $event_id = (int) $event_id;
        $phone_norm = $this->normalize_phone($phone_norm);

        if ($event_id <= 0 || $phone_norm === '') return false;

        $invites = $this->get_invites_structured($event_id);
        return isset($invites[$phone_norm]);
    }
}
