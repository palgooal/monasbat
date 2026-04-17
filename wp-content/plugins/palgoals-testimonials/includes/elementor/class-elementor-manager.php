<?php

/**
 * Elementor manager.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
    exit;
}

class Palgoals_Testimonials_Elementor_Manager
{

    /**
     * Prevent duplicate registration across Elementor versions.
     *
     * @var array
     */
    protected $registered_widgets = array();

    /**
     * Register Elementor hooks.
     *
     * @return void
     */
    public function register()
    {
        add_action('elementor/elements/categories_registered', array($this, 'register_category'));
        add_action('elementor/widgets/register', array($this, 'register_widget'));
        add_action('elementor/widgets/widgets_registered', array($this, 'register_legacy_widget'));
    }

    /**
     * Add the Palgoals category.
     *
     * @param object $elements_manager Elementor elements manager.
     * @return void
     */
    public function register_category($elements_manager)
    {
        if (! method_exists($elements_manager, 'add_category')) {
            return;
        }

        $elements_manager->add_category(
            'palgoals-elements',
            array(
                'title' => __('Palgoals', 'palgoals-testimonials'),
                'icon'  => 'fa fa-plug',
            )
        );
    }

    /**
     * Register widgets for current Elementor versions.
     *
     * @param object $widgets_manager Elementor widgets manager.
     * @return void
     */
    public function register_widget($widgets_manager)
    {
        if (! class_exists('\Elementor\Widget_Base') || ! method_exists($widgets_manager, 'register')) {
            return;
        }

        foreach ($this->get_widget_definitions() as $class_name => $file) {
            $this->load_widget_class($class_name, $file);

            if (isset($this->registered_widgets[$class_name])) {
                continue;
            }

            $widgets_manager->register(new $class_name());
            $this->registered_widgets[$class_name] = true;
        }
    }

    /**
     * Register widgets for legacy Elementor versions.
     *
     * @return void
     */
    public function register_legacy_widget()
    {
        if (! class_exists('\Elementor\Plugin') || ! class_exists('\Elementor\Widget_Base')) {
            return;
        }

        $plugin = \Elementor\Plugin::instance();

        if (! isset($plugin->widgets_manager) || ! method_exists($plugin->widgets_manager, 'register_widget_type')) {
            return;
        }

        foreach ($this->get_widget_definitions() as $class_name => $file) {
            $this->load_widget_class($class_name, $file);

            if (isset($this->registered_widgets[$class_name])) {
                continue;
            }

            $plugin->widgets_manager->register_widget_type(new $class_name());
            $this->registered_widgets[$class_name] = true;
        }
    }

    /**
     * Return managed widgets.
     *
     * @return array
     */
    protected function get_widget_definitions()
    {
        return array(
            'Palgoals_Testimonials_Widget'                                 => 'elementor/class-widget-testimonials.php',
            'Palgoals\Testimonials\Elementor\Widgets\PG_Testimonials_Grid_Widget' => 'elementor/widgets/class-testimonials-grid-widget.php',
            'Palgoals\Testimonials\Elementor\Widgets\PG_Testimonials_Slider_Widget' => 'elementor/widgets/class-testimonials-slider-widget.php',
            'Palgoals\Testimonials\Elementor\Widgets\PG_Testimonials_Chat_Widget' => 'elementor/widgets/class-testimonials-chat-widget.php',
            'Palgoals\Testimonials\Elementor\Widgets\PG_Testimonial_Single_Widget' => 'elementor/widgets/class-testimonial-single-widget.php',
            'Palgoals_Chat_Screenshots_Widget'                              => 'elementor/class-widget-chat-screenshots.php',
        );
    }

    /**
     * Load widget class file.
     *
     * @param string $class_name Widget class name.
     * @param string $file       Relative file path.
     * @return void
     */
    protected function load_widget_class($class_name, $file)
    {
        if (class_exists($class_name)) {
            return;
        }

        require_once PALGOALS_TESTIMONIALS_PATH . $file;
    }
}
