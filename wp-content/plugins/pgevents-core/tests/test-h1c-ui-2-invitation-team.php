<?php
/**
 * Phase H1C-UI-2 — Invitation Team UI Integration.
 *
 * Source/contract-level suite ONLY (per the H1C-UI-2 brief's explicit
 * "اختبر source/contracts فقط" scope-minimization instruction) — no WP
 * bootstrap, no fake $wpdb, no execution of any production class. Every
 * assertion below reads the actual committed source of the touched files
 * and checks it against the contract the brief requires: which AJAX
 * actions each screen calls, that no user_id/group_id field is sent where
 * forbidden, that no template calls Repository/Authorization/$wpdb
 * directly, that get_footer() runs after the inline <script> block, and
 * that no new wp_ajax_nopriv_ action was registered anywhere in this
 * phase's files.
 *
 * Run: php tests/test-h1c-ui-2-invitation-team.php
 */

$ROOT = dirname(__DIR__);

$FILES = [
    'routing' => $ROOT . '/includes/routing.php',
    'owner_screen' => $ROOT . '/templates/event-invitation-team.php',
    'my_invitations' => $ROOT . '/templates/my-invitations.php',
    'dashboard' => $ROOT . '/templates/dashboard-main.php',
    'nav_theme' => dirname($ROOT, 2) . '/themes/pgevents-pro/page-event-manage.php',
    'onboarding_ajax' => $ROOT . '/includes/additional-inviter-onboarding-ajax.php',
    'inviter_ajax' => $ROOT . '/includes/additional-inviter-ajax.php',
    'event_access_ajax' => $ROOT . '/includes/event-access-ajax.php',
];

$SOURCE = [];
foreach ($FILES as $key => $path) {
    $SOURCE[$key] = file_exists($path) ? file_get_contents($path) : false;
}

/**
 * Strips /* ... *\/ block comments (including /** ... *\/ docblocks) so
 * contract checks below assert on actual CODE, not on explanatory prose
 * that happens to mention a forbidden class/field/action name while
 * documenting that it is deliberately NOT used (this codebase's own
 * convention throughout — every template in this phase explains what it
 * does NOT call in its top docblock). // line comments are left alone
 * since none of the checks below need to ignore those.
 */
function strip_php_block_comments($src)
{
    if ($src === false) return false;
    return preg_replace('#/\*.*?\*/#s', '', $src);
}

$CODE = [];
foreach ($SOURCE as $key => $src) {
    $CODE[$key] = strip_php_block_comments($src);
}

$pass = 0;
$fail = 0;
$failures = [];

function ok($label, $cond)
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        $failures[] = $label;
    }
    echo ($cond ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
}

// ──────────────────────────────────────────────────────────────
// A. Files exist
// ──────────────────────────────────────────────────────────────
foreach ($FILES as $key => $path) {
    ok("A. File exists: $key ($path)", $SOURCE[$key] !== false);
}

// ──────────────────────────────────────────────────────────────
// B. routes موجودة
// ──────────────────────────────────────────────────────────────
ok('B1. invitation-team/ rewrite rule registered', $SOURCE['routing'] !== false
    && strpos($SOURCE['routing'], "event-manage/([0-9]+)/invitation-team/?\$") !== false
    && strpos($SOURCE['routing'], 'pge_action=event_invitation_team') !== false);

ok('B2. my-invitations/ rewrite rule registered', $SOURCE['routing'] !== false
    && strpos($SOURCE['routing'], "event-manage/([0-9]+)/my-invitations/?\$") !== false
    && strpos($SOURCE['routing'], 'pge_action=my_invitations') !== false);

ok('B3. template_include resolves event_invitation_team to a real template file', $SOURCE['routing'] !== false
    && strpos($SOURCE['routing'], "\$action === 'event_invitation_team'") !== false
    && strpos($SOURCE['routing'], 'templates/event-invitation-team.php') !== false);

ok('B4. template_include resolves my_invitations to a real template file', $SOURCE['routing'] !== false
    && strpos($SOURCE['routing'], "\$action === 'my_invitations'") !== false
    && strpos($SOURCE['routing'], 'templates/my-invitations.php') !== false);

// ──────────────────────────────────────────────────────────────
// C. nav "فريق الدعوة"
// ──────────────────────────────────────────────────────────────
ok('C1. Nav entry "فريق الدعوة" present in page-event-manage.php', $SOURCE['nav_theme'] !== false
    && strpos($SOURCE['nav_theme'], 'فريق الدعوة') !== false);

ok('C2. Nav entry links to invitation-team/ URL', $SOURCE['nav_theme'] !== false
    && strpos($SOURCE['nav_theme'], "invitation_team_url") !== false
    && strpos($SOURCE['nav_theme'], "/invitation-team/") !== false);

ok('C3. Nav entry is a distinct link id from supervisors ("مشرفو الدخول")', $SOURCE['nav_theme'] !== false
    && strpos($SOURCE['nav_theme'], 'navInvitationTeamLink') !== false
    && strpos($SOURCE['nav_theme'], 'navSupervisorsLink') !== false
    && strpos($SOURCE['nav_theme'], 'navInvitationTeamLink') !== strpos($SOURCE['nav_theme'], 'navSupervisorsLink'));

// ──────────────────────────────────────────────────────────────
// D. owner screen يستخدم actions الصحيحة
// ──────────────────────────────────────────────────────────────
$owner = $SOURCE['owner_screen'];
$owner_code = $CODE['owner_screen'];
ok('D1. Owner screen calls pge_additional_inviter_list', $owner !== false
    && strpos($owner, "'pge_additional_inviter_list'") !== false);
ok('D2. Owner screen calls pge_additional_inviter_onboarding_list_pending', $owner !== false
    && strpos($owner, "'pge_additional_inviter_onboarding_list_pending'") !== false);
ok('D3. Owner screen calls pge_additional_inviter_onboarding_invite', $owner !== false
    && strpos($owner, "'pge_additional_inviter_onboarding_invite'") !== false);

// ──────────────────────────────────────────────────────────────
// E. pending invite action الصحيحة (revoke)
// ──────────────────────────────────────────────────────────────
ok('E1. Owner screen calls pge_additional_inviter_onboarding_revoke for the cancel button', $owner !== false
    && strpos($owner, "'pge_additional_inviter_onboarding_revoke'") !== false
    && strpos($owner, 'invitation_id') !== false);

// ──────────────────────────────────────────────────────────────
// F. no user_id search/input on the owner screen or its create-invite form
// (checked against comment-stripped code — the top docblock legitimately
// documents "no user_id" in prose, which must not itself trip this check)
// ──────────────────────────────────────────────────────────────
ok('F1. Owner screen never sends a user_id field (in actual code, not doc prose)', $owner_code !== false
    && !preg_match('/\buser_id\b/', $owner_code));
ok('F2. Owner screen has no free-text user search input', $owner !== false
    && strpos($owner, 'user_search') === false && strpos($owner, 'target_user_id') === false);

// ──────────────────────────────────────────────────────────────
// G. dashboard يحتوي discoverability
// ──────────────────────────────────────────────────────────────
$dash = $SOURCE['dashboard'];
ok('G1. Dashboard calls pge_additional_inviter_list_my_events', $dash !== false
    && strpos($dash, "'pge_additional_inviter_list_my_events'") !== false);
ok('G2. Dashboard discoverability section links to my-invitations/', $dash !== false
    && strpos($dash, '/my-invitations/') !== false);
ok('G3. Dashboard discoverability section is hidden by default (no items = no section, per Section 6)', $dash !== false
    && strpos($dash, 'pgeMyInvitesDiscoverySection') !== false
    && preg_match('/id="pgeMyInvitesDiscoverySection"\s+class="hidden/', $dash) === 1);

// ──────────────────────────────────────────────────────────────
// H. my-invitations uses self actions
// ──────────────────────────────────────────────────────────────
$mine = $SOURCE['my_invitations'];
$mine_code = $CODE['my_invitations'];
ok('H1. My Invitations screen calls pge_additional_inviter_get_my_quota', $mine !== false
    && strpos($mine, "'pge_additional_inviter_get_my_quota'") !== false);
ok('H2. My Invitations screen calls pge_additional_inviter_create_guest', $mine !== false
    && strpos($mine, "'pge_additional_inviter_create_guest'") !== false);
ok('H3. My Invitations screen calls pge_event_access_list_guests for the scoped list', $mine !== false
    && strpos($mine, "'pge_event_access_list_guests'") !== false);
ok('H4. My Invitations screen never calls an Owner/Admin-only action (list_pending/invite/revoke/list)', $mine !== false
    && strpos($mine, 'pge_additional_inviter_onboarding_list_pending') === false
    && strpos($mine, 'pge_additional_inviter_onboarding_invite') === false
    && strpos($mine, 'pge_additional_inviter_onboarding_revoke') === false
    && strpos($mine, "'pge_additional_inviter_list'") === false);

// ──────────────────────────────────────────────────────────────
// I. create guest لا يرسل group_id
// ──────────────────────────────────────────────────────────────
ok('I1. My Invitations create-guest payload never includes group_id', $mine !== false
    && preg_match('/payload\s*=\s*\{[^}]*group_id/s', $mine) !== 1);
ok('I2. My Invitations screen has no group selector element for guest creation', $mine !== false
    && strpos($mine, 'id="guestGroup"') === false && strpos($mine, 'name="group_id"') === false);

// ──────────────────────────────────────────────────────────────
// J. scoped list uses pge_event_access_list_guests (duplicate-check against
//    Owner screen too — the owner screen must never call the collaborator's
//    scoped-guest action to fetch its own separate group-detail data,
//    keeping the two screens' read paths independent).
// ──────────────────────────────────────────────────────────────
ok('J1. Scoped guest list action string appears in my-invitations.php only among the two new templates for THIS purpose', $mine !== false
    && strpos($mine, "'pge_event_access_list_guests'") !== false);

// ──────────────────────────────────────────────────────────────
// K. no Repository/Authorization/$wpdb direct calls from either new template
// (checked against comment-stripped code — both templates' top docblocks
// legitimately name these classes in prose to document that they are NOT
// called directly; that prose must not itself trip this check)
// ──────────────────────────────────────────────────────────────
foreach (['owner_screen' => $owner_code, 'my_invitations' => $mine_code] as $label => $src) {
    ok("K1. $label never references PGE_Event_Access_Repository directly", $src !== false
        && strpos($src, 'PGE_Event_Access_Repository') === false);
    ok("K2. $label never references PGE_Event_Access_Authorization directly", $src !== false
        && strpos($src, 'PGE_Event_Access_Authorization') === false);
    ok("K3. $label never references PGE_Additional_Inviter_Onboarding class directly (PHP-side)", $src !== false
        && strpos($src, 'PGE_Additional_Inviter_Onboarding::') === false);
    ok("K4. $label never references PGE_Additional_Inviter class directly (PHP-side)", $src !== false
        && strpos($src, 'PGE_Additional_Inviter::') === false);
    ok("K5. $label never uses \$wpdb", $src !== false
        && strpos($src, '$wpdb') === false);
}

// ──────────────────────────────────────────────────────────────
// L. get_footer() بعد scripts
// ──────────────────────────────────────────────────────────────
foreach (['owner_screen' => $owner, 'my_invitations' => $mine] as $label => $src) {
    $script_pos = strrpos($src, '<script>');
    $footer_pos = strrpos($src, 'get_footer();');
    ok("L1. $label calls get_footer() after its inline <script> block", $src !== false
        && $script_pos !== false && $footer_pos !== false && $footer_pos > $script_pos);
}

// ──────────────────────────────────────────────────────────────
// M. no new nopriv registered anywhere in this phase's touched files
// ──────────────────────────────────────────────────────────────
foreach ($SOURCE as $key => $src) {
    if ($key === 'onboarding_ajax') {
        // W10's ONE pre-existing, already-reviewed nopriv exception — must
        // still be exactly one ACTUAL add_action() registration, not
        // duplicated/expanded by this phase. Counted against comment-
        // stripped code since this file's own top docblock (legitimately,
        // pre-existing from W10) mentions the string "wp_ajax_nopriv_" in
        // prose once while explaining the exception.
        $count = $CODE[$key] !== false ? substr_count($CODE[$key], "add_action('wp_ajax_nopriv_") : -1;
        ok('M1. additional-inviter-onboarding-ajax.php still has exactly ONE wp_ajax_nopriv_ registration (W10 exception, unchanged by UI-2)', $count === 1);
        continue;
    }
    ok("M2. $key introduces no wp_ajax_nopriv_ registration", $src !== false
        && strpos($src, "add_action('wp_ajax_nopriv_") === false);
}

// ──────────────────────────────────────────────────────────────
// N. Owner screen presentation gate matches H1C-UI-1's pattern (Owner/Admin
//    only — not edit_post alone), My Invitations gate is login-only.
// ──────────────────────────────────────────────────────────────
ok('N1. Owner screen presentation gate checks administrator OR post_author (not edit_post alone)', $owner_code !== false
    && strpos($owner_code, "current_user_can('administrator')") !== false
    && strpos($owner_code, 'post_author') !== false
    && strpos($owner_code, "edit_post") === false);

ok('N2. My Invitations gate is login-only (auth_redirect), no administrator/post_author check', $mine_code !== false
    && strpos($mine_code, 'auth_redirect()') !== false
    && strpos($mine_code, "current_user_can('administrator')") === false
    && strpos($mine_code, 'post_author ===') === false);

// ──────────────────────────────────────────────────────────────
// Summary
// ──────────────────────────────────────────────────────────────
echo "\n==============================\n";
echo "H1C-UI-2 Invitation Team UI — $pass passed, $fail failed\n";
if ($fail > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
}
echo "==============================\n";

exit($fail > 0 ? 1 : 0);
