<?php
/**
 * Admin UI for testimonials.
 *
 * @package PalgoalsTestimonials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Palgoals_Testimonials_Admin {

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_' . Palgoals_Testimonials_CPT::POST_TYPE, array( $this, 'save_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'edit_form_after_title', array( $this, 'render_editor_notice' ) );
		add_filter( 'manage_' . Palgoals_Testimonials_CPT::POST_TYPE . '_posts_columns', array( $this, 'register_columns' ) );
		add_action( 'manage_' . Palgoals_Testimonials_CPT::POST_TYPE . '_posts_custom_column', array( $this, 'render_columns' ), 10, 2 );
		add_filter( 'manage_edit_' . Palgoals_Testimonials_CPT::POST_TYPE . '_sortable_columns', array( $this, 'register_sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'handle_admin_list_query' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_status_filter' ) );
		add_filter( 'post_row_actions', array( $this, 'add_toggle_row_action' ), 10, 2 );
		add_action( 'admin_action_palgoals_toggle_testimonial', array( $this, 'handle_toggle_status' ) );
	}

	/**
	 * Register details metabox.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'palgoals-testimonial-details',
			__( 'Client Details', 'palgoals-testimonials' ),
			array( $this, 'render_details_meta_box' ),
			Palgoals_Testimonials_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render editor helper text.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_editor_notice( $post ) {
		if ( Palgoals_Testimonials_CPT::POST_TYPE !== $post->post_type ) {
			return;
		}
		?>
		<div class="palgoals-admin-notice">
			<p>
				<strong><?php esc_html_e( 'Client Name', 'palgoals-testimonials' ); ?>:</strong>
				<?php esc_html_e( 'Use the title field above for the client name and the editor below for the testimonial text.', 'palgoals-testimonials' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the testimonial details meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_details_meta_box( $post ) {
		wp_nonce_field( 'palgoals_testimonial_save', 'palgoals_testimonial_nonce' );

		$position    = get_post_meta( $post->ID, Palgoals_Testimonials_CPT::META_POSITION, true );
		$company     = get_post_meta( $post->ID, Palgoals_Testimonials_CPT::META_COMPANY, true );
		$photo_id    = absint( get_post_meta( $post->ID, Palgoals_Testimonials_CPT::META_PHOTO_ID, true ) );
		$rating      = absint( get_post_meta( $post->ID, Palgoals_Testimonials_CPT::META_RATING, true ) );
		$website_url = get_post_meta( $post->ID, Palgoals_Testimonials_CPT::META_WEBSITE_URL, true );
		$status      = get_post_meta( $post->ID, Palgoals_Testimonials_CPT::META_STATUS, true );

		if ( $rating < 1 || $rating > 5 ) {
			$rating = 5;
		}

		if ( empty( $status ) ) {
			$status = Palgoals_Testimonials_CPT::STATUS_ACTIVE;
		}

		$image_markup = $photo_id
			? wp_get_attachment_image(
				$photo_id,
				array( 120, 120 ),
				false,
				array(
					'class'   => 'palgoals-admin-media__image',
					'loading' => 'lazy',
				)
			)
			: '<span class="palgoals-admin-media__placeholder">' . esc_html__( 'No photo selected', 'palgoals-testimonials' ) . '</span>';
		?>
		<div class="palgoals-admin-panel">
			<div class="palgoals-admin-grid">
				<div class="palgoals-admin-field">
					<label for="palgoals_client_position"><?php esc_html_e( 'Client Position / Job Title', 'palgoals-testimonials' ); ?></label>
					<input type="text" id="palgoals_client_position" name="palgoals_client_position" value="<?php echo esc_attr( $position ); ?>" class="widefat" />
				</div>

				<div class="palgoals-admin-field">
					<label for="palgoals_company_name"><?php esc_html_e( 'Company Name', 'palgoals-testimonials' ); ?></label>
					<input type="text" id="palgoals_company_name" name="palgoals_company_name" value="<?php echo esc_attr( $company ); ?>" class="widefat" />
				</div>

				<div class="palgoals-admin-field">
					<label for="palgoals_website_url"><?php esc_html_e( 'Website URL', 'palgoals-testimonials' ); ?></label>
					<input type="url" id="palgoals_website_url" name="palgoals_website_url" value="<?php echo esc_attr( $website_url ); ?>" class="widefat" placeholder="https://example.com" />
				</div>

				<div class="palgoals-admin-field">
					<label for="palgoals_status"><?php esc_html_e( 'Status', 'palgoals-testimonials' ); ?></label>
					<select id="palgoals_status" name="palgoals_status" class="widefat">
						<?php foreach ( Palgoals_Testimonials_CPT::get_status_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="palgoals-admin-split">
				<div class="palgoals-admin-card">
					<h4><?php esc_html_e( 'Client Photo', 'palgoals-testimonials' ); ?></h4>
					<div class="palgoals-admin-media" data-placeholder="<?php echo esc_attr__( 'No photo selected', 'palgoals-testimonials' ); ?>">
						<input type="hidden" name="palgoals_client_photo_id" value="<?php echo esc_attr( $photo_id ); ?>" />
						<div class="palgoals-admin-media__preview"><?php echo wp_kses_post( $image_markup ); ?></div>
						<div class="palgoals-admin-media__actions">
							<button type="button" class="button button-primary palgoals-upload-image"><?php esc_html_e( 'Upload Photo', 'palgoals-testimonials' ); ?></button>
							<button type="button" class="button palgoals-remove-image <?php echo $photo_id ? '' : 'hidden'; ?>"><?php esc_html_e( 'Remove Photo', 'palgoals-testimonials' ); ?></button>
						</div>
					</div>
				</div>

				<div class="palgoals-admin-card">
					<h4><?php esc_html_e( 'Rating', 'palgoals-testimonials' ); ?></h4>
					<div class="palgoals-rating-control" role="radiogroup" aria-label="<?php echo esc_attr__( 'Testimonial rating', 'palgoals-testimonials' ); ?>">
						<?php for ( $value = 5; $value >= 1; $value-- ) : ?>
							<input type="radio" id="palgoals_rating_<?php echo esc_attr( $value ); ?>" name="palgoals_rating" value="<?php echo esc_attr( $value ); ?>" <?php checked( $rating, $value ); ?> />
							<label for="palgoals_rating_<?php echo esc_attr( $value ); ?>" title="<?php echo esc_attr( sprintf( __( '%d star rating', 'palgoals-testimonials' ), $value ) ); ?>">
								<span class="screen-reader-text"><?php echo esc_html( sprintf( __( '%d star rating', 'palgoals-testimonials' ), $value ) ); ?></span>
								&#9733;
							</label>
						<?php endfor; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Persist meta box data.
	 *
	 * @param int $post_id Current post ID.
	 * @return void
	 */
	public function save_meta_boxes( $post_id ) {
		if ( ! isset( $_POST['palgoals_testimonial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['palgoals_testimonial_nonce'] ) ), 'palgoals_testimonial_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$position    = isset( $_POST['palgoals_client_position'] ) ? sanitize_text_field( wp_unslash( $_POST['palgoals_client_position'] ) ) : '';
		$company     = isset( $_POST['palgoals_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['palgoals_company_name'] ) ) : '';
		$photo_id    = isset( $_POST['palgoals_client_photo_id'] ) ? absint( $_POST['palgoals_client_photo_id'] ) : 0;
		$rating      = isset( $_POST['palgoals_rating'] ) ? Palgoals_Testimonials_CPT::sanitize_rating( wp_unslash( $_POST['palgoals_rating'] ) ) : 5;
		$website_url = isset( $_POST['palgoals_website_url'] ) ? esc_url_raw( wp_unslash( $_POST['palgoals_website_url'] ) ) : '';
		$status      = isset( $_POST['palgoals_status'] ) ? Palgoals_Testimonials_CPT::sanitize_status( wp_unslash( $_POST['palgoals_status'] ) ) : Palgoals_Testimonials_CPT::STATUS_ACTIVE;

		update_post_meta( $post_id, Palgoals_Testimonials_CPT::META_POSITION, $position );
		update_post_meta( $post_id, Palgoals_Testimonials_CPT::META_COMPANY, $company );
		update_post_meta( $post_id, Palgoals_Testimonials_CPT::META_PHOTO_ID, $photo_id );
		update_post_meta( $post_id, Palgoals_Testimonials_CPT::META_RATING, $rating );
		update_post_meta( $post_id, Palgoals_Testimonials_CPT::META_WEBSITE_URL, $website_url );
		update_post_meta( $post_id, Palgoals_Testimonials_CPT::META_STATUS, $status );

		Palgoals_Testimonials_CPT::invalidate_cache();
	}

	/**
	 * Load admin assets on testimonial screens.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = get_current_screen();

		if ( ! $screen || Palgoals_Testimonials_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'palgoals-testimonials-admin', PALGOALS_TESTIMONIALS_URL . 'assets/css/admin.css', array(), PALGOALS_TESTIMONIALS_VERSION );
		wp_enqueue_script( 'palgoals-testimonials-admin', PALGOALS_TESTIMONIALS_URL . 'assets/js/admin.js', array( 'jquery' ), PALGOALS_TESTIMONIALS_VERSION, true );
		wp_localize_script(
			'palgoals-testimonials-admin',
			'PalgoalsTestimonialsAdmin',
			array(
				'frameTitle'  => __( 'Select client photo', 'palgoals-testimonials' ),
				'buttonLabel' => __( 'Use this photo', 'palgoals-testimonials' ),
			)
		);
	}

	/**
	 * Customize columns on the testimonial list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function register_columns( $columns ) {
		$new_columns = array();

		if ( isset( $columns['cb'] ) ) {
			$new_columns['cb'] = $columns['cb'];
		}

		$new_columns['title']   = __( 'Client Name', 'palgoals-testimonials' );
		$new_columns['company'] = __( 'Company', 'palgoals-testimonials' );
		$new_columns['rating']  = __( 'Rating', 'palgoals-testimonials' );
		$new_columns['status']  = __( 'Status', 'palgoals-testimonials' );
		$new_columns['date']    = __( 'Date', 'palgoals-testimonials' );

		return $new_columns;
	}

	/**
	 * Render custom columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'company':
				$company = get_post_meta( $post_id, Palgoals_Testimonials_CPT::META_COMPANY, true );
				echo $company ? esc_html( $company ) : '&mdash;';
				break;

			case 'rating':
				echo wp_kses_post( Palgoals_Testimonials_Renderer::render_stars( absint( get_post_meta( $post_id, Palgoals_Testimonials_CPT::META_RATING, true ) ) ) );
				break;

			case 'status':
				$status  = get_post_meta( $post_id, Palgoals_Testimonials_CPT::META_STATUS, true );
				$status  = Palgoals_Testimonials_CPT::sanitize_status( $status );
				$classes = 'palgoals-status-badge palgoals-status-badge--' . $status;
				echo '<span class="' . esc_attr( $classes ) . '">' . esc_html( Palgoals_Testimonials_CPT::get_status_options()[ $status ] ) . '</span>';
				break;
		}
	}

	/**
	 * Register sortable admin columns.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function register_sortable_columns( $columns ) {
		$columns['company'] = 'company';
		$columns['rating']  = 'rating';
		$columns['status']  = 'status';

		return $columns;
	}

	/**
	 * Render a status filter dropdown.
	 *
	 * @return void
	 */
	public function render_status_filter() {
		global $typenow;

		if ( Palgoals_Testimonials_CPT::POST_TYPE !== $typenow ) {
			return;
		}

		$current = isset( $_GET['palgoals_status_filter'] ) ? sanitize_key( wp_unslash( $_GET['palgoals_status_filter'] ) ) : '';
		?>
		<select name="palgoals_status_filter">
			<option value=""><?php esc_html_e( 'All statuses', 'palgoals-testimonials' ); ?></option>
			<?php foreach ( Palgoals_Testimonials_CPT::get_status_options() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Add admin filters and sorting to the list query.
	 *
	 * @param WP_Query $query Current query.
	 * @return void
	 */
	public function handle_admin_list_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		if ( Palgoals_Testimonials_CPT::POST_TYPE !== $post_type ) {
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' );
		$status     = isset( $_GET['palgoals_status_filter'] ) ? sanitize_key( wp_unslash( $_GET['palgoals_status_filter'] ) ) : '';
		$orderby    = $query->get( 'orderby' );

		if ( $status && array_key_exists( $status, Palgoals_Testimonials_CPT::get_status_options() ) ) {
			$meta_query[] = array(
				'key'     => Palgoals_Testimonials_CPT::META_STATUS,
				'value'   => $status,
				'compare' => '=',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}

		if ( 'company' === $orderby ) {
			$query->set( 'meta_key', Palgoals_Testimonials_CPT::META_COMPANY );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( 'rating' === $orderby ) {
			$query->set( 'meta_key', Palgoals_Testimonials_CPT::META_RATING );
			$query->set( 'orderby', 'meta_value_num' );
		} elseif ( 'status' === $orderby ) {
			$query->set( 'meta_key', Palgoals_Testimonials_CPT::META_STATUS );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Add quick status toggle row actions.
	 *
	 * @param array   $actions Existing actions.
	 * @param WP_Post $post    Current post.
	 * @return array
	 */
	public function add_toggle_row_action( $actions, $post ) {
		if ( Palgoals_Testimonials_CPT::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$current_status = get_post_meta( $post->ID, Palgoals_Testimonials_CPT::META_STATUS, true );
		$current_status = Palgoals_Testimonials_CPT::sanitize_status( $current_status );
		$target_status  = Palgoals_Testimonials_CPT::STATUS_ACTIVE === $current_status ? Palgoals_Testimonials_CPT::STATUS_HIDDEN : Palgoals_Testimonials_CPT::STATUS_ACTIVE;
		$label          = Palgoals_Testimonials_CPT::STATUS_ACTIVE === $target_status ? __( 'Activate', 'palgoals-testimonials' ) : __( 'Hide', 'palgoals-testimonials' );
		$url            = add_query_arg(
			array(
				'action' => 'palgoals_toggle_testimonial',
				'post'   => $post->ID,
				'status' => $target_status,
			),
			admin_url( 'admin.php' )
		);

		$actions['palgoals_toggle_status'] = '<a href="' . esc_url( wp_nonce_url( $url, 'palgoals_toggle_testimonial_' . $post->ID ) ) . '">' . esc_html( $label ) . '</a>';

		return $actions;
	}

	/**
	 * Handle the quick status toggle action.
	 *
	 * @return void
	 */
	public function handle_toggle_status() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$status  = isset( $_GET['status'] ) ? Palgoals_Testimonials_CPT::sanitize_status( wp_unslash( $_GET['status'] ) ) : Palgoals_Testimonials_CPT::STATUS_ACTIVE;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to update this testimonial.', 'palgoals-testimonials' ) );
		}

		check_admin_referer( 'palgoals_toggle_testimonial_' . $post_id );
		update_post_meta( $post_id, Palgoals_Testimonials_CPT::META_STATUS, $status );
		Palgoals_Testimonials_CPT::invalidate_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => Palgoals_Testimonials_CPT::POST_TYPE,
					'updated'   => '1',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
