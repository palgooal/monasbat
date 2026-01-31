<?php
if (!defined('ABSPATH')) exit;

function pge_create_rsvp_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'pge_rsvp';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        event_id bigint(20) NOT NULL,
        guest_name varchar(100) NOT NULL,
        status varchar(20) NOT NULL,
        plus_ones int(11) DEFAULT 0,
        notes text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(PGE_PATH . 'pgevents-core.php', 'pge_create_rsvp_table');
