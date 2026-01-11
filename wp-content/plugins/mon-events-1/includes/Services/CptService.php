<?php

namespace MonEvents\Services;

if (!defined('ABSPATH')) exit;

class CptService
{
    public function register(): void
    {
        add_action('init', [$this, 'register_cpt_tax'], 0);
    }

    public function register_cpt_tax(): void
    {
        register_post_type('event', [
            'labels' => [
                'name' => 'المناسبات',
                'singular_name' => 'مناسبة',
                'add_new' => 'إضافة مناسبة',
                'add_new_item' => 'إضافة مناسبة جديدة',
                'edit_item' => 'تعديل المناسبة',
                'new_item' => 'مناسبة جديدة',
                'view_item' => 'عرض المناسبة',
                'search_items' => 'بحث في المناسبات',
            ],
            'public' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => 'events'],
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title', 'editor', 'thumbnail', 'author', 'comments'],
            'show_in_rest' => true,
        ]);

        register_taxonomy('event_type', ['event'], [
            'labels' => [
                'name' => 'نوع المناسبة',
                'singular_name' => 'نوع المناسبة',
            ],
            'public' => true,
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'event-type'],
        ]);
    }
}
