# CLAUDE.md — دليل المطور لمشروع مناسبات (Monasbat)

> هذا الملف موجّه للمطورين الجدد وأدوات الذكاء الاصطناعي. يشرح معمارية المشروع، أنماط الكود، والقرارات الفنية المهمة.
> آخر تحديث: 2026-04-29

---

## نظرة عامة

منصة WordPress لإدارة دعوات المناسبات (أعراس، فعاليات). تتكون من مكونين رئيسيين:

| المكون | المسار | الدور |
|--------|--------|-------|
| `pgevents-core` | `wp-content/plugins/pgevents-core/` | المحرك البرمجي: CPT، AJAX، Webhooks، منطق الباقات |
| `pgevents-pro` | `wp-content/themes/pgevents-pro/` | الواجهة: Tailwind CSS + Elementor |

---

## هيكل الإضافة (`pgevents-core`)

```
pgevents-core/
├── pgevents-core.php          ← نقطة الدخول، يحمّل كل الملفات
├── includes/
│   ├── helpers.php            ← ★ دوال مساعدة مشتركة (يُحمَّل أولاً)
│   ├── cpts.php               ← تسجيل Custom Post Type: pge_event
│   ├── metaboxes.php          ← حقول المناسبة في لوحة التحكم
│   ├── user-profiles.php      ← حقول إضافية في ملف المستخدم (pge_bio, pge_cover_url)
│   ├── rsvp-handler.php       ← إنشاء جدول RSVP + AJAX handlers
│   ├── event-factory.php      ← AJAX إنشاء/تعديل/أرشفة المناسبات
│   ├── event-guests.php       ← CRUD المدعوين (إضافة/تعديل/حذف)
│   ├── ajax.php               ← AJAX تسجيل الحضور (Check-in)
│   ├── admin-mods.php         ← لوحة تحكم الباقات + إعدادات سلة
│   ├── class-pge-packages.php ← كلاس إدارة الباقات والحدود
│   ├── class-mon-events-users.php ← كلاس تفعيل الباقة بعد الدفع
│   ├── class-salla-handler.php    ← معالج Webhook سلة
│   ├── routing.php            ← نظام التوجيه المخصص
│   └── helpers.php            ← الدوال المساعدة المركزية
└── templates/
    └── dashboard-main.php     ← قالب لوحة تحكم المضيف
```

**ملفات معطّلة (`.disabled`) — لا تحذفها، قد تحتاجها مرجعاً:**
- `checkin-handler.php.disabled` — كانت مكررة مع ajax.php
- `class-mon-salla-api.php.disabled` — كانت مكررة مع class-salla-handler.php

---

## قاعدة البيانات

### Custom Post Type
**`pge_event`** — يخزن المناسبات. Post Meta المهمة:

| المفتاح | النوع | الوصف |
|---------|-------|-------|
| `_pge_event_date` | string | تاريخ المناسبة |
| `_pge_event_location` | string | رابط خريطة Google |
| `_pge_host_phone` | string | جوال المضيف |
| `_pge_invite_code` | string | رمز الدعوة (XXXX-XXXX) |
| `_pge_invited_phones` | array\|string | قائمة أرقام المدعوين |
| `_pge_invited_guests` | array | بيانات المدعوين (اسم، ملاحظة) |
| `_pge_rsvp_map` | array | ردود RSVP (احتياطي قديم) |
| `_pge_checkins` | array | سجل الحضور يوم المناسبة |

### Custom Table
**`wp_pge_event_rsvps`** — ردود RSVP الرئيسية:

```sql
id, event_id, guest_phone, companions, note, reply,
checked_in (0/1), checked_in_at, created_at
```

> **مهم:** هذا الجدول هو المصدر الرئيسي للـ RSVP. `_pge_rsvp_map` في Post Meta موجود للتوافق مع كود قديم فقط.

### User Meta

| المفتاح | الوصف |
|---------|-------|
| `_mon_package_status` | `active` / `expired` / `canceled` |
| `_mon_package_key` | مثل `plan_1`, `plan_2`, `plan_3`, `plan_4` |
| `_mon_package_name` | اسم الباقة للعرض |
| `_mon_events_limit` | عدد المناسبات المسموحة |
| `_mon_guest_limit` | عدد المدعوين لكل مناسبة |
| `_mon_host_photos_limit` | عدد الصور المسموحة |
| `_mon_wa_limit` | عدد رسائل WhatsApp |
| `_mon_active_features` | array من مفاتيح الميزات المفعّلة |
| `_created_via_salla` | `yes` إذا أُنشئ الحساب عبر سلة |
| `pge_bio` | النبذة الشخصية |
| `pge_cover_url` | رابط صورة الغلاف |

### wp_options المهمة

| المفتاح | الوصف |
|---------|-------|
| `mon_packages_settings` | إعدادات الباقات الأربع (JSON) |
| `pge_salla_webhook_secret` | مفتاح HMAC للـ Webhook |
| `pge_salla_client_id` | Client ID لتطبيق سلة |
| `pge_salla_client_secret` | Client Secret لتطبيق سلة |
| `pge_salla_tokens_{merchant_id}` | توكنات OAuth لكل متجر |
| `pge_salla_install_{merchant_id}` | بيانات تثبيت التطبيق |

---

## تدفق العمل الرئيسي

### 1. تفعيل الباقة عبر سلة
```
سلة (دفع) → POST /wp-json/mon/v1/salla-callback
    → Mon_Salla_Handler::handle_salla_notification()
    → handle_order_event() [إذا order.created/updated]
    → process_user_and_plan()
    → map_product_to_plan()   ← يطابق salla_id مع plan_key
    → Mon_Events_Users::activate_user_package()
    → تحديث User Meta بالحدود والميزات
    → إرسال بريد ترحيب مع رابط تعيين كلمة المرور
```

### 2. دخول الضيف للمناسبة (Access Gate)
```
الضيف يفتح /event/{slug}/
    → single-pge_event.php
    → template-parts/event/access-gate.php
        → يتحقق من pge_access_{event_id} cookie (HMAC موقّع)
        → إذا لم يمر: يعرض نموذج (جوال + رمز دعوة)
        → عند التحقق الناجح:
            → يكتب pge_access_{event_id} cookie (access token)
            → يكتب pge_event_phone_{event_id} cookie (phone|HMAC)
            → redirect للمناسبة
```

### 3. تسجيل RSVP
```
الضيف يضغط "سأحضر"
    → AJAX: wp_ajax_nopriv_pge_rsvp_submit
    → pge_rsvp_submit() في rsvp-handler.php
    → يتحقق من nonce
    → يقرأ الجوال من cookie مع التحقق من HMAC
    → يتحقق أن الجوال في قائمة المدعوين
    → يكتب/يحدث سجل في wp_pge_event_rsvps
```

---

## الـ AJAX Endpoints

جميع الـ AJAX handlers تتحقق من **nonce + login + capability** بهذا الترتيب:

| Action | الملف | الوصول | الصلاحية المطلوبة |
|--------|-------|--------|------------------|
| `pge_rsvp_submit` | rsvp-handler.php | عام (nopriv) | HMAC cookie صالح |
| `pge_checkin_submit` | rsvp-handler.php | مسجّل | مضيف أو أدمن |
| `pge_checkin_guest` | ajax.php | مسجّل | مضيف أو أدمن |
| `pge_create_new_event` | event-factory.php | مسجّل | أي مستخدم مسجّل (مع فحص الكوتا) |
| `pge_handle_event_update` | event-factory.php | مسجّل | صاحب المناسبة |
| `pge_archive_event` | event-factory.php | مسجّل | صاحب المناسبة |
| `pge_event_set_invite_code` | event-factory.php | مسجّل | صاحب المناسبة |
| `pge_event_guest_*` | event-guests.php | مسجّل | صاحب المناسبة |

---

## نظام الباقات

الباقات تُعرَّف في `wp_options` تحت مفتاح `mon_packages_settings`:

```php
[
    'plan_1' => [
        'name'         => 'الباقة الأساسية',
        'salla_id'     => '12345',   // معرف المنتج في متجر سلة
        'events_count' => 3,
        'guest_limit'  => 100,
        'host_photos'  => 10,
        'wa_messages'  => 0,
        'google_map'   => '0',       // '1' = مفعّلة
        'header_img'   => '1',
    ],
    'plan_2' => [...],
    'plan_3' => [...],
    'plan_4' => [...],
]
```

عند تفعيل الباقة، تُنسخ الحدود مباشرة إلى User Meta (للقراءة السريعة بدون join):
- `_mon_events_limit`, `_mon_guest_limit`, `_mon_host_photos_limit`, `_mon_wa_limit`
- `_mon_active_features` = array من مفاتيح الميزات التي قيمتها `"1"`

للتحقق من ميزة في القالب:
```php
$plan_limits = pge_get_user_plan_limits_for_events($user_id);
if (pge_plan_feature_enabled_for_events($plan_limits, 'google_map')) {
    // الميزة مفعّلة
}
```

---

## تكامل سلة App Store

### الـ Webhook Endpoint
```
POST https://hilwah.net/wp-json/mon/v1/salla-callback
```

### التحقق من الهوية (مهم جداً)
كل طلب يجب أن يحمل header `x-salla-signature` وهو HMAC-SHA256 للـ payload بمفتاح `pge_salla_webhook_secret`. التحقق يحدث في `Mon_Salla_Handler::is_valid_signature()`.

### الأحداث المعالجة

| الحدث | الإجراء |
|-------|---------|
| `order.created/updated/payment.updated` | تفعيل أو إلغاء الباقة |
| `app.store.authorize` | حفظ OAuth tokens (يصل كل 14 يوم) |
| `app.installed` | تسجيل تثبيت المتجر |
| `app.store.uninstall` / `app.uninstalled` | مسح tokens |
| `app.updated` | تسجيل في log فقط |

### التوكنات
تُحفظ في `wp_options` تحت `pge_salla_tokens_{merchant_id}` وتحتوي:
`access_token`, `refresh_token`, `expires`, `scope`, `token_type`, `updated_at`

---

## الأمان — نقاط مهمة

1. **Cookie الضيف موقَّع بـ HMAC** — القيمة المخزنة هي `phone|wp_hash(phone|event_id)`. لا تقبل الرقم بدون التحقق من الـ hash.

2. **Webhook يتحقق من الـ signature** — أي طلب بدون signature صحيح يُرفض بـ 401.

3. **المفاتيح الحساسة في DB** — `pge_salla_webhook_secret`, `pge_salla_client_id`, `pge_salla_client_secret` تُخزَّن في `wp_options` وتُدار من لوحة التحكم. يمكن override بـ constants في `wp-config.php`.

4. **ملفات محمية** — `.htaccess` يمنع تنزيل `*.zip|tar|gz|bak|sql|env|log`.

---

## الدوال المساعدة المركزية (`helpers.php`)

جميع هذه الدوال محمية بـ `if (!function_exists())` — لا تعيد تعريفها في ملفات أخرى.

| الدالة | الوصف |
|--------|-------|
| `pge_norm_phone($v)` | إزالة كل شيء ما عدا الأرقام من رقم الجوال |
| `pge_event_guests_norm_phone($v)` | alias لـ pge_norm_phone |
| `pge_get_invited_phones($event_id)` | جلب قائمة المدعوين كـ array أرقام موحّدة |
| `pge_normalize_invite_code($code)` | تطبيع رمز الدعوة إلى صيغة XXXX-XXXX |
| `pge_norm_invite_code($code)` | alias لـ pge_normalize_invite_code |
| `pge_generate_invite_code()` | توليد رمز دعوة عشوائي |
| `pge_is_host_or_admin($event_id)` | هل المستخدم الحالي مضيف المناسبة أو أدمن؟ |

---

## نظام التوجيه (Routing)

المشروع لا يستخدم صفحات WordPress التقليدية للمسارات الوظيفية. بدلاً منها، `routing.php` يضيف rewrite rules ويعترض الطلبات:

| المسار | query var | القالب المُحمَّل |
|--------|-----------|-----------------|
| `/dashboard/` | `pge_action=dashboard` | `templates/dashboard-main.php` |
| `/create-event/` | `pge_action=create_event` | `page-create-event.php` |
| `/edit-event/{id}/` | `pge_action=edit_event` | `page-edit-event.php` |
| `/event-manage/{id}/` | `pge_action=event_manage` | `page-event-manage.php` |
| `/login/` | `pge_action=login` | `page-login.php` |
| `/register/` | `pge_action=register` | `page-register.php` |
| `/forgot-password/` | `pge_action=forgot_password` | `page-forgot-password.php` |

> إذا غيّرت rewrite rules، افعل **Deactivate ثم Activate** للإضافة لتطبيقها.

---

## أنماط يجب الالتزام بها

### عند إضافة AJAX handler جديد
```php
add_action('wp_ajax_pge_my_action', function () {
    // 1. nonce أولاً
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pge_my_nonce')) {
        wp_send_json_error('Invalid nonce');
    }
    // 2. login check
    if (!is_user_logged_in()) wp_send_json_error('Unauthorized');
    // 3. capability check
    $event_id = absint($_POST['event_id'] ?? 0);
    if (!pge_is_host_or_admin($event_id)) wp_send_json_error('Forbidden');
    // 4. المنطق...
});
```

### عند قراءة cookie الضيف
```php
$cookie_name = 'pge_event_phone_' . $event_id;
$phone = '';
if (isset($_COOKIE[$cookie_name])) {
    $parts = explode('|', (string) $_COOKIE[$cookie_name], 2);
    if (count($parts) === 2 && hash_equals(wp_hash($parts[0] . '|' . $event_id), $parts[1])) {
        $phone = pge_norm_phone($parts[0]);
    }
}
```

### عند تطبيع رقم الجوال
```php
// صح ✅
$phone = pge_norm_phone($_POST['phone']);

// خطأ ❌ — لا تكرر المنطق inline
$phone = preg_replace('/\D+/', '', $_POST['phone']);
```

---

## بيئة التطوير

- **Stack:** Laravel + WordPress على `F:\laragon\www\monasbat\`
- **Theme Build:** `cd wp-content/themes/pgevents-pro && npm run build` (Tailwind CSS)
- **الدومين المحلي:** `http://monasbat.test/` (Laragon)
- **الدومين الإنتاجي:** `https://hilwah.net/`

---

## ما لا يجب لمسه بدون مراجعة

| الملف | السبب |
|-------|-------|
| `includes/routing.php` | تغيير خاطئ يكسر كل الـ URLs |
| `includes/class-pge-packages.php` | منطق الباقات حساس — اختبر جيداً |
| `wp-config.php` | يحوي ثوابت سلة الحساسة |
| `wp_pge_event_rsvps` (جدول) | لا تغير schema بدون migration script |
