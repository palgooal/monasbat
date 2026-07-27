# Features — Phase 3 Specification (Resolver)

> مواصفات تنفيذ دقيقة (Execution Specification) — **ليست وثيقة معمارية، وليست خطة مشروع، وليست إعادة تصميم.**
> لا قرار معماري جديد هنا، ولا تفسير أو تغيير لأي قرار قائم، ولا اقتراح تحسين.
> كل بند غير محسوم حرفياً في المراجع أدناه يُكتب `Blocked`.
> تاريخ الإعداد: 2026-07-26.

**المراجع الملزمة (لا تُكرَّر محتواها هنا، يُحال إليها عند الحاجة):**
- `docs/PACKAGE-FEATURE-MATRIX.md` — المرجع المعماري النهائي (Frozen)، تحديداً §7 (Resolver).
- `docs/FEATURES-IMPLEMENTATION-PLAN.md` — خطة التنفيذ العامة، تحديداً قسم "Phase 3 — Resolver"، §3 (Dependencies)، §5 (Files Expected To Change)، §7 (Implementation Order، Commits 6-7).
- `docs/FEATURES-PHASE-1-SPEC.md` — نطاق ومخرجات Phase 1 (Feature Registry)، Precondition لهذه المرحلة.
- `docs/FEATURES-PHASE-2-SPEC.md` — نطاق ومخرجات Phase 2 (Tier Features Storage)، Precondition لهذه المرحلة.
- `docs/PHASE-1-STATUS.md` — يؤكد اكتمال Phase 1 فعلياً (`Ready For Merge: Yes`).
- `docs/DECISION-LOG.md` — يحتوي حالياً ثلاثة قرارات معتمدة: `DEC-001` (طول `feature_key` = `VARCHAR(64)`)، `DEC-002` (Repository Return Contracts لـ`includes/class-pge-tier-features.php`)، و`DEC-003` (عقود ودلالات الدوال الثلاث في Resolver — تحديد Tier، عقد `pge_get_user_feature_value()`، شكل `pge_get_user_package_features()`، دلالات فشل قاعدة البيانات، تفسير Percentage). الثلاثة يُستشهَد بها أدناه حيث تنطبق مباشرة على عقود Resolver.

**التنفيذ الفعلي الحالي المُطَّلَع عليه** (بلا أي تعديل عليه ضمن هذه الوثيقة): `includes/class-pge-feature-registry.php`، `includes/class-pge-tier-features.php`، `includes/class-pge-catalog.php`، `includes/class-mon-events-users.php`، `pgevents-core.php`، `tests/test-feature-registry.php`، `tests/test-tier-features.php`.

---

## 1. Phase Overview

**الهدف**: بناء Resolver يقرأ تعريف الميزة (نوعها، قيمتها الافتراضية) من Feature Registry عبر Registry Provider (Phase 1)، وقيمتها الخام الخاصة بالـTier من Tier Features Repository (Phase 2)، ثم يُعيد قيمة **مفهومة** (Interpreted) حسب نوع الميزة — لا نصاً خاماً — للطبقات الأعلى.

**ملاحظة حرجة على الترتيب** (منقولة حرفياً من `FEATURES-IMPLEMENTATION-PLAN.md`، Phase 3، "ملاحظة على الترتيب"): "الـResolver يقرأ من Snapshot (§7: 'Catalog Snapshot أولاً') الذي يُبنى في Phase 4 — لذا اكتمال منطق الـResolver الفعلي (لا مجرد هيكله) يتطلب وجود Phase 4 أيضاً." بعبارة أخرى: **كود قراءة Snapshot جزء من Phase 3 نفسها** (الترتيب الكامل Snapshot→Tier Fallback→Legacy→Default مُطبَّق هنا بالكامل)، لكن **آلية كتابة Snapshot** (`_mon_package_features`) تبقى حصراً في Phase 4 ولا تُبنى هنا. Phase 3 يُختبَر بمحاكاة صريحة (Seed) لقيم Snapshot، لا ببناء الكتابة الفعلية لها.

**توضيح صريح (`DEC-003`، مراجعة ما قبل التنفيذ)**: هذا **ليس Placeholder أو Stub** — كود قراءة حقيقي وفعّال (`get_user_meta($user_id, '_mon_package_features', true)`)، **بلا أي استدعاء لأي كود من Phase 4**. `PACKAGE-FEATURE-MATRIX.md §7` (Frozen، أعلى سلطة) يُعرِّف ترتيب الـResolver بلا أي تقييد بمرحلة تنفيذية؛ جدول Dependencies الرسمي (`FEATURES-IMPLEMENTATION-PLAN.md §3`) يسرد اعتماد Resolver على "Feature Registry, Tier Features" فقط — لا Snapshot/Phase 4 إطلاقاً — بينما Phase 4 نفسها تعتمد على Resolver، لا العكس. قبل اكتمال Phase 4، المفتاح غير موجود لمعظم المستخدمين، فتُعيد `get_user_meta()` `''` (غير Array)، فيُعامَل تلقائياً كـ"لا Snapshot"، ويُتابَع الترتيب لـTier Fallback — بلا خطأ أو حالة خاصة. **Phase 4 لن تُعدِّل Resolver مطلقاً** — عملها محصور في `activate_catalog_tier()` فقط (كتابة المفتاح الذي يقرأه Resolver أصلاً منذ Phase 3).

**النطاق**: بناء الدوال الثلاث في §7 من الوثيقة المعمارية فقط، بمعزل عن أي كتابة أو استهلاك خارجي فعلي.

**ما الذي لن يتم بناؤه في هذه المرحلة**:
- **آلية كتابة Snapshot** (`_mon_package_features`, `_mon_package_feature_version`) — Phase 4 حصراً (`FEATURES-IMPLEMENTATION-PLAN.md`، Phase 4).
- أي واجهة إدارة (Admin UI) — Phase 5.
- أي ربط بـ`/create-event/` أو أي صفحة أمامية — Phase 6.
- أي تعديل على الرصيد (`invitation_credit_limit`/`replacement_credit_limit`، الـLedger، الـReplacement Entitlements) — خارج نطاق طبقة Features بالكامل (`PACKAGE-FEATURE-MATRIX.md §14`).
- أي تعديل على Catalog (`PGE_Catalog`, `mon_plans`, `mon_plan_tiers`).
- أي تعديل على Feature Registry (Phase 1) أو Tier Features Repository (Phase 2) — لا نص صريح في أي مرجع يطلب تعديلهما ضمن Phase 3، فيبقيان بلا لمس.

---

## 2. Architecture Scope

الطبقات التي يتعامل معها Resolver، حرفياً وفق `PACKAGE-FEATURE-MATRIX.md §7` وDependency Direction (§6):

- **Feature Registry** (عبر `PGE_Feature_Registry::get()`/`has()`/`all()`، Phase 1): مصدر تعريف الميزة (`type`, `default`, `validation`, إلخ). الوصول **حصراً** عبر Registry Provider — "لا يجوز لأي طبقة أدنى... الاعتماد مباشرة على Registry الداخلي" (§6 "Dependency Direction").
- **Tier Features Repository** (عبر `PGE_Tier_Features::get_tier_feature_value()`/`get_all_tier_features()`، Phase 2): مصدر القيمة الخام الخاصة بالـTier، حصراً كمصدر Fallback دفاعي (راجع §5 أدناه) — ليس المصدر الأول.
- **Catalog** (`PGE_Catalog`, User Meta الخاص بـCatalog): يُستخدَم **فقط** لتحديد Tier أو Plan المستخدم — آلية التحديد محسومة عبر `_mon_catalog_tier_id` وفق `DEC-003` (راجع §8، §16).
- **Legacy** (`PGE_Packages`, `_mon_active_features`): مصدر بيانات مستقل تماماً لمستخدمي `_mon_package_source !== 'catalog'` فقط، وفق الفصل الصارم الموثَّق في `PACKAGE-FEATURE-MATRIX.md §13` ("ازدواجية Legacy/Catalog").

**لا يفترض Resolver وصولاً لـUser Meta الخاص بـSnapshot الميزات الجديد (`_mon_package_features`) على أنه موجود بالضرورة** — Phase 4 (الكتابة الفعلية له) قد تكون غير مكتملة بعد وقت اختبار Phase 3؛ الكود يجب أن يتعامل مع غيابه كحالة طبيعية (Fallback)، لا كخطأ.

---

## 3. Files To Create

| الملف | المرجع الحرفي |
|---|---|
| `includes/feature-resolver.php` | `FEATURES-IMPLEMENTATION-PLAN.md`، Phase 3، "الملفات الجديدة": "`includes/feature-resolver.php` — يحوي الدوال الثلاث المذكورة في §7 حرفياً" |

اسم الملف **محدَّد حرفياً في المرجع** — لا حاجة لأي `Blocked` هنا (خلافاً لتحذير الطلب من افتراض اسم؛ الاسم موجود نصاً).

---

## 4. Files To Modify

| الملف | التعديل | المرجع الحرفي |
|---|---|---|
| `pgevents-core.php` | إضافة سطر `require_once` واحد لتحميل `includes/feature-resolver.php` | `FEATURES-IMPLEMENTATION-PLAN.md`، Phase 3، "الملفات المتوقع تعديلها": "`pgevents-core.php` — `require_once` للملف الجديد فقط" |

لا ملف آخر مسموح تعديله. لا تعديل على `includes/class-pge-feature-registry.php` ولا `includes/class-pge-tier-features.php` — لا نص صريح في أي مرجع يطلب أي تعديل عليهما ضمن Phase 3 ("الملفات المتوقع تعديلها" في Phase 3 تذكر `pgevents-core.php` فقط).

---

## 5. Files Forbidden

| الملف/النظام | السبب |
|---|---|
| `includes/class-pge-feature-registry.php` | Phase 1 مكتملة ومغلقة (`docs/PHASE-1-STATUS.md`: `Ready For Merge: Yes`) — لا تعديل |
| `includes/class-pge-tier-features.php` | Phase 2 مكتملة من ناحية التنفيذ والمراجعة — لا تعديل ما لم ينص مرجع صراحة، ولا يوجد نص كهذا |
| `includes/class-mon-catalog-schema.php` | لا تعديل Schema في Phase 3 — لا جدول جديد ولا ترقية `DB_VERSION` |
| `includes/class-pge-catalog.php`, `mon_plans`, `mon_plan_tiers` | مذكور صراحة كممنوع في Phase 1/2؛ Phase 3 لا تذكر أي استثناء |
| `includes/class-pge-invitation-credit-ledger.php`, `includes/class-pge-replacement-entitlements.php` | خارج نطاق طبقة Features بالكامل (`PACKAGE-FEATURE-MATRIX.md §14`) |
| `includes/event-factory.php` | مذكور صراحة في Phase 3، "الملفات الممنوع تعديلها": "لا تعديل على `pge_get_user_plan_limits_for_events()` — الرصيد يبقى خارج هذه الطبقة كلياً" |
| `includes/class-mon-events-users.php` | يخص Snapshot (Phase 4) حصراً — Phase 3 "الملفات المتوقع تعديلها" لا تذكره إطلاقاً |
| `includes/class-cartat-handler.php`, `includes/class-ultramsg-handler.php`, `includes/class-salla-handler.php` | غير معنيّة بـPhase 3 |
| ملفات Legacy (`admin-mods.php`, `class-pge-packages.php`) | نظام Legacy مستقر (`PACKAGE-FEATURE-MATRIX.md §0`) — Resolver **يقرأ منها فقط** (`PGE_Packages`, `_mon_active_features`)، لا يعدّلها |
| أي ملف Frontend (`page-create-event.php`, `templates/`) | الاستهلاك الفعلي يبدأ في Phase 6 فقط |
| `docs/PACKAGE-FEATURE-MATRIX.md`, `docs/FEATURES-IMPLEMENTATION-PLAN.md`, `docs/DECISION-LOG.md` | وثائق مرجعية/معمارية، لا تُعدَّل ضمن أي وثيقة تنفيذ |

---

## 6. Resolver Data Flow

بالترتيب الإلزامي المنقول حرفياً من `PACKAGE-FEATURE-MATRIX.md §7`:

```
Database (mon_tier_features → feature_value خام، عبر PGE_Tier_Features)
        ↓
Registry (تعريف key → type/default/validation، عبر PGE_Feature_Registry)
        ↓
Resolver (تفسير القيمة الخام وفق النوع من Registry + ترتيب المصادر)
        ↓
UI (/create-event/, Dashboard, أي صفحة مستقبلية — Phase 6، خارج نطاق هذه المرحلة)
```

**ترتيب مصادر القراءة لكل استدعاء** (منقول حرفياً، راجع §8 للتفصيل الكامل لكل خطوة): Catalog Snapshot (`_mon_package_features`) → Catalog Tier كـFallback دفاعي فقط → Legacy (`PGE_Packages` + `_mon_active_features`، لمستخدم `_mon_package_source !== 'catalog'` فقط) → Default آمن من Registry.

**القاعدة الحاسمة (§7، منقولة حرفياً)**: "الـResolver لا يفسّر القيمة الخام مباشرة من الجدول بأي منطق مضمَّن فيه (لا `if is_numeric()` ولا تخمين نوع) — في كل استدعاء، يسأل Registry أولاً 'ما نوع `$feature_key`؟' عبر Registry Provider حصراً... ثم يُطبِّق قاعدة التفسير الموحَّدة لذلك النوع فقط."

---

## 7. Public API

الدوال الثلاث منقولة حرفياً من `PACKAGE-FEATURE-MATRIX.md §7` ("العقد المقترح (بدون كود)") ومطابقة لما ورد في `FEATURES-IMPLEMENTATION-PLAN.md`، Phase 3، "خطوات التنفيذ" (بنود 1-3). لا API رابعة تُضاف.

### `pge_user_has_feature($user_id, $feature_key): bool`

- **Signature**: مطابقة حرفياً لـ§7، بما فيها نوع الإرجاع المُعلَن `: bool`.
- **Inputs**: `$user_id` (معرّف مستخدم)، `$feature_key` (نص).
- **Return type**: `bool` — مُعلَن حرفياً في المرجع، لا لبس هنا.
- **Success behavior**: يُطبَّق تفسير `boolean` (راجع §9) على القيمة الخام المُستخرَجة عبر ترتيب المصادر في §8، وتُعاد نتيجة المقارنة كـ`bool`.
- **Missing behavior (feature_key غير موجود في Registry)**: بما أن نوع الإرجاع المُعلَن `bool` صراحة، والقيمة الوحيدة المتوافقة مع "Default آمن" (§7) ضمن نوع `bool` هي `false` — **هذه النتيجة مُشتقَّة مباشرة من نوع الإرجاع المُعلَن نفسه لا قراراً منفصلاً مُخترَعاً**؛ أي محاولة لإعادة أي قيمة أخرى (`null`, استثناء) تخالف التوقيع المُعلَن حرفياً في §7. **مُحدَّد**: `false`.
- **Invalid-value behavior**: القيمة الخام التي لا تطابق أياً من الأشكال المقبولة لـ`boolean` (راجع §9) تُعامَل حسب نفس قاعدة `pge_plan_feature_enabled_for_events()` الحالية (`event-factory.php:333-344`) — أي قيمة غير مطابقة صراحة تُنتج `false` (لا حالة "خطأ" منفصلة لهذا النوع؛ الفرع الوحيد الموجود هو المطابقة أو عدمها).
- **Database failure behavior**: مُحدَّد (`DEC-003`) — `false` من `PGE_Tier_Features::get_tier_feature_value()` (فشل استعلام فعلي وفق `DEC-002`) يُعامَل مطابقاً تماماً لـ"لا قيمة موجودة في هذه الخطوة"، فيُتابَع ترتيب §8 إلى الخطوة التالية، بلا أي إشارة خطأ منفصلة تصل للمستدعي.
- **Validation Responsibility**: لا Business Validation هنا. الدالة تفسّر النوع فقط وفق قواعد §9؛ لا تتحقق من صلاحيات المستخدم، لا تُنشئ/تُعدّل أي بيانات.
- **Dependencies**: `PGE_Feature_Registry::has()`/`get()` (Phase 1)، `PGE_Tier_Features::get_tier_feature_value()` (Phase 2)، `PGE_Packages`/`_mon_active_features` (Legacy، قراءة فقط)، `PGE_Catalog::get_tier()` (تحديد Tier، `DEC-003`).

### `pge_get_user_feature_value($user_id, $feature_key, $default = null)`

- **Signature**: مطابقة حرفياً لـ§7 (بما فيها البارامتر الثالث `$default = null`).
- **Inputs**: `$user_id`، `$feature_key`، `$default` (اختياري — يُستخدَم حصراً في حالة "feature_key غير معرَّف في Registry"، راجع أدناه).
- **Return type**: مُحدَّد (`DEC-003`، مؤكَّد بمراجعة إضافية قبل التنفيذ): `mixed`. **بلا PHP Return Type Declaration** — التوقيع المُجمَّد في `PACKAGE-FEATURE-MATRIX.md §7` يُغفِل عمداً `: type` لهذه الدالة تحديداً (خلافاً لرفيقتيها `: bool`/`: array`)؛ إضافة إعلان الآن تُعتبر كسراً للتجميد المعماري بلا مبرر موثَّق. الاستخدام **الموثَّق والمقصود فعلياً** وفق §7 ("كيف تُقرأ الأنواع") ومثال §11 هو `int` (لميزات `integer`/`percentage` حصراً — Boolean موثَّقة حصراً عبر `pge_user_has_feature()` لا هذه الدالة). `bool` يبقى ممكناً نظرياً فقط (القاعدة العامة "تفسير حسب النوع" غير مقصورة على دالة بعينها) بلا مثال فعلي في أي مرجع. `$default` (بلا Type Hint في التوقيع) يُعاد كما هو لحالة feature_key غير معرَّف — وهذا سبب إضافي **قائم اليوم فعلياً** (لا افتراضي) لكون `mixed` هو الوصف الدقيق، لا بسبب أي توسّع مستقبلي لأنواع Registry (لا أساس نصي لأنواع كـ`string`/`enum`/`array` في أي مرجع). لا تُعيد أبداً `null`/`false` كإشارة خطأ، ولا ترمي استثناء — امتداد مباشر لنمط `pge_get_user_plan_limits_for_events()` (`event-factory.php:86-131`) القائم فعلاً في المشروع.
- **Success behavior**: يُطبَّق تفسير النوع وفق §9 حسب `type` المُعرَّف في Registry لذلك الـ`feature_key`.
- **Missing behavior** (مُحدَّد بالكامل، `DEC-003`):
  - **`feature_key` غير موجود في Registry أصلاً**: تُعاد قيمة `$default` كما مُرِّرت تماماً، دون أي محاولة تفسير نوع (لا يوجد نوع معروف لتفسيره).
  - **الميزة موجودة في Registry لكن لا قيمة مخزَّنة لها في أي مصدر (Snapshot/Tier/Legacy)**: يُطبَّق تفسير §9 على حقل `default` في تعريف الميزة، وتُعاد النتيجة. **نتيجة موثَّقة**: لثلاث ميزات (`host_limit`, `admin_supervisor_limit`, `invitation_design_limit`) قيمة `default` الفعلية هي السلسلة `'TBD'` (`class-pge-feature-registry.php` الأسطر 60، 70، 80) — بعد تطبيق `(int)` عليها وفق §9، الناتج الفعلي `0`، لا السلسلة `'TBD'` (تصحيح لملاحظة سابقة في هذه الوثيقة افترضت غياب التفسير عن قيمة Default؛ `DEC-003` يحسم أن التفسير يُطبَّق دوماً بلا استثناء لمصدر القيمة).
  - **العلاقة بين `$default` والـ"Default الآمن من Registry"**: `$default` المُمرَّر يُستخدَم **حصراً** عند "feature_key غير معرَّف في Registry"؛ لا يُستخدَم أبداً لميزة معرَّفة، لأن قواعد التفسير المحسومة (§9) لا "تفشل" أبداً (كل قيمة خام أو Default تُنتج قيمة صالحة عبر `in_array`/`(int)`)، فلا تنشأ حالة تستدعي اللجوء لـ`$default` كبديل عن Default معرَّف.
- **Invalid-value behavior**: يتبع قواعد §9 لكل نوع — Boolean/Integer/Percentage محسومة بالكامل (راجع §9).
- **Database failure behavior**: مُحدَّد (`DEC-003`) — `false` من `PGE_Tier_Features` يُعامَل كـ"لا قيمة"، يُتابَع ترتيب §8.
- **Validation Responsibility**: لا Business Validation. تفسير نوع فقط وفق §9.
- **Dependencies**: نفس تبعيات `pge_user_has_feature()` أعلاه.

### `pge_get_user_package_features($user_id): array`

- **Signature**: مطابقة حرفياً لـ§7، بما فيها نوع الإرجاع المُعلَن `: array`.
- **Inputs**: `$user_id`.
- **Return type**: `array` — مُعلَن حرفياً، لا لبس.
- **Success behavior**: مُحدَّد (`DEC-003`) — بنية مسطّحة (Flat): `feature_key => قيمة_مُفسَّرة` لكل الميزات التسع عشرة المُعرَّفة في Registry، بلا استثناء بحسب `lifecycle` (لا تصفية — لا نص يفرضها ولا استهلاك فعلي لـ`lifecycle` كفلتر تشغيلي في أي كود قائم). هذا الشكل هو الامتداد الوحيد المتوافق مع المثال التوضيحي الحرفي في `PACKAGE-FEATURE-MATRIX.md §9` لبنية `_mon_package_features` (بنية مسطّحة بلا Metadata إضافية). لكل مفتاح: تُطبَّق نفس آلية `pge_get_user_feature_value()` (نفس ترتيب §8، نفس تفسير §9)، تنتهي بـDefault من Registry عند غياب قيمة خام — بلا بارامتر `$default` مستقل لكل مفتاح (لا يوجد في توقيع هذه الدالة).
- **Missing behavior**: مُحدَّد — كل مفتاح من الـ19 ينتهي دوماً بقيمة صالحة (حتى `0` للميزات الثلاث ذات `TBD`، بنفس منطق `pge_get_user_feature_value()` أعلاه)؛ لا مفتاح يُستبعَد من المصفوفة الناتجة تحت أي ظرف.
- **Invalid-value behavior**: يتبع §9 لكل عنصر (Boolean/Integer/Percentage محسومة بالكامل).
- **Database failure behavior**: مُحدَّد (`DEC-003`) — فشل أي استعلام فردي داخل الحلقة يُعامَل كـ"لا قيمة" لذلك المفتاح فقط (يُتابَع ترتيب §8 لذلك المفتاح تحديداً، ينتهي بـDefault من Registry)؛ لا يؤثر على بقية المفاتيح، ولا يُسقِط الدالة كاملة. المصفوفة الناتجة دوماً بحجم 19 عنصراً بالضبط، التزاماً بالتوقيع المُعلَن `: array` الذي لا يحتمل قيمة أخرى تُعبِّر عن "فشل".
- **Validation Responsibility**: لا Business Validation.
- **Dependencies**: `PGE_Feature_Registry::all()` (Phase 1)، `PGE_Tier_Features::get_all_tier_features()` (Phase 2)، Legacy/Catalog Snapshot حسب المصدر.

---

## 8. Resolution Precedence

**الترتيب موثَّق حرفياً في `PACKAGE-FEATURE-MATRIX.md §7`**: Catalog Snapshot (`_mon_package_features`) → Catalog Tier كـFallback دفاعي فقط (حالة انتقالية موثَّقة لا مصدر دائم) → Legacy (`PGE_Packages` + `_mon_active_features`، لمستخدم `_mon_package_source !== 'catalog'` فقط) → Default آمن من Registry.

الإجابات على الأسئلة المطلوبة صراحة:

- **هل يقرأ Resolver من Snapshot أولاً أم من Tier مباشرة؟** من Snapshot أولاً (مُحدَّد حرفياً أعلاه).
- **هل Snapshot خارج Phase 3 بالكامل؟** لا — **منطق القراءة** من Snapshot جزء من Phase 3 (يُختبَر بمحاكاة/Seed مباشر لقيمة `_mon_package_features`، راجع §1 أعلاه)؛ **آلية الكتابة الفعلية** لـSnapshot هي حصراً Phase 4.
- **ماذا يحدث عند عدم وجود قيمة مخزنة للميزة على Tier؟** يُتابَع الترتيب إلى Legacy ثم Default من Registry — لا نص يفرّق صراحة بين "لا Tier محدَّد أصلاً" و"Tier محدَّد لكن لا صف `mon_tier_features` له" (كلاهما ينتج نفس الأثر العملي: `PGE_Tier_Features::get_tier_feature_value()` تُعيد `null` وفق `DEC-002`، فيُتابَع الترتيب).
- **هل تُستخدَم قيمة default من Registry؟** نعم، كآخر حلقة في الترتيب.
- **ماذا يحدث إذا لم تكن الميزة موجودة في Registry؟** رفض فوري (راجع §7 لكل دالة — `has_feature` محسومة كـ`false`؛ `pge_get_user_feature_value()` تُعيد `$default` كما مُرِّر، `DEC-003`).
- **كيف يُحدَّد Tier المستخدم أصلاً؟** مُحدَّد (`DEC-003`) — المصدر الوحيد `_mon_catalog_tier_id` (User Meta، يُكتب في `class-mon-events-users.php:203`)، ويُقرأ فقط إذا `_mon_package_source === 'catalog'` و`_mon_package_status === 'active'` معاً (امتداد لنمط `event-factory.php:91-93` و`197-199`). قيمة تؤول لـ0 (فارغة/غير رقمية/صفر) = "لا Tier محدَّد".
- **ماذا يحدث إذا لم يكن Tier موجوداً؟** مُحدَّد (`DEC-003`) — `PGE_Catalog::get_tier()` (`class-pge-catalog.php:739-768`) يُعيد `null` موحَّداً لثلاث حالات (tier_id غير صالح، الصف غير موجود، فشل استعلام) دون تمييز ممكن على مستوى Resolver (تعديل `PGE_Catalog` ممنوع). القرار: `null` بكل مسبباته يُعامَل كـ"Tier غير متاح لهذا الاستدعاء" → تُتخطى خطوة Tier Fallback، يُتابَع الترتيب.
- **ماذا يحدث عند فشل Repository؟** مُحدَّد (`DEC-003`) — `false` من `PGE_Tier_Features` (`DEC-002`) يُعامَل كـ"لا قيمة"، يُتابَع الترتيب؛ بلا إشارة خطأ منفصلة تصل للمستدعي (توقيعات الدوال الثلاث لا تحتمل قيمة إرجاع مخصَّصة لهذا).
- **هل توجد أي fallback إلى Legacy لمستخدم Catalog؟** لا — الفصل صارم: Legacy يُقرأ فقط لمستخدم `_mon_package_source !== 'catalog'` (منقول حرفياً)؛ لا تسرّب بين المسارين مسموح (`PACKAGE-FEATURE-MATRIX.md §13`، "ازدواجية Legacy/Catalog").
- **هل توجد أي fallback إلى `mon_packages_settings`؟** لا نص يذكر `mon_packages_settings` مباشرة في سياق Resolver — القراءة من Legacy تتم عبر `PGE_Packages`/`_mon_active_features` فقط وفق النص الحرفي؛ أي وصول مباشر إلى `mon_packages_settings` من داخل Resolver غير موثَّق ويُعتبر خارج هذا العقد.

---

## 9. Type Interpretation Rules

**قاعدة عامة (§7، منقولة حرفياً)**: لا Parser من تصميم حر — كل قاعدة أدناه مُستخرَجة حرفياً من المرجع أو من كود قائم مُستشهَد به صراحة في المرجع نفسه.

### Boolean

- **المرجع**: `PACKAGE-FEATURE-MATRIX.md §7`: "القيمة الخام تُقارَن حرفياً بـ`true`/مكافئاتها المعروفة، حسب نفس قاعدة `pge_plan_feature_enabled_for_events()` الحالية."
- **القاعدة الفعلية المُستشهَد بها** (`includes/event-factory.php:333-344`، فرع السلسلة النصية — وهو الفرع المنطبق دائماً على قيمة خام من `mon_tier_features` بما أنها `LONGTEXT` أي `string` دائماً من PHP): `$value = strtolower(trim((string) $raw_value)); return in_array($value, ['1', 'on', 'yes', 'true'], true);`
- **القيمة الناتجة**: `bool` صريح (`true`/`false`).
- **"0"**: بعد `strtolower(trim())` = `"0"`، غير موجودة في `['1','on','yes','true']` → `false`.
- **"1"**: موجودة في القائمة → `true`.
- **"true"/"false"**: `"true"` (بعد lowercase) موجودة → `true`؛ `"false"` غير موجودة → `false`.
- **سلسلة فارغة `""`**: غير موجودة في القائمة → `false`.
- **أرقام سالبة كسلسلة (مثال "-1")**: غير موجودة حرفياً في القائمة → `false`.
- **قيم عشرية كسلسلة (مثال "1.0")**: غير موجودة حرفياً (المقارنة سلسلة لا رقمية) → `false`.
- **قيمة خارج حدود validation** (أي نص آخر غير الأشكال الأربعة أعلاه): لا حالة "خطأ" منفصلة — تُعامَل بنفس آلية "غير مطابق" → `false`.
- **مُحدَّد بالكامل** — لا `Blocked` لهذا النوع.

### Integer

- **المرجع**: `PACKAGE-FEATURE-MATRIX.md §7`: "`pge_get_user_feature_value()` تُعيد `(int)` صريحاً."
- **القاعدة**: `(int) $raw_value` — تحويل PHP القياسي المباشر، بلا أي Validation إضافي مذكور.
- **القيمة الناتجة**: `int`.
- **"0"**: `(int) "0" = 0`.
- **"1"**: `(int) "1" = 1`.
- **سلسلة فارغة `""`**: `(int) "" = 0` (سلوك PHP القياسي، غير مذكور استثناء له).
- **أرقام سالبة (مثال "-5")**: `(int) "-5" = -5` — لا رفض مذكور لهذا النطاق رغم أن `validation` في Registry لبعض ميزات `integer` تنص "عدد صحيح ≥ 0"؛ **لا نص في §7 يفرض تطبيق قاعدة `validation` هذه داخل Resolver نفسه** (§11 يوضح أن `validation` مسؤولية طبقة أعلى، لا Resolver، إلا بما يخص "قراءة النوع" فقط).
- **قيم عشرية (مثال "12.50")**: `(int) "12.50" = 12` (سلوك PHP القياسي — يقطع الجزء العشري، لا يُقرِّب).
- **قيمة خارج حدود validation** (مثال قيمة سالبة رغم "≥ 0" في `validation`): بلا رفض — `(int)` صريح فقط، وفق النص الحرفي.
- **مُحدَّد بالكامل** — لا `Blocked` لهذا النوع.

### Percentage

- **المرجع**: `PACKAGE-FEATURE-MATRIX.md §7`: "`pge_get_user_feature_value()` ... نوع `percentage` تُعيد عدداً صحيحاً 0-100 — لا يوجد اليوم أي Feature من نوع `percentage` تُقرأ وقت التشغيل."
- **مُحدَّد بالكامل (`DEC-003`)** — تُعامَل بنفس آلية Integer تماماً: `(int) $raw_value`، بلا Clamp لأي نطاق، بلا رفض لأي قيمة خارج 0-100، بلا أي منطق إضافي. السبب: حقل `validation` الوصفي في Registry (`'0-100 عدد صحيح'`) نص حر غير قابل للتحليل برمجياً (ليس بنية min/max مهيكلة)، وغير مُستهلَك فعلياً في أي كود قائم — بنفس المنطق الذي حسم بالفعل عدم إنفاذ `validation` لـInteger (أدناه).
- **"150"، "-10"، "45.5"**: `(int) "150" = 150`، `(int) "-10" = -10`، `(int) "45.5" = 45` — بلا رفض، بلا Clamp، مطابقاً تماماً لسلوك `(int)` القياسي.
- **Return type**: `int`.
- **لا Feature حالياً من هذا النوع تُقرأ عملياً** (`support_services_discount_percentage` بحالة `lifecycle: planned`) — لا يمنع اعتماد القاعدة أعلاه.
- **مُحدَّد بالكامل** — لا `Blocked` لهذا النوع.

---

## 10. Missing And Invalid Value Semantics

التفريق المطلوب صراحة، مع الحسم أو `Blocked` لكل حالة على حدة:

| الحالة | الحسم |
|---|---|
| Feature غير موجودة في Registry | `pge_user_has_feature()` → `false`. `pge_get_user_feature_value()` → `$default` كما مُرِّر. `pge_get_user_package_features()` → غير قابلة للحدوث (تقتصر على مفاتيح Registry نفسها). (`DEC-003`) |
| Feature موجودة في Registry لكن لا قيمة Tier مخزَّنة لها | يُتابَع ترتيب المصادر (§8) حتى الوصول لـDefault من Registry، وتُطبَّق قاعدة §9 عليه. لثلاث ميزات (`TBD`)، الناتج الفعلي بعد `(int)` هو `0` (`DEC-003`). |
| قيمة خام موجودة لكنها غير صالحة للنوع | Boolean/Integer/Percentage: محسومة بالكامل (§9) — تؤول لـ`false`/تحويل `(int)` بلا رفض لأي منها. |
| Tier غير موجود | مُحدَّد (`DEC-003`) — يُعامَل مطابقاً لـ"Tier ID غير محدَّد"؛ تُتخطى خطوة Tier Fallback، يُتابَع الترتيب. |
| Repository أعاد `false` بسبب فشل قاعدة البيانات | مُحدَّد (`DEC-003`) — يُعامَل كـ"لا قيمة"، يُتابَع الترتيب، لكل الدوال الثلاث. |
| Registry أعاد عدم وجود (`PGE_Feature_Registry::get()` يعيد `null`) | يعني أن `feature_key` غير معرَّف إطلاقاً — نفس صف "Feature غير موجودة في Registry" أعلاه. |
| القيمة الخام سلسلة فارغة `""` | Boolean → `false`. Integer/Percentage → `0`. (محسوم بالكامل) |
| القيمة الخام `"0"` | Boolean → `false`. Integer/Percentage → `0`. (محسوم بالكامل) |

---

## 11. Validation Responsibility

الفصل التالي منقول حرفياً من المراجع (`PACKAGE-FEATURE-MATRIX.md §7`, §8؛ `FEATURES-PHASE-2-SPEC.md §8`):

- **Repository (Phase 2، `PGE_Tier_Features`)**: تخزين خام فقط. لا Type Validation، لا Business Validation، لا تحقق من وجود `feature_key` في Registry (مؤكَّد ومُطبَّق فعلياً في الكود الحالي، راجع مراجعة Commit 2 السابقة لهذه المرحلة).
- **Registry (Phase 1، `PGE_Feature_Registry`)**: تعريف Metadata فقط (`type`, `default`, `validation` كنص وصفي، `lifecycle`) — لا تفسير قيم، لا استعلام قاعدة بيانات (مؤكَّد في `includes/class-pge-feature-registry.php` الفعلي).
- **Resolver (Phase 3، هذه الوثيقة)**: تفسير نوع القيمة فقط ضمن القواعد المعتمدة في §9 أعلاه — **لا يتحقق من `validation` الوصفي المُعرَّف في Registry كقاعدة رفض** (مثال: لا يرفض قيمة `integer` سالبة رغم أن `validation` الوصفي يقول "≥ 0" — راجع §9 "Integer"). لا Business Validation، لا صلاحيات مستخدم، لا كتابة بيانات من أي نوع.
- **Admin UI (Phase 5)**: التحقق الفعلي من `validation` عند الإدخال — **خارج نطاق Phase 3 بالكامل**؛ Phase 5 نفسها موسومة `Blocked` جزئياً في خطة التنفيذ العامة (موضع الواجهة).

**لا نقل Business Validation إلى Resolver بدون نص صريح** — ولا يوجد نص صريح كهذا في أي مرجع؛ لذا لا Validation من هذا النوع تُبنى هنا.

---

## 12. Commit Strategy

الترقيم أدناه محلي لهذه المرحلة (Commit 1/2 من Phase 3)، ويقابل حرفياً **Commit 6/7 العام** في `FEATURES-IMPLEMENTATION-PLAN.md §7 — Implementation Order`. **لا Commit ثالث منفصل لـ"الربط/Wiring"** — الخطة الفعلية تدمج إنشاء الملف مع سطر `require_once` في Commit واحد (نفس نمط Phase 1 Commit 1)، لا فصلاً كما قد يُفهَم من "Resolver implementation / Loader wiring / Tests" كثلاث خطوات منفصلة.

### Commit 1 (يقابل Commit 6 العام)

- **الهدف**: إنشاء `includes/feature-resolver.php` (الدوال الثلاث في §7 أعلاه) وربطه بـ`require_once` في `pgevents-core.php`.
- **الملفات**: `includes/feature-resolver.php` (جديد)، `pgevents-core.php` (تعديل سطر واحد).
- **Definition of Done**: مطابقة حرفياً لـ`FEATURES-IMPLEMENTATION-PLAN.md`، Phase 3: "الدوال الثلاث تعمل وفق العقد والترتيب في §7 بالضبط، مُختبَرة لكل مصدر (Catalog Snapshot/Fallback/Legacy/Default) ولكل نوع (boolean/integer/percentage)." **محدَّث بعد `DEC-003`**: الأنواع الثلاثة (boolean/integer/percentage) محسومة بالكامل الآن (§9) — لا عائق متبقٍ لتحقيق هذا الشرط من ناحية التفسير.
- **Dependencies**: Phase 1 (`PGE_Feature_Registry`) وPhase 2 (`PGE_Tier_Features`) مكتملتان — مؤكَّد فعلياً (`docs/PHASE-1-STATUS.md`: `Ready For Merge: Yes`؛ Phase 2: مكتملة تنفيذاً ومراجعة وفق آخر تقرير معتمد لهذه السلسلة).
- **Rollback Expectations**: منقولة حرفياً من `FEATURES-IMPLEMENTATION-PLAN.md`، Phase 3، "Rollback": "حذف الملف الجديد وسطر الـ`require_once` — لا استهلاك خارجي بعد في هذه المرحلة، فالإزالة آمنة بالكامل."

### Commit 2 (يقابل Commit 7 العام)

- **الهدف**: كتابة `tests/test-feature-resolver.php`.
- **الملفات**: `tests/test-feature-resolver.php` (جديد).
- **Definition of Done**: كل بنود "Testing Checklist" (§13 أدناه) القابلة للتحديد فعلياً (غير المحجوبة بـ`Blocked`) محقَّقة.
- **Dependencies**: Commit 1 من هذه المرحلة مكتمل.
- **Rollback Expectations**: حذف ملف الاختبار فقط — لا أثر على أي كود إنتاجي (اختبار مستقل بذاته، بلا PHPUnit، بنفس نمط `tests/test-tier-features.php`/`tests/test-feature-registry.php`).

لا Commit ثالث ضمن نطاق Phase 3 — أي عمل لاحق (Snapshot، Commit 8 فصاعداً في الترقيم العام) يخص Phase 4 ولا يُوصَف هنا.

---

## 13. Testing Checklist

منقولة/مُشتقَّة من "الاختبارات المطلوبة" لـPhase 3 في `FEATURES-IMPLEMENTATION-PLAN.md`، مع تمييز كل بند بحسب إمكانية تحديد النتيجة المتوقعة فعلياً وفق حالة `Blocked` الموثَّقة أعلاه — **لا تخمين لأي نتيجة محجوبة**:

- [ ] **Boolean `true`**: قيمة خام مطابقة لأحد الأشكال الأربعة (`"1"`, `"on"`, `"yes"`, `"true"`) تُعيد `true` من `pge_user_has_feature()`. **قابل للتحديد** (§9).
- [ ] **Boolean `false`**: أي قيمة خام أخرى (بما فيها `"0"`, `""`, نص عشوائي) تُعيد `false`. **قابل للتحديد**.
- [ ] **Integer**: قيمة خام رقمية (نصاً) تُعاد كـ`(int)` صريح عبر `pge_get_user_feature_value()`. **قابل للتحديد**.
- [ ] **Percentage**: مُحدَّد بالكامل الآن (`DEC-003`، §9) — نفس آلية Integer، `(int)` بلا Clamp. **قابل للتحديد**.
- [ ] **raw value `"0"`**: Boolean → `false`؛ Integer/Percentage → `0`. **قابل للتحديد** للأنواع الثلاثة.
- [ ] **raw value `""`**: Boolean → `false`؛ Integer/Percentage → `0`. **قابل للتحديد** للأنواع الثلاثة.
- [ ] **missing Tier value** (لا صف `mon_tier_features` للـTier/المفتاح): **قابل للتحديد** — يُتابَع ترتيب §8 إلى Legacy ثم Default من Registry (`DEC-003`).
- [ ] **Registry default**: **قابل للتحديد** — يُطبَّق تفسير §9 على حقل `default` دوماً؛ لثلاث ميزات `TBD` الناتج الفعلي `0` بعد `(int)` (`DEC-003`)؛ لميزة بلا `TBD` (مثال `event_website`، Default = `true`) الناتج `true`.
- [ ] **unknown feature key**: `pge_user_has_feature()` → `false`؛ `pge_get_user_feature_value()` → `$default` كما مُرِّر. **قابل للتحديد للدالتين** (`DEC-003`). `pge_get_user_package_features()` — غير قابل للحدوث (لا بارامتر `feature_key`).
- [ ] **invalid raw value**: Boolean/Integer/Percentage قابلة للتحديد بالكامل (§9).
- [ ] **Repository DB failure**: **قابل للتحديد** — يُعامَل كـ"لا قيمة"، يُتابَع ترتيب §8، لكل الدوال الثلاث (`DEC-003`).
- [ ] **Tier غير موجود / tier_id غير محدَّد**: **قابل للتحديد** — يُعامَلان معاملة واحدة موحَّدة: تخطي خطوة Tier Fallback (`DEC-003`).
- [ ] **isolation بين Tiers**: قابل للتحديد جزئياً عبر عزل قراءة Snapshot لكل `user_id` (لا تسرّب Meta بين مستخدمين) — العزل الكامل على مستوى Tier نفسه محكوم بنفس آلية تحديد Tier أعلاه (`DEC-003`).
- [ ] **عدم قراءة آلية كتابة Snapshot في Phase 3**: قابل للتحديد — الاختبار يُحاكي (Seed) قيمة `_mon_package_features` مباشرة عبر دالة مساعدة اختبارية (بنفس نمط `set_test_user_meta()` في `tests/test-invitation-credit-ledger.php`)، بلا استدعاء أي منطق كتابة فعلي (لأنه غير موجود بعد).
- [ ] **عدم fallback إلى Legacy لمستخدم Catalog**: قابل للتحديد (§8 — الفصل صريح).
- [ ] **عدم تعديل البيانات أثناء القراءة**: قابل للتحديد — تحقُّق أن Fake Repository/`$wpdb` لا يسجّل أي استدعاء `insert()`/`update()`/`delete()` أثناء أي استدعاء لأي من الدوال الثلاث.
- [ ] **عدم وجود SQL مباشر داخل Resolver**: قابل للتحديد عبر مراجعة كود/grep — Resolver يستدعي `PGE_Tier_Features` فقط، لا `$wpdb` مباشرة.

---

## 14. Regression Checklist

- [ ] صفر تعديل على Schema (`class-mon-catalog-schema.php`).
- [ ] صفر تعديل على `DB_VERSION`.
- [ ] صفر تعديل على Feature Registry (`class-pge-feature-registry.php`).
- [ ] صفر تعديل على Tier Features Repository (`class-pge-tier-features.php`) — لا نص يطلب أي تعديل عليه ضمن Phase 3.
- [ ] صفر تعديل على Catalog (`PGE_Catalog`, `mon_plans`, `mon_plan_tiers`).
- [ ] صفر تعديل على Ledger (`class-pge-invitation-credit-ledger.php`).
- [ ] صفر تعديل على Replacement Entitlements (`class-pge-replacement-entitlements.php`).
- [ ] صفر تعديل على Legacy (`admin-mods.php`, `class-pge-packages.php`, `mon_packages_settings`) — قراءة فقط من `PGE_Packages`/`_mon_active_features`، بلا أي كتابة.
- [ ] صفر تعديل على Cartat/UltraMsg/Salla.
- [ ] صفر تعديل على Frontend (`page-create-event.php`, `templates/`).
- [ ] استمرار مرور `tests/test-feature-registry.php` وPhase 2 (`tests/test-tier-features.php`) دون أي تعديل عليهما.

---

## 15. Definition of Done

مأخوذ حرفياً من `FEATURES-IMPLEMENTATION-PLAN.md`، Phase 3، Definition of Done: "الدوال الثلاث تعمل وفق العقد والترتيب في §7 بالضبط، مُختبَرة لكل مصدر (Catalog Snapshot/Fallback/Legacy/Default) ولكل نوع (boolean/integer/percentage)."

**محدَّث بعد `DEC-003`**: كل البنود التسعة التي كانت `Blocked` في §16 محسومة الآن. لا عائق متبقٍ لتحقيق هذا الشرط بالكامل من ناحية العقود والدلالات.

**شروط اعتبار Phase 3 منتهية** (Commits 1-2 معاً):
- [ ] توقيعات الدوال الثلاث مطابقة حرفياً لـ§7.
- [ ] تفسير Boolean/Integer/Percentage يمر عبر Registry Provider دون تخمين محلي (§9).
- [ ] Legacy وCatalog معزولان تماماً (§8).
- [ ] تحديد Tier عبر `_mon_catalog_tier_id` فقط، وفق شرطي `_mon_package_source`/`_mon_package_status` المُحدَّدين (`DEC-003`).
- [ ] كل بنود "Testing Checklist" (§13) محقَّقة (جميعها قابلة للتحديد الآن).

---

## 16. Blocking Conditions

**كل الحالات التسع السابقة محسومة الآن عبر `DEC-003`** (`docs/DECISION-LOG.md`). لا حالة Blocked متبقية في هذه الوثيقة. الحالات التسع الأصلية وحسمها:

1. **كيفية تحديد Tier للمستخدم**: محسوم — `_mon_catalog_tier_id` (User Meta، `class-mon-events-users.php:203`)، يُقرأ فقط عند `_mon_package_source === 'catalog'` و`_mon_package_status === 'active'` معاً (§8، `DEC-003`).
2. **Signature النهائي لـ`pge_get_user_feature_value()`**: محسوم — `mixed` (`bool`/`int`)، لا `null`/`false` كإشارة خطأ (§7، `DEC-003`).
3. **العلاقة بين بارامتر `$default` والـ"Default آمن من Registry"**: محسوم — `$default` يُستخدَم حصراً عند feature_key غير معرَّف؛ Default من Registry يُستخدَم دوماً لميزة معرَّفة بلا قيمة مخزَّنة (§7، `DEC-003`).
4. **return contract لـ`pge_get_user_feature_value()` عند feature_key غير موجود في Registry**: محسوم — `$default` كما مُرِّر (§7، `DEC-003`).
5. **return contract لـ`pge_get_user_package_features()`**: محسوم — بنية مسطّحة، كل الـ19 مفتاحاً دوماً (§7، `DEC-003`).
6. **DB failure semantics**: محسوم — يُعامَل كـ"لا قيمة"، يُتابَع ترتيب §8، لكل الدوال الثلاث (§7، §8، `DEC-003`).
7. **Percentage parser**: محسوم — نفس آلية Integer، `(int)` بلا Clamp (§9، `DEC-003`).
8. **Tier غير موجود**: محسوم — يُعامَل مطابقاً لـ"Tier ID غير محدَّد" (§8، `DEC-003`).
9. **لا Feature Key محدَّد في Registry**: محسوم للدوال الثلاث (`has_feature` → `false`؛ `get_user_feature_value` → `$default`؛ `get_user_package_features` — غير قابلة للحدوث) (§7، `DEC-003`).

**نقاط محسومة صراحة (للتوضيح، لا Blocked)**:
- **هل APIs تستقبل `user_id` أم `tier_id`؟** محسوم: `$user_id` في الدوال الثلاث (§7، حرفياً).
- **اسم ملف Resolver**: محسوم: `includes/feature-resolver.php` (§3).
- **هل Resolver Class أم Global Helper Functions؟** محسوم: Global Helper Functions — التوقيعات في §7 بلا بادئة Class (`pge_user_has_feature(...)` لا `SomeClass::method(...)`)، مطابقةً لنمط دوال قائمة فعلياً في المشروع (`pge_get_user_plan_limits_for_events()`, `pge_plan_feature_enabled_for_events()` في `event-factory.php`/`helpers.php`).
- **هل تُحمَّل الدوال مباشرة أو عبر class؟** محسوم: عبر `require_once` مباشر لملف واحد يحوي الدوال كـGlobal Functions (§3، §4).
- **هل Snapshot جزء من القراءة الآن أم Phase 4 فقط؟** محسوم: منطق القراءة جزء من Phase 3؛ آلية الكتابة حصراً Phase 4 (§1، §8).
- **Boolean parser**: محسوم بالكامل (§9، مُستشهَد بـ`event-factory.php:333-344`).
- **Integer parser**: محسوم بالكامل (§9، `(int)` صريح).
- **Precedence**: محسوم بالكامل (§8).

---

## 17. Out Of Scope

- Snapshot (كتابة `_mon_package_features`/`_mon_package_feature_version`) — Phase 4.
- Admin UI (واجهة إدخال قيم الميزات) — Phase 5.
- Frontend Integration (`/create-event/`) — Phase 6.
- اختبارات التكامل الشاملة عبر كل المراحل — Phase 7.
- أي تعديل على الرصيد (`invitation_credit_limit`/`replacement_credit_limit`, Ledger, Replacement Entitlements) — `PACKAGE-FEATURE-MATRIX.md §14`.
- أي تعديل على Catalog (`PGE_Catalog`, `mon_plans`, `mon_plan_tiers`, `create_tier()`/`update_tier()`).
- أي تعديل على Legacy (`admin-mods.php`, `class-pge-packages.php`, `mon_packages_settings`) — قراءة فقط.
- أي تعديل على Cartat/UltraMsg/Salla.
- إدخال قيم فعلية للباقات في `mon_tier_features` — يبقى فارغاً حتى Phase 5.
- أي تعديل على Feature Registry أو Tier Features Repository.
- دمج Phase 3 وPhase 4 في تنفيذ واحد — تبقيان Commit-strategy منفصلتين وفق `FEATURES-IMPLEMENTATION-PLAN.md §7` بالرغم من الترابط الوثيق الموثَّق في §1 أعلاه.

---
