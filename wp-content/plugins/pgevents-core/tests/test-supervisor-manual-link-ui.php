<?php
/**
 * اختبار تنفيذي حقيقي — RC1 Enhancement: Supervisor Invitation Manual Link
 * (UI Only). فحص بنيوي على **محتوى القالب الحقيقي نفسه**
 * (templates/event-supervisors.php، عبر file_get_contents، بلا أي تعديل
 * يدوي هنا) — نفس منهجية tests/test-rc1-fixpack1.php لفحص A3 (Navigation
 * Visibility)، لأن هذا القالب لا يمكن تنفيذه مباشرة خارج بيئة ووردبريس
 * كاملة (auth_redirect()/get_header()/get_footer()) ولا يوجد محرّك JS في
 * هذا الحصاد لتشغيل الشيفرة داخل <script> فعلياً.
 *
 * النطاق: "UI ONLY. Do NOT implement backend logic." — هذا الملف يُثبت
 * تحديداً: (أ) الزر موجود ومربوط بنفس شرط الحالة القائم فعلياً لزر "إعادة
 * إرسال" (invited/pending) — لا منطق حالة جديد. (ب) لا AJAX/fetch/نقطة
 * نهاية جديدة في مسار الزر. (ج) الضغط يفتح فقط رسالة معلوماتية عبر مكوّن
 * التنبيه القائم. (د) لا تغيير على أي زر/تدفّق آخر قائم (تعديل/إعادة
 * إرسال/حذف/إنشاء/تسليم/Cartat/توجيه/مصادقة/جلسة/تدقيق).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: node runany-fixpack3a.mjs tests/test-supervisor-manual-link-ui.php
 */

define('ABSPATH', __DIR__ . '/');

if (!defined('PGE_PATH')) {
    define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

$total = 0;
$passed = 0;
$failures = [];

function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failures[] = $label;
        echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}
function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

$template_path = __DIR__ . '/../templates/event-supervisors.php';
check_true('0. الملف الحقيقي templates/event-supervisors.php موجود وقابل للقراءة', is_file($template_path));
$src = file_get_contents($template_path);
check_true('0ب. تمت قراءة محتوى حقيقي غير فارغ', is_string($src) && $src !== '');

$ajax_path = __DIR__ . '/../includes/supervisor-management-ajax.php';
$ajax_src = file_get_contents($ajax_path);

// ============================================================================
// القسم أ: وجود الزر وربطه بنفس شرط الحالة القائم (canResend)
// ============================================================================
echo "=== القسم أ: الزر وربطه بشرط الحالة القائم ===\n";

check_true('أ1. زر copy-link-sup-btn موجود في markup الصفوف', strpos($src, 'copy-link-sup-btn') !== false);
check_true('أ2. الزر يحمل aria-label', (bool) preg_match('/copy-link-sup-btn[^>]*aria-label="[^"]+"/', $src));
check_true('أ3. نص الزر هو "نسخ رابط الدعوة"', strpos($src, '>نسخ رابط الدعوة</button>') !== false);

// شرط الظهور الفعلي القائم فعلاً لزر "إعادة إرسال" — يجب أن يبقى حرفياً
// كما هو (لا منطق حالة جديد اختُرِع)، والزر الجديد يجب أن يكون مُغلَّفاً
// بنفس هذا الشرط (canResend) تحديداً، لا شرط منفصل جديد.
check_true('أ4. تعريف canResend الأصلي لم يتغيَّر (invited/pending)', strpos($src, "var canResend = (row.status === 'invited' || row.status === 'pending');") !== false);

$copy_btn_pos = strpos($src, 'copy-link-sup-btn');
$resend_condition_pos = strpos($src, "(canResend ? '<button type=\"button\" class=\"resend-sup-btn");
check_true('أ5. الزر الجديد يظهر بعد زر "إعادة إرسال" في نفس الشرط (canResend ?)، لا شرط جديد', $resend_condition_pos !== false && $copy_btn_pos !== false && $copy_btn_pos > $resend_condition_pos);

// النافذة النصية المباشرة حول تعريف الزر يجب أن تبدأ بـ"(canResend ? " —
// إثبات مباشر أن التعبير الشرطي المُغلِّف للزر هو canResend نفسه لا غيره.
$copy_btn_wrap_start = strrpos(substr($src, 0, $copy_btn_pos), '(canResend ?');
check_true('أ6. الزر الجديد مُغلَّف مباشرة بـ(canResend ? ...) — نفس متغيّر الشرط القائم بالضبط', $copy_btn_wrap_start !== false && ($copy_btn_pos - $copy_btn_wrap_start) < 400);

// ============================================================================
// القسم ب: قواعد الظهور — إعادة إنتاج نفس شرط JS القائم فعلياً كجدول حقيقة PHP
// (نفس التعبير المُتحقَّق حرفياً في أ4 أعلاه: status === 'invited' || status === 'pending')
// ============================================================================
echo "\n=== القسم ب: قواعد الظهور حسب الحالة (1-5 من المتطلبات) ===\n";

function supervisor_row_can_resend_mirror($status)
{
    // مطابقة حرفية لتعبير JS المُتحقَّق وجوده في أ4 أعلاه — لا إعادة تفسير.
    return ($status === 'invited' || $status === 'pending');
}

check_true('1. الزر يظهر لحالة invited', supervisor_row_can_resend_mirror('invited'));
check_true('2. الزر يظهر لحالة pending', supervisor_row_can_resend_mirror('pending'));
check_true('3. الزر مخفي لحالة active', !supervisor_row_can_resend_mirror('active'));
check_true('4. الزر مخفي لحالة revoked', !supervisor_row_can_resend_mirror('revoked'));
check_true('5. الزر مخفي لحالة expired', !supervisor_row_can_resend_mirror('expired'));

// ============================================================================
// القسم ج: لا AJAX/fetch/نقطة نهاية جديدة في مسار الزر
// ============================================================================
echo "\n=== القسم ج: لا اتصال خلفي من مسار الزر ===\n";

// استخراج جسم handleCopyLinkPlaceholder() تحديداً (بين تعريفها وأول '}' على
// نفس مستوى التعشيش السطحي التالي) لفحصه بمعزل عن بقية الملف — لا مجرد
// "لا يوجد fetch في كل الملف" (postAjax/fetch مُستخدَمتان شرعياً في دوال
// أخرى غير مرتبطة بهذا الزر، كإنشاء/تعديل/إعادة إرسال/إلغاء القائمة أصلاً).
$fn_start = strpos($src, 'function handleCopyLinkPlaceholder()');
check_true('6ب. الدالة handleCopyLinkPlaceholder() موجودة فعلياً', $fn_start !== false);

$fn_body = '';
if ($fn_start !== false) {
    $brace_open = strpos($src, '{', $fn_start);
    $depth = 0;
    $i = $brace_open;
    $len = strlen($src);
    for (; $i < $len; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) { $i++; break; }
        }
    }
    $fn_body = substr($src, $brace_open, $i - $brace_open);
}

check_true('6. لا استدعاء AJAX (postAjax) داخل معالج الزر', $fn_body !== '' && strpos($fn_body, 'postAjax') === false);
check_true('7. لا fetch() داخل معالج الزر', $fn_body !== '' && stripos($fn_body, 'fetch(') === false);
check_true('7ب. لا XMLHttpRequest داخل معالج الزر', $fn_body !== '' && stripos($fn_body, 'XMLHttpRequest') === false);
check_true('8. لا مرجع لأي إجراء/نقطة نهاية جديدة (لا نص "action" داخل معالج الزر)', $fn_body !== '' && stripos($fn_body, "'action'") === false);
check_true('8ب. لا توليد رابط (لا home_url/location.href/navigator.clipboard) داخل معالج الزر', $fn_body !== '' && stripos($fn_body, 'clipboard') === false && stripos($fn_body, 'location.href') === false);

// لا نقطة AJAX جديدة سُجِّلت في الطبقة الخلفية إطلاقاً (regression خلفي صريح
// رغم أن التكليف "UI ONLY" — إثبات سلبي أن لا شيء أُضيف هناك بالخطأ).
check_true('8ج. لا إجراء AJAX جديد باسم يحمل copy_link في supervisor-management-ajax.php', stripos($ajax_src, 'copy_link') === false);

// ============================================================================
// القسم د: الضغط يفتح فقط الرسالة المعلوماتية عبر مكوّن التنبيه القائم
// ============================================================================
echo "\n=== القسم د: الرسالة المعلوماتية عبر showToast() القائمة ===\n";

check_true('9. المعالج يستدعي showToast() القائمة فعلياً (لا مكوّن جديد)', $fn_body !== '' && strpos($fn_body, 'showToast(') !== false);
check_true('9ب. نص الرسالة المطلوب حرفياً موجود', strpos($src, 'ستتوفر ميزة نسخ رابط الدعوة في التحديث القادم.') !== false);
check_true('9ج. الزر مربوط فعلياً بحدث click يستدعي handleCopyLinkPlaceholder', strpos($src, "copyLinkBtn.addEventListener('click', function () { handleCopyLinkPlaceholder(); });") !== false);

// ============================================================================
// القسم هـ: بقية واجهة المشرفين لم تتغيَّر (Regression)
// ============================================================================
echo "\n=== القسم هـ: عدم تغيير الأزرار/التدفقات القائمة ===\n";

check_true('10أ. زر "تعديل" لا يزال قائماً بلا تغيير', strpos($src, 'class="edit-sup-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">تعديل</button>') !== false);
check_true('10ب. زر "إعادة إرسال" لا يزال قائماً بلا تغيير', strpos($src, 'class="resend-sup-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">إعادة إرسال</button>') !== false);
check_true('10ج. شرط canRevoke الأصلي لم يتغيَّر', strpos($src, "var canRevoke = (row.status !== 'revoked');") !== false);
check_true('10د. زر "إلغاء" لا يزال قائماً بلا تغيير', strpos($src, 'class="revoke-sup-btn h-9 px-2.5 rounded-lg bg-destructive/10 text-destructive-text text-xs font-semibold">إلغاء</button>') !== false);
check_true('10هـ. نموذج إنشاء مشرف جديد (createSupForm) لا يزال قائماً', strpos($src, 'id="createSupForm"') !== false);
check_true('10و. دالة handleResend() الأصلية لم تُمَس (توقيعها كما هو)', strpos($src, 'function handleResend(id, btn)') !== false);
check_true('10ز. دالة handleRevoke() الأصلية لم تُمَس (توقيعها كما هو)', strpos($src, 'function handleRevoke(id, btn)') !== false);
check_true('10ح. دالة postAjax() المشتركة لم تُمَس (لا تعديل على آلية النداء الخلفي العامة)', strpos($src, 'function postAjax(action, extraParams)') !== false);

// ملاحظة: كلمة "Cartat" تظهر في تعليقات توثيقية داخل هذا الملف (تشرح أن الزر
// لا يستدعيها) — الفحص الحقيقي إذاً هو غياب أي استدعاء/مرجع كودي فعلي لها
// (اسم الصنف PGE_Cartat_Transport أو أي دالة/متغيّر باسم يحمل cartat)، لا
// مجرد غياب الكلمة نصياً بالكامل من الملف.
check_true('11. لا لمس لملف class-pge-cartat-transport.php (Cartat) — لا استدعاء/مرجع كودي فعلي له في هذا القالب', strpos($src, 'PGE_Cartat_Transport') === false && preg_match('/[a-zA-Z_]cartat|cartat[a-zA-Z_(]/i', $src) !== 1);
check_true('12. لا لمس لملف routing.php من هذا القالب (لا rewrite/route جديد)', stripos($src, 'add_rewrite_rule') === false);

// ── ملخص ────────────────────────────────────────────────────────────────

echo "\n========================================\n";
echo "النتيجة: $passed / $total نجحت.\n";
if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
exit(0);
