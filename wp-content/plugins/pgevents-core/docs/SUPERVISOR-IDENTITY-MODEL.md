# Supervisor Identity Model

> Architectural reference document — Entry Check-in Supervisors feature.
> Status: **Adopted / describes the system as implemented through Phase 8.**
> This document does not introduce any new behaviour. It formalizes and freezes
> an identity model that was already implemented implicitly, across Phases 1–8,
> before any future phase (Notifications, Analytics, Performance, Activity,
> Multi-Event Supervisors, Device History, Reports) is allowed to assume one
> model or the other.

---

## 1. Chosen Model

**Model B — Assignment Identity.**

There is no independent "Supervisor" entity anywhere in this system. The
**Assignment** (one row in `{$wpdb->prefix}mon_event_supervisors`) *is* the
identity. An assignment is scoped to exactly one `event_id`. If the same
real-world person supervises three events, three completely independent,
unrelated Assignment rows exist. No code path merges, joins, aggregates, or
authenticates across them. Nothing in the system today knows or claims that
those three rows "are the same person."

Model A ("Supervisor Entity" — an independent identity that owns multiple
Assignments) is **not implemented**, is **not partially implemented**, and no
hybrid of the two models exists anywhere in the codebase (see §4, Hybrid
Verification).

---

## 2. Why This Model Was Selected

This document does not select a new model — it names the model the project
already committed to, decision by decision, across four prior phases:

1. **Phase 1 (Schema)** — `mon_event_supervisors` was designed with `event_id`
   as a first-class scoping column and an explicit product decision: *"Supervisor
   limit is PER EVENT"* (`supervisor-quota-resolver.php`, quota is counted via
   `pge_count_active_event_supervisors($event_id)`, never per-phone or
   per-person). There is no `supervisor_id` foreign key and never has been.

2. **Blocker fix (Phase 2, "Append-Only History")** — an earlier `UNIQUE KEY
   (event_id, supervisor_phone)` constraint was deliberately **removed**. The
   reasoning recorded in `class-mon-catalog-schema.php` at the time: a UNIQUE
   constraint on phone would block re-inviting the same phone number after a
   prior assignment became `revoked`, because it would force the system to
   treat "this phone number" as a persistent identity slot rather than a
   contact detail attached to one disposable assignment. Removing it was a
   direct, explicit rejection of phone-as-identity.

3. **Phase 3 ("Supervisor Authentication" RFC)** — the RFC explicitly decided:
   *"Do NOT reuse WordPress login. Do NOT require WP accounts."* A session
   (`mon_supervisor_sessions`) binds to exactly one `assignment_id` +
   `event_id` pair, never to a phone, a WP user, or any cross-assignment
   identity. `PGE_Supervisor_Session::create_session($assignment_id,
   $event_id)` has no code path that produces one session valid for multiple
   assignments.

4. **Phase 4 (Check-in Engine schema decision, recorded verbatim in
   `class-pge-checkin-schema.php`)** — the original design document proposed a
   column named `checked_in_by` referencing a WordPress `user_id`. This was
   **rejected in favor of `checked_in_by_assignment_id`** specifically because
   "a supervisor does not necessarily hold a WordPress account" (Phase 3's own
   architecture). The audit trail for attendance is anchored to the assignment,
   not to any person-level identity.

5. **`pge_has_active_supervisor_assignment($event_id, $phone)`** (Phase 2) is
   the one function in the codebase that performs a phone+event lookup. Its own
   doc comment is explicit that this is a *data lookup*, not an identity or
   authorization primitive, and that it must never be used as an access guard
   — precisely to prevent this one phone-scoped helper from accidentally
   becoming a backdoor into Model A semantics.

In short: every opportunity this project has had to introduce a persistent,
cross-event Supervisor identity (a unique phone constraint, a WP user link, a
person-scoped session, a person-scoped audit actor) was considered and
explicitly declined, in favor of treating each assignment as self-contained.
Model B is not a gap — it is the accumulated, intentional result of four
phases of decisions. This document exists to make that implicit consistency
explicit and binding for future phases.

---

## 3. Current Implementation Evidence

| Concern | Where | Scoping key | Model |
|---|---|---|---|
| Schema | `class-mon-catalog-schema.php` (`mon_event_supervisors`) | `id` (PK), `event_id`; `event_phone` index is **non-unique** | B |
| Creation | `PGE_Supervisor_Assignment_Service::create_supervisor_assignment()` | `event_id` + phone (duplicate-active check scoped to one event only); `user_id` inserted as `NULL` unconditionally | B |
| Listing | `list_assignments_for_event()` / `list_assignments_for_event_page()` (Phase 5 / Phase 8) | `event_id` only — no cross-event listing method exists | B |
| Edit (Phase 8) | `edit_supervisor_details($id, ...)` | single `id`; duplicate-phone check scoped to the same `event_id` only | B |
| Resend / Revoke (Phase 8) | `resend_invitation($id)` / `revoke_supervisor_assignment($id)` | single `id`, no fan-out to any other row | B |
| Session | `PGE_Supervisor_Session::create_session($assignment_id, $event_id)` / `validate_session()` | one `assignment_id` + `event_id` pair per session row | B |
| Authorization | `pge_is_active_supervisor_for_event($event_id)`, `PGE_Supervisor_Portal_Middleware::authorize()` | resolves `{assignment_id, event_id}` from the session cookie only | B |
| Check-in write | `PGE_Checkin_Recorder` (`checked_in_by_assignment_id` column) | `assignment_id`, not `user_id`/`supervisor_id` | B |
| Statistics / Dashboard | `PGE_Attendance_Statistics_Service::get_supervisor_summary()` | `GROUP BY assignment_id`, `WHERE event_id = %d` | B |
| Host management audit (Phase 8) | `PGE_Supervisor_Management_Audit` | `assignment_id` (actor is the **host**, a WP user, recorded separately from supervisor identity) | B |
| Quota | `pge_resolve_supervisor_quota_status($event_id)` | counts active assignment rows **per event**, not per phone/person | B |

Full grep of the plugin (and theme) for `supervisor_id`, `supervisor_phone`,
and `mon_event_supervisors` confirms: no cross-event join, aggregation, or
identity-merge exists anywhere outside this table's own per-event scoping. The
`user_id` column on `mon_event_supervisors` is written as `NULL`
unconditionally in the only `INSERT` that exists, and is never read/filtered
anywhere in the codebase — it is inert schema, not a working reference.

A regression test (`tests/test-supervisor-management.php`, Scenario 8,
"Different event isolation") already proves structurally that operating on one
assignment can never affect another assignment, even when both belong
conceptually to what a human would call "the same supervisor."

---

## 4. Hybrid Verification

Checked explicitly and confirmed absent: no feature simultaneously assumes
**both** identity models for the same concern. Specifically:

- Quota is **never** counted by phone anywhere alongside its per-event count.
- No session/authentication path resolves "this phone number" as a trusted
  identity; the only phone-based lookup (`pge_has_active_supervisor_assignment`)
  is explicitly documented and enforced (by absence of any caller using it as
  an auth guard) as non-authoritative.
- No dashboard, statistics, or audit view aggregates by phone across events;
  all are `GROUP BY assignment_id` / `WHERE event_id = %d`.
- Editing an assignment's phone or name (Phase 8) never searches for or
  touches any other row that happens to share the same phone number in a
  different event.

**No hybrid identity assumption was found in any file.**

---

## 5. Future Implications (Binding Guidance for Later Phases)

Any future phase must treat the **Assignment row** — never a phone number, a
WP user, or any synthetic "supervisor profile" — as the unit of identity,
unless and until a dedicated future RFC explicitly authorizes introducing a
real `Supervisor` entity (Model A) with its own migration and schema review.
Until that happens:

- **Notifications**: a notification is sent to *an assignment* (its
  `supervisor_phone` at the time of sending), not to "a supervisor's" merged
  contact record. If the same person has three assignments across three
  events, they receive three independent notification relationships, each
  tied to its own assignment row.
- **Performance metrics / Analytics / Reports**: any per-supervisor metric
  (check-ins performed, average confirmation time, etc.) must be computed
  **per assignment_id**, scoped to a single event, exactly like
  `get_supervisor_summary()` does today. A report that says "this phone number
  performed N check-ins across all their events" would require a new,
  explicitly-scoped aggregation feature — it does not fall out of the existing
  data model for free, and must not be implemented by silently joining on
  `supervisor_phone` across events.
- **Last activity**: already defined, per Phase 8, as the assignment row's own
  `updated_at` — not a person-level "last seen anywhere" timestamp. This
  remains correct only if future phases keep last-activity scoped per
  assignment.
- **Multiple events (same person, several assignments)**: this is already
  possible today (nothing prevents it), but the assignments remain and must
  remain **structurally invisible to each other**. There is no "view all my
  events as a supervisor" feature, and building one would require a new
  identity mechanism, not a query over the existing table.
- **Device history**: must be tied to a `session_id`/`assignment_id`, not to a
  phone number, consistent with `mon_supervisor_sessions` today.
- **If a future RFC decides Model A is actually needed** (e.g., a supervisor
  portal that lets one person manage all their assignments from a single
  login), that RFC must explicitly: add a real `Supervisor` entity/table,
  define the migration path for existing rows, and revisit every row in the
  table in §3 above — it cannot be introduced incrementally by adding a
  phone-based join to just one feature. Partial adoption is exactly the hybrid
  state this document rules out.

---

## 6. Explicit Non-Goals of This Document

- This document does **not** change any schema, migration, authentication,
  authorization, recorder, dashboard, statistics, check-in, or invitation
  behaviour. It is descriptive and architectural only.
- This document does **not** create a `Supervisor` table, and does not imply
  one is planned for the immediate next phase.
- This document does **not** deprecate the `user_id` column; it remains
  reserved schema for a possible future Model A migration, exactly as it is
  today (nullable, unused).
- This document does **not** evaluate whether Model A would be a *better*
  long-term architecture — only that Model B is what is implemented today, and
  that all current code is internally consistent with it.
