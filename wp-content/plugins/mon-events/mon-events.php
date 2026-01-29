<?php

/**
 * Plugin Name: Mon Events (MVP)
 * Description: Custom Events CPT + RSVP (MVP) for KLEO setup.
 * Version: 0.2.1
 */

if (!defined('ABSPATH')) exit;

if ( ! defined( 'MON_EVENTS_PATH' ) ) {
    define( 'MON_EVENTS_PATH', plugin_dir_path( __FILE__ ) );
}

// 1. استدعاء الملفات الأساسية
require_once MON_EVENTS_PATH . 'includes/class-invites.php';
require_once MON_EVENTS_PATH . 'includes/class-rsvp.php';
require_once MON_EVENTS_PATH . 'includes/class-buddypress.php';
require_once MON_EVENTS_PATH . 'includes/class-admin.php';
require_once MON_EVENTS_PATH . 'includes/class-comments-gate.php';
require_once MON_EVENTS_PATH . 'includes/class-gallery.php';
require_once MON_EVENTS_PATH . 'includes/class-mon-packages.php';
require_once MON_EVENTS_PATH . 'includes/class-mon-limits.php';
require_once MON_EVENTS_PATH . 'includes/class-salla-sso.php';

// 2. المحرك الرئيسي للربط مع سلة (Webhook Handler)
require_once MON_EVENTS_PATH . 'includes/class-salla-handler.php';

class Mon_Events_MVP
{
    private $invites;
    private $rsvp;
    private $bp;
    private $admin;
    private $gate;
    private $gallery;
    
    // تم حذف salla_api لأن كلاس Salla_Handler يعمل بشكل مستقل تلقائياً

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

        // Ajax handlers
        add_action('wp_ajax_mon_events_attach_url', function () {
            if (!is_user_logged_in()) wp_send_json_error(['msg' => 'not allowed'], 403);
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            if ($id <= 0) wp_send_json_error(['msg' => 'bad id'], 400);
            $url = wp_get_attachment_image_url($id, 'thumbnail') ?: wp_get_attachment_url($id);
            wp_send_json_success(['url' => $url ?: '']);
        });
    }

    // --- Helpers ---
    public function rsvp(): Mon_Events_RSVP { return $this->rsvp; }
    public function invites(): Mon_Events_Invites { return $this->invites; }

    public function register_cpt_tax()
    {
        register_post_type('event', [
            'labels' => [
                'name' => 'المناسبات',
                'singular_name' => 'مناسبة',
                'add_new' => 'إضافة مناسبة',
                'add_new_item' => 'إضافة مناسبة جديدة',
                'edit_item' => 'تعديل المناسبة',
                'view_item' => 'عرض المناسبة',
            ],
            'public' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => 'events'],
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title', 'editor', 'thumbnail', 'author', 'comments'],
            'show_in_rest' => true,
        ]);

        register_taxonomy('event_type', ['event'], [
            'labels' => ['name' => 'نوع المناسبة'],
            'public' => true,
            'hierarchical' => true, 
            'show_admin_column' => true,
            'show_in_rest' => true,
        ]);
    }

    public function gate_passed($event_id): bool { return $this->gate ? $this->gate->gate_passed((int)$event_id) : false; }
    public function gate_phone($event_id): string { return $this->gate ? $this->gate->gate_phone((int)$event_id) : ''; }

    public function register_frontend(): void
    {
        add_filter('single_template', [$this, 'load_single_event_template'], 99);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 20);
        add_shortcode('mon_event_gate', [$this, 'shortcode_gate']);
        add_action('template_redirect', [$this, 'handle_gate_submit']);
    }

    public function load_single_event_template($template)
    {
        if (is_singular('event')) {
            $custom = MON_EVENTS_PATH . 'templates/single-event.php';
            if (file_exists($custom)) return $custom;
        }
        return $template;
    }

    public function enqueue_assets(): void
    {
        if (!is_singular('event') && !is_post_type_archive('event')) return;
        wp_enqueue_style('mon-events-ui', plugins_url('assets/mon-events.css', __FILE__), [], '0.2.1');
        wp_enqueue_script('mon-events-frontend', plugins_url('assets/mon-events-frontend.js', __FILE__), [], '0.2.1', true);
    }

    public function shortcode_gate(): string
    {
        if (!is_singular('event')) return '';
        $event_id = (int) get_the_ID();
        if (is_user_logged_in() || $this->gate_passed($event_id)) return '<div class="mon-note mon-ok">تم التحقق ✅</div>';

        $err = get_transient('mon_gate_err_' . $event_id);
        if ($err) delete_transient('mon_gate_err_' . $event_id);
        $err_html = $err ? '<div class="mon-note" style="background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;border-radius:12px;padding:10px 12px;">' . esc_html($err) . '</div>' : '';

        return '<div class="mon-gate">' . $err_html . '<p class="mon-muted">أدخل رقم جوالك للتحقق من الدعوة.</p><form method="post" class="mon-gate-form"><input type="text" name="mon_gate_phone" placeholder="05xxxxxxxx" class="mon-input" /><button type="submit" name="mon_gate_submit" value="1" class="mon-btn">تحقق</button></form></div>';
    }

    public function handle_gate_submit(): void
    {
        if (!is_singular('event') || empty($_POST['mon_gate_submit'])) return;
        $event_id = (int) get_the_ID();
        $raw_phone = sanitize_text_field($_POST['mon_gate_phone'] ?? '');
        if ($raw_phone === '') { wp_safe_redirect(get_permalink($event_id)); exit; }

        $phone_norm = $this->invites()->normalize_phone($raw_phone);
        $ok = $this->invites()->is_phone_invited($event_id, $phone_norm);

        if (!$ok) {
            $phone_norm_970 = $this->invites()->normalize_phone($raw_phone, '970');
            $ok = $this->invites()->is_phone_invited($event_id, $phone_norm_970);
            if ($ok) $phone_norm = $phone_norm_970;
        }

        if (!$ok) {
            set_transient('mon_gate_err_' . $event_id, 'هذا الرقم غير موجود ضمن قائمة المدعوين.', 30);
            wp_safe_redirect(get_permalink($event_id));
            exit;
        }

        setcookie('mon_inv_' . $event_id, $this->gate->make_invite_cookie_value($event_id, $phone_norm), [
            'expires' => time() + (DAY_IN_SECONDS * 30),
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        wp_safe_redirect(get_permalink($event_id));
        exit;
    }
}

$GLOBALS['mon_events_mvp_instance'] = new Mon_Events_MVP();

function mon_events_mvp() {
    return $GLOBALS['mon_events_mvp_instance'] ?? null;
}