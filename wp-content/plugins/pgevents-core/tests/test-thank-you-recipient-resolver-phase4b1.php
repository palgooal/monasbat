<?php
/**
 * Central standalone verification for Phase 4B-1 — Manual Thank You
 * Recipient Resolver. This suite loads the production resolver and exercises
 * read-only eligibility without WordPress, MySQL, Claim, send, AJAX, or UI.
 *
 * Run: php tests/test-thank-you-recipient-resolver-phase4b1.php
 */

define('ABSPATH', __DIR__ . '/');

$total = 0;
$passed = 0;
$failures = [];

function check($label, $actual, $expected)
{
    global $total, $passed, $failures;
    $total++;
    if ($actual === $expected) {
        $passed++;
        return;
    }
    $failures[] = $label . ' — expected: ' . var_export($expected, true)
        . ' | actual: ' . var_export($actual, true);
}

function check_true($label, $condition)
{
    check($label, (bool) $condition, true);
}

$GLOBALS['ty_guest_maps'] = [];
$GLOBALS['ty_invitations'] = [];
$GLOBALS['ty_rsvps'] = [];
$GLOBALS['ty_write_count'] = 0;

function pge_norm_phone($value)
{
    return preg_replace('/\D+/', '', trim((string) $value));
}

function pge_event_guests_get_map($event_id)
{
    return $GLOBALS['ty_guest_maps'][(int) $event_id] ?? [];
}

function pge_rsvp_find_canonical_by_phone($event_id, $phone)
{
    $key = (int) $event_id . ':' . pge_norm_phone($phone);
    if (!array_key_exists($key, $GLOBALS['ty_rsvps'])) {
        return ['status' => 'not_found', 'row' => null];
    }
    if ($GLOBALS['ty_rsvps'][$key] === 'integrity_error') {
        return ['status' => 'integrity_error', 'row' => null];
    }
    return ['status' => 'found', 'row' => (object) $GLOBALS['ty_rsvps'][$key]];
}

class PGE_Invitation_Repository
{
    public static function get_invitation($event_id, $phone): ?array
    {
        $phone = pge_norm_phone($phone);
        $map = pge_event_guests_get_map((int) $event_id);
        if (!isset($map[$phone])) {
            return null;
        }
        $key = (int) $event_id . ':' . $phone;
        return array_merge(
            ['invitation_status' => 'active', 'invited_at' => ''],
            $GLOBALS['ty_invitations'][$key] ?? []
        );
    }

    public static function is_rsvp_row_current($event_id, $phone, $created_at): bool
    {
        $invitation = self::get_invitation($event_id, $phone);
        if (!is_array($invitation)) {
            return false;
        }
        $invited_at = (string) ($invitation['invited_at'] ?? '');
        $created_at = is_scalar($created_at) ? (string) $created_at : '';
        return $invited_at === '' || $created_at === '' || $created_at >= $invited_at;
    }
}

require_once dirname(__DIR__) . '/includes/class-pge-message-type.php';
require_once dirname(__DIR__) . '/includes/class-pge-message-recipient-resolver.php';

function ty_guest($phone, $name)
{
    return ['phone' => $phone, 'name' => $name, 'code' => 'IGNORED'];
}

function ty_rsvp($id, $checked_in, $reply, $created_at, $sent_at = null)
{
    return [
        'id' => $id,
        'checked_in' => $checked_in,
        'reply' => $reply,
        'created_at' => $created_at,
        'thank_you_sent_at' => $sent_at,
    ];
}

function ty_phones(array $result): array
{
    return array_column($result['recipients'], 'phone');
}

// Mixed eligibility fixture.
$event = 100;
$GLOBALS['ty_guest_maps'][$event] = [
    '966500000001' => ty_guest('966500000001', 'Yes + checked in'),
    '966500000002' => ty_guest('966500000002', 'Yes but not checked in'),
    '966500000003' => ty_guest('966500000003', 'No + checked in'),
    '966500000004' => ty_guest('966500000004', 'Pending + checked in'),
    '966500000005' => ty_guest('966500000005', 'No RSVP'),
    '966500000006' => ty_guest('966500000006', 'Integrity error'),
    '966500000007' => ty_guest('966500000007', 'Stale lifecycle'),
    '966500000008' => ty_guest('966500000008', 'Cancelled'),
    '966500000009' => ty_guest('966500000009', 'Already sent'),
    '966500000010' => ty_guest('966500000010', 'Invalid RSVP id'),
    '966500000011' => ty_guest('966 500 000 011', 'Formatted phone'),
    '' => ty_guest('', 'Invalid phone'),
];

foreach (['001', '002', '003', '004', '005', '006', '007', '008', '009', '010', '011'] as $suffix) {
    $phone = '966500000' . $suffix;
    $GLOBALS['ty_invitations'][$event . ':' . $phone] = [
        'invitation_status' => 'active',
        'invited_at' => '2026-08-10 10:00:00',
    ];
}
$GLOBALS['ty_invitations'][$event . ':966500000008']['invitation_status'] = 'cancelled';

$GLOBALS['ty_rsvps'][$event . ':966500000001'] = ty_rsvp(101, 1, 'yes', '2026-08-10 10:01:00');
$GLOBALS['ty_rsvps'][$event . ':966500000002'] = ty_rsvp(102, 0, 'yes', '2026-08-10 10:01:00');
$GLOBALS['ty_rsvps'][$event . ':966500000003'] = ty_rsvp(103, 1, 'no', '2026-08-10 10:01:00');
$GLOBALS['ty_rsvps'][$event . ':966500000004'] = ty_rsvp(104, 1, '', '2026-08-10 10:01:00');
$GLOBALS['ty_rsvps'][$event . ':966500000006'] = 'integrity_error';
$GLOBALS['ty_rsvps'][$event . ':966500000007'] = ty_rsvp(107, 1, 'yes', '2026-08-10 09:59:59');
$GLOBALS['ty_rsvps'][$event . ':966500000008'] = ty_rsvp(108, 1, 'yes', '2026-08-10 10:01:00');
$GLOBALS['ty_rsvps'][$event . ':966500000009'] = ty_rsvp(109, 1, 'yes', '2026-08-10 10:01:00', '2026-08-10 11:00:00');
$GLOBALS['ty_rsvps'][$event . ':966500000010'] = ty_rsvp(0, 1, 'yes', '2026-08-10 10:01:00');
$GLOBALS['ty_rsvps'][$event . ':966500000011'] = ty_rsvp(111, 1, 'yes', '2026-08-10 10:01:00');

$before_writes = $GLOBALS['ty_write_count'];
$result = PGE_Message_Recipient_Resolver::resolve($event, 'thank_you', 'ignored');

check('message type is thank_you', $result['message_type'], 'thank_you');
check('Thank You uses fixed checked_in filter', $result['filter'], 'checked_in');
check('current invitations count excludes invalid phone', $result['total_current_invitations'], 11);
check('eligible count', $result['eligible'], 5);
check('recipient phones', ty_phones($result), [
    '966500000001',
    '966500000003',
    '966500000004',
    '966500000009',
    '966500000011',
]);
check('recipient payload carries canonical rsvp id', $result['recipients'][0]['rsvp_id'], 101);
check('recipient payload carries guest name', $result['recipients'][0]['name'], 'Yes + checked in');
check('recipient payload does not expose invitation code', array_key_exists('code', $result['recipients'][0]), false);
check('reply=yes is not enough without check-in', in_array('966500000002', ty_phones($result), true), false);
check('reply=no does not block checked-in guest', in_array('966500000003', ty_phones($result), true), true);
check('pending reply does not block checked-in guest', in_array('966500000004', ty_phones($result), true), true);
check('thank_you_sent_at does not affect resolver eligibility', in_array('966500000009', ty_phones($result), true), true);
check('invalid phone metric', $result['skipped_invalid_phone'], 1);
check('cancelled metric', $result['skipped_cancelled'], 1);
check('not checked in metric', $result['skipped_not_checked_in'], 1);
check('missing or invalid RSVP metric', $result['skipped_no_rsvp'], 2);
check('integrity error metric', $result['skipped_integrity_error'], 1);
check('stale lifecycle metric', $result['skipped_stale_lifecycle'], 1);
check('resolver performs no writes', $GLOBALS['ty_write_count'], $before_writes);
check('count delegates to eligible recipients', PGE_Message_Recipient_Resolver::count($event, 'thank_you', 'all'), 5);

// A checked-in orphan row is ineligible after hard deletion because no current invitation exists.
$GLOBALS['ty_rsvps']['200:966511111111'] = ty_rsvp(201, 1, 'yes', '2026-08-10 08:00:00');
$hard_deleted = PGE_Message_Recipient_Resolver::resolve(200, 'thank_you', 'all');
check('hard-deleted orphan RSVP is excluded', count($hard_deleted['recipients']), 0);
check('hard-deleted orphan is not a current invitation', $hard_deleted['total_current_invitations'], 0);

// Reinvite starts a new lifecycle. Reset row is not eligible until the new lifecycle checks in.
$GLOBALS['ty_guest_maps'][200] = ['966511111111' => ty_guest('966511111111', 'Reinvited')];
$GLOBALS['ty_invitations']['200:966511111111'] = ['invitation_status' => 'active', 'invited_at' => '2026-08-10 12:00:00'];
$GLOBALS['ty_rsvps']['200:966511111111'] = ty_rsvp(201, 0, '', '2026-08-10 12:00:00');
$reinvited_reset = PGE_Message_Recipient_Resolver::resolve(200, 'thank_you', 'all');
check('reinvited reset row is not eligible', count($reinvited_reset['recipients']), 0);
check('reinvited reset row counts as not checked in', $reinvited_reset['skipped_not_checked_in'], 1);
$GLOBALS['ty_rsvps']['200:966511111111']['checked_in'] = 1;
$reinvited_checked = PGE_Message_Recipient_Resolver::resolve(200, 'thank_you', 'all');
check('reinvited guest becomes eligible after new check-in', ty_phones($reinvited_checked), ['966511111111']);
check('reinvited recipient keeps canonical RSVP id', $reinvited_checked['recipients'][0]['rsvp_id'], 201);

// Phone change: old identity is no longer current; target identity must check in anew.
$GLOBALS['ty_guest_maps'][300] = ['966522222222' => ty_guest('966522222222', 'New phone')];
$GLOBALS['ty_invitations']['300:966522222222'] = ['invitation_status' => 'active', 'invited_at' => '2026-08-10 14:00:00'];
$GLOBALS['ty_rsvps']['300:966533333333'] = ty_rsvp(301, 1, 'yes', '2026-08-10 13:00:00');
$GLOBALS['ty_rsvps']['300:966522222222'] = ty_rsvp(302, 0, '', '2026-08-10 14:00:00');
$phone_changed = PGE_Message_Recipient_Resolver::resolve(300, 'thank_you', 'all');
check('old checked-in phone does not leak into current recipients', in_array('966533333333', ty_phones($phone_changed), true), false);
check('phone-change target is excluded before check-in', count($phone_changed['recipients']), 0);
$GLOBALS['ty_rsvps']['300:966522222222']['checked_in'] = 1;
$phone_changed_checked = PGE_Message_Recipient_Resolver::resolve(300, 'thank_you', 'all');
check('phone-change target becomes eligible after check-in', ty_phones($phone_changed_checked), ['966522222222']);
check('phone-change target returns its canonical id', $phone_changed_checked['recipients'][0]['rsvp_id'], 302);

// Legacy records with a missing timestamp preserve the central compatibility contract.
$GLOBALS['ty_guest_maps'][400] = ['966544444444' => ty_guest('966544444444', 'Legacy')];
$GLOBALS['ty_invitations']['400:966544444444'] = ['invitation_status' => 'active', 'invited_at' => ''];
$GLOBALS['ty_rsvps']['400:966544444444'] = ty_rsvp(401, 1, 'yes', '');
$legacy = PGE_Message_Recipient_Resolver::resolve(400, 'thank_you', 'all');
check('legacy missing timestamps follow central lifecycle compatibility', ty_phones($legacy), ['966544444444']);

// Event isolation: an eligible RSVP in another event must not affect this event.
$GLOBALS['ty_guest_maps'][500] = ['966555555555' => ty_guest('966555555555', 'Other event')];
$GLOBALS['ty_invitations']['500:966555555555'] = ['invitation_status' => 'active', 'invited_at' => '2026-08-10 16:00:00'];
$GLOBALS['ty_rsvps']['500:966555555555'] = ty_rsvp(501, 1, 'yes', '2026-08-10 16:00:00');
$other_event = PGE_Message_Recipient_Resolver::resolve(500, 'thank_you', 'all');
check('eligible guest resolves within its own event', ty_phones($other_event), ['966555555555']);
check('unrelated event guest is absent from original event', in_array('966555555555', ty_phones($result), true), false);
check('unrelated ineligible guest does not affect an eligible peer', in_array('966500000001', ty_phones($result), true), true);

echo "Thank You Recipient Resolver Phase 4B-1: {$passed}/{$total} passed\n";
if ($failures) {
    foreach ($failures as $failure) {
        echo "FAIL: {$failure}\n";
    }
    exit(1);
}
exit(0);
