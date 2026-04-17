<?php

/**
 * Query layer for testimonial data.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
    exit;
}

class Palgoals_Testimonials_Query
{

    /**
     * Query and prepare testimonials.
     *
     * @param array $settings Query settings.
     * @return array
     */
    public static function get_testimonials($settings = array())
    {
        $query_args = self::build_testimonial_query_args($settings);
        $post_ids   = self::get_cached_post_ids('testimonials', $query_args);

        if (empty($post_ids)) {
            return array();
        }

        update_meta_cache('post', $post_ids);

        $items = array();
        foreach ($post_ids as $post_id) {
            $items[] = self::prepare_testimonial($post_id);
        }

        return $items;
    }

    /**
     * Query and prepare screenshot items.
     *
     * @param array $settings Query settings.
     * @return array
     */
    public static function get_screenshots($settings = array())
    {
        $query_args = self::build_screenshot_query_args($settings);
        $post_ids   = self::get_cached_post_ids('screenshots', $query_args);

        if (empty($post_ids)) {
            return array();
        }

        update_meta_cache('post', $post_ids);

        $items = array();
        foreach ($post_ids as $post_id) {
            $items[] = self::prepare_screenshot($post_id);
        }

        return $items;
    }

    /**
     * Build testimonial query args.
     *
     * @param array $settings Raw settings.
     * @return array
     */
    public static function build_testimonial_query_args($settings = array())
    {
        $settings = self::normalize_testimonial_settings($settings);
        $args     = self::build_base_query_args($settings['posts_per_page'], $settings['order']);

        if ($settings['rating'] > 0) {
            $args['meta_query'][] = array(
                'key'     => Palgoals_Testimonials_CPT::META_RATING,
                'value'   => $settings['rating'],
                'type'    => 'NUMERIC',
                'compare' => '>=',
            );
        }

        if ($settings['has_photo']) {
            $args['meta_query'][] = array(
                'key'     => Palgoals_Testimonials_CPT::META_PHOTO_ID,
                'value'   => 0,
                'type'    => 'NUMERIC',
                'compare' => '>',
            );
        }

        if ('manual' === $settings['source']) {
            $args['post__in'] = empty($settings['include_ids']) ? array(0) : $settings['include_ids'];
            $args['orderby']  = 'post__in';
            $args['order']    = 'ASC';

            return $args;
        }

        $args['orderby'] = $settings['orderby'];

        if ('category' === $settings['source'] && ! empty($settings['categories'])) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => Palgoals_Testimonials_CPT::CATEGORY_TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $settings['categories'],
                ),
            );
        }

        return $args;
    }

    /**
     * Build screenshot query args.
     *
     * @param array $settings Raw settings.
     * @return array
     */
    public static function build_screenshot_query_args($settings = array())
    {
        $settings = self::normalize_screenshot_settings($settings);

        return self::build_testimonial_query_args(
            array(
                'source'         => 'latest',
                'posts_per_page' => $settings['limit'],
                'order'          => $settings['order'],
                'orderby'        => 'date',
                'has_photo'      => true,
            )
        );
    }

    /**
     * Prepare testimonial payload.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    public static function prepare_testimonial($post_id)
    {
        $name        = get_the_title($post_id);
        $photo_id    = absint(get_post_meta($post_id, Palgoals_Testimonials_CPT::META_PHOTO_ID, true));
        $raw_rating  = get_post_meta($post_id, Palgoals_Testimonials_CPT::META_RATING, true);
        $rating      = '' === $raw_rating ? 5 : Palgoals_Testimonials_CPT::sanitize_rating($raw_rating);
        $website_url = esc_url(get_post_meta($post_id, Palgoals_Testimonials_CPT::META_WEBSITE_URL, true));

        return array(
            'id'       => $post_id,
            'name'     => $name,
            'initials' => self::get_initials($name),
            'position' => get_post_meta($post_id, Palgoals_Testimonials_CPT::META_POSITION, true),
            'company'  => get_post_meta($post_id, Palgoals_Testimonials_CPT::META_COMPANY, true),
            'photo_id' => $photo_id,
            'rating'   => $rating,
            'content'  => get_post_field('post_content', $post_id),
            'website'  => $website_url,
        );
    }

    /**
     * Prepare screenshot payload.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    public static function prepare_screenshot($post_id)
    {
        $photo_id = absint(get_post_meta($post_id, Palgoals_Testimonials_CPT::META_PHOTO_ID, true));
        $caption  = trim((string) get_post_field('post_content', $post_id));

        if (self::is_placeholder_caption($caption)) {
            $caption = '';
        }

        return array(
            'id'        => $post_id,
            'title'     => get_the_title($post_id),
            'photo_id'  => $photo_id,
            'image_url' => $photo_id ? wp_get_attachment_image_url($photo_id, 'full') : '',
            'caption'   => $caption,
        );
    }

    /**
     * Build initials safely for multibyte names.
     *
     * @param string $name Full name.
     * @return string
     */
    public static function get_initials($name)
    {
        $parts = preg_split('/[\s\p{Z}]+/u', trim((string) $name));
        $parts = array_filter($parts);
        $parts = array_slice($parts, 0, 2);

        if (empty($parts)) {
            return 'C';
        }

        $initials = '';
        foreach ($parts as $part) {
            $character = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
            $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($character, 'UTF-8') : strtoupper($character);
        }

        return $initials;
    }

    /**
     * Normalize testimonial query settings.
     *
     * @param array $settings Raw settings.
     * @return array
     */
    protected static function normalize_testimonial_settings($settings)
    {
        $settings = wp_parse_args(
            $settings,
            array(
                'source'         => 'latest',
                'posts_per_page' => 0,
                'limit'          => 6,
                'order'          => 'DESC',
                'orderby'        => 'date',
                'include_ids'    => array(),
                'categories'     => array(),
                'rating'         => 0,
                'has_photo'      => false,
            )
        );

        $settings['source']         = in_array($settings['source'], array('manual', 'latest', 'category'), true) ? $settings['source'] : 'latest';
        $settings['posts_per_page'] = max(1, absint($settings['posts_per_page'] ? $settings['posts_per_page'] : $settings['limit']));
        $settings['order']          = 'ASC' === strtoupper($settings['order']) ? 'ASC' : 'DESC';
        $settings['orderby']        = in_array($settings['orderby'], array('date', 'title', 'rand'), true) ? $settings['orderby'] : 'date';
        $settings['rating']         = max(0, min(5, absint($settings['rating'])));
        $settings['has_photo']      = ! empty($settings['has_photo']);
        $settings['include_ids']    = self::parse_post_ids($settings['include_ids']);
        $settings['categories']     = self::parse_post_ids($settings['categories']);

        return $settings;
    }

    /**
     * Normalize screenshot query settings.
     *
     * @param array $settings Raw settings.
     * @return array
     */
    protected static function normalize_screenshot_settings($settings)
    {
        $settings = wp_parse_args(
            $settings,
            array(
                'limit' => 6,
                'order' => 'DESC',
            )
        );

        $settings['limit'] = max(1, absint($settings['limit']));
        $settings['order'] = 'ASC' === strtoupper($settings['order']) ? 'ASC' : 'DESC';

        return $settings;
    }

    /**
     * Build base query args for testimonial content.
     *
     * @param int    $posts_per_page Posts per page.
     * @param string $order          Query order.
     * @return array
     */
    protected static function build_base_query_args($posts_per_page, $order)
    {
        return array(
            'post_type'              => Palgoals_Testimonials_CPT::POST_TYPE,
            'post_status'            => 'publish',
            'posts_per_page'         => $posts_per_page,
            'orderby'                => 'date',
            'order'                  => $order,
            'fields'                 => 'ids',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array(
                    'key'     => Palgoals_Testimonials_CPT::META_STATUS,
                    'value'   => Palgoals_Testimonials_CPT::STATUS_ACTIVE,
                    'compare' => '=',
                ),
            ),
        );
    }

    /**
     * Query and cache post IDs.
     *
     * @param string $cache_prefix Cache key prefix.
     * @param array  $query_args   Query arguments.
     * @return array
     */
    protected static function get_cached_post_ids($cache_prefix, $query_args)
    {
        $cache_key = 'palgoals_' . $cache_prefix . '_' . md5(wp_json_encode($query_args) . '|' . Palgoals_Testimonials_CPT::get_cache_version());
        $post_ids  = wp_cache_get($cache_key, 'palgoals_testimonials');

        if (false === $post_ids) {
            $query    = new WP_Query($query_args);
            $post_ids = $query->posts;
            wp_cache_set($cache_key, $post_ids, 'palgoals_testimonials', HOUR_IN_SECONDS);
        }

        return is_array($post_ids) ? $post_ids : array();
    }

    /**
     * Parse post or term IDs from a string or array.
     *
     * @param mixed $value Raw value.
     * @return array
     */
    protected static function parse_post_ids($value)
    {
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value);
        }

        $ids = array_map('absint', (array) $value);
        $ids = array_filter($ids);

        return array_values(array_unique($ids));
    }

    /**
     * Detect placeholder caption content.
     *
     * @param string $caption Raw caption.
     * @return bool
     */
    protected static function is_placeholder_caption($caption)
    {
        $caption      = trim(wp_strip_all_tags($caption));
        $placeholders = array(
            'Write the testimonial content here...',
            __('Write the testimonial content here...', 'palgoals-testimonials'),
        );

        return in_array($caption, $placeholders, true);
    }
}
