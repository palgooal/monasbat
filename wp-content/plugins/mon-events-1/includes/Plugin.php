<?php

namespace MonEvents;

if (!defined('ABSPATH')) exit;

require_once MON_EVENTS_PATH . 'includes/Helpers.php';

// Services
require_once MON_EVENTS_PATH . 'includes/Services/CptService.php';
require_once MON_EVENTS_PATH . 'includes/Services/MetaBoxesService.php';
require_once MON_EVENTS_PATH . 'includes/Services/GateService.php';
require_once MON_EVENTS_PATH . 'includes/Services/RsvpService.php';
require_once MON_EVENTS_PATH . 'includes/Services/CommentsService.php';
require_once MON_EVENTS_PATH . 'includes/Services/BuddyPressService.php';

// Admin
require_once MON_EVENTS_PATH . 'includes/Admin/AdminMenu.php';
require_once MON_EVENTS_PATH . 'includes/Admin/InvitesPage.php';
require_once MON_EVENTS_PATH . 'includes/Admin/Exports.php';

class Plugin
{
    private static ?Plugin $instance = null;

    /** Meta Keys / Constants */
    public const RSVP_META_KEY = '_mon_rsvps';

    public static function instance(): Plugin
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function boot(): void
    {
        // Instantiate helpers/services
        $helpers  = new Helpers();

        $gate     = new Services\GateService($helpers);
        $cpt      = new Services\CptService();
        $metabox  = new Services\MetaBoxesService($helpers);
        $rsvp     = new Services\RsvpService($gate);
        $comments = new Services\CommentsService($gate);
        $bp       = new Services\BuddyPressService();

        $invitesPage = new Admin\InvitesPage($helpers);
        $exports     = new Admin\Exports($helpers);
        $adminMenu   = new Admin\AdminMenu($invitesPage, $exports);

        // Register hooks
        $cpt->register();
        $metabox->register();
        $rsvp->register();
        $comments->register();
        $bp->register();
        $adminMenu->register();

        // Assets (اختياري الآن)
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'public_assets']);
    }

    public function admin_assets(): void
    {
        // لاحقًا لو بدك CSS/JS
        // wp_enqueue_style('mon-events-admin', MON_EVENTS_URL . 'assets/css/admin.css', [], MON_EVENTS_VERSION);
    }

    public function public_assets(): void
    {
        // wp_enqueue_style('mon-events-public', MON_EVENTS_URL . 'assets/css/public.css', [], MON_EVENTS_VERSION);
    }
}
