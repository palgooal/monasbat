<?php
if (!defined('ABSPATH')) exit;

/**
 * Class Mon_Cartat_Handler
 * تكامل واتساب عبر cartat.net
 * — إرسال دعوات بصورة + نص
 * — استقبال الردود (1=حضور / 2=اعتذار) وتسجيلها في RSVP
 */
class Mon_Cartat_Handler
{
    private string $api_token;
    private string $api_base    = 'https://api.cartat.net';
    private string $country_code;

    public function __construct()
    {
        $this->api_token    = (string) get_option('pge_cartat_api_token', '');
        $this->country_code = (string) get_option('pge_cartat_country_code', '966');

        add_action('rest_api_init',               [$this, 'register_webhook_route']);
        add_action('wp_ajax_pge_send_wa_invites', [$this, 'handle_send_invitations_ajax']);

        // نظام الإرسال في الخلفية (Queue)
        add_action('wp_ajax_pge_wa_queue_start',  [$this, 'ajax_queue_start']);
        add_action('wp_ajax_pge_wa_queue_status', [$this, 'ajax_queue_status']);
        add_action('pge_wa_process_queue',        [$this, 'cron_process_queue'], 10, 1);

        // إرسال تجريبي لرقم محدد
        add_action('wp_ajax_pge_wa_test_send',    [$this, 'ajax_test_send']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REST Webhook — استقبال ردود المدعوين
    // ══════════════════════════════════════════════════════════════════════════

    public function register_webhook_route()
    {
        // POST — استقبال ردود المدعوين من Cartat
        register_rest_route('mon/v1', '/wa-callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_incoming_message'],
            'permission_callback' => '__return_true',
        ]);

        // GET — للتحقق أن الـ endpoint يعمل
        register_rest_route('mon/v1', '/wa-callback', [
            'methods'             => 'GET',
            'callback'            => function () {
                $log_file = WP_CONTENT_DIR . '/cartat-webhook.log';
                $last = file_exists($log_file)
                    ? array_slice(file($log_file), -20)
                    : ['لا يوجد سجلات بعد'];
                return new WP_REST_Response([
                    'status'     => 'endpoint_active',
                    'last_lines' => $last,
                ], 200);
            },
            'permission_callback' => '__return_true',
        ]);
    }

    /** كتابة سجل في ملف مباشر (يعمل حتى بدون WP_DEBUG) */
    private function log(string $msg): void
    {
        $log_file = WP_CONTENT_DIR . '/cartat-webhook.log';
        $line     = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
        error_log($msg);
    }

    public function handle_incoming_message($request)
    {
        $raw_body   = $request->get_body();
        $payload    = json_decode($raw_body, true);
        $event_type = $payload['event'] ?? '';

        $this->log("📱 webhook: event=$event_type");

        // ══════════════════════════════════════════════════════════════
        // حدث ACK — نستخدمه لربط msg_id بالـ LID قبل وصول الرد
        // ══════════════════════════════════════════════════════════════
        if ($event_type === 'ack') {
            // نعالج فقط أول ACK (server) لتجنب التكرار
            if ((int)($payload['ack'] ?? 0) === 1) {
                $msg_id  = $payload['id']  ?? '';
                $raw_to  = $payload['to']  ?? '';
                $to_bare = preg_replace('/@.*$/', '', $raw_to);

                if ($msg_id && $to_bare) {
                    $pending = get_option('pge_wa_pending_msgid_' . $msg_id);
                    if ($pending && !empty($pending['event_id'])) {
                        update_option('pge_wa_pending_lid_' . $to_bare, $pending, false);
                        $this->log("🔗 ACK: ربط msg_id=$msg_id → lid=$to_bare | event={$pending['event_id']}");
                    }
                }
            }
            return new WP_REST_Response(['status' => 'ack_ok'], 200);
        }

        // ══════════════════════════════════════════════════════════════
        // نتجاهل أي حدث غير message_received
        // ══════════════════════════════════════════════════════════════
        if ($event_type !== 'message_received') {
            return new WP_REST_Response(['status' => 'ignored', 'reason' => $event_type ?: 'unknown'], 200);
        }

        // تجاهل الرسائل الصادرة
        if ($payload['fromMe'] ?? false) {
            return new WP_REST_Response(['status' => 'ignored', 'reason' => 'outgoing'], 200);
        }

        $raw_from = $payload['from']    ?? '';
        $body     = trim($payload['body'] ?? $payload['content'] ?? '');
        $from_bare = preg_replace('/@.*$/', '', $raw_from);

        if (!$from_bare || $body === '') {
            return new WP_REST_Response(['status' => 'ignored', 'reason' => 'empty'], 200);
        }

        $this->log("📨 message_received: from=$raw_from | body=$body");

        // ══════════════════════════════════════════════════════════════
        // البحث عن الدعوة المعلّقة بثلاث صيغ
        // ══════════════════════════════════════════════════════════════
        $pending = null;

        // 1. LID (الصيغة الجديدة من واتساب: XXXXXXXXXXXX@lid)
        if (str_contains($raw_from, '@lid')) {
            $pending = get_option('pge_wa_pending_lid_' . $from_bare);
            if ($pending) $this->log("✅ pending عبر LID: $from_bare");
        }

        // 2. رقم الهاتف المباشر (972XXXXXXX)
        if (!$pending) {
            $pending = get_option('pge_wa_pending_' . pge_norm_phone($from_bare));
            if ($pending) $this->log("✅ pending عبر phone: $from_bare");
        }

        // 3. رقم بصيغة 00XXXXXXX
        if (!$pending) {
            $pending = get_option('pge_wa_pending_00' . pge_norm_phone($from_bare));
            if ($pending) $this->log("✅ pending عبر 00phone: $from_bare");
        }

        if (!$pending || empty($pending['event_id'])) {
            $this->log("❌ لا دعوة معلّقة للمُرسِل: $raw_from");
            return new WP_REST_Response(['status' => 'no_pending'], 200);
        }

        $event_id = (int) $pending['event_id'];

        // ══════════════════════════════════════════════════════════════
        // تحليل الرد
        // ══════════════════════════════════════════════════════════════
        $reply = $this->parse_rsvp_reply($body);
        if (!$reply) {
            $send_to = $pending['wa_number'] ?? $raw_from;
            $this->send_text_message($send_to, $this->get_reminder_text());
            return new WP_REST_Response(['status' => 'invalid_reply'], 200);
        }

        // ══════════════════════════════════════════════════════════════
        // تسجيل RSVP
        // ══════════════════════════════════════════════════════════════
        $rsvp_phone = $pending['original_phone'] ?? pge_norm_phone($from_bare);
        $this->record_rsvp($event_id, $rsvp_phone, $reply);

        // مسح جميع مفاتيح الدعوة المعلّقة
        if (str_contains($raw_from, '@lid')) {
            delete_option('pge_wa_pending_lid_' . $from_bare);
        }
        if (!empty($pending['msg_id'])) {
            delete_option('pge_wa_pending_msgid_' . $pending['msg_id']);
        }
        if (!empty($pending['wa_number'])) {
            delete_option('pge_wa_pending_' . $pending['wa_number']);
        }

        // ══════════════════════════════════════════════════════════════
        // رسالة التأكيد — من القالب المخصص أو الافتراضي
        // ══════════════════════════════════════════════════════════════
        $send_to     = $pending['wa_number'] ?? $raw_from;
        $event_name  = get_the_title($event_id);
        $event_url   = $pending['event_url']   ?? function_exists("pge_get_event_short_url") ? pge_get_event_short_url($event_id) : (string) get_permalink($event_id);
        $invite_code = $pending['invite_code'] ?? '';
        $disp_phone  = $pending['norm_phone']  ?? $rsvp_phone;

        $tpls = function_exists('pge_wa_get_templates') ? pge_wa_get_templates($event_id) : [];

        $tpl_vars = [
            'event_name'  => $event_name,
            'event_url'   => $event_url,
            'invite_code' => $invite_code,
            'guest_phone' => $disp_phone,
        ];

        $tpl = ($reply === 'yes')
            ? ($tpls['yes'] ?? pge_wa_default_reply_yes_template())
            : ($tpls['no']  ?? pge_wa_default_reply_no_template());

        $confirm_msg = function_exists('pge_wa_render_template')
            ? pge_wa_render_template($tpl, $tpl_vars)
            : $tpl;

        $this->send_text_message($send_to, $confirm_msg);

        // ── إرسال QR code عند تأكيد الحضور ───────────────────────────────────
        if ($reply === 'yes' && $invite_code !== '' && function_exists('pge_generate_qr_url')) {
            $qr_url     = pge_generate_qr_url($invite_code);
            $qr_caption = "🔳 *بطاقة دخولك*\nأرِها عند الباب للدخول السريع\n🔑 الرمز: *{$invite_code}*";
            $this->send_media_message($send_to, $qr_url, $qr_caption);
            $this->log("📱 QR sent: code=$invite_code | to=$send_to");
        }

        $this->log("✅ RSVP: from=$raw_from | rsvp_phone=$rsvp_phone | reply=$reply | event=$event_id");
        return new WP_REST_Response(['status' => 'success', 'reply' => $reply], 200);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // AJAX — إرسال تجريبي لرقم محدد
    // ══════════════════════════════════════════════════════════════════════════

    public function ajax_test_send(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pge_event_manage_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $event_id   = absint($_POST['event_id']   ?? 0);
        $test_phone = sanitize_text_field($_POST['test_phone'] ?? '');
        $test_name  = sanitize_text_field($_POST['test_name']  ?? 'ضيف تجريبي');

        if (!$event_id || !pge_is_host_or_admin($event_id)) {
            wp_send_json_error(['message' => 'Forbidden']);
        }
        if (empty($this->api_token)) {
            wp_send_json_error(['message' => 'لم يتم ضبط Cartat API Token']);
        }
        if ($test_phone === '') {
            wp_send_json_error(['message' => 'أدخل رقم الجوال للاختبار']);
        }

        $wa_number  = $this->format_wa_number($test_phone);
        $norm_phone = pge_norm_phone($test_phone);

        $event          = get_post($event_id);
        $event_name     = $event ? $event->post_title : 'مناسبتنا';
        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        $event_date     = $event_date_raw
            ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
            : '';
        $image_url = (string) get_the_post_thumbnail_url($event_id, 'full');

        // بناء رسالة الدعوة التجريبية من القالب المخصص
        $tpl_invite = function_exists('pge_wa_get_templates')
            ? pge_wa_get_templates($event_id)['invite']
            : pge_wa_default_invite_template();

        $caption = pge_wa_render_template($tpl_invite, [
            'guest_name'      => $test_name ?: 'ضيف تجريبي',
            'event_name'      => $event_name,
            'event_date'      => $event_date,
            'event_date_line' => $event_date ? "\n📅 {$event_date}" : '',
            'guest_phone'     => $norm_phone,
        ]);

        $result = $image_url
            ? $this->send_media_message($wa_number, $image_url, $caption)
            : $this->send_text_message($wa_number, $caption);

        $is_error = ($result === null)
                 || (isset($result['status']) && $result['status'] === 'error')
                 || (isset($result['success']) && $result['success'] === false);

        if ($is_error) {
            wp_send_json_error(['message' => 'فشل الإرسال: ' . json_encode($result)]);
        }

        $this->log("🧪 Test send: wa=$wa_number | name=$test_name | event=$event_id");
        wp_send_json_success(['message' => "✅ تم إرسال الرسالة التجريبية للرقم $wa_number — تحقق من واتساب"]);
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

        if (empty($this->api_token)) {
            wp_send_json_error(['message' => 'لم يتم ضبط Cartat API Token في الإعدادات']);
        }

        $offset     = absint($_POST['offset']     ?? 0);
        $batch_size = absint($_POST['batch_size'] ?? 20);
        $batch_size = min($batch_size, 30); // حد أقصى 30 لكل دفعة

        $results = $this->send_invitations($event_id, $offset, $batch_size);
        wp_send_json_success($results);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // إرسال الدعوات لكل المدعوين
    // ══════════════════════════════════════════════════════════════════════════

    public function send_invitations(int $event_id, int $offset = 0, int $batch_size = 20): array
    {
        @set_time_limit(120); // دقيقتان تكفي لدفعة 20 رسالة

        $event      = get_post($event_id);
        $event_name = $event ? $event->post_title : 'مناسبتنا';

        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        $event_date     = $event_date_raw
            ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
            : '';

        $image_url         = (string) get_the_post_thumbnail_url($event_id, 'full');
        $event_url         = function_exists("pge_get_event_short_url") ? pge_get_event_short_url($event_id) : (string) get_permalink($event_id);
        $event_invite_code = (string) get_post_meta($event_id, '_pge_invite_code', true);
        if (function_exists('pge_normalize_invite_code')) {
            $event_invite_code = pge_normalize_invite_code($event_invite_code);
        }
        $guests_map  = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
        $all_phones  = pge_get_invited_phones($event_id);
        $total       = count($all_phones);

        if (empty($all_phones)) {
            return ['sent' => 0, 'failed' => 0, 'total' => 0, 'done' => true, 'message' => 'لا يوجد مدعوون مضافون'];
        }

        // أخذ الدفعة المطلوبة فقط
        $phones    = array_slice($all_phones, $offset, $batch_size);
        $next_offset = $offset + count($phones);
        $has_more    = $next_offset < $total;

        $sent = $failed = 0;

        foreach ($phones as $phone) {
            $wa_number  = $this->format_wa_number($phone);
            $guest_name = $guests_map[$phone]['name'] ?? 'ضيفنا العزيز';
            $norm_phone = pge_norm_phone($phone);

            // رمز الضيف الشخصي — fallback للرمز الموحّد
            $guest_code_raw    = $guests_map[$phone]['code'] ?? '';
            $guest_invite_code = $guest_code_raw !== ''
                ? (function_exists('pge_normalize_invite_code') ? pge_normalize_invite_code($guest_code_raw) : $guest_code_raw)
                : $event_invite_code;

            // بناء رسالة الدعوة من القالب المخصص أو الافتراضي
            $tpl_invite = function_exists('pge_wa_get_templates')
                ? pge_wa_get_templates($event_id)['invite']
                : pge_wa_default_invite_template();

            $caption = pge_wa_render_template($tpl_invite, [
                'guest_name'      => $guest_name,
                'event_name'      => $event_name,
                'event_date'      => $event_date,
                'event_date_line' => $event_date ? "\n📅 {$event_date}" : '',
                'guest_phone'     => $norm_phone,
            ]);

            // إرسال صورة أو نص حسب توفر الصورة
            if ($image_url) {
                $result = $this->send_media_message($wa_number, $image_url, $caption);
            } else {
                $result = $this->send_text_message($wa_number, $caption);
            }

            // تأخير عشوائي بين 2-4 ثوانٍ — يشبه السلوك البشري ويقلل خطر الحظر
            usleep(rand(2_000_000, 4_000_000));

            // نعتبر الإرسال ناجحاً إذا لم يكن هناك status=error صريح
            // (Cartat قد يُرجع status=queued أو sent أو success — كلها تعني القبول)
            $is_error = ($result === null)
                     || (isset($result['status']) && $result['status'] === 'error')
                     || (isset($result['success']) && $result['success'] === false);

            $this->log("📨 Cartat send result for $wa_number: " . json_encode($result) . " | is_error=" . ($is_error ? 'yes' : 'no'));

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
                    'invite_code'    => $guest_invite_code,  // رمز الضيف الشخصي
                    'norm_phone'     => $norm_phone,
                ];

                // حفظ بصيغة رقم الهاتف (fallback)
                update_option('pge_wa_pending_' . $wa_number, $pending_data, false);

                // حفظ بصيغة msg_id — يُستخدم في ACK لمعرفة LID المستقبِل
                if ($msg_id) {
                    update_option('pge_wa_pending_msgid_' . $msg_id, $pending_data, false);
                }

                $this->log("✅ Cartat: pending saved | wa=$wa_number | msg_id=$msg_id");
            } else {
                $failed++;
                $this->log("❌ Cartat: فشل إرسال لـ $wa_number | " . json_encode($result));
            }
        }

        // حفظ إحصائيات آخر إرسال في الـ post meta (فقط عند انتهاء كل الدفعات)
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
    // API Wrappers
    // ══════════════════════════════════════════════════════════════════════════

    private function send_text_message(string $number, string $message): ?array
    {
        return $this->api_request('/message/text', [
            'number'  => $number,
            'message' => $message,
        ]);
    }

    private function send_media_message(string $number, string $media_url, string $caption = ''): ?array
    {
        return $this->api_request('/message/media', [
            'number'    => $number,
            'media_url' => $media_url,
            'caption'   => $caption,
        ]);
    }

    private function api_request(string $endpoint, array $body): ?array
    {
        $response = wp_remote_post($this->api_base . $endpoint, [
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_token,
                'Expect'        => '', // منع Expect: 100-continue الذي يُعلّق الاتصال
            ],
            'body'        => wp_json_encode($body),
            'timeout'     => 20,
            'httpversion' => '1.1',  // تجنب مشاكل HTTP/2
            'sslverify'   => true,
        ]);

        if (is_wp_error($response)) {
            error_log('❌ Cartat API Error: ' . $response->get_error_message());
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        error_log('📤 Cartat API Response [' . $endpoint . ']: ' . json_encode($decoded));
        return $decoded;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * تحويل رقم الجوال إلى صيغة واتساب الدولية (966XXXXXXXXX)
     * يعالج: 00XXXXXXXX / 0XXXXXXXX / XXXXXXXX / +XXXXXXXX
     */
    private function format_wa_number(string $phone): string
    {
        $phone = pge_norm_phone($phone); // أرقام فقط

        // 00XXXXXXXXX → الرقم يحمل كود الدولة بعد الـ 00
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }
        // 0XXXXXXXXX → رقم محلي، أضف كود الدولة بدل الصفر
        elseif (str_starts_with($phone, '0')) {
            $phone = $this->country_code . substr($phone, 1);
        }
        // رقم قصير (أقل من 10) بدون كود دولة → أضفه
        elseif (strlen($phone) < 10) {
            $phone = $this->country_code . $phone;
        }
        // رقم ≥ 10 أرقام لا يبدأ بـ 0 → كود الدولة موجود مسبقاً (972, 966, 962...)

        return $phone;
    }

    /**
     * تحليل رد المدعو إلى 'yes' أو 'no'
     */
    private function parse_rsvp_reply(string $body): string
    {
        $b = mb_strtolower(trim($body));

        $yes = ['1', 'نعم', 'yes', 'حاضر', 'سأحضر', 'حضور', 'اوكي', 'موافق', 'ok', '✅', 'ايه', 'اه'];
        $no  = ['2', 'لا', 'no', 'اعتذر', 'لن احضر', 'اعتذار', 'معذرة', '❌', 'مش قادر', 'مو قادر'];

        if (in_array($b, $yes, true)) return 'yes';
        if (in_array($b, $no,  true)) return 'no';
        return '';
    }

    /**
     * تسجيل RSVP في الجدول المخصص
     */
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
                'note'        => 'via WhatsApp',
                'checked_in'  => 0,
                'created_at'  => current_time('mysql'),
            ]);
        }
    }

    private function get_reminder_text(): string
    {
        return "عذراً، لم نتعرف على ردك 😊\n\nأرسل *1* لتأكيد الحضور\nأو *2* للاعتذار";
    }

    // ══════════════════════════════════════════════════════════════════════════
    // نظام الإرسال في الخلفية (Background Queue)
    // ══════════════════════════════════════════════════════════════════════════

    /** مفتاح الـ Queue في wp_options */
    private function queue_key(int $event_id): string
    {
        return 'pge_wa_queue_' . $event_id;
    }

    /** AJAX — بدء الإرسال في الخلفية */
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
        if (empty($this->api_token)) {
            wp_send_json_error(['message' => 'لم يتم ضبط Cartat API Token']);
        }

        $phones = pge_get_invited_phones($event_id);
        if (empty($phones)) {
            wp_send_json_error(['message' => 'لا يوجد مدعوون']);
        }

        // تجميع بيانات المناسبة مرة واحدة
        $event          = get_post($event_id);
        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        $invite_code    = (string) get_post_meta($event_id, '_pge_invite_code', true);
        if (function_exists('pge_normalize_invite_code')) {
            $invite_code = pge_normalize_invite_code($invite_code);
        }

        $queue = [
            'event_id'   => $event_id,
            'status'     => 'queued',
            'phones'     => array_values($phones),
            'guests_map' => function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [],
            'event_name' => $event ? $event->post_title : 'مناسبتنا',
            'event_date' => $event_date_raw
                ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
                : '',
            'image_url'  => (string) get_the_post_thumbnail_url($event_id, 'full'),
            'event_url'  => function_exists("pge_get_event_short_url") ? pge_get_event_short_url($event_id) : (string) get_permalink($event_id),
            'invite_code'=> $invite_code,
            'offset'     => 0,
            'total'      => count($phones),
            'results'    => [],
            'created_at' => time(),
            'done_at'    => null,
        ];

        update_option($this->queue_key($event_id), $queue, false);

        // جدولة أول دفعة فوراً
        wp_schedule_single_event(time(), 'pge_wa_process_queue', [$event_id]);
        spawn_cron(); // إجبار WordPress على تشغيل Cron فوراً

        wp_send_json_success([
            'message' => "🚀 بدأ الإرسال في الخلفية لـ {$queue['total']} مدعو. يمكنك إغلاق الصفحة.",
            'total'   => $queue['total'],
        ]);
    }

    /** AJAX — جلب حالة الإرسال */
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

        // بناء تقرير مفصّل
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
            'total'    => $queue['total'],
            'offset'   => $queue['offset'],
            'sent'     => $sent,
            'failed'   => $failed,
            'progress' => $pct,
            'report'   => $report,
            'done_at'  => $queue['done_at'],
        ]);
    }

    /** WP Cron — معالجة دفعة واحدة في الخلفية */
    public function cron_process_queue(int $event_id): void
    {
        $queue = get_option($this->queue_key($event_id));
        if (!$queue || $queue['status'] === 'done') return;

        @set_time_limit(120);

        $queue['status'] = 'running';
        update_option($this->queue_key($event_id), $queue, false);

        $batch_size = 10; // دفعة صغيرة لضمان عدم انتهاء الوقت
        $phones     = array_slice($queue['phones'], $queue['offset'], $batch_size);

        foreach ($phones as $phone) {
            $wa_number  = $this->format_wa_number($phone);
            $norm_phone = pge_norm_phone($phone);
            $guest_name = $queue['guests_map'][$phone]['name'] ?? 'ضيفنا العزيز';

            // رمز الضيف الشخصي — fallback للرمز الموحّد
            $guest_code_raw    = $queue['guests_map'][$phone]['code'] ?? '';
            $guest_invite_code = $guest_code_raw !== ''
                ? (function_exists('pge_normalize_invite_code') ? pge_normalize_invite_code($guest_code_raw) : $guest_code_raw)
                : $queue['invite_code'];

            $tpl_invite = function_exists('pge_wa_get_templates')
                ? pge_wa_get_templates($event_id)['invite']
                : pge_wa_default_invite_template();

            $caption = pge_wa_render_template($tpl_invite, [
                'guest_name'      => $guest_name,
                'event_name'      => $queue['event_name'],
                'event_date'      => $queue['event_date'],
                'event_date_line' => $queue['event_date'] ? "\n📅 {$queue['event_date']}" : '',
                'guest_phone'     => $norm_phone,
            ]);

            $result = $queue['image_url']
                ? $this->send_media_message($wa_number, $queue['image_url'], $caption)
                : $this->send_text_message($wa_number, $caption);

            $is_error = ($result === null)
                     || (isset($result['status']) && $result['status'] === 'error')
                     || (isset($result['success']) && $result['success'] === false);

            if (!$is_error) {
                $msg_id = $result['id'] ?? '';
                $pending_data = [
                    'event_id'       => $event_id,
                    'sent_at'        => time(),
                    'msg_id'         => $msg_id,
                    'original_phone' => $norm_phone,
                    'wa_number'      => $wa_number,
                    'event_url'      => $queue['event_url'],
                    'invite_code'    => $guest_invite_code,  // رمز الضيف الشخصي
                    'norm_phone'     => $norm_phone,
                ];
                update_option('pge_wa_pending_' . $wa_number, $pending_data, false);
                if ($msg_id) {
                    update_option('pge_wa_pending_msgid_' . $msg_id, $pending_data, false);
                }
                $queue['results'][$phone] = ['status' => 'sent',   'time' => current_time('mysql')];
            } else {
                $queue['results'][$phone] = ['status' => 'failed',  'time' => current_time('mysql')];
                $this->log("❌ Queue: فشل إرسال لـ $wa_number | " . json_encode($result));
            }

            // تأخير عشوائي بين الرسائل
            usleep(rand(2_000_000, 4_000_000));
        }

        $queue['offset'] += count($phones);

        if ($queue['offset'] >= $queue['total']) {
            // انتهى الإرسال
            $queue['status']  = 'done';
            $queue['done_at'] = current_time('mysql');
            update_post_meta($event_id, '_pge_wa_sent_at',    current_time('mysql'));
            update_post_meta($event_id, '_pge_wa_sent_count', $queue['offset']);
            $this->log("✅ Queue done: event=$event_id | offset={$queue['offset']}/{$queue['total']}");
        } else {
            // جدولة الدفعة التالية بعد 35 ثانية استراحة
            $queue['status'] = 'running';
            wp_schedule_single_event(time() + 35, 'pge_wa_process_queue', [$event_id]);
        }

        update_option($this->queue_key($event_id), $queue, false);
    }
}

new Mon_Cartat_Handler();
