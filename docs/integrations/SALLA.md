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

## Catalog Activation History / Plus Lifecycle — Final Design Review (2026-09-23)

Status: **DESIGN APPROVED; IMPLEMENTATION NOT INCLUDED IN THIS UPDATE**

This section records the contracts that closed the five Final Implementation
Design blockers. It does not assert that the lifecycle, schema, migration, or
backfill has been implemented. The existing 2026-09-18 synchronization scope
above remains historical evidence of the currently implemented behavior.

### DEC-SALLA-GROUP-01 — Accept Controlled Replacement Race

Plus membership removal will use this controlled replacement sequence:

1. Read authoritative Customer Details from Salla.
2. Remove only Plus Group ID `225189340` from the group list returned by that
   pre-GET.
3. PUT the complete remaining group list together with a recognized, unchanged
   customer field.
4. Perform an independent Customer Details GET for reconciliation.

A local identity lock serializes this application's work for the same identity,
but cannot prevent a concurrent external mutation in Salla. Success therefore
requires both conditions below:

- Plus Group ID is absent from the reconciliation GET.
- Every non-target group present in the pre-GET is still present.

Any discrepancy is a `reconciliation_conflict`. The system must not blindly
repair, recreate, or overwrite non-target groups.

#### Behavioral verification: `groups=[]` — PASS

The controlled Salla test used no logged token, refresh token, client secret,
webhook secret, or Authorization header.

| Field | Verified value |
|---|---|
| Merchant | `392732220` |
| Test customer | `1888007575` |
| Test group | `1702339165` |
| Plus group | `225189340` |
| Pre-state | `[1702339165]`; Plus absent |
| Update Customer request | unchanged `first_name` and `groups=[]` |
| PUT result | HTTP 200 |
| Independent post-GET | `POST_GROUPS=[]` |
| Result | `EMPTY_GROUPS=YES` |

This proves that Update Customer replacement semantics also support removing
the customer's last group. It verifies the API behavior required by the design;
it does not by itself implement Plus removal.

### DEC-PLUS-MIG-01 — Verified Pre-Launch Backfill

Production inspection produced the following bounded inventory:

| Counter | Value |
|---|---:|
| `CATALOG_USERS` | 4 |
| `CATALOG_ACTIVE` | 4 |
| `PLUS_ACTIVE` | 1 |
| `PLUS_WITH_CYCLE` | 1 |
| `PLUS_WITH_SALLA_CUSTOMER` | 1 |
| `PLUS_WITH_LAST_ORDER` | 1 |
| `PLUS_WITH_MATCHING_EVENT` | 0 |

The only current Plus case is:

- WP user: `380`
- plan: `halwa_plus`
- tier: `guests_100`
- cycle: `6daa5ee5-5bda-43a6-a392-ad75402b8c21`
- Salla customer: `1888007575`
- order: `1576373696`

Salla verification returned Order HTTP 200 for customer `1888007575` with
status `completed`. List Order Items returned HTTP 200 with one item: product
`1539650850`, SKU `HALWA-PLUS-100`, name `حلوة بلس`.

Only this exact inspected case is eligible for a verified pre-launch Salla Plus
activation backfill. It is logically `active_unbound` because no matching event
exists. This is not a general heuristic: future provider origin must never be
inferred only from `_pge_salla_customer_id`, `_mon_last_order_id`, or a
membership-sync row. This decision authorizes the bounded backfill design; this
documentation update does not execute that backfill.

### DEC-CATALOG-IDEM-01 — Stable Manual Operation Identity

Every new manual activation operation receives a UUID before its first submit.
Its idempotency key is `manual:{operation_id}`. The same UUID persists across a
timeout, network retry, double-submit, or resume of that same operation; a newly
intended operation receives a new UUID.

The server/database idempotency record is the source of truth.
`sessionStorage` or other browser persistence is transport persistence only.

- Same key and same payload replays or resumes the same activation.
- Same key with a different user, plan, or tier payload is an idempotency
  conflict.
- A nonce, timestamp, or user ID is not a substitute for operation identity.

### DEC-PLUS-REFUND-01 — Terminal Revocation Tombstone

The durable Salla order identity is
`(provider=salla, merchant_id, external_order_id)`. A trusted, verified refund
or cancellation applies the following transitions:

| Current state | Result |
|---|---|
| `active_unbound` | `revoked` |
| `active_bound` | `revoked` |
| `preparing` | `revoked` |
| `revoked` | `revoked` idempotently |
| `ended` | `ended` |

If a trusted refund/cancel arrives before activation, the system must create a
durable `revoked` tombstone for the same order identity. A late activation
webhook for that order must not create an entitlement or restore Plus group
membership. No tombstone may be created from an untrusted webhook or an
ambiguous status.

After revocation, aggregate Salla Plus eligibility is recalculated. Another
active Salla Plus activation prevents group removal. A later repurchase has a
new order ID and is therefore an independent activation.

### DEC-SALLA-REMOVAL-SNAPSHOT-01 — Durable Pre-GET Evidence

Before a destructive Plus-group removal PUT, the system must durably persist
the complete normalized set of non-target groups returned by the immediately
preceding authoritative Customer Details GET. This removal snapshot belongs to
the canonical membership identity together with the exact `desired_revision`
that requested `not_member`; an attempt token is not its durable identity.

For the same `desired_revision`, the snapshot must remain recoverable across a
process crash, PUT timeout, failed reconciliation GET, lease expiry, and a new
`attempt_token`. A transition of `desired_state` back to `member` must
invalidate or clear any older removal snapshot so that a stale `not_member`
attempt cannot reuse it.

Successful reconciliation still requires the target Plus group to be absent
and every non-target group captured in the durable pre-GET snapshot to remain
present. A discrepancy is `reconciliation_conflict`; it never authorizes blind
repair of non-target groups.

The Phase 7 implementation therefore requires schema support for this durable
snapshot. Its Customer Details parser must return all of the following as an
explicit validated result before destructive removal can proceed:

- the complete normalized customer-group list;
- the current `first_name` used as the recognized unchanged customer field;
- explicit customer existence;
- confirmation that the response structure is valid.

This decision defines the storage and parser contract only. No schema change,
Customer Details request, Update Customer PUT, or removal worker is implemented
by this documentation update.

### DEC-SALLA-REMOVAL-401-01 — Preserve Existing 401 Policy

Phase 7 uses the existing Token Manager and its current token-acquisition
policy. It does not add forced or reactive token refresh. If a removal Customer
Details or Update Customer transport receives HTTP 401 after that policy has
run, the operation must return `unauthorized_after_token_recovery`.

Phase 7 must not replay a destructive PUT through removal-specific refresh
logic. Any future centralized reactive-401 enhancement is a separate Token
Manager change with its own design and verification; it is not part of Phase 7.

### Final Implementation Design blockers

The earlier questions and evidence remain recorded above and in project
history. Their current disposition is:

| Blocker | Status | Closing decision or evidence |
|---|---|---|
| Safe removal without overwriting concurrent non-target group changes | **RESOLVED** | `DEC-SALLA-GROUP-01`: accept the controlled replacement race, require independent reconciliation, and emit `reconciliation_conflict` on discrepancy; never blind-repair non-target groups. |
| Can Update Customer remove the final customer group with `groups=[]`? | **RESOLVED** | Controlled behavioral verification: PUT HTTP 200, independent `POST_GROUPS=[]`, `EMPTY_GROUPS=YES`. |
| How should existing pre-launch Plus state enter Activation History? | **RESOLVED** | `DEC-PLUS-MIG-01`: permit only the single fully verified case as `active_unbound`; prohibit a reusable metadata heuristic. |
| What is the stable idempotency identity for manual activation? | **RESOLVED** | `DEC-CATALOG-IDEM-01`: client-created operation UUID plus authoritative server/database idempotency record. |
| What happens when trusted refund/cancel precedes or follows activation? | **RESOLVED** | `DEC-PLUS-REFUND-01`: terminal order-identity tombstone, late-activation suppression, and aggregate eligibility recalculation. |
| What evidence survives a crash or retry before destructive group removal? | **RESOLVED** | `DEC-SALLA-REMOVAL-SNAPSHOT-01`: persist the authoritative pre-GET non-target groups by membership identity and `desired_revision`, invalidate them on return to `member`, and never blind-repair a reconciliation conflict. |
| How does Phase 7 handle HTTP 401 from removal GET/PUT transports? | **RESOLVED** | `DEC-SALLA-REMOVAL-401-01`: retain the current Token Manager policy, return `unauthorized_after_token_recovery`, and do not add a removal-specific refresh or destructive PUT replay. |

## Phase 8 closure and Phase 9 release preparation (2026-09-26)

Phase 8 is **CLOSED** after its final independent re-gate:

| Inventory | Result |
|---|---:|
| PHP Phase 1–8 | `1124/1124 PASS` |
| JavaScript | `17/17 PASS` |
| Total | `1141/1141 PASS` |
| Critical findings | `0` |
| High findings | `0` |

H8-01 is closed. When removal is disabled and authoritative reconciliation
confirms that the Plus group remains present while all required non-target
groups are preserved, the durable snapshot transitions from
`prepared`/`ambiguous` to `retryable`. The row remains `not_member` at the same
`desired_revision`, but disabled scheduling no longer repeatedly polls it.

The Phase 9 Design/Audit verdict is **READY WITH NOTES**. Its operational
decisions are approved with the following deployment constraint:

### DEC-P9-DEPLOY-01 — Atomic where verified

Production deployment must be as atomic as the verified Production filesystem
and hosting layout permit, and must use maintenance mode, a cron pause, and a
post-deployment schema gate. A versioned release directory with an atomic
symlink switch is preferred only if later read-only Production inspection
proves that this mechanism fits the actual hosting layout. Phase 9 preparation
must not assume that symlink deployment already exists.

Code deployment, the single verified pre-launch activation backfill, and
destructive `not_member` removal enablement remain three separately authorized
operations. Success of one does not authorize the next. The option
`pge_salla_not_member_removal_enabled` remains missing/disabled by default.

This release-candidate preparation records no Production rollout, Production
schema mutation, activation backfill, feature enablement, or real Salla HTTP.

## DEC-PLUS-MIG-01 one-off implementation (2026-09-30)

The approved pre-launch backfill is implemented as an explicit CLI-only tool,
hard-scoped to WP user `380` and the verified Salla order identity documented
above. It is not registered on plugin activation, `plugins_loaded`, `init`,
cron, schema upgrade, webhook handling, or any normal web request.

Before writing, the tool requires the exact historical Catalog User Meta,
including cycle `6daa5ee5-5bda-43a6-a392-ad75402b8c21`, plan/tier `2/6`, order
`1576373696`, product `1539650850`, active `halwa_plus/guests_100`, and the
verified quota and credit values. It stops if the user is missing, a trusted
revocation tombstone exists, an event already owns the cycle, or activation,
idempotency, provider-origin, or replay state is partial or conflicting.

The lock order is user activation, Salla provider-order, then event activation.
After all three locks are held, guards are checked again before and inside a
single transaction. That transaction creates the `backfill` activation in
`preparing`, creates its exact Salla provider origin, then uses repository CAS
to transition `preparing -> active_unbound` with historical `activated_at`.
Any proven failure rolls back both rows. Database uniqueness remains the final
collision boundary.

The persisted projection snapshot is an explicit whitelist of the verified
historical entitlement. Mutable invitation and replacement usage is stored in
`credit_cycle.initial_used`; operational email markers are excluded. The tool
never invokes `PGE_Catalog_Activation_Service::activate()`, projection building
or application, User Meta writes, credit grants, event creation/binding,
membership projection, removal scheduling, feature-flag changes, email, or
Salla HTTP. An exact matching replay returns `already_backfilled`; any mismatch
stops instead of repairing data.

### Production execution result (2026-10-01)

The separately authorized Production invocation completed successfully:

```bash
php wp-content/plugins/pgevents-core/tools/dec-plus-mig-01-backfill.php --execute-dec-plus-mig-01
```

The resulting durable activation is catalog activation `1`, activation
`6daa5ee5-5bda-43a6-a392-ad75402b8c21`, in `active_unbound`, with
`activation_source=backfill`. Post-backfill verification confirmed that User
Meta and credit counters were unchanged. The read-only aggregate resolver was
authoritative and returned `eligible=true`, `active_activation_count=1`, and
`reason=active_salla_plus`. The destructive removal feature remained
missing/disabled.

The tool itself returned `membership_projection=not_requested`. This describes
the synchronous backfill operation only; it is not a durable suppression of
the independent eligibility-recovery subsystem.

After WP-Cron resumed, a membership row appeared for merchant `392732220`,
customer `1888007575`, and Plus group `225189340`. It had
`desired_state=member`, `desired_revision=1`, `status=satisfied`, and
`attempt_count=1`; it was created at `2026-10-01 03:36:16 UTC` and recorded
`last_success_at=2026-10-01 03:38:17 UTC`. All `removal_snapshot_*` fields were
`NULL`.

This is a Phase 9 operational/runbook isolation finding, not a backfill bug.
`pge_salla_membership_sync_recovery` can discover durable eligible Salla Plus
activations and independently invoke aggregate eligibility recomputation and
membership projection. Consequently, restoring or running WP-Cron while that
recovery is enabled implicitly permits aggregate membership convergence. If a
future controlled backfill requires membership projection to remain separately
operator-authorized, keep WordPress cron and any external cron runner paused
through that approval boundary, or introduce a separately reviewed recovery
gate before restoring them.

The durable `satisfied` row proves successful convergence. Available logs did
not establish whether the successful attempt was a direct add POST success or
another reconciliation path; no exact Salla HTTP operation is asserted here.

Success is either `backfilled` on the first run or `already_backfilled` on an
exact replay. Any `stopped` result is a hard stop; do not edit rows or retry
blindly. `storage_uncertain` requires incident review before any retry.

### Future read-only verification

From the WordPress root, the following command prints only the bounded durable
activation, provider origin, event-binding count, selected historical User
Meta, and removal flag; it performs no writes:

```bash
wp eval '$a=PGE_Catalog_Activation_Repository::find_by_activation_id("6daa5ee5-5bda-43a6-a392-ad75402b8c21"); $o=is_array($a)?PGE_Catalog_Provider_Origin_Repository::find_by_activation_and_provider((int)$a["id"],"salla"):null; echo wp_json_encode(["activation"=>$a,"origin"=>$o,"binding_count"=>is_array($a)?count(PGE_Catalog_Event_Binding_Repository::find_by_activation_id((int)$a["id"])):null,"meta"=>array_map(fn($k)=>get_user_meta(380,$k,true),["_mon_package_source","_mon_catalog_plan_id","_mon_catalog_tier_id","_mon_catalog_plan_key","_mon_catalog_tier_key","_mon_package_status","_mon_credit_cycle_id","_mon_last_order_id","_mon_salla_product_id","_mon_invitation_credit_total","_mon_invitation_credit_used","_mon_replacement_credit_total","_mon_replacement_credit_used"]),"removal_flag"=>get_option("pge_salla_not_member_removal_enabled","MISSING")],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;'
```

This verification command remains read-only. The Production backfill execution
and its post-backfill operational observation are recorded above.
