# Quality Gate

> ليست Architecture، وليست Plan، وليست Specification، وليست Status، وليست Report، وليست Working Agreement.
> قائمة تحقق إلزامية يجب المرور عليها قبل اعتبار أي Commit جاهزاً للمراجعة أو الدمج.

---

## 1. Purpose

منع دمج أي تنفيذ غير مكتمل، وتوحيد معايير قبول جميع الـCommits.

---

## 2. Preconditions

- [ ] Commit مكتمل.
- [ ] Status محدَّث (`PHASE-1-STATUS.md`).
- [ ] Report موجود (وفق `IMPLEMENTATION-REPORT-TEMPLATE.md`).

---

## 3. Architecture Validation

- [ ] لم تتغير المعمارية (`PACKAGE-FEATURE-MATRIX.md`).
- [ ] لم تتم إضافة قرارات جديدة.
- [ ] لم يتم تجاوز ترتيب Source of Truth (`WORKING-AGREEMENT.md §2`).

---

## 4. Scope Validation

- [ ] لم يتم الخروج عن Scope المحدَّد في `FEATURES-PHASE-1-SPEC.md`.
- [ ] لم يتم تعديل ملفات خارج نطاق المرحلة الحالية.

---

## 5. Code Validation

- [ ] لا يوجد كود غير مستخدم.
- [ ] لا يوجد Duplicate Logic.
- [ ] لا توجد ملفات زائدة.

---

## 6. Testing Validation

- [ ] جميع الاختبارات المطلوبة نجحت.
- [ ] Regression مكتمل.

---

## 7. Documentation Validation

- [ ] Status محدَّث.
- [ ] Report مكتمل.

---

## 8. Approval

```
Reviewer:
Date:
Result: Pass / Fail
```

---
