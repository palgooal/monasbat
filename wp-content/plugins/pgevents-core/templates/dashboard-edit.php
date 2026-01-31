<?php

/**
 * Template الخاص بتعديل مناسبة قائمة
 */
// echo "Event ID: " . get_query_var('event_id');
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) auth_redirect();

// 1. جلب رقم المناسبة من الرابط
$event_id = get_query_var('event_id');
$post = get_post($event_id);

// 2. حماية أمنية: التأكد من وجود المناسبة وأن المستخدم هو صاحبها
if (!$post || $post->post_type !== 'pge_event' || $post->post_author != get_current_user_id()) {
    wp_die('عذراً، لا تملك صلاحية الوصول لهذه الصفحة أو أن المناسبة غير موجودة.');
}

// 3. جلب البيانات الحالية
$event_date     = get_post_meta($event_id, '_pge_event_date', true);
$event_location = get_post_meta($event_id, '_pge_event_location', true);
$host_phone     = get_post_meta($event_id, '_pge_host_phone', true);

get_header();
?>

<div class="min-h-screen bg-gray-50 pt-24 pb-12 text-right" dir="rtl">
    <div class="container mx-auto px-4 max-w-2xl">
        <div class="bg-white rounded-4xl shadow-sm border border-gray-100 overflow-hidden">

            <div class="bg-blue-600 p-8 text-white flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold mb-2">تعديل المناسبة</h1>
                    <p class="opacity-80">قم بتحديث بيانات مناسبتك ثم اضغط حفظ</p>
                </div>
                <i class="fas fa-edit text-4xl opacity-20"></i>
            </div>

            <form id="edit-event-form" class="p-8 space-y-6">
                <?php wp_nonce_field('pge_edit_event_action', 'pge_event_nonce'); ?>
                <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">

                <div>
                    <label class="block text-sm font-bold mb-2 mr-1">اسم المناسبة</label>
                    <input type="text" name="event_title" value="<?php echo esc_attr($post->post_title); ?>" required
                        class="w-full p-4 bg-gray-50 border border-gray-200 rounded-2xl outline-pg-primary focus:bg-white transition-all">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold mb-2 mr-1">تاريخ ووقت المناسبة</label>
                        <input type="datetime-local" name="event_date" value="<?php echo esc_attr($event_date); ?>" required
                            class="w-full p-4 bg-gray-50 border border-gray-200 rounded-2xl outline-pg-primary focus:bg-white transition-all">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-2 mr-1">رقم واتساب المضيف</label>
                        <input type="text" name="host_phone" value="<?php echo esc_attr($host_phone); ?>" placeholder="9665xxxxxxxx" required
                            class="w-full p-4 bg-gray-50 border border-gray-200 rounded-2xl outline-pg-primary focus:bg-white transition-all text-left" dir="ltr">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold mb-2 mr-1">رابط موقع القاعة (Google Maps)</label>
                    <input type="url" name="event_location" value="<?php echo esc_url($event_location); ?>" placeholder="https://goo.gl/maps/..." required
                        class="w-full p-4 bg-gray-50 border border-gray-200 rounded-2xl outline-pg-primary focus:bg-white transition-all text-left" dir="ltr">
                </div>

                <div class="flex gap-4">
                    <button type="submit" class="flex-1 bg-pg-dark text-white py-5 rounded-3xl font-bold text-xl shadow-lg hover:bg-pg-primary transition-all active:scale-95 flex items-center justify-center gap-3">
                        <span>حفظ التعديلات</span>
                        <i class="fas fa-save"></i>
                    </button>
                    <a href="<?php echo home_url('/dashboard/'); ?>" class="px-8 py-5 bg-gray-100 text-gray-500 rounded-3xl font-bold flex items-center justify-center hover:bg-gray-200 transition-all">
                        إلغاء
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.getElementById('edit-event-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...';

        const formData = new FormData(this);
        formData.append('action', 'pge_handle_event_update'); // استدعاء دالة التحديث في event-factory

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // التوجيه للوحة التحكم بعد النجاح
                    window.location.href = '<?php echo home_url('/dashboard/'); ?>?update=success';
                } else {
                    alert(data.data || 'حدث خطأ أثناء التعديل');
                    btn.disabled = false;
                    btn.innerHTML = '<span>حفظ التعديلات</span><i class="fas fa-save"></i>';
                }
            })
            .catch(err => {
                alert('حدث خطأ في الاتصال بالسيرفر');
                btn.disabled = false;
            });
    });
</script>

<?php get_footer(); ?>