<?php
/**
 * FEATURE ITEM
 * [kleo_feature_item]Text[/kleo_feature_item]
 *
 * @package WordPress
 * @subpackage K Elements
 * @author SeventhQueen <themesupport@seventhqueen.com>
 * @since K Elements 1.0
 */


$output = $title = $icon = $icon_size = $icon_position = $class = '';
extract( shortcode_atts( array(
	'title'         => '',
	'href'          => '',
	'target'          => '',
	'icon'          => '',
	'icon_size'     => 'default',
	'icon_position' => 'left',
	'icon_color'    => '',
	'class'         => '',
), $atts ) );

global $kleo_grid_font_size;
$title_attr = '';
if ( '' != $kleo_grid_font_size ) {
	$title_attr = ' style="font-size: ' . kleo_set_default_unit( $kleo_grid_font_size ) . ' !important;"';
}

$class = ( $class != '' ) ? 'kleo-block feature-item list-el-animated ' . esc_attr( $class ) : 'kleo-block feature-item list-el-animated';

$icon = str_replace( 'icon-', '', $icon );
$icon = ( $icon != '' ) ? ' icon-' . $icon : '';

$class .= ( $icon_size != '' ) ? " " . $icon_size . '-icons-size' : '';
$class .= ( $icon_position == 'center' ) ? " center-icons" : '';

$data_attr = '';

if ( $href !== '' ) {
	$class .= ' kleo-open-href';
	$data_attr .= ' data-href="' . esc_url( $href ) . '"';
	if ($target === 'blank') {
		$data_attr .= ' data-target="blank"';
	}
}

$output .= '<div class="' . $class . '"' . $data_attr . '>';
$output .= '<span class="feature-icon el-appear' . $icon . '"' . ( $icon_color != '' ? 'style="color:' . $icon_color . '"' : '' ) . '></span>';
$output .= '<h3 class="feature-title"' . $title_attr . '>' . $title . '</h3>';
$output .= '<div class="feature-text">' . do_shortcode( $content ) . '</div>';
$output .= '</div>';

