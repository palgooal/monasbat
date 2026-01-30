<?php
// includes/class-admin.php

if (!defined('ABSPATH')) exit;

class Mon_Events_Admin
{
    /** @var Mon_Events_MVP */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function register(): void
    {
        // Meta boxes
        add_action('add_meta_boxes', [$this, 'register_metaboxes']);

        // Save event meta
        add_action('save_post_event', [$this, 'save_event_meta'], 10, 2);

        // Enable file upload on edit form
        add_action('post_edit_form_tag', [$this, 'add_multipart_form_enctype']);

        // Admin pages
        add_action('admin_menu', [$this, 'register_admin_pages']);

        // Exports
        add_action('admin_post_mon_export_invites_csv', [$this, 'handle_export_invites_csv']);
        add_action('admin_post_mon_export_rsvps_csv',   [$this, 'handle_export_rsvps_csv']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_gallery_uploader_assets']);
    }

    /* --------------------------------------------------------------------------
     * Meta Boxes & Rendering (تم الإبقاء عليها كما هي)
     * -------------------------------------------------------------------------- */

    public function register_metaboxes()
    {
        add_meta_box('mon_event_details', 'إعدادات المناسبة', [$this, 'render_event_details_box'], 'event', 'normal', 'high');
        add_meta_box('mon_event_rsvps', 'تأكيدات الحضور (RSVP)', [$this, 'render_event_rsvps_box'], 'event', 'side', 'default');
        add_meta_box('mon_event_invites', 'قائمة المدعوين', [$this, 'render_event_invites_box'], 'event', 'normal', 'default');
        add_meta_box('mon_event_gallery', 'ألبوم الصور', [$this, 'render_event_gallery_box'], 'event', 'normal', 'default');
    }

    public function render_event_details_box($post)
    {
        wp_nonce_field('mon_event_save', 'mon_event_nonce');
        $date     = get_post_meta($post->ID, '_mon_event_date', true);
        $time     = get_post_meta($post->ID, '_mon_event_time', true);
        $location = get_post_meta($post->ID, '_mon_event_location', true);
        $maps     = get_post_meta($post->ID, '_mon_event_maps', true);

        $hide_gallery         = (int) get_post_meta($post->ID, '_mon_hide_gallery', true);
        $hide_visitors        = (int) get_post_meta($post->ID, '_mon_hide_visitors', true);
        $close_comments_after  = (int) get_post_meta($post->ID, '_mon_close_comments_after', true);
        $hide_public_comments  = (int) get_post_meta($post->ID, '_mon_hide_public_comments', true);
        ?>
        <div class="mon-grid">
            <div class="mon-field"><label>تاريخ المناسبة</label><input type="date" name="mon_event_date" value="<?php echo esc_attr($date); ?>"></div>
            <div class="mon-field"><label>وقت المناسبة</label><input type="time" name="mon_event_time" value="<?php echo esc_attr($time); ?>"></div>
            <div class="mon-field"><label>الموقع (نص)</label><input type="text" name="mon_event_location" value="<?php echo esc_attr($location); ?>"></div>
            <div class="mon-field"><label>رابط خرائط Google</label><input type="text" name="mon_event_maps" value="<?php echo esc_attr($maps); ?>"></div>
        </div>
        <div class="mon-toggles">
            <label><input type="checkbox" name="mon_hide_visitors" value="1" <?php checked($hide_visitors, 1); ?>> إخفاء عدد الزوار</label>
            <label><input type="checkbox" name="mon_hide_gallery" value="1" <?php checked($hide_gallery, 1); ?>> إخفاء ألبوم الصور</label>
        </div>
        <?php
    }

    public function render_event_rsvps_box($post) {
        $rsvps = get_post_meta($post->ID, '_mon_rsvp_data', true);
        $count = is_array($rsvps) ? count($rsvps) : 0;
        echo '<p>عدد الردود: <strong>' . esc_html($count) . '</strong></p>';
    }

    public function render_event_invites_box($post) {
        $raw_list = (string) get_post_meta($post->ID, '_mon_invited_phones', true);
        wp_nonce_field('mon_event_invites_save', 'mon_event_invites_nonce');
        echo '<textarea name="mon_invited_phones" rows="5" style="width:100%">' . esc_textarea($raw_list) . '</textarea>';
    }

    public function render_event_gallery_box($post): void {
        $ids = get_post_meta($post->ID, '_mon_gallery_ids', true) ?: [];
        wp_nonce_field('mon_event_gallery_save', 'mon_event_gallery_nonce');
        echo '<input type="hidden" id="mon_gallery_ids" name="mon_gallery_ids" value="'.implode(',', $ids).'">';
        echo '<button type="button" class="button" id="mon_gallery_add">إضافة صور</button>';
        echo '<div id="mon_gallery_preview" style="display:flex;gap:10px;margin-top:10px;">';
        foreach($ids as $id) { echo wp_get_attachment_image($id, 'thumbnail'); }
        echo '</div>';
    }

    public function save_event_meta($post_id, $post)
    {
        if (!isset($_POST['mon_event_nonce']) || !wp_verify_nonce($_POST['mon_event_nonce'], 'mon_event_save')) return;
        update_post_meta($post_id, '_mon_event_date', sanitize_text_field($_POST['mon_event_date']));
        update_post_meta($post_id, '_mon_event_time', sanitize_text_field($_POST['mon_event_time']));
        
        if (isset($_POST['mon_gallery_ids'])) {
            $ids = array_filter(array_map('intval', explode(',', $_POST['mon_gallery_ids'])));
            update_post_meta($post_id, '_mon_gallery_ids', $ids);
        }
    }

    /* --------------------------------------------------------------------------
     * Admin Pages & Salla Settings (الجزء الجديد المصحح)
     * -------------------------------------------------------------------------- */

    public function register_admin_pages()
    {
        // صفحة إدارة المدعوين
        add_submenu_page(
            'edit.php?post_type=event',
            'إدارة المدعوين',
            '👥 إدارة المدعوين',
            'edit_posts',
            'mon-event-invites',
            [$this, 'render_admin_invites_page']
        );

        // صفحة إعدادات الباقات (سلة)
        add_submenu_page(
            'edit.php?post_type=event',
            'إعدادات الباقات وسلة',
            '⚙️ إعدادات الباقات',
            'manage_options',
            'mon-packages-settings',
            [$this, 'render_packages_admin_page']
        );
    }

    public function render_packages_admin_page() {
        if (isset($_POST['mon_save_plans'])) {
            update_option('mon_packages_settings', $_POST['plans']);
            echo '<div class="notice notice-success is-dismissible"><p>تم تحديث كافة تفاصيل الباقات والربط التقني بنجاح! ✅</p></div>';
        }

        $plans = get_option('mon_packages_settings', []);
        ?>
        <style>
            .mon-wrapper { background: #f0f2f5; padding: 20px; font-family: 'Segoe UI', Tahoma; direction: rtl; }
            .mon-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow-x: auto; padding: 20px; }
            .mon-table { width: 100%; border-collapse: collapse; min-width: 1100px; }
            .mon-table th { background: #1d2327; color: #fff; padding: 12px; font-size: 13px; text-align: center; }
            .mon-table td { padding: 8px; border: 1px solid #ddd; text-align: center; }
            .group-header { background: #f1f1f1; font-weight: bold; text-align: right !important; padding: 10px 15px !important; color: #2271b1; border-bottom: 2px solid #2271b1 !important; }
            .mon-input { width: 95%; border: 1px solid #ccc !important; border-radius: 4px !important; padding: 4px !important; text-align: center; font-size: 12px; }
            .salla-field { background: #fff9e6; direction: ltr; }
            .sticky-footer { position: sticky; bottom: 0; background: #fff; padding: 15px; border-top: 2px solid #2271b1; text-align: left; }
        </style>

        <div class="wrap mon-wrapper">
            <h1>📑 الضبط الكامل لباقات "موقع مناسبات" والربط مع سلة</h1>
            <form method="post">
                <div class="mon-card">
                    <table class="mon-table">
                        <thead>
                            <tr>
                                <th style="width: 220px;">الميزة / الخاصية</th>
                                <?php for($i=1;$i<=4;$i++): ?> <th>باقة <?php echo $i; ?></th> <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td class="group-header" colspan="5">🔗 ربط متجر سلة (Salla)</td></tr>
                            <tr><td>ID منتج سلة (Product ID)</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][salla_id]" value="<?php echo $plans["plan_$i"]['salla_id'] ?? ''; ?>" class="mon-input salla-field"></td><?php endfor; ?></tr>
                            <tr><td>رابط الشراء المباشر</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][salla_url]" value="<?php echo $plans["plan_$i"]['salla_url'] ?? ''; ?>" class="mon-input salla-field"></td><?php endfor; ?></tr>
                            <tr><td>سعر الباقة (ريال)</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][price]" value="<?php echo $plans["plan_$i"]['price'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?></tr>

                            <tr><td class="group-header" colspan="5">🖼️ العرض والوسائط (Media)</td></tr>
                            <?php 
                            $media_features = [
                                'header_img' => 'صورة هيدر كبيرة',
                                'event_barcode' => 'باركود زيارة المناسبة',
                                'event_date' => 'تاريخ المناسبة',
                                'countdown' => 'كاونت داون (عد تنازلي)',
                                'google_map' => 'موقع قوقل ماب',
                                'stc_pay' => 'باركود STCPay للهدايا'
                            ];
                            foreach($media_features as $key => $label): ?>
                            <tr><td><?php echo $label; ?></td><?php for($i=1;$i<=4;$i++): ?><td><input type="checkbox" name="plans[plan_<?php echo $i; ?>][<?php echo $key; ?>]" value="1" <?php checked($plans["plan_$i"][$key] ?? 0, 1); ?>></td><?php endfor; ?></tr>
                            <?php endforeach; ?>

                            <tr><td class="group-header" colspan="5">📊 الحدود والكميات</td></tr>
                            <tr><td>عدد المدعوين (Guests)</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][guest_limit]" value="<?php echo $plans["plan_$i"]['guest_limit'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?></tr>
                            <tr><td>عدد صور المضيف</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][host_photos]" value="<?php echo $plans["plan_$i"]['host_photos'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?></tr>
                            <tr><td>فيديو برومو (يوتيوب/رفع)</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][promo_video]" value="<?php echo $plans["plan_$i"]['promo_video'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?></tr>
                            <tr><td>عدد المناسبات في الباقة</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][events_count]" value="<?php echo $plans["plan_$i"]['events_count'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?></tr>
                            <tr><td>حجم الداتا (ميجا)</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][data_size]" value="<?php echo $plans["plan_$i"]['data_size'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?></tr>

                            <tr><td class="group-header" colspan="5">💬 التفاعل والخصوصية</td></tr>
                            <?php 
                            $interact = [
                                'guest_photos' => 'رفع صور خاص (للضيف)',
                                'guest_video' => 'رفع فيديو خاص (للضيف)',
                                'public_chat' => 'دردشة عامة',
                                'private_chat' => 'دردشة خاصة',
                                'prev_events' => 'المناسبات السابقة',
                                'next_events' => 'المناسبات القادمة',
                                'guest_history' => 'مناسبات حضرتها كضيف',
                                'archive' => 'أرشفة المناسبات السابقة'
                            ];
                            foreach($interact as $key => $label): ?>
                            <tr><td><?php echo $label; ?></td><?php for($i=1;$i<=4;$i++): ?><td><input type="checkbox" name="plans[plan_<?php echo $i; ?>][<?php echo $key; ?>]" value="1" <?php checked($plans["plan_$i"][$key] ?? 0, 1); ?>></td><?php endfor; ?></tr>
                            <?php endforeach; ?>

                            <tr><td class="group-header" colspan="5">📩 الدعوات والإضافات المدفوعة</td></tr>
                            <tr><td>رسائل واتساب (دعوة/تذكير/شكر)</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][wa_messages]" value="<?php echo $plans["plan_$i"]['wa_messages'] ?? ''; ?>" class="mon-input" placeholder="عدد الأرقام"></td><?php endfor; ?></tr>
                            <tr><td>سعر وضع الخصوصية (OTP)</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][otp_price]" value="<?php echo $plans["plan_$i"]['otp_price'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?></tr>
                            <tr><td>سعر إضافة ضيف (لكل 5)</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][extra_guest_price]" value="<?php echo $plans["plan_$i"]['extra_guest_price'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?></tr>
                            <tr><td>سعر إضافة مدير (بحد أقصى 3)</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][extra_admin_price]" value="<?php echo $plans["plan_$i"]['extra_admin_price'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?></tr>
                            <tr><td>سعر التحكم بصلاحيات المدير</td><?php for($i=1;$i<=4;$i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][admin_perms_price]" value="<?php echo $plans["plan_$i"]['admin_perms_price'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?></tr>
                        </tbody>
                    </table>
                </div>
                <div class="sticky-footer">
                    <button type="submit" name="mon_save_plans" class="button button-primary button-large">حفظ كافة الإعدادات والربط مع سلة ✨</button>
                </div>
            </form>
        </div>
        <?php
    }

    public function render_admin_invites_page() {
        echo '<div class="wrap"><h1>إدارة المدعوين</h1><p>سيتم عرض قائمة المدعوين هنا.</p></div>';
    }

    /* --------------------------------------------------------------------------
     * Helpers & Assets
     * -------------------------------------------------------------------------- */

    public function add_multipart_form_enctype() { echo ' enctype="multipart/form-data"'; }

    public function enqueue_admin_assets($hook): void {
        wp_enqueue_media();
    }

    public function enqueue_gallery_uploader_assets($hook): void {
        wp_enqueue_script('mon-events-admin-gallery', plugins_url('../assets/admin-gallery.js', __FILE__), ['jquery'], '1.0', true);
    }
}