<?php
if (!defined('ABSPATH')) exit;

/**
 * كلاس إدارة لوحة تحكم المناسبات - النسخة الشاملة والمدمجة
 */
class PGE_Admin_Controller
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'pge_register_menus']);
        add_filter('manage_pge_event_posts_columns', [$this, 'pge_set_custom_columns']);
        add_action('manage_pge_event_posts_custom_column', [$this, 'pge_fill_custom_columns'], 10, 2);
        add_filter('manage_edit-pge_event_sortable_columns', [$this, 'pge_sortable_columns']);
        add_action('restrict_manage_posts', [$this, 'pge_add_filters_and_export_button']);
        add_action('admin_init', [$this, 'pge_handle_export_csv']);
        add_action('wp_dashboard_setup', [$this, 'pge_add_dashboard_widget']);
    }

    public function pge_register_menus()
    {
        add_submenu_page(
            'edit.php?post_type=pge_event',
            'إعدادات الباقات وسلة',
            '⚙️ إعدادات الباقات',
            'manage_options',
            'pge-packages-settings',
            [$this, 'render_packages_admin_page']
        );
    }

    /**
     * لوحة تحكم إعدادات الباقات الشاملة
     */
    public function render_packages_admin_page()
    {
        // مصفوفات المفاتيح لضمان معالجة الـ Checkboxes التي لا تُرسل قيمتها إذا لم تكن محددة
        $media_keys = ['header_img', 'event_barcode', 'event_date', 'countdown', 'google_map', 'stc_pay'];
        $interact_keys = ['guest_photos', 'guest_video', 'public_chat', 'private_chat', 'prev_events', 'next_events', 'guest_history', 'archive'];
        $all_checkbox_keys = array_merge($media_keys, $interact_keys);

        if (isset($_POST['mon_save_plans'])) {
            $submitted_plans = $_POST['plans'];

            // تأمين الـ Checkboxes: إذا لم تكن موجودة في POST، نضع قيمتها 0
            for ($i = 1; $i <= 4; $i++) {
                foreach ($all_checkbox_keys as $key) {
                    if (!isset($submitted_plans["plan_$i"][$key])) {
                        $submitted_plans["plan_$i"][$key] = 0;
                    }
                }
            }

            update_option('mon_packages_settings', $submitted_plans);
            echo '<div class="notice notice-success is-dismissible"><p>تم تحديث كافة تفاصيل الباقات والربط التقني بنجاح! ✅</p></div>';
        }

        $plans = get_option('mon_packages_settings', []);
?>
        <style>
            .mon-wrapper {
                background: #f0f2f5;
                padding: 20px;
                font-family: 'Segoe UI', Tahoma;
                direction: rtl;
                margin-right: -20px;
            }

            .mon-card {
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                overflow-x: auto;
                padding: 20px;
            }

            .mon-table {
                width: 100%;
                border-collapse: collapse;
                min-width: 1100px;
            }

            .mon-table th {
                background: #1d2327;
                color: #fff;
                padding: 12px;
                font-size: 13px;
                text-align: center;
            }

            .mon-table td {
                padding: 8px;
                border: 1px solid #ddd;
                text-align: center;
                vertical-align: middle;
            }

            .group-header {
                background: #f1f1f1;
                font-weight: bold;
                text-align: right !important;
                padding: 12px 15px !important;
                color: #2271b1;
                border-bottom: 2px solid #2271b1 !important;
            }

            .mon-input {
                width: 95%;
                border: 1px solid #ccc !important;
                border-radius: 4px !important;
                padding: 6px !important;
                text-align: center;
                font-size: 12px;
            }

            .salla-field {
                background: #fff9e6;
                direction: ltr;
                border-color: #ffd966 !important;
            }

            .sticky-footer {
                position: sticky;
                bottom: -20px;
                background: #fff;
                padding: 15px;
                border-top: 2px solid #2271b1;
                text-align: left;
                z-index: 99;
                margin-top: 20px;
                border-radius: 0 0 12px 12px;
            }

            input[type="checkbox"] {
                transform: scale(1.2);
                cursor: pointer;
            }
        </style>

        <div class="wrap mon-wrapper">
            <h1>📑 الضبط الكامل لباقات "موقع مناسبات" والربط مع سلة</h1>
            <form method="post">
                <div class="mon-card">
                    <table class="mon-table">
                        <thead>
                            <tr>
                                <th style="width: 220px;">الميزة / الخاصية</th>
                                <?php for ($i = 1; $i <= 4; $i++): ?> <th>باقة <?php echo $i; ?></th> <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="group-header" colspan="5">🏷️ التعريف الأساسي</td>
                            </tr>
                            <tr>
                                <td>اسم الباقة في الموقع</td>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <td><input type="text" name="plans[plan_<?php echo $i; ?>][name]" value="<?php echo esc_attr($plans["plan_$i"]['name'] ?? 'باقة ' . $i); ?>" class="mon-input" placeholder="مثلاً: الباقة الماسية"></td>
                                <?php endfor; ?>
                            </tr>

                            <tr>
                                <td class="group-header" colspan="5">🔗 ربط متجر سلة (Salla)</td>
                            </tr>
                            <tr>
                                <td>ID منتج سلة (Product ID)</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][salla_id]" value="<?php echo $plans["plan_$i"]['salla_id'] ?? ''; ?>" class="mon-input salla-field"></td><?php endfor; ?>
                            </tr>
                            <tr>
                                <td>رابط الشراء المباشر</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][salla_url]" value="<?php echo $plans["plan_$i"]['salla_url'] ?? ''; ?>" class="mon-input salla-field"></td><?php endfor; ?>
                            </tr>
                            <tr>
                                <td>سعر الباقة (ريال)</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][price]" value="<?php echo $plans["plan_$i"]['price'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?>
                            </tr>

                            <tr>
                                <td class="group-header" colspan="5">🖼️ العرض والوسائط (Media)</td>
                            </tr>
                            <?php
                            $media_features = [
                                'header_img' => 'صورة هيدر كبيرة',
                                'event_barcode' => 'باركود زيارة المناسبة',
                                'event_date' => 'تاريخ المناسبة',
                                'countdown' => 'كاونت داون (عد تنازلي)',
                                'google_map' => 'موقع قوقل ماب',
                                'stc_pay' => 'باركود STCPay للهدايا'
                            ];
                            foreach ($media_features as $key => $label): ?>
                                <tr>
                                    <td><?php echo $label; ?></td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="checkbox" name="plans[plan_<?php echo $i; ?>][<?php echo $key; ?>]" value="1" <?php checked($plans["plan_$i"][$key] ?? 0, 1); ?>></td><?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <tr>
                                <td class="group-header" colspan="5">📊 الحدود والكميات</td>
                            </tr>
                            <tr>
                                <td>عدد المدعوين (Guests)</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="number" name="plans[plan_<?php echo $i; ?>][guest_limit]" value="<?php echo $plans["plan_$i"]['guest_limit'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?>
                            </tr>
                            <tr>
                                <td>عدد صور المضيف</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="number" name="plans[plan_<?php echo $i; ?>][host_photos]" value="<?php echo $plans["plan_$i"]['host_photos'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?>
                            </tr>
                            <tr>
                                <td>فيديو برومو (يوتيوب/رفع)</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][promo_video]" value="<?php echo $plans["plan_$i"]['promo_video'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?>
                            </tr>
                            <tr>
                                <td>عدد المناسبات في الباقة</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="number" name="plans[plan_<?php echo $i; ?>][events_count]" value="<?php echo $plans["plan_$i"]['events_count'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?>
                            </tr>
                            <tr>
                                <td>حجم الداتا (ميجا)</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][data_size]" value="<?php echo $plans["plan_$i"]['data_size'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?>
                            </tr>

                            <tr>
                                <td class="group-header" colspan="5">💬 التفاعل والخصوصية</td>
                            </tr>
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
                            foreach ($interact as $key => $label): ?>
                                <tr>
                                    <td><?php echo $label; ?></td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="checkbox" name="plans[plan_<?php echo $i; ?>][<?php echo $key; ?>]" value="1" <?php checked($plans["plan_$i"][$key] ?? 0, 1); ?>></td><?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>

                            <tr>
                                <td class="group-header" colspan="5">📩 الدعوات والإضافات المدفوعة</td>
                            </tr>
                            <tr>
                                <td>رسائل واتساب (دعوة/تذكير/شكر)</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][wa_messages]" value="<?php echo $plans["plan_$i"]['wa_messages'] ?? ''; ?>" class="mon-input" placeholder="عدد الأرقام"></td><?php endfor; ?>
                            </tr>
                            <tr>
                                <td>سعر وضع الخصوصية (OTP)</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][otp_price]" value="<?php echo $plans["plan_$i"]['otp_price'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?>
                            </tr>
                            <tr>
                                <td>سعر إضافة ضيف (لكل 5)</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][extra_guest_price]" value="<?php echo $plans["plan_$i"]['extra_guest_price'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?>
                            </tr>
                            <tr>
                                <td>سعر إضافة مدير (بحد أقصى 3)</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][extra_admin_price]" value="<?php echo $plans["plan_$i"]['extra_admin_price'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?>
                            </tr>
                            <tr>
                                <td>سعر التحكم بصلاحيات المدير</td><?php for ($i = 1; $i <= 4; $i++): ?><td><input type="text" name="plans[plan_<?php echo $i; ?>][admin_perms_price]" value="<?php echo $plans["plan_$i"]['admin_perms_price'] ?? ''; ?>" class="mon-input"></td><?php endfor; ?>
                            </tr>
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

    /* --- دوال إدارة الجداول (الأعمدة، التصدير، الإحصائيات) --- */

    public function pge_set_custom_columns($columns)
    {
        return ['cb' => $columns['cb'], 'title' => 'اسم المناسبة', 'author' => 'المشترك', 'event_date' => 'تاريخ المناسبة', 'host_phone' => 'رقم الواتساب', 'status' => 'الحالة'];
    }

    public function pge_fill_custom_columns($column, $post_id)
    {
        switch ($column) {
            case 'event_date':
                $date = get_post_meta($post_id, '_pge_event_date', true);
                echo $date ? '<strong>' . date_i18n('j F Y', strtotime($date)) . '</strong>' : '—';
                break;
            case 'host_phone':
                $phone = get_post_meta($post_id, '_pge_host_phone', true);
                if ($phone) echo '<a href="https://wa.me/' . $phone . '" target="_blank" style="color:#25D366; font-weight:bold;"><span class="dashicons dashicons-whatsapp"></span> ' . $phone . '</a>';
                else echo '—';
                break;
            case 'status':
                $post_status = get_post_status($post_id);
                $is_private = ($post_status === 'private');
                echo '<span style="background:' . ($is_private ? '#ffe4e6' : '#f0fdf4') . '; color:' . ($is_private ? '#e11d48' : '#16a34a') . '; padding:5px 10px; border-radius:20px; font-size:11px; font-weight:bold; border:1px solid ' . ($is_private ? '#fecdd3' : '#bbf7d0') . ';">' . ($is_private ? 'مؤرشفة' : 'نشطة') . '</span>';
                break;
        }
    }

    public function pge_sortable_columns($columns)
    {
        $columns['event_date'] = 'event_date';
        return $columns;
    }

    public function pge_add_filters_and_export_button()
    {
        global $typenow;
        if ($typenow == 'pge_event') {
            wp_dropdown_users(['show_option_all' => 'كل المشتركين', 'name' => 'author', 'selected' => $_GET['author'] ?? 0]);
            echo '<button type="submit" name="pge_export_csv" value="1" class="button button-secondary" style="margin-right:5px;"><span class="dashicons dashicons-download"></span> تصدير Excel</button>';
        }
    }

    public function pge_handle_export_csv()
    {
        if (isset($_GET['pge_export_csv']) && $_GET['pge_export_csv'] == '1') {
            if (!current_user_can('manage_options')) return;
            $filename = 'events_export_' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $filename);
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, ['اسم المناسبة', 'المشترك', 'التاريخ', 'رقم الهاتف', 'الحالة']);
            $query = new WP_Query(['post_type' => 'pge_event', 'post_status' => ['publish', 'private'], 'posts_per_page' => -1, 'author' => $_GET['author'] ?? '']);
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    fputcsv($output, [get_the_title(), get_the_author(), get_post_meta(get_the_ID(), '_pge_event_date', true), get_post_meta(get_the_ID(), '_pge_host_phone', true), (get_post_status() == 'publish' ? 'نشطة' : 'مؤرشفة')]);
                }
            }
            exit;
        }
    }

    public function pge_add_dashboard_widget()
    {
        wp_add_dashboard_widget('pge_stats_widget', '📊 إحصائيات نظام المناسبات', function () {
            $total = wp_count_posts('pge_event');
            echo '<div style="display:flex; justify-content:space-around; text-align:center; padding:15px 0;">
                    <div><span style="display:block; font-size:28px; font-weight:bold; color:#16a34a;">' . ($total->publish ?? 0) . '</span> نشطة</div>
                    <div style="border-right:1px solid #eee; padding-right:20px;"><span style="display:block; font-size:28px; font-weight:bold; color:#e11d48;">' . ($total->private ?? 0) . '</span> مؤرشفة</div>
                  </div>';
        });
    }
}

new PGE_Admin_Controller();
