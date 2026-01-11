<?php

// Remove some notices
add_action( 'init', function () {
	if ( function_exists( 'yz_display_new_extension_notice' ) ) {
		if ( is_admin() && ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'youzer-panel' ) ) {
			remove_action( 'admin_notices', 'yz_display_new_extension_notice' );
		}
	}
} );


/* bbPress re-add the social share */
function kleo_yz_social_share() {
	get_template_part( 'page-parts/posts-social-share' );
}

add_action( 'bbp_template_after_single_topic', 'kleo_yz_social_share' );
add_action( 'bbp_template_after_single_forum', 'kleo_yz_social_share' );

add_action( 'wp', 'kleo_youzer_enable_activity' );
function kleo_youzer_enable_activity() {

	if ( kleo_has_shortcode( 'kleo_bp_activity' ) ) {
		add_filter( 'yz_is_activity_component', '__return_true' );
	}
}

add_action( 'wp_footer', 'kleo_youzer_add_bp_css' );
function kleo_youzer_add_bp_css() {

	if ( kleo_has_shortcode( 'kleo_bp' ) && ! kleo_has_shortcode( 'kleo_bp_activity' ) ) {
		wp_enqueue_style( 'bp-parent-css' );
	}
}

// Kleo Theme Fixes from Youzer plugin
add_filter( 'enable_kleo_bp_dir_send_private_message_button', '__return_false' );

function kleo_youzer_remove_kleo_bp_css() {
	wp_dequeue_style( 'bp-parent-css' );
	wp_dequeue_style( 'kleo-bbpress' );
}

add_action( 'wp_enqueue_scripts', 'kleo_youzer_remove_kleo_bp_css', 999 );

function kleo_youzer_remove_bp_dynamic_style() {

	remove_filter( 'kleo_dynamic_main', 'kleo_buddypress_dynamic_style', 12 );

	if ( is_buddypress() ) {
		remove_action( 'wp_enqueue_scripts', 'kleo_load_woocommerce_css', 20 );
	}

	remove_action( 'bbp_enqueue_scripts', 'kleo_bbpress_register_style', 15 );

}

add_action( 'after_setup_theme', 'kleo_youzer_remove_bp_dynamic_style', 14 );


add_action( 'kleo_before_main', function () {
	if ( ! bp_is_blog_page() ) {
		echo '<div id="buddypress">';
	}

} );
add_action( 'kleo_after_main', function () {
	if ( ! bp_is_blog_page() ) {
		echo '</div>';
	}
} );