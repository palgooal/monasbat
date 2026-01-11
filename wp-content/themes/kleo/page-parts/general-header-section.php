<?php
/**
 * Header section of our theme
 *
 * Displays all of the <div id="header"> section
 *
 * @package WordPress
 * @subpackage Kleo
 * @since Kleo 1.0
 */

//set logo path
$logo_path = sq_option_url( 'logo' );
$logo_path = apply_filters( 'kleo_logo', $logo_path );
$logo_href = apply_filters( 'kleo_logo_href', home_url() ) ;
$logo_title = apply_filters( 'kleo_logo_title', get_bloginfo( 'name' ) ) ;

$social_icons = apply_filters( 'kleo_show_social_icons', sq_option( 'show_social_icons', 1 ) );
$top_bar = sq_option( 'show_top_bar', 1 );
$top_bar = apply_filters( 'kleo_show_top_bar', $top_bar );
$top_bar_container_class = 'top-menu no-padd';
if ( sq_option( 'top_bar_flex', 1 ) ) {
	$top_bar_container_class .= ' top-menu-flex';
}

$uuid = bin2hex( openssl_random_pseudo_bytes( 4 ) );
?>

<div id="header" class="header-color">

	<div class="navbar" role="navigation">

		<?php if ( $top_bar == 1 ) : /* top bar enabled */ ?>

			<div class="social-header header-color">
				<div class="container">
					<div class="top-bar top-bar-flex">

						<?php
						$social_icons_data  = kleo_get_social_profiles();

						//empty data or disabled social icons
						if ( $social_icons && $social_icons_data ) : ?>

                            <div id="top-social" class="no-padd">
                                <?php echo kleo_get_social_profiles(); ?>
                            </div>

                        <?php endif; ?>

						<?php
						// Top menu
						wp_nav_menu( array(
							'theme_location'    => 'top',
							'depth'             => 3,
							'container'         => 'div',
							'container_class'   => $top_bar_container_class,
							'menu_class'        => '',
							'fallback_cb'       => '',
							'walker'            => new kleo_walker_nav_menu(),
							'echo'              => true
						) );
						?>

					</div><!--end top-bar-->
				</div>
			</div>

		<?php endif; /* end top bar condition */ ?>

		<?php
		$header_style = sq_option( 'header_layout', 'normal' );
		if ( $header_style == 'right_logo' ) {
			$header_class = ' logo-to-right';
		} elseif ( $header_style == 'center_logo' ) {
			$header_class = ' header-centered';
		} elseif ( $header_style == 'left_logo' ) {
			$header_class = ' header-left';
		} elseif( $header_style == 'extras' ) {
			$header_class = ' header-extras';
		} elseif( $header_style == 'split' ) {
			$header_class = ' header-split';
		} elseif( $header_style == 'lp' ) {
			$header_class = ' header-lp';
		} elseif( $header_style == 'split_side' ) {
			$header_class = ' header-split header-split-side';
		} else {
			$header_class = ' header-normal';
		}
		?>
		<div class="kleo-main-header<?php echo esc_attr( $header_class ); ?>">
			<div class="container">
				<!-- Brand and toggle get grouped for better mobile display -->
				<div class="navbar-header">
					<div class="kleo-mobile-switch">

						<?php

						$mobile_menu_atts = array(
							'class' => 'navbar-toggle',
							'data-toggle' => 'collapse',
							'data-target' => '.nav-' . $uuid
						);
						
						/* open the Side menu instead of the normal menu */
						if ( sq_option( 'side_menu', 0 ) == 1 && sq_option( 'side_menu_mobile', 0 ) == 1 ) {
							$mobile_menu_atts = array(
								'class' => 'navbar-toggle open-sidebar'
							);
						}
						?>
						<button type="button" <?php echo kleo_get_attributes_string($mobile_menu_atts); ?>>
							<span class="sr-only"><?php esc_html_e( "Toggle navigation", 'kleo' ); ?></span>
							<span class="icon-bar"></span>
							<span class="icon-bar"></span>
							<span class="icon-bar"></span>
						</button>
					</div>

					<div class="kleo-mobile-icons">

						<?php
						/** kleo_mobile_header_icons - action
						 * You can put here various icons using this action
						 *
						 * @hooked kleo_bp_mobile_notify - 9
						 * @hooked kleo_woo_mobile_icon - 10
						 */
						do_action( 'kleo_mobile_header_icons' );
						?>

					</div>

					<strong class="logo">
						<a href="<?php echo esc_url( $logo_href ); ?>">

							<?php if ( $logo_path != '' ) { ?>

								<img id="logo_img" title="<?php echo esc_attr( $logo_title ); ?>" src="<?php echo esc_attr( $logo_path ); ?>"
								     alt="<?php echo esc_attr( $logo_title ); ?>">

							<?php } else { ?>

								<?php echo esc_html( $logo_title ); ?>

							<?php } ?>

						</a>
					</strong>
				</div>

				<?php if ( $header_style == 'left_logo' ) : ?>
					<div class="header-banner">
						<?php echo do_shortcode( sq_option( 'header_banner', '' ) ); ?>
					</div>
				<?php endif; ?>

				<?php
				$menu_wrap_enabled = false;
				if ( $header_style == 'center_logo' || $header_style == 'left_logo' ) {
					$menu_wrap_enabled = TRUE;
					echo '<div class="menus-wrap">';
				}

				// Main menu
				wp_nav_menu( 
					array(
						'theme_location'    => 'primary',
						'depth'             => 6,
						'container'         => 'div',
						'container_class'   => 'primary-menu collapse navbar-collapse nav-collapse nav-' . $uuid,
						'menu_class'        => 'nav navbar-nav',
						//'fallback_cb'       => 'kleo_walker_nav_menu::fallback',
						'fallback_cb'       => '',
						'walker'            => new kleo_walker_nav_menu(),
						'echo'              => true
					) 
				);

				// Secondary menu
				wp_nav_menu( 
					array(
						'theme_location'    => 'secondary',
						'depth'             => 6,
						'container'         => 'div',
						'container_class'   => 'secondary-menu collapse navbar-collapse nav-collapse',
						'menu_class'        => 'nav navbar-nav',
						//'fallback_cb'       => 'kleo_walker_nav_menu::fallback',
						'fallback_cb'       => '',
						'walker'            => new kleo_walker_nav_menu(),
						'echo'              => true
					)
				);
	
				if ( $menu_wrap_enabled == TRUE ) {
					echo '</div>';
				}
				?>
			</div><!--end container-->
		</div>
	</div>

</div><!--end header-->
