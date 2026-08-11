# RC1 Hard Delete Semantics Fix Pack (Critical Release Blocker)

> يُصلِح هذا الفيكس باك حصراً الموانع الحرجة الثلاثة التي كشفها
> `docs/HARD-DELETE-SEMANTICS-AUDIT.md`. لا إعادة تصميم، لا API عام تغيَّر،
> لا واجهة مستخدم تغيَّرت، لا جدول/عمود/مفتاح خارجي جديد. أصغر تعديل معماري
> ممكن، في الطبقة الصحيحة لكل سبب جذري.

## تحديث Phase D1 — QR Lifecycle Tombstone

Hard Delete ما زال يحذف الدعوة من `_pge_invited_guests` ولا يحذف RSVP أو
سجلات الحضور. التغيير التقني الوحيد: لا تُحذف
`_pge_invitation_status[phone]` بالكامل؛ تبقى Tombstone غير مرئية تحمل آخر
`qr_version`. كل readers والقوائم والإحصاءات تبدأ من guest map، لذلك لا تصبح
Tombstone دعوة ولا تُحتسب ضمن Guest Limit أو exports.

عند Re-invite لنفس الهاتف، يعاد استخدام `rsvp_id` نفسه وفق Phase C، وتتحول
Tombstone إلى entry نشطة بإصدار `qr_version` أكبر رتيباً. هذا يفصل QR القديم
عن الجديد حتى لو تم الحذف وإعادة الإنشاء في الثانية نفسها. التفاصيل الكاملة
في `docs/INVITATION-QR-ARCHITECTURE.md`.

## المشكلة الجذرية الموحَّدة

Hard Delete (Fix Pack 3B) يحذف الضيف من خريطة الضيوف (`_pge_invited_guests`)
عمداً بلا لمس `wp_pge_event_rsvps` (لا Cascade، قرار موثَّق). كل طبقة أخرى
(محرك حلّ الضيف، رمز QR، مسار كتابة RSVP) كانت تفترض ضمنياً أن "وجود صف
RSVP بهاتف معيّن" يعني "توجد دعوة نشطة بهذا الهاتف الآن" — افتراض كان صحيحاً
قبل وجود Hard Delete، وأصبح خاطئاً بعده.

## الإصلاح الجذري الوحيد

دالة واحدة جديدة: `PGE_Invitation_Repository::is_rsvp_row_current($event_id,
$phone, $rsvp_created_at)` — تقارن `created_at` لصف RSVP مع `invited_at`
لدورة حياة الدعوة **الحالية** لنفس الهاتف (كلاهما طابعا وقت موجودان أصلاً،
لا عمود جديد). صف أقدم من `invited_at` الحالي (أو لا دعوة حالية إطلاقاً) —
ينتمي لدورة حياة سابقة، ويُعامَل كغير موجود.

نقطتا استدعاء فقط، في الطبقتين الصحيحتين معمارياً:

1. **طبقة الحلّ** (`PGE_Guest_Resolution_Service::resolve_by_rsvp_id()`/
   `resolve_by_phone()`) — تُعالج Blocker 1 (منع الوصول لـRecorder) وBlocker
   2 (منع إحياء QR مُدوَّر) معاً، لأن `resolve_from_qr()` تمرّ عبر
   `resolve_by_rsvp_id()` أصلاً (نقطة تقارب موجودة سلفاً، لا جديدة).
2. **طبقة كتابة RSVP** (`pge_save_rsvp_response()` في `rsvp-handler.php`) —
   تُعالج Blocker 3: صف قديم غير منتمٍ لدورة الحياة الحالية يُهمَل عند
   upsert، فيُنشأ صف جديد مستقل بدل توريث حالته.

## الملفات المعدَّلة (3 فقط)

- `includes/class-pge-invitation-repository.php` — إضافة
  `is_rsvp_row_current()` فقط (لا تعديل على أي دالة قائمة).
- `includes/class-pge-guest-resolution-service.php` — سطرا استدعاء في
  `resolve_by_rsvp_id()`/`resolve_by_phone()`.
- `includes/rsvp-handler.php` — سطر استدعاء واحد في `pge_save_rsvp_response()`
  (يقتصر على `!$is_host_or_admin` عمداً — لا يمسّ مسار المضيف/الأدمن).

## الاختبارات

- `tests/test-hard-delete-semantics-fixpack.php` (جديد) — 26 حالة، تغطي
  الحالات 1-19 المطلوبة صراحة.
- `tests/test-hard-delete-semantics-audit.php` (مُحدَّث) — التأكيدات التي
  كانت تُثبت العلل الثلاث حُدِّثت لتعكس السلوك المُصلَح، مع توثيق السبب في
  رأس الملف (نفس نمط تصحيحات fixpack2/fixpack3a السابقة). 42/42.

## الحالة

**GO** — الموانع الثلاثة الحرجة مُغلَقة بأدلة تنفيذية حقيقية. راجع التقرير
النهائي المُسلَّم في المحادثة للتفاصيل الكاملة (السبب الجذري لكل Blocker،
نتائج الانحدار الكاملة، المخاطر المتبقية).
