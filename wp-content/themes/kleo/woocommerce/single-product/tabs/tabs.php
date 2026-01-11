<?php
/**
 * Single Product tabs
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/tabs/tabs.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version 3.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter tabs and allow third parties to add their own.
 *
 * Each tab is an array containing title, callback and priority.
 *
 * @see woocommerce_default_product_tabs()
 */
$product_tabs = apply_filters( 'woocommerce_product_tabs', array() );

if ( ! empty( $product_tabs ) ) : ?>

    <div id="accordion-woo" class="panel-group panel-kleo icons-to-right" data-active-tab="1">

		<?php
		$count_tab = 0;
		foreach ( $product_tabs as $key => $product_tab ) : $count_tab ++;
		$toggle_class = 'accordion-toggle ';
		$toggle_class .= $count_tab === 1 ? 'active' : 'collapsed';
		?>
            <div class="panel">
                <div class="panel-heading">
                    <div class="panel-title">
                        <a class="<?php echo esc_attr( $toggle_class ); ?>" data-toggle="collapse" data-parent="#accordion-woo"
                           href="#<?php echo esc_attr( $key ); ?>_tab">
							<?php echo wp_kses_post( apply_filters( 'woocommerce_product_' . $key . '_tab_title', $product_tab['title'], $key ) ); ?>
                            <span class="icon-closed icon-angle-down <?php if ( $count_tab == 1 ) {
								echo ' hide';
							} ?>"></span>
                            <span class="icon-opened icon-angle-up<?php if ( $count_tab != 1 ) {
								echo ' hide';
							} ?>"></span>
                        </a>
                    </div>
                </div>
                <div id="<?php echo esc_attr( $key ); ?>_tab" class="panel-collapse<?php if ( $count_tab === 1 ) {
					echo ' in';
				} else {
					echo ' collapse';
				} ?>">
                    <div class="panel-body">
						<?php
						if ( isset( $product_tab['callback'] ) ) {
							call_user_func( $product_tab['callback'], $key, $product_tab );
						}
						?>
                    </div>
                </div>
            </div>
		<?php endforeach; ?>

		<?php do_action( 'woocommerce_product_after_tabs' ); ?>

    </div>

<?php endif; ?>
