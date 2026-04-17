<?php

/**
 * Testimonial CPT registration.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
	exit;
}

class Palgoals_Testimonials_CPT
{

	const POST_TYPE            = 'pal_testimonial';
	const CATEGORY_TAXONOMY    = 'pal_testimonial_category';
	const META_POSITION        = '_palgoals_client_position';
	const META_COMPANY         = '_palgoals_company_name';
	const META_PHOTO_ID        = '_palgoals_client_photo_id';
	const META_RATING          = '_palgoals_rating';
	const META_WEBSITE_URL     = '_palgoals_website_url';
	const META_STATUS          = '_palgoals_status';
	const STATUS_ACTIVE        = 'active';
	const STATUS_HIDDEN        = 'hidden';
	const CACHE_VERSION_OPTION = 'palgoals_testimonials_cache_version';

	/**
	 * Post type service.
	 *
	 * @var Palgoals_Testimonials_Post_Type
	 */
	protected $post_type_service;

	/**
	 * Taxonomy service.
	 *
	 * @var Palgoals_Testimonials_Category_Taxonomy
	 */
	protected $taxonomy_service;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->post_type_service = new Palgoals_Testimonials_Post_Type(self::POST_TYPE, self::CATEGORY_TAXONOMY);
		$this->taxonomy_service  = new Palgoals_Testimonials_Category_Taxonomy(self::CATEGORY_TAXONOMY, self::POST_TYPE);
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function register()
	{
		add_action('init', array($this, 'register_post_type'));
		add_action('init', array($this, 'register_taxonomy'));
		add_action('init', array($this, 'register_post_meta'));
		add_filter('enter_title_here', array($this, 'enter_title_here'), 10, 2);
		add_filter('use_block_editor_for_post_type', array($this, 'disable_block_editor_for_testimonials'), 10, 2);
		add_action('save_post_' . self::POST_TYPE, array($this, 'maybe_bump_cache_on_save'), 99);
		add_action('deleted_post', array($this, 'maybe_bump_cache_for_post'));
		add_action('trashed_post', array($this, 'maybe_bump_cache_for_post'));
		add_action('untrashed_post', array($this, 'maybe_bump_cache_for_post'));
		add_action('set_object_terms', array($this, 'maybe_bump_cache_for_terms'), 10, 6);
	}

	/**
	 * Register the custom post type.
	 *
	 * @return void
	 */
	public function register_post_type()
	{
		$this->post_type_service->register();
	}

	/**
	 * Register the testimonial category taxonomy.
	 *
	 * @return void
	 */
	public function register_taxonomy()
	{
		$this->taxonomy_service->register();
	}

	/**
	 * Register post meta.
	 *
	 * @return void
	 */
	public function register_post_meta()
	{
		$fields = self::get_meta_fields();

		foreach ($fields as $meta_key => $field) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'single'            => true,
					'type'              => $field['type'],
					'default'           => $field['default'],
					'show_in_rest'      => true,
					'sanitize_callback' => $field['sanitize_callback'],
					'auth_callback'     => array($this, 'can_edit_meta'),
				)
			);
		}
	}

	/**
	 * Capability check for meta edits.
	 *
	 * @return bool
	 */
	public function can_edit_meta()
	{
		return current_user_can('edit_posts');
	}

	/**
	 * Title field placeholder.
	 *
	 * @param string  $text Placeholder text.
	 * @param WP_Post $post Current post.
	 * @return string
	 */
	public function enter_title_here($text, $post)
	{
		if (self::POST_TYPE === $post->post_type) {
			return __('Enter client name', 'palgoals-testimonials');
		}

		return $text;
	}

	/**
	 * Meta box based editing is more stable in the classic editor.
	 *
	 * @param bool   $use_block_editor Whether to use the block editor.
	 * @param string $post_type        Post type key.
	 * @return bool
	 */
	public function disable_block_editor_for_testimonials($use_block_editor, $post_type)
	{
		if (self::POST_TYPE === $post_type) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Bump cache version after testimonial saves.
	 *
	 * @return void
	 */
	public function maybe_bump_cache_on_save()
	{
		self::invalidate_cache();
	}

	/**
	 * Bump cache for relevant post operations.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function maybe_bump_cache_for_post($post_id)
	{
		if (self::POST_TYPE === get_post_type($post_id)) {
			self::invalidate_cache();
		}
	}

	/**
	 * Bump cache when testimonial terms are updated.
	 *
	 * @param int    $object_id    Object ID.
	 * @param array  $terms        Assigned terms.
	 * @param array  $tt_ids       Assigned term taxonomy IDs.
	 * @param string $taxonomy     Taxonomy slug.
	 * @param bool   $append       Whether terms were appended.
	 * @param array  $old_tt_ids   Previous term taxonomy IDs.
	 * @return void
	 */
	public function maybe_bump_cache_for_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids)
	{
		unset($terms, $tt_ids, $append, $old_tt_ids);

		if (self::CATEGORY_TAXONOMY === $taxonomy && self::POST_TYPE === get_post_type($object_id)) {
			self::invalidate_cache();
		}
	}

	/**
	 * Return registered meta configuration.
	 *
	 * @return array
	 */
	public static function get_meta_fields()
	{
		return array(
			self::META_POSITION    => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			self::META_COMPANY     => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			self::META_PHOTO_ID    => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			self::META_RATING      => array(
				'type'              => 'integer',
				'default'           => 5,
				'sanitize_callback' => array(__CLASS__, 'sanitize_rating'),
			),
			self::META_WEBSITE_URL => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			),
			self::META_STATUS      => array(
				'type'              => 'string',
				'default'           => self::STATUS_ACTIVE,
				'sanitize_callback' => array(__CLASS__, 'sanitize_status'),
			),
		);
	}

	/**
	 * Available testimonial statuses.
	 *
	 * @return array
	 */
	public static function get_status_options()
	{
		return array(
			self::STATUS_ACTIVE => __('Active', 'palgoals-testimonials'),
			self::STATUS_HIDDEN => __('Hidden', 'palgoals-testimonials'),
		);
	}

	/**
	 * Sanitize a rating value.
	 *
	 * @param mixed $rating Rating.
	 * @return int
	 */
	public static function sanitize_rating($rating)
	{
		$rating = absint($rating);

		if ($rating < 1) {
			$rating = 1;
		}

		if ($rating > 5) {
			$rating = 5;
		}

		return $rating;
	}

	/**
	 * Sanitize a status value.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function sanitize_status($status)
	{
		$status = sanitize_key($status);

		if (! array_key_exists($status, self::get_status_options())) {
			return self::STATUS_ACTIVE;
		}

		return $status;
	}

	/**
	 * Return current cache version.
	 *
	 * @return int
	 */
	public static function get_cache_version()
	{
		return (int) get_option(self::CACHE_VERSION_OPTION, 1);
	}

	/**
	 * Invalidate the query cache.
	 *
	 * @return void
	 */
	public static function invalidate_cache()
	{
		update_option(self::CACHE_VERSION_OPTION, time(), false);
	}
}
