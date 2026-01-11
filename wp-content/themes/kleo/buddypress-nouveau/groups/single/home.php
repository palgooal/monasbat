<?php
/**
 * BuddyPress - Groups Home
 *
 * @since 3.0.0
 * @version 3.0.0
 */

if ( bp_has_groups() ) :
	while ( bp_groups() ) :
		bp_the_group();
	?>

		<?php bp_nouveau_group_hook( 'before', 'home_content' ); ?>

		<?php if( sq_option( 'bp_full_group', 0 ) == 1 ) : ?>
            <section class="alternate-color bp-full-width-profile">
        <?php endif; ?>

		<div id="item-header" role="complementary" data-bp-item-id="<?php bp_group_id(); ?>" data-bp-item-component="groups" class="groups-header single-headers">

			<?php bp_nouveau_group_header_template_part(); ?>

		</div><!-- #item-header -->

		<?php if( sq_option( 'bp_full_group', 0 ) == 1 ) : ?>
            </section>
	    <?php endif; ?>

		<div class="bp-wrap">

			<?php if( sq_option( 'bp_full_group', 0 ) == 1 ) : ?>
				<?php get_template_part( 'page-parts/general-before-wrap' ); ?>
			<?php endif; ?>

			<?php if ( ! bp_nouveau_is_object_nav_in_sidebar() ) : ?>

				<?php bp_get_template_part( 'groups/single/parts/item-nav' ); ?>

			<?php endif; ?>

			<div id="item-body" class="item-body">

				<?php bp_nouveau_group_template_part(); ?>

			</div><!-- #item-body -->

			<?php if( sq_option( 'bp_full_group', 0 ) == 1 ) : ?>
				<?php get_template_part( 'page-parts/general-after-wrap' ); ?>
			<?php endif; ?>

		</div><!-- // .bp-wrap -->

		<?php bp_nouveau_group_hook( 'after', 'home_content' ); ?>

	<?php endwhile; ?>

<?php
endif;
