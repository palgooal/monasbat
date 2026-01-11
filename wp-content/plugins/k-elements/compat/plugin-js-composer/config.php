<?php

/*
 * Visual Composer specific configurations
 * @package WordPress
 * @subpackage K Elements
 * @author SeventhQueen <themesupport@seventhqueen.com>
 * @since K Elements 1.0
 */


vc_set_as_theme();

if ( ! function_exists( 'kleo_vc_elem_increment' ) ) {
	function kleo_vc_elem_increment() {
		static $count = 0;
		$count ++;

		return $count;
	}
}


/***************************************************
 * :: Remove Teaser Metabox
 ***************************************************/
if ( is_admin() ) {
	function kleo_vc_remove_teaser_metabox() {
		$post_types = get_post_types( '', 'names' );
		foreach ( $post_types as $post_type ) {
			remove_meta_box( 'vc_teaser', $post_type, 'side' );
		}
	}

	add_action( 'do_meta_boxes', 'kleo_vc_remove_teaser_metabox' );
}


// Remove Default VC Features
/*vc_remove_element("vc_wp_search");
vc_remove_element("vc_wp_meta");
vc_remove_element("vc_wp_recentcomments");
vc_remove_element("vc_wp_pages");
vc_remove_element("vc_wp_text");
vc_remove_element("vc_wp_links");
vc_remove_element("vc_wp_rss");
vc_remove_element("vc_button");
vc_remove_element("vc_posts_slider");
vc_remove_element("vc_cta_button");
vc_remove_element("vc_cta_button2");*/


/***************************************************
 * :: Visual Composer CSS replace classes
 ***************************************************/


function kleo_css_classes_for_vc_row_and_vc_column( $class_string, $tag ) {

	if ( $tag == 'vc_row' || $tag == 'vc_row_inner' ) {
		$class_string = str_replace( 'vc_row-fluid', 'row', $class_string );
	} elseif ( $tag == 'vc_column' || $tag == 'vc_column_inner' ) {
		$class_string = str_replace( 'vc_span2', 'col-sm-2', $class_string );
		$class_string = str_replace( 'vc_span3', 'col-sm-3', $class_string );
		$class_string = str_replace( 'vc_span4', 'col-sm-4', $class_string );
		$class_string = str_replace( 'vc_span5', 'col-sm-5', $class_string );
		$class_string = str_replace( 'vc_span6', 'col-sm-6', $class_string );
		$class_string = str_replace( 'vc_span7', 'col-sm-7', $class_string );
		$class_string = str_replace( 'vc_span8', 'col-sm-8', $class_string );
		$class_string = str_replace( 'vc_span9', 'col-sm-9', $class_string );
		$class_string = str_replace( 'vc_span10', 'col-sm-10', $class_string );
		$class_string = str_replace( 'vc_span11', 'col-sm-11', $class_string );
		$class_string = str_replace( 'vc_span12', 'col-sm-12', $class_string );
		$class_string = str_replace( 'wpb_column', 'kleo_column', $class_string );

	}

	return $class_string;
}

function kleo_css_classes_for_elements( $class_string, $tag, $atts = array() ) {
	if ( $tag == 'vc_widget_sidebar' ) {
		$class_string .= ' sidebar';
	}

	if ( $tag == 'vc_column_text' || $tag == 'vc_custom_heading' ) {

		if ( isset( $atts['letter_spacing'] ) && $atts['letter_spacing'] != '' ) {
			$class_string .= ' letter-spacing-' . $atts['letter_spacing'];
		}
		if ( isset( $atts['vertical_separator'] ) && $atts['vertical_separator'] != '' ) {
			$class_string .= ' vertical-separator';
			if ( $atts['vertical_separator'] == 'dark' ) {
				$class_string .= ' vertical-dark';
			}
		}
	}
	if ( $tag == 'vc_custom_heading' ) {
		if ( isset( $atts['visibility'] ) && $atts['visibility'] != '' ) {
			$class_string .= ' ' . str_replace( ',', ' ', $atts['visibility'] );
		}
	}
	if ( $tag == 'vc_progress_bar' ) {
		if ( isset( $atts['style'] ) && $atts['style'] != '' ) {
			$class_string .= ' ' . $atts['style'] . '-style';
		}
	}


	return $class_string;
}

/**
 * Disabled class changes and replaced with custom column template in theme for performance
 */
function kleo_vc_replace_classes() {
	if ( defined( 'SVQ_THEME_VERSION' ) && SVQ_THEME_VERSION <= '3.0' ) {
		add_filter( 'vc_shortcodes_css_class', 'kleo_css_classes_for_vc_row_and_vc_column', 10, 3 );
	}
	add_filter( 'vc_shortcodes_css_class', 'kleo_css_classes_for_elements', 10, 3 );
}

add_action( 'after_setup_theme', 'kleo_vc_replace_classes', 12 );


/***************************************************
 * :: Visual Composer modify parameters
 ***************************************************/

function kleo_vc_manipulate_shortcodes() {

	global $kleo_config;
	$k_elements = $kleo_config['shortcodes'];

	$animation     = array(
		"type"        => "dropdown",
		"class"       => "hide hidden",
		"holder"      => 'div',
		"heading"     => __( "Animation", "k-elements" ),
		"admin_label" => true,
		"param_name"  => "animation",
		"value"       => array(
			"None"                        => "",
			"Animate when visible"        => "animate-when-visible",
			"Animate when almost visible" => "animate-when-almost-visible"
		),
		"description" => ""
	);
	$css_animation = array(
		"type"        => "dropdown",
		"class"       => "hide hidden",
		"holder"      => 'div',
		"heading"     => __( "Animation type", "k-elements" ),
		//"admin_label" => true,
		"param_name"  => "css_animation",
		"value"       => array(
			"Right to Left" => "right-to-left",
			"Left to Right" => "left-to-right",
			"Bottom to Top" => "bottom-to-top",
			"Top to Bottom" => "top-to-bottom",
			"Scale"         => "el-appear",
			"Fade"          => "el-fade",
			"Pulsate"       => "pulsate",
		),
		"dependency"  => array(
			"element"   => "animation",
			"not_empty" => true
		),
		"description" => ""
	);

	$visibility = array(
		'param_name'  => 'visibility',
		'heading'     => __( 'Responsive Visibility', 'k-elements' ),
		'description' => __( 'Hide/Show content by screen size.', 'k-elements' ),
		'type'        => 'checkbox',
		'holder'      => 'div',
		'value'       => array(
			'Hidden Phones (max 768px)'            => 'hidden-xs',
			'Hidden Tablets (768px - 991px)'       => 'hidden-sm',
			'Hidden Desktops (992px - 1199px)'     => 'hidden-md',
			'Hidden Large Desktops (min 1200px)'   => 'hidden-lg',
			'Hidden XLarge Desktops (min 1440px)'  => 'hidden-xlg',
			'Visible Phones (max 767px)'           => 'visible-xs',
			'Visible Tablets (768px - 991px)'      => 'visible-sm',
			'Visible Desktops (992px - 1199px)'    => 'visible-md',
			'Visible Large Desktops (min 1200px)'  => 'visible-lg',
			'Visible XLarge Desktops (min 1440px)' => 'visible-xlg'
		),
		'group'       => __( 'Responsive Visibility', 'k-elements' ),
	);

	$letter_spacing     = array(
		'param_name'  => 'letter_spacing',
		'heading'     => __( 'Letter spacing', 'k-elements' ),
		'description' => __( 'Set a custom letter spacing.', 'k-elements' ),
		'type'        => 'dropdown',
		'class'       => 'hide hidden',
		'holder'      => 'div',
		'value'       => array(
			'Default' => '',
			'0.03em'  => '25',
			'0.06em'  => '50',
			'0.09em'  => '75',
			'0.12em'  => '100',
			'2px'     => '2px',
			'4px'     => '4px',
		),
	);
	$vertical_separator = array(
		'param_name'  => 'vertical_separator',
		'heading'     => __( 'Vertical separator', 'k-elements' ),
		'description' => __( 'Set a fancy vertical line separator to the left side.', 'k-elements' ),
		'type'        => 'dropdown',
		'class'       => 'hide hidden',
		'holder'      => 'div',
		'value'       => array(
			'No'   => '',
			'Yes'  => 'yes',
			'Dark' => 'dark'
		),
		'group'       => __( 'Vertical separator', 'k-elements' ),
	);


	$icon = array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon', 'k-elements' ),
		'param_name'  => 'icon',
		"admin_label" => true,
		'value'       => '', // default value to backend editor admin_label
		'settings'    => array(
			'emptyIcon'    => false,
			'type'         => 'fontello',
			'iconsPerPage' => 4000,
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	);

	$icon_size = array(
		"type"        => "dropdown",
		"class"       => "hide hidden",
		"holder"      => 'div',
		"heading"     => __( "Icon size", "k-elements" ),
		"admin_label" => true,
		"param_name"  => "icon_size",
		"value"       => array(
			"Regular" => "",
			"2x"      => "2x",
			"3x"      => "3x",
			"4x"      => "4x"
		)
	);

	$el_class = array(
		"type"        => "textfield",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Extra class name", "k-elements" ),
		"param_name"  => "el_class",
		"value"       => "",
		"description" => __( "If you wish to style particular content element differently, then use this field to add a class name and then refer to it in your css file.", "k-elements" )
	);

	$tooltip          = array(
		"type"        => "dropdown",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Show Tooltip/Popover", "k-elements" ),
		"param_name"  => "tooltip",
		"value"       => array(
			'No'      => '',
			"Tooltip" => "tooltip",
			"Popover" => "popover"
		),
		"description" => __( "Display a tooltip or popover with descriptive text." ),
	);
	$tooltip_position = array(
		"type"        => "dropdown",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Tip position", "k-elements" ),
		"param_name"  => "tooltip_position",
		"value"       => array(
			"Left"   => "left",
			"Right"  => "right",
			"Top"    => "top",
			"Bottom" => "bottom"
		),
		"dependency"  => array(
			"element"   => "tooltip",
			"not_empty" => true
		),
		"description" => __( "In which position to show the tooltip/popover" ),
	);
	$tooltip_title    = array(
		"type"       => "textfield",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Tip/Popover Title", "k-elements" ),
		"param_name" => "tooltip_title",
		"value"      => "",
		"dependency" => array(
			"element"   => "tooltip",
			"not_empty" => true
		),
	);
	$tooltip_text     = array(
		"type"       => "textfield",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Popover text", "k-elements" ),
		"param_name" => "tooltip_text",
		"value"      => "",
		"dependency" => array(
			"element" => "tooltip",
			"value"   => "popover"
		),
	);
	$tooltip_action   = array(
		"type"        => "dropdown",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Tip/Popover action", "k-elements" ),
		"param_name"  => "tooltip_action",
		"value"       => array(
			"Hover" => "hover",
			"Click" => "click"
		),
		"dependency"  => array(
			"element"   => "tooltip",
			"not_empty" => true
		),
		"description" => __( "When to trigger the popover" ),
	);

	$button_args = array(
		array(
			"param_name"  => "title",
			"type"        => "textfield",
			"holder"      => "div",
			"class"       => "",
			"heading"     => __( "Title", "k-elements" ),
			"value"       => __( 'Text on the button', "k-elements" ),
			"description" => __( "Button text." )
		),
		array(
			"param_name"  => "href",
			"type"        => "textfield",
			"holder"      => "div",
			"class"       => "",
			"heading"     => __( "URL(Link)", "k-elements" ),
			"value"       => '',
			"description" => ""
		),
		array(
			"type"        => "dropdown",
			"holder"      => "div",
			"class"       => "hide hidden",
			"heading"     => __( "Target", "k-elements" ),
			"param_name"  => "target",
			"value"       => array(
				'Same window' => '_self',
				'New window'  => '_blank'
			),
			"description" => ""
		),
		array(
			"param_name"  => "style",
			"type"        => "dropdown",
			"holder"      => "div",
			"class"       => "hide hidden",
			"heading"     => __( "Style", "k-elements" ),
			"value"       => array(
				'Default'           => 'default',
				'Primary'           => 'primary',
				'See through'       => 'see-through',
				'Highlight'         => 'highlight',
				'Highlight style 2' => 'highlight style2',
				'Link'              => 'link',
				'Custom'            => 'custom'
			),
			"description" => "Choose the button style",
		),
		array(
			'type'             => 'colorpicker',
			'heading'          => __( 'Background color', 'k-elements' ),
			'param_name'       => 'custom_background',
			'description'      => __( 'Select custom background color.', 'k-elements' ),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
			'group'            => __( 'Custom button', 'k-elements' ),
			'dependency'       => array(
				'element' => 'style',
				'value'   => array( 'custom' )
			),
		),
		array(
			'type'             => 'colorpicker',
			'heading'          => __( 'Hover Background color', 'k-elements' ),
			'param_name'       => 'custom_bg_hover',
			'description'      => __( 'Select custom background color on hover.', 'k-elements' ),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
			'group'            => __( 'Custom button', 'k-elements' ),
			'dependency'       => array(
				'element' => 'style',
				'value'   => array( 'custom' )
			),
		),
		array(
			'type'             => 'colorpicker',
			'heading'          => __( 'Text color', 'k-elements' ),
			'param_name'       => 'custom_text',
			'description'      => __( 'Select custom text color.', 'k-elements' ),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
			'group'            => __( 'Custom button', 'k-elements' ),
			'dependency'       => array(
				'element' => 'style',
				'value'   => array( 'custom' )
			),
		),
		array(
			'type'             => 'colorpicker',
			'heading'          => __( 'Hover Text color', 'k-elements' ),
			'param_name'       => 'custom_text_hover',
			'description'      => __( 'Select custom text color on hover.', 'k-elements' ),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
			'group'            => __( 'Custom button', 'k-elements' ),
			'dependency'       => array(
				'element' => 'style',
				'value'   => array( 'custom' )
			),
		),
		array(
			'type'             => 'colorpicker',
			'heading'          => __( 'Border color', 'k-elements' ),
			'param_name'       => 'custom_border',
			'description'      => __( 'Select custom border color.', 'k-elements' ),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
			'group'            => __( 'Custom button', 'k-elements' ),
			'dependency'       => array(
				'element' => 'style',
				'value'   => array( 'custom' )
			),
		),
		array(
			'type'             => 'colorpicker',
			'heading'          => __( 'Hover border color', 'k-elements' ),
			'param_name'       => 'custom_border_hover',
			'description'      => __( 'Select custom border color on hover.', 'k-elements' ),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
			'group'            => __( 'Custom button', 'k-elements' ),
			'dependency'       => array(
				'element' => 'style',
				'value'   => array( 'custom' )
			),
		),
		array(
			"param_name"  => "border_width",
			"type"        => "dropdown",
			"holder"      => "div",
			"class"       => "hide hidden",
			"heading"     => __( "Border width", "k-elements" ),
			"value"       => array(
				'Default' => '',
				'1px'     => '1px',
				'2px'     => '2px',
				'3px'     => '3px',
				'4px'     => '4px',
				'5px'     => '5px',
				'6px'     => '6px',
				'7px'     => '7px',
				'8px'     => '8px',
				'9px'     => '9px',
				'10px'    => '10px',
			),
			"description" => 'Custom border width',
			'group'       => __( 'Custom button', 'k-elements' ),
			'dependency'  => array(
				'element' => 'style',
				'value'   => array( 'custom' )
			),
		),
		array(
			'type'             => 'colorpicker',
			'heading'          => __( 'Boxed Icon Type - Icon Background', 'k-elements' ),
			'param_name'       => 'icon_custom_background',
			'description'      => __( 'Select custom background color.', 'k-elements' ),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
			'group'            => __( 'Custom button', 'k-elements' ),
			'dependency'       => array(
				'element' => 'type',
				'value'   => array( 'boxed-icon' )
			),
		),
		array(
			'type'             => 'colorpicker',
			'heading'          => __( 'Boxed Icon Type - Icon Text color', 'k-elements' ),
			'param_name'       => 'icon_custom_text',
			'description'      => __( 'Select custom text color.', 'k-elements' ),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
			'group'            => __( 'Custom button', 'k-elements' ),
			'dependency'       => array(
				'element' => 'type',
				'value'   => array( 'boxed-icon' )
			),
		),
		array(
			"param_name"  => "position",
			"type"        => "dropdown",
			"holder"      => "div",
			"class"       => "hide hidden",
			"heading"     => __( "Position", "k-elements" ),
			"value"       => array(
				'Inline' => 'inline',
				'Left'   => 'left',
				'Right'  => 'right',
				'Center' => 'center',
			),
			"description" => "Choose how to position the button"
		),
		array(
			"param_name"  => "size",
			"type"        => "dropdown",
			"holder"      => "div",
			"class"       => "hide hidden",
			"heading"     => __( "Size", "k-elements" ),
			"value"       => array(
				'Default'     => '',
				'Extra small' => 'xs',
				'Small'       => 'sm',
				'Large'       => 'lg',
				'XLarge'      => 'xl',
				'XXLarge'     => 'xxl',
			),
			"description" => "Choose how you want them to appear"
		),
		array(
			"param_name"  => "type",
			"type"        => "dropdown",
			"holder"      => "div",
			"class"       => "hide hidden",
			"heading"     => __( "Type", "k-elements" ),
			"value"       => array(
				'Regular'         => '',
				'Boxed Left Icon' => 'boxed-icon',
				'Animated'        => 'text-animated',
				'Subtext'         => 'subtext',
				'App button'      => 'app'
			),
			"description" => "Choose between several button types. Fox Boxed Let Icon type check Advanced styling for custom colors "
		),


		array(
			"param_name"  => "title_alt",
			"type"        => "textfield",
			"holder"      => "div",
			"class"       => "",
			"heading"     => __( "Second title", "k-elements" ),
			"value"       => '',
			"dependency"  => array(
				"element" => "type",
				"value"   => array( 'text-animated', 'subtext', 'app' )
			),
			"description" => ""
		),

		array(
			"param_name"  => "special",
			"type"        => "dropdown",
			"holder"      => "div",
			"class"       => "hide hidden",
			"heading"     => __( "Rounded button", "k-elements" ),
			"value"       => array(
				'Default(2px)'              => '',
				'Extra rounded(Special)'    => 'yes',
				'Extra rounded - no border' => 'no_border',
				'Not rounded'               => 'no',
			),
			"description" => "Make the button extra rounded"
		),
		array(
			"param_name"  => "border",
			"type"        => "dropdown",
			"holder"      => "div",
			"class"       => "hide hidden",
			"heading"     => __( "Border", "k-elements" ),
			"value"       => array(
				'Default'   => '',
				'No border' => 'no',
			),
			"description" => 'Remove button border'
		),

		$icon,
		$tooltip,
		$tooltip_position,
		$tooltip_title,
		$tooltip_text,
		$tooltip_action,
		array(
			"param_name"       => "font_size",
			"type"             => "textfield",
			"holder"           => "div",
			"class"            => "hide hidden",
			"heading"          => __( "Font size. Use px|em|pt", "k-elements" ),
			"value"            => '',
			"description"      => "",
			//'group' => __( 'Advanced styling', 'k-elements' ),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
		),
		array(
			"param_name"       => "font_weight",
			"type"             => "dropdown",
			"holder"           => "div",
			"class"            => "hide hidden",
			"heading"          => __( "Font weight", "k-elements" ),
			"value"            => array(
				'Theme Default' => '',
				'Regular'       => '400',
				'Bold'          => '700',

			),
			"description"      => "Set a custom font weight.",
			//'group' => __( 'Advanced styling', 'k-elements' ),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
		),
		array(
			"param_name"  => "uppercase",
			"type"        => "checkbox",
			"holder"      => "div",
			"class"       => "hide hidden",
			"heading"     => __( "Make text uppercase", "k-elements" ),
			"value"       => array(
				"Yes" => "yes"
			),
			"description" => "",
			//'group' => __( 'Advanced styling', 'k-elements' ),
		),
	);


	$box_shadow = array(
		array(
			"type"       => "colorpicker",
			"holder"     => "div",
			"class"      => "hide hidden",
			"heading"    => __( "Shadow color", "k-elements" ),
			"param_name" => "box_shadow_color",
			"value"      => "#000000",
			'group'      => __( 'Box shadow', 'k-elements' ),
		),
		array(
			"type"             => "textfield",
			"holder"           => "div",
			"class"            => "hide hidden",
			"heading"          => __( "Horizontal Length", "k-elements" ),
			"param_name"       => "box_shadow_x",
			"value"            => "0",
			"description"      => __( "Length in pixels" ),
			'edit_field_class' => 'vc_col-sm-3 vc_column',
			'group'            => __( 'Box shadow', 'k-elements' ),
		),
		array(
			"type"             => "textfield",
			"holder"           => "div",
			"class"            => "hide hidden",
			"heading"          => __( "Vertical Length", "k-elements" ),
			"param_name"       => "box_shadow_y",
			"value"            => "0",
			"description"      => __( "Length in pixels" ),
			'edit_field_class' => 'vc_col-sm-3 vc_column',
			'group'            => __( 'Box shadow', 'k-elements' ),
		),
		array(
			"type"             => "textfield",
			"holder"           => "div",
			"class"            => "hide hidden",
			"heading"          => __( "Blur Radius", "k-elements" ),
			"param_name"       => "box_shadow_blur",
			"value"            => "0",
			"description"      => __( "Length in pixels" ),
			'edit_field_class' => 'vc_col-sm-3 vc_column',
			'group'            => __( 'Box shadow', 'k-elements' ),
		),
		array(
			"type"             => "textfield",
			"holder"           => "div",
			"class"            => "hide hidden",
			"heading"          => __( "Spread Radius", "k-elements" ),
			"param_name"       => "box_shadow_spread",
			"value"            => "0",
			"description"      => __( "Length in pixels" ),
			'edit_field_class' => 'vc_col-sm-3 vc_column',
			'group'            => __( 'Box shadow', 'k-elements' ),
		),
	);


	$query_offset = array(
		"param_name"  => "query_offset",
		"type"        => "textfield",
		"holder"      => "div",
		"class"       => "hide hidden",
		"heading"     => __( 'Query offset', 'k-elements' ),
		"value"       => '0',
		"description" => 'Enter an offset for the posts query(numerical value)',
	);

	$responsive_font = array(
		array(
			"type"        => "checkbox",
			"holder"      => 'div',
			'class'       => 'hide hidden',
			"heading"     => __( "Enable responsive font size settings", "k-elements" ),
			"param_name"  => "responsive_font",
			"description" => "",
			'value'       => array(
				'Yes' => 'yes'
			),
		),
		array(
			"type"             => "textfield",
			"class"            => "hide hidden",
			"holder"           => 'div',
			"heading"          => __( "Font size - Mobile", "k-elements" ),
			"admin_label"      => true,
			"param_name"       => "font_size_xs",
			"value"            => "",
			"description"      => "Max 768px device width. Use px|em|%|pt|cm",
			'group'            => __( 'Responsive Font', 'k-elements' ),
			'dependency'       => array(
				'element'   => 'responsive_font',
				"not_empty" => true
			),
			'edit_field_class' => 'vc_col-sm-6 vc_column pad-top-15',
		),
		array(
			"type"             => "textfield",
			"class"            => "hide hidden",
			"holder"           => 'div',
			"heading"          => __( "Line Height - Mobile", "k-elements" ),
			"admin_label"      => true,
			"param_name"       => "line_height_xs",
			"value"            => "",
			"description"      => "Max 768px device width. Use px|em|%|pt|cm",
			'group'            => __( 'Responsive Font', 'k-elements' ),
			'dependency'       => array(
				'element'   => 'responsive_font',
				"not_empty" => true
			),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
		),
		array(
			"type"             => "textfield",
			"class"            => "hide hidden",
			"holder"           => 'div',
			"heading"          => __( "Font size - Tablet", "k-elements" ),
			"admin_label"      => true,
			"param_name"       => "font_size_sm",
			"value"            => "",
			"description"      => "768px - 991px device width. Use px|em|%|pt|cm",
			'group'            => __( 'Responsive Font', 'k-elements' ),
			'dependency'       => array(
				'element'   => 'responsive_font',
				"not_empty" => true
			),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
		),
		array(
			"type"             => "textfield",
			"class"            => "hide hidden",
			"holder"           => 'div',
			"heading"          => __( "Line Height - Tablet", "k-elements" ),
			"admin_label"      => true,
			"param_name"       => "line_height_sm",
			"value"            => "",
			"description"      => "768px - 991px device width. Use px|em|%|pt|cm",
			'group'            => __( 'Responsive Font', 'k-elements' ),
			'dependency'       => array(
				'element'   => 'responsive_font',
				"not_empty" => true
			),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
		),
		array(
			"type"             => "textfield",
			"class"            => "hide hidden",
			"holder"           => 'div',
			"heading"          => __( "Font size - Desktop", "k-elements" ),
			"admin_label"      => true,
			"param_name"       => "font_size_md",
			"value"            => "",
			"description"      => "991px - 1200px device width. Use px|em|%|pt|cm",
			'group'            => __( 'Responsive Font', 'k-elements' ),
			'dependency'       => array(
				'element'   => 'responsive_font',
				"not_empty" => true
			),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
		),
		array(
			"type"             => "textfield",
			"class"            => "hide hidden",
			"holder"           => 'div',
			"heading"          => __( "Line Height - Desktop", "k-elements" ),
			"admin_label"      => true,
			"param_name"       => "line_height_md",
			"value"            => "",
			"description"      => "991px - 1200px device width. Use px|em|%|pt|cm",
			'group'            => __( 'Responsive Font', 'k-elements' ),
			'dependency'       => array(
				'element'   => 'responsive_font',
				"not_empty" => true
			),
			'edit_field_class' => 'vc_col-sm-6 vc_column',
		),
	);


	/* ROW */
	vc_remove_param( 'vc_row', 'full_width' );
	//vc_remove_param( 'vc_row', 'bg_color' );
	vc_remove_param( 'vc_row', 'font_color' );
	//vc_remove_param( 'vc_row', 'bg_image' );
	vc_remove_param( 'vc_row', 'bg_image_repeat' );
	vc_remove_param( 'vc_row', 'padding' );
	vc_remove_param( 'vc_row', 'margin_bottom' );
	vc_remove_param( 'vc_row', 'el_class' );
	vc_remove_param( 'vc_row', 'css' );
	vc_remove_param( 'vc_row', 'parallax' );
	vc_remove_param( 'vc_row', 'parallax_image' );
	vc_remove_param( 'vc_row', 'parallax_speed_bg' );

	vc_add_param( 'vc_row', array(
		'param_name'  => 'front_status',
		'heading'     => __( 'Status', 'k-elements' ),
		'description' => __( 'Select front-end visibility status. If set to Draft it will not show on your page.', 'k-elements' ),
		'type'        => 'dropdown',
		'holder'      => 'div',
		'class'       => 'hide hidden',
		"value"       => array(
			'Enabled' => '',
			'Draft'   => 'draft'
		)
	) );

	vc_add_param( 'vc_row', array(
		'param_name'  => 'inner_container',
		'heading'     => __( 'Inner Container', 'k-elements' ),
		'description' => __( 'Select whether to insert a container to the section. This will keep the content from going full with.', 'k-elements' ),
		'type'        => 'dropdown',
		'holder'      => 'div',
		'class'       => 'hide hidden',
		"admin_label" => true,
		"value"       => array(
			'Yes' => 'yes',
			'No'  => 'no'
		)
	) );
	vc_add_param( 'vc_row', array(
		'param_name'  => 'text_align',
		'heading'     => __( 'Text align', 'k-elements' ),
		'description' => __( 'Align whole row content.', 'k-elements' ),
		'type'        => 'dropdown',
		'holder'      => 'div',
		'class'       => 'hide hidden',
		"value"       => array(
			'Left'   => '',
			'Right'  => 'right',
			'Center' => 'center'
		)
	) );


	vc_add_param( "vc_row", array(
		"type"        => "colorpicker",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Text color", "k-elements" ),
		"param_name"  => "text_color",
		"value"       => "",
		"description" => __( "Try to force a color for the whole section." ),
		'group'       => __( 'Text & Background', 'k-elements' ),
	) );

	vc_add_param( "vc_row", array(
		"type"        => "dropdown",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Section style", "k-elements" ),
		"admin_label" => true,
		"param_name"  => "section_type",
		"value"       => array(
			"Main style"      => "main",
			"Alternate style" => "alternate",
			"Header style"    => "header",
			"Footer style"    => "footer",
			"Socket style"    => "socket"
		),
		"description" => __( "These styles are set under Theme options. They will apply that section styling to this row(background color, text color, highlight color, etc)" ),
		'group'       => __( 'Text & Background', 'k-elements' ),
	) );

	vc_add_param( "vc_row", array(
		"type"        => "dropdown",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Background style", "k-elements" ),
		"admin_label" => true,
		"param_name"  => "type",
		"value"       => array(
			"Default" => '',
			"Color"   => "color",
			"Image"   => "image",
			"Video"   => "video"
		),
		"description" => "",
		'group'       => __( 'Text & Background', 'k-elements' ),
	) );


	vc_add_param( "vc_row", array(
		"type"        => "colorpicker",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Background color", "k-elements" ),
		"param_name"  => "bg_color",
		"value"       => "",
		"description" => __( "" ),
		"dependency"  => array(
			"element" => "type",
			"value"   => array( "color", "image" )
		),
		'group'       => __( 'Text & Background', 'k-elements' ),
	) );
	vc_add_param( "vc_row", array(
		"type"        => "attach_image", //attach_images
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Background image", "k-elements" ),
		"param_name"  => "bg_image",
		"description" => "",
		"dependency"  => array(
			"element" => "type",
			"value"   => "image"
		),
		'group'       => __( 'Text & Background', 'k-elements' ),
	) );
	vc_add_param( "vc_row", array(
		"type"        => "checkbox", //attach_images
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Enable dark gradient", "k-elements" ),
		"param_name"  => "bg_gradient",
		"description" => "",
		"dependency"  => array(
			"element" => "type",
			"value"   => "image"
		),
		'value'       => array(
			'Yes' => 'yes'
		),
		'group'       => __( 'Text & Background', 'k-elements' ),
	) );

	vc_add_param( "vc_row", array(
		"type"             => "dropdown",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "BG vertical position", "k-elements" ),
		"param_name"       => "bg_position",
		"value"            => array(
			"Top"    => "top",
			"Middle" => "center",
			"Bottom" => "bottom"
		),
		"description"      => __( "" ),
		"dependency"       => array(
			"element" => "type",
			"value"   => "image"
		),
		'edit_field_class' => 'vc_col-sm-3 vc_column',
		'group'            => __( 'Text & Background', 'k-elements' ),
	) );
	vc_add_param( "vc_row", array(
		"type"             => "dropdown",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "BG horizontal position", "k-elements" ),
		"param_name"       => "bg_position_horizontal",
		"value"            => array(
			"Left"   => "left",
			"Middle" => "center",
			"Right"  => "right"
		),
		"description"      => __( "" ),
		"dependency"       => array(
			"element" => "type",
			"value"   => "image"
		),
		'edit_field_class' => 'vc_col-sm-3 vc_column',
		'group'            => __( 'Text & Background', 'k-elements' ),
	) );
	vc_add_param( "vc_row", array(
		"type"             => "dropdown",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Background repeat", "k-elements" ),
		"param_name"       => "bg_repeat",
		"value"            => array(
			"No repeat"                          => "no-repeat",
			"Repeat (horizontally & vertically)" => "repeat",
			"Repeat horizontally"                => "repeat-x",
			"Repeat vertically"                  => "repeat-y"
		),
		"description"      => __( "" ),
		"dependency"       => array(
			"element" => "type",
			"value"   => "image"
		),
		'edit_field_class' => 'vc_col-sm-3 vc_column',
		'group'            => __( 'Text & Background', 'k-elements' ),
	) );
	vc_add_param( "vc_row", array(
		"type"             => "dropdown",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Full-width background", "k-elements" ),
		"param_name"       => "bg_cover",
		"value"            => array(
			"Enabled"  => "true",
			"Disabled" => "false"
		),
		"description"      => "",
		"dependency"       => array(
			"element" => "type",
			"value"   => "image"
		),
		'edit_field_class' => 'vc_col-sm-3 vc_column',
		'group'            => __( 'Text & Background', 'k-elements' ),
	) );
	vc_add_param( "vc_row", array(
		"type"        => "dropdown",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Fixed background", "k-elements" ),
		"param_name"  => "bg_attachment",
		"value"       => array(
			"Disabled" => "false",
			"Enabled"  => "true"
		),
		"description" => __( "" ),
		"dependency"  => array(
			"element" => "type",
			"value"   => "image"
		),
		'group'       => __( 'Text & Background', 'k-elements' ),
	) );
	// parallax enable
	vc_add_param( "vc_row", array(
		"type"       => "checkbox",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Enable parallax", "k-elements" ),
		"param_name" => "enable_parallax",
		"value"      => array(
			"" => "false"
		),
		"dependency" => array(
			"element" => "type",
			"value"   => "image"
		),
		'group'      => __( 'Text & Background', 'k-elements' ),
	) );
	vc_add_param( "vc_row", array(
		"type"       => "textfield",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Parallax speed", "k-elements" ),
		"param_name" => "parallax_speed",
		"value"      => "0.1",
		"dependency" => array(
			"element"   => "enable_parallax",
			"not_empty" => true
		),
		'group'      => __( 'Text & Background', 'k-elements' ),
	) );
	// video background
	vc_add_param( "vc_row", array(
		"type"       => "textfield",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Video background (mp4)", "k-elements" ),
		"param_name" => "bg_video_src_mp4",
		"value"      => "",
		"dependency" => array(
			"element" => "type",
			"value"   => "video"
		),
		'group'      => __( 'Text & Background', 'k-elements' ),
	) );
	vc_add_param( "vc_row", array(
		"type"       => "textfield",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Video background (ogv)", "k-elements" ),
		"param_name" => "bg_video_src_ogv",
		"value"      => "",
		"dependency" => array(
			"element" => "type",
			"value"   => "video"
		),
		'group'      => __( 'Text & Background', 'k-elements' ),
	) );
	vc_add_param( "vc_row", array(
		"type"       => "textfield",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Video background (webm)", "k-elements" ),
		"param_name" => "bg_video_src_webm",
		"value"      => "",
		"dependency" => array(
			"element" => "type",
			"value"   => "video"
		),
		'group'      => __( 'Text & Background', 'k-elements' ),
	) );
	vc_add_param( "vc_row", array(
		"type"        => "attach_image", //attach_images
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Image cover", "k-elements" ),
		"param_name"  => "bg_video_cover",
		"description" => "It will show before video load and on some mobile devices where video can't be played.",
		"dependency"  => array(
			"element" => "type",
			"value"   => "video"
		),
		'group'       => __( 'Text & Background', 'k-elements' ),
	) );

	vc_add_param( "vc_row", array(
		"type"        => "dropdown",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Vertical Aligned Content", "k-elements" ),
		"param_name"  => "vertical_align",
		"value"       => array(
			"No"  => '',
			"Yes" => "yes"
		),
		"description" => "If you set Yes then the content in the row columns will have middle vertical alignment"
	) );
	vc_add_param( "vc_row", array(
		"type"        => "dropdown",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Between Columns Gap", "k-elements" ),
		"param_name"  => "column_gap",
		"value"       => array(
			"Yes" => '',
			"No"  => "no"
		),
		"description" => "Set to No only when you want to remove inner Columns padding."
	) );

	vc_add_param( "vc_row", array(
		"type"             => "textfield",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Top padding", "k-elements" ),
		"param_name"       => "padding_top",
		"value"            => "40",
		"description"      => __( "Allowed measures: px,em,%,pt,cm." ),
		'edit_field_class' => 'vc_col-sm-3 vc_column',
	) );
	vc_add_param( "vc_row", array(
		"type"             => "textfield",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Bottom padding", "k-elements" ),
		"param_name"       => "padding_bottom",
		"value"            => "40",
		"description"      => __( "Allowed measures: px,em,%,pt,cm." ),
		'edit_field_class' => 'vc_col-sm-3 vc_column',
	) );
	vc_add_param( "vc_row", array(
		"type"             => "textfield",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Left padding", "k-elements" ),
		"param_name"       => "padding_left",
		"value"            => "",
		"description"      => __( "Allowed measures: px,em,%,pt,cm." ),
		'edit_field_class' => 'vc_col-sm-3 vc_column',
	) );
	vc_add_param( "vc_row", array(
		"type"             => "textfield",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Right padding", "k-elements" ),
		"param_name"       => "padding_right",
		"value"            => "",
		"description"      => __( "Allowed measures: px,em,%,pt,cm." ),
		'edit_field_class' => 'vc_col-sm-3 vc_column',
	) );


	vc_add_param( "vc_row", array(
		"type"             => "textfield",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Top margin", "k-elements" ),
		"param_name"       => "margin_top",
		"value"            => "",
		"description"      => __( "Allowed measures: px,em,%,pt,cm." ),
		'edit_field_class' => 'vc_col-sm-6 vc_column',
	) );
	vc_add_param( "vc_row", array(
		"type"             => "textfield",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Bottom margin", "k-elements" ),
		"param_name"       => "margin_bottom",
		"value"            => "",
		"description"      => __( "Allowed measures: px,em,%,pt,cm." ),
		'edit_field_class' => 'vc_col-sm-6 vc_column',
	) );
	vc_add_param( "vc_row", array(
		"type"             => "textfield",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Minim height", "k-elements" ),
		"param_name"       => "min_height",
		"value"            => "0",
		"description"      => __( "Allowed measures: px,em,%,pt,cm." ),
		'edit_field_class' => 'vc_col-sm-6 vc_column',
	) );
	vc_add_param( "vc_row", array(
		"type"             => "textfield",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Fixed height", "k-elements" ),
		"param_name"       => "fixed_height",
		"value"            => "0",
		"description"      => __( "Allowed measures: px,em,%,pt,cm." ),
		'edit_field_class' => 'vc_col-sm-6 vc_column',
	) );

	vc_add_param( 'vc_row', array(
		'param_name'  => 'border',
		'heading'     => __( 'Border', 'k-elements' ),
		'description' => __( 'Select whether or not to display a border on this section.', 'k-elements' ),
		'type'        => 'dropdown',
		"holder"      => 'div',
		'class'       => 'hide hidden',
		'value'       => array(
			'None'       => 'none',
			'Bottom'     => 'bottom',
			'Top'        => 'top',
			'Left'       => 'left',
			'Right'      => 'right',
			'Horizontal' => 'horizontal',
			'Vertical'   => 'vertical',
			'All'        => 'all'
		)
	) );

	// overflow hidden
	vc_add_param( "vc_row", array(
		"type"        => "checkbox",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Overflow hidden", "k-elements" ),
		"param_name"  => "overflow",
		'description' => __( 'Check if you want to hide section overflow', 'k-elements' ),
		"value"       => array(
			"" => "false"
		)
	) );

	vc_add_param( "vc_row", $animation );
	vc_add_param( "vc_row", $css_animation );

	vc_add_param( "vc_row", $visibility );

	vc_add_param( "vc_row", array(
		"type"       => "textfield",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Custom inline style", "k-elements" ),
		"param_name" => "inline_style",
		"value"      => ""
	) );

	vc_add_param( "vc_row", $el_class );

	vc_add_param( "vc_row", array(
		"type"        => "textfield",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Element ID", "k-elements" ),
		"param_name"  => "el_id",
		"value"       => "",
		"description" => "Set an ID for this section(without #). Can be used for One page links"
	) );


	/* vc_row_inner */
	vc_add_param( 'vc_row_inner', array(
		'param_name'  => 'inner_container',
		'heading'     => __( 'Inner Container', 'k-elements' ),
		'description' => __( 'Select whether to insert a container to the section. This will keep the content from going full with.', 'k-elements' ),
		'type'        => 'dropdown',
		'holder'      => 'div',
		'class'       => 'hide hidden',
		"admin_label" => true,
		"value"       => array(
			'No'  => 'no',
			'Yes' => 'yes'
		)
	) );
	vc_add_param( 'vc_row_inner', array(
		"type"             => "textfield",
		"holder"           => "div",
		"class"            => "hide hidden",
		"heading"          => __( "Min. height", "k-elements" ),
		"param_name"       => "min_height",
		"value"            => "",
		"description"      => __( "Force a minimum height. Use px|em|%|pt|cm" ),
		'edit_field_class' => 'vc_col-sm-6 vc_column',
	) );

	vc_add_param( 'vc_row_inner', array(
		"type"             => "textfield",
		"holder"           => "div",
		"class"            => "hide hidden",
		"heading"          => __( "Fixed height", "k-elements" ),
		"param_name"       => "fixed_height",
		"value"            => "",
		"description"      => __( "Force a fixed height. Use px|em|%|pt|cm" ),
		'edit_field_class' => 'vc_col-sm-6 vc_column',
	) );
	vc_add_param( "vc_row_inner", array(
		"type"             => "dropdown",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Background horizontal position", "k-elements" ),
		"param_name"       => "bg_pos_h",
		"value"            => array(
			"Default" => "",
			"Left"    => "left",
			"Center"  => "center",
			"Right"   => "right"
		),
		"description"      => __( "" ),
		'edit_field_class' => 'vc_col-sm-6 vc_column',
		'group'            => __( 'Design Options', 'k-elements' ),
	) );

	vc_add_param( "vc_row_inner", array(
		"type"             => "dropdown",
		"holder"           => 'div',
		'class'            => 'hide hidden',
		"heading"          => __( "Background vertical position", "k-elements" ),
		"param_name"       => "bg_pos_v",
		"value"            => array(
			"Default" => "",
			"Top"     => "top",
			"Center"  => "center",
			"Bottom"  => "bottom"
		),
		"description"      => __( "" ),
		'edit_field_class' => 'vc_col-sm-6 vc_column',
		'group'            => __( 'Design Options', 'k-elements' ),
	) );

	vc_add_param( "vc_row_inner", array(
		"type"        => "checkbox",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Enable dark gradient", "k-elements" ),
		"param_name"  => "bg_gradient",
		"description" => "",
		'value'       => array(
			'Yes' => 'yes'
		),
		'group'       => __( 'Design Options', 'k-elements' ),
	) );


	/* Inner column */

	vc_map_update( "vc_column_inner", array( "allowed_container_element" => true ) );


	vc_add_param( "vc_column_inner", array(
		"type"        => "checkbox",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Enable dark gradient", "k-elements" ),
		"param_name"  => "bg_gradient",
		"description" => "",
		'value'       => array(
			'Yes' => 'yes'
		),
		'group'       => __( 'Design Options', 'k-elements' ),
	) );

	vc_add_param( "vc_column", array(
		"type"        => "checkbox",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Enable dark gradient", "k-elements" ),
		"param_name"  => "bg_gradient",
		"description" => "",
		'value'       => array(
			'Yes' => 'yes'
		),
		'group'       => __( 'Design Options', 'k-elements' ),
	) );

	vc_add_param( "vc_column", array(
		"type"        => "textfield",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Z-index" ),
		"param_name"  => "z_index",
		"value"       => '',
		"description" => __( "Stack order for overlapping elements. Use integer value." ),
	) );


	/* VC Message */

	add_action( 'vc_after_init', 'kleo_add_icons_to_vc_message' );

	vc_add_param( 'vc_message', array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon', 'k-elements' ),
		'param_name'  => 'icon_fontello',
		'value'       => 'icon-info-circled', // default value to backend editor admin_label
		'settings'    => array(
			'emptyIcon'    => false,
			'type'         => 'fontello',
			'iconsPerPage' => 4000,
		),
		'dependency'  => array(
			'element' => 'icon_type',
			'value'   => 'fontello',
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
		'weight'      => 1,
	) );


	vc_remove_param( 'vc_message', 'css_animation' );
	vc_add_param( "vc_message", $animation );
	vc_add_param( "vc_message", $css_animation );


	/* Text column */
	vc_remove_param( 'vc_column_text', 'css_animation' );
	vc_remove_param( 'vc_column_text', 'el_class' );
	vc_map_update( 'vc_column_text', array( 'weight' => '990' ) );
	vc_add_param( "vc_column_text", array(
		"type"        => "dropdown",
		"class"       => "hide hidden",
		"holder"      => 'div',
		"heading"     => __( "Lead Content", "k-elements" ),
		"admin_label" => true,
		"param_name"  => "lead",
		"value"       => array(
			"No"  => "",
			"Yes" => "yes"
		),
		"description" => ""
	) );
	vc_add_param( "vc_column_text", array(
		"type"        => "colorpicker",
		"class"       => "hide hidden",
		"holder"      => 'div',
		"heading"     => __( "Text color", "k-elements" ),
		"admin_label" => true,
		"param_name"  => "text_color",
		"value"       => "",
		"description" => "Set a custom text color."
	) );
	vc_add_param( "vc_column_text", array(
		"type"        => "textfield",
		"class"       => "hide hidden",
		"holder"      => 'div',
		"heading"     => __( "Font size", "k-elements" ),
		"admin_label" => true,
		"param_name"  => "font_size",
		"value"       => "",
		"description" => "Set a custom Font size. Use px|em|%|pt|cm"
	) );
	vc_add_param( "vc_column_text", array(
		"type"        => "textfield",
		"class"       => "hide hidden",
		"holder"      => 'div',
		"heading"     => __( "Line Height", "k-elements" ),
		"admin_label" => true,
		"param_name"  => "line_height",
		"value"       => "",
		"description" => "Set a custom line height. Use px|em|%|pt|cm"
	) );

	vc_add_params( "vc_column_text", $responsive_font );
	vc_add_param( 'vc_column_text', array(
		"type"        => "dropdown",
		"class"       => "hide hidden",
		"holder"      => 'div',
		"heading"     => __( "Font weight", "k-elements" ),
		"admin_label" => true,
		"param_name"  => "font_weight",
		"value"       => array(
			"Normal" => "",
			"Bold"   => "bold"
		),
		"description" => "Set a custom Font Weight for this text"
	) );


	vc_add_param( "vc_column_text", $vertical_separator );

	vc_add_param( "vc_column_text", $letter_spacing );

	vc_add_param( "vc_column_text", $animation );
	vc_add_param( "vc_column_text", $css_animation );
	vc_add_param( "vc_column_text", $el_class );


	/* VC CUSTOM HEADING */
	vc_add_param( "vc_custom_heading", $letter_spacing );
	vc_add_params( "vc_custom_heading", $responsive_font );
	vc_add_param( "vc_custom_heading", $vertical_separator );
	vc_add_param( "vc_custom_heading", $visibility );


	/* Toggle */
	vc_map_update( 'vc_toggle', array( 'name' => 'Toggle', 'description' => 'Add a Toggle element', 'weight' => 6 ) );
	vc_remove_param( 'vc_toggle', 'color' );
	vc_remove_param( 'vc_toggle', 'style' );
	vc_remove_param( 'vc_toggle', 'size' );
	vc_remove_param( 'vc_toggle', 'inverted' );
	vc_remove_param( 'vc_toggle', 'css_animation' );
	vc_remove_param( 'vc_toggle', 'el_class' );

	vc_add_param( "vc_toggle", array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon when toggle is opened' ),
		'param_name'  => 'icon',
		'value'       => '', // default value to backend editor admin_label
		'settings'    => array(
			'emptyIcon'    => false,
			'type'         => 'fontello',
			'iconsPerPage' => 4000,
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	) );

	vc_add_param( "vc_toggle", array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon when toggle is closed' ),
		'param_name'  => 'icon_closed',
		'value'       => '', // default value to backend editor admin_label
		'settings'    => array(
			'emptyIcon'    => false,
			'type'         => 'fontello',
			'iconsPerPage' => 4000,
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	) );
	vc_add_param( "vc_toggle", array(
		"type"        => "dropdown",
		"class"       => "hide hidden",
		"holder"      => 'div',
		"heading"     => __( "Icon position" ),
		"admin_label" => true,
		"param_name"  => "icon_position",
		"value"       => array(
			"Left"  => "to-left",
			"Right" => "to-right"
		),
		"description" => ""
	) );

	vc_add_param( "vc_toggle", $tooltip );
	vc_add_param( "vc_toggle", $tooltip_position );
	vc_add_param( "vc_toggle", $tooltip_title );
	vc_add_param( "vc_toggle", $tooltip_text );
	vc_add_param( "vc_toggle", $tooltip_action );


	vc_add_param( "vc_toggle", $animation );
	vc_add_param( "vc_toggle", $css_animation );
	vc_add_param( "vc_toggle", $el_class ); //add the class field


	/* Single Image */

	vc_remove_param( 'vc_single_image', 'css_animation' );
	vc_remove_param( 'vc_single_image', 'el_class' );

	vc_add_param( 'vc_single_image', array(
		"type"        => "dropdown",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Full width image" ),
		"param_name"  => "full_width",
		"value"       => array(
			'No'  => '',
			'Yes' => 'yes'
		),
		'description' => 'If enabled then the image will stretch to the width of the container'
	) );

	vc_add_param( 'vc_single_image', array(
		'param_name'  => 'box_shadow',
		'heading'     => __( 'Image effects', 'k-elements' ),
		'description' => 'Apply nice effects to the image element',
		'type'        => 'dropdown',
		'class'       => 'hide hidden',
		'holder'      => 'div',
		'value'       => array(
			'No effect'             => '',
			'Box Shadow'            => 'yes',
			'Hover Zoom and shadow' => 'zoom',
		)
	) );


	vc_add_param( "vc_single_image", $animation );
	vc_add_param( "vc_single_image", $css_animation );
	vc_add_param( "vc_single_image", $el_class ); //add the class field
	vc_add_param( "vc_single_image", $visibility );


	/* Gallery */
	vc_map_update( 'vc_gallery', array( 'description' => '' ) );
	vc_remove_param( 'vc_gallery', 'interval' );
	vc_remove_param( 'vc_gallery', 'css_animation' );
	vc_remove_param( 'vc_gallery', 'el_class' );
	vc_add_param( 'vc_gallery', array(
		"type"       => "dropdown",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Type" ),
		"param_name" => "type",
		"value"      => array(
			'Big image + thumbs' => 'thumbs',
			'Grid'               => 'grid'
		)
	) );
	vc_add_param( 'vc_gallery', array(
		'param_name'  => 'enable_animation',
		'heading'     => __( 'Enable Fading animation', 'k-elements' ),
		'description' => 'When enabled you will have a nice fading animation',
		'type'        => 'checkbox',
		'class'       => 'hide hidden',
		'holder'      => 'div',
		'value'       => array(
			'Yes' => 'yes',
		),
		'std'         => 'yes'
	) );
	vc_add_param( 'vc_gallery', array(
		'param_name'  => 'thumbs_over_img',
		'heading'     => __( 'Thumbnail over image', 'k-elements' ),
		'description' => 'Thumbnails will show on hover over the main image',
		'type'        => 'checkbox',
		'class'       => 'hide hidden',
		'holder'      => 'div',
		'value'       => array(
			'Yes' => 'yes',
		),
		"dependency"  => array(
			"element" => "type",
			"value"   => "thumbs"
		)
	) );
	vc_add_param( 'vc_gallery', array(
		"type"       => "dropdown",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Number of items per row" ),
		"param_name" => "grid_number",
		"value"      => array(
			'6' => '6',
			'5' => '5',
			'4' => '4',
			'3' => '3',
			'2' => '2'
		),
		"std"        => "6",
		"dependency" => array(
			"element" => "type",
			"value"   => "grid"
		)
	) );
	vc_add_param( 'vc_gallery', array(
		"type"       => "checkbox",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Show Caption ?" ),
		"param_name" => "show_caption",
		"value"      => array(
			'Show Caption under image in prettyphoto pop-up' => 'yes'
		)
	) );
	vc_add_param( 'vc_gallery', array(
		"type"       => "dropdown",
		"holder"     => 'div',
		'class'      => 'hide hidden',
		"heading"    => __( "Gap" ),
		"param_name" => "gap",
		"value"      => array(
			'None'  => '',
			'Small' => 'small',
			'Large' => 'large'
		),
		"dependency" => array(
			"element" => "type",
			"value"   => "grid"
		)
	) );


	vc_add_param( 'vc_gallery', $el_class );

	/* Block Grid */
	if ( isset( $k_elements['kleo_grid'] ) ) {
		vc_map(
			array(
				'base'            => 'kleo_grid',
				'name'            => __( 'Feature Items Grid', 'k-elements' ),
				'weight'          => 6,
				'class'           => '',
				'icon'            => 'block-grid',
				'category'        => "Content",
				'description'     => __( 'Easily include elements into a Grid container', 'k-elements' ),
				'as_parent'       => array( 'only' => 'kleo_feature_item' ),
				'content_element' => true,
				'js_view'         => 'VcColumnView',
				'params'          => array(
					array(
						'param_name'  => 'type',
						'heading'     => __( 'Type', 'k-elements' ),
						'description' => __( 'Select how many items you want per row.', 'k-elements' ),
						'type'        => 'dropdown',
						'holder'      => "div",
						'class'       => 'hide hidden',
						'value'       => array(
							'One'   => '1',
							'Two'   => '2',
							'Three' => '3',
							'Four'  => '4'
						)
					),
					array(
						'param_name'  => 'animation',
						'heading'     => __( 'Animation', 'k-elements' ),
						'description' => __( 'Animate elements inside the grid one by one', 'k-elements' ),
						'type'        => 'dropdown',
						'holder'      => "div",
						'class'       => 'hide hidden',
						'value'       => array(
							'No'  => '',
							'Yes' => 'yes',
						)
					),
					array(
						'param_name'  => 'colored_icons',
						'heading'     => __( 'Colored icons', 'k-elements' ),
						'description' => __( 'Show colored icons. Color will be taken from the Highlight color from Theme options', 'k-elements' ),
						'type'        => 'dropdown',
						'holder'      => "div",
						'class'       => 'hide hidden',
						'value'       => array(
							'No'  => '',
							'Yes' => 'yes',
						)
					),
					array(
						'param_name'  => 'bordered_icons',
						'heading'     => __( 'Bordered icons', 'k-elements' ),
						'description' => __( 'Show bordered icons', 'k-elements' ),
						'type'        => 'dropdown',
						'holder'      => "div",
						'class'       => 'hide hidden',
						'value'       => array(
							'No'  => '',
							'Yes' => 'yes',
						)
					),
					array(
						'param_name'  => 'style',
						'heading'     => __( 'Style', 'k-elements' ),
						'description' => __( 'Choose a different style', 'k-elements' ),
						'type'        => 'dropdown',
						'holder'      => "div",
						'class'       => 'hide hidden',
						'value'       => array(
							'Default' => '',
							'Box'     => 'box',
						)
					),
					array(
						'param_name'  => 'divided',
						'heading'     => __( 'Divided Items', 'k-elements' ),
						'description' => __( 'Apply border between items', 'k-elements' ),
						'type'        => 'dropdown',
						'holder'      => "div",
						'class'       => 'hide hidden',
						'value'       => array(
							'No'  => '',
							'Yes' => 'yes',
						)
					),
					array(
						'param_name'  => 'title_only',
						'heading'     => __( 'Title only', 'k-elements' ),
						'description' => __( 'Just the Title visible by default. Content is visible on item hover', 'k-elements' ),
						'type'        => 'dropdown',
						'holder'      => "div",
						'class'       => 'hide hidden',
						'value'       => array(
							'No'  => '',
							'Yes' => 'yes',
						)
					),
					array(
						'param_name'  => 'title_size',
						'heading'     => 'Title Font Size',
						'description' => "Custom title font size. Use px|em|pt|cm",
						'type'        => 'textfield',
						'class'       => 'hide hidden',
						'holder'      => "div",
						'value'       => ""
					),
					array(
						'param_name'  => 'box_shadow',
						'heading'     => __( 'Enable box shadow.', 'k-elements' ),
						'description' => 'Will add a nice shadow to the container',
						'type'        => 'checkbox',
						'class'       => 'hide hidden',
						'holder'      => 'div',
						'value'       => array(
							'Yes' => 'yes',
						)
					),
					$el_class,

				)
			)
		);
	}

	/* Feature item */
	if ( isset( $k_elements['kleo_feature_item'] ) ) {
		vc_map(
			array(
				'base'            => 'kleo_feature_item',
				'name'            => __( 'Feature Item', 'k-elements' ),
				'weight'          => 880,
				'class'           => '',
				'icon'            => '',
				'category'        => "Content",
				'description'     => __( 'Include a feature item in your block grid', 'k-elements' ),
				'as_child'        => array( 'only' => 'kleo_grid' ),
				'content_element' => true,
				'params'          => array(

					array(
						'param_name'  => 'title',
						'heading'     => 'Title',
						'description' => "Enter your title here",
						'type'        => 'textfield',
						'holder'      => "div",
						'value'       => ""
					),
					array(
						'param_name'  => 'content',
						'heading'     => 'Text',
						'description' => "Enter your text here",
						'type'        => 'textarea_html',
						'holder'      => "div",
						'value'       => ""
					),
					array(
						'param_name'  => 'href',
						'heading'     => 'Link',
						'description' => 'Enter your link here',
						'type'        => 'textfield',
						'holder'      => "div",
						'value'       => ''
					),
					array(
						'param_name'  => 'target',
						'heading'     => 'Link',
						'description' => '',
						'type'        => 'dropdown',
						'holder'      => 'div',
						'value'       => array(
							'Same Window' => '',
							'New Tab' => 'blank'
						),
						'dependency'  => array(
							'element'   => 'href',
							'not_empty' => true
						),
					),
					$icon, //Icons select
					array(
						"type"        => "colorpicker",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Custom icon color" ),
						"param_name"  => "icon_color",
						"value"       => '',
						"description" => ''
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Icon size" ),
						"param_name"  => "icon_size",
						"value"       => array(
							'Default' => 'default',
							'Big'     => 'big'
						),
						"description" => ""
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Icon position" ),
						"param_name"  => "icon_position",
						"value"       => array(
							'Left'   => '',
							'Center' => 'center'
						),
						"description" => ""
					)
				)
			)
		);
	}


	/* List */
	vc_map(
		array(
			'base'            => 'kleo_list',
			'name'            => __( 'Fancy List', 'k-elements' ),
			'weight'          => 6,
			'class'           => '',
			'icon'            => 'block-list',
			'category'        => "Content",
			'description'     => '',
			'as_parent'       => array( 'only' => 'kleo_list_item' ),
			'content_element' => true,
			'js_view'         => 'VcColumnView',
			'params'          => array(
				array(
					'param_name'  => 'type',
					'heading'     => __( 'Type', 'k-elements' ),
					'description' => __( 'Select the list type.', 'k-elements' ),
					'type'        => 'dropdown',
					'holder'      => "div",
					'class'       => 'hide hidden',
					'value'       => array(
						'Standard'      => 'standard',
						'With Icons'    => 'icons',
						'Ordered'       => 'ordered',
						'Ordered Roman' => 'ordered-roman',
						'Unstyled'      => 'unstyled'
					)
				),
				array(
					'param_name'  => 'icon_color',
					'heading'     => __( 'Icon color', 'k-elements' ),
					'description' => "",
					'type'        => 'dropdown',
					'holder'      => "div",
					'class'       => 'hide hidden',
					'value'       => array(
						'Normal'  => '',
						'Colored' => 'yes'
					),
					"dependency"  => array(
						"element" => "type",
						"value"   => array( "standard", "icons", "ordered", "ordered-roman" )
					)
				),
				array(
					'param_name'  => 'icon_shadow',
					'heading'     => __( 'Icon shadow', 'k-elements' ),
					'description' => "",
					'type'        => 'dropdown',
					'holder'      => "div",
					'class'       => 'hide hidden',
					'value'       => array(
						'Normal' => '',
						'Shadow' => 'yes'
					),
					"dependency"  => array(
						"element" => "type",
						"value"   => "icons"
					)
				),
				array(
					'param_name'  => 'icon_large',
					'heading'     => __( 'Large icon', 'k-elements' ),
					'description' => "",
					'type'        => 'dropdown',
					'holder'      => "div",
					'class'       => 'hide hidden',
					'value'       => array(
						'Normal' => '',
						'Large'  => 'yes'
					),
					"dependency"  => array(
						"element" => "type",
						"value"   => "icons"
					)
				),
				array(
					'param_name'  => 'inline',
					'heading'     => __( 'Inline', 'k-elements' ),
					'description' => "",
					'type'        => 'dropdown',
					'holder'      => "div",
					'class'       => 'hide hidden',
					'value'       => array(
						'No'  => '',
						'Yes' => 'yes'
					),
					"dependency"  => array(
						"element" => "type",
						"value"   => "icons"
					)
				),

				array(
					'param_name'  => 'divider',
					'heading'     => __( 'Divider', 'k-elements' ),
					'description' => "",
					'type'        => 'dropdown',
					'holder'      => "div",
					'class'       => 'hide hidden',
					'value'       => array(
						'No'     => '',
						'Solid'  => 'yes',
						'Dashed' => 'dashed'
					),
				),
				array(
					'param_name'  => 'align',
					'heading'     => __( 'Align', 'k-elements' ),
					'description' => __( 'Align the list', 'k-elements' ),
					'type'        => 'dropdown',
					'holder'      => "div",
					'class'       => 'hide hidden',
					'value'       => array(
						'None'   => '',
						'Left'   => 'left',
						'Right'  => 'right',
						'Center' => 'center'
					)
				),


				$el_class,

			)
		)
	);

	/* List item */

	vc_map(
		array(
			'base'            => 'kleo_list_item',
			'name'            => __( 'List Item', 'k-elements' ),
			'weight'          => 880,
			'category'        => "Content",
			'description'     => '',
			'as_child'        => array( 'only' => 'kleo_list' ),
			'content_element' => true,
			'params'          => array(
				$icon,
				array(
					'param_name'  => 'content',
					'heading'     => 'Text',
					'description' => "Enter your text here",
					'type'        => 'textarea_html',
					'holder'      => "div",
					'value'       => ""
				),
			)
		)
	);


	/* TABS */
	vc_map_update( "vc_tabs",
		array(
			"name"            => "Kleo Tabs",
			'category'        => __( "Content", 'k-elements' ),
			"deprecated"      => null,
			"content_element" => true
		)
	);
	vc_map_update( "vc_tab",
		array(
			"name"            => "Kleo Tab",
			'category'        => __( "Content", 'k-elements' ),
			"deprecated"      => null,
		)
	);

	vc_remove_param( 'vc_tabs', 'interval' );
	vc_remove_param( 'vc_tabs', 'title' );
	vc_remove_param( 'vc_tabs', 'el_class' );

	vc_map_update( "vc_tabs", array( 'weight' => 6 ) );

	vc_add_param( 'vc_tabs', array(
		"type"        => "dropdown",
		"holder"      => "div",
		"class"       => "hide hidden",
		"heading"     => __( "Type", "k-elements" ),
		"param_name"  => "type",
		"value"       => array(
			'Tabs'  => 'tabs',
			'Pills' => 'pills'
		),
		"description" => "Choose how you want them to appear"
	) );
	vc_add_param( 'vc_tabs', array(
		"type"        => "dropdown",
		"holder"      => "div",
		"class"       => "hide hidden",
		"heading"     => __( "Style", "k-elements" ),
		"param_name"  => "style",
		"value"       => array(
			'Default' => 'default',
			'Square'  => 'square',
			'Line'    => 'line',
			'Text'    => 'text'
		),
		"dependency"  => array(
			"element" => "type",
			"value"   => "tabs"
		),
		"description" => ""
	) );
	vc_add_param( 'vc_tabs', array(
		"type"        => "dropdown",
		"holder"      => "div",
		"class"       => "hide hidden",
		"heading"     => __( "Style", "k-elements" ),
		"param_name"  => "style_pills",
		"value"       => array(
			'Square' => 'square',
			'Ghost'  => 'ghost'
		),
		"dependency"  => array(
			"element" => "type",
			"value"   => "pills"
		),
		"description" => "",
	) );
	vc_add_param( 'vc_tabs', array(
		'param_name'  => 'active_tab',
		'heading'     => 'Active tab',
		'description' => "Enter tab number to be active on load(Example: 1)",
		'type'        => 'textfield',
		'holder'      => "div",
		'class'       => "hide hidden",
		'value'       => ""
	) );
	vc_add_param( 'vc_tabs', array(
		'param_name'  => 'no_tab_drop',
		'heading'     => 'Disable Tab Drop',
		'description' => "Disable Tabdrop to be initialized for this element.",
		'type'        => 'dropdown',
		'holder'      => "div",
		'value'       => array(
			"No"      => "",
			"Yes"     => "Yes",

		)
	) );

	vc_add_param( 'vc_tabs', array(
		"type"        => "dropdown",
		"holder"      => "div",
		"class"       => "hide hidden",
		"heading"     => __( "Align", "k-elements" ),
		"param_name"  => "align",
		"value"       => array(
			'Left'     => '',
			'Centered' => 'centered',
		),
		"description" => ""
	) );
	vc_add_param( 'vc_tabs', array(
		'param_name'  => 'margin_top',
		'heading'     => 'Top Margin',
		'description' => "Enter the value in pixels. Eq. 50. Field accepts negative values.",
		'type'        => 'textfield',
		'holder'      => "div",
		'class'       => "hide hidden",
		'value'       => ""
	) );

	vc_add_param( 'vc_tabs', $el_class );

	vc_map_update(
		"vc_tab",
		array(
			"allowed_container_element" => true,
			"deprecated"                => null
		)
	);

	vc_remove_param( 'vc_tab', 'tab_id' );
	vc_add_param( 'vc_tab', array(
		'param_name' => 'tab_id',
		'heading'    => 'Tab ID',
		'type'       => 'textfield',
		'holder'     => "div",
		'class'      => "hide hidden",
		'value'      => ""
	) );

	kleo_vc_add_icon( 'vc_tab' );
	//vc_add_param('vc_tab', $icon);


	/* Tours */
	vc_map_update( "vc_tour",
		array(
			"name"            => "Kleo Tour",
			'category'        => __( "Content", 'k-elements' ),
			"deprecated"      => null,
			"content_element" => true
		)
	);
	vc_remove_param( 'vc_tour', 'el_class' );
	vc_add_param( 'vc_tour', array(
		"type"        => "dropdown",
		"holder"      => "div",
		"class"       => "hide hidden",
		"heading"     => __( "Tabs Position", "k-elements" ),
		"param_name"  => "position",
		"value"       => array(
			'Left'  => '',
			'Right' => 'right'
		),
		"description" => "Choose how you want them to appear"
	) );
	vc_add_param( 'vc_tour', $el_class );

	/* Accordion */
	vc_map_update( "vc_accordion",
		array(
			"name"            => "Kleo Accordion",
			'category'        => __( "Content", 'k-elements' ),
			"deprecated"      => null,
			"content_element" => true
		)
	);
	vc_remove_param( 'vc_accordion', 'interval' );
	vc_remove_param( 'vc_accordion', 'title' );
	vc_remove_param( 'vc_accordion', 'el_class' );
	vc_map_update( "vc_accordion", array( 'weight' => 6, 'description' => '' ) );

	vc_add_param( "vc_accordion", array(
		"type"        => "dropdown",
		"class"       => "hide hidden",
		"holder"      => 'div',
		"heading"     => __( "Icons position" ),
		"admin_label" => true,
		"param_name"  => "icons_position",
		"value"       => array(
			"Left"  => "to-left",
			"Right" => "to-right"
		),
		"description" => ""
	) );
	vc_add_param( 'vc_accordion', $el_class );


	vc_map_update( "vc_accordion_tab",
		array(
			"deprecated" => null,
		)
	);

	vc_map_update( "vc_accordion_tab", array( "allowed_container_element" => true ) );
	vc_add_param( "vc_accordion_tab", array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon when accordion item is opened', 'k-elements' ),
		'param_name'  => 'icon',
		'value'       => '', // default value to backend editor admin_label
		'settings'    => array(
			'emptyIcon'    => false,
			'type'         => 'fontello',
			'iconsPerPage' => 4000,
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	) );
	vc_add_param( "vc_accordion_tab", array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon when accordion item is closed', 'k-elements' ),
		'param_name'  => 'icon_closed',
		'value'       => '', // default value to backend editor admin_label
		'settings'    => array(
			'emptyIcon'    => false,
			'type'         => 'fontello',
			'iconsPerPage' => 4000,
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	) );

	vc_add_param( "vc_accordion_tab", $tooltip );
	vc_add_param( "vc_accordion_tab", $tooltip_position );
	vc_add_param( "vc_accordion_tab", $tooltip_title );
	vc_add_param( "vc_accordion_tab", $tooltip_text );
	vc_add_param( "vc_accordion_tab", $tooltip_action );


	/* Icon */
	vc_map(
		array(
			'base'        => 'kleo_icon',
			'name'        => 'Kleo Icon',
			'weight'      => 5,
			'class'       => '',
			'icon'        => 'icon-wpb-vc_icon',
			'category'    => __( "Content", 'k-elements' ),
			'description' => __( 'Insert an icon into your content', 'k-elements' ),
			'params'      => array(
				$icon,
				$icon_size,
				array(
					'param_name'  => 'icon_color',
					'type'        => 'colorpicker',
					'heading'     => __( 'Icon color', 'k-elements' ),
					'class'       => 'hide hidden',
					'description' => __( 'Select custom icon color.', 'k-elements' ),
				),
				$tooltip,
				$tooltip_position,
				$tooltip_title,
				$tooltip_text,
				$tooltip_action,
				array(
					"param_name"  => "position",
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Position" ),
					"value"       => array(
						'Inline' => 'inline',
						'Left'   => 'left',
						'Right'  => 'right',
						'Center' => 'center',
					),
					"description" => "Choose how to position the icon"
				),
				array(
					'param_name' => 'text',
					'heading'    => __( 'Add a text to the icon', 'k-elements' ),
					'type'       => 'textfield',
					'class'      => 'hide hidden',
					'holder'     => 'div',
					'value'      => ''
				),
				array(
					'param_name'  => 'text_position',
					'heading'     => __( 'Text position', 'k-elements' ),
					'description' => '',
					'type'        => 'dropdown',
					'class'       => 'hide hidden',
					'holder'      => 'div',
					"dependency"  => array(
						"element"   => "text",
						"not_empty" => true
					),
					'value'       => array(
						'Left'  => 'left',
						'Right' => 'right'
					)
				),
				array(
					'param_name'  => 'font_size',
					'heading'     => __( 'Font size', 'k-elements' ),
					'description' => 'Use px|em|pt|cm',
					'type'        => 'textfield',
					'class'       => 'hide hidden',
					'holder'      => 'div',
					"dependency"  => array(
						"element"   => "text",
						"not_empty" => true
					),
					'value'       => ''
				),
				array(
					'param_name'  => 'href',
					'heading'     => __( 'Add a link', 'k-elements' ),
					'description' => __( 'Type a http:// address', 'k-elements' ),
					'type'        => 'textfield',
					'class'       => 'hide hidden',
					'holder'      => 'div',
					'value'       => ''
				),
				array(
					'param_name'  => 'target',
					'heading'     => __( 'Target window', 'k-elements' ),
					'description' => '',
					'type'        => 'dropdown',
					'class'       => 'hide hidden',
					'holder'      => 'div',
					"dependency"  => array(
						"element"   => "href",
						"not_empty" => true
					),
					'value'       => array(
						'Same window' => '_self',
						'New window'  => '_blank'
					)
				),
				array(
					'param_name'  => 'scroll_to',
					'heading'     => __( 'Enable smooth scroll to page section.', 'k-elements' ),
					'description' => 'Works only when you have links to sections in the current page, like #section',
					'type'        => 'checkbox',
					'class'       => 'hide hidden',
					'holder'      => 'div',
					"dependency"  => array(
						"element"   => "href",
						"not_empty" => true
					),
					'value'       => array(
						'Yes' => 'yes',
					)
				),
				array(
					'param_name'  => 'padding',
					'heading'     => 'Left/right padding',
					'description' => "Adds padding to the left and right sides of the icon",
					'type'        => 'textfield',
					'class'       => 'hide hidden',
					'holder'      => "div",
					'value'       => ""
				),
				$el_class
			)

		)
	);

	/* vc_progress_bar  */
	vc_remove_param( 'vc_progress_bar', 'el_class' );
	vc_add_param( "vc_progress_bar", array(
		'param_name'  => 'style',
		'heading'     => __( 'Style', 'k-elements' ),
		'description' => __( 'Choose the bar appearance', 'k-elements' ),
		'type'        => 'dropdown',
		'class'       => 'hide hidden',
		'holder'      => 'div',
		'value'       => array(
			'Regular' => '',
			'Slim'    => 'slim'
		)
	) );
	vc_add_param( 'vc_progress_bar', $el_class );

	/* Clients */

	if ( isset( $k_elements['kleo_clients'] ) ) {
		$client_tags = array();

		$defined_tags = get_terms( 'clients-tag' );
		if ( is_array( $defined_tags ) && ! empty( $defined_tags ) ) {
			//var_dump($defined_tags);
			foreach ( $defined_tags as $tag ) {
				$client_tags[ $tag->name ] = $tag->term_id;
			}

		}

		vc_map(
			array(
				'base'        => 'kleo_clients',
				'name'        => 'Clients',
				'weight'      => 5,
				'class'       => '',
				'icon'        => 'kleo_clients',
				'category'    => __( "Content", 'k-elements' ),
				'description' => __( 'Showcase clients logos', 'k-elements' ),
				'params'      => array(
					array(
						'param_name'  => 'animated',
						'heading'     => __( 'Animated', 'k-elements' ),
						'description' => __( 'Animate the icons when you first view them', 'k-elements' ),
						'type'        => 'dropdown',
						'class'       => 'hide hidden',
						'holder'      => 'div',
						'value'       => array(
							'Yes' => 'yes',
							'No'  => ''
						)
					),
					array(
						'param_name'  => 'animation',
						'heading'     => __( 'Animation', 'k-elements' ),
						'description' => "",
						'type'        => 'dropdown',
						'class'       => 'hide hidden',
						'holder'      => 'div',
						'value'       => array(
							'Fade'   => 'fade',
							'Appear' => 'appear'
						),
						"dependency"  => array(
							"element" => "animated",
							"value"   => "yes"
						),
					),
					array(
						'param_name'  => 'number',
						'heading'     => __( 'Number of logos', 'k-elements' ),
						'description' => "How many images to show",
						'type'        => 'textfield',
						'class'       => 'hide hidden',
						'holder'      => "div",
						'value'       => "5"
					),
					array(
						'param_name'  => 'target',
						'heading'     => __( 'Open links in new window', 'k-elements' ),
						'description' => "",
						'type'        => 'dropdown',
						'class'       => 'hide hidden',
						'holder'      => 'div',
						'value'       => array(
							'No'  => '',
							'Yes' => '_blank'
						)
					),
					array(
						'param_name'  => 'tags',
						'heading'     => __( 'Filter by Tags', 'k-elements' ),
						'description' => "",
						'type'        => 'checkbox',
						'class'       => 'hide hidden',
						'holder'      => 'div',
						'value'       => $client_tags
					),
					$el_class
				)

			) );
	}

	/* Testimonials */

	if ( isset( $k_elements['kleo_testimonials'] ) ) {
		$testimonial_tags = array();

		$defined_tags = get_terms( 'testimonials-tag' );
		if ( is_array( $defined_tags ) && ! empty( $defined_tags ) ) {

			foreach ( $defined_tags as $tag ) {
				$testimonial_tags[ $tag->name ] = $tag->term_id;
			}

		}

		vc_map(
			array(
				'base'        => 'kleo_testimonials',
				'name'        => 'Testimonials',
				'weight'      => 5,
				'class'       => '',
				'icon'        => 'kleo_testimonials',
				'category'    => __( "Content", 'k-elements' ),
				'description' => __( 'Showcase client testimonials', 'k-elements' ),
				'params'      => array(

					array(
						'param_name'  => 'type',
						'heading'     => __( 'Type', 'k-elements' ),
						'description' => "",
						'type'        => 'dropdown',
						'class'       => 'hide hidden',
						'holder'      => 'div',
						'value'       => array(
							'Simple'                    => 'simple',
							'Carousel'                  => 'carousel',
							'Boxed with 5 star ratings' => 'boxed'
						)
					),
					array(
						'param_name'  => 'specific_id',
						'heading'     => __( 'By IDs', 'k-elements' ),
						'description' => "",
						'type'        => 'dropdown',
						'class'       => 'hide hidden',
						'holder'      => 'div',
						'value'       => array(
							'No'  => 'no',
							'Yes' => 'yes'
						)
					),
					array(
						'param_name'  => 'ids',
						'heading'     => __( 'Testimonials IDs to show.', 'k-elements' ),
						'description' => __( "Comma separated list of ids to display. ", "k-elements" ),
						"dependency"  => array(
							"element" => "specific_id",
							"value"   => "yes"
						),
						'type'        => 'textfield',
						'class'       => 'hide hidden',
						'holder'      => "div",
						'value'       => ""
					),
					array(
						'param_name'  => 'number',
						'heading'     => __( 'Number of testimonials', 'k-elements' ),
						'description' => "How many testimonials to show. Default is 3",
						"dependency"  => array(
							"element" => "specific_id",
							"value"   => "no"
						),
						'type'        => 'textfield',
						'class'       => 'hide hidden',
						'holder'      => "div",
						'value'       => ""
					),
					array(
						'param_name'  => 'offset',
						'heading'     => __( 'Testimonials offset', 'k-elements' ),
						'description' => "Display testimonials starting from the number you enter. Eq: if you enter 3, it will show testimonials from the 4th one",
						"dependency"  => array(
							"element" => "specific_id",
							"value"   => "no"
						),
						'type'        => 'textfield',
						'class'       => 'hide hidden',
						'holder'      => "div",
						'value'       => ""
					),

					array(
						'param_name'  => 'tags',
						'heading'     => __( 'Filter by Tags', 'k-elements' ),
						'description' => "",
						"dependency"  => array(
							"element" => "specific_id",
							"value"   => "no"
						),
						'type'        => 'checkbox',
						'class'       => 'hide hidden',
						'holder'      => 'div',
						'value'       => $testimonial_tags
					),
					array(
						"type"        => "textfield",
						"holder"      => 'div',
						'class'       => 'hide hidden',
						"heading"     => __( "Minimum items to show", "k-elements" ),
						"param_name"  => "min_items",
						"value"       => "",
						"description" => "Default 1",
						"dependency"  => array(
							"element" => "type",
							"value"   => "carousel"
						),
					),
					array(
						"type"        => "textfield",
						"holder"      => 'div',
						'class'       => 'hide hidden',
						"heading"     => __( "Maximum items to show", "k-elements" ),
						"param_name"  => "max_items",
						"value"       => "",
						"description" => "Default 1",
						"dependency"  => array(
							"element" => "type",
							"value"   => "carousel"
						),
					),
					array(
						"type"        => "textfield",
						"holder"      => 'div',
						'class'       => 'hide hidden',
						"heading"     => __( "Speed between slides", "k-elements" ),
						"param_name"  => "speed",
						"value"       => "",
						"description" => "In milliseconds. Default is 5000 milliseconds, meaning 5 seconds",
						"dependency"  => array(
							"element" => "type",
							"value"   => "carousel"
						),
					),
					array(
						"type"        => "textfield",
						"holder"      => 'div',
						'class'       => 'hide hidden',
						"heading"     => __( "Elements height", "k-elements" ),
						"param_name"  => "height",
						"value"       => "",
						"description" => "Force a height on all elements. Expressed in pixels, eq: 300 will represent 300px",
					),
					$el_class
				)

			) );
	}

	/* PIN */
	if ( isset( $k_elements['kleo_pin'] ) ) {
		vc_map(
			array(
				'base'        => 'kleo_pin',
				'name'        => 'Pin',
				'weight'      => 5,
				'class'       => '',
				'icon'        => 'icon-wpb-ui-icon',
				'category'    => __( "Content", 'k-elements' ),
				'description' => __( 'Add pins with info', 'k-elements' ),
				'params'      => array(
					array(
						'param_name'  => 'type',
						'heading'     => __( 'Type', 'k-elements' ),
						'description' => __( 'Type of pin', 'k-elements' ),
						'type'        => 'dropdown',
						'holder'      => 'div',
						'value'       => array(
							'Icon'   => 'icon',
							'Circle' => 'circle',
							'POI'    => 'poi'
						)
					),
					array(
						'type'        => 'iconpicker',
						'heading'     => __( 'Icon', 'k-elements' ),
						'param_name'  => 'icon',
						"class"       => "hide hidden",
						'value'       => '', // default value to backend editor admin_label
						'settings'    => array(
							'emptyIcon'    => false,
							'type'         => 'fontello',
							'iconsPerPage' => 4000,
						),
						'description' => __( 'Choose the icon to display', 'k-elements' ),
						"dependency"  => array(
							"element" => "type",
							"value"   => "icon"
						)
					),
					array(
						'param_name'  => 'top',
						'heading'     => __( 'Top position', 'k-elements' ),
						'description' => "Please enter only pixels and percentage, eq. 50px or 15%",
						'type'        => 'textfield',
						'holder'      => "div",
						'value'       => ""
					),
					array(
						'param_name'  => 'left',
						'heading'     => __( 'Left position', 'k-elements' ),
						'description' => "Please enter only pixels and percentage, eq. 50px or 15%",
						'type'        => 'textfield',
						'holder'      => "div",
						'value'       => ""
					),
					array(
						'param_name'  => 'right',
						'heading'     => __( 'Right position', 'k-elements' ),
						'description' => "Please enter only pixels and percentage, eq. 50px or 15%",
						'type'        => 'textfield',
						'holder'      => "div",
						'value'       => ""
					),
					array(
						'param_name'  => 'bottom',
						'heading'     => __( 'Bottom position', 'k-elements' ),
						'description' => "Please enter only pixels and percentage, eq. 50px or 15%",
						'type'        => 'textfield',
						'holder'      => "div",
						'value'       => ""
					),
					$tooltip,
					$tooltip_position,
					$tooltip_title,
					$tooltip_text,
					$tooltip_action,
					$animation,
					$css_animation,
					$el_class
				)

			) );
	}

	/* Button */
	vc_map(
		array(
			'base'        => 'kleo_button',
			'name'        => 'Kleo Button',
			'weight'      => 970,
			'class'       => '',
			'icon'        => 'icon-wpb-ui-button',
			'category'    => __( "Content", 'k-elements' ),
			'description' => __( 'Insert a button in your content', 'k-elements' ),
			'params'      => $button_args
		)
	);

	vc_add_param( 'kleo_button', $letter_spacing );
	vc_add_params( 'kleo_button', $box_shadow );
	vc_add_param( 'kleo_button', $el_class );


	/* Animated numbers */
	vc_map(
		array(
			'base'            => 'kleo_animate_numbers',
			'name'            => 'Animated numbers',
			'weight'          => 970,
			'content_element' => true,
			'class'           => '',
			'icon'            => 'animated-numbers',
			'category'        => __( "Content", 'k-elements' ),
			'description'     => __( 'Insert an animated number', 'k-elements' ),
			'params'          => array(
				$animation,
				array(
					'param_name'  => 'content',
					'heading'     => __( 'Number', 'k-elements' ),
					'description' => "Enter the number to animate",
					'type'        => 'textfield',
					'holder'      => "div",
					'value'       => ""
				),
				array(
					'param_name'  => 'timer',
					'heading'     => __( 'Timer', 'k-elements' ),
					'description' => "The time in miliseconds to complete the animation, eq 3000 for 3 seconds of animation",
					'type'        => 'textfield',
					'holder'      => "div",
					'class'       => "hide hidden",
					'value'       => ""
				),
				array(
					'param_name'  => 'element',
					'heading'     => __( 'HTML Element', 'k-elements' ),
					'description' => __( 'What type of HTML tag to render. Default if span', 'k-elements' ),
					'type'        => 'dropdown',
					'holder'      => 'div',
					'value'       => array(
						'span' => 'span',
						'p'    => 'p',
						'H1'   => 'h1',
						'H2'   => 'h2',
						'H3'   => 'h3',
						'H4'   => 'h4',
						'H5'   => 'h5',
						'H6'   => 'h6'
					)
				),
				array(
					"param_name"  => "font_size",
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Font size. Use px|em|pt" ),
					"value"       => '',
					"description" => "",
				),
				array(
					'param_name'  => 'font_weight',
					'heading'     => __( 'Font Weight', 'k-elements' ),
					'description' => '',
					'type'        => 'dropdown',
					'holder'      => 'div',
					'value'       => array(
						'Regular' => '',
						'Bold'    => 'bold'
					)
				),
				$el_class
			)
		)
	);
	vc_add_param( 'kleo_animate_numbers', $el_class );


	/* Responsive visibility */


	vc_map(
		array(
			'base'            => 'kleo_visibility',
			'name'            => __( 'Visibility', 'k-elements' ),
			'weight'          => 6,
			'class'           => '',
			'icon'            => 'visibility',
			'category'        => "Content",
			'description'     => __( 'Alter content based on screen size', 'k-elements' ),
			'as_parent'       => array( 'except' => 'vc_row, vc_column' ),
			'content_element' => true,
			'js_view'         => 'VcColumnView',
			'params'          => array(
				array(
					'param_name'  => 'type',
					'heading'     => __( 'Visibility Type', 'k-elements' ),
					'description' => __( 'Hide/Show content by screen size.', 'k-elements' ),
					'type'        => 'checkbox',
					'holder'      => 'div',
					'value'       => array(
						'Hidden Phones (max 768px)'            => 'hidden-xs',
						'Hidden Tablets (768px - 991px)'       => 'hidden-sm',
						'Hidden Desktops (992px - 1199px)'     => 'hidden-md',
						'Hidden Large Desktops (min 1200px)'   => 'hidden-lg',
						'Hidden XLarge Desktops (min 1440px)'  => 'hidden-xlg',
						'Visible Phones (max 767px)'           => 'visible-xs',
						'Visible Tablets (768px - 991px)'      => 'visible-sm',
						'Visible Desktops (992px - 1199px)'    => 'visible-md',
						'Visible Large Desktops (min 1200px)'  => 'visible-lg',
						'Visible XLarge Desktops (min 1440px)' => 'visible-xlg'
					)
				),
				$el_class
			)
		)
	);

	/* Content restrict by user type */


	vc_map(
		array(
			'base'            => 'kleo_restrict',
			'name'            => __( 'Content by user type', 'k-elements' ),
			'weight'          => 6,
			'class'           => '',
			'icon'            => 'kleo_restrict',
			'category'        => "Content",
			'description'     => __( 'Restrict content based on user type', 'k-elements' ),
			'as_parent'       => array( 'except' => 'vc_row, vc_column' ),
			'content_element' => true,
			"admin_label"     => true,
			'js_view'         => 'VcColumnView',
			'params'          => array(
				array(
					'param_name'  => 'type',
					'heading'     => __( 'User Type', 'k-elements' ),
					'description' => __( 'Show content for user type.', 'k-elements' ),
					'type'        => 'dropdown',
					'holder'      => 'div',
					'class'       => 'hide hidden',
					"admin_label" => true,
					'value'       => array(
						'Logged in user' => 'user',
						'Guest user'     => 'guest'
					)
				),
				$el_class
			)
		)
	);


	/* GAP */

	vc_map(
		array(
			'base'        => 'kleo_gap',
			'name'        => 'Gap',
			'weight'      => 6,
			'class'       => 'kleo-icon',
			'icon'        => 'gap',
			'category'    => __( "Content", 'k-elements' ),
			'description' => __( 'Insert a vertical gap in your content', 'k-elements' ),
			'params'      => array(
				array(
					'param_name'  => 'size',
					'heading'     => __( 'Size', 'k-elements' ),
					'description' => __( 'Enter in the size of your gap. Pixels, ems, and percentages are all valid units of measurement.', 'k-elements' ),
					'type'        => 'textfield',
					'holder'      => "div",
					'value'       => '10px'
				),
				array(
					"param_name"  => "class",
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Class", "k-elements" ),
					"value"       => '',
					"description" => __( "A class to add to the element for CSS referrences.", "k-elements" )
				),
				array(
					'param_name'  => "id",
					'heading'     => __( "Id", "k-elements" ),
					'description' => __( 'Unique id to add to the element for CSS referrences', 'k-elements' ),
					'type'        => "textfield",
					'holder'      => "div"
				),
				array(
					"param_name" => "style",
					"type"       => "textfield",
					"class"      => "",
					"holder"     => 'div',
					"heading"    => __( "Custom inline style", "k-elements" ),
					"value"      => ""
				),
				$visibility
			)
		)
	);


	/* Divider */

	vc_map(
		array(
			'base'        => 'kleo_divider',
			'name'        => 'Divider with icon',
			'weight'      => 6,
			'class'       => 'kleo-icon',
			'icon'        => 'icon-wpb-ui-separator-label',
			'category'    => __( "Content", 'k-elements' ),
			'description' => __( 'Insert a vertical divider in your content', 'k-elements' ),
			'params'      => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Type", "k-elements" ),
					"param_name"  => "type",
					"value"       => array(
						'Full'   => 'full',
						'Long'   => 'long',
						'Short'  => 'short',
						'Double' => 'double'
					),
					"description" => __( "The type of the divider.", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Double border", "k-elements" ),
					"param_name"  => "double",
					"value"       => array(
						'No'  => '',
						'Yes' => 'Yes'
					),
					"description" => __( "Have the divider double lined.", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Position", "k-elements" ),
					"param_name"  => "position",
					"value"       => array(
						'Center' => 'center',
						'Left'   => 'left',
						'Right'  => 'right'
					),
					"description" => ""
				),
			)
		) );

	kleo_vc_add_icon( 'kleo_divider' );

	vc_add_params( 'kleo_divider', array(

		array(
			"type"        => "dropdown",
			"holder"      => "div",
			"class"       => "hide hidden",
			"heading"     => __( "Icon size", "k-elements" ),
			"param_name"  => "icon_size",
			"value"       => array(
				'Normal' => '',
				'Large'  => 'large'
			),
			"description" => ""
		),
		array(
			"param_name"  => "text",
			"type"        => "textfield",
			"holder"      => "div",
			"class"       => "",
			"heading"     => __( "Text", "k-elements" ),
			"value"       => '',
			"description" => __( "This text wil show inside the divider" )
		),

		array(
			'param_name'  => "id",
			'heading'     => __( "Id", "k-elements" ),
			'description' => __( 'Unique id to add to the element for CSS referrences', 'k-elements' ),
			'type'        => "textfield",
			'holder'      => "div"
		),
		array(
			"param_name"  => "class",
			"type"        => "textfield",
			"holder"      => "div",
			"class"       => "",
			"heading"     => __( "Class", "k-elements" ),
			"value"       => '',
			"description" => __( "A class to add to the element for CSS referrences.", "k-elements" )
		),
		array(
			"param_name" => "style",
			"type"       => "textfield",
			"class"      => "",
			"holder"     => 'div',
			"heading"    => __( "Custom inline style", "k-elements" ),
			"value"      => ""
		),
	) );


	/* Posts grid */
	require_once K_ELEM_PLUGIN_DIR . 'compat/plugin-js-composer/posts_grid.php';


	/* Posts carousel */
	require_once K_ELEM_PLUGIN_DIR . 'compat/plugin-js-composer/posts_carousel.php';

	/* Image carousel */
	vc_remove_param( 'vc_images_carousel', 'title' );
	vc_remove_param( 'vc_images_carousel', 'mode' );
	vc_remove_param( 'vc_images_carousel', 'slides_per_view' );
	vc_remove_param( 'vc_images_carousel', 'partial_view' );
	vc_remove_param( 'vc_images_carousel', 'wrap' );
	vc_remove_param( 'vc_images_carousel', 'el_class' );
	//vc_remove_param('vc_images_carousel','hide_prev_next_buttons');

	vc_add_param( "vc_images_carousel", array(
		"type"        => "dropdown",
		"holder"      => "div",
		"class"       => "hide hidden",
		"heading"     => __( "Scroll effect", "k-elements" ),
		"param_name"  => "scroll_fx",
		"value"       => array(
			'Scroll'    => 'scroll',
			'CrossFade' => 'crossfade'
		),
		"description" => ""
	) );


	vc_add_param( "vc_images_carousel", $animation );
	vc_add_param( "vc_images_carousel", array(
		'param_name'  => 'css_animation',
		'heading'     => __( 'Animation type', 'k-elements' ),
		'description' => "",
		'type'        => 'dropdown',
		'class'       => 'hide hidden',
		'holder'      => 'div',
		'value'       => array(
			'Fade'   => 'fade',
			'Appear' => 'appear'
		),
		"dependency"  => array(
			"element"   => "animation",
			"not_empty" => true
		),
	) );

	vc_add_param( "vc_images_carousel", array(
		"type"        => "textfield",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Minimum items to show", "k-elements" ),
		"param_name"  => "min_items",
		"value"       => "",
		"description" => __( "Default value: 1", "k-elements" )
	) );
	vc_add_param( "vc_images_carousel", array(
		"type"        => "textfield",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Maximum items to show", "k-elements" ),
		"param_name"  => "max_items",
		"value"       => "",
		"description" => __( "Default value: 1.", "k-elements" )
	) );
	vc_add_param( "vc_images_carousel", array(
		"type"        => "textfield",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Custom width for the carousel items", "k-elements" ),
		"param_name"  => "items_width",
		"value"       => "",
		"description" => __( "Set a custom width for the items. It is optional since they are automatically calculated. Expressed in pixels. Example: If you want to have one item per row on mobile you need to set a 360 value.", "k-elements" )
	) );


	vc_add_param( 'vc_images_carousel', $el_class );


	/* Tagcloud */
	vc_map_update(
		'vc_wp_tagcloud',
		array( 'description' => 'Your most used tags or single post tags in cloud format' )
	);
	vc_add_param( 'vc_wp_tagcloud', array(
		"type"       => "dropdown",
		"heading"    => __( "Context", "k-elements" ),
		"param_name" => "context",
		"value"      => array(
			'All Posts'    => 'all_posts',
			'Current Post' => 'single_post'
		),
		"dependency" => array(
			"element" => "taxonomy",
			"value"   => "post_tag"
		),
	) );
	vc_remove_param( 'vc_wp_tagcloud', 'el_class' );
	vc_add_param( 'vc_wp_tagcloud', $el_class );


	/* Portfolio */
	if ( isset( $k_elements['kleo_portfolio'] ) ) {
		$exclude_cats = kleo_get_category_list_key_array( apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ), 'term_id' );
		unset( $exclude_cats['all'] );
		$exclude_cats = array_flip( $exclude_cats );
		vc_map(
			array(
				'base'        => 'kleo_portfolio',
				'name'        => 'Portfolio',
				'weight'      => 6,
				'class'       => 'kleo-icon',
				'icon'        => 'portfolio',
				'category'    => __( "Content", 'k-elements' ),
				'description' => __( 'Insert portfolio items', 'k-elements' ),
				'params'      => array(
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Display type", "k-elements" ),
						"param_name"  => "display_type",
						"value"       => array(
							'Default' => 'default',
							'Overlay' => 'overlay'
						),
						"description" => ''
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Title style", "k-elements" ),
						"param_name"  => "title_style",
						"dependency"  => array(
							"element" => "display_type",
							"value"   => "overlay"
						),
						"value"       => array(
							'Normal'                   => 'normal',
							'Shown only on item hover' => 'hover'
						),
						"description" => ''
					),
					array(
						"param_name"  => "columns",
						"type"        => "textfield",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( 'Number of items per row', 'k-elements' ),
						"value"       => '4',
						"description" => __( 'A number between 2 and 6', 'k-elements' )
					),
					array(
						"param_name"  => "item_count",
						"type"        => "textfield",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( 'Items to show', 'k-elements' ),
						"value"       => '',
						"description" => __( 'Leave blank for default value as in Settings - Reading', 'k-elements' )
					),
					array(
						"param_name"  => "pagination",
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Display pagination. Only if is the case it will be shown", "k-elements" ),
						"value"       => array(
							'No'  => 'no',
							'Yes' => 'yes'
						),
						"description" => ''
					),
					array(
						"param_name"  => "filter",
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Show filter on top with categories", "k-elements" ),
						"value"       => array(
							'Yes' => 'yes',
							'No'  => 'no'
						),
						"description" => ''
					),
					array(
						'param_name'  => 'ajax',
						'heading'     => __( 'Enable AJAX on filter or pagination.', 'k-elements' ),
						'description' => __( 'When clicking filters will send an AJAX request to get specific category items.Same goes for pagination', 'k-elements' ),
						'type'        => 'checkbox',
						'class'       => 'hide hidden',
						'holder'      => 'div',
						'value'       => array(
							'Yes' => 'yes',
						),
					),
					array(
						"param_name"  => "excerpt",
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Display Subtitle for each item", "k-elements" ),
						"value"       => array(
							'Yes' => 'yes',
							'No'  => 'no'
						),
						"description" => ''
					),
					array(
						"param_name"  => "image_size",
						"type"        => "textfield",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( 'Images size', 'k-elements' ),
						"value"       => '',
						"description" => __( 'Leave blank to use default value 480x270. Expressed in pixels. Insert like: 400x400', 'k-elements' )
					),
					array(
						"param_name"  => "category",
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Show from Category", "k-elements" ),
						"value"       => kleo_get_category_list( apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ), 2 ),
						"description" => __( 'It will get also any subcategories', 'k-elements' )
					),
					array(
						"param_name"  => "exclude_categories",
						"type"        => "checkbox",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Exclude categories", "k-elements" ),
						"value"       => $exclude_cats,
						"description" => ''
					),
					$el_class


				)
			)
		);
	}


	/* Search form */
	$kleo_post_types = array();

	if ( function_exists( 'bp_is_active' ) ) {
		$kleo_post_types['Members'] = 'members';
		$kleo_post_types['Groups']  = 'groups';
	}
	$kleo_post_types['Posts'] = 'post';
	$kleo_post_types['Pages'] = 'page';

	$args = array(
		'public'   => true,
		'_builtin' => false
	);

	$types_return = 'objects'; // names or objects, note names is the default
	$post_types   = get_post_types( $args, $types_return );

	$except_post_types = array( 'kleo_clients', 'kleo-testimonials', 'topic', 'reply' );

	foreach ( $post_types as $post_type ) {
		if ( in_array( $post_type->name, $except_post_types ) ) {
			continue;
		}
		$kleo_post_types[ $post_type->labels->name ] = $post_type->name;
	}

	vc_map(
		array(
			'base'        => 'kleo_search_form',
			'name'        => 'Search Form',
			'weight'      => 6,
			'class'       => 'kleo-search',
			'icon'        => 'kleo-search',
			'category'    => __( "Content", 'k-elements' ),
			'description' => __( 'Insert search form', 'k-elements' ),
			'params'      => array(
				array(
					"param_name"  => "form_style",
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Form Style", "k-elements" ),
					"value"       => array(
						'Default'     => 'default',
						'Transparent' => 'transparent'
					),
					"description" => __( 'This affects the look of the form. Default has a border and works for white backgrounds.', 'k-elements' )
				),
				array(
					"param_name"  => "type",
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Form Type", "k-elements" ),
					"value"       => array(
						'Form submit + AJAX results' => 'both',
						'Just Form Submit'           => 'form_submit',
						'Just AJAX results'          => 'ajax'
					),
					"description" => __( 'Here you can disable Form Submit or AJAX results.', 'k-elements' )
				),
				array(
					"param_name"  => "context",
					"type"        => "checkbox",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Search context", "k-elements" ),
					"value"       => $kleo_post_types,
					"std"         => "",
					"description" => __( 'Leave unchecked to search in all content. What content do you want to search for. For example if you select just Members then the form submit will go to members directory. Same applies for Forums and Products.', 'k-elements' )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Placeholder", "k-elements" ),
					"param_name"  => "placeholder",
					"value"       => '',
					"description" => __( "Placeholder to show when the input is empty", "k-elements" )
				),
				$el_class


			)
		)
	);

	/* bbPress Search form */

	vc_map(
		array(
			"name"     => __( "bbPress Header Search" ),
			"base"     => "kleo_bbp_header_search",
			"class"    => "",
			"category" => __( 'bbPress' ),
			"icon"     => "kleo-bpp-icon",
			"params"   => array(
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Forum ID", "k-elements" ),
					"param_name"  => "forum_id",
					"value"       => '',
					"description" => __( "Enter a Forum ID to search just in that forum. Leave blank to search in all forums", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Placeholder", "k-elements" ),
					"param_name"  => "placeholder",
					"value"       => '',
					"description" => __( "Placeholder to show when the input is empty", "k-elements" )
				),
				array(
					"type"        => "colorpicker",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Button text color", "k-elements" ),
					"param_name"  => "text_color",
					"value"       => '#ffffff',
					"description" => ''
				),
				array(
					"type"        => "colorpicker",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Button background color", "k-elements" ),
					"param_name"  => "bg_color",
					"value"       => '#e74c3c',
					"description" => ''
				),
				$el_class
			)
		)
	);


	/* Posts count */
	vc_map(
		array(
			"name"     => __( "Count Posts", "k-elements" ),
			"base"     => "kleo_post_count",
			"class"    => "",
			"category" => __( 'Content', 'k-elements' ),
			"icon"     => "kleo-post-icon",
			"params"   => array(
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Post type", "k-elements" ),
					"param_name"  => "type",
					"value"       => '',
					"description" => __( "Enter a post type to count for. This should be something like: post, page or portfolio ", "k-elements" )
				)
			)
		)
	);


	/* Let it Snow */
	vc_map(
		array(
			"name"     => __( "Let it Snow", "k-elements" ),
			"base"     => "kleo_snow",
			"class"    => "",
			"category" => __( 'Content', 'k-elements' ),
			"icon"     => "kleo-snow-icon",
			"params"   => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Where the snowing effect should happen", "k-elements" ),
					"param_name"  => "scope",
					"value"       => array(
						'Parent Column' => 'column',
						'Whole window'  => 'window'
					),
					"description" => __( "Add beautiful snow effect. Let it snow only in the container column element or activate it for the whole site. ", "k-elements" )
				)
			)
		)
	);

	/* Pricing table */
	if ( isset( $k_elements['kleo_pricing_table'] ) ) {
		vc_map(
			array(
				'base'            => 'kleo_pricing_table',
				'name'            => __( 'Pricing Table', 'k-elements' ),
				'weight'          => 6,
				'class'           => '',
				'icon'            => 'kleo-pricing-table',
				'category'        => "Content",
				'description'     => __( 'Easy to add pricing tables', 'k-elements' ),
				'as_parent'       => array( 'only' => 'kleo_pricing_table_item' ),
				'content_element' => true,
				'js_view'         => 'VcColumnView',
				'params'          => array(
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						'class'       => 'hide hidden',
						"admin_label" => true,
						"heading"     => __( "Columns" ),
						"param_name"  => "columns",
						"value"       => array(
							'2' => '2',
							'3' => '3',
							'4' => '4',
							'6' => '6'
						),
						"description" => __( "Number of pricing table columns.", "k-elements" )
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Style" ),
						"param_name"  => "style",
						"value"       => array(
							'Default'   => '',
							'Condensed' => '2'
						),
						"description" => __( "Choose a different style.", "k-elements" )
					),
					array(
						"type"       => "colorpicker",
						"class"      => "hide hidden",
						"holder"     => 'div',
						'class'      => 'hide hidden',
						"heading"    => __( "Custom Heading background", "k-elements" ),
						"param_name" => "heading_bg",
						"value"      => "",
					),
					array(
						"type"       => "colorpicker",
						"class"      => "hide hidden",
						"holder"     => 'div',
						'class'      => 'hide hidden',
						"heading"    => __( "Custom Price background", "k-elements" ),
						"param_name" => "price_bg",
						"value"      => "",
					),
					array(
						"type"       => "colorpicker",
						"class"      => "hide hidden",
						"holder"     => 'div',
						'class'      => 'hide hidden',
						"heading"    => __( "Custom Text color", "k-elements" ),
						"param_name" => "text_color",
						"value"      => "",
					),
					$el_class
				)
			)
		);
	}

	/* Pricing table item */
	if ( isset( $k_elements['kleo_pricing_table_item'] ) ) {
		vc_map(
			array(
				"name"            => __( "Pricing table item" ),
				"base"            => "kleo_pricing_table_item",
				"class"           => "kleo-pricing-table-item",
				"category"        => __( 'Content' ),
				'as_child'        => array( 'only' => 'kleo_pricing_table' ),
				'content_element' => true,
				"icon"            => "kleo-pricing-table-item",
				"params"          => array(
					array(
						"type"        => "textfield",
						"holder"      => "div",
						"class"       => "hide hidden",
						"admin_label" => true,
						"heading"     => __( "Title", "k-elements" ),
						"param_name"  => "title",
						"value"       => "Column title",
					),
					array(
						"type"        => "textfield",
						"holder"      => "div",
						"admin_label" => true,
						"class"       => "hide hidden",
						"heading"     => __( "Price", "k-elements" ),
						"param_name"  => "price",
						"value"       => "$10",
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"admin_label" => true,
						"heading"     => __( "Popular column?", "k-elements" ),
						"param_name"  => "popular",
						"value"       => array(
							'No'  => '',
							'Yes' => 'yes'
						),
						"description" => __( "Shows this column with a different style", "k-elements" )
					),
					array(
						'param_name'  => 'content',
						'heading'     => 'Description',
						'description' => "Enter description column here",
						'type'        => 'textarea_html',
						'holder'      => "div",
						"class"       => "hide hidden",
						'value'       => ""
					),
					array(
						"type"        => "exploded_textarea",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Features list", "k-elements" ),
						"param_name"  => "features",
						"description" => __( "" )
					),
					array(
						"type"       => "textfield",
						"holder"     => "div",
						"class"      => "hide hidden",
						"heading"    => __( "Button label", "k-elements" ),
						"param_name" => "button_label",
						"value"      => "Select",
					),
					array(
						'type'        => 'vc_link',
						'heading'     => __( 'URL (Link)', 'k-elements' ),
						'param_name'  => 'link',
						'description' => __( 'Add link to button', 'k-elements' )
					),

				)
			)
		);
	}

	/* Kleo Particles */
	vc_map(
		array(
			"name"        => __( "Galaxy Particles" ),
			"base"        => "kleo_particles",
			"class"       => "",
			"category"    => __( 'Content' ),
			"icon"        => "kleo-particles",
			"description" => __( "Add nice background connected particles" ),
			"params"      => array(
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"admin_label" => true,
					"heading"     => __( "Particles number", "k-elements" ),
					"param_name"  => "number",
					"value"       => "80",
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"admin_label" => true,
					"heading"     => __( "Particles size", "k-elements" ),
					"param_name"  => "size",
					"value"       => "4",
				),
				array(
					"type"        => "colorpicker",
					"holder"      => "div",
					"class"       => "hide hidden",
					"admin_label" => true,
					"heading"     => __( "Particles color", "k-elements" ),
					"param_name"  => "color",
					"value"       => "#ffffff",
				)
			)
		)
	);

	/* Kleo Login */
	vc_map(
		array(
			"name"        => __( "Kleo Login" ),
			"base"        => "kleo_login",
			"class"       => "",
			"category"    => __( 'Content' ),
			"icon"        => "kleo-login",
			"description" => __( "Login / Lost Password Forms (Hidden from logged in users.)", "k-elements" ),
			"params"      => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Show", "k-elements" ),
					"param_name"  => "show",
					"value"       => array(
						'Login'         => 'login',
						'Lost Password' => 'lostpass',
					),
					"description" => __( "Initial form to show.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Login Title", "k-elements" ),
					"param_name"  => "login_title",
					"value"       => "Log in with your credentials",
					"description" => __( "Enter the login title.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Lost Password Title", "k-elements" ),
					"param_name"  => "lostpass_title",
					"value"       => "Forgot your details?",
					"description" => __( "Enter the login title.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Login Link", "k-elements" ),
					"param_name"  => "login_link",
					"value"       => "#",
					"description" => __( "Use # or custom url. Using # will allow inline switching between login and lost password boxes.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Lost Password Link", "k-elements" ),
					"param_name"  => "lostpass_link",
					"value"       => "#",
					"description" => __( "Use # or custom url. Using # will allow inline switching between login and lost password boxes.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Register Link", "k-elements" ),
					"param_name"  => "register_link",
					"value"       => "",
					"description" => __( "Leave empty for WordPress or BuddyPress default url. Use 'hide' or custom url. Using 'hide will allow you to hide any registration information.", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"admin_label" => true,
					"heading"     => __( "Style", "k-elements" ),
					"param_name"  => "style",
					"value"       => array(
						'Transparent White' => 'white',
						'Transparent Black' => 'black',
						'Theme Default'     => 'default',
					),
					"description" => __( "Form style. If you don't use this form with a background behind then you should set the form style to 'Theme Default'", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Input Size", "k-elements" ),
					"param_name"  => "input_size",
					"value"       => array(
						'Normal' => 'normal',
						'Large'  => 'large',
					),
					"description" => __( "Form input sizes." )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"admin_label" => true,
					"heading"     => __( "Show for logged in users too", "k-elements" ),
					"param_name"  => "show_for_users",
					"value"       => array(
						'No'  => '',
						'Yes' => 'yes',
					),
					"description" => __( "The form will be displayed for registered users too if enabled. <br> " .
					                     "Note: Use only on testing environment." )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"admin_label" => true,
					"heading"     => __( "Autofocus to the input field", "k-elements" ),
					"param_name"  => "autofocus_login_field",
					"value"       => array(
						'Yes' => '',
						'No'  => 'no',
					),
					"description" => __( "Will focus to the input to type directly and if the form isn't on page view will scroll direcly where the form it's placed. ", "k-elements" )
				),
				$el_class
			)
		)
	);

	/* Kleo Login */
	vc_map(
		array(
			"name"        => __( "Kleo Social Sharing", "k-elements" ),
			"base"        => "kleo_social_share",
			"class"       => "",
			"category"    => __( 'Content' ),
			"icon"        => "kleo-social-share",
			"description" => __( "Adds Kleo Likes and social sharing.)", "k-elements" ),
			"params"      => array(
				$el_class
			)
		)
	);

	/* Kleo Magic container */
	vc_map(
		array(
			"name"            => __( "Kleo Magic Container", "k-elements" ),
			"base"            => "kleo_magic_container",
			"class"           => "",
			"category"        => __( 'Content' ),
			"icon"            => "kleo-magic-container",
			"description"     => __( "Very customizable container element", "k-elements" ),
			'as_parent'       => array( 'except' => '' ),
			'content_element' => true,
			'js_view'         => 'VcColumnView',
			"params"          => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "CSS Position", "k-elements" ),
					"param_name"  => "position",
					"value"       => array(
						'Static'   => '',
						'Relative' => 'relative',
						'Absolute' => 'absolute',
						'Fixed'    => 'fixed',
					),
					"description" => __( "Set the content position.", "k-elements" ),
					//'edit_field_class' => 'vc_col-sm-6 vc_column',
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "CSS Float", "k-elements" ),
					"param_name"  => "float",
					"value"       => array(
						'None'  => '',
						'Left'  => 'left',
						'Right' => 'right'
					),
					"description" => __( "Set the content position.", "k-elements" ),
					//'edit_field_class' => 'vc_col-sm-6 vc_column',
				),
				array(
					"type"             => "textfield",
					"holder"           => "div",
					"class"            => "hide hidden",
					"heading"          => __( "CSS Top", "k-elements" ),
					"param_name"       => "top",
					"value"            => "",
					"description"      => __( "Css Top value. Use px|em|%|pt|cm", "k-elements" ),
					'edit_field_class' => 'vc_col-sm-3 vc_column',
				),
				array(
					"type"             => "textfield",
					"holder"           => "div",
					"class"            => "hide hidden",
					"heading"          => __( "CSS Right" ),
					"param_name"       => "right",
					"value"            => "",
					"description"      => __( "Css RIGHT value. Use px|em|%|pt|cm", "k-elements" ),
					'edit_field_class' => 'vc_col-sm-3 vc_column',
				),
				array(
					"type"             => "textfield",
					"holder"           => "div",
					"class"            => "hide hidden",
					"heading"          => __( "CSS Bottom", "k-elements" ),
					"param_name"       => "bottom",
					"value"            => "",
					"description"      => __( "Css BOTTOM value. Use px|em|%|pt|cm", "k-elements" ),
					'edit_field_class' => 'vc_col-sm-3 vc_column',
				),
				array(
					"type"             => "textfield",
					"holder"           => "div",
					"class"            => "hide hidden",
					"heading"          => __( "CSS Left", "k-elements" ),
					"param_name"       => "left",
					"value"            => "",
					"description"      => __( "Css LEFT value. Use px|em|%|pt|cm" ),
					'edit_field_class' => 'vc_col-sm-3 vc_column',
				),
				array(
					"type"             => "textfield",
					"holder"           => "div",
					"class"            => "hide hidden",
					"heading"          => __( "Min. width", "k-elements" ),
					"param_name"       => "min_width",
					"value"            => "",
					"description"      => __( "Force a minimum width. Use px|em|%|pt|cm", "k-elements" ),
					'edit_field_class' => 'vc_col-sm-6 vc_column',
				),
				array(
					"type"             => "textfield",
					"holder"           => "div",
					"class"            => "hide hidden",
					"heading"          => __( "Min. height", "k-elements" ),
					"param_name"       => "min_height",
					"value"            => "",
					"description"      => __( "Force a minimum height. Use px|em|%|pt|cm", "k-elements" ),
					'edit_field_class' => 'vc_col-sm-6 vc_column',
				),
				array(
					"type"             => "textfield",
					"holder"           => "div",
					"class"            => "hide hidden",
					"heading"          => __( "Fixed width", "k-elements" ),
					"param_name"       => "width",
					"value"            => "",
					"description"      => __( "Force a fixed width. Use px|em|%|pt|cm", "k-elements" ),
					'edit_field_class' => 'vc_col-sm-6 vc_column',
				),
				array(
					"type"             => "textfield",
					"holder"           => "div",
					"class"            => "hide hidden",
					"heading"          => __( "Fixed height" ),
					"param_name"       => "height",
					"value"            => "",
					"description"      => __( "Force a fixed height. Use px|em|%|pt|cm", "k-elements" ),
					'edit_field_class' => 'vc_col-sm-6 vc_column',
				),
				array(
					"type"             => "dropdown",
					"holder"           => "div",
					"class"            => "hide hidden",
					"heading"          => __( "Content vertical position in container", "k-elements" ),
					"param_name"       => "content_position",
					"value"            => array(
						'Default top' => '',
						'Center'      => 'center',
						'Bottom'      => 'bottom'
					),
					"description"      => __( "Set the content position related to the container. Works when you set a custom container height.", "k-elements" ),
					'edit_field_class' => 'vc_col-sm-6 vc_column',
				),
				array(
					"type"             => "checkbox",
					"holder"           => "div",
					"class"            => "hide hidden",
					"heading"          => __( "Center text horizontally", "k-elements" ),
					"param_name"       => "text_center",
					"value"            => array(
						"Yes" => "yes"
					),
					"description"      => __( "Enabling this option will center any text inside the container", "k-elements" ),
					'edit_field_class' => 'vc_col-sm-6 vc_column',
				),
				array(
					"type"        => "checkbox",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Center the container box horizontally", "k-elements" ),
					"param_name"  => "div_center",
					"value"       => array(
						"Yes" => "yes"
					),
					"description" => __( "Works when you set a custom container width.", "k-elements" ),
				),
				array(
					"type"        => "checkbox",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Full height content", "k-elements" ),
					"param_name"  => "full_height",
					"value"       => array(
						"Yes" => "yes"
					),
					"description" => __( "Stretch the content to the container height. Works when you set a custom container height.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "hide hidden",
					"heading"     => __( "Border Radius", "k-elements" ),
					"param_name"  => "border_radius",
					"value"       => "",
					"description" => __( "Set container border radius. Use px|em|%|pt|cm", "k-elements" ),
				),
				array(
					"type"        => "textfield",
					"holder"      => 'div',
					'class'       => 'hide hidden',
					"heading"     => __( "Z-index", "k-elements" ),
					"param_name"  => "z_index",
					"value"       => '',
					"description" => __( "Stack order for overlapping elements. Use integer value.", "k-elements" ),
				),
				array(
					'type'       => 'css_editor',
					'heading'    => __( 'Css', 'k-elements' ),
					'param_name' => 'css',
					'group'      => __( 'Design Options', 'k-elements' ),
				),
				array(
					"type"             => "dropdown",
					"holder"           => 'div',
					'class'            => 'hide hidden',
					"heading"          => __( "Background horizontal position", "k-elements" ),
					"param_name"       => "bg_pos_h",
					"value"            => array(
						"Default" => "",
						"Left"    => "left",
						"Center"  => "center",
						"Right"   => "right"
					),
					"description"      => __( "" ),
					'edit_field_class' => 'vc_col-sm-6 vc_column',
					'group'            => __( 'Design Options', 'k-elements' ),
				),

				array(
					"type"             => "dropdown",
					"holder"           => 'div',
					'class'            => 'hide hidden',
					"heading"          => __( "Background vertical position", "k-elements" ),
					"param_name"       => "bg_pos_v",
					"value"            => array(
						"Default" => "",
						"Top"     => "top",
						"Center"  => "center",
						"Bottom"  => "bottom"
					),
					"description"      => __( "" ),
					'edit_field_class' => 'vc_col-sm-6 vc_column',
					'group'            => __( 'Design Options', 'k-elements' ),
				),
				$visibility,
				$el_class,
			)
		)
	);
	vc_add_params( 'kleo_magic_container', $box_shadow );
	vc_add_param( "kleo_magic_container", $vertical_separator );

	/* Kleo Register */
	if ( isset( $k_elements['kleo_register'] ) ) {
		vc_map(
			array(
				"name"        => __( "Kleo Register" ),
				"base"        => "kleo_register",
				"class"       => "",
				"category"    => __( 'Content' ),
				"icon"        => "kleo-register",
				"description" => __( "Register Form (Hidden from logged in users.)" ),
				"params"      => array(
					array(
						"type"        => "textfield",
						"holder"      => "div",
						"class"       => "hide hidden",
						"admin_label" => true,
						"heading"     => __( "Register Title", "k-elements" ),
						"param_name"  => "register_title",
						"value"       => "Create Account",
						"description" => __( "Enter the register title.", "k-elements" )
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"admin_label" => true,
						"heading"     => __( "Style", "k-elements" ),
						"param_name"  => "style",
						"value"       => array(
							'Transparent White' => 'white',
							'Transparent Black' => 'black',
							'Theme Default'     => 'default',
						),
						"description" => __( "Form style. If you don't use this form with a background behind then you should set the form style to 'Theme Default'", "k-elements" )
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Input Size", "k-elements" ),
						"param_name"  => "input_size",
						"value"       => array(
							'Normal' => 'normal',
							'Large'  => 'large',
						),
						"description" => __( "Form input sizes." )
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"admin_label" => true,
						"heading"     => __( "Show for logged in users too", "k-elements" ),
						"param_name"  => "show_for_users",
						"value"       => array(
							'No'  => '',
							'Yes' => 'yes',
						),
						"description" => __( "The form will be displayed for registered users too if enabled. <br> " .
						                     "Note: Use only on testing environment." )
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"admin_label" => true,
						"heading"     => __( "Allow BuddyPress plugin hook before submit", "k-elements" ),
						"param_name"  => "bp_plugins_hook",
						"value"       => array(
							'Yes' => '',
							'No'  => 'no',
						),
						"description" => __( "Allow plugins to hook into the form using bp_before_registration_submit_buttons", "k-elements" )
					),

					$el_class
				)
			)
		);
	}

	/* Kleo News Focus */
	if ( isset( $k_elements['kleo_news_focus'] ) ) {
		vc_map(
			array(
				"name"        => __( "NEWS Focus", "k-elements" ),
				"base"        => "kleo_news_focus",
				"class"       => "",
				"category"    => __( 'Content' ),
				"icon"        => "kleo-news-focus",
				"description" => __( "News Posts with big left image", "k-elements" ),
				"params"      => array(
					array(
						"type"        => "textfield",
						"holder"      => "div",
						"class"       => "",
						"heading"     => __( "Section name", "k-elements" ),
						"param_name"  => "name",
						"value"       => "",
						"description" => __( "Enter a section name.", "k-elements" )
					),
					array(
						"type"        => "textfield",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Featured posts on left side", "k-elements" ),
						"param_name"  => "featured",
						"value"       => "1",
						"description" => __( "Enter the number of post to highlight and show big on the left column.", "k-elements" )
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Open links in new tab ?", "k-elements" ),
						"param_name"  => "new_tab",
						"description" => __( "Check box if you want to open links in new tab", "k-elements" ),
						"value"       => array(
							'No'  => '0',
							'Yes' => '1',
						),
					),
					array(
						'type'        => 'loop',
						'heading'     => __( 'Build your query', 'k-elements' ),
						'param_name'  => 'posts_query',
						'settings'    => array(
							'size'     => array( 'hidden' => false, 'value' => 10 ),
							'order_by' => array( 'value' => 'date' )
						),
						'description' => __( 'If you refine your posts by category then they will show as tabbed content in the shortcode', 'k-elements' )
					),
					$query_offset,
					$el_class
				)
			)
		);
	}

	/* Kleo News Highlight */
	if ( isset( $k_elements['kleo_news_highlight'] ) ) {
		vc_map(
			array(
				"name"        => __( "NEWS Highlight" ),
				"base"        => "kleo_news_highlight",
				"class"       => "",
				"category"    => __( 'Content' ),
				"icon"        => "kleo-news-highlight",
				"description" => __( "News Posts with first post big image", "k-elements" ),
				"params"      => array(
					array(
						"type"        => "textfield",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Featured posts to show big", "k-elements" ),
						"param_name"  => "featured",
						"value"       => "1",
						"description" => __( "Enter the number of post to highlight and show with bigger thumb.", "k-elements" )
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Open links in new tab ?", "k-elements" ),
						"param_name"  => "new_tab",
						"description" => __( "Check box if you want to open links in new tab", "k-elements" ),
						"value"       => array(
							'No'  => '0',
							'Yes' => '1',
						),
					),
					array(
						'type'        => 'loop',
						'heading'     => __( 'Build your query', 'k-elements' ),
						'param_name'  => 'posts_query',
						'settings'    => array(
							'size'     => array( 'hidden' => false, 'value' => 10 ),
							'order_by' => array( 'value' => 'date' )
						),
						'description' => __( 'If you refine your posts by category then the first category will show as a label on first post image', 'k-elements' )
					),
					$query_offset,
					$el_class
				)
			)
		);
	}

	/* Kleo News Ticker */
	if ( isset( $k_elements['kleo_news_ticker'] ) ) {
		vc_map(
			array(
				"name"        => __( "NEWS Ticker", "k-elements" ),
				"base"        => "kleo_news_ticker",
				"class"       => "",
				"category"    => __( 'Content' ),
				"icon"        => "kleo-news-ticker",
				"description" => __( "Auto Scrolling posts" ),
				"params"      => array(
					array(
						'type'        => 'loop',
						'heading'     => __( 'Build your query', 'k-elements' ),
						'param_name'  => 'posts_query',
						'settings'    => array(
							'size'     => array( 'hidden' => false, 'value' => 10 ),
							'order_by' => array( 'value' => 'date' )
						),
						'description' => __( 'Refine your posts by specific filters', 'k-elements' )
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Open links in new tab ?", "k-elements" ),
						"param_name"  => "new_tab",
						"description" => __( "Check box if you want to open links in new tab", "k-elements" ),
						"value"       => array(
							'No'  => '0',
							'Yes' => '1',
						),
					),
					$query_offset,
					$el_class
				)

			)
		);
	}

	/* Kleo News Ticker */
	if ( isset( $k_elements['kleo_news_puzzle'] ) ) {
		vc_map(
			array(
				"name"        => __( "News Puzzle" ),
				"base"        => "kleo_news_puzzle",
				"class"       => "",
				"category"    => __( 'Content' ),
				"icon"        => "kleo-news-puzzle",
				"description" => __( "Posts in puzzle design" ),
				"params"      => array(
					array(
						'type'        => 'loop',
						'heading'     => __( 'Build your query', 'k-elements' ),
						'param_name'  => 'posts_query',
						'settings'    => array(
							'size'     => array( 'hidden' => false, 'value' => 10 ),
							'order_by' => array( 'value' => 'date' )
						),
						'description' => __( 'Refine your posts by specific filters. This will get only image and gallery post formats', 'k-elements' )
					),
					$query_offset,
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Posts per Row", "k-elements" ),
						"param_name"  => "per_row",
						"value"       => array(
							'2' => '2',
							'3' => '3',
							'4' => '4',
						),
						'std'         => '3',
						"description" => __( "How many posts to show on one line.", "k-elements" )
					),
					array(
						"type"        => "dropdown",
						"holder"      => "div",
						"class"       => "hide hidden",
						"heading"     => __( "Open links in new tab ?", "k-elements" ),
						"param_name"  => "new_tab",
						"description" => __( "Check box if you want to open links in new tab", "k-elements" ),
						"value"       => array(
							'No'  => '0',
							'Yes' => '1',
						),
					),
					$el_class
				)

			)
		);
	}

	/* Buddypress members count */
	vc_map(
		array(
			"name"                    => __( "Members Count", "k-elements" ),
			"base"                    => "kleo_total_members",
			"class"                   => "",
			"category"                => __( 'BuddyPress' ),
			"icon"                    => "kleo-bp-icon",
			"description"             => __( "Show the total member count.", "k-elements" ),
			"show_settings_on_create" => false,
			"params"                  => array()
		)
	);

	/* Buddypress members statistics */
	vc_map(
		array(
			"name"        => __( "Members Statistics", "k-elements" ),
			"base"        => "kleo_bp_member_stats",
			"class"       => "",
			"category"    => __( 'BuddyPress', 'k-elements' ),
			"icon"        => "kleo-bp-icon",
			"params"      => array(
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Field Name", "k-elements" ),
					"param_name"  => "field",
					"value"       => "",
					"description" => __( "Profile field name.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Field Value" ),
					"param_name"  => "value",
					"value"       => "",
					"description" => __( "Profile field value.", "k-elements" )
				),
				array(
					"type"        => "checkbox",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Online?", "k-elements" ),
					"param_name"  => "online",
					"value"       => array(
						"" => "false"
					),
					"description" => __( "Only include online members.", "k-elements" )
				)
			),
			"description" => __( "Show the member count by profile field value.", "k-elements" )
		)
	);


	// Buddypress Groups Carousel

	vc_map(
		array(
			"name"     => __( "Groups Carousel" ),
			"base"     => "kleo_bp_groups_carousel",
			"class"    => "",
			"category" => __( 'BuddyPress' ),
			"icon"     => "bp-icon",
			"params"   => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Type", "k-elements" ),
					"param_name"  => "type",
					"value"       => array(
						'Active'            => 'active',
						'Newest'            => 'newest',
						'Popular'           => 'popular',
						'Alphabetical'      => 'alphabetical',
						'Most Forum Topics' => 'most-forum-topics',
						'Most Forum Posts'  => 'most-forum-posts',
						'Random'            => 'random'
					),
					"description" => __( "The type of groups to display.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Number of groups", "k-elements" ),
					"param_name"  => "number",
					"value"       => 12,
					"description" => __( "How many groups to get.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Minimum Items", "k-elements" ),
					"param_name"  => "min_items",
					"value"       => 1,
					"description" => __( "Minimum number of items to show on the screen", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Maximum Items", "k-elements" ),
					"param_name"  => "max_items",
					"value"       => 6,
					"description" => __( "Maximum number of items to show on the screen", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Image Type", "k-elements" ),
					"param_name"  => "image_size",
					"value"       => array(
						'Full'      => 'full',
						'Thumbnail' => 'thumb'
					),
					"description" => __( "The size to get from buddypress", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Auto play", "k-elements" ),
					"param_name"  => "autoplay",
					"value"       => array(
						'No'  => '',
						'Yes' => 'yes'
					),
					"description" => __( "If the carousel should play automatically", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Avatar type", "k-elements" ),
					"param_name"  => "rounded",
					"value"       => array(
						'Rounded' => 'rounded',
						'Square'  => 'square'
					),
					"description" => __( "Rounded or square avatar", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Image Width", "k-elements" ),
					"param_name"  => "item_width",
					"value"       => 150,
					"description" => __( "The size of the group image", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Class", "k-elements" ),
					"param_name"  => "class",
					"value"       => '',
					"description" => __( "A class to add to the element for CSS referrences.", "k-elements" )
				),

			)
		)
	);


	// Buddypress Groups Masonry

	vc_map(
		array(
			"name"     => __( "Groups Masonry" ),
			"base"     => "kleo_bp_groups_masonry",
			"class"    => "",
			"category" => __( 'BuddyPress' ),
			"icon"     => "bp-icon",
			"params"   => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Type", "k-elements" ),
					"param_name"  => "type",
					"value"       => array(
						'Active'            => 'active',
						'Newest'            => 'newest',
						'Popular'           => 'popular',
						'Alphabetical'      => 'alphabetical',
						'Most Forum Topics' => 'most-forum-topics',
						'Most Forum Posts'  => 'most-forum-posts',
						'Random'            => 'random'
					),
					"description" => __( "The type of groups to display.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Number of groups", "k-elements" ),
					"param_name"  => "number",
					"value"       => 12,
					"description" => __( "How many groups to get.", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Avatar type", "k-elements" ),
					"param_name"  => "rounded",
					"value"       => array(
						'Rounded' => 'rounded',
						'Square'  => 'square'
					),
					"description" => __( "Rounded or square avatar", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Avatar size", "k-elements" ),
					"param_name"  => "avatarsize",
					"value"       => array(
						'Small' => '',
						'Large' => 'large'
					),
					"description" => __( "Small or large avatar", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Width & Height", "k-elements" ),
					"param_name"  => "width_height",
					"value"       => '',
					'dependency'  => array(
						'element' => 'avatarsize',
						'value'   => 'large',
					),
					"description" => __( "Example: 200x200", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Class" ),
					"param_name"  => "class",
					"value"       => '',
					"description" => __( "A class to add to the element for CSS referrences.", "k-elements" )
				),

			)
		)
	);


	/* Groups grid */

	vc_map(
		array(
			"name"     => __( "Groups Grid", "k-elements" ),
			"base"     => "kleo_bp_groups_grid",
			"class"    => "",
			"category" => __( 'BuddyPress' ),
			"icon"     => "bp-icon",
			"params"   => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Type", "k-elements" ),
					"param_name"  => "type",
					"value"       => array(
						'Active'            => 'active',
						'Newest'            => 'newest',
						'Popular'           => 'popular',
						'Alphabetical'      => 'alphabetical',
						'Most Forum Topics' => 'most-forum-topics',
						'Most Forum Posts'  => 'most-forum-posts',
						'Random'            => 'random'
					),
					"description" => __( "The type of groups to display." )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Maximum members", "k-elements" ),
					"param_name"  => "number",
					"value"       => 12,
					"description" => __( "How many groups you want to display.", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Groups per line", "k-elements" ),
					"param_name"  => "perline",
					"value"       => array(
						'1'  => 'one',
						'2'  => 'two',
						'3'  => 'three',
						'4'  => 'four',
						'5'  => 'five',
						'6'  => 'six',
						'7'  => 'seven',
						'8'  => 'eight',
						'9'  => 'nine',
						'10' => 'ten',
						'11' => 'eleven',
						'12' => 'twelve'
					),
					"description" => __( "How many groups to show per line", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Avatar type", "k-elements" ),
					"param_name"  => "rounded",
					"value"       => array(
						'Square'  => 'square',
						'Rounded' => 'rounded'
					),
					"description" => __( "Rounded or square avatar", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Avatar size", "k-elements" ),
					"param_name"  => "avatarsize",
					"value"       => array(
						'Small' => '',
						'Large' => 'large'
					),
					"description" => __( "Small or large avatar", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Width & Height", "k-elements" ),
					"param_name"  => "width_height",
					"value"       => '',
					'dependency'  => array(
						'element' => 'avatarsize',
						'value'   => 'large',
					),
					"description" => __( "Example: 200x200", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Animatione", "k-elements" ),
					"param_name"  => "animation",
					"value"       => array(
						'None'   => '',
						'Fade'   => 'fade',
						'Appear' => 'appear'
					),
					"description" => __( "Elements will appear animated", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Class", "k-elements" ),
					"param_name"  => "class",
					"value"       => '',
					"description" => __( "A class to add to the element for CSS referrences.", "k-elements" )
				),
			)
		)
	);

	//Activity Stream
	vc_map(
		array(
			"name"     => __( "Activity Stream", "k-elements" ),
			"base"     => "kleo_bp_activity_stream",
			"class"    => "",
			"category" => __( 'BuddyPress' ),
			"icon"     => "kleo-bp-icon",
			"params"   => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Display", "k-elements" ),
					"param_name"  => "show",
					"value"       => array(
						'All'     => false,
						'Blogs'   => 'blogs',
						'Groups'  => 'groups',
						'Friends' => 'friends',
						'Profile' => 'profile',
						'Status'  => 'status'
					),
					"description" => __( "The type of activity to show. It adds the 'object' parameter as in https://codex.buddypress.org/developer/loops-reference/the-activity-stream-loop/", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Filter action", "k-elements" ),
					"param_name"  => "filter_action",
					"value"       => '',
					"description" => __( "Example: activity_update<br> See action parameter from the filters section from https://codex.buddypress.org/developer/loops-reference/the-activity-stream-loop/", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Number", "k-elements" ),
					"param_name"  => "number",
					"value"       => '6',
					"description" => __( "How many activity streams to show", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Show post update form", "k-elements" ),
					"param_name"  => "post_form",
					"value"       => array(
						'No'  => 'no',
						'Yes' => 'yes'
					),
					"description" => __( "Shows the form to post a new update", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Bottom button", "k-elements" ),
					"param_name"  => "show_button",
					"value"       => array(
						'Yes' => 'yes',
						'No'  => 'no'
					),
					"description" => __( "Show a button with link to the activity page", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Activity Button Label", "k-elements" ),
					"param_name"  => "button_label",
					"value"       => 'View All Activity',
					"dependency"  => array(
						"element" => "show_button",
						"value"   => "yes"
					),
					"description" => __( "Button text" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Activity Button Link", "k-elements" ),
					"param_name"  => "button_link",
					"value"       => '/activity',
					"dependency"  => array(
						"element" => "show_button",
						"value"   => "yes"
					),
					"description" => __( "Put here the link to your activity page", "k-elements" )
				)
			)
		)
	);

	//Activity Page
	vc_map(
		array(
			"name"                    => __( "Activity Page", "k-elements" ),
			"base"                    => "kleo_bp_activity_page",
			"class"                   => "",
			"category"                => __( 'BuddyPress' ),
			"icon"                    => "kleo-bp-icon",
			"show_settings_on_create" => false
		)
	);


}

add_action( 'vc_before_init', 'kleo_vc_manipulate_shortcodes' );


function kleo_vc_manipulate_shortcodes_after() {

	if ( version_compare( WPB_VC_VERSION, '4.10' ) >= 0 ) {

		//Get current values stored in the width param in "Column" element
		$param          = WPBMap::getParam( 'vc_row', 'video_bg' );
		$param['group'] = __( 'Text & Background', 'k-elements' );
		//Finally "mutate" param with new values
		vc_update_shortcode_param( 'vc_row', $param );

		//Get current values stored in the width param in "Column" element
		$param          = WPBMap::getParam( 'vc_row', 'video_bg_url' );
		$param['group'] = __( 'Text & Background', 'k-elements' );
		//Finally "mutate" param with new values
		vc_update_shortcode_param( 'vc_row', $param );

		//Get current values stored in the width param in "Column" element
		$param          = WPBMap::getParam( 'vc_row', 'video_bg_parallax' );
		$param['group'] = __( 'Text & Background', 'k-elements' );
		//Finally "mutate" param with new values
		vc_update_shortcode_param( 'vc_row', $param );

		//Get current values stored in the width param in "Column" element
		$param          = WPBMap::getParam( 'vc_row', 'parallax_speed_video' );
		$param['group'] = __( 'Text & Background', 'k-elements' );
		//Finally "mutate" param with new values
		vc_update_shortcode_param( 'vc_row', $param );
	}


	vc_add_shortcode_param( 'sq_hidden', 'sq_vc_hidden_param' );
	function sq_vc_hidden_param( $settings, $value ) {
		return '<div class="sq-param-block" style="display: none;">'
		       . '<input name="' . esc_attr( $settings['param_name'] ) . '" class="wpb_vc_param_value' .
		       esc_attr( $settings['param_name'] ) . ' ' .
		       esc_attr( $settings['type'] ) . '_field" type="hidden" value="' . esc_attr( $value ) . '" />'
		       . '</div>';
	}
}

add_action( 'vc_after_init', 'kleo_vc_manipulate_shortcodes_after' );


/* Load theme Fontello icons */
add_filter( 'vc_iconpicker-type-fontello', 'kleo_vc_iconpicker_fontello' );

function kleo_vc_iconpicker_fontello( $icons ) {

	$fontello_icons = kleo_icons_array( '' );

	$arranged_icons = array();

	if ( $fontello_icons ) {
		foreach ( $fontello_icons as $k => $icon ) {
			$arranged_icons[][ $k ] = $icon;
		}
	}

	$f_icons = array( $arranged_icons );

	return array_merge( $icons, $f_icons );
}

add_action( 'vc_backend_editor_enqueue_js_css', 'kleo_enqueue_admin_font' );

function kleo_enqueue_admin_font() {
	wp_enqueue_style( 'kleo-fonts' );
}


class WPBakeryShortCode_Kleo_Grid extends WPBakeryShortCodesContainer {
}

class WPBakeryShortCode_Kleo_Visibility extends WPBakeryShortCodesContainer {
}

class WPBakeryShortCode_Kleo_Restrict extends WPBakeryShortCodesContainer {
}

class WPBakeryShortCode_Kleo_List extends WPBakeryShortCodesContainer {
}

class WPBakeryShortCode_Kleo_Feature_item extends WPBakeryShortCode {
}

class WPBakeryShortCode_Kleo_List_item extends WPBakeryShortCode {
}

class WPBakeryShortCode_Kleo_Pricing_Table extends WPBakeryShortCodesContainer {
}

class WPBakeryShortCode_Kleo_Magic_Container extends WPBakeryShortCodesContainer {
}


/* Default templates */
add_action( 'vc_load_default_templates_action', 'kleo_custom_template_for_vc' );
function kleo_custom_template_for_vc() {

	$data            = array();
	$data['name']    = 'Portfolio With Grid Images V1';
	$data['content'] = <<<CONTENT
[vc_row inner_container="no" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="30px" min_height="0" border="none" css_animation="right-to-left" type="image" bg_image="2755" text_align="center"][vc_column width="1/1"][vc_row_inner][vc_column_inner width="1/1"][vc_column_text css_animation="right-to-left"]
<h1>Portfolio With Grid Images V1</h1>
[/vc_column_text][vc_column_text lead="yes" css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_column_inner][/vc_row_inner][kleo_gap size="20px"][vc_gallery type="grid" images="723,721,722,731,732,733,734,744,778,749,743,742" onclick="link_image" custom_links_target="_self" img_size="500x400" grid_number="6"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40px" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left"][vc_column width="1/2"][vc_column_text css_animation="right-to-left"]
<h3>Project Description</h3>
[/vc_column_text][kleo_gap size="10px"][vc_column_text css_animation="right-to-left"]
<div class="wpb_text_column wpb_content_element ">
<div class="wpb_wrapper">

Maecenas nec ultrices massa. Quisque orci diam, malesuada id augue nec, faucibus interdum dolor. Curabitur sagittis, felis porttitor placerat rhoncus, mauris diam sollicitudin nisl, sed luctus nulla sem non velit. Fusce a libero ullamcorper, volutpat orci ut, suscipit erat. Morbi tempor tortor vel urna lobortis.

Hendrerit faucibus massa consequat. Vivamus feugiat sapien massa, non luctus purus scelerisque et. Donec sodales pellentesque diam, et adipiscing erat imperdiet ac. Integer a lacinia velit. Pellentesque eu adipiscing arcu, a eleifend nulla. Vivamus tempus sem erat, eget lobortis odio interdum at. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Interdum et malesuada fames ac ante ipsum primis in faucibus.

Phasellus et feugiat risus. Ut a egestas libero. Morbi dictum quis felis vel congue. Sed eu arcu auctor, volutpat justo et, egestas libero. Phasellus sagittis sem in iaculis faucibus. Aenean vel lacus purus.

</div>
</div>
[/vc_column_text][/vc_column][vc_column width="1/2"][kleo_gap size="10px"][vc_accordion icons_position="to-left"][vc_accordion_tab title="Project Details" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Client" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Request a Quote" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][contact-form-7 id="29"][/vc_accordion_tab][/vc_accordion][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][kleo_button title="Launch Project" target="_self" style="highlight" tooltip_position="left" tooltip_action="hover"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="alternate" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="60" min_height="0" border="none" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h3>Related Projects</h3>
[/vc_column_text][kleo_gap size="20px"][kleo_portfolio display_type="default" title_style="normal" pagination="no" filter="no" excerpt="yes" category="All" columns="4" item_count="4"][/vc_column][/vc_row]
CONTENT;

	$data2            = array();
	$data2['name']    = 'Portfolio With Grid Images V2';
	$data2['content'] = <<<CONTENT
[vc_row inner_container="no" text_align="center" section_type="main" bg_position="center" bg_position_horizontal="center" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="50" min_height="0" border="none" css_animation="right-to-left" front_status="draft"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h1>Portfolio With Grid Images V2</h1>
[/vc_column_text][/vc_column][/vc_row][vc_row inner_container="no" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="0" min_height="0" border="none" css_animation="right-to-left" text_align="center" padding_bottom="40px" bg_color="#000000" column_gap="no"][vc_column width="1/1"][vc_gallery type="grid" onclick="link_image" custom_links_target="_self" grid_number="4" img_size="full" images="3202,3174,3175,3172,3170,3169,3168,3167,3166,3171"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40px" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left"][vc_column width="1/2"][vc_column_text css_animation="right-to-left"]
<h3>Project Description</h3>
[/vc_column_text][kleo_gap size="10px"][vc_column_text css_animation="right-to-left"]
<div class="wpb_text_column wpb_content_element ">
<div class="wpb_wrapper">

Maecenas nec ultrices massa. Quisque orci diam, malesuada id augue nec, faucibus interdum dolor. Curabitur sagittis, felis porttitor placerat rhoncus, mauris diam sollicitudin nisl, sed luctus nulla sem non velit. Fusce a libero ullamcorper, volutpat orci ut, suscipit erat. Morbi tempor tortor vel urna lobortis.

Hendrerit faucibus massa consequat. Vivamus feugiat sapien massa, non luctus purus scelerisque et. Donec sodales pellentesque diam, et adipiscing erat imperdiet ac. Integer a lacinia velit. Pellentesque eu adipiscing arcu, a eleifend nulla. Vivamus tempus sem erat, eget lobortis odio interdum at. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Interdum et malesuada fames ac ante ipsum primis in faucibus.

Phasellus et feugiat risus. Ut a egestas libero. Morbi dictum quis felis vel congue. Sed eu arcu auctor, volutpat justo et, egestas libero. Phasellus sagittis sem in iaculis faucibus. Aenean vel lacus purus.

</div>
</div>
[/vc_column_text][/vc_column][vc_column width="1/2"][kleo_gap size="10px"][vc_accordion icons_position="to-left"][vc_accordion_tab title="Project Details" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Client" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Request a Quote" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][contact-form-7 id="29"][/vc_accordion_tab][/vc_accordion][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][kleo_button title="Launch Project" target="_self" style="highlight" tooltip_position="left" tooltip_action="hover"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="alternate" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="60" min_height="0" border="none" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h3>Related Projects</h3>
[/vc_column_text][kleo_gap size="20px"][kleo_portfolio display_type="default" title_style="normal" pagination="no" filter="no" excerpt="yes" category="All" columns="4" item_count="4"][/vc_column][/vc_row]
CONTENT;

	$data3            = array();
	$data3['name']    = 'Portfolio With Carousel Images';
	$data3['content'] = <<<CONTENT
[vc_row inner_container="no" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="30px" padding_bottom="40" min_height="0" border="none" css_animation="right-to-left" type="image" bg_image="2755" text_align="center"][vc_column width="1/1"][vc_row_inner][vc_column_inner width="1/1"][vc_column_text css_animation="right-to-left"]
<h1>Portfolio With Carousel Images</h1>
[/vc_column_text][vc_column_text lead="yes" css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_column_inner][/vc_row_inner][kleo_gap size="20px"][vc_images_carousel images="734,740,733,737,741,728,722,721,723" onclick="link_image" custom_links_target="_self" speed="3000" scroll_fx="scroll" animation="animate-when-almost-visible" css_animation="fade" min_items="1" max_items="3" img_size="400px" autoplay="yes"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40px" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left"][vc_column width="1/2"][vc_column_text css_animation="right-to-left"]
<h3>Project Description</h3>
[/vc_column_text][kleo_gap size="10px"][vc_column_text css_animation="right-to-left"]
<div class="wpb_text_column wpb_content_element ">
<div class="wpb_wrapper">

Maecenas nec ultrices massa. Quisque orci diam, malesuada id augue nec, faucibus interdum dolor. Curabitur sagittis, felis porttitor placerat rhoncus, mauris diam sollicitudin nisl, sed luctus nulla sem non velit. Fusce a libero ullamcorper, volutpat orci ut, suscipit erat. Morbi tempor tortor vel urna lobortis.

Hendrerit faucibus massa consequat. Vivamus feugiat sapien massa, non luctus purus scelerisque et. Donec sodales pellentesque diam, et adipiscing erat imperdiet ac. Integer a lacinia velit. Pellentesque eu adipiscing arcu, a eleifend nulla. Vivamus tempus sem erat, eget lobortis odio interdum at. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Interdum et malesuada fames ac ante ipsum primis in faucibus.

Phasellus et feugiat risus. Ut a egestas libero. Morbi dictum quis felis vel congue. Sed eu arcu auctor, volutpat justo et, egestas libero. Phasellus sagittis sem in iaculis faucibus. Aenean vel lacus purus.

</div>
</div>
[/vc_column_text][/vc_column][vc_column width="1/2"][kleo_gap size="10px"][vc_accordion icons_position="to-left"][vc_accordion_tab title="Project Details" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Client" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Request a Quote" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][contact-form-7 id="29"][/vc_accordion_tab][/vc_accordion][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][kleo_button title="Launch Project" target="_self" style="highlight" tooltip_position="left" tooltip_action="hover"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="alternate" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="60" min_height="0" border="none" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h3>Related Projects</h3>
[/vc_column_text][kleo_gap size="20px"][kleo_portfolio display_type="default" title_style="normal" columns="4" item_count="4" pagination="no" filter="no" excerpt="yes" category="All"][/vc_column][/vc_row]
CONTENT;

	$data4            = array();
	$data4['name']    = 'Single Image Lightbox';
	$data4['content'] = <<<CONTENT
[vc_row inner_container="yes" text_align="center" section_type="main" bg_position="top" bg_position_horizontal="center" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="30" padding_bottom="20" min_height="0" border="bottom" css_animation="right-to-left" type="image" bg_image="2926"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h1>Single Image Lightbox</h1>
[/vc_column_text][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="30px" min_height="0" border="none" css_animation="right-to-left" text_align="center" padding_bottom="40"][vc_column width="1/1"][kleo_gap size="20px"][vc_images_carousel images="2906" onclick="link_image" custom_links_target="_self" speed="5000" scroll_fx="scroll" css_animation="appear" min_items="1" max_items="1" img_size="full"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40px" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left"][vc_column width="1/2"][vc_column_text css_animation="right-to-left"]
<h3>Project Description</h3>
[/vc_column_text][kleo_gap size="10px"][vc_column_text css_animation="right-to-left"]
<div class="wpb_text_column wpb_content_element ">
<div class="wpb_wrapper">

Maecenas nec ultrices massa. Quisque orci diam, malesuada id augue nec, faucibus interdum dolor. Curabitur sagittis, felis porttitor placerat rhoncus, mauris diam sollicitudin nisl, sed luctus nulla sem non velit. Fusce a libero ullamcorper, volutpat orci ut, suscipit erat. Morbi tempor tortor vel urna lobortis.

Hendrerit faucibus massa consequat. Vivamus feugiat sapien massa, non luctus purus scelerisque et. Donec sodales pellentesque diam, et adipiscing erat imperdiet ac. Integer a lacinia velit. Pellentesque eu adipiscing arcu, a eleifend nulla. Vivamus tempus sem erat, eget lobortis odio interdum at. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Interdum et malesuada fames ac ante ipsum primis in faucibus.

Phasellus et feugiat risus. Ut a egestas libero. Morbi dictum quis felis vel congue. Sed eu arcu auctor, volutpat justo et, egestas libero. Phasellus sagittis sem in iaculis faucibus. Aenean vel lacus purus.

</div>
</div>
[/vc_column_text][/vc_column][vc_column width="1/2"][kleo_gap size="10px"][vc_accordion icons_position="to-left"][vc_accordion_tab title="Project Details" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Client" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Request a Quote" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][contact-form-7 id="29"][/vc_accordion_tab][/vc_accordion][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][kleo_button title="Launch Project" target="_self" style="highlight" tooltip_position="left" tooltip_action="hover"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="alternate" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="60" min_height="0" border="none" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h3>Related Projects</h3>
[/vc_column_text][kleo_gap size="20px"][kleo_portfolio display_type="default" title_style="normal" pagination="no" filter="no" excerpt="yes" category="All" columns="4" item_count="4"][/vc_column][/vc_row]
CONTENT;

	$data5            = array();
	$data5['name']    = 'Video Background Header';
	$data5['content'] = <<<CONTENT
[vc_row inner_container="yes" section_type="main" type="video" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" min_height="0" border="none" css_animation="right-to-left" bg_video_src_webm="http://seventhqueen.com/themes/kleo/wp-content/uploads/2014/09/video_street.webm" padding_top="0" padding_bottom="0" text_color="#ffffff" bg_video_src_mp4="http://seventhqueen.com/themes/kleo/wp-content/uploads/2014/09/video_street.mp4" bg_image="3214" bg_video_src_ogv="http://seventhqueen.com/themes/kleo/wp-content/uploads/2014/09/video_street.ogg" bg_video_cover="3203"][vc_column width="1/1"][kleo_gap size="200px"][kleo_gap size="30px"][vc_column_text css_animation="right-to-left"]
<h1>Video Background Header</h1>
[/vc_column_text][vc_column_text lead="yes" css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][kleo_gap size="30px"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="60" min_height="0" border="bottom" css_animation="right-to-left"][vc_column width="2/3"][vc_single_image image="2914" border_color="grey" img_link_target="_self" animation="animate-when-almost-visible" css_animation="el-fade" img_size="large"][kleo_gap size="10px"][/vc_column][vc_column width="1/3"][vc_column_text css_animation="right-to-left"]
<h3>Project Description</h3>
[/vc_column_text][kleo_gap size="10px"][vc_column_text css_animation="right-to-left"]
<div class="wpb_text_column wpb_content_element ">
<div class="wpb_wrapper">

Maecenas nec ultrices massa. Quisque orci diam, malesuada id augue nec, faucibus interdum dolor. Curabitur sagittis, felis porttitor placerat rhoncus, mauris diam sollicitudin nisl, sed luctus nulla sem non velit. Fusce a libero ullamcorper, volutpat orci ut, suscipit erat. Morbi tempor tortor vel urna lobortis.

Hendrerit faucibus massa consequat. Vivamus feugiat sapien massa, non luctus purus scelerisque et. Donec sodales pellentesque diam, et adipiscing erat imperdiet ac. Integer a lacinia velit. Pellentesque eu adipiscing arcu, a eleifend nulla. Vivamus tempus sem erat, eget lobortis odio interdum at. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Interdum et malesuada fames ac ante ipsum primis in faucibus.

Phasellus et feugiat risus. Ut a egestas libero. Morbi dictum quis felis vel congue. Sed eu arcu auctor, volutpat justo et, egestas libero. Phasellus sagittis sem in iaculis faucibus. Aenean vel lacus purus.

</div>
</div>
[/vc_column_text][vc_accordion icons_position="to-left"][vc_accordion_tab title="Project Details" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Client" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Request a Quote" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][contact-form-7 id="29"][/vc_accordion_tab][/vc_accordion][kleo_gap size="30px"][kleo_button title="Launch Project" target="_self" style="highlight" tooltip_position="left" tooltip_action="hover"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="60" min_height="0" border="none" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h3>Related Projects</h3>
[/vc_column_text][kleo_gap size="20px"][kleo_portfolio display_type="default" title_style="normal" columns="4" item_count="4" pagination="no" filter="no" excerpt="yes" category="All"][/vc_column][/vc_row]
CONTENT;

	$data6            = array();
	$data6['name']    = 'Embed Vimeo Full Width';
	$data6['content'] = <<<CONTENT
[vc_row inner_container="no" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="30px" min_height="0" border="none" css_animation="right-to-left" type="image" bg_image="2755" text_align="center"][vc_column width="1/1"][vc_row_inner][vc_column_inner width="1/1"][vc_column_text css_animation="right-to-left"]
<h1>Embed Vimeo Full Width</h1>
[/vc_column_text][vc_column_text lead="yes" css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_column_inner][/vc_row_inner][kleo_gap size="20px"][vc_video link="https://vimeo.com/16202331"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40px" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left"][vc_column width="1/2"][vc_column_text css_animation="right-to-left"]
<h3>Project Description</h3>
[/vc_column_text][kleo_gap size="10px"][vc_column_text css_animation="right-to-left"]
<div class="wpb_text_column wpb_content_element ">
<div class="wpb_wrapper">

Maecenas nec ultrices massa. Quisque orci diam, malesuada id augue nec, faucibus interdum dolor. Curabitur sagittis, felis porttitor placerat rhoncus, mauris diam sollicitudin nisl, sed luctus nulla sem non velit. Fusce a libero ullamcorper, volutpat orci ut, suscipit erat. Morbi tempor tortor vel urna lobortis.

Hendrerit faucibus massa consequat. Vivamus feugiat sapien massa, non luctus purus scelerisque et. Donec sodales pellentesque diam, et adipiscing erat imperdiet ac. Integer a lacinia velit. Pellentesque eu adipiscing arcu, a eleifend nulla. Vivamus tempus sem erat, eget lobortis odio interdum at. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Interdum et malesuada fames ac ante ipsum primis in faucibus.

Phasellus et feugiat risus. Ut a egestas libero. Morbi dictum quis felis vel congue. Sed eu arcu auctor, volutpat justo et, egestas libero. Phasellus sagittis sem in iaculis faucibus. Aenean vel lacus purus.

</div>
</div>
[/vc_column_text][/vc_column][vc_column width="1/2"][kleo_gap size="10px"][vc_accordion icons_position="to-left"][vc_accordion_tab title="Project Details" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Client" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Request a Quote" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][contact-form-7 id="29"][/vc_accordion_tab][/vc_accordion][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][kleo_button title="Launch Project" target="_self" style="highlight" tooltip_position="left" tooltip_action="hover"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="alternate" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="60" min_height="0" border="none" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h3>Related Projects</h3>
[/vc_column_text][kleo_gap size="20px"][kleo_portfolio display_type="default" title_style="normal" pagination="no" filter="no" excerpt="yes" category="All" columns="4" item_count="4"][/vc_column][/vc_row]
CONTENT;

	$data7            = array();
	$data7['name']    = 'Portfolio With Slider';
	$data7['content'] = <<<CONTENT
[vc_row inner_container="yes" text_align="center" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="30" padding_bottom="0" min_height="0" border="none" css_animation="right-to-left"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h1>Portfolio With Slider</h1>
[/vc_column_text][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="0" padding_bottom="40" min_height="0" border="none" css_animation="right-to-left"][vc_column width="1/1"][kleo_gap size="20px"][vc_images_carousel images="734,733,737,741" onclick="link_image" custom_links_target="_self" speed="5000" scroll_fx="scroll" animation="animate-when-almost-visible" css_animation="fade" min_items="1" max_items="1" img_size="large"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="0" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left"][vc_column width="1/2"][vc_column_text css_animation="right-to-left"]
<h3>Project Description</h3>
[/vc_column_text][kleo_gap size="10px"][vc_column_text css_animation="right-to-left"]
<div class="wpb_text_column wpb_content_element ">
<div class="wpb_wrapper">

Maecenas nec ultrices massa. Quisque orci diam, malesuada id augue nec, faucibus interdum dolor. Curabitur sagittis, felis porttitor placerat rhoncus, mauris diam sollicitudin nisl, sed luctus nulla sem non velit. Fusce a libero ullamcorper, volutpat orci ut, suscipit erat. Morbi tempor tortor vel urna lobortis.

Hendrerit faucibus massa consequat. Vivamus feugiat sapien massa, non luctus purus scelerisque et. Donec sodales pellentesque diam, et adipiscing erat imperdiet ac. Integer a lacinia velit. Pellentesque eu adipiscing arcu, a eleifend nulla. Vivamus tempus sem erat, eget lobortis odio interdum at. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Interdum et malesuada fames ac ante ipsum primis in faucibus.

Phasellus et feugiat risus. Ut a egestas libero. Morbi dictum quis felis vel congue. Sed eu arcu auctor, volutpat justo et, egestas libero. Phasellus sagittis sem in iaculis faucibus. Aenean vel lacus purus.

</div>
</div>
[/vc_column_text][/vc_column][vc_column width="1/2"][kleo_gap size="10px"][vc_accordion icons_position="to-left"][vc_accordion_tab title="Project Details" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Client" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Request a Quote" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][contact-form-7 id="29"][/vc_accordion_tab][/vc_accordion][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][kleo_button title="Launch Project" target="_self" style="highlight" tooltip_position="left" tooltip_action="hover"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="60" min_height="0" border="none" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h3>Related Projects</h3>
[/vc_column_text][kleo_gap size="20px"][kleo_portfolio display_type="default" title_style="normal" columns="4" item_count="4" pagination="no" filter="no" excerpt="yes" category="All"][/vc_column][/vc_row]
CONTENT;

	$data8            = array();
	$data8['name']    = 'Gallery with thumbs';
	$data8['content'] = <<<CONTENT
[vc_row inner_container="yes" text_align="center" section_type="main" bg_position="center" bg_position_horizontal="center" bg_repeat="no-repeat" bg_cover="true" bg_attachment="true" parallax_speed="0.05" padding_top="30" padding_bottom="20" min_height="0" border="none" css_animation="right-to-left" type="image" bg_image="3166" front_status="draft"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h1>Gallery with thumbs</h1>
[/vc_column_text][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="60" min_height="0" border="bottom" css_animation="right-to-left"][vc_column width="2/3"][vc_gallery type="thumbs" images="3168,3166,3190,3167,3169,3170,3171,3172,3173,3174,3175" onclick="link_no" custom_links_target="_self" img_size="180x120" grid_number="6"][kleo_gap size="10px"][/vc_column][vc_column width="1/3"][vc_column_text css_animation="right-to-left"]
<h3>Project Description</h3>
[/vc_column_text][kleo_gap size="10px"][vc_column_text css_animation="right-to-left"]
<div class="wpb_text_column wpb_content_element ">
<div class="wpb_wrapper">

Maecenas nec ultrices massa. Quisque orci diam, malesuada id augue nec, faucibus interdum dolor. Curabitur sagittis, felis porttitor placerat rhoncus, mauris diam sollicitudin nisl, sed luctus nulla sem non velit. Fusce a libero ullamcorper, volutpat orci ut, suscipit erat. Morbi tempor tortor vel urna lobortis.

Hendrerit faucibus massa consequat. Vivamus feugiat sapien massa, non luctus purus scelerisque et. Donec sodales pellentesque diam, et adipiscing erat imperdiet ac. Integer a lacinia velit. Pellentesque eu adipiscing arcu, a eleifend nulla. Vivamus tempus sem erat, eget lobortis odio interdum at. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Interdum et malesuada fames ac ante ipsum primis in faucibus.

Phasellus et feugiat risus. Ut a egestas libero. Morbi dictum quis felis vel congue. Sed eu arcu auctor, volutpat justo et, egestas libero. Phasellus sagittis sem in iaculis faucibus. Aenean vel lacus purus.

</div>
</div>
[/vc_column_text][vc_accordion icons_position="to-left"][vc_accordion_tab title="Project Details" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Client" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Request a Quote" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][contact-form-7 id="29"][/vc_accordion_tab][/vc_accordion][kleo_gap size="30px"][kleo_button title="Launch Project" target="_self" style="highlight" tooltip_position="left" tooltip_action="hover"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="60" padding_bottom="60" min_height="0" border="none" css_animation="right-to-left" text_align="center"][vc_column width="1/1"][vc_column_text css_animation="right-to-left"]
<h3>Related Projects</h3>
[/vc_column_text][kleo_gap size="20px"][kleo_portfolio display_type="default" title_style="normal" columns="5" item_count="5" pagination="no" filter="no" excerpt="yes" category="All"][/vc_column][/vc_row]
CONTENT;

	$data9            = array();
	$data9['name']    = 'Image Full Width Header';
	$data9['content'] = <<<CONTENT
[vc_row inner_container="yes" section_type="main" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="300px" padding_bottom="200px" min_height="0" border="none" css_animation="right-to-left" text_align="center" text_color="#ffffff" type="image" bg_image="749"][vc_column width="1/2"][/vc_column][vc_column width="1/2"][kleo_button title="Launch Project" target="_self" style="see-through" tooltip_position="left" tooltip_action="hover" size="lg"][/vc_column][/vc_row][vc_row inner_container="no" section_type="main" type="color" bg_color="#000000" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="0" padding_bottom="0" min_height="0" border="none" css_animation="right-to-left"][vc_column width="1/1"][vc_images_carousel images="741,740,739,737,736,734,733,735,728,731" onclick="link_image" custom_links_target="_self" speed="5000" hide_pagination_control="yes" scroll_fx="scroll" css_animation="fade" min_items="2" max_items="8" img_size="300x140"][/vc_column][/vc_row][vc_row inner_container="yes" section_type="footer" bg_position="top" bg_position_horizontal="left" bg_repeat="no-repeat" bg_cover="true" bg_attachment="false" parallax_speed="0.1" padding_top="40px" padding_bottom="40" min_height="0" border="bottom" css_animation="right-to-left"][vc_column width="1/2"][vc_column_text css_animation="right-to-left"]
<h3>Project Description</h3>
[/vc_column_text][kleo_gap size="10px"][vc_column_text css_animation="right-to-left"]

Maecenas nec ultrices massa. Quisque orci diam, malesuada id augue nec, faucibus interdum dolor. Curabitur sagittis, felis porttitor placerat rhoncus, mauris diam sollicitudin nisl, sed luctus nulla sem non velit. Fusce a libero ullamcorper, volutpat orci ut, suscipit erat. Morbi tempor tortor vel urna lobortis.

Hendrerit faucibus massa consequat. Vivamus feugiat sapien massa, non luctus purus scelerisque et. Donec sodales pellentesque diam, et adipiscing erat imperdiet ac. Integer a lacinia velit. Pellentesque eu adipiscing arcu, a eleifend nulla. Vivamus tempus sem erat, eget lobortis odio interdum at. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Interdum et malesuada fames ac ante ipsum primis in faucibus.

Phasellus et feugiat risus. Ut a egestas libero. Morbi dictum quis felis vel congue. Sed eu arcu auctor, volutpat justo et, egestas libero. Phasellus sagittis sem in iaculis faucibus. Aenean vel lacus purus.

[/vc_column_text][/vc_column][vc_column width="1/2"][kleo_gap size="10px"][vc_accordion icons_position="to-left"][vc_accordion_tab title="Project Details" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Client" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][vc_column_text css_animation="right-to-left"]I am text block. Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.[/vc_column_text][/vc_accordion_tab][vc_accordion_tab title="Request a Quote" icon="minus-circle" icon_closed="plus-circled" tooltip_position="left" tooltip_action="hover"][contact-form-7 id="29"][/vc_accordion_tab][/vc_accordion][/vc_column][/vc_row]
CONTENT;

	vc_add_default_templates( $data );
	vc_add_default_templates( $data2 );
	vc_add_default_templates( $data3 );
	vc_add_default_templates( $data4 );
	vc_add_default_templates( $data5 );
	vc_add_default_templates( $data6 );
	vc_add_default_templates( $data7 );
	vc_add_default_templates( $data8 );
	vc_add_default_templates( $data9 );
}


function kleo_add_icons_to_vc_message() {
	//Get current values stored in the width param in "Column" element
	$param = WPBMap::getParam( 'vc_message', 'icon_type' );
	if ( is_array( $param ) ) {
		$my_val         = array( __( 'Fontello(theme default)', 'k-elements' ) => 'fontello' );
		$param['value'] = array_merge( $my_val, $param['value'] );
		//Finally "mutate" param with new values
		vc_update_shortcode_param( 'vc_message', $param );
	}


	//Get current values stored in the width param in "Column" element
	$param = WPBMap::getParam( 'vc_message', 'color' );
	if ( is_array( $param ) ) {
		$param['weight'] = 2;
		//Finally "mutate" param with new values
		vc_update_shortcode_param( 'vc_message', $param );
	}

	//Get current values stored in the width param in "Column" element
	$param = WPBMap::getParam( 'vc_message', 'message_box_style' );
	if ( is_array( $param ) ) {
		$param['weight'] = 2;
		//Finally "mutate" param with new values
		vc_update_shortcode_param( 'vc_message', $param );
	}

	//Get current values stored in the width param in "Column" element
	$param = WPBMap::getParam( 'vc_message', 'style' );
	if ( is_array( $param ) ) {
		$param['weight'] = 2;
		//Finally "mutate" param with new values
		vc_update_shortcode_param( 'vc_message', $param );
	}

	//Get current values stored in the width param in "Column" element
	$param = WPBMap::getParam( 'vc_message', 'message_box_color' );
	if ( is_array( $param ) ) {
		$param['weight'] = 2;
		//Finally "mutate" param with new values
		vc_update_shortcode_param( 'vc_message', $param );
	}

	//Get current values stored in the width param in "Column" element
	$param = WPBMap::getParam( 'vc_message', 'icon_type' );
	if ( is_array( $param ) ) {
		$param['weight'] = 2;
		//Finally "mutate" param with new values
		vc_update_shortcode_param( 'vc_message', $param );
	}

}


function kleo_vc_add_icon( $shortcode = null ) {
	if ( ! $shortcode ) {
		return;
	}

	$pixel_icons = array(
		array( 'vc_pixel_icon vc_pixel_icon-alert' => __( 'Alert', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-info' => __( 'Info', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-tick' => __( 'Tick', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-explanation' => __( 'Explanation', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-address_book' => __( 'Address book', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-alarm_clock' => __( 'Alarm clock', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-anchor' => __( 'Anchor', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-application_image' => __( 'Application Image', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-arrow' => __( 'Arrow', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-asterisk' => __( 'Asterisk', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-hammer' => __( 'Hammer', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-balloon' => __( 'Balloon', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-balloon_buzz' => __( 'Balloon Buzz', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-balloon_facebook' => __( 'Balloon Facebook', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-balloon_twitter' => __( 'Balloon Twitter', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-battery' => __( 'Battery', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-binocular' => __( 'Binocular', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-document_excel' => __( 'Document Excel', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-document_image' => __( 'Document Image', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-document_music' => __( 'Document Music', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-document_office' => __( 'Document Office', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-document_pdf' => __( 'Document PDF', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-document_powerpoint' => __( 'Document Powerpoint', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-document_word' => __( 'Document Word', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-bookmark' => __( 'Bookmark', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-camcorder' => __( 'Camcorder', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-camera' => __( 'Camera', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-chart' => __( 'Chart', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-chart_pie' => __( 'Chart pie', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-clock' => __( 'Clock', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-fire' => __( 'Fire', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-heart' => __( 'Heart', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-mail' => __( 'Mail', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-play' => __( 'Play', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-shield' => __( 'Shield', 'k-elements' ) ),
		array( 'vc_pixel_icon vc_pixel_icon-video' => __( 'Video', 'k-elements' ) ),
	);

	/*vc_remove_param( $shortcode, 'icon_type' );
	vc_remove_param( $shortcode, 'icon_fontawesome' );
	vc_remove_param( $shortcode, 'icon_openiconic' );
	vc_remove_param( $shortcode, 'icon_typicons' );
	vc_remove_param( $shortcode, 'icon_entypo' );
	vc_remove_param( $shortcode, 'icon_linecons' );
	vc_remove_param( $shortcode, 'icon_pixelicons' );*/

	vc_add_param( $shortcode, array(
		'type'        => 'dropdown',
		'heading'     => __( 'Icon library', 'k-elements' ),
		'value'       => array(
			__( 'Fontello(theme default)', 'k-elements' ) => 'fontello',
			__( 'Font Awesome', 'k-elements' )            => 'fontawesome',
			__( 'Open Iconic', 'k-elements' )             => 'openiconic',
			__( 'Typicons', 'k-elements' )                => 'typicons',
			__( 'Entypo', 'k-elements' )                  => 'entypo',
			__( 'Linecons', 'k-elements' )                => 'linecons',
			__( 'Pixel', 'k-elements' )                   => 'pixelicons',
		),
		'param_name'  => 'icon_type',
		'description' => __( 'Select icon library.', 'k-elements' ),
	) );
	vc_add_param( $shortcode, array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon', 'k-elements' ),
		'param_name'  => 'icon',
		'value'       => '', // default value to backend editor admin_label
		'settings'    => array(
			'emptyIcon'    => false,
			'type'         => 'fontello',
			'iconsPerPage' => 4000,
		),
		'dependency'  => array(
			'element' => 'icon_type',
			'value'   => 'fontello',
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	) );
	vc_add_param( $shortcode, array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon', 'k-elements' ),
		'param_name'  => 'icon_fontawesome',
		'value'       => 'fa fa-info-circle',
		'settings'    => array(
			'emptyIcon'    => false, // default true, display an "EMPTY" icon?
			'iconsPerPage' => 200, // default 100, how many icons per/page to display
		),
		'dependency'  => array(
			'element' => 'icon_type',
			'value'   => 'fontawesome',
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	) );
	vc_add_param( $shortcode, array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon', 'k-elements' ),
		'param_name'  => 'icon_openiconic',
		'settings'    => array(
			'emptyIcon'    => false, // default true, display an "EMPTY" icon?
			'type'         => 'openiconic',
			'iconsPerPage' => 200, // default 100, how many icons per/page to display
		),
		'dependency'  => array(
			'element' => 'icon_type',
			'value'   => 'openiconic',
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	) );
	vc_add_param( $shortcode, array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon', 'k-elements' ),
		'param_name'  => 'icon_typicons',
		'settings'    => array(
			'emptyIcon'    => false, // default true, display an "EMPTY" icon?
			'type'         => 'typicons',
			'iconsPerPage' => 200, // default 100, how many icons per/page to display
		),
		'dependency'  => array(
			'element' => 'icon_type',
			'value'   => 'typicons',
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	) );
	vc_add_param( $shortcode, array(
		'type'       => 'iconpicker',
		'heading'    => __( 'Icon', 'k-elements' ),
		'param_name' => 'icon_entypo',
		'settings'   => array(
			'emptyIcon'    => false, // default true, display an "EMPTY" icon?
			'type'         => 'entypo',
			'iconsPerPage' => 300, // default 100, how many icons per/page to display
		),
		'dependency' => array(
			'element' => 'icon_type',
			'value'   => 'entypo',
		),
	) );
	vc_add_param( $shortcode, array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon', 'k-elements' ),
		'param_name'  => 'icon_linecons',
		'settings'    => array(
			'emptyIcon'    => false, // default true, display an "EMPTY" icon?
			'type'         => 'linecons',
			'iconsPerPage' => 200, // default 100, how many icons per/page to display
		),
		'dependency'  => array(
			'element' => 'icon_type',
			'value'   => 'linecons',
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	) );
	vc_add_param( $shortcode, array(
		'type'        => 'iconpicker',
		'heading'     => __( 'Icon', 'k-elements' ),
		'param_name'  => 'icon_pixelicons',
		'settings'    => array(
			'emptyIcon' => false, // default true, display an "EMPTY" icon?
			'type'      => 'pixelicons',
			'source'    => $pixel_icons,
		),
		'dependency'  => array(
			'element' => 'icon_type',
			'value'   => 'pixelicons',
		),
		'description' => __( 'Select icon from library.', 'k-elements' ),
	) );
}

function sq_vc_bp_register_shortcodes() {
	/* Get registered member types */
	$kleo_member_types = array( "All" => 'all' );
	if ( function_exists( 'bp_get_member_types' ) ) {
		$kleo_member_types = $kleo_member_types + bp_get_member_types( array(), 'names' );
	}

	// Buddypress Members Carousel

	vc_map(
		array(
			"name"     => __( "Members Carousel", "k-elements" ),
			"base"     => "kleo_bp_members_carousel",
			"class"    => "",
			"category" => __( 'BuddyPress' ),
			"icon"     => "kleo-bp-icon",
			"params"   => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Member Type", "k-elements" ),
					"param_name"  => "member_type",
					"value"       => $kleo_member_types,
					"description" => __( "The type of members to display." )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Filter", "k-elements" ),
					"param_name"  => "type",
					"value"       => array(
						'Active'       => 'active',
						'Newest'       => 'newest',
						'Popular'      => 'popular',
						'Online'       => 'online',
						'Alphabetical' => 'alphabetical',
						'Random'       => 'random'
					),
					"description" => __( "Filter the members by.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Number of members", "k-elements" ),
					"param_name"  => "number",
					"value"       => 12,
					"description" => __( "How many members to get.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Minimum Items", "k-elements" ),
					"param_name"  => "min_items",
					"value"       => 1,
					"description" => __( "Minimum number of items to show on the screen", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Maximum Items", "k-elements" ),
					"param_name"  => "max_items",
					"value"       => 6,
					"description" => __( "Maximum number of items to show on the screen", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Image Type", "k-elements" ),
					"param_name"  => "image_size",
					"value"       => array(
						'Full'      => 'full',
						'Thumbnail' => 'thumb'
					),
					"description" => __( "The size to get from buddypress", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Avatar type", "k-elements" ),
					"param_name"  => "rounded",
					"value"       => array(
						'Rounded' => 'rounded',
						'Square'  => 'square'
					),
					"description" => __( "Rounded or square avatar", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Image Width" ),
					"param_name"  => "item_width",
					"value"       => 150,
					"description" => __( "The size of the member image", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Auto play", "k-elements" ),
					"param_name"  => "autoplay",
					"value"       => array(
						'No'  => '',
						'Yes' => 'yes'
					),
					"description" => __( "If the carousel should play automatically", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Online status", "k-elements" ),
					"param_name"  => "online",
					"value"       => array(
						'Show' => 'show',
						'Hide' => 'noshow'
					),
					"description" => __( "Show online status", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Class", "k-elements" ),
					"param_name"  => "class",
					"value"       => '',
					"description" => __( "A class to add to the element for CSS referrences.", "k-elements" )
				),

			)
		)
	);


	// Buddypress Members Masonry

	vc_map(
		array(
			"name"     => __( "Members Masonry" ),
			"base"     => "kleo_bp_members_masonry",
			"class"    => "",
			"category" => __( 'BuddyPress' ),
			"icon"     => "kleo-bp-icon",
			"params"   => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Member Type", "k-elements" ),
					"param_name"  => "member_type",
					"value"       => $kleo_member_types,
					"description" => __( "The type of members to display.", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Filter", "k-elements" ),
					"param_name"  => "type",
					"value"       => array(
						'Active'       => 'active',
						'Newest'       => 'newest',
						'Popular'      => 'popular',
						'Online'       => 'online',
						'Alphabetical' => 'alphabetical',
						'Random'       => 'random'
					),
					"description" => __( "Filter the members by.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Number of members", "k-elements" ),
					"param_name"  => "number",
					"value"       => 12,
					"description" => __( "How many members to get.", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Avatar type", "k-elements" ),
					"param_name"  => "rounded",
					"value"       => array(
						'Rounded' => 'rounded',
						'Square'  => 'square'
					),
					"description" => __( "Rounded or square avatar", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Avatar size", "k-elements" ),
					"param_name"  => "avatarsize",
					"value"       => array(
						'Small' => '',
						'Large' => 'large'
					),
					"description" => __( "Small or large avatar", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Width & Height", "k-elements" ),
					"param_name"  => "width_height",
					"value"       => '',
					'dependency'  => array(
						'element' => 'avatarsize',
						'value'   => 'large',
					),
					"description" => __( "Example: 200x200", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Online status", "k-elements" ),
					"param_name"  => "online",
					"value"       => array(
						'Show' => 'show',
						'Hide' => 'noshow'
					),
					"description" => __( "Show online status", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Class", "k-elements" ),
					"param_name"  => "class",
					"value"       => '',
					"description" => __( "A class to add to the element for CSS referrences.", "k-elements" )
				),

			)
		)
	);


	/* Members grid */

	vc_map(
		array(
			"name"     => __( "Members Grid", "k-elements" ),
			"base"     => "kleo_bp_members_grid",
			"class"    => "",
			"category" => __( 'BuddyPress' ),
			"icon"     => "bp-icon",
			"params"   => array(
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Member Type", "k-elements" ),
					"param_name"  => "member_type",
					"value"       => $kleo_member_types,
					"description" => __( "The type of members to display.", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Filter", "k-elements" ),
					"param_name"  => "type",
					"value"       => array(
						'Active'       => 'active',
						'Newest'       => 'newest',
						'Popular'      => 'popular',
						'Online'       => 'online',
						'Alphabetical' => 'alphabetical',
						'Random'       => 'random'
					),
					"description" => __( "Filter the members by.", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Maximum members", "k-elements" ),
					"param_name"  => "number",
					"value"       => 12,
					"description" => __( "How many members you want to display.", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Members per line", "k-elements" ),
					"param_name"  => "perline",
					"value"       => array(
						'1'  => 'one',
						'2'  => 'two',
						'3'  => 'three',
						'4'  => 'four',
						'5'  => 'five',
						'6'  => 'six',
						'7'  => 'seven',
						'8'  => 'eight',
						'9'  => 'nine',
						'10' => 'ten',
						'11' => 'eleven',
						'12' => 'twelve'
					),
					"description" => __( "How many members to show per line", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Avatar type", "k-elements" ),
					"param_name"  => "rounded",
					"value"       => array(
						'Square'  => 'square',
						'Rounded' => 'rounded'
					),
					"description" => __( "Rounded or square avatar", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Avatar size", "k-elements" ),
					"param_name"  => "avatarsize",
					"value"       => array(
						'Small' => '',
						'Large' => 'large'
					),
					"description" => __( "Small or large avatar", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Width & Height", "k-elements" ),
					"param_name"  => "width_height",
					"value"       => '',
					'dependency'  => array(
						'element' => 'avatarsize',
						'value'   => 'large',
					),
					"description" => __( "Example: 200x200", "k-elements" )
				),
				array(
					"type"        => "dropdown",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Animation", "k-elements" ),
					"param_name"  => "animation",
					"value"       => array(
						'None'   => '',
						'Fade'   => 'fade',
						'Appear' => 'appear'
					),
					"description" => __( "Elements will appear animated", "k-elements" )
				),
				array(
					"type"        => "textfield",
					"holder"      => "div",
					"class"       => "",
					"heading"     => __( "Class", "k-elements" ),
					"param_name"  => "class",
					"value"       => '',
					"description" => __( "A class to add to the element for CSS referrences.", "k-elements" )
				),
			)
		)
	);

}

add_action( 'init', 'sq_vc_bp_register_shortcodes' );