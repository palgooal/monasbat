<?php
if (!defined('ABSPATH')) exit;

function pge_register_event_post_type()
{
    $labels = array(
        'name'               => 'المناسبات',
        'singular_name'      => 'مناسبة',
        'add_new'            => 'إضافة مناسبة جديدة',
        'add_new_item'       => 'إضافة تفاصيل المناسبة',
        'edit_item'          => 'تعديل المناسبة',
        'all_items'          => 'كل المناسبات',
        'menu_name'          => 'PgEvents - المناسبات'
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'has_archive'        => true,
        'show_in_menu'       => true,
        'menu_icon'          => 'dashicons-calendar-alt',
        'supports'           => array('title', 'editor', 'thumbnail'),
        'rewrite'            => array('slug' => 'event'),
        'show_in_rest'       => true,
    );
    register_post_type('pge_event', $args);
}
add_action('init', 'pge_register_event_post_type');
