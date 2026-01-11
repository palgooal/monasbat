<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

if ( ! function_exists( 'kleo_build_query_loop' ) ) {
	echo 'Please update K Elements plugin to latest version';
	return;
}

global $kleo_config;

$el_class = $loop = '';

/* KLEO Added */
$post_layout = $query_offset = $show_meta = $show_excerpt = $show_thumb = $show_switcher = $inline_meta = $show_footer = $load_more = $ajax_post = $ajax_paged = '';
/* END KLEO Added */


$atts = shortcode_atts( array(
	'el_class'         => '',
	'loop'             => 'post_type:post',
	'post_layout'      => 'grid',
	'columns'          => '4',
	'show_switcher'    => 'no',
	'switcher_layouts' => implode( ',', array_values( array_flip( $kleo_config['blog_layouts'] ) ) ),
	'query_offset'     => '',
	'show_thumb'       => 'yes',
	'show_meta'        => 'yes',
	'inline_meta'      => 'no',
	'show_excerpt'     => 'yes',
	'show_footer'      => 'yes',
	'load_more'        => '',
	'ajax_post'        => '',
	'ajax_paged'       => '',
), $atts );

extract( $atts );

$args = kleo_build_query_loop( $loop );

if ( (int) $query_offset > 0 ) {
	$args['offset'] = $query_offset;
}

/* Set the global post from the sent AJAX request */
if ( '' != $ajax_post ) {
	$page_post_id = $ajax_post;
} else {
	$page_post_id = get_the_ID();
}

/* check if we have pagination */
if ( '' != $load_more ) {
	global $sq_posts_count;
	$sq_posts_count ++;

	set_transient( 'kleo_post_' . $page_post_id . '_' . $sq_posts_count, $atts );

	/* if we get a page over ajax request */
	if ( '' != $ajax_paged ) {
		$args['paged'] = $ajax_paged;
	}

} else {
	$sq_posts_count = 0;
}

$el_class = $el_class != "" ? " " . $el_class : "";

// Alias for Grid to Masonry
if ( $post_layout == 'grid' ) {
	$post_layout = 'masonry';
}
$post_layout = apply_filters( 'kleo_blog_type', $post_layout, $page_post_id );

if ( $post_layout == 'standard' && 0 === strpos( $show_thumb, 'just_' ) ) {
	global $conditional_thumb;
	$conditional_thumb = substr( $show_thumb, - 1 );
	$el_class          .= ' just-thumb-' . esc_attr( $conditional_thumb );
} elseif ( $show_thumb == 'no' ) {
	global $conditional_thumb;
	$conditional_thumb = 0;
}

if ( $show_meta == 'yes' ) {
	$el_class .= ' with-meta';
} else {
	$el_class .= ' no-meta';
}

if ( $show_footer == 'no' ) {
	$el_class .= ' no-footer';
}

if ( $show_excerpt == 'no' ) {
	$el_class .= ' no-excerpt';
}

if ( $inline_meta == 'yes' ) {
	$el_class .= ' inline-meta';
}

$el_class .= " " . esc_attr( $post_layout ) . '-listing';

$the_query = new WP_Query( $args );

$current_page = 1;
if ( '' != $ajax_paged ) {
	$current_page = $ajax_paged;
}
$next_page = $current_page + 1;

if ( $the_query->have_posts() ) : ?>

	<div class="sq-posts-data" style="display: none;">
		<?php wp_nonce_field( 'kleo-ajax-posts-nonce', 'post-security', true, false ); ?>
		<input type="hidden" name="pitem" value="<?php echo esc_attr( $sq_posts_count ); ?>">
		<input type="hidden" name="post_id" value="<?php echo esc_attr( $page_post_id ); ?>">
	</div>

	<?php if ( $show_switcher == 'yes' && ! empty( $switcher_layouts ) ) : ?>

		<?php
		if ( ! is_array( $switcher_layouts ) ) {
			$switcher_layouts = explode( ',', $switcher_layouts );
		}
		kleo_view_switch( $switcher_layouts, $post_layout, $page_post_id );
		?>

	<?php endif; ?>

	<?php if ( $post_layout == 'masonry' ) : ?>

		<div class="posts-listing responsive-cols kleo-masonry per-row-<?php echo esc_attr( $columns ); ?><?php echo esc_attr( $el_class ); ?>">

	<?php else: ?>

		<div class="posts-listing <?php echo esc_attr( $el_class ); ?>">

	<?php endif; ?>

	<?php
	while ( $the_query->have_posts() ) : $the_query->the_post();

		if ( $post_layout != 'standard' ) {
			get_template_part( 'page-parts/post-content-' . $post_layout );
		} else {
			get_template_part( 'content', get_post_format() );
		}

	endwhile;
	?>

	</div> <!-- END post listing -->

	<?php if ( '' != $load_more && $the_query->max_num_pages >= $next_page ) : ?>
		<div class="clearfix clear"></div>
		<div class="posts-load-more text-center">
			<a data-paged="<?php echo esc_attr( $next_page ); ?>" class="btn btn-highlight style2"
			   href="#"><?php _e( 'Load more', 'kleo' ); ?></a>
		</div>
	<?php endif; ?>

<?php
endif;
/* Restore original Post Data */
wp_reset_postdata();
