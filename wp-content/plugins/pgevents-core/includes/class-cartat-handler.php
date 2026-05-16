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

        add_action('rest_api_init',              [$this, 'register_webhook_route']);
        add_action('wp_ajax_pge_send_wa_invites', [$this, 'handle_send_invitations_ajax']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REST Webhook — استقبال ردود المدعوين
    // ══════════════════════════════════════════════════════════════════════════

    public function register_webhook_route()
    {
        register_rest_route('mon/v1', '/wa-callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_incoming_message'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_incoming_message($request)
    {
        $payload = json_decode($request->get_body(), true);

        // تسجيل الـ payload للتشخيص
        error_log('📱 Cartat Webhook: ' . json_encode($payload));

        // تجاهل الرسائل الصادرة
        $from_me = $payload['data']['fromMe'] ?? $payload['fromMe'] ?? false;
        if ($from_me) {
            return new WP_REST_Response(['status' => 'ignored', 'reason' => 'outgoing'], 200);
        }

        // استخراج الرقم والنص (نتعامل مع أشكال payload متعددة)
        $raw_from = $payload['data']['from'] ?? $payload['from'] ?? '';
        $raw_from = preg_replace('/@.*$/', '', $raw_from); // إزالة @s.whatsapp.net
        $body     = trim($payload['data']['body'] ?? $payload['body'] ?? '');

        if (!$raw_from || $body === '') {
            return new WP_REST_Response(['status' => 'ignored', 'reason' => 'empty'], 200);
        }

        $phone_norm = pge_norm_phone($raw_from);

        // البحث عن دعوة معلّقة لهذا الرقم
        $pending = get_option('pge_wa_pending_' . $phone_norm);
        if (!$pending || empty($pending['event_id'])) {
            error_log("📱 Cartat: لا دعوة معلّقة للرقم $phone_norm");
            return new WP_REST_Response(['status' => 'no_pending'], 200);
        }

        $event_id = (int) $pending['event_id'];

        // تحليل الرد
        $reply = $this->parse_rsvp_reply($body);
        if (!$reply) {
            // رد غير مفهوم — أرسل تذكيراً
            $this->send_text_message($raw_from, $this->get_reminder_text());
            return new WP_REST_Response(['status' => 'invalid_reply'], 200);
        }

        // تسجيل الرد في قاعدة البيانات
        $this->record_rsvp($event_id, $phone_norm, $reply);

        // مسح الدعوة المعلّقة
        delete_option('pge_wa_pending_' . $phone_norm);

        // تأكيد للمدعو
        $confirm_msg = ($reply === 'yes')
            ? "شكراً على تأكيد حضورك! 🎉\nنتطلع لرؤيتك في *" . get_the_title($event_id) . "*"
            : "شكراً على إبلاغنا. نتمنى لك دوام الصحة والسعادة 🌸";
        $this->send_text_message($raw_from, $confirm_msg);

        error_log("✅ Cartat RSVP: $phone_norm → $reply | event=$event_id");
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

        if (empty($this->api_token)) {
            wp_send_json_error(['message' => 'لم يتم ضبط Cartat API Token في الإعدادات']);
        }

        $results = $this->send_invitations($event_id);
        wp_send_json_success($results);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // إرسال الدعوات لكل المدعوين
    // ══════════════════════════════════════════════════════════════════════════

    public function send_invitations(int $event_id): array
    {
        $event      = get_post($event_id);
        $event_name = $event ? $event->post_title : 'مناسبتنا';

        $event_date_raw = (string) get_post_meta($event_id, '_pge_event_date', true);
        $event_date     = $event_date_raw
            ? date_i18n('j F Y — g:i a', strtotime(str_replace('T', ' ', $event_date_raw)))
            : '';

        $image_url  = (string) get_the_post_thumbnail_url($event_id, 'full');
        $guests_map = function_exists('pge_event_guests_get_map') ? pge_event_guests_get_map($event_id) : [];
        $phones     = pge_get_invited_phones($event_id);

        if (empty($phones)) {
            return ['sent' => 0, 'failed' => 0, 'total' => 0, 'message' => 'لا يوجد مدعوون مضافون'];
        }

        $sent = $failed = 0;

        foreach ($phones as $phone) {
            $wa_number  = $this->format_wa_number($phone);
            $guest_name = $guests_map[$phone]['name'] ?? 'ضيفنا العزيز';

            $caption  = "مرحباً {$guest_name} 👋\n\n";
            $caption .= "يسعدنا دعوتك لحضور:\n*{$event_name}*\n";
            if ($event_date) {
                $caption .= "\n📅 {$event_date}\n";
            }
            $caption .= "\nللرد على الدعوة أرسل:\n";
            $caption .= "✅ *1* — سأحضر بإذن الله\n";
            $caption .= "❌ *2* — لن أتمكن من الحضور";

            // إرسال صورة أو نص حسب توفر الصورة
            if ($image_url) {
                $result = $this->send_media_message($wa_number, $image_url, $caption);
            } else {
                $result = $this->send_text_message($wa_number, $caption);
            }

            if ($result && ($result['status'] ?? '') === 'success') {
                $sent++;
                // تخزين الدعوة المعلّقة لربط الرد بالمناسبة لاحقاً
                update_option('pge_wa_pending_' . pge_norm_phone($phone), [
                    'event_id' => $event_id,
                    'sent_at'  => time(),
                    'msg_id'   => $result['id'] ?? '',
                ], false);
            } else {
                $failed++;
                error_log("❌ Cartat: فشل إرسال لـ $wa_number | " . json_encode($result));
            }
        }

        // حفظ إحصائيات آخر إرسال في الـ post meta
        update_post_meta($event_id, '_pge_wa_sent_at',    current_time('mysql'));
        update_post_meta($event_id, '_pge_wa_sent_count', $sent);

        return [
            'sent'    => $sent,
            'failed'  => $failed,
            'total'   => count($phones),
            'message' => "✅ نجح: {$sent} | ❌ فشل: {$failed} | الإجمالي: " . count($phones),
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
        // XXXXXXXXX بدون كود دولة → أضفه
        elseif (!str_starts_with($phone, $this->country_code)) {
            $phone = $this->country_code . $phone;
        }

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
}

new Mon_Cartat_Handler();
