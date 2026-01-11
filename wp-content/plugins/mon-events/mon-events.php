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


    public function __construct()
    {
        $this->comments = new Mon_Events_Comments($this);
        $this->comments->register();
        $this->invites = new Mon_Events_Invites($this);
        $this->invites->register();
        $this->rsvp = new Mon_Events_RSVP($this);
        $this->rsvp->register();
        // CPT + Tax
        add_action('init', [$this, 'register_cpt_tax'], 0);

        // Meta boxes
        add_action('add_meta_boxes', [$this, 'register_metaboxes']);

        // Save event meta
        add_action('save_post_event', [$this, 'save_event_meta'], 10, 2);

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
     * Meta Boxes
     * -------------------------------------------------------------------------- */

    public function register_metaboxes()
    {
        add_meta_box('mon_event_details', 'إعدادات المناسبة', [$this, 'render_event_details_box'], 'event', 'normal', 'high');
        add_meta_box('mon_event_rsvps', 'تأكيدات الحضور (RSVP)', [$this, 'render_event_rsvps_box'], 'event', 'side', 'default');

    }

    public function render_event_details_box($post)
    {
        // Nonce عام لحفظ بيانات المناسبة
        wp_nonce_field('mon_event_save', 'mon_event_nonce');

        $date = get_post_meta($post->ID, '_mon_event_date', true);
        $time = get_post_meta($post->ID, '_mon_event_time', true);
        $location = get_post_meta($post->ID, '_mon_event_location', true);
        $maps = get_post_meta($post->ID, '_mon_event_maps', true);

        $hide_gallery = (int) get_post_meta($post->ID, '_mon_hide_gallery', true);
        $hide_visitors = (int) get_post_meta($post->ID, '_mon_hide_visitors', true);
        $close_comments_after = (int) get_post_meta($post->ID, '_mon_close_comments_after', true);
        $hide_public_comments = (int) get_post_meta($post->ID, '_mon_hide_public_comments', true);
?>
        <style>
            .mon-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px
            }

            .mon-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 6px
            }

            .mon-field input[type="text"],
            .mon-field input[type="date"],
            .mon-field input[type="time"] {
                width: 100%
            }

            .mon-toggles {
                margin-top: 14px
            }

            .mon-toggles label {
                display: block;
                margin: 6px 0
            }
        </style>

        <div class="mon-grid">
            <div class="mon-field">
                <label>تاريخ المناسبة</label>
                <input type="date" name="mon_event_date" value="<?php echo esc_attr($date); ?>">
            </div>
            <div class="mon-field">
                <label>وقت المناسبة</label>
                <input type="time" name="mon_event_time" value="<?php echo esc_attr($time); ?>">
            </div>
            <div class="mon-field">
                <label>الموقع (نص)</label>
                <input type="text" name="mon_event_location" value="<?php echo esc_attr($location); ?>" placeholder="مثال: قاعة الورد - الرياض">
            </div>
            <div class="mon-field">
                <label>رابط خرائط Google (اختياري)</label>
                <input type="text" name="mon_event_maps" value="<?php echo esc_attr($maps); ?>" placeholder="https://maps.google.com/...">
            </div>
        </div>

        <div class="mon-toggles">
            <label><input type="checkbox" name="mon_hide_visitors" value="1" <?php checked($hide_visitors, 1); ?>> إخفاء عدد الزوار</label>
            <label><input type="checkbox" name="mon_hide_gallery" value="1" <?php checked($hide_gallery, 1); ?>> إخفاء ألبوم الصور</label>
            <label><input type="checkbox" name="mon_hide_public_comments" value="1" <?php checked($hide_public_comments, 1); ?>> إخفاء التعليقات العامة</label>
            <label><input type="checkbox" name="mon_close_comments_after" value="1" <?php checked($close_comments_after, 1); ?>> إغلاق التعليقات بعد تاريخ المناسبة</label>
        </div>
    <?php
    }

    public function render_event_rsvps_box($post)
    {
        $rsvps = get_post_meta($post->ID, self::RSVP_META_KEY, true);
        $count = is_array($rsvps) ? count($rsvps) : 0;

        echo '<p>عدد الردود: <strong>' . esc_html($count) . '</strong></p>';
        echo '<p style="font-size:12px;color:#666">عرض التفاصيل سيتم في المرحلة القادمة داخل لوحة “إدارة المدعوين”.</p>';
    }


    /* --------------------------------------------------------------------------
     * Saving Event Meta
     * -------------------------------------------------------------------------- */

    public function save_event_meta($post_id, $post)
    {
        // =========================================================
        // 0) Basic security checks
        // =========================================================
        if (!isset($_POST['mon_event_nonce']) || !wp_verify_nonce($_POST['mon_event_nonce'], 'mon_event_save')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // =========================================================
        // 1) Basic Event Fields (always save)
        // =========================================================
        $date     = sanitize_text_field($_POST['mon_event_date'] ?? '');
        $time     = sanitize_text_field($_POST['mon_event_time'] ?? '');
        $location = sanitize_text_field($_POST['mon_event_location'] ?? '');
        $maps     = esc_url_raw($_POST['mon_event_maps'] ?? '');

        update_post_meta($post_id, '_mon_event_date', $date);
        update_post_meta($post_id, '_mon_event_time', $time);
        update_post_meta($post_id, '_mon_event_location', $location);
        update_post_meta($post_id, '_mon_event_maps', $maps);

        update_post_meta($post_id, '_mon_hide_gallery', isset($_POST['mon_hide_gallery']) ? 1 : 0);
        update_post_meta($post_id, '_mon_hide_visitors', isset($_POST['mon_hide_visitors']) ? 1 : 0);
        update_post_meta($post_id, '_mon_hide_public_comments', isset($_POST['mon_hide_public_comments']) ? 1 : 0);
        update_post_meta($post_id, '_mon_close_comments_after', isset($_POST['mon_close_comments_after']) ? 1 : 0);

        // =========================================================
        // 2) Invites (manual + paste CSV + upload CSV)
        // =========================================================
        // مهم: لا نكسر حفظ باقي الحقول إذا nonce المدعوين ناقص.
        $invites_nonce_ok = (
            isset($_POST['mon_event_invites_nonce']) &&
            wp_verify_nonce($_POST['mon_event_invites_nonce'], 'mon_event_invites_save')
        );

        if (!$invites_nonce_ok) {
            return;
        }

        // (A) manual textarea
        $manual_raw = sanitize_textarea_field($_POST['mon_invited_phones'] ?? '');

        // (B) pasted CSV textarea
        $csv_raw = sanitize_textarea_field($_POST['mon_invited_csv'] ?? '');

        // (C) uploaded CSV file from Excel
        if (!empty($_FILES['mon_invited_file']) && !empty($_FILES['mon_invited_file']['tmp_name'])) {
            $file = $_FILES['mon_invited_file'];

            // Ignore if upload error
            if (empty($file['error'])) {
                $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
                if ($ext === 'csv') {
                    // read file content safely
                    $csv_from_file = $this->mon_read_csv_file_content($file['tmp_name']);
                    if ($csv_from_file !== '') {
                        // merge file content with pasted content
                        $csv_raw = trim($csv_raw . "\n" . $csv_from_file);
                    }
                }
            }
        }

        // Parse sources into structured arrays: phone => ['name' => ...]
        $manual = $this->mon_parse_invites_from_raw_list($manual_raw);
        $csv    = $this->mon_parse_invites_from_csv($csv_raw);

        // Merge: CSV name wins if provided
        $merged = $manual;
        foreach ($csv as $phone => $row) {
            if (!isset($merged[$phone])) {
                $merged[$phone] = $row;
            } else {
                if (!empty($row['name'])) {
                    $merged[$phone]['name'] = $row['name'];
                }
            }
        }

        // Final sanitize + ensure structure
        foreach ($merged as $phone => $row) {
            $merged[$phone] = [
                'name' => sanitize_text_field($row['name'] ?? ''),
            ];
        }

        // Save structured list (future use)
        update_post_meta($post_id, '_mon_invites', $merged);

        // Save raw phones list (used by Gate in single-event.php)
        $raw_lines = implode("\n", array_keys($merged));
        update_post_meta($post_id, '_mon_invited_phones', $raw_lines);
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


    /* --------------------------------------------------------------------------
     * Revisions
     * -------------------------------------------------------------------------- */

    public function exclude_rsvp_from_revisions($keys)
    {
        if (!is_array($keys)) $keys = [];
        $keys[] = self::RSVP_META_KEY;
        return array_values(array_unique($keys));
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

        $events = $this->get_events_by_user_rsvp($user_id);

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
     * Export RSVP CSV: user_id,user_name,status,updated_at
     */
    public function handle_export_rsvps_csv()
    {
        $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        $nonce    = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';

        if ($event_id <= 0 || !wp_verify_nonce($nonce, 'mon_export_rsvps_csv|' . $event_id)) {
            wp_die('Nonce غير صالح.');
        }
        if (!current_user_can('edit_post', $event_id)) {
            wp_die('غير مسموح.');
        }

        $rsvps = get_post_meta($event_id, self::RSVP_META_KEY, true);
        if (!is_array($rsvps)) $rsvps = [];

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="event-' . $event_id . '-rsvps.csv"');

        // UTF-8 BOM for Excel
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, ['user_id', 'user_name', 'status', 'updated_at']);

        foreach ($rsvps as $uid => $row) {
            $uid = (int)$uid;
            $user = get_user_by('id', $uid);
            $name = $user ? $user->display_name : '';
            $status_raw = $row['status'] ?? '';
            $status = ($status_raw === 'attending') ? 'attending' : 'declined';
            $updated = $row['updated_at'] ?? '';
            fputcsv($out, [$uid, $name, $status, $updated]);
        }

        fclose($out);
        exit;
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
