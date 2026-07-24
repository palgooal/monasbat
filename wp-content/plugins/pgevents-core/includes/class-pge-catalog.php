<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Catalog — الهيكل الأساسي فقط (جزء صغير من الخطوة الثانية)
 * ============================================================================
 *
 * هذا الملف لا يحتوي حالياً على أي CRUD، أي استعلام SQL فعلي، أي Validation،
 * أي JSON، وأي ربط بـ Webhook أو سلة. الغرض الوحيد منه في هذه المرحلة هو
 * تعريف الكلاس ودوال داخلية خاصة لإرجاع أسماء الجداول الثلاثة التي أنشأتها
 * class-mon-catalog-schema.php، تمهيداً لإضافة منطق فعلي لاحقاً بتوجيه صريح.
 *
 * لا يوجد أي تنفيذ تلقائي عند تحميل هذا الملف: لا استدعاء دوال، لا Hooks،
 * لا تسجيل أي شيء. فقط تعريف الكلاس.
 */

class PGE_Catalog
{
    /**
     * القيم الرسمية المسموحة — موثّقة في docs/CATALOG-BUSINESS-RULES.md.
     * مصدر داخلي فقط لاستخدام دوال الكتابة لاحقاً؛ لا استخدام لها بعد.
     */
    private const ALLOWED_PLAN_TYPES = [
        'personal',
        'business',
    ];

    private const ALLOWED_STATUSES = [
        'active',
        'inactive',
    ];

    private const ALLOWED_CURRENCIES = [
        'SAR',
    ];

    /**
     * اسم جدول الباقات (mon_plans) مع بادئة $wpdb->prefix.
     */
    private static function plans_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mon_plans';
    }

    /**
     * اسم جدول مستويات الباقات (mon_plan_tiers) مع بادئة $wpdb->prefix.
     */
    private static function tiers_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mon_plan_tiers';
    }

    /**
     * اسم جدول الخدمات (mon_services) مع بادئة $wpdb->prefix.
     */
    private static function services_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mon_services';
    }

    /**
     * تطبيع والتحقق من قيمة plan_type مقابل self::ALLOWED_PLAN_TYPES. تقبل
     * string فقط؛ أي نوع آخر يُعيد null فوراً. تُطبَّع القيمة عبر trim() ثم
     * strtolower()، وإن أصبحت فارغة تُعاد null. المقارنة مع القائمة المسموحة
     * تتم عبر in_array(..., true) (strict) لمنع أي تطابق ضمني بين الأنواع.
     * عند النجاح تُعاد القيمة المطبَّعة (lowercase, trimmed)؛ وإلا تُعاد null.
     */
    private static function normalize_plan_type($plan_type)
    {
        if (!is_string($plan_type)) {
            return null;
        }

        $normalized = strtolower(trim($plan_type));

        if ($normalized === '') {
            return null;
        }

        if (!in_array($normalized, self::ALLOWED_PLAN_TYPES, true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * تطبيع والتحقق من قيمة status مقابل self::ALLOWED_STATUSES. تقبل string
     * فقط؛ أي نوع آخر يُعيد null فوراً. تُطبَّع القيمة عبر trim() ثم
     * strtolower()، وإن أصبحت فارغة تُعاد null. المقارنة مع القائمة المسموحة
     * تتم عبر in_array(..., true) (strict) لمنع أي تطابق ضمني بين الأنواع.
     * عند النجاح تُعاد القيمة المطبَّعة (lowercase, trimmed)؛ وإلا تُعاد null.
     */
    private static function normalize_status($status)
    {
        if (!is_string($status)) {
            return null;
        }

        $normalized = strtolower(trim($status));

        if ($normalized === '') {
            return null;
        }

        if (!in_array($normalized, self::ALLOWED_STATUSES, true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * تطبيع والتحقق من قيمة currency مقابل self::ALLOWED_CURRENCIES. تقبل
     * string فقط؛ أي نوع آخر يُعيد null فوراً. تُطبَّع القيمة عبر trim() ثم
     * strtoupper() (على عكس plan_type/status التي تُطبَّع لـ lowercase —
     * رموز العملات مخزَّنة بأحرف كبيرة في القائمة المسموحة والـSchema)، وإن
     * أصبحت فارغة تُعاد null. المقارنة مع القائمة المسموحة تتم عبر
     * in_array(..., true) (strict). عند النجاح تُعاد القيمة المطبَّعة
     * (uppercase, trimmed)؛ وإلا تُعاد null.
     */
    private static function normalize_currency($currency)
    {
        if (!is_string($currency)) {
            return null;
        }

        $normalized = strtoupper(trim($currency));

        if ($normalized === '') {
            return null;
        }

        if (!in_array($normalized, self::ALLOWED_CURRENCIES, true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * تطبيع قيمة plan_key عبر sanitize_key(). تقبل string فقط؛ أي نوع آخر
     * يُعيد null فوراً. sanitize_key() تُحوِّل لـ lowercase وتُبقي فقط
     * [a-z0-9_-] مع trim (نفس التطبيع المستخدم في get_plan_by_key()). إن
     * أصبحت القيمة فارغة بعد التنظيف (نص فارغ، مسافات فقط، أو نص غير لاتيني
     * بالكامل كالعربية) تُعاد null. لا مقارنة مع أي قائمة مسموحة هنا — plan_key
     * مفتاح حر يحدده منشئ الباقة، على عكس plan_type/status/currency.
     */
    private static function normalize_plan_key($plan_key)
    {
        if (!is_string($plan_key)) {
            return null;
        }

        $normalized = sanitize_key($plan_key);

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * تطبيع قيمة name (اسم الباقة). تقبل string فقط؛ أي نوع آخر يُعيد null
     * فوراً. تُطبَّع القيمة عبر trim() ثم sanitize_text_field() (تُزيل وسوم
     * HTML والمسافات الزائدة/أسطر التحكم دون التأثير على النص العربي أو أي
     * نص UTF-8 آخر)، ثم trim() مرة أخرى لأن sanitize_text_field() قد تترك
     * فراغات طرفية بعد إزالة الوسوم. على عكس normalize_plan_key()، لا تحويل
     * لـ lowercase ولا sanitize_key() — هذا اسم عرض حر بأي لغة، لا مفتاح.
     * إن أصبحت القيمة فارغة بعد التنظيف تُعاد null.
     */
    private static function normalize_plan_name($name)
    {
        if (!is_string($name)) {
            return null;
        }

        $normalized = trim(sanitize_text_field(trim($name)));

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * تطبيع والتحقق من قيمة sort_order. تقبل عدداً صحيحاً (int) يساوي صفراً
     * أو أكبر، أو نصاً (string) مكوّناً بالكامل من أرقام صحيحة غير سالبة
     * (نمط ^[0-9]+$ — بلا إشارة `+`/`-`، بلا فاصلة عشرية، بلا أي حرف زائد).
     * أي شيء آخر يُعيد null فوراً. النص الصالح يُحوَّل إلى int، ثم يُتحقَّق
     * أنه >= 0 (شرط دفاعي إضافي، إذ preg_match أعلاه يمنع أصلاً أي قيمة
     * سالبة أن تصل لهذه النقطة). عند النجاح تُعاد قيمة int صريحة.
     */
    private static function normalize_sort_order($sort_order)
    {
        if (is_int($sort_order)) {
            $normalized = $sort_order;
        } elseif (is_string($sort_order) && preg_match('/^[0-9]+$/', $sort_order)) {
            $normalized = (int) $sort_order;
        } else {
            return null;
        }

        if ($normalized < 0) {
            return null;
        }

        return $normalized;
    }

    /**
     * تطبيع والتحقق من قيمة features (عمود mon_plans.features، LONGTEXT NULL
     * يخزّن JSON array من نصوص). لها ثلاث قيم إرجاع مميّزة فعلاً (مثل
     * normalize_salla_product_id()، لنفس السبب: القيمة اختيارية بطبيعتها):
     *  - null: لا مزايا (المدخل كان null، أو string فارغة بعد trim، أو
     *    array أصبحت فارغة بعد التنظيف) — حالة صالحة تُخزَّن كـ NULL.
     *  - false: المدخل غير صالح إطلاقاً (ليس array/null/string فارغة، أو
     *    يحتوي عنصراً غير string، أو تجاوز حد العدد/الطول، أو فشل JSON
     *    encoding) — يجب على المستدعي رفض العملية بالكامل عند هذه النتيجة.
     *  - string: JSON array صالحة من النصوص المُنظَّفة.
     * لا wp_unslash() هنا عمداً — نفس نمط بقية normalize_* الحالية التي لا
     * تفترض مصدر البيانات (المستدعي في طبقة الواجهة مسؤول عن ذلك). كل عنصر
     * يمر بخط أنابيب: trim() فحذف الفارغ → wp_strip_all_tags() ثم trim()
     * مرة أخرى فحذف الفارغ مجدداً (يمنع HTML بما فيه محتوى <script> كاملاً،
     * دون أي تحويل lowercase أو تدخّل في النص العربي) → فحص الطول عبر
     * mb_strlen() مع fallback إلى strlen() إن كانت mb_strlen غير متاحة (حد
     * 255 حرفاً) → إزالة التكرار الحرفي بالحفاظ على أول ظهور فقط (ترتيب
     * القائمة النهائية = ترتيب أول ظهور لكل عنصر فريد). بعد التنظيف الكامل:
     * أكثر من 50 عنصراً يُرفض، وقائمة فارغة تُعاد كـ null. الترميز النهائي
     * عبر wp_json_encode() بخيار JSON_UNESCAPED_UNICODE للحفاظ على العربية
     * دون escape غير ضروري (\uXXXX)؛ فشل الترميز (نادر، مثل UTF-8 غير صالح)
     * يُعيد false.
     */
    private static function normalize_features($features)
    {
        if ($features === null) {
            return null;
        }

        if (is_string($features) && trim($features) === '') {
            return null;
        }

        if (!is_array($features)) {
            return false;
        }

        $cleaned = [];

        foreach ($features as $feature) {
            if (!is_string($feature)) {
                return false;
            }

            $feature = trim($feature);
            if ($feature === '') {
                continue;
            }

            $feature = trim(wp_strip_all_tags($feature));
            if ($feature === '') {
                continue;
            }

            $length = function_exists('mb_strlen') ? mb_strlen($feature) : strlen($feature);
            if ($length > 255) {
                return false;
            }

            if (!in_array($feature, $cleaned, true)) {
                $cleaned[] = $feature;
            }
        }

        if (count($cleaned) > 50) {
            return false;
        }

        if (empty($cleaned)) {
            return null;
        }

        $json = wp_json_encode($cleaned, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        return $json;
    }

    /**
     * تطبيع والتحقق من قيمة tier_key (عمود mon_plan_tiers.tier_key،
     * VARCHAR(64)). تقبل string فقط؛ أي نوع آخر يُعيد null فوراً. تُطبَّع
     * القيمة عبر trim() ثم strtolower()، وإن أصبحت فارغة تُعاد null. تُرفض
     * أي قيمة يتجاوز طولها 64 حرفاً (حد Schema الفعلي). القيمة يجب أن تطابق
     * تماماً النمط ^[a-z0-9][a-z0-9_-]*$ — أي تبدأ بحرف/رقم لاتيني فقط (لا
     * يجوز البدء بـ`-` أو `_`)، ولا تحتوي إلا [a-z0-9_-] بعدها؛ هذا يرفض
     * تلقائياً المسافات، الأحرف العربية، النقطة، الشرطة المائلة، وأي رمز آخر.
     * عند النجاح تُعاد القيمة المطبَّعة (lowercase, trimmed)؛ وإلا تُعاد null.
     */
    private static function normalize_tier_key($tier_key)
    {
        if (!is_string($tier_key)) {
            return null;
        }

        $normalized = strtolower(trim($tier_key));

        if ($normalized === '') {
            return null;
        }

        if (strlen($normalized) > 64) {
            return null;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * تطبيع والتحقق من قيمة name (عمود mon_plan_tiers.name، VARCHAR(190)
     * NOT NULL — اسم عرض المستوى، مثل "100 مدعو"، منفصل تماماً عن tier_key
     * التقني). تقبل string فقط؛ أي نوع آخر (int، bool، array، null...) يُعيد
     * false فوراً — على عكس بقية normalize_* هنا (التي تُعيد null للمدخل
     * غير الصالح)، هذه الدالة تُعيد false حصرياً لأنه لا توجد لها أي حالة
     * "فارغة لكن صالحة": اسم المستوى مطلوب دائماً ولا يجوز تخزينه فارغاً
     * (النموذج الأصلي VARCHAR(190) NOT NULL بلا معنى منطقي لسلسلة فارغة).
     * التطبيع: trim() ثم wp_strip_all_tags() (تزيل وسوم HTML بما فيها محتوى
     * <script>/<style> كاملاً، دون أي تحويل lowercase أو تدخّل في النص
     * العربي — نفس الأسلوب المستخدم لكل عنصر داخل normalize_features())، ثم
     * trim() مرة أخرى. إن أصبحت القيمة فارغة بعد كل ذلك تُعاد false. لا
     * sanitize_key() ولا strtolower() هنا عمداً: هذا اسم عرض حر بأي لغة، لا
     * مفتاح تقني — العربية والإنجليزية والأرقام وعلامات الترقيم الطبيعية كلها
     * مسموحة دون تغيير. فحص الطول عبر mb_strlen() مع fallback إلى strlen()
     * إن كانت mb_strlen غير متاحة (حد 190 حرفاً، مطابق لحد Schema الفعلي)؛
     * تجاوزه يُعيد false. عند النجاح تُعاد القيمة المطبَّعة كـ string فقط.
     */
    private static function normalize_tier_name($name)
    {
        if (!is_string($name)) {
            return false;
        }

        $normalized = trim($name);
        $normalized = trim(wp_strip_all_tags($normalized));

        if ($normalized === '') {
            return false;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized);
        if ($length > 190) {
            return false;
        }

        return $normalized;
    }

    /**
     * تطبيع والتحقق من قيمة price (عمود mon_plan_tiers.price،
     * DECIMAL(10,2) — أي 8 خانات صحيحة كحد أقصى + خانتان عشريتان، فالحد
     * الأقصى الفعلي هو 99999999.99). تقبل int موجباً أو صفراً، أو float
     * موجباً أو صفراً، أو string عددي كامل بلا أي حرف زائد (نمط
     * ^\d+(\.\d{1,2})?$ — يرفض تلقائياً الفراغ، الإشارة السالبة، الفواصل
     * الألفية، الترميز العلمي، وأي أكثر من خانتين عشريتين). أي نوع آخر
     * (null، bool، array، object) يُعيد null فوراً. int/float تُحوَّل أولاً
     * إلى نص (number_format للـfloat) ثم تمر بنفس مسار التحقق النصي — هذا
     * يوحّد منطق التحقق النهائي في مكان واحد. فحص تجاوز الحد الأقصى يتم على
     * الجزء الصحيح من النص كسلسلة أحرف (بعد حذف الأصفار الزائدة على اليسار):
     * إن تجاوز طوله 8 خانات تُرفض القيمة — تعمّدت تجنّب أي مقارنة float
     * مباشرة هنا لأن الدقة العشرية لـfloat قد تُنتج نتائج غير موثوقة عند
     * حدود القيمة القصوى بالضبط. عند النجاح تُعاد القيمة كنص ثابت بخانتين
     * عشريتين دائماً (مثل '0.00'، '100.00'، '150.50').
     */
    private static function normalize_price($price)
    {
        if (is_int($price)) {
            if ($price < 0) {
                return null;
            }
            $price_string = (string) $price;
        } elseif (is_float($price)) {
            if ($price < 0) {
                return null;
            }
            $price_string = number_format($price, 2, '.', '');
        } elseif (is_string($price)) {
            $price_string = trim($price);
        } else {
            return null;
        }

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $price_string)) {
            return null;
        }

        if (strpos($price_string, '.') !== false) {
            [$integer_part, $decimal_part] = explode('.', $price_string, 2);
            $decimal_part = str_pad($decimal_part, 2, '0');
        } else {
            $integer_part = $price_string;
            $decimal_part = '00';
        }

        $integer_part = ltrim($integer_part, '0');
        if ($integer_part === '') {
            $integer_part = '0';
        }

        if (strlen($integer_part) > 8) {
            return null;
        }

        return $integer_part . '.' . $decimal_part;
    }

    /**
     * تطبيع والتحقق من قيمة salla_product_id (عمود mon_plan_tiers، VARCHAR(64)
     * NULL). هذا العمود اختياري بطبيعته على عكس بقية الحقول، لذا دالة
     * التطبيع هذه تُميّز بين ثلاث حالات بثلاث قيم إرجاع مختلفة فعلاً:
     *  - false: القيمة غير صالحة إطلاقاً (int صفر/سالب، float، bool، array،
     *    object، أو string يتجاوز طولها 64 حرفاً) — يجب على المستدعي رفض
     *    العملية بالكامل عند هذه النتيجة تحديداً.
     *  - null: القيمة null أصلاً، أو string فارغة بعد trim() — كلاهما
     *    "لا يوجد معرّف منتج" وهي حالة صالحة تُخزَّن كـ NULL في القاعدة.
     *  - string غير فارغة: القيمة (مُطبَّعة بـ trim فقط، بلا sanitize_key()
     *    وبلا lowercase لأن معرّف منتج سلة قد يحتوي صيغة لا يجوز تغييرها).
     * int موجب يُحوَّل إلى string دون استخدام absint() (قد يكون معرّفاً
     * نصياً أو رقماً كبيراً في حالات أخرى، فالتحويل المباشر أدق هنا).
     */
    private static function normalize_salla_product_id($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            if ($value <= 0) {
                return false;
            }
            return (string) $value;
        }

        if (!is_string($value)) {
            return false;
        }

        $normalized = trim(sanitize_text_field($value));

        if ($normalized === '') {
            return null;
        }

        if (strlen($normalized) > 64 || preg_match('/\s/u', $normalized)) {
            return false;
        }

        return $normalized;
    }

    /**
     * تطبيع SKU اختياري لمستوى Catalog. القيمة الفارغة تعني NULL، والقيمة
     * غير الفارغة تقتصر على 100 بايت وعلى الأحرف الآمنة المتفق عليها.
     */
    private static function normalize_salla_sku($value)
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return false;
        }

        $normalized = trim(sanitize_text_field($value));
        if ($normalized === '') {
            return null;
        }

        if (strlen($normalized) > 100 || !preg_match('/^[A-Za-z0-9_-]+$/', $normalized)) {
            return false;
        }

        return $normalized;
    }

    /**
     * Normalize an optional Salla product URL. Empty values are stored as NULL;
     * non-empty values must be absolute, valid HTTPS URLs within the column size.
     */
    private static function normalize_salla_url($value)
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (strlen($value) > 255) {
            return false;
        }

        $original_parts = wp_parse_url($value);
        if (
            !is_array($original_parts)
            || strtolower((string) ($original_parts['scheme'] ?? '')) !== 'https'
            || empty($original_parts['host'])
        ) {
            return false;
        }

        $normalized = esc_url_raw($value, ['https']);
        $parts = $normalized !== '' ? wp_parse_url($normalized) : false;

        if (
            $normalized === ''
            || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
        ) {
            return false;
        }

        return $normalized;
    }

    /**
     * تطبيع والتحقق من قيمة events_count (عمود mon_plan_tiers.events_count،
     * INT UNSIGNED NULL). حسب القرار التجاري لمرحلة "كل عملية شراء = مناسبة
     * واحدة": هذا الحقل اختياري عند الإنشاء/التحديث وله قيمة افتراضية 1 —
     * ليس لأن 1 "قيمة صحيحة عشوائية"، بل لأنه لا يوجد مسار CRUD قبل هذه
     * الدالة كان يكتب هذا العمود إطلاقاً (كان يبقى NULL دائماً)، وهذا بالضبط
     * سبب ظهور events_count = 0 لكل مستويات Catalog الحالية. القيم التالية
     * تُعامَل كـ"غير محدَّدة" وتُعيد 1 مباشرة دون رفض العملية: null، 0 (int
     * أو النص "0")، أو نص فارغ بعد trim(). أي قيمة أخرى غير صالحة (سالبة،
     * عشرية، نص غير رقمي بالكامل، bool، array، object) تُعيد false وتوقف
     * العملية بالكامل — نفس نمط false = رفض المدخل في بقية normalize_* هنا.
     * قيمة موجبة صريحة (int أو نص رقمي صحيح مثل "5") تُقبَل كما هي دون أي
     * تعديل — هذا يسمح صراحة للمسؤول بتغيير القيمة لاحقاً لأي رقم آخر أكبر
     * من الافتراضي، دون أي قفل أو فرض دائم على القيمة 1. عند النجاح تُعاد
     * قيمة int صريحة (>= 1 دائماً؛ لا تُعاد 0 أو قيمة سالبة أبداً من هذه
     * الدالة تحديداً — على عكس normalize_sort_order() التي تقبل صفراً).
     */
    private static function normalize_events_count($value)
    {
        if ($value === null) {
            return 1;
        }

        if (is_int($value)) {
            if ($value === 0) {
                return 1;
            }
            if ($value < 0) {
                return false;
            }
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || $trimmed === '0') {
                return 1;
            }
            if (!preg_match('/^[1-9][0-9]*$/', $trimmed)) {
                return false;
            }
            return (int) $trimmed;
        }

        return false;
    }

    /**
     * قراءة باقة واحدة برقمها فقط. لا تقبل إلا:
     *  - عدداً صحيحاً (int) موجباً (>= 1)، أو
     *  - نصاً (string) يحتوي على عدد صحيح موجب فقط بلا فاصلة عشرية وبلا أي
     *    حرف زائد (نمط ^[1-9][0-9]*$)، مثل "1" أو "42".
     * أي شيء آخر — صفر، سالب، عدد عشري int أو string ("1.5")، نص غير رقمي
     * بالكامل ("1abc")، نص فارغ، null، true/false، أو array — يُعيد null
     * فوراً دون أي استعلام. تعمّدت عدم الاكتفاء بـ is_numeric() لأنها تقبل
     * صيغاً عشرية وعلمية ("1.5", "1e2") لا تصلح كمعرّف صف صحيح. إن لم توجد
     * الباقة في الجدول بعد التطبيع تُعاد null أيضاً. عند النجاح تُعاد الصفوف
     * كمصفوفة ترابطية (Associative Array) عبر ARRAY_A.
     */
    public static function get_plan($plan_id)
    {
        if (is_int($plan_id)) {
            $normalized_id = $plan_id;
        } elseif (is_string($plan_id) && preg_match('/^[1-9][0-9]*$/', $plan_id)) {
            $normalized_id = (int) $plan_id;
        } else {
            return null;
        }

        if ($normalized_id < 1) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::plans_table() . " WHERE id = %d",
                $normalized_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $row;
    }

    /**
     * قراءة باقة واحدة بمفتاحها النصي (plan_key) فقط. لا تقبل إلا string؛ أي
     * نوع آخر (null، bool، array، عدد...) يُعيد null فوراً دون أي استعلام.
     * تُطبَّع القيمة عبر sanitize_key() — وهي تُحوِّل الأحرف إلى lowercase
     * وتُبقي فقط [a-z0-9_-] مع trim، وهو نفس التطبيع المفترض لأي plan_key
     * مخزَّن في الجدول. إن أصبحت القيمة فارغة بعد التنظيف (مثل "" أو "   ")
     * تُعاد null أيضاً دون استعلام. لا يوجد أي شرط على status هنا: تُقرأ
     * الباقة سواء كانت active أو inactive. إن لم توجد الباقة تُعاد null؛
     * وإلا تُعاد الصفوف كمصفوفة ترابطية عبر ARRAY_A.
     */
    public static function get_plan_by_key($plan_key)
    {
        if (!is_string($plan_key)) {
            return null;
        }

        $normalized_key = sanitize_key($plan_key);

        if ($normalized_key === '') {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::plans_table() . " WHERE plan_key = %s",
                $normalized_key
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $row;
    }

    /**
     * قراءة جميع صفوف جدول الباقات بلا أي معامل، بلا فلترة على status أو
     * plan_type، وبلا ترتيب ديناميكي. الترتيب ثابت دائماً: sort_order تصاعدياً
     * ثم id تصاعدياً كفاصل تعادل (Tie-breaker) عند تساوي sort_order، لضمان
     * ترتيب مستقر ومتوقّع. إن لم توجد أي باقة تُعاد مصفوفة فارغة []، وإلا
     * تُعاد مصفوفة من الصفوف، كل صف كمصفوفة ترابطية عبر ARRAY_A.
     */
    public static function get_plans()
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM " . self::plans_table() . " ORDER BY sort_order ASC, id ASC",
            ARRAY_A
        );

        if (!$rows) {
            return [];
        }

        return $rows;
    }

    /**
     * قراءة مستوى واحد (tier) برقمه فقط. نفس قواعد التحقق المستخدمة في
     * get_plan(): تقبل عدداً صحيحاً (int) موجباً (>= 1)، أو نصاً (string)
     * يحتوي على عدد صحيح موجب فقط بلا فاصلة عشرية وبلا أي حرف زائد
     * (نمط ^[1-9][0-9]*$)، مثل "1" أو "42". أي شيء آخر يُعيد null فوراً
     * دون أي استعلام. إن لم يوجد المستوى بعد التطبيع تُعاد null أيضاً.
     * عند النجاح تُعاد الصفوف كمصفوفة ترابطية (Associative Array) عبر ARRAY_A.
     */
    public static function get_tier($tier_id)
    {
        if (is_int($tier_id)) {
            $normalized_id = $tier_id;
        } elseif (is_string($tier_id) && preg_match('/^[1-9][0-9]*$/', $tier_id)) {
            $normalized_id = (int) $tier_id;
        } else {
            return null;
        }

        if ($normalized_id < 1) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::tiers_table() . " WHERE id = %d",
                $normalized_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $row;
    }

    /**
     * قراءة جميع مستويات (tiers) باقة واحدة برقمها. نفس شرط التحقق المستخدم
     * في get_plan() وget_tier(): تقبل عدداً صحيحاً (int) موجباً (>= 1)، أو
     * نصاً (string) يحتوي على عدد صحيح موجب فقط بلا فاصلة عشرية وبلا أي حرف
     * زائد (نمط ^[1-9][0-9]*$). أي شيء آخر يُعيد [] فوراً دون أي استعلام.
     * لا يُشترط وجود الباقة فعلياً في mon_plans — الاستعلام وحده يعيد []
     * إن لم توجد مستويات مرتبطة بهذا الرقم. الترتيب ثابت دائماً: sort_order
     * تصاعدياً ثم id تصاعدياً كفاصل تعادل، نفس نمط get_plans(). تُعاد مصفوفة
     * من الصفوف (كل صف ARRAY_A)، أو [] إن لم توجد نتائج.
     */
    public static function get_plan_tiers($plan_id)
    {
        if (is_int($plan_id)) {
            $normalized_id = $plan_id;
        } elseif (is_string($plan_id) && preg_match('/^[1-9][0-9]*$/', $plan_id)) {
            $normalized_id = (int) $plan_id;
        } else {
            return [];
        }

        if ($normalized_id < 1) {
            return [];
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::tiers_table() . " WHERE plan_id = %d ORDER BY sort_order ASC, id ASC",
                $normalized_id
            ),
            ARRAY_A
        );

        if (!$rows) {
            return [];
        }

        return $rows;
    }

    /**
     * قراءة مستوى واحد (tier) بمعرّف منتج سلة (salla_product_id). تقبل إما
     * string غير فارغ بعد trim()، أو int موجب (>= 1) يُحوَّل إلى string قبل
     * الاستعلام. أي شيء آخر (null، true، false، array، int <= 0، أو نص فارغ/
     * مسافات فقط بعد trim) يُعيد null فوراً دون أي استعلام. تعمّدت عدم استخدام
     * sanitize_key() هنا وعدم تحويل الحروف لـ lowercase لأن معرّف منتج سلة قد
     * يحتوي صيغة (أحرف كبيرة، رموز) لا يجوز تغييرها قبل المطابقة في القاعدة —
     * على عكس plan_key الذي هو مفتاح داخلي نتحكم بصيغته. عند النجاح تُعاد
     * الصفوف كمصفوفة ترابطية عبر ARRAY_A؛ إن لم يوجد المستوى تُعاد null.
     */
    public static function get_tier_by_salla_product_id($salla_product_id)
    {
        $tiers = self::get_tiers_by_salla_product_id($salla_product_id);

        return count($tiers) === 1 ? $tiers[0] : null;
    }

    /**
     * قراءة جميع المستويات المطابقة تماماً لمعرّف منتج سلة. تُستخدم لمنع
     * اختيار مستوى عشوائي عندما تشترك عدة مستويات في Product ID واحد.
     */
    public static function get_tiers_by_salla_product_id($salla_product_id)
    {
        if (is_int($salla_product_id)) {
            if ($salla_product_id <= 0) {
                return [];
            }
            $normalized = (string) $salla_product_id;
        } elseif (is_string($salla_product_id)) {
            $normalized = $salla_product_id;
        } else {
            return [];
        }

        $normalized = trim($normalized);

        if ($normalized === '') {
            return [];
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::tiers_table() . " WHERE salla_product_id = %s",
                $normalized
            ),
            ARRAY_A
        );

        if (!$rows) {
            return [];
        }

        return $rows;
    }

    /**
     * قراءة مستوى واحد عبر SKU بمطابقة تامة. لا تقبل إلا String صالحة وفق
     * نفس قواعد الحفظ، ولا تستخدم LIKE أو مطابقة جزئية.
     */
    public static function get_tier_by_salla_sku($salla_sku)
    {
        $normalized = self::normalize_salla_sku($salla_sku);
        if ($normalized === false || $normalized === null) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::tiers_table() . " WHERE salla_sku = %s",
                $normalized
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * قراءة مستوى واحد بمفتاحه النصي (tier_key) ضمن باقة محددة (plan_id).
     * plan_id يخضع لنفس شرط التحقق الصارم المستخدم في get_plan() (int موجب
     * أو string ^[1-9][0-9]*$)؛ فشل التحقق يُعيد null فوراً دون استعلام.
     * tier_key يُطبَّع عبر normalize_tier_key()؛ فشل ذلك يُعيد null أيضاً.
     * البحث معزول بالكامل حسب (plan_id, tier_key) معاً — لا يبحث عبر جميع
     * الباقات، لأن tier_key فريد ضمن نطاق باقته فقط وليس عالمياً (بحسب
     * UNIQUE KEY tier_per_plan في Schema). عند النجاح تُعاد الصفوف كمصفوفة
     * ترابطية عبر ARRAY_A؛ إن لم يوجد المستوى تُعاد null.
     */
    public static function get_tier_by_key($plan_id, $tier_key)
    {
        if (is_int($plan_id)) {
            $normalized_plan_id = $plan_id;
        } elseif (is_string($plan_id) && preg_match('/^[1-9][0-9]*$/', $plan_id)) {
            $normalized_plan_id = (int) $plan_id;
        } else {
            return null;
        }

        if ($normalized_plan_id < 1) {
            return null;
        }

        $normalized_tier_key = self::normalize_tier_key($tier_key);
        if ($normalized_tier_key === null) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::tiers_table() . " WHERE plan_id = %d AND tier_key = %s",
                $normalized_plan_id,
                $normalized_tier_key
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $row;
    }

    /**
     * ملاحظة (إصلاح "كل عملية شراء = مناسبة واحدة"): events_count هو الحقل
     * الاختياري الوحيد في هذه الدالة إلى جانب salla_sku/salla_url (راجع
     * أسفله) — غيابه الكامل من $data يُعامَل مثل null فيُطبَّع عبر
     * normalize_events_count() إلى القيمة الافتراضية 1 تلقائياً. هذا مختلف
     * عمداً عن سلوك name (الذي يبقى إلزامياً هنا رغم اختياريته في
     * update_tier()) لأن events_count لم يكن له أصلاً أي مسار كتابة قبل هذا
     * الإصلاح، فلا معنى لجعله "إلزامياً" على مستدعين قدامى لا يعرفون به.
     *
     * إنشاء صف واحد في mon_plan_tiers. $data يجب أن تكون array تحتوي جميع
     * الحقول الثمانية (plan_id, tier_key, name, price, currency,
     * salla_product_id, status, sort_order) عبر array_key_exists() — أي حقل
     * غائب يُعيد null فوراً؛ لا حقول اختيارية ولا قيم افتراضية في هذه الدالة
     * (name مطلوب هنا مثل بقية الحقول، على عكس سلوكها الاختياري في
     * update_tier() — راجع توثيق تلك الدالة). plan_id يخضع لنفس شرط
     * get_plan() الصارم، ويجب أن تكون الباقة موجودة فعلياً (عبر get_plan())
     * وإلا تُعاد null. name يُطبَّع عبر normalize_tier_name()؛ فشلها (تُعيد
     * false) يوقف الإنشاء بالكامل دون أي استخراج تلقائي من tier_key — لا
     * قيمة افتراضية لـname في create_tier(). كل حقل آخر يُطبَّع عبر دالة
     * normalize_* المخصصة له (tier_key، price، currency عبر
     * normalize_currency() الحالية، status عبر normalize_status() الحالية،
     * sort_order عبر normalize_sort_order() الحالية)؛ فشل أي منها يوقف
     * العملية بالكامل فوراً دون أي إدخال. salla_product_id حالة خاصة: normalize_salla_product_id()
     * تُعيد false للقيم غير الصالحة فعلاً (يوقف العملية)، أو null لقيمة
     * "لا يوجد معرّف" الصالحة (يُتابَع بها العملية وتُخزَّن NULL)، أو نصاً
     * غير فارغ. بعد نجاح كل التطبيعات: يُتحقَّق أن tier_key غير مستخدم من
     * قبل *ضمن نفس الباقة فقط* عبر get_tier_by_key($plan_id, $tier_key) —
     * نفس المفتاح في باقة أخرى مقبول تماماً؛ وإن كانت salla_product_id غير
     * null، يُتحقَّق أنها غير مستخدمة في أي مستوى آخر عبر
     * get_tier_by_salla_product_id() (فحص تطبيقي مستقل عن أي UNIQUE index
     * في القاعدة، لا اعتماد عليه). الإدخال عبر $wpdb->insert() واحد فقط،
     * للحقول السبعة حصراً (بلا created_at/updated_at — تُملأ تلقائياً عبر
     * DEFAULT CURRENT_TIMESTAMP في Schema). فشل insert() (false) يُعيد
     * null؛ عند النجاح تُعاد الصفوف كاملة عبر get_tier($wpdb->insert_id).
     */
    public static function create_tier($data)
    {
        if (!is_array($data)) {
            return null;
        }

        $required_fields = ['plan_id', 'tier_key', 'name', 'price', 'currency', 'salla_product_id', 'status', 'sort_order'];
        foreach ($required_fields as $field) {
            if (!array_key_exists($field, $data)) {
                return null;
            }
        }

        if (is_int($data['plan_id'])) {
            $normalized_plan_id = $data['plan_id'];
        } elseif (is_string($data['plan_id']) && preg_match('/^[1-9][0-9]*$/', $data['plan_id'])) {
            $normalized_plan_id = (int) $data['plan_id'];
        } else {
            return null;
        }

        if ($normalized_plan_id < 1) {
            return null;
        }

        if (self::get_plan($normalized_plan_id) === null) {
            return null;
        }

        $normalized_tier_key = self::normalize_tier_key($data['tier_key']);
        if ($normalized_tier_key === null) {
            return null;
        }

        $normalized_name = self::normalize_tier_name($data['name']);
        if ($normalized_name === false) {
            return null;
        }

        $normalized_price = self::normalize_price($data['price']);
        if ($normalized_price === null) {
            return null;
        }

        $normalized_currency = self::normalize_currency($data['currency']);
        if ($normalized_currency === null) {
            return null;
        }

        $normalized_salla_product_id = self::normalize_salla_product_id($data['salla_product_id']);
        if ($normalized_salla_product_id === false) {
            return null;
        }

        $normalized_salla_sku = self::normalize_salla_sku($data['salla_sku'] ?? null);
        if ($normalized_salla_sku === false) {
            return null;
        }

        $normalized_salla_url = self::normalize_salla_url($data['salla_url'] ?? null);
        if ($normalized_salla_url === false) {
            return null;
        }

        $normalized_status = self::normalize_status($data['status']);
        if ($normalized_status === null) {
            return null;
        }

        $normalized_sort_order = self::normalize_sort_order($data['sort_order']);
        if ($normalized_sort_order === null) {
            return null;
        }

        // events_count اختياري عن قصد (على عكس الحقول الثمانية الإلزامية
        // أعلاه): غيابه بالكامل من $data يُعامَل مثل إرساله null — تُعيد
        // normalize_events_count() القيمة الافتراضية 1 مباشرة (راجع توثيق
        // تلك الدالة لسبب اختيار 1 تحديداً). هذا يضمن أن كل Tier جديدة تُنشَأ
        // عبر أي مسار (لوحة إدارة حالية بلا حقل لهذا العمود، أو أي كود
        // مستقبلي) تحصل تلقائياً على events_count = 1 دون أي حاجة للمستدعي
        // لتمرير القيمة صراحةً — وفي الوقت نفسه، تمرير قيمة > 1 صراحةً يبقى
        // مدعوماً بالكامل ولا يُفرَض عليه 1 أبداً.
        $normalized_events_count = self::normalize_events_count($data['events_count'] ?? null);
        if ($normalized_events_count === false) {
            return null;
        }

        if (self::get_tier_by_key($normalized_plan_id, $normalized_tier_key) !== null) {
            return null;
        }

        if ($normalized_salla_sku !== null && self::get_tier_by_salla_sku($normalized_salla_sku) !== null) {
            return null;
        }

        global $wpdb;

        $inserted = $wpdb->insert(
            self::tiers_table(),
            [
                'plan_id'           => $normalized_plan_id,
                'tier_key'          => $normalized_tier_key,
                'name'              => $normalized_name,
                'events_count'      => $normalized_events_count,
                'price'             => $normalized_price,
                'currency'          => $normalized_currency,
                'salla_product_id'  => $normalized_salla_product_id,
                'salla_sku'         => $normalized_salla_sku,
                'salla_url'         => $normalized_salla_url,
                'status'            => $normalized_status,
                'sort_order'        => $normalized_sort_order,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );

        if (!$inserted) {
            return null;
        }

        return self::get_tier($wpdb->insert_id);
    }

    /**
     * ملاحظة (إصلاح "كل عملية شراء = مناسبة واحدة"): events_count اختياري
     * هنا بنفس أسلوب name بالضبط (array_key_exists — غياب المفتاح كلياً لا
     * يُغيّر القيمة الحالية إطلاقاً؛ وجوده يُطبَّع عبر normalize_events_count()
     * ويُحدَّث). هذا يسمح للمسؤول بتغيير القيمة لاحقاً لأي رقم يريده، ولا
     * يفرض 1 إلا عند إرسال قيمة "غير محدَّدة" صراحةً (null/0/'').
     *
     * تحديث ذري كامل لصف مستوى موجود عبر استدعاء $wpdb->update() واحد فقط،
     * بعد التحقق الكامل من جميع القيم مسبقاً — لا تحديث جزئي بأي حال (نفس
     * فلسفة update_plan()). tier_id يخضع لنفس شرط get_tier() الصارم (int
     * موجب أو string ^[1-9][0-9]*$)؛ فشل التحقق يُعيد null فوراً دون
     * استعلام. تُجلَب الباقة الحالية عبر get_tier()؛ إن لم توجد تُعاد null.
     * $data يجب أن تكون array تحتوي الحقول السبعة جميعها عبر
     * array_key_exists() — أي حقل غائب يُعيد null فوراً، بلا حقول اختيارية.
     * plan_id الجديد يخضع لنفس شرط create_tier() الصارم، ويجب أن تكون
     * الباقة الجديدة موجودة فعلياً — هذا يسمح ضمنياً بنقل المستوى بين
     * الباقات (plan_id الجديد قد يختلف عن الحالي، ولا فحص خاص يمنع ذلك طالما
     * الباقة الهدف موجودة ولا تعارض في tier_key). كل حقل آخر يُطبَّع عبر
     * دالة normalize_* الحالية نفسها المستخدمة في create_tier() دون أي
     * تعديل عليها؛ فشل أي منها يوقف العملية بالكامل فوراً. salla_product_id
     * حالة خاصة كما في create_tier(): normalize_salla_product_id() تُعيد
     * false للقيمة غير الصالحة (يوقف العملية)، أو null لقيمة "إزالة الربط"
     * الصالحة (يُتابَع بها التحديث، وتُخزَّن NULL — هذا يسمح صراحة بإزالة
     * salla_product_id من مستوى كان مرتبطاً بمنتج)، أو نصاً غير فارغ. بعد
     * نجاح كل التطبيعات: يُتحقَّق أن (plan_id الجديد, tier_key الجديد) غير
     * مستخدمين من قبل عبر get_tier_by_key() — إن أعادت صفاً بـ id مختلف عن
     * $normalized_tier_id تُرفض العملية؛ أما إن كان الصف الناتج هو المستوى
     * نفسه (نفس id) فالتحديث مسموح (يشمل حالة عدم تغيير plan_id/tier_key
     * إطلاقاً). نفس المنطق لـsalla_product_id عبر get_tier_by_salla_product_id()
     * إن كانت القيمة المطبَّعة غير null. الإدخال عبر $wpdb->update() واحد
     * فقط للحقول السبعة معاً (WHERE id = %d)، بلا لمس created_at/updated_at
     * (الأخير تتولاه Schema تلقائياً عبر ON UPDATE CURRENT_TIMESTAMP).
     * $wpdb->update() يُعيد false عند خطأ SQL فعلي (→ null)؛ أما 0 (لم تتغير
     * أي قيمة فعلياً في MySQL) فليس فشلاً — في كلتا الحالتين (0 أو أكبر)
     * تُعاد الباقة عبر get_tier($normalized_tier_id). لا transaction هنا —
     * التحقق الكامل يسبق الاستعلام الوحيد أصلاً فلا حاجة له.
     *
     * name حالة خاصة عن قصد (على عكس الحقول السبعة الإلزامية أعلاه، ونفس
     * أسلوب features في update_plan() تماماً): يُستخدَم
     * array_key_exists('name', $data) لا isset() — إن كان المفتاح غائباً
     * كلياً، لا يُلمَس عمود name إطلاقاً (لا يدخل في $update_data)، والاسم
     * الحالي يبقى كما هو دون أي تغيير. إن كان المفتاح موجوداً، تُطبَّع القيمة
     * عبر normalize_tier_name()؛ فشلها (تُعيد false) يوقف التحديث بالكامل
     * فوراً دون لمس أي حقل آخر — تماماً كفشل أي حقل إلزامي آخر، لا تحديث
     * جزئي بأي حال. على عكس features، لا توجد لـname أي قيمة "امسح" صالحة:
     * إرسال name فارغة أو مسافات فقط يُرفَض دائماً عبر normalize_tier_name()
     * نفسها (تُعيد false)، فلا يمكن أبداً مسح الاسم إلى فارغ.
     */
    public static function update_tier($tier_id, $data)
    {
        if (is_int($tier_id)) {
            $normalized_tier_id = $tier_id;
        } elseif (is_string($tier_id) && preg_match('/^[1-9][0-9]*$/', $tier_id)) {
            $normalized_tier_id = (int) $tier_id;
        } else {
            return null;
        }

        if ($normalized_tier_id < 1) {
            return null;
        }

        $current_tier = self::get_tier($normalized_tier_id);
        if ($current_tier === null) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $required_fields = ['plan_id', 'tier_key', 'price', 'currency', 'salla_product_id', 'status', 'sort_order'];
        foreach ($required_fields as $field) {
            if (!array_key_exists($field, $data)) {
                return null;
            }
        }

        if (is_int($data['plan_id'])) {
            $normalized_plan_id = $data['plan_id'];
        } elseif (is_string($data['plan_id']) && preg_match('/^[1-9][0-9]*$/', $data['plan_id'])) {
            $normalized_plan_id = (int) $data['plan_id'];
        } else {
            return null;
        }

        if ($normalized_plan_id < 1) {
            return null;
        }

        if (self::get_plan($normalized_plan_id) === null) {
            return null;
        }

        $normalized_tier_key = self::normalize_tier_key($data['tier_key']);
        if ($normalized_tier_key === null) {
            return null;
        }

        $normalized_price = self::normalize_price($data['price']);
        if ($normalized_price === null) {
            return null;
        }

        $normalized_currency = self::normalize_currency($data['currency']);
        if ($normalized_currency === null) {
            return null;
        }

        $normalized_salla_product_id = self::normalize_salla_product_id($data['salla_product_id']);
        if ($normalized_salla_product_id === false) {
            return null;
        }

        $normalized_salla_sku = self::normalize_salla_sku(
            array_key_exists('salla_sku', $data)
                ? $data['salla_sku']
                : ($current_tier['salla_sku'] ?? null)
        );
        if ($normalized_salla_sku === false) {
            return null;
        }

        $normalized_salla_url = self::normalize_salla_url(
            array_key_exists('salla_url', $data)
                ? $data['salla_url']
                : ($current_tier['salla_url'] ?? null)
        );
        if ($normalized_salla_url === false) {
            return null;
        }

        $normalized_status = self::normalize_status($data['status']);
        if ($normalized_status === null) {
            return null;
        }

        $normalized_sort_order = self::normalize_sort_order($data['sort_order']);
        if ($normalized_sort_order === null) {
            return null;
        }

        $name_provided = array_key_exists('name', $data);
        $normalized_name = null;
        if ($name_provided) {
            $normalized_name = self::normalize_tier_name($data['name']);
            if ($normalized_name === false) {
                return null;
            }
        }

        // events_count اختياري هنا بنفس أسلوب name أعلاه بالضبط (لا نمط
        // create_tier() الذي يُعيد الافتراضي 1 عند الغياب الكامل): إن كان
        // المفتاح غائباً كلياً عن $data، لا يُلمَس عمود events_count إطلاقاً
        // والقيمة الحالية للمستوى تبقى كما هي دون أي تغيير — هذا يحمي مثلاً
        // مستوى events_count = 5 من أن يتحول لـ1 بالخطأ بسبب تحديث لا علاقة
        // له بهذا العمود إطلاقاً (كتحديث السعر فقط). إن كان المفتاح موجوداً
        // صراحةً (حتى لو null/0/'')، تُطبَّق normalize_events_count() كاملة:
        // القيم "غير المحدَّدة" (null/0/'') تُصبح 1 عمداً، والقيم الصحيحة
        // الأخرى تُقبَل كما هي — هذا يسمح للمسؤول بتغيير القيمة لاحقاً لأي
        // رقم يريده دون أي قفل، تماماً كما يتطلب القرار التجاري لهذه المرحلة.
        $events_count_provided = array_key_exists('events_count', $data);
        $normalized_events_count = null;
        if ($events_count_provided) {
            $normalized_events_count = self::normalize_events_count($data['events_count']);
            if ($normalized_events_count === false) {
                return null;
            }
        }

        $key_owner = self::get_tier_by_key($normalized_plan_id, $normalized_tier_key);
        if ($key_owner !== null && (int) $key_owner['id'] !== $normalized_tier_id) {
            return null;
        }

        if ($normalized_salla_sku !== null) {
            $salla_owner = self::get_tier_by_salla_sku($normalized_salla_sku);
            if ($salla_owner !== null && (int) $salla_owner['id'] !== $normalized_tier_id) {
                return null;
            }
        }

        global $wpdb;

        $update_data = [
            'plan_id'          => $normalized_plan_id,
            'tier_key'         => $normalized_tier_key,
            'price'            => $normalized_price,
            'currency'         => $normalized_currency,
            'salla_product_id' => $normalized_salla_product_id,
            'salla_sku'        => $normalized_salla_sku,
            'salla_url'        => $normalized_salla_url,
            'status'           => $normalized_status,
            'sort_order'       => $normalized_sort_order,
        ];
        $update_formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d'];

        if ($name_provided) {
            $update_data['name'] = $normalized_name;
            $update_formats[] = '%s';
        }

        if ($events_count_provided) {
            $update_data['events_count'] = $normalized_events_count;
            $update_formats[] = '%d';
        }

        $updated = $wpdb->update(
            self::tiers_table(),
            $update_data,
            [
                'id' => $normalized_tier_id,
            ],
            $update_formats,
            [
                '%d',
            ]
        );

        if ($updated === false) {
            return null;
        }

        return self::get_tier($normalized_tier_id);
    }

    /**
     * حذف صف مستوى واحد من mon_plan_tiers، بنفس أسلوب delete_plan() تماماً:
     * tier_id يخضع لنفس شرط التحقق الصارم المستخدم في get_tier()/
     * update_tier() (int موجب، أو string مكوّن بالكامل من رقم صحيح موجب
     * بنمط ^[1-9][0-9]*$ — يرفض الصفر، السالب، العشري، النص المختلط
     * كـ"12abc"، الصفر البادئ كـ"01"، والمسافات كـ" 12 " لأن preg_match لا
     * يقبل أي حرف زائد قبل أو بعد الأرقام)؛ فشل التحقق يُعيد false فوراً
     * دون أي استعلام. تُجلَب المستوى أولاً عبر get_tier() للتأكد من وجوده
     * فعلياً؛ إن لم يوجد تُعاد false دون أي محاولة حذف — لا يُعتبر حذف صف
     * غير موجود عملية ناجحة. لا فحص لـ plan_id ولا لأي جدول آخر هنا: tier_id
     * الموثوق بعد التحقق ووجود الصف كافيان، ولا توجد صفوف تابعة للمستوى في
     * البنية الحالية تستوجب فحص ارتباط مثل الذي في delete_plan(). الحذف عبر
     * $wpdb->delete() واحد فقط (WHERE id = %d)؛ أي نتيجة غير نجاح صف واحد
     * بالضبط (false عند خطأ SQL فعلي، أو 0 عند عدم تأثر أي صف — حالة
     * مستبعدة عملياً هنا لأن الوجود تحقق مسبقاً) تُعاد كـ false. النجاح
     * الوحيد هو حذف صف واحد فعلي، وعندها تُعاد true. القيمة المُعادة boolean
     * صرفة في كل المسارات — لا يُعاد الصف المحذوف ولا عدد الصفوف.
     */
    public static function delete_tier($tier_id)
    {
        if (is_int($tier_id)) {
            $normalized_tier_id = $tier_id;
        } elseif (is_string($tier_id) && preg_match('/^[1-9][0-9]*$/', $tier_id)) {
            $normalized_tier_id = (int) $tier_id;
        } else {
            return false;
        }

        if ($normalized_tier_id < 1) {
            return false;
        }

        $current_tier = self::get_tier($normalized_tier_id);
        if ($current_tier === null) {
            return false;
        }

        global $wpdb;

        $deleted = $wpdb->delete(
            self::tiers_table(),
            ['id' => $normalized_tier_id],
            ['%d']
        );

        if ($deleted === false || $deleted === 0) {
            return false;
        }

        return true;
    }

    /**
     * قراءة خدمة واحدة برقمها فقط. نفس قواعد التحقق المستخدمة في get_plan()
     * وget_tier(): تقبل عدداً صحيحاً (int) موجباً (>= 1)، أو نصاً (string)
     * يحتوي على عدد صحيح موجب فقط بلا فاصلة عشرية وبلا أي حرف زائد (نمط
     * ^[1-9][0-9]*$). أي شيء آخر يُعيد null فوراً دون أي استعلام. إن لم
     * توجد الخدمة بعد التطبيع تُعاد null أيضاً. عند النجاح تُعاد الصفوف
     * كمصفوفة ترابطية (Associative Array) عبر ARRAY_A.
     */
    public static function get_service($service_id)
    {
        if (is_int($service_id)) {
            $normalized_id = $service_id;
        } elseif (is_string($service_id) && preg_match('/^[1-9][0-9]*$/', $service_id)) {
            $normalized_id = (int) $service_id;
        } else {
            return null;
        }

        if ($normalized_id < 1) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::services_table() . " WHERE id = %d",
                $normalized_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $row;
    }

    /**
     * قراءة خدمة واحدة بمفتاحها النصي (service_key) فقط. نفس نمط
     * get_plan_by_key(): لا تقبل إلا string؛ أي نوع آخر (null، bool، array،
     * عدد...) يُعيد null فوراً دون أي استعلام. تُطبَّع القيمة عبر
     * sanitize_key() (تحويل لـ lowercase مع إبقاء [a-z0-9_-] فقط وtrim). إن
     * أصبحت القيمة فارغة بعد التنظيف تُعاد null أيضاً دون استعلام. لا يوجد
     * أي شرط على status هنا. إن لم توجد الخدمة تُعاد null؛ وإلا تُعاد الصفوف
     * كمصفوفة ترابطية عبر ARRAY_A.
     */
    public static function get_service_by_key($service_key)
    {
        if (!is_string($service_key)) {
            return null;
        }

        $normalized_key = sanitize_key($service_key);

        if ($normalized_key === '') {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::services_table() . " WHERE service_key = %s",
                $normalized_key
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $row;
    }

    /**
     * قراءة جميع صفوف جدول الخدمات بلا أي معامل، بلا فلترة على status، وبلا
     * ترتيب ديناميكي. الترتيب ثابت دائماً: sort_order تصاعدياً ثم id تصاعدياً
     * كفاصل تعادل، نفس نمط get_plans(). إن لم توجد أي خدمة تُعاد مصفوفة فارغة
     * []، وإلا تُعاد مصفوفة من الصفوف، كل صف كمصفوفة ترابطية عبر ARRAY_A.
     */
    public static function get_services()
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM " . self::services_table() . " ORDER BY sort_order ASC, id ASC",
            ARRAY_A
        );

        if (!$rows) {
            return [];
        }

        return $rows;
    }

    /**
     * قراءة خدمة واحدة بمعرّف منتج سلة (salla_product_id). نفس نمط التحقق
     * والتطبيع المستخدم في get_tier_by_salla_product_id(): تقبل إما string
     * غير فارغ بعد trim()، أو int موجب (>= 1) يُحوَّل إلى string قبل
     * الاستعلام. أي شيء آخر (null، true، false، array، int <= 0، أو نص فارغ/
     * مسافات فقط بعد trim) يُعيد null فوراً دون أي استعلام. لا sanitize_key()
     * ولا تحويل لـ lowercase — لنفس السبب: معرّف منتج سلة قد يحتوي صيغة لا
     * يجوز تغييرها قبل المطابقة. عند النجاح تُعاد الصفوف كمصفوفة ترابطية عبر
     * ARRAY_A؛ إن لم توجد الخدمة تُعاد null.
     */
    public static function get_service_by_salla_product_id($salla_product_id)
    {
        if (is_int($salla_product_id)) {
            if ($salla_product_id <= 0) {
                return null;
            }
            $normalized = (string) $salla_product_id;
        } elseif (is_string($salla_product_id)) {
            $normalized = $salla_product_id;
        } else {
            return null;
        }

        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::services_table() . " WHERE salla_product_id = %s",
                $normalized
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $row;
    }

    /**
     * إنشاء صف واحد في mon_plans. تقبل فقط الحقول: plan_key, name, plan_type,
     * status, sort_order — أي حقل آخر في $data يُتجاهَل تماماً ولا يصل لقاعدة
     * البيانات. plan_key وname مطلوبان؛ plan_type/status/sort_order اختيارية
     * بقيم افتراضية ('personal'/'active'/0) تُستخدَم فقط عند غياب الحقل
     * كلياً من $data — إن كان الحقل موجوداً لكن قيمته فشلت في التطبيع، تُعاد
     * null فوراً دون أي استبدال بالقيمة الافتراضية (فرق متعمد بين "غائب"
     * و"موجود لكن غير صالح"). كل حقل يُطبَّع عبر دالة normalize_* المخصصة
     * له. features اختياري دائماً (بلا حقل مطلوب مقابل): يُطبَّع عبر
     * normalize_features($data['features'] ?? null)، وفشل تطبيعها (تُعيد
     * false) يوقف الإنشاء بالكامل مثل أي حقل آخر. قبل الإدخال يُتحقَّق أن
     * plan_key غير مستخدم مسبقاً عبر get_plan_by_key()، لمنع وصول الإدخال
     * المكرر لخطأ قاعدة بيانات #1062. عند نجاح $wpdb->insert() تُعاد الباقة
     * كاملة عبر get_plan($wpdb->insert_id)؛ عند أي فشل (مدخل غير صالح، حقل
     * مفقود/غير صالح، مفتاح مكرر، أو فشل الإدخال نفسه) تُعاد null دون
     * استثناءات أو WP_Error.
     */
    public static function create_plan($data)
    {
        if (!is_array($data)) {
            return null;
        }

        if (!array_key_exists('plan_key', $data)) {
            return null;
        }
        $plan_key = self::normalize_plan_key($data['plan_key']);
        if ($plan_key === null) {
            return null;
        }

        if (!array_key_exists('name', $data)) {
            return null;
        }
        $name = self::normalize_plan_name($data['name']);
        if ($name === null) {
            return null;
        }

        if (array_key_exists('plan_type', $data)) {
            $plan_type = self::normalize_plan_type($data['plan_type']);
            if ($plan_type === null) {
                return null;
            }
        } else {
            $plan_type = 'personal';
        }

        if (array_key_exists('status', $data)) {
            $status = self::normalize_status($data['status']);
            if ($status === null) {
                return null;
            }
        } else {
            $status = 'active';
        }

        if (array_key_exists('sort_order', $data)) {
            $sort_order = self::normalize_sort_order($data['sort_order']);
            if ($sort_order === null) {
                return null;
            }
        } else {
            $sort_order = 0;
        }

        $features = self::normalize_features($data['features'] ?? null);
        if ($features === false) {
            return null;
        }

        if (self::get_plan_by_key($plan_key) !== null) {
            return null;
        }

        global $wpdb;

        $inserted = $wpdb->insert(
            self::plans_table(),
            [
                'plan_key' => $plan_key,
                'name' => $name,
                'plan_type' => $plan_type,
                'status' => $status,
                'features' => $features,
                'sort_order' => $sort_order,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d']
        );

        if (!$inserted) {
            return null;
        }

        return self::get_plan($wpdb->insert_id);
    }

    /**
     * تحديث موحّد للحقول الخمسة (plan_key, name, plan_type, status,
     * sort_order) عبر استدعاء $wpdb->update() واحد فقط، بعد التحقق الكامل من
     * جميع القيم مسبقاً — يحل مشكلة التحديث الجزئي الموجودة في سلسلة دوال
     * update_plan_* المنفردة (التي قد ينجح بعضها ثم يفشل لاحقها فتترك الصف
     * في حالة وسيطة). plan_id يخضع لنفس شرط التحقق الصارم المستخدم في
     * get_plan(): int موجب، أو string مكوّن بالكامل من رقم صحيح موجب (نمط
     * ^[1-9][0-9]*$)؛ أي شيء آخر يُعيد null فوراً دون أي استعلام. $data يجب
     * أن تكون array، وإلا null فوراً. تُجلَب الباقة الحالية أولاً عبر
     * get_plan()؛ إن لم توجد تُعاد null. الحقول الخمسة جميعها مطلوبة داخل
     * $data عبر array_key_exists() (وليس isset()) — هذا متعمد: يسمح لقيمة
     * null الصريحة بالوصول إلى دالة normalize_* المختصة بها لتُرفض هناك،
     * بدل أن تُعامَل ضمنياً كـ"حقل غائب" بواسطة isset(). كل قيمة تُطبَّع عبر
     * دالة normalize_* الحالية المخصصة لها فقط (بلا استدعاء أي دالة
     * update_plan_* منفردة)؛ فشل أي دالة تطبيع (تُعيد null) يوقف العملية
     * بالكامل فوراً دون أي UPDATE. بعد نجاح التطبيع، يُتحقَّق من عدم تكرار
     * plan_key عبر get_plan_by_key() — إن وُجدت باقة أخرى (id مختلف) تملك
     * نفس المفتاح، تُعاد null؛ استخدام نفس المفتاح الحالي للباقة نفسها مقبول
     * بطبيعته لأن شرط "id مختلف" لا يتحقق. إن كانت القيم الخمس المطبَّعة
     * جميعها مطابقة تماماً للقيم الحالية المخزَّنة (بعد تحويل sort_order إلى
     * int للمقارنة، لأن $wpdb يُعيده كـ string)، تُعاد الباقة الحالية فوراً
     * دون أي استدعاء لـ $wpdb->update() — تماماً كما في دوال update_plan_*
     * المنفردة. خلاف ذلك يُنفَّذ استدعاء UPDATE واحد فقط للحقول الخمسة معاً.
     * $wpdb->update() يُعيد false عند خطأ SQL فعلي (→ null)، أو عدداً (0 أو
     * أكبر) عند النجاح — كلا الحالتين هنا تعنيان نجاح الاستعلام نفسه (0 لا
     * يعني فشلاً، بل قد يعني عدم وجود اختلاف فعلي رصدته MySQL رغم اختلاف
     * القيم منطقياً)، فتُعاد الباقة المحدَّثة عبر get_plan($normalized_id)
     * في الحالتين. لا transaction هنا — العملية استعلام واحد فقط أصلاً.
     *
     * features حالة خاصة عن قصد (على عكس الحقول الخمسة الإلزامية): يُستخدَم
     * array_key_exists('features', $data) لا isset() — إن كان المفتاح
     * غائباً كلياً، لا يُلمَس عمود features إطلاقاً (لا يدخل في $update_data
     * ولا في مقارنة $has_changes). إن كان المفتاح موجوداً (بأي قيمة، بما
     * فيها null/[]/'' التي تعني صراحة "امسح المزايا")، تُطبَّع القيمة عبر
     * normalize_features()؛ فشلها (تُعيد false) يوقف التحديث بالكامل فوراً
     * دون لمس أي حقل آخر — تماماً كفشل أي حقل إلزامي آخر.
     */
    public static function update_plan($plan_id, $data)
    {
        if (is_int($plan_id)) {
            $normalized_id = $plan_id;
        } elseif (is_string($plan_id) && preg_match('/^[1-9][0-9]*$/', $plan_id)) {
            $normalized_id = (int) $plan_id;
        } else {
            return null;
        }

        if ($normalized_id < 1) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $existing_plan = self::get_plan($normalized_id);
        if ($existing_plan === null) {
            return null;
        }

        $required_fields = ['plan_key', 'name', 'plan_type', 'status', 'sort_order'];
        foreach ($required_fields as $field) {
            if (!array_key_exists($field, $data)) {
                return null;
            }
        }

        $normalized_plan_key = self::normalize_plan_key($data['plan_key']);
        if ($normalized_plan_key === null) {
            return null;
        }

        $normalized_name = self::normalize_plan_name($data['name']);
        if ($normalized_name === null) {
            return null;
        }

        $normalized_plan_type = self::normalize_plan_type($data['plan_type']);
        if ($normalized_plan_type === null) {
            return null;
        }

        $normalized_status = self::normalize_status($data['status']);
        if ($normalized_status === null) {
            return null;
        }

        $normalized_sort_order = self::normalize_sort_order($data['sort_order']);
        if ($normalized_sort_order === null) {
            return null;
        }

        $features_provided = array_key_exists('features', $data);
        $normalized_features = null;
        if ($features_provided) {
            $normalized_features = self::normalize_features($data['features']);
            if ($normalized_features === false) {
                return null;
            }
        }

        $key_owner = self::get_plan_by_key($normalized_plan_key);
        if ($key_owner !== null && (int) $key_owner['id'] !== $normalized_id) {
            return null;
        }

        $has_changes = (
            $normalized_plan_key !== $existing_plan['plan_key']
            || $normalized_name !== $existing_plan['name']
            || $normalized_plan_type !== $existing_plan['plan_type']
            || $normalized_status !== $existing_plan['status']
            || $normalized_sort_order !== (int) $existing_plan['sort_order']
            || ($features_provided && $normalized_features !== $existing_plan['features'])
        );

        if (!$has_changes) {
            return $existing_plan;
        }

        $update_data = [
            'plan_key'   => $normalized_plan_key,
            'name'       => $normalized_name,
            'plan_type'  => $normalized_plan_type,
            'status'     => $normalized_status,
            'sort_order' => $normalized_sort_order,
        ];
        $update_formats = ['%s', '%s', '%s', '%s', '%d'];

        if ($features_provided) {
            $update_data['features'] = $normalized_features;
            $update_formats[] = '%s';
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::plans_table(),
            $update_data,
            [
                'id' => $normalized_id,
            ],
            $update_formats,
            [
                '%d',
            ]
        );

        if ($updated === false) {
            return null;
        }

        return self::get_plan($normalized_id);
    }

    /**
     * تحديث حقل name فقط لباقة موجودة. plan_id يخضع لنفس شرط التحقق الصارم
     * المستخدم في get_plan()/get_tier()/get_service(): int موجب، أو string
     * مكوّن بالكامل من رقم صحيح موجب (نمط ^[1-9][0-9]*$)؛ أي شيء آخر يُعيد
     * null فوراً دون أي استعلام. الاسم يُطبَّع عبر normalize_plan_name()؛
     * فشل التطبيع يُعيد null. تُجلَب الباقة أولاً عبر get_plan() للتأكد من
     * وجودها فعلياً، وإن لم توجد تُعاد null. إن كان الاسم المطبَّع مطابقاً
     * تماماً (===) للاسم المخزَّن حالياً، تُعاد الباقة كما هي فوراً دون أي
     * استدعاء لـ $wpdb->update() — لا داعٍ لكتابة لم تُغيِّر شيئاً. عند
     * التحديث الفعلي، يُحدَّث عمود name فقط عبر WHERE id = %d؛ فشل
     * $wpdb->update() (يُعيد false عند خطأ SQL فعلي، وليس عند 0 صفوف
     * متأثرة، وهي حالة مستبعدة أصلاً لأن الوجود تحقق مسبقاً) يُعيد null.
     * عند النجاح تُعاد الباقة المحدَّثة عبر get_plan($normalized_id).
     */
    public static function update_plan_name($plan_id, $name)
    {
        if (is_int($plan_id)) {
            $normalized_id = $plan_id;
        } elseif (is_string($plan_id) && preg_match('/^[1-9][0-9]*$/', $plan_id)) {
            $normalized_id = (int) $plan_id;
        } else {
            return null;
        }

        if ($normalized_id < 1) {
            return null;
        }

        $normalized_name = self::normalize_plan_name($name);
        if ($normalized_name === null) {
            return null;
        }

        $plan = self::get_plan($normalized_id);
        if ($plan === null) {
            return null;
        }

        if ($normalized_name === $plan['name']) {
            return $plan;
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::plans_table(),
            ['name' => $normalized_name],
            ['id' => $normalized_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return null;
        }

        return self::get_plan($normalized_id);
    }

    /**
     * تحديث حقل plan_type فقط لباقة موجودة. نفس بنية update_plan_name()
     * تماماً لكن مع normalize_plan_type() بدل normalize_plan_name(): plan_id
     * يخضع لنفس شرط التحقق الصارم (int موجب أو string ^[1-9][0-9]*$)؛ فشل
     * التحقق يُعيد null فوراً دون استعلام. plan_type يُطبَّع ويُتحقَّق منه
     * مقابل self::ALLOWED_PLAN_TYPES عبر normalize_plan_type()؛ فشل ذلك يُعيد
     * null. تُجلَب الباقة أولاً؛ إن لم توجد تُعاد null. إن كانت القيمة
     * المطبَّعة مطابقة تماماً (===) للقيمة الحالية، تُعاد الباقة كما هي فوراً
     * دون أي استدعاء لـ $wpdb->update(). عند التحديث الفعلي، يُحدَّث عمود
     * plan_type فقط عبر WHERE id = %d؛ فشل $wpdb->update() يُعيد null. عند
     * النجاح تُعاد الباقة المحدَّثة عبر get_plan($normalized_id).
     */
    public static function update_plan_type($plan_id, $plan_type)
    {
        if (is_int($plan_id)) {
            $normalized_id = $plan_id;
        } elseif (is_string($plan_id) && preg_match('/^[1-9][0-9]*$/', $plan_id)) {
            $normalized_id = (int) $plan_id;
        } else {
            return null;
        }

        if ($normalized_id < 1) {
            return null;
        }

        $normalized_type = self::normalize_plan_type($plan_type);
        if ($normalized_type === null) {
            return null;
        }

        $plan = self::get_plan($normalized_id);
        if ($plan === null) {
            return null;
        }

        if ($normalized_type === $plan['plan_type']) {
            return $plan;
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::plans_table(),
            ['plan_type' => $normalized_type],
            ['id' => $normalized_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return null;
        }

        return self::get_plan($normalized_id);
    }

    /**
     * تحديث حقل status فقط لباقة موجودة. نفس بنية update_plan_name()
     * وupdate_plan_type() تماماً لكن مع normalize_status(): plan_id يخضع
     * لنفس شرط التحقق الصارم (int موجب أو string ^[1-9][0-9]*$)؛ فشل
     * التحقق يُعيد null فوراً دون استعلام. status يُطبَّع ويُتحقَّق منه
     * مقابل self::ALLOWED_STATUSES عبر normalize_status()؛ فشل ذلك يُعيد
     * null. تُجلَب الباقة أولاً؛ إن لم توجد تُعاد null. إن كانت القيمة
     * المطبَّعة مطابقة تماماً (===) للقيمة الحالية، تُعاد الباقة كما هي فوراً
     * دون أي استدعاء لـ $wpdb->update(). عند التحديث الفعلي، يُحدَّث عمود
     * status فقط عبر WHERE id = %d؛ فشل $wpdb->update() يُعيد null. عند
     * النجاح تُعاد الباقة المحدَّثة عبر get_plan($normalized_id).
     */
    public static function update_plan_status($plan_id, $status)
    {
        if (is_int($plan_id)) {
            $normalized_id = $plan_id;
        } elseif (is_string($plan_id) && preg_match('/^[1-9][0-9]*$/', $plan_id)) {
            $normalized_id = (int) $plan_id;
        } else {
            return null;
        }

        if ($normalized_id < 1) {
            return null;
        }

        $normalized_status = self::normalize_status($status);
        if ($normalized_status === null) {
            return null;
        }

        $plan = self::get_plan($normalized_id);
        if ($plan === null) {
            return null;
        }

        if ($normalized_status === $plan['status']) {
            return $plan;
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::plans_table(),
            ['status' => $normalized_status],
            ['id' => $normalized_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return null;
        }

        return self::get_plan($normalized_id);
    }

    /**
     * تحديث حقل sort_order فقط لباقة موجودة. نفس بنية update_plan_name()/
     * update_plan_type()/update_plan_status() لكن مع normalize_sort_order():
     * plan_id يخضع لنفس شرط التحقق الصارم (int موجب أو string
     * ^[1-9][0-9]*$)؛ فشل التحقق يُعيد null فوراً دون استعلام. sort_order
     * يُطبَّع عبر normalize_sort_order()؛ فشل ذلك يُعيد null. تُجلَب الباقة
     * أولاً؛ إن لم توجد تُعاد null. المقارنة مع القيمة الحالية تتم عبر
     * تحويل صريح لـ (int) — $plan['sort_order'] قادمة من $wpdb كـ string
     * دائماً، فلا يصح مقارنتها بـ === مباشرة مع القيمة المطبَّعة (int) دون
     * هذا التحويل. إن تطابقتا تُعاد الباقة كما هي فوراً دون أي استدعاء لـ
     * $wpdb->update(). عند التحديث الفعلي، يُحدَّث عمود sort_order فقط عبر
     * WHERE id = %d؛ فشل $wpdb->update() يُعيد null. عند النجاح تُعاد الباقة
     * المحدَّثة عبر get_plan($normalized_id).
     */
    public static function update_plan_sort_order($plan_id, $sort_order)
    {
        if (is_int($plan_id)) {
            $normalized_id = $plan_id;
        } elseif (is_string($plan_id) && preg_match('/^[1-9][0-9]*$/', $plan_id)) {
            $normalized_id = (int) $plan_id;
        } else {
            return null;
        }

        if ($normalized_id < 1) {
            return null;
        }

        $normalized_sort_order = self::normalize_sort_order($sort_order);
        if ($normalized_sort_order === null) {
            return null;
        }

        $plan = self::get_plan($normalized_id);
        if ($plan === null) {
            return null;
        }

        if ($normalized_sort_order === (int) $plan['sort_order']) {
            return $plan;
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::plans_table(),
            ['sort_order' => $normalized_sort_order],
            ['id' => $normalized_id],
            ['%d'],
            ['%d']
        );

        if ($updated === false) {
            return null;
        }

        return self::get_plan($normalized_id);
    }

    /**
     * تحديث حقل plan_key فقط لباقة موجودة. نفس بنية دوال update_plan_* الأخرى
     * لكن مع normalize_plan_key() وفحص تفرّد إضافي: plan_id يخضع لنفس شرط
     * التحقق الصارم (int موجب أو string ^[1-9][0-9]*$)؛ فشل التحقق يُعيد
     * null فوراً دون استعلام. plan_key يُطبَّع عبر normalize_plan_key()؛ فشل
     * ذلك يُعيد null. تُجلَب الباقة أولاً؛ إن لم توجد تُعاد null. إن كان
     * المفتاح المطبَّع مطابقاً تماماً (===) للمفتاح الحالي، تُعاد الباقة كما
     * هي فوراً دون أي فحص تفرّد أو استدعاء لـ $wpdb->update(). بما أن هذا
     * الفرع يُغادر الدالة عند التطابق، أي مفتاح يصل لفحص get_plan_by_key()
     * لاحقاً هو بالضرورة مختلف عن مفتاح هذه الباقة — فإن وُجدت باقة أخرى
     * تملكه، تُعاد null فوراً (يمنع الوصول لخطأ DB #1062 في الحالة الشائعة؛
     * UNIQUE KEY plan_key في Schema يبقى الحماية النهائية ضد التزامن —
     * لم يُعدَّل هنا). عند التحديث الفعلي، يُحدَّث عمود plan_key فقط عبر
     * WHERE id = %d؛ فشل $wpdb->update() يُعيد null. عند النجاح تُعاد الباقة
     * المحدَّثة عبر get_plan($normalized_id).
     */
    public static function update_plan_key($plan_id, $plan_key)
    {
        if (is_int($plan_id)) {
            $normalized_id = $plan_id;
        } elseif (is_string($plan_id) && preg_match('/^[1-9][0-9]*$/', $plan_id)) {
            $normalized_id = (int) $plan_id;
        } else {
            return null;
        }

        if ($normalized_id < 1) {
            return null;
        }

        $normalized_key = self::normalize_plan_key($plan_key);
        if ($normalized_key === null) {
            return null;
        }

        $plan = self::get_plan($normalized_id);
        if ($plan === null) {
            return null;
        }

        if ($normalized_key === $plan['plan_key']) {
            return $plan;
        }

        if (self::get_plan_by_key($normalized_key) !== null) {
            return null;
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::plans_table(),
            ['plan_key' => $normalized_key],
            ['id' => $normalized_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return null;
        }

        return self::get_plan($normalized_id);
    }

    /**
     * حذف باقة واحدة من mon_plans، برفض الحذف إذا كانت مرتبطة بأي tier في
     * mon_plan_tiers (بلا CASCADE يدوي وبلا حذف tiers تلقائياً — القرار
     * الأمان الافتراضي هو الرفض، لا الحذف الجانبي الصامت). plan_id يخضع لنفس
     * شرط التحقق الصارم المستخدم في get_plan() ودوال update_plan_* (int موجب
     * أو string ^[1-9][0-9]*$)؛ فشل التحقق يُعيد false فوراً دون استعلام.
     * تُجلَب الباقة أولاً عبر get_plan()؛ إن لم توجد تُعاد false. بعدها يُعدّ
     * عدد صفوف mon_plan_tiers المرتبطة بـ plan_id عبر COUNT(*) مُحضَّر
     * ($wpdb->prepare())؛ إن كان العدد أكبر من صفر تُعاد false دون أي محاولة
     * حذف. عند الحذف الفعلي عبر $wpdb->delete() (WHERE id = %d)، أي نتيجة
     * غير نجاح صف واحد بالضبط (false عند خطأ SQL، أو 0 عند عدم تأثر أي صف —
     * حالة مستبعدة عملياً هنا لأن الوجود تحقق مسبقاً) تُعاد كـ false. النجاح
     * الوحيد هو حذف صف واحد فعلي، وعندها تُعاد true.
     */
    public static function delete_plan($plan_id)
    {
        if (is_int($plan_id)) {
            $normalized_id = $plan_id;
        } elseif (is_string($plan_id) && preg_match('/^[1-9][0-9]*$/', $plan_id)) {
            $normalized_id = (int) $plan_id;
        } else {
            return false;
        }

        if ($normalized_id < 1) {
            return false;
        }

        $plan = self::get_plan($normalized_id);
        if ($plan === null) {
            return false;
        }

        global $wpdb;

        $tiers_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::tiers_table() . " WHERE plan_id = %d",
                $normalized_id
            )
        );

        if ($tiers_count > 0) {
            return false;
        }

        $deleted = $wpdb->delete(
            self::plans_table(),
            ['id' => $normalized_id],
            ['%d']
        );

        if ($deleted === false || $deleted === 0) {
            return false;
        }

        return true;
    }
}
