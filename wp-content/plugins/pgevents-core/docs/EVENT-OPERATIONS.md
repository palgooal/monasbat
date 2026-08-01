# لوحة عمليات المناسبة الحيّة (Event Operations Dashboard)

> Entry Check-in Supervisors — Phase 10 ("Event Operations" RFC)
> آخر تحديث: 2026-07-31

---

## 1. الهدف والنطاق

صفحة واحدة فقط (`/event-manage/{id}/operations/`) تُجمِّع (Orchestrate) قدرات
مُعتمَدة **موجودة فعلاً** في يوم المناسبة الحيّ، بلا أي منطق أعمال جديد، وبلا
أي إعادة تصميم لخدمة قائمة. الصفحة **لا تُنشئ** أي قدرة عمل جديدة — كل ما
تعرضه/تفعله موجود ومُعتمَد مسبقاً في مرحلة سابقة.

خارج النطاق صراحةً (Out of Scope — لم يُنفَّذ شيء منها): WebSockets، Server-
Sent Events، وضع عدم الاتصال (Offline)، وضع الكشك (Kiosk)، تسجيل حضور عام
(Public Check-in)، إشعارات، مراسلة (SMS/واتساب/بريد)، تقارير PDF، استيراد
بيانات، تعديل جماعي (Bulk Editing).

---

## 2. المعمارية — طبقة تجميع رقيقة فوق خدمات مُعتمَدة

```
templates/event-operations.php (SSR أولي)
        │
        ▼
includes/event-operations-ajax.php (AJAX رقيق — لا SQL هنا)
        │
        ├─► PGE_Attendance_Dashboard_Provider::get_dashboard($event_id, $limit)
        │       │   (Phase 5/6 — غير مُعدَّلة في حسابها؛ الإضافة الوحيدة:
        │       │    معامل $recent_checkins_limit اختياري، راجع §5)
        │       ├─► authorize() [host أو مشرف نفس المناسبة]
        │       └─► PGE_Attendance_Statistics_Service (Phase 5 — مُجمَّدة تماماً، بلا لمس)
        │
        ├─► PGE_Invitation_Service::list_invitations() (Phase 9 — بلا تعديل)
        │       (أ) عدّاد الدعوات المُلغاة  (ب) بحث الدعوات
        │
        └─► PGE_Supervisor_Assignment_Service::list_assignments_for_event_page()
                (Phase 8 — بلا تعديل)
                (أ) "آخر نشاط" لكل مشرف (updated_at)  (ب) بحث المشرفين
```

لا `$wpdb`، لا SQL، لا حساب إحصاء جديد في أي من `event-operations-ajax.php` أو
`templates/event-operations.php` — كل رقم معروض مصدره أحد الاستدعاءات الثلاثة
أعلاه حصراً.

---

## 3. المكوّنات (الأقسام الخمسة المطلوبة)

| # | القسم | المصدر | ملاحظة |
|---|-------|--------|--------|
| 1 | ملخّص الحضور الحيّ | `attendance_summary` من Dashboard Provider + عدّاد الملغاة من Invitation Service | عرض فقط، لا حساب جديد |
| 2 | حالة المشرفين الحاليّة | `supervisor_summary` من Dashboard Provider + `updated_at` من Assignment Service | راجع §6 لقرار "بلا تتبُّع حضور حيّ جديد" |
| 3 | آخر عمليات تسجيل الحضور | `recent_checkins` من Dashboard Provider، مربوطة باسم المشرف عبر `supervisor_summary` لنفس اللقطة | Lookup بالذاكرة فقط، بلا استعلام إضافي |
| 4 | بحث سريع موحَّد | نتيجتان مستقلتان: `PGE_Invitation_Service::list_invitations()` + `PGE_Supervisor_Assignment_Service::list_assignments_for_event_page()` | لا محرك بحث جديد، لا دمج/تصنيف موحَّد مصطنع |
| 5 | اختصارات تشغيلية | روابط لصفحتَي Invitations/Supervisors القائمتين + أزرار تصدير CSV/Excel (نفس نقطتَي AJAX الحاليتين حرفياً) + زر "تجديد QR" لكل نتيجة بحث دعوة (نفس `pge_invitation_mgmt_qr_regenerate` الحالية حرفياً) | لا إجراء عمل جديد بأي شكل |

---

## 4. استراتيجية التحديث الحيّ (Live Refresh)

- استطلاع خفيف (Lightweight Polling) كل **15 ثانية افتراضياً** (`data-poll-interval-ms`
  على جذر القالب، قابل للتهيئة مستقبلاً بلا تغيير كود JS).
- يُحدَّث فقط: الإحصاء الحيّ + آخر عمليات الحضور + حالة المشرفين (نفس استدعاء
  `pge_event_ops_dashboard` واحد يُعيد لقطة واحدة ذرّية — تُطبَّق كل الأقسام
  الثلاثة معاً أو لا شيء منها، بنفس مبدأ "Atomic Dashboard Snapshot" من Phase
  6 Final Fix).
- **لا** إعادة تحميل كاملة للصفحة، **لا** WebSockets، **لا** SSE — `fetch()`
  دوري بسيط فقط عبر `setInterval`.
- البحث السريع **لا** يُنشِّط استطلاع اللوحة ولا العكس — مسـاران مستقلان
  تماماً (Requirement: "Only Statistics/Recent activity/Supervisor
  availability refreshed" — البحث ليس من هذه الثلاثة).
- دورة استطلاع قيد التنفيذ تمنع دورة أخرى متزامنة (`isRefreshingDashboard`)
  — نفس نمط `isRefreshing` في `supervisor-dashboard.php` حرفياً.

---

## 5. قرار معماري: معامل `$recent_checkins_limit` الاختياري

`PGE_Attendance_Dashboard_Provider::get_dashboard()` (Phase 5/6) كانت تستدعي
`get_recent_checkins($event_id)` بلا أي معامل (فتُستخدَم القيمة الافتراضية
الحالية في `PGE_Attendance_Statistics_Service` وهي **10**). صفحة عمليات
المناسبة تتطلَّب "عدد قابل للتهيئة، افتراضه 20" لقسم "آخر عمليات تسجيل
الحضور" — أكبر من افتراض لوحة المشرف (Phase 6، 10، غير مُغيَّر).

**الحل:** إضافة معامل اختياري ثانٍ `int $recent_checkins_limit = 10` لتوقيع
`get_dashboard()` — القيمة الافتراضية **نفسها بالضبط** القيمة الضمنية
السابقة، فأي مستدعٍ حالي بمعامل واحد فقط (`dashboard-ajax.php`، Phase 6) لا
يتغيّر سلوكه إطلاقاً (مُثبَت في `tests/test-event-operations.php`، سيناريو
Backward Compatibility). صفحة العمليات تُمرِّر `20` صراحة.

**لماذا هذا لا يخالف تجميد المعمارية:** لا تغيير على:
- `PGE_Attendance_Statistics_Service` (الصف المُجمَّد فعلياً) — `get_recent_checkins($event_id, $limit)`
  كانت تقبل `$limit` أصلاً منذ Phase 5؛ لم تُعدَّل هذه الدالة بحرف واحد هنا.
- `PGE_Attendance_Recorder` — غير مُستهدَف إطلاقاً.
- أي حساب/استعلام داخل الإحصاء — نفس الاستعلام بالضبط، فقط `LIMIT` مختلف
  يُمرَّر صراحةً بدل الاعتماد على قيمة افتراضية ضمنية.

`PGE_Attendance_Dashboard_Provider` نفسه غير مُدرَج ضمن قائمة "Architecture
Freeze" الصريحة لهذه المرحلة (المُدرَج: Invitation Identity/QR
Architecture/QR Rotation/Guest Resolution/Attendance Recorder/Attendance
Statistics/Invitation CRUD-Search-Filtering-Export/Supervisor
Identity-Sessions/Authorization/Quota/Audit/Delivery Request) — فهو تحديداً
طبقة التجميع (Aggregation Layer) التي تطلب هذه المرحلة صراحةً البناء فوقها
وإعادة استخدامها.

---

## 6. قرارات نطاق أخرى (موثَّقة صراحةً، لا انحراف صامت)

### أ) لا تتبُّع حضور حيّ (Presence Tracking) جديد
الطلب: "Online/offline (if already available)". لا توجد آلية تتبُّع جلسة
حيّة/اتصال مباشر في `class-pge-supervisor-session.php` (لا دالة "قائمة
الجلسات النشطة الآن" أصلاً) — عمداً وفق الحظر الصريح لهذه المرحلة على
"introducing presence tracking". لذلك **لم تُعرَض** أي قيمة "متصل الآن/غير
متصل" مُخترَعة. المعروض بدلاً منها: حالة الإسناد الحالية (`status`: نشط/
بانتظار القبول/ملغى — من `mon_event_supervisors`، Phase 2/8) و"آخر نشاط"
(`updated_at` لنفس الصف — يتحدَّث فعلياً عند كل انتقال حالة حقيقي: قبول/
إلغاء/تعديل/إعادة إرسال). كلاهما بيانات **موجودة أصلاً**، بلا أي عمود/جدول/
تتبُّع جديد.

### ب) مصدر عدّاد "الدعوات المُلغاة"
`PGE_Attendance_Statistics_Service::get_attendance_summary()` لا يُعيد عدّاد
"ملغاة" أصلاً (حالة الإلغاء تعيش في `PGE_Invitation_Repository` عبر
`_pge_invitation_status`، لا في جدول `wp_pge_event_rsvps` الذي يقرأه محرك
الإحصاء). الحل: استدعاء إضافي مستقل غير مُكلِف عبر `PGE_Invitation_Service::
list_invitations($event_id, ['invitation_status'=>'cancelled','per_page'=>1])`
وقراءة `['total']` فقط (لا صفوف فعلية تُنقَل) — استعلام واحد إضافي لكل تحديث
لوحة، بصرف النظر عن عدد الدعوات. هذا **ليس** تعديلاً على محرك الإحصاء ولا
حساباً جديداً داخله؛ فقط دمج عرضي (Display Merge) لعدّاد من خدمة أخرى
مُعتمَدة أصلاً ضمن نفس بطاقات "ملخّص الحضور الحيّ".

### ج) "عدد المشرفين" كبطاقة إحصاء
بطاقة "عدد المشرفين" في مثال الطلب (Total Invitations/Checked In/Pending/
Cancelled/Attendance Rate/Supervisor Count) هي `count()` بسيط لمصفوفة
`supervisor_summary` **العائدة أصلاً** من نفس استدعاء Dashboard Provider —
لا استعلام جديد، لا `SELECT COUNT`، مجرَّد طول مصفوفة موجودة فعلاً في نفس
اللقطة. يُعامَل كتنسيق عرض بحت (Presentation Formatting)، بنفس المبدأ الذي
عومل به `attendance_rate_percent`/`average_guests_display` في
`supervisor-dashboard.php` (Phase 6).

### د) "فتح الدعوة/المشرف/سجلّ الحضور" كروابط لا كصفحات جديدة
لا صفحة "تفاصيل دعوة مفردة" أو "سجلّ حضور مفرد" منفصلة موجودة في المشروع
حتى الآن — "فتح" يعني الانتقال إلى الصفحة القائمة المعتمَدة (`/event-manage/
{id}/invitations/` أو `/event-manage/{id}/supervisors/`) حيث يمكن للمضيف
البحث/الإجراء يدوياً؛ لا تمرير معامل بحث مُسبَق عبر الرابط (كان سيتطلَّب
تعديل تلك الصفحات المُعتمَدة سابقاً لدعم قراءة معامل من الرابط — خارج نطاق
هذه المرحلة، ولا داعٍ له أصلاً بما أن حقل البحث موجود أصلاً في كلتا
الصفحتين).

---

## 7. الأمان (Security)

- **التفويض:** `pge_event_guests_user_can_manage($event_id)` — نفس دالة
  Invitations/Supervisors حرفياً، في `pge_event_ops_validate_request()`
  (المستخدَمة في كل من نقطتَي AJAX) وفي أعلى `templates/event-operations.php`
  (SSR). Nonce نفسه `pge_event_manage_nonce` المُستخدَم في كل صفحات إدارة
  المضيف الأخرى.
- **تكرار دفاعي مقصود:** `PGE_Attendance_Dashboard_Provider::get_dashboard()`
  يُعيد تنفيذ تفويضه الداخلي الخاص (`pge_is_host_or_admin()`) بصرف النظر عن
  تحقق الطبقة الأعلى — نمط مُعتمَد فعلياً في المشروع (`templates/
  supervisor-dashboard.php` تفعل نفس الشيء).
- **عزل بين المناسبات (Cross-Event Isolation):** كل استدعاء يمرِّر `$event_id`
  المُوثَّق (من `$_POST` بعد فحص `get_post_type($event_id) === 'pge_event'`)
  إلى الخدمات الثلاث — لا استدعاء بلا `event_id` صريح، ولا اعتماد على جلسة
  ضمنية عابرة للمناسبات.
- **لا تسريب بيانات مناسبة أخرى:** `authorize()` داخل Dashboard Provider
  يرفض صراحةً (`event_mismatch`/403) أي محاولة وصول عبر جلسة مشرف لمناسبة
  مختلفة (نفس منطق Phase 5، غير مُعدَّل).

---

## 8. الأداء (Performance)

لكل تحديث لوحة واحد (`pge_event_ops_dashboard`)، عدد الاستعلامات الإجمالي:

| المصدر | عدد الاستعلامات | ملاحظة |
|--------|-----------------|--------|
| `get_attendance_summary()` | 1 (تجميع واحد) | غير مُعدَّلة |
| `get_supervisor_summary()` | 2 (GROUP BY + قائمة إسنادات) | غير مُعدَّلة |
| `get_recent_checkins()` | 2 (سجل تدقيق LIMIT + دفعة RSVP) | غير مُعدَّلة، فقط `LIMIT` مختلف |
| عدّاد الملغاة (جديد) | 1 (`COUNT` عبر `list_invitations` بـ`per_page=1`) | إضافة Phase 10 الوحيدة هنا |
| آخر نشاط المشرفين (جديد) | 1 (`list_assignments_for_event_page` بحد أقصى 100) | إضافة Phase 10 الوحيدة هنا، دمج بالذاكرة فقط، O(n) لا O(n²) |

**المجموع: 7 استعلامات** لكل لقطة لوحة كاملة، بصرف النظر عن عدد الدعوات/
المشرفين/سجلّات الحضور — لا N+1 في أي مكان (كل استعلام دفعة واحدة، لا حلقة
تستعلم لكل صف).

نقطة البحث السريع (`pge_event_ops_search`) منفصلة تماماً: استعلامان فقط (بحث
دعوات + بحث مشرفين)، كل واحد بحد `per_page=10` — حمولة صغيرة مقصودة لواجهة
بحث سريع، لا قائمة كاملة.

---

## 9. التدقيق (Audit)

عرض اللوحة **لا يُسجِّل أي حدث تدقيق** — لا استدعاء لـ`PGE_Invitation_
Management_Audit::record()` ولا `PGE_Supervisor_Management_Audit::record()`
ولا أي جدول تدقيق آخر من `event-operations-ajax.php` أو من القالب. الإجراءات
القديمة المُعاد استخدامها من اللوحة (تجديد QR، تصدير CSV/Excel) تستمر
بتسجيل تدقيقها الخاص كما كانت تماماً قبل هذه المرحلة (بلا أي تغيير هناك) —
لأنها تُستدعى عبر نفس نقاط AJAX القديمة غير المُعدَّلة حرفياً.

---

## 10. إعادة استخدام الخدمات المُعتمَدة (خريطة كاملة)

| الخدمة | المرحلة | الاستخدام هنا | مُعدَّلة؟ |
|--------|---------|----------------|-----------|
| `PGE_Attendance_Dashboard_Provider` | 5/6 | مصدر الإحصاء/المشرفين/آخر الحضور | إضافة معامل اختياري توافقي خلفياً فقط (راجع §5) |
| `PGE_Attendance_Statistics_Service` | 5 | (غير مُستدعاة مباشرة — عبر Provider حصراً) | لا، مُجمَّدة تماماً |
| `PGE_Invitation_Service` | 9 | عدّاد الملغاة + بحث الدعوات | لا |
| `PGE_Supervisor_Assignment_Service` | 8 | آخر نشاط المشرف + بحث المشرفين | لا |
| `pge_invitation_mgmt_reshape_row()` | 9 | إعادة تشكيل صفوف بحث الدعوات (إعادة استخدام حرفية للدالة الحالية) | لا |
| `pge_supervisor_mgmt_reshape_row()` | 8 | إعادة تشكيل صفوف بحث/حالة المشرفين (إعادة استخدام حرفية) | لا |
| `pge_invitation_mgmt_qr_regenerate` (AJAX) | 9B | زر "تجديد QR" في نتائج البحث | لا |
| `pge_invitation_mgmt_export_csv`/`_excel` (AJAX) | 9C | أزرار "اختصارات تشغيلية" للتصدير | لا |
| `pge_event_guests_user_can_manage()` | — | التفويض في كل نقاط AJAX + القالب | لا |
