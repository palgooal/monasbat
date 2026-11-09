<?php
/**
 * Approved HILWAH Hero reproduced as a native PHP partial for the isolated
 * preview. The copy, illustration, and destinations intentionally match the
 * approved Elementor widget without depending on Elementor at render time.
 */
defined('ABSPATH') || exit;

$create_event_url = home_url('/create-event/');
$packages_url = function_exists('pge_is_native_home_preview') && pge_is_native_home_preview()
    ? '#hilwah-packages'
    : home_url('/#hilwah-packages');
?>
<section class="pge-native-home-hero relative isolate overflow-hidden" aria-labelledby="pge-native-home-hero-title">
    <div class="pge-native-home-hero__glow pge-native-home-hero__glow--gold" aria-hidden="true"></div>
    <div class="pge-native-home-hero__glow pge-native-home-hero__glow--navy" aria-hidden="true"></div>

    <div class="relative z-10 mx-auto grid w-full max-w-6xl grid-cols-1 items-center gap-8 px-4 py-9 sm:px-6 sm:py-14 lg:grid-cols-2 lg:gap-12 lg:px-8 lg:py-16">
        <div class="pge-native-home-hero__copy py-4 text-center lg:text-right">
            <p class="pge-native-home-hero__eyebrow"><span aria-hidden="true"></span> دعوتك تبدأ من لحظة تستحق الذكرى</p>
            <h1 id="pge-native-home-hero-title">اجعل مناسباتك<br><em>تجربة لا تُنسى</em></h1>
            <p class="pge-native-home-hero__lead">صمّم دعوتك الرقمية، أدر ضيوفك، واستقبل تأكيدات الحضور في مساحة واحدة أنيقة ومهيّأة للجوال.</p>

            <nav class="pge-native-home-hero__actions" aria-label="إجراءات البداية">
                <a class="pge-native-home-hero__button pge-native-home-hero__button--primary" href="<?php echo esc_url($create_event_url); ?>">
                    <span>أنشئ مناسبتك</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg>
                </a>
                <a class="pge-native-home-hero__button pge-native-home-hero__button--quiet" href="<?php echo esc_url($packages_url); ?>">استكشف التجربة</a>
            </nav>

            <ul class="pge-native-home-hero__proof" aria-label="مزايا حلوة">
                <li><strong>دعوات</strong><span>رقمية أنيقة</span></li>
                <li><strong>RSVP</strong><span>بتأكيد فوري</span></li>
                <li><strong>QR</strong><span>لدخول سلس</span></li>
            </ul>
        </div>

        <div class="pge-native-home-hero__visual" aria-label="معاينة دعوة إلكترونية على هاتف" role="img">
            <div class="pge-native-home-hero__orbit pge-native-home-hero__orbit--one" aria-hidden="true"></div>
            <div class="pge-native-home-hero__orbit pge-native-home-hero__orbit--two" aria-hidden="true"></div>
            <div class="pge-native-home-hero__note pge-native-home-hero__note--top" aria-hidden="true">
                <span class="pge-native-home-hero__note-dot"></span><span>تأكيد الحضور</span><strong>145</strong>
            </div>

            <div class="pge-native-home-hero__phone">
                <div class="pge-native-home-hero__speaker" aria-hidden="true"></div>
                <article class="pge-native-home-hero__invitation">
                    <header class="pge-native-home-hero__invitation-top">
                        <span class="pge-native-home-hero__invitation-mark" aria-hidden="true">ح</span>
                        <span>دعوة خاصة</span>
                    </header>
                    <div class="pge-native-home-hero__ornament" aria-hidden="true"><span></span><span></span><span></span></div>
                    <p class="pge-native-home-hero__invitation-label">بكل المحبة والسرور</p>
                    <h2>ليلة من العمر</h2>
                    <p class="pge-native-home-hero__names">أحمد <i>و</i> نورة</p>
                    <div class="pge-native-home-hero__invitation-line" aria-hidden="true"></div>
                    <dl class="pge-native-home-hero__details">
                        <div><dt>السبت</dt><dd>١٧ مايو</dd></div>
                        <div><dt>الساعة</dt><dd>٨:٣٠ مساءً</dd></div>
                    </dl>
                    <span class="pge-native-home-hero__rsvp">تأكيد الحضور <span aria-hidden="true">←</span></span>
                    <footer><span>قاعة النخبة · الرياض</span><span class="pge-native-home-hero__qr" aria-hidden="true"></span></footer>
                </article>
            </div>

            <div class="pge-native-home-hero__note pge-native-home-hero__note--bottom" aria-hidden="true">
                <span class="pge-native-home-hero__check">✓</span><span>دعوتك أصبحت جاهزة للمشاركة</span>
            </div>
        </div>
    </div>
</section>
