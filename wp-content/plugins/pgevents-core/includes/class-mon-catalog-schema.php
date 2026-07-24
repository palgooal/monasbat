<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Mon Catalog Schema — الخطوة الأولى فقط من نظام كتالوج الباقات والخدمات
 * ============================================================================
 *
 * هذا الملف مسؤول حصرياً عن:
 *  - إنشاء/مزامنة 3 جداول: mon_plans, mon_plan_tiers, mon_services عبر dbDelta().
 *  - إدارة رقم إصدار قاعدة البيانات (mon_catalog_db_version) وتشغيل دوال
 *    الترقية المستقبلية بالترتيب عند الحاجة.
 *
 * لا يحتوي هذا الملف على: طبقة PGE_Catalog، CRUD، لوحة إدارة، ربط Webhook،
 * تفعيل باقات، أو أي تعديل على User Meta أو الجداول/الملفات الحالية.
 *
 * قرار التوقيتات (created_at/updated_at):
 * تم اعتماد نمط "DATETIME DEFAULT CURRENT_TIMESTAMP" و
 * "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" بناءً على
 * فحص فعلي لجدول {$wpdb->prefix}pge_event_rsvps الموجود حالياً في
 * includes/rsvp-handler.php (يستخدم نفس النمط تماماً ويعمل في هذه البيئة
 * تحديداً)، وليس افتراضاً جديداً. هذا يضمن التوافق مع نفس نسخة MySQL/MariaDB
 * التي يعمل عليها المشروع فعلياً دون الحاجة لتخمين نسخة الخادم.
 *
 * قرار التباعد حول PRIMARY KEY:
 * التوثيق التاريخي لـ dbDelta() يذكر ضرورة مسافتين بعد "PRIMARY KEY"، لكن
 * جدول pge_event_rsvps الفعلي في هذا المشروع يستخدم مسافة واحدة ويعمل بنجاح
 * (نفس السطر: "PRIMARY KEY (id),"). اعتمدت نفس تنسيق المسافة الواحدة هنا
 * للاتساق مع نمط مُثبت عملياً في هذه البيئة تحديداً بدل نمط موثّق نظرياً.
 */

class Mon_Catalog_Schema
{
    /**
     * رقم الإصدار الحالي لبنية كتالوج الباقات والخدمات.
     * أي تغيير مستقبلي في البنية أو ترحيل بيانات يرفع هذا الرقم.
     */
    const DB_VERSION = '1.3.0';

    /**
     * اسم الـ option الذي يخزّن آخر إصدار تم تطبيقه فعلياً على قاعدة البيانات.
     */
    const DB_VERSION_OPTION = 'mon_catalog_db_version';

    /**
     * نقطة الدخول الوحيدة. آمنة للاستدعاء من register_activation_hook ومن
     * plugins_loaded على حد سواء — إن كان الإصدار المخزَّن مطابقاً للحالي،
     * تنتهي الدالة فوراً دون أي استعلام إضافي.
     */
    public static function maybe_upgrade()
    {
        $stored_version = (string) get_option(self::DB_VERSION_OPTION, '');

        // الحالة 3: الإصدار مطابق — لا تُعِد تشغيل أي شيء في هذا الـ Request.
        if ($stored_version === self::DB_VERSION) {
            return;
        }

        // الحالتان 1 و2 تحتاجان مزامنة البنية أولاً (dbDelta آمنة للتكرار بطبيعتها).
        self::sync_schema();

        // الحالة 1: لا يوجد إصدار مخزَّن إطلاقاً — تثبيت أول (لا ترحيلات بيانات).
        if ($stored_version === '') {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
            return;
        }

        // الحالة 2: إصدار مخزَّن أقدم — تشغيل دوال الترقية المرتبة بين الإصدارين.
        // في هذا الإصدار (1.0.0) القائمة فارغة عمداً؛ الآلية مُجهَّزة فقط.
        $migration_failed = false;

        foreach (self::get_upgrade_routines() as $target_version => $callback) {
            // تخطَّ أي خطوة نُفذت مسبقاً أو تقع خارج نطاق الترقية الحالية.
            if (version_compare($stored_version, $target_version, '>=')) {
                continue;
            }
            if (version_compare($target_version, self::DB_VERSION, '>')) {
                continue;
            }

            $success = is_callable($callback) ? (bool) call_user_func($callback) : false;

            // التوقف عند أول فشل — لا يجوز الانتقال لخطوة لاحقة فوق بنية غير مكتملة،
            // ولا يجوز الادّعاء بنجاح الترقية النهائي (راجع الشرط بعد الحلقة).
            if (!$success) {
                $migration_failed = true;
                break;
            }

            // تحديث الإصدار فور نجاح كل خطوة على حدة، لا في النهاية فقط —
            // هذا يضمن استئناف الترقية من نقطة التوقف الصحيحة عند أي فشل لاحق.
            $stored_version = $target_version;
            update_option(self::DB_VERSION_OPTION, $stored_version);
        }

        // إن لم تفشل أي خطوة ترقية (سواء وُجدت خطوات فعلية ونجحت جميعها، أو لم
        // تكن هناك أي خطوة مطلوبة أصلاً بين الإصدار المخزَّن و DB_VERSION —
        // كحال إصدار قديم/اختباري لا يقابله مسار ترقية صريح في
        // get_upgrade_routines())، فهذا يعني أن sync_schema() أعلاه كافية
        // فعلاً لمطابقة البنية مع DB_VERSION. حدّث الإصدار المخزَّن ليطابقه
        // صراحةً، حتى لا يبقى النظام عالقاً بإصدار قديم يُعيد تشغيل dbDelta()
        // في كل Request لاحق بلا داعٍ. إن فشلت خطوة فعلية، $migration_failed
        // تمنع هذا التحديث ويبقى $stored_version عند آخر نقطة نجاح مسجَّلة.
        if (!$migration_failed) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    /**
     * إنشاء/مزامنة الجداول الثلاثة عبر dbDelta(). لا تُنشئ أي جدول رابع،
     * ولا تُعدّل أي جدول آخر في المشروع.
     */
    private static function sync_schema()
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(self::get_schema_sql());
    }

    /**
     * قائمة دوال الترقية المستقبلية، مرتبة برقم الإصدار المستهدف. كل دالة
     * يجب أن تُعيد true عند النجاح لتحديث الإصدار، أو false للتوقف. تُنفَّذ
     * هذه الدوال من داخل maybe_upgrade() بعد sync_schema() (أي بعد أن يكون
     * dbDelta() قد أضاف أي عمود جديد فعلياً)، وفقط عند الحاجة الفعلية —
     * ليست جزءاً من مسار الطلب العادي بعد اكتمال الترقية.
     */
    private static function get_upgrade_routines(): array
    {
        return [
            '1.1.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_1_0'],
            '1.2.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_2_0'],
            '1.3.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_3_0'],
        ];
    }

    /**
     * ترقية 1.1.0: تعبئة (Backfill) عمود mon_plan_tiers.name للصفوف القديمة
     * التي أضاف لها dbDelta() القيمة الافتراضية '' (فارغة) عند إضافة العمود
     * NOT NULL DEFAULT ''. لا تلمس أي صف قيمة name فيه غير فارغة أصلاً —
     * الشرط WHERE name = '' في كل من الـ SELECT والـ UPDATE يضمن ذلك، ويجعل
     * تشغيل هذه الدالة مرة ثانية بلا أي أثر إضافي (Idempotent) لأنها ببساطة
     * لن تجد أي صف يطابق الشرط بعد التنفيذ الأول.
     *
     * لكل صف: إن كان tier_key يطابق تماماً النمط ^guests_([1-9][0-9]*)$
     * (مثل guests_100)، يُستخرَج الرقم وتُبنى القيمة "{الرقم} مدعو" (مثل
     * "100 مدعو"). خلاف ذلك (أي نمط آخر لـtier_key)، تُستخدَم tier_key نفسها
     * كقيمة name المؤقتة بدل ترك الصف بلا اسم أو حذفه. لا id ولا plan_id ولا
     * tier_key ولا أي عمود آخر يتغيّر هنا — عمود name فقط.
     *
     * تُعيد false فقط إذا فشل استعلام SELECT الأولي فشلاً فعلياً (يوقف
     * الترقية بالكامل عبر maybe_upgrade())؛ فشل تحديث صف واحد فعلياً
     * ($wpdb->update() يُعيد false) يُسجَّل كفشل أيضاً لنفس السبب — لا يجوز
     * الادّعاء بنجاح ترقية تركت صفوفاً بلا اسم.
     */
    private static function upgrade_to_1_1_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_plan_tiers';

        $rows = $wpdb->get_results("SELECT id, tier_key FROM $table WHERE name = ''", ARRAY_A);

        if ($rows === null) {
            return false;
        }

        foreach ($rows as $row) {
            $tier_key = (string) $row['tier_key'];

            if (preg_match('/^guests_([1-9][0-9]*)$/', $tier_key, $matches)) {
                $name = $matches[1] . ' مدعو';
            } else {
                $name = $tier_key;
            }

            $updated = $wpdb->update(
                $table,
                ['name' => $name],
                ['id' => (int) $row['id'], 'name' => ''],
                ['%s'],
                ['%d', '%s']
            );

            if ($updated === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * ترقية 1.2.0: يسمح بمشاركة Product ID واحد بين عدة مستويات، ويجعل SKU
     * المعرّف الفريد الحقيقي للخيار. sync_schema() تضيف العمود والفهرس الجديد
     * أولاً، ثم تنظف هذه الخطوة أي فهرس قديم على salla_product_id وتعيده
     * كفهرس عادي فقط. التحقق النهائي يمنع تحديث رقم الإصدار عند بنية ناقصة.
     */
    private static function upgrade_to_1_2_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_plan_tiers';
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if ($indexes === null) {
            return false;
        }

        $product_index_names = [];
        foreach ($indexes as $index) {
            if (($index['Column_name'] ?? '') === 'salla_product_id' && ($index['Key_name'] ?? '') !== 'PRIMARY') {
                $product_index_names[(string) $index['Key_name']] = true;
            }
        }

        foreach (array_keys($product_index_names) as $index_name) {
            $safe_index_name = str_replace('`', '``', $index_name);
            $dropped = $wpdb->query("ALTER TABLE $table DROP INDEX `" . $safe_index_name . "`");
            if ($dropped === false) {
                return false;
            }
        }

        if ($wpdb->query("ALTER TABLE $table ADD INDEX salla_product_id (salla_product_id)") === false) {
            return false;
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if ($indexes === null) {
            return false;
        }

        $has_non_unique_product_index = false;
        $has_unique_sku_index = false;
        foreach ($indexes as $index) {
            if (
                ($index['Column_name'] ?? '') === 'salla_product_id'
                && (int) ($index['Non_unique'] ?? 0) === 1
            ) {
                $has_non_unique_product_index = true;
            }
            if (
                ($index['Column_name'] ?? '') === 'salla_sku'
                && (int) ($index['Non_unique'] ?? 1) === 0
            ) {
                $has_unique_sku_index = true;
            }
        }

        return $has_non_unique_product_index && $has_unique_sku_index;
    }

    /**
     * ترقية 1.3.0: تعبئة (Backfill) عمود mon_plan_tiers.events_count للمستويات
     * القديمة التي لم يُحدَّد لها عدد مناسبات صراحة (NULL) أو خُزِّنت بقيمة
     * صفر (0) — كلتا الحالتين تعنيان عملياً "غير محدَّد" وليس "صفر مناسبات
     * فعلي"، إذ لم يكن أي مسار CRUD في class-pge-catalog.php يكتب هذا العمود
     * إطلاقاً قبل هذا الإصدار (راجع normalize_events_count() الجديدة في تلك
     * الدالة). القرار التجاري: كل عملية شراء لأي Tier = مناسبة واحدة بالضبط
     * بغض النظر عن حدود المدعوين أو السعر أو المميزات — لذا القيمة الافتراضية
     * الصحيحة لأي صف "غير محدَّد" هي 1، وليس أي رقم آخر.
     *
     * لا تلمس أي صف events_count فيه قيمة > 0 فعلاً (5 مثلاً تبقى 5 دون أي
     * تغيير) — شرط WHERE في كل من SELECT وUPDATE يضمن ذلك، بنفس أسلوب
     * upgrade_to_1_1_0() تماماً، مما يجعل تشغيل هذه الدالة مرة ثانية بلا أي
     * أثر إضافي (Idempotent): بعد التنفيذ الأول لن يبقى أي صف events_count
     * فيه NULL أو 0، فلن يطابق أي صف شرط SELECT في أي تشغيل لاحق.
     *
     * تُعيد false فقط إذا فشل استعلام SELECT الأولي فشلاً فعلياً (يوقف
     * الترقية بالكامل عبر maybe_upgrade())؛ فشل تحديث صف واحد فعلياً
     * ($wpdb->update() يُعيد false) يُسجَّل كفشل أيضاً لنفس السبب — لا يجوز
     * الادّعاء بنجاح ترقية تركت صفوفاً بقيمة events_count خاطئة. لا تُلمَس
     * أي أعمدة أخرى (guest_limit، host_photos_limit، wa_messages_limit) هنا
     * إطلاقاً — خارج نطاق القرار التجاري لهذه المرحلة.
     */
    private static function upgrade_to_1_3_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_plan_tiers';

        $rows = $wpdb->get_results("SELECT id, events_count FROM $table WHERE events_count IS NULL OR events_count = 0", ARRAY_A);

        if ($rows === null) {
            return false;
        }

        foreach ($rows as $row) {
            $updated = $wpdb->update(
                $table,
                ['events_count' => 1],
                ['id' => (int) $row['id']],
                ['%d'],
                ['%d']
            );

            if ($updated === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * صياغة SQL للجداول الثلاثة، بصيغة متوافقة مع dbDelta() (كل عمود بسطر
     * مستقل، بلا FOREIGN KEY، بلا ENGINE، بلا ENUM).
     */
    private static function get_schema_sql(): array
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $table_plans    = $wpdb->prefix . 'mon_plans';
        $table_tiers    = $wpdb->prefix . 'mon_plan_tiers';
        $table_services = $wpdb->prefix . 'mon_services';

        $sql_plans = "CREATE TABLE $table_plans (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_key VARCHAR(64) NOT NULL,
            name VARCHAR(191) NOT NULL,
            plan_type VARCHAR(20) NOT NULL DEFAULT 'personal',
            is_custom_quote TINYINT(1) NOT NULL DEFAULT 0,
            services_discount_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
            features LONGTEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY plan_key (plan_key),
            KEY plan_type (plan_type),
            KEY status (status)
        ) $charset_collate;";

        $sql_tiers = "CREATE TABLE $table_tiers (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id BIGINT(20) UNSIGNED NOT NULL,
            tier_key VARCHAR(64) NOT NULL,
            name VARCHAR(190) NOT NULL DEFAULT '',
            guest_limit INT UNSIGNED NULL DEFAULT NULL,
            events_count INT UNSIGNED NULL DEFAULT 1,
            host_photos_limit INT UNSIGNED NULL DEFAULT NULL,
            wa_messages_limit INT UNSIGNED NULL DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(3) NOT NULL DEFAULT 'SAR',
            salla_product_id VARCHAR(64) NULL,
            salla_sku VARCHAR(100) NULL,
            salla_url VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY plan_id (plan_id),
            UNIQUE KEY tier_per_plan (plan_id, tier_key),
            KEY salla_product_id (salla_product_id),
            UNIQUE KEY salla_sku (salla_sku),
            KEY status (status)
        ) $charset_collate;";

        $sql_services = "CREATE TABLE $table_services (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            service_key VARCHAR(64) NOT NULL,
            name VARCHAR(191) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(3) NOT NULL DEFAULT 'SAR',
            salla_product_id VARCHAR(64) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY service_key (service_key),
            UNIQUE KEY salla_product_id (salla_product_id),
            KEY status (status)
        ) $charset_collate;";

        return [$sql_plans, $sql_tiers, $sql_services];
    }
}

// تسجيل تلقائي أول عند تفعيل الإضافة — نفس نمط pge_create_rsvp_table في rsvp-handler.php
register_activation_hook(PGE_PATH . 'pgevents-core.php', ['Mon_Catalog_Schema', 'maybe_upgrade']);

// شبكة أمان لتحديثات الكود بدون Deactivate/Activate — نفس فلسفة فحص pge_rewrite_version
// في pgevents-core.php، لكن هنا على plugins_loaded (أبكر من init) لأنه لا يعتمد على
// أي منشور نوع مسجَّل مسبقاً، والفرع السريع (تطابق الإصدار) لا يُنفّذ شيئاً فعلياً.
add_action('plugins_loaded', ['Mon_Catalog_Schema', 'maybe_upgrade']);
