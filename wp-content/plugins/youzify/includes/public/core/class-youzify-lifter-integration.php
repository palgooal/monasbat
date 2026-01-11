<?php

class Youzify_LifterLMS_Integration {

    public function __construct() {

    	// Show Course Tab
    	add_action( 'youzify_add_profile_courses_tab', '__return_true' );
    	add_action( 'youzify_add_profile_certificates_subtab', '__return_true' );

    	// Add Course Tab
		add_action( 'youzify_profile_courses_tab_content', array( $this, 'add_course_tab_content' ), 10 );
		add_action( 'youzify_profile_certificates_tab_content', array( $this, 'add_certificates_tab_content' ), 10 );

		// Register Activity Actions.
		add_action( 'bp_register_activity_actions', array( $this, 'activity_actions' ), 10 );

		// Add new activity posts on user actions
		add_action( 'transition_post_status', array( $this, 'add_new_course_to_activity_page' ) , 10, 3 );
		// add_action( 'llms_generator_new_course', array( $this, 'add_new_course_to_activity_page' ) , 10 );
		add_action( 'llms_user_earned_certificate', array( $this, 'add_new_certificate_to_activity_page' ) , 10, 3 );
		add_action( 'llms_user_enrolled_in_course', array( $this, 'add_new_enrolled_course_to_activity_page' ) , 10, 2 );

		// Get Activity Post Content
		add_action( 'youzify_show_new_lifterlms_certificate', array( $this, 'get_activity_content' ) , 10 );
		add_action( 'youzify_show_new_lifterlms_course', array( $this, 'get_activity_content' ) , 10 );
		add_action( 'youzify_show_new_lifterlms_enrolled_course', array( $this, 'get_activity_content' ) , 10 );


		add_filter( 'youzify_activity_post_types', array( $this, 'add_activity_post_types' ) );
		add_filter( 'youzify_wall_show_everything_filter_actions', array( $this, 'add_activity_post_types_visibility' ) );
		add_filter( 'bp_get_activity_show_filters_options', array( $this, 'add_activity_post_types' ) );
        add_filter( 'youzify_wall_post_types_visibility', array( $this, 'enable_course_activity_posts' ) );
	
	}

	function enable_certificate_subtab() {
		return defined( 'TUTOR_CERT_VERSION' ) ? true : false;
	}

	function add_course_tab_content() {

	    require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-lifter-courses.php';

		$courses = new Youzify_Lifter_Courses_Tab();

		$courses->tab();

	}

	function add_certificates_tab_content() {

	    require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-lifter-certificates.php';

		$certificates = new Youzify_Lifter_Certificates_Tab();

		$certificates->tab();


	}
	function get_activity_content() {
        
		// Get Activity Type.
		$activity_type = bp_get_activity_type();

		switch( $activity_type ) {
			
			case 'new_lifterlms_course':
			case 'new_lifterlms_enrolled_course':

				// Include Courses.
	            require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-lifter-courses.php';

	            // Get Course
	            $courses = new Youzify_Lifter_Courses_Tab();

	            // Get User ID.
	            $user_id = bp_get_activity_user_id();

	            // Get Args
	            $args = array(
					'post_type'		 => 'course',
					'order' 		 => 'DESC',
					'disable_pagination' => true,
					'post_status'	 => 'publish',
					'posts_per_page' => 1,
					'fields' 		 => "id=>parent",
					'post__in' 		 => array( bp_get_activity_item_id() )
				);

				// if ( $activity_type == 'new_lifterlms_enrolled_course' ) {
				// 	$args['post_status'] = 'completed';
				// 	$args['post_type'] = 'tutor_enrolled';
				// 	$args['author'] = $user_id;
				// }
				
		        // Get Student.
				$student = llms_get_student( $user_id );

				// Get Course.
				$courses->courses_core( $args, $user_id, $student, $activity_type );
				break;

			case 'new_lifterlms_certificate':

		        youzify_styling()->custom_styling( 'certificates' );

				$args = array(
					'user_id' => bp_get_activity_user_id(),
					'related_posts' => array( bp_get_activity_item_id() )
				);

			    require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-lifter-certificates.php';

				$certificates = new Youzify_Lifter_Certificates_Tab();

				// Show Certificate
				$certificates->tab( $args );

				break;

		}
	
    }
	
	/**
	 * Add Activity Actions.
	 */
	function activity_actions() {

		// Init Vars
		$bp = buddypress();

		bp_activity_set_action(
			$bp->activity->id,
			'new_lifterlms_course',
			__( 'added a new course', 'youzify' ),
			'youzify_activity_action_wall_posts',
			__( 'Courses', 'youzify' ),
			array( 'activity', 'member' )
		);

		bp_activity_set_action(
			$bp->activity->id,
			'new_lifterlms_enrolled_course',
			__( 'enrolled in a new course', 'youzify' ),
			'youzify_activity_action_wall_posts',
			__( 'Enrolled Courses', 'youzify' ),
			array( 'activity', 'member' )
		);

		bp_activity_set_action(
			$bp->activity->id,
			'new_lifterlms_certificate',
			__( 'earned a new certificate', 'youzify' ),
			'youzify_activity_action_wall_posts',
			__( 'Earned Certificates', 'youzify' ),
			array( 'activity', 'member' )
		);

	}

	/**
	 * Get Activity Posts Types
	 */
	function add_activity_post_types_visibility( $post_types ) {

	   $post_types[] = 'new_lifterlms_course';
	   $post_types[] = 'new_lifterlms_certificate';
	   $post_types[] = 'new_lifterlms_enrolled_course';
	    
	    return $post_types;
	}

	/**
	 * Enable Activity Poll Posts Visibility.
	 */
	function enable_course_activity_posts( $post_types ) {
		$post_types['new_lifterlms_course'] = youzify_option( 'youzify_enable_wall_new_lifterlms_course' , 'on' );
		$post_types['new_lifterlms_certificate'] = youzify_option( 'youzify_enable_wall_new_lifterlms_certificate' , 'on' );
		$post_types['new_lifterlms_enrolled_course'] = youzify_option( 'youzify_enable_wall_new_lifterlms_enrolled_course' , 'on' );
		return $post_types;
	}

	/**
	 * Get Activity Posts Types
	 */
	function add_activity_post_types( $post_types ) {

	   $post_types['new_lifterlms_course'] = __( 'New Course', 'youzify' );
	   $post_types['new_lifterlms_enrolled_course'] = __( 'New Enrolled Course', 'youzify' );
	   $post_types['new_lifterlms_certificate'] = __( 'New Certificate', 'youzify' );
	    
	    return $post_types;
	}

	/**
	 * Add prodcut to activity stream.
	 */
	function add_new_course_to_activity_page(  $new_status, $old_status, $post  ) {
	    
	    if ( ! bp_is_active( 'activity' ) || $post->post_type !== 'course' || 'publish' !== $new_status || 'publish' === $old_status ) return;

	    $user_link = bp_core_get_userlink( $post->post_author );

	    // Get Activity Action.
	    $action = apply_filters( 'youzify_new_lifterlms_product_action', sprintf( __( '%s added a new course', 'youzify' ), $user_link ), $post->ID );

	    // record the activity
	    bp_activity_add( array(
	        'user_id'   => $post->post_author,
	        'action'    => $action,
	        'item_id'   => $post->ID,
	        'component' => 'activity',
	        'type'      => 'new_lifterlms_course',
	    ) );

	    // $user_link = bp_core_get_userlink( $course->get_author_id() );

	    // // Get Activity Action.
	    // $action = apply_filters( '', sprintf( __( '%s added a new course', 'youzify' ), $user_link ), $course->get( 'id' ) );

	    // // record the activity
	    // bp_activity_add( array(
	    //     'user_id'   => $course->get_author_id(),
	    //     'action'    => $action,
	    //     'item_id'   => $course->get( 'id' ),
	    //     'component' => 'activity',
	    //     'type'      => '',
	    // ) );

	}

	/**
	 * Add prodcut to activity stream.
	 */
	function add_new_enrolled_course_to_activity_page( $user_id, $course_id ) {

	    if ( ! bp_is_active( 'activity' ) ) return;

	    $user_link = bp_core_get_userlink( $user_id );

	    // Get Activity Action.
	    $action = apply_filters( 'youzify_new_wc_product_action', sprintf( __( '%s enrolled in a new course', 'youzify' ), $user_link ), $course_id );

	    // record the activity
	    bp_activity_add( array(
	        'user_id'   => $user_id,
	        'action'    => $action,
	        'item_id'   => $course_id,
	        'component' => 'activity',
	        'type'      => 'new_lifterlms_enrolled_course',
	    ) );

	}

	/**
	 * Add prodcut to activity stream.
	 */
	function add_new_certificate_to_activity_page( $user_id, $certificate_id, $course_id ) {

	    if ( ! bp_is_active( 'activity' ) ) return;

	    // if ( ! defined( 'TUTOR_CERT_VERSION' ) ) return;

	    // Check if Course has certificate
		// if ( ! get_post_meta( $course_id, 'tutor_course_certificate_template', true ) ) {
			// return;
		// }

        // Get User Link
	    $user_link = bp_core_get_userlink( $user_id );

	    // Get Activity Action.
	    $action = apply_filters( 'youzify_new_lifterlms_course_action', sprintf( __( '%s earned a new certificate', 'youzify' ), $user_link ), $course_id );

	    // record the activity
	    bp_activity_add(
	    	array(
		        'user_id'   => $user_id,
		        'action'    => $action,
		        'item_id'   => $course_id,
		        'component' => 'activity',
		        'type'      => 'new_lifterlms_certificate',
	    	)
	    );

	}

}

new Youzify_LifterLMS_Integration();