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

        // CPT + Tax
        add_action('init', [$this, 'register_cpt_tax'], 0);
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

}

new Mon_Events_MVP();
