<?php
/** Catalog-only cards for the authorized native homepage preview. */
defined('ABSPATH') || exit;

require_once dirname(__DIR__, 2) . '/inc/class-pge-catalog-packages-presenter.php';
$settings = ['max_features' => PHP_INT_MAX, 'fallback_url' => ['url' => home_url('/packages/')]];
$packages = PGE_Catalog_Packages_Presenter::build($settings, PGE_Catalog_Packages_Presenter::NATIVE);
?>
<section id="hilwah-packages" class="pge-native-home-packages" aria-labelledby="hilwah-packages-title">
    <div class="mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8">
        <div class="pge-native-home-packages__intro">
            <h2 id="hilwah-packages-title">باقات حلوة</h2>
            <p>تعرّف على الباقات ومميزاتها، واختر ما يناسب مناسبتك.</p>
        </div>
        <?php if (!$packages) : ?>
            <p class="pge-native-home-packages__empty">لا توجد باقات متاحة للعرض حاليًا. يرجى العودة لاحقًا.</p>
        <?php else : ?>
            <div class="pge-native-home-packages__cards">
                <?php foreach ($packages as $package) : ?>
                    <article class="pge-native-home-packages__card">
                        <h3><?php echo esc_html($package['name']); ?></h3>
                        <?php if ($package['price_text'] !== '') : ?>
                            <p class="pge-native-home-packages__price">
                                <?php if ($package['has_starting_price']) : ?>
                                    <span class="pge-native-home-packages__prefix">ابتداءً من</span>
                                <?php endif; ?>
                                <strong><bdi><?php echo esc_html($package['price_text']); ?></bdi></strong>
                                <bdi class="pge-native-home-packages__currency"><?php echo esc_html($package['currency'] === 'SAR' ? 'ر.س' : $package['currency']); ?></bdi>
                            </p>
                        <?php else : ?>
                            <p class="pge-native-home-packages__unavailable">لا يتوفر سعر للشراء حاليًا.</p>
                        <?php endif; ?>
                        <?php if ($package['features']) : ?>
                            <ul class="pge-native-home-packages__features">
                                <?php foreach ($package['features'] as $feature) : ?>
                                    <li><span aria-hidden="true">✓</span><span><?php echo esc_html($feature); ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <a class="pge-native-home-packages__cta" href="<?php echo esc_url($package['url']); ?>">
                            <?php echo esc_html($package['is_direct_purchase'] ? 'اشترِ الآن' : 'عرض التفاصيل'); ?>
                            <span class="pge-native-home-packages__sr-only"> — <?php echo esc_html($package['name']); ?></span>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
