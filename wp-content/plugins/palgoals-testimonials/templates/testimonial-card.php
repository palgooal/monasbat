<?php
/**
 * Testimonial card template.
 *
 * @package PalgoalsTestimonials
 *
 * @var array $testimonial Prepared testimonial data.
 * @var array $settings    Normalized render settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$skin         = ! empty( $settings['skin'] ) ? $settings['skin'] : 'default';
$card_classes = array(
	'palgoals-testimonial-card',
	'palgoals-testimonial-card--skin-' . $skin,
);
$has_position = ! empty( $testimonial['position'] );
$has_company  = ! empty( $settings['show_company'] ) && ! empty( $testimonial['company'] );
$separator    = 'editorial' === $skin ? ( is_rtl() ? '/' : __( 'at', 'palgoals-testimonials' ) ) : '/';
?>
<article class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
	<div class="palgoals-testimonial-card__header">
		<?php if ( ! empty( $settings['show_photo'] ) ) : ?>
			<div class="palgoals-testimonial-avatar">
				<?php if ( ! empty( $testimonial['photo_id'] ) ) : ?>
					<?php
					echo wp_get_attachment_image(
						$testimonial['photo_id'],
						'thumbnail',
						false,
						array(
							'class'   => 'palgoals-testimonial-avatar__image',
							'loading' => 'lazy',
							'alt'     => esc_attr( $testimonial['name'] ),
						)
					);
					?>
				<?php else : ?>
					<span class="palgoals-testimonial-avatar__fallback" aria-hidden="true"><?php echo esc_html( $testimonial['initials'] ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="palgoals-testimonial-identity">
			<h3 class="palgoals-testimonial-name">
				<?php if ( ! empty( $testimonial['website'] ) ) : ?>
					<a href="<?php echo esc_url( $testimonial['website'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $testimonial['name'] ); ?>
					</a>
				<?php else : ?>
					<?php echo esc_html( $testimonial['name'] ); ?>
				<?php endif; ?>
			</h3>
			<?php if ( $has_position || $has_company ) : ?>
				<div class="palgoals-testimonial-meta">
					<?php if ( $has_position ) : ?>
						<span class="palgoals-testimonial-meta__position"><?php echo esc_html( $testimonial['position'] ); ?></span>
					<?php endif; ?>

					<?php if ( $has_position && $has_company ) : ?>
						<span class="palgoals-testimonial-meta__separator"><?php echo esc_html( $separator ); ?></span>
					<?php endif; ?>

					<?php if ( $has_company ) : ?>
						<span class="palgoals-testimonial-meta__company"><?php echo esc_html( $testimonial['company'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! empty( $settings['show_rating'] ) ) : ?>
		<div class="palgoals-testimonial-rating"><?php echo wp_kses_post( Palgoals_Testimonials_Renderer::render_stars( $testimonial['rating'] ) ); ?></div>
	<?php endif; ?>

	<div class="palgoals-testimonial-content">
		<?php echo wp_kses_post( wpautop( $testimonial['content'] ) ); ?>
	</div>
</article>
