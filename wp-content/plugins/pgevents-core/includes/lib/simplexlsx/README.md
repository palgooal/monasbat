# SimpleXLSX (vendored)

- **المصدر الرسمي**: https://github.com/shuchkin/simplexlsx
- **الإصدار المُثبَّت**: `1.1.16` (تاغ Git رسمي، مُنزَّل مباشرة من `raw.githubusercontent.com/shuchkin/simplexlsx/1.1.16/src/`)
- **الرخصة**: MIT — راجع ملف [`LICENSE`](./LICENSE) في هذا المجلد (نسخة طبق الأصل من `license.md` الرسمي).
- **المؤلف**: Sergey Shuchkin <sergey.shuchkin@gmail.com>

## لماذا هذا المجلد

هذا المشروع (pgevents-core) لا يستخدم Composer ولا يملك مجلد `vendor/` — نمط ثابت عبر كل الكودبيس
(راجع تعليق `class-pge-xlsx-writer.php` وقرار الاختيار الموثَّق في `docs/EXCEL-GUEST-IMPORT-SPEC.md`
القسم 4). لذلك تم تنزيل الكلاسين المطلوبين **يدوياً وبلا أي تعديل على منطقهما** ووضعهما هنا مباشرة،
بنفس الأسلوب المتبع في بقية المشروع لكل الملفات المفردة بلا اعتماديات خارجية.

## الملفات

| الملف | الدور |
|---|---|
| `SimpleXLSX.php` | الكلاس الأساسي — قراءة/تحليل ملفات `.xlsx` (`SimpleXLSX::parse()`, `rows()`, إلخ). |
| `SimpleXLSXEx.php` | ملحق رسمي **مطلوب** لدالة `rowsEx()` تحديداً (تفاصيل الأنماط/النوع لكل خلية — `type`, `s`, إلخ). يُحمَّل تلقائياً بواسطة `SimpleXLSX::readRowsEx()` عبر `require_once __DIR__ . '/SimpleXLSXEx.php'` الموجود أصلاً داخل `SimpleXLSX.php` نفسها — لا يحتاج تحميلاً يدوياً من كودنا. |
| `LICENSE` | نص رخصة MIT الرسمي الكامل. |

## الاستخدام في هذا المشروع

يُحمَّل عبر `require_once` واحد فقط لـ`SimpleXLSX.php` من داخل
`includes/class-pge-invitation-excel-import.php`:

```php
require_once __DIR__ . '/lib/simplexlsx/SimpleXLSX.php';
// ...
$xlsx = \Shuchkin\SimpleXLSX::parse($path);
```

الكلاس داخل namespace `Shuchkin` (كما في المصدر الرسمي بلا تعديل) — يُستدعى بالاسم الكامل
`\Shuchkin\SimpleXLSX` من كود المشروع (الذي لا يستخدم namespaces في أي مكان آخر).

## تعديلات على الكود الأصلي

**لا يوجد أي تعديل** على محتوى `SimpleXLSX.php` أو `SimpleXLSXEx.php` — نسخة طبق الأصل من الإصدار
`1.1.16` الرسمي. أي تحديث مستقبلي للمكتبة يجب أن يمر بنفس هذه الخطوة (تنزيل الإصدار الجديد بالكامل)
وليس تعديلاً يدوياً جزئياً.

## الاعتماديات

- لا Composer، لا `vendor/`.
- لا يحتاج `ext-zip` — فك ضغط ZIP داخلي مُنفَّذ يدوياً داخل الكلاس نفسه.
- يحتاج فقط `ext-simplexml` (مفعّل افتراضياً في كل توزيعات PHP القياسية تقريباً).
