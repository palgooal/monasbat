<?php
/**
 * Include and setup custom metaboxes and fields.
 *
 */

add_filter( 'kleo_meta_boxes', 'kleo_metaboxes' );
/**
 * Define the metabox and field configurations.
 *
 * @param  array $meta_boxes
 *
 * @return array
 */
function kleo_metaboxes( array $meta_boxes ) {

	// Start with an underscore to hide fields from custom fields list
	$prefix = '_kleo_';

	$meta_boxes['general_settings'] = array(
		'id'         => 'general_settings',
		'title'      => esc_html__( 'Theme General settings', 'kleo' ),
		'pages'      => apply_filters( 'sq_metabox_general_settings', array( 'post', 'page' ) ), // Post type
		'context'    => 'normal',
		'priority'   => 'default',
		'show_names' => true, // Show field names on the left
		'fields'     => array(
			array(
				'name' => 'Display settings',
				'desc' => '',
				'id'   => 'kleodisplay',
				'type' => 'tab',
			),
			array(
				'name'    => esc_html__( 'Site Layout', 'kleo' ),
				'desc'    => esc_html__( 'Override default site layout', 'kleo' ),
				'id'      => $prefix . 'site_style',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => 'Default' ),
					array( 'value' => 'wide', 'name' => 'Wide' ),
					array( 'value' => 'boxed', 'name' => 'Boxed' ),
				),
				'value'   => '',
			),
			array(
				'name'  => esc_html__( 'Centered text', 'kleo' ),
				'desc'  => esc_html__( 'Check to have centered text on this page', 'kleo' ),
				'id'    => $prefix . 'centered_text',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name'    => esc_html__( 'Top bar status', 'kleo' ),
				'desc'    => esc_html__( 'Enable/disable site top bar', 'kleo' ),
				'id'      => $prefix . 'topbar_status',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => 'Default' ),
					array( 'value' => '1', 'name' => 'Visible' ),
					array( 'value' => '0', 'name' => 'Hidden' ),
				),
				'value'   => '',
			),
			array(
				'name'  => esc_html__( 'Hide Header', 'kleo' ),
				'desc'  => esc_html__( 'Check to hide whole header area', 'kleo' ),
				'id'    => $prefix . 'hide_header',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name'  => esc_html__( 'Hide Footer', 'kleo' ),
				'desc'  => esc_html__( 'Check to hide whole footer area', 'kleo' ),
				'id'    => $prefix . 'hide_footer',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name'  => esc_html__( 'Hide Socket area', 'kleo' ),
				'desc'  => esc_html__( 'Check to hide the area after footer that contains copyright info.', 'kleo' ),
				'id'    => $prefix . 'hide_socket',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name' => esc_html__( 'Custom Logo', 'kleo' ),
				'desc' => esc_html__( 'Use a custom logo for this page only', 'kleo' ),
				'id'   => $prefix . 'logo',
				'type' => 'file',
			),
			array(
				'name' => esc_html__( 'Custom Logo Retina', 'kleo' ),
				'desc' => esc_html__( 'Use a custom retina logo for this page only. Make sure it is exact double in size(width,height) as the original logo.', 'kleo' ),
				'id'   => $prefix . 'logo_retina',
				'type' => 'file',
			),
            array(
				'name' => esc_html__( 'Custom Mobile Logo', 'kleo' ),
				'desc' => esc_html__( 'Use a custom mobile logo for this page and mobile devices only ', 'kleo' ),
				'id'   => $prefix . 'mobile_logo',
				'type' => 'file',
			),array(
				'name' => esc_html__( 'Custom Retina Mobile Logo', 'kleo' ),
				'desc' => esc_html__( 'Use a custom retina mobile logo for this page and mobile devices only ', 'kleo' ),
				'id'   => $prefix . 'mobile_logo_retina',
				'type' => 'file',
			),
			array(
				'name'  => esc_html__( 'Main Menu Full Width', 'kleo' ),
				'desc'  => esc_html__( 'Check to enable full browser width menu style.', 'kleo' ),
				'id'    => $prefix . 'menu_full_width',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name'  => esc_html__( 'Transparent Header', 'kleo' ),
				'desc'  => esc_html__( 'Check to have Main Header with transparent background.', 'kleo' ),
				'id'    => $prefix . 'transparent_menu',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name'    => esc_html__( 'Transparent Header - Menu color', 'kleo' ),
				'desc'    => '',
				'id'      => $prefix . 'transparent_menu_color',
				'type'    => 'select',
				'options' => array(
					array( 'value' => 'white', 'name' => esc_html__( 'White Text', 'kleo' ) ),
					array( 'value' => 'black', 'name' => esc_html__( 'Black Text', 'kleo' ) ),
				),
				'value'   => 'white',
			),
			array(
				'name'    => esc_html__( 'Social share', 'kleo' ),
				'desc'    => esc_html__( 'Display social share at bottom of the single page.', 'kleo' ),
				'id'      => $prefix . 'blog_social_share',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => esc_html__( 'Default', 'kleo' ) ),
					array( 'value' => '1', 'name' => esc_html__( 'Visible', 'kleo' ) ),
					array( 'value' => '0', 'name' => esc_html__( 'Hidden', 'kleo' ) ),
				),
				'value'   => '',
			),


			array(
				'name' => esc_html__( 'Title section', 'kleo' ),
				'desc' => '',
				'id'   => 'kleoheader',
				'type' => 'tab',
			),
			array(
				'name'    => esc_html__( 'Section Layout', 'kleo' ),
				'desc'    => '',
				'id'      => $prefix . 'title_layout',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => esc_html__( 'Default', 'kleo' ) ),
					array( 'value' => 'regular', 'name' => esc_html__( 'Regular', 'kleo' ) ),
					array( 'value' => 'center', 'name' => esc_html__( 'Centered', 'kleo' ) ),
					array( 'value' => 'right_breadcrumb', 'name' => esc_html__( 'Right Breadcrumb', 'kleo' ) ),
				),
				'value'   => '',
			),
			array(
				'name' => esc_html__( 'Custom page title', 'kleo' ),
				'desc' => esc_html__( 'Set a custom page title here if you need.', 'kleo' ),
				'id'   => $prefix . 'custom_title',
				'type' => 'text',
			),
			array(
				'name'  => esc_html__( 'Hide the title', 'kleo' ),
				'desc'  => esc_html__( 'Check to hide the title when displaying the post/page', 'kleo' ),
				'id'    => $prefix . 'title_checkbox',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name'    => esc_html__( 'Breadcrumb', 'kleo' ),
				'desc'    => '',
				'id'      => $prefix . 'hide_breadcrumb',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => esc_html__( 'Default', 'kleo' ) ),
					array( 'value' => '0', 'name' => esc_html__( 'Visible', 'kleo' ) ),
					array( 'value' => '1', 'name' => esc_html__( 'Hidden', 'kleo' ) ),
				),
				'value'   => '',
			),
			array(
				'name'  => esc_html__( 'Hide information', 'kleo' ),
				'desc'  => esc_html__( 'Check to hide contact info in title section', 'kleo' ),
				'id'    => $prefix . 'hide_info',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name' => esc_html__( 'Top Padding', 'kleo' ),
				'desc' => 'Put a value without px. Example: 20<br>Default value is taken from Theme options - Header - Title/Breadcrumb section',
				'id'   => $prefix . 'title_top_padding',
				'type' => 'text',
			),
			array(
				'name' => esc_html__( 'Bottom Padding', 'kleo' ),
				'desc' => 'Put a value without px. Example: 20<br>Default value is taken from Theme options - Header - Title/Breadcrumb section',
				'id'   => $prefix . 'title_bottom_padding',
				'type' => 'text',
			),
			array(
				'name'  => esc_html__( 'Text Color', 'kleo' ),
				'desc'  => esc_html__( 'Override the default text color as set in Theme options - Styling options - Title', 'kleo' ),
				'id'    => $prefix . 'title_color',
				'type'  => 'colorpicker',
				'value' => '',
			),
			array(
				'name'       => esc_html__( 'Background Image', 'kleo' ),
				'desc'       => esc_html__( 'Choose a background image for the section.', 'kleo' ),
				'id'         => $prefix . 'title_bg',
				'type'       => 'file',
				'bg_options' => 'yes',
			),
			array(
				'name'  => esc_html__( 'Background Color', 'kleo' ),
				'desc'  => esc_html__( 'If an image is not set the color will be used', 'kleo' ),
				'id'    => $prefix . 'title_bg_color',
				'type'  => 'colorpicker',
				'value' => '',
			),

			array(
				'name' => esc_html__( 'Media', 'kleo' ),
				'desc' => '',
				'id'   => 'kleomedia',
				'type' => 'tab',
			),
			array(
				'name'    => esc_html__( 'Show media on post page', 'kleo' ),
				'desc'    => esc_html__( 'If you want to show image/gallery/video/audio before the post on single page', 'kleo' ),
				'id'      => $prefix . 'post_media_status',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => esc_html__( 'Default', 'kleo' ) ),
					array( 'value' => '1', 'name' => esc_html__( 'Yes', 'kleo' ) ),
					array( 'value' => '0', 'name' => esc_html__( 'No', 'kleo' ) ),
				),
				'value'   => '',
			),
			array(
				'name'  => esc_html__( 'Slider', 'kleo' ),
				'desc'  => esc_html__( 'Used when you select the Gallery format. Upload an image or enter an URL.', 'kleo' ),
				'id'    => $prefix . 'slider',
				'type'  => 'file_repeat',
				'allow' => 'url',
			),
			array(
				'name' => esc_html__( 'Video oEmbed URL', 'kleo' ),
				'desc' => 'Used when you select Video format. Enter a Youtube, Vimeo, Soundcloud, etc URL. See supported services at <a target="_blank" href="http://codex.wordpress.org/Embeds">http://codex.wordpress.org/Embeds</a>.',
				'id'   => $prefix . 'embed',
				'type' => 'oembed',
			),

			array(
				'name' => esc_html__( 'Video Self hosted(mp4)', 'kleo' ),
				'desc' => 'Used when you select Video format. Upload your MP4 video file. Setting a self hosted video will ignore Video oEmbed above.',
				'id'   => $prefix . 'video_mp4',
				'type' => 'file',
			),
			array(
				'name' => esc_html__( 'Video Self hosted(ogv)', 'kleo' ),
				'desc' => 'Used when you select Video format. Upload your OGV video file.',
				'id'   => $prefix . 'video_ogv',
				'type' => 'file',
			),
			array(
				'name' => esc_html__( 'Video Self hosted(webm)', 'kleo' ),
				'desc' => 'Used when you select Video format. Upload your WEBM video file.',
				'id'   => $prefix . 'video_webm',
				'type' => 'file',
			),
			array(
				'name' => esc_html__( 'Video Self hosted Poster', 'kleo' ),
				'desc' => 'Used to show before the video loads',
				'id'   => $prefix . 'video_poster',
				'type' => 'file',
			),

			array(
				'name' => esc_html__( 'Audio', 'kleo' ),
				'desc' => 'Used when you select Audio format. Upload your audio file',
				'id'   => $prefix . 'audio',
				'type' => 'file',
			),
		),
	);

	$meta_boxes[] = array(
		'id'         => 'post_meta',
		'title'      => 'Theme Post Settings',
		'pages'      => apply_filters( 'sq_metabox_post_settings', array( 'post' ) ), // Post type
		'context'    => 'normal',
		'priority'   => 'default',
		'show_names' => true, // Show field names on the left
		'fields'     => array(

			array(
				'name'  => esc_html__( 'Hide post meta', 'kleo' ),
				'desc'  => 'Check to hide the post meta when displaying a post',
				'id'    => $prefix . 'meta_checkbox',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name'    => esc_html__( 'Related posts', 'kleo' ),
				'desc'    => 'Display related posts at bottom of the single post display',
				'id'      => $prefix . 'related_posts',
				'type'    => 'select',
				'options' => array(
					array( 'value' => '', 'name' => esc_html__( 'Default', 'kleo' ) ),
					array( 'value' => '1', 'name' => esc_html__( 'Visible', 'kleo' ) ),
					array( 'value' => '0', 'name' => esc_html__( 'Hidden', 'kleo' ) ),
				),
				'value'   => '',
			),
		),
	);


	$meta_boxes[] = array(
		'id'         => 'post_layout',
		'title'      => esc_html__( 'Post Layout', 'kleo' ),
		'pages'      => apply_filters( 'sq_metabox_post_layout', array( 'post', 'product', 'portfolio' ) ), // Post type
		'context'    => 'side',
		'priority'   => 'default',
		'show_names' => false, // Show field names on the left
		'fields'     => array(

			array(
				'name'    => esc_html__( 'Post layout', 'kleo' ),
				'desc'    => '',
				'id'      => $prefix . 'post_layout',
				'type'    => 'select',
				'options' => array(
					array( 'value' => 'default', 'name' => esc_html__( 'Default', 'kleo' ) ),
					array( 'value' => 'right', 'name' => esc_html__( 'Right Sidebar', 'kleo' ) ),
					array( 'value' => 'left', 'name' => esc_html__( 'Left sidebar', 'kleo' ) ),
					array( 'value' => 'no', 'name' => esc_html__( 'Full width, no sidebar', 'kleo' ) ),
					array(
						'value' => '3lr',
						'name'  => esc_html__( '3 columns, Right and Left sidebars', 'kleo' ),
					),
					array( 'value' => '3ll', 'name' => esc_html__( '3 columns, 2 Left sidebars', 'kleo' ) ),
					array( 'value' => '3rr', 'name' => esc_html__( '3 columns, 2 Right sidebars', 'kleo' ) ),
				),
				'value'   => 'right',
			),


		),
	);

	$meta_boxes[] = array(
		'id'         => 'header_content',
		'title'      => esc_html__( 'Header content(optional)', 'kleo' ),
		'pages'      => array( 'post', 'page', 'product' ), // Post type
		'context'    => 'normal',
		'priority'   => 'default',
		'show_names' => false, // Show field names on the left
		'fields'     => array(

			array(
				'name' => esc_html__( 'Header content', 'kleo' ),
				'desc' => esc_html__( 'This will be displayed right after the menu. Shortcodes are allowed', 'kleo' ),
				'id'   => $prefix . 'header_content',
				'type' => 'textarea',
			),

		),
	);

	$meta_boxes[] = array(
		'id'         => 'bottom_content',
		'title'      => esc_html__( 'Bottom content(optional)', 'kleo' ),
		'pages'      => array( 'post', 'page', 'product' ), // Post type
		'context'    => 'normal',
		'priority'   => 'default',
		'show_names' => false, // Show field names on the left
		'fields'     => array(

			array(
				'name' => esc_html__( 'Bottom content', 'kleo' ),
				'desc' => esc_html__( 'This will be displayed right after the generated page content ends. Shortcodes are allowed', 'kleo' ),
				'id'   => $prefix . 'bottom_content',
				'type' => 'textarea',
			),

		),
	);

	$meta_boxes[] = array(
		'id'         => 'clients_metabox',
		'title'      => __( 'Clients - link', 'kleo' ),
		'pages'      => array( 'kleo_clients' ), // Post type
		'context'    => 'normal',
		'priority'   => 'default',
		'show_names' => false, // Show field names on the left
		'fields'     => array(
			array(
				'name' => 'Client link',
				'desc' => 'http://example.com',
				'id'   => $prefix . 'client_link',
				'type' => 'text',
			),
		),
	);

	$meta_boxes[] = array(
		'id'         => 'testimonials_metabox',
		'title'      => esc_html__( 'Testimonial - Author description', 'kleo' ),
		'pages'      => array( 'kleo-testimonials' ), // Post type
		'context'    => 'normal',
		'priority'   => 'high',
		'show_names' => false, // Show field names on the left
		'fields'     => array(
			array(
				'name' => esc_html__( 'Author description', 'kleo' ),
				'desc' => '',
				'id'   => $prefix . 'author_description',
				'type' => 'text',
			),
		),
	);

	//Custom menu
	$kleo_menus     = wp_get_nav_menus();
	$menu_options   = array();
	$menu_options[] = array( 'value' => 'default', 'name' => 'Site default' );
	foreach ( $kleo_menus as $menu ) {
		$menu_options[] = array( 'value' => $menu->slug, 'name' => $menu->name );
	}

	$meta_boxes[] = array(
		'id'         => 'page_menu',
		'title'      => esc_html__( 'Main menu options', 'kleo' ),
		'pages'      => apply_filters( 'sq_metabox_page_menu', array( 'page', 'post' ) ), // Post type
		'context'    => 'side',
		'priority'   => 'default',
		'show_names' => true, // Show field names on the left
		'fields'     => array(

			array(
				'name'    => esc_html__( 'Header Layout', 'kleo' ),
				'desc'    => '',
				'id'      => $prefix . 'header_layout',
				'type'    => 'select',
				'options' => array(
					array( 'value' => 'default', 'name' => 'Default' ),
					array( 'value' => 'normal', 'name' => 'Normal header' ),
					array( 'value' => 'extras', 'name' => 'Extras header' ),
					array( 'value' => 'split', 'name' => 'Split header' ),
					array( 'value' => 'lp', 'name' => 'LP header' ),
					array( 'value' => 'right_logo', 'name' => 'Right logo' ),
					array( 'value' => 'center_logo', 'name' => 'Center logo' ),
					array( 'value' => 'left_logo', 'name' => 'Left logo and menu' ),
				),
				'value'   => 'default',
			),

			array(
				'name'    => esc_html__( 'Primary menu', 'kleo' ),
				'desc'    => '',
				'id'      => $prefix . 'page_menu',
				'type'    => 'select',
				'options' => $menu_options,
				'value'   => 'default',
			),
			array(
				'name'    => esc_html__( 'Secondary menu', 'kleo' ),
				'desc'    => '',
				'id'      => $prefix . 'page_menu_secondary',
				'type'    => 'select',
				'options' => $menu_options,
				'value'   => 'default',
			),
			array(
				'name'  => esc_html__( 'Hide Shop', 'kleo' ),
				'desc'  => esc_html__( 'Check to hide the Shop icon in the main menu', 'kleo' ),
				'id'    => $prefix . 'hide_shop_icon',
				'type'  => 'checkbox',
				'value' => '1',
			),
			array(
				'name'  => esc_html__( 'Hide Search', 'kleo' ),
				'desc'  => 'Check to hide the Search icon in the main menu',
				'id'    => $prefix . 'hide_search_icon',
				'type'  => 'checkbox',
				'value' => '1',
			),

		),
	);


	// Add other metaboxes as needed

	return $meta_boxes;
}