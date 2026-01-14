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
        add_action('bp_setup_nav', [$this, 'add_my_events_tab'], 100);
        add_action('bp_setup_nav', [$this, 'add_my_invites_tab'], 101);
    }

    /* --------------------------------------------------------------------------
     * UI Helpers
     * -------------------------------------------------------------------------- */

    private function now_ts(): int
    {
        // WordPress timezone-safe
        return current_time('timestamp');
    }

    private function event_ts(int $event_id): int
    {
        $date = (string) get_post_meta($event_id, '_mon_event_date', true);
        $time = (string) get_post_meta($event_id, '_mon_event_time', true);

        if (!$date) return 0;

        // if time missing => end of day
        $when = $date . ($time ? (' ' . $time) : ' 23:59');
        $ts = strtotime($when);
        return $ts ? (int)$ts : 0;
    }

    private function is_today(int $event_id): bool
    {
        $date = (string) get_post_meta($event_id, '_mon_event_date', true);
        if (!$date) return false;

        $today = wp_date('Y-m-d', $this->now_ts());
        return $date === $today;
    }

    private function get_event_type_label(int $event_id): string
    {
        $terms = wp_get_post_terms($event_id, 'event_type', ['fields' => 'names']);
        if (is_wp_error($terms) || empty($terms)) return '';
        return implode(' • ', array_map('sanitize_text_field', $terms));
    }

    private function card_event(WP_Post $ev): string
    {
        $id = (int) $ev->ID;

        $date = (string) get_post_meta($id, '_mon_event_date', true);
        $time = (string) get_post_meta($id, '_mon_event_time', true);
        $location = (string) get_post_meta($id, '_mon_event_location', true);
        $maps = (string) get_post_meta($id, '_mon_event_maps', true);

        $type_label = $this->get_event_type_label($id);

        $date_label = $date ? $date : '';
        if ($date_label && $time) $date_label .= ' • ' . $time;

        $badge = '';
        if ($this->is_today($id)) {
            $badge = '<span class="mon-badge mon-badge-today">اليوم</span>';
        } else {
            $ts = $this->event_ts($id);
            if ($ts && $ts < $this->now_ts()) {
                $badge = '<span class="mon-badge mon-badge-past">انتهت</span>';
            } else {
                $badge = '<span class="mon-badge mon-badge-upcoming">قادمة</span>';
            }
        }

        $maps_btn = '';
        if ($maps) {
            $maps_btn = '<a class="mon-btn mon-btn-soft" target="_blank" rel="noopener" href="' . esc_url($maps) . '">الخريطة</a>';
        }

        $html  = '<div class="mon-card">';
        $html .= '  <div class="mon-card-top">';
        $html .= '    <div class="mon-card-title">';
        $html .= '      <a class="mon-title-link" href="' . esc_url(get_permalink($id)) . '">' . esc_html($ev->post_title) . '</a>';
        $html .= '      <div class="mon-meta-line">';
        $html .=           ($type_label ? '<span class="mon-type">' . esc_html($type_label) . '</span>' : '');
        $html .=           ($type_label && $date_label ? '<span class="mon-dot">•</span>' : '');
        $html .=           ($date_label ? '<span class="mon-date">' . esc_html($date_label) . '</span>' : '');
        $html .= '      </div>';
        $html .= '    </div>';
        $html .= '    <div class="mon-card-badge">' . $badge . '</div>';
        $html .= '  </div>';

        if ($location) {
            $html .= '<div class="mon-sub">' . esc_html($location) . '</div>';
        }

        $html .= '  <div class="mon-actions">';
        $html .= '    <a class="mon-btn" href="' . esc_url(get_permalink($id)) . '">فتح المناسبة</a>';
        $html .=      $maps_btn;
        $html .= '  </div>';
        $html .= '</div>';

        return $html;
    }

    private function styles(): void
    {
        // بسيطة واحترافية بدون تعارض مع الثيم
        echo '<style>
        .mon-wrap{max-width:1100px}
        .mon-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin:0 0 14px}
        .mon-h{margin:0;font-size:18px}
        .mon-muted{margin:2px 0 0;color:#6b7280;font-size:13px}
        .mon-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        @media (max-width: 980px){.mon-grid{grid-template-columns:1fr}}
        .mon-section{background:#fff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden}
        .mon-section-head{padding:12px 14px;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between;align-items:center}
        .mon-section-title{margin:0;font-size:14px;font-weight:700}
        .mon-section-count{font-size:12px;color:#6b7280}
        .mon-list{padding:12px 14px;display:grid;gap:10px}
        .mon-card{border:1px solid #eef2f7;border-radius:14px;padding:12px;background:#fff}
        .mon-card-top{display:flex;justify-content:space-between;gap:10px}
        .mon-title-link{font-weight:800;text-decoration:none}
        .mon-meta-line{margin-top:4px;color:#6b7280;font-size:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .mon-dot{opacity:.6}
        .mon-sub{margin-top:8px;color:#111827;font-size:13px}
        .mon-actions{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}
        .mon-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#111827;color:#fff;text-decoration:none;font-size:13px}
        .mon-btn:hover{opacity:.92}
        .mon-btn-soft{background:#fff;color:#111827}
        .mon-badge{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid #e5e7eb;background:#fff}
        .mon-badge-upcoming{border-color:#c7d2fe;background:#eef2ff;color:#3730a3}
        .mon-badge-today{border-color:#bbf7d0;background:#ecfdf5;color:#065f46}
        .mon-badge-past{border-color:#fee2e2;background:#fef2f2;color:#991b1b}
        .mon-empty{color:#6b7280;font-size:13px;margin:0}
        </style>';
    }

    /* --------------------------------------------------------------------------
     * Tab: "مناسباتي" (Host)
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

        $this->styles();

        $events = get_posts([
            'post_type'      => 'event',
            'author'         => $user_id,
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $today = [];
        $upcoming = [];
        $past = [];

        foreach ($events as $ev) {
            $ts = $this->event_ts((int)$ev->ID);
            if ($this->is_today((int)$ev->ID)) {
                $today[] = $ev;
            } elseif ($ts && $ts < $this->now_ts()) {
                $past[] = $ev;
            } else {
                $upcoming[] = $ev;
            }
        }

        echo '<div class="mon-wrap">';
        echo '  <div class="mon-header">';
        echo '    <div>';
        echo '      <h3 class="mon-h">مناسباتي</h3>';
        echo '      <p class="mon-muted">القادمة • اليوم • السابقة</p>';
        echo '    </div>';
        echo '  </div>';

        if (!$events) {
            echo '<p class="mon-empty">لا توجد مناسبات بعد.</p></div>';
            return;
        }

        echo '<div class="mon-grid">';
        echo $this->render_events_section('قادمة', $upcoming);
        echo $this->render_events_section('اليوم', $today);
        echo $this->render_events_section('سابقة', $past);
        echo '</div>';

        echo '</div>';
    }

    private function render_events_section(string $title, array $items): string
    {
        $html  = '<div class="mon-section">';
        $html .= '  <div class="mon-section-head">';
        $html .= '    <h4 class="mon-section-title">' . esc_html($title) . '</h4>';
        $html .= '    <div class="mon-section-count">(' . (int)count($items) . ')</div>';
        $html .= '  </div>';
        $html .= '  <div class="mon-list">';

        if (!$items) {
            $html .= '<p class="mon-empty">لا توجد عناصر.</p>';
        } else {
            foreach ($items as $ev) {
                $html .= $this->card_event($ev);
            }
        }

        $html .= '  </div>';
        $html .= '</div>';

        return $html;
    }

    /* --------------------------------------------------------------------------
     * Tab: "دعواتي" (Guest via RSVP)
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

        $this->styles();

        $data = $this->plugin->rsvp()->get_events_by_user_rsvp($user_id);

        $attending = $data['attending'] ?? [];
        $declined  = $data['declined'] ?? [];

        // Convert to WP_Post arrays
        $att_posts = array_values(array_filter(array_map(fn($r) => $r['post'] ?? null, $attending)));
        $dec_posts = array_values(array_filter(array_map(fn($r) => $r['post'] ?? null, $declined)));

        // Optional: sort by event datetime
        usort($att_posts, fn($a, $b) => $this->event_ts((int)$a->ID) <=> $this->event_ts((int)$b->ID));
        usort($dec_posts, fn($a, $b) => $this->event_ts((int)$a->ID) <=> $this->event_ts((int)$b->ID));

        echo '<div class="mon-wrap">';
        echo '  <div class="mon-header">';
        echo '    <div>';
        echo '      <h3 class="mon-h">دعواتي</h3>';
        echo '      <p class="mon-muted">هذه القوائم تُبنى من ردود RSVP على صفحات المناسبات.</p>';
        echo '    </div>';
        echo '  </div>';

        echo '<div class="mon-grid">';
        echo $this->render_events_section('سأحضر', $att_posts);
        echo $this->render_events_section('لن أحضر', $dec_posts);
        // عمود ثالث فاضي لتوازن الجريد في الديسكتوب
        echo '<div></div>';
        echo '</div>';

        echo '</div>';
    }
}
