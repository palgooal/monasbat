<?php

/**
 * Plugin Name: PgEvents Core
 * Description: المحرك البرمجي لنظام المناسبات - شركة بال قول.
 * Version: 1.0.0
 * Author: Pal Goal Team
 */

if (!defined('ABSPATH')) exit;

define('PGE_URL', plugin_dir_url(__FILE__));
define('PGE_PATH', plugin_dir_path(__FILE__));

// 1. استدعاء المكونات الأساسية (Logic)
require_once PGE_PATH . 'includes/helpers.php';
require_once PGE_PATH . 'includes/cpts.php';
require_once PGE_PATH . 'includes/metaboxes.php';
require_once PGE_PATH . 'includes/user-profiles.php';
require_once PGE_PATH . 'includes/rsvp-handler.php';
require_once PGE_PATH . 'includes/rsvp-migration.php';

// Schema كتالوج الباقات والخدمات (الخطوة الأولى فقط — لا CRUD ولا واجهة إدارة بعد)
require_once PGE_PATH . 'includes/class-mon-catalog-schema.php';
require_once PGE_PATH . 'includes/class-pge-catalog.php';

// صفحة إدارة الباقات (خطوة النموذج فقط — عرض HTML بلا معالجة $_POST وبلا
// استدعاء لـ PGE_Catalog::create_plan() بعد؛ لا حفظ ولا رسائل نجاح/فشل)
add_action('admin_menu', function () {
    add_menu_page(
        'إدارة الباقات',
        'الباقات',
        'manage_options',
        'pge-catalog-plans',
        'pge_render_catalog_plans_page',
        'dashicons-products',
        58
    );

    add_submenu_page(
        'pge-catalog-plans',
        'مستويات الباقات',
        'مستويات الباقات',
        'manage_options',
        'pge-catalog-tiers',
        'pge_render_catalog_tiers_page'
    );
});

function pge_render_catalog_tiers_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('ليس لديك صلاحية للوصول إلى هذه الصفحة.', 'pgevents'));
    }

    $notice_type = '';
    $notice_message = '';

    $validate_salla_fields = static function ($product_id, $sku, $url) {
        $product_id = trim(sanitize_text_field((string) $product_id));
        if (!is_string($sku)) {
            return [
                'valid'      => false,
                'message'    => 'رمز SKU في سلة غير صالح.',
                'product_id' => $product_id,
                'sku'        => '',
                'url'        => trim((string) $url),
            ];
        }
        $sku = trim(sanitize_text_field($sku));
        if (strlen($product_id) > 64 || ($product_id !== '' && preg_match('/\s/u', $product_id))) {
            return [
                'valid'      => false,
                'message'    => 'معرّف منتج سلة غير صالح.',
                'product_id' => $product_id,
                'sku'        => $sku,
                'url'        => trim((string) $url),
            ];
        }

        if (strlen($sku) > 100 || ($sku !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $sku))) {
            return [
                'valid'      => false,
                'message'    => 'رمز SKU في سلة غير صالح.',
                'product_id' => $product_id,
                'sku'        => $sku,
                'url'        => trim((string) $url),
            ];
        }

        $url = trim((string) $url);
        if ($url !== '') {
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                return [
                    'valid'      => false,
                    'message'    => 'رابط سلة غير صالح.',
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'url'        => $url,
                ];
            }

            if (strtolower((string) $parts['scheme']) !== 'https') {
                return [
                    'valid'      => false,
                    'message'    => 'رابط سلة يجب أن يستخدم HTTPS.',
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'url'        => $url,
                ];
            }

            $sanitized_url = esc_url_raw($url, ['https']);
            if ($sanitized_url === '' || strlen($sanitized_url) > 255) {
                return [
                    'valid'      => false,
                    'message'    => 'رابط سلة غير صالح.',
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'url'        => $url,
                ];
            }
            $url = $sanitized_url;
        }

        return [
            'valid'      => true,
            'message'    => '',
            'product_id' => $product_id,
            'sku'        => $sku,
            'url'        => $url,
        ];
    };

    $tier_form_values = [
        'name'             => '',
        'tier_key'         => '',
        'price'            => '0.00',
        'currency'         => 'SAR',
        'salla_product_id' => '',
        'salla_sku'        => '',
        'salla_url'        => '',
        'status'           => 'active',
        'sort_order'       => '0',
    ];

    $tier_edit_form_values = [
        'name'             => '',
        'tier_key'         => '',
        'price'            => '',
        'currency'         => 'SAR',
        'salla_product_id' => '',
        'salla_sku'        => '',
        'salla_url'        => '',
        'status'           => 'active',
        'sort_order'       => '0',
    ];

    $tier_create_post_handled = false;
    $tier_post_handled = false;

    $editing_tier_id = 0;
    $editing_tier = null;

    $plans = PGE_Catalog::get_plans();
    if (!is_array($plans)) {
        $plans = [];
    }

    $selected_plan_id = 0;
    $selected_plan = null;
    $tiers = [];

    if (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'create_tier'
        && isset($_POST['submit_create_tier'])
    ) {
        check_admin_referer('pge_create_catalog_tier', 'pge_catalog_tier_nonce');

        $tier_create_post_handled = true;
        $tier_post_handled = true;

        $posted_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));
        $posted_plan = ($posted_plan_id > 0) ? PGE_Catalog::get_plan($posted_plan_id) : null;

        if ($posted_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';

            $selected_plan_id = 0;
            $selected_plan = null;
            $tiers = [];
        } else {
            $selected_plan_id = $posted_plan_id;
            $selected_plan = $posted_plan;

            $tier_form_values = [
                'name'             => wp_unslash($_POST['name'] ?? ''),
                'tier_key'         => wp_unslash($_POST['tier_key'] ?? ''),
                'price'            => wp_unslash($_POST['price'] ?? ''),
                'currency'         => wp_unslash($_POST['currency'] ?? ''),
                'salla_product_id' => wp_unslash($_POST['salla_product_id'] ?? ''),
                'salla_sku'        => wp_unslash($_POST['salla_sku'] ?? ''),
                'salla_url'        => wp_unslash($_POST['salla_url'] ?? ''),
                'status'           => wp_unslash($_POST['status'] ?? ''),
                'sort_order'       => wp_unslash($_POST['sort_order'] ?? ''),
            ];

            $salla_validation = $validate_salla_fields(
                $tier_form_values['salla_product_id'],
                $tier_form_values['salla_sku'],
                $tier_form_values['salla_url']
            );
            $tier_form_values['salla_product_id'] = $salla_validation['product_id'];
            $tier_form_values['salla_sku'] = $salla_validation['sku'];
            $tier_form_values['salla_url'] = $salla_validation['url'];

            if (!$salla_validation['valid']) {
                $notice_type = 'error';
                $notice_message = $salla_validation['message'];
            } else {
                $salla_owner = $tier_form_values['salla_sku'] !== ''
                    ? PGE_Catalog::get_tier_by_salla_sku($tier_form_values['salla_sku'])
                    : null;

                if (is_array($salla_owner)) {
                    $notice_type = 'error';
                    $notice_message = 'رمز SKU في سلة مستخدم مسبقًا في مستوى آخر.';
                } else {
                    $created_tier = PGE_Catalog::create_tier([
                        'plan_id'          => $posted_plan_id,
                        'tier_key'         => $tier_form_values['tier_key'],
                        'name'             => $tier_form_values['name'],
                        'price'            => $tier_form_values['price'],
                        'currency'         => $tier_form_values['currency'],
                        'salla_product_id' => $tier_form_values['salla_product_id'],
                        'salla_sku'        => $tier_form_values['salla_sku'],
                        'salla_url'        => $tier_form_values['salla_url'],
                        'status'           => $tier_form_values['status'],
                        'sort_order'       => $tier_form_values['sort_order'],
                    ]);

                    if (is_array($created_tier)) {
                        $notice_type = 'success';
                        $notice_message = 'تمت إضافة المستوى بنجاح. تم حفظ ربط سلة بنجاح.';

                        $tier_form_values = [
                            'name'             => '',
                            'tier_key'         => '',
                            'price'            => '0.00',
                            'currency'         => 'SAR',
                            'salla_product_id' => '',
                            'salla_sku'        => '',
                            'salla_url'        => '',
                            'status'           => 'active',
                            'sort_order'       => '0',
                        ];
                    } else {
                        $notice_type = 'error';
                        $notice_message = 'تعذر حفظ المستوى.';
                    }
                }
            }

            $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
            if (!is_array($tiers)) {
                $tiers = [];
            }
        }
    } elseif (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'update_tier'
        && isset($_POST['submit_update_tier'])
    ) {
        $tier_post_handled = true;

        $posted_tier_id = absint(wp_unslash($_POST['tier_id'] ?? 0));

        check_admin_referer('pge_update_catalog_tier_' . $posted_tier_id, 'pge_catalog_tier_update_nonce');

        $posted_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));

        $posted_plan = ($posted_plan_id > 0) ? PGE_Catalog::get_plan($posted_plan_id) : null;
        $posted_tier = ($posted_tier_id > 0) ? PGE_Catalog::get_tier($posted_tier_id) : null;

        if ($posted_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';

            $selected_plan_id = 0;
            $selected_plan = null;
            $editing_tier_id = 0;
            $editing_tier = null;
            $tiers = [];
        } else {
            $selected_plan_id = $posted_plan_id;
            $selected_plan = $posted_plan;

            if ($posted_tier === null) {
                $notice_type = 'error';
                $notice_message = 'تعذر العثور على المستوى المطلوب تعديله.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } elseif (absint($posted_tier['plan_id']) !== $posted_plan_id) {
                $notice_type = 'error';
                $notice_message = 'المستوى المطلوب لا يتبع الباقة المختارة.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } else {
                $tier_edit_form_values = [
                    'name'             => wp_unslash($_POST['name'] ?? ''),
                    'tier_key'         => wp_unslash($_POST['tier_key'] ?? ''),
                    'price'            => wp_unslash($_POST['price'] ?? ''),
                    'currency'         => wp_unslash($_POST['currency'] ?? ''),
                    'salla_product_id' => wp_unslash($_POST['salla_product_id'] ?? ''),
                    'salla_sku'        => wp_unslash($_POST['salla_sku'] ?? ''),
                    'salla_url'        => wp_unslash($_POST['salla_url'] ?? ''),
                    'status'           => wp_unslash($_POST['status'] ?? ''),
                    'sort_order'       => wp_unslash($_POST['sort_order'] ?? ''),
                ];

                $salla_validation = $validate_salla_fields(
                    $tier_edit_form_values['salla_product_id'],
                    $tier_edit_form_values['salla_sku'],
                    $tier_edit_form_values['salla_url']
                );
                $tier_edit_form_values['salla_product_id'] = $salla_validation['product_id'];
                $tier_edit_form_values['salla_sku'] = $salla_validation['sku'];
                $tier_edit_form_values['salla_url'] = $salla_validation['url'];

                if (!$salla_validation['valid']) {
                    $notice_type = 'error';
                    $notice_message = $salla_validation['message'];

                    $editing_tier_id = $posted_tier_id;
                    $editing_tier = $posted_tier;
                } else {
                    $salla_owner = $tier_edit_form_values['salla_sku'] !== ''
                        ? PGE_Catalog::get_tier_by_salla_sku($tier_edit_form_values['salla_sku'])
                        : null;

                    if (is_array($salla_owner) && absint($salla_owner['id']) !== $posted_tier_id) {
                        $notice_type = 'error';
                        $notice_message = 'رمز SKU في سلة مستخدم مسبقًا في مستوى آخر.';

                        $editing_tier_id = $posted_tier_id;
                        $editing_tier = $posted_tier;
                    } else {
                        $updated_tier = PGE_Catalog::update_tier(
                            $posted_tier_id,
                            [
                                'plan_id'          => $posted_plan_id,
                                'tier_key'         => $tier_edit_form_values['tier_key'],
                                'name'             => $tier_edit_form_values['name'],
                                'price'            => $tier_edit_form_values['price'],
                                'currency'         => $tier_edit_form_values['currency'],
                                'salla_product_id' => $tier_edit_form_values['salla_product_id'],
                                'salla_sku'        => $tier_edit_form_values['salla_sku'],
                                'salla_url'        => $tier_edit_form_values['salla_url'],
                                'status'           => $tier_edit_form_values['status'],
                                'sort_order'       => $tier_edit_form_values['sort_order'],
                            ]
                        );

                        if (is_array($updated_tier)) {
                            $notice_type = 'success';
                            $notice_message = 'تم تحديث المستوى بنجاح. تم حفظ ربط سلة بنجاح.';

                            $editing_tier_id = absint($updated_tier['id']);
                            $editing_tier = $updated_tier;

                            $tier_edit_form_values = [
                                'name'             => $updated_tier['name'],
                                'tier_key'         => $updated_tier['tier_key'],
                                'price'            => $updated_tier['price'],
                                'currency'         => $updated_tier['currency'],
                                'salla_product_id' => $updated_tier['salla_product_id'] ?? '',
                                'salla_sku'        => $updated_tier['salla_sku'] ?? '',
                                'salla_url'        => $updated_tier['salla_url'] ?? '',
                                'status'           => $updated_tier['status'],
                                'sort_order'       => $updated_tier['sort_order'],
                            ];
                        } else {
                            $notice_type = 'error';
                            $notice_message = 'تعذر حفظ المستوى.';

                            $editing_tier_id = $posted_tier_id;
                            $editing_tier = $posted_tier;
                        }
                    }
                }

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            }
        }
    } elseif (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'delete_tier'
        && isset($_POST['submit_delete_tier'])
    ) {
        $tier_post_handled = true;

        $posted_tier_id = absint(wp_unslash($_POST['tier_id'] ?? 0));

        check_admin_referer('pge_delete_catalog_tier_' . $posted_tier_id, 'pge_catalog_tier_delete_nonce');

        $posted_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));

        $posted_plan = ($posted_plan_id > 0) ? PGE_Catalog::get_plan($posted_plan_id) : null;
        $posted_tier = ($posted_tier_id > 0) ? PGE_Catalog::get_tier($posted_tier_id) : null;

        if ($posted_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';

            $selected_plan_id = 0;
            $selected_plan = null;
            $editing_tier_id = 0;
            $editing_tier = null;
            $tiers = [];
        } else {
            $selected_plan_id = $posted_plan_id;
            $selected_plan = $posted_plan;

            if ($posted_tier === null) {
                $notice_type = 'error';
                $notice_message = 'تعذر العثور على المستوى المطلوب حذفه.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } elseif (absint($posted_tier['plan_id']) !== $posted_plan_id) {
                $notice_type = 'error';
                $notice_message = 'المستوى المطلوب لا يتبع الباقة المختارة.';

                $editing_tier_id = 0;
                $editing_tier = null;

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            } else {
                $deleted = PGE_Catalog::delete_tier($posted_tier_id);

                if ($deleted === true) {
                    $notice_type = 'success';
                    $notice_message = 'تم حذف المستوى بنجاح.';

                    $editing_tier_id = 0;
                    $editing_tier = null;

                    $tier_edit_form_values = [
                        'name'             => '',
                        'tier_key'         => '',
                        'price'            => '',
                        'currency'         => 'SAR',
                        'salla_product_id' => '',
                        'salla_sku'        => '',
                        'salla_url'        => '',
                        'status'           => 'active',
                        'sort_order'       => '0',
                    ];
                } else {
                    $notice_type = 'error';
                    $notice_message = 'تعذر حذف المستوى.';

                    $editing_tier_id = 0;
                    $editing_tier = null;
                }

                $tiers = PGE_Catalog::get_plan_tiers($posted_plan_id);
                if (!is_array($tiers)) {
                    $tiers = [];
                }
            }
        }
    }

    if (!$tier_post_handled) {
        $selected_plan_id = absint(wp_unslash($_GET['plan_id'] ?? 0));

        if ($selected_plan_id > 0) {
            $selected_plan = PGE_Catalog::get_plan($selected_plan_id);

            if ($selected_plan === null) {
                $notice_type = 'error';
                $notice_message = 'تعذر العثور على الباقة المطلوبة.';
            }
        }

        if (is_array($selected_plan)) {
            $tiers = PGE_Catalog::get_plan_tiers($selected_plan_id);
            if (!is_array($tiers)) {
                $tiers = [];
            }
        }

        $editing_tier_id = absint(wp_unslash($_GET['edit_tier'] ?? 0));

        if (is_array($selected_plan) && $editing_tier_id > 0) {
            $editing_tier = PGE_Catalog::get_tier($editing_tier_id);

            if ($editing_tier === null) {
                $notice_type = 'error';
                $notice_message = 'تعذر العثور على المستوى المطلوب تعديله.';
                $editing_tier = null;
                $editing_tier_id = 0;
            } elseif (absint($editing_tier['plan_id']) !== $selected_plan_id) {
                $notice_type = 'error';
                $notice_message = 'المستوى المطلوب لا يتبع الباقة المختارة.';
                $editing_tier = null;
                $editing_tier_id = 0;
            } else {
                $tier_edit_form_values = [
                    'name'             => $editing_tier['name'],
                    'tier_key'         => $editing_tier['tier_key'],
                    'price'            => $editing_tier['price'],
                    'currency'         => $editing_tier['currency'],
                    'salla_product_id' => $editing_tier['salla_product_id'] ?? '',
                    'salla_sku'        => $editing_tier['salla_sku'] ?? '',
                    'salla_url'        => $editing_tier['salla_url'] ?? '',
                    'status'           => $editing_tier['status'],
                    'sort_order'       => $editing_tier['sort_order'],
                ];
            }
        }
    }

    $plan_type_labels = [
        'personal' => 'شخصية',
        'business' => 'أعمال',
    ];
    $status_labels = [
        'active' => 'نشطة',
        'inactive' => 'غير نشطة',
    ];
    $tier_status_labels = [
        'active' => 'نشط',
        'inactive' => 'غير نشط',
    ];
?>
    <div class="wrap">
        <h1><?php esc_html_e('مستويات الباقات', 'pgevents'); ?></h1>

        <?php if ($notice_message !== ''): ?>
            <?php if ($notice_type === 'success'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($notice_message); ?></p>
                </div>
            <?php elseif ($notice_type === 'error'): ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($notice_message); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($plans)): ?>
            <div class="notice notice-info inline">
                <p><?php esc_html_e('لا توجد باقات متاحة لإدارة مستوياتها.', 'pgevents'); ?></p>
            </div>
        <?php else: ?>
            <form method="get">
                <input type="hidden" name="page" value="pge-catalog-tiers">

                <label for="pge_tiers_plan_id"><?php esc_html_e('اختر الباقة', 'pgevents'); ?></label>
                <select id="pge_tiers_plan_id" name="plan_id">
                    <option value=""><?php esc_html_e('— اختر باقة —', 'pgevents'); ?></option>
                    <?php foreach ($plans as $plan): ?>
                        <option value="<?php echo esc_attr(absint($plan['id'])); ?>" <?php echo selected($selected_plan_id, absint($plan['id']), false); ?>>
                            <?php echo esc_html($plan['name']); ?> — <?php echo esc_html($plan['plan_key']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php submit_button('عرض الباقة', 'secondary', 'submit', false); ?>
            </form>

            <?php if (is_array($selected_plan)): ?>
                <h2><?php esc_html_e('الباقة المختارة', 'pgevents'); ?></h2>
                <p>
                    <?php esc_html_e('اسم الباقة:', 'pgevents'); ?>
                    <?php echo esc_html($selected_plan['name']); ?>
                </p>
                <p>
                    <?php esc_html_e('مفتاح الباقة:', 'pgevents'); ?>
                    <?php echo esc_html($selected_plan['plan_key']); ?>
                </p>
                <p>
                    <?php esc_html_e('نوع الباقة:', 'pgevents'); ?>
                    <?php echo esc_html($plan_type_labels[$selected_plan['plan_type']] ?? $selected_plan['plan_type']); ?>
                </p>
                <p>
                    <?php esc_html_e('الحالة:', 'pgevents'); ?>
                    <?php echo esc_html($status_labels[$selected_plan['status']] ?? $selected_plan['status']); ?>
                </p>

                <h2><?php esc_html_e('إضافة مستوى جديد', 'pgevents'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('pge_create_catalog_tier', 'pge_catalog_tier_nonce'); ?>
                    <input type="hidden" name="pge_catalog_action" value="create_tier">
                    <input type="hidden" name="plan_id" value="<?php echo esc_attr(absint($selected_plan['id'])); ?>">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="pge-tier-create-name"><?php esc_html_e('اسم المستوى', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="pge-tier-create-name" name="name" class="regular-text" value="<?php echo esc_attr($tier_form_values['name']); ?>" maxlength="190" required>
                                <p class="description"><?php esc_html_e('الاسم الظاهر للمستخدم، مثل: 100 مدعو.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_key"><?php esc_html_e('مفتاح المستوى', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="pge_tier_key" name="tier_key" class="regular-text" value="<?php echo esc_attr($tier_form_values['tier_key']); ?>" maxlength="64" required>
                                <p class="description"><?php esc_html_e('أحرف إنجليزية صغيرة وأرقام وشرطة أو شرطة سفلية فقط.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_price"><?php esc_html_e('السعر', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="pge_tier_price" name="price" min="0" max="99999999.99" step="0.01" value="<?php echo esc_attr($tier_form_values['price']); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_currency"><?php esc_html_e('العملة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <select id="pge_tier_currency" name="currency" required>
                                    <option value="SAR" <?php echo selected($tier_form_values['currency'], 'SAR', false); ?>>SAR</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_salla_product_id"><?php esc_html_e('معرّف منتج سلة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="pge_tier_salla_product_id" name="salla_product_id" class="regular-text" value="<?php echo esc_attr($tier_form_values['salla_product_id']); ?>" maxlength="64">
                                <p class="description"><?php esc_html_e('معرّف المنتج المقابل لهذا المستوى داخل متجر سلة.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_salla_sku"><?php esc_html_e('رمز SKU في سلة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="pge_tier_salla_sku" name="salla_sku" class="regular-text" value="<?php echo esc_attr($tier_form_values['salla_sku']); ?>" maxlength="100" pattern="[A-Za-z0-9_-]+">
                                <p class="description"><?php esc_html_e('رمز الخيار المقابل لهذا المستوى داخل منتج سلة، مثل HALWA-CLASSIC-100.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_salla_url"><?php esc_html_e('رابط منتج سلة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="url" id="pge_tier_salla_url" name="salla_url" class="regular-text" value="<?php echo esc_attr($tier_form_values['salla_url']); ?>" maxlength="255" placeholder="https://">
                                <p class="description"><?php esc_html_e('رابط صفحة المنتج التي سينتقل إليها العميل لإكمال الشراء.', 'pgevents'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_status"><?php esc_html_e('الحالة', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <select id="pge_tier_status" name="status" required>
                                    <option value="active" <?php echo selected($tier_form_values['status'], 'active', false); ?>><?php esc_html_e('نشط', 'pgevents'); ?></option>
                                    <option value="inactive" <?php echo selected($tier_form_values['status'], 'inactive', false); ?>><?php esc_html_e('غير نشط', 'pgevents'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pge_tier_sort_order"><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="pge_tier_sort_order" name="sort_order" class="small-text" min="0" step="1" value="<?php echo esc_attr($tier_form_values['sort_order']); ?>" required>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('إضافة المستوى', 'primary', 'submit_create_tier'); ?>
                </form>

                <?php if (is_array($editing_tier)): ?>
                    <h2><?php esc_html_e('تعديل المستوى', 'pgevents'); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field('pge_update_catalog_tier_' . absint($editing_tier['id']), 'pge_catalog_tier_update_nonce'); ?>
                        <input type="hidden" name="pge_catalog_action" value="update_tier">
                        <input type="hidden" name="tier_id" value="<?php echo esc_attr(absint($editing_tier['id'])); ?>">
                        <input type="hidden" name="plan_id" value="<?php echo esc_attr(absint($selected_plan['id'])); ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="pge-tier-edit-name"><?php esc_html_e('اسم المستوى', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="pge-tier-edit-name" name="name" class="regular-text" value="<?php echo esc_attr($tier_edit_form_values['name']); ?>" maxlength="190" required>
                                    <p class="description"><?php esc_html_e('الاسم الظاهر للمستخدم، مثل: 100 مدعو.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_key"><?php esc_html_e('مفتاح المستوى', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="pge_edit_tier_key" name="tier_key" class="regular-text" value="<?php echo esc_attr($tier_edit_form_values['tier_key']); ?>" maxlength="64" required>
                                    <p class="description"><?php esc_html_e('أحرف إنجليزية صغيرة وأرقام وشرطة أو شرطة سفلية فقط.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_price"><?php esc_html_e('السعر', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="pge_edit_tier_price" name="price" min="0" max="99999999.99" step="0.01" value="<?php echo esc_attr($tier_edit_form_values['price']); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_currency"><?php esc_html_e('العملة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <select id="pge_edit_tier_currency" name="currency" required>
                                        <option value="SAR" <?php echo selected($tier_edit_form_values['currency'], 'SAR', false); ?>>SAR</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_salla_product_id"><?php esc_html_e('معرّف منتج سلة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="pge_edit_tier_salla_product_id" name="salla_product_id" class="regular-text" value="<?php echo esc_attr($tier_edit_form_values['salla_product_id']); ?>" maxlength="64">
                                    <p class="description"><?php esc_html_e('معرّف المنتج المقابل لهذا المستوى داخل متجر سلة.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_salla_sku"><?php esc_html_e('رمز SKU في سلة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="pge_edit_tier_salla_sku" name="salla_sku" class="regular-text" value="<?php echo esc_attr($tier_edit_form_values['salla_sku']); ?>" maxlength="100" pattern="[A-Za-z0-9_-]+">
                                    <p class="description"><?php esc_html_e('رمز الخيار المقابل لهذا المستوى داخل منتج سلة، مثل HALWA-CLASSIC-100.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_salla_url"><?php esc_html_e('رابط منتج سلة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="url" id="pge_edit_tier_salla_url" name="salla_url" class="regular-text" value="<?php echo esc_attr($tier_edit_form_values['salla_url']); ?>" maxlength="255" placeholder="https://">
                                    <p class="description"><?php esc_html_e('رابط صفحة المنتج التي سينتقل إليها العميل لإكمال الشراء.', 'pgevents'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_status"><?php esc_html_e('الحالة', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <select id="pge_edit_tier_status" name="status" required>
                                        <option value="active" <?php echo selected($tier_edit_form_values['status'], 'active', false); ?>><?php esc_html_e('نشط', 'pgevents'); ?></option>
                                        <option value="inactive" <?php echo selected($tier_edit_form_values['status'], 'inactive', false); ?>><?php esc_html_e('غير نشط', 'pgevents'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="pge_edit_tier_sort_order"><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="pge_edit_tier_sort_order" name="sort_order" class="small-text" min="0" step="1" value="<?php echo esc_attr($tier_edit_form_values['sort_order']); ?>" required>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button('حفظ التعديلات', 'primary', 'submit_update_tier', false); ?>
                        <?php
                        $tier_cancel_url = add_query_arg(
                            [
                                'page'    => 'pge-catalog-tiers',
                                'plan_id' => $selected_plan_id,
                            ],
                            admin_url('admin.php')
                        );
                        ?>
                        <a href="<?php echo esc_url($tier_cancel_url); ?>"><?php esc_html_e('إلغاء', 'pgevents'); ?></a>
                    </form>
                <?php endif; ?>

                <h2><?php esc_html_e('مستويات الباقة', 'pgevents'); ?></h2>
                <?php if (empty($tiers)): ?>
                    <div class="notice notice-info inline">
                        <p><?php esc_html_e('لا توجد مستويات مضافة لهذه الباقة حتى الآن.', 'pgevents'); ?></p>
                    </div>
                <?php else: ?>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('المعرّف', 'pgevents'); ?></th>
                                <th><?php esc_html_e('اسم المستوى', 'pgevents'); ?></th>
                                <th><?php esc_html_e('مفتاح المستوى', 'pgevents'); ?></th>
                                <th><?php esc_html_e('السعر', 'pgevents'); ?></th>
                                <th><?php esc_html_e('العملة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('ربط سلة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('الحالة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></th>
                                <th><?php esc_html_e('الإجراءات', 'pgevents'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tiers as $tier): ?>
                                <tr>
                                    <td><?php echo absint($tier['id']); ?></td>
                                    <td><?php echo esc_html($tier['name']); ?></td>
                                    <td><code><?php echo esc_html($tier['tier_key']); ?></code></td>
                                    <td><?php echo esc_html($tier['price']); ?></td>
                                    <td><?php echo esc_html($tier['currency']); ?></td>
                                    <td>
                                        <?php
                                        $tier_salla_product_id = trim((string) ($tier['salla_product_id'] ?? ''));
                                        $tier_salla_sku = trim((string) ($tier['salla_sku'] ?? ''));
                                        $tier_salla_url_raw = trim((string) ($tier['salla_url'] ?? ''));
                                        $tier_salla_url = $tier_salla_url_raw !== ''
                                            ? esc_url($tier_salla_url_raw, ['https'])
                                            : '';
                                        $tier_salla_url_parts = $tier_salla_url !== '' ? wp_parse_url($tier_salla_url) : false;
                                        $has_salla_product_id = $tier_salla_product_id !== '';
                                        $has_salla_sku = $tier_salla_sku !== '';
                                        $has_valid_salla_url = is_array($tier_salla_url_parts)
                                            && strtolower((string) ($tier_salla_url_parts['scheme'] ?? '')) === 'https'
                                            && !empty($tier_salla_url_parts['host']);

                                        if ($has_salla_product_id && $has_salla_sku && $has_valid_salla_url) {
                                            $salla_link_status = 'مرتبط بالكامل';
                                        } elseif ($has_salla_product_id && $has_salla_sku) {
                                            $salla_link_status = 'معرّف وSKU';
                                        } elseif ($has_salla_product_id) {
                                            $salla_link_status = 'معرّف فقط';
                                        } elseif ($has_salla_sku) {
                                            $salla_link_status = 'SKU فقط';
                                        } elseif ($has_valid_salla_url) {
                                            $salla_link_status = 'رابط فقط';
                                        } else {
                                            $salla_link_status = 'غير مرتبط';
                                        }
                                        ?>
                                        <strong<?php echo $has_salla_sku ? ' title="' . esc_attr($tier_salla_sku) . '"' : ''; ?>><?php echo esc_html($salla_link_status); ?></strong>
                                        <?php if ($has_valid_salla_url): ?>
                                            <br><a href="<?php echo esc_url($tier_salla_url, ['https']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('فتح المنتج', 'pgevents'); ?></a>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($tier_status_labels[$tier['status']] ?? $tier['status']); ?></td>
                                    <td><?php echo absint($tier['sort_order']); ?></td>
                                    <td>
                                        <?php
                                        $tier_edit_url = add_query_arg(
                                            [
                                                'page'      => 'pge-catalog-tiers',
                                                'plan_id'   => $selected_plan_id,
                                                'edit_tier' => absint($tier['id']),
                                            ],
                                            admin_url('admin.php')
                                        );
                                        ?>
                                        <a href="<?php echo esc_url($tier_edit_url); ?>"><?php esc_html_e('تعديل', 'pgevents'); ?></a>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="pge_catalog_action" value="delete_tier">
                                            <input type="hidden" name="tier_id" value="<?php echo esc_attr(absint($tier['id'])); ?>">
                                            <input type="hidden" name="plan_id" value="<?php echo esc_attr(absint($selected_plan['id'])); ?>">
                                            <?php wp_nonce_field('pge_delete_catalog_tier_' . absint($tier['id']), 'pge_catalog_tier_delete_nonce'); ?>
                                            <?php submit_button('حذف', 'delete small', 'submit_delete_tier', false); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th><?php esc_html_e('المعرّف', 'pgevents'); ?></th>
                                <th><?php esc_html_e('اسم المستوى', 'pgevents'); ?></th>
                                <th><?php esc_html_e('مفتاح المستوى', 'pgevents'); ?></th>
                                <th><?php esc_html_e('السعر', 'pgevents'); ?></th>
                                <th><?php esc_html_e('العملة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('ربط سلة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('الحالة', 'pgevents'); ?></th>
                                <th><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></th>
                                <th><?php esc_html_e('الإجراءات', 'pgevents'); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php
}

function pge_render_catalog_plans_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('غير مصرح لك بالوصول لهذه الصفحة.', 'pgevents'));
    }

    $notice_type = null;
    $notice_message = null;

    $form_plan_key = '';
    $form_name = '';
    $form_plan_type = 'personal';
    $form_status = 'active';
    $form_sort_order = '0';
    $form_features = '';

    $edit_plan = null;
    $delete_plan = null;
    $delete_post_handled = false;
    $edit_features_raw_override = null;

    $pge_parse_features_textarea = function ($raw_text) {
        $raw_text = (string) $raw_text;
        $lines = preg_split('/\r\n|\r|\n/', $raw_text);
        return is_array($lines) ? $lines : [];
    };

    $pge_decode_features_for_display = function ($stored_value) {
        if (!is_string($stored_value) || trim($stored_value) === '') {
            return '';
        }
        $decoded = json_decode($stored_value, true);
        if (!is_array($decoded)) {
            return '';
        }
        $lines = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $lines[] = $item;
            }
        }
        return implode("\n", $lines);
    };

    if (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'create_plan'
        && isset($_POST['submit_create_plan'])
    ) {
        check_admin_referer('pge_create_catalog_plan', 'pge_catalog_plan_nonce');

        $form_plan_key = isset($_POST['plan_key']) ? wp_unslash($_POST['plan_key']) : '';
        $form_name = isset($_POST['name']) ? wp_unslash($_POST['name']) : '';
        $form_plan_type = isset($_POST['plan_type']) ? wp_unslash($_POST['plan_type']) : '';
        $form_status = isset($_POST['status']) ? wp_unslash($_POST['status']) : '';
        $form_sort_order = isset($_POST['sort_order']) ? wp_unslash($_POST['sort_order']) : '';
        $form_features = isset($_POST['features']) ? wp_unslash($_POST['features']) : '';

        $created_plan = PGE_Catalog::create_plan([
            'plan_key'   => $form_plan_key,
            'name'       => $form_name,
            'plan_type'  => $form_plan_type,
            'status'     => $form_status,
            'sort_order' => $form_sort_order,
            'features'   => $pge_parse_features_textarea($form_features),
        ]);

        if (is_array($created_plan)) {
            $notice_type = 'success';
            $notice_message = 'تمت إضافة الباقة بنجاح.';

            $form_plan_key = '';
            $form_name = '';
            $form_plan_type = 'personal';
            $form_status = 'active';
            $form_sort_order = '0';
            $form_features = '';
        } else {
            $notice_type = 'error';
            $notice_message = 'تعذر إضافة الباقة. تحقق من الحقول أو من عدم تكرار مفتاح الباقة.';
        }
    } elseif (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'update_plan'
        && isset($_POST['submit_update_plan'])
    ) {
        check_admin_referer('pge_update_catalog_plan', 'pge_catalog_update_nonce');

        $update_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));

        $update_plan_key = isset($_POST['plan_key']) ? wp_unslash($_POST['plan_key']) : '';
        $update_name = isset($_POST['name']) ? wp_unslash($_POST['name']) : '';
        $update_plan_type = isset($_POST['plan_type']) ? wp_unslash($_POST['plan_type']) : '';
        $update_status = isset($_POST['status']) ? wp_unslash($_POST['status']) : '';
        $update_sort_order = isset($_POST['sort_order']) ? wp_unslash($_POST['sort_order']) : '';
        $update_features = isset($_POST['features']) ? wp_unslash($_POST['features']) : '';

        $existing_plan = ($update_plan_id > 0) ? PGE_Catalog::get_plan($update_plan_id) : null;

        if ($existing_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';
        } else {
            $updated_plan = PGE_Catalog::update_plan(
                $update_plan_id,
                [
                    'plan_key'   => $update_plan_key,
                    'name'       => $update_name,
                    'plan_type'  => $update_plan_type,
                    'status'     => $update_status,
                    'sort_order' => $update_sort_order,
                    'features'   => $pge_parse_features_textarea($update_features),
                ]
            );

            if (is_array($updated_plan)) {
                $notice_type = 'success';
                $notice_message = 'تم حفظ تعديلات الباقة بنجاح.';
                $edit_plan = $updated_plan;
            } else {
                $notice_type = 'error';
                $notice_message = 'تعذر حفظ التعديلات. تحقق من الحقول أو من عدم تكرار مفتاح الباقة.';
                $edit_plan = [
                    'id'         => $update_plan_id,
                    'plan_key'   => $update_plan_key,
                    'name'       => $update_name,
                    'plan_type'  => $update_plan_type,
                    'status'     => $update_status,
                    'sort_order' => $update_sort_order,
                ];
                $edit_features_raw_override = $update_features;
            }
        }
    } elseif (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['pge_catalog_action'])
        && wp_unslash($_POST['pge_catalog_action']) === 'delete_plan'
        && isset($_POST['submit_delete_plan'])
    ) {
        check_admin_referer('pge_delete_catalog_plan', 'pge_catalog_delete_nonce');

        $delete_post_handled = true;

        $delete_plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));

        $existing_delete_plan = ($delete_plan_id > 0) ? PGE_Catalog::get_plan($delete_plan_id) : null;

        if ($existing_delete_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';
        } else {
            $deleted = PGE_Catalog::delete_plan($delete_plan_id);

            if ($deleted === true) {
                $notice_type = 'success';
                $notice_message = 'تم حذف الباقة بنجاح.';
                $delete_plan = null;
            } else {
                $notice_type = 'error';
                $notice_message = 'تعذر حذف الباقة. قد تكون مرتبطة بمستويات أو لم تعد موجودة.';
                $delete_plan = $existing_delete_plan;
            }
        }
    }

    if (
        $edit_plan === null
        && isset($_GET['action'])
        && isset($_GET['plan_id'])
        && wp_unslash($_GET['action']) === 'edit'
    ) {
        $edit_plan_id = absint(wp_unslash($_GET['plan_id']));

        if ($edit_plan_id > 0) {
            $edit_plan = PGE_Catalog::get_plan($edit_plan_id);
        }

        if ($edit_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';
        }
    }

    if (
        !$delete_post_handled
        && $delete_plan === null
        && isset($_GET['action'])
        && isset($_GET['plan_id'])
        && wp_unslash($_GET['action']) === 'delete'
    ) {
        $delete_plan_id = absint(wp_unslash($_GET['plan_id']));

        if ($delete_plan_id > 0) {
            $delete_plan = PGE_Catalog::get_plan($delete_plan_id);
        }

        if ($delete_plan === null) {
            $notice_type = 'error';
            $notice_message = 'تعذر العثور على الباقة المطلوبة.';
        }
    }

    $plans = PGE_Catalog::get_plans();

    $edit_features_text = '';
    if (is_array($edit_plan)) {
        $edit_features_text = ($edit_features_raw_override !== null)
            ? $edit_features_raw_override
            : $pge_decode_features_for_display($edit_plan['features'] ?? null);
    }

    $plan_type_labels = [
        'personal' => 'شخصية',
        'business' => 'أعمال',
    ];
    $status_labels = [
        'active' => 'نشطة',
        'inactive' => 'غير نشطة',
    ];
?>
    <div class="wrap">
        <h1><?php esc_html_e('إدارة الباقات', 'pgevents'); ?></h1>

        <?php if ($notice_type === 'success'): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($notice_message); ?></p>
            </div>
        <?php elseif ($notice_type === 'error'): ?>
            <div class="notice notice-error">
                <p><?php echo esc_html($notice_message); ?></p>
            </div>
        <?php endif; ?>

        <?php if (is_array($edit_plan)): ?>
            <h2><?php esc_html_e('تعديل الباقة', 'pgevents'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('pge_update_catalog_plan', 'pge_catalog_update_nonce'); ?>
                <input type="hidden" name="pge_catalog_action" value="update_plan">
                <input type="hidden" name="plan_id" value="<?php echo esc_attr($edit_plan['id']); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="pge_edit_plan_key"><?php esc_html_e('مفتاح الباقة', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="pge_edit_plan_key" name="plan_key" class="regular-text" value="<?php echo esc_attr($edit_plan['plan_key']); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pge_edit_plan_name"><?php esc_html_e('اسم الباقة', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="pge_edit_plan_name" name="name" class="regular-text" value="<?php echo esc_attr($edit_plan['name']); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pge_edit_plan_type"><?php esc_html_e('نوع الباقة', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <select id="pge_edit_plan_type" name="plan_type">
                                <option value="personal" <?php selected($edit_plan['plan_type'], 'personal'); ?>><?php esc_html_e('شخصية', 'pgevents'); ?></option>
                                <option value="business" <?php selected($edit_plan['plan_type'], 'business'); ?>><?php esc_html_e('أعمال', 'pgevents'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pge_edit_plan_status"><?php esc_html_e('حالة الباقة', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <select id="pge_edit_plan_status" name="status">
                                <option value="active" <?php selected($edit_plan['status'], 'active'); ?>><?php esc_html_e('نشطة', 'pgevents'); ?></option>
                                <option value="inactive" <?php selected($edit_plan['status'], 'inactive'); ?>><?php esc_html_e('غير نشطة', 'pgevents'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pge_edit_plan_sort_order"><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="pge_edit_plan_sort_order" name="sort_order" class="small-text" min="0" step="1" value="<?php echo esc_attr($edit_plan['sort_order']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pge-plan-edit-features"><?php esc_html_e('مزايا الباقة', 'pgevents'); ?></label>
                        </th>
                        <td>
                            <textarea id="pge-plan-edit-features" name="features" rows="10" class="large-text"><?php echo esc_textarea($edit_features_text); ?></textarea>
                            <p class="description"><?php esc_html_e('كل ميزة في سطر مستقل.', 'pgevents'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('حفظ التعديلات', 'primary', 'submit_update_plan'); ?>
                <?php
                $cancel_url = add_query_arg(['page' => 'pge-catalog-plans'], admin_url('admin.php'));
                ?>
                <a href="<?php echo esc_url($cancel_url); ?>"><?php esc_html_e('إلغاء', 'pgevents'); ?></a>
            </form>
        <?php endif; ?>

        <?php if (is_array($delete_plan)): ?>
            <h2><?php esc_html_e('تأكيد حذف الباقة', 'pgevents'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s: plan name */
                    esc_html__('أنت على وشك حذف الباقة: %s', 'pgevents'),
                    esc_html($delete_plan['name'])
                );
                ?>
            </p>
            <p>
                <?php esc_html_e('مفتاح الباقة:', 'pgevents'); ?>
                <?php echo esc_html($delete_plan['plan_key']); ?>
            </p>
            <p>
                <strong><?php esc_html_e('لا يمكن حذف الباقة إذا كانت مرتبطة بأي مستوى.', 'pgevents'); ?></strong>
            </p>
            <form method="post">
                <?php wp_nonce_field('pge_delete_catalog_plan', 'pge_catalog_delete_nonce'); ?>
                <input type="hidden" name="pge_catalog_action" value="delete_plan">
                <input type="hidden" name="plan_id" value="<?php echo esc_attr($delete_plan['id']); ?>">

                <?php submit_button('تأكيد الحذف', 'delete', 'submit_delete_plan'); ?>
                <?php
                $delete_cancel_url = add_query_arg(['page' => 'pge-catalog-plans'], admin_url('admin.php'));
                ?>
                <a href="<?php echo esc_url($delete_cancel_url); ?>"><?php esc_html_e('إلغاء', 'pgevents'); ?></a>
            </form>
        <?php endif; ?>

        <h2><?php esc_html_e('إضافة باقة جديدة', 'pgevents'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('pge_create_catalog_plan', 'pge_catalog_plan_nonce'); ?>
            <input type="hidden" name="pge_catalog_action" value="create_plan">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="pge_plan_key"><?php esc_html_e('مفتاح الباقة', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="pge_plan_key" name="plan_key" class="regular-text" value="<?php echo esc_attr($form_plan_key); ?>" required>
                        <p class="description"><?php esc_html_e('مفتاح تقني فريد للباقة، مثل: classic أو business-pro.', 'pgevents'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pge_plan_name"><?php esc_html_e('اسم الباقة', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="pge_plan_name" name="name" class="regular-text" value="<?php echo esc_attr($form_name); ?>" required>
                        <p class="description"><?php esc_html_e('الاسم الظاهر للمستخدم، مثل: حلوة كلاسيك.', 'pgevents'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pge_plan_type"><?php esc_html_e('نوع الباقة', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <select id="pge_plan_type" name="plan_type">
                            <option value="personal" <?php selected($form_plan_type, 'personal'); ?>><?php esc_html_e('شخصية', 'pgevents'); ?></option>
                            <option value="business" <?php selected($form_plan_type, 'business'); ?>><?php esc_html_e('أعمال', 'pgevents'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pge_plan_status"><?php esc_html_e('حالة الباقة', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <select id="pge_plan_status" name="status">
                            <option value="active" <?php selected($form_status, 'active'); ?>><?php esc_html_e('نشطة', 'pgevents'); ?></option>
                            <option value="inactive" <?php selected($form_status, 'inactive'); ?>><?php esc_html_e('غير نشطة', 'pgevents'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pge_plan_sort_order"><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="pge_plan_sort_order" name="sort_order" class="small-text" min="0" step="1" value="<?php echo esc_attr($form_sort_order); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pge-plan-create-features"><?php esc_html_e('مزايا الباقة', 'pgevents'); ?></label>
                    </th>
                    <td>
                        <textarea id="pge-plan-create-features" name="features" rows="10" class="large-text"><?php echo esc_textarea($form_features); ?></textarea>
                        <p class="description"><?php esc_html_e('كل ميزة في سطر مستقل.', 'pgevents'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button('إضافة الباقة', 'primary', 'submit_create_plan'); ?>
        </form>

        <h2><?php esc_html_e('الباقات الحالية', 'pgevents'); ?></h2>
        <?php if (empty($plans)): ?>
            <p><?php esc_html_e('لا توجد باقات مضافة حتى الآن.', 'pgevents'); ?></p>
        <?php else: ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('المعرّف', 'pgevents'); ?></th>
                        <th><?php esc_html_e('مفتاح الباقة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('اسم الباقة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('المزايا', 'pgevents'); ?></th>
                        <th><?php esc_html_e('النوع', 'pgevents'); ?></th>
                        <th><?php esc_html_e('الحالة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></th>
                        <th><?php esc_html_e('الإجراءات', 'pgevents'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plans as $plan): ?>
                        <?php
                        $row_features_count = 0;
                        if (is_string($plan['features']) && trim($plan['features']) !== '') {
                            $row_features_decoded = json_decode($plan['features'], true);
                            if (is_array($row_features_decoded)) {
                                $row_features_count = count($row_features_decoded);
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo absint($plan['id']); ?></td>
                            <td><?php echo esc_html($plan['plan_key']); ?></td>
                            <td><?php echo esc_html($plan['name']); ?></td>
                            <td>
                                <?php if ($row_features_count > 0): ?>
                                    <?php
                                    printf(
                                        /* translators: %d: features count */
                                        esc_html__('%d ميزات', 'pgevents'),
                                        (int) $row_features_count
                                    );
                                    ?>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($plan_type_labels[$plan['plan_type']] ?? $plan['plan_type']); ?></td>
                            <td><?php echo esc_html($status_labels[$plan['status']] ?? $plan['status']); ?></td>
                            <td><?php echo absint($plan['sort_order']); ?></td>
                            <td>
                                <?php
                                $edit_url = add_query_arg(
                                    [
                                        'page' => 'pge-catalog-plans',
                                        'action' => 'edit',
                                        'plan_id' => absint($plan['id']),
                                    ],
                                    admin_url('admin.php')
                                );
                                ?>
                                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('تعديل', 'pgevents'); ?></a>
                                |
                                <?php
                                $delete_url = add_query_arg(
                                    [
                                        'page' => 'pge-catalog-plans',
                                        'action' => 'delete',
                                        'plan_id' => absint($plan['id']),
                                    ],
                                    admin_url('admin.php')
                                );
                                ?>
                                <a href="<?php echo esc_url($delete_url); ?>"><?php esc_html_e('حذف', 'pgevents'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th><?php esc_html_e('المعرّف', 'pgevents'); ?></th>
                        <th><?php esc_html_e('مفتاح الباقة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('اسم الباقة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('المزايا', 'pgevents'); ?></th>
                        <th><?php esc_html_e('النوع', 'pgevents'); ?></th>
                        <th><?php esc_html_e('الحالة', 'pgevents'); ?></th>
                        <th><?php esc_html_e('ترتيب العرض', 'pgevents'); ?></th>
                        <th><?php esc_html_e('الإجراءات', 'pgevents'); ?></th>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>
<?php
}

require_once PGE_PATH . 'includes/event-factory.php';
require_once PGE_PATH . 'includes/admin-mods.php';
require_once PGE_PATH . 'includes/class-pge-packages.php';
include_once PGE_PATH . 'includes/ajax.php';
require_once PGE_PATH . 'includes/event-guests.php';


// أضف هذا السطر هنا (مهم جداً لحل خطأ 500)
require_once PGE_PATH . 'includes/class-mon-events-users.php';

// 2. المحرك الرئيسي للربط مع سلة (Webhook Handler)
require_once PGE_PATH . 'includes/class-salla-handler.php';

// 3. تكامل واتساب — يُحمَّل المزوّد النشط فقط (Cartat أو UltraMsg)
$_pge_wa_provider = get_option('pge_wa_provider', 'cartat');
if ($_pge_wa_provider === 'ultramsg') {
    require_once PGE_PATH . 'includes/class-ultramsg-handler.php';
} else {
    require_once PGE_PATH . 'includes/class-cartat-handler.php';
}

// 2. استدعاء نظام التوجيه (Routing) - بديل الصفحات التقليدية
require_once PGE_PATH . 'includes/routing.php';

// 3. تحديث الروابط عند التفعيل لضمان عمل الـ Endpoints
register_activation_hook(__FILE__, function () {
    // 1. تسجيل نوع المنشورات
    pge_register_event_post_type();
    add_rewrite_rule('^e/([0-9]+)/?$', 'index.php?pge_short_event=$matches[1]', 'top');
    add_rewrite_rule('^dashboard/?$', 'index.php?pge_action=dashboard', 'top');
    add_rewrite_rule('^create-event/?$', 'index.php?pge_action=create_event', 'top');
    add_rewrite_rule('^edit-event/([0-9]+)/?$', 'index.php?pge_action=edit_event&event_id=$matches[1]', 'top');
    add_rewrite_rule('^event-manage/([0-9]+)/?$', 'index.php?pge_action=event_manage&event_id=$matches[1]', 'top');
    add_rewrite_rule('^login/?$', 'index.php?pge_action=login', 'top');
    add_rewrite_rule('^register/?$', 'index.php?pge_action=register', 'top');
    add_rewrite_rule('^forgot-password/?$', 'index.php?pge_action=forgot_password', 'top');
    flush_rewrite_rules();
    update_option('pge_rewrite_version', '1.0.5');
});

// auto-flush عند تغيير الإصدار (بدون deactivate/activate)
add_action('init', function () {
    if (get_option('pge_rewrite_version') !== '1.0.5') {
        flush_rewrite_rules();
        update_option('pge_rewrite_version', '1.0.5');
    }
}, 99);

// 4. تحديث الروابط عند التعطيل (تنظيف)
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
