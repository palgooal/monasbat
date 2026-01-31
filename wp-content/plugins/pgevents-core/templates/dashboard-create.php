<?php
/**
 * Template الخاص بإنشاء مناسبة - يُستدعى برمجياً عبر الإضافة
 */

// منع الوصول المباشر للملف
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
    auth_redirect();
}

get_header(); 
?>

<div class="min-h-screen bg-gray-50 pt-24 pb-12 text-right" dir="rtl">
    <div class="container mx-auto px-4 max-w-2xl">
        <div class="bg-white rounded-4xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="bg-pg-primary p-8 text-white">
                <h1 class="text-2xl font-bold mb-2">إنشاء مناسبة جديدة</h1>
                <p class="opacity-80">أدخل تفاصيل مناسبتك لنقوم بتجهيز صفحة الدعوة فوراً</p>
            </div>

            <form id="create-event-form" class="p-8 space-y-6">
                <?php wp_nonce_field('pge_create_event_action', 'pge_event_nonce'); ?>

                <div>
                    <label class="block text-sm font-bold mb-2 mr-1">اسم المناسبة</label>
                    <input type="text" name="event_title" placeholder="مثلاً: حفل زفاف محمد وأمل" required 
                           class="w-full p-4 bg-gray-50 border border-gray-200 rounded-2xl outline-pg-primary focus:bg-white transition-all">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold mb-2 mr-1">تاريخ ووقت المناسبة</label>
                        <input type="datetime-local" name="event_date" required 
                               class="w-full p-4 bg-gray-50 border border-gray-200 rounded-2xl outline-pg-primary focus:bg-white transition-all">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-2 mr-1">رقم واتساب المضيف</label>
                        <input type="text" name="host_phone" placeholder="9665xxxxxxxx" required 
                               class="w-full p-4 bg-gray-50 border border-gray-200 rounded-2xl outline-pg-primary focus:bg-white transition-all text-left" dir="ltr">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold mb-2 mr-1">رابط موقع القاعة (Google Maps)</label>
                    <input type="url" name="event_location" placeholder="https://goo.gl/maps/..." required 
                           class="w-full p-4 bg-gray-50 border border-gray-200 rounded-2xl outline-pg-primary focus:bg-white transition-all text-left" dir="ltr">
                </div>

                <button type="submit" class="w-full bg-pg-dark text-white py-5 rounded-3xl font-bold text-xl shadow-lg hover:bg-pg-primary transition-all active:scale-95 flex items-center justify-center gap-3">
                    <span>نشر المناسبة الآن</span>
                    <i class="fas fa-magic"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    document.getElementById('create-event-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإنشاء...';

        const formData = new FormData(this);
        formData.append('action', 'pge_create_new_event');

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = data.data.redirect_url;
            } else {
                alert(data.data || 'حدث خطأ ما');
                btn.disabled = false;
                btn.innerHTML = 'نشر المناسبة الآن';
            }
        })
        .catch(err => {
            console.error(err);
            alert('حدث خطأ في الاتصال بالسيرفر');
            btn.disabled = false;
        });
    });
</script>

<?php get_footer(); ?>