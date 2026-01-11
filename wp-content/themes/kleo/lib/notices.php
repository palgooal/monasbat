<?php
/**
 * Admin notices
 */

add_action( 'admin_notices', 'kleo_admin_notices' );

function kleo_admin_notices() {
	if ( current_user_can( 'list_users' ) ) {
		kleo_required_k_elements();
		kleo_rename_mo_files();
	}
}

function kleo_rename_mo_files() {

	$option_name = 'kleo_mo_files_49';
	$site_option = get_option( $option_name );

	if ( ! $site_option || 'hide' !== $site_option ) {
		update_option( $option_name, 'show' );

		?>
		<div class="updated kleo-translation-notice">
			<p>
				<span>
					<?php
					$message = esc_html__( 'Kleo needs to update your translation files saved under wp-content/languages/themes to match the new translation domain. Please create a backup of your files and database first.', 'kleo' );
					?>
					<?php echo wp_kses_post( $message ); ?>
					<?php
					$link  = add_query_arg( array( 'kleo-update-domain' => '' ) );
					echo '<a href="' . esc_url( $link ) . '">' . esc_html__( 'Update', 'kleo' ) . '</a>';
					?>
				</span>
				<a href="#"
				   onclick="kleo_hide_translation_notice('<?php echo esc_js( wp_create_nonce( 'kleo_notice' ) ); ?>');"
				   style="float:right"><?php esc_html_e( 'Dismiss', 'kleo' ); ?></a>
			</p>
		</div>
		<?php

		?>
		<script type="text/javascript">
			function kleo_hide_translation_notice(nonce) {
				var data = {action: 'kleo_update_translation_notice', _kleo_nonce: nonce };
				jQuery.post(ajaxurl, data, function (response) {
					response = response.trim();

					if (response === "1")
						jQuery('.kleo-translation-notice').remove();
				});
			}
		</script>
		<?php
	}
}

add_action( 'admin_init', 'kleo_update_translation_files' );
function kleo_update_translation_files() {

	if( ! isset( $_GET['kleo-update-domain'] ) || ! current_user_can( 'list_users' ) ) {
		return;
	}

	$option_name = 'kleo_mo_files_49';
	$site_option = get_option( $option_name );

	if ( ! $site_option || 'hide' !== $site_option ) {

		//WPML String translation plugin
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			global $wpdb;
			$wpdb->query( "UPDATE `" . $wpdb->prefix . "icl_strings` SET `domain_name_context_md5`=MD5(CONCAT(`context`,`name`,`gettext_context`)), `context`='kleo' WHERE `context`='kleo_framework'" );
		}

		// File languages rename
		$file_list = list_files( WP_CONTENT_DIR . '/languages/themes' );
		$loco_file_list = list_files( WP_CONTENT_DIR . '/languages/loco/themes' );
		if( ! empty( $loco_file_list ) ) {
			$file_list = array_merge( $file_list, $loco_file_list );
		}

		if ( ! empty( $file_list ) ) {
			foreach ( $file_list as $item ) {
				if ( strpos( $item, 'kleo_framework' ) !== false ) {
					$new_path = str_replace( 'kleo_framework', 'kleo', $item );
					copy( $item, $new_path );
				}
			}
		}

		update_option( $option_name, 'hide' );
	}
}

/*
 * Hide notice
 */

add_action( 'wp_ajax_kleo_update_translation_notice', 'kleo_update_translation_notice' );
function kleo_update_translation_notice() {
	if ( check_ajax_referer( 'kleo_notice', '_kleo_nonce' ) ) {
		update_option( 'kleo_mo_files_49', 'hide' );
		echo '1';
	} else {
		echo '0';
	}
	die();
}

function kleo_required_k_elements() {

	$k_version = kleo_get_plugin_version( 'k-elements' );

	if ( defined( 'K_ELEM_VERSION' ) && version_compare( K_ELEM_VERSION, $k_version, '>=' )  ) {
		return;
	}

	$option_name = 'kleo_k_elements_notice';
	$site_option = get_option( $option_name );

	if ( ( ! $site_option || 'hide' !== $site_option ) ) {
		update_option( $option_name, 'show' );
		if ( ! defined( 'K_ELEM_VERSION' ) ) {

			?>
			<div class="updated kleo-update-notice">
				<p>
						<span>
							<?php
							$message =
								sprintf(
									__( 'K Elements plugin must be enabled to take full advantage of KLEO theme. <a href="%s">Activate plugin</a>', 'kleo' ),
									admin_url( 'themes.php?page=install-required-plugins' )
								);
							?>
							<?php echo wp_kses_post( $message ); ?>
						</span>
					<a href="#"
					   onclick="kleo_hide_k_elements_notice('<?php echo esc_js( wp_create_nonce( 'kleo_notice' ) ); ?>');"
					   style="float:right"><?php esc_html_e( 'Dismiss', 'kleo' ); ?></a>
				</p>
			</div>
			<?php

		} else if ( version_compare( K_ELEM_VERSION, $k_version, '<' ) ) {
			?>
			<div class="updated kleo-update-notice">
				<p>
						<span>
							<?php
							$message =
								sprintf(
									__( 'K Elements plugin must be updated to take full advantage of KLEO theme. <a href="%s">Update plugin</a>', 'kleo' ),
									admin_url( 'themes.php?page=install-required-plugins' )
								);
							?>
							<?php echo wp_kses_post( $message ); ?>
						</span>
					<a href="#"
					   onclick="kleo_hide_k_elements_notice('<?php echo esc_js( wp_create_nonce( 'kleo_notice' ) ); ?>');"
					   style="float:right"><?php esc_html_e( 'Dismiss', 'kleo' ); ?></a>
				</p>
			</div>
			<?php
		}

		?>
		<script type="text/javascript">
			function kleo_hide_k_elements_notice(nonce) {
				var data = {action: 'kleo_hide_k_elements_notice', _kleo_nonce: nonce };
				jQuery.post(ajaxurl, data, function (response) {
					response = response.trim();

					if (response === "1")
						jQuery('.kleo-update-notice').remove();
				});
			}
		</script>
		<?php
	}
}

/*
 * Hide notice
 */

add_action( 'wp_ajax_kleo_hide_k_elements_notice', 'kleo_hide_k_elements_notice' );
function kleo_hide_k_elements_notice() {
	if ( check_ajax_referer( 'kleo_notice', '_kleo_nonce' ) && update_option( 'kleo_k_elements_notice', 'hide' ) ) {
		echo '1';
	} else {
		echo '0';
	}
	die();
}

