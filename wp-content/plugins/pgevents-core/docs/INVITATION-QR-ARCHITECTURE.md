# Invitation QR Architecture — Phase 9B QR Architecture Final Fix

> "QR is an access credential. QR is NOT invitation identity."

هذا المستند هو المرجع المعماري الوحيد لعلاقة الدعوة (Invitation Identity)،
رمز الدعوة اليدوي (`invite_code`)، وحمولة QR الموقَّعة المستخدَمة في ماسح
المشرفين (Scanner QR Credential). كُتب كتصحيح معماري نهائي بعد رفض نهج
سابق (fallback من `resolve_from_qr()` إلى `invite_code` الخام) اعتُبر
"تخفيضاً أمنياً" (authentication downgrade) — راجع قسم التاريخ أدناه.

---

## 1. الفصل المعماري الثلاثي (لا تخلط بينها)

| المفهوم | ماذا يمثّل | أين يُخزَّن | من يستهلكه | يتغيّر متى |
|---|---|---|---|---|
| **Invitation Identity** | سجل الدعوة/الضيف نفسه: اسم، هاتف، ملاحظة، حالة (نشط/مُلغى) | `_pge_invited_guests` (post meta) + `wp_pge_event_rsvps` | كل النظام (RSVP، الحضور، الإحصائيات، الإدارة) | عند تعديل/إلغاء/حذف الدعوة فقط |
| **Manual Invitation Code** (`invite_code`) | رمز مرجعي بشري — للبحث اليدوي والعرض فقط | `_pge_invited_guests[phone]['code']` | البحث اليدوي (`PGE_Guest_Resolution_Service::search()`)، نص التسمية التوضيحية في رسائل واتساب | نادراً، عبر `event-guests.php` (خارج نطاق هذا المستند) |
| **Scanner QR Credential** | بيانات اعتماد وصول موقَّعة لماسح تسجيل الحضور فقط | لا شيء يُخزَّن كنص — يُشتَق حسابياً من `(event_id, rsvp_id, qr_version)`؛ **فقط** `qr_version` يُخزَّن، في `_pge_invitation_status[phone]['qr_version']` | `PGE_Guest_Resolution_Service::resolve_from_qr()` حصراً | عند كل `regenerate_qr()` (يُدوِّر `qr_version` فقط) |

**القاعدة الحاكمة:** `resolve_from_qr()` لا يقبل أبداً `invite_code` الخام —
لا كسقوط احتياطي، لا كصيغة بديلة. البحث اليدوي بـ`invite_code` يستمر حصراً
عبر `PGE_Guest_Resolution_Service::search()`، مساره الحالي غير المُعدَّل.

---

## 2. صيغة الحمولة الكنسية (Canonical Payload Format)

```
event_id|rsvp_id|qr_version|signature
```

أربعة أجزاء مفصولة بـ`|`، بالضبط — لا أكثر ولا أقل:

- **`event_id`** (int موجب) — معرّف المناسبة كما وُلِّد وقت إنشاء QR. بيانات
  فقط، لا سلطة: السلطة الفعلية تأتي حصراً من `event_id` المُصادَق عليه عبر
  جلسة المشرف (`PGE_Supervisor_Portal_Middleware::authorize()`)، المُمرَّر
  كمعامل `$authorized_event_id` في `validate()`.
- **`rsvp_id`** (int موجب) — معرّف صف الضيف في `wp_pge_event_rsvps`. لا
  بيانات ضيف خام (لا اسم، لا جوال) — نفس مبدأ Phase 4 الأصلي.
- **`qr_version`** (int موجب) — بدائيّ التدوير (rotation primitive). ليس
  سرّاً؛ آمن للكشف/التسجيل. يُقارَن مع الإصدار الإداري النشط الحالي لتلك
  الدعوة (خطوة تحقّق منفصلة، راجع القسم 4).
- **`signature`** — `wp_hash(event_id.'|'.rsvp_id.'|'.qr_version.'|'.CONTEXT)`
  حيث `CONTEXT = 'pge_checkin_qr_v2'`. يُغطّي التوقيع الحقول الثلاثة معاً؛
  أي تلاعب بأي جزء (بما فيه `qr_version` نفسه) يُبطِل التوقيع فوراً.

**توليد لحظي بلا تخزين نصي:** لا جدول توكنات جديد — الحمولة الكاملة تُشتَق
حسابياً من `(event_id, rsvp_id, qr_version)` في أي لحظة عبر
`PGE_Checkin_QR_Service::build_payload()`. المُخزَّن الوحيد هو `qr_version`
نفسه (عدد صحيح صغير)، لا الحمولة الموقَّعة.

`CONTEXT` رُقِّم `v2` عمداً (كان `v1` ثلاثي الأجزاء بلا `qr_version`) — أي
حمولة v1 قديمة (نظرياً، لم تكن مطروحة إنتاجياً بعد) تفشل الآن حتماً
كـ`malformed_payload` (عدد أجزاء خاطئ)، لا فك تشفير مزدوج، لا قبول ضمني
لصيغة قديمة.

---

## 3. المولِّد الكنسي الوحيد (Single Canonical Generator)

```php
PGE_Guest_Resolution_Service::build_scanner_qr_payload(
    int $event_id, int $rsvp_id, string $phone
): string
```

**كل منتِج QR في الإنتاج يجب أن يستدعي هذه الدالة حصراً** — لا
`PGE_Checkin_QR_Service::build_payload()` مباشرة (تلك تتطلّب الآن `qr_version`
كمعامل ثالث صريح، ولا تعرف كيف "تجلب" الإصدار الحالي بنفسها؛ فقط المولِّد
الكنسي يعرف ذلك عبر `PGE_Invitation_Repository::get_qr_version()`).

**منتِجو الإنتاج الفعليون (مُحدَّثون في هذا الإصلاح):**

| الملف | الموضع | الاستخدام |
|---|---|---|
| `includes/checkin-ui-ajax.php` | `pge_checkin_ui_reshape_guest()` | مرجع مرشَّح واحد لواجهة البحث اليدوي |
| `includes/class-pge-guest-resolution-service.php` | `resolve_by_phone()` (فرع `ambiguous`) | مرجع كل مرشَّح عند تعدد نتائج البحث بالهاتف |
| `includes/class-cartat-handler.php` | بعد تأكيد الحضور (`reply === 'yes'`) | صورة QR المُرسَلة للضيف عبر واتساب (Cartat) |
| `includes/class-ultramsg-handler.php` | بعد تأكيد الحضور (`reply === 'yes'`) | صورة QR المُرسَلة للضيف عبر واتساب (UltraMsg) |

**تمييز مهم:** `pge_generate_qr_url($data)` في `helpers.php` (توليد رابط
*صورة* QR عبر `api.qrserver.com`) **لم يتغيّر** — يبقى دالة عامة تأخذ أي نص
وتُرجع رابط صورة. التغيير هو في **ما يُمرَّر لها كـ`$data`**: كان `invite_code`
الخام، أصبح الحمولة الكنسية الموقَّعة من `build_scanner_qr_payload()`.

---

## 4. التحقّق (Validation) — 8 خطوات

`PGE_Guest_Resolution_Service::resolve_from_qr(int $event_id, string $raw_qr_payload)`:

1. **تحليل الصيغة الكنسية** — `PGE_Checkin_QR_Service::validate()`: تفكيك 4
   أجزاء بالضبط؛ فشل فوري (`malformed_payload`) إن لم يطابق العدد (يشمل هذا
   `invite_code` الخام — لا يحتوي `|` بالصيغة المطلوبة أصلاً).
2. **التحقق من التوقيع** — `hash_equals()` (لا مقارنة مباشرة)، نفس نمط
   `access-gate.php`. فشل: `signature_mismatch`.
3. **التحقق من المناسبة** — `payload_event_id === $authorized_event_id`
   (الموثوق من جلسة المشرف، لا من داخل QR). فشل: `event_mismatch`.
4. **التحقق من انتماء `rsvp_id` للمناسبة** — استعلام `wp_pge_event_rsvps`
   (`id = rsvp_id AND event_id = event_id`). فشل: `invitation_not_found`.
   *(خطوات 1-4 تعيش في `PGE_Checkin_QR_Service::validate()` — طبقة
   تشفيرية/بنيوية بحتة، بلا أي معرفة بمعنى `qr_version` الإداري.)*
5. **مطابقة إصدار الاعتماد الحالي** — `is_qr_version_current()` (في
   `PGE_Guest_Resolution_Service`، **ليس** في `PGE_Checkin_QR_Service` —
   فصل معماري مقصود، راجع القسم 6): يقارن `qr_version` في الحمولة مع
   `PGE_Invitation_Repository::get_qr_version($event_id, $phone)` الحالي.
   فشل: **`qr_superseded`** (خطأ عمل مستقر واحد، راجع القسم 7).
6. **حارس الإلغاء الإداري الحالي** — `is_invitation_administratively_cancelled()`
   (غير مُعدَّل، من Phase 9A Final Fix) — يُطبَّق كما هو ضمن مسار حل الضيف.
7. **بناء Guest Object الموحَّد** — نفس العقد الحالي غير المُعدَّل
   (`resolve_by_rsvp_id()`).
8. **الاستمرار عبر Recorder** — `PGE_Checkin_Recorder` (Phase 4)، بلا أي
   تغيير — تسجيل الحضور يحدث بنفس الآلية القديمة تماماً بعد نجاح الحل.

---

## 5. دورة حياة التجديد (QR Regeneration Lifecycle)

`PGE_Invitation_Repository::regenerate_qr($event_id, $phone)`:

1. تحميل خريطة الحالة الحالية (`_pge_invitation_status`) وخريطة الضيوف
   (`_pge_invited_guests`) — تحقّق الوجود (`not_found` إن غاب الضيف).
2. تحقّق الحالة النشطة — رفض إن كانت الدعوة `cancelled` (`error: cancelled`).
3. قراءة `qr_version` الحالي (افتراضي `DEFAULT_QR_VERSION = 1` إن لم يُخزَّن
   شيء من قبل).
4. **تدوير**: `$new_version = $current_version + 1`.
5. تخزين `qr_version` الجديد + `qr_regenerated_at` + `updated_at` في
   `_pge_invitation_status[phone]` — **لا كتابة على أي مكان آخر إطلاقاً**.
6. إرجاع `['result' => 'regenerated', 'qr_version' => $new_version]`.

`PGE_Invitation_Service::regenerate_qr()` يُضيف تدقيقاً: حدث `qr_regenerated`
واحد بالضبط عند النجاح (لا حدث عند الفشل/الإلغاء).

**ما لا تفعله التجديد أبداً (مُتحقَّق منه بالاختبارات):**
- لا تستبدل `invite_code` ولا تكتب عليه.
- لا تُنشئ دعوة/ضيف/RSVP جديداً.
- لا تُصفِّر أي بيانات RSVP أو حضور.
- لا تُغيِّر أي إحصائية حضور.
- لا تكشف الاعتماد القديم (الحمولة الكنسية القديمة لا تُعاد في أي استجابة —
  فقط `qr_version` الرقمي الجديد يُعاد للواجهة).
- لا تكتب أي صف تدقيق تسجيل حضور (`PGE_Checkin_Recorder` لا يُستدعى إطلاقاً
  من مسار التجديد).

---

## 6. لماذا يعيش فحص `qr_version` في `PGE_Guest_Resolution_Service` لا في `PGE_Checkin_QR_Service`

`PGE_Checkin_QR_Service` (Phase 4) مصمَّم عمداً كطبقة تحقّق تشفيري/بنيوي
بحتة، بلا أي معرفة بمفاهيم Phase 9 الإدارية (الدعوات، الإلغاء، التدوير).
يوقِّع/يتحقّق من `qr_version` كحقل بيانات خام فقط — لا "يفهم" معناه.

"هل هذا `qr_version` هو **الإصدار النشط الحالي**؟" سؤال إداري (Phase 9)،
فحُلّ في طبقة أعلى: `PGE_Guest_Resolution_Service` — نفس الطبقة التي تحمل
فعلاً حارس الإلغاء الإداري (`is_invitation_administratively_cancelled()`)
من Phase 9A Final Fix. `is_qr_version_current()` الجديدة تتبع بالضبط نفس
النمط المُثبَت — لا مفهوم إداري جديد يُضاف إلى `PGE_Checkin_QR_Service`.

هذا الفصل يحافظ على قابلية إعادة استخدام `PGE_Checkin_QR_Service` كطبقة
تحقّق عامة، ويُبقي "من هو الإصدار النشط" مسؤولية واحدة في مكان واحد.

---

## 7. أخطاء العمل المستقرة (Stable Business Errors)

| الخطأ | متى يُعاد | ملاحظة |
|---|---|---|
| `malformed_payload` | الحمولة ليست 4 أجزاء صحيحة، أو `invite_code` خام مُرسَل مباشرة | لا سقوط احتياطي لأي صيغة أخرى |
| `signature_mismatch` | التوقيع لا يطابق الحقول | تلاعب/تزوير |
| `event_mismatch` | `event_id` في الحمولة لا يطابق مناسبة الجلسة | QR مناسبة أخرى |
| `invitation_not_found` | لا يوجد صف RSVP بهذا `rsvp_id`/`event_id` | الضيف حُذف لاحقاً |
| **`qr_superseded`** | `qr_version` في الحمولة لا يطابق الإصدار النشط الحالي | **الاعتماد القديم، أُبطِل بالتجديد** — خطأ واحد مستقر، لا `qr_revoked` منفصل |

اختير `qr_superseded` (من بين `qr_revoked`/`qr_superseded` المُقترَحَين)
ويُستخدَم باتساق في كل مسار — لا يوجد مكانان يُرجعان معنى "أُبطِل" بأسماء
مختلفة.

---

## 8. سياسة التوافق مع الدعوات القديمة (Legacy Compatibility Policy)

**السياسة المُعتمَدة:** قراءة افتراضية كسولة، كتابة صريحة فقط عند التجديد.

- `PGE_Invitation_Repository::get_qr_version()` تُعيد `DEFAULT_QR_VERSION`
  (`= 1`) لأي دعوة **لا تملك** قيمة `qr_version` مُخزَّنة صراحةً — قراءة
  بحتة، **بلا أي أثر جانبي (لا كتابة عند القراءة)**.
- بالتالي: أي QR يُولَّد لدعوة لم تُجدَّد بعد يحمل دائماً `qr_version = 1`
  (بشكل ثابت ومتّسق، دون أي "كتابة كسولة" مطلوبة).
- **فقط** `regenerate_qr()` يكتب قيمة صريحة جديدة (`current + 1`، أو
  `DEFAULT_QR_VERSION + 1` إن لم تُخزَّن قيمة من قبل).
- **النتيجة العملية:** أي QR قديم صادر قبل أول تجديد يبقى صالحاً حتى أول
  استدعاء لـ`regenerate_qr()` على تلك الدعوة تحديداً؛ بعدها، فقط الاعتماد
  المُدوَّر الحالي صالح — أي QR سابق يفشل بـ`qr_superseded`.
- **لا صيغتَي حمولة موازيتين إلى ما لا نهاية:** لا يوجد "قبول صيغة v1 القديمة
  بجانب v2 الجديدة" — `CONTEXT` رُقِّم `v2` فتفشل أي حمولة v1 نظرية
  كـ`malformed_payload` مباشرة، دون الحاجة لمحلِّل ثنائي الصيغة.

هذه السياسة أبسط من "كتابة كسولة عند أول توليد" (كانت الخيار الموصى به
بديلاً في الـRFC) لأنها تحقق نفس الأثر الملحوظ (استمرارية الصلاحية حتى أول
تجديد فعلي) دون حاجة لأي كتابة إضافية على مسار القراءة.

---

## 9. الأمان — ملخص التحقق

- **لا سقوط احتياطي غير موقَّع في الماسح:** `resolve_from_qr()` لا يحتوي أي
  فرع يقبل `invite_code` أو أي صيغة غير موقَّعة. أُزيلت
  `resolve_by_invite_code_payload()` بالكامل (كانت أُضيفت مؤقتاً في إصلاح
  سابق، ورُفضت لاحقاً صراحة — راجع القسم 10).
- **لا كشف لأي سرّ في التدقيق:** حدث `qr_regenerated` لا يحمل أي حمولة QR
  كاملة (موقَّعة أو غير موقَّعة) — فقط `event_id`/`phone`/`actor`/الوقت (نفس
  عقد `PGE_Invitation_Management_Audit` الحالي).
- **لا إعادة للاعتماد القديم:** استجابة AJAX التجديد تُعيد `qr_version`
  الرقمي الجديد فقط، لا أي حمولة موقَّعة (لا القديمة ولا الجديدة) — توليد
  الحمولة الفعلية يحدث فقط عند إرسال QR التالي للضيف عبر واتساب.
- **آلية مقارنة التوقيع:** `hash_equals()` — نفس الآلية الآمنة المستخدَمة
  فعلياً في `access-gate.php` وكامل `PGE_Checkin_QR_Service` الأصلي، لا
  مقارنة `===` مباشرة.
- **لا ثقة بـ`event_id`/`rsvp_id` من العميل بلا تحقق خادمي:** `event_id`
  المُستخدَم في المطابقة (خطوة 3) مصدره حصراً جلسة المشرف الموثوقة
  (`$authorized_event_id`)، لا القيمة داخل QR نفسها؛ `rsvp_id` يُتحقَّق من
  وجوده الفعلي في قاعدة البيانات (خطوة 4) قبل أي استخدام آخر.

---

## 10. تاريخ القرار (لماذا أُلغي نهج invite_code fallback)

إصلاح سابق ("Phase 9B Final Fix") واجه حقيقة معمارية سابقة للمشروع: صورة
QR الحقيقية المُرسَلة للضيف عبر واتساب كانت تُشفِّر نص `invite_code` الخام
(عبر `pge_generate_qr_url($invite_code)`)، بينما ماسح الإنتاج
(`resolve_from_qr()` → `PGE_Checkin_QR_Service::validate()`) يتطلّب صيغة
موقَّعة ثلاثية الأجزاء غير ذات صلة — فلم يكن `invite_code` قادراً على الحل
عبر الماسح إطلاقاً، بصرف النظر عن أي تجديد.

الحل المؤقت وقتها كان إضافة `resolve_by_invite_code_payload()` كفرع
احتياطي داخل `resolve_from_qr()`: عند فشل التحقق من الصيغة الموقَّعة، يُعاد
تجربة الحمولة كـ`invite_code` خام.

اعتُبر هذا النهج لاحقاً (Phase 9B QR Architecture Final Fix) **تخفيضاً
أمنياً حقيقياً**: "Invalid signed payload → fallback → accepted as unsigned
invite_code" — أي أن فشل تحقّق تشفيري يُعامَل كإشارة "جرّب صيغة أضعف" بدل
رفض قاطع. كما أنه خلط عقدَي QR داخل نقطة ماسح واحدة (عقد موقَّع + عقد نصّي
غير موقَّع)، وهو بالضبط ما يمنعه هذا المستند بالفصل الثلاثي في القسم 1.

**التصحيح النهائي المُعتمَد** (هذا المستند): إزالة الفرع الاحتياطي بالكامل،
وإضافة بدائيّ تدوير حقيقي (`qr_version`) يجعل التجديد فعلياً قابلاً للتحقق
(الاعتماد القديم يُرفَض فعلياً بعد التجديد، لا مجرد نظرياً) دون الحاجة لأي
سقوط احتياطي إطلاقاً — عودة القرار السابق ("Authorize a scoped
reconciliation exception") مُلغاة صراحة بهذا الإصلاح.
