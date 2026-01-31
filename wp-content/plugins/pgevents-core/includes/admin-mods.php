<?php
if (!defined('ABSPATH')) exit;

/**
 * 1. إضافة الأعمدة المخصصة لقائمة المناسبات
 */
add_filter('manage_pge_event_posts_columns', function ($columns) {
    $new_columns = array();
    $new_columns['cb'] = $columns['cb'];
    $new_columns['title'] = 'اسم المناسبة';
    $new_columns['author'] = 'المشترك (صاحب المناسبة)';
    $new_columns['event_date'] = 'تاريخ المناسبة';
    $new_columns['host_phone'] = 'رقم الواتساب';
    $new_columns['status'] = 'الحالة';
    return $new_columns;
});

/**
 * 2. عرض البيانات داخل الأعمدة المخصصة مع لمسات جمالية
 */
add_action('manage_pge_event_posts_custom_column', function ($column, $post_id) {
    switch ($column) {
        case 'event_date':
            $date = get_post_meta($post_id, '_pge_event_date', true);
            echo $date ? '<strong>' . date_i18n('j F Y', strtotime($date)) . '</strong>' : '—';
            break;

        case 'host_phone':
            $phone = get_post_meta($post_id, '_pge_host_phone', true);
            if ($phone) {
                echo '<a href="https://wa.me/' . $phone . '" target="_blank" style="color:#25D366; font-weight:bold; text-decoration:none;">';
                echo '<span class="dashicons dashicons-whatsapp" style="vertical-align:middle; margin-left:4px;"></span>' . $phone;
                echo '</a>';
            } else {
                echo '—';
            }
            break;

        case 'status':
            $post_status = get_post_status($post_id);
            if ($post_status === 'private') {
                echo '<span style="background:#ffe4e6; color:#e11d48; padding:5px 10px; border-radius:20px; font-size:11px; font-weight:bold; border:1px solid #fecdd3;">مؤرشفة</span>';
            } else {
                echo '<span style="background:#f0fdf4; color:#16a34a; padding:5px 10px; border-radius:20px; font-size:11px; font-weight:bold; border:1px solid #bbf7d0;">نشطة</span>';
            }
            break;
    }
}, 10, 2);

/**
 * 3. إضافة فلتر المشتركين بجانب أزرار التصفية
 */
add_action('restrict_manage_posts', function () {
    global $typenow;
    if ($typenow == 'pge_event') {
        wp_dropdown_users(array(
            'show_option_all' => 'كل المشتركين',
            'name'            => 'author',
            'selected'        => !empty($_GET['author']) ? $_GET['author'] : 0,
        ));

        // إضافة زر التصدير بجانب زر التصفية
        echo '<button type="submit" name="pge_export_csv" value="1" class="button button-secondary" style="margin-right:5px; background:#f0f0f1; border-color:#007cba; color:#007cba;">
            <span class="dashicons dashicons-download" style="vertical-align:middle; padding-bottom:4px;"></span> تصدير Excel
        </button>';
    }
});

/**
 * 4. معالجة عملية تصدير البيانات (Export to CSV)
 */
add_action('admin_init', function () {
    if (isset($_GET['pge_export_csv']) && $_GET['pge_export_csv'] == '1') {
        if (!current_user_can('manage_options')) return;

        $filename = 'events_export_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');
        // إضافة BOM لدعم اللغة العربية في Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // عناوين الأعمدة في ملف Excel
        fputcsv($output, array('اسم المناسبة', 'المشترك', 'التاريخ', 'رقم الهاتف', 'الحالة'));

        $args = array(
            'post_type'      => 'pge_event',
            'post_status'    => array('publish', 'private'),
            'posts_per_page' => -1,
            'author'         => !empty($_GET['author']) ? $_GET['author'] : '',
        );

        $query = new WP_Query($args);
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                fputcsv($output, array(
                    get_the_title(),
                    get_the_author(),
                    get_post_meta($post_id, '_pge_event_date', true),
                    get_post_meta($post_id, '_pge_host_phone', true),
                    (get_post_status() == 'publish' ? 'نشطة' : 'مؤرشفة')
                ));
            }
        }
        fclose($output);
        exit;
    }
});

/**
 * 5. جعل الأعمدة قابلة للترتيب
 */
add_filter('manage_edit-pge_event_sortable_columns', function ($columns) {
    $columns['event_date'] = 'event_date';
    return $columns;
});

/**
 * 6. إحصائيات سريعة في لوحة التحكم الرئيسية (Widget)
 */
add_action('wp_dashboard_setup', function () {
    wp_add_dashboard_widget('pge_stats_widget', '📊 إحصائيات نظام المناسبات', function () {
        $total = wp_count_posts('pge_event');
        echo '<div style="display:flex; justify-content:space-around; text-align:center; padding:15px 0;">
                <div><span style="display:block; font-size:28px; font-weight:bold; color:#16a34a;">' . ($total->publish ?? 0) . '</span> مناسبة نشطة</div>
                <div style="border-right:1px solid #eee; padding-right:20px;"><span style="display:block; font-size:28px; font-weight:bold; color:#e11d48;">' . ($total->private ?? 0) . '</span> مؤرشفة</div>
              </div>';
        echo '<p style="text-align:center; margin-top:10px; border-top:1px solid #eee; pt:10px;">
                <a href="edit.php?post_type=pge_event" class="button button-primary button-large">إدارة المناسبات وتصدير البيانات</a>
              </p>';
    });
});
