<?php

class Youzify_LearnDash_Certificates_Tab {

	/**
	 * Tab Content
	 */
	function tab( $args = array() ) {

		// Get Course
		$course_id = isset( $args['course_id'] ) ? $args['course_id'] : '';

		// Get Displayed User ID
        $user_id = isset( $args['user_id'] ) ? $args['user_id'] : bp_displayed_user_id();

        // Certificate 
		$certificates = $this->get_user_certificates( $user_id, $course_id );

		// Call Tab
		youzify_get_user_certificates( $certificates );

	}

	function get_user_certificates( $user_id = '', $course_id = '' ) {

	    if ( empty( $user_id ) ) {
	        return false;
	    }
	 
	    /**
	     * Course Certificate
	     **/
	    $certificates = array();

	    $user_courses = ! empty( $course_id ) ? array( $course_id ) : ld_get_mycourses( $user_id, array() );

	    foreach ( $user_courses as $course_id ) {
	 
	        $certificateLink = learndash_get_course_certificate_link( $course_id, $user_id );
	        $filename        = "Certificate.pdf";
	        $course_title    = get_the_title( $course_id );
	        $certificate_id  = learndash_get_setting( $course_id, 'certificate' );
	        $image           = '';
	 
	        if ( ! empty( $certificate_id ) ) {
	            $certificate_data = get_post( $certificate_id );
	            $filename         = sanitize_file_name( $course_title ) . "-" . sanitize_file_name( $certificate_data->post_title ) . ".pdf";
	            $image            = wp_get_attachment_url( get_post_thumbnail_id( $certificate_id ) );
	        }
	 
	        $date = get_user_meta( $user_id, 'course_completed_' . $course_id, true );
	 
	        if ( ! empty( $certificateLink ) ) {
	            $certificate           = new \stdClass();
	            $certificate->course_id       = $course_id;
	            $certificate->url     = $certificateLink;
	            $certificate->course_title    = get_the_title( $course_id );
	            $certificate->course_url    = get_the_permalink( $course_id );
	            $certificate->course_image    = get_the_post_thumbnail_url( $course_id );
	            $certificate->filename = $filename;
	            $certificate->issued_on     = date_i18n( get_option( 'date_format' ), $date );
	            $certificate->time     = $date;
	            $certificate->type     = 'course';
	            $certificates[]        = (array) $certificate;
	        }
	    }
	 
	    /**
	     * Quiz Certificate
	     **/
	    $quizzes  = get_user_meta( $user_id, '_sfwd-quizzes', true );
	    $quiz_ids = empty( $quizzes ) ? array() : wp_list_pluck( $quizzes, 'quiz' );
	    if ( ! empty( $quiz_ids ) ) {
	        $quiz_total_query_args = array(
	            'post_type' => 'sfwd-quiz',
	            'fields'    => 'ids',
	            'orderby'   => 'title', //$atts['quiz_orderby'],
	            'order'     => 'ASC', //$atts['quiz_order'],
	            'nopaging'  => true,
	            'post__in'  => $quiz_ids
	        );
	        $quiz_query            = new \WP_Query( $quiz_total_query_args );
	        $quizzes_tmp           = array();
	        foreach ( $quiz_query->posts as $post_idx => $quiz_id ) {
	            foreach ( $quizzes as $quiz_idx => $quiz_attempt ) {
	                if ( $quiz_attempt['quiz'] == $quiz_id ) {
	                    $quiz_key                 = $quiz_attempt['time'] . '-' . $quiz_attempt['quiz'];
	                    $quizzes_tmp[ $quiz_key ] = $quiz_attempt;
	                    unset( $quizzes[ $quiz_idx ] );
	                }
	            }
	        }
	        $quizzes = $quizzes_tmp;
	        krsort( $quizzes );
	        if ( ! empty( $quizzes ) ) {
	            foreach ( $quizzes as $quizdata ) {
	                if ( ! in_array( $quizdata['quiz'], wp_list_pluck( $certificates, 'ID' ) ) ) {
	                    $quiz_settings         = learndash_get_setting( $quizdata['quiz'] );
	                    $certificate_post_id   = intval( $quiz_settings['certificate'] );
	                    $certificate_post_data = get_post( $certificate_post_id );
	                    $certificate_data      = learndash_certificate_details( $quizdata['quiz'], $user_id );
	                    if ( ! empty( $certificate_data['certificateLink'] ) && $certificate_data['certificate_threshold'] <= $quizdata['percentage'] / 100 ) {
	                        $filename              = sanitize_file_name( get_the_title( $quizdata['quiz'] ) ) . "-" . sanitize_file_name( get_the_title( $certificate_post_id ) ) . ".pdf";
	                        $certificate           = new \stdClass();
	                        $certificate->course_id       = $quizdata['quiz'];
	                        $certificate->url     = $certificate_data['certificateLink'];
	                        $certificate->course_title    = get_the_title( $quizdata['quiz'] );
	                        $certificate->course_image    = get_the_post_thumbnail_url( $quizdata['quiz'] );
	                        $certificate->course_url    = get_the_permalink( $quizdata['quiz'] );
	                        $certificate->filename = $filename;
	                        $certificate->issued_on     = date_i18n( get_option( 'date_format' ),  $quizdata['time'] );
	                        $certificate->time     = $quizdata['time'];
	                        $certificate->type     = 'quiz';
	                        $certificates[]        = (array) $certificate;
	                    }
	                }
	 
	            }
	        }
	    }
	 
	    usort( $certificates, function ( $a, $b ) {
	        return strcmp( $b['time'], $a['time'] );

	    } );
	 
	    return $certificates;
	}


}