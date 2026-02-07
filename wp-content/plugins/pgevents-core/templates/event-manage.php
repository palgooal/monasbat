<?php
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
    auth_redirect();
}

$event_id = (int) get_query_var('event_id');

$redirect_url = add_query_arg(
    [
        'tab'   => 'operations',
        'event' => $event_id,
    ],
    home_url('/dashboard/')
);

wp_safe_redirect($redirect_url);
exit;
