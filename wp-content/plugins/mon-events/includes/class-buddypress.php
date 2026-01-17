<?php
// wp-content/plugins/mon-events/includes/class-buddypress.php

if (!defined('ABSPATH')) exit;

/**
 * Mon_Events_BuddyPress
 * ------------------------------------------------------------
 * - BuddyPress Tabs: (مناسباتي / دعواتي / إضافة-تعديل مناسبة)
 * - Frontend Form: create/update event + featured + gallery + invites
 * - Assets loaded only inside "event-form" tab
 */
class Mon_Events_BuddyPress
{
    /** @var Mon_Events_MVP */
    private $plugin;

    private string $form_slug = 'event-form';
    private int $max_events = 200;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function register(): void
    {
        add_action('bp_setup_nav', [$this, 'add_my_events_tab'], 100);
        add_action('bp_setup_nav', [$this, 'add_my_invites_tab'], 101);
        add_action('bp_setup_nav', [$this, 'add_event_form_tab'], 102);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_event_form_assets'], 20);
        add_action('init', [$this, 'handle_event_form_submit']);
    }

    /* --------------------------------------------------------------------------
     * Context helpers (BuddyPress)
     * -------------------------------------------------------------------------- */

    private function is_own_profile(): bool
    {
        if (!function_exists('bp_displayed_user_id')) return false;

        $displayed = (int) bp_displayed_user_id();
        return is_user_logged_in() && $displayed > 0 && $displayed === (int) get_current_user_id();
    }

    private function can_edit_event(int $event_id): bool
    {
        if (!is_user_logged_in() || $event_id <= 0) return false;
        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'event') return false;
        return (int) $event->post_author === (int) get_current_user_id();
    }

    private function is_form_tab(): bool
    {
        if (!function_exists('bp_is_user') || !bp_is_user()) return false;
        if (!function_exists('bp_current_action')) return false;
        return (string) bp_current_action() === $this->form_slug;
    }

    /* --------------------------------------------------------------------------
     * Time helpers (timezone-safe)
     * -------------------------------------------------------------------------- */

    private function now_ts(): int
    {
        return (int) current_time('timestamp');
    }

    private function event_ts(int $event_id): int
    {
        $date = (string) get_post_meta($event_id, '_mon_event_date', true);
        $time = (string) get_post_meta($event_id, '_mon_event_time', true);
        if (!$date) return 0;

        $when = $date . ($time ? (' ' . $time) : ' 23:59');
        $ts = strtotime($when);

        return $ts ? (int) $ts : 0;
    }

    private function is_today(int $event_id): bool
    {
        $date = (string) get_post_meta($event_id, '_mon_event_date', true);
        if (!$date) return false;
        return $date === wp_date('Y-m-d', $this->now_ts());
    }

    /* --------------------------------------------------------------------------
     * Taxonomy helpers
     * -------------------------------------------------------------------------- */

    private function get_event_type_label(int $event_id): string
    {
        $terms = wp_get_post_terms($event_id, 'event_type', ['fields' => 'names']);
        if (is_wp_error($terms) || empty($terms)) return '';
        return implode(' • ', array_map('sanitize_text_field', $terms));
    }

    private function get_event_type_selected_id(int $event_id): int
    {
        $ids = wp_get_post_terms($event_id, 'event_type', ['fields' => 'ids']);
        if (is_wp_error($ids) || empty($ids)) return 0;
        return (int) $ids[0];
    }

    /* --------------------------------------------------------------------------
     * Gallery helpers
     * -------------------------------------------------------------------------- */

    private function sanitize_gallery_ids($raw): array
    {
        if (is_array($raw)) {
            $ids = $raw;
        } else {
            $raw = (string) $raw;
            $ids = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($v) => $v > 0);
        $ids = array_values(array_unique($ids));

        return $ids;
    }

    /* --------------------------------------------------------------------------
     * Invites helpers
     * -------------------------------------------------------------------------- */

    private function normalize_phone($raw, $default_cc = '966'): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if (!$digits) return '';

        // 00 => international
        if (substr($digits, 0, 2) === '00') {
            $digits = substr($digits, 2);
        }

        // KSA local 05xxxxxxxx -> 9665xxxxxxxx
        if (strlen($digits) === 10 && substr($digits, 0, 1) === '0') {
            $digits = $default_cc . substr($digits, 1);
        }

        // KSA short 5xxxxxxxx -> 9665xxxxxxxx
        if (strlen($digits) === 9 && substr($digits, 0, 1) === '5') {
            $digits = $default_cc . $digits;
        }

        return $digits;
    }

    /**
     * manual input: numbers separated by new lines or commas
     * returns: [phone => ['name'=>''], ...]
     */
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

    /**
     * CSV paste: phone OR phone,name
     * returns: [phone => ['name'=>'Ahmad'], ...]
     */
    private function parse_invites_from_csv($csv_text): array
    {
        $out = [];
        $lines = preg_split("/\r\n|\n|\r/", (string) $csv_text);

        $row_index = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $cols = str_getcsv($line);
            if (empty($cols)) continue;

            $col0 = strtolower(trim((string)($cols[0] ?? '')));
            $col1 = strtolower(trim((string)($cols[1] ?? '')));

            // Skip header
            if ($row_index === 0) {
                if ($col0 === 'phone' || $col0 === 'mobile' || $col0 === 'number') {
                    $row_index++;
                    continue;
                }
                if ($col0 === 'phone' && $col1 === 'name') {
                    $row_index++;
                    continue;
                }
            }

            $phone_raw = (string)($cols[0] ?? '');
            $name_raw  = (string)($cols[1] ?? '');

            $phone = $this->normalize_phone($phone_raw);
            if (!$phone) {
                $row_index++;
                continue;
            }

            $out[$phone] = ['name' => sanitize_text_field($name_raw)];
            $row_index++;
        }

        return $out;
    }

    private function read_csv_file_content($tmp_path): string
    {
        $content = @file_get_contents($tmp_path);
        if (!is_string($content) || $content === '') return '';

        // Remove UTF-8 BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Limit 1MB
        if (strlen($content) > 1024 * 1024) {
            $content = substr($content, 0, 1024 * 1024);
        }

        return trim($content);
    }

    /**
     * Merge invites: prefer name if provided.
     * input: [phone=>['name'=>...], ...]
     */
    private function merge_invites(array $a, array $b): array
    {
        $out = $a;

        foreach ($b as $phone => $row) {
            $phone = (string) $phone;
            if ($phone === '') continue;

            $name = '';
            if (is_array($row) && isset($row['name'])) {
                $name = sanitize_text_field((string) $row['name']);
            }

            if (!isset($out[$phone])) {
                $out[$phone] = ['name' => $name];
            } else {
                if ($name && empty($out[$phone]['name'])) {
                    $out[$phone]['name'] = $name;
                }
            }
        }

        // Sanitize final
        foreach ($out as $phone => $row) {
            $out[$phone] = ['name' => sanitize_text_field($row['name'] ?? '')];
        }

        ksort($out);
        return $out;
    }

    /**
     * JSON manager input => returns map: [phone => ['name'=>...], ...]
     * expected JSON: [ {phone:"966..", name:".."}, ... ]
     */
    private function sanitize_invites_json($raw): array
    {
        $raw = (string) $raw;
        if ($raw === '') return [];

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) continue;

            $phone = $this->normalize_phone($item['phone'] ?? '');
            if (!$phone) continue;

            $name = sanitize_text_field((string)($item['name'] ?? ''));
            $out[$phone] = ['name' => $name];
        }

        ksort($out);
        return $out;
    }

    /**
     * Converts map to rows for JS: [ ['phone'=>..,'name'=>..], ... ]
     */
    private function invites_map_to_rows(array $map): array
    {
        $rows = [];
        foreach ($map as $phone => $row) {
            $rows[] = [
                'phone' => (string) $phone,
                'name'  => sanitize_text_field((string)($row['name'] ?? '')),
            ];
        }
        return $rows;
    }

    /* --------------------------------------------------------------------------
     * Shared UI styles
     * -------------------------------------------------------------------------- */

    private function styles(): void
    {
        echo '<style>
        .mon-wrap{max-width:1100px}
        .mon-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin:0 0 14px}
        .mon-h{margin:0;font-size:18px;font-weight:900;color:#0f172a}
        .mon-muted{margin:4px 0 0;color:#64748b;font-size:13px;line-height:1.6}

        .mon-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        @media (max-width: 980px){.mon-grid{grid-template-columns:1fr}}

        .mon-section{
          background:#ffffff;
          border:1px solid #e5e7eb;
          border-radius:18px;
          overflow:hidden;
          box-shadow:0 10px 30px rgba(15,23,42,.06);
        }
        .mon-section-head{
          padding:12px 14px;
          border-bottom:1px solid #eef2f7;
          display:flex;justify-content:space-between;align-items:center
        }
        .mon-section-title{margin:0;font-size:14px;font-weight:900;color:#0f172a}
        .mon-section-count{font-size:12px;color:#64748b}

        .mon-list{padding:12px 14px;display:grid;gap:10px}

        .mon-card{
          border:1px solid #eef2f7;
          border-radius:16px;
          padding:12px;
          background:#fff;
          box-shadow:0 6px 16px rgba(2,6,23,.04);
        }

        .mon-card-top{display:flex;justify-content:space-between;gap:10px}
        .mon-title-link{font-weight:900;text-decoration:none;color:#0f172a}
        .mon-title-link:hover{text-decoration:underline}

        .mon-meta-line{margin-top:6px;color:#64748b;font-size:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .mon-dot{opacity:.6}
        .mon-sub{margin-top:8px;color:#0f172a;font-size:13px}

        .mon-actions{margin-top:10px;display:flex;gap:8px;flex-wrap:wrap}

        .mon-btn{
          display:inline-flex;align-items:center;justify-content:center;
          padding:9px 12px;border-radius:12px;
          border:1px solid #e5e7eb;background:#ffffff;color:#0f172a;
          text-decoration:none;font-size:13px;font-weight:900;
          box-shadow:0 6px 14px rgba(2,6,23,.05);
          transition:.15s;
        }
        .mon-btn:hover{transform:translateY(-1px);box-shadow:0 10px 20px rgba(2,6,23,.08)}
        .mon-btn-primary{background:#240B36;color:#fff;border-color:#240B36}
        .mon-btn-primary:hover{opacity:.95}

        .mon-input{
          width:100%;
          padding:11px 12px;
          border-radius:14px;
          border:1px solid #e5e7eb;
          background:#fff;
          color:#0f172a;
          outline:none;
        }
        .mon-input:focus{
          border-color:#c7b3d6;
          box-shadow:0 0 0 4px rgba(36,11,54,.08);
        }

        .mon-badge{
          display:inline-flex;align-items:center;
          padding:6px 10px;border-radius:999px;
          font-size:12px;font-weight:900;border:1px solid #e5e7eb;background:#fff;color:#0f172a
        }
        .mon-badge-upcoming{border-color:#dbeafe;background:#eff6ff;color:#1d4ed8}
        .mon-badge-today{border-color:#bbf7d0;background:#ecfdf5;color:#166534}
        .mon-badge-past{border-color:#fee2e2;background:#fef2f2;color:#991b1b}

        .mon-empty{color:#64748b;font-size:13px;margin:0}

        /* Form */
        .mon-form{display:grid;gap:12px}
        .mon-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        @media(max-width:900px){.mon-two{grid-template-columns:1fr}}
        .mon-help{margin:6px 0 0;color:#64748b;font-size:12px}

        /* Gallery */
        .mon-gal-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .mon-gal-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}
        @media(max-width:900px){.mon-gal-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
        .mon-g-item{position:relative;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#f8fafc;cursor:grab}
        .mon-g-item img{width:100%;height:92px;object-fit:cover;display:block}
        .mon-g-remove{
          position:absolute;top:6px;left:6px;border:0;background:rgba(15,23,42,.78);color:#fff;
          border-radius:10px;padding:6px 8px;cursor:pointer
        }

        /* Invites list row */
        .mon-inv-row{
          border:1px solid #eef2f7;border-radius:14px;padding:10px 12px;
          display:flex;align-items:center;justify-content:space-between;gap:10px;
          background:#fff;
        }
        .mon-inv-phone{font-weight:900;color:#0f172a;direction:ltr;font-family:monospace}
        .mon-inv-name{color:#64748b;font-size:13px;margin-top:2px}
        </style>';
    }

    /* --------------------------------------------------------------------------
     * Cards (My events / My invites)
     * -------------------------------------------------------------------------- */

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

        if ($this->is_today($id)) {
            $badge = '<span class="mon-badge mon-badge-today">اليوم</span>';
        } else {
            $ts = $this->event_ts($id);
            $badge = ($ts && $ts < $this->now_ts())
                ? '<span class="mon-badge mon-badge-past">انتهت</span>'
                : '<span class="mon-badge mon-badge-upcoming">قادمة</span>';
        }

        $maps_btn = $maps ? '<a class="mon-btn" target="_blank" rel="noopener" href="' . esc_url($maps) . '">الخريطة</a>' : '';

        $edit_btn = '';
        if ($this->is_own_profile() && $this->can_edit_event($id)) {
            $edit_url = trailingslashit(bp_displayed_user_domain() . $this->form_slug) . '?event_id=' . $id;
            $edit_btn = '<a class="mon-btn" href="' . esc_url($edit_url) . '">تعديل</a>';
        }

        $html  = '<div class="mon-card">';
        $html .= '  <div class="mon-card-top">';
        $html .= '    <div class="mon-card-title">';
        $html .= '      <a class="mon-title-link" href="' . esc_url(get_permalink($id)) . '">' . esc_html($ev->post_title ?: '(بدون عنوان)') . '</a>';
        $html .= '      <div class="mon-meta-line">';
        $html .=           ($type_label ? '<span>' . esc_html($type_label) . '</span>' : '');
        $html .=           ($type_label && $date_label ? '<span class="mon-dot">•</span>' : '');
        $html .=           ($date_label ? '<span>' . esc_html($date_label) . '</span>' : '');
        $html .= '      </div>';
        $html .= '    </div>';
        $html .= '    <div class="mon-card-badge">' . $badge . '</div>';
        $html .= '  </div>';

        if ($location) {
            $html .= '<div class="mon-sub">' . esc_html($location) . '</div>';
        }

        $html .= '  <div class="mon-actions">';
        $html .= '    <a class="mon-btn mon-btn-primary" href="' . esc_url(get_permalink($id)) . '">فتح المناسبة</a>';
        $html .=      $maps_btn;
        $html .=      $edit_btn;
        $html .= '  </div>';
        $html .= '</div>';

        return $html;
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
            foreach ($items as $ev) $html .= $this->card_event($ev);
        }

        $html .= '  </div>';
        $html .= '</div>';

        return $html;
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

        $this->styles();

        $events = get_posts([
            'post_type'      => 'event',
            'author'         => $user_id,
            'posts_per_page' => $this->max_events,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $today = [];
        $upcoming = [];
        $past = [];

        foreach ($events as $ev) {
            $ts = $this->event_ts((int)$ev->ID);
            if ($this->is_today((int)$ev->ID)) $today[] = $ev;
            elseif ($ts && $ts < $this->now_ts()) $past[] = $ev;
            else $upcoming[] = $ev;
        }

        echo '<div class="mon-wrap">';
        echo '  <div class="mon-header">';
        echo '    <div>';
        echo '      <h3 class="mon-h">مناسباتي</h3>';
        echo '      <p class="mon-muted">القادمة • اليوم • السابقة</p>';
        echo '    </div>';

        if ($this->is_own_profile()) {
            $add_url = trailingslashit(bp_displayed_user_domain() . $this->form_slug);
            echo '    <a class="mon-btn mon-btn-primary" href="' . esc_url($add_url) . '">إضافة مناسبة</a>';
        }

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

        $this->styles();

        $data = $this->plugin->rsvp()->get_events_by_user_rsvp($user_id);

        $attending = $data['attending'] ?? [];
        $declined  = $data['declined'] ?? [];

        $att_posts = array_values(array_filter(array_map(fn($r) => $r['post'] ?? null, $attending)));
        $dec_posts = array_values(array_filter(array_map(fn($r) => $r['post'] ?? null, $declined)));

        usort($att_posts, fn($a, $b) => $this->event_ts((int)$a->ID) <=> $this->event_ts((int)$b->ID));
        usort($dec_posts, fn($a, $b) => $this->event_ts((int)$a->ID) <=> $this->event_ts((int)$b->ID));

        echo '<div class="mon-wrap">';
        echo '  <div class="mon-header">';
        echo '    <div>';
        echo '      <h3 class="mon-h">دعواتي</h3>';
        echo '      <p class="mon-muted">القائمة هنا تُبنى من ردود RSVP داخل صفحات المناسبات.</p>';
        echo '    </div>';
        echo '  </div>';

        echo '<div class="mon-grid">';
        echo $this->render_events_section('سأحضر', $att_posts);
        echo $this->render_events_section('لن أحضر', $dec_posts);
        echo '<div></div>';
        echo '</div>';

        echo '</div>';
    }

    /* --------------------------------------------------------------------------
     * Tab: "إضافة/تعديل مناسبة" (owner only)
     * -------------------------------------------------------------------------- */

    public function add_event_form_tab(): void
    {
        if (!function_exists('bp_core_new_nav_item')) return;
        if (!$this->is_own_profile()) return;

        bp_core_new_nav_item([
            'name' => 'إضافة/تعديل مناسبة',
            'slug' => $this->form_slug,
            'default_subnav_slug' => $this->form_slug,
            'position' => 40,
            'screen_function' => function () {
                add_action('bp_template_content', [$this, 'render_event_form']);
                bp_core_load_template('members/single/plugins');
            },
        ]);
    }

    public function render_event_form(): void
    {
        if (!$this->is_own_profile()) {
            echo '<div class="mon-section"><div class="mon-list"><p class="mon-empty">غير مسموح.</p></div></div>';
            return;
        }

        $this->styles();

        // Detect edit mode
        $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        $event    = $event_id ? get_post($event_id) : null;

        if ($event && $event->post_type !== 'event') {
            $event = null;
            $event_id = 0;
        }

        if ($event && !$this->can_edit_event((int) $event->ID)) {
            echo '<div class="mon-section"><div class="mon-list"><p class="mon-empty">هذه المناسبة ليست لك.</p></div></div>';
            return;
        }

        // Prefill
        $title    = $event ? (string) $event->post_title : '';
        $content  = $event ? (string) $event->post_content : '';

        $date     = $event ? (string) get_post_meta($event->ID, '_mon_event_date', true) : '';
        $time     = $event ? (string) get_post_meta($event->ID, '_mon_event_time', true) : '';
        $location = $event ? (string) get_post_meta($event->ID, '_mon_event_location', true) : '';
        $maps     = $event ? (string) get_post_meta($event->ID, '_mon_event_maps', true) : '';

        $hide_gallery         = $event ? (int) get_post_meta($event->ID, '_mon_hide_gallery', true) : 0;
        $hide_visitors        = $event ? (int) get_post_meta($event->ID, '_mon_hide_visitors', true) : 0;
        $hide_public_comments = $event ? (int) get_post_meta($event->ID, '_mon_hide_public_comments', true) : 0;
        $close_comments_after = $event ? (int) get_post_meta($event->ID, '_mon_close_comments_after', true) : 0;

        // Featured
        $featured_id  = $event ? (int) get_post_thumbnail_id((int)$event->ID) : 0;
        $featured_url = $featured_id ? (string) wp_get_attachment_image_url($featured_id, 'medium') : '';

        // Event type
        $selected_term_id = $event ? (int) $this->get_event_type_selected_id((int)$event->ID) : 0;
        $terms = get_terms(['taxonomy' => 'event_type', 'hide_empty' => false]);
        if (is_wp_error($terms)) $terms = [];

        // Gallery
        $gallery_ids = $event ? (string) get_post_meta($event->ID, '_mon_gallery_ids', true) : '';
        $gallery_ids = $this->sanitize_gallery_ids($gallery_ids);

        // Invites: read saved map
        $saved_invites = $event ? (array) get_post_meta($event->ID, '_mon_invites', true) : [];
        if (!is_array($saved_invites)) $saved_invites = [];

        $invite_rows_json = wp_json_encode($this->invites_map_to_rows($saved_invites), JSON_UNESCAPED_UNICODE);

        $notice = isset($_GET['saved']) ? 'تم حفظ المناسبة بنجاح ✅' : '';

        echo '<div class="mon-wrap">';
        echo '  <div class="mon-header">';
        echo '    <div>';
        echo '      <h3 class="mon-h">' . esc_html($event ? 'تعديل المناسبة' : 'إضافة مناسبة جديدة') . '</h3>';
        echo '      <p class="mon-muted">إدارة مناسباتك من الواجهة الأمامية (فقط أنت).</p>';
        echo '    </div>';
        if ($event) {
            echo '    <a class="mon-btn" href="' . esc_url(get_permalink((int)$event->ID)) . '">عرض المناسبة</a>';
        }
        echo '  </div>';

        if ($notice) {
            echo '<div class="mon-section"><div class="mon-list"><p class="mon-empty" style="color:#166534">' . esc_html($notice) . '</p></div></div>';
        }

        echo '<div class="mon-section">';
        echo '  <div class="mon-section-head">';
        echo '    <h4 class="mon-section-title">بيانات المناسبة</h4>';
        echo '    <div class="mon-section-count">' . ($event ? '#' . (int)$event->ID : '') . '</div>';
        echo '  </div>';

        echo '  <div class="mon-list">';
        echo '  <form method="post" enctype="multipart/form-data" class="mon-form" data-mon-event-form>';

        wp_nonce_field('mon_event_form_save', 'mon_event_form_nonce');

        if ($event) echo '<input type="hidden" name="event_id" value="' . (int)$event->ID . '">';

        // Title
        echo '<div>';
        echo '  <label style="display:block;font-weight:900;margin:0 0 6px">عنوان المناسبة *</label>';
        echo '  <input class="mon-input" type="text" name="event_title" value="' . esc_attr($title) . '" required>';
        echo '</div>';

        // Content
        echo '<div>';
        echo '  <label style="display:block;font-weight:900;margin:0 0 6px">تفاصيل المناسبة</label>';
        echo '  <textarea class="mon-input" name="event_content" rows="5" style="line-height:1.8">' . esc_textarea($content) . '</textarea>';
        echo '</div>';

        // Featured image
        echo '<div>';
        echo '  <label style="display:block;font-weight:900;margin:0 0 6px">الصورة المميزة للمناسبة</label>';
        echo '  <div style="border:1px solid #eef2f7;border-radius:16px;padding:12px;background:#fff;display:grid;gap:10px">';
        echo '    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
        echo '      <button type="button" class="mon-btn mon-btn-primary" data-featured-pick>اختيار / تغيير الصورة</button>';
        echo '      <button type="button" class="mon-btn" data-featured-remove>حذف الصورة</button>';
        echo '      <span class="mon-help">ستظهر هذه الصورة كبطاقة/غلاف للمناسبة.</span>';
        echo '    </div>';
        echo '    <input type="hidden" name="mon_featured_id" data-featured-id value="' . (int)$featured_id . '">';
        echo '    <div data-featured-preview style="width:100%;max-width:420px">';
        if ($featured_url) {
            echo '<img src="' . esc_url($featured_url) . '" alt="" style="width:100%;height:auto;border-radius:14px;border:1px solid #e5e7eb;display:block">';
        } else {
            echo '<div style="padding:14px;border-radius:14px;background:#f8fafc;border:1px dashed #cbd5e1;color:#64748b;font-size:13px">لا توجد صورة بعد.</div>';
        }
        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        // Date/Time
        echo '<div class="mon-two">';
        echo '  <div><label style="display:block;font-weight:900;margin:0 0 6px">تاريخ المناسبة</label><input class="mon-input" type="date" name="event_date" value="' . esc_attr($date) . '"></div>';
        echo '  <div><label style="display:block;font-weight:900;margin:0 0 6px">وقت المناسبة</label><input class="mon-input" type="time" name="event_time" value="' . esc_attr($time) . '"></div>';
        echo '</div>';

        // Event type
        echo '<div>';
        echo '  <label style="display:block;font-weight:900;margin:0 0 6px">نوع المناسبة</label>';
        echo '  <select class="mon-input" name="event_type_term">';
        echo '    <option value="0">— اختر النوع —</option>';
        foreach ($terms as $t) {
            $tid = (int) $t->term_id;
            echo '<option value="' . $tid . '" ' . selected($selected_term_id, $tid, false) . '>' . esc_html($t->name) . '</option>';
        }
        echo '  </select>';
        echo '  <p class="mon-help">يمكنك إدارة الأنواع لاحقًا.</p>';
        echo '</div>';

        // Location
        echo '<div>';
        echo '  <label style="display:block;font-weight:900;margin:0 0 6px">الموقع (نص)</label>';
        echo '  <input class="mon-input" type="text" name="event_location" value="' . esc_attr($location) . '">';
        echo '</div>';

        // Maps
        echo '<div>';
        echo '  <label style="display:block;font-weight:900;margin:0 0 6px">رابط خرائط Google (اختياري)</label>';
        echo '  <input class="mon-input" type="url" name="event_maps" value="' . esc_attr($maps) . '" placeholder="https://maps.google.com/...">';
        echo '</div>';

        // Toggles
        echo '<div style="display:grid;gap:8px;padding:12px;border:1px solid #eef2f7;border-radius:14px;background:#fff">';
        echo '  <label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="mon_hide_visitors" value="1" ' . checked($hide_visitors, 1, false) . '><span><b>إخفاء عدد الزوار</b><div class="mon-help">لن يظهر عداد الزيارات داخل صفحة المناسبة.</div></span></label>';
        echo '  <label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="mon_hide_gallery" value="1" ' . checked($hide_gallery, 1, false) . '><span><b>إخفاء ألبوم الصور</b><div class="mon-help">الألبوم يبقى محفوظ ولكن مخفي للزوار.</div></span></label>';
        echo '  <label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="mon_hide_public_comments" value="1" ' . checked($hide_public_comments, 1, false) . '><span><b>إخفاء التعليقات العامة</b><div class="mon-help">إيقاف إظهار التعليقات للجميع.</div></span></label>';
        echo '  <label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="mon_close_comments_after" value="1" ' . checked($close_comments_after, 1, false) . '><span><b>إغلاق التعليقات بعد تاريخ المناسبة</b><div class="mon-help">تُغلق تلقائيًا بعد انتهاء المناسبة.</div></span></label>';
        echo '</div>';

        // Gallery
        echo '<div>';
        echo '  <label style="display:block;font-weight:900;margin:0 0 6px">ألبوم الصور</label>';
        echo '  <div style="border:1px solid #eef2f7;border-radius:16px;padding:12px;background:#fff;display:grid;gap:10px">';
        echo '    <div class="mon-gal-bar">';
        echo '      <button type="button" class="mon-btn mon-btn-primary" data-gal-add>إضافة / اختيار صور</button>';
        echo '      <button type="button" class="mon-btn" data-gal-clear>حذف الكل</button>';
        echo '      <span class="mon-help">اسحب الصور لترتيبها.</span>';
        echo '    </div>';
        echo '    <input type="hidden" name="mon_gallery_ids" data-gal-ids value="' . esc_attr(implode(',', $gallery_ids)) . '">';
        echo '    <div class="mon-gal-grid" data-gal-grid>';
        foreach ($gallery_ids as $aid) {
            $thumb = wp_get_attachment_image_url($aid, 'thumbnail');
            if (!$thumb) continue;
            echo '<div class="mon-g-item" draggable="true" data-id="' . (int)$aid . '"><img src="' . esc_url($thumb) . '" alt=""><button type="button" class="mon-g-remove" data-gal-remove title="حذف">×</button></div>';
        }
        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        // Invites manager (NEW)
        echo '<div>';
        echo '  <label style="display:block;font-weight:900;margin:0 0 6px">قائمة المدعوين</label>';
        echo '  <div style="display:grid;gap:12px;padding:12px;border:1px solid #eef2f7;border-radius:16px;background:#fff">';

        echo '    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between">';
        echo '      <input class="mon-input" type="text" placeholder="بحث..." data-inv-search style="max-width:260px">';
        echo '      <div style="display:flex;gap:10px;flex-wrap:wrap">';
        echo '        <button type="button" class="mon-btn" data-inv-export>تصدير CSV</button>';
        echo '        <button type="button" class="mon-btn" data-inv-clear>حذف الكل</button>';
        echo '      </div>';
        echo '    </div>';

        echo '    <div class="mon-two">';
        echo '      <div><label style="display:block;font-weight:900;margin:0 0 6px">رقم الجوال</label><input class="mon-input" type="text" placeholder="05xxxxxxxx أو 9665xxxxxxx" data-inv-phone style="direction:ltr;font-family:monospace"></div>';
        echo '      <div><label style="display:block;font-weight:900;margin:0 0 6px">الاسم (اختياري)</label><input class="mon-input" type="text" placeholder="أحمد" data-inv-name></div>';
        echo '    </div>';

        echo '    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
        echo '      <button type="button" class="mon-btn mon-btn-primary" data-inv-add>إضافة مدعو</button>';
        echo '      <span class="mon-help">مدير المدعوين هو المعتمد عند الحفظ.</span>';
        echo '    </div>';

        // Source of truth
        echo '    <input type="hidden" name="mon_invites_json" data-inv-json value="' . esc_attr($invite_rows_json) . '">';

        echo '    <div class="mon-help">إجمالي المدعوين: <b data-inv-count>0</b></div>';
        echo '    <div data-inv-list style="display:grid;gap:10px"></div>';

        echo '    <hr style="border:none;border-top:1px solid #eef2f7;margin:6px 0">';

        // Bulk Import (keep)
        echo '    <div style="display:grid;gap:12px">';
        echo '      <div style="display:grid;gap:8px">';
        echo '        <div style="font-weight:900;color:#0f172a">رفع ملف CSV من Excel (مستحسن)</div>';
        echo '        <div class="mon-help">Save As → CSV UTF-8. الصيغ: <b>phone</b> أو <b>phone,name</b></div>';
        echo '        <input class="mon-input" type="file" name="mon_invited_file" accept=".csv,text/csv">';
        echo '      </div>';

        echo '      <div style="display:grid;gap:8px">';
        echo '        <div style="font-weight:900;color:#0f172a">إدخال يدوي (رقم في كل سطر)</div>';
        echo '        <textarea class="mon-input" name="mon_invited_phones" rows="5" style="direction:ltr;font-family:monospace" placeholder="05xxxxxxxx&#10;9665xxxxxxxx"></textarea>';
        echo '      </div>';

        echo '      <div style="display:grid;gap:8px">';
        echo '        <div style="font-weight:900;color:#0f172a">استيراد CSV (لصق محتوى الملف)</div>';
        echo '        <div class="mon-help">الصيغ: عمود واحد <b>phone</b> أو عمودان <b>phone,name</b></div>';
        echo '        <textarea class="mon-input" name="mon_invited_csv" rows="4" style="direction:ltr;font-family:monospace" placeholder="9665xxxxxxx,Ahmed&#10;05yyyyyyyy,Ali"></textarea>';
        echo '      </div>';

        echo '      <div style="display:flex;gap:10px;flex-wrap:wrap">';
        echo '        <button type="button" class="mon-btn" data-inv-import>استيراد ودمج (من الحقول أعلاه)</button>';
        echo '        <span class="mon-help">سيتم دمج المستورد مع القائمة الحالية (بدون تكرار).</span>';
        echo '      </div>';
        echo '    </div>';

        echo '  </div>';
        echo '</div>';

        // Actions
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
        echo '  <button class="mon-btn mon-btn-primary" type="submit" name="mon_event_form_submit" value="1">' . esc_html($event ? 'تحديث المناسبة' : 'إضافة المناسبة') . '</button>';
        if ($event) echo '  <a class="mon-btn" href="' . esc_url(get_permalink((int)$event->ID)) . '">عرض المناسبة</a>';
        echo '</div>';

        echo '</form>';
        echo '  </div>';
        echo '</div>';
        echo '</div>';
    }

    /* --------------------------------------------------------------------------
     * Assets: only for event-form tab
     * -------------------------------------------------------------------------- */

    public function enqueue_event_form_assets(): void
    {
        if (!$this->is_form_tab()) return;

        wp_enqueue_media();

        $js = <<<JS
(function(){
  const wrap = document.querySelector('[data-mon-event-form]');
  if(!wrap || typeof wp === 'undefined' || !wp.media) return;

  // ============================================================
  // Featured Image
  // ============================================================
  const btnFPick   = wrap.querySelector('[data-featured-pick]');
  const btnFRemove = wrap.querySelector('[data-featured-remove]');
  const inputFId   = wrap.querySelector('[data-featured-id]');
  const previewF   = wrap.querySelector('[data-featured-preview]');

  function renderFeatured(url){
    if(!previewF) return;
    previewF.innerHTML = url
      ? '<img src="'+url+'" alt="" style="width:100%;max-width:420px;height:auto;border-radius:14px;border:1px solid #e5e7eb;display:block">'
      : '<div style="padding:14px;border-radius:14px;background:#f8fafc;border:1px dashed #cbd5e1;color:#64748b;font-size:13px">لا توجد صورة بعد.</div>';
  }

  if(btnFPick && inputFId){
    btnFPick.addEventListener('click', function(){
      const frame = wp.media({ title:'اختيار الصورة المميزة', button:{text:'اعتماد الصورة'}, multiple:false });
      frame.on('select', function(){
        const att = frame.state().get('selection').first();
        if(!att) return;
        const json = att.toJSON();
        if(!json || !json.id) return;

        inputFId.value = json.id;

        const url =
          (json.sizes && json.sizes.medium && json.sizes.medium.url) ? json.sizes.medium.url :
          (json.sizes && json.sizes.thumbnail && json.sizes.thumbnail.url) ? json.sizes.thumbnail.url :
          (json.url || '');

        renderFeatured(url);
      });
      frame.open();
    });
  }

  if(btnFRemove && inputFId){
    btnFRemove.addEventListener('click', function(){
      inputFId.value = '';
      renderFeatured('');
    });
  }

  // ============================================================
  // Gallery
  // ============================================================
  const btnAdd   = wrap.querySelector('[data-gal-add]');
  const btnClear = wrap.querySelector('[data-gal-clear]');
  const inputIds = wrap.querySelector('[data-gal-ids]');
  const grid     = wrap.querySelector('[data-gal-grid]');

  function readIds(){
    return (inputIds.value || '').split(',').map(v=>parseInt((v||'').trim(),10)).filter(v=>v>0);
  }
  function writeIds(ids){
    const uniq = Array.from(new Set(ids));
    inputIds.value = uniq.join(',');
  }
  function syncFromDom(){
    const ids = Array.from(grid.querySelectorAll('.mon-g-item'))
      .map(el => parseInt(el.getAttribute('data-id'),10))
      .filter(v=>v>0);
    writeIds(ids);
  }
  function makeItem(id, thumbUrl){
    const el = document.createElement('div');
    el.className = 'mon-g-item';
    el.setAttribute('data-id', id);
    el.setAttribute('draggable','true');
    el.innerHTML = '<img src="'+thumbUrl+'" alt="">' +
      '<button type="button" class="mon-g-remove" data-gal-remove title="حذف">×</button>';
    return el;
  }

  if(btnAdd && inputIds && grid){
    grid.addEventListener('click', function(e){
      const btn = e.target.closest('[data-gal-remove]');
      if(!btn) return;
      const item = btn.closest('.mon-g-item');
      if(item) item.remove();
      syncFromDom();
    });

    btnClear && btnClear.addEventListener('click', function(){
      grid.innerHTML = '';
      inputIds.value = '';
    });

    btnAdd.addEventListener('click', function(){
      const frame = wp.media({ title:'اختيار صور الألبوم', button:{text:'إضافة'}, multiple:true });
      frame.on('select', function(){
        const selection = frame.state().get('selection').toJSON();
        const current = readIds();
        selection.forEach(att=>{
          const id = att.id;
          if(!id || current.includes(id)) return;
          const thumb = (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) ? att.sizes.thumbnail.url : (att.url||'');
          if(!thumb) return;
          current.push(id);
          grid.appendChild(makeItem(id, thumb));
        });
        writeIds(current);
      });
      frame.open();
    });

    let dragEl = null;
    grid.addEventListener('dragstart', function(e){
      const item = e.target.closest('.mon-g-item');
      if(!item) return;
      dragEl = item;
      e.dataTransfer.effectAllowed = 'move';
    });

    grid.addEventListener('dragover', function(e){
      e.preventDefault();
      const over = e.target.closest('.mon-g-item');
      if(!over || !dragEl || over === dragEl) return;
      const rect = over.getBoundingClientRect();
      const next = (e.clientX - rect.left) / rect.width > 0.5;
      grid.insertBefore(dragEl, next ? over.nextSibling : over);
    });

    grid.addEventListener('drop', function(e){
      e.preventDefault();
      dragEl = null;
      syncFromDom();
    });
  }

  // ============================================================
  // Invites Manager
  // ============================================================
  const jsonInput = wrap.querySelector('[data-inv-json]');
  const listEl    = wrap.querySelector('[data-inv-list]');
  const countEl   = wrap.querySelector('[data-inv-count]');
  const searchEl  = wrap.querySelector('[data-inv-search]');
  const phoneEl   = wrap.querySelector('[data-inv-phone]');
  const nameEl    = wrap.querySelector('[data-inv-name]');
  const btnAddInv = wrap.querySelector('[data-inv-add]');
  const btnClearInv = wrap.querySelector('[data-inv-clear]');
  const btnExport = wrap.querySelector('[data-inv-export]');
  const btnImport = wrap.querySelector('[data-inv-import]');

  const taManual  = wrap.querySelector('textarea[name="mon_invited_phones"]');
  const taCsv     = wrap.querySelector('textarea[name="mon_invited_csv"]');

  function safeParse(){
    try {
      const v = JSON.parse(jsonInput.value || '[]');
      return Array.isArray(v) ? v : [];
    } catch(e){
      return [];
    }
  }

  function normalizeDigits(raw){
    const digits = String(raw||'').replace(/\\D+/g,'');
    if(!digits) return '';
    let d = digits.startsWith('00') ? digits.slice(2) : digits;

    if(d.length === 10 && d.startsWith('0')) d = '966' + d.slice(1);
    if(d.length === 9 && d.startsWith('5'))  d = '966' + d;

    return d;
  }

  function setRows(rows){
    const map = new Map();
    rows.forEach(r=>{
      const phone = normalizeDigits(r.phone);
      if(!phone) return;
      const name = String(r.name||'').trim();

      if(!map.has(phone)){
        map.set(phone, {phone, name});
      }else{
        const cur = map.get(phone);
        if(!cur.name && name) cur.name = name;
        map.set(phone, cur);
      }
    });

    const out = Array.from(map.values());
    jsonInput.value = JSON.stringify(out);
    renderInvites();
  }

  function renderInvites(){
    const rows = safeParse();
    const q = (searchEl && searchEl.value) ? searchEl.value.trim().toLowerCase() : '';
    const filtered = !q ? rows : rows.filter(r =>
      String(r.phone||'').includes(q) || String(r.name||'').toLowerCase().includes(q)
    );

    listEl.innerHTML = '';
    filtered.forEach(r=>{
      const row = document.createElement('div');
      row.className = 'mon-inv-row';

      const left = document.createElement('div');
      left.innerHTML =
        '<div class="mon-inv-phone">'+(r.phone||'')+'</div>' +
        (r.name ? '<div class="mon-inv-name">'+String(r.name).replace(/</g,'&lt;')+'</div>' : '');

      const right = document.createElement('div');
      right.innerHTML = '<button type="button" class="mon-btn" data-inv-del="'+(r.phone||'')+'">حذف</button>';

      row.appendChild(left);
      row.appendChild(right);
      listEl.appendChild(row);
    });

    if(countEl) countEl.textContent = String(rows.length);
  }

  // delete
  listEl && listEl.addEventListener('click', function(e){
    const btn = e.target.closest('[data-inv-del]');
    if(!btn) return;
    const phone = btn.getAttribute('data-inv-del') || '';
    const rows = safeParse().filter(r => String(r.phone||'') !== phone);
    setRows(rows);
  });

  // add single
  btnAddInv && btnAddInv.addEventListener('click', function(){
    const phone = normalizeDigits(phoneEl ? phoneEl.value : '');
    const name  = (nameEl ? nameEl.value : '').trim();
    if(!phone){ alert('أدخل رقم صحيح'); return; }
    const rows = safeParse();
    rows.push({phone, name});
    setRows(rows);
    if(phoneEl) phoneEl.value = '';
    if(nameEl) nameEl.value = '';
  });

  // clear
  btnClearInv && btnClearInv.addEventListener('click', function(){
    if(!confirm('هل تريد حذف كل المدعوين؟')) return;
    setRows([]);
  });

  // search
  searchEl && searchEl.addEventListener('input', renderInvites);

  // export
  btnExport && btnExport.addEventListener('click', function(){
    const rows = safeParse();
    const lines = ['phone,name'].concat(rows.map(r=>{
      const p = String(r.phone||'');
      const n = String(r.name||'').replace(/"/g,'""');
      return p + ',"' + n + '"';
    }));
    const blob = new Blob([lines.join('\\n')], {type:'text/csv;charset=utf-8'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'invites.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
  });

  // import & merge from textareas (file import happens server-side on submit)
  btnImport && btnImport.addEventListener('click', function(){
    const rows = safeParse();
    const add = [];

    if(taManual && taManual.value.trim()){
      taManual.value.split(/\\r?\\n+/).forEach(line=>{
        const p = normalizeDigits(line.trim());
        if(p) add.push({phone:p, name:''});
      });
    }

    if(taCsv && taCsv.value.trim()){
      taCsv.value.split(/\\r?\\n+/).forEach(line=>{
        const l = line.trim();
        if(!l) return;
        const parts = l.split(',');
        const p = normalizeDigits(parts[0]||'');
        const n = (parts[1]||'').trim();
        if(p) add.push({phone:p, name:n});
      });
    }

    setRows(rows.concat(add));
    alert('تم دمج المدعوين في القائمة ✅');
  });

  renderInvites();
})();
JS;

        wp_register_script('mon-events-bp-form-inline', false, [], '1.0.0', true);
        wp_enqueue_script('mon-events-bp-form-inline');
        wp_add_inline_script('mon-events-bp-form-inline', $js);
    }

    /* --------------------------------------------------------------------------
     * Submit handler: create/update + save meta + taxonomy + featured + gallery + invites
     * -------------------------------------------------------------------------- */

    public function handle_event_form_submit(): void
    {
        if (!is_user_logged_in()) return;
        if (empty($_POST['mon_event_form_submit'])) return;

        if (empty($_POST['mon_event_form_nonce']) || !wp_verify_nonce($_POST['mon_event_form_nonce'], 'mon_event_form_save')) {
            return;
        }

        $current_user = (int) get_current_user_id();

        $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
        $editing  = $event_id > 0;

        if ($editing) {
            $ev = get_post($event_id);
            if (!$ev || $ev->post_type !== 'event') return;
            if ((int) $ev->post_author !== $current_user) return;
        }

        $title   = sanitize_text_field($_POST['event_title'] ?? '');
        $content = wp_kses_post($_POST['event_content'] ?? '');

        if ($title === '') return;

        $date     = sanitize_text_field($_POST['event_date'] ?? '');
        $time     = sanitize_text_field($_POST['event_time'] ?? '');
        $location = sanitize_text_field($_POST['event_location'] ?? '');
        $maps     = esc_url_raw($_POST['event_maps'] ?? '');

        $event_type_term = isset($_POST['event_type_term']) ? (int) $_POST['event_type_term'] : 0;

        $hide_visitors        = !empty($_POST['mon_hide_visitors']) ? 1 : 0;
        $hide_gallery         = !empty($_POST['mon_hide_gallery']) ? 1 : 0;
        $hide_public_comments = !empty($_POST['mon_hide_public_comments']) ? 1 : 0;
        $close_comments_after = !empty($_POST['mon_close_comments_after']) ? 1 : 0;

        $featured_id = isset($_POST['mon_featured_id']) ? (int) $_POST['mon_featured_id'] : 0;

        $gallery_ids_raw = sanitize_text_field($_POST['mon_gallery_ids'] ?? '');
        $gallery_ids     = $this->sanitize_gallery_ids($gallery_ids_raw);
        $gallery_ids_str = implode(',', $gallery_ids);

        // Invites: manager JSON (source of truth)
        $inv_json = sanitize_textarea_field($_POST['mon_invites_json'] ?? '');
        $merged   = $this->sanitize_invites_json($inv_json);

        // Bulk fallback inputs (if JSON empty OR first time)
        $manual_raw = sanitize_textarea_field($_POST['mon_invited_phones'] ?? '');
        $csv_raw    = sanitize_textarea_field($_POST['mon_invited_csv'] ?? '');

        // CSV upload => append to csv_raw
        if (!empty($_FILES['mon_invited_file']) && !empty($_FILES['mon_invited_file']['tmp_name'])) {
            $file = $_FILES['mon_invited_file'];
            if (empty($file['error']) && is_uploaded_file($file['tmp_name'])) {
                $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
                if ($ext === 'csv') {
                    $csv_from_file = $this->read_csv_file_content($file['tmp_name']);
                    if ($csv_from_file !== '') {
                        $csv_raw = trim($csv_raw . "\n" . $csv_from_file);
                    }
                }
            }
        }

        // If JSON empty => merge from fallback
        if (empty($merged)) {
            $manual = $this->parse_invites_from_raw_list($manual_raw);
            $csv    = $this->parse_invites_from_csv($csv_raw);
            $merged = $this->merge_invites($manual, $csv);
        } else {
            // Even if JSON exists, you may still want to merge newly pasted bulk:
            $manual = $this->parse_invites_from_raw_list($manual_raw);
            $csv    = $this->parse_invites_from_csv($csv_raw);
            $merged = $this->merge_invites($merged, $this->merge_invites($manual, $csv));
        }

        // Create/Update post
        $postarr = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_type'    => 'event',
            'post_status'  => 'publish',
            'post_author'  => $current_user,
        ];

        if ($editing) {
            $postarr['ID'] = $event_id;
            $updated = wp_update_post($postarr, true);
            if (is_wp_error($updated)) return;
            $saved_id = $event_id;
        } else {
            $inserted = wp_insert_post($postarr, true);
            if (is_wp_error($inserted) || !$inserted) return;
            $saved_id = (int) $inserted;
        }

        // Save meta
        update_post_meta($saved_id, '_mon_event_date', $date);
        update_post_meta($saved_id, '_mon_event_time', $time);
        update_post_meta($saved_id, '_mon_event_location', $location);
        update_post_meta($saved_id, '_mon_event_maps', $maps);

        update_post_meta($saved_id, '_mon_hide_visitors', $hide_visitors);
        update_post_meta($saved_id, '_mon_hide_gallery', $hide_gallery);
        update_post_meta($saved_id, '_mon_hide_public_comments', $hide_public_comments);
        update_post_meta($saved_id, '_mon_close_comments_after', $close_comments_after);

        update_post_meta($saved_id, '_mon_gallery_ids', $gallery_ids_str);

        // Taxonomy
        if ($event_type_term > 0) wp_set_post_terms($saved_id, [$event_type_term], 'event_type', false);
        else wp_set_post_terms($saved_id, [], 'event_type', false);

        // Featured
        if ($featured_id > 0) set_post_thumbnail($saved_id, $featured_id);
        else delete_post_thumbnail($saved_id);

        // Invites
        update_post_meta($saved_id, '_mon_invites', $merged);
        update_post_meta($saved_id, '_mon_invited_phones', implode("\n", array_keys($merged)));

        // Redirect
        $redirect = '';
        if (function_exists('bp_displayed_user_domain') && !empty(bp_displayed_user_domain())) {
            $base = trailingslashit(bp_displayed_user_domain() . $this->form_slug);
            $redirect = add_query_arg(['event_id' => $saved_id, 'saved' => '1'], $base);
        } else {
            $redirect = add_query_arg(['saved' => '1'], get_permalink($saved_id));
        }

        wp_safe_redirect($redirect);
        exit;
    }
}
