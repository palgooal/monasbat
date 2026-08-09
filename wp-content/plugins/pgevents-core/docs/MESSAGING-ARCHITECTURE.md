# نظام رسائل المناسبة (Messaging Architecture) — Phase 0 + Phase 1

> يوثّق هذا الملف **فقط** ما تم اعتماده (Phase 0 — Contract) وما تم تنفيذه فعلياً
> في الكود (Phase 1 — Refactor داخلي بلا تغيير سلوك). لا يوثّق تفاصيل مراحل
> مستقبلية (Reminder/Thank You الفعليَّين، الجدولة، التتبّع) إلا كقائمة "غير
> منفَّذ بعد" مختصرة — راجع تقرير Architecture Audit وتقرير Phase 0 للتفاصيل
> الكاملة لتلك المراحل عند بدء تنفيذها فعلياً.

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

## 4. غير منفَّذ بعد (NOT IMPLEMENTED YET)

- إرسال Reminder فعلياً (لا AJAX، لا Queue، لا زر واجهة).
- إرسال Thank You فعلياً (لا AJAX، لا Queue، لا زر واجهة).
- أي جدولة تلقائية (Cron) لـReminder أو Thank You.
- جدول/آلية تتبّع (Tracking) لحالة إرسال Reminder/Thank You لكل ضيف
  (`thank_you_sent_at` أو ما يعادله) — غير موجود بعد.
- Idempotency الفعلية لـThank You (منع التكرار لكل ضيف لكل مناسبة) — القرار
  معتمد من حيث المبدأ (Phase 0) لكن غير مُنفَّذ في قاعدة البيانات.
- منع التكرار المزدوج لـReminder (قفل عملية إرسال واحدة) — غير مُنفَّذ.
- Recipient Resolver الفعلي لتحديد "غير المستجيبين" أو "كل المدعوين"
  لـReminder، أو `checked_in=1` لـThank You — غير مُنفَّذ.
- أي واجهة مستخدم (UI) لإطلاق Reminder/Thank You من `event-invitations.php`
  أو أي مكان آخر.
- ربط `message_type` ببنية الـQueue (`$queue['message_type']`) — مؤجَّل، راجع
  §2.5.
