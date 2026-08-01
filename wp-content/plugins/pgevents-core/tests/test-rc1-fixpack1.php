<?php
/**
 * اختبار تنفيذي حقيقي (لا مرآة منطقية) لـRC1 Fix Pack 1 ("Security &
 * Navigation" — S1 + A3 فقط، Entry Check-in Supervisors). الحد الأدنى
 * المطلوب صراحةً: "Navigation visibility" و"Shared export path" — لا مزيد.
 *
 * ============================================================================
 * S1 — Shared Export Path (لا تنفيذ تصدير مزدوج بعد الآن)
 * ============================================================================
 * الثيم (wp-content/themes/pgevents-pro/page-event-manage.php) لا يُنشئ ملفات
 * PHP قابلة للاستدعاء المباشر عبر هذا الحصاد (يعتمد على bootstrap ثيم/
 * Elementor كامل غير متوفّر هنا) — لذلك يُتحقَّق منه هنا بطريقتين صادقتين
 * معاً، بلا محاكاة منطقية:
 *   (أ) فحص بنيوي على **محتوى الملف الحقيقي نفسه** (file_get_contents، بلا
 *       أي تعديل يدوي) يثبت غياب كل أثر لتوليد CSV/تطهير محلي قديم (لا
 *       `new Blob`، لا `URL.createObjectURL`، لا بناء يدوي لمصفوفة CSV)،
 *       ووجود الاستدعاء الجديد لنقطة التصدير المعتمَدة بالاسم الحرفي.
 *   (ب) **تنفيذ حقيقي فعلي** لسلسلة تحميل الإضافة الحقيقية
 *       (invitation-management-ajax.php وكل ما تتطلَّبه) يُثبت أن الإجراء
 *       `pge_invitation_mgmt_export_csv` الذي يستهدفه الثيم الآن **مُسجَّل
 *       فعلياً** في نقطة التصدير المعتمَدة الوحيدة (Phase 9C) — لا استدعاء
 *       لإجراء وهمي/غير موجود.
 *
 * ============================================================================
 * A3 — Navigation Visibility
 * ============================================================================
 * فحص بنيوي على نفس ملف الثيم الحقيقي يثبت وجود الروابط الثلاثة (مشرفون/
 * دعوات/عمليات) بعناوين URL تُطابق حرفياً أنماط `add_rewrite_rule()` الحقيقية
 * المُسجَّلة فعلاً في includes/routing.php (لا رابط "ميت" يُشير لمسار غير
 * مُسجَّل).
 *
 * لا يلمس أي قاعدة بيانات حقيقية ولا أي ملف إنتاج.
 *
 * التشغيل: node /tmp/phpcheck/runany.mjs tests/test-rc1-fixpack1.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__test_registered_hooks'] = [];
function add_action($hook, ...$args) { $GLOBALS['__test_registered_hooks'][$hook] = true; }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op */ }

if (!function_exists('sanitize_key')) { function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return trim((string) $v); } }
if (!function_exists('sanitize_file_name')) { function sanitize_file_name($v) { return preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string) $v); } }
if (!function_exists('absint')) { function absint($value) { return abs((int) $value); } }
if (!function_exists('current_time')) { function current_time($type = 'mysql', $gmt = 0) { return '2026-01-01 00:00:00'; } }
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
if (!function_exists('wp_hash')) { function wp_hash($data) { return hash_hmac('sha256', (string) $data, 'test-auth-salt-fixed'); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($string, $encoding = null) { return strtolower((string) $string); } }
if (!function_exists('mb_strpos')) { function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) { return strpos((string) $haystack, (string) $needle, $offset); } }
function get_current_user_id() { return 0; }
function is_user_logged_in() { return true; }
function current_user_can($cap, $object_id = null) { return false; }
function get_post_type($event_id) { return 'pge_event'; }

if (!defined('PGE_PATH')) define('PGE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

$total = 0; $passed = 0; $failures = [];
function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) { $passed++; echo "PASS  $label\n"; }
    else { $failures[] = $label; echo "FAIL  $label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}
function check_true($label, $cond) { check($label, (bool) $cond, true); }

// ============================================================================
// الجزء (ب) لـS1 — تحميل حقيقي لسلسلة الإضافة، إثبات تسجيل الإجراء المعتمَد
// ============================================================================
echo "=== S1 (ب): تسجيل حقيقي لنقطة التصدير المعتمَدة ===\n";

require_once __DIR__ . '/../includes/event-guests.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-schema.php';
require_once __DIR__ . '/../includes/class-pge-invitation-management-audit.php';
require_once __DIR__ . '/../includes/class-pge-invitation-repository.php';
require_once __DIR__ . '/../includes/class-pge-invitation-service.php';
require_once __DIR__ . '/../includes/class-pge-xlsx-writer.php';
require_once __DIR__ . '/../includes/class-pge-invitation-export.php';
require_once __DIR__ . '/../includes/invitation-management-ajax.php';

check_true(
    'S1.1 وحدة التصدير المعتمَدة الحقيقية تُسجِّل wp_ajax_pge_invitation_mgmt_export_csv فعلياً',
    !empty($GLOBALS['__test_registered_hooks']['wp_ajax_pge_invitation_mgmt_export_csv'])
);
check_true(
    'S1.2 دالة المعالج الحقيقية pge_invitation_mgmt_export_csv_handler موجودة وقابلة للاستدعاء',
    function_exists('pge_invitation_mgmt_export_csv_handler')
);
check_true(
    'S1.3 حماية حقن الصيغ الحقيقية sanitize_spreadsheet_cell() ما زالت موجودة في PGE_Invitation_Export (نفس Phase 9C Final Security Fix، بلا تعديل)',
    method_exists('PGE_Invitation_Export', 'sanitize_spreadsheet_cell')
);

// ============================================================================
// الجزء (أ) لـS1 — فحص بنيوي على محتوى ملف الثيم الحقيقي (بلا تعديل يدوي)
// ============================================================================
echo "\n=== S1 (أ): فحص بنيوي على page-event-manage.php الحقيقي ===\n";

$theme_candidates = [
    __DIR__ . '/../../../themes/pgevents-pro/page-event-manage.php',
    dirname(dirname(dirname(__DIR__))) . '/themes/pgevents-pro/page-event-manage.php',
];
if (defined('PGE_TEST_THEME_FILE')) {
    // يوفّرها المُشغِّل (runany-fixpack1.mjs) بعد نسخ الملف الحقيقي حرفياً من
    // شجرة الثيم الفعلية إلى نظام ملفات الحصار الافتراضي — لازم لأن حصار
    // php-wasm يعزل الوصول لأي شيء خارج شجرة الإضافة المُعتادة.
    array_unshift($theme_candidates, PGE_TEST_THEME_FILE);
}
$theme_file = null;
foreach ($theme_candidates as $candidate) {
    if (file_exists($candidate)) { $theme_file = $candidate; break; }
}
check_true('S1.4 العثور على ملف الثيم page-event-manage.php', $theme_file !== null);

$theme_source = $theme_file !== null ? file_get_contents($theme_file) : '';
check_true('S1.5 الملف يُقرَأ بنجاح', $theme_source !== false && $theme_source !== '');

check_true('S1.6 لا يوجد "new Blob(" (توليد CSV محلي قديم أُزيل بالكامل)', strpos($theme_source, 'new Blob(') === false);
check_true('S1.7 لا يوجد "URL.createObjectURL" (تنزيل Blob محلي قديم أُزيل)', strpos($theme_source, 'URL.createObjectURL') === false);
check_true('S1.8 لا يوجد بناء يدوي لمصفوفة صفوف CSV (data.push) داخل معالج التصدير', strpos($theme_source, "data.push([\n            row.dataset.name") === false);
check_true('S1.9 الزر يستهدف الآن action=pge_invitation_mgmt_export_csv (نقطة التصدير المعتمَدة الوحيدة)', strpos($theme_source, "addHiddenField('action', 'pge_invitation_mgmt_export_csv')") !== false);
check_true('S1.10 التصدير يُقدَّم كنموذج POST مخفٍ (نفس نمط triggerInvitationExport في event-invitations.php)، لا fetch/blob', strpos($theme_source, "form.method = 'post'") !== false);
check_true('S1.11 يُعاد استخدام nonce/eventId الموجودَين أصلاً (cfg.nonce/cfg.eventId)، بلا قيمة جديدة مُخترَعة', strpos($theme_source, "addHiddenField('nonce', cfg.nonce") !== false && strpos($theme_source, "addHiddenField('event_id', cfg.eventId") !== false);

// ============================================================================
// A3 — روابط التنقّل (فحص بنيوي + مطابقة مع أنماط routing.php الحقيقية)
// ============================================================================
echo "\n=== A3: روابط التنقّل للوحدات الثلاث ===\n";

check_true('A3.1 رابط "مشرفو الدخول" موجود (id=navSupervisorsLink)', strpos($theme_source, 'id="navSupervisorsLink"') !== false);
check_true('A3.2 رابط "إدارة الدعوات" موجود (id=navInvitationsLink)', strpos($theme_source, 'id="navInvitationsLink"') !== false);
check_true('A3.3 رابط "لوحة العمليات" موجود (id=navOperationsLink)', strpos($theme_source, 'id="navOperationsLink"') !== false);

check_true('A3.4 متغيّر $supervisors_url يبني /event-manage/{id}/supervisors/', strpos($theme_source, "home_url('/event-manage/' . \$event_id . '/supervisors/')") !== false);
check_true('A3.5 متغيّر $invitations_url يبني /event-manage/{id}/invitations/', strpos($theme_source, "home_url('/event-manage/' . \$event_id . '/invitations/')") !== false);
check_true('A3.6 متغيّر $operations_url يبني /event-manage/{id}/operations/', strpos($theme_source, "home_url('/event-manage/' . \$event_id . '/operations/')") !== false);

// لا روابط ميتة: مطابقة الأنماط الثلاثة مع routing.php الحقيقي نفسه (نفس
// الملف غير المُعدَّل في Phase 10) — إثبات أن الروابط الجديدة تصل فعلياً
// لمسارات مُسجَّلة، لا مسارات مخترَعة.
$routing_source = file_get_contents(__DIR__ . '/../includes/routing.php');
check_true('A3.7 routing.php الحقيقي يُسجِّل فعلياً event-manage/([0-9]+)/supervisors/', strpos($routing_source, "event-manage/([0-9]+)/supervisors/?$") !== false);
check_true('A3.8 routing.php الحقيقي يُسجِّل فعلياً event-manage/([0-9]+)/invitations/', strpos($routing_source, "event-manage/([0-9]+)/invitations/?$") !== false);
check_true('A3.9 routing.php الحقيقي يُسجِّل فعلياً event-manage/([0-9]+)/operations/', strpos($routing_source, "event-manage/([0-9]+)/operations/?$") !== false);

check_true('A3.10 الروابط الثلاثة بنفس تنسيق الروابط الموجودة أصلاً (h-11 ... border-border bg-white)، لا تصميم جديد', substr_count($theme_source, 'id="navSupervisorsLink" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-border bg-white') === 1);

// ============================================================================
// A3 (Fix Pack 1.1) — إكمال التنقّل للجوال/الأجهزة اللوحية
// ============================================================================
// تحليل الحالة (Case A/B) اعتماداً على الكود الفعلي: الملف يحتوي مكوّناً
// مستقلاً للتنقّل على الجوال — bnav (شريط سفلي ثابت 4 أزرار) + actionsPanel
// (لوحة سفلية تُفتح بزر "أكثر")، مفصولين بالكامل بصرياً وبنيوياً عن شريط
// "الإجراءات السريعة" الخاص بسطح المكتب (hidden lg:block). هذا Case A.
// التحقّق هنا بنيوي على نفس المصدر الحقيقي المقروء أعلاه.
echo "\n=== A3 (Fix Pack 1.1): تحليل الحالة + روابط التنقّل على الجوال/الأجهزة اللوحية ===\n";

check_true('A3.11 Case A مؤكَّد: bnav (الشريط السفلي الثابت) موجود ومنفصل بصرياً (lg:hidden)', strpos($theme_source, 'class="bnav lg:hidden"') !== false);
check_true('A3.12 Case A مؤكَّد: actionsPanel (اللوحة السفلية) موجودة وتتحول لسايدبار دائم على سطح المكتب فقط (min-width:1024px)', strpos($theme_source, "@media (min-width:1024px)") !== false && strpos($theme_source, "#actionsPanel { position:static") !== false);
check_true('A3.13 Case A مؤكَّد: نقطة كسر واحدة موحَّدة 1023/1024px لكل من bnav/actionsPanel (لا نقطة "تابلت" منفصلة — الجوال والتابلت يعاملان معاملة واحدة في هذا المكوّن)', strpos($theme_source, '@media (max-width:1023px)') !== false);

check_true('A3.14 رابط جوال "مشرفو الدخول" موجود (id=navSupervisorsLinkMobile) داخل actionsPanel', strpos($theme_source, 'id="navSupervisorsLinkMobile"') !== false);
check_true('A3.15 رابط جوال "إدارة الدعوات" موجود (id=navInvitationsLinkMobile) داخل actionsPanel', strpos($theme_source, 'id="navInvitationsLinkMobile"') !== false);
check_true('A3.16 رابط جوال "لوحة العمليات" موجود (id=navOperationsLinkMobile) داخل actionsPanel', strpos($theme_source, 'id="navOperationsLinkMobile"') !== false);

// لا تصميم جديد: نفس الأنماط الحرفية (class) المستخدمة في نسخة سطح المكتب،
// فقط flex بدل inline-flex (نفس الأثر البصري ضمن عمود actionsPanel).
check_true('A3.17 روابط الجوال بنفس نمط الروابط الحرفي (h-11 rounded-xl border-border bg-white text-sm font-bold)، لا تصميم جديد', substr_count($theme_source, 'id="navSupervisorsLinkMobile" class="flex h-11 items-center justify-center gap-2 rounded-xl border border-border bg-white') === 1);

// نفس متغيّرات الرابط الحقيقية المُعاد استخدامها (لا مسار مُخترَع جديد) —
// الدليل الفعلي أن $supervisors_url/$invitations_url/$operations_url (المُثبَت
// بناؤها الصحيح في A3.4-A3.6 أعلاه) هي نفسها المُستخدَمة في روابط الجوال.
check_true('A3.18 رابط الجوال supervisors يستخدم نفس $supervisors_url المُتحقَّق من بنائها أعلاه', strpos($theme_source, "esc_url(\$supervisors_url) ?>\" id=\"navSupervisorsLinkMobile\"") !== false);
check_true('A3.19 رابط الجوال invitations يستخدم نفس $invitations_url المُتحقَّق من بنائها أعلاه', strpos($theme_source, "esc_url(\$invitations_url) ?>\" id=\"navInvitationsLinkMobile\"") !== false);
check_true('A3.20 رابط الجوال operations يستخدم نفس $operations_url المُتحقَّق من بنائها أعلاه', strpos($theme_source, "esc_url(\$operations_url) ?>\" id=\"navOperationsLinkMobile\"") !== false);

// لا ازدواج ظهور: روابط الجوال مُغلَّفة بـlg:hidden (تختفي ابتداءً من سطح
// المكتب lg+)، وروابط سطح المكتب مُغلَّفة بحاوية hidden lg:block (تظهر
// حصراً من lg+) — الاثنان متبادلا الاستبعاد بصرياً عند كل نقطة كسر، فلا
// يظهر الرابط نفسه مرتين لنفس المستخدم في نفس اللحظة.
$mobile_block_start = strpos($theme_source, 'id="navSupervisorsLinkMobile"');
$mobile_wrapper_before = $mobile_block_start !== false ? substr($theme_source, max(0, $mobile_block_start - 400), 400) : '';
check_true('A3.21 حاوية روابط الجوال مُغلَّفة بـ"lg:hidden" (تختفي على سطح المكتب — لا ازدواج مع الشريط العلوي)', strpos($mobile_wrapper_before, 'class="lg:hidden space-y-2"') !== false);

// حاوية شريط "الإجراءات السريعة" الرئيسية (hidden lg:block) تفتح مرة واحدة
// فقط في الملف وقبل ظهور روابط سطح المكتب (لا حاجة لمسافة ملاصقة — يكفي أن
// تكون الحاوية هي الأقرب افتتاحاً قبل الروابط ولم تُغلَق بعد؛ تحقَّقنا سلباً
// أن العنصر يظهر مرة واحدة فقط في الملف بأكمله، ما ينفي وجود حاوية أخرى
// منافسة تغلِّف نفس الروابط بقواعد ظهور مختلفة).
$desktop_container_pos = strpos($theme_source, 'class="hidden lg:block max-w-7xl mx-auto');
$desktop_block_start   = strpos($theme_source, 'id="navSupervisorsLink"');
check_true('A3.22 حاوية روابط سطح المكتب مُغلَّفة بـ"hidden lg:block" (تختفي على الجوال/التابلت — لا ازدواج مع اللوحة السفلية)', $desktop_container_pos !== false && $desktop_block_start !== false && $desktop_container_pos < $desktop_block_start);
check_true('A3.22ب حاوية "hidden lg:block max-w-7xl mx-auto" فريدة (مرة واحدة فقط) — لا حاوية منافسة', substr_count($theme_source, 'class="hidden lg:block max-w-7xl mx-auto') === 1);

// لا تكرار: كل معرِّف (id) فريد حرفياً مرة واحدة فقط في كامل الملف (لا نسخ
// لصق غير مقصود يُنتج عنصرَي DOM بنفس id).
foreach (['navSupervisorsLink', 'navInvitationsLink', 'navOperationsLink', 'navSupervisorsLinkMobile', 'navInvitationsLinkMobile', 'navOperationsLinkMobile'] as $nav_id) {
    check('A3.23 لا تكرار: id="' . $nav_id . '" يظهر مرة واحدة فقط في الملف', substr_count($theme_source, 'id="' . $nav_id . '"'), 1);
}

// ── ملخّص ────────────────────────────────────────────────────────────────
echo "\n============================================================\n";
echo "الإجمالي: $total | نجح: $passed | فشل: " . count($failures) . "\n";
if ($failures) {
    echo "الاختبارات الفاشلة:\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
exit(0);
