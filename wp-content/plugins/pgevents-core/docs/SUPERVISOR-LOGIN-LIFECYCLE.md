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

## 6. المصادقة — `/supervisor/login/{token}/` (مسار مستقل عن القبول)

```
includes/routing.php
  ^supervisor/login/([^/]+)/?$  →  pge_action = supervisor_login_authenticate
        │
        ▼
PGE_Supervisor_Login_Authenticator::authenticate($raw_token)
        │  PGE_Supervisor_Assignment_Service::consume_login_token()
        │     (يبحث في login_token_hash فقط — لا علاقة بـinvitation_token_hash)
        │  نجاح: PGE_Supervisor_Session::create_session($assignment_id, $event_id)
        ▼
كوكي جلسة + redirect إلى /supervisor/checkin/
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
`PGE_Supervisor_Authenticator`)، تسليم دعوة Cartat
(`PGE_Supervisor_Invitation_Delivery`)، الرابط اليدوي للدعوة
(`PGE_Supervisor_Manual_Link_Service`)، إسناد المشرفين
(`PGE_Supervisor_Assignment_Service` — إضافات فقط، لا تعديل على الدوال
القائمة)، الحضور (Attendance/Check-in)، الإحصاء (Statistics)، الحصة (Quota)،
QR، حلّ هوية الضيف (Guest Resolution). كل الإضافات في هذه الميزة إضافية بحتة
(أعمدة جديدة، خدمات جديدة، مسارات جديدة، حقول إرجاع إضافية) — لا حذف ولا
تعديل سلوكي على أي مسار موجود.

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
- تحديثات على اختبارات قائمة (تأكيدات تقادمت بسبب هذه الميزة، لا تراجع
  سلوكي): `tests/test-supervisor-schema.php` (+13 اختباراً لعمود
  `login_token_hash`/`upgrade_to_1_13_0()`)، `tests/test-supervisor-manual-
  link-ui.php` (تحديث 4 تأكيدات لتوقيع `openManualLinkFallback(url, kindLabel)`
  المعمَّم ونص زر الإلغاء الديناميكي).

**نتيجة الانحدار الكامل عبر 17 ملف اختبار متعلق بالمشرفين (`test-supervisor-
*.php` + `test-rc1-fixpack*.php`): 1128 اختباراً، كلها ناجحة، صفر فشل.**

## 16. المسار خارج النطاق (Scope Guard) — لم يُنفَّذ عمداً

انتهاء صلاحية زمنية لتوكن الدخول (Time-To-Live)، حد أقصى لعدد التوليدات لكل
ساعة/يوم (Rate Limiting)، إشعار المضيف عند تسجيل دخول المشرف، سجل أجهزة/جلسات
متعددة نشطة لنفس المشرف في آنٍ واحد (Multi-Session)، "تذكرني" أو أي استمرارية
متصفح، تسليم عبر UltraMsg لهذه الميزة تحديداً، أو أي تعديل على انتهاء صلاحية
الجلسة نفسها (`mon_supervisor_sessions.expires_at`) — لا شيء من هذا لُمِس في
هذا التنفيذ.
