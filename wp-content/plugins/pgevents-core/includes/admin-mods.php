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
}

new PGE_Admin_Controller();
