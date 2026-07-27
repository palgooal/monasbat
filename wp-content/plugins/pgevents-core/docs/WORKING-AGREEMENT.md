# Working Agreement — قواعد العمل الإلزامية أثناء التنفيذ

> هذه الوثيقة ليست Architecture، وليست Plan، وليست Specification، وليست Status، وليست Report.
> هي قواعد عمل إلزامية فقط — أي تنفيذ جديد يجب أن يلتزم بها.

---

## 1. Purpose

منع الانحراف أثناء التطوير، وتوحيد أسلوب التنفيذ، وتسهيل مراجعة الكود.

---

## 2. Source of Truth

ترتيب المراجع الرسمي، من الأعلى سلطة إلى الأدنى:

1. `PACKAGE-FEATURE-MATRIX.md`
2. `FEATURES-IMPLEMENTATION-PLAN.md`
3. `FEATURES-PHASE-1-SPEC.md`
4. `PHASE-1-STATUS.md`

عند أي تعارض، المرجع الأعلى في هذا الترتيب هو الفيصل.

---

## 3. Allowed Work

- تنفيذ المرحلة الحالية فقط، كما هي محدَّدة في `FEATURES-PHASE-1-SPEC.md`.
- الالتزام الحرفي بالمواصفات الواردة في المرجع رقم 3 (`FEATURES-PHASE-1-SPEC.md`).
- تحديث `PHASE-1-STATUS.md`.
- إنشاء تقرير تنفيذ باستخدام `IMPLEMENTATION-REPORT-TEMPLATE.md`.

---

## 4. Forbidden Work

- تغيير Architecture (`PACKAGE-FEATURE-MATRIX.md`).
- تعديل الخطة (`FEATURES-IMPLEMENTATION-PLAN.md`).
- إضافة Features غير واردة في Feature Registry.
- إنشاء جداول قاعدة بيانات غير مخطط لها في المواصفات.
- تعديل ملفات خارج Scope المرحلة الحالية.

---

## 5. Change Rules

- أقل تعديل ممكن.
- عدم تعديل ملفات غير ضرورية للمهمة الحالية.
- Commit صغير.
- خطوة واحدة في كل مرة.

---

## 6. Documentation Rules

بعد كل Commit، يجب تحديث:

- Status (`PHASE-1-STATUS.md`).
- Report (باستخدام `IMPLEMENTATION-REPORT-TEMPLATE.md`).

ولا شيء آخر.

---

## 7. Review Rules

قبل اعتبار المهمة منتهية يجب التأكد من:

- الاختبارات.
- Regression.
- Definition of Done.

---

## 8. Escalation Rules

إذا ظهر شيء غير موجود في Architecture أو Specification: **لا يُتَّخَذ أي قرار.** يُوقَف التنفيذ، ويُكتَب:

```
Blocked
```

---

## 9. Completion Rules

تُعتبر المهمة مكتملة عندما تتحقق كل بنود القسم 7 (Review Rules) وتُحدَّث الوثائق المذكورة في القسم 6 (Documentation Rules).

---
