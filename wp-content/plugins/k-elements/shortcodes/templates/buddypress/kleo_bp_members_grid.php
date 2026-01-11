<?php
/**
 * Buddypress Members Grid
 * 
 * 
 * @package WordPress
 * @subpackage K Elements
 * @author SeventhQueen <themesupport@seventhqueen.com>
 * @since K Elements 1.0
 */


$output = $anim1 = '';

extract( 
	shortcode_atts( array(
			'type' => 'newest',
			'member_type' => 'all',
			'number' => 12,
			'perline' => '',
			'animation' => '',
			'rounded' => "",
            'avatarsize' => '',
            'width_height' => '',
			'class' => ''
	), $atts )
);

$params = array(
	'type' => $type,
	'scope' => $member_type,
	'per_page' => $number,

);

if($perline != '') {
	$class .= ' ' . $perline . '-thumbs';
}

if ($animation != '') {
	$anim1 = ' animate-when-almost-visible';
	$class .= ' kleo-thumbs-animated th-' . $animation;
}

if ($rounded == 'rounded') {
	$class .= ' rounded';
}

$avatarquery = '';
if( $avatarsize == 'large' ) {
	$avatarsizewh = explode( 'x', $width_height );
	if( isset( $avatarsizewh[0] ) && isset($avatarsizewh[1] ) ) {
		$avatar_width = $avatarsizewh[0];
		$avatar_height = $avatarsizewh[1];
        $avatarquery = 'type=full&width='.$avatar_width.'&height='.$avatar_height.' ';
    }
}

if ( function_exists('bp_is_active') ) {
	// begin bp members loop
	if ( bp_has_members( $params ) ){
			$output .= '<div class="wpb_wrapper">';
			$output .= '<div class="kleo-gallery'.$anim1.'">';
			$output .= '<div class="kleo-thumbs-images '.$class.'">';
				while( bp_members() ){
	
						bp_the_member();
						$output .= '<a href="'. bp_get_member_permalink() .'" title="'. bp_get_member_name() .'">';
								$output .= bp_get_member_avatar( $avatarquery );
								$output .= kleo_get_img_overlay();
						$output .= '</a>';	
	
				}
			$output .= '</div>';	
			$output .= '</div>';
			$output .= '</div>';
	}
}
else
{
	$output = __("This shortcode must have Buddypress installed to work.","k-elements");
} 
