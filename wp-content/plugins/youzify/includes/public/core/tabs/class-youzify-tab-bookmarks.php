<?php

class Youzify_Bookmarks_Tab {

    function __construct() {

        // add_action( 'bp_setup_nav', array( $this, 'add_bookmark_subtabs' ) );
        $this->add_bookmark_subtabs();
        add_filter( 'youzify_is_current_tab_has_children', '__return_false' );

    }

    /**
     * Tab.
     */
    function tab() {

        // Include Wall Files.
        require_once YOUZIFY_CORE . 'functions/wall/youzify-wall-general-functions.php';
        require_once YOUZIFY_CORE . 'class-youzify-wall.php';

        do_action( 'bp_bookmarks_screen' );

        bp_get_template_part( 'members/single/bookmarks' );

    }

    /**
     * Setup Tabs.
     */
    function add_bookmark_subtabs() {

        // Add Activities Sub Tab.
        bp_core_new_subnav_item( array(
                'slug' => 'activities',
                'name' => __( 'Activities', 'youzify' ),
                'parent_slug' => 'bookmarks',
                'parent_url' => bp_displayed_user_url() . "bookmarks/",
                'screen_function' => 'youzify_bookmarks_screen',
            )
        );
    }

}