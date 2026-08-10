<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================================
 * PGE Reminder Message Service — Messaging Architecture Phase 3 (Manual Reminder)
 * ============================================================================
 * Service واحدة مسؤولة عن كامل عملية "إرسال تذكير يدوي" (PART 9): تتحقق من
 * المناسبة، تحل المستلمين عبر PGE_Message_Recipient_Resolver، تُولِّد
 * batch_id عبر PGE_Message_Batch، تُنشئ صفوف تتبّع pending عبر PGE_Message_Log،
 * تبني المحتوى لكل ضيف عبر PGE_Message_Content_Resolver (Phase 1، بلا تغيير)،
 * ترسل عبر PGE_Cartat_Transport (طبقة النقل المشتركة الحالية — بلا نسخ HTTP/
 * auth/payload)، تُحدِّث السجل، وتُعيد ملخّصاً. لا business logic خارج هذا —
 * لا Invitation Credits، لا Feature Gate، لا UI.
 *
 * ============================================================================
 * PART 11 — Batch Idempotency (حماية Double-click/طلبين متزامنين حقيقية)
 * ============================================================================
 * المشكلة: كل "ضغطة إرسال مقصودة" تُولِّد batch_id جديداً بتصميم (PART 10) —
 * إذاً حتى ضغطتان سريعتان متتاليتان (Double-click) يُنشئان batch_id مختلفَين
 * فعلياً، فلا يمنعهما فحص "هل batch_id هذا استُخدم من قبل؟" (كلاهما batch
 * جديد شرعاً). الحل: قفل GET_LOCK قصير العمر مُحدَّد بـ(event_id) فقط
 * (operation_lock_name()) يُحجَز *فقط* أثناء خطوة "حل المستلمين + إنشاء كل
 * صفوف pending في message_log" (سريعة، ملّي ثوانٍ لكل صف) — لا يُحمَل عبر
 * الإرسال الفعلي البطيء (نفس فلسفة PGE_Thank_You_Claim/PGE_Checkin_Recorder:
 * القفل للخطوة الذرية القصيرة فقط). طلب ثانٍ يصل بينما القفل محجوز (timeout=0،
 * فشل فوري بلا انتظار — نفس نمط cron_process_queue()'s cron_lock_name) يُرفَض
 * صراحةً بسبب 'operation_in_progress' *قبل* إنشاء أي batch/صف تتبّع جديد —
 * فلا يمكن لضغطتين متزامنتين إنشاء دفعتين تُرسلان لنفس المستلمين في نفس
 * اللحظة. هذا حماية خادمية حقيقية، مستقلة تماماً عن تعطيل الزر في JavaScript.
 *
 * ============================================================================
 * PART 25-27 — Large Batches / Queue Strategy / Queue Collision
 * ============================================================================
 * قرار معماري صريح (وثّقه التقرير النهائي كجزء من "أي انحراف عن Contract"):
 * تفحّصنا cron_process_queue() الحالية (class-cartat-handler.php) فوجدناها
 * مُطعَّمة بعمق بمنطق Invitation Credit Ledger/Replacement Entitlements في كل
 * فرع تقريباً (claim_for_delivery/mark_consumed_with_token/GET_LOCK مخصص
 * للرصيد البديل...). تمديدها لدعم message_type=reminder (الذي يُمنَع صراحة من
 * لمس أي رصيد) يعني إما تفريع خطر عبر دالة حساسة تم تصليدها عبر مراحل عديدة،
 * أو خطر تسرّب منطق رصيد لمسار لا يجب أن يعرف عن الرصيد شيئاً — كلاهما
 * "Refactor كبير" بالضبط كما حذّر التكليف. الخيار الأقل مخاطرة المُختار: طابور
 * مستقل تماماً بلا أي مشاركة حالة مع pge_wa_queue_{event_id} — Queue key
 * مختلف كلياً (لا يوجد أصلاً؛ حالة الطابور *هي* صفوف message_log نفسها
 * بـstatus='pending' لكل batch_id — لا wp_options جديد بحالة موازية)، Lock
 * name مختلف (build_tick_lock_name())، Cron hook مختلف (CRON_HOOK). هذا
 * يحقق "لا تعارض" (PART 27) بنيوياً: لا يوجد أي مفتاح/متغير مشترك بين مسار
 * الدعوة الحالي وهذا الطابور إطلاقاً، فلا يمكن لأحدهما محو الآخر أو خلط
 * النتائج أو استهلاك رصيد الآخر بالتصميم — لا حاجة لمنع تشغيل متوازٍ لأنه لا
 * يوجد تشارك حالة يستدعي المنع أصلاً.
 *
 * الدفعة الأولى (حتى SYNC_CHUNK_SIZE مستلماً) تُعالَج فوراً Synchronous ضمن
 * نفس طلب الـAJAX (نفس سقف invitation's send_invitations() الحالي —
 * batch_size افتراضي مشابه، set_time_limit(120)). أي متبقٍّ بعدها يُكمَل عبر
 * WP-Cron (نفس آلية wp_schedule_single_event()+spawn_cron() الحالية، حجم دفعة
 * أصغر لكل تشغيلة Cron) — فلا Loop ضخمة عمياء داخل طلب واحد أبداً.
 */
class PGE_Reminder_Message_Service
{
    /** أول دفعة تُعالَج مباشرة ضمن طلب الـAJAX نفسه (نفس سقف invitation's manual batch). */
    const SYNC_CHUNK_SIZE = 25;

    /** حجم كل دفعة يعالجها WP-Cron لاحقاً — أصغر من SYNC لضمان عدم انتهاء وقت التنفيذ. */
    const CRON_CHUNK_SIZE = 15;

    /** فاصل زمني بين تشغيلات Cron المتتالية لنفس الدفعة (ثوانٍ) — قريب من راحة invitation's 35s. */
    const CRON_RECHECK_DELAY_SECONDS = 25;

    const CRON_HOOK = 'pge_wa_process_reminder_queue';

    /**
     * تأخير مكافحة الحظر بين الرسائل (usleep أدناه في process_batch_tick) —
     * مفعَّل دائماً في الإنتاج (القيمة الافتراضية true، لا تُغيَّر في أي مسار
     * إنتاجي). مُتاح للتعطيل حصراً من ملفات الاختبار المستقلة (PART 34 —
     * اختبار دفعة واقعية بعدد كبير) عبر set_send_delay_enabled_for_tests()
     * أدناه، لأن usleep(1.5-3s) لكل رسالة هو تأخير تشغيلي حقيقي (مقصود لتجنّب
     * حظر واتساب للرقم) لا "منطق عمل" يُختبَر بحد ذاته — إيقافه في الاختبار لا
     * يغيّر أي سلوك يخص التتبّع/الرصيد/الحالة، فقط سرعة التنفيذ. هذا لا يمس
     * حدود Transport (PART 34 "Mock فقط عند حدود Transport") لأنه لا يُبدِّل
     * أي استدعاء لـPGE_Cartat_Transport — لا يزال كل مستلم يمر فعلياً عبر
     * send_text()/send_media()/interpret_result() الحقيقيَّة.
     */
    private static $send_delay_enabled = true;

    /** لا تستدعِ هذه إلا من ملفات الاختبار المستقلة تحت tests/. */
    public static function set_send_delay_enabled_for_tests(bool $enabled): void
    {
        self::$send_delay_enabled = $enabled;
    }

    private static function log(string $msg): void
    {
        $log_file = WP_CONTENT_DIR . '/cartat-webhook.log';
        $line     = '[' . date('Y-m-d H:i:s') . '] [reminder] ' . $msg . "\n";
        file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function operation_lock_name(int $event_id): string
    {
        return 'pge_reminder_op_' . md5((string) $event_id);
    }

    private static function tick_lock_name(int $event_id, string $batch_id): string
    {
        return 'pge_reminder_tick_' . md5($event_id . '|' . $batch_id);
    }

    /**
     * نقطة الدخول الوحيدة — تُستدعى من الـAJAX Endpoint فقط. $actor_user_id
     * يُلتَقط من get_current_user_id() في المُستدعي (نفس نمط subscriber_user_id
     * في ajax_queue_start() — يُمرَّر صراحةً، لا استنتاج داخلي).
     *
     * @return array{result:string,reason?:string,batch_id?:string,total_targeted?:int,skipped_invalid_phone?:int,sent?:int,failed?:int,ambiguous?:int,queued_remaining?:int}
     */
    public static function send_reminder_batch(int $event_id, string $filter, int $actor_user_id, bool $include_image = false): array
    {
        if ($event_id <= 0 || get_post_type($event_id) !== 'pge_event') {
            return ['result' => 'error', 'reason' => 'invalid_event'];
        }

        $transport = new PGE_Cartat_Transport();
        if (!$transport->has_credentials()) {
            return ['result' => 'error', 'reason' => 'no_provider_credentials'];
        }

        global $wpdb;
        $lock_name = self::operation_lock_name($event_id);
        $got_lock = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 0));
        if ($got_lock !== 1) {
            self::log("⏭ event=$event_id: عملية إرسال تذكير أخرى قيد الإنشاء بالفعل — رفض فوري (PART 11)");
            return ['result' => 'error', 'reason' => 'operation_in_progress'];
        }

        $batch_id = '';
        $log_ids = [];
        $skipped_invalid_phone = 0;

        try {
            $resolved = PGE_Message_Recipient_Resolver::resolve($event_id, PGE_Message_Type::REMINDER, $filter);
            $recipients = $resolved['recipients'];
            $skipped_invalid_phone = $resolved['skipped_invalid_phone'];

            if (empty($recipients)) {
                return ['result' => 'error', 'reason' => 'no_recipients'];
            }

            $batch_id = PGE_Message_Batch::generate_batch_id();

            foreach ($recipients as $r) {
                $log_id = PGE_Message_Log::create_pending([
                    'event_id'      => $event_id,
                    'guest_phone'   => $r['phone'],
                    'message_type'  => PGE_Message_Type::REMINDER,
                    'batch_id'      => $batch_id,
                    'provider'      => 'cartat',
                    'actor_user_id' => $actor_user_id,
                ]);
                if ($log_id !== false) {
                    $log_ids[] = $log_id;
                }
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }

        $created_count = count($log_ids);
        if ($created_count === 0) {
            return ['result' => 'error', 'reason' => 'tracking_creation_failed'];
        }

        self::log("🚀 event=$event_id: batch=$batch_id بدأ — targeted=$created_count | filter={$resolved['filter']} | skipped_invalid_phone=$skipped_invalid_phone");

        @set_time_limit(120);
        $tick_summary = self::process_batch_tick($event_id, $batch_id, self::SYNC_CHUNK_SIZE, $include_image);

        $remaining = self::count_pending_in_batch($event_id, $batch_id);
        if ($remaining > 0) {
            wp_schedule_single_event(time() + self::CRON_RECHECK_DELAY_SECONDS, self::CRON_HOOK, [$event_id, $batch_id, $include_image]);
            if (function_exists('spawn_cron')) {
                spawn_cron();
            }
            self::log("⏳ event=$event_id: batch=$batch_id — متبقٍّ $remaining مستلماً، جُدولت متابعة عبر Cron");
        } else {
            self::log("✅ event=$event_id: batch=$batch_id اكتمل بالكامل ضمن الطلب المتزامن");
        }

        return [
            'result'                => 'started',
            'batch_id'              => $batch_id,
            'total_targeted'        => $created_count,
            'skipped_invalid_phone' => $skipped_invalid_phone,
            'sent'                  => $tick_summary['sent'],
            'failed'                => $tick_summary['failed'],
            'ambiguous'             => $tick_summary['ambiguous'],
            'queued_remaining'      => $remaining,
        ];
    }

    /**
     * WP-Cron — معالجة دفعة متبقية من batch سابق (يُسجَّل في pgevents-core.php).
     * Public لأن add_action() يستدعيها مباشرة (نفس نمط cron_process_queue()).
     */
    public static function cron_process_reminder_queue(int $event_id, string $batch_id, bool $include_image = false): void
    {
        @set_time_limit(60);
        self::process_batch_tick($event_id, $batch_id, self::CRON_CHUNK_SIZE, $include_image);

        $remaining = self::count_pending_in_batch($event_id, $batch_id);
        if ($remaining > 0) {
            wp_schedule_single_event(time() + self::CRON_RECHECK_DELAY_SECONDS, self::CRON_HOOK, [$event_id, $batch_id, $include_image]);
            self::log("⏳ event=$event_id: batch=$batch_id — متبقٍّ $remaining مستلماً بعد تشغيلة Cron، جُدولت متابعة أخرى");
        } else {
            self::log("✅ event=$event_id: batch=$batch_id اكتمل عبر Cron");
        }
    }

    /**
     * معالجة دفعة واحدة من صفوف pending في batch معيَّن — الوحدة المشتركة بين
     * المسار المتزامن (أول SYNC_CHUNK_SIZE) ومسار Cron (كل CRON_CHUNK_SIZE) —
     * لا تكرار منطق بينهما. قفل قصير خاص بـ(event_id, batch_id) يمنع تشغيلتين
     * متزامنتين لنفس الدفعة بالذات (نفس فلسفة cron_lock_name في
     * cron_process_queue()، مستقل تماماً عن أي قفل آخر في المشروع).
     *
     * @return array{sent:int,failed:int,ambiguous:int}
     */
    private static function process_batch_tick(int $event_id, string $batch_id, int $chunk_size, bool $include_image = false): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'ambiguous' => 0];

        global $wpdb;
        $lock_name = self::tick_lock_name($event_id, $batch_id);
        $got_lock = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 0));
        if ($got_lock !== 1) {
            self::log("⏭ event=$event_id: batch=$batch_id — تشغيلة أخرى تعالج هذه الدفعة بالفعل، تخطّي");
            return $summary;
        }

        try {
            $rows = PGE_Message_Log::query_by_batch($batch_id);
            $pending_rows = array_values(array_filter($rows, function ($row) use ($event_id) {
                return (string) ($row['status'] ?? '') === PGE_Message_Log::STATUS_PENDING
                    && (int) ($row['event_id'] ?? 0) === $event_id;
            }));

            if (empty($pending_rows)) {
                return $summary;
            }

            $chunk = array_slice($pending_rows, 0, max(1, $chunk_size));

            $event = get_post($event_id);
            $event_name = $event ? $event->post_title : 'مناسبتنا';
            $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
            $event_date = $event_date_raw
                ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
                : '';
            $event_url = function_exists('pge_get_event_short_url') ? pge_get_event_short_url($event_id) : (string) get_permalink($event_id);
            $invite_code = (string) get_post_meta($event_id, '_pge_invite_code', true);
            if (function_exists('pge_normalize_invite_code')) {
                $invite_code = pge_normalize_invite_code($invite_code);
            }
            $guests_map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
            $transport = new PGE_Cartat_Transport();
            // Intent الدفعة فقط ينتقل عبر Cron. رابط الصورة يُحل خادمياً من
            // المناسبة في كل tick حتى لا نعتمد على Preview أو URL قديم.
            $image_url = $include_image ? self::resolve_event_featured_image_url($event_id) : null;

            foreach ($chunk as $row) {
                $log_id = (int) $row['id'];
                $phone = (string) $row['guest_phone'];
                $guest_name = (string) ($guests_map[$phone]['name'] ?? '');
                $guest_code = (string) ($guests_map[$phone]['code'] ?? '');

                $content = PGE_Message_Content_Resolver::resolve(PGE_Message_Type::REMINDER, $event_id, [
                    'guest_name'    => $guest_name !== '' ? $guest_name : 'ضيفنا العزيز',
                    'event_name'    => $event_name,
                    'event_date'    => $event_date,
                    'guest_phone'   => $phone,
                    'event_url'     => $event_url,
                    'invite_code'   => $guest_code !== '' ? $guest_code : $invite_code,
                    'location_line' => '',
                    'image_url'     => $image_url,
                ]);

                $wa_number = $transport->format_number($phone);
                $result = $content['image_url']
                    ? $transport->send_media($wa_number, $content['image_url'], $content['text'])
                    : $transport->send_text($wa_number, $content['text']);
                $outcome = $transport->interpret_result($result);

                if ($outcome === 'accepted') {
                    PGE_Message_Log::mark_sent($log_id);
                    $summary['sent']++;
                } elseif ($outcome === 'rejected') {
                    PGE_Message_Log::mark_failed($log_id, PGE_Message_Log::STATUS_FAILED);
                    $summary['failed']++;
                } else {
                    PGE_Message_Log::mark_failed($log_id, PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR);
                    $summary['ambiguous']++;
                }

                if (count($chunk) > 1 && self::$send_delay_enabled) {
                    usleep(rand(1_500_000, 3_000_000));
                }
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }

        return $summary;
    }

    /**
     * مصدر Reminder Media الوحيد: Featured Image صالحة مرتبطة بالمناسبة.
     * لا تقبل هذه الدالة أي URL خارجي ولا تقرأ أي قيمة من العميل.
     */
    public static function resolve_event_featured_image_url(int $event_id): ?string
    {
        $attachment_id = (int) get_post_thumbnail_id($event_id);
        if ($attachment_id <= 0) {
            return null;
        }

        $attachment = get_post($attachment_id);
        if (!$attachment || (string) ($attachment->post_type ?? '') !== 'attachment') {
            return null;
        }

        if (!wp_attachment_is_image($attachment_id)) {
            return null;
        }

        $url = trim((string) get_the_post_thumbnail_url($event_id, 'full'));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /** عدد صفوف pending المتبقية فعلياً لدفعة معيَّنة — تُستخدَم لتقرير التقدّم. */
    public static function count_pending_in_batch(int $event_id, string $batch_id): int
    {
        $rows = PGE_Message_Log::query_by_batch($batch_id);
        return count(array_filter($rows, function ($row) use ($event_id) {
            return (string) ($row['status'] ?? '') === PGE_Message_Log::STATUS_PENDING
                && (int) ($row['event_id'] ?? 0) === $event_id;
        }));
    }

    /**
     * تقرير حالة دفعة كاملة (Sent/Failed/Ambiguous/Pending) — تُستخدَم من
     * نقطة الـAJAX لعرض تقدّم الإرسال في الواجهة (PART 24)، بنفس فلسفة
     * ajax_queue_status() الحالية لكن بلا أي حالة Queue مشتركة معها.
     *
     * @return array{total:int,sent:int,failed:int,ambiguous:int,pending:int}
     */
    public static function batch_status(int $event_id, string $batch_id): array
    {
        $rows = PGE_Message_Log::query_by_batch($batch_id);
        $rows = array_filter($rows, function ($row) use ($event_id) {
            return (int) ($row['event_id'] ?? 0) === $event_id;
        });

        $counts = ['total' => count($rows), 'sent' => 0, 'failed' => 0, 'ambiguous' => 0, 'pending' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === PGE_Message_Log::STATUS_SENT) {
                $counts['sent']++;
            } elseif ($status === PGE_Message_Log::STATUS_FAILED) {
                $counts['failed']++;
            } elseif ($status === PGE_Message_Log::STATUS_AMBIGUOUS_TRANSPORT_ERROR) {
                $counts['ambiguous']++;
            } elseif ($status === PGE_Message_Log::STATUS_PENDING) {
                $counts['pending']++;
            }
        }

        return $counts;
    }
}

add_action(PGE_Reminder_Message_Service::CRON_HOOK, ['PGE_Reminder_Message_Service', 'cron_process_reminder_queue'], 10, 3);
