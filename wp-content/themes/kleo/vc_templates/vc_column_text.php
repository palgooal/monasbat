<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}
/**
 * Shortcode attributes
 * @var $atts
 * @var $el_class
 * @var $el_id
 * @var $css
 * @var $content - shortcode content
 * Shortcode class
 * @var $this WPBakeryShortCode_VC_Column_text
 */

$el_class = $animation = $css = $css_animation = $lead = $text_color = $font_size = $line_height = $responsive_font = $font_size_xs = $line_height_xs =
$font_size_sm = $line_height_sm = $font_size_md = $line_height_md = $font_weight = $el_id = '';

$atts = vc_map_get_attributes( $this->getShortcode(), $atts );
extract( $atts );

$class_to_filter = 'kleo_text_column wpb_text_column wpb_content_element ';
$class_to_filter .= vc_shortcode_custom_css_class( $css, ' ' ) . $this->getExtraClass( $el_class );
$css_class = apply_filters( VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG, $class_to_filter, $this->settings['base'], $atts );
$wrapper_attributes = array();
if ( ! empty( $el_id ) ) {
	$wrapper_attributes[] = 'id="' . esc_attr( $el_id ) . '"';
}

if ( $animation != '' ) {
	wp_enqueue_script( 'waypoints' );
	$css_class .= " animated {$animation} {$css_animation}";
}

if ( $lead ) {
	$css_class .= ' lead';
}

/* Custom inline styles */
$style_inline = '';
$styles       = '';

if ( $font_size != '' ) {
	$styles .= ' font-size:' . kleo_set_default_unit( $font_size ) . ';';
}
if ( $line_height != '' ) {
	$styles .= ' line-height:' . kleo_set_default_unit( $line_height ) . ';';
}
if ( $font_weight && $font_weight != 'normal' ) {
	$styles .= ' font-weight:' . $font_weight . ';';
}
if ( $text_color != '' ) {
	$styles .= ' color:' . $text_color . ';';
}

if ( $styles != '' ) {
	$style_inline = ' style="' . $styles . '"';
}

/* Responsive text */
$text_data = '';
if ( $responsive_font == 'yes' ) {
	$text_id  = uniqid();
	$text_css = '';
	$text_el  = '[data-uid="' . esc_attr( $text_id ) . '"]';

	if ( $font_size_xs != '' || $line_height_xs != '' ) {
		$text_css .= '@media (max-width: 767px) { ' .
		             $text_el . '{';

		if ( $font_size_xs != '' ) {
			$text_css .= 'font-size: ' . kleo_set_default_unit( $font_size_xs ) . ' !important;';
		}

		if ( $line_height_xs != '' ) {
			$text_css .= 'line-height: ' . kleo_set_default_unit( $line_height_xs ) . ' !important;';
		}
		$text_css .= '} }';
	}

	if ( $font_size_sm != '' || $line_height_sm != '' ) {
		$text_css .= '@media (min-width: 768px) and (max-width: 991px) { ' .
		             $text_el . '{';

		if ( $font_size_sm != '' ) {
			$text_css .= 'font-size: ' . kleo_set_default_unit( $font_size_sm ) . ' !important;';
		}

		if ( $line_height_sm != '' ) {
			$text_css .= 'line-height: ' . kleo_set_default_unit( $line_height_sm ) . ' !important;';
		}
		$text_css .= '} }';
	}

	if ( $font_size_md != '' || $line_height_md != '' ) {
		$text_css .= '@media (min-width: 992px) and (max-width: 1199px) { ' .
		             $text_el . '{';

		if ( $font_size_md != '' ) {
			$text_css .= 'font-size: ' . kleo_set_default_unit( $font_size_md ) . ' !important;';
		}

		if ( $line_height_md != '' ) {
			$text_css .= 'line-height: ' . kleo_set_default_unit( $line_height_md ) . ' !important;';
		}
		$text_css .= '} }';
	}

	$text_data = ' data-uid="' . esc_attr( $text_id ) . '"';
	echo '<style>' . $text_css . '</style>';
}

?>
<div class="<?php echo esc_attr($css_class); ?>" <?php echo implode(' ', $wrapper_attributes) . $style_inline . $text_data; ?>>
    <div class="wpb_wrapper">
        <?php echo wpb_js_remove_wpautop($content, true); ?>
    </div>
</div>
