<?php
/**
 * BuddyPress - Members Home
 *
 * @since   1.0.0
 * @version 3.0.0
 */
?>

	<?php bp_nouveau_member_hook( 'before', 'home_content' ); ?>

        <?php if( sq_option( 'bp_full_profile', 0 ) == 1 ) : ?>
            <section class="alternate-color bp-full-width-profile">
        <?php endif; ?>

        <div id="item-header" role="complementary" data-bp-item-id="<?php echo esc_attr( bp_displayed_user_id() ); ?>" data-bp-item-component="members" class="users-header single-headers">

            <?php bp_nouveau_member_header_template_part(); ?>

        </div><!-- #item-header -->

        <?php if( sq_option( 'bp_full_profile', 0 ) == 1 ) : ?>
            </section>
        <?php endif; ?>

	<div class="bp-wrap">

		<?php if( sq_option( 'bp_full_profile', 0 ) == 1 ) : ?>
			<?php get_template_part( 'page-parts/general-before-wrap' ); ?>
		<?php endif; ?>

        <?php if ( ! bp_nouveau_is_object_nav_in_sidebar() ) : ?>

			<?php bp_get_template_part( 'members/single/parts/item-nav' ); ?>

		<?php endif; ?>

		<div id="item-body" class="item-body">

			<?php bp_nouveau_member_template_part(); ?>

		</div><!-- #item-body -->

		<?php if( sq_option( 'bp_full_profile', 0 ) == 1 ) : ?>
		    <?php get_template_part( 'page-parts/general-after-wrap' ); ?>
		<?php endif; ?>

	</div><!-- // .bp-wrap -->

	<?php bp_nouveau_member_hook( 'after', 'home_content' ); ?>
