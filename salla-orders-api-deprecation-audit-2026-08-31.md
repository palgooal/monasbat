# Salla Orders API Deprecation Audit — 31 August 2026

**Scope:** wp-content/plugins/pgevents-core (the only code in this repository that references Salla), plus wp-content/themes/pgevents-pro, docs/, and root-level project notes. Audit only — no code was modified.

**Trigger:** Salla's final notice that legacy Orders API behavior is deprecated starting 1 September 2026 — `expanded=true` dropped from List Orders, the legacy expanded Order Details response dropped in favor of a `light` response (already testable via `format=light`), with Order Items and Order Shipments becoming dedicated endpoints.

---

## A. Verdict

**PASS WITH NOTES.**

The reachable production code makes **zero HTTP calls to any Salla Orders API endpoint** (List Orders or Order Details, expanded or light). Every field the system consumes for package/bouquet activation, deactivation, customer identification, and idempotency comes exclusively from the **Salla webhook payload** (`order.created`, `order.updated`, `order.payment.updated`, `order.status.updated`), which is a separate contract from the Orders API and is not named anywhere in Salla's deprecation notice as quoted. Because there is no Orders API dependency to begin with, the 1 September 2026 change cannot break this system's reachable order-processing path.

The "with notes" qualifier is because: (1) a dead, unreachable duplicate handler still exists in the codebase and should be removed so nobody mistakes it for live code; (2) there is no dedicated test that exercises the webhook→fulfillment pipeline with a realistic order payload, so the one contract this system *does* depend on (the webhook shape) has no regression safety net; and (3) the assumption that webhooks are unaffected by this specific Salla notice is reasonable but should be confirmed against Salla's current documentation rather than left implicit. None of these block the 1 September deadline.

---

## B. Production Calls

| File | Class/Function | Endpoint | HTTP Method | Query Params | Caller | Fields Consumed | Reachable in Production? | Migration Risk |
|---|---|---|---|---|---|---|---|---|
| — | — | *(none found)* | — | — | — | — | — | **None** |

No file in `wp-content/plugins/pgevents-core` or `wp-content/themes/pgevents-pro` issues `wp_remote_get`, `wp_remote_post`, `wp_remote_request`, `curl_init`, or `file_get_contents()` against any `salla.sa` / `api.salla.dev` / `salla.dev` host. The only outbound HTTP calls in the plugin target UltraMsg and Cartat (WhatsApp providers) — unrelated to Salla.

Salla OAuth credentials (`pge_salla_client_id`, `pge_salla_client_secret`) and per-merchant tokens (`pge_salla_tokens_{merchant_id}`, captured from the `app.store.authorize` webhook) are stored in `wp_options`, but **no code path reads them to call any Salla API**. This is dormant capability, not a live dependency — confirmed by `PROJECT-NOTES.md` which lists "Salla Merchant API client مع Rate Limiting" as a **backlog item, not yet built**.

A second, disabled duplicate handler exists: `includes/class-mon-salla-api.php.disabled`. It is not `require`'d anywhere (`pgevents-core.php` only loads `class-salla-handler.php`), and its `.disabled` extension means WordPress/PHP will never autoload or execute it. It also makes no Orders API calls — it only reads `$body['data']['items'][0]['product']['id']` from a webhook body — so even if it were somehow reactivated, it would not have depended on `expanded=true`. It is dead code, listed here for completeness and flagged for cleanup in section H.

---

## C. `expanded=true` / `expanded` / `format=light` Findings

Full repository-wide search results (PHP, JS, JSON) across the plugin and theme:

| Literal | Occurrences | Production-relevant? |
|---|---|---|
| `expanded=true` | 0 | N/A — never appears |
| `expanded` (bare) | 4 | **No** — all are the HTML/ARIA attribute `aria-expanded` on an accordion-style "Send Thank You" button in `templates/event-invitations.php` (lines 102, 2026, 2037) and one matching assertion in `tests/test-thank-you-ui-phase4b3b.php`. None relate to Salla or any API response. |
| `format=light` | 0 | N/A — never appears |
| `'format' => ...` / `"format"` (bare `format` key) | ~10 | **No** — all belong to the XLSX import/export subsystem (`class-pge-invitation-export.php`, `SimpleXLSX.php`, cell-format stubs in Excel-import tests) or `$wpdb->insert()`'s `$format` parameter. None reference Salla or `light`. |

No dynamically constructed query string (e.g., string concatenation or `http_build_query()` building an `/orders` URL) was found anywhere, because — per section B — no code constructs any Salla Orders API request at all.

---

## D. Order Details Contract — Fields Actually Consumed

Since no Order Details (or List Orders) API call exists, there is no "Order Details contract" in the Salla-API sense to evaluate against light/expanded. Instead, here is every field the system consumes, all sourced from the **webhook payload**, with the requested classification:

| Field | Consumed by | Classification | Note |
|---|---|---|---|
| `order.id` (top-level `id`) | `extract_order_id()` | **E** — webhook | Used as the sole order identifier for matching, idempotency, and logging |
| `order.status.slug` | `handle_order_event()` | **E** — webhook | Routes to activation (`completed`/`delivered`) or deactivation (`canceled`/`cancelled`/`refunded`/`returned`) |
| `order.customer.email` | `extract_customer_email()` | **E** — webhook | Legacy path email match/creation; also used in Catalog path as a secondary identity signal |
| `order.customer.mobile` | `extract_customer_mobile()` | **E** — webhook | Primary identity key for Catalog customer resolution/creation |
| `order.customer.name` | `extract_customer_name()` | **E** — webhook | Display name on auto-created customer accounts |
| `order.items[]` (array) | `classify_order_items()` | **E** — webhook | Iterated once per order |
| `item.product.id` / `item.product_id` | `extract_product_id()` | **E** — webhook | Falls back from `product.id` to a flat `product_id` key |
| `item.sku` | `extract_salla_sku()` | **E** — webhook | Primary key for bouquet/tier matching against the internal Catalog table |
| `item.quantity` | `classify_order_items()` | **E** — webhook | Logged only (`catalog_quantity_ignored`) — quantity > 1 does not multiply activation |
| `item.amounts.price_without_tax.amount` / `.currency` | `extract_catalog_unit_price()` / `extract_catalog_currency()` | **E** — webhook | Preferred price/currency source for Catalog price-match validation |
| `item.amounts.total.amount` / `.currency` | same as above | **E** — webhook | Fallback when `price_without_tax` is absent (total ÷ quantity) |

**None** of these fields are classified A/B/C/D (light response, legacy-expanded-only, Order Items endpoint, or Order Shipments endpoint), because none of them are ever fetched from the Orders API — they all arrive pre-packaged in the webhook body. Order Shipments data is not consumed anywhere in this codebase at all (there is no shipping/fulfillment-address logic tied to Salla order data).

**Requires documentation verification (F):** the *webhook* payload shape itself — specifically that `order.status.updated` nests order data under `data.order` while `order.created`/`order.updated`/`order.payment.updated` place it directly under `data` — is documented in this project only as an empirical finding (`PROJECT-NOTES.md`, 2026-04-30: "اكتشف من اختبار سلة الحقيقي" / "discovered from real Salla testing"), not cited against Salla's current webhook documentation. This is a pre-existing characteristic of the webhook contract, unrelated to the 1 September Orders-API change, but it is the single assumption the whole PASS verdict rests on, so it's worth Salla-doc confirmation that webhook payloads are unaffected by this deprecation round.

---

## E. Bouquet/Package Fulfillment Impact

Traced end-to-end:

`Salla webhook (order.created / order.updated / order.payment.updated / order.status.updated)` → `Mon_Salla_Handler::handle_salla_notification()` (HMAC-SHA256 signature check via `x-salla-signature`) → `handle_order_event()` (routes on `status.slug`) → `process_order_packages()` → `classify_order_items()` (reads `items[]`, matches `sku` then falls back to `product.id` against `PGE_Catalog::get_tier_by_salla_sku()` / `get_tiers_by_salla_product_id()` — an **internal, admin-managed database table**, not a Salla API call) → `process_catalog_match()` (validates price/currency/tier/plan, resolves or creates the customer by mobile/email) → `Mon_Events_Users::activate_catalog_tier()` / `deactivate_catalog_tier()` (writes the user's plan/tier/feature snapshot) → `PGE_Package_Activation_Email::send()`.

Bouquet/package identification is keyed entirely on `item.sku` and `item.product.id`/`item.product_id`, both read straight from the webhook body, matched against the internal `PGE_Catalog` tables that store `salla_sku` / `salla_product_id` per tier. **No step in this chain ever calls the Orders API, List Orders, or Order Details endpoint**, expanded or light. The Salla change of 1 September 2026 therefore has **no effect** on bouquet/package detection or execution in this system.

---

## F. Dedup/Idempotency Impact

Two independent, order-id-keyed idempotency guards exist, both keyed **exclusively** on the webhook's own `order.id` field:

1. **Package activation** (`Mon_Events_Users::activate_catalog_tier()`): compares the incoming `order_id` against `_mon_last_order_id` user meta together with `_mon_package_source === 'catalog'`, `_mon_package_status === 'active'`, matching `plan_id`/`tier_id`. An exact repeat returns early (`return true`) without re-writing state or re-accumulating invitation/replacement credit.
2. **Activation email** (`PGE_Package_Activation_Email::send()`): a MySQL `GET_LOCK()` named from `md5(user_id|order_id)` plus a per-order marker user-meta key (`hash('sha256', order_id)`) guarantee the email is sent at most once per `(user_id, order_id)` pair, surviving Salla's webhook retries.

Since neither guard reads anything from the Orders API — both depend solely on the `id` field already present in the webhook body — the 1 September change **cannot** cause:
- **duplicate fulfillment** — the dedup key doesn't change,
- **skipped fulfillment** — activation doesn't depend on any now-removed field,
- **failure to identify an order** — `order.id` is a webhook top-level field, not an Orders API field,
- **incorrect package/product identification** — SKU/product-id matching is webhook- and internal-DB-driven, not Orders-API-driven.

---

## G. Tests/Fixtures Risk

No JSON or PHP-array fixture anywhere in the repository models a Salla **List Orders**, **Order Details** (light or expanded), **Order Items**, or **Order Shipments** response. This means there is no fixture that could "falsely pass" today and silently break tomorrow because it encodes a field that the light response drops — that specific risk class does not exist here, because nothing tests against an Orders-API shape at all.

What does exist, and its actual coverage:

- `tests/test-catalog-plan-limits.php` — instantiates `Mon_Salla_Handler` and, via `ReflectionMethod`, calls the **private** `deactivate_user_package($email, $order_id)` method directly with hand-supplied strings. It verifies the Catalog-vs-Legacy protection guard, but never exercises `classify_order_items()`, `process_catalog_match()`, or a realistic order/webhook payload with `items`/`customer`/`amounts`.
- `tests/test-package-activation-email.php` — asserts (via a source-text `check_contains`) that the *signature* of `classify_order_items($order_data, $order_id, $action)` hasn't changed. It does not invoke the method.
- `tests/test-package-catalog-salla-cta.php` — a boundary scan on the **storefront catalog-browsing widget** (not the order handler), asserting that widget file contains **zero** occurrences of `wp_remote_get`/`wp_remote_post`/`wp_remote_request`/`curl_exec`/`curl_init`/`file_get_contents('https://api.salla…`. This is unrelated to order processing and is unaffected by the Orders API change (it already asserts the widget never talks to Salla at all).

**Gap identified (not caused by the Sept 1 change, but relevant to this audit):** there is no end-to-end test that feeds `Mon_Salla_Handler` a realistic `order.status.updated`/`order.created` webhook body (with nested `data.order`, `items[]`, `customer{}`, `amounts{}`) and asserts the resulting user-meta/email outcome. This is the one place a future contract change — in the webhook, not the Orders API — could go unnoticed. See P1 recommendation below.

---

## H. Required Migration

**No P0 changes are required before 1 September 2026.** The reachable code has no dependency on the deprecated Orders API behavior.

**P1 — important, not urgent:**
1. Delete `wp-content/plugins/pgevents-core/includes/class-mon-salla-api.php.disabled`. It is dead, unreachable, ~80%-duplicate code (already flagged as backlog item #6 in `PROJECT-NOTES.md`). Removing it eliminates any chance of a future contributor reactivating stale, redundant webhook-parsing logic.
2. Add one integration-style test (`tests/test-salla-webhook-order-processing.php` or similar) that drives `Mon_Salla_Handler::process_order_packages()`/`classify_order_items()` with a realistic order-webhook fixture (items, customer, amounts, both the flat `data` shape and the nested `data.order` shape) to lock in the parsing contract this system actually depends on.
3. Extend `docs/integrations/SALLA.md` with the concrete webhook payload shape per event type (`order.created`/`order.updated`/`order.payment.updated` = flat `data`; `order.status.updated` = `data.order`), sourced and verified against Salla's current webhook documentation rather than left as an inferred, undocumented empirical finding.

**P2 — cleanup/future hardening:**
1. If the backlogged "Salla Merchant API client مع Rate Limiting" is ever built, it must be built from day one against the **light** Order Details response plus the dedicated **Order Items** and **Order Shipments** endpoints — `expanded=true` and the legacy expanded Order Details response must never be assumed available, since both are gone as of 1 September 2026.

---

## I. Exact Files That Would Need Modification

No production file requires modification for the 1 September 2026 deadline. For the P1/P2 notes above, if the team chooses to act on them:

- `wp-content/plugins/pgevents-core/includes/class-mon-salla-api.php.disabled` — delete (P1).
- `wp-content/plugins/pgevents-core/tests/` — add a new test file exercising the webhook→fulfillment path (P1).
- `docs/integrations/SALLA.md` — extend with the verified webhook payload contract (P1).
- Any future new file implementing a Salla Merchant API client — must target light/Order-Items/Order-Shipments endpoints from the start (P2, not yet created).

---

## J. Verification Plan

**Static (repeatable, already performed for this audit):**
- `grep -rn "expanded" wp-content/plugins/pgevents-core wp-content/themes/pgevents-pro` — confirm all hits remain `aria-expanded` UI attributes only.
- `grep -rn "format.*light\|format=light" wp-content/plugins/pgevents-core wp-content/themes/pgevents-pro` — confirm no hits.
- `grep -rn "wp_remote_get\|wp_remote_post\|wp_remote_request\|curl_init" wp-content/plugins/pgevents-core wp-content/themes/pgevents-pro` — confirm none target `salla.sa`/`salla.dev`/`api.salla`.
- `grep -rn "mon-salla-api" wp-content/plugins/pgevents-core/pgevents-core.php` — confirm the disabled duplicate remains un-required.

**Production-safe runtime checks (no behavior change, observation only):**
- After 1 September 2026, use Salla Partner Portal's webhook test/resend feature to redeliver a real `order.status.updated` (and `order.created`) event against the live `/wp-json/mon/v1/salla-callback` endpoint. Confirm: HTTP 200 response; the expected `catalog_activation_success` (or legacy equivalent) entry appears in `error_log`; `_mon_last_order_id` user meta is set to the correct order id; the activation email is sent exactly once.
- Redeliver the identical webhook a second time (simulating Salla's own retry behavior) and confirm no duplicate email, no duplicate invitation/replacement credit accumulation, and the early-return "already applied" path is taken.
- Confirm no error-log entries reference Salla API HTTP failures (there should be none, since no such calls exist) in the days surrounding 1 September 2026.

**Regression test (once the P1 test file is added):**
- `php tests/test-salla-webhook-order-processing.php`, `php tests/test-catalog-plan-limits.php`, and `php tests/test-package-activation-email.php` should all continue to pass in CI or on manual run.

**Documentation verification (explicitly required, not inferred from memory):**
- Confirm against Salla's current webhook documentation (docs.salla.dev → Webhooks/App Events) that webhook payload contracts (`order.created`, `order.updated`, `order.payment.updated`, `order.status.updated`) are genuinely out of scope of the Orders-API deprecation notice quoted for this audit — the notice as given names only List Orders and Order Details behavior. This is the one assumption this PASS verdict depends on and should be confirmed rather than left implicit.
