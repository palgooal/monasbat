# تكامل سلة

آخر تحديث: 2026-07-04
الحالة: نشط

# مقدمة

يوجد تكامل سلة داخل النظام لحل مشكلة عمل محددة: تمكين العملاء من شراء باقات المنتج والحصول على وصول فوري لها، دون تدخل يدوي من فريق التشغيل. سلة تمثّل قناة البيع والدفع التي يمر عبرها العميل قبل أن يصبح مستخدماً فعلياً للمنتج، وهي بذلك الجسر الذي يربط قرار الشراء التجاري بحالة الحساب التشغيلية داخل النظام.

# دور سلة داخل النظام

- **شراء الباقات**: توفير قناة تجارية يشتري عبرها العميل باقة استخدام للمنتج.
- **تفعيل حسابات المستخدمين**: تحويل عملية شراء ناجحة إلى حساب مستخدم فعّال داخل النظام.
- **ربط المستخدم بباقة مناسبة**: التأكد من أن كل عميل يحصل على الباقة التي دفع مقابلها فعلياً.
- **إدارة دورة حياة الاشتراك**: متابعة استمرار الباقة أو انتهائها أو تغييرها مع الوقت.
- **دعم استمرارية الخدمة دون تدخل يدوي**: تمكين تفعيل وإلغاء الباقات تلقائياً دون الحاجة لتدخل بشري في كل حالة.

من المهم توضيح أن سلة تكامل تجاري (Business Integration) وليست ميزة تواجه المستخدم مباشرة. المستخدم يتعامل مع نتيجة هذا التكامل — باقة نشطة وحدود واضحة — دون أن يحتاج للتفاعل مع سلة كجزء من تجربته داخل المنتج.

# دورة حياة الباقة

من المنظور المفاهيمي، تمر الباقة بالتسلسل التالي:

1. شراء الباقة من قِبل العميل.
2. التحقق من نجاح العملية التجارية.
3. تفعيل حالة المستخدم داخل النظام.
4. تطبيق الحدود والميزات المرتبطة بالباقة المشتراة على حساب المستخدم.
5. بدء استخدام المنتج فعلياً ضمن هذه الحدود.

هذا التسلسل هو ما يربط قرار الشراء التجاري بجاهزية المستخدم للعمل داخل المنتج.

# مبادئ التكامل

- النظام الداخلي هو مصدر الحقيقة النهائي لحالة المستخدم وباقته، وليس سلة.
- التكاملات الخارجية تنفّذ قرارات العمل التي يتخذها النظام، ولا تصنع هذه القرارات بنفسها.
- تفعيل الباقة يجب أن يكون واضحاً وقابلاً للتحقق في أي لحظة، لا اعتماداً على افتراضات.
- حدود الباقة يجب أن تصبح جزءاً من الحالة التشغيلية للمستخدم نفسه، لا أن تُشتق من سلة في كل استخدام.
- المستخدم لا يجب أن يفهم أو يتعامل مع أي من تفاصيل هذا التكامل؛ تجربته تقتصر على نتيجة التفعيل.

# إدارة دورة الحياة

- **التثبيت**: ربط متجر العميل بالنظام كخطوة تأسيسية أولى تمكّن التكامل من العمل.
- **التفعيل**: تحويل حالة الربط إلى تكامل فعّال وجاهز لمعالجة عمليات الشراء.
- **التحديث**: الحفاظ على استمرارية صلاحية الربط بين المتجر والنظام مع مرور الوقت.
- **الإلغاء**: إنهاء الربط بين المتجر والنظام عند توقف العميل عن استخدام الخدمة عبر سلة.
- **انتهاء الصلاحية**: التعامل مع الحالات التي تصبح فيها الباقة أو الربط غير سارٍ، وضمان انعكاس ذلك على حالة المستخدم دون تأخير.

كل مرحلة من هذه المراحل مسؤولة عن جانب واحد من استمرارية العلاقة بين المتجر والنظام، دون أن تتداخل مسؤولياتها.

# مبادئ الأمان

- التحقق من مصدر الطلبات الخارجية قبل قبول أي معلومة واردة منها.
- عدم الثقة بأي بيانات واردة من الخارج دون تحقق مسبق من صحتها ومصدرها.
- حماية المعلومات الحساسة المرتبطة بهذا التكامل من أي كشف أو تسرب.
- إمكانية استبدال المفاتيح والبيانات السرية المرتبطة بالتكامل دون تعطيل الخدمة.
- الفصل الواضح بين الهوية التجارية للعميل داخل سلة والحالة التشغيلية لحسابه داخل النظام.

# ما لا يجب كسره

- الباقات تُدار داخل النظام نفسه، وليس داخل واجهة المستخدم أو بالاعتماد المباشر على سلة في كل تفاعل.
- الحالة التشغيلية للمستخدم يجب أن تبقى مستقلة عن المنصة الخارجية بمجرد تفعيلها.
- فشل التكامل مع سلة لا يجب أن يفسد أو يتلف أي بيانات داخلية قائمة.
- لا يجوز ربط تجربة المستخدم داخل المنتج بأي تفاصيل خاصة بمزود الدفع.
- يجب أن يبقى استبدال المزود الخارجي (سلة أو غيره مستقبلاً) ممكناً دون إعادة تصميم النظام الداخلي.


# توافق Orders API — تحديث سلة (1 سبتمبر 2026)

آخر تحديث: 2026-08-31
الحالة: **CLOSED — PASS WITH NOTES**

أعلنت سلة عن إيقاف السلوك القديم لواجهة Orders API اعتباراً من 1 سبتمبر 2026: إلغاء دعم `expanded=true` في List Orders، وجعل الاستجابة المختصرة (light) هي الافتراضية في Order Details بدل الاستجابة الموسّعة القديمة. تم تدقيق هذا التكامل بالكامل بتاريخ 31 أغسطس 2026 مقابل هذا التغيير — راجع السجل الكامل في `salla-orders-api-deprecation-audit-2026-08-31.md` في جذر المشروع.

**النتيجة المؤكَّدة:**

- لا يوجد أي استدعاء فعلي (Reachable) في الكود الحالي لواجهة List Orders.
- لا يوجد أي استدعاء فعلي لواجهة Order Details.
- لا استخدام لـ `expanded=true` في أي مكان في المستودع.
- معالجة الباقات/البوكيهات (bouquet/package fulfillment) تعتمد بالكامل على حمولة Webhook الواردة من سلة (`order.created` / `order.updated` / `order.payment.updated` / `order.status.updated`)، وليس على استدعاء أي واجهة Orders API.
- مطابقة المنتج/الباقة تعتمد على حقول الـ Webhook نفسها: `items[]`، `sku`، و `product.id` / `product_id`.
- منع التكرار (Idempotency) لتفعيل الباقة ولإرسال بريد التفعيل يعتمدان كلاهما على حقل `order.id` القادم من الـ Webhook نفسه، لا على أي استجابة من Orders API.
- **لا حاجة لأي ترحيل إنتاجي (P0 migration) قبل 1 سبتمبر 2026** — النظام لا يعتمد أصلاً على السلوك المُزال.

**ملاحظة مهمة — لا تُفهَم خطأً:** هذا لا يعني أن حمولة الـ Webhook نفسها مضمونة الثبات إلى الأبد؛ التوافق الحالي المذكور أعلاه خاص بواجهة Orders API فقط (List/Details)، ولا يُشكّل تأكيداً بأن حمولات الـ Webhook لن تتغيّر مستقبلاً. تغطية اختبارية لعقد الـ Webhook (webhook contract regression coverage) تبقى بنداً منفصلاً لم يُنفَّذ بعد (راجع بند P1 — اختبار انحدار الـ Webhook في `PROJECT-NOTES.md`).

## Durable Plus customer-group synchronization (2026-09-18)

Hilwah is the source of truth for Plus entitlement. After a successful internal
`halwa_plus` activation, the webhook records `desired_state=member` in
`wp_pge_salla_membership_sync`; it does not wait for a Salla HTTP mutation.

- Ownership: one mutable row per `(merchant_id, salla_customer_id, group_id)`,
  enforced by `membership_identity`. Replayed webhooks reuse that row.
- State: `desired_revision` versions the desired state. A worker claim records
  both an opaque `attempt_token` and `attempt_revision`; finalization requires
  status, token, and revision to match, so stale workers cannot overwrite a
  reclaimed attempt or a newer desired-state mutation.
- Worker: bounded batches are driven by single-event WP-Cron ticks. A deduped
  15-minute recovery event is re-armed from `init`; the SQL row, not cron, is
  authoritative. Expired five-minute processing leases are reclaimable.
- Retry: transport failures, HTTP 429, and HTTP 5xx use persisted bounded
  exponential backoff (60 seconds through 3600 seconds). HTTP 400/401/403/422
  become terminal diagnostic failures and are not blindly retried.
- Ambiguity: after an add transport failure, and before repeating an ambiguous
  mutation, the worker reads the documented Customer Details endpoint
  `GET /admin/v2/customers/{customer}`. Its `data.groups` membership is the
  authority; deprecated `order.customer.groups` is never used.
- Security: synchronization rows store identifiers, state, timestamps, and
  sanitized error codes only. OAuth tokens, client secrets, webhook secrets,
  raw response bodies, email, and mobile are never stored or logged here.
- Scope: only `plan_key=halwa_plus` activation creates desired membership.
  Activation email and entitlement remain independent of sync persistence or
  Salla availability.

Future `desired_state=not_member` is intentionally reserved but not executed.
Removal must not occur until Salla's official remove-membership endpoint is
verified. It must also use an entitlement resolver: a customer remains a member
while any active Plus event/entitlement exists, so one event ending is never
sufficient by itself to request removal.
