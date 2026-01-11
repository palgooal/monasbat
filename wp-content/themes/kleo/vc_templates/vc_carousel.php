<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}
if ( ! function_exists( 'kleo_build_query_loop' ) ) {
	echo 'Please update K Elements plugin to latest version';
	return;
}

$el_class = $posts_query = $speed = $autoplay = $query_offset = $layout = $min_items = $max_items = $height = '';

$atts = shortcode_atts( array(
	'el_class'         => '',
	'posts_query'      => 'post_type:post',
	'layout'      => 'default',
	'min_items'      => '3',
	'max_items'      => '6',
    'autoplay'       => '',
    'speed'         => '',
	'height'      => '',

), $atts );

extract( $atts );

/* KLEO Added */
if ( $min_items == '' ) {
	$min_items = '3';
}
if ( $max_items == '' ) {
	$max_items = '6';
}
/* END Kleo Added */

global $vc_posts_grid_exclude_id;
$vc_posts_grid_exclude_id[] = get_the_ID(); // fix recursive nesting

if ( is_array( $posts_query ) ) {
	$posts_query['post_status'] = 'publish';
} else {
	$posts_query .= '|post_status:publish';
}
$args = kleo_build_query_loop( $posts_query );

if ( (int) $query_offset > 0 ) {
	$args['offset'] = $query_offset;
}

$extra_data = [
	'data-min-items' => esc_attr( $min_items ),
	'data-max-items' => esc_attr( $max_items ),
];

if ( $autoplay == 'yes' ) {
	$extra_data['data-autoplay'] = 'true';
}

if ( $speed ) {
	$extra_data['data-speed'] = esc_attr( $speed );
}

if ( $height != '' ) {
	$extra_data['data-items-height'] = esc_attr( $height );
}

if ( $layout != 'default' ) {
	$el_class .= ' kleo-carousel-style-' . $layout;
}

query_posts( $args );

if ( have_posts() ) : ?>

	<div class="kleo-carousel-container <?php echo esc_attr( $el_class ); ?>">
		<div class="kleo-carousel-items kleo-carousel-post"<?php echo kleo_get_attributes_string( $extra_data ); ?>>
			<ul class="kleo-carousel">

				<?php
				while ( have_posts() ) : the_post();

					get_template_part( 'page-parts/post-content-carousel' );

				endwhile;
				?>

			</ul>
		</div>
		<div class="carousel-arrow">
			<a class="carousel-prev" href="#"><i class="icon-angle-left"></i></a>
			<a class="carousel-next" href="#"><i class="icon-angle-right"></i></a>
		</div>
		<div class="kleo-carousel-post-pager carousel-pager"></div>
	</div><!--end carousel-container-->

<?php
endif;

// Reset Query
wp_reset_query();
