<?php get_header(); ?>
<?php
$front_page_id = (int) get_queried_object_id();
$use_elementor_front = function_exists('pge_is_elementor_built_page') && pge_is_elementor_built_page($front_page_id);
?>

<?php if ($use_elementor_front && have_posts()) : ?>
    <?php while (have_posts()) : the_post(); ?>
        <main class="min-h-screen">
            <?php the_content(); ?>
        </main>
    <?php endwhile; ?>
<?php else : ?>
    <main class="min-h-screen bg-slate-50 text-slate-900">
        <?php get_template_part('template-parts/home/hero'); ?>
        <?php get_template_part('template-parts/home/features'); ?>
        <?php get_template_part('template-parts/home/how-it-works'); ?>
        <?php get_template_part('template-parts/home/pricing'); ?>
        <?php get_template_part('template-parts/home/faq'); ?>
        <?php get_template_part('template-parts/home/final-cta'); ?>
    </main>
<?php endif; ?>

<?php get_footer(); ?>
