<?php
/**
 * Admin menu page customization
 * @author SeventQueen
 */

if ( ! class_exists( 'Walker_Nav_Menu_Edit' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/nav-menu.php' );
}


class kleo_custom_menu {

	/*--------------------------------------------*
	 * Constructor
	 *--------------------------------------------*/

	function __construct() {

		// add custom menu fields to menu
		add_filter( 'wp_setup_nav_menu_item', array( $this, 'kleo_add_custom_nav_fields' ) );

		// save menu custom fields
		add_action( 'wp_update_nav_menu_item', array( $this, 'kleo_update_custom_nav_fields' ), 10, 3 );

		// edit menu walker
		add_filter( 'wp_edit_nav_menu_walker', array( $this, 'kleo_edit_walker' ), 10, 2 );

		// add js for custom icon selector
		add_action( 'admin_print_footer_scripts', array( $this, 'kleo_admin_wp_nav_menu_icon_js' ) );

		// load theme icon fonts
		add_action( 'load-nav-menus.php', array( $this, 'kleo_admin_wp_nav_menu_icon_font' ) );

	} // end constructor


	/**
	 * Add custom fields to $item nav object
	 * in order to be used in custom Walker
	 *
	 * @access      public
	 * @return      object
	 * @since       1.0
	 */
	function kleo_add_custom_nav_fields( $menu_item ) {

		if ( isset( $menu_item->ID ) ) {

			$menu_item->mega    = get_post_meta( $menu_item->ID, '_menu_item_mega', true );
			$menu_item->icon    = get_post_meta( $menu_item->ID, '_menu_item_icon', true );
			$menu_item->iconpos = get_post_meta( $menu_item->ID, '_menu_item_iconpos', true );
			$menu_item->istyle  = get_post_meta( $menu_item->ID, '_menu_item_istyle', true );
		}

		return $menu_item;

	}

	/**
	 * Save menu custom fields
	 *
	 * @access      public
	 * @return      void
	 * @since       1.0
	 */
	function kleo_update_custom_nav_fields( $menu_id, $menu_item_db_id, $args ) {

		// Check if  mega element is properly sent
		if ( isset( $_REQUEST['menu-item-mega'] ) && is_array( $_REQUEST['menu-item-mega'] ) && isset( $_REQUEST['menu-item-mega'][ $menu_item_db_id ] ) ) {
			$mega_value = $_REQUEST['menu-item-mega'][ $menu_item_db_id ];
			update_post_meta( $menu_item_db_id, '_menu_item_mega', $mega_value );
		} else {
			update_post_meta( $menu_item_db_id, '_menu_item_mega', null );
		}

		// Check if  icons element is properly sent
		if ( isset( $_REQUEST['menu-item-icon'] ) && is_array( $_REQUEST['menu-item-icon'] ) && isset( $_REQUEST['menu-item-icon'][ $menu_item_db_id ] ) ) {
			$icon_value = $_REQUEST['menu-item-icon'][ $menu_item_db_id ];
			update_post_meta( $menu_item_db_id, '_menu_item_icon', $icon_value );
		} else {
			update_post_meta( $menu_item_db_id, '_menu_item_icon', null );
		}

		// Check if icon position element is properly sent
		if ( isset( $_REQUEST['menu-item-iconpos'] ) && is_array( $_REQUEST['menu-item-iconpos'] ) && isset( $_REQUEST['menu-item-iconpos'][ $menu_item_db_id ] ) ) {
			$iconpos_value = $_REQUEST['menu-item-iconpos'][ $menu_item_db_id ];
			update_post_meta( $menu_item_db_id, '_menu_item_iconpos', $iconpos_value );
		} else {
			update_post_meta( $menu_item_db_id, '_menu_item_iconpos', null );
		}

		// Check if style is properly sent
		if ( isset( $_REQUEST['menu-item-istyle'] ) && is_array( $_REQUEST['menu-item-istyle'] ) && isset( $_REQUEST['menu-item-istyle'][ $menu_item_db_id ] ) ) {
			$istyle_value = $_REQUEST['menu-item-istyle'][ $menu_item_db_id ];
			update_post_meta( $menu_item_db_id, '_menu_item_istyle', $istyle_value );
		} else {
			delete_post_meta( $menu_item_db_id, '_menu_item_istyle' );
		}

	}

	/**
	 * Define new Walker edit
	 *
	 * @access      public
	 * @return      string
	 * @since       1.0
	 */
	function kleo_edit_walker( $walker, $menu_id = null ) {

		return 'Kleo_Walker_Nav_Menu_Edit';

	}

	function kleo_admin_wp_nav_menu_icon_js() {
		?>
        <style>
            .kleo-wp-menu-item-icon-select, .kleo-wp-menu-item-icon-remove {
                border: 1px solid #eee;
                padding: 3px 10px;
                border-radius: 3px;
                color: #333;
                text-decoration: none;
                position: relative;
                display: inline-block;
                margin-right: 5px;
            }

            .kleo-wp-menu-item-icon-remove.hidden,
            .kleo-wp-menu-item-icon-selected.hidden {
                display: none !important;
            }

            .kleo-wp-menu-item-icon-container {
                margin-top: 10px;
                border: 1px solid #eee;
                border-radius: 3px;
                padding: 10px;
                margin-right: 10px;
                max-height: 100px;
                overflow: auto;
                display: none;
            }

            .kleo-wp-menu-item-icon-container.open {
                display: block !important;
            }

            a.kleo-wp-menu-item-icon-item {
                padding: 5px;
                display: inline-block;
                color: #000;
                width: 25px;
                height: 25px;
                font-size: 16px;
                text-align: center;
            }

            a.kleo-wp-menu-item-icon-item:hover {
                background: #eee;
            }

            .kleo-wp-menu-item-icon-selected {
                font-size: 16px;
                display: inline-block;
                margin-right: 5px;
            }

            .menu-item-mega, .menu-item-istyle {
                display: none;
            }

            .menu-item-depth-0 .menu-item-mega,
            .menu-item-depth-0 .menu-item-istyle {
                display: block !important;
            }

        </style>
        <script>
			<?php
			$icon_opts = '';
			foreach ( kleo_icons_array() as $icon ) {
				if ( '' != $icon ) {
					$icon_opts .= '<a href="#" class="kleo-wp-menu-item-icon-item" data-value="' . esc_attr( $icon ) . '">
						<i class="icon-' . esc_attr( $icon ) . '"></i>
					</a>';
				}
			}

			$allowed_html = array(
				'a' => array(
					'href' => array(),
					'class' => array(),
					'data-value' => array(),
				),
				'i' => array(
					'class' => array(),
				),
			);
			?>
            //menu icons container
            var SqMenuIcons = <?php echo wp_kses( $icon_opts, $allowed_html ); ?>;

            jQuery(document).ready(function () {

                jQuery('.kleo-wp-menu-item-icon-container').append(SqMenuIcons);

                jQuery(document).on('click', '.kleo-wp-menu-item-icon-select', function (e) {
                    e.preventDefault();
                    if (jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-container').filter(":empty").length) {
                        jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-container').append(SqMenuIcons);
                    }
                    jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-container').toggleClass('open');
                });
                jQuery(document).on('click', '.kleo-wp-menu-item-icon-remove', function (e) {
                    e.preventDefault();
                    jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-selected').html('');
                    jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-value').val('');
                    jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-remove').addClass('hidden');
                    jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-selected').addClass('hidden');
                });

                jQuery(document).on('click', '.kleo-wp-menu-item-icon-item', function (e) {
                    e.preventDefault();
                    jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-container').toggleClass('open');
                    jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-selected').html('<i class="icon-' + jQuery(this).attr('data-value') + '"></i>');
                    jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-value').val(jQuery(this).attr('data-value'));
                    jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-remove').removeClass('hidden');
                    jQuery(this).closest('.menu-item-icons').find('.kleo-wp-menu-item-icon-selected').removeClass('hidden');
                });

            });
        </script>
		<?php
	}

	function kleo_admin_wp_nav_menu_icon_font() {
		wp_register_style( 'kleo-fonts', kleo_get_fonts_path(), array(), SVQ_THEME_VERSION, 'all' );
		wp_enqueue_style( 'kleo-fonts' );
	}

}

if ( ! class_exists( 'Kleo_Walker_Nav_Menu_Edit' ) ) {
	/**
	 *
	 * Create HTML list of nav menu input items.
	 *
	 * @package WordPress
	 * @since 3.0.0
	 * @uses Walker_Nav_Menu
	 */
	class Kleo_Walker_Nav_Menu_Edit extends Walker_Nav_Menu_Edit {
		/**
		 * @param string $output Passed by reference.
		 *
		 * @since 3.0.0
		 *
		 * @see Walker_Nav_Menu::start_lvl()
		 */
		function start_lvl( &$output, $depth = 0, $args = array() ) {
		}

		/**
		 * @param string $output Passed by reference.
		 *
		 * @since 3.0.0
		 *
		 * @see Walker_Nav_Menu::end_lvl()
		 */
		function end_lvl( &$output, $depth = 0, $args = array() ) {
		}

		/**
		 * @param string $output Passed by reference. Used to append additional content.
		 * @param object $item Menu item data object.
		 * @param int $depth Depth of menu item. Used for padding.
		 * @param array $args
		 *
		 * @see Walker::start_el()
		 * @since 3.0.0
		 *
		 */
		function start_el( &$output, $item, $depth = 0, $args = array(), $id = 0 ) {

			parent::start_el( $output, $item, $depth, $args, $id );

			$item_id  = esc_attr( $item->ID );
			$to_add   = '';
			$has_icon = isset( $item->icon ) && '' != $item->icon ? true : false;

			$icon_opts = '<span class="kleo-wp-menu-item-icon-container">';
			$icon_opts .= '</span>';

			$to_add .= '<p class="menu-item-icons">'
			           . '<label for="edit-menu-item-icons-' . $item_id . '">'
			           . '<span class="kleo-wp-menu-item-icon-selected ' . ( $has_icon ? '' : 'hidden' ) . '">'
			           . ( $has_icon ? '<i class="icon-' . $item->icon . '"></i>' : '' )
			           . '</span>'
			           . '<a href="#" class="kleo-wp-menu-item-icon-select">'
			           . esc_html__( 'Choose icon', 'kleo' )
			           . '</a>'
			           . '<a href="#" class="kleo-wp-menu-item-icon-remove ' . ( $has_icon ? '' : 'hidden' ) . '">'
			           . esc_html__( 'Remove icon', 'kleo' )
			           . '</a>'
			           . '<input type="hidden" id="edit-menu-item-icon-' . $item_id . '" name="menu-item-icon[' . $item_id . ']" class="kleo-wp-menu-item-icon-value" value="' . ( $has_icon ? $item->icon : '' ) . '">'
			           . '<select id="edit-menu-item-iconpos-' . $item_id . '" name="menu-item-iconpos[' . $item_id . ']">'
			           . '<option value="">' . esc_html__( 'Before title', 'kleo' ) . '</option>'
			           . '<option' . ( $item->iconpos == 'after' ? ' selected="selected"' : '' ) . ' value="after">' . esc_html__( 'After title', 'kleo' ) . '</option>'
			           . '<option ' . ( $item->iconpos == 'icon' ? ' selected="selected"' : '' ) . ' value="icon">' . esc_html__( 'Just the icon', 'kleo' ) . '</option>'
			           . '</select>'
			           . $icon_opts
			           . '</label>'
			           . '</p>';

			if ( $depth == 0 ) {
				$to_add .= '<p class="menu-item-istyle">
                        <label for="edit-menu-item-istyle-' . $item_id . '">'
				           . esc_html__( 'Menu style', 'kleo' )
				           . ' <select id="edit-menu-item-istyle-' . $item_id . '" name="menu-item-istyle[' . $item_id . ']">'
				           . '<option value="">' . esc_html__( 'Default', 'kleo' ) . '</option>'
				           . '<option' . ( $item->istyle == 'buy' ? ' selected="selected"' : '' ) . ' value="buy">' . esc_html__( 'Buy button', 'kleo' ) . '</option>'
				           . '<option ' . ( $item->istyle == 'border' ? ' selected="selected"' : '' ) . ' value="border">' . esc_html__( 'See through', 'kleo' ) . '</option>'
				           . '<option ' . ( $item->istyle == 'highlight' ? ' selected="selected"' : '' ) . ' value="highlight">' . esc_html__( 'Highlight', 'kleo' ) . '</option>'
				           . '</select>'
				           . '</label>
                    </p>';

				$to_add .= '<p class="menu-item-mega">
                        <label for="edit-menu-item-mega-' . $item_id . '">
                                <input type="checkbox" id="edit-menu-item-mega-' . $item_id . '" value="yes" name="menu-item-mega[' . $item_id . ']"' . ( $item->mega == 'yes' ? 'checked="checked"' : '' ) . ' />'
				           . esc_html__( 'Enable Mega Menu for child items.', 'kleo' )
				           . '</label>
                    </p>';
			}

			ob_start();

			// action for other plugins
			global $wp_version;
			if ( version_compare( $wp_version, '5.4.0', '<' ) ) {
				do_action( 'wp_nav_menu_item_custom_fields', $item_id, $item, $depth, $args );
			}

			$to_add .= ob_get_clean();

			$output = str_replace( '<label for="edit-menu-item-target-' . $item_id . '">', '</p>' . $to_add . '<p class="field-link-target description"><label for="edit-menu-item-target-' . $item_id . '">', $output );

		}

	}
}
// instantiate the custom menu class
$GLOBALS['kleo_custom_menu'] = new kleo_custom_menu();
