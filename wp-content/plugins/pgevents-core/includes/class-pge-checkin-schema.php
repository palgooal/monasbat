<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Checkin Schema — Guest Check-in Engine، Phase 4
 * ============================================================================
 * "Guest Check-in Engine" RFC، Requirement 6: "Attendance Audit Log —
 * Append-only. Never overwrite history." وRequirement 3: "Store: Expected
 * Guest Count, Actual Entered Count, Check-in timestamp, Supervisor
 * assignment, Method."
 *
 * هذا الملف مسؤول حصراً عن بنية قاعدة البيانات لمحرك تسجيل الحضور:
 *   1. جدول جديد {$wpdb->prefix}pge_checkin_audit_log — Append-Only بحت،
 *      لا UPDATE ولا DELETE على أي صف من أي كود في المشروع إطلاقاً.
 *   2. أعمدة إضافية (Additive فقط، لا تعديل على أي عمود قائم) على الجدول
 *      الحالي {$wpdb->prefix}pge_event_rsvps (المُعرَّف أصلاً في
 *      includes/rsvp-handler.php منذ ما قبل هذه المرحلة):
 *        - checked_in_by_assignment_id: مرجع لإسناد المشرف (mon_event_
 *          supervisors.id عبر PGE_Supervisor_Assignment_Service) الذي نفَّذ
 *          العملية. **تنبيه معماري صريح:** وثيقة التصميم الأصلية
 *          (docs/ENTRY-SUPERVISORS-DESIGN.md §10.3) اقترحت اسم العمود
 *          `checked_in_by` بدلالة "WordPress user_id" — لكن معمارية Phase 3
 *          المُعتمَدة فعلياً ("Supervisor Authentication" RFC) قرَّرت صراحة ألا
 *          يملك المشرف حساب ووردبريس بالضرورة (جلسة مستقلة تماماً، راجع
 *          class-pge-supervisor-session.php). لذلك هذا العمود يُسمَّى بدقة
 *          `checked_in_by_assignment_id` ويخزّن معرّف الإسناد (assignment_id)
 *          لا أي user_id — القيمة الوحيدة المتاحة فعلياً من جلسة مشرف موثوقة.
 *        - checkin_method: 'qr' أو 'manual' فقط.
 *        - actual_entered_count: العدد الفعلي الذي أدخله المشرف عند التأكيد
 *          (يبقى NULL طالما checked_in = 0؛ يُملأ مرة واحدة فقط عند التأكيد
 *          الأول، ولا يُعدَّل بعدها من أي مسار في هذه المرحلة — راجع القسم 1.1
 *          من وثيقة التصميم: "التأكيد الأول نهائي وكامل دائماً").
 *
 * لا تعديل على أي عمود قائم في pge_event_rsvps (checked_in/checked_in_at
 * القديمان يبقيان كما هما تماماً، ويُكتَبان أيضاً من هذه المرحلة للتوافق مع
 * كل قراءة قائمة فعلاً في dashboard-main.php/event-guests.php التي تعتمد
 * عليهما) — الأعمدة الثلاثة أعلاه إضافة صرفة فقط.
 *
 * نمط الترقية هنا يطابق حرفياً Mon_Catalog_Schema::maybe_upgrade() (خيار
 * مُتَّبع، لا اختراع جديد): رقم إصدار مخزَّن في wp_options، dbDelta() لإنشاء
 * الجدول الجديد (موثوق لـCREATE TABLE فقط)، وSHOW COLUMNS + ALTER TABLE ADD
 * COLUMN صريح للتحقق من/إضافة الأعمدة على الجدول القائم (dbDelta() لا يُوثَق
 * به لتعديل جدول موجود مسبقاً — نفس القرار المُتَّخذ سابقاً في
 * upgrade_to_1_2_0() لتلك الفئة).
 *
 * ============================================================================
 * SCHEMA_VERSION 1.1.0 (Blocking Issue #1) — إزالة UNIQUE عن (event_id, guest_phone)
 * ============================================================================
 * "The protected entity is the RSVP / invitation record, NOT the phone
 * number... Two different RSVP / invitation records within the same event
 * may share the same phone number." — الجدول الإنتاجي الفعلي
 * wp_pge_event_rsvps (المُعرَّف أصلاً في includes/rsvp-handler.php منذ ما قبل
 * هذه المرحلة) يحمل `UNIQUE KEY event_phone (event_id, guest_phone)` منذ
 * إنشائه الأول — هذا يتعارض مباشرة مع القرار المعماري أعلاه. هذا الإصدار
 * (1.1.0) يضيف خطوة ترقية ثالثة (ensure_phone_index_not_unique()) تكتشف أي
 * فهرس UNIQUE قائم فعلياً على بالضبط (event_id, guest_phone) — بصرف النظر عن
 * اسمه في أي بيئة منشورة (لا نفترض أنه 'event_phone' حصراً) — وتحذفه، ثم
 * تُنشئ بدلاً منه فهرساً عادياً (غير فريد) بنفس الأعمدة `event_guest_phone
 * (event_id, guest_phone)` إن لم يكن موجوداً أصلاً، ثم تتحقق فعلياً (SHOW
 * INDEX) أن لا فهرس UNIQUE تبقّى على نفس الزوج. لا تعديل على أي عمود أو صف
 * قائم — فهرس فقط. راجع includes/class-pge-guest-resolution-service.php
 * للتغييرات المصاحبة على طبقة القراءة (البحث بالهاتف يجب أن يدعم أكثر من
 * نتيجة صراحة بعد إزالة هذا القيد).
 */
class PGE_Checkin_Schema
{
    const SCHEMA_VERSION = '1.1.0';
    const VERSION_OPTION = 'pge_checkin_schema_version';

    /** الأعمدة المستهدَفة بترتيبها الصحيح — لا تغييره (Blocking Issue #1). */
    const PHONE_INDEX_COLUMNS = ['event_id', 'guest_phone'];

    /** اسم الفهرس غير الفريد الجديد المُراد ضمانه. */
    const PHONE_INDEX_NAME = 'event_guest_phone';

    private static function audit_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_checkin_audit_log';
    }

    private static function rsvps_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'pge_event_rsvps';
    }

    /**
     * نقطة الدخول الوحيدة — آمنة للاستدعاء من register_activation_hook ومن
     * plugins_loaded على حد سواء (بنفس فلسفة Mon_Catalog_Schema::maybe_upgrade()).
     */
    public static function maybe_upgrade()
    {
        $stored_version = (string) get_option(self::VERSION_OPTION, '');

        if ($stored_version === self::SCHEMA_VERSION) {
            return;
        }

        $audit_ok = self::ensure_audit_table();
        $columns_ok = self::ensure_rsvp_columns();
        $phone_index_ok = self::ensure_phone_index_not_unique();

        if ($audit_ok && $columns_ok && $phone_index_ok) {
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
        }
        // فشل جزئي: لا يُحدَّث الإصدار المخزَّن، فيُعاد المحاولة كاملةً في
        // الطلب التالي (نفس فلسفة $migration_failed في Mon_Catalog_Schema).
    }

    /**
     * إنشاء جدول سجل التدقيق عبر dbDelta() — موثوق تماماً لـCREATE TABLE
     * (بخلاف ALTER TABLE على جدول قائم، راجع ensure_rsvp_columns() أدناه).
     * تُعيد true فقط بعد التحقق الفعلي من وجود الأعمدة المطلوبة (لا افتراض
     * نجاح dbDelta() بشكل أعمى).
     */
    private static function ensure_audit_table(): bool
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::audit_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            rsvp_id BIGINT(20) UNSIGNED NOT NULL,
            assignment_id BIGINT(20) UNSIGNED NOT NULL,
            method VARCHAR(10) NOT NULL,
            expected_count INT UNSIGNED NOT NULL,
            actual_count INT UNSIGNED NOT NULL,
            entry_type VARCHAR(12) NOT NULL DEFAULT 'confirmation',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY rsvp_id (rsvp_id)
        ) $charset_collate;";

        dbDelta($sql);

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required = ['id', 'event_id', 'rsvp_id', 'assignment_id', 'method', 'expected_count', 'actual_count', 'entry_type', 'created_at'];
        $found = [];
        foreach ($columns as $column) {
            $found[] = (string) ($column['Field'] ?? '');
        }

        foreach ($required as $r) {
            if (!in_array($r, $found, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * إضافة الأعمدة الثلاثة الجديدة على pge_event_rsvps — ALTER TABLE صريح
     * بعد التحقق من غيابها فعلياً عبر SHOW COLUMNS أولاً (Idempotent: تشغيل
     * متكرر لا يحاول إضافة عمود موجود أصلاً، فلا يفشل بخطأ "Duplicate column").
     */
    private static function ensure_rsvp_columns(): bool
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

        $to_add = [
            'checked_in_by_assignment_id' => 'BIGINT(20) UNSIGNED NULL',
            'checkin_method' => 'VARCHAR(10) NULL',
            'actual_entered_count' => 'INT UNSIGNED NULL',
        ];

        foreach ($to_add as $column_name => $definition) {
            if (in_array($column_name, $found, true)) {
                continue;
            }

            $result = $wpdb->query("ALTER TABLE $table ADD COLUMN $column_name $definition");
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

        foreach (array_keys($to_add) as $column_name) {
            if (!in_array($column_name, $found_after, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * تجميع مساعد: يقرأ SHOW INDEX FROM $table ويُعيد خريطة
     * [key_name => ['non_unique' => 0|1, 'columns' => [عمود بترتيب Seq_in_index]]]
     * بعد استبعاد PRIMARY (لا علاقة له بهذا الفحص). يُعيد null عند فشل القراءة.
     */
    private static function read_index_map($table): ?array
    {
        global $wpdb;

        $rows = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if ($rows === null) {
            return null;
        }

        $keys = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === '' || $name === 'PRIMARY') {
                continue;
            }
            $seq = (int) ($row['Seq_in_index'] ?? 0);
            if (!isset($keys[$name])) {
                $keys[$name] = ['non_unique' => (int) ($row['Non_unique'] ?? 1), 'columns' => []];
            }
            $keys[$name]['columns'][$seq] = (string) ($row['Column_name'] ?? '');
        }

        foreach ($keys as $name => $meta) {
            ksort($keys[$name]['columns']);
            $keys[$name]['columns'] = array_values($keys[$name]['columns']);
        }

        return $keys;
    }

    /**
     * ========================================================================
     * Blocking Issue #1 — إزالة أي فهرس UNIQUE على (event_id, guest_phone)
     * ========================================================================
     * "Remove the unique constraint... Replace it with a normal non-unique
     * index... The migration must safely detect and remove the existing
     * unique index before creating the normal index." — منطق حذف ثم إنشاء،
     * Idempotent بالكامل (استدعاء متكرر لا يفشل ولا يُنشئ فهارس مكرَّرة)، مع
     * تحقّق نهائي فعلي عبر SHOW INDEX (لا افتراض نجاح ALTER TABLE أعمى، بنفس
     * فلسفة ensure_rsvp_columns() أعلاه).
     */
    private static function ensure_phone_index_not_unique(): bool
    {
        global $wpdb;
        $table = self::rsvps_table_name();
        $target = self::PHONE_INDEX_COLUMNS;

        $keys = self::read_index_map($table);
        if ($keys === null) {
            return false;
        }

        // 1) حذف أي فهرس UNIQUE قائم فعلياً على بالضبط (event_id, guest_phone)
        //    — بصرف النظر عن اسمه (لا نفترض 'event_phone' حصراً في كل بيئة).
        foreach ($keys as $name => $meta) {
            if ($meta['columns'] === $target && (int) $meta['non_unique'] === 0) {
                $dropped = $wpdb->query("ALTER TABLE $table DROP INDEX $name");
                if ($dropped === false) {
                    return false;
                }
            }
        }

        // 2) إعادة قراءة الحالة بعد الحذف — التأكد من عدم وجود فهرس غير فريد
        //    مسبقاً بنفس الأعمدة قبل إنشاء واحد جديد (تجنّب فهارس مكرَّرة).
        $keys_after_drop = self::read_index_map($table);
        if ($keys_after_drop === null) {
            return false;
        }

        $has_non_unique_pair = false;
        foreach ($keys_after_drop as $meta) {
            if ($meta['columns'] === $target && (int) $meta['non_unique'] === 1) {
                $has_non_unique_pair = true;
                break;
            }
        }

        if (!$has_non_unique_pair) {
            $created = $wpdb->query(
                'ALTER TABLE ' . $table . ' ADD INDEX ' . self::PHONE_INDEX_NAME . ' (' . implode(', ', $target) . ')'
            );
            if ($created === false) {
                return false;
            }
        }

        // 3) التحقّق النهائي الفعلي (Verification المطلوب صراحةً):
        //    - يوجد فهرس على (event_id, guest_phone) بنفس ترتيب الأعمدة.
        //    - Non_unique = 1 لذلك الفهرس.
        //    - لا يوجد أي فهرس UNIQUE متبقٍّ على نفس الزوج.
        $final_keys = self::read_index_map($table);
        if ($final_keys === null) {
            return false;
        }

        $found_non_unique_pair = false;
        foreach ($final_keys as $meta) {
            if ($meta['columns'] === $target) {
                if ((int) $meta['non_unique'] === 0) {
                    return false;
                }
                $found_non_unique_pair = true;
            }
        }

        return $found_non_unique_pair;
    }
}

register_activation_hook(PGE_PATH . 'pgevents-core.php', ['PGE_Checkin_Schema', 'maybe_upgrade']);
add_action('plugins_loaded', ['PGE_Checkin_Schema', 'maybe_upgrade']);
