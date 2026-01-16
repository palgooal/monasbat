<?php

/**
 * Plugin Name: Mon Events (MVP)
 * Description: Custom Events CPT + RSVP (MVP) for KLEO setup.
 * Version: 0.2.0
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-invites.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-rsvp.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-buddypress.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-comments-gate.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-gallery.php';


class Mon_Events_MVP
{
    /** @var Mon_Events_Invites */
    private $invites;

    /** @var Mon_Events_RSVP */
    private $rsvp;

    /** @var Mon_Events_BuddyPress */
    private $bp;

    /** @var Mon_Events_Admin */
    private $admin;

    /** @var Mon_Events_Comments_Gate */
    private $gate;
    /** @var Mon_Events_Gallery */
    private $gallery;


    public function __construct()
    {
        // Modules
        $this->invites = new Mon_Events_Invites($this);
        $this->invites->register();

        $this->rsvp = new Mon_Events_RSVP($this);
        $this->rsvp->register();

        $this->bp = new Mon_Events_BuddyPress($this);
        $this->bp->register();

        $this->admin = new Mon_Events_Admin($this);
        $this->admin->register();

        $this->gate = new Mon_Events_Comments_Gate($this);
        $this->gate->register();
        $this->register_frontend();
        $this->gallery = new Mon_Events_Gallery($this);
        $this->gallery->register();


        // CPT + Tax
        add_action('init', [$this, 'register_cpt_tax'], 0);

        add_action('wp_ajax_mon_events_attach_url', function () {
            if (!is_user_logged_in()) wp_send_json_error(['msg' => 'not allowed'], 403);

            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            if ($id <= 0) wp_send_json_error(['msg' => 'bad id'], 400);

            $url = wp_get_attachment_image_url($id, 'thumbnail');
            if (!$url) $url = wp_get_attachment_url($id);

            wp_send_json_success(['url' => $url ?: '']);
        });
    }

    // Keep old calls working (only one gate source)
    public function mon_gate_passed($event_id): bool
    {
        return $this->gate ? $this->gate->gate_passed((int)$event_id) : false;
    }

    public function mon_gate_phone($event_id): string
    {
        return $this->gate ? $this->gate->gate_phone((int)$event_id) : '';
    }

    public function mon_make_invite_cookie_value($event_id, $phone_norm): string
    {
        return $this->gate ? $this->gate->make_invite_cookie_value((int)$event_id, (string)$phone_norm) : '';
    }

    public function mon_verify_invite_cookie_value($cookie_value): array
    {
        return $this->gate ? $this->gate->verify_invite_cookie_value((string)$cookie_value) : [false, 0, ''];
    }

    public function rsvp(): Mon_Events_RSVP
    {
        return $this->rsvp;
    }

    public function invites(): Mon_Events_Invites
    {
        return $this->invites;
    }

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
                'add_new_item' => 'إضافة نوع مناسبة',
                'edit_item' => 'تعديل نوع المناسبة',
            ],
            'public' => true,
            'hierarchical' => true, // مثل الوسوم
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'meta_box_cb' => 'post_tags_meta_box', // ⭐ مهم جدًا
            'rewrite' => ['slug' => 'event-type'],
        ]);
    }
    public function gate_passed($event_id): bool
    {
        return $this->gate ? $this->gate->gate_passed((int)$event_id) : false;
    }

    public function gate_phone($event_id): string
    {
        return $this->gate ? $this->gate->gate_phone((int)$event_id) : '';
    }

    // داخل class Mon_Events_MVP

    public function register_frontend(): void
    {
        // Template override for single event
        add_filter('single_template', [$this, 'load_single_event_template'], 99);

        // Enqueue CSS only for event pages
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 20);

        // Gate shortcode
        add_shortcode('mon_event_gate', [$this, 'shortcode_gate']);
        add_action('template_redirect', [$this, 'handle_gate_submit']);
    }

    public function load_single_event_template($template)
    {
        if (is_singular('event')) {
            $custom = plugin_dir_path(__FILE__) . 'templates/single-event.php';
            if (file_exists($custom)) return $custom;
        }
        return $template;
    }

    public function enqueue_assets(): void
    {
        if (!is_singular('event') && !is_post_type_archive('event')) return;

        wp_enqueue_style(
            'mon-events-ui',
            plugins_url('assets/mon-events.css', __FILE__),
            [],
            '0.2.0'
        );

        // wp_enqueue_script(
        //     'mon-events-ui',
        //     plugins_url('assets/mon-events.js', __FILE__),
        //     [],
        //     '0.2.0',
        //     true
        // );
        wp_enqueue_script(
            'mon-events-frontend',
            plugins_url('assets/mon-events-frontend.js', __FILE__),
            [],
            '0.2.0',
            true
        );
    }


    public function shortcode_gate(): string
    {
        if (!is_singular('event')) return '';
        $event_id = (int) get_the_ID();

        if (is_user_logged_in() || $this->gate_passed($event_id)) {
            return '<div class="mon-note mon-ok">تم التحقق ✅</div>';
        }

        $err = get_transient('mon_gate_err_' . $event_id);
        if ($err) delete_transient('mon_gate_err_' . $event_id);

        $err_html = $err ? '<div class="mon-note" style="background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;border-radius:12px;padding:10px 12px;">'
            . esc_html($err) . '</div>' : '';

        return '
    <div class="mon-gate">
      ' . $err_html . '
      <p class="mon-muted">أدخل رقم جوالك للتحقق من الدعوة.</p>
      <form method="post" class="mon-gate-form">
        <input type="text" name="mon_gate_phone" placeholder="05xxxxxxxx" class="mon-input" />
        <button type="submit" name="mon_gate_submit" value="1" class="mon-btn">تحقق</button>
      </form>
    </div>';
    }

    public function handle_gate_submit(): void
    {
        if (!is_singular('event')) return;
        if (empty($_POST['mon_gate_submit'])) return;

        $event_id = (int) get_the_ID();
        if ($event_id <= 0) return;

        // رقم الجوال
        $raw_phone = sanitize_text_field($_POST['mon_gate_phone'] ?? '');
        if ($raw_phone === '') {
            wp_safe_redirect(get_permalink($event_id));
            exit;
        }

        // ✅ طَبّع الرقم بنفس منطق الدعوات
        $phone_norm = $this->invites()->normalize_phone($raw_phone);

        // (اختياري لكن مهم لو عندك فلسطين + السعودية)
        // جرّب 970 أيضا لو 966 ما زبط
        $ok = $this->invites()->is_phone_invited($event_id, $phone_norm);

        if (!$ok) {
            $phone_norm_970 = $this->invites()->normalize_phone($raw_phone, '970');
            $ok = $this->invites()->is_phone_invited($event_id, $phone_norm_970);
            if ($ok) $phone_norm = $phone_norm_970;
        }

        // ❌ غير مدعو
        if (!$ok) {
            // خزن رسالة بسيطة في transient (أو اعرضها مباشرة لاحقاً)
            set_transient('mon_gate_err_' . $event_id, 'هذا الرقم غير موجود ضمن قائمة المدعوين.', 30);
            wp_safe_redirect(get_permalink($event_id));
            exit;
        }

        // ✅ مدعو: ضع Cookie موقّع
        $cookie_name  = 'mon_inv_' . $event_id;
        $cookie_value = $this->mon_make_invite_cookie_value($event_id, $phone_norm);

        $secure = is_ssl();
        $expire = time() + (DAY_IN_SECONDS * 30);

        // PHP 7.3+ : SameSite
        setcookie($cookie_name, $cookie_value, [
            'expires'  => $expire,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        wp_safe_redirect(get_permalink($event_id));
        exit;
    }
}

$GLOBALS['mon_events_mvp_instance'] = new Mon_Events_MVP();

function mon_events_mvp(): ?Mon_Events_MVP
{
    return $GLOBALS['mon_events_mvp_instance'] instanceof Mon_Events_MVP
        ? $GLOBALS['mon_events_mvp_instance']
        : null;
}


