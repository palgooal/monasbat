<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('pge_event_guests_norm_phone')) {
    function pge_event_guests_norm_phone($value)
    {
        return preg_replace('/\D+/', '', trim((string) $value));
    }
}

if (!function_exists('pge_event_guests_resolve_current_by_phone')) {
    /**
     * Resolve one current invited-guest identity without exposing guest data.
     *
     * This intentionally inspects the raw guest records. The normal map cannot
     * prove uniqueness because canonical phone keys collapse duplicates.
     */
    function pge_event_guests_resolve_current_by_phone($event_id, $guest_phone)
    {
        if (!is_int($event_id) || $event_id <= 0 || !is_string($guest_phone)) {
            return ['status' => 'storage_error'];
        }

        $canonical = pge_event_guests_norm_phone($guest_phone);
        if (!is_string($canonical) || $canonical === '') {
            return ['status' => 'storage_error'];
        }

        $stored = get_post_meta($event_id, '_pge_invited_guests', true);
        if ($stored === '' || $stored === null || $stored === false || $stored === []) {
            return ['status' => 'not_found'];
        }
        if (!is_array($stored)) {
            return ['status' => 'storage_error'];
        }

        $matches = 0;
        foreach ($stored as $guest) {
            if (!is_array($guest) || !array_key_exists('phone', $guest) || !is_string($guest['phone'])) {
                return ['status' => 'storage_error'];
            }
            $phone = pge_event_guests_norm_phone($guest['phone']);
            if (!is_string($phone) || $phone === '') {
                return ['status' => 'storage_error'];
            }
            if ($phone === $canonical) {
                $matches++;
                if ($matches > 1) return ['status' => 'ambiguous'];
            }
        }

        return ['status' => $matches === 1 ? 'found' : 'not_found'];
    }
}

if (!function_exists('pge_event_guests_user_can_manage')) {
    function pge_event_guests_user_can_manage($event_id)
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0 || get_post_type($event_id) !== 'pge_event') return false;

        $uid = get_current_user_id();
        $author_id = (int) get_post_field('post_author', $event_id);

        if (current_user_can('administrator')) return true;
        if ($uid && $uid === $author_id) return true;
        if (current_user_can('edit_post', $event_id)) return true;

        return false;
    }
}

if (!function_exists('pge_event_guests_normalize_phones_value')) {
    /**
     * Concurrency Correctness Fix Pass — Fresh-Read Audit.
     *
     * Shared canonicalization for `_pge_invited_phones`' legacy value shape
     * (either an array of phone strings, or a newline-delimited string).
     * Extracted so pge_event_guests_parse_phones_meta() (cached,
     * get_post_meta()-backed) and pge_event_guests_parse_phones_meta_fresh()
     * (cache-bypassing, raw $wpdb query) always produce byte-identical
     * output for byte-identical underlying stored content — the two must
     * never diverge, or a freshness-fingerprint comparison built from one
     * could never match one built from the other.
     *
     * @return array<int,string> de-duplicated, normalized phone list.
     */
    function pge_event_guests_normalize_phones_value($value)
    {
        if (is_array($value)) {
            $phones = $value;
        } else {
            $raw = (string) $value;
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            $phones = array_filter(array_map('trim', explode("\n", $raw)));
        }

        $out = [];
        foreach ($phones as $phone) {
            $norm = pge_event_guests_norm_phone($phone);
            if ($norm !== '') $out[$norm] = $norm;
        }

        return array_values($out);
    }
}

if (!function_exists('pge_event_guests_parse_phones_meta')) {
    function pge_event_guests_parse_phones_meta($event_id)
    {
        $raw = get_post_meta($event_id, '_pge_invited_phones', true);

        return pge_event_guests_normalize_phones_value($raw);
    }
}

if (!function_exists('pge_event_guests_parse_phones_meta_fresh')) {
    /**
     * Concurrency Correctness Fix Pass — Fresh-Read Audit.
     *
     * Cache-bypassing counterpart to pge_event_guests_parse_phones_meta():
     * queries wp_postmeta directly for `_pge_invited_phones`, mirroring
     * pge_event_guests_get_map_fresh()'s raw-query pattern for
     * `_pge_invited_guests`, so this function's output can never come from
     * a same-process WP object cache echo. Used exclusively by
     * pge_event_guests_get_map_fresh() to thread a genuinely fresh
     * legacy-phones value through to
     * pge_event_guests_normalize_stored_map()'s optional third parameter,
     * closing the gap where the fresh reader's empty/legacy fallback could
     * otherwise silently reach the cached get_post_meta() read instead.
     *
     * @return array|null null only on a genuine query error; an array
     *   (possibly empty) otherwise — including when no meta row exists yet.
     */
    function pge_event_guests_parse_phones_meta_fresh($event_id)
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) {
            return null;
        }

        global $wpdb;
        $wpdb->last_error = '';
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
            $event_id,
            '_pge_invited_phones'
        ));

        if ($raw === null) {
            if ($wpdb->last_error !== '') {
                return null;
            }
            return [];
        }

        return pge_event_guests_normalize_phones_value(maybe_unserialize($raw));
    }
}

if (!function_exists('pge_event_guests_normalize_stored_map')) {
    /**
     * Phase H1C-GR1 — Freshness Publication Protocol Redesign.
     *
     * Shared canonicalization: turns whatever `_pge_invited_guests` holds
     * (or, when empty, the legacy `_pge_invited_phones` fallback) into the
     * exact same phone-keyed map shape, regardless of whether the caller is
     * the normal (WP-object-cache-backed) reader below or the
     * cache-bypassing fresh reader. Both MUST produce identical output for
     * identical stored content — PGE_Event_Guest_Read_Projection's content
     * fingerprint comparison is only meaningful if they do.
     *
     * Concurrency Correctness Fix Pass — Fresh-Read Audit: $legacy_phones
     * is an OPTIONAL third parameter. When omitted (null, the default),
     * behavior is fully unchanged — the empty-map fallback below calls the
     * ordinary, WP-object-cache-backed pge_event_guests_parse_phones_meta().
     * When explicitly passed an array (including an empty one), that array
     * is used directly instead — this is how pge_event_guests_get_map_fresh()
     * threads a genuinely cache-bypassing legacy-phones read through, so
     * that ALL data entering a freshness comparison comes from a
     * cache-bypassing read, not just the primary `_pge_invited_guests` key.
     */
    function pge_event_guests_normalize_stored_map($event_id, $stored, $legacy_phones = null)
    {
        $event_id = (int) $event_id;
        $map = [];

        if (is_array($stored)) {
            foreach ($stored as $key => $guest) {
                if (!is_array($guest)) {
                    $phone = pge_event_guests_norm_phone(is_string($guest) ? $guest : $key);
                    if ($phone === '') continue;
                    $map[$phone] = [
                        'phone' => $phone,
                        'name'  => '',
                        'note'  => '',
                        'code'  => '',
                    ];
                    continue;
                }

                $phone = pge_event_guests_norm_phone($guest['phone'] ?? $key);
                if ($phone === '') continue;

                $map[$phone] = [
                    'phone' => $phone,
                    'name'  => sanitize_text_field((string) ($guest['name'] ?? '')),
                    'note'  => sanitize_textarea_field((string) ($guest['note'] ?? '')),
                    'code'  => sanitize_text_field((string) ($guest['code'] ?? '')),
                ];
            }
        }

        if (empty($map)) {
            $legacy_phones = is_array($legacy_phones)
                ? $legacy_phones
                : pge_event_guests_parse_phones_meta($event_id);
            foreach ($legacy_phones as $phone) {
                $map[$phone] = [
                    'phone' => $phone,
                    'name'  => '',
                    'note'  => '',
                    'code'  => '',
                ];
            }
        }

        return $map;
    }
}

if (!function_exists('pge_event_guests_get_map')) {
    function pge_event_guests_get_map($event_id)
    {
        $event_id = (int) $event_id;
        $stored = get_post_meta($event_id, '_pge_invited_guests', true);
        return pge_event_guests_normalize_stored_map($event_id, $stored);
    }
}

if (!function_exists('pge_event_guests_get_map_fresh')) {
    /**
     * Phase H1C-GR1 — Freshness Publication Protocol Redesign.
     *
     * A cache-BYPASSING read of `_pge_invited_guests`: queries wp_postmeta
     * directly instead of going through get_post_meta(), because WordPress's
     * per-request object cache would otherwise return whatever THIS process
     * itself last wrote for this key (update_meta_cache()'s
     * eager-fetch-on-first-touch behavior populates the cache from the DB
     * only once per request and is never invalidated mid-request by another
     * process's write) rather than re-querying the database — meaning it
     * could never observe a concurrent OTHER process's more recent write.
     * This function exists specifically so
     * PGE_Event_Guest_Read_Projection::attempt_publish()/rebuild_event() can
     * verify/rebuild against what is ACTUALLY currently stored, not against
     * a same-process cache echo.
     *
     * ORDER BY meta_id ASC LIMIT 1 deliberately matches get_post_meta()'s
     * own "first row wins" semantics for a (rare, not expected under the
     * single-choke-point contract) multi-row same-key case, so this
     * function's output can never diverge from what get_post_meta() would
     * eventually return once its cache is (re)populated from the DB.
     *
     * Deliberately used ONLY on the write/rebuild path (never from the
     * steady-state collaborator scoped-read path) — see
     * PGE_Event_Guest_Read_Projection's class docblock.
     *
     * Concurrency Correctness Fix Pass — Fresh-Read Audit: this function
     * ALSO fetches `_pge_invited_phones` via a fresh, cache-bypassing query
     * (pge_event_guests_parse_phones_meta_fresh()) and threads it through
     * to pge_event_guests_normalize_stored_map()'s optional third
     * parameter. Without this, the empty/legacy fallback INSIDE the
     * normalizer would silently reach `_pge_invited_phones` via the
     * ordinary, WP-object-cache-backed get_post_meta() even when called
     * from here — undermining this function's cache-bypassing contract for
     * any event still relying on the legacy phones-only storage shape. A
     * genuine query error reading the legacy key is propagated as an
     * overall fresh-read failure (null), the same as a genuine error
     * reading the primary key.
     *
     * @return array|null null only on a genuine query error; an array
     *   (possibly empty) otherwise — including when no meta row exists yet.
     */
    function pge_event_guests_get_map_fresh($event_id)
    {
        $event_id = (int) $event_id;
        if ($event_id <= 0) {
            return null;
        }

        global $wpdb;
        $wpdb->last_error = '';
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
            $event_id,
            '_pge_invited_guests'
        ));

        if ($raw === null && $wpdb->last_error !== '') {
            return null;
        }

        $legacy_fresh = pge_event_guests_parse_phones_meta_fresh($event_id);
        if ($legacy_fresh === null) {
            // Genuine query error reading the legacy fallback key: this is
            // a fresh-read failure, not "no legacy phones" — must not
            // silently degrade to a cached read.
            return null;
        }

        // $raw === null with no error means "no row at all" — identical
        // meaning to get_post_meta()'s '' — canonicalize the same way.
        $stored = ($raw === null) ? null : maybe_unserialize($raw);

        return pge_event_guests_normalize_stored_map($event_id, $stored, $legacy_fresh);
    }
}

if (!function_exists('pge_event_guests_save_map')) {
    function pge_event_guests_save_map($event_id, $map)
    {
        $event_id = (int) $event_id;
        $clean = [];

        foreach ((array) $map as $guest) {
            if (!is_array($guest)) continue;

            $phone = pge_event_guests_norm_phone($guest['phone'] ?? '');
            if ($phone === '') continue;

            // توليد رمز شخصي إذا لم يكن موجوداً
            $code = sanitize_text_field((string) ($guest['code'] ?? ''));
            if ($code === '' || !preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) {
                $code = function_exists('pge_generate_invite_code')
                    ? pge_generate_invite_code()
                    : strtoupper(substr(str_replace(['+','/','='],'',base64_encode(random_bytes(6))), 0, 4))
                      . '-'
                      . strtoupper(substr(str_replace(['+','/','='],'',base64_encode(random_bytes(6))), 0, 4));
            }

            $clean[$phone] = [
                'phone' => $phone,
                'name'  => sanitize_text_field((string) ($guest['name'] ?? '')),
                'note'  => sanitize_textarea_field((string) ($guest['note'] ?? '')),
                'code'  => $code,
            ];
        }

        // Strict Pre-Write Invalidation Contract Fix Pass (this pass) — see
        // the class docblock in class-pge-event-guest-read-projection.php
        // ("STRICT PRE-WRITE INVALIDATION CONTRACT FIX PASS") for the full
        // analysis. Prior passes closed the reader-vs-writer race by making
        // the authoritative write ALWAYS proceed and racing a best-effort
        // invalidation/self-heal against it — leaving one honestly-disclosed
        // residual corner case where a sustained invalidation failure could
        // still let a lock-free reader briefly observe
        // `Post Meta = V2 AND Projection = READY(V1)`. This pass eliminates
        // that window ENTIRELY instead of minimizing it: the authoritative
        // write below is now GATED on both the per-event lock AND a
        // CONFIRMED pre-write invalidation. If either cannot be confirmed,
        // this function ABORTS BEFORE update_post_meta() runs at all —
        // Post Meta is never touched, and this function returns null
        // instead of the clean map, so a caller can never observe or report
        // success for a write that never happened. This changes save_map()'s
        // return contract for the FIRST time since this class's write path
        // was introduced: previously always array; now array on success,
        // null on a pre-write failure (lock unavailable, or pre-write
        // invalidation could not be confirmed after retries). This is
        // authorized explicitly for this pass: gating WHEN a write may
        // *start* is a write-sequencing safety gate, not a change to what
        // is authoritative — Post Meta remains the sole Source of Truth,
        // and the derived projection's CONTENT still never gates or
        // overrides it; only the ORDERING of an individual write attempt is
        // gated. See the full caller audit in the phase report: every
        // direct caller was reviewed and only 3 of 7 real call sites needed
        // any change at all, because two already defensively checked
        // is_array() on the return value, two are rollback/compensation
        // calls that already re-verify actual persisted state (not the
        // return value) as their real postcondition, and 3 (edit()'s
        // same-phone path, delete(), and the legacy regen-code AJAX
        // endpoint) previously ignored the return value entirely and were
        // updated to check it.
        $has_projection = class_exists('PGE_Event_Guest_Read_Projection');

        if ($has_projection) {
            if (!PGE_Event_Guest_Read_Projection::acquire_event_lock($event_id)) {
                // Cannot prove no concurrent writer is mid-publish for this
                // event — a concurrent writer's OWN update_post_meta() could
                // land between an unlocked write here and that writer's own
                // verify/activate sequence. Never write unlocked: abort
                // before Post Meta is touched at all. No SQL/lock name/DB
                // error/guest data in the log line.
                error_log("PGE guest write aborted before authoritative Post Meta write for event_id={$event_id}: per-event lock not acquired");
                return null;
            }

            // Pre-write invalidation: revoke READY trust from whatever
            // generation is currently active BEFORE the authoritative Post
            // Meta write, so a lock-free reader can never observe
            // Post Meta=V2 while Projection=READY(V1). Retried a small,
            // bounded number of times immediately (no backoff — this is a
            // trivial single-row UPDATE, not a resource worth waiting on),
            // because a single transient failure must not abort a write
            // that could otherwise safely proceed.
            $invalidated = false;
            for ($pge_grp_invalidate_attempt = 0; $pge_grp_invalidate_attempt < 3 && !$invalidated; $pge_grp_invalidate_attempt++) {
                try {
                    $invalidated = PGE_Event_Guest_Read_Projection::force_stale_best_effort($event_id);
                } catch (\Throwable $e) {
                    // Never allow an exception here to propagate — treated
                    // exactly like a failed (false) attempt and retried the
                    // same way. No raw exception text/SQL/guest data logged.
                    $invalidated = false;
                }
            }
            if (!$invalidated) {
                // Cannot prove the OLD generation's READY trust was actually
                // revoked — writing Post Meta now would risk exactly the
                // forbidden window this whole phase exists to close. Abort
                // before the write, release the lock we DID acquire above,
                // and let a later successful save_map() call, or the
                // Application Service's lazy rebuild_event() path, recover.
                error_log("PGE guest write aborted before authoritative Post Meta write for event_id={$event_id}: pre-write invalidation could not be confirmed after retries");
                PGE_Event_Guest_Read_Projection::release_event_lock($event_id);
                return null;
            }
        }

        // From this point on, EITHER the projection class does not exist
        // (nothing to protect) OR the lock is held AND invalidation is
        // confirmed — the authoritative write below is now safe to proceed
        // unconditionally; nothing past this point may abort it.
        //
        // Authoritative Write Confirmation & Lock Cleanup Fix Pass (this
        // pass): everything from the authoritative Post Meta write through
        // projection synchronization now runs inside a single try/finally
        // that covers the per-event lock acquired above, so ANY Throwable
        // occurring anywhere in this section — including one thrown by a
        // save_post/updated_post_meta hook or plugin callback triggered
        // BY update_post_meta() itself, not just by sync_event() — still
        // reaches the finally and releases the lock exactly once. The lock
        // must never depend on PHP request termination to be freed, and
        // MariaDB's GET_LOCK is reentrant on the same connection, so a
        // leaked hold count is unacceptable even if a later acquisition in
        // the same request appears to succeed.
        //
        // This pass also closes the remaining authoritative-write
        // correctness gap: previously, once update_post_meta() was called,
        // the function assumed the write succeeded and unconditionally
        // returned $clean. update_post_meta()'s own boolean return is NOT
        // a valid success signal here — WordPress returns false when the
        // requested value is identical to what is already stored, which is
        // not a failure. Instead, the authoritative postcondition is
        // verified with a fresh, cache-bypassing read
        // (pge_event_guests_get_map_fresh(), the SAME infrastructure
        // PGE_Event_Guest_Read_Projection already uses to verify freshness
        // before publishing) compared against the exact $clean map that was
        // requested. That fresh read already incorporates the
        // `_pge_invited_phones` legacy-fallback compatibility path (via
        // pge_event_guests_normalize_stored_map()'s shared logic), so a
        // single comparison against it correctly catches a partial write
        // across BOTH Post Meta keys — including the one case where
        // `_pge_invited_phones` alone can influence the canonical result:
        // $clean is empty and the normalizer would otherwise reconstruct
        // stale guests from a not-yet-updated legacy phones value. No
        // second source of truth is introduced — Post Meta remains sole
        // authority; this only confirms what was actually persisted before
        // trusting it.
        if ($has_projection) {
            $authoritative_confirmed = false;
            try {
                update_post_meta($event_id, '_pge_invited_guests', $clean);
                update_post_meta($event_id, '_pge_invited_phones', array_keys($clean));

                $fresh_after_write = pge_event_guests_get_map_fresh($event_id);
                $authoritative_confirmed = is_array($fresh_after_write);
                if ($authoritative_confirmed) {
                    $expected = $clean;
                    $actual = $fresh_after_write;
                    ksort($expected);
                    ksort($actual);
                    $authoritative_confirmed = ($expected === $actual);
                }

                if (!$authoritative_confirmed) {
                    // Cannot prove the requested map is actually what is
                    // now canonically stored — never report success for an
                    // unconfirmed authoritative write. The pre-write
                    // invalidation above already revoked the OLD
                    // generation's READY trust, so nothing here may leave
                    // it (or a false new one) trusted as current. No
                    // SQL/lock name/DB error/guest data logged.
                    error_log("PGE guest write could not be confirmed as authoritative for event_id={$event_id}: post-write verification mismatch");
                    PGE_Event_Guest_Read_Projection::force_stale_best_effort($event_id);
                    return null;
                }

                // Phase H1C-GR1 (DEC-004) — this function is the single,
                // verified choke point for every guest-identity write in
                // this codebase (no other call site writes
                // `_pge_invited_guests` directly). Synchronize the derived,
                // non-authoritative relational read projection from the
                // exact map that was just CONFIRMED persisted above as
                // Source of Truth. The authoritative write is confirmed by
                // this point regardless of what happens below — a
                // projection sync/build failure here is a POST-write,
                // DERIVED-layer failure, categorically distinct from the
                // pre-write and write-confirmation failures above: it must
                // NEVER be reported to the caller as if the authoritative
                // write itself failed. $clean is passed directly (not
                // re-read from Post Meta) so the projection reflects
                // precisely what this call just confirmed it wrote.
                PGE_Event_Guest_Read_Projection::sync_event($event_id, $clean);
            } catch (\Throwable $e) {
                // Freshness Hardening Fix Pass: never log the raw exception
                // message, SQL text, or any guest field — a fixed, minimal
                // message only.
                error_log("PGE guest read projection synchronization failed for event_id={$event_id}");
                try {
                    PGE_Event_Guest_Read_Projection::force_stale_best_effort($event_id);
                } catch (\Throwable $e2) {
                    // Never allow a secondary failure here to escape either.
                }
                if (!$authoritative_confirmed) {
                    // The Throwable happened before (or during) confirming
                    // the authoritative write itself — this is NOT a
                    // post-write, derived-layer failure, and must not be
                    // reported as success.
                    return null;
                }
                // else: the Throwable happened during sync_event() AFTER
                // the authoritative write was already confirmed — fall
                // through to the normal success return below, unchanged
                // from the prior pass's accepted behavior.
            } finally {
                PGE_Event_Guest_Read_Projection::release_event_lock($event_id);
            }
        } else {
            update_post_meta($event_id, '_pge_invited_guests', $clean);
            update_post_meta($event_id, '_pge_invited_phones', array_keys($clean));
        }

        return $clean;
    }
}

if (!function_exists('pge_event_guests_get_status_label')) {
    function pge_event_guests_get_status_label($status)
    {
        if ($status === 'yes') return 'سيحضر';
        if ($status === 'no') return 'اعتذر';
        return 'لم يرد';
    }
}

/**
 * جلب بيانات RSVP من الجدول الحقيقي لمناسبة معينة
 * يُعيد: map (phone=>reply) + checkin_map (phone=>true) +
 * integrity_errors (phone=>true). الهوية المكررة لا تختار أول/آخر صف.
 * يُخزَّن في static cache لتجنب الاستعلام المتكرر في نفس الطلب
 */
if (!function_exists('pge_event_guests_load_rsvp_from_db')) {
    function pge_event_guests_load_rsvp_from_db(int $event_id): array
    {
        static $cache = [];
        if (isset($cache[$event_id])) return $cache[$event_id];

        global $wpdb;
        $table = $wpdb->prefix . 'pge_event_rsvps';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT guest_phone, reply, checked_in FROM {$table} WHERE event_id = %d",
            $event_id
        ), ARRAY_A);

        $map = [];
        $checkin_map = [];
        $integrity_errors = [];
        $seen_phones = [];
        foreach ((array) $rows as $r) {
            $phone = function_exists('pge_norm_phone')
                ? pge_norm_phone($r['guest_phone'] ?? '')
                : preg_replace('/\D+/', '', (string) ($r['guest_phone'] ?? ''));
            if ($phone === '') {
                continue;
            }
            if (isset($seen_phones[$phone])) {
                $integrity_errors[$phone] = true;
                unset($map[$phone], $checkin_map[$phone]);
                continue;
            }
            $seen_phones[$phone] = true;
            if (isset($integrity_errors[$phone])) {
                continue;
            }
            $map[$phone] = $r['reply'];
            if ((int) $r['checked_in'] === 1) {
                $checkin_map[$phone] = true;
            }
        }

        $cache[$event_id] = compact('map', 'checkin_map', 'integrity_errors');
        return $cache[$event_id];
    }
}

if (!function_exists('pge_event_guests_get_row_payload')) {
    function pge_event_guests_get_row_payload($event_id, $guest)
    {
        $phone = pge_event_guests_norm_phone($guest['phone'] ?? '');
        $name  = sanitize_text_field((string) ($guest['name'] ?? ''));
        $note  = sanitize_textarea_field((string) ($guest['note'] ?? ''));

        $rsvp   = pge_event_guests_load_rsvp_from_db((int) $event_id);
        $reply  = isset($rsvp['map'][$phone]) ? (string) $rsvp['map'][$phone] : '';
        $status = ($reply === 'yes' || $reply === 'no') ? $reply : 'pending';
        $checked = isset($rsvp['checkin_map'][$phone]) ? 'yes' : 'no';

        $code = sanitize_text_field((string) ($guest['code'] ?? ''));

        return [
            'phone'        => $phone,
            'name'         => $name,
            'note'         => $note,
            'code'         => $code,
            'status'       => $status,
            'status_label' => pge_event_guests_get_status_label($status),
            'checked'      => $checked,
        ];
    }
}

if (!function_exists('pge_event_guests_get_stats')) {
    function pge_event_guests_get_stats($event_id, $guests_map = null)
    {
        if (!is_array($guests_map)) {
            $guests_map = pge_event_guests_get_map($event_id);
        }

        $phones = array_keys($guests_map);
        $total  = count($phones);
        $yes = $no = $checked = 0;

        $rsvp = pge_event_guests_load_rsvp_from_db((int) $event_id);

        foreach ($phones as $phone) {
            $reply = $rsvp['map'][$phone] ?? '';
            if ($reply === 'yes') $yes++;
            if ($reply === 'no')  $no++;
            if (isset($rsvp['checkin_map'][$phone])) $checked++;
        }

        $pending = max(0, $total - ($yes + $no));

        return [
            'total'   => $total,
            'yes'     => $yes,
            'no'      => $no,
            'pending' => $pending,
            'checked' => $checked,
        ];
    }
}

if (!function_exists('pge_event_guests_validate_request')) {
    function pge_event_guests_validate_request()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error('غير مصرح');
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'pge_event_manage_nonce')) {
            wp_send_json_error('رمز الأمان غير صالح');
        }

        $event_id = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
        if (!$event_id || get_post_type($event_id) !== 'pge_event') {
            wp_send_json_error('مناسبة غير صالحة');
        }

        if (!pge_event_guests_user_can_manage($event_id)) {
            wp_send_json_error('ليس لديك صلاحية إدارة هذه المناسبة');
        }

        return $event_id;
    }
}

if (!function_exists('pge_event_guests_migrate_phone_refs')) {
    function pge_event_guests_migrate_phone_refs($event_id, $old_phone, $new_phone)
    {
        if ($old_phone === $new_phone || $old_phone === '' || $new_phone === '') return;

        $rsvp_map = (array) get_post_meta($event_id, '_pge_rsvp_map', true);
        if (isset($rsvp_map[$old_phone])) {
            if (!isset($rsvp_map[$new_phone])) {
                $rsvp_map[$new_phone] = $rsvp_map[$old_phone];
            }
            unset($rsvp_map[$old_phone]);
            update_post_meta($event_id, '_pge_rsvp_map', $rsvp_map);
        }

        $checkins = (array) get_post_meta($event_id, '_pge_checkins', true);
        if (isset($checkins[$old_phone])) {
            if (!isset($checkins[$new_phone])) {
                $checkins[$new_phone] = $checkins[$old_phone];
            }
            unset($checkins[$old_phone]);
            update_post_meta($event_id, '_pge_checkins', $checkins);
        }
    }
}

if (!function_exists('pge_event_guests_remove_phone_refs')) {
    function pge_event_guests_remove_phone_refs($event_id, $phone)
    {
        if ($phone === '') return;

        $rsvp_map = (array) get_post_meta($event_id, '_pge_rsvp_map', true);
        if (isset($rsvp_map[$phone])) {
            unset($rsvp_map[$phone]);
            update_post_meta($event_id, '_pge_rsvp_map', $rsvp_map);
        }

        $checkins = (array) get_post_meta($event_id, '_pge_checkins', true);
        if (isset($checkins[$phone])) {
            unset($checkins[$phone]);
            update_post_meta($event_id, '_pge_checkins', $checkins);
        }
    }
}

/**
 * ============================================================================
 * Guest Limit Unification RFC — Part A: Legacy Creation Endpoint Retirement
 * ============================================================================
 * `wp_ajax_pge_event_guest_add` و`wp_ajax_pge_event_guest_bulk_add` كانتا
 * مسجَّلتين هنا وتكتبان مباشرة عبر pge_event_guests_get_map()/save_map()،
 * **بلا مرور إطلاقاً** عبر PGE_Invitation_Service/Repository/Audit — أي بلا
 * أي وعي بحصة المدعوين (guest_limit)، وبلا تدقيق. هذا كان الثغرة الوحيدة
 * التي تسمح بتجاوز حصة المدعوين حتى بعد توحيد الإنفاذ في
 * PGE_Invitation_Service::create() (راجع docs/INVITATION-GUEST-LIMIT-
 * ENFORCEMENT.md).
 *
 * دليل عدم الاستخدام قبل الإزالة (لا افتراض):
 *   - RC1 Fix Pack 3B (docs/RC1-AUDIT.md §16.4) أزال بالفعل كل نماذج/أزرار
 *     الواجهة القديمة (`addGuestForm`/`bulkGuestForm`) من page-event-
 *     manage.php — لا عنصر DOM يستدعي هذين الإجرائين بعد الآن (مُتحقَّق
 *     تنفيذياً في tests/test-rc1-fixpack2.php A4.8/A4.9 وtest-rc1-
 *     fixpack3b.php).
 *   - RC1 Fix Pack 3A (§15) نقل الإضافة الجماعية بالكامل إلى
 *     PGE_Invitation_Bulk_Add_Service — لا فجوة وظيفية متبقّية تبرّر إبقاء
 *     `pge_event_guest_bulk_add` حياً.
 *   - بحث شامل في كامل wp-content (PHP + JS + قوالب) عن استدعاء فعلي لهذين
 *     الإجراءين عبر admin-ajax.php لم يُظهر أي مستدعٍ حي سوى الاختبارات
 *     والتوثيق التاريخي.
 *
 * القرار: إزالة تسجيل الإجراءين (`add_action`) فقط — لم تُحذف أي دالة
 * مساعدة (pge_event_guests_get_map/save_map/validate_request/...)، ولم
 * يُلمَس `pge_event_guest_update`/`_delete`/`_bulk_delete`/`_regen_code` أو
 * أي كود QR/قراءة أدناه في هذا الملف إطلاقاً. لم تعد هاتان الدالتان قابلتين
 * للاستدعاء عبر admin-ajax.php بعد الآن.
 */



add_action('wp_ajax_pge_event_guest_update', function () {
    $event_id = pge_event_guests_validate_request();

    $old_phone = pge_event_guests_norm_phone($_POST['old_phone'] ?? '');
    $new_phone = pge_event_guests_norm_phone($_POST['phone'] ?? '');
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

    if ($old_phone === '' || $new_phone === '') {
        wp_send_json_error('بيانات المدعو غير مكتملة');
    }

    // Phase D2: keep this registered legacy compatibility endpoint, but route
    // all writes through the authoritative Service so phone changes cannot
    // bypass target RSVP reset, QR rotation, compensation, or audit.
    if (!class_exists('PGE_Invitation_Service')) {
        require_once __DIR__ . '/class-pge-invitation-service.php';
    }
    $result = PGE_Invitation_Service::edit(
        $event_id,
        $old_phone,
        $new_phone,
        $name,
        $note,
        get_current_user_id()
    );

    if (($result['result'] ?? '') === 'duplicate') {
        wp_send_json_error('رقم الجوال الجديد مستخدم بالفعل');
    }
    if (($result['result'] ?? '') === 'error' && ($result['reason'] ?? '') === 'not_found') {
        wp_send_json_error('المدعو غير موجود');
    }
    if (($result['result'] ?? '') !== 'updated') {
        wp_send_json_error('تعذّر تحديث بيانات المدعو');
    }

    $guests_map = pge_event_guests_get_map($event_id);

    wp_send_json_success([
        'message' => 'تم تحديث بيانات المدعو',
        'guest'   => pge_event_guests_get_row_payload($event_id, $guests_map[$new_phone]),
        'stats'   => pge_event_guests_get_stats($event_id, $guests_map),
    ]);
});

/**
 * ============================================================================
 * RC1 Fix Pack 3B — "Legacy Guest Panel Retirement (Hard Delete Migration)"
 * ============================================================================
 * "If deletion currently bypasses the Invitation Service, refactor by moving
 * deletion into the Service. Do NOT duplicate delete logic." — هذا المعالج
 * القديم كان يُنفِّذ الحذف مباشرة (unset + remove_phone_refs + save_map) بلا
 * مرور بأي طبقة خدمة. أصبح الآن يُفوِّض بالكامل لـPGE_Invitation_Service::
 * delete() (التي تُعيد استخدام **نفس** الخطوات الثلاث حرفياً داخل
 * PGE_Invitation_Repository::delete() — راجع توثيقها الكامل هناك)، فيصبح
 * هناك نقطة تنفيذ حذف واحدة فقط في كامل المشروع. **لا تغيير في السلوك
 * الملحوظ من طرف العميل** (نفس رسائل النجاح/الفشل، نفس بنية 'stats' في
 * الاستجابة) — فقط إزالة الازدواجية الداخلية. هذا المعالج **لم يعد له أي
 * زر مُشغِّل في الواجهة** بعد تفكيك لوحة المدعوين القديمة (page-event-
 * manage.php) — يبقى مُسجَّلاً فعلياً (Legacy AJAX handlers may remain
 * registered) كطبقة توافق فقط، ويستمر بتطبيق نفس فحوصات التفويض/nonce/
 * ملكية المناسبة كاملة عبر pge_event_guests_validate_request() أعلاه.
 */
add_action('wp_ajax_pge_event_guest_delete', function () {
    $event_id = pge_event_guests_validate_request();
    $phone = pge_event_guests_norm_phone($_POST['phone'] ?? '');

    if ($phone === '') {
        wp_send_json_error('رقم الجوال غير صالح');
    }

    if (!class_exists('PGE_Invitation_Service')) {
        wp_send_json_error('تعذّر تنفيذ العملية');
    }

    $result = PGE_Invitation_Service::delete($event_id, $phone, get_current_user_id());
    if (($result['result'] ?? '') !== 'deleted') {
        wp_send_json_error('المدعو غير موجود');
    }

    wp_send_json_success([
        'message' => 'تم حذف المدعو',
        'stats'   => pge_event_guests_get_stats($event_id),
    ]);
});

// ── حفظ قوالب رسائل واتساب للمناسبة ────────────────────────────────────────
add_action('wp_ajax_pge_event_save_wa_templates', function () {
    $event_id = pge_event_guests_validate_request();

    $keys = ['invite', 'yes', 'no', 'invalid'];
    foreach ($keys as $k) {
        $val = sanitize_textarea_field(wp_unslash($_POST['tpl_' . $k] ?? ''));
        if ($val !== '') {
            update_post_meta($event_id, '_pge_wa_tpl_' . $k, $val);
        } else {
            delete_post_meta($event_id, '_pge_wa_tpl_' . $k); // حذف = استخدام الافتراضي
        }
    }

    wp_send_json_success(['message' => '✅ تم حفظ قوالب الرسائل']);
});

// ── تجديد رمز ضيف معيّن ─────────────────────────────────────────────────────
add_action('wp_ajax_pge_event_guest_regen_code', function () {
    $event_id = pge_event_guests_validate_request();
    $phone = pge_event_guests_norm_phone($_POST['phone'] ?? '');

    if ($phone === '') {
        wp_send_json_error('رقم الجوال غير صالح');
    }

    $guests_map = pge_event_guests_get_map($event_id);
    if (!isset($guests_map[$phone])) {
        wp_send_json_error('المدعو غير موجود');
    }

    $new_code = function_exists('pge_generate_invite_code')
        ? pge_generate_invite_code()
        : strtoupper(substr(str_replace(['+','/','='],'',base64_encode(random_bytes(6))), 0, 4))
          . '-'
          . strtoupper(substr(str_replace(['+','/','='],'',base64_encode(random_bytes(6))), 0, 4));

    $guests_map[$phone]['code'] = $new_code;
    $saved_regen = pge_event_guests_save_map($event_id, $guests_map);
    // Strict Pre-Write Invalidation Contract Fix Pass — this legacy direct
    // caller previously ignored the return value entirely and always sent
    // wp_send_json_success(), even if the write never happened. save_map()
    // can now return null when the write was aborted BEFORE Post Meta was
    // touched — checked explicitly so this endpoint never reports success
    // for a write that never occurred. Generic, PII-free message only.
    if (!is_array($saved_regen)) {
        wp_send_json_error('تعذّر تنفيذ العملية، يرجى المحاولة مجدداً');
    }

    wp_send_json_success([
        'message' => 'تم توليد رمز جديد',
        'phone'   => $phone,
        'code'    => $new_code,
    ]);
});

/**
 * RC1 Fix Pack 3B — نفس التفويض/عدم-تكرار-منطق-الحذف الموثَّق أعلى المعالج
 * المفرد مباشرة. "Reuse the same deletion path repeatedly. Do not create a
 * second delete algorithm." — حلقة تستدعي PGE_Invitation_Service::delete()
 * مرة لكل هاتف، تماماً كما يفعل معالج الحذف الجماعي في الإضافة الجديدة
 * (pge_invitation_mgmt_bulk_delete_handler) — **نفس** الدالة الأساسية،
 * مُستدعاة من نقطتين مختلفتين فقط، لا نسخة ثانية من منطق الحذف نفسه.
 */
add_action('wp_ajax_pge_event_guest_bulk_delete', function () {
    $event_id = pge_event_guests_validate_request();

    $phones_raw = $_POST['phones'] ?? '';
    if (is_array($phones_raw)) {
        $candidates = $phones_raw;
    } else {
        $candidates = preg_split('/[\s,]+/', (string) $phones_raw);
    }

    $phones = [];
    foreach ((array) $candidates as $candidate) {
        $phone = pge_event_guests_norm_phone($candidate);
        if ($phone !== '') $phones[$phone] = $phone;
    }
    $phones = array_values($phones);

    if (empty($phones)) {
        wp_send_json_error('اختر مدعوين للحذف');
    }

    if (!class_exists('PGE_Invitation_Service')) {
        wp_send_json_error('تعذّر تنفيذ العملية');
    }

    $actor_user_id = get_current_user_id();
    $deleted = 0;
    foreach ($phones as $phone) {
        $result = PGE_Invitation_Service::delete($event_id, $phone, $actor_user_id);
        if (($result['result'] ?? '') === 'deleted') {
            $deleted++;
        }
    }

    wp_send_json_success([
        'message' => sprintf('تم حذف %d مدعو.', $deleted),
        'deleted' => $deleted,
        'stats'   => pge_event_guests_get_stats($event_id),
    ]);
});
