<?php
/**
 * The Sidebar containing the main widget area
 *
 * @package WordPress
 * @subpackage Kleo
 * @since Kleo 1.0
 */
?>
<?php
$sidebar_classes = apply_filters( 'kleo_sidebar_classes', '' );
$sidebar_name = apply_filters( 'kleo_sidebar_name', 'sidebar-1' );
?>

<div class="sidebar sidebar-main <?php echo esc_attr( $sidebar_classes ); ?>">
	<div class="inner-content widgets-container">
		<?php
		if ( function_exists( 'generated_dynamic_sidebar' ) ) {
			generated_dynamic_sidebar( $sidebar_name );
		} else {
			dynamic_sidebar( $sidebar_name );
		}
		?>
	</div><!--end inner-content-->
</div><!--end sidebar-->
