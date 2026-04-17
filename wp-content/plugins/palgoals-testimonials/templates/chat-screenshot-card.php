<?php
/**
 * Chat screenshot card template.
 *
 * @package PalgoalsTestimonials
 *
 * @var array $screenshot Prepared screenshot data.
 * @var array $settings   Normalized render settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$show_title   = ! empty( $settings['show_title'] ) && ! empty( $screenshot['title'] );
$show_caption = ! empty( $settings['show_caption'] ) && ! empty( $screenshot['caption'] );
$card_classes = array( 'palgoals-chat-shot-card' );

if ( ! $show_title && ! $show_caption ) {
	$card_classes[] = 'palgoals-chat-shot-card--media-only';
}
?>
<article class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
	<?php if ( ! empty( $settings['open_full_image'] ) && ! empty( $screenshot['image_url'] ) ) : ?>
		<a
			class="palgoals-chat-shot-card__link"
			href="<?php echo esc_url( $screenshot['image_url'] ); ?>"
			target="_blank"
			rel="noopener noreferrer"
			data-elementor-open-lightbox="yes"
			data-elementor-lightbox-slideshow="<?php echo esc_attr( $settings['lightbox_group'] ); ?>"
		>
	<?php else : ?>
		<div class="palgoals-chat-shot-card__link">
	<?php endif; ?>
		<div class="palgoals-chat-shot-card__media">
			<?php
			echo wp_get_attachment_image(
				$screenshot['photo_id'],
				'large',
				false,
				array(
					'class'   => 'palgoals-chat-shot-card__image',
					'loading' => 'lazy',
					'alt'     => esc_attr( $screenshot['title'] ),
				)
			);
			?>
		</div>
	<?php if ( ! empty( $settings['open_full_image'] ) && ! empty( $screenshot['image_url'] ) ) : ?>
		</a>
	<?php else : ?>
		</div>
	<?php endif; ?>

	<?php if ( $show_title || $show_caption ) : ?>
		<div class="palgoals-chat-shot-card__caption">
			<?php if ( $show_title ) : ?>
				<h3 class="palgoals-chat-shot-card__title"><?php echo esc_html( $screenshot['title'] ); ?></h3>
			<?php endif; ?>

			<?php if ( $show_caption ) : ?>
				<div class="palgoals-chat-shot-card__text"><?php echo wp_kses_post( wpautop( $screenshot['caption'] ) ); ?></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</article>
