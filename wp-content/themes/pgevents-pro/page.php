<?php get_header(); ?>

<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php $is_elementor_page = function_exists('pge_is_elementor_built_page') && pge_is_elementor_built_page(get_the_ID()); ?>

        <?php if ($is_elementor_page) : ?>
            <main class="min-h-screen">
                <?php the_content(); ?>
            </main>
        <?php else : ?>
            <main class="min-h-screen bg-gray-50 py-12">
                <div class="container mx-auto px-4">
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
                </div>
            </main>
        <?php endif; ?>

    <?php endwhile;
else : ?>
    <main class="min-h-screen bg-gray-50 py-12">
        <div class="container mx-auto px-4">
            <p class="text-center text-gray-500">عذرا، لم يتم العثور على محتوى.</p>
        </div>
    </main>
<?php endif; ?>

<?php get_footer(); ?>
