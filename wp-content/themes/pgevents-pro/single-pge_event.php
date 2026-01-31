<?php get_header(); ?>

<?php if (have_posts()) : while (have_posts()) : the_post(); ?>

        <main class="bg-white min-h-screen">
            <?php get_template_part('template-parts/event', 'hero'); ?>

            <?php get_template_part('template-parts/event', 'countdown'); ?>

            <?php get_template_part('template-parts/event', 'location'); ?>

            <?php get_template_part('template-parts/event', 'rsvp'); ?>
        </main>

<?php endwhile;
endif; ?>

<?php get_footer(); ?>