<?php

/**
 * Single Event template (MVP)
 * Path: kleo-child/single-event.php
 *
 * الهدف:
 * - صفحة الدعوة لا تظهر إلا للمدعوين.
 * - التحقق عبر رقم الجوال الموجود في قائمة المدعوين داخل المناسبة (meta: _mon_invited_phones).
 * - بعد التحقق نخزّن Cookie موقّع ليتذكر جهازه (30 يوم).
 * - RSVP والتعليقات لا تظهر/لا تُستخدم إلا بعد اجتياز الـ Gate.
 */

if (!defined('ABSPATH')) exit;

get_header();

while (have_posts()) : the_post();

    $event_id = get_the_ID();

    // =========================================================
    // Helpers (MVP) - Phone matching without assuming one country
    // =========================================================

    if (!function_exists('mon_phone_candidates_mvp')) {
        /**
         * Return candidate variants for a phone number (digits-only).
         * يدعم صيغ متعددة بدون افتراض كود دولة واحد.
         *
         * Examples:
         * - 05xxxxxxxx  => [05xxxxxxxx, 5xxxxxxxx, 9665xxxxxxxx, 9705xxxxxxxx]
         * - 9665xxxxxxx => [9665xxxxxxx, 05xxxxxxx, 5xxxxxxx]
         * - 97059...... => [97059......, 059......, 59......]
         * - 00970.....  => [970....., 0....., .....]
         */
        function mon_phone_candidates_mvp($raw)
        {
            $d = preg_replace('/\D+/', '', (string) $raw);
            if (!$d) return [];

            // 00XXXXXXXX => XXXXXXXX
            if (substr($d, 0, 2) === '00') {
                $d = substr($d, 2);
            }

            $set = [];

            $add = function ($v) use (&$set) {
                $v = preg_replace('/\D+/', '', (string) $v);
                if ($v !== '') $set[$v] = true;
            };

            // As-is
            $add($d);

            // If starts with common CC (966/970), also add local forms
            if (substr($d, 0, 3) === '970' || substr($d, 0, 3) === '966') {
                $local0 = '0' . substr($d, 3); // 9705.. => 05..
                $add($local0);
                if (substr($local0, 0, 1) === '0') {
                    $add(substr($local0, 1)); // 05.. => 5..
                }
            }

            // If starts with 0, add without 0 + add with common CCs
            if (substr($d, 0, 1) === '0' && strlen($d) >= 8) {
                $no0 = substr($d, 1);
                $add($no0);
                $add('966' . $no0);
                $add('970' . $no0);
            }

            // If starts with 5, add with CCs
            if (substr($d, 0, 1) === '5' && strlen($d) >= 8) {
                $add('966' . $d);
                $add('970' . $d);
            }

            return array_keys($set);
        }
    }

    if (!function_exists('mon_get_invited_set_mvp')) {
        /**
         * Build invited SET from meta _mon_invited_phones.
         * Returns associative set: ['9665...' => true, '059...' => true, ...]
         * بحيث أي صيغة مكافئة للرقم تعتبر "مدعو".
         */
        function mon_get_invited_set_mvp($event_id)
        {
            $raw = (string) get_post_meta($event_id, '_mon_invited_phones', true);
            if (!$raw) return [];

            $parts = preg_split('/[\r\n,]+/', $raw);
            $set   = [];

            foreach ($parts as $p) {
                $p = trim((string) $p);
                if ($p === '') continue;

                foreach (mon_phone_candidates_mvp($p) as $cand) {
                    $set[$cand] = true;
                }
            }

            return $set;
        }
    }

    if (!function_exists('mon_match_invited_phone_mvp')) {
        /**
         * Try matching input phone against invited set.
         * Returns matched candidate value (string) or '' if no match.
         */
        function mon_match_invited_phone_mvp(array $invited_set, $input_raw)
        {
            foreach (mon_phone_candidates_mvp($input_raw) as $cand) {
                if (!empty($invited_set[$cand])) return $cand;
            }
            return '';
        }
    }

    if (!function_exists('mon_make_cookie_value_mvp')) {
        /**
         * Create signed cookie value: event_id|phone|sig
         */
        function mon_make_cookie_value_mvp($event_id, $phone_norm)
        {
            $payload  = $event_id . '|' . $phone_norm;
            $sig      = hash_hmac('sha256', $payload, wp_salt('auth'));
            return base64_encode($payload . '|' . $sig);
        }
    }

    if (!function_exists('mon_verify_cookie_value_mvp')) {
        /**
         * Verify signed cookie value.
         * Returns [ok(bool), event_id(int), phone(string)]
         */
        function mon_verify_cookie_value_mvp($cookie_value)
        {
            $decoded = base64_decode((string) $cookie_value, true);
            if (!$decoded) return [false, 0, ''];

            $parts = explode('|', $decoded);
            if (count($parts) !== 3) return [false, 0, ''];

            [$cid, $cphone, $sig] = $parts;
            $cid = (int) $cid;

            if ($cid <= 0 || !$cphone || !$sig) return [false, 0, ''];

            $payload  = $cid . '|' . $cphone;
            $expected = hash_hmac('sha256', $payload, wp_salt('auth'));

            if (!hash_equals($expected, $sig)) return [false, 0, ''];

            return [true, $cid, (string) $cphone];
        }
    }

    // =========================================================
    // Gate Logic
    // =========================================================

    $cookie_name  = 'mon_inv_' . $event_id;
    $is_allowed   = false;
    $gate_error   = '';

    $author_id = (int) get_post_field('post_author', $event_id);

    // استثناءات: صاحب المناسبة أو مدير/محرر (حتى لا ينقفل عليهم)
    $is_host_or_admin = is_user_logged_in() && (
        get_current_user_id() === $author_id ||
        current_user_can('edit_post', $event_id) ||
        current_user_can('manage_options')
    );

    // ✅ Build invited set (مرن للصيغ المختلفة)
    $invited_set = mon_get_invited_set_mvp($event_id);

    if ($is_host_or_admin) {
        $is_allowed = true;
    } else {

        // (1) Cookie check: إذا سبق التحقق
        if (!empty($_COOKIE[$cookie_name])) {
            [$ok, $cid, $cphone] = mon_verify_cookie_value_mvp($_COOKIE[$cookie_name]);
            if ($ok && $cid === (int) $event_id && !empty($invited_set[$cphone])) {
                $is_allowed = true;
            }
        }

        // (2) Phone submit check
        if (!$is_allowed && isset($_POST['mon_invite_submit'])) {

            // Nonce protection
            if (!isset($_POST['mon_invite_nonce']) || !wp_verify_nonce($_POST['mon_invite_nonce'], 'mon_invite_gate')) {
                $gate_error = 'انتهت صلاحية الجلسة، حاول مرة أخرى.';
            } else {
                $phone_input = sanitize_text_field($_POST['mon_invite_phone'] ?? '');
                $digits_only = preg_replace('/\D+/', '', (string) $phone_input);
                $matched     = mon_match_invited_phone_mvp($invited_set, $phone_input);

                if (!$digits_only) {
                    $gate_error = 'الرجاء إدخال رقم جوال صحيح.';
                } elseif (!$matched) {
                    $gate_error = 'عذرًا، هذا الرقم غير موجود ضمن قائمة المدعوين.';
                } else {
                    // ✅ Allowed: set signed cookie for 30 days
                    // نخزن الرقم بصيغته المطابقة داخل الـ set لضمان تحقق ثابت لاحقًا
                    $cookie_val = mon_make_cookie_value_mvp($event_id, $matched);
                    setcookie($cookie_name, $cookie_val, [
                        'expires'  => time() + (30 * DAY_IN_SECONDS),
                        'path'     => '/',
                        'secure'   => is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);

                    // redirect to avoid resubmission
                    wp_safe_redirect(get_permalink($event_id));
                    exit;
                }
            }
        }

        // (3) Not allowed: show gate UI and stop rendering the event
        if (!$is_allowed) : ?>
            <div style="max-width:560px;margin:40px auto;padding:18px;background:#fff;border-radius:16px;box-shadow:0 6px 20px rgba(0,0,0,.06)">
                <h2 style="margin:0 0 10px">الدعوة خاصة بالمدعوين</h2>
                <p style="margin:0 0 14px;color:#6b7280">
                    الرجاء إدخال رقم الجوال المدرج في قائمة المدعوين لعرض تفاصيل المناسبة.
                </p>

                <?php if ($gate_error): ?>
                    <div style="margin:0 0 12px;padding:10px 12px;border-radius:12px;background:#fee2e2;color:#991b1b">
                        <?php echo esc_html($gate_error); ?>
                    </div>
                <?php endif; ?>

                <form method="post" style="display:flex;gap:10px;align-items:center">
                    <?php wp_nonce_field('mon_invite_gate', 'mon_invite_nonce'); ?>
                    <input type="text" name="mon_invite_phone" required
                        placeholder="مثال: 05xxxxxxxx أو 9665xxxxxxxx أو 9705xxxxxxxx"
                        style="flex:1;padding:12px;border:1px solid #e5e7eb;border-radius:12px;direction:ltr">
                    <button type="submit" name="mon_invite_submit" value="1"
                        style="padding:12px 14px;border-radius:12px;border:0;background:#111;color:#fff;cursor:pointer">
                        تحقق
                    </button>
                </form>

                <p style="margin:12px 0 0;color:#9ca3af;font-size:12px">
                    * بعد التحقق سيتم تذكر جهازك تلقائيًا لمدة 30 يوم.
                </p>
            </div>
    <?php
            get_footer();
            exit;
        endif;
    }

    // =========================================================
    // ✅ Allowed: show full event (RSVP + comments now allowed)
    // =========================================================

    $type      = wp_get_post_terms($event_id, 'event_type', ['fields' => 'names']);
    $type_text = !empty($type) ? implode('، ', $type) : '';

    $date      = get_post_meta($event_id, '_mon_event_date', true);
    $time      = get_post_meta($event_id, '_mon_event_time', true);
    $location  = get_post_meta($event_id, '_mon_event_location', true);
    $maps      = get_post_meta($event_id, '_mon_event_maps', true);

    $hide_visitors        = (int) get_post_meta($event_id, '_mon_hide_visitors', true);
    $hide_gallery         = (int) get_post_meta($event_id, '_mon_hide_gallery', true);
    $hide_public_comments = (int) get_post_meta($event_id, '_mon_hide_public_comments', true);
    $close_comments_after = (int) get_post_meta($event_id, '_mon_close_comments_after', true);

    $cover       = get_the_post_thumbnail_url($event_id, 'full');
    $author_name = $author_id ? get_the_author_meta('display_name', $author_id) : '';
    $author_link = $author_id ? get_author_posts_url($author_id) : '#';

    // Visitors Count (MVP)
    $views_key = '_mon_event_views';
    $views = (int) get_post_meta($event_id, $views_key, true);
    if (!is_user_logged_in() || (is_user_logged_in() && get_current_user_id() !== $author_id)) {
        update_post_meta($event_id, $views_key, $views + 1);
        $views++;
    }
    ?>

    <style>
        .mon-wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 18px;
        }

        .mon-hero {
            border-radius: 18px;
            overflow: hidden;
            position: relative;
            min-height: 280px;
            background: #111;
            color: #fff;
        }

        .mon-hero__bg {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            filter: brightness(.65);
        }

        .mon-hero__overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(0, 0, 0, .25), rgba(0, 0, 0, .75));
        }

        .mon-hero__content {
            position: relative;
            z-index: 2;
            padding: 22px;
        }

        .mon-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .12);
            backdrop-filter: blur(6px);
            font-size: 13px;
        }

        .mon-title {
            margin: 12px 0 6px;
            font-size: 30px;
            line-height: 1.2;
        }

        .mon-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 14px;
            opacity: .95;
        }

        .mon-meta span {
            display: inline-flex;
            gap: 6px;
            align-items: center;
        }

        .mon-card {
            margin-top: 16px;
            border-radius: 16px;
            background: #fff;
            padding: 16px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .06);
        }

        body.dark .mon-card {
            background: #0b1220;
            color: #e5e7eb;
        }

        .mon-grid {
            display: grid;
            grid-template-columns: 1.2fr .8fr;
            gap: 16px;
        }

        @media (max-width:900px) {
            .mon-grid {
                grid-template-columns: 1fr;
            }
        }

        .mon-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .mon-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 12px;
            background: #111;
            color: #fff;
            text-decoration: none;
        }

        .mon-muted {
            color: #6b7280;
            font-size: 13px;
        }

        .mon-sep {
            height: 1px;
            background: rgba(0, 0, 0, .06);
            margin: 12px 0;
        }
    </style>

    <div class="mon-wrap">
        <header class="mon-hero">
            <div class="mon-hero__bg" style="background-image:url('<?php echo esc_url($cover ?: ''); ?>')"></div>
            <div class="mon-hero__overlay"></div>
            <div class="mon-hero__content">
                <?php if ($type_text): ?>
                    <span class="mon-badge"><?php echo esc_html($type_text); ?></span>
                <?php endif; ?>

                <h1 class="mon-title"><?php the_title(); ?></h1>

                <div class="mon-meta">
                    <?php if ($author_name): ?>
                        <span>👤 <a href="<?php echo esc_url($author_link); ?>" style="color:#fff;text-decoration:underline;"><?php echo esc_html($author_name); ?></a></span>
                    <?php endif; ?>
                    <?php if ($date): ?><span>📅 <?php echo esc_html($date); ?></span><?php endif; ?>
                    <?php if ($time): ?><span>⏰ <?php echo esc_html($time); ?></span><?php endif; ?>
                    <?php if ($location): ?><span>📍 <?php echo esc_html($location); ?></span><?php endif; ?>
                    <?php if (!$hide_visitors): ?><span>👁️ <?php echo esc_html($views); ?></span><?php endif; ?>
                </div>

                <div class="mon-row" style="margin-top:14px">
                    <?php if ($maps): ?>
                        <a class="mon-btn" href="<?php echo esc_url($maps); ?>" target="_blank" rel="noopener">🗺️ فتح الخريطة</a>
                    <?php endif; ?>
                    <a class="mon-btn" href="<?php echo esc_url('https://wa.me/?text=' . rawurlencode(get_permalink($event_id))); ?>" target="_blank" rel="noopener">💬 مشاركة واتساب</a>
                </div>
            </div>
        </header>

        <div class="mon-grid">
            <main class="mon-card">
                <?php if (get_the_content()): ?>
                    <div class="mon-content">
                        <?php
                        /**
                         * محتوى Elementor + shortcode [mon_event_rsvp]
                         * الآن مسموح فقط لأننا اجتزنا الـ Gate.
                         */
                        the_content();
                        ?>
                    </div>
                <?php else: ?>
                    <p class="mon-muted">ضع محتوى الدعوة داخل Elementor وأضف شورت كود RSVP.</p>
                <?php endif; ?>

                <div class="mon-sep"></div>

                <?php
                /**
                 * التعليقات:
                 * - لا تظهر إلا بعد Gate (نحن هنا بالفعل بعد Gate)
                 * - وتحترم إعدادات الإضافة: hide_public_comments + close_comments_after
                 */
                if ($hide_public_comments) {
                    echo '<p class="mon-muted">التعليقات العامة مخفية من إعدادات المناسبة.</p>';
                } else {

                    if (!is_user_logged_in() && empty($is_allowed)) {
                        echo '<p class="mon-muted">لإضافة تعليق، الرجاء <a href="' . esc_url(wp_login_url(get_permalink($event_id))) . '">تسجيل الدخول</a>.</p>';
                    }

                    $is_past = false;
                    if ($close_comments_after && $date) {
                        $event_ts = strtotime($date . ($time ? " $time" : " 23:59"));
                        $is_past = $event_ts && time() > $event_ts;
                    }

                    if ($close_comments_after && $is_past) {
                        echo '<p class="mon-muted">تم إغلاق التعليقات بعد انتهاء المناسبة.</p>';
                    }

                    if (get_comments_number($event_id) || comments_open($event_id)) {
                        comments_template();
                    } else {
                        echo '<p class="mon-muted">لا توجد تعليقات بعد.</p>';
                    }
                }
                ?>
            </main>

            <aside class="mon-card">
                <h3 style="margin:0 0 8px">معلومات سريعة</h3>
                <p class="mon-muted" style="margin:0">
                    هذه المنطقة جاهزة لاحقًا لعرض: عدد المدعوين، حالة RSVP للمستخدم، وإعدادات الباقة.
                </p>

                <div class="mon-sep"></div>

                <?php if ($hide_gallery): ?>
                    <p class="mon-muted">ألبوم الصور مخفي من إعدادات المناسبة.</p>
                <?php else: ?>
                    <p class="mon-muted">مكان ألبوم الصور (Phase 2).</p>
                <?php endif; ?>
            </aside>
        </div>
    </div>

<?php
endwhile;

get_footer();
