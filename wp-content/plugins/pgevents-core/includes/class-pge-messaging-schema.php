<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Messaging Schema — Messaging Architecture Phase 2 (Foundation)
 * ============================================================================
 * "Phase 2 Foundation فقط" — يوفِّر بنية قاعدة البيانات اللازمة *قبل* إرسال
 * Reminder/Thank You فعلياً (راجع docs/MESSAGING-ARCHITECTURE.md §Phase 2):
 *
 *   1. عمود إضافي واحد (Additive فقط) على الجدول الحالي
 *      {$wpdb->prefix}pge_event_rsvps (مُعرَّف أصلاً في includes/rsvp-handler.php):
 *        - thank_you_sent_at DATETIME NULL — راجع class-pge-thank-you-claim.php
 *          للعقد الكامل (NULL = لم يُرسَل، غير NULL = أُرسل بنجاح نهائياً).
 *   2. جدول جديد {$wpdb->prefix}pge_message_log — سجل تتبّع عام (Tracking
 *      فقط، لا Business Logic) لكل محاولة إرسال رسالة من أي نوع
 *      (PGE_Message_Type::ALL)، مع lifecycle_started_at nullable لربط Claim
 *      الشكر بدورة RSVP الحالية دون التأثير على أنواع الرسائل الأخرى.
 *
 * نمط الترقية يطابق حرفياً PGE_Checkin_Schema (Phase 4)/PGE_Invitation_
 * Management_Schema (Phase 9) — رقم إصدار مخزَّن في wp_options، dbDelta()
 * موثوق لـCREATE TABLE فقط، SHOW COLUMNS + ALTER TABLE ADD COLUMN صريح idempotent
 * للعمود الإضافي على الجدول القائم (dbDelta() لا يُوثَق به لتعديل جدول موجود
 * مسبقاً)، وتحقّق فعلي نهائي عبر SHOW COLUMNS قبل اعتماد نجاح كل خطوة — لا
 * افتراض نجاح أعمى لأي استعلام.
 *
 * لا تعديل على أي عمود قائم (Additive فقط)، ولا على أي جدول آخر.
 */
class PGE_Messaging_Schema
{
    const SCHEMA_VERSION = '1.1.0';
    const VERSION_OPTION = 'pge_messaging_schema_version';

    private static function rsvps_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_event_rsvps';
    }

    private static function message_log_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_message_log';
    }

    /**
     * نقطة الدخول الوحيدة — آمنة للاستدعاء من register_activation_hook ومن
     * plugins_loaded على حد سواء (بنفس فلسفة PGE_Checkin_Schema::maybe_upgrade()).
     */
    public static function maybe_upgrade()
    {
        $stored_version = (string) get_option(self::VERSION_OPTION, '');

        if ($stored_version === self::SCHEMA_VERSION) {
            return;
        }

        $log_table_ok = self::ensure_message_log_table();
        $column_ok = self::ensure_thank_you_column();

        if ($log_table_ok && $column_ok) {
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
        }
        // فشل جزئي: لا يُحدَّث الإصدار المخزَّن، فيُعاد المحاولة كاملةً في
        // الطلب التالي (نفس فلسفة الفشل الجزئي في PGE_Checkin_Schema).
    }

    /**
     * إنشاء جدول سجل الرسائل عبر dbDelta() — موثوق تماماً لـCREATE TABLE.
     * تُعيد true فقط بعد التحقق الفعلي من وجود كل الأعمدة المطلوبة.
     *
     * الفهارس: composite (event_id, message_type) يخدم كلاً من "كل رسائل
     * مناسبة" و"رسائل مناسبة من نوع محدد" (PGE_Message_Log::query_by_event_type())
     * كطرف أيسر (leftmost prefix) — فهرس event_id مستقل غير مضاف عمداً
     * لتفادي تكرار لا داعٍ له. batch_id وstatus فهرسان مستقلان يخدمان
     * query_by_batch() وفلترة الحالة (pending/failed) على التوالي.
     */
    private static function ensure_message_log_table(): bool
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::message_log_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            rsvp_id BIGINT(20) UNSIGNED NULL,
            lifecycle_started_at DATETIME NULL,
            guest_phone VARCHAR(32) NOT NULL DEFAULT '',
            message_type VARCHAR(20) NOT NULL,
            batch_id VARCHAR(36) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            provider VARCHAR(20) NULL,
            actor_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY event_type (event_id, message_type),
            KEY batch_id (batch_id),
            KEY status (status)
        ) $charset_collate;";

        dbDelta($sql);

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $found = [];
        foreach ($columns as $column) {
            $found[] = (string) ($column['Field'] ?? '');
        }

        // Phase 4A-2: dbDelta() creates the column on fresh installs. Existing
        // tables get the same additive column explicitly and idempotently.
        if (!in_array('lifecycle_started_at', $found, true)) {
            $added = $wpdb->query("ALTER TABLE $table ADD COLUMN lifecycle_started_at DATETIME NULL AFTER rsvp_id");
            if ($added === false) {
                return false;
            }

            $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
            if ($columns === null) {
                return false;
            }
            $found = [];
            foreach ($columns as $column) {
                $found[] = (string) ($column['Field'] ?? '');
            }
        }

        $required = ['id', 'event_id', 'rsvp_id', 'lifecycle_started_at', 'guest_phone', 'message_type', 'batch_id', 'status', 'provider', 'actor_user_id', 'created_at', 'sent_at'];

        foreach ($required as $r) {
            if (!in_array($r, $found, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * إضافة العمود الوحيد thank_you_sent_at على pge_event_rsvps — ALTER TABLE
     * صريح بعد التحقق من غيابه فعلياً عبر SHOW COLUMNS أولاً (Idempotent).
     * لا تعديل على أي عمود قائم — Additive فقط.
     */
    private static function ensure_thank_you_column(): bool
    {
        global $wpdb;

        $table = self::rsvps_table_name();

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $found = [];
        foreach ($columns as $column) {
            $found[] = (string) ($column['Field'] ?? '');
        }

        if (!in_array('thank_you_sent_at', $found, true)) {
            $result = $wpdb->query("ALTER TABLE $table ADD COLUMN thank_you_sent_at DATETIME NULL DEFAULT NULL");
            if ($result === false) {
                return false;
            }
        }

        // تحقّق نهائي فعلي — لا افتراض نجاح ALTER TABLE أعلاه بشكل أعمى.
        $columns_after = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns_after === null) {
            return false;
        }

        $found_after = [];
        foreach ($columns_after as $column) {
            $found_after[] = (string) ($column['Field'] ?? '');
        }

        return in_array('thank_you_sent_at', $found_after, true);
    }
}

register_activation_hook(PGE_PATH . 'pgevents-core.php', ['PGE_Messaging_Schema', 'maybe_upgrade']);
add_action('plugins_loaded', ['PGE_Messaging_Schema', 'maybe_upgrade']);
