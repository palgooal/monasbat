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
        add_action('admin_notices', [$this, 'pge_salla_secret_notice']);
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

        add_submenu_page(
            'edit.php?post_type=pge_event',
            'متاجر سلة المربوطة',
            '🛒 متاجر سلة',
            'manage_options',
            'pge-salla-stores',
            [$this, 'render_salla_stores_page']
        );

        add_submenu_page(
            'edit.php?post_type=pge_event',
            'إعدادات واتساب',
            '💬 إعدادات واتساب',
            'manage_options',
            'pge-cartat-settings',
            [$this, 'render_cartat_settings_page']
        );
    }

    // ── صفحة متاجر سلة ─────────────────────────────────────────────────────
    public function render_salla_stores_page()
    {
        global $wpdb;

        // معالجة حذف متجر
        if (isset($_GET['delete_merchant']) && check_admin_referer('delete_merchant_' . $_GET['delete_merchant'])) {
            $mid = sanitize_text_field($_GET['delete_merchant']);
            delete_option('pge_salla_tokens_'  . $mid);
            delete_option('pge_salla_install_' . $mid);
            echo '<div class="notice notice-success is-dismissible"><p>تم حذف بيانات المتجر ' . esc_html($mid) . ' ✅</p></div>';
        }

        // جلب كل المتاجر المثبتة
        $installs = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'pge_salla_install_%'
             ORDER BY option_name ASC"
        );

        echo '<div class="wrap" style="direction:rtl; font-family:\'Segoe UI\',Tahoma;">';
        echo '<h1>🛒 متاجر سلة المربوطة</h1>';

        if (empty($installs)) {
            echo '<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:20px; margin-top:16px; color:#16a34a;">';
            echo '<strong>لا توجد متاجر مربوطة حتى الآن.</strong> ستظهر هنا تلقائياً عند تثبيت التطبيق من Salla App Store.';
            echo '</div></div>';
            return;
        }

        echo '<p style="color:#666;">المتاجر التي ثبّتت التطبيق عبر Salla App Store.</p>';
        echo '<table class="widefat striped" style="direction:rtl; margin-top:16px;">';
        echo '<thead><tr>
                <th>Merchant ID</th>
                <th>تاريخ التثبيت</th>
                <th>آخر تحديث توكن</th>
                <th>تاريخ انتهاء التوكن</th>
                <th>حالة التوكن</th>
                <th>الـ Scope</th>
                <th>إجراء</th>
              </tr></thead><tbody>';

        $now = time();
        foreach ($installs as $row) {
            $merchant_id = str_replace('pge_salla_install_', '', $row->option_name);
            $install     = maybe_unserialize($row->option_value);
            $tokens      = get_option('pge_salla_tokens_' . $merchant_id, []);

            $expires_ts   = (int) ($tokens['expires'] ?? 0);
            $expires_date = $expires_ts ? date_i18n('Y-m-d H:i', $expires_ts) : '—';
            $days_left    = $expires_ts ? ceil(($expires_ts - $now) / 86400) : null;

            if (empty($tokens)) {
                $status_label = 'لا يوجد توكن';
                $status_color = '#e11d48';
                $status_bg    = '#fff1f2';
            } elseif ($expires_ts && $expires_ts < $now) {
                $status_label = 'منتهي الصلاحية';
                $status_color = '#b45309';
                $status_bg    = '#fffbeb';
            } else {
                $status_label = 'نشط (' . $days_left . ' يوم)';
                $status_color = '#16a34a';
                $status_bg    = '#f0fdf4';
            }

            $delete_url = wp_nonce_url(
                admin_url('edit.php?post_type=pge_event&page=pge-salla-stores&delete_merchant=' . $merchant_id),
                'delete_merchant_' . $merchant_id
            );

            echo '<tr>';
            echo '<td><strong>' . esc_html($merchant_id) . '</strong></td>';
            echo '<td>' . esc_html($install['installed_at'] ?? '—') . '</td>';
            echo '<td>' . esc_html($tokens['updated_at'] ?? '—') . '</td>';
            echo '<td>' . esc_html($expires_date) . '</td>';
            echo '<td><span style="background:' . esc_attr($status_bg) . '; color:' . esc_attr($status_color) . '; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:bold;">'
                . esc_html($status_label) . '</span></td>';
            echo '<td style="font-size:11px; color:#666;">' . esc_html($tokens['scope'] ?? '—') . '</td>';
            echo '<td><a href="' . esc_url($delete_url) . '" style="color:#e11d48; font-size:12px;" onclick="return confirm(\'حذف المتجر ' . esc_attr($merchant_id) . '؟\');">🗑️ حذف</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p style="color:#888; font-size:12px; margin-top:12px;">يتم تحديث هذه البيانات تلقائياً عند وصول أحداث Webhook من سلة.</p>';
        echo '</div>';
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

        // حفظ مفتاح Webhook السري
        if (isset($_POST['mon_save_salla_secret']) && check_admin_referer('mon_salla_secret_nonce')) {
            $new_secret = sanitize_text_field(wp_unslash($_POST['pge_salla_webhook_secret'] ?? ''));
            if ($new_secret !== '') {
                update_option('pge_salla_webhook_secret', $new_secret);
                update_option('pge_salla_webhook_secret_updated', current_time('mysql'));
                echo '<div class="notice notice-success is-dismissible"><p>✅ تم تحديث مفتاح Webhook السري بنجاح!</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>⚠️ المفتاح لا يمكن أن يكون فارغاً.</p></div>';
            }
        }

        // حفظ Client ID و Client Secret
        if (isset($_POST['mon_save_salla_credentials']) && check_admin_referer('mon_salla_credentials_nonce')) {
            $client_id     = sanitize_text_field(wp_unslash($_POST['pge_salla_client_id']     ?? ''));
            $client_secret = sanitize_text_field(wp_unslash($_POST['pge_salla_client_secret'] ?? ''));
            $saved = false;
            if ($client_id !== '') {
                update_option('pge_salla_client_id', $client_id);
                $saved = true;
            }
            if ($client_secret !== '') {
                update_option('pge_salla_client_secret', $client_secret);
                update_option('pge_salla_client_secret_updated', current_time('mysql'));
                $saved = true;
            }
            if ($saved) {
                echo '<div class="notice notice-success is-dismissible"><p>✅ تم حفظ بيانات تطبيق سلة بنجاح!</p></div>';
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>⚠️ لم يتغير شيء — الحقول فارغة.</p></div>';
            }
        }

        // إسناد باقة لمستخدم يدوياً (إعادة تفعيل)
        $assign_msg = '';
        if (isset($_POST['mon_assign_package']) && check_admin_referer('mon_assign_package_nonce')) {
            $assign_email    = sanitize_email(wp_unslash($_POST['assign_user_email'] ?? ''));
            $assign_plan_key = sanitize_text_field(wp_unslash($_POST['assign_plan_key'] ?? ''));

            if ($assign_email && $assign_plan_key) {
                $all_plans_now  = get_option('mon_packages_settings', []);
                $plan_det       = $all_plans_now[$assign_plan_key] ?? [];

                if (empty($plan_det)) {
                    $assign_msg = '<div class="notice notice-error is-dismissible"><p>❌ الباقة "' . esc_html($assign_plan_key) . '" غير موجودة في الإعدادات.</p></div>';
                } else {
                    $assign_user = get_user_by('email', $assign_email);
                    if (!$assign_user) {
                        $assign_msg = '<div class="notice notice-error is-dismissible"><p>❌ لم يُعثر على مستخدم بهذا البريد: ' . esc_html($assign_email) . '</p></div>';
                    } else {
                        // تطبيق نفس منطق activate_user_package مباشرة
                        update_user_meta($assign_user->ID, '_mon_package_status', 'active');
                        update_user_meta($assign_user->ID, '_mon_package_key',    $assign_plan_key);
                        update_user_meta($assign_user->ID, '_mon_package_name',   $plan_det['name'] ?? 'باقة');
                        update_user_meta($assign_user->ID, '_mon_events_limit',   max(1, (int)($plan_det['events_count'] ?? 1)));
                        update_user_meta($assign_user->ID, '_mon_guest_limit',    max(0, (int)($plan_det['guest_limit']  ?? 0)));
                        update_user_meta($assign_user->ID, '_mon_host_photos_limit', max(0, (int)($plan_det['host_photos'] ?? 0)));
                        update_user_meta($assign_user->ID, '_mon_wa_limit',       max(0, (int)($plan_det['wa_messages']  ?? 0)));

                        $features = [];
                        foreach ($plan_det as $fk => $fv) {
                            if ($fv == '1' || $fv === 1) $features[] = $fk;
                        }
                        update_user_meta($assign_user->ID, '_mon_active_features', $features);

                        error_log("🔧 Manual Plan Assign: {$assign_plan_key} → User #{$assign_user->ID} ({$assign_email}) by Admin");

                        $pname = $plan_det['name'] ?? $assign_plan_key;
                        $elim  = max(1, (int)($plan_det['events_count'] ?? 1));
                        $assign_msg = '<div class="notice notice-success is-dismissible"><p>✅ تم تفعيل باقة "<strong>' . esc_html($pname) . '</strong>" للمستخدم <strong>' . esc_html($assign_email) . '</strong> بنجاح! (مناسبات: ' . $elim . ')</p></div>';
                    }
                }
            } else {
                $assign_msg = '<div class="notice notice-warning is-dismissible"><p>⚠️ يرجى إدخال البريد الإلكتروني واختيار الباقة.</p></div>';
            }
        }
        echo wp_kses_post($assign_msg);

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

            <?php
            // تحديد مصدر المفتاح الحالي
            $secret_from_config = defined('PGE_SALLA_WEBHOOK_SECRET');
            $secret_in_db       = get_option('pge_salla_webhook_secret', '');
            $secret_updated_at  = get_option('pge_salla_webhook_secret_updated', '');
            ?>

            <div class="mon-card" style="margin-top:24px;">
                <h2 style="margin-top:0; color:#1d2327; border-bottom:2px solid #2271b1; padding-bottom:10px;">
                    🔐 مفتاح Webhook السري (سلة)
                </h2>

                <?php if ($secret_from_config): ?>
                    <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:14px 18px; margin-bottom:16px; color:#856404;">
                        <strong>⚠️ ملاحظة:</strong> المفتاح محدد حالياً عبر ثابت <code>PGE_SALLA_WEBHOOK_SECRET</code> في ملف <code>wp-config.php</code>، وهو يأخذ الأولوية على ما تحفظه هنا. لتفعيل التحكم من هذه الصفحة، علّق على ذلك السطر في <code>wp-config.php</code>.
                    </div>
                <?php endif; ?>

                <p style="color:#555; margin-bottom:18px;">
                    المفتاح يُستخدم للتحقق من أن التنبيهات القادمة من سلة حقيقية وغير مزورة (HMAC-SHA256).
                    يمكنك تغييره من <a href="https://partners.salla.com" target="_blank">Salla Partner Portal</a> ← تطبيقك ← التنبيهات ← "إعادة توليد"، ثم لصق القيمة الجديدة هنا.
                </p>

                <form method="post">
                    <?php wp_nonce_field('mon_salla_secret_nonce'); ?>
                    <table style="width:100%; max-width:700px;">
                        <tr>
                            <td style="padding:8px 0; font-weight:bold; width:200px;">المفتاح الحالي في DB:</td>
                            <td>
                                <?php if ($secret_in_db): ?>
                                    <code style="background:#f0f0f0; padding:4px 10px; border-radius:4px; font-size:12px; letter-spacing:1px;">
                                        <?php echo esc_html(substr($secret_in_db, 0, 8)); ?>••••••••••••••••••••••••••••••••
                                    </code>
                                    <?php if ($secret_updated_at): ?>
                                        <span style="color:#888; font-size:12px; margin-right:10px;">
                                            آخر تحديث: <?php echo esc_html($secret_updated_at); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#e11d48; font-weight:bold;">⚠️ لا يوجد مفتاح محفوظ في DB</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:8px 0; font-weight:bold;">المفتاح الجديد:</td>
                            <td>
                                <div style="display:flex; gap:8px; align-items:center;">
                                    <input
                                        type="password"
                                        id="salla_secret_input"
                                        name="pge_salla_webhook_secret"
                                        placeholder="الصق هنا المفتاح الجديد من Partner Portal"
                                        style="width:100%; max-width:500px; padding:8px 12px; border:1px solid #ccc; border-radius:6px; font-family:monospace; direction:ltr;"
                                        autocomplete="off"
                                    >
                                    <button type="button"
                                        onclick="var f=document.getElementById('salla_secret_input'); f.type=f.type==='password'?'text':'password'; this.textContent=f.type==='password'?'👁️ إظهار':'🙈 إخفاء';"
                                        class="button button-secondary" style="white-space:nowrap;">
                                        👁️ إظهار
                                    </button>
                                </div>
                                <p style="color:#888; font-size:12px; margin:6px 0 0;">
                                    اتركه فارغاً إذا لم تريد تغيير المفتاح الحالي.
                                </p>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top:16px;">
                        <button type="submit" name="mon_save_salla_secret" class="button button-primary">
                            🔑 حفظ المفتاح الجديد
                        </button>
                    </div>
                </form>
            </div>

            <?php
            // بيانات Client ID / Secret
            $client_id             = defined('PGE_SALLA_CLIENT_ID')     ? PGE_SALLA_CLIENT_ID     : get_option('pge_salla_client_id', '');
            $client_secret         = defined('PGE_SALLA_CLIENT_SECRET') ? PGE_SALLA_CLIENT_SECRET : get_option('pge_salla_client_secret', '');
            $client_secret_updated = get_option('pge_salla_client_secret_updated', '');
            $creds_from_config     = defined('PGE_SALLA_CLIENT_ID') || defined('PGE_SALLA_CLIENT_SECRET');
            ?>

            <div class="mon-card" style="margin-top:24px;">
                <h2 style="margin-top:0; color:#1d2327; border-bottom:2px solid #2271b1; padding-bottom:10px;">
                    🔑 بيانات تطبيق سلة (Client ID / Secret)
                </h2>

                <?php if ($creds_from_config): ?>
                    <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:14px 18px; margin-bottom:16px; color:#856404;">
                        <strong>⚠️ ملاحظة:</strong> إحدى القيم محددة عبر ثوابت في <code>wp-config.php</code> وتأخذ الأولوية. علّق عليها لتفعيل التحكم من هنا.
                    </div>
                <?php endif; ?>

                <p style="color:#555; margin-bottom:18px;">
                    تجدها في <a href="https://partners.salla.com" target="_blank">Salla Partner Portal</a> ← تطبيقك ← إعدادات OAuth.
                    <strong>Client Secret</strong> يُستخدم في Easy Mode فقط عند الحاجة لاستدعاء Salla API.
                </p>

                <form method="post">
                    <?php wp_nonce_field('mon_salla_credentials_nonce'); ?>
                    <table style="width:100%; max-width:700px; border-collapse:collapse;">
                        <?php
                        $rows = [
                            ['label' => 'Client ID الحالي', 'id' => 'salla_client_id_input', 'name' => 'pge_salla_client_id',     'current' => $client_id,     'placeholder' => 'الصق هنا Client ID من Partner Portal'],
                            ['label' => 'Client Secret الحالي', 'id' => 'salla_client_secret_input', 'name' => 'pge_salla_client_secret', 'current' => $client_secret, 'placeholder' => 'الصق هنا Client Secret من Partner Portal', 'updated' => $client_secret_updated],
                        ];
                        foreach ($rows as $r): ?>
                        <tr>
                            <td style="padding:10px 0 4px; font-weight:bold; width:200px; vertical-align:top;">
                                <?php echo esc_html($r['label']); ?>:
                            </td>
                            <td style="padding:10px 0 4px;">
                                <?php if (!empty($r['current'])): ?>
                                    <code style="background:#f0f0f0; padding:4px 10px; border-radius:4px; font-size:12px; direction:ltr; display:inline-block;">
                                        <?php echo esc_html(substr($r['current'], 0, 6)); ?>••••••••••••••••••••
                                    </code>
                                    <?php if (!empty($r['updated'])): ?>
                                        <span style="color:#888; font-size:12px; margin-right:8px;">آخر تحديث: <?php echo esc_html($r['updated']); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#e11d48; font-weight:bold; font-size:13px;">⚠️ غير محدد</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:4px 0 14px; vertical-align:middle;">
                                <span style="font-size:12px; color:#888;">جديد:</span>
                            </td>
                            <td style="padding:4px 0 14px;">
                                <div style="display:flex; gap:8px; align-items:center;">
                                    <input
                                        type="password"
                                        id="<?php echo esc_attr($r['id']); ?>"
                                        name="<?php echo esc_attr($r['name']); ?>"
                                        placeholder="<?php echo esc_attr($r['placeholder']); ?>"
                                        style="width:100%; max-width:500px; padding:8px 12px; border:1px solid #ccc; border-radius:6px; font-family:monospace; direction:ltr;"
                                        autocomplete="off"
                                    >
                                    <button type="button"
                                        onclick="var f=document.getElementById('<?php echo esc_attr($r['id']); ?>'); f.type=f.type==='password'?'text':'password'; this.textContent=f.type==='password'?'👁️':'🙈';"
                                        class="button button-secondary">👁️</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <p style="color:#888; font-size:12px; margin:4px 0 16px;">اترك الحقل فارغاً إذا لم تريد تغيير القيمة الحالية.</p>
                    <button type="submit" name="mon_save_salla_credentials" class="button button-primary">
                        💾 حفظ بيانات التطبيق
                    </button>
                </form>
            </div>

            <!-- ── إسناد باقة لمستخدم يدوياً ─────────────────────────────── -->
            <div class="mon-card" style="margin-top:24px; border: 2px solid #f59e0b;">
                <h2 style="margin-top:0; color:#92400e; border-bottom:2px solid #f59e0b; padding-bottom:10px;">
                    🛠️ إسناد / إصلاح باقة مستخدم يدوياً
                </h2>
                <p style="color:#555; margin-bottom:18px;">
                    استخدم هذه الأداة لإسناد باقة لمستخدم موجود أو لإصلاح حالة اشتراك لم يتفعل بشكل صحيح.
                    سيتم نسخ جميع الحدود والمميزات من إعدادات الباقة المحفوظة أعلاه مباشرةً.
                </p>
                <form method="post">
                    <?php wp_nonce_field('mon_assign_package_nonce'); ?>
                    <table style="width:100%; max-width:700px; border-collapse:collapse;">
                        <tr>
                            <td style="padding:8px 0; font-weight:bold; width:200px;">البريد الإلكتروني للمستخدم:</td>
                            <td style="padding:8px 0;">
                                <input
                                    type="email"
                                    name="assign_user_email"
                                    placeholder="user@example.com"
                                    style="width:100%; max-width:350px; padding:8px 12px; border:1px solid #ccc; border-radius:6px; direction:ltr;"
                                    required
                                />
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:8px 0; font-weight:bold;">الباقة:</td>
                            <td style="padding:8px 0;">
                                <select name="assign_plan_key" style="padding:8px 12px; border:1px solid #ccc; border-radius:6px; min-width:200px;">
                                    <?php
                                    $assign_plans = get_option('mon_packages_settings', []);
                                    if (empty($assign_plans)) {
                                        $assign_plans = [
                                            'plan_1' => ['name' => 'باقة 1'],
                                            'plan_2' => ['name' => 'باقة 2'],
                                            'plan_3' => ['name' => 'باقة 3'],
                                            'plan_4' => ['name' => 'باقة 4'],
                                        ];
                                    }
                                    foreach ($assign_plans as $pk => $pd):
                                        if (empty($pd['name'])) continue;
                                        $elim = max(1, (int)($pd['events_count'] ?? 1));
                                    ?>
                                        <option value="<?php echo esc_attr($pk); ?>">
                                            <?php echo esc_html($pd['name']); ?> — <?php echo $elim; ?> مناسبة
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top:16px;">
                        <button type="submit" name="mon_assign_package" class="button button-primary" style="background:#f59e0b; border-color:#d97706; color:#fff;">
                            ⚡ تفعيل الباقة للمستخدم
                        </button>
                        <span style="margin-right:12px; color:#888; font-size:12px;">سيتم استبدال الباقة الحالية للمستخدم.</span>
                    </div>
                </form>
            </div>

        </div>
<?php
    }

    /**
     * تنبيه عند غياب مفتاح Webhook السري
     */
    public function pge_salla_secret_notice()
    {
        if (!current_user_can('manage_options')) return;
        if (defined('PGE_SALLA_WEBHOOK_SECRET')) return; // محدد في wp-config، لا حاجة للتنبيه
        $secret = get_option('pge_salla_webhook_secret', '');
        if (!empty($secret)) return; // محفوظ في DB، كل شيء تمام
        $url = admin_url('edit.php?post_type=pge_event&page=pge-packages-settings');
        echo '<div class="notice notice-warning is-dismissible">
            <p>⚠️ <strong>مناسبات:</strong> مفتاح Webhook السري (سلة) غير مضبوط. Webhooks القادمة ستُرفض حتى تضبطه.
            <a href="' . esc_url($url) . '"><strong>اضبطه الآن من إعدادات الباقات</strong></a></p>
        </div>';
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

    // ── صفحة إعدادات واتساب (Cartat + UltraMsg) ───────────────────────────────
    public function render_cartat_settings_page()
    {
        if (!current_user_can('manage_options')) return;

        // ── حفظ اختيار المزوّد ──────────────────────────────────────────────
        if (isset($_POST['pge_wa_provider_save']) && check_admin_referer('pge_wa_provider_nonce')) {
            $prov = sanitize_text_field($_POST['pge_wa_provider'] ?? 'cartat');
            $prov = in_array($prov, ['cartat', 'ultramsg'], true) ? $prov : 'cartat';
            update_option('pge_wa_provider', $prov);
            echo '<div class="notice notice-success is-dismissible"><p>✅ تم تغيير المزوّد إلى <strong>' . esc_html($prov === 'ultramsg' ? 'UltraMsg' : 'Cartat') . '</strong> — سيدخل حيز التنفيذ فوراً.</p></div>';
        }

        // ── حفظ إعدادات UltraMsg ────────────────────────────────────────────
        if (isset($_POST['pge_ultramsg_save']) && check_admin_referer('pge_ultramsg_settings_nonce')) {
            update_option('pge_ultramsg_instance_id', sanitize_text_field($_POST['pge_ultramsg_instance_id'] ?? ''));
            update_option('pge_ultramsg_token',       sanitize_text_field($_POST['pge_ultramsg_token']       ?? ''));
            update_option('pge_cartat_country_code',  sanitize_text_field($_POST['pge_cartat_country_code']  ?? '966'));
            echo '<div class="notice notice-success is-dismissible"><p>✅ تم حفظ إعدادات UltraMsg بنجاح.</p></div>';
        }

        // ── حفظ إعدادات Cartat ──────────────────────────────────────────────
        if (isset($_POST['pge_cartat_save']) && check_admin_referer('pge_cartat_settings_nonce')) {
            update_option('pge_cartat_api_token',    sanitize_text_field($_POST['pge_cartat_api_token']    ?? ''));
            update_option('pge_cartat_country_code', sanitize_text_field($_POST['pge_cartat_country_code'] ?? '966'));
            echo '<div class="notice notice-success is-dismissible"><p>✅ تم حفظ إعدادات واتساب بنجاح.</p></div>';
        }

        $token        = (string) get_option('pge_cartat_api_token', '');
        $country_code = (string) get_option('pge_cartat_country_code', '966');
        $webhook_url  = home_url('/wp-json/mon/v1/wa-callback');

        $active_provider    = (string) get_option('pge_wa_provider', 'cartat');
        $um_instance_id     = (string) get_option('pge_ultramsg_instance_id', '');
        $um_token           = (string) get_option('pge_ultramsg_token', '');
        $um_webhook_url     = home_url('/wp-json/mon/v1/um-callback');

        echo '<div class="wrap" style="direction:rtl; font-family:\'Segoe UI\',Tahoma;">';
        echo '<h1>💬 إعدادات واتساب</h1>';

        // ── زر التبديل بين المزوّدين ─────────────────────────────────────────
        echo '<div style="background:#fff; border:2px solid #2271b1; border-radius:12px; padding:20px 24px; margin-bottom:24px;">';
        echo '<h2 style="margin-top:0; color:#1d2327;">🔀 المزوّد النشط حالياً</h2>';
        echo '<form method="post" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">';
        wp_nonce_field('pge_wa_provider_nonce');
        echo '<label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:12px 20px; border-radius:8px; border:2px solid ' . ($active_provider === 'cartat' ? '#2271b1' : '#ccc') . '; background:' . ($active_provider === 'cartat' ? '#eff6ff' : '#f9fafb') . ';">';
        echo '<input type="radio" name="pge_wa_provider" value="cartat" ' . checked($active_provider, 'cartat', false) . '>';
        echo '<span style="font-weight:bold; font-size:15px;">Cartat</span>';
        echo '<span style="font-size:12px; color:#666;">api.cartat.net</span>';
        echo '</label>';
        echo '<label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:12px 20px; border-radius:8px; border:2px solid ' . ($active_provider === 'ultramsg' ? '#16a34a' : '#ccc') . '; background:' . ($active_provider === 'ultramsg' ? '#f0fdf4' : '#f9fafb') . ';">';
        echo '<input type="radio" name="pge_wa_provider" value="ultramsg" ' . checked($active_provider, 'ultramsg', false) . '>';
        echo '<span style="font-weight:bold; font-size:15px;">UltraMsg</span>';
        echo '<span style="font-size:12px; color:#666;">api.ultramsg.com</span>';
        echo '</label>';
        echo '<button type="submit" name="pge_wa_provider_save" class="button button-primary">💾 تطبيق الاختيار</button>';
        echo '</form>';
        $provider_badge = $active_provider === 'ultramsg'
            ? '<span style="background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; padding:4px 12px; border-radius:20px; font-weight:bold; font-size:13px;">✅ UltraMsg نشط</span>'
            : '<span style="background:#eff6ff; color:#2271b1; border:1px solid #bfdbfe; padding:4px 12px; border-radius:20px; font-weight:bold; font-size:13px;">✅ Cartat نشط</span>';
        echo '<p style="margin-top:12px; color:#555;">المزوّد الحالي: ' . $provider_badge . '</p>';
        echo '</div>';

        // ── إعدادات UltraMsg ─────────────────────────────────────────────────
        $um_display = $active_provider === 'ultramsg' ? 'block' : 'none';
        echo '<div id="pge-ultramsg-section" style="display:' . $um_display . ';">';
        echo '<div style="background:#f0fdf4; border:2px solid #bbf7d0; border-radius:12px; padding:20px 24px; margin-bottom:24px;">';
        echo '<h2 style="margin-top:0; color:#15803d;">📱 إعدادات UltraMsg</h2>';

        echo '<div style="background:#dcfce7; border:1px solid #86efac; border-radius:8px; padding:14px 18px; margin:0 0 16px; color:#166534;">';
        echo '<strong>🔗 Webhook URL لـ UltraMsg</strong> — أدخل هذا الرابط في لوحة UltraMsg:<br>';
        echo '<code style="background:#fff; border:1px solid #86efac; padding:6px 12px; display:inline-block; margin-top:8px; border-radius:4px; font-size:13px;">' . esc_url($um_webhook_url) . '</code>';
        echo '<br><small style="margin-top:6px; display:block;">في UltraMsg: Settings → Webhook → Webhook URL</small>';
        echo '</div>';

        // اختبار الاتصال
        if (!empty($um_instance_id) && !empty($um_token)) {
            $um_test_res = wp_remote_get('https://api.ultramsg.com/' . $um_instance_id . '/instance/status', [
                'body'    => ['token' => $um_token],
                'timeout' => 10,
            ]);
            if (!is_wp_error($um_test_res)) {
                $um_body = json_decode(wp_remote_retrieve_body($um_test_res), true);
                $um_status = $um_body['status'] ?? ($um_body['accountStatus'] ?? '');
                $is_connected = in_array($um_status, ['authenticated', 'connected'], true)
                             || (isset($um_body['status']['msg']) && $um_body['status']['msg'] === 'qrCode'  ? false : true);
                echo '<div style="background:' . ($is_connected ? '#f0fdf4' : '#fef9c3') . '; border:1px solid ' . ($is_connected ? '#86efac' : '#fde047') . '; padding:10px 14px; border-radius:6px; margin-bottom:16px; color:' . ($is_connected ? '#15803d' : '#854d0e') . ';">';
                echo ($is_connected ? '✅ الاتصال ناجح' : '⚠️ تحقق من البيانات') . ' — ' . esc_html(json_encode($um_body));
                echo '</div>';
            }
        }

        echo '<form method="post">';
        wp_nonce_field('pge_ultramsg_settings_nonce');
        echo '<table class="form-table" style="direction:rtl;">';

        echo '<tr>';
        echo '<th scope="row"><label for="pge_um_instance">🆔 Instance ID</label></th>';
        echo '<td>';
        echo '<input type="text" id="pge_um_instance" name="pge_ultramsg_instance_id"
                     value="' . esc_attr($um_instance_id) . '"
                     class="regular-text" style="width:300px;" placeholder="instance12345">';
        echo '<p class="description">تجده في لوحة UltraMsg: My Instances → Instance ID</p>';
        echo '</td></tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="pge_um_token">🔑 Token</label></th>';
        echo '<td>';
        echo '<input type="text" id="pge_um_token" name="pge_ultramsg_token"
                     value="' . esc_attr($um_token) . '"
                     class="regular-text" style="width:400px;" placeholder="token من لوحة UltraMsg">';
        echo '<p class="description">تجده في لوحة UltraMsg: My Instances → Token</p>';
        echo '</td></tr>';

        $um_country_code = (string) get_option('pge_cartat_country_code', '966');
        echo '<tr>';
        echo '<th scope="row"><label for="pge_um_country_code">🌍 كود الدولة</label></th>';
        echo '<td>';
        echo '<input type="text" id="pge_um_country_code" name="pge_cartat_country_code"
                     value="' . esc_attr($um_country_code) . '"
                     class="small-text" placeholder="966" maxlength="5">';
        echo '<p class="description">مثال: 966 للسعودية، 962 للأردن، 970 لفلسطين — يُستخدم للأرقام المحلية التي تبدأ بـ 0</p>';
        echo '</td></tr>';

        echo '</table>';
        echo '<p><input type="submit" name="pge_ultramsg_save" class="button button-primary" value="💾 حفظ إعدادات UltraMsg"></p>';
        echo '</form>';

        // اختبار الإرسال عبر UltraMsg
        echo '<hr>';
        echo '<h3>📤 اختبار الإرسال عبر UltraMsg</h3>';
        $um_test_send_result = null;
        if (isset($_POST['pge_ultramsg_test_send']) && check_admin_referer('pge_ultramsg_test_nonce') && !empty($um_instance_id) && !empty($um_token)) {
            $t_phone   = preg_replace('/\D+/', '', sanitize_text_field($_POST['um_test_phone'] ?? ''));
            if (str_starts_with($t_phone, '00')) $t_phone = substr($t_phone, 2);
            $t_message = sanitize_textarea_field($_POST['um_test_message'] ?? 'رسالة تجريبية من UltraMsg ✅');
            $send_res  = wp_remote_post('https://api.ultramsg.com/' . $um_instance_id . '/messages/chat', [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body'    => http_build_query(['token' => $um_token, 'to' => $t_phone, 'body' => $t_message]),
                'timeout' => 20,
            ]);
            if (is_wp_error($send_res)) {
                $um_test_send_result = ['ok' => false, 'msg' => $send_res->get_error_message()];
            } else {
                $b = json_decode(wp_remote_retrieve_body($send_res), true);
                $ok = ($b['sent'] ?? '') === 'true';
                $um_test_send_result = ['ok' => $ok, 'msg' => $ok ? '✅ تم الإرسال! ID: ' . ($b['id'] ?? '—') . " | إلى: $t_phone" : '❌ فشل: ' . json_encode($b)];
            }
        }
        if ($um_test_send_result) {
            $c = $um_test_send_result['ok'] ? '#f0fdf4; border:1px solid #bbf7d0; color:#15803d' : '#fef2f2; border:1px solid #fecaca; color:#dc2626';
            echo '<div style="background:' . $c . '; padding:12px; border-radius:6px; margin-bottom:16px;">' . esc_html($um_test_send_result['msg']) . '</div>';
        }
        if (!empty($um_instance_id) && !empty($um_token)) {
            echo '<form method="post" style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:16px; max-width:480px;">';
            wp_nonce_field('pge_ultramsg_test_nonce');
            echo '<table class="form-table" style="direction:rtl; margin:0;">';
            echo '<tr><th style="width:130px;">📱 رقم الجوال</th><td><input type="text" name="um_test_phone" class="regular-text" placeholder="972599XXXXXX" value="' . esc_attr($_POST['um_test_phone'] ?? '') . '"></td></tr>';
            echo '<tr><th>✉️ نص الرسالة</th><td><textarea name="um_test_message" rows="3" class="large-text">' . esc_textarea($_POST['um_test_message'] ?? 'رسالة تجريبية من UltraMsg ✅') . '</textarea></td></tr>';
            echo '</table>';
            echo '<p><input type="submit" name="pge_ultramsg_test_send" class="button button-secondary" value="📨 إرسال تجريبي"></p>';
            echo '</form>';
        } else {
            echo '<p style="color:#9ca3af;">أدخل Instance ID و Token أولاً.</p>';
        }

        echo '</div></div>'; // end ultramsg section + card

        // ── قسم Cartat ─────────────────────────────────────────────────────
        $cartat_display = $active_provider === 'cartat' ? 'block' : 'none';
        echo '<div id="pge-cartat-section" style="display:' . $cartat_display . ';">';
        // Script للتبديل الفوري بدون reload
        echo '<script>
        document.addEventListener("DOMContentLoaded", function(){
            document.querySelectorAll("[name=pge_wa_provider]").forEach(function(r){
                r.addEventListener("change", function(){
                    document.getElementById("pge-ultramsg-section").style.display = this.value==="ultramsg" ? "block" : "none";
                    document.getElementById("pge-cartat-section").style.display   = this.value==="cartat"   ? "block" : "none";
                });
            });
        });
        </script>';

        echo '<div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:16px; margin:16px 0; color:#1e40af;">';
        echo '<strong>🔗 Webhook URL</strong> — أدخل هذا الرابط في إعدادات Cartat:<br>';
        echo '<code style="background:#fff; border:1px solid #bfdbfe; padding:6px 12px; display:inline-block; margin-top:8px; border-radius:4px; font-size:13px;">' . esc_url($webhook_url) . '</code>';
        echo '<br><small style="margin-top:6px; display:block;">تأكد من تفعيل <strong>messages_events = true</strong> في إعدادات Cartat.</small>';
        echo '</div>';

        echo '<form method="post">';
        wp_nonce_field('pge_cartat_settings_nonce');
        echo '<table class="form-table" style="direction:rtl;">';

        echo '<tr>';
        echo '<th scope="row"><label for="pge_cartat_api_token">🔑 API Token</label></th>';
        echo '<td>';
        echo '<input type="text" id="pge_cartat_api_token" name="pge_cartat_api_token"
                     value="' . esc_attr($token) . '"
                     class="regular-text"
                     placeholder="Bearer token من لوحة Cartat"
                     style="width:400px;">';
        echo '<p class="description">احصل عليه من <a href="https://app.cartat.net/store/api" target="_blank">app.cartat.net/store/api</a></p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="pge_cartat_country_code">🌍 كود الدولة</label></th>';
        echo '<td>';
        echo '<input type="text" id="pge_cartat_country_code" name="pge_cartat_country_code"
                     value="' . esc_attr($country_code) . '"
                     class="small-text"
                     placeholder="966"
                     maxlength="5">';
        echo '<p class="description">مثال: 966 للسعودية، 962 للأردن، 970 لفلسطين</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';
        echo '<p><input type="submit" name="pge_cartat_save" class="button button-primary" value="💾 حفظ الإعدادات"></p>';
        echo '</form>';

        // ── اختبار الاتصال ──────────────────────────────────────────────────
        echo '<hr>';
        echo '<h2>🧪 اختبار الاتصال</h2>';
        if (!empty($token)) {
            $response = wp_remote_get('https://api.cartat.net/instance/settings', [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'timeout' => 10,
            ]);
            if (is_wp_error($response)) {
                echo '<div style="color:#dc2626;">❌ خطأ: ' . esc_html($response->get_error_message()) . '</div>';
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 200 && isset($body['webhook'])) {
                    $wh = $body['webhook'];
                    echo '<div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:12px; border-radius:6px; color:#15803d;">';
                    echo '✅ الاتصال ناجح!<br>';
                    echo '<strong>Webhook URL المُضبوط في Cartat:</strong> ' . esc_html($wh['webhook_url'] ?? '—') . '<br>';
                    echo '<strong>messages_events:</strong> ' . (($wh['messages_events'] ?? false) ? '✅ مفعّل' : '❌ معطّل') . '<br>';
                    echo '<strong>is_active:</strong> '       . (($wh['is_active']      ?? false) ? '✅' : '❌');
                    echo '</div>';
                } else {
                    echo '<div style="color:#dc2626;">⚠️ رمز الاستجابة: ' . esc_html($code) . ' | ' . esc_html(json_encode($body)) . '</div>';
                }
            }
        } else {
            echo '<p style="color:#9ca3af;">أدخل الـ API Token أولاً ثم احفظ.</p>';
        }

        // ── اختبار الإرسال ──────────────────────────────────────────────────────
        echo '<hr>';
        echo '<h2>📤 اختبار الإرسال</h2>';

        // معالجة إرسال رسالة تجريبية
        $test_result = null;
        if (isset($_POST['pge_cartat_test_send']) && check_admin_referer('pge_cartat_test_nonce') && !empty($token)) {
            $test_phone   = sanitize_text_field($_POST['test_phone']   ?? '');
            $test_message = sanitize_textarea_field($_POST['test_message'] ?? 'رسالة تجريبية من منصة مناسبات ✅');
            $test_type    = sanitize_text_field($_POST['test_type']    ?? 'text');
            $test_img_url = esc_url_raw($_POST['test_img_url'] ?? '');

            // تنسيق الرقم (يعالج: 00XXX / 0XXX / +XXX / XXX)
            $phone_norm = preg_replace('/\D+/', '', $test_phone);
            if (str_starts_with($phone_norm, '00')) {
                $phone_norm = substr($phone_norm, 2);          // 00972XXX → 972XXX
            } elseif (str_starts_with($phone_norm, '0')) {
                $phone_norm = $country_code . substr($phone_norm, 1); // 0599XXX → 970599XXX
            } elseif (!str_starts_with($phone_norm, $country_code)) {
                $phone_norm = $country_code . $phone_norm;     // 599XXX → 970599XXX
            }

            if ($test_type === 'media' && $test_img_url) {
                $api_body = ['number' => $phone_norm, 'media_url' => $test_img_url, 'caption' => $test_message];
                $endpoint = 'https://api.cartat.net/message/media';
            } else {
                $api_body = ['number' => $phone_norm, 'message' => $test_message];
                $endpoint = 'https://api.cartat.net/message/text';
            }

            $api_res = wp_remote_post($endpoint, [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                    'Expect'        => '',
                ],
                'body'        => wp_json_encode($api_body),
                'timeout'     => 20,
                'httpversion' => '1.1',
                'sslverify'   => true,
            ]);

            if (is_wp_error($api_res)) {
                $test_result = ['ok' => false, 'msg' => $api_res->get_error_message() . " | الرقم المُرسَل: $phone_norm"];
            } else {
                $api_body_res = json_decode(wp_remote_retrieve_body($api_res), true);
                $ok = ($api_body_res['status'] ?? '') === 'success';
                $test_result = [
                    'ok'  => $ok,
                    'msg' => $ok
                        ? "✅ تم الإرسال! ID: " . ($api_body_res['id'] ?? '—') . " | إلى: $phone_norm"
                        : "❌ فشل: " . wp_json_encode($api_body_res) . " | الرقم المُرسَل للـ API: $phone_norm",
                ];
            }
        }

        if ($test_result) {
            $color = $test_result['ok'] ? '#f0fdf4; border:1px solid #bbf7d0; color:#15803d' : '#fef2f2; border:1px solid #fecaca; color:#dc2626';
            echo '<div style="background:' . $color . '; padding:12px; border-radius:6px; margin-bottom:16px;">' . esc_html($test_result['msg']) . '</div>';
        }

        if (!empty($token)) {
            echo '<form method="post" style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:20px; max-width:540px;">';
            wp_nonce_field('pge_cartat_test_nonce');

            echo '<table class="form-table" style="direction:rtl; margin:0;">';

            echo '<tr>';
            echo '<th style="width:140px;"><label for="test_phone">📱 رقم الجوال</label></th>';
            echo '<td><input type="text" id="test_phone" name="test_phone" class="regular-text"
                         placeholder="05XXXXXXXX" value="' . esc_attr($_POST['test_phone'] ?? '') . '">
                  <p class="description">رقم واتساب للاختبار (سعودي أو بكود دولي)</p></td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th><label for="test_type">📋 نوع الرسالة</label></th>';
            echo '<td>';
            $sel_text  = (($_POST['test_type'] ?? 'text') === 'text')  ? 'selected' : '';
            $sel_media = (($_POST['test_type'] ?? 'text') === 'media') ? 'selected' : '';
            echo '<select id="test_type" name="test_type" onchange="document.getElementById(\'img_row\').style.display=this.value===\'media\'?\'table-row\':\'none\'">
                    <option value="text" '  . $sel_text  . '>نص فقط</option>
                    <option value="media" ' . $sel_media . '>صورة + نص (media)</option>
                  </select>';
            echo '</td>';
            echo '</tr>';

            $img_display = (($_POST['test_type'] ?? 'text') === 'media') ? 'table-row' : 'none';
            echo '<tr id="img_row" style="display:' . $img_display . ';">';
            echo '<th><label for="test_img_url">🖼️ رابط الصورة</label></th>';
            echo '<td><input type="url" id="test_img_url" name="test_img_url" class="regular-text"
                         placeholder="https://..." value="' . esc_attr($_POST['test_img_url'] ?? '') . '">
                  <p class="description">PNG أو JPEG — يجب أن يكون الرابط عاماً ويمكن لـ Cartat الوصول إليه</p></td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th><label for="test_message">✉️ نص الرسالة</label></th>';
            echo '<td><textarea id="test_message" name="test_message" rows="4" class="large-text">'
                . esc_textarea($_POST['test_message'] ?? 'رسالة تجريبية من منصة مناسبات ✅') . '</textarea></td>';
            echo '</tr>';

            echo '</table>';
            echo '<p style="margin-top:12px;"><input type="submit" name="pge_cartat_test_send" class="button button-secondary" value="📨 إرسال رسالة تجريبية"></p>';
            echo '</form>';
        } else {
            echo '<p style="color:#9ca3af;">أدخل الـ API Token أولاً لتفعيل خاصية الاختبار.</p>';
        }

        echo '</div>'; // end cartat section
        echo '</div>'; // end wrap
    }
}

new PGE_Admin_Controller();
