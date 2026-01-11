<?php
// includes/class-rsvp.php

if (!defined('ABSPATH')) exit;

class Mon_Events_RSVP
{
    /** @var Mon_Events_MVP */
    private $plugin;

    /** Meta key for storing RSVP map */
    const RSVP_META_KEY = '_mon_rsvps';

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function register(): void
    {
        // RSVP shortcode
        add_shortcode('mon_event_rsvp', [$this, 'shortcode_rsvp']);

        // Prevent RSVP meta from going into revisions
        add_filter('wp_post_revision_meta_keys', [$this, 'exclude_rsvp_from_revisions']);

        // Admin export RSVP CSV
        add_action('admin_post_mon_export_rsvps_csv', [$this, 'handle_export_rsvps_csv']);
    }

    /* --------------------------------------------------------------------------
     * Key helpers
     * -------------------------------------------------------------------------- */

    public function key_for_user(int $user_id): string
    {
        return 'u:' . (int)$user_id;
    }

    public function key_for_phone(string $phone_norm): string
    {
        $phone_norm = preg_replace('/\D+/', '', (string)$phone_norm);
        return 'p:' . $phone_norm;
    }

    /**
     * Get RSVP map for event
     * Returns array: [ rsvp_key => ['status'=>..., 'updated_at'=>..., 'type'=>..., 'phone'=>..., 'user_id'=>...] ]
     */
    public function get_rsvps(int $event_id): array
    {
        $rsvps = get_post_meta($event_id, self::RSVP_META_KEY, true);
        return is_array($rsvps) ? $rsvps : [];
    }

    public function set_rsvp(int $event_id, string $rsvp_key, array $payload): void
    {
        $rsvps = $this->get_rsvps($event_id);
        $rsvps[$rsvp_key] = $payload;
        update_post_meta($event_id, self::RSVP_META_KEY, $rsvps);
    }

    public function get_status_for_key(int $event_id, string $rsvp_key): string
    {
        $rsvps = $this->get_rsvps($event_id);
        return (string)($rsvps[$rsvp_key]['status'] ?? '');
    }

    /* --------------------------------------------------------------------------
     * Shortcode
     * -------------------------------------------------------------------------- */

    public function shortcode_rsvp($atts): string
    {
        if (!is_singular('event')) return '';

        $event_id = (int) get_the_ID();

        // ✅ Allow if: logged in OR passed gate
        $gate_ok    = $this->plugin->gate_passed($event_id);
        $gate_phone = $this->plugin->gate_phone($event_id);

        if (!is_user_logged_in() && !$gate_ok) {
            return '<div class="mon-rsvp-box">الرجاء إدخال رقم الدعوة أولاً لفتح RSVP.</div>';
        }

        // Identify RSVP key
        $rsvp_key = is_user_logged_in()
            ? $this->key_for_user(get_current_user_id())
            : $this->key_for_phone($gate_phone);

        // Handle postback
        if (isset($_POST['mon_rsvp_submit'], $_POST['mon_rsvp_nonce']) && wp_verify_nonce($_POST['mon_rsvp_nonce'], 'mon_rsvp')) {

            if (!is_user_logged_in() && !$this->plugin->gate_passed($event_id)) {
                return '<div class="mon-rsvp-box">لا يمكنك تأكيد الحضور قبل اجتياز التحقق.</div>';
            }

            $status = sanitize_text_field($_POST['mon_rsvp_status'] ?? '');
            if (!in_array($status, ['attending', 'declined'], true)) $status = 'declined';

            $payload = [
                'status'     => $status,
                'updated_at' => current_time('mysql'),
                'type'       => is_user_logged_in() ? 'user' : 'phone',
                'phone'      => is_user_logged_in() ? '' : preg_replace('/\D+/', '', (string)$gate_phone),
                'user_id'    => is_user_logged_in() ? (int)get_current_user_id() : 0,
            ];

            $this->set_rsvp($event_id, $rsvp_key, $payload);
        }

        $mine = $this->get_status_for_key($event_id, $rsvp_key);

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
        return (string)ob_get_clean();
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
     * Admin Export RSVP CSV
     * -------------------------------------------------------------------------- */

    private function admin_export_url(int $event_id): string
    {
        $args = [
            'action'   => 'mon_export_rsvps_csv',
            'event_id' => (int)$event_id,
            '_wpnonce' => wp_create_nonce('mon_export_rsvps_csv|' . (int)$event_id),
        ];
        return admin_url('admin-post.php?' . http_build_query($args));
    }

    /**
     * Export RSVP CSV:
     * key,type,user_id,user_name,phone,status,updated_at
     */
    public function handle_export_rsvps_csv(): void
    {
        $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        $nonce    = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';

        if ($event_id <= 0 || !wp_verify_nonce($nonce, 'mon_export_rsvps_csv|' . $event_id)) {
            wp_die('Nonce غير صالح.');
        }
        if (!current_user_can('edit_post', $event_id)) {
            wp_die('غير مسموح.');
        }

        $rsvps = $this->get_rsvps($event_id);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="event-' . $event_id . '-rsvps.csv"');

        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, ['key', 'type', 'user_id', 'user_name', 'phone', 'status', 'updated_at']);

        foreach ($rsvps as $key => $row) {
            $type = (string)($row['type'] ?? '');
            $uid  = (int)($row['user_id'] ?? 0);
            $phone = (string)($row['phone'] ?? '');
            $status = (string)($row['status'] ?? '');
            $updated = (string)($row['updated_at'] ?? '');

            $user_name = '';
            if ($uid > 0) {
                $user = get_user_by('id', $uid);
                $user_name = $user ? $user->display_name : '';
            }

            fputcsv($out, [$key, $type, $uid, $user_name, $phone, $status, $updated]);
        }

        fclose($out);
        exit;
    }

    /* --------------------------------------------------------------------------
     * BuddyPress helper: list events by user RSVP
     * -------------------------------------------------------------------------- */

    /**
     * Return:
     * [
     *   'attending' => [ ['post'=>WP_Post, 'date'=>...], ... ],
     *   'declined'  => ...
     * ]
     */
    public function get_events_by_user_rsvp(int $user_id): array
    {
        $attending = [];
        $declined  = [];

        $key = $this->key_for_user($user_id);

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
            $rsvps = $this->get_rsvps((int)$ev->ID);
            if (!isset($rsvps[$key])) continue;

            $status = (string)($rsvps[$key]['status'] ?? '');
            $date   = (string)get_post_meta($ev->ID, '_mon_event_date', true);

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
}
