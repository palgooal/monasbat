<?php

/**
 * Compatibility template loader.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
    exit;
}

class Palgoals_Testimonials_Legacy_Template_Loader
{

    /**
     * Locate a legacy template with fallback to the original path.
     *
     * @param string $template_name Template filename.
     * @return string
     */
    public static function locate($template_name)
    {
        $candidates = array(
            PALGOALS_TESTIMONIALS_PATH . 'templates/legacy/' . $template_name,
            PALGOALS_TESTIMONIALS_PATH . 'templates/' . $template_name,
        );

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
