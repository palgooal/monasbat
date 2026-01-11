<?php
/***************************************************
 * :: Bottom contact form
 ***************************************************/

if ( ! function_exists( 'kleo_contact_form' ) ) {
	function kleo_contact_form( $atts, $content = null ) {
		$title = $builtin_form = '';

		extract( shortcode_atts( array(
			'title'        => 'CONTACT US',
			'builtin_form' => 1
		), $atts ) );

		$output = '';

		$output .= '<div class="kleo-quick-contact-wrapper">'
		           . '<a class="kleo-quick-contact-link" href="#"><i class="icon-mail-alt"></i></a>'
		           . '<div id="kleo-quick-contact">'
		           . '<h4 class="kleo-qc-title">' . esc_html( $title ) . '</h4>'
		           . '<p>' . do_shortcode( wp_kses_post( $content ) ) . '</p>';
		if ( $builtin_form == 1 ) {
			$output .= '<form class="kleo-contact-form" action="#" method="post" novalidate>'
			           . '<input type="text" placeholder="' . esc_html__( "Your Name", 'kleo' ) . '" required id="contact_name" name="contact_name" class="form-control" value="" tabindex="276" />'
			           . '<input type="email" required placeholder="' . esc_html__( "Your Email", 'kleo' ) . '" id="contact_email" name="contact_email" class="form-control" value="" tabindex="277"  />'
			           . '<textarea placeholder="' . esc_html__( "Type your message...", 'kleo' ) . '" required id="contact_content" name="contact_content" class="form-control" tabindex="278"></textarea>'
			           . '<input type="hidden" name="action" value="kleo_sendmail">'
			           . '<button tabindex="279" class="btn btn-default pull-right" type="submit">' . esc_html__( "Send", 'kleo' ) . '</button>'
			           . '<div class="kleo-contact-loading">' . esc_html__( "Sending", 'kleo' ) . ' <i class="icon-spinner icon-spin icon-large"></i></div>'
			           . '<div class="kleo-contact-success"> </div>'
			           . '</form>';
		}
		$output .= '<div class="bottom-arrow"></div>'
		           . '</div>'
		           . '</div><!--end kleo-quick-contact-wrapper-->';

		return $output;
	}

}


if ( ! function_exists( 'kleo_sendmail' ) ):
	function kleo_sendmail() {

		$error_tpl = "<span class='mail-error'>%s</span>";

		if ( isset( $_POST['action'] ) ) {

			//contact name
			if ( trim( $_POST['contact_name'] ) === '' ) {
				printf( $error_tpl, esc_html__( 'Please enter your name.', 'kleo' ) );
				die();
			} else {
				$name = trim( $_POST['contact_name'] );
			}

			///contact email
			if ( trim( $_POST['contact_email'] ) === '' ) {
				printf( $error_tpl, esc_html__( 'Please enter your email address.', 'kleo' ) );
				die();
			} elseif ( ! preg_match( "/^[[:alnum:]][a-z0-9_.-]*@[a-z0-9.-]+.[a-z]{2,4}$/i", trim( $_POST['contact_email'] ) ) ) {
				printf( $error_tpl, esc_html__( 'You entered an invalid email address.', 'kleo' ) );
				die();
			} else {
				$email = trim( $_POST['contact_email'] );
			}

			//message
			if ( trim( $_POST['contact_content'] ) === '' ) {
				printf( $error_tpl, esc_html__( 'Please enter a message.', 'kleo' ) );
				die();
			} else {
				if ( function_exists( 'stripslashes' ) ) {
					$comment = stripslashes( trim( $_POST['contact_content'] ) );
				} else {
					$comment = trim( $_POST['contact_content'] );
				}
			}

			$emailTo = sq_option( 'contact_form_to', '' );
			if ( ! isset( $emailTo ) || ( $emailTo == '' ) ) {
				$emailTo = get_option( 'admin_email' );
			}

			$subject = esc_html__( 'Contact Form Message', 'kleo' );
			apply_filters( 'kleo_contact_form_subject', $subject );

			$body = esc_html__( "You received a new contact form message:", 'kleo' ) . "\n";
			$body .= esc_html__( "Name: ", 'kleo' ) . $name . "\n";
			$body .= esc_html__( "Email: ", 'kleo' ) . $email . "\n";
			$body .= esc_html__( "Message: ", 'kleo' ) . $comment . "\n";

			$headers[] = "Content-type: text/html";
			$headers[] = "Reply-To: $name <$email>";
			apply_filters( 'kleo_contact_form_headers', $headers );

			if ( wp_mail( $emailTo, $subject, $body, $headers ) ) {
				echo '<span class="mail-success">' . esc_html__( "Thank you. Your message has been sent.", 'kleo' ) . ' <i class="icon-ok icon-large"></i></span>';

				do_action( 'kleo_after_contact_form_mail_send', $name, $email, $comment );
			} else {
				printf( $error_tpl, esc_html__( "Mail couldn't be sent. Please try again!", 'kleo' ) );
			}

		} else {
			printf( $error_tpl, esc_html__( "Unknown error occurred. Please try again!", 'kleo' ) );
		}
		die();
	}
endif;

function kleo_show_contact_form() {
	$title        = sq_option( 'contact_form_title', '' );
	$content      = sq_option( 'contact_form_text', '' );
	$builtin_form = sq_option( 'contact_form_builtin', 1 );

	$data = [
		'title' => $title,
		'builtin_form' => $builtin_form
	];
	echo kleo_contact_form( $data, $content );
}

if ( sq_option( 'contact_form', 1 ) == 1 ) {
	add_action( 'wp_ajax_kleo_sendmail', 'kleo_sendmail' );
	add_action( 'wp_ajax_nopriv_kleo_sendmail', 'kleo_sendmail' );
	add_action( 'kleo_after_footer', 'kleo_show_contact_form' );
}

add_shortcode( 'kleo_contact_form', 'kleo_contact_form' );
