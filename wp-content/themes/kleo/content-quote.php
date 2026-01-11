<?php
/**
 * The template for displaying posts in the Quote post format
 *
 * @package WordPress
 * @subpackage Kleo
 * @since Kleo 1.0
 */
?>

<?php
$post_class = 'clearfix';
if( is_single() && get_cfield( 'centered_text' ) == 1 ) { $post_class .= ' text-center'; }
?>

<!-- Begin Article -->

<article id="post-<?php the_ID(); ?>" <?php post_class(array( $post_class )); ?>>

	<?php if ( ! is_single() ) : ?>
		<h2 class="article-title entry-title">
			<a href="<?php echo get_permalink(); ?>" rel="bookmark"><?php the_title(); ?></a>
		</h2>
	<?php endif;?>

	<?php if ( kleo_postmeta_enabled() ) :
		$meta_class = 'article-meta';
		if ( sq_kleo()->get_option( 'has_vc_shortcode' ) ) {
			$meta_class .= ' container';
		}
		?>
        <div class="<?php echo esc_attr( $meta_class ); ?>">
			<span class="post-meta">
				<?php kleo_entry_meta();?>
			</span>
			<?php edit_post_link( esc_html__( 'Edit', 'kleo' ), '<span class="edit-link">', '</span>' ); ?>
		</div><!--end article-meta-->
	<?php endif;?>

	<div class="article-content">

		<?php do_action( 'kleo_before_inner_article_loop' ); ?>

		<?php the_content( kleo_get_continue_reading() ); ?>

		<?php wp_link_pages( array( 'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'kleo' ), 'after' => '</div>' ) ); ?>

		<?php do_action( 'kleo_after_inner_article_loop' ); ?>

	</div><!--end article-content-->
	

</article>

<!-- End  Article -->