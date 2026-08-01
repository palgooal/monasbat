<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Mon Catalog Schema — الخطوة الأولى فقط من نظام كتالوج الباقات والخدمات
 * ============================================================================
 *
 * هذا الملف مسؤول حصرياً عن:
 *  - إنشاء/مزامنة 8 جداول: mon_plans, mon_plan_tiers, mon_services,
 *    mon_invitation_credit_ledger (أُضيف في 1.5.0 — سجل ذري لاستهلاك رصيد
 *    الدعوات، راجع includes/class-pge-invitation-credit-ledger.php)،
 *    mon_replacement_entitlements (أُضيف في 1.7.0 — سجل استحقاقات
 *    Replacement Credits، راجع includes/class-pge-replacement-entitlements.php.
 *    تأسيس بنية فقط في هذه المرحلة — غير مربوط بأي مسار RSVP/Queue/Cartat بعد)،
 *    mon_tier_features (أُضيف في 1.8.0 — تخزين خام لقيم ميزات كل Tier، وفق
 *    docs/PACKAGE-FEATURE-MATRIX.md §5 وdocs/FEATURES-PHASE-2-SPEC.md Commit 1.
 *    Phase 2 — Commit 1: تأسيس الجدول فقط، بلا Repository وبلا أي استهلاك)،
 *    mon_event_supervisors (أُضيف في 1.10.0 — جدول إسناد مشرفي الدخول، Entry
 *    Check-in Supervisors Phase 1: تأسيس بنية فقط، بلا دعوات/قبول/تسجيل
 *    حضور فعلي بعد، راجع includes/supervisor-quota-resolver.php)،
 *    mon_supervisor_sessions (أُضيف في 1.12.0 — Entry Check-in Supervisors
 *    Phase 3 "Supervisor Authentication": جلسات مشرف مستقلة تماماً عن تسجيل
 *    دخول WordPress، راجع includes/class-pge-supervisor-session.php)
 *    عبر dbDelta().
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
    const DB_VERSION = '1.12.0';

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
            '1.4.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_4_0'],
            '1.5.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_5_0'],
            '1.6.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_6_0'],
            '1.7.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_7_0'],
            '1.8.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_8_0'],
            '1.9.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_9_0'],
            '1.10.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_10_0'],
            '1.11.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_11_0'],
            '1.12.0' => ['Mon_Catalog_Schema', 'upgrade_to_1_12_0'],
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
     * ترقية 1.4.0: إضافة عمودَي رصيد الدعوات (invitation_credit_limit،
     * replacement_credit_limit) إلى mon_plan_tiers — مرحلة "تأسيس بيانات
     * فقط" لنظام Invitation Credits Engine، بلا أي خصم أو استرداد فعلي بعد.
     *
     * على عكس upgrade_to_1_1_0()/upgrade_to_1_3_0()، لا تُنفّذ هذه الدالة أي
     * SELECT/UPDATE على صفوف موجودة: العمودان الجديدان NOT NULL DEFAULT 0،
     * وdbDelta() في sync_schema() (التي تُستدعى دائماً قبل هذه الدالة عبر
     * maybe_upgrade()) تُضيفهما عبر ALTER TABLE ADD COLUMN — وهذا يملأ كل
     * الصفوف الموجودة بالقيمة الافتراضية 0 تلقائياً بحكم قواعد MySQL نفسها،
     * دون حاجة لأي Backfill يدوي هنا. لا توجد بيانات NULL/0 "قديمة" لهذين
     * العمودين لأنهما لم يوجدا أصلاً قبل هذا الإصدار.
     *
     * دور هذه الدالة إذن تحقّق (Verification) فقط: تتأكد أن dbDelta() نجحت
     * فعلاً في إضافة العمودين قبل السماح لرقم الإصدار المخزَّن بالتقدّم إلى
     * 1.4.0 — بنفس فلسفة upgrade_to_1_2_0() (التحقق عبر SHOW INDEX هناك،
     * وSHOW COLUMNS هنا) بدل الافتراض الأعمى بنجاح dbDelta(). قراءة فقط بلا
     * أي كتابة، فهي Idempotent بديهياً: تشغيلها أي عدد من المرات يُعيد نفس
     * النتيجة طالما بنية الجدول لم تتغيّر.
     */
    private static function upgrade_to_1_4_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_plan_tiers';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);

        if ($columns === null) {
            return false;
        }

        $has_invitation_credit_limit = false;
        $has_replacement_credit_limit = false;

        foreach ($columns as $column) {
            $field_name = (string) ($column['Field'] ?? '');
            if ($field_name === 'invitation_credit_limit') {
                $has_invitation_credit_limit = true;
            }
            if ($field_name === 'replacement_credit_limit') {
                $has_replacement_credit_limit = true;
            }
        }

        return $has_invitation_credit_limit && $has_replacement_credit_limit;
    }

    /**
     * ترقية 1.5.0: إضافة جدول mon_invitation_credit_ledger — سجل ذري لاستهلاك
     * رصيد الدعوات (راجع PGE_Invitation_Credit_Ledger). مرحلة "تأسيس بنية
     * بيانات فقط": لا خصم/استرداد فعلي بعد، ولا أي صف يُنشأ هنا — الجدول
     * فارغ عند إنشائه، والقيد UNIQUE (credit_cycle_id, event_id, guest_phone,
     * credit_type) هو الحماية الذرية الحقيقية ضد الخصم المزدوج لاحقاً، لا أي
     * فحص تطبيقي "افحص ثم أدرج".
     *
     * نفس فلسفة upgrade_to_1_2_0()/upgrade_to_1_4_0(): تحقّق فعلي (SHOW
     * COLUMNS + SHOW INDEX) بدل الافتراض الأعمى بنجاح dbDelta() في
     * sync_schema() (التي أضافت الجدول عبر CREATE TABLE ضمن get_schema_sql()
     * قبل استدعاء هذه الدالة). قراءة فقط بلا أي كتابة — Idempotent بديهياً.
     *
     * التحقق من القيد UNIQUE يقارن ترتيب أعمدته بالضبط (عبر Seq_in_index)
     * مع الترتيب المطلوب صراحة في المهمة: credit_cycle_id ثم event_id ثم
     * guest_phone ثم credit_type — أي انحراف في الترتيب أو نقص أي عمود منها
     * يُعيد false ويمنع تقدّم رقم الإصدار.
     */
    private static function upgrade_to_1_5_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_invitation_credit_ledger';

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required_columns = [
            'id', 'user_id', 'credit_cycle_id', 'event_id', 'guest_phone',
            'credit_type', 'status', 'created_at', 'consumed_at', 'refunded_at',
        ];

        $found_columns = [];
        foreach ($columns as $column) {
            $found_columns[] = (string) ($column['Field'] ?? '');
        }

        foreach ($required_columns as $required_column) {
            if (!in_array($required_column, $found_columns, true)) {
                return false;
            }
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if ($indexes === null) {
            return false;
        }

        $unique_columns_by_seq = [];
        foreach ($indexes as $index) {
            if (($index['Key_name'] ?? '') === 'unique_credit_consumption') {
                $seq = (int) ($index['Seq_in_index'] ?? 0);
                $unique_columns_by_seq[$seq] = (string) ($index['Column_name'] ?? '');
            }
        }
        ksort($unique_columns_by_seq);

        $expected_unique_columns = ['credit_cycle_id', 'event_id', 'guest_phone', 'credit_type'];

        return array_values($unique_columns_by_seq) === $expected_unique_columns;
    }

    /**
     * ترقية 1.6.0: إضافة أعمدة "محاولة التسليم المملوكة" (Owned Delivery
     * Attempt) إلى mon_invitation_credit_ledger — المرحلة الثالثة A من
     * Invitation Credits Engine: ربط الخصم الفعلي بمسار Cartat Queue/Cron مع
     * حماية ذرية ضد التزامن. الأعمدة الثلاثة الجديدة (attempt_token،
     * attempt_started_at، last_attempt_at) NULL-able بطبيعتها — لا تحتاج أي
     * Backfill لصفوف قديمة (صفوف المرحلة الثانية جميعها reserved/consumed/
     * refunded بلا محاولة نشطة، فتبقى NULL بأمان تماماً كما تُنشئها dbDelta()
     * افتراضياً)، فدور هذه الدالة تحقّق (Verification) فقط بنفس فلسفة
     * upgrade_to_1_4_0()/upgrade_to_1_5_0() — قراءة SHOW COLUMNS فقط، بلا أي
     * كتابة، فهي Idempotent بديهياً.
     *
     * حالة status الجديدة 'failed' لا تحتاج أي تعديل في Schema نفسه — العمود
     * status يبقى VARCHAR(20) كما هو (بلا ENUM، بنفس نمط المشروع)؛ القيم
     * المسموحة (reserved/consumed/refunded/failed الآن) تُفرَض حصراً على
     * مستوى التطبيق عبر PGE_Invitation_Credit_Ledger::normalize_status()،
     * تماماً كما كانت الحالات الثلاث الأولى مُفروضة هناك فقط.
     */
    private static function upgrade_to_1_6_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_invitation_credit_ledger';

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required_columns = ['attempt_token', 'attempt_started_at', 'last_attempt_at'];

        $found_columns = [];
        foreach ($columns as $column) {
            $found_columns[] = (string) ($column['Field'] ?? '');
        }

        foreach ($required_columns as $required_column) {
            if (!in_array($required_column, $found_columns, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ترقية 1.7.0: إضافة جدول mon_replacement_entitlements — سجل استحقاقات
     * Replacement Credits (راجع PGE_Replacement_Entitlements). المرحلة 4A من
     * Invitation Credits Engine: "تأسيس بنية الاستحقاقات فقط" — لا ربط بمسار
     * RSVP، لا Queue، لا Cartat، لا إرسال replacement فعلي بعد. الجدول فارغ
     * عند إنشائه، والقيد UNIQUE (credit_cycle_id, event_id, source_guest_phone)
     * هو الحماية الذرية ضد منح استحقاق مزدوج لنفس المعتذر، بنفس فلسفة
     * unique_credit_consumption في mon_invitation_credit_ledger تماماً.
     *
     * نفس فلسفة upgrade_to_1_5_0(): تحقّق فعلي (SHOW COLUMNS + SHOW INDEX)
     * بدل الافتراض الأعمى بنجاح dbDelta() في sync_schema() (التي أضافت
     * الجدول عبر CREATE TABLE ضمن get_schema_sql() قبل استدعاء هذه الدالة).
     * قراءة فقط بلا أي كتابة — Idempotent بديهياً.
     *
     * التحقق من القيد UNIQUE يقارن ترتيب أعمدته بالضبط (عبر Seq_in_index) مع
     * الترتيب المطلوب صراحة: credit_cycle_id ثم event_id ثم source_guest_phone
     * — أي انحراف في الترتيب أو نقص أي عمود منها يُعيد false ويمنع تقدّم رقم
     * الإصدار. لا تحقّق هنا من الفهارس العادية الثلاثة (user_cycle_status/
     * source_ledger_id/consumed_by_ledger_id) — بنفس نطاق upgrade_to_1_5_0()
     * التي لم تتحقق من الفهارس العادية لجدول Ledger أيضاً، فقط الأعمدة
     * والقيد UNIQUE.
     */
    private static function upgrade_to_1_7_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_replacement_entitlements';

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required_columns = [
            'id', 'user_id', 'credit_cycle_id', 'event_id', 'source_guest_phone',
            'source_ledger_id', 'status', 'consumed_by_ledger_id', 'granted_at',
            'consumed_at', 'created_at', 'updated_at',
        ];

        $found_columns = [];
        foreach ($columns as $column) {
            $found_columns[] = (string) ($column['Field'] ?? '');
        }

        foreach ($required_columns as $required_column) {
            if (!in_array($required_column, $found_columns, true)) {
                return false;
            }
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if ($indexes === null) {
            return false;
        }

        $unique_columns_by_seq = [];
        foreach ($indexes as $index) {
            if (($index['Key_name'] ?? '') === 'unique_replacement_entitlement') {
                $seq = (int) ($index['Seq_in_index'] ?? 0);
                $unique_columns_by_seq[$seq] = (string) ($index['Column_name'] ?? '');
            }
        }
        ksort($unique_columns_by_seq);

        $expected_unique_columns = ['credit_cycle_id', 'event_id', 'source_guest_phone'];

        return array_values($unique_columns_by_seq) === $expected_unique_columns;
    }

    /**
     * ترقية 1.8.0: إضافة جدول mon_tier_features — تخزين خام (Raw Storage) لقيم
     * ميزات كل Tier، وفق docs/PACKAGE-FEATURE-MATRIX.md §5 وdocs/FEATURES-
     * PHASE-2-SPEC.md Commit 1 (Phase 2 — Tier Features Storage). مرحلة "تأسيس
     * الجدول فقط" — بلا Repository، بلا أي استهلاك من أي مسار آخر بعد.
     *
     * feature_key VARCHAR(64) — الطول معتمد صراحةً عبر DEC-001 في
     * docs/DECISION-LOG.md (أطول مفتاح فعلي في Feature Registry 36 حرفاً،
     * وVARCHAR(64) يوفر هامشاً للتوسع دون أثر سلبي على الفهرس المركب). لا عمود
     * value_type هنا — قرار معماري صريح في PACKAGE-FEATURE-MATRIX.md §5. لا
     * FOREIGN KEY — بنفس نمط بقية جداول هذا الملف.
     *
     * نفس فلسفة upgrade_to_1_5_0()/upgrade_to_1_7_0(): تحقّق فعلي (SHOW
     * COLUMNS + SHOW INDEX) بدل الافتراض الأعمى بنجاح dbDelta() في
     * sync_schema() (التي أضافت الجدول عبر CREATE TABLE ضمن get_schema_sql()
     * قبل استدعاء هذه الدالة). قراءة فقط بلا أي كتابة — Idempotent بديهياً.
     *
     * التحقق من القيد UNIQUE يقارن ترتيب أعمدته بالضبط (عبر Seq_in_index) مع
     * الترتيب المطلوب صراحة في PACKAGE-FEATURE-MATRIX.md §5: tier_id ثم
     * feature_key — أي انحراف في الترتيب أو نقص أي عمود منها يُعيد false
     * ويمنع تقدّم رقم الإصدار.
     */
    private static function upgrade_to_1_8_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_tier_features';

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required_columns = [
            'id', 'tier_id', 'feature_key', 'feature_value', 'created_at', 'updated_at',
        ];

        $found_columns = [];
        foreach ($columns as $column) {
            $found_columns[] = (string) ($column['Field'] ?? '');
        }

        foreach ($required_columns as $required_column) {
            if (!in_array($required_column, $found_columns, true)) {
                return false;
            }
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if ($indexes === null) {
            return false;
        }

        $unique_columns_by_seq = [];
        foreach ($indexes as $index) {
            if (($index['Key_name'] ?? '') === 'tier_feature') {
                $seq = (int) ($index['Seq_in_index'] ?? 0);
                $unique_columns_by_seq[$seq] = (string) ($index['Column_name'] ?? '');
            }
        }
        ksort($unique_columns_by_seq);

        $expected_unique_columns = ['tier_id', 'feature_key'];

        return array_values($unique_columns_by_seq) === $expected_unique_columns;
    }

    /**
     * ترقية 1.9.0: إضافة عمودَي حصة المناسبات (event_quota_mode،
     * event_quota_limit) إلى mon_plan_tiers — Event Quota Architecture
     * (خاصية تجارية على مستوى Tier، خارج Feature Registry/Resolver/Snapshot
     * الميزات تماماً، حسب القرار المعماري المعتمد).
     *
     * event_quota_mode: VARCHAR(20) NOT NULL DEFAULT 'limited' — نصّي وليس
     * ENUM، بنفس نمط status في mon_plans/mon_plan_tiers/mon_services وbقية
     * أعمدة الحالة في هذا الملف (راجع تعليق upgrade_to_1_6_0() حول نفس
     * القرار). القيمتان المسموحتان (limited/unlimited) تُفرَضان على مستوى
     * التطبيق فقط عبر PGE_Catalog::normalize_event_quota_mode() (لاحقاً)،
     * لا عبر قيد قاعدة بيانات — هذا يبقي الباب مفتوحاً لحالة تجارية ثالثة
     * مستقبلاً (مثل add-on مُقاس) بلا أي ALTER TABLE إضافي.
     *
     * event_quota_limit: INT UNSIGNED NOT NULL DEFAULT 1 — رقم صريح دائماً،
     * لا NULL. القيمة ذات معنى فقط عندما event_quota_mode = 'limited'؛ عند
     * 'unlimited' تُتجاهَل القيمة بالكامل على مستوى التطبيق. هذا يختلف
     * عمداً عن events_count (NULL-able، NULL يُطبَّع إلى 1 عبر
     * normalize_events_count()) — هنا "غير محدود" حالة صريحة في
     * event_quota_mode، لا حالة مُستنتَجة من غياب/فراغ event_quota_limit،
     * تفادياً للالتباس الذي يعانيه _mon_guest_limit اليوم بين "لم يُفعَّل
     * إطلاقاً" و"NULL مقصودة" (كلاهما يُقرآن كسلسلة فارغة من User Meta).
     *
     * كلا العمودين NOT NULL DEFAULT — فdbDelta() في sync_schema() (تُستدعى
     * دائماً قبل هذه الدالة عبر maybe_upgrade()) تملأ كل الصفوف الموجودة
     * بالقيمتين الافتراضيتين ('limited', 1) تلقائياً بحكم قواعد MySQL نفسها
     * عند ALTER TABLE ADD COLUMN، دون أي Backfill يدوي هنا — بنفس فلسفة
     * upgrade_to_1_4_0() بالضبط (invitation_credit_limit/
     * replacement_credit_limit، نفس النمط NOT NULL DEFAULT رقمي). هذا يعني
     * أيضاً أن كل Tier موجود اليوم (مرحلة تطوير، بلا بيانات إنتاج فعلية)
     * يستمر بسلوك "باقة واحدة = مناسبة واحدة" فوراً وتلقائياً بعد هذه
     * الترقية، بلا أي سطر ترحيل بيانات إضافي — لا حاجة لمهاجرة بيانات
     * Tier قديمة هنا إطلاقاً (بخلاف upgrade_to_1_3_0() التي احتاجت Backfill
     * فعلياً لأن events_count كانت NULL-able بلا Default رقمي وقتها).
     *
     * دور هذه الدالة إذن تحقّق (Verification) فقط، بنفس فلسفة
     * upgrade_to_1_4_0(): SHOW COLUMNS للتأكد أن dbDelta() نجحت فعلاً في
     * إضافة العمودين قبل السماح لرقم الإصدار المخزَّن بالتقدّم إلى 1.9.0،
     * بدل الافتراض الأعمى بنجاحها. قراءة فقط بلا أي كتابة — Idempotent
     * بديهياً: تشغيلها أي عدد من المرات يُعيد نفس النتيجة طالما بنية
     * الجدول لم تتغيّر.
     */
    private static function upgrade_to_1_9_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_plan_tiers';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);

        if ($columns === null) {
            return false;
        }

        $has_event_quota_mode = false;
        $has_event_quota_limit = false;

        foreach ($columns as $column) {
            $field_name = (string) ($column['Field'] ?? '');
            if ($field_name === 'event_quota_mode') {
                $has_event_quota_mode = true;
            }
            if ($field_name === 'event_quota_limit') {
                $has_event_quota_limit = true;
            }
        }

        return $has_event_quota_mode && $has_event_quota_limit;
    }

    /**
     * ترقية 1.10.0: إضافة جدول mon_event_supervisors — Entry Check-in
     * Supervisors، Phase 1 ("Supervisor Entitlement Foundation"). تأسيس بنية
     * "جدول إسناد المشرفين" فقط (وفق نص RFC حرفياً: "Only the supervisor
     * assignment table. Do NOT create audit tables yet. Do NOT create
     * check-in tables. Do NOT create invitation flow.") — لا جدول تدقيق، لا
     * جدول تسجيل حضور، لا منطق دعوة/قبول/رمز فعلي في هذه المرحلة. الجدول
     * فارغ عند إنشائه.
     *
     * event_id (لا user_id مركزي/لا credit_cycle_id): الإسناد هنا على مستوى
     * المناسبة مباشرة، بما يطابق القرار التجاري النهائي في RFC ("Supervisor
     * limit is PER EVENT. This decision is final. Do NOT implement per-user
     * limits.") — عدة صفوف بنفس event_id = عدة مشرفين لنفس المناسبة (يدعم
     * "multiple supervisors per event" صراحة)، وعدم ربط الجدول بأي
     * credit_cycle_id يترك الباب مفتوحاً طبيعياً لسيناريو "نفس المشرف يُسنَد
     * لعدة مناسبات مستقبلاً" (future multi-event supervisors) بلا أي قيد
     * بنيوي يمنع ذلك.
     *
     * user_id NULL-able: يُملأ لاحقاً (Phase غير هذه) بعد اكتمال دورة قبول
     * الدعوة وربط المشرف بمستخدم WordPress فعلي؛ في هذه المرحلة الأعمدة
     * الهوياتية الوحيدة المضمونة الوجود عند الإدراج هي supervisor_phone/
     * supervisor_name — بلا أي منطق إدراج فعلي بعد (لا AJAX، لا CRUD، مجرد
     * بنية الجدول).
     *
     * status VARCHAR(20) لا ENUM (بنفس نمط status في بقية جداول هذا الملف)
     * — قيمة افتراضية 'invited' فقط كموضع نائب لدورة حياة الدعوة المستقبلية
     * (invited/pending/active/revoked/expired، راجع docs/ENTRY-SUPERVISORS-
     * DESIGN.md §5) بلا أي منطق انتقال حالة فعلي في هذا الـCommit — القيمة
     * تُستهلَك فقط لاحقاً من pge_resolve_supervisor_quota_status() (Phase 1
     * نفسها، لكن كقراءة إحصائية بحتة: عدّ الصفوف بحالة غير revoked/expired،
     * بلا أي كتابة أو تغيير حالة من هذا الـResolver).
     *
     * ── تصحيح معماري (قبل موافقة Phase 1 — لم يُنشَر هذا الجدول بعد على أي
     * بيئة، فالتصحيح هنا مباشر على نفس 1.10.0، لا خطوة ترقية إضافية) ─────────
     *
     * القيد السابق UNIQUE (event_id, supervisor_phone) كان خطأً معمارياً:
     * يتعارض مع دورة حياة المشرف المعتمدة (invited → pending → active →
     * revoked/expired) والقاعدة الصريحة "دعوة جديدة يجب أن تُنشئ صفاً جديداً
     * دائماً — لا يجوز تعديل صف تاريخي لمحاكاة دعوة جديدة" (Append-Only
     * History). القيد الفريد كان سيمنع تماماً السيناريو المشروع التالي: دعوة
     * هاتف ← قبول ← إلغاء لاحقاً (revoked) ← دعوة نفس الهاتف مرة أخرى — إذ أن
     * دعوة ثانية لاحقة لنفس (event_id, supervisor_phone) تصطدم بنفس القيد
     * الفريد بصرف النظر عن أن الصف الأول أصبح revoked فعلاً.
     *
     * الحل المعتمد: **إزالة القيد الفريد نهائياً**، واستبداله بفهرس عادي
     * (Non-Unique) فقط: KEY event_phone (event_id, supervisor_phone) — لتسريع
     * الاستعلامات على نفس التركيبة (event_id, supervisor_phone) بلا أي فرض
     * تفرّد على مستوى قاعدة البيانات. التاريخ الآن Append-Only بحق: أي عدد من
     * الصفوف (نشطة أو منتهية) لنفس (event_id, supervisor_phone) قد يتعايش
     * معاً؛ لا صف قديم يُحدَّث أبداً لمحاكاة دعوة جديدة — فقط INSERT صفوف
     * جديدة (Phase 2، غير موجود في هذا الـCommit).
     *
     * القاعدة التجارية (تُطبَّق تطبيقياً في Phase 2 فقط، غير موجودة هنا):
     * لا يجوز وجود أكثر من إسناد "نشط" واحد لنفس (event_id, supervisor_phone
     * المُطبَّع) في آنٍ واحد — والحالات "النشطة" هي invited/pending/active
     * حصراً (revoked/expired مُستبعَدتان تماماً، بنفس تعريف "نشط" المُعتمَد
     * أصلاً في pge_count_active_event_supervisors() هذا الـCommit نفسه). هذا
     * التفرّد **لا يُفرَض بقيد قاعدة بيانات إطلاقاً** — سيُنفَّذ حصراً على
     * مستوى منطق إنشاء الدعوة في Phase 2 (SELECT فحص الحالات النشطة أولاً ثم
     * INSERT)، محمياً بقفل GET_LOCK (أو ما يعادله من حماية تزامن حقيقية) بنفس
     * الفلسفة المعتمدة فعلياً في هذا المشروع لمنع Race Condition مطابق تماماً
     * (راجع PGE_Invitation_Credit_Ledger::claim_for_delivery() وقفل
     * pge_handle_event_creation() لحصة المناسبات Commit 6) — قفل مُشتق من
     * (event_id, supervisor_phone المُطبَّع) يمنع طلبين متزامنين من إنشاء
     * إسنادين "نشطين" معاً لنفس الهاتف ونفس المناسبة. توثيق فقط في هذا
     * الـCommit — **لا تنفيذ فعلي لأي قفل أو دالة إنشاء دعوة هنا إطلاقاً**.
     *
     * نفس فلسفة upgrade_to_1_5_0()/upgrade_to_1_7_0(): تحقّق فعلي (SHOW
     * COLUMNS + SHOW INDEX) بدل الافتراض الأعمى بنجاح dbDelta() في
     * sync_schema() (التي أضافت الجدول عبر CREATE TABLE ضمن get_schema_sql()
     * قبل استدعاء هذه الدالة). قراءة فقط بلا أي كتابة — Idempotent بديهياً.
     * التحقق هنا يثبت أمرين معاً: (أ) الفهرس event_phone موجود بترتيب أعمدة
     * صحيح، (ب) هذا الفهرس **غير فريد** (Non_unique = 1) — فشل أي منهما يمنع
     * تقدّم رقم الإصدار.
     */
    private static function upgrade_to_1_10_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_event_supervisors';

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required_columns = [
            'id', 'event_id', 'user_id', 'supervisor_phone', 'supervisor_name',
            'status', 'invited_by_user_id', 'invited_at', 'accepted_at',
            'revoked_at', 'created_at', 'updated_at',
        ];

        $found_columns = [];
        foreach ($columns as $column) {
            $found_columns[] = (string) ($column['Field'] ?? '');
        }

        foreach ($required_columns as $required_column) {
            if (!in_array($required_column, $found_columns, true)) {
                return false;
            }
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if ($indexes === null) {
            return false;
        }

        $event_phone_columns_by_seq = [];
        $event_phone_is_unique = null;
        foreach ($indexes as $index) {
            if (($index['Key_name'] ?? '') === 'event_phone') {
                $seq = (int) ($index['Seq_in_index'] ?? 0);
                $event_phone_columns_by_seq[$seq] = (string) ($index['Column_name'] ?? '');
                // Non_unique: 1 = فهرس عادي، 0 = فريد — كل صفوف نفس الفهرس
                // تحمل نفس القيمة فعلياً في MySQL، تكفي قراءتها من أي صف.
                $event_phone_is_unique = ((int) ($index['Non_unique'] ?? 1)) === 0;
            }
        }
        ksort($event_phone_columns_by_seq);

        $expected_columns = ['event_id', 'supervisor_phone'];
        $columns_match = array_values($event_phone_columns_by_seq) === $expected_columns;

        // يجب أن يكون الفهرس موجوداً، بالترتيب الصحيح، **وغير فريد** — أي
        // بقاء القيد فريداً (رجوع غير مقصود لما قبل التصحيح المعماري) يُفشل
        // هذا التحقق عمداً.
        return $columns_match && $event_phone_is_unique === false;
    }

    /**
     * ترقية 1.11.0: إضافة عمود invitation_token_hash إلى mon_event_supervisors
     * — Entry Check-in Supervisors، Phase 2 ("Supervisor Invitation Lifecycle"،
     * Requirement 3: "Generate a secure random token. Store only a hash.
     * Never store the raw token."). Phase 1 لم تحتج هذا العمود إطلاقاً (لا
     * دعوات فعلية بعد)؛ Phase 2 هي أول من يستهلك دورة حياة الدعوة الفعلية.
     *
     * invitation_token_hash VARCHAR(64) NULL: طول 64 حرفاً بالضبط = ناتج
     * hash('sha256', ...) بصيغة hex (32 بايت × حرفين لكل بايت). NULL-able
     * بطبيعتين مختلفتين: (أ) الصف لم يُنشأ بعد عبر مسار Phase 2 (صفوف
     * افتراضية/اختبارية قديمة)، (ب) التوكن أُبطِل فعلياً بعد القبول (Requirement
     * 4: "Invalidate token" — يُصفَّر العمود صراحة إلى NULL بدل الاحتفاظ بهاش
     * توكن مُستهلَك، بنفس فلسفة مسح attempt_token في
     * PGE_Invitation_Credit_Ledger::mark_consumed_with_token() تماماً: لا
     * فائدة أمنية أو تشغيلية من الاحتفاظ بهاش توكن انتهت صلاحيته).
     *
     * القيمة الخام (raw token) لا تُخزَّن في أي عمود إطلاقاً — تُعاد فقط مرة
     * واحدة من create_supervisor_assignment() (راجع
     * class-pge-supervisor-assignment-service.php) للمستدعي مباشرة، ليُضمِّنها
     * لاحقاً في رابط الدعوة (خارج نطاق Phase 2 — لا UI/رسائل هنا).
     *
     * UNIQUE KEY على invitation_token_hash: بخلاف القيد المحظور على
     * (event_id, supervisor_phone) — الذي أُزيل معمارياً (راجع تعليق
     * upgrade_to_1_10_0() أعلاه) لأنه يمثّل هوية طبيعية قابلة لإعادة
     * الاستخدام عبر الزمن — التوكن هنا قيمة عشوائية طارئة/لمرة واحدة؛ تفرّده
     * ليس قيداً تجارياً بل ضمان أمني (لا يجوز أبداً لصفَّين مختلفين أن يحملا
     * نفس التوكن الفعّال في آنٍ واحد). قيم NULL المتعددة لا تنتهك القيد
     * الفريد في MySQL (كل NULL يُعامَل كقيمة مختلفة)، فلا تعارض بين عدة صفوف
     * أُبطِلت توكناتها معاً.
     *
     * نفس فلسفة upgrade_to_1_4_0()/upgrade_to_1_10_0(): تحقّق فعلي (SHOW
     * COLUMNS + SHOW INDEX) بدل الافتراض الأعمى بنجاح dbDelta(). قراءة فقط
     * بلا أي كتابة — Idempotent بديهياً.
     */
    private static function upgrade_to_1_11_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_event_supervisors';

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $has_token_hash_column = false;
        foreach ($columns as $column) {
            if (($column['Field'] ?? '') === 'invitation_token_hash') {
                $has_token_hash_column = true;
                break;
            }
        }
        if (!$has_token_hash_column) {
            return false;
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if ($indexes === null) {
            return false;
        }

        $token_hash_is_unique = false;
        foreach ($indexes as $index) {
            if (($index['Key_name'] ?? '') === 'invitation_token_hash') {
                $token_hash_is_unique = ((int) ($index['Non_unique'] ?? 1)) === 0;
                break;
            }
        }

        return $token_hash_is_unique;
    }

    /**
     * ترقية 1.12.0: إضافة جدول mon_supervisor_sessions — Entry Check-in
     * Supervisors، Phase 3 ("Supervisor Authentication" RFC، Requirement 1:
     * "Create a dedicated Supervisor Session. Do NOT reuse WordPress login.
     * Do NOT require WP accounts."). جلسة مستقلة تماماً عن wp_users/
     * wp_sessions — لا صف هنا يشير إلى أي مستخدم WordPress، فقط إلى صف إسناد
     * (assignment_id) في mon_event_supervisors (Phase 1/2).
     *
     * راجع includes/class-pge-supervisor-session.php للتفصيل الكامل حول
     * منطق الإنشاء/التحقق/الإلغاء. هذا الملف مسؤول عن بنية الجدول فقط.
     *
     * session_token_hash VARCHAR(64) UNIQUE: بنفس فلسفة invitation_token_hash
     * تماماً (راجع تعليق upgrade_to_1_11_0()) — التوكن الخام (32 بايت عشوائية
     * حقيقية، bin2hex → 64 حرف hex) لا يُخزَّن أبداً في أي عمود؛ فقط
     * sha256(raw) يُخزَّن، والتحقق لاحقاً عبر مقارنة الهاش. بخلاف
     * invitation_token_hash (يُصفَّر إلى NULL بعد الاستهلاك لمرة واحدة)، عمود
     * هذا الجدول لا يُصفَّر أبداً بعد الإنشاء — الجلسة نفسها (لا التوكن) هي ما
     * يُبطَل عبر revoked_at عند تسجيل الخروج الصريح؛ التوكن يبقى المفتاح
     * الوحيد لإيجاد الصف طوال عمره.
     *
     * event_id مُكرَّر (denormalized) من assignment_id: يسمح بفحص "الجلسة
     * تخص هذه المناسبة بالتحديد" مباشرة من صف الجلسة نفسه دون أي JOIN إضافي؛
     * PGE_Supervisor_Session::validate_session() تُعيد قراءة event_id الحيّة
     * من صف الإسناد الفعلي في mon_event_supervisors أيضاً كتحقّق دفاعي إضافي
     * (Requirement 5: "Assignment belongs to event")، فلا اعتماد أعمى على
     * هذا العمود المُكرَّر وحده.
     *
     * revoked_at NULL-able: يُكتَب فقط عند تسجيل خروج صريح (Requirement 6).
     * **لا** يُكتَب أبداً تلقائياً عند إلغاء الإسناد نفسه (revoke_supervisor_
     * assignment() في Phase 2 يبقى دون أي تعديل في هذا الـCommit، ولا أي كود
     * هنا يكتب على mon_event_supervisors) — إبطال الجلسات عند إلغاء الإسناد
     * (Requirement 7: "all existing sessions... become invalid immediately")
     * محقَّق فعلياً عبر إعادة قراءة حالة الإسناد الحيّة (status) في كل
     * validate_session()، لا عبر أي كتابة متتالية (Fan-out) على صفوف جلسات
     * قد تكون كثيرة — إذا أصبحت status ≠ 'active'، أي جلسة قائمة لنفس
     * assignment_id تُرفَض فوراً في الطلب التالي مباشرة، بلا أي تأخير أو
     * مهمة خلفية (Cron) مطلوبة.
     *
     * expires_at NOT NULL: يُكتَب صراحة عند الإنشاء = issued_at + مهلة صلاحية
     * (PGE_Supervisor_Session::SESSION_TTL_SECONDS) — قيمة هندسية افتراضية
     * (12 ساعة)، **ليست قاعدة عمل تجارية** بحاجة تفويض منتج، بنفس منطق اختيار
     * مهلة GET_LOCK=5 ثوانٍ في create_supervisor_assignment() (Phase 2) —
     * قابلة للتعديل مستقبلاً دون أي أثر معماري.
     *
     * نفس فلسفة upgrade_to_1_11_0(): تحقّق فعلي (SHOW COLUMNS + SHOW INDEX)
     * بدل الافتراض الأعمى بنجاح dbDelta() في sync_schema() (التي أضافت
     * الجدول عبر CREATE TABLE ضمن get_schema_sql() قبل استدعاء هذه الدالة).
     * قراءة فقط بلا أي كتابة — Idempotent بديهياً.
     */
    private static function upgrade_to_1_12_0(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mon_supervisor_sessions';

        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        if ($columns === null) {
            return false;
        }

        $required_columns = [
            'id', 'assignment_id', 'event_id', 'session_token_hash',
            'issued_at', 'expires_at', 'revoked_at', 'created_at', 'updated_at',
        ];

        $found_columns = [];
        foreach ($columns as $column) {
            $found_columns[] = (string) ($column['Field'] ?? '');
        }

        foreach ($required_columns as $required_column) {
            if (!in_array($required_column, $found_columns, true)) {
                return false;
            }
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
        if ($indexes === null) {
            return false;
        }

        $token_hash_is_unique = false;
        foreach ($indexes as $index) {
            if (($index['Key_name'] ?? '') === 'session_token_hash') {
                $token_hash_is_unique = ((int) ($index['Non_unique'] ?? 1)) === 0;
                break;
            }
        }

        return $token_hash_is_unique;
    }

    /**
     * صياغة SQL للجداول الثمانية، بصيغة متوافقة مع dbDelta() (كل عمود بسطر
     * مستقل، بلا FOREIGN KEY، بلا ENGINE، بلا ENUM).
     */
    private static function get_schema_sql(): array
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $table_plans         = $wpdb->prefix . 'mon_plans';
        $table_tiers         = $wpdb->prefix . 'mon_plan_tiers';
        $table_services      = $wpdb->prefix . 'mon_services';
        $table_ledger        = $wpdb->prefix . 'mon_invitation_credit_ledger';
        $table_entitlements  = $wpdb->prefix . 'mon_replacement_entitlements';
        $table_tier_features = $wpdb->prefix . 'mon_tier_features';
        $table_supervisors   = $wpdb->prefix . 'mon_event_supervisors';
        $table_supervisor_sessions = $wpdb->prefix . 'mon_supervisor_sessions';

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
            invitation_credit_limit INT UNSIGNED NOT NULL DEFAULT 0,
            replacement_credit_limit INT UNSIGNED NOT NULL DEFAULT 0,
            event_quota_mode VARCHAR(20) NOT NULL DEFAULT 'limited',
            event_quota_limit INT UNSIGNED NOT NULL DEFAULT 1,
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

        // ═══════════════════════════════════════════════════════════════════
        // mon_invitation_credit_ledger — أُضيف في 1.5.0. سجل ذري لاستهلاك رصيد
        // الدعوات (Invitation Credits Engine، المرحلة الثانية: "تأسيس دورة
        // الرصيد وسجل الاستهلاك الذري فقط"). الحماية الحقيقية ضد الخصم
        // المزدوج عبر مسارات الإرسال المتزامنة (يدوي/Queue/Cron/Cartat/
        // UltraMsg) هي القيد UNIQUE أدناه، لا أي منطق تطبيقي "افحص ثم أدرج".
        //
        // credit_cycle_id (وليس plan_id/tier_id) هو ما يفصل استهلاك اشتراك
        // المستخدم الحالي عن أي تفعيل سابق لنفس المستخدم — راجع
        // _mon_credit_cycle_id الجديد في activate_catalog_tier(). قيمة
        // VARCHAR(36) تكفي لـUUID v4 القياسي (36 حرفاً بالضبط مع الشرطات).
        //
        // guest_phone بنفس حجم wp_pge_event_rsvps.guest_phone (VARCHAR(32))
        // للاتساق، وتُطبَّع دوماً عبر نفس دالة تطبيع الجوال الحالية في
        // المشروع قبل أي كتابة (راجع PGE_Invitation_Credit_Ledger).
        //
        // credit_type وstatus نصيّان (VARCHAR) لا ENUM، بنفس نمط status في
        // mon_plans/mon_plan_tiers/mon_services — القيم المسموحة (primary/
        // replacement للأول، reserved/consumed/refunded/failed للثاني —
        // failed أُضيفت في 1.6.0) تُفرَض على مستوى التطبيق فقط
        // (PGE_Invitation_Credit_Ledger::normalize_*)، تماماً كما هو الحال مع
        // status الحالية في الجداول الثلاثة الأخرى.
        //
        // attempt_token/attempt_started_at/last_attempt_at (1.6.0): "محاولة
        // تسليم مملوكة" — تُستخدَم عبر claim_for_delivery()/
        // mark_consumed_with_token()/mark_failed_with_token() لضمان أن عاملاً
        // واحداً فقط (Cron/AJAX) يملك حق إنهاء صف reserved معيّن في لحظة
        // معينة، حتى مع وجود قفل GET_LOCK خارجي — الحماية مزدوجة عمداً.
        $sql_ledger = "CREATE TABLE $table_ledger (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            credit_cycle_id VARCHAR(36) NOT NULL,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            guest_phone VARCHAR(32) NOT NULL,
            credit_type VARCHAR(20) NOT NULL DEFAULT 'primary',
            status VARCHAR(20) NOT NULL DEFAULT 'reserved',
            attempt_token VARCHAR(64) NULL,
            attempt_started_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            consumed_at DATETIME NULL,
            refunded_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY event_id (event_id),
            KEY credit_cycle_id (credit_cycle_id),
            KEY status (status),
            UNIQUE KEY unique_credit_consumption (credit_cycle_id, event_id, guest_phone, credit_type)
        ) $charset_collate;";

        // ═══════════════════════════════════════════════════════════════════
        // mon_replacement_entitlements — أُضيف في 1.7.0. سجل استحقاقات
        // Replacement Credits (Invitation Credits Engine، المرحلة 4A: "تأسيس
        // بنية الاستحقاقات فقط" — راجع includes/class-pge-replacement-
        // entitlements.php). هذا الجدول مستقل تماماً عن mon_invitation_credit_
        // ledger (قرار معماري نهائي: لا عمود replacement_granted داخل جدول
        // Ledger) — يفصل بوضوح بين مفهومين مختلفين: "محاولة تسليم" (Ledger)
        // و"استحقاق مُكتسَب" (هذا الجدول)، لكل منهما دورة حياة وقواعد مختلفة.
        //
        // source_ledger_id: معرّف صف primary/consumed في mon_invitation_
        // credit_ledger الذي وَلَّد هذا الاستحقاق (الضيف الذي اعتذر بعد إرسال
        // ناجح فعلياً له). consumed_by_ledger_id: معرّف صف replacement/consumed
        // في نفس الجدول الذي استهلك هذا الاستحقاق لاحقاً بإرسال فعلي لضيف
        // بديل. كلاهما بلا FOREIGN KEY فعلية (بنفس نمط بقية المشروع) — التحقق
        // من صحتهما يتم بالكامل على مستوى التطبيق (PGE_Replacement_Entitlements).
        //
        // القيد UNIQUE (credit_cycle_id, event_id, source_guest_phone) هو
        // الحماية الذرية الحقيقية ضد منح استحقاق مزدوج لنفس المعتذر (اعتذار
        // متكرر، أو ضغط رابط RSVP عدة مرات) — بنفس فلسفة unique_credit_
        // consumption بالضبط: محاولة INSERT فعلية أولاً، لا فحص "افحص ثم
        // أدرج" بمفرده.
        //
        // status نصّي (VARCHAR) لا ENUM، بنفس نمط status في بقية جداول
        // المشروع — القيم المسموحة (granted/consumed/voided) تُفرَض على مستوى
        // التطبيق فقط. لا منطق voiding تجاري في هذه المرحلة — فقط الانتقال
        // الآمن (granted→voided) كأداة مُعدّة للاستخدام لاحقاً.
        //
        // granted_at/consumed_at/created_at/updated_at كلها NOT NULL بلا
        // DEFAULT CURRENT_TIMESTAMP (باستثناء consumed_at وهو NULL-able بطبيعته
        // قبل الاستهلاك) — القيم تُكتَب صراحة من التطبيق دائماً عبر
        // current_time('mysql', true)، بنفس فلسفة Ledger (created_at هناك DEFAULT
        // CURRENT_TIMESTAMP لكن consumed_at/refunded_at بلا DEFAULT ويُكتَبان
        // صراحة)، وهنا التزاماً بالمواصفة المطلوبة حرفياً.
        $sql_entitlements = "CREATE TABLE $table_entitlements (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            credit_cycle_id VARCHAR(64) NOT NULL,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            source_guest_phone VARCHAR(32) NOT NULL,
            source_ledger_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'granted',
            consumed_by_ledger_id BIGINT(20) UNSIGNED NULL,
            granted_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_cycle_status (user_id, credit_cycle_id, status),
            KEY source_ledger_id (source_ledger_id),
            KEY consumed_by_ledger_id (consumed_by_ledger_id),
            UNIQUE KEY unique_replacement_entitlement (credit_cycle_id, event_id, source_guest_phone)
        ) $charset_collate;";

        // ═══════════════════════════════════════════════════════════════════
        // mon_tier_features — أُضيف في 1.8.0. تخزين خام (Raw Storage) لقيم
        // ميزات كل Tier (Phase 2 — Tier Features Storage، راجع
        // docs/PACKAGE-FEATURE-MATRIX.md §5 وdocs/FEATURES-PHASE-2-SPEC.md
        // Commit 1). هذا الـCommit تأسيس بنية الجدول فقط — بلا Repository
        // (includes/class-pge-tier-features.php يأتي في Commit 2)، وبلا أي
        // استهلاك من أي مسار آخر بعد.
        //
        // feature_key VARCHAR(64): الطول معتمد صراحةً عبر DEC-001 في
        // docs/DECISION-LOG.md — أطول مفتاح فعلي في Feature Registry حالياً
        // (class-pge-feature-registry.php) طوله 36 حرفاً، وVARCHAR(64) يوفر
        // هامشاً مناسباً للتوسع دون أثر سلبي على الفهرس المركب.
        //
        // feature_value LONGTEXT: تخزين خام بلا أي تفسير لنوع القيمة على
        // مستوى قاعدة البيانات — لا عمود value_type هنا، قرار معماري صريح في
        // PACKAGE-FEATURE-MATRIX.md §5. التحقق من النوع/القيمة الفعلي مسؤولية
        // طبقة أعلى (Repository/Feature Registry)، خارج نطاق هذا الجدول.
        //
        // القيد UNIQUE (tier_id, feature_key) يمنع تكرار نفس المفتاح لنفس
        // الـTier — بنفس فلسفة tier_per_plan في mon_plan_tiers. بلا FOREIGN
        // KEY فعلية (بنفس نمط بقية جداول هذا الملف) — التحقق من صحة tier_id
        // على مستوى التطبيق فقط.
        $sql_tier_features = "CREATE TABLE $table_tier_features (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tier_id BIGINT(20) UNSIGNED NOT NULL,
            feature_key VARCHAR(64) NOT NULL,
            feature_value LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tier_feature (tier_id, feature_key)
        ) $charset_collate;";

        // ═══════════════════════════════════════════════════════════════════
        // mon_event_supervisors — أُضيف في 1.10.0. Entry Check-in Supervisors،
        // Phase 1 ("Supervisor Entitlement Foundation" RFC): "جدول إسناد
        // المشرفين فقط" — لا جدول تدقيق، لا جدول تسجيل حضور، لا منطق دعوة/
        // قبول/رمز فعلي بعد (راجع تعليق upgrade_to_1_10_0() أعلاه للتفصيل
        // الكامل حول كل قرار في هذا الجدول). الجدول فارغ عند إنشائه.
        //
        // event_id بلا credit_cycle_id: الإسناد على مستوى المناسبة مباشرة
        // (قرار RFC نهائي: "Supervisor limit is PER EVENT")، لا على مستوى
        // دورة تفعيل Catalog — هذا يدعم "عدة مشرفين لنفس المناسبة" (عدة صفوف
        // بنفس event_id) و"نفس المشرف لعدة مناسبات مستقبلاً" (بلا أي قيد هنا
        // يربط صفاً بمناسبة واحدة حصراً) دون أي تعديل بنيوي لاحق.
        //
        // status نصّي (VARCHAR) لا ENUM، بنفس نمط status في بقية جداول هذا
        // الملف — القيمة الافتراضية 'invited' موضع نائب لدورة حياة مستقبلية
        // (invited/pending/active/revoked/expired) بلا أي منطق انتقال حالة
        // في هذا الـCommit.
        //
        // ── تصحيح معماري (Append-Only History، قبل موافقة Phase 1) ──────────
        // لا قيد UNIQUE على (event_id, supervisor_phone): القيد الفريد كان
        // سيمنع إعادة دعوة نفس الهاتف لنفس المناسبة بعد أن يُصبح إسنادها
        // السابق revoked/expired — يتعارض مباشرة مع "دعوة جديدة = صف جديد
        // دائماً، لا تعديل صف تاريخي أبداً". الفهرس event_phone أدناه فهرس
        // عادي (Non-Unique) للتسريع فقط، لا لفرض تفرّد. التفرّد التجاري
        // الحقيقي ("إسناد نشط واحد فقط" — invited/pending/active حصراً لنفس
        // event_id + الهاتف المُطبَّع) مؤجَّل بالكامل لمنطق إنشاء الدعوة في
        // Phase 2، محمياً بقفل GET_LOCK (أو ما يعادله) — توثيق فقط هنا، راجع
        // التعليق الكامل أعلى upgrade_to_1_10_0().
        // invitation_token_hash (أُضيف في 1.11.0 — Phase 2): راجع تعليق
        // upgrade_to_1_11_0() للتفصيل الكامل. VARCHAR(64) NULL، UNIQUE (قيم
        // NULL متعددة لا تنتهك التفرّد)، لا يُخزَّن أبداً التوكن الخام نفسه.
        $sql_supervisors = "CREATE TABLE $table_supervisors (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            supervisor_phone VARCHAR(32) NOT NULL,
            supervisor_name VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'invited',
            invitation_token_hash VARCHAR(64) NULL,
            invited_by_user_id BIGINT(20) UNSIGNED NOT NULL,
            invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            accepted_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY user_id (user_id),
            KEY event_status (event_id, status),
            KEY event_phone (event_id, supervisor_phone),
            UNIQUE KEY invitation_token_hash (invitation_token_hash)
        ) $charset_collate;";

        // ═══════════════════════════════════════════════════════════════════
        // mon_supervisor_sessions — أُضيف في 1.12.0. Entry Check-in Supervisors،
        // Phase 3 ("Supervisor Authentication" RFC). راجع تعليق upgrade_to_
        // 1_12_0() أعلاه للتفصيل الكامل حول كل قرار في هذا الجدول (اختيار
        // الهاش بدل التوكن الخام، denormalization عمود event_id، آلية إبطال
        // الجلسات عند إلغاء الإسناد بلا كتابة على هذا الجدول، مهلة الصلاحية).
        //
        // assignment_id/event_id بلا FOREIGN KEY فعلية (بنفس نمط بقية جداول
        // هذا الملف) — التحقق من صحتهما على مستوى التطبيق فقط
        // (PGE_Supervisor_Session تقرأ mon_event_supervisors مباشرة عند كل
        // تحقق جلسة). الجدول فارغ عند إنشائه.
        $sql_supervisor_sessions = "CREATE TABLE $table_supervisor_sessions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            assignment_id BIGINT(20) UNSIGNED NOT NULL,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            session_token_hash VARCHAR(64) NOT NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_token_hash (session_token_hash),
            KEY assignment_id (assignment_id),
            KEY event_id (event_id)
        ) $charset_collate;";

        return [$sql_plans, $sql_tiers, $sql_services, $sql_ledger, $sql_entitlements, $sql_tier_features, $sql_supervisors, $sql_supervisor_sessions];
    }
}

// تسجيل تلقائي أول عند تفعيل الإضافة — نفس نمط pge_create_rsvp_table في rsvp-handler.php
register_activation_hook(PGE_PATH . 'pgevents-core.php', ['Mon_Catalog_Schema', 'maybe_upgrade']);

// شبكة أمان لتحديثات الكود بدون Deactivate/Activate — نفس فلسفة فحص pge_rewrite_version
// في pgevents-core.php، لكن هنا على plugins_loaded (أبكر من init) لأنه لا يعتمد على
// أي منشور نوع مسجَّل مسبقاً، والفرع السريع (تطابق الإصدار) لا يُنفّذ شيئاً فعلياً.
add_action('plugins_loaded', ['Mon_Catalog_Schema', 'maybe_upgrade']);
