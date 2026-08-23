<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Invitation Send State — Model D2، D2-W2 (Read/State Contract — بلا أي
 * أثر جانبي، بلا إرسال فعلي)
 * ============================================================================
 * الطبقة الوحيدة التي تُركِّب "عقد قراءة" مستقر (Read/State Contract) فوق
 * D2-W1 (PGE_Invitation_Send_Ledger) لصالح طبقات التطبيق/الواجهة المستقبلية
 * (D2-W3 التخويل، D2-W4 الإرسال الفعلي/AJAX/UI). تُجيب فقط عن: ما حالة إرسال
 * الدعوة الحالية؟ ما آخر محاولة؟ هل الإرسال العادي مسموح الآن؟ هل يلزم Resend
 * صريح؟ هل هناك محاولة قيد التنفيذ؟ هل فشلت آخر محاولة؟ مَن نفَّذها ومتى؟
 *
 * لا تُقرِّر هذه الطبقة إطلاقاً هل الفاعل (actor) مخوَّل بالإرسال — ذلك بالكامل
 * مسؤولية D2-W3 المستقبلية (غير موجودة بعد). لا تفحص أدوار WP، لا Event
 * Access، لا عضوية، لا Additional Inviter/Owner، ولا حصة (Quota) — الحصة تحكم
 * إنشاء الدعوة (Guest Creation)، لا قابلية إرسالها (Sendability)، فلا علاقة
 * لها هنا إطلاقاً. المُستدعي (طبقة تطبيقية مستقبلية) هو من يُمرِّر هوية الضيف
 * بعد أن يقرر بنفسه أن الفاعل مخوَّل أصلاً.
 *
 * ============================================================================
 * التركيب: لا تكرار لمنطق D2-W1، ولا تعديل عليه إطلاقاً
 * ============================================================================
 * كل الحالة تُشتَقّ عبر تأليف (Compose) اثنتين من الواجهات العامة الموجودة
 * أصلاً، بلا تعديل حرف واحد في class-pge-invitation-send-ledger.php أو
 * class-pge-message-log.php (تحقَّقتُ أن هذا التأليف الخارجي كافٍ تماماً —
 * لا "عقد قراءة مفقود" يستوجب تعديل D2-W1):
 *   1) PGE_Invitation_Send_Ledger::current_state() — تُعيد الحالة المُشتَقّة
 *      من "أحدث محاولة فقط" ضمن دورة الحياة الحالية (منطق Fix Pass 1)، ومعها
 *      log_id لأحدث محاولة إن وُجدت. هذه الطبقة تُعيد استخدام تلك الحالة
 *      حرفياً — لا اشتقاق حالة موازٍ، لا احتمال انحراف دلالي بين الطبقتين.
 *   2) PGE_Message_Log::find_by_id($log_id) — لجلب الصف الكامل لأحدث محاولة
 *      (actor_user_id/batch_id/created_at/sent_at...) فقط عند الحاجة لملخّص
 *      المحاولة الأخيرة (القسم 6 أدناه). قراءة فقط، طريقة عامة موجودة أصلاً.
 *
 * ============================================================================
 * القرار الأهم في D2-W2: قابلية الإرسال العادي لحالة ambiguous_transport_error
 * ============================================================================
 * لم يُخمَّن هذا القرار — استند إلى فحص مباشر لكيفية تعامل النظام القائم فعلياً
 * مع transport_error/ambiguous_transport_error في مسارين حقيقيين مختلفين:
 *
 *   أ) طابور الدعوة الحالي (Legacy، class-cartat-handler.php حوالي السطر
 *      1568-1591): "transport_error: قد تكون الرسالة وصلت فعلاً لكارتات
 *      وانقطع الرد فقط — لا نعتبرها ناجحة ولا نحوّلها failed تلقائياً... ولا
 *      Retry تلقائي هنا. الصف يبقى reserved بتوكن نشط عمداً... حتى انتهاء
 *      Lease لإعادة محاولة **يدوية** لاحقة" — نص صريح: لا إعادة محاولة تلقائية،
 *      القرار يدوي (عملية إنسان واعية، لا سلوك "إرسال عادي" روتيني).
 *   ب) Thank You (class-pge-thank-you-message-service.php أسطر 99-117):
 *      نفس المعاملة — نتيجة transport_error/finalize_error/content_error عند
 *      استثناء تُنهى دوماً بـfinalize_failure(..., STATUS_AMBIGUOUS_TRANSPORT_ERROR)،
 *      لا بـfailed، تمييزاً متعمَّداً بين "رُفض صراحة" (failed، إعادة محاولة
 *      عادية آمنة) و"نتيجة غامضة" (ambiguous، تحتاج قراراً واعياً).
 *
 * الخلاصة المتّسقة عبر كلا المسارين القائمين فعلياً: **ambiguous_transport_error
 * لا يُعامَل كـ'failed' العادية** — الإرسال العادي (Normal Send) لا يجب أن
 * يُقدَّم كخيار متاح روتينياً لهذه الحالة، تماماً كما لا "Retry تلقائي" في أي
 * من المسارين القائمين. لذلك في D2-W2: `ambiguous_transport_error` →
 * `normal_send_allowed = false`، `resend_required = true` (يتطلب نفس نوع
 * القرار الصريح/الواعي الذي يتطلبه Resend بعد نجاح — قرار مقصود، لا محاولة
 * صامتة تلقائية) — **لا** نفس معاملة `failed` (التي تسمح صراحة بـRetry تقني
 * عادي بلا نيّة خاصة، لأن الرفض هناك كان صريحاً من المزوّد، لا غامضاً).
 *
 * ملاحظة دقيقة إضافية: `in_progress` هنا دالة نقية لقيمة `state` فقط (تُطابق
 * حرفياً `state === 'send_requested'`) — لا حساب انتهاء Lease هنا (ذلك سلوك
 * `claim()` الذري وحده في D2-W1؛ ازدواج هذا الحساب هنا يُخاطر بانحراف دلالي
 * بين الطبقتين). `ambiguous_transport_error` حالة **نهائية** أصلاً في عقد
 * PGE_Message_Log (ضمن TERMINAL_FAILURE_STATUSES) — ليست "قيد التنفيذ" بالمعنى
 * الحرفي لعمود status، حتى لو منعها `claim()` مؤقتاً حتى انتهاء الـLease؛ لذلك
 * `in_progress = false` لها هنا، والحاجة لقرار صريح تُنقَل عبر `resend_required`.
 *
 * لا كتابة هنا إطلاقاً (لا claim/finalize/إنشاء صف/إعادة محاولة)، ولا
 * GET_LOCK — قراءة محضة، بلا حاجة موضوعية لأي قفل قراءة. تختبرها حصراً
 * tests/test-d2-w2-invitation-send-state.php.
 *
 * ============================================================================
 * D2-W6A Fix Pass 1 — قرار cancelled (يُقارَن بقرار ambiguous_transport_error
 * أعلاه، عمداً معاكس تماماً)
 * ============================================================================
 * `cancelled` تعني — بضمان صارم من طبقة D2-W1 (راجع PGE_Invitation_Send_
 * Ledger::finalize_cancelled()/PGE_Message_Log::mark_cancelled()) — أن Cartat
 * لم يُستدعَ إطلاقاً لهذه المحاولة. لا غموض حول وصول الرسالة للمزوّد، خلافاً
 * جوهرياً عن `ambiguous_transport_error`. لذلك القرار هنا معاكس تماماً:
 * `normal_send_allowed = true` (محاولة Normal تالية هي إرسال جديد عادي بكل
 * معنى الكلمة، بنفس معاملة `failed`)، `resend_required = false` (لا معنى
 * لـ"إعادة إرسال" رسالة لم تُرسَل قط). راجع claim() (D2-W1) للتطبيق المتَّسق:
 * intent=resend صريح بعد cancelled يُرفَض هناك بـinvalid_state — لا يُحوَّل
 * صامتاً لـnormal ولا يُسمَح ضمنياً هنا.
 */
class PGE_Invitation_Send_State
{
    /**
     * العقد الموحَّد الوحيد لحالة/قابلية إرسال الدعوة لهوية ضيف واحدة (دورة
     * الحياة الحالية فقط). قراءة فقط بالكامل — بلا أي أثر جانبي.
     *
     * @param int    $event_id
     * @param string $guest_phone
     * @return array{
     *   ok:bool,
     *   state:string,
     *   reason:?string,
     *   normal_send_allowed:bool,
     *   resend_required:bool,
     *   in_progress:bool,
     *   latest_attempt:?array{log_id:int,status:string,actor_user_id:?int,batch_id:?string,created_at:?string,sent_at:?string,failure_status:?string,lifecycle_started_at:?string},
     *   latest_actor_user_id:?int,
     *   latest_attempt_at:?string,
     *   latest_sent_at:?string,
     *   latest_failure_status:?string
     * }
     *   state: 'not_sent' | 'send_requested' | 'provider_accepted' | 'failed'
     *        | 'ambiguous_transport_error' | 'cancelled' (D2-W6A Fix Pass 1
     *          — كل هذه فقط عند ok=true)
     *        | 'not_found' | 'invalid' (عند ok=false — نفس اصطلاح Fail-Closed
     *          الداخلي المستخدَم أصلاً في PGE_Invitation_Send_Ledger).
     */
    public static function resolve($event_id, $guest_phone): array
    {
        $current = PGE_Invitation_Send_Ledger::current_state($event_id, $guest_phone);
        $state = is_scalar($current['state'] ?? null) ? (string) $current['state'] : 'invalid';

        if ($state === 'invalid' || $state === 'not_found') {
            return [
                'ok' => false,
                'state' => $state,
                'reason' => isset($current['reason']) ? (string) $current['reason'] : null,
                'normal_send_allowed' => false,
                'resend_required' => false,
                'in_progress' => false,
                'latest_attempt' => null,
                'latest_actor_user_id' => null,
                'latest_attempt_at' => null,
                'latest_sent_at' => null,
                'latest_failure_status' => null,
            ];
        }

        $sendability = self::sendability_for_state($state);

        $latest_attempt = null;
        $latest_actor_user_id = null;
        $latest_attempt_at = null;
        $latest_sent_at = null;
        $latest_failure_status = null;

        $log_id = isset($current['log_id']) ? (int) $current['log_id'] : 0;
        if ($log_id > 0) {
            $row = PGE_Message_Log::find_by_id($log_id);
            if (is_array($row)) {
                $row_status = (string) ($row['status'] ?? '');
                $failure_status = in_array($row_status, PGE_Message_Log::TERMINAL_FAILURE_STATUSES, true)
                    ? $row_status
                    : null;

                $latest_actor_user_id = isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : null;
                $latest_attempt_at = (isset($row['created_at']) && $row['created_at'] !== null)
                    ? (string) $row['created_at']
                    : null;
                $latest_sent_at = (isset($row['sent_at']) && $row['sent_at'] !== null)
                    ? (string) $row['sent_at']
                    : null;
                $latest_failure_status = $failure_status;

                // ملخَّص القراءة فقط (القسم 6): لا محتوى رسالة، لا هاتف مكرَّر
                // (الهاتف مُمرَّر أصلاً من المستدعي)، لا provider/أسرار نقل.
                $latest_attempt = [
                    'log_id'               => $log_id,
                    'status'               => $row_status,
                    'actor_user_id'        => $latest_actor_user_id,
                    'batch_id'             => isset($row['batch_id']) ? (string) $row['batch_id'] : null,
                    'created_at'           => $latest_attempt_at,
                    'sent_at'              => $latest_sent_at,
                    'failure_status'       => $latest_failure_status,
                    'lifecycle_started_at' => isset($row['lifecycle_started_at']) ? (string) $row['lifecycle_started_at'] : null,
                ];
            }
        }

        return [
            'ok' => true,
            'state' => $state,
            'reason' => null,
            'normal_send_allowed' => $sendability['normal_send_allowed'],
            'resend_required' => $sendability['resend_required'],
            'in_progress' => $sendability['in_progress'],
            'latest_attempt' => $latest_attempt,
            'latest_actor_user_id' => $latest_actor_user_id,
            'latest_attempt_at' => $latest_attempt_at,
            'latest_sent_at' => $latest_sent_at,
            'latest_failure_status' => $latest_failure_status,
        ];
    }

    /**
     * جدول القابلية (القسم 5) — دالة نقية بحتة، بلا أي قراءة قاعدة بيانات.
     * راجع توثيق الملف أعلاه لتبرير قرار ambiguous_transport_error تحديداً.
     *
     * @return array{normal_send_allowed:bool,resend_required:bool,in_progress:bool}
     */
    private static function sendability_for_state(string $state): array
    {
        switch ($state) {
            case 'not_sent':
                return ['normal_send_allowed' => true, 'resend_required' => false, 'in_progress' => false];

            case 'send_requested':
                return ['normal_send_allowed' => false, 'resend_required' => false, 'in_progress' => true];

            case 'provider_accepted':
                return ['normal_send_allowed' => false, 'resend_required' => true, 'in_progress' => false];

            case 'failed':
                // مسار Retry تقني عادي — الرفض كان صريحاً من المزوّد.
                return ['normal_send_allowed' => true, 'resend_required' => false, 'in_progress' => false];

            case PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR: // 'ambiguous_transport_error'
                // قرار مقصود، غير افتراضي — راجع القسم الموثَّق أعلاه في رأس الملف.
                return ['normal_send_allowed' => false, 'resend_required' => true, 'in_progress' => false];

            case PGE_Message_Log::STATUS_CANCELLED: // 'cancelled' — D2-W6A Fix Pass 1
                // حالة صريحة أولى الدرجة (First-Class)، محسومة الآن لا
                // مؤجَّلة: 'cancelled' تعني أن Cartat لم يُستدعَ إطلاقاً لهذه
                // المحاولة (راجع PGE_Invitation_Send_Ledger::finalize_cancelled()
                // وPGE_Message_Log::mark_cancelled()) — لا غموض حول ما إذا
                // كانت الرسالة وصلت فعلاً للمزوّد، خلافاً تماماً لـ
                // ambiguous_transport_error أعلاه. لذلك: مطالبة Normal تالية
                // = محاولة إرسال جديدة عادية تماماً (بنفس معاملة 'failed')،
                // لا "إعادة إرسال" رسالة قد تكون وصلت. Resend صريح غير مناسب
                // هنا دلالياً (لا شيء "يُعاد إرساله") — resend_required=false
                // عمداً؛ claim() (D2-W1) ترفض intent=resend صراحةً بعد
                // cancelled بـinvalid_state، فلا تعارض بين الطبقتين.
                return ['normal_send_allowed' => true, 'resend_required' => false, 'in_progress' => false];

            default:
                // دفاعي بحت — لا حالة أخرى مُتوقَّعة فعلياً من current_state().
                return ['normal_send_allowed' => false, 'resend_required' => false, 'in_progress' => false];
        }
    }
}
