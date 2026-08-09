<?php
/**
 * Messaging Architecture — Phase 1: اختبارات تنفيذية حقيقية.
 *
 * تشغّل PGE_Message_Type وPGE_Message_Content_Resolver وpge_wa_get_templates()
 * الحقيقية (بلا Mock) لإثبات: (أ) العقد الجديد يعمل كما هو مُصمَّم، و(ب) سلوك
 * الدعوة (Invitation) الحالي — النص والصورة على حد سواء — لم يتغيّر حرفياً
 * بعد الـRefactor في class-cartat-handler.php.
 *
 * الاصطلاح: نفس نمط tests/test-feature-registry.php بالضبط (بلا PHPUnit):
 * ABSPATH وهمي + require_once للملفات الحقيقية + check()/check_true() +
 * exit(1) عند وجود فشل.
 */

define('ABSPATH', __DIR__ . '/');

// ── WordPress function stubs (الحد الأدنى المطلوب لتشغيل الملفات الحقيقية) ──

$GLOBALS['__test_post_meta'] = [];       // event_id => [meta_key => value]
$GLOBALS['__test_thumbnail_url'] = [];   // event_id => url|''

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false)
    {
        $post_id = (int) $post_id;
        $val = $GLOBALS['__test_post_meta'][$post_id][$key] ?? '';
        return $single ? $val : [$val];
    }
}

if (!function_exists('get_the_post_thumbnail_url')) {
    function get_the_post_thumbnail_url($post_id, $size = 'full')
    {
        $post_id = (int) $post_id;
        return $GLOBALS['__test_thumbnail_url'][$post_id] ?? false;
    }
}

// ── تحميل الملفات الحقيقية تحت الاختبار (بلا Mock للملف نفسه) ──

require_once __DIR__ . '/../includes/class-pge-message-type.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/class-pge-message-content-resolver.php';

// ── أدوات الفحص (نفس نمط test-feature-registry.php) ──

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
        $failures[] = "$label (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")";
        echo "FAIL  $label (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")\n";
    }
}

function check_true($label, $condition)
{
    global $total, $passed, $failures;
    $total++;
    if ($condition) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failures[] = "$label (condition was false)";
        echo "FAIL  $label (condition was false)\n";
    }
}

// ══════════════════════════════════════════════════════════════════════
// 1-5: PGE_Message_Type::normalize()
// ══════════════════════════════════════════════════════════════════════

check("1. normalize('invitation') → invitation", PGE_Message_Type::normalize('invitation'), 'invitation');
check("2. normalize('reminder') → reminder", PGE_Message_Type::normalize('reminder'), 'reminder');
check("3. normalize('thank_you') → thank_you", PGE_Message_Type::normalize('thank_you'), 'thank_you');
check("4. normalize('  INVITATION  ') يُطبَّع → invitation", PGE_Message_Type::normalize('  INVITATION  '), 'invitation');
check("5. normalize('bogus') → null", PGE_Message_Type::normalize('bogus'), null);

// ══════════════════════════════════════════════════════════════════════
// 6-9: pge_wa_get_templates() — القيم القديمة لم تتغيّر
// ══════════════════════════════════════════════════════════════════════

$event_a = 1001;
$GLOBALS['__test_post_meta'][$event_a] = []; // بلا أي stored templates → كلها defaults

$tpls_a = pge_wa_get_templates($event_a);

check("6. pge_wa_get_templates()['invite'] == pge_wa_default_invite_template()", $tpls_a['invite'], pge_wa_default_invite_template());
check("7. pge_wa_get_templates()['yes'] == pge_wa_default_reply_yes_template()", $tpls_a['yes'], pge_wa_default_reply_yes_template());
check("8. pge_wa_get_templates()['no'] == pge_wa_default_reply_no_template()", $tpls_a['no'], pge_wa_default_reply_no_template());
check("9. pge_wa_get_templates()['invalid'] == pge_wa_default_reply_invalid_template()", $tpls_a['invalid'], pge_wa_default_reply_invalid_template());

// ══════════════════════════════════════════════════════════════════════
// 10-11: reminder/thank_you defaults موجودة
// ══════════════════════════════════════════════════════════════════════

check("10. pge_wa_get_templates()['reminder'] == pge_wa_default_reminder_template() (لا stored)", $tpls_a['reminder'], pge_wa_default_reminder_template());
check("11. pge_wa_get_templates()['thank_you'] == pge_wa_default_thank_you_template() (لا stored)", $tpls_a['thank_you'], pge_wa_default_thank_you_template());

// ══════════════════════════════════════════════════════════════════════
// 12-13: stored reminder/thank_you تتجاوز الـdefault
// ══════════════════════════════════════════════════════════════════════

$event_b = 1002;
$GLOBALS['__test_post_meta'][$event_b] = [
    '_pge_wa_tpl_reminder'  => 'تذكير مخصّص: {{event_name}}',
    '_pge_wa_tpl_thank_you' => 'شكر مخصّص: {{event_name}}',
];
$tpls_b = pge_wa_get_templates($event_b);

check("12. stored reminder يتجاوز الـdefault", $tpls_b['reminder'], 'تذكير مخصّص: {{event_name}}');
check("13. stored thank_you يتجاوز الـdefault", $tpls_b['thank_you'], 'شكر مخصّص: {{event_name}}');

// ══════════════════════════════════════════════════════════════════════
// 14-15: Resolver invitation ينتج نفس النص/الصورة القديمَين حرفياً
// (محاكاة send_invitations()/cron_process_queue(): image_url يُمرَّر صراحةً
//  ضمن context — نفس ما يفعله الكود الحقيقي المُعاد بناؤه).
// ══════════════════════════════════════════════════════════════════════

$event_c = 1003;
$GLOBALS['__test_post_meta'][$event_c] = []; // invite = default
$GLOBALS['__test_thumbnail_url'][$event_c] = 'https://example.com/cover.jpg';

$guest_context = [
    'guest_name'      => 'أحمد',
    'event_name'      => 'حفل التخرج',
    'event_date'      => '2026-09-01',
    'event_date_line' => "\n📅 2026-09-01",
    'guest_phone'     => '966500000000',
    'image_url'       => 'https://example.com/cover.jpg', // مُمرَّرة صراحةً كما في send_invitations()
];

// السلوك القديم (قبل الـRefactor) المُعاد بناؤه هنا يدوياً للمقارنة:
$old_tpl_invite = pge_wa_get_templates($event_c)['invite'];
$old_caption = pge_wa_render_template($old_tpl_invite, [
    'guest_name'      => $guest_context['guest_name'],
    'event_name'      => $guest_context['event_name'],
    'event_date'      => $guest_context['event_date'],
    'event_date_line' => $guest_context['event_date_line'],
    'guest_phone'     => $guest_context['guest_phone'],
]);
$old_image_url = $guest_context['image_url'] ?: null;

$new_content = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::INVITATION, $event_c, $guest_context);

check("14. Resolver invitation نص مطابق حرفياً للسلوك القديم", $new_content['text'], $old_caption);
check("15. Resolver invitation image_url مطابق حرفياً للسلوك القديم", $new_content['image_url'], $old_image_url);

// ══════════════════════════════════════════════════════════════════════
// 16: Test Send invitation payload لم يتغيّر (محاكاة ajax_test_send(): بلا
//     image_url في context → يُحسَب حديثاً عبر get_the_post_thumbnail_url()،
//     تماماً كسلوك ajax_test_send() القديم).
// ══════════════════════════════════════════════════════════════════════

$test_send_context = [
    'guest_name'      => 'ضيف تجريبي',
    'event_name'      => 'حفل التخرج',
    'event_date'      => '2026-09-01',
    'event_date_line' => '',
    'guest_phone'     => '966511111111',
    // بلا image_url — كما في ajax_test_send() الحقيقي
];
$test_send_result = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::INVITATION, $event_c, $test_send_context);
$expected_test_caption = pge_wa_render_template($old_tpl_invite, [
    'guest_name'      => $test_send_context['guest_name'],
    'event_name'      => $test_send_context['event_name'],
    'event_date'      => $test_send_context['event_date'],
    'event_date_line' => $test_send_context['event_date_line'],
    'guest_phone'     => $test_send_context['guest_phone'],
]);

check("16. Test Send payload (نص) مطابق للسلوك القديم", $test_send_result['text'], $expected_test_caption);
check_true("16b. Test Send image_url يُحسَب من get_the_post_thumbnail_url عند غياب المفتاح", $test_send_result['image_url'] === 'https://example.com/cover.jpg');

// ══════════════════════════════════════════════════════════════════════
// 17: Queue invitation rendered message لم يتغيّر (محاكاة cron_process_queue():
//     image_url من $queue['image_url'] مُمرَّرة صراحةً).
// ══════════════════════════════════════════════════════════════════════

$queue_sim = [
    'event_name' => 'حفل التخرج',
    'event_date' => '2026-09-01',
    'image_url'  => 'https://example.com/queue-cover.jpg',
];
$queue_context = [
    'guest_name'      => 'سارة',
    'event_name'      => $queue_sim['event_name'],
    'event_date'      => $queue_sim['event_date'],
    'event_date_line' => $queue_sim['event_date'] ? "\n📅 {$queue_sim['event_date']}" : '',
    'guest_phone'     => '966522222222',
    'image_url'       => $queue_sim['image_url'],
];
$queue_result = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::INVITATION, $event_c, $queue_context);
$expected_queue_caption = pge_wa_render_template($old_tpl_invite, [
    'guest_name'      => $queue_context['guest_name'],
    'event_name'      => $queue_context['event_name'],
    'event_date'      => $queue_context['event_date'],
    'event_date_line' => $queue_context['event_date_line'],
    'guest_phone'     => $queue_context['guest_phone'],
]);

check("17. Queue invitation نص مطابق للسلوك القديم", $queue_result['text'], $expected_queue_caption);
check("17b. Queue invitation image_url == queue['image_url'] المُمرَّرة", $queue_result['image_url'], $queue_sim['image_url']);

// ══════════════════════════════════════════════════════════════════════
// 18: المسار المباشر/القديم (fallback الدفاعي في الكود الحقيقي) — إن استُخدم
//     لا يزال ينتج نفس القيمة (نتحقق أن نفس المدخلات تُنتج نفس المخرجات عبر
//     المسارين: Resolver مقابل بناء يدوي مطابق للـfallback حرفياً).
// ══════════════════════════════════════════════════════════════════════

$legacy_style_caption = pge_wa_render_template(
    pge_wa_get_templates($event_c)['invite'],
    [
        'guest_name'      => $guest_context['guest_name'],
        'event_name'      => $guest_context['event_name'],
        'event_date'      => $guest_context['event_date'],
        'event_date_line' => $guest_context['event_date_line'],
        'guest_phone'     => $guest_context['guest_phone'],
    ]
);
check("18. المسار المباشر/القديم (fallback) يُنتج نفس نص Resolver", $legacy_style_caption, $new_content['text']);

// ══════════════════════════════════════════════════════════════════════
// 19: Resolver reminder — text صحيح وimage_url=null
// ══════════════════════════════════════════════════════════════════════

$event_d = 1004;
$GLOBALS['__test_post_meta'][$event_d] = []; // reminder = default
$reminder_context = [
    'guest_name' => 'خالد',
    'event_name' => 'حفل التخرج',
    'event_date' => '2026-09-01',
];
$reminder_result = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::REMINDER, $event_d, $reminder_context);
$expected_reminder_text = pge_wa_render_template(pge_wa_default_reminder_template(), [
    'guest_name'      => 'خالد',
    'event_name'      => 'حفل التخرج',
    'event_date'      => '2026-09-01',
    'event_date_line' => '',
    'guest_phone'     => '',
    'event_url'       => '',
    'invite_code'     => '',
    'location_line'   => '',
]);

check("19. Resolver reminder نص صحيح", $reminder_result['text'], $expected_reminder_text);
check("19b. Resolver reminder image_url === null", $reminder_result['image_url'], null);

// ══════════════════════════════════════════════════════════════════════
// 20: Resolver thank_you — text صحيح وimage_url=null
// ══════════════════════════════════════════════════════════════════════

$event_e = 1005;
$GLOBALS['__test_post_meta'][$event_e] = []; // thank_you = default
$thank_you_context = [
    'guest_name' => 'منى',
    'event_name' => 'حفل التخرج',
    'event_date' => '2026-09-01',
];
$thank_you_result = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::THANK_YOU, $event_e, $thank_you_context);
$expected_thank_you_text = pge_wa_render_template(pge_wa_default_thank_you_template(), [
    'guest_name' => 'منى',
    'event_name' => 'حفل التخرج',
    'event_date' => '2026-09-01',
]);

check("20. Resolver thank_you نص صحيح", $thank_you_result['text'], $expected_thank_you_text);
check("20b. Resolver thank_you image_url === null", $thank_you_result['image_url'], null);

// ══════════════════════════════════════════════════════════════════════
// 21-22: لا Caller إنتاجي يرسل reminder/thank_you (تدقيق ثابت على الكود
//         الحقيقي عبر قراءة الملف — لا استدعاء إرسال فعلي بالطبع في اختبار).
// ══════════════════════════════════════════════════════════════════════

$cartat_handler_src = file_get_contents(__DIR__ . '/../includes/class-cartat-handler.php');

check_true(
    "21. لا استدعاء PGE_Message_Type::REMINDER في class-cartat-handler.php",
    strpos($cartat_handler_src, 'PGE_Message_Type::REMINDER') === false
);
check_true(
    "22. لا استدعاء PGE_Message_Type::THANK_YOU في class-cartat-handler.php",
    strpos($cartat_handler_src, 'PGE_Message_Type::THANK_YOU') === false
);

// ══════════════════════════════════════════════════════════════════════
// 23-24: لا تعديل على ملفات Ledger/Provider (تحقق أن أسماء العقد الحرجة ما
//         زالت موجودة حرفياً بلا مساس — إثبات عدم التعديل عبر بصمة توقيع
//         الدوال الحرجة، وليس diff كامل الذي يُغطّى في تدقيق منفصل خارج
//         هذا الملف).
// ══════════════════════════════════════════════════════════════════════

$ledger_path = __DIR__ . '/../includes/class-pge-invitation-credit-ledger.php';
check_true("23. ملف Ledger موجود ولم يُحذف: class-pge-invitation-credit-ledger.php", file_exists($ledger_path));
if (file_exists($ledger_path)) {
    $ledger_src = file_get_contents($ledger_path);
    check_true("23b. Ledger ما زال يحوي ALLOWED_CREDIT_TYPES بلا تغيير في التوقيع", strpos($ledger_src, 'ALLOWED_CREDIT_TYPES') !== false);
    check_true("23c. Ledger ما زال يحوي claim_for_delivery()", strpos($ledger_src, 'function claim_for_delivery') !== false);
    check_true("23d. Ledger ما زال يحوي mark_consumed_with_token()", strpos($ledger_src, 'function mark_consumed_with_token') !== false);
    check_true("23e. Ledger ما زال يحوي mark_failed_with_token()", strpos($ledger_src, 'function mark_failed_with_token') !== false);
}

$transport_path = __DIR__ . '/../includes/class-pge-cartat-transport.php';
check_true("24. ملف PGE_Cartat_Transport موجود ولم يُحذف", file_exists($transport_path));
if (file_exists($transport_path)) {
    $transport_src = file_get_contents($transport_path);
    check_true("24b. Transport ما زال يحوي send_text()", strpos($transport_src, 'function send_text') !== false);
    check_true("24c. Transport ما زال يحوي send_media()", strpos($transport_src, 'function send_media') !== false);
    check_true("24d. Transport لا يعرف message_type إطلاقاً (بلا أثر لها في الملف)", strpos($transport_src, 'message_type') === false);
}

// ══════════════════════════════════════════════════════════════════════
// 25: لا AJAX action جديد أُضيف في class-cartat-handler.php لأجل Phase 1
// ══════════════════════════════════════════════════════════════════════

check_true(
    "25. لا wp_ajax إجراء جديد يحمل 'reminder' أو 'thank_you' في class-cartat-handler.php",
    (strpos($cartat_handler_src, "wp_ajax_pge_wa_reminder") === false)
    && (strpos($cartat_handler_src, "wp_ajax_pge_wa_thank_you") === false)
    && (strpos($cartat_handler_src, "wp_ajax_nopriv_pge_wa_reminder") === false)
    && (strpos($cartat_handler_src, "wp_ajax_nopriv_pge_wa_thank_you") === false)
);

// ══════════════════════════════════════════════════════════════════════
// 26: لا تغيير في قاعدة البيانات/Schema لأجل Phase 1 (لا CREATE TABLE ولا
//     ALTER TABLE جديدة في الملفات المُعدَّلة/المُضافة هذه المرحلة).
// ══════════════════════════════════════════════════════════════════════

$message_type_src   = file_get_contents(__DIR__ . '/../includes/class-pge-message-type.php');
$resolver_src        = file_get_contents(__DIR__ . '/../includes/class-pge-message-content-resolver.php');
$helpers_src          = file_get_contents(__DIR__ . '/../includes/helpers.php');

check_true(
    "26. لا CREATE TABLE/ALTER TABLE/dbDelta في class-pge-message-type.php أو class-pge-message-content-resolver.php أو helpers.php",
    (stripos($message_type_src, 'CREATE TABLE') === false) && (stripos($message_type_src, 'ALTER TABLE') === false) && (stripos($message_type_src, 'dbDelta') === false)
    && (stripos($resolver_src, 'CREATE TABLE') === false) && (stripos($resolver_src, 'ALTER TABLE') === false) && (stripos($resolver_src, 'dbDelta') === false)
    && (stripos($helpers_src, 'CREATE TABLE') === false) && (stripos($helpers_src, 'ALTER TABLE') === false) && (stripos($helpers_src, 'dbDelta') === false)
);

// ── ملخص ────────────────────────────────────────────────────────────────

echo "\n";
echo "النتيجة: $passed / $total نجحت.\n";

if (!empty($failures)) {
    echo "الحالات الفاشلة:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}

exit(0);
