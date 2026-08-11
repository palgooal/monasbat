<?php
/**
 * Structural and JavaScript-contract verification for Phase 4B-3B.
 *
 * This reads the production invitation-management template only. It performs
 * no WordPress bootstrap, HTTP request, database write, Cron tick, or message
 * transport call.
 *
 * Run: php tests/test-thank-you-ui-phase4b3b.php
 */

$total = 0;
$passed = 0;
$failures = [];

function check_ui(string $label, bool $condition): void
{
    global $total, $passed, $failures;
    $total++;
    if ($condition) {
        $passed++;
        return;
    }
    $failures[] = $label;
}

$template_path = __DIR__ . '/../templates/event-invitations.php';
$source = file_exists($template_path) ? (string) file_get_contents($template_path) : '';
$markup_start = strpos($source, '<!-- ══ Messaging Architecture Phase 4B-3B — Manual Thank You UI ══ -->');
$markup_end = strpos($source, "\n</div>\n<?php get_footer(); ?>", $markup_start === false ? 0 : $markup_start);
$ui_markup = ($markup_start !== false && $markup_end !== false)
    ? substr($source, $markup_start, $markup_end - $markup_start)
    : '';
$script_start = strpos($source, '// Messaging Architecture Phase 4B-3B — Manual Thank You UI');
$script_end = strpos($source, '  renderRows(INITIAL.items);', $script_start === false ? 0 : $script_start);
$ui_script = ($script_start !== false && $script_end !== false)
    ? substr($source, $script_start, $script_end - $script_start)
    : '';

check_ui('1. production template is readable', $source !== '');
check_ui('2. Thank You button exists', strpos($source, 'id="openThankYouBtn"') !== false);
check_ui('3. Thank You button uses type=button', preg_match('/<button[^>]*type="button"[^>]*id="openThankYouBtn"/', $source) === 1);
check_ui('4. button label is explicit', strpos($source, 'إرسال شكر للحاضرين') !== false);
check_ui('5. button declares modal ownership', strpos($source, 'aria-controls="thankYouModal"') !== false);
check_ui('6. independent Thank You modal exists', strpos($ui_markup, 'id="thankYouModal"') !== false);
check_ui('7. modal is an accessible dialog', strpos($ui_markup, 'role="dialog"') !== false && strpos($ui_markup, 'aria-modal="true"') !== false);
check_ui('8. modal has labelled heading', strpos($ui_markup, 'aria-labelledby="thankYouHeading"') !== false);
check_ui('9. close control has an accessible name', strpos($ui_markup, 'aria-label="إغلاق نافذة إرسال الشكر"') !== false);

foreach ([
    'thankYouLoadingState',
    'thankYouReadyState',
    'thankYouStartingState',
    'thankYouProcessingState',
    'thankYouCompleteState',
    'thankYouErrorState',
] as $index => $state_id) {
    check_ui((10 + $index) . '. state exists: ' . $state_id, strpos($ui_markup, 'id="' . $state_id . '"') !== false);
}

check_ui('16. Preview action is used', substr_count($ui_script, "postAjax('pge_invitation_mgmt_thank_you_preview', {})") === 1);
check_ui('17. Start action is used', substr_count($ui_script, "postAjax('pge_invitation_mgmt_thank_you_start', {})") === 1);
check_ui('18. Status action sends only batch id beyond common request fields', substr_count($ui_script, "postAjax('pge_invitation_mgmt_thank_you_status', { batch_id: batchId })") === 1);
check_ui('19. Preview delegates nonce/event to the shared AJAX helper only', strpos($ui_script, "postAjax('pge_invitation_mgmt_thank_you_preview', {})") !== false);
check_ui('20. Start delegates nonce/event to the shared AJAX helper only', strpos($ui_script, "postAjax('pge_invitation_mgmt_thank_you_start', {})") !== false);
check_ui('21. polling interval is 4000ms', preg_match('/thankYouPollStatus\(batchId\);\s*\}, 4000\);/', $ui_script) === 1);
check_ui('22. schedule clears any prior timer first', preg_match('/function thankYouSchedulePoll\(batchId\)\s*\{\s*thankYouStopPolling\(\);/', $ui_script) === 1);
check_ui('23. complete status stops polling', preg_match('/res\.data\.complete[\s\S]*?thankYouStopPolling\(\);[\s\S]*?thankYouRenderComplete/', $ui_script) === 1);
check_ui('24. closing modal stops polling', preg_match('/function thankYouCloseModal\(\)[\s\S]*?thankYouStopPolling\(\);/', $ui_script) === 1);
check_ui('25. page unload stops polling', strpos($ui_script, "window.addEventListener('beforeunload', thankYouStopPolling)") !== false);
check_ui('26. start is guarded against double submit', strpos($ui_script, 'if (thankYouStartInFlight || startThankYouBtn.disabled) return;') !== false);
check_ui('27. Start button is disabled synchronously', preg_match('/thankYouStartInFlight = true;\s*startThankYouBtn\.disabled = true;/', $ui_script) === 1);
check_ui('28. no eligible keeps Start disabled', strpos($ui_script, 'startThankYouBtn.disabled = eligible === 0;') !== false);
check_ui('29. eligible enables Start through the same boolean contract', strpos($ui_script, 'startThankYouBtn.disabled = eligible === 0;') !== false);
check_ui('30. no-eligible state is user-facing, not technical error', strpos($ui_markup, 'لا يوجد حضور مؤهل لإرسال رسالة شكر حالياً.') !== false);
check_ui('31. active or new successful batch enters processing', preg_match('/thankYouBatchId = String[\s\S]*?thankYouShowState\(\'processing\'\)/', $ui_script) === 1);
check_ui('32. successful batch always resumes polling without rejecting existing=true', strpos($ui_script, 'thankYouSchedulePoll(thankYouBatchId);') !== false && strpos($ui_script, 'res.data.existing') === false);
check_ui('33. reopening creates a fresh Preview session', preg_match('/function thankYouOpenModal\(\)[\s\S]*?thankYouSession \+= 1;[\s\S]*?thankYouLoadPreview\(thankYouSession\);/', $ui_script) === 1);
check_ui('34. closing invalidates stale callbacks', preg_match('/function thankYouCloseModal\(\)[\s\S]*?thankYouSession \+= 1;/', $ui_script) === 1);
check_ui('35. preview text is rendered read-only', strpos($ui_markup, 'id="thankYouPreviewText"') !== false && strpos($ui_markup, '<textarea') === false && strpos($ui_script, 'thankYouPreviewText.textContent = data.preview_text') !== false);
check_ui('36. modal renders no input controls or recipient filters', strpos($ui_markup, '<input') === false && strpos($ui_markup, '<select') === false);
check_ui('37. UI scope contains no phone rendering', stripos($ui_markup . $ui_script, 'phone') === false);
check_ui('38. UI scope contains no RSVP id rendering', stripos($ui_markup . $ui_script, 'rsvp_id') === false);
check_ui('39. UI scope contains no lifecycle marker rendering', stripos($ui_markup . $ui_script, 'lifecycle_started_at') === false);
check_ui('40. UI scope contains no raw manifest rendering', stripos($ui_markup . $ui_script, 'manifest') === false);
check_ui('41. UI scope contains no Credits UX', stripos($ui_markup . $ui_script, 'credit') === false && strpos($ui_markup . $ui_script, 'رصيد') === false);
check_ui('42. UI scope contains no Retry control or endpoint', stripos($ui_markup . $ui_script, 'retry') === false && stripos($ui_markup . $ui_script, 'thank_you_retry') === false);
check_ui('43. UI scope contains no image/media option', strpos($ui_markup, '<img') === false && stripos($ui_script, 'image_url') === false);
check_ui('44. ambiguous copy does not call the outcome failed', strpos($ui_script, "'تعذر تأكيد حالة ' + ambiguous + ' من الرسائل.'") !== false);
check_ui('45. confirmed failures have separate copy', strpos($ui_script, "'تعذر إرسال ' + failed + ' رسالة.'") !== false);
check_ui('46. skipped copy hides technical reason codes', strpos($ui_script, "' لأنها لم تعد مؤهلة أو سبق إرسال الشكر لها.'") !== false && strpos($ui_script, 'skipped_reasons') === false);
check_ui('47. partial completion avoids absolute success copy', strpos($ui_script, 'اكتملت معالجة رسائل الشكر مع نتائج تحتاج إلى المراجعة.') !== false);
check_ui('48. provider credentials error is safe and clear', strpos($ui_script, "no_provider_credentials: 'خدمة إرسال الرسائل غير مهيأة حالياً.'") !== false);
check_ui('49. server errors are mapped before display', strpos($ui_script, 'THANK_YOU_ERROR_MESSAGES[reason] || fallback') !== false);
check_ui('50. preview and status values use textContent', strpos($ui_script, 'thankYouEligibleCount.textContent') !== false && strpos($ui_script, 'thankYouProcessingProgress.textContent') !== false);
check_ui('51. Escape closes the modal', strpos($ui_script, "event.key === 'Escape'") !== false && strpos($ui_script, 'thankYouCloseModal();') !== false);
check_ui('52. open focuses the close button', strpos($ui_script, 'closeThankYouBtn.focus();') !== false);
check_ui('53. close restores prior focus', strpos($ui_script, 'thankYouLastFocusedElement.focus();') !== false);
check_ui('54. trigger expanded state is synchronized', strpos($ui_script, "openThankYouBtn.setAttribute('aria-expanded', 'true')") !== false && strpos($ui_script, "openThankYouBtn.setAttribute('aria-expanded', 'false')") !== false);
check_ui('55. loading state exposes busy semantics', strpos($ui_script, "thankYouModal.setAttribute('aria-busy'") !== false);
check_ui('56. Reminder button remains present', strpos($source, 'id="openReminderBtn"') !== false);
check_ui('57. Reminder modal remains present', strpos($source, 'id="reminderModal"') !== false);
check_ui('58. Reminder JavaScript remains present', strpos($source, 'function reminderOpenModal()') !== false && strpos($source, 'function reminderPollStatus(') !== false);
check_ui('59. Thank You state names stay isolated', strpos($ui_script, 'var reminderPollTimer') === false && strpos($ui_script, 'var thankYouPollTimer') !== false);
check_ui('60. shared helper remains server-authoritative for nonce and event', strpos($source, "body.set('nonce', CONFIG.nonce)") !== false && strpos($source, "body.set('event_id', CONFIG.eventId)") !== false);

echo "Thank You UI Phase 4B-3B: {$passed}/{$total} passed\n";
if ($failures) {
    foreach ($failures as $failure) {
        echo "FAIL: {$failure}\n";
    }
    exit(1);
}
exit(0);
