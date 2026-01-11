<?php

$params = [];

$params[] = $query_offset;

$params[] = array(
	"type"        => "dropdown",
	"holder"      => "div",
	"class"       => "hide hidden",
	"heading"     => __( "Layout", "k-elements" ),
	"param_name"  => "layout",
	"value"       => array(
		'Default' => 'default',
		'Overlay' => 'overlay'
	),
	"description" => "Select the carousel layout. Overlay works when you have featured images attached to the post"
);

$params[] = array(
	"type"        => "textfield",
	"holder"      => 'div',
	'class'       => 'hide hidden',
	"heading"     => __( "Minimum items to show", "k-elements" ),
	"param_name"  => "min_items",
	"value"       => "",
	"description" => "Defaults to 3",
);
$params[] = array(
	"type"        => "textfield",
	"holder"      => 'div',
	'class'       => 'hide hidden',
	"heading"     => __( "Maximum items to show", "k-elements" ),
	"param_name"  => "max_items",
	"value"       => "",
	"description" => "Defaults to 6",
);

$params[] = array(
	"type"        => "textfield",
	"holder"      => 'div',
	'class'       => 'hide hidden',
	"heading"     => __( "Elements height", "k-elements" ),
	"param_name"  => "height",
	"value"       => "",
	"description" => __( "Force a height on all elements. Expressed in pixels, eq: 300 will represent 300px", "k-elements" )
);
$params[] = $el_class;

if ( version_compare( WPB_VC_VERSION, '6.0.0', '>=' ) ) {

	$new_params   = [];
	$new_params[] = array(
		'type'       => 'loop',
		'heading'    => __( 'Build your query', 'k-elements' ),
		'param_name' => 'posts_query',
		'settings'   => array(
			'post_type' => array( 'value' => 'post' ),
			'size'      => array( 'hidden' => false, 'value' => 6 ),
			'order_by'  => array( 'value' => 'date' )
		)
	);

	$new_params[] = array(
		"type"        => "dropdown",
		"holder"      => "div",
		"class"       => "hide hidden",
		"heading"     => __( "Autoplay", "k-elements" ),
		"param_name"  => "autoplay",
		"value"       => array(
			'No' => '',
			'Yes' => 'yes',
		),
		"description" => "When enabled the slider will automatically play"
	);

	$new_params[] = array(
		"type"        => "textfield",
		"holder"      => 'div',
		'class'       => 'hide hidden',
		"heading"     => __( "Autoplay speed", "k-elements" ),
		"param_name"  => "speed",
		"value"       => "",
		"description" => "The time between slides. Expressed in milliseconds, example 2000 for 2 seconds. ",
	);

	$params = array_merge( $new_params, $params );
	vc_map(
		array(
			'base'        => 'vc_carousel',
			'name'        => 'Kleo Posts Carousel',
			'weight'      => 970,
			'class'       => '',
			'icon'        => 'icon-wpb-images-carousel',
			'category'    => __( "Content", 'k-elements' ),
			'description' => __( 'Insert Posts Carousel', 'k-elements' ),
			'params'      => $params
		)
	);


} else {

	vc_map_update( "vc_carousel",
		array(
			"name"            => "Kleo Posts Carousel",
			"deprecated"      => null,
			"content_element" => true
		)
	);
	vc_remove_param( 'vc_carousel', 'title' );
	vc_remove_param( 'vc_carousel', 'layout' );
	vc_remove_param( 'vc_carousel', 'link_target' );
	vc_remove_param( 'vc_carousel', 'thumb_size' );
	vc_remove_param( 'vc_carousel', 'mode' );
	vc_remove_param( 'vc_carousel', 'slides_per_view' );
	vc_remove_param( 'vc_carousel', 'partial_view' );
	vc_remove_param( 'vc_carousel', 'wrap' );
	vc_remove_param( 'vc_carousel', 'el_class' );
	vc_remove_param( 'vc_carousel', 'hide_pagination_control' );
	vc_remove_param( 'vc_carousel', 'hide_prev_next_buttons' );

	vc_add_params( 'vc_carousel', $params );
}
