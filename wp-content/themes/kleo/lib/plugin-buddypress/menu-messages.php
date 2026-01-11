<?php
/* Add Live messages to menu */

/* Buddypress Messages in menu item */
add_filter( 'kleo_nav_menu_items', 'kleo_add_messages_nav_item' );
function kleo_add_messages_nav_item( $menu_items ) {
	$menu_items[] = array(
		'name' => esc_html__( 'Live Messages', 'kleo' ),
		'slug' => 'messages',
		'link' => '#',
	);

	return $menu_items;
}


add_filter( 'kleo_setup_nav_item_messages', 'kleo_setup_messages_nav' );
function kleo_setup_messages_nav( $menu_item ) {
	$menu_item->classes[] = 'kleo-toggle-menu kleo-messages';
	$menu_item->classes[] = 'dropdown-submenu';
	if ( ! is_user_logged_in() ) {
		$menu_item->_invalid = true;
	} else {
		add_filter( 'walker_nav_menu_start_el_messages', 'kleo_menu_messages', 10, 4 );
	}

	return $menu_item;
}

function kleo_bp_mobile_messages() {

	$url    = bp_loggedin_user_domain() . bp_get_messages_slug();
	$count  = bp_get_total_unread_messages_count();

	if ( $count > 0 ) {
		$alert = 'new-alert';
	} else {
		$alert = 'no-alert';
	}

	if ( sq_kleo()->get_option( 'mobile_messages' ) ) {
		$icon = sq_kleo()->get_option( 'mobile_messages' );
	} else {
		$icon = 'mail';
	}
	?>

	<a title="<?php esc_attr_e( 'View Messages', 'kleo' ); ?>" class="notify-contents" href="<?php echo esc_url( $url ); ?>">
		<span class="notify-items sq-messages-mobile">
			<i class="icon-<?php echo esc_attr( $icon ); ?>"></i>
			<span class="kleo-notifications <?php echo esc_attr( $alert ); ?>">
				<?php echo esc_html( $count ); ?>
			</span>
		</span>
	</a>

	<?php
}

function kleo_menu_messages( $item_output = '', $item = null, $depth = 1, $args = null ) {

	if ( ! isset( $item ) ) {
		$item             = new stdClass();
		$item->title      = esc_html__( 'Messages', 'kleo' );
		$item->attr_title = esc_attr__( 'Messages', 'kleo' );
		$item->icon       = 'messages-line';
	}
	if ( ! isset( $args ) ) {
		$args                 = new stdClass();
		$args->theme_location = 'primary';
	}
	$output = '';
	$url    = bp_loggedin_user_domain() . bp_get_messages_slug();
	$count  = bp_get_total_unread_messages_count();

	if ( $count > 0 ) {
		$alert  = 'new-alert';
		$status = ' has-notif';
	} else {
		$alert  = 'no-alert';
		$status = '';
	}
	$attr_title = strip_tags( $item->attr_title );

	if ( isset( $item->icon ) && $item->icon != '' ) {

		$icon = $item->icon;
		sq_kleo()->set_option( 'mobile_messages', $icon );
		$title_icon = '<i class="icon-' . $icon . '"></i>';

		if ( $item->iconpos == 'after' ) {
			$title = $item->title . ' ' . $title_icon;
		} elseif ( $item->iconpos == 'icon' ) {
			$title = $title_icon;
		} else {
			$title = $title_icon . ' ' . $item->title;
		}
	} else {
		$title = $item->title;
	}

	//If we have the menu item then add it to the mobile menu
	add_action( 'kleo_mobile_header_icons', 'kleo_bp_mobile_messages', 9 );

	/* Menu style */
	$atts = array();
	if ( $depth === 0 && isset( $item->istyle ) ) {
		if ( $item->istyle == 'buy' ) {
			$atts['class'] = ( isset( $atts['class'] ) ? $atts['class'] : '' ) . ' btn-buy';
		} elseif ( $item->istyle == 'border' ) {
			$atts['class'] = ( isset( $atts['class'] ) ? $atts['class'] : '' ) . ' btn btn-see-through';
		} elseif ( $item->istyle == 'highlight' ) {
			$atts['class'] = ( isset( $atts['class'] ) ? $atts['class'] : '' ) . ' btn btn-highlight';
		}
	}

	$class = 'notify-contents';
	if ( $depth === 0 ) {
		$class .= ' js-activated';
	}
	$class .= isset( $atts['class'] ) ? ' ' . $atts['class'] : '';

	$output .= '<a class="' . $class . '" href="' . $url . '" title="' . $attr_title . '">'
	           . '<span class="notify-items"> ' . $title . ' <span class="kleo-notifications ' . $alert . '">' . $count . '</span></span>'
	           . '</a>';

	$output .= '<div class="kleo-toggle-submenu dropdown-menu sub-menu"><ul class="submenu-inner' . $status . '">';

	$message_list = kleo_bp_messages_get_list();

	if ( $message_list ) {
		$output .= $message_list;
		$style  = '';
	} else {
		$output .= '<li class="kleo-submenu-item"><span>' . esc_html__( 'There are no new messages.', 'kleo' ) . '</span></li>';
		$style  = ' style="display: none;"';
	}

	$output .= '<div class="minicart-buttons text-center"' . $style . '><a class="btn btn-default see-all-messages" href="' . esc_url( $url ) . '">' . esc_html__( 'See all', 'kleo' ) . '</a></li>';

	$output .= '</ul>';

	return $output;
}


add_filter( 'kleo_bp_ajax_call', 'kleo_bp_messages_refresh' );
function kleo_bp_messages_refresh( $response ) {

	if ( ! isset( $_GET['current_messages'] ) ) {
		$response['statusMessages'] = 'failure';

		return $response;
	}

	$old_count = (int) $_GET['current_messages'];
	$count     = bp_get_total_unread_messages_count();

	if ( $count == $old_count ) {
		$response['statusMessages'] = 'no_change';

		return $response;
	}

	$message_list = kleo_bp_messages_get_list();
	if ( $message_list ) {
		$output = $message_list;
	} else {
		$output = '<li class="kleo-submenu-item">' . esc_html__( 'There are no new messages.', 'kleo' ) . '</li>';
	}

	$response['dataMessages']   = $output;
	$response['countMessages']  = $count;
	$response['statusMessages'] = 'success';

	return $response;
}

if ( ! function_exists( 'kleo_bp_messages_get_list' ) ) {

	function kleo_bp_messages_get_list() {

		$output = '';
		?>

		<?php if ( bp_has_message_threads( array( 'user_id'   => bp_loggedin_user_id(),
		                                          'type'      => 'unread',
		                                          'max'       => 5,
		                                          'per_page ' => 5,
		                                          'box'       => 'inbox'
		) ) ) : ob_start(); ?>

			<?php while ( bp_message_threads() ) : bp_message_thread(); ?>

                <li class="kleo-submenu-item <?php echo bp_message_thread_has_unread() ? 'unread' : 'read'; ?>"
                    id="kleo-message-<?php bp_message_thread_id() ?>">

                    <div class="message-thumb"><?php bp_message_thread_avatar() ?></div>
                    <em class="message-from-wrap">
                        <em class="message-from"><?php bp_message_thread_from() ?></em>
                        <em class="message-date"><?php bp_message_thread_last_post_date() ?></em>
                    </em>

                    <div class="message-body">
                        <a href="<?php bp_message_thread_view_link(); ?>">
							<?php bp_message_thread_excerpt() ?>
                        </a>
                    </div>
                </li>
			<?php endwhile; ?>

			<?php $output .= ob_get_clean(); ?>

		<?php endif; ?>

		<?php

		return $output;
	}
}