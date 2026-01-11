<?php
add_filter( 'kleo_dynamic_main', 'kleo_buddypress_nouveau_dynamic_style', 12, 2 );

function kleo_buddypress_nouveau_dynamic_style( $content, $section ) {
	
	$name = 'main';
	ob_start();
	
	?>
	#buddypress div.item-list-tabs a,
	#buddypress div.activity-comments form textarea,
	#buddypress div#item-nav ul li a:hover:before,
	#buddypress button,
	#buddypress a.button,
	#buddypress input[type=submit],
	#buddypress input[type=button],
	#buddypress input[type=reset],
	#buddypress ul.button-nav li a,
	#buddypress .generic-button a,
	.bp-full-width-profile .generic-button a,
	#buddypress .portfolio-filter-tabs li a,
	.profile-cover-action a.button:hover,
	.profile-cover-action a.button:before,
    .main-navs > ul > li > a,
    .main-navs > ul > li > a:hover:before,
    .main-navs > ul > li.selected > a:before,
    .message-lists li .thread-content a.subject,
    table#group-members-list-table .group-member-actions span a,
    .main-navs ul li.tabdrop .dropdown-menu li a,

	/* rtMedia */
	#rtMedia-queue-list .remove-from-queue,
	.rtmedia-container .rtmedia-editor-main dl.tabs dd.active > a,
	.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd.active > a,
	#buddypress div.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd.active > a {
	color: <?php echo esc_html( $section['text'] ); ?>;
	}
	
	#buddypress a.button.unfav,
	#buddypress #profile-edit-form ul.button-nav li.current a,
    #buddypress.buddypress-wrap .activity-comments .show-all button,
	.read-notifications td.notify-text a:hover,
	.unread-notifications td.notify-text a:hover {
	color: <?php echo esc_html( $section['link'] ); ?>;
	}
	
	
	/*Buddypress*/
	#buddypress li span.unread-count,
	#buddypress #groups-list .item-avatar .member-count {
	background-color: <?php echo esc_html( $section['link'] ); ?>;
	}
	
	/*Buddypress*/
	.checkbox-mark:before,
	.checkbox-cb:checked ~ .checkbox-mark,
	#buddypress #profile-edit-form ul.button-nav li.current a {
	border-color: <?php echo esc_html( $section['link'] ); ?>;
	}
	
	/* Buddypress */
	#buddypress .activity-list li.load-more a:hover,
	.manage-members .member-name:hover,
	.manage-members .member-name a:hover,
    table.notifications .notification-actions a.delete:hover,
    table.notifications .notification-actions a.mark-read:hover,
    table.notifications .notification-actions a.mark-unread:hover,
    table.notifications .notification-description a:hover,
    .message-lists li .thread-content a.subject:hover,
    .message-lists li.unread .thread-content a.subject:hover,
    .buddypress-wrap table#group-members-list-table .group-member > a:hover,
    table#group-members-list-table .group-member-actions span a:hover,
	
	/* rtMedia */
	#rtm-media-options-list ul li .rtmedia-action-buttons:hover {
	color: <?php echo esc_html( $section['link_hover'] ); ?>;
	}
	
	#buddypress input[type=submit],
	#buddypress #friend-list .friend-inner-list,
	#buddypress #member-list .member-inner-list,
	#buddypress #groups-list .group-inner-list,
	#buddypress .activity-list .activity-avatar,

    #buddypress .profile-cover-action a.button:hover,
	.profile-cover-action a.button:before,
	div#item-header .toggle-header .bp-toggle-less:hover,
	div#item-header .toggle-header .bp-toggle-more:hover,
	div#item-header .toggle-header .bp-toggle-less:before,
	div#item-header .toggle-header .bp-toggle-more:before,
	
	#buddypress .standard-form textarea,
	#buddypress .standard-form input[type=text],
	#buddypress .standard-form input[type=color],
	#buddypress .standard-form input[type=date],
	#buddypress .standard-form input[type=datetime],
	#buddypress .standard-form input[type=datetime-local],
	#buddypress .standard-form input[type=email],
	#buddypress .standard-form input[type=month],
	#buddypress .standard-form input[type=number],
	#buddypress .standard-form input[type=range],
	#buddypress .standard-form input[type=search],
	#buddypress .standard-form input[type=tel],
	#buddypress .standard-form input[type=time],
	#buddypress .standard-form input[type=url],
	#buddypress .standard-form input[type=week],
	#buddypress .standard-form select,
	#buddypress .standard-form input[type=password],
	#buddypress .dir-search input[type=search],
	#buddypress .dir-search input[type=text],
    #buddypress.buddypress-wrap .editfield input[type="text"]:focus,
    #buddypress .bp-full-width-profile .generic-button a:hover,
    .bp-overlay-menu.is-user-profile .main-navs.horizontal.tabbed-links > ul:not(.dropdown-menu) > li.current a,

	/* rtMedia */
	#rtMedia-upload-button,
	#buddypress div.rtmedia-single-container .rtmedia-single-meta button,
	.rtmedia-container .rtmedia-editor-main dl.tabs dd > a,
	.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a,
	#buddypress div.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a {
	background-color: <?php echo esc_html( $section['bg'] ); ?>;
	}
	
	<?php
	$bg_fallback = $section['bg'] == 'transparent' ? '#ffffff' : $section['bg'];
	?>
	
	#buddypress .activity-read-more a {
	background-color: <?php echo esc_html( $bg_fallback ); ?>;
	}
	
	/*Buddypress*/
	.buddypress #item-header-avatar {
	color: <?php echo esc_html( $section['bg'] ); ?>;
	}
	
	/*Buddypress*/
	#buddypress #groups-list .item-avatar .member-count,
	#buddypress li span.unread-count,
    .buddypress-wrap .grid.bp-list li .item-avatar a:after {
	border-color: <?php echo esc_html( $section['bg'] ); ?>;
	}

    .buddypress-wrap .tabbed-links:not(.vertical) ul li.current,
    .buddypress-wrap .tabbed-links:not(.vertical) ol li.current {
    border-bottom-color: <?php echo esc_html( $section['bg'] ); ?>;
    }
	
	<!--.activity-timeline,-->
	#buddypress button,
	.buddypress a.button,
	#buddypress a.button,
	#buddypress input[type=submit],
	#buddypress input[type=button],
	#buddypress input[type=reset],
	#buddypress ul.button-nav li a,
	#buddypress .generic-button a,
	.bp-full-width-profile .generic-button a,
	#buddypress .comment-reply-link,
	#buddypress #whats-new,
	#buddypress div.message-search,
	#buddypress div.dir-search,
	#buddypress .activity-read-more,
	#buddypress #friend-list .friend-inner-list,
	#buddypress #member-list .member-inner-list,
<!--	#buddypress #members-list .member-inner-list,-->
    .grid > li .list-wrap,
	#buddypress #groups-list .group-inner-list,
	#buddypress div#item-nav .tabdrop .dropdown-menu,
	#buddypress div.profile,
	#buddypress #friend-list div.action .generic-button a.send-message,
	#buddypress #member-list div.action .generic-button a.send-message,
	#buddypress #members-list div.action .generic-button a.send-message,
	#buddypress form.standard-form .left-menu img.avatar,
	#buddypress div#group-create-tabs ul li.current a,
	.rtmedia-container #rtMedia-queue-list tr td,
	
	#buddypress .standard-form textarea,
	#buddypress .standard-form input[type=text],
	#buddypress .standard-form input[type=color],
	#buddypress .standard-form input[type=date],
	#buddypress .standard-form input[type=datetime],
	#buddypress .standard-form input[type=datetime-local],
	#buddypress .standard-form input[type=email],
	#buddypress .standard-form input[type=month],
	#buddypress .standard-form input[type=number],
	#buddypress .standard-form input[type=range],
	#buddypress .standard-form input[type=search],
	#buddypress .standard-form input[type=tel],
	#buddypress .standard-form input[type=time],
	#buddypress .standard-form input[type=url],
	#buddypress .standard-form input[type=week],
	#buddypress .standard-form select,
	#buddypress .standard-form input[type=password],
	#buddypress .dir-search input[type=search],
	#buddypress .dir-search input[type=text],
    .buddypress-wrap .select-wrap,
    form.bp-dir-search-form,
    form.bp-messages-search-form,
    form[data-bp-search].bp-invites-search-form,
    form#group-members-search,

	/* rtMedia */
	.rtmedia-container .rtmedia_next_prev a,
	.rtmedia-activity-container .rtmedia_next_prev a,
	#buddypress div.rtmedia-activity-container .rtmedia_next_prev a,
	#rtm-gallery-title-container #rtm-media-options,
	#rtMedia-upload-button,
	#buddypress #item-body .rtmedia-item-comments .rt_media_comment_form textarea,
	.rtmedia-container .rtmedia-editor-main dl.tabs dd > a,
	.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a,
	#buddypress div.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a,
	.rtmedia-container .imgedit-wrap div.imgedit-settings .imgedit-group,
    .bp-single-vert-nav .item-body:not(#group-create-body),
    .bp-single-vert-nav .bp-navs.vertical ul,
    .bp-vertical-navs .main-navs.user-nav-tabs ul li.selected,
    .tabbed-links ul li.current,
    .tabbed-links ol li.current,
    .bp-navs.tabbed-links + .item-body .tabbed-links .subnav li.current,
    .main-navs.vertical + .item-body .tabbed-links .subnav li.current,
    #buddypress .rtmedia-container .media_search.media_search,
	
	#buddypress .rtmedia-container textarea,
	#buddypress .rtmedia-container input[type=text],
	#buddypress .rtmedia-container input[type=text],
	#buddypress .rtmedia-container input[type=color],
	#buddypress .rtmedia-container input[type=date],
	#buddypress .rtmedia-container input[type=datetime],
	#buddypress .rtmedia-container input[type=datetime-local],
	#buddypress .rtmedia-container input[type=email],
	#buddypress .rtmedia-container input[type=month],
	#buddypress .rtmedia-container input[type=number],
	#buddypress .rtmedia-container input[type=range],
	#buddypress .rtmedia-container input[type=search],
	#buddypress .rtmedia-container input[type=tel],
	#buddypress .rtmedia-container input[type=time],
	#buddypress .rtmedia-container input[type=url],
	#buddypress .rtmedia-container input[type=week],
	#buddypress .rtmedia-container select,
	#buddypress .rtmedia-container input[type=password] {
	border-color: <?php echo esc_html( $section['border'] ); ?>;
	}
	
	/* Buddypress */
	#buddypress div.activity-comments ul li,
	#buddypress #item-body #pag-bottom {
	border-top-color: <?php echo esc_html( $section['border'] ); ?>;
	}
	
	/* rtMedia */
	#rtMedia-queue-list {
	border-left-color: <?php echo esc_html( $section['border'] ); ?>;
	}

    .buddypress-wrap .bp-tables-user tr td.label,
    .buddypress-wrap table.wp-profile-fields tr td.label,
    .buddypress-wrap table.forum tr td.label {
    border-right-color: <?php echo esc_html( $section['border'] ); ?>;}
	
	/* Buddypress */
	#buddypress div#item-nav,
	#buddypress .activity-list .activity-content,
	#buddypress form.standard-form .left-menu #invite-list ul li,
	#buddypress form.standard-form div#invite-list ul li,
	#buddypress div.item-list-tabs#subnav,
	#buddypress div#message-thread div.message-content,
	#buddypress ul.mesage-item,
	#buddypress div.messages .pagination,
    .profile.public .profile-group-title,
    .buddypress-wrap table#group-members-list-table td,
    .membership-requests-list li,
    .buddypress-wrap .tabbed-links ul,
    .buddypress-wrap .tabbed-links ol {
	border-bottom-color: <?php echo esc_html( $section['border'] ); ?>;
	}

    .activity-item:before,
    .activity-item:after,
    .buddypress-wrap .grid.bp-list li .item-avatar a:after {
    background-color: <?php echo esc_html( $section['border'] ); ?>;
    }

	
	<!--#buddypress div.item-list-tabs ul li a span,-->
    .main-navs > ul > li .count,
	<!--#buddypress div.activity-comments form .ac-textarea,-->
	#buddypress .standard-form input[type=text]:focus,
	#buddypress table.notifications thead tr,
	#buddypress table.notifications-settings thead tr,
	#buddypress table.profile-fields thead tr,
	#buddypress table.wp-profile-fields thead tr,
	#buddypress table.messages-notices thead tr,
	#buddypress table.forum thead tr,
	#buddypress button:hover,
	#buddypress a.button:hover,
	#buddypress a.button:focus,
	#buddypress a.bp-secondary-action.view:hover,
	#buddypress input[type=submit]:hover,
	#buddypress input[type=button]:hover,
	#buddypress input[type=reset]:hover,
	#buddypress ul.button-nav li a:hover,
	#buddypress ul.button-nav li.current a,
	#buddypress .generic-button a:hover,
	.bp-full-width-profile .generic-button a:hover,
	#buddypress .comment-reply-link:hover,
    div.activity-comments form textarea,
    .bp-single-vert-nav .bp-navs.vertical li.selected a,
    .buddypress-wrap table.wp-profile-fields tbody tr,
    .buddypress-wrap table.profile-fields tbody tr,
    .buddypress-wrap table.forum tbody tr,
    table.profile-settings thead th,
    .buddypress-wrap .profile.edit .editfield,
    .bp-messages-content #bp-message-thread-list li:nth-child(2n) .message-content,
    .bp-messages-content #thread-preview h2:first-child,
    .bp-messages-content #bp-message-thread-header h2:first-child,
    .buddypress-wrap table#group-members-list-table th,
    .buddypress-wrap .main-navs:not(.dir-navs):not(.vertical):not(.tabbed-links) li.current a,
    .buddypress_object_nav .bp-navs li.selected a .count,
    .buddypress_object_nav .bp-navs li.current a .count,
    .buddypress-wrap .bp-navs li.selected a .count,
    .buddypress-wrap .bp-navs li.current a .count,
    .bp-overlay-menu.is-user-profile .main-navs.horizontal.tabbed-links > ul:not(.dropdown-menu) > li.current a .count,
    #group-create-body .group-create-buttons li.current a,

	/* rtMedia */
	.rtmedia-container .drag-drop,
	.rtmedia-activity-container .drag-drop,
	#buddypress div.rtmedia-activity-container .drag-drop,
	#buddypress #item-body .rtmedia-container ul#rtmedia_comment_ul li,
	#buddypress #item-body .rtmedia-activity-container ul#rtmedia_comment_ul li,
	.rtmedia-container .rtmedia-editor-main dl.tabs dd.active > a,
	.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd.active > a,
	#buddypress div.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd.active > a,
	.rtmedia-container .rtmedia-editor-main dl.tabs dd > a:hover,
	.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a:hover,
	#buddypress div.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a:hover,
	.rtmedia-container .imgedit-wrap div.imgedit-menu,
	.rtmedia-container .imgedit-menu div {
	background-color: <?php echo esc_html( $section['alt_bg'] ); ?>;
	}

	#buddypress .kleo-online-status,
    .bp-list li .item-avatar > a {
	border-color: <?php echo esc_html( $section['alt_bg'] ); ?>;
	}

	#buddypress div.activity-comments form .ac-textarea,
	#buddypress .standard-form input[type=text]:focus,
	/* rtMedia */
	.rtmedia-container .drag-drop,
	.rtmedia-activity-container .drag-drop,
	#buddypress div.rtmedia-activity-container .drag-drop,
	.rtmedia-container .rtmedia-editor-main dl.tabs dd.active > a,
	.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd.active > a,
	#buddypress div.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd.active > a,
	.rtmedia-container .rtmedia-editor-main dl.tabs dd > a:hover,
	.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a:hover,
	#buddypress div.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a:hover {
	border-color: <?php echo esc_html( $section['alt_border'] ); ?>;
	}
	
	#buddypress li span.unread-count,
	#buddypress #groups-list .item-avatar .member-count,
	#buddypress .generic-button .add,
	.bp-full-width-profile .generic-button .add,
	#buddypress .generic-button a.accept,
	#buddypress .generic-button .join-group,
	#buddypress .generic-button a.add:hover,
	.bp-full-width-profile .generic-button .add:hover,
	#buddypress .generic-button a.accept:hover,
	#buddypress .generic-button .join-group:hover {
	color: <?php echo esc_html( $section['high_color'] ); ?>;
	}
	
	#buddypress .generic-button .add,
    .is-user-profile div#item-header .generic-button button.add,
<!--	.bp-full-width-profile .generic-button .add,-->
	#buddypress .generic-button a.accept,
	#buddypress .generic-button .join-group,
    .buddypress-wrap .grid.bp-list li.is-online .item-avatar a:after,
	
	/* rtMedia */
	.rtm-primary-button,
	.rtmedia-container .drag-drop .start-media-upload,
	.rtmedia-activity-container .drag-drop .start-media-upload,
	#buddypress .rtmedia-container .rtmedia-uploader .drag-drop .start-media-upload {
	background-color: <?php echo esc_html( $section['high_bg'] ); ?>;
	}
	
	/* BuddyPress */
	#buddypress div#subnav.item-list-tabs ul li a.group-create {
	color: <?php echo esc_html( $section['high_bg'] ); ?>;
	}
	
	/* Buddypress */
	#buddypress .generic-button .add,
<!--	.bp-full-width-profile .add,-->
	#buddypress .generic-button a.accept,
	#buddypress .generic-button .join-group,
	#buddypress .generic-button .add:hover,
	.bp-full-width-profile .generic-button .add:hover,
	#buddypress .generic-button a.accept:hover,
	#buddypress .generic-button .join-group:hover {
	border-color: <?php echo esc_html( $section['high_bg'] ); ?>;
	}
	
	#buddypress #friend-list .item-title a,
	#buddypress #member-list h5 a,
	#buddypress #members-list .list-title a,
    #buddypress #groups-list .list-title a,
	#buddypress li.unread div.thread-info a,
	#buddypress #groups-list .item-title a,
	#buddypress button:hover,
	#buddypress a.button:hover,
	#buddypress a.button:focus,
	#buddypress input[type=submit]:hover,
	#buddypress input[type=button]:hover,
	#buddypress input[type=reset]:hover,
	#buddypress ul.button-nav li a:hover,
	#buddypress ul.button-nav li.current a,
	#buddypress .generic-button a:hover,
	.bp-full-width-profile .generic-button a:hover,
	#buddypress .comment-reply-link:hover,
	#buddypress div.item-list-tabs#subnav ul li.selected a,
	#buddypress div.item-list-tabs#subnav ul li a:hover,
	#buddypress div.item-list-tabs li.selected a,
	#buddypress div#item-nav .tabdrop .dropdown-menu li.selected a:before,
	#buddypress div#item-nav ul li.current a:before,
	#buddypress .standard-form textarea,
	#buddypress .standard-form input[type=text],
	#buddypress .standard-form input[type=color],
	#buddypress .standard-form input[type=date],
	#buddypress .standard-form input[type=datetime],
	#buddypress .standard-form input[type=datetime-local],
	#buddypress .standard-form input[type=email],
	#buddypress .standard-form input[type=month],
	#buddypress .standard-form input[type=number],
	#buddypress .standard-form input[type=range],
	#buddypress .standard-form input[type=search],
	#buddypress .standard-form input[type=tel],
	#buddypress .standard-form input[type=time],
	#buddypress .standard-form input[type=url],
	#buddypress .standard-form input[type=week],
	#buddypress .standard-form select,
	#buddypress .standard-form input[type=password],
	#buddypress .dir-search input[type=search],
	#buddypress .dir-search input[type=text],
    table.notifications .notification-description a,
    .message-lists li.unread .thread-content a.subject,
    .buddypress-wrap table#group-members-list-table .group-member > a,

	/* rtMedia */
	.rtmedia-container .rtmedia-editor-main dl.tabs dd.active > a,
	.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd.active > a,
	#buddypress div.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd.active > a,
	.rtmedia-container .rtmedia-editor-main dl.tabs dd > a:hover,
	.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a:hover,
	#buddypress div.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a:hover {
	color: <?php echo esc_html( $section['heading'] ); ?>;
	}
	
	#buddypress div#item-nav .tabdrop .dropdown-menu li a:hover:before,
	#buddypress .activity-header .time-since,
	#buddypress div#item-nav ul li a:before,
	#buddypress div#item-nav .tabdrop .dropdown-menu li a:before,
	
	#buddypress a.button.fav,
	#buddypress .comment-reply-link,
	#buddypress div#item-header div#item-meta,
	#buddypress .activity-list li.load-more a,
    .main-navs > ul > li > a:before,
    .bp-subnavs li a,
    table.notifications .notification-actions,
    table.notifications .notification-actions a.delete,
    table.notifications .notification-actions a.mark-read,
    table.notifications .notification-actions a.mark-unread,
    table#group-members-list-table .group-member-actions,

	/* rtMedia */
	.rtmedia-container .rtmedia_next_prev a,
	.rtmedia-activity-container .rtmedia_next_prev a,
	#buddypress div.rtmedia-activity-container .rtmedia_next_prev a,
	.rtmedia-container .rtmedia-editor-main dl.tabs dd > a,
	.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a,
	#buddypress div.rtmedia-activity-container .rtmedia-editor-main dl.tabs dd > a {
	color: <?php echo esc_html( $section['lighter'] ); ?>;
	}
	
	/* Buddypress */
	#buddypress #whats-new:focus {
	border-color: <?php echo esc_html( $section['lighter'] ); ?>;
	outline-color: <?php echo esc_html( $section['lighter'] ); ?>;
	}
	
	.btn-highlight:hover,
	#buddypress .generic-button .add:hover,
    .is-user-profile div#item-header .generic-button button:hover,
	.bp-full-width-profile .add:hover,
	#buddypress .generic-button a.accept:hover,
	#buddypress .generic-button .join-group:hover {
	background-color: rgba(<?php echo esc_html( $section['high_bg_rgb']['r'] ); ?>,<?php echo esc_html( $section['high_bg_rgb']['g'] ); ?>,<?php echo esc_html( $section['high_bg_rgb']['b'] ); ?>, 0.7);
	}
	
	/* rtMedia */
	#item-body .rtm-top-notch,
	#item-body .rtmedia-container.rtmedia-single-container .rtm-like-comments-info:before,
	#item-body .rtmedia-single-container.rtmedia-activity-container .rtm-like-comments-info:before {
	border-color: rgba(0,0,0,0) rgba(0,0,0,0) <?php echo esc_html( $section['alt_bg'] ); ?>;
	}
	
	<?php
	
	$output = ob_get_clean();
	
	return $content . $output;
}
