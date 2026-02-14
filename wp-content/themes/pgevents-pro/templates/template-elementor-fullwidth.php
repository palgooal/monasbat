<?php
/**
 * Template Name: Elementor Full Width
 * Template Post Type: page
 */

defined('ABSPATH') || exit;

get_header();

if (have_posts()) :
    while (have_posts()) :
        the_post();
?>
        <main class="min-h-screen">
            <?php the_content(); ?>
        </main>
<?php
    endwhile;
endif;

get_footer();
