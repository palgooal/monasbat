<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
    <style type="text/tailwindcss">
        /* تخصيص هوية PgEvents بألوان بال قول */
        @theme {
            --color-pg-primary: #2271b1;
            --color-pg-secondary: #ffb900;
            --color-pg-dark: #1d2327;
        }
    </style>
    <?php wp_head(); ?>
</head>

<body <?php body_class('bg-gray-50 text-right'); ?> dir="rtl">