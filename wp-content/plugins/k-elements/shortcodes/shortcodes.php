<?php
/**
 * Shortcodes logic.
 *
 */

// File Security Check
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/***************************************************
 * :: Include shortcodes
 ***************************************************/
global $kleo_config;
$k_elements = array(

	'kleo_gap'                 => array(
		'name'     => 'Gap',
		'category' => '',
		'example'  => '[kleo_gap size="12px" class="" id=""]',
	),
	'kleo_divider'             => array(
		'name'     => 'Divider',
		'category' => '',
		'example'  => '[kleo_divider type="full|long|double|short" double="yes|no" position="center|left|right" text="" class="" id=""]'
	),
	'kleo_grid'                => array(
		'name'     => 'Grid container',
		'category' => '',
		'example'  => '[kleo_grid type="2|3|4|5|6" animation="yes"][kleo_feature_item]Feature item 1[/kleo_feature_item][kleo_feature_item]Feature item 2[/kleo_feature_item][/kleo_grid]'
	),
	'kleo_feature_item'        => array(
		'name'     => 'Feature Item',
		'category' => '',
		'example'  => '[kleo_feature_item title="" icon="" icon_size="default|big" icon_position="left|center" ]Text[/kleo_feature_item]'
	),
	'kleo_button'              => array(
		'name'     => 'Button',
		'category' => '',
		'example'  => '[kleo_button title="Button text" href="" style="default" size="" ]'
	),
	'kleo_icon'                => array(
		'name'     => 'Icon',
		'category' => '',
		'example'  => '[kleo_icon icon="android" icon_size=""]'
	),
	'kleo_list'                => array(
		'name'     => 'Fancy List',
		'category' => '',
		'example'  => '[kleo_list][kleo_list_item]Lorem ipsum[/kleo_list_item][kleo_list_item]Lorem ipsum[/kleo_list_item][/kleo_list]'
	),
	'kleo_list_item'           => array(
		'name'     => 'Fancy List Item',
		'category' => '',
		'example'  => '[kleo_list_item]Lorem ipsum[/kleo_list_item]'
	),
	'kleo_animate_numbers'     => array(
		'name'     => 'Animated numbers',
		'category' => '',
		'example'  => '[kleo_animate_numbers animation="animate-when-almost-visible" timer=""]100[/kleo_animate_numbers]'
	),
	'kleo_visibility'          => array(
		'name'     => 'Responsive Visibility',
		'category' => '',
		'example'  => '[kleo_visibility type="visible-md"]'
	),
	'kleo_pin'                 => array(
		'name'     => 'Pin',
		'category' => '',
		'example'  => '[kleo_pin type="circle" left="" right="" top="" bottom=""]'
	),
	'kleo_clients'             => array(
		'name'     => 'Clients',
		'category' => '',
		'example'  => '[kleo_clients number=5 animated=yes animation=fade]'
	),
	'kleo_testimonials'        => array(
		'name'     => 'Testimonials',
		'category' => '',
		'example'  => '[kleo_testimonials number=3]'
	),
	'kleo_portfolio'           => array(
		'name'     => 'Portfolio',
		'category' => '',
		'example'  => '[kleo_portfolio]'
	),
	'kleo_bp_members_carousel' => array(
		'name'     => 'Members Carousel',
		'category' => 'buddypress',
		'example'  => '[kleo_bp_members_carousel member_type="all" type="newest" number="12" min_items="1" max_items="6" item_width="150" image_size="full" rounded="rounded" online="show" class=""]'
	),
	'kleo_bp_members_grid'     => array(
		'name'     => 'Members Grid',
		'category' => 'buddypress',
		'example'  => '[kleo_bp_members_grid member_type="all" type="newest" number="12" size="150" class=""]'
	),
	'kleo_bp_members_masonry'  => array(
		'name'     => 'Members Masonry',
		'category' => 'buddypress',
		'example'  => '[kleo_bp_members_masonry member_type="all" type="newest" number="12" rounded="rounded" class=""]'
	),
	'kleo_bp_groups_carousel'  => array(
		'name'     => 'Groups Carousel',
		'category' => 'buddypress',
		'example'  => '[kleo_bp_groups_carousel type="newest" number="12" min_items="1" max_items="6" item_width="150" image_size="full" rounded="rounded" class=""]'
	),
	'kleo_bp_groups_grid'      => array(
		'name'     => 'Groups Grid',
		'category' => 'buddypress',
		'example'  => '[kleo_bp_groups_grid type="newest" number="12" size="150" class=""]'
	),
	'kleo_bp_groups_masonry'   => array(
		'name'     => 'Groups Masonry',
		'category' => 'buddypress',
		'example'  => '[kleo_bp_groups_masonry type="newest" number="12" rounded="rounded" class=""]'
	),
	'kleo_bp_activity_stream'  => array(
		'name'     => 'Activity Stream',
		'category' => 'buddypress',
		'example'  => '[kleo_bp_activity_stream number=6 show_button=yes button_label="View All Activity" button_link="/activity"]'
	),
	'kleo_bp_activity_page'    => array(
		'name'     => 'Activity Page',
		'category' => 'buddypress',
		'example'  => '[kleo_bp_activity_page]'
	),
	'kleo_pricing_table'       => array(
		'name'     => 'Pricing table',
		'category' => '',
		'example'  => '[kleo_pricing_table columns=3 style=1|2 heading_bg="#689F38" price_bg="#8BC34A" text_color="#ffffff"] add here kleo_pricing_table_item elements [/kleo_pricing_table]'
	),
	'kleo_pricing_table_item'  => array(
		'name'     => 'Pricing table item',
		'category' => '',
		'example'  => '[kleo_pricing_table_item title="Package 1" price="$10" popular="yes|no" features="Feature one, Feature 2", button_label="Select" link="url:http://mysite.com|target:_blank"]Description[/kleo_pricing_table_item]'
	),
	'kleo_particles'           => array(
		'name'     => 'Galaxy Particles',
		'category' => '',
		'example'  => '[kleo_particles]'
	),
	'kleo_news_focus'          => array(
		'name'     => 'News Focus',
		'category' => '',
		'example'  => '[kleo_news_focus name="Section name"]'
	),
	'kleo_news_highlight'      => array(
		'name'     => 'News Highlight',
		'category' => '',
		'example'  => '[kleo_news_highlight]'
	),
	'kleo_news_ticker'         => array(
		'name'     => 'News Ticker',
		'category' => '',
		'example'  => '[kleo_news_ticker]'
	),
	'kleo_news_puzzle'         => array(
		'name'     => 'News Column',
		'category' => '',
		'example'  => '[kleo_news_puzzle]'
	),
	'kleo_login'               => array(
		'name'     => 'Login / Lost Password Forms',
		'category' => '',
		'example'  => '[kleo_login show="login|lostpass" login_title="Log in with your credentials" lostpass_title="Forgot your details?" login_link="#|url" lostpass_link="#|url" register_url="hide|url"]'
	),
	'kleo_register'            => array(
		'name'     => 'Register Form',
		'category' => '',
		'example'  => '[kleo_register register_title="Create Account"]'
	),
	'kleo_social_share'        => array(
		'name'     => 'Kleo Social Share',
		'category' => '',
		'example'  => '[kleo_social_share]'
	),
	'kleo_magic_container'     => array(
		'name'     => 'Magic Container',
		'category' => '',
		'example'  => '[kleo_magic_container]'
	),
);

$k_elements                = apply_filters( 'k_elements_shortcodes', $k_elements );
$kleo_config['shortcodes'] = $k_elements;


/***************************************************
 * :: SMALL SHORTCODES
 ***************************************************/

function kleo_current_year( $atts, $content = null ) {
	return date( 'Y' );
}

add_shortcode( 'current-year', 'kleo_current_year' );
add_shortcode( 'site-url', 'home_url' );


/**
 * Animated numbers
 */
add_shortcode( "kleo_animate_numbers", "kleo_animate_numbers_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_animate_numbers_mce" );


function kleo_animate_numbers_func( $atts, $content = null ) {
	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_animate_numbers'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_animate_numbers';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_animate_numbers_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_animate_numbers'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/* Button */
add_shortcode( "kleo_button", "kleo_button_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_button_mce" );

function kleo_button_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_button'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return $output;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_button';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_button_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_button'];

	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/* List */
add_shortcode( "kleo_list", "kleo_list_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_list_mce" );

function kleo_list_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_list'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_list';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_list_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_list'];

	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/* List item */
add_shortcode( "kleo_list_item", "kleo_list_item_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_list_item_mce" );

function kleo_list_item_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_list_item'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_list_item';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_list_item_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_list_item'];

	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * Responsive Visibility
 */
add_shortcode( "kleo_visibility", "kleo_visibility_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_visibility_mce" );


function kleo_visibility_func( $atts, $content = null ) {
	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_visibility'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_visibility';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_visibility_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_visibility'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * ICON
 */

add_shortcode( "kleo_icon", "kleo_icon_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_icon_mce" );


function kleo_icon_func( $atts, $content = null ) {
	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_icon'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_icon';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_icon_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_icon'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/* 
 * Clients
 */

if ( isset( $k_elements['kleo_clients'] ) && post_type_exists( 'kleo_clients' ) ) {
	add_shortcode( "kleo_clients", "kleo_clients_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_clients_mce" );

	function kleo_clients_func( $atts, $content = null ) {
		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_clients'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_clients';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_clients_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_clients'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}

}

/* 
 * Testimonials
 */
if ( isset( $k_elements['kleo_testimonials'] ) && post_type_exists( 'kleo-testimonials' ) ) {
	add_shortcode( "kleo_testimonials", "kleo_testimonials_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_testimonials_mce" );

	function kleo_testimonials_func( $atts, $content = null ) {
		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_testimonials'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_testimonials';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_testimonials_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_testimonials'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}

}

/**
 * PIN
 */

if ( isset( $k_elements['kleo_pin'] ) ) {
	add_shortcode( "kleo_pin", "kleo_pin_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_pin_mce" );


	function kleo_pin_func( $atts, $content = null ) {
		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_pin'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_pin';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_pin_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_pin'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}
}

/**
 * GAP
 */

add_shortcode( "kleo_gap", "kleo_gap_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_gap_mce" );


function kleo_gap_func( $atts, $content = null ) {
	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_gap'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_gap';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_gap_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_gap'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * DIVIDER
 */

add_shortcode( "kleo_divider", "kleo_divider_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_divider_mce" );


function kleo_divider_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_divider'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_divider';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_divider_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_divider'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}

/* Grid */

if ( isset( $k_elements['kleo_grid'] ) ) {
	add_shortcode( "kleo_grid", "kleo_grid_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_grid_mce" );


	function kleo_grid_func( $atts, $content = null ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_grid'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_grid';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_grid_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_grid'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}
}

/* Feature Item */

if ( isset( $k_elements['kleo_feature_item'] ) ) {
	add_shortcode( "kleo_feature_item", "kleo_feature_item_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_feature_item_mce" );

	function kleo_feature_item_func( $atts, $content = null ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_feature_item'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_feature_item';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_feature_item_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_feature_item'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}
}

/**
 * Buddypress Activity Stream.
 */

add_shortcode( "kleo_bp_activity_stream", "kleo_bp_activity_stream_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_bp_activity_stream_mce" );


function kleo_bp_activity_stream_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_activity_stream'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_bp_activity_stream';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_bp_activity_stream_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_activity_stream'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}

/**
 * Buddypress Activity page.
 */

add_shortcode( 'kleo_bp_activity_page', 'kleo_bp_activity_page_func' );
add_filter( 'kleo_tinymce_shortcodes', "kleo_bp_groups_carousel_mce" );


function kleo_bp_activity_page_func( $atts, $content = null ) {
	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_activity_page'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_bp_activity_page';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_bp_activity_page_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_activity_page'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * Buddypress Groups carousel.
 */

add_shortcode( "kleo_bp_groups_carousel", "kleo_bp_groups_carousel_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_bp_groups_carousel_mce" );


function kleo_bp_groups_carousel_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_groups_carousel'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_bp_groups_carousel';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_bp_groups_carousel_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_groups_carousel'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * Buddypress Groups grid.
 */

add_shortcode( "kleo_bp_groups_grid", "kleo_bp_groups_grid_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_bp_groups_grid_mce" );


function kleo_bp_groups_grid_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_groups_grid'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_bp_groups_grid';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_bp_groups_grid_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_groups_grid'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * Buddypress Groups carousel.
 */

add_shortcode( "kleo_bp_groups_masonry", "kleo_bp_groups_masonry_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_bp_groups_masonry_mce" );


function kleo_bp_groups_masonry_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_groups_masonry'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_bp_groups_masonry';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_bp_groups_masonry_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_groups_masonry'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * Buddypress Members carousel.
 */

add_shortcode( "kleo_bp_members_carousel", "kleo_bp_members_carousel_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_bp_members_carousel_mce" );


function kleo_bp_members_carousel_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_members_carousel'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_bp_members_carousel';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_bp_members_carousel_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_members_carousel'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$shortcode = $kleo_config['shortcodes']['kleo_bp_members_carousel'];

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * Buddypress Members grid.
 */

add_shortcode( "kleo_bp_members_grid", "kleo_bp_members_grid_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_bp_members_grid_mce" );


function kleo_bp_members_grid_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_members_grid'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_bp_members_grid';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_bp_members_grid_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_members_grid'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$shortcode = $kleo_config['shortcodes']['kleo_bp_members_grid'];

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * Buddypress Members carousel.
 */

add_shortcode( "kleo_bp_members_masonry", "kleo_bp_members_masonry_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_bp_members_masonry_mce" );


function kleo_bp_members_masonry_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_members_masonry'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_bp_members_masonry';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_bp_members_masonry_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_bp_members_masonry'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$shortcode = $kleo_config['shortcodes']['kleo_bp_members_masonry'];

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/* BP MEMBERS STATS */

if ( ! function_exists( 'kleo_bp_member_stats_func' ) ) {
	function kleo_bp_member_stats_func( $atts, $content = null ) {

		$field = $value = $online = '';

		extract( shortcode_atts( array(
			'field'    => '',
			'field_id' => '',
			'value'    => '',
			'online'   => false
		), $atts ) );

		return kleo_bp_member_stats( $field, $value, $online );
	}

	add_shortcode( 'kleo_bp_member_stats', 'kleo_bp_member_stats_func' );
	add_filter( 'kleo_tinymce_shortcodes', function ( $args ) {
		$args["buddypress"][] = array(
			'name'     => __( 'Members statistics', 'k-elements' ),
			'category' => 'buddypress',
			'code'     => '[kleo_bp_member_stats field="" value="" online=false]',
		);

		return $args;
	} );
}


if ( ! function_exists( 'kleo_total_members' ) ) {
	function kleo_total_members( $atts, $content = null ) {
		if ( function_exists( 'bp_is_active' ) ) {
			return bp_get_total_member_count();
		}

		return '';
	}

	add_shortcode( 'kleo_total_members', 'kleo_total_members' );
	add_filter( 'kleo_tinymce_shortcodes', function ( $args ) {
		$args['buddypress'][] = array(
			'name'     => __( 'Total members', 'k-elements' ),
			'category' => 'buddypress',
			'code'     => '[kleo_total_members]',
		);

		return $args;
	} );
}

if ( ! function_exists( 'kleo_bbpress_stats' ) ) {
	function kleo_bbpress_stats( $atts, $content = null ) {
		$a = shortcode_atts( array(
			'type' => '',
		), $atts );

		if ( ! class_exists( 'bbPress' ) ) {
			return "0";
		}

		if ( $a['type'] == 'forums' ) {
			$forum_count = wp_count_posts( bbp_get_forum_post_type() )->publish;

			return $forum_count;

		} else if ( $a['type'] == 'replies' ) {
			$all_replies = wp_count_posts( bbp_get_reply_post_type() )->publish;

			return $all_replies;
		} else if ( $a['type'] == 'topics' ) {
			$all_topics = wp_count_posts( bbp_get_topic_post_type() )->publish;

			return $all_topics;
		}

	}

	add_shortcode( 'kleo_bbpress_stats', 'kleo_bbpress_stats' );
	add_filter( 'kleo_tinymce_shortcodes', function ( $args ) {
		$args[''][] = array(
			'name'     => __( 'bbPress stats', 'k-elements' ),
			'category' => '',
			'code'     => '[kleo_bbpress_stats type=forums|replies|topics]',
		);

		return $args;
	} );
}

/**
 * Restrict content based on type of user
 */
if ( ! function_exists( 'kleo_restrict_func' ) ) {
	function kleo_restrict_func( $atts, $content = null ) {
		$a = shortcode_atts( array(
			'type' => 'user',
		), $atts );

		if ( is_user_logged_in() && $a['type'] == 'user' ) {
			return do_shortcode( $content );

		} else if ( ! is_user_logged_in() && $a['type'] == 'guest' ) {
			return do_shortcode( $content );
		}
	}

	add_shortcode( 'kleo_restrict', 'kleo_restrict_func' );
	add_filter( 'kleo_tinymce_shortcodes', function ( $args ) {
		$args[""][] = array(
			'name'     => __( 'Content by user type', 'k-elements' ),
			'category' => '',
			'code'     => '[kleo_restrict type=user|guest]Content here[/kleo_restrict]',
		);

		return $args;
	} );
}

/**
 * Portfolio
 */
if ( ! function_exists( 'kleo_portfolio_func' ) && function_exists( 'kleo_portfolio_items' ) && isset( $k_elements['kleo_portfolio'] ) ) {
	function kleo_portfolio_func( $atts, $content = null ) {
		$output = $display_type = $title_style = $columns = $item_count = $pagination = $filter = $ajax = $excerpt = $image_size = $category = $exclude_categories = $el_class = '';
		extract( shortcode_atts( array(
			'display_type'       => 'default',
			'title_style'        => 'standard',
			'columns'            => 4,
			'item_count'         => null,
			'pagination'         => 'no',
			'filter'             => 'yes',
			'ajax'               => '',
			'excerpt'            => 1,
			'image_size'         => '',
			'category'           => '',
			'exclude_categories' => '',
			'el_class'           => ''
		), $atts ) );


		$class = '';
		if ( $el_class != '' ) {
			$class = ' ' . $el_class;
		}

		if ( $exclude_categories != '' && ! is_array( $exclude_categories ) ) {
			$exclude_categories = explode( ',', $exclude_categories );
		}

		$img_width = $img_height = '';
		if ( $image_size != '' ) {
			$img_array = explode( 'x', strtolower( $image_size ) );
			if ( isset( $img_array[1] ) ) {
				$img_width  = $img_array[0];
				$img_height = $img_array[1];
			}
		}

		$use_ajax = false;
		if ( 'yes' == $filter && 'yes' == $ajax ) {
			$use_ajax = true;
		}

		$output .= '<div class="wpb_wrapper' . $class . '">';
		$output .= kleo_portfolio_items( $display_type, $title_style, $columns, $item_count, $pagination, $filter, $excerpt, $img_width, $img_height, $category, $exclude_categories, $use_ajax );
		$output .= '</div>';

		return $output;
	}

	add_shortcode( 'kleo_portfolio', 'kleo_portfolio_func' );
	add_filter( 'kleo_tinymce_shortcodes', function ( $args ) {
		$args[''][] = array(
			'name'     => 'Portfolio',
			'category' => '',
			'code'     => '[kleo_portfolio]',
		);

		return $args;
	} );
}


add_shortcode( 'kleo_search_form', 'kleo_search_form_func' );
function kleo_search_form_func( $atts, $content = null ) {

	$form_style = $type = $placeholder = $context = $el_class = '';
	extract( shortcode_atts( array(
		'form_style'  => 'default',
		'type'        => 'both',
		'context'     => '',
		'placeholder' => '',
		'el_class'    => ''
	), $atts ) );


	global $kleo_config;

	$class = '';
	if ( $el_class != '' ) {
		$class = ' ' . $el_class;
	}

	$class         .= ' search-style-' . $form_style;

	if ( is_array( $context ) ) {
		$context = implode( ',', $context );
		$context_array = $context;
	} else {
		$context_array = explode( ',', $context );
	}

	//Defaults
	$action     = home_url( '/' );
	$hidden     = '';
	$input_name = 's';

	$ajax_results = 'yes';
	$search_page  = 'yes';

	if ( $type == 'ajax' ) {
		$search_page = 'no';
	} elseif ( $type == 'form_submit' ) {
		$ajax_results = 'no';
	}

	if ( function_exists( 'bp_is_active' ) && $context == 'members' ) {
		//Buddypress members form link
		$action = bp_get_members_directory_permalink();

	} elseif ( function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) && $context == 'groups' ) {
		//Buddypress group directory link
		$action = bp_get_groups_directory_permalink();

	} elseif ( class_exists( 'bbPress' ) && $context == 'forum' ) {
		$action     = bbp_get_search_url();
		$input_name = 'bbp_search';

	} elseif ( $context == 'product' ) {
		$hidden .= '<input type="hidden" name="post_type" value="product">';
		$action = home_url( '/' ) . '?post_type=product';
	} else {
		foreach ( $context_array as $post_type ) {
			if ( in_array( $post_type, [ 'members', 'groups' ] ) ) {
				continue;
			}
			$hidden .= '<input type="hidden" name="post_type[]" value="' . esc_attr( $post_type ) . '">';

		}
	}

	$value  = isset( $_REQUEST['s'] ) ? esc_attr( $_REQUEST['s'] ) : '';
	$output = '<div class="kleo-search-wrap kleo-search-form' . $class . '">';

	$output .= '<form role="search" method="get" class="searchform" ' . ( $search_page == 'no' ? ' onsubmit="return false;"' : '' ) . ' action="' . esc_url( $action ) . '" data-context="' . esc_attr( $context ) . '">
	    ' . $hidden . '
		<div class="input-group">
			<input name="' . $input_name . '" autocomplete="off" type="text" class="ajax_s form-control input-lg" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '">';

	if ( $search_page == 'yes' ) {
		$output .= '<span class="input-group-btn">' .
		           '<input type="submit" value="' . esc_html__( "Search" ) . '" class="button searchsubmit">' .
		           '</span>';
	}

	$output .= '</div>' .
	           '</form>';
	if ( $ajax_results == 'yes' ) {
		$output .= '<span class="kleo-ajax-search-loading"><span class="kleo-loading-icon"></span></span>' .
		           '<div class="kleo_ajax_results"></div>';
	}

	$output .= '</div>';

	return $output;
}

add_shortcode( 'kleo_bbp_header_search', 'kleo_bbp_header_search_func' );

function kleo_bbp_header_search_func( $atts, $content = null ) {

	if ( ! class_exists( 'bbPress' ) ) {
		return esc_html__( 'You need to install bbPress plugin to enable this shortcode!', 'k-elements' );
	}

	$forum_id = $el_class = $placeholder = $text_color = $bg_color = '';
	extract( shortcode_atts( array(
		'forum_id'    => '',
		'placeholder' => '',
		'text_color'  => '#ffffff',
		'bg_color'    => '#e74c3c',
		'el_class'    => ''
	), $atts ) );

	$class = '';
	if ( $el_class != '' ) {
		$class = ' ' . $el_class;
	}

	$output = '<div class="header-bb-search' . $class . '">';
	$output .= do_shortcode( '[bbp-search]' );
	$output .= '</div>';

	$to_replace_input  = ' placeholder="' . $placeholder . '"';
	$to_replace_button = ' style="color: ' . $text_color . '; background-color: ' . $bg_color . ';"';

	$output = str_replace( 'id="bbp_search"', 'id="bbp_search"' . $to_replace_input, $output );
	$output = str_replace( 'id="bbp_search_submit"', 'id="bbp_search_submit"' . $to_replace_button, $output );

	if ( $forum_id != '' && is_numeric( $forum_id ) ) {
		$output = str_replace( '</form>', '<input type="hidden" name="bbp_search_forum_id" value="' . $forum_id . '"></form>', $output );
	}

	return $output;
}


/**
 * Count posts by type
 * thanks to @sharmstr
 *
 * @param array $atts
 * @param null $content
 *
 * @return mixed
 */
function kleo_post_count( $atts, $content = null ) {
	$a = shortcode_atts( array(
		'type' => '',
	), $atts );

	return wp_count_posts( $a['type'] )->publish;
}

add_shortcode( 'kleo_post_count', 'kleo_post_count' );
add_filter( 'kleo_tinymce_shortcodes', function ( $args ) {
	$args[""][] = array(
		'name' => __( 'Post count', 'k-elements' ),
		'code' => '[kleo_post_count type=post|page|custom post type]',
	);

	return $args;
} );


/**
 * Add a nice snowing effect to whole window or just element
 *
 * @param array $atts
 * @param string $content
 *
 * @return string
 * @since 2.3
 *
 */
function kleo_snow( $atts, $content = null ) {

	if ( ! wp_script_is( 'snow', 'registered' ) ) {
		return '<h3 class="text-center">Please update KLEO theme to version 2.3 in order to use SNOW effect</h3>';
	}

	$output = $scope = $type = '';
	extract( shortcode_atts( array(
		'scope' => 'column',
		'type'  => 'column',
	), $atts ) );

	wp_enqueue_script( 'three-canvas' );
	wp_enqueue_script( 'snow' );

	/* Small compat with an type attribute added by mistake instead of scope */
	if ( $type != 'column' ) {
		$scope = $type;
	}

	$rand = rand( 100, 999 );
	if ( $scope == 'column' ) {
		$output .= '<span id="snow-' . $rand . '"></span>';
		$output .= '<script>jQuery(document).ready(function() { initSnow(jQuery("#snow-' . $rand . '").closest("div[class*=\'col-sm-\']")); });</script>';
	} elseif ( $scope == 'window' ) {
		$output .= '<script>jQuery(document).ready(function() { initSnow(jQuery("body")); });</script>';
	}

	return $output;
}

add_shortcode( 'kleo_snow', 'kleo_snow' );


/**
 * Pricing tables
 */

if ( isset( $k_elements['kleo_pricing_table'] ) ) {
	add_shortcode( "kleo_pricing_table", "kleo_pricing_table_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_pricing_table_mce" );


	function kleo_pricing_table_func( $atts, $content = null ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_pricing_table'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_pricing_table';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_pricing_table_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_pricing_table'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$shortcode = $kleo_config['shortcodes']['kleo_pricing_table'];

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}
}

/**
 * Pricing table item
 */

if ( isset( $k_elements['kleo_pricing_table_item'] ) ) {
	add_shortcode( "kleo_pricing_table_item", "kleo_pricing_table_item_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_pricing_table_item_mce" );


	function kleo_pricing_table_item_func( $atts, $content = null ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_pricing_table_item'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_pricing_table_item';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_pricing_table_item_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_pricing_table_item'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$shortcode = $kleo_config['shortcodes']['kleo_pricing_table_item'];

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}
}

/**
 * Particles shortcode
 */

add_shortcode( "kleo_particles", "kleo_particles_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_particles_mce" );


function kleo_particles_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_particles'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_particles';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_particles_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_particles'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$shortcode = $kleo_config['shortcodes']['kleo_particles'];

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * News Focus shortcode
 */

if ( isset( $k_elements['kleo_news_focus'] ) ) {
	add_shortcode( "kleo_news_focus", "kleo_news_focus_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_news_focus_mce" );


	function kleo_news_focus_func( $atts, $content = null ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_news_focus'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_news_focus';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_news_focus_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_news_focus'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$shortcode = $kleo_config['shortcodes']['kleo_news_focus'];

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}
}

/**
 * News Highlight shortcode
 */

if ( isset( $k_elements['kleo_news_highlight'] ) ) {
	add_shortcode( "kleo_news_highlight", "kleo_news_highlight_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_news_highlight_mce" );


	function kleo_news_highlight_func( $atts, $content = null ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_news_highlight'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_news_highlight';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_news_highlight_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_news_highlight'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$shortcode = $kleo_config['shortcodes']['kleo_news_highlight'];

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}
}


/**
 * News Ticker shortcode
 */

if ( isset( $k_elements['kleo_news_ticker'] ) ) {
	add_shortcode( "kleo_news_ticker", "kleo_news_ticker_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_news_ticker_mce" );


	function kleo_news_ticker_func( $atts, $content = null ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_news_ticker'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_news_ticker';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_news_ticker_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_news_ticker'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$shortcode = $kleo_config['shortcodes']['kleo_news_ticker'];

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}
}


/**
 * News Ticker shortcode
 */

if ( isset( $k_elements['kleo_news_puzzle'] ) ) {
	add_shortcode( "kleo_news_puzzle", "kleo_news_puzzle_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_news_puzzle_mce" );


	function kleo_news_puzzle_func( $atts, $content = null ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_news_puzzle'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_news_puzzle';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_news_puzzle_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_news_puzzle'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$shortcode = $kleo_config['shortcodes']['kleo_news_puzzle'];

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}
}


/**
 * Login / Lost Password Forms shortcode
 */

add_shortcode( "kleo_login", "kleo_login_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_login_mce" );


function kleo_login_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_login'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_login';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_login_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_login'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$shortcode = $kleo_config['shortcodes']['kleo_login'];

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}

/**
 * Register Form shortcode
 */

if ( isset( $k_elements['kleo_register'] ) ) {
	add_shortcode( "kleo_register", "kleo_register_func" );
	add_filter( 'kleo_tinymce_shortcodes', "kleo_register_mce" );

	function kleo_register_func( $atts, $content = null ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_register'];
		$output    = '';

		if ( ! isset( $shortcode ) ) {
			return;
		}

		$sh_category = '';
		if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
			$sh_category = trailingslashit( $shortcode['category'] );
		}
		$shortcode_path = $sh_category . 'kleo_register';

		if ( find_shortcode_template( $shortcode_path ) ) {
			include find_shortcode_template( $shortcode_path );
		} else {
			$output = kleo_shortcode_not_found();
		}

		return $output;
	}

	function kleo_register_mce( $args ) {

		global $kleo_config;
		$shortcode = $kleo_config['shortcodes']['kleo_register'];
		if ( ! isset( $shortcode ) ) {
			return $args;
		}

		$shortcode = $kleo_config['shortcodes']['kleo_register'];

		$args[ $shortcode["category"] ][] = array(
			"name"     => $shortcode["name"],
			"category" => $shortcode["category"],
			"code"     => $shortcode["example"]
		);

		return $args;
	}
}


/**
 * Kleo Social Share shortcode
 */

add_shortcode( "kleo_social_share", "kleo_social_share_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_social_share_mce" );


function kleo_social_share_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_social_share'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_social_share';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_social_share_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_social_share'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$shortcode = $kleo_config['shortcodes']['kleo_social_share'];

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


/**
 * Magic Container
 */

add_shortcode( "kleo_magic_container", "kleo_magic_container_func" );
add_filter( 'kleo_tinymce_shortcodes', "kleo_magic_container_mce" );


function kleo_magic_container_func( $atts, $content = null ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_magic_container'];
	$output    = '';

	if ( ! isset( $shortcode ) ) {
		return;
	}

	$sh_category = '';
	if ( isset( $shortcode['category'] ) && ! empty( $shortcode['category'] ) ) {
		$sh_category = trailingslashit( $shortcode['category'] );
	}
	$shortcode_path = $sh_category . 'kleo_magic_container';

	if ( find_shortcode_template( $shortcode_path ) ) {
		include find_shortcode_template( $shortcode_path );
	} else {
		$output = kleo_shortcode_not_found();
	}

	return $output;
}

function kleo_magic_container_mce( $args ) {

	global $kleo_config;
	$shortcode = $kleo_config['shortcodes']['kleo_magic_container'];
	if ( ! isset( $shortcode ) ) {
		return $args;
	}

	$shortcode = $kleo_config['shortcodes']['kleo_magic_container'];

	$args[ $shortcode["category"] ][] = array(
		"name"     => $shortcode["name"],
		"category" => $shortcode["category"],
		"code"     => $shortcode["example"]
	);

	return $args;
}


if ( ! function_exists( 'kleo_pmpro_access_func' ) ) {

	function kleo_pmpro_access_func( $atts, $content = "" ) {
		if ( ! function_exists( 'kleo_pmpro_has_access' ) ) {
			return '';
		}

		$atts = shortcode_atts( array(
			'area' => '',
			'user' => '',
			'type' => 'access' //no_access
		), $atts, 'kleo_pmpro_access' );

		if ( ! $atts['user'] ) {
			$user_id = get_current_user_id();
		} else {
			$user = get_user_by( 'login', $atts['user'] );
			if ( ! $user ) {
				return '';
			}
			$user_id = $user->ID;
		}


		if ( $atts['area'] != '' ) {
			if ( $atts['type'] == 'access' && kleo_pmpro_has_access( $atts['area'], $user_id ) ) {
				return $content;
			} elseif ( $atts['type'] == 'no_access' && ! kleo_pmpro_has_access( $atts['area'], $user_id ) ) {
				return $content;
			}
		}

		return '';

	}

	add_shortcode( 'kleo_pmpro_access', 'kleo_pmpro_access_func' );
}

if ( function_exists( 'kleo_get_social_profiles' ) ) {
	add_shortcode( 'kleo_social_icons', 'kleo_get_social_profiles' );
}


if ( ! shortcode_exists( 'vc_posts_grid' ) ) {
	add_shortcode( 'vc_posts_grid', 'kleo_posts_func' );
}
add_shortcode( 'kleo_posts', 'kleo_posts_func' );

function kleo_posts_func( $atts = [] ) {

	ob_start();

	$template_path = locate_template( 'vc_templates/vc_posts_grid.php' );

	if ( $template_path != '' ) {
		include( $template_path );
	}

	return ob_get_clean();
}

if ( ! shortcode_exists( 'vc_carousel' ) ) {
	add_shortcode( 'vc_carousel', 'kleo_posts_carousel_func' );
}
add_shortcode( 'kleo_posts_carousel', 'kleo_posts_carousel_func' );

function kleo_posts_carousel_func( $atts = [] ) {
	ob_start();

	$template_path = locate_template( 'vc_templates/vc_carousel.php' );

	if ( $template_path != '' ) {
		include( $template_path );
	}

	return ob_get_clean();
}

if ( ! shortcode_exists( 'vc_tabs' ) ) {
	add_shortcode( 'vc_tabs', 'kleo_tabs_func' );
	add_shortcode( 'vc_tour', 'kleo_tour_func' );
	add_shortcode( 'vc_tab', 'kleo_tab_func' );
}
add_shortcode( 'kleo_tabs', 'kleo_tabs_func' );
add_shortcode( 'kleo_tab', 'kleo_tab_func' );

function kleo_tabs_func( $atts = [], $content = '' ) {

	$shortcode = 'vc_tabs';

	ob_start();

	$template_path = locate_template( 'vc_templates/vc_tabs.php' );

	if ( $template_path != '' ) {
		include( $template_path );
	}

	return ob_get_clean();
}

function kleo_tour_func( $atts = [], $content = '' ) {

	$shortcode = 'vc_tour';
	ob_start();

	$template_path = locate_template( 'vc_templates/vc_tabs.php' );

	if ( $template_path != '' ) {
		include( $template_path );
	}

	return ob_get_clean();
}

function kleo_tab_func( $atts = [], $content = '' ) {
	ob_start();

	$template_path = locate_template( 'vc_templates/vc_tab.php' );

	if ( $template_path != '' ) {
		include( $template_path );
	}

	return ob_get_clean();
}


/* BPS Compatibility */
if ( function_exists( 'bps_show_form' ) && ! shortcode_exists( 'bps_display' ) ) {
	add_shortcode( 'bps_display', function ( $atts ) {
		if ( isset( $atts['form'] ) ) {
			$id = $atts['form'];
		} else {
			$id = '';
		}

		return do_shortcode( '[bps_form id="' . $id . '"]' );
	} );
}
