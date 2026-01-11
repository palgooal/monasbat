<?php

/**
 * Plugin Name: Mon Events (MVP)
 * Description: Custom Events CPT + RSVP (MVP) for KLEO setup.
 * Version: 0.2.0
 */


if (!defined('ABSPATH')) exit;
require_once plugin_dir_path(__FILE__) . 'includes/class-comments.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-invites.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-rsvp.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-buddypress.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin.php';





class Mon_Events_MVP
{
    /**
     * RSVP_META_KEY
     * نخزن الردود على RSVP كـ array بالشكل:
     *  user_id => ['status' => attending|declined, 'updated_at' => '...']
     */
    const RSVP_META_KEY = '_mon_rsvps';
    /** @var Mon_Events_Comments */
    private $comments;
    private $invites;
    /** @var Mon_Events_RSVP */
    private $rsvp;
    /** @var Mon_Events_BuddyPress */
    private $bp;
    /** @var Mon_Events_Admin */
    private $admin;




    public function __construct()
    {
        $this->comments = new Mon_Events_Comments($this);
        $this->comments->register();
        $this->invites = new Mon_Events_Invites($this);
        $this->invites->register();
        $this->rsvp = new Mon_Events_RSVP($this);
        $this->rsvp->register();
        $this->bp = new Mon_Events_BuddyPress($this);
        $this->bp->register();
        $this->admin = new Mon_Events_Admin($this);
        $this->admin->register();


        // CPT + Tax
        add_action('init', [$this, 'register_cpt_tax'], 0);

         // BuddyPress Tabs (MVP)
        add_action('bp_setup_nav', [$this, 'bp_add_my_events_tab'], 100);
        add_action('bp_setup_nav', [$this, 'bp_add_my_invites_tab'], 101);

    }

    /**
     * Public wrapper: used by other modules (like Comments).
     */
    public function gate_passed($event_id): bool
    {
        return $this->invites ? (bool) $this->invites->gate_passed((int)$event_id) : false;
    }

    /**
     * Public wrapper: used by other modules (like Comments).
     */
    public function gate_phone($event_id): string
    {
        return $this->invites ? (string) $this->invites->gate_phone((int)$event_id) : '';
    }
    public function rsvp(): Mon_Events_RSVP
    {
        return $this->rsvp;
    }



    /* --------------------------------------------------------------------------
     * BuddyPress - My Events Tab
     * -------------------------------------------------------------------------- */

    public function bp_add_my_events_tab()
    {
        if (!function_exists('bp_core_new_nav_item')) return;

        bp_core_new_nav_item([
            'name' => 'مناسباتي',
            'slug' => 'my-events',
            'screen_function' => function () {
                add_action('bp_template_content', [$this, 'bp_render_my_events_tab']);
                bp_core_load_template('members/single/plugins');
            },
            'position' => 35,
            'default_subnav_slug' => 'my-events',
        ]);
    }

    public function bp_render_my_events_tab()
    {
        $user_id = bp_displayed_user_id();
        if (!$user_id) return;

        $events = get_posts([
            'post_type' => 'event',
            'author' => $user_id,
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        echo '<div class="mon-card" style="padding:16px;border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(0,0,0,.06)">';
        echo '<h3 style="margin-top:0">مناسباتي</h3>';

        if (!$events) {
            echo '<p style="color:#6b7280">لا توجد مناسبات بعد.</p></div>';
            return;
        }

        echo '<ul style="margin:0;padding-right:18px">';
        foreach ($events as $ev) {
            $date = get_post_meta($ev->ID, '_mon_event_date', true);
            echo '<li style="margin:8px 0"><a href="' . esc_url(get_permalink($ev->ID)) . '">' . esc_html($ev->post_title) . '</a>'
                . ($date ? ' <span style="color:#6b7280;font-size:12px"> — ' . esc_html($date) . '</span>' : '')
                . '</li>';
        }
        echo '</ul></div>';
    }

    /* --------------------------------------------------------------------------
     * CPT + Taxonomy
     * -------------------------------------------------------------------------- */

    public function register_cpt_tax()
    {
        register_post_type('event', [
            'labels' => [
                'name' => 'المناسبات',
                'singular_name' => 'مناسبة',
                'add_new' => 'إضافة مناسبة',
                'add_new_item' => 'إضافة مناسبة جديدة',
                'edit_item' => 'تعديل المناسبة',
                'new_item' => 'مناسبة جديدة',
                'view_item' => 'عرض المناسبة',
                'search_items' => 'بحث في المناسبات',
            ],
            'public' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => 'events'],
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title', 'editor', 'thumbnail', 'author', 'comments'],
            'show_in_rest' => true,
        ]);

        register_taxonomy('event_type', ['event'], [
            'labels' => [
                'name' => 'نوع المناسبة',
                'singular_name' => 'نوع المناسبة',
            ],
            'public' => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'event-type'],
        ]);
    }

    /* --------------------------------------------------------------------------
     * RSVP Shortcode (unchanged)
     * -------------------------------------------------------------------------- */

    public function shortcode_rsvp($atts)
    {
        if (!is_singular('event')) return '';

        $event_id = get_the_ID();

        // ✅ Allow if: logged in OR passed gate
        $gate_ok   = $this->mon_gate_passed($event_id);
        $gate_phone = $this->mon_gate_phone($event_id);

        if (!is_user_logged_in() && !$gate_ok) {
            return '<div class="mon-rsvp-box">الرجاء إدخال رقم الدعوة أولاً لفتح RSVP.</div>';
        }

        // Identify RSVP key
        // - logged user => u:{id}
        // - gated guest => p:{phone}
        $rsvp_key = is_user_logged_in()
            ? ('u:' . get_current_user_id())
            : ('p:' . $gate_phone);

        // handle postback (must re-check gate)
        if (isset($_POST['mon_rsvp_submit']) && isset($_POST['mon_rsvp_nonce']) && wp_verify_nonce($_POST['mon_rsvp_nonce'], 'mon_rsvp')) {
            if (!is_user_logged_in() && !$this->mon_gate_passed($event_id)) {
                return '<div class="mon-rsvp-box">لا يمكنك تأكيد الحضور قبل اجتياز التحقق.</div>';
            }

            $status = sanitize_text_field($_POST['mon_rsvp_status'] ?? '');
            if (!in_array($status, ['attending', 'declined'], true)) $status = 'declined';

            $rsvps = get_post_meta($event_id, self::RSVP_META_KEY, true);
            if (!is_array($rsvps)) $rsvps = [];

            $rsvps[$rsvp_key] = [
                'status'     => $status,
                'updated_at' => current_time('mysql'),
                'type'       => is_user_logged_in() ? 'user' : 'phone',
                'phone'      => is_user_logged_in() ? '' : $gate_phone,
                'user_id'    => is_user_logged_in() ? get_current_user_id() : 0,
            ];

            update_post_meta($event_id, self::RSVP_META_KEY, $rsvps);
        }

        // read current status
        $rsvps = get_post_meta($event_id, self::RSVP_META_KEY, true);
        $mine  = (is_array($rsvps) && isset($rsvps[$rsvp_key])) ? ($rsvps[$rsvp_key]['status'] ?? '') : '';

        ob_start(); ?>
        <div class="mon-rsvp-box" style="padding:14px;border:1px solid #eee;border-radius:12px">
            <h4 style="margin:0 0 10px">تأكيد الحضور</h4>

            <?php if ($mine): ?>
                <p style="margin:0 0 10px">حالتك الحالية:
                    <strong><?php echo $mine === 'attending' ? 'سأحضر' : 'لن أحضر'; ?></strong>
                </p>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('mon_rsvp', 'mon_rsvp_nonce'); ?>
                <label style="display:block;margin:6px 0">
                    <input type="radio" name="mon_rsvp_status" value="attending" <?php checked($mine, 'attending'); ?>>
                    سأحضر
                </label>
                <label style="display:block;margin:6px 0">
                    <input type="radio" name="mon_rsvp_status" value="declined" <?php checked($mine, 'declined'); ?>>
                    لن أحضر
                </label>
                <button type="submit" name="mon_rsvp_submit" value="1" style="margin-top:10px;padding:10px 14px;border-radius:10px">
                    حفظ
                </button>
            </form>
        </div>
    <?php
        return ob_get_clean();
    }

    public function event_comments_open_filter($open, $post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return $open;
        if (!$this->is_event_post($post_id)) return $open;

        if ($this->event_hide_public_comments($post_id)) return false;

        

        return true;
    }




    /* --------------------------------------------------------------------------
     * BuddyPress - My Invites Tab (unchanged from your logic)
     * -------------------------------------------------------------------------- */

    public function bp_add_my_invites_tab()
    {
        if (!function_exists('bp_core_new_nav_item')) return;

        bp_core_new_nav_item([
            'name' => 'دعواتي',
            'slug' => 'my-invites',
            'screen_function' => function () {
                add_action('bp_template_content', [$this, 'bp_render_my_invites_tab']);
                bp_core_load_template('members/single/plugins');
            },
            'position' => 36,
            'default_subnav_slug' => 'my-invites',
        ]);
    }

    public function bp_render_my_invites_tab()
    {
        $user_id = bp_displayed_user_id();
        if (!$user_id) return;

        $events = $this->rsvp->get_events_by_user_rsvp((int)$user_id);

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start">';
        echo $this->render_events_list_card('سأحضر', $events['attending']);
        echo $this->render_events_list_card('لن أحضر', $events['declined']);
        echo '</div>';

        echo '<p style="margin-top:12px;color:#6b7280;font-size:12px">* هذه القوائم تُبنى من ردود RSVP على صفحات المناسبات.</p>';
    }

    private function render_events_list_card($title, $items)
    {
        $html  = '<div style="padding:16px;border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(0,0,0,.06)">';
        $html .= '<h3 style="margin-top:0;margin-bottom:10px">' . esc_html($title) . '</h3>';

        if (!$items) {
            $html .= '<p style="color:#6b7280;margin:0">لا توجد عناصر.</p></div>';
            return $html;
        }

        $html .= '<ul style="margin:0;padding-right:18px">';
        foreach ($items as $row) {
            $ev = $row['post'];
            $date = $row['date'];
            $html .= '<li style="margin:8px 0">';
            $html .= '<a href="' . esc_url(get_permalink($ev->ID)) . '">' . esc_html($ev->post_title) . '</a>';
            if ($date) $html .= ' <span style="color:#6b7280;font-size:12px">— ' . esc_html($date) . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    private function get_events_by_user_rsvp($user_id)
    {
        $attending = [];
        $declined  = [];

        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => 200,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_key'       => self::RSVP_META_KEY,
            'meta_compare'   => 'EXISTS',
        ]);

        foreach ($events as $ev) {
            $rsvps = get_post_meta($ev->ID, self::RSVP_META_KEY, true);
            if (!is_array($rsvps) || !isset($rsvps[$user_id])) continue;

            $status = $rsvps[$user_id]['status'] ?? '';
            $date   = get_post_meta($ev->ID, '_mon_event_date', true);

            if ($status === 'attending') {
                $attending[] = ['post' => $ev, 'date' => $date];
            } elseif ($status === 'declined') {
                $declined[]  = ['post' => $ev, 'date' => $date];
            }
        }

        return [
            'attending' => $attending,
            'declined'  => $declined,
        ];
    }


    /**
     * Check if current visitor passed the invite gate for this event.
     * - Host/Admin bypass
     * - Valid signed cookie AND phone still in invited list
     */
    private function mon_gate_passed($event_id): bool
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

        // Verify signed cookie (uses your existing verifier)
        [$ok, $cid, $phone_norm] = $this->mon_verify_invite_cookie_value($_COOKIE[$cookie_name]);
        if (!$ok || (int)$cid !== $event_id) return false;

        // Ensure phone still invited
        return $this->mon_is_phone_invited_mvp($event_id, $phone_norm);
    }

    /**
     * Get gate phone from cookie (if gate passed).
     */
    private function mon_gate_phone($event_id): string
    {
        $event_id = (int) $event_id;
        $cookie_name = 'mon_inv_' . $event_id;

        if (empty($_COOKIE[$cookie_name])) return '';
        [$ok, $cid, $phone_norm] = $this->mon_verify_invite_cookie_value($_COOKIE[$cookie_name]);

        if (!$ok || (int)$cid !== $event_id) return '';
        return $phone_norm ?: '';
    }
}

new Mon_Events_MVP();
