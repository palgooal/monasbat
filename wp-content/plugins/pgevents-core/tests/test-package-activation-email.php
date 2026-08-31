<?php
/**
 * E2E-02 FIX PASS 5A — Post-Purchase Activation Email + Durable Per-Order
 * Replay Deduplication.
 *
 * تصنيف الأدلة:
 *
 * القسم 1 = اختبار وحدة حقيقي (Real Unit Test) على الصنف الحقيقي نفسه —
 * PGE_Package_Activation_Email من class-pge-package-activation-email.php —
 * يُحمَّل ويُنفَّذ فعلياً هنا (لا نسخة موازية/محاكاة منطقية له)، ضمن Stubs
 * دنيا لبيئة ووردبريس (get_userdata/wp_mail/get_user_meta/update_user_meta
 * عبر مخزن usermeta حقيقي في الذاكرة، PGE_Catalog، $wpdb عبر Stub يحاكي
 * GET_LOCK/RELEASE_LOCK فعلياً). send() تُستدعى مباشرة (public method، لا
 * حاجة لـReflection هنا).
 *
 * القسم 2 = فحص نصي/حدودي (Source/Boundary Scan) على class-salla-handler.php
 * الحقيقي — يثبت موضع نقطة الاستدعاء الوحيدة (بعد نجاح activate_catalog_tier()
 * فعلياً، داخل مسار activate فقط، لا deactivate إطلاقاً) دون تشغيل مسار
 * Webhook الكامل (يتطلب بيئة ووردبريس/REST كاملة غير متاحة هنا).
 *
 * لا اختبار تكامل HTTP كامل لووردبريس أو Webhook حقيقي من Salla في هذا
 * الملف — هذا هو الدليل الآلي المتاح فقط.
 *
 * التشغيل:
 *   php tests/test-package-activation-email.php
 */

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

function check_contains($label, $haystack, $needle)
{
    check_true($label, is_string($haystack) && strpos($haystack, $needle) !== false);
}

function check_not_contains($label, $haystack, $needle)
{
    check_true($label, is_string($haystack) && strpos($haystack, $needle) === false);
}

// ═══════════════════════════════════════════════════════════════════════
// Stubs دنيا لبيئة ووردبريس — دوال حقيقية بسيطة، لا محاكاة منطقية لكود
// الإنتاج نفسه (كود الإنتاج يُحمَّل وينفَّذ فعلياً أدناه).
// ═══════════════════════════════════════════════════════════════════════

define('ABSPATH', '/tmp/');

function sanitize_text_field($t) { return trim((string) $t); }
function is_email($e) { return is_string($e) && strpos($e, '@') !== false; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function home_url($path = '') { return 'https://hilwah.net' . $path; }
function get_bloginfo($key) { return 'حلوة'; }
function wp_specialchars_decode($t, $flags = 0) { return $t; }
function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, $decimals); }

// ── مخزن usermeta حقيقي في الذاكرة (لا Mock ثابت — قراءة/كتابة فعلية) ──
$GLOBALS['user_meta_store'] = [];
$GLOBALS['update_user_meta_should_fail'] = false;

function get_user_meta($user_id, $key, $single = false)
{
    global $user_meta_store;
    if (!isset($user_meta_store[$user_id][$key])) {
        return $single ? '' : [];
    }
    return $single ? $user_meta_store[$user_id][$key] : [$user_meta_store[$user_id][$key]];
}

function update_user_meta($user_id, $key, $value)
{
    global $user_meta_store, $update_user_meta_should_fail;
    if ($update_user_meta_should_fail) {
        return false;
    }
    // نفس سلوك ووردبريس الحقيقي: القيمة الجديدة المطابقة تماماً للقيمة
    // المخزَّنة أصلاً تُعيد false (ليس فشلاً) — يُختبَر أن persist_marker()
    // في الكود الحقيقي يتعامل مع هذا بتحقّق فعلي لا افتراض فشل.
    if (isset($user_meta_store[$user_id][$key]) && $user_meta_store[$user_id][$key] === $value) {
        return false;
    }
    $user_meta_store[$user_id][$key] = $value;
    return true;
}

// ── مستخدمو الاختبار ──
$GLOBALS['users_store'] = [
    501 => (object) ['ID' => 501, 'display_name' => 'أحمد العتيبي', 'user_login' => 'ahmad501', 'user_email' => 'ahmad@example.com'],
    502 => (object) ['ID' => 502, 'display_name' => 'سارة القحطاني', 'user_login' => 'sara502', 'user_email' => 'sara@example.com'],
    503 => (object) ['ID' => 503, 'display_name' => '', 'user_login' => 'user503', 'user_email' => 'user503@example.com'],
    504 => (object) ['ID' => 504, 'display_name' => 'مستخدم مستقل', 'user_login' => 'user504', 'user_email' => 'user504@example.com'],
    505 => (object) ['ID' => 505, 'display_name' => 'اختبار التزامن', 'user_login' => 'user505', 'user_email' => 'user505@example.com'],
];

function get_userdata($user_id)
{
    global $users_store;
    return $users_store[$user_id] ?? false;
}

// ── wp_mail قابل للتحكم (نجاح/فشل مُتحكَّم به لكل حالة اختبار) — يسجّل كل
// استدعاء فعلياً ليُفحَص المحتوى لاحقاً ──
$GLOBALS['wp_mail_calls'] = [];
$GLOBALS['wp_mail_should_succeed'] = true;
$GLOBALS['wp_mail_callback'] = null;

function wp_mail($to, $subject, $message, $headers = [])
{
    global $wp_mail_calls, $wp_mail_should_succeed, $wp_mail_callback;
    $wp_mail_calls[] = ['to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers];
    if (is_callable($wp_mail_callback)) {
        $callback = $wp_mail_callback;
        $wp_mail_callback = null;
        $callback();
    }
    return $wp_mail_should_succeed;
}

// ── PGE_Catalog stub — بيانات ثابتة يتحكم بها الاختبار ──
class PGE_Catalog
{
    public static $plans_by_id = [];
    public static $tiers_by_id = [];

    public static function get_plan($plan_id) { return self::$plans_by_id[$plan_id] ?? null; }
    public static function get_tier($tier_id) { return self::$tiers_by_id[$tier_id] ?? null; }
}

PGE_Catalog::$plans_by_id = [
    10 => ['id' => 10, 'plan_key' => 'halwa_classic', 'name' => 'حلوة كلاسيك', 'status' => 'active'],
];
PGE_Catalog::$tiers_by_id = [
    20 => [
        'id' => 20, 'plan_id' => 10, 'name' => '100 مدعو', 'status' => 'active',
        'guest_limit' => 100, 'event_quota_mode' => 'limited', 'event_quota_limit' => 1,
        'wa_messages_limit' => 50,
    ],
    21 => [
        // مستوى بلا حدود مدعوين محددة ولا رصيد رسائل — لاختبار الحذف الآمن
        // لسطور غير ذات معنى بدل عرض قيم مُخترَعة.
        'id' => 21, 'plan_id' => 10, 'name' => 'غير محدود', 'status' => 'active',
        'guest_limit' => null, 'event_quota_mode' => 'unlimited', 'event_quota_limit' => 1,
        'wa_messages_limit' => null,
    ],
];

// ── $wpdb Stub — يحاكي GET_LOCK/RELEASE_LOCK فعلياً (لا اتصال MySQL حقيقي
// متاح في بيئة CLI هذه)؛ prepare() تستبدل %s/%d فعلياً كي يبقى get_var()
// قادراً على استخراج اسم القفل من نص الاستعلام الناتج، تماماً كما يفعل
// $wpdb->prepare() الحقيقي. ──
class WPDB_Lock_Stub
{
    public $locks_held = [];
    public $get_lock_calls = 0;

    public function prepare($query, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%s|%d/', function ($m) use (&$i, $args) {
            $val = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $val : "'" . addslashes((string) $val) . "'";
        }, $query);
    }

    public function get_var($query)
    {
        if (preg_match("/GET_LOCK\('([^']*)'/", $query, $m)) {
            $this->get_lock_calls++;
            $name = $m[1];
            if (isset($this->locks_held[$name])) {
                return 0;
            }
            $this->locks_held[$name] = true;
            return 1;
        }
        return null;
    }

    public function query($query)
    {
        if (preg_match("/RELEASE_LOCK\('([^']*)'/", $query, $m)) {
            unset($this->locks_held[$m[1]]);
            return 1;
        }
        return false;
    }
}

$GLOBALS['wpdb'] = new WPDB_Lock_Stub();

// ═══════════════════════════════════════════════════════════════════════
// تحميل الصنف الحقيقي فعلياً
// ═══════════════════════════════════════════════════════════════════════

$class_file = __DIR__ . '/../includes/class-pge-package-activation-email.php';
$class_src = file_exists($class_file) ? (string) file_get_contents($class_file) : '';
check_true('ملف class-pge-package-activation-email.php موجود وقابل للقراءة', $class_src !== '');

require $class_file;
check_true('الصنف الحقيقي PGE_Package_Activation_Email مُحمَّل فعلياً', class_exists('PGE_Package_Activation_Email'));

function reset_wp_mail_log()
{
    $GLOBALS['wp_mail_calls'] = [];
    $GLOBALS['wp_mail_callback'] = null;
}

function marker_key($order_id)
{
    return PGE_Package_Activation_Email::MARKER_META_KEY_PREFIX . hash('sha256', $order_id);
}

// ═══════════════════════════════════════════════════════════════════════
// A-F. علامات دائمة مستقلة لثلاثة طلبات، بما فيها إعادة طلب تاريخي قديم
// ═══════════════════════════════════════════════════════════════════════

reset_wp_mail_log();
$GLOBALS['wp_mail_should_succeed'] = true;

$result_a = PGE_Package_Activation_Email::send(501, 10, 20, 'ORDER-1001');
check_true('A: تفعيل جديد → send() تُعيد true', $result_a === true);
check('A: تُحاوَل الإرسال مرة واحدة', count($GLOBALS['wp_mail_calls']), 1);
check('A: علامة ORDER-1001 المستقلة تُكتب بعد نجاح الإرسال', get_user_meta(501, marker_key('ORDER-1001'), true), '1');

// ═══════════════════════════════════════════════════════════════════════
// B. إعادة إرسال نفس Webhook (نفس user/plan/tier/order) → بلا بريد مكرَّر
// ═══════════════════════════════════════════════════════════════════════

$result_b = PGE_Package_Activation_Email::send(501, 10, 20, 'ORDER-1001');
check_true('B: تكرار نفس الطلب → send() تُعيد true (تخطٍّ آمن، ليس فشلاً)', $result_b === true);
check('B: لا محاولة إرسال إضافية (العدّاد يبقى 1)', count($GLOBALS['wp_mail_calls']), 1);
check('B: علامة ORDER-1001 تبقى دائمة', get_user_meta(501, marker_key('ORDER-1001'), true), '1');

// ═══════════════════════════════════════════════════════════════════════
// C. طلب جديد فعلياً (نفس user/plan/tier، order_id مختلف) → بريد جديد
// ═══════════════════════════════════════════════════════════════════════

$result_c = PGE_Package_Activation_Email::send(501, 10, 20, 'ORDER-1002');
check_true('C: طلب جديد (order_id مختلف) → send() تُعيد true', $result_c === true);
check('C: مُحاوَلة إرسال جديدة فعلياً (العدّاد يصبح 2)', count($GLOBALS['wp_mail_calls']), 2);
check('C: علامة ORDER-1002 المستقلة تُكتب', get_user_meta(501, marker_key('ORDER-1002'), true), '1');

$result_d = PGE_Package_Activation_Email::send(501, 10, 20, 'ORDER-1001');
check_true('D: إعادة ORDER-1001 القديمة بعد ORDER-1002 → تخطٍّ ناجح', $result_d === true);
check('D: لا بريد إضافي عند إعادة الطلب التاريخي القديم', count($GLOBALS['wp_mail_calls']), 2);

$result_e = PGE_Package_Activation_Email::send(501, 10, 20, 'ORDER-1003');
check_true('E: ORDER-1003 الجديدة → send() تُعيد true', $result_e === true);
check('E: ORDER-1003 الجديدة تُرسل بريداً', count($GLOBALS['wp_mail_calls']), 3);

foreach (['ORDER-1001', 'ORDER-1002', 'ORDER-1003'] as $durable_order_id) {
    check("F: علامة مستقلة ودائمة للطلب $durable_order_id", get_user_meta(501, marker_key($durable_order_id), true), '1');
    check_not_contains("F: مفتاح $durable_order_id لا يكشف معرّف الطلب الخام", marker_key($durable_order_id), $durable_order_id);
}

// G. محاولة متزامنة لنفس الطلب أثناء احتفاظ المحاولة الأولى بالقفل.
reset_wp_mail_log();
$GLOBALS['wp_mail_callback'] = function () {
    $GLOBALS['concurrent_result'] = PGE_Package_Activation_Email::send(505, 10, 20, 'ORDER-CONCURRENT');
};
$result_g = PGE_Package_Activation_Email::send(505, 10, 20, 'ORDER-CONCURRENT');
check_true('G: المحاولة المالكة للقفل تنجح', $result_g === true);
check_true('G: المحاولة المتزامنة لا تحصل على القفل', $GLOBALS['concurrent_result'] === false);
check('G: محاولتان متزامنتان لنفس الطلب تستدعيان wp_mail مرة واحدة كحد أقصى', count($GLOBALS['wp_mail_calls']), 1);
check('G: العلامة تُثبت داخل نافذة القفل', get_user_meta(505, marker_key('ORDER-CONCURRENT'), true), '1');

// ═══════════════════════════════════════════════════════════════════════
// H. نجاح wp_mail مع فشل حفظ العلامة: نتيجة صريحة وسجل صادق عن احتمال التكرار
// ═══════════════════════════════════════════════════════════════════════

reset_wp_mail_log();
$marker_failure_log = tempnam(sys_get_temp_dir(), 'pge-email-log-');
$previous_error_log = ini_set('error_log', $marker_failure_log);
$GLOBALS['update_user_meta_should_fail'] = true;
$result_h = PGE_Package_Activation_Email::send(503, 10, 20, 'ORDER-MARKER-FAIL');
$GLOBALS['update_user_meta_should_fail'] = false;
$marker_failure_log_contents = (string) file_get_contents($marker_failure_log);
if ($previous_error_log !== false) {
    ini_set('error_log', $previous_error_log);
}
unlink($marker_failure_log);
check_true('H: نجاح wp_mail مع فشل تأكيد العلامة → send() تُعيد false', $result_h === false);
check('H: البريد ربما أُرسل فعلاً قبل فشل التخزين', count($GLOBALS['wp_mail_calls']), 1);
check('H: لا توجد علامة مرسلة غير مؤكدة', get_user_meta(503, marker_key('ORDER-MARKER-FAIL'), true), '');
check_contains('H: السجل يصرّح أن فشل العلامة قد يسبب تكرار البريد', $marker_failure_log_contents, 'marker_persistence_failed_email_may_duplicate');

// ═══════════════════════════════════════════════════════════════════════
// I. فشل wp_mail() → لا علامة، والمحاولة اللاحقة مسموحة
// ═══════════════════════════════════════════════════════════════════════

reset_wp_mail_log();
$GLOBALS['wp_mail_should_succeed'] = false;

$result_i = PGE_Package_Activation_Email::send(502, 10, 20, 'ORDER-2001');
check_true('I: فشل wp_mail() → send() تُعيد false', $result_i === false);
check('I: مُحاوَلة إرسال واحدة رغم الفشل', count($GLOBALS['wp_mail_calls']), 1);
check('I: لا تُكتَب علامة عند فشل الإرسال', get_user_meta(502, marker_key('ORDER-2001'), true), '');

$GLOBALS['wp_mail_should_succeed'] = true;
$result_i_retry = PGE_Package_Activation_Email::send(502, 10, 20, 'ORDER-2001');
check_true('I: إعادة المحاولة بعد فشل wp_mail مسموحة وتنجح', $result_i_retry === true);
check('I: إعادة المحاولة تنفّذ إرسالاً ثانياً', count($GLOBALS['wp_mail_calls']), 2);
check('I: العلامة تُكتب بعد نجاح إعادة المحاولة', get_user_meta(502, marker_key('ORDER-2001'), true), '1');
$sara_mail_call = $GLOBALS['wp_mail_calls'][1] ?? null;

// J. نفس order_id لمستخدم مختلف يملك هوية إشعار مستقلة.
reset_wp_mail_log();
$result_j = PGE_Package_Activation_Email::send(504, 10, 20, 'ORDER-1001');
check_true('J: مستخدم مختلف مع ORDER-1001 نفسها → send() تُعيد true', $result_j === true);
check('J: المستخدم المختلف يستقبل بريداً مستقلاً', count($GLOBALS['wp_mail_calls']), 1);
check('J: علامة المستخدم المختلف مستقلة', get_user_meta(504, marker_key('ORDER-1001'), true), '1');

// ═══════════════════════════════════════════════════════════════════════
// G. محتوى البريد — اسم العرض الصحيح، اسم الباقة/المستوى، حد المدعوين،
// رابط لوحة التحكم
// ═══════════════════════════════════════════════════════════════════════

$last_call_a = $sara_mail_call;
check_true('G: تم العثور على استدعاء wp_mail الخاص بالمستخدم 502', $last_call_a !== null);
check('G: العنوان (Subject) مطابق للمطلوب حرفياً', $last_call_a['subject'], 'تم تفعيل باقتك في حلوة 🎉');
check_contains('G: نص البريد يحوي اسم العرض الصحيح', $last_call_a['message'], 'سارة القحطاني');
check_contains('G: نص البريد يحوي اسم الباقة الصحيح', $last_call_a['message'], 'حلوة كلاسيك');
check_contains('G: نص البريد يحوي اسم المستوى الصحيح', $last_call_a['message'], '100 مدعو');
check_contains('G: نص البريد يحوي حد المدعوين الفعلي (100)', $last_call_a['message'], 'عدد المدعوين: 100');
check_contains('G: نص البريد يحوي رابط لوحة التحكم الصحيح', $last_call_a['message'], 'https://hilwah.net/dashboard/');
check_contains('G: نص البريد يحوي زر "الذهاب إلى لوحة التحكم"', $last_call_a['message'], 'الذهاب إلى لوحة التحكم');
check_contains('G: نص البريد يحوي Content-Type: text/html; charset=UTF-8 ضمن الترويسات', implode(',', $last_call_a['headers']), 'text/html; charset=UTF-8');

// حالة "غير محدود" (Tier 21) — نص عربي مناسب بلا رقم، ولا رصيد رسائل غير
// موثوق يُعرَض (wa_messages_limit = null)
reset_wp_mail_log();
$GLOBALS['wp_mail_should_succeed'] = true;
PGE_Package_Activation_Email::send(503, 10, 21, 'ORDER-3001');
$unlimited_call = $GLOBALS['wp_mail_calls'][0] ?? null;
check_true('G (غير محدود): تم العثور على استدعاء wp_mail الخاص بالمستخدم 503', $unlimited_call !== null);
check_contains('G (غير محدود): نص البريد يحوي "عدد المناسبات: غير محدود"', $unlimited_call['message'], 'عدد المناسبات: غير محدود');
check_not_contains('G (غير محدود): لا يُعرَض "عدد المدعوين" لأن guest_limit = null', $unlimited_call['message'], 'عدد المدعوين');
check_not_contains('G (غير محدود): لا يُعرَض "رصيد الرسائل" لأن wa_messages_limit = null', $unlimited_call['message'], 'رصيد الرسائل');
check_contains('G (اسم عرض فارغ): يُستخدَم user_login كبديل آمن عندما يكون display_name فارغاً', $unlimited_call['message'], 'user503');

// ═══════════════════════════════════════════════════════════════════════
// H. أمان — لا كلمة مرور، لا رابط إعادة تعيين، لا Nonce، لا Token، لا
// حمولة Webhook خام في نص البريد إطلاقاً
// ═══════════════════════════════════════════════════════════════════════

$sensitive_markers = ['password', 'كلمة المرور', 'reset', 'nonce', '_wpnonce', 'auth_cookie', 'wp-login', 'access_token', 'client_secret', 'webhook_secret', 'x_salla_signature'];
foreach ($sensitive_markers as $marker) {
    check_not_contains("H: لا إشارة لـ\"$marker\" في نص بريد التفعيل", $last_call_a['message'], $marker);
}

// ═══════════════════════════════════════════════════════════════════════
// بيانات غير صالحة — فشل آمن، بلا استثناء، بلا محاولة إرسال
// ═══════════════════════════════════════════════════════════════════════

reset_wp_mail_log();
check_true('user_id غير صالح (صفر) → send() تُعيد false', PGE_Package_Activation_Email::send(0, 10, 20, 'ORDER-9001') === false);
check_true('order_id فارغ → send() تُعيد false', PGE_Package_Activation_Email::send(501, 10, 20, '') === false);
check_true('plan_id غير موجود في الكتالوج → send() تُعيد false', PGE_Package_Activation_Email::send(501, 999, 20, 'ORDER-9002') === false);
check_true('tier_id غير موجود في الكتالوج → send() تُعيد false', PGE_Package_Activation_Email::send(501, 10, 999, 'ORDER-9003') === false);
check('لا محاولة إرسال إطلاقاً عند بيانات غير صالحة', count($GLOBALS['wp_mail_calls']), 0);

// قفل غير مُحصَّل (يُحاكي تزامناً حقيقياً) → فشل آمن، بلا إرسال
reset_wp_mail_log();
$busy_lock_name = 'pge_pkg_activation_email_' . md5('501|ORDER-BUSY');
$GLOBALS['wpdb']->locks_held[$busy_lock_name] = true;
$result_busy = PGE_Package_Activation_Email::send(501, 10, 20, 'ORDER-BUSY');
check_true('قفل مُحتَجَز مسبقاً (تزامن) → send() تُعيد false بأمان', $result_busy === false);
check('قفل مُحتَجَز → لا محاولة إرسال إطلاقاً', count($GLOBALS['wp_mail_calls']), 0);
unset($GLOBALS['wpdb']->locks_held[$busy_lock_name]);

// ═══════════════════════════════════════════════════════════════════════
// القسم 2 — فحص نصي/حدودي على class-salla-handler.php الحقيقي: موضع
// الاستدعاء الوحيد، بعد نجاح is_wp_error($result) فقط، داخل activate حصراً
// ═══════════════════════════════════════════════════════════════════════

$handler_file = __DIR__ . '/../includes/class-salla-handler.php';
$handler_src = file_exists($handler_file) ? (string) file_get_contents($handler_file) : '';
check_true('ملف class-salla-handler.php موجود وقابل للقراءة', $handler_src !== '');

$call_needle = 'PGE_Package_Activation_Email::send(';
check('I: يوجد موضع استدعاء وحيد لـsend() في class-salla-handler.php', substr_count($handler_src, $call_needle), 1);

$call_pos = strpos($handler_src, $call_needle);
$is_wp_error_pos = strpos($handler_src, 'if (is_wp_error($result)) {');
$activate_branch_pos = strpos($handler_src, "if (\$action === 'activate') {");

check_true('D: استدعاء send() يقع بعد فحص is_wp_error($result) نصياً في نفس الدالة', $call_pos !== false && $is_wp_error_pos !== false && $call_pos > $is_wp_error_pos);
check_true('I: استدعاء send() يقع داخل فرع activate (بعد فتحه) لا قبله', $call_pos !== false && $activate_branch_pos !== false && $call_pos > $activate_branch_pos);

// موضع فرع else (deactivate) — يجب ألا يحوي الاستدعاء إطلاقاً
$deactivate_snippet = "\$result = Mon_Events_Users::deactivate_catalog_tier(";
$deactivate_pos = strpos($handler_src, $deactivate_snippet);
check_true('I: مقطع deactivate_catalog_tier موجود فعلاً للمقارنة', $deactivate_pos !== false);
check_true('I: استدعاء send() لا يقع قبل استدعاء deactivate_catalog_tier (أي ليس في مساره)', $call_pos > $deactivate_pos);

// لا استدعاء لـsend() قبل نجاح is_wp_error — يعني عدم وجوده بين استدعاء
// activate_catalog_tier وفحص is_wp_error
$activate_call_pos = strpos($handler_src, 'Mon_Events_Users::activate_catalog_tier(');
check_true('C/D: الاستدعاء الحقيقي لـactivate_catalog_tier() يسبق فحص is_wp_error، ويسبقه بدوره استدعاء send()', $activate_call_pos !== false && $activate_call_pos < $is_wp_error_pos && $is_wp_error_pos < $call_pos);

// ═══════════════════════════════════════════════════════════════════════
// القسم 2 — لا تعديل على منطق التحقق/المطابقة الحساس (فحص سلبي — يثبت أن
// هذا الإصلاح لم يلمس أياً من هذه الدوال، بلا تنفيذ فعلي لها)
// ═══════════════════════════════════════════════════════════════════════

check_contains('لم يتغيّر: is_valid_signature لا تزال موجودة كما هي', $handler_src, 'private function is_valid_signature($payload, $signature)');
check_contains('لم يتغيّر: classify_order_items لا تزال موجودة كما هي', $handler_src, 'private function classify_order_items($order_data, $order_id, $action)');
check_contains('لم يتغيّر: resolve_catalog_customer_user لا تزال موجودة كما هي', $handler_src, 'private static function resolve_catalog_customer_user($mobile, $email)');
check_contains('لم يتغيّر: normalize_mobile لا تزال موجودة كما هي', $handler_src, 'private static function normalize_mobile($mobile)');

echo "\n============================================\n";
echo "الإجمالي: $total | ناجح: $passed | فاشل: " . count($failures) . "\n";
if (count($failures) > 0) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "كل الحالات ناجحة.\n";
exit(0);
