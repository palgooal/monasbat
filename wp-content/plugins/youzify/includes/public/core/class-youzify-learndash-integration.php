<?php

class Youzify_Learndash_Integration {


    public function __construct() {
    	
    	// Show Course Tab
    	add_action( 'youzify_add_profile_courses_tab', '__return_true' );
    	add_action( 'youzify_add_profile_certificates_subtab', '__return_true' );

    	// Add Course Tab
		add_action( 'youzify_profile_courses_tab_content', array( $this, 'add_course_tab_content' ), 10 );
		add_action( 'youzify_profile_certificates_tab_content', array( $this, 'add_certificates_tab_content' ), 10 );

		// Register Activity Actions.
		add_action( 'bp_register_activity_actions', array( $this, 'activity_actions' ), 10 );
		add_action( 'learndash_course_completed', array( $this, 'add_new_certificate_to_activity_page' ) , 10 );
		add_action( 'transition_post_status', array( $this, 'add_new_course_to_activity_page' ) , 10, 3 );
		add_action( 'learndash_update_user_activity', array( $this, 'add_new_learndash_enrolled_course_to_activity_page' ) , 10, 3 );
		add_action( 'youzify_show_new_learndash_course', array( $this, 'show_course_box' ) );
		add_action( 'youzify_show_new_learndash_certificate', array( $this, 'earned_certificate_activity' ) );
		add_filter( 'youzify_activity_post_types', array( $this, 'add_activity_post_types' ) );
		add_filter( 'youzify_wall_show_everything_filter_actions', array( $this, 'add_activity_post_types_visibility' ) );
		add_filter( 'bp_get_activity_show_filters_options', array( $this, 'add_activity_post_types' ) );
        add_filter( 'youzify_wall_post_types_visibility', array( $this, 'enable_course_activity_posts' ) );
	
	}

	function add_course_tab_content() {

	    require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-learndash-courses.php';

		$courses = new Youzify_LearnDash_Courses_Tab();

		$courses->tab();

	}

	function add_certificates_tab_content() {

	    require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-learndash-certificates.php';

		$certificates = new Youzify_LearnDash_Certificates_Tab();

		$certificates->tab();


	}

	function show_course_box() {
        
		// Get Activity Type.
		$activity_type = bp_get_activity_type();

		if ( $activity_type == 'new_learndash_course' || $activity_type == 'new_learndash_enrolled_course' ) {

            youzify_styling()->custom_styling( 'courses' );

            require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-courses.php';
            require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-learndash-courses.php';

            $courses = new Youzify_LearnDash_Courses_Tab();

            $args = array(
				'post_type'		 => array( 'sfwd-courses' ),
				'order' 		 => 'DESC',
				'disable_pagination' => true,
				'post_status'	 => 'publish',
				'posts_per_page' => 1,
				'post__in' 		 => array( bp_get_activity_item_id() )
			);

			$courses->courses_core( $args, bp_get_activity_user_id(), $activity_type );
        }
	
    }

	function earned_certificate_activity() {
    
        youzify_styling()->custom_styling( 'certificates' );

	    require_once YOUZIFY_CORE . 'tabs/class-youzify-tab-learndash-certificates.php';

		$certificates = new Youzify_LearnDash_Certificates_Tab();

		$certificates->tab( array( 'user_id' => bp_get_activity_user_id(), 'course_id' => bp_get_activity_item_id() )  );

    }

	/**
	 * Add Woocommerce Activity Actions.
	 */
	function activity_actions() {

		// Init Vars
		$bp = buddypress();

		bp_activity_set_action(
			$bp->activity->id,
			'new_learndash_course',
			__( 'added a new Course', 'youzify' ),
			'youzify_activity_action_wall_posts',
			__( 'Courses', 'youzify' ),
			array( 'activity', 'member' )
		);

		bp_activity_set_action(
			$bp->activity->id,
			'new_learndash_enrolled_course',
			__( 'Enrolled a new course', 'youzify' ),
			'youzify_activity_action_wall_posts',
			__( 'Course', 'youzify' ),
			array( 'activity', 'member')
		);

		bp_activity_set_action(
			$bp->activity->id,
			'new_learndash_certificate',
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

	   $post_types[] = 'new_learndash_course';
	   $post_types[] = 'new_learndash_enrolled_course';
	   $post_types[] = 'new_learndash_certificate';
	    
	    return $post_types;
	}

	/**
	 * Enable Activity Poll Posts Visibility.
	 */
	function enable_course_activity_posts( $post_types ) {
		$post_types['new_learndash_course'] = youzify_option( 'youzify_enable_wall_new_learndash_course' , 'on' );
		$post_types['new_learndash_enrolled_course'] = youzify_option( 'youzify_enable_wall_new_learndash_enrolled_course' , 'on' );
		$post_types['new_learndash_certificate'] = youzify_option( 'youzify_enable_wall_new_learndash_certificate' , 'on' );
		return $post_types;
	}

	/**
	 * Get Activity Posts Types
	 */
	function add_activity_post_types( $post_types ) {
		$post_types['new_learndash_course'] = __( 'New Course', 'youzify' );
		$post_types['new_learndash_enrolled_course'] = __( 'New Enrolled Course', 'youzify' );
		$post_types['new_learndash_certificate'] = __( 'New Earned Certificate', 'youzify' );

		return $post_types;
	}


	/**
	 * Add prodcut to activity stream.
	 */
	function add_new_course_to_activity_page( $new_status, $old_status, $post ) {

	    if ( ! bp_is_active( 'activity' ) || $post->post_type !== 'sfwd-courses' || 'publish' !== $new_status || 'publish' === $old_status ) return;

	    $user_link = bp_core_get_userlink( $post->post_author );
	    
	    // Get Activity Action.
	    $action = apply_filters( 'youzify_new_learndash_course_action', sprintf( __( '%s added a new course', 'youzify' ), $user_link ), $post->ID );

	    // record the activity	``	
	    bp_activity_add( array(
	        'user_id'   => $post->post_author,
	        'action'    => $action,
	        'item_id'   => $post->ID,
	        'component' => 'activity',
	        'type'      => 'new_learndash_course',
	    ) );


	}

	/**
	 * Add prodcut to activity stream.
	 */
	function add_new_certificate_to_activity_page( $args ) {

	    if ( ! bp_is_active( 'activity' ) ) return;

	    // Check if Course has certificate
        $certificateLink = learndash_get_course_certificate_link( $args['course']->ID, $args['user']->ID );

        if ( empty( $certificateLink ) ) {
        	return;
        }

        // Get User Link
	    $user_link = bp_core_get_userlink( $args['user']->ID );

	    // Get Activity Action.
	    $action = apply_filters( 'youzify_new_learndash_course_action', sprintf( __( '%s earned a new certificate', 'youzify' ), $user_link ), $args['course']->ID );

	    // record the activity	``	
	    bp_activity_add( array(
	        'user_id'   =>  $args['user']->ID,
	        'action'    => $action,
	        'item_id'   => $args['course']->ID,
	        'component' => 'activity',
	        'type'      => 'new_learndash_certificate',
	    ) );


	}

	/**
	 * Add prodcut to activity stream.
	 */
	function add_new_learndash_enrolled_course_to_activity_page( $args ) {

	    if (  $args['activity_type'] !== 'access') return;

	    $user_link = bp_core_get_userlink( $args['user_id'] );
	    
	    // Get Activity Action.
	    $action = apply_filters( 'youzify_new_wc_product_action', sprintf( __( '%s enrolled in a new course', 'youzify' ), $user_link ), $args['course_id'] );

	    // record the activity	``	
	    bp_activity_add( array(
	        'user_id'   => $args['user_id'],
	        'action'    => $action,
	        'item_id'   => $args['course_id'],
	        'component' => 'activity',
	        'type'      => 'new_learndash_enrolled_course',
	    ) );

	}

}

new Youzify_Learndash_Integration();