<?php

/**
 * Testimonial category taxonomy service.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
    exit;
}

class Palgoals_Testimonials_Category_Taxonomy
{

    /**
     * Taxonomy key.
     *
     * @var string
     */
    protected $taxonomy;

    /**
     * Related post type.
     *
     * @var string
     */
    protected $post_type;

    /**
     * Constructor.
     *
     * @param string $taxonomy  Taxonomy key.
     * @param string $post_type Related post type.
     */
    public function __construct($taxonomy, $post_type)
    {
        $this->taxonomy  = $taxonomy;
        $this->post_type = $post_type;
    }

    /**
     * Register taxonomy.
     *
     * @return void
     */
    public function register()
    {
        register_taxonomy($this->taxonomy, $this->post_type, $this->get_args());
    }

    /**
     * Build taxonomy args.
     *
     * @return array
     */
    protected function get_args()
    {
        return array(
            'labels'            => $this->get_labels(),
            'public'            => true,
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => array('slug' => 'testimonial-category'),
        );
    }

    /**
     * Build taxonomy labels.
     *
     * @return array
     */
    protected function get_labels()
    {
        return array(
            'name'              => __('Testimonial Categories', 'palgoals-testimonials'),
            'singular_name'     => __('Testimonial Category', 'palgoals-testimonials'),
            'search_items'      => __('Search Testimonial Categories', 'palgoals-testimonials'),
            'all_items'         => __('All Testimonial Categories', 'palgoals-testimonials'),
            'parent_item'       => __('Parent Testimonial Category', 'palgoals-testimonials'),
            'parent_item_colon' => __('Parent Testimonial Category:', 'palgoals-testimonials'),
            'edit_item'         => __('Edit Testimonial Category', 'palgoals-testimonials'),
            'update_item'       => __('Update Testimonial Category', 'palgoals-testimonials'),
            'add_new_item'      => __('Add New Testimonial Category', 'palgoals-testimonials'),
            'new_item_name'     => __('New Testimonial Category Name', 'palgoals-testimonials'),
            'menu_name'         => __('Categories', 'palgoals-testimonials'),
        );
    }
}
