<?php
/**
 * اختبار تنفيذي حقيقي — Supervisor Manual Invitation Link: Secure One-Time
 * Generation (الواجهة). فحص بنيوي على **محتوى القالب الحقيقي نفسه**
 * (templates/event-supervisors.php، عبر file_get_contents، بلا أي تعديل يدوي
 * هنا) — نفس منهجية tests/test-rc1-fixpack1.php (A3 Navigation Visibility)
 * وtests/test-supervisor-manual-link-ui.php الأصلية (طور UI Only)، لأن هذا
 * القالب لا يمكن تنفيذه مباشرة خارج بيئة ووردبريس كاملة، ولا يوجد محرّك JS في
 * هذا الحصاد لتشغيل الشيفرة داخل <script> فعلياً.
 *
 * يستبدل هذا الملف نسخة "UI Only" السابقة بالكامل — تلك اختبرت سلوك placeholder
 * (لا AJAX، رسالة توضيحية فقط) لم يعد قائماً بعد هذا التنفيذ الفعلي؛ الآن
 * الزر يستدعي فعلياً pge_supervisor_mgmt_manual_link_handler() عبر AJAX واحد،
 * وينسخ الرابط المُعاد إلى الحافظة (أو يعرضه في نافذة احتياطية عند تعذّر ذلك).
 *
 * النطاق: يُثبت هذا الملف تحديداً: (أ) الزر لا يزال مُغلَّفاً بنفس شرط الظهور
 * القائم (canResend = invited/pending، بلا تغيير — "keep the existing rule
 * unchanged"). (ب) طلب AJAX واحد فقط لكل ضغطة، لنفس الإجراء الجديد المسجَّل
 * فعلياً في الطبقة الخلفية. (ج) لا منطق توليد توكن مكرَّر في JavaScript (لا
 * توليد عشوائي محلي، لا بناء رابط قبول محلياً — الرابط الكامل يصل جاهزاً من
 * الخادم فقط). (د) تدفّق النسخ: navigator.clipboard أولاً، ثم نافذة احتياطية
 * عند الفشل/التعذّر — بلا استدعاء خلفي ثانٍ في مسار الفشل، وبلا أي تخزين في
 * localStorage/sessionStorage. (هـ) تعطيل/إعادة تفعيل الزر أثناء المعالجة.
 * (و) بقية الأزرار/التدفقات القائمة لم تتأثر (انحدار).
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

$service_path = __DIR__ . '/../includes/class-pge-supervisor-manual-link-service.php';
check_true('0ج. ملف الخدمة الحقيقي class-pge-supervisor-manual-link-service.php موجود', is_file($service_path));

$ajax_path = __DIR__ . '/../includes/supervisor-management-ajax.php';
$ajax_src = file_get_contents($ajax_path);
check_true('0د. الإجراء الجديد pge_supervisor_mgmt_manual_link مسجَّل فعلياً في الطبقة الخلفية', strpos($ajax_src, "add_action('wp_ajax_pge_supervisor_mgmt_manual_link'") !== false);

// ============================================================================
// القسم أ: وجود الزر وربطه بنفس شرط الحالة القائم (canResend) — بلا تغيير
// ============================================================================
echo "=== القسم أ: الزر وشرط الظهور (بلا تغيير) ===\n";

check_true('أ1. زر copy-link-sup-btn موجود في markup الصفوف', strpos($src, 'copy-link-sup-btn') !== false);
check_true('أ2. الزر يحمل aria-label', (bool) preg_match('/copy-link-sup-btn[^>]*aria-label="[^"]+"/', $src));
check_true('أ3. نص الزر هو "نسخ رابط الدعوة"', strpos($src, '>نسخ رابط الدعوة</button>') !== false);

check_true('أ4. تعريف canResend الأصلي لم يتغيَّر (invited/pending)', strpos($src, "var canResend = (row.status === 'invited' || row.status === 'pending');") !== false);

$copy_btn_pos = strpos($src, 'copy-link-sup-btn');
$resend_condition_pos = strpos($src, "(canResend ? '<button type=\"button\" class=\"resend-sup-btn");
check_true('أ5. الزر يظهر بعد زر "إعادة إرسال" في نفس الشرط (canResend ?)، لا شرط جديد', $resend_condition_pos !== false && $copy_btn_pos !== false && $copy_btn_pos > $resend_condition_pos);

$copy_btn_wrap_start = strrpos(substr($src, 0, $copy_btn_pos), '(canResend ?');
check_true('أ6. الزر مُغلَّف مباشرة بـ(canResend ? ...) — نفس متغيّر الشرط القائم بالضبط', $copy_btn_wrap_start !== false && ($copy_btn_pos - $copy_btn_wrap_start) < 400);

// ============================================================================
// القسم ب: قواعد الظهور — إعادة إنتاج نفس شرط JS القائم فعلياً كجدول حقيقة PHP
// (نفس التعبير المُتحقَّق حرفياً في أ4 أعلاه)
// ============================================================================
echo "\n=== القسم ب: قواعد الظهور حسب الحالة (بلا منطق حالة جديد) ===\n";

function supervisor_row_can_resend_mirror($status)
{
    return ($status === 'invited' || $status === 'pending');
}

check_true('ب1. الزر يظهر لحالة invited', supervisor_row_can_resend_mirror('invited'));
check_true('ب2. الزر يظهر لحالة pending', supervisor_row_can_resend_mirror('pending'));
check_true('ب3. الزر مخفي لحالة active', !supervisor_row_can_resend_mirror('active'));
check_true('ب4. الزر مخفي لحالة revoked', !supervisor_row_can_resend_mirror('revoked'));
check_true('ب5. الزر مخفي لحالة expired', !supervisor_row_can_resend_mirror('expired'));

// ============================================================================
// القسم ج: تدفّق handleManualLink() — طلب AJAX واحد فقط، لا توليد توكن محلي
// ============================================================================
echo "\n=== القسم ج: معالج handleManualLink() ===\n";

$fn_start = strpos($src, 'function handleManualLink(id, btn)');
check_true('ج1. الدالة handleManualLink(id, btn) موجودة فعلياً', $fn_start !== false);

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
check_true('ج1ب. تم استخراج جسم الدالة فعلياً (غير فارغ)', $fn_body !== '');

// ج2: طلب AJAX واحد فقط بالضبط لنفس الإجراء الجديد المسجَّل خلفياً.
check('ج2. استدعاء postAjax واحد بالضبط داخل handleManualLink', substr_count($fn_body, 'postAjax('), 1);
check_true('ج2ب. الإجراء المُستدعى هو pge_supervisor_mgmt_manual_link تحديداً', strpos($fn_body, "postAjax('pge_supervisor_mgmt_manual_link'") !== false);
check_true('ج2ج. المعامل المُرسَل هو assignment_id فقط (لا حقول إضافية غير ضرورية)', (bool) preg_match("/postAjax\('pge_supervisor_mgmt_manual_link',\s*\{\s*assignment_id:\s*id\s*\}\s*\)/", $fn_body));

// ج3: تعطيل الزر قبل الطلب + إعادة تفعيله بعد وصول الاستجابة (كلا مسارَي النجاح/الفشل).
check_true('ج3. الزر يُعطَّل مبكراً (btn.disabled = true)', strpos($fn_body, 'btn.disabled = true') !== false);
check('ج3ب. إعادة تفعيل الزر تحدث مرتين (نجاح AJAX + فشل الاتصال)', substr_count($fn_body, 'btn.disabled = false'), 2);
check_true('ج3ج. حارس عدم التكرار أثناء طلب قائم (if (btn.disabled) return)', strpos($fn_body, 'if (btn.disabled) return;') !== false);

// ج4: لا توليد توكن/رابط محلي — الرابط الكامل invitation_url يصل جاهزاً من
// الخادم فقط، بلا home_url/crypto/Math.random/بناء مسار /supervisor/accept/
// يدوياً داخل هذه الدالة.
check_true('ج4. لا استخدام Math.random داخل handleManualLink', stripos($fn_body, 'Math.random') === false);
check_true('ج4ب. لا استخدام crypto.getRandomValues داخل handleManualLink', stripos($fn_body, 'crypto.') === false);
check_true('ج4ج. لا بناء مسار /supervisor/accept/ يدوياً داخل handleManualLink (الرابط جاهز من الخادم)', strpos($fn_body, '/supervisor/accept/') === false);
check_true('ج4د. الرابط يُقرَأ من استجابة الخادم (json.data.invitation_url أو ما يعادلها)', strpos($fn_body, 'invitation_url') !== false);

// ============================================================================
// القسم د: النسخ إلى الحافظة — النجاح، الفشل، وعدم توفر navigator.clipboard
// ============================================================================
echo "\n=== القسم د: النسخ إلى الحافظة + النافذة الاحتياطية ===\n";

check_true('د1. استخدام navigator.clipboard.writeText داخل handleManualLink', strpos($fn_body, 'navigator.clipboard.writeText') !== false);
check_true('د2. رسالة النجاح الحرفية "تم نسخ رابط الدعوة." موجودة', strpos($fn_body, 'تم نسخ رابط الدعوة.') !== false);
check_true('د3. فشل النسخ (catch) يفتح النافذة الاحتياطية (openManualLinkFallback) لا استدعاءً خلفياً جديداً', (bool) preg_match('/\.catch\(function\s*\(\)\s*\{[^}]*openManualLinkFallback\(url\)/s', $fn_body));
check_true('د4. تعذّر navigator.clipboard إطلاقاً (else) يفتح نفس النافذة الاحتياطية مباشرة', (bool) preg_match('/\}\s*else\s*\{[^}]*openManualLinkFallback\(url\)/s', $fn_body));
check_true('د5. لا استدعاء postAjax/fetch إضافي داخل مساري النسخ الاحتياطي (catch/else) — بحث عن استدعاء postAjax ثانٍ', substr_count($fn_body, 'postAjax(') === 1);

// النافذة الاحتياطية نفسها: عناصر DOM + دوال الفتح/الإغلاق/النسخ اليدوي.
check_true('د6. عنصر النافذة الاحتياطية manualLinkModal موجود في markup القالب', strpos($src, 'id="manualLinkModal"') !== false);
check_true('د7. حقل عرض الرابط manualLinkInput موجود (قابل للتحديد، readonly)', (bool) preg_match('/id="manualLinkInput"[^>]*readonly/', $src));
check_true('د8. زر نسخ يدوي ثانوي manualLinkCopyBtn موجود', strpos($src, 'id="manualLinkCopyBtn"') !== false);
check_true('د9. زر إغلاق manualLinkCloseBtn موجود', strpos($src, 'id="manualLinkCloseBtn"') !== false);
check_true('د10. دالة openManualLinkFallback(url) موجودة وتملأ الحقل بالرابط الجاهز (لا استدعاء خلفي)', (bool) preg_match('/function openManualLinkFallback\(url\)\s*\{[^}]*manualLinkInput\.value = url;/s', $src));
check_true('د11. دالة closeManualLinkFallback() تُفرِغ الحقل عند الإغلاق (لا يبقى الرابط في الـDOM)', (bool) preg_match('/function closeManualLinkFallback\(\)\s*\{[^}]*manualLinkInput\.value = \'\';/s', $src));
check_true('د12. زر النسخ اليدوي يستخدم document.execCommand(\'copy\') محلياً (لا AJAX)', strpos($src, "document.execCommand('copy')") !== false);

// ============================================================================
// القسم هـ: لا تخزين متصفح إطلاقاً (localStorage/sessionStorage) في كامل الملف
// ============================================================================
echo "\n=== القسم هـ: لا تخزين متصفح ===\n";

// فحص استخدام فعلي (استدعاء دالة/خاصية عبر نقطة) لا مجرد ذكر الكلمة نصياً —
// الكلمتان تظهران في تعليقات JS توثيقية تشرح صراحة أنهما غير مُستخدَمتين (نفس
// الدرس المستفاد سابقاً من فحص "Cartat" في نسخة UI Only من هذا الملف).
check_true('هـ1. لا استخدام فعلي لـlocalStorage.* في كامل القالب', preg_match('/localStorage\s*\./', $src) !== 1);
check_true('هـ2. لا استخدام فعلي لـsessionStorage.* في كامل القالب', preg_match('/sessionStorage\s*\./', $src) !== 1);

// ============================================================================
// القسم و: لا استدعاء Cartat من مسار هذه الميزة إطلاقاً — لا PGE_Cartat_Transport،
// لا مرجع كودي فعلي لكلمة cartat (فقط ذكرها في تعليقات توثيقية شارحة أنها غير مُستخدَمة)
// ============================================================================
echo "\n=== القسم و: عدم استدعاء Cartat ===\n";

check_true('و1. لا لمس لملف class-pge-cartat-transport.php من هذا القالب — لا استدعاء/مرجع كودي فعلي له', strpos($src, 'PGE_Cartat_Transport') === false && preg_match('/[a-zA-Z_]cartat|cartat[a-zA-Z_(]/i', $src) !== 1);
// نفس منهجية و1 أعلاه: الخدمة توثِّق صراحةً في تعليقاتها أنها لا تستدعي
// Cartat (لتوضيح القرار المعماري)، فذكر الاسم في تعليق ليس استخداماً فعلياً —
// الفحص الحقيقي هو غياب أي استدعاء كودي فعلي (new/::) للصنف.
$service_src = (string) file_get_contents($service_path);
check_true('و2. الخدمة الجديدة لا تستدعي PGE_Cartat_Transport فعلياً (لا new/::، فقط ذكر توثيقي)', preg_match('/new\s+PGE_Cartat_Transport|PGE_Cartat_Transport::/', $service_src) !== 1);
check_true('و3. لا لمس لملف routing.php من هذا القالب (لا rewrite/route جديد)', stripos($src, 'add_rewrite_rule') === false);

// ============================================================================
// القسم ز: بقية واجهة المشرفين لم تتغيَّر (Regression)
// ============================================================================
echo "\n=== القسم ز: عدم تغيير الأزرار/التدفقات القائمة ===\n";

check_true('ز1. زر "تعديل" لا يزال قائماً بلا تغيير', strpos($src, 'class="edit-sup-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">تعديل</button>') !== false);
check_true('ز2. زر "إعادة إرسال" لا يزال قائماً بلا تغيير', strpos($src, 'class="resend-sup-btn h-9 px-2.5 rounded-lg border border-border text-xs font-semibold">إعادة إرسال</button>') !== false);
check_true('ز3. شرط canRevoke الأصلي لم يتغيَّر', strpos($src, "var canRevoke = (row.status !== 'revoked');") !== false);
check_true('ز4. زر "إلغاء" لا يزال قائماً بلا تغيير', strpos($src, 'class="revoke-sup-btn h-9 px-2.5 rounded-lg bg-destructive/10 text-destructive-text text-xs font-semibold">إلغاء</button>') !== false);
check_true('ز5. نموذج إنشاء مشرف جديد (createSupForm) لا يزال قائماً', strpos($src, 'id="createSupForm"') !== false);
check_true('ز6. دالة handleResend() الأصلية لم تُمَس (توقيعها كما هو)', strpos($src, 'function handleResend(id, btn)') !== false);
check_true('ز7. دالة handleRevoke() الأصلية لم تُمَس (توقيعها كما هو)', strpos($src, 'function handleRevoke(id, btn)') !== false);
check_true('ز8. دالة postAjax() المشتركة لم تُمَس (لا تعديل على آلية النداء الخلفي العامة)', strpos($src, 'function postAjax(action, extraParams)') !== false);
check_true('ز9. الإجراءات الخلفية القائمة (list/create/edit/resend/revoke) لا تزال مسجَّلة كما هي', strpos($ajax_src, "add_action('wp_ajax_pge_supervisor_mgmt_list'") !== false
    && strpos($ajax_src, "add_action('wp_ajax_pge_supervisor_mgmt_create'") !== false
    && strpos($ajax_src, "add_action('wp_ajax_pge_supervisor_mgmt_edit'") !== false
    && strpos($ajax_src, "add_action('wp_ajax_pge_supervisor_mgmt_resend'") !== false
    && strpos($ajax_src, "add_action('wp_ajax_pge_supervisor_mgmt_revoke'") !== false);
check_true('ز10. لا وجود لبقايا الدالة placeholder السابقة (handleCopyLinkPlaceholder) في القالب', strpos($src, 'handleCopyLinkPlaceholder') === false);

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
