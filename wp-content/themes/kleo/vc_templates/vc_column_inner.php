<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

/**
 * Shortcode attributes
 * @var $atts
 * @var $el_class
 * @var $el_id
 * @var $width
 * @var $css
 * @var $offset
 * @var $content - shortcode content
 * Shortcode class
 * @var $this WPBakeryShortCode_VC_Column_Inner
 */
$el_class = $width = $el_id = $css = $offset = '';

/* KLEO ADDED */
$bg_gradient = '';

$atts = vc_map_get_attributes( $this->getShortcode(), $atts );
extract( $atts );

$width = kleo_translateColumnWidth( $width );
$width = vc_column_offset_class_merge( $offset, $width );

$extra_inner_class = $bg_gradient != '' ? ' kleo-gradient' : '';

$css_classes = array(
	$this->getExtraClass( $el_class ),
	'wpb_column',
	'vc_column_container',
	$width,
);

if ( vc_shortcode_custom_css_has_property( $css, array(
	'border',
	'background',
) ) ) {
	$css_classes[]='vc_col-has-fill';
}

$css_class = preg_replace( '/\s+/', ' ', apply_filters( VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG, implode( ' ', array_filter( $css_classes ) ), $this->settings['base'], $atts ) );
?>

<div class="<?php echo esc_attr( trim( $css_class ) ); ?>"<?php echo !empty( $el_id ) ? ' id="' . esc_attr( $el_id ) . '"' : ''; ?>>
    <div class="vc_column-inner <?php echo esc_attr( trim( vc_shortcode_custom_css_class( $css ) ) . $extra_inner_class ); ?>">
        <div class="wpb_wrapper">
            <?php echo wpb_js_remove_wpautop( $content ); ?>
        </div>
    </div>
</div>
