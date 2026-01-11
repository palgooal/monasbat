<?php

/**
 * Search
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( bbp_allow_search() ) : ?>

    <div class="bbp-search-form">
        <form role="search" method="get" id="bbp-search-form" action="<?php bbp_search_url(); ?>">
            <div class="input-group">
                <label class="screen-reader-text hidden" for="bbp_search"><?php _e( 'Search for:', 'bbpress' ); ?></label>
                <!--<input type="hidden" name="action" value="bbp-search-request" />-->
                <input type="text" value="<?php bbp_search_terms(); ?>" name="bbp_search" id="bbp_search"/>
                <span class="input-group-btn">
            <input class="button" type="submit" id="bbp_search_submit" value="<?php esc_attr_e( 'Search', 'bbpress' ); ?>"/>
        </span>
            </div>

			<?php
			$forum_id = bbp_get_forum_id();
			if ( $forum_id ): ?>
                <input type="hidden" name="bbp_search_forum_id" value="<?php echo esc_attr( $forum_id ); ?>"/>
			<?php endif; ?>
        </form>
    </div>

<?php endif;
