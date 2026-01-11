<?php

/***************************************************
 * :: Modal Ajax login && Modal Lost Password
 ***************************************************/

add_action( 'wp_footer', 'kleo_load_popups', 12 );

function kleo_load_popups() {
	if ( ! is_user_logged_in() ) {
		get_template_part( 'page-parts/general-popups' );
	}
}

add_action( 'init', 'kleo_ajax_login' );

if ( ! function_exists( 'kleo_ajax_login' ) ) {
	function kleo_ajax_login() {

		/* If not our action, bail out */
		if ( ! isset( $_POST['action'] ) || ( isset( $_POST['action'] ) && 'kleoajaxlogin' != $_POST['action'] ) ) {
			return false;
		}

		do_action( 'kleo_before_ajax_login' );

		/* If user is already logged in print a specific message */
		if ( is_user_logged_in() ) {

			wp_send_json_success( array(
				'loggedin'    => true,
				'redirecturl' => null,
				'message'     => '<span class="good-response"><i class="icon icon-ok-circled"></i> ' . esc_html__( 'Login successful, redirecting...', 'kleo' ) . '</span>',
			) );

			die();
		}

		// Check the nonce, if it fails the function will break
		check_ajax_referer( 'kleo-ajax-login-nonce', 'sq-login-security' );

		// Nonce is checked, continue
		$secure_cookie = '';

		// If the user wants ssl but the session is not ssl, force a secure cookie.
		if ( ! empty( $_POST['log'] ) && ! force_ssl_admin() ) {
			$user_name = sanitize_user( $_POST['log'] );
			if ( $user = get_user_by( 'login', $user_name ) ) {
				if ( get_user_option( 'use_ssl', $user->ID ) ) {
					$secure_cookie = true;
					force_ssl_admin( true );
				}
			}
		}

		$redirect_to = '';

		if ( isset( $_REQUEST['redirect_to'] ) ) {
			$redirect_to = $_REQUEST['redirect_to'];

		} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$location = $_SERVER['HTTP_REFERER'];
			$parts    = parse_url( $location );
			parse_str( $parts['query'], $query );
			if ( isset( $query['redirect_to'] ) ) {
				$redirect_to = $query['redirect_to'];
			}
		}

		// Redirect to https if user wants ssl
		if ( $redirect_to != '' && $secure_cookie && false !== strpos( $redirect_to, 'wp-admin' ) ) {
			$redirect_to = preg_replace( '|^http://|', 'https://', $redirect_to );
		}

		$user_signon = wp_signon( '', $secure_cookie );
		if ( is_wp_error( $user_signon ) ) {
			$error_msg = $user_signon->get_error_message();
			$error_msg = apply_filters( 'login_errors', $error_msg );

			wp_send_json_error( array(
				'loggedin' => false,
				'message'  => '<span class="wrong-response"><i class="icon icon-attention"></i> ' . $error_msg . '</span>',
			) );

		} else {
			if ( sq_option( 'login_redirect', 'default' ) == 'reload' ) {
				$redirecturl = null;
			} else {
				$requested_redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : '';

				/**
				 * Filter the login redirect URL.
				 *
				 * @param string $redirect_to The redirect destination URL.
				 * @param string $requested_redirect_to The requested redirect destination URL passed as a parameter.
				 * @param WP_User|WP_Error $user WP_User object if login was successful, WP_Error object otherwise.
				 *
				 * @since 3.0.0
				 *
				 */
				$redirecturl = apply_filters( 'login_redirect', $redirect_to, $requested_redirect_to, $user_signon );

				if ( $redirecturl == false ) {
					$redirecturl = '';
				}

			}

			wp_send_json_success( array(
				'loggedin'    => true,
				'redirecturl' => $redirecturl,
				'message'     => '<span class="good-response"><i class="icon icon-ok-circled"></i> ' .
				                 esc_html__( 'Login successful, redirecting...', 'kleo' ) . '</span>',
			) );
		}

		die();
	}
}


if ( ! function_exists( 'kleo_lost_password_ajax' ) ) {
	function kleo_lost_password_ajax() {
		// Check the nonce, if it fails the function will break
		check_ajax_referer( 'kleo-ajax-login-nonce', 'security-pass' );

		$errors = new WP_Error();

		if ( isset( $_POST ) ) {

			if ( empty( $_POST['user_login'] ) ) {
				$errors->add( 'empty_username', wp_kses_post( __( '<strong>ERROR</strong>: Enter a username or email address.', 'default' ) ) );
			} elseif ( strpos( $_POST['user_login'], '@' ) ) {
				$user_data = get_user_by( 'email', trim( $_POST['user_login'] ) );
				if ( empty( $user_data ) ) {
					$errors->add( 'invalid_email', wp_kses_post( __( '<strong>ERROR</strong>: There is no account with that username or email address.', 'default' ) ) );
				}
			} else {
				$login     = trim( $_POST['user_login'] );
				$user_data = get_user_by( 'login', $login );
			}

			/**
			 * Fires before errors are returned from a password reset request.
			 *
			 * @param WP_Error $errors A WP_Error object containing any errors generated
			 *                         by using invalid credentials.
			 *
			 * @since 4.4.0 Added the `$errors` parameter.
			 *
			 * @since 2.1.0
			 */
			do_action( 'lostpassword_post', $errors );

			if ( $errors->get_error_code() ) {
				echo '<span class="wrong-response">' . $errors->get_error_message() . '</span>';
				die();
			}

			if ( ! $user_data ) {
				$errors->add(
					'invalidcombo', wp_kses_data( __( '<strong>ERROR</strong>: Invalid username or e-mail.', 'kleo' ) )
				);
				echo '<span class="wrong-response">' . $errors->get_error_message() . '</span>';
				die();
			}

			// Redefining user_login ensures we return the right case in the email.
			$user_login = $user_data->user_login;
			$user_email = $user_data->user_email;
			$key        = get_password_reset_key( $user_data );

			if ( is_wp_error( $key ) ) {
				echo '<span class="wrong-response">' . $key->get_error_message() . '</span>';
				die();
			}

			$message = esc_html__( 'Someone has requested a password reset for the following account:', 'default' ) . "\r\n\r\n";
			$message .= network_home_url( '/' ) . "\r\n\r\n";
			$message .= sprintf( esc_html__( 'Username: %s', 'default' ), $user_login ) . "\r\n\r\n";
			$message .= esc_html__( 'If this was a mistake, just ignore this email and nothing will happen.', 'default' ) . "\r\n\r\n";
			$message .= esc_html__( 'To reset your password, visit the following address:', 'default' ) . "\r\n\r\n";
			$message .= '<' . network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user_login ), 'login' ) . ">\r\n";

			if ( is_multisite() ) {
				$blogname = get_network()->site_name;
			} else /*
                 * The blogname option is escaped with esc_html on the way into the database
                 * in sanitize_option we want to reverse this for the plain text arena of emails.
                 */ {
				$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
			}

			$title = sprintf( esc_html__( '[%s] Password Reset', 'default' ), $blogname );

			/**
			 * Filters the subject of the password reset email.
			 *
			 * @param string $title Default email title.
			 * @param string $user_login The username for the user.
			 * @param WP_User $user_data WP_User object.
			 *
			 * @since 4.4.0 Added the `$user_login` and `$user_data` parameters.
			 *
			 * @since 2.8.0
			 */
			$title = apply_filters( 'retrieve_password_title', $title, $user_login, $user_data );

			/**
			 * Filters the message body of the password reset mail.
			 *
			 * @param string $message Default mail message.
			 * @param string $key The activation key.
			 * @param string $user_login The username for the user.
			 * @param WP_User $user_data WP_User object.
			 *
			 * @since 2.8.0
			 * @since 4.1.0 Added `$user_login` and `$user_data` parameters.
			 *
			 */
			$message = apply_filters( 'retrieve_password_message', $message, $key, $user_login, $user_data );


			if ( $message && ! wp_mail( $user_email, wp_specialchars_decode( $title ), $message ) ) {
				echo '<span class="wrong-response">' . esc_html__( 'Failure!', 'kleo' );
				echo esc_html__( 'The email could not be sent.', 'default' );
				echo '</span>';
				die();
			} else {
				echo '<span class="good-response">' . esc_html__( 'Email successfully sent!', 'kleo' ) . '</span>';
				die();
			}
		}
		die();
	}
}
add_action( "wp_ajax_kleo_lost_password", "kleo_lost_password_ajax" );
add_action( 'wp_ajax_nopriv_kleo_lost_password', 'kleo_lost_password_ajax' );

/**
 * Custom login failed 403
 *
 * This is the same as the default version provided by WP Engine,
 * except that we don't send the 403 when the request is an Ajax
 * request
 *
 * @since 1.0
 */
if ( ! function_exists( 'svq_login_failed_403' ) ) {
	remove_action( 'wp_login_failed', 'wpe_login_failed_403' );
	add_action( 'wp_login_failed', 'svq_login_failed_403' );

	function svq_login_failed_403() {
		// Don't 403 for Ajax requests
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( isset( $_POST['action'] ) && $_POST['action'] == 'kleoajaxlogin' ) ) {
			return;
		}

		status_header( 403 );
	}
}
