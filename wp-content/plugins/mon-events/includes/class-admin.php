<?php
// includes/class-admin.php

if (!defined('ABSPATH')) exit;

class Mon_Events_Admin
{
    /** @var Mon_Events_MVP */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function register(): void
    {
        // Meta boxes
        add_action('add_meta_boxes', [$this, 'register_metaboxes']);

        // Save event meta
        add_action('save_post_event', [$this, 'save_event_meta'], 10, 2);

        // Enable file upload on edit form
        add_action('post_edit_form_tag', [$this, 'add_multipart_form_enctype']);

        // Admin pages
        add_action('admin_menu', [$this, 'register_admin_pages']);

        // Exports
        add_action('admin_post_mon_export_invites_csv', [$this, 'handle_export_invites_csv']);
        add_action('admin_post_mon_export_rsvps_csv',   [$this, 'handle_export_rsvps_csv']);
    }

    /* --------------------------------------------------------------------------
     * Meta Boxes
     * -------------------------------------------------------------------------- */

    public function register_metaboxes()
    {
        add_meta_box(
            'mon_event_details',
            'إعدادات المناسبة',
            [$this, 'render_event_details_box'],
            'event',
            'normal',
            'high'
        );

        add_meta_box(
            'mon_event_rsvps',
            'تأكيدات الحضور (RSVP)',
            [$this, 'render_event_rsvps_box'],
            'event',
            'side',
            'default'
        );

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
        wp_nonce_field('mon_event_save', 'mon_event_nonce');

        $date     = get_post_meta($post->ID, '_mon_event_date', true);
        $time     = get_post_meta($post->ID, '_mon_event_time', true);
        $location = get_post_meta($post->ID, '_mon_event_location', true);
        $maps     = get_post_meta($post->ID, '_mon_event_maps', true);

        $hide_gallery         = (int) get_post_meta($post->ID, '_mon_hide_gallery', true);
        $hide_visitors        = (int) get_post_meta($post->ID, '_mon_hide_visitors', true);
        $close_comments_after  = (int) get_post_meta($post->ID, '_mon_close_comments_after', true);
        $hide_public_comments  = (int) get_post_meta($post->ID, '_mon_hide_public_comments', true);
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
        $rsvps = get_post_meta($post->ID, Mon_Events_RSVP::RSVP_META_KEY, true);
        $count = is_array($rsvps) ? count($rsvps) : 0;

        echo '<p>عدد الردود: <strong>' . esc_html($count) . '</strong></p>';
        echo '<p style="font-size:12px;color:#666">عرض التفاصيل سيتم داخل لوحة “إدارة المدعوين”.</p>';
    }

    public function render_event_invites_box($post)
    {
        $raw_list = (string) get_post_meta($post->ID, '_mon_invited_phones', true);
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
     * Save Meta
     * -------------------------------------------------------------------------- */

    public function save_event_meta($post_id, $post)
    {
        // 0) Security
        if (!isset($_POST['mon_event_nonce']) || !wp_verify_nonce($_POST['mon_event_nonce'], 'mon_event_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // 1) Basic fields
        $date     = sanitize_text_field($_POST['mon_event_date'] ?? '');
        $time     = sanitize_text_field($_POST['mon_event_time'] ?? '');
        $location = sanitize_text_field($_POST['mon_event_location'] ?? '');
        $maps     = esc_url_raw($_POST['mon_event_maps'] ?? '');

        update_post_meta($post_id, '_mon_event_date', $date);
        update_post_meta($post_id, '_mon_event_time', $time);
        update_post_meta($post_id, '_mon_event_location', $location);
        update_post_meta($post_id, '_mon_event_maps', $maps);

        update_post_meta($post_id, '_mon_hide_gallery',        isset($_POST['mon_hide_gallery']) ? 1 : 0);
        update_post_meta($post_id, '_mon_hide_visitors',       isset($_POST['mon_hide_visitors']) ? 1 : 0);
        update_post_meta($post_id, '_mon_hide_public_comments', isset($_POST['mon_hide_public_comments']) ? 1 : 0);
        update_post_meta($post_id, '_mon_close_comments_after', isset($_POST['mon_close_comments_after']) ? 1 : 0);

        // 2) Invites nonce
        $invites_nonce_ok = (
            isset($_POST['mon_event_invites_nonce']) &&
            wp_verify_nonce($_POST['mon_event_invites_nonce'], 'mon_event_invites_save')
        );
        if (!$invites_nonce_ok) return;

        $manual_raw = sanitize_textarea_field($_POST['mon_invited_phones'] ?? '');
        $csv_raw    = sanitize_textarea_field($_POST['mon_invited_csv'] ?? '');

        // Upload CSV
        if (!empty($_FILES['mon_invited_file']) && !empty($_FILES['mon_invited_file']['tmp_name'])) {
            $file = $_FILES['mon_invited_file'];
            if (empty($file['error'])) {
                $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
                if ($ext === 'csv') {
                    $csv_from_file = $this->read_csv_file_content($file['tmp_name']);
                    if ($csv_from_file !== '') {
                        $csv_raw = trim($csv_raw . "\n" . $csv_from_file);
                    }
                }
            }
        }

        $manual = $this->parse_invites_from_raw_list($manual_raw);
        $csv    = $this->parse_invites_from_csv($csv_raw);

        $merged = $manual;
        foreach ($csv as $phone => $row) {
            if (!isset($merged[$phone])) {
                $merged[$phone] = $row;
            } else {
                if (!empty($row['name'])) $merged[$phone]['name'] = $row['name'];
            }
        }

        foreach ($merged as $phone => $row) {
            $merged[$phone] = [
                'name' => sanitize_text_field($row['name'] ?? ''),
            ];
        }

        update_post_meta($post_id, '_mon_invites', $merged);
        update_post_meta($post_id, '_mon_invited_phones', implode("\n", array_keys($merged)));
    }

    private function read_csv_file_content($tmp_path): string
    {
        $content = @file_get_contents($tmp_path);
        if (!is_string($content) || $content === '') return '';

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (strlen($content) > 1024 * 1024) {
            $content = substr($content, 0, 1024 * 1024);
        }

        return trim($content);
    }

    /* --------------------------------------------------------------------------
     * Admin Page: Invites Manager
     * -------------------------------------------------------------------------- */

    public function add_multipart_form_enctype()
    {
        echo ' enctype="multipart/form-data"';
    }

    public function register_admin_pages()
    {
        add_submenu_page(
            'edit.php?post_type=event',
            'إدارة المدعوين',
            'إدارة المدعوين',
            'edit_posts',
            'mon-event-invites',
            [$this, 'render_admin_invites_page']
        );
    }

    public function render_admin_invites_page()
    {
        if (!current_user_can('edit_posts')) wp_die('غير مسموح.');

        $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        if ($event_id <= 0 && !empty($events)) $event_id = (int) $events[0]->ID;

    $invites = $event_id ? $this->plugin->invites()->get_invites_structured($event_id) : [];
    $rsvps   = $event_id ? get_post_meta($event_id, Mon_Events_MVP::RSVP_META_KEY, true) : [];
        if (!is_array($rsvps)) $rsvps = [];

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
                    <a class="button button-secondary" href="<?php echo esc_url($this->admin_export_url('mon_export_invites_csv', $event_id)); ?>">
                        تصدير المدعوين CSV
                    </a>
                    <a class="button button-secondary" href="<?php echo esc_url($this->admin_export_url('mon_export_rsvps_csv', $event_id)); ?>">
                        تصدير RSVP CSV
                    </a>
                </div>

                <div style="display:grid;grid-template-columns:1fr;gap:12px">

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

                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">
                        <div style="padding:12px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center">
                            <h2 style="margin:0;font-size:16px">ردود RSVP</h2>
                            <div style="color:#6b7280;font-size:12px">ملاحظة: المفاتيح قد تكون u:ID أو p:PHONE</div>
                        </div>

                        <table class="widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width:160px">Key</th>
                                    <th>المستخدم/الهاتف</th>
                                    <th style="width:140px">الحالة</th>
                                    <th style="width:220px">آخر تحديث</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (!$rsvps) {
                                    echo '<tr><td colspan="4" style="padding:14px;color:#6b7280">لا توجد ردود RSVP بعد.</td></tr>';
                                } else {
                                    foreach ($rsvps as $key => $row) {
                                        $key = (string) $key;

                                        $label = '';
                                        if (strpos($key, 'u:') === 0) {
                                            $uid = (int) substr($key, 2);
                                            $user = get_user_by('id', $uid);
                                            $label = $user ? $user->display_name : '(مستخدم محذوف)';
                                        } elseif (strpos($key, 'p:') === 0) {
                                            $label = 'Phone ' . substr($key, -4);
                                        } else {
                                            $label = $key;
                                        }

                                        $status = ($row['status'] ?? '') === 'attending' ? 'سأحضر' : 'لن أحضر';
                                        $updated = $row['updated_at'] ?? '';

                                        echo '<tr>';
                                        echo '<td style="direction:ltr;font-family:monospace">' . esc_html($key) . '</td>';
                                        echo '<td>' . esc_html($label) . '</td>';
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

    /* --------------------------------------------------------------------------
     * Export
     * -------------------------------------------------------------------------- */

    private function admin_export_url(string $action, int $event_id): string
    {
        $args = [
            'action'   => $action,
            'event_id' => (int) $event_id,
            '_wpnonce' => wp_create_nonce($action . '|' . (int)$event_id),
        ];
        return admin_url('admin-post.php?' . http_build_query($args));
    }

    public function handle_export_invites_csv()
    {
        $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        $nonce    = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';

        if ($event_id <= 0 || !wp_verify_nonce($nonce, 'mon_export_invites_csv|' . $event_id)) wp_die('Nonce غير صالح.');
        if (!current_user_can('edit_post', $event_id)) wp_die('غير مسموح.');

        $invites = $this->get_invites_structured($event_id);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="event-' . $event_id . '-invites.csv"');
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, ['phone', 'name']);
        foreach ($invites as $phone => $row) {
            fputcsv($out, [$phone, $row['name'] ?? '']);
        }
        fclose($out);
        exit;
    }

    public function handle_export_rsvps_csv()
    {
        $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        $nonce    = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';

        if ($event_id <= 0 || !wp_verify_nonce($nonce, 'mon_export_rsvps_csv|' . $event_id)) wp_die('Nonce غير صالح.');
        if (!current_user_can('edit_post', $event_id)) wp_die('غير مسموح.');

        $rsvps = get_post_meta($event_id, Mon_Events_RSVP::RSVP_META_KEY, true);
        if (!is_array($rsvps)) $rsvps = [];

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="event-' . $event_id . '-rsvps.csv"');
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, ['key', 'label', 'status', 'updated_at']);

        foreach ($rsvps as $key => $row) {
            $key = (string)$key;

            $label = '';
            if (strpos($key, 'u:') === 0) {
                $uid = (int) substr($key, 2);
                $user = get_user_by('id', $uid);
                $label = $user ? $user->display_name : '';
            } elseif (strpos($key, 'p:') === 0) {
                $label = 'phone:' . substr($key, 2);
            } else {
                $label = $key;
            }

            $status_raw = $row['status'] ?? '';
            $status = ($status_raw === 'attending') ? 'attending' : 'declined';
            $updated = $row['updated_at'] ?? '';

            fputcsv($out, [$key, $label, $status, $updated]);
        }

        fclose($out);
        exit;
    }

    /* --------------------------------------------------------------------------
     * Invites Helpers (داخل Admin حالياً)
     * -------------------------------------------------------------------------- */

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
                $out[$p] = ['name' => sanitize_text_field($row['name'] ?? '')];
            }
            ksort($out);
            return $out;
        }

        $raw = (string) get_post_meta($event_id, '_mon_invited_phones', true);
        $out = $this->parse_invites_from_raw_list($raw);
        ksort($out);
        return $out;
    }
}
