<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

/**
 * Shortcode attributes
 * @var $atts
 * @var $source
 * @var $text
 * @var $link
 * @var $google_fonts
 * @var $font_container
 * @var $el_class
 * @var $el_id
 * @var $css
 * @var $css_animation
 * @var $font_container_data - returned from $this->getAttributes
 * @var $google_fonts_data - returned from $this->getAttributes
 * Shortcode class
 * @var $this WPBakeryShortCode_VC_Custom_heading
 */
$source = $text = $link = $google_fonts = $font_container = $el_id = $el_class = $css = $css_animation = $font_container_data = $google_fonts_data = '';

// This is needed to extract $font_container_data and $google_fonts_data
extract( $this->getAttributes( $atts ) );

$atts = vc_map_get_attributes( $this->getShortcode(), $atts );
extract( $atts );


/**
 * @var $css_class
 */
extract( $this->getStyles( $el_class . $this->getCSSAnimation( $css_animation ), $css, $google_fonts_data, $font_container_data, $atts ) );

$settings = get_option( 'wpb_js_google_fonts_subsets' );
if ( is_array( $settings ) && ! empty( $settings ) ) {
	$subsets = '&subset=' . implode( ',', $settings );
} else {
	$subsets = '';
}

if ( ( ! isset( $atts['use_theme_fonts'] ) || 'yes' !== $atts['use_theme_fonts'] ) && isset( $google_fonts_data['values']['font_family'] ) ) {
	wp_enqueue_style( 'vc_google_fonts_' . vc_build_safe_css_class( $google_fonts_data['values']['font_family'] ), '//fonts.googleapis.com/css?family=' . $google_fonts_data['values']['font_family'] . $subsets );
}

$attributes = array();

if ( ! empty( $styles ) ) {
	$attributes['style'] = esc_attr( implode( ';', $styles ) );
}

if ( 'post_title' === $source ) {
	$text = get_the_title( get_the_ID() );
}

if ( ! empty( $link ) ) {
	$link = vc_build_link( $link );
	$text = '<a href="' . esc_attr( $link['url'] ) . '"' . ( $link['target'] ? ' target="' . esc_attr( $link['target'] ) . '"' : '' ) . ( $link['rel'] ? ' rel="' . esc_attr( $link['rel'] ) . '"' : '' ) . ( $link['title'] ? ' title="' . esc_attr( $link['title'] ) . '"' : '' ) . '>' . $text . '</a>';
}

/* Responsive text */

if ( isset( $responsive_font ) && 'yes' == $responsive_font ) {
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

	echo '<style>' . $text_css . '</style>';
}

if ( apply_filters( 'vc_custom_heading_template_use_wrapper', false ) ) {
    ?>
    <div class="<?php echo esc_attr( $css_class ); ?>" id="<?php echo esc_attr( $atts['el_id'] ); ?>">
        <<?php echo esc_attr($font_container_data['values']['tag']); ?> <?php echo kleo_get_attributes_string( $attributes ); ?> data-uid="<?php echo esc_attr( $text_id ); ?>">
            <?php echo wp_kses_post( $text ); ?>
        </<?php echo esc_attr($font_container_data['values']['tag']); ?>>
    </div>
    <?php
} else {
    ?>
    <<?php echo esc_attr($font_container_data['values']['tag']); ?> <?php echo kleo_get_attributes_string( $attributes ); ?> data-uid="<?php echo esc_attr( $text_id ); ?>" class="<?php echo esc_attr( $css_class ); ?>" id="<?php echo esc_attr( $atts['el_id'] ); ?>">
        <?php echo wp_kses_post( $text ); ?>
    </<?php echo esc_attr($font_container_data['values']['tag']); ?>>
    <?php
}
