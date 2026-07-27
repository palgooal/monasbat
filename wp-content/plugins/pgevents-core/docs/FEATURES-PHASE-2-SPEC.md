# Features — Phase 2 Specification (Tier Features Storage)

> مواصفات تنفيذ دقيقة (Execution Specification) — **ليست وثيقة معمارية، وليست خطة مشروع.**
> لا قرار معماري جديد هنا، ولا تفسير أو تغيير لأي قرار قائم، ولا اقتراح تحسين، ولا إعادة تصميم.
> كل بند غير موجود حرفياً في الوثائق المرجعية أدناه يُكتب `Blocked`.
> تاريخ الإعداد: 2026-07-26.

**المراجع الملزمة (لا تُكرَّر محتواها هنا، يُحال إليها عند الحاجة):**
- `docs/PACKAGE-FEATURE-MATRIX.md` — المرجع المعماري النهائي (Frozen). المصدر الوحيد للتصميم التقني (§5 تحديداً).
- `docs/FEATURES-IMPLEMENTATION-PLAN.md` — خطة التنفيذ العامة، تحديداً قسم "Phase 2 — Tier Features (التخزين)" و§3 (Dependencies) و§7 (Implementation Order، Commits 3-5).
- `docs/WORKING-AGREEMENT.md` — يحكم أسلوب العمل أثناء التنفيذ (لا محتوى تقني منه هنا).
- `docs/QUALITY-GATE.md` — بوابة المراجعة قبل اعتماد أي Commit (لا محتوى تقني منه هنا).
- `docs/DECISION-LOG.md` — يحتوي حالياً قرارين معتمدين: `DEC-001` (طول عمود `feature_key` = `VARCHAR(64)`، بتاريخ 2026-07-26، الحالة `Approved`) و`DEC-002` (عقود قيم الإرجاع الأربعة لـ`includes/class-pge-tier-features.php`، بتاريخ 2026-07-26، الحالة `Approved`). أي قرار آخر غير هذين لا يُبنى عليه هذا المستند.

---

## 1. Phase Overview

**الهدف**: بناء طبقة تخزين خام لقيم الميزات لكل Tier — جدول `mon_tier_features` بالتصميم المعتمد نهائياً في `PACKAGE-FEATURE-MATRIX.md §5`، وRepository بسيط للقراءة/الكتابة الخام، بلا أي تفسير نوع.

**النطاق**: تأسيس بنية التخزين فقط. لا قراءة فعلية من أي مستخدم، لا واجهة إدارة، لا Resolver، لا Snapshot.

**ما الذي سيتم بناؤه**:
- جدول `mon_tier_features` (تعريف Schema + Migration/Upgrade Routine).
- طبقة Repository (`includes/class-pge-tier-features.php`) بعمليات القراءة/الكتابة/الحذف الخام على هذا الجدول فقط.
- اختبارات مستقلة للـRepository والـMigration.

**ما الذي لن يتم بناؤه**:
- أي منطق تفسير نوع (`boolean`/`integer`/`percentage`) — هذا عمل الـResolver (Phase 3، غير موجود بعد).
- أي كتابة أو قراءة لـSnapshot المستخدم (Phase 4).
- أي واجهة إدارة لإدخال القيم (Phase 5).
- أي ربط بـ`/create-event/` أو أي صفحة أمامية (Phase 6).
- أي تعديل على Feature Registry (Phase 1، مكتملة ومغلقة).

---

## 2. Architecture Scope

**الطبقات المسموح تعديلها في هذه المرحلة**:
- طبقة Schema (`includes/class-mon-catalog-schema.php`) — إضافة تعريف جدول جديد فقط (`mon_tier_features`)، لا تعديل على أي جدول موجود.
- طبقة تحميل الإضافة (`pgevents-core.php`) — سطر `require_once` واحد للملف الجديد فقط.
- ملف Repository جديد (`includes/class-pge-tier-features.php`).
- ملف اختبار جديد (`tests/test-tier-features.php`).

**الطبقات الممنوع تعديلها في هذه المرحلة**:
- Feature Registry (`includes/class-pge-feature-registry.php`) — مكتملة من Phase 1، لا تُلمَس.
- Catalog (`includes/class-pge-catalog.php`، جداول `mon_plans`/`mon_plan_tiers`) — لا `ALTER TABLE`، لا تعديل على `create_tier()`/`update_tier()`.
- أي طبقة رصيد (`class-pge-invitation-credit-ledger.php`, `class-pge-replacement-entitlements.php`, `event-factory.php`) — خارج نطاق طبقة Features بالكامل (`PACKAGE-FEATURE-MATRIX.md §14`).
- أي مسار Legacy (`admin-mods.php`, `class-pge-packages.php`, `mon_packages_settings`).
- أي مسار واتساب (`class-cartat-handler.php`, `class-ultramsg-handler.php`) أو Salla (`class-salla-handler.php`).
- أي صفحة أمامية أو قالب Frontend (`page-create-event.php`, `templates/`).
- كل الوثائق المرجعية المذكورة أعلاه.

---

## 3. Files To Create

| الملف | الوظيفة |
|---|---|
| `includes/class-pge-tier-features.php` | Repository الوصول الخام لجدول `mon_tier_features` — قراءة/كتابة/حذف بلا أي تفسير نوع |
| `tests/test-tier-features.php` | اختبار مستقل بذاته (بلا PHPUnit، بنفس نمط بقية `tests/`) للـMigration وللـRepository |

---

## 4. Files To Modify

| الملف | التعديل |
|---|---|
| `includes/class-mon-catalog-schema.php` | إضافة تعريف جدول `mon_tier_features` إلى `get_schema_sql()`، رفع `DB_VERSION`، وإضافة Upgrade Routine بنفس أسلوب الترقيات السابقة الموثَّقة في الملف (`upgrade_to_1_5_0`, `upgrade_to_1_6_0`, `upgrade_to_1_7_0`) |
| `pgevents-core.php` | إضافة سطر `require_once` واحد لتحميل `includes/class-pge-tier-features.php` |

**ملاحظة تنفيذية (ليست قراراً معمارياً)**: القيمة الحالية لـ`DB_VERSION` وقت إعداد هذه الوثيقة هي `1.7.0` (مؤكَّدة بقراءة `includes/class-mon-catalog-schema.php` مباشرة)؛ القيمة التالية المتوقعة وفق النمط التسلسلي القائم هي `1.8.0`. هذه ملاحظة حالة حالية، لا قراراً ثابتاً — يجب التحقق من القيمة الفعلية في الملف مباشرة قبل بدء أي Commit من هذه المرحلة، لا افتراضها.

---

## 5. Files Forbidden

| الملف/النظام | السبب |
|---|---|
| `includes/class-pge-feature-registry.php` | Phase 1 مكتملة ومغلقة — لا تعديل على Registry |
| `includes/class-pge-catalog.php`, `mon_plans`, `mon_plan_tiers` | Catalog نظام مستقر لا يُعاد تصميمه (`PACKAGE-FEATURE-MATRIX.md §0`)؛ لا `ALTER TABLE` |
| `includes/class-pge-invitation-credit-ledger.php`, `includes/class-pge-replacement-entitlements.php` | خارج نطاق طبقة Features بالكامل (§14) |
| `includes/event-factory.php` | الرصيد خارج نطاق طبقة Features بالكامل (§14)؛ لا علاقة له بتخزين الميزات |
| `includes/class-mon-events-users.php` | يخص Snapshot (Phase 4) حصراً |
| `includes/class-cartat-handler.php`, `includes/class-ultramsg-handler.php`, `includes/class-salla-handler.php` | غير معنيّة بـPhase 2 — لا استهلاك من أي Handler في هذه المرحلة |
| ملفات Legacy (`admin-mods.php`, `class-pge-packages.php`) | نظام Legacy مستقر (§0) |
| أي ملف Frontend (`page-create-event.php`, `templates/`) | الاستهلاك الفعلي يبدأ في Phase 6 فقط |
| `docs/PACKAGE-FEATURE-MATRIX.md`, `docs/FEATURES-IMPLEMENTATION-PLAN.md`, `docs/DECISION-LOG.md` | وثائق مرجعية/معمارية، لا تُعدَّل ضمن أي وثيقة تنفيذ |

---

## 6. Data Flow

في نطاق Phase 2 وحدها، لا يوجد أي مستهلِك فعلي بعد (لا Resolver، لا Admin UI، لا صفحة أمامية) — تدفق البيانات المتاح في هذه المرحلة هو تدفق داخلي بين الـRepository والجدول فقط:

1. **الكتابة (Upsert)**: طرف مستدعٍ (غير موجود بعد في هذه المرحلة — سيكون Admin UI في Phase 5) يمرّر `tier_id` و`feature_key` وقيمة خام (`$raw_value`، نص دائماً) إلى الـRepository. الـRepository يكتب أو يحدّث الصف المطابق لـ`(tier_id, feature_key)` دون أي تحقق من نوع القيمة أو من وجود `feature_key` في Feature Registry (هذا التحقق مسؤولية الطبقة المستدعية في Phase 5، وفق `PACKAGE-FEATURE-MATRIX.md §8`، لا الـRepository نفسه).
2. **القراءة (قيمة واحدة)**: طرف مستدعٍ (سيكون الـResolver في Phase 3) يطلب قيمة ميزة واحدة لـTier محدَّد عبر `tier_id` و`feature_key`؛ الـRepository يُعيد القيمة الخام كما هي مخزَّنة (نص) أو يُعيد إشارة "غير موجود" (راجع Blocked في القسم 7).
3. **القراءة (كل الميزات)**: طرف مستدعٍ يطلب كل صفوف Tier محدَّد؛ الـRepository يُعيد كل القيم الخام الخاصة بذلك الـTier فقط (عزل كامل بين Tiers مختلفة عبر `tier_id`).
4. **الحذف**: طرف مستدعٍ يطلب حذف صف ميزة واحدة لـTier محدَّد؛ الـRepository يحذف الصف المطابق إن وُجد (عملية Idempotent — لا خطأ إذا لم يوجد الصف أصلاً).

لا يوجد أي تدفق آخر ضمن Phase 2 — لا اتصال بـSnapshot، لا اتصال بـFeature Registry من داخل الـRepository نفسه (الـRepository لا يستدعي `PGE_Feature_Registry` إطلاقاً في هذه المرحلة).

---

## 7. Public API

كل الدوال أدناه في `includes/class-pge-tier-features.php`، منقولة حرفياً من قائمة العمليات المطلوبة في `FEATURES-IMPLEMENTATION-PLAN.md` (Phase 2، "خطوات التنفيذ" بند 3). بدون أي تنفيذ فعلي هنا — توصيف فقط.

### `get_tier_feature_value($tier_id, $feature_key)`
- **المدخلات**: `$tier_id` (معرّف الـTier)، `$feature_key` (نص، اسم مفتاح الميزة).
- **المخرجات**: القيمة الخام المخزَّنة (نص) إن وُجد الصف المطابق لـ`(tier_id, feature_key)`.
- **السلوك**: قراءة مباشرة بلا أي تفسير نوع — القيمة تُعاد كما خُزِّنت حرفياً.
- **حالات الخطأ/عدم الوجود**: معتمدة عبر `DEC-002` (`docs/DECISION-LOG.md`): تُعيد `string` إذا كانت القيمة موجودة؛ تُعيد `null` إذا لم يوجد الصف؛ تُعيد `false` إذا فشل استعلام قاعدة البيانات.
- **Validation Responsibility**: هذه الدالة مسؤولة عن التخزين الخام فقط. لا تقوم بأي Type Validation. لا تقوم بأي Business Validation. لا تتحقق من وجود `feature_key` داخل Feature Registry. لا تفسّر نوع القيمة المُعادة. كل عمليات التحقق تقع على الطبقات الأعلى (الـResolver في Phase 3 لتفسير النوع؛ الإدارة في Phase 5 للتحقق من الوجود في Registry) وفق `PACKAGE-FEATURE-MATRIX.md §7` و`§8`.

### `get_all_tier_features($tier_id)`
- **المدخلات**: `$tier_id`.
- **المخرجات**: كل صفوف الميزات الخاصة بهذا الـTier فقط (لا صفوف من Tiers أخرى).
- **السلوك**: قراءة جماعية معزولة بـ`tier_id`.
- **حالات الخطأ/عدم الوجود**: معتمدة عبر `DEC-002` (`docs/DECISION-LOG.md`): تُعيد `array` إذا نجح الاستعلام (قد تكون `[]` إذا لم توجد أي صفوف — لا لبس هنا؛ التمثيل الطبيعي الوحيد لعدم وجود صفوف)؛ تُعيد `false` إذا فشل استعلام قاعدة البيانات.
- **Validation Responsibility**: هذه الدالة مسؤولة عن التخزين الخام فقط. لا تقوم بأي Type Validation. لا تقوم بأي Business Validation. لا تتحقق من وجود أي `feature_key` داخل Feature Registry. لا تفسّر نوع أي قيمة مُعادة. كل عمليات التحقق تقع على الطبقات الأعلى وفق الوثائق المرجعية.

### `set_tier_feature_value($tier_id, $feature_key, $raw_value)`
- **المدخلات**: `$tier_id`، `$feature_key`، `$raw_value` (نص خام دائماً — `LONGTEXT`، وفق §5).
- **المخرجات**: معتمدة عبر `DEC-002` (`docs/DECISION-LOG.md`): تُعيد `true` عند نجاح عملية الإدراج أو التحديث؛ تُعيد `false` عند فشل الكتابة في قاعدة البيانات.
- **السلوك**: Upsert وفق `UNIQUE (tier_id, feature_key)` — كتابة صف جديد إن لم يوجد، أو تحديث الصف الموجود إن وُجد. **لا فحص نوع أو Validation على `$raw_value` داخل هذه الدالة** (`FEATURES-IMPLEMENTATION-PLAN.md`، Phase 2، خطوات التنفيذ بند 4).
- **حالات الخطأ**: معتمدة عبر `DEC-002`: فشل الكتابة في قاعدة البيانات نفسها (خطأ اتصال/قيد آخر) يُعيد `false`، بنفس عقد "المخرجات" أعلاه.
- **Validation Responsibility**: هذه الدالة مسؤولة عن التخزين الخام فقط. لا تقوم بأي Type Validation على `$raw_value`. لا تقوم بأي Business Validation. لا تتحقق من وجود `feature_key` داخل Feature Registry قبل الكتابة. لا تفسّر نوع القيمة المُخزَّنة. كل عمليات التحقق (نوع القيمة، وجود المفتاح في Registry) تقع على الطبقة المستدعية الأعلى (الإدارة، Phase 5) وفق `PACKAGE-FEATURE-MATRIX.md §8`.

### `delete_tier_feature($tier_id, $feature_key)`
- **المدخلات**: `$tier_id`، `$feature_key`.
- **المخرجات**: معتمدة عبر `DEC-002` (`docs/DECISION-LOG.md`): تُعيد `true` عند نجاح تنفيذ الحذف — سواء تم حذف صف فعلياً أو لم يكن الصف موجوداً أصلاً (Idempotent)؛ تُعيد `false` إذا فشل استعلام قاعدة البيانات.
- **السلوك**: حذف الصف المطابق لـ`(tier_id, feature_key)` إن وُجد. عملية Idempotent (استدعاؤها على صف غير موجود لا يُنتج خطأً فادحاً، وتُعيد `true` بنفس عقد "المخرجات" أعلاه).
- **Validation Responsibility**: هذه الدالة مسؤولة عن التخزين الخام فقط (حذف صف). لا تقوم بأي Type Validation. لا تقوم بأي Business Validation. لا تتحقق من وجود `feature_key` داخل Feature Registry. لا تفسّر نوع أي قيمة. كل عمليات التحقق تقع على الطبقات الأعلى وفق الوثائق المرجعية.

---

## 8. Validation Rules

- **لا فحص نوع داخل الـRepository إطلاقاً** — القيمة الخام (`$raw_value`) تُقبَل وتُخزَّن كنص كما هي، بلا أي تحويل أو تحقق (`FEATURES-IMPLEMENTATION-PLAN.md`، Phase 2، خطوات التنفيذ بند 4). التحقق من الصحة وفق `validation` المعرَّف في Feature Registry هو مسؤولية الطبقة المستدعية (الإدارة، Phase 5)، لا هذه الطبقة.
- **لا تحقق من وجود `feature_key` في Feature Registry داخل الـRepository** — إنفاذ قاعدة "لا Feature بلا Registry أولاً" (`PACKAGE-FEATURE-MATRIX.md §8`) يقع على مستوى واجهة الإدارة المستقبلية (Phase 5)، لا على مستوى التخزين الخام نفسه. الـRepository في هذه المرحلة يقبل أي `feature_key` نصي دون تمييز.
- **القيد البنيوي الوحيد المفروض فعلياً هو `UNIQUE (tier_id, feature_key)`** على مستوى قاعدة البيانات — لا يسمح بوجود أكثر من صف لنفس الزوج، ويُعتمَد عليه وحده لمنع التكرار (لا فحص تطبيقي "افحص ثم أدرج" منفصل، اتساقاً مع النمط المُتَّبع فعلياً في `class-pge-invitation-credit-ledger.php` الموثَّق في تعليقات ذلك الملف).
- **لا `FOREIGN KEY` فعلية بين `mon_tier_features.tier_id` و`mon_plan_tiers.id`** — نفس النمط المُتَّبع في كل جداول المشروع الأخرى (لا قيد مرجعي على مستوى قاعدة البيانات؛ راجع "Rollback" في `FEATURES-IMPLEMENTATION-PLAN.md` Phase 2: "لا أثر على أي جدول آخر (بلا FOREIGN KEY فعلية)").

---

## 9. Commit Strategy

الترقيم أدناه محلي لهذه المرحلة (Commit 1/2/3 من Phase 2)، ويقابل حرفياً Commit 3/4/5 في `FEATURES-IMPLEMENTATION-PLAN.md §7 — Implementation Order` (الترقيم العام هناك يشمل كل المراحل تسلسلياً؛ الترقيم هنا محلي فقط لسهولة تنفيذ Phase 2 منفردة، كما جرى العمل بنفس الأسلوب في `FEATURES-PHASE-1-SPEC.md`).

### Commit 1 (يقابل Commit 3 العام)
- **الهدف**: إضافة تعريف جدول `mon_tier_features` إلى `get_schema_sql()` في `includes/class-mon-catalog-schema.php`، ورفع `DB_VERSION`، مع Upgrade Routine بنفس أسلوب الترقيات السابقة.
- **الملفات**: `includes/class-mon-catalog-schema.php` (تعديل فقط).
- **Definition of Done**: الجدول مُعرَّف بالضبط وفق البنية في `PACKAGE-FEATURE-MATRIX.md §5` (الأعمدة الأربعة + `UNIQUE (tier_id, feature_key)`، بلا عمود `value_type`، وعمود `feature_key` بطول `VARCHAR(64)` المعتمد في `DECISION-LOG.md` — `DEC-001`)؛ `DB_VERSION` مرفوع؛ Upgrade Routine يعمل على قاعدة بيانات موجودة مسبقاً بلا فقدان بيانات؛ صفر تعديل على `mon_plans`/`mon_plan_tiers`.

**Rollback Expectations**

الأوصاف أدناه مبنية على السلوك الفعلي القائم لآلية الترقية في `includes/class-mon-catalog-schema.php` (`maybe_upgrade()`)، والتي ينص `FEATURES-IMPLEMENTATION-PLAN.md` صراحة على أن Upgrade Routine هذه المرحلة يجب أن يتبع "نفس أسلوب الترقيات السابقة" فيها — لا اقتراح آلية جديدة، بل وصف الآلية القائمة نفسها:

- عند فشل Upgrade Routine (إعادة `false`)، تتوقف سلسلة الترقية فوراً — لا تُنفَّذ أي خطوة ترقية لاحقة بعدها في نفس الطلب.
- لا يُحدَّث `DB_VERSION_OPTION` إلى رقم الإصدار المستهدف لهذه المرحلة عند الفشل — يبقى مسجَّلاً عند آخر إصدار نجح فعلاً، بما يسمح بإعادة محاولة الترقية في الطلب التالي.
- لا تُعدَّل أي جدول آخر غير `mon_tier_features` ضمن Upgrade Routine هذه المرحلة (كل ترقية سابقة موثَّقة في نفس الملف مقصورة على جدولها الخاص فقط).
- لا يبدأ Commit 2 قبل نجاح هذا الـUpgrade Routine بالكامل (`DB_VERSION_OPTION` يعكس الإصدار الجديد) — تسلسل يفرضه ترتيب الاعتماد في القسم 9 نفسه.

**Documentation Note**

سلوك `dbDelta()` نفسها (المُستدعاة داخل `sync_schema()` قبل أي Upgrade Routine) عند فشل جزئي أثناء تنفيذ `CREATE TABLE` نفسه (مثال: انقطاع اتصال قاعدة البيانات أثناء التنفيذ) غير موثَّق في أي من الوثيقتين المرجعيتين أو في تعليقات الكود الحالي — هذا سلوك مستوى WordPress Core/MySQL لا يحدِّده أي مرجع من مراجع هذا المشروع. المشروع لا يفترض أي ضمانات إضافية لسلوك `dbDelta()` في هذه الحالة؛ التنفيذ يعتمد على السلوك القياسي لووردبريس كما هو، بلا أي توثيق لضمانات غير موجودة فعلياً. هذا حدّ معرفي توثيقي (Documentation Limitation) لا مانعاً معمارياً — **لا يمثل مانعاً لبدء Commit 1**.

### Commit 2 (يقابل Commit 4 العام)
- **الهدف**: إنشاء `includes/class-pge-tier-features.php` (الدوال الأربع في القسم 7 أعلاه) وربطه بـ`require_once` في `pgevents-core.php`.
- **الملفات**: `includes/class-pge-tier-features.php` (جديد)، `pgevents-core.php` (تعديل سطر واحد).
- **Definition of Done**: الدوال الأربع موجودة وتعمل على الجدول الحقيقي (الناتج من Commit 1) بلا أي تفسير نوع؛ صفر استهلاك من أي كود آخر بعد.

### Commit 3 (يقابل Commit 5 العام)
- **الهدف**: كتابة `tests/test-tier-features.php`.
- **الملفات**: `tests/test-tier-features.php` (جديد).
- **Definition of Done**: كل بنود "Testing Checklist" (القسم 10 أدناه) مُحقَّقة.

لا Commit رابع ضمن نطاق Phase 2 — أي عمل لاحق (Resolver، Commit 6 فصاعداً في الترقيم العام) يخص Phase 3 ولا يُوصَف هنا.

---

## 10. Testing Checklist

منقولة حرفياً من "الاختبارات المطلوبة" لـPhase 2 في `FEATURES-IMPLEMENTATION-PLAN.md`:

- [ ] إنشاء الجدول عبر Migration وهمية (Fake `$wpdb`) ينجح، بنفس نمط `tests/test-invitation-credit-ledger.php`.
- [ ] Upsert لنفس `(tier_id, feature_key)` مرتين يُحدِّث الصف الموجود، لا يُنشئ تكراراً (يحترم `UNIQUE`).
- [ ] قراءة ميزة غير موجودة لـTier معيَّن تُعيد نتيجة "غير موجود" صريحة، لا خطأ فادح (Fatal) — القيمة الحرفية الدقيقة معلَّقة (راجع Blocked في القسم 7).
- [ ] قراءة كل ميزات Tier تُعيد فقط الصفوف الخاصة به (عزل بين Tiers مختلفة).

---

## 11. Regression Checklist

- [ ] صفر تغيير في سلوك `PGE_Catalog::create_tier()`/`update_tier()`.
- [ ] صفر تغيير في بنية أو بيانات `mon_plans`/`mon_plan_tiers`.
- [ ] كل اختبارات الانحدار القائمة (الرصيد، الـLedger، الـReplacement Entitlements، بطاقات Dashboard، Feature Registry من Phase 1) تستمر بالمرور دون أي تعديل عليها.
- [ ] `pgevents-core.php` يستمر بالتحميل بلا أخطاء PHP فادحة بعد إضافة سطر الـ`require_once` الجديد.
- [ ] Migration تعمل على قاعدة بيانات تحتوي بيانات موجودة مسبقاً (Ledger، Replacement Entitlements، إلخ) بلا فقدان أو تلف بيانات.

---

## 12. Definition of Done

مأخوذ حرفياً من `FEATURES-IMPLEMENTATION-PLAN.md`، Phase 2، Definition of Done:

الجدول موجود وفق التصميم الحرفي في §5؛ الـRepository يقرأ ويكتب بلا أي تفسير نوع؛ لا بيانات فعلية مُدخَلة بعد (لا واجهة إدخال حتى Phase 5).

**شروط إضافية لاعتبار Phase 2 بأكملها منتهية** (Commits 1-3 معاً):
- [ ] بنية الجدول مطابقة حرفياً لـ§5 (لا عمود `value_type`، `feature_value` من نوع `LONGTEXT`).
- [ ] `UNIQUE (tier_id, feature_key)` مُفعَّل ومُختبَر.
- [ ] الـRepository لا يحتوي أي منطق تفسير نوع (مراجعة كود).
- [ ] صفر تعديل على `mon_plans`/`mon_plan_tiers`.
- [ ] كل بنود Testing Checklist وRegression Checklist أعلاه مُحقَّقة.

---

## 13. Blocking Conditions

يجب التوقف فوراً وكتابة `Blocked` دون اتخاذ أي قرار بديل في أي من الحالات التالية:

- عند وجود ترقية `DB_VERSION` أخرى جارية بالتوازي تتعارض مع الرقم التالي المتوقَّع (`1.8.0`).
- أي طلب لربط هذه الطبقة باستهلاك فعلي (Resolver، Admin UI، أي صفحة) قبل اكتمال Phase 2 نفسها — هذا يخص مراحل لاحقة فقط.
- أي طلب لإضافة عمود أو حقل غير مذكور حرفياً في §5 (مثل `value_type` أو أي Metadata إضافية).

---

## 14. Out Of Scope

- الـResolver (`pge_user_has_feature`, `pge_get_user_feature_value`, `pge_get_user_package_features`) — Phase 3.
- منطق Snapshot (`_mon_package_features`, `_mon_package_feature_version`) — Phase 4.
- أي واجهة إدارة لإدخال قيم الميزات — Phase 5.
- أي ربط بـ`/create-event/` أو أي صفحة أمامية أخرى — Phase 6.
- أي اختبار تكامل شامل عبر كل المراحل — Phase 7.
- أي تفسير لنوع القيمة (`boolean`/`integer`/`percentage`) — ليس عمل هذه الطبقة إطلاقاً في أي مرحلة.
- أي تعديل على Feature Registry أو أي ميزة جديدة فيه — Phase 1 مكتملة ومغلقة.
- أي تعديل على `mon_plans`/`mon_plan_tiers`/`PGE_Catalog::create_tier()`/`update_tier()`.
- أي تعديل على الرصيد (Ledger، Replacement Entitlements، `event-factory.php`) — خارج نطاق طبقة Features بالكامل (§14 من الوثيقة المعمارية).
- أي تعديل على Legacy أو Cartat/UltraMsg/Salla.

---
