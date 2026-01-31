<section id="rsvp" class="py-20 bg-white">
    <div class="container mx-auto max-w-2xl px-4">
        <div class="bg-white p-10 rounded-4xl shadow-2xl border border-gray-50 text-center">
            <h2 class="text-3xl font-bold text-pg-dark mb-2">تأكيد الحضور</h2>
            <p class="text-gray-500 mb-10 text-lg italic">يسعدنا جداً أن نراكم بيننا</p>

            <form id="pge-rsvp-form" class="space-y-6 text-right">
                <input type="hidden" name="event_id" value="<?php echo get_the_ID(); ?>">
                <div>
                    <label class="block text-sm font-bold mb-2 mr-1">الاسم الكريم</label>
                    <input type="text" name="guest_name" required class="w-full p-4 bg-gray-50 border border-gray-200 rounded-2xl outline-pg-primary transition-all focus:bg-white" placeholder="اكتب اسمك هنا...">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <label class="cursor-pointer group"><input type="radio" name="attendance" value="will_attend" checked class="peer hidden">
                        <div class="p-4 bg-gray-50 border-2 border-transparent rounded-2xl text-center font-bold text-gray-600 transition-all peer-checked:border-pg-primary peer-checked:bg-pg-primary/5 peer-checked:text-pg-primary">سأحضر بكل سرور</div>
                    </label>
                    <label class="cursor-pointer group"><input type="radio" name="attendance" value="sorry" class="peer hidden">
                        <div class="p-4 bg-gray-50 border-2 border-transparent rounded-2xl text-center font-bold text-gray-600 transition-all peer-checked:border-red-500 peer-checked:bg-red-50 peer-checked:text-red-500">أعتذر، تمنيت الحضور</div>
                    </label>
                </div>

                <div id="plus-ones-wrapper">
                    <label class="block text-sm font-bold mb-2 mr-1">عدد المرافقين</label>
                    <input type="number" name="plus_ones" min="0" value="0" class="w-full p-4 bg-gray-50 border border-gray-200 rounded-2xl outline-pg-primary">
                </div>

                <button type="submit" class="w-full bg-pg-primary text-white py-5 rounded-3xl font-bold text-xl shadow-lg hover:shadow-pg-primary/30 transition-all active:scale-95">إرسال الرد <i class="fas fa-paper-plane mr-2 text-sm"></i></button>
                <div id="rsvp-message" class="hidden p-4 rounded-2xl font-bold text-center mt-4"></div>
            </form>
        </div>
    </div>
</section>

<script>
    document.getElementById('pge-rsvp-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const hostPhone = "<?php echo get_post_meta(get_the_ID(), '_pge_host_phone', true); ?>";
        const msg = `مرحباً، أنا: *${fd.get('guest_name')}*\nالرد: ${fd.get('attendance') === 'will_attend' ? 'سأحضر ✅' : 'أعتذر ❌'}\nالمرافقين: ${fd.get('plus_ones')}\nPgEvents`;

        const res = document.getElementById('rsvp-message');
        res.className = "p-4 rounded-2xl font-bold text-center mt-4 bg-green-100 text-green-700";
        res.innerText = "جاري توجيهك لواتساب...";
        res.classList.remove('hidden');

        setTimeout(() => window.open(`https://wa.me/${hostPhone}?text=${encodeURIComponent(msg)}`, '_blank'), 1000);
    });
</script>