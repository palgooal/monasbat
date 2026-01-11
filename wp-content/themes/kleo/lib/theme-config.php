<?php

$config = array(
	'theme_name' => 'KLEO',
	'theme_lower' => 'kleo',
	'slug' => 'sq-panel',
	'item_id' => SVQ_FW::get_config( 'item_id' ),
	'purchase_link' => 'https://themeforest.net/item/kleo-pro-community-focused-multipurpose-buddypress-theme/6776630?ref=SeventhQueen',
);
$config['priority_addons'] = [
	'k-elements',
	'buddypress',
];

/**
 * @param array $config
 * @return \Seventhqueen\Panel\Plugin
 */
function svq_panel( $config = null ) {
	if ( $config !== null ) {
		return \Seventhqueen\Panel\Plugin::instance( $config );
	}

	return \Seventhqueen\Panel\Plugin::instance();
}

svq_panel( $config );

/* demo import */
$remote_path = 'https://cdn.seventhqueen.com/sq-support/files/kleo/importer/';

$demo_config = array(
	'theme_name' => 'KLEO',
	'theme_lower' => 'kleo',
	'core_plugin' => 'k-elements',
);

$pages_data = [];

$connected_fields = array(
	array(
		'field_group_id' => '1',
		'name'           => 'Skills',
		'can_delete'     => 1,
		'is_required'    => false,
		'type'           => 'selectbox',
		'options'        => array(
			'Web Designer',
			'PHP Developer',
			'SEO Guru',
			'Java Programmer',
		),
	),
	array(
		'field_group_id' => '1',
		'name'           => 'Country',
		'can_delete'     => 1,
		'is_required'    => false,
		'type'           => 'selectbox',
		'options'        => array(
			'USA',
			'United Kingdom',
			'India',
			'France',
			'Spain',
			'Romania',
			'Germany',
			'Other',
		),
	),
	array(
		'field_group_id' => '1',
		'name'           => 'Price rate',
		'can_delete'     => 1,
		'is_required'    => false,
		'type'           => 'textbox',
	),
);

$pages_data['home-travel'] = array(
	'name'    => 'Travel Destination (v4.2)',
	'slug'    => 'travel-destination',
	'img'     => $remote_path . 'img/home-travel-destination.jpg',
	'page'    => 'pages/home-travel-destination',
	'extra'   => array(

		array(
			'id'      => 'testimonials',
			'data'    => 'content/testimonials',
			'name'    => 'Import Dummy Testimonials',
			'checked' => true,
		),
	),
	'attach'  => 'yes',
	'plugins' => array(
		'js_composer',
		'k-elements',
		'mailchimp-for-wp'
	),
	//'details' => '',
	'link'    => 'https://seventhqueen.com/themes/kleo/travel-destination/',
);

$pages_data['home-sport'] = array(
	'name'    => 'Sport/Fitness (v4.2)',
	'slug'    => 'home-sport',
	'img'     => $remote_path . 'img/home-sport.jpg',
	'page'    => 'pages/home-sport',
	'attach'  => 'yes',
	'plugins' => array( 'js_composer', 'k-elements' ),
	'options' => 'home-sport',
	'link'    => 'https://seventhqueen.com/themes/kleo/sport/',
);

$pages_data['home-medical'] = array(
	'name'      => 'Home Medical (v4.2)',
	'slug'      => 'home-medical',
	'img'       => $remote_path . 'img/home-medical.jpg',
	'page'      => 'pages/home-medical',
	'attach'    => 'yes',
	'plugins'   => array( 'js_composer', 'k-elements', 'revslider', 'contact-form-7' ),
	'revslider' => 'kleo_medical',
	'options'   => 'home-medical',
	'extra'     => array(
		array(
			'id'      => 'menu',
			'name'    => 'Import Menu',
			'slug'    => 'kleo-medical',
			'location'    => [ 'primary' => 'kleo-medical'],
			'data'    => 'content/menu-medical',
			'checked' => true,
		),
		array(
			'id'      => 'posts',
			'name'    => 'Import Posts',
			'data'    => 'content/posts-medical',
			'checked' => true,
		),
	),
	'link'      => 'http://seventhqueen.com/themes/kleo/medical/',
);
$pages_data['home-company'] = array(
	'name'    => 'Home Company (v4.0)',
	'slug'    => 'home-company',
	'img'     => $remote_path . 'img/home-company.jpg',
	'page'    => 'pages/home-company',
	'attach'  => 'yes',
	'plugins' => array( 'js_composer', 'k-elements', 'mailchimp-for-wp' ),
	//'revslider' => '',
	'link'    => 'http://seventhqueen.com/themes/kleo/company/',
);
$pages_data['home-food']    = array(
	'name'   => 'Home Food (v4.0)',
	'slug'   => 'home-food',
	'img'    => $remote_path . 'img/home-food.jpg',
	'page'   => 'pages/home-food',
	'attach' => 'yes',
	'plugins' => array( 'js_composer', 'k-elements' ),
	//'revslider' => '',
	'link'   => 'http://seventhqueen.com/themes/kleo/food/',
);

$pages_data['home-register'] = array(
	'name'   => 'Home Register Landing Page (v4.0)',
	'slug'   => 'home-register',
	'img'    => $remote_path . 'img/home-register.jpg',
	'page'   => 'pages/home-register',
	'attach' => 'yes',
	'plugins' => array( 'js_composer', 'k-elements' ),
	//'revslider' => '',
	'link'   => 'http://seventhqueen.com/themes/kleo/home-register/',
);

$pages_data['home-community']              = array(
	'name'             => 'Home Default(Community)',
	'slug'             => 'home-community',
	'img'              => $remote_path . 'img/home-community.jpg',
	'page'             => 'pages/home-community',
	//'attach' => 'yes',
	'widgets'          => 'widget_data',
	'widgets_sidebars' => true,
	'plugins'          => array( 'js_composer', 'k-elements', 'revslider', 'buddypress' ),
	'revslider'        => 'HomeFullwidth',
	'extra'            => array(
		array(
			'id'      => 'menu',
			'name'    => 'Import Menu',
			'slug'    => 'kleonavmenu',
			'location'    => [ 'primary' => 'kleonavmenu', 'top' => 'kleotopmenu' ],
			'data'    => 'content/menu-community',
			'checked' => true,
		),
		array(
			'id'      => 'clients',
			'data'    => 'content/clients',
			'name'    => 'Import Clients',
			'checked' => true,
		),
	),
	'link'             => 'http://seventhqueen.com/themes/kleo/home-default/',
);
$pages_data['home-pinterest']              = array(
	'name'  => 'Home Pinterest',
	'slug'  => 'home-pinterest',
	'img'   => $remote_path . 'img/home-pinterest.jpg',
	'page'  => 'pages/home-pinterest',
	//'attach' => 'yes',
	'plugins'          => array( 'js_composer', 'k-elements' ),
	//'revslider' => '',
	'extra' => array(
		array(
			'id'      => 'posts',
			'data'    => 'content/posts',
			'name'    => 'Import Posts',
			'checked' => true,
		),
	),
	'link'  => 'http://seventhqueen.com/themes/kleo/pinterest/',
);
$pages_data['home-news-magazine']          = array(
	'name'      => 'Home News Magazine',
	'slug'      => 'news-magazine',
	'img'       => $remote_path . 'img/home-news-magazine.jpg',
	'page'      => 'pages/home-news-magazine',
	'extra'     => array(
		array(
			'id'      => 'posts',
			'data'    => 'content/posts-news',
			'name'    => 'Import Posts',
			'checked' => true,
		),
		array(
			'id'      => 'menu',
			'name'    => 'Import Menu',
			'slug'    => 'kleonavmenu',
			'location'    => [ 'primary' => 'kleonavmenu', 'top' => 'kleotopmenu' ],
			'data'    => 'content/menu-community',
			'checked' => true,
		),
	),
	'attach'    => 'yes',
	'plugins'   => array( 'js_composer', 'k-elements', 'revslider' ),
	'revslider' => 'news-magazine',
	'link'      => 'http://seventhqueen.com/themes/kleo/news-magazine/',
);
$pages_data['home-material']               = array(
	'name'      => 'Home Material Design',
	'slug'      => 'home-material-design',
	'img'       => $remote_path . 'img/home-material-design.jpg',
	'page'      => 'pages/home-material',
	'attach'    => 'yes',
	'extra'     => array(
		array(
			'id'      => 'testimonials',
			'data'    => 'content/testimonials',
			'name'    => 'Import Dummy Testimonials',
			'checked' => true,
		),
	),
	'plugins'   => array( 'js_composer', 'k-elements', 'revslider' ),
	'revslider' => 'material-design',
	'options'   => 'home-material-design',
	'link'      => 'http://seventhqueen.com/themes/kleo/material-design-colors/',
);
$pages_data['home-get-connected']          = array(
	'name'       => 'Home Get Connected',
	'slug'       => 'get-connected',
	'img'        => $remote_path . 'img/home-get-connected.jpg',
	'page'       => 'pages/home-get-connected',
	'attach'     => 'yes',
	'plugins'    => array( 'js_composer', 'k-elements', 'buddypress', 'bp-profile-search' ),
	'bp_fields'  => $connected_fields,
	'post_types' => array( 'bps_form' ),
	//'revslider' => '',
	//'details' => '',
	'link'       => 'http://seventhqueen.com/themes/kleo/get-connected',
);
$pages_data['home-get-connected-vertical'] = array(
	'name'       => 'Home Get Connected Vertical',
	'slug'       => 'get-connected-vertical-form',
	'img'        => $remote_path . 'img/home-get-connected-vertical.jpg',
	'page'       => 'pages/home-get-connected-vertical',
	'attach'     => 'yes',
	'plugins'    => array( 'js_composer', 'k-elements', 'buddypress', 'bp-profile-search' ),
	'bp_fields'  => $connected_fields,
	'post_types' => array( 'bps_form' ),
	'link'       => 'http://seventhqueen.com/themes/kleo/get-connected-vertical-form/',
);
$pages_data['home-product-landing']        = array(
	'name'    => 'Home Product Landing Page',
	'slug'    => 'product-landing-page',
	'img'     => $remote_path . 'img/home-product-landing-page.jpg',
	'page'    => 'pages/home-product-landing',
	'attach'  => 'yes',
	'plugins' => array( 'js_composer', 'k-elements', 'woocommerce' ),
	'link'    => 'http://seventhqueen.com/themes/kleo/product-landing-page/',
);
$pages_data['home-mobile-app']             = array(
	'name'      => 'Home Mobile APP',
	'slug'      => 'home-mobile-app',
	'img'       => $remote_path . 'img/home-mobile-app.jpg',
	'page'      => 'pages/home-mobile-app',
	'attach'    => 'yes',
	'plugins'   => array( 'js_composer', 'k-elements', 'revslider' ),
	'revslider' => 'mobile-app',
	'link'      => 'http://seventhqueen.com/themes/kleo/mobile-app/',
);
$pages_data['home-resume']                 = array(
	'name'   => 'Home Resume',
	'slug'   => 'home-resume',
	'img'    => $remote_path . 'img/home-resume.jpg',
	'page'   => 'pages/home-resume',
	'attach' => 'yes',
	'plugins'   => array( 'js_composer', 'k-elements' ),
	//'revslider' => 'mobile-app',
	'link'   => 'http://seventhqueen.com/themes/kleo/resume/',
);
$pages_data['home-sensei']                 = array(
	'name'      => 'Home Sensei',
	'slug'      => 'home-sensei',
	'img'       => $remote_path . 'img/home-sensei.jpg',
	'page'      => 'pages/home-sensei',
	'attach'    => 'yes',
	'plugins'   => array(  'js_composer', 'k-elements', 'revslider', 'sensei', 'mailchimp-for-wp' ),
	'revslider' => 'elearning_homepage',
	'extra'     => array(
		array(
			'id'      => 'courses',
			'data'    => 'content/sensei',
			'name'    => 'Import Sample Courses, Lessons and Quizzes',
			'checked' => true,
		),
	),
	'details'   => '',
	'link'      => 'http://seventhqueen.com/themes/kleo/sensei-e-learning/',
);
$pages_data['home-elearning']              = array(
	'name'      => 'Home e-Learning',
	'slug'      => 'home-e-learning',
	'img'       => $remote_path . 'img/home-elearning.jpg',
	'page'      => 'pages/home-elearning',
	'attach'    => 'yes',
	'plugins'   => array(  'js_composer', 'k-elements', 'revslider', 'mailchimp-for-wp' ),
	'revslider' => 'elearning_homepage',
	'extra'     => array(
		array(
			'id'      => 'posts',
			'data'    => 'content/posts',
			'name'    => 'Import Sample Posts',
			'checked' => true,
		),
		array(
			'id'      => 'testimonials',
			'data'    => 'content/testimonials',
			'name'    => 'Import Sample Testimonials',
			'checked' => true,
		),
	),
	'link'      => 'http://seventhqueen.com/themes/kleo/e-learning-home/',
);
$pages_data['home-geodirectory-v2']           = [
	'name'    => 'Home Geodirectory V2',
	'slug'    => 'directory-home-v2',
	'img'     => $remote_path . 'img/home-geodirectory.jpg',
	'page'    => 'pages/home-geodirectory-v2',
	//'widgets' => 'widget_data_geodirectory',
	//'attach' => 'yes',
	'plugins' => array( 'geodirectory' ),
	//'revslider' => '',
	'details' => 'Please read the <a target="_blank" href="https://wpgeodirectory.com/docs-v2/geodirectory/getting-started/">documentation</a>',
	'link'    => 'http://seventhqueen.com/themes/kleo/business-directory/',
];

$pages_data['home-portfolio-full']         = array(
	'name'      => 'Home Portfolio Full-Width',
	'slug'      => 'portfolio-fullwidth-overlay',
	'img'       => $remote_path . 'img/home-portfolio-full.jpg',
	'page'      => 'pages/home-portfolio-full',
	'extra'     => array(
		array(
			'id'      => 'portfolio',
			'data'    => 'content/portfolio',
			'name'    => 'Import Portfolio posts',
			'checked' => true,
		),
	),
	//'widgets' => 'yes',
	//'attach' => 'yes',
	'plugins'   => array(  'js_composer', 'k-elements', 'revslider' ),
	'revslider' => 'HomeFullwidth',
	'link'      => 'http://seventhqueen.com/themes/kleo/portfolio-full-width/',
);
$pages_data['home-shop']                   = array(
	'name'             => 'Home Shop',
	'slug'             => 'home-shop',
	'img'              => $remote_path . 'img/home-shop.jpg',
	'page'             => 'pages/home-shop',
	'extra'            => array(
		array(
			'id'   => 'products',
			'data' => 'content/products-dummy',
			'name' => 'Import Dummy Products',
		),
	),
	'widgets'          => 'widget_data',
	'widgets_sidebars' => true,
	'attach'           => 'yes',
	'plugins'          => array( 'js_composer', 'k-elements', 'revslider', 'woocommerce' ),
	'revslider'        => 'HomeFullwidth',
	//'details' => 'Woocommerce plugin required.',
	'link'             => 'http://seventhqueen.com/themes/kleo/default-shop/',
);
$pages_data['home-stylish-woo']            = array(
	'name'    => 'Home Stylish Woocommerce',
	'slug'    => 'stylish-woocommerce',
	'img'     => $remote_path . 'img/home-stylish-woo.jpg',
	'page'    => 'pages/home-stylish-woo',
	'extra'   => array(
		array(
			'id'      => 'clients',
			'data'    => 'content/clients',
			'name'    => 'Import Dummy clients',
			'checked' => true,
		),
		array(
			'id'      => 'portfolio',
			'data'    => 'content/portfolio',
			'name'    => 'Import Portfolio posts',
			'checked' => true,
		),
		array(
			'id'      => 'products',
			'data'    => 'content/products-dummy',
			'name'    => 'Import Dummy Products',
			'checked' => true,
		),

	),
	'attach'  => 'yes',
	'plugins' => array( 'js_composer', 'k-elements', 'woocommerce', 'mailchimp-for-wp' ),
	//'details' => '',
	'link'    => 'http://seventhqueen.com/themes/kleo/stylish-woocommerce/',
);
$pages_data['home-agency']                 = array(
	'name'      => 'Home Agency',
	'slug'      => 'home-agency',
	'img'       => $remote_path . 'img/home-agency.jpg',
	'page'      => 'all-agency',
	'extra'     => array(
		array(
			'id'      => 'posts',
			'data'    => 'content/posts',
			'name'    => 'Import Sample Posts',
			'checked' => true,
		),
		array(
			'id'      => 'clients',
			'data'    => 'content/clients',
			'name'    => 'Import Dummy clients',
			'checked' => true,
		),
	),
	//'widgets' => 'yes',
	'attach'    => 'yes',
	'plugins'   => array( 'js_composer', 'k-elements', 'revslider', 'mailchimp-for-wp', 'contact-form-7' ),
	'revslider' => array( 'agency-home', 'agency-careers', 'agency-services' ),
	'link'      => 'http://seventhqueen.com/themes/kleo/demo-agency/',
);
$pages_data['home-simple']                 = array(
	'name'      => 'Home Simple',
	'slug'      => 'home-simple',
	'img'       => $remote_path . 'img/home-simple.jpg',
	'page'      => 'pages/home-simple',
	'extra'     => array(
		array(
			'id'      => 'clients',
			'data'    => 'content/clients',
			'name'    => 'Import Dummy clients',
			'checked' => true,
		),
		array(
			'id'      => 'menu',
			'name'    => 'Import Menu',
			'slug'    => 'kleonavmenu',
			'location'    => [ 'primary' => 'kleonavmenu', 'top' => 'kleotopmenu' ],
			'data'    => 'content/menu-community',
			'checked' => true,
		),
	),
	//'widgets' => 'yes',
	'attach'    => 'yes',
	'plugins'   => array( 'js_composer', 'k-elements', 'revslider' ),
	'revslider' => 'home-simple',
	'link'      => 'http://seventhqueen.com/themes/kleo/home/',
);
$pages_data['home-onepage']                = array(
	'name'      => 'Home OnePage - KLEO demo (v2)',
	'img'       => $remote_path . 'img/home-onepage.jpg',
	'page'      => 'pages/home-onepage',
	'extra'     => array(
		array(
			'id'      => 'menu',
			'slug'    => 'one-page-menu',
			'data'    => 'content/menu-onepage',
			'name'    => 'Import Menu',
			'checked' => true,
		),
	),
	//'widgets' => 'yes',
	'attach'    => 'yes',
	'plugins'   => array( 'js_composer', 'k-elements', 'revslider' ),
	'revslider' => 'lp-home-full-screen',
	'link'      => 'http://seventhqueen.com/themes/kleo/one-page-presentation/',
);
$pages_data['home-onepage-v3']             = array(
	'name'      => 'Home OnePage - KLEO demo (v3)',
	'img'       => $remote_path . 'img/rev-onepage-v3.jpg',
	//'page' => 'pages/home-onepage',
	//'widgets' => 'yes',
	//'attach' => 'yes',
	'revslider' => 'landingpage_v3_0_beta',
	'link'      => 'https://seventhqueen.com/themes/kleo/connecting-people-slider/',
	'details'   => 'Just Revslider to import.',
);
$pages_data['home-geodirectory']           = [
	'name'    => 'Home Geodirectory V1(old)',
	'slug'    => 'directory',
	'img'     => $remote_path . 'img/home-geodirectory.jpg',
	'page'    => 'pages/home-geodirectory',
	'widgets' => 'widget_data_geodirectory',
	//'attach' => 'yes',
	'plugins' => array( 'geodirectory' ),
	//'revslider' => '',
	'details' => 'Please read the <a target="_blank" href="http://seventhqueen.com/support/documentation/kleo#geo-directory">documentation</a>',
	'link'    => 'http://seventhqueen.com/themes/kleo/business-directory/',
];

$demo_config['data_set'] = $pages_data;

\Seventhqueen\Panel\Importer::getInstance( $demo_config );

add_action( 'seventhqueen/import/after_import_options', 'svq_generate_dynamic_css' );
