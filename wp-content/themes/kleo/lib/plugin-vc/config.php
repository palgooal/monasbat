<?php

/* Add some shortcodes to VC interface */
add_action( 'vc_before_init', 'kleo_vc_theme_mapping' );
function kleo_vc_theme_mapping() {

	if ( ! shortcode_exists( 'mc4wp_form' ) ) {
		return;
	}
	/* MailChimp4WP shortcode */

	$posts = array( 'Last added' => '' );
	$args = array(
		'post_type' => 'mc4wp-form',
	);
	$the_query = new WP_Query( $args );

	// The Loop
	if ( $the_query->have_posts() ) {
		while ( $the_query->have_posts() ) {
			$the_query->the_post();
			$posts[ get_the_title() ] = get_the_ID();
		}
		/* Restore original Post Data */
		wp_reset_postdata();
	} else {
		// no posts found
	}

	vc_map(
		array(
			'name' => esc_html__( 'MailChimp 4 WP Form', 'kleo' ),
			'base' => 'mc4wp_form',
			'class' => '',
			'category' => esc_html__( 'Content', 'kleo' ),
			'icon' => 'mc4wp',
			'description' => esc_html__( 'Displays a mailchimp signup form', 'kleo' ),
			'params' => array(
				array(
					'type'        => 'dropdown',
					'holder'      => 'div',
					'class'       => 'hide hidden',
					'heading'     => esc_html__( 'Form', 'kleo' ),
					'param_name'  => 'id',
					'value'       => $posts,
					'description' => esc_html__( 'What form to show', 'kleo' ),
				),
			),
		)
	);
}

if ( ! function_exists( 'vc_has_class' ) ) {
	/**
	 * Check if element has specific class
	 *
	 * E.g. f('foo', 'foo bar baz') -> true
	 *
	 * @param string $class Class to check for
	 * @param string $classes Classes separated by space(s)
	 *
	 * @return boolean
	 */
	function vc_has_class( $class, $classes ) {
		return in_array( $class, explode( ' ', strtolower( $classes ) ) );
	}
}

if ( ! function_exists( 'vc_stringify_attributes' ) ) {
	/**
	 * Convert array of named params to string version
	 * All values will be escaped
	 *
	 * E.g. f(array('name' => 'foo', 'id' => 'bar')) -> 'name="foo" id="bar"'
	 *
	 * @param $attributes
	 *
	 * @return string
	 */
	function vc_stringify_attributes( $attributes ) {
		$atts = array();
		foreach ( $attributes as $name => $value ) {
			$atts[] = $name . '="' . esc_attr( $value ) . '"';
		}

		return implode( ' ', $atts );
	}
}

if ( ! function_exists( 'vc_map_get_attributes' ) ) {
	/**
	 * @param $tag - shortcode tag
	 * @param $atts - shortcode attributes
	 *
	 * @return array - return merged values with provided attributes ( 'a'=>1,'b'=>2 + 'b'=>3,'c'=>4 == 'a'=>1,'b'=>3 )
	 *
	 * @see vc_shortcode_attribute_parse - return union of provided attributes ( 'a'=>1,'b'=>2 + 'b'=>3,'c'=>4 == 'a'=>1,
	 *     'b'=>3, 'c'=>4 )
	 */
	function vc_map_get_attributes( $tag, $atts = array() ) {

		$atts = shortcode_atts( vc_map_get_defaults( $tag ), $atts, $tag );

		return apply_filters( 'vc_map_get_attributes', $atts, $tag );

	}
}

if ( ! function_exists( 'vc_map_get_defaults' ) ) {
	/**
	 * Function to get defaults values for shortcode.
	 * @since 4.6
	 *
	 * @param $tag - shortcode tag
	 *
	 * @return array - list of param=>default_value
	 */
	function vc_map_get_defaults( $tag ) {
		$shortcode = vc_get_shortcode( $tag );
		$params    = array();
		if ( is_array( $shortcode ) && isset( $shortcode['params'] ) && ! empty( $shortcode['params'] ) ) {
			foreach ( $shortcode['params'] as $param ) {
				if ( isset( $param['param_name'] ) && 'content' !== $param['param_name'] ) {
					$value = '';
					if ( isset( $param['std'] ) ) {
						$value = $param['std'];
					} elseif ( isset( $param['value'] ) && 'checkbox' !== $param['type'] ) {
						if ( is_array( $param['value'] ) ) {
							$value = current( $param['value'] );
							if ( is_array( $value ) ) {
								// in case if two-dimensional array provided (vc_basic_grid)
								$value = current( $value );
							}
							// return first value from array (by default)
						} else {
							$value = $param['value'];
						}
					}
					$params[ $param['param_name'] ] = $value;
				}
			}
		}

		return $params;
	}
}


if ( ! function_exists( 'vc_shortcode_custom_css_has_property' ) ) {
	/**
	 * @param $subject
	 * @param $property
	 * @param bool|false $strict
	 *
	 * @since 4.9
	 * @return bool
	 */
	function vc_shortcode_custom_css_has_property( $subject, $property, $strict = false ) {
		$styles  = array();
		$pattern = '/\{([^\}]*?)\}/i';
		preg_match( $pattern, $subject, $styles );
		if ( array_key_exists( 1, $styles ) ) {
			$styles = explode( ';', $styles[1] );
		}
		$new_styles = array();
		foreach ( $styles as $val ) {
			$val = explode( ':', $val );
			if ( is_array( $property ) ) {
				foreach ( $property as $prop ) {
					$pos  = strpos( $val[0], $prop );
					$full = ( $strict ) ? ( $pos === 0 && strlen( $val[0] ) === strlen( $prop ) ) : true;
					if ( $pos !== false && $full ) {
						$new_styles[] = $val;
					}
				}
			} else {
				$pos  = strpos( $val[0], $property );
				$full = ( $strict ) ? ( $pos === 0 && strlen( $val[0] ) === strlen( $property ) ) : true;
				if ( $pos !== false && $full ) {
					$new_styles[] = $val;
				}
			}
		}

		return ! empty( $new_styles );
	}
}


if ( ! function_exists( 'vc_shortcode_custom_css_class' ) ) {
	/**
	 * @param $param_value
	 * @param string $prefix
	 *
	 * @since 4.2
	 * @return string
	 */
	function vc_shortcode_custom_css_class( $param_value, $prefix = '' ) {
		$css_class = preg_match( '/\s*\.([^\{]+)\s*\{\s*([^\}]+)\s*\}\s*/', $param_value ) ? $prefix . preg_replace( '/\s*\.([^\{]+)\s*\{\s*([^\}]+)\s*\}\s*/', '$1', $param_value ) : '';

		return $css_class;
	}
}

if ( ! function_exists( 'kleo_translateColumnWidth' ) ) {
	/**
	 * Translate column proportions to classes
	 *
	 * @param string $width
	 *
	 * @return string
	 */
	function kleo_translateColumnWidth( $width ) {
		if ( preg_match( '/^(\d{1,2})\/12$/', $width, $match ) ) {
			$w = 'vc_col-sm-' . $match[1];
		} else {
			$w = 'vc_col-sm-';
			switch ( $width ) {
				case "1/6" :
					$w .= '2';
					break;
				case "1/4" :
					$w .= '3';
					break;
				case "1/3" :
					$w .= '4';
					break;
				case "1/2" :
					$w .= '6';
					break;
				case "2/3" :
					$w .= '8';
					break;
				case "3/4" :
					$w .= '9';
					break;
				case '5/6' :
					$w .= '10';
					break;
				case '1/1' :
					$w .= '12';
					break;
				/* custom 5 columns */
				case '1/5' :
					$w = 'col-sm-1-5';
					break;
				case '2/5' :
					$w = 'col-sm-2-5';
					break;
				case '3/5' :
					$w = 'col-sm-3-5';
					break;
				case '4/5' :
					$w = 'col-sm-4-5';
					break;

				default :
					$w = $width;
			}
		}

		return $w;
	}
}


/* Custom 5 columns layout */

function kleo_add_new_five_cols() {
	global $vc_row_layouts;
	$vc_row_layouts[] = array(
		'cells'      => '15_15_15_15_15',
		'mask'       => '530',
		'title'      => '1/5 + 1/5 + 1/5 + 1/5 + 1/5',
		'icon_class' => '1-6_1-6_1-6_1-6_1-6_1-6',
	);
}

add_action( 'vc_before_init_base', 'kleo_add_new_five_cols' );

function kleo_add_vc_column_five_cols_options() {
	//Get current values stored in the width param in "Column" element
	$param                                                  = WPBMap::getParam( 'vc_column', 'width' );
	$param['value'][ esc_html__( '1/5 Column', 'kleo' ) ] = '1/5';
	$param['value'][ esc_html__( '2/5 Column', 'kleo' ) ] = '2/5';
	$param['value'][ esc_html__( '3/5 Column', 'kleo' ) ] = '3/5';
	$param['value'][ esc_html__( '4/5 Column', 'kleo' ) ] = '4/5';
	//Finally "mutate" param with new values
	vc_update_shortcode_param( 'vc_column', $param );

	//Get current values stored in the width param in "Column inner" element
	$param                                                  = WPBMap::getParam( 'vc_column_inner', 'width' );
	$param['value'][ esc_html__( '1/5 Column', 'kleo' ) ] = '1/5';
	$param['value'][ esc_html__( '2/5 Column', 'kleo' ) ] = '2/5';
	$param['value'][ esc_html__( '3/5 Column', 'kleo' ) ] = '3/5';
	$param['value'][ esc_html__( '4/5 Column', 'kleo' ) ] = '4/5';
	//Finally "mutate" param with new values
	vc_update_shortcode_param( 'vc_column_inner', $param );
}

add_action( 'vc_after_init', 'kleo_add_vc_column_five_cols_options' );





if ( ! function_exists( 'kleo_vc_elem_increment' ) ) {
	function kleo_vc_elem_increment() {
		static $count = 0;
		$count ++;

		return $count;
	}
}

/**
 * Add title to Image
 *
 * @param $attr
 * @param WP_Post $attachment
 *
 * @return mixed
 */
function kleo_vc_single_img_title_fix( $attr, $attachment ) {

	if ( ! isset( $attr['title'] ) ) {
		$title         = $attachment->post_title;
		$attr['title'] = $title;
	}

	return $attr;
}
