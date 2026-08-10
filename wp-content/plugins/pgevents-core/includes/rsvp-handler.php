<?php
if (!defined('ABSPATH')) exit;

if (!defined('PGE_RSVP_SCHEMA_VERSION')) {
    define('PGE_RSVP_SCHEMA_VERSION', '1.0.0');
}
if (!defined('PGE_RSVP_SCHEMA_VERSION_OPTION')) {
    define('PGE_RSVP_SCHEMA_VERSION_OPTION', 'pge_rsvp_schema_version');
}

/**
 * RSVP Schema Owner — Option A.
 *
 * الهوية التجارية والوحيدة لصف RSVP هي (event_id, guest_phone) بعد تطبيع
 * الهاتف في مسارات الإدخال. هذا الملف هو المالك authoritative للجدول الأساسي
 * وللقيد UNIQUE المقابل. مخططات Check-in/Messaging تضيف أعمدتها وجداولها فقط.
 */
function pge_rsvp_schema_table_name()
{
    global $wpdb;
    return $wpdb->prefix . 'pge_event_rsvps';
}

function pge_rsvp_schema_read_index_map($table)
{
    global $wpdb;

    $rows = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
    if ($rows === null) {
        return null;
    }

    $indexes = [];
    foreach ($rows as $row) {
        $name = (string) ($row['Key_name'] ?? '');
        if ($name === '') {
            continue;
        }
        if (!isset($indexes[$name])) {
            $indexes[$name] = [
                'non_unique' => (int) ($row['Non_unique'] ?? 1),
                'columns'    => [],
            ];
        }
        $indexes[$name]['columns'][(int) ($row['Seq_in_index'] ?? 0)] = (string) ($row['Column_name'] ?? '');
    }

    foreach ($indexes as $name => $meta) {
        ksort($indexes[$name]['columns']);
        $indexes[$name]['columns'] = array_values($indexes[$name]['columns']);
    }

    return $indexes;
}

function pge_rsvp_schema_has_unique_identity(array $indexes)
{
    $identity_columns = ['event_id', 'guest_phone'];
    foreach ($indexes as $meta) {
        if ($meta['columns'] === $identity_columns && (int) $meta['non_unique'] === 0) {
            return true;
        }
    }
    return false;
}

function pge_rsvp_schema_has_redundant_phone_index(array $indexes)
{
    return isset($indexes['event_guest_phone'])
        && $indexes['event_guest_phone']['columns'] === ['event_id', 'guest_phone']
        && (int) $indexes['event_guest_phone']['non_unique'] === 1;
}

/**
 * فحص سلامة البيانات قبل إنشاء UNIQUE. لا يعرض أو يسجل أرقام هواتف.
 */
function pge_rsvp_schema_identity_data_is_safe($table)
{
    global $wpdb;

    $duplicate_groups = $wpdb->get_var(
        "SELECT COUNT(*) FROM (
            SELECT event_id, guest_phone
            FROM $table
            GROUP BY event_id, guest_phone
            HAVING COUNT(*) > 1
        ) pge_rsvp_duplicates"
    );
    if ($duplicate_groups === null || (int) $duplicate_groups > 0) {
        error_log('PGE RSVP schema migration blocked: duplicate_rsvp_identity.');
        return false;
    }

    // UNIQUE على القيمة الخام لا يكفي إذا كانت قيمتان مختلفتان تتطبعان إلى
    // الهاتف نفسه. الفحص في PHP يعيد استخدام نفس التطبيع الفعلي بلا طباعة PII.
    $rows = $wpdb->get_results("SELECT event_id, guest_phone FROM $table ORDER BY id ASC", ARRAY_A);
    if ($rows === null) {
        error_log('PGE RSVP schema migration blocked: identity_scan_failed.');
        return false;
    }

    $seen = [];
    foreach ($rows as $row) {
        $raw_phone = (string) ($row['guest_phone'] ?? '');
        $normalized_phone = function_exists('pge_norm_phone')
            ? pge_norm_phone($raw_phone)
            : preg_replace('/\D+/', '', $raw_phone);
        $identity_key = (int) ($row['event_id'] ?? 0) . '|' . $normalized_phone;

        if (isset($seen[$identity_key]) && $seen[$identity_key] !== $raw_phone) {
            error_log('PGE RSVP schema migration blocked: normalized_rsvp_identity_collision.');
            return false;
        }
        $seen[$identity_key] = $raw_phone;
    }

    return true;
}

function pge_rsvp_schema_create_base_table($table)
{
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
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

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * يضمن عقد Option A فعلياً، حتى إذا كان رقم الإصدار الحالي مخزناً لكن الفهرس
 * منحرفاً. كل ALTER يتبعه SHOW INDEX؛ لا يُحدّث الإصدار قبل اكتمال postconditions.
 */
function pge_maybe_upgrade_rsvp_schema()
{
    global $wpdb;

    $table = pge_rsvp_schema_table_name();
    $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

    if (!$table_exists) {
        pge_rsvp_schema_create_base_table($table);
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$table_exists) {
            error_log('PGE RSVP schema migration failed: base_table_missing_after_create.');
            return false;
        }
    }

    $indexes = pge_rsvp_schema_read_index_map($table);
    if ($indexes === null) {
        error_log('PGE RSVP schema migration failed: index_read_failed.');
        return false;
    }

    if (!pge_rsvp_schema_has_unique_identity($indexes)) {
        if (!pge_rsvp_schema_identity_data_is_safe($table)) {
            return false;
        }

        // استبدال فهرس event_phone غير الفريد على الزوج نفسه يتم في ALTER
        // واحد؛ عند فشله لا يُحذف الفهرس الاحتياطي event_guest_phone أدناه.
        if (isset($indexes['event_phone'])) {
            if ($indexes['event_phone']['columns'] === ['event_id', 'guest_phone']
                && (int) $indexes['event_phone']['non_unique'] === 1) {
                $altered = $wpdb->query(
                    "ALTER TABLE $table DROP INDEX event_phone, ADD UNIQUE KEY event_phone (event_id, guest_phone)"
                );
            } else {
                $altered = $wpdb->query(
                    "ALTER TABLE $table ADD UNIQUE KEY event_phone_unique (event_id, guest_phone)"
                );
            }
        } else {
            $altered = $wpdb->query(
                "ALTER TABLE $table ADD UNIQUE KEY event_phone (event_id, guest_phone)"
            );
        }

        if ($altered === false) {
            error_log('PGE RSVP schema migration failed: unique_identity_add_failed.');
            return false;
        }

        $indexes = pge_rsvp_schema_read_index_map($table);
        if ($indexes === null || !pge_rsvp_schema_has_unique_identity($indexes)) {
            error_log('PGE RSVP schema migration failed: unique_identity_not_verified.');
            return false;
        }
    }

    // الفهرس غير الفريد لا يُحذف إلا بعد إثبات UNIQUE من الحالة الفعلية.
    if (pge_rsvp_schema_has_redundant_phone_index($indexes)) {
        $dropped = $wpdb->query("ALTER TABLE $table DROP INDEX event_guest_phone");
        if ($dropped === false) {
            error_log('PGE RSVP schema migration failed: redundant_index_drop_failed.');
            return false;
        }
    }

    $final_indexes = pge_rsvp_schema_read_index_map($table);
    if ($final_indexes === null
        || !pge_rsvp_schema_has_unique_identity($final_indexes)
        || pge_rsvp_schema_has_redundant_phone_index($final_indexes)) {
        error_log('PGE RSVP schema migration failed: identity_postconditions_failed.');
        return false;
    }

    if ((string) get_option(PGE_RSVP_SCHEMA_VERSION_OPTION, '') !== PGE_RSVP_SCHEMA_VERSION) {
        update_option(PGE_RSVP_SCHEMA_VERSION_OPTION, PGE_RSVP_SCHEMA_VERSION);
    }

    return true;
}

function pge_create_rsvp_table()
{
    return pge_maybe_upgrade_rsvp_schema();
}
register_activation_hook(PGE_PATH . 'pgevents-core.php', 'pge_create_rsvp_table');
add_action('plugins_loaded', 'pge_maybe_upgrade_rsvp_schema');

/**
 * Canonical RSVP lookup by the Option A identity contract.
 *
 * The phone is always normalized here, and two rows are fetched deliberately
 * so corrupt duplicate identities can never degrade into a silent first-row
 * selection. This helper is read-only and never exposes duplicate candidates.
 *
 * @return array{status:'found'|'not_found'|'integrity_error',row:?object,reason?:string}
 */
if (!function_exists('pge_rsvp_find_canonical_by_phone')) {
    function pge_rsvp_find_canonical_by_phone($event_id, $guest_phone_raw): array
    {
        global $wpdb;

        $event_id = (int) $event_id;
        $phone = function_exists('pge_norm_phone')
            ? pge_norm_phone($guest_phone_raw)
            : preg_replace('/\D+/', '', (string) $guest_phone_raw);

        if ($event_id <= 0 || $phone === '') {
            return ['status' => 'not_found', 'row' => null];
        }

        $table = $wpdb->prefix . 'pge_event_rsvps';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_id = %d AND guest_phone = %s ORDER BY id ASC LIMIT 2",
            $event_id,
            $phone
        ));

        if (!is_array($rows)) {
            error_log("PGE RSVP lookup error: event_id={$event_id} reason=rsvp_lookup_failed");
            return ['status' => 'integrity_error', 'row' => null, 'reason' => 'rsvp_lookup_failed'];
        }

        $count = count($rows);
        if ($count === 0) {
            return ['status' => 'not_found', 'row' => null];
        }
        if ($count === 1) {
            return ['status' => 'found', 'row' => $rows[0]];
        }

        error_log("PGE RSVP integrity error: event_id={$event_id} reason=duplicate_rsvp_identity count={$count}");
        return ['status' => 'integrity_error', 'row' => null, 'reason' => 'duplicate_rsvp_identity'];
    }
}

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

        $lookup = pge_rsvp_find_canonical_by_phone($event_id, $phone);
        if ($lookup['status'] === 'integrity_error') {
            return ['success' => false, 'message' => 'تعذر حفظ الرد بسبب تعارض في بيانات الدعوة.'];
        }
        $existing = $lookup['status'] === 'found' ? $lookup['row'] : null;

        // RC1 Final Release Blocker: RSVP Write Path Unification — القرار
        // الموحَّد الوحيد لكل مسار كتابة RSVP في المشروع الآن يعيش حصراً في
        // PGE_Invitation_Repository::current_or_null() (تستدعيه أيضاً مسارات
        // واتساب Cartat/UltraMsg وترحيل البيانات القديمة — لا نسخة موازية من
        // الشرط هنا). Hard Delete لا يلمس هذا الجدول إطلاقاً (راجع
        // docs/HARD-DELETE-SEMANTICS-AUDIT.md)، فقد يكون الصف الموجود هنا
        // "يتيماً" من دعوة سابقة حُذفت ثم أُعيد إنشاء دعوة جديدة بنفس الهاتف؛
        // صف كهذا يُعامَل كغير موجود فيُنشئ upsert صفاً جديداً مستقلاً تماماً
        // بدلاً من توريث checked_in/checked_in_at/checked_in_by_assignment_id/
        // checkin_method/actual_entered_count. يقتصر على مسار الضيف العادي
        // (!$is_host_or_admin) عمداً — مسار المضيف/الأدمن قد يستخدم رقم هاتف
        // المضيف نفسه (لا يخضع لمفهوم "دعوة" أصلاً)، فتطبيق هذا الحارس عليه
        // كان سيُنشئ صفاً جديداً في كل مرة بلا داعٍ ويُغيِّر سلوك تدفّق RSVP
        // القائم لذلك المسار تحديداً — خارج نطاق هذا الإصلاح.
        if ($existing && !$is_host_or_admin && class_exists('PGE_Invitation_Repository')) {
            $existing = PGE_Invitation_Repository::current_or_null($event_id, $phone, $existing);
        }

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

        $old_reply = $existing ? $existing->reply : null;

        if ($existing) {
            $wpdb->update($table, $data, ['id' => (int) $existing->id], $formats, ['%d']);
        } else {
            $wpdb->insert($table, $data, $formats);
        }

        // المرحلة 4B: منح Replacement Entitlement عند انتقال RSVP حقيقي إلى
        // اعتذار — بعد نجاح الحفظ أعلاه فقط، وSide Effect لاحق لا يؤثر على
        // نتيجة/عقد هذه الدالة إطلاقاً (pge_maybe_grant_replacement_entitlement
        // مُحصَّنة بالكامل ضد أي استثناء داخلها، راجع includes/replacement-
        // entitlement-grant.php).
        if ($reply === 'no' && function_exists('pge_maybe_grant_replacement_entitlement')) {
            pge_maybe_grant_replacement_entitlement($event_id, $phone, $old_reply, $reply);
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
