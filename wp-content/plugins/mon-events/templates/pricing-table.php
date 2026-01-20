<?php
// دالة لعرض جدول الباقات
function mon_display_packages() {
$packages = [
'plan_1' => ['name' => 'باقة ١', 'price' => '49', 'guests' => '15', 'photos' => '10', 'video' => 'يوتيوب'],
'plan_2' => ['name' => 'باقة ٢', 'price' => '69', 'guests' => '30', 'photos' => '25', 'video' => 'يوتيوب'],
'plan_3' => ['name' => 'باقة ٣', 'price' => '199', 'guests' => '200', 'photos' => '50', 'video' => 'رفع فيديو'],
'plan_4' => ['name' => 'باقة ٤', 'price' => '450', 'guests' => '500', 'photos' => '70', 'video' => '2 فيديو'],
];

$html = '<div class="mon-packages-container">';

    foreach ($packages as $id => $pkg) {
    $html .= '
    <div class="mon-package-card ' . $id . '">
        <h3>' . $pkg['name'] . '</h3>
        <div class="price">' . $pkg['price'] . ' <span>ريال</span></div>
        <ul>
            <li>عدد المدعوين: ' . $pkg['guests'] . '</li>
            <li>رفع صور: ' . $pkg['photos'] . '</li>
            <li>الفيديو: ' . $pkg['video'] . '</li>
            <li>باركود المناسبة: مدعوم</li>
        </ul>
        <a href="' . mon_get_salla_link($id) . '" class="buy-btn">اشترك الآن</a>
    </div>';
    }

    $html .= '
</div>';
return $html;
}
add_shortcode('mon_packages', 'mon_display_packages');

// دالة تجريبية لجلب رابط المنتج من سلة (سنربطها لاحقاً بالـ API)
function mon_get_salla_link($plan_id)
{
// روابط المنتجات في متجر سلة (يجب تحديثها بالروابط الحقيقية لاحقاً)
$links = [
'plan_1' => 'https://salla.sa/your-store/product-1',
'plan_2' => 'https://salla.sa/your-store/product-2',
'plan_3' => 'https://salla.sa/your-store/product-3', // أضفنا هذا السطر
'plan_4' => 'https://salla.sa/your-store/product-4', // أضفنا هذا السطر
];

// تأكد من وجود الرابط لتجنب الخطأ المستقبلي
return isset($links[$plan_id]) ? $links[$plan_id] : '#';
}