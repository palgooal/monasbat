<?php

/**
 * Plugin Name: Mon Events (MVP)
 * Description: Custom Events CPT + RSVP (MVP) for KLEO setup.
 * Version: 0.2.0
 */

if (!defined('ABSPATH')) exit;

define('MON_EVENTS_PATH', plugin_dir_path(__FILE__));
define('MON_EVENTS_URL', plugin_dir_url(__FILE__));
define('MON_EVENTS_VERSION', '0.2.0');

require_once MON_EVENTS_PATH . 'includes/Plugin.php';

add_action('plugins_loaded', function () {
    \MonEvents\Plugin::instance()->boot();
});
