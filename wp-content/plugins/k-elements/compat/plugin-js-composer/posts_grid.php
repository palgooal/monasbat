<?php
$params = [];

$params[] = $query_offset;

$params[] = array(
	"type"        => "dropdown",
	"holder"      => "div",
	"class"       => "hide hidden",
	"heading"     => __( "Layout", "k-elements" ),
	"param_name"  => "post_layout",
	"value"       => array(
		'Grid'             => 'grid',
		'Small Left Thumb' => 'small',
		'Standard'         => 'standard'
	),
	"description" => ""
);

$params[] = array(
	"param_name"  => "columns",
	"type"        => "textfield",
	"holder"      => "div",
	"class"       => "hide hidden",
	"heading"     => __( 'Number of items per row', 'k-elements' ),
	"value"       => '4',
	"description" => __( 'A number between 2 and 6', 'k-elements' ),
	"dependency"  => array(
		"element" => "post_layout",
		"value"   => "grid"
	)
);

if ( isset( $kleo_config['blog_layouts'] ) ) {

	$params[] = array(
		"type"        => "dropdown",
		"holder"      => "div",
		"class"       => "hide hidden",
		"heading"     => __( "Show Layout Switcher", "k-elements" ),
		"param_name"  => "show_switcher",
		"value"       => array(
			'No'  => 'no',
			'Yes' => 'yes'
		),
		"description" => __( "This allows the visitor to change posts layout.", "k-elements" ),
	);

	$params[] = array(
		"type"        => "checkbox",
		"holder"      => "div",
		"class"       => "hide hidden",
		"heading"     => __( "Switcher Layouts", "k-elements" ),
		"param_name"  => "switcher_layouts",
		"value"       => array_flip( $kleo_config['blog_layouts'] ),
		'std'         => join( ",", array_values( array_flip( $kleo_config['blog_layouts'] ) ) ),
		"description" => __( "What layouts are available for the user to switch.", "k-elements" ),
		"dependency"  => array(
			"element" => "show_switcher",
			"value"   => "yes"
		)
	);
}

$params[] = array(
	"type"        => "dropdown",
	"holder"      => "div",
	"class"       => "hide hidden",
	"heading"     => __( "Show Thumbnail image", "k-elements" ),
	"param_name"  => "show_thumb",
	"value"       => array(
		'Yes'                        => 'yes',
		'Just for the first post'    => 'just_1',
		'Just for first two posts'   => 'just_2',
		'Just for first three posts' => 'just_3',
		'No'                         => 'no'
	),
	"description" => "",
	"dependency"  => array(
		"element" => "post_layout",
		"value"   => "standard"
	)
);

$params[] = array(
	"type"        => "dropdown",
	"holder"      => "div",
	"class"       => "hide hidden",
	"heading"     => __( "Show post meta", "k-elements" ),
	"param_name"  => "show_meta",
	"value"       => array(
		'Yes' => 'yes',
		'No'  => 'no'
	),
	"description" => ""
);

$params[] = array(
	"type"        => "dropdown",
	"holder"      => "div",
	"class"       => "hide hidden",
	"heading"     => __( "Inline post meta", "k-elements" ),
	"param_name"  => "inline_meta",
	"value"       => array(
		'No'  => 'no',
		'Yes' => 'yes'
	),
	"description" => "Applies to Standard Layout only. Shows the post meta elements in one line if enabled.",
	"dependency"  => array(
		"element" => "show_meta",
		"value"   => "yes"
	)
);

$params[] = array(
	"type"        => "dropdown",
	"holder"      => "div",
	"class"       => "hide hidden",
	"heading"     => __( "Show post excerpt", "k-elements" ),
	"param_name"  => "show_excerpt",
	"value"       => array(
		'Yes' => 'yes',
		'No'  => 'no'
	),
	"description" => ""
);

$params[] = array(
	"type"        => "dropdown",
	"holder"      => "div",
	"class"       => "hide hidden",
	"heading"     => __( "Show post footer", "k-elements" ),
	"param_name"  => "show_footer",
	"value"       => array(
		'Yes' => 'yes',
		'No'  => 'no'
	),
	"description" => "Show read more button and post likes"
);

$params[] = array(
	'param_name'  => 'load_more',
	'heading'     => __( 'Enable Load More', 'k-elements' ),
	'description' => __( 'Enable Load more posts via AJAX.', 'k-elements' ),
	'type'        => 'checkbox',
	'class'       => 'hide hidden',
	'holder'      => 'div',
	'value'       => array(
		'Yes' => 'yes',
	),
);

$params[] = array(
	'param_name'  => 'ajax_post',
	'heading'     => '',
	'description' => '',
	'type'        => 'sq_hidden',
	'class'       => 'hide hidden',
	'holder'      => 'div',
	'value'       => '',
);

$params[] = array(
	'param_name'  => 'ajax_paged',
	'heading'     => '',
	'description' => '',
	'type'        => 'sq_hidden',
	'class'       => 'hide hidden',
	'holder'      => 'div',
	'value'       => '',
);

$params[] = $el_class;

if ( version_compare( WPB_VC_VERSION, '6.0.0', '>=' ) ) {

	$new_params   = [];
	$new_params[] = array(
		'type'       => 'loop',
		'heading'    => __( 'Build your query', 'k-elements' ),
		'param_name' => 'loop',
		'settings'   => array(
			'post_type'     => array( 'value' => 'post' ),
			'size'     => array( 'hidden' => false, 'value' => 12 ),
			'order_by' => array( 'value' => 'date' )
		)
	);

	$params = array_merge( $new_params, $params );

	vc_map(
		array(
			'base'        => 'vc_posts_grid',
			'name'        => 'Kleo Posts Grid',
			'weight'      => 970,
			'class'       => '',
			'icon'        => 'icon-wpb-application-icon-large',
			'category'    => __( "Content", 'k-elements' ),
			'description' => __( 'Insert Posts lists', 'k-elements' ),
			'params'      => $params
		)
	);
} else {
	vc_map_update( "vc_posts_grid",
		array(
			"name"            => "Kleo Posts",
			'category'        => __( "Content", 'k-elements' ),
			"deprecated"      => null,
			"content_element" => true
		)
	);

	vc_remove_param( 'vc_posts_grid', 'title' );
	vc_remove_param( 'vc_posts_grid', 'grid_columns_count' );
	vc_remove_param( 'vc_posts_grid', 'grid_layout' );
	vc_remove_param( 'vc_posts_grid', 'grid_link_target' );
	vc_remove_param( 'vc_posts_grid', 'filter' );
	vc_remove_param( 'vc_posts_grid', 'grid_layout_mode' );
	vc_remove_param( 'vc_posts_grid', 'grid_thumb_size' );
	vc_remove_param( 'vc_posts_grid', 'el_class' );

	vc_add_params( 'vc_posts_grid', $params );
}
