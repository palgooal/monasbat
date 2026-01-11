<?php

/**
 * Archive Events template (MVP)
 * Path: kleo-child/archive-event.php
 */
if (!defined('ABSPATH')) exit;

get_header();

$view = sanitize_text_field($_GET['view'] ?? 'upcoming'); // upcoming | past | all
$today = current_time('Y-m-d');

$args = [
    'post_type' => 'event',
    'posts_per_page' => 12,
    'paged' => max(1, (int) get_query_var('paged')),
    'meta_key' => '_mon_event_date',
    'orderby'  => 'meta_value',
    'order'    => 'ASC',
];

$meta_query = [];

if ($view === 'upcoming') {
    $meta_query[] = [
        'key'     => '_mon_event_date',
        'value'   => $today,
        'compare' => '>=',
        'type'    => 'DATE',
    ];
    $args['order'] = 'ASC';
} elseif ($view === 'past') {
    $meta_query[] = [
        'key'     => '_mon_event_date',
        'value'   => $today,
        'compare' => '<',
        'type'    => 'DATE',
    ];
    $args['order'] = 'DESC';
}

if ($meta_query) {
    $args['meta_query'] = $meta_query;
}

$q = new WP_Query($args);

?>
<style>
    .mon-wrap {
        max-width: 1100px;
        margin: 0 auto;
        padding: 18px
    }

    .mon-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap
    }

    .mon-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap
    }

    .mon-tab {
        padding: 8px 12px;
        border-radius: 999px;
        border: 1px solid rgba(0, 0, 0, .08);
        text-decoration: none
    }

    .mon-tab.is-active {
        background: #111;
        color: #fff
    }

    .mon-grid {
        margin-top: 14px;
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px
    }

    @media(max-width:1000px) {
        .mon-grid {
            grid-template-columns: repeat(2, 1fr)
        }
    }

    @media(max-width:640px) {
        .mon-grid {
            grid-template-columns: 1fr
        }
    }

    .mon-card {
        border-radius: 16px;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 6px 20px rgba(0, 0, 0, .06)
    }

    .mon-thumb {
        aspect-ratio: 16/9;
        background: #111
    }

    .mon-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover
    }

    .mon-body {
        padding: 12px
    }

    .mon-title {
        margin: 0 0 6px;
        font-size: 18px
    }

    .mon-meta {
        font-size: 13px;
        color: #6b7280;
        display: flex;
        gap: 10px;
        flex-wrap: wrap
    }

    .mon-btn {
        display: inline-flex;
        margin-top: 10px;
        padding: 9px 12px;
        border-radius: 12px;
        background: #111;
        color: #fff;
        text-decoration: none
    }
</style>

<div class="mon-wrap">
    <div class="mon-head">
        <h1 style="margin:0">المناسبات</h1>

        <div class="mon-tabs">
            <?php
            $base = get_post_type_archive_link('event');
            $mk = fn($k) => esc_url(add_query_arg('view', $k, $base));
            ?>
            <a class="mon-tab <?php echo $view === 'upcoming' ? 'is-active' : ''; ?>" href="<?php echo $mk('upcoming'); ?>">القادمة</a>
            <a class="mon-tab <?php echo $view === 'past' ? 'is-active' : ''; ?>" href="<?php echo $mk('past'); ?>">السابقة</a>
            <a class="mon-tab <?php echo $view === 'all' ? 'is-active' : ''; ?>" href="<?php echo $mk('all'); ?>">الكل</a>
        </div>
    </div>

    <?php if ($q->have_posts()): ?>
        <div class="mon-grid">
            <?php while ($q->have_posts()): $q->the_post();
                $id = get_the_ID();
                $date = get_post_meta($id, '_mon_event_date', true);
                $time = get_post_meta($id, '_mon_event_time', true);
                $location = get_post_meta($id, '_mon_event_location', true);
            ?>
                <article class="mon-card">
                    <div class="mon-thumb">
                        <?php if (has_post_thumbnail()): the_post_thumbnail('large');
                        endif; ?>
                    </div>
                    <div class="mon-body">
                        <h3 class="mon-title"><?php the_title(); ?></h3>
                        <div class="mon-meta">
                            <?php if ($date): ?><span>📅 <?php echo esc_html($date); ?></span><?php endif; ?>
                            <?php if ($time): ?><span>⏰ <?php echo esc_html($time); ?></span><?php endif; ?>
                            <?php if ($location): ?><span>📍 <?php echo esc_html($location); ?></span><?php endif; ?>
                        </div>
                        <a class="mon-btn" href="<?php the_permalink(); ?>">عرض الدعوة</a>
                    </div>
                </article>
            <?php endwhile;
            wp_reset_postdata(); ?>
        </div>

        <div style="margin-top:18px">
            <?php
            echo paginate_links([
                'total' => $q->max_num_pages,
                'current' => max(1, (int) get_query_var('paged')),
            ]);
            ?>
        </div>
    <?php else: ?>
        <p style="margin-top:14px;color:#6b7280">لا توجد مناسبات لعرضها.</p>
    <?php endif; ?>
</div>

<?php get_footer(); ?>