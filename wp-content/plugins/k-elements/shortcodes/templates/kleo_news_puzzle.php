<?php
/**
 * NEWS Puzzle Shortcode
 * [kleo_news_puzzle]
 *
 * @package WordPress
 * @subpackage K Elements
 * @author SeventhQueen <themesupport@seventhqueen.com>
 * @since K Elements 4.2
 */

$args = [];
$output = $el_class = $per_row = $query_offset = $my_query = $output_inside = '';
extract( shortcode_atts( array(
	'posts_query' => '',
	'new_tab'   => 0,
	'query_offset' => '0',
	'per_row' => '3',
	'el_class' => '',
), $atts ) );

$el_class = ( '' != $el_class ) ? ' ' . esc_attr( $el_class ) : '';

if ( function_exists( 'vc_build_loop_query' ) ) {
	list( $args, $my_query ) = vc_build_loop_query( $posts_query );
} else {
	$args = kleo_build_query_loop( $posts_query );
}

if ( (int) $query_offset > 0 ) {
	$args['offset'] = $query_offset;
}
$args['tax_query'] = array(
	array(
		'taxonomy' => 'post_format',
		'field'    => 'slug',
		'terms'    => array(
			'post-format-aside',
			'post-format-audio',
			'post-format-chat',
			//'post-format-gallery',
			//'post-format-image',
			'post-format-link',
			'post-format-quote',
			'post-format-status',
			'post-format-video',
		),
		'operator' => 'NOT IN',
	),
);

if ( '2' == $per_row ) {
	$per_row = '6';
} elseif ( '3' == $per_row ) {
	$per_row = '4';
} elseif ( '4' == $per_row ) {
	$per_row = '3';
}
$column_class = 'col-lg-' . $per_row . ' col-md-' . $per_row . ' col-sm-6 col-xs-12';

// The Query
$the_query = new WP_Query( $args );

// The Loop
if ( $the_query->have_posts() ) {

	ob_start();
	$i = 0;

	while ( $the_query->have_posts() ) {
		$the_query->the_post();
		$i++;
		$even_class = '';
		if ( 0 == $i % 2 ) {
			$even_class = ' need-switch';
		}
		?>

		<div class="kleo-column-news-one-news<?php echo $even_class; ?>">
			<div class="<?php echo $column_class;?>">
				<div class="switch-container">
					<div class="aspect-ratio-container">
						<div class="content kleo-column-news-image-wrapper">
							<?php echo kleo_get_post_media( get_post_format(), array( 'media_width' => 300, 'media_height' => 300 ) ); ?>
						</div>
					</div>
				</div>
				<div class="switch-container">
					<div class="aspect-ratio-container">
						<div class="content kleo-column-news-text-wrapper">
							<h4 class="kleo-column-news-title">
								<a href="<?php the_permalink(); ?>" <?php if ($new_tab == 1 ) { echo 'target="_blank"'; } ?>><?php the_title(); ?></a>
                            </h4>
							<div class="news-meta">
			                    <span class="news-post-meta">
			                        <?php kleo_entry_meta(); ?>
			                    </span>
							</div>

							<?php echo kleo_excerpt(); ?>
						</div>
					</div>
				</div>
			</div>
		</div>

		<?php
	}

	$output_inside .= ob_get_clean();

	/* Restore original Post Data */
	wp_reset_postdata();
}

$output .= "\n\t" . "<div class=\"kleo-column-news border-radius{$el_class}\">{$output_inside}</div>";
