<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Event Quota — Commit 8: Developer Diagnostics (أداة تشخيص مؤقتة للمطورين)
 * ============================================================================
 * هذا الملف ليس ميزة عمل جديدة — لوحة قراءة فقط (Read-only) تُضاف إلى شاشة
 * تعديل ملف المستخدم في لوحة تحكم ووردبريس (show_user_profile/
 * edit_user_profile)، مرئية للمدير (manage_options) فقط، تعرض الحالة
 * الكاملة لنظام Event Quota لحظة العرض للمستخدم المعروض حالياً.
 *
 * قيود صريحة (وفق مواصفة Commit 8):
 * - لا كتابة User Meta من هذا الملف إطلاقاً (لا update_user_meta هنا).
 * - لا أزرار ولا نماذج (forms) ولا أي إجراء (action) — عرض فقط.
 * - لا حساب حصة مستقل: Used/Remaining/Resolver Status تُقرأ حصراً من
 *   pge_resolve_event_quota_status() (Commit 5) — لا استعلام مباشر على
 *   PGE_Catalog (Tier)، لا PGE_Feature_Registry، لا Feature Resolver.
 * - Package Source/Package Status/Credit Cycle ID/Event Quota Mode/Event
 *   Quota Limit تُعرَض كقيم User Meta الخام كما هي مخزَّنة فعلياً (لا تفسير
 *   ولا تطبيع) — هذا مقصود: الغرض من هذه اللوحة تحديداً هو كشف حالات مثل
 *   الحالة المُشخَّصة سابقاً (Snapshot قديم قبل Commit 3 يفتقر لهذين
 *   المفتاحين رغم وجود بقية حقول Catalog) والتي لا تظهر أبداً عبر أي شاشة
 *   Legacy/Catalog الحالية للمستخدم النهائي.
 * - تشخيص الملكية (Ownership) قسم منفصل تماماً عن الـResolver: عدّ مباشر
 *   لمناسبات هذا المستخدم مصنَّفة حسب _pge_event_activation_id مقارنةً
 *   بـ_mon_credit_cycle_id الحالي — هذا ليس "حساب حصة مكرَّراً"، بل معلومة
 *   تشخيصية إضافية غير موجودة أصلاً في مخرجات الـResolver.
 */

add_action('show_user_profile', 'pge_render_event_quota_diagnostics_panel');
add_action('edit_user_profile', 'pge_render_event_quota_diagnostics_panel');

/**
 * يعرض لوحة تشخيص Event Quota الكاملة للمستخدم المعروض حالياً في شاشة
 * تعديل المستخدم بلوحة التحكم. لا تُستدعى هذه الدالة أبداً خارج سياق
 * show_user_profile/edit_user_profile (أي أبداً في الواجهة الأمامية).
 *
 * @param WP_User $user المستخدم المعروضة صفحته حالياً (تمرره ووردبريس نفسه).
 */
function pge_render_event_quota_diagnostics_panel($user)
{
    // حماية المدير حصراً — فحص صريح إضافي فوق أن الخطاف نفسه مقيَّد أصلاً
    // بشاشة تعديل مستخدم بلوحة التحكم؛ show_user_profile يُستدعى أيضاً عند
    // عرض أي مستخدم (بما فيهم غير المدير) لملفه الشخصي الخاص، لذا هذا الفحص
    // ضروري ليمنع ظهور اللوحة لأي مستخدم عادي تحت أي ظرف.
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!($user instanceof WP_User) || (int) $user->ID <= 0) {
        return;
    }

    $user_id = (int) $user->ID;

    // ── القيم الخام كما هي مخزَّنة فعلياً (بلا أي تفسير/تطبيع) ──────────────
    $package_source  = (string) get_user_meta($user_id, '_mon_package_source', true);
    $package_status  = (string) get_user_meta($user_id, '_mon_package_status', true);
    $credit_cycle_id = (string) get_user_meta($user_id, '_mon_credit_cycle_id', true);

    $quota_mode_raw  = get_user_meta($user_id, '_mon_event_quota_mode', true);
    $quota_limit_raw = get_user_meta($user_id, '_mon_event_quota_limit', true);

    $quota_mode_display  = ($quota_mode_raw === '' || $quota_mode_raw === false)
        ? '(غير موجود — Missing)'
        : (string) $quota_mode_raw;
    $quota_limit_display = ($quota_limit_raw === '' || $quota_limit_raw === false)
        ? '(غير موجود — Missing)'
        : (string) $quota_limit_raw;

    // ── الـResolver — المصدر الوحيد لـUsed/Remaining/Resolver Status ────────
    // لا حساب مستقل هنا: استدعاء واحد فقط لـpge_resolve_event_quota_status()،
    // بلا أي استعلام WP_Query لحساب الحصة، بلا قراءة لـPGE_Catalog/Tier، بلا
    // قراءة Feature Registry/Resolver.
    $resolver_status_label = '—';
    $used_display = '—';
    $remaining_display = '—';
    $resolver_error_code = '';
    $resolver_error_message = '';

    if (function_exists('pge_resolve_event_quota_status')) {
        $quota_status = pge_resolve_event_quota_status($user_id);

        if (is_wp_error($quota_status)) {
            $resolver_status_label = 'خطأ (WP_Error)';
            $resolver_error_code = (string) $quota_status->get_error_code();
            $resolver_error_message = (string) $quota_status->get_error_message();
        } elseif (is_array($quota_status)) {
            $resolver_status_label = 'نجح — الوضع (mode): ' . (string) ($quota_status['mode'] ?? '—');

            $used_display = (array_key_exists('used', $quota_status) && $quota_status['used'] !== null)
                ? (string) $quota_status['used']
                : '—';
            $remaining_display = (array_key_exists('remaining', $quota_status) && $quota_status['remaining'] !== null)
                ? (string) $quota_status['remaining']
                : '—';
        } else {
            $resolver_status_label = 'مخرَج غير متوقَّع من الـResolver (لا array ولا WP_Error)';
        }
    } else {
        $resolver_status_label = 'الدالة المركزية pge_resolve_event_quota_status() غير محمَّلة حالياً';
    }

    // ── تشخيص الملكية — قراءة فقط، بلا كتابة، بلا إصلاح، بلا إعادة حساب ─────
    // يُصنِّف كل مناسبات هذا المستخدم إلى ثلاث فئات وفق _pge_event_activation_id
    // مقارنةً بـ_mon_credit_cycle_id الحالي. معلومة تشخيصية بحتة، لا علاقة لها
    // بمخرجات الـResolver ولا بديل عنها.
    $owned_current = 0;
    $owned_empty = 0;
    $owned_previous = 0;

    $diagnostics_events_query = new WP_Query([
        'post_type'      => 'pge_event',
        'author'         => $user_id,
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    foreach ((array) $diagnostics_events_query->posts as $diagnostics_event_id) {
        $owner_activation_id = (string) get_post_meta($diagnostics_event_id, '_pge_event_activation_id', true);

        if ($owner_activation_id === '') {
            $owned_empty++;
        } elseif ($credit_cycle_id !== '' && $owner_activation_id === $credit_cycle_id) {
            $owned_current++;
        } else {
            $owned_previous++;
        }
    }
    ?>
    <h3>🛠️ تشخيص Event Quota (أداة مطوّرين مؤقتة — للمدير فقط)</h3>
    <p><em>عرض فقط — بلا أزرار، بلا حفظ، بلا إصلاح تلقائي. لا يظهر هذا القسم لغير المدير تحت أي ظرف.</em></p>
    <table class="form-table" role="presentation">
        <tr>
            <th>مصدر الباقة (Package Source)</th>
            <td><code><?php echo esc_html($package_source !== '' ? $package_source : '(فارغ)'); ?></code></td>
        </tr>
        <tr>
            <th>حالة الباقة (Package Status)</th>
            <td><code><?php echo esc_html($package_status !== '' ? $package_status : '(فارغ)'); ?></code></td>
        </tr>
        <tr>
            <th>معرّف دورة الرصيد (Credit Cycle ID)</th>
            <td><code><?php echo esc_html($credit_cycle_id !== '' ? $credit_cycle_id : '(فارغ)'); ?></code></td>
        </tr>
        <tr>
            <th>وضع حصة المناسبات (Event Quota Mode)</th>
            <td><code><?php echo esc_html($quota_mode_display); ?></code></td>
        </tr>
        <tr>
            <th>حد حصة المناسبات (Event Quota Limit)</th>
            <td><code><?php echo esc_html($quota_limit_display); ?></code></td>
        </tr>
        <tr>
            <th>حالة الـResolver (Resolver Status)</th>
            <td><code><?php echo esc_html($resolver_status_label); ?></code></td>
        </tr>
        <?php if ($resolver_error_code !== '' || $resolver_error_message !== ''): ?>
        <tr>
            <th>رمز الخطأ (Error Code)</th>
            <td><code><?php echo esc_html($resolver_error_code); ?></code></td>
        </tr>
        <tr>
            <th>رسالة الخطأ (Error Message)</th>
            <td><code><?php echo esc_html($resolver_error_message); ?></code></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th>المناسبات المُستخدَمة (Used Events)</th>
            <td><code><?php echo esc_html($used_display); ?></code></td>
        </tr>
        <tr>
            <th>المناسبات المتبقية (Remaining Events)</th>
            <td><code><?php echo esc_html($remaining_display); ?></code></td>
        </tr>
        <tr>
            <th colspan="2"><em>ملكية المناسبات (Ownership) — تشخيصي بحت</em></th>
        </tr>
        <tr>
            <th>مناسبات مملوكة للتفعيل الحالي</th>
            <td><code><?php echo (int) $owned_current; ?></code></td>
        </tr>
        <tr>
            <th>مناسبات بلا ملكية (فارغة)</th>
            <td><code><?php echo (int) $owned_empty; ?></code></td>
        </tr>
        <tr>
            <th>مناسبات تابعة لتفعيلات سابقة</th>
            <td><code><?php echo (int) $owned_previous; ?></code></td>
        </tr>
    </table>
    <?php
}
