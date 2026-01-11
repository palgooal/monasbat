<?php

class Youzify_Tutor_Certificates_Tab {

	/**
	 * Tab Content
	 */
	function tab( $args = array() ) {

		if ( ! function_exists( 'tutor_utils' )  || ! class_exists( 'TUTOR_CERT\Certificate' ) ) {
			return;
		}

        $certificates = array();
        
        // Get Cerificate Class
		$cert_obj = new TUTOR_CERT\Certificate( true );

		// Get Upload Dir
		$upload_dir = wp_upload_dir();

		// Get Upload Paths
		$certificate_dir_url  = $upload_dir['baseurl'] . '/' . $cert_obj->certificates_dir_name;
		$certificate_dir_path = $upload_dir['basedir'] . '/' . $cert_obj->certificates_dir_name;

		// Get Displayed User ID
        $user_id = isset( $args['user_id'] ) ? $args['user_id'] : bp_displayed_user_id();

        // Get User Completed Courses
		$posts_query = isset( $args['query'] ) ? $args['query'] : tutor_utils()->get_courses_by_user( $user_id );

		if ( $posts_query && $posts_query->have_posts() ) {

			while ( $posts_query->have_posts() )  {

				$posts_query->the_post();

				// Get Post Data
				$course_id = $posts_query->post->ID;

				if ( ! get_post_meta( $course_id, 'tutor_course_certificate_template', true ) ) {
					continue;
				}
				
				// Get Certificate Data
				$completed = tutor_utils()->is_completed_course( $course_id, $user_id );

				// Get Random String
				$rand_string = get_comment_meta( $completed->comment_ID, $cert_obj->certificate_stored_key, true );

				// Get Certificate Path.
				$cert_path = '/' . $rand_string . '-' .  $completed->completed_hash . '.jpg';

				// Get Certificate URL.
				if ( file_exists( $certificate_dir_path . $cert_path ) ) {
					$certificates[ $course_id ]['url'] = $certificate_dir_url . $cert_path;
				} else {
					$certificates[ $course_id ]['url'] = $cert_obj->get_certificate( $course_id );
				}

				// Get Certificate Data.
				$certificates[ $course_id ]['course_id'] = $course_id;
				$certificates[ $course_id ]['course_image'] = get_the_post_thumbnail_url( $course_id );
				$certificates[ $course_id ]['course_url'] = get_the_permalink( $course_id );
				$certificates[ $course_id ]['course_title'] = get_the_title( $course_id );
				$certificates[ $course_id ]['issued_on'] = tutor_get_formated_date( get_option( 'date_format' ), $completed->completion_date );

			}
		}

		// Call Tab
		youzify_get_user_certificates( $certificates );


	}


}