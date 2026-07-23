# قواعد عمل كتالوج الباقات والخدمات

آخر تحديث: 2026-07-22
الحالة: نشط

# مقدمة

يوثّق هذا المستند القيم التطبيقية المسموحة (Controlled Values) لحقول جداول كتالوج الباقات والخدمات الجديد: `mon_plans`, `mon_plan_tiers`, `mon_services` (المُعرَّفة في `includes/class-mon-catalog-schema.php` ضمن الإضافة `pgevents-core`). هذه القيم تحكم طبقة `PGE_Catalog` عند إضافة دوال الكتابة (`create_plan()` وما يليها)، وليست جزءاً من بنية قاعدة البيانات نفسها.

# Catalog Controlled Values — Initial Decision

الحالة: Approved for initial implementation
التاريخ: 2026-07-22

## 1. plan_type (mon_plans)

القيم الرسمية المسموحة مبدئياً:

- `personal`
- `business`

## 2. status

القيم الرسمية المسموحة مبدئياً، وتُطبَّق على الجداول الثلاثة:

- `mon_plans`
- `mon_plan_tiers`
- `mon_services`

وهي:

- `active`
- `inactive`

## 3. currency

العملة المدعومة مبدئياً، وتُطبَّق على:

- `mon_plan_tiers`
- `mon_services`

وهي:

- `SAR`

`mon_plans` لا يحتوي على عمود `currency` أصلاً — السعر منطقياً يتبع المستوى (`mon_plan_tiers`) أو الخدمة (`mon_services`)، لا الباقة نفسها.

## 4. طبيعة هذه القرارات

- هذه قرارات تطبيقية (Application-level) وليست `ENUM` ولا `CHECK constraints` في قاعدة البيانات.
- الأعمدة ستبقى `VARCHAR` للتوافق مع `dbDelta()` (نفس القرار الموثّق داخل `class-mon-catalog-schema.php`).
- التحقق من هذه القيم يتم داخل طبقة `PGE_Catalog` عند إضافة دوال الكتابة، لا في بنية الجدول.
- أي قيمة جديدة مستقبلاً (لأي من الحقول الثلاثة) تحتاج قراراً موثقاً في هذا الملف واختبارات فعلية قبل اعتمادها في الكود.
- لا يتم اعتماد `draft` أو `published` كقيم لـ `status` في المرحلة الحالية.
- لا يتم اعتماد `USD` أو `ILS` كقيم لـ `currency` في المرحلة الحالية.

# مصدر القرار

هذا القرار جاء بعد مراجعة فعلية أكدت أن Schema الأصلي (`class-mon-catalog-schema.php`) لا يحدد أي قائمة قيم رسمية لهذه الحقول (أعمدة `VARCHAR` بقيمة افتراضية واحدة فقط دون Enum)، ولا يوجد أي نظام سابق في المشروع (بما في ذلك نظام الباقات القديم عبر `mon_packages_settings`) يحمل مفاهيم `plan_type`/`status`/`currency` يمكن القياس عليها. القيم أعلاه هي أول اعتماد رسمي لها.
