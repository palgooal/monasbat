<?php



if (!defined('ABSPATH')) exit;

class Mon_Events_MVP
{
    /**
     * RSVP_META_KEY
     * نخزن الردود على RSVP كـ array بالشكل:
     *  user_id => ['status' => attending|declined, 'updated_at' => '...']
     */
    const RSVP_META_KEY = '_mon_rsvps';

    public function __construct()
    {
        // CPT + Tax
        add_action('init', [$this, 'register_cpt_tax'], 0);

        // Meta boxes
        add_action('add_meta_boxes', [$this, 'register_metaboxes']);

        // Save event meta
        add_action('save_post_event', [$this, 'save_event_meta'], 10, 2);

        // RSVP shortcode
        add_shortcode('mon_event_rsvp', [$this, 'shortcode_rsvp']);

        // Prevent RSVP meta from going into revisions
        add_filter('wp_post_revision_meta_keys', [$this, 'exclude_rsvp_from_revisions']);

        // BuddyPress Tabs (MVP)
        add_action('bp_setup_nav', [$this, 'bp_add_my_events_tab'], 100);
        add_action('bp_setup_nav', [$this, 'bp_add_my_invites_tab'], 101);

        // Comments toggles for Events (as you already built)
        add_filter('comments_open', [$this, 'event_comments_open_filter'], 20, 2);
        add_filter('pings_open',    [$this, 'event_comments_open_filter'], 20, 2);
        add_filter('comments_open', [$this, 'event_require_login_for_comments'], 30, 2);
        add_filter('comments_array', [$this, 'event_comments_array_filter'], 20, 2);
        add_action('pre_comment_on_post', [$this, 'event_block_comment_submit'], 10, 1);

        // Open comments by default for newly created events فقط (اختياري)
        add_action('save_post_event', [$this, 'enable_comments_by_default_for_event'], 20, 3);

        add_action('post_edit_form_tag', [$this, 'add_multipart_form_enctype']);

        // Admin: Invites Manager page
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_post_mon_export_invites_csv', [$this, 'handle_export_invites_csv']);
        add_action('admin_post_mon_export_rsvps_csv',   [$this, 'handle_export_rsvps_csv']);
        add_filter('preprocess_comment', [$this, 'event_allow_guest_comment_if_gate'], 5);
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

        add_meta_box(
            'mon_event_invites',
            'قائمة المدعوين',
            [$this, 'render_event_invites_box'],
            'event',
            'normal',
            'default'
        );
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

    public function render_event_invites_box($post)
    {
        // نخزن القائمة الخام (نص) + قائمة منظمة (array)
        $raw_list = (string) get_post_meta($post->ID, '_mon_invited_phones', true);

        // nonce خاص بالمدعوين (حتى لا يكسر حفظ باقي الحقول)
        wp_nonce_field('mon_event_invites_save', 'mon_event_invites_nonce');
    ?>
        <div style="display:grid;grid-template-columns:1fr;gap:12px">

            <div style="padding:12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff">
                <h4 style="margin:0 0 8px">رفع ملف CSV من Excel (مستحسن)</h4>
                <p style="margin:0 0 10px;color:#6b7280;font-size:12px">
                    من Excel: Save As → CSV UTF-8. الصيغ المدعومة:
                    <b>phone</b> أو <b>phone,name</b>
                </p>

                <input type="file" name="mon_invited_file" accept=".csv,text/csv"
                    style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:12px;background:#fff">

                <p style="margin:10px 0 0;color:#6b7280;font-size:12px">
                    * عند الحفظ سيتم دمج الملف مع الإدخال اليدوي وحذف التكرارات.
                </p>
            </div>

            <div style="padding:12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff">
                <h4 style="margin:0 0 8px">إدخال يدوي (رقم في كل سطر)</h4>
                <p style="margin:0 0 10px;color:#6b7280;font-size:12px">
                    أمثلة مقبولة: <b>05xxxxxxxx</b> أو <b>9665xxxxxxxx</b> أو <b>9705xxxxxxxx</b>
                </p>

                <textarea name="mon_invited_phones" rows="8"
                    style="width:100%;direction:ltr;font-family:monospace;border:1px solid #e5e7eb;border-radius:12px;padding:10px"
                    placeholder="05xxxxxxxx&#10;9665xxxxxxxx"><?php echo esc_textarea($raw_list); ?></textarea>
            </div>

            <div style="padding:12px;border:1px dashed #e5e7eb;border-radius:12px;background:#fafafa">
                <h4 style="margin:0 0 8px">استيراد CSV (لصق محتوى الملف)</h4>
                <p style="margin:0 0 10px;color:#6b7280;font-size:12px">
                    الصيغ المدعومة:<br>
                    - عمود واحد: <b>phone</b><br>
                    - عمودان: <b>phone,name</b>
                </p>

                <textarea name="mon_invited_csv" rows="6"
                    style="width:100%;direction:ltr;font-family:monospace;border:1px solid #e5e7eb;border-radius:12px;padding:10px"
                    placeholder="9665xxxxxxx,Ahmed&#10;05yyyyyyyy,Ali"></textarea>

                <p style="margin:10px 0 0;color:#6b7280;font-size:12px">
                    * عند الحفظ سيتم دمج CSV مع الإدخال اليدوي وحذف التكرارات.
                </p>
            </div>

        </div>
    <?php
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

    /**
     * Read uploaded CSV file content safely.
     * - Removes UTF-8 BOM (Excel CSV UTF-8)
     * - Limits size to 1MB for safety
     */
    private function mon_read_csv_file_content($tmp_path): string
    {
        $content = @file_get_contents($tmp_path);
        if (!is_string($content) || $content === '') return '';

        // remove UTF-8 BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // limit to 1MB
        if (strlen($content) > 1024 * 1024) {
            $content = substr($content, 0, 1024 * 1024);
        }

        return trim($content);
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

    /* --------------------------------------------------------------------------
     * Comments logic (unchanged from your logic)
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
        if (!$this->is_event_post($post_id)) return;

        $is_hidden = $this->event_hide_public_comments($post_id);
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

        return $this->mon_gate_passed($post_id);
    }


    /**
     * فتح التعليقات تلقائيًا عند إنشاء مناسبة جديدة فقط (ليس عند التعديل)
     */
    public function enable_comments_by_default_for_event($post_id, $post, $update)
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!$post || $post->post_type !== 'event') return;

        // افتحها فقط عند أول إنشاء
        if ($update) return;

        remove_action('save_post_event', [$this, 'enable_comments_by_default_for_event'], 20);

        wp_update_post([
            'ID' => $post_id,
            'comment_status' => 'open',
            'ping_status' => 'closed',
        ]);

        add_action('save_post_event', [$this, 'enable_comments_by_default_for_event'], 20, 3);
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

    /* --------------------------------------------------------------------------
     * Invites Helpers
     * -------------------------------------------------------------------------- */

    /**
     * Normalize phone to international digits (default Saudi 966)
     * - 05xxxxxxxx  => 9665xxxxxxxx
     * - 5xxxxxxxx   => 9665xxxxxxxx
     * - 009665...   => 9665...
     * - 9665...     => 9665...
     */
    private function mon_normalize_phone($raw, $default_cc = '966'): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if (!$digits) return '';

        if (function_exists('str_starts_with') && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (substr($digits, 0, 2) === '00') {
            $digits = substr($digits, 2);
        }

        // 05xxxxxxxx => 9665xxxxxxxx
        if (strlen($digits) === 10 && substr($digits, 0, 1) === '0') {
            $digits = $default_cc . substr($digits, 1);
        }

        // 5xxxxxxxx => 9665xxxxxxxx
        if (strlen($digits) === 9 && substr($digits, 0, 1) === '5') {
            $digits = $default_cc . $digits;
        }

        return $digits;
    }

    /**
     * Manual list: split by newline or comma => phone only
     */
    private function mon_parse_invites_from_raw_list($raw): array
    {
        $parts = preg_split('/[\r\n,]+/', (string) $raw);
        $out = []; // phone => ['name' => '']

        foreach ($parts as $p) {
            $phone = $this->mon_normalize_phone(trim($p));
            if (!$phone) continue;
            $out[$phone] = ['name' => ''];
        }

        return $out;
    }

    /**
     * CSV paste parsing:
     * Supported:
     *  - phone
     *  - phone,name
     * If first row looks like a header => ignored
     */
    private function mon_parse_invites_from_csv($csv_text): array
    {
        $out = []; // phone => ['name' => '...']
        $lines = preg_split("/\r\n|\n|\r/", (string) $csv_text);

        $row_index = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $cols = str_getcsv($line);
            if (empty($cols)) continue;

            $col0 = strtolower(trim((string)($cols[0] ?? '')));
            $col1 = strtolower(trim((string)($cols[1] ?? '')));

            // header detection (first non-empty row)
            if ($row_index === 0) {
                if ($col0 === 'phone' || $col0 === 'mobile' || $col0 === 'number') {
                    $row_index++;
                    continue; // skip header row
                }
                if ($col0 === 'phone' && $col1 === 'name') {
                    $row_index++;
                    continue; // skip header row
                }
            }

            $phone_raw = (string)($cols[0] ?? '');
            $name_raw  = (string)($cols[1] ?? '');

            $phone = $this->mon_normalize_phone($phone_raw);
            if (!$phone) {
                $row_index++;
                continue;
            }

            $out[$phone] = ['name' => sanitize_text_field($name_raw)];
            $row_index++;
        }

        return $out;
    }

    /* --------------------------------------------------------------------------
     * Cookie Helpers (kept as you wrote - for future gate flow)
     * -------------------------------------------------------------------------- */

    private function mon_make_invite_cookie_value($event_id, $phone_norm): string
    {
        $payload = $event_id . '|' . $phone_norm;
        $sig = hash_hmac('sha256', $payload, wp_salt('auth'));
        return base64_encode($payload . '|' . $sig);
    }

    private function mon_verify_invite_cookie_value($cookie_value): array
    {
        $decoded = base64_decode((string) $cookie_value, true);
        if (!$decoded) return [false, 0, ''];

        $parts = explode('|', $decoded);
        if (count($parts) !== 3) return [false, 0, ''];

        [$event_id, $phone_norm, $sig] = $parts;

        $event_id = (int) $event_id;
        $phone_norm = (string) $phone_norm;

        if ($event_id <= 0 || !$phone_norm || !$sig) return [false, 0, ''];

        $payload = $event_id . '|' . $phone_norm;
        $expected = hash_hmac('sha256', $payload, wp_salt('auth'));

        if (!hash_equals($expected, $sig)) return [false, 0, ''];

        return [true, $event_id, $phone_norm];
    }
    public function add_multipart_form_enctype()
    {
        // يضيف enctype فقط في شاشة تحرير/إضافة بوست
        echo ' enctype="multipart/form-data"';
    }
    /**
     * ------------------------------------------------------------
     * Admin Pages: "إدارة المدعوين"
     * تظهر تحت قائمة "المناسبات" (CPT: event)
     * ------------------------------------------------------------
     */
    public function register_admin_pages()
    {
        // تحت: مناسبات > إدارة المدعوين
        add_submenu_page(
            'edit.php?post_type=event',
            'إدارة المدعوين',
            'إدارة المدعوين',
            'edit_posts',
            'mon-event-invites',
            [$this, 'render_admin_invites_page']
        );
    }

    /**
     * Read structured invites list from meta _mon_invites.
     * Fallback: if not found, parse _mon_invited_phones.
     *
     * Returns: [ phone => ['name' => '...'] ]
     */
    private function mon_get_invites_structured($event_id): array
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) return [];

        $invites = get_post_meta($event_id, '_mon_invites', true);
        if (is_array($invites) && !empty($invites)) {
            // Ensure normalized structure
            $out = [];
            foreach ($invites as $phone => $row) {
                $p = $this->mon_normalize_phone($phone);
                if (!$p) continue;
                $out[$p] = [
                    'name' => sanitize_text_field($row['name'] ?? ''),
                ];
            }
            ksort($out);
            return $out;
        }

        // Fallback: parse raw phones list
        $raw = (string) get_post_meta($event_id, '_mon_invited_phones', true);
        $out = $this->mon_parse_invites_from_raw_list($raw);
        ksort($out);
        return $out;
    }

    /**
     * Check if a normalized phone exists in the invited list for event.
     * Uses structured invites meta (_mon_invites) with fallback to raw list.
     */
    private function mon_is_phone_invited_mvp(int $event_id, string $phone_norm): bool
    {
        $event_id = (int) $event_id;
        $phone_norm = $this->mon_normalize_phone($phone_norm);

        if ($event_id <= 0 || $phone_norm === '') {
            return false;
        }

        $invites = $this->mon_get_invites_structured($event_id); // [phone => ['name'=>...]]
        return isset($invites[$phone_norm]);
    }


    /**
     * Render Admin Page UI
     */
    public function render_admin_invites_page()
    {
        if (!current_user_can('edit_posts')) {
            wp_die('غير مسموح.');
        }

        $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

        // Fetch events for dropdown (latest 200)
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        // If no event selected, auto-select first
        if ($event_id <= 0 && !empty($events)) {
            $event_id = (int) $events[0]->ID;
        }

        // Load invites + rsvps
        $invites = $event_id ? $this->mon_get_invites_structured($event_id) : [];
        $rsvps   = $event_id ? get_post_meta($event_id, self::RSVP_META_KEY, true) : [];
        if (!is_array($rsvps)) $rsvps = [];

        // Simple search
        $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        if ($q && $invites) {
            $q_l = mb_strtolower($q);
            $invites = array_filter($invites, function ($row, $phone) use ($q_l) {
                $name = mb_strtolower((string)($row['name'] ?? ''));
                $phone_s = (string)$phone;
                return (strpos($name, $q_l) !== false) || (strpos($phone_s, $q_l) !== false);
            }, ARRAY_FILTER_USE_BOTH);
        }

        $invites_count = is_array($invites) ? count($invites) : 0;
        $rsvp_count    = is_array($rsvps) ? count($rsvps) : 0;

    ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                <span class="dashicons dashicons-groups" style="font-size:28px;width:28px;height:28px"></span>
                إدارة المدعوين
            </h1>

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-bottom:12px">
                <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="post_type" value="event">
                    <input type="hidden" name="page" value="mon-event-invites">

                    <label style="font-weight:600">اختر المناسبة:</label>
                    <select name="event_id" style="min-width:320px">
                        <?php foreach ($events as $ev): ?>
                            <option value="<?php echo (int)$ev->ID; ?>" <?php selected($event_id, (int)$ev->ID); ?>>
                                <?php echo esc_html($ev->post_title ?: '(بدون عنوان)'); ?> — #<?php echo (int)$ev->ID; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" name="q" value="<?php echo esc_attr($q); ?>" placeholder="ابحث بالاسم أو الجوال..." style="min-width:260px">

                    <button class="button button-primary" type="submit">عرض</button>

                    <?php if ($event_id): ?>
                        <a class="button" href="<?php echo esc_url(get_edit_post_link($event_id)); ?>">فتح تحرير المناسبة</a>
                    <?php endif; ?>
                </form>

                <div style="margin-top:10px;color:#6b7280;font-size:12px">
                    إجمالي المدعوين: <b><?php echo (int)$invites_count; ?></b> —
                    إجمالي ردود RSVP: <b><?php echo (int)$rsvp_count; ?></b>
                </div>
            </div>

            <?php if (!$event_id): ?>
                <p>لا توجد مناسبات.</p>
            <?php else: ?>

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
                    <a class="button button-secondary"
                        href="<?php echo esc_url($this->mon_admin_export_url('mon_export_invites_csv', $event_id)); ?>">
                        تصدير المدعوين CSV
                    </a>

                    <a class="button button-secondary"
                        href="<?php echo esc_url($this->mon_admin_export_url('mon_export_rsvps_csv', $event_id)); ?>">
                        تصدير RSVP CSV
                    </a>
                </div>

                <div style="display:grid;grid-template-columns:1fr;gap:12px">

                    <!-- Invites Table -->
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">
                        <div style="padding:12px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center">
                            <h2 style="margin:0;font-size:16px">قائمة المدعوين</h2>
                            <div style="color:#6b7280;font-size:12px">مصدر البيانات: <code>_mon_invites</code> / <code>_mon_invited_phones</code></div>
                        </div>

                        <table class="widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width:60px">#</th>
                                    <th>الاسم</th>
                                    <th style="width:220px">الجوال (موحّد)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (!$invites) {
                                    echo '<tr><td colspan="3" style="padding:14px;color:#6b7280">لا يوجد مدعوون.</td></tr>';
                                } else {
                                    $i = 1;
                                    foreach ($invites as $phone => $row) {
                                        $name = $row['name'] ?? '';
                                        echo '<tr>';
                                        echo '<td>' . (int)$i++ . '</td>';
                                        echo '<td>' . esc_html($name ?: '—') . '</td>';
                                        echo '<td style="direction:ltr;font-family:monospace">' . esc_html($phone) . '</td>';
                                        echo '</tr>';
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- RSVP Table -->
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">
                        <div style="padding:12px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center">
                            <h2 style="margin:0;font-size:16px">ردود RSVP</h2>
                            <div style="color:#6b7280;font-size:12px">ملاحظة: RSVP مربوط بـ <code>user_id</code></div>
                        </div>

                        <table class="widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width:80px">User ID</th>
                                    <th>المستخدم</th>
                                    <th style="width:140px">الحالة</th>
                                    <th style="width:220px">آخر تحديث</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (!$rsvps) {
                                    echo '<tr><td colspan="4" style="padding:14px;color:#6b7280">لا توجد ردود RSVP بعد.</td></tr>';
                                } else {
                                    foreach ($rsvps as $uid => $row) {
                                        $uid = (int)$uid;
                                        $user = get_user_by('id', $uid);
                                        $name = $user ? $user->display_name : '(مستخدم محذوف)';
                                        $status = ($row['status'] ?? '') === 'attending' ? 'سأحضر' : 'لن أحضر';
                                        $updated = $row['updated_at'] ?? '';

                                        echo '<tr>';
                                        echo '<td>' . (int)$uid . '</td>';
                                        echo '<td>' . esc_html($name) . '</td>';
                                        echo '<td><b>' . esc_html($status) . '</b></td>';
                                        echo '<td style="direction:ltr;font-family:monospace">' . esc_html($updated) . '</td>';
                                        echo '</tr>';
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                </div>

            <?php endif; ?>
        </div>
<?php
    }

    /**
     * Helper: build export URL with nonce
     */
    private function mon_admin_export_url(string $action, int $event_id): string
    {
        $args = [
            'action'   => $action === 'mon_export_invites_csv' ? 'mon_export_invites_csv' : 'mon_export_rsvps_csv',
            'event_id' => (int)$event_id,
            '_wpnonce' => wp_create_nonce($action . '|' . (int)$event_id),
        ];
        return admin_url('admin-post.php?' . http_build_query($args));
    }

    /**
     * Export Invites CSV: phone,name
     */
    public function handle_export_invites_csv()
    {
        $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        $nonce    = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';

        if ($event_id <= 0 || !wp_verify_nonce($nonce, 'mon_export_invites_csv|' . $event_id)) {
            wp_die('Nonce غير صالح.');
        }
        if (!current_user_can('edit_post', $event_id)) {
            wp_die('غير مسموح.');
        }

        $invites = $this->mon_get_invites_structured($event_id);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="event-' . $event_id . '-invites.csv"');

        // UTF-8 BOM for Excel
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, ['phone', 'name']);

        foreach ($invites as $phone => $row) {
            fputcsv($out, [$phone, $row['name'] ?? '']);
        }

        fclose($out);
        exit;
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
        if (!$this->mon_gate_passed($post_id)) {
            wp_die('لا يمكنك إضافة تعليق قبل اجتياز التحقق.', 403);
        }

        // Attach a "virtual identity" based on phone to satisfy WP validation
        $phone = $this->mon_gate_phone($post_id);
        $commentdata['comment_author']       = $commentdata['comment_author'] ?: ('Guest ' . substr($phone, -4));
        $commentdata['comment_author_email'] = $commentdata['comment_author_email'] ?: ('p' . md5($phone) . '@invite.local');
        $commentdata['comment_author_url']   = '';

        return $commentdata;
    }
}

new Mon_Events_MVP();
