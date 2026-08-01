# Supervisor Invitation Delivery via Cartat — تنفيذ

> "Do NOT invalidate the existing active token before a new delivery request
> has been accepted by Cartat."

هذا المستند هو المرجع المعماري الوحيد لآلية تسليم دعوات مشرفي الدخول
(Entry Check-in Supervisors) فعلياً عبر واتساب (Cartat)، بعد أن كانت دورة
حياة الإسناد (Phase 2/8) بلا أي قناة تسليم فعلية على الإطلاق — `resend_
invitation()` كانت تُدوِّر توكناً جديداً وتُسجِّل `invitation_resent` دون أن
تُرسِل أي رسالة فعلياً لأي أحد.

---

## 1. المشكلة قبل هذا التنفيذ

| السؤال | الإجابة قبل التنفيذ |
|---|---|
| هل يوجد مُرسِل Cartat عام قابل لإعادة الاستخدام؟ | لا — منطق الإرسال بالكامل كان دوالاً `private` داخل `Mon_Cartat_Handler` (دعوات الضيوف حصراً). |
| هل يوجد مسار HTTP فعلي لقبول دعوة مشرف؟ | لا — `PGE_Supervisor_Authenticator::authenticate()` موجودة ومعتمدة (Phase 3) لكن لا Controller/Route يستدعيها. |
| ماذا كان يحدث عند "إعادة الإرسال"؟ | تدوير توكن + تسجيل `invitation_resent` تدقيقياً — **بلا أي إرسال فعلي**. تسمية مضلِّلة. |

---

## 2. اختيار المعمارية: Option B — طبقة نقل مشتركة

بدل بناء مسار إرسال ثانٍ موازٍ لمسار دعوات الضيوف، استُخرِجت طبقة النقل
الفعلية (تحميل الاعتماد، تهيئة رقم الجوال، تنفيذ `wp_remote_post()`، تفسير
النتيجة) من `Mon_Cartat_Handler` إلى ملف مستقل واحد:
`includes/class-pge-cartat-transport.php` (`PGE_Cartat_Transport`).

**بعد هذا الاستخراج، هذا هو تطبيق النقل الوحيد لـCartat في المشروع بالكامل.**
`Mon_Cartat_Handler` (دعوات الضيوف) يُفوِّض إليه داخلياً — راجع الجدول أدناه؛
لا نسخة موازية من نفس المنطق في أي مكان آخر.

| ما تحتويه `PGE_Cartat_Transport` | ما لا تحتويه عمداً |
|---|---|
| تحميل `pge_cartat_api_token`/`pge_cartat_country_code` | تركيب نص دعوة الضيف أو المشرف |
| `format_number()` (تطبيع الرقم لصيغة واتساب الدولية) | منطق RSVP |
| `send_text()`/`send_media()` (`wp_remote_post()` الفعلي) | دورة حياة إسناد المشرف |
| `interpret_result()` (`accepted`/`rejected`/`transport_error`) | إنشاء جلسة |
| تشخيص غير حسّاس (`error_log()` بالـEndpoint والاستجابة المفكوكة فقط) | دلالات تدقيق (Audit) |

### التغيير في `Mon_Cartat_Handler`

استُبدِلت الخصائص `$api_token`/`$api_base`/`$country_code` بخاصية واحدة
`private PGE_Cartat_Transport $transport;`، وحُذِفت الدوال الخاصة الأربع
(`send_text_message`/`send_media_message`/`api_request`/`interpret_cartat_
result`/`format_wa_number`) بالكامل، واستُبدِلت كل استدعاءاتها الإحدى عشرة
(عبر `handle_incoming_message()`/`ajax_test_send()`/`handle_send_
invitations_ajax()`/`ajax_queue_start()`/`cron_process_queue()`) بمكافئها
عبر `$this->transport->...()`. **صفر تغيير سلوكي** على أي تدفّق دعوات ضيف
قائم — راجع تغطية الانحدار في §7.

---

## 3. `PGE_Supervisor_Invitation_Delivery` — الخدمة المركزية

`includes/class-pge-supervisor-invitation-delivery.php`
(`PGE_Supervisor_Invitation_Delivery::deliver($assignment_id,
$actor_user_id)`) — النقطة الوحيدة المسموح بها لطلب تسليم دعوة مشرف فعلي.
تُستدعى من مسارَي "إنشاء مشرف" و"إعادة الإرسال" في `supervisor-management-
ajax.php` على حدٍّ سواء (نفس منطق الترتيب الآمن لكليهما، بلا تكرار).

### ما لا تفعله هذه الخدمة عمداً

- لا تُفعِّل الإسناد (`accept_invitation()` تبقى حصراً استجابةً لرابط القبول
  الفعلي — راجع §5).
- لا تُنشئ جلسة مشرف (`PGE_Supervisor_Session` منفصلة تماماً).
- لا تلمس الحضور/الإحصاء/QR/إدارة الدعوات (Guest Invitations).
- لا تُنشئ حساب WordPress بأي شكل — لا مشرف يملك حساب WP إطلاقاً.
- لا تشتق `event_id` من أي مُدخَل عميل مباشر — تُشتَق حصراً من صف الإسناد
  نفسه بعد التحقق من ملكيته. التفويض (هل المستدعي مضيف مخوَّل؟) مسؤولية
  الطبقة المستدعية (`supervisor-management-ajax.php`)، لا تُعاد هنا.

---

## 4. ترتيب تدوير التوكن الآمن (حرج)

الخطوات الفعلية بالضبط، بهذا الترتيب:

1. **تحقّق الأهلية** — الإسناد يجب أن يكون `invited` أو `pending` (نفس
   `ACCEPTABLE_STATUSES`). أي حالة أخرى → `not_eligible`، **بلا أي استدعاء
   لـCartat إطلاقاً**.
2. **`GET_LOCK`** خاص بهذا الإسناد تحديداً (`pge_supervisor_delivery_{md5(id)}`،
   مهلة 5 ثوانٍ — نفس القيمة الهندسية المعتمَدة في `create_supervisor_
   assignment()`). مشغول → `lock_busy`، بلا أي سجل تدقيق (لم يبدأ أي "طلب"
   فعلي بعد).
3. **إعادة تحقّق الأهلية تحت القفل** (الحالة قد تكون تغيّرت بين الفحص الأول
   وحصولنا على القفل فعلياً).
4. **توليد توكن خام وهاش جديدين** عبر `generate_delivery_token()` — **بلا
   أي كتابة على `invitation_token_hash`.** التوكن القديم يبقى سارياً تماماً
   كما هو حتى هذه اللحظة.
5. **بناء رابط القبول** من التوكن الجديد الخام (`build_acceptance_url()`)
   وتركيب نص الرسالة (`build_message()`).
6. **محاولة التسليم** عبر `PGE_Cartat_Transport::send_text()`.
7. **رفض/فشل نقل** (`interpret_result() !== 'accepted'`) → **لا** استبدال
   `invitation_token_hash` — التوكن القديم يبقى سارياً بالضبط كما كان، وتُسجَّل
   `delivery_failed` بفئة (`transport_error`/`provider_rejected`).
8. **قبول المزوّد فعلياً** → **الآن فقط** يُستبدَل الهاش عبر `commit_new_
   token_hash($id, $expected_status, $new_hash)` (شرط `WHERE id + status`
   ذري، نفس فلسفة `accept_invitation()`)، وتُسجَّل `provider_accepted`. تعارض
   تزامن نادر عند هذه الخطوة تحديداً (الحالة تغيّرت بين القبول والالتزام) →
   `delivery_failed` بفئة `token_commit_failed` (رسالة أُرسِلت فعلياً، لكن
   لا تراجع عنها — راجع §6).
9. **تحرير القفل** في كل مسار خروج (`finally`).

**لا حالة وسيطة يمكن أن ينتج عنها "توكن قديم مُبطَل + توكن جديد لم يُقبَل من
Cartat قط".**

### `generate_delivery_token()`/`commit_new_token_hash()`

إضافتان على `PGE_Supervisor_Assignment_Service` (الوسيط الوحيد المسموح به
للكتابة على `mon_event_supervisors`) — تفصلان التوليد (بلا كتابة) عن
الالتزام (كتابة ذرية شرطية)، بلا إعادة تعريف آلية التوليد/الهاش نفسها
(نفس `generate_invitation_token()`/`hash_invitation_token()` الخاصتين).
`resend_invitation()` القديمة أُعيدت كتابتها لاستدعاء هاتين الدالتين بدل
تكرار المنطق محلياً — **بلا أي تغيير في قيمها المُعادة** (`resent`/`error`/
`not_resendable`/`concurrent_status_change`)، فتبقى متوافقة خلفياً مع أي
مستدعٍ قائم؛ لكن مسار الإنتاج الفعلي (الإنشاء/إعادة الإرسال عبر الواجهة) لا
يستدعيها بعد الآن — يستدعي `deliver()` أعلاه حصراً.

---

## 5. مسار القبول الفعلي — `GET /supervisor/accept/{token}/`

`includes/routing.php` يضيف Rewrite Rule جديداً:

```
^supervisor/accept/([^/]+)/?$  →  index.php?pge_action=supervisor_accept_invitation&pge_token=$matches[1]
```

**"The route is only an HTTP adapter."** المعالج (`template_redirect`،
أولوية 1) لا يحتوي أي منطق قبول دعوة أو جلسة بنفسه:

1. تحقّق شكل التوكن محلياً (`pge_supervisor_accept_token_shape_valid()`،
   نمط `^[a-f0-9]{64}$` — بلا أي استعلام قاعدة بيانات، بلا أي تسجيل لقيمة
   التوكن في أي مكان).
2. استدعاء واحد لـ`PGE_Supervisor_Authenticator::authenticate($raw_token)`
   الموجودة والمعتمدة فعلياً (Phase 3/Blocker fix #3) — بلا إعادة تنفيذ أي
   من خطواتها الداخلية.
3. نجاح (`'authenticated'`) → كوكي جلسة (`PGE_Supervisor_Session::
   SESSION_COOKIE_NAME`، القيمة الخام فقط بلا أي معرِّف داخلي، `HttpOnly`
   دائماً، `Secure` عند HTTPS، `SameSite=Lax`، مسار الجذر، انتهاء = نفس
   `expires_at` المُعادة من `create_session()`) ثم `wp_safe_redirect(home_
   url('/supervisor/'))`.
4. فشل → `pge_supervisor_accept_classify_auth_error()` (دالة نقية، مُختبَرة
   مباشرة) تُترجِم النتيجة إلى إحدى ست صفحات خطأ RTL آمنة ودنيا (نفس البنية
   البصرية لـ`templates/supervisor-portal.php`) — راجع الجدول أدناه.

### تصنيف الفشل (ست حالات)

| # | الشرط | HTTP | ملاحظة |
|---|---|---|---|
| 1 | شكل التوكن غير صالح | 400 | رفض محلي بحت، بلا استعلام |
| 2 | `invalid_token` / `token_already_used_or_invalid` | 410 | توكن غير موجود أو استُهلِك مسبقاً |
| 3 | `assignment_not_acceptable` | 403 | الإسناد مُلغى/غير قابل للقبول |
| 4 | سبب `invitation`‑stage غير متوقَّع آخر | 500 | رسالة عامة آمنة، بلا كشف اسم السبب |
| 5 | `stage === 'session'` (فشل جزئي) | 500 | التوكن استُهلِك فعلياً؛ **لا** اقتراح "أعد فتح هذا الرابط" |
| 6 | فئات `PGE_Supervisor_Authenticator`/`PGE_Supervisor_Session` غير مُحمَّلة | 503 | فشل بيئة — رفض آمن (Fail Closed) |

**الفشل الجزئي (الحالة 5) يحافظ حرفياً على القاعدة الموثَّقة في
`class-pge-supervisor-authenticator.php`:** لا تراجع، لا إعادة توليد دعوة،
لا لمس لصف الإسناد بعد نجاح القبول. نقطة مصادقة مستقبلية قد تُصدِر جلسة
جديدة لنفس الإسناد النشط دون إعادة قبول الدعوة — غير مُنفَّذة بعد، خارج
نطاق هذا التنفيذ.

---

## 6. دورة التدقيق الصادقة (Audit Lifecycle)

```
delivery_requested → delivery_attempted → provider_accepted | delivery_failed
```

Append-only بالكامل عبر `PGE_Supervisor_Management_Audit::record()`
القائمة فعلاً (بلا أي عمود/جدول جديد — `action` عمود `VARCHAR(20)` حر
بالفعل، يتّسع للقيم الأربع الجديدة). **لا `'delivered'` إطلاقاً** (Cartat
لا يؤكد استلام الجهاز فعلياً، فقط قبول طلب الإرسال) **ولا `'invitation_
resent'`** بعد الآن (تسمية مضلِّلة لعملية لم تكن تُسلِّم شيئاً فعلياً قبل
هذا التنفيذ؛ نفس مبدأ استبدال `invitation_resent` بـ`delivery_requested`
المُعتمَد فعلاً لدعوات الضيوف في Phase 9B Final Fix).

**بيانات الصف المسموحة فقط:** `event_id`، `assignment_id`، `actor_user_id`،
`action`، `reason` (فئة فشل مُطبَّعة فقط — `missing_settings`/`transport_
error`/`provider_rejected`/`token_commit_failed`/`not_eligible`/...)،
`created_at`.

**بيانات ممنوعة صراحةً من أي صف تدقيق:** التوكن الخام، رابط القبول الكامل،
رقم الهاتف الكامل، نص الرسالة، جسم استجابة Cartat الخام. `error_log()` في
`PGE_Cartat_Transport` نفسه يسجِّل فقط الـEndpoint والاستجابة **المفكوكة من
Cartat** (لا التوكن، لا الرسالة الصادرة، لا ترويسة Authorization) — راجع
تدقيق ثابت في §8.

---

## 7. توافق دعوات الضيوف (Guest Cartat Compatibility)

الالتزام الصريح: **"After completion there must be one Cartat transport
implementation only."** مُحقَّق فعلياً — راجع §2. صفر تغيير سلوكي على أي
تدفّق ضيف قائم، مُثبَت تنفيذياً عبر ثلاثة ملفات اختبار مستقلة تمر جميعها:

| ملف الاختبار | ما يُغطّيه | النتيجة |
|---|---|---|
| `tests/test-cartat-credits-queue.php` | طابور الإرسال/الاعتماد/الأرصدة عبر `Mon_Cartat_Handler` | 43/43 |
| `tests/test-rsvp-write-path-unification.php` | تسجيل RSVP عبر Cartat/UltraMsg/الويب موحَّداً | 22/22 |
| `tests/test-replacement-entitlement-grant.php` | منح رصيد الاستبدال عبر `record_rsvp()` (Cartat) | 54/55 (فشل واحد سابق للتنفيذ، غير مرتبط) |

---

## 8. التدقيق الثابت (Static Audit)

فُحِصت النقاط التالية عبر `grep` مباشرة بعد التنفيذ (لا افتراض، لا تخمين):

1. لا دوال خاصة مكرَّرة متبقية في `Mon_Cartat_Handler`
   (`send_text_message`/`send_media_message`/`api_request`/`interpret_
   cartat_result`/`format_wa_number`) — صفر مطابقة.
2. `error_log()` في `PGE_Cartat_Transport` لا يسجِّل التوكن/الرسالة/رأس
   Authorization — فقط الـEndpoint والاستجابة المفكوكة.
3. `'invitation_resent'` لا يظهر في أي مسار كود فعلي بعد الآن (فقط داخل
   تعليقات توثيقية تشرح الاستبدال التاريخي).
4. `'delivered'` لا يظهر كقيمة `action` مكتوبة في أي مكان.
5. `includes/routing.php` لا يستدعي `accept_invitation()`/`create_
   session()` مباشرة — استدعاء واحد فقط لـ`PGE_Supervisor_Authenticator::
   authenticate()`.
6. `PGE_Supervisor_Invitation_Delivery::audit()` لا تُمرِّر أبداً هاتفاً/
   رسالة/رابطاً كقيمة `reason` — فئات نصية ثابتة فقط.
7. لا `wp_insert_user()`/`wp_create_user()`/`wp_set_current_user()` في أي
   من الملفات الثلاثة الجديدة (`class-pge-cartat-transport.php`،
   `class-pge-supervisor-invitation-delivery.php`، `routing.php`).
8. لا `wp_mail()`/إشارة لـUltraMsg داخل خدمة تسليم المشرفين — Cartat حصراً.
9. لا `wp_schedule_event()`/طابور/إعادة محاولة تلقائية في خدمة التسليم —
   محاولة واحدة متزامنة فقط لكل طلب (Scope Guard صريح، لا "queue" جديدة).

---

## 9. الاختبارات التنفيذية

`tests/test-supervisor-cartat-delivery.php` — 99 تحقّقاً تنفيذياً حقيقياً
(الحد الأدنى المطلوب: 45)، عبر خمسة أقسام:

- **أ (13):** `PGE_Cartat_Transport` — الاعتماد، تهيئة الرقم، تفسير النتيجة، مسارات الإرسال الفعلية.
- **ب (8):** `generate_delivery_token()`/`commit_new_token_hash()` — التوليد، الالتزام الناجح، تعارض التزامن، مدخلات غير صالحة.
- **ج (~40):** `PGE_Supervisor_Invitation_Delivery::deliver()` — كل بوابات الرفض المبكر (أهلية/مزوّد/اعتماد/قفل)، تسليم ناجح كامل (تدقيق + تدوير توكن + محتوى الرسالة)، فشل نقل، رفض المزوّد، تعارض التزامن عند الالتزام، مع تأكيد أن التوكن القديم يبقى سارياً في كل مسارات الفشل.
- **د (~19):** الدالتان النقيتان في `routing.php` — شكل التوكن، تصنيف رسائل الخطأ الست، غياب أي مصطلح تقني مسرَّب للمستخدم.
- **هـ (12):** تكامل كامل `deliver() ← PGE_Supervisor_Authenticator::authenticate()` — توكن مُسلَّم فعلياً يُصادِق بنجاح كامل (جلسة حقيقية تُنشَأ)، منع إعادة استخدامه، واستمرار صلاحية التوكن القديم بعد محاولة تسليم فاشلة عبر المصادقة الكاملة لا `accept_invitation()` فقط.

بلا أي اتصال شبكي حقيقي — `wp_remote_post()` دالة وهمية بالكامل، قابلة
للتحكم لكل سيناريو (نجاح/رفض صريح/`WP_Error`).

---

## 10. النطاق غير المُنفَّذ عمداً (Out of Scope)

- لا مزوّد ثانٍ (UltraMsg/SMS/بريد إلكتروني) لتسليم دعوات المشرفين.
- لا إعدادات Cartat جديدة — نفس المفاتيح الثلاثة القائمة حصراً
  (`pge_wa_provider`، `pge_cartat_api_token`، `pge_cartat_country_code`).
  إن لم يكن `pge_wa_provider === 'cartat'` → `provider_not_active` (خطأ
  عمل ثابت، لا تسليم صامت عبر مزوّد آخر).
- لا طابور/إعادة محاولة تلقائية — محاولة واحدة متزامنة فقط لكل طلب.
- لا انتهاء صلاحية زمني للتوكن (نفس قرار Option A المعتمَد سابقاً — Phase 2
  Blocker fix #2 — لم يتغيَّر هنا).
- لا حساب WordPress للمشرف بأي شكل.
- لا تعديل على الحضور/تسجيل الدخول (Check-in)/QR/إدارة الدعوات (Guest
  Invitations)/العمليات (Operations)/الحصة (Quota)/نموذج الهوية (Identity
  Model).
