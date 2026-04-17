<?php

/**
 * Core plugin bootstrap.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
    exit;
}

class Palgoals_Testimonials_Plugin
{

    /**
     * CPT compatibility facade.
     *
     * @var Palgoals_Testimonials_CPT
     */
    protected $cpt;

    /**
     * Admin manager.
     *
     * @var Palgoals_Testimonials_Admin
     */
    protected $admin;

    /**
     * Shortcode manager.
     *
     * @var Palgoals_Testimonials_Shortcode
     */
    protected $shortcode;

    /**
     * Elementor manager.
     *
     * @var Palgoals_Testimonials_Elementor
     */
    protected $elementor;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->cpt       = new Palgoals_Testimonials_CPT();
        $this->admin     = new Palgoals_Testimonials_Admin();
        $this->shortcode = new Palgoals_Testimonials_Shortcode();
        $this->elementor = new Palgoals_Testimonials_Elementor();
    }

    /**
     * Register plugin services.
     *
     * @return void
     */
    public function register()
    {
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        $this->cpt->register();
        $this->admin->register();
        $this->shortcode->register();
        $this->elementor->register();

        Palgoals_Testimonials_Renderer::register();
    }

    /**
     * Load plugin translations.
     *
     * @return void
     */
    public function load_textdomain()
    {
        load_plugin_textdomain(
            'palgoals-testimonials',
            false,
            dirname(plugin_basename(PALGOALS_TESTIMONIALS_FILE)) . '/languages'
        );
    }

    /**
     * Activation callback.
     *
     * @return void
     */
    public static function activate()
    {
        $cpt = new Palgoals_Testimonials_CPT();
        $cpt->register_post_type();
        $cpt->register_taxonomy();
        $cpt->register_post_meta();
        flush_rewrite_rules();
    }

    /**
     * Deactivation callback.
     *
     * @return void
     */
    public static function deactivate()
    {
        flush_rewrite_rules();
    }
}
