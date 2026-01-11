<?php
/**
 * GRID Shortcode
 * [kleo_grid]Text[/kleo_grid]
 * 
 * @package WordPress
 * @subpackage K Elements
 * @author SeventhQueen <themesupport@seventhqueen.com>
 * @since K Elements 1.0
 */

$style = $type = $box_shadow = $el_class = $colored_icons = $bordered_icons = $title_only = $title_size = $divided = '';

extract( shortcode_atts( array(
	'el_class' => '',
	'type' => '1',
	'colored_icons' => '',
	'bordered_icons' => '',
	'style' => '',
	'divided' => '',
	'title_only' => '',
	'title_size' => '',
	'box_shadow' => '',
	'animation' => '',
), $atts ) );

global $kleo_grid_font_size;
$kleo_grid_font_size = $title_size;


$class = ( $el_class != '' ) ? ' row multi-columns-row ' . esc_attr( $el_class ) : 'row multi-columns-row';

if ( $colored_icons == 'yes' ) {
	$class .= ' colored-icons';
}
if ( $bordered_icons == 'yes' ) {
	$class .= ' bordered-icons';
}

if ( $style != '' ) {
	$class .= ' ' . $style . '-style';
}


if ( $type === '1' ) {
	$class .= ' one-column-items';
} elseif($type === '2') {
	$class .= ' two-column-items';
} elseif($type === '3') {
	$class .= ' three-column-items';
} elseif($type === '4') {
	$class .= ' four-column-items';
}

if ($divided != '' ) {
	$class .= ' divided-items';
}

if ($box_shadow != '') {
	$class .= ' box-shadow';
}

if ( $title_only != '' ) {
	$class .= ' title-only';
}


$col = floor( 12 / $type );

//Find items
$innersh = '';
$sh = preg_match_all( '~\[(\[?)(kleo_feature_item)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)~s', $content, $childs );

if ( $sh && isset( $childs[0] ) && ! empty( $childs[0] ) ) {

	foreach ( $childs[0] as $child ) {
		$innersh .= '<div class="feature-items col-xs-12 ' . ( $type > 1 ? 'col-sm-6 ' : ' ' ) . 'col-md-' . $col . '">';
		$innersh .= do_shortcode( $child );
		$innersh .= '</div>';
	}
}

$output .= "<div class=\"{$class}\">"; 
if ( $animation == 'yes' ) {
	$output .='<div class="one-by-one-animated animate-when-almost-visible">';
}

$output .= $innersh;

if ($animation == 'yes') {
	$output .= "</div>";
}

$output .= "</div>";