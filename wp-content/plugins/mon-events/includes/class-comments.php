<?php
// includes/class-comments.php

if (!defined('ABSPATH')) exit;

class Mon_Events_Comments
{
    /** @var Mon_Events_MVP */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Register hooks related to comments for Events
     */
    public function register(): void
    {
        // Comments toggles for Events
        add_filter('comments_open', [$this, 'event_comments_open_filter'], 20, 2);
        add_filter('pings_open',    [$this, 'event_comments_open_filter'], 20, 2);

        // Require login OR gate passed
        add_filter('comments_open', [$this, 'event_require_login_for_comments'], 30, 2);

        // Hide public comments list if hidden
        add_filter('comments_array', [$this, 'event_comments_array_filter'], 20, 2);

        // Block submission if closed/hidden
        add_action('pre_comment_on_post', [$this, 'event_block_comment_submit'], 10, 1);

        // Allow guest comments if gate passed (bypass "must be registered")
        add_filter('preprocess_comment', [$this, 'event_allow_guest_comment_if_gate'], 5);

        // Open comments by default for newly created events فقط (اختياري)
        add_action('save_post_event', [$this, 'enable_comments_by_default_for_event'], 20, 3);
    }

    /* --------------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------------- */

    private function is_event_post($post_id): bool
    {
        return get_post_type((int)$post_id) === 'event';
    }

    private function event_is_past($post_id): bool
    {
        $post_id = (int)$post_id;

        $date = get_post_meta($post_id, '_mon_event_date', true);
        $time = get_post_meta($post_id, '_mon_event_time', true);

        if (!$date) return false;

        $ts = strtotime($date . ($time ? " $time" : " 23:59"));
        if (!$ts) return false;

        return time() > $ts;
    }

    private function event_hide_public_comments($post_id): bool
    {
        return (int) get_post_meta((int)$post_id, '_mon_hide_public_comments', true) === 1;
    }

    private function event_close_comments_after($post_id): bool
    {
        return (int) get_post_meta((int)$post_id, '_mon_close_comments_after', true) === 1;
    }

    /* --------------------------------------------------------------------------
     * Filters / Actions
     * -------------------------------------------------------------------------- */

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
        $post_id = (int)$post_id;
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

        // لو مغلقة أصلاً بسبب التوجل/التاريخ خلّيها مغلقة
        if (!$open) return false;

        // ✅ Allow if logged in OR gate passed
        if (is_user_logged_in()) return true;

        // استدعاء Gate من البلجن الرئيسي عبر Wrapper public
        return (bool) $this->plugin->gate_passed($post_id);
    }

    /**
     * فتح التعليقات تلقائيًا عند إنشاء مناسبة جديدة فقط (ليس عند التعديل)
     */
    public function enable_comments_by_default_for_event($post_id, $post, $update)
    {
        $post_id = (int)$post_id;

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!$post || $post->post_type !== 'event') return;

        // افتحها فقط عند أول إنشاء
        if ($update) return;

        remove_action('save_post_event', [$this, 'enable_comments_by_default_for_event'], 20);

        wp_update_post([
            'ID'             => $post_id,
            'comment_status' => 'open',
            'ping_status'    => 'closed',
        ]);

        add_action('save_post_event', [$this, 'enable_comments_by_default_for_event'], 20, 3);
    }

    /**
     * Allow guest comments on event IF gate passed.
     * This bypasses the global "Users must be registered to comment" setting for gated guests ONLY.
     */
    public function event_allow_guest_comment_if_gate($commentdata)
    {
        $post_id = (int) ($commentdata['comment_post_ID'] ?? 0);
        if ($post_id <= 0 || !$this->is_event_post($post_id)) {
            return $commentdata;
        }

        // If logged in, normal flow
        if (is_user_logged_in()) {
            return $commentdata;
        }

        // If not logged in, allow only if gate passed
        if (!(bool) $this->plugin->gate_passed($post_id)) {
            wp_die('لا يمكنك إضافة تعليق قبل اجتياز التحقق.', 403);
        }

        // Attach a "virtual identity" based on phone to satisfy WP validation
        $phone = (string) $this->plugin->gate_phone($post_id);

        $commentdata['comment_author']       = $commentdata['comment_author'] ?: ('Guest ' . substr($phone, -4));
        $commentdata['comment_author_email'] = $commentdata['comment_author_email'] ?: ('p' . md5($phone) . '@invite.local');
        $commentdata['comment_author_url']   = '';

        return $commentdata;
    }
}
