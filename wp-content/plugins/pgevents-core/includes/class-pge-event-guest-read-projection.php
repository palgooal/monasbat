<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Event Guest Read Projection — Phase H1C-GR1
 * ============================================================================
 * DEC-004 (docs/DECISION-LOG.md): `_pge_invited_guests` (Post Meta) remains
 * the sole Source of Truth for guest identity. This class owns a DERIVED,
 * REBUILDABLE, NON-AUTHORITATIVE relational read model of that same data
 * (phone/name/note/code only — status/status_label/checked are never
 * duplicated here, they stay read from wp_pge_event_rsvps via the existing
 * pge_event_guests_get_row_payload()/pge_event_guests_load_rsvp_from_db()).
 *
 * This class is deliberately NOT authorization-aware: no actor_user_id, no
 * current-user lookup, no nonce, no AJAX, no notion of "who is asking". It
 * knows only (event_id, phones) in and typed guest-identity rows out. The
 * caller (PGE_Event_Access_Application_Service) is solely responsible for
 * deciding *which* phones a given actor may request.
 *
 * Two write entry points, and only two:
 *   - sync_event()   — called from pge_event_guests_save_map() (the single
 *                       existing choke point for every guest-identity write
 *                       in this codebase), with the exact map that was just
 *                       persisted to Post Meta.
 *   - rebuild_event() — lazy rebuild-on-first-read / stale-recovery path.
 *                       Re-reads Post Meta itself (bypassing the WP object
 *                       cache — see pge_event_guests_get_map_fresh()) rather
 *                       than trusting a caller-supplied map.
 * Both funnel into the same private attempt_publish() below — there is no
 * other way rows in either table are ever written.
 *
 * ════════════════════════════════════════════════════════════════════════
 * Freshness Publication Protocol Redesign (this pass)
 * ════════════════════════════════════════════════════════════════════════
 *
 * WHY THE PREVIOUS DESIGN (monotonic expected_generation/ready_generation +
 * CAS) WAS REPLACED
 * ------------------------------------------------------------------------
 * That design's generation counter answered "did a newer *sync attempt*
 * start since I began" — not "does my content match the current
 * authoritative Post Meta". Those two questions coincide ONLY if generation
 * numbers are minted at the exact moment (and in the exact order) their
 * corresponding Post Meta write lands. They are not: establish_generation()
 * runs INSIDE sync_event(), which is called AFTER save_map()'s own
 * update_post_meta() — with an uncontrolled gap in between (PHP execution
 * time, any code between the two calls, scheduler preemption, ...). Two
 * concurrent writers' Post-Meta-write-order and generation-mint-order can
 * therefore diverge:
 *
 *   Writer A: update_post_meta(A)  ─────────────╮ (pauses before sync)
 *   Writer B:                      update_post_meta(B) → sync → mint G1 → READY(G1)
 *   Writer A:                      (resumes) ──╯ sync → mint G2 (G2 > G1!) → READY(G2)
 *
 *   Final Post Meta = B (truly latest). Final projection = A's data, but
 *   is_ready() reports true, because G2 > G1 and the CAS predicate only
 *   ever compared generation *numbers* to each other — it had no way to
 *   ask "does A's content still match what Post Meta actually holds right
 *   now". A monotonic counter proves ordering among *mint events*; it
 *   cannot prove ordering among *write events*, because minting is not
 *   synchronized with writing. This is Blocker A, and no amount of extra
 *   retries, timestamps, or narrowing the CAS WHERE-clause fixes it — the
 *   defect is in what the number is allowed to mean, not in how carefully
 *   it is compared.
 *
 * THE REPLACEMENT FACT: WHAT MAKES A PROJECTION READY
 * ------------------------------------------------------------------------
 *   "A projection generation is READY iff, at some instant after it was
 *   fully staged, a cache-bypassing fresh read of Post Meta's actual
 *   stored content — the same row every writer contends on — produced the
 *   IDENTICAL canonical content fingerprint."
 *
 * This is strictly stronger than both of the two claims the redesign
 * brief required it to beat: stronger than "my sync finished last" (no
 * notion of "last" is used at all — completion order is irrelevant) and
 * stronger than "I own the highest generation number" (generation numbers
 * in this design carry NO ordering/authority meaning whatsoever — see
 * allocate_build_generation()). It is tied directly to actual Post Meta
 * content because it IS a comparison against actual Post Meta content,
 * performed at publish time, not an inference from call order.
 *
 * Why this closes Blocker A: Post Meta is a single MySQL row. MySQL
 * serializes writes to a single row — at any instant, that row holds
 * exactly one value, whichever write physically landed last. A fresh read
 * of that row at instant T therefore reflects the true state as of T,
 * regardless of which process's code merely *finished running* first or
 * last, and regardless of how long any writer paused between its own
 * Post-Meta write and its own sync call. Re-tracing the Blocker A scenario
 * under this protocol: B's sync fresh-reads Post Meta after B's own write
 * landed and finds B (B is genuinely current) → B activates. A's sync
 * (resumed later) fresh-reads Post Meta *at that later moment* and finds
 * B — not A — because A itself never re-wrote Post Meta after pausing; B's
 * write physically overwrote the row. A's own fingerprint (computed from
 * the map A was called with) cannot match B's fresh content, so A's
 * publish attempt is correctly rejected. Post Meta stays B, projection
 * stays B, is_ready() reports true only for B. No serialization of the two
 * writers' *sync calls* was required for this to hold — only that each
 * writer's *own* publish attempt re-reads real, current, cache-bypassed
 * Post Meta immediately before trusting itself.
 *
 * GENERATION NUMBERS NO LONGER CARRY AUTHORITY
 * ------------------------------------------------------------------------
 * In this design, "generation" is nothing more than a collision-free slot
 * label handed to each build attempt so its staged rows cannot collide
 * with any other build's staged rows (including the currently active one).
 * allocate_build_generation() reuses the same MySQL LAST_INSERT_ID(expr)
 * idiom proven correct under real concurrent connections in the previous
 * pass — but purely as an atomic counter, with no CAS, no "expected vs
 * ready" pairing, and no comparison between generation numbers anywhere in
 * this class. A build owning a numerically higher generation than another
 * build proves nothing about which one is fresher; only a successful
 * fingerprint-vs-fresh-Post-Meta match at publish time does. (A dedicated
 * regression test — "a higher generation number alone must not confer
 * authority" — asserts this directly; see the GR1 test suite.)
 *
 * DESTRUCTIVE-MUTATION SAFETY (Blocker B)
 * ------------------------------------------------------------------------
 * The previous design published by DELETE-then-INSERT directly on the
 * active rows: a DELETE that succeeded followed by an INSERT that failed
 * partway could leave status=READY over an incomplete row set. This
 * design's projection table is generation-tagged
 * (UNIQUE(event_id, generation, guest_phone)) and a build ALWAYS writes to
 * a fresh, never-before-used generation (stage_rows()) — it NEVER deletes
 * or overwrites any other generation's rows, including whichever is
 * currently active. A build that fails at any point before activation
 * (staging failure, fresh-read failure, fingerprint mismatch) simply never
 * touches the active generation at all; the active generation keeps
 * serving exactly what it was serving before, untouched. Trust is revoked
 * (or never granted) before any destructive step, and — because staging
 * a NEW generation is not destructive to any OTHER generation's rows —
 * there is no path in this design where "trust must be revoked before a
 * DELETE" even arises for the active data. The only DELETEs against
 * already-written rows are best-effort cleanup of generations that are
 * NOT active (discard_generation()/cleanup_old_generations()), which by
 * construction can never remove what a reader is currently being served.
 *
 * THE PUBLICATION SEQUENCE (attempt_publish(), shared by sync_event() and
 * rebuild_event())
 * ------------------------------------------------------------------------
 *   1. Compute the canonical fingerprint of the candidate content.
 *   2. Allocate a fresh, unused generation slot (no ordering meaning).
 *   3. Stage rows for that generation only (active generation untouched).
 *   4. Fresh-read Post Meta (cache-bypassing) and re-fingerprint it.
 *      If it does not match the candidate's fingerprint: the candidate is
 *      stale-on-arrival — a legitimate, expected outcome under
 *      concurrency, not an error. Discard the staged generation, return
 *      false. Nothing about the active generation changes.
 *   5. Only on a match: activate this generation (single UPDATE of the
 *      per-event state row: active_generation, active_fingerprint,
 *      status=ready, row_count, synced_at).
 *   6. Post-activation re-verify: fresh-read Post Meta once more and
 *      re-compare. If a newer write landed in the (necessarily narrow —
 *      one UPDATE statement wide) window between step 4's read and step
 *      5's activation, this catches it and self-corrects by forcing the
 *      state row back to stale (force_stale_best_effort()) rather than
 *      leaving a now-known-superseded generation reporting READY.
 *   7. Best-effort cleanup of superseded generations' rows (never the one
 *      just activated; failure here is not a correctness problem, only a
 *      cosmetic row-growth one).
 *
 * ════════════════════════════════════════════════════════════════════════
 * Concurrency Correctness Fix Pass (this pass) — PER-EVENT SERIALIZATION LOCK
 * ════════════════════════════════════════════════════════════════════════
 *
 * WHY THE FRESH-READ-ONLY DESIGN ABOVE WAS NOT SUFFICIENT ON ITS OWN
 * ------------------------------------------------------------------------
 * The fingerprint-verify protocol above closes Blocker A (a permanently
 * stuck incorrect READY). It does NOT, on its own, close two further,
 * genuinely distinct races, both CONFIRMED by tracing the real
 * implementation:
 *
 *   Blocker #1 (Active Generation Cleanup Race) — cleanup_old_generations()
 *   deletes "every generation except the one I myself just activated".
 *   Two publishers building IDENTICAL content concurrently can each
 *   legitimately activate (activate_generation() is an unconditional
 *   UPDATE, not a CAS — "last writer wins" on the state row). Whichever
 *   publisher's cleanup then runs LAST deletes the generation the OTHER
 *   publisher made active, because "not my number" is the wrong test once
 *   someone else has since become active too.
 *
 *   Blocker #2 (Reader Between Activation and Post-Verify) — step 5
 *   (activate) is a single UPDATE that commits immediately (MySQL
 *   autocommit). Nothing spans steps 4-6. A reader can observe the
 *   newly-activated state in the window between step 5 and step 6, before
 *   self-heal has run — meaning the previous docblock's claim that "no
 *   reader is ever served WRONG ROW DATA" was an overclaim: content can be
 *   transiently stale in that window, not merely the phone-set.
 *
 * THE FIX: A SINGLE PER-EVENT GET_LOCK, SPANNING THE POST META WRITE ITSELF
 * ------------------------------------------------------------------------
 * acquire_event_lock($event_id)/release_event_lock($event_id) wrap a
 * GET_LOCK(name, 5)/RELEASE_LOCK(name) pair, name =
 * 'pge_grp_evt_' . md5($event_id) — the same precedented pattern already
 * used elsewhere in this codebase (class-pge-invitation-service.php,
 * event-factory.php).
 *
 * The critical design decision, determined by explicit tracing of both
 * options: a lock taken ONLY inside sync_event() does NOT close Blocker
 * #2, because a concurrent writer B's own update_post_meta() call (inside
 * B's own save_map() invocation) remains entirely unprotected and can
 * still land during A's locked window. To close Blocker #2 to true
 * zero-width, the SAME lock must be acquired BEFORE update_post_meta()
 * inside pge_event_guests_save_map() itself, and held through the ENTIRE
 * nested sync_event() -> attempt_publish() sequence (verify, activate,
 * post-verify, cleanup) — see pge_event_guests_save_map() in
 * event-guests.php. This serializes every event's write+publish sequence
 * end to end: a concurrent writer B cannot write Post Meta at all until
 * publisher A's full critical section (including A's own post-verify) has
 * completed and released the lock. rebuild_event() is a separate call path
 * (invoked by the Application Service, not nested inside save_map()) and
 * therefore independently acquires the same lock around its own
 * read+build+verify+activate+cleanup sequence.
 *
 * sync_event() ALSO acquires this lock itself (not merely relying on
 * save_map() to already hold it), so it remains self-contained and safe to
 * call from any context. GET_LOCK is per-session and was verified
 * empirically against real MariaDB 10.11.14 to be safely re-entrant within
 * the same connection — a second GET_LOCK call on the same name from the
 * same session succeeds immediately (no self-block), and a matching number
 * of RELEASE_LOCK calls is required to fully release. This makes the
 * nested acquisition (outer in save_map(), inner in sync_event()) safe
 * with no self-deadlock risk.
 *
 * Why this closes both blockers: with the lock held across the Post Meta
 * write and the entire publish sequence, no second publisher for the SAME
 * event can ever be concurrently mid-attempt_publish() — Blocker #1's
 * "two publishers both activate" precondition can no longer occur.
 * Blocker #2's precondition (a writer changes Post Meta strictly between
 * this publisher's pre-activation fresh read and its post-activation
 * re-verify) also can no longer occur, because that writer is blocked on
 * the same lock until this publisher's entire sequence — including its
 * own post-verify — has completed. The post-activation re-verify (step 6)
 * is KEPT as defense-in-depth (cheap, harmless) but should now always
 * trivially pass under correct lock operation.
 *
 * cleanup_old_generations() is ADDITIONALLY hardened as defense-in-depth
 * beyond the lock: it excludes not only $keep_generation but also whatever
 * generation the state row reports active AT THE MOMENT OF THE DELETE
 * (via a scoped subquery on the state table), rather than only "not the
 * generation I myself activated". Under correct lock operation these are
 * always the same value; this guard only matters if that invariant is
 * ever violated by a future bug, in which case it leaves stale extra rows
 * behind instead of deleting live data — per the explicit preference that
 * temporary row growth is always safer than deleting an active generation.
 *
 * LOCK FAILURE SEMANTICS — SUPERSEDED, see "STRICT PRE-WRITE INVALIDATION
 * CONTRACT FIX PASS" below for the current, binding contract.
 * ------------------------------------------------------------------------
 * sync_event()/rebuild_event() themselves, if unable to acquire the lock,
 * force stale and return false — consistent with their EXISTING
 * false-on-failure contract; this is unchanged by any later pass. Release
 * always happens in a finally block; a lock is never left held across an
 * exception or an early return.
 *
 * ════════════════════════════════════════════════════════════════════════
 * Reader-Safety Publication Ordering Fix Pass (superseded pass) — CLOSING
 * THE READER-VS-WRITER RACE ACROSS THE AUTHORITATIVE WRITE ITSELF
 * ════════════════════════════════════════════════════════════════════════
 *
 * WHY THE WRITER LOCK ALONE WAS NOT SUFFICIENT
 * ------------------------------------------------------------------------
 * The per-event GET_LOCK above serializes writer-vs-writer contention. It
 * does NOT, and by design cannot, protect against a lock-FREE reader:
 * get_guests_by_phones()/is_ready()/state() never call acquire_event_lock()
 * — they are plain, unguarded SELECTs, and MUST remain so (a lock-free
 * steady-state collaborator read path is a hard requirement of this
 * phase). GET_LOCK is a purely advisory, named lock: it only contends with
 * another GET_LOCK() call on the identical name; it never blocks an
 * ordinary SELECT/UPDATE issued by a different connection. Confirmed by
 * direct tracing of an earlier pass's real, deployed code: inside
 * pge_event_guests_save_map(), update_post_meta($event_id,
 * '_pge_invited_guests', $clean) — the authoritative write, Post Meta
 * becoming V2 — executed immediately after lock acquisition, while
 * activate_generation() (the ONLY step that changes the state row's
 * status/active_generation) did not run until deep inside the nested
 * attempt_publish() call, AFTER fingerprint computation,
 * allocate_build_generation(), stage_rows(), and the pre-activation
 * fresh-read verify. Every one of those intervening steps is a real,
 * non-trivial span of PHP execution and SQL round-trips during which the
 * state row still reported the OLD generation as READY. A lock-free
 * reader querying at ANY point in that span observed
 * `Post Meta = V2 AND Projection = READY(V1)` — a genuine correctness
 * bug: the projection claiming a readiness for content that Post Meta,
 * the sole Source of Truth, had already superseded.
 *
 * THE (SUPERSEDED) FIX: INVALIDATE READY TRUST BEFORE THE WRITE, RACING A
 * BEST-EFFORT SELF-HEAL AGAINST A STILL-UNCONDITIONAL WRITE
 * ------------------------------------------------------------------------
 * The immediately preceding pass made pge_event_guests_save_map() call
 * force_stale_best_effort() unconditionally before update_post_meta(),
 * regardless of lock outcome, and retried it up to 3 times — but the
 * authoritative write STILL proceeded even if every retry failed, on the
 * reasoning that DEC-004 forbids the derived projection from ever gating
 * the Source of Truth. This closed the race in every case EXCEPT one
 * honestly-disclosed, narrow residual: if invalidation failed on every
 * pre-write attempt, a lock-free reader could briefly observe the old
 * generation as still READY in the gap between the write and a further
 * post-write force-stale retry. That residual is exactly what the next pass
 * (below) eliminates.
 *
 * ════════════════════════════════════════════════════════════════════════
 * Strict Pre-Write Invalidation Contract Fix Pass (superseded pass) —
 * ELIMINATING THE RESIDUAL WINDOW BY GATING THE WRITE ITSELF
 * ════════════════════════════════════════════════════════════════════════
 *
 * WHY "RACE THE INVALIDATION AGAINST THE WRITE" WAS STILL NOT ENOUGH
 * ------------------------------------------------------------------------
 * The previous pass's own new regression test (the scenario now covered by
 * TEST 3 below) proved, against the real fake-$wpdb harness, that a
 * lock-free reader COULD observe `Post Meta = V2 AND Projection =
 * READY(V1)` if invalidation failed on every pre-write attempt — a real,
 * if narrow and sustained-failure-only, forbidden window. "Self-heal a
 * few lines later" is not the same guarantee as "the window never opens."
 * This pass closes it completely rather than narrowing it further.
 *
 * THE FIX: GATE THE START OF THE WRITE ON A CONFIRMED SAFE ORDERING
 * ------------------------------------------------------------------------
 * pge_event_guests_save_map() now requires BOTH the per-event lock AND a
 * CONFIRMED pre-write invalidation before update_post_meta() is ever
 * called. If either cannot be established:
 *
 *   1. Lock acquisition fails -> ABORT before Post Meta is touched at all.
 *      Cannot prove no concurrent writer is mid-publish; writing unlocked
 *      risks a competing update_post_meta() landing mid-sequence.
 *   2. Lock acquired, but invalidation cannot be confirmed after 3
 *      immediate retries -> release the lock, ABORT before Post Meta is
 *      touched. Cannot prove the OLD generation's READY trust was
 *      actually revoked.
 *   3. Both confirmed -> proceed: write Post Meta, then
 *      sync/build/verify/activate, then release the lock.
 *
 * Step 3 above is superseded by the next pass (below), which inserts an
 * authoritative-write-confirmation step between "write Post Meta" and
 * "sync/build/verify/activate" — see that section for the corrected,
 * binding sequence and return contract. The gating logic in steps 1-2
 * (lock/invalidation preconditions) is UNCHANGED and remains binding.
 *
 * WHY THIS DOES NOT MAKE THE PROJECTION AUTHORITATIVE, AND DOES NOT VIOLATE
 * DEC-004
 * ------------------------------------------------------------------------
 * DEC-004 protects the CONTENT relationship: Post Meta's stored VALUE is
 * the sole Source of Truth, and the projection's content must never
 * override or be trusted over it. This pass does not touch that — the
 * projection's stored content still has zero authority; get_guests_by_
 * phones() is still purely derived and rebuildable, and a
 * force_stale_best_effort() failure never causes the projection's EXISTING
 * content to be trusted or served as current. What this pass gates is
 * different: WHEN an individual write attempt may *start* — a
 * write-sequencing safety precondition, not a content-authority
 * inversion. A caller whose write is aborted pre-write is in EXACTLY the
 * same state as a caller who never attempted the write at all: Post Meta
 * is untouched, unambiguously still whatever it was before this call. This
 * principle is also unchanged and remains binding through the next pass.
 *
 * ════════════════════════════════════════════════════════════════════════
 * Authoritative Write Confirmation & Lock Cleanup Fix Pass (this pass,
 * CURRENT AND BINDING) — VERIFYING THE WRITE ACTUALLY PERSISTED, AND
 * RELEASING THE LOCK ON EVERY PATH THROUGH THE WRITE ITSELF
 * ════════════════════════════════════════════════════════════════════════
 *
 * WHY "THE WRITE PROCEEDED, SO IT MUST HAVE SUCCEEDED" WAS STILL NOT ENOUGH
 * ------------------------------------------------------------------------
 * The previous pass correctly gated WHEN a write may start, but once
 * update_post_meta() was called, the function assumed the write had landed
 * and unconditionally returned the clean map. update_post_meta()'s own
 * boolean return is NOT a valid success signal — WordPress returns false
 * when the requested value is identical to what is already stored, which is
 * not a failure, so a naive `if (!update_post_meta(...))` check would
 * misclassify a genuine no-change save as an error. Separately, the
 * per-event lock's try/finally only wrapped the sync_event() call, not the
 * two update_post_meta() calls preceding it — a Throwable thrown during the
 * authoritative write itself (e.g. from a save_post/updated_post_meta hook)
 * could propagate out of save_map() without ever releasing the lock.
 *
 * THE FIX: A FRESH-READ POSTCONDITION, INSIDE THE SAME LOCKED try/finally AS
 * THE WRITE ITSELF
 * ------------------------------------------------------------------------
 * The authoritative Post Meta writes, the postcondition check, and
 * sync_event() now all run inside ONE try/finally that covers the
 * per-event lock acquired earlier. After update_post_meta() is called for
 * both `_pge_invited_guests` and `_pge_invited_phones`, a fresh,
 * cache-bypassing read (pge_event_guests_get_map_fresh() — the SAME
 * infrastructure this class already uses to verify freshness before
 * publishing, not a new mechanism) is compared against the exact map that
 * was requested. Only if they match is the write treated as CONFIRMED, and
 * only then does sync_event() run. If they do not match (or the fresh read
 * itself fails), the projection is force-stale'd (best-effort) and the
 * function returns null — the write is never reported as successful. A
 * Throwable anywhere in this section — from the writes, the postcondition
 * read, or sync_event() — is caught by the same catch block; if it occurred
 * BEFORE the write was confirmed, null is returned; if it occurred AFTER
 * confirmation (i.e. only sync_event() itself threw), the function still
 * returns the clean map, per "POST-WRITE VS PRE-WRITE FAILURE" below. The
 * finally block, covering the entire section, releases the lock exactly
 * once regardless of which branch runs or throws — no path through the
 * authoritative write can leave the lock held.
 *
 * CORRECTED RETURN CONTRACT
 * ------------------------------------------------------------------------
 * pge_event_guests_save_map() returns array|null. Success (the clean,
 * normalized map) means the fresh-read postcondition confirmed the
 * canonical store now matches what was requested — this includes the case
 * where the authoritative write was confirmed but the DERIVED projection
 * sync/build failed afterward (see "POST-WRITE VS PRE-WRITE FAILURE"
 * below). null now covers TWO distinct categories, which must never be
 * conflated:
 *
 *   - PRE-WRITE null: no authoritative write was ever started (lock
 *     unavailable, or pre-write invalidation could not be confirmed after
 *     retries). Post Meta is provably untouched by this call.
 *   - POST-WRITE-ATTEMPT null: update_post_meta() was called, but the
 *     requested authoritative state could not be CONFIRMED as the
 *     canonical result (the fresh-read postcondition did not match, or a
 *     Throwable occurred before confirmation). This does NOT mean "no
 *     storage mutation ever occurred" — a partial or unconfirmed mutation
 *     may have happened; what is guaranteed is only that this call never
 *     reports success for a write it could not verify, and the projection
 *     is never left/republished as READY for that unconfirmed state.
 *
 * This still mirrors the existing array|null precedent already used in
 * this same file (pge_event_guests_get_map_fresh()) — no new API shape was
 * invented, and no second source of truth was introduced: the postcondition
 * only CONFIRMS what Post Meta actually holds, it never overrides it.
 *
 * POST-WRITE VS PRE-WRITE FAILURE — NEVER CONFLATED
 * ------------------------------------------------------------------------
 * Three categories, not two:
 *
 *   (A) Pre-write abort (lock/invalidation) — covered above, unchanged.
 *   (B) Post-write-attempt write-confirmation failure (this pass, new) —
 *       the write was attempted but could not be confirmed persisted;
 *       returns null; the projection is force-stale'd, never republished
 *       as READY for the unconfirmed state.
 *   (C) Post-CONFIRMATION derived-layer failure — once the fresh-read
 *       postcondition has confirmed the write, this function's return
 *       contract for THIS call is fixed at "success": it returns the clean
 *       map even if the subsequent sync_event() call (building/activating
 *       the new generation) fails or throws. A caller must never be told
 *       "your write failed" for a write that Post Meta already,
 *       confirmed-authoritatively, reflects. This is a derived-layer
 *       degradation only (the projection is left/forced stale, self-healing
 *       via a later save_map() call or the Application Service's lazy
 *       rebuild_event() path) — never a Source-of-Truth failure.
 *
 * A caller must never conflate (B) with (C): (B) means "do not trust this
 * call happened"; (C) means "it happened, only the derived cache lagging."
 *
 * SCOPE NOTE — normal loaded plugin runtime
 * ------------------------------------------------------------------------
 * All of the above (this pass and the pass before it) applies when
 * PGE_Event_Guest_Read_Projection is loaded, which is the normal runtime for
 * this plugin (see pgevents-core.php's require_once ordering). If this
 * class does not exist, pge_event_guests_save_map() falls back to a plain,
 * unconditional, unlocked, unconfirmed write — this fallback branch existed
 * before this class did and is deliberately left unchanged by this phase;
 * it does NOT carry the same guarantees documented above, and no claim is
 * made that it does.
 *
 * RESIDUAL SCOPE (what this pass deliberately does not change)
 * ------------------------------------------------------------------------
 * The lock protects the write/publish path only. get_guests_by_phones()
 * (the steady-state collaborator read path) remains completely lock-free
 * by design — it only ever scopes its SELECT to whatever active_generation
 * the state row currently reports, and never blocks on or waits for a
 * build in progress. No HTTP/session concerns are introduced. Post Meta
 * remains the sole Source of Truth; the projection remains derived,
 * rebuildable, and non-authoritative; the lock is never used to change
 * that. No schema or migration change was needed for this pass. This pass
 * makes the projection's READY claim trustworthy at every instant a
 * lock-free reader might observe it — it does not, and must not, make the
 * projection itself authoritative.
 *
 * Freshness is tracked via a dedicated per-event state row, NOT via Post
 * Meta directly: reading state() must never touch wp_postmeta for the
 * event post, because WordPress's own meta cache (update_meta_cache())
 * eagerly fetches and unserializes *every* meta row for a post_id the
 * first time any single key on that post is requested — including
 * `_pge_invited_guests` — which would silently reintroduce a full-blob
 * load as a side effect. A dedicated SQL table has no such side channel.
 * The one place this class DOES intentionally read Post Meta is the
 * cache-bypassing fresh read inside attempt_publish() (steps 4 and 6) and
 * rebuild_event()'s own re-read — both on the write/rebuild path, never on
 * the steady-state collaborator read path (get_guests_by_phones()), so the
 * original GR1 performance target (bounded query count for steady-state
 * scoped reads) is preserved.
 */
class PGE_Event_Guest_Read_Projection
{
    const MAX_PHONE_BATCH = 200;
    const STATUS_READY = 'ready';
    const STATUS_STALE = 'stale';

    /**
     * Called from pge_event_guests_save_map() with the exact map it just
     * persisted to Post Meta. Does NOT trust that map as automatically
     * current by the time this runs — see attempt_publish(): the map is
     * only published if a fresh, cache-bypassing read of Post Meta still
     * matches its fingerprint at the moment of publish.
     *
     * @return bool true only if this call's content was verified fresh AND
     *   successfully activated. false covers every other outcome (invalid
     *   input, a DB write failure, or — under concurrency — a legitimate,
     *   EXPECTED loss because a newer authoritative write landed before
     *   this one could verify itself; the caller must not treat false as
     *   "Post Meta failed", it never means that).
     */
    public static function sync_event($event_id, array $authoritative_map): bool
    {
        $event_id = self::valid_event_id($event_id);
        if ($event_id === null) {
            return false;
        }

        if (!self::acquire_event_lock($event_id)) {
            self::force_stale_best_effort($event_id);
            return false;
        }

        try {
            return self::attempt_publish($event_id, $authoritative_map);
        } finally {
            self::release_event_lock($event_id);
        }
    }

    /**
     * Recovery/backfill path: re-reads Post Meta itself, fresh and
     * cache-bypassing (never trusts a caller-supplied map), and attempts to
     * publish it. Used for lazy rebuild-on-first-read and stale recovery.
     * Goes through the exact same attempt_publish() verification as
     * sync_event() — a rebuild that reads an old snapshot cannot publish
     * over newer data any more than a delayed sync_event() call can,
     * because "old snapshot" means its own fresh-read-immediately-before-
     * activating will simply see something newer and reject itself.
     */
    public static function rebuild_event($event_id): bool
    {
        $event_id = self::valid_event_id($event_id);
        if ($event_id === null) {
            return false;
        }
        if (!function_exists('pge_event_guests_get_map_fresh')) {
            return false;
        }

        if (!self::acquire_event_lock($event_id)) {
            self::force_stale_best_effort($event_id);
            return false;
        }

        try {
            $map = pge_event_guests_get_map_fresh($event_id);
            if (!is_array($map)) {
                return false;
            }

            return self::attempt_publish($event_id, $map);
        } finally {
            self::release_event_lock($event_id);
        }
    }

    /**
     * True only if the projection has an active generation that was
     * verified READY (see the class docblock for the precise fact this
     * certifies). False for "never built" (no state row), "stale"
     * (explicitly invalidated or self-corrected), and any row with
     * active_generation = 0 alike.
     */
    public static function is_ready($event_id): bool
    {
        return self::ready_generation($event_id) !== null;
    }

    /**
     * @return array{status:string,row_count:int,synced_at:?string,active_generation:int,active_fingerprint:string}|null
     *   null means "no state row exists for this event" (never built) —
     *   distinct from a row that exists with status=stale, and distinct
     *   from status=ready with row_count=0 (a real, confirmed zero-guest
     *   event). Never derives state from COUNT(*) on the projection table
     *   alone.
     */
    public static function state($event_id)
    {
        $event_id = self::valid_event_id($event_id);
        if ($event_id === null) {
            return null;
        }

        global $wpdb;
        $table = PGE_Event_Guest_Read_Projection_Schema::state_table_name();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, row_count, synced_at, active_generation, active_fingerprint FROM $table WHERE event_id = %d",
            $event_id
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        return [
            'status' => (string) ($row['status'] ?? ''),
            'row_count' => (int) ($row['row_count'] ?? 0),
            'synced_at' => isset($row['synced_at']) ? (string) $row['synced_at'] : null,
            'active_generation' => (int) ($row['active_generation'] ?? 0),
            'active_fingerprint' => (string) ($row['active_fingerprint'] ?? ''),
        ];
    }

    /**
     * Scoped SQL lookup: event_id + a bounded phone list, prepared
     * statement, canonical phone validation, event-scoped AND
     * generation-scoped WHERE clause (only the currently active
     * generation is ever queried — a build-in-progress generation is
     * never visible here). Returns a list (never a phone-keyed associative
     * array — a fully numeric phone string would be silently coerced to a
     * PHP int array key). No unauthorized phone can ever appear in the
     * result: only rows matching the exact phones passed in are ever
     * selected.
     *
     * If the projection is not currently ready (never built, stale, or
     * mid-self-heal), this returns an empty list rather than an error —
     * exactly like "no rows found" — so the caller's existing
     * completeness-check-then-rebuild retry logic (Application Service)
     * naturally takes over. This method never inspects or waits on a
     * build in progress; it fails closed to "not ready" instead.
     *
     * @return array<int,array{phone:string,name:string,note:string,code:string}>|WP_Error
     */
    public static function get_guests_by_phones($event_id, $phones)
    {
        $event_id = self::valid_event_id($event_id);
        if ($event_id === null) {
            return self::invalid_input();
        }
        if (!is_array($phones) || array_values($phones) !== $phones || count($phones) > self::MAX_PHONE_BATCH) {
            return self::invalid_input();
        }
        if (!function_exists('pge_event_guests_norm_phone')) {
            return self::invalid_input();
        }

        $normalized = [];
        $seen = [];
        foreach ($phones as $value) {
            if (!is_string($value)) {
                return self::invalid_input();
            }
            $phone = pge_event_guests_norm_phone($value);
            if ($phone === '' || strlen($phone) > 32) {
                return self::invalid_input();
            }
            $key = 'phone:' . $phone;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = $phone;
        }

        if ($normalized === []) {
            return [];
        }

        $generation = self::ready_generation($event_id);
        if ($generation === null) {
            return [];
        }

        global $wpdb;
        $table = PGE_Event_Guest_Read_Projection_Schema::projection_table_name();
        $placeholders = implode(', ', array_fill(0, count($normalized), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT guest_phone, guest_name, guest_note, guest_code FROM $table WHERE event_id = %d AND generation = %d AND guest_phone IN ($placeholders)",
            array_merge([$event_id, $generation], $normalized)
        ), ARRAY_A);

        if ($rows === null) {
            return self::database_error();
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['guest_phone'])) {
                return self::database_error();
            }
            $phone = (string) $row['guest_phone'];
            $key = 'phone:' . $phone;
            if (!isset($seen[$key])) {
                // A row for a phone we never asked for cannot happen with a
                // parameterized IN() over exactly $normalized — fail closed
                // rather than trust an unexplained result shape.
                return self::database_error();
            }
            $out[] = [
                'phone' => $phone,
                'name'  => (string) ($row['guest_name'] ?? ''),
                'note'  => (string) ($row['guest_note'] ?? ''),
                'code'  => (string) ($row['guest_code'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Utility, not wired to any hook in this phase: no existing code path in
     * this codebase cleans up any H1B/H1C table when an event post is
     * hard-deleted either — this class follows that same existing precedent.
     */
    public static function delete_event($event_id): bool
    {
        $event_id = self::valid_event_id($event_id);
        if ($event_id === null) {
            return false;
        }

        global $wpdb;
        $projection_table = PGE_Event_Guest_Read_Projection_Schema::projection_table_name();
        $state_table = PGE_Event_Guest_Read_Projection_Schema::state_table_name();

        $a = $wpdb->query($wpdb->prepare("DELETE FROM $projection_table WHERE event_id = %d", $event_id));
        $b = $wpdb->query($wpdb->prepare("DELETE FROM $state_table WHERE event_id = %d", $event_id));

        return $a !== false && $b !== false;
    }

    /**
     * Acquires the per-event serialization lock used to close Blocker #1
     * and Blocker #2 — see the class docblock, "PER-EVENT SERIALIZATION
     * LOCK". Safe to call re-entrantly from the same request/connection
     * (empirically verified against real MariaDB: a second GET_LOCK on the
     * same name from the same session succeeds immediately rather than
     * blocking itself); a matching number of release_event_lock() calls is
     * required to fully release. Returns false on invalid input, timeout,
     * or a genuine GET_LOCK error — callers must never proceed with an
     * unlocked write/publish sequence when this returns false.
     */
    public static function acquire_event_lock($event_id, int $timeout_seconds = 5): bool
    {
        $event_id = self::valid_event_id($event_id);
        if ($event_id === null) {
            return false;
        }

        global $wpdb;
        $lock_name = self::event_lock_name($event_id);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, $timeout_seconds));

        return (int) $got === 1;
    }

    /**
     * Best-effort release of the per-event lock. Safe to call even if the
     * lock was never held by this session (RELEASE_LOCK on an unheld name
     * is a documented no-op, not an error). Callers must always call this
     * in a finally block, never conditionally on success of the guarded
     * work, so a lock is never left held across an exception.
     */
    public static function release_event_lock($event_id): void
    {
        $event_id = self::valid_event_id($event_id);
        if ($event_id === null) {
            return;
        }

        global $wpdb;
        $lock_name = self::event_lock_name($event_id);
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    /**
     * Deterministic, length-bounded, per-event lock name. md5() keeps the
     * name well under MySQL's 64-character GET_LOCK name limit regardless
     * of event_id's digit count, matching the precedent already used
     * elsewhere in this codebase (class-pge-invitation-service.php).
     */
    private static function event_lock_name($event_id): string
    {
        return 'pge_grp_evt_' . md5((string) (int) $event_id);
    }

    // ──────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * The single shared publication sequence — see the class docblock,
     * "THE PUBLICATION SEQUENCE", for the full seven-step description.
     * Both sync_event() and rebuild_event() call this and only this to
     * ever write projection rows or flip the state row to ready.
     */
    private static function attempt_publish($event_id, array $candidate_map): bool
    {
        $fingerprint = self::fingerprint($candidate_map);
        $rows = self::normalize_rows($candidate_map);

        $generation = self::allocate_build_generation($event_id);
        if ($generation === null) {
            return false;
        }

        if (!self::stage_rows($event_id, $generation, $rows)) {
            self::discard_generation($event_id, $generation);
            return false;
        }

        if (!function_exists('pge_event_guests_get_map_fresh')) {
            self::discard_generation($event_id, $generation);
            return false;
        }

        $fresh_map = pge_event_guests_get_map_fresh($event_id);
        if (!is_array($fresh_map) || self::fingerprint($fresh_map) !== $fingerprint) {
            // Stale-on-arrival: a newer authoritative write already landed
            // in Post Meta since $candidate_map was handed to us. This is a
            // legitimate, EXPECTED outcome under concurrency, not an error.
            // The active generation (if any) is completely untouched.
            self::discard_generation($event_id, $generation);
            return false;
        }

        if (!self::activate_generation($event_id, $generation, $fingerprint, count($rows))) {
            self::discard_generation($event_id, $generation);
            return false;
        }

        // Post-activation re-verify: closes the narrow window between the
        // fresh-read above and the UPDATE that just ran. See the class
        // docblock's "HONEST RESIDUAL LIMIT" section for exactly what this
        // does and does not guarantee.
        $post_map = pge_event_guests_get_map_fresh($event_id);
        if (!is_array($post_map) || self::fingerprint($post_map) !== $fingerprint) {
            self::force_stale_best_effort($event_id);
            return false;
        }

        self::cleanup_old_generations($event_id, $generation);

        return true;
    }

    /**
     * Atomically allocates a fresh, never-before-used generation slot for
     * $event_id via the MySQL LAST_INSERT_ID(expr) idiom (proven correct
     * under genuinely concurrent connections in the previous fix pass; the
     * idiom itself is unchanged here, only its meaning is — see the class
     * docblock, "GENERATION NUMBERS NO LONGER CARRY AUTHORITY"). On the
     * UPDATE branch (a state row already exists), deliberately does NOT
     * touch `status` — a build merely STARTING must never affect whatever
     * generation is currently active and being served to readers; only a
     * successful activate_generation() call may change status/active_*.
     */
    private static function allocate_build_generation($event_id)
    {
        global $wpdb;
        $table = PGE_Event_Guest_Read_Projection_Schema::state_table_name();
        $now = current_time('mysql', true);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (event_id, active_generation, active_fingerprint, next_generation, status, row_count, synced_at, updated_at) VALUES (%d, 0, '', LAST_INSERT_ID(1), %s, 0, NULL, %s) ON DUPLICATE KEY UPDATE next_generation = LAST_INSERT_ID(next_generation + 1), updated_at = VALUES(updated_at)",
            $event_id,
            self::STATUS_STALE,
            $now
        ));

        if ($result === false) {
            return null;
        }

        $generation = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
        if ($generation <= 0) {
            return null;
        }

        return $generation;
    }

    /**
     * Writes rows for $generation only. Never touches any other
     * generation's rows (including whichever is currently active) — see
     * the class docblock, "DESTRUCTIVE-MUTATION SAFETY".
     */
    private static function stage_rows($event_id, int $generation, array $rows): bool
    {
        global $wpdb;
        $table = PGE_Event_Guest_Read_Projection_Schema::projection_table_name();
        $now = current_time('mysql', true);

        foreach ($rows as $row) {
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (event_id, generation, guest_phone, guest_name, guest_note, guest_code, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)",
                $event_id,
                $generation,
                $row['guest_phone'],
                $row['guest_name'],
                $row['guest_note'],
                $row['guest_code'],
                $now
            ));
            if ($inserted === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Unconditional activation UPDATE of the per-event state row. Not a
     * CAS: correctness comes from the fresh-read-vs-fingerprint checks that
     * bracket this call in attempt_publish() (before AND after), not from a
     * predicate on this statement — see the class docblock, "THE
     * PUBLICATION SEQUENCE" and "HONEST RESIDUAL LIMIT", for why a single
     * fully-atomic verify+activate statement is not attempted here (it
     * would require duplicating the PHP-side canonicalization/fingerprint
     * logic inside raw SQL, which is rejected as fragile and rejected as
     * an unjustified duplication of business logic).
     */
    private static function activate_generation($event_id, int $generation, string $fingerprint, int $row_count): bool
    {
        global $wpdb;
        $table = PGE_Event_Guest_Read_Projection_Schema::state_table_name();
        $now = current_time('mysql', true);

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET active_generation = %d, active_fingerprint = %s, status = %s, row_count = %d, synced_at = %s, updated_at = %s WHERE event_id = %d",
            $generation,
            $fingerprint,
            self::STATUS_READY,
            $row_count,
            $now,
            $now,
            $event_id
        ));

        return $updated !== false;
    }

    /**
     * Best-effort: discards one non-active build generation's staged rows.
     * Safe to call even if staging never inserted any rows (affects 0
     * rows). Never targets the active generation (callers only ever pass a
     * generation this same call allocated and never successfully
     * activated).
     */
    private static function discard_generation($event_id, int $generation): void
    {
        global $wpdb;
        $table = PGE_Event_Guest_Read_Projection_Schema::projection_table_name();

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE event_id = %d AND generation = %d",
            $event_id,
            $generation
        ));
    }

    /**
     * Best-effort cleanup of generations other than the one just
     * successfully activated. Purely cosmetic (bounds row growth); a
     * failure here has no correctness impact, because get_guests_by_phones()
     * always scopes to whatever generation the state row currently reports
     * active, never to a superseded one.
     *
     * Concurrency Correctness Fix Pass: under correct operation this call
     * only ever runs while this event's per-event lock is held (see
     * sync_event()/rebuild_event()), which by itself already makes it
     * impossible for another publisher to have concurrently activated a
     * different generation — see the class docblock, "PER-EVENT
     * SERIALIZATION LOCK". This statement is ADDITIONALLY hardened as
     * defense-in-depth beyond the lock: it excludes not only
     * $keep_generation but also whatever generation the state row reports
     * active AT THE MOMENT OF THIS DELETE (a scoped subquery on the state
     * table, not merely "not the generation I myself activated"). Under
     * correct lock operation these are always the same value, so this
     * guard changes nothing observable; it exists only so that a future
     * bug that violates the locking invariant leaves stale extra rows
     * behind instead of deleting an active generation's live data.
     */
    private static function cleanup_old_generations($event_id, int $keep_generation): void
    {
        global $wpdb;
        $table = PGE_Event_Guest_Read_Projection_Schema::projection_table_name();
        $state_table = PGE_Event_Guest_Read_Projection_Schema::state_table_name();

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE event_id = %d AND generation <> %d AND generation <> COALESCE((SELECT active_generation FROM $state_table WHERE event_id = %d), 0)",
            $event_id,
            $keep_generation,
            $event_id
        ));
    }

    /**
     * Best-effort, unconditional: forces status to STALE and nothing else.
     * Safe to call even when no row exists yet (affects 0 rows, no error)
     * and safe under any concurrent generation churn, because it never
     * claims or grants readiness — it only ever removes trust from
     * whatever is currently there.
     *
     * Made PUBLIC in the Concurrency Correctness Fix Pass so
     * pge_event_guests_save_map() (event-guests.php) can force the
     * projection stale directly when per-event lock acquisition fails,
     * without attempting an unlocked/unsafe sync.
     *
     * Reader-Safety Publication Ordering Fix Pass — signature widened from
     * void to bool (a safe, non-breaking change: every existing call site
     * discarded the return value already) so pge_event_guests_save_map()
     * can now observe and log a genuine pre-write invalidation failure
     * (see the class docblock, "READER-SAFETY PUBLICATION ORDERING FIX
     * PASS") instead of silently assuming it always succeeds. "0 rows
     * affected because no state row exists yet" is still success (true) —
     * only a genuine SQL-level error (`$wpdb->query()` returning false) is
     * failure.
     *
     * @return bool true unless the underlying UPDATE itself reports a
     *   genuine query error; never throws.
     */
    public static function force_stale_best_effort($event_id): bool
    {
        global $wpdb;
        $table = PGE_Event_Guest_Read_Projection_Schema::state_table_name();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status = %s WHERE event_id = %d",
            self::STATUS_STALE,
            $event_id
        ));

        return $result !== false;
    }

    /**
     * The generation currently reported active for $event_id, but ONLY if
     * status is READY and active_generation > 0 — this is the private
     * counterpart to is_ready() and the only source get_guests_by_phones()
     * ever scopes its SELECT to.
     *
     * @return int|null
     */
    private static function ready_generation($event_id)
    {
        $state = self::state($event_id);
        if (!is_array($state)) {
            return null;
        }
        if ($state['status'] !== self::STATUS_READY) {
            return null;
        }
        if ($state['active_generation'] <= 0) {
            return null;
        }
        return $state['active_generation'];
    }

    /**
     * Canonical content fingerprint: phone-keyed rows (fixed field order
     * phone/name/note/code), sorted by phone as STRINGS (SORT_STRING —
     * avoids PHP's silent all-digit-string-to-int array-key coercion for
     * phone numbers), re-indexed to a plain ordered list, then encoded via
     * wp_json_encode()/json_encode() for deterministic, stable encoding,
     * then SHA-256'd. Both sides of every comparison in this class
     * (candidate map and freshly-read Post Meta map) go through this exact
     * same function, so identical guest content — regardless of which of
     * the two callers produced it — always yields an identical fingerprint.
     */
    private static function fingerprint($map): string
    {
        $rows = self::normalize_rows(is_array($map) ? $map : []);

        $by_phone = [];
        foreach ($rows as $row) {
            $by_phone[$row['guest_phone']] = [
                'phone' => $row['guest_phone'],
                'name'  => $row['guest_name'],
                'note'  => $row['guest_note'],
                'code'  => $row['guest_code'],
            ];
        }
        ksort($by_phone, SORT_STRING);
        $ordered = array_values($by_phone);

        $encoded = function_exists('wp_json_encode') ? wp_json_encode($ordered) : json_encode($ordered);
        if (!is_string($encoded)) {
            // Should not be reachable for well-formed rows (plain strings
            // only); kept only as a defensive, still-deterministic fallback.
            $encoded = serialize($ordered);
        }

        return hash('sha256', $encoded);
    }

    private static function normalize_rows(array $authoritative_map): array
    {
        $rows = [];
        foreach ($authoritative_map as $guest) {
            if (!is_array($guest)) {
                continue;
            }
            $phone = function_exists('pge_event_guests_norm_phone')
                ? pge_event_guests_norm_phone($guest['phone'] ?? '')
                : '';
            if (!is_string($phone) || $phone === '' || strlen($phone) > 32) {
                continue;
            }
            $rows[] = [
                'guest_phone' => $phone,
                'guest_name'  => (string) ($guest['name'] ?? ''),
                'guest_note'  => (string) ($guest['note'] ?? ''),
                'guest_code'  => (string) ($guest['code'] ?? ''),
            ];
        }
        return $rows;
    }

    private static function valid_event_id($event_id)
    {
        if (is_int($event_id) && $event_id > 0) {
            return $event_id;
        }
        if (is_string($event_id) && preg_match('/\A[1-9][0-9]*\z/', $event_id) === 1) {
            return (int) $event_id;
        }
        return null;
    }

    private static function invalid_input()
    {
        return new WP_Error('invalid_input', 'Invalid guest read projection input.');
    }

    private static function database_error()
    {
        return new WP_Error('database_error', 'Guest read projection database error.');
    }
}
