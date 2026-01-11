<?php
/**
 * Buddypress Groups Masonry.
 *
 *
 * @package WordPress
 * @subpackage K Elements
 * @author SeventhQueen <themesupport@seventhqueen.com>
 * @since K Elements 1.0
 */

$output = $avatarsize = $type = $number = $width_height = $rounded = '';

extract(
	shortcode_atts( array(
		'type'         => 'newest',
		'number'       => 12,
		'class'        => '',
		'rounded'      => "rounded",
		'avatarsize'   => '',
		'width_height' => ''
	), $atts )
);

$params = array(
	'type'     => $type,
	'per_page' => $number
);


$avatar_width = $avatar_height = '';
if ( $avatarsize === 'large' ) {
	$avatar_size_wh = explode( 'x', $width_height );
	if ( isset( $avatar_size_wh[0] ) && isset( $avatar_size_wh[1] ) ) {
		$avatar_width  = $avatar_size_wh[0];
		$avatar_height = $avatar_size_wh[1];
	}
}

if ( function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) ) {

	ob_start();

	$current_component = static function () {
		return 'groups';
	};

	$loop_classes = static function () {
		return [
			'item-list',
			'groups-list',
			'bp-list',
			'grid',
			'three',
		];
	};

	$avatar_filter = static function ( $args ) use ( $rounded, $avatar_width, $avatar_height ) {

		if ( ! isset( $args['class'] ) ) {
			$args['class'] = 'avatar ';
		}

		$args['class'] .= ( $rounded === 'rounded' ) ? 'bp-rounded-avatar' : 'bp-square-avatar';


		if ( $avatar_width && $avatar_height ) {
			$args['width']  = $avatar_width;
			$args['height'] = $avatar_height;
		}

		return $args;
	};

	$query_filter = static function ( $query ) use ( $params ) {
		//$query = add_query_arg( 'type', $params['type'], $query );
		$query = add_query_arg( 'per_page', $params['per_page'], $query );

		return $query;
	};

	add_filter( 'bp_current_component', $current_component );
	add_filter( 'bp_ajax_querystring', $query_filter );

	add_filter( 'bp_nouveau_get_loop_classes', $loop_classes );
	add_filter( 'bp_nouveau_avatar_args', $avatar_filter );

	add_filter( 'bp_get_groups_pagination_count', '__return_empty_string' );
	add_filter( 'bp_get_groups_pagination_links', '__return_empty_string' );

	?>

    <div class="screen-content">
        <div id="groups-dir-list" class="groups dir-list" data-bp-list="">
			<?php bp_get_template_part( 'groups/groups-loop' ); ?>
        </div>
    </div>

	<?php
	$output = ob_get_clean();


	remove_filter( 'bp_current_component', $current_component );
	remove_filter( 'bp_ajax_querystring', $query_filter );

	remove_filter( 'bp_nouveau_avatar_args', $avatar_filter );
	remove_filter( 'bp_get_groups_pagination_count', '__return_empty_string' );
	remove_filter( 'bp_get_groups_pagination_links', '__return_empty_string' );
	?>

	<?php

} else {
	$output = __( "This shortcode must have Buddypress installed to work.", "k-elements" );
}
