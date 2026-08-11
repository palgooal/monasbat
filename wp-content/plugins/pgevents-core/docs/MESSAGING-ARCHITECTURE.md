# نظام رسائل المناسبة — Phase 0 + Phase 1 + Phase 2 + Phase 3 + Phase 4A-2/4A-3 + Phase 4B-1/4B-2/4B-2.6/4B-3A/4B-3B

> يوثّق هذا الملف **فقط** ما تم اعتماده (Phase 0 — Contract) وما تم تنفيذه فعلياً
> في الكود (Phase 1 — Refactor داخلي بلا تغيير سلوك؛ Phase 2 — بنية تحتية
> Foundation فقط لـReminder/Thank You، بلا إرسال فعلي؛ Phase 3 — إرسال Reminder
> اليدوي الفعلي، أول مسار إرسال حقيقي يستخدم بنية Phase 1/2؛ Phase 4A-2 — Claim
> lease/reclaim؛ Phase 4A-3 — Schema drift hardening؛ Phase 4B-1 — Recipient
> eligibility read-only لـThank You؛ Phase 4B-2 — Service إرسال داخلية؛
> Phase 4B-2.6 — Durable async-only Batch/Worker؛ Phase 4B-3A — authenticated
> Preview/Start/Status AJAX؛ Phase 4B-3B — Manual Thank You UI وStatus polling).
> لا يوثّق تفاصيل
> مراحل مستقبلية (Thank You الفعلي، الجدولة التلقائية) إلا كقائمة "غير منفَّذ
> بعد" مختصرة — راجع تقرير Architecture Audit وتقرير Phase 0 للتفاصيل الكاملة
> لتلك المراحل عند بدء تنفيذها فعلياً.

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

أصبح مفتاح `reminder` مستهلكاً عبر Phase 3، ومفتاح `thank_you` عبر Service
Phase 4B-2 الداخلية. كلاهما ما زال يستخدم نفس fallback الافتراضي، ولا يوجد
endpoint لحفظ قالب Thank You حتى الآن.

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
- **`reminder`**: نص من القالب المناسب، و`image_url = null` افتراضياً. يقبل
  رابطاً صريحاً فقط من Reminder Service بعد حل Featured Image خادمياً؛ لا يقرأ
  WordPress image بنفسه. **`thank_you`** يبقى `image_url = null` دوماً.

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
  (nullable), lifecycle_started_at (nullable), guest_phone, message_type, batch_id, status, provider
  (nullable), actor_user_id, created_at, sent_at (nullable)`. فهارس:
  `KEY event_type (event_id, message_type)`، `KEY batch_id (batch_id)`،
  `KEY status (status)` — الحد الأدنى المبرَّر لكل استعلام مطلوب فعلياً في
  `PGE_Message_Log`، بلا فهرس زائد على `event_id` وحده (مُغطًّى أصلاً كطرف
  أيسر لفهرس `event_type`).

**Phase 4A-3 — Schema Drift Hardening:** قيمة
`pge_messaging_schema_version` هي Hint وليست وحدها إثبات سلامة Schema.
`maybe_upgrade()` لا تنفّذ early return عند version الحالية إلا بعد فحص
postconditions فعلياً: وجود `pge_message_log`، اكتمال أعمدته، وأن
`lifecycle_started_at` من نوع `DATETIME` ويسمح بـ`NULL`، مع بقاء عمود RSVP
المطلوب موجوداً. إذا كان الجدول مفقوداً يُعاد إنشاؤه عبر مسار `dbDelta()`
الحالي، وإذا كان العمود وحده مفقوداً يُصلح بإضافة `ADD COLUMN` idempotent.
فشل الإصلاح لا يغيّر version ولا ينتج postcondition سليمة زائفة. وجود العمود
بعقد غير متوافق مثل `NOT NULL` يُكتشف ويفشل بأمان دون `MODIFY` أو تغيير
مدمّر تلقائي.

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
يدوي. الحارس المُنفَّذ: سجل `pge_message_log` بحالة `pending` لهوية
`event_id + rsvp_id + lifecycle_started_at + thank_you`. قيمة
`lifecycle_started_at` هي snapshot من `RSVP.created_at`، التي تمثل بداية
Current Lifecycle وفق Phase C/D. السجلات القديمة بلا marker لا تحجب دورة
جديدة. `GET_LOCK`
(نفس نمط `PGE_Checkin_Recorder`/`PGE_Invitation_Credit_Ledger`) يُستخدَم فقط
للحظة القصيرة اللازمة لإنشاء سجل الحارس ذرياً — لا يُحمَل عبر الإرسال الخارجي
الفعلي (الذي يحدث لاحقاً، بعد Release القفل، بين `claim()` و`finalize_*()`).

**API:** `is_sent()`, `can_send()`, `claim($event_id, $rsvp_id, ...)` (يعيد
`claimed` / `already_sent` / `already_in_progress` / `error`),
`finalize_success($log_id, $rsvp_id)` (يكتب `thank_you_sent_at` الآن فقط),
`finalize_failure($log_id, $status)` (يُحرِّر الحارس، يسمح بإعادة المحاولة —
`thank_you_sent_at` لا يُلمَس إطلاقاً).

**Phase 4A-2 — Lease/Reclaim:** `CLAIM_LEASE_SECONDS = 120` هو المصدر المركزي
للمهلة؛ يطابق Invitation Credit Ledger، ويتجاوز Cartat timeout البالغ 20 ثانية
بهامش واضح. `pending` أحدث من المهلة تمنع مطالبة متوازية، أما `pending` المتقادمة
فتتحول إلى `failed` ثم تُنشأ مطالبة جديدة. `ambiguous_transport_error` الحديثة
تمنع retry فورياً حتى نهاية نفس المهلة، ثم يمكن retry دون status جديد. مطالبة
Lifecycle قديمة لا تحجب الجديدة حتى لو كانت حديثة زمنياً.

`finalize_success()` و`finalize_failure()` تعيدان قراءة الـLog والـRSVP داخل
قفل قصير وتتحققان من تطابق marker. نجاح متأخر من دورة قديمة لا يستطيع كتابة
`thank_you_sent_at` على الدورة الحالية؛ والفشل المتأخر لا يغير حالتها. لا يُحمَل
أي DB lock أثناء HTTP مستقبلي.

### 4.4 `PGE_Message_Batch` — مولّد `batch_id`

**الملف:** `includes/class-pge-message-batch.php` (جديد). `generate_batch_id()`
تعيد UUID v4 عبر `wp_generate_uuid4()` (WordPress Core منذ 4.7) — عشوائي
بالكامل، جديد لكل استدعاء، بلا اعتماد على `time()` أو `event_id`. لا Queue
تستهلكه بعد.

### 4.5 Lifecycle / Hard Delete / Current Snapshot (Phase C)

Hard Delete (راجع `docs/HARD-DELETE-SEMANTICS-AUDIT.md`) **لا يلمس**
`wp_pge_event_rsvps` ولا `wp_pge_message_log` إطلاقاً. لا FK ولا Cascade Delete
أُضيف. صف RSVP ليس Historical Log متعدد الصفوف؛ تحت عقد Option A هو **Current
Snapshot** واحد للهوية `event_id + normalized_guest_phone`، ويُعاد استخدام نفس
`rsvp_id` عبر دورة الدعوة التالية.

تبدأ New Invitation Lifecycle فقط عند نجاح إنشاء دعوة جديدة (أول إنشاء أو
Re-create بعد Hard Delete) عبر `PGE_Invitation_Service::create()` ثم
`PGE_Invitation_Repository::create()`. بعد validation وGuest Limit وduplicate
checks، ينفّذ Repository reset مركزياً للصف canonical الموجود قبل إظهار الدعوة
الجديدة. `not_found` نجاح بلا INSERT؛ `integrity_error` أو فشل update يمنع
الإنشاء والتدقيق. Bulk Add وExcel Confirm يرثان السلوك عبر Service ولا يملكان
reset مكرراً.

الـreset يحافظ على `id/event_id/guest_phone` ويصفّر `guest_name`، `reply`،
`companions`، `note`، جميع حقول Check-in، و`thank_you_sent_at`، ثم يضبط
`created_at/updated_at` إلى طابع بداية الدورة UTC نفسه. لذلك `created_at` يمثل
بداية الـRSVP lifecycle الحالية، لا عمر `rsvp_id` الفيزيائي. أما
`current_or_null()` فأصبح حارس stale read-only: يعيد الصف الحالي كما هو أو
`null` للصف القديم، ولا يغيّر lifecycle عند lookup/RSVP/Check-in/Reminder.

لا تُستخدم transaction سطحية تجمع WordPress meta cache مع SQL. التنفيذ يفحص
postconditions ويستخدم compensation لإعادة خرائط الدعوة ولقطة RSVP السابقة عند
فشل تخزين الدعوة بعد reset. لا يُسجَّل Audit `created` إلا بعد نجاح التخزين
والـreset معاً.

### 4.6 الإثبات التنفيذي

ملف `tests/test-messaging-phase2.php` (89 فحصاً، جميعها ناجحة، عبر نفس
مُفسِّر PHP 8.3 الحقيقي المُستخدَم في Phase 1) يُثبت: الترقية تضيف العمود
والجدول فعلياً، `create_pending()` يرفض الأنواع غير المعروفة ولا يخزّن نص
رسالة/tokens، `mark_sent()`/`mark_failed()` ذريان وIdempotent،
`query_by_batch()`/`query_by_event_type()` تعملان بشكل صحيح، `claim()`
يمنع مطالبتين متزامنتين لنفس الضيف في Harness متسلسل يحاكي advisory lock
(لم يُشغَّل اتصالا MySQL متوازيان فعلياً)، `finalize_success()` يكتب `thank_you_sent_at` فقط عند النجاح
المؤكَّد، `finalize_failure()` يسمح بإعادة المحاولة بلا أي أثر على العمود،
مطالبة لضيف أُرسل له الشكر مسبقاً تُرفَض دائماً، وLease/Reclaim وLifecycle
reuse وlate finalize وambiguous retry window مغطاة، ضيفان/مناسبتان مختلفتان
مستقلان تماماً، ولا أثر لأي من هذا في مسارات الدعوة/RSVP/Check-in/Ledger
القائمة (فحص ثابت على المصدر الفعلي).

---

## 5. Phase 3 — Manual Reminder (IMPLEMENTED)

أول مسار إرسال فعلي في نظام الرسائل — يُرسِل المضيف "تذكيراً" نصياً يدوياً
لضيوف مناسبة معيَّنة، مبنياً بالكامل فوق بنية Phase 1 (Content Resolver/
Templates) وPhase 2 (Message Log/Batch) بلا أي بنية موازية.

### 5.1 نطاق v1 (بالضبط كما اعتُمد في Contract)

المستهدف الافتراضي = المدعوون الذين لم يردوا بعد (`pending`)، مع خيار "كل
المدعوين" (`all`). المزوّد = Cartat فقط. المحتوى الافتراضي نص فقط، مع خيار
إرفاق Featured Image الخاصة بالمناسبة (`Text + Media`) لكل عملية إرسال مقصودة.
Reminder لا يستهلك أي Invitation Credit. يمكن إرساله مرة أخرى لاحقاً
عبر عملية جديدة مقصودة (**ليس** Once-per-event). لا جدولة تلقائية.

### 5.2 `PGE_Message_Recipient_Resolver` — تحديد المستلمين

**الملف:** `includes/class-pge-message-recipient-resolver.php` (جديد).

Abstraction صغيرة بلا Provider logic ولا UI logic: `resolve(event_id,
message_type, filter)` تُعيد `['recipients' => [...], 'skipped_invalid_phone'
=> int, 'filter' => string]`. المصادر: `pge_get_invited_phones()` +
`pge_event_guests_get_map()` + قراءة مباشرة لـ`wp_pge_event_rsvps` (نفس طريقة
`pge_event_guests_load_rsvp_from_db()`).

**تعريف `pending` النهائي** (مُعاد استخدامه حرفياً من
`pge_event_guests_get_row_payload()` — نفس ما يراه المضيف في عمود "حالة
الرد" في صفحة إدارة الدعوات، لا تعريف جديد):

```php
$reply  = $rsvp_map[$phone] ?? '';
$status = ($reply === 'yes' || $reply === 'no') ? $reply : 'pending';
```

أي: لا يوجد صف RSVP إطلاقاً، أو يوجد صف لكن `reply` ليس `yes` ولا `no` بالضبط
→ `pending`. **لا** علاقة بـ`checked_in`. `all` = كل المدعوين الصالحين
(أرقام غير قابلة للتطبيع تُستبعَد وتُحسَب في `skipped_invalid_phone`). الـ
Resolver يُطبِّع كل رقم بـ`pge_norm_phone()` (لا Normalizer جديد) ويزيل
التكرار (`$seen[$norm_phone]`) بحيث لا يمكن لنفس الرقم أن يظهر مرتين في نفس
نتيجة `resolve()` بصرف النظر عن شكل التخزين المصدر.

بعد Phase B، خريطة RSVP تكشف أيضاً أي خرق تاريخي لعقد Option A (أكثر من صف
لنفس `event_id + normalized_guest_phone`) عبر `integrity_errors`. الـResolver
لا يختار أول/آخر صف ولا يرسل Reminder لذلك الهاتف؛ يستبعده من `pending` و`all`
ويحسبه في `skipped_integrity_error`. لا تتغير بقية فلاتر Reminder، ولا علاقة
لهذا الحارس بـCheck-in أو Credits أو Invitation queue.

#### Phase 4B-1 — أهلية مستلمي Thank You (IMPLEMENTED، قراءة فقط)

عند `message_type=thank_you` يبدأ الـResolver حصراً من خريطة الدعوات الحالية
`pge_event_guests_get_map()`؛ لذلك لا تتسرب صفوف RSVP اليتيمة بعد Hard Delete أو
Phone Change. تُستبعد الدعوة ذات `invitation_status=cancelled` لأن الإلغاء إبطال
إداري للدعوة الحالية، حتى لو بقي صف RSVP تاريخي بقيمة `checked_in=1`.

لكل هاتف حالي نشط يُستدعى `pge_rsvp_find_canonical_by_phone()`:

- `not_found` أو `rsvp_id` غير صالح: غير مؤهل ويُحسب في `skipped_no_rsvp`.
- `integrity_error`: Fail closed بلا اختيار صف اعتباطي، ويُحسب في
  `skipped_integrity_error`.
- `found`: يجب أن يجتاز الصف عقد الدورة المركزية
  `PGE_Invitation_Repository::is_rsvp_row_current()`، وإلا يُحسب في
  `skipped_stale_lifecycle`.
- بعد ذلك فقط تكون الأهلية عند `checked_in=1`. قيمة `reply` (`yes`/`no`/pending)
  لا تدخل في القرار إطلاقاً، ولا يجوز استعمال RSVP=yes بديلاً عن Check-in.

حمولة كل مستلم المؤهل هي `phone` + الـ`rsvp_id` canonical + `name`. لا يفحص
الـResolver قيمة `thank_you_sent_at` عمداً: هذه طبقة eligibility فقط، بينما
`PGE_Thank_You_Claim` تبقى السلطة الوحيدة لقرار once-per-guest/event ضمن دورة
الدعوة الحالية. لا Claim ولا Log ولا إرسال ولا كتابة قاعدة بيانات ولا Lifecycle
reset في هذه الطبقة. الملخص يعيد `total_current_invitations` و`eligible` وعدادات
الاستبعاد: invalid phone، cancelled، not checked in، no RSVP، integrity error،
stale lifecycle.

الاختبار المركزي `tests/test-thank-you-recipient-resolver-phase4b1.php` يحمّل
الـResolver الإنتاجي ويثبت الأهلية وعزل المناسبات وHard Delete/Reinvite وPhone
Change وFail-closed عند النزاهة والدورة القديمة، مع إثبات عدم تنفيذ أي كتابة.

### 5.3 `PGE_Reminder_Message_Service` — تنسيق العملية الكاملة

**الملف:** `includes/class-pge-reminder-message-service.php` (جديد).

نقطة دخول وحيدة: `send_reminder_batch(event_id, filter, actor_user_id,
include_image=false)`.
مسؤولة عن: التحقق من المناسبة، حل المستلمين عبر الـResolver، توليد `batch_id`
عبر `PGE_Message_Batch` (خادمياً بالكامل)، إنشاء صفوف `pending` في
`PGE_Message_Log` (`message_type=reminder`)، بناء المحتوى لكل ضيف عبر
`PGE_Message_Content_Resolver::resolve(PGE_Message_Type::REMINDER, ...)`
(بلا Template Engine جديد)، الإرسال عبر `PGE_Cartat_Transport::send_text()` أو
`send_media()` عند طلب Featured Image صالحة
(الطبقة المشتركة نفسها، بلا نسخ HTTP/auth/payload)، تفسير النتيجة عبر
`interpret_result()` الحالية، تحديث `PGE_Message_Log`، وإعادة ملخّص.

**Batch Idempotency (PART 11) — حماية خادمية حقيقية**: قفل `GET_LOCK`
قصير العمر مفتاحه `pge_reminder_op_{md5(event_id)}` يُحجَز *فقط* أثناء خطوة
"حل المستلمين + إنشاء صفوف `pending`" (ملّي ثوانٍ)، لا خلال الإرسال الفعلي
البطيء — طلب متزامن ثانٍ (Double-click أو تبويبان) يصل والقفل محجوز يُرفَض
فوراً بـ`operation_in_progress` *قبل* إنشاء أي `batch_id`/صف تتبّع، فلا يمكن
لضغطتين متزامنتين إنشاء دفعتين تُرسلان لنفس المستلمين. هذا مستقل تماماً عن
تعطيل الزر في JavaScript (الذي يبقى موجوداً كطبقة UX إضافية فقط).
**Reminder ليس Once-per-event**: لا `reminder_sent_at` على RSVP، عملية جديدة
مقصودة (زر إرسال آخر لاحقاً) تحصل على `batch_id` مختلف وتُرسِل من جديد بلا
منع — الحماية أعلاه per-operation فقط، وليست منعاً دائماً للمدعو.

### 5.4 Large Batches / Queue Strategy / Queue Collision (PART 25-27)

**القرار المعماري**: فُحِص `cron_process_queue()` الحالي
(`class-cartat-handler.php`) فوُجِد مُطعَّماً بعمق بمنطق Invitation Credit
Ledger/Replacement Entitlements في كل فرع تقريباً — تمديده لدعم
`message_type=reminder` (المُمنَع صراحة من لمس أي رصيد) كان يعني إما تفريعاً
خطراً داخل دالة حسّاسة صُلِّبت عبر مراحل عديدة، أو خطر تسرّب منطق رصيد لمسار
لا يعرف عن الرصيد شيئاً — "Refactor كبير" بالضبط كما حذَّر التكليف. **الخيار
المُختار (الأقل مخاطرة)**: طابور مستقل تماماً بلا أي مشاركة حالة مع
`pge_wa_queue_{event_id}` — لا `wp_options` جديد بحالة موازية إطلاقاً؛ حالة
"الطابور" *هي* صفوف `message_log` نفسها (`status=pending` لكل `batch_id`).
Lock names مختلفة كلياً (`pge_reminder_op_*` / `pge_reminder_tick_*`)، Cron
hook مختلف (`pge_wa_process_reminder_queue`). هذا يحقق "لا تعارض" بنيوياً —
لا يوجد أي مفتاح/متغيّر مشترك بين مسار الدعوة الحالي وهذا الطابور، فلا يمكن
لأحدهما محو الآخر أو خلط النتائج أو استهلاك رصيد الآخر بالتصميم، ولا حاجة
لمنع تشغيل متوازٍ لأنه لا يوجد تشارك حالة يستدعي المنع أصلاً.

**Sync/Cron hybrid**: أول 25 مستلماً (`SYNC_CHUNK_SIZE`) يُعالَجون فوراً ضمن
نفس طلب الـAJAX (`set_time_limit(120)`، نفس سقف مسار الدعوة). أي متبقٍّ
يُكمَل عبر WP-Cron بدفعات 15 (`CRON_CHUNK_SIZE`) كل 25 ثانية
(`wp_schedule_single_event()` + `spawn_cron()`) حتى تفرغ صفوف `pending` لهذا
الـ`batch_id` — لا Loop ضخمة عمياء داخل طلب واحد أبداً. Cron الوحيد المُضاف
هو استكمال دفعة بدأها المستخدم يدوياً — لا مسح تلقائي مجدول بالتاريخ.
ينتقل `include_image` إلى Cron كـBoolean فقط (والغياب القديم = `false`)؛ ويُعاد
حل Featured Image خادمياً في كل tick، لذلك حذفها يجعل المتبقي Text Only.

### 5.5 نقطة الـAJAX والصلاحيات

**الملف:** `includes/invitation-management-ajax.php` (مُعدَّل — لا ملف جديد).

أربعة معالجات جديدة تحت ثابت تفعيل واحد (`PGE_INVITATION_MGMT_REMINDER_ENABLED`،
بنفس نمط `RESEND_QR_ENABLED`/`EXPORT_ENABLED` الحالية):

| Action | الوصف |
|--------|-------|
| `pge_invitation_mgmt_reminder_preview` | معاينة: عدد المستلمين المتوقَّع + النص + توفر Featured Image ورابط عرضها — قراءة فقط |
| `pge_invitation_mgmt_save_reminder_template` | حفظ `_pge_wa_tpl_reminder` (Sanitize + طول أقصى 2000 حرف) |
| `pge_invitation_mgmt_send_reminder` | نقطة الإرسال الفعلي — تستقبل **فقط** `nonce`/`event_id`/`filter` و`include_image=0/1` |
| `pge_invitation_mgmt_reminder_status` | تقرير تقدّم دفعة (`batch_id`) — Sent/Failed/Ambiguous/Pending، للـPolling |

**الخادم authoritative بالكامل**: لا الهاتف ولا `batch_id` ولا عدد المستلمين
يُقبَل من العميل في `pge_invitation_mgmt_send_reminder`، ولا يُقبل أي
`image_url`/`media_url`/file path؛ العميل يرسل intent فقط والصورة تُحل من
Featured Image على الخادم — `batch_id` يُولَّد
خادمياً عبر `PGE_Message_Batch`، المستلمون يُحسَبون خادمياً عبر الـResolver،
القالب يُقرَأ خادمياً من Post Meta. **الصلاحيات**: نفس
`pge_mgmt_validate_request()` الحالي بالضبط (`is_user_logged_in()` → nonce
`pge_event_manage_nonce` → `get_post_type()==='pge_event'` →
`pge_event_guests_user_can_manage()`) — لا منطق تفويض جديد. جلسات Supervisor
منفصلة تماماً (كوكي مختلف، لا `is_user_logged_in()` WordPress) فلا يمكنها
اجتياز هذا التحقق بنيوياً — لا اعتماد على إخفاء الزر في الواجهة.
**`reminder_message` Baseline في كل الباقات** — لا استدعاء لـ
`pge_user_has_feature()` أو أي Tier/Catalog Feature Gate في كامل مسار
Reminder؛ الثابت `PGE_INVITATION_MGMT_REMINDER_ENABLED` هو تفعيل مرحلي
للكود نفسه فقط، لا علاقة له بأي باقة/مستوى.

### 5.6 حفظ القالب (`_pge_wa_tpl_reminder`)

تدقيق شامل قبل الإضافة أثبت عدم وجود أي مسار حفظ لأي قالب واتساب
(`_pge_wa_tpl_*`) في المشروع كله سابقاً — القراءة فقط كانت موجودة
(`pge_wa_get_templates()`/`pge_wa_render_template()` من Phase 1، بلا تغيير).
لذا أُضيف مسار حفظ جديد صغير (`pge_invitation_mgmt_save_reminder_template`)
بدل توسيع نقطة غير موجودة أصلاً، بنفس مستوى Sanitize/Authorization المتّبع
في بقية الملف.

### 5.7 الواجهة (`templates/event-invitations.php`)

زر "🔔 إرسال تذكير" بجانب أزرار إدارة الدعوات الحالية (نفس تنسيق الأزرار
المجاورة). Modal بسيط بثلاث حالات: **Setup** (اختيار المستهدفين Radio
pending/all، عدد المستلمين المتوقَّع Estimate، نص القالب القابل للتعديل،
معاينة)، **Sending** (Spinner + تعطيل الإرسال/الإغلاق أثناء الطلب — طبقة UX
فقط، الحماية الحقيقية خادمية)، **Result** (إجمالي المستهدفين/تم الإرسال/تعذّر/
تم التخطي، بلا أي API errors أو Raw Provider Response). عدد المستلمين
المعروض في Setup **تقديري**؛ العدد النهائي المُستخدَم فعلياً في الإرسال
يُعاد حسابه خادمياً عند الضغط على "إرسال" (لا اعتماد على DOM). عند دفعة
كبيرة تُكمَل عبر Cron، الواجهة تستطلع (`poll`) `pge_invitation_mgmt_reminder_status`
كل 4 ثوانٍ حتى الاكتمال.

### 5.8 الإثبات التنفيذي

`tests/test-messaging-phase3.php` (46 فحصاً، عبر نفس مُفسِّر PHP 8.3 الحقيقي)
يُثبت: تعريف `pending`/`all` مطابق للنظام الفعلي (لا RSVP/reply≠yes/no
→pending، yes/no مستبعدان، أرقام غير صالحة/مكرَّرة/من مناسبة أخرى مستبعدة)،
`message_type=reminder` فعلياً، القالب المخصَّص يُستخدَم مع fallback للافتراضي،
المتغيّرات تُصيَّر، وReminder يبقى Text افتراضياً أو يستخدم Featured Image
اختيارياً، والإرسال عبر `PGE_Cartat_Transport`
فعلياً (لا UltraMsg)، `batch_id` يُولَّد خادمياً ومختلف بين عمليتين، لا تكرار
داخل نفس الدفعة، `sent`/`failed`/`ambiguous_transport_error` تُسجَّل بدقة حسب
نتيجة Transport الحقيقية، لا تخزين لنص الرسالة في `message_log`، طلب متزامن
أثناء إنشاء دفعة أخرى يُرفَض ثم ينجح بعد تحرّر القفل، معالجات AJAX الحقيقية
(nonce خاطئ/مناسبة ليست ملكه/غير مسجّل/Supervisor-مكافئ/هاتف أو batch_id أو
عدّادات من العميل) كلها تُرفَض أو تُتجاهَل فعلياً، وInvitation Credit
Ledger/Replacement Entitlements غير محمَّلتين إطلاقاً في هذا المسار (فحص
بنيوي: الكلاسات غير معرَّفة). اختبار دفعة واقعية بـ120 مستلماً يُثبت: لا
تكرار في التتبّع، الدفعة الأولى المتزامنة محدودة بـ`SYNC_CHUNK_SIZE`،
المتبقّي يُكمَل عبر Cron محاكى، 120 صف تتبّع بلا تكرار، لا استهلاك رصيد.
(تأخير مكافحة الحظر التشغيلي بين الرسائل — `usleep` — عُطِّل حصراً لهذا
الاختبار عبر seam اختباري مخصَّص `set_send_delay_enabled_for_tests()`؛ راجع
"الانحراف عن العقد" في التقرير النهائي.)

---

## 6. Phase 4B-2 — Manual Thank You Service (IMPLEMENTED داخلياً)

**الملف:** `includes/class-pge-thank-you-message-service.php`.

نقطة الدخول الداخلية:

```php
PGE_Thank_You_Message_Service::send_thank_you_batch(
    int $event_id,
    int $actor_user_id
): array
```

المسار ثابت: `Thank You Resolver` → `PGE_Thank_You_Claim::claim()` →
`PGE_Message_Content_Resolver` → `PGE_Cartat_Transport::send_text()` →
`finalize_success()` أو `finalize_failure()`. الخدمة لا تعيد تطبيق canonical
lookup أو Check-in/Cancel/Lifecycle eligibility؛ الـResolver هي السلطة الوحيدة
لذلك. كما لا تفحص `thank_you_sent_at`؛ الـClaim تبقى سلطة once-only الوحيدة
وتستمد `lifecycle_started_at` من Current RSVP داخل القفل.

الإرسال **Cartat Text Only**: لا `send_media()`، ولا UltraMsg/fallback، ولا HTTP
أو Auth داخل الخدمة. المحتوى يأتي من فرع `thank_you` الموجود في Content
Resolver باستخدام `_pge_wa_tpl_thank_you` مع fallback إلى
`pge_wa_default_thank_you_template()`، والمتغيرات المعتمدة فقط:
`guest_name`, `event_name`, `event_date`. لا endpoint لحفظ القالب في هذه المرحلة.

دلالات النتائج:

- `accepted`: `finalize_success(log_id, rsvp_id)`؛ وهي وحدها تثبت
  `thank_you_sent_at`. إذا تعذر تثبيت النتيجة محلياً بعد قبول النقل، تُحاط
  المحاولة بحالة ambiguous بدلاً من الادعاء بنجاح غير مثبت أو retry فوري.
- `rejected`: `finalize_failure(..., failed)`؛ لا تُكتب علامة الإرسال ويمكن
  لمحاولة يدوية لاحقة المطالبة من جديد.
- `transport_error` أو Exception بعد بدء استدعاء النقل:
  `finalize_failure(..., ambiguous_transport_error)`؛ لا يُعد نجاحاً ولا يعاد
  الإرسال داخل نفس الخدمة، وتمنع Lease الحالية retry الفوري حتى انقضاء 120 ثانية.
- `already_sent` و`already_in_progress` يتخطيان النقل ولا يُصنفان كفشل Provider.
  خطأ Claim مستقل في `skipped_claim_error` ولا يوقف معالجة بقية المستلمين.

الملخص الثابت يعيد: `result`, `batch_id`, `eligible`, `claimed`, `sent`,
`failed`, `ambiguous`, `skipped_already_sent`, `skipped_active_claim`,
`skipped_claim_error`، ومعه Resolver summary بلا قائمة الهواتف. كل مستلم يعالج
بشكل مستقل؛ rejection أو Claim skip لا يوقف بقية المجموعة. عقد
`send_thank_you_batch()` المتزامن محفوظ للتوافق والاختبارات، بينما orchestration
الجديدة للأحجام الفعلية async-only في Phase 4B-2.6 وتعيد استخدام مسار المستلم
الواحد دون نسخ منطق الإرسال.

لا Invitation/Replacement Credits ولا `credit_type`، ولا كتابة مباشرة في
RSVP أو Message Log من الخدمة؛ كل كتابة تمر عبر Claim. لا logging لأرقام
الهواتف أو نص الرسالة أو بيانات Cartat. لا AJAX أو UI أو صلاحيات مستخدم هنا؛
هذه Service داخلية بلا Caller HTTP حتى الآن.

`tests/test-thank-you-message-service-phase4b2.php` يحمّل Service وContent
Resolver الإنتاجيين ويثبت accepted/rejected/ambiguous/exception، once-only،
Lease، lifecycle reuse، Phone Change، حالات الاستبعاد القادمة من Resolver،
partial failure، Text-only/Cartat-only، وعدم استخدام Credits أو DB/HTTP مباشر.

---

## 6.1 Phase 4B-2.6 — Durable Thank You Batch/Worker (IMPLEMENTED داخلياً)

هذه المرحلة تجعل تشغيل Manual Thank You **async-only** بحد مزامنة يساوي صفراً.
واجهة الإنشاء الداخلية هي
`PGE_Thank_You_Batch_Worker::create_batch(event_id, actor_user_id)`، ولا تقبل
قائمة مستلمين من العميل ولا ترسل أثناء الإنشاء. إذا لم يوجد مستلم مؤهل تعيد
`no_eligible` بلا Manifest أو Cron chain. بقيت هذه المرحلة الداخلية بلا Caller؛
أضيف AJAX في Phase 4B-3A والواجهة المستهلكة له في Phase 4B-3B دون تغيير العامل.

`PGE_Thank_You_Batch_Store` تحفظ Manifest دائمة في WordPress options منفصلة
غير autoloaded، مع Active Batch index مستقل لكل مناسبة بلا مسح شامل للخيارات.
هوية الدفعة UUID v4 مولدة خادمياً. تحفظ الدفعة `event_id` و`actor_user_id`
والحالة والأوقات، وتحفظ لكل عنصر فقط `rsvp_id + lifecycle_started_at` وحالة
الصف؛ لا هاتف، ولا اسم، ولا نص رسالة، ولا بيانات Cartat أو أسرار. Queue state
ليست Message Log: `queued` لا تنشئ Claim أو سجل pending.

حالات العناصر هي `queued`, `processing`, `waiting`, `sent`, `failed`,
`ambiguous`, `skipped`، وحالات الدفعة `active` و`complete`. الملخص الداخلي
يعيد العدادات والأوقات وسبب skip بلا PII، وتبقى `waiting` قابلة للمعالجة ولا
تُكمل الدفعة. `failed` و`ambiguous` نهائيتان داخل الدفعة الحالية ولا يعاد
إرسالهما تلقائياً.

الـWorker مسجل على `pge_process_thank_you_batch` ويعالج بحد أقصى **4** عناصر
في كل tick؛ لا توجد initial synchronous chunk ولا `sleep`. التأخير بين ticks
25 ثانية، وهو continuation/recovery داخلي لدفعة بدأها المستخدم وليس ميزة
Automatic Thank You. Watchdog مستقل على `pge_watchdog_thank_you_batch` كل 120
ثانية يعيد إنشاء tick المفقودة، كما أن tick التي تجد القفل مشغولاً تعيد جدولة
نفسها.

قبل كل Claim يعيد العامل استخدام targeted helper في Recipient Resolver للتحقق
من الدعوة الحالية غير الملغاة، canonical RSVP، تطابق `rsvp_id`، تطابق lifecycle
marker، و`checked_in=1`. لذلك Hard Delete أو Phone Change أو Cancel أو lifecycle
reset أو check-in reset بعد إنشاء الدفعة ينتج `skipped` بلا Claim ولا Send، ولا
يمكن لدفعة قديمة إرسال lifecycle جديدة.

المطالبة Just-in-Time فقط. العامل يعيد استخدام
`PGE_Thank_You_Message_Service::process_recipient()`، وهي المسار الوحيد لـClaim
ثم Content Resolver ثم Cartat `send_text()` ثم finalize؛ لا Transport logic
منسوخة في العامل. `already_sent` يصبح `skipped`، وactive Claim تصبح `waiting`
حتى إعادة التقييم بعد lease الحالية (120 ثانية)، وreclaim يبقى من مسؤولية
`PGE_Thank_You_Claim` الحالية.

Operation lock لكل event يمنع إنشاء دفعتين نشطتين، وtick lock لكل
`event_id + batch_id` يحمي كل read-modify-write للـManifest. العامل يحجز أربعة
عناصر ويحرر tick lock قبل أي اتصال HTTP. Processing lease تساوي 120 ثانية؛
انهيار العامل قبل Claim يعيد العنصر إلى queued بعد انتهائها، والانهيار بعد Claim
يحترم lease/reclaim الحالية. إذا ضاعت كتابة النتيجة بعد النقل، تبقى حدود
exactly-once غير قابلة للحسم دون idempotency من المزود؛ لذلك تُحترم
`ambiguous_transport_error` ولا يوجد retry تلقائي داخل الدفعة.

لا Credits أو `credit_type`، لا Media/UltraMsg، لا Schema/DB queue table، ولا
تغيير في Reminder أو Invitation Queue أو RSVP/QR/Check-in أو Salla/Catalog.

اختبار `tests/test-thank-you-batch-worker-phase4b26.php` يغطي الإنشاء، الخصوصية،
الأقفال، الاسترداد، lifecycle fencing، نتائج النقل، والدفعات 25/100/400 مع حد
أربعة عناصر لكل tick باستخدام fakes بلا HTTP حقيقي.

---

## 6.2 Phase 4B-3A — Manual Thank You AJAX (IMPLEMENTED، Server API)

**الملف:** `includes/invitation-management-ajax.php`. أضيفت ثلاثة authenticated
WordPress AJAX actions فقط:

- `pge_invitation_mgmt_thank_you_preview`
- `pge_invitation_mgmt_thank_you_start`
- `pge_invitation_mgmt_thank_you_status`

لا توجد `nopriv` actions. تمر العمليات الثلاث حرفياً عبر
`pge_invitation_mgmt_validate_request()`، وبالتالي تستخدم نفس login وnonce
`pge_event_manage_nonce` وملكية/صلاحية إدارة المناسبة المعتمدة لبقية صفحة إدارة
الدعوات. صلاحية المناسبة تكفي؛ لا يوجد actor restriction موازٍ، لكن Status
تتحقق قطعاً أن Manifest تنتمي إلى `event_id` المصرح بها قبل إعادة الملخص.

Preview تقبل `nonce + event_id` فقط منطقياً، وتعيد عدادات Thank You Resolver
المسموح بها مع sample text عام من Content Resolver باستخدام event context وضيف
عام. لا تعيد recipients أو phones أو RSVP/lifecycle identities، ولا تنشئ Batch
أو Claim ولا ترسل.

Start تقبل `nonce + event_id` فقط وتستدعي
`PGE_Thank_You_Batch_Worker::create_batch()` مع current user. `batch_id` مولدة
خادمياً، ولا synchronous Send أو Claim. تعيد `batch_id + status + existing`؛
إذا كانت هناك دفعة نشطة تعيد نفس الدفعة بنجاح مع `existing=true` لتسهيل
idempotency، ولا تنشئ دفعة ثانية. أي recipient list أو phone أو rsvp/lifecycle
identity أو batch_id أو message/image/provider/credit field من العميل مهمل ولا
يصل إلى authority.

Status تقبل `nonce + event_id + batch_id`، تقرأ Manifest للتحقق من الانتماء ثم
تعيد Summary whitelist فقط: `batch_id`, `total`, `queued`, `processing`,
`waiting`, `sent`, `failed`, `ambiguous`, `skipped`, skipped breakdown،
`complete`, `started_at`, `updated_at`. لا تعيد Raw Manifest أو PII أو نصوصاً
أو أسراراً. هي read-only تماماً: لا Worker tick، لا Claim/Send، لا Cron أو
watchdog reinforcement؛ استمرار الدفعة يبقى مستقلاً عبر Worker/Watchdog حتى لو
أُغلقت الصفحة.

أسباب الخطأ الخاصة بالسطح الجديد: `invalid_nonce`, `not_logged_in`,
`invalid_event`, `forbidden`, `no_eligible`, `batch_in_progress`,
`missing_batch_id`, `batch_not_found`, `batch_event_mismatch`,
`no_provider_credentials`, `batch_persistence_failed`, و`internal_error` بلا
تسريب تفاصيل الاستثناء. لا endpoint لحفظ قالب Thank You ولا Retry. كانت هذه
المرحلة خادمية فقط؛ الواجهة وpolling أضيفتا لاحقاً في Phase 4B-3B (§6.3).

`tests/test-thank-you-ajax-phase4b3a.php` يستدعي المعالجات الإنتاجية ويغطي
التفويض، المدخلات المزورة، async-only start، summaries، العزل، الخصوصية، وحالة
القراءة فقط باستخدام fakes بلا Transport أو DB.

---

## 6.3 Phase 4B-3B — Manual Thank You UI (IMPLEMENTED)

**الملف:** `templates/event-invitations.php`. أضيف زر «إرسال شكر للحاضرين» قرب
أداة Reminder الحالية، وModal مستقلة بست حالات: تحميل المعاينة، جاهزة، بدء
الدفعة، المعالجة، الاكتمال، والخطأ. التدفق الوحيد هو:

```text
Preview → Start → Async Processing → Poll Status → Result
```

فتح النافذة يستدعي Preview بـ`nonce + event_id` عبر helper الصفحة الحالي،
ويعرض عدد المؤهلين ونص المعاينة العام read-only. لا توجد فلاتر أو اختيار
مستلمين، و`eligible=0` حالة فارغة واضحة تعطّل زر البدء وليست خطأ تقنياً.

Start يرسل `nonce + event_id` فقط، يعطّل الزر فوراً ويحمي من double-click، ثم
يتعامل مع الدفعة الجديدة والنشطة المعادة بـ`existing=true` بالعقد نفسه: يستخدم
`batch_id` الخادمية وينتقل إلى المعالجة بلا خطأ. لا ترسل الواجهة recipient أو
phone أو RSVP/lifecycle identity أو message text أو provider أو credit values.

Status polling يحدث كل 4 ثوانٍ بـ`nonce + event_id + batch_id`. يعرض للمستخدم
`total/sent/failed/ambiguous/skipped` بنصوص مبسطة، ويفصل الفشل المؤكد عن حالة
تعذر التأكيد. يتوقف polling عند `complete=true`، أو إغلاق النافذة، أو خطأ خادمي
نهائي، أو مغادرة الصفحة، مع منع duplicate timers. إغلاق النافذة لا يوقف Worker؛
عند إعادة الفتح تبدأ Preview جديدة، وإذا كانت دفعة نشطة يعيدها Start وتُستأنف
متابعتها.

الردود تُعرض عبر حقول whitelist و`textContent` فقط بلا PII أو Raw Manifest أو
أسباب skip التقنية. لا Credits UX، لا قالب قابل للتعديل، لا Media، لا Retry،
ولا endpoint رابع. `tests/test-thank-you-ui-phase4b3b.php` يثبت بنية الواجهة
وعقود JavaScript والخصوصية واستقلال state عن Reminder دون HTTP أو Transport.

---

## 7. غير منفَّذ بعد (NOT IMPLEMENTED YET)

- Retry UI لـManual Thank You غير منفذة؛ لا retry endpoint أو إعادة محاولة
  تلقائية من المتصفح.
- أي جدولة تلقائية (Automatic Reminder/Cron يومي بحسب تاريخ المناسبة) —
  الوحيد المسموح هو Cron استكمال دفعة Reminder بدأها المستخدم يدوياً (§5.4).
- Lease/Reclaim لمطالبات Thank You العالقة نُفّذ في Phase 4A-2 (§4.3)
  وتستهلكه Service Phase 4B-2 والـWorker الداخلية في Phase 4B-2.6 عند إطلاق
  الدفعة يدوياً من واجهة Phase 4B-3B.
- شرط عدم توريث `thank_you_sent_at` حُسم في Phase C عبر reset دورة الدعوة
  authoritative (راجع §4.5). لا يعني ذلك أن إرسال Thank You بدأ.
- Recipient Resolver لـ`checked_in=1` نُفّذ قراءة فقط في Phase 4B-1 (§5.2)
  وتستهلكه Service Phase 4B-2 دون إعادة تطبيق الأهلية.
- أي Retry UI أو إدارة يدوية لإعادة محاولة نتائج Thank You.
- إرسال Reminder عبر UltraMsg — Cartat فقط في Phase 3.
- ربط `message_type` ببنية الـQueue الحالية للدعوة (`$queue['message_type']`)
  — مؤجَّل، راجع §2.5. لا علاقة له بـ`pge_message_log` (مستقل تماماً، ومستقل
  أيضاً عن طابور Reminder الجديد في §5.4).
- استخدام `pge_message_log` فعلياً لتتبّع إرسال الدعوة (Invitation) — غير
  مُنفَّذ عمداً (راجع PART 9 من تكليف Phase 2: لا كتابة من مسار الدعوة
  الحالي إلى `message_log`).
