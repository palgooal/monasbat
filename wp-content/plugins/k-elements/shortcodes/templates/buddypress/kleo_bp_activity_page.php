<?php
/**
 * Buddypress Activity Page
 *
 *
 * @package WordPress
 * @subpackage K Elements
 * @author SeventhQueen <themesupport@seventhqueen.com>
 * @since K Elements 1.5
 */

extract(
	shortcode_atts(
		array(), $atts
	)
);

if ( function_exists( 'bp_is_active' ) && bp_is_active( 'activity' ) ) {

	if ( function_exists( 'youzer' ) || function_exists( 'youzify' ) ) {
		$output = do_shortcode( '[youzify_activity]' );

		return;
	}

	$current_component = static function () {
		return 'activity';
	};
	add_filter( 'bp_current_component', $current_component );

	wp_enqueue_script( 'bp-nouveau-activity' );

	$output = '';
	$output .= '<div class="buddypress">';
	$output .= '<div id="buddypress">';
	$output .= '<div class="buddypress-wrap kleo-activity-page">';

	ob_start();
	get_template_part( 'buddypress/activity/index' );
	$output .= ob_get_clean();

	$output .= '</div>';
	$output .= '</div>';
	$output .= '</div>';

} else {
	$output = __( "This shortcode must have Buddypress installed and activity component activated.", "k-elements" );
}
