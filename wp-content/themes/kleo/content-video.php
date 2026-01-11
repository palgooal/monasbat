<?php
/**
 * The template for displaying posts in the Video post format
 *
 * @package Kleo
 * @subpackage Twenty_Fourteen
 * @since Kleo 1.0
 */
?>

<?php
/* Helper variables for this template */
$is_single          = is_single();
$post_meta_enabled  = kleo_postmeta_enabled();
$post_media_enabled = kleo_postmedia_enabled();

/* Check if we need an extra container for meta and media */
$show_extra_container = $is_single && sq_kleo()->get_option( 'has_vc_shortcode' ) && $post_media_enabled;

$post_class = 'clearfix';
if ( $is_single && get_cfield( 'centered_text' ) == 1 ) {
	$post_class .= ' text-center';
}
?>

<!-- Begin Article -->
<article id="post-<?php the_ID(); ?>" <?php post_class( array( $post_class ) ); ?>>

	<?php if ( ! $is_single ) : ?>
		<h2 class="article-title entry-title">
			<a href="<?php the_permalink(); ?>"
			   title="<?php echo esc_attr( sprintf( esc_html__( 'Permalink to %s', 'kleo' ), the_title_attribute( 'echo=0' ) ) ); ?>"
			   rel="bookmark"><?php the_title(); ?></a>
		</h2>
	<?php endif; // is_single() ?>

	<?php if ( $show_extra_container ) : /* Small fix for full width layout to center media and meta */ ?>
	<div class="container">
		<?php endif; ?>

		<?php if ( $post_meta_enabled ) :
			$meta_class = 'article-meta';
			if ( sq_kleo()->get_option( 'has_vc_shortcode' ) ) {
				$meta_class .= ' container';
			}
			?>
            <div class="<?php echo esc_attr( $meta_class ); ?>">
                <span class="post-meta">
                    <?php kleo_entry_meta(); ?>
                </span>
				<?php edit_post_link( esc_html__( 'Edit', 'kleo' ), '<span class="edit-link">', '</span>' ); ?>
			</div><!--end article-meta-->

		<?php endif; ?>

		<?php if ( $post_media_enabled ) : ?>

			<div class="article-media">
				<?php
				//oEmbed video
				$video = get_cfield( 'embed' );
				// video bg self hosted
				$bg_video_args = array();
				$k_video       = '';

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
				<?php
				} // oEmbed
				elseif ( ! empty( $video ) ) {
					global $wp_embed;
					echo apply_filters( 'kleo_oembed_video', $video );
				}
				?>

			</div><!--end article-media-->

		<?php endif; ?>

		<?php if ( $show_extra_container ) : /* Small fix for full width layout to center media and meta */ ?>
	</div>
<?php endif; ?>

	<div class="article-content">

		<?php do_action( 'kleo_before_inner_article_loop' ); ?>

		<?php if ( ! $is_single ) : // Only display Excerpts for Search ?>

			<?php echo kleo_excerpt( 50 ); ?>
			<p class="kleo-continue">
				<a class="btn btn-default" href="<?php the_permalink() ?>"><?php kleo_continue_reading(); ?></a>
			</p>

		<?php else : ?>

			<?php the_content( kleo_get_continue_reading() ); ?>
			<?php wp_link_pages( array(
				'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'kleo' ),
				'after'  => '</div>',
			) ); ?>

		<?php endif; ?>

		<?php do_action( 'kleo_after_inner_article_loop' ); ?>

	</div><!--end article-content-->

</article>
<!-- End  Article -->
