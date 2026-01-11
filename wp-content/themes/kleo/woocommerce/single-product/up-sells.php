<?php
/**
 * Single Product Up-Sells
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/up-sells.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see 	    https://docs.woocommerce.com/document/template-structure/
 * @author 		WooThemes
 * @package 	WooCommerce/Templates
 * @version     3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* KLEO ADDED */
$items = sq_option( 'woo_related_columns', 3 );

if ( ! isset( $upsells ) ) {
	return;
}

if ( $upsells ) : ?>


	<div class="upsells products kleo-shop-<?php echo esc_attr( $items );?>">

		<div class="hr-title hr-full"><abbr><?php _e( 'You may also like&hellip;', 'woocommerce' ) ?></abbr></div>

		<?php woocommerce_product_loop_start(); ?>

		<?php foreach ( $upsells as $upsell ) : ?>

			<?php
			$post_object = get_post( $upsell->get_id() );
			setup_postdata( $GLOBALS['post'] =& $post_object );
			wc_get_template_part( 'content', 'product' ); ?>

		<?php endforeach; ?>

		<?php woocommerce_product_loop_end(); ?>


	</div>

<?php endif;

wp_reset_postdata();
