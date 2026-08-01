<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Minimal XLSX Writer — Phase 9C ("Invitation Export")
 * ============================================================================
 * "Excel — Generate true XLSX. Do NOT generate HTML disguised as Excel. Use
 * the project's existing spreadsheet dependency if present. Otherwise add
 * the minimum supported implementation."
 *
 * لا اعتمادية Composer قائمة في هذا المشروع (لا composer.json، لا vendor/،
 * لا PhpSpreadsheet — تحقّقتُ بحثاً مباشراً قبل كتابة هذا الملف)، لذلك هذا هو
 * "الحد الأدنى من التنفيذ المدعوم" المطلوب صراحة في الـRFC — كاتب XLSX حقيقي
 * (حاوية ZIP + XML OOXML صالحة تماماً، لا HTML بامتداد .xlsx) بلا أي
 * اعتمادية خارجية إطلاقاً (لا ext-zip، لا ext-zlib) — يبني حاوية ZIP يدوياً
 * بطريقة STORE (بلا ضغط، عبر crc32()/pack() الأساسيتين في PHP فقط)، فيعمل
 * حتى في بيئات لا تملك أي امتداد ضغط مفعَّل. الصيغة الناتجة صالحة ومتوافقة
 * تماماً مع Excel/LibreOffice/Google Sheets (خلايا نصية inlineStr، لا حاجة
 * لجدول sharedStrings.xml منفصل).
 *
 * قراءة/توليد بحت — لا كتابة على أي جدول/post meta، لا علاقة له بأي طبقة
 * مُجمَّدة في هذه المرحلة (Invitation CRUD/Search/Filtering/Pagination/QR/
 * Attendance/Supervisor/Audit) — يستهلك فقط مصفوفة صفوف نصية جاهزة يُمرِّرها
 * الاستدعاء (PGE_Invitation_Export)، لا معرفة له بمصدرها إطلاقاً.
 */
class PGE_Xlsx_Writer
{
    /**
     * يبني ملف .xlsx حقيقي كامل من مصفوفة صفوف ثنائية الأبعاد (الصف الأول
     * يُعامَل كرأس أعمدة من قِبَل المستهلك — لا معالجة خاصة هنا، كل صف يُكتب
     * كما هو نصاً خاماً في خلاياه).
     *
     * @param array<int,array<int,string>> $rows
     * @return string المحتوى الثنائي الكامل لملف .xlsx جاهز للإرسال مباشرة.
     */
    public static function build(array $rows): string
    {
        $files = [
            '[Content_Types].xml'        => self::content_types_xml(),
            '_rels/.rels'                => self::root_rels_xml(),
            'xl/workbook.xml'             => self::workbook_xml(),
            'xl/_rels/workbook.xml.rels' => self::workbook_rels_xml(),
            'xl/styles.xml'               => self::styles_xml(),
            'xl/worksheets/sheet1.xml'   => self::build_sheet_xml($rows),
        ];

        return self::build_zip($files);
    }

    private static function xml_escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function col_letter(int $index): string
    {
        $letter = '';
        $index++; // تحويل لصيغة 1-based المطلوبة لخوارزمية base-26 لأحرف الأعمدة.
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - $mod, 26);
        }
        return $letter;
    }

    private static function build_sheet_xml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetData>';

        $r = 0;
        foreach ($rows as $row) {
            $r++;
            $xml .= '<row r="' . $r . '">';
            $c = 0;
            foreach ($row as $cell) {
                $ref = self::col_letter($c) . $r;
                // t="inlineStr": خلية نصية مضمَّنة — لا حاجة لجدول sharedStrings.xml
                // منفصل (تبسيط متعمَّد، صالح تماماً حسب معيار OOXML).
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml_escape($cell) . '</t></is></c>';
                $c++;
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private static function content_types_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function root_rels_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Invitations" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbook_rels_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function styles_xml(): string
    {
        // الحد الأدنى الصالح المطلوب — نمط خط/تعبئة/حد افتراضي واحد فقط،
        // كافٍ لفتح الملف بلا أي طلب "إصلاح" من Excel.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }

    private static function u16(int $v): string
    {
        return pack('v', $v);
    }

    private static function u32(int $v): string
    {
        return pack('V', $v);
    }

    /**
     * تاريخ/وقت بصيغة DOS (المطلوبة داخل رؤوس ZIP) من الوقت الحالي — بيانات
     * تعريفية بحتة (metadata) لا علاقة لها بأي منطق عمل، آمنة للتقريب.
     *
     * @return array{0:int,1:int} [dosTime, dosDate]
     */
    private static function dos_datetime(): array
    {
        $t = function_exists('current_time') ? (int) current_time('timestamp') : time();
        $dosTime = (((int) date('H', $t)) << 11) | (((int) date('i', $t)) << 5) | intdiv((int) date('s', $t), 2);
        $year = max(0, ((int) date('Y', $t)) - 1980);
        $dosDate = ($year << 9) | (((int) date('n', $t)) << 5) | ((int) date('j', $t));
        return [$dosTime, $dosDate];
    }

    /**
     * حاوية ZIP يدوية بطريقة STORE (بلا ضغط) — بلا أي اعتمادية على ext-zip
     * أو ext-zlib. صالحة تماماً حسب معيار ZIP (PKWARE APPNOTE) ومقروءة من
     * كل قارئ XLSX قياسي (الضغط اختياري في الصيغة، لا إلزامي).
     *
     * @param array<string,string> $files اسم الملف داخل الأرشيف => المحتوى الخام.
     */
    private static function build_zip(array $files): string
    {
        $localParts = '';
        $centralParts = '';
        $offset = 0;
        $count = 0;
        [$dosTime, $dosDate] = self::dos_datetime();

        foreach ($files as $name => $content) {
            $count++;
            $crc = crc32($content);
            $size = strlen($content);
            $nameLen = strlen($name);

            $local = self::u32(0x04034b50)   // signature
                . self::u16(20)               // version needed
                . self::u16(0)                // flags
                . self::u16(0)                // method: STORE
                . self::u16($dosTime)
                . self::u16($dosDate)
                . self::u32($crc)
                . self::u32($size)            // compressed size = uncompressed (STORE)
                . self::u32($size)
                . self::u16($nameLen)
                . self::u16(0)                // extra length
                . $name
                . $content;

            $central = self::u32(0x02014b50) // signature
                . self::u16(20)               // version made by
                . self::u16(20)               // version needed
                . self::u16(0)                // flags
                . self::u16(0)                // method: STORE
                . self::u16($dosTime)
                . self::u16($dosDate)
                . self::u32($crc)
                . self::u32($size)
                . self::u32($size)
                . self::u16($nameLen)
                . self::u16(0)                // extra length
                . self::u16(0)                // comment length
                . self::u16(0)                // disk number start
                . self::u16(0)                // internal attributes
                . self::u32(0)                // external attributes
                . self::u32($offset)          // relative offset of local header
                . $name;

            $localParts .= $local;
            $centralParts .= $central;
            $offset += strlen($local);
        }

        $centralOffset = $offset;
        $centralSize = strlen($centralParts);

        $end = self::u32(0x06054b50)   // signature
            . self::u16(0)              // disk number
            . self::u16(0)              // disk with central dir
            . self::u16($count)         // entries on this disk
            . self::u16($count)         // total entries
            . self::u32($centralSize)
            . self::u32($centralOffset)
            . self::u16(0);             // comment length

        return $localParts . $centralParts . $end;
    }
}
