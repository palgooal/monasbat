<?php

/**
 * PG testimonial card template.
 *
 * @package PalgoalsTestimonials
 *
 * @var array $testimonial Prepared testimonial payload.
 * @var array $settings    Render settings.
 */

if (! defined('ABSPATH')) {
    exit;
}

$show_avatar = ! empty($settings['show_avatar']);
$show_role   = ! empty($settings['show_role']);
$show_rating = ! empty($settings['show_rating']) && ! empty($testimonial['rating']);
$show_link   = ! empty($settings['show_link']) && ! empty($testimonial['website']);
$card_classes = array('pg-testimonial-card');

if (! empty($settings['card_classes'])) {
    $card_classes = array_merge($card_classes, array_filter((array) $settings['card_classes']));
}

$role_parts  = array_filter(
    array(
        isset($testimonial['position']) ? $testimonial['position'] : '',
        isset($testimonial['company']) ? $testimonial['company'] : '',
    )
);
$role_text   = implode(' • ', $role_parts);
?>
<article class="<?php echo esc_attr(implode(' ', array_unique($card_classes))); ?>">
    <div class="pg-testimonial-card__header">
        <?php if ($show_avatar) : ?>
            <div class="pg-testimonial-card__avatar">
                <?php if (! empty($testimonial['photo_id'])) : ?>
                    <?php
                    echo wp_get_attachment_image(
                        $testimonial['photo_id'],
                        'thumbnail',
                        false,
                        array(
                            'class'   => 'pg-testimonial-card__avatar-image',
                            'loading' => 'lazy',
                            'alt'     => esc_attr($testimonial['name']),
                        )
                    );
                    ?>
                <?php else : ?>
                    <span class="pg-testimonial-card__avatar-fallback" aria-hidden="true"><?php echo esc_html($testimonial['initials']); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="pg-testimonial-card__meta pg-testimonial-card__body">
            <?php if ($show_link) : ?>
                <h3 class="pg-testimonial-card__name"><a href="<?php echo esc_url($testimonial['website']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($testimonial['name']); ?></a></h3>
            <?php else : ?>
                <h3 class="pg-testimonial-card__name"><?php echo esc_html($testimonial['name']); ?></h3>
            <?php endif; ?>

            <?php if ($show_role && '' !== $role_text) : ?>
                <p class="pg-testimonial-card__role"><?php echo esc_html($role_text); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($show_rating) : ?>
        <?php echo wp_kses_post(Palgoals_Testimonial_Card_Renderer::render_stars($testimonial['rating'])); ?>
    <?php endif; ?>

    <div class="pg-testimonial-card__content">
        <?php echo wp_kses_post(wpautop($testimonial['content'])); ?>
    </div>
</article>