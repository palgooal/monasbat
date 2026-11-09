<?php
/**
 * Local-only native homepage preview.
 *
 * This template is reached only through pge_is_native_home_preview(); it is
 * deliberately independent of the Page 62 Elementor document.
 * The Header is native in this preview. Footer remains HFE-provided until its
 * homepage-specific native replacement is approved in a separate phase.
 */
defined('ABSPATH') || exit;
?>
<main id="hilwah-home-native" class="min-h-screen overflow-x-clip bg-[hsl(30_30%_97%)] text-[hsl(220_25%_18%)]" dir="rtl">
    <?php get_template_part('template-parts/home-native/hero'); ?>
    <?php get_template_part('template-parts/home-native/features'); ?>
    <?php get_template_part('template-parts/home-native/how-it-works'); ?>
    <?php get_template_part('template-parts/home-native/packages'); ?>
    <?php get_template_part('template-parts/home-native/faq'); ?>
    <?php get_template_part('template-parts/home-native/final-cta'); ?>
</main>
