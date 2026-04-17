<?php

/**
 * Plugin Name: Palgoals Testimonials Manager
 * Plugin URI: https://palgoals.com/
 * Description: Manage customer testimonials from the WordPress dashboard and display them through Elementor widgets or shortcodes.
 * Version: 1.0.0
 * Author: Palgoals Information Technology
 * Author URI: https://palgoals.com/
 * Text Domain: palgoals-testimonials
 * Domain Path: /languages
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
	exit;
}

define('PALGOALS_TESTIMONIALS_VERSION', '1.0.0');
define('PALGOALS_TESTIMONIALS_FILE', __FILE__);
define('PALGOALS_TESTIMONIALS_PATH', plugin_dir_path(__FILE__));
define('PALGOALS_TESTIMONIALS_URL', plugin_dir_url(__FILE__));

require_once PALGOALS_TESTIMONIALS_PATH . 'includes/post-types/class-testimonials-post-type.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/taxonomies/class-testimonials-category-taxonomy.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/render/class-asset-manager.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/render/class-testimonial-card-renderer.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/compatibility/class-legacy-template-loader.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/elementor/class-elementor-manager.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/class-cpt.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/query/class-testimonials-query.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/class-renderer.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/class-screenshots-renderer.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/class-admin.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/class-shortcode.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/class-elementor.php';
require_once PALGOALS_TESTIMONIALS_PATH . 'includes/class-plugin.php';

final class Palgoals_Testimonials_Manager
{

	/**
	 * Singleton instance.
	 *
	 * @var Palgoals_Testimonials_Manager|null
	 */
	private static $instance = null;

	/**
	 * Core plugin bootstrap.
	 *
	 * @var Palgoals_Testimonials_Plugin
	 */
	private $plugin;

	/**
	 * Return the singleton instance.
	 *
	 * @return Palgoals_Testimonials_Manager
	 */
	public static function instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire plugin services.
	 */
	private function __construct()
	{
		$this->plugin = new Palgoals_Testimonials_Plugin();
		$this->plugin->register();

		add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain()
	{
		if ($this->plugin instanceof Palgoals_Testimonials_Plugin) {
			$this->plugin->load_textdomain();
		}
	}

	/**
	 * Add branded plugin meta.
	 *
	 * @param string[] $links Existing links.
	 * @param string   $file  Plugin file.
	 * @return string[]
	 */
	public function plugin_row_meta($links, $file)
	{
		if (plugin_basename(PALGOALS_TESTIMONIALS_FILE) !== $file) {
			return $links;
		}

		$links[] = '<span>' . esc_html__('Developed by Palgoals Information Technology', 'palgoals-testimonials') . '</span>';

		return $links;
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate()
	{
		Palgoals_Testimonials_Plugin::activate();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate()
	{
		Palgoals_Testimonials_Plugin::deactivate();
	}
}

register_activation_hook(PALGOALS_TESTIMONIALS_FILE, array('Palgoals_Testimonials_Manager', 'activate'));
register_deactivation_hook(PALGOALS_TESTIMONIALS_FILE, array('Palgoals_Testimonials_Manager', 'deactivate'));

/**
 * Bootstrap the plugin.
 *
 * @return Palgoals_Testimonials_Manager
 */
function palgoals_testimonials_manager()
{
	return Palgoals_Testimonials_Manager::instance();
}

palgoals_testimonials_manager();
