<?php

/**
 * Template Name: Dashboard - لوحة تحكم المضيف
 */

get_header(); ?>

<div class="pg-dashboard-container">
    <header class="pg-user-header">
        <div class="pg-cover" style="background-image: url('<?php echo get_user_meta(get_current_user_id(), 'pg_cover', true); ?>');">
            <div class="pg-avatar-wrapper">
                <?php echo get_avatar(get_current_user_id(), 120); ?>
                <h2>أهلاً، <?php $user = wp_get_current_user();
                            echo $user->display_name; ?></h2>
                <p class="pg-bio"><?php echo get_user_meta(get_current_user_id(), 'description', true); ?></p>
            </div>
        </div>
    </header>

    <nav class="pg-dashboard-tabs">
        <button class="tab-btn active" onclick="openTab('upcoming')">القادمة</button>
        <button class="tab-btn" onclick="openTab('current')">الحالية</button>
        <button class="tab-btn" onclick="openTab('past')">السابقة</button>
        <button class="tab-btn" onclick="openTab('guest')">حضرتها كضيف</button>
    </nav>

    <div id="upcoming" class="tab-content">
        <p>هنا تظهر مناسباتك التي لم تبدأ بعد...</p>
    </div>
</div>

<style>
    .pg-dashboard-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    .pg-user-header {
        border-radius: 15px;
        overflow: hidden;
        background: #fff;
        margin-bottom: 30px;
    }

    .pg-cover {
        height: 250px;
        background-size: cover;
        position: relative;
        background-color: #ddd;
    }

    .pg-avatar-wrapper {
        position: absolute;
        bottom: -50px;
        right: 40px;
        text-align: right;
    }

    .pg-avatar-wrapper img {
        border: 5px solid #fff;
        border-radius: 50%;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }

    .pg-dashboard-tabs {
        display: flex;
        gap: 10px;
        border-bottom: 2px solid #eee;
        margin-top: 60px;
    }

    .tab-btn {
        padding: 10px 25px;
        border: none;
        background: none;
        cursor: pointer;
        font-weight: bold;
    }

    .tab-btn.active {
        color: var(--pg-primary);
        border-bottom: 3px solid var(--pg-primary);
    }
</style>

<?php get_footer(); ?>