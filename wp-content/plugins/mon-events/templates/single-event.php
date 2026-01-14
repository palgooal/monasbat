<?php
// templates/single-event.php
if (!defined('ABSPATH')) exit;

get_header();

while (have_posts()) : the_post();

    $event_id  = (int) get_the_ID();

    $date      = (string) get_post_meta($event_id, '_mon_event_date', true);
    $time      = (string) get_post_meta($event_id, '_mon_event_time', true);
    $location  = (string) get_post_meta($event_id, '_mon_event_location', true);
    $maps      = (string) get_post_meta($event_id, '_mon_event_maps', true);

    $hide_gallery  = ((int) get_post_meta($event_id, '_mon_hide_gallery', true) === 1);
    $hide_visitors = ((int) get_post_meta($event_id, '_mon_hide_visitors', true) === 1);

    // Gate (safe)
    $plugin  = function_exists('mon_events_mvp') ? mon_events_mvp() : null;
    $gate_ok = ($plugin && method_exists($plugin, 'gate_passed')) ? (bool) $plugin->gate_passed($event_id) : false;

    // Gallery ids (لو عندك طريقة أخرى لتخزين الصور غير _mon_gallery_ids عدّلها هنا)
    $gallery_ids = get_post_meta($event_id, '_mon_gallery_ids', true);
    if (!is_array($gallery_ids)) $gallery_ids = [];

    // B: الألبوم للمدعوين فقط بعد التحقق
    $can_see_gallery = is_user_logged_in() || $gate_ok;

    // Featured image
    $cover = get_the_post_thumbnail_url($event_id, 'full');

    // Build countdown timestamp (if date exists)
    $event_ts = 0;
    if ($date) {
        $ts = strtotime($date . ($time ? " $time" : " 23:59"));
        $event_ts = $ts ? (int) $ts : 0;
    }

    // Visitors (اختياري - إذا عندك نظام زيارات لاحقًا)
    // $visitors = (int) get_post_meta($event_id, '_mon_visitors', true);

?>
    <div class="mon-page">

        <section class="mon-hero">
            <div class="mon-hero__bg"></div>

            <div class="mon-container">
                <div class="mon-hero__grid">

                    <div class="mon-hero__main">
                        <div class="mon-breadcrumb">
                            <span>المناسبات</span>
                            <span class="mon-dot">•</span>
                            <span class="mon-dim"><?php echo esc_html(get_the_title()); ?></span>
                        </div>

                        <h1 class="mon-title"><?php the_title(); ?></h1>

                        <div class="mon-meta">
                            <?php if ($date): ?>
                                <span class="mon-pill"><span class="mon-ico">📅</span><?php echo esc_html($date); ?></span>
                            <?php endif; ?>
                            <?php if ($time): ?>
                                <span class="mon-pill"><span class="mon-ico">⏰</span><?php echo esc_html($time); ?></span>
                            <?php endif; ?>
                            <?php if ($location): ?>
                                <span class="mon-pill"><span class="mon-ico">📍</span><?php echo esc_html($location); ?></span>
                            <?php endif; ?>
                            <?php if (!$hide_visitors): ?>
                                <span class="mon-pill mon-pill--ghost"><span class="mon-ico">👀</span>مشاهدات قريبًا</span>
                            <?php endif; ?>
                        </div>

                        <div class="mon-actions">
                            <?php if ($maps): ?>
                                <a class="mon-btn mon-btn--primary" target="_blank" rel="noopener" href="<?php echo esc_url($maps); ?>">
                                    فتح الخريطة
                                </a>
                            <?php endif; ?>

                            <a class="mon-btn mon-btn--soft" href="#rsvp">تأكيد الحضور</a>
                            <a class="mon-btn mon-btn--ghost" href="#comments">التعليقات</a>
                        </div>

                        <?php if ($event_ts > 0): ?>
                            <div class="mon-countdown" data-role="countdown" data-ts="<?php echo esc_attr($event_ts); ?>">
                                <div class="mon-countdown__label">متبقي على المناسبة</div>
                                <div class="mon-countdown__value" data-role="countdownValue">—</div>
                            </div>
                        <?php endif; ?>

                    </div>

                    <div class="mon-hero__side">
                        <div class="mon-cover">
                            <?php if ($cover): ?>
                                <img class="mon-cover__img" src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr(get_the_title()); ?>">
                            <?php else: ?>
                                <div class="mon-cover__placeholder">
                                    <div class="mon-cover__badge">Mon Events</div>
                                    <div class="mon-cover__text">أضف صورة مميزة للمناسبة لعرضها هنا</div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mon-mini">
                            <div class="mon-mini__row">
                                <div class="mon-mini__k">حالة الدعوة</div>
                                <div class="mon-mini__v">
                                    <?php if (is_user_logged_in() || $gate_ok): ?>
                                        <span class="mon-badge mon-badge--ok">تم التحقق</span>
                                    <?php else: ?>
                                        <span class="mon-badge mon-badge--warn">غير متحقق</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mon-mini__row">
                                <div class="mon-mini__k">الألبوم</div>
                                <div class="mon-mini__v">
                                    <?php if ($hide_gallery): ?>
                                        <span class="mon-badge">مخفي</span>
                                    <?php elseif ($can_see_gallery): ?>
                                        <span class="mon-badge mon-badge--ok">متاح</span>
                                    <?php else: ?>
                                        <span class="mon-badge mon-badge--warn">للمدعوين فقط</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!$can_see_gallery && !$hide_gallery): ?>
                                <div class="mon-mini__gate">
                                    <div class="mon-dim" style="margin-bottom:8px;">للوصول للألبوم والتعليقات:</div>
                                    <?php echo do_shortcode('[mon_event_gate]'); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>

                </div>
            </div>
        </section>

        <main class="mon-container mon-layout">

            <div class="mon-col">

                <section class="mon-card mon-card--about" id="about">
                    <div class="mon-card__head">
                        <h2 class="mon-h2">عن المناسبة</h2>
                        <div class="mon-sub">تفاصيل مختصرة ومحتوى المناسبة</div>
                    </div>

                    <div class="mon-content">
                        <?php the_content(); ?>
                    </div>
                </section>

                <section class="mon-card mon-card--gallery" id="gallery">
                    <div class="mon-card__head">
                        <h2 class="mon-h2">ألبوم الصور</h2>
                        <div class="mon-sub">صور خاصة بالمناسبة</div>
                    </div>

                    <?php if ($hide_gallery): ?>
                        <p class="mon-muted">تم إخفاء الألبوم من إعدادات المناسبة.</p>

                    <?php elseif (!$can_see_gallery): ?>
                        <p class="mon-muted">الألبوم متاح فقط للمدعوين بعد التحقق من الدعوة.</p>
                        <?php echo do_shortcode('[mon_event_gate]'); ?>

                    <?php elseif (empty($gallery_ids)): ?>
                        <p class="mon-muted">لا توجد صور بعد.</p>

                    <?php else: ?>
                        <div class="mon-gallery">
                            <?php foreach ($gallery_ids as $img_id):
                                $img_id = (int) $img_id;
                                $full = wp_get_attachment_image_url($img_id, 'full');
                                if (!$full) continue;
                            ?>
                                <a class="mon-gallery__item" href="<?php echo esc_url($full); ?>" target="_blank" rel="noopener">
                                    <?php echo wp_get_attachment_image($img_id, 'large'); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="mon-card mon-card--rsvp" id="rsvp">
                    <div class="mon-card__head">
                        <h2 class="mon-h2">تأكيد الحضور</h2>
                        <div class="mon-sub">اختر حالتك واحفظ الرد</div>
                    </div>

                    <?php echo do_shortcode('[mon_event_rsvp]'); ?>
                </section>

                <section class="mon-card mon-card--comments" id="comments">
                    <div class="mon-card__head">
                        <h2 class="mon-h2">التعليقات</h2>
                        <div class="mon-sub">نقاش خاص بالمدعوين</div>
                    </div>

                    <?php if (!$gate_ok && !is_user_logged_in()): ?>
                        <p class="mon-muted">التعليقات متاحة فقط لمن تم التحقق من دعوتهم.</p>
                        <?php echo do_shortcode('[mon_event_gate]'); ?>
                    <?php else: ?>
                        <?php comments_template(); ?>
                    <?php endif; ?>
                </section>

            </div>

            <aside class="mon-aside">
                <div class="mon-card mon-card--aside">
                    <h3 class="mon-h3">معلومات سريعة</h3>

                    <div class="mon-info">
                        <?php if ($date): ?>
                            <div class="mon-info__row"><span>التاريخ</span><b><?php echo esc_html($date); ?></b></div>
                        <?php endif; ?>
                        <?php if ($time): ?>
                            <div class="mon-info__row"><span>الوقت</span><b><?php echo esc_html($time); ?></b></div>
                        <?php endif; ?>
                        <?php if ($location): ?>
                            <div class="mon-info__row"><span>المكان</span><b><?php echo esc_html($location); ?></b></div>
                        <?php endif; ?>
                    </div>

                    <div class="mon-aside__cta">
                        <a class="mon-btn mon-btn--primary mon-btn--block" href="#rsvp">اذهب لتأكيد الحضور</a>
                        <?php if ($maps): ?>
                            <a class="mon-btn mon-btn--soft mon-btn--block" target="_blank" rel="noopener" href="<?php echo esc_url($maps); ?>">فتح الخريطة</a>
                        <?php endif; ?>
                    </div>
                </div>
            </aside>

        </main>

    </div>
<?php
endwhile;

get_footer();
