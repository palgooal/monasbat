<?php
// includes/class-invites.php

if (!defined('ABSPATH')) exit;

class Mon_Events_Invites
{
    /** @var Mon_Events_MVP */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Register hooks related to invites/gate/export
     */
    public function register(): void
    {
        // Metabox invites UI
        add_action('add_meta_boxes', [$this, 'register_invites_metabox']);

        // Ensure multipart for file upload in edit screen
        add_action('post_edit_form_tag', [$this, 'add_multipart_form_enctype']);

        // Save invites on event save
        add_action('save_post_event', [$this, 'save_event_invites_meta'], 20, 2); // priority 20 after basic meta

        // Admin: Invites Manager page
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_post_mon_export_invites_csv', [$this, 'handle_export_invites_csv']);

        add_action('mon_events_manage_invites', [$this, 'render_invites_manager'], 10, 1);

        // Frontend: Invites manager assets (single event + owner only)
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_invites_assets'], 20);

        // Frontend AJAX (owner only)
        add_action('wp_ajax_mon_invite_add',    [$this, 'ajax_invite_add']);
        add_action('wp_ajax_mon_invite_delete', [$this, 'ajax_invite_delete']);
        add_action('wp_ajax_mon_invite_status', [$this, 'ajax_invite_status']);
    }

    /* --------------------------------------------------------------------------
     * MetaBox: Invites
     * -------------------------------------------------------------------------- */

    public function register_invites_metabox(): void
    {
        add_meta_box(
            'mon_event_invites',
            'قائمة المدعوين',
            [$this, 'render_event_invites_box'],
            'event',
            'normal',
            'default'
        );
    }

    public function render_event_invites_box($post): void
    {
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

    public function add_multipart_form_enctype(): void
    {
        echo ' enctype="multipart/form-data"';
    }

    /* --------------------------------------------------------------------------
     * Saving Invites
     * -------------------------------------------------------------------------- */

    public function save_event_invites_meta($post_id, $post): void
    {
        $post_id = (int)$post_id;

        // لا نكسر حفظ باقي الحقول إذا nonce المدعوين ناقص
        $invites_nonce_ok = (
            isset($_POST['mon_event_invites_nonce']) &&
            wp_verify_nonce($_POST['mon_event_invites_nonce'], 'mon_event_invites_save')
        );

        if (!$invites_nonce_ok) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // (A) manual textarea
        $manual_raw = sanitize_textarea_field($_POST['mon_invited_phones'] ?? '');

        // (B) pasted CSV textarea
        $csv_raw = sanitize_textarea_field($_POST['mon_invited_csv'] ?? '');

        // (C) uploaded CSV file
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

        // Merge: CSV name wins
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

        // Final sanitize
        foreach ($merged as $phone => $row) {
            $merged[$phone] = [
                'name' => sanitize_text_field($row['name'] ?? ''),
            ];
        }

        update_post_meta($post_id, '_mon_invites', $merged);

        // raw phones list (used by gate)
        $raw_lines = implode("\n", array_keys($merged));
        update_post_meta($post_id, '_mon_invited_phones', $raw_lines);
    }

    /**
     * Read uploaded CSV content safely (BOM removal + size limit)
     */
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
     * Phone / Parsing Helpers
     * -------------------------------------------------------------------------- */

    public function normalize_phone($raw, $default_cc = '966'): string
    {
        $digits = preg_replace('/\D+/', '', (string)$raw);
        if (!$digits) return '';

        if ((function_exists('str_starts_with') && str_starts_with($digits, '00')) || substr($digits, 0, 2) === '00') {
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

    private function parse_invites_from_raw_list($raw): array
    {
        $parts = preg_split('/[\r\n,]+/', (string)$raw);
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
        $lines = preg_split("/\r\n|\n|\r/", (string)$csv_text);

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

    /* --------------------------------------------------------------------------
     * Read Invites Structured
     * -------------------------------------------------------------------------- */

    public function get_invites_structured($event_id): array
    {
        $event_id = (int)$event_id;
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
            ksort($out);
            return $out;
        }

        // fallback: raw phones list
        $raw = (string) get_post_meta($event_id, '_mon_invited_phones', true);
        $out = $this->parse_invites_from_raw_list($raw);
        ksort($out);
        return $out;
    }

    public function is_phone_invited($event_id, $phone_norm): bool
    {
        $event_id = (int)$event_id;
        $phone_norm = $this->normalize_phone($phone_norm);

        if ($event_id <= 0 || $phone_norm === '') return false;

        $invites = $this->get_invites_structured($event_id);
        return isset($invites[$phone_norm]);
    }

    /* --------------------------------------------------------------------------
     * Gate Cookie Helpers
     * -------------------------------------------------------------------------- */

    public function make_invite_cookie_value($event_id, $phone_norm): string
    {
        $payload = (int)$event_id . '|' . (string)$phone_norm;
        $sig = hash_hmac('sha256', $payload, wp_salt('auth'));
        return base64_encode($payload . '|' . $sig);
    }

    public function verify_invite_cookie_value($cookie_value): array
    {
        $decoded = base64_decode((string)$cookie_value, true);
        if (!$decoded) return [false, 0, ''];

        $parts = explode('|', $decoded);
        if (count($parts) !== 3) return [false, 0, ''];

        [$event_id, $phone_norm, $sig] = $parts;

        $event_id = (int)$event_id;
        $phone_norm = (string)$phone_norm;

        if ($event_id <= 0 || !$phone_norm || !$sig) return [false, 0, ''];

        $payload = $event_id . '|' . $phone_norm;
        $expected = hash_hmac('sha256', $payload, wp_salt('auth'));

        if (!hash_equals($expected, $sig)) return [false, 0, ''];

        return [true, $event_id, $phone_norm];
    }

    /**
     * Gate passed?
     * - Host/Admin bypass
     * - Valid signed cookie AND phone still in invited list
     */
    public function gate_passed($event_id): bool
    {
        $event_id = (int)$event_id;
        if ($event_id <= 0) return false;

        $author_id = (int) get_post_field('post_author', $event_id);

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
        $event_id = (int)$event_id;
        $cookie_name = 'mon_inv_' . $event_id;

        if (empty($_COOKIE[$cookie_name])) return '';
        [$ok, $cid, $phone_norm] = $this->verify_invite_cookie_value($_COOKIE[$cookie_name]);

        if (!$ok || (int)$cid !== $event_id) return '';
        return $phone_norm ?: '';
    }

    /* --------------------------------------------------------------------------
     * Admin Page + Export (Invites only)
     * -------------------------------------------------------------------------- */

    public function register_admin_pages(): void
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

    public function render_admin_invites_page(): void
    {
        if (!current_user_can('edit_posts')) wp_die('غير مسموح.');

        $event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        if ($event_id <= 0 && !empty($events)) {
            $event_id = (int)$events[0]->ID;
        }

        $invites = $event_id ? $this->get_invites_structured($event_id) : [];

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
                    إجمالي المدعوين: <b><?php echo (int)$invites_count; ?></b>
                </div>
            </div>

            <?php if (!$event_id): ?>
                <p>لا توجد مناسبات.</p>
            <?php else: ?>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
                    <a class="button button-secondary"
                        href="<?php echo esc_url($this->admin_export_url((int)$event_id)); ?>">
                        تصدير المدعوين CSV
                    </a>
                </div>

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
            <?php endif; ?>
        </div>
    <?php
    }

    private function admin_export_url(int $event_id): string
    {
        $args = [
            'action'   => 'mon_export_invites_csv',
            'event_id' => (int)$event_id,
            '_wpnonce' => wp_create_nonce('mon_export_invites_csv|' . (int)$event_id),
        ];
        return admin_url('admin-post.php?' . http_build_query($args));
    }

    public function handle_export_invites_csv(): void
    {
        $event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
        $nonce    = isset($_GET['_wpnonce']) ? (string)$_GET['_wpnonce'] : '';

        if ($event_id <= 0 || !wp_verify_nonce($nonce, 'mon_export_invites_csv|' . $event_id)) {
            wp_die('Nonce غير صالح.');
        }
        if (!current_user_can('edit_post', $event_id)) {
            wp_die('غير مسموح.');
        }

        $invites = $this->get_invites_structured($event_id);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="event-' . $event_id . '-invites.csv"');

        echo "\xEF\xBB\xBF"; // BOM

        $out = fopen('php://output', 'w');
        fputcsv($out, ['phone', 'name']);

        foreach ($invites as $phone => $row) {
            fputcsv($out, [$phone, $row['name'] ?? '']);
        }

        fclose($out);
        exit;
    }

    public function render_invites_manager(int $event_id): void
    {
        $event_id = (int)$event_id;

        // ✅ Owner only
        if (!$this->current_user_is_owner($event_id)) return;

        $invites = get_post_meta($event_id, '_mon_invites', true);
        if (!is_array($invites)) $invites = [];

        // Normalize / ensure keys
        $invites_norm = [];
        foreach ($invites as $phone => $row) {
            $p = $this->normalize_phone($phone);
            if (!$p) continue;
            $invites_norm[$p] = [
                'name'   => sanitize_text_field($row['name'] ?? ''),
                'status' => sanitize_text_field($row['status'] ?? 'pending'),
            ];
        }
        ksort($invites_norm);

        $count = count($invites_norm);

        echo '<div class="mon-invites-box" data-mon-invites-manager>';
        echo '  <div class="mon-inv-head">';
        echo '    <div>';
        echo '      <p class="mon-inv-title">إدارة المدعوين</p>';
        echo '      <p class="mon-inv-sub">عدد المدعوين: <b>' . (int)$count . '</b> — إضافة/حذف/تحديث الحالة مباشرة.</p>';
        echo '    </div>';
        echo '  </div>';

        // Add form
        echo '  <div class="mon-inv-form">';
        echo '    <input class="mon-inv-input" type="text" data-add-phone placeholder="رقم الهاتف (05.. أو 966..)" style="min-width:220px;direction:ltr">';
        echo '    <input class="mon-inv-input" type="text" data-add-name  placeholder="الاسم (اختياري)" style="min-width:200px">';
        echo '    <button type="button" class="mon-inv-btn mon-inv-btn--primary" data-add-btn>إضافة</button>';
        echo '  </div>';

        echo '  <div class="mon-inv-ok" data-msg-ok></div>';
        echo '  <div class="mon-inv-err" data-msg-err></div>';

        // Table
        echo '  <table class="mon-inv-table">';
        echo '    <thead><tr>';
        echo '      <th>الاسم</th>';
        echo '      <th>رقم الهاتف</th>';
        echo '      <th>الحالة</th>';
        echo '      <th style="width:110px">إجراءات</th>';
        echo '    </tr></thead>';
        echo '    <tbody>' . $this->invites_rows_html($invites_norm) . '</tbody>';
        echo '  </table>';

        echo '  <p class="mon-inv-note">* التعديلات هنا لا تظهر للمدعوين، فقط لإدارة قائمتك.</p>';
        echo '</div>';
    }

    private function invite_status_label(string $status): string
    {
        return match ($status) {
            'attending' => 'سيحضر',
            'declined'  => 'لن يحضر',
            default     => 'لم يرد',
        };
    }

    private function current_user_is_owner(int $event_id): bool
    {
        if (!is_user_logged_in() || $event_id <= 0) return false;

        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'event') return false;

        return (int) $event->post_author === (int) get_current_user_id();
    }

    public function enqueue_frontend_invites_assets(): void
    {
        if (!is_singular('event')) return;

        $event_id = get_queried_object_id();
        if (!$this->current_user_is_owner((int)$event_id)) return;

        $handle = 'mon-events-invites-fe';

        wp_register_style($handle, false, [], '1.0.0');
        wp_enqueue_style($handle);

        // ✅ Light UI (no dark)
        $css = '
    .mon-invites-box{
      margin-top:12px;
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      padding:12px;
      box-shadow:0 10px 30px rgba(15,23,42,.06);
    }
    .mon-inv-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
    .mon-inv-title{margin:0;font-size:14px;font-weight:900;color:#0f172a}
    .mon-inv-sub{color:#64748b;font-size:12px;margin:0}
    .mon-inv-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}

    .mon-inv-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0}
    .mon-inv-input{
      padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;
      font-size:13px;outline:none;
    }
    .mon-inv-input:focus{border-color:#c7b3d6;box-shadow:0 0 0 4px rgba(36,11,54,.08)}
    .mon-inv-btn{
      padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;
      font-size:13px;font-weight:900;cursor:pointer;
      box-shadow:0 6px 14px rgba(2,6,23,.05);transition:.15s;
    }
    .mon-inv-btn:hover{transform:translateY(-1px);box-shadow:0 10px 20px rgba(2,6,23,.08)}
    .mon-inv-btn--primary{background:#240B36;color:#fff;border-color:#240B36}
    .mon-inv-btn--primary:hover{opacity:.95}

    .mon-inv-table{width:100%;border-collapse:collapse;margin-top:10px}
    .mon-inv-table th,.mon-inv-table td{padding:10px;border-top:1px solid #eef2f7;font-size:13px;text-align:right}
    .mon-inv-table th{color:#64748b;font-weight:900;font-size:12px}
    .mon-inv-phone{direction:ltr;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
    .mon-inv-badge{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;font-size:12px;font-weight:900}
    .mon-inv-badge--att{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
    .mon-inv-badge--dec{background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .mon-inv-badge--pen{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}

    .mon-inv-select{padding:8px 10px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;font-size:13px}
    .mon-inv-del{border:1px solid #fee2e2;background:#fff;color:#991b1b}
    .mon-inv-del:hover{background:#fef2f2}
    .mon-inv-note{color:#64748b;font-size:12px;margin:8px 0 0}
    .mon-inv-err{color:#991b1b;font-size:12px;margin:8px 0 0}
    .mon-inv-ok{color:#166534;font-size:12px;margin:8px 0 0}
    ';
        wp_add_inline_style($handle, $css);

        wp_register_script($handle, false, [], '1.0.0', true);
        wp_enqueue_script($handle);

        $nonce = wp_create_nonce('mon_invites_fe|' . (int)$event_id);

        $js = "window.MON_INVITES_FE = " . wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => $nonce,
            'eventId' => (int)$event_id,
        ]) . ";";

        wp_add_inline_script($handle, $js, 'before');

        // JS behavior
        $js2 = <<<JS
(function(){
  const cfg = window.MON_INVITES_FE;
  if(!cfg) return;

  const root = document.querySelector('[data-mon-invites-manager]');
  if(!root) return;

  const msgOk  = root.querySelector('[data-msg-ok]');
  const msgErr = root.querySelector('[data-msg-err]');

  const phoneInput = root.querySelector('[data-add-phone]');
  const nameInput  = root.querySelector('[data-add-name]');
  const addBtn     = root.querySelector('[data-add-btn]');

  function setMsg(ok, text){
    if(msgOk)  msgOk.textContent  = ok ? (text || '') : '';
    if(msgErr) msgErr.textContent = ok ? '' : (text || '');
  }

  async function post(action, payload){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_ajax_nonce', cfg.nonce);
    fd.append('event_id', String(cfg.eventId));
    Object.keys(payload||{}).forEach(k => fd.append(k, payload[k]));

    const res = await fetch(cfg.ajaxUrl, { method:'POST', credentials:'same-origin', body: fd });
    const json = await res.json().catch(()=>null);
    return json;
  }

  // Add invite
  addBtn && addBtn.addEventListener('click', async function(){
    setMsg(true,'');
    const phone = (phoneInput?.value || '').trim();
    const name  = (nameInput?.value || '').trim();

    if(!phone){ setMsg(false,'أدخل رقم هاتف'); return; }

    addBtn.disabled = true;
    const r = await post('mon_invite_add', { phone, name });
    addBtn.disabled = false;

    if(!r || !r.success){ setMsg(false, (r && r.data && r.data.message) ? r.data.message : 'فشل الإضافة'); return; }

    // refresh row list (simple strategy)
    const tbody = root.querySelector('tbody');
    if(tbody && r.data && r.data.rows_html){
      tbody.innerHTML = r.data.rows_html;
    }
    if(phoneInput) phoneInput.value = '';
    if(nameInput) nameInput.value = '';
    setMsg(true,'تمت الإضافة ✅');
  });

  // Delegate: delete + status
  root.addEventListener('click', async function(e){
    const delBtn = e.target.closest('[data-del]');
    if(!delBtn) return;

    const phone = delBtn.getAttribute('data-phone') || '';
    if(!phone) return;

    if(!confirm('حذف هذا المدعو؟')) return;

    setMsg(true,'');
    delBtn.disabled = true;

    const r = await post('mon_invite_delete', { phone });
    delBtn.disabled = false;

    if(!r || !r.success){ setMsg(false, (r && r.data && r.data.message) ? r.data.message : 'فشل الحذف'); return; }

    const tbody = root.querySelector('tbody');
    if(tbody && r.data && r.data.rows_html){
      tbody.innerHTML = r.data.rows_html;
    }
    setMsg(true,'تم الحذف ✅');
  });

  root.addEventListener('change', async function(e){
    const sel = e.target.closest('[data-status]');
    if(!sel) return;

    const phone = sel.getAttribute('data-phone') || '';
    const status = sel.value || 'pending';
    if(!phone) return;

    setMsg(true,'');
    sel.disabled = true;

    const r = await post('mon_invite_status', { phone, status });
    sel.disabled = false;

    if(!r || !r.success){ setMsg(false, (r && r.data && r.data.message) ? r.data.message : 'فشل تحديث الحالة'); return; }

    const tbody = root.querySelector('tbody');
    if(tbody && r.data && r.data.rows_html){
      tbody.innerHTML = r.data.rows_html;
    }
    setMsg(true,'تم التحديث ✅');
  });
})();
JS;

        wp_add_inline_script($handle, $js2, 'after');
    }

    private function invites_rows_html(array $invites_norm): string
    {
        if (empty($invites_norm)) {
            return '<tr><td colspan="4" style="padding:14px;color:#64748b">لا يوجد مدعوون بعد.</td></tr>';
        }

        $html = '';
        foreach ($invites_norm as $phone => $row) {
            $name   = (string)($row['name'] ?? '');
            $status = (string)($row['status'] ?? 'pending');

            $badgeClass = 'mon-inv-badge--pen';
            if ($status === 'attending') $badgeClass = 'mon-inv-badge--att';
            elseif ($status === 'declined') $badgeClass = 'mon-inv-badge--dec';

            $html .= '<tr>';
            $html .= '<td>' . esc_html($name ?: '—') . '</td>';
            $html .= '<td class="mon-inv-phone">' . esc_html($phone) . '</td>';

            $html .= '<td>';
            $html .= '  <span class="mon-inv-badge ' . esc_attr($badgeClass) . '">' . esc_html($this->invite_status_label($status)) . '</span>';
            $html .= '  <div style="margin-top:6px">';
            $html .= '    <select class="mon-inv-select" data-status data-phone="' . esc_attr($phone) . '">';
            $html .= '      <option value="pending" '   . selected($status, 'pending', false)   . '>لم يرد</option>';
            $html .= '      <option value="attending" ' . selected($status, 'attending', false) . '>سيحضر</option>';
            $html .= '      <option value="declined" '  . selected($status, 'declined', false)  . '>لن يحضر</option>';
            $html .= '    </select>';
            $html .= '  </div>';
            $html .= '</td>';

            $html .= '<td>';
            $html .= '  <button type="button" class="mon-inv-btn mon-inv-del" data-del data-phone="' . esc_attr($phone) . '">حذف</button>';
            $html .= '</td>';

            $html .= '</tr>';
        }

        return $html;
    }

    public function ajax_invite_add(): void
    {
        $event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
        check_ajax_referer('mon_invites_fe|' . $event_id);

        if (!$this->current_user_is_owner($event_id)) {
            wp_send_json_error(['message' => 'غير مسموح.']);
        }

        $phone_raw = sanitize_text_field($_POST['phone'] ?? '');
        $name_raw  = sanitize_text_field($_POST['name'] ?? '');

        $phone = $this->normalize_phone($phone_raw);
        if (!$phone) wp_send_json_error(['message' => 'رقم الهاتف غير صالح.']);

        $invites = get_post_meta($event_id, '_mon_invites', true);
        if (!is_array($invites)) $invites = [];

        // merge / upsert
        $invites[$phone] = [
            'name'   => $name_raw,
            'status' => $invites[$phone]['status'] ?? 'pending',
        ];

        // normalize + save
        $inv_norm = [];
        foreach ($invites as $p => $row) {
            $pp = $this->normalize_phone($p);
            if (!$pp) continue;
            $inv_norm[$pp] = [
                'name'   => sanitize_text_field($row['name'] ?? ''),
                'status' => sanitize_text_field($row['status'] ?? 'pending'),
            ];
        }
        ksort($inv_norm);

        update_post_meta($event_id, '_mon_invites', $inv_norm);
        update_post_meta($event_id, '_mon_invited_phones', implode("\n", array_keys($inv_norm)));

        wp_send_json_success([
            'rows_html' => $this->invites_rows_html($inv_norm),
        ]);
    }

    public function ajax_invite_delete(): void
    {
        $event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
        check_ajax_referer('mon_invites_fe|' . $event_id);

        if (!$this->current_user_is_owner($event_id)) {
            wp_send_json_error(['message' => 'غير مسموح.']);
        }

        $phone_raw = sanitize_text_field($_POST['phone'] ?? '');
        $phone = $this->normalize_phone($phone_raw);
        if (!$phone) wp_send_json_error(['message' => 'رقم غير صالح.']);

        $invites = get_post_meta($event_id, '_mon_invites', true);
        if (!is_array($invites)) $invites = [];

        unset($invites[$phone]);

        // normalize + save
        $inv_norm = [];
        foreach ($invites as $p => $row) {
            $pp = $this->normalize_phone($p);
            if (!$pp) continue;
            $inv_norm[$pp] = [
                'name'   => sanitize_text_field($row['name'] ?? ''),
                'status' => sanitize_text_field($row['status'] ?? 'pending'),
            ];
        }
        ksort($inv_norm);

        update_post_meta($event_id, '_mon_invites', $inv_norm);
        update_post_meta($event_id, '_mon_invited_phones', implode("\n", array_keys($inv_norm)));

        wp_send_json_success([
            'rows_html' => $this->invites_rows_html($inv_norm),
        ]);
    }

    public function ajax_invite_status(): void
    {
        $event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
        check_ajax_referer('mon_invites_fe|' . $event_id);

        if (!$this->current_user_is_owner($event_id)) {
            wp_send_json_error(['message' => 'غير مسموح.']);
        }

        $phone_raw = sanitize_text_field($_POST['phone'] ?? '');
        $status    = sanitize_text_field($_POST['status'] ?? 'pending');

        $allowed = ['pending', 'attending', 'declined'];
        if (!in_array($status, $allowed, true)) $status = 'pending';

        $phone = $this->normalize_phone($phone_raw);
        if (!$phone) wp_send_json_error(['message' => 'رقم غير صالح.']);

        $invites = get_post_meta($event_id, '_mon_invites', true);
        if (!is_array($invites)) $invites = [];

        if (!isset($invites[$phone])) {
            wp_send_json_error(['message' => 'هذا الرقم غير موجود بالقائمة.']);
        }

        $invites[$phone]['status'] = $status;

        // normalize + save
        $inv_norm = [];
        foreach ($invites as $p => $row) {
            $pp = $this->normalize_phone($p);
            if (!$pp) continue;
            $inv_norm[$pp] = [
                'name'   => sanitize_text_field($row['name'] ?? ''),
                'status' => sanitize_text_field($row['status'] ?? 'pending'),
            ];
        }
        ksort($inv_norm);

        update_post_meta($event_id, '_mon_invites', $inv_norm);

        wp_send_json_success([
            'rows_html' => $this->invites_rows_html($inv_norm),
        ]);
    }
}
