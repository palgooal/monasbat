<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Export — Phase 9C ("Invitation Export")
 * ============================================================================
 * "Export only. No import. No notifications. No delivery. No QR changes."
 *
 * طبقة **جديدة بالكامل**، قراءة فقط تماماً — لا تُعدِّل أي ملف من قائمة
 * التجميد المعمارية الصريحة لهذه المرحلة (Invitation Identity/QR Architecture/
 * QR Rotation/Guest Resolution/Attendance Recorder/Attendance Statistics/
 * Supervisor Identity/Supervisor Authentication/Authorization/Quota System/
 * Invitation CRUD/Search/Filtering/Pagination/Delivery Request/Audit Model).
 *
 * مصدر البيانات الوحيد: PGE_Invitation_Service::get_export_rows($event_id,
 * $filters) — موجودة فعلاً منذ Phase 9 الأصلية، غير مُعدَّلة هنا بحرف واحد؛
 * تستدعي PGE_Invitation_Repository::list_invitations() **نفسها بالضبط**
 * (نفس الفلترة/البحث/الفرز الحاليَّين) مع per_page كبير بما يكفي لتغطية كل
 * النتائج المُرشَّحة دفعة واحدة (بلا ترقيم منفصل، بلا خوارزمية فرز ثانية —
 * "Do NOT introduce a second sorting algorithm").
 *
 * حقل "Supervisor (if assigned)" الإضافي الوحيد غير المتوفر أصلاً في صف
 * الدعوة القياسي: يُبنى هنا عبر استعلامَين إضافيَّين فقط بلا علاقة بعدد
 * الصفوف (لا N+1) — راجع load_supervisor_names_by_phone() أدناه: استعلام
 * واحد لقراءة (event_id, guest_phone, checked_in_by_assignment_id) دفعة
 * واحدة من wp_pge_event_rsvps (قراءة بحتة، الجدول نفسه الذي يقرأه أصلاً
 * pge_event_guests_load_rsvp_from_db() الحالي)، واستدعاء واحد لـPGE_
 * Supervisor_Assignment_Service::list_assignments_for_event() الحالية
 * (Phase 8، غير مُعدَّلة) لبناء خريطة assignment_id → supervisor_name. كلا
 * الاستدعاءَين للقراءة فقط، بلا أي تعديل على أي من الطبقتين.
 *
 * ============================================================================
 * Phase 9C Final Security Fix ("Spreadsheet Formula Injection Protection")
 * ============================================================================
 * إضافة واحدة فقط على هذا الملف: sanitize_spreadsheet_cell() (راجع تعليقها
 * أدناه) + استدعاؤها على الحقول النصية غير الموثوقة الخمسة داخل
 * build_dataset() (الهاتف مرتين، الاسم، رمز الدعوة، اسم المشرف). لا تعديل
 * على csv_line()/PGE_Xlsx_Writer (مُجمَّدان تماماً هذا الإصلاح)، لا تعديل
 * على الفلترة/الفرز/التدقيق/الترويسات/ترتيب الأعمدة — راجع docs/
 * INVITATION-EXPORT.md قسم "Spreadsheet Formula Injection Protection".
 */
class PGE_Invitation_Export
{
    /**
     * الحقول التجارية المسموح تصديرها حصراً (بيانات عمل فقط) — يُطابق تماماً
     * القائمة المُوصى بها في الـRFC. **ممنوع نهائياً** أي حقل من: حمولة QR،
     * توقيع QR، qr_version، أي سرّ داخلي، معرِّفات تدقيق، رموز أمان، بيانات
     * وصفية خاصة — لا واحد منها موجود أصلاً في هذه القائمة (لا حاجة لقائمة
     * حظر Blocklist منفصلة؛ القائمة البيضاء Allowlist وحدها كافية وأكثر أماناً).
     */
    private const HEADERS = [
        'معرّف الدعوة',
        'اسم الضيف',
        'الجوال',
        'رمز الدعوة',
        'حالة الرد',
        'حالة الحضور',
        'حالة الدعوة',
        'المشرف (إن وُجد)',
        'تاريخ الإنشاء',
        'آخر تحديث',
        'تاريخ آخر تجديد QR',
    ];

    /**
     * يبني مجموعة بيانات التصدير الكاملة (رأس + صفوف) لمناسبة واحدة، محترمة
     * الفلاتر/الفرز الحاليَّين تماماً — بلا أي منطق فلترة/فرز مستقل هنا.
     *
     * @return array{header:array<int,string>,rows:array<int,array<int,string>>,count:int}
     */
    public static function build_dataset(int $event_id, array $filters): array
    {
        $raw_rows = class_exists('PGE_Invitation_Service')
            ? PGE_Invitation_Service::get_export_rows($event_id, $filters)
            : [];

        $supervisor_by_phone = self::load_supervisor_names_by_phone($event_id);

        $rows = [];
        foreach ($raw_rows as $row) {
            // الهاتف المُطبَّع نفسه (خام، غير مُطهَّر) يبقى مفتاح البحث في
            // خريطة المشرفين أدناه — التطهير (Phase 9C Final Security Fix)
            // يُطبَّق فقط لحظة الكتابة داخل مصفوفة الصف المُصدَّرة، لا قبلها،
            // فلا يؤثر إطلاقاً على منطق البحث/الفلترة/الفرز.
            $phone = (string) ($row['phone'] ?? '');
            $name = (string) ($row['name'] ?? '');
            $code = (string) ($row['code'] ?? '');
            $supervisor_name = $supervisor_by_phone[$phone] ?? '';

            $rows[] = [
                // معرّف الدعوة — الهاتف المُطبَّع هو المفتاح الطبيعي الوحيد لهوية
                // الدعوة في هذا المشروع (راجع class-pge-invitation-repository.php)،
                // لا مُعرِّف رقمي داخلي مُنفصل. حقل نصي مُدخَل من الضيف — يمر عبر
                // sanitize_spreadsheet_cell() (Phase 9C Final Security Fix).
                self::sanitize_spreadsheet_cell($phone),
                self::sanitize_spreadsheet_cell($name),
                self::sanitize_spreadsheet_cell($phone),
                self::sanitize_spreadsheet_cell($code),
                // حالة الرد/الحضور/الدعوة: تسميات ثابتة من الكود نفسه (Enum
                // مغلَق)، ليست نصاً حرّاً مُدخَلاً من المستخدم — لا تُطهَّر عمداً.
                (string) ($row['rsvp_status_label'] ?? self::rsvp_label($row['rsvp_status'] ?? '')),
                self::attendance_label((string) ($row['attendance_status'] ?? 'no')),
                self::invitation_status_label((string) ($row['invitation_status'] ?? 'active')),
                self::sanitize_spreadsheet_cell($supervisor_name),
                // التواريخ: طوابع زمنية مُولَّدة من الخادم (current_time()) — لا تُطهَّر عمداً.
                (string) ($row['invited_at'] ?? ''),
                (string) ($row['updated_at'] ?? ''),
                (string) ($row['qr_regenerated_at'] ?? ''),
            ];
        }

        return [
            'header' => self::HEADERS,
            'rows'   => $rows,
            'count'  => count($rows),
        ];
    }

    /**
     * ============================================================================
     * Phase 9C Final Security Fix ("Spreadsheet Formula Injection Protection")
     * ============================================================================
     * نقطة التطهير المركزية الوحيدة لكل قيمة نصية غير موثوقة (مُدخَلة من
     * مستخدم) تُصدَّر لأي صيغة — "Every textual value exported to CSV and
     * XLSX must pass through this helper. Do NOT duplicate logic." تُستدعى
     * حصراً هنا داخل build_dataset() (قبل أن تدخل الصفوف csv_line()/
     * PGE_Xlsx_Writer::build() المُجمَّدتين تماماً بلا أي تعديل) — فكلا
     * الكاتبين يستهلكان نفس مصفوفة $rows المُطهَّرة مسبقاً، بلا علم بوجود هذه
     * الدالة إطلاقاً ("Do NOT change CSV layout, Excel layout... streaming").
     *
     * التهديد (Threat model): قيمة نصية تبدأ (بعد إزالة أي مسافات بادئة:
     * مسافة عادية، Tab، CR، LF) بأحد الأحرف = + - @ يُمكن أن تُفسِّرها
     * Excel/LibreOffice/Google Sheets كصيغة تُنفَّذ تلقائياً عند فتح الملف —
     * خطر حقيقي لأي حقل نصي حرّ يُدخِله مستخدم غير موثوق (اسم الضيف، الجوال
     * المُدخَل يدوياً، رمز الدعوة اليدوي، اسم المشرف، وأي حقل نصي حرّ مستقبلي).
     *
     * الحل: بادئة علامة اقتباس مفردة (') واحدة قبل القيمة الأصلية كاملة —
     * يجعل كل تطبيقات الجداول الثلاثة تُعامِل الخلية كنص خام صراحة، بلا أي
     * تنفيذ، مع بقاء المحتوى الأصلي مقروءاً بشرياً بالكامل (Excel/LibreOffice/
     * Google Sheets الثلاثة تُخفي علامة الاقتباس البادئة تلقائياً عند العرض
     * لخلية نصية). **لا تعديل إطلاقاً على القيمة المخزَّنة في قاعدة
     * البيانات** — التطهير يحدث فقط على النسخة الخارجة للتصدير، لحظة البناء.
     *
     * ما لا يُطهَّر عمداً (Safe Values — "Only sanitize untrusted text
     * values"): تسميات الحالة الثابتة والتواريخ المُولَّدة من الخادم — لا
     * تمر عبر هذه الدالة إطلاقاً في build_dataset() أعلاه (Server-generated
     * integers/Dates/Booleans/Counts/Trusted numeric IDs مُستثناة صراحة).
     */
    public static function sanitize_spreadsheet_cell($value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return $value;
        }

        // إزالة المسافات البادئة (مسافة عادية/Tab/CR/LF) **لغرض الفحص فقط** —
        // القيمة المُعادة عند الخطر تحمل النص الأصلي كاملاً (بمسافاته البادئة
        // كما هي تماماً)، مع علامة اقتباس واحدة تُضاف في البداية المطلقة، لا
        // بعد إزالة المسافات — فلا يُفقَد أي جزء من المحتوى الأصلي.
        $trimmed = ltrim($value, " \t\r\n");
        if ($trimmed === '') {
            return $value;
        }

        $first_char = $trimmed[0];
        if ($first_char === '=' || $first_char === '+' || $first_char === '-' || $first_char === '@') {
            return "'" . $value;
        }

        return $value;
    }

    private static function rsvp_label(string $status): string
    {
        switch ($status) {
            case 'yes': return 'حاضر (سيحضر)';
            case 'no':  return 'اعتذر';
            default:    return 'بانتظار الرد';
        }
    }

    private static function attendance_label(string $status): string
    {
        return $status === 'yes' ? 'حضر فعلياً' : 'لم يحضر بعد';
    }

    private static function invitation_status_label(string $status): string
    {
        return $status === 'cancelled' ? 'مُلغاة' : 'نشطة';
    }

    /**
     * خريطة هاتف → اسم المشرف الذي سجَّل حضوره فعلياً (إن وُجد) — استعلامان
     * فقط بصرف النظر عن عدد الدعوات (لا N+1):
     *   1) استعلام واحد على wp_pge_event_rsvps لقراءة checked_in_by_assignment_id
     *      لكل هاتف ضمن هذه المناسبة (قراءة بحتة، لا كتابة، لا تعديل Schema).
     *   2) استدعاء واحد لـPGE_Supervisor_Assignment_Service::list_assignments_
     *      for_event() الحالية (Phase 8، غير مُعدَّلة) لبناء خريطة
     *      assignment_id → supervisor_name.
     * دعوة بلا تسجيل حضور، أو بلا مشرف مرتبط، تُعاد بقيمة فارغة.
     */
    private static function load_supervisor_names_by_phone(int $event_id): array
    {
        if ($event_id <= 0 || !class_exists('PGE_Supervisor_Assignment_Service')) {
            return [];
        }

        global $wpdb;
        $rsvp_table = $wpdb->prefix . 'pge_event_rsvps';
        $assignment_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT guest_phone, checked_in_by_assignment_id FROM $rsvp_table WHERE event_id = %d AND checked_in_by_assignment_id IS NOT NULL",
                $event_id
            ),
            ARRAY_A
        );
        if (!is_array($assignment_rows) || count($assignment_rows) === 0) {
            return [];
        }

        $names_by_assignment_id = [];
        foreach (PGE_Supervisor_Assignment_Service::list_assignments_for_event($event_id) as $assignment) {
            $names_by_assignment_id[(int) ($assignment['id'] ?? 0)] = (string) ($assignment['supervisor_name'] ?? '');
        }

        $result = [];
        foreach ($assignment_rows as $row) {
            $phone = (string) ($row['guest_phone'] ?? '');
            $assignment_id = (int) ($row['checked_in_by_assignment_id'] ?? 0);
            if ($phone === '' || $assignment_id <= 0) {
                continue;
            }
            $result[$phone] = $names_by_assignment_id[$assignment_id] ?? '';
        }

        return $result;
    }

    /**
     * ترميز CSV مطابق لـRFC 4180 — كل خلية بين علامتَي اقتباس مزدوجتَين،
     * وأي علامة اقتباس داخل الخلية تُضاعَف (لا هروب backslash). UTF-8 BOM في
     * البداية لضمان عرض العربية صحيحاً في Excel تلقائياً بلا استيراد يدوي.
     */
    public static function csv_line(array $cells): string
    {
        $escaped = array_map(function ($cell) {
            $cell = str_replace('"', '""', (string) $cell);
            return '"' . $cell . '"';
        }, $cells);
        return implode(',', $escaped) . "\r\n";
    }

    public const CSV_BOM = "\xEF\xBB\xBF";
}
