# AGENTS.md — قواعد العمل على مشروع مناسبات

هذا الملف قواعد تنفيذ مختصرة وملزمة لأي Coding Agent يعمل على المشروع. ليس توثيقاً شاملاً؛ راجع الكود والاختبارات والوثائق ذات الصلة قبل كل مهمة.

## 1. مصادر الحقيقة

ترتيب الثقة عند التحقق من السلوك الحالي:

1. الكود الحالي.
2. الاختبارات الحالية.
3. الوثائق الحديثة المرتبطة بالنظام.
4. Git history.
5. `CLAUDE.md` كمصدر تاريخي فقط.

إذا تعارض `CLAUDE.md` مع الكود الحالي، فالكود هو المرجع ويجب الإبلاغ عن التعارض.

## 2. نطاق المشروع

- المشروع WordPress application، وليس Laravel application.
- بيئة التطوير المحلية: Laragon على Windows.
- منطق الأعمال: `wp-content/plugins/pgevents-core/`.
- طبقة العرض: `wp-content/themes/pgevents-pro/`.

## 3. أسلوب العمل الإلزامي

- اعمل خطوة بخطوة، بمهمة واحدة محددة في كل مرحلة.
- نفّذ Preflight audit قبل أي تعديل جوهري.
- لا توسّع Scope تلقائياً، ولا تصلح مشكلة جانبية إلا إذا كانت Blocker مباشراً.
- عند ظهور Blocker معماري: توقف، اشرح الأدلة، واطلب قرار المستخدم.
- استخدم أقل Diff ممكن.
- أعد استخدام Services/Resolvers/Repositories الحالية قبل إنشاء abstraction جديدة.
- لا تنسخ منطقاً موجوداً إلى مسار جديد ولا تنشئ مصدر حقيقة موازياً.

## 4. سلامة Git

قبل كل مهمة راجع:

```text
git status
git diff
git diff --cached
```

لا تستخدم دون موافقة صريحة: `git reset`، `git restore`، `git checkout -- .`، `git clean`، أو `git stash`. لا تحذف أو تستبدل تغييرات المستخدم السابقة.

احترم حالة Working Tree الحالية، ولا تنظف تلقائياً:

- `wp-content/plugins/pgevents-core.zip`
- `wp-content/themes/pgevents-pro.zip`
- `wp-content/plugins/pgevents-core/tests/wp-admin/includes/upgrade.php` (غير متتبع)

هذه القائمة تعكس حالة Working Tree الحالية وقت كتابة `AGENTS.md` وليست قاعدة دائمة. يجب تحديثها أو حذفها عندما تُحسم حالة هذه الملفات بقرار صريح.

لا تعمل commit أو push إلا إذا سمحت المهمة بذلك صراحة. قبل commit: راجع `git diff` والملفات المعدلة، شغّل الاختبارات المطلوبة، وتأكد من عدم دخول ملفات غير مرتبطة. لا تعمل push تلقائياً.

## 5. الاختبارات والملفات المولدة

- الاختبارات في `wp-content/plugins/pgevents-core/tests/`، وهي PHP scripts مستقلة وليست PHPUnit suite مركزية.
- لا تفترض أن `php tests/...` هو الـHarness الصحيح؛ اقرأ تعليمات كل Test أولاً.
- بعض الاختبارات تعتمد `php-wasm`، وبعضها يستخدم Fake WordPress/Fake `$wpdb`، وبعض UI tests بنيوية وليست Browser E2E.
- Harness limitation لا يُعد Regression تلقائياً. عند فشل مشكوك فيه، قارن Baseline قبل/بعد في البيئة الصحيحة.
- `wp-content/themes/pgevents-pro/assets/css/output.css` ملف مولّد؛ لا تعدله يدوياً. عند إضافة Tailwind classes، شغّل build المناسب وتحقق من وجودها في الناتج. لا تعتبر التغيير مكتملاً إذا كان يعتمد CSS غير مبني.

## 6. الباقات والحدود

- Catalog هو النظام الحديث، وLegacy موجود للتوافق فقط.
- Catalog entitlements تُستهلك من User Snapshot. لا تغيّر Snapshot semantics دون مهمة مستقلة.
- لا تخلط بين `guest_limit` و`invitation_credit_limit` و`replacement_credit_limit` وEvent Quota؛ كل مفهوم مستقل.
- مسار فرض Guest Limit المعتمد هو `PGE_Invitation_Service::create()`، والحالة تُحل عبر `pge_resolve_guest_quota_status()`.
- Legacy create AJAX endpoints معطلة. لا تضف أي مسار إنشاء مدعو يتجاوز الـService.
- Invitation Credit Ledger وReplacement Credits أنظمة حساسة. Reminder وThank You لا يستهلكان أيّاً منهما، ولا يجوز اختراع `credit_type` لتمرير الرسائل الجديدة.

## 7. Messaging Architecture

الحالة المعتمدة:

- Phase 1 — COMPLETE: Message abstraction.
- Phase 2 — COMPLETE: Tracking/schema foundation.
- Phase 3 — COMPLETE: Manual Reminder.
- Phase 4 — NOT STARTED: Manual Thank You.

أنواع الرسائل المستقلة: `invitation`، `reminder`، `thank_you`.

Reminder الحالي:

- Cartat فقط، ويدعم نصاً أو نصاً مع Featured Image اختيارية للمناسبة.
- قابل للتكرار عبر intentional batches جديدة.
- يستخدم queue/tracking مستقلين عن Invitation؛ `message_log` هو tracking foundation.
- الفلاتر: `pending` و`all`. حالة `pending` لا تعتمد على `checked_in`.
- لا يستهلك Credits، ولا يوجد Automatic Reminder حالياً. Cron يكمل فقط دفعة بدأها المستخدم يدوياً.

Thank You المخطط:

- Cartat، ونص فقط.
- للحاضرين فعلياً (`checked_in = 1`) فقط.
- مرة واحدة لكل ضيف/مناسبة.
- لا يستهلك Credits.

لا تغيّر Invitation send path أثناء تطوير Reminder/Thank You إلا إذا طلبت المهمة ذلك صراحة. Invitation queue/credits/provider semantics مستقرة وحساسة.

## 8. شروط ما قبل Phase 4

لا تبدأ Phase 4 قبل حسم هذه الشروط المعروفة؛ ليست مهاماً للتنفيذ تلقائياً:

1. حُسم في Phase C: إعادة استخدام RSVP row تصفّر `thank_you_sent_at` عبر مسار إنشاء الدعوة authoritative؛ حافظ على هذا العقد.
2. يجب اتخاذ قرار واضح لمعالجة stale pending claim / lease / reclaim في `PGE_Thank_You_Claim` إذا توقفت العملية بعد `claim()` وقبل `finalize_*()`.
3. Recipient Resolver لـThank You يجب أن يعتمد `checked_in = 1`، لا RSVP=`yes`.

RSVP وCheck-in مفهومان منفصلان. أهلية Thank You المخططة تعتمد Check-in الفعلي، ولا يجوز استخدام RSVP=`yes` بديلاً عنه.

## 9. Providers والتكاملات

- لا تنسخ HTTP/Auth/Payload logic؛ استخدم Transport الموجود.
- Cartat هو Provider للرسائل الجديدة الحالية. لا توسّع Reminder/Thank You إلى UltraMsg دون مهمة مستقلة.
- Salla/Webhook activation/deactivation/idempotency حساس. لا تغيّر Webhook semantics أو order identity أو Catalog activation أو Snapshot refresh أو credit accumulation ضمن مهمة Messaging غير مرتبطة.

## 10. الأسرار وSchema

- لا تطبع أو تنسخ أو تضمّن محتوى `wp-config.php`، API tokens، Salla secrets، Cartat credentials أو WordPress salts في تقارير أو logs أو tests أو commits أو documentation.
- إذا ظهر Secret أثناء الفحص، لا تعِده في الرد.
- لا تنفذ Schema/Migration تلقائياً أثناء Audit.
- أي Schema change يجب أن يكون ضمن Scope صريحاً، additive قدر الإمكان، متبعاً schema/versioning الحالي، ومغطى بالاختبارات.
- أي RSVP lookup بالهاتف يجب أن يمر عبر `pge_rsvp_find_canonical_by_phone()`؛ لا تستخدم `LIMIT 1` لاختيار صف صامتاً من هوية `event_id + normalized_guest_phone`.
- RSVP row هو Current Snapshot تحت هوية Option A. Lifecycle reset يحدث فقط عبر مسار بدء lifecycle المعتمد: `create()` بعد validation وGuest Limit وduplicate checks، أو Phone Change لهاتف الهدف بعد duplicate/integrity checks؛ لا تنفّذ reset في lookup أو RSVP أو Check-in أو Reminder أو resend، ولا تكرر منطقه في Bulk/Excel.
- لا تشتق `qr_version` لدورة دعوة جديدة من `invited_at` أو الوقت وحده. Hard Delete يجب أن يحافظ على QR tombstone غير مرئية، وRe-invite يجب أن تدوّر النسخة persistent مع بقاء `_pge_invited_guests` مصدر وجود الدعوة الوحيد.
- تغيير هاتف الدعوة يبدأ lifecycle جديدة للهاتف الهدف فقط: لا تعدّل RSVP row الخاصة بهاتف المصدر، وأعد ضبط canonical target RSVP إن وُجدت عبر نفس Phase C matrix، مع تدوير QR الهدف والحفاظ على source tombstone غير مرئية.

## 11. تقرير التسليم لكل مهمة

بعد أي تنفيذ، اذكر على الأقل:

1. الملفات المعدلة.
2. ماذا تغيّر.
3. لماذا تغيّر.
4. القرارات المعمارية.
5. الاختبارات ونتائجها.
6. حالة Regression.
7. مراجعة `git diff`/static audit.
8. المشاكل أو المخاطر المتبقية.
9. Git commit hash إن وُجد commit.
