<?php

class Youzify_LearnPress_Certificates_Tab {

	/**
	 * Tab Content
	 */
	function tab( $options = array() ) {

        $certificates = array();

		// Get Displayed User ID
        $user_id = isset( $options['user_id'] ) ? $options['user_id'] : bp_displayed_user_id();

		// Get the current student if none supplied.
		$student = llms_get_student( $user_id );

		$args = array(
			'page'     => 1,
			'per_page' => -1,
		);

		if ( isset( $options['related_posts'] ) ) {
			$args['related_posts'] = $options['related_posts'];
		}

		// Get certificates.
		$query = $student->get_certificates( $args );

        $user_certificates = isset( $args['query'] ) ? $args['query'] : $query->get_awards();

		foreach( $user_certificates as $certificate ) {

			// print_r( $certificate );
			$course_id = $certificate->get( 'related' );
		
			// Get Certificate Data.
			$certificates[ $course_id ]['course_id'] = $course_id;
				$certificates[ $course_id ]['url'] = get_the_permalink( $certificate->get( 'id' ) );
			$certificates[ $course_id ]['course_image'] = get_the_post_thumbnail_url( $course_id );
			$certificates[ $course_id ]['course_url'] = get_the_permalink( $course_id );
			$certificates[ $course_id ]['course_title'] = get_the_title( $course_id );
			$certificates[ $course_id ]['issued_on'] = $certificate->get_earned_date();

		}

		// Call Tab
		youzify_get_user_certificates( $certificates );


	}


}