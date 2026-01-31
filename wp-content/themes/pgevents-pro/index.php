<?php get_header(); ?>
<main class="container mx-auto px-4 py-20 min-h-screen">
    <div class="bg-white rounded-4xl shadow-xl p-10 border border-gray-100">
        <?php
        if (have_posts()) :
            while (have_posts()) : the_post();
                the_content();
            endwhile;
        endif;
        ?>
    </div>
</main>
<?php get_footer(); ?>