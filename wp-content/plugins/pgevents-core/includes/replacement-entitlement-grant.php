<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * ربط منح Replacement Entitlement من مسارات RSVP الحية — المرحلة 4B
 * ============================================================================
 * دالة مركزية واحدة تُستدعى من مسارين حيّين فقط:
 *   1) pge_save_rsvp_response()  في includes/rsvp-handler.php
 *   2) Mon_Cartat_Handler::record_rsvp() في includes/class-cartat-handler.php
 *
 * الهدف: عند انتقال RSVP حقيقي إلى 'no' (اعتذار)، امنح Replacement
 * Entitlement مرة واحدة فقط — بشرط وجود دعوة primary مُستهلَكة فعلياً لنفس
 * (user_id, credit_cycle_id, event_id, guest_phone) — عبر
 * PGE_Replacement_Entitlements::create_entitlement() حصراً. لا يوجد هنا أي
 * منطق منح مستقل خارج الـRepository — هذا الملف orchestration فقط: يتحقق من
 * أهلية الانتقال، يحدد مالك المناسبة، يطبّع الهاتف، يحدد صف primary المصدر،
 * ثم يستدعي create_entitlement() ويسجّل النتيجة.
 *
 * لا إرسال Replacement فعلي هنا، ولا استدعاء لأي من: Queue، Cron،
 * ajax_queue_start()، claim_for_delivery()، mark_consumed_with_token()/
 * mark_failed_with_token()، ولا سحب لأي استحقاق سابق (لا mark_voided()) —
 * خارج نطاق هذه المرحلة بالكامل.
 *
 * أمان الاستدعاء: هذه الدالة Side Effect **بعد** نجاح حفظ الرد، وليست جزءاً
 * من معاملة الحفظ نفسها — أي فشل أو استثناء داخلها (مُلتقَط بالكامل عبر
 * try/catch(\Throwable) أدناه) لا يجوز أن يُسقِط أو يُفشِل حفظ RSVP الذي
 * استدعاها. من هنا يستدعيها كلا المسارين **بعد** نجاح الـupsert فقط.
 */

if (!function_exists('pge_maybe_grant_replacement_entitlement')) {
    /**
     * @param int|string $event_id   معرّف المناسبة
     * @param string     $guest_phone رقم جوال الضيف (أي تنسيق — يُطبَّع داخلياً)
     * @param string|null $old_reply  الحالة السابقة قبل هذا الحفظ: null (لا صف
     *                                سابق) | 'pending' | 'yes' | 'no'
     * @param string     $new_reply   الحالة المحفوظة للتو: عادة 'no'، لكن
     *                                الدالة آمنة لاستدعائها بأي قيمة (تُرجع
     *                                skipped/transition_not_eligible فوراً
     *                                لأي قيمة غير 'no')
     * @return array{result:string,reason?:string,id?:int}
     */
    function pge_maybe_grant_replacement_entitlement($event_id, $guest_phone, $old_reply, $new_reply)
    {
        try {
            // ── 1. شرط الانتقال الحقيقي فقط ──────────────────────────────────
            // مؤهل: pending→no، yes→no، null(لا صف سابق)→no.
            // غير مؤهل: أي new_reply ≠ 'no'، وno→no تحديداً (لا يعيد محاولة
            // المنح من نفس المسار حتى مع أن create_entitlement() آمنة أصلاً
            // ضد التكرار — نتجنب استعلام DB غير ضروري بالكامل).
            $new_reply_normalized = is_string($new_reply) ? $new_reply : '';
            if ($new_reply_normalized !== 'no') {
                return ['result' => 'skipped', 'reason' => 'transition_not_eligible'];
            }

            // القيمة القديمة قد تكون null (لا صف)، أو 'pending'/'yes'/'no'، أو
            // أي شيء آخر غير متوقع (بيانات تالفة) — أي قيمة خارج الثلاث
            // المعروفة تُعامَل معاملة "ليست no" (مؤهلة)، دفاعياً، لأن الحالة
            // الوحيدة الواجب حجبها صراحة هي 'no' الحقيقية فقط.
            $old_reply_normalized = in_array($old_reply, ['pending', 'yes', 'no'], true) ? $old_reply : null;
            if ($old_reply_normalized === 'no') {
                return ['result' => 'skipped', 'reason' => 'transition_not_eligible'];
            }

            // ── 2. مناسبة صالحة ──────────────────────────────────────────────
            $normalized_event_id = 0;
            if (is_int($event_id) && $event_id > 0) {
                $normalized_event_id = $event_id;
            } elseif (is_string($event_id) && preg_match('/^[1-9][0-9]*$/', $event_id)) {
                $normalized_event_id = (int) $event_id;
            }

            if ($normalized_event_id === 0 || get_post_type($normalized_event_id) !== 'pge_event') {
                error_log('[replacement_entitlement_skipped] reason=invalid_event event_id=' . var_export($event_id, true));
                return ['result' => 'skipped', 'reason' => 'invalid_event'];
            }

            // ── 3. مالك المناسبة — post_author حصراً، لا current_user/cookie ──
            $user_id = (int) get_post_field('post_author', $normalized_event_id);
            if ($user_id <= 0) {
                error_log("[replacement_entitlement_skipped] reason=invalid_owner event_id={$normalized_event_id}");
                return ['result' => 'skipped', 'reason' => 'invalid_owner'];
            }

            // ── 4. تطبيع الهاتف — نفس منطق Ledger/Entitlements بالضبط ────────
            $normalized_phone = function_exists('pge_norm_phone')
                ? pge_norm_phone($guest_phone)
                : preg_replace('/\D+/', '', trim((string) $guest_phone));

            if ($normalized_phone === '') {
                error_log("[replacement_entitlement_skipped] reason=invalid_phone event_id={$normalized_event_id} user_id={$user_id}");
                return ['result' => 'skipped', 'reason' => 'invalid_phone'];
            }

            // ── 5. صف primary المصدر ─────────────────────────────────────────
            // لا نعتمد على _mon_credit_cycle_id الحالي — الاستحقاق يُنسَب دائماً
            // للدورة التي حملها صف primary المستهلك فعلياً، حتى لو تغيّرت دورة
            // المستخدم أو انتهى الاشتراك لاحقاً. إن وُجد أكثر من صف consumed
            // (نظرياً عبر دورات مختلفة)، يُختار الأحدث حتمياً:
            // consumed_at DESC ثم id DESC (يحسم أي تساوٍ أو NULL في consumed_at).
            global $wpdb;
            $ledger_table = class_exists('PGE_Invitation_Credit_Ledger')
                ? PGE_Invitation_Credit_Ledger::table_name()
                : $wpdb->prefix . 'mon_invitation_credit_ledger';

            $source_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $ledger_table WHERE user_id = %d AND event_id = %d AND guest_phone = %s AND credit_type = %s AND status = %s ORDER BY consumed_at DESC, id DESC LIMIT 1",
                    $user_id,
                    $normalized_event_id,
                    $normalized_phone,
                    'primary',
                    'consumed'
                ),
                ARRAY_A
            );

            if (!$source_row) {
                error_log("[replacement_entitlement_skipped] reason=no_consumed_primary event_id={$normalized_event_id} user_id={$user_id} phone={$normalized_phone}");
                return ['result' => 'skipped', 'reason' => 'no_consumed_primary'];
            }

            $source_ledger_id = (int) ($source_row['id'] ?? 0);
            $source_cycle_id  = (string) ($source_row['credit_cycle_id'] ?? '');

            // ── 6. المنح — حصراً عبر الـRepository، لا منطق منح آخر هنا ───────
            if (!class_exists('PGE_Replacement_Entitlements')) {
                error_log("[replacement_entitlement_error] event_id={$normalized_event_id} user_id={$user_id} phone={$normalized_phone} source_ledger_id={$source_ledger_id} reason=repository_unavailable");
                return ['result' => 'error', 'reason' => 'repository_unavailable'];
            }

            $result = PGE_Replacement_Entitlements::create_entitlement(
                $user_id,
                $source_cycle_id,
                $normalized_event_id,
                $normalized_phone,
                $source_ledger_id
            );

            $outcome = $result['result'] ?? 'error';

            if ($outcome === 'created') {
                error_log("[replacement_entitlement_created] event_id={$normalized_event_id} user_id={$user_id} phone={$normalized_phone} source_ledger_id={$source_ledger_id} entitlement_id=" . (int) ($result['id'] ?? 0));
            } elseif ($outcome === 'already_exists') {
                error_log("[replacement_entitlement_already_exists] event_id={$normalized_event_id} user_id={$user_id} phone={$normalized_phone} source_ledger_id={$source_ledger_id} entitlement_id=" . (int) ($result['id'] ?? 0));
            } else {
                error_log("[replacement_entitlement_error] event_id={$normalized_event_id} user_id={$user_id} phone={$normalized_phone} source_ledger_id={$source_ledger_id} reason=" . (string) ($result['reason'] ?? 'unknown'));
            }

            return $result;
        } catch (\Throwable $e) {
            // لا Fatal يخرج من هذه الدالة أبداً — Side Effect لاحق فقط، لا يجوز
            // أن يُسقِط استدعاءه حفظ RSVP الذي نجح فعلاً قبل استدعائها.
            error_log('[replacement_entitlement_error] استثناء غير متوقع: ' . $e->getMessage());
            return ['result' => 'error', 'reason' => 'exception'];
        }
    }
}
