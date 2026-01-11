<?php

/**
 * Add custom wcvendors pro css classes
 *
 * @param array $classes - body css classes
 *
 * @return array $classes - body css classes
 * @since    1.0.0
 * @access   public
 *
 */
function kleo_wcv_body_class( $classes ) {

	$dashboard_page = get_option( 'wcvendors_vendor_dashboard_page_id' );
	$orders_page    = get_option( 'wcvendors_product_orders_page_id' );
	$shop_settings  = get_option( 'wcvendors_shop_settings_page_id' );
	$terms_page     = get_option( 'wcvendors_vendor_terms_page_id' );

	if ( is_page( $dashboard_page ) ) {
		$classes[] = 'wcvendors wcv-vendor-dashboard-page';
	}

	if ( is_page( $orders_page ) ) {
		$classes[] = 'wcvendors wcv-orders-page';
	}

	if ( is_page( $shop_settings ) ) {
		$classes[] = 'wcvendors wcv-shop-settings-page';
	}

	if ( is_page( $terms_page ) ) {
		$classes[] = 'wcvendors wcv-terms-page';
	}

	return $classes;

} // body_class()
add_filter( 'body_class', 'kleo_wcv_body_class', 999 );
