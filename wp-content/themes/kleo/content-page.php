<?php
/**
 * The template used for displaying page content
 *
 * @package WordPress
 * @subpackage Kleo
 * @since Kleo 1.0
 */
?>

<?php
$extra_classes = 'clearfix';
if ( get_cfield( 'centered_text' ) == 1 ) {
	$extra_classes .= ' text-center';
}
?>

<!-- Begin Article -->
<article id="post-<?php the_ID(); ?>" <?php post_class( $extra_classes ); ?>>

	<?php
	if ( kleo_postmedia_enabled( 'page_media', 0, true ) ) {

		$slides = get_cfield( 'slider' );
		$audio  = get_cfield( 'audio' );

		//oEmbed video
		$video = get_cfield( 'embed' );
		// video bg self hosted
		$bg_video_args = array();

		if ( get_cfield( 'video_mp4' ) ) {
			$bg_video_args['mp4'] = esc_attr( get_cfield( 'video_mp4' ) );
		}
		if ( get_cfield( 'video_ogv' ) ) {
			$bg_video_args['ogv'] = esc_attr( get_cfield( 'video_ogv' ) );
		}
		if ( get_cfield( 'video_webm' ) ) {
			$bg_video_args['webm'] = esc_attr( get_cfield( 'video_webm' ) );
		}

		if ( ! empty( $bg_video_args ) ) {
			$attr_strings = array(
				'preload="none"'
			);

			if ( get_cfield( 'video_poster' ) ) {
				$attr_strings[] = 'poster="' . esc_attr( get_cfield( 'video_poster' ) ) . '"';
			}
			?>
			<div class="article-media clearfix">
				<div class="kleo-video-wrap">
					<video <?php echo join( ' ', $attr_strings ); ?> controls="controls" class="kleo-video" style="height: 100%; width: 100%;">';
						<?php
						$source = '<source type="%s" src="%s" />';
						foreach ( $bg_video_args as $video_type => $video_src ) {
							$video_type = wp_check_filetype( $video_src, wp_get_mime_types() );
							echo sprintf( $source, $video_type['type'], esc_url( $video_src ) );
						}
						?>
					</video>
				</div>
			</div>

			<?php
		} // oEmbed
		elseif ( ! empty( $video ) ) {
			?>
			<div class="article-media clearfix">
				<?php
					echo wp_oembed_get( $video );
				?>
			</div>
		<?php
		} elseif ( ! empty( $slides ) ) {
			?>
			<div class="article-media kleo-banner-slider">
				<div class="kleo-banner-items">
					<?php
					foreach ( $slides as $slide ) {
						?>
						<article>
							<a href="<?php echo esc_url( $slide ); ?>" data-rel="modalPhoto[inner-gallery]">
								<img src="<?php echo esc_url( $slide ); ?>" alt="<?php echo get_the_title(); ?>">
								<?php echo kleo_get_img_overlay(); ?>
							</a>
						</article>
						<?php
					}
					?>
				</div>
				<a href="#" class="kleo-banner-prev"><i class="icon-angle-left"></i></a>
				<a href="#" class="kleo-banner-next"><i class="icon-angle-right"></i></a>
				<div class="kleo-banner-features-pager carousel-pager"></div>
			</div>
		<?php
		} elseif ( ! empty( $audio ) ) {
			?>

			<div class="article-media clearfix">
				<audio id="audio_<?php the_id(); ?>" class="kleo-audio" src="<?php echo esc_attr( $audio ); ?>"></audio>
			</div><!--end article-media-->

		<?php } elseif ( get_post_thumbnail_id() ) { ?>

			<div class="article-media clearfix">
				<?php the_post_thumbnail( 'kleo-full-width' ); ?>
			</div><!--end article-media-->

		<?php } ?>

	<?php } /* end if is_single */ ?>

	<div class="article-content">

		<?php the_content(); ?>
		<?php wp_link_pages( array(
			'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'kleo' ),
			'after'  => '</div>',
		) ); ?>

	</div><!--end article-content-->

</article>
<!-- End  Article -->
