<?php $event_date = get_post_meta(get_the_ID(), '_pge_event_date', true); ?>
<section class="py-16 bg-white border-b border-gray-100" id="countdown-section" data-date="<?php echo esc_attr($event_date); ?>">
    <div class="container mx-auto px-4 text-center">
        <h2 class="text-2xl font-bold mb-8 text-pg-primary italic">المتبقي على اللحظة المنتظرة</h2>
        <div class="flex justify-center gap-4 text-pg-dark flex-row-reverse">
            <?php
            $units = [
                'days' => 'يوم',
                'hours' => 'ساعة',
                'minutes' => 'دقيقة',
                'seconds' => 'ثانية'
            ];
            foreach ($units as $id => $label) : ?>
                <div class="bg-gray-100 p-4 rounded-2xl min-w-[90px]">
                    <span id="<?php echo $id; ?>" class="block text-4xl font-bold text-pg-primary">00</span>
                    <span class="text-sm"><?php echo $label; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const countdownSection = document.getElementById('countdown-section');
        const targetDate = new Date(countdownSection.getAttribute('data-date')).getTime();

        const update = () => {
            const distance = targetDate - new Date().getTime();
            if (distance < 0) {
                countdownSection.querySelector('.flex').innerHTML = "<h2 class='text-2xl font-bold text-pg-primary font-bold'>🎉 بدأت المناسبة الآن!</h2>";
                return;
            }
            document.getElementById('days').innerText = Math.floor(distance / 86400000);
            document.getElementById('hours').innerText = Math.floor((distance % 86400000) / 3600000);
            document.getElementById('minutes').innerText = Math.floor((distance % 3600000) / 60000);
            document.getElementById('seconds').innerText = Math.floor((distance % 60000) / 1000);
        };
        setInterval(update, 1000);
        update();
    });
</script>