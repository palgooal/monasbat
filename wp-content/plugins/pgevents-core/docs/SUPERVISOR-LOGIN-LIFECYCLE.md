# Supervisor Login Architecture (Post-Activation Login)

## 1. الغرض ولماذا هذه الميزة

تدفّق الدعوة الحالي (المضيف ← إنشاء مشرف ← تسليم دعوة عبر Cartat أو رابط يدوي
← قبول الدعوة ← المشرف يصبح `active` ← جلسة تُنشَأ) يعمل بشكل صحيح، لكن لا
يوجد مسار دخول مخصَّص لمشرف **نشط بالفعل** بعد تسجيل خروجه — الرابط الوحيد
المتاح تاريخياً هو رابط القبول (`/supervisor/accept/{token}/`)، وهو مصمَّم
لتفعيل الإسناد **مرة واحدة فقط**، لا كآلية دخول يومية متكرّرة.

هذه الميزة تُقدِّم **دورة حياة دخول مستقلة تماماً**، محفوظة جنباً إلى جنب مع
دورة حياة الدعوة الحالية دون أي تغيير عليها:

- **دورة الدعوة (Invitation)**: توثَّق بالكامل في
  `docs/SUPERVISOR-CARTAT-DELIVERY.md` و`docs/SUPERVISOR-MANUAL-INVITATION-LINK.md`
  — تُفعِّل الإسناد لأول مرة فقط، ثم تنتهي دورها للأبد.
- **دورة الدخول (Login)**: هذا المستند — تُنشئ جلسة جديدة لمشرف نشط بالفعل،
  لا تُغيِّر حالة الإسناد إطلاقاً، وقد تُستخدَم عدد غير محدود من المرات.

## 2. المعمارية — توكنان مستقلان تماماً على نفس الصف

كلا التوكنين يعيشان في نفس صف `wp_mon_event_supervisors`، لكنهما مستقلان
وظيفياً وعمودياً بالكامل:

| | `invitation_token_hash` (موجود مسبقاً) | `login_token_hash` (جديد) |
|---|---|---|
| الأهلية للتوليد | `invited`/`pending` فقط | `active` فقط |
| الأهلية للاستهلاك | `invited`/`pending` فقط | `active` فقط |
| يُغيِّر حالة الإسناد؟ | نعم — إلى `active` (مرة واحدة) | **لا، أبداً** |
| قابل لإعادة الاستخدام؟ | لا — Single-Use | نعم — يُولَّد عدد غير محدود من المرات |
| نقطة القبول/المصادقة | `/supervisor/accept/{token}/` | `/supervisor/login/{token}/` |
| الخدمة المسؤولة | `PGE_Supervisor_Manual_Link_Service` /
  `PGE_Supervisor_Invitation_Delivery` | `PGE_Supervisor_Login_Service` /
  `PGE_Supervisor_Login_Delivery` |
| طبقة المصادقة | `PGE_Supervisor_Authenticator` | `PGE_Supervisor_Login_Authenticator` |
| ينتهي دوره متى | فور القبول الناجح — للأبد | لا ينتهي — يُدوَّر باستمرار |

**لا طبقة واحدة تخلط بين التوكنين.** توليد رابط دخول لا يمسّ
`invitation_token_hash` إطلاقاً (والعكس صحيح)، ولا خدمة دخول تستدعي خدمة دعوة
أو العكس — "Never reuse invitation service" مُطبَّقة حرفياً على مستوى
التنسيق (orchestration)، مع إعادة استخدام مشروعة فقط للأدوات العامة المشتركة
فعلاً (`hash_invitation_token()`/التسمية تاريخية، `PGE_Supervisor_Session::
create_session()`، نمط القفل، نمط التدقيق).

## 3. Schema — عمود `login_token_hash` (DB_VERSION 1.13.0)

```sql
login_token_hash VARCHAR(64) NULL,
...
UNIQUE KEY login_token_hash (login_token_hash)
```

مضاف في `includes/class-mon-catalog-schema.php` بجانب `invitation_token_hash`
الموجود مسبقاً في نفس الجدول — عمود مستقل، فهرس `UNIQUE` مستقل، بلا أي تعديل
على `invitation_token_hash` أو أي عمود آخر. `upgrade_to_1_13_0()` (نفس فلسفة
`upgrade_to_1_11_0()` تماماً) يتحقق فعلياً عبر `SHOW COLUMNS`/`SHOW INDEX` من
وجود العمود والفهرس `UNIQUE` قبل اعتبار الترقية ناجحة — لا افتراض أعمى.

## 4. دورة حياة توكن الدخول — `PGE_Supervisor_Login_Service::generate()`

نفس فلسفة "Safe Commit Ordering" المعتمَدة في خدمتي الدعوة/الرابط اليدوي،
مطبَّقة بقفل واسم مستقل تماماً:

```
'pge_supervisor_login_' . md5((string) $assignment_id)
```

**قرار السياسة المعتمَد — Option A (تدوير فوري)**، كما هو الحال في الرابط
اليدوي:

1. `GET_LOCK` خاص بهذا الإسناد (مهلة 5 ثوانٍ، `RELEASE_LOCK` داخل `finally`).
2. إعادة قراءة الإسناد **تحت القفل**.
3. التحقق من الأهلية: `active` فقط — أي حالة أخرى تُرفَض بـ `not_eligible`.
4. توليد توكن خام وهاش جديدين، بناء رابط `/supervisor/login/{token}/`.
5. التزام الهاش الجديد ذرياً (`WHERE id + status='active'`) عبر
   `PGE_Supervisor_Assignment_Service::commit_new_login_token_hash()`.
6. نجاح الالتزام: التوكن السابق مُبطَل ضمنياً (نفس العمود استُبدِل)، تدقيق
   `login_link_generated`، إعادة الرابط مرة واحدة.
7. فشل الالتزام: `token_commit_failed` — لا رابط يُعاد، لا تدقيق نجاح.

**تدوير كل توليد**: بما أن `login_token_hash` عمود `UNIQUE` واحد لكل إسناد،
كتابة هاش جديد تستبدل القديم فعلياً — توكن واحد نشط فقط لكل إسناد في أي لحظة،
والرابط السابق يُرفَض فوراً بعد أي تدويرة جديدة (`invalid_token`، لا صف يطابق
الهاش القديم بعد الآن).

### قرار Option A مقابل Option B — لماذا هنا Option A رغم وجود Cartat؟

تسليم دعوة Cartat (`PGE_Supervisor_Invitation_Delivery`) يعتمد **Option B**:
لا يُبطَل التوكن القديم حتى *يقبل* Cartat الطلب فعلياً — لأن فشل تسليم دعوة
يعني أن المشرف **لن يحصل على أي رابط بديل تلقائياً** (لا زر "نسخ الرابط
اليدوي" مرتبط بنفس التوكن في تلك اللحظة)، فإبطال مبكر كان سيحرمه من أي مسار
دخول.

توكن الدخول مختلف جوهرياً: نصّ الـRFC صريح — "Each generation invalidates the
previous login token"، بلا اشتراط نجاح التسليم. وبخلاف الدعوة، فشل تسليم رابط
دخول عبر Cartat **ليس نهاية المسار** — `PGE_Supervisor_Login_Delivery::deliver()`
يُعيد الرابط نفسه (`login_url`) في نتيجة `generated_delivery_failed` للمستدعي
الداخلي (المضيف عبر واجهة الإدارة)، الذي يملك فوراً بديلاً: زر "نسخ رابط
الدخول" يعرض نفس الرابط المُلتزَم فعلاً للنسخ اليدوي. لذلك التزام فوري (Option
A) هنا لا يحرم أحداً من مسار بديل، ويطابق حرفياً النص الصريح للـRFC. **هذا
قرار تصميمي واعٍ، موثَّق في `PGE_Supervisor_Login_Service` نفسها.**

## 5. التسليم — `PGE_Supervisor_Login_Delivery::deliver()`

```
PGE_Supervisor_Login_Service::generate()   ← يُلتزَم أولاً (Option A)
        │ نجاح
        ▼
هل pge_wa_provider === 'cartat' و PGE_Cartat_Transport::has_credentials()؟
        │ نعم                              │ لا
        ▼                                  ▼
إرسال رسالة نصية مستقلة (نص             'generated_delivery_failed'
مختلف تماماً عن رسالة الدعوة)          (login_url يُعاد للمستدعي الداخلي
        │                                فقط — أبداً لا يُعرَض في استجابة
   'sent' / 'generated_delivery_failed'  الطلب الذاتي العام)
```

لا مسار ثانٍ لتوليد التوكن — `deliver()` يستدعي `generate()` فقط، ثم يتعامل
مع Cartat كطبقة تسليم اختيارية فوقه، مطابقاً لنفس نمط الفصل المعماري
(Service يولِّد/يلتزم، Delivery يعرف Cartat وحده).

## 6. المصادقة — `/supervisor/login/{token}/` (مسار مستقل عن القبول، GET/POST منفصلان)

```
includes/routing.php
  ^supervisor/login/([^/]+)/?$  →  pge_action = supervisor_login_authenticate
        │
        ├── GET  → pge_supervisor_login_evaluate_get_request($raw_token)
        │            │  PGE_Supervisor_Assignment_Service::peek_login_token()
        │            │     (SELECT بحت — بلا أي UPDATE)
        │            ▼
        │          صفحة تأكيد (templates/supervisor-login-confirm.php)
        │          أو صفحة خطأ آمنة — **بلا أي أثر جانبي على الإطلاق**
        │
        └── POST → pge_supervisor_login_handle_post_confirmation($raw_token, $nonce)
                     │  1. wp_verify_nonce($nonce, 'pge_supervisor_login_confirm')
                     │  2. pge_supervisor_accept_token_shape_valid($raw_token)
                     │  3. PGE_Supervisor_Login_Authenticator::authenticate($raw_token)
                     │       │  PGE_Supervisor_Assignment_Service::consume_login_token()
                     │       │     (يبحث في login_token_hash فقط)
                     │       │  نجاح: PGE_Supervisor_Session::create_session(...)
                     ▼
                   كوكي جلسة + تحديد وجهة (§6ج) + redirect فوري
                   (/supervisor/checkin/ أو /supervisor/) + exit
```

**"Do NOT reuse /supervisor/accept/{token}"** مُطبَّقة حرفياً: مسار منفصل
تماماً (`/supervisor/login/{token}/`، معالج `template_redirect` منفصل)،
معالِج مصادقة منفصل (`PGE_Supervisor_Login_Authenticator`، لا يستدعي ولا
يُستدعى من `PGE_Supervisor_Authenticator` الأصلي)، ودالة تصنيف أخطاء منفصلة
(`pge_supervisor_login_classify_auth_error()`)، مع إعادة استخدام مشروعة فقط
لأدوات عامة حقاً: `pge_supervisor_accept_token_shape_valid()` (فحص شكل توكن
64 حرف hex — لا علاقة له بأي جدول أو حالة) و`pge_render_supervisor_accept_
error()` (عارض HTML عام).

`consume_login_token()` (في `PGE_Supervisor_Assignment_Service`) يبحث حصراً
في `login_token_hash` — توكن دعوة صالح **لا يظهر إطلاقاً** في هذا البحث
(مخزَّن في عمود مختلف تماماً)، فيُرفَض دائماً بـ`invalid_token`. وبالمثل،
`accept_invitation()` الأصلية تبحث حصراً في `invitation_token_hash` — توكن
دخول صالح لا يمكنه تفعيل دعوة أبداً لنفس السبب.

## 6ب. Link Preview Safety Fix — لماذا يجب أن يكون GET غير هدّام

### التهديد: معاينات الروابط الآلية

روابط الدخول تُرسَل عادة عبر واتساب (Cartat) أو تُنسَخ يدوياً وتُلصَق في قناة
مراسلة. قبل أن يفتح المشرف الرابط فعلياً، عدة أطراف آلية قد تُرسِل طلب **GET**
لنفس الرابط دون علم المستخدم:

- **معاينات WhatsApp/تطبيقات المراسلة**: تجلب الرابط لعرض معاينة (OpenGraph
  metadata) فور وصول الرسالة، قبل أن يضغط أحد عليه إطلاقاً.
- **زواحف/فاحصات أمنية** (بعض حلول أمن الشركات تفحص الروابط الواردة تلقائياً
  قبل السماح بوصولها للمستخدم).
- **Prefetch من المتصفح** (بعض المتصفحات/الإضافات تجلب الروابط الظاهرة على
  الشاشة مسبقاً لتسريع التنقل).
- **برامج مكافحة الفيروسات** التي تفحص عناوين URL الواردة في الرسائل.

كان التصميم السابق يستهلك توكن الدخول (يُصفِّر `login_token_hash`، يُنشئ
جلسة) **فور وصول أي طلب GET** — أي طرف من الأطراف أعلاه كان قادراً على
استهلاك رابط الدخول الحقيقي الوحيد قبل أن يصل المشرف إليه فعلياً، فيرى رابطاً
"غير صالح" رغم أنه لم يفتحه من قبل.

### الحل: فصل "المعاينة" عن "الاستهلاك"

- **GET غير هدّام تماماً**: يتحقق فقط من صحة التوكن عبر
  `PGE_Supervisor_Assignment_Service::peek_login_token()` — استعلام `SELECT`
  بحت، بلا أي `UPDATE`. يعرض صفحة تأكيد (`templates/supervisor-login-
  confirm.php`) تطلب من المستخدم ضغط زر "الدخول إلى لوحة المشرف" صراحةً.
  معاينات آلية غير محدودة العدد لنفس الرابط لا تُغيِّر أي شيء في قاعدة
  البيانات ولا تستهلك التوكن أبداً.
- **POST هو الاستهلاك الوحيد**: لا يحدث الاستهلاك الفعلي (تصفير
  `login_token_hash`) ولا إنشاء الجلسة إلا عند إرسال نموذج POST حقيقي —
  والذي لا تُرسِله معاينات الروابط الآلية إطلاقاً (لا تملأ نماذج ولا تضغط
  أزراراً). النموذج نفسه محمي بـnonce وحيد الغرض
  (`pge_supervisor_login_confirm`) لمنع أي POST مُزوَّر من صفحة خارجية
  (CSRF)، بمعزل تام عن صلاحية التوكن نفسه.

هذا يجعل GET **idempotent بالكامل** (أي عدد من طلبات GET المتكررة لا يُغيِّر
حالة النظام) — خاصية أساسية في تصميم HTTP الصحيح، وتحديداً هنا: خاصية أمنية
حرجة تمنع أي طرف ثالث آلي من "حرق" رابط دخول لمرة واحدة نيابة عن المستخدم.

### توافق واتساب (WhatsApp Compatibility)

النتيجة العملية: رابط الدخول يعمل بشكل طبيعي تماماً عند إرساله عبر واتساب —
معاينة واتساب تفتحه (GET، بلا أثر)، ثم يضغط المشرف على الرابط في محادثته
(GET ثانٍ، لا يزال بلا أثر، يعرض صفحة التأكيد)، ثم يضغط زر "الدخول إلى لوحة
المشرف" (POST، الاستهلاك الفعلي الوحيد). لا حاجة لأي تغيير في طريقة الإرسال
عبر Cartat — الرسالة النصية والرابط نفسه بلا أي تعديل.

### التزامن (Concurrency)

- **GET لا يتنافس على استهلاك التوكن إطلاقاً** — لا قفل، لا معاملة كتابة، لا
  حتى إمكانية تعارض بنيوياً (استعلام `SELECT` بحت).
- **POST يستهلك ذرياً عبر شرط `WHERE` مزدوج** (نفس آلية `consume_login_
  token()` الأصلية، بلا أي تغيير عليها): `UPDATE ... WHERE id = ? AND
  login_token_hash = ?`. إن وصل طلبا POST متزامنَين لنفس التوكن، الأول الذي
  يصل لقاعدة البيانات ينجح ويُصفِّر الهاش، والثاني لا يجد صفاً يطابق الهاش
  القديم فيفشل بـ`token_already_used_or_invalid` — **نتيجة واحدة ناجحة فقط،
  دائماً**، بغضّ النظر عن ترتيب الوصول الفعلي أو عدد المحاولات المتزامنة.

## 6ج. Supervisor Login Redirect Fix — تحديد وجهة إعادة التوجيه بعد POST الناجح

### المشكلة المُبلَّغة

بعد نجاح POST (استهلاك التوكن + إنشاء الجلسة، §6 أعلاه)، طُلِب التأكد من أن
المتصفح **يغادر صفحة التأكيد فوراً وبلا احتمال عرضها مرة ثانية** — أي إعادة
توجيه واحدة فقط، بلا أي مسار يُعيد صفحة التأكيد بعد نجاح المصادقة.

مراجعة الكود الفعلي (`includes/routing.php`) أثبتت أن كل مسار في فرع POST كان
يُنهي التنفيذ بشكل صحيح فعلاً (إما `wp_safe_redirect()` متبوعاً بـ`exit;`
مباشرة عند النجاح، أو `exit;` غير مشروط داخل `pge_render_supervisor_accept_
error()` عند الفشل) — لا وجود لخلل تحكّم فعلي يُعيد عرض صفحة التأكيد. الفجوة
الحقيقية كانت: **وجهة إعادة التوجيه كانت ثابتة دوماً** (`/supervisor/` بلا أي
منطق تحديد)، بينما المطلوب فعلياً وجهة تعتمد على عدد إسنادات المشرف النشطة.

### الحل: `pge_supervisor_login_determine_redirect_target()`

دالة قرار جديدة، **قراءة فقط بالكامل**، تعيش حصراً في `includes/routing.php`
(بلا أي دالة/جدول/عمود جديد) — تُستدعى فقط داخل فرع POST الناجح، بعد إنشاء
الجلسة مباشرة وقبل `wp_safe_redirect()`:

```
pge_supervisor_login_determine_redirect_target($assignment_id)
  │
  ├── PGE_Supervisor_Assignment_Service::get_assignment_state($assignment_id)
  │     → استخراج supervisor_phone
  │
  ├── PGE_Supervisor_Assignment_Service::find_active_assignments_by_phone($phone)
  │     → عدّ كل الإسنادات active لنفس الهاتف عبر كل المناسبات
  │
  ├── عدد = 1  → /supervisor/checkin/  (تخطَّ شاشة الاختيار، دخول مباشر)
  └── غير ذلك (0 أو ≥2 أو أي حالة غامضة) → /supervisor/  (بوابة الاختيار، الافتراضي الآمن)
```

كلتا الدالتين المُستخدَمتين (`get_assignment_state()`، `find_active_
assignments_by_phone()`) موجودتان فعلاً في `PGE_Supervisor_Assignment_
Service` منذ Phase 2/Login Architecture — لا تعديل عليهما، لا استدعاء SQL
مباشر في `routing.php`.

### التقوية الدفاعية — تفريغ الـoutput buffering

أُضيف `while (ob_get_level() > 0) { ob_end_clean(); }` مباشرة قبل استدعاء
`wp_safe_redirect()` في فرع POST الناجح — إجراء وقائي صريح (لم يُثبَت أي خلل
فعلي متعلق به) يضمن عدم وجود أي إخراج HTML معلَّق قد يمنع صمتاً إرسال ترويسة
`Location` (حالة PHP معروفة: أي `echo`/مسافة بيضاء قبل `header()` تمنع إرسال
الترويسة). لا تغيير آخر على سياسة الكوكي أو منطق المصادقة.

### الضمان الساكن (مُثبَت باختبارات `test-supervisor-login-redirect-fix.php`)

فرع POST الناجح في `routing.php` يحتوي **استدعاءً واحداً بالضبط** لـ
`wp_safe_redirect()`، متبوعاً مباشرة بـ`exit;` بلا أي كود بينهما سوى تعليق،
و**لا يحتوي أي `require`/`include`** لقالب `supervisor-login-confirm.php` —
بنيوياً، لا مسار في فرع POST الناجح يمكنه إعادة عرض صفحة التأكيد بعد نجاح
المصادقة.

## 7. الأهلية (Eligibility)

| التوكن | الحالات المقبولة | الحالات المرفوضة |
|---|---|---|
| دعوة (`invitation_token_hash`) | `invited`, `pending` | `active`, `revoked`, `expired` |
| دخول (`login_token_hash`) | `active` فقط | `invited`, `pending`, `revoked`, `expired` |

مشرف بانتظار القبول (`invited`/`pending`) **لا يمكنه طلب رابط دخول إطلاقاً**
— `generate()` يرفضه بـ`not_eligible` قبل أي محاولة توليد. مشرف نشط يمكنه
طلب رابط دخول عدد غير محدود من المرات، ولا يمكنه استخدام رابط الدعوة القديم
(انتهى دوره للأبد فور القبول).

## 8. التفويض — جانب المضيف مقابل الطلب الذاتي

- **أزرار المضيف** (`إرسال رابط الدخول`/`نسخ رابط الدخول` في
  `/event-manage/{id}/supervisors/`): نفس تفويض بقية لوحة إدارة المشرفين —
  `pge_supervisor_mgmt_validate_request()` (nonce + تسجيل دخول +
  `pge_event_guests_user_can_manage()`) و`pge_supervisor_mgmt_load_owned_
  assignment()` (عزل مناسبات — إسناد يخصّ مناسبة أخرى يُرفَض بـ`not_found`
  بلا تسريب تمييز). Administrator يعمل أيضاً بصرف النظر عن الملكية.
- **الطلب الذاتي** (`/supervisor/login/` → `pge_supervisor_login_request`،
  `wp_ajax_nopriv`): **لا تفويض مطلوب أصلاً** — المشرف الذي يحتاج هذه الصفحة
  بالتعريف لا يملك جلسة قائمة ولا حساب WordPress بالضرورة. الحارس الوحيد هنا
  هو `nonce` (`pge_supervisor_login_request`) لمنع تلقائية عشوائية، ثم منع
  تعداد الأرقام (§9 أدناه).

## 9. الطلب الذاتي — `/supervisor/login/` ومنع Phone Enumeration

`templates/supervisor-login.php`: إذا كان للمشرف جلسة قائمة بالفعل
(`PGE_Supervisor_Portal_Middleware::authorize()` ناجحة)، إعادة توجيه فورية
إلى `/supervisor/checkin/` — لا حاجة لطلب دخول جديد. غير ذلك: نموذج جوال
واحد فقط.

`includes/supervisor-login-ajax.php`، `pge_supervisor_login_request_handler()`:
**الاستجابة الناجحة مطابقة حرفياً بصرف النظر عن نتيجة البحث** — سواء الرقم
غير مسجَّل إطلاقاً، مسجَّل كمشرف نشط في مناسبة واحدة، أو في عدة مناسبات، النص
المُعاد للمتصفح واحد بالضبط:

> "إذا كان هذا الرقم مسجَّلاً كمشرف نشط، ستصلك رسالة واتساب تحتوي رابط الدخول
> خلال لحظات."

لا فرع منطقي واحد في هذا المعالج يُنتج نصاً مختلفاً بناءً على وجود تطابق —
هذا إلزامي أمنياً: أي اختلاف (نص، زمن استجابة، حالة HTTP) بين "الرقم موجود"
و"الرقم غير موجود" يُمكِّن مهاجماً من اكتشاف أرقام هواتف مسجَّلة كمشرفين عبر
تجربة كل الأرقام الممكنة. لكل إسناد نشط مطابق فعلياً (عبر
`PGE_Supervisor_Assignment_Service::find_active_assignments_by_phone()`)،
تُكتَب `login_requested` ثم يُستدعى `PGE_Supervisor_Login_Delivery::deliver()`
— **نتيجة `deliver()` لا تؤثر إطلاقاً على الاستجابة المُعادة**، فقط تولِّد/
تُرسِل الرابط فعلياً في الخلفية.

## 10. واجهة المضيف — انتقال أزرار الحالة

`templates/event-supervisors.php`، `renderRows()`:

| حالة الإسناد | الأزرار المعروضة |
|---|---|
| `invited`/`pending` (`canResend`) | إعادة إرسال الدعوة، نسخ رابط الدعوة، إلغاء الدعوة |
| `active` (`canLogin`) | إرسال رابط الدخول، نسخ رابط الدخول، إلغاء إسناد المشرف |
| `revoked` وغيرها | لا أزرار دعوة ولا دخول |

**لا تُعرَض أزرار الدعوة لمشرف نشط بعد الآن** — الانتقال بين مجموعتَي الأزرار
تلقائي بالكامل حسب `row.status`، بلا حاجة لأي إجراء يدوي من المضيف. نص زر
"إلغاء" أصبح سياقياً (`revokeLabel`): "إلغاء الدعوة" لمشرف بانتظار القبول،
"إلغاء إسناد المشرف" لمشرف نشط — نفس الزر (`revoke-sup-btn`) ونفس الإجراء
الخلفي (`pge_supervisor_mgmt_revoke`) تماماً في الحالتين، تسمية عرض فقط، لا
منطق جديد.

النافذة الاحتياطية المشتركة لفشل النسخ التلقائي (`#manualLinkModal`) عُمِّمَت
لتُستخدَم لكلا نوعَي الرابط (`openManualLinkFallback(url, kindLabel)`) — لا
نافذة مكرَّرة، فقط عنوان ورسالة نجاح يتبعان `kindLabel` ('رابط الدعوة' أو
'رابط الدخول').

## 11. التدقيق (Audit)

جدول `wp_pge_supervisor_mgmt_audit_log` نفسه (append-only، بلا عمود/جدول
جديد). أحداث دورة الدعوة الحالية (`invitation_sent`، `invitation_accepted`،
"Manual invitation generated" أي `manual_link_generated`) بلا أي تغيير.
أحداث دورة الدخول الجديدة:

| الحدث | يُكتَب متى |
|---|---|
| `login_link_generated` | التزام توكن دخول جديد ناجح (`PGE_Supervisor_Login_Service::generate()`) |
| `login_requested` | طلب ذاتي وصل لإسناد نشط مطابق فعلياً (قبل محاولة التسليم) |
| `login_authenticated` | استهلاك توكن دخول ناجح + إنشاء جلسة ناجح |
| `login_failed` | استهلاك توكن دخول فاشل، **فقط عندما يُعرَف الإسناد/المناسبة بيقين** (لا تدقيق لتوكن غير مطابق لأي صف إطلاقاً — لا شيء لنُسنِد إليه الحدث) |
| `logout` | إبطال جلسة فعلي جديد فقط (`logged_out`) — لا يُكتَب عند طلب خروج مكرَّر على جلسة مُبطَلة أصلاً (`already_revoked`، لا ضجيج تدقيق) |

كل الأحداث append-only، بلا تعديل أو حذف على أي صف سابق.

**GET على `/supervisor/login/{token}/` لا يكتب أي حدث تدقيق إطلاقاً** — لا
`login_authenticated` (لم يحدث استهلاك)، ولا حدث "معاينة" جديد لم يكن موجوداً
أصلاً (لا داعٍ لتسجيل حركة معاينات آلية بلا أي أثر جانبي فعلي؛ إضافة حدث كهذا
كانت ستُنتج ضجيج تدقيق مضلِّلاً — سجل مليء بمحاولات "دخول" لم تحدث فعلياً).
`login_authenticated` يُكتَب **فقط** من داخل
`PGE_Supervisor_Login_Authenticator::authenticate()`، المُستدعاة حصراً من فرع
POST.

## 12. الأمان — ملخّص الضمانات

- توكن واحد نشط (`login_token_hash`) لكل إسناد في أي لحظة — كل توليد جديد
  يُدوِّر الذي قبله فوراً.
- التوكن الخام لا يُخزَّن في قاعدة البيانات إطلاقاً — فقط هاش `sha256()`.
- لا تخزين متصفح دائم (`localStorage`/`sessionStorage`) في أي مسار.
- لا توكن أو رابط مُقدَّم من العميل يُقبَل — كل شيء يُولَّد ويُلتزَم خلفياً فقط.
- لا تسجيل للتوكن الخام أو الرابط الكامل في أي `error_log()`/صف تدقيق.
- الطلب الذاتي بلا فرق ظاهري بين رقم مسجَّل وغير مسجَّل (§9) — منع Phone
  Enumeration مُطبَّق على مستوى النص، الحالة، وعدم فرع منطقي مختلف.
- عزل مناسبات كامل قبل أي استدعاء لخدمة الدخول (جانب المضيف) — إسناد يخصّ
  مناسبة أخرى يُرفَض بـ`not_found` بلا تسريب تمييز.
- نفس استراتيجية القفل المعتمَدة في بقية الميزة (`GET_LOCK`/`RELEASE_LOCK`
  ذري، اسم قفل مستقل بادئته `pge_supervisor_login_`، لا تنافس مع أقفال
  الدعوة/الرابط اليدوي).
- **GET على `/supervisor/login/{token}/` غير هدّام بالكامل (Link Preview
  Safety Fix)** — قراءة `SELECT` بحتة فقط، لا يمكن لمعاينات الروابط الآلية
  (WhatsApp، زواحف، Prefetch) استهلاك التوكن. راجع §6ب للتفصيل الكامل.
- **POST محمي بـnonce وحيد الغرض** (`pge_supervisor_login_confirm`) —
  مستقل تماماً عن أي nonce آخر في المشروع (لا `pge_supervisor_logout`، لا
  `pge_supervisor_login_request`). فشل التحقّق يُرفَض فوراً **قبل** أي
  استدعاء لـ`PGE_Supervisor_Login_Authenticator::authenticate()` — التوكن
  يبقى بلا أي لمس عند فشل nonce.

## 13. تسجيل الخروج — يدمّر الجلسة فقط

`PGE_Supervisor_Session::logout($raw_token)` — **بلا أي تغيير على سلوكها
الأصلي**: تبطل صف الجلسة في `mon_supervisor_sessions` فقط. لا تلمس أي عمود
في `mon_event_supervisors` — لا `status`، لا `login_token_hash`، لا
`invitation_token_hash`، لا `accepted_at`. المضيف يمكنه توليد رابط دخول جديد
فوراً بعد أي عملية logout (المشرف يبقى `active` طوال الوقت).

توسيع إضافي غير كاسر: عقد الإرجاع الآن يتضمّن `assignment_id`/`event_id`
(إضافة إلى الحقول الأصلية) — لتمكين معالج التوجيه من كتابة تدقيق `logout`
الصادق (§11) دون قراءة قاعدة بيانات ثانية. لا مستدعٍ سابق كان يقرأ هذين
الحقلين، فالتوسيع بلا أي أثر كاسر.

منطق الإبطال + التدقيق الشرطي استُخرِج إلى دالة مسمّاة مستقلة
(`pge_supervisor_process_logout_token()` في `includes/routing.php`) بدل بقائه
داخل الـ`closure` المباشر لـ`template_redirect` — لتكون قابلة للاختبار
التنفيذي المباشر، بلا أي تغيير في السلوك الفعلي (نقل حرفي للكتلة نفسها).

## 14. التوافق مع الإصدارات السابقة (Backward Compatibility)

**لم يتغيَّر أي شيء في**: قبول الدعوة (`accept_invitation()`،
`PGE_Supervisor_Authenticator`، مسار `/supervisor/accept/{token}/` بالكامل —
لا يزال يستهلك على GET مباشرة، تصميمه الأصلي المعتمَد، **خارج نطاق Link
Preview Safety Fix كلياً**)، تسليم دعوة Cartat
(`PGE_Supervisor_Invitation_Delivery`)، الرابط اليدوي للدعوة
(`PGE_Supervisor_Manual_Link_Service`)، إسناد المشرفين
(`PGE_Supervisor_Assignment_Service` — إضافات فقط، لا تعديل على الدوال
القائمة)، الحضور (Attendance/Check-in)، الإحصاء (Statistics)، الحصة (Quota)،
QR (لم تُضَف أي ميزة QR جديدة لهذا التصحيح ولا يوجد أي تعديل على QR الحضور
القائم)، حلّ هوية الضيف (Guest Resolution)، معمارية توكن الدخول نفسها (§2-§4 —
Option A، Eligibility، أعمدة Schema) بلا أي تغيير، تسليم Cartat لرابط الدخول
(§5).

**تاريخ وجهة إعادة التوجيه بعد نجاح POST (توثيقاً لتسلسل القرارات):**
كانت الوجهة في التصميم الأصلي (قبل Link Preview Safety Fix) ثابتة على
`/supervisor/checkin/`، ثم تغيَّرت إلى `/supervisor/` الثابتة (بوابة المشرف
الرسمية) ضمن Link Preview Safety Fix. **Supervisor Login Redirect Fix
(Post-Authentication UX)، §6ج أعلاه، هو آخر تغيير وينسخ ما قبله**: الوجهة الآن
ديناميكية — `/supervisor/checkin/` عند إسناد نشط واحد بالضبط لهاتف المشرف،
أو `/supervisor/` في أي حالة أخرى (بما فيها الحالة الافتراضية الآمنة)، عبر
`pge_supervisor_login_determine_redirect_target()` الجديدة. كل الإضافات
الأخرى في كل من التصحيحين إضافية بحتة (دوال قرار جديدة، قالب تأكيد جديد، عمود
لم يتغيَّر) — لا حذف ولا تعديل سلوكي على أي مسار آخر.

## 15. الاختبارات التنفيذية

- `tests/test-supervisor-login-lifecycle.php` (67 اختباراً): الأهلية (كل
  الحالات الخمس لتوليد الدخول)، استقلال التوكنين (توليد دخول لا يمسّ توكن
  الدعوة والعكس، توكن دعوة لا يعمل كتوكن دخول والعكس)، التدوير (رابط قديم
  يُرفَض بعد تدويرة جديدة، تسجيل دخول متكرر ناجح لمشرف نشط)، التسليم عبر
  Cartat (نجاح/رفض المزوّد/مزوّد غير نشط/غير مؤهَّل من الأساس)، معالِجات AJAX
  جانب المضيف (تفويض مالك/Administrator/غير مخوَّل، عزل مناسبات)، الطلب الذاتي
  (تطابق/عدم تطابق بنفس الرسالة، nonce)، تسجيل الخروج (يدمّر الجلسة فقط،
  تدقيق مرة واحدة، لا ضجيج عند التكرار)، وانحدار (تسليم الدعوة/الرابط
  اليدوي/القبول الأصلي كلها تعمل دون أي أثر).
- `tests/test-supervisor-login-preview-safety.php` (51 اختباراً — Link
  Preview Safety Fix، مُحدَّثة لاحقاً لـSupervisor Login Redirect Fix، راجع
  البند التالي): GET لا يستهلك التوكن (فحوصات مباشرة + تدقيق ساكن على
  جسم `pge_supervisor_login_evaluate_get_request()` يثبت غياب أي
  `setcookie`/`create_session`/`consume_login_token`/استدعاء المصادِق)، GET
  لا يغيِّر `login_token_hash` ولا ينشئ جلسة ولا يكتب `login_authenticated`،
  ثلاث طلبات GET متتالية تترك التوكن صالحاً، POST ينجح/يستهلك/ينشئ
  جلسة/يضبط معاملات كوكي آمنة (`httponly`/`samesite=Lax`/`secure`)/يستدعي
  محدِّد الوجهة الديناميكي ويمرِّر ناتجه مباشرة لـ`wp_safe_redirect()` (§6ج)،
  POST ثانٍ بنفس التوكن يفشل، حالات خطأ آمنة (توكن غير صالح/مُستخدَم/إسناد
  ملغى/nonce غير صالح أو مفقود) بلا أي استهلاك، **محاكاة معاينة WhatsApp
  صريحة** (ثلاث طلبات GET متتالية ثم POST حقيقي ينجح رغمها)، انحدار على
  تسليم Cartat/الرابط اليدوي (يعمل بنجاح عبر تدفّق GET→POST الجديد
  بالكامل)/قبول الدعوة الأصلي (بلا أي تعديل، تدقيق ساكن يثبت غياب أي
  `REQUEST_METHOD` في معالجه)، إثبات عدم إنشاء أي حساب WordPress
  (`wp_insert_user()` لم تُستدعَ ولو مرة)، وتدقيق ساكن إضافي (لا مسار دخول
  مكرَّر، لا أي إشارة QR في نطاق مسار الدخول، استدعاء واحد فقط للمصادِق عبر
  كامل الملف).
- `tests/test-supervisor-login-redirect-fix.php` (22 اختباراً — Supervisor
  Login Redirect Fix، §6ج): إعادة توجيه واحدة فعلية عند نجاح POST، الوجهة =
  `/supervisor/checkin/` لإسناد نشط واحد بالضبط لنفس الهاتف، الوجهة =
  `/supervisor/` لإسنادين نشطين فأكثر (بوابة الاختيار) وللحالات الغامضة
  (إسناد غير معروف)، تدقيق ساكن يثبت غياب أي `require`/`include` لقالب
  التأكيد ضمن فرع POST بالكامل، تفريغ الـoutput buffering قبل ترويسة
  إعادة التوجيه، استدعاء وحيد لـ`wp_safe_redirect()` متبوع مباشرة بـ`exit;`
  (لا إعادة توجيه مزدوجة)، فشل المصادقة لا يزال يعرض صفحة الخطأ الموجودة
  بلا تغيير، انحدار كامل على سلوك GET (المعاينة)/قبول الدعوة الأصلي/دورة
  تسجيل الدخول والخروج.
- تحديثات على اختبارات قائمة (تأكيدات تقادمت بسبب هذه الميزة، لا تراجع
  سلوكي): `tests/test-supervisor-schema.php` (+13 اختباراً لعمود
  `login_token_hash`/`upgrade_to_1_13_0()`)، `tests/test-supervisor-manual-
  link-ui.php` (تحديث 4 تأكيدات لتوقيع `openManualLinkFallback(url, kindLabel)`
  المعمَّم ونص زر الإلغاء الديناميكي)، `tests/test-supervisor-login-preview-
  safety.php` (تحديث تأكيد #11 من نص حرفي ثابت `wp_safe_redirect(home_url
  ('/supervisor/'))` إلى تحقّق من استدعاء `pge_supervisor_login_determine_
  redirect_target()` والوجهة الديناميكية الناتجة عنه — بسبب §6ج، لا تراجع
  سلوكي).

**نتيجة الانحدار الكامل عبر 19 ملف اختبار متعلق بالمشرفين (`test-supervisor-
*.php` + `test-rc1-fixpack*.php`): 1201 اختباراً، كلها ناجحة، صفر فشل.**

## 16. المسار خارج النطاق (Scope Guard) — لم يُنفَّذ عمداً

انتهاء صلاحية زمنية لتوكن الدخول (Time-To-Live)، حد أقصى لعدد التوليدات لكل
ساعة/يوم (Rate Limiting)، إشعار المضيف عند تسجيل دخول المشرف، سجل أجهزة/جلسات
متعددة نشطة لنفس المشرف في آنٍ واحد (Multi-Session)، "تذكرني" أو أي استمرارية
متصفح، تسليم عبر UltraMsg لهذه الميزة تحديداً، أو أي تعديل على انتهاء صلاحية
الجلسة نفسها (`mon_supervisor_sessions.expires_at`) — لا شيء من هذا لُمِس في
هذا التنفيذ. **ميزة QR لم تُضَف في Link Preview Safety Fix ولا في أي جزء من
هذا المستند** — لا صفحة تأكيد الدخول ولا أي مسار جديد يتضمّن أي منطق QR.
