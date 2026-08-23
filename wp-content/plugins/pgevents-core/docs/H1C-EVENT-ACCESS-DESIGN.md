# H1C Event Access Design

> **Status of this document:** CURRENT operational/design source of truth for H1C (Event
> Access / Additional Inviter). `docs/DECISION-LOG.md` remains the historical
> decision-rationale log — it is not duplicated here, only referenced. If this document and
> the actual code/tests ever disagree, code and tests are authoritative; treat the
> discrepancy as a doc bug to fix, not as a spec to enforce.

---

## 1. Purpose and Scope

H1C ("Event Access") is the subsystem responsible for:

- Invitation groups (create/rename/archive/set default) for an event.
- Collaborator memberships on an event: Owner, Administrator, Manager, Viewer.
- Group access grants (which memberships can see/act on which groups).
- Guest assignment to groups (assign/move/unassign) and scoped guest creation.
- Guest **read** scoping and projection (who sees which guest fields).
- The **Additional Inviter** role: a specialized, quota-bounded Manager membership.
- Additional Inviter onboarding (email invite → token-based join → membership creation).
- The Owner-facing "Invitation Team" management screen.
- The Additional-Inviter-facing "My Invitations" self-service screen and dashboard
  discoverability.

### Explicitly NOT H1C

- **Entry Supervisor / check-in staffing** — a separate subsystem for scanning QR codes and
  confirming attendance on event day. See `ENTRY-SUPERVISORS-DESIGN.md`. Do not conflate an
  Additional Inviter with a Supervisor — see §11.
- **Guest Invitation delivery/messaging pipeline** (Reminder/Thank-You, WhatsApp/Cartat) — see
  `MESSAGING-ARCHITECTURE.md`. H1C's own onboarding email (§9) is a separate, narrower path.
- **RSVP engine** — H1C reads RSVP/invitation status to compute quota occupancy (§9) but does
  not own RSVP collection or state transitions.
- **Package/feature-tier system** — quota here is a per-membership `allocated_quota` set by
  the Owner at invite time, unrelated to `PACKAGE-FEATURE-MATRIX.md` tier limits.

---

## 2. Terminology

| Term | Meaning |
|---|---|
| Event Owner | The WP user who authored the event post (`post_author`). |
| Administrator | A WP user with the `administrator` capability. Treated as Owner-equivalent throughout H1C. |
| Manager | An active event-access membership with `role = manager`. Scoped to its granted groups. |
| Viewer | An active event-access membership with `role = viewer`. Read-only, more restricted projection than Manager. |
| Additional Inviter | A specialized Manager membership: `status = active AND role = manager AND allocated_quota IS NOT NULL`, with exactly one granted group (DEC-005). |
| Group | An invitation group (`pge_event_invitation_groups`) — the unit of scoping for both guest assignment and membership access. |
| Membership | A row binding a WP user to an event with a role and status (`pge_event_host_memberships`). |
| Group Access | A grant binding a membership to a specific group (`pge_event_host_group_access`). |
| Guest Assignment | The group a guest is currently assigned to (`pge_invitation_group_assignments`). |
| Guest Invitation | The Phase 9 guest record (stored in `_pge_invited_guests` Post Meta) — the invited guest itself, unrelated to H1C. |
| Additional Inviter Onboarding Invitation | The H1C-W10 record (`pge_additional_inviter_invitations`) representing a pending offer to become an Additional Inviter. Distinct entity, distinct lifecycle from a Guest Invitation (DEC-007). |

---

## 3. Actor Model

Additional Inviter is **not** a separate role in the schema — it is a Manager membership that
also satisfies the quota+single-group predicate above (DEC-005). The table below reflects
capability as actually enforced in `PGE_Event_Access_Authorization` and
`PGE_Additional_Inviter`/`PGE_Additional_Inviter_Onboarding`.

| Capability | Owner | Admin | Manager | Viewer | Additional Inviter |
|---|:---:|:---:|:---:|:---:|:---:|
| View scoped guests (own granted groups) | ✅ (full) | ✅ (full) | ✅ (Manager projection) | ✅ (Viewer projection, fewer fields) | ✅ (Manager projection, own group only) |
| View unassigned guests | ✅ | ✅ | ❌ | ❌ | ❌ |
| Manage groups (create/rename/archive/set default) | ✅ | ✅ | ❌ | ❌ | ❌ |
| Manage memberships (create/change role/revoke/reactivate) | ✅ | ✅ | ❌ | ❌ | ❌ |
| Manage group access (grant/revoke) | ✅ | ✅ | ❌ | ❌ | ❌ |
| Assign guest from unassigned pool | ✅ | ✅ | ❌ | ❌ | ❌ |
| Move guest between groups | ✅ | ✅ | ✅ (both groups must be granted) | ❌ | n/a¹ (only one group granted) |
| Unassign guest | ✅ | ✅ | ✅ (if group granted) | ❌ | ✅ (own group only) |
| Create a scoped guest directly (no pool browsing, no group choice) | n/a¹ | n/a¹ | ✅ (authorization-level; any active Manager) | ❌ | ✅ (the primary real-world use of this capability) |
| Manage Invitation Team (invite/revoke Additional Inviters) | ✅ | ✅ | ❌ | ❌ | ❌ |
| Self-service "My Invitations" | ❌ | ❌ | ❌ | ❌ | ✅ |
| View audit trail | ✅ | ✅ | ❌ | ❌ | ❌ |

¹ "n/a" marks a workflow that does not apply to that actor — e.g. Owner/Admin already assign
guests via the unassigned pool (§3 row above) and are not restricted to a single group, so the
narrow "one-group, no-pool-browsing" scoped-creation workflow is simply not their path. It does
not mean Owner/Admin lack general guest-creation/management capability.

Note on "Create a scoped guest": `can_create_scoped_guest()` is an authorization-layer check
that only requires an active Manager membership — it does not itself check quota or
group-count. The quota-configured, single-group business rule that turns a plain Manager into
an "Additional Inviter" is enforced separately, by the caller (`PGE_Additional_Inviter`), as a
state/configuration check, not an authority check.

---

## 4. Data Model

High-level entities (all under the `PGE_Event_Access_Repository` boundary unless noted):

| Table | Purpose |
|---|---|
| `pge_event_invitation_groups` | Groups defined for an event. |
| `pge_event_host_memberships` | Collaborator memberships (role, status, `allocated_quota`). |
| `pge_event_host_group_access` | Grants: which membership can act on which group. |
| `pge_invitation_group_assignments` | Which group a guest (by phone) is currently assigned to. |
| `pge_event_access_audit_log` | Audit trail for H1C mutations. |
| `pge_event_guest_read_projection` (+ `..._state`) | Derived, rebuildable relational projection of guest identity, used only for scoped reads (§7). |
| `pge_additional_inviter_invitations` | Additional Inviter onboarding invitations (H1C-W10), separate table, separate lifecycle from guest data. |

**Source-of-truth relationships:**

- Guest identity/details remain authoritative in `_pge_invited_guests` Post Meta. H1C
  relational tables do not replace that source of truth; guest-creation flows (including
  Additional Inviter scoped guest creation, via `PGE_Invitation_Service::
  create_for_additional_inviter()`) may update it through the established guest write path
  (`PGE_Invitation_Service`/`PGE_Invitation_Repository`), not through H1C's own tables.
- `pge_event_guest_read_projection` is a derived, rebuildable read-side cache, synced through
  the existing `pge_event_guests_save_map()` write point. If it is stale/missing, it is
  rebuilt on demand (`rebuild_event()`); it is never treated as authoritative over Post Meta
  (DEC-004 / H1C-GR1).
- Group/membership/access/assignment tables are themselves the source of truth for H1C's own
  scoping structure — nothing derives them from Post Meta.

---

## 5. Core Invariants

- A membership belongs to exactly one event; group access grants and guest assignments are
  always scoped within a single event (no cross-event leakage).
- An Additional Inviter (quota-enabled Manager) must have **exactly one** granted group.
  Membership rows with zero or more than one granted group are treated as misconfigured and
  fail closed on quota reads (`PGE_Additional_Inviter::resolve_quota_status()`).
- Additional Inviter predicate: `status = active AND role = manager AND allocated_quota IS NOT
  NULL` (DEC-005). No separate database flag exists for this.
- At most one **active, quota-enabled** Manager membership may be bound to a given group at a
  time — enforced at write time
  (`PGE_Event_Access_Repository::ensure_no_active_quota_inviter_for_group()`), re-checked
  defensively on read.
- A pending onboarding reservation blocks a second concurrent invite for the same group or the
  same invitee email while it is still unexpired (`find_blocking_pending_invitation()`) — this
  is how the group-reservation window is enforced.
- All authenticated H1C mutations derive the acting user from the WP session
  (`get_current_user_id()`/nonce-verified request), never from a client-supplied user/actor
  id. The one narrow exception is documented in DEC-007 (see below).
- **EC1 privacy collapse:** any authorization failure — including "event does not exist" —
  collapses to the same external `not_authorized` result. No response distinguishes "denied"
  from "does not exist" (DEC-006).
- The raw Additional Inviter onboarding token is never stored — only its SHA-256 hash. The
  token is single-use: the GET preview never mutates state; consumption happens only after a
  POST completion successfully creates the membership (DEC-007).

---

## 6. Authorization Architecture

Layering, outer to inner:

1. **AJAX transport** (`event-access-ajax.php`, `additional-inviter-ajax.php`,
   `additional-inviter-onboarding-ajax.php`) — nonce verification, input shape validation,
   resolves the actor/event context, calls the Application Service, shapes the JSON response.
2. **Application Service** (`PGE_Event_Access_Application_Service`,
   `PGE_Additional_Inviter`, `PGE_Additional_Inviter_Onboarding`) — orchestrates a use case:
   resolves context, calls Authorization, calls Repository, applies projection.
3. **Authorization Core** (`PGE_Event_Access_Authorization`) — pure capability checks
   (`can_*`) against a resolved context; also owns guest field projection
   (`project_guest_fields()`). Contains no I/O.
4. **Repository** (`PGE_Event_Access_Repository`) — the only layer that touches the database;
   enforces data-level invariants (§5) but performs **no authorization** of its own.

**Owner/Admin** get flat, unscoped management access to structural operations (groups,
memberships, group access grants, audit, unassigned pool). **Manager/Viewer** get scoped
behavior gated by `has_group_access()` against their granted groups, with Viewer strictly more
restricted (no assign/move/unassign, narrower field projection). **Additional Inviter** is a
Manager whose one granted group and quota state make `can_create_scoped_guest()` its
practically-used path — it does not gain any capability a plain Manager lacks; it is a
narrower, configuration-gated use of the same authorization surface.

**UI/template gates are presentation-only.** Every template (`event-groups.php`,
`event-invitation-team.php`, `my-invitations.php`) may check `current_user_can()` or
`is_user_logged_in()` before rendering, but this never substitutes for the AJAX-layer
authorization above — a template gate controls what loads visually, not what a request is
permitted to do.

**BACKEND: IMPLEMENTED (D2-W1 through D2-W7, DEC-009). USER-FACING CALLER: NOT IMPLEMENTED
YET.** A new, narrower authorization concept — scoped sending — exists in code today, layered
the same way as the checks above and without widening any generic Manager capability elsewhere
in this table:

- `PGE_Event_Access_Authorization::can_send_guest_invitation()` exists and is the actual
  capability check — it lets an Additional Inviter trigger sends restricted to guests currently
  assigned to their own granted group, with Owner/Admin retaining an unconditional, event-wide
  operational bypass.
- Scoped send authorization (request-time) exists via `PGE_Invitation_Send_Application`
  (D2-W3/D2-W4) — the shared D2 sending pipeline (§9).
- **Execution-time reauthorization exists**: `PGE_Invitation_Send_Worker` (D2-W6) calls
  `can_send_guest_invitation()` again, fresh, at the moment each send is actually executed by
  the asynchronous worker — not only when the send request was first created/enqueued. An actor
  authorized at request time but no longer authorized (revoked membership, lost qualifying
  Additional-Inviter status, or lost the relevant group scope) by execution time does not have
  that send proceed under their authority. This applies before the transport call, not as a
  guarantee of cancelling a request already transmitted to the provider.
- **What does not exist yet: no AJAX action, no UI element, no user-facing caller of any kind
  invokes this pipeline in production** — see §9 for the full backend/caller distinction.

**EC1** (DEC-006): every Application-layer class that resolves an actor/event context does so
through a local `resolve_actor_context()`-style helper that collapses "not authorized" and
"event does not exist" into the same denial, so no response leaks resource existence to an
unauthorized caller.

---

## 7. Read Surfaces

| Read | Consumer | Scope | Privacy behavior |
|---|---|---|---|
| `pge_event_access_list_guests` | Owner/Admin, Manager, Viewer, Additional Inviter | Own granted groups only (Owner/Admin: all) | Field set varies by role via `project_guest_fields()` — Manager/Additional Inviter get the same projection; Viewer gets fewer fields; unauthorized context gets none. |
| `pge_event_access_list_audit` | Owner/Admin | Event-wide | Owner/Admin only — no scoped/partial view exists. |
| `pge_event_access_get_group` / `list_groups` | Owner/Admin (all); Manager/Viewer (granted groups only) | Per-group `can_view_group()` | Ungranted groups return the same deny shape as nonexistent groups. |
| `pge_event_access_get_membership` / `list_memberships` | Owner/Admin | Event-wide | Owner/Admin only. |
| `pge_additional_inviter_list` | Owner/Admin | Event-wide (Invitation Team screen) | Lists current Additional Inviters for the event; management-only read. |
| `pge_additional_inviter_list_my_events` | The logged-in Additional Inviter | Own memberships only | Self-discovery read — used by the dashboard card; returns only events where the caller holds a quota-enabled Manager membership. |
| `pge_additional_inviter_get_my_quota` / `get_quota` | Additional Inviter (own); Owner/Admin (any, via `get_quota`) | Own membership only for the self-service variant | Returns the quota-status shape from §9's formula; this response is also the real authorization gate for "My Invitations" — the template performs no separate check. |

Internal Repository read helpers (per-field getters, id lookups, etc.) are not enumerated here
— they are implementation detail behind the surfaces above.

---

## 8. Write Surfaces

| Domain | Operations | Gate |
|---|---|---|
| Group Lifecycle | create / rename / archive / set default group | Owner/Admin only |
| Membership Lifecycle | create / change role / revoke / reactivate membership | Owner/Admin only |
| Group Access | grant / revoke group access | Owner/Admin only |
| Guest Assignment | assign (from unassigned pool) / move / unassign | Assign: Owner/Admin only. Move/unassign: Owner/Admin any group, Manager within granted group(s) only. |
| Additional Inviter scoped guest creation | `pge_additional_inviter_create_guest` | Additional Inviter only; group is always the membership's own single granted group — never client-selectable. |
| Additional Inviter quota | `pge_additional_inviter_set_quota` | Owner/Admin only. |
| Onboarding invitation lifecycle | create (`..._invite`) / revoke (`..._revoke`) | Owner/Admin only (`can_manage_membership()`). |
| Onboarding completion | `pge_additional_inviter_onboarding_complete` (public, nopriv) | Token-authenticated only — see §9. |

Request parameters are not enumerated field-by-field except where security-relevant (§12).
Client-supplied resource identifiers such as `event_id`, `group_id`, or `membership_id` are
treated only as the requested target — they identify *what* the request is about, never *who*
is making it. Actor identity is never accepted from the client (always session-derived, §12);
every handler independently authorizes the requested target server-side (Authorization Core,
§6) before acting on it — a client naming a real `group_id` outside the caller's own scope is
authorized-and-denied, not rejected as malformed input.

---

## 9. Additional Inviter Lifecycle

```
Owner selects/creates a group
  → Owner creates an onboarding invitation (email + allocated_quota + group)
      → reservation check: no other unexpired pending invitation for this group/email
      → row inserted (status=pending, raw token issued, only its SHA-256 hash stored)
      → confirmation email sent via wp_mail() directly (outside the Reminder/Thank-You pipeline)
  → invitee opens the public join link (token in URL, GET — preview only, no mutation)
      → existing-account path (already registered under invitee email) OR new-account path
  → invitee submits completion (POST, public/nopriv, token re-verified from scratch)
      → invitation row re-validated fresh (pending, unexpired, hash match)
      → Additional Inviter membership created: role=manager, one group granted, allocated_quota set
      → onboarding invitation row marked consumed (token hash cleared)
  → server-derived redirect to /event-manage/{event_id}/my-invitations/ (event_id from the
    validated invitation row only — never client input)
  → inviter adds scoped guests via "My Invitations" (create_guest — always into their one group)
  → quota is recomputed live on every quota read, never stored as a counter
```

**Quota formula** (`PGE_Additional_Inviter::resolve_quota_status()`):

```
occupied  = attending + pending
available = max(0, allocated_quota - occupied)
```

`declined` and `cancelled` guests are excluded from `occupied` — they free the slot. This does
**not** mean the original guest record is reused: a declined or cancelled guest stays in the
event's history as-is. Freeing a slot means the inviter can add a **different** guest (a new
phone number) using the freed quota — never a duplicate entry for the same declined phone. This
distinction was confirmed important from real-user testing.

### Sending Responsibility (Model D2)

**Backend pipeline: implemented (D2-W1 through D2-W7 — see
`MESSAGING-ARCHITECTURE.md` §8 for the full architectural writeup).** The durable ledger,
execution-time reauthorization, operational queue, Cron-driven orchestrator, and Cartat-transport
worker described below all exist in code and are tested. **No user-facing sending capability
exists yet for either Owner/Admin or the Additional Inviter (see §6, §10)** — nothing in the
product today calls the pipeline's entry point (`request_send_for_actor()`); there is no UI
screen, no AJAX action, no bulk-send surface. Everything in this subsection therefore remains
the **APPROVED D2 TARGET architecture, backend-complete but not yet reachable by any user
action** (DEC-009, refined by its 2026-08-22 technical-decisions update, technical completion
noted in DECISION-LOG.md's 2026-08-23 addendum); the authorization/state rules below describe
what the built pipeline enforces, not a live product capability.

Core rule: sending authority follows the actor's **current** authorized event/group scope, not
the historical creator of the guest — the immutable guest-creation audit trail and the mutable
current group assignment are two distinct signals (§4), and sending authorization uses the
latter, not the former:

- An Additional Inviter may send invitations only to guests currently assigned to the single
  group they are authorized for — regardless of who originally created that guest.
- If a guest moves outside an inviter's authorized group, that inviter immediately loses the
  ability to send to that guest.
- The guest-creation audit trail remains useful for historical attribution only — it is never,
  by itself, the authorization source for a send action.
- Owner/Admin remain an unconditional sending backstop for the event regardless of Additional
  Inviter scope — intentional operational recovery/oversight authority, not impersonation of the
  inviter. Sending history preserves the actual actor who triggered each send.
- Revocation follows current scope immediately: a revoked Additional Inviter loses sending
  authority the moment their membership is revoked. A replacement inviter later granted the same
  group may send to currently-eligible guests in that group regardless of who originally created
  them. Historical send records are not migrated or reattributed.
- **Authorization is re-evaluated at worker execution time, not only at request-creation time.**
  If an actor was authorized when a send request was created but is revoked, loses qualifying
  Additional-Inviter status, or loses the relevant group scope before the asynchronous worker
  actually executes the send, that send must not proceed under their authority. This applies
  before the transport call — it is not a guarantee of cancelling a request already transmitted
  to the provider. Owner/Admin remain governed by their current operational authority under the
  same principle.

**Target pipeline — shared, not parallel:** the target architecture is **one durable
invitation-send pipeline shared by Owner/Admin and Additional Inviter** — the same durable send
ledger, the same dedup/idempotency rules, the same state semantics, the same queue/worker
infrastructure, and the same transport boundary. Authorization differs by actor only:
Owner/Admin get event-wide operational sending authority; an Additional Inviter is limited to
their current authorized group scope. `actor_user_id`/send history always identifies the real
actor; Owner/Admin sending is operational authority, never impersonation of an inviter. **The
existing legacy Owner invitation queue (§1 — outside H1C, see `MESSAGING-ARCHITECTURE.md`) may
remain temporarily during incremental implementation/migration** — this document does not claim
it disappears immediately. The approved direction is that legacy Owner sending ultimately
converges onto the D2 durable pipeline rather than remaining a permanent, untracked parallel
system.

**First implementation phase — provider/channel scope:** Cartat-first — the initial
implementation reuses the existing server-side Cartat transport boundary. UltraMsg is not
redesigned as part of the initial D2 implementation; it remains a deferred
compatibility/integration concern for this phase only, not a claim that UltraMsg is unsupported
by the product as a whole, and the D2 architecture must not deliberately foreclose a future
UltraMsg transport. Separately, and unrelated to provider choice: the manual WhatsApp deep-link
channel is explicitly deferred for Additional Inviters — unlike the server-side queued channel,
it has no server-side authorization boundary or trustworthy send record today.

**Sending primitive:** the fundamental backend operation is an authorized **single-guest send**
— not "send all." That primitive is server-authoritative throughout: actor identity from the
current session, event/resource authorization resolved server-side, the target guest resolved
server-side, and the guest's current group assignment re-validated server-side — no client-
supplied authorization or scope is trusted. Bulk operations (send selected guests, send all
eligible/not-yet-sent guests) are composed **over this same primitive** — they do not get
separate authorization or ledger semantics. The exact bulk UX/UI is not decided by this
document.

**Durable send state — approved technical direction, now implemented:** the existing transient
per-event queue/report state was not a sufficient source of truth for Model D2. The approved
direction — reuse the existing `pge_message_log` table (`message_type = invitation`) as an
immutable attempt/history ledger, not a new D2-specific messaging table — is exactly what was
built. Durable guest identity is the event plus the normalized guest phone, using the same
lifecycle-fencing fields already present on that table. The state names sketched here at
decision time (`not_sent`, `queued` / `send_requested`, `provider_accepted`, `failed`) were
placeholders; the actual implemented column contract is `pending` / `sent` / `failed` /
`ambiguous_transport_error` / `cancelled` — see `MESSAGING-ARCHITECTURE.md` §8.3 for the
authoritative current vocabulary and exact semantics (`sent` means provider-accepted, not
handset-delivery; `cancelled` means the attempt ended before any transport call). This remains
always distinct from RSVP state — the two are never conflated. No state may claim delivery
confirmation unless the provider genuinely exposes verified delivery, and a manual WhatsApp-link
click is never treated as proof of sending. No new table/migration was needed — the existing
`VARCHAR(30)` status column absorbed the additional value.

**Duplicate/resend:** an already successfully-sent guest must not be silently re-sent by
repeating the normal send action; that is a no-op/conflict, not a duplicate attempt. **Explicit
resend is a distinct, separate operation that creates a new legitimate ledger attempt** — it is
never silently merged with a normal send or a technical-failure retry. Owner and Additional
Inviter sends must consult the same durable send ledger for this dedup decision, and actor
identity plus send timestamp remain auditable for every send. Exact cooldown/confirmation UX for
resend is not decided here (§13).

**Owner oversight (future UX requirement, not designed here):** once implemented, the Owner
should be able to see, per guest: sending state, current group, current Additional Inviter (if
any), the actual actor of the latest send, the latest send timestamp, and RSVP state shown
separately from sending state.

---

## 10. User-Facing Surfaces

| Screen | Route | Actor | Purpose | Backend contract |
|---|---|---|---|---|
| Groups | `/event-manage/{event_id}/groups/` (`templates/event-groups.php`) | Owner/Admin | Manage invitation groups and general group-scoped access. | `pge_event_access_*` group/membership/access actions. |
| Invitation Team | `/event-manage/{event_id}/invitation-team/` (`templates/event-invitation-team.php`) | Owner/Admin | List current Additional Inviters, list/revoke pending onboarding invitations, invite a new Additional Inviter. | `pge_additional_inviter_list`, `pge_additional_inviter_onboarding_list_pending`, `pge_additional_inviter_onboarding_invite`, `pge_additional_inviter_onboarding_revoke`. |
| Dashboard discoverability | `/dashboard/` (`templates/dashboard-main.php`) | Any logged-in user | A self-contained section (hidden when empty) surfacing events where the user holds an Additional Inviter membership, linking into "My Invitations". | `pge_additional_inviter_list_my_events`. |
| My Invitations | `/event-manage/{event_id}/my-invitations/` (`templates/my-invitations.php`) | Additional Inviter | Self-service quota summary, add-guest form (no group selector), read-only scoped guest list. | `pge_additional_inviter_get_my_quota`, `pge_additional_inviter_create_guest`, `pge_event_access_list_guests`. |
| Onboarding join | `/additional-inviter/join/{token}/` (`templates/additional-inviter-onboarding-join.php`) | Public/anonymous (token-bearing) | Preview the invitation (masked email, event title), then complete via existing/new account. | `pge_additional_inviter_onboarding_complete` (nopriv). |

No screen above has a guest-sending action for the Additional Inviter today. The backend D2
pipeline that a scoped sending capability on "My Invitations" would call into is implemented
(§9), but the UI action itself — on this screen or anywhere else — is not yet built (Model D2,
§9, DEC-009).

---

## 11. Relationship to Entry Supervisors

Entry Supervisor (`ENTRY-SUPERVISORS-DESIGN.md`) is a **separate, independently-designed
subsystem** for day-of-event check-in staffing (QR scanning / manual search / attendance
confirmation). It shares no table, no membership row, and no authorization path with H1C.

Additional Inviter exists for a different purpose entirely: pre-event **guest invitation
ownership and scoped guest management** delegated by the Owner. Additional Inviter membership
itself grants no check-in capability — Entry Supervisor authority is obtained only through the
separate Supervisor subsystem; the reverse holds equally — Supervisor authority itself grants
no H1C guest-management authority. (This is a separation of authority systems, not a claim that
the same WP user account could never independently hold both.) These two authority systems must
**never** be merged conceptually, in code, or in future UI — this distinction is deliberate and
load bearing.

---

## 12. Security and Privacy Guarantees

- Actor identity on every authenticated H1C action comes from the WP session — never from a
  client-supplied user/actor id.
- The one narrow, documented exception: onboarding completion passes the original inviter's
  `actor_user_id` into `create_additional_inviter_membership()` purely as **audit
  attribution**, and only inside the fully-validated onboarding completion path (token
  validated → invitation still pending/unexpired → invitation row re-validated fresh →
  membership/group invariants checked). It is never a general permission to call Repository
  membership writes with a historical actor id from any other code path (DEC-007).
- EC1: authorization failures (including "resource does not exist") return an identical
  external result — no existence oracle (DEC-006).
- Manager/Viewer/Additional Inviter guest reads are always passed through
  `project_guest_fields()` — never the raw guest payload.
- The onboarding token is stored only as a SHA-256 hash; the raw token lives only in the
  invitation link.
- The public onboarding completion endpoint is token-authenticated only (no login required to
  call it) — it re-validates the token and the invitation row itself before doing anything.
- The post-onboarding redirect destination is derived from the validated invitation's own
  `event_id` server-side; the client cannot supply `event_id` or `redirect_url` to influence
  it.
- Public onboarding responses (preview and completion, both anonymous-origin) expose only the
  fields required by the join flow itself (masked invitee email, event title,
  has-existing-account flag for preview; `ok`/`redirect_url` for completion) — raw token
  hashes, unmasked invitee email, and internal authorization/state identifiers (membership id,
  group id, quota) are not exposed by either.
- An Additional Inviter cannot select an arbitrary group when creating a scoped guest — the
  group is always resolved server-side from their own single granted membership.
- **Implemented backend guarantees (D2-W1 through D2-W7, DEC-009):** actor identity for every
  send is preserved even when Owner/Admin uses their unconditional backstop authority to send on
  an inviter's behalf — this is operational recovery/oversight, never session impersonation of
  the inviter (`actor_user_id` is recorded on every `pge_message_log` row). The underlying send
  primitive itself resolves actor identity, event/resource authorization, and guest resolution
  entirely server-side for every send. Current group-scope is re-validated twice: once at request
  time (`PGE_Invitation_Send_Application`, D2-W3/D2-W4) and again fresh at worker execution time
  (`PGE_Invitation_Send_Worker`, D2-W6, calling `can_send_guest_invitation()` a second time
  immediately before transport) — no client-supplied authorization or scope is ever trusted (§9).
  **Not yet true in practice: no user-facing AJAX/UI caller exists that lets anyone actually
  trigger a send**, single or bulk — these guarantees describe what the backend enforces once a
  caller exists, not a live product capability today (§9).

---

## 13. Known Limits / Deferred Product Questions

### Confirmed technical limits

- W9 pagination totals may include misconfigured rows that the orchestrator subsequently
  skips when rendering — the displayed total and the rendered row count can differ in that
  edge case.

### Resolved by DEC-009 (Model D2 — backend implemented D2-W1 through D2-W7; no UI/AJAX caller yet)

- ~~Can an Additional Inviter send invitations/messages to their own guests?~~ — Yes: the backend
  capability is implemented, scoped to their currently-authorized group, via the shared D2
  pipeline (§9). The product/UI capability — an actual button or action a user can trigger — is
  still pending; no caller exists yet.
- ~~Who owns sending responsibility: Owner only, Additional Inviter, or both?~~ — Both: the
  Additional Inviter within current scope, Owner/Admin as an unconditional backstop (§9).
- ~~Is a send request's authorization still valid if the inviter's status changes before an
  async worker executes it?~~ — No: authorization is re-evaluated at worker execution time, not
  only at request creation (§9, DEC-009 2026-08-22 update).
- ~~Should Owner and Additional Inviter sending share infrastructure, or run as separate
  systems?~~ — Shared: one target pipeline, one ledger, one dedup/state/queue/transport
  boundary; the legacy Owner queue may persist temporarily during migration (§9).
- ~~Which messaging provider does the first D2 implementation target?~~ — Cartat-first;
  UltraMsg is deferred for this phase only, not ruled out long-term (§9).
- ~~Is "send all" the fundamental backend sending operation?~~ — No: an authorized single-guest
  send is the fundamental primitive; bulk operations compose over it (§9).
- ~~What is the durable-ledger technical direction for D2 sending state?~~ — Reuse
  `pge_message_log` (`message_type = invitation`) as an immutable attempt/history ledger, keyed
  by event + normalized phone; no new D2-specific messaging table (§9). The durable-ledger
  implementation is complete; current storage/index details are documented in
  `MESSAGING-ARCHITECTURE.md` §8 and the production Message Log implementation.

### Open UX/Product questions (not decided here)

- Can an Additional Inviter resend an invitation under what cooldown/confirmation UX? (§9
  approves explicit-resend as an architecturally distinct operation from a normal send; the
  exact UX — cooldown, confirmation, wording — is not yet designed.)
- What is the exact Owner-facing UI design for the sending-oversight fields listed in §9?
- What is the exact bulk-sending UX (select guests vs. all-eligible vs. single-guest-only in the
  first release)? (§9 fixes the backend primitive as single-guest with bulk composed over it;
  the UI shape is not decided.)
- What actions are allowed after a guest declines?
- Should the Owner see which inviter created each guest (beyond the audit trail already
  recorded)?
- Should an Additional Inviter be able to edit/delete/cancel/move their own guests?
- Is the quota terminology clear enough in the UI?
- Should quota editing be available directly from the Invitation Team UI?

---

## 14. Decision References

- **DEC-004** — H1C-GR1 (Relational Guest Read Projection) accepted, later completed and wired
  into the Manager/Viewer read path.
- **DEC-005** — Additional Inviter is represented as an Event Access membership, not a
  parallel authorization system.
- **DEC-006** — EC1 authority-collapse pattern: no resource-existence leakage on denial.
- **DEC-007** — Onboarding trust boundary (token-as-authority, hashed storage, actor-id as
  scoped audit attribution) and the Guest Invitation vs. Onboarding Invitation terminology
  split.
- **DEC-008** — Self-service UX boundary: Owner manages Invitation Team, Additional Inviter
  gets a separate self-service experience, backend-driven authorization throughout.
- **DEC-009** — Scoped Sending Responsibility (Model D2): sending authority follows an
  Additional Inviter's current authorized group scope, not historical guest-creation
  attribution; Owner/Admin remain an unconditional backstop. Refined 2026-08-22 with approved
  technical decisions: execution-time authorization revalidation, one shared Owner/Inviter
  target pipeline, Cartat-first provider scope, a single-guest send primitive with bulk composed
  over it, and a `pge_message_log`-based durable ledger direction. **Backend pipeline implemented
  (D2-W1 through D2-W7, technical completion noted in DECISION-LOG.md's 2026-08-23 addendum) —
  no UI/AJAX caller exists yet for either Owner/Admin or the Additional Inviter (§9)**.

---

## 15. Maintenance Rules

- Update this document when H1C behavior or contracts actually change in code.
- Update `DECISION-LOG.md` only when a new durable architectural/product decision is approved
  — not for every change described here.
- Do not treat phase/milestone reports (W1–W10, UI-2, REAL-USER-FIX-1, etc.) as current source
  of truth; they are historical implementation records.
- Code and tests override this document if it ever drifts.
