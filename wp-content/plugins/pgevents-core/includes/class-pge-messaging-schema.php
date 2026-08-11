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

        // الإصدار المخزّن Hint فقط: لا نتجاوز الفحص البنيوي إذا كان الجدول أو
        // العمود مفقوداً/غير متوافق بسبب Schema drift.
        if ($stored_version === self::SCHEMA_VERSION && self::schema_postconditions_hold()) {
            return;
        }

        $log_table_ok = self::ensure_message_log_table();
        $column_ok = self::ensure_thank_you_column();

        if ($log_table_ok && $column_ok && $stored_version !== self::SCHEMA_VERSION) {
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
        }
        // فشل جزئي: لا يُحدَّث الإصدار المخزَّن، فيُعاد المحاولة كاملةً في
        // الطلب التالي (نفس فلسفة الفشل الجزئي في PGE_Checkin_Schema).
    }

    /**
     * فحص read-only للعقد البنيوي الحالي. لا تكفي version وحدها لإثبات أن
     * الجدول والعمود الإضافي ما زالا موجودين بالعقد الصحيح.
     */
    private static function schema_postconditions_hold(): bool
    {
        $log_columns = self::read_table_columns(self::message_log_table_name());
        $rsvp_columns = self::read_table_columns(self::rsvps_table_name());

        return self::message_log_columns_are_valid($log_columns)
            && self::has_column($rsvp_columns, 'thank_you_sent_at');
    }

    /** @return array<int,array>|null */
    private static function read_table_columns(string $table): ?array
    {
        global $wpdb;

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        return is_array($columns) && !empty($columns) ? $columns : null;
    }

    /** @param array<int,array>|null $columns */
    private static function has_column(?array $columns, string $name): bool
    {
        if ($columns === null) {
            return false;
        }

        foreach ($columns as $column) {
            if ((string) ($column['Field'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,array>|null $columns */
    private static function message_log_columns_are_valid(?array $columns): bool
    {
        if ($columns === null) {
            return false;
        }

        $required = ['id', 'event_id', 'rsvp_id', 'lifecycle_started_at', 'guest_phone', 'message_type', 'batch_id', 'status', 'provider', 'actor_user_id', 'created_at', 'sent_at'];
        foreach ($required as $name) {
            if (!self::has_column($columns, $name)) {
                return false;
            }
        }

        foreach ($columns as $column) {
            if ((string) ($column['Field'] ?? '') !== 'lifecycle_started_at') {
                continue;
            }

            $type = strtolower(trim((string) ($column['Type'] ?? '')));
            $nullable = strtoupper(trim((string) ($column['Null'] ?? ''))) === 'YES';

            // نقبل تمثيل MySQL لـDATETIME مع precision اختيارية، لكن لا نقبل
            // نوعاً آخر أو NOT NULL. العقد الخاطئ يُكتشف فقط ولا يُعدَّل.
            return preg_match('/^datetime(?:\(\d+\))?$/', $type) === 1 && $nullable;
        }

        return false;
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

        $columns = self::read_table_columns($table);
        if ($columns === null) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
            $columns = self::read_table_columns($table);
            if ($columns === null) {
                return false;
            }
        }

        // Phase 4A-2: dbDelta() creates the column on fresh installs. Existing
        // tables get the same additive column explicitly and idempotently.
        if (!self::has_column($columns, 'lifecycle_started_at')) {
            $added = $wpdb->query("ALTER TABLE $table ADD COLUMN lifecycle_started_at DATETIME NULL AFTER rsvp_id");
            if ($added === false) {
                return false;
            }

            $columns = self::read_table_columns($table);
            if ($columns === null) {
                return false;
            }
        }

        return self::message_log_columns_are_valid($columns);
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
