<?php

/**
 * Testimonial post type service.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
    exit;
}

class Palgoals_Testimonials_Post_Type
{

    /**
     * Post type key.
     *
     * @var string
     */
    protected $post_type;

    /**
     * Related taxonomy key.
     *
     * @var string
     */
    protected $taxonomy;

    /**
     * Constructor.
     *
     * @param string $post_type Post type key.
     * @param string $taxonomy  Related taxonomy key.
     */
    public function __construct($post_type, $taxonomy)
    {
        $this->post_type = $post_type;
        $this->taxonomy  = $taxonomy;
    }

    /**
     * Register the post type.
     *
     * @return void
     */
    public function register()
    {
        register_post_type($this->post_type, $this->get_args());
    }

    /**
     * Build post type args.
     *
     * @return array
     */
    protected function get_args()
    {
        return array(
            'labels'             => $this->get_labels(),
            'public'             => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'has_archive'        => true,
            'menu_icon'          => 'dashicons-format-quote',
            'rewrite'            => array('slug' => 'testimonials'),
            'supports'           => array('title', 'editor'),
            'taxonomies'         => array($this->taxonomy),
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'publicly_queryable' => true,
        );
    }

    /**
     * Build post type labels.
     *
     * @return array
     */
    protected function get_labels()
    {
        return array(
            'name'                  => __('Testimonials', 'palgoals-testimonials'),
            'singular_name'         => __('Testimonial', 'palgoals-testimonials'),
            'menu_name'             => __('Testimonials', 'palgoals-testimonials'),
            'name_admin_bar'        => __('Testimonial', 'palgoals-testimonials'),
            'add_new'               => __('Add New', 'palgoals-testimonials'),
            'add_new_item'          => __('Add New Testimonial', 'palgoals-testimonials'),
            'edit_item'             => __('Edit Testimonial', 'palgoals-testimonials'),
            'new_item'              => __('New Testimonial', 'palgoals-testimonials'),
            'view_item'             => __('View Testimonial', 'palgoals-testimonials'),
            'search_items'          => __('Search Testimonials', 'palgoals-testimonials'),
            'not_found'             => __('No testimonials found.', 'palgoals-testimonials'),
            'not_found_in_trash'    => __('No testimonials found in Trash.', 'palgoals-testimonials'),
            'all_items'             => __('All Testimonials', 'palgoals-testimonials'),
            'archives'              => __('Testimonial Archives', 'palgoals-testimonials'),
            'attributes'            => __('Testimonial Attributes', 'palgoals-testimonials'),
            'insert_into_item'      => __('Insert into testimonial', 'palgoals-testimonials'),
            'uploaded_to_this_item' => __('Uploaded to this testimonial', 'palgoals-testimonials'),
        );
    }
}
