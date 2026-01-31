<section class="relative h-[80vh] w-full flex items-center justify-center overflow-hidden">
    <div class="absolute inset-0 z-0">
        <?php if (has_post_thumbnail()) : ?>
            <?php the_post_thumbnail('full', ['class' => 'w-full h-full object-cover']); ?>
        <?php else : ?>
            <img src="https://via.placeholder.com/1920x1080" class="w-full h-full object-cover" alt="Default Cover">
        <?php endif; ?>
        <div class="absolute inset-0 bg-black/50"></div>
    </div>

    <div class="relative z-10 text-center text-white px-4">
        <h1 class="text-5xl md:text-7xl font-bold mb-6 drop-shadow-2xl animate-fade-in"><?php the_title(); ?></h1>
        <p class="text-xl md:text-2xl font-light opacity-90 italic">نتشرف بدعوتكم لحضور حفلنا</p>
    </div>
</section>