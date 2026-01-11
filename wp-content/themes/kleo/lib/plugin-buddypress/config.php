<?php
/**
 * @package WordPress
 * @subpackage KLEO
 * @author SeventhQueen <themesupport@seventhqueen.com>
 * @since 1.6
 */

if ( bp_get_theme_package_id() === 'nouveau' || defined( 'BP_PLATFORM_VERSION' ) ) {
    require_once( KLEO_LIB_DIR . '/plugin-buddypress/nouveau/nouveau.php' );
} else {
    require_once( KLEO_LIB_DIR . '/plugin-buddypress/legacy/legacy.php' );
}

if ( bp_is_active( 'notifications' ) ) {
	require_once( KLEO_LIB_DIR . '/plugin-buddypress/menu-notifications.php' );
}

if ( bp_is_active( 'messages' ) ) {
	require_once( KLEO_LIB_DIR . '/plugin-buddypress/menu-messages.php' );
}

// Youzer compatibility
if ( function_exists( 'youzer' ) ||  function_exists( 'youzify' ) ) {
	require_once( KLEO_LIB_DIR . '/plugin-youzer/config.php' );
}

/***************************************************
 * :: Catch AJAX requests
 ***************************************************/

add_action( 'wp_ajax_kleo_bp_ajax_call', 'kleo_bp_ajax_call' );

function kleo_bp_ajax_call() {

	$response = array();
	$response = apply_filters( 'kleo_bp_ajax_call', $response );

	if ( ! empty( $response ) ) {
		echo json_encode( $response );
	}
	exit;
}

/* Localize refresh interval to JavaScript app.js */
add_filter( 'kleo_localize_app', 'kleo_bp_notif_refresh_int' );
function kleo_bp_notif_refresh_int( $data ) {
	$data['bpAjaxRefresh'] = sq_option( 'bp_notif_interval', 20000 );

	return $data;
}

/* BuddyPress Avatar in menu item */
add_filter( 'kleo_nav_menu_items', 'kleo_add_user_avatar_nav_item' );
function kleo_add_user_avatar_nav_item( $menu_items ) {
	$menu_items[] = array(
		'name' => esc_html__( 'My Account', 'kleo' ),
		'slug' => 'user_avatar',
		'link' => '#',
	);

	return $menu_items;
}

add_filter( 'kleo_setup_nav_item_user_avatar', 'kleo_setup_user_avatar_nav' );
function kleo_setup_user_avatar_nav( $menu_item ) {

	add_filter( 'walker_nav_menu_start_el_user_avatar', 'kleo_menu_user_avatar', 10, 4 );
	add_filter( 'walker_nav_menu_start_el_li_user_avatar', 'kleo_menu_user_avatar_li', 10, 4 );

	return $menu_item;
}

if ( ! function_exists( 'kleo_menu_user_avatar' ) ) {
	/**
	 * Render user avatar menu item
	 *
	 * @param string $item_output
	 * @param object $item
	 * @param integer $depth
	 * @param object $args
	 *
	 * @return string
	 */
	function kleo_menu_user_avatar( $item_output, $item, $depth, $args ) {

		$output = '';

		if ( is_user_logged_in() ) {

			$url          = bp_loggedin_user_domain();
			$anchor_class = 'kleo-bp-user-avatar';
			$anchor_class .= $args->has_children && in_array( $depth, array( 0, 1 ) ) ? ' js-activated' : '';

			$attr_title = strip_tags( $item->attr_title );
			$output     .= '<a title="' . bp_get_loggedin_user_fullname() . '" class="' . $anchor_class . '" href="' . $url . '" title="' . $attr_title . '">'
			               . '<img src="' . esc_attr( bp_get_loggedin_user_avatar( array(
					'width'  => 30,
					'height' => 30,
					'html'   => false
				) ) ) . '" class="kleo-rounded" alt="' . bp_get_loggedin_user_fullname() . '">' . ( $item->attr_title != '' ? ' ' . $item->attr_title : '' );

			$output .= ( $args->has_children && in_array( $depth, array(
					0,
					1
				) ) ) ? ' <span class="caret"></span></a>' : '</a>';

			return $output;
		} elseif ( $args->has_children && in_array( $depth, array( 0, 1 ) ) ) {
			return $item_output;
		} else {
			return '';
		}
	}
}

function kleo_menu_user_avatar_li( $item_output, $item, $depth, $args ) {
	$output = '';
	if ( is_user_logged_in() || ( $args->has_children && in_array( $depth, array( 0, 1 ) ) ) ) {
		$output = $item_output;
	}

	return $output;
}


/* Fix for members search form placeholder */
add_filter( 'bp_directory_members_search_form', 'kleo_bp_fix_members_placeholder' );

function kleo_bp_fix_members_placeholder( $html ) {
	if ( isset( $_GET['s'] ) && $_GET['s'] != '' ) {
		$html = str_replace( 'placeholder', 'value', $html );
	}

	return $html;
}


/* Display BuddyPress Member Types Filters in Members Directory */

add_action( 'bp_members_directory_member_types', 'kleo_bp_member_types_tabs' );
function kleo_bp_member_types_tabs() {
	if ( ! bp_get_current_member_type() ) {
		$member_types = bp_get_member_types( array(), 'objects' );
		if ( $member_types ) {
			foreach ( $member_types as $member_type ) {
				if ( $member_type->has_directory == 1 ) {
					echo '<li id="members-' . esc_attr( $member_type->name ) . '" class="bp-member-type-filter">';
					echo '<a href="' . bp_get_members_directory_permalink() . 'type/' . $member_type->directory_slug . '/">' . sprintf( '%s <span>%d</span>', $member_type->labels['name'], kleo_bp_count_member_types( $member_type->name ) ) . '</a>';
					echo '</li>';
				}
			}
		}
	}
}


add_filter( 'bp_modify_page_title', 'kleo_bp_members_type_directory_page_title', 9, 4 );
function kleo_bp_members_type_directory_page_title( $title, $original_title, $sep, $seplocation ) {
	$member_type = bp_get_current_member_type();
	if ( bp_is_directory() && $member_type ) {
		$member_type = bp_get_member_type_object( $member_type );
		if ( $member_type ) {
			global $post;
			$post->post_title = $member_type->labels['name'];
			$title            = $member_type->labels['name'] . " " . $sep . " " . $original_title;
		}
	}

	return $title;
}


add_filter( 'bp_get_total_member_count', 'kleo_bp_get_total_member_count_member_type' );
function kleo_bp_get_total_member_count_member_type() {
	$count       = bp_core_get_active_member_count();
	$member_type = bp_get_current_member_type();
	if ( bp_is_directory() && $member_type ) {
		$count = kleo_bp_count_member_types( $member_type );
	}

	return $count;
}


add_filter( 'bp_get_total_friend_count', 'kleo_bp_get_total_friend_count_member_type' );
function kleo_bp_get_total_friend_count_member_type() {
	$user_id     = get_current_user_id();
	$count       = friends_get_total_friend_count( $user_id );
	$member_type = bp_get_current_member_type();
	if ( bp_is_directory() && $member_type ) {
		global $bp, $wpdb;
		$friends = $wpdb->get_results( "SELECT count(1) as count FROM {$bp->friends->table_name} bpf
        LEFT JOIN {$wpdb->term_relationships} tr ON (bpf.initiator_user_id = tr.object_id || bpf.friend_user_id = tr.object_id )
        LEFT JOIN {$wpdb->terms} t ON t.term_id = tr.term_taxonomy_id
        WHERE t.slug = '" . $member_type . "' AND (bpf.initiator_user_id = $user_id || bpf.friend_user_id = $user_id ) AND tr.object_id != $user_id AND bpf.is_confirmed = 1", ARRAY_A );
		$count   = 0;
		if ( isset( $friends['0']['count'] ) && is_numeric( $friends['0']['count'] ) ) {
			$count = $friends['0']['count'];
		}
	}

	return $count;
}


function kleo_bp_count_member_types( $member_type = '' ) {
	if ( ! bp_is_root_blog() ) {
		switch_to_blog( bp_get_root_blog_id() );
	}
	global $wpdb;
	$sql           = array(
		'select' => "SELECT t.slug, tt.count FROM {$wpdb->term_taxonomy} tt LEFT JOIN {$wpdb->terms} t",
		'on'     => 'ON tt.term_id = t.term_id',
		'where'  => $wpdb->prepare( 'WHERE tt.taxonomy = %s', 'bp_member_type' ),
	);
	$members_count = $wpdb->get_results( join( ' ', $sql ) );
	$members_count = wp_filter_object_list( $members_count, array( 'slug' => $member_type ), 'and', 'count' );
	$members_count = array_values( $members_count );
	if ( isset( $members_count[0] ) && is_numeric( $members_count[0] ) ) {
		$members_count = $members_count[0];
	} else {
		$members_count = 0;
	}
	restore_current_blog();

	return $members_count;
}


add_filter( 'bp_before_has_members_parse_args', 'kleo_bp_set_has_members_type_arg', 10, 1 );
function kleo_bp_set_has_members_type_arg( $args ) {
	$member_type  = bp_get_current_member_type();
	$member_types = bp_get_member_types( array(), 'names' );
	if ( isset( $args['scope'] ) && ! isset( $args['member_type'] ) && in_array( $args['scope'], $member_types ) ) {
		if ( $member_type ) {
			unset( $args['scope'] );
		} else {
			$args['member_type'] = $args['scope'];
		}
	}

	return $args;
}

add_action( 'bp_before_member_header_meta', 'kleo_bp_profile_member_type_label' );
function kleo_bp_profile_member_type_label() {
	$member_type = bp_get_member_type( bp_displayed_user_id() );
	if ( empty( $member_type ) ) {
		return;
	}
	$member_type_object = bp_get_member_type_object( $member_type );
	if ( $member_type_object ) {
		$member_type_label = '<p class="kleo_bp_profile_member_type_label">' . esc_html( $member_type_object->labels['singular_name'] ) . '</p>';
		echo apply_filters( 'kleo_bp_profile_member_type_label', $member_type_label );
	}
}


/* Get current Buddypress page */
function kleo_bp_get_page_id() {
	$current_page_id = null;
	$page_array      = get_option( 'bp-pages' );

	if ( bp_is_register_page() ) { /* register page */
		$current_page_id = $page_array['register'];
	} elseif ( bp_is_members_directory() ) { /* members directory */
		$current_page_id = $page_array['members'];
	} elseif ( bp_is_activity_directory() ) { /* activity directory */
		$current_page_id = $page_array['activity'];
	} elseif ( bp_is_groups_directory() ) { /* groups directory */
		$current_page_id = $page_array['groups'];
	} elseif ( bp_is_activation_page() ) { /* activation page */
		$current_page_id = $page_array['activate'];
	}

	return $current_page_id;
}

if ( ! function_exists( 'sq_bp_get_page_id' ) ) {
	function sq_bp_get_page_id() {
		return kleo_bp_get_page_id();
	}
}


/* Get current Buddypress page */
function kleo_bp_get_component_id() {
	$current_page_id = null;
	$page_array      = get_option( 'bp-pages' );

	if ( bp_is_register_page() ) { /* register page */
		$current_page_id = $page_array['register'];
	} elseif ( bp_is_members_component() || bp_is_user() ) { /* members component */
		$current_page_id = $page_array['members'];
	} elseif ( bp_is_activity_directory() ) { /* activity directory */
		$current_page_id = $page_array['activity'];
	} elseif ( bp_is_groups_directory() || bp_is_group_single() ) { /* groups directory */
		$current_page_id = $page_array['groups'];
	} elseif ( bp_is_activation_page() ) { /* activation page */
		$current_page_id = $page_array['activate'];
	}

	return $current_page_id;
}


function kleo_bp_header() {

	$current_page_id = kleo_bp_get_page_id();
	if ( ! $current_page_id ) {
		return;
	}

	$page_header = get_cfield( 'header_content', $current_page_id );
	if ( $page_header != '' ) {
		echo '<section class="kleo-bp-header container-wrap main-color">';
		echo do_shortcode( html_entity_decode( $page_header ) );
		echo '</section>';
	}
}


function kleo_bp_bottom() {

	$current_page_id = kleo_bp_get_page_id();
	if ( ! $current_page_id ) {
		return;
	}

	$page_bottom = get_cfield( 'bottom_content', $current_page_id );
	if ( $page_bottom != '' ) {
		echo '<section class="kleo-bp-bottom">';
		echo do_shortcode( $page_bottom );
		echo '</section>';
	}
}


if ( ! function_exists( 'kleo_bp_page_options' ) ) {
	/**
	 * Set Buddypress page layout based of individual page settings
	 */
	function kleo_bp_page_options() {

//		$current_page_id = kleo_bp_get_page_id();
		$current_page_id = get_queried_object_id();

		if ( ! $current_page_id ) {
			return false;
		}

		$topbar_status = get_cfield( 'topbar_status', $current_page_id );
		//Top bar
		if ( isset( $topbar_status ) ) {
			if ( $topbar_status === '1' ) {
				add_filter( 'kleo_show_top_bar', function () {
					return 1;
				} );
			} elseif ( $topbar_status === '0' ) {
				add_filter( 'kleo_show_top_bar', '__return_zero' );
			}
		}
		//Header and Footer settings
		if ( get_cfield( 'hide_header', $current_page_id ) == 1 ) {
			remove_action( 'kleo_header', 'kleo_show_header' );
		}
		if ( get_cfield( 'hide_footer', $current_page_id ) == 1 ) {
			add_filter( 'kleo_footer_hidden', '__return_true' );
		}
		if ( get_cfield( 'hide_socket', $current_page_id ) == 1 ) {
			remove_action( 'kleo_after_footer', 'kleo_show_socket' );
		}

		//Custom logo
		if ( get_cfield( 'logo', $current_page_id ) ) {
			global $kleo_custom_logo;
			$kleo_custom_logo = get_cfield( 'logo', $current_page_id );
			add_filter( 'kleo_logo', function () use ( $kleo_custom_logo ) {
				return $kleo_custom_logo;
			} );
		}

		//Transparent menu
		if ( get_cfield( 'transparent_menu', $current_page_id ) ) {
			add_filter( 'body_class', function ( $classes ) {
				$classes[] = 'navbar-transparent';

				return $classes;
			} );
		}

		//Remove shop icon
		if ( get_cfield( 'hide_shop_icon', $current_page_id ) && get_cfield( 'hide_shop_icon', $current_page_id ) == 1 ) {
			remove_filter( 'wp_nav_menu_items', 'kleo_woo_header_cart', 9 );
			remove_filter( 'kleo_mobile_header_icons', 'kleo_woo_mobile_icon', 10 );
		}
		//Remove search icon
		if ( get_cfield( 'hide_search_icon', $current_page_id ) && get_cfield( 'hide_search_icon', $current_page_id ) == 1 ) {
			remove_filter( 'wp_nav_menu_items', 'kleo_search_menu_item', 200, 2 );
		}
	}
}

function prepare_logo_retina_buddypress() {
	//Custom Retina Logo
	$current_page_id = get_queried_object_id();

	if ( get_cfield( 'logo_retina', $current_page_id ) ) {
		add_filter( 'logo_retina', function () use ( $current_page_id ) {
			return get_cfield( 'logo_retina', $current_page_id );
			// return custom_retina_logo_buddypress($obj_array, $kleo_custom_logo);
		} );
	}

}

add_action( 'wp_enqueue_scripts', 'prepare_logo_retina_buddypress', 8 );


add_filter( 'body_class', 'kleo_bp_body_classes' );

function kleo_bp_body_classes( $classes = array() ) {

	if ( ! bp_is_blog_page() ) {
		if ( 'light' == sq_option( 'bp_icons_style', 'normal' ) ) {
			//check if on group or on profile page
			if ( bp_is_user() || ( bp_is_single_item() && bp_is_groups_component() ) ) {
				$classes[] = 'bp-light-icons';
			}
		}

		if ( sq_option( 'bp_nav_overlay', 0 ) == 1 ) {
			$classes[] = 'bp-overlay-menu';
		}
	}

	if ( sq_option( 'bp_rounded_avatars', 1 ) == 1 ) {
		$classes[] = 'bp-rounded-avatar';
	}

	if ( ! sq_option( 'bp_online_status', 1 ) ) {
		$classes[] = 'bp-hide-online';
	}

	return $classes;
}


function kleo_bp_set_custom_menu( $args = '' ) {

	if ( 'primary' != $args['theme_location'] && 'secondary' != $args['theme_location'] ) {
		return $args;
	}

	$page_id = kleo_bp_get_component_id();

	if ( $page_id ) {

		if ( 'primary' == $args['theme_location'] ) {
			$menuslug = get_cfield( 'page_menu', $page_id );

			if ( ! empty( $menuslug ) && $menuslug != 'default' && is_nav_menu( $menuslug ) ) {
				$args['menu'] = $menuslug;
			}

		} elseif ( 'secondary' == $args['theme_location'] ) {

			$sec_menuslug = get_cfield( 'page_menu_secondary', $page_id );

			if ( ! empty( $sec_menuslug ) && $sec_menuslug != 'default' && is_nav_menu( $sec_menuslug ) ) {
				$args['menu'] = $sec_menuslug;
			}

		}

	}

	return $args;
} // END function kleo_set_custom_menu($args = '')


if ( ! function_exists( 'bp_groups_front_template_part' ) ) {
	/**
	 * Output the contents of the current group's home page.
	 *
	 * You should only use this when on a single group page.
	 *
	 * @since 2.4.0
	 */
	function bp_groups_front_template_part() {
		$located = bp_groups_get_front_template();

		if ( false !== $located ) {
			$slug = str_replace( '.php', '', $located );

			/**
			 * Let plugins adding an action to bp_get_template_part get it from here
			 *
			 * @param string $slug Template part slug requested.
			 * @param string $name Template part name requested.
			 */
			do_action( 'get_template_part_' . $slug, $slug, false );

			load_template( $located, true );

		} else if ( bp_is_active( 'activity' ) ) {
			bp_get_template_part( 'groups/single/activity' );

		} else if ( bp_is_active( 'members' ) ) {
			bp_groups_members_template_part();
		}

		return $located;
	}
}



/* BP DOCS compatibility */
add_filter( 'bp_docs_allow_comment_section', '__return_true', 100 );


/* Remove shortcodes from activity view */
add_filter( 'bp_get_activity_content_body', 'kleo_bp_activity_filter', 1 );
function kleo_bp_activity_filter( $content ) {
	$content = preg_replace( "/\[(.*?)(?:(\/))?\](?:(.+?)\[\/\2\])?/s", '', $content );

	return $content;
}


add_action( 'init', function () {
	if ( bp_is_user() && sq_option( 'bp_profile_sidebar' ) ) {
		add_filter( 'kleo_sidebar_name', function () {
			return sq_option( 'bp_profile_sidebar' );
		} );
	}
	if ( bp_is_group() && sq_option( 'bp_group_sidebar' ) ) {
		add_filter( 'kleo_sidebar_name', function () {
			return sq_option( 'bp_group_sidebar' );
		} );
	}

} );


//Change page layout to match theme options settings
add_filter( 'kleo_page_layout', 'kleo_bp_change_layout' );

if ( ! function_exists( 'kleo_bp_change_layout' ) ) {
	function kleo_bp_change_layout( $layout ) {
		global $bp;

		if ( ! bp_is_blog_page() ) {

			$layout = sq_option( 'bp_layout', 'right' );

			//profile page
			if ( sq_option( 'bp_layout_profile', 'default' ) !== 'default' && bp_is_user() ) {
				$layout = sq_option( 'bp_layout_profile', 'right' );
			} elseif ( sq_option( 'bp_layout_members_dir', 'default' ) !== 'default'
			           && bp_is_current_component( $bp->members->slug )
			           && bp_is_directory()
			) {
				$layout = sq_option( 'bp_layout_members_dir', 'right' );
			} elseif ( sq_option( 'bp_layout_groups', 'default' ) !== 'default'
			           && bp_is_current_component( $bp->groups->slug )
			) {
				$layout = sq_option( 'bp_layout_groups', 'right' );
			} elseif ( sq_option( 'bp_layout_activity', 'default' ) !== 'default'
			           && bp_is_current_component( $bp->activity->slug ) && ! bp_is_user()
			) {
				$layout = sq_option( 'bp_layout_activity', 'right' );
			} elseif ( sq_option( 'bp_layout_register', 'default' ) !== 'default'
			           && bp_is_register_page()
			) {
				$layout = sq_option( 'bp_layout_register', 'right' );
			}

		}

		return $layout;
	}
}

/* Get User online */
if ( ! function_exists( 'kleo_is_user_online' ) ):
	/**
	 * Check if a Buddypress member is online or not
	 *
	 * @param integer $user_id
	 * @param integer $time
	 *
	 * @return boolean
	 * @global object $wpdb
	 *
	 */
	function kleo_is_user_online( $user_id, $time = 5 ) {

		$current_time  = bp_core_current_time( true, 'timestamp' );
		$last_activity =  bp_get_user_last_activity( $user_id );
		$still_online  = strtotime( '+' . $time . ' minutes', strtotime( $last_activity ) );

		// Has the user been active recently?
		return $current_time <= $still_online;

	}
endif;


if ( ! function_exists( 'kleo_get_online_status' ) ) :
	function kleo_get_online_status( $user_id ) {
		if ( kleo_is_user_online( $user_id ) ) {
			$class = 'high-bg ';
		} else {
			$class = '';
		}
		$output = '<span class="' . $class . 'kleo-online-status hover-tip click-tip"  data-toggle="tooltip" data-container="body" data-title="' . bp_get_last_activity( $user_id ) . '" data-placement="top"></span>';

		return $output;
	}
endif;


/**
 * Render the html to show if a user is online or not
 */
if ( ! function_exists( 'kleo_online_status' ) ) {
	function kleo_online_status( $user_id ) {
		echo kleo_get_online_status( $user_id );
	}
}

/*
 * Add Prev,Next links after breadcrumb if it is a profile page
 */
function kleo_bp_add_profile_navigation() {
	if ( bp_is_user() ): ?>
        <nav class="pagination-sticky members-navigation" role="navigation">
			<?php
			$prev = bp_prev_profile( bp_displayed_user_id() );
			$next = bp_next_profile( bp_displayed_user_id() );

			if ( $prev !== "#" ) {
				$name = bp_core_get_user_displayname( $prev );
				?>

                <a rel="prev" href="<?php echo bp_core_get_user_domain( $prev ); ?>"
                   title="<?php echo esc_attr( $name ); ?>">
					<span id="older-nav">
						<span class="nav-image"><?php echo bp_core_fetch_avatar( array(
								'item_id' => $prev,
								'type'    => 'full'
							) ); ?></span>
						<span class="outter-title"><span
                                    class="entry-title"><?php echo esc_html( $name ); ?></span>
						</span>
					</span>
                </a>

			<?php }

			if ( $next !== "#" ) {
				$name = bp_core_get_user_displayname( $next );
				?>

                <a rel="next" href="<?php echo bp_core_get_user_domain( $next ); ?>"
                   title="<?php echo esc_attr( $name ); ?>">
				<span id="newer-nav">
					<span class="nav-image"><?php echo bp_core_fetch_avatar( array(
							'item_id' => $next,
							'type'    => 'full'
						) ); ?></span>
					<span class="outter-title"><span
                                class="entry-title"><?php echo esc_html( $name ); ?></span></span>
				</span>
                </a>

			<?php } ?>
        </nav><!-- .navigation -->
	<?php endif;
}

if ( sq_option( 'member_navigation', 1 ) == 1 ) :
	add_action( 'bp_after_member_body', 'kleo_bp_add_profile_navigation' );
endif;

/**
 * Get next profile link
 *
 * @param int $current_id Displayer user ID
 *
 * @return string User link
 */
if ( ! function_exists( 'bp_next_profile' ) ):
	function bp_next_profile( $current_id ) {
		global $wpdb;

		$extra = '';
		$obj   = new stdClass();
		do_action_ref_array( 'bp_pre_user_query_construct', array( &$obj ) );
		if ( isset( $obj->query_vars ) && $obj->query_vars && $obj->query_vars['exclude'] && is_array( $obj->query_vars['exclude'] ) && ! empty( $obj->query_vars['exclude'] ) ) {
			$extra = " AND us.ID NOT IN (" . implode( ",", $obj->query_vars['exclude'] ) . ")";
		}

//		$sql = "SELECT MIN(us.ID) FROM " . $wpdb->base_prefix . "users us"
		$sql = "SELECT MIN(us.ID) FROM " . $wpdb->users . " us"
		       . " JOIN " . $wpdb->base_prefix . "bp_xprofile_data bp ON us.ID = bp.user_id"
		       . " JOIN " . $wpdb->usermeta . " um ON um.user_id = us.ID"
		       . " WHERE um.meta_key = 'last_activity' AND us.ID > $current_id"
		       . $extra;

		if ( $wpdb->get_var( $sql ) && $wpdb->get_var( $sql ) !== $current_id ) {
			return $wpdb->get_var( $sql );
		} else {
			return '#';
		}
	}
endif;

/**
 * Get previous profile link
 *
 * @param int $current_id Displayer user ID
 *
 * @return string User link
 */
if ( ! function_exists( 'bp_prev_profile' ) ):
	function bp_prev_profile( $current_id ) {
		global $wpdb;

		$extra = '';
		$obj   = new stdClass();
		do_action_ref_array( 'bp_pre_user_query_construct', array( &$obj ) );
		if ( isset( $obj->query_vars ) && $obj->query_vars && $obj->query_vars['exclude'] && is_array( $obj->query_vars['exclude'] ) && ! empty( $obj->query_vars['exclude'] ) ) {
			$extra = " AND us.ID NOT IN (" . implode( ",", $obj->query_vars['exclude'] ) . ")";
		}

//		$sql = "SELECT MAX(us.ID) FROM " . $wpdb->base_prefix . "users us"
		$sql = "SELECT MAX(us.ID) FROM " . $wpdb->users . " us"
		       . " JOIN " . $wpdb->base_prefix . "bp_xprofile_data bp ON us.ID = bp.user_id"
		       . " JOIN " . $wpdb->usermeta . " um ON um.user_id = us.ID"
		       . " WHERE um.meta_key = 'last_activity' AND us.ID < $current_id"
		       . $extra;

		if ( $wpdb->get_var( $sql ) && $wpdb->get_var( $sql ) !== $current_id ) {
			return $wpdb->get_var( $sql );
		} else {
			return '#';
		}
	}
endif;

/* Activity animation classes */

add_filter( 'bp_get_activity_css_class', 'kleo_bp_activity_classes' );
function kleo_bp_activity_classes( $class ) {
	if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		$class .= ' animated animate-when-almost-visible bottom-to-top';
	}

	return $class;
}

/* Buddypress fix for Posting ordered list */
function kleo_fix_activity_ordered_list( $activity_allowedtags ) {
	$activity_allowedtags['ol'] = array();

	return $activity_allowedtags;
}

add_filter( 'bp_activity_allowed_tags', 'kleo_fix_activity_ordered_list' );




if ( ! function_exists( 'get_member_age' ) ):
	/**
	 * Calculate member age based on date of birth
	 *
	 * @param int $id User id to get the age for
	 *
	 * @return string
	 */
	function get_member_age( $id ) {
		$default_age_field = get_profile_id_by_name( 'Birthday' );
		$age_field         = sq_option( 'bp_age_field', $default_age_field );
		if ( $age_field == 0 ) {
			$age_field = $default_age_field;
		}

		if ( bp_is_active( 'xprofile' ) && xprofile_get_field_data( $age_field, $id ) ) {
			$birth = BP_XProfile_ProfileData::get_value_byid( $age_field, $id );

			$field = new BP_XProfile_Field ( $age_field );

			if ( $field->type == 'birthdate' ) {
				return strip_tags( $birth );
			}
			if ( ! empty( $birth ) ) {
				$diff = time() - strtotime( $birth );
				$age  = floor( $diff / ( 365 * 60 * 60 * 24 ) );
			}
		} else {
			$age = '';
		}

		return $age;
	}
endif;


/* Show Age instead of Birthdate */
if ( sq_option( 'bp_birthdate_to_age', 0 ) == 1 ) {

	add_filter( 'bp_get_the_profile_field_name', 'kleo_change_birth_to_age' );
	add_action( 'bp_before_profile_edit_content', 'kleo_revert_birth_to_age' );
	add_filter( 'bp_get_the_profile_field_value', 'kleo_get_field_value', 9, 3 );
	remove_filter( 'bp_get_the_profile_field_value', 'xprofile_filter_format_field_value', 1, 2 );
	remove_filter( 'bp_get_the_profile_field_value', 'xprofile_filter_format_field_value_by_type', 8, 2 );

}


function kleo_revert_birth_to_age() {

	remove_filter( 'bp_get_the_profile_field_name', 'kleo_change_birth_to_age' );

}

function kleo_change_birth_to_age( $name ) {

	if ( $name == sq_option( 'bp_birthdate_to_age_field', 'Birthday' ) ) {

		$name = __( 'Age', 'buddypress' );

	}

	return $name;
}


if ( ! function_exists( 'kleo_get_field_value' ) ) {
	function kleo_get_field_value( $value, $type, $field_id ) {

		$value_to_return = $value;
		$field           = new BP_XProfile_Field( $field_id );

		//our Age field
		$default_age_field = get_profile_id_by_name( 'Birthday' );
		$age_field         = sq_option( 'bp_age_field', $default_age_field );
		if ( $age_field == 0 ) {
			$age_field = $default_age_field;
		}

		if ( $type === 'datebox' && $age_field == $field_id ) {
			$value_to_return = floor( ( time() - strtotime( $value ) ) / 31556926 );
		}

		return $value_to_return;
	}
}

/* If BuddyBoss is active we can end file processing since those are no longer needed */
if (defined( 'BP_PLATFORM_VERSION' )) {
    return;
}

//Add nice expand functionality in member and group profile
add_action( 'bp_before_member_header', 'kleo_bp_expand_profile', 20 );
add_action( 'bp_before_group_header', 'kleo_bp_expand_profile', 20 );

function kleo_bp_expand_profile() {
	?>
    <span class="toggle-header">
	<span class="bp-toggle-less"><?php esc_html_e( "show less", 'kleo' ); ?></span>
	<span class="bp-toggle-more"><?php esc_html_e( "show more", 'kleo' ); ?></span>
</span>
	<?php
}

add_filter( 'body_class', 'kleo_bp_profile_expand_class' );

function kleo_bp_profile_expand_class( $class = '' ) {
	if ( isset( $_COOKIE['bp-profile-header'] ) && $_COOKIE['bp-profile-header'] === 'small' ) {
		$class[] = 'bp-header-small';
	}

	return $class;
}


/* Buddypress cover compatibility */

if ( version_compare( bp_get_version(), '6.0.0', '<' ) ) {
	add_filter( 'bp_before_xprofile_cover_image_settings_parse_args', 'kleo_bp_cover_image_css', 10, 1 );
} else {
	add_filter( 'bp_before_members_cover_image_settings_parse_args', 'kleo_bp_cover_image_css', 10, 1 );
}
add_filter( 'bp_before_groups_cover_image_settings_parse_args', 'kleo_bp_cover_image_css', 10, 1 );


function kleo_bp_cover_image_css( $settings = array() ) {
	$settings['callback'] = 'kleo_bp_cover_image_callback';
	$settings['width']        = 1400;
	$settings['height']       = 440;
	if ( bp_get_theme_package_id() !== 'nouveau' ) {
		$settings['theme_handle'] = 'bp-parent-css';
	}

	return $settings;
}

/**
 * BP callback for the cover image feature.
 *
 * @param array $params the current component's feature parameters.
 *
 * @return void|null|string An array to inform about the css handle to attach the css rules to
 * @since  2.4.0
 *
 */
function kleo_bp_cover_image_callback( $params = array() ) {
	if ( empty( $params ) || empty( $params['cover_image'] ) ) {
		return;
	}

	/* Add body class for users with cover */
	add_filter( 'body_class', 'kleo_bp_cover_body_class', 30 );

	$cover_image = 'background-image: url(' . $params['cover_image'] . '); ' .
	               'background-repeat: no-repeat; background-size: cover; background-position: center center !important;';

	return '
		/* Cover image */
		body.buddypress div#item-header #header-cover-image {
			' . $cover_image . '
		}';
}

function kleo_bp_cover_body_class( $classes ) {
	$classes[] = 'is-user-profile';

	return $classes;
}

function kleo_bp_add_cover_html() {

	if ( ! bp_displayed_user_use_cover_image_header() ) {
		return;
	}

	global $bp;

	?>
	<div class="profile-cover-inner"></div>
	<?php
	if ( bp_is_my_profile() || is_super_admin() ) {
		?>
		<div class="profile-cover-action">
			<a href="<?php echo esc_url( bp_get_members_component_link( 'profile', 'change-cover-image' ) ); ?>" class="button">
				<?php esc_html_e( "Change Cover", 'kleo' ); ?>
			</a>
		</div>
		<?php
	}
}

function kleo_bp_group_add_cover_html() {

	if ( ! bp_group_use_cover_image_header() ) {
		return;
	}

	?>
	<div class="profile-cover-inner"></div>
	<?php

	if ( is_user_logged_in() ) {

		$user_ID  = get_current_user_id();
		$group_id = bp_get_current_group_id();

		if ( groups_is_user_mod( $user_ID, $group_id ) || groups_is_user_admin( $user_ID, $group_id ) ) {

			$group = groups_get_group( array( 'group_id' => $group_id ) );
			?>
			<div class="profile-cover-action">
				<a href="<?php echo esc_url( bp_get_group_url( $group ) . 'admin/group-cover-image/' ); ?>" class="button">
					<?php esc_html_e( "Change Cover", 'kleo' ); ?>
				</a>
			</div>
		<?php
		}
	}
}

if ( version_compare( BP_VERSION, '2.4', '>=' ) ) {
	add_action( 'bp_before_member_header', 'kleo_bp_add_cover_html', 20 );
	add_action( 'bp_before_group_header', 'kleo_bp_group_add_cover_html', 20 );
}


/* Private message in Members directory loop */
function kleo_bp_filter_message_button_link( $link = '' ) {
	$bp_user_id = ( bp_get_member_user_id() ? bp_get_member_user_id() : bp_displayed_user_id() );

	return wp_nonce_url( bp_loggedin_user_domain() . bp_get_messages_slug() . '/compose/?r=' . bp_core_get_username( $bp_user_id ) );
}

// Messages button.
if ( bp_is_active( 'messages' ) && apply_filters( 'enable_kleo_bp_dir_send_private_message_button', true ) ) {
	add_action( 'bp_directory_members_actions', 'kleo_bp_dir_send_private_message_button', 11 );
}
function kleo_bp_dir_send_private_message_button() {
	if ( bp_get_member_user_id() != bp_loggedin_user_id() ) {
		add_filter( 'bp_get_send_private_message_link', 'kleo_bp_filter_message_button_link', 1, 1 );
		add_filter( 'bp_get_send_message_button_args', 'kleo_bp_private_msg_args' );
		bp_send_message_button();
	}
}


/**
 * Override default BP private message button to work on Friends tab
 *
 * @param array $btn
 *
 * @return bool|array
 * @since 2.2
 *
 */
function kleo_bp_private_msg_args( $btn ) {

	if ( ! is_user_logged_in() ) {
		return false;
	}

	$btn['link_href'] = kleo_bp_filter_message_button_link();

	return $btn;
}

add_action( 'bp_after_member_header', 'kleo_toggle_profile_header' );
add_action( 'bp_after_group_home_content', 'kleo_toggle_profile_header' );
function kleo_toggle_profile_header() {
	?>
    <script>
        window.addEventListener('DOMContentLoaded', function () {
            jQuery(document).ready(function (jq) {
                jq('.toggle-header').on('click', function () {
                    if (!jq('body').hasClass("bp-header-small")) {
                        jq('body').addClass('bp-header-small');
                        jq.cookie('bp-profile-header', 'small', {
                            path: '/'
                        });
                    } else {
                        jq('body').removeClass('bp-header-small');
                        jq.cookie('bp-profile-header', null, {path: '/'});
                    }
                });

                jq(document).ajaxComplete(function (event, xhr, settings) {
                    if(settings.data){
                        if(settings.data.indexOf("action=bp_cover_image_delete") != -1){
                            jq('body').removeClass('is-user-profile');
                        }
                    }
                });
                if(typeof(bp) !== 'undefined' && typeof(bp.Uploader) !== 'undefined' && typeof(bp.Uploader.filesQueue) !== 'undefined'){
                    bp.Uploader.filesQueue.on( 'add', function(){
                        jq('body').addClass('is-user-profile');
                    });
                }
            });
        });
    </script>
	<?php
}