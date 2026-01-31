<?php

/**
 * Template: لوحة التحكم الرئيسية (SaaS Dashboard)
 */

if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
    auth_redirect();
}

get_header();
$current_user = wp_get_current_user();

// --- نظام الحصص (Quota Logic) ---
$allowed_limit = 5; // يمكنك مستقبلاً ربط هذا بمتغير من قاعدة البيانات حسب الباقة
$user_events_query = new WP_Query(array(
    'post_type'      => 'pge_event',
    'author'         => $current_user->ID,
    'post_status'    => array('publish', 'private', 'draft', 'pending'),
    'posts_per_page' => -1
));
$used_count = $user_events_query->found_posts;
$percentage = ($used_count / $allowed_limit) * 100;
$bar_color = ($percentage >= 100) ? 'bg-red-500' : 'bg-pg-primary';
// --------------------------------

$user_bio = get_user_meta($current_user->ID, 'pge_bio', true);
$cover_img = get_user_meta($current_user->ID, 'pge_cover_url', true) ?: 'https://images.unsplash.com/photo-1519741497674-611481863552?q=80&w=2070&auto=format&fit=crop';
?>

<div class="min-h-screen bg-gray-50 pt-20 pb-20 text-right" id="pge-dashboard" dir="rtl">
    <div class="container mx-auto px-4">

        <div class="relative bg-white rounded-4xl shadow-sm overflow-hidden border border-gray-100 mb-8">
            <div class="h-64 bg-cover bg-center" style="background-image: url('<?php echo esc_url($cover_img); ?>')">
                <div class="w-full h-full bg-black/40 backdrop-blur-xs"></div>
            </div>

            <div class="p-8 flex flex-col md:flex-row items-center gap-6 -mt-24">
                <div class="relative">
                    <?php echo get_avatar($current_user->ID, 160, '', '', array('class' => 'rounded-4xl border-8 border-white shadow-2xl h-40 w-40 object-cover')); ?>
                </div>

                <div class="text-center md:text-right pt-12 md:pt-24 flex-1">
                    <h1 class="text-4xl font-bold text-gray-900 mb-2"><?php echo esc_html($current_user->display_name); ?></h1>
                    <p id="display-bio" class="text-pg-primary font-medium mb-3 italic">"<?php echo esc_html($user_bio ?: 'أضف نبذتك الشخصية هنا...'); ?>"</p>
                </div>

                <div class="pt-12 md:pt-24 flex gap-2">
                    <button onclick="toggleEditModal()" class="bg-pg-primary text-white px-6 py-3 rounded-2xl font-bold hover:shadow-lg transition-all">
                        <i class="fas fa-edit ml-2"></i> تعديل حسابي
                    </button>
                    <a href="<?php echo wp_logout_url(home_url()); ?>" class="bg-red-50 text-red-600 px-6 py-3 rounded-2xl font-bold hover:bg-red-100 transition-all">خروج</a>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white p-6 rounded-4xl shadow-sm border border-gray-100">
                    <h3 class="font-bold text-lg mb-4 border-b pb-2">رصيد الباقة</h3>

                    <div class="mb-4">
                        <div class="flex justify-between mb-2">
                            <span class="text-sm text-gray-500">استهلاك المناسبات</span>
                            <span class="text-sm font-bold text-gray-900"><?php echo $used_count; ?> من <?php echo $allowed_limit; ?></span>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-2.5 overflow-hidden">
                            <div class="<?php echo $bar_color; ?> h-2.5 rounded-full transition-all duration-1000" style="width: <?php echo min($percentage, 100); ?>%"></div>
                        </div>
                    </div>

                    <?php if ($used_count >= $allowed_limit): ?>
                        <div class="p-3 bg-red-50 border border-red-100 rounded-2xl text-red-600 text-xs font-bold flex items-center gap-2">
                            <i class="fas fa-exclamation-circle"></i>
                            لقد استنفدت رصيدك، قم بالترقية الآن.
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-2 gap-4 mt-6">
                        <div class="bg-blue-50 p-4 rounded-3xl text-center border border-blue-100">
                            <span class="block text-2xl font-bold text-blue-600">
                                <?php echo $used_count; ?>
                            </span>
                            <span class="text-[10px] text-gray-500 font-bold uppercase">إجمالي المحاولات</span>
                        </div>
                        <div class="bg-green-50 p-4 rounded-3xl text-center border border-green-100">
                            <span class="block text-2xl font-bold text-green-600">0</span>
                            <span class="text-[10px] text-gray-500 font-bold uppercase">ضيوف أكدوا</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white p-8 rounded-4xl shadow-sm border border-gray-100">
                    <div class="flex justify-between items-center mb-8">
                        <h2 class="text-2xl font-bold text-gray-800 italic">إدارة المناسبات</h2>
                        <a href="<?php echo home_url('/create-event/'); ?>" class="bg-pg-dark text-white px-5 py-2 rounded-xl text-sm font-bold shadow-md hover:bg-pg-primary transition-colors <?php echo ($used_count >= $allowed_limit) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            + إنشاء مناسبة جديدة
                        </a>
                    </div>

                    <div class="space-y-4">
                        <?php
                        $args = array(
                            'post_type'      => 'pge_event',
                            'author'         => $current_user->ID,
                            'posts_per_page' => -1,
                            'post_status'    => array('publish', 'private'), // عرض النشطة والمؤرشفة
                            'meta_key'       => '_pge_event_date',
                            'orderby'        => 'meta_value',
                            'order'          => 'ASC'
                        );
                        $query = new WP_Query($args);

                        if ($query->have_posts()) :
                            while ($query->have_posts()) : $query->the_post();
                                $event_date = get_post_meta(get_the_ID(), '_pge_event_date', true);
                                $is_archived = (get_post_status() === 'private');
                                $status_text = $is_archived ? 'مغلقة/مؤرشفة' : ((strtotime($event_date) < time()) ? 'سابقة' : 'قادمة');
                                $status_color = $is_archived ? 'bg-red-100 text-red-600' : (($status_text == 'قادمة') ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500');
                        ?>
                                <div class="flex flex-col sm:flex-row items-center justify-between p-5 bg-gray-50 rounded-3xl border border-transparent hover:border-pg-primary/30 transition-all group <?php echo $is_archived ? 'opacity-70' : ''; ?>">
                                    <div class="flex items-center gap-4 mb-4 sm:mb-0 w-full sm:w-auto text-right">
                                        <div class="w-12 h-12 bg-white rounded-2xl flex items-center justify-center shadow-sm text-pg-primary">
                                            <i class="fas <?php echo $is_archived ? 'fa-archive' : 'fa-calendar-day'; ?> text-xl"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-bold text-gray-900 group-hover:text-pg-primary transition-colors"><?php the_title(); ?></h3>
                                            <div class="flex items-center gap-2 mt-1">
                                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?php echo $status_color; ?>"><?php echo $status_text; ?></span>
                                                <span class="text-xs text-gray-400"><?php echo date_i18n('j F Y', strtotime($event_date)); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex gap-2 mr-auto sm:mr-0">
                                        <?php if (!$is_archived): ?>
                                            <a href="<?php the_permalink(); ?>" class="p-3 bg-white rounded-xl text-gray-400 hover:text-pg-primary shadow-xs transition-all" title="عرض"><i class="fas fa-eye"></i></a>
                                            <a href="<?php echo home_url('/edit-event/' . get_the_ID() . '/'); ?>" class="p-3 bg-white rounded-xl text-gray-400 hover:text-blue-500 shadow-xs transition-all" title="تعديل"><i class="fas fa-cog"></i></a>
                                            <button onclick="archiveEvent(<?php echo get_the_ID(); ?>, '<?php echo wp_create_nonce('pge_archive_event_nonce'); ?>')" class="p-3 bg-white rounded-xl text-gray-400 hover:text-red-500 shadow-xs transition-all" title="إغلاق وأرشفة"><i class="fas fa-archive"></i></button>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400 italic ml-4">غير قابلة للتعديل</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile;
                            wp_reset_postdata();
                        else: ?>
                            <div class="text-center py-12">
                                <i class="fas fa-calendar-times text-gray-200 text-5xl mb-4 block"></i>
                                <p class="text-gray-400 italic">لا توجد مناسبات مسجلة حالياً</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php get_footer(); ?>