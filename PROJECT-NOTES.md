# دفتر ملاحظات مشروع مناسبات (Monasbat)

> **المرجع المركزي للقرارات الفنية وخطة العمل**
> آخر تحديث: 2026-04-28
> الفريق: شركة بال قول

هذا الملف هو **مصدر الحقيقة الوحيد** لكل ما نقرر العمل عليه على الإضافة `pgevents-core` والقالب `pgevents-pro`. عند إنجاز أي بند، حدّث الـ checkbox من `[ ]` إلى `[x]` وأضف تاريخ الإنجاز.

---

## جدول المحتويات

- [نظرة عامة على المشروع](#نظرة-عامة-على-المشروع)
- [هيكل المشروع](#هيكل-المشروع)
- [قائمة الملاحظات والإصلاحات](#قائمة-الملاحظات-والإصلاحات)
  - [حرجة (Critical)](#حرجة-critical)
  - [أمنية (Security)](#أمنية-security)
  - [معمارية (Architectural)](#معمارية-architectural)
- [متطلب سلة الجديد: تكامل App Store](#متطلب-سلة-الجديد-تكامل-app-store)
- [خارطة العمل (Roadmap)](#خارطة-العمل-roadmap)
- [مرجع سريع](#مرجع-سريع)

---

## نظرة عامة على المشروع

منصة ووردبريس لإدارة دعوات المناسبات (أعراس، فعاليات) مع:

- **نظام RSVP** للضيوف (سيحضر / يعتذر / عدد المرافقين)
- **تسجيل دخول الضيوف** بالجوال + كود الدعوة (Access Gate)
- **نظام Check-in** بالجوال يوم المناسبة
- **4 باقات** بحدود مختلفة (مدعوين، مناسبات، صور، رسائل واتساب، ميزات)
- **تكامل مالي مع متجر سلة** — Webhook يُفعّل الباقات تلقائياً بعد الدفع

### المكونات الرئيسية

| المكون | الدور |
|--------|------|
| `wp-content/plugins/pgevents-core` | المحرك البرمجي (CPT, AJAX, Webhooks, Logic) |
| `wp-content/themes/pgevents-pro` | الواجهة المرئية (Tailwind + Elementor) |
| Elementor + Pro | بناء الصفحات + 3 ودجات مخصصة |
| متجر سلة (خارجي) | بوابة الدفع وتفعيل الباقات |

---

## هيكل المشروع

### المسارات المخصصة (Routing)

| المسار | الوظيفة |
|--------|---------|
| `/dashboard/` | لوحة تحكم المضيف (KPIs ومناسباته) |
| `/create-event/` | إنشاء مناسبة (مع فحص الكوتا) |
| `/edit-event/{id}/` | تعديل مناسبة |
| `/event-manage/{id}/` | إدارة المدعوين والـ RSVP |
| `/login/` | تسجيل الدخول (إيميل/جوال) |
| `/register/` | إنشاء حساب |
| `/forgot-password/` | استعادة كلمة المرور |
| `/event/{slug}/` | عرض المناسبة (للضيوف، خلف Access Gate) |

### تدفق العمل

1. العميل يدفع في **متجر سلة** → Webhook يصل إلى `mon/v1/salla-callback`
2. النظام يُنشئ الحساب تلقائياً ويُفعّل الباقة (الحدود + الميزات)
3. يرسل بريد ترحيب مع رابط تعيين كلمة المرور
4. العميل يدخل `/login/` → `/dashboard/` يرى الكوتا المتبقية
5. ينشئ مناسبة `/create-event/` (مع تطبيق صلاحيات الباقة)
6. يدير المدعوين عبر `/event-manage/{id}/`
7. الضيف يفتح رابط المناسبة → Access Gate (جوال + كود) → RSVP
8. يوم المناسبة: المضيف يستخدم Check-in بالجوال

### REST/AJAX Endpoints

**REST (Webhooks):**
- `POST /wp-json/mon/v1/salla-callback` — استقبال webhook من سلة
- `POST /wp-json/mon-events/v1/salla-webhook` — endpoint بديل (غير مستخدم حالياً)

**AJAX (Events):**
- `pge_create_new_event`, `pge_handle_event_update`, `pge_archive_event`, `pge_event_set_invite_code`

**AJAX (RSVP/Check-in):**
- `pge_rsvp_submit`, `pge_checkin_submit`, `pge_checkin_guest`

**AJAX (Guests):**
- `pge_event_guest_add/update/delete`, `pge_event_guest_bulk_add/delete`

**AJAX (Profile):**
- `pge_update_user_profile`

### مفاتيح البيانات

**Custom Post Type:** `pge_event`

**Custom Table:** `wp_pge_event_rsvps` (يخزن RSVP والـ check-ins)

**Post Meta:**
`_pge_event_date`, `_pge_event_location`, `_pge_host_phone`, `_pge_invite_code`,
`_pge_invited_phones`, `_pge_invited_guests`, `_pge_rsvp_map`, `_pge_checkins`

**User Meta:**
`pge_bio`, `pge_cover_url`, `pge_current_plan`, `_mon_package_key`,
`_mon_package_status`, `_mon_events_limit`, `_mon_guest_limit`, `_mon_active_features`

**Options:**
`mon_packages_settings` (إعدادات الباقات الـ4)، `pge_rewrite_version`

---

## قائمة الملاحظات والإصلاحات

### حرجة (Critical)

> هذه الملاحظات تُعطل وظائف فعلية أو تتسبب في خسارة بيانات. **يجب البدء بها.**

#### [x] #1 — تناقض اسم جدول RSVP بين الإنشاء والكتابة ✅ 2026-04-28

**الملف:** `wp-content/plugins/pgevents-core/includes/rsvp-handler.php`

**المشكلة:** دالة `pge_create_rsvp_table` تنشئ جدول `wp_pge_rsvp` لكن دالة `pge_rsvp_submit` تكتب في `wp_pge_event_rsvps`. النتيجة: **الجدول المُنشأ فارغ دائماً، وردود RSVP لا تُحفظ في DB**.

**الحل:**
- وحّد الاسم على `wp_pge_event_rsvps` في كل المراجع
- احذف الجدول القديم `wp_pge_rsvp` من phpMyAdmin
- أعد تفعيل الإضافة (Deactivate ثم Activate)
- أضف سكربت ترحيل من `_pge_rsvp_map` إلى الجدول الجديد إن لزم

```php
// قبل:
$table_name = $wpdb->prefix . 'pge_rsvp';        // عند الإنشاء
$table = $wpdb->prefix . 'pge_event_rsvps';      // عند الكتابة

// بعد:
$table = $wpdb->prefix . 'pge_event_rsvps';      // في كل مكان
```

---

#### [x] #2 — ازدواجية معالج AJAX `pge_checkin_guest` ✅ 2026-04-28

**الملف:** `includes/checkin-handler.php` و `includes/ajax.php`

**المشكلة:** نفس الـ AJAX action مُسجّل في ملفين، WordPress ينفذ الاثنين بالترتيب وقد يحدث `headers already sent` أو سلوك مزدوج.

**الحل:**
- احتفظ بنسخة `ajax.php` (الأشمل)
- احذف `checkin-handler.php` بالكامل
- احذف سطر `include_once PGE_PATH . 'includes/checkin-handler.php';` من `pgevents-core.php`

---

### أمنية (Security)

#### [ ] #3 — حقل النبذة `pge_bio` بدون تنظيف (XSS Stored)

**الملف:** `wp-content/plugins/pgevents-core/includes/user-profiles.php`

**المشكلة:** `update_user_meta($user_id, 'pge_bio', $_POST['pge_bio'])` بدون أي sanitize. مستخدم مسجل يستطيع حقن سكربت يُنفَّذ عند زيارة ملفه.

**الحل:**
```php
update_user_meta(
    $user_id,
    'pge_bio',
    sanitize_textarea_field(wp_unslash($_POST['pge_bio']))
);
```

---

#### [ ] #4 — سر Webhook مكتوب hardcoded

**الملف:** `class-salla-handler.php`

**المشكلة:** `private $webhook_secret = '60ae23...'` ظاهر في الكود + موجود في نسخ ZIP داخل `wp-content`.

**الحل:**
```php
// في wp-config.php:
define('PGE_SALLA_WEBHOOK_SECRET', 'your-secret-here');

// في الكلاس:
$this->webhook_secret = defined('PGE_SALLA_WEBHOOK_SECRET')
    ? PGE_SALLA_WEBHOOK_SECRET
    : (string) get_option('pge_salla_webhook_secret', '');
```

**مهم:** بعد النقل، **غيّر السر القديم في لوحة سلة** لأنه مسرّب في النسخ الاحتياطية.

---

#### [x] #5 — دالة التحقق من توقيع Webhook غير مستدعاة (حرجة) ✅ 2026-04-28

**الملف:** `class-salla-handler.php`

**المشكلة:** الدالة `is_valid_signature()` معرّفة لكن غير مستدعاة. أي طلب POST إلى `mon/v1/salla-callback` يُقبل بدون تحقق → أي شخص يعرف الـ endpoint يستطيع تفعيل باقات وهمية.

**الحل:**
```php
public function handle_salla_notification($request) {
    $payload   = $request->get_body();
    $signature = $request->get_header('x_salla_signature');

    if (!$this->is_valid_signature($payload, $signature)) {
        return new WP_REST_Response(['error' => 'Invalid signature'], 401);
    }
    // ... باقي المنطق
}
```

كذلك: احذف method `GET` من `register_rest_route` (webhook حقيقي يجب أن يكون POST فقط).

---

#### [x] #9 — نسخ ZIP داخل `wp-content` قابلة للتنزيل ✅ 2026-04-28 (htaccess + حذف جميع الـ ZIPs يدوياً)

**الملفات:**
- `wp-content/themes/pgevents-pro*.zip` (4 نسخ)
- `wp-content/plugins/backup-dev/pgevents-core*.zip` (4 نسخ)

**المشكلة:** Apache/Nginx قد يخدمها مباشرة عبر URL → كشف الكود المصدري.

**الحل:**
1. انقل الـ ZIPs خارج `public_html` (مثلاً `~/monasbat-backups/`)
2. أضف في `.htaccess`:
```apache
<FilesMatch "\.(zip|tar|gz|bak|sql|env)$">
    Require all denied
</FilesMatch>
```
3. فعّل نظام نسخ احتياطية احترافي (UpdraftPlus) بدلاً من ZIPs يدوية.

---

#### [ ] #11 — فحص ضعيف للصلاحيات في `pge_rsvp_submit`

**الملف:** `rsvp-handler.php`

**المشكلة:** الجوال يؤخذ من cookie مباشرة دون توقيع. أي شخص يضع cookie بأي رقم من قائمة المدعوين يستطيع تزوير RSVP.

**الحل:** عند مرور الضيف من Access Gate، احفظ في الـ cookie قيمة موقّعة بـ `wp_hash` تربط: الجوال + event_id + timestamp. تحقق من التوقيع قبل قبول الـ cookie.

---

### معمارية (Architectural)

#### [ ] #6 — ازدواجية كلاسات Salla

**الملفات:** `class-salla-handler.php` و `class-mon-salla-api.php`

**المشكلة:** `Mon_Salla_API` غير مستدعى في `pgevents-core.php` (كود ميت) وينسخ منطق `Mon_Salla_Handler` بنسبة 80%.

**الحل:** احذف `class-mon-salla-api.php` بالكامل.

---

#### [ ] #7 — ملف `helpers.php` فارغ ومستدعى

**الملف:** `includes/helpers.php` (0 byte)

**الحل:** املأه بالدوال المساعدة المتكررة (انظر #8) أو احذفه نهائياً.

---

#### [ ] #8 — تكرار الدوال المساعدة

**الملفات:** `rsvp-handler.php`, `event-guests.php`, `single-pge_event.php` (في القالب!)

**المشكلة:** دالة تطبيع رقم الجوال مكررة بثلاثة أسماء:
- `pge_event_guests_norm_phone` في الإضافة
- `pge_norm_phone` في القالب
- `preg_replace` inline في rsvp-handler

نفس الكلام مع `pge_normalize_invite_code` و `pge_norm_invite_code`.

**الحل:**
1. أنشئ `includes/helpers.php` يحوي: `pge_norm_phone`, `pge_normalize_invite_code`, `pge_generate_invite_code`
2. احذف التعريفات المكررة
3. القالب يستخدم دوال الإضافة (لا يكرر تعريفها)

---

#### [ ] #10 — `flush_rewrite_rules` مكرر بطرق مختلفة

**الملف:** `pgevents-core.php`

**المشكلة:** flush في init action + activation hook + deactivation hook = استهلاك أداء غير مبرر.

**الحل:** احتفظ بـ flush فقط في activation/deactivation hooks. احذف الـ init flush.

---

#### [ ] #12 — `single-event.php` يحتوي بيانات وهمية

**الملف:** `wp-content/themes/pgevents-pro/single-event.php`

**المشكلة:** قالب تجريبي قديم بـ "مناسبة زواج فلان" و via.placeholder.com. الصفحة الفعلية هي `single-pge_event.php`.

**الحل:** احذف الملف نهائياً (لا يستخدمه CPT `pge_event`).

---

## متطلب سلة الجديد: تكامل App Store

### السياق

في اجتماع zoom طلبت إدارة سلة من فريق بال قول إضافة معالجة لحدث **`app.store.authorize`** قبل تفعيل التطبيق على متجر تطبيقات سلة (App Store)، وذلك لأن سلة تُحدّث الـ access token تلقائياً **كل 14 يوم** وترسله عبر نفس الحدث.

### إعدادات التطبيق على Salla Partner Portal

| البند | القيمة |
|------|--------|
| Webhook URL المسجَّل | `https://hilwah.net/wp-json/mon/v1/salla-callback` |
| نمط المصادقة | **Easy Mode** |
| نمط حماية التنبيهات | **Signature** (HMAC-SHA256) |
| Client ID | محفوظ في `wp-config.php` كـ `PGE_SALLA_CLIENT_ID` (لا يُكتب في git) |
| Client Secret | محفوظ في `wp-config.php` كـ `PGE_SALLA_CLIENT_SECRET` (لا يُكتب في git) |
| Webhook Secret | محفوظ في `wp-config.php` كـ `PGE_SALLA_WEBHOOK_SECRET` (مختلف عن Client Secret) |

> **مهم:** Client Secret و Webhook Secret كيانان مختلفان في سلة. Client Secret يُستخدم لتبادل OAuth code (في Custom Mode فقط)، بينما Webhook Secret يُستخدم لحساب توقيع HMAC للـ webhooks. كلاهما يجب أن يكون في `wp-config.php` خارج git.

### الـ Scopes المختارة على Partner Portal

| الصلاحية | الإذن | مستخدم في الكود؟ |
|----------|-------|-------------------|
| البيانات الأساسية | قراءة فقط | ✅ للتحقق من المتجر |
| العملاء | قراءة فقط | للتحقق من الطلبات (إذا أُضيف Merchant API client مستقبلاً) |
| الطلبات | قراءة فقط | للتحقق من الطلب الحقيقي بعد الـ webhook |
| السلات | قراءة وتعديل | ⚠️ غير مستخدم — يُنصح بإزالته (Least Privilege) |
| ويب هوك | قراءة وتعديل | لتسجيل/تعديل اشتراكات webhook |

**[ ] مهمة إضافية:** بعد التنفيذ، الدخول لـ Partner Portal وإلغاء اختيار "السلات" من الصلاحيات.

### تنبيه أمني (Credentials Rotation)

> Client ID و Client Secret الحاليان شُورِكا في محادثة تطوير. **بعد إنهاء التنفيذ والاختبار**، يجب الضغط على "توليد مفتاح جديد" في Salla Partner Portal لإعادة توليد Client Secret، ثم تحديث `wp-config.php` بالقيمة الجديدة.

**[ ] بعد إنهاء المراحل** — تجديد Client Secret من Partner Portal.

### النمط المستخدم: Easy Mode

في هذا النمط، سلة تتولى توليد التوكن وترسله للتطبيق عبر Webhook، فلا حاجة لتنفيذ OAuth flow كامل من جانبنا.

### Payload المتوقع من سلة

```json
{
  "event": "app.store.authorize",
  "merchant": 1234509876,
  "created_at": "2026-04-28 12:31:25",
  "data": {
    "access_token": "KGsnBcNNkR2AgHnrd0U9lCIjrUiukF_-Fb8OjRiEcog.NuZv_mJaB46jA2OHa...",
    "expires": 1634819484,
    "refresh_token": "fWcceFWF9eFH4yPVOCaYHy-UolnU7iJNDH-dnZwakUE.bpSNQCNjbNg6hT...",
    "scope": "settings.read branches.read offline_access",
    "token_type": "bearer"
  }
}
```

### تفاصيل التوكنات

| الحقل | المدة | ملاحظة |
|-------|------|--------|
| `access_token` | 14 يوم | يُجدد تلقائياً ويصلنا في حدث جديد |
| `refresh_token` | شهر | single-use — كل استخدام يُلغي القديم |
| `expires` | unix timestamp | تاريخ انتهاء الـ access_token |

> **تحذير من توثيق سلة:** استخدام نفس `refresh_token` بشكل متوازٍ من عمليات متعددة قد يؤدي لفشل المصادقة كلياً.

### المشكلة في الكود الحالي

الـ handler الموجود في `class-salla-handler.php::handle_salla_notification` يفلتر بـ `status.slug` (مخصص لأحداث الطلبات `completed/delivered`). عند وصول event `app.store.authorize` الذي **لا يحتوي حقل `status` أصلاً**، سيُرفض الطلب صامتاً ويرد بـ `"No Data"` ولن يحفظ التوكن.

### خطة التنفيذ المقترحة

#### [ ] S1 — التمييز بين أنواع الـ events في الـ webhook handler

في `handle_salla_notification`، أضف switch على `$data['event']`:

```php
$event = $data['event'] ?? '';
$payload = $data['data'] ?? [];

switch ($event) {
    case 'order.created':
    case 'order.updated':
    case 'order.payment.updated':
        return $this->handle_order_event($payload);

    case 'app.store.authorize':
        return $this->handle_app_authorize($payload, $data['merchant'] ?? 0);

    case 'app.installed':
        return $this->handle_app_installed($payload, $data['merchant'] ?? 0);

    case 'app.store.uninstall':
    case 'app.uninstalled':
        return $this->handle_app_uninstalled($data['merchant'] ?? 0);

    case 'app.updated':
        return $this->handle_app_updated($payload, $data['merchant'] ?? 0);

    default:
        return new WP_REST_Response(['ignored' => $event], 200);
}
```

#### [ ] S2 — معالج `app.store.authorize` يحفظ التوكنات

```php
private function handle_app_authorize($data, $merchant_id) {
    $tokens = [
        'access_token'  => $data['access_token']  ?? '',
        'refresh_token' => $data['refresh_token'] ?? '',
        'expires'       => (int) ($data['expires'] ?? 0),
        'scope'         => $data['scope']         ?? '',
        'token_type'    => $data['token_type']    ?? 'bearer',
        'updated_at'    => current_time('mysql'),
    ];

    update_option('pge_salla_tokens_' . (int)$merchant_id, $tokens);

    // سجل في log للتتبع
    error_log("✅ Salla tokens saved for merchant: $merchant_id");

    return new WP_REST_Response(['status' => 'authorized'], 200);
}
```

#### [ ] S3 — معالجات `app.installed` / `app.uninstalled` / `app.updated`

```php
private function handle_app_installed($data, $merchant_id) {
    update_option('pge_salla_install_' . $merchant_id, [
        'installed_at' => current_time('mysql'),
        'data'         => $data,
    ]);
    return new WP_REST_Response(['status' => 'installed'], 200);
}

private function handle_app_uninstalled($merchant_id) {
    delete_option('pge_salla_tokens_' . $merchant_id);
    delete_option('pge_salla_install_' . $merchant_id);
    return new WP_REST_Response(['status' => 'uninstalled'], 200);
}

private function handle_app_updated($data, $merchant_id) {
    // فقط سجل، لا حاجة لإجراء — التوكن سيصل بعدها في app.store.authorize
    error_log("ℹ️ Salla app updated for merchant: $merchant_id");
    return new WP_REST_Response(['status' => 'noted'], 200);
}
```

#### [ ] S4 — صفحة إدارية لمراقبة المتاجر المربوطة

في `admin-mods.php` أضف صفحة فرعية تحت `PgEvents` تعرض جدول:

| Merchant ID | تاريخ التثبيت | تاريخ آخر تحديث توكن | متى ينتهي | الحالة |
|-------------|---------------|----------------------|-----------|--------|

تستخرج البيانات بـ `wp_options` LIKE `'pge_salla_tokens_%'`.

#### [ ] S5 — تفعيل التحقق من signature (إلزامي — الملاحظة #5)

> **هذه الخطوة لم تعد اختيارية.** بما أن نمط الحماية المُختار في Partner Portal هو **Signature**، فإن سلة سترسل header اسمه `x-salla-signature` مع كل webhook، وعلينا التحقق منه قبل قبول الطلب. بدون التحقق:
> - أي شخص يستطيع تزوير `app.store.authorize` ويسرق هوية متجر
> - التطبيق قد لا يجتاز مراجعة سلة قبل النشر

**خوارزمية التحقق:**

```php
private function is_valid_signature($payload, $signature) {
    if (empty($signature)) return false;
    $computed = hash_hmac('sha256', $payload, $this->webhook_secret);
    return hash_equals($computed, $signature);
}

// في handle_salla_notification (السطر الأول):
$payload   = $request->get_body();
$signature = $request->get_header('x_salla_signature');

if (!$this->is_valid_signature($payload, $signature)) {
    return new WP_REST_Response(['error' => 'Invalid signature'], 401);
}
```

**ملاحظة:** `webhook_secret` يجب أن يأتي من `PGE_SALLA_WEBHOOK_SECRET` في `wp-config.php`، وهو **مختلف** عن `Client Secret` و **مختلف** عن السر القديم المكتوب hardcoded في الكود الحالي.

#### [ ] S6 — اختبار شامل

- [ ] تثبيت التطبيق على متجر تجريبي → تأكد من حفظ التوكنات
- [ ] انتظار 14 يوم (أو محاكاة الحدث) → تأكد من تحديث التوكن
- [ ] إزالة التطبيق → تأكد من مسح التوكنات
- [ ] إرسال webhook بـ signature خاطئ → يجب أن يُرفض

### ما لم يُطلب الآن (لكن قد نحتاجه لاحقاً)

- **Salla Merchant API client** مع Rate Limiting (60/120/180 طلب/دقيقة حسب باقة المتجر)
- **Leaky Bucket algorithm** + معالجة 429 + `Retry-After`
- **Cache طبقي** للهيدرز `X-RateLimit-*` لتفادي تجاوز الحد
- استخدامات محتملة: التحقق من الطلب بعد الـ Webhook، جلب تفاصيل العميل، نشر منتجات

---

## خارطة العمل (Roadmap)

### ~~المرحلة 1~~ — إصلاحات حرجة ✅ مكتملة بالكامل 2026-04-28

- [x] #1 — توحيد اسم جدول RSVP ✅
- [x] #2 — حذف `checkin-handler.php` المكرر ✅
- [x] #5 — تفعيل التحقق من signature ✅
- [x] #9 — إزالة جميع ZIPs من wp-content + htaccess ✅
- [x] إدارة Webhook Secret من لوحة التحكم ✅
- [x] حذف مجلد `backup-dev` يدوياً ✅
- [x] Deactivate/Activate الإضافة لتجديد هيكل جدول RSVP ✅
- [x] حفظ Webhook Secret الجديد في لوحة التحكم ✅
- [x] إعادة توليد Webhook Secret من Salla Partner Portal ✅

### المرحلة 2 — تكامل سلة App Store

- [x] S1 — switch على نوع الحدث في `handle_salla_notification` ✅ 2026-04-28
- [x] S2 — معالج `handle_app_authorize` يحفظ التوكنات في DB ✅ 2026-04-28
- [x] S3 — معالجات `app.installed` / `app.uninstalled` / `app.updated` ✅ 2026-04-28
- [x] S4 — صفحة إدارية لمراقبة المتاجر المربوطة ✅ 2026-04-28
- [x] S5 — إضافة `PGE_SALLA_CLIENT_ID/SECRET` في `wp-config.php` ✅ 2026-04-28
- [x] إدارة Client ID/Secret من لوحة التحكم (DB) ✅ 2026-04-28
- [ ] S6 — اختبار شامل على متجر تجريبي
- [ ] إزالة scope "السلات" غير المستخدم من Partner Portal
- [ ] **بعد الاختبار:** تجديد Client Secret من Partner Portal

### المرحلة 3 — تشديد الأمان (يومان)

- [x] #3 — تنظيف `pge_bio` بـ sanitize_textarea_field ✅ 2026-04-29
- [x] #4 — مفاتيح سلة تُدار من لوحة التحكم (DB) ✅ 2026-04-28
- [x] #11 — HMAC على cookie الضيف (access-gate + rsvp-handler + rsvp.php) ✅ 2026-04-29
- [x] مراجعة AJAX: إضافة nonce لـ pge_checkin_submit ✅ 2026-04-29

### المرحلة 4 — تنظيف معماري (3-5 أيام)

- [x] #6 — تعطيل `class-mon-salla-api.php` (→ .disabled) ✅ 2026-04-29
- [x] #7 + #8 — `helpers.php` حقيقي يحوي pge_norm_phone / pge_normalize_invite_code / pge_generate_invite_code / pge_is_host_or_admin ✅ 2026-04-29
- [x] #10 — حذف init flush المكرر (يبقى فقط activation/deactivation) ✅ 2026-04-29
- [x] #12 — تعطيل `single-event.php` (→ .disabled) ✅ 2026-04-29
- [ ] إعادة تنظيم AJAX endpoints في كلاس واحد

### المرحلة 5 — تحسينات اختيارية (لاحقاً)

- [ ] اختبارات وحدة (PHPUnit) لمنطق الباقات والكوتا
- [ ] `CLAUDE.md` يشرح المعمارية للمطورين الجدد
- [ ] تحويل `event-factory.php` إلى كلاس
- [ ] Logging مركزي بدلاً من `error_log` المتفرق
- [ ] Salla Merchant API client مع Rate Limiting (إن احتجناه)

---

## مرجع سريع

### ملفات الإضافة (`pgevents-core/includes/`)

| الملف | الوظيفة | حالة |
|-------|---------|------|
| `cpts.php` | تسجيل CPT `pge_event` | ✅ |
| `metaboxes.php` | حقول إدارة المناسبة | ✅ |
| `user-profiles.php` | حقول إضافية في الملف الشخصي | ⚠️ #3 |
| `routing.php` | نظام التوجيه الافتراضي | ✅ |
| `rsvp-handler.php` | إنشاء جدول + AJAX RSVP | 🔴 #1 |
| `event-factory.php` | AJAX إنشاء/تعديل/أرشفة | ✅ |
| `admin-mods.php` | لوحة الباقات + CSV export | ✅ |
| `class-pge-packages.php` | إدارة الباقات | ✅ |
| `class-salla-handler.php` | استقبال Webhook من سلة | 🔴 #4, #5, S1-S5 |
| `class-mon-salla-api.php` | كلاس مكرر | ⚠️ #6 |
| `class-mon-events-users.php` | تفعيل الباقة | ✅ |
| `checkin-handler.php` | تسجيل حضور (مكرر) | 🔴 #2 |
| `ajax.php` | تسجيل حضور (المعتمد) | ✅ |
| `event-guests.php` | CRUD المدعوين | ✅ |
| `helpers.php` | فارغ | ⚠️ #7 |

**الرموز:** ✅ سليم — ⚠️ يحتاج تحسين — 🔴 خلل وظيفي

### ملفات القالب (`pgevents-pro/`)

| الملف | الوظيفة |
|-------|---------|
| `functions.php` | تحميل assets، Elementor، إعادة توجيه login |
| `header.php / footer.php` | الترويسة والتذييل (Tailwind) |
| `front-page.php` | الصفحة الرئيسية (6 أقسام) |
| `page-dashboard.php` | لوحة تحكم المضيف مع KPIs |
| `page-create-event.php` | إنشاء مناسبة (391 سطر) |
| `page-edit-event.php` | تعديل مناسبة |
| `page-event-manage.php` | إدارة المدعوين (1008 سطر — أكبر ملف) |
| `page-login.php / register.php / forgot-password.php` | الحسابات المخصصة |
| `single-pge_event.php` | عرض المناسبة (Access Gate + Hero + Tabs + RSVP) |
| `single-event.php` | قالب تجريبي قديم — للحذف #12 |
| `template-parts/event/*` | access-gate / hero / tabs / rsvp |
| `template-parts/home/*` | أقسام الهوم بيج |
| `inc/elementor/widgets/*` | 3 ودجات Elementor مخصصة |
| `assets/css/output.css` | Tailwind المُجمَّع (62KB) |
| `assets/js/event.js` | منطق صفحة الحدث |

### روابط توثيق سلة المهمة

- [Authorization](https://docs.salla.dev/421118m0)
- [App Events](https://docs.salla.dev/421413m0)
- [Webhooks Overview](https://docs.salla.dev/421119m0)
- [Rate Limiting](https://docs.salla.dev/421125m0)
- [Responses](https://docs.salla.dev/421123m0)

---

## سجل التغييرات

| التاريخ | التغيير | بواسطة |
|---------|---------|---------|
| 2026-04-28 | إنشاء الملف، توثيق الـ12 ملاحظة + متطلب سلة | فريق بال قول |
| 2026-04-28 | إضافة إعدادات Salla Partner Portal (Webhook URL, Signature mode, Scopes)، توضيح الفرق بين Client Secret و Webhook Secret، تنبيه أمني لتجديد Client Secret بعد التطوير | فريق بال قول |
| 2026-04-28 | إصلاح #1: توحيد اسم جدول RSVP على `wp_pge_event_rsvps` في `rsvp-handler.php` — إصلاح #2: تعطيل `checkin-handler.php` المكرر وحذف استدعاؤه — إصلاح #5: تفعيل `is_valid_signature()` في Webhook + تغيير السر ليقرأ من `wp-config.php` + حذف method GET — إصلاح #9: إضافة حماية `.htaccess` لمنع تنزيل zip/sql/env | Claude (Cowork) |
| 2026-04-28 | **المرحلة الثانية S1-S5:** switch على نوع الحدث في Webhook handler — معالج `app.store.authorize` يحفظ التوكنات — معالجات installed/uninstalled/updated — صفحة إدارية للمتاجر المربوطة — إضافة Client ID/Secret في wp-config.php | Claude (Cowork) |
| 2026-04-28 | **اكتمال المرحلة الأولى كاملاً:** حذف `backup-dev` يدوياً — إزالة جميع ZIPs من `wp-content/themes/` — Deactivate/Activate الإضافة لتجديد جدول RSVP — حفظ Webhook Secret الجديد في لوحة التحكم — إعادة توليد Webhook Secret من Salla Partner Portal — إضافة واجهة إدارة المفتاح في `admin-mods.php` | فريق بال قول + Claude (Cowork) |
| 2026-04-29 | **المرحلة الثالثة (تشديد الأمان):** #3 XSS في pge_bio — #11 HMAC على cookie الضيف (3 ملفات) — nonce لـ pge_checkin_submit | Claude (Cowork) |
| 2026-04-29 | **المرحلة الرابعة (تنظيف معماري):** تعطيل class-mon-salla-api.php و single-event.php — helpers.php يحوي pge_norm_phone / pge_event_guests_norm_phone / pge_get_invited_phones / pge_normalize_invite_code / pge_generate_invite_code / pge_is_host_or_admin — حذف init flush المكرر | Claude (Cowork) |
| 2026-04-30 | **إصلاح حرج (مكتشف من اختبار سلة الحقيقي):** إضافة `order.status.updated` إلى switch في class-salla-handler.php — هذا هو الحدث الفعلي الذي يُفعّل الباقة عند إكمال الطلب (كان مفقوداً). البنية مختلفة: بيانات الطلب في `data.order` وليس `data` مباشرة | Claude (Cowork) |

> عند معالجة أي بند، حدّث الـ checkbox من `[ ]` إلى `[x]` وأضف صفاً جديداً هنا بالتاريخ ووصف التغيير.
