<?php
/**
 * Single Product Thumbnails
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/product-thumbnails.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see        https://docs.woocommerce.com/document/template-structure/
 * @package    WooCommerce/Templates
 * @version     3.5.1
 */

defined( 'ABSPATH' ) || exit;

// Note: `wc_get_gallery_image_html` was added in WC 3.3.2 and did not exist prior. This check protects against theme overrides being used on older versions of WC.
if ( ! function_exists( 'wc_get_gallery_image_html' ) ) {
	return;
}

global $post, $product;

$attachment_ids = $product->get_gallery_image_ids();

if ( $attachment_ids && $product->get_image_id() ) {

	if ( has_post_thumbnail() ) {
		$attachment_ids = array_merge([get_post_thumbnail_id()], $attachment_ids);
	}

	$loop    = 1;
	$columns = apply_filters( 'woocommerce_product_thumbnails_columns', 3 );

	?>

	<?php if ( count( $attachment_ids ) > 1 ) : ?>
		<div class="kleo-gallery kleo-woo-gallery animate-when-almost-visible">
			<div class="kleo-thumbs-carousel kleo-thumbs-animated th-fade" 
				data-min-items="<?php esc_attr_e($columns); ?>" 
				data-max-items="<?php esc_attr_e($columns+1); ?>" 
				data-circular="true">

	<?php else : ?>
		<div class="kleo-woo-gallery thumbnails">
	<?php endif; ?>

	<?php
	foreach ( $attachment_ids as $attachment_id ) {
		$full_size_image = wp_get_attachment_image_src( $attachment_id, 'full' );
		$thumbnail       = wp_get_attachment_image_src( $attachment_id, 'shop_thumbnail' );
		$attributes      = array(
			'title'                   => get_post_field( 'post_title', $attachment_id ),
			'data-caption'            => get_post_field( 'post_excerpt', $attachment_id ),
			'data-src'                => $full_size_image[0],
			'data-large_image'        => $full_size_image[0],
			'data-large_image_width'  => $full_size_image[1],
			'data-large_image_height' => $full_size_image[2],
		);

		$selected = (int) $product->get_image_id() === (int) $attachment_id ? ' selected' : '';

		$html = '<div data-thumb="' . esc_url( $thumbnail[0] ) . '" class="woocommerce-product-gallery__image">' .
		        '<a class="zoom' . $selected . '" id="product-thumb-' . $loop . '" href="' . esc_url( $full_size_image[0] ) . '">';
		$html .= wp_get_attachment_image( $attachment_id, 'shop_single', false, $attributes );
		$html .= '</a></div>';

		echo apply_filters( 'woocommerce_single_product_image_thumbnail_html', $html, $attachment_id );

		$loop ++;
	}
	?>
	<?php if ( count( $attachment_ids ) > 1 ) : ?>
			</div>
			<a class="kleo-thumbs-prev" href="#"><i class="icon-angle-left"></i></a>
			<a class="kleo-thumbs-next" href="#"><i class="icon-angle-right"></i></a>
		</div><!--end carousel-container-->
	<?php else : ?>
		</div>
	<?php endif; ?>
<?php
}
