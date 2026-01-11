<?php
// includes/class-buddypress.php

if (!defined('ABSPATH')) exit;

class Mon_Events_BuddyPress
{
    /** @var Mon_Events_MVP */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function register(): void
    {
        // BuddyPress Tabs (MVP)
        add_action('bp_setup_nav', [$this, 'add_my_events_tab'], 100);
        add_action('bp_setup_nav', [$this, 'add_my_invites_tab'], 101);
    }

    /* --------------------------------------------------------------------------
     * Tab: "مناسباتي"
     * -------------------------------------------------------------------------- */

    public function add_my_events_tab()
    {
        if (!function_exists('bp_core_new_nav_item')) return;

        bp_core_new_nav_item([
            'name' => 'مناسباتي',
            'slug' => 'my-events',
            'screen_function' => function () {
                add_action('bp_template_content', [$this, 'render_my_events_tab']);
                bp_core_load_template('members/single/plugins');
            },
            'position' => 35,
            'default_subnav_slug' => 'my-events',
        ]);
    }

    public function render_my_events_tab()
    {
        $user_id = function_exists('bp_displayed_user_id') ? (int) bp_displayed_user_id() : 0;
        if (!$user_id) return;

        $events = get_posts([
            'post_type'      => 'event',
            'author'         => $user_id,
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
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
     * Tab: "دعواتي"
     * -------------------------------------------------------------------------- */

    public function add_my_invites_tab()
    {
        if (!function_exists('bp_core_new_nav_item')) return;

        bp_core_new_nav_item([
            'name' => 'دعواتي',
            'slug' => 'my-invites',
            'screen_function' => function () {
                add_action('bp_template_content', [$this, 'render_my_invites_tab']);
                bp_core_load_template('members/single/plugins');
            },
            'position' => 36,
            'default_subnav_slug' => 'my-invites',
        ]);
    }

    public function render_my_invites_tab()
    {
        $user_id = function_exists('bp_displayed_user_id') ? (int) bp_displayed_user_id() : 0;
        if (!$user_id) return;

        // ✅ IMPORTANT: use RSVP module (keys are u:ID)
        $events = $this->plugin->rsvp()->get_events_by_user_rsvp($user_id);

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start">';
        echo $this->render_events_list_card('سأحضر', $events['attending'] ?? []);
        echo $this->render_events_list_card('لن أحضر', $events['declined'] ?? []);
        echo '</div>';

        echo '<p style="margin-top:12px;color:#6b7280;font-size:12px">* هذه القوائم تُبنى من ردود RSVP على صفحات المناسبات.</p>';
    }

    private function render_events_list_card(string $title, array $items): string
    {
        $html  = '<div style="padding:16px;border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(0,0,0,.06)">';
        $html .= '<h3 style="margin-top:0;margin-bottom:10px">' . esc_html($title) . '</h3>';

        if (!$items) {
            $html .= '<p style="color:#6b7280;margin:0">لا توجد عناصر.</p></div>';
            return $html;
        }

        $html .= '<ul style="margin:0;padding-right:18px">';
        foreach ($items as $row) {
            $ev   = $row['post'] ?? null;
            $date = $row['date'] ?? '';

            if (!$ev || !isset($ev->ID)) continue;

            $html .= '<li style="margin:8px 0">';
            $html .= '<a href="' . esc_url(get_permalink($ev->ID)) . '">' . esc_html($ev->post_title) . '</a>';
            if ($date) $html .= ' <span style="color:#6b7280;font-size:12px">— ' . esc_html($date) . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }
}
