<?php
/**
 * Facebook Login Module
 */

/* Load facebook login if it is enabled in theme options */
if ( sq_option( 'facebook_login', 1 ) != 1 || sq_option( 'fb_app_id', '' ) == '' ) {
	return;
}

if ( ! function_exists( 'kleo_fb_admin_notice' ) ) {
	function kleo_fb_admin_notice() {
		// Only show to administrators
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if Facebook login is enabled but app secret is missing
		if ( sq_option( 'facebook_login', 0 ) == 1 && empty( sq_option( 'fb_app_secret', '' ) ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php 
					printf(
						/* translators: %s: Theme options URL */
						esc_html__( 'Facebook Login is enabled but the App Secret is not configured. Please %s to set up your Facebook App Secret.', 'kleo' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=kleo_options&tab=27' ) ) . '">' . esc_html__( 'visit the Theme Options', 'kleo' ) . '</a>'
					); 
					?>
				</p>
			</div>
			<?php
		}
	}
}

if ( ! function_exists( 'kleo_fb_head' ) ) {
	/**
	 * @return bool|string
	 */
	function kleo_fb_head() {

		if ( is_user_logged_in() ) {
			return false;
		}

		?>
        <div id="fb-root"></div>
		<?php
	}
}
if ( ! function_exists( 'kleo_fb_footer' ) ) {

	function kleo_fb_footer() {

		if ( is_user_logged_in() ) {
			return false;
		}

		?>
        <script async defer crossorigin="anonymous" src="https://connect.facebook.net/<?php echo apply_filters( 'kleo_facebook_js_locale', 'en_US' ); ?>/sdk.js"></script>
        <script>
            // Additional JS functions here
            window.fbAsyncInit = function () {
                FB.init({
                    appId: '<?php echo sq_option( 'fb_app_id' ); ?>', // App ID
                    version: 'v21.0',
                    status: true, // check login status
                    cookie: true, // enable cookies to allow the server to access the session
                    xfbml: true  // parse XFBML
                });

                // Additional init code here
                jQuery('body').trigger('sq_fb.init');

            };
        </script>

        <script type="text/javascript">
            var fbAjaxUrl = '<?php echo site_url( 'wp-login.php', 'login_post' ); ?>';

            jQuery(document).ready(function () {

                jQuery('.kleo-facebook-connect').on('click', function () {

                    // fix iOS Chrome
                    if (navigator.userAgent.match('CriOS') ) {
                        window.open('https://www.facebook.com/dialog/oauth?client_id=<?php echo sq_option( 'fb_app_id' ); ?>' +
                        + '&redirect_uri=<?php echo esc_url( home_url( '/') ); ?>&scope=email&response_type=token', '', null);
                    } else {
                        FB.login(function (FB_response) {
                                if (FB_response.authResponse) {
                                    fb_intialize(FB_response, '');
                                }
                            },
                            {
                                scope: 'email',
                                auth_type: 'rerequest',
                                return_scopes: true
                            });
                    }
                });

                //if (navigator.userAgent.match('CriOS') || navigator.userAgent.match(/Android/i)) {
                jQuery("body").on("sq_fb.init", function () {
                    var accToken = jQuery.getUrlVar('#access_token');
                    if (accToken) {
                        var fbArr = {scopes: "email"};
                        fb_intialize(fbArr, accToken);
                    }
                });
                //}

            });

            function fb_intialize(FB_response, token) {
                // Use the access token from the response if available, otherwise use the provided token
                var accessToken = (FB_response.authResponse && FB_response.authResponse.accessToken) ? 
                                 FB_response.authResponse.accessToken : 
                                 token;

                FB.api('/me', {
                        fields: 'id,email,name',
                        access_token: accessToken  // Pass token directly in API call
                    },
                    function (FB_userdata) {
                        jQuery.ajax({
                            type: 'POST',
                            url: fbAjaxUrl,
                            data: {
                                "action": "fb_intialize", 
                                "FB_response": {
                                    authResponse: {
                                        accessToken: accessToken
                                    }
                                }
                            },
                            success: function (user) {
                                if (user.error) {
                                    alert(user.error);
                                } else if (user.loggedin) {
                                    jQuery('#kleo-login-result').html(user.message);

                                    if (window.location.href.indexOf("wp-login.php") > -1) {
                                        window.location = user.url;
                                    } else if (user.redirectType == 'reload') {
                                        window.location.reload();
                                    } else {
                                        window.location = user.url;
                                    }
                                }
                            }
                        });
                    }
                );
            }

            jQuery.extend({
                getUrlVars: function () {
                    var vars = [], hash;
                    var hashes = window.location.href.slice(window.location.href.indexOf('?') + 1).split('&');
                    for (var i = 0; i < hashes.length; i++) {
                        hash = hashes[i].split('=');
                        vars.push(hash[0]);
                        vars[hash[0]] = hash[1];
                    }
                    return vars;
                },
                getUrlVar: function (name) {
                    return jQuery.getUrlVars()[name];
                }
            });
        </script>
		<?php
	}
}

if ( ! function_exists( 'kleo_fb_loginform_script' ) ) {

	function kleo_fb_loginform_script() {
		//Enqueue jQuery
		wp_enqueue_script( 'jquery' );

		//Output CSS
		echo '<style type="text/css" media="screen">
		.hr-title, .gap-30, .gap-10 {display: none;}
    .kleo-facebook-connect.btn.btn-default {
      background-color: #3b5997;
      border-color: #2b4780;
      color: #fff;
      border-radius: 2px;
      font-size: 13px;
      font-weight: normal;
      margin: 3px 0;
      min-width: 80px;
      transition: all 0.4s ease-in-out 0s;
      cursor: pointer;
      display: inline-block;
      line-height: 1.42857;
      padding: 6px 12px;
      text-align: center;
      text-decoration: none;
      vertical-align: middle;
      white-space: nowrap;
    }
		</style>';
	}
}

if ( sq_option( 'facebook_login', 0 ) == 1 ) {
	add_action( 'kleo_after_body', 'kleo_fb_head' );
	add_action( 'login_head', 'kleo_fb_head' );
	add_action( 'login_head', 'kleo_fb_loginform_script' );
	add_action( 'wp_footer', 'kleo_fb_footer', 99 );
	add_action( 'login_footer', 'kleo_fb_footer', 99 );
	add_action( 'admin_notices', 'kleo_fb_admin_notice' );
}

if ( ! function_exists( 'kleo_verify_facebook_token_and_get_data' ) ) {
	function kleo_verify_facebook_token_and_get_data( $access_token ) {
		$app_token = sq_option( 'fb_app_id' ) . '|' . sq_option( 'fb_app_secret' );
		
		// Verify token with Facebook
		$verify_url = 'https://graph.facebook.com/debug_token?' . http_build_query( array(
			'input_token'  => $access_token,
			'access_token' => $app_token
		) );

		$response = wp_remote_get( $verify_url );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fb_verify_failed', esc_html__( 'Failed to verify Facebook token.', 'kleo' ) );
		}

		$verify_data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $verify_data['data']['is_valid'] ) || ! $verify_data['data']['is_valid'] ) {
			return new WP_Error( 'fb_invalid_token', esc_html__( 'Invalid Facebook token.', 'kleo' ) );
		}

		// Get user data from Facebook
		$graph_url = 'https://graph.facebook.com/me?' . http_build_query( array(
			'fields'       => 'id,email,name,first_name,last_name',
			'access_token' => $access_token
		) );

		$response = wp_remote_get( $graph_url );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fb_data_failed', esc_html__( 'Failed to get Facebook user data.', 'kleo' ) );
		}

		$user_data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $user_data['id'] ) ) {
			return new WP_Error( 'fb_invalid_data', esc_html__( 'Invalid Facebook user data.', 'kleo' ) );
		}

		return $user_data;
	}
}

if ( ! function_exists( 'kleo_fb_intialize' ) ) {

	function kleo_fb_intialize() {

		/* If not our action, bail out */
		if ( ! isset( $_POST['action'] ) || ( isset( $_POST['action'] ) && $_POST['action'] != 'fb_intialize' ) ) {
			return false;
		}

		@error_reporting( 0 ); // Don't break the JSON result
		header( 'Content-type: application/json' );

		if ( is_user_logged_in() ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'You are already logged in.', 'kleo' ) ) ) );
		}

		// Verify Facebook access token
		if ( ! isset( $_REQUEST['FB_response']['authResponse']['accessToken'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Invalid Facebook authentication.', 'kleo' ) ) ) );
		}

		$access_token = sanitize_text_field( $_REQUEST['FB_response']['authResponse']['accessToken'] );
		
		// Get verified Facebook data
		$fb_user_data = kleo_verify_facebook_token_and_get_data( $access_token );
		if ( is_wp_error( $fb_user_data ) ) {
			die( wp_json_encode( array( 'error' => $fb_user_data->get_error_message() ) ) );
		}

		// Use verified data instead of direct POST data
		$FB_userid = $fb_user_data['id'];
		$user_email = $fb_user_data['email'];

		if ( ! $FB_userid ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Please connect your facebook account.', 'kleo' ) ) ) );
		}

		global $wpdb;
		// Use prepared statement for security
		$user_ID = $wpdb->get_var( $wpdb->prepare( 
			"SELECT user_id FROM $wpdb->usermeta WHERE meta_key = '_fbid' AND meta_value = %s",
			$FB_userid
		) );

		$redirect      = '';
		$redirect_type = 'redirect';

		//if facebook is not connected
		if ( ! $user_ID ) {
			$user_ID    = $wpdb->get_var( $wpdb->prepare( 
				"SELECT ID FROM $wpdb->users WHERE user_email = %s",
				$user_email
			) );

			//Register user
			if ( ! $user_ID ) {
				if ( ! get_option( 'users_can_register' ) ) {
					die( wp_json_encode( array( 'error' => esc_html__( 'Registration is not open at this time. Please come back later.', 'kleo' ) ) ) );
				}
				if ( sq_option( 'facebook_register', 0 ) == 0 ) {
					die( wp_json_encode( array( 'error' => esc_html__( 'Registration using Facebook is not currently allowed. Please use our Register page', 'kleo' ) ) ) );
				}

				$display_name = $fb_user_data['name'];
				$name_array = explode( ' ', $fb_user_data['name'], 2 );
				$first_name = $fb_user_data['first_name'];
				$last_name = $fb_user_data['last_name'];

				if ( empty( $user_email ) ) {
					die( wp_json_encode( array( 'error' => esc_html__( 'Please click again to login with Facebook and allow the application to use your email address', 'kleo' ) ) ) );
				}

				if ( empty( $fb_user_data['name'] ) ) {
					die( wp_json_encode( array(
						'error' => 'empty_name',
						esc_html__( 'We didn\'t find your name. Please complete your facebook account before proceeding.', 'kleo' )
					) ) );
				}

				$user_login = sanitize_title_with_dashes( sanitize_user( $display_name, true ) );

				if ( username_exists( $user_login ) ) {
					$user_login = $user_login . time();
				}

				$user_pass = wp_generate_password( 12, false );
				$userdata  = compact( 'user_login', 'user_email', 'user_pass', 'display_name', 'first_name', 'last_name' );
				$userdata  = apply_filters( 'kleo_fb_register_data', $userdata );

				$user_ID = wp_insert_user( $userdata );
				if ( is_wp_error( $user_ID ) ) {
					die( wp_json_encode( array( 'error' => $user_ID->get_error_message() ) ) );
				}

				if ( sq_option( 'facebook_sent_email_login_details', '1' ) == '1' ) {
					//send email with password
					wp_new_user_notification( $user_ID, wp_unslash( $user_pass ) );
				}
				//add Facebook image
				update_user_meta( $user_ID, 'kleo_fb_picture', 'https://graph.facebook.com/' . $fb_user_data['id'] . '/picture' );

				do_action( 'fb_register_action', $user_ID );
				do_action( 'user_register', $user_ID );

				update_user_meta( $user_ID, '_fbid', $fb_user_data['id'] );

				// Try to update Bp name with Facebook data
				if ( function_exists( 'xprofile_set_field_data' ) ) {
					xprofile_set_field_data( 1, $user_ID, $display_name );
				}

				$logintype = 'register';

				/* Registration logic redirect */
				if ( function_exists( 'bp_is_active' ) && sq_option( 'facebook_register_redirect', 'default' ) == 'default' ) {
					$redirect_url = trailingslashit( bp_members_get_user_url( $user_ID ) ) . 'profile/edit/group/1/?fb=registered';
				} elseif ( sq_option( 'facebook_register_redirect', 'default' ) == 'reload' ) {
					$redirect_type = 'reload';
					$redirect_url  = home_url();
				} elseif ( sq_option( 'facebook_register_redirect', 'default' ) == 'custom' ) {
					$redirect_url = sq_option( 'facebook_register_redirect_url', '' );
					if ( function_exists( 'bp_is_active' ) ) {
						$logged_in_link = bp_members_get_user_url( $user_ID );
						$redirect_url   = str_replace( '##profile_link##', $logged_in_link, $redirect_url );
					}
				}

				if ( ! isset( $redirect_url ) || empty( $redirect_url ) ) {
					$redirect_type = 'reload';
					$redirect_url  = home_url();
				}

				$redirect = apply_filters( 'kleo_fb_register_redirect', $redirect_url, $user_ID );
			} else {
				update_user_meta( $user_ID, '_fbid', $fb_user_data['id'] );
				//add Facebook image
				update_user_meta( $user_ID, 'kleo_fb_picture', 'https://graph.facebook.com/' . $fb_user_data['id'] . '/picture' );
				$logintype = 'login';
			}
		} else {
			$logintype = 'login';
		}

		$user = get_user_by( 'id', $user_ID );

		if ( $logintype == 'login' ) {

			$redirect_to = home_url();
			if ( function_exists( 'bp_is_active' ) ) {
				$redirect_to = bp_members_get_user_url( $user_ID );
			}

			/* Check the configured type of redirect */
			if ( sq_option( 'login_redirect' ) == 'reload' ) {
				$redirect_type = 'reload';
			}

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

			$redirect = apply_filters( 'login_redirect', $redirect_to, '', $user );
		}

		if ( ! $redirect || empty( $redirect ) ) {
			$redirect = home_url();
		}

		wp_set_auth_cookie( $user_ID, false, false );
		/**
		 * Fires after the user has successfully logged in.
		 *
		 * @param string $user_login Username.
		 * @param WP_User $user WP_User object of the logged-in user.
		 *
		 * @since 1.5.0
		 *
		 */
		do_action( 'wp_login', $user->user_login, $user );

		die ( wp_json_encode( array(
			'loggedin'     => true,
			'type'         => $logintype,
			'url'          => $redirect,
			'redirectType' => $redirect_type,
			'message'      => esc_html__( 'Login successful, redirecting...', 'kleo' ),
		) ) );
	}
}

if ( ! is_admin() ) {
	add_action( 'init', 'kleo_fb_intialize' );
}


//If registered via Facebook -> show message
add_action( 'template_notices', 'kleo_fb_register_message' );
if ( ! function_exists( 'kleo_fb_register_message' ) ) {
	function kleo_fb_register_message() {
		if ( isset( $_GET['fb'] ) && $_GET['fb'] == 'registered' ) {
			echo '<div class="clearfix"></div><div class="alert alert-success" id="message" data-alert>';
			echo esc_html__( 'Thank you for registering. Please make sure to complete your profile fields below.', 'kleo' );
			echo '</div>';
		}
	}
}


//display Facebook avatar
if ( sq_option( 'facebook_avatar', 1 ) == 1 ) {
	//show Facebook avatar in WP
	add_filter( 'get_avatar', 'kleo_fb_show_avatar', 5, 5 );
	//show Facebook avatar in Buddypress
	add_filter( 'bp_core_fetch_avatar', 'kleo_fb_bp_show_avatar', 3, 5 );
	//show Facebook avatar in Buddypress - url version
	add_filter( 'bp_core_fetch_avatar_url', 'kleo_fb_bp_show_avatar_url', 3, 2 );
}

if ( ! function_exists( 'kleo_fb_show_avatar' ) ) {

	function kleo_fb_show_avatar( $avatar = '', $id_or_email = null, $size = 96, $default = '', $alt = false ) {
		$id = 0;

		if ( is_numeric( $id_or_email ) ) {
			$id = $id_or_email;
		} elseif ( is_string( $id_or_email ) ) {
			$u = get_user_by( 'email', $id_or_email );
			if ( $u ) {
				$id = $u->id;
			}
		} elseif ( is_object( $id_or_email ) ) {
			$id = $id_or_email->user_id;
		}

		if ( $id == 0 ) {
			return $avatar;
		}

		//if we have an avatar uploaded and is not Gravatar return it
		if ( strpos( $avatar, home_url() ) !== false && strpos( $avatar, 'gravatar' ) === false ) {
			return $avatar;
		}

		//if we don't have a Facebook photo
		$pic = get_user_meta( $id, 'kleo_fb_picture', true );
		if ( ! $pic || $pic == '' ) {
			return $avatar;
		}

		$avatar = preg_replace( '/src=("|\').*?("|\')/i', 'src=\'' . $pic . apply_filters( 'fb_show_avatar_params', '?width=580&amp;height=580' ) . '\'', $avatar );

		return $avatar;
	}
}

if ( ! function_exists( 'kleo_fb_bp_show_avatar' ) ) {
	function kleo_fb_bp_show_avatar( $avatar = '', $params = [], $id = null ) {
		if ( ! is_numeric( $id ) || strpos( $avatar, 'gravatar' ) === false ) {
			return $avatar;
		}

		//if we have an avatar uploaded and is not Gravatar return it
		if ( strpos( $avatar, home_url() ) !== false && strpos( $avatar, 'gravatar' ) === false ) {
			return $avatar;
		}

		$pic = get_user_meta( $id, 'kleo_fb_picture', true );
		if ( ! $pic || $pic == '' ) {
			return $avatar;
		}
		$avatar = preg_replace( '/src=("|\').*?("|\')/i', 'src=\'' . $pic . apply_filters( 'fb_show_avatar_params', '?width=580&amp;height=580' ) . '\'', $avatar );

		return $avatar;
	}
}
if ( ! function_exists( 'kleo_fb_bp_show_avatar_url' ) ) {
	function kleo_fb_bp_show_avatar_url( $gravatar, $params ) {

		//if we have an avatar uploaded and is not Gravatar return it
		if ( strpos( $gravatar, home_url() ) !== false && strpos( $gravatar, 'gravatar' ) === false ) {
			return $gravatar;
		}

		$pic = get_user_meta( $params['item_id'], 'kleo_fb_picture', true );
		if ( ! $pic || $pic == '' ) {
			return $gravatar;
		}

		return $pic . apply_filters( 'fb_show_avatar_params', '?width=580&amp;height=580' );
	}
}


/* Add a new activity stream when registering with Facebook */
if ( ! function_exists( 'sq_fb_register_activity' ) ) {
	/**
	 * @param int $user_id
	 *
	 * @return void
	 */
	function sq_fb_register_activity( $user_id ) {

		if ( ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$userlink = bp_core_get_userlink( $user_id );
		bp_activity_add( array(
			'user_id'   => $user_id,
			'action'    => apply_filters( 'xprofile_fb_register_action', sprintf( __( '%s became a registered member', 'buddypress' ), $userlink ), $user_id ),
			'component' => 'xprofile',
			'type'      => 'new_member',
		) );
	}
}
add_action( 'fb_register_action', 'sq_fb_register_activity' );

/* CUSTOM KLEO */

if ( ! function_exists( 'kleo_fb_button' ) ) :
	function kleo_fb_button() {
		echo kleo_get_fb_button();
	}
endif;
if ( ! function_exists( 'kleo_get_fb_button' ) ) :
	function kleo_get_fb_button() {
		ob_start();
		?>
        <div class="kleo-fb-wrapper text-center">
            <a href="#" class="kleo-facebook-connect btn btn-default "><i class="icon-facebook"></i>
                &nbsp;<?php esc_html_e( "Log in with Facebook", 'kleo' ); ?></a>
        </div>
        <div class="gap-20"></div>
        <div class="hr-title hr-full"><abbr> <?php esc_html_e( "or", 'kleo' ); ?> </abbr></div>
		<?php

		$output = ob_get_clean();

		return $output;
	}
endif;

if ( ! function_exists( 'kleo_fb_button_regpage' ) ) :
	function kleo_fb_button_regpage() {
		echo kleo_get_fb_button_regpage();
	}
endif;
if ( ! function_exists( 'kleo_get_fb_button_regpage' ) ) :
	function kleo_get_fb_button_regpage() {
		ob_start();
		?>
        <div class="kleo-fb-wrapper text-center">
            <a href="#" class="kleo-facebook-connect btn btn-default "><i class="icon-facebook"></i>
                &nbsp;<?php esc_html_e( "Log in with Facebook", 'kleo' ); ?></a>
        </div>
        <div class="gap-30"></div>
        <div class="hr-title hr-full"><abbr> <?php esc_html_e( "or", 'kleo' ); ?> </abbr></div>
        <div class="gap-10"></div>
		<?php
		$output = ob_get_clean();

		return $output;
	}
endif;

if ( ! function_exists( 'kleo_fb_button_shortcode' ) ) :
	function kleo_fb_button_shortcode() {
		$output = '';
		if ( sq_option( 'facebook_login', 0 ) == 1 && get_option( 'users_can_register' ) && ! is_user_logged_in() ) {
			$output .= '<a href="#" class="kleo-facebook-connect btn btn-default "><i class="icon-facebook"></i> &nbsp; ' . esc_html__( "Log in with Facebook", 'kleo' ) . '</a>';
		}

		return $output;
	}

	add_shortcode( 'kleo_fb_button', 'kleo_fb_button_shortcode' );
endif;

if ( sq_option( 'facebook_login', 0 ) == 1 ) {
	add_action( 'bp_before_login_widget_loggedout', 'kleo_fb_button' );
	add_action( 'login_form', 'kleo_fb_button', 10 );
	add_action( 'kleo_before_login_form', 'kleo_fb_button', 10 );
	add_action( 'kleo_before_register_form_modal', 'kleo_fb_button', 10 );

	if ( class_exists( 'WooCommerce' ) ) {
		add_action( 'woocommerce_login_form_start', 'kleo_fb_button', 10 );
	}

	if ( sq_option( 'facebook_register', 0 ) == 1 ) {
		add_action( 'bp_before_register_page', 'kleo_fb_button_regpage' );
	}
}
