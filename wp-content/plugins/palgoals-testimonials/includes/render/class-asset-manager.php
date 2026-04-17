<?php

/**
 * Asset manager for frontend rendering.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
    exit;
}

class Palgoals_Testimonials_Asset_Manager
{

    const STYLE_HANDLE        = 'palgoals-testimonials-frontend';
    const LEGACY_STYLE_HANDLE = 'palgoals-testimonials-frontend-legacy';
    const SCRIPT_HANDLE       = 'palgoals-testimonials-frontend';

    /**
     * Register assets.
     *
     * @return void
     */
    public static function register_assets()
    {
        wp_register_style(
            self::STYLE_HANDLE,
            PALGOALS_TESTIMONIALS_URL . 'assets/css/frontend.css',
            array(),
            PALGOALS_TESTIMONIALS_VERSION
        );

        if (file_exists(PALGOALS_TESTIMONIALS_PATH . 'assets/css/frontend-legacy.css')) {
            wp_register_style(
                self::LEGACY_STYLE_HANDLE,
                PALGOALS_TESTIMONIALS_URL . 'assets/css/frontend-legacy.css',
                array(self::STYLE_HANDLE),
                PALGOALS_TESTIMONIALS_VERSION
            );
        }

        wp_register_script(
            self::SCRIPT_HANDLE,
            PALGOALS_TESTIMONIALS_URL . 'assets/js/frontend.js',
            array(),
            PALGOALS_TESTIMONIALS_VERSION,
            true
        );
    }

    /**
     * Return the first registered Swiper style handle.
     *
     * @return string
     */
    public static function get_swiper_style_handle()
    {
        foreach (array('swiper', 'elementor-swiper', 'e-swiper') as $style_handle) {
            if (wp_style_is($style_handle, 'registered')) {
                return $style_handle;
            }
        }

        return '';
    }

    /**
     * Return the first registered Swiper script handle.
     *
     * @return string
     */
    public static function get_swiper_script_handle()
    {
        foreach (array('swiper', 'elementor-swiper', 'e-swiper') as $script_handle) {
            if (wp_script_is($script_handle, 'registered')) {
                return $script_handle;
            }
        }

        return '';
    }

    /**
     * Enqueue Swiper assets when compatible handles are available.
     *
     * @return void
     */
    public static function enqueue_swiper_assets()
    {
        $style_handle  = self::get_swiper_style_handle();
        $script_handle = self::get_swiper_script_handle();

        if ($style_handle) {
            wp_enqueue_style($style_handle);
        }

        if ($script_handle) {
            wp_enqueue_script($script_handle);
        }
    }

    /**
     * Enqueue runtime assets required by Swiper-based widgets.
     *
     * @return void
     */
    public static function enqueue_slider_assets()
    {
        self::register_assets();
        self::enqueue_swiper_assets();
        wp_enqueue_script(self::SCRIPT_HANDLE);
    }

    /**
     * Enqueue frontend styles.
     *
     * @return void
     */
    public static function enqueue_base_styles()
    {
        self::register_assets();
        wp_enqueue_style(self::STYLE_HANDLE);

        if (wp_style_is(self::LEGACY_STYLE_HANDLE, 'registered')) {
            wp_enqueue_style(self::LEGACY_STYLE_HANDLE);
        }
    }
}
