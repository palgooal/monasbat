# نظام رسائل المناسبة (Messaging Architecture) — Phase 0 + Phase 1 + Phase 2

> يوثّق هذا الملف **فقط** ما تم اعتماده (Phase 0 — Contract) وما تم تنفيذه فعلياً
> في الكود (Phase 1 — Refactor داخلي بلا تغيير سلوك؛ Phase 2 — بنية تحتية
> Foundation فقط لـReminder/Thank You، بلا إرسال فعلي). لا يوثّق تفاصيل مراحل
> مستقبلية (Reminder/Thank You الفعليَّين، الجدولة) إلا كقائمة "غير منفَّذ بعد"
> مختصرة — راجع تقرير Architecture Audit وتقرير Phase 0 للتفاصيل الكاملة لتلك
> المراحل عند بدء تنفيذها فعلياً.

---

## 1. القرار المعتمد (Phase 0 Contract)

- ثلاثة أنواع رسائل: `invitation` (قائم فعلياً)، `reminder`، `thank_you`.
- الثلاثة **Baseline Features** في كل الباقات (ليست ميزة مدفوعة تفاضلية) —
  قرار منتج معتمد مسبقاً، لا علاقة له بـ Feature Registry الحالي.
- Reminder: إطلاق يدوي فقط (بلا جدولة تلقائية بعد)، الهدف الافتراضي = غير
  المستجيبين مع خيار "كل المدعوين"، يُسمح بإعادة الإرسال عمداً لكن مع منع
  التكرار المزدوج (Double-click / Queue متزامنة مكررة) داخل عملية إرسال واحدة.
- Thank You: إطلاق يدوي فقط، الهدف = `checked_in = 1` حصراً (وليس RSVP=yes)،
  الافتراض = مرة واحدة لكل ضيف لكل مناسبة في v1 (يحتاج Contract صريحاً عند
  التنفيذ الفعلي).
- لا Reminder ولا Thank You يستهلكان Invitation Credit — الرصيد يبقى محجوزاً
  لمنطق الدعوة (الأساسية/البديلة) القائم حالياً بلا تغيير.
- الحقل القديم `wa_messages` يبقى غير مستخدَم/غير مرتبط الآن.
- `message_type` Contract: ملف مستقل `includes/class-pge-message-type.php`،
  وليس داخل `helpers.php`.

---

## 2. ما تم تنفيذه فعلياً (IMPLEMENTED — Phase 1)

### 2.1 `PGE_Message_Type` — العقد الوحيد للقيم المسموحة

**الملف:** `includes/class-pge-message-type.php` (جديد).

كلاس بثوابت (بنفس فلسفة `ALLOWED_CREDIT_TYPES` في `PGE_Invitation_Credit_Ledger`
— لا PHP Enum، المشروع لا يفترض PHP 8.1+):

```php
PGE_Message_Type::INVITATION // 'invitation'
PGE_Message_Type::REMINDER   // 'reminder'
PGE_Message_Type::THANK_YOU  // 'thank_you'
PGE_Message_Type::ALL        // القيم الثلاث كمصفوفة

PGE_Message_Type::normalize($value): ?string  // trim + strtolower + مطابقة تامة، أو null
PGE_Message_Type::is_valid($value): bool
```

يُحمَّل من `pgevents-core.php` مباشرة بعد `includes/helpers.php` (بلا أي
Dependency، Contract فقط).

### 2.2 قوالب Reminder/Thank You — أساس معماري فقط

**الملف:** `includes/helpers.php` (مُعدَّل).

دالتان جديدتان بنفس نمط `pge_wa_default_invite_template()`:

```php
pge_wa_default_reminder_template()
// "مرحباً {{guest_name}}،\nنذكّركم بموعد {{event_name}} بتاريخ {{event_date}}."

pge_wa_default_thank_you_template()
// "شكراً لحضوركم {{event_name}}، سعدنا بمشاركتكم."
```

`pge_wa_get_templates(int $event_id): array` وُسِّعت إضافياً لتعيد مفتاحين
جديدين (`reminder`, `thank_you`) بجانب الأربعة القائمة (`invite`, `yes`, `no`,
`invalid`)، بمصدر مخصّص لكل مناسبة عبر Post Meta جديدة
(`_pge_wa_tpl_reminder`, `_pge_wa_tpl_thank_you`) مع fallback للـdefault —
تماماً كنمط الأربعة الحاليين. **تحقّق مسبق قبل التوسيع:** كل مستهلكي هذه
الدالة في المشروع (3 مواضع في `class-cartat-handler.php`، 3 مواضع مطابقة في
`class-ultramsg-handler.php`، وملفا اختبار يعرّفان Mock محلياً خاصاً بهما) يقرأ
بمفتاح جمعي محدد فقط (`['invite']`/`['yes']`/…) بلا أي اعتماد على `count()`
أو ترتيب — التوسيع آمن ولا يُغيّر أي قيمة قائمة.

لا Caller إنتاجي يستدعي `pge_wa_default_reminder_template()` أو
`pge_wa_default_thank_you_template()` أو مفتاحي `reminder`/`thank_you` من
`pge_wa_get_templates()` بعد — أساس معماري فقط بانتظار Phase مستقبلية.

### 2.3 `PGE_Message_Content_Resolver` — نقطة بناء المحتوى الموحَّدة

**الملف:** `includes/class-pge-message-content-resolver.php` (جديد).

```php
PGE_Message_Content_Resolver::resolve(
    string $message_type,   // من PGE_Message_Type::ALL؛ قيمة غير صالحة → fallback إلى invitation دفاعياً
    int    $event_id,
    array  $context = []    // guest_name, event_name, event_date, event_date_line,
                             // guest_phone, event_url, invite_code, location_line, image_url
): array // ['text' => string, 'image_url' => ?string]
```

**مسؤوليته: CONTENT فقط** (اختيار القالب + استبدال المتغيرات عبر
`pge_wa_render_template()` القائمة بلا تغيير + تحديد رابط الصورة). لا علاقة
له إطلاقاً بـ Invitation Credits، حالة الـQueue، الـLedger، تفسير نتيجة
المزوّد، إعادة المحاولة، أو السجلات — تلك تبقى بالكامل مسؤولية الطبقات
المستدعية (`class-cartat-handler.php`) بلا أي تغيير.

- **`invitation`**: يُنتج بالضبط نفس القالب/النص/الصورة التي كانت تُبنى يدوياً
  من قبل في المواضع الثلاثة (مُثبَت تنفيذياً — راجع §3). `image_url`: إن وُجد
  المفتاح في `$context` (حتى لو فارغاً) يُستخدَم كما هو؛ إن غاب كلياً يُحسَب
  حديثاً عبر `get_the_post_thumbnail_url()` — يحافظ على أداء الكود القديم
  (حساب الصورة مرة واحدة لكل دفعة، لا لكل هاتف).
- **`reminder`/`thank_you`**: تُبنى المحتوى (نص من القالب المناسب،
  `image_url = null` دوماً — نص فقط، قرار Phase 0 صريح) لكن **لا Caller
  إنتاجي واحد يستدعيهما بعد** — لا إرسال، لا Queue، لا AJAX.

### 2.4 المواضع المُعاد بناؤها (Refactor) في `class-cartat-handler.php`

ثلاثة مواضع فقط، الثلاثة الحقيقية التي كانت تكرر نفس منطق "قراءة القالب +
بناء المتغيرات + التصيير + تحديد الصورة":

| الدالة | كانت تبني يدوياً | أصبحت |
|---|---|---|
| `ajax_test_send()` | قالب + render + صورة | `PGE_Message_Content_Resolver::resolve(INVITATION, ...)` |
| `send_invitations()` (لكل هاتف في الحلقة) | نفس الشيء | نفس الاستدعاء، مع `image_url` مُمرَّرة صراحةً من القيمة المحسوبة مرة واحدة خارج الحلقة |
| `cron_process_queue()` (لكل هاتف) | نفس الشيء | نفس الاستدعاء، مع `image_url` من `$queue['image_url']` |

كل موضع محاط بحارس `class_exists('PGE_Message_Content_Resolver') &&
class_exists('PGE_Message_Type')` مع مسار احتياطي (`else`) يُكرر المنطق
القديم حرفياً — دفاعي فقط، غير متوقَّع تفعيله عملياً لأن الملفات تُحمَّل دوماً.

**Credits/Queue/Ledger/Provider/Retry/Logs/Status لم تُنقَل إلى الـResolver
إطلاقاً** — بقيت بالكامل في `class-cartat-handler.php` كما كانت.

### 2.5 حقل `message_type` في الـQueue — تم التأجيل

**القرار: لم يُضَف** حقل `message_type` إلى مصفوفة `$queue` (`pge_wa_queue_{event_id}`
في `wp_options`) في هذه المرحلة.

**السبب:** لا كود حالي يقرأ مفتاح `message_type` من `$queue`، والـResolver
يستقبل `message_type` كوسيط دالة صريح في كل واحد من المواضع الثلاثة (قيمة
`PGE_Message_Type::INVITATION` ثابتة، مُمرَّرة مباشرة في كل استدعاء) — لا حاجة
فعلية لتخزينها في بنية الـQueue طالما لا Caller آخر (Reminder/Thank You)
يستهلك نفس الـQueue بعد. إضافتها الآن ستكون حقلاً ميتاً بلا أي قارئ — أقل Diff
ممكن هو عدم إضافته، ويُرجَّح تناوله في Phase لاحقة عندما يُصبح Reminder/Thank
You فعليَّين ويحتاجا مساراً في الـQueue (عندها سيُحدَّد ما إذا كانا يشتركان في
نفس بنية الـQueue أو يحصلان على بنية مستقلة). **لم تتغيّر** بنية `$queue`، ولا
مفتاح الـQueue في `wp_options`، ولا اسم قفل الـCron (`pge_wa_lock_{event_id}`)،
ولا منطق الحالة (status).

---

## 3. الإثبات التنفيذي أن سلوك الدعوة لم يتغيّر

ملف `tests/test-messaging-phase1.php` (37 فحصاً، جميعها ناجحة، مُشغَّلة عبر
مُفسِّر PHP 8.3 حقيقي — WordPress Playground `@php-wasm`) يُثبت — بتشغيل
الكود الحقيقي غير المُقلَّد (`require_once` مباشر للملفات الفعلية) — أن:

- نص الدعوة الناتج من `PGE_Message_Content_Resolver` مطابق حرفياً لما كان
  يُنتَج يدوياً قبل الـRefactor (نفس القالب، نفس المتغيرات، نفس النتيجة).
- `image_url` الناتج مطابق حرفياً في الحالات الثلاث (Test Send يحسب الصورة
  حديثاً، الحلقة الرئيسية والـQueue يمرّران القيمة المحسوبة مسبقاً).
- `pge_wa_get_templates()` لا تزال تعيد نفس القيم القديمة للمفاتيح الأربعة
  الأصلية بلا أي تغيير.
- لا Caller إنتاجي واحد يستخدم `PGE_Message_Type::REMINDER` أو
  `PGE_Message_Type::THANK_YOU` في `class-cartat-handler.php`.
- ملفات الـLedger والـTransport لم تُعدَّل (توقيعات الدوال الحرجة سليمة، ولا
  أثر لـ`message_type` في طبقة النقل).
- لا AJAX action جديد، لا CREATE/ALTER TABLE جديد.

بالإضافة إلى انحدار كامل على ستة ملفات اختبار قائمة (RSVP parsing/verification،
Cartat credits queue، Supervisor Cartat delivery، Invitation Credit Ledger،
Replacement Credit Consumption، RSVP write-path unification) — راجع التقرير
النهائي لتفاصيل النتائج والفشل المسبق (Pre-existing) غير المرتبط بهذه المرحلة.

---

## 4. Phase 2 — Foundation (بنية تحتية فقط، بلا إرسال فعلي)

Phase 2 لا تضيف أي إرسال حقيقي لـReminder أو Thank You — تضيف فقط البنية
التحتية (Schema + Tracking + Idempotency Primitive) التي سيحتاجها إرسال
حقيقي في مرحلة مستقبلية. لا Caller إنتاجي واحد يستدعي أياً من الملفات
التالية في Phase 2.

### 4.1 Schema (إضافي بحت — Additive فقط)

**الملف:** `includes/class-pge-messaging-schema.php` (جديد، نفس نمط
`PGE_Checkin_Schema`/`PGE_Invitation_Management_Schema`).

- عمود جديد واحد على `{$wpdb->prefix}pge_event_rsvps`: `thank_you_sent_at
  DATETIME NULL` — لا تعديل على أي عمود قائم.
- جدول جديد `{$wpdb->prefix}pge_message_log`: `id, event_id, rsvp_id
  (nullable), guest_phone, message_type, batch_id, status, provider
  (nullable), actor_user_id, created_at, sent_at (nullable)`. فهارس:
  `KEY event_type (event_id, message_type)`، `KEY batch_id (batch_id)`،
  `KEY status (status)` — الحد الأدنى المبرَّر لكل استعلام مطلوب فعلياً في
  `PGE_Message_Log`، بلا فهرس زائد على `event_id` وحده (مُغطًّى أصلاً كطرف
  أيسر لفهرس `event_type`).

### 4.2 `PGE_Message_Log` — طبقة Tracking عامة (Tracking فقط)

**الملف:** `includes/class-pge-message-log.php` (جديد).

`create_pending()` / `mark_sent()` / `mark_failed()` / `query_by_batch()` /
`query_by_event_type()`. يرفض `create_pending()` أي `message_type` غير
معروف عبر `PGE_Message_Type::normalize()` (لا قائمة موازية جديدة). Status
Contract: `pending`, `sent`, `failed`, `ambiguous_transport_error` فقط — لا
`duplicate_operation` (مفهوم Queue، لا رسالة)، لا `already_sent` (نتيجة
Claim، لا حالة سجل). **لا يُخزَّن أبداً:** نص الرسالة، QR، رمز الدعوة،
tokens، بيانات اعتماد المزوّد، أو استجابة API الخام — `guest_phone` بصيغته
المُطبَّعة القياسية (`pge_norm_phone()`) فقط.

### 4.3 `PGE_Thank_You_Claim` — مطالبة Thank You الذرية (Idempotency Primitive)

**الملف:** `includes/class-pge-thank-you-claim.php` (جديد).

عقد الحالة: `thank_you_sent_at = NULL` ⇒ لم يُرسَل بعد؛ `!= NULL` ⇒ أُرسل
بنجاح نهائياً. **لا قيمة وسيطة تعني "قيد الإرسال".**

**القرار المعماري (خيار B، لا خيار A):** قُورِن بين (A) كتابة
`thank_you_sent_at` فوراً عند بدء المحاولة مع Rollback عند الفشل، و(B) حارس
"قيد التنفيذ" منفصل تماماً بحيث لا يُكتَب `thank_you_sent_at` إلا عند النجاح
المؤكَّد فقط. اختير **(B)**: خطر (A) البنيوي هو أن أي انقطاع غير متوقَّع بين
بدء المحاولة وRollback يترك `thank_you_sent_at` مكتوبة رغم عدم إرسال أي
رسالة فعلياً — يكذب العقد أعلاه بشكل دائم لا يمكن اكتشافه لاحقاً بلا تدخّل
يدوي. الحارس المُنفَّذ: سجل `pge_message_log` بحالة `pending` لنفس
`rsvp_id`/`thank_you` — طالما هو موجود، لا `claim()` جديد يُقبَل. `GET_LOCK`
(نفس نمط `PGE_Checkin_Recorder`/`PGE_Invitation_Credit_Ledger`) يُستخدَم فقط
للحظة القصيرة اللازمة لإنشاء سجل الحارس ذرياً — لا يُحمَل عبر الإرسال الخارجي
الفعلي (الذي يحدث لاحقاً، بعد Release القفل، بين `claim()` و`finalize_*()`).

**API:** `is_sent()`, `can_send()`, `claim($event_id, $rsvp_id, ...)` (يعيد
`claimed` / `already_sent` / `already_in_progress` / `error`),
`finalize_success($log_id, $rsvp_id)` (يكتب `thank_you_sent_at` الآن فقط),
`finalize_failure($log_id, $status)` (يُحرِّر الحارس، يسمح بإعادة المحاولة —
`thank_you_sent_at` لا يُلمَس إطلاقاً).

**قيد معروف مقبول صراحة (Foundation فقط):** إن تعطَّلت العملية بعد `claim()`
(سجل `pending` أُنشئ) ولم يُستدعَ `finalize_*()` إطلاقاً، يبقى الحارس عالقاً
إلى الأبد (نفس فئة الخطر التي واجهها `PGE_Invitation_Credit_Ledger::
claim_for_delivery()` قبل إضافة Lease لاحقاً). لا آلية Lease/Reclaim هنا
بعد — غير مطلوبة في Phase 2 (لا إرسال فعلي بعد يجعل هذا الخطر واقعياً)، تُترَك
صراحةً لمرحلة تفعيل الإرسال الفعلي إن ثبتت الحاجة.

### 4.4 `PGE_Message_Batch` — مولّد `batch_id`

**الملف:** `includes/class-pge-message-batch.php` (جديد). `generate_batch_id()`
تعيد UUID v4 عبر `wp_generate_uuid4()` (WordPress Core منذ 4.7) — عشوائي
بالكامل، جديد لكل استدعاء، بلا اعتماد على `time()` أو `event_id`. لا Queue
تستهلكه بعد.

### 4.5 Lifecycle / Hard Delete (توثيق سلوك حالي — بلا تغيير)

Hard Delete (راجع `docs/HARD-DELETE-SEMANTICS-AUDIT.md`) **لا يلمس**
`wp_pge_event_rsvps` ولا `wp_pge_message_log` إطلاقاً — كلاهما يبقى كسجل
تاريخي/Audit "يتيم" (orphan) بعد حذف الدعوة، تماماً كـ`checked_in`/
`checked_in_at` القائمَين أصلاً. لا FK، لا Cascade Delete أُضيف (يتوافق مع
تجنّب المشروع للـFK في كل الجداول القائمة).

**قيد مكتشَف، غير مُصلَح عمداً في Phase 2:** دالة "تصفير في مكانه"
(`PGE_Invitation_Repository::current_or_null()`, تُشغَّل عند إعادة دعوة
هاتف كان له صف RSVP يتيم سابقاً) تُصفِّر صراحةً `checked_in`/`checked_in_at`/
`checked_in_by_assignment_id`/`checkin_method`/`actual_entered_count`/
`reply`/`companions`/`note`/`created_at` — لكنها **لا تُصفِّر
`thank_you_sent_at`** (العمود لم يكن موجوداً وقت كتابة تلك الدالة). هذا يعني
نظرياً: لو أُرسل شكر لضيف، حُذفت دعوته، ثم أُعيدت دعوته لاحقاً بنفس الهاتف عبر
مسار "تصفير في المكان"، سيرث الصف "المُصفَّر حديثاً" قيمة `thank_you_sent_at`
القديمة خطأً. **لا خطر فعلي اليوم** (لا Caller إنتاجي يكتب `thank_you_sent_at`
بعد — القيمة دائماً `NULL` لكل صف حالياً)، لكن هذا قيد يجب إصلاحه (إضافة
`thank_you_sent_at => null` إلى مصفوفة التصفير في `current_or_null()`) **قبل**
تفعيل أي إرسال Thank You حقيقي في مرحلة مستقبلية — خارج نطاق Phase 2 عمداً
(Foundation فقط، لا تعديل على ملفات RSVP Write Path القائمة).

### 4.6 الإثبات التنفيذي

ملف `tests/test-messaging-phase2.php` (46 فحصاً، جميعها ناجحة، عبر نفس
مُفسِّر PHP 8.3 الحقيقي المُستخدَم في Phase 1) يُثبت: الترقية تضيف العمود
والجدول فعلياً، `create_pending()` يرفض الأنواع غير المعروفة ولا يخزّن نص
رسالة/tokens، `mark_sent()`/`mark_failed()` ذريان وIdempotent،
`query_by_batch()`/`query_by_event_type()` تعملان بشكل صحيح، `claim()`
يمنع مطالبتين متزامنتين لنفس الضيف (سواء عبر الحارس أو عبر قفل SQL محجوز
فعلياً)، `finalize_success()` يكتب `thank_you_sent_at` فقط عند النجاح
المؤكَّد، `finalize_failure()` يسمح بإعادة المحاولة بلا أي أثر على العمود،
مطالبة لضيف أُرسل له الشكر مسبقاً تُرفَض دائماً، ضيفان/مناسبتان مختلفتان
مستقلان تماماً، ولا أثر لأي من هذا في مسارات الدعوة/RSVP/Check-in/Ledger
القائمة (فحص ثابت على المصدر الفعلي).

---

## 5. غير منفَّذ بعد (NOT IMPLEMENTED YET)

- إرسال Reminder فعلياً (لا AJAX، لا Queue، لا زر واجهة).
- إرسال Thank You فعلياً (لا AJAX، لا Queue، لا زر واجهة) — البنية التحتية
  (Schema + Log + Claim) جاهزة (Phase 2) لكن بلا Caller.
- أي جدولة تلقائية (Cron) لـReminder أو Thank You.
- منع التكرار المزدوج لـReminder (قفل عملية إرسال واحدة، مفهوم مختلف عن
  Idempotency Thank You أعلاه) — غير مُنفَّذ.
- Lease/Reclaim لمطالبات Thank You العالقة (راجع §4.3 "قيد معروف مقبول") —
  غير مُنفَّذ، غير مطلوب طالما لا إرسال فعلي.
- إصلاح توريث `thank_you_sent_at` في `current_or_null()` (راجع §4.5) — يجب
  إنجازه قبل أي إرسال Thank You حقيقي.
- Recipient Resolver الفعلي لتحديد "غير المستجيبين" أو "كل المدعوين"
  لـReminder، أو `checked_in=1` لـThank You — غير مُنفَّذ.
- أي واجهة مستخدم (UI) لإطلاق Reminder/Thank You من `event-invitations.php`
  أو أي مكان آخر.
- ربط `message_type` ببنية الـQueue الحالية (`$queue['message_type']`) —
  مؤجَّل، راجع §2.5. لا علاقة له بـ`pge_message_log` (جدول Tracking مستقل
  تماماً عن Queue الدعوة الحالية).
- استخدام `pge_message_log` فعلياً لتتبّع إرسال الدعوة (Invitation) — غير
  مُنفَّذ عمداً في Phase 2 (راجع PART 9 من تكليف Phase 2: لا كتابة من مسار
  الدعوة الحالي إلى `message_log`).
