<?php
$output = $title = $el_class = $taxonomy = $context = '';
extract( shortcode_atts( array(
	'title'    => esc_html__( 'Tags', 'kleo' ),
	'taxonomy' => 'post_tag',
	'context'  => 'all_posts',
	'el_class' => '',
), $atts ) );

$el_class = $this->getExtraClass( $el_class );
$type = 'WP_Widget_Tag_Cloud';
$args = array();

$no_tags = false;
if ( $taxonomy == 'post_tag' && $context == 'single_post' ) {
	global $post, $tag_ids;
	$tag_ids = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );
	if ( ! empty( $tag_ids ) ) {
		$my_func = function () {
			global $tag_ids;

			return array(
				'taxonomy' => 'post_tag',
				'include'  => $tag_ids,
			);
		};
		add_filter( 'widget_tag_cloud_args', $my_func );
	} else {
		$no_tags = true;
	}
}

if ( $no_tags ) {
	echo esc_html__( "No tags defined", 'kleo' );
} else {
	?>
	<div class="vc_wp_tagcloud wpb_content_element<?php echo esc_attr($el_class); ?>">
		<?php
		the_widget( $type, $atts, $args );
		?>
	</div>
	<?php
	if (isset($my_func)) {
		remove_filter('widget_tag_cloud_args', $my_func);
	}
}
