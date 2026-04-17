<?php

/**
 * Shared testimonial renderer for shortcodes and Elementor.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
	exit;
}

class Palgoals_Testimonials_Renderer
{

	const STYLE_HANDLE  = 'palgoals-testimonials-frontend';
	const SCRIPT_HANDLE = 'palgoals-testimonials-frontend';

	/**
	 * Register asset hooks.
	 *
	 * @return void
	 */
	public static function register()
	{
		add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'), 5);
		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_base_styles'), 20);
		add_action('elementor/frontend/after_register_styles', array(__CLASS__, 'register_assets'));
		add_action('elementor/frontend/after_register_scripts', array(__CLASS__, 'register_assets'));
		add_action('elementor/preview/enqueue_styles', array(__CLASS__, 'enqueue_base_styles'));
		add_action('elementor/frontend/after_enqueue_styles', array(__CLASS__, 'enqueue_base_styles'));
	}

	/**
	 * Register reusable frontend assets.
	 *
	 * @return void
	 */
	public static function register_assets()
	{
		Palgoals_Testimonials_Asset_Manager::register_assets();
	}

	/**
	 * Return the first registered Swiper style handle.
	 *
	 * @return string
	 */
	public static function get_swiper_style_handle()
	{
		return Palgoals_Testimonials_Asset_Manager::get_swiper_style_handle();
	}

	/**
	 * Return the first registered Swiper script handle.
	 *
	 * @return string
	 */
	public static function get_swiper_script_handle()
	{
		return Palgoals_Testimonials_Asset_Manager::get_swiper_script_handle();
	}

	/**
	 * Load the base stylesheet early so the markup is styled on first paint.
	 *
	 * @return void
	 */
	public static function enqueue_base_styles()
	{
		Palgoals_Testimonials_Asset_Manager::enqueue_base_styles();
	}

	/**
	 * Render testimonials markup.
	 *
	 * @param array $settings Render settings.
	 * @return string
	 */
	public static function render($settings = array())
	{
		$settings     = self::normalize_settings($settings);
		$testimonials = self::get_testimonials($settings);
		$item_count   = count($testimonials);
		$wrapper_id   = function_exists('wp_unique_id') ? wp_unique_id('palgoals-testimonials-') : uniqid('palgoals-testimonials-');
		$columns      = self::resolve_columns($settings, $item_count);

		self::enqueue_assets($settings);

		ob_start();

		if (empty($testimonials)) :
?>
			<div class="palgoals-testimonials palgoals-testimonials--empty">
				<p class="palgoals-testimonials__empty"><?php esc_html_e('No testimonials found.', 'palgoals-testimonials'); ?></p>
			</div>
		<?php
			return ob_get_clean();
		endif;

		$wrapper_classes = array(
			'palgoals-testimonials',
			'palgoals-testimonials--' . $settings['layout'],
			'palgoals-testimonials--skin-' . $settings['skin'],
			is_rtl() ? 'palgoals-testimonials--rtl' : 'palgoals-testimonials--ltr',
		);

		if (1 === $item_count) {
			$wrapper_classes[] = 'palgoals-testimonials--single';
		}

		$wrapper_style = sprintf(
			'--palgoals-columns-desktop:%1$d;--palgoals-columns-tablet:%2$d;--palgoals-columns-mobile:%3$d;',
			$columns['desktop'],
			$columns['tablet'],
			$columns['mobile']
		);
		$has_intro         = self::has_intro_content($settings);
		$controls_in_intro = 'editorial' === $settings['skin'] && $has_intro && in_array($settings['layout'], array('slider', 'carousel'), true);
		?>
		<div
			id="<?php echo esc_attr($wrapper_id); ?>"
			class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>"
			style="<?php echo esc_attr($wrapper_style); ?>"
			data-layout="<?php echo esc_attr($settings['layout']); ?>"
			data-skin="<?php echo esc_attr($settings['skin']); ?>">
			<?php if ('editorial' === $settings['skin'] && $has_intro) : ?>
				<div class="palgoals-testimonials__shell">
					<?php self::render_intro($settings, $controls_in_intro); ?>
					<div class="palgoals-testimonials__content-wrap">
						<?php self::render_content_markup($testimonials, $settings, $columns, ! $controls_in_intro); ?>
					</div>
				</div>
			<?php else : ?>
				<?php self::render_content_markup($testimonials, $settings, $columns, true); ?>
			<?php endif; ?>
		</div>
	<?php

		return ob_get_clean();
	}

	/**
	 * Render intro content for skins that support it.
	 *
	 * @param array $settings            Normalized settings.
	 * @param bool  $render_nav_controls Whether slider controls should render in the intro.
	 * @return void
	 */
	protected static function render_intro($settings, $render_nav_controls)
	{
	?>
		<div class="palgoals-testimonials__intro">
			<?php if (! empty($settings['intro_eyebrow'])) : ?>
				<p class="palgoals-testimonials__eyebrow"><?php echo esc_html($settings['intro_eyebrow']); ?></p>
			<?php endif; ?>
			<?php if (! empty($settings['intro_title'])) : ?>
				<h2 class="palgoals-testimonials__title"><?php echo esc_html($settings['intro_title']); ?></h2>
			<?php endif; ?>
			<?php if (! empty($settings['intro_description'])) : ?>
				<p class="palgoals-testimonials__description"><?php echo esc_html($settings['intro_description']); ?></p>
			<?php endif; ?>
			<?php if ($render_nav_controls) : ?>
				<?php self::render_controls('palgoals-testimonials__controls--intro'); ?>
			<?php endif; ?>
		</div>
	<?php
	}

	/**
	 * Render the chosen layout markup.
	 *
	 * @param array $testimonials    Prepared testimonial items.
	 * @param array $settings        Normalized settings.
	 * @param array $columns         Resolved columns.
	 * @param bool  $render_controls Whether slider controls should be rendered.
	 * @return void
	 */
	protected static function render_content_markup($testimonials, $settings, $columns, $render_controls)
	{
		if (in_array($settings['layout'], array('slider', 'carousel'), true)) {
			self::render_slider_markup($testimonials, $settings, $columns, $render_controls);
			return;
		}

		self::render_track_markup($testimonials, $settings);
	}

	/**
	 * Render static layouts.
	 *
	 * @param array $testimonials Prepared testimonial items.
	 * @param array $settings     Normalized settings.
	 * @return void
	 */
	protected static function render_track_markup($testimonials, $settings)
	{
	?>
		<div class="palgoals-testimonials__track palgoals-testimonials__track--<?php echo esc_attr($settings['layout']); ?>">
			<?php foreach ($testimonials as $testimonial) : ?>
				<?php self::load_card_template($testimonial, $settings); ?>
			<?php endforeach; ?>
		</div>
	<?php
	}

	/**
	 * Render slider/carousel layouts.
	 *
	 * @param array $testimonials    Prepared testimonial items.
	 * @param array $settings        Normalized settings.
	 * @param array $columns         Resolved columns.
	 * @param bool  $render_controls Whether slider controls should be rendered.
	 * @return void
	 */
	protected static function render_slider_markup($testimonials, $settings, $columns, $render_controls)
	{
		$max_visible    = 'carousel' === $settings['layout'] ? max($columns['desktop'], $columns['tablet'], $columns['mobile']) : 1;
		$swiper_options = array(
			'speed'         => 700,
			'spaceBetween'  => 24,
			'watchOverflow' => true,
			'autoHeight'    => 'slider' === $settings['layout'],
			'layout'        => $settings['layout'],
			'breakpoints'   => array(
				0    => array(
					'slidesPerView' => 'carousel' === $settings['layout'] ? $columns['mobile'] : 1,
				),
				768  => array(
					'slidesPerView' => 'carousel' === $settings['layout'] ? $columns['tablet'] : 1,
				),
				1024 => array(
					'slidesPerView' => 'carousel' === $settings['layout'] ? $columns['desktop'] : 1,
				),
			),
			'loop'          => count($testimonials) > $max_visible,
		);

		if ($settings['autoplay_speed'] > 0) {
			$swiper_options['autoplay'] = array(
				'delay'                => $settings['autoplay_speed'],
				'disableOnInteraction' => false,
				'pauseOnMouseEnter'    => true,
			);
		}
	?>
		<div class="palgoals-testimonials__slider js-palgoals-swiper" data-swiper-options="<?php echo esc_attr(wp_json_encode($swiper_options)); ?>">
			<div class="swiper-wrapper">
				<?php foreach ($testimonials as $testimonial) : ?>
					<div class="swiper-slide palgoals-testimonials__slide">
						<?php self::load_card_template($testimonial, $settings); ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php if ($render_controls) : ?>
			<?php self::render_controls(); ?>
		<?php endif; ?>
	<?php
	}

	/**
	 * Render navigation controls.
	 *
	 * @param string $modifier Optional controls modifier.
	 * @return void
	 */
	protected static function render_controls($modifier = '')
	{
		$controls_class = 'palgoals-testimonials__controls';
		$prev_icon      = is_rtl() ? '&rarr;' : '&larr;';
		$next_icon      = is_rtl() ? '&larr;' : '&rarr;';

		if ($modifier) {
			$controls_class .= ' ' . $modifier;
		}
	?>
		<div class="<?php echo esc_attr($controls_class); ?>">
			<button type="button" class="palgoals-testimonials__button palgoals-testimonials__button--prev" aria-label="<?php echo esc_attr__('Previous testimonial', 'palgoals-testimonials'); ?>">
				<span aria-hidden="true"><?php echo wp_kses_post($prev_icon); ?></span>
			</button>
			<div class="palgoals-testimonials__pagination"></div>
			<button type="button" class="palgoals-testimonials__button palgoals-testimonials__button--next" aria-label="<?php echo esc_attr__('Next testimonial', 'palgoals-testimonials'); ?>">
				<span aria-hidden="true"><?php echo wp_kses_post($next_icon); ?></span>
			</button>
		</div>
	<?php
	}

	/**
	 * Determine whether intro text should be rendered.
	 *
	 * @param array $settings Normalized settings.
	 * @return bool
	 */
	protected static function has_intro_content($settings)
	{
		foreach (array('intro_eyebrow', 'intro_title', 'intro_description') as $field) {
			if (! empty($settings[$field])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Query testimonials using a versioned cache key.
	 *
	 * @param array $settings Normalized settings.
	 * @return array
	 */
	public static function get_testimonials($settings)
	{
		return Palgoals_Testimonials_Query::get_testimonials(
			array(
				'posts_per_page' => isset($settings['limit']) ? $settings['limit'] : 6,
				'order'          => isset($settings['order']) ? $settings['order'] : 'DESC',
				'rating'         => isset($settings['rating']) ? $settings['rating'] : 0,
			)
		);
	}

	/**
	 * Return WP_Query arguments.
	 *
	 * @param array $settings Normalized settings.
	 * @return array
	 */
	protected static function get_query_args($settings)
	{
		$meta_query = array(
			array(
				'key'     => Palgoals_Testimonials_CPT::META_STATUS,
				'value'   => Palgoals_Testimonials_CPT::STATUS_ACTIVE,
				'compare' => '=',
			),
		);

		if ($settings['rating'] > 0) {
			$meta_query[] = array(
				'key'     => Palgoals_Testimonials_CPT::META_RATING,
				'value'   => $settings['rating'],
				'type'    => 'NUMERIC',
				'compare' => '>=',
			);
		}

		return array(
			'post_type'              => Palgoals_Testimonials_CPT::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => $settings['limit'],
			'orderby'                => 'date',
			'order'                  => $settings['order'],
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'meta_query'             => $meta_query,
		);
	}

	/**
	 * Prepare one testimonial payload.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected static function prepare_testimonial($post_id)
	{
		$name        = get_the_title($post_id);
		$photo_id    = absint(get_post_meta($post_id, Palgoals_Testimonials_CPT::META_PHOTO_ID, true));
		$raw_rating  = get_post_meta($post_id, Palgoals_Testimonials_CPT::META_RATING, true);
		$rating      = '' === $raw_rating ? 5 : Palgoals_Testimonials_CPT::sanitize_rating($raw_rating);
		$website_url = esc_url(get_post_meta($post_id, Palgoals_Testimonials_CPT::META_WEBSITE_URL, true));

		return array(
			'id'       => $post_id,
			'name'     => $name,
			'initials' => self::get_initials($name),
			'position' => get_post_meta($post_id, Palgoals_Testimonials_CPT::META_POSITION, true),
			'company'  => get_post_meta($post_id, Palgoals_Testimonials_CPT::META_COMPANY, true),
			'photo_id' => $photo_id,
			'rating'   => $rating,
			'content'  => get_post_field('post_content', $post_id),
			'website'  => $website_url,
		);
	}

	/**
	 * Normalize renderer settings.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	protected static function normalize_settings($settings)
	{
		$settings = wp_parse_args(
			$settings,
			array(
				'skin'           => 'default',
				'layout'         => 'grid',
				'limit'          => 6,
				'order'          => 'DESC',
				'rating'         => 0,
				'columns'        => 3,
				'columns_tablet' => 2,
				'columns_mobile' => 1,
				'show_photo'     => true,
				'show_company'   => true,
				'show_rating'    => true,
				'intro_eyebrow'  => '',
				'intro_title'    => '',
				'intro_description' => '',
				'autoplay_speed' => 5000,
			)
		);

		$allowed_layouts    = array('grid', 'slider', 'masonry', 'carousel');
		$allowed_skins      = array('default', 'editorial');
		$settings['skin']   = in_array($settings['skin'], $allowed_skins, true) ? $settings['skin'] : 'default';
		$settings['layout'] = in_array($settings['layout'], $allowed_layouts, true) ? $settings['layout'] : 'grid';
		$settings['limit']  = max(1, absint($settings['limit']));
		$settings['rating'] = max(0, min(5, absint($settings['rating'])));
		$settings['order']  = 'ASC' === strtoupper($settings['order']) ? 'ASC' : 'DESC';

		foreach (array('columns', 'columns_tablet', 'columns_mobile') as $column_key) {
			$settings[$column_key] = max(1, min(6, absint($settings[$column_key])));
		}

		foreach (array('show_photo', 'show_company', 'show_rating') as $boolean_key) {
			$settings[$boolean_key] = self::normalize_boolean($settings[$boolean_key]);
		}

		foreach (array('intro_eyebrow', 'intro_title') as $text_key) {
			$settings[$text_key] = sanitize_text_field($settings[$text_key]);
		}

		$settings['intro_description'] = sanitize_textarea_field($settings['intro_description']);

		$settings['autoplay_speed'] = max(0, absint($settings['autoplay_speed']));

		if ('slider' === $settings['layout']) {
			$settings['columns']        = 1;
			$settings['columns_tablet'] = 1;
			$settings['columns_mobile'] = 1;
		}

		return $settings;
	}

	/**
	 * Normalize a boolean-like value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	protected static function normalize_boolean($value)
	{
		if (is_bool($value)) {
			return $value;
		}

		if (is_string($value)) {
			return in_array(strtolower($value), array('1', 'true', 'yes', 'on'), true);
		}

		return ! empty($value);
	}

	/**
	 * Resolve responsive columns against the actual number of testimonials.
	 *
	 * @param array $settings   Normalized settings.
	 * @param int   $item_count Number of testimonials.
	 * @return array
	 */
	protected static function resolve_columns($settings, $item_count)
	{
		$item_count = max(1, absint($item_count));

		if (in_array($settings['layout'], array('slider'), true)) {
			return array(
				'desktop' => 1,
				'tablet'  => 1,
				'mobile'  => 1,
			);
		}

		return array(
			'desktop' => min($settings['columns'], $item_count),
			'tablet'  => min($settings['columns_tablet'], $item_count),
			'mobile'  => min($settings['columns_mobile'], $item_count),
		);
	}

	/**
	 * Enqueue only the required assets for the chosen layout.
	 *
	 * @param array $settings Normalized settings.
	 * @return void
	 */
	protected static function enqueue_assets($settings)
	{
		if (! wp_style_is(self::STYLE_HANDLE, 'registered') || ! wp_script_is(self::SCRIPT_HANDLE, 'registered')) {
			self::register_assets();
		}

		if (in_array($settings['layout'], array('slider', 'carousel'), true)) {
			self::maybe_enqueue_swiper_assets();
			wp_enqueue_script(self::SCRIPT_HANDLE);
		}
	}

	/**
	 * Try to reuse any Swiper assets already registered by Elementor or the theme.
	 *
	 * @return void
	 */
	protected static function maybe_enqueue_swiper_assets()
	{
		Palgoals_Testimonials_Asset_Manager::enqueue_swiper_assets();
	}

	/**
	 * Render stars markup.
	 *
	 * @param int $rating Rating.
	 * @return string
	 */
	public static function render_stars($rating)
	{
		$rating = max(0, min(5, absint($rating)));

		ob_start();
	?>
		<span class="palgoals-testimonial-stars" aria-label="<?php echo esc_attr(sprintf(__('%d out of 5 stars', 'palgoals-testimonials'), $rating)); ?>">
			<?php for ($index = 1; $index <= 5; $index++) : ?>
				<span class="palgoals-testimonial-stars__icon <?php echo $index <= $rating ? 'is-active' : ''; ?>" aria-hidden="true">&#9733;</span>
			<?php endfor; ?>
		</span>
<?php
		return ob_get_clean();
	}

	/**
	 * Build initials for the avatar fallback.
	 *
	 * @param string $name Client name.
	 * @return string
	 */
	public static function get_initials($name)
	{
		return Palgoals_Testimonials_Query::get_initials($name);
	}

	/**
	 * Load the testimonial card template.
	 *
	 * @param array $testimonial Prepared testimonial data.
	 * @param array $settings    Normalized render settings.
	 * @return void
	 */
	protected static function load_card_template($testimonial, $settings)
	{
		$template = Palgoals_Testimonials_Legacy_Template_Loader::locate('testimonial-card.php');

		if ($template) {
			include $template;
		}
	}
}
