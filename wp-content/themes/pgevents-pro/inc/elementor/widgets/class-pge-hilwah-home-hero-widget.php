<?php
defined('ABSPATH') || exit;

if (!class_exists('\Elementor\Widget_Base')) {
    return;
}

/**
 * A deliberately static, isolated homepage Hero.
 *
 * It is registered for Elementor discovery only. It has not been inserted
 * into the homepage's Elementor document in this preparation phase.
 */
class PGE_Hilwah_Home_Hero_Widget extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'pge_hilwah_home_hero';
    }

    public function get_title()
    {
        return __('HILWAH Home Hero', 'pgevents');
    }

    public function get_icon()
    {
        return 'eicon-welcome';
    }

    public function get_categories()
    {
        return ['general'];
    }

    public function get_keywords()
    {
        return ['hilwah', 'hero', 'home', 'دعوات'];
    }

    public function get_style_depends()
    {
        return ['pge-hilwah-home-hero'];
    }

    protected function render()
    {
        $create_event_url = home_url('/create-event/');
        $packages_url = home_url('/#hilwah-packages');
        ?>
        <section class="pge-hilwah-home-hero" dir="rtl" aria-labelledby="pge-hilwah-home-hero-title">
            <div class="pge-hilwah-home-hero__glow pge-hilwah-home-hero__glow--gold" aria-hidden="true"></div>
            <div class="pge-hilwah-home-hero__glow pge-hilwah-home-hero__glow--navy" aria-hidden="true"></div>

            <div class="pge-hilwah-home-hero__frame">
                <div class="pge-hilwah-home-hero__copy">
                    <p class="pge-hilwah-home-hero__eyebrow"><span aria-hidden="true"></span> دعوتك تبدأ من لحظة تستحق الذكرى</p>
                    <h1 id="pge-hilwah-home-hero-title">اجعل مناسباتك<br><em>تجربة لا تُنسى</em></h1>
                    <p class="pge-hilwah-home-hero__lead">صمّم دعوتك الرقمية، أدر ضيوفك، واستقبل تأكيدات الحضور في مساحة واحدة أنيقة ومهيّأة للجوال.</p>

                    <nav class="pge-hilwah-home-hero__actions" aria-label="إجراءات البداية">
                        <a class="pge-hilwah-home-hero__button pge-hilwah-home-hero__button--primary" href="<?php echo esc_url($create_event_url); ?>">
                            <span>أنشئ مناسبتك</span>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg>
                        </a>
                        <a class="pge-hilwah-home-hero__button pge-hilwah-home-hero__button--quiet" href="<?php echo esc_url($packages_url); ?>">استكشف التجربة</a>
                    </nav>

                    <ul class="pge-hilwah-home-hero__proof" aria-label="مزايا حلوة">
                        <li><strong>دعوات</strong><span>رقمية أنيقة</span></li>
                        <li><strong>RSVP</strong><span>بتأكيد فوري</span></li>
                        <li><strong>QR</strong><span>لدخول سلس</span></li>
                    </ul>
                </div>

                <div class="pge-hilwah-home-hero__visual" aria-label="معاينة دعوة إلكترونية على هاتف" role="img">
                    <div class="pge-hilwah-home-hero__orbit pge-hilwah-home-hero__orbit--one" aria-hidden="true"></div>
                    <div class="pge-hilwah-home-hero__orbit pge-hilwah-home-hero__orbit--two" aria-hidden="true"></div>
                    <div class="pge-hilwah-home-hero__note pge-hilwah-home-hero__note--top" aria-hidden="true">
                        <span class="pge-hilwah-home-hero__note-dot"></span><span>تأكيد الحضور</span><strong>145</strong>
                    </div>

                    <div class="pge-hilwah-home-hero__phone">
                        <div class="pge-hilwah-home-hero__speaker" aria-hidden="true"></div>
                        <article class="pge-hilwah-home-hero__invitation">
                            <header class="pge-hilwah-home-hero__invitation-top">
                                <span class="pge-hilwah-home-hero__invitation-mark" aria-hidden="true">ح</span>
                                <span>دعوة خاصة</span>
                            </header>
                            <div class="pge-hilwah-home-hero__ornament" aria-hidden="true"><span></span><span></span><span></span></div>
                            <p class="pge-hilwah-home-hero__invitation-label">بكل المحبة والسرور</p>
                            <h2>ليلة من العمر</h2>
                            <p class="pge-hilwah-home-hero__names">أحمد <i>و</i> نورة</p>
                            <div class="pge-hilwah-home-hero__invitation-line" aria-hidden="true"></div>
                            <dl class="pge-hilwah-home-hero__details">
                                <div><dt>السبت</dt><dd>١٧ مايو</dd></div>
                                <div><dt>الساعة</dt><dd>٨:٣٠ مساءً</dd></div>
                            </dl>
                            <span class="pge-hilwah-home-hero__rsvp">تأكيد الحضور <span aria-hidden="true">←</span></span>
                            <footer><span>قاعة النخبة · الرياض</span><span class="pge-hilwah-home-hero__qr" aria-hidden="true"></span></footer>
                        </article>
                    </div>

                    <div class="pge-hilwah-home-hero__note pge-hilwah-home-hero__note--bottom" aria-hidden="true">
                        <span class="pge-hilwah-home-hero__check">✓</span><span>دعوتك أصبحت جاهزة للمشاركة</span>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }
}
