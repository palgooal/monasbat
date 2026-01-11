<?php
/**
 * The template for displaying the footer
 *
 * Contains footer content and the closing of the #main and #page div elements.
 *
 * @package WordPress
 * @subpackage Kleo
 * @since Kleo 1.0
 */

?>
	<?php
	/**
	 * After main part - action
	 */
	do_action( 'kleo_after_main' );
	?>
	</div><!-- #main -->

    <?php
    /**
     * Footer hook
     *
     * @hooked kleo_show_footer
     * @uses get_sidebar( 'footer' )
     */
    do_action( 'kleo_footer' );
    ?>

	<?php
	/**
	 * After footer hook
	 *
	 * @hooked kleo_go_up
	 * @hooked kleo_show_contact_form
	 * @hooked kleo_show_socket
	 */
	do_action( 'kleo_after_footer' );
	?>

	</div><!-- #page -->

	<?php
	/**
	 * After page hook
	 *
	 * @hooked kleo_show_side_menu 10
	 */
	do_action( 'kleo_after_page' );
	?>

	<!-- Analytics -->
	<?php echo sq_option( 'analytics', '' ); ?>

	<?php wp_footer(); ?>

	</body>
</html>
