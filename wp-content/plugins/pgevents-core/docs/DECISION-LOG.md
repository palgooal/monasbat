# Decision Log

> سجل تراكمي الإضافة فقط (Append-Only) للقرارات المعمارية المعتمدة نهائياً، بترتيب
> صدورها زمنياً. هذا السجل **ليس** مصدر الحقيقة التشغيلي أو التصميمي للنظام، ولا يُغني عن
> الكود الفعلي، الاختبارات القائمة، أو وثائق التصميم الحالية (مثل ENTRY-SUPERVISORS-DESIGN.md
> أو أي وثيقة تصميم H1C مستقبلية) — عند أي تعارض، يُرجَع دوماً لتلك المصادر أولاً، ويُعامَل هذا
> السجل كسياق قرار تاريخي مرجعي فقط.

---

## 1. Purpose

توثيق أي قرار مُعتَمَد نهائياً بعد صدوره، كسجل تاريخي مرجعي.

---

## 2. Rules

- لا يُسجَّل إلا القرار المعتمد.
- لا تُسجَّل الاقتراحات.
- لا تُسجَّل الأفكار.
- لا تُسجَّل المناقشات.
- لا تُسجَّل البدائل.
- كل قرار يجب أن يكون معتمداً نهائياً.

---

## 3. Decision Template

```
Decision ID:
Date:
Title:
Reason:
Approved By:
Affected Documents:
Status:
```

---

## 4. Current Decisions

```
Decision ID: DEC-001
Date: 2026-07-26
Title: اعتماد طول feature_key في mon_tier_features
Reason: أطول مفتاح فعلي طوله 36 حرفًا، ونمط التسمية قابل للتوسع، وVARCHAR(64) يوفر هامشًا
مناسبًا دون أثر سلبي على الفهرس المركب.
Approved By: Project Owner
Affected Documents: PACKAGE-FEATURE-MATRIX.md §5, FEATURES-PHASE-2-SPEC.md
Status: Approved
```

```
Decision ID: DEC-002
Date: 2026-07-26
Title: Repository Return Contracts
Reason: عقود قيم الإرجاع الأربعة لدوال includes/class-pge-tier-features.php لم تكن
محسومة في أي مرجع، مما أوقف تنفيذ Phase 2 — Commit 2. اعتماد عقود صريحة وموحّدة
(string/null/false للقراءة المفردة، array/[]/false للقراءة الجماعية، true/false
للكتابة والحذف) يزيل الغموض ويسمح باستئناف التنفيذ دون افتراض أو اجتهاد.
Approved By: Project Owner
Affected Documents: FEATURES-PHASE-2-SPEC.md §7, FEATURES-PHASE-2-SPEC.md §13
Status: Approved

العقود المعتمدة:

get_tier_feature_value($tier_id, $feature_key):
  - string إذا كانت القيمة موجودة.
  - null إذا لم يوجد الصف.
  - false إذا فشل استعلام قاعدة البيانات.

get_all_tier_features($tier_id):
  - array إذا نجح الاستعلام (قد تكون فارغة [] إذا لم توجد أي صفوف).
  - false إذا فشل استعلام قاعدة البيانات.

set_tier_feature_value($tier_id, $feature_key, $raw_value):
  - true عند نجاح عملية الإدراج أو التحديث.
  - false عند فشل الكتابة في قاعدة البيانات.

delete_tier_feature($tier_id, $feature_key):
  - true عند نجاح تنفيذ الحذف، سواء تم حذف صف فعلياً أو لم يكن الصف موجوداً أصلاً
    (Idempotent).
  - false إذا فشل استعلام قاعدة البيانات.
```

```
Decision ID: DEC-003
Date: 2026-07-26
Title: عقود ودلالات الدوال الثلاث في Resolver (includes/feature-resolver.php)
Reason: تسع حالات Blocked في docs/FEATURES-PHASE-3-SPEC.md §16 أوقفت إمكانية البدء
بتنفيذ Phase 3. تحليل الكود الفعلي القائم (event-factory.php,
class-mon-events-users.php, class-pge-catalog.php, class-pge-feature-registry.php)
أظهر أن كل الحالات التسع قابلة للحسم دون اختراع معمارية جديدة، إما (أ) بامتداد
سلوك موثَّق فعلياً في نمط pge_get_user_plan_limits_for_events()/
pge_get_catalog_user_plan_limits_for_events() (النمط الوحيد المشابه القائم فعلاً
في المشروع)، أو (ب) بنتيجة رياضية إلزامية عن قواعد التفسير المحسومة مسبقاً
(Boolean/Integer، §9)، أو (ج) بقيد يفرضه توقيع مُعلَن مسبقاً (bool/array) لا يمكن
تغييره. لا حالة تبقى بلا حسم باستثناء ما هو مذكور صراحة في "خارج نطاق هذا القرار"
أدناه.
Approved By: Project Owner
Affected Documents: docs/FEATURES-PHASE-3-SPEC.md §7, §8, §9, §10, §13, §16
Status: Approved

== تحديد Tier للمستخدم (يحسم البندين 1 و8 من §16) ==

المصدر الوحيد: User Meta `_mon_catalog_tier_id` (يُكتب حصراً في
class-mon-events-users.php:203 ضمن activate_catalog_tier()؛ يُقرأ فعلياً بنفس
الاسم في event-factory.php:245 وclass-mon-events-users.php:186 — لا مفتاح آخر في
المشروع يخزّن معرّف Tier رقمياً قابلاً للاستعلام؛ _mon_catalog_tier_key/
_mon_catalog_tier_name نصوص عرض فقط، لا تُستخدَمان لإعادة اشتقاق tier_id في أي
موضع).

خطوة "Catalog Tier Fallback" تُنفَّذ فقط إذا تحقق الشرطان معاً (بامتداد حرفي لنمط
pge_get_catalog_user_plan_limits_for_events()، event-factory.php:91-93 وE
event-factory.php:197-199):
  1. `_mon_package_source === 'catalog'`.
  2. `_mon_package_status === 'active'`.
غير ذلك (مصدر ≠ catalog، أو حالة ≠ active): تُتخطى خطوة Tier Fallback بالكامل
مباشرة إلى الخطوة التالية في §8 (Legacy لمستخدم غير Catalog، أو Default من
Registry لمستخدم Catalog غير Active).

`_mon_catalog_tier_id` يُقرأ بـ`absint()` (مطابقاً event-factory.php:245). أي قيمة
تؤول لـ0 (فارغة، غير رقمية، أو 0 صراحة) تعني "لا Tier محدَّد" — تُتخطى خطوة Tier
Fallback، لا استعلام يُنفَّذ.

قيد موثَّق من الكود القائم (لا يُمكن تجاوزه دون تعديل PGE_Catalog، وهو ممنوع في
Phase 3): PGE_Catalog::get_tier($tier_id) (class-pge-catalog.php:739-768) يُعيد
null موحَّداً لثلاث حالات مختلفة معاً (tier_id غير صالح، الصف غير موجود، فشل
استعلام قاعدة البيانات) دون أي تمييز (لا فحص last_error، خلافاً لـDEC-002). بما
أن Resolver ممنوع من تعديل PGE_Catalog، فهذا التمييز غير متاح فعلياً على مستوى
Resolver. القرار: null من get_tier() (بكل مسبباته الثلاثة مجتمعة) يُعامَل كنتيجة
واحدة موحَّدة = "Tier غير متاح لهذا الاستدعاء" → تُتخطى خطوة Tier Fallback،
يُتابَع الترتيب في §8 إلى الخطوة التالية. هذا يحسم "Tier غير موجود" (البند 8) بنفس
آلية "Tier ID غير محدَّد" (البند 1) لأنهما غير قابلين للتمييز فعلياً عبر التبعية
المتاحة.

لا fallback من Tier إلى Plan (`_mon_catalog_plan_id` يُستخدَم حصراً كفحص اتساق
دفاعي وفق النمط القائم فعلياً في event-factory.php:247-254 — إن تعارض plan_id
المخزَّن مع plan_id الفعلي لصف الـtier المُستَرجَع، تُهمَل بيانات ذلك الـtier
بالكامل وتُعامَل كـ"Tier غير متاح"، بنفس الآلية أعلاه). لا fallback إلى Legacy
لمستخدم Catalog تحت أي ظرف (يبقى الفصل الصارم في PACKAGE-FEATURE-MATRIX.md §13
كما هو، غير متأثر بهذا القرار).

== عقد pge_get_user_feature_value($user_id, $feature_key, $default = null) (يحسم
البنود 2، 3، 4 من §16) ==

Return type: `mixed` — فعلياً `bool` (لميزات boolean) أو `int` (لميزات
integer/percentage)، ولا يُعيد أبداً null أو false كإشارة خطأ، ولا يرمي استثناء.
هذا امتداد مباشر للنمط الوحيد المشابه القائم فعلياً في المشروع
(pge_get_user_plan_limits_for_events()، event-factory.php:86-131) الذي لا يُعيد
أبداً null/false/استثناء، بل مصفوفة صفرية آمنة دائماً الاستخدام. لا نص في أي مرجع
يمنع تعميم هذا النمط، ولا نص يفرض بديلاً عنه.

ترتيب القرار الكامل لتحديد القيمة النهائية:

1. `feature_key` غير معرَّف في Registry (`PGE_Feature_Registry::get()` يعيد
   null): إرجاع بارامتر `$default` كما مُرِّر تماماً، دون أي محاولة تفسير نوع (لا
   يوجد نوع معروف لتفسيره). هذا هو الاستخدام العملي الوحيد لبارامتر `$default` —
   راجع التوضيح أدناه.
2. `feature_key` معرَّف: تحديد `type` من Registry، ثم البحث عن قيمة خام عبر ترتيب
   §8 (Snapshot → Tier Fallback → Legacy).
   - إن وُجدت قيمة خام في أي خطوة: تُطبَّق قاعدة التفسير المطابقة للنوع (§9) على
     هذه القيمة، وتُعاد النتيجة.
   - إن لم توجد أي قيمة خام في أي خطوة (بما في ذلك أي فشل قاعدة بيانات أثناء
     البحث — راجع "دلالات فشل قاعدة البيانات" أدناه، الذي يُعامَل كـ"لا قيمة"):
     تُطبَّق نفس قاعدة التفسير (§9) على حقل `default` في تعريف الميزة بـRegistry،
     وتُعاد النتيجة.

العلاقة بين `$default` الممرَّر والـ"Default الآمن من Registry": بما أن قواعد
التفسير المحسومة فعلياً (Boolean عبر in_array، Integer/Percentage عبر (int))
لا تفشل أبداً — أي قيمة خام مهما كانت (بما فيها السلسلة الحرفية 'TBD' الموجودة
فعلياً كـdefault لثلاث ميزات في class-pge-feature-registry.php الأسطر 60، 70، 80)
تُنتج قيمة bool/int صالحة دوماً عبر (int)/in_array — فإن حقل `default` بـRegistry
لا "يفشل" أبداً بالمعنى الذي يستدعي اللجوء لـ$default كبديل. لذلك: `$default`
المُمرَّر للدالة يُستخدَم **حصراً وفقط** في الحالة (1) أعلاه (feature_key غير
معرَّف إطلاقاً في Registry) — لا يُستخدَم أبداً لميزة معرَّفة، حتى لو كانت قيمة
default الخاصة بها هي 'TBD'. هذا هو الحسم الصريح المطلوب لعلاقة البارامترين.

نتيجة موثَّقة (لا تُعتبر خللاً، بل أثر مباشر لتطبيق قاعدة (int) بشكل موحَّد بلا
استثناء لحقل default): لثلاث ميزات (host_limit, admin_supervisor_limit,
invitation_design_limit)، الوصول لخطوة "Default من Registry" بلا قيمة خام مخزَّنة
ينتج `(int) 'TBD' = 0` — أي القيمة 0، لا السلسلة 'TBD' كما ورد سابقاً كـ"ملاحظة
خطر" في مسودة §7 السابقة لهذه الوثيقة (تلك الملاحظة افترضت تمرير default بلا
تفسير؛ هذا القرار يصحّح ذلك: التفسير يُطبَّق دوماً، فالناتج الفعلي 0 لا 'TBD').

== عقد pge_user_has_feature($user_id, $feature_key): bool ==

غير متأثر بالبنود أعلاه في توقيعه (لا بارامتر $default أصلاً). يتبع نفس ترتيب
البحث في §8 وينتهي بتطبيق قاعدة Boolean (§9) على الناتج (قيمة خام إن وُجدت، أو
حقل default من Registry إن لم توجد). لـfeature_key غير معرَّف في Registry: `false`
(مُشتقّ إلزاماً من نوع الإرجاع `bool` المُعلَن حرفياً في PACKAGE-FEATURE-MATRIX.md
§7 — لا قرار مُخترَع، بل نتيجة وحيدة ممكنة ضمن bool ك"Default آمن").

== عقد pge_get_user_package_features($user_id): array (يحسم البند 5 من §16) ==

الشكل: مسطّح (Flat) — `feature_key => قيمة_مُفسَّرة` لكل الميزات التسع عشرة
المُعرَّفة في Registry، بلا استثناء بحسب lifecycle (لا نص في أي مرجع يفرض تصفية
حسب lifecycle؛ Registry نفسه لا يستهلك lifecycle كفلتر تشغيلي في أي كود قائم).
هذا الشكل هو الامتداد المباشر الوحيد المتوافق مع المثال التوضيحي الحرفي الوحيد
الوارد في PACKAGE-FEATURE-MATRIX.md §9 لبنية `_mon_package_features`
(`['google_maps' => true, 'host_limit' => 2, 'gift_feature' => false]`) وهي بنية
مسطّحة بلا أي طبقة Metadata إضافية (لا Nested type/validation).

لكل مفتاح من الـ19: تُطبَّق تماماً نفس آلية `pge_get_user_feature_value()` أعلاه
(نفس ترتيب §8، نفس قاعدة التفسير حسب type، ينتهي بـdefault من Registry عند غياب
قيمة خام) — دون بارامتر `$default` مستقل لكل مفتاح (هذه الدالة لا تملك هذا
البارامتر في توقيعها المُعلَن)، وبالتالي كل مفتاح ينتهي دوماً بقيمة صالحة (حتى لو
0 للميزات الثلاث ذات TBD، وفق نفس المنطق أعلاه). المصفوفة الناتجة دوماً بحجم 19
عنصراً بالضبط، ولا تُعيد الدالة أبداً false أو مصفوفة جزئية، التزاماً بتوقيعها
المُعلَن `: array` (لا قيمة أخرى ممكنة ضمن هذا التوقيع تُعبِّر عن "فشل").

== دلالات فشل قاعدة البيانات (يحسم البند 6 من §16) ==

ينطبق على الدوال الثلاث معاً بقاعدة واحدة: أي استدعاء لـ
`PGE_Tier_Features::get_tier_feature_value()`/`get_all_tier_features()` يُعيد
`false` (فشل استعلام فعلي وفق DEC-002) يُعامَل مطابقاً تماماً لحالة "لا قيمة
موجودة في هذه الخطوة" — أي يُتابَع ترتيب §8 إلى الخطوة التالية (Legacy، ثم Default
من Registry)، بلا أي إشارة خطأ منفصلة تصل للمستدعي. نفس القاعدة تنطبق على أي فشل
مكافئ من جهة Catalog (PGE_Catalog::get_tier() المُعامَل أعلاه كـ"Tier غير متاح").

سبب هذا القرار: توقيعات الدوال الثلاث المُعلَنة مسبقاً في
PACKAGE-FEATURE-MATRIX.md §7 (`bool`، بلا نوع مُعلَن لكن مُحسوم أعلاه كـ`mixed`
غير null/false، و`array`) لا تملك أي قيمة إرجاع مخصَّصة للتعبير عن "فشل قاعدة
بيانات" دون مخالفة التوقيع نفسه — فامتصاص الفشل ضمن سلسلة "لا قيمة" هو الخيار
الوحيد المتوافق مع التوقيعات المُعلَنة أصلاً، لا اختراعاً جديداً. لا Logging يُضاف
لهذا القرار (لا نص في أي مرجع يطلبه ضمن نطاق Resolver).

== تفسير Percentage (يحسم البند 7 من §16) ==

يُعامَل بنفس آلية Integer تماماً: `(int) $raw_value`، بلا Clamp لأي نطاق، بلا رفض
لأي قيمة خارج 0-100، بلا أي منطق إضافي. السبب: لا تفريق فعلي بين النوعين في أي
كود قائم أو نص مرجعي بخصوص إنفاذ الحدود — حقل `validation` في Registry نص وصفي
حر (`'0-100 عدد صحيح'` لـpercentage، مقابل `'عدد صحيح ≥ 0'` لبعض ميزات integer)
غير قابل للتحليل برمجياً (ليس بنية min/max مهيكلة)، وغير مُستهلَك فعلياً في أي
موضع من الكود القائم لأي نوع — القاعدة المحسومة مسبقاً لـInteger (§9: "لا نص في
§7 يفرض تطبيق قاعدة validation هذه داخل Resolver نفسه") تنطبق بنفس المنطق حرفياً
على Percentage، إذ لا أساس للتفريق بينهما. Return type: `int`.

== خارج نطاق هذا القرار (يبقى كما هو، غير محسوم عمداً) ==

لا شيء تبقى بلا حسم من البنود التسعة الأصلية في §16 — كل بند مُغطّى أعلاه. هذا
القرار لا يُغيّر: عقود Repository (DEC-002)، بنية Registry (Phase 1)، ترتيب
مصادر §8 الأصلي (Snapshot→Tier→Legacy→Default)، تقسيم Snapshot بين Phase 3
(قراءة) وPhase 4 (كتابة)، أو أي ملف ممنوع تعديله وفق §5 من
docs/FEATURES-PHASE-3-SPEC.md.

== توضيح إضافي على DEC-003 قبل بدء التنفيذ (لا تغيير في القرار، توضيح فقط) ==

قبل إصدار برومبت تنفيذ Phase 3، رُوجعت نقطتان معماريتان محتملتا اللبس في القرار
أعلاه، ولم يظهر أي تعارض يستدعي تعديل القرار نفسه — فقط توضيح صريح يمنع الالتباس
وقت التنفيذ:

**1) دخول Snapshot ضمن Resolution Precedence رغم أن كتابته (Phase 4) لم تبدأ
بعد**: هذا **مقصود ومحسوم في المرجع الأعلى سلطة**، لا خطأ في DEC-003.
`PACKAGE-FEATURE-MATRIX.md §7` (Frozen، أعلى سلطة في `WORKING-AGREEMENT.md §2`)
يُعرِّف ترتيب مصادر الـResolver بلا أي تقييد بمرحلة تنفيذية: "Catalog Snapshot
(`_mon_package_features`) → Catalog Tier ... → Legacy → Default". هذا هو تعريف
الـResolver نفسه، لا تفصيل إضافي اختياري. `FEATURES-IMPLEMENTATION-PLAN.md`
(سلطة أدنى) يؤكد ذلك صراحة داخل قسم Phase 3 نفسه: Acceptance Criteria "ترتيب
المصادر مطابق حرفياً (Snapshot→Tier Fallback→Legacy→Default)"، والاختبارات
المطلوبة "مستخدم Catalog بميزة boolean = true في **Snapshot وهمي**". "ملاحظة على
الترتيب" في نفس الوثيقة تخص فقط اكتمال **السلوك الفعلي مع بيانات حقيقية** بعد
اكتمال Phase 4 (كتابة Snapshot لمستخدمين حقيقيين) — لا تعني أن كود القراءة نفسه
يعتمد على أي كود من Phase 4. الدليل الحاسم: جدول Dependencies الرسمي
(`FEATURES-IMPLEMENTATION-PLAN.md §3`) يسرد اعتماد Resolver (Phase 3) على
"Feature Registry, Tier Features" فقط — **لا Snapshot/Phase 4 مذكور إطلاقاً** —
بينما Phase 4 نفسها تعتمد على "..., Resolver"، أي أن Phase 3 يجب أن تكتمل قبل
Phase 4 لا العكس؛ هذا مستحيل منطقياً لو كان كود Phase 3 يستدعي كوداً من Phase 4.

الكود الفعلي المطلوب في Phase 3 لقراءة Snapshot هو قراءة خام لـUser Meta فقط
(`get_user_meta($user_id, '_mon_package_features', true)`) — **بلا أي استدعاء
لأي Class/Function من Phase 4** (لا شيء في Phase 4 يُنشئ دالة أو Class يُستدعى؛
Phase 4 فقط يكتب هذا المفتاح داخل `activate_catalog_tier()` الموجودة أصلاً).
قبل اكتمال Phase 4، هذا المفتاح ببساطة غير موجود لمعظم/كل المستخدمين، فتُعيد
`get_user_meta()` سلسلة فارغة `''` (سلوك ووردبريس القياسي لمفتاح غير موجود) —
وهي ليست Array، فيُعامَل هذا تلقائياً كـ"لا Snapshot"، ويُتابَع الترتيب إلى Tier
Fallback، **بلا أي خطأ أو استثناء أو حالة خاصة**. هذا هو نفس السلوك الذي
سيستمر بعده تماماً لأي مستخدم لم يُفعَّل له Snapshot بعد حتى بعد اكتمال Phase 4.

**الحسم**: هذا هو **الخيار C** من الخيارات الثلاثة المطروحة للمراجعة — Snapshot
جزء فعلي من قراءة Phase 3 — **وليس اعتماداً بالاستنتاج فقط**، بل منصوص عليه
حرفياً في Acceptance Criteria وTesting الخاصين بـPhase 3 نفسها في
`FEATURES-IMPLEMENTATION-PLAN.md`، وفي `PACKAGE-FEATURE-MATRIX.md §7` (تعريف
الـResolver ذاته، أعلى سلطة). **ليس Placeholder ولا Stub** — كود قراءة حقيقي
وفعّال، يُختبَر عبر Seed مباشر لقيمة `_mon_package_features` في الاختبار (بنفس
نمط `set_test_user_meta()` المستخدم فعلياً في `tests/test-invitation-credit-ledger.php`)،
بلا أي اعتماد على منطق الكتابة الذي يُبنى لاحقاً في Phase 4. Phase 4 نفسها **لن
تُعدِّل Resolver مطلقاً** — عملها محصور بالكامل داخل `activate_catalog_tier()`
في `class-mon-events-users.php` (كتابة المفتاح الذي يقرأه Resolver أصلاً منذ
Phase 3)؛ Public API/كود Resolver لا يتغيران بين Phase 3 وPhase 4.

**2) عقد `pge_get_user_feature_value()` المحصور فعلياً في bool/int**: هذا أيضاً
**محسوم وصحيح كما ورد في DEC-003 (`mixed`)**، بأدلة إضافية أدق لم تُذكر صراحة في
النص الأصلي، تُضاف هنا للتوثيق لا لتغيير القرار:
- `PACKAGE-FEATURE-MATRIX.md §7` تُعلِن توقيع هذه الدالة تحديداً **بلا** `: type`
  في نهايته (خلافاً لرفيقتيها `pge_user_has_feature(...): bool` و
  `pge_get_user_package_features(...): array` اللتين تحملان إعلاناً صريحاً) —
  هذا الغياب مقصود وليس سهواً (التبايُن بين الدوال الثلاث واضح في نفس الجملة)،
  لذا **لا يُضاف أي PHP Return Type Declaration** لهذه الدالة تحديداً عند
  التنفيذ — إضافة واحد الآن تُعتبر تعديلاً معمارياً على توقيع مُجمَّد (Frozen)
  يتطلب تبريراً صريحاً لكسر التجميد (`PACKAGE-FEATURE-MATRIX.md`, "Architecture
  Freeze")، ولا مبرر كهذا مذكور في أي مرجع.
- `§7` "كيف تُقرأ الأنواع" يُخصِّص Boolean حصراً لـ`pge_user_has_feature()`، ولا
  يذكر `pge_get_user_feature_value()` إطلاقاً في سياق Boolean — الاستخدام
  الموثَّق/المرجعي الوحيد لهذه الدالة في كل المرجع المعماري هو لـ`integer`
  (مثال `§11`: `pge_get_user_feature_value($user_id, 'host_limit', 1)`) ولـ
  `percentage`. عملياً هذا يعني: القيمة الناتجة **الموثَّقة والمقصودة** هي `int`
  في كل استخدام معروف اليوم؛ إعادة `bool` منها تبقى ممكنة نظرياً فقط إن استُدعيت
  لميزة `boolean` (طبقاً للقاعدة العامة في §7: "يسأل Registry عن النوع، ثم يطبّق
  قاعدة التفسير لذلك النوع فقط" — قاعدة عامة غير مقصورة على دالة بعينها)، لكنه
  استخدام خارج النمط الموثَّق، لا مثال فعلي عليه في أي مرجع.
- `$default = null` **بلا Type Hint** في التوقيع المُجمَّد — يقبل أي نوع من
  المستدعي. وبما أن DEC-003 يحسم أن `$default` يُعاد **حرفياً بلا تفسير** عند
  `feature_key` غير معرَّف في Registry، فإن النوع الفعلي المُعاد في هذه الحالة
  تحديداً هو نوع `$default` نفسه أياً كان — هذا سبب إضافي **قائم اليوم فعلياً**
  (لا افتراضي/مستقبلي) يجعل `mixed` هو الوصف الدقيق الوحيد، لا لأن Registry قد
  يتوسع لاحقاً لأنواع جديدة (لا نص في أي مرجع يذكر أنواعاً مستقبلية كـ
  `string`/`enum`/`array` — هذا الأساس **غير موجود**، فلا يُعتمَد كسبب).
- **الحسم**: العقد الموثَّق هو `mixed`، بلا PHP Return Type Declaration، مع
  توضيح أن الاستخدام الفعلي/الموثَّق اليوم هو `int` (integer/percentage) كحالة
  أساسية موثَّقة بالمرجع، و`$default` كما مُرِّر (أي نوع) لحالة feature_key غير
  معرَّف، و`bool` كحالة نظرية ممكنة لكن غير موثَّقة بمثال فعلي إن استُدعيت لميزة
  boolean. لو أُضيفت أنواع Registry جديدة مستقبلاً (لا أساس نصي لذلك اليوم)، هذا
  لن يكسر الـPublic API لأنه بلا Type Declaration أصلاً.

لا تعديل على نص القرار الأصلي أعلاه — هذا توضيح تمهيدي قبل التنفيذ
(Pre-Implementation Clarification)، لا تغييراً بعد الإنتاج.
```

---

```
Decision ID: DEC-004
Date: 2026-08-15
Title: H1C-W1 Guest Read Architecture — قبول مؤقت + Technical Debt (H1C-GR1)
Reason: مراجعة Phase H1C-W1D أثبتت من الكود الفعلي أن قراءة الضيوف للمتعاونين
(PGE_Event_Access_Application_Service::list_for_collaborator()) تحسم الـScope
بالكامل عبر H1B (Authorization/Result/Projection scoping = YES) قبل أي لمس
لـPost Meta، لكن pge_event_guests_get_map($event_id) — الدالة الوحيدة المتاحة
لقراءة تفاصيل الضيوف — تُحمِّل حتماً كامل الـblob المُسلسَل لـ_pge_invited_guests
في ذاكرة PHP دفعة واحدة، إذ لا يوجد بديل أضيق في الكود القائم (Storage/PII
scoping = NO). هذا ليس ثغرة تفويض ولا تسريب بيانات للعميل (لا شيء يتجاوز
الاستخراج المصرَّح به يصل لأي Response) — التوصيف الدقيق: server-side
over-fetch / defense-in-depth weakness / architectural limitation. نظراً لضيق
أثر المشكلة (مسار قراءة واحد فقط في W1) مقابل حجم أي إعادة تصميم لتخزين الضيوف
(consumer inventory: عشرات نقاط القراءة/الكتابة عبر Invitation/Messaging/
Check-in/Thank-You)، اعتُمد قبول H1C-W1 مؤقتاً كما هو معماريًا دون حجب
الـCommit، مع تسجيل الفجوة كـTechnical Debt رسمي بدلاً من تجاهلها أو المبالغة
في تصنيفها.
Approved By: Project Owner
Affected Documents: includes/class-pge-event-access-application-service.php
Status: Approved

== الصياغة المعتمدة لوصف السلوك الحالي (لا غيرها) ==

مسموح: "scope-before-load with scoped projection over a full serialized guest
blob" — أي: الـScope يُحسَم أولاً على طبقة H1B العلائقية، ثم يُحمَّل كامل
الـblob حتماً، ثم تُستخرَج فقط الهواتف المصرَّح بها مسبقاً، ثم يُعاد فقط ما مر
عبر project_guest_fields() بالإسقاط الصحيح للدور.

ممنوع: "only authorized guest PII is loaded" أو "out-of-scope guest PII is
never loaded" أو أي صياغة مكافئة توحي بأن التحميل نفسه (لا الإرجاع) كان محدوداً
بالـScope.

== Technical Debt المسجَّل ==

Phase H1C-GR1 — Relational Guest Read Projection (لم تبدأ، لا Schema/Migration
حتى الآن): إضافة طبقة قراءة علائقية مُشتقّة (derived/rebuildable) لتفاصيل
الضيوف، تُزامَن عبر نقطة الكتابة الموحّدة الموجودة أصلاً
pge_event_guests_save_map()، بينما يبقى _pge_invited_guests (Post Meta) هو
مصدر الحقيقة (Source-of-Truth Model 1). لا ترحيل كامل عن Post Meta (تم استبعاد
هذا الخيار صراحة لعدم تناسبه مع حجم المشكلة الفعلي في هذه المرحلة).

== تحديث لاحق (2026-08-21): إنجاز H1C-GR1 — لا تغيير على نص القرار الأصلي أعلاه ==

Phase H1C-GR1 اكتملت وأصبحت مُستهلَكة فعلياً في الكود القائم — هذا يُصحِّح الوصف
"لم تبدأ" أعلاه فقط، ولا يمس بقية القرار (الموافقة على قبول H1C-W1 كما كان
وقتها، وتوثيق الفجوة كـTechnical Debt، تبقى وقائع تاريخية صحيحة كما وردت).
تحقُّق فعلي من الكود الحالي (لا استنتاج): includes/class-pge-event-guest-read-
projection-schema.php وincludes/class-pge-event-guest-read-projection.php
موجودتان فعلياً، ومُحمَّلتان صراحة في pgevents-core.php قبل Authorization/
Application Service. مسار قراءة المتعاونين (Manager/Viewer) في
PGE_Event_Access_Application_Service لم يعد يستدعي pge_event_guests_get_map()
إطلاقاً — أصبح يستدعي PGE_Event_Guest_Read_Projection::get_guests_by_phones()
مباشرة (طبقة علائقية مُشتقّة تُقرأ بالهاتف المصرَّح به فقط)، مع
rebuild_event()/is_ready() كآلية إعادة بناء واستعادة عند الحاجة. أي: القيد
الموصوف أعلاه ضمن "الصياغة المعتمدة لوصف السلوك الحالي" (Storage/PII
scoping = NO لهذا المسار تحديداً) أصبح الآن NO→YES لمسار المتعاونين. لا تحقق
تم من أي مسار آخر خارج هذا المسار ضمن هذا التحديث — لا يُفترَض حسمه.
_pge_invited_guests (Post Meta) لا يزال Source-of-Truth كما تقرر أصلاً (Model
1، بلا ترحيل كامل)، مطابقاً تماماً لما كان مخطَّطاً في نص القرار الأصلي.
Status (لهذا التحديث فقط): Resolved.
```

---

```
Decision ID: DEC-005
Date: 2026-08-21
Title: نموذج "الداعي الإضافي" (Additional Inviter) — عضوية Event Access، لا نظام تفويض موازٍ
Reason: احتاج H1C-W8/W9/W10 حسم كيف يُمثَّل "الداعي الإضافي" معمارياً. القرار المعتمد: لا
كيان أو نظام تفويض جديد — الداعي الإضافي هو ببساطة عضوية موجودة أصلاً في
PGE_Event_Access_Repository تحقق الشرط الثلاثي معاً: status = active AND role = manager AND
allocated_quota IS NOT NULL، مقيَّدة (Scoped) بمجموعة واحدة (group) بالضبط لكل عضوية. هذا
الثلاثي هو الفيصل الوحيد المعتمد لتمييز "مدير عادي" عن "داعٍ إضافي" في كل نقاط الفحص القائمة
داخل class-pge-event-access-repository.php — لا عمود/حقل منفصل is_additional_inviter في قاعدة
البيانات، ولا جدول جديد. أي مسار مستقبلي يحتاج التمييز بين الاثنين يجب أن يستخدم نفس هذا
الثلاثي، لا يخترع فحصاً موازياً.
Approved By: Project Owner
Affected Documents: includes/class-pge-event-access-repository.php
Status: Approved
```

```
Decision ID: DEC-006
Date: 2026-08-21
Title: نمط "انهيار السلطة قبل الفحص" (EC1) — عدم كشف وجود المورد عبر رسالة الخطأ
Reason: عبر طبقات Application المتعددة (class-pge-event-access-application-service.php،
class-pge-additional-inviter-onboarding.php) تقرر أن أي WP_Error صادر من resolve_context()/
resolve_event_actor_context() — سواء كان السبب "المستخدم غير مخوَّل لمورد موجود فعلاً" أو
"المورد (event_id) غير موجود أصلاً" — ينهار إلى نفس نتيجة not_authorized الخارجية تماماً، بلا
أي تمييز في الرسالة أو رمز الحالة يسمح لطرف خارجي باستنتاج ما إذا كان المورد موجوداً أصلاً.
يُنفَّذ هذا عبر دالة resolve_actor_context() محلية (مُكرَّرة عمداً في كل Class من طبقة
Application تحتاجه)، لا عبر Helper مشترك واحد، لتفادي اقتران غير مرغوب بين الطبقات.
Approved By: Project Owner
Affected Documents: includes/class-pge-event-access-application-service.php,
includes/class-pge-additional-inviter-onboarding.php
Status: Approved
```

```
Decision ID: DEC-007
Date: 2026-08-21
Title: بنية دعوة انضمام "الداعي الإضافي" (H1C-W10 Onboarding) — التوكن كسلطة وحيدة، والتسليم عبر wp_mail() مباشرة
Reason: احتاج H1C-W10 حسم كيف تُدار دعوة انضمام مستخدم (موجود أو جديد) كداعٍ إضافي. القرارات
المعتمدة مجتمعة:
(أ) التوكن الخام (bin2hex(random_bytes(32))، 64 حرف hex) هو السلطة الوحيدة على الدعوة — لا
يُخزَّن أبداً، فقط SHA-256 hash الخاص به. المعاينة (GET، preview_onboarding_token()) لا تُنفِّذ
أي UPDATE مطلقاً (Link Preview Safety، بنفس فلسفة templates/supervisor-login-confirm.php).
الإتمام (POST، finish_completion()) لا يستهلك التوكن إلا بعد نجاح إنشاء العضوية فعلياً — لا
استهلاك مبكر يُفشِل عملية لاحقة بلا رجعة.
(ب) حساب WP جديد يُنشأ عبر wp_create_user() لا يُحذَف ولا يُراجَع (Rollback) أبداً عند فشل
إنشاء العضوية لاحقاً في نفس الطلب — يسمح هذا بإعادة محاولة آمنة دون تكرار إنشاء حسابات.
(ج) **محصور حصراً بمسار إتمام دعوة الانضمام المُتحقَّق منه (W10 Onboarding Completion) بعد
اجتياز التسلسل الكامل**: (1) التحقق من التوكن الخام مقابل الـSHA-256 hash المخزَّن، (2) التحقق
من أن صف الدعوة لا يزال pending وغير منتهي الصلاحية، (3) إعادة التحقق من صف الدعوة نفسه عند
لحظة الإتمام فعلياً (لا وثوق بما سبق)، (4) اجتياز ثوابت العضوية/المجموعة (membership/group
invariants) في PGE_Event_Access_Repository — فقط عندها actor_user_id المُمرَّر لـ
PGE_Event_Access_Repository::create_additional_inviter_membership() هو Audit Attribution فقط
(يُسجَّل من هوية الداعي الأصلي وقت إنشاء الدعوة)، لا فحص تفويض؛ الفحص الفعلي الوحيد هو إعادة
التحقق من صف الدعوة نفسه عند الإتمام كما ورد أعلاه. **هذا القرار لا يُعامَل كإذن عام** لتمرير
actor ID تاريخي إلى أي كتابة عضوية أخرى في Repository من أي مسار كود آخر، أو بلا اجتياز نفس
سلسلة التحقق الأربع أعلاه بالكامل — نطاقه محصور بهذا المسار المُتحقَّق منه وحده. هذا ليس
"انتحال جلسة" (Session Impersonation) لأن لا جلسة تُنتحَل — فقط إسناد Audit لعملية أثبتت الدعوة
الأصلية صلاحيتها وقت إنشائها، ضمن هذا المسار المحدد فقط. لا تغيير على السلوك الفعلي للكود —
توضيح نطاق توثيقي فقط.
(د) التسليم البريدي لهذه الدعوة يستخدم wp_mail() مباشرة (includes/class-pge-additional-
inviter-onboarding.php) — بمعزل تام عن أنبوب Reminder/Thank-You الموثَّق في
MESSAGING-ARCHITECTURE.md. قرار نطاق (Scope) لا سهو: لا حاجة موثَّقة لجدولة/قوالب/تتبع حالة
تسليم على مستوى Messaging Pipeline لدعوة تُرسَل مرة واحدة عند إنشائها.

== توضيح مصطلحات (لا قرار جديد، فقط لمنع الالتباس) ==

"Invitation" تُستخدَم في وثائق ومشروع Monasbat بمعنيين مختلفين تماماً، ثبت التباسهما فعلياً
أثناء مراجعة التوثيق (DOCS-REVIEW-1)، يجب عدم الخلط بينهما مستقبلاً:

1. "Guest Invitation" (دعوة الضيف) — السجل الأقدم من Phase 9، المرتبط بـ_pge_invited_guests
   (Post Meta)، الموثَّق في INVITATION-QR-ARCHITECTURE.md وINVITATION-BULK-ADD.md
   وINVITATION-EXPORT.md وINVITATION-GUEST-LIMIT-ENFORCEMENT.md — دورة حياته بلا علاقة
   إطلاقاً بـH1C.

2. "Additional Inviter Onboarding Invitation" (دعوة انضمام الداعي الإضافي) — سجل جديد بالكامل
   من H1C-W10 (جدول مستقل، توكن SHA-256، دورة حياة منفصلة تماماً: pending → consumed/expired/
   revoked)، لا علاقة له بالسجل أعلاه ولا يُخزَّن في _pge_invited_guests.

عند الإشارة لأي منهما في وثائق مستقبلية أو مراجعات كود، يُستخدَم المصطلح الكامل ("دعوة
الضيف" أو "دعوة انضمام الداعي الإضافي") لا "دعوة" وحدها.
Approved By: Project Owner
Affected Documents: includes/class-pge-additional-inviter-onboarding.php,
includes/additional-inviter-onboarding-ajax.php, docs/MESSAGING-ARCHITECTURE.md (بالتمييز فقط،
بلا تعديل على محتواها)
Status: Approved
```

```
Decision ID: DEC-008
Date: 2026-08-21
Title: حدود واجهة الخدمة الذاتية لـ"الداعي الإضافي" (H1C-UI-2 / REAL-USER-FIX-1)
Reason: احتاج H1C-UI-2 وREAL-USER-FIX-1 حسم حدود واجهة المستخدم بين المالك (Owner) والداعي
الإضافي، كقرار معماري/منتجي مجرَّد — التفاصيل التنفيذية الدقيقة (أسماء الملفات، إجراءات AJAX
المحددة، حقول الإسقاط، آلية بوابة العرض داخل كل Template) تخص وثيقة تصميم H1C مستقبلية
(H1C-EVENT-ACCESS-DESIGN.md)، لا هذا السجل، وقد تتغير دون أن يتغير القرار أدناه. المبادئ
الدائمة المعتمدة:
(أ) المالك/الأدمن هو من يدير "فريق الدعوة" (Invitation Team) بالكامل؛ الداعي الإضافي لا يصل
لهذه الإدارة إطلاقاً، ويُخدَم عبر تجربة خدمة ذاتية منفصلة تماماً ("دعواتي" / My Invitations)
لا عبر نفس شاشة المالك بصلاحيات مخفَّضة.
(ب) التفويض الفعلي يبقى خادمي المصدر بالكامل (Backend-Driven) في كل الحالات — لا Template يُعيد
تنفيذ منطق تفويض أو يفترضه من مجرد عرض الواجهة.
(ج) وصول الداعي الإضافي لبيانات الضيوف مقيَّد النطاق (Scoped) تبعاً لنفس إسقاط/صلاحيات المدير
(Manager projection/capabilities) القائمة أصلاً — واجهة الخدمة الذاتية لا توسِّع ولا تتجاوز هذا
النطاق.
(د) وجهة إعادة التوجيه بعد نجاح الانضمام تُشتَق حصراً من سياق الحدث (event context) الموثوق
خادمياً — لا تُقبَل أبداً وجهة أو هوية حدث من مدخلات العميل (Client-Supplied) لهذا الغرض.
Approved By: Project Owner
Affected Documents: منظومة واجهة "الداعي الإضافي" في H1C (الملفات والإجراءات التنفيذية الدقيقة
تُوثَّق لاحقاً في H1C-EVENT-ACCESS-DESIGN.md، لا تُجمَّد هنا)
Status: Approved
```

---
