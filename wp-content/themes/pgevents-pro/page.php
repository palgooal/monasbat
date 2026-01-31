<?php get_header(); ?>

<main class="min-h-screen bg-gray-50 py-12">
    <div class="container mx-auto px-4">

        <?php if (have_posts()) : while (have_posts()) : the_post(); ?>

                <article id="post-<?php the_ID(); ?>" <?php post_class('bg-white p-8 rounded-3xl shadow-sm'); ?>>

                    <?php if (!is_front_page()) : ?>
                        <h1 class="text-3xl font-bold text-pg-dark mb-8 border-r-4 border-pg-primary pr-4">
                            <?php the_title(); ?>
                        </h1>
                    <?php endif; ?>

                    <div class="prose max-w-none text-gray-700">
                        <?php the_content(); ?>
                    </div>

                </article>

            <?php endwhile;
        else : ?>
            <p class="text-center text-gray-500">عذراً، لم يتم العثور على محتوى.</p>
        <?php endif; ?>

    </div>
</main>

<?php get_footer(); ?>