<?php
/**
 * NEWS Ticker Shortcode
 * [kleo_news_ticker]
 *
 * @package WordPress
 * @subpackage K Elements
 * @author SeventhQueen <themesupport@seventhqueen.com>
 * @since K Elements 3.0
 */

$args = [];
$output = $my_query = $new_tab = $output_inside = '';

extract( shortcode_atts( array(
	'posts_query'  => '',
	'new_tab'      => 0,
	'query_offset' => '0',
	'el_class'     => '',
), $atts ) );

$el_class = ( $el_class != '' ) ? 'news-ticker ' . esc_attr( $el_class ) : 'news-ticker';

if ( function_exists( 'vc_build_loop_query' ) ) {
	list( $args, $my_query ) = vc_build_loop_query( $posts_query );
} else {
	$args = kleo_build_query_loop( $posts_query );
}


if ( (int) $query_offset > 0 ) {
	$args['offset'] = $query_offset;
}

query_posts( $args );

if ( have_posts() ) :

	ob_start();

	while ( have_posts() ) : the_post();
		?>

		<article id="post-<?php the_ID(); ?>" <?php post_class(); ?> style="display: inline-block;">
			<h3 class="post-title"><a href="<?php the_permalink(); ?>" <?php if ( $new_tab == 1 ) {
					echo 'target="_blank"';
				} ?>><?php the_title(); ?></a></h3>
		</article>

	<?php

	endwhile;

	$output_inside .= ob_get_clean();

endif;

// Reset Query
wp_reset_query();

$output .= "\n\t" . "<div class=\"{$el_class}\">{$output_inside}</div>";
