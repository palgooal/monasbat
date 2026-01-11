<?php

class Youzify_Tutor_Integration {

    public function __construct() {

    	// Show Course Tab
    	add_action( 'youzify_add_profile_courses_tab', '__return_true' );
    	add_action( 'youzify_add_profile_certificates_subtab', array( $this, 'enable_certificate_subtab' ) );

    	// Add Course Tab
		add_action( 'youzify_profile_courses_tab_content', array( $this, 'add_course_tab_content' ), 10 );
		add_action( 'youzify_profile_certificates_tab_content', array( $this, 'add_certificates_tab_content' ), 10 );
		add_action( 'tutor_course_complete_after', array( $this, 'add_new_certificate_to_activity_page' ) , 10, 2 );

		// Register Activity Actions.
		add_action( 'bp_register_activity_actions', array( $this, 'activity_actions' ), 10 );
		add_action( 'transition_post_status', array( $this, 'add_new_course_to_activity_page' ) , 10, 3 );
		add_action( 'transition_post_status', array( $this, 'add_new_enrolled_course_to_activity_page' ) , 10, 3 );
		add_action( 'youzify_show_new_tutor_certificate', array( $this, 'get_activity_content' ) , 10 );
		add_action( 'youzify_show_new_tutor_course', array( $this, 'get_activity_content' ) , 10 );
		add_action( 'youzify_show_new_tutor_enrolled_course', array( $this, 'get_activity_content' ) , 10 );
		add_filter( 'youzify_activity_post_types', array( $this, 'add_activity_post_types' ) );
		add_filter( 'youzify_wall_show_everything_filter_actions', array( $this, 'add_activity_post_types_visibility' ) );
		add_filter( 'bp_get_activity_show_filters_options', array( $this, 'add_activity_post_types' ) );
        add_filter( 'youzify_wall_post_types_visibility', array( $this, 'enable_course_activity_posts' ) );
	
	}

	function enable_certificate_subtab() {
		return defined( 'TUTOR_CERT_VERSION' ) ? true : false;
	}
	function add_course_tab_content() {

	    require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-tutor-courses.php';

		$courses = new Youzify_Tutor_Courses_Tab();

		$courses->tab();

	}

	function add_certificates_tab_content() {

	    require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-tutor-certificates.php';

		$certificates = new Youzify_Tutor_Certificates_Tab();

		$certificates->tab();


	}
	function get_activity_content() {
        
		// Get Activity Type.
		$activity_type = bp_get_activity_type();

		switch( $activity_type ) {
			
			case 'new_tutor_course':
			case 'new_tutor_enrolled_course':

				// Include Courses.
	            require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-tutor-courses.php';

	            // Get Course
	            $courses = new Youzify_Tutor_Courses_Tab();

	            // Get User ID.
	            $user_id = bp_get_activity_user_id();

	            // Get Args
	            $args = array(
					'post_type'		 => 'courses',
					'order' 		 => 'DESC',
					'disable_pagination' => true,
					'post_status'	 => 'publish',
					'posts_per_page' => 1,
					'fields' 		 => "id=>parent",
					'post__in' 		 => array( bp_get_activity_item_id() )
				);

				if ( $activity_type == 'new_tutor_enrolled_course' ) {
					$args['post_status'] = 'completed';
					$args['post_type'] = 'tutor_enrolled';
					$args['author'] = $user_id;
				}
				
				// Get Course.
				$courses->courses_core( $args, $user_id, $activity_type );
				break;

			case 'new_tutor_certificate':

		        youzify_styling()->custom_styling( 'certificates' );

			    require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-tutor-certificates.php';

				$certificates = new Youzify_Tutor_Certificates_Tab();

				// Get Cource Args
				$course_args      = array(
					'post_type'      => tutor()->course_post_type,
					'post_status'    => 'publish',
					'post__in'       => array( bp_get_activity_item_id() ),
					'posts_per_page' => '1',
				);

				// Show Certificate
				$certificates->tab(
					array(
						'user_id' => bp_get_activity_user_id(),
						'query' => new \WP_Query( $course_args )
					)
				);

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
			'new_tutor_course',
			__( 'added a new course', 'youzify' ),
			'youzify_activity_action_wall_posts',
			__( 'Courses', 'youzify' ),
			array( 'activity', 'member' )
		);

		bp_activity_set_action(
			$bp->activity->id,
			'new_tutor_enrolled_course',
			__( 'enrolled in a new course', 'youzify' ),
			'youzify_activity_action_wall_posts',
			__( 'Enrolled Courses', 'youzify' ),
			array( 'activity', 'member' )
		);

		bp_activity_set_action(
			$bp->activity->id,
			'new_tutor_certificate',
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

	   $post_types[] = 'new_tutor_course';
	   $post_types[] = 'new_tutor_certificate';
	   $post_types[] = 'new_tutor_enrolled_course';
	    
	    return $post_types;
	}

	/**
	 * Enable Activity Poll Posts Visibility.
	 */
	function enable_course_activity_posts( $post_types ) {
		$post_types['new_tutor_course'] = youzify_option( 'youzify_enable_wall_new_tutor_course' , 'on' );
		$post_types['new_tutor_certificate'] = youzify_option( 'youzify_enable_wall_new_tutor_certificate' , 'on' );
		$post_types['new_tutor_enrolled_course'] = youzify_option( 'youzify_enable_wall_new_tutor_enrolled_course' , 'on' );
		return $post_types;
	}

	/**
	 * Get Activity Posts Types
	 */
	function add_activity_post_types( $post_types ) {

	   $post_types['new_tutor_course'] = __( 'New Course', 'youzify' );
	   $post_types['new_tutor_enrolled_course'] = __( 'New Enrolled Course', 'youzify' );
	   $post_types['new_tutor_certificate'] = __( 'New Certificate', 'youzify' );
	    
	    return $post_types;
	}

	/**
	 * Add prodcut to activity stream.
	 */
	function add_new_course_to_activity_page( $new_status, $old_status, $post ) {

	    if ( ! bp_is_active( 'activity' ) || $post->post_type !== 'courses' || 'publish' !== $new_status || 'publish' === $old_status ) return;

	    $user_link = bp_core_get_userlink( $post->post_author );

	    // Get Activity Action.
	    $action = apply_filters( 'youzify_new_wc_product_action', sprintf( __( '%s added a new course', 'youzify' ), $user_link ), $post->ID );

	    // record the activity
	    bp_activity_add( array(
	        'user_id'   => $post->post_author,
	        'action'    => $action,
	        'item_id'   => $post->ID,
	        'component' => 'activity',
	        'type'      => 'new_tutor_course',
	    ) );

	}

	/**
	 * Add prodcut to activity stream.
	 */
	function add_new_enrolled_course_to_activity_page( $new_status, $old_status, $post ) {

	    if ( ! bp_is_active( 'activity' ) || $post->post_type !== 'tutor_enrolled' || 'completed' !== $new_status || 'completed' === $old_status ) return;

	    $user_link = bp_core_get_userlink( $post->post_author );
	    

	    // Get Activity Action.
	    $action = apply_filters( 'youzify_new_wc_product_action', sprintf( __( '%s enrolled in a new course', 'youzify' ), $user_link ), $post->ID );

	    // record the activity
	    bp_activity_add( array(
	        'user_id'   => $post->post_author,
	        'action'    => $action,
	        'item_id'   => $post->ID,
	        'component' => 'activity',
	        'type'      => 'new_tutor_enrolled_course',
	    ) );

	}

	/**
	 * Add prodcut to activity stream.
	 */
	function add_new_certificate_to_activity_page( $course_id, $user_id ) {

	    if ( ! bp_is_active( 'activity' ) ) return;

	    if ( ! defined( 'TUTOR_CERT_VERSION' ) ) return;

	    // Check if Course has certificate
		if ( ! get_post_meta( $course_id, 'tutor_course_certificate_template', true ) ) {
			return;
		}

        // Get User Link
	    $user_link = bp_core_get_userlink( $user_id);

	    // Get Activity Action.
	    $action = apply_filters( 'youzify_new_tutor_course_action', sprintf( __( '%s earned a new certificate', 'youzify' ), $user_link ), $course_id );

	    // record the activity
	    bp_activity_add(
	    	array(
		        'user_id'   => $user_id,
		        'action'    => $action,
		        'item_id'   => $course_id,
		        'component' => 'activity',
		        'type'      => 'new_tutor_certificate',
	    	)
	    );

	}

}

new Youzify_Tutor_Integration();