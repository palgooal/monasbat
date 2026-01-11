<?php

namespace MonEvents\Services;

if (!defined('ABSPATH')) exit;

class CommentsService
{
    private GateService $gate;

    public function __construct(GateService $gate)
    {
        $this->gate = $gate;
    }

    public function register(): void
    {
        // 1) قواعد فتح/إغلاق التعليقات (حسب إعدادات المناسبة)
        add_filter('comments_open', [$this, 'comments_open_filter'], 20, 2);
        add_filter('pings_open',    [$this, 'comments_open_filter'], 20, 2);

        // 2) شرط: التعليق متاح فقط للـ Logged-in أو لمن اجتاز Gate
        add_filter('comments_open', [$this, 'require_login_or_gate_filter'], 30, 2);

        // 3) إذا التعليقات مخفية: لا نعرض أي تعليق
        add_filter('comments_array', [$this, 'comments_array_filter'], 20, 2);

        // 4) منع إرسال التعليق إذا ممنوع (حتى لو حاول يرسل POST)
        add_action('pre_comment_on_post', [$this, 'block_comment_submit'], 10, 1);

        // 5) السماح للضيف بالتعليق فقط إذا اجتاز Gate
        //    + حقن هوية افتراضية لتجاوز متطلبات WP (name/email)
        add_filter('preprocess_comment', [$this, 'allow_guest_comment_if_gate'], 5);
    }

    /* --------------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------------- */

    private function is_event(int $post_id): bool
    {
        return $this->gate->is_event_post($post_id);
    }

    private function hide_public_comments(int $post_id): bool
    {
        return (int) get_post_meta($post_id, '_mon_hide_public_comments', true) === 1;
    }

    private function close_comments_after(int $post_id): bool
    {
        return (int) get_post_meta($post_id, '_mon_close_comments_after', true) === 1;
    }

    private function is_past(int $post_id): bool
    {
        $date = (string) get_post_meta($post_id, '_mon_event_date', true);
        $time = (string) get_post_meta($post_id, '_mon_event_time', true);

        if (!$date) return false;

        $ts = strtotime($date . ($time ? " $time" : " 23:59"));
        if (!$ts) return false;

        return time() > $ts;
    }

    /* --------------------------------------------------------------------------
     * Filters / Actions
     * -------------------------------------------------------------------------- */

    /**
     * إغلاق التعليقات حسب إعدادات المناسبة (إخفاء/إغلاق بعد التاريخ)
     */
    public function comments_open_filter($open, $post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return $open;
        if (!$this->is_event($post_id)) return $open;

        if ($this->hide_public_comments($post_id)) return false;

        if ($this->close_comments_after($post_id) && $this->is_past($post_id)) {
            return false;
        }

        return $open;
    }

    /**
     * شرط إضافي: التعليق مسموح فقط إذا:
     * - المستخدم مسجل دخول
     * - أو اجتاز Gate (كوكي صالحة ورقم موجود بالقائمة)
     */
    public function require_login_or_gate_filter($open, $post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return $open;
        if (!$this->is_event($post_id)) return $open;

        // إذا مغلقة أصلاً (توجل/تاريخ) خليها مغلقة
        if (!$open) return false;

        if (is_user_logged_in()) return true;

        return $this->gate->gate_passed($post_id);
    }

    /**
     * إذا التعليقات العامة مخفية: لا نعرض أي تعليق
     */
    public function comments_array_filter($comments, $post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return $comments;
        if (!$this->is_event($post_id)) return $comments;

        if ($this->hide_public_comments($post_id)) return [];

        return $comments;
    }

    /**
     * منع إرسال التعليق إذا:
     * - التعليقات مخفية
     * - أو مغلقة بعد انتهاء المناسبة
     * - أو الضيف لم يجتز Gate
     */
    public function block_comment_submit($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !$this->is_event($post_id)) return;

        if ($this->hide_public_comments($post_id)) {
            wp_die('التعليقات غير متاحة لهذه المناسبة.', 403);
        }

        if ($this->close_comments_after($post_id) && $this->is_past($post_id)) {
            wp_die('تم إغلاق التعليقات بعد انتهاء المناسبة.', 403);
        }

        if (!is_user_logged_in() && !$this->gate->gate_passed($post_id)) {
            wp_die('لا يمكنك إضافة تعليق قبل اجتياز التحقق.', 403);
        }
    }

    /**
     * السماح للضيف بالتعليق إذا اجتاز Gate فقط
     * + حقن اسم/إيميل افتراضيين (لأن WP قد يفرضهما)
     */
    public function allow_guest_comment_if_gate($commentdata)
    {
        $post_id = (int) ($commentdata['comment_post_ID'] ?? 0);
        if ($post_id <= 0 || !$this->is_event($post_id)) {
            return $commentdata;
        }

        // لو مسجل دخول: طبيعي
        if (is_user_logged_in()) {
            return $commentdata;
        }

        // ضيف: لازم Gate
        if (!$this->gate->gate_passed($post_id)) {
            wp_die('لا يمكنك إضافة تعليق قبل اجتياز التحقق.', 403);
        }

        $phone = $this->gate->gate_phone($post_id);

        // هوية افتراضية
        $commentdata['comment_author']       = $commentdata['comment_author'] ?: ('Guest ' . substr($phone, -4));
        $commentdata['comment_author_email'] = $commentdata['comment_author_email'] ?: ('p' . md5($phone) . '@invite.local');
        $commentdata['comment_author_url']   = '';

        return $commentdata;
    }
}
