<?php

/**
 * PG testimonial card renderer.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
    exit;
}

class Palgoals_Testimonial_Card_Renderer
{

    /**
     * Render a PG testimonial card.
     *
     * @param array $testimonial Prepared testimonial payload.
     * @param array $settings    Render settings.
     * @return string
     */
    public static function render($testimonial, $settings = array())
    {
        $settings = wp_parse_args(
            $settings,
            array(
                'show_avatar'  => true,
                'show_role'    => true,
                'show_rating'  => true,
                'show_link'    => true,
                'card_classes' => array(),
            )
        );

        $template = PALGOALS_TESTIMONIALS_PATH . 'templates/pg/testimonial-card.php';

        if (! file_exists($template)) {
            return '';
        }

        ob_start();
        include $template;

        return ob_get_clean();
    }

    /**
     * Render star markup.
     *
     * @param int $rating Rating value.
     * @return string
     */
    public static function render_stars($rating)
    {
        $rating = max(0, min(5, absint($rating)));

        ob_start();
?>
        <div class="pg-testimonial-card__rating" aria-label="<?php echo esc_attr(sprintf(__('%d out of 5 stars', 'palgoals-testimonials'), $rating)); ?>">
            <?php for ($index = 1; $index <= 5; $index++) : ?>
                <span class="pg-testimonial-card__star <?php echo $index <= $rating ? 'is-active' : ''; ?>" aria-hidden="true">&#9733;</span>
            <?php endfor; ?>
        </div>
<?php

        return ob_get_clean();
    }
}
