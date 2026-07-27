# Feature Matrix — طبقة الميزات الجديدة فوق Catalog

> وثيقة تخطيط معماري فقط — **لا كود، لا Migration، لا تعديل Schema، لا تعديل أي ملف PHP.**
> تُبنى فوق نظام Catalog الحالي (`mon_plans` / `mon_plan_tiers` / Snapshot / `PGE_Catalog` / Credits / Replacement Credits / Ledger / Cartat / Salla) **بلا أي إعادة تصميم لأي جزء منها**.
> تاريخ الإعداد: 2026-07-25 — **الإصدار النهائي** (مُحدَّث بعد قرارات معمارية وتجارية نهائية).

---

## 0. ملخص تنفيذي

النظام الحالي **مستقر ولا يُعاد تصميم أي جزء منه**: Catalog، Plans، Plan Tiers، Snapshot الحالي، Credits، Replacement Credits، Ledger، Cartat، وSalla تبقى تماماً كما هي. طبقة Features الموصوفة في هذه الوثيقة **امتداد فوق النظام الحالي فقط** — إضافة معزولة تماماً، لا تلمس أي جدول أو Snapshot أو Resolver موجود.

قرار حاسم ومُلزِم لهذه الوثيقة: **`invitation_credit_limit` و`replacement_credit_limit` ليسا جزءاً من طبقة Features إطلاقاً**. هذان الحقلان أصبحا جزءاً أساسياً وثابتاً من `mon_plan_tiers`، ويبقيان في Snapshot الحالي (`_mon_invitation_credit_total`/`_mon_replacement_credit_total`) وفي الـResolver الحالي (`pge_get_user_plan_limits_for_events()`) بلا أي تكرار في مخزن Features الجديد. أي تكرار لهما هناك يُعتبر مصدرين للحقيقة ويُرفَض معمارياً — راجع القسم 9 "Not Part of Feature System".

القرار المعماري النهائي لتخزين الميزات: **جدول `mon_tier_features` بأربعة أعمدة بيانات فقط** (`tier_id`, `feature_key`, `feature_value`، بلا عمود `value_type`) — **نوع كل ميزة يُعرَّف حصراً داخل Feature Registry** (قسم 6، مرجع برمجي واحد لا قاعدة بيانات)، لا داخل الجدول. الـResolver يعتمد على هذا الترتيب الصارم: **Database → Registry → Resolver → UI**. ولوحة الإدارة المستقبلية لهذه الميزات **تُولَّد تلقائياً من Registry** — لا يُسمح بإدخال Feature Key حر من أي Textarea أو Text Input (قسم 8).

القرارات التجارية المعتمدة الآن نهائياً في هذه الوثيقة: **Google Maps متاحة في الباقتين معاً (`true` لحلوة كلاسيك وحلوة بلس) وليست ميزة تمييز بينهما**، و**نسبة الدعوات البديلة (30%/40%) لا تُستخدَم في التشغيل إطلاقاً** — تُستخدَم فقط كأداة حساب عند إنشاء أو تعديل الـTier لتحديد قيمة `replacement_credit_limit` الثابتة التي يبقى النظام التشغيلي يعتمدها كما هو اليوم بلا أي تغيير.

---

## 1. Feature Matrix — نظرة عامة على مستوى العمل

> **ملاحظة**: هذا الجدول لا يتضمن `invitation_credit_limit` ولا `replacement_credit_limit` — هذان الحقلان خارج نطاق طبقة Features بالكامل (راجع القسم 9). لا يتضمن أيضاً نسبة الدعوات البديلة كميزة مُستهلَكة وقت التشغيل (راجع القسم 3).

| Feature Key | الاسم العربي | النوع | حلوة كلاسيك | حلوة بلس | مصدر Legacy الحالي | حالة التنفيذ الحالية | الصفحات/الخدمات المستهلِكة |
|---|---|---|---|---|---|---|---|
| `host_limit` | عدد المضيفين الإضافيين | `integer` | TBD | TBD | تسعير فقط (`extra_admin_price`) | **غير موجودة** — `pge_is_host_or_admin()` ثنائي فقط (مالك المنشور أو مدير ووردبريس) | يتطلب كياناً جديداً بالكامل |
| `admin_supervisor_limit` | عدد مشرفي الدخول | `integer` | TBD | TBD | تسعير فقط (`admin_perms_price`) | **غير موجودة** — لا منطق يمنح صلاحية Check-in لغير المالك | يتطلب كياناً جديداً بالكامل |
| `invitation_design_limit` | عدد تصاميم الدعوة | `integer` (المعنى TBD) | TBD | — | لا يوجد | **غير موجودة + غامضة المعنى** | يعتمد على القرار التجاري (القسم 10) |
| `event_website` | موقع الحفل الإلكتروني | `boolean` | ✅ | ✅ | لا مفتاح (تعمل دائماً) | **موجودة وتعمل** | `single-pge_event.php`, `routing.php` |
| `google_maps` | خرائط Google | `boolean` | ✅ (معتمد نهائياً) | ✅ (معتمد نهائياً) | `google_map` | **موجودة وتعمل (Legacy)، هشّة في Catalog** — راجع القسم 3 | `/create-event/`, `class-cartat-handler.php` |
| `guest_qr` | QR لكل دعوة | `boolean` | ✅ | ✅ | لا مفتاح (تعمل دائماً) | **موجودة وتعمل** | `helpers.php:pge_generate_qr_url`, `class-cartat-handler.php` |
| `rsvp` | تأكيد الحضور والاعتذار | `boolean` | ✅ | ✅ | لا مفتاح (أساسي) | **موجودة وتعمل** | `wp_pge_event_rsvps`, `rsvp-handler.php` |
| `attendance_statistics` | إحصائية الحضور | `boolean` | ✅ | ✅ | لا مفتاح (تعمل دائماً) | **موجودة وتعمل** | `templates/dashboard-main.php` |
| `guest_comments` | التعليقات ورسائل التهنئة | `boolean` | ❌ | ✅ | تقريبياً `public_chat`/`private_chat` (TBD) | **موجودة جزئياً** — التطابق مع الوصف غير مؤكد | `template-parts/event/tabs.php` |
| `event_photo_album` | ألبوم صور المناسبة | `boolean` | ❌ | ✅ | تقريبياً `guest_photos` (TBD) | **موجودة جزئياً** — التطابق مع الوصف غير مؤكد | `template-parts/event/tabs.php` |
| `gift_feature` | خاصية إرسال هدية (يتحكم بها العميل) | `boolean` | ❌ | ✅ | `stc_pay` | **موجودة جزئياً** — تفعيل إداري فقط، لا تحكم للعميل | `template-parts/event/tabs.php` |
| `invitation_message` | رسالة الدعوة الأولى | `boolean` | ✅ | ✅ | لا مفتاح (تعمل دائماً) | **موجودة وتعمل** | `pge_wa_get_templates()['invite']` |
| `reminder_message` | رسالة تذكير | `boolean` | ✅ | ✅ | لا يوجد | **غير موجودة** — لا قالب، لا جدولة | يتطلب كياناً جديداً بالكامل |
| `thank_you_message` | رسالة شكر للحضور | `boolean` | ✅ | ✅ | لا يوجد | **غير موجودة** — لا قالب، لا آلية اكتشاف انتهاء المناسبة | يتطلب كياناً جديداً بالكامل |
| `decline_message` | رسالة خاصة للمعتذرين | `boolean` | ❌ | ✅ | لا مفتاح Feature | **موجودة بلا بوابة — متاحة للجميع فعلياً اليوم** | `pge_wa_get_templates()['no']` |
| `custom_invitation_image` | صورة دعوة مخصصة | `boolean` | ❌ | ✅ | تقريباً `header_img` (عام لا خاص) | **غير موجودة كميزة مستقلة** | — |
| `custom_reminder_image` | صورة تذكير مخصصة | `boolean` | ❌ | ✅ | لا يوجد | **غير موجودة** (تعتمد على `reminder_message` أولاً) | — |
| `custom_thank_you_image` | صورة شكر مخصصة | `boolean` | ❌ | ✅ | لا يوجد | **غير موجودة** (تعتمد على `thank_you_message` أولاً) | — |
| `support_services_discount_percentage` | خصم الخدمات المساندة | `percentage` | — | 40% (TBD تأكيد) | تسعير فقط | **غير موجودة** — لا "خدمات مساندة" كمنتج قابل للطلب | يتطلب كياناً تجارياً جديداً بالكامل |

---

## 2. التصنيف المقترح

**Credits and Limits** *(داخل طبقة Features فقط — لا تشمل رصيد الدعوات نفسه، راجع القسم 9)*: `host_limit`, `admin_supervisor_limit`, `invitation_design_limit`

**Event Experience**: `event_website`, `google_maps`, `guest_qr`, `rsvp`, `attendance_statistics`, `guest_comments`, `event_photo_album`, `gift_feature`

**Messaging**: `invitation_message`, `reminder_message`, `thank_you_message`, `decline_message`, `custom_invitation_image`, `custom_reminder_image`, `custom_thank_you_image`

**Commercial**: `support_services_discount_percentage`

---

## 3. القرارات التجارية المعتمدة نهائياً

### Google Maps: متاحة في الباقتين — ليست ميزة تمييز

**القرار المعتمد**: `google_maps = true` لحلوة كلاسيك وحلوة بلس معاً. لا تُستخدَم كأداة تفريق بين الباقتين.
**أثر هذا القرار على التنفيذ الحالي**: القرار التجاري لا يُغيّر آلية التسليم التقنية الحالية تلقائياً — اليوم تعمل عبر `google_map` في Legacy (تعمل بثبات)، وعبر حقل `features` الحر على مستوى Plan في Catalog (آلية هشة، راجع §0 من الإصدار السابق من هذه الوثيقة — الخطر نفسه موثَّق في القسم 12). عند تنفيذ `mon_tier_features` مستقبلاً (المرحلة 1)، تصبح `google_maps` أول مرشح طبيعي للانتقال إليه بقيمة ثابتة `true` على كل Tier من الباقتين، بلا حاجة لأي منطق شرطي بين الباقتين.

### Replacement Credits — النسبة أداة تخطيط فقط، لا قيمة تشغيلية

**القرار المعتمد**: النظام التشغيلي (الاستهلاك الذري عبر `class-pge-replacement-entitlements.php`، القفل، الـLedger) **يبقى كما هو تماماً بلا أي تغيير** — يعتمد حصراً على `replacement_credit_limit` كرقم ثابت مخزَّن في `mon_plan_tiers`، بالضبط كما يعمل اليوم. **النسبة لا تُخزَّن، لا تُقرأ في أي مسار تشغيلي، ولا تدخل في أي حساب لحظة الاستهلاك.**

النسبة تُستخدَم **فقط كخطوة حسابية يدوية عند إنشاء أو تعديل Tier** في لوحة الإدارة الحالية (`pge-catalog-tiers`)، لتحديد الرقم الذي يُكتَب في حقل "رصيد الدعوات البديلة" الموجود بالفعل في النموذج. أمثلة القيم المعتمدة تجارياً:

| عدد الدعوات (Tier) | حلوة كلاسيك — 30% | حلوة بلس — 40% |
|---|---|---|
| 100 | 30 | 40 |
| 150 | 45 | 60 |
| 200 | 60 | 80 |
| 300 | 90 | 120 |
| 400 | 120 | 160 |

هذا الجدول **مرجع توثيقي للمسؤول عند إدخال البيانات فقط** — لا يقتضي أي تعديل على `PGE_Catalog::create_tier()`/`update_tier()` الحاليتين، ولا أي عمود جديد في `mon_plan_tiers`. النسبة نفسها **ليست Feature** ولا تدخل في Feature Registry (القسم 6) ولا في `mon_tier_features` — راجع القسم 9.

---

## 4. Mapping مع النظام الحالي

القاعدة المتبعة: **اسم الحقل لا يعني أن الميزة تعمل** — لكل صف تتبُّع استهلاك فعلي، لا افتراض. *(لا تظهر هنا `invitation_credit_limit`/`replacement_credit_limit` — راجع القسم 9 لمكانهما الصحيح خارج هذا الجدول.)*

| Feature Key | `mon_plans` | `mon_plan_tiers` | Snapshot الحالي | Legacy `mon_packages_settings` | `event-factory.php` | `/create-event/` | Dashboard | Cartat | **التصنيف** |
|---|---|---|---|---|---|---|---|---|---|
| `host_limit` | — | — | — | تسعير فقط | لا | حقل مضيف واحد فقط | لا | لا | **غير موجودة — تحتاج كياناً مستقلاً** |
| `admin_supervisor_limit` | — | — | — | تسعير فقط | لا | لا | لا | لا | **غير موجودة — تحتاج كياناً مستقلاً** |
| `invitation_design_limit` | — | — | — | لا | لا | حقل صورة بارزة واحد | لا | لا | **غير موجودة، وغامضة المعنى** |
| `event_website` | — | — | — | لا (تعمل دائماً) | — | — | — | — | **موجودة وتعمل (غير مشروطة)** |
| `google_maps` | حقل `features` الحر (هشّ) | لا عمود مخصَّص | `_mon_catalog_features` (عبر Plan) | مفتاح `google_map` مباشر | يُقرأ عبر `pge_plan_feature_enabled_for_events()` | ✅ يُعطِّل/يُفعِّل الحقل فعلياً | لا | ✅ صورة خريطة ثابتة عند التأكيد | **موجودة وتعمل (Legacy)، هشّة (Catalog)** |
| `guest_qr` | — | — | — | لا (تعمل دائماً) | — | — | — | ✅ | **موجودة وتعمل** |
| `rsvp` | — | — | — | لا (أساسي) | — | — | — | — | **موجودة وتعمل** |
| `attendance_statistics` | — | — | — | لا (أساسي) | — | — | ✅ | — | **موجودة وتعمل** |
| `guest_comments` | حقل `features` الحر محتمَل | لا | — | `public_chat`/`private_chat` | لا | لا | لا | لا | **موجودة جزئياً (Legacy فقط)** |
| `event_photo_album` | حقل `features` الحر محتمَل | لا | — | `guest_photos` | لا | لا | لا | لا | **موجودة جزئياً (Legacy فقط)** |
| `gift_feature` | حقل `features` الحر محتمَل | لا | — | `stc_pay` | لا | لا | لا | لا | **موجودة جزئياً (تفعيل إداري فقط)** |
| `invitation_message` | — | — | — | لا (تعمل دائماً) | — | — | — | ✅ قالب `invite` | **موجودة وتعمل (غير مشروطة)** |
| `reminder_message` | — | — | — | لا | — | — | — | لا قالب | **غير موجودة** |
| `thank_you_message` | — | — | — | لا | — | — | — | لا قالب | **غير موجودة** |
| `decline_message` | — | — | — | لا | — | — | — | ✅ قالب `no` (بلا بوابة) | **موجودة بلا Enforcement** |
| `custom_invitation_image` | — | — | — | `header_img` (تقريبي) | — | — | — | لا | **غير موجودة كميزة مستقلة** |
| `custom_reminder_image` | — | — | — | لا | — | — | — | لا | **غير موجودة** |
| `custom_thank_you_image` | — | — | — | لا | — | — | — | لا | **غير موجودة** |
| `support_services_discount_percentage` | — | — | — | تسعير فقط | — | — | — | — | **غير موجودة** |

---

## 5. التصميم المقترح للتخزين

### Schema المعتمد نهائياً — `mon_tier_features`

```
mon_tier_features
    id            BIGINT UNSIGNED  (PK)
    tier_id       BIGINT UNSIGNED
    feature_key   VARCHAR(64)
    feature_value LONGTEXT
    created_at    DATETIME
    updated_at    DATETIME

UNIQUE (tier_id, feature_key)
```

**لماذا `LONGTEXT` لا `TEXT`**: ليس لأن القيم الحالية كبيرة — كل القيم المعروفة اليوم في Registry (§6) بسيطة جداً (`true`/`false`، أرقام صغيرة). السبب هو تفادي أي حاجة لـMigration مستقبلية على هذا العمود تحديداً عندما تحتاج ميزة قادمة (غير معروفة اليوم) تخزين قيمة أكبر بطبيعتها — JSON، Array، أو Config Object كامل — دون أي تعديل على بنية الجدول لاستيعابها. هذا القرار لا يُغيّر التوافق مع القيم البسيطة الحالية بأي شكل: `feature_value = 'true'` أو `'2'` يبقيان يُخزَّنان ويُقرآن بنفس الطريقة تماماً.

**لا عمود `value_type` في هذا الجدول.** كل صف يحمل فقط القيمة الخام (`feature_value`) كنص. تفسير هذه القيمة (هل هي `boolean`؟ `integer`؟ `percentage`؟) **يُشتقّ حصراً من Feature Registry (القسم 6)**، لا من الجدول نفسه — هذا يمنع تكرار تعريف النوع في مكانين (الجدول + الكود)، ويجعل Registry المصدر الوحيد للحقيقة بخصوص شكل كل ميزة.

### لماذا هذا التصميم يناسب المشروع الحالي

- **إضافة معزولة بالكامل**: لا `ALTER TABLE` على `mon_plans` أو `mon_plan_tiers`، لا تعديل على `PGE_Catalog::create_tier()`/`update_tier()` الحاليتين، لا لمس لـ`class-pge-invitation-credit-ledger.php` أو `class-pge-replacement-entitlements.php` أو `class-cartat-handler.php` أو `class-salla-handler.php`.
- **يعالج الثغرة المرصودة فعلياً**: حقل `features` الحر الحالي على مستوى Plan يخلط بين نص عرض ومفتاح تقني بلا أي تفريق — `mon_tier_features` + Registry يفصلان القيمة الخام عن تفسيرها بشكل صريح ومركزي.
- **يدعم كل الأنواع المطلوبة عبر Registry لا عبر الجدول**: `feature_value` نص خام دائماً؛ Registry يحدد كيف يُقرأ (`true`/`false` لـboolean، رقم صحيح لـinteger، رقم 0-100 لـpercentage).
- **قابل للحذف الكامل لاحقاً دون أثر**: طبقة منفصلة تماماً عن كل الأنظمة المستقرة المذكورة في القسم 0.

---

## 6. Feature Registry — المرجع الوحيد لتعريف الميزات

> **Feature Registry هو المصدر الرسمي الوحيد لتعريف أي Feature داخل النظام. قاعدة البيانات لا تعرّف الميزات، وإنما تخزن قيمها فقط. جميع أنواع الميزات وقيمها الافتراضية وقواعد التحقق وأسماء الإدارة وتصنيفاتها تُعرَّف داخل Feature Registry فقط. لا يجوز لأي جزء من النظام تفسير Feature أو إنشاء Feature أو استهلاك Feature خارج Feature Registry.**

Registry هو **تعريف برمجي (كود)، لا قاعدة بيانات** — قائمة ثابتة يُضاف إليها يدوياً عند كل ميزة جديدة (راجع القاعدة المعمارية في القسم 8). نوع كل ميزة، قيمتها الافتراضية، وقواعد التحقق منها **تُعرَّف هنا حصراً** — لا يُشتقّ أي منها من `mon_tier_features` أو من أي جدول آخر.

### Feature Lifecycle

جاهزية كل ميزة **لا تُقاس بقيمة Boolean بعد الآن** — بل عبر عمود `lifecycle` بقيمة واحدة من خمس مراحل مرتَّبة تصاعدياً:

| القيمة | المعنى |
|---|---|
| `planned` | الميزة معرَّفة في Registry فقط — لا Backend ولا UI بعد. |
| `backend` | منطق التنفيذ الخلفي موجود (كلياً أو جزئياً)، لكن لا واجهة إدارة/استهلاك مكتملة أو مؤكَّدة النطاق بعد. |
| `ui` | الواجهة (إدارية أو استهلاكية) موجودة، لكن الربط الكامل بالـFeature System الجديد (Registry + `mon_tier_features` + Resolver) غير مكتمل بعد. |
| `production` | الميزة تعمل فعلياً وتخدم مستخدمين حقيقيين اليوم. |
| `deprecated` | ميزة كانت تعمل سابقاً ولم تعد جزءاً من الاتجاه المعماري الحالي. |

| key | type | default | category | admin_label | description | validation | lifecycle |
|---|---|---|---|---|---|---|---|
| `host_limit` | integer | TBD | credits_and_limits | عدد المضيفين الإضافيين | عدد الأشخاص الإضافيين المسموح تعيينهم كمضيفين لنفس المناسبة | عدد صحيح ≥ 0 | planned |
| `admin_supervisor_limit` | integer | TBD | credits_and_limits | عدد مشرفي الدخول | عدد الأشخاص المسموح تفويضهم لتسجيل حضور الضيوف (Check-in) | عدد صحيح ≥ 0 | planned |
| `invitation_design_limit` | integer | TBD | credits_and_limits | عدد تصاميم الدعوة | المعنى غير محسوم بعد (قوالب/صور/تصاميم مخصصة) — راجع القسم 10 | عدد صحيح ≥ 0 (بعد حسم المعنى) | planned |
| `event_website` | boolean | true | event_experience | موقع الحفل الإلكتروني | صفحة دعوة عامة مستقلة لكل مناسبة | `true`/`false` فقط | production |
| `google_maps` | boolean | true | event_experience | خرائط Google | إظهار/تفعيل حقل رابط الموقع على خرائط Google | `true`/`false` فقط | production |
| `guest_qr` | boolean | true | event_experience | QR لكل دعوة | رمز QR يُرسَل لكل ضيف عند تأكيد الحضور | `true`/`false` فقط | production |
| `rsvp` | boolean | true | event_experience | تأكيد الحضور والاعتذار | تسجيل ردود الضيوف في `wp_pge_event_rsvps` | `true`/`false` فقط | production |
| `attendance_statistics` | boolean | true | event_experience | إحصائية الحضور | عرض مؤشرات الحضور في لوحة المضيف | `true`/`false` فقط | production |
| `guest_comments` | boolean | false | event_experience | التعليقات ورسائل التهنئة | تفعيل تبويب تعليقات/تهنئة للضيوف — التطابق مع `public_chat`/`private_chat` الحالي غير مؤكد | `true`/`false` فقط | backend |
| `event_photo_album` | boolean | false | event_experience | ألبوم صور المناسبة | ألبوم رسمي — التطابق مع `guest_photos` الحالي غير مؤكد | `true`/`false` فقط | backend |
| `gift_feature` | boolean | false | event_experience | خاصية إرسال هدية | يتحكم المضيف نفسه بتفعيلها (لا المسؤول فقط كما في `stc_pay` الحالي) | `true`/`false` فقط | backend |
| `invitation_message` | boolean | true | messaging | رسالة الدعوة الأولى | إرسال قالب `invite` عبر واتساب | `true`/`false` فقط | production |
| `reminder_message` | boolean | false | messaging | رسالة تذكير | يتطلب قالب جديد + آلية جدولة غير موجودة اليوم | `true`/`false` فقط | planned |
| `thank_you_message` | boolean | false | messaging | رسالة شكر للحضور | يتطلب قالب جديد + آلية اكتشاف انتهاء المناسبة غير موجودة اليوم | `true`/`false` فقط | planned |
| `decline_message` | boolean | false | messaging | رسالة خاصة للمعتذرين | القالب (`no`) موجود فعلياً لكنه يعمل اليوم للجميع بلا بوابة Feature | `true`/`false` فقط | backend |
| `custom_invitation_image` | boolean | false | messaging | صورة دعوة مخصصة | صورة مستقلة عن الصورة البارزة العامة للمناسبة | `true`/`false` فقط | planned |
| `custom_reminder_image` | boolean | false | messaging | صورة تذكير مخصصة | يعتمد على `reminder_message` أولاً | `true`/`false` فقط | planned |
| `custom_thank_you_image` | boolean | false | messaging | صورة شكر مخصصة | يعتمد على `thank_you_message` أولاً | `true`/`false` فقط | planned |
| `support_services_discount_percentage` | percentage | 0 | commercial | خصم الخدمات المساندة | لا "خدمات مساندة" كمنتج قابل للطلب اليوم — القيمة بلا معنى تشغيلي بعد | 0-100 عدد صحيح | planned |

**ملاحظة صريحة**: `google_maps`, `invitation_message`, `guest_comments`, `event_photo_album`, `gift_feature`, `decline_message` تحمل `lifecycle` متقدِّماً (`production`/`backend`) رغم عدم وجود صف لها في `mon_tier_features` بعد — لأن آلياتها الحالية (Legacy مباشرة، أو قالب واتساب بلا بوابة) تعمل فعلياً اليوم خارج طبقة Features تماماً. Registry يوثِّق هذا الواقع بصدق؛ لا يعني ذلك أن `mon_tier_features` يخزّنها بعد — الانتقال الفعلي لكل منها إلى المخزن الجديد يبقى عملاً تنفيذياً موصوفاً في خطة المراحل (القسم 12).

### Registry Independence

**Feature Registry لا ينتمي إلى باقة معينة، ولا إلى مناسبة معينة — بل يمثل جميع الميزات الموجودة في النظام ككل.** الباقات (Plans/Tiers) ليست مالكة لأي Feature؛ هي فقط كيان يحدد **قيمة** Feature معرَّفة مسبقاً في Registry.

مثال: `gift_feature` هي Feature تابعة للنظام بأكمله — معرَّفة مرة واحدة في Registry بنوعها ووصفها وقواعدها. أما "حلوة كلاسيك" و"حلوة بلس" فلا تملكان هذه الميزة، بل تحدد كل منهما فقط `gift_feature = false` أو `gift_feature = true` — القيمة تختلف، لكن تعريف الميزة نفسه واحد لا يتكرر ولا يُعاد تعريفه لكل باقة.

هذا الفصل بين "تعريف الميزة" (Registry) و"قيمتها لكل باقة" (`mon_tier_features`) هو ما يسمح مستقبلاً بإعادة استخدام Feature Registry نفسه خارج نطاق باقات "مناسبات" الحالية — لباقات جديدة، أو منتجات جديدة، أو وحدات أخرى بالكامل — دون أي حاجة لإعادة تعريف الميزات من الصفر لكل سياق جديد.

### Registry Provider

Feature Registry، من الناحية المعمارية، **ليس مجرد Array ثابت داخل ملف** — بل **واجهة (Provider)** يتعامل من خلالها كل جزء آخر في النظام. لا يجوز لأي طبقة (Resolver، لوحة إدارة، أو أي كود مستقبلي) قراءة تعريفات الميزات من مصدرها الفعلي مباشرة؛ الجميع يمر عبر Registry Provider حصراً:

```
Feature Registry
        ↓
Registry Provider
        ↓
Resolver
        ↓
UI
```

**الهدف من هذه الطبقة الإضافية ليس التعقيد** — بل أربعة أمور محددة:

- **Central Access Point**: نقطة وصول واحدة لكل تعريفات الميزات، لا مسارات قراءة متعددة متفرقة في الكود.
- **Single Source of Truth**: يبقى Registry نفسه (§6) هو المرجع الوحيد للمحتوى؛ الـProvider لا يُنشئ حقيقة جديدة، فقط يوحّد طريقة الوصول إليها.
- **Extensibility**: إضافة مصدر Registry جديد أو تغيير طريقة تحميله مستقبلاً (راجع Future Extensibility أدناه) لا يتطلب تعديل أي طرف يستهلك الميزات — فقط تعديل الـProvider نفسه.
- **Testability**: يمكن اختبار Resolver وأي طبقة أعلى بمعزل عن مصدر Registry الفعلي (عبر Provider وهمي/بديل عند الاختبار)، بنفس الروح التي تُختبَر بها بقية أجزاء هذا المشروع اليوم (Fake `$wpdb`، Stubs، إلخ — راجع ملفات `tests/` الحالية).

النتيجة العملية لهذا المبدأ: **لا يعرف أي جزء من النظام أين يُخزَّن Registry فعلياً** — سواء كان اليوم قائمة ثابتة في كود PHP، أو أصبح لاحقاً مصدراً آخر بالكامل — لأن كل استهلاك يمر عبر واجهة الـProvider الموحَّدة، لا عبر المصدر مباشرة.

### Future Extensibility

هذا التصميم (Registry مستقل عن أي باقة + الوصول إليه حصراً عبر Provider موحَّد) يسمح مستقبلاً بإضافة أي مما يلي **دون أي تعديل على Resolver أو Snapshot أو بقية النظام**:

- Plugins جديدة.
- Modules جديدة.
- منتجات جديدة.
- أنواع مناسبات جديدة.
- Features جديدة.

كل ما يلزم في كل حالة من هذه هو **تسجيل الـFeature الجديدة داخل Registry Provider** (إضافة تعريفها الكامل وفق بنية القسم 6) — لا أي تغيير بنيوي في الطبقات الأخرى، لأن جميعها تتعامل مع الميزات عبر نفس الواجهة الموحَّدة بصرف النظر عن عددها أو مصدرها.

### Dependency Direction

اتجاه الاعتماد بين الطبقات **يجب أن يكون باتجاه واحد ثابت دائماً**، ولا يجوز عكسه أو تجاوزه:

```
Feature Registry
        ↓
Registry Provider
        ↓
Resolver
        ↓
Business Logic
        ↓
UI
```

**لا يجوز لأي طبقة أدنى (Business Logic أو UI) الاعتماد مباشرة على Registry الداخلي أو على قاعدة البيانات** — الوصول يجب أن يمر دائماً عبر الطبقة التي تعلوه مباشرة في هذا الترتيب. مثال محظور صراحة: صفحة UI تقرأ `mon_tier_features` مباشرة عبر استعلام SQL خاص بها بدل المرور عبر Resolver — هذا يخالف اتجاه الاعتماد المعتمد هنا حتى لو أنتج نفس القيمة ظاهرياً.

### Implementation Guideline

**توصية تصميمية فقط — وليست متطلباً إلزامياً**: يُفضَّل أن يوفر تنفيذ Registry Provider المستقبلي واجهة صغيرة بثلاث عمليات فقط — `get()` (قراءة تعريف ميزة واحدة)، `has()` (التحقق من وجود ميزة في Registry)، `all()` (قراءة كل التعريفات) — بحيث لا تعتمد بقية أجزاء النظام على التعامل مع Array خام مباشرة في أي مكان خارج الـProvider نفسه. هذه توصية لتوجيه التنفيذ المستقبلي، لا قيداً يمنع البدء بتصميم أبسط إن استدعت الحاجة.

---

## 7. Resolver — يعتمد على Registry حصراً

**الترتيب الإلزامي**:

```
Database (mon_tier_features → feature_value خام)
        ↓
Registry (تعريف key → type/default/validation)
        ↓
Resolver (تفسير القيمة الخام وفق النوع من Registry + ترتيب المصادر)
        ↓
UI (/create-event/, Dashboard, أي صفحة مستقبلية)
```

**العقد المقترح (بدون كود):**

```
pge_user_has_feature($user_id, $feature_key): bool
pge_get_user_feature_value($user_id, $feature_key, $default = null)
pge_get_user_package_features($user_id): array
```

**القاعدة الحاسمة**: الـResolver **لا يفسّر القيمة الخام مباشرة من الجدول** بأي منطق مضمَّن فيه (لا `if is_numeric()` ولا تخمين نوع) — في كل استدعاء، يسأل Registry أولاً "ما نوع `$feature_key`؟" **عبر Registry Provider حصراً، لا بالوصول المباشر لمصدر Registry** (راجع Registry Provider وDependency Direction أعلاه)، ثم يُطبِّق قاعدة التفسير الموحَّدة لذلك النوع فقط. أي `feature_key` غير موجود في Registry أصلاً **يُرفَض فوراً** (Default آمن، لا محاولة تخمين) — هذا يمنع معمارياً وصول أي مفتاح لم يُعرَّف مسبقاً إلى أي واجهة.

**ترتيب مصادر القراءة** (كما في الإصدار السابق، بلا تغيير): Catalog Snapshot (`_mon_package_features`) → Catalog Tier كـFallback دفاعي فقط (حالة انتقالية موثَّقة لا مصدر دائم) → Legacy (`PGE_Packages` + `_mon_active_features`، لمستخدم `_mon_package_source !== 'catalog'` فقط) → Default آمن من Registry.

**كيف تُقرأ الأنواع (وفق تعريف Registry لا تخمين):**
- **Boolean**: `pge_user_has_feature()` — القيمة الخام تُقارَن حرفياً بـ`true`/مكافئاتها المعروفة، حسب نفس قاعدة `pge_plan_feature_enabled_for_events()` الحالية.
- **Integer**: `pge_get_user_feature_value()` تُعيد `(int)` صريحاً.
- **Percentage**: `pge_get_user_feature_value()` تُعيد عدداً صحيحاً 0-100 — **لا يوجد اليوم أي Feature من نوع percentage تُقرأ وقت التشغيل** (`support_services_discount_percentage` غير مُنفَّذة بعد؛ نسبة الدعوات البديلة ليست Feature إطلاقاً، راجع القسم 3).

---

## 8. قاعدة معمارية جديدة — لا Feature بلا Registry أولاً

**القاعدة المُلزِمة**: لا يجوز إنشاء أي Feature جديدة في `mon_tier_features` قبل إضافتها أولاً إلى Feature Registry (القسم 6) بكامل حقولها التسعة. **لا يجوز إدخال Feature Key حر من لوحة الإدارة بأي حال** — لا Textarea، لا Text Input حر لاسم المفتاح.

**الأثر على لوحة الإدارة المستقبلية**: صفحة إدارة ميزات الـTier (امتداد مستقبلي لصفحة `pge-catalog-tiers` الحالية، خارج نطاق التنفيذ في هذه الوثيقة) **تُولَّد تلقائياً من Registry** — قائمة الحقول المعروضة، نوع كل عنصر إدخال (Checkbox لـ`boolean`، حقل رقم لـ`integer`/`percentage`)، والتحقق من كل قيمة (`validation`) تأتي كلها من Registry مباشرة، لا من إدخال حر للمسؤول. هذا يمنع معمارياً تكرار الثغرة الحالية في حقل `features` الحر على مستوى Plan (§0، §5) — لا يمكن للمسؤول مستقبلاً كتابة مفتاح خاطئ أو نص عرض بدل مفتاح تقني، لأن الخيارات المتاحة له محدودة بما هو معرَّف في Registry فقط.

---

## 9. Snapshot المقترح

**نعم — تُنسَخ Features إلى User Meta عند تفعيل الباقة**، بنفس مبدأ Snapshot الحالي للرصيد (بلا أي تعديل على ذلك الـSnapshot نفسه).

### ما يُخزَّن في Snapshot الجديد

**لا نوع (`type`) ولا أي Metadata أخرى** — فقط أزواج `feature_key => feature_value` مباشرة:

```
_mon_package_features = [
    'google_maps'  => true,
    'host_limit'   => 2,
    'gift_feature' => false,
]
```

يُضاف: `_mon_package_feature_version` — **رقم إصدار صحيح (Integer، مثل `1`, `2`, `3`...)، وليس Timestamp** — يزداد بمقدار واحد في كل مرة يُعاد فيها بناء الـSnapshot لنفس المستخدم (تفعيل جديد/تجديد/تبديل Tier)، ليُستخدَم للتمييز بين نسخ الـSnapshot المتعاقبة دون الحاجة لمقارنة التاريخ.

### لماذا Snapshot مهم

نفس المبدأ المُطبَّق بالفعل على `_mon_invitation_credit_total` اليوم — فصل "ما يملكه المستخدم فعلياً وقت التفعيل" عن "ما هو مُعرَّف حالياً في الـTier"، بلا تغيير بأثر رجعي.

### ماذا يحدث إذا عدّل المسؤول ميزات Tier بعد شراء المستخدم

لا شيء يتغيّر لدى المستخدم الحالي — `_mon_package_features` يبقى كما كُتِب وقت التفعيل، تماماً كسلوك الرصيد الحالي غير المتأثر بتعديل `mon_plan_tiers.invitation_credit_limit` لاحقاً.

### هل يحتفظ المشترك القديم بمزاياه الأصلية حتى التجديد

نعم — طالما `_mon_package_status = active` ولم يُستدعَ منطق التفعيل مجدداً لنفس المستخدم.

### عند التجديد أو تغيير الباقة

يُعاد بناء `_mon_package_features` بالكامل من حالة `mon_tier_features` الحيّة لحظة التفعيل الجديد (بلا ترحيل قيم قديمة)، ويزداد `_mon_package_feature_version` بمقدار واحد — بنفس فلسفة "كل تفعيل جديد = Snapshot جديد" المُطبَّقة بالفعل على الرصيد.

---

## 10. القرارات المعلّقة (TBD) — لا تنفيذ ولا افتراض لها

القرارات التالية **تبقى معلَّقة صراحة**، ولم يُوضَع لها أي تنفيذ أو قيمة افتراضية في هذه الوثيقة:

1. **معنى الدعوة التجريبية** — معاينة قبل الإرسال؟ إرسال لرقم واحد؟ أم رفع ملف تصميم؟
2. **معنى تصميم دعوة واحد** — عدد القوالب؟ عدد الصور المرفوعة؟ عدد التصاميم المخصصة؟
3. **عدد المضيفين (`host_limit`)** — القيمة الرقمية لكل من كلاسيك وبلس.
4. **عدد المشرفين (`admin_supervisor_limit`)** — القيمة الرقمية.
5. **الفرق بين `guest_comments` والدردشة الحالية (`public_chat`/`private_chat`)** — هل هما نفس الشيء أم مفهوم مختلف يتطلب بناءً جديداً؟
6. **الفرق بين `event_photo_album` وصور الضيوف الحالية (`guest_photos`)** — هل هما نفس الشيء أم ألبوم رسمي منفصل؟
7. **أسعار Plus** — جدولا أسعار كلاسيك وبلس متطابقان حرفياً رغم فرق الميزات (10 ميزات إضافية موثَّقة في §1)؛ يحتاج تأكيداً تجارياً صريحاً هل هذا مقصود أم سهو، قبل أي إطلاق تجاري.

---

## 11. ربط `/create-event/` (وصف فقط — لا تنفيذ)

| العنصر | الاستخدام المقترح للـResolver | الحالة عند غياب الميزة |
|---|---|---|
| حد الدعوات | **لا علاقة له بطبقة Features** — يبقى عبر الـResolver الحالي (`pge_get_user_plan_limits_for_events()['invitation_credit_total']`) بلا أي تغيير، لأن `invitation_credit_limit` خارج نطاق هذه الطبقة كلياً (القسم 9) | لا تغيير — السلوك الحالي كما هو |
| Google Maps | `pge_user_has_feature($user_id, 'google_maps')` بدل `pge_plan_feature_enabled_for_events()` المباشرة اليوم | حقل الخريطة **Disabled** (لا تغيير في هذا السلوك الظاهري — القيمة أصبحت `true` دائماً لكلا الباقتين فعلياً بعد القرار التجاري في القسم 3) |
| صورة المناسبة | `pge_user_has_feature($user_id, 'custom_invitation_image')` (بعد حسم القسم 10) | حقل رفع الصورة **Disabled** (نفس نمط `header_img` الحالي) |
| بطاقة الميزات المفعَّلة | `pge_get_user_package_features($user_id)` لبناء قائمة الشارات بمصدر واحد بدل تكرار كل شرط يدوياً | تبقى القائمة فارغة (نفس الرسالة الحالية) |
| المضيفون الإضافيون | `pge_get_user_feature_value($user_id, 'host_limit', 1)` (بعد حسم القسم 10، بند 3) | حقل مضيف واحد فقط كما هو الحال اليوم |
| المشرف | `pge_get_user_feature_value($user_id, 'admin_supervisor_limit', 0)` (بعد حسم القسم 10، بند 4) | القسم **Hidden بالكامل** — لا Disabled |

**ملاحظة صريحة (بلا تغيير عن الإصدار السابق)**: أي حقل يعتمد على ميزة لا يوجد خلفها Backend فعلي بعد يجب أن يبقى **Hidden بالكامل**، لا Disabled — Disabled مقبول فقط لميزة تعمل فعلياً وتُقفَل بالباقة (مثل Google Maps).

---

## 12. خطة انتقال تدريجية

### المرحلة 1 — الأساس
- إنشاء `mon_tier_features` بالتصميم النهائي في القسم 5 (بلا عمود `value_type`).
- كتابة Feature Registry (القسم 6) ككود ثابت.
- بناء الـResolver الثلاثي (القسم 7) بترتيب Database→Registry→Resolver.
- بناء منطق Snapshot الجديد (`_mon_package_features`, `_mon_package_feature_version` كرقم إصدار).
- **صفر تعديل على**: `mon_plans`, `mon_plan_tiers` (أعمدة الرصيد تبقى كما هي)، `class-pge-invitation-credit-ledger.php`, `class-pge-replacement-entitlements.php`, `class-cartat-handler.php`, `class-salla-handler.php`.
- اختبارات أساسية (قراءة Boolean/Integer، فصل Catalog/Legacy، رفض أي `feature_key` غير موجود في Registry).
- **Definition of Done**: الجدول والـRegistry والـResolver موجودون ومُختبَرون، بلا أي صفحة مستخدم تستهلكهم بعد؛ صفر تغيير وظيفي ظاهر.

### المرحلة 2 — الربط الأول بـ`/create-event/`
- ترحيل `google_maps` فقط (القرار الأبسط والمحسوم تجارياً في القسم 3) من الآلية الحالية الهشّة إلى `mon_tier_features` + Registry + Resolver.
- بطاقة الميزات المفعَّلة عبر `pge_get_user_package_features()`.
- **Definition of Done**: `/create-event/` تعمل بالضبط كما تعمل اليوم (لا Regression)، وGoogle Maps يُقرأ من المصدر الجديد الموحَّد بدل الحقل الحر الهش.

### المرحلة 3 — الرسائل والصور المخصصة
- بناء قوالب `reminder`/`thank_you` + آلية جدولة إرسال جديدة (تصميم منفصل، تشبه طابور Cartat لكن مبنية على الوقت).
- ربط `decline_message` الحالي (قالب `no`) ببوابة Feature فعلية لأول مرة (اليوم متاح للجميع بلا قيد).
- حقول الصور المخصَّصة الثلاثة (بعد حسم القسم 10، بند 2 و1).
- **Definition of Done**: كلاسيك لا يرى "رسالة الاعتذار المخصصة"؛ بلس يملك قوالب تذكير/شكر فعلية تُرسَل تلقائياً في وقتها.

### المرحلة 4 — المضيفون والمشرفون
- تصميم كيان "مضيف إضافي"/"مشرف دخول" (يتطلب قراراً معمارياً منفصلاً يمس نموذج الصلاحيات `pge_is_host_or_admin()` — خارج نطاق طبقة Features وحدها).
- ربط `host_limit`/`admin_supervisor_limit` (بعد حسم القسم 10، بند 3 و4) بواجهتَي `/create-event/` و`/event-manage/`.
- **Definition of Done**: مستخدم Plus بحد `host_limit` محدَّد يستطيع فعلياً إضافة مضيفين، ومنح صلاحية Check-in لمشرف محدَّد.

### المرحلة 5 — ميزات Plus الاجتماعية والتجارية
- المعتذرون (`decline_message`) بحسب المرحلة 3.
- التعليقات/رسائل التهنئة (بعد حسم القسم 10، بند 5).
- الألبوم الرسمي (بعد حسم القسم 10، بند 6).
- الهدايا — تحويل `stc_pay` من مفتاح إداري ثابت إلى `gift_feature` يتحكم به المضيف نفسه.
- خصم الخدمات المساندة — يتطلب تصميم "خدمات مساندة" كمنتج قابل للطلب أولاً (غير موجود اليوم إطلاقاً).
- **Definition of Done**: كل ميزة حصرية لحلوة بلس تعمل فعلياً ومحجوبة تماماً عن حلوة كلاسيك عبر بوابة Feature حقيقية.

---

## 13. المخاطر

| الخطر | الوصف | أين يظهر فعلياً في الكود اليوم |
|---|---|---|
| ازدواجية Legacy/Catalog | مساران منفصلان بمنطق فصل صارم (`_mon_package_source`) — طبقة Features الجديدة يجب أن تحترم هذا الفصل حرفياً | `event-factory.php:79-84,158-160` |
| اختلاف Snapshot عن Tier الحيّ | مقصود ومُوثَّق، لكنه يعني أن تعديل ميزات Tier إدارياً **لن ينعكس** على مشتركين حاليين حتى التجديد — تبعات دعم عملاء يجب توضيحها مسبقاً | `class-mon-events-users.php:216-224` |
| `guest_limit` مقابل رصيد الدعوات المشترك | عمود `guest_limit` موجود في Schema لكن **لا مسار كتابة له** في `create_tier()`/`update_tier()` الحاليتين — أي منطق مستقبلي يفترض أنه يعمل سيفشل بصمت | مكتشَف في تدقيق سابق لهذا المشروع |
| `events_count` الحالي | قيمة افتراضية 1 لكل Tier جديد، ومُنفَّذة فعلياً في `event-factory.php:387-398` | مؤكَّد في إصلاح سابق لبطاقات Dashboard |
| الخلط بين نسبة الدعوات البديلة (أداة تخطيط) وبين `replacement_credit_limit` (قيمة تشغيلية) | القسم 3 يحسم هذا صراحة: النسبة لا تُخزَّن ولا تُقرأ وقت التشغيل أبداً — أي كود مستقبلي يحاول قراءة "نسبة" وقت الاستهلاك يخالف هذا القرار المعماري المُلزِم | — |
| ميزات تظهر بلا Backend فعلي | 12+ ميزة مطلوبة في §1 **غير موجودة إطلاقاً** خلف الاسم — عرضها في أي واجهة قبل بناء المرحلة المقابلة يخلق وعداً كاذباً للعميل | القسم 1، عمود "implemented" في Registry |
| ربط ميزة مدفوعة باسم فقط بلا Enforcement | `decline_message` مثال حي: القالب يعمل لكل المستخدمين اليوم بلا أي تحقق من الباقة | `helpers.php:pge_wa_get_templates()` |
| تغيير ميزات باقة لمشترك حالي | معالَج معمارياً عبر Snapshot (القسم 9) — الخطر الفعلي تشغيلي/دعم عملاء لا تقني | — |
| التجديد وتغيير الدورة | يجب أن تتبع طبقة Features نفس نمط الرصيد الحالي تماماً (Snapshot جديد + رقم إصدار جديد) | `class-mon-events-users.php:167-177` |
| Refund أو Downgrade | **غير معالَج في أي مكان بالكود الحالي** — `deactivate_catalog_tier()` يُبقي الـSnapshot كاملاً؛ لا منطق "تخفيض جزئي للميزات" موجود اليوم، وطبقة Features سترث هذه الفجوة كما هي | `class-mon-events-users.php:255-294` |
| إدخال Feature Key حر يتجاوز Registry | مخاطرة معمارية جديدة يمنعها صراحة القسم 8 — أي تنفيذ مستقبلي يسمح بإدخال مفتاح خارج Registry من لوحة الإدارة يُعتبر خرقاً لهذه الوثيقة | — |

---

## 14. Not Part of Feature System

الحقول والأنظمة التالية **ليست جزءاً من طبقة Features إطلاقاً**، ولا تُقرَأ ولا تُكتَب ولا تُشتَقّ عبر `mon_tier_features` أو Feature Registry أو Resolver الميزات الجديد بأي حال. تبقى بالكامل ضمن النظام الحالي المستقر (القسم 0)، ولها Resolver ومسار Snapshot خاصان بها منفصلان تماماً:

- `invitation_credit_limit` — عمود `mon_plan_tiers`، يبقى كما هو.
- `replacement_credit_limit` — عمود `mon_plan_tiers`، يبقى كما هو.
- `invitation_credit_used` — Snapshot الحالي (`_mon_invitation_credit_used`).
- `replacement_credit_used` — Snapshot الحالي (`_mon_replacement_credit_used`).
- `credit_cycle_id` — يُولَّد ويُدار بالكامل داخل `class-mon-events-users.php` الحالي.
- **Ledger** (`class-pge-invitation-credit-ledger.php`, جدول `mon_invitation_credit_ledger`) — نظام مستقل تماماً.
- **Replacement Entitlements** (`class-pge-replacement-entitlements.php`, جدول `mon_replacement_entitlements`) — نظام مستقل تماماً.

**الغرض من هذا القسم**: منع أي التباس مستقبلي بين هذه الأنظمة المستقرة القائمة وطبقة Features الجديدة — أي مطوّر يعمل مستقبلاً على هذه الوثيقة يجب أن يفهم فوراً أن هذه الحقول محكومة بمنطق مختلف تماماً، محفوظ في ملفات مختلفة، بقواعد اتساق (Atomic Locking، Ledger) لا علاقة لها بآلية القراءة/الكتابة البسيطة لطبقة Features.

---

## Architecture Freeze

الهيكل المعماري الموصوف في هذه الوثيقة (§5-§9: `mon_tier_features` بأربعة أعمدة بيانات، Feature Registry كمصدر وحيد للتعريف، Resolver بترتيب Database→Registry→Resolver→UI، Snapshot بلا Metadata، وفصل Registry التام عن أي باقة محدَّدة) **أصبح مستقراً (Frozen)**.

- لا يجوز إعادة تصميم طبقة Features من جذورها إلا عند ظهور متطلب تجاري جديد حقيقي يتعذّر استيعابه ضمن هذا التصميم كما هو.
- أي تطوير لاحق (بدءاً من المرحلة 1 في §12) يجب أن يكون **تنفيذياً فقط** — كتابة الكود والاختبارات وفق ما هو موصوف هنا حرفياً، لا إعادة نقاش القرارات المعمارية نفسها.
- أي تغيير معماري مستقبلي على هذا التصميم (لا على قيم TBD في §10، ولا على تفاصيل تنفيذية داخل نطاقه) **يجب أن يوثِّق صراحة سبب كسر هذا التجميد** — لماذا لم يعد هذا التصميم كافياً، وما المتطلب التجاري الجديد الذي استدعى ذلك — قبل أي تعديل فعلي عليه.

---
