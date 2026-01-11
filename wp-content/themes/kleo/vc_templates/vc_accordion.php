<?php

extract( shortcode_atts( array(
	'el_class'       => '',
	'collapsible'    => 'no',
	'active_tab'     => '1',
	'icons_position' => 'to-left'
), $atts ) );

global $kleo_acc_id, $kleo_acc_active_tab, $kleo_acc_count;
$kleo_acc_id         = kleo_vc_elem_increment();
$kleo_acc_active_tab = $active_tab;
$kleo_acc_count      = 0;

$css_class = 'panel-group panel-kleo ' . trim( $el_class );
$css_class .= ' icons-' . $icons_position;

$attributes = [
	'class' => esc_attr( $css_class ),
	'data-active-tab' => esc_attr( $active_tab ),
];

if ( $collapsible != 'yes' ) {
	$attributes['id'] = 'accordion-' . esc_attr( $kleo_acc_id );
}
?>

<div <?php echo kleo_get_attributes_string( $attributes ); ?>>
    <?php echo wpb_js_remove_wpautop( $content ); ?>
</div>
