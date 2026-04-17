<?php

/**
 * Shared renderer for chat screenshot displays.
 *
 * @package PalgoalsTestimonials
 */

if (! defined('ABSPATH')) {
	exit;
}

class Palgoals_Testimonials_Screenshots_Renderer
{

	/**
	 * Render screenshot markup.
	 *
	 * @param array $settings Render settings.
	 * @return string
	 */
	public static function render($settings = array())
	{
		$settings    = self::normalize_settings($settings);
		$screenshots = self::get_screenshots($settings);
		$item_count  = count($screenshots);
		$wrapper_id  = function_exists('wp_unique_id') ? wp_unique_id('palgoals-chat-shots-') : uniqid('palgoals-chat-shots-');
		$columns     = self::resolve_columns($settings, $item_count);

		self::enqueue_assets($settings);

		ob_start();

		if (empty($screenshots)) :
?>
			<div class="palgoals-chat-shots palgoals-chat-shots--empty">
				<p class="palgoals-chat-shots__empty"><?php esc_html_e('No screenshots found.', 'palgoals-testimonials'); ?></p>
			</div>
		<?php
			return ob_get_clean();
		endif;

		$wrapper_classes = array(
			'palgoals-chat-shots',
			'palgoals-chat-shots--' . $settings['layout'],
		);

		if (1 === $item_count) {
			$wrapper_classes[] = 'palgoals-chat-shots--single';
		}

		$wrapper_style = sprintf(
			'--palgoals-columns-desktop:%1$d;--palgoals-columns-tablet:%2$d;--palgoals-columns-mobile:%3$d;',
			$columns['desktop'],
			$columns['tablet'],
			$columns['mobile']
		);

		$template_settings                   = $settings;
		$template_settings['lightbox_group'] = $wrapper_id;
		?>
		<div
			id="<?php echo esc_attr($wrapper_id); ?>"
			class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>"
			style="<?php echo esc_attr($wrapper_style); ?>"
			data-layout="<?php echo esc_attr($settings['layout']); ?>">
			<?php if (in_array($settings['layout'], array('slider', 'carousel'), true)) : ?>
				<?php
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
					'loop'          => count($screenshots) > $max_visible,
				);

				if ($settings['autoplay_speed'] > 0) {
					$swiper_options['autoplay'] = array(
						'delay'                => $settings['autoplay_speed'],
						'disableOnInteraction' => false,
						'pauseOnMouseEnter'    => true,
					);
				}
				?>
				<div class="palgoals-chat-shots__slider js-palgoals-swiper" data-swiper-options="<?php echo esc_attr(wp_json_encode($swiper_options)); ?>">
					<div class="swiper-wrapper">
						<?php foreach ($screenshots as $screenshot) : ?>
							<div class="swiper-slide palgoals-chat-shots__slide">
								<?php self::load_card_template($screenshot, $template_settings); ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="palgoals-chat-shots__controls">
					<button type="button" class="palgoals-chat-shots__button palgoals-chat-shots__button--prev" aria-label="<?php echo esc_attr__('Previous screenshot', 'palgoals-testimonials'); ?>">
						<span aria-hidden="true">&larr;</span>
					</button>
					<div class="palgoals-chat-shots__pagination"></div>
					<button type="button" class="palgoals-chat-shots__button palgoals-chat-shots__button--next" aria-label="<?php echo esc_attr__('Next screenshot', 'palgoals-testimonials'); ?>">
						<span aria-hidden="true">&rarr;</span>
					</button>
				</div>
			<?php else : ?>
				<div class="palgoals-chat-shots__track palgoals-chat-shots__track--<?php echo esc_attr($settings['layout']); ?>">
					<?php foreach ($screenshots as $screenshot) : ?>
						<?php self::load_card_template($screenshot, $template_settings); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
<?php

		return ob_get_clean();
	}

	/**
	 * Query screenshot items using the testimonial CPT.
	 *
	 * @param array $settings Normalized settings.
	 * @return array
	 */
	public static function get_screenshots($settings)
	{
		return Palgoals_Testimonials_Query::get_screenshots($settings);
	}

	/**
	 * Build query args for screenshot items.
	 *
	 * @param array $settings Normalized settings.
	 * @return array
	 */
	protected static function get_query_args($settings)
	{
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
			'meta_query'             => array(
				array(
					'key'     => Palgoals_Testimonials_CPT::META_STATUS,
					'value'   => Palgoals_Testimonials_CPT::STATUS_ACTIVE,
					'compare' => '=',
				),
				array(
					'key'     => Palgoals_Testimonials_CPT::META_PHOTO_ID,
					'value'   => 0,
					'type'    => 'NUMERIC',
					'compare' => '>',
				),
			),
		);
	}

	/**
	 * Prepare one screenshot payload.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected static function prepare_screenshot($post_id)
	{
		$photo_id = absint(get_post_meta($post_id, Palgoals_Testimonials_CPT::META_PHOTO_ID, true));
		$caption  = trim((string) get_post_field('post_content', $post_id));

		if (self::is_placeholder_caption($caption)) {
			$caption = '';
		}

		return array(
			'id'        => $post_id,
			'title'     => get_the_title($post_id),
			'photo_id'  => $photo_id,
			'image_url' => $photo_id ? wp_get_attachment_image_url($photo_id, 'full') : '',
			'caption'   => $caption,
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
				'layout'          => 'masonry',
				'limit'           => 6,
				'order'           => 'DESC',
				'columns'         => 3,
				'columns_tablet'  => 2,
				'columns_mobile'  => 1,
				'show_title'      => false,
				'show_caption'    => false,
				'open_full_image' => true,
				'autoplay_speed'  => 0,
			)
		);

		$allowed_layouts    = array('grid', 'masonry', 'slider', 'carousel');
		$settings['layout'] = in_array($settings['layout'], $allowed_layouts, true) ? $settings['layout'] : 'masonry';
		$settings['limit']  = max(1, absint($settings['limit']));
		$settings['order']  = 'ASC' === strtoupper($settings['order']) ? 'ASC' : 'DESC';

		foreach (array('columns', 'columns_tablet', 'columns_mobile') as $column_key) {
			$settings[$column_key] = max(1, min(6, absint($settings[$column_key])));
		}

		foreach (array('show_title', 'show_caption', 'open_full_image') as $boolean_key) {
			$settings[$boolean_key] = self::normalize_boolean($settings[$boolean_key]);
		}

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
	 * Resolve responsive columns against the actual number of items.
	 *
	 * @param array $settings   Normalized settings.
	 * @param int   $item_count Number of screenshots.
	 * @return array
	 */
	protected static function resolve_columns($settings, $item_count)
	{
		$item_count = max(1, absint($item_count));

		if ('slider' === $settings['layout']) {
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
	 * Enqueue assets for screenshot layouts that need them.
	 *
	 * @param array $settings Normalized settings.
	 * @return void
	 */
	protected static function enqueue_assets($settings)
	{
		Palgoals_Testimonials_Renderer::register_assets();

		if (in_array($settings['layout'], array('slider', 'carousel'), true)) {
			self::maybe_enqueue_swiper_assets();
			wp_enqueue_script(Palgoals_Testimonials_Renderer::SCRIPT_HANDLE);
		}
	}

	/**
	 * Reuse registered Swiper assets when available.
	 *
	 * @return void
	 */
	protected static function maybe_enqueue_swiper_assets()
	{
		$style_handle  = Palgoals_Testimonials_Renderer::get_swiper_style_handle();
		$script_handle = Palgoals_Testimonials_Renderer::get_swiper_script_handle();

		if ($style_handle) {
			wp_enqueue_style($style_handle);
		}

		if ($script_handle) {
			wp_enqueue_script($script_handle);
		}
	}

	/**
	 * Detect placeholder content that should not be shown as a real caption.
	 *
	 * @param string $caption Raw caption.
	 * @return bool
	 */
	protected static function is_placeholder_caption($caption)
	{
		$caption      = trim(wp_strip_all_tags($caption));
		$placeholders = array(
			'Write the testimonial content here...',
			__('Write the testimonial content here...', 'palgoals-testimonials'),
		);

		return in_array($caption, $placeholders, true);
	}

	/**
	 * Load the screenshot card template.
	 *
	 * @param array $screenshot Prepared screenshot data.
	 * @param array $settings   Normalized render settings.
	 * @return void
	 */
	protected static function load_card_template($screenshot, $settings)
	{
		$template = Palgoals_Testimonials_Legacy_Template_Loader::locate('chat-screenshot-card.php');

		if ($template) {
			include $template;
		}
	}
}
