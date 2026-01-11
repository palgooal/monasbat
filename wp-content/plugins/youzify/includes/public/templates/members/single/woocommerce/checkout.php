<?php
/**
 * WC Checkout Template
 */

global $wp;
	
if ( isset( $_GET['yz_order'] ) && ! empty(  $_GET['yz_order'] ) ) {
	$wp->query_vars['order-received'] = absint( $_GET['yz_order'] );
}

?>
<div class="youzify-wc-main-content youzify-wc-checkout-content">

	<?php do_action( 'youzify_wc_before_checkout_content' ); ?>

	<?php echo force_balance_tags( do_shortcode( '[woocommerce_checkout]' ) ); ?>

	<?php do_action( 'youzify_wc_after_checkout_content' ); ?>

</div>