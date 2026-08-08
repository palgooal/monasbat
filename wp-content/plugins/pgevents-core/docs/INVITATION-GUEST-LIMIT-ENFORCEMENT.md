# Guest Limit Unification — Invitation Record Quota Enforcement

> يوثّق هذا الملف التوحيد الكامل لإنفاذ "حد المدعوين" (Guest Limit) عبر كل
> مسارات إنشاء الدعوة في النظام، بعد Architecture Audit أثبت أن استيراد
> Excel كان المسار الوحيد الذي يفرضه فعلياً. آخر تحديث: 2026-08-08.

---

## 1. معنى "guest_limit" هنا — Invitation Record Limit

هذا المستند يوثّق مفهوماً واحداً محدَّداً بدقة: **سقف عدد سجلات الدعوة**
(صفوف `_pge_invited_guests`) المسموح بها لمناسبة واحدة، مقارنةً بحد
`guest_limit` المخزَّن في `_mon_guest_limit` (User Meta) والمُقروء عبر
`pge_get_user_plan_limits_for_events($author_id)['guest_limit']`.

**هذا مختلف تماماً وعمداً** عن استخدام آخر منفصل لنفس القيمة المصدرية في
`rsvp-handler.php`، الذي يقيس **سعة الحضور المؤكَّد (Confirmed-Attendance
Capacity)** — عدد ردود RSVP "نعم" المسموح بها. لا علاقة تنفيذية بين
المفهومين (لا دالة مشتركة، لا قراءة/كتابة مشتركة). راجع القسم 8 أدناه.

---

## 2. نقطة الإنفاذ الموحَّدة

**`PGE_Invitation_Service::create($event_id, $phone, $name, $note, $actor_user_id)`**
(`includes/class-pge-invitation-service.php`) هي نقطة الإنفاذ الوحيدة
لقاعدة العمل "Guest Limit" في كامل النظام. كل مسارات الإنشاء الرسمية تمر
بها ولا شيء غيرها:

| المسار | نقطة الدخول |
|--------|-------------|
| الإضافة اليدوية | `pge_invitation_mgmt_create_handler()` في `invitation-management-ajax.php` |
| Bulk Add | `PGE_Invitation_Bulk_Add_Service::confirm()` في `class-pge-invitation-bulk-add.php` |
| استيراد Excel | `pge_invitation_mgmt_excel_confirm_handler()` في `invitation-management-ajax.php` |

الحصة تُحسَب عبر `pge_resolve_guest_quota_status($event_id)` (موجودة أصلاً
في `helpers.php`) — **لم يُضَف أي حساب موازٍ جديد**، فقط أُعيد استخدامها من
داخل `Service::create()` بدل حصرها في مسار Excel وحده.

---

## 3. استراتيجية القفل (Concurrency Lock)

كل استدعاء لـ`Service::create()` يُغلَّف بقفل MySQL `GET_LOCK`/`RELEASE_LOCK`
واحد **لكل مناسبة** (`event_id`)، بنفس النمط المُثبَت فعلياً في
`PGE_Invitation_Credit_Ledger::claim_for_delivery()` وEvent Quota
(`event-factory.php`, Commit 6):

```
اسم القفل: pge_invitation_create_<md5(event_id)>
مهلة الانتظار: 5 ثوانٍ
```

**التسلسل داخل القفل:** إعادة حساب `pge_resolve_guest_quota_status()` طازجة
← فحص الحصة ← `PGE_Invitation_Repository::create()` (يتضمن فحص التكرار
والكتابة الفعلية) ← تسجيل التدقيق عند النجاح ← **`RELEASE_LOCK` في
`finally`** (يُنفَّذ دائماً — نجاحاً أو فشلاً، بما في ذلك أخطاء التحقق
الداخلية مثل `invalid_name`).

**ملكية القفل:** `Service::create()` هي الطبقة الوحيدة التي تحصل على هذا
القفل. `PGE_Invitation_Repository::create()` لا تحصل على أي قفل بنفسها (لم
تُعدَّل إطلاقاً) — فلا قفل متداخل (Nested Lock) ولا احتمال Deadlock ذاتي.

**الضمانات المُثبَتة تنفيذياً** (`tests/test-guest-limit-unification.php`,
قسم E):
- طلبان يتنافسان على مقعد واحد أخير → نجاح واحد فقط، لا تجاوز للحد.
- نفس الهاتف من طلبين متتاليين → دعوة واحدة فقط (لا ازدواج).
- هاتفان مختلفان ضمن حصة كافية → كلاهما ينجو معاً (لا Lost Update).
- القفل يُحرَّر دائماً: بعد النجاح، بعد التكرار، بعد تجاوز الحصة، وبعد فشل
  تحقّق داخلي.

> **قيد منهجي صريح:** التحقق أعلاه تسلسلي ضمن عملية PHP واحدة (نفس منهجية
> اختبارات Ledger/Event Quota القائمة أصلاً في هذا المشروع) — يُثبت توازن
> القفل والقرار الصحيح تسلسلياً، لا محاكاة حقيقية لاتصالي MySQL منفصلين
> فعلياً (مستحيل ضمن سكربت PHP واحد متتابع).

---

## 4. سلوك الباقات غير المحدودة

`guest_limit <= 0` يعني بلا حد (اصطلاح قائم مسبقاً في `rsvp-handler.php`
وفي `pge_resolve_guest_quota_status()` نفسها، لم يتغيّر). في هذه الحالة
`mode='unlimited'` و`remaining=null`، ولا يحدث أي رفض حصة إطلاقاً بصرف
النظر عن عدد المدعوين الحاليين.

---

## 5. مناسبات تجاوزت الحد مسبقاً

إذا انخفض `guest_limit` لمستخدم لديه مناسبة بها مدعوون أكثر من الحد الجديد
(`current > limit`)، فإن `pge_resolve_guest_quota_status()` تحسب
`remaining = max(0, limit - current) = 0` (منطق قائم مسبقاً، لم يتغيّر).
النتيجة: **أي دعوة جديدة تُرفَض** (`quota_exceeded`)، لكن **السجلات
الموجودة سلفاً لا تُحذَف ولا تُعطَّل إطلاقاً** — هذا التوحيد لا يمس بيانات
قائمة بأي شكل، فقط يمنع الإضافة الجديدة.

---

## 6. قرار المسارات القديمة (Legacy)

`event-guests.php` كان يحوي معالجَي AJAX قديمَين (`wp_ajax_pge_event_guest_add`
و`wp_ajax_pge_event_guest_bulk_add`) يكتبان مباشرة على خريطة المدعوين بلا
أي مرور بـ`PGE_Invitation_Service`/`Repository`/`Audit`، وبلا أي وعي بحصة
المدعوين — الثغرة الوحيدة المتبقية القادرة على تجاوز التوحيد بالكامل.

**الدليل قبل التعطيل (لا افتراض):**
- RC1 Fix Pack 3B (`docs/RC1-AUDIT.md` §16.4) كان قد أزال بالفعل كل عناصر
  الواجهة (`addGuestForm`/`bulkGuestForm`) التي تستدعي هذين الإجراءين — لا
  زر حي يستدعيهما، مُتحقَّق منه تنفيذياً في `tests/test-rc1-fixpack2.php`
  (A4.8/A4.9) و`tests/test-rc1-fixpack3b.php`.
- RC1 Fix Pack 3A نقل الإضافة الجماعية بالكامل إلى
  `PGE_Invitation_Bulk_Add_Service` — لا فجوة وظيفية متبقّية.
- بحث شامل في كامل `wp-content` (PHP + JS + قوالب) لم يُظهر أي مستدعٍ حي
  آخر لهذين الإجراءين سوى التوثيق والاختبارات التاريخية.

**القرار المُنفَّذ:** إلغاء تسجيل الإجراءين (`add_action`) في
`event-guests.php` فقط. **لم تُحذَف أي دالة مساعدة**
(`pge_event_guests_get_map`/`save_map`/`validate_request`/...)، ولم يُلمَس
`pge_event_guest_update`/`_delete`/`_bulk_delete`/`_regen_code` أو أي كود
قراءة/QR في هذا الملف. هذان الإجراءان لم يعودا قابلين للاستدعاء عبر
`admin-ajax.php` بعد الآن — مُتحقَّق منه تنفيذياً في
`tests/test-guest-limit-unification.php` (D14/D15) عبر فحص مباشر لسجل
`add_action` الحقيقي.

---

## 7. تفاعل Excel

- **Preview** (`pge_invitation_mgmt_excel_preview_handler`) لا تزال تحسب
  `limit`/`current`/`remaining`/`will_import` لأغراض عرض الواجهة فقط — بلا
  أي كتابة، وبلا أي تغيير في شكل الاستجابة.
- **Confirm** (`pge_invitation_mgmt_excel_confirm_handler`) لم يعد يحسب
  سقفاً محلياً مجمَّداً (`import_cap`) في بداية الطلب ويستخدمه للتحكم في
  الحلقة — تلك كانت سلطة إنفاذ ثانية موازية لـ`Service::create()`. **كل صف
  valid يستدعي الآن `PGE_Invitation_Service::create()` دون أي بوابة سابقة**،
  وهي وحدها من تقرر النتيجة (`created`/`duplicate`/`quota_exceeded`) بناءً
  على الحالة الحية لحظة ذلك الصف بالذات. شكل الاستجابة النهائية
  (`summary`/`rows`/`quota`) لم يتغيّر — فقط آلية الحساب الداخلية.

---

## 8. الفصل عن دلالات RSVP — لم تُمَسّ إطلاقاً

`rsvp-handler.php` **لم يُعدَّل في هذه المهمة ولا يُعتزَم تعديله هنا**. يوجد
اليوم استخدامان منفصلان تماماً لقيمة `guest_limit` المصدرية نفسها:

1. **سقف سجلات الدعوة (Invitation Record Quota)** — موضوع هذا المستند،
   موحَّد الآن في `PGE_Invitation_Service::create()`.
2. **سعة الحضور المؤكَّد (Confirmed-Attendance Capacity)** — منطق أقدم
   ومستقل بالكامل في `rsvp-handler.php`، يقيس عدد ردود RSVP "نعم"، ولا
   علاقة تنفيذية له بمنطق إنشاء الدعوة أعلاه.

توحيد هذين المفهومين تحت طبقة واحدة (إن رغب المشروع بذلك مستقبلاً) **قرار
معماري/منتجي منفصل تماماً**، خارج نطاق هذه المهمة عمداً.

---

## 9. التغطية الاختبارية

- `tests/test-guest-limit-unification.php` (جديد، 47 حالة) — الإضافة
  اليدوية (دون الحد/عند الحد/بلا حد/مناسبة متجاوزة سلفاً)، Bulk Add
  (Best-Effort مع quota_exceeded)، المسارات القديمة (عدم التسجيل)،
  التزامن/القفل (7 سيناريوهات)، وانحدار مركَّز على edit/cancel/delete/QR.
- `tests/test-excel-import-guest-limit.php` (موجود، مُحدَّث) — يثبت أن
  Excel Confirm يعيد الحساب حياً لحظة كل صف (سيناريو D2: Preview
  `remaining=3`، حالة المناسبة تتغيّر أثناء الانتظار، Confirm يستورد 1 فقط
  فعلياً لا 3 كما أوحت المعاينة).
- `tests/test-rc1-fixpack2.php`, `tests/test-rc1-fixpack3a.php` — تأكيدات
  مُحدَّثة لتعكس إلغاء تسجيل المسارات القديمة وإضافة `quota_exceeded` كدِلو
  خامس في تدقيق Bulk Add الدفعي (تصحيح تأكيدات قديمة أصبحت غير صحيحة
  بتصميم مقصود، بنفس منهجية RC1 Fix Pack 3B §16.5).
- انحدار كامل عبر مجموعات الاختبار القائمة (إدارة الدعوات، التصدير، Event
  Quota، Ledger، RSVP Write Path Unification، Check-in Cancellation).

---

## 10. مخاطر/قرارات منتجية متبقّية

- اللوحة القديمة (`page-event-manage.php`) لا تزال تعرض بطاقة واتساب (قرار
  مستخدم صريح سابق، RC1 Fix Pack 3B §16.1) — لا علاقة لها بإنشاء المدعوين،
  خارج نطاق هذا التوحيد.
- توحيد مفهومَي "سقف سجلات الدعوة" و"سعة الحضور المؤكَّد" (القسم 8) قرار
  مستقبلي منفصل، لم يُتَّخذ هنا.
