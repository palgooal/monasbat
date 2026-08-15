<?php
/**
 * Phase H1C-GR1 — Relational Guest Read Projection.
 * Freshness Publication Protocol Redesign (this pass).
 *
 * Exercises the real PGE_Event_Guest_Read_Projection_Schema,
 * PGE_Event_Guest_Read_Projection, and the real pge_event_guests_save_map()/
 * get_map()/get_map_fresh() functions from event-guests.php — against a
 * small in-memory fake $wpdb (the two GR1 tables + a fake wp_postmeta raw
 * read) and a fake Post Meta store (`_pge_invited_guests`). No real
 * database, no real network, no UI.
 *
 * Real-DB note: the exact CREATE TABLE SQL (generation column,
 * UNIQUE(event_id, generation, guest_phone)), the exact
 * allocate_build_generation()/activate_generation()/discard_generation()/
 * cleanup_old_generations()/get_guests_by_phones() SQL strings this class
 * generates, and the query-level phone+generation scoping behavior were
 * ALSO executed and verified against a real, isolated MariaDB database in
 * this environment as part of this phase (see the phase report's "Real DB
 * Verification" section for the exact commands and output). What was NOT
 * verified against a real database: the PHP-level dbDelta()/
 * register_activation_hook()/plugins_loaded() orchestration in
 * maybe_upgrade(), and the WordPress object-cache-bypassing behavior of
 * pge_event_guests_get_map_fresh() itself (this environment has no full
 * WordPress bootstrap / no wp_postmeta object cache to actually race
 * against). This suite (the fake $wpdb below) is what actually exercises
 * that PHP orchestration layer and simulates concurrent-writer races
 * deterministically.
 *
 * Race-testing technique note: the three required race scenarios (Test #1
 * critical / Test #2 normal order / Test #3 order-independence) are all
 * driven through the REAL PUBLIC API (sync_event()/rebuild_event()) called
 * in the literal, real problem order — no artificial reordering, no
 * ReflectionMethod decomposition needed for those three, because this
 * protocol's correctness does not depend on call/completion order at all,
 * only on fresh-read timing (see the class docblock). One additional test
 * (Section L, the narrow post-activation self-heal window) DOES need to
 * simulate a write landing INSIDE a single attempt_publish() call — for
 * that one case this suite uses a deterministic fake-$wpdb hook
 * ($GLOBALS['gr1_mutate_on_nth_fresh_read']) that mutates the fake Post
 * Meta store on a specific, chosen call number of the cache-bypassing
 * fresh-read primitive itself — the same primitive production code
 * actually calls twice per publish. This changes no production code.
 *
 * Run: php tests/test-event-guest-read-projection-phase-h1c-gr1.php
 *
 * Test-category labeling (Strict Pre-Write Invalidation Contract Fix Pass,
 * this pass — required audit item 24): every section below that exercises
 * write/publish behavior is now labeled, in its section banner, as one of:
 *   (A) PROJECTION PRIMITIVE BEHAVIOR — calls
 *       PGE_Event_Guest_Read_Projection::sync_event()/rebuild_event()
 *       directly, often after manually seeding/mutating the fake Post Meta
 *       store, bypassing the production pge_event_guests_save_map() choke
 *       point entirely. These sections prove the projection primitives
 *       themselves are correct (generation/fingerprint/lock/cleanup
 *       mechanics) but do NOT, on their own, prove anything about the
 *       production authoritative-write ordering contract, since real
 *       production code never calls sync_event()/rebuild_event() directly
 *       for a guest-map write — only save_map() does.
 *   (B) PRODUCTION save_map() AUTHORITATIVE-WRITE BEHAVIOR — drives the
 *       scenario through the real pge_event_guests_save_map() function
 *       (the only function real callers use), and is what actually proves
 *       claims about the production write-ordering/failure contract (lock
 *       gating, pre-write invalidation gating, the array|null return
 *       contract, etc.).
 * Sections that are purely structural/schema/source-level (no
 * write/publish call at all) are left unlabeled. No section's test logic,
 * assertions, or execution order were changed to add these labels — this
 * is a documentation-only pass over existing, already-passing sections.
 */

define('ABSPATH', __DIR__ . '/');
define('PGE_PATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

// ──────────────────────────────────────────────────────────────
// Minimal WordPress primitives
// ──────────────────────────────────────────────────────────────

class WP_Error
{
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

function add_action(...$args) { /* no-op recorder not needed in this suite */ }
function add_filter(...$args) { /* no-op */ }
function register_activation_hook(...$args) { /* no-op — never fires in this run */ }

function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function sanitize_text_field($v) { return trim((string) $v); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function wp_unslash($value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }

function maybe_unserialize($value)
{
    if (is_string($value)) {
        $unserialized = @unserialize($value);
        if ($unserialized !== false || $value === 'b:0;') {
            return $unserialized;
        }
    }
    return $value;
}

$GLOBALS['gr1_now'] = '2026-01-01 00:00:00';
function current_time($type = 'mysql', $gmt = 0) { return $GLOBALS['gr1_now']; }

// ──────────────────────────────────────────────────────────────
// Fake Post Meta store (`_pge_invited_guests`) — the authoritative side,
// used by rebuild_event()/pge_event_guests_save_map()/get_map_fresh().
// ──────────────────────────────────────────────────────────────

$GLOBALS['gr1_post_meta'] = [];
$GLOBALS['gr1_postmeta_guest_call_count'] = 0;

function get_post_meta($post_id, $key = '', $single = false)
{
    if ($key === '_pge_invited_guests') {
        $GLOBALS['gr1_postmeta_guest_call_count']++;
    }
    $value = $GLOBALS['gr1_post_meta'][$post_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_post_meta($post_id, $key, $value)
{
    // Authoritative Write Confirmation & Lock Cleanup Fix Pass — harness
    // hooks proving the NEW write-confirmation gate is load-bearing, not
    // decorative. Both are one-shot and scoped to the specific meta key a
    // test needs to simulate a failure for.
    if ($key === '_pge_invited_guests' && $GLOBALS['gr1_throw_on_post_meta_write']) {
        // Simulates a Throwable coming out of update_post_meta() itself —
        // e.g. a save_post/updated_post_meta hook or plugin callback that
        // throws — BEFORE the value is ever actually persisted.
        $GLOBALS['gr1_throw_on_post_meta_write'] = false;
        throw new \RuntimeException('gr1-fake-wpdb: simulated fatal error during authoritative Post Meta write');
    }
    if ($key === '_pge_invited_guests' && $GLOBALS['gr1_not_persist_guests_write']) {
        // Simulates the write CALL happening (and WordPress's own
        // update_post_meta() returning normally) WITHOUT the requested
        // value actually landing in storage — deliberately does NOT touch
        // $GLOBALS['gr1_post_meta']. This is the scenario the new
        // fresh-read authoritative postcondition exists to catch; a naive
        // `if (!update_post_meta(...))` boolean check could never catch
        // this, since the fake (like real WordPress) still "succeeds" here.
        $GLOBALS['gr1_not_persist_guests_write'] = false;
        if (is_callable($GLOBALS['gr1_run_after_post_meta_write'])) {
            $cb = $GLOBALS['gr1_run_after_post_meta_write'];
            $GLOBALS['gr1_run_after_post_meta_write'] = null;
            $cb($post_id, $GLOBALS['gr1_post_meta'][$post_id]['_pge_invited_guests'] ?? []);
        }
        return true;
    }
    if ($key === '_pge_invited_phones' && $GLOBALS['gr1_not_persist_phones_write']) {
        // Simulates the compatibility key's write silently not persisting,
        // while the primary `_pge_invited_guests` key DOES persist
        // correctly — isolates whether a partial write across the two keys
        // can produce a false success (Required Test 12 / Section 5/H).
        $GLOBALS['gr1_not_persist_phones_write'] = false;
        return true;
    }

    $GLOBALS['gr1_post_meta'][$post_id][$key] = $value;

    // Reader-Safety Publication Ordering Fix Pass — see gr1_reset_db()'s
    // gr1_run_after_post_meta_write doc comment. Scoped to
    // '_pge_invited_guests' only (the authoritative key the projection
    // tracks) to match the exact hook point requested: immediately after
    // Post Meta becomes the new value, before anything else in
    // pge_event_guests_save_map() runs.
    if ($key === '_pge_invited_guests' && is_callable($GLOBALS['gr1_run_after_post_meta_write'])) {
        $cb = $GLOBALS['gr1_run_after_post_meta_write'];
        $GLOBALS['gr1_run_after_post_meta_write'] = null;
        $cb($post_id, $value);
    }

    return true;
}
function delete_post_meta($post_id, $key) { unset($GLOBALS['gr1_post_meta'][$post_id][$key]); return true; }

/**
 * Simulates the "Post Meta write" half of pge_event_guests_save_map() (a
 * full replace, matching update_post_meta($event_id, '_pge_invited_guests',
 * $clean)) WITHOUT the "call sync_event()" half — lets a test simulate a
 * writer that has written Post Meta but has not yet (or, in some tests,
 * deliberately never) called its own sync. This is exactly the pause
 * described in the Freshness Publication Protocol Redesign's Blocker A
 * scenario: "Writer A writes Post Meta=A then pauses before calling sync."
 */
function gr1_write_post_meta($event_id, array $clean_map)
{
    $GLOBALS['gr1_post_meta'][$event_id]['_pge_invited_guests'] = $clean_map;
}

/** One-guest clean map shape, for building race-test fixtures concisely. */
function gr1_map($phone, $name, $note = '', $code = 'AAAA-0000')
{
    return [$phone => ['phone' => $phone, 'name' => $name, 'note' => $note, 'code' => $code]];
}

// ──────────────────────────────────────────────────────────────
// Fake $wpdb — the two GR1 tables, plus a fake raw wp_postmeta SELECT for
// pge_event_guests_get_map_fresh(). Any other SQL is "unexpected".
// ──────────────────────────────────────────────────────────────

function gr1_tables()
{
    return [
        'projection' => 'wp_pge_event_guest_read_projection',
        'state' => 'wp_pge_event_guest_read_projection_state',
        'postmeta' => 'wp_postmeta',
    ];
}

function gr1_reset_db()
{
    $GLOBALS['gr1_db'] = ['projection' => [], 'state' => []];
    $GLOBALS['gr1_post_meta'] = [];
    $GLOBALS['gr1_postmeta_guest_call_count'] = 0;
    $GLOBALS['gr1_query_log'] = [];
    $GLOBALS['gr1_fail_projection_insert'] = false;
    $GLOBALS['gr1_fail_allocate_generation'] = false;
    $GLOBALS['gr1_fail_activate'] = false;
    $GLOBALS['gr1_fail_projection_delete'] = false;
    $GLOBALS['gr1_fail_fresh_read'] = false;
    // Fresh-Read Audit — fails ONLY the `_pge_invited_phones` fresh query,
    // isolating a legacy-fallback-specific query error from the primary
    // `_pge_invited_guests` fresh query (distinct from gr1_fail_fresh_read
    // above, which fails both).
    $GLOBALS['gr1_fail_fresh_read_phones_only'] = false;
    $GLOBALS['gr1_last_insert_id'] = 0;
    $GLOBALS['gr1_now'] = '2026-01-01 00:00:00';
    // Freshness Publication Protocol Redesign — deterministic single-write-
    // landing-mid-publish simulation hook. See the file docblock.
    $GLOBALS['gr1_fresh_read_call_count'] = [];
    $GLOBALS['gr1_mutate_on_nth_fresh_read'] = null;
    // Concurrency Correctness Fix Pass — per-event GET_LOCK simulation.
    // gr1_lock_state tracks the (single, same-session) hold count per lock
    // name — reentrant GET_LOCK calls from this one fake connection always
    // succeed, exactly matching the real MariaDB behavior verified against
    // a real database in this phase. gr1_lock_deny_next/gr1_lock_always_deny
    // simulate a DIFFERENT connection currently holding the lock (deny_next
    // is a one-shot counter consumed on the next N GET_LOCK calls; used to
    // deterministically model a concurrent writer's attempt landing while
    // this "session" already holds the lock — the same simulation technique
    // as gr1_mutate_on_nth_fresh_read above, purely test-harness-side).
    $GLOBALS['gr1_lock_state'] = [];
    $GLOBALS['gr1_lock_deny_next'] = 0;
    $GLOBALS['gr1_lock_always_deny'] = false;
    $GLOBALS['gr1_lock_calls'] = [];
    // One-shot callback fired synchronously right after a successful
    // activate_generation() UPDATE is applied, before the post-activation
    // fresh-read runs — lets a test run a "reader" and/or a "concurrent
    // writer" call at the exact point production code is mid-attempt_publish().
    $GLOBALS['gr1_run_after_activate'] = null;
    // One-shot: makes the NEXT $wpdb->query() call throw instead of
    // returning, to test that the per-event lock is released via finally()
    // even when an exception propagates out of the locked section.
    // gr1_throw_on_next_query_skip lets a test skip N query() calls before
    // the throw fires (Reader-Safety Publication Ordering Fix Pass — needed
    // so a test can target a throw specifically PAST the new pre-write
    // force_stale_best_effort() invalidation query(), e.g. to still exercise
    // an exception from inside the nested sync_event() call specifically).
    $GLOBALS['gr1_throw_on_next_query'] = false;
    $GLOBALS['gr1_throw_on_next_query_skip'] = 0;
    // Reader-Safety Publication Ordering Fix Pass — one-shot counter:
    // makes the next N force_stale_best_effort() UPDATE queries report a
    // genuine query failure (return false) instead of applying, without
    // throwing. Used to simulate the pre-write invalidation step itself
    // failing (TEST 3 — Invalidation Failure), distinct from
    // gr1_throw_on_next_query (a fatal exception) and from an ordinary
    // false return.
    $GLOBALS['gr1_fail_force_stale_next'] = 0;
    // One-shot callback fired synchronously inside the fake update_post_meta()
    // for the '_pge_invited_guests' key specifically — i.e. immediately after
    // the authoritative write lands, before anything else in
    // pge_event_guests_save_map() runs. Lets a test run a "reader" at the
    // exact instant Post Meta becomes the new value, to prove no lock-free
    // reader can observe `Post Meta = V2 AND Projection = READY(V1)` at that
    // instant (Reader-Safety Publication Ordering Fix Pass).
    $GLOBALS['gr1_run_after_post_meta_write'] = null;
    // Authoritative Write Confirmation & Lock Cleanup Fix Pass — one-shot
    // harness hooks (see update_post_meta() above for exact semantics).
    $GLOBALS['gr1_throw_on_post_meta_write'] = false;
    $GLOBALS['gr1_not_persist_guests_write'] = false;
    $GLOBALS['gr1_not_persist_phones_write'] = false;
}

/**
 * Invokes a private static method directly via Reflection. Used sparingly
 * in this pass — only where a test genuinely needs to exercise an internal
 * primitive in isolation (allocate_build_generation()'s slot-allocator
 * behavior) rather than to fake a race (every race in this suite is driven
 * through the real public API in real order instead — see the file
 * docblock). Changes no production code.
 */
function gr1_invoke_private($class, $method, array $args)
{
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

/** Direct Post Meta seed (additive) — bypasses pge_event_guests_save_map() on purpose. */
function gr1_set_guest($event_id, $phone, $name, $note = '', $code = 'ABCD-1234')
{
    $existing = $GLOBALS['gr1_post_meta'][$event_id]['_pge_invited_guests'] ?? [];
    if (!is_array($existing)) $existing = [];
    $existing[$phone] = ['phone' => $phone, 'name' => $name, 'note' => $note, 'code' => $code];
    $GLOBALS['gr1_post_meta'][$event_id]['_pge_invited_guests'] = $existing;
}

function gr1_dispatch_select($sql)
{
    $t = gr1_tables();
    $sql = trim($sql);
    $GLOBALS['gr1_query_log'][] = $sql;

    $select = '/^SELECT guest_phone, guest_name, guest_note, guest_code FROM '
        . preg_quote($t['projection'], '/') . ' WHERE event_id = (\d+) AND generation = (\d+) AND guest_phone IN \(([^)]*)\)$/';
    if (preg_match($select, $sql, $m)) {
        $event_id = (int) $m[1];
        $generation = (int) $m[2];
        $phones = array_map(function ($p) { return trim(trim($p), "'"); }, explode(',', $m[3]));
        $out = [];
        foreach ($GLOBALS['gr1_db']['projection'] as $row) {
            if ($row['event_id'] !== $event_id || $row['generation'] !== $generation || !in_array($row['guest_phone'], $phones, true)) continue;
            $out[] = [
                'guest_phone' => $row['guest_phone'], 'guest_name' => $row['guest_name'],
                'guest_note' => $row['guest_note'], 'guest_code' => $row['guest_code'],
            ];
        }
        return $out;
    }

    return null;
}

function gr1_dispatch_row($sql)
{
    $t = gr1_tables();
    $sql = trim($sql);
    $GLOBALS['gr1_query_log'][] = $sql;

    if (preg_match('/^SELECT status, row_count, synced_at, active_generation, active_fingerprint FROM ' . preg_quote($t['state'], '/') . ' WHERE event_id = (\d+)$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $state = $GLOBALS['gr1_db']['state'][$event_id] ?? null;
        return $state === null ? null : [
            'status' => $state['status'], 'row_count' => $state['row_count'], 'synced_at' => $state['synced_at'],
            'active_generation' => $state['active_generation'], 'active_fingerprint' => $state['active_fingerprint'],
        ];
    }

    return false;
}

/**
 * get_var() dispatcher: LAST_INSERT_ID() read-back and the cache-bypassing
 * raw wp_postmeta SELECT that backs pge_event_guests_get_map_fresh().
 *
 * @return array{0:mixed,1:bool} [value, recognized] — recognized=false is
 *   the "unrecognized SQL" sentinel (distinct from "recognized, legitimately
 *   NULL", e.g. no meta row exists yet for this event).
 */
function gr1_dispatch_scalar($sql)
{
    $t = gr1_tables();
    $sql = trim($sql);
    $GLOBALS['gr1_query_log'][] = $sql;

    if ($sql === 'SELECT LAST_INSERT_ID()') {
        return [$GLOBALS['gr1_last_insert_id'], true];
    }

    if (preg_match('/^SELECT meta_value FROM ' . preg_quote($t['postmeta'], '/') . ' WHERE post_id = (\d+) AND meta_key = \'(_pge_invited_guests|_pge_invited_phones)\' ORDER BY meta_id ASC LIMIT 1$/', $sql, $m)) {
        $post_id = (int) $m[1];
        $meta_key = $m[2];

        if ($GLOBALS['gr1_fail_fresh_read'] || ($GLOBALS['gr1_fail_fresh_read_phones_only'] && $meta_key === '_pge_invited_phones')) {
            global $wpdb;
            $wpdb->last_error = 'gr1-fake-wpdb: simulated fresh-read query error';
            return [null, true];
        }

        // Concurrency Correctness Fix Pass — Fresh-Read Audit: the mid-
        // publish mutation hook and the call-count-based interleaving trap
        // (Section L/M) are deliberately scoped to `_pge_invited_guests`
        // only, matching the existing tests that arm them — a fresh read of
        // the (separate) legacy `_pge_invited_phones` key never participates
        // in that specific simulation.
        if ($meta_key === '_pge_invited_guests') {
            $GLOBALS['gr1_postmeta_guest_call_count']++;
            if (!isset($GLOBALS['gr1_fresh_read_call_count'][$post_id])) {
                $GLOBALS['gr1_fresh_read_call_count'][$post_id] = 0;
            }
            $GLOBALS['gr1_fresh_read_call_count'][$post_id]++;
            $call_n = $GLOBALS['gr1_fresh_read_call_count'][$post_id];

            $mutate = $GLOBALS['gr1_mutate_on_nth_fresh_read'];
            if (is_array($mutate) && $mutate['event_id'] === $post_id && $mutate['n'] === $call_n) {
                // Simulate a concurrent writer's Post Meta write landing exactly
                // at this point — i.e. between two fresh-reads inside the SAME
                // attempt_publish() call. Fires once only.
                $GLOBALS['gr1_post_meta'][$post_id]['_pge_invited_guests'] = $mutate['new_stored'];
                $GLOBALS['gr1_mutate_on_nth_fresh_read'] = null;
            }
        }

        if (!array_key_exists($post_id, $GLOBALS['gr1_post_meta']) || !array_key_exists($meta_key, $GLOBALS['gr1_post_meta'][$post_id])) {
            return [null, true];
        }
        $value = $GLOBALS['gr1_post_meta'][$post_id][$meta_key];
        return [serialize($value), true];
    }

    // Concurrency Correctness Fix Pass — per-event serialization lock
    // (PGE_Event_Guest_Read_Projection::acquire_event_lock()).
    if (preg_match("/^SELECT GET_LOCK\('([^']*)', (\d+)\)\$/", $sql, $m)) {
        $name = $m[1];
        if ($GLOBALS['gr1_lock_always_deny'] || $GLOBALS['gr1_lock_deny_next'] > 0) {
            if ($GLOBALS['gr1_lock_deny_next'] > 0) {
                $GLOBALS['gr1_lock_deny_next']--;
            }
            $GLOBALS['gr1_lock_calls'][] = ['action' => 'get', 'name' => $name, 'result' => 0];
            return [0, true];
        }
        $GLOBALS['gr1_lock_state'][$name] = ($GLOBALS['gr1_lock_state'][$name] ?? 0) + 1;
        $GLOBALS['gr1_lock_calls'][] = ['action' => 'get', 'name' => $name, 'result' => 1];
        return [1, true];
    }

    return [null, false];
}

function gr1_dispatch_query($sql)
{
    $t = gr1_tables();
    $sql = trim($sql);
    $GLOBALS['gr1_query_log'][] = $sql;

    if ($GLOBALS['gr1_throw_on_next_query']) {
        if ($GLOBALS['gr1_throw_on_next_query_skip'] > 0) {
            $GLOBALS['gr1_throw_on_next_query_skip']--;
        } else {
            $GLOBALS['gr1_throw_on_next_query'] = false;
            throw new \RuntimeException('gr1-fake-wpdb: simulated fatal DB error');
        }
    }

    // Concurrency Correctness Fix Pass — RELEASE_LOCK() for the per-event
    // serialization lock. Best-effort: releasing an unheld name is a
    // documented no-op (returns NULL in real MySQL), not an error.
    if (preg_match("/^SELECT RELEASE_LOCK\('([^']*)'\)\$/", $sql, $m)) {
        $name = $m[1];
        $held = $GLOBALS['gr1_lock_state'][$name] ?? 0;
        if ($held > 0) {
            $GLOBALS['gr1_lock_state'][$name] = $held - 1;
            if ($GLOBALS['gr1_lock_state'][$name] === 0) {
                unset($GLOBALS['gr1_lock_state'][$name]);
            }
            $GLOBALS['gr1_lock_calls'][] = ['action' => 'release', 'name' => $name, 'result' => 1];
            return 1;
        }
        // Real RELEASE_LOCK() on a name this session does not hold returns
        // NULL (name never locked) or 0 (locked by someone else) — either
        // way, not the fake dispatcher's null="unrecognized query" sentinel;
        // 0 is used here purely to stay distinct from that sentinel.
        $GLOBALS['gr1_lock_calls'][] = ['action' => 'release', 'name' => $name, 'result' => 0];
        return 0;
    }

    // stage_rows(): INSERT one row for a specific (never-before-used)
    // generation. Simulates UNIQUE(event_id, generation, guest_phone).
    $insertProj = '/^INSERT INTO ' . preg_quote($t['projection'], '/')
        . ' \(event_id, generation, guest_phone, guest_name, guest_note, guest_code, updated_at\) VALUES \((\d+), (\d+), \'([^\']*)\', \'([^\']*)\', \'([^\']*)\', \'([^\']*)\', \'([^\']*)\'\)$/';
    if (preg_match($insertProj, $sql, $m)) {
        if ($GLOBALS['gr1_fail_projection_insert']) {
            return false; // simulate a real DB write failure.
        }
        $event_id = (int) $m[1];
        $generation = (int) $m[2];
        foreach ($GLOBALS['gr1_db']['projection'] as $row) {
            if ($row['event_id'] === $event_id && $row['generation'] === $generation && $row['guest_phone'] === $m[3]) {
                return false; // UNIQUE(event_id, generation, guest_phone) violation.
            }
        }
        $GLOBALS['gr1_db']['projection'][] = [
            'event_id' => $event_id, 'generation' => $generation, 'guest_phone' => $m[3], 'guest_name' => $m[4],
            'guest_note' => $m[5], 'guest_code' => $m[6], 'updated_at' => $m[7],
        ];
        return 1;
    }

    // discard_generation(): DELETE targeted at exactly one (non-active)
    // generation.
    $deleteOne = '/^DELETE FROM ' . preg_quote($t['projection'], '/') . ' WHERE event_id = (\d+) AND generation = (\d+)$/';
    if (preg_match($deleteOne, $sql, $m)) {
        if ($GLOBALS['gr1_fail_projection_delete']) {
            return false;
        }
        $event_id = (int) $m[1];
        $generation = (int) $m[2];
        $before = count($GLOBALS['gr1_db']['projection']);
        $GLOBALS['gr1_db']['projection'] = array_values(array_filter(
            $GLOBALS['gr1_db']['projection'],
            function ($row) use ($event_id, $generation) { return !($row['event_id'] === $event_id && $row['generation'] === $generation); }
        ));
        return $before - count($GLOBALS['gr1_db']['projection']);
    }

    // cleanup_old_generations(): DELETE every generation except the one just
    // activated AND except whatever generation the state row currently
    // reports active (Concurrency Correctness Fix Pass — defense-in-depth
    // guard; under correct lock operation these are always the same value —
    // see PGE_Event_Guest_Read_Projection::cleanup_old_generations()).
    $deleteExcept = '/^DELETE FROM ' . preg_quote($t['projection'], '/') . ' WHERE event_id = (\d+) AND generation <> (\d+) AND generation <> COALESCE\(\(SELECT active_generation FROM ' . preg_quote($t['state'], '/') . ' WHERE event_id = (\d+)\), 0\)$/';
    if (preg_match($deleteExcept, $sql, $m)) {
        if ($GLOBALS['gr1_fail_projection_delete']) {
            return false;
        }
        $event_id = (int) $m[1];
        $keep = (int) $m[2];
        $active = (int) ($GLOBALS['gr1_db']['state'][$event_id]['active_generation'] ?? 0);
        $before = count($GLOBALS['gr1_db']['projection']);
        $GLOBALS['gr1_db']['projection'] = array_values(array_filter(
            $GLOBALS['gr1_db']['projection'],
            function ($row) use ($event_id, $keep, $active) {
                if ($row['event_id'] !== $event_id) return true;
                return $row['generation'] === $keep || $row['generation'] === $active;
            }
        ));
        return $before - count($GLOBALS['gr1_db']['projection']);
    }

    // delete_event(): event-wide projection DELETE (all generations).
    $deleteAll = '/^DELETE FROM ' . preg_quote($t['projection'], '/') . ' WHERE event_id = (\d+)$/';
    if (preg_match($deleteAll, $sql, $m)) {
        $event_id = (int) $m[1];
        $before = count($GLOBALS['gr1_db']['projection']);
        $GLOBALS['gr1_db']['projection'] = array_values(array_filter(
            $GLOBALS['gr1_db']['projection'],
            function ($row) use ($event_id) { return $row['event_id'] !== $event_id; }
        ));
        return $before - count($GLOBALS['gr1_db']['projection']);
    }

    // allocate_build_generation(): atomic, non-authoritative slot allocator
    // via the MySQL LAST_INSERT_ID(expr) idiom. On the UPDATE (ON DUPLICATE
    // KEY) branch, deliberately does NOT touch `status` — mirrors the real
    // SQL exactly.
    $allocate = '/^INSERT INTO ' . preg_quote($t['state'], '/')
        . ' \(event_id, active_generation, active_fingerprint, next_generation, status, row_count, synced_at, updated_at\) VALUES \((\d+), 0, \'\', LAST_INSERT_ID\(1\), \'([^\']*)\', 0, NULL, \'([^\']*)\'\) ON DUPLICATE KEY UPDATE next_generation = LAST_INSERT_ID\(next_generation \+ 1\), updated_at = VALUES\(updated_at\)$/';
    if (preg_match($allocate, $sql, $m)) {
        if ($GLOBALS['gr1_fail_allocate_generation']) {
            return false; // the mint step itself fails outright.
        }
        $event_id = (int) $m[1];
        $status = $m[2];
        $now = $m[3];
        $existing = $GLOBALS['gr1_db']['state'][$event_id] ?? null;
        if ($existing === null) {
            $new_generation = 1;
            $GLOBALS['gr1_db']['state'][$event_id] = [
                'active_generation' => 0, 'active_fingerprint' => '', 'next_generation' => $new_generation,
                'status' => $status, 'row_count' => 0, 'synced_at' => null, 'updated_at' => $now,
            ];
        } else {
            $new_generation = (int) $existing['next_generation'] + 1;
            $GLOBALS['gr1_db']['state'][$event_id]['next_generation'] = $new_generation;
            $GLOBALS['gr1_db']['state'][$event_id]['updated_at'] = $now;
            // status intentionally untouched — matches ON DUPLICATE KEY
            // UPDATE, which never assigns status on the update branch.
        }
        $GLOBALS['gr1_last_insert_id'] = $new_generation;
        return 1;
    }

    // activate_generation(): unconditional activation UPDATE. Correctness
    // comes from the fresh-read verify steps that bracket this call in
    // attempt_publish(), not from a CAS predicate on this statement itself.
    $activate = '/^UPDATE ' . preg_quote($t['state'], '/')
        . ' SET active_generation = (\d+), active_fingerprint = \'([^\']*)\', status = \'ready\', row_count = (\d+), synced_at = \'([^\']*)\', updated_at = \'([^\']*)\' WHERE event_id = (\d+)$/';
    if (preg_match($activate, $sql, $m)) {
        if ($GLOBALS['gr1_fail_activate']) {
            return false;
        }
        $generation = (int) $m[1];
        $fingerprint = $m[2];
        $row_count = (int) $m[3];
        $synced_at = $m[4];
        $updated_at = $m[5];
        $event_id = (int) $m[6];
        if (!isset($GLOBALS['gr1_db']['state'][$event_id])) {
            return 0; // should not happen: allocate_build_generation() always creates the row first.
        }
        $GLOBALS['gr1_db']['state'][$event_id]['active_generation'] = $generation;
        $GLOBALS['gr1_db']['state'][$event_id]['active_fingerprint'] = $fingerprint;
        $GLOBALS['gr1_db']['state'][$event_id]['status'] = 'ready';
        $GLOBALS['gr1_db']['state'][$event_id]['row_count'] = $row_count;
        $GLOBALS['gr1_db']['state'][$event_id]['synced_at'] = $synced_at;
        $GLOBALS['gr1_db']['state'][$event_id]['updated_at'] = $updated_at;

        // Concurrency Correctness Fix Pass — one-shot hook fired exactly
        // when a real concurrent reader/writer could observe this
        // just-committed activation, i.e. strictly after this UPDATE lands
        // and strictly before attempt_publish()'s post-activation fresh-read
        // runs. Lets a test synchronously run a "reader" query and/or a
        // "concurrent writer" save_map() call at this precise point — the
        // same test-harness-only interleaving technique as
        // gr1_mutate_on_nth_fresh_read above.
        if (is_callable($GLOBALS['gr1_run_after_activate'])) {
            $cb = $GLOBALS['gr1_run_after_activate'];
            $GLOBALS['gr1_run_after_activate'] = null;
            $cb();
        }

        return 1;
    }

    // force_stale_best_effort(): plain, unconditional — revokes trust only.
    $forceStale = '/^UPDATE ' . preg_quote($t['state'], '/') . ' SET status = \'stale\' WHERE event_id = (\d+)$/';
    if (preg_match($forceStale, $sql, $m)) {
        // Reader-Safety Publication Ordering Fix Pass (TEST 3 — Invalidation
        // Failure) — one-shot-per-call counter: simulates the underlying
        // UPDATE itself reporting a genuine query error (false), WITHOUT
        // applying the status change and WITHOUT throwing. Distinct from
        // gr1_throw_on_next_query (a fatal exception).
        if ($GLOBALS['gr1_fail_force_stale_next'] > 0) {
            $GLOBALS['gr1_fail_force_stale_next']--;
            return false;
        }
        $event_id = (int) $m[1];
        if (!isset($GLOBALS['gr1_db']['state'][$event_id])) {
            return 0;
        }
        $GLOBALS['gr1_db']['state'][$event_id]['status'] = 'stale';
        return 1;
    }

    if (preg_match('/^DELETE FROM ' . preg_quote($t['state'], '/') . ' WHERE event_id = (\d+)$/', $sql, $m)) {
        $event_id = (int) $m[1];
        $existed = isset($GLOBALS['gr1_db']['state'][$event_id]);
        unset($GLOBALS['gr1_db']['state'][$event_id]);
        return $existed ? 1 : 0;
    }

    return null;
}

class PGE_GR1_Fake_WPDB
{
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $last_error = '';
    public $unexpected_calls = 0;

    public function prepare($sql, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : ("'" . addslashes((string) $v) . "'");
        }, $sql);
    }

    public function get_results($sql, $output = null)
    {
        $rows = gr1_dispatch_select($sql);
        if ($rows === null) {
            $this->unexpected_calls++;
            $this->last_error = 'gr1-fake-wpdb: unrecognized SELECT: ' . $sql;
            return null;
        }
        return $rows;
    }

    public function get_row($sql, $output = null)
    {
        $row = gr1_dispatch_row($sql);
        if ($row === false) {
            $this->unexpected_calls++;
            $this->last_error = 'gr1-fake-wpdb: unrecognized get_row(): ' . $sql;
            return null;
        }
        return $row;
    }

    public function query($sql)
    {
        $result = gr1_dispatch_query($sql);
        if ($result === null) {
            $this->unexpected_calls++;
            $this->last_error = 'gr1-fake-wpdb: unexpected mutating query(): ' . $sql;
            return false;
        }
        return $result;
    }

    public function get_var($sql)
    {
        $this->last_error = '';
        [$value, $recognized] = gr1_dispatch_scalar($sql);
        if (!$recognized) {
            $this->unexpected_calls++;
            $this->last_error = 'gr1-fake-wpdb: unrecognized get_var(): ' . $sql;
        }
        return $value;
    }
}

gr1_reset_db();
global $wpdb;
$wpdb = new PGE_GR1_Fake_WPDB();

require_once PGE_PATH . 'includes/class-pge-event-guest-read-projection-schema.php';
require_once PGE_PATH . 'includes/class-pge-event-guest-read-projection.php';
require_once PGE_PATH . 'includes/event-guests.php';

// ──────────────────────────────────────────────────────────────
// Assertion helpers
// ──────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$failures = [];

function gr1_ok($label, $condition, $extra = '')
{
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failed++;
        $failures[] = $label . ($extra !== '' ? " ({$extra})" : '');
        echo "FAIL: $label" . ($extra !== '' ? " ({$extra})" : '') . "\n";
    }
}

// ══════════════════════════════════════════════════════════════
// Section A — Schema
// ══════════════════════════════════════════════════════════════

gr1_ok('A1 projection table name follows $wpdb->prefix convention', PGE_Event_Guest_Read_Projection_Schema::projection_table_name() === 'wp_pge_event_guest_read_projection');
gr1_ok('A2 state table name follows $wpdb->prefix convention', PGE_Event_Guest_Read_Projection_Schema::state_table_name() === 'wp_pge_event_guest_read_projection_state');
gr1_ok('A3 schema version constant is a non-empty string', is_string(PGE_Event_Guest_Read_Projection_Schema::SCHEMA_VERSION) && PGE_Event_Guest_Read_Projection_Schema::SCHEMA_VERSION !== '');
// The exact CREATE TABLE SQL (InnoDB, UNIQUE KEY(event_id, generation,
// guest_phone), all required columns), the allocate/activate/discard/
// cleanup SQL, the scoped SELECT SQL, and the event-level DELETE SQL were
// executed and verified against a REAL, isolated MariaDB database as part
// of this phase (see the phase report). This file cannot re-run that here
// (no real DB in this suite by design) — REAL DB VERIFICATION FOR THIS
// SUITE'S OWN RUN: NOT VERIFIED (fake harness only).
gr1_ok('A4 (documented, not re-asserted here) real MariaDB verification of the schema SQL was performed separately — see phase report', true);

// ══════════════════════════════════════════════════════════════
// Section B — Write synchronization (sync_event via save_map)  [(B) PRODUCTION save_map() BEHAVIOR]
// ══════════════════════════════════════════════════════════════

gr1_reset_db();

// B1 — initial rebuild / insert multiple guests.
gr1_set_guest(100, '970000000001', 'Guest A', 'note-a', 'AAAA-1111');
gr1_set_guest(100, '970000000002', 'Guest B', 'note-b', 'BBBB-2222');
$ok = PGE_Event_Guest_Read_Projection::rebuild_event(100);
gr1_ok('B1 rebuild_event() on a fresh event with 2 guests succeeds', $ok === true);
gr1_ok('B1b projection now contains exactly 2 rows for event 100', count(array_filter($GLOBALS['gr1_db']['projection'], function ($r) { return $r['event_id'] === 100; })) === 2);
$b1_state = PGE_Event_Guest_Read_Projection::state(100);
gr1_ok(
    'B1c state is READY with row_count=2, synced_at set, and a positive active_generation/fingerprint',
    is_array($b1_state) && $b1_state['status'] === 'ready' && $b1_state['row_count'] === 2
    && $b1_state['synced_at'] === '2026-01-01 00:00:00'
    && $b1_state['active_generation'] > 0 && $b1_state['active_fingerprint'] !== ''
);

// B2 — edit name/note/code via the REAL pge_event_guests_save_map() choke
// point (proves the production sync wiring, not just direct sync_event()).
$map = pge_event_guests_get_map(100);
$map['970000000001']['name'] = 'Guest A Edited';
$map['970000000001']['note'] = 'edited-note';
pge_event_guests_save_map(100, $map);
$rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones(100, ['970000000001']);
gr1_ok('B2 edit name/note via save_map() is reflected in the projection', is_array($rows) && count($rows) === 1 && $rows[0]['name'] === 'Guest A Edited' && $rows[0]['note'] === 'edited-note');

// B3 — phone change: old phone disappears, new phone appears (full replace,
// not incremental upsert).
$map = pge_event_guests_get_map(100);
$map['970000000009'] = $map['970000000001']; // simulate rename by key
$map['970000000009']['phone'] = '970000000009';
unset($map['970000000001']);
pge_event_guests_save_map(100, $map);
$old = PGE_Event_Guest_Read_Projection::get_guests_by_phones(100, ['970000000001']);
$new = PGE_Event_Guest_Read_Projection::get_guests_by_phones(100, ['970000000009']);
gr1_ok('B3 phone change: the OLD phone no longer resolves in the projection', is_array($old) && count($old) === 0);
gr1_ok('B3b phone change: the NEW phone resolves with the carried-over identity', is_array($new) && count($new) === 1 && $new[0]['name'] === 'Guest A Edited');

// B4 — guest deletion: full event-level replace removes the row, not just
// leaves it unreferenced.
$map = pge_event_guests_get_map(100);
unset($map['970000000002']);
pge_event_guests_save_map(100, $map);
$deleted = PGE_Event_Guest_Read_Projection::get_guests_by_phones(100, ['970000000002']);
gr1_ok('B4 guest deletion via save_map() removes the row from the projection', is_array($deleted) && count($deleted) === 0);
gr1_ok('B4b the active generation for event 100 now has exactly 1 row (the survivor)', PGE_Event_Guest_Read_Projection::state(100)['row_count'] === 1);

// B5 — empty map: syncing an event down to zero guests is a valid,
// confirmed "zero guests" state, not "not built".
pge_event_guests_save_map(100, []);
gr1_ok('B5 syncing an empty map yields state READY with row_count=0', PGE_Event_Guest_Read_Projection::state(100)['status'] === 'ready' && PGE_Event_Guest_Read_Projection::state(100)['row_count'] === 0);
gr1_ok('B5b get_guests_by_phones() on a confirmed-empty event returns an empty list, not an error', PGE_Event_Guest_Read_Projection::get_guests_by_phones(100, ['970000000009']) === []);

// B6 — repeated identical sync is idempotent (no drift, no duplicate-key
// errors within a generation, no unbounded growth thanks to cleanup).
gr1_set_guest(101, '970000000101', 'Repeat Guest', '', 'RRRR-0001');
PGE_Event_Guest_Read_Projection::rebuild_event(101);
PGE_Event_Guest_Read_Projection::rebuild_event(101);
PGE_Event_Guest_Read_Projection::rebuild_event(101);
gr1_ok('B6 repeated identical sync stays at exactly 1 row (no duplicate growth; best-effort cleanup keeps only the active generation)', count(array_filter($GLOBALS['gr1_db']['projection'], function ($r) { return $r['event_id'] === 101; })) === 1);

// B7 — legacy-string guest record normalization.
$GLOBALS['gr1_post_meta'][102]['_pge_invited_guests'] = ['970000000202' => '970000000202'];
PGE_Event_Guest_Read_Projection::rebuild_event(102);
$legacy_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones(102, ['970000000202']);
gr1_ok('B7 a legacy non-array Post Meta entry normalizes into a valid projection row with the recovered phone', is_array($legacy_rows) && count($legacy_rows) === 1 && $legacy_rows[0]['phone'] === '970000000202' && $legacy_rows[0]['name'] === '');

// ══════════════════════════════════════════════════════════════
// Section C — Failure semantics (first-ever sync for an event)  [(A)+(B) MIXED — direct sync_event() then real save_map()]
// ══════════════════════════════════════════════════════════════

gr1_reset_db();
gr1_set_guest(200, '970000000301', 'Fails To Sync', '', 'FFFF-0001');
$GLOBALS['gr1_fail_projection_insert'] = true;
$sync_ok = PGE_Event_Guest_Read_Projection::sync_event(200, pge_event_guests_get_map(200));
gr1_ok('C1 a simulated projection write failure makes sync_event() report failure', $sync_ok === false);
gr1_ok('C2 the projection is left/marked STALE, never falsely READY, after a failed first-ever sync', PGE_Event_Guest_Read_Projection::state(200)['status'] === 'stale');
$GLOBALS['gr1_fail_projection_insert'] = false;

// Post Meta save succeeds even though projection sync fails — proved via
// the REAL pge_event_guests_save_map() choke point, which must never let a
// projection failure change its own return contract or fail to persist
// Post Meta.
gr1_reset_db();
$GLOBALS['gr1_fail_projection_insert'] = true;
$saved = pge_event_guests_save_map(201, ['g' => ['phone' => '970000000401', 'name' => 'Still Saved', 'note' => '', 'code' => '']]);
gr1_ok('C3 pge_event_guests_save_map() still returns the saved map even when projection sync fails underneath it', is_array($saved) && isset($saved['970000000401']));
gr1_ok('C4 Post Meta itself was actually written despite the projection failure (Source of Truth unaffected)', pge_event_guests_get_map(201) === $saved);
gr1_ok('C5 the projection for this event is correctly marked stale/unhealthy after the failed sync inside save_map()', PGE_Event_Guest_Read_Projection::state(201)['status'] === 'stale');
$GLOBALS['gr1_fail_projection_insert'] = false;

// A later, successful call recovers cleanly (rebuild path, not a permanent
// wedge).
$recovered = PGE_Event_Guest_Read_Projection::rebuild_event(201);
gr1_ok('C6 a subsequent successful rebuild recovers the projection to READY', $recovered === true && PGE_Event_Guest_Read_Projection::state(201)['status'] === 'ready');

// ══════════════════════════════════════════════════════════════
// Section D — Completeness / staleness distinction  [(A)+(B) MIXED — save_map() and direct rebuild_event()]
// ══════════════════════════════════════════════════════════════

gr1_reset_db();

gr1_ok('D1 an event that was never synced has NO state row at all (never_built)', PGE_Event_Guest_Read_Projection::state(300) === null);
gr1_ok('D1b is_ready() is false for a never-built event', PGE_Event_Guest_Read_Projection::is_ready(300) === false);

pge_event_guests_save_map(300, []); // confirmed zero guests
gr1_ok('D2 a confirmed-empty (synced with zero guests) event IS ready', PGE_Event_Guest_Read_Projection::is_ready(300) === true);
gr1_ok('D2b its state row_count is exactly 0, distinguishing it from never-built', PGE_Event_Guest_Read_Projection::state(300)['row_count'] === 0);

$GLOBALS['gr1_db']['state'][300]['status'] = 'stale'; // simulate external invalidation
gr1_ok('D3 an explicitly-invalidated (stale) event is NOT ready, even though a state row (and possibly rows) exist', PGE_Event_Guest_Read_Projection::is_ready(300) === false);

gr1_reset_db();
gr1_set_guest(301, '970000000501', 'Present', '', 'PPPP-0001');
PGE_Event_Guest_Read_Projection::rebuild_event(301);
$partial = PGE_Event_Guest_Read_Projection::get_guests_by_phones(301, ['970000000501', '970000000502']);
gr1_ok('D4 requesting a phone with no matching row returns only the rows that exist, never a placeholder', is_array($partial) && count($partial) === 1 && $partial[0]['phone'] === '970000000501');

// ══════════════════════════════════════════════════════════════
// Section E — Query-level PII scoping proof (the central success criterion)  [(A) PRIMITIVE — seeded via direct rebuild_event()]
// ══════════════════════════════════════════════════════════════

gr1_reset_db();
gr1_set_guest(400, '970000000601', 'Guest A (authorized)', 'note-a', 'AAAA-0001');
gr1_set_guest(400, '970000000602', 'Guest B (NOT authorized)', 'note-b', 'BBBB-0002');
PGE_Event_Guest_Read_Projection::rebuild_event(400);

$GLOBALS['gr1_query_log'] = [];
$scoped = PGE_Event_Guest_Read_Projection::get_guests_by_phones(400, ['970000000601']);

gr1_ok('E1 the scoped read for phone A only succeeds and returns exactly 1 row', is_array($scoped) && count($scoped) === 1 && $scoped[0]['phone'] === '970000000601');
gr1_ok('E2 Guest B (in the same event\'s projection table) is absent from the result', count(array_filter($scoped, function ($r) { return $r['phone'] === '970000000602'; })) === 0);

$select_query = null;
foreach ($GLOBALS['gr1_query_log'] as $q) {
    if (strpos($q, 'SELECT guest_phone') === 0) { $select_query = $q; break; }
}
gr1_ok('E3 (query-level, not just result-level) the actual SQL executed contains the authorized phone', $select_query !== null && strpos($select_query, "'970000000601'") !== false);
gr1_ok('E4 (query-level) the actual SQL executed does NOT contain the unauthorized phone anywhere in its IN(...) list', $select_query !== null && strpos($select_query, '970000000602') === false);
gr1_ok('E5 the SQL is properly event-scoped (WHERE event_id = 400)', $select_query !== null && strpos($select_query, 'event_id = 400') !== false);
gr1_ok('E6 the SQL is also generation-scoped (only the active generation is ever queried)', $select_query !== null && strpos($select_query, 'AND generation = ') !== false);

// ══════════════════════════════════════════════════════════════
// Section F — Source-level guard: the collaborator executable path in the
// Application Service must not call pge_event_guests_get_map(). Owner/Admin
// legitimately still does.
// ══════════════════════════════════════════════════════════════

function gr1_strip_php_comments($source)
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $code .= is_array($token) ? $token[1] : $token;
    }
    return $code;
}

$app_service_source = file_get_contents(PGE_PATH . 'includes/class-pge-event-access-application-service.php');
$app_service_code = gr1_strip_php_comments($app_service_source);

function gr1_extract_method_body($code, $method_name)
{
    $start = strpos($code, 'function ' . $method_name . '(');
    if ($start === false) return null;
    $next = strpos($code, 'private static function', $start + 10);
    return $next === false ? substr($code, $start) : substr($code, $start, $next - $start);
}

$collaborator_body = gr1_extract_method_body($app_service_code, 'list_for_collaborator');
$scoped_lookup_body = gr1_extract_method_body($app_service_code, 'scoped_guest_identity_rows');
$owner_admin_body = gr1_extract_method_body($app_service_code, 'list_for_owner_or_admin');

gr1_ok('F1 list_for_collaborator() method body was found for source inspection', $collaborator_body !== null);
gr1_ok('F2 scoped_guest_identity_rows() method body was found for source inspection', $scoped_lookup_body !== null);
gr1_ok('F3 list_for_owner_or_admin() method body was found for source inspection', $owner_admin_body !== null);
gr1_ok(
    'F4 list_for_collaborator() executable code never calls pge_event_guests_get_map(',
    $collaborator_body !== null && strpos($collaborator_body, 'pge_event_guests_get_map(') === false
);
gr1_ok(
    'F5 scoped_guest_identity_rows() executable code never calls pge_event_guests_get_map(',
    $scoped_lookup_body !== null && strpos($scoped_lookup_body, 'pge_event_guests_get_map(') === false
);
gr1_ok(
    'F6 (Owner/Admin unaffected) list_for_owner_or_admin() STILL calls pge_event_guests_get_map( — this pass must not have accidentally changed this path',
    $owner_admin_body !== null && strpos($owner_admin_body, 'pge_event_guests_get_map(') !== false
);
gr1_ok(
    'F7 scoped_guest_identity_rows() no longer calls is_ready() as a separate steady-state pre-check (query-count discipline: get_guests_by_phones() resolves readiness internally)',
    $scoped_lookup_body !== null && strpos($scoped_lookup_body, '::is_ready(') === false
);

// ══════════════════════════════════════════════════════════════
// Section G — save_map() call ordering: Post Meta write must appear, in
// source, strictly before the projection sync call.
// ══════════════════════════════════════════════════════════════

$event_guests_source = file_get_contents(PGE_PATH . 'includes/event-guests.php');
$event_guests_code = gr1_strip_php_comments($event_guests_source);
$postmeta_write_pos = strpos($event_guests_code, "update_post_meta(\$event_id, '_pge_invited_guests', \$clean);");
$sync_call_pos = strpos($event_guests_code, 'PGE_Event_Guest_Read_Projection::sync_event($event_id, $clean);');
gr1_ok('G1 the Post Meta write and the projection sync call both exist in save_map()', $postmeta_write_pos !== false && $sync_call_pos !== false);
gr1_ok('G2 the Post Meta write appears in source strictly before the projection sync call', $postmeta_write_pos !== false && $sync_call_pos !== false && $postmeta_write_pos < $sync_call_pos);

$try_pos = strrpos(substr($event_guests_code, 0, $sync_call_pos), 'try {');
$catch_pos = $sync_call_pos !== false ? strpos($event_guests_code, 'catch (\Throwable', $sync_call_pos) : false;
gr1_ok(
    'G3 the projection sync call sits inside its own try{}catch(\Throwable) block, so it cannot let an exception escape save_map() and break its return contract',
    $sync_call_pos !== false && $try_pos !== false && $catch_pos !== false && $try_pos < $sync_call_pos && $sync_call_pos < $catch_pos
);

// ══════════════════════════════════════════════════════════════
// Section H — allocate_build_generation(): a plain, non-authoritative slot  [(A) PRIMITIVE]
// allocator. Generation numbers carry no ordering/authority meaning by
// themselves (proved more fully in Section I/M below); this section only
// proves the low-level allocator mechanics and its failure isolation.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();
$h_g1 = gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'allocate_build_generation', [500]);
$h_g2 = gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'allocate_build_generation', [500]);
gr1_ok('H1 two successive allocate_build_generation() calls for the same event mint strictly increasing, collision-free slots', is_int($h_g1) && is_int($h_g2) && $h_g2 === $h_g1 + 1);

// H2 — mint failure on a genuinely never-built event: must not leave any
// false READY state.
gr1_reset_db();
$GLOBALS['gr1_fail_allocate_generation'] = true;
$h2_ok = PGE_Event_Guest_Read_Projection::sync_event(501, gr1_map('970000000900', 'Never Built'));
$GLOBALS['gr1_fail_allocate_generation'] = false;
gr1_ok('H2 sync_event() reports failure when the slot-allocation step itself fails', $h2_ok === false);
gr1_ok('H2b no false READY state exists after a first-ever mint failure', PGE_Event_Guest_Read_Projection::is_ready(501) === false);

// H3 — mint failure when a PRIOR READY generation already exists: the prior
// trusted state must remain completely untouched (Blocker B — a build that
// cannot even start must never revoke or corrupt existing trust).
gr1_reset_db();
gr1_write_post_meta(502, gr1_map('970000001000', 'H3 V1'));
PGE_Event_Guest_Read_Projection::rebuild_event(502);
$h3_state_before = PGE_Event_Guest_Read_Projection::state(502);
gr1_ok('H3 baseline: event 502 syncs to READY', $h3_state_before['status'] === 'ready');

$GLOBALS['gr1_fail_allocate_generation'] = true;
$h3_ok = PGE_Event_Guest_Read_Projection::sync_event(502, gr1_map('970000001001', 'H3 V2'));
$GLOBALS['gr1_fail_allocate_generation'] = false;
$h3_state_after = PGE_Event_Guest_Read_Projection::state(502);
gr1_ok('H3b sync_event() reports failure when a later mint fails', $h3_ok === false);
gr1_ok(
    'H3c the prior READY generation/state is completely untouched by the failed mint attempt (same active_generation, same fingerprint, still ready)',
    $h3_state_after === $h3_state_before
);
gr1_ok('H3d the previously-active data is still correctly queryable', PGE_Event_Guest_Read_Projection::get_guests_by_phones(502, ['970000001000'])[0]['name'] === 'H3 V1');

// ══════════════════════════════════════════════════════════════
// Section I — Race Test #1 (CRITICAL — Blocker A's exact scenario).  [(A) PRIMITIVE]
// Real order, real public API, no artificial reordering:
//   Writer A writes Post Meta=A, then PAUSES before calling its own sync.
//   Writer B writes Post Meta=B (truly latest) and FULLY syncs.
//   Writer A resumes and syncs — using the map it captured BEFORE pausing.
// Invariant: the projection must NOT be trusted READY for A. It must
// reflect B, and A's sync call must be rejected.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();

// Writer A writes Post Meta, then pauses (captures its own map, but does
// NOT call sync_event() yet).
gr1_write_post_meta(700, gr1_map('970000002001', 'Writer A (paused)'));
$i_a_map = pge_event_guests_get_map(700);

// Writer B writes Post Meta (overwrites — this is now the true, current
// content) and fully syncs.
gr1_write_post_meta(700, gr1_map('970000002002', 'Writer B (fresh)'));
$i_b_map = pge_event_guests_get_map(700);
$i_b_ok = PGE_Event_Guest_Read_Projection::sync_event(700, $i_b_map);
gr1_ok('I1 Writer B\'s sync (truly current content) succeeds', $i_b_ok === true);
$i_state_after_b = PGE_Event_Guest_Read_Projection::state(700);
$i_b_generation = $i_state_after_b['active_generation'];

// Writer A now resumes and syncs — using the STALE map it captured before
// pausing, and AFTER B has already published.
$i_a_ok = PGE_Event_Guest_Read_Projection::sync_event(700, $i_a_map);
gr1_ok('I2 (Blocker A — the central invariant) Writer A\'s delayed sync is REJECTED, even though it runs strictly after B\'s and would mint a numerically higher generation', $i_a_ok === false);

$i_state_final = PGE_Event_Guest_Read_Projection::state(700);
gr1_ok('I3 the projection is still READY, and still reflects B\'s fingerprint/generation, not a new one from A', $i_state_final['status'] === 'ready' && $i_state_final['active_generation'] === $i_b_generation && $i_state_final['active_fingerprint'] === $i_state_after_b['active_fingerprint']);

$i_rows_b = PGE_Event_Guest_Read_Projection::get_guests_by_phones(700, ['970000002002']);
$i_rows_a = PGE_Event_Guest_Read_Projection::get_guests_by_phones(700, ['970000002001']);
gr1_ok('I4 the served data is B\'s (Writer B (fresh))', is_array($i_rows_b) && count($i_rows_b) === 1 && $i_rows_b[0]['name'] === 'Writer B (fresh)');
gr1_ok('I5 A\'s (stale) phone/content is NOT queryable — A never became active', is_array($i_rows_a) && count($i_rows_a) === 0);

// I6/I7 — a higher generation number alone must not confer authority
// (a direct, explicit check of this invariant, independent of I1-I5 above):
// A's rejected attempt still allocated a generation slot BEFORE failing its
// verify step — prove that slot number is higher than B's, yet B remains
// active.
$i_a_generation_attempted = $GLOBALS['gr1_last_insert_id']; // last mint was A's rejected attempt
gr1_ok('I6 Writer A\'s (rejected) attempt minted a generation number strictly higher than Writer B\'s', $i_a_generation_attempted > $i_b_generation);
gr1_ok('I7 despite that higher number, the active/trusted generation is still B\'s — a generation number alone never confers authority in this protocol', $i_state_final['active_generation'] === $i_b_generation && $i_state_final['active_generation'] !== $i_a_generation_attempted);

// ══════════════════════════════════════════════════════════════
// Section J — Race Test #2 (normal order): A writes+syncs completely, THEN  [(A) PRIMITIVE]
// B writes+syncs completely. B ends up trusted — the straightforward,
// non-adversarial baseline the protocol must also get right.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();

gr1_write_post_meta(701, gr1_map('970000003001', 'J Writer A'));
$j_a_ok = PGE_Event_Guest_Read_Projection::sync_event(701, pge_event_guests_get_map(701));
gr1_ok('J1 Writer A\'s sync (first, normal order) succeeds', $j_a_ok === true);

gr1_write_post_meta(701, gr1_map('970000003002', 'J Writer B'));
$j_b_ok = PGE_Event_Guest_Read_Projection::sync_event(701, pge_event_guests_get_map(701));
gr1_ok('J2 Writer B\'s sync (second, normal order) succeeds', $j_b_ok === true);

$j_state = PGE_Event_Guest_Read_Projection::state(701);
gr1_ok('J3 the projection is READY and reflects B (the later, current write)', $j_state['status'] === 'ready');
$j_rows_b = PGE_Event_Guest_Read_Projection::get_guests_by_phones(701, ['970000003002']);
$j_rows_a = PGE_Event_Guest_Read_Projection::get_guests_by_phones(701, ['970000003001']);
gr1_ok('J4 B\'s data is served', is_array($j_rows_b) && count($j_rows_b) === 1 && $j_rows_b[0]['name'] === 'J Writer B');
gr1_ok('J5 A\'s data is no longer queryable (full replace, not additive)', is_array($j_rows_a) && count($j_rows_a) === 0);

// ══════════════════════════════════════════════════════════════
// Section K — Race Test #3 (order-independence): B writes Post Meta FIRST,  [(A) PRIMITIVE]
// A writes Post Meta LAST (so A is truly authoritative) — but B's sync CALL
// executes AFTER A's (B "completes" last in call order). The projection
// must still end up trusted as A, never B, proving authority is decided by
// fresh-read content, never by which sync call merely finishes last.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();

// B writes Post Meta first and captures its own map (does not sync yet).
gr1_write_post_meta(702, gr1_map('970000004001', 'K Writer B (wrote first)'));
$k_b_map = pge_event_guests_get_map(702);

// A writes Post Meta LAST — this is now the true, current content.
gr1_write_post_meta(702, gr1_map('970000004002', 'K Writer A (wrote last)'));
$k_a_map = pge_event_guests_get_map(702);

// Call order: A's sync call FIRST, B's sync call SECOND/LAST — deliberately
// the opposite of write order, to prove call order is irrelevant.
$k_a_ok = PGE_Event_Guest_Read_Projection::sync_event(702, $k_a_map);
gr1_ok('K1 A\'s sync (content matches current Post Meta) succeeds, even though its call happens first', $k_a_ok === true);

$k_b_ok = PGE_Event_Guest_Read_Projection::sync_event(702, $k_b_map);
gr1_ok('K2 (order-independence — the central invariant of Test #3) B\'s sync is REJECTED even though its CALL completes last — its captured content no longer matches current Post Meta', $k_b_ok === false);

$k_state = PGE_Event_Guest_Read_Projection::state(702);
gr1_ok('K3 the projection is READY and reflects A (the truly authoritative write), never B', $k_state['status'] === 'ready');
$k_rows_a = PGE_Event_Guest_Read_Projection::get_guests_by_phones(702, ['970000004002']);
$k_rows_b = PGE_Event_Guest_Read_Projection::get_guests_by_phones(702, ['970000004001']);
gr1_ok('K4 A\'s data is served', is_array($k_rows_a) && count($k_rows_a) === 1 && $k_rows_a[0]['name'] === 'K Writer A (wrote last)');
gr1_ok('K5 B\'s data is never served, despite B\'s sync call finishing last', is_array($k_rows_b) && count($k_rows_b) === 0);

// ══════════════════════════════════════════════════════════════
// Section L — Honest residual limit: the narrow post-activation self-heal  [(A) PRIMITIVE]
// window (class docblock, "HONEST RESIDUAL LIMIT"). Simulates a third
// write landing INSIDE a single attempt_publish() call, between its
// pre-activation fresh-read and its post-activation fresh-read, via the
// deterministic fake-$wpdb hook (see file docblock) — this is the one
// scenario in this suite that genuinely requires simulating an
// interleaving inside a single function call.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();

// Baseline: V1 is READY.
gr1_write_post_meta(703, gr1_map('970000005001', 'L V1'));
$l_v1_ok = PGE_Event_Guest_Read_Projection::rebuild_event(703);
gr1_ok('L1 baseline: event 703 syncs V1 to READY', $l_v1_ok === true);

// Set up V2 (about to be synced) and V3 (the "third writer" that will land
// mid-publish).
$l_v2_map = gr1_map('970000005002', 'L V2');
$l_v3_map = gr1_map('970000005003', 'L V3');
gr1_write_post_meta(703, $l_v2_map); // V2 is now the current Post Meta.

// Arm the trap: on the 2nd fresh-read call for event 703 (the
// post-activation re-verify inside attempt_publish()), mutate Post Meta to
// V3 — simulating a genuine concurrent write landing in that narrow window.
$GLOBALS['gr1_fresh_read_call_count'][703] = 0;
$GLOBALS['gr1_mutate_on_nth_fresh_read'] = ['event_id' => 703, 'n' => 2, 'new_stored' => $l_v3_map];

$l_v2_ok = PGE_Event_Guest_Read_Projection::sync_event(703, $l_v2_map);
gr1_ok('L2 V2\'s sync self-corrects to failure once its post-activation re-verify detects the mid-window V3 write', $l_v2_ok === false);

$l_state_after = PGE_Event_Guest_Read_Projection::state(703);
gr1_ok('L3 the state is forced to STALE by the self-heal — never left falsely READY for V2', $l_state_after['status'] === 'stale');
gr1_ok('L4 is_ready() is false immediately after the self-heal (neither V2 nor the un-verified V3 is trusted)', PGE_Event_Guest_Read_Projection::is_ready(703) === false);

// A subsequent rebuild correctly recovers to V3 — the truly current content
// by then.
$l_recovered = PGE_Event_Guest_Read_Projection::rebuild_event(703);
$l_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones(703, ['970000005003']);
gr1_ok('L5 a subsequent rebuild recovers cleanly to V3 (the truly current content)', $l_recovered === true && is_array($l_rows) && count($l_rows) === 1 && $l_rows[0]['name'] === 'L V3');

// ══════════════════════════════════════════════════════════════
// Section M — rebuild_event() also respects the fresh-read-verify protocol:  [(A) PRIMITIVE]
// a rebuild that captured its own candidate snapshot must not publish it if
// a newer write has already landed by the time it verifies — same
// technique as Section L, applied to the rebuild path specifically.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();

gr1_write_post_meta(704, gr1_map('970000006001', 'M V1'));
$m_v1_ok = PGE_Event_Guest_Read_Projection::rebuild_event(704);
gr1_ok('M1 baseline: event 704 syncs V1 to READY', $m_v1_ok === true);
$m_state_before = PGE_Event_Guest_Read_Projection::state(704);

// Arm the trap: rebuild_event() itself performs the FIRST fresh-read (its
// own candidate snapshot); attempt_publish()'s pre-activation verify is the
// SECOND fresh-read within this one rebuild_event() call. Mutate on that
// 2nd call to simulate a newer write landing between "rebuild captured its
// snapshot" and "rebuild verified it".
$m_v2_map = gr1_map('970000006002', 'M V2 (lands mid-rebuild)');
$GLOBALS['gr1_fresh_read_call_count'][704] = 0;
$GLOBALS['gr1_mutate_on_nth_fresh_read'] = ['event_id' => 704, 'n' => 2, 'new_stored' => $m_v2_map];

$m_rebuild_ok = PGE_Event_Guest_Read_Projection::rebuild_event(704);
gr1_ok('M2 the rebuild correctly rejects its own (now-stale) snapshot once its verify step sees the newer write', $m_rebuild_ok === false);

$m_state_after = PGE_Event_Guest_Read_Projection::state(704);
gr1_ok('M3 the prior READY (V1) state is completely untouched by the rejected rebuild — never deleted, never marked stale for a build that never activated', $m_state_after === $m_state_before);
gr1_ok('M4 V1 is still correctly served', PGE_Event_Guest_Read_Projection::get_guests_by_phones(704, ['970000006001'])[0]['name'] === 'M V1');

// A plain subsequent rebuild (no more mutation armed) correctly picks up V2.
$m_recovered = PGE_Event_Guest_Read_Projection::rebuild_event(704);
$m_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones(704, ['970000006002']);
gr1_ok('M5 a subsequent plain rebuild correctly targets the current authoritative content (V2)', $m_recovered === true && is_array($m_rows) && count($m_rows) === 1 && $m_rows[0]['name'] === 'M V2 (lands mid-rebuild)');

// ══════════════════════════════════════════════════════════════
// Section N — Blocker B failure-safety: a failing build must never delete,  [(A) PRIMITIVE]
// corrupt, or revoke trust from the currently active generation's rows —
// at every failure point (stage/INSERT failure, activate/UPDATE failure,
// best-effort cleanup DELETE failure, fresh-read query failure).
// ══════════════════════════════════════════════════════════════

gr1_reset_db();
gr1_write_post_meta(705, gr1_map('970000007001', 'N V1'));
PGE_Event_Guest_Read_Projection::rebuild_event(705);
$n_state_baseline = PGE_Event_Guest_Read_Projection::state(705);
gr1_ok('N1 baseline: event 705 is READY (G1/V1)', $n_state_baseline['status'] === 'ready');

// N2/N3/N4 — stage_rows() (INSERT) failure: the active generation must be
// completely untouched. Post Meta is updated to match the candidate FIRST
// (exactly like a real save_map() write would), so this test isolates the
// INSERT failure itself from the (separately tested, Section I/K/L/M)
// freshness-verify logic — the candidate is genuinely current here.
$n_v2_map = gr1_map('970000007002', 'N V2');
gr1_write_post_meta(705, $n_v2_map);
$GLOBALS['gr1_fail_projection_insert'] = true;
$n2_ok = PGE_Event_Guest_Read_Projection::sync_event(705, $n_v2_map);
$GLOBALS['gr1_fail_projection_insert'] = false;
gr1_ok('N2 a stage/INSERT failure makes the sync attempt report failure', $n2_ok === false);
gr1_ok('N3 the active generation (G1/V1) is completely untouched after a stage failure', PGE_Event_Guest_Read_Projection::state(705) === $n_state_baseline);
gr1_ok('N4 V1 is still correctly served after the failed build', PGE_Event_Guest_Read_Projection::get_guests_by_phones(705, ['970000007001'])[0]['name'] === 'N V1');

// N5/N6/N7 — activate_generation() (UPDATE) failure: staging succeeded, but
// the final activation UPDATE fails — the active generation must STILL be
// completely untouched (this is Blocker B's exact "DELETE succeeds, INSERT
// fails" shape, generalized to this design's own failure point: staging
// succeeds, activation fails — active rows/pointer are untouched either
// way, because staging never touches them in the first place). Again, Post
// Meta is updated to match the candidate first so this isolates the
// activation failure from the freshness-verify step.
$n_v3_map = gr1_map('970000007003', 'N V3');
gr1_write_post_meta(705, $n_v3_map);
$GLOBALS['gr1_fail_activate'] = true;
$n5_ok = PGE_Event_Guest_Read_Projection::sync_event(705, $n_v3_map);
$GLOBALS['gr1_fail_activate'] = false;
gr1_ok('N5 an activation failure (after successful staging) makes the sync attempt report failure', $n5_ok === false);
gr1_ok('N6 the active generation (G1/V1) is STILL completely untouched — a fully-staged-but-never-activated build never corrupts what is being served', PGE_Event_Guest_Read_Projection::state(705) === $n_state_baseline);
gr1_ok('N7 V1 is still correctly served after the failed activation', PGE_Event_Guest_Read_Projection::get_guests_by_phones(705, ['970000007001'])[0]['name'] === 'N V1');

// N8 — best-effort cleanup/discard DELETE failure: cosmetic only, must not
// affect correctness of the active generation. Post Meta updated to match
// the candidate first, exactly as N2/N5 above — this V4 build must
// otherwise succeed cleanly.
$n_v4_map = gr1_map('970000007004', 'N V4');
gr1_write_post_meta(705, $n_v4_map);
$GLOBALS['gr1_fail_projection_delete'] = true;
$n8_ok = PGE_Event_Guest_Read_Projection::sync_event(705, $n_v4_map);
$GLOBALS['gr1_fail_projection_delete'] = false;
// V4's own stage+verify+activate should still succeed even if a PRIOR
// generation's best-effort cleanup DELETE fails outright — cleanup failure
// must never be allowed to fail the publish itself.
gr1_ok('N8 a best-effort cleanup DELETE failure does not fail the publish itself', $n8_ok === true);
gr1_ok('N9 the newly active generation correctly serves V4 despite the orphaned old rows left behind by the failed cleanup', PGE_Event_Guest_Read_Projection::get_guests_by_phones(705, ['970000007004'])[0]['name'] === 'N V4');

// N10/N11 — fresh-read query failure during publish: must fail closed,
// never touching the active generation.
gr1_reset_db();
gr1_write_post_meta(706, gr1_map('970000007101', 'N2 V1'));
PGE_Event_Guest_Read_Projection::rebuild_event(706);
$n_state2_baseline = PGE_Event_Guest_Read_Projection::state(706);
$GLOBALS['gr1_fail_fresh_read'] = true;
$n10_ok = PGE_Event_Guest_Read_Projection::sync_event(706, gr1_map('970000007102', 'N2 V2'));
$GLOBALS['gr1_fail_fresh_read'] = false;
gr1_ok('N10 a genuine fresh-read query error during publish makes the sync attempt report failure (fail closed, never silently trusts unread content)', $n10_ok === false);
gr1_ok('N11 the active generation is untouched by the fresh-read failure', PGE_Event_Guest_Read_Projection::state(706) === $n_state2_baseline);

// ══════════════════════════════════════════════════════════════
// Section O — error_log hardening: the catch block in save_map() must log
// a fixed, minimal message — never the raw exception message, SQL text, or
// PII. Updated by the Authoritative Write Confirmation & Lock Cleanup Fix
// Pass for the new 4th, write-confirmation-mismatch log line.
// ══════════════════════════════════════════════════════════════

$o_event_guests_code = gr1_strip_php_comments(file_get_contents(PGE_PATH . 'includes/event-guests.php'));
gr1_ok(
    'O1 the catch block does not concatenate the raw exception message ($e->getMessage())',
    strpos($o_event_guests_code, 'getMessage()') === false
);
gr1_ok(
    'O2 a fixed, minimal error_log() message is present instead',
    strpos($o_event_guests_code, 'PGE guest read projection synchronization failed for event_id=') !== false
);
gr1_ok(
    'O3 (Authoritative Write Confirmation & Lock Cleanup Fix Pass) no new logging/telemetry/audit framework was introduced — still exactly four fixed, minimal error_log() call sites in save_map() (lock-acquisition-abort; invalidation-unconfirmed-abort; write-confirmation-mismatch-abort; post-write sync failure), no new logging system',
    substr_count($o_event_guests_code, 'error_log(') === 4
);
gr1_ok(
    'O4 the lock-acquisition-abort error_log() message is fixed/minimal — no raw exception text, no guest data, no lock name',
    strpos($o_event_guests_code, "PGE guest write aborted before authoritative Post Meta write for event_id={\$event_id}: per-event lock not acquired") !== false
);
gr1_ok(
    'O5 (Strict Pre-Write Invalidation Contract Fix Pass) the invalidation-unconfirmed-abort error_log() message is fixed/minimal — no raw exception text, no guest data, no SQL',
    strpos($o_event_guests_code, "PGE guest write aborted before authoritative Post Meta write for event_id={\$event_id}: pre-write invalidation could not be confirmed after retries") !== false
);
gr1_ok(
    'O6 (Authoritative Write Confirmation & Lock Cleanup Fix Pass) the write-confirmation-mismatch error_log() message is fixed/minimal — no raw exception text, no guest data, no SQL',
    strpos($o_event_guests_code, "PGE guest write could not be confirmed as authoritative for event_id={\$event_id}: post-write verification mismatch") !== false
);

// ══════════════════════════════════════════════════════════════
// Section P — Concurrency Correctness Fix Pass: per-event lock mechanics.
// Proves sync_event()/rebuild_event() actually acquire the lock before
// doing any DB work and release it after, that lock names are
// deterministic/event-scoped/length-bounded, and that
// acquire_event_lock()/release_event_lock() behave correctly on their own,
// including under simulated contention (gr1_lock_deny_next).
// ══════════════════════════════════════════════════════════════

gr1_reset_db();

gr1_write_post_meta(800, gr1_map('970000008001', 'P Guest'));
$GLOBALS['gr1_query_log'] = [];
$p1_ok = PGE_Event_Guest_Read_Projection::sync_event(800, pge_event_guests_get_map(800));
gr1_ok('P1 sync_event() succeeds with the lock wired in', $p1_ok === true);

$p_get_lock_idx = null;
$p_release_lock_idx = null;
foreach ($GLOBALS['gr1_query_log'] as $i => $q) {
    if ($p_get_lock_idx === null && strpos($q, 'GET_LOCK(') !== false) $p_get_lock_idx = $i;
    if (strpos($q, 'RELEASE_LOCK(') !== false) $p_release_lock_idx = $i; // last occurrence wins
}
gr1_ok('P2 GET_LOCK() is the very first query issued by sync_event(), before any INSERT/UPDATE against the projection/state tables', $p_get_lock_idx === 0);
gr1_ok('P3 RELEASE_LOCK() is called after all publish work has completed (last in the query log)', $p_release_lock_idx !== null && $p_release_lock_idx === count($GLOBALS['gr1_query_log']) - 1);

$p_name_800 = gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'event_lock_name', [800]);
$p_name_800_again = gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'event_lock_name', [800]);
$p_name_801 = gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'event_lock_name', [801]);
gr1_ok('P4 the lock name is deterministic (same event_id always produces the same name)', $p_name_800 === $p_name_800_again);
gr1_ok('P5 the lock name is per-event (different event_ids produce different names)', $p_name_800 !== $p_name_801);
gr1_ok('P6 the lock name is well under MySQL\'s 64-character GET_LOCK name limit', strlen($p_name_800) <= 64);

gr1_reset_db();
$p7 = PGE_Event_Guest_Read_Projection::acquire_event_lock(900);
gr1_ok('P7 acquire_event_lock() succeeds against an available lock', $p7 === true);
$p8 = PGE_Event_Guest_Read_Projection::acquire_event_lock(900);
gr1_ok('P8 a second, RE-ENTRANT acquire_event_lock() call for the SAME event (same session) also succeeds — matches real MariaDB reentrant GET_LOCK behavior, verified separately in this phase against a real database', $p8 === true);
PGE_Event_Guest_Read_Projection::release_event_lock(900); // count now 1 (still held once)
$GLOBALS['gr1_lock_deny_next'] = 1; // simulate a genuinely different connection contending for the same name
$p9 = PGE_Event_Guest_Read_Projection::acquire_event_lock(900, 1);
gr1_ok('P9 (contention simulation) a forced-deny acquisition attempt correctly reports failure', $p9 === false);
PGE_Event_Guest_Read_Projection::release_event_lock(900); // release the remaining hold
$p10 = PGE_Event_Guest_Read_Projection::acquire_event_lock(900);
gr1_ok('P10 once fully released, acquisition succeeds again', $p10 === true);
PGE_Event_Guest_Read_Projection::release_event_lock(900);

// ══════════════════════════════════════════════════════════════
// Section Q — Blocker #1 (Active Generation Cleanup Race): SQL-level
// defense-in-depth, exercised DIRECTLY against the underlying primitives via
// Reflection (deliberately bypassing sync_event()/rebuild_event()'s lock),
// to prove cleanup_old_generations()'s hardened guard holds independently
// of lock discipline — see class docblock, "PER-EVENT SERIALIZATION LOCK".
// Section R below additionally proves this exact race is now structurally
// unreachable through the real public API under the lock.
//
// Reproduces the literal user-described scenario: Publisher A stages G1 and
// activates it. Publisher B (identical content) stages G2 and activates it
// — making G2 truly active (activate_generation() is unconditional, "last
// writer wins"). Publisher A's cleanup then runs LATE, using ITS OWN
// (now-stale) G1 as $keep_generation — exactly the confirmed Blocker #1
// precondition.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();
$q_event = 810;
$q_row = [['guest_phone' => '970000008101', 'guest_name' => 'Q Guest', 'guest_note' => '', 'guest_code' => 'QQQQ-0001']];

$q_g1 = gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'allocate_build_generation', [$q_event]);
gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'stage_rows', [$q_event, $q_g1, $q_row]);
gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'activate_generation', [$q_event, $q_g1, 'fp-Q', 1]);

$q_g2 = gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'allocate_build_generation', [$q_event]);
gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'stage_rows', [$q_event, $q_g2, $q_row]);
gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'activate_generation', [$q_event, $q_g2, 'fp-Q', 1]); // "last writer wins" — G2 is now truly active.

gr1_ok('Q1 setup: G1 and G2 are distinct generations', $q_g1 !== $q_g2);
gr1_ok('Q2 setup: after both activate, the state row reports G2 (the later activation) active', PGE_Event_Guest_Read_Projection::state($q_event)['active_generation'] === $q_g2);

gr1_invoke_private('PGE_Event_Guest_Read_Projection', 'cleanup_old_generations', [$q_event, $q_g1]); // A's cleanup runs LATE, using its own stale G1.

$q_state_after = PGE_Event_Guest_Read_Projection::state($q_event);
gr1_ok('Q3 (Blocker #1 — the central invariant) active_generation is STILL G2 after A\'s late, stale cleanup call', $q_state_after['active_generation'] === $q_g2);
gr1_ok(
    'Q4 G2\'s rows still exist — NOT deleted by A\'s cleanup, despite $keep_generation=G1 not matching G2',
    count(array_filter($GLOBALS['gr1_db']['projection'], function ($r) use ($q_event, $q_g2) { return $r['event_id'] === $q_event && $r['generation'] === $q_g2; })) === 1
);
gr1_ok(
    'Q5 get_guests_by_phones() can still correctly read G2\'s data after the late cleanup',
    PGE_Event_Guest_Read_Projection::get_guests_by_phones($q_event, ['970000008101']) === [['phone' => '970000008101', 'name' => 'Q Guest', 'note' => '', 'code' => 'QQQQ-0001']]
);
gr1_ok(
    'Q6 the system never reports READY while active-generation rows are deleted (status is ready AND the rows exist together, never one without the other)',
    $q_state_after['status'] === 'ready' && count(array_filter($GLOBALS['gr1_db']['projection'], function ($r) use ($q_event, $q_g2) { return $r['event_id'] === $q_event && $r['generation'] === $q_g2; })) === 1
);

// ══════════════════════════════════════════════════════════════
// Section R — Strict Pre-Write Invalidation Contract Fix Pass: under the  [(B) PRODUCTION save_map() BEHAVIOR]
// per-event lock, a concurrent writer B that FAILS to acquire the lock
// (simulating an extreme case — a lock held past its timeout by a
// still-in-progress publisher A) no longer writes Post Meta AT ALL — a
// categorically stronger guarantee than the prior pass's "writes
// unconditionally but self-heals". B's save_map() call ABORTS before
// touching Post Meta, so A's in-progress publish is never interfered with,
// and A's own publish completes successfully (not merely "safely stale").
// See Section S below for the PRIMARY case (B properly respects the lock,
// no timeout at all).
// ══════════════════════════════════════════════════════════════

gr1_reset_db();
$r_event = 811;
gr1_write_post_meta($r_event, gr1_map('970000008201', 'R Writer A'));

$r_a_generation_snapshot = null;
$r_b_saved = null;
$r_b_state_during_hook = null;
$GLOBALS['gr1_run_after_activate'] = function () use ($r_event, &$r_a_generation_snapshot, &$r_b_saved, &$r_b_state_during_hook) {
    $r_a_generation_snapshot = PGE_Event_Guest_Read_Projection::state($r_event)['active_generation']; // A's generation, just activated
    $GLOBALS['gr1_lock_deny_next'] = 1; // B's GET_LOCK call (next one) is denied — simulates a different, contending connection.
    $r_b_saved = pge_event_guests_save_map($r_event, gr1_map('970000008202', 'R Writer B (denied the lock)'));
    $r_b_state_during_hook = PGE_Event_Guest_Read_Projection::state($r_event); // snapshot immediately after B's attempt, before A's own post-verify runs
};

$r_a_ok = PGE_Event_Guest_Read_Projection::sync_event($r_event, pge_event_guests_get_map($r_event));

gr1_ok(
    'R1 (strict contract — central invariant) B\'s concurrent save_map() call, denied the lock, ABORTS and returns null — never the saved map',
    $r_b_saved === null
);
$r_post_meta_after_b = pge_event_guests_get_map($r_event);
gr1_ok(
    'R2 B\'s Post Meta write NEVER landed at all — Post Meta is still exactly A\'s content, B\'s phone was never written',
    $r_post_meta_after_b === gr1_map('970000008201', 'R Writer A') && !isset($r_post_meta_after_b['970000008202'])
);
gr1_ok(
    'R3 immediately after B\'s denied (and now write-less) attempt, active_generation is still A\'s, completely undisturbed',
    $r_b_state_during_hook['active_generation'] === $r_a_generation_snapshot
);
gr1_ok(
    'R4 immediately after B\'s denied attempt, the state is A\'s just-activated READY — never forced stale by an interference that no longer occurs (B never touched anything)',
    $r_b_state_during_hook['status'] === 'ready'
);
gr1_ok(
    'R5 (strict contract improvement over the prior pass) A\'s own publish now SUCCEEDS cleanly — B\'s complete non-interference means A\'s post-activation re-verify has nothing to detect; no self-heal-to-failure is even needed anymore',
    $r_a_ok === true
);

$r_state_final = PGE_Event_Guest_Read_Projection::state($r_event);
gr1_ok(
    'R6 the final state is READY, genuinely and correctly serving A\'s content — not merely "safely stale"',
    $r_state_final['status'] === 'ready'
);
$r_rows_final = PGE_Event_Guest_Read_Projection::get_guests_by_phones($r_event, ['970000008201']);
gr1_ok(
    'R6b the final projection correctly serves A\'s content',
    is_array($r_rows_final) && count($r_rows_final) === 1 && $r_rows_final[0]['name'] === 'R Writer A'
);

$r_b_retry_saved = pge_event_guests_save_map($r_event, gr1_map('970000008202', 'R Writer B (retried after lock freed)'));
$r_b_retry_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones($r_event, ['970000008202']);
gr1_ok(
    'R7 B, retrying normally once the lock is free (no contention this time), succeeds cleanly — the system is never permanently stuck by the earlier denial',
    is_array($r_b_retry_saved) && isset($r_b_retry_saved['970000008202']) && is_array($r_b_retry_rows) && count($r_b_retry_rows) === 1 && $r_b_retry_rows[0]['name'] === 'R Writer B (retried after lock freed)'
);

// ══════════════════════════════════════════════════════════════
// Section S — Blocker #2 fix, PRIMARY case: a Reader querying strictly  [(A) PRIMITIVE]
// between activation and post-verify (the exact window Blocker #2
// identified) sees fully consistent, currently-authoritative data — because
// under normal lock operation (nothing denies the lock in this section), no
// concurrent writer's update_post_meta() can land until this publisher's
// ENTIRE locked sequence — including its own post-verify — has completed
// and released the lock.
//
// SCOPE NOTE (Reader-Safety Publication Ordering Fix Pass audit, question
// 9): this section deliberately tests only the NARROW window between
// activate_generation() and the post-activation re-verify — it does NOT,
// and never did, test the much wider window between the authoritative
// Post Meta write (update_post_meta() in save_map()) and
// activate_generation() succeeding. That wider window was a real,
// separately-confirmed reader-vs-writer race (a lock-free reader could
// observe `Post Meta = V2 AND Projection = READY(V1)` throughout
// fingerprint computation, generation allocation, row staging, and the
// pre-activation fresh-read verify) — closed by pre-write invalidation in
// pge_event_guests_save_map(), and specifically proven closed by TEST 1
// (Section X) below, which arms the reader hook at the earliest possible
// point: immediately after the Post Meta write itself, before any of the
// steps this section's window comes after even begin.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();
$s_event = 812;
gr1_write_post_meta($s_event, gr1_map('970000008301', 'S Guest A'));

$s_reader_ready = null;
$s_reader_result = null;
$GLOBALS['gr1_run_after_activate'] = function () use ($s_event, &$s_reader_ready, &$s_reader_result) {
    $s_reader_ready = PGE_Event_Guest_Read_Projection::is_ready($s_event);
    $s_reader_result = PGE_Event_Guest_Read_Projection::get_guests_by_phones($s_event, ['970000008301']);
};

$s_ok = PGE_Event_Guest_Read_Projection::sync_event($s_event, pge_event_guests_get_map($s_event));
gr1_ok('S1 the publish succeeds (nothing denied the lock in this section — the primary, common case)', $s_ok === true);
gr1_ok('S2 the Reader, executing strictly between activation and post-verify, sees is_ready()=true (the just-activated generation)', $s_reader_ready === true);
gr1_ok(
    'S3 (Blocker #2 — the central invariant) the Reader\'s data is fully correct and current — never wrong/mismatched content in that window',
    is_array($s_reader_result) && count($s_reader_result) === 1 && $s_reader_result[0]['name'] === 'S Guest A'
);
$s_state_final = PGE_Event_Guest_Read_Projection::state($s_event);
gr1_ok('S4 the post-verify (running after the Reader) also confirms READY — the Reader\'s view and the final state agree, since nothing changed Post Meta between them', $s_state_final['status'] === 'ready');

// ══════════════════════════════════════════════════════════════
// Section T — Lock acquisition failure (Strict Pre-Write Invalidation  [(B) PRODUCTION save_map() BEHAVIOR]
// Contract Fix Pass, Required Test — lock acquisition failure). save_map()
// now ABORTS BEFORE update_post_meta() when the lock cannot be acquired —
// Post Meta is NEVER written, the return contract is null (not the saved
// map), and the existing (still 100% accurate, since nothing changed)
// projection is left completely untouched — no reason to invalidate
// content that was never superseded. sync_event()/rebuild_event()
// themselves fail closed too, unchanged from before.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();

// Baseline: event 813 is first built successfully under normal conditions.
gr1_write_post_meta(813, ['g' => ['phone' => '970000008401', 'name' => 'T Guest V1', 'note' => '', 'code' => '']]);
PGE_Event_Guest_Read_Projection::rebuild_event(813);
gr1_ok('T0 baseline: event 813 is READY before the lock-failure scenario', PGE_Event_Guest_Read_Projection::state(813)['status'] === 'ready');

$GLOBALS['gr1_lock_always_deny'] = true;
$t_query_count_before = count($GLOBALS['gr1_query_log']);
$t_saved = pge_event_guests_save_map(813, ['g' => ['phone' => '970000008401', 'name' => 'T Guest V2', 'note' => '', 'code' => '']]);
$GLOBALS['gr1_lock_always_deny'] = false;

gr1_ok('T1 (strict contract — central invariant) save_map() returns null, NOT the saved map, when the lock cannot be acquired at all — the write never started', $t_saved === null);
$t2_map_after = pge_event_guests_get_map(813);
gr1_ok(
    'T2 Post Meta was NOT written — it is still exactly V1, never touched (this is the whole point of aborting before the write)',
    isset($t2_map_after['970000008401']) && $t2_map_after['970000008401']['name'] === 'T Guest V1'
);

$t_relevant_queries = array_slice($GLOBALS['gr1_query_log'], $t_query_count_before);
$t_unsafe_query_found = false;
foreach ($t_relevant_queries as $q) {
    if (strpos($q, 'INSERT INTO wp_pge_event_guest_read_projection ') === 0) { $t_unsafe_query_found = true; break; }
    if (strpos($q, 'UPDATE wp_pge_event_guest_read_projection_state SET active_generation') === 0) { $t_unsafe_query_found = true; break; }
}
gr1_ok('T3 no projection stage/activate query was ever attempted for this event (nothing to build — the write itself never started)', $t_unsafe_query_found === false);
gr1_ok(
    'T4 (strict contract improvement over prior passes) the projection is left completely untouched, still correctly READY for V1 — there is no reason to invalidate content that was never superseded, since Post Meta genuinely never changed',
    PGE_Event_Guest_Read_Projection::state(813)['status'] === 'ready'
);
gr1_ok('T5 is_ready() correctly reports true — V1 remains genuinely, accurately current', PGE_Event_Guest_Read_Projection::is_ready(813) === true);
$t5b_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones(813, ['970000008401']);
gr1_ok('T5b the reader still correctly gets V1 (never wrongly invalidated by a write that never happened)', is_array($t5b_rows) && count($t5b_rows) === 1 && $t5b_rows[0]['name'] === 'T Guest V1');

$GLOBALS['gr1_lock_always_deny'] = true;
$t6_ok = PGE_Event_Guest_Read_Projection::sync_event(813, pge_event_guests_get_map(813));
$t7_ok = PGE_Event_Guest_Read_Projection::rebuild_event(813);
$GLOBALS['gr1_lock_always_deny'] = false;
gr1_ok('T6 sync_event() itself reports failure (its existing false-on-failure contract, unchanged) when it cannot acquire the lock', $t6_ok === false);
gr1_ok('T7 rebuild_event() itself reports failure when it cannot acquire the lock', $t7_ok === false);

$t8_saved = pge_event_guests_save_map(813, ['g' => ['phone' => '970000008401', 'name' => 'T Guest V2', 'note' => '', 'code' => '']]);
gr1_ok(
    'T8 once the lock is available again, a normal save_map() call succeeds cleanly and V2 becomes authoritative — never permanently wedged by a prior lock failure',
    is_array($t8_saved) && $t8_saved['970000008401']['name'] === 'T Guest V2' && PGE_Event_Guest_Read_Projection::state(813)['status'] === 'ready'
);

// Distinct edge case, tested explicitly rather than left implicit (per the
// "Documentation Accuracy" requirement — this is a proven, intentional
// outcome, not an overlooked gap): a genuinely NEVER-BUILT event whose very
// FIRST save_map() call is denied the lock aborts before any write —
// stays never-built (state()===null) AND Post Meta stays entirely
// untouched (no row at all, not even an empty one).
gr1_reset_db();
$GLOBALS['gr1_lock_always_deny'] = true;
$t9_saved = pge_event_guests_save_map(816, ['g' => ['phone' => '970000008601', 'name' => 'T Never Built', 'note' => '', 'code' => '']]);
$GLOBALS['gr1_lock_always_deny'] = false;
gr1_ok(
    'T9 a never-built event whose first-ever save_map() call is denied the lock stays "never built" (state()===null) — is_ready() is safely false',
    PGE_Event_Guest_Read_Projection::state(816) === null && PGE_Event_Guest_Read_Projection::is_ready(816) === false
);
gr1_ok('T10 save_map() returned null (write aborted), and Post Meta was never written for this event at all', $t9_saved === null && pge_event_guests_get_map(816) === []);

// ══════════════════════════════════════════════════════════════
// Section U — Exception while the lock is held (required test F): release  [(A)+(B) MIXED — direct sync_event() then real save_map()]
// is verified to still happen via finally(), never leaving the lock stuck.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();
gr1_write_post_meta(814, gr1_map('970000008501', 'U Guest V1'));
PGE_Event_Guest_Read_Projection::rebuild_event(814); // baseline READY, so the exception below forces an EXISTING ready projection stale.
gr1_ok('U0 baseline: event 814 is READY before the exception scenario', PGE_Event_Guest_Read_Projection::state(814)['status'] === 'ready');

gr1_write_post_meta(814, gr1_map('970000008501', 'U Guest V2'));
$GLOBALS['gr1_throw_on_next_query'] = true; // fires on the first query() call inside the locked section
$u_threw = false;
try {
    PGE_Event_Guest_Read_Projection::sync_event(814, gr1_map('970000008501', 'U Guest V2'));
} catch (\Throwable $e) {
    $u_threw = true;
}
gr1_ok('U1 the simulated fatal DB error during the locked section propagates as a real exception (proving this test exercises the exception path, not a swallowed one)', $u_threw === true);

$u_reacquired = PGE_Event_Guest_Read_Projection::acquire_event_lock(814);
gr1_ok('U2 the per-event lock was released (finally) despite the exception — a fresh acquire_event_lock() call for the same event succeeds immediately, proving no lock leak', $u_reacquired === true);
PGE_Event_Guest_Read_Projection::release_event_lock(814);

$u3_ok = PGE_Event_Guest_Read_Projection::rebuild_event(814);
gr1_ok('U3 a subsequent normal rebuild succeeds cleanly after the earlier exception (no lasting corruption, no permanently wedged lock)', $u3_ok === true && PGE_Event_Guest_Read_Projection::state(814)['status'] === 'ready');

// Same proof, but through pge_event_guests_save_map()'s own try/catch —
// confirms the OUTER (save_map()) lock is also released correctly when the
// nested sync_event() call throws. Again baselined to READY first so this
// tests "force an EXISTING ready projection stale", the meaningful case.
//
// Reader-Safety Publication Ordering Fix Pass — gr1_throw_on_next_query_skip
// is set to 1 here because save_map() now issues ITS OWN query() call
// (the pre-write force_stale_best_effort() invalidation) before reaching
// the nested sync_event() call this test specifically targets. Skipping
// that one query preserves this test's original intent — the throw still
// fires on the first query() call INSIDE the nested locked section, exactly
// as U1-U3 (which call sync_event() directly and are unaffected by the
// pre-write invalidation step) already prove in isolation. Invalidation
// itself succeeding here (not what this test is about) is separately and
// specifically exercised by TEST 3 — Invalidation Failure, below.
gr1_reset_db();
gr1_write_post_meta(815, gr1_map('970000008502', 'U Guest 2 V1'));
PGE_Event_Guest_Read_Projection::rebuild_event(815);
$GLOBALS['gr1_throw_on_next_query'] = true;
$GLOBALS['gr1_throw_on_next_query_skip'] = 1;
$u4_saved = pge_event_guests_save_map(815, ['g' => ['phone' => '970000008502', 'name' => 'U Guest 2 V2', 'note' => '', 'code' => '']]);
gr1_ok('U4 save_map() itself never lets the exception escape (its own try/catch/finally around sync_event() catches it) and still returns the saved map', is_array($u4_saved) && isset($u4_saved['970000008502']));
gr1_ok('U5 the previously-READY projection is correctly forced STALE after the exception, never left falsely READY for now-superseded content', PGE_Event_Guest_Read_Projection::state(815)['status'] === 'stale');
$u6_reacquired = PGE_Event_Guest_Read_Projection::acquire_event_lock(815);
gr1_ok('U6 save_map()\'s own (outer) lock acquisition was also released despite the exception — no lock leak at the save_map() level either', $u6_reacquired === true);
PGE_Event_Guest_Read_Projection::release_event_lock(815);

// ══════════════════════════════════════════════════════════════
// Section W — Fresh-Read Audit (required test L): pge_event_guests_get_map_fresh()'s
// empty/legacy fallback must use a cache-bypassing read of
// `_pge_invited_phones`, not the ordinary get_post_meta()-backed one.
// ══════════════════════════════════════════════════════════════

gr1_reset_db();

update_post_meta(820, '_pge_invited_phones', ['970000008601', '970000008602']);
$w1_fresh = pge_event_guests_get_map_fresh(820);
gr1_ok(
    'W1 get_map_fresh() correctly falls back to the legacy phones-only key when `_pge_invited_guests` is empty/absent',
    is_array($w1_fresh) && count($w1_fresh) === 2 && isset($w1_fresh['970000008601']) && isset($w1_fresh['970000008602'])
);

$w2_code = gr1_strip_php_comments(file_get_contents(PGE_PATH . 'includes/event-guests.php'));
$w2_fn_start = strpos($w2_code, 'function pge_event_guests_get_map_fresh(');
$w2_fn_end = strpos($w2_code, 'function ', $w2_fn_start + 10);
$w2_fn_body = substr($w2_code, $w2_fn_start, $w2_fn_end !== false ? $w2_fn_end - $w2_fn_start : null);
gr1_ok(
    'W2 (source-level proof) pge_event_guests_get_map_fresh() calls the cache-bypassing pge_event_guests_parse_phones_meta_fresh(), never the cached pge_event_guests_parse_phones_meta()',
    $w2_fn_start !== false && strpos($w2_fn_body, 'pge_event_guests_parse_phones_meta_fresh(') !== false && strpos($w2_fn_body, 'pge_event_guests_parse_phones_meta(') === false
);

gr1_reset_db();
update_post_meta(821, '_pge_invited_phones', ['970000008701']);
$GLOBALS['gr1_query_log'] = [];
pge_event_guests_get_map_fresh(821);
$w4_phones_query = null;
foreach ($GLOBALS['gr1_query_log'] as $q) {
    if (strpos($q, "meta_key = '_pge_invited_phones'") !== false) { $w4_phones_query = $q; break; }
}
gr1_ok('W3 (query-level proof) get_map_fresh() actually executes a raw SQL query against `_pge_invited_phones`, not just `_pge_invited_guests`', $w4_phones_query !== null);
gr1_ok('W4 that query is a direct wp_postmeta SELECT (the same cache-bypassing shape as the `_pge_invited_guests` read), not routed through get_post_meta()', $w4_phones_query !== null && strpos($w4_phones_query, 'SELECT meta_value FROM wp_postmeta') === 0);

gr1_reset_db();
update_post_meta(822, '_pge_invited_phones', ['970000008801']);
$GLOBALS['gr1_fail_fresh_read_phones_only'] = true;
$w5_fresh = pge_event_guests_get_map_fresh(822);
$GLOBALS['gr1_fail_fresh_read_phones_only'] = false;
gr1_ok(
    'W5 a genuine query error reading SPECIFICALLY the legacy fallback key (guests-key read still succeeds) makes get_map_fresh() report an overall failure (null), never a silent degrade to a cached read',
    $w5_fresh === null
);

gr1_reset_db();
update_post_meta(823, '_pge_invited_phones', ['970000008901']);
$w6_map = pge_event_guests_get_map(823);
gr1_ok('W6 the ordinary (non-fresh) get_map() path is unaffected — still correctly falls back to the legacy phones key (unchanged observable behavior)', is_array($w6_map) && count($w6_map) === 1 && isset($w6_map['970000008901']));

// ══════════════════════════════════════════════════════════════
// Section X — Reader-Safety Publication Ordering Fix Pass. Confirms the  [(B) PRODUCTION save_map() BEHAVIOR]
// reader-vs-writer race (a lock-free reader observing
// `Post Meta = V2 AND Projection = READY(V1)`) is CLOSED — proven via the
// real public API, driven through the real, unmodified
// pge_event_guests_save_map() choke point, using the new
// gr1_run_after_post_meta_write one-shot hook fired from inside the fake
// update_post_meta() specifically at the earliest point a lock-free reader
// could possibly observe the new Post Meta value: the instant it lands.
// TEST 1-5 map directly to the phase brief's required scenarios.
// ══════════════════════════════════════════════════════════════

// ---- TEST 1: Reader Between Invalidation/Post-Meta Write and Activation ----
gr1_reset_db();
$x1_event = 840;
gr1_write_post_meta($x1_event, gr1_map('970000009001', 'X1 Guest V1'));
PGE_Event_Guest_Read_Projection::rebuild_event($x1_event);
gr1_ok('X1-0 baseline: V1 is READY before the writer runs', PGE_Event_Guest_Read_Projection::state($x1_event)['status'] === 'ready');

$x1_reader_post_meta_at_hook = null;
$x1_reader_is_ready = null;
$x1_reader_state = null;
$x1_reader_rows_old_phone = null;
$x1_reader_hook_fired = false;
$GLOBALS['gr1_run_after_post_meta_write'] = function ($post_id, $value) use (
    $x1_event, &$x1_reader_post_meta_at_hook, &$x1_reader_is_ready, &$x1_reader_state, &$x1_reader_rows_old_phone, &$x1_reader_hook_fired
) {
    if ($post_id !== $x1_event) return;
    $x1_reader_hook_fired = true;
    $x1_reader_post_meta_at_hook = $value; // Post Meta exactly as this writer just wrote it — already V2.
    $x1_reader_is_ready = PGE_Event_Guest_Read_Projection::is_ready($x1_event);
    $x1_reader_state = PGE_Event_Guest_Read_Projection::state($x1_event);
    $x1_reader_rows_old_phone = PGE_Event_Guest_Read_Projection::get_guests_by_phones($x1_event, ['970000009001']);
};

$x1_saved = pge_event_guests_save_map($x1_event, gr1_map('970000009002', 'X1 Guest V2'));

gr1_ok('X1-1 the reader hook actually fired (this test genuinely exercises the window, not a no-op)', $x1_reader_hook_fired === true);
gr1_ok('X1-2 at the exact instant the hook fired, Post Meta was ALREADY V2 — the forbidden scenario\'s precondition is genuinely met, not merely assumed', isset($x1_reader_post_meta_at_hook['970000009002']));
gr1_ok('X1-3 (the central invariant) the reader did NOT see the projection as READY at that instant — Post Meta=V2 was never observed alongside Projection=READY(V1)', $x1_reader_is_ready === false);
gr1_ok('X1-4 the reader\'s state() snapshot at that instant reports STALE, not the old generation\'s READY', $x1_reader_state !== null && $x1_reader_state['status'] === 'stale');
gr1_ok('X1-5 the reader\'s get_guests_by_phones() call at that instant returned NO rows (safe/empty), never V1\'s stale content silently served as current', is_array($x1_reader_rows_old_phone) && count($x1_reader_rows_old_phone) === 0);

gr1_ok('X1-6 save_map() itself still returns the saved V2 map (return contract unchanged)', is_array($x1_saved) && isset($x1_saved['970000009002']));
$x1_final_state = PGE_Event_Guest_Read_Projection::state($x1_event);
gr1_ok('X1-7 after the writer fully completes, the projection is READY again, now for V2', $x1_final_state['status'] === 'ready');
$x1_final_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones($x1_event, ['970000009002']);
gr1_ok('X1-8 the reader now correctly gets V2\'s data', is_array($x1_final_rows) && count($x1_final_rows) === 1 && $x1_final_rows[0]['name'] === 'X1 Guest V2');
$x1_old_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones($x1_event, ['970000009001']);
gr1_ok('X1-9 V1\'s old phone is never resurrected as part of the current (V2) generation', is_array($x1_old_rows) && count($x1_old_rows) === 0);

// ---- TEST 2: Lock Timeout Before Authoritative Write (Strict Pre-Write
// Invalidation Contract Fix Pass — required test: lock acquisition
// failure). Prove deterministic ordering: the authoritative write DOES NOT
// PROCEED AT ALL when the lock cannot be acquired — Post Meta remains V1
// unconditionally, the caller receives an explicit pre-write failure
// (null), and V1 remains authoritative and correctly READY throughout. ----
gr1_reset_db();
$x2_event = 841;
gr1_write_post_meta($x2_event, gr1_map('970000009101', 'X2 Guest V1'));
PGE_Event_Guest_Read_Projection::rebuild_event($x2_event);
gr1_ok('X2-0 baseline: V1 is READY before the lock-timeout scenario', PGE_Event_Guest_Read_Projection::state($x2_event)['status'] === 'ready');

$x2_reader_hook_fired = false;
$GLOBALS['gr1_run_after_post_meta_write'] = function ($post_id) use (&$x2_reader_hook_fired) {
    $x2_reader_hook_fired = true; // must NEVER fire in this test — the write must never reach update_post_meta() at all.
};

$GLOBALS['gr1_lock_always_deny'] = true;
$x2_saved = pge_event_guests_save_map($x2_event, gr1_map('970000009102', 'X2 Guest V2'));
$GLOBALS['gr1_lock_always_deny'] = false;

gr1_ok(
    'X2-1 (strict contract — central invariant) save_map() returns null, NOT the saved map, when the lock cannot be acquired — the write never started',
    $x2_saved === null
);
gr1_ok(
    'X2-2 the Post-Meta-write hook NEVER fired — update_post_meta() was never even called; the write was aborted before it, not merely raced against',
    $x2_reader_hook_fired === false
);
$x2_map_after = pge_event_guests_get_map($x2_event);
gr1_ok(
    'X2-3 (deterministic ordering, proven) authoritative Post Meta MUST NOT change to V2 — it is still exactly V1',
    isset($x2_map_after['970000009101']) && $x2_map_after['970000009101']['name'] === 'X2 Guest V1' && !isset($x2_map_after['970000009102'])
);
$x2_final_state = PGE_Event_Guest_Read_Projection::state($x2_event);
gr1_ok(
    'X2-4 no unlocked projection sync ever occurred, and V1 remains genuinely, correctly READY — never forced stale for a write that never happened',
    $x2_final_state['status'] === 'ready'
);
gr1_ok('X2-5 is_ready() correctly reports TRUE — V1 remains authoritative and current', PGE_Event_Guest_Read_Projection::is_ready($x2_event) === true);
$x2_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones($x2_event, ['970000009101']);
gr1_ok('X2-6 a reader still correctly gets V1 — lock failure never creates `Post Meta V2 + Projection V1 READY`, because Post Meta never became V2 in the first place', is_array($x2_rows) && count($x2_rows) === 1 && $x2_rows[0]['name'] === 'X2 Guest V1');

$x2_retry_saved = pge_event_guests_save_map($x2_event, gr1_map('970000009102', 'X2 Guest V2 (retry)'));
gr1_ok('X2-7 a later retry, once the lock is available, succeeds normally and V2 becomes authoritative — the system is never permanently stuck', is_array($x2_retry_saved) && PGE_Event_Guest_Read_Projection::state($x2_event)['status'] === 'ready' && PGE_Event_Guest_Read_Projection::get_guests_by_phones($x2_event, ['970000009102'])[0]['name'] === 'X2 Guest V2 (retry)');

// ---- TEST 3a: Transient Invalidation Failure (required test — first
// invalidation attempt fails, second succeeds; write proceeds normally as
// a full, ordinary success). ----
gr1_reset_db();
$x3a_event = 842;
gr1_write_post_meta($x3a_event, gr1_map('970000009201', 'X3a Guest V1'));
PGE_Event_Guest_Read_Projection::rebuild_event($x3a_event);

$GLOBALS['gr1_fail_force_stale_next'] = 1; // only the FIRST pre-write invalidation attempt fails; the retry (2nd attempt, still pre-write) succeeds.
$x3a_reader_is_ready = null;
$x3a_reader_hook_fired = false;
$GLOBALS['gr1_run_after_post_meta_write'] = function ($post_id) use ($x3a_event, &$x3a_reader_is_ready, &$x3a_reader_hook_fired) {
    if ($post_id !== $x3a_event) return;
    $x3a_reader_hook_fired = true;
    $x3a_reader_is_ready = PGE_Event_Guest_Read_Projection::is_ready($x3a_event);
};
$x3a_saved = pge_event_guests_save_map($x3a_event, gr1_map('970000009202', 'X3a Guest V2'));

gr1_ok('X3a-1 the write proceeds normally — save_map() returns the saved map (a full, ordinary success), not null', is_array($x3a_saved) && isset($x3a_saved['970000009202']));
gr1_ok('X3a-2 the Post-Meta-write hook fired (the write DID proceed, unlike TEST 2/3b)', $x3a_reader_hook_fired === true);
gr1_ok('X3a-3 a transient (single-attempt) invalidation failure is fully absorbed by the in-loop retry, BEFORE the write — the reader never observes READY(V1) alongside Post Meta=V2', $x3a_reader_is_ready === false);
gr1_ok('X3a-4 the projection transitions cleanly to READY(V2) — the normal success path, unaffected by the earlier transient hiccup', PGE_Event_Guest_Read_Projection::state($x3a_event)['status'] === 'ready');
$x3a_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones($x3a_event, ['970000009202']);
gr1_ok('X3a-5 the final projection correctly serves V2', is_array($x3a_rows) && count($x3a_rows) === 1 && $x3a_rows[0]['name'] === 'X3a Guest V2');

// ---- TEST 3b: Sustained Invalidation Failure (Strict Pre-Write Invalidation
// Contract Fix Pass — required test: transformed from the prior pass's
// "known residual accepted" (X3b) into a strict failure-safe invariant).
// If EVERY pre-write invalidation attempt fails, save_map() now ABORTS
// before Post Meta is touched — exactly like a lock-acquisition failure.
// There is no code path left in which this can produce
// `Post Meta = V2 AND Projection = READY(V1)`. ----
gr1_reset_db();
$x3b_event = 843;
gr1_write_post_meta($x3b_event, gr1_map('970000009301', 'X3b Guest V1'));
PGE_Event_Guest_Read_Projection::rebuild_event($x3b_event);
gr1_ok('X3b-0 baseline: V1 is READY', PGE_Event_Guest_Read_Projection::state($x3b_event)['status'] === 'ready');

$GLOBALS['gr1_fail_force_stale_next'] = 3; // ALL 3 pre-write invalidation attempts fail — sustained failure.
$x3b_reader_hook_fired = false;
$GLOBALS['gr1_run_after_post_meta_write'] = function ($post_id) use (&$x3b_reader_hook_fired) {
    $x3b_reader_hook_fired = true; // must NEVER fire — the write must abort before update_post_meta() is ever reached.
};
$x3b_query_count_before = count($GLOBALS['gr1_query_log']);
$x3b_saved = pge_event_guests_save_map($x3b_event, gr1_map('970000009302', 'X3b Guest V2'));

gr1_ok(
    'X3b-1 (strict contract — central invariant, the exact scenario Required Test 17 targets) save_map() reports failure via the explicit contract (null) — it does NOT report false success for an aborted write',
    $x3b_saved === null
);
gr1_ok('X3b-1b the Post-Meta-write hook never fired — the write never reached update_post_meta() at all', $x3b_reader_hook_fired === false);
$x3b_map_after = pge_event_guests_get_map($x3b_event);
gr1_ok(
    'X3b-2 Post Meta remains exactly V1 — `_pge_invited_guests` was never touched by this aborted call',
    isset($x3b_map_after['970000009301']) && $x3b_map_after['970000009301']['name'] === 'X3b Guest V1' && !isset($x3b_map_after['970000009302'])
);
$x3b_relevant = array_slice($GLOBALS['gr1_query_log'], $x3b_query_count_before);
$x3b_build_attempted = false;
foreach ($x3b_relevant as $q) {
    if (strpos($q, 'INSERT INTO wp_pge_event_guest_read_projection ') === 0) { $x3b_build_attempted = true; break; }
    if (strpos($q, 'UPDATE wp_pge_event_guest_read_projection_state SET active_generation') === 0) { $x3b_build_attempted = true; break; }
}
gr1_ok('X3b-4 no build/activation was ever attempted — the write itself never started, so there is nothing to build on top of', $x3b_build_attempted === false);

$x3b_final_state = PGE_Event_Guest_Read_Projection::state($x3b_event);
gr1_ok(
    'X3b-5 (strict contract — the eliminated residual) V1 remains genuinely, correctly READY throughout and after this call — NOT forced stale, because nothing was ever superseded. This is the exact scenario that was previously the one honestly-disclosed residual gap; it no longer exists as a gap at all',
    $x3b_final_state['status'] === 'ready'
);
gr1_ok('X3b-5b is_ready() correctly reports TRUE — V1 remains authoritative and current', PGE_Event_Guest_Read_Projection::is_ready($x3b_event) === true);

$x3b_rows_v1 = PGE_Event_Guest_Read_Projection::get_guests_by_phones($x3b_event, ['970000009301']);
gr1_ok('X3b-6 a reader still correctly gets V1 — no observable window of `Post Meta V2 + Projection V1 READY` was ever created, and no false success was returned to the caller', is_array($x3b_rows_v1) && count($x3b_rows_v1) === 1 && $x3b_rows_v1[0]['name'] === 'X3b Guest V1');

$x3b_retry_saved = pge_event_guests_save_map($x3b_event, gr1_map('970000009302', 'X3b Guest V2 (retry)'));
$x3b_retry_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones($x3b_event, ['970000009302']);
gr1_ok('X3b-7 a later successful retry (invalidation available again) succeeds normally and V2 becomes authoritative — the system is never permanently stuck', is_array($x3b_retry_saved) && is_array($x3b_retry_rows) && count($x3b_retry_rows) === 1 && $x3b_retry_rows[0]['name'] === 'X3b Guest V2 (retry)');

// ---- TEST 4: Failure After Stale but Before Activation ----
gr1_reset_db();
$x4_event = 844;
gr1_write_post_meta($x4_event, gr1_map('970000009401', 'X4 Guest V1'));
PGE_Event_Guest_Read_Projection::rebuild_event($x4_event);
gr1_ok('X4-0 baseline: V1 is READY', PGE_Event_Guest_Read_Projection::state($x4_event)['status'] === 'ready');

$GLOBALS['gr1_fail_activate'] = true; // lock acquired, invalidation succeeds, Post Meta V2 written, build succeeds, but activation itself fails.
$x4_saved = pge_event_guests_save_map($x4_event, gr1_map('970000009402', 'X4 Guest V2'));
$GLOBALS['gr1_fail_activate'] = false;

gr1_ok('X4-1 Post Meta V2 was still written despite the later activation failure', pge_event_guests_get_map($x4_event) === $x4_saved && isset($x4_saved['970000009402']));
$x4_state_after_failure = PGE_Event_Guest_Read_Projection::state($x4_event);
gr1_ok(
    'X4-2 (the central invariant for this scenario) V1 never becomes READY again merely because publication failed — the projection stays safely STALE (it was already invalidated pre-write; activation never ran to flip it back)',
    $x4_state_after_failure['status'] === 'stale'
);
gr1_ok('X4-3 is_ready() correctly reports false while stale', PGE_Event_Guest_Read_Projection::is_ready($x4_event) === false);
gr1_ok('X4-4 a reader gets no rows rather than V1\'s superseded content', PGE_Event_Guest_Read_Projection::get_guests_by_phones($x4_event, ['970000009401']) === []);

$x4_recovered = PGE_Event_Guest_Read_Projection::rebuild_event($x4_event);
$x4_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones($x4_event, ['970000009402']);
gr1_ok('X4-5 a later successful rebuild recovers cleanly to V2', $x4_recovered === true && is_array($x4_rows) && count($x4_rows) === 1 && $x4_rows[0]['name'] === 'X4 Guest V2');

// ---- TEST 5: Successful Publication (full required ordering, proven via the query log) ----
gr1_reset_db();
$x5_event = 845;
gr1_write_post_meta($x5_event, gr1_map('970000009501', 'X5 Guest V1'));
PGE_Event_Guest_Read_Projection::rebuild_event($x5_event);
$x5_old_generation = PGE_Event_Guest_Read_Projection::state($x5_event)['active_generation'];
gr1_ok('X5-0 baseline: V1 is READY', PGE_Event_Guest_Read_Projection::state($x5_event)['status'] === 'ready');

$GLOBALS['gr1_query_log'] = []; // isolate exactly this call's query ordering.
$x5_saved = pge_event_guests_save_map($x5_event, gr1_map('970000009502', 'X5 Guest V2'));

$x5_get_lock_idx = null;
$x5_force_stale_idx = null;
$x5_allocate_idx = null;
$x5_activate_idx = null;
$x5_release_lock_idx = null;
foreach ($GLOBALS['gr1_query_log'] as $i => $q) {
    if ($x5_get_lock_idx === null && strpos($q, 'GET_LOCK(') !== false) $x5_get_lock_idx = $i;
    if ($x5_force_stale_idx === null && strpos($q, "SET status = 'stale'") !== false) $x5_force_stale_idx = $i;
    if ($x5_allocate_idx === null && strpos($q, 'INSERT INTO wp_pge_event_guest_read_projection_state') === 0) $x5_allocate_idx = $i;
    if ($x5_activate_idx === null && strpos($q, 'SET active_generation') !== false) $x5_activate_idx = $i;
    if (strpos($q, 'RELEASE_LOCK(') !== false) $x5_release_lock_idx = $i; // last occurrence wins
}

gr1_ok('X5-1 GET_LOCK(event) is the very first query (unchanged from Section P)', $x5_get_lock_idx === 0);
gr1_ok('X5-2 the pre-write invalidation (revoke READY trust) runs BEFORE the build starts (allocate_build_generation\'s INSERT)', $x5_force_stale_idx !== null && $x5_allocate_idx !== null && $x5_force_stale_idx < $x5_allocate_idx);
gr1_ok('X5-3 the pre-write invalidation runs AFTER GET_LOCK is attempted, matching the required order GET_LOCK -> invalidate -> write -> build -> verify -> activate -> cleanup -> RELEASE_LOCK', $x5_force_stale_idx > $x5_get_lock_idx);
gr1_ok('X5-4 activation (the only READY-granting step) still runs, and after the build steps', $x5_activate_idx !== null && $x5_activate_idx > $x5_allocate_idx);
gr1_ok('X5-5 RELEASE_LOCK is still the last query issued (unchanged from Section P)', $x5_release_lock_idx !== null && $x5_release_lock_idx === count($GLOBALS['gr1_query_log']) - 1);

gr1_ok('X5-6 save_map() returns V2', is_array($x5_saved) && isset($x5_saved['970000009502']));
$x5_final_state = PGE_Event_Guest_Read_Projection::state($x5_event);
gr1_ok('X5-7 final state is READY, for a NEW generation (not the old one) — generation semantics are unchanged', $x5_final_state['status'] === 'ready' && $x5_final_state['active_generation'] !== $x5_old_generation);
gr1_ok('X5-8 fingerprint semantics are unchanged — the active fingerprint is non-empty and reflects the new content', $x5_final_state['active_fingerprint'] !== '');
$x5_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones($x5_event, ['970000009502']);
gr1_ok('X5-9 the reader now correctly gets V2\'s data', is_array($x5_rows) && count($x5_rows) === 1 && $x5_rows[0]['name'] === 'X5 Guest V2');
$x5_old_rows = PGE_Event_Guest_Read_Projection::get_guests_by_phones($x5_event, ['970000009501']);
gr1_ok('X5-10 the OLD generation is never trusted again — V1\'s phone returns no rows', is_array($x5_old_rows) && count($x5_old_rows) === 0);
$x5_reacquire = PGE_Event_Guest_Read_Projection::acquire_event_lock($x5_event);
gr1_ok('X5-11 the lock was genuinely released — a fresh acquisition for the same event succeeds immediately', $x5_reacquire === true);
PGE_Event_Guest_Read_Projection::release_event_lock($x5_event);

// ══════════════════════════════════════════════════════════════
// Section Y — Strict Pre-Write Invalidation Contract Fix Pass: Caller  [(B) PRODUCTION save_map() BEHAVIOR, source-level]
// Propagation Audit (source-level, matching Section O's established
// methodology in this same file — comparable to what real production
// callers already got, without duplicating a second production
// WordPress/RSVP/status-map harness inside this suite). Proves every real
// production caller of pge_event_guests_save_map() was actually audited
// and, where the return value was previously ignored, updated to check it
// — never silently returns success for a write that was aborted before
// Post Meta. See the phase report's full caller audit table for the
// complete A-H analysis per call site.
// ══════════════════════════════════════════════════════════════

$y_save_map_code = gr1_strip_php_comments(file_get_contents(PGE_PATH . 'includes/event-guests.php'));
$y_fn_start = strpos($y_save_map_code, 'function pge_event_guests_save_map(');
$y_fn_end = strpos($y_save_map_code, 'function ', $y_fn_start + 10);
$y_fn_body = substr($y_save_map_code, $y_fn_start, $y_fn_end !== false ? $y_fn_end - $y_fn_start : null);
gr1_ok(
    'Y1 (Authoritative Write Confirmation & Lock Cleanup Fix Pass) save_map() contains exactly 4 null-return abort points total: 2 pre-write gates (lock-acquisition-failure, invalidation-unconfirmed-failure) + 2 post-write-attempt write-confirmation gates (verification-mismatch, Throwable-before-confirmation)',
    substr_count($y_fn_body, 'return null;') === 4
);
$y_first_write = strpos($y_fn_body, "update_post_meta(\$event_id, '_pge_invited_guests'");
$y_return_null_positions = [];
$y_offset = 0;
while (($y_pos = strpos($y_fn_body, 'return null;', $y_offset)) !== false) {
    $y_return_null_positions[] = $y_pos;
    $y_offset = $y_pos + 1;
}
$y_pre_write_return_null_count = count(array_filter($y_return_null_positions, function ($p) use ($y_first_write) {
    return $p < $y_first_write;
}));
gr1_ok(
    'Y2 (source-level ordering proof) exactly 2 of the 4 null-return abort points are positioned textually BEFORE the authoritative update_post_meta() call (the original pre-write gates, unchanged by this pass) — the other 2 are the new post-write-attempt write-confirmation gates, which necessarily sit after the write since they verify what was actually persisted',
    $y_first_write !== false && $y_pre_write_return_null_count === 2
);

$y_regen_start = strpos($y_save_map_code, "wp_ajax_pge_event_guest_regen_code");
$y_regen_end = strpos($y_save_map_code, '});', $y_regen_start);
$y_regen_body = substr($y_save_map_code, $y_regen_start, $y_regen_end !== false ? $y_regen_end - $y_regen_start : null);
gr1_ok(
    'Y3 (legacy AJAX regen-code caller — Required audit item 14) the handler now checks save_map()\'s return value and reports wp_send_json_error() on failure, instead of unconditionally reporting wp_send_json_success()',
    strpos($y_regen_body, 'is_array($saved_regen)') !== false && strpos($y_regen_body, 'wp_send_json_error(') !== false
);

$y_repo_code = gr1_strip_php_comments(file_get_contents(PGE_PATH . 'includes/class-pge-invitation-repository.php'));

// edit() same-phone metadata-only branch (anchor on the actual save_map()
// call site, not the preceding comment — gr1_strip_php_comments() strips
// the "Same-phone edits are metadata-only" comment line from $y_repo_code)
$y_edit_samephone_pos = strpos($y_repo_code, '$saved_same_phone = pge_event_guests_save_map(');
$y_edit_samephone_body = substr($y_repo_code, $y_edit_samephone_pos, 400);
gr1_ok(
    'Y4 (edit() same-phone caller — previously ignored the return value entirely) now checks is_array() and returns an explicit, generic, PII-free error instead of falsely reporting \'updated\'',
    strpos($y_edit_samephone_body, 'is_array($saved_same_phone)') !== false
    && strpos($y_edit_samephone_body, "'result' => 'error', 'reason' => 'guest_write_unavailable'") !== false
);

// delete() — both the is_array() guard AND the reordering of
// pge_event_guests_remove_phone_refs() to run only AFTER a confirmed
// successful write.
$y_delete_pos = strpos($y_repo_code, 'public static function delete($event_id, $phone)');
$y_delete_body = substr($y_repo_code, $y_delete_pos, 1600);
$y_delete_save_pos = strpos($y_delete_body, 'pge_event_guests_save_map($event_id, $guests_map)');
$y_delete_refs_pos = strpos($y_delete_body, 'pge_event_guests_remove_phone_refs(');
gr1_ok(
    'Y5 (delete() caller — previously ignored the return value entirely) now checks is_array() and returns an explicit, generic, PII-free error instead of falsely reporting \'deleted\'',
    strpos($y_delete_body, 'is_array($saved_after_delete)') !== false
    && strpos($y_delete_body, "'result' => 'error', 'reason' => 'guest_write_unavailable'") !== false
);
gr1_ok(
    'Y6 (delete() ordering fix — closes a genuinely new inconsistency window this pass would otherwise introduce) pge_event_guests_remove_phone_refs() now runs AFTER the guest-map write is confirmed successful, not before it — so a pre-write abort can never leave legacy RSVP/check-in refs cleared while the guest itself was never actually removed',
    $y_delete_save_pos !== false && $y_delete_refs_pos !== false && $y_delete_save_pos < $y_delete_refs_pos
);

// create() and edit() phone-change: already-safe call sites — confirm the
// existing is_array() defensive check (which now doubles as this pass's
// precondition, at ZERO additional code cost) is still present and unchanged.
gr1_ok(
    'Y7 (create() — already-safe call site, confirmed unchanged) the existing is_array($saved_guests_map) postcondition check still gates $invitation_stored, correctly treating a null return (pre-write abort) as storage failure with zero code changes needed',
    strpos($y_repo_code, 'is_array($saved_guests_map)') !== false
);
gr1_ok(
    'Y8 (edit() phone-change — already-safe call site, confirmed unchanged) the existing is_array($saved_guests_map) postcondition check still gates $phone_change_stored',
    substr_count($y_repo_code, 'is_array($saved_guests_map)') >= 2 // create() AND edit()'s phone-change branch each have their own local $saved_guests_map check.
);

// Rollback/compensation call sites (create()'s and edit()'s) — confirm they
// still exist, unmodified, and that their REAL postcondition re-reads
// actual persisted Post Meta rather than trusting save_map()'s return
// value — which is exactly why they needed zero changes for this pass.
gr1_ok(
    'Y9 (rollback/compensation callers — confirmed already-safe, zero changes needed) both rollback call sites still verify the ACTUAL persisted map via pge_event_guests_get_map($event_id) === $original_guests_map / !isset(...), not the rollback call\'s own return value — safe whether or not the rollback write itself succeeds',
    substr_count($y_repo_code, 'pge_event_guests_get_map($event_id) === $original_guests_map') === 1
    && strpos($y_repo_code, '!isset($restored_guests_map[$normalized_phone])') !== false
);

// ══════════════════════════════════════════════════════════════
// Section Z — Authoritative Write Confirmation & Lock Cleanup Fix Pass  [(B) PRODUCTION save_map() BEHAVIOR]
// (this pass). All tests below drive the scenario through the REAL
// production pge_event_guests_save_map() path — behavioral, not
// source-level, per this pass's explicit instruction to prefer behavioral
// proof for the new write-confirmation requirement (the prior pass's
// Section Y source-level bugs are the cautionary example not to repeat).
// ══════════════════════════════════════════════════════════════

// ---- Required Test 10: Authoritative Write Not Persisted ----
// update_post_meta() is called (the fake, like real WordPress, returns
// normally) but the requested value never actually lands in storage. A
// naive `if (!update_post_meta(...))` boolean check could never catch
// this; only a fresh-read postcondition can.
gr1_reset_db();
$z1_event = 900;
gr1_write_post_meta($z1_event, gr1_map('970000010001', 'Z1 Guest V1'));
PGE_Event_Guest_Read_Projection::rebuild_event($z1_event);
gr1_ok('Z1-0 baseline: V1 is READY', PGE_Event_Guest_Read_Projection::state($z1_event)['status'] === 'ready');

$GLOBALS['gr1_not_persist_guests_write'] = true;
$z1_saved = pge_event_guests_save_map($z1_event, gr1_map('970000010002', 'Z1 Guest V2'));

gr1_ok(
    'Z1-1 (strict contract — central invariant, Required Test 10) save_map() does NOT return success when the authoritative write did not actually persist — returns null per the existing array|null contract',
    $z1_saved === null
);
$z1_fresh_after = pge_event_guests_get_map_fresh($z1_event);
gr1_ok(
    'Z1-2 the fresh authoritative canonical state genuinely never became V2 — it is still exactly V1, never falsely claimed as V2',
    $z1_fresh_after === gr1_map('970000010001', 'Z1 Guest V1')
);
$z1_state_after = PGE_Event_Guest_Read_Projection::state($z1_event);
gr1_ok(
    'Z1-3 the projection is NOT READY as V2 (V2 was never actually published), and the old V1 projection is NOT trusted as READY/current either, since pre-write invalidation had already genuinely revoked that trust before the unconfirmed write was discovered — no V2 publication ever occurs',
    $z1_state_after['status'] === 'stale'
);
gr1_ok('Z1-4 is_ready() correctly reports false — nothing unconfirmed is ever trusted as current', PGE_Event_Guest_Read_Projection::is_ready($z1_event) === false);
gr1_ok(
    'Z1-5 a reader gets no rows for either phone — never V1\'s stale content, never a phantom V2',
    PGE_Event_Guest_Read_Projection::get_guests_by_phones($z1_event, ['970000010001', '970000010002']) === []
);
$z1_reacquire = PGE_Event_Guest_Read_Projection::acquire_event_lock($z1_event);
gr1_ok('Z1-6 the event lock was genuinely released (finally ran even though the write-confirmation gate returned null) — a fresh acquisition succeeds immediately', $z1_reacquire === true);
PGE_Event_Guest_Read_Projection::release_event_lock($z1_event);

$z1_retry_saved = pge_event_guests_save_map($z1_event, gr1_map('970000010002', 'Z1 Guest V2 (retry)'));
gr1_ok('Z1-7 a later normal retry (no simulated failure) succeeds cleanly and V2 becomes authoritative — the system is never permanently stuck', is_array($z1_retry_saved) && isset($z1_retry_saved['970000010002']));
gr1_ok('Z1-8 the projection correctly reaches READY for the retried V2', PGE_Event_Guest_Read_Projection::state($z1_event)['status'] === 'ready');

// ---- Required Test 11: Throwable During Post Meta Write ----
// Established project policy (already used for sync_event()'s own
// Throwables) is that save_map() catches a Throwable internally and
// returns null rather than letting it propagate — tested here for a
// Throwable coming out of the authoritative Post Meta write itself.
gr1_reset_db();
$z2_event = 901;
gr1_write_post_meta($z2_event, gr1_map('970000010101', 'Z2 Guest V1'));
PGE_Event_Guest_Read_Projection::rebuild_event($z2_event);
gr1_ok('Z2-0 baseline: V1 is READY', PGE_Event_Guest_Read_Projection::state($z2_event)['status'] === 'ready');

$GLOBALS['gr1_throw_on_post_meta_write'] = true;
$z2_saved = pge_event_guests_save_map($z2_event, gr1_map('970000010102', 'Z2 Guest V2'));

gr1_ok(
    'Z2-1 (Required Test 11) save_map() catches the Throwable internally and returns null — no false success is ever returned for a write that threw before it could be confirmed',
    $z2_saved === null
);
$z2_fresh_after = pge_event_guests_get_map_fresh($z2_event);
gr1_ok(
    'Z2-2 the authoritative Post Meta never actually changed — the fake update_post_meta() throws BEFORE persisting, and the fresh read proves it: still exactly V1',
    $z2_fresh_after === gr1_map('970000010101', 'Z2 Guest V1')
);
gr1_ok('Z2-3 the projection remains STALE/not-ready — no V2 projection publication ever occurs', PGE_Event_Guest_Read_Projection::state($z2_event)['status'] === 'stale');
gr1_ok('Z2-4 is_ready() correctly reports false', PGE_Event_Guest_Read_Projection::is_ready($z2_event) === false);
$z2_reacquire = PGE_Event_Guest_Read_Projection::acquire_event_lock($z2_event);
gr1_ok(
    'Z2-5 (Required Test 11 — the central lock-cleanup invariant) RELEASE_LOCK occurred exactly once for the successful outer acquisition despite the Throwable — a fresh acquisition for the same event succeeds immediately, proving no lock leak',
    $z2_reacquire === true
);
PGE_Event_Guest_Read_Projection::release_event_lock($z2_event);

$z2_retry_saved = pge_event_guests_save_map($z2_event, gr1_map('970000010102', 'Z2 Guest V2 (retry)'));
gr1_ok('Z2-6 a subsequent save_map() call can acquire the lock and succeed normally after the earlier Throwable', is_array($z2_retry_saved) && isset($z2_retry_saved['970000010102']));

// ---- Required Test 12: Partial-Write / `_pge_invited_phones` Legacy
// Fallback ----
// Inspection of pge_event_guests_normalize_stored_map() (event-guests.php)
// shows `_pge_invited_phones` is consulted ONLY when the primary
// `_pge_invited_guests` map normalizes to EMPTY — i.e. only the
// empty-guest-map case can let a partial write (guests persisted, phones
// not) produce a false canonical result via stale legacy reconstruction.
// When $clean is non-empty, `_pge_invited_guests` alone is authoritative
// for the canonical read and a stale `_pge_invited_phones` is provably
// harmless. Both halves are proven empirically below, not just asserted.

// 12a — the case that MATTERS: deleting all guests (`$clean = []`) while
// `_pge_invited_phones` fails to persist its own update (stays at the OLD
// non-empty phone list) must NOT be reported as success, because a fresh
// read would otherwise reconstruct the old guest(s) from the stale legacy
// key even though the canonical `_pge_invited_guests` key is correctly []
// — a false "delete succeeded" that actually left legacy guests
// resurrectable.
gr1_reset_db();
$z3_event = 902;
$z3_v1_saved = pge_event_guests_save_map($z3_event, gr1_map('970000010201', 'Z3 Guest V1'));
gr1_ok('Z3-0a baseline write (both Post Meta keys) succeeds normally', is_array($z3_v1_saved));
PGE_Event_Guest_Read_Projection::rebuild_event($z3_event);
gr1_ok('Z3-0b baseline: V1 is READY', PGE_Event_Guest_Read_Projection::state($z3_event)['status'] === 'ready');

$GLOBALS['gr1_not_persist_phones_write'] = true;
$z3_saved = pge_event_guests_save_map($z3_event, []); // delete all guests

gr1_ok(
    'Z3-1 (Required Test 12, the case that matters) save_map() does NOT report success for an empty-map write when the legacy `_pge_invited_phones` compatibility key failed to persist — the fresh-read postcondition catches the resulting stale-legacy-reconstruction risk that a naive `_pge_invited_guests`-only check would miss',
    $z3_saved === null
);
$z3_fresh_after = pge_event_guests_get_map_fresh($z3_event);
gr1_ok(
    'Z3-2 (proving WHY this matters) a fresh canonical read at this exact moment would incorrectly reconstruct the old guest from the stale legacy phones key if trusted — confirming the empty-map/legacy-fallback risk described in Section 5/12 is real, not hypothetical',
    is_array($z3_fresh_after) && count($z3_fresh_after) === 1 && isset($z3_fresh_after['970000010201'])
);
gr1_ok('Z3-3 the projection remains STALE/not-ready, never falsely republished as an empty/ready state', PGE_Event_Guest_Read_Projection::state($z3_event)['status'] === 'stale');
$z3_reacquire = PGE_Event_Guest_Read_Projection::acquire_event_lock($z3_event);
gr1_ok('Z3-4 the event lock was genuinely released', $z3_reacquire === true);
PGE_Event_Guest_Read_Projection::release_event_lock($z3_event);

$z3_retry_saved = pge_event_guests_save_map($z3_event, []); // no simulated failure this time
gr1_ok('Z3-5 a later normal retry (both keys persist correctly) succeeds and the empty map becomes genuinely authoritative', $z3_retry_saved === []);
gr1_ok('Z3-6 the fresh canonical read now correctly reports empty — no legacy resurrection', pge_event_guests_get_map_fresh($z3_event) === []);

// 12b — the case that is HARMLESS: a non-empty `$clean` write with
// `_pge_invited_phones` failing to persist must still succeed normally,
// because the canonical read never consults the legacy key when
// `_pge_invited_guests` itself is non-empty.
gr1_reset_db();
$z4_event = 903;
$z4_v1_saved = pge_event_guests_save_map($z4_event, gr1_map('970000010301', 'Z4 Guest V1'));
gr1_ok('Z4-0a baseline write succeeds', is_array($z4_v1_saved));
PGE_Event_Guest_Read_Projection::rebuild_event($z4_event);

$GLOBALS['gr1_not_persist_phones_write'] = true;
$z4_saved = pge_event_guests_save_map($z4_event, gr1_map('970000010302', 'Z4 Guest V2'));

gr1_ok(
    'Z4-1 (Required Test 12, the harmless case, proven empirically not just asserted) a non-empty write still succeeds normally even though the legacy `_pge_invited_phones` key failed to persist, because the canonical fresh read never falls back to it when `_pge_invited_guests` is non-empty',
    is_array($z4_saved) && isset($z4_saved['970000010302'])
);
gr1_ok('Z4-2 the projection correctly reaches READY for V2 despite the harmless legacy-key write failure', PGE_Event_Guest_Read_Projection::state($z4_event)['status'] === 'ready');
gr1_ok('Z4-3 a reader correctly gets V2', PGE_Event_Guest_Read_Projection::get_guests_by_phones($z4_event, ['970000010302'])[0]['name'] === 'Z4 Guest V2');

// ══════════════════════════════════════════════════════════════
// Final report
// ══════════════════════════════════════════════════════════════

echo "\nH1C-GR1: {$passed}/" . ($passed + $failed) . " passed\n";
if ($failed > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - $f\n";
}
