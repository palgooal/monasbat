# Palgoals Testimonials Manager

Professional WordPress plugin for managing testimonials and displaying them with Elementor or shortcodes.

## Features

- Custom Post Type for testimonials
- Secure admin meta boxes for client details, photo, rating, URL, and status
- Elementor widget with layout, visibility, query, and style controls
- Shortcode support via `[palgoals_testimonials]`
- Grid, slider, masonry, and carousel layouts
- Swiper-based slider support when Swiper is registered on the page
- Query caching with cache invalidation on testimonial updates
- Lazy loaded images and conditional asset enqueueing

## Structure

```text
palgoals-testimonials/
├── assets/
│   ├── css/
│   └── js/
├── elementor/
├── includes/
├── templates/
├── README.md
└── palgoals-testimonials.php
```

## Shortcode Examples

```text
[palgoals_testimonials]
[palgoals_testimonials layout="grid" columns="3" limit="6"]
[palgoals_testimonials layout="carousel" columns="4" rating="4" limit="8"]
```

## Elementor Widget

Search for `Palgoals Testimonials` in Elementor after activating the plugin.

## Notes

- Testimonial title is used as the client name.
- Testimonial editor content is used as the review text.
- Only testimonials with status `Active` are shown on the frontend.
