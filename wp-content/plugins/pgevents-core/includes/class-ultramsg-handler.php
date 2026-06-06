<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Mon_UltraMsg_Handler
 * تكامل واتساب عبر UltraMsg
 * — نفس منطق Cartat تماماً ولكن عبر UltraMsg API
 * — لا يوجد مشكلة LID لأن UltraMsg يُرجع رقم الهاتف مباشرة
 */
class Mon_UltraMsg_Handler
{
    private string $instance_id;
    private string $token;
    private string $api_base;
    private string $country_code;

    public function __construct()
    {
        $this->instance_id  = (string) get_option('pge_ultramsg_instance_id', '');
        $this->token        = (string) get_option('pge_ultramsg_token', '');
        $this->country_code = (string) get_option('pge_cartat_country_code', '966');
        $this->api_base     = 'https://api.ultramsg.com/' . $this->instance_id;

        add_action('rest_api_init',               [$this, 'register_webhook_route']);
        add_action('wp_ajax_pge_send_wa_invites', [$this, 'handle_send_invitations_ajax']);

        // نفس أسماء AJAX الخاصة بـ Cartat — page-event-manage.php يعمل بدون تعديل
        add_action('wp_ajax_pge_wa_queue_start',  [$this, 'ajax_queue_start']);
        add_action('wp_ajax_pge_wa_queue_status', [$this, 'ajax_queue_status']);
        add_action('pge_wa_process_queue',        [$this, 'cron_process_queue'], 10, 1);

        // تشغيل فوري للطابور (يحل مشكلة WP Cron على localhost)
        add_action('wp_ajax_pge_wa_run_now',      [$this, 'ajax_run_now']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REST Webhook — استقبال ردود المدعوين من UltraMsg
    // ══════════════════════════════════════════════════════════════════════════

    public function register_webhook_route()
    {
        register_rest_route('mon/v1', '/um-callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_incoming_message'],
            'permission_callback' => '__return_true',
        ]);

        // GET — للتحقق أن الـ endpoint يعمل + عرض آخر السجلات
        register_rest_route('mon/v1', '/um-callback', [
            'methods'             => 'GET',
            'callback'            => function () {
                $log_file = WP_CONTENT_DIR . '/ultramsg-webhook.log';
                $last = file_exists($log_file)
                    ? array_slice(file($log_file), -20)
                    : ['لا يوجد سجلات بعد'];
                return new WP_REST_Response([
                    'status'     => 'endpoint_active',
                    'provider'   => 'ultramsg',
                    'last_lines' => $last,
                ], 200);
            },
            'permission_callback' => '__return_true',
        ]);
    }

    /** كتابة سجل في ملف مخصص لـ UltraMsg */
    private function log(string $msg): void
    {
        $log_file = WP_CONTENT_DIR . '/ultramsg-webhook.log';
        $line     = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
        error_log($msg);
    }

    public function handle_incoming_message($request)
    {
        $raw_body   = $request->get_body();
        $payload    = json_decode($raw_body, true);

        // UltraMsg يستخدم 'event_type' وليس 'event'
        $event_type = $payload['event_type'] ?? ($payload['event'] ?? '');
        $data       = $payload['data'] ?? $payload; // بعض الإصدارات ترسل البيانات مباشرة

        $this->log("📱 UltraMsg webhook: event_type=$event_type | raw=" . substr($raw_body, 0, 200));

        // تجاهل أي حدث غير message_received
        if ($event_type !== 'message_received') {
            return new WP_REST_Response(['status' => 'ignored', 'reason' => $event_type ?: 'unknown'], 200);
        }

        // تجاهل الرسائل الصادرة (self=1 أو fromMe=true)
        if (($data['self'] ?? '0') === '1' || ($data['fromMe'] ?? false)) {
            return new WP_REST_Response(['status' => 'ignored', 'reason' => 'outgoing'], 200);
        }

        $raw_from    = $data['from'] ?? '';
        $body        = trim($data['body'] ?? '');
        $msg_type    = $data['type']             ?? 'chat';
        $selected_id = $data['selectedButtonId'] ?? ($data['selectedId'] ?? '');

        // UltraMsg: from = "972599000932@c.us" — نزيل الـ suffix
        $from_bare = preg_replace('/@.*$/', '', $raw_from);

        // السماح برسائل الأزرار حتى لو body فارغ (بعض الإصدارات لا ترسل body مع buttons_response)
        $is_button_reply = in_array($msg_type, ['button_reply', 'buttons_response', 'buttonsResponseMessage'], true);
        if (!$from_bare || ($body === '' && !$is_button_reply)) {
            return new WP_REST_Response(['status' => 'ignored', 'reason' => 'empty'], 200);
        }

        $this->log("📨 message_received: from=$raw_from | type=$msg_type | selected_id=$selected_id | body=$body");

        // ══════════════════════════════════════════════════════════════
        // البحث عن الدعوة المعلّقة
        // UltraMsg لا يستخدم LID — الرقم دائماً بالصيغة الدولية
        // ══════════════════════════════════════════════════════════════
        $pending = get_option('pge_wa_pending_' . pge_norm_phone($from_bare));
        if (!$pending) {
            // fallback: الرقم بصيغة 00XXXXXXX
            $pending = get_option('pge_wa_pending_00' . pge_norm_phone($from_bare));
        }
        if ($pending) {
            $this->log("✅ pending عبر phone: $from_bare");
        }

        if (!$pending || empty($pending['event_id'])) {
            $this->log("❌ لا دعوة معلّقة للمُرسِل: $raw_from");
            return new WP_REST_Response(['status' => 'no_pending'], 200);
        }

        $event_id = (int) $pending['event_id'];

        // ══════════════════════════════════════════════════════════════
        // تحليل الرد — أزرار أو نص
        // ══════════════════════════════════════════════════════════════
        $reply = '';

        // 1. رد عبر الأزرار (الأولوية)
        if ($is_button_reply && $selected_id !== '') {
            $reply = ($selected_id === '1') ? 'yes' : (($selected_id === '2') ? 'no' : '');
        }

        // 2. fallback: تحليل النص (للمستخدمين الذين يكتبون 1 أو 2 يدوياً)
        if (!$reply && $body !== '') {
            $reply = $this->parse_rsvp_reply($body);
        }

        if (!$reply) {
            // لا نرسل "لم نتعرف" إذا كان زر — فقط للنصوص المجهولة
            if (!$is_button_reply) {
                $send_to = $pending['wa_number'] ?? $raw_from;
                $this->send_text_message($send_to, $this->get_reminder_text());
            }
            return new WP_REST_Response(['status' => 'invalid_reply'], 200);
        }

        // ══════════════════════════════════════════════════════════════
        // تسجيل RSVP
        // ══════════════════════════════════════════════════════════════
        $rsvp_phone = $pending['original_phone'] ?? pge_norm_phone($from_bare);
        $this->record_rsvp($event_id, $rsvp_phone, $reply);

        // مسح الدعوة المعلّقة
        if (!empty($pending['wa_number'])) {
            delete_option('pge_wa_pending_' . $pending['wa_number']);
        }
        if (!empty($pending['msg_id'])) {
            delete_option('pge_wa_pending_msgid_' . $pending['msg_id']);
        }

        // ══════════════════════════════════════════════════════════════
        // رسالة التأكيد
        // ══════════════════════════════════════════════════════════════
        $send_to = $pending['wa_number'] ?? $from_bare;

        if ($reply === 'yes') {
            $event_name  = get_the_title($event_id);
            $event_url   = $pending['event_url']   ?? (string) get_permalink($event_id);
            $invite_code = $pending['invite_code'] ?? '';
            $disp_phone  = $pending['norm_phone']  ?? $rsvp_phone;

            $confirm_msg  = "شكراً على تأكيد حضورك! 🎉\n";
            $confirm_msg .= "نتطلع لرؤيتك في *{$event_name}*\n\n";
            $confirm_msg .= "━━━━━━━━━━━━━━━\n";
            $confirm_msg .= "📌 *تفاصيل دخولك:*\n";
            if ($event_url) {
                $confirm_msg .= "🔗 رابط المناسبة:\n{$event_url}\n";
            }
            if ($invite_code) {
                $confirm_msg .= "\n🔑 رمز الدعوة: *{$invite_code}*\n";
                $confirm_msg .= "📱 رقمك المسجل: *{$disp_phone}*";
            }
        } else {
            $confirm_msg = "شكراً على إبلاغنا. نتمنى لك دوام الصحة والسعادة 🌸";
        }
        $this->send_text_message($send_to, $confirm_msg);

        $this->log("✅ RSVP: from=$raw_from | rsvp_phone=$rsvp_phone | reply=$reply | event=$event_id");
        return new WP_REST_Response(['status' => 'success', 'reply' => $reply], 200);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // AJAX — إرسال الدعوات من صفحة إدارة المناسبة
    // ══════════════════════════════════════════════════════════════════════════

    public function handle_send_invitations_ajax()
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pge_event_manage_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        if (!$event_id || !pge_is_host_or_admin($event_id)) {
            wp_send_json_error(['message' => 'Forbidden']);
        }
        if (empty($this->instance_id) || empty($this->token)) {
            wp_send_json_error(['message' => 'لم يتم ضبط UltraMsg Instance ID أو Token في الإعدادات']);
        }

        $offset     = absint($_POST['offset']     ?? 0);
        $batch_size = absint($_POST['batch_size'] ?? 20);
        $batch_size = min($batch_size, 30);

        $results = $this->send_invitations($event_id, $offset, $batch_size);
        wp_send_json_success($results);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // إرسال الدعوات
    // ══════════════════════════════════════════════════════════════════════════

    public function send_invitations(int $event_id, int $offset = 0, int $batch_size = 20): array
    {
        @set_time_limit(120);

        $event      = get_post($event_id);
        $event_name = $event ? $event->post_title : 'مناسبتنا';

        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        $event_date     = $event_date_raw
            ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
            : '';

        $image_url   = (string) get_the_post_thumbnail_url($event_id, 'full');
        $event_url   = (string) get_permalink($event_id);
        $invite_code = (string) get_post_meta($event_id, '_pge_invite_code', true);
        if (function_exists('pge_normalize_invite_code')) {
            $invite_code = pge_normalize_invite_code($invite_code);
        }
        $guests_map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
        $all_phones = pge_get_invited_phones($event_id);
        $total      = count($all_phones);

        if (empty($all_phones)) {
            return ['sent' => 0, 'failed' => 0, 'total' => 0, 'done' => true, 'message' => 'لا يوجد مدعوون مضافون'];
        }

        $phones      = array_slice($all_phones, $offset, $batch_size);
        $next_offset = $offset + count($phones);
        $has_more    = $next_offset < $total;

        $sent = $failed = 0;

        foreach ($phones as $phone) {
            $wa_number  = $this->format_wa_number($phone);
            $guest_name = $guests_map[$phone]['name'] ?? 'ضيفنا العزيز';
            $norm_phone = pge_norm_phone($phone);

            $result = $this->send_invite_with_buttons(
                $wa_number, $guest_name, $event_name, $event_date, $image_url
            );

            // تأخير عشوائي 2-4 ثوانٍ (إضافي بعد التأخير الداخلي في send_invite_with_buttons)
            usleep(rand(2_000_000, 3_000_000));

            // UltraMsg يُرجع {"sent":"true","id":"..."} عند النجاح
            $is_error = ($result === null)
                     || (isset($result['error'])  && !empty($result['error']))
                     || (isset($result['sent'])   && $result['sent'] === 'false')
                     || (isset($result['status']) && $result['status'] === 'error');

            $this->log("📨 UltraMsg send result for $wa_number: " . json_encode($result) . " | is_error=" . ($is_error ? 'yes' : 'no'));

            if (!$is_error) {
                $sent++;
                $msg_id = $result['id'] ?? '';

                $pending_data = [
                    'event_id'       => $event_id,
                    'sent_at'        => time(),
                    'msg_id'         => $msg_id,
                    'original_phone' => $norm_phone,
                    'wa_number'      => $wa_number,
                    'event_url'      => $event_url,
                    'invite_code'    => $invite_code,
                    'norm_phone'     => $norm_phone,
                ];

                update_option('pge_wa_pending_' . $wa_number, $pending_data, false);
                if ($msg_id) {
                    update_option('pge_wa_pending_msgid_' . $msg_id, $pending_data, false);
                }
                $this->log("✅ UltraMsg: pending saved | wa=$wa_number | msg_id=$msg_id");
            } else {
                $failed++;
                $this->log("❌ UltraMsg: فشل إرسال لـ $wa_number | " . json_encode($result));
            }
        }

        if (!$has_more) {
            update_post_meta($event_id, '_pge_wa_sent_at',    current_time('mysql'));
            update_post_meta($event_id, '_pge_wa_sent_count', $next_offset);
        }

        $progress_pct = $total > 0 ? round(($next_offset / $total) * 100) : 100;

        return [
            'sent'        => $sent,
            'failed'      => $failed,
            'total'       => $total,
            'offset'      => $offset,
            'next_offset' => $next_offset,
            'has_more'    => $has_more,
            'progress'    => $progress_pct,
            'done'        => !$has_more,
            'message'     => $has_more
                ? "⏳ تم إرسال {$next_offset} من {$total} ({$progress_pct}%)"
                : "✅ اكتمل الإرسال | نجح: {$sent} | فشل: {$failed} | الإجمالي: {$total}",
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // UltraMsg API Wrappers
    // ══════════════════════════════════════════════════════════════════════════

    private function send_text_message(string $number, string $message): ?array
    {
        return $this->api_request('/messages/chat', [
            'token' => $this->token,
            'to'    => $number,
            'body'  => $message,
        ]);
    }

    private function send_media_message(string $number, string $media_url, string $caption = ''): ?array
    {
        return $this->api_request('/messages/image', [
            'token'   => $this->token,
            'to'      => $number,
            'image'   => $media_url,
            'caption' => $caption,
        ]);
    }

    /**
     * إرسال رسالة مع أزرار RSVP التفاعلية
     * header = عنوان الرسالة (يظهر بخط عريض)
     * body   = النص الرئيسي
     * footer = نص صغير في الأسفل (اختياري)
     */
    private function send_button_message(string $number, string $header, string $body, string $footer = ''): ?array
    {
        $buttons = json_encode([
            ['id' => '1', 'title' => 'سأحضر بإذن الله ✅'],
            ['id' => '2', 'title' => 'لن أتمكن من الحضور ❌'],
        ]);

        return $this->api_request('/messages/buttons', [
            'token'       => $this->token,
            'to'          => $number,
            'title'       => $header,
            'footer'      => $footer,
            'description' => $body,
            'buttons'     => $buttons,
        ]);
    }

    /**
     * إرسال دعوة المناسبة — صورة (اختياري) + رسالة الأزرار
     * إذا وجدت صورة → ترسل أولاً كـ caption، ثم رسالة الأزرار بعدها
     */
    private function send_invite_with_buttons(
        string $wa_number,
        string $guest_name,
        string $event_name,
        string $event_date,
        string $image_url
    ): ?array {
        // ── بناء نصوص الرسالة ─────────────────────────────────────────
        $header = "مرحباً {$guest_name} 👋";

        $body  = "يسعدنا دعوتك لحضور:\n";
        $body .= "✨ *{$event_name}*";
        if ($event_date) {
            $body .= "\n\n📅 {$event_date}";
        }
        $body .= "\n\nاختر ردك على الدعوة:";

        $footer = $event_name;

        // ── إرسال الصورة أولاً إن وجدت ──────────────────────────────
        if ($image_url) {
            $this->send_media_message($wa_number, $image_url, "دعوة: {$event_name}");
            usleep(rand(1_500_000, 2_500_000)); // استراحة 1.5-2.5 ثانية بين الرسالتين
        }

        // ── إرسال رسالة الأزرار ──────────────────────────────────────
        return $this->send_button_message($wa_number, $header, $body, $footer);
    }

    private function api_request(string $endpoint, array $body): ?array
    {
        if (empty($this->instance_id)) {
            error_log('❌ UltraMsg: instance_id فارغ');
            return null;
        }

        $response = wp_remote_post($this->api_base . $endpoint, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body'    => http_build_query($body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            error_log('❌ UltraMsg API Error: ' . $response->get_error_message());
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        error_log('📤 UltraMsg API Response [' . $endpoint . ']: ' . json_encode($decoded));
        return $decoded;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function format_wa_number(string $phone): string
    {
        $phone = pge_norm_phone($phone);

        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        } elseif (str_starts_with($phone, '0')) {
            $phone = $this->country_code . substr($phone, 1);
        } elseif (!str_starts_with($phone, $this->country_code)) {
            $phone = $this->country_code . $phone;
        }

        return $phone;
    }

    private function parse_rsvp_reply(string $body): string
    {
        $b = mb_strtolower(trim($body));

        $yes = ['1', 'نعم', 'yes', 'حاضر', 'سأحضر', 'حضور', 'اوكي', 'موافق', 'ok', '✅', 'ايه', 'اه'];
        $no  = ['2', 'لا', 'no', 'اعتذر', 'لن احضر', 'اعتذار', 'معذرة', '❌', 'مش قادر', 'مو قادر'];

        if (in_array($b, $yes, true)) return 'yes';
        if (in_array($b, $no,  true)) return 'no';
        return '';
    }

    private function record_rsvp(int $event_id, string $phone, string $reply): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pge_event_rsvps';
        $phone = pge_norm_phone($phone);

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE event_id = %d AND guest_phone = %s LIMIT 1",
            $event_id,
            $phone
        ));

        if ($existing_id) {
            $wpdb->update(
                $table,
                ['reply' => $reply, 'created_at' => current_time('mysql')],
                ['id' => $existing_id]
            );
        } else {
            $wpdb->insert($table, [
                'event_id'    => $event_id,
                'guest_phone' => $phone,
                'reply'       => $reply,
                'companions'  => 0,
                'note'        => 'via WhatsApp (UltraMsg)',
                'checked_in'  => 0,
                'created_at'  => current_time('mysql'),
            ]);
        }
    }

    private function get_reminder_text(): string
    {
        return "عذراً، لم نتعرف على ردك 😊\n\nيمكنك الرد بـ:\n*1* — للحضور\n*2* — للاعتذار";
    }

    // ══════════════════════════════════════════════════════════════════════════
    // نظام الإرسال في الخلفية (Background Queue)
    // ══════════════════════════════════════════════════════════════════════════

    private function queue_key(int $event_id): string
    {
        return 'pge_wa_queue_' . $event_id;
    }

    public function ajax_queue_start(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pge_event_manage_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        if (!$event_id || !pge_is_host_or_admin($event_id)) {
            wp_send_json_error(['message' => 'Forbidden']);
        }
        if (empty($this->instance_id) || empty($this->token)) {
            wp_send_json_error(['message' => 'لم يتم ضبط UltraMsg Instance ID أو Token']);
        }

        $phones = pge_get_invited_phones($event_id);
        if (empty($phones)) {
            wp_send_json_error(['message' => 'لا يوجد مدعوون']);
        }

        $event          = get_post($event_id);
        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        $invite_code    = (string) get_post_meta($event_id, '_pge_invite_code', true);
        if (function_exists('pge_normalize_invite_code')) {
            $invite_code = pge_normalize_invite_code($invite_code);
        }

        $queue = [
            'event_id'   => $event_id,
            'status'     => 'queued',
            'provider'   => 'ultramsg',
            'phones'     => array_values($phones),
            'guests_map' => function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [],
            'event_name' => $event ? $event->post_title : 'مناسبتنا',
            'event_date' => $event_date_raw
                ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
                : '',
            'image_url'  => (string) get_the_post_thumbnail_url($event_id, 'full'),
            'event_url'  => (string) get_permalink($event_id),
            'invite_code'=> $invite_code,
            'offset'     => 0,
            'total'      => count($phones),
            'results'    => [],
            'created_at' => time(),
            'done_at'    => null,
        ];

        update_option($this->queue_key($event_id), $queue, false);

        wp_schedule_single_event(time(), 'pge_wa_process_queue', [$event_id]);
        spawn_cron();

        wp_send_json_success([
            'message' => "🚀 بدأ الإرسال في الخلفية عبر UltraMsg لـ {$queue['total']} مدعو. يمكنك إغلاق الصفحة.",
            'total'   => $queue['total'],
        ]);
    }

    public function ajax_queue_status(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pge_event_manage_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $event_id = absint($_POST['event_id'] ?? 0);
        if (!$event_id) {
            wp_send_json_error(['message' => 'Missing event_id']);
        }

        $queue = get_option($this->queue_key($event_id));
        if (!$queue) {
            wp_send_json_success(['status' => 'none']);
            return;
        }

        $sent   = count(array_filter($queue['results'], fn($r) => $r['status'] === 'sent'));
        $failed = count(array_filter($queue['results'], fn($r) => $r['status'] === 'failed'));
        $pct    = $queue['total'] > 0 ? round(($queue['offset'] / $queue['total']) * 100) : 0;

        $report = [];
        foreach ($queue['results'] as $phone => $res) {
            $guest_name = $queue['guests_map'][$phone]['name'] ?? $phone;
            $report[]   = [
                'name'   => $guest_name,
                'phone'  => $phone,
                'status' => $res['status'],
                'time'   => $res['time'] ?? '',
            ];
        }

        wp_send_json_success([
            'status'   => $queue['status'],
            'provider' => $queue['provider'] ?? 'ultramsg',
            'total'    => $queue['total'],
            'offset'   => $queue['offset'],
            'sent'     => $sent,
            'failed'   => $failed,
            'progress' => $pct,
            'report'   => $report,
            'done_at'  => $queue['done_at'],
        ]);
    }

    /**
     * AJAX — تشغيل الطابور فوراً بدون انتظار WP Cron
     * يحل مشكلة localhost حيث لا يعمل WP Cron تلقائياً
     */
    public function ajax_run_now(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pge_event_manage_nonce')) wp_die('unauthorized');
        if (!is_user_logged_in()) wp_die('unauthorized');

        $event_id = absint($_POST['event_id'] ?? 0);
        if (!$event_id) wp_die('missing event_id');

        // أغلق الاتصال مع المتصفح فوراً وتابع المعالجة في الخلفية
        status_header(200);
        header('Content-Type: application/json');
        header('Content-Length: 2');
        header('Connection: close');
        echo '{}';

        // فراغ الـ output buffer وأرسل للمتصفح
        if (ob_get_level() > 0) ob_end_flush();
        flush();

        // PHP-FPM: أنهِ الاتصال مع المتصفح وتابع العمل
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // الآن المتصفح أغلق الاتصال لكن PHP ما زال يعمل
        @set_time_limit(300);
        ignore_user_abort(true);

        $this->cron_process_queue($event_id);

        exit;
    }

    public function cron_process_queue(int $event_id): void
    {
        $queue = get_option($this->queue_key($event_id));
        if (!$queue || $queue['status'] === 'done') return;

        // تجاهل الـ queue إذا كان من مزوّد مختلف
        if (($queue['provider'] ?? 'ultramsg') !== 'ultramsg') return;

        @set_time_limit(120);

        $queue['status'] = 'running';
        update_option($this->queue_key($event_id), $queue, false);

        $batch_size = 10;
        $phones     = array_slice($queue['phones'], $queue['offset'], $batch_size);

        foreach ($phones as $phone) {
            $wa_number  = $this->format_wa_number($phone);
            $norm_phone = pge_norm_phone($phone);
            $guest_name = $queue['guests_map'][$phone]['name'] ?? 'ضيفنا العزيز';

            $result = $this->send_invite_with_buttons(
                $wa_number,
                $guest_name,
                $queue['event_name'],
                $queue['event_date'],
                $queue['image_url']
            );

            $is_error = ($result === null)
                     || (isset($result['error'])  && !empty($result['error']))
                     || (isset($result['sent'])   && $result['sent'] === 'false')
                     || (isset($result['status']) && $result['status'] === 'error');

            if (!$is_error) {
                $msg_id = $result['id'] ?? '';
                $pending_data = [
                    'event_id'       => $event_id,
                    'sent_at'        => time(),
                    'msg_id'         => $msg_id,
                    'original_phone' => $norm_phone,
                    'wa_number'      => $wa_number,
                    'event_url'      => $queue['event_url'],
                    'invite_code'    => $queue['invite_code'],
                    'norm_phone'     => $norm_phone,
                ];
                update_option('pge_wa_pending_' . $wa_number, $pending_data, false);
                if ($msg_id) {
                    update_option('pge_wa_pending_msgid_' . $msg_id, $pending_data, false);
                }
                $queue['results'][$phone] = ['status' => 'sent',   'time' => current_time('mysql')];
            } else {
                $queue['results'][$phone] = ['status' => 'failed', 'time' => current_time('mysql')];
                $this->log("❌ Queue: فشل إرسال لـ $wa_number | " . json_encode($result));
            }

            usleep(rand(2_000_000, 4_000_000));
        }

        $queue['offset'] += count($phones);

        if ($queue['offset'] >= $queue['total']) {
            $queue['status']  = 'done';
            $queue['done_at'] = current_time('mysql');
            update_post_meta($event_id, '_pge_wa_sent_at',    current_time('mysql'));
            update_post_meta($event_id, '_pge_wa_sent_count', $queue['offset']);
            $this->log("✅ Queue done: event=$event_id | offset={$queue['offset']}/{$queue['total']}");
        } else {
            $queue['status'] = 'running';
            wp_schedule_single_event(time() + 35, 'pge_wa_process_queue', [$event_id]);
        }

        update_option($this->queue_key($event_id), $queue, false);
    }
}

new Mon_UltraMsg_Handler();
