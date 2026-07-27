# Features Implementation Plan — خطة التنفيذ الرسمية

> وثيقة تنفيذ عملية، وليست معمارية. **لا كود منفَّذ هنا، لا Migration، لا تعديل PHP، لا جدول فعلي.**
> تاريخ الإعداد: 2026-07-25.

---

## 1. الهدف

هذه الوثيقة هي **خطة التنفيذ الرسمية لطبقة Features** فوق نظام Catalog الحالي. المرجع المعماري الوحيد لكل قرار وارد هنا هو:

```
docs/PACKAGE-FEATURE-MATRIX.md
```

الوثيقة المعمارية أعلاه **معتمدة نهائياً (Frozen)** ولا تُعدَّل هنا بأي شكل. كل خطوة تنفيذية في هذه الوثيقة مُشتقَّة حرفياً منها؛ حيثما احتاج التنفيذ قراراً غير موجود فيها صراحة، تُكتب الكلمة **`Blocked`** بدل اختراع أي قرار جديد — راجع كل عنصر `Blocked` كنقطة يجب الرجوع فيها إلى المرجع المعماري أو لقرار تجاري إضافي قبل المتابعة.

نطاق هذه الوثيقة تحديداً: **المرحلة الأولى** من خارطة الطريق الموصوفة في `PACKAGE-FEATURE-MATRIX.md §12` (Foundation) + أول شريحة تكامل فعلي مع `/create-event/` (نقل `google_maps` فقط، وهو القرار التجاري الوحيد المحسوم بالكامل في §3 من الوثيقة المعمارية). كل ما بعد ذلك (المراسلات، المضيفون/المشرفون، ميزات بلس الاجتماعية) خارج نطاق هذه الوثيقة — راجع قسم "Out of Scope".

---

## 2. مراحل التنفيذ

> التقسيم أدناه مشتق من `§12` (خطة الانتقال التدريجية) في الوثيقة المعمارية، مع تفكيك "المرحلة 1" هناك إلى خطوات تنفيذية أدق قابلة للـCommit المنفصل — بلا أي تغيير في نطاقها أو ترتيبها المنطقي المعتمد.

### Phase 1 — Feature Registry

**الهدف**
بناء Feature Registry ووراءه Registry Provider (§6 و"Registry Provider" في الوثيقة المعمارية) ككود ثابت — تعريف الـ19 ميزة بكامل حقولها التسعة (`key`, `type`, `default`, `category`, `admin_label`, `description`, `validation`, `lifecycle`)، ووراء ذلك واجهة وصول موحَّدة. بلا أي جدول قاعدة بيانات في هذه المرحلة.

**الملفات المتوقع تعديلها**
- `pgevents-core.php` — إضافة سطر `require_once` واحد للملف الجديد (بنفس نمط الأسطر 24-38 الحالية).

**الملفات الجديدة**
- `includes/class-pge-feature-registry.php` — يحوي بيانات Registry (مصفوفة الـ19 ميزة وفق §6 حرفياً) + Registry Provider (توصية §"Implementation Guideline": `get()`/`has()`/`all()` — غير إلزامية، قد يُكتفى بدالة وصول واحدة إن استُخدِمت بانضباط).

**الملفات التي يُمنع تعديلها**
`class-pge-catalog.php`, `class-mon-catalog-schema.php`, `class-pge-invitation-credit-ledger.php`, `class-pge-replacement-entitlements.php`, `class-cartat-handler.php`, `class-salla-handler.php`, `class-mon-events-users.php`, `event-factory.php`, أي ملف Legacy (`admin-mods.php`, `class-pge-packages.php`).

**Preconditions**
لا يوجد — هذه أول خطوة تنفيذية، لا تعتمد على أي مرحلة أخرى.

**خطوات التنفيذ**
1. نقل جدول §6 (19 صفاً) حرفياً إلى بنية بيانات ثابتة داخل الملف الجديد.
2. تطبيق مبدأ Registry Independence: لا حقل في هذه البنية يشير لأي `plan_key`/`tier_id` محدَّد.
3. توفير طريقة وصول واحدة موحَّدة (Provider) بحيث لا يقرأ أي كود لاحق البنية الثابتة مباشرة.
4. رفض صريح (قيمة `null`/استثناء متحكَّم به) لأي `feature_key` غير موجود عند الاستعلام عنه — لا تخمين.

**الاختبارات المطلوبة**
- كل ميزة من الـ19 تُقرأ بكامل حقولها التسعة ومطابقة لـ§6 حرفياً.
- استعلام عن `feature_key` غير موجود يُعيد نتيجة "غير موجود" صريحة، لا `null` صامت يُفسَّر خطأً كقيمة صالحة.
- عدد الميزات المُعادة من `all()` (أو المكافئ) = 19 بالضبط.

**حالات الفشل المحتملة**
- نسخ خاطئ لقيمة `lifecycle`/`type` عن §6 (خطأ نسخ يدوي بحت، لا خطأ منطقي).
- إضافة حقل عاشر غير موجود في التعريف المعماري (تجاوز غير مصرَّح به لبنية §6).

**Rollback**
حذف الملف الجديد + سطر الـ`require_once` — لا أثر على أي نظام آخر (طبقة معزولة بالكامل، بلا جدول أو Snapshot متأثر في هذه المرحلة).

**Definition of Done**
الملف موجود، يحمّل بلا أخطاء PHP، ويُعيد الـ19 ميزة مطابقة لـ§6 حرفياً؛ لا استهلاك له من أي صفحة مستخدم بعد.

**Acceptance Criteria**
- [ ] 19 ميزة مُعرَّفة، لا أكثر ولا أقل.
- [ ] كل ميزة تحمل الحقول التسعة كاملة.
- [ ] لا إشارة لأي Plan/Tier محدَّد داخل التعريف.
- [ ] الوصول يتم حصراً عبر واجهة واحدة (Provider) لا عبر البنية الخام مباشرة من أي كود مستهلِك خارجي (تحقُّق يدوي/مراجعة كود، لا اختبار آلي بالضرورة).

---

### Phase 2 — Tier Features (التخزين)

**الهدف**
إنشاء جدول `mon_tier_features` بالتصميم المعتمد نهائياً في §5، وطبقة وصول (Repository) بسيطة للقراءة/الكتابة الخام (بلا أي تفسير نوع — التفسير مهمة Resolver في Phase 3).

**الملفات المتوقع تعديلها**
- `includes/class-mon-catalog-schema.php` — إضافة `CREATE TABLE mon_tier_features` إلى `get_schema_sql()`، ورفع `DB_VERSION` (بنفس نمط الترقيات السابقة الموثَّقة في هذا الملف: 1.5.0 → 1.6.0 → 1.7.0)، مع Migration/Upgrade Routine بنفس الأسلوب المتبع للجداول السابقة (Ledger، Replacement Entitlements).
- `pgevents-core.php` — `require_once` لملف الـRepository الجديد.

**الملفات الجديدة**
- `includes/class-pge-tier-features.php` — Repository بسيط: قراءة كل ميزات Tier، قراءة قيمة ميزة واحدة، كتابة/تحديث قيمة (Upsert وفق `UNIQUE (tier_id, feature_key)`)، حذف. **بلا أي تفسير نوع** — يتعامل مع `feature_value` كنص خام دائماً (`LONGTEXT`، كما في §5).

**الملفات التي يُمنع تعديلها**
`mon_plans`/`mon_plan_tiers` (لا `ALTER TABLE` عليهما)، `PGE_Catalog::create_tier()`/`update_tier()` (لا تعديل)، وكل الملفات المذكورة في قسم "يُمنع تعديلها" لـPhase 1.

**Preconditions**
Phase 1 مكتملة (Registry Provider موجود — الـRepository هنا لا يعتمد عليه مباشرة، لكن ترتيب الطبقات في §"Dependency Direction" يقتضي وجود Registry أولاً في التسلسل العام).

**خطوات التنفيذ**
1. إضافة تعريف الجدول حرفياً كما في §5 (`id`, `tier_id`, `feature_key`, `feature_value LONGTEXT`, `created_at`, `updated_at`, `UNIQUE (tier_id, feature_key)`).
2. ترقية `DB_VERSION` + Upgrade Routine (نفس نمط `sync_schema()` الحالي).
3. بناء Repository بعمليات: `get_tier_feature_value($tier_id, $feature_key)`, `get_all_tier_features($tier_id)`, `set_tier_feature_value($tier_id, $feature_key, $raw_value)` (Upsert)، `delete_tier_feature($tier_id, $feature_key)`.
4. **لا فحص نوع داخل الـRepository** — أي قيمة نصية تُقبَل وتُخزَّن كما هي؛ التحقق من الصحة وفق `validation` في Registry هو مسؤولية الطبقة التي تستدعي الكتابة (خارج نطاق هذه المرحلة إن لم تُربَط بلوحة إدارة بعد — Phase 5).

**الاختبارات المطلوبة**
- إنشاء الجدول عبر Migration وهمية (Fake `$wpdb`، بنفس نمط `tests/test-invitation-credit-ledger.php`).
- Upsert لنفس `(tier_id, feature_key)` مرتين يُحدِّث الصف الموجود، لا يُنشئ تكراراً (يحترم `UNIQUE`).
- قراءة ميزة غير موجودة لـTier معيَّن تُعيد نتيجة "غير موجود" صريحة، لا خطأ فادح (Fatal).
- قراءة كل ميزات Tier تُعيد فقط الصفوف الخاصة به (عزل بين Tiers مختلفة).

**حالات الفشل المحتملة**
- تعارض `DB_VERSION` مع ترقية Schema أخرى تُدمَج بالتوازي (خطر تشغيلي، لا معماري — راجع جدول المخاطر أدناه).
- نسيان الفهرس الفريد `UNIQUE (tier_id, feature_key)` عند كتابة SQL يدوياً، مما يسمح بتكرار صف لنفس المفتاح.

**Rollback**
Migration عكسية تُسقِط الجدول (`DROP TABLE mon_tier_features`) + إعادة `DB_VERSION` للقيمة السابقة + حذف ملف الـRepository وسطر الـ`require_once`. لا أثر على أي جدول آخر (بلا `FOREIGN KEY` فعلية، بنفس نمط بقية جداول المشروع).

**Definition of Done**
الجدول موجود وفق التصميم الحرفي في §5؛ الـRepository يقرأ ويكتب بلا أي تفسير نوع؛ لا بيانات فعلية مُدخَلة بعد (لا واجهة إدخال حتى Phase 5).

**Acceptance Criteria**
- [ ] بنية الجدول مطابقة حرفياً لـ§5 (لا عمود `value_type`، `feature_value` من نوع `LONGTEXT`).
- [ ] `UNIQUE (tier_id, feature_key)` مُفعَّل ومُختبَر.
- [ ] الـRepository لا يحتوي أي منطق تفسير نوع (مراجعة كود).
- [ ] صفر تعديل على `mon_plans`/`mon_plan_tiers`.

---

### Phase 3 — Resolver

**الهدف**
بناء العقد الثلاثي المقترح في §7 (`pge_user_has_feature`, `pge_get_user_feature_value`, `pge_get_user_package_features`)، بترتيب القراءة الإلزامي: Database → Registry (عبر Registry Provider من Phase 1) → Resolver → UI، وبترتيب مصادر البيانات: Catalog Snapshot → Catalog Tier (Fallback دفاعي) → Legacy → Default آمن من Registry.

**الملفات المتوقع تعديلها**
لا يوجد — هذه المرحلة تنشئ ملفاً جديداً فقط، ولا تُعدِّل أي استهلاك حالٍ بعد (الربط الفعلي بـ`/create-event/` يبدأ في Phase 6).
- `pgevents-core.php` — `require_once` للملف الجديد فقط.

**الملفات الجديدة**
- `includes/feature-resolver.php` — يحوي الدوال الثلاث المذكورة في §7 حرفياً (أسماء وتوقيعات مطابقة)، مبنية على: Phase 1 (Registry Provider) + Phase 2 (Tier Features Repository) + Phase 4 (Snapshot — انظر ملاحظة الترتيب أدناه).

**ملاحظة على الترتيب**: الـResolver يقرأ من Snapshot (§7: "Catalog Snapshot أولاً") الذي يُبنى في Phase 4 — لذا اكتمال منطق الـResolver الفعلي (لا مجرد هيكله) يتطلب وجود Phase 4 أيضاً؛ لهذا تُدرَجان هنا كمرحلتين متتاليتين وثيقتَي الترابط (راجع جدول Dependencies أدناه) بدل مرحلة واحدة، للحفاظ على Commit صغير ومركَّز لكل منهما.

**الملفات التي يُمنع تعديلها**
`event-factory.php` (لا تعديل على `pge_get_user_plan_limits_for_events()` — الرصيد يبقى خارج هذه الطبقة كلياً وفق §9/§14 من الوثيقة المعمارية)، وكل ملفات Legacy/Credits/Ledger/Replacement/Cartat/Salla المذكورة سابقاً.

**Preconditions**
Phase 1 وPhase 2 مكتملتان.

**خطوات التنفيذ**
1. تعريف `pge_user_has_feature($user_id, $feature_key): bool`.
2. تعريف `pge_get_user_feature_value($user_id, $feature_key, $default = null)`.
3. تعريف `pge_get_user_package_features($user_id): array`.
4. تطبيق ترتيب المصادر حرفياً كما في §7 (سطر "ترتيب مصادر القراءة").
5. تطبيق تفسير الأنواع الثلاثة (`boolean`/`integer`/`percentage`) حرفياً كما في §7 ("كيف تُقرأ الأنواع") — عبر سؤال Registry Provider عن النوع أولاً، لا تخمين.
6. رفض أي `feature_key` غير موجود في Registry (Default آمن فوري، بلا استعلام قاعدة بيانات).

**الاختبارات المطلوبة**
- مستخدم Catalog بميزة `boolean = true` في Snapshot وهمي: `pge_user_has_feature()` تُعيد `true`.
- مستخدم بلا Snapshot لكن بـTier حيّ (Fallback): يُقرأ من الـTier، ويُسجَّل كحالة انتقالية (وفق §7).
- مستخدم Legacy (`_mon_package_source !== 'catalog'`): لا قراءة إطلاقاً من `mon_tier_features` أو Registry Snapshot الجديد — فقط `PGE_Packages`/`_mon_active_features`.
- استعلام عن `feature_key` غير معرَّف في Registry: يُعيد Default آمن فوراً، بلا استثناء غير متحكَّم به.
- قراءة نوع `integer` تُعيد `(int)` صريحاً؛ نوع `percentage` يُعيد عدداً 0-100.

**حالات الفشل المحتملة**
- كسر الفصل الصارم بين Legacy وCatalog سهواً (قراءة مصدر خاطئ لمستخدم من المصدر الآخر) — الخطر المعماري الأول المذكور في §13.
- الاعتماد على Fallback (Tier الحيّ) كمصدر دائم بدل حالة انتقالية موثَّقة فقط (مخالفة صريحة لـ§7).

**Rollback**
حذف الملف الجديد وسطر الـ`require_once` — لا استهلاك خارجي بعد في هذه المرحلة، فالإزالة آمنة بالكامل.

**Definition of Done**
الدوال الثلاث تعمل وفق العقد والترتيب في §7 بالضبط، مُختبَرة لكل مصدر (Catalog Snapshot/Fallback/Legacy/Default) ولكل نوع (boolean/integer/percentage).

**Acceptance Criteria**
- [ ] توقيعات الدوال الثلاث مطابقة حرفياً لـ§7.
- [ ] ترتيب المصادر مطابق حرفياً (Snapshot→Tier Fallback→Legacy→Default).
- [ ] تفسير النوع يمر دائماً عبر Registry Provider (Phase 1)، لا منطق تخمين محلي.
- [ ] Legacy وCatalog معزولان تماماً (لا تسرّب بيانات بين المسارين).

---

### Phase 4 — Snapshot

**الهدف**
بناء منطق كتابة `_mon_package_features` و`_mon_package_feature_version` عند تفعيل/تجديد/تبديل Tier، وفق §9 حرفياً (بلا `type` أو Metadata، رقم إصدار صحيح لا Timestamp).

**الملفات المتوقع تعديلها**
- `includes/class-mon-events-users.php` — إضافة منطق كتابة Snapshot الميزات داخل `activate_catalog_tier()` (إلى جانب منطق Snapshot الرصيد الحالي القائم فعلاً في نفس الدالة، بلا تعديل عليه — إضافة فقط).

**الملفات الجديدة**
لا يوجد (المنطق الجديد يُضاف داخل الدالة الحالية مباشرة، أو عبر دالة مساعدة خاصة داخل نفس الملف — القرار بين الخيارين تفصيل تنفيذي بحت لا يغيّر أي عقد معماري).

**الملفات التي يُمنع تعديلها**
منطق Snapshot الرصيد الحالي داخل نفس الدالة (`_mon_invitation_credit_total/used`, `_mon_replacement_credit_total/used`, `credit_cycle_id`) — **يُمنع لمسه بأي شكل**؛ هذا هو التطبيق الحرفي لقسم §14 "Not Part of Feature System".

**Preconditions**
Phase 1، Phase 2، وPhase 3 مكتملة (Snapshot يُبنى من قراءة `mon_tier_features` الحيّة للـTier المفعَّل وقت الاستدعاء، عبر نفس آلية القراءة التي يستخدمها Resolver لضمان الاتساق).

**خطوات التنفيذ**
1. عند تفعيل Tier: قراءة كل صفوف `mon_tier_features` الخاصة بالـTier (عبر Repository من Phase 2).
2. بناء مصفوفة `feature_key => feature_value` (بعد تفسير النوع وفق Registry — لتخزين قيمة نهائية جاهزة، لا نص خام، تماماً كما يوضح المثال في §9).
3. كتابة `_mon_package_features` بهذه المصفوفة.
4. قراءة `_mon_package_feature_version` الحالي (إن وُجد)، وزيادته بمقدار 1؛ أو تعيينه إلى `1` إن كان أول تفعيل.
5. **لا لمس لأي مفتاح Snapshot خاص بالرصيد** — الإضافة معزولة بالكامل ضمن نفس استدعاء الدالة.

**الاختبارات المطلوبة**
- أول تفعيل لمستخدم: `_mon_package_feature_version = 1`.
- إعادة تفعيل (تجديد/تبديل Tier) لنفس المستخدم: يزداد الإصدار بمقدار 1 بالضبط، وتُعاد كتابة `_mon_package_features` بالكامل من حالة الـTier الحيّة الجديدة (لا ترحيل قيم قديمة).
- تعديل إداري لميزات Tier بعد تفعيل مستخدم: لا يتغيّر Snapshot ذلك المستخدم إطلاقاً (تحقُّق مباشر من ثبات §9).
- Snapshot الرصيد (`_mon_invitation_credit_total` وغيره) يبقى مطابقاً تماماً لسلوكه قبل هذا التعديل — اختبار انحدار صريح.

**حالات الفشل المحتملة**
- استخدام Timestamp بدل رقم صحيح لـ`_mon_package_feature_version` سهواً (مخالفة صريحة لـ§9).
- تسرّب منطق كتابة الميزات إلى مسار الرصيد أو العكس (خرق للفصل المطلوب في §14).

**Rollback**
إزالة الإضافة من `activate_catalog_tier()` فقط (Diff صغير معزول). أي `_mon_package_features`/`_mon_package_feature_version` مكتوبة مسبقاً في قاعدة بيانات تشغيلية تبقى بيانات يتيمة غير مقروءة من أي كود بعد التراجع — لا خطر بيانات حرجة (لا استهلاك تشغيلي لها بعد هذه المرحلة وحدها).

**Definition of Done**
Snapshot جديد يُكتَب صحيحاً عند كل تفعيل/تجديد، بمعزل تام عن Snapshot الرصيد الحالي، ومطابق حرفياً لبنية المثال في §9.

**Acceptance Criteria**
- [ ] `_mon_package_features` = أزواج `key => value` فقط، بلا `type`/Metadata.
- [ ] `_mon_package_feature_version` عدد صحيح متزايد، لا Timestamp.
- [ ] صفر تغيير في سلوك Snapshot الرصيد الحالي (اختبار انحدار يمر).
- [ ] تعديل Tier لاحقاً لا يُغيّر Snapshot مستخدم مُفعَّل مسبقاً.

---

### Phase 5 — Admin Integration

**الهدف**
واجهة إدارة تسمح بإدخال قيم الميزات لكل Tier، **مولَّدة تلقائياً من Feature Registry** — لا Textarea أو Text Input حر لاسم المفتاح (§8).

**الملفات المتوقع تعديلها**
`Blocked` — الوثيقة المعمارية تنص أن هذه الواجهة "امتداد مستقبلي لصفحة `pge-catalog-tiers` الحالية، **خارج نطاق التنفيذ في هذه الوثيقة**" (§8)، ولا تحدد: هل تُبنى كقسم إضافي داخل `pgevents-core.php` (حيث تعيش `pge_render_catalog_tiers_page()` حالياً)، أم كصفحة/قائمة فرعية جديدة تماماً. **القرار بين الخيارين غير موجود في المرجع المعماري — `Blocked` حتى صدور قرار.**

**الملفات الجديدة**
`Blocked` (نفس السبب أعلاه).

**الملفات التي يُمنع تعديلها**
`PGE_Catalog::create_tier()`/`update_tier()` (النموذج الحالي للحقول الثمانية+ يبقى كما هو تماماً — هذه الواجهة الجديدة تتعامل حصراً مع `mon_tier_features` عبر Repository من Phase 2، لا مع `mon_plan_tiers`).

**Preconditions**
Phase 1، Phase 2، Phase 3 مكتملة (الواجهة تكتب عبر Repository، وتحتاج Registry لتوليد الحقول ونوع كل إدخال).

**خطوات التنفيذ**
1. `Blocked` — بانتظار قرار حول موضع الواجهة (سطر ضمن `pge-catalog-tiers` أو صفحة مستقلة).
2. توليد قائمة الحقول من Registry (Checkbox لـ`boolean`، حقل رقم لـ`integer`/`percentage`) — مبدأ محسوم في §8، آلية التوليد الدقيقة تفصيل تنفيذي يُبنى وقت الكتابة الفعلية.
3. تطبيق `validation` من Registry قبل أي كتابة عبر Repository (Phase 2).
4. نونس + `manage_options` لكل عملية كتابة، بنفس نمط الأمان المؤكَّد فعلياً في صفحات `pge-catalog-plans`/`pge-catalog-tiers` الحالية.

**الاختبارات المطلوبة**
- محاولة إرسال `feature_key` غير موجود في Registry تُرفَض من الخادم (لا تصل إلى Repository إطلاقاً) — تحقُّق مباشر من §8.
- قيمة تخالف `validation` المعرَّف (مثال: نص لحقل `integer`) تُرفَض مع رسالة واضحة.
- الحفظ يستدعي `PGE_Catalog::create_tier()`/`update_tier()`؟ **لا** — تحقُّق أن هذه الدوال لم تُلمَس ولم تُستدعَ من مسار حفظ الميزات الجديد.

**حالات الفشل المحتملة**
- السماح (سهواً) بإدخال حر لاسم مفتاح غير موجود في Registry — أخطر حالة فشل ممكنة هنا، لأنها تُعيد الثغرة الأصلية التي صُمِّم هذا النظام كله لمنعها (§0، §8).

**Rollback**
`Blocked` (يعتمد على القرار في الخطوة 1).

**Definition of Done**
مسؤول `manage_options` يستطيع فتح Tier وإدخال/تعديل قيمة كل ميزة معرَّفة في Registry فقط، بحقل إدخال يطابق نوعها، دون أي إمكانية لكتابة مفتاح حر.

**Acceptance Criteria**
- [ ] لا Textarea ولا Text Input حر لاسم Feature Key في أي مكان بالواجهة.
- [ ] كل حقل معروض مصدره Registry حصراً.
- [ ] القيم المحفوظة تمر عبر Repository من Phase 2 فقط.
- [ ] `PGE_Catalog::create_tier()`/`update_tier()` غير مُستدعَيان من هذا المسار.

---

### Phase 6 — Create Event Integration

**الهدف**
الربط الفعلي الأول بـ`/create-event/`، محصور تماماً في البند المحسوم تجارياً وحده وفق §11/§12 (Phase 2 هناك): نقل `google_maps` من الآلية الحالية الهشّة إلى الـResolver الجديد، وبطاقة الميزات المفعَّلة.

**الملفات المتوقع تعديلها**
- `wp-content/themes/pgevents-pro/page-create-event.php` — استبدال استدعاء `$feature_enabled($plan_limits, 'google_map')` (السطر الحالي، منطق `pge_plan_feature_enabled_for_events()`) بـ `pge_user_has_feature($user_id, 'google_maps')` **لهذا الحقل فقط**؛ وبناء قائمة "الميزات المفعَّلة" (`$active_badges` الحالية) لتضم أيضاً مخرجات `pge_get_user_package_features($user_id)` الجديدة إلى جانب الشارات الحالية (لا استبدال للشارات القائمة — إضافة فقط).

**الملفات الجديدة**
لا يوجد.

**الملفات التي يُمنع تعديلها**
كل بقية `page-create-event.php` (حد الدعوات يبقى عبر `pge_get_user_plan_limits_for_events()['invitation_credit_total']` بلا أي تغيير — §11 صريحة في هذا)، `event-factory.php` (`pge_handle_event_creation()` ومنطق الحصة فيها لا يُلمَس)، وكل حقل آخر في هذه الصفحة (المضيف الإضافي، المشرف، صورة الدعوة المخصَّصة) **`Blocked`** لأنها مرهونة بقرارات TBD في §10 ولا تدخل ضمن نطاق هذه المرحلة أصلاً (راجع "Out of Scope").

**Preconditions**
Phase 1 حتى Phase 4 مكتملة (يحتاج Resolver عاملاً بالكامل، وSnapshot يحتوي فعلياً `google_maps` لمستخدمين حقيقيين — أي Phase 5 [إدخال القيمة إدارياً] يجب أن تكون منجَزة عملياً لتوفير بيانات حقيقية، حتى لو لم تكتمل واجهتها الإدارية بالكامل بعد وفق حالة `Blocked` في Phase 5).

**خطوات التنفيذ**
1. استبدال مصدر قراءة `google_maps` في `page-create-event.php` بالـResolver الجديد فقط — بقية الصفحة بلا تغيير حرفي.
2. إضافة استدعاء `pge_get_user_package_features($user_id)` لعرض شارات إضافية في قائمة "الميزات المفعَّلة" الحالية (`$active_badges`)، بلا حذف أي شارة موجودة اليوم (`google_map`, `header_img`, `public_chat`, `private_chat`, `guest_photos`, `guest_video`, `wa_limit`).
3. لا تغيير على أي حقل Disabled/Hidden آخر في النموذج.

**الاختبارات المطلوبة**
- مستخدم Catalog بـ`google_maps = true` في Snapshot: حقل الخريطة يظهر مفعَّلاً بالضبط كما اليوم (لا Regression بصري أو وظيفي).
- مستخدم Legacy بـ`google_map` مفعَّلة: يستمر العمل بلا تغيير (المسار القديم لبقية الميزات لم يُلمَس).
- مستخدم بلا `google_maps` في Snapshot إطلاقاً (حالة انتقالية قبل اكتمال Phase 5 الفعلي): يقع في Fallback (Tier الحيّ) ثم Default آمن من Registry (`true` بحسب §6) — يجب أن يتصرف مطابقاً للقيمة الافتراضية المعتمدة تجارياً في §3.

**حالات الفشل المحتملة**
- Regression على أي حقل آخر في `/create-event/` بسبب تعديل غير محكوم النطاق (الخطر الأهم هنا تحديداً — الصفحة حساسة ومُختبَرة سابقاً بعناية في تصحيحات سابقة لهذا المشروع).

**Rollback**
إعادة استدعاء `pge_plan_feature_enabled_for_events($plan_limits, 'google_map')` القديم مكان استدعاء الـResolver الجديد — Diff من سطر واحد إلى بضعة أسطر، معزول تماماً.

**Definition of Done**
`/create-event/` تعمل بالضبط كما تعمل اليوم لكل مستخدمي Legacy وCatalog الحاليين (Zero Regression مؤكَّد باختبار)، وGoogle Maps يُقرأ من المصدر الموحَّد الجديد.

**Acceptance Criteria**
- [ ] لا تغيير وظيفي ظاهر لأي مستخدم حالي (Legacy أو Catalog) باستثناء مصدر بيانات `google_maps` نفسه.
- [ ] حد الدعوات لم يُلمَس إطلاقاً (لا يزال عبر `pge_get_user_plan_limits_for_events()`).
- [ ] لا حقل آخر في الصفحة تأثَّر.

---

### Phase 7 — Testing

**الهدف**
تغطية شاملة عبر كل المراحل (1-6) قبل أي Merge، بما يشمل اختبارات الانحدار على الأنظمة المستقرة (§0/§14) للتأكد من عدم مسّها.

**الملفات المتوقع تعديلها**
لا يوجد على كود الإنتاج — هذه المرحلة اختبارات فقط.

**الملفات الجديدة**
- `tests/test-feature-registry.php`
- `tests/test-tier-features.php`
- `tests/test-feature-resolver.php`
- `tests/test-feature-snapshot.php`
- `tests/test-create-event-google-maps-migration.php` (أو دمجها ضمن ملف اختبار `/create-event/` قائم إن وُجد لاحقاً بهذا الاسم تحديداً — `Blocked` حتى التأكد من التسمية الفعلية وقت التنفيذ)

**الملفات التي يُمنع تعديلها**
أي ملف اختبار موجود مسبقاً في `tests/` يخص الرصيد/الـLedger/الـReplacement Entitlements — تُقرأ فقط للتأكد أنها ما زالت تمر (Regression)، لا تُعدَّل.

**Preconditions**
Phase 1 حتى Phase 6 مكتملة.

**خطوات التنفيذ**
1. تشغيل (أو تتبّع AST + يدوي، حسب توفر بيئة PHP CLI فعلية — قيد بيئي موثَّق سابقاً في هذا المشروع) لكل ملفات الاختبار الجديدة.
2. إعادة تشغيل/تتبّع ملفات اختبار الرصيد والـLedger والـReplacement Entitlements القائمة، للتأكد من Zero Regression.
3. اختبار يدوي (Manual) على بيئة حقيقية: مستخدم Catalog حقيقي بميزات مُدخَلة فعلياً عبر Phase 5، عرض `/create-event/`، التأكد من Google Maps.
4. Smoke Test نهائي قبل الدمج (راجع Testing Matrix أدناه).

**الاختبارات المطلوبة**
تفصيل كامل في "Testing Matrix" أدناه.

**حالات الفشل المحتملة**
- اكتشاف Regression متأخر في نظام الرصيد بسبب تداخل غير مقصود مع Snapshot الميزات الجديد (Phase 4) — أهم فحص انحدار في كامل الخطة.

**Rollback**
لا ينطبق (مرحلة اختبار، لا تعديل إنتاج).

**Definition of Done**
كل الاختبارات الجديدة تمر؛ كل اختبارات الانحدار القائمة تستمر بالمرور دون أي تعديل عليها.

**Acceptance Criteria**
- [ ] Testing Matrix (أدناه) مكتملة بالكامل.
- [ ] صفر فشل في أي اختبار انحدار قائم.
- [ ] Smoke Test النهائي ناجح على بيئة تشبه الإنتاج.

---

## 3. Dependencies

| المرحلة | تعتمد على |
|---|---|
| Feature Registry (Phase 1) | لا شيء (نقطة البداية) |
| Tier Features (Phase 2) | Feature Registry |
| Resolver (Phase 3) | Feature Registry, Tier Features |
| Snapshot (Phase 4) | Feature Registry, Tier Features, Resolver |
| Admin Integration (Phase 5) | Feature Registry, Tier Features |
| Create Event Integration (Phase 6) | Resolver, Snapshot, Admin Integration (لتوفير بيانات حقيقية) |
| Testing (Phase 7) | كل المراحل 1-6 |

---

## 4. المخاطر (مخاطر التنفيذ فقط)

> لا تكرار للمخاطر المعمارية الموثَّقة في `PACKAGE-FEATURE-MATRIX.md §13` — هذه مخاطر تنفيذية بحتة (تسلسل العمل، الأدوات، البيئة).

| الخطر | الوصف | التخفيف المقترح |
|---|---|---|
| تعارض ترقية `DB_VERSION` | إن جرى تطوير Schema آخر بالتوازي مع Phase 2، قد يتعارض رقم الإصدار الجديد | التأكد من آخر `DB_VERSION` مباشرة قبل بدء Phase 2، وتنسيق مع أي عمل Schema آخر جارٍ |
| غياب PHP CLI حقيقي في بيئة التطوير | قيد بيئي مؤكَّد ومتكرر في هذا المشروع — التحقق من الاختبارات الجديدة قد يقتصر على AST Parsing + تتبّع يدوي بدل تشغيل فعلي | تشغيل فعلي إلزامي على بيئة السيرفر الحقيقية قبل اعتماد أي Phase كمكتملة نهائياً |
| Regression صامت في صفحة `/create-event/` | الصفحة حساسة (نقطة فشل حرجة تُحبِط إنشاء مناسبات) وسبق إصلاحها عدة مرات في تاريخ هذا المشروع | Phase 6 محصورة عمداً في حقل واحد فقط (`google_maps`) + اختبار انحدار كامل قبل الدمج |
| توسّع نطاق Phase 5 (Admin Integration) بسبب غياب قرار التموضع | حالة `Blocked` قد تُغري بأخذ قرار عشوائي وقت التنفيذ لتفادي التأخير | الرجوع الصريح لصاحب القرار قبل أي سطر كود في Phase 5 — لا قرار افتراضي |
| كتابة Snapshot الميزات بمنطق يتقاطع مع كود Snapshot الرصيد في نفس الدالة (`activate_catalog_tier()`) | كلا الإضافتين تعيشان في نفس الدالة فعلياً — خطر لصق غير دقيق يُدخل تغييراً غير مقصود على منطق الرصيد | Diff صغير ومعزول بصرياً + اختبار انحدار صريح على مخرجات الرصيد قبل وبعد (Phase 4) |
| نسيان تحديث Feature Registry عند أي تعديل مستقبلي على قائمة الميزات | يخالف القاعدة المعمارية في §8 بصمت | فرض "Feature Registry أولاً" كخطوة أولى إلزامية في أي عمل مستقبلي، Code Review يتحقق من ذلك صراحة |

---

## 5. Files Expected To Change

| اسم الملف | سبب التعديل | نوع التعديل |
|---|---|---|
| `includes/class-pge-feature-registry.php` | Feature Registry + Registry Provider (Phase 1) | New |
| `includes/class-pge-tier-features.php` | Repository لجدول `mon_tier_features` (Phase 2) | New |
| `includes/feature-resolver.php` | الدوال الثلاث للـResolver (Phase 3) | New |
| `includes/class-mon-catalog-schema.php` | إضافة `CREATE TABLE mon_tier_features` + ترقية `DB_VERSION` (Phase 2) | Modify |
| `pgevents-core.php` | `require_once` للملفات الجديدة الثلاثة (Phases 1-3) | Modify |
| `includes/class-mon-events-users.php` | إضافة كتابة `_mon_package_features`/`_mon_package_feature_version` داخل `activate_catalog_tier()` (Phase 4) | Modify |
| صفحة/قسم إدارة ميزات الـTier | واجهة مولَّدة من Registry (Phase 5) | TBD (`Blocked` — راجع Phase 5) |
| `wp-content/themes/pgevents-pro/page-create-event.php` | نقل `google_maps` للـResolver الجديد + بطاقة الميزات (Phase 6) | Modify |
| `mon_plans`, `mon_plan_tiers` | — | No Change |
| `class-pge-catalog.php` | — | No Change |
| `class-pge-invitation-credit-ledger.php`, `class-pge-replacement-entitlements.php` | — | No Change |
| `class-cartat-handler.php`, `class-salla-handler.php` | — | No Change |
| `event-factory.php` (منطق حد الدعوات/الحصة) | — | No Change |
| `admin-mods.php` (Legacy) | — | No Change |
| `tests/test-feature-registry.php`, `tests/test-tier-features.php`, `tests/test-feature-resolver.php`, `tests/test-feature-snapshot.php` | اختبارات المراحل 1-4 (Phase 7) | New |

---

## 6. Testing Matrix

| النوع | النطاق | أمثلة |
|---|---|---|
| **Unit Tests** | Feature Registry، Tier Features Repository، Resolver (كل دالة بمعزل) | قراءة تعريف ميزة واحدة؛ Upsert لصف واحد؛ تفسير `boolean`/`integer`/`percentage` بمعزل عن أي مصدر بيانات حقيقي |
| **Integration Tests** | تفاعل الطبقات معاً (Registry↔Repository↔Resolver↔Snapshot) | مستخدم Catalog وهمي كامل: تفعيل → Snapshot → قراءة عبر Resolver → قيمة نهائية صحيحة |
| **Regression Tests** | التأكد من صفر أثر على الأنظمة المستقرة | اختبارات الرصيد/الـLedger/الـReplacement Entitlements القائمة تستمر بالمرور؛ `/create-event/` تعمل لمستخدم Legacy كما اليوم تماماً |
| **Manual Tests** | سيناريوهات حقيقية على بيئة تشبه الإنتاج | مسؤول يُدخل قيمة ميزة عبر Phase 5، مستخدم حقيقي يرى الأثر في `/create-event/` |
| **Smoke Tests** | فحص سريع قبل الدمج للتأكد أن لا شيء "مكسور بشكل صارخ" | تحميل `pgevents-core.php` بلا أخطاء PHP فادحة؛ فتح `/create-event/` و`/dashboard/` بلا Fatal Error لكل من مستخدمي Legacy وCatalog |

---

## 7. Implementation Order (من أول Commit إلى آخر Commit)

1. **Commit 1** — `includes/class-pge-feature-registry.php` (Phase 1) + `require_once` في `pgevents-core.php`.
2. **Commit 2** — `tests/test-feature-registry.php` (جزء من Phase 7، يُكتَب فور اكتمال Phase 1 لا في نهاية الخطة).
3. **Commit 3** — تعديل `class-mon-catalog-schema.php` (جدول `mon_tier_features` + ترقية `DB_VERSION`) (Phase 2).
4. **Commit 4** — `includes/class-pge-tier-features.php` (Phase 2) + `require_once`.
5. **Commit 5** — `tests/test-tier-features.php`.
6. **Commit 6** — `includes/feature-resolver.php` (Phase 3) + `require_once`.
7. **Commit 7** — `tests/test-feature-resolver.php`.
8. **Commit 8** — تعديل `activate_catalog_tier()` في `class-mon-events-users.php` (Phase 4).
9. **Commit 9** — `tests/test-feature-snapshot.php` + إعادة تشغيل/تتبّع اختبارات الرصيد الحالية (تأكيد Zero Regression).
10. **Commit 10** — Admin Integration (Phase 5) — **`Blocked` حتى صدور قرار التموضع**؛ يتوقف تسلسل الـCommits هنا فعلياً حتى ورود القرار.
11. **Commit 11** — تعديل `page-create-event.php` لنقل `google_maps` + بطاقة الميزات (Phase 6) — يتطلب اكتمال Commit 10 لتوفير بيانات حقيقية للاختبار اليدوي.
12. **Commit 12** — اختبار انحدار شامل نهائي + Smoke Test (Phase 7) قبل فتح Pull Request للدمج.

---

## 8. Out of Scope

كل ما يلي **لن يُنفَّذ في نطاق هذه الوثيقة (المرحلة الأولى)**، وفق §12 (المراحل 3-5) و§10 من الوثيقة المعمارية:

- إنشاء كيان "مضيف إضافي" أو "مشرف دخول" فعلي (`host_limit`, `admin_supervisor_limit`) — يتطلب قراراً معمارياً منفصلاً يمس `pge_is_host_or_admin()` نفسها (§12 Phase 4)، وقيمه الرقمية معلَّقة أصلاً (§10، بند 3 و4).
- قوالب الرسائل الجديدة (`reminder_message`, `thank_you_message`) وآلية الجدولة الزمنية اللازمة لها (§12 Phase 3).
- ربط `decline_message` الحالي (قالب `no`) ببوابة Feature فعلية — يبقى متاحاً للجميع بلا قيد كما هو اليوم (§12 Phase 3).
- حقول الصور المخصَّصة الثلاثة (`custom_invitation_image`, `custom_reminder_image`, `custom_thank_you_image`) — معلَّقة لحين حسم §10 بند 1 و2.
- `invitation_design_limit` — المعنى نفسه غير محسوم (§10 بند 2)، فلا تنفيذ ممكن أصلاً.
- ميزات "حلوة بلس" الاجتماعية والتجارية (`guest_comments`, `event_photo_album`, `gift_feature` كتحكّم من العميل، `support_services_discount_percentage`) — §12 Phase 5، معلَّقة لحين حسم §10 بند 5 و6 و7.
- أي تعديل على `mon_plans`/`mon_plan_tiers`/`PGE_Catalog::create_tier()`/`update_tier()`.
- أي تعديل على Ledger أو Replacement Entitlements أو Cartat أو Salla — هذه الأنظمة خارج نطاق طبقة Features بالكامل (§14 من الوثيقة المعمارية).
- أي حقل آخر في `/create-event/` غير `google_maps` وبطاقة الميزات المفعَّلة (حد الدعوات، صورة المناسبة، المضيف، المشرف تبقى كما هي — §11).
- أي واجهة لغير `manage_options` (لا واجهة عميل نهائي لعرض/تعديل ميزاته بنفسه في هذه المرحلة).

---

## 9. Completion Checklist

- [ ] Feature Registry يحتوي 19 ميزة مطابقة حرفياً لـ`PACKAGE-FEATURE-MATRIX.md §6`.
- [ ] لا حقل عاشر أو تاسع مفقود في أي تعريف ميزة.
- [ ] جدول `mon_tier_features` مطابق حرفياً لـ§5 (4 أعمدة بيانات، `LONGTEXT`، `UNIQUE (tier_id, feature_key)`، بلا `value_type`).
- [ ] `DB_VERSION` مُرقَّى، وMigration تعمل على قاعدة بيانات موجودة مسبقاً بلا فقدان بيانات.
- [ ] الدوال الثلاث للـResolver موجودة بنفس التوقيعات والترتيب المذكور في §7.
- [ ] Legacy وCatalog معزولان تماماً في كل مسار قراءة جديد (لا تسرّب بيانات بين المصدرين).
- [ ] `_mon_package_features` و`_mon_package_feature_version` يُكتَبان وفق §9 حرفياً (بلا `type`/Metadata، رقم إصدار لا Timestamp).
- [ ] صفر تعديل على `invitation_credit_limit`, `replacement_credit_limit`, `invitation_credit_used`, `replacement_credit_used`, `credit_cycle_id`, Ledger, Replacement Entitlements (§14).
- [ ] صفر تعديل على `mon_plans`, `mon_plan_tiers`, `PGE_Catalog::create_tier()`/`update_tier()`.
- [ ] صفر تعديل على Legacy (`admin-mods.php`, `class-pge-packages.php`, `mon_packages_settings`).
- [ ] `/create-event/` تعمل بلا Regression لكل من مستخدمي Legacy وCatalog الحاليين (تحقُّق يدوي + آلي).
- [ ] Google Maps فقط هو الحقل المنقول في `/create-event/` — لا حقل آخر تأثَّر.
- [ ] لا Feature Key حر قابل للإدخال من أي واجهة إدارية جديدة (Phase 5، إن اكتملت).
- [ ] كل اختبارات الانحدار القائمة (الرصيد، الـLedger، الـReplacement Entitlements) لا تزال تمر بلا أي تعديل عليها.
- [ ] كل عناصر `Blocked` في هذه الوثيقة إما حُسِمت بقرار موثَّق (خارج نطاق هذه الوثيقة) أو ما زالت خارج نطاق هذا الـPull Request صراحة.
- [ ] لا أي تعديل على `docs/PACKAGE-FEATURE-MATRIX.md` ضمن هذا الـPull Request.

---
