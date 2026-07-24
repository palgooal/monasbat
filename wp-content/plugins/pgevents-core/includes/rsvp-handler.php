<?php
if (!defined('ABSPATH')) exit;

function pge_create_rsvp_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'pge_event_rsvps';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id BIGINT(20) UNSIGNED NOT NULL,
        guest_phone VARCHAR(32) NOT NULL,
        guest_name VARCHAR(191) NULL,
        companions INT(11) DEFAULT 0,
        note TEXT NULL,
        reply VARCHAR(10) DEFAULT 'pending',
        checked_in TINYINT(1) DEFAULT 0,
        checked_in_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY event_phone (event_id, guest_phone),
        KEY event_id (event_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(PGE_PATH . 'pgevents-core.php', 'pge_create_rsvp_table');

/**
 * ==========================================================================
 * الدالة المركزية الوحيدة لحفظ رد RSVP (Canonical RSVP write path)
 * ==========================================================================
 * تُستخدم من مسارين مختلفين:
 *   1) النموذج المباشر على صفحة المناسبة (template-parts/event/rsvp.php)
 *   2) معالج الـ AJAX أدناه (pge_rsvp_submit)
 *
 * كل مستدعٍ يتحقق من الـ nonce الخاص به بطريقته الحالية *قبل* استدعاء هذه
 * الدالة — هي نفسها لا تتحقق من nonce، فقط من صحة البيانات والهوية والسعة.
 *
 * تكتب حصرياً إلى الجدول wp_pge_event_rsvps (مصدر الحقيقة الوحيد المعتمد في
 * لوحة التحكم وإدارة المدعوين)، ولا تلمس _pge_rsvp_map / _pge_rsvp_records
 * إطلاقاً — هذان الحقلان أصبحا للقراءة فقط لأغراض الترحيل التاريخي (راجع
 * pge_migrate_legacy_rsvp_meta() في نفس الملف).
 *
 * تحافظ على حالة checked_in / checked_in_at الحالية دون أي تعديل، لأن مصفوفة
 * $data أدناه لا تتضمنهما إطلاقاً — التحديث يقتصر على الأعمدة المذكورة فيها.
 *
 * @param int    $event_id
 * @param string $guest_phone_raw  رقم جوال الضيف كما وصل (سيُطبَّع داخلياً)
 * @param string $reply            'yes' | 'no' | 'pending'
 * @param int    $companions       عدد المرافقين (يُحدّ بين 0 و20)
 * @param string $note             ملاحظة الضيف للمضيف
 * @param bool   $is_host_or_admin هل المستدعي مضيف المناسبة أو أدمن (يتجاوز فحص قائمة المدعوين)
 * @return array{success:bool,message:string,guest_phone?:string,reply?:string,companions?:int,total_attending?:int,remaining?:int|null}
 */
if (!function_exists('pge_save_rsvp_response')) {
    function pge_save_rsvp_response($event_id, $guest_phone_raw, $reply, $companions, $note, $is_host_or_admin = false)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pge_event_rsvps';

        $event_id = (int) $event_id;
        if (!$event_id || get_post_type($event_id) !== 'pge_event') {
            return ['success' => false, 'message' => 'حدث غير صالح.'];
        }

        $phone = function_exists('pge_norm_phone')
            ? pge_norm_phone($guest_phone_raw)
            : preg_replace('/\D+/', '', (string) $guest_phone_raw);

        // إذا كان المستدعي مضيف/أدمن ولم يُمرَّر رقم صريح، استخدم جوال المضيف
        // المسجَّل على المناسبة نفسها كهوية RSVP الخاصة به (حقل مطلوب دائماً).
        if ($phone === '' && $is_host_or_admin) {
            $host_phone_raw = (string) get_post_meta($event_id, '_pge_host_phone', true);
            $phone = function_exists('pge_norm_phone')
                ? pge_norm_phone($host_phone_raw)
                : preg_replace('/\D+/', '', $host_phone_raw);
        }

        if ($phone === '') {
            return ['success' => false, 'message' => 'فضلاً أدخل رقم الجوال.'];
        }

        // تحقق أن الرقم موجود ضمن المدعوين (إلا لو مضيف/أدمن)
        if (!$is_host_or_admin) {
            $invited = function_exists('pge_get_invited_phones') ? pge_get_invited_phones($event_id) : [];
            if (!in_array($phone, $invited, true)) {
                return ['success' => false, 'message' => 'رقم الجوال غير موجود ضمن قائمة المدعوين.'];
            }
        }

        $reply      = in_array($reply, ['yes', 'no', 'pending'], true) ? $reply : 'pending';
        $companions = max(0, min(20, (int) $companions));
        $note       = trim((string) $note);

        // سعة الضيوف — من باقة صاحب المناسبة عبر الدالة المركزية حصراً
        // (Catalog-aware/Legacy-aware حسب _mon_package_source). كانت هذه
        // النقطة تستدعي PGE_Packages::get_user_plan_limits() مباشرة، وهي
        // مسار Legacy فقط لا يتحقق من _mon_package_status — ما يعني أن
        // مضيف Catalog منتهي الاشتراك كان يبقى guest_limit لديه كما هو
        // (لا يُصفَّر تلقائياً كما يحدث في Legacy عند الإلغاء).
        $author_id   = (int) get_post_field('post_author', $event_id);
        $plan_limits = function_exists('pge_get_user_plan_limits_for_events')
            ? pge_get_user_plan_limits_for_events($author_id)
            : ['guest_limit' => 0];
        $guest_limit = (int) ($plan_limits['guest_limit'] ?? 0);

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, companions, reply FROM {$table} WHERE event_id = %d AND guest_phone = %s LIMIT 1",
            $event_id,
            $phone
        ));

        $old_count         = ($existing && $existing->reply === 'yes') ? (1 + (int) $existing->companions) : 0;
        $current_yes_total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(1 + companions), 0) FROM {$table} WHERE event_id = %d AND reply = 'yes'",
            $event_id
        ));
        $new_count = ($reply === 'yes') ? (1 + $companions) : 0;
        $new_total = $current_yes_total - $old_count + $new_count;

        if ($guest_limit > 0 && $reply === 'yes' && $new_total > $guest_limit) {
            $allowed = max(0, $guest_limit - ($current_yes_total - $old_count));
            return [
                'success' => false,
                'message' => 'عذرًا، تجاوزت الطاقة المتاحة. الحد المتبقي: ' . (int) $allowed,
            ];
        }

        // Upsert — الأعمدة المذكورة فقط تُكتب؛ checked_in/checked_in_at لا يُلمَسان أبداً هنا
        $data = [
            'event_id'    => $event_id,
            'guest_phone' => $phone,
            'companions'  => $companions,
            'note'        => $note,
            'reply'       => $reply,
        ];
        $formats = ['%d', '%s', '%d', '%s', '%s'];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => (int) $existing->id], $formats, ['%d']);
        } else {
            $wpdb->insert($table, $data, $formats);
        }

        $total_attending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(1 + companions), 0) FROM {$table} WHERE event_id = %d AND reply = 'yes'",
            $event_id
        ));
        $remaining = $guest_limit > 0 ? max(0, $guest_limit - $total_attending) : null;

        return [
            'success'         => true,
            'message'         => 'تم الحفظ بنجاح.',
            'guest_phone'     => $phone,
            'reply'           => $reply,
            'companions'      => $companions,
            'total_attending' => $total_attending,
            'remaining'       => $remaining,
        ];
    }
}

add_action('wp_ajax_pge_rsvp_submit', 'pge_rsvp_submit');
add_action('wp_ajax_nopriv_pge_rsvp_submit', 'pge_rsvp_submit');

function pge_rsvp_submit()
{
    if (empty($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pge_rsvp_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
    }

    $event_id = absint($_POST['event_id'] ?? 0);
    if (!$event_id) wp_send_json_error(['message' => 'Invalid event']);

    $reply      = sanitize_text_field($_POST['reply'] ?? 'pending');
    $companions = intval($_POST['companions'] ?? 0);
    $note       = sanitize_text_field($_POST['note'] ?? '');

    // الضيف: خذ الهاتف من cookie بعد التحقق من توقيع HMAC
    $phone_cookie = 'pge_event_phone_' . $event_id;
    $guest_phone  = '';
    if (isset($_COOKIE[$phone_cookie])) {
        $parts = explode('|', (string) $_COOKIE[$phone_cookie], 2);
        if (count($parts) === 2) {
            [$raw_phone, $raw_hmac] = $parts;
            $expected_hmac = wp_hash($raw_phone . '|' . (int) $event_id);
            if (hash_equals($expected_hmac, $raw_hmac)) {
                $guest_phone = preg_replace('/\D+/', '', $raw_phone);
            }
        }
    }

    $is_host = current_user_can('administrator')
        || (get_current_user_id() && get_current_user_id() === (int) get_post_field('post_author', $event_id));

    // المضيف/المدير ممكن يمرر phone (اختياري)
    if ($is_host && !empty($_POST['guest_phone'])) {
        $guest_phone = preg_replace('/\D+/', '', sanitize_text_field($_POST['guest_phone']));
    }

    $result = pge_save_rsvp_response($event_id, $guest_phone, $reply, $companions, $note, $is_host);

    if (!$result['success']) {
        wp_send_json_error(['message' => $result['message']]);
    }

    wp_send_json_success([
        'message'         => 'RSVP saved',
        'reply'           => $result['reply'],
        'companions'      => $result['companions'],
        'total_attending' => $result['total_attending'],
        'remaining'       => $result['remaining'],
    ]);
}

// Check-in (للمضيف فقط)
add_action('wp_ajax_pge_checkin_submit', function () {
    // 1. التحقق من الـ Nonce أولاً
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'pge_checkin_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce']);
    }

    // 2. التحقق من الصلاحية (أدمن أو مضيف المناسبة)
    if (!current_user_can('administrator')) {
        $event_id = absint($_POST['event_id'] ?? 0);
        $author_id = (int) get_post_field('post_author', $event_id);
        if (!$event_id || get_current_user_id() !== $author_id) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
    }

    $event_id = absint($_POST['event_id'] ?? 0);
    $phone = preg_replace('/\D+/', '', sanitize_text_field($_POST['guest_phone'] ?? ''));
    if (!$event_id || $phone === '') wp_send_json_error(['message' => 'Invalid']);

    global $wpdb;
    $table = $wpdb->prefix . 'pge_event_rsvps';

    $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (event_id, guest_phone, reply, checked_in, checked_in_at)
         VALUES (%d, %s, 'pending', 1, NOW())
         ON DUPLICATE KEY UPDATE checked_in=1, checked_in_at=NOW()",
        $event_id,
        $phone
    ));

    wp_send_json_success(['message' => 'Checked in']);
});
