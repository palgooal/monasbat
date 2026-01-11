<?php
/**
 * Before content wrap
 * Used in all templates
 */
?>
<?php
$main_tpl_classes = apply_filters( 'kleo_main_template_classes', '' );
$container = apply_filters( 'kleo_main_container_class', 'container' );

/**
 * Before main content - action
 */
do_action( 'kleo_before_content' );

?>

<section class="container-wrap main-color">
	<div id="main-container" class="<?php echo esc_attr( $container ); ?>">
		<?php if ( 'container' === $container ) { ?><div class="row"><?php } ?>

			<div <?php echo kleo_has_shortcode( 'kleo_bp_' ) ? 'id="buddypress"' : ''; ?>class="template-page <?php echo esc_attr( $main_tpl_classes ); ?>">
				<div class="wrap-content">
					
				<?php
				/**
				 * Before main content - action
				 */
				do_action( 'kleo_before_main_content' );
				?>
