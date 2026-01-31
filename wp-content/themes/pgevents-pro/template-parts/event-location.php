<?php $location_url = get_post_meta(get_the_ID(), '_pge_event_location', true); ?>
<section class="py-20 bg-gray-50">
    <div class="container mx-auto px-4 max-w-4xl">
        <div class="bg-white rounded-4xl shadow-xl overflow-hidden border border-gray-100 text-center p-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-pg-primary/10 text-pg-primary rounded-full mb-4">
                <i class="fas fa-map-marker-alt text-2xl"></i>
            </div>
            <h2 class="text-3xl font-bold text-pg-dark mb-4">موقع المناسبة</h2>
            <p class="text-gray-600 mb-8 text-lg">يسعدنا حضوركم في العنوان الموضح أدناه</p>
            <a href="<?php echo esc_url($location_url); ?>" target="_blank"
                class="inline-block bg-pg-primary text-white px-10 py-4 rounded-2xl font-bold text-xl shadow-lg hover:bg-pg-dark transition-all transform hover:-translate-y-1">
                فتح الموقع في الخريطة <i class="fas fa-external-link-alt mr-2 text-sm"></i>
            </a>
        </div>
    </div>
</section>