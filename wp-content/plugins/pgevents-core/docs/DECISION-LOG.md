# Decision Log

> سجل تاريخي فقط — لا يحتوي أي قرارات حالية. يُستخدم فقط إذا اعتُمِد قرار جديد مستقبلاً.

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
