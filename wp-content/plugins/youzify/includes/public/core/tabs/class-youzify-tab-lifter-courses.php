<?php

class Youzify_Lifter_Courses_Tab {

	/**
	 * Tab Content
	 */
	function tab() {

		// Get User ID.
        $user_id = bp_displayed_user_id();

        // Get Student.
		$student = llms_get_student( $user_id );

		// Get Courses
        $courses = $student->get_courses(
			array(
				'limit'  => 1000,
				'status' => 'enrolled',
			)
		);

		$args = array(
			'post_type'		 => 'course',
			'order' 		 => 'DESC',
			'paged' 		 => get_query_var( 'page' ) ? get_query_var( 'page' ) : 1,
			'post_status'	 => 'publish',
			'posts_per_page' => youzify_option( 'youzify_profile_courses_per_page', 6 ),
			'post__in' 		 => $courses['found'] > 0 ? $courses['results'] : array( 0 ) ,
			'fields' 		 => "id=>parent"
		);

		echo '<div class="youzify-tab youzify-courses"><div id="youzify-main-courses" class="youzify-tab youzify-tab-courses" data-plugin_type="lifterlms">';

		$this->courses_core( $args, $user_id, $student );

		youzify_loading();

		echo '</div></div>';

		// Pagination Script.
 		youzify_profile_posts_comments_pagination();

	}

	/**
	 * Post Core .
	 */
	function courses_core( $args, $user_id, $student = '', $activity_type = '' ) {


		if ( empty( $student ) ) {
	        // Get Student.
			$student = llms_get_student( $user_id );
		}

		// Init Vars.
		$posts_exist = false;

		$blogs_ids = is_multisite() ? get_sites() : array( (object) array( 'blog_id' => 1 ) );

		$blogs_ids = apply_filters( 'youzify_profile_posts_tab_blog_ids', $blogs_ids );

		// Posts Pagination
		$posts_page = ! empty( $args['page'] ) ? $args['page'] : 1 ;

		// Get Base
		$base = isset( $args['base'] ) ? $args['base'] : get_pagenum_link( 1 );

		echo '<div class="youzify-courses-page" data-post-page="' . $posts_page . '">';
		
		// Show / Hide Post Elements
		$display_enrolment_status = youzify_option( 'youzify_display_course_enrolment_status', 'on' );
		$display_date 		= youzify_option( 'youzify_display_course_date', 'on' );
		$display_author 	= youzify_option( 'youzify_display_course_author', 'on' );
		$display_excerpt	= youzify_option( 'youzify_display_course_excerpt', 'on' );
		$display_completion_bar = youzify_option( 'youzify_display_course_completion_bar', 'on' );
		$display_completion_percent 	= youzify_option( 'youzify_display_course_completion_percent', 'on' );
		$display_completion_steps = youzify_option( 'youzify_display_course_completed_steps', 'on' );
		
		foreach ( $blogs_ids as $b ) {

			switch_to_blog( $b->blog_id );

			// init WP Query
			$posts_query = new WP_Query( $args );
			
			if ( $posts_query->have_posts() ) : $posts_exist = true;

			while ( $posts_query->have_posts() ) : $posts_query->the_post();

				// Get Post Data
				$post_id = $posts_query->post->ID;

				$course_progress =  $student->get_progress( $post_id );
				$course        = new LLMS_Course( $post_id);
				$total_lessons_count = $course->get_lessons_count();

			?>

			<div class="youzify-tab-course youzify-course-content">

				<?php

					if ( $course_progress == 0 ) {
						$progress_status = __( 'Not Started', 'youzify' );
						$progress_id = 'start_course';
					} elseif ( $course_progress > 0 && $course_progress < 100 ) {
						$progress_status = __( 'In Progress', 'youzify' );
						$progress_id = 'in_progress';
					} elseif ( $course_progress  == 100 ) {
						$progress_status = __( 'Complete', 'youzify' );
						$progress_id = 'complete';
					}
				?>

				<?php $this->get_post_thumbnail( array( 'attachment_id' => get_post_thumbnail_id( $post_id ), 'widget'=>'course','size' => 'large', 'element' => 'profile-courses-tab' ), $progress_status, $progress_id ); ?>

				<div class="youzify-course-container">

					<div class="youzify-course-inner-content">
						<?php do_action( 'youzify_before_courses_tab_container' ); ?>
						<div class="youzify-course-head">
							<?php if ( $activity_type != 'new_lifterlms_course' && $display_enrolment_status == 'on' ) : ?>
							<span class='youzify-course-status' data-status="<?php echo $progress_id; ?>"><?php echo $progress_status; ?></span>
							<?php endif; ?>
							<h2 class="youzify-course-title">
								<a href="<?php the_permalink( $post_id ); ?>"><?php echo get_the_title( $post_id ); ?></a>
							</h2>

							<div class="youzify-course-meta">

								<ul>
									<?php if ( $activity_type != 'new_lifterlms_course' && 'on' == $display_author ) : ?>
									<li>
										<?php $author_id = get_post_field ('post_author', $post_id ); ?>
										<div class="youzify-course-author-img"><?php echo bp_core_fetch_avatar( array( 'item_id' => $author_id, 'type' => 'thumb', 'width' => 20, 'height' => 20, 'object' => 'user' ) ); ?></div>
										<?php echo bp_core_get_userlink( $author_id ); ?>
									</li>
									<?php endif; ?>
									<?php if ( 'on' == $display_date ) : ?>
										<li>
											<i class="far fa-calendar-alt"></i>
											<?php echo get_the_date( '', $post_id ); ?>
										</li>
									<?php endif; ?>

								</ul>

							</div>

						</div>
						<?php if ( 'on' == $display_excerpt ) : ?>
						<div class="youzify-course-text">
							<?php $post_excerpt = get_the_excerpt( $post_id ); ?>
							<?php $post_excerpt = ! empty( $post_excerpt ) ? $post_excerpt : do_shortcode( youzify_get_excerpt( get_the_content(), 25 ) ); ?>
							<?php $post_excerpt = substr( $post_excerpt, 0, 50 ); ?>
							<p><?php echo apply_filters( 'youzify_profile_posts_tab_post_excerpt', $post_excerpt, $post_id ) .'...'; ?></p>
						</div>
						<?php endif; ?>
							<?php if (  $activity_type != 'new_lifterlms_course' ) : ?>
							<div class="youzify-course-completion-data">

								<?php if ( $display_completion_bar == 'on' ) : ?>
									<div class="youzify-completionbar clearfix" data-percent="<?php echo $course_progress; ?>" loaded="true">
										<div class="youzify-completion-bar" style="background-color: rgb(129, 215, 66); width: <?php echo $course_progress ?>%;">
										</div>
									</div>
								<?php endif; ?>

								<div class="youzify-course-completion-meta">
									
									<?php if ( $display_completion_percent == 'on' ) : ?>
									<div class="youzify-course-bar-percent"><?php echo sprintf( __( '<span class="youzify-course-meta-label">Completed:</span> <span class="youzify-course-meta-value">%d%%</span>', 'youzify' ), $course_progress ) ?></div>
									<?php endif; ?>

									<?php if ( $display_completion_steps == 'on' ) : ?>
									<span class="youzify-course-progress-steps"><?php echo sprintf( __( '<span class="youzify-course-meta-label">Lessons:</span> <span class="youzify-course-meta-value">%s</span>', 'youzify' ), $this->calculateCompletedCourses( $course_progress, $total_lessons_count ) . '/' . $total_lessons_count ); ?>
									</span>
									<?php endif; ?>
								
								</div>

							</div>
						<?php endif; ?>

					</div>

				</div>

			</div>

			<?php endwhile;?>
			
			<?php wp_reset_postdata(); ?>
			<?php if ( ! isset( $args['disable_pagination' ]) || $args['disable_pagination'] == false ) $this->pagination( $args, $posts_query->max_num_pages, $base ); ?>
			<?php endif; ?>
		

		<?php

			restore_current_blog();
		}

		if ( ! $posts_exist ) {
			echo '<div class="youzify-info-msg youzify-failure-msg"><div class="youzify-msg-icon"><i class="fas fa-exclamation-triangle"></i></div>
			<p>'. __( 'Sorry, no courses found!', 'youzify' ) . '</p></div>';
		}

		echo '</div>';
		
	}

	/**
	 * Pagination
	 */
	function pagination( $args = null, $numpages = '', $base = null ) {

		// Get current Page Number
		$paged = ! empty( $args['paged'] ) ? $args['paged'] : 1 ;

		// Get Total Pages Number
		if ( $numpages == '' ) {
			global $wp_query;
			$numpages = $wp_query->max_num_pages;
			if ( ! $numpages ) {
				$numpages = 1;
			}
		}

		// Get Next and Previous Pages Number
		if ( ! empty( $paged ) ) {
			$next_page = $paged + 1;
			$prev_page = $paged - 1;
		}

		// Pagination Settings
		$pagination_args = array(
			'base'            		=> $base . '%_%',
			'format'          		=> 'page/%#%',
			'total'           		=> $numpages,
			'current'         		=> $paged,
			'show_all'        		=> False,
			'end_size'        		=> 1,
			'mid_size'        		=> 2,
			'prev_next'       		=> True,
			'prev_text'       		=> '<div class="youzify-page-symbole">&laquo;</div><span class="youzify-next-nbr">'. $prev_page .'</span>',
			'next_text'       		=> '<div class="youzify-page-symbole">&raquo;</div><span class="youzify-next-nbr">'. $next_page .'</span>',
			'type'            		=> 'plain',
			'add_args'        		=> false,
			'add_fragment'    		=> '',
			'before_page_number' 	=> '<span class="youzify-page-nbr">',
			'after_page_number' 	=> '</span>',
		);

		// Call Pagination Function
		$paginate_links = paginate_links( $pagination_args );

		// Print Pagination
		if ( $paginate_links ) {
			echo sprintf( '<nav class="youzify-pagination" data-base="%1s">' , $base );
			echo '<span class="youzify-pagination-pages">';
			printf( __( 'Page %1$d of %2$d', 'youzify' ), $paged, $numpages );
			echo "</span><div class='courses-nav-links youzify-nav-links'>$paginate_links</div></nav>";
		}

	}

	/**
	 * Get Post Thumbnail
	 */
	function get_post_thumbnail( $args = false, $status = '', $progress_id = '' ) {

	    $widget = isset( $args['widget'] ) ? $args['widget'] : 'post';
	    $img_size = isset( $args['size'] ) ? $args['size'] : apply_filters( 'youzify_default_blog_post_image_size','medium' );

        if ( $args['attachment_id'] ) {
            echo "<div class='youzify-course-image youzify-$widget-thumbnail'><img loading='lazy' " . youzify_get_image_attributes( $args['attachment_id'], $args['size'], $args['element'] ) . " alt=''></div>";
        } else {
            echo '<div class="youzify-course-image  youzify-no-thumbnail"><div class="thumbnail-icon"><i class="fas fa-image"></i></div></div>';
        }

	}

	function calculateCompletedCourses( $completionPercentage, $totalLessonsPerCourse ) {
	    $completedCourses = floor(($completionPercentage / 100) * $totalLessonsPerCourse);
	    return $completedCourses;
	}

}