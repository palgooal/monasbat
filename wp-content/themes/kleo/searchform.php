<form role="search" method="get" class="searchform" action="<?php echo home_url( '/' ); ?>">
	
	<div class="input-group">
		<input name="s" autocomplete="off" type="text" class="ajax_s form-control input-sm" value="<?php echo get_search_query(); ?>">
		<span class="input-group-btn">
            <input type="submit" value="<?php esc_attr_e( 'Search', 'default' );?>" class="searchsubmit button">
		</span>
	</div>

</form>