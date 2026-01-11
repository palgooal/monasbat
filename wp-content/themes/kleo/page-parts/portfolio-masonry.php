<?php
/*
Portfolio archive loop item
*/

$post_terms = get_the_terms( $post->ID, 'portfolio-category' );
$term_slug  = '';

if ( ! empty( $post_terms ) ) {
	foreach ( $post_terms as $post_term ) {
		$term_slug .= ' ' . $post_term->slug;
	}
}

$kleo_post_format = get_cfield( 'media_type' ) ?: 'thumb';
?>

<li itemscope itemtype="http://schema.org/CreativeWork"
    id="post-<?php the_ID(); ?>" <?php post_class( $term_slug . " porto-" . $kleo_post_format ); ?>>

	<?php
    echo '<div class="portfolio-item-content animated animate-when-almost-visible el-appear">';
	?>
		<?php do_action( 'kleo_before_portfolio_item' ); ?>

		<?php
		global $kleo_config;

		switch ( $kleo_post_format ) {

			case 'hosted_video':
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
					<div class="kleo-video-wrap">
						<video <?php echo join( ' ', $attr_strings ); ?> controls="controls" class="kleo-video" style="width: 100%; height: 100%;">
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
				}
				break;

			case 'video':

				//oEmbed video
				$video = get_cfield( 'embed' );

				if ( ! empty( $video ) ) {
					global $wp_embed;
					echo '<div class="kleo-video-embed">';
					echo apply_filters( 'kleo_oembed_video', $video );
					echo '</div>';
				}

				break;

			case 'slider':

				$slides      = get_cfield( 'slider' );
				$extra_class = '';
				if ( sq_option( 'portfolio_slider_action' ) == 'modal' ) {
					$extra_class = ' modal-gallery';
				}

				echo '<div class="kleo-banner-slider">'
				     . '<div class="kleo-banner-items' . $extra_class . '" >';
				if ( $slides ) {
					global $portfolio_img_width, $portfolio_img_height;
					$img_width  = isset( $portfolio_img_width ) ? $portfolio_img_width : $kleo_config['post_gallery_img_width'];
					$img_height = isset( $portfolio_img_height ) ? $portfolio_img_height : $kleo_config['post_gallery_img_height'];

					foreach ( $slides as $slide ) {
						if ( $slide ) {
							$image = aq_resize( $slide, $img_width, $img_height, true, true, true );
							//small hack for non-hosted images
							if ( ! $image ) {
								$image = $slide;
							}

							if ( sq_option( 'portfolio_slider_action' ) == 'modal' ) {
								$href = 'href="' . esc_url( $slide ) . '"';
							} else {
								$href = 'href="' . get_the_permalink() . '"';
							}

							echo '<article>
                                    <a ' . $href . '><img src="' . esc_attr( $image ) . '" alt="' . get_the_title() . '">' . kleo_get_img_overlay() . '</a>
                                </article>';
						}
					}
				}

				echo '</div>';
				echo '</div>';

				break;

			default:
				if ( kleo_get_post_thumbnail_url() != '' ) {
					global $portfolio_img_width, $portfolio_img_height;
					$img_width  = isset( $portfolio_img_width ) ? $portfolio_img_width : $kleo_config['post_gallery_img_width'];
					$img_height = isset( $portfolio_img_height ) ? $portfolio_img_height : $kleo_config['post_gallery_img_height'];

					echo '<div class="portfolio-image">';

					$img_url = kleo_get_post_thumbnail_url();
					$image   = aq_resize( $img_url, $img_width, $img_height, true, true, true );
					if ( ! $image ) {
						$image = $img_url;
					}
					echo '<a href="' . get_permalink() . '" class="element-wrap">'
					     . '<img src="' . $image . '">'
					     . kleo_get_img_overlay()
					     . '</a>';

					echo '</div><!--end post-image-->';
				}

				break;
		}
		?>


		<div class="portfolio-header">
			<h3 class="post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		</div><!--end portfolio-header-->

		<?php do_action( 'kleo_after_portfolio_item_title' ); ?>

		<?php if ( kleo_excerpt() != '<p></p>' ) : ?>

			<div class="portfolio-info">
				<?php echo kleo_excerpt( 60, false ); ?>
			</div><!--end post-info-->

		<?php endif; ?>

		<?php do_action( 'kleo_after_portfolio_item' ); ?>

	</div><!--end post-content-->
</li>
