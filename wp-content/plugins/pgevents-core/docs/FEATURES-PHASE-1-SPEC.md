# Features — Phase 1 Specification (Feature Registry)

> مواصفات تنفيذ دقيقة (Execution Specification) — **ليست وثيقة معمارية، وليست خطة مشروع.**
> لا قرار معماري جديد هنا، ولا تفسير أو تغيير لأي قرار قائم، ولا اقتراح تحسين، ولا إعادة تصميم.
> كل بند غير موجود حرفياً في الوثيقتين المرجعيتين أدناه يُكتب `Blocked`.
> تاريخ الإعداد: 2026-07-25.

**المراجع الملزمة (لا تُكرَّر هنا، يُحال إليها عند الحاجة):**
- `docs/PACKAGE-FEATURE-MATRIX.md` — المرجع المعماري النهائي (Frozen).
- `docs/FEATURES-IMPLEMENTATION-PLAN.md` — خطة التنفيذ العامة، تحديداً قسم "Phase 1 — Feature Registry".

---

## 1. الهدف

هذه الوثيقة تغطي **Phase 1 فقط** — بناء Feature Registry (المرجع البرمجي الوحيد لتعريف الميزات) والـRegistry Provider (واجهة الوصول الموحَّدة إليه) — كما وردت حرفياً في قسم "Phase 1 — Feature Registry" من `FEATURES-IMPLEMENTATION-PLAN.md`، والمبنية على `PACKAGE-FEATURE-MATRIX.md §6` (Feature Registry) و§8 (قاعدة "لا Feature بلا Registry أولاً").

**لا يجوز استخدام هذه الوثيقة كمرجع لأي مرحلة أخرى.** أي عمل يخص Tier Features (تخزين)، Resolver، Snapshot، Admin Integration، Create Event Integration، أو Testing الشامل (المراحل 2-7 في خطة التنفيذ) له وثيقة/مواصفات خاصة به منفصلة عن هذه الوثيقة تماماً، ولا تصفها هذه الوثيقة بأي تفصيل تنفيذي.

---

## 2. Scope

Phase 1 يغطي حصراً: تعريف الميزات التسعة عشر (19) ببياناتها الثمانية كاملة (`key`, `type`, `default`, `category`, `admin_label`, `description`, `validation`, `lifecycle`) كبنية بيانات ثابتة في كود PHP، ووراءها واجهة وصول موحَّدة (Registry Provider)، وربط الملف الجديد بنقطة تحميل الإضافة (`pgevents-core.php`)، واختبار هذه الطبقة بمعزل عن أي طبقة أخرى.

Phase 1 **لا يشمل** أي جدول قاعدة بيانات، أي منطق قراءة لمستخدم فعلي، أي واجهة إدارة، وأي استهلاك من أي صفحة أمامية. هذه الحدود مأخوذة حرفياً من `FEATURES-IMPLEMENTATION-PLAN.md` قسم "Phase 1 — Feature Registry": *"بلا أي جدول قاعدة بيانات في هذه المرحلة"*، و*"لا استهلاك له من أي صفحة مستخدم بعد"* (Definition of Done).

---

## 3. In Scope

- إنشاء ملف `includes/class-pge-feature-registry.php` يحوي:
  - بنية بيانات ثابتة لـ19 ميزة، منقولة حرفياً من `PACKAGE-FEATURE-MATRIX.md §6`.
  - واجهة وصول واحدة موحَّدة (Registry Provider) بحيث لا يُقرأ البنية الخام من أي كود خارج هذا الملف مباشرة (مبدأ Registry Provider و"Dependency Direction" في §6).
  - رفض صريح (لا `null` صامت) لأي `feature_key` غير موجود عند الاستعلام عنه.
- تطبيق مبدأ Registry Independence: لا حقل في أي تعريف ميزة يشير إلى `plan_key`/`tier_id` محدَّد (§6 "Registry Independence").
- إضافة سطر `require_once` واحد في `pgevents-core.php` لتحميل الملف الجديد.
- كتابة `tests/test-feature-registry.php` (مُدرَج في `FEATURES-IMPLEMENTATION-PLAN.md §7 — Implementation Order` كـCommit 2، تابع مباشرة لاكتمال Phase 1).

---

## 4. Out of Scope

كل ما يلي **خارج نطاق هذه الوثيقة تماماً**، سواء كان مذكوراً في خطة التنفيذ العامة أو الوثيقة المعمارية:

- جدول `mon_tier_features` وRepository القراءة/الكتابة الخاص به (Phase 2 — Tier Features).
- الدوال الثلاث للـResolver (`pge_user_has_feature`, `pge_get_user_feature_value`, `pge_get_user_package_features`) (Phase 3).
- منطق Snapshot (`_mon_package_features`, `_mon_package_feature_version`) (Phase 4).
- أي واجهة إدارة لإدخال قيم الميزات (Phase 5).
- أي تعديل على `/create-event/` أو أي صفحة أمامية أخرى (Phase 6).
- اختبارات التكامل الشاملة عبر كل المراحل، أو أي Regression Test خارج نطاق Phase 1 نفسها (جزء من Phase 7 الأوسع).
- كل ما ورد في قسم "Out of Scope" في `FEATURES-IMPLEMENTATION-PLAN.md` (المضيفون/المشرفون، الرسائل الجديدة، الصور المخصصة، ميزات بلس الاجتماعية والتجارية، وغيرها) — هذه أصلاً خارج نطاق المرحلة الأولى من خطة التنفيذ العامة، وبالتالي خارج نطاق هذه الوثيقة من باب أولى.
- أي تعديل على `mon_plans`, `mon_plan_tiers`, Ledger, Replacement Entitlements, Cartat, Salla, أو أي نظام Legacy.

---

## 5. الملفات المسموح تعديلها

| File | Purpose | Modify Type |
|---|---|---|
| `pgevents-core.php` | إضافة سطر `require_once` واحد لتحميل `includes/class-pge-feature-registry.php`، بنفس نمط أسطر التحميل الحالية | Modify |

لا يوجد ملف مسموح تعديله غير المذكور أعلاه ضمن نطاق Phase 1 — هذا مطابق حرفياً لقسم "الملفات المتوقع تعديلها" في `FEATURES-IMPLEMENTATION-PLAN.md` (Phase 1).

---

## 6. الملفات الممنوع تعديلها

| File | السبب |
|---|---|
| `includes/class-pge-catalog.php` | مذكور صراحة في قائمة الملفات الممنوعة لـPhase 1 في خطة التنفيذ — Registry طبقة معزولة عن Catalog تماماً في هذه المرحلة |
| `includes/class-mon-catalog-schema.php` | لا جدول قاعدة بيانات في Phase 1 — التعديل على Schema يخص Phase 2 حصراً |
| `includes/class-pge-invitation-credit-ledger.php` | خارج نطاق طبقة Features بالكامل (`PACKAGE-FEATURE-MATRIX.md §14 — Not Part of Feature System`) |
| `includes/class-pge-replacement-entitlements.php` | نفس السبب أعلاه (§14) |
| `includes/class-cartat-handler.php` | غير معنيّ بـPhase 1 — لا استهلاك للـRegistry من أي Handler في هذه المرحلة |
| `includes/class-salla-handler.php` | نفس السبب أعلاه |
| `includes/class-mon-events-users.php` | يخص Snapshot (Phase 4) حصراً — لا علاقة له بتعريف Registry |
| `includes/event-factory.php` | الرصيد (`invitation_credit_limit`/`replacement_credit_limit`) خارج نطاق طبقة Features بالكامل (§14)، ولا علاقة له بـPhase 1 |
| ملفات Legacy (`admin-mods.php`, `class-pge-packages.php`, وغيرها) | نظام Legacy مستقر ولا يُعاد تصميمه (`PACKAGE-FEATURE-MATRIX.md §0`) |
| `mon_plans`, `mon_plan_tiers` (لا `ALTER TABLE`) | لا تعديل Schema من أي نوع في Phase 1 — لا جدول جديد ولا تعديل جدول قائم |
| أي ملف Frontend (`page-create-event.php`، قوالب `templates/`) | الاستهلاك الفعلي للـRegistry يبدأ في Phase 6 فقط — صفر استهلاك في Phase 1 |

---

## 7. New Files

| الملف | الوظيفة |
|---|---|
| `includes/class-pge-feature-registry.php` | يحوي بيانات Registry (تعريف الـ19 ميزة، منقولة حرفياً من `PACKAGE-FEATURE-MATRIX.md §6`) + Registry Provider (واجهة وصول موحَّدة، وفق توصية "Implementation Guideline" في §6 — `get()`/`has()`/`all()` غير إلزامية الشكل، لكن يجب أن تبقى نقطة وصول واحدة) |
| `tests/test-feature-registry.php` | اختبار Phase 1 المستقل — يتحقق من عدد الميزات (19)، اكتمال الحقول الثمانية لكل ميزة، ورفض أي `feature_key` غير معرَّف |

---

## 8. Dependencies

وفق `FEATURES-IMPLEMENTATION-PLAN.md §3 — Dependencies`:

| Phase | يعتمد على |
|---|---|
| Feature Registry (Phase 1) | لا شيء (نقطة البداية) |

Phase 1 لا يعتمد على أي عمل سابق. (للعلم فقط — بلا وصف تنفيذي: Phase 2 "Tier Features" هي التي تعتمد على اكتمال Phase 1، وفق نفس الجدول في خطة التنفيذ.)

---

## 9. Commit Strategy

مأخوذة حرفياً من `FEATURES-IMPLEMENTATION-PLAN.md §7 — Implementation Order`، Commit 1 وCommit 2 فقط (هما وحدهما ضمن نطاق Phase 1):

### Commit 1
- **الهدف**: إنشاء `includes/class-pge-feature-registry.php` (بيانات الـ19 ميزة + Registry Provider) وربطه بـ`require_once` في `pgevents-core.php`.
- **الملفات**: `includes/class-pge-feature-registry.php` (جديد)، `pgevents-core.php` (تعديل سطر واحد).
- **سبب الفصل**: هذا هو الترتيب المحدَّد حرفياً في §7 من خطة التنفيذ (Commit 1) — الكود الإنتاجي أولاً، بمعزل عن أي اختبار.

### Commit 2
- **الهدف**: إضافة `tests/test-feature-registry.php` للتحقق من Commit 1.
- **الملفات**: `tests/test-feature-registry.php` (جديد).
- **سبب الفصل**: مُحدَّد حرفياً في §7 من خطة التنفيذ (Commit 2) — فصل كود الاختبار عن الكود الإنتاجي في Commit مستقل، متسقاً مع مبدأ "الحفاظ على Commit صغير ومركَّز" المطبَّق في خطة التنفيذ نفسها (مذكور صراحة عند تبرير فصل Phase 3 عن Phase 4 هناك).

لا Commit ثالث ضمن نطاق Phase 1 — أي Commit لاحق (Commit 3 فصاعداً في §7) يخص Phase 2 ولا يُوصَف هنا.

---

## 10. Code Review Checklist

كل بند أدناه مُشتقّ حرفياً من قواعد واردة في الوثيقتين المرجعيتين — لا بند مُخترَع:

- [ ] لا يوجد منطق تفسير قيم (Interpretation Logic) داخل ملف Registry — تفسير القيم مهمة الـResolver حصراً (Phase 3)، لا Registry (`PACKAGE-FEATURE-MATRIX.md §7`: "الـResolver لا يفسّر القيمة الخام مباشرة... يسأل Registry أولاً").
- [ ] لا يوجد أي استعلام SQL أو أي تفاعل مع قاعدة البيانات داخل Registry — "Registry هو تعريف برمجي (كود)، لا قاعدة بيانات" (§6).
- [ ] لا يوجد أي Feature (مفتاح) مُستخدَم أو مُفترَض في أي مكان دون أن يكون معرَّفاً أولاً في Registry بكامل حقوله الثمانية (§6، §8).
- [ ] لا يوجد وصول مباشر لبنية البيانات الخام (Array) من أي كود خارج هذا الملف — الوصول حصراً عبر Registry Provider (§6 "Registry Provider"، "Dependency Direction").
- [ ] لا يوجد تكرار تعريف (Duplicate Definition) لنفس الـ`feature_key` — كل مفتاح يظهر مرة واحدة فقط، والعدد الكلي = 19 بالضبط (§6، Acceptance Criteria لـPhase 1 في خطة التنفيذ).
- [ ] لا إشارة لأي `plan_key`/`tier_id` محدَّد داخل تعريف أي ميزة (مبدأ Registry Independence، §6).
- [ ] كل ميزة تحمل الحقول الثمانية كاملة، بلا حقل تاسع مُضاف أو حقل ناقص (§6، "حالات الفشل المحتملة" في خطة التنفيذ لـPhase 1).

---

## 11. Testing Checklist

مأخوذة حرفياً من قسم "الاختبارات المطلوبة" و"Acceptance Criteria" لـPhase 1 في `FEATURES-IMPLEMENTATION-PLAN.md`:

- [ ] كل ميزة من الـ19 تُقرأ بكامل حقولها الثمانية، ومطابقة لـ`PACKAGE-FEATURE-MATRIX.md §6` حرفياً.
- [ ] استعلام عن `feature_key` غير موجود يُعيد نتيجة "غير موجود" صريحة، لا `null` صامت يُفسَّر خطأً كقيمة صالحة.
- [ ] عدد الميزات المُعادة من `all()` (أو المكافئ) = 19 بالضبط.
- [ ] الوصول يتم حصراً عبر واجهة واحدة (Provider)، لا عبر البنية الخام مباشرة من أي كود مستهلِك خارجي (تحقُّق يدوي/مراجعة كود).

---

## 12. Regression Checklist

Phase 1 طبقة معزولة بالكامل بلا أي استهلاك من كود قائم (`FEATURES-IMPLEMENTATION-PLAN.md`، Phase 1، Definition of Done: "لا استهلاك له من أي صفحة مستخدم بعد") — لذلك نطاق التأكد من عدم التغيير محدود لكنه إلزامي:

- [ ] لا تغيير في سلوك أي صفحة أمامية أو لوحة إدارة قائمة — لا صفحة تستهلك Registry بعد.
- [ ] لا تغيير في أي اختبار موجود مسبقاً في `tests/` (اختبارات الرصيد، الـLedger، الـReplacement Entitlements، بطاقات Dashboard) — هذه الملفات لا تُعدَّل ولا تتأثر.
- [ ] `pgevents-core.php` يستمر بالتحميل بلا أخطاء PHP فادحة بعد إضافة سطر الـ`require_once` الجديد.
- [ ] صفر تعديل على أي من الملفات المذكورة في القسم 6 أعلاه (الملفات الممنوع تعديلها).

---

## 13. Rollback

مأخوذ حرفياً من "Rollback" لـPhase 1 في `FEATURES-IMPLEMENTATION-PLAN.md`، مع تضمين ملف الاختبار المُضاف في Commit 2 (نفس نطاق هذه الوثيقة):

حذف `includes/class-pge-feature-registry.php` و`tests/test-feature-registry.php`، وإزالة سطر الـ`require_once` من `pgevents-core.php` — **لا أثر على أي نظام آخر**، لأن هذه طبقة معزولة بالكامل بلا جدول قاعدة بيانات أو Snapshot متأثر في هذه المرحلة.

---

## 14. Definition of Done

مأخوذ حرفياً من `FEATURES-IMPLEMENTATION-PLAN.md`، Phase 1، Definition of Done:

الملف موجود، يحمّل بلا أخطاء PHP، ويُعيد الـ19 ميزة مطابقة لـ`PACKAGE-FEATURE-MATRIX.md §6` حرفياً؛ لا استهلاك له من أي صفحة مستخدم بعد.

---

## 15. Ready For Phase 2

وفق `FEATURES-IMPLEMENTATION-PLAN.md §3 — Dependencies` ("Tier Features (Phase 2) | Feature Registry") وقسم Preconditions لـPhase 2 ("Phase 1 مكتملة (Registry Provider موجود)")، الانتقال إلى Phase 2 يتطلب اكتمال كل ما يلي من هذه الوثيقة:

- [ ] كل بنود "Code Review Checklist" (القسم 10) مُحقَّقة.
- [ ] كل بنود "Testing Checklist" (القسم 11) مُحقَّقة.
- [ ] كل بنود "Regression Checklist" (القسم 12) مُحقَّقة.
- [ ] "Definition of Done" (القسم 14) مُحقَّق بالكامل.
- [ ] Registry Provider موجود وقابل للاستدعاء كنقطة وصول وحيدة، جاهز ليُستخدَم كـPrecondition لبناء `includes/class-pge-tier-features.php` في Phase 2 (لا وصف تنفيذي لـPhase 2 هنا — فقط شرط الجاهزية).

---
