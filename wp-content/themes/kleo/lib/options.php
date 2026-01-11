<?php
/**
 * Options panel Config File
 */

// This is your option name where all the Redux data is stored.
$opt_name = "kleo_" . KLEO_DOMAIN;
$args     = array();

// BEGIN Config

// Setting dev mode to true allows you to view the class settings/info in the panel.
// Default: true
$args['dev_mode'] = false;
// Set the icon for the dev mode tab.
// If $args['icon_type'] = 'image', this should be the path to the icon.
// If $args['icon_type'] = 'iconfont', this should be the icon name.
// Default: info-sign
//$args['dev_mode_icon'] = 'info-sign';

// Set the class for the dev mode tab icon.
// This is ignored unless $args['icon_type'] = 'iconfont'
// Default: null
$args['dev_mode_icon_class'] = 'icon-large';

// Set a custom option name. Don't forget to replace spaces with underscores!
$args['opt_name'] = $opt_name;

/** AJAX saving theme options. Disable it in child theme if you encounter problems
 * Code example: add_filter( 'kleo_theme_options_ajax', '__return_false' ); */

$args['ajax_save'] = apply_filters( 'kleo_theme_options_ajax', false );
// $args['use_cdn'] = false;

$args['global_variable']  = false;
$args['compiler']         = false;
$args['output']           = false;
$args['customizer']       = false;
$args['disable_tracking'] = true;
$theme                    = wp_get_theme();

$args['display_name'] = $theme->get( 'Name' );
//$args['database'] = "theme_mods_expanded";
$args['display_version'] = $theme->get( 'Version' );

// If you want to use Google Webfonts, you MUST define the api key.
$args['google_api_key']       = '';
$args['google_update_weekly'] = false;

// Set the class for the import/export tab icon.
// This is ignored unless $args['icon_type'] = 'iconfont'
// Default: null
$args['import_icon_class'] = 'icon-large';

/**
 * Set default icon class for all sections and tabs
 * @since 3.0.9
 */
$args['default_icon_class'] = 'icon-large';

// Set a custom menu icon.
//$args['menu_icon'] = '';

// Set a custom title for the options page.
// Default: Options
$args['menu_title'] = esc_html__( 'Theme options', 'kleo' );

// Set a custom page title for the options page.
// Default: Options
$args['page_title'] = esc_html__( 'Theme options', 'kleo' );

// Set a custom page slug for options page (wp-admin/themes.php?page=***).
// Default: redux_options
$args['page_slug'] = 'kleo_options';

$args['default_show'] = true;
$args['default_mark'] = '*';

// Add HTML before the form.
$demo_link          = admin_url( 'themes.php?page=sq-panel#demo-data' );
$args['intro_text'] = wp_kses_post( sprintf( __( '<p>Theme customisations are done here. Make sure to <a target="_blank" href="%s">Import Demo Content</a> first</p>', 'kleo' ), $demo_link ) );

// Set footer/credit line.
$args['footer_credit']    = ' ';
$args['page_permissions'] = 'manage_options';
$args['page_priority'] = 90;


$args['hints'] = array(
	'icon'          => 'el el-question-sign',
	'icon_position' => 'left',
	'icon_color'    => '#348DFC',
	'icon_size'     => 'normal',

	'tip_style'    => array(
		'color'   => 'light',
		'shadow'  => true,
		'rounded' => false,
		'style'   => '',
	),
	'tip_position' => array(
		'my' => 'top left',
		'at' => 'bottom left',
	),
	'tip_effect'   => array(
		'show' => array(
			'effect'   => 'slide',
			'duration' => '500',
			'event'    => 'mouseover',
		),
		'hide' => array(
			'effect'   => 'slide',
			'duration' => '500',
			'event'    => 'click mouseleave',
		),
	),
);


/* ----------------------------------------------------------------
  DEFAULT Header Colors
-----------------------------------------------------------------*/

$customizer_teaser = wp_kses_post( sprintf( __( '<br><strong>It is advised to use <a href="%s">Customizer</a> to live preview these color settings in your site.</strong>', 'kleo' ), admin_url( 'customize.php' ) ) );

//Description
$style_defaults['header']['desc'] = esc_html__( 'Style your Header section(Logo, menu) with color and background settings. This affects the Top bar section too', 'kleo' ) .
                                    '<br>' . $customizer_teaser;
//Text color
$style_defaults['header']['text'] = "#444444";
//Headings color
$style_defaults['header']['headings'] = "#111111";
//Background color
$style_defaults['header']['bg'] = "#ffffff";
//Link color
$style_defaults['header']['link'] = "#444444";
//Hover link color
$style_defaults['header']['link_hover'] = "#777777";
//Highlight color
$style_defaults['header']['high_color'] = "#ffffff";
//Highlight hover color
$style_defaults['header']['high_bg'] = "#00b9f7";
//Border color
$style_defaults['header']['border'] = "#e5e5e5";

//Alternate Background color
$style_defaults['header']['alt_bg'] = "#f7f7f7";
//Alternate Border color
$style_defaults['header']['alt_border'] = "#e5e5e5";


/* ----------------------------------------------------------------
    Main Colors
-----------------------------------------------------------------*/

//Description
$style_defaults['main']['desc'] = esc_html__( 'Style your Main site section with color and background settings. This includes the sidebar too.', 'kleo' ) .
                                  '<br>' . $customizer_teaser;
//Text color
$style_defaults['main']['text'] = "#777777";
//Headings color
$style_defaults['main']['headings'] = "#444444";
//Background color
$style_defaults['main']['bg'] = "#ffffff";
//Link color
$style_defaults['main']['link'] = "#367bb7";
//Hover link color
$style_defaults['main']['link_hover'] = "#00b9f7";
//Highlight color
$style_defaults['main']['high_color'] = "#ffffff";
//Highlight hover color
$style_defaults['main']['high_bg'] = "#00b9f7";
//Border color
$style_defaults['main']['border'] = "#e5e5e5";

//Alternate Background color
$style_defaults['main']['alt_bg'] = "#f7f7f7";
//Alternate Border color
$style_defaults['main']['alt_border'] = "#e5e5e5";


/* ----------------------------------------------------------------
    Alternate Colors
-----------------------------------------------------------------*/

//Description
$style_defaults['alternate']['desc'] = esc_html__( 'Style your Title / Breadcrumb / Extra information section with color and background settings.', 'kleo' ) .
                                       '<br>' . $customizer_teaser;
//Text color
$style_defaults['alternate']['text'] = "#777777";
//Headings color
$style_defaults['alternate']['headings'] = "#444444";
//Background color
$style_defaults['alternate']['bg'] = "#f7f7f7";
//Link color
$style_defaults['alternate']['link'] = "#367bb7";
//Hover link color
$style_defaults['alternate']['link_hover'] = "#00b9f7";
//Highlight color
$style_defaults['alternate']['high_color'] = "#ffffff";
//Highlight hover color
$style_defaults['alternate']['high_bg'] = "#00b9f7";
//Border color
$style_defaults['alternate']['border'] = "#e5e5e5";

//Alternate Background color
$style_defaults['alternate']['alt_bg'] = "#ffffff";
//Alternate Border color
$style_defaults['alternate']['alt_border'] = "#e5e5e5";


/* ----------------------------------------------------------------
    Side Menu Colors
-----------------------------------------------------------------*/

//Description
$style_defaults['side']['desc'] = esc_html__( "Style your Side menu section with color and background settings. This appears from left/right side of the site", 'kleo' ) .
                                  '<br>' . $customizer_teaser;
//Text color
$style_defaults['side']['text'] = "#777777";
//Headings color
$style_defaults['side']['headings'] = "#aaaaaa";
//Background color
$style_defaults['side']['bg'] = "#1c1c1c";
//Link color
$style_defaults['side']['link'] = "#cccccc";
//Hover link color
$style_defaults['side']['link_hover'] = "#777777";
//Highlight color
$style_defaults['side']['high_color'] = "#ffffff";
//Highlight hover color
$style_defaults['side']['high_bg'] = "#00b9f7";
//Border color
$style_defaults['side']['border'] = "#333333";

//Alternate Background color
$style_defaults['side']['alt_bg'] = "#272727";
//Alternate Border color
$style_defaults['side']['alt_border'] = "#333333";


/* ----------------------------------------------------------------
    Footer Colors
-----------------------------------------------------------------*/

//Description
$style_defaults['footer']['desc'] = esc_html__( "Style your Footer section with color and background settings. This is the section with the four columns at the bottom of your site.", 'kleo' ) .
                                    '<br>' . $customizer_teaser;
//Text color
$style_defaults['footer']['text'] = "#777777";
//Headings color
$style_defaults['footer']['headings'] = "#aaaaaa";
//Background color
$style_defaults['footer']['bg'] = "#1c1c1c";
//Link color
$style_defaults['footer']['link'] = "#cccccc";
//Hover link color
$style_defaults['footer']['link_hover'] = "#777777";
//Highlight color
$style_defaults['footer']['high_color'] = "#ffffff";
//Highlight hover color
$style_defaults['footer']['high_bg'] = "#00b9f7";
//Border color
$style_defaults['footer']['border'] = "#333333";

//Alternate Background color
$style_defaults['footer']['alt_bg'] = "#272727";
//Alternate Border color
$style_defaults['footer']['alt_border'] = "#333333";


/* ----------------------------------------------------------------
    Socket Colors
-----------------------------------------------------------------*/

//Description
$style_defaults['socket']['desc'] = esc_html__( "Style your Socket area with color and background settings. This is the last section of your site with the Credits information.", 'kleo' ) .
                                    '<br>' . $customizer_teaser;
//Text color
$style_defaults['socket']['text'] = "#515151";
//Headings color
$style_defaults['socket']['headings'] = "#cccccc";
//Background color
$style_defaults['socket']['bg'] = "#171717";
//Link color
$style_defaults['socket']['link'] = "#515151";
//Hover link color
$style_defaults['socket']['link_hover'] = "#777777";
//Highlight color
$style_defaults['socket']['high_color'] = "#ffffff";
//Highlight hover color
$style_defaults['socket']['high_bg'] = "#00b9f7";
//Border color
$style_defaults['socket']['border'] = "#333333";

//Alternate Background color
$style_defaults['socket']['alt_bg'] = "#f7f7f7";
//Alternate Border color
$style_defaults['socket']['alt_border'] = "#272727";


/**
 * Get Default section colors presets
 * @return mixed|null|void
 */
function kleo_get_color_presets() {
	$presets = array(
		'default'           => array(
			'alt'     => 'Default',
			'img'     => KLEO_LIB_URI . '/assets/images/presets/default.jpg',
			'presets' => array(
				'text'       => '#777777',
				'headings'   => '#444444',
				'bg'         => '#ffffff',
				'border'     => '#e5e5e5',
				'link'       => '#367bb7',
				'link_hover' => '#00b9f7',
				'high_color' => '#ffffff',
				'high_bg'    => '#00b9f7',
				'alt_bg'     => '#f7f7f7',
				'alt_border' => '#e5e5e5',
			)
		),
		'dark'              => array(
			'alt'     => 'Dark',
			'img'     => KLEO_LIB_URI . '/assets/images/presets/dark.jpg',
			'presets' => array(
				'text'       => '#777777',
				'headings'   => '#aaaaaa',
				'bg'         => '#1c1c1c',
				'border'     => '#333333',
				'link'       => '#cccccc',
				'link_hover' => '#777777',
				'high_color' => '#ffffff',
				'high_bg'    => '#00b9f7',
				'alt_bg'     => '#272727',
				'alt_border' => '#333333',
			)
		),
		'amber-brown'       => array(
			'alt'     => 'Amber/Brown',
			'img'     => KLEO_LIB_URI . '/assets/images/presets/amber-ac-brown.jpg',
			'presets' => array(
				'text'       => '#000000',
				'headings'   => '#000000',
				'bg'         => '#ffc107',
				'border'     => '#ffca28',
				'link'       => '#000000',
				'link_hover' => '#616161',
				'high_color' => '#ffffff',
				'high_bg'    => '#795548',
				'alt_bg'     => '#ffca28',
				'alt_border' => '#ffca28',
			)
		),
		'blue-orange'       => array(
			'alt'     => 'Blue-Gray/Deep-Orange',
			'img'     => KLEO_LIB_URI . '/assets/images/presets/blue-gray-ac-deep-orange.jpg',
			'presets' => array(
				'text'       => '#ffffff',
				'headings'   => '#ffffff',
				'bg'         => '#607d8b',
				'border'     => '#78909c',
				'link'       => '#ffffff',
				'link_hover' => '#cfd8dc',
				'high_color' => '#ffffff',
				'high_bg'    => '#ff5722',
				'alt_bg'     => '#78909c',
				'alt_border' => '#78909c',
			)
		),
		'brown-amber'       => array(
			'alt'     => 'Brown/Amber',
			'img'     => KLEO_LIB_URI . '/assets/images/presets/brown-ac-amber.jpg',
			'presets' => array(
				'text'       => '#ffffff',
				'headings'   => '#ffffff',
				'bg'         => '#795548',
				'border'     => '#8d6e63',
				'link'       => '#ffffff',
				'link_hover' => '#d7ccc8',
				'high_color' => '#ffffff',
				'high_bg'    => '#ffc107',
				'alt_bg'     => '#8d6e63',
				'alt_border' => '#8d6e63',
			)
		),
		'green-amber'       => array(
			'alt'     => 'Green/Amber',
			'img'     => KLEO_LIB_URI . '/assets/images/presets/green-ac-amber.jpg',
			'presets' => array(
				'text'       => '#ffffff',
				'headings'   => '#ffffff',
				'bg'         => '#4caf50',
				'border'     => '#66bb6a',
				'link'       => '#ffffff',
				'link_hover' => '#c8e6c9',
				'high_color' => '#ffffff',
				'high_bg'    => '#ffc107',
				'alt_bg'     => '#66bb6a',
				'alt_border' => '#66bb6a',
			)
		),
		'indigo-light-blue' => array(
			'alt'     => 'Indigo/Light Blue',
			'img'     => KLEO_LIB_URI . '/assets/images/presets/indigo-ac-light-blue.jpg',
			'presets' => array(
				'text'       => '#ffffff',
				'headings'   => '#ffffff',
				'bg'         => '#3f51b5',
				'border'     => '#5c6bc0',
				'link'       => '#ffffff',
				'link_hover' => '#c5cae9',
				'high_color' => '#ffffff',
				'high_bg'    => '#03a9f4',
				'alt_bg'     => '#5c6bc0',
				'alt_border' => '#5c6bc0',
			)
		),
		'pink-deep-orange'  => array(
			'alt'     => 'Pink/Deep Orange',
			'img'     => KLEO_LIB_URI . '/assets/images/presets/pink-ac-deep-orange.jpg',
			'presets' => array(
				'text'       => '#ffffff',
				'headings'   => '#ffffff',
				'bg'         => '#e91e63',
				'border'     => '#ec407a',
				'link'       => '#ffffff',
				'link_hover' => '#f8bbd0',
				'high_color' => '#ffffff',
				'high_bg'    => '#ff5722',
				'alt_bg'     => '#ec407a',
				'alt_border' => '#ec407a',
			)
		),
		'deep-purple-amber' => array(
			'alt'     => 'Deep Purple/Amber',
			'img'     => KLEO_LIB_URI . '/assets/images/presets/deep-purple-ac-amber.jpg',
			'presets' => array(
				'text'       => '#ffffff',
				'headings'   => '#ffffff',
				'bg'         => '#673ab7',
				'border'     => 'transparent',
				'link'       => '#ffffff',
				'link_hover' => '#d1c4e9',
				'high_color' => '#ffffff',
				'high_bg'    => '#ffc107',
				'alt_bg'     => '#7e57c2',
				'alt_border' => '#7e57c2',
			)
		),
	);

	return apply_filters( 'section_color_presets', $presets );
}

/**
 * Return the prepared array for the color presets sections
 *
 * @param string $name
 *
 * @return mixed|null|void
 */
function kleo_get_color_presets_array( $name ) {
	$color_sets = kleo_get_color_presets();

	if ( $color_sets && ! empty( $color_sets ) ) {
		foreach ( $color_sets as $key => $set ) {
			foreach ( $set['presets'] as $preset_key => $preset_value ) {
				$color_sets[ $key ]['presets'][ $name . $preset_key ] = $preset_value;
				unset( $color_sets[ $key ]['presets'][ $preset_key ] );
			}
		}
	}

	return $color_sets;
}


global $kleo_config;
$style_sets = $kleo_config['style_sets'];

$style_elements = array(
	array( 'slug' => 'desc', 'desc' => 'ss', 'type' => 'info' ),
	array( 'slug' => 'preset', 'desc' => 'ss', 'type' => 'preset' ),
	array(
		'slug'     => 'text',
		'title'    => 'Text color',
		'subtitle' => '',
		'desc'     => 'Set the text color for this section',
		'type'     => 'color'
	),
	array(
		'slug'     => 'headings',
		'title'    => 'Headings color',
		'subtitle' => '',
		'desc'     => 'Set the text color for h1,h2,h3,h4,h5,h6 elements',
		'type'     => 'color'
	),
	array(
		'slug'     => 'bg',
		'title'    => 'Background color',
		'subtitle' => '',
		'desc'     => 'Set the background color for this section',
		'type'     => 'color'
	),
	array(
		'slug'     => 'bg_image',
		'title'    => 'Background image',
		'subtitle' => '',
		'desc'     => 'Set a background image for this section',
		'type'     => 'background',
		'default'  => array()
	),
	array(
		'slug'     => 'border',
		'title'    => 'Border color',
		'subtitle' => '',
		'desc'     => 'Set the borders color for this section. It affects the border css property for elements in this section',
		'type'     => 'color',
		'default'  => ''
	),
	array(
		'slug'     => 'link',
		'title'    => 'Link color',
		'subtitle' => '',
		'desc'     => 'Select your color for anchor elements(links) for this section',
		'type'     => 'color'
	),
	array(
		'slug'     => 'link_hover',
		'title'    => 'Hover link color',
		'desc'     => 'Select your color for anchor elements(links) when hovered',
		'subtitle' => '',
		'type'     => 'color'
	),
	array(
		'slug'     => 'high_color',
		'title'    => 'Highlight color',
		'desc'     => 'Select your text color for highlight elements. It can be the highlight button style, notification bubbles, pricing table popular column or Pin shortcode color',
		'subtitle' => '',
		'type'     => 'color'
	),
	array(
		'slug'     => 'high_bg',
		'title'    => 'Highlight background color',
		'desc'     => 'Select your background color for highlight elements. It can be the highlight button style, notification bubbles, pricing table popular column or Pin shortcode color',
		'subtitle' => '',
		'type'     => 'color'
	),
	array(
		'slug'     => 'alt_bg',
		'title'    => 'Alternate background color',
		'desc'     => 'This is not very used in the design but is a supplementary color when some elements needed it. It is mostly used on elements hover like tabbed navigation, pagination or disabled inputs.',
		'subtitle' => '',
		'type'     => 'color'
	),
	array(
		'slug'     => 'alt_border',
		'title'    => 'Alternate border color',
		'desc'     => 'This is not very used in the design but is a supplementary color when some elements needed it. It is mostly used on elements hover like tabbed navigation, pagination or disabled inputs.',
		'subtitle' => '',
		'type'     => 'color'
	)
);


$sections   = array();
$sections[] = array(
	'icon'       => 'el-icon-home',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'General settings', 'kleo' ),
	'customizer' => false,
	'desc'       => wp_kses_post( __( '<p class="description">Here you will set your site-wide preferences.</p>', 'kleo' ) ),
	'fields'     => array(

		array(
			'id'       => 'maintenance_mode',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable maintenance mode.', 'kleo' ),
			'subtitle' => esc_html__( 'Site visitors will see a banner with the message you set bellow.', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'maintenance_msg',
			'type'     => 'textarea',
			'required' => array( 'maintenance_mode', 'equals', '1' ),
			'title'    => esc_html__( 'Message to show in maintenance mode', 'kleo' ),
			'subtitle' => '',
			'desc'     => '',
			'default'  => 'We are currently in maintenance mode. Please check back later.'
		),

		array(
			'id'       => 'logo',
			'type'     => 'media',
			'url'      => true,
			'readonly' => false,
			'title'    => esc_html__( 'Logo', 'kleo' ),
			'subtitle' => esc_html__( 'Upload your own logo.', 'kleo' ),
			'default'  => ''
		),
		array(
			'id'       => 'logo_retina',
			'type'     => 'media',
			'url'      => true,
			'readonly' => false,
			'title'    => esc_html__( 'Logo Retina', 'kleo' ),
			'subtitle' => esc_html__( 'This ensures logo looks nice on retina displays(optional).', 'kleo' ) .
			              '<br><strong>' . esc_html__( 'Make sure it is exact double in size(width,height) as the original logo.', 'kleo' ) . '</strong>',
		),
		array(
			'id'       => 'mobile_logo',
			'type'     => 'media',
			'url'      => true,
			'readonly' => false,
			'title'    => esc_html__( 'Mobile Logo ', 'kleo' ),
			'subtitle' => esc_html__( 'Upload Mobile logo. This is will be shown only on mobile devices.', 'kleo' ),
		),
		array(
			'id'       => 'mobile_logo_retina',
			'type'     => 'media',
			'url'      => true,
			'readonly' => false,
			'title'    => esc_html__( 'Mobile Logo Retina', 'kleo' ),
			'subtitle' => esc_html__( 'Upload mobile retina logo. This is optional and should be double in size than normal logo and it will displayed only on mobile devices.', 'kleo' ),
		),
		array(
			'id'       => 'favicon',
			'type'     => 'media',
			'url'      => true,
			'readonly' => false,
			'title'    => esc_html__( 'Favicon', 'kleo' ),
			'subtitle' => esc_html__( 'image that will be used as favicon (32px32px).', 'kleo' ),
			'default'  => array( 'url' => get_template_directory_uri() . '/assets/ico/favicon.png' )
		),
		array(
			'id'       => 'apple57',
			'type'     => 'media',
			'url'      => true,
			'readonly' => false,
			'title'    => esc_html__( 'Apple Iphone Icon', 'kleo' ),
			'subtitle' => esc_html__( 'Apple Iphone Icon (57px 57px).', 'kleo' ),
			'default'  => array( 'url' => get_template_directory_uri() . '/assets/ico/apple-touch-icon-57-precomposed.png' )
		),
		array(
			'id'       => 'apple114',
			'type'     => 'media',
			'url'      => true,
			'readonly' => false,
			'title'    => esc_html__( 'Apple Iphone Retina Icon', 'kleo' ),
			'subtitle' => esc_html__( 'Apple Iphone Retina Icon (114px 114px).', 'kleo' ),
			'default'  => array( 'url' => get_template_directory_uri() . '/assets/ico/apple-touch-icon-114-precomposed.png' )
		),
		array(
			'id'       => 'apple72',
			'type'     => 'media',
			'url'      => true,
			'readonly' => false,
			'title'    => esc_html__( 'Apple iPad Icon', 'kleo' ),
			'subtitle' => esc_html__( 'Apple Iphone Retina Icon (72px 72px).', 'kleo' ),
			'default'  => array( 'url' => get_template_directory_uri() . '/assets/ico/apple-touch-icon-72-precomposed.png' )
		),
		array(
			'id'       => 'apple144',
			'type'     => 'media',
			'url'      => true,
			'readonly' => false,
			'title'    => esc_html__( 'Apple iPad Retina Icon', 'kleo' ),
			'subtitle' => esc_html__( 'Apple iPad Retina Icon (144px 144px).', 'kleo' ),
			'default'  => array( 'url' => get_template_directory_uri() . '/assets/ico/apple-touch-icon-144-precomposed.png' )
		),

		array(
			'id'       => 'analytics',
			'type'     => 'textarea',
			'title'    => esc_html__( 'JavaScript code', 'kleo' ),
			'subtitle' => wp_kses_post( __( 'Paste your Google Analytics, other tracking code or any script you need.<br/> This will be loaded in the footer.', 'kleo' ) ),
			'desc'     => ''
		),

		array(
			'id'       => 'quick_css',
			'type'     => 'textarea',
			'title'    => esc_html__( 'Quick css', 'kleo' ),
			'subtitle' => esc_html__( 'Place you custom css here', 'kleo' ),
			'desc'     => ''
		),

		array(
			'id'       => 'socket_enable',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable socket text', 'kleo' ),
			'subtitle' => esc_html__( 'Enable text under the footer widgets area', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),

		array(
			'id'       => 'footer_text',
			'type'     => 'editor',
			'required' => array( 'socket_enable', 'equals', '1' ),
			'title'    => esc_html__( 'Footer Text', 'kleo' ),
			'subtitle' => esc_html__( 'You can use shortcodes in your footer text like: [site-url] [current-year]', 'kleo' ),
			'default'  => '<p style="text-align: center;"><strong>&copy;[current-year] KLEO Template</strong> a premium and multipurpose theme from <a href="http://www.seventhqueen.com" target="_blank" rel="nofollow">Seven<sup>th</sup> Queen</a></p>',
		),
		array(
			'id'       => 'footer_bottom',
			'type'     => 'switch',
			'title'    => esc_html__( 'Keep footer on bottom', 'kleo' ),
			'subtitle' => esc_html__( 'When page content is very small, force footer to stay on bottom', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),

	)
);

$sections[] = array(
	'icon'       => 'el-icon-website',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Layout settings', 'kleo' ),
	'customizer' => false,
	'desc'       => '<p class="description">' . esc_html__( 'Here you set options for the layout.', 'kleo' ) . '</p>',

	'fields' => array(

		array(
			'id'       => 'site_style',
			'type'     => 'button_set',
			'title'    => esc_html__( 'Site Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select between wide or boxed site layout', 'kleo' ),
			'options'  => array(
				'wide'  => 'Wide',
				'boxed' => 'Boxed'
			),
			'default'  => 'wide'
		),
		array(
			'id'       => 'boxed_size',
			'type'     => 'select',
			'required' => array( 'site_style', 'equals', 'boxed' ),
			'title'    => esc_html__( 'Site Width', 'kleo' ),
			'subtitle' => esc_html__( 'Select the width for the site when using boxed version', 'kleo' ),
			'options'  => array( '1440' => '1440px', '1200' => '1200px', '1024' => '1024px' ),
			'default'  => '1440'
		),

		//BOXED BACKGROUND
		array(
			'id'            => 'body_bg',
			'type'          => 'background',
			'tiles'         => true,
			'required'      => array( 'site_style', 'equals', 'boxed' ),
			'title'         => esc_html__( '- Background', 'kleo' ),
			'subtitle'      => esc_html__( 'Select your boxed background', 'kleo' ),
			'default'       => array(
				'background-image'  => get_template_directory_uri() . '/assets/img/bg-body.gif',
				'background-repeat' => 'repeat'
			),
			'preview'       => false,
			'preview_media' => true
		),

		array(
			'id'       => 'global_sidebar',
			'type'     => 'image_select',
			'compiler' => true,
			'title'    => esc_html__( 'Main Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select main content and sidebar alignment. Choose between 1, 2 or 3 column layout.', 'kleo' ),
			'options'  => array(
				'no'    => array( 'alt' => 'No sidebar', 'img' => SVQ_LIB_URI . '/assets/images/1col.png' ),
				'left'  => array( 'alt' => '2 Column Left', 'img' => SVQ_LIB_URI . '/assets/images/2cl.png' ),
				'right' => array( 'alt' => '2 Column Right', 'img' => SVQ_LIB_URI . '/assets/images/2cr.png' ),
				'3lr'   => array( 'alt' => '3 Column Middle', 'img' => SVQ_LIB_URI . '/assets/images/3cm.png' ),
				'3ll'   => array( 'alt' => '3 Column Left', 'img' => SVQ_LIB_URI . '/assets/images/3cl.png' ),
				'3rr'   => array( 'alt' => '3 Column Right', 'img' => SVQ_LIB_URI . '/assets/images/3cr.png' )
			),
			'default'  => 'right'
		),

		array(
			'id'       => 'main_width_2cols',
			'type'     => 'select',
			'title'    => esc_html__( 'Main content width for one sidebar templates', 'kleo' ),
			'subtitle' => esc_html__( 'Select the width for the main container used in templates with just one sidebar like "Right Sidebar"', 'kleo' ),
			'options'  => array( '6' => '50%', '7' => '58.3%', '8' => '67%', '9' => '75%' ),
			'default'  => '9'
		),
		array(
			'id'       => 'main_width_3cols',
			'type'     => 'select',
			'title'    => esc_html__( 'Main content width for two sidebars templates', 'kleo' ),
			'subtitle' => esc_html__( 'Select the width for the main container used in templates with two sidebars like "Two left sidebars"', 'kleo' ),
			'options'  => array( '4' => '33%', '6' => '50%', '8' => '67%' ),
			'default'  => '6'
		),

		array(
			'id'       => 'go_top',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Go Up button', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable the button in the right down corner that takes you to the top of the screen.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),

		array(
			'id'       => 'title_location',
			'type'     => 'button_set',
			'compiler' => true,
			'title'    => esc_html__( 'Page Title location', 'kleo' ),
			'subtitle' => esc_html__( 'Choose where to show page title. In the breadcrumb section or in the main content', 'kleo' ),
			'options'  => array(
				'breadcrumb' => esc_html__( 'Breadcrumb section', 'kleo' ),
				'main'       => esc_html__( 'Main section', 'kleo' )
			),
			'default'  => 'breadcrumb'
		),

		array(
			'id'       => 'contact_form',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Contact form', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable the contact form bottom screen', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'contact_form_builtin',
			'type'     => 'switch',
			'required' => array( 'contact_form', 'equals', '1' ),
			'title'    => esc_html__( 'Built-in Contact Form', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable the built-in contact form. You can choose to disable it if you want to use your own custom form by adding shortcodes in the Contact Form Text box below.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'contact_form_to',
			'type'     => 'text',
			'required' => array( 'contact_form', 'equals', '1' ),
			'title'    => esc_html__( 'TO email', 'kleo' ),
			'subtitle' => esc_html__( 'Enter a valid email address where the emails are sent to', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),

		array(
			'id'       => 'contact_form_title',
			'type'     => 'text',
			'required' => array( 'contact_form', 'equals', '1' ),
			'title'    => esc_html__( 'Contact form title', 'kleo' ),
			'sub_desc' => "",
			'desc'     => '',
			'default'  => 'CONTACT US'
		),
		array(
			'id'       => 'contact_form_text',
			'type'     => 'textarea',
			'required' => array( 'contact_form', 'equals', '1' ),
			'title'    => esc_html__( 'Contact form text', 'kleo' ),
			'subtitle' => esc_html__( 'Enter the content to show in the contact floating box. You can even use shortcodes to output whatever you like in it.', 'kleo' ),
			'sub_desc' => "",
			'desc'     => '',
			'default'  => "We're not around right now. But you can send us an email and we'll get back to you, asap."
		),

	)

);


$sections[] = array(
	'icon'       => 'el-icon-magic',
	'icon_class' => 'icon-large',
	'title'      => __( 'Modules and Speed', 'kleo' ),
	'customizer' => false,
	'desc'       => '<p class="description">' .
	                esc_html__( 'Choose what modules to enable on your site. Make sure to leave only used modules active to increase performance!', 'kleo' ) .
	                '</p>',
	'fields'     => array(

		array(
			'id'          => 'performance',
			'type'        => 'switch',
			'title'       => esc_html__( 'PERFORMANCE and SPEED', 'kleo' ),
			'subtitle'    => esc_html__( 'Enable theme modules system.', 'kleo' ),
			'description' => wp_kses_post( __( 'When enabled, we will generate the main app.css and plugins.css files to include only the modules and shortcodes enabled below. <br><i>Note: Make sure you have write permissions on your server under wp-content/uploads</i>', 'kleo' ) ),
			'default'     => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'perf_combine_css',
			'type'        => 'switch',
			'title'       => esc_html__( 'Combine theme CSS files', 'kleo' ),
			'subtitle'    => esc_html__( 'Combine all theme CSS files into one single file', 'kleo' ),
			'description' => esc_html__( 'It will combine bootstrap.css, magnific-popup.css, app.css and plugins.css', 'kleo' ),
			'default'     => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'perf_combine_js',
			'type'        => 'switch',
			'title'       => esc_html__( 'Combine theme JS files', 'kleo' ),
			'subtitle'    => esc_html__( 'Combine all theme JS files into one single file', 'kleo' ),
			'description' => esc_html__( 'It will combine bootstrap.js, waypoints.js, magnific-popup.js, caroufredsel.js, jquery.touchSwipe.min.js and jquery.isotope.min.js', 'kleo' ),
			'default'     => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'perf_remove_query',
			'type'        => 'switch',
			'title'       => esc_html__( 'Remove query string', 'kleo' ),
			'subtitle'    => esc_html__( 'Remove the ?v=4.1 query strings from theme css and js loaded files', 'kleo' ),
			'description' => esc_html__( 'This is sometimes required if you want to increase your Google page speed scores and for better file caching.', 'kleo' ),
			'default'     => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'section-title-modules',
			'type'     => 'section',
			'title'    => esc_html__( 'MODULES settings', 'kleo' ),
			'subtitle' => esc_html__( "Choose what modules to enable", 'kleo' ),
			'indent'   => false, // Indent all options below until the next 'section' option is set.
		),
		array(
			'id'       => 'module_testimonials',
			'type'     => 'switch',
			'title'    => esc_html__( 'Testimonials module', 'kleo' ),
			'subtitle' => esc_html__( 'Enable testimonials module.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'module_clients',
			'type'     => 'switch',
			'title'    => esc_html__( 'Clients module', 'kleo' ),
			'subtitle' => esc_html__( 'Enable clients module.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'module_portfolio',
			'type'     => 'switch',
			'title'    => esc_html__( 'Portfolio module', 'kleo' ),
			'subtitle' => esc_html__( 'Enable portfolio module.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'     => 'section-title-modules-end',
			'type'   => 'section',
			'indent' => false, // Indent all options below until the next 'section' option is set.
		),
		array(
			'id'       => 'section-title-shortcodes',
			'type'     => 'section',
			'title'    => esc_html__( 'Shortcode settings', 'kleo' ),
			'subtitle' => esc_html__( "Choose what shortcodes to enable", 'kleo' ),
			'indent'   => false, // Indent all options below until the next 'section' option is set.
		),
		array(
			'id'       => 'sh_feature_item',
			'type'     => 'switch',
			'title'    => esc_html__( 'Feature Items shortcode', 'kleo' ),
			'subtitle' => wp_kses_post( sprintf(
				__( 'Enable if you use [kleo_grid] and [kleo_feature_item] shortcodes.<br>See <a href="%s" target="_blank">shortcode demo</a>.', 'kleo' ),
				'http://seventhqueen.com/themes/kleo/feature-items/'
			) ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'sh_kleo_register',
			'type'     => 'switch',
			'title'    => esc_html__( 'Kleo_register shortcode', 'kleo' ),
			'subtitle' => wp_kses_post( sprintf( __( 'Enable if you use [kleo_register] shortcode.<br>See <a href="%s" target="_blank">shortcode demo</a>.', 'kleo' ), 'http://seventhqueen.com/themes/kleo/home-register/' ) ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'sh_news_focus',
			'type'     => 'switch',
			'title'    => esc_html__( 'News Focus shortcode', 'kleo' ),
			'subtitle' => esc_html__( 'Enable if you use [kleo_news_focus] shortcode', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'sh_news_highlight',
			'type'     => 'switch',
			'title'    => esc_html__( 'News Highlight shortcode', 'kleo' ),
			'subtitle' => esc_html__( 'Enable if you use [kleo_news_highlight] shortcode', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'sh_news_ticker',
			'type'     => 'switch',
			'title'    => esc_html__( 'News Ticker shortcode', 'kleo' ),
			'subtitle' => esc_html__( 'Enable if you use [kleo_news_ticker] shortcode', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'sh_news_puzzle',
			'type'     => 'switch',
			'title'    => esc_html__( 'News Puzzle shortcode', 'kleo' ),
			'subtitle' => esc_html__( 'Enable if you use [kleo_news_puzzle] shortcode', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'sh_poi',
			'type'     => 'switch',
			'title'    => esc_html__( 'Poi/Pin shortcode', 'kleo' ),
			'subtitle' => esc_html__( 'Enable if you use [kleo_pin] shortcode', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'sh_pricing_table',
			'type'     => 'switch',
			'title'    => esc_html__( 'Pricing table shortcode', 'kleo' ),
			'subtitle' => esc_html__( 'Enable if you use [kleo_pricing_table] shortcode', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'     => 'section-title-shortcodes-end',
			'type'   => 'section',
			'indent' => false, // Indent all options below until the next 'section' option is set.
		),
	)
);

/* Get post types for Search scope */
function kleo_search_scope_post_types() {
	$scope_atts          = array();
	$scope_atts['extra'] = array();
	if ( function_exists( 'bp_is_active' ) ) {
		$scope_atts['extra']['members'] = 'Members';
		$scope_atts['extra']['groups']  = 'Groups';
	}
	$scope_atts['extra']['post']       = 'Posts';
	$scope_atts['extra']['page']       = 'Pages';
	$scope_atts['extra']['attachment'] = 'Media';
	$scope_atts['exclude']             = array( 'kleo_clients', 'kleo-testimonials', 'topic', 'reply' );

	return kleo_post_types( $scope_atts );
}

$sections[] = array(
	'icon'       => 'el-icon-lines',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Header options', 'kleo' ),
	'customizer' => true,
	'desc'       => '<p class="description">' . esc_html__( 'Customize header appearance', 'kleo' ) . '</p>',
	'fields'     => array(
		array(
			'id'       => 'header_layout',
			'type'     => 'image_select',
			'title'    => esc_html__( 'Header Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select how you want your header format', 'kleo' ),
			'options'  => array(
				'normal'      => array(
					'alt' => 'Normal header',
					'img' => SVQ_FW_URI . '/assets/img/header_normal.png'
				),
				'extras'      => array(
					'alt' => 'Extras header',
					'img' => SVQ_FW_URI . '/assets/img/header_extras.png'
				),
				'split'       => array(
					'alt' => 'Split header',
					'img' => SVQ_FW_URI . '/assets/img/header_split.png'
				),
				'split_side'  => array(
					'alt' => 'Split header side',
					'img' => SVQ_FW_URI . '/assets/img/header_split_side.png'
				),
				'lp'          => array( 'alt' => 'LP header', 'img' => SVQ_FW_URI . '/assets/img/header_lp.png' ),
				'right_logo'  => array(
					'alt' => 'Right logo',
					'img' => SVQ_FW_URI . '/assets/img/header_logo_to_right.png'
				),
				'center_logo' => array(
					'alt' => 'Center logo',
					'img' => SVQ_FW_URI . '/assets/img/header_centered.png'
				),
				'left_logo'   => array(
					'alt' => 'Left logo and menu',
					'img' => SVQ_FW_URI . '/assets/img/header_left.png'
				),
			),
			'default'  => 'normal'
		),
		array(
			'id'       => 'header_banner',
			'type'     => 'editor',
			'required' => array( 'header_layout', 'equals', 'left_logo' ),
			'title'    => esc_html__( 'Header Banner content', 'kleo' ),
			'subtitle' => 'You can add content to the banner section in the menu. It can include shortcodes as well.<br> Examples: <br>- show main sidebar: [vc_widget_sidebar sidebar_id="sidebar-1"]',
			'default'  => 'Banner text/AD here',
		),
		array(
			'id'          => 'menu_height',
			'type'        => 'text',
			'title'       => esc_html__( 'Header Height', 'kleo' ),
			'description' => esc_html__( 'Set your header height(expressed in pixels). Example: 88', 'kleo' ),
			'subtitle'    => esc_html__( 'Applies the height to the logo container', 'kleo' ),
			'default'     => '88'
		),
		array(
			'id'          => 'menu_two_height',
			'type'        => 'text',
			'required'    => array( 'header_layout', 'equals', array( 'left_logo', 'center_logo' ) ),
			'title'       => esc_html__( 'Menu Height', 'kleo' ),
			'subtitle'    => esc_html__( 'Applies only to Left and Centered header layout', 'kleo' ),
			'description' => esc_html__( 'Set your menu height(expressed in pixels). Example: 88', 'kleo' ),
			'default'     => '88'
		),
		array(
			'id'       => 'sticky_menu',
			'type'     => 'switch',
			'title'    => esc_html__( 'Sticky Main menu', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable the sticky menu.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'resize_logo',
			'type'     => 'switch',
			'required' => array( 'sticky_menu', 'equals', '1' ),
			'title'    => esc_html__( 'Resize logo on scroll', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable logo resizing when scrolling down the page', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'menu_height_scrolled',
			'type'        => 'text',
			'required'    => array( 'resize_logo', 'equals', '1' ),
			'title'       => esc_html__( 'Header Height when scrolled', 'kleo' ),
			'subtitle'    => esc_html__( 'Set your header height(expressed in pixels). Default is half the Header height', 'kleo' ),
			'description' => esc_html__( 'Applies when the header is scrolled(expressed in pixels). Example: 44', 'kleo' ),
			'default'     => ''
		),
		array(
			'id'          => 'menu_scroll_offset',
			'type'        => 'text',
			'required'    => array( 'resize_logo', 'equals', '1' ),
			'title'       => esc_html__( 'Resize Offset', 'kleo' ),
			'subtitle'    => esc_html__( 'After how many pixels the header starts to resize.', 'kleo' ),
			'description' => esc_html__( '(expressed in pixels). Leave blank to automatically calculate when the header reaches top.', 'kleo' ),
			'default'     => ''
		),
		array(
			'id'          => 'menu_two_height_scrolled',
			'type'        => 'text',
			'required'    => array(
				array( 'resize_logo', 'equals', '1' ),
				array( 'header_layout', 'equals', array( 'left_logo', 'center_logo' ) )
			),
			'title'       => esc_html__( 'Menu Height when scrolled', 'kleo' ),
			'description' => esc_html__( 'Applies only to Left and Centered header layout when header is scrolled', 'kleo' ),
			'subtitle'    => esc_html__( 'Set your menu height(expressed in pixels). Default is half the Menu height', 'kleo' ),
			'default'     => ''
		),
		/*array(
				'id' => 'sticky_hide_logo',
				'type' => 'switch',
				'required' => array( array('sticky_menu','equals','1'), array('header_layout','equals','center_logo') ),
				'title' => __('Hide logo on scrolled menu', 'kleo'),
				'subtitle' => __('When the page is scrolled the logo will hide and show only the menu(applies to centered layout only)', 'kleo'),
				'default' => '0' // 1 = checked | 0 = unchecked
			),*/
		array(
			'id'          => 'transparent_logo',
			'type'        => 'switch',
			'required'    => array( 'sticky_menu', 'equals', '1' ),
			'title'       => esc_html__( 'Transparent Main Menu', 'kleo' ),
			'subtitle'    => esc_html__( 'Enable or disable main menu background transparency', 'kleo' ),
			'description' => esc_html__( 'DEPRECATED: This will be removed as a general option. Enable it from Page edit only.', 'kleo' ),
			'default'     => '0' // 1 = checked | 0 = unchecked
		),

		array(
			'id'       => 'menu_full_width',
			'type'     => 'switch',
			'title'    => esc_html__( 'Main Menu Full Width', 'kleo' ),
			'subtitle' => esc_html__( 'Enable full browser width menu style.', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'header_flexmenu',
			'type'     => 'switch',
			'title'    => esc_html__( 'Compact overflowing menu items ', 'kleo' ),
			'subtitle' => esc_html__( 'If the menu items are too many and would not fit then the extra elements will be added under a new sub-menu.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'show_lang',
			'type'     => 'switch',
			'title'    => esc_html__( 'Show language switch', 'kleo' ),
			'subtitle' => esc_html__( 'Works only when WPML plugin is enabled.', 'kleo' ) .
			              esc_html__( 'Language switcher will be added to the top menu. You can easily add a language menu to primary site menu from WPML - Languages - Menu language switcher', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),

		array(
			'id'       => 'header_overlay_hover',
			'type'     => 'switch',
			'title'    => esc_html__( 'Increased header opacity when hovered', 'kleo' ),
			'subtitle' => esc_html__( 'For transparent header only and when page is not scrolled. When hovering the header area it will become slightly opaque.', 'kleo' ),
			'default'  => '0', // 1 = checked | 0 = unchecked
			'hint'     => array(
				'title'   => 'Preview',
				'content' => '<img width=\'300\' src=\'http://seventhqueen.com/support/files/kleo/doc/header-hover-option.gif\'>'
			),
		),
		array(
			'id'       => 'mobile_user_avatar',
			'type'     => 'switch',
			'title'    => esc_html__( 'Show user avatar on mobile header', 'kleo' ),
			'options'  => array( '0' => 'OFF', '1' => 'ON' ),
			'subtitle' => esc_html__( 'Enable or disable the user avatar on mobile header', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'ajax_search',
			'type'     => 'button_set',
			'title'    => esc_html__( 'Ajax Search in menu', 'kleo' ),
			'options'  => array( '0' => 'OFF', '1' => 'ON', 'logged_in' => 'For logged users' ),
			'subtitle' => esc_html__( 'Enable or disable the button for search.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'menu_search_location',
			'type'     => 'select',
			'title'    => esc_html__( 'Search menu location', 'kleo' ),
			'subtitle' => esc_html__( 'In which location to show the search item', 'kleo' ),
			'options'  => array(
				'primary'   => esc_html__( 'Primary', 'kleo' ),
				'secondary' => esc_html__( 'Secondary', 'kleo' ),

			),
			'default'  => 'primary',
			'required' => array( 'ajax_search', 'equals', '1' ),
		),
		array(
			'id'       => 'search_context',
			'type'     => 'select',
			'multi'    => true,
			'required' => array( 'ajax_search', 'equals', '1' ),
			'title'    => esc_html__( 'Search context', 'kleo' ),
			'subtitle' => 'Leave unchecked to search in all content, otherwise check the content you want to appear in the search',
			//'options' => kleo_post_types( $scope_atts ),
			'data'     => 'callback',
			'args'     => 'kleo_search_scope_post_types',
			'default'  => ''
		),
		array(
			'id'          => 'header_custom_search',
			'type'        => 'switch',
			'title'       => esc_html__( 'Replace search form content', 'kleo' ),
			'subtitle'    => esc_html__( 'Advanced users only. You could break HTML', 'kleo' ),
			'description' => esc_html__( 'You can put Google Custom Search box.', 'kleo' ),
			'default'     => '0', // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'header_search_form',
			'type'        => 'textarea',
			'title'       => esc_html__( 'Custom Search form HTML', 'kleo' ),
			'subtitle'    => esc_html__( 'Be careful when modifying this', 'kleo' ),
			'description' => wp_kses_post( sprintf( __( 'See tutorial for setting up Google Custom Search box <a target="_blank" href="%s">here</a>', 'kleo' ), 'https://seventhqueen.com/support/kleo/article/add-google-custom-search-box-replace-wordpress-search-header' ) ),
			'default'     => '',
			'required'    => array( 'header_custom_search', 'equals', '1' ),
		),

	)
);


$sections[] = array(
	'icon'            => 'el-icon-chevron-up',
	'icon_class'      => 'icon-large',
	'title'           => esc_html__( 'Header Fonts', 'kleo' ),
	'desc'            => '',
	'fields'          => array(
		array(
			'id'             => 'font_header',
			'type'           => 'typography',
			'title'          => esc_html__( 'Font settings', 'kleo' ),
			//'compiler'=>true, // Use if you want to hook in your own CSS compiler
			'google'         => true, // Disable google fonts. Won't work if you haven't defined your google api key
			'font-backup'    => true, // Select a backup non-google font in addition to a google font
			'font-style'     => true, // Includes font-style and weight. Can use font-style or font-weight to declare
			//'subsets'=>false, // Only appears if google is true and subsets not set to false
			'font-size'      => false,
			'line-height'    => false,
			//'word-spacing'=>true, // Defaults to false
			'letter-spacing' => true, // Defaults to false
			'color'          => false,
			//'preview'=>false, // Disable the previewer
			'all_styles'     => true, // Enable all Google Font style/weight variations to be added to the page
			'output'         => array(), // An array of CSS selectors to apply this font style to dynamically
			'units'          => 'px', // Defaults to px
			'subtitle'       => "",
			/*'default'     => array(
				'font-weight' => '',
				'font-family' => '',
				'google'      => 'true',
				'font-size'   => '',
				'line-height' => ''
			),*/
		),
		array(
			'id'       => 'menu_size',
			'type'     => 'text',
			'title'    => esc_html__( 'Main Menu Font size', 'kleo' ),
			'subtitle' => esc_html__( 'Font size in pixels. Default: 14', 'kleo' ),
			'default'  => ''
		),
	),
	'customizer'      => false,
	'kleo_customizer' => false,
	'subsection'      => true
);


$sections[] = array(
	'icon'            => 'el-icon-chevron-up',
	'icon_class'      => 'icon-large',
	'title'           => esc_html__( 'Top bar', 'kleo' ),
	'desc'            => '',
	'fields'          => array(
		array(
			'id'       => 'show_top_bar',
			'type'     => 'switch',
			'title'    => esc_html__( 'Display top bar', 'kleo' ),
			'subtitle' => wp_kses_post( __( 'Enable or disable the top bar.<br> See Social icons tab to enable the social icons inside it.<br> Set a Top menu from  Appearance - Menus ', 'kleo' ) ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'top_bar_darker',
			'type'     => 'switch',
			'title'    => esc_html__( 'Top bar - Darker background', 'kleo' ),
			'required' => array( 'show_top_bar', 'equals', '1' ),
			'subtitle' => esc_html__( 'Make the Top bar background a little darker instead of same header color. This is based on Styling options - Header.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'top_bar_flex',
			'type'     => 'switch',
			'title'    => esc_html__( 'Responsive Menu', 'kleo' ),
			'required' => array( 'show_top_bar', 'equals', '1' ),
			'subtitle' => esc_html__( 'Enable responsive menu that automatically collapses items into a "more" drop-down when they won\'t fit in one line ', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = uncheckd
		),
	),
	'customizer'      => false,
	'kleo_customizer' => false,
	'subsection'      => true
);

$sections[] = array(
	'icon'       => 'el-icon-bookmark-empty',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Title/Breadcrumb', 'kleo' ),
	'desc'       => wp_kses_post( __( 'Settings for the title/breadcrumb section that comes just after the menu.<br>To <strong>Style this section</strong> go to Styling options - Title/Breadcrumb', 'kleo' ) ),
	'fields'     => array(
		array(
			'id'       => 'title_status',
			'type'     => 'switch',
			'title'    => esc_html__( 'Show Page Title', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable Page title display.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'breadcrumb_status',
			'type'     => 'switch',
			'title'    => esc_html__( 'Show breadcrumb', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable the site path under the page title.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'title_info',
			'type'     => 'textarea',
			'title'    => esc_html__( 'Main menu info', 'kleo' ),
			'sub_desc' => esc_html__( 'This text displays next to the main menu. To disable it just delete the whole text.', 'kleo' ),
			'desc'     => '',
			'default'  => '<em class="muted">feel free to call us</em> &nbsp;&nbsp;<i class="icon-phone"></i> +91.33.26789234 &nbsp;&nbsp;&nbsp;&nbsp;<i class="icon-mail-alt"></i> youremail@yourdomain.com'
		),
		array(
			'id'       => 'title_layout',
			'type'     => 'image_select',
			'title'    => esc_html__( 'Title/Breadcrumb layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select the appearance for the title and breadcrumb section', 'kleo' ),
			'options'  => array(
				'normal'           => array( 'alt' => 'Normal', 'img' => SVQ_FW_URI . '/assets/img/normal-title.png' ),
				'right_breadcrumb' => array(
					'alt' => 'Right Breadcrumb',
					'img' => SVQ_FW_URI . '/assets/img/right-breadcrumb.png'
				),
				'center'           => array(
					'alt' => 'Centered',
					'img' => SVQ_FW_URI . '/assets/img/center-title.png'
				),
			),
			'default'  => 'normal'
		),

		array(
			'id'            => 'title_padding',
			'type'          => 'spacing',
			'output'        => array( '.main-title' ),
			// An array of CSS selectors to apply this font style to
			'mode'          => 'padding',
			// absolute, padding, margin, defaults to padding
			//'all' => true,
			// Have one field that applies to all
			//'top' => false, // Disable the top
			'right'         => false, // Disable the right
			//'bottom' => false, // Disable the bottom
			'left'          => false, // Disable the left
			'units'         => 'px', // You can specify a unit value. Possible: px, em, %
			//'units_extended'=> 'true', // Allow users to select any type of unit
			'display_units' => 'true', // Set to false to hide the units if the units are specified
			'title'         => esc_html__( 'Padding', 'kleo' ),
			'subtitle'      => esc_html__( 'Set a top/bottom padding for the title section', 'kleo' ),
			'desc'          => esc_html__( 'Defined in px. Enter values without px', 'kleo' ),
			'default'       => array(
				'padding-top'    => '10px',
				'padding-bottom' => '10px'
			)
		),
	)
);


$font_fields   = array();
$font_sections = array(
	'h1'   => array( 'size' => '36', 'height' => '48', 'font' => 'Roboto Condensed', 'weight' => '300' ),
	'h2'   => array( 'size' => '28', 'height' => '36', 'font' => 'Roboto Condensed', 'weight' => '300' ),
	'h3'   => array( 'size' => '22', 'height' => '28', 'font' => 'Roboto Condensed', 'weight' => '300' ),
	'h4'   => array( 'size' => '18', 'height' => '28', 'font' => 'Roboto Condensed', 'weight' => '300' ),
	'h5'   => array( 'size' => '17', 'height' => '27', 'font' => 'Roboto Condensed', 'weight' => '300' ),
	'h6'   => array( 'size' => '16', 'height' => '24', 'font' => 'Roboto Condensed', 'weight' => '300' ),
	'body' => array( 'size' => '13', 'height' => '20', 'font' => 'Open Sans', 'weight' => '400' )
);

foreach ( $font_sections as $k => $font ) {
	$font_fields[] = array(
		'id'          => 'font_' . $k,
		'type'        => 'typography',
		'title'       => ucwords( $k ),
		//'compiler'=>true, // Use if you want to hook in your own CSS compiler
		'google'      => true, // Disable google fonts. Won't work if you haven't defined your google api key
		'font-backup' => true, // Select a backup non-google font in addition to a google font
		'font-style'  => true, // Includes font-style and weight. Can use font-style or font-weight to declare
		//'subsets'=> false, // Only appears if google is true and subsets not set to false
		//'font-size'=>false,
		//'line-height'=>false,
		//'word-spacing'=>true, // Defaults to false
		//'letter-spacing'=>true, // Defaults to false
		'color'       => false,
		//'preview'=>false, // Disable the previewer
		'all_styles'  => true, // Enable all Google Font style/weight variations to be added to the page
		'output'      => array(), // An array of CSS selectors to apply this font style to dynamically
		'units'       => 'px', // Defaults to px
		'subtitle'    => "",
		'default'     => array(
			'font-weight' => $font['weight'],
			'font-family' => $font['font'],
			'google'      => 'true',
			'font-size'   => $font['size'] . 'px',
			'line-height' => $font['height'] . 'px'
		),
	);
}

$style_fields   = array();
$style_sections = array();

$sections[] = $style_sections[] = array(
	'icon'            => 'el-icon-adjust',
	'icon_class'      => 'icon-large',
	'title'           => esc_html__( 'Styling options', 'kleo' ),
	'customizer'      => false,
	'kleo_customizer' => true,
	'desc'            => '',
	'fields'          => array(
		array(
			'id'     => 'styling-info',
			'type'   => 'info',
			'notice' => true,
			'style'  => 'success',
			'desc'   => wp_kses_post( __( 'Style colors and backgrounds for each section of your site.<br>Start by selecting a sub-menu from the left.', 'kleo' ) ) .
			            "<br>" . $customizer_teaser,
		),
	)
);


/* Generate the Styling options sections */
foreach ( $style_sets as $set ) {
	$style_fields = array();

	foreach ( $style_elements as $elem ) {
		if ( $elem['type'] == 'color' ) {

			/*if ( $set == 'header' && $elem['slug'] == 'headings' ) {
                continue;
            }*/

			$style_fields[] = array(
				'id'       => 'st__' . $set . '__' . $elem['slug'],
				'type'     => $elem['type'],
				'title'    => $elem['title'],
				'subtitle' => $elem['subtitle'],
				'desc'     => $elem['desc'],
				'default'  => $style_defaults[ $set ][ $elem['slug'] ]
			);
		} elseif ( $elem['type'] == 'background' ) {
			$style_fields[] = array(
				'id'               => 'st__' . $set . '__' . $elem['slug'],
				'type'             => $elem['type'],
				'title'            => $elem['title'],
				'subtitle'         => $elem['subtitle'],
				'desc'             => $elem['desc'],
				'default'          => $elem['default'],
				'background-color' => false,
				'preview'          => false,
				'preview_media'    => true
			);
		} elseif ( $elem['type'] == 'info' ) {
			$style_fields[] = array(
				'id'     => 'st__' . $set . '__' . $elem['slug'],
				'type'   => 'info',
				'notice' => true,
				'style'  => 'success',
				'desc'   => $style_defaults[ $set ]['desc']
			);
		} elseif ( $elem['type'] == 'preset' ) {
			$style_fields[] = array(
				'id'       => 'st__' . $set . '__' . $elem['slug'],
				'type'     => 'image_select',
				'title'    => 'Color presets',
				'subtitle' => 'Change section colors based on these presets',
				'presets'  => true,
				'default'  => 0,
				'options'  => kleo_get_color_presets_array( 'st__' . $set . '__' )
			);
		}
	}

	$sections[] = $style_sections[] = array(
		'icon'            => 'el-icon-adjust',
		'icon_class'      => 'icon-large',
		'title'           => ucfirst( ( $set == 'alternate' ? 'Title section(Alternate)' : $set ) ),
		'desc'            => '',
		'fields'          => $style_fields,
		'customizer'      => false,
		'kleo_customizer' => true,
		'subsection'      => true
	);
}


$sections[] = array(
	'icon'       => 'el-icon-fontsize',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Fonts', 'kleo' ),
	'desc'       => '<p class="description">' . esc_html__( 'Customize font options for body text and headings.', 'kleo' ) . '</p>',
	'fields'     => $font_fields
);


$sections[] = array(
	'icon'       => 'el-icon-chevron-right',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Side Menu', 'kleo' ),
	'customizer' => false,
	'desc'       => '<p class="description">' . esc_html__( 'Here you can enable side menu.', 'kleo' ) . '</p>',
	//'subsection' => true,
	'fields'     => array(
		array(
			'id'          => 'side_menu',
			'type'        => 'switch',
			'title'       => esc_html__( 'Enable side menu on your site', 'kleo' ),
			'subtitle'    => esc_html__( 'This will load the side menu functionality', 'kleo' ),
			'description' => 'Make sure to assign a menu from Appearance - Menus - Manage Locations',
			'default'     => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'side_menu_button',
			'type'        => 'switch',
			'required'    => array( 'side_menu', 'equals', '1' ),
			'title'       => esc_html__( 'Add toggle button on your main menu', 'kleo' ),
			'subtitle'    => esc_html__( 'It will appear at the end of your primary menu', 'kleo' ),
			'description' => 'Another way to toggle it is by adding this class to any element on the page: open-sidebar<br> You can even <strong>add this class to a menu item</strong> by putting in the Title Attribute input: class=open-sidebar ',
			'default'     => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'side_menu_mobile',
			'type'     => 'switch',
			'required' => array( 'side_menu', 'equals', '1' ),
			'title'    => esc_html__( 'Replace the default Mobile Menu with the Side Menu', 'kleo' ),
			'subtitle' => esc_html__( 'When you click the menu icon on mobile, the side menu will open instead of the normal menu', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'side_menu_position',
			'type'     => 'select',
			'required' => array( 'side_menu', 'equals', '1' ),
			'title'    => esc_html__( 'Side menu position', 'kleo' ),
			'subtitle' => esc_html__( 'Where the side menu will appear', 'kleo' ),
			'options'  => array( 'left' => 'Left', 'right' => 'Right' ),
			'default'  => 'left'
		),
		array(
			'id'       => 'side_menu_type',
			'type'     => 'select',
			'required' => array( 'side_menu', 'equals', '1' ),
			'title'    => esc_html__( 'Side menu type', 'kleo' ),
			'subtitle' => esc_html__( 'Type of side menu appearance', 'kleo' ),
			'options'  => array( 'default' => 'Default', 'overlay' => 'Overlay' ),
			'default'  => 'default'
		),
		array(
			'id'       => 'side_menu_before',
			'type'     => 'editor',
			'required' => array( 'side_menu', 'equals', '1' ),
			'title'    => esc_html__( 'Before Menu text', 'kleo' ),
			'subtitle' => 'You can add a text to show before the menu. It can include shortcodes as well.<br> Examples: <br>- show main sidebar: [vc_widget_sidebar sidebar_id="sidebar-1"]<br> - show social icons: [kleo_social_icons]',
			'default'  => '',
		),
		array(
			'id'       => 'side_menu_after',
			'type'     => 'editor',
			'required' => array( 'side_menu', 'equals', '1' ),
			'title'    => esc_html__( 'After Menu text', 'kleo' ),
			'subtitle' => 'You can add a text to show after the menu. It can include shortcodes as well.<br> Examples: <br>- show main sidebar: [vc_widget_sidebar sidebar_id="sidebar-1"]<br> - show social icons: [kleo_social_icons]',
			'default'  => '[kleo_social_icons]',
		),

	)
);

$sections[] = array(
	'icon'       => 'el-icon-pencil-alt',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Blog', 'kleo' ),
	'customizer' => false,
	'desc'       => '<p class="description">' . esc_html__( 'Settings related to blog', 'kleo' ) . '</p>',
	'fields'     => array(

		array(
			'id'       => 'blog_layout',
			'type'     => 'image_select',
			'title'    => esc_html__( 'Blog Page Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select your blog layout. Choose between 1, 2 or 3 column layout.', 'kleo' ),
			'options'  => array(
				'no'    => array( 'alt' => 'No sidebar', 'img' => SVQ_LIB_URI . '/assets/images/1col.png' ),
				'left'  => array( 'alt' => '2 Column Left', 'img' => SVQ_LIB_URI . '/assets/images/2cl.png' ),
				'right' => array( 'alt' => '2 Column Right', 'img' => SVQ_LIB_URI . '/assets/images/2cr.png' ),
				'3lr'   => array( 'alt' => '3 Column Middle', 'img' => SVQ_LIB_URI . '/assets/images/3cm.png' ),
				'3ll'   => array( 'alt' => '3 Column Left', 'img' => SVQ_LIB_URI . '/assets/images/3cl.png' ),
				'3rr'   => array( 'alt' => '3 Column Right', 'img' => SVQ_LIB_URI . '/assets/images/3cr.png' )
			),
			'default'  => 'right'
		),
		array(
			'id'       => 'cat_layout',
			'type'     => 'image_select',
			'title'    => esc_html__( 'Categories/Archives Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select your blog categories layout. Choose between 1, 2 or 3 column layout.', 'kleo' ),
			'options'  => array(
				'no'    => array( 'alt' => 'No sidebar', 'img' => SVQ_LIB_URI . '/assets/images/1col.png' ),
				'left'  => array( 'alt' => '2 Column Left', 'img' => SVQ_LIB_URI . '/assets/images/2cl.png' ),
				'right' => array( 'alt' => '2 Column Right', 'img' => SVQ_LIB_URI . '/assets/images/2cr.png' ),
				'3lr'   => array( 'alt' => '3 Column Middle', 'img' => SVQ_LIB_URI . '/assets/images/3cm.png' ),
				'3ll'   => array( 'alt' => '3 Column Left', 'img' => SVQ_LIB_URI . '/assets/images/3cl.png' ),
				'3rr'   => array( 'alt' => '3 Column Right', 'img' => SVQ_LIB_URI . '/assets/images/3cr.png' )
			),
			'default'  => 'right'
		),
		array(
			'id'       => 'blog_post_layout',
			'type'     => 'select',
			'compiler' => true,
			'title'    => esc_html__( 'Single Post page Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select your Blog post page layout. Choose between 1, 2 or 3 column layout.', 'kleo' ),
			'options'  => array(
				'default' => 'Default as in Layout Settings',
				'no'      => 'Full width',
				'left'    => 'Left Sidebar',
				'right'   => 'Right Sidebar',
				'3lr'     => '3 Column, Left and Right Sidebars',
				'3ll'     => '3 Column, 2 Left sidebars',
				'3rr'     => '3 Column, 2 Right sidebars'
			),
			'default'  => 'default'
		),

		array(
			'id'       => 'blog_type',
			'type'     => 'select',
			'title'    => esc_html__( 'Display type', 'kleo' ),
			'subtitle' => esc_html__( 'How your blog posts will display', 'kleo' ),
			'options'  => $kleo_config['blog_layouts'],
			'default'  => 'masonry'
		),

		array(
			'id'       => 'blog_columns',
			'type'     => 'select',
			'required' => array( 'blog_type', 'equals', 'masonry' ),
			'title'    => esc_html__( 'Posts per row', 'kleo' ),
			'subtitle' => esc_html__( 'How many columns to have in the grid', 'kleo' ),
			'options'  => array( '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6' ),
			'default'  => '3'
		),
		array(
			'id'       => 'blog_switch_layout',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Layout Switcher Icons', 'kleo' ),
			'subtitle' => esc_html__( 'Let your visitors switch the layout of the Blog page', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_enabled_layouts',
			'type'     => 'select',
			'multi'    => true,
			'title'    => esc_html__( 'Enabled Layouts', 'kleo' ),
			'required' => array( 'blog_switch_layout', 'equals', '1' ),
			'subtitle' => 'What layouts are available for the user to switch.',
			'options'  => $kleo_config['blog_layouts'],
			'default'  => array_values( array_flip( $kleo_config['blog_layouts'] ) )
		),
		array(
			'id'       => 'featured_content_layout',
			'type'     => 'select',
			'title'    => esc_html__( 'Featured content display type', 'kleo' ),
			'subtitle' => 'Featured articles can be displayed on your Blog page just above regular articles. Just add them a tag named Featured. Change default tag name <a href="' . admin_url( 'customize.php' ) . '">here</a><br>This setting affects the way they are displayed',
			'options'  => array( 'carousel' => 'Carousel', 'grid' => 'Grid' ),
			'default'  => 'carousel'
		),
		array(
			'id'       => 'featured_grid_columns',
			'type'     => 'select',
			'title'    => esc_html__( 'Featured articles per row', 'kleo' ),
			'required' => array( 'featured_content_layout', 'equals', 'grid' ),
			'subtitle' => 'Number of articles to show per row.',
			'options'  => array( '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6' ),
			'default'  => '3'
		),
		array(
			'id'    => 'blog_exclude_cat',
			'type'  => 'select',
			'multi' => true,
			'title' => esc_html__( 'Exclude categories from main blog', 'kleo' ),
			'desc'  => esc_html__( 'Choose the categories to hide posts from in the main blog.', 'kleo' ),
			'data'  => 'categories',
		),
		array(
			'id'          => 'blog_tag_cloud',
			'type'        => 'switch',
			'title'       => esc_html__( 'Tag Cloud Widget dynamic font size ', 'kleo' ),
			'description' => esc_html__( 'When enabled, tags will appear in different sizes based on number of posts.', 'kleo' ),
			'default'     => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'blog_custom_img',
			'type'        => 'switch',
			'title'       => esc_html__( 'Enable Custom Image sizes', 'kleo' ),
			'subtitle'    => wp_kses_post( __( '<strong>It is recommended to enable it for better performance.</strong><br> Enabling it will use default WP engine and will allow plugins like WP Smush to optimize images.', 'kleo' ) ),
			'description' => esc_html__( 'When enabled, you can set different image sizes for each blog listing type.', 'kleo' ),
			'default'     => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'section-title-blog-img',
			'type'     => 'section',
			'title'    => esc_html__( 'Image size settings', 'kleo' ),
			'subtitle' => 'Customise image sizes for the blog. After changing image sizes you need to regenerate thumbnails using https://wordpress.org/plugins/regenerate-thumbnails',
			'indent'   => true, // Indent all options below until the next 'section' option is set.
			'required' => array( 'blog_custom_img', 'equals', '1' ),

		),
		array(
			'id'       => 'blog_img_single_width',
			'type'     => 'text',
			'title'    => esc_html__( 'Single post & Standard Blog - Image Width', 'kleo' ),
			'subtitle' => esc_html__( 'Enter the width of the image in pixels, default: 480', 'kleo' ),
			'default'  => '1038'
		),
		array(
			'id'       => 'blog_img_single_height',
			'type'     => 'text',
			'title'    => esc_html__( 'Single post & Standard Blog -  Image Height', 'kleo' ),
			'subtitle' => esc_html__( 'Enter the height of the image in pixels. Unlimited height is 9999. Default: 9999', 'kleo' ),
			'default'  => '9999',
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),
		array(
			'id'       => 'blog_img_single_crop',
			'type'     => 'switch',
			'title'    => esc_html__( 'Crop instead of resize', 'kleo' ),
			'subtitle' => esc_html__( 'Image will be cropped to exact dimension', 'kleo' ),
			'default'  => '0', // 1 = checked | 0 = unchecked
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),
		array(
			'id'       => 'blog_img_standard_width',
			'type'     => 'text',
			'title'    => esc_html__( 'Gallery format & Carousel - Image Width', 'kleo' ),
			'subtitle' => esc_html__( 'Applies for Gallery format and Carousel. Enter the width of the image in pixels, default: 480', 'kleo' ),
			'default'  => $kleo_config['post_gallery_img_width'],
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),
		array(
			'id'       => 'blog_img_standard_height',
			'type'     => 'text',
			'title'    => esc_html__( 'Gallery format & Carousel - Image Height', 'kleo' ),
			'subtitle' => esc_html__( 'Applies for Gallery format and Carousel. Enter the height of the image in pixels. Unlimited height is 9999. Default: 270', 'kleo' ),
			'default'  => $kleo_config['post_gallery_img_height'],
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),
		array(
			'id'       => 'blog_img_standard_crop',
			'type'     => 'switch',
			'title'    => esc_html__( 'Crop instead of resize', 'kleo' ),
			'subtitle' => esc_html__( 'Image will be cropped to exact dimension', 'kleo' ),
			'default'  => '1', // 1 = checked | 0 = unchecked
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),
		array(
			'id'       => 'blog_img_grid_width',
			'type'     => 'text',
			'title'    => esc_html__( 'Masonry Grid Image Width', 'kleo' ),
			'subtitle' => esc_html__( 'Enter the width of the image in pixels, default: 480', 'kleo' ),
			'default'  => $kleo_config['post_gallery_img_width'],
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),
		array(
			'id'       => 'blog_img_grid_height',
			'type'     => 'text',
			'title'    => esc_html__( 'Masonry Grid Image Height', 'kleo' ),
			'subtitle' => esc_html__( 'Enter the height of the image in pixels. Unlimited height is 9999. Default: 9999', 'kleo' ),
			'default'  => '9999',
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),
		array(
			'id'       => 'blog_img_grid_crop',
			'type'     => 'switch',
			'title'    => esc_html__( 'Crop instead of resize', 'kleo' ),
			'subtitle' => esc_html__( 'Image will be cropped to exact dimension', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_img_small_width',
			'type'     => 'text',
			'title'    => esc_html__( 'Small Left Thumbnail Blog - Image Width', 'kleo' ),
			'subtitle' => esc_html__( 'Enter the width of the image in pixels, default: 480', 'kleo' ),
			'default'  => $kleo_config['post_gallery_img_width'],
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),
		array(
			'id'       => 'blog_img_small_height',
			'type'     => 'text',
			'title'    => esc_html__( 'Small Left Thumbnail Blog - Image Height', 'kleo' ),
			'subtitle' => esc_html__( 'Enter the height of the image in pixels. Unlimited height is 9999. Default: 270', 'kleo' ),
			'default'  => $kleo_config['post_gallery_img_height'],
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),
		array(
			'id'       => 'blog_img_small_crop',
			'type'     => 'switch',
			'title'    => esc_html__( 'Crop instead of resize', 'kleo' ),
			'subtitle' => esc_html__( 'Image will be cropped to exact dimension', 'kleo' ),
			'default'  => '1', // 1 = checked | 0 = unchecked
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),
		array(
			'id'       => 'section-title-blog-img-end',
			'type'     => 'section',
			'indent'   => false, // Indent all options below until the next 'section' option is set.
			'required' => array( 'blog_custom_img', 'equals', '1' ),
		),

		array(
			'id'       => 'section-title-blog-meta',
			'type'     => 'section',
			'title'    => esc_html__( 'Meta settings', 'kleo' ),
			'subtitle' => esc_html__( 'Enable/disable the display of post meta like author, categories, date', 'kleo' ),
			'indent'   => true, // Indent all options below until the next 'section' option is set.
		),
		array(
			'id'       => 'blog_archive_meta',
			'type'     => 'switch',
			'title'    => esc_html__( 'Display post meta on archive listing', 'kleo' ),
			'subtitle' => esc_html__( 'If you want to show meta info in Blog posts listing like Author, Date, Category', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_meta_status',
			'type'     => 'switch',
			'title'    => esc_html__( 'Display post meta in Single post page', 'kleo' ),
			'subtitle' => esc_html__( 'If enabled it will show post info like author, categories', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_meta_elements',
			'type'     => 'select',
			'multi'    => true,
			'title'    => esc_html__( 'Display Meta Fields', 'kleo' ),
			'subtitle' => esc_html__( 'What fields do you want displayed? Link fields will only work if BuddyPress is active.', 'kleo' ),
			'options'  => $kleo_config['blog_meta_elements'],
			'default'  => $kleo_config['blog_meta_defaults']
		),
		array(
			'id'       => 'blog_meta_sep',
			'type'     => 'text',
			'title'    => esc_html__( 'Post meta separator', 'kleo' ),
			'subtitle' => esc_html__( 'Customize your post meta separator.', 'kleo' ),
			'default'  => ', '
		),
		array(
			'id'       => 'blog_standard_meta',
			'type'     => 'select',
			'title'    => esc_html__( 'Post meta style for standard layout', 'kleo' ),
			'subtitle' => esc_html__( 'How the display meta, left side or under the title. Applies to standard layout only.', 'kleo' ),
			'options'  => array( 'left' => 'Left side', 'inline' => 'Inline under title' ),
			'default'  => 'left'
		),
		array(
			'id'       => 'blog_single_meta',
			'type'     => 'select',
			'title'    => esc_html__( 'Post meta style for single post page', 'kleo' ),
			'subtitle' => esc_html__( 'How the display meta, left side or before content.', 'kleo' ),
			'options'  => array( 'left' => 'Left side', 'inline' => 'Inline before content' ),
			'default'  => 'left'
		),
		array(
			'id'       => 'blog_tags_footer',
			'type'     => 'switch',
			'title'    => esc_html__( 'Display post tags after the content', 'kleo' ),
			'subtitle' => esc_html__( 'If enabled it will show post tags after the post content in single pages. Make sure to remove the Tags options above in Display meta fields.', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'     => 'section-title-blog-meta-end',
			'type'   => 'section',
			'indent' => false, // Indent all options below until the next 'section' option is set.
		),
		array(
			'id'       => 'blog_media_status',
			'type'     => 'switch',
			'title'    => esc_html__( 'Display media on post page', 'kleo' ),
			'subtitle' => esc_html__( 'If you want to show image/gallery/video/audio before the post on single page', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_get_image',
			'type'     => 'switch',
			'title'    => esc_html__( 'Get Featured image from content', 'kleo' ),
			'subtitle' => esc_html__( 'If you have not set a Featured image allow the system to show the first image from post content on archive pages', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_default_image',
			'type'     => 'media',
			'url'      => true,
			'readonly' => false,
			'title'    => esc_html__( 'Default Featured Image Placeholder', 'kleo' ),
			'subtitle' => esc_html__( 'If your post does not have a Featured image set then show a default image on archive pages.', 'kleo' ),
		),
		array(
			'id'       => 'continue_reading_blog_text',
			'type'     => 'text',
			'title'    => esc_html__( 'Read more text from blog/archives ', 'kleo' ),
			'subtitle' => esc_html__( 'Add your desired text for read more button from blog/archives', 'kleo' ),
			'default'  => 'Continue reading'
		),
		array(
			'id'       => 'post_navigation',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable post navigation', 'kleo' ),
			'subtitle' => esc_html__( 'Display previous and next post navigation', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'related_posts',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable related posts', 'kleo' ),
			'subtitle' => esc_html__( 'Display related posts in single blog entry', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'related_custom_posts',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable custom posts related', 'kleo' ),
			'subtitle' => esc_html__( 'Display related posts in custom post type single entry', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		)
	)
);

$sections[] = array(
	'icon'       => 'el-icon-file-alt',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Pages', 'kleo' ),
	'desc'       => '<p class="description">' . esc_html__( 'Settings related to Pages', 'kleo' ) . '</p>',
	'fields'     => array(
		array(
			'id'       => 'page_media',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Media on single page', 'kleo' ),
			'subtitle' => esc_html__( 'Video, Sound or Image Thumbnail will appear before post content', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'page_comments',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Page comments', 'kleo' ),
			'subtitle' => esc_html__( 'If you enable this make sure you have checked also Settings - Discussion - Allow people to post comments on new articles ', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
	)
);


/* Get post types for Search scope */
function kleo_share_post_types() {
	$scope_atts                  = array();
	$scope_atts['extra']         = array();
	$scope_atts['extra']['post'] = 'Posts';
	$scope_atts['extra']['page'] = 'Pages';

	return kleo_post_types( $scope_atts );
}


$sections[] = array(
	'icon'       => 'el-icon-share',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Social Share', 'kleo' ),
	'customizer' => false,
	'desc'       => '<p class="description">' .
	                wp_kses_post( __( 'Settings related to Social sharing that appear after post/page content<br>NOTE: Woocommerce Wishlist share options are configured from plugin page: WP admin - Woocommerce - Settings - Wishlist', 'kleo' ) ) . '</p>',
	'fields'     => array(
		array(
			'id'       => 'blog_social_share',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Social share', 'kleo' ),
			'subtitle' => esc_html__( 'Display social share icons after single blog entry.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_share_types',
			'type'     => 'select',
			'multi'    => true,
			'required' => array( 'blog_social_share', 'equals', '1' ),
			'title'    => esc_html__( 'Social share Post types', 'kleo' ),
			'subtitle' => esc_html__( 'Select the post types to enable social sharing for.', 'kleo' ),
			//'options' => kleo_post_types( $scope_atts ),
			'data'     => 'callback',
			'args'     => 'kleo_share_post_types',
			'default'  => array( 'post', 'product' )
		),
		array(
			'id'       => 'blog_share_exclude',
			'type'     => 'text',
			'required' => array( 'blog_social_share', 'equals', '1' ),
			'title'    => esc_html__( 'Exclude social share by Post IDs', 'kleo' ),
			'subtitle' => esc_html__( 'List of Post IDs separated by comma to exclude from showing.', 'kleo' ),
			'default'  => ''
		),

		array(
			'id'       => 'blog_social_share_facebook',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Facebook sharing', 'kleo' ),
			'subtitle' => esc_html__( 'Show Facebook sharing icon', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_social_share_twitter',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Twitter sharing', 'kleo' ),
			'subtitle' => esc_html__( 'Show Twitter sharing icon', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_social_share_googleplus',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Google+ sharing', 'kleo' ),
			'subtitle' => esc_html__( 'Show Google+ sharing icon', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_social_share_pinterest',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Pinterest sharing', 'kleo' ),
			'subtitle' => esc_html__( 'Show Pinterest sharing icon', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_social_share_linkedin',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Linkedin sharing', 'kleo' ),
			'subtitle' => esc_html__( 'Show Linkedin sharing icon', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_social_share_whatsapp',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Whatsapp sharing', 'kleo' ),
			'subtitle' => esc_html__( 'Show Whatsapp sharing icon on mobile and tablet devices', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_social_share_vk',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable VK sharing', 'kleo' ),
			'subtitle' => esc_html__( 'Show VK sharing icon on mobile and tablet devices', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'blog_social_share_mail',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Mail sharing', 'kleo' ),
			'subtitle' => esc_html__( 'Show Mail sharing icon', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),

		array(
			'id'       => 'likes_status',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable post likes', 'kleo' ),
			'subtitle' => esc_html__( 'Allow people to like your post', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'likes_exclude',
			'type'     => 'text',
			'required' => array( 'likes_status', 'equals', '1' ),
			'title'    => esc_html__( 'Exclude IDs', 'kleo' ),
			'subtitle' => esc_html__( 'List of Post IDs separated by comma to exclude from showing likes', 'kleo' ),
			'default'  => ''
		),
		array(
			'id'       => 'likes_ajax',
			'type'     => 'switch',
			'required' => array( 'likes_status', 'equals', '1' ),
			'title'    => esc_html__( 'Enable Likes by Ajax', 'kleo' ),
			'subtitle' => wp_kses_post( __( 'Get the likes count by Ajax if you have cached content. <br> NOTE: Not recommended. This will increase page load DRAMATICALLY.', 'kleo' ) ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'likes_zero_text',
			'type'     => 'text',
			'required' => array( 'likes_status', 'equals', '1' ),
			'title'    => esc_html__( '0 likes text', 'kleo' ),
			'subtitle' => esc_html__( 'Text to show in case the post has no likes', 'kleo' ),
			'default'  => 'likes' // 1 = checked | 0 = unchecked
		),

		array(
			'id'       => 'likes_one_text',
			'type'     => 'text',
			'required' => array( 'likes_status', 'equals', '1' ),
			'title'    => esc_html__( '1 like text', 'kleo' ),
			'subtitle' => esc_html__( 'Text to show in case the post has 1 like', 'kleo' ),
			'default'  => 'like' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'likes_more_text',
			'type'     => 'text',
			'required' => array( 'likes_status', 'equals', '1' ),
			'title'    => esc_html__( 'More than 1 like text', 'kleo' ),
			'subtitle' => esc_html__( 'Text to show in case the post has more than 1 like', 'kleo' ),
			'default'  => 'likes' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'likes_already',
			'type'     => 'text',
			'required' => array( 'likes_status', 'equals', '1' ),
			'title'    => esc_html__( 'More than 1 like text', 'kleo' ),
			'subtitle' => esc_html__( 'Text to show in case user has already liked the post', 'kleo' ),
			'default'  => 'You already like this' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'like_this_text',
			'type'     => 'text',
			'required' => array( 'likes_status', 'equals', '1' ),
			'title'    => esc_html__( 'Text on icon hover', 'kleo' ),
			'subtitle' => esc_html__( 'Text that shows when hovering the icon', 'kleo' ),
			'default'  => 'Like this' // 1 = checked | 0 = unchecked
		)
	)
);


if ( sq_option( 'module_portfolio', 1 ) == 1 ) {
	$sections[] = array(
		'icon'       => 'el-icon-th-large',
		'icon_class' => 'icon-large',
		'title'      => esc_html__( 'Portfolio', 'kleo' ),
		'customizer' => false,
		'desc'       => '<p class="description">' . esc_html__( 'Portfolio related settings. Please re-save permalinks when changing slugs or archive page.', 'kleo' ) . '</p>',
		'fields'     => array(

			array(
				'id'          => 'portfolio_custom_archive',
				'type'        => 'switch',
				'title'       => esc_html__( 'Custom page for Portfolio Archive', 'kleo' ),
				'subtitle'    => 'This means you need to create a page and assign it below. Re-save permalinks from Settings - Permalinks',
				'description' => 'Setting it to ON will take the name and slug from the page assigned.',
				'default'     => '0' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'portfolio_page',
				'type'     => 'select',
				'data'     => 'pages',
				'required' => array( 'portfolio_custom_archive', 'equals', '1' ),
				'title'    => esc_html__( 'Portfolio Page', 'kleo' ),
				'subtitle' => "You need to add [kleo_portfolio] shortcode to the page or using Visual Composer.",
				'default'  => ''
			),
			array(
				'id'       => 'portfolio_name',
				'type'     => 'text',
				'required' => array( 'portfolio_custom_archive', 'equals', '0' ),
				'title'    => esc_html__( 'Portfolio name', 'kleo' ),
				'subtitle' => "You can replace the name with something else",
				'default'  => 'Portfolio'
			),
			array(
				'id'       => 'portfolio_slug',
				'type'     => 'text',
				'required' => array( 'portfolio_custom_archive', 'equals', '0' ),
				'title'    => esc_html__( 'Portfolio link', 'kleo' ),
				'subtitle' => "You can replace the name with something else. This affects your permalink structure so after changing this you must re-save options in Settings - Permalinks",
				'default'  => 'portfolio'
			),
			array(
				'id'       => 'portfolio_style',
				'type'     => 'select',
				'required' => array( 'portfolio_custom_archive', 'equals', '0' ),
				'title'    => esc_html__( 'Display style for Portfolio page', 'kleo' ),
				'subtitle' => 'How to display the portfolio listed items ',
				'options'  => array(
					'default' => 'Default',
					'overlay' => 'Overlay'
				),
				'default'  => 'default'
			),
			array(
				'id'       => 'portfolio_title_style',
				'type'     => 'select',
				'required' => array(
					array( 'portfolio_custom_archive', 'equals', '0' ),
					array( 'portfolio_style', 'equals', 'overlay' )
				),
				'title'    => esc_html__( 'Title style', 'kleo' ),
				'subtitle' => '',
				'options'  => array(
					'normal' => 'Normal',
					'hover'  => 'Shown only on item hover'
				),
				'default'  => 'normal' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'portfolio_excerpt',
				'type'     => 'switch',
				'required' => array( 'portfolio_custom_archive', 'equals', '0' ),
				'title'    => esc_html__( 'Show/Hide subtitle', 'kleo' ),
				'subtitle' => 'Display item excerpt on portfolio page',
				'default'  => '1' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'portfolio_per_row',
				'type'     => 'text',
				'required' => array( 'portfolio_custom_archive', 'equals', '0' ),
				'title'    => esc_html__( 'Number of items per row', 'kleo' ),
				'subtitle' => "A number between 2 and 6",
				'default'  => '4'
			),
			array(
				'id'       => 'portfolio_filter',
				'type'     => 'select',
				'required' => array( 'portfolio_custom_archive', 'equals', '0' ),
				'title'    => esc_html__( 'Show categories filter on portfolio page', 'kleo' ),
				'subtitle' => '',
				'options'  => array(
					'yes' => 'Yes',
					'no'  => 'No'
				),
				'default'  => 'yes'
			),
			array(
				'id'       => 'portfolio_image',
				'type'     => 'text',
				'required' => array( 'portfolio_custom_archive', 'equals', '0' ),
				'title'    => esc_html__( 'Thumbnail image size', 'kleo' ),
				'subtitle' => esc_html__( 'Set your portfolio image size in portfolio list. Defined in pixels. If you are using video items, use a 16:9 size format', 'kleo' ),
				'default'  => $kleo_config['post_gallery_img_width'] . "x" . $kleo_config['post_gallery_img_height']
			),
			array(
				'id'          => 'portfolio_video_height',
				'type'        => 'text',
				'title'       => esc_html__( 'Portfolio list Video Height', 'kleo' ),
				'description' => esc_html__( 'Set your portfolio video height default size. It is used when you only have videos in a page. In portfolio lists where you also have images it will have the image height.', 'kleo' ),
				'subtitle'    => esc_html__( "Expressed in pixels. Example: 160", 'kleo' ),
				'default'     => '160'
			),
			array(
				'id'       => 'portfolio_slider_action',
				'type'     => 'select',
				'title'    => esc_html__( 'Click on Slider images action', 'kleo' ),
				'subtitle' => 'What to do when you click a Slider image on the archive page or from shortcode. Works only for the Slider media type',
				'options'  => array(
					'default' => 'Open portfolio item',
					'modal'   => 'Open big image in modal'
				),
				'default'  => 'default'
			),

			array(
				'id'       => 'section-title-porto-single',
				'type'     => 'section',
				'title'    => esc_html__( 'Portfolio Single Item Page', 'kleo' ),
				'subtitle' => esc_html__( 'Settings for portfolio item page', 'kleo' ),
				'indent'   => true, // Indent all options below until the next 'section' option is set.
			),
			array(
				'id'       => 'portfolio_media_status',
				'type'     => 'switch',
				'title'    => esc_html__( 'Display media on single portfolio page', 'kleo' ),
				'subtitle' => esc_html__( 'If you want to show image/gallery/video before the content on single portfolio page', 'kleo' ),
				'default'  => '1' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'portfolio_back_to',
				'type'     => 'switch',
				'title'    => esc_html__( 'Show back to Portfolio icon(bottom of single portfolio item page)', 'kleo' ),
				'subtitle' => '',
				'default'  => '1' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'portfolio_comments',
				'type'     => 'switch',
				'title'    => esc_html__( 'Enable comments on portfolio single page', 'kleo' ),
				'subtitle' => '',
				'default'  => '0' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'portfolio_navigation',
				'type'     => 'switch',
				'title'    => esc_html__( 'Enable portfolio navigation', 'kleo' ),
				'subtitle' => 'Display previous and next portfolio navigation',
				'default'  => '1' // 1 = checked | 0 = unchecked
			),
			array(
				'id'     => 'section-title-porto-single-end',
				'type'   => 'section',
				'indent' => false, // Indent all options below until the next 'section' option is set.
			),
		)
	);
}
if ( function_exists( 'bp_is_active' ) ) {
	$bp_fields = array(

		array(
			'id'       => 'bp_layout',
			'type'     => 'image_select',
			'compiler' => true,
			'title'    => esc_html__( 'Default Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select your Buddypress pages layout. Choose between 1, 2 or 3 column layout.', 'kleo' ),
			'options'  => array(
				'no'    => array( 'alt' => 'No sidebar', 'img' => SVQ_LIB_URI . '/assets/images/1col.png' ),
				'left'  => array( 'alt' => '2 Column Left', 'img' => SVQ_LIB_URI . '/assets/images/2cl.png' ),
				'right' => array( 'alt' => '2 Column Right', 'img' => SVQ_LIB_URI . '/assets/images/2cr.png' ),
				'3lr'   => array( 'alt' => '3 Column Middle', 'img' => SVQ_LIB_URI . '/assets/images/3cm.png' ),
				'3ll'   => array( 'alt' => '3 Column Left', 'img' => SVQ_LIB_URI . '/assets/images/3cl.png' ),
				'3rr'   => array( 'alt' => '3 Column Right', 'img' => SVQ_LIB_URI . '/assets/images/3cr.png' )
			),
			'default'  => 'right'
		),
		array(
			'id'       => 'bp_layout_members_dir',
			'type'     => 'select',
			'compiler' => true,
			'title'    => esc_html__( 'Members Directory Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select your Buddypress Members directory layout. Choose between 1, 2 or 3 column layout.', 'kleo' ),
			'options'  => array(
				'default' => 'Default layout as set above',
				'no'      => 'Full width',
				'left'    => 'Left Sidebar',
				'right'   => 'Right Sidebar',
				'3lr'     => '3 Column, Left and Right Sidebars',
				'3ll'     => '3 Column, 2 Left sidebars',
				'3rr'     => '3 Column, 2 Right sidebars'
			),
			'default'  => 'default'
		),
		array(
			'id'       => 'bp_layout_profile',
			'type'     => 'select',
			'compiler' => true,
			'title'    => esc_html__( 'Member Profile Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select your Member profile layout. Choose between 1, 2 or 3 column layout.', 'kleo' ),
			'options'  => array(
				'default' => 'Default layout as set above',
				'no'      => 'Full width',
				'left'    => 'Left Sidebar',
				'right'   => 'Right Sidebar',
				'3lr'     => '3 Column, Left and Right Sidebars',
				'3ll'     => '3 Column, 2 Left sidebars',
				'3rr'     => '3 Column, 2 Right sidebars'
			),
			'default'  => 'default'
		),
		array(
			'id'       => 'bp_layout_groups',
			'type'     => 'select',
			'compiler' => true,
			'title'    => esc_html__( 'Groups Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select your Groups pages layout. Choose between 1, 2 or 3 column layout.', 'kleo' ),
			'options'  => array(
				'default' => 'Default layout as set above',
				'no'      => 'Full width',
				'left'    => 'Left Sidebar',
				'right'   => 'Right Sidebar',
				'3lr'     => '3 Column, Left and Right Sidebars',
				'3ll'     => '3 Column, 2 Left sidebars',
				'3rr'     => '3 Column, 2 Right sidebars'
			),
			'default'  => 'default'
		),
		array(
			'id'       => 'bp_layout_activity',
			'type'     => 'select',
			'compiler' => true,
			'title'    => esc_html__( 'Activity Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select your Activity pages layout. Choose between 1, 2 or 3 column layout.', 'kleo' ),
			'options'  => array(
				'default' => 'Default layout as set above',
				'no'      => 'Full width',
				'left'    => 'Left Sidebar',
				'right'   => 'Right Sidebar',
				'3lr'     => '3 Column, Left and Right Sidebars',
				'3ll'     => '3 Column, 2 Left sidebars',
				'3rr'     => '3 Column, 2 Right sidebars'
			),
			'default'  => 'default'
		),
		array(
			'id'       => 'bp_layout_register',
			'type'     => 'select',
			'compiler' => true,
			'title'    => esc_html__( 'Register page Layout', 'kleo' ),
			'subtitle' => esc_html__( 'Select your Register page layout. Choose between 1, 2 or 3 column layout.', 'kleo' ),
			'options'  => array(
				'default' => 'Default layout as set above',
				'no'      => 'Full width',
				'left'    => 'Left Sidebar',
				'right'   => 'Right Sidebar',
				'3lr'     => '3 Column, Left and Right Sidebars',
				'3ll'     => '3 Column, 2 Left sidebars',
				'3rr'     => '3 Column, 2 Right sidebars'
			),
			'default'  => 'default'
		),

		array(
			'id'       => 'bp_profile_sidebar',
			'type'     => 'select',
			'compiler' => true,
			'title'    => esc_html__( 'Profile page sidebar', 'kleo' ),
			'subtitle' => esc_html__( 'Select your sidebar to show on profile pages.', 'kleo' ),
			'data'     => 'sidebar',
			'default'  => 'default'
		),

		array(
			'id'       => 'bp_group_sidebar',
			'type'     => 'select',
			'compiler' => true,
			'title'    => esc_html__( 'Group page sidebar', 'kleo' ),
			'subtitle' => esc_html__( 'Select your sidebar to show on single group page.', 'kleo' ),
			'data'     => 'sidebar',
			'default'  => 'default'
		),

		array(
			'id'       => 'bp_title_location',
			'type'     => 'button_set',
			'compiler' => true,
			'title'    => esc_html__( 'Page Title location', 'kleo' ),
			'subtitle' => esc_html__( 'Choose where to show page title. In the breadcrumb section or in the main content', 'kleo' ),
			'options'  => array(
				'default'    => 'Site Default',
				'breadcrumb' => 'Breadcrumb section',
				'main'       => 'Main section',
				'disabled'   => "Disabled"
			),
			'default'  => 'default'
		),
		array(
			'id'       => 'bp_breadcrumb_status',
			'type'     => 'switch',
			'title'    => esc_html__( 'Show breadcrumb', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable the site path under the page title.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'bp_custom_info',
			'type'     => 'switch',
			'title'    => esc_html__( 'Custom main menu info', 'kleo' ),
			'subtitle' => esc_html__( 'Add a custom text in the main menu to show only on Buddypress pages.', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'bp_title_info',
			'type'     => 'textarea',
			'title'    => esc_html__( 'Main menu info', 'kleo' ),
			'required' => array( 'bp_custom_info', '=', '1' ),
			'subtitle' => esc_html__( 'This text displays next to the main menu', 'kleo' ),
			'desc'     => '',
			'default'  => '<em class="muted">feel free to call us</em> &nbsp;&nbsp;<i class="icon-phone"></i> +91.33.26789234 &nbsp;&nbsp;&nbsp;&nbsp;<i class="icon-mail-alt"></i> support@seventhqueen.com'
		),
		array(
			'id'       => 'bp_profile_breadcrumb_disable',
			'type'     => 'switch',
			'title'    => esc_html__( 'Hide Breadcrumb section for Profile pages', 'kleo' ),
			'subtitle' => esc_html__( 'If enabled the breadcrumb section will be hidden. Best used when you enable full width profile page below', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'bp_full_profile',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Full width Profile Header', 'kleo' ),
			'subtitle' => esc_html__( 'If enabled it will show the profile Photo in full width', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'bp_full_group',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Full width Group Header', 'kleo' ),
			'subtitle' => esc_html__( 'If enabled it will show the Group Photo section in full width', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'bp_nav_overlay',
			'type'     => 'switch',
			'title'    => esc_html__( 'Profile &amp; Group Menu Overlay', 'kleo' ),
			'subtitle' => esc_html__( 'Put navigation menu over the cover image like an overlay.', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		)
	);

	if ( bp_get_theme_package_id() === 'legacy' ) {
		$bp_fields[] = array(
			'id'       => 'bp_icons_style',
			'type'     => 'image_select',
			'compiler' => true,
			'title'    => esc_html__( 'Profile &amp; Group Icons style', 'kleo' ),
			'subtitle' => esc_html__( 'Choose between normal or light icons.', 'kleo' ),
			'options'  => array(
				'normal' => array(
					'alt' => 'Normal icons',
					'img' => KLEO_LIB_URI . '/assets/images/normal-profile.png'
				),
				'light'  => array(
					'alt' => 'Light icons',
					'img' => KLEO_LIB_URI . '/assets/images/light-profile.png'
				),
			),
			'default'  => 'normal'
		);
	}

	$bp_fields = array_merge( $bp_fields, array(
		array(
			'id'       => 'bp_online_status',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable online status', 'kleo' ),
			'subtitle' => esc_html__( 'If enabled it will show a colored dot next to each member avatar', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'bp_birthdate_to_age',
			'type'     => 'switch',
			'title'    => esc_html__( 'Switch birthday to age', 'kleo' ),
			'subtitle' => esc_html__( 'If enabled it will show a age instead birthday', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'bp_birthdate_to_age_field',
			'type'     => 'text',
			'title'    => esc_html__( 'Buddypress Birthday field name', 'kleo' ),
			'subtitle' => esc_html__( 'The field name should be exactly the same as in Users -> Profile Fields for field that contains the datebox', 'kleo' ),
			'default'  => 'Birthday',// 1 = checked | 0 = unchecked
			'required' => array( 'bp_birthdate_to_age', '=', '1' ),

		),
		array(
			'id'       => 'member_navigation',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable member navigation', 'kleo' ),
			'subtitle' => esc_html__( 'Display previous and next member navigation', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'bp_notif_interval',
			'type'     => 'text',
			'title'    => esc_html__( 'AJAX refresh interval', 'kleo' ),
			'subtitle' => 'Menu notifications and Messages Refresh time in miliseconds. Default is 20000 which means 20 seconds. Put 0 to disable notifications refresh. Add it to the menu from Appearance - Menus - KLEO section.',
			'default'  => '20000' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'bp_members_perpage',
			'type'     => 'text',
			'title'    => esc_html__( 'Members per page', 'kleo' ),
			'subtitle' => esc_html__( 'How many members to show per page in the Members Directory', 'kleo' ),
			'default'  => '24' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'bp_groups_perpage',
			'type'     => 'text',
			'title'    => esc_html__( 'Groups per page', 'kleo' ),
			'subtitle' => esc_html__( 'How many groups to show per page in the Groups Directory', 'kleo' ),
			'default'  => '24' // 1 = checked | 0 = unchecked
		)
	) );

	if ( bp_get_theme_package_id() === 'nouveau' ) {
		$bp_fields[] = array(
			'id'       => 'bp_rounded_avatars',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Rounded avatars', 'kleo' ),
			'subtitle' => esc_html__( 'If enabled will enable rounded avatars', 'kleo' ),
			'default'  => '1', // 1 = checked | 0 = unchecked
		);
	}

	$sections[] = array(
		'icon'       => 'el-icon-torso',
		'icon_class' => 'icon-large',
		'title'      => esc_html__( 'Buddypress', 'kleo' ),
		'customizer' => false,
		'desc'       => '<p class="description">' . esc_html__( 'Buddypress related settings', 'kleo' ) . '</p>',
		'fields'     => $bp_fields
	);
}

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

	$sections[] = array(
		'icon'       => 'el-icon-shopping-cart',
		'icon_class' => 'icon-large',
		'title'      => esc_html__( 'Woocommerce', 'kleo' ),
		'customizer' => false,
		'desc'       => '',
		'fields'     => array(
			array(
				'id'       => 'woo_sidebar',
				'type'     => 'select',
				'compiler' => true,
				'title'    => esc_html__( 'Woocommerce Pages Layout', 'kleo' ),
				'subtitle' => esc_html__( 'Select the layout to use in Woocommerce pages.', 'kleo' ),
				'options'  => array(
					'default' => 'Default site layout',
					'no'      => 'Full width',
					'left'    => 'Left Sidebar',
					'right'   => 'Right Sidebar',
					'3lr'     => '3 Column, Left and Right Sidebars',
					'3ll'     => '3 Column, 2 Left sidebars',
					'3rr'     => '3 Column, 2 Right sidebars'
				),
				'default'  => 'default'
			),
			array(
				'id'       => 'woo_cat_sidebar',
				'type'     => 'select',
				'compiler' => true,
				'title'    => esc_html__( 'Woocommerce Category Layout', 'kleo' ),
				'subtitle' => esc_html__( 'Select the layout to use in Woocommerce product listing pages.', 'kleo' ),
				'options'  => array(
					'default' => 'Default as set above',
					'no'      => 'Full width',
					'left'    => 'Left Sidebar',
					'right'   => 'Right Sidebar',
					'3lr'     => '3 Column, Left and Right Sidebars',
					'3ll'     => '3 Column, 2 Left sidebars',
					'3rr'     => '3 Column, 2 Right sidebars'
				),
				'default'  => 'default'
			),
			array(
				'id'       => 'woo_single_sidebar',
				'type'     => 'select',
				'compiler' => true,
				'title'    => esc_html__( 'Woocommerce Single Product Layout', 'kleo' ),
				'subtitle' => esc_html__( 'Select the layout to use in Woocommerce single product pages.', 'kleo' ),
				'options'  => array(
					'default' => 'Default as set above',
					'no'      => 'Full width',
					'left'    => 'Left Sidebar',
					'right'   => 'Right Sidebar',
					'3lr'     => '3 Column, Left and Right Sidebars',
					'3ll'     => '3 Column, 2 Left sidebars',
					'3rr'     => '3 Column, 2 Right sidebars'
				),
				'default'  => 'default'
			),
			array(
				'id'       => 'woo_cart_location',
				'type'     => 'button_set',
				'title'    => esc_html__( 'Menu cart location', 'kleo' ),
				'subtitle' => esc_html__( 'Shopping Cart in header menu location', 'kleo' ),
				'options'  => array(
					'off'       => 'Disabled',
					'primary'   => 'Primary menu',
					'secondary' => 'Secondary menu',
					'top'       => 'Top menu'
				),
				'default'  => 'primary'
			),

			array(
				'id'       => 'woo_mobile_cart',
				'type'     => 'switch',
				'title'    => esc_html__( 'Mobile menu Cart Icon', 'kleo' ),
				'subtitle' => esc_html__( 'This will show on mobile menu a shop icon with the number of cart items', 'kleo' ),
				'default'  => '1' // 1 = checked | 0 = unchecked
			),

			array(
				'id'       => 'woo_image_effect',
				'type'     => 'button_set',
				'title'    => esc_html__( 'Product image effect', 'kleo' ),
				'subtitle' => esc_html__( 'The effect on products listing when hovering an image.', 'kleo' ),
				'options'  => array(
					'default' => 'Bottom-Top',
					'fade'    => 'Fade',
					'alt'     => 'Left-Right',
					'single'  => 'No effect'
				),
				'default'  => 'default'
			),

			array(
				'id'       => 'woo_product_animate',
				'type'     => 'switch',
				'title'    => esc_html__( 'Enable product listing Appear Animation', 'kleo' ),
				'subtitle' => esc_html__( 'On product listing the products will have a appear animation.', 'kleo' ),
				'default'  => '1' // 1 = checked | 0 = unchecked
			),

			array(
				'id'       => 'woo_percentage_badge',
				'type'     => 'switch',
				'title'    => esc_html__( 'Show percentage badge on products list', 'kleo' ),
				'subtitle' => esc_html__( 'This will replace the "Sale" badge with "SAVE UP TO xx%"', 'kleo' ),
				'default'  => '0' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'woo_percent_color',
				'type'     => 'color',
				'required' => array( 'woo_percentage_badge', '=', '1' ),
				'title'    => esc_html__( 'Custom Badge color', 'kleo' ),
				'subtitle' => '',
				'default'  => '#ffffff' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'woo_percent_bg',
				'type'     => 'color',
				'required' => array( 'woo_percentage_badge', '=', '1' ),
				'title'    => esc_html__( 'Custom Badge Background', 'kleo' ),
				'subtitle' => '',
				'default'  => '#000000' // 1 = checked | 0 = unchecked
			),
			array(
				'id'      => 'woo_free_badge',
				'type'    => 'switch',
				'title'   => esc_html__( 'Show free badge on products list', 'kleo' ),
				'default' => '1' // 1 = checked | 0 = unchecked
			),

			array(
				'id'       => 'woo_new_badge',
				'type'     => 'switch',
				'title'    => esc_html__( 'Show NEW badge for new products added', 'kleo' ),
				'subtitle' => '',
				'default'  => '1' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'woo_new_days',
				'type'     => 'text',
				'required' => array( 'woo_new_badge', '=', '1' ),
				'title'    => esc_html__( 'Number of days to treat a product as new', 'kleo' ),
				'subtitle' => esc_html__( 'For how many days to show the NEW badge once a product is added to the shop.', 'kleo' ),
				'default'  => '7' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'section-title-woo-single',
				'type'     => 'section',
				'title'    => esc_html__( 'Product Page settings', 'kleo' ),
				'subtitle' => esc_html__( 'Settings for Single Product page', 'kleo' ),
				'indent'   => true, // Indent all options below until the next 'section' option is set.
			),
			array(
				'id'       => 'woo_prod_zoom',
				'type'     => 'switch',
				'title'    => esc_html__( 'Enable Image Zoom', 'kleo' ),
				'subtitle' => 'Enable Main Image Zoom effect on hover. Will disable click to open big image. Use with bellow option for gallery',
				'default'  => '0' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'woo_prod_lightbox',
				'type'     => 'switch',
				'title'    => esc_html__( 'Enable Default Image Gallery', 'kleo' ),
				'subtitle' => 'Enable Woocommerce default gallery for product images',
				'default'  => '0' // 1 = checked | 0 = unchecked
			),

			array(
				'id'       => 'woo_show_excerpt_single',
				'type'     => 'switch',
				'title'    => esc_html__( 'Show excerpt on product page', 'kleo' ),
				'subtitle' => '',
				'default'  => '0' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'woo_show_description_after_product',
				'type'     => 'switch',
				'title'    => esc_html__( 'Show tabs after product instead under cart', 'kleo' ),
				'subtitle' => '',
				'default'  => '0' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'woo_product_navigation',
				'type'     => 'switch',
				'title'    => esc_html__( 'Enable product navigation', 'kleo' ),
				'subtitle' => 'Display previous and next product navigation',
				'default'  => '1' // 1 = checked | 0 = unchecked
			),

			array(
				'id'     => 'section-title-woo-single-end',
				'type'   => 'section',
				'indent' => false, // Indent all options below until the next 'section' option is set.
			),

			array(
				'id'       => 'woo_buddypress_menus',
				'type'     => 'switch',
				'title'    => esc_html__( 'Manage account in Buddypress', 'kleo' ),
				'subtitle' => esc_html__( 'Integrates "My Account" into Buddypress profile tabs', 'kleo' ),
				'default'  => '1' // 1 = checked | 0 = unchecked
			),


			array(
				'id'       => 'woo_catalog',
				'type'     => 'button_set',
				'title'    => esc_html__( 'Catalog mode', 'kleo' ),
				'subtitle' => esc_html__( 'If you enable catalog mode will disable Add To Cart buttons, Checkout and Shopping cart.', 'kleo' ),
				'options'  => array(
					'0' => 'No',
					'1' => 'Yes'
				),
				'default'  => '0'
			),
			array(
				'id'       => 'woo_disable_prices',
				'type'     => 'button_set',
				'title'    => esc_html__( 'Disable prices', 'kleo' ),
				'subtitle' => esc_html__( 'Disable prices on category pages and product page', 'kleo' ),
				'options'  => array(
					'0' => 'No',
					'1' => 'Yes'
				),
				'required' => array( 'woo_catalog', '=', '1' ),
				'default'  => '0'
			),
			array(
				'id'       => 'woo_shop_columns',
				'type'     => 'select',
				'title'    => esc_html__( 'Shop Products Columns', 'kleo' ),
				'subtitle' => esc_html__( 'Select the number of columns to use for products display.', 'kleo' ),
				'options'  => array(
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6'
				),
				'default'  => '3'
			),
			array(
				'id'       => 'woo_shop_products',
				'type'     => 'text',
				'title'    => esc_html__( 'Shop Products per page', 'kleo' ),
				'subtitle' => esc_html__( 'How many products to show per page', 'kleo' ),
				'default'  => '15' // 1 = checked | 0 = unchecked
			),
			array(
				'id'       => 'woo_related_columns',
				'type'     => 'select',
				'title'    => esc_html__( 'Related Products number', 'kleo' ),
				'subtitle' => esc_html__( 'Select the number of related products to show on product page. Set 0 to disable', 'kleo' ),
				'options'  => array(
					'0' => '0',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6'
				),
				'default'  => '3'
			),
			array(
				'id'       => 'woo_upsell_columns',
				'type'     => 'select',
				'title'    => esc_html__( 'Upsell Products number', 'kleo' ),
				'subtitle' => esc_html__( 'Select the number of upsell products to show on product page.', 'kleo' ),
				'options'  => array(
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6'
				),
				'default'  => '3'
			),
			array(
				'id'       => 'woo_cross_columns',
				'type'     => 'select',
				'title'    => esc_html__( 'Cross-sell Products number', 'kleo' ),
				'subtitle' => esc_html__( 'Select the number of Cross-sell products to show on cart page.', 'kleo' ),
				'options'  => array(
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6'
				),
				'default'  => '3'
			)
		)
	);
}

if ( class_exists( 'bbPress' ) ) {

	$sections[] = array(
		'icon'       => 'el-icon-comment-alt',
		'icon_class' => 'icon-large',
		'title'      => esc_html__( 'bbPress', 'kleo' ),
		'customizer' => false,
		'desc'       => '',
		'fields'     => array(
			array(
				'id'       => 'bbpress_sidebar',
				'type'     => 'select',
				'title'    => esc_html__( 'bbPress Pages Layout', 'kleo' ),
				'subtitle' => esc_html__( 'Select the layout to use in bbPress pages.', 'kleo' ),
				'options'  => array(
					'default' => 'Default site layout',
					'no'      => 'Full width',
					'left'    => 'Left Sidebar',
					'right'   => 'Right Sidebar',
					'3lr'     => '3 Column, Left and Right Sidebars',
					'3ll'     => '3 Column, 2 Left sidebars',
					'3rr'     => '3 Column, 2 Right sidebars'
				),
				'default'  => 'default'
			),
			array(
				'id'       => 'bbpress_custom_sidebar',
				'type'     => 'select',
				'compiler' => true,
				'title'    => esc_html__( 'Custom sidebar', 'kleo' ),
				'subtitle' => esc_html__( 'Select your sidebar to show on all bbPress pages.', 'kleo' ),
				'data'     => 'sidebar',
				'default'  => ''
			),
			array(
				'id'       => 'bbpress_mentions',
				'type'     => 'switch',
				'title'    => esc_html__( 'Enable @mentions.', 'kleo' ),
				'subtitle' => esc_html__( 'Enable or disable Buddypress @mentions in forum topics.', 'kleo' ),
				'default'  => '1' // 1 = checked | 0 = unchecked
			),
		)
	);
}

if ( function_exists( 'pmpro_url' ) && function_exists( 'pmpro_data_set' ) ) {

	$sections[] = array(

		'icon'       => 'el-icon-group',
		'icon_class' => 'icon-large',
		'title'      => esc_html__( 'Memberships', 'kleo' ),
		'customizer' => false,
		'desc'       => '<p class="description">' . esc_html__( 'Settings related to membership. You need to have Paid Memberships Pro plugin activated', 'kleo' ) . '</p>',
		'fields'     => array(
			array(
				'id'       => 'membership',
				'type'     => 'callback',
				'title'    => esc_html__( 'Membership settings', 'kleo' ),
				'sub_desc' => '',
				'callback' => 'pmpro_data_set',
			)
		)
	);
}


$sections[] = array(

	'icon'       => 'el-icon-iphone-home',
	'icon_type'  => 'iconfont',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Mobile options', 'kleo' ),
	'customizer' => false,
	'desc'       => esc_html__( "Mobile specific customisations", 'kleo' ),
	'fields'     => array(
		array(
			'id'       => 'mobile_app_capable',
			'type'     => 'switch',
			'title'    => esc_html__( 'Add mobile app capable meta tag', 'kleo' ),
			'subtitle' => wp_kses_post( sprintf( __( 'See more <a target="_blank" href="%s">here</a> ', 'kleo' ), 'https://developer.chrome.com/multidevice/android/installtohomescreen' ) ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'apple_mobile_app_capable',
			'type'     => 'switch',
			'title'    => esc_html__( 'Add Apple mobile app capable meta tag', 'kleo' ),
			'subtitle' => wp_kses_post( sprintf( __( 'See more <a target="_blank" href="%s">here</a> ', 'kleo' ), 'https://developer.apple.com/library/iad/documentation/AppleApplications/Reference/SafariHTMLRef/Articles/MetaTags.html' ) ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'meta_theme_color',
			'type'        => 'color',
			'title'       => esc_html__( 'Set mobile browser theme color(only on supported browsers)', 'kleo' ),
			'subtitle'    => wp_kses_post( sprintf( __( 'See more <a target="_blank" href="%s">here</a> ', 'kleo' ), 'https://developers.google.com/web/fundamentals/design-and-ui/browser-customization/theme-color?hl=en' ) ),
			'description' => esc_html__( "Allows you to set a browser theme color. To disable it just remove the color code", 'kleo' ),
			'default'     => '' // 1 = checked | 0 = unchecked
		),
	)
);

$sections[] = array(

	'icon'       => 'el-icon-cogs',
	'icon_type'  => 'iconfont',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Miscellaneous', 'kleo' ),
	'customizer' => false,
	'desc'       => "",
	'fields'     => array(
		array(
			'id'       => 'sitewide_animations',
			'type'     => 'button_set',
			'title'    => esc_html__( 'Site-Wide Animations', 'kleo' ),
			'subtitle' => esc_html__( 'Options to turn site-wide animations on/off.', 'kleo' ),
			'options'  => array(
				'enabled'        => 'On',
				'disable-all'    => 'Disable on all devices ',
				'disable-mobile' => 'Disable on mobile devices only'
			),
			'default'  => 'enabled'
		),

		array(
			'id'       => 'full_fontawesome',
			'type'     => 'switch',
			'title'    => esc_html__( 'Load complete Font Awesome library', 'kleo' ),
			'subtitle' => esc_html__( 'By default KLEO uses only selected icons from fontello library. If enabled the full library will load', 'kleo' ),
			'options'  => array(
				'off' => '0',
				'on'  => '1',
			),
			'default'  => '0'
		),
		array(
			'id'       => 'admin_bar',
			'type'     => 'switch',
			'title'    => esc_html__( 'Admin toolbar', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable wordpress default top toolbar', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),
		/*array(
			'id'       => 'login_custom_page',
			'type'     => 'select',
			'data'     => 'pages',
			'title'    => esc_html__( 'Login Custom Page', 'kleo' ),
			'subtitle' => esc_html__( 'Select a login page instead of the wordPress default.', 'kleo' ),
            'default'  => '',
            'options'  => array()
        ),*/

		array(
			'id'       => 'login_redirect',
			'type'     => 'select',
			'title'    => esc_html__( 'Login redirect for Popup', 'kleo' ),
			'subtitle' => esc_html__( 'Select the redirect action taken when members login from the popup window.', 'kleo' ),
			'options'  => array(
				'default' => esc_html__( 'Default WordPress redirect', 'kleo' ),
				'reload'  => esc_html__( 'Reload the current page', 'kleo' ),
				'custom'  => esc_html__( 'Custom link', 'kleo' ),
			),
			'default'  => 'default'
		),
		array(
			'id'          => 'login_redirect_custom',
			'type'        => 'text',
			'title'       => esc_html__( 'Custom link redirect', 'kleo' ),
			'description' => wp_kses_post( __( 'Set a link like http://yoursite.com/homepage for users to get redirected on login.<br>For more complex redirect logic please set Login redirect to Default WordPress and use Peter\'s redirect plugin or a similar plugin.', 'kleo' ) ),
			'default'     => '',
			'required'    => array( 'login_redirect', '=', 'custom' ),
		),
		array(
			'id'       => 'homepage_redirect',
			'type'     => 'select',
			'title'    => esc_html__( 'Homepage redirect', 'kleo' ),
			'subtitle' => esc_html__( 'This option will help you redirect your users away from the homepage once they are logged in.', 'kleo' ),
			'options'  => array(
				'disabled' => esc_html__( 'Disabled', 'kleo' ),
				'profile'  => esc_html__( 'Redirect to current user profile or author page', 'kleo' ),
				'custom'   => esc_html__( 'Custom link', 'kleo' ),
			),
			'default'  => 'disabled'
		),
		array(
			'id'          => 'homepage_redirect_custom',
			'type'        => 'text',
			'title'       => esc_html__( 'Custom link redirect', 'kleo' ),
			'description' => wp_kses_post( __( 'Set a link like http://yoursite.com/custompage to redirect logged in users away from your homepage.<br>With BuddyPress/bbPress installed you can add a link to your profile with ##profile_link## in the URL input. Example: ##profile_link##/messages', 'kleo' ) ),
			'default'     => '',
			'required'    => array( 'homepage_redirect', '=', 'custom' ),
		),
		array(
			'id'       => 'let_it_snow',
			'type'     => 'switch',
			'title'    => esc_html__( 'Let it snow', 'kleo' ),
			'subtitle' => esc_html__( 'If you enable this, a beautiful snowing effect will cover the whole site', 'kleo' ),
			'default'  => '0', // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'magnific_disable_gallery',
			'type'        => 'switch',
			'title'       => esc_html__( 'Disable Magnific popup for blog gallery', 'kleo' ),
			'subtitle'    => esc_html__( 'WARNING: Not recommended. Disable this only if you have other implementation for Image popup.(ON will disable the popup)', 'kleo' ),
			'description' => "<small>Will disable popups for this image tags:<br> a[data-rel^='prettyPhoto'], a[rel^='prettyPhoto'], a[rel^='modalPhoto'], a[data-rel^='modalPhoto'], .article-content a[href$=jpg]:has(img), .article-content a[href$=JPG]:has(img), .article-content a[href$=jpeg]:has(img), .article-content a[href$=JPEG]:has(img), .article-content a[href$=gif]:has(img), .article-content a[href$=GIF]:has(img), .article-content a[href$=bmp]:has(img), .article-content a[href$=BMP]:has(img), .article-content a[href$=png]:has(img), .article-content a[href$=PNG]:has(img)</small>",
			'default'     => '0', // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'client_publicly_queryable',
			'type'        => 'switch',
			'title'       => esc_html__( 'Clients publicly queryable', 'kleo' ),
			'description' => "Will allow the clients post type to be accessible from /clients on front-end",
			'default'     => '1', // 1 = checked | 0 = unchecked
		),
		array(
			'id'          => 'testimonial_publicly_queryable',
			'type'        => 'switch',
			'title'       => esc_html__( 'Testimonials publicly queryable', 'kleo' ),
			'description' => "Will allow the testimonials post type to be accessible from /testimonials on front-end",
			'default'     => '1', // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'dev_mode',
			'type'     => 'switch',
			'title'    => esc_html__( 'Development mode', 'kleo' ),
			'subtitle' => esc_html__( 'If you enable this, CSS and JS resources will not be loaded minified', 'kleo' ),
			'default'  => '0', // 1 = checked | 0 = unchecked
		),
	)
);


$sections[] = array(
	'icon_type'  => 'iconfont',
	'icon_class' => 'el-icon-twitter',
	'title'      => esc_html__( 'Social Info', 'kleo' ),
	'customizer' => false,
	'desc'       => '<p class="description">' . esc_html__( 'Here you can set your contact info that will display in the top bar.', 'kleo' ) . '</p>',
	'fields'     => array(

		array(
			'id'       => 'show_social_icons',
			'type'     => 'switch',
			'title'    => esc_html__( 'Display social icons', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable the social icons in top bar.', 'kleo' ),
			'default'  => '1' // 1 = checked | 0 = unchecked
		),

		array(
			'id'       => 'social_twitter',
			'type'     => 'text',
			'title'    => esc_html__( 'Twitter', 'kleo' ),
			'subtitle' => esc_html__( 'Your Twitter link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_facebook',
			'type'     => 'text',
			'title'    => esc_html__( 'Facebook', 'kleo' ),
			'subtitle' => esc_html__( 'Your Facebook page/profile link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
        array(
            'id'       => 'social_tiktok',
            'type'     => 'text',
            'title'    => esc_html__( 'TikTok', 'kleo' ),
            'subtitle' => esc_html__( 'Your Tiktok page/profile link', 'kleo' ),
            'desc'     => '',
            'default'  => ''
        ),
		array(
			'id'       => 'social_whatsapp',
			'type'     => 'text',
			'title'    => esc_html__( 'WhatsApp', 'kleo' ),
			'subtitle' => esc_html__( 'Your WhatsApp number link', 'kleo' ),
			'desc'     => esc_html__( 'Usage example: https://api.whatsapp.com/send?phone=40721234567', 'kleo' ),
			'default'  => ''
		),
		array(
			'id'       => 'social_telegram',
			'type'     => 'text',
			'title'    => esc_html__( 'Telegram', 'kleo' ),
			'subtitle' => esc_html__( 'Your Telegram group/number link', 'kleo' ),
			'desc'     => esc_html__( 'Usage Example: https://t.me/ChangeWithYourUsername', 'kleo' ),
			'default'  => ''
		),
		array(
			'id'       => 'social_dribbble',
			'type'     => 'text',
			'title'    => esc_html__( 'Dribbble', 'kleo' ),
			'subtitle' => esc_html__( 'Your Dribbble link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_vimeo-squared',
			'type'     => 'text',
			'title'    => esc_html__( 'Vimeo', 'kleo' ),
			'subtitle' => esc_html__( 'Your Vimeo link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_tumblr',
			'type'     => 'text',
			'title'    => esc_html__( 'Tumblr', 'kleo' ),
			'subtitle' => esc_html__( 'Your Tumblr link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_linkedin',
			'type'     => 'text',
			'title'    => esc_html__( 'LinkedIn', 'kleo' ),
			'subtitle' => esc_html__( 'Your LinkedIn link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_gplus',
			'type'     => 'text',
			'title'    => esc_html__( 'Google+', 'kleo' ),
			'subtitle' => esc_html__( 'Your Google+ link', 'kleo' ),
			'desc'     => esc_html__( 'Google plus is now discontinued!', 'kleo' ),
			'default'  => ''
		),
		array(
			'id'       => 'social_flickr',
			'type'     => 'text',
			'title'    => esc_html__( 'Flickr', 'kleo' ),
			'subtitle' => esc_html__( 'Your Flickr page link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_youtube',
			'type'     => 'text',
			'title'    => esc_html__( 'YouTube', 'kleo' ),
			'subtitle' => esc_html__( 'Your YouTube link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_pinterest-circled',
			'type'     => 'text',
			'title'    => esc_html__( 'Pinterest', 'kleo' ),
			'subtitle' => esc_html__( 'Your Pinterest link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_foursquare',
			'type'     => 'text',
			'title'    => esc_html__( 'Foursquare', 'kleo' ),
			'subtitle' => esc_html__( 'Your Foursqaure link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_instagramm',
			'type'     => 'text',
			'title'    => esc_html__( 'Instagram', 'kleo' ),
			'subtitle' => esc_html__( 'Your Instagram link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_github',
			'type'     => 'text',
			'title'    => esc_html__( 'GitHub', 'kleo' ),
			'subtitle' => esc_html__( 'Your GitHub link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_xing',
			'type'     => 'text',
			'title'    => esc_html__( 'Xing', 'kleo' ),
			'subtitle' => esc_html__( 'Your Xing link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_weibo',
			'type'     => 'text',
			'title'    => esc_html__( 'Weibo', 'kleo' ),
			'subtitle' => esc_html__( 'Your Weibo link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		),
		array(
			'id'       => 'social_qq',
			'type'     => 'text',
			'title'    => esc_html__( 'QQ Link', 'kleo' ),
			'subtitle' => esc_html__( 'Your QQ link', 'kleo' ),
			'desc'     => '',
			'default'  => ''
		)
	)
);


$sections[] = array(
	'icon'       => 'el-icon-facebook',
	'icon_class' => 'icon-large',
	'title'      => esc_html__( 'Facebook Integration', 'kleo' ),
	'customizer' => false,
	'desc'       => '',
	'fields'     => array(
		array(
			'id'          => 'fb_app_id',
			'type'        => 'text',
			'title'       => esc_html__( 'Facebook APP ID', 'kleo' ),
			'subtitle'    => wp_kses_post( sprintf( __( 'In order to integrate with Facebook you need to enter your Facebook APP ID<br/>If you don\'t have one, you can create it from: <a target="_blank" href="%s">HERE</a> ', 'kleo' ), 'https://developers.facebook.com/apps' ) ),
			'description' => wp_kses_post( sprintf( __( "See tutorial <a href='%s'>here</a>", 'kleo' ), 'http://seventhqueen.com/support/general/article/create-facebook-app-get-app-id-facebook-login' ) ),
			'default'     => '',

		),
		array(
			'id'          => 'fb_app_secret',
			'type'        => 'text',
			'title'       => esc_html__( 'Facebook APP Secret', 'kleo' ),
			'subtitle'    => 'Facebook APP Secret needed for the Facebook Login to work',
			'description' => '',
			'default'     => '',

		),
		array(
			'id'       => 'facebook_login',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Facebook Login', 'kleo' ),
			'subtitle' => esc_html__( 'Enable or disable Login/Register with Facebook', 'kleo' ),
			'default'  => '0', // 1 = checked | 0 = unchecked
		),
		array(
			'id'       => 'facebook_avatar',
			'type'     => 'switch',
			'title'    => esc_html__( 'Show Facebook avatar', 'kleo' ),
			'subtitle' => esc_html__( 'If you enable this, users that registered with Facebook will display Facebook profile image as avatar.', 'kleo' ),
			'default'  => '0', // 1 = checked | 0 = unchecked
			'required' => array( 'facebook_login', '=', '1' ),
		),
		array(
			'id'       => 'facebook_register',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Registration via Facebook', 'kleo' ),
			'subtitle' => esc_html__( 'If you enable this, users will be able to register a new account using Facebook. This skips the registration page including required profile fields', 'kleo' ),
			'default'  => '0', // 1 = checked | 0 = unchecked
			'required' => array( 'facebook_login', '=', '1' ),
		),
		array(
			'id'       => 'facebook_register_redirect',
			'type'     => 'select',
			'title'    => esc_html__( 'Redirect after Facebook Registration', 'kleo' ),
			'subtitle' => esc_html__( 'Select the redirect action taken when members register with Facebook.', 'kleo' ),
			'options'  => array(
				'default' => esc_html__( 'Redirect to profile edit page(BP only)', 'kleo' ),
				'reload'  => esc_html__( 'Reload the current page', 'kleo' ),
				'custom'  => esc_html__( 'Custom link', 'kleo' ),
			),
			'default'  => 'default',
			'required' => array( 'facebook_register', '=', '1' ),

		),
		array(
			'id'       => 'facebook_sent_email_login_details',
			'type'     => 'switch',
			'title'    => esc_html__( 'Send email with usern and password', 'kleo' ),
			'subtitle' => esc_html__( 'Select if you want to send an email with username and generated password for users that login using Facebook.', 'kleo' ),
			'default'  => '1', // 1 = checked | 0 = unchecked
			'required' => array( 'facebook_register', '=', '1' ),

		),
		array(
			'id'          => 'facebook_register_redirect_url',
			'type'        => 'text',
			'title'       => esc_html__( 'Custom URL after registration.', 'kleo' ),
			'subtitle'    => esc_html__( 'Custom redirect URL after Facebook registration.', 'kleo' ),
			'description' => esc_html__( 'You can use ##profile_link## placeholder to redirect to your profile. Example: ##profile_link##/messages', 'kleo' ),
			'default'     => '', // 1 = checked | 0 = unchecked
			'required'    => array( 'facebook_register_redirect', '=', 'custom' ),
		),
		array(
			'id'       => 'replace_wp_comments_with_fb_comments',
			'type'     => 'switch',
			'title'    => esc_html__( 'Enable Facebook comments', 'kleo' ),
			'subtitle' => esc_html__( 'Use Facebook comments instead of Wordpress default comments', 'kleo' ),
			'default'  => '0' // 1 = checked | 0 = unchecked
		)
	)
);

// END Config

if ( class_exists( 'Redux' ) ) {
	if (method_exists('Redux', 'set_args')) {
		Redux::set_args( $opt_name, $args );
		Redux::set_Sections( $opt_name, $sections );
	} else {
		Redux::setArgs( $opt_name, $args );
		Redux::setSections( $opt_name, $sections );
	}
}

/**
 * Get an array of registered post types with different options
 *
 * @param array $args
 *
 * @return array
 */
function kleo_post_types( $args = array() ) {
	$kleo_post_types = array();

	if ( isset( $args['extra'] ) ) {
		$kleo_post_types = $args['extra'];
	}

	$post_args = array(
		'public'   => true,
		'_builtin' => false
	);

	$types_return = 'objects'; // names or objects, note names is the default
	$post_types   = get_post_types( $post_args, $types_return );

	if ( isset( $args['exclude'] ) ) {
		$except_post_types = array( 'kleo_clients', 'kleo-testimonials', 'topic', 'reply' );
	}

	foreach ( $post_types as $post_type ) {
		if ( isset( $except_post_types ) && in_array( $post_type->name, $except_post_types ) ) {
			continue;
		}
		$kleo_post_types[ $post_type->name ] = $post_type->labels->name;
	}

	return $kleo_post_types;
}


if ( ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] == 'true' )
     || isset( $_POST[ "kleo_" . KLEO_DOMAIN ]['defaults-section'] )
     || isset( $_POST[ "kleo_" . KLEO_DOMAIN ]['defaults'] )
) {
	kleo_write_dynamic_css_file();
}

add_action( 'kleo-opts-saved', 'kleo_write_dynamic_css_file' );
add_action( 'kleo-opts-saved', 'kleo_check_rewrite_permalinks', 10, 2 );
add_action( 'kleo-opts-saved', 'kleo_after_save_opts' );
add_action( 'customize_save_after', 'kleo_write_dynamic_css_file', 100 );
add_action( 'customize_save_after', 'kleo_save_customizer_options' );

/* Generate styling for customizer preview only */
if ( is_customize_preview() ) {
	if ( isset( $_POST['customized'] ) && ! empty( $_POST['customized'] ) && $_POST['customized'] != '{}' ) {
		add_filter( 'kleo_options', 'kleo_customizer_override_options' );
		add_action( 'wp_head', 'kleo_custom_head_css', 998 );
	}
}

/**
 * Flush permalinks on theme options save and portfolio activation
 *
 * @param $options
 * @param $saved_options
 */
function kleo_check_rewrite_permalinks( $options, $saved_options ) {

	foreach ( $saved_options as $k => $v ) {
		if ( 'module_portfolio' == $k ) {
			global $wp_rewrite;
			$wp_rewrite->flush_rules( false );
		}
	}
}


function kleo_after_save_opts() {
	$current_options          = get_option( 'kleo_' . KLEO_DOMAIN );
	$current_options['stime'] = time();
	update_option( 'kleo_' . KLEO_DOMAIN, $current_options );

	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
}


function kleo_customizer_override_options( $options ) {

	if ( isset( $_POST['customized'] ) && ! empty( $_POST['customized'] ) && $_POST['customized'] != '{}' ) {
		$changed_options = json_decode( wp_unslash( $_POST['customized'] ), true );

		$new_options = array();

		foreach ( $changed_options as $key => $option ) {
			$replaced_key                 = rtrim( ltrim( str_replace( 'kleo_' . KLEO_DOMAIN, '', $key ), '[' ), ']' );
			$new_options[ $replaced_key ] = $option;
		}

		return array_merge( $options, $new_options );
	}

	return $options;
}


function kleo_save_customizer_options( $wp_customize ) {

	$options     = json_decode( stripslashes_deep( $_POST['customized'] ), true );
	$opt_name    = 'kleo_' . KLEO_DOMAIN;
	$old_options = get_option( 'kleo_' . KLEO_DOMAIN );
	$changed     = false;

	if ( ! empty ( $old_options ) ) {
		foreach ( $options as $key => $value ) {
			if ( strpos( $key, $opt_name ) !== false ) {
				$key = str_replace( $opt_name . '[', '', rtrim( $key, "]" ) );

				if ( ! isset ( $old_options[ $key ] ) || $old_options[ $key ] != $value || ( isset( $old_options[ $key ] ) && ! empty( $old_options[ $key ] ) && empty( $value ) ) ) {
					$old_options[ $key ] = $value;
					$changed             = true;
				}
			}
		}

		if ( $changed ) {
			$old_options['stime'] = time();
			update_option( $opt_name, $old_options );
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}
}


/* Customizer register */
add_action( 'customize_register', 'kleo_register_customizer_controls' ); // Create controls


class KleoOptions {

	public $opt_name = '';

	public $args = array();
	public $options = array();
	public $sections = array();
	public $style_sections = array();

	public function __construct( $args, $sections, $options ) {
		$this->options  = $options;
		$this->args     = $args;
		$this->sections = $sections;
		$this->opt_name = 'kleo_' . KLEO_DOMAIN;
	}
}

global $kleo_opts_class, $kleo_options;
$kleo_opts_class = new KleoOptions( $args, $sections, $kleo_options );


function kleo_register_customizer_controls( $wp_customize ) {
	global $kleo_opts_class;
	$customizer_sections = $kleo_opts_class->sections;

	// All sections, settings, and controls will be added here
	$order = array(
		'heading' => - 500,
		'option'  => - 500,
	);
	$panel = "";

	foreach ( $customizer_sections as $key => $section ) {

		// If section customizer enabled
		if ( isset( $section['kleo_customizer'] ) && $section['kleo_customizer'] == true ) {
			$section_enabled = true;
		} else {
			$section_enabled = false;
		}

		if ( $section_enabled === false ) {
			continue;
		}

		if ( isset( $section['id'] ) && $section['id'] == "import/export" ) {
			continue;
		}

		// Not a type that should go on the customizer
		if ( empty( $section['fields'] ) || ( isset( $section['type'] ) && $section['type'] == "divide" ) ) {
			continue;
		}

		// Evaluate section permissions
		if ( isset( $section['permissions'] ) ) {
			if ( ! current_user_can( $section['permissions'] ) ) {
				continue;
			}
		}

		// No errors please
		if ( ! isset( $section['desc'] ) ) {
			$section['desc'] = "";
		}

		// Fill the description if there is a subtitle
		if ( empty( $section['desc'] ) && ! empty( $section['subtitle'] ) ) {
			$section['desc'] = $section['subtitle'];
		}

		// Let's make a section ID from the title
		if ( empty( $section['id'] ) ) {
			$section['id'] = strtolower( str_replace( " ", "", $section['title'] ) );
		}


		// Let's set a default priority
		if ( empty( $section['priority'] ) ) {
			$section['priority'] = $order['heading'];
			$order['heading'] ++;
		}

		if ( method_exists( $wp_customize, 'add_panel' ) && ( ! isset( $section['subsection'] ) || ( isset( $section['subsection'] ) && $section['subsection'] != true ) ) && isset( $customizer_sections[ ( $key + 1 ) ]['subsection'] ) && $customizer_sections[ ( $key + 1 ) ]['subsection'] ) {

			$wp_customize->add_panel( $section['id'], array(
				'priority'       => $section['priority'],
				'capability'     => 'customize',
				'theme_supports' => '',
				'title'          => $section['title'],
				'description'    => $section['desc'],
			) );
			$panel = $section['id'];

			$wp_customize->add_section( $section['id'], array(
				'title'       => $section['title'],
				'priority'    => $section['priority'],
				'description' => $section['desc'],
				'panel'       => $panel
			) );


		} else {
			if ( ! isset( $section['subsection'] ) || ( isset( $section['subsection'] ) && $section['subsection'] != true ) ) {
				$panel = "";
			}
			$wp_customize->add_section( $section['id'], array(
				'title'       => $section['title'],
				'priority'    => $section['priority'],
				'description' => $section['desc'],
				'panel'       => $panel
			) );
		}

		foreach ( $section['fields'] as $skey => $option ) {

			// Evaluate section permissions
			if ( isset( $option['permissions'] ) ) {
				if ( ! current_user_can( $option['permissions'] ) ) {
					continue 2;
				}
			}
			if ( isset( $option['validate'] ) && $option['validate'] != false ) {
				continue 2;
			}

			if ( isset( $option['validate_callback'] ) && ! empty( $option['validate_callback'] ) ) {
				continue 2;
			}

			if ( isset( $option['customizer'] ) && $option['customizer'] === false ) {
				continue 2;
			}

			//Change the item priority if not set
			if ( $option['type'] != 'heading' && ! isset( $option['priority'] ) ) {
				$option['priority'] = $order['option'];
				$order['option'] ++;
			}

			if ( isset( $kleo_opts_class->options[ $option['id'] ] ) ) {
				$option['default'] = $kleo_opts_class->options[ $option['id'] ];
			} else {
				$option['default'] = '';
			}

			if ( ! isset( $option['title'] ) ) {
				$option['title'] = "";
			}

			// Wordpress doesn't support multi-select
			if ( $option['type'] == "select" && isset( $option['multi'] ) && $option['multi'] == true ) {
				continue 2;
			}

			$option['id'] = $kleo_opts_class->opt_name . '[' . $option['id'] . ']';

			if ( $option['type'] != "heading" && $option['type'] != "import_export" && $option['type'] != "options_object" && ! empty( $option['type'] ) ) {
				$wp_customize->add_setting( $option['id'],
					array(
						'default'           => $option['default'],
						'type'              => 'option',
						'capabilities'      => 'edit_theme_options',
						//'capabilities'    => $this->parent->args['page_permissions'],
						'transport'         => isset( $option['customizer_post'] ) ? 'postMessage' : 'refresh',
						'theme_supports'    => '',
						//'sanitize_callback' => '__return_false',
						'sanitize_callback' => 'kleo_field_validation',
						//'sanitize_js_callback' =>array( &$parent, '_field_input' ),
					)
				);
			}


			switch ( $option['type'] ) {
				case 'heading':
					// We don't want to put up the section unless it's used by something visible in the customizer
					$section          = $option;
					$section['id']    = strtolower( str_replace( " ", "", $option['title'] ) );
					$order['heading'] = - 500;

					if ( ! empty( $option['priority'] ) ) {
						$section['priority'] = $option['priority'];
					} else {
						$section['priority'] = $order['heading'];
						$order['heading'] ++;
					}
					break;

				case 'text':
					if ( isset( $option['data'] ) && $option['data'] ) {
						break;
					}
					$wp_customize->add_control( $option['id'], array(
						'label'    => $option['title'],
						'section'  => $section['id'],
						'settings' => $option['id'],
						'priority' => $option['priority'],
						'type'     => 'text',
					) );
					break;

				case 'select':
				case 'button_set':
					if ( ! isset( $option['options'] ) ) {
						break;
					}

					$newOptions = array();
					foreach ( $option['options'] as $key => $value ) {
						if ( is_array( $value ) ) {
							foreach ( $value as $key => $v ) {
								$newOptions[] = $v;
							}

						}
					}

					if ( ! empty( $newOptions ) ) {
						$option['options'] = $newOptions;
					}

					if ( ( isset( $option['sortable'] ) && $option['sortable'] ) ) {
						break;
					}

					if ( ( isset( $option['multi'] ) && $option['multi'] ) ) {
						break;
					}

					$wp_customize->add_control( $option['id'], array(
						'label'    => $option['title'],
						'section'  => $section['id'],
						'settings' => $option['id'],
						'priority' => $option['priority'],
						'type'     => 'select',
						'choices'  => $option['options']
					) );
					break;

				case 'radio':
					//continue;
					$wp_customize->add_control( $option['id'], array(
						'label'    => $option['title'],
						'section'  => $section['id'],
						'settings' => $option['id'],
						'priority' => $option['priority'],
						'type'     => 'radio',
						'choices'  => $option['options']
					) );
					break;

				case 'checkbox':
					if ( ( isset( $option['data'] ) && $option['data'] ) || ( ( isset( $option['multi'] ) && $option['multi'] ) ) || ( ( isset( $option['options'] ) && ! empty( $option['options'] ) ) ) ) {
						break;
					}
					$wp_customize->add_control( $option['id'], array(
						'label'    => $option['title'],
						'section'  => $section['id'],
						'settings' => $option['id'],
						'priority' => $option['priority'],
						'type'     => 'checkbox',
					) );
					break;

				case 'media':
					break;
				/*$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, $option['id'], array(
					'label'    => $option['title'],
					'section'  => $section['id'],
					'settings' => $option['id'],
					'priority' => $option['priority']
				) ) );
				break;*/

				case 'color':
					$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $option['id'], array(
						'label'       => $option['title'],
						'section'     => $section['id'],
						'settings'    => $option['id'],
						'priority'    => $option['priority'],
						'description' => $option['desc']
					) ) );
					break;

				default:
					break;
			}
		}
	}
}


function kleo_field_validation( $value ) {
	return $value;
}
