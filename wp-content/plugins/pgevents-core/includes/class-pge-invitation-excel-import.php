<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Excel Import — Parsing Adapter (Phase 2) + Duplicate
 * Detection (Phase 3)
 * ============================================================================
 * راجع docs/EXCEL-GUEST-IMPORT-SPEC.md (القسم 8: خطة التنفيذ المرحلية).
 *
 * نطاق Phase 2 (parse_file() وما يتصل بها مباشرة): قراءة ملف مدعوين (.xlsx
 * أو .csv) وتحويله إلى مصفوفة صفوف موحَّدة `['name','phone','note','status']`.
 *
 * نطاق Phase 3 المُضاف هنا (apply_duplicate_detection()/summarize_preview()):
 * تصنيف الصفوف الصالحة بنيوياً إلى valid/duplicate عبر مقارنتها بضيوف
 * المناسبة الحاليين فعلياً، وبناء ملخص أعداد لشاشة Preview. **لا يكتب هذا
 * الملف أي بيانات على الإطلاق حتى الآن** — لا استدعاء لـ
 * `PGE_Invitation_Service::create()`، لا لمس لـ`PGE_Invitation_Repository`،
 * لا Audit، لا قاعدة بيانات — هذه من مسؤولية Phase الـ Confirm اللاحقة حصراً.
 *
 * هذا الملف هو **محوّل مصدر بيانات (Adapter)** بحت: يقرأ → يتحقق من العقد
 * (Template Contract) → يطبِّع → يفحص التكرار (قراءة فقط) → يُرجِع مصفوفة.
 * لا Repository جديد، لا جدول جديد، لا Normalizer جديد (`pge_norm_phone()`
 * الموجودة فقط، helpers.php)، ولا قاعدة تكرار جديدة (`pge_event_guests_get_
 * map()` الموجودة فقط، event-guests.php — نفس ما يستخدمه Repository داخلياً).
 *
 * القارئ المُعتمَد لملفات XLSX: `\Shuchkin\SimpleXLSX` (إصدار 1.1.16، MIT)،
 * مُضمَّن يدوياً بلا Composer في `includes/lib/simplexlsx/` — راجع
 * `includes/lib/simplexlsx/README.md` لتفاصيل المصدر/الإصدار/الرخصة.
 */
class PGE_Invitation_Excel_Import_Service
{
    /** الصيغ المدعومة حصراً — أي صيغة أخرى (مثل xls) تُرفَض صراحة، بلا محاولة قراءة. */
    const SUPPORTED_TYPES = ['xlsx', 'csv'];

    /**
     * Template Contract (القسم 6 من الوثيقة): 3 أعمدة بالضبط، بهذا الترتيب
     * حرفياً. لا Column Mapping، لا اكتشاف أعمدة بديلة — تطابق حرفي أو رفض.
     */
    const EXPECTED_HEADER = ['الاسم', 'رقم الجوال', 'ملاحظة'];

    /**
     * حالات الصف. أُضيفَت 'duplicate' في Phase 3 (Duplicate Detection ضمن نفس
     * المناسبة) — لا تُنتَج مباشرة من parse_file() نفسها (التي لا تزال تُنتِج
     * فقط الحالات الست الأصلية من Phase 2)، بل تُطبَّق لاحقاً كتحويل على صفوف
     * status='valid' عبر apply_duplicate_detection() أدناه.
     */
    const ROW_STATUSES = [
        'valid',
        'empty_row',
        'missing_name',
        'missing_phone',
        'invalid_phone',
        'invalid_phone_cell_type',
        'duplicate',
    ];

    const STATUS_DUPLICATE = 'duplicate';

    /**
     * حدّ أدنى لعدد أرقام خلية Excel الرقمية (Numeric) ليُعتبَر "رقم هاتف
     * محتمل" آمناً للتحويل إلى نص — تحديث سياسة القسم 5.1 (لا يمسّ خلايا
     * Text، تلك تبقى بلا أي حد أدنى كما كانت دائماً — pge_norm_phone() فقط
     * هي من يحسم الفراغ بعد التطبيع). نفس حد "looks_like_phone" المُستخدَم
     * فعلياً في checkin-ui-ajax.php (راجع resolve_by_phone) — قيمة موثَّقة
     * ومُستخدَمة سلفاً في المشروع بدل رقم جديد مُخترَع.
     */
    const MIN_SAFE_NUMERIC_PHONE_DIGITS = 7;

    /**
     * حدّ أقصى لعدد أرقام خلية Excel الرقمية — نفس MAX_PHONE_DIGITS الموثَّقة
     * في class-pge-invitation-bulk-add.php (لا رقم هاتف حقيقي يتجاوز ~15
     * رقماً وفق E.164، مع هامش أمان لغاية 20).
     */
    const MAX_SAFE_NUMERIC_PHONE_DIGITS = 20;

    /**
     * نقطة الدخول الوحيدة. لا يكتب أي بيانات، لا يستدعي أي طبقة إنشاء ضيوف.
     *
     * @param string $path مسار ملف محلي موجود بالفعل على القرص (لا يُبنى من
     *        مُدخَل مستخدم مباشر هنا — القرار بشأن كيفية وصول الملف لهذا
     *        المسار "بأمان" هو مسؤولية طبقة الرفع في Phase لاحقة؛ هذه الدالة
     *        تقرأ فقط الملف الذي يُشار إليها به، بلا أي include/eval/استخراج).
     * @param string $type 'xlsx' أو 'csv' فقط (case-insensitive، بلا نقطة بادئة).
     * @return array{ok:bool, error:?string, message:?string, rows:array}
     */
    public static function parse_file(string $path, string $type): array
    {
        $type = strtolower(trim($type));

        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            return self::file_error(
                'unsupported_extension',
                'صيغة الملف غير مدعومة. المسموح: xlsx أو csv فقط.'
            );
        }

        if ($path === '' || !is_readable($path)) {
            return self::file_error('unreadable_file', 'تعذّر قراءة الملف المحدَّد.');
        }

        return $type === 'csv' ? self::parse_csv_file($path) : self::parse_xlsx_file($path);
    }

    // ================================================================== XLSX ==

    private static function parse_xlsx_file(string $path): array
    {
        if (!class_exists('\\Shuchkin\\SimpleXLSX')) {
            require_once __DIR__ . '/lib/simplexlsx/SimpleXLSX.php';
        }

        /**
         * Phase 3 hardening (إضافة صغيرة خارج الحرف الدقيق لنص Phase 2/3، لكن
         * ضرورية): مكتبة الطرف الثالث SimpleXLSX قد تُطلق استثناء/خطأ PHP غير
         * مُلتقَط داخلياً في حالات نادرة من ملفات ZIP/XML مشوَّهة جداً (بدل
         * إرجاع false بهدوء كما في الحالة المُختبَرة الشائعة). بدون try/catch
         * هنا، طلب AJAX كامل كان سيتحطَّم بخطأ PHP فادح خام يصل للمستخدم بدل
         * استجابة JSON نظيفة — يخالف مبدأ "Return stable business errors"
         * المُتَّبع في كل هذا المشروع. هذا التقاط دفاعي بحت حول استدعاء مكتبة
         * خارجية واحدة تحديداً؛ لا تغيير على منطق العقد/التحقق نفسه.
         */
        try {
            $xlsx = \Shuchkin\SimpleXLSX::parse($path);
        } catch (\Throwable $e) {
            return self::file_error('xlsx_parse_error', 'تعذّر تحليل ملف Excel. تأكد أن الملف غير تالف وبصيغة xlsx صحيحة.');
        }

        if (!$xlsx) {
            return self::file_error('malformed_xlsx', 'الملف تالف أو ليس بصيغة Excel صالحة.');
        }

        return self::build_from_rows_ex($xlsx->rowsEx());
    }

    /**
     * منطق العقد + سياسة خلايا الجوال (القسمان 4 و5.1 من الوثيقة) — مُصمَّم
     * عمداً كدالة عامة (public) منفصلة عن parse_xlsx_file() ليمكن اختبارها
     * مباشرة ببيانات `rowsEx()` حقيقية أو بمصفوفة اصطناعية بنفس الشكل
     * (`['type' => ..., 'value' => ...]` لكل خلية) — بلا الحاجة لتنفيذ
     * SimpleXLSX الفعلية في كل اختبار.
     *
     * @param array $rowsEx نفس الشكل الذي يُعيده SimpleXLSX::rowsEx() —
     *        مصفوفة صفوف، كل صف مصفوفة خلايا، كل خلية على الأقل
     *        `['type' => string, 'value' => mixed]`.
     */
    public static function build_from_rows_ex(array $rowsEx): array
    {
        if (count($rowsEx) === 0) {
            return self::file_error('invalid_columns', 'الملف فارغ — لا يوجد حتى صف الهيدر.');
        }

        $header_values = array_map(function ($cell) {
            return isset($cell['value']) ? (string) $cell['value'] : '';
        }, $rowsEx[0]);

        $header_check = self::validate_header_cells($header_values);
        if ($header_check !== true) {
            return self::file_error('invalid_columns', $header_check);
        }

        $rows = [];
        foreach ($rowsEx as $i => $cells) {
            if ($i === 0) {
                continue; // صف الهيدر — تحقَّقنا منه أعلاه، لا يُضاف كصف بيانات.
            }

            $name_raw = isset($cells[0]['value']) ? (string) $cells[0]['value'] : '';
            $phone_value_raw = $cells[1]['value'] ?? ''; // خام كما أعادتها SimpleXLSX: قد تكون int/float/string.
            $phone_type = isset($cells[1]['type']) ? (string) $cells[1]['type'] : '';
            $phone_display = (string) $phone_value_raw; // للتحقق من الفراغ فقط (missing_phone) — لا علاقة له بالثقة.
            $note_raw = isset($cells[2]['value']) ? (string) $cells[2]['value'] : '';

            // القسم 5.1 (تحديث سياسة الأرقام الرقمية — راجع
            // resolve_safe_numeric_phone_text() أدناه لتفاصيل الشروط الخمسة):
            // type === 's' (Shared/Inline String حقيقي) لا يزال نصاً موثوقاً
            // كما كان تماماً، بلا أي تغيير. لأي نوع آخر: قد تكون خلية رقمية
            // آمنة قابلة للتحويل بلا فقدان بيانات — يُحسَم ذلك عبر الدالة
            // المخصصة، لا عبر تخمين مباشر هنا. لا علاقة لهذا الفحص بكون
            // الخلية فارغة أم لا — ذلك يُحسَم لاحقاً داخل build_row().
            $phone_trusted_text = ($phone_type === 's')
                ? $phone_display
                : self::resolve_safe_numeric_phone_text($phone_value_raw);

            $rows[] = self::build_row($name_raw, $phone_display, $note_raw, $phone_trusted_text);
        }

        return ['ok' => true, 'error' => null, 'message' => null, 'rows' => $rows];
    }

    // =================================================================== CSV ==

    private static function parse_csv_file(string $path): array
    {
        // فحص أوّلي خفيف لملف CSV تالف/ثنائي بالخطأ (القسم 12، بند 27 —
        // "malformed csv إذا أمكن كشفه"): وجود بايت NUL مستحيل في CSV نصي
        // سليم، ومؤشر موثوق لمحتوى ثنائي/تالف بامتداد csv خاطئ.
        $probe = file_get_contents($path, false, null, 0, 8192);
        if ($probe !== false && strpos($probe, "\0") !== false) {
            return self::file_error('malformed_csv', 'الملف لا يبدو ملف CSV نصياً سليماً.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return self::file_error('unreadable_file', 'تعذّر فتح الملف للقراءة.');
        }

        $lines = [];
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $lines[] = $row;
        }
        fclose($handle);

        return self::build_from_csv_rows($lines);
    }

    /**
     * منطق العقد لمصفوفة صفوف CSV خام (نتيجة fgetcsv() لكل سطر) — نفس فلسفة
     * build_from_rows_ex(): دالة عامة منفصلة قابلة للاختبار مباشرة.
     *
     * القسم 6 من الوثيقة: CSV لا يملك Cell Types إطلاقاً — كل قيمة نص خام
     * دائماً (fgetcsv لا يُحوِّل أي شيء لـint/float من تلقاء نفسه)، فتُعامَل
     * كـ"نص موثوق" دوماً (بخلاف XLSX) طالما لم تكن فارغة.
     */
    public static function build_from_csv_rows(array $lines): array
    {
        if (count($lines) === 0) {
            return self::file_error('invalid_columns', 'الملف فارغ — لا يوجد حتى صف الهيدر.');
        }

        $header_values = array_map(function ($v) {
            return $v === null ? '' : (string) $v;
        }, $lines[0]);

        $header_check = self::validate_header_cells($header_values);
        if ($header_check !== true) {
            return self::file_error('invalid_columns', $header_check);
        }

        $rows = [];
        foreach ($lines as $i => $cols) {
            if ($i === 0) {
                continue;
            }

            $name_raw = isset($cols[0]) && $cols[0] !== null ? (string) $cols[0] : '';
            $phone_raw = isset($cols[1]) && $cols[1] !== null ? (string) $cols[1] : '';
            $note_raw = isset($cols[2]) && $cols[2] !== null ? (string) $cols[2] : '';

            // نص موثوق دائماً: CSV بلا Cell Types، القيمة النصية الخام تُمرَّر
            // مباشرة كما كانت (لا تغيير على CSV إطلاقاً — النطاق محصور بـXLSX فقط).
            $rows[] = self::build_row($name_raw, $phone_raw, $note_raw, $phone_raw);
        }

        return ['ok' => true, 'error' => null, 'message' => null, 'rows' => $rows];
    }

    // ============================================================ منطق مشترك ==

    /**
     * @return true|string true عند التطابق التام، أو رسالة الخطأ عند الرفض.
     */
    private static function validate_header_cells(array $values): bool|string
    {
        if (count($values) !== 3) {
            return 'يجب أن يحتوي الملف على 3 أعمدة بالضبط (الاسم، رقم الجوال، ملاحظة) — لا أكثر ولا أقل.';
        }

        foreach (self::EXPECTED_HEADER as $i => $expected) {
            if (trim((string) $values[$i]) !== $expected) {
                return 'ترتيب/أسماء الأعمدة لا تطابق النموذج الرسمي. يجب أن تكون بالضبط: الاسم | رقم الجوال | ملاحظة، بنفس هذا الترتيب.';
            }
        }

        return true;
    }

    /**
     * ترتيب الفحوصات ثابت ومتعمَّد (القسم 8 من الوثيقة): empty_row أولاً
     * (يسبق حتى missing_name)، ثم missing_name، ثم missing_phone، ثم رفض
     * نوع الخلية (قبل أي محاولة تطبيع)، ثم رفض المحتوى بعد التطبيع، وأخيراً valid.
     *
     * @param string $phone_display القيمة النصية الخام لغرض فحص الفراغ
     *        (missing_phone) فقط — بلا علاقة بالثقة/التحويل.
     * @param ?string $phone_trusted_text null: لا تُمرَّر أي قيمة لـ
     *        pge_norm_phone() إطلاقاً ولا تُعاد ضمن الصف — "لا تخمّن الرقم".
     *        غير null: النص الآمن (نص Excel موثوق، أو رقمي اجتاز الشروط
     *        الخمسة عبر resolve_safe_numeric_phone_text()، أو عمود CSV خام).
     */
    private static function build_row(string $name_raw, string $phone_display, string $note_raw, ?string $phone_trusted_text): array
    {
        $name = trim($name_raw);
        $phone_trimmed = trim($phone_display);
        $note = trim($note_raw);

        if ($name === '' && $phone_trimmed === '' && $note === '') {
            return ['name' => '', 'phone' => '', 'note' => '', 'status' => 'empty_row'];
        }

        if ($name === '') {
            return ['name' => '', 'phone' => '', 'note' => $note, 'status' => 'missing_name'];
        }

        if ($phone_trimmed === '') {
            return ['name' => $name, 'phone' => '', 'note' => $note, 'status' => 'missing_phone'];
        }

        if ($phone_trusted_text === null) {
            return ['name' => $name, 'phone' => '', 'note' => $note, 'status' => 'invalid_phone_cell_type'];
        }

        // القسم 7 من الوثيقة: pge_norm_phone() الموجودة فقط — لا Normalizer جديد.
        $normalized = function_exists('pge_norm_phone')
            ? pge_norm_phone(trim($phone_trusted_text))
            : preg_replace('/\D+/', '', trim($phone_trusted_text));

        if ($normalized === '') {
            return ['name' => $name, 'phone' => '', 'note' => $note, 'status' => 'invalid_phone'];
        }

        return ['name' => $name, 'phone' => $normalized, 'note' => $note, 'status' => 'valid'];
    }

    /**
     * ============================================================================
     * تحديث سياسة القسم 5.1 — تحويل آمن لخلية Excel رقمية إلى نص رقم جوال
     * ============================================================================
     * قبل هذا التحديث: أي خلية جوال ليست Shared String حرفياً (type !== 's')
     * كانت تُرفَض فوراً (invalid_phone_cell_type) — بما فيها أرقام صحيحة
     * تماماً أدخلها Excel كـ"رقم" (Numeric) بلا أي فقدان أرقام، مثل
     * 970599000932. هذا التحديث يستبدل الرفض المباشر بفحص أمان صريح، لا
     * تخمين: يُقبَل رقم Numeric فقط إذا اجتاز الشروط الخمسة التالية معاً؛ أي
     * مؤشر واحد على فقدان بيانات محتمل يعني رفضاً فورياً كما كان.
     *
     * الشروط الخمسة:
     *   1) ليست Scientific Notation.
     *   2) لا تحتوي كسوراً (Decimal).
     *   3) تمثّل رقماً صحيحاً فقط (PHP int حصراً — SimpleXLSX تحوّل أي رقم
     *      صحيح رياضياً إلى int داخلياً في value()؛ أي float هنا = كسر حقيقي).
     *   4) قابلة للتحويل إلى String بلا أي تغيير في القيمة (round-trip: رقم
     *      → نص → رقم يُعيد القيمة ذاتها تماماً).
     *   5) طول الرقم بعد التحويل ضمن الحدود المقبولة (MIN/MAX_SAFE_NUMERIC_
     *      PHONE_DIGITS أعلاه)، ثم يُمرَّر لـpge_norm_phone() كأي رقم آخر.
     *
     * "لا نخمّن صفراً بادئاً مفقوداً": خلية Numeric لا يمكن أصلاً أن تحتفظ
     * بصفر بادئ (599000932 وَ 0599000932 يمثّلان نفس القيمة العددية 599000932
     * في Excel) — هذه الدالة لا تحاول استعادته ولا تعتمد على الطول لإضافة أي
     * رقم؛ تقبل الرقم كما هو حرفياً أو ترفضه، ولا تُخترَع بيانات مطلقاً.
     * (0599000932 بصفره البادئ يبقى مدعوماً فقط إذا احتفظ به Excel كخلية Text
     * حرفياً — تلك تسلك مسار type === 's' الموثوق الموجود أصلاً، بلا مساس.)
     *
     * @param mixed $raw_value القيمة الخام كما أعادتها SimpleXLSX::value() —
     *        عادة int لخلية رقمية صحيحة، float لخلية بها كسر حقيقي، أو string
     *        لأي نوع آخر (نص/تاريخ/منطقي/خطأ/صيغة...).
     * @return ?string نص الرقم الآمن، أو null إذا وُجد أي مؤشر فقدان بيانات.
     */
    private static function resolve_safe_numeric_phone_text($raw_value): ?string
    {
        // شرط 3: PHP int حصراً. أي float يعني كسراً حقيقياً (شرط 2) → رفض.
        // ملاحظة: هذا يستبعد ضمنياً أيضاً أي خلية Date مُنسَّقة أعادت قيمة
        // Serial عائمة (float) بدل نص — بلا الحاجة لفحص Type خاص بها هنا.
        if (!is_int($raw_value)) {
            return null;
        }

        // NaN/Infinity مستحيلة بنيوياً لقيمة int في PHP — فحص دفاعي موثَّق فقط.
        if (!is_finite((float) $raw_value)) {
            return null;
        }

        // لا رقم هاتف حقيقي سالب؛ السماح به يعني الاعتماد على pge_norm_phone()
        // لحذف علامة "-" ضمناً بصمت — تخمين غير مقبول هنا.
        if ($raw_value < 0) {
            return null;
        }

        $as_string = (string) $raw_value;

        // شرط 1 (فحص دفاعي): (string) لعدد int في PHP لا تُنتِج أبداً E/e.
        if (stripos($as_string, 'e') !== false) {
            return null;
        }

        // شرط 2 (فحص دفاعي مكرر — مؤكَّد أصلاً عبر is_int() أعلاه): لا نقطة عشرية.
        if (strpos($as_string, '.') !== false) {
            return null;
        }

        // شرط 4: Round-trip — رقم → نص → رقم يجب أن يُعيد القيمة ذاتها تماماً.
        if ((int) $as_string !== $raw_value) {
            return null;
        }

        // شرط 5: طول ضمن الحدود المقبولة لرقم هاتف حقيقي — لا نُطيل ولا
        // نُقصّر الرقم، نرفض فقط إن كان طوله غير معقول أصلاً (يشمل هذا
        // دفاعياً أي Serial تاريخ رقمي قصير تسرَّب من الفحص أعلاه).
        $len = strlen($as_string);
        if ($len < self::MIN_SAFE_NUMERIC_PHONE_DIGITS || $len > self::MAX_SAFE_NUMERIC_PHONE_DIGITS) {
            return null;
        }

        return $as_string;
    }

    private static function file_error(string $code, string $message): array
    {
        return ['ok' => false, 'error' => $code, 'message' => $message, 'rows' => []];
    }

    // ============================================================ Phase 3 ==

    /**
     * ============================================================================
     * Phase 3 — Duplicate Detection ضمن نفس المناسبة فقط
     * ============================================================================
     * "Reuse existing duplicate protection. Do not invent a second duplicate
     * rule." — يُعاد استخدام pge_event_guests_get_map($event_id) حرفياً، وهي
     * **نفس مصدر الحقيقة** الذي يستخدمه PGE_Invitation_Repository::create()
     * داخلياً لفحص التكرار (قراءة فقط هنا، بلا أي أثر جانبي). عمداً **لا
     * يُستدعى PGE_Invitation_Bulk_Add_Service** هنا (ممنوع صراحةً في نطاق
     * Phase 3 الحالي) — يُعاد استخدام مصدر البيانات نفسه مباشرة بدل الخدمة.
     *
     * النطاق الحالي محصور بحرفية النص المطلوب: "هل الرقم موجود داخل نفس
     * المناسبة" فقط — لا فحص تكرار داخل الدفعة نفسها (نفس الملف قد يحتوي
     * رقماً مكرراً مرتين)، ذلك خارج نطاق Phase 3 الحالي عمداً.
     *
     * لا يمسّ الصفوف التي فشلت التحقّق أصلاً (status !== 'valid') — تبقى كما
     * هي؛ سبب الفشل الأصلي (اسم مفقود، جوال غير صالح، إلخ) أهم من فحص تكرار
     * لن يُطبَّق عليها أصلاً.
     *
     * @param array<int,array> $rows نتيجة parse_file()['rows'] — تُعدَّل بالمرجع.
     */
    public static function apply_duplicate_detection(int $event_id, array &$rows): void
    {
        $existing_guests_map = function_exists('pge_event_guests_get_map')
            ? pge_event_guests_get_map($event_id)
            : [];

        foreach ($rows as &$row) {
            if ($row['status'] !== 'valid') {
                continue;
            }
            if (isset($existing_guests_map[$row['phone']])) {
                $row['status'] = self::STATUS_DUPLICATE;
            }
        }
        unset($row);
    }

    /**
     * ملخص عددي لصفوف المعاينة (Phase 3) — تُجمَّع 'invalid_phone' و
     * 'invalid_phone_cell_type' تحت فئة عرض واحدة "رقم غير صالح" (نفس ما ورد
     * في مثال شاشة Preview بالمهمة)، بينما تبقى الحالة التفصيلية الكاملة
     * محفوظة في كل صف (row['status']) لعرضها في الجدول عند الحاجة.
     *
     * @return array{total:int,valid:int,duplicate:int,invalid_phone:int,missing_name:int,missing_phone:int,empty_row:int}
     */
    public static function summarize_preview(array $rows): array
    {
        $summary = [
            'total'         => count($rows),
            'valid'         => 0,
            'duplicate'     => 0,
            'invalid_phone' => 0,
            'missing_name'  => 0,
            'missing_phone' => 0,
            'empty_row'     => 0,
        ];

        foreach ($rows as $row) {
            switch ($row['status']) {
                case 'valid':
                    $summary['valid']++;
                    break;
                case self::STATUS_DUPLICATE:
                    $summary['duplicate']++;
                    break;
                case 'invalid_phone':
                case 'invalid_phone_cell_type':
                    $summary['invalid_phone']++;
                    break;
                case 'missing_name':
                    $summary['missing_name']++;
                    break;
                case 'missing_phone':
                    $summary['missing_phone']++;
                    break;
                case 'empty_row':
                    $summary['empty_row']++;
                    break;
            }
        }

        return $summary;
    }
}
