<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Bulk Add — RC1 Fix Pack 3A ("Invitation Bulk Add Migration")
 * ============================================================================
 * الهدف: نقل قدرة "الإضافة الجماعية" الوحيدة المتبقّية خلف اللوحة القديمة
 * (Legacy Guest Panel) إلى معمارية "Host Invitation Management" المعتمَدة
 * (Phase 9)، **بلا أي منطق إنشاء دعوة جديد**: "Required flow: Host UI →
 * Authorized AJAX Controller → Invitation Service → Invitation Repository →
 * Existing Guest Storage." هذا الملف هو "الطبقة الوسيطة" (Parser/Validator)
 * فقط — الإنشاء الفعلي يمرّ حصراً عبر PGE_Invitation_Service::create()
 * (التي تُنادي بدورها PGE_Invitation_Repository::create() **بلا أي تعديل
 * عليها إطلاقاً**) — نفس مسار "إضافة دعوة" المفردة الحالي بالضبط، صفاً صفاً.
 *
 * ============================================================================
 * قرار معماري مُحدَّث (Blocker Fix): اسم عرض مُولَّد حتمياً لصفوف "هاتف فقط"
 * ============================================================================
 * القرار الأصلي (أول تنفيذ لهذا الملف) رفض صفوف "هاتف فقط" بسبب غياب الاسم،
 * استناداً لصيغة RFC الشرطية: "Guest name may be optional only if the
 * current invitation model supports unnamed invitations" — وPGE_Invitation_
 * Repository::create() فعلياً لا يدعم دعوة بلا اسم. **ثبت لاحقاً أن هذا
 * يكسر التوافق مع "هاتف فقط" الذي تنص RFC صراحة أنه صيغة إدخال معتمَدة**،
 * وهو ما كانت تدعمه اللوحة القديمة (event-guests.php bulk_add) أصلاً.
 *
 * الحل (Blocker Fix، محصور هنا فقط): حين يكون الاسم فارغاً، تُولَّد قيمة
 * اسم عرض حتمية من الهاتف نفسه — "ضيف — {آخر 4 أرقام}" — عبر
 * generate_display_name() أدناه، **قبل** استدعاء PGE_Invitation_Service::
 * create()، لا بتعديل create()/Repository نفسيهما. هذا الاستثناء **محصور
 * داخل هذا الملف فقط** — نموذج "إضافة دعوة" المفردة (event-invitations.php)
 * ونموذج الحقل `required` فيه لم يتغيّرا؛ إدخال هاتف بلا اسم عبر النموذج
 * المفرد لا يزال يُرفَض تماماً كما كان.
 *
 * الفهرسة: substr($normalized_phone, -4) تُعيد تلقائياً كامل السلسلة إذا
 * كان طولها أقل من 4 أرقام (بلا فرع شرطي إضافي) — يُحقِّق "fewer than 4
 * digits → use all available digits" مباشرة. حالة "لا أرقام صالحة إطلاقاً"
 * تبقى مرفوضة (phone_missing) قبل الوصول لتوليد الاسم أصلاً.
 *
 * الخصوصية: آخر 4 أرقام فقط تُكشَف في الاسم المُولَّد — لا الرقم الكامل.
 *
 * ============================================================================
 * التزامن (Concurrency) — فجوة معمارية موجودة مسبقاً، لم تُخترَع هنا ولم تُحَل
 * ============================================================================
 * PGE_Invitation_Repository::create() يقرأ pge_event_guests_get_map()،
 * يتحقّق من التكرار في الذاكرة، ثم يكتب pge_event_guests_save_map() — قراءة/
 * تعديل/كتابة على post meta واحد **بلا أي قفل (GET_LOCK/transient) إطلاقاً**.
 * هذا يعني أن طلبين متزامنين (سواء عبر "إضافة دعوة" المفردة أو عبر هذه
 * الإضافة الجماعية أو كلاهما معاً) يمكن نظرياً أن يتسبَّبا بـ"تحديث مفقود"
 * (Lost Update) — كلاهما يقرأ الخريطة قبل أن يكتب الآخر، فتُكتَب نسخة تُلغي
 * إضافة الآخر. **هذه فجوة قائمة أصلاً في مسار الإنشاء الحالي بالكامل، لا
 * علاقة لها ببناء هذه المرحلة** — RFC يطلب صراحةً: "If the current create
 * path is not concurrency-safe, report the gap before changing unrelated
 * architecture. Do not invent a second duplicate rule." لذا: **لم يُضَف أي
 * قفل جديد هنا** (كان سيُنشئ قاعدة تكرار ثانية تختلف عن القاعدة الحالية غير
 * الآمنة للتزامن أصلاً) — بل يُعاد استخدام PGE_Invitation_Service::create()
 * حرفياً صفاً صفاً (نفس مستوى الأمان — أو انعدامه — تماماً كمسار الإنشاء
 * المفرد الحالي). موثَّق كمخاطرة صريحة في التقرير النهائي وفي التوثيق.
 */
class PGE_Invitation_Bulk_Add_Service
{
    /** حد أقصى لعدد الأسطر (الفعلية، غير الفارغة) في الدفعة الواحدة. */
    const MAX_LINES = 500;

    /**
     * حد أقصى آمن موثَّق لحجم النص الملصوق بالكامل (بايت) — قيمة محافِظة
     * تكفي بسهولة لـ500 سطر بأسماء عربية طويلة نسبياً (~200 بايت/سطر بمعدل
     * سخي)، مع هامش أمان واسع ضد حمولات كبيرة غير معقولة.
     */
    const MAX_PAYLOAD_BYTES = 100000;

    /**
     * حد أقصى لطول الاسم — لا يوجد عمود قاعدة بيانات بطول ثابت للاسم حالياً
     * (`_pge_invited_guests` post meta، بلا حد بنيوي)؛ استُعير الحد الاصطلاحي
     * المُستخدَم فعلياً في المشروع لحقول نصية قصيرة مشابهة (راجع
     * `maxlength="190"` في نماذج اسم الباقة، pgevents-core.php) كقيمة آمنة
     * موثَّقة عمداً بدل عدم وجود أي حد إطلاقاً.
     */
    const MAX_NAME_LENGTH = 190;

    /**
     * حد أقصى لعدد أرقام الهاتف بعد التطبيع — يحمي عمود guest_phone
     * VARCHAR(32) في جدول التدقيق (pge_invitation_mgmt_audit_log، الذي يمر
     * عبره كل صف يُنشأ بنجاح عبر PGE_Invitation_Service::create()) من أي
     * اقتطاع صامت محتمل، ويرفض مدخلات غير معقولة دولياً (لا رقم هاتف حقيقي
     * يتجاوز ~15 رقماً وفق E.164).
     */
    const MAX_PHONE_DIGITS = 20;

    const STATUS_VALID = 'valid';
    const STATUS_INVALID = 'invalid';
    const STATUS_DUPLICATE_IN_BATCH = 'duplicate_in_batch';
    const STATUS_DUPLICATE_IN_EVENT = 'duplicate_in_event';

    /**
     * تطبيع الهاتف — إعادة استخدام حرفية للدالة المركزية الحالية، بلا أي
     * منطق تطبيع ثانٍ (RFC: "Reuse the existing project phone-normalization
     * function. Do NOT duplicate phone normalization.").
     */
    private static function normalize_phone($value): string
    {
        return function_exists('pge_norm_phone') ? pge_norm_phone($value) : preg_replace('/\D+/', '', trim((string) $value));
    }

    /**
     * ============================================================================
     * RC1 Fix Pack 3A (Blocker Fix) — اسم عرض مُولَّد حتمياً لصفوف "هاتف فقط"
     * ============================================================================
     * القيد التجاري المطلوب صراحةً: "Phone-only rows MUST be accepted in Bulk
     * Add. Do NOT relax the name requirement for normal single-invitation
     * creation. The exception applies ONLY inside PGE_Invitation_Bulk_Add_
     * Service." — هذه الدالة هي الاستثناء الوحيد؛ لا تُستدعى من أي مكان آخر
     * في المشروع، ولا تُغيِّر اشتراط الاسم في PGE_Invitation_Repository::
     * create()/PGE_Invitation_Service::create()/نموذج "إضافة دعوة" المفردة.
     *
     * الصيغة: "ضيف — {آخر 4 أرقام}". حتمية بالكامل (بلا عشوائية) — نفس
     * الهاتف يُنتج نفس الاسم دائماً، في المعاينة وفي التأكيد على حدٍّ سواء،
     * لأن كليهما يستدعي parse() من الصفر على نفس النص الملصوق (راجع preview()
     * وconfirm() أدناه) — لا حاجة لتمرير الاسم المُولَّد من العميل إطلاقاً،
     * ولا يُقبَل أصلاً (لا معامل لذلك في confirm()).
     *
     * الخصوصية: يُكشَف آخر 4 أرقام فقط، لا الرقم الكامل — نفس مبدأ إخفاء
     * الهوية الجزئي المُستخدَم شائعاً لعرض أرقام حسابات/بطاقات (مثال: "تنتهي
     * بـ 4567") — كافٍ لتمييز الضيوف بصرياً في القائمة بلا كشف رقم اتصال
     * كامل في واجهة قد يراها أكثر من مستخدم (مضيف + مشرفون).
     *
     * fallback: substr($s, -4) في PHP تُعيد **كامل** السلسلة إذا كان طولها
     * أقل من 4 — يُحقِّق "if fewer than 4 digits, use all available digits"
     * تلقائياً بلا فرع شرطي إضافي. الحالة "لا أرقام صالحة إطلاقاً" مُغطاة
     * أصلاً قبل استدعاء هذه الدالة (فرع phone_missing في parse() لا يستدعيها
     * إطلاقاً لو normalized_phone === '').
     */
    public static function generate_display_name(string $normalized_phone): string
    {
        $last_digits = substr($normalized_phone, -4);
        return 'ضيف — ' . $last_digits;
    }

    /**
     * تحليل سطر واحد (بعد trim وتأكيد أنه غير فارغ) إلى [name, phone_raw, error_code].
     * يدعم فقط الصيغتين المطلوبتين صراحة: فاصلة، أو تبويب — لا صيغة ثالثة
     * (لا رفع ملفات/CSV/Excel في هذه المرحلة، ممنوع صراحة في RFC).
     */
    private static function parse_line(string $line): array
    {
        $has_comma = strpos($line, ',') !== false;
        $has_tab = strpos($line, "\t") !== false;

        if ($has_comma && $has_tab) {
            return ['', '', 'malformed_ambiguous_separator'];
        }

        if ($has_comma) {
            $parts = explode(',', $line);
            if (count($parts) > 2) {
                return ['', '', 'unsupported_extra_columns'];
            }
            return [trim($parts[0]), trim($parts[1] ?? ''), null];
        }

        if ($has_tab) {
            $parts = explode("\t", $line);
            if (count($parts) > 2) {
                return ['', '', 'unsupported_extra_columns'];
            }
            return [trim($parts[0]), trim($parts[1] ?? ''), null];
        }

        // لا فاصلة ولا تبويب — السطر بالكامل رقم هاتف (Phone only).
        return ['', $line, null];
    }

    /**
     * تحليل نص ملصوق كامل إلى صفوف مُهيكَلة — بلا أي كتابة، بلا أي فحص
     * تكرار مقابل قاعدة البيانات هنا (مسؤولية check_duplicates() أدناه،
     * تحتاج event_id). "line_number" هنا هو رقم السطر الفعلي في النص
     * الأصلي (بعد تطبيع فواصل الأسطر، قبل تجاهل الأسطر الفارغة) — حتى تُطابق
     * معاينة المستخدم نصّه الملصوق الأصلي بدقة.
     *
     * @return array{ok:bool, reason?:string, rows?:array<int,array>}
     *   ok=false مع reason='payload_too_large' أو 'too_many_lines' يعني رفض
     *   الطلب بأكمله (بلا اقتطاع صامت — RFC: "Do NOT silently truncate
     *   input. Reject over-limit input clearly.").
     */
    public static function parse(string $raw_text): array
    {
        if (strlen($raw_text) > self::MAX_PAYLOAD_BYTES) {
            return ['ok' => false, 'reason' => 'payload_too_large'];
        }

        $normalized_text = str_replace(["\r\n", "\r"], "\n", $raw_text);
        $raw_lines = explode("\n", $normalized_text);

        $rows = [];
        $line_number = 0;
        foreach ($raw_lines as $raw_line) {
            $line_number++;
            $trimmed = trim($raw_line);
            if ($trimmed === '') {
                continue; // "Ignore empty lines." — لا تُحسَب صفاً إطلاقاً.
            }

            if (count($rows) >= self::MAX_LINES) {
                return ['ok' => false, 'reason' => 'too_many_lines'];
            }

            [$name, $phone_raw, $parse_error] = self::parse_line($trimmed);

            if ($parse_error !== null) {
                $rows[] = [
                    'line_number'      => $line_number,
                    'guest_name'       => '',
                    'phone'            => $trimmed,
                    'normalized_phone' => '',
                    'status'           => self::STATUS_INVALID,
                    'error'            => $parse_error,
                ];
                continue;
            }

            $normalized_phone = self::normalize_phone($phone_raw);

            $row = [
                'line_number'      => $line_number,
                'guest_name'       => $name,
                'phone'            => $phone_raw,
                'normalized_phone' => $normalized_phone,
                'status'           => self::STATUS_VALID,
                'error'            => null,
            ];

            if ($normalized_phone === '') {
                $row['status'] = self::STATUS_INVALID;
                $row['error'] = 'phone_missing';
            } elseif (strlen($normalized_phone) > self::MAX_PHONE_DIGITS) {
                $row['status'] = self::STATUS_INVALID;
                $row['error'] = 'phone_too_long';
            } else {
                // RC1 Fix Pack 3A (Blocker Fix — Phone-Only Compatibility):
                // اسم مفقود يُعوَّض باسم عرض مُولَّد حتمياً من الهاتف بدل
                // رفض الصف. الاستثناء محصور هنا فقط — راجع التعليق المعماري
                // أعلى الملف؛ PGE_Invitation_Repository::create() وPGE_
                // Invitation_Service::create() ونموذج "إضافة دعوة" المفردة
                // لم تُلمَس، ولا تزال تشترط اسماً غير فارغ كما كانت تماماً.
                if ($name === '') {
                    $name = self::generate_display_name($normalized_phone);
                    $row['guest_name'] = $name;
                }

                if (strlen($name) > self::MAX_NAME_LENGTH) {
                    $row['status'] = self::STATUS_INVALID;
                    $row['error'] = 'name_too_long';
                }
            }

            $rows[] = $row;
        }

        return ['ok' => true, 'rows' => $rows];
    }

    /**
     * فحص التكرار — داخل الدفعة نفسها، ومقابل دعوات المناسبة الحالية فعلياً.
     * "Reuse existing duplicate protection. Do not invent a second duplicate
     * rule." — يُعاد استخدام pge_event_guests_get_map($event_id) حرفياً،
     * وهي **نفس مصدر الحقيقة** الذي يستخدمه PGE_Invitation_Repository::
     * create() داخلياً لفحص التكرار (قراءة فقط هنا، بلا أي أثر جانبي).
     * لا يمسّ الصفوف التي فشلت التحقّق أصلاً (status !== valid) — تبقى كما
     * هي (سبب الفشل الأصلي أهم من فحص تكرار لن يُنفَّذ لها أصلاً).
     *
     * @param array<int,array> $rows نتيجة parse()['rows'] — تُعدَّل بالمرجع.
     */
    public static function check_duplicates(int $event_id, array &$rows): void
    {
        $existing_guests_map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
        $seen_in_batch = [];

        foreach ($rows as &$row) {
            if ($row['status'] !== self::STATUS_VALID) {
                continue;
            }

            $phone = $row['normalized_phone'];

            if (isset($existing_guests_map[$phone])) {
                $row['status'] = self::STATUS_DUPLICATE_IN_EVENT;
                $row['error'] = 'duplicate_in_event';
                continue;
            }

            if (isset($seen_in_batch[$phone])) {
                $row['status'] = self::STATUS_DUPLICATE_IN_BATCH;
                $row['error'] = 'duplicate_in_batch';
                continue;
            }

            $seen_in_batch[$phone] = true;
        }
        unset($row);
    }

    /**
     * ملخص عددي للصفوف — نفس التصنيف المطلوب في المعاينة والنتيجة النهائية.
     *
     * @return array{total:int,valid:int,invalid:int,duplicate:int}
     */
    public static function summarize(array $rows): array
    {
        $summary = ['total' => count($rows), 'valid' => 0, 'invalid' => 0, 'duplicate' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === self::STATUS_VALID) {
                $summary['valid']++;
            } elseif ($row['status'] === self::STATUS_DUPLICATE_IN_BATCH || $row['status'] === self::STATUS_DUPLICATE_IN_EVENT) {
                $summary['duplicate']++;
            } else {
                $summary['invalid']++;
            }
        }
        return $summary;
    }

    /**
     * التحضير الكامل (Parse + Duplicate Check) لخطوة "Preview" — قراءة فقط،
     * لا إنشاء دعوة واحدة هنا إطلاقاً (RFC: "No invitation is created during
     * preview.").
     *
     * @return array{ok:bool, reason?:string, rows?:array<int,array>, summary?:array}
     */
    public static function preview(int $event_id, string $raw_text): array
    {
        $parsed = self::parse($raw_text);
        if (!$parsed['ok']) {
            return $parsed;
        }

        $rows = $parsed['rows'];
        self::check_duplicates($event_id, $rows);

        return ['ok' => true, 'rows' => $rows, 'summary' => self::summarize($rows)];
    }

    /**
     * ========================================================================
     * التأكيد (Confirm) — إعادة تحقّق كاملة من الصفر، بلا أي ثقة بنتيجة
     * المعاينة القادمة من المتصفح
     * ========================================================================
     * RFC: "Never trust the preview result sent by the browser." — لذلك هذه
     * الدالة **لا تقبل مصفوفة صفوف جاهزة من العميل إطلاقاً** — فقط النص
     * الملصوق الخام (raw_text) نفسه، ثم تُعيد التحليل وفحص التكرار من جديد
     * بالكامل (parse() + check_duplicates()) — بالضبط كما لو كانت أول مرة.
     * أي صف يبدو "صالحاً" في حمولة الطلب من العميل بلا أن يكون كذلك فعلياً
     * بعد إعادة الحساب الخادمي **لا يُنشأ إطلاقاً** — بنيوياً مستحيل تجاوز
     * هذا التحقّق من طرف العميل.
     *
     * كل صف "valid" بعد إعادة التحقّق يُمرَّر فرداً إلى
     * PGE_Invitation_Service::create() الحالية **بلا أي تعديل عليها** — نفس
     * مسار "إضافة دعوة" المفردة تماماً، صفاً صفاً، بأفضل ما يمكن (Best-Effort):
     * فشل صف واحد لا يُراجع أو يُلغي الصفوف الناجحة سابقاً في نفس الدفعة
     * (RFC: "One failed row must not roll back invitations already created
     * successfully.").
     *
     * @return array{ok:bool, reason?:string, rows?:array<int,array>, summary?:array}
     *   rows هنا تحمل مفتاحاً إضافياً 'result' لكل صف:
     *   'created' | 'duplicate' | 'invalid' | 'failed'.
     *   summary هنا: {total,created,duplicate,invalid,failed}.
     */
    public static function confirm(int $event_id, string $raw_text, int $actor_user_id): array
    {
        $parsed = self::parse($raw_text);
        if (!$parsed['ok']) {
            return $parsed;
        }

        $rows = $parsed['rows'];
        self::check_duplicates($event_id, $rows);

        $result_summary = ['total' => count($rows), 'created' => 0, 'duplicate' => 0, 'invalid' => 0, 'failed' => 0];

        foreach ($rows as &$row) {
            if ($row['status'] !== self::STATUS_VALID) {
                // فشل تحقّق (بنيوي/تنسيقي/اسم مفقود) أو تكرار — لا محاولة إنشاء إطلاقاً.
                $row['result'] = ($row['status'] === self::STATUS_DUPLICATE_IN_BATCH || $row['status'] === self::STATUS_DUPLICATE_IN_EVENT)
                    ? 'duplicate'
                    : 'invalid';
                $result_summary[$row['result']]++;
                continue;
            }

            try {
                $create_result = PGE_Invitation_Service::create(
                    $event_id,
                    $row['normalized_phone'],
                    $row['guest_name'],
                    '',
                    $actor_user_id
                );
                $outcome = (string) ($create_result['result'] ?? '');

                if ($outcome === 'created') {
                    $row['result'] = 'created';
                } elseif ($outcome === 'duplicate') {
                    // حالة سباق حقيقية (Race Condition) — الرقم أُضيف بين
                    // Preview/Confirm أو بين صفَّين في نفس الطلب عبر مسار آخر
                    // متزامن. لا قاعدة تكرار جديدة هنا — هذه نتيجة create()
                    // الحقيقية نفسها.
                    $row['result'] = 'duplicate';
                    $row['error'] = 'duplicate_in_event';
                } else {
                    $row['result'] = 'failed';
                    $row['error'] = (string) ($create_result['reason'] ?? 'unknown_error');
                }
            } catch (\Throwable $e) {
                // RC1 Fix Pack 3A — Best-Effort: فشل صف واحد لا يوقف الدفعة.
                // تشخيص خادمي فقط (نفس اصطلاح error_log الحالي، A21) — بلا
                // اسم/هاتف/رسالة استثناء قد تحمل تفاصيل داخلية.
                error_log(sprintf(
                    '[invitation_bulk_add_row_failed] event_id=%d user_id=%d line=%d exception_class=%s',
                    $event_id,
                    $actor_user_id,
                    (int) $row['line_number'],
                    get_class($e)
                ));
                $row['result'] = 'failed';
                $row['error'] = 'unexpected_error';
            }

            $result_summary[$row['result']]++;
        }
        unset($row);

        return ['ok' => true, 'rows' => $rows, 'summary' => $result_summary];
    }
}
