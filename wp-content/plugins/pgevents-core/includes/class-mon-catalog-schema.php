<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * Mon Catalog Schema — الخطوة الأولى فقط من نظام كتالوج الباقات والخدمات
 * ============================================================================
 *
 * هذا الملف مسؤول حصرياً عن:
 *  - إنشاء/مزامنة 4 جداول: mon_plans, mon_plan_tiers, mon_services,
 *    mon_invitation_credit_ledger (أُضيف في 1.5.0 — سجل ذري لاستهلاك رصيد
 *    الدعوات، راجع includes/class-pge-invitation-credit-ledger.php) عبر dbDelta().
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
    const DB_VERSION = '1.6.0';

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
     * صياغة SQL للجداول الأربعة، بصيغة متوافقة مع dbDelta() (كل عمود بسطر
     * مستقل، بلا FOREIGN KEY، بلا ENGINE، بلا ENUM).
     */
    private static function get_schema_sql(): array
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $table_plans    = $wpdb->prefix . 'mon_plans';
        $table_tiers    = $wpdb->prefix . 'mon_plan_tiers';
        $table_services = $wpdb->prefix . 'mon_services';
        $table_ledger   = $wpdb->prefix . 'mon_invitation_credit_ledger';

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

        return [$sql_plans, $sql_tiers, $sql_services, $sql_ledger];
    }
}

// تسجيل تلقائي أول عند تفعيل الإضافة — نفس نمط pge_create_rsvp_table في rsvp-handler.php
register_activation_hook(PGE_PATH . 'pgevents-core.php', ['Mon_Catalog_Schema', 'maybe_upgrade']);

// شبكة أمان لتحديثات الكود بدون Deactivate/Activate — نفس فلسفة فحص pge_rewrite_version
// في pgevents-core.php، لكن هنا على plugins_loaded (أبكر من init) لأنه لا يعتمد على
// أي منشور نوع مسجَّل مسبقاً، والفرع السريع (تطابق الإصدار) لا يُنفّذ شيئاً فعلياً.
add_action('plugins_loaded', ['Mon_Catalog_Schema', 'maybe_upgrade']);
