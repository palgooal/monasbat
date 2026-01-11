<?php
/**
 * Buddypress Members masonry
 *
 *
 * @package WordPress
 * @subpackage K Elements
 * @author SeventhQueen <themesupport@seventhqueen.com>
 * @since K Elements 1.0
 */


$output = $rounded = $type = $member_type = $number = $width_height = $avatarsize = $online = '';

extract(
	shortcode_atts( array(
		'type'         => 'newest',
		'member_type'  => 'all',
		'number'       => 12,
		'class'        => '',
		'rounded'      => "rounded",
		'avatarsize'   => '',
		'width_height' => '',
		'online'       => 'show'
	), $atts )
);

$avatar_width = $avatar_height = '';
if ( $avatarsize === 'large' ) {
	$avatar_size_wh = explode( 'x', $width_height );
	if ( isset( $avatar_size_wh[0] ) && isset( $avatar_size_wh[1] ) ) {
		$avatar_width  = $avatar_size_wh[0];
		$avatar_height = $avatar_size_wh[1];
	}
}

if ( function_exists( 'bp_is_active' ) ) {

	$current_component = static function () {
		return 'members';
	};

	$loop_class_filter = static function () {
		return [ 'item-list', 'members-list', 'bp-list', 'grid', 'four' ];
	};

	$query_filter = static function ( $query ) use ( $type, $member_type, $number ) {
		$query = add_query_arg( 'type', $type, $query );
		$query = add_query_arg( 'scope', $member_type, $query );
		$query = add_query_arg( 'per_page', $number, $query );

		return $query;

	};

	$avatar_filter = static function ( $args ) use ( $rounded, $online, $avatar_width, $avatar_height ) {

		if ( ! isset( $args['class'] ) ) {
			$args['class'] = 'avatar ';
		}

		$args['class'] .= ( $rounded === 'rounded' ) ? 'bp-rounded-avatar' : 'bp-square-avatar';
		$args['class'] .= ( $online === 'show' ) ? ' bp-show-online' : ' bp-hide-online';


		if ( $avatar_width && $avatar_height ) {
			$args['width']  = $avatar_width;
			$args['height'] = $avatar_height;
		}

		return $args;
	};

	add_filter( 'bp_current_component', $current_component );
	add_filter( 'bp_nouveau_get_loop_classes', $loop_class_filter );
	add_filter( 'bp_ajax_querystring', $query_filter );
	add_filter( 'bp_nouveau_avatar_args', $avatar_filter );

	add_filter( 'bp_members_pagination_count', '__return_empty_string' );
	add_filter( 'bp_get_members_pagination_links', '__return_empty_string' );

	ob_start();
	?>
    <div class="screen-content">
        <div id="members-dir-list" class="members dir-list" data-bp-list="">
            <?php bp_get_template_part( 'members/members-loop' ); ?>
        </div>

    </div>
    <?php

	$output = ob_get_clean();

	remove_filter( 'bp_members_pagination_count', '__return_empty_string' );
	remove_filter( 'bp_get_members_pagination_links', '__return_empty_string' );

	remove_filter( 'bp_nouveau_get_loop_classes', $loop_class_filter );
	remove_filter( 'bp_ajax_querystring', $query_filter );
	remove_filter( 'bp_nouveau_avatar_args', $avatar_filter );
	remove_filter( 'bp_current_component', $current_component );

} else {
	$output = __( "This shortcode must have Buddypress installed to work.", "k-elements" );
}
